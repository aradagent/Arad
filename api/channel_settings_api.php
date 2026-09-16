<?php
/**
 * api/channel_settings_api.php
 * مدیریت تنظیمات ارسال آگهی به کانال تلگرام (فقط ادمین)
 *   - get      : خواندن تنظیمات فعلی
 *   - save     : ذخیرهٔ channel_id و فعال/غیرفعال بودن
 *   - send_now : ارسال دستی فوری (بدون در نظر گرفتن بازهٔ یک‌ساعته)
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/session_boot.php';
require_once __DIR__ . '/../config/database.php';
avapay_restore_session_from_token($conn);

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'لطفاً وارد شوید'], JSON_UNESCAPED_UNICODE);
    exit();
}

$userId = intval($_SESSION['user_id']);

// بررسی ادمین
$ADMIN_TELEGRAM_ID = defined('ADMIN_TELEGRAM_ID') ? ADMIN_TELEGRAM_ID : '5330629504';
$isAdmin = false;
$r = $conn->query("SELECT telegram_id, is_admin FROM users WHERE id = " . $userId)->fetch_assoc();
if ($r) {
    if (($r['telegram_id'] ?? '') == $ADMIN_TELEGRAM_ID) $isAdmin = true;
    if ((int)($r['is_admin'] ?? 0) === 1) $isAdmin = true;
}
if (!$isAdmin) {
    echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز'], JSON_UNESCAPED_UNICODE);
    exit();
}

$conn->query("CREATE TABLE IF NOT EXISTS `app_settings` (
    `k` VARCHAR(64) PRIMARY KEY,
    `v` TEXT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function s_get($conn, $k, $d = null) {
    $stmt = $conn->prepare("SELECT v FROM app_settings WHERE k = ? LIMIT 1");
    $stmt->bind_param("s", $k); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $row ? $row['v'] : $d;
}
function s_set($conn, $k, $v) {
    $stmt = $conn->prepare("INSERT INTO app_settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)");
    $stmt->bind_param("ss", $k, $v); $stmt->execute(); $stmt->close();
}

$action = $_GET['action'] ?? '';

if ($action === 'get') {
    echo json_encode([
        'success'    => true,
        'channel_id' => s_get($conn, 'ads_channel_id', ''),
        'enabled'    => s_get($conn, 'ads_broadcast_enabled', '1') === '1',
        'last_ts'    => intval(s_get($conn, 'ads_last_broadcast_ts', '0')),
        'last_status'=> s_get($conn, 'ads_last_broadcast_status', ''),
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true);
    $channelId = trim($in['channel_id'] ?? '');
    $enabled   = !empty($in['enabled']) ? '1' : '0';
    s_set($conn, 'ads_channel_id', $channelId);
    s_set($conn, 'ads_broadcast_enabled', $enabled);
    echo json_encode(['success' => true, 'message' => 'تنظیمات ذخیره شد.'], JSON_UNESCAPED_UNICODE);
    exit();
}

if ($action === 'send_now' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // فراخوانی مستقیم اسکریپت ارسال با force
    $_GET['force'] = '1';
    define('AVAPAY_TRUSTED_BROADCAST', true); // بای‌پس بررسی کلید وب
    // چون خروجی آن اسکریپت خودش JSON چاپ می‌کند، آن را بافر می‌کنیم
    ob_start();
    include __DIR__ . '/broadcast_ads_to_channel.php';
    $raw = ob_get_clean();
    $decoded = json_decode($raw, true);
    echo json_encode(['success' => true, 'result' => $decoded ?: $raw], JSON_UNESCAPED_UNICODE);
    exit();
}

echo json_encode(['success' => false, 'message' => 'اکشن نامعتبر'], JSON_UNESCAPED_UNICODE);
