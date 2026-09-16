<?php
/**
 * api/admin_exchange_api.php
 * ---------------------------------------------------------------------------
 * Endpointهای فقط-خواندنی برای پنل مدیریت ادغام‌شده (admin_panel.php):
 *   - list_transfers   : لیست حواله‌های ارزی (money_transfers)
 *   - list_deals       : لیست معاملات فعال بازار ارز (ad_deals در انتظار تسویه)
 *   - counts           : تعداد آیتم‌های نیازمند اقدام برای کاشی‌های «اقدامات لازم»
 *
 * اکشن‌های نوشتنی (تایید/رد/آپلود فیش/ارسال شماره‌حساب/تکمیل معامله) از همان
 * APIهای موجود استفاده می‌کنند:
 *   transfer_api.php  (admin_update_status, admin_send_accounts, upload_settlement)
 *   withdraw.php      (approve, reject, upload_receipt)  ← تسویه حساب
 *   deals_api.php     (complete_deal)                    ← تبادل ارزی
 * ---------------------------------------------------------------------------
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'لطفاً وارد شوید']);
    exit();
}

$userId = (int) $_SESSION['user_id'];

// بررسی ادمین (همان منطق سایر صفحات)
$isAdminUser = false;
$u = $conn->query("SELECT telegram_id, is_admin FROM users WHERE id = $userId")->fetch_assoc();
if ($u) {
    if ((string)($u['telegram_id'] ?? '') === (string)ADMIN_TELEGRAM_ID || (int)($u['is_admin'] ?? 0) === 1) {
        $isAdminUser = true;
    }
}
if (!$isAdminUser && $userId == 5330629504) $isAdminUser = true;

if (!$isAdminUser) {
    echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']);
    exit();
}

$action = $_GET['action'] ?? '';

function tableExists($conn, $t) {
    $r = @$conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($t) . "'");
    return $r && $r->num_rows > 0;
}
function colExists($conn, $table, $col) {
    $r = @$conn->query("SHOW COLUMNS FROM `$table` LIKE '" . $conn->real_escape_string($col) . "'");
    return $r && $r->num_rows > 0;
}

/* ======================================================================
 * لیست حواله‌های ارزی
 * ==================================================================== */
if ($action === 'list_transfers') {
    if (!tableExists($conn, 'money_transfers')) {
        echo json_encode(['success' => true, 'transfers' => []]);
        exit();
    }
    $filter = $_GET['filter'] ?? 'all'; // all | pending | active | completed | archived
    $hasArchived = colExists($conn, 'money_transfers', 'archived');
    $archCond = $hasArchived ? "t.archived = 0" : "1";
    $where = "WHERE $archCond";
    if ($filter === 'pending')   $where = "WHERE t.status = 'pending' AND $archCond";
    elseif ($filter === 'active') $where = "WHERE t.status IN ('approved','awaiting_payment','payment_submitted') AND $archCond";
    elseif ($filter === 'completed') $where = "WHERE t.status = 'completed' AND $archCond";
    elseif ($filter === 'archived') $where = $hasArchived ? "WHERE t.archived = 1" : "WHERE 0";

    $hasAccounts = colExists($conn, 'money_transfers', 'admin_accounts');
    $hasReceipts = colExists($conn, 'money_transfers', 'payment_receipts');

    $sql = "SELECT t.*, u.first_name, u.last_name, u.telegram_id, u.email
            FROM money_transfers t
            JOIN users u ON t.user_id = u.id
            $where
            ORDER BY t.created_at DESC
            LIMIT 300";
    $res = $conn->query($sql);
    $list = [];
    while ($res && $row = $res->fetch_assoc()) {
        // تبدیل JSON شماره‌حساب‌ها و فیش‌ها به آرایه
        $row['admin_accounts_arr'] = [];
        if ($hasAccounts && !empty($row['admin_accounts'])) {
            $d = json_decode($row['admin_accounts'], true);
            if (is_array($d)) $row['admin_accounts_arr'] = $d;
        }
        $row['payment_receipts_arr'] = [];
        if ($hasReceipts && !empty($row['payment_receipts'])) {
            $d = json_decode($row['payment_receipts'], true);
            if (is_array($d)) $row['payment_receipts_arr'] = $d;
        }
        if (empty($row['payment_receipts_arr']) && !empty($row['payment_receipt'])) {
            $row['payment_receipts_arr'] = [$row['payment_receipt']];
        }
        $list[] = $row;
    }
    echo json_encode(['success' => true, 'transfers' => $list]);
    exit();
}

