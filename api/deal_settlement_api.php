<?php
/**
 * api/deal_settlement_api.php
 * ------------------------------------------------------------------
 * جریان تسویه‌ی معاملات بازار ارز به‌صورت جداگانه برای هر دو طرف
 * (آگهی‌دهنده = فروشنده / متقاضی = خریدار)
 *
 * اکشن‌ها:
 *   admin_send_accounts   (POST)  ادمین برای یک طرف (buyer|seller) یک/چند شماره‌کارت با نام می‌فرستد
 *   upload_receipt        (POST)  کاربر (طرف مربوطه) یک/چند فیش پرداخت آپلود می‌کند
 *   admin_complete_side   (POST)  ادمین برای یک طرف نوت + یک/چند فیش تسویه می‌گذارد و آن طرف را تکمیل می‌کند
 *   admin_finalize_deal   (POST)  ادمین کل معامله را تسویه می‌کند (خروج از لیست در انتظار)
 *   get_deal              (GET)   دریافت وضعیت کامل معامله برای کاربر/ادمین
 *   get_side_receipts     (GET)   دریافت فیش‌های تسویه‌ی یک طرف
 *   download_receipt      (GET)   دانلود یک فیش مشخص
 *
 * همه‌ی داده‌ها روی جدول ad_deals ذخیره می‌شوند (ستون‌های JSON خوددرمان).
 * ------------------------------------------------------------------
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

/* ------------------------------------------------------------------
 * محافظ خروجی: هر خطای غیرمنتظره هم باید JSON برگردد نه HTML.
 * (پیش‌تر خطای PHP باعث می‌شد پنل ادمین فقط «خطا» نشان دهد.)
 * ------------------------------------------------------------------ */
$__DS_IS_DOWNLOAD = (($_GET['action'] ?? '') === 'download_receipt');
if (!$__DS_IS_DOWNLOAD) {
    header('Content-Type: application/json; charset=utf-8');
    ob_start();
}

function ds_fail($msg, $code = null) {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    echo json_encode(['success' => false, 'message' => $msg, 'error_code' => $code], JSON_UNESCAPED_UNICODE);
    exit();
}

set_exception_handler(function ($e) {
    ds_fail('خطای سرور: ' . $e->getMessage(), 'exception');
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success'    => false,
            'message'    => 'خطای داخلی سرور: ' . $err['message'],
            'error_code' => 'fatal',
        ], JSON_UNESCAPED_UNICODE);
    }
});

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/notification_helper.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id'])) {
    ds_fail('لطفاً وارد شوید', 'auth');
}

$userId = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$ADMIN_TELEGRAM_ID = defined('ADMIN_TELEGRAM_ID') ? (string)ADMIN_TELEGRAM_ID : '5330629504';

/* ---------- تشخیص ادمین (هماهنگ با پنل مدیریت) ---------- */
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

/* ================================================================
 * خوددرمانی ستون‌ها
 * ================================================================ */
function dsEnsureColumns($conn) {
    static $done = false;
    if ($done) return;
    $done = true;

    $chk = @$conn->query("SHOW TABLES LIKE 'ad_deals'");
    if (!$chk || $chk->num_rows === 0) return;

    $cols = [
        'buyer_admin_accounts'       => "ALTER TABLE ad_deals ADD COLUMN buyer_admin_accounts TEXT NULL",
        'seller_admin_accounts'      => "ALTER TABLE ad_deals ADD COLUMN seller_admin_accounts TEXT NULL",
        'buyer_receipts'             => "ALTER TABLE ad_deals ADD COLUMN buyer_receipts TEXT NULL",
        'seller_receipts'            => "ALTER TABLE ad_deals ADD COLUMN seller_receipts TEXT NULL",
        'buyer_settlement_receipts'  => "ALTER TABLE ad_deals ADD COLUMN buyer_settlement_receipts TEXT NULL",
        'seller_settlement_receipts' => "ALTER TABLE ad_deals ADD COLUMN seller_settlement_receipts TEXT NULL",
        'buyer_admin_note'           => "ALTER TABLE ad_deals ADD COLUMN buyer_admin_note TEXT NULL",
        'seller_admin_note'          => "ALTER TABLE ad_deals ADD COLUMN seller_admin_note TEXT NULL",
        'buyer_side_status'          => "ALTER TABLE ad_deals ADD COLUMN buyer_side_status VARCHAR(30) DEFAULT 'new'",
        'seller_side_status'         => "ALTER TABLE ad_deals ADD COLUMN seller_side_status VARCHAR(30) DEFAULT 'new'",
        'admin_completed'            => "ALTER TABLE ad_deals ADD COLUMN admin_completed TINYINT(1) DEFAULT 0",
        'buyer_completed'            => "ALTER TABLE ad_deals ADD COLUMN buyer_completed TINYINT(1) DEFAULT 0",
        'seller_completed'           => "ALTER TABLE ad_deals ADD COLUMN seller_completed TINYINT(1) DEFAULT 0",
        'completed_at'               => "ALTER TABLE ad_deals ADD COLUMN completed_at DATETIME DEFAULT NULL",
        'completed_by'               => "ALTER TABLE ad_deals ADD COLUMN completed_by INT DEFAULT NULL",
        'archived'                   => "ALTER TABLE ad_deals ADD COLUMN archived TINYINT(1) NOT NULL DEFAULT 0",
        'buyer_payment_amount'       => "ALTER TABLE ad_deals ADD COLUMN buyer_payment_amount DECIMAL(20,2) DEFAULT NULL",
        'buyer_payment_currency'     => "ALTER TABLE ad_deals ADD COLUMN buyer_payment_currency VARCHAR(10) DEFAULT NULL",
        'seller_payment_amount'      => "ALTER TABLE ad_deals ADD COLUMN seller_payment_amount DECIMAL(20,2) DEFAULT NULL",
        'seller_payment_currency'    => "ALTER TABLE ad_deals ADD COLUMN seller_payment_currency VARCHAR(10) DEFAULT NULL",
    ];
    foreach ($cols as $c => $ddl) {
        $r = @$conn->query("SHOW COLUMNS FROM ad_deals LIKE '$c'");
        if ($r && $r->num_rows === 0) { @$conn->query($ddl); }
    }
}
dsEnsureColumns($conn);

