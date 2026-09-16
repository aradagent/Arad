<?php
// api/tier_api.php - سیستم سطح‌بندی خودکار کاربران (Tier/VIP)

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';
require_once __DIR__ . '/../includes/tier_system.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'لطفاً وارد شوید']);
    exit();
}

$userId = (int)$_SESSION['user_id'];

function tier_is_admin($conn, $userId) {
    $stmt = $conn->prepare("SELECT is_admin FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row && (int)$row['is_admin'] === 1;
}

avapay_ensure_tier_tables($conn);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ==================== سطح خودِ کاربر ====================
if ($action === 'get_my_tier') {
    $info = avapay_get_user_tier($conn, $userId);
    echo json_encode(['success' => true] + $info);
    exit();
}

// ==================== لیست همه‌ی سطوح (برای نمایش عمومی/راهنما) ====================
if ($action === 'list_public') {
    $res = $conn->query("SELECT id, name, min_volume_toman, discount_percent, badge_icon, badge_color FROM tier_levels ORDER BY min_volume_toman ASC");
    echo json_encode(['success' => true, 'tiers' => $res ? $res->fetch_all(MYSQLI_ASSOC) : [], 'volume_currency' => avapay_get_tier_currency($conn)]);
    exit();
}

// ---- از این‌جا به بعد فقط ادمین ----
if (!tier_is_admin($conn, $userId)) {
    echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']);
    exit();
}

if ($action === 'admin_list') {
    $res = $conn->query("SELECT * FROM tier_levels ORDER BY min_volume_toman ASC");
    echo json_encode(['success' => true, 'tiers' => $res ? $res->fetch_all(MYSQLI_ASSOC) : [], 'volume_currency' => avapay_get_tier_currency($conn)]);
    exit();
}

// ==================== ارزِ مبنای سطح‌بندی (تومان/دلار/یورو/تتر) ====================
if ($action === 'admin_get_currency') {
    echo json_encode(['success' => true, 'volume_currency' => avapay_get_tier_currency($conn)]);
    exit();
}

if ($action === 'admin_set_currency') {
    $currency = trim($_POST['volume_currency'] ?? '');
    $ok = avapay_set_tier_currency($conn, $currency);
    echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'ذخیره شد' : 'ارز نامعتبر']);
    exit();
}

if ($action === 'admin_save') {
    $id       = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $name     = trim($_POST['name'] ?? '');
    $minVol   = isset($_POST['min_volume_toman']) ? (float)$_POST['min_volume_toman'] : 0;
    $discount = isset($_POST['discount_percent']) ? (float)$_POST['discount_percent'] : 0;
    $icon     = trim($_POST['badge_icon'] ?? 'fa-medal');
    $color    = trim($_POST['badge_color'] ?? '#B8860B');

    if ($name === '') {
        echo json_encode(['success' => false, 'message' => 'نام سطح الزامی است']);
        exit();
    }
    if ($discount < 0 || $discount > 100) {
        echo json_encode(['success' => false, 'message' => 'درصد تخفیف باید بین ۰ تا ۱۰۰ باشد']);
        exit();
    }

    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE tier_levels SET name=?, min_volume_toman=?, discount_percent=?, badge_icon=?, badge_color=? WHERE id=?");
        $stmt->bind_param("sddssi", $name, $minVol, $discount, $icon, $color, $id);
    } else {
        $stmt = $conn->prepare("INSERT INTO tier_levels (name, min_volume_toman, discount_percent, badge_icon, badge_color, sort_order) VALUES (?,?,?,?,?, (SELECT m FROM (SELECT COALESCE(MAX(sort_order),0)+1 AS m FROM tier_levels) t))");
        $stmt->bind_param("sddss", $name, $minVol, $discount, $icon, $color);
    }
    $ok = $stmt->execute();
    echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'ذخیره شد' : $conn->error]);
    exit();
}

if ($action === 'admin_delete') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'شناسه نامعتبر']); exit(); }
    $stmt = $conn->prepare("DELETE FROM tier_levels WHERE id = ?");
    $stmt->bind_param("i", $id);
    $ok = $stmt->execute();
    echo json_encode(['success' => (bool)$ok]);
    exit();
}

echo json_encode(['success' => false, 'message' => 'اکشن نامعتبر']);