/* ======================================================================
 * لیست معاملات بازار ارز (در انتظار تسویه)
 * ==================================================================== */
if ($action === 'list_deals') {
    if (!tableExists($conn, 'ad_deals')) {
        echo json_encode(['success' => true, 'deals' => []]);
        exit();
    }
    $filter = $_GET['filter'] ?? 'pending'; // pending | completed | all | archived
    $hasArchivedD = colExists($conn, 'ad_deals', 'archived');
    $archCondD = $hasArchivedD ? "d.archived = 0" : "1";
    $where = "WHERE d.admin_completed = 0 AND d.status = 'pending' AND $archCondD";
    if ($filter === 'completed') $where = "WHERE d.status = 'completed' AND $archCondD";
    elseif ($filter === 'all')   $where = "WHERE $archCondD";
    elseif ($filter === 'archived') $where = $hasArchivedD ? "WHERE d.archived = 1" : "WHERE 0";

    $sql = "SELECT d.*, a.currency,
                   ub.first_name AS buyer_first_name, ub.last_name AS buyer_last_name, ub.telegram_id AS buyer_telegram,
                   us.first_name AS seller_first_name, us.last_name AS seller_last_name, us.telegram_id AS seller_telegram
            FROM ad_deals d
            JOIN user_ads a ON d.ad_id = a.id
            JOIN users ub ON d.buyer_id = ub.id
            JOIN users us ON d.seller_id = us.id
            $where
            ORDER BY d.created_at DESC
            LIMIT 300";
    $res = $conn->query($sql);
    if (!$res) {
        echo json_encode(['success' => false, 'message' => 'خطای پایگاه‌داده: ' . $conn->error, 'deals' => []]);
        exit();
    }

    $jarr = function ($v) { $d = json_decode((string)($v ?? ''), true); return is_array($d) ? $d : []; };
    $list = [];
    while ($row = $res->fetch_assoc()) {
        // خلاصه‌ی وضعیت هر طرف تا ادمین بدون باز کردن مدال هم بفهمد چه‌کاری لازم است
        foreach (['buyer', 'seller'] as $sd) {
            $row[$sd . '_side'] = [
                'status'            => $row[$sd . '_side_status'] ?? 'new',
                'accounts_count'    => count($jarr($row[$sd . '_admin_accounts'] ?? '')),
                'receipts_count'    => count($jarr($row[$sd . '_receipts'] ?? '')),
                'settlement_count'  => count($jarr($row[$sd . '_settlement_receipts'] ?? '')),
            ];
        }
        $row['receipts_total'] = $row['buyer_side']['receipts_count'] + $row['seller_side']['receipts_count'];
        $list[] = $row;
    }
    echo json_encode(['success' => true, 'deals' => $list]);
    exit();
}

/* ======================================================================
 * شمارنده‌های «اقدامات لازم» برای کاشی‌های تابلوی کلی
 * ==================================================================== */
