<?php
// api/data_management_api.php
// بخش «داده» در پنل ادمین:
//   ۱) پاک‌سازی کامل تاریخچه‌ی تراکنش/ارزی یک کاربر (نه حذف خود کاربر)
//   ۲) مدیریت بک‌آپ خودکار روزانه‌ی دیتابیس (لیست/بک‌آپ فوری/دانلود/ریستور)
// هر دو بخش کاملاً ادمین‌محور و بسیار حساس هستند — همه‌جا تاییدیه‌ی دوگانه لازم است.
header('Content-Type: application/json');

require_once '../config/database.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}
$userId = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? '';

function isAdminUserDM($conn, $uid) {
    $r = $conn->query("SELECT is_admin FROM users WHERE id = " . intval($uid));
    $row = $r ? $r->fetch_assoc() : null;
    return $row && (int)$row['is_admin'] === 1;
}

if (!isAdminUserDM($conn, $userId)) {
    echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']);
    exit();
}

/* ------------------------------------------------------------------
 * جدول‌هایی که «تاریخچه‌ی ارزی/تراکنشی» یک کاربر در آن‌ها ذخیره می‌شود.
 * هر مورد: [نام جدول، ستون‌هایی که ممکن است به کاربر اشاره کنند، برچسب فارسی]
 * توجه: برای جدول‌های دوطرفه (ad_deals/ad_offers/transactions) پاک‌سازیِ
 * داده‌ی این کاربر یعنی کل ردیف حذف می‌شود — یعنی طرف مقابلِ آن معامله هم
 * دیگر آن ردیف را در تاریخچه‌ی خودش نخواهد دید. این رفتار عمداً همینه،
 * چون کاربر درخواست پاک‌سازی «هر تاریخچه‌ی ارزی که انجام داده» را داده.
 * ------------------------------------------------------------------ */
function dm_history_tables() {
    return [
        ['table' => 'transactions',        'cols' => ['sender_id', 'receiver_id'], 'label' => 'تراکنش‌ها'],
        ['table' => 'ad_deals',            'cols' => ['buyer_id', 'seller_id'],    'label' => 'معاملات تبادل ارزی'],
        ['table' => 'ad_offers',           'cols' => ['buyer_id', 'seller_id'],    'label' => 'پیشنهادهای معامله'],
        ['table' => 'user_ads',            'cols' => ['user_id'],                  'label' => 'آگهی‌های ثبت‌شده'],
        ['table' => 'topup_requests',      'cols' => ['user_id'],                  'label' => 'درخواست‌های شارژ'],
        ['table' => 'unpaid_invoices',     'cols' => ['user_id'],                  'label' => 'فیش‌های پرداخت'],
        ['table' => 'withdrawal_requests', 'cols' => ['user_id'],                  'label' => 'درخواست‌های تسویه (حواله‌های فعال)'],
        ['table' => 'money_transfers',     'cols' => ['user_id'],                  'label' => 'حواله‌های ارزی'],
        ['table' => 'user_balance_history','cols' => ['user_id'],                  'label' => 'تاریخچه‌ی موجودی کیف پول'],
    ];
}

function dm_table_exists($conn, $table) {
    $t = $conn->real_escape_string($table);
    $r = $conn->query("SHOW TABLES LIKE '$t'");
    return $r && $r->num_rows > 0;
}

