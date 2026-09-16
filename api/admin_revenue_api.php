<?php
/**
 * api/admin_revenue_api.php
 * ------------------------------------------------------------------
 * درآمد ادمین، تفکیک‌شده بر اساس ارزهای اصلی: USD / EUR / USDT / IRR(تومان)
 *
 * منابع درآمد:
 *   ۱) کمیسیون معاملات تبادل ارزیِ تکمیل‌شده (ad_deals) — با ارز خودش
 *   ۲) موجودی منفی کاربران (بدهی) — با ارز خودش
 *   ۳) رکوردهای دستی که ادمین اضافه می‌کند (admin_revenue_entries)
 *
 * برخلاف نسخه‌ی قبلی هیچ تبدیل ارزی اجباری انجام نمی‌شود؛ هر ارز جدا
 * گزارش می‌شود.
 *
 * اکشن‌ها:
 *   get_revenue   (GET)   خلاصه + سری ماهانه به تفکیک ارز
 *   list_entries  (GET)   فهرست رکوردهای دستی
 *   add_entry     (POST)  افزودن رکورد دستی
 *   update_entry  (POST)  ویرایش رکورد دستی
 *   delete_entry  (POST)  حذف رکورد دستی
 * ------------------------------------------------------------------
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');
ob_start();

function arv_fail($msg, $code = null) {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    echo json_encode(['success' => false, 'message' => $msg, 'error_code' => $code], JSON_UNESCAPED_UNICODE);
    exit();
}
set_exception_handler(function ($e) { arv_fail('خطای سرور: ' . $e->getMessage(), 'exception'); });
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'خطای داخلی سرور: ' . $err['message'], 'error_code' => 'fatal'], JSON_UNESCAPED_UNICODE);
    }
});

require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) arv_fail('لطفاً وارد شوید', 'auth');

$userId = (int)$_SESSION['user_id'];
$ADMIN_TELEGRAM_ID = defined('ADMIN_TELEGRAM_ID') ? (string)ADMIN_TELEGRAM_ID : '5330629504';
$isAdmin = false;
$__ur = @$conn->query("SELECT telegram_id, is_admin FROM users WHERE id = $userId");
$urow = $__ur ? $__ur->fetch_assoc() : null;
if ($urow && ((int)($urow['is_admin'] ?? 0) === 1 || (string)($urow['telegram_id'] ?? '') === $ADMIN_TELEGRAM_ID || (string)$userId === $ADMIN_TELEGRAM_ID)) {
    $isAdmin = true;
}
if (!$isAdmin) arv_fail('دسترسی غیرمجاز', 'forbidden');

define('ARV_CURRENCIES', ['USD', 'EUR', 'USDT', 'IRR']);

/* ---------- آماده‌سازی اسکیما (امن روی MySQL و MariaDB) ---------- */
function arvEnsureSchema($conn) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $cols = [];
        $r = $conn->query("SHOW COLUMNS FROM `ad_deals`");
        if ($r) { while ($row = $r->fetch_assoc()) { $cols[strtolower($row['Field'])] = true; } }
        if (!isset($cols['commission_amount']))   $conn->query("ALTER TABLE `ad_deals` ADD COLUMN `commission_amount` DECIMAL(20,6) DEFAULT NULL");
        if (!isset($cols['commission_currency'])) $conn->query("ALTER TABLE `ad_deals` ADD COLUMN `commission_currency` VARCHAR(10) DEFAULT NULL");

        $conn->query("CREATE TABLE IF NOT EXISTS `admin_revenue_entries` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `currency` VARCHAR(10) NOT NULL,
            `amount` DECIMAL(20,6) NOT NULL,
            `note` VARCHAR(255) DEFAULT NULL,
            `entry_date` DATE NOT NULL,
            `created_by` INT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('arvEnsureSchema: ' . $e->getMessage());
    }
}
arvEnsureSchema($conn);