if ($action === 'counts') {
    $c = [
        'transfers_new'  => 0, // حواله‌های در انتظار تایید یا فیش‌دریافت‌شده
        'settlements_new'=> 0, // درخواست‌های تسویه در انتظار
        'deals_new'      => 0, // معاملات بازار ارز در انتظار تسویه
        'deal_receipts_new' => 0, // معاملاتی که کاربر فیش پرداخت فرستاده و منتظر اقدام ادمین است
    ];

    if (tableExists($conn, 'money_transfers')) {
        $archCol = colExists($conn, 'money_transfers', 'archived') ? " AND archived = 0" : "";
        $r = $conn->query("SELECT COUNT(*) c FROM money_transfers WHERE status IN ('pending','payment_submitted')$archCol");
        if ($r) $c['transfers_new'] = (int) $r->fetch_assoc()['c'];
    }
    if (tableExists($conn, 'withdrawal_requests')) {
        $archCol = colExists($conn, 'withdrawal_requests', 'archived') ? " AND archived = 0" : "";
        $r = $conn->query("SELECT COUNT(*) c FROM withdrawal_requests WHERE status = 'pending'$archCol");
        if ($r) $c['settlements_new'] = (int) $r->fetch_assoc()['c'];
    }
    if (tableExists($conn, 'ad_deals')) {
        $archCol = colExists($conn, 'ad_deals', 'archived') ? " AND archived = 0" : "";
        $r = $conn->query("SELECT COUNT(*) c FROM ad_deals WHERE admin_completed = 0 AND status = 'pending'$archCol");
        if ($r) $c['deals_new'] = (int) $r->fetch_assoc()['c'];

        if (colExists($conn, 'ad_deals', 'buyer_side_status') && colExists($conn, 'ad_deals', 'seller_side_status')) {
            $r = $conn->query("SELECT COUNT(*) c FROM ad_deals
                               WHERE admin_completed = 0 AND status = 'pending'
                                 AND (buyer_side_status = 'receipt_submitted' OR seller_side_status = 'receipt_submitted')$archCol");
            if ($r) $c['deal_receipts_new'] = (int) $r->fetch_assoc()['c'];
        }
    }
    echo json_encode(['success' => true, 'counts' => $c]);
    exit();
}

/* ======================================================================
 * آرشیو/حذف درخواست‌ها (برای مرتب‌سازی پنل بعد از انجام کار)
 *   type: transfer | settlement | deal
 * ==================================================================== */
function ax_ensureArchivedCol($conn, $table) {
    if (!colExists($conn, $table, 'archived')) {
        @$conn->query("ALTER TABLE `$table` ADD COLUMN `archived` TINYINT(1) NOT NULL DEFAULT 0");
    }
}

if ($action === 'archive_request' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $type = $input['type'] ?? '';
    $id   = intval($input['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'شناسه نامعتبر']); exit(); }

    $map = ['transfer' => 'money_transfers', 'settlement' => 'withdrawal_requests', 'deal' => 'ad_deals'];
    if (!isset($map[$type])) { echo json_encode(['success' => false, 'message' => 'نوع نامعتبر']); exit(); }
    $table = $map[$type];
    if (!tableExists($conn, $table)) { echo json_encode(['success' => false, 'message' => 'جدول یافت نشد']); exit(); }

    ax_ensureArchivedCol($conn, $table);
    $conn->query("UPDATE `$table` SET archived = 1 WHERE id = $id");
    echo json_encode(['success' => true, 'message' => 'به آرشیو منتقل شد']);
    exit();
}

if ($action === 'unarchive_request' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $type = $input['type'] ?? '';
    $id   = intval($input['id'] ?? 0);
    $map = ['transfer' => 'money_transfers', 'settlement' => 'withdrawal_requests', 'deal' => 'ad_deals'];
    if (!isset($map[$type]) || $id <= 0) { echo json_encode(['success' => false, 'message' => 'ورودی نامعتبر']); exit(); }
    $table = $map[$type];
    ax_ensureArchivedCol($conn, $table);
    $conn->query("UPDATE `$table` SET archived = 0 WHERE id = $id");
    echo json_encode(['success' => true, 'message' => 'از آرشیو خارج شد']);
    exit();
}

if ($action === 'delete_request' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $type = $input['type'] ?? '';
    $id   = intval($input['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'شناسه نامعتبر']); exit(); }

    $map = ['transfer' => 'money_transfers', 'settlement' => 'withdrawal_requests', 'deal' => 'ad_deals'];
    if (!isset($map[$type])) { echo json_encode(['success' => false, 'message' => 'نوع نامعتبر']); exit(); }
    $table = $map[$type];
    if (!tableExists($conn, $table)) { echo json_encode(['success' => false, 'message' => 'جدول یافت نشد']); exit(); }

    $stmt = $conn->prepare("DELETE FROM `$table` WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    echo json_encode(['success' => true, 'message' => 'حذف شد']);
    exit();
}

echo json_encode(['success' => false, 'message' => 'اکشن نامعتبر']);