/* ================================================================
 * کمک‌تابع‌ها
 * ================================================================ */
function dsGetDeal($conn, $dealId) {
    $dealId = (int)$dealId;
    if ($dealId <= 0) return null;
    $sql = "SELECT d.*, a.currency AS ad_currency,
                   b.id AS b_id, b.first_name AS b_first, b.last_name AS b_last, b.telegram_id AS b_tg,
                   s.id AS s_id, s.first_name AS s_first, s.last_name AS s_last, s.telegram_id AS s_tg
            FROM ad_deals d
            LEFT JOIN users b ON d.buyer_id = b.id
            LEFT JOIN users s ON d.seller_id = s.id
            LEFT JOIN user_ads a ON d.ad_id = a.id
            WHERE d.id = $dealId";
    $r = @$conn->query($sql);
    if (!$r) return null;
    return $r->fetch_assoc();
}
function dsSideCol($side, $base) { return ($side === 'seller' ? 'seller_' : 'buyer_') . $base; }
function dsNormSide($s)          { return ($s === 'seller') ? 'seller' : 'buyer'; }
function dsJsonArr($val) {
    if (is_array($val)) return $val;
    $d = json_decode((string)($val ?? ''), true);
    return is_array($d) ? $d : [];
}
function dsUserSide($deal, $userId) {
    if ((int)$deal['buyer_id']  === (int)$userId) return 'buyer';
    if ((int)$deal['seller_id'] === (int)$userId) return 'seller';
    return null;
}
function dsSideFa($side) { return $side === 'buyer' ? 'متقاضی (خریدار)' : 'آگهی‌دهنده (فروشنده)'; }

/* ------------------------------------------------------------------
 * همگام‌سازی درخواستِ پرداختِ معامله با کادر «صورت‌حساب‌ها» در داشبورد
 * ------------------------------------------------------------------
 * وقتی ادمین از تبادل ارزی برای یک طرف شماره‌حساب و مبلغ می‌فرستد،
 * همان درخواست باید به‌عنوان یک صورت‌حساب پرداخت‌نشده در داشبورد کاربر
 * هم دیده شود؛ و بعد از تکمیل/تسویه، از لیست «در انتظار پرداخت» خارج
 * شده و به آرشیو (تاریخچه) برود.
 *
 * برای جلوگیری از ساخته‌شدن صورت‌حساب تکراری، هر صورت‌حساب با
 * (deal_id, deal_side) به معامله گره می‌خورد.
 * ------------------------------------------------------------------ */
function dsEnsureInvoiceSchema($conn) {
    static $done = false;
    if ($done) return;
    $done = true;

    // نکته‌ی مهم: از PHP 8.1 به بعد mysqli به‌صورت پیش‌فرض در خطاها Exception
    // پرتاب می‌کند و عملگر @ جلوی Exception را نمی‌گیرد. همچنین
    // «ADD COLUMN IF NOT EXISTS» فقط در MariaDB کار می‌کند (در MySQL خطای
    // نحوی است) و «ADD INDEX» بدون بررسی، بار دوم خطای کلید تکراری می‌دهد.
    // به همین دلیل ابتدا وجود ستون/ایندکس بررسی و همه‌چیز در try/catch اجرا می‌شود؛
    // در غیر این‌صورت کل درخواستِ ادمین با خطا متوقف می‌شد.
    try {
        $cols = [];
        $r = $conn->query("SHOW COLUMNS FROM `unpaid_invoices`");
        if ($r) { while ($row = $r->fetch_assoc()) { $cols[strtolower($row['Field'])] = true; } }

        if (!isset($cols['deal_id'])) {
            $conn->query("ALTER TABLE `unpaid_invoices` ADD COLUMN `deal_id` INT DEFAULT NULL");
        }
        if (!isset($cols['deal_side'])) {
            $conn->query("ALTER TABLE `unpaid_invoices` ADD COLUMN `deal_side` VARCHAR(10) DEFAULT NULL");
        }

        $hasIdx = false;
        $ri = $conn->query("SHOW INDEX FROM `unpaid_invoices`");
        if ($ri) {
            while ($row = $ri->fetch_assoc()) {
                if (strtolower($row['Key_name']) === 'idx_deal') { $hasIdx = true; break; }
            }
        }
        if (!$hasIdx) {
            $conn->query("ALTER TABLE `unpaid_invoices` ADD INDEX `idx_deal` (`deal_id`, `deal_side`)");
        }
    } catch (Throwable $e) {
        // مهاجرت اختیاری است؛ نباید جریان اصلی ادمین را متوقف کند
        error_log('dsEnsureInvoiceSchema: ' . $e->getMessage());
    }
}

/** ارز را به مقادیر مجاز ENUM جدول صورت‌حساب‌ها محدود می‌کند */
function dsInvoiceCurrency($cur) {
    $cur = strtoupper(trim((string)$cur));
    return in_array($cur, ['USD', 'EUR', 'USDT', 'IRR'], true) ? $cur : 'IRR';
}

/** ایجاد/به‌روزرسانی صورت‌حساب مربوط به یک طرفِ معامله */
function dsSyncInvoice($conn, array $deal, $side, array $accounts, $amount, $currency) {
    try {
        dsSyncInvoiceInner($conn, $deal, $side, $accounts, $amount, $currency);
    } catch (Throwable $e) {
        // ثبت صورت‌حساب نباید باعث شکست ارسال شماره‌حساب توسط ادمین شود
        error_log('dsSyncInvoice: ' . $e->getMessage());
    }
}

