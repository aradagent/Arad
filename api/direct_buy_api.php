<?php
/**
 * api/direct_buy_api.php
 * ---------------------------------------------------------------------------
 * «خرید مستقیم»: کاربر یک ارز (USD/EUR/USDT) و یک قیمت هدف (تومان) را از روی
 * نمودار لحظه‌ای انتخاب می‌کند، مقدار مورد نظر را وارد می‌کند و درخواست ثبت
 * می‌شود. درخواست به همه‌ی ادمین‌ها اطلاع داده می‌شود. هر زمان ادمین آماده
 * بود، از پنل مدیریت «موجود است» را می‌زند و/یا صورت‌حساب می‌سازد (با استفاده
 * از همان سیستم فیش/صورت‌حساب موجود در unpaid_invoices — چیزی از نو ساخته
 * نشده) تا کاربر پرداخت کند.
 * ---------------------------------------------------------------------------
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once '../config/database.php';
require_once __DIR__ . '/../includes/notify_helper.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'لطفاً وارد شوید']);
    exit();
}
$userId = $_SESSION['user_id'];

// ---------- بررسی ادمین بودن (همان الگوی استفاده‌شده در بقیه‌ی API های ادمین) ----------
$ADMIN_TELEGRAM_ID = '5330629504';
$isAdmin = false;
$__adminChk = $conn->prepare("SELECT telegram_id, is_admin FROM users WHERE id = ?");
$__adminChk->bind_param("i", $userId);
$__adminChk->execute();
$__adminRow = $__adminChk->get_result()->fetch_assoc();
if ($__adminRow) {
    if ((string)($__adminRow['telegram_id'] ?? '') === $ADMIN_TELEGRAM_ID) $isAdmin = true;
    if ((int)($__adminRow['is_admin'] ?? 0) === 1) $isAdmin = true;
}
if (!$isAdmin && (int)$userId === (int)$ADMIN_TELEGRAM_ID) $isAdmin = true;

// ---------- ساخت جدول در صورت نبود ----------
$conn->query("CREATE TABLE IF NOT EXISTS `direct_buy_requests` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `currency` ENUM('USD','EUR','USDT') NOT NULL,
    `target_price` DECIMAL(18,2) NOT NULL,
    `amount` DECIMAL(18,4) NOT NULL,
    `total_toman` DECIMAL(18,2) NOT NULL,
    `status` ENUM('pending','available','invoiced','completed','cancelled') NOT NULL DEFAULT 'pending',
    `admin_note` TEXT DEFAULT NULL,
    `invoice_id` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_status (status)
)");

$ALLOWED_CURRENCIES = ['USD', 'EUR', 'USDT'];

function db_round_currency($cur) {
    // تتر با دو رقم اعشار، دلار/یورو معمولاً صحیح — ولی برای سادگی و انعطاف
    // اجازه‌ی تا ۴ رقم اعشار روی مقدار (amount) داده می‌شود؛ قیمت هدف تومان صحیح است.
    return $cur;
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

/* =============================================================================
 * کاربر: تاریخچه‌ی قیمت ۲۴ ساعت اخیر + قیمت لحظه‌ای (برای رسم نمودار)
 * ========================================================================== */
if ($action === 'price_history') {
    $cur = strtoupper(trim($_GET['currency'] ?? ''));
    if (!in_array($cur, $ALLOWED_CURRENCIES, true)) {
        echo json_encode(['success' => false, 'message' => 'ارز نامعتبر است']);
        exit();
    }
    $lookupCur = ($cur === 'USDT') ? "IN ('USDT','TETHER')" : "= '" . $conn->real_escape_string($cur) . "'";

    $liveRow = $conn->query("SELECT price, change_24h FROM currency_rates WHERE currency $lookupCur ORDER BY id DESC LIMIT 1")->fetch_assoc();
    $livePrice = $liveRow ? (float)$liveRow['price'] : 0.0;
    $change24h = $liveRow ? (float)$liveRow['change_24h'] : 0.0;

    $series = [];
    // برای نمودار «خرید مستقیم» باید حداقل سه ماه تاریخچه نشان داده شود تا کاربر
    // بتواند با نگاه به نوسانات چند ماه اخیر، روی نمودار قیمت هدف بزند — قبلاً
    // این کوئری فقط ۲۴ ساعت اخیر را می‌گرفت. جدول currency_rate_history خودش
    // حداکثر ۱۰۰ روز نگه‌داری می‌شود (dashboard.php)، پس همان بازه اینجا هم
    // خواسته می‌شود.
    $histRes = $conn->query("SELECT price, recorded_at FROM currency_rate_history WHERE currency $lookupCur AND recorded_at >= DATE_SUB(NOW(), INTERVAL 100 DAY) ORDER BY recorded_at ASC");
    if ($histRes) {
        while ($r = $histRes->fetch_assoc()) {
            $series[] = ['p' => (float)$r['price'], 't' => $r['recorded_at']];
        }
    }
    // اگر تاریخچه‌ی کافی نبود (سرور تازه راه‌اندازی شده)، دست‌کم قیمت لحظه‌ای را به‌عنوان یک نقطه بگذار
    if (count($series) < 2 && $livePrice > 0) {
        $series[] = ['p' => $livePrice, 't' => date('Y-m-d H:i:s')];
    }

    echo json_encode([
        'success'   => true,
        'currency'  => $cur,
        'live'      => $livePrice,
        'change24h' => $change24h,
        'series'    => $series,
    ]);
    exit();
}