/* ---------- جستجوی کاربر (برای انتخاب هدف پاک‌سازی) ---------- */
if ($action === 'search_users') {
    $search = isset($_GET['search']) ? '%' . $_GET['search'] . '%' : '%%';
    $stmt = $conn->prepare("SELECT id, first_name, last_name, email, phone_number, telegram_id
                            FROM users
                            WHERE first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone_number LIKE ? OR telegram_id LIKE ?
                            ORDER BY id DESC LIMIT 200");
    $stmt->bind_param("sssss", $search, $search, $search, $search, $search);
    $stmt->execute();
    $res = $stmt->get_result();
    $users = [];
    while ($row = $res->fetch_assoc()) { $users[] = $row; }
    echo json_encode(['success' => true, 'users' => $users]);
    exit();
}

/* ---------- خلاصه‌ی داده‌های یک کاربر (قبل از پاک‌سازی نشان داده می‌شود) ---------- */
if ($action === 'get_user_summary') {
    $targetId = intval($_GET['user_id'] ?? 0);
    if ($targetId <= 0) { echo json_encode(['success' => false, 'message' => 'کاربر نامعتبر است']); exit(); }

    $u = $conn->query("SELECT id, first_name, last_name, email, telegram_id, balance_usd, balance_eur, balance_usdt, balance_irr FROM users WHERE id = $targetId")->fetch_assoc();
    if (!$u) { echo json_encode(['success' => false, 'message' => 'کاربر یافت نشد']); exit(); }

    $counts = [];
    $total = 0;
    foreach (dm_history_tables() as $t) {
        if (!dm_table_exists($conn, $t['table'])) { $counts[$t['table']] = ['label' => $t['label'], 'count' => 0]; continue; }
        $whereParts = array_map(function($c) use ($targetId) { return "`$c` = $targetId"; }, $t['cols']);
        $where = implode(' OR ', $whereParts);
        $r = $conn->query("SELECT COUNT(*) AS c FROM `{$t['table']}` WHERE $where");
        $c = $r ? (int)$r->fetch_assoc()['c'] : 0;
        $counts[$t['table']] = ['label' => $t['label'], 'count' => $c];
        $total += $c;
    }

    $balances = [
        'USD'  => (float)$u['balance_usd'],
        'EUR'  => (float)$u['balance_eur'],
        'USDT' => (float)$u['balance_usdt'],
        'IRR'  => (float)$u['balance_irr'],
    ];

    echo json_encode(['success' => true, 'user' => $u, 'counts' => $counts, 'total' => $total, 'balances' => $balances]);
    exit();
}

/* ---------- پاک‌سازی کامل داده‌های ارزی/تراکنشی یک کاربر (کاربر خودش حذف نمی‌شود) ---------- */
if ($action === 'wipe_user_data' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $targetId = intval($data['user_id'] ?? 0);
    $confirmId = intval($data['confirm_user_id'] ?? 0);

    if ($targetId <= 0) { echo json_encode(['success' => false, 'message' => 'کاربر نامعتبر است']); exit(); }
    // تاییدیه‌ی سمت سرور: شناسه‌ی تایپ‌شده باید دقیقاً برابر با شناسه‌ی هدف باشد
    // (لایه‌ی دوم امنیتی، مستقل از تاییدیه‌ی سمت کلاینت)
    if ($confirmId !== $targetId) {
        echo json_encode(['success' => false, 'message' => 'شناسه‌ی تاییدیه مطابقت ندارد']);
        exit();
    }

    $u = $conn->query("SELECT id, first_name, last_name FROM users WHERE id = $targetId")->fetch_assoc();
    if (!$u) { echo json_encode(['success' => false, 'message' => 'کاربر یافت نشد']); exit(); }

    // مهم: موجودی/بالانس ولت کاربر هم باید صفر شود (خودِ پروفایل—نام، ایمیل،
    // شماره تلگرام و غیره—دست‌نخورده می‌ماند، فقط بالانس ارزی پاک می‌شود).
    // این UPDATE تریگر after_user_balance_update را هم فعال می‌کند که یک ردیف
    // «صفر شد» در user_balance_history درج می‌کند — برای همین ابتدا بالانس را
    // صفر می‌کنیم و «بعد» جدول تاریخچه را پاک می‌کنیم، تا حتی همان ردیفِ
    // خودکار هم چیزی از او باقی نگذارد.
    $conn->query("UPDATE users SET balance_usd = 0, balance_eur = 0, balance_usdt = 0, balance_irr = 0 WHERE id = $targetId");

    $deleted = [];
    $totalDeleted = 0;
    foreach (dm_history_tables() as $t) {
        if (!dm_table_exists($conn, $t['table'])) { continue; }
        $whereParts = array_map(function($c) use ($targetId) { return "`$c` = $targetId"; }, $t['cols']);
        $where = implode(' OR ', $whereParts);
        $conn->query("DELETE FROM `{$t['table']}` WHERE $where");
        $n = $conn->affected_rows;
        if ($n > 0) { $deleted[$t['label']] = $n; $totalDeleted += $n; }
    }

    error_log("AvaPay data-wipe: admin #$userId wiped history+balance of user #$targetId ({$u['first_name']} {$u['last_name']}) — $totalDeleted rows across " . count($deleted) . ' tables, balances reset to 0');

    echo json_encode(['success' => true, 'deleted' => $deleted, 'total' => $totalDeleted, 'balance_reset' => true]);
    exit();
}

/* ================= بخش بکاپ ================= */

require_once __DIR__ . '/../cron_db_backup.php'; // avapay_run_db_backup() + avapay_backup_dir() را می‌دهد
$backupDir = function_exists('avapay_backup_dir') ? avapay_backup_dir() : (__DIR__ . '/../backups');

/* ---------- لیست بک‌آپ‌های موجود ---------- */
if ($action === 'list_backups') {
    $files = [];
    if (is_dir($backupDir)) {
        // مهم: قبلاً فقط backup_*.sql.gz لیست می‌شد — یعنی بک‌آپ‌های ایمنیِ
        // pre_restore_safety_*.sql.gz (تنها راه بازگشت بعد از یک ریستور
        // اشتباه) اصلاً در این لیست دیده نمی‌شدند. حالا هر دو نوع دیده می‌شوند
        // و نوعشان مشخص است تا در UI قابل تفکیک باشند.
        $patterns = ['backup_*.sql.gz' => 'auto', 'pre_restore_safety_*.sql.gz' => 'safety'];
        foreach ($patterns as $pattern => $kind) {
            foreach (glob($backupDir . '/' . $pattern) as $f) {
                $files[] = [
                    'name'  => basename($f),
                    'kind'  => $kind,
                    'size'  => filesize($f),
                'sizeKb' => round(filesize($f) / 1024, 1),
                'date'  => date('Y-m-d H:i:s', filemtime($f)),
                'ts'    => filemtime($f),
                ];
            }
        }
        usort($files, function($a, $b) { return $b['ts'] <=> $a['ts']; });
    }
    // آخرین زمان بک‌آپ خودکار (برای نمایش «آخرین بک‌آپ خودکار: ...»)
    $gateFile = sys_get_temp_dir() . '/ava_gate_daily_db_backup.txt';
    $lastAuto = is_readable($gateFile) ? (int)@file_get_contents($gateFile) : 0;
    echo json_encode(['success' => true, 'files' => $files, 'last_auto' => $lastAuto ? date('Y-m-d H:i:s', $lastAuto) : null]);
    exit();
}

/* ---------- بک‌آپ فوری (دستی) ---------- */
if ($action === 'run_backup_now' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../cron_db_backup.php'; // تابع avapay_run_db_backup را می‌دهد
    if (!function_exists('avapay_run_db_backup')) {
        echo json_encode(['success' => false, 'message' => 'ماژول بک‌آپ در دسترس نیست']);
        exit();
    }
    $result = avapay_run_db_backup($conn, $backupDir);
    echo json_encode($result);
    exit();
}

/* ---------- دانلود یک فایل بک‌آپ ---------- */
if ($action === 'download_backup') {
    $name = basename($_GET['file'] ?? ''); // basename برای جلوگیری از path traversal
    $path = $backupDir . '/' . $name;
    if (!preg_match('/^(backup_|pre_restore_safety_)[\d\-_]+\.sql\.gz$/', $name) || !is_file($path)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'فایل یافت نشد']);
        exit();
    }
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit();
}

