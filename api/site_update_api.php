<?php
/**
 * api/site_update_api.php
 * ---------------------------------------------------------------
 * بخش «آپدیت سایت» در پنل ادمین: آپلود یک فایل zip از کل پروژه، فعال‌سازیِ
 * خودکارِ «حالتِ آپدیت» (که همه‌ی بازدیدکنندگانِ غیرادمین پیامِ شمارش‌معکوس
 * می‌بینند)، گرفتنِ یک بکاپِ ایمنی از وضعیتِ فعلی، جایگزینیِ فایل‌ها، و در
 * صورتِ بروزِ هر خطا، بازگردانیِ خودکار از همان بکاپ.
 *
 * action=status عمداً بدونِ نیاز به لاگین کار می‌کند — همان چیزی‌ست که
 * صفحه‌ی «در حال آپدیت» (includes/maintenance_gate.php) هر ۵ ثانیه یک‌بار
 * برای فهمیدنِ «تمام شد یا نه» صدا می‌زند.
 * ---------------------------------------------------------------
 */
header('Content-Type: application/json; charset=utf-8');

if (!function_exists('ava_su_ini_bytes')) {
    function ava_su_ini_bytes(string $val): int {
        $val = trim($val);
        if ($val === '') return 0;
        $unit = strtolower(substr($val, -1));
        $num = (int)$val;
        switch ($unit) {
            case 'g': return $num * 1024 * 1024 * 1024;
            case 'm': return $num * 1024 * 1024;
            case 'k': return $num * 1024;
            default:  return (int)$val;
        }
    }
}
if (!function_exists('ava_su_fmt_bytes')) {
    function ava_su_fmt_bytes(int $bytes): string {
        if ($bytes <= 0) return 'نامشخص';
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . 'G';
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . 'M';
        return round($bytes / 1024) . 'K';
    }
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/maintenance_gate.php';
require_once __DIR__ . '/../includes/site_updater.php';
require_once __DIR__ . '/../cron_db_backup.php'; // avapay_backup_dir() — همان پوشه‌ی بکاپ دیتابیس

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/* (اصلاح) وقتی حجمِ آپلود از post_max_size هاست بیشتر باشد، PHP بی‌صدا کل
   $_POST و $_FILES را خالی می‌کند (نه فقط فایل را رد می‌کند) — یعنی حتی
   خودِ فیلدِ action هم به دستِ ما نمی‌رسد و بدونِ این چک، کاربر به‌جای پیامِ
   روشنِ «حجم زیاد است»، فقط «اکشن نامعتبر» می‌بیند که گمراه‌کننده است. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $postMaxBytes = ava_su_ini_bytes(ini_get('post_max_size'));
    $upMaxBytes   = ava_su_ini_bytes(ini_get('upload_max_filesize'));
    echo json_encode([
        'success' => false,
        'message' => 'حجمِ فایل از سقفِ مجازِ هاست بیشتر است (post_max_size فعلی: '
            . ava_su_fmt_bytes($postMaxBytes) . '، upload_max_filesize فعلی: ' . ava_su_fmt_bytes($upMaxBytes)
            . ') — این دو مقدار را در php.ini یا .htaccess افزایش دهید و دوباره تلاش کنید.',
    ], JSON_UNESCAPED_UNICODE); exit();
}

/* ---------- action=status: عمومی، بدون نیاز به لاگین ---------- */
if ($action === 'status') {
    $state = avapay_maintenance_read();
    echo json_encode([
        'success'   => true,
        'active'    => !empty($state['active']),
        'message'   => $state['message'] ?? null,
        'reopen_at' => $state['reopen_at'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

/* ---------- بقیه‌ی اکشن‌ها فقط ادمین ---------- */
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'لطفاً وارد شوید']); exit();
}
$userId = (int)$_SESSION['user_id'];
$isAdmin = false;
$__r = @$conn->query("SELECT is_admin FROM users WHERE id = {$userId}");
$__row = $__r ? $__r->fetch_assoc() : null;
if ($__row && (int)$__row['is_admin'] === 1) $isAdmin = true;
if (!$isAdmin) {
    echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']); exit();
}

$siteRoot = realpath(__DIR__ . '/..');
$updDir = site_update_dir();

/* ---------- action=start_update: آپلود و اعمالِ زیپ ---------- */
if ($action === 'start_update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // استخراج/کپیِ چند صد فایل ممکن است چند ثانیه طول بکشد — محدودیتِ زمان/حافظه‌ی
    // پیش‌فرض هاست را تا جایی که اجازه بدهد بالا می‌بریم (اگر هاست open_basedir/
    // disable_functions را قفل کرده باشد، این‌ها بی‌صدا نادیده گرفته می‌شوند)
    @set_time_limit(300);
    @ini_set('memory_limit', '512M');

    if (empty($_FILES['zip']) || !isset($_FILES['zip']['error'])) {
        echo json_encode(['success' => false, 'message' => 'فایل zip ارسال نشد']); exit();
    }
    if ($_FILES['zip']['error'] !== UPLOAD_ERR_OK) {
        $uploadErrMap = [
            UPLOAD_ERR_INI_SIZE   => 'حجمِ فایل از سقفِ مجازِ هاست (upload_max_filesize) بیشتر است — این مقدار را در php.ini یا .htaccess افزایش دهید',
            UPLOAD_ERR_FORM_SIZE  => 'حجمِ فایل بیشتر از حدِ مجاز است',
            UPLOAD_ERR_PARTIAL    => 'آپلود ناقص انجام شد — دوباره تلاش کنید',
            UPLOAD_ERR_NO_FILE    => 'فایلی انتخاب نشده بود',
        ];
        $m = $uploadErrMap[$_FILES['zip']['error']] ?? ('خطای آپلود (کد ' . $_FILES['zip']['error'] . ')');
        echo json_encode(['success' => false, 'message' => $m]); exit();
    }
    $origName = (string)$_FILES['zip']['name'];
    if (strtolower(pathinfo($origName, PATHINFO_EXTENSION)) !== 'zip') {
        echo json_encode(['success' => false, 'message' => 'فقط فایلِ zip پذیرفته می‌شود']); exit();
    }

    $durationSec    = max(20, min(900, (int)($_POST['duration'] ?? 90)));
    $message        = trim((string)($_POST['message'] ?? ''));
    if ($message === '') $message = 'سایت در حال آپدیت است. لطفاً چند لحظه‌ی دیگر دوباره سر بزنید.';
    $includeConfig  = !empty($_POST['include_config']);
    $includeUploads = !empty($_POST['include_uploads']);

    // ۱) فوراً حالتِ آپدیت را فعال کن — از همین لحظه کاربران عادی پیامِ شمارش‌معکوس می‌بینند
    $startedAt = date('Y-m-d H:i:s');
    $reopenAt  = date('Y-m-d H:i:s', time() + $durationSec);
    avapay_maintenance_write([
        'active' => true, 'message' => $message, 'reopen_at' => $reopenAt, 'started_at' => $startedAt,
    ]);

    $backupFile = null;
    $stagingDir = null;
    try {
        // ۲) بکاپِ ایمنی از وضعیتِ فعلیِ سایت (بدون config/uploads، مگر خودشان هم انتخاب شده باشند)
        $backupFile = $updDir . '/pre_update_' . date('Ymd_His') . '.zip';
        site_update_zip_directory($siteRoot, $backupFile, $includeConfig, $includeUploads);

        // ۳) استخراجِ بسته‌ی آپلودی
        $stagingDir = $updDir . '/staging_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 6);
        @mkdir($stagingDir, 0755, true);
        $zip = new ZipArchive();
        if ($zip->open($_FILES['zip']['tmp_name']) !== true) {
            throw new RuntimeException('باز کردنِ فایلِ zip ناموفق بود — ممکن است فایل خراب باشد');
        }
        $zip->extractTo($stagingDir);
        $zip->close();
        $realStaging = site_update_flatten_single_wrapper($stagingDir);

        // ۴) جایگزینیِ فایل‌ها روی ریشه‌ی سایت
        $copied = site_update_copy_recursive($realStaging, $siteRoot, $includeConfig, $includeUploads);

        // ۵) پاک‌سازیِ staging و فایلِ زیپِ آپلودشده
        site_update_rrmdir($stagingDir);

        // ۶) پایان موفق — حالتِ آپدیت خاموش می‌شود (کاربران با اولین poll برمی‌گردند)
        avapay_maintenance_write(['active' => false]);

        echo json_encode([
            'success'       => true,
            'message'       => 'آپدیت با موفقیت اعمال شد',
            'files_updated' => $copied,
            'backup_file'   => basename($backupFile),
        ], JSON_UNESCAPED_UNICODE);
        exit();

    } catch (\Throwable $e) {
        // rollback خودکار از بکاپِ همین لحظه
        $rolledBack = false;
        try {
            if ($backupFile && is_file($backupFile)) {
                site_update_restore_from_zip($backupFile, $siteRoot);
                $rolledBack = true;
            }
        } catch (\Throwable $e2) { /* اگر rollback هم شکست خورد، پیام زیر خودش هشدار می‌دهد */ }

        if ($stagingDir && is_dir($stagingDir)) { try { site_update_rrmdir($stagingDir); } catch (\Throwable $e3) {} }

        avapay_maintenance_write(['active' => false]);

        echo json_encode([
            'success'      => false,
            'message'      => $rolledBack
                ? ('خطا در آپدیت — سایت خودکار به نسخه‌ی قبل از آپدیت بازگردانده شد. جزئیات خطا: ' . $e->getMessage())
                : ('خطا در آپدیت و rollback هم ناموفق بود — لطفاً فوراً از بخش بکاپ‌ها بررسی کنید. جزئیات خطا: ' . $e->getMessage()),
            'rolled_back'  => $rolledBack,
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
}

/* ---------- action=end_maintenance: خاموشِ دستیِ حالت آپدیت (ابزار ایمنی) ---------- */
if ($action === 'end_maintenance' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    avapay_maintenance_write(['active' => false]);
    echo json_encode(['success' => true, 'message' => 'حالت آپدیت خاموش شد']); exit();
}

/* ---------- action=list_backups: فهرستِ بکاپ‌های پیش‌از‌آپدیت ---------- */
if ($action === 'list_backups') {
    $files = glob($updDir . '/pre_update_*.zip') ?: [];
    rsort($files);
    $out = [];
    foreach ($files as $f) {
        $out[] = ['file' => basename($f), 'size' => filesize($f), 'time' => date('Y-m-d H:i:s', filemtime($f))];
    }
    echo json_encode(['success' => true, 'items' => $out], JSON_UNESCAPED_UNICODE); exit();
}

/* ---------- action=restore_backup: بازگردانیِ دستی به یکی از بکاپ‌های قبلی ---------- */
if ($action === 'restore_backup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $file = basename((string)($in['file'] ?? ''));
    $path = $updDir . '/' . $file;
    if ($file === '' || !is_file($path) || pathinfo($path, PATHINFO_EXTENSION) !== 'zip') {
        echo json_encode(['success' => false, 'message' => 'فایلِ بکاپ پیدا نشد']); exit();
    }
    $duration = max(20, min(900, (int)($in['duration'] ?? 60)));
    avapay_maintenance_write([
        'active' => true,
        'message' => 'سایت در حال بازگردانی به نسخه‌ی قبلی است.',
        'reopen_at' => date('Y-m-d H:i:s', time() + $duration),
        'started_at' => date('Y-m-d H:i:s'),
    ]);
    try {
        $count = site_update_restore_from_zip($path, $siteRoot);
        avapay_maintenance_write(['active' => false]);
        echo json_encode(['success' => true, 'message' => 'بازگردانی انجام شد', 'files_restored' => $count], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        avapay_maintenance_write(['active' => false]);
        echo json_encode(['success' => false, 'message' => 'خطا در بازگردانی: ' . $e->getMessage()]);
    }
    exit();
}

echo json_encode(['success' => false, 'message' => 'اکشن نامعتبر']); exit();