/* =============================================================================
 * کاربر: ثبت درخواست خرید مستقیم
 * ========================================================================== */
if ($action === 'submit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) $data = $_POST;

    $cur    = strtoupper(trim($data['currency'] ?? ''));
    $target = (float)($data['target_price'] ?? 0);
    $amount = (float)($data['amount'] ?? 0);

    if (!in_array($cur, $ALLOWED_CURRENCIES, true)) {
        echo json_encode(['success' => false, 'message' => 'ارز نامعتبر است']); exit();
    }
    if ($target <= 0) {
        echo json_encode(['success' => false, 'message' => 'قیمت هدف نامعتبر است']); exit();
    }
    if ($amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'مقدار باید بزرگ‌تر از صفر باشد']); exit();
    }
    // سقف‌های منطقی برای جلوگیری از ورودی اشتباه/سوءاستفاده
    if ($target > 100000000 || $amount > 1000000) {
        echo json_encode(['success' => false, 'message' => 'مقدار وارد شده خیلی بزرگ است']); exit();
    }

    $total = round($target * $amount, 2);

    $st = $conn->prepare("INSERT INTO direct_buy_requests (user_id, currency, target_price, amount, total_toman, status) VALUES (?, ?, ?, ?, ?, 'pending')");
    $st->bind_param("isddd", $userId, $cur, $target, $amount, $total);
    $st->execute();
    $newId = $st->insert_id;
    $st->close();

    $uRow = $conn->query("SELECT first_name, last_name FROM users WHERE id=" . (int)$userId)->fetch_assoc();
    $uName = $uRow ? trim(($uRow['first_name'] ?? '') . ' ' . ($uRow['last_name'] ?? '')) : 'کاربر';
    if ($uName === '') $uName = 'کاربر #' . $userId;

    if (function_exists('notifyAdmins')) {
        notifyAdmins($conn,
            '🟢 درخواست خرید مستقیم جدید',
            "{$uName} درخواست خرید " . number_format($amount, 4) . " {$cur} در قیمت " . number_format($target) . " تومان ثبت کرد.",
            ['type' => 'direct_buy_new', 'url' => '/ledor/admin_panel.php#directbuy', 'related_id' => $newId, 'immediate' => true]
        );
    }

    echo json_encode(['success' => true, 'id' => $newId, 'message' => 'درخواست شما ثبت شد و به ادمین اطلاع داده شد']);
    exit();
}

/* =============================================================================
 * کاربر: لیست درخواست‌های خودم
 * ========================================================================== */
if ($action === 'my_list') {
    $rows = [];
    $res = $conn->query("SELECT * FROM direct_buy_requests WHERE user_id=" . (int)$userId . " ORDER BY created_at DESC LIMIT 30");
    if ($res) { while ($r = $res->fetch_assoc()) $rows[] = $r; }
    echo json_encode(['success' => true, 'requests' => $rows]);
    exit();
}

/* =============================================================================
 * کاربر: لغو درخواست (فقط تا وقتی pending است)
 * ========================================================================== */
if ($action === 'cancel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) $data = $_POST;
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'شناسه نامعتبر']); exit(); }

    $row = $conn->query("SELECT * FROM direct_buy_requests WHERE id={$id} AND user_id=" . (int)$userId)->fetch_assoc();
    if (!$row) { echo json_encode(['success' => false, 'message' => 'درخواست یافت نشد']); exit(); }
    if ($row['status'] !== 'pending') { echo json_encode(['success' => false, 'message' => 'این درخواست دیگر قابل لغو نیست']); exit(); }

    $conn->query("UPDATE direct_buy_requests SET status='cancelled' WHERE id={$id}");
    echo json_encode(['success' => true]);
    exit();
}

/* =============================================================================
 * از این پس فقط ادمین
 * ========================================================================== */
if (!$isAdmin) {
    echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']);
    exit();
}