/* ---------- حذف یک فایل بک‌آپ قدیمی (توسط ادمین) ---------- */
if ($action === 'delete_backup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $name = basename($data['file'] ?? ''); // basename برای جلوگیری از path traversal
    $path = $backupDir . '/' . $name;
    if (!preg_match('/^(backup_|pre_restore_safety_)[\d\-_]+\.sql\.gz$/', $name) || !is_file($path)) {
        echo json_encode(['success' => false, 'message' => 'فایل بک‌آپ یافت نشد']);
        exit();
    }
    if (@unlink($path)) {
        error_log("AvaPay backup deleted by admin: $name");
        echo json_encode(['success' => true, 'message' => 'بک‌آپ حذف شد']);
    } else {
        echo json_encode(['success' => false, 'message' => 'حذف فایل ممکن نشد (دسترسی فایل را بررسی کنید)']);
    }
    exit();
}

/* ---------- ریستور از یک فایل بک‌آپ (بسیار حساس) ---------- */
if ($action === 'restore_backup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    @set_time_limit(0);
    @ini_set('memory_limit', '512M');
    $data = json_decode(file_get_contents('php://input'), true);
    $name = basename($data['file'] ?? '');
    $confirmText = trim($data['confirm_text'] ?? '');

    if ($confirmText !== 'RESTORE') {
        echo json_encode(['success' => false, 'message' => 'برای ریستور باید عبارت تاییدیه دقیق تایپ شود']);
        exit();
    }
    $path = $backupDir . '/' . $name;
    // نکته: قبلاً این الگو فقط backup_*.sql.gz را قبول می‌کرد، یعنی بک‌آپِ
    // ایمنیِ pre_restore_safety_*.sql.gz (تنها راه بازگشت بعد از یک ریستور
    // اشتباه) اصلاً از همین‌جا قابل انتخاب/ریستور نبود. حالا هر دو مجازند.
    if (!preg_match('/^(backup_|pre_restore_safety_)[\d\-_]+\.sql\.gz$/', $name) || !is_file($path)) {
        echo json_encode(['success' => false, 'message' => 'فایل بک‌آپ یافت نشد']);
        exit();
    }

    // بررسیِ سلامتِ فایل قبل از هرگونه دست‌زدن به دیتابیس زنده — علت اصلیِ
    // حادثه‌ی قبلی این بود که یک بک‌آپِ نیمه‌کاره/ناقص روی دیتابیس زنده اجرا
    // شد. اگر این نشانه در انتهای فایل نباشد، یعنی حین ساخت بک‌آپ (تایم‌اوت،
    // قطع دیسک و ...) قطع شده — و دیگر اصلاً به دیتابیس دست نمی‌زنیم.
    $gzChk = @gzopen($path, 'rb');
    if (!$gzChk) {
        echo json_encode(['success' => false, 'message' => 'امکان باز کردن فایل بک‌آپ نبود']);
        exit();
    }
    $sql = '';
    while (!gzeof($gzChk)) { $sql .= gzread($gzChk, 1024 * 512); }
    gzclose($gzChk);

    if (strpos($sql, '-- AVAPAY_BACKUP_COMPLETE_OK') === false) {
        echo json_encode(['success' => false, 'message' => 'این فایل بک‌آپ ناقص/خراب به‌نظر می‌رسد (نشانه‌ی پایانِ سالم پیدا نشد) — برای ایمنی، ریستور انجام نشد. لطفاً بک‌آپ دیگری را امتحان کنید.']);
        exit();
    }

    // ایمنیِ اول: قبل از ریستور، از وضعیت *فعلی* هم یک بک‌آپ فوری می‌گیریم
    // تا اگر ریستور اشتباه بود، خودِ همین لحظه هم قابل بازگشت باشد.
    require_once __DIR__ . '/../cron_db_backup.php';
    $safetyBackup = null;
    if (function_exists('avapay_run_db_backup')) {
        $safety = avapay_run_db_backup($conn, $backupDir, 'pre_restore_safety_');
        if (!empty($safety['success'])) $safetyBackup = $safety['file_name'] ?? null;
    }

    $ok = true;
    $errorMsg = '';
    $statementsRun = 0;
    $failedTable = '';
    try {
        $conn->query("SET FOREIGN_KEY_CHECKS=0");
        // مهم: بک‌آپ‌های قدیمی‌تر (از قبل از این اصلاح) مقدار ستون‌های
        // GENERATED (مثل ad_offers.total_amount) را هم صراحتاً در INSERT
        // داشتند. در sql_mode پیش‌فرضِ سخت‌گیرانه (STRICT_TRANS_TABLES) این
        // باعث خطای واقعی می‌شود و کل ریستور را متوقف می‌کند؛ در حالتِ غیرِ
        // strict، MySQL فقط مقدار را نادیده می‌گیرد (هشدار بی‌ضرر) و خودش
        // از روی ستون‌های دیگر محاسبه‌اش می‌کند. برای همینِ session این خطا
        // را به هشدار تبدیل می‌کنیم تا ریستور بک‌آپ‌های قدیمی هم کار کند —
        // بک‌آپ‌های جدید اصلاً دیگر این ستون‌ها را در INSERT نمی‌آورند.
        $conn->query("SET SESSION sql_mode = ''");

        // مهم — یک ریسک باقی‌مانده که قبلاً پوشش داده نشده بود: حتی با اینکه
        // هر INSERT به‌تنهایی به ~۲۵۰ کیلوبایت محدود شده، فرستادن *کل* فایل
        // (که برای یک دیتابیس واقعی می‌تواند چند مگابایت باشد) به‌عنوان یک
        // multi_query واحد یعنی MySQL باید کل آن را در یک پکت شبکه دریافت
        // کند — اگر از max_allowed_packet سرور (روی هاست‌های اشتراکی معمولاً
        // ۱ تا ۴ مگابایت) رد شود، خودِ اتصال قطع می‌شود و هیچ‌کدام از
        // جدول‌های بعدی هرگز ریستور نمی‌شوند. برای همین، فایل را از روی
        // مرزهای «هر جدول» که خودِ بک‌آپ‌گیر می‌نویسد (کامنت‌های
        // «-- Table: X» — یک نشانه‌ی کاملاً یکتا که هرگز داخل داده‌ی واقعی
        // ظاهر نمی‌شود) به چند تکه تقسیم می‌کنیم و هر تکه را جداگانه اجرا
        // می‌کنیم. این یعنی حتی برای دیتابیس‌های بزرگ، هیچ‌وقت کل فایل در
        // یک پکت فرستاده نمی‌شود — و اگر جایی خطا بدهد، دقیقاً می‌فهمیم کدام
        // جدول بوده، نه فقط شماره‌ی یک statement گمنام.
        $chunks = preg_split('/(?=\n-- ----------------------------\n-- Table: )/', $sql);

        foreach ($chunks as $chunk) {
            if (trim($chunk) === '') continue;
            $curTable = '';
            if (preg_match('/-- Table:\s*(\S+)/', $chunk, $m)) $curTable = $m[1];

            if ($conn->multi_query($chunk)) {
                do {
                    $statementsRun++;
                    if ($res = $conn->store_result()) { $res->free(); }
                    if ($conn->errno) { $ok = false; $errorMsg = $conn->error; $failedTable = $curTable; break; }
                } while ($conn->more_results() && $conn->next_result());
            } else {
                $ok = false;
                $errorMsg = $conn->error;
                $failedTable = $curTable;
            }
            if (!$ok) break;
        }
        if ($ok && $conn->errno) { $ok = false; $errorMsg = $conn->error; }
        $conn->query("SET FOREIGN_KEY_CHECKS=1");
    } catch (\Throwable $e) {
        $ok = false;
        $errorMsg = $e->getMessage();
    }

    error_log('AvaPay RESTORE ' . ($ok ? 'OK' : 'FAILED') . " by admin #$userId from $name (statements: $statementsRun)" . ($errorMsg ? " — $errorMsg" . ($failedTable ? " [table: $failedTable]" : '') : ''));

    echo json_encode([
        'success' => $ok,
        'message' => $ok
            ? 'دیتابیس با موفقیت و به‌طور کامل (همه‌ی جدول‌ها و رکوردها) از روی بک‌آپ بازیابی شد'
            : ('خطا در ریستور (در statement شماره ' . $statementsRun . ($failedTable ? "، جدول «{$failedTable}»" : '') . '): ' . $errorMsg . ($safetyBackup ? ' — بک‌آپ ایمنیِ قبل از این تلاش با نام «' . $safetyBackup . '» موجود است.' : '')),
        'safety_backup' => $safetyBackup,
    ]);
    exit();
}

echo json_encode(['success' => false, 'message' => 'اکشن نامعتبر']);
