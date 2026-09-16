<?php
/**
 * api/admin_send_receipt_api.php
 * ------------------------------------------------------------------
 * ادمین یک کاربر را انتخاب می‌کند و یک/چند فیش (عکس) برای او ارسال
 * می‌کند. این فیش‌ها دقیقاً مثل بقیه‌ی فیش‌ها در بخش «حساب‌ها و فیش‌ها»ی
 * داشبورد کاربر (دسته‌ی «تسویه حساب») نمایش داده می‌شوند، چون روی همان
 * جدول user_receipts ذخیره می‌شوند که dashboard.php از قبل می‌خواند.
 *
 * اکشن‌ها:
 *   send_receipt  (POST, multipart)  ارسال یک/چند فیش برای یک کاربر
 *   list_receipts (GET)              آخرین فیش‌های ارسالی توسط ادمین (برای نمایش در پنل)
 * ------------------------------------------------------------------
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');
ob_start();

function asr_fail($msg, $code = null) {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    echo json_encode(['success' => false, 'message' => $msg, 'error_code' => $code], JSON_UNESCAPED_UNICODE);
    exit();
}

set_exception_handler(function ($e) { asr_fail('خطای سرور: ' . $e->getMessage(), 'exception'); });
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'خطای داخلی سرور: ' . $err['message'], 'error_code' => 'fatal'], JSON_UNESCAPED_UNICODE);
    }
});

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/upload_paths.php';
require_once __DIR__ . '/../includes/notify_helper.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) asr_fail('لطفاً وارد شوید', 'auth');

$userId = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$ADMIN_TELEGRAM_ID = defined('ADMIN_TELEGRAM_ID') ? (string)ADMIN_TELEGRAM_ID : '5330629504';

$isAdmin = false;
$__ur = @$conn->query("SELECT telegram_id, is_admin FROM users WHERE id = $userId");
$urow = $__ur ? $__ur->fetch_assoc() : null;
if ($urow) {
    if ((int)($urow['is_admin'] ?? 0) === 1
        || (string)($urow['telegram_id'] ?? '') === $ADMIN_TELEGRAM_ID
        || (string)$userId === $ADMIN_TELEGRAM_ID) {
        $isAdmin = true;
    }
}
if (!$isAdmin) asr_fail('دسترسی غیرمجاز', 'forbidden');

/* آپلود فایل‌ها؛ همان الگوی dsSaveFiles در deal_settlement_api.php */
function asrSaveFiles($fileField, $dirAbs, $dirRel, $prefix, &$err, &$failed = null) {
    $err = ''; $failed = []; $saved = [];
    if (empty($_FILES[$fileField])) { $err = 'حداقل یک فایل انتخاب کنید'; return $saved; }
    if (!is_dir($dirAbs)) { @mkdir($dirAbs, 0775, true); }
    if (!is_dir($dirAbs) || !is_writable($dirAbs)) {
        $err = 'پوشه‌ی آپلود قابل نوشتن نیست (' . $dirRel . ')';
        return $saved;
    }

    $f = $_FILES[$fileField];
    $names = is_array($f['name'])     ? $f['name']     : [$f['name']];
    $tmps  = is_array($f['tmp_name']) ? $f['tmp_name'] : [$f['tmp_name']];
    $errs  = is_array($f['error'])    ? $f['error']    : [$f['error']];
    $sizes = is_array($f['size'])     ? $f['size']     : [$f['size']];

    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'bmp'];
    $maxSize = 25 * 1024 * 1024;

    for ($i = 0, $n = count($names); $i < $n; $i++) {
        $label = trim((string)($names[$i] ?? '')) !== '' ? (string)$names[$i] : ('عکس ' . ($i + 1));
        $code = (int)($errs[$i] ?? UPLOAD_ERR_NO_FILE);
        if ($code === UPLOAD_ERR_NO_FILE) continue;
        if ($code !== UPLOAD_ERR_OK) { $failed[] = "$label: خطای آپلود ($code)"; continue; }
        if (!is_uploaded_file($tmps[$i])) { $failed[] = "$label: فایل معتبر نیست"; continue; }
        if ((int)($sizes[$i] ?? 0) > $maxSize) { $failed[] = "$label: حجم بیش از ۲۵ مگابایت"; continue; }

        $ext = strtolower(pathinfo($names[$i], PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $allowed, true)) {
            $failed[] = "$label: فقط عکس مجاز است (jpg/png/webp/...)";
            continue;
        }

        $fname = $prefix . '_' . time() . '_' . $i . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest  = $dirAbs . '/' . $fname;
        if (!@move_uploaded_file($tmps[$i], $dest)) {
            if (!@copy($tmps[$i], $dest)) { $failed[] = "$label: ذخیره روی سرور ناموفق بود"; continue; }
        }
        @chmod($dest, 0644);
        $saved[] = $dirRel . '/' . $fname;
    }

    if (!$saved) { $err = $failed ? implode(' | ', $failed) : 'خطا در آپلود فایل‌ها'; }
    return $saved;
}