/* ---------- ادمین: لیست درخواست‌ها ---------- */
if ($action === 'admin_list') {
    $status = trim($_GET['status'] ?? 'pending');
    $allowedStatus = ['pending', 'available', 'invoiced', 'completed', 'cancelled', 'all'];
    if (!in_array($status, $allowedStatus, true)) $status = 'pending';

    $where = ($status === 'all') ? '' : ("WHERE d.status='" . $conn->real_escape_string($status) . "'");
    $sql = "SELECT d.*, u.first_name, u.last_name, u.telegram_id
            FROM direct_buy_requests d
            JOIN users u ON u.id = d.user_id
            {$where}
            ORDER BY d.created_at DESC
            LIMIT 200";
    $rows = [];
    $res = $conn->query($sql);
    if ($res) { while ($r = $res->fetch_assoc()) $rows[] = $r; }
    echo json_encode(['success' => true, 'requests' => $rows]);
    exit();
}

/* ---------- ادمین: علامت‌گذاری «موجود است» ---------- */
if ($action === 'admin_mark_available' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) $data = $_POST;
    $id   = (int)($data['id'] ?? 0);
    $note = trim((string)($data['note'] ?? ''));
    if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'شناسه نامعتبر']); exit(); }

    $row = $conn->query("SELECT * FROM direct_buy_requests WHERE id={$id}")->fetch_assoc();
    if (!$row) { echo json_encode(['success' => false, 'message' => 'درخواست یافت نشد']); exit(); }
    if (!in_array($row['status'], ['pending'], true)) {
        echo json_encode(['success' => false, 'message' => 'این درخواست در وضعیت قابل تغییر نیست']); exit();
    }

    $st = $conn->prepare("UPDATE direct_buy_requests SET status='available', admin_note=? WHERE id=?");
    $st->bind_param("si", $note, $id);
    $st->execute();
    $st->close();

    if (function_exists('notifyUser')) {
        notifyUser($conn, (int)$row['user_id'],
            '🟢 ارز شما آماده شد',
            number_format((float)$row['amount'], 4) . " {$row['currency']} با قیمت " . number_format((float)$row['target_price']) . " تومان موجود است — منتظر صورت‌حساب باشید." . ($note !== '' ? " ({$note})" : ''),
            ['type' => 'direct_buy_available', 'url' => '/ledor/dashboard.php', 'related_id' => $id, 'immediate' => true]
        );
    }

    echo json_encode(['success' => true]);
    exit();
}

/* ---------- ادمین: لغو درخواست ---------- */
if ($action === 'admin_cancel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) $data = $_POST;
    $id     = (int)($data['id'] ?? 0);
    $reason = trim((string)($data['reason'] ?? ''));
    if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'شناسه نامعتبر']); exit(); }

    $row = $conn->query("SELECT * FROM direct_buy_requests WHERE id={$id}")->fetch_assoc();
    if (!$row) { echo json_encode(['success' => false, 'message' => 'درخواست یافت نشد']); exit(); }

    $st = $conn->prepare("UPDATE direct_buy_requests SET status='cancelled', admin_note=? WHERE id=?");
    $st->bind_param("si", $reason, $id);
    $st->execute();
    $st->close();

    if (function_exists('notifyUser')) {
        notifyUser($conn, (int)$row['user_id'],
            '❌ درخواست خرید مستقیم لغو شد',
            "درخواست خرید " . number_format((float)$row['amount'], 4) . " {$row['currency']} شما لغو شد." . ($reason !== '' ? " دلیل: {$reason}" : ''),
            ['type' => 'direct_buy_cancelled', 'url' => '/ledor/dashboard.php', 'related_id' => $id, 'immediate' => true]
        );
    }

    echo json_encode(['success' => true]);
    exit();
}

/* ---------- ادمین: پیوند‌دادن یک صورت‌حساب صادرشده (unpaid_invoices) به این درخواست ----------
 * توجه: خودِ ساخت صورت‌حساب همچنان از api/unpaid_invoice_api.php?action=create_invoice
 * انجام می‌شود (چیزی دوباره‌سازی نشده)؛ این‌جا فقط بعد از ساخت موفق آن، شناسه‌ی
 * صورت‌حساب را به این درخواست وصل می‌کنیم تا وضعیتش invoiced شود. */
if ($action === 'admin_link_invoice' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) $data = $_POST;
    $id        = (int)($data['id'] ?? 0);
    $invoiceId = (int)($data['invoice_id'] ?? 0);
    if ($id <= 0 || $invoiceId <= 0) { echo json_encode(['success' => false, 'message' => 'اطلاعات ناقص است']); exit(); }

    $st = $conn->prepare("UPDATE direct_buy_requests SET status='invoiced', invoice_id=? WHERE id=?");
    $st->bind_param("ii", $invoiceId, $id);
    $st->execute();
    $st->close();

    echo json_encode(['success' => true]);
    exit();
}

echo json_encode(['success' => false, 'message' => 'عملیات نامعتبر است']);