/** ارزها را به چهار ارز اصلی نگاشت می‌کند (تومان/ریال → IRR) */
function arvNormCur($c) {
    $c = strtoupper(trim((string)$c));
    if ($c === 'تومان' || $c === 'ریال' || $c === 'TOMAN' || $c === 'RIAL' || $c === '') return 'IRR';
    return in_array($c, ARV_CURRENCIES, true) ? $c : 'IRR';
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/* ================================================================
 * گزارش درآمد به تفکیک ارز
 * ================================================================ */
if ($action === 'get_revenue') {
    $months = max(1, min(24, (int)($_GET['months'] ?? 6)));

    $emptyByCur      = array_fill_keys(ARV_CURRENCIES, 0.0);
    $commissionByCur = $emptyByCur;
    $debtByCur       = $emptyByCur;
    $manualByCur     = $emptyByCur;

    $monthKeys = [];
    for ($i = $months - 1; $i >= 0; $i--) $monthKeys[] = date('Y-m', strtotime("-{$i} months"));
    $monthly = [];
    foreach (ARV_CURRENCIES as $c) $monthly[$c] = array_fill_keys($monthKeys, 0.0);

    // ---- ۱) کمیسیون معاملات تکمیل‌شده ----
    $dealCount = 0; $missingCommissionCount = 0;
    $r = @$conn->query("SELECT commission_amount, commission_currency, completed_at, created_at
                         FROM ad_deals WHERE status = 'completed' ORDER BY id DESC LIMIT 5000");
    if ($r) {
        while ($d = $r->fetch_assoc()) {
            if ($d['commission_amount'] === null) { $missingCommissionCount++; continue; }
            $cur = arvNormCur($d['commission_currency']);
            $amt = (float)$d['commission_amount'];
            $commissionByCur[$cur] += $amt;
            $dealCount++;
            $when = $d['completed_at'] ?: $d['created_at'];
            if ($when) {
                $k = date('Y-m', strtotime($when));
                if (isset($monthly[$cur][$k])) $monthly[$cur][$k] += $amt;
            }
        }
    }

    // ---- ۲) موجودی منفی کاربران (بدهی) ----
    $debtUsers = 0;
    $br = @$conn->query("SELECT balance_usd, balance_eur, balance_usdt, balance_irr FROM users
                          WHERE balance_usd < 0 OR balance_eur < 0 OR balance_usdt < 0 OR balance_irr < 0");
    if ($br) {
        while ($u = $br->fetch_assoc()) {
            $debtUsers++;
            if ((float)$u['balance_usd']  < 0) $debtByCur['USD']  += abs((float)$u['balance_usd']);
            if ((float)$u['balance_eur']  < 0) $debtByCur['EUR']  += abs((float)$u['balance_eur']);
            if ((float)$u['balance_usdt'] < 0) $debtByCur['USDT'] += abs((float)$u['balance_usdt']);
            if ((float)$u['balance_irr']  < 0) $debtByCur['IRR']  += abs((float)$u['balance_irr']);
        }
    }

    // ---- ۳) رکوردهای دستی ----
    $mr = @$conn->query("SELECT currency, amount, entry_date FROM admin_revenue_entries");
    if ($mr) {
        while ($m = $mr->fetch_assoc()) {
            $cur = arvNormCur($m['currency']);
            $amt = (float)$m['amount'];
            $manualByCur[$cur] += $amt;
            if (!empty($m['entry_date'])) {
                $k = date('Y-m', strtotime($m['entry_date']));
                if (isset($monthly[$cur][$k])) $monthly[$cur][$k] += $amt;
            }
        }
    }

    $totals = [];
    foreach (ARV_CURRENCIES as $c) {
        $totals[$c] = [
            'commission' => round($commissionByCur[$c], 6),
            'debt'       => round($debtByCur[$c], 6),
            'manual'     => round($manualByCur[$c], 6),
            'total'      => round($commissionByCur[$c] + $debtByCur[$c] + $manualByCur[$c], 6),
        ];
    }
    $series = [];
    foreach (ARV_CURRENCIES as $c) $series[$c] = array_values(array_map(fn($v) => round($v, 6), $monthly[$c]));

    echo json_encode([
        'success'        => true,
        'currencies'     => ARV_CURRENCIES,
        'totals'         => $totals,
        'monthly_labels' => $monthKeys,
        'monthly'        => $series,
        'deal_count'     => $dealCount,
        'missing_count'  => $missingCommissionCount,
        'debt_users'     => $debtUsers,
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

/* ================================================================
 * رکوردهای دستی
 * ================================================================ */
if ($action === 'list_entries') {
    $rows = [];
    $r = @$conn->query("SELECT id, currency, amount, note, entry_date, created_at
                         FROM admin_revenue_entries ORDER BY entry_date DESC, id DESC LIMIT 200");
    if ($r) { while ($row = $r->fetch_assoc()) { $rows[] = $row; } }
    echo json_encode(['success' => true, 'entries' => $rows], JSON_UNESCAPED_UNICODE);
    exit();
}

if ($action === 'add_entry' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $cur  = arvNormCur($data['currency'] ?? '');
    $amt  = (float)($data['amount'] ?? 0);
    $note = trim((string)($data['note'] ?? ''));
    $date = trim((string)($data['entry_date'] ?? ''));
    if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
    if ($amt == 0) arv_fail('مبلغ نمی‌تواند صفر باشد', 'bad_amount');

    $st = $conn->prepare("INSERT INTO admin_revenue_entries (currency, amount, note, entry_date, created_by) VALUES (?,?,?,?,?)");
    if (!$st) arv_fail('خطا در ذخیره‌سازی: ' . $conn->error, 'db');
    $st->bind_param('sdssi', $cur, $amt, $note, $date, $userId);
    $st->execute();
    $newId = $conn->insert_id;
    $st->close();

    echo json_encode(['success' => true, 'message' => 'رکورد درآمد ثبت شد', 'id' => $newId], JSON_UNESCAPED_UNICODE);
    exit();
}

if ($action === 'update_entry' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id   = (int)($data['id'] ?? 0);
    $cur  = arvNormCur($data['currency'] ?? '');
    $amt  = (float)($data['amount'] ?? 0);
    $note = trim((string)($data['note'] ?? ''));
    $date = trim((string)($data['entry_date'] ?? ''));
    if ($id <= 0) arv_fail('رکورد نامعتبر', 'bad_id');
    if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
    if ($amt == 0) arv_fail('مبلغ نمی‌تواند صفر باشد', 'bad_amount');

    $st = $conn->prepare("UPDATE admin_revenue_entries SET currency=?, amount=?, note=?, entry_date=? WHERE id=?");
    if (!$st) arv_fail('خطا در به‌روزرسانی: ' . $conn->error, 'db');
    $st->bind_param('sdssi', $cur, $amt, $note, $date, $id);
    $st->execute();
    $st->close();

    echo json_encode(['success' => true, 'message' => 'رکورد به‌روزرسانی شد'], JSON_UNESCAPED_UNICODE);
    exit();
}

if ($action === 'delete_entry' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) arv_fail('رکورد نامعتبر', 'bad_id');
    $st = $conn->prepare("DELETE FROM admin_revenue_entries WHERE id = ?");
    if (!$st) arv_fail('خطا در حذف: ' . $conn->error, 'db');
    $st->bind_param('i', $id);
    $st->execute();
    $st->close();
    echo json_encode(['success' => true, 'message' => 'رکورد حذف شد'], JSON_UNESCAPED_UNICODE);
    exit();
}

arv_fail('اکشن نامعتبر است', 'bad_action');