/* ================================================================
 * ادمین: ارسال یک/چند فیش برای یک کاربر
 * multipart: user_id, note(optional), file[]
 * ================================================================ */
if ($action === 'send_receipt' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $targetUserId = (int)($_POST['user_id'] ?? 0);
    $note = trim((string)($_POST['note'] ?? ''));
    if ($targetUserId <= 0) asr_fail('کاربر انتخاب نشده است', 'no_user');

    $chkU = @$conn->query("SELECT id, first_name, last_name FROM users WHERE id = $targetUserId");
    $target = $chkU ? $chkU->fetch_assoc() : null;
    if (!$target) asr_fail('کاربر یافت نشد', 'not_found');

    $dirRel = 'uploads/admin_receipts';
    $dirAbs = rtrim(avapay_upload_dir('admin_receipts'), '/');
    $err = ''; $failed = [];
    $saved = asrSaveFiles('file', $dirAbs, $dirRel, "adminrcpt_{$targetUserId}", $err, $failed);
    if (empty($saved)) asr_fail($err ?: 'خطا در آپلود فیش‌ها', 'upload');

    $ins = $conn->prepare("INSERT INTO user_receipts
        (user_id, receipt_file, notes, status, uploaded_by, admin_id, uploaded_at)
        VALUES (?, ?, ?, 'approved', ?, ?, NOW())");
    if (!$ins) asr_fail('خطا در ذخیره‌سازی: ' . $conn->error, 'db');

    $insertedCount = 0;
    foreach ($saved as $path) {
        $ins->bind_param('issii', $targetUserId, $path, $note, $userId, $userId);
        if ($ins->execute()) $insertedCount++;
    }
    $ins->close();

    if ($insertedCount === 0) asr_fail('هیچ فیشی ذخیره نشد', 'db');

    $targetName = trim(($target['first_name'] ?? '') . ' ' . ($target['last_name'] ?? '')) ?: 'کاربر گرامی';
    $bodyMsg = ($insertedCount > 1 ? "{$insertedCount} فیش جدید" : 'یک فیش جدید') . ' برای شما ارسال شد.'
             . ($note !== '' ? "\n\nتوضیح ادمین: {$note}" : '')
             . "\n\nبرای مشاهده به بخش «حساب‌ها و فیش‌ها» در داشبورد مراجعه کنید.";

    notifyUser($conn, $targetUserId, '📎 فیش جدید دریافت کردید', $bodyMsg, [
        'type' => 'admin_receipt', 'url' => '/ledor/dashboard.php', 'related_id' => null,
        'email' => true, 'telegram' => true, 'immediate' => true,
    ]);

    echo json_encode([
        'success' => true,
        'message' => "{$insertedCount} فیش برای " . htmlspecialchars($targetName, ENT_QUOTES, 'UTF-8') . ' ارسال شد'
                     . ($failed ? ' (' . count($failed) . ' فایل ناموفق)' : ''),
        'count'   => $insertedCount,
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

/* ================================================================
 * ادمین: آخرین فیش‌های ارسالی (برای نمایش فهرست در پنل، اختیاری)
 * ================================================================ */
if ($action === 'list_receipts') {
    $rows = [];
    $r = @$conn->query("SELECT r.id, r.user_id, r.receipt_file, r.notes, r.uploaded_at,
                                u.first_name, u.last_name
                         FROM user_receipts r
                         LEFT JOIN users u ON u.id = r.user_id
                         WHERE r.uploaded_by IS NOT NULL
                         ORDER BY r.id DESC LIMIT 30");
    if ($r) { while ($row = $r->fetch_assoc()) { $rows[] = $row; } }
    echo json_encode(['success' => true, 'receipts' => $rows], JSON_UNESCAPED_UNICODE);
    exit();
}

asr_fail('اکشن نامعتبر است', 'bad_action');