function dsSyncInvoiceInner($conn, array $deal, $side, array $accounts, $amount, $currency) {
    dsEnsureInvoiceSchema($conn);

    $dealId = (int)$deal['id'];
    $userId = ($side === 'buyer') ? (int)$deal['buyer_id'] : (int)$deal['seller_id'];
    if ($userId <= 0 || $amount <= 0) return;

    $cur  = dsInvoiceCurrency($currency);
    $code = (string)($deal['deal_code'] ?? $dealId);
    $desc = "پرداخت معامله‌ی تبادل ارزی {$code} (" . dsSideFa($side) . ")";

    // اولین حساب برای نمایش در کارت صورت‌حساب استفاده می‌شود
    $first     = $accounts[0] ?? [];
    $bank      = (string)($first['bank'] ?? '');
    $card      = (string)($first['card'] ?? '');
    $recipient = (string)($first['name'] ?? '');

    $st = $conn->prepare("SELECT id, status FROM unpaid_invoices WHERE deal_id = ? AND deal_side = ? LIMIT 1");
    if (!$st) return;
    $st->bind_param('is', $dealId, $side);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if ($row) {
        // اگر کاربر قبلاً پرداخت کرده یا نهایی شده، وضعیت را عقب نمی‌بریم
        if (in_array($row['status'], ['paid', 'finalized'], true)) return;
        // وضعیت approved یعنی «اطلاعات پرداخت ارسال شده، آماده‌ی پرداخت».
        // چون خودِ ادمین شماره‌حساب را فرستاده، نیازی به تایید مجدد نیست.
        $up = $conn->prepare("UPDATE unpaid_invoices
                              SET amount = ?, currency = ?, description = ?, bank_name = ?,
                                  card_number = ?, recipient_name = ?, status = 'approved', updated_at = NOW()
                              WHERE id = ?");
        if ($up) {
            $up->bind_param('dsssssi', $amount, $cur, $desc, $bank, $card, $recipient, $row['id']);
            $up->execute();
            $up->close();
        }
        return;
    }

    // مستقیماً approved ثبت می‌شود (نه pending): اطلاعات پرداخت همین حالا
    // توسط ادمین ارسال شده و صورت‌حساب نباید «در انتظار بررسی» نمایش داده شود.
    $ins = $conn->prepare("INSERT INTO unpaid_invoices
        (user_id, currency, amount, description, status, bank_name, card_number, recipient_name, deal_id, deal_side)
        VALUES (?, ?, ?, ?, 'approved', ?, ?, ?, ?, ?)");
    if ($ins) {
        $ins->bind_param('isdssssis', $userId, $cur, $amount, $desc, $bank, $card, $recipient, $dealId, $side);
        $ins->execute();
        $ins->close();
    }
}

/** بستن صورت‌حساب(های) یک معامله تا از «در انتظار پرداخت» به آرشیو برود */
function dsCloseInvoice($conn, $dealId, $side = null) {
    try {
        dsCloseInvoiceInner($conn, $dealId, $side);
    } catch (Throwable $e) {
        error_log('dsCloseInvoice: ' . $e->getMessage());
    }
}

function dsCloseInvoiceInner($conn, $dealId, $side = null) {
    dsEnsureInvoiceSchema($conn);
    $dealId = (int)$dealId;
    if ($dealId <= 0) return;

    if ($side === null) {
        $st = $conn->prepare("UPDATE unpaid_invoices SET status = 'finalized', updated_at = NOW()
                              WHERE deal_id = ? AND status NOT IN ('finalized','rejected')");
        if (!$st) return;
        $st->bind_param('i', $dealId);
    } else {
        $st = $conn->prepare("UPDATE unpaid_invoices SET status = 'finalized', updated_at = NOW()
                              WHERE deal_id = ? AND deal_side = ? AND status NOT IN ('finalized','rejected')");
        if (!$st) return;
        $st->bind_param('is', $dealId, $side);
    }
    $st->execute();
    $st->close();
}

/** آی‌دی همه‌ی ادمین‌ها — تا نوتیفیکیشن فیش به همه برسد */
function dsAdminIds($conn, $adminTelegramId) {
    $ids = [];
    $tg = $conn->real_escape_string((string)$adminTelegramId);
    $r = @$conn->query("SELECT id FROM users WHERE is_admin = 1 OR telegram_id = '$tg'");
    while ($r && $row = $r->fetch_assoc()) { $ids[] = (int)$row['id']; }
    return array_values(array_unique($ids));
}

/** ارسال اعلان بدون اینکه خطای آن جریان اصلی را بشکند */
function dsNotify($conn, $uid, $title, $body, $opts = []) {
    try {
        if (function_exists('notifyUser')) { notifyUser($conn, (int)$uid, $title, $body, $opts); }
    } catch (Throwable $e) { /* نادیده */ }
}

/** به‌روزرسانی امن ad_deals */
function dsUpdate($conn, $dealId, array $sets) {
    $parts = [];
    foreach ($sets as $col => $val) {
        if ($val === null)          { $parts[] = "`$col` = NULL"; }
        elseif ($val === 'NOW()')   { $parts[] = "`$col` = NOW()"; }
        elseif (is_int($val))       { $parts[] = "`$col` = $val"; }
        else                        { $parts[] = "`$col` = '" . $conn->real_escape_string((string)$val) . "'"; }
    }
    if (!$parts) return true;
    return (bool)$conn->query("UPDATE ad_deals SET " . implode(', ', $parts) . " WHERE id = " . (int)$dealId);
}

/**
 * تشخیص پسوند واقعی فایل از روی محتوا (MIME)
 * لازم است چون بعضی گوشی‌ها (مخصوصاً دوربین آیفون/اندروید) فایل را
 * بدون پسوند یا با نام «image» می‌فرستند و تکیه بر نام فایل باعث
 * حذف بی‌صدای فیش می‌شد.
 */
function dsDetectExt($tmpPath, $origName) {
    $mimeMap = [
        'image/jpeg' => 'jpg', 'image/pjpeg' => 'jpg', 'image/png'  => 'png',
        'image/webp' => 'webp', 'image/gif'  => 'gif', 'image/heic' => 'heic',
        'image/heif' => 'heic', 'image/bmp'  => 'bmp', 'image/tiff' => 'tiff',
        'application/pdf' => 'pdf',
    ];

    // ۱) از روی محتوای واقعی فایل
    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = @finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) { $mime = (string)@finfo_file($fi, $tmpPath); @finfo_close($fi); }
    }
    if ($mime === '' && function_exists('mime_content_type')) {
        $mime = (string)@mime_content_type($tmpPath);
    }
    $mime = strtolower(trim(explode(';', $mime)[0]));
    if (isset($mimeMap[$mime])) return $mimeMap[$mime];

    // ۲) اگر تصویر است ولی MIME ناشناخته بود
    $info = @getimagesize($tmpPath);
    if ($info && !empty($info['mime']) && isset($mimeMap[strtolower($info['mime'])])) {
        return $mimeMap[strtolower($info['mime'])];
    }

    // ۳) در نهایت از روی نام فایل
    $ext = strtolower(pathinfo((string)$origName, PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]/', '', $ext);
    if ($ext === 'jpeg') $ext = 'jpg';
    return $ext;
}

function dsUploadErrFa($code) {
    switch ((int)$code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:  return 'حجم فایل از حد مجاز سرور بیشتر است';
        case UPLOAD_ERR_PARTIAL:    return 'فایل ناقص ارسال شد (اتصال قطع شد)';
        case UPLOAD_ERR_NO_FILE:    return 'فایلی ارسال نشد';
        case UPLOAD_ERR_NO_TMP_DIR: return 'پوشه‌ی موقت سرور موجود نیست';
        case UPLOAD_ERR_CANT_WRITE: return 'سرور نتوانست فایل را بنویسد';
        case UPLOAD_ERR_EXTENSION:  return 'یک افزونه‌ی PHP آپلود را متوقف کرد';
        default:                    return 'خطای نامشخص در آپلود';
    }
}

/**
 * آپلود چندفایلی امن.
 * $saved  : مسیرهای ذخیره‌شده
 * $err    : فقط وقتی پر می‌شود که هیچ فایلی ذخیره نشده باشد
 * $failed : دلیلِ هر فایلی که ذخیره نشد (حتی اگر بقیه موفق بوده‌اند)
 *
 * اصلاح مهم: پیش‌تر اگر از ۲ فایل فقط ۱ فایل ذخیره می‌شد، فایل دوم
 * بی‌صدا حذف و به کاربر «با موفقیت ارسال شد» گفته می‌شد.
 */
function dsSaveFiles($fileField, $dirAbs, $dirRel, $prefix, &$err, &$failed = null) {
    $err = '';
    $failed = [];
    $saved = [];

    if (empty($_FILES[$fileField])) { $err = 'حداقل یک فایل انتخاب کنید'; return $saved; }

    if (!is_dir($dirAbs)) { @mkdir($dirAbs, 0775, true); }
    if (!is_dir($dirAbs) || !is_writable($dirAbs)) {
        $err = 'پوشه‌ی آپلود قابل نوشتن نیست (' . $dirRel . '). لطفاً دسترسی 775 را تنظیم کنید.';
        return $saved;
    }

    $f = $_FILES[$fileField];
    $names = is_array($f['name'])     ? $f['name']     : [$f['name']];
    $tmps  = is_array($f['tmp_name']) ? $f['tmp_name'] : [$f['tmp_name']];
    $errs  = is_array($f['error'])    ? $f['error']    : [$f['error']];
    $sizes = is_array($f['size'])     ? $f['size']     : [$f['size']];

    $allowed = ['jpg', 'png', 'webp', 'gif', 'heic', 'bmp', 'tiff', 'pdf'];
    $maxSize = 25 * 1024 * 1024; // 25MB — عکس‌های گوشی‌های جدید بزرگ‌اند

    for ($i = 0, $n = count($names); $i < $n; $i++) {
        $label = trim((string)($names[$i] ?? '')) !== '' ? (string)$names[$i] : ('فایل ' . ($i + 1));

        $code = (int)($errs[$i] ?? UPLOAD_ERR_NO_FILE);
        if ($code === UPLOAD_ERR_NO_FILE) continue;           // ورودی خالی — واقعاً نادیده
        if ($code !== UPLOAD_ERR_OK) { $failed[] = "$label: " . dsUploadErrFa($code); continue; }

        if (!is_uploaded_file($tmps[$i])) { $failed[] = "$label: فایل معتبر نیست"; continue; }

        if ((int)($sizes[$i] ?? 0) > $maxSize) {
            $failed[] = "$label: حجم بیش از ۲۵ مگابایت";
            continue;
        }

        $ext = dsDetectExt($tmps[$i], $names[$i]);
        if ($ext === '' || !in_array($ext, $allowed, true)) {
            $failed[] = "$label: فرمت پشتیبانی نمی‌شود (فقط تصویر یا PDF)";
            continue;
        }

        $fname = $prefix . '_' . time() . '_' . $i . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest  = $dirAbs . '/' . $fname;

        if (!@move_uploaded_file($tmps[$i], $dest)) {
            // fallback: بعضی هاست‌ها با open_basedir مشکل دارند
            if (!@copy($tmps[$i], $dest)) {
                $failed[] = "$label: ذخیره روی سرور ناموفق بود";
                continue;
            }
        }
        @chmod($dest, 0644);
        $saved[] = $dirRel . '/' . $fname;
    }

    if (!$saved) {
        $err = $failed ? implode(' | ', $failed) : 'خطا در آپلود فایل‌ها';
    }
    return $saved;
}

/* ================================================================
 * ادمین: ارسال شماره‌حساب برای یک طرف
 * body: { deal_id, side: 'buyer'|'seller', accounts:[{name, card, bank?}, ...] }
 * ================================================================ */
if ($action === 'admin_send_accounts' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) ds_fail('دسترسی غیرمجاز', 'forbidden');

    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) $in = $_POST;

    $dealId = (int)($in['deal_id'] ?? 0);
    $side   = dsNormSide($in['side'] ?? '');
    $accountsRaw = $in['accounts'] ?? [];
    if (is_string($accountsRaw)) $accountsRaw = json_decode($accountsRaw, true) ?: [];
    $paymentAmount   = isset($in['payment_amount']) ? (float)$in['payment_amount'] : 0;
    $paymentCurrency = trim((string)($in['payment_currency'] ?? 'IRR'));

    $clean = [];
    foreach ((array)$accountsRaw as $acc) {
        if (!is_array($acc)) continue;
        $name = trim((string)($acc['name'] ?? ''));
        $bank = trim((string)($acc['bank'] ?? ''));
        $card = trim((string)($acc['card'] ?? ''));
        if ($card === '') continue;
        $clean[] = ['name' => $name, 'card' => $card, 'bank' => $bank];
    }

    if ($dealId <= 0) ds_fail('شناسه‌ی معامله نامعتبر است', 'bad_deal');
    if (empty($clean)) ds_fail('حداقل یک شماره‌کارت/حساب معتبر وارد کنید', 'no_accounts');
    if ($paymentAmount <= 0) ds_fail('لطفاً مبلغ قابل پرداخت را وارد کنید', 'no_amount');

    $deal = dsGetDeal($conn, $dealId);
    if (!$deal) ds_fail('معامله یافت نشد', 'not_found');

    $col       = dsSideCol($side, 'admin_accounts');
    $stCol     = dsSideCol($side, 'side_status');
    $amountCol = dsSideCol($side, 'payment_amount');
    $curCol    = dsSideCol($side, 'payment_currency');

    // اگر آن طرف قبلاً تسویه شده، وضعیتش را عقب نبریم
    $curStatus = $deal[$stCol] ?? 'new';
    $newStatus = ($curStatus === 'completed') ? 'completed' : 'awaiting_payment';

    if (!dsUpdate($conn, $dealId, [
        $col       => json_encode($clean, JSON_UNESCAPED_UNICODE),
        $stCol     => $newStatus,
        $amountCol => $paymentAmount,
        $curCol    => $paymentCurrency,
    ])) {
        ds_fail('خطا در ذخیره‌سازی: ' . $conn->error, 'db');
    }

    // همان درخواست پرداخت را در کادر «صورت‌حساب‌ها»ی داشبورد کاربر هم ثبت می‌کنیم،
    // تا کاربر آن را در «در انتظار پرداخت» ببیند و بعد از تسویه به آرشیو برود.
    dsSyncInvoice($conn, $deal, $side, $clean, $paymentAmount, $paymentCurrency);

    // اعلان به کاربرِ همان طرف
    $targetId = $side === 'buyer' ? (int)$deal['buyer_id'] : (int)$deal['seller_id'];
    $lines = [];
    foreach ($clean as $a) {
        $lines[] = '• ' . ($a['name'] !== '' ? $a['name'] . ' — ' : '') . $a['card'];
    }
    $amountFmt = number_format($paymentAmount);

    // باکس ساختاریافته‌ی ایمیل — دقیقاً مطابق طرح: هر حساب یک ردیف «شماره کارت»
    // (با آیکون کارت) به‌علاوه یک ردیف «نام صاحب حساب» (با آیکون ساعت)، و در
    // پایان یک ردیف «مبلغ قابل پرداخت» با آیکون کیف پول.
    $emailRows = [];
    foreach ($clean as $i => $a) {
        $emailRows[] = [
            'icon'  => '💳',
            'label' => ($i === 0) ? 'شماره کارت واریز ارسال' : '',
            'value' => $a['card'],
            'copy'  => true,
        ];
        if ($a['name'] !== '') {
            $emailRows[] = ['icon' => '🕐', 'label' => '', 'value' => $a['name']];
        }
    }
    $emailRows[] = ['icon' => '💰', 'label' => 'مبلغ قابل پرداخت', 'value' => "{$amountFmt} {$paymentCurrency}"];

    dsNotify($conn, $targetId,
        'شماره‌حساب واریز ارسال شد 💳',
        "برای معامله‌ی {$deal['deal_code']} شماره‌کارت واریز ارسال شد:\n" . implode("\n", $lines) .
        "\n\n💰 مبلغ قابل پرداخت: {$amountFmt} {$paymentCurrency}" .
        "\nلطفاً مبلغ را واریز و فیش پرداخت را از بخش «معاملات در انتظار تسویه» آپلود کنید.",
        // مهم: وقتی ادمین شماره‌حساب برای کاربر می‌فرستد، کاربر حتماً باید
        // ایمیل و پیام ربات دریافت کند (صرف‌نظر از تنظیمات شخصی‌اش)
        [
            'type' => 'deal_accounts', 'url' => '/ledor/arad.php', 'related_id' => $dealId,
            'email' => true, 'telegram' => true, 'immediate' => true,
            'email_details' => [
                'intro' => "شماره‌کارت واریز ارسال {$deal['deal_code']} برای معامله‌ی شما آماده است.",
                'rows'  => $emailRows,
                'note'  => 'لطفاً مبلغ را واریز و فیش پرداخت را از بخش «معاملات در انتظار تسویه» آپلود کنید.',
            ],
        ]
    );

    echo json_encode([
        'success'  => true,
        'message'  => count($clean) . ' شماره‌حساب و مبلغ ' . $amountFmt . ' ' . $paymentCurrency . ' برای ' . dsSideFa($side) . ' ارسال شد',
        'accounts' => $clean,
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

/* ================================================================
 * کاربر: آپلود فیش پرداخت (طرف خودش)
 * multipart: deal_id, file[]
 * ================================================================ */
if ($action === 'upload_receipt' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $dealId = (int)($_POST['deal_id'] ?? 0);
    $deal = dsGetDeal($conn, $dealId);
    if (!$deal) ds_fail('معامله یافت نشد', 'not_found');

    $side = dsUserSide($deal, $userId);
    if (!$side) ds_fail('شما طرف این معامله نیستید', 'forbidden');

    $dirRel = 'uploads/deal_receipts';
    $dirAbs = rtrim(avapay_upload_dir('deal_receipts'), '/');
    $err = '';
    $failed = [];
    $saved = dsSaveFiles('file', $dirAbs, $dirRel, "deal_{$dealId}_{$side}", $err, $failed);
    if (empty($saved)) ds_fail($err ?: 'خطا در آپلود فیش‌ها', 'upload');

    $col   = dsSideCol($side, 'receipts');
    $stCol = dsSideCol($side, 'side_status');
    $all   = array_merge(dsJsonArr($deal[$col] ?? ''), $saved);

    // اگر ادمین قبلاً این طرف را تسویه کرده، وضعیت را عقب نبریم
    $curStatus = $deal[$stCol] ?? 'new';
    $newStatus = ($curStatus === 'completed') ? 'completed' : 'receipt_submitted';

    if (!dsUpdate($conn, $dealId, [$col => json_encode($all, JSON_UNESCAPED_UNICODE), $stCol => $newStatus])) {
        ds_fail('خطا در ذخیره‌سازی: ' . $conn->error, 'db');
    }

    // اعلان به همه‌ی ادمین‌ها
    $sideFa   = dsSideFa($side);
    $userName = $side === 'buyer'
        ? trim(($deal['b_first'] ?? '') . ' ' . ($deal['b_last'] ?? ''))
        : trim(($deal['s_first'] ?? '') . ' ' . ($deal['s_last'] ?? ''));
    foreach (dsAdminIds($conn, $ADMIN_TELEGRAM_ID) as $aid) {
        dsNotify($conn, $aid,
            'فیش پرداخت جدید 🧾',
            "$sideFa ($userName) برای معامله‌ی {$deal['deal_code']} تعداد " . count($saved) . " فیش پرداخت آپلود کرد.",
            // مهم: ادمین باید حتماً ایمیل یا پیام ربات دریافت کند، نه فقط نوتیف داخل‌برنامه
            ['type' => 'deal_receipt', 'url' => '/ledor/admin_panel.php#exchange', 'related_id' => $dealId, 'email' => true, 'telegram' => true]
        );
    }

    // اگر بخشی از فایل‌ها ذخیره نشد، حتماً به کاربر بگو (قبلاً بی‌صدا حذف می‌شدند)
    $msg = count($saved) . ' فیش با موفقیت برای پشتیبانی ارسال شد';
    if (!empty($failed)) {
        $msg .= ' — اما ' . count($failed) . ' فایل ذخیره نشد: ' . implode(' | ', $failed);
    }

    echo json_encode([
        'success'  => true,
        'message'  => $msg,
        'saved'    => count($saved),
        'failed'   => $failed,
        'receipts' => $all,
        'total'    => count($all),
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

/* ================================================================
 * ادمین: تکمیل یک طرف با نوت اختیاری + یک/چند فیش تسویه
 * multipart: deal_id, side, note(optional), file[](optional)
 * ================================================================ */
if ($action === 'admin_complete_side' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) ds_fail('دسترسی غیرمجاز', 'forbidden');

    $dealId = (int)($_POST['deal_id'] ?? 0);
    $side   = dsNormSide($_POST['side'] ?? '');
    $note   = trim((string)($_POST['note'] ?? ''));

    $deal = dsGetDeal($conn, $dealId);
    if (!$deal) ds_fail('معامله یافت نشد', 'not_found');

    $col = dsSideCol($side, 'settlement_receipts');
    $existing = dsJsonArr($deal[$col] ?? '');

    $saved = [];
    $failed = [];
    $hasFiles = !empty($_FILES['file']['name']) &&
                (is_array($_FILES['file']['name']) ? count(array_filter($_FILES['file']['name'])) > 0 : true);
    if ($hasFiles) {
        $dirRel = 'uploads/deal_settlements';
        $dirAbs = rtrim(avapay_upload_dir('deal_settlements'), '/');
        $err = '';
        $saved = dsSaveFiles('file', $dirAbs, $dirRel, "settle_{$dealId}_{$side}", $err, $failed);
        if (empty($saved) && $err !== '') ds_fail($err, 'upload');
    }
    $all = array_merge($existing, $saved);

    $noteCol = dsSideCol($side, 'admin_note');
    $stCol   = dsSideCol($side, 'side_status');

    if (!dsUpdate($conn, $dealId, [
        $col     => json_encode($all, JSON_UNESCAPED_UNICODE),
        $noteCol => $note,
        $stCol   => 'completed',
        dsSideCol($side, 'completed') => 1,
    ])) {
        ds_fail('خطا در ذخیره‌سازی: ' . $conn->error, 'db');
    }

    // این طرف تسویه شد → صورت‌حسابش از «در انتظار پرداخت» به آرشیو منتقل می‌شود
    dsCloseInvoice($conn, $dealId, $side);

    // اگر هر دو طرف تکمیل شدند، کل معامله را completed کن
    $fresh = dsGetDeal($conn, $dealId);
    $bothDone = (($fresh['buyer_side_status'] ?? '') === 'completed')
             && (($fresh['seller_side_status'] ?? '') === 'completed');
    if ($bothDone) {
        dsUpdate($conn, $dealId, [
            'status'          => 'completed',
            'admin_completed' => 1,
            'completed_by'    => (int)$userId,
            'completed_at'    => 'NOW()',
        ]);
    }

    // اعلان به کاربرِ همان طرف
    $targetId = $side === 'buyer' ? (int)$deal['buyer_id'] : (int)$deal['seller_id'];
    $extra = $note !== '' ? ("\nیادداشت پشتیبانی: " . $note) : '';
    dsNotify($conn, $targetId,
        'معامله‌ی شما تسویه شد ✅',
        "معامله‌ی {$deal['deal_code']} برای شما تکمیل و تسویه شد. می‌توانید فیش تسویه را در تاریخچه‌ی معاملات مشاهده کنید." . $extra,
        ['type' => 'deal_completed', 'url' => '/ledor/arad.php', 'related_id' => $dealId]
    );

    $msg = 'طرف ' . dsSideFa($side) . ' تکمیل شد' . ($bothDone ? ' — کل معامله تسویه شد' : '');
    if (!empty($failed)) {
        $msg .= ' — اما ' . count($failed) . ' فایل ذخیره نشد: ' . implode(' | ', $failed);
    }

    echo json_encode([
        'success'   => true,
        'message'   => $msg,
        'both_done' => $bothDone,
        'saved'     => count($saved),
        'failed'    => $failed,
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

/* ================================================================
 * ادمین: «تسویه‌ی این تراکنش» — تکمیل نهایی کل معامله
 * body: { deal_id }
 * پس از این، معامله از لیست «در انتظار تسویه» (کاربر و ادمین) خارج
 * و به‌عنوان پرداخت‌شده در «تاریخچه معاملات» ثبت می‌شود.
 * ================================================================ */
if ($action === 'admin_finalize_deal' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) ds_fail('دسترسی غیرمجاز', 'forbidden');

    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) $in = $_POST;
    $dealId = (int)($in['deal_id'] ?? 0);
    $force  = !empty($in['force']);

    $deal = dsGetDeal($conn, $dealId);
    if (!$deal) ds_fail('معامله یافت نشد', 'not_found');

    if ((int)($deal['admin_completed'] ?? 0) === 1 && ($deal['status'] ?? '') === 'completed') {
        echo json_encode(['success' => true, 'message' => 'این معامله قبلاً تسویه شده بود', 'already' => true], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // (اصلاح) قبل از بستنِ کل معامله، مطمئن شو هر دو طرف واقعاً فیش پرداخت
    // فرستاده‌اند (یا از قبل توسط ادمین completed شده‌اند). در غیر این‌صورت،
    // بدونِ force، فقط هشدار برمی‌گردد تا با یک کلیک اشتباه، معامله‌ای که
    // هنوز پول واریز نشده «تسویه‌شده» ثبت نشود.
    $okStatuses = ['receipt_submitted', 'completed'];
    $missing = [];
    if (!in_array($deal['buyer_side_status'] ?? 'new', $okStatuses, true)) $missing[] = 'خریدار';
    if (!in_array($deal['seller_side_status'] ?? 'new', $okStatuses, true)) $missing[] = 'فروشنده';
    if (!empty($missing) && !$force) {
        echo json_encode([
            'success'      => false,
            'need_confirm' => true,
            'missing'      => $missing,
            'message'      => 'هنوز فیش پرداخت از طرف ' . implode(' و ', $missing) . ' دریافت نشده است. اگر مطمئنید، دوباره با تأیید صریح ارسال کنید.',
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    if (!dsUpdate($conn, $dealId, [
        'status'             => 'completed',
        'admin_completed'    => 1,
        'buyer_side_status'  => 'completed',
        'seller_side_status' => 'completed',
        'buyer_completed'    => 1,
        'seller_completed'   => 1,
        'completed_by'       => (int)$userId,
        'completed_at'       => 'NOW()',
    ])) {
        ds_fail('خطا در ثبت تسویه: ' . $conn->error, 'db');
    }

    // پس از تسویه‌ی نهایی، آگهیِ مرتبط باید از لیست آگهی‌های فعالِ «تبادل ارزی» خارج شود.
    // (در مسیر عادیِ پذیرش پیشنهاد این کار انجام می‌شود، ولی اگر معامله از مسیر دیگری
    //  ساخته شده باشد آگهی ممکن است هنوز active مانده باشد؛ اینجا تضمین می‌کنیم.)
    // همه‌ی صورت‌حساب‌های این معامله بسته و آرشیو می‌شوند
    dsCloseInvoice($conn, $dealId);

    $adId = (int)($deal['ad_id'] ?? 0);
    if ($adId > 0) {
        @$conn->query("UPDATE user_ads SET status = 'completed', updated_at = NOW()
                       WHERE id = $adId AND status <> 'completed'");

        // (جدید) اگر ریشه‌ی این آگهی یک آگهیِ mainbot باشد، دکمه‌ی پستِ کانال
        // را به «با موفقیت انجام شد» تغییر بده — دقیقاً همان لحظه‌ای که ادمین
        // از پنل، «تکمیل آگهی» را می‌زند
        require_once __DIR__ . '/../includes/mozayede_bridge.php';
        moz_syncDealCompleted($conn, $adId);
    }

    // اعلان به هر دو طرف
    foreach ([(int)$deal['buyer_id'], (int)$deal['seller_id']] as $uid) {
        if ($uid <= 0) continue;
        dsNotify($conn, $uid,
            'معامله تکمیل و تسویه شد ✅',
            "معامله‌ی {$deal['deal_code']} با موفقیت تسویه شد و در تاریخچه‌ی معاملات شما ثبت گردید.",
            ['type' => 'deal_completed', 'url' => '/ledor/arad.php', 'related_id' => $dealId]
        );
    }

    echo json_encode([
        'success' => true,
        'message' => 'تراکنش تسویه شد و از لیست «در انتظار تسویه» خارج گردید',
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

/* ================================================================
 * دریافت وضعیت کامل معامله
 * GET: deal_id
 * ================================================================ */
if ($action === 'get_deal') {
    $dealId = (int)($_GET['deal_id'] ?? 0);
    $deal = dsGetDeal($conn, $dealId);
    if (!$deal) ds_fail('معامله یافت نشد', 'not_found');

    $side = dsUserSide($deal, $userId);
    if (!$isAdmin && !$side) ds_fail('دسترسی غیرمجاز', 'forbidden');

    $payload = [
        'id'              => (int)$deal['id'],
        'deal_code'       => $deal['deal_code'],
        'currency'        => $deal['ad_currency'] ?? $deal['currency'] ?? '',
        'amount'          => $deal['amount'],
        'total_price'     => $deal['total_price'],
        'status'          => $deal['status'],
        'admin_completed' => (int)($deal['admin_completed'] ?? 0),
        'created_at'      => $deal['created_at'] ?? null,
        'completed_at'    => $deal['completed_at'] ?? null,
        'my_side'         => $side,
        'buyer'  => [
            'name'                => trim(($deal['b_first'] ?? '') . ' ' . ($deal['b_last'] ?? '')),
            'telegram'            => $deal['b_tg'] ?? '',
            'accounts'            => dsJsonArr($deal['buyer_admin_accounts'] ?? ''),
            'receipts'            => dsJsonArr($deal['buyer_receipts'] ?? ''),
            'settlement_receipts' => dsJsonArr($deal['buyer_settlement_receipts'] ?? ''),
            'admin_note'          => $deal['buyer_admin_note'] ?? '',
            'side_status'         => $deal['buyer_side_status'] ?? 'new',
            'payment_amount'      => $deal['buyer_payment_amount'] ?? null,
            'payment_currency'    => $deal['buyer_payment_currency'] ?? null,
        ],
        'seller' => [
            'name'                => trim(($deal['s_first'] ?? '') . ' ' . ($deal['s_last'] ?? '')),
            'telegram'            => $deal['s_tg'] ?? '',
            'accounts'            => dsJsonArr($deal['seller_admin_accounts'] ?? ''),
            'receipts'            => dsJsonArr($deal['seller_receipts'] ?? ''),
            'settlement_receipts' => dsJsonArr($deal['seller_settlement_receipts'] ?? ''),
            'admin_note'          => $deal['seller_admin_note'] ?? '',
            'payment_amount'      => $deal['seller_payment_amount'] ?? null,
            'payment_currency'    => $deal['seller_payment_currency'] ?? null,
            'side_status'         => $deal['seller_side_status'] ?? 'new',
        ],
    ];

    // کاربر عادی فقط بخش خودش را می‌بیند (حریم خصوصی طرف مقابل)
    if (!$isAdmin && $side) {
        $payload['side_data'] = $payload[$side];
        unset($payload['buyer'], $payload['seller']);
    }
    echo json_encode(['success' => true, 'is_admin' => $isAdmin, 'deal' => $payload], JSON_UNESCAPED_UNICODE);
    exit();
}

/* ================================================================
 * دریافت فیش‌های تسویه‌ی یک طرف
 * ================================================================ */
if ($action === 'get_side_receipts') {
    $dealId = (int)($_GET['deal_id'] ?? 0);
    $deal = dsGetDeal($conn, $dealId);
    if (!$deal) ds_fail('معامله یافت نشد', 'not_found');

    $side = dsUserSide($deal, $userId);
    if ($isAdmin) {
        $side = dsNormSide($_GET['side'] ?? $side ?? 'buyer');
    } elseif (!$side) {
        ds_fail('دسترسی غیرمجاز', 'forbidden');
    }

    $col     = dsSideCol($side, 'settlement_receipts');
    $noteCol = dsSideCol($side, 'admin_note');
    echo json_encode([
        'success'  => true,
        'receipts' => dsJsonArr($deal[$col] ?? ''),
        'note'     => $deal[$noteCol] ?? '',
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

/* ================================================================
 * دانلود یک فیش مشخص
 * GET: deal_id, side, kind(settlement|receipt), index
 * ================================================================ */
if ($action === 'download_receipt') {
    $dealId = (int)($_GET['deal_id'] ?? 0);
    $index  = (int)($_GET['index'] ?? 0);
    $kind   = (($_GET['kind'] ?? 'settlement') === 'receipt') ? 'receipts' : 'settlement_receipts';

    $deal = dsGetDeal($conn, $dealId);
    if (!$deal) { http_response_code(404); exit('not found'); }

    $side = dsUserSide($deal, $userId);
    if ($isAdmin) {
        $side = dsNormSide($_GET['side'] ?? $side ?? 'buyer');
    } elseif (!$side) {
        http_response_code(403); exit('forbidden');
    }

    $col  = dsSideCol($side, $kind);
    $list = dsJsonArr($deal[$col] ?? '');
    if (!isset($list[$index])) { http_response_code(404); exit('not found'); }

    // جلوگیری از path traversal: فقط داخل پوشه‌های مجاز
    $rel = ltrim(str_replace('\\', '/', (string)$list[$index]), '/');
    $okDir = false;
    foreach (['uploads/deal_receipts/', 'uploads/deal_settlements/'] as $bd) {
        if (strpos($rel, $bd) === 0) { $okDir = true; break; }
    }
    if (!$okDir || strpos($rel, '..') !== false) { http_response_code(403); exit('forbidden'); }

    $__sub = (strpos($rel, 'uploads/deal_receipts/') === 0) ? 'deal_receipts' : 'deal_settlements';
    $__file = basename($rel);
    $path = rtrim(avapay_upload_dir($__sub), '/') . '/' . $__file;
    if (!is_file($path)) {
        // سازگاری با فایل‌های قدیمی که هنوز داخل /ledor/uploads/ هستند
        $legacy = realpath(__DIR__ . '/../' . $rel);
        $root   = realpath(__DIR__ . '/../uploads');
        $path = ($legacy && $root && strpos($legacy, $root) === 0 && is_file($legacy)) ? $legacy : false;
    }
    if (!$path || !is_file($path)) { http_response_code(404); exit('missing'); }

    $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'webp' => 'image/webp', 'gif' => 'image/gif', 'heic' => 'image/heic',
        'pdf' => 'application/pdf',
    ][$ext] ?? 'application/octet-stream';

    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit();
}

ds_fail('اکشن نامعتبر: ' . htmlspecialchars((string)$action), 'bad_action');
