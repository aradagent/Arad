<?php
// api/save_push_subscription.php
// نسخه اصلاح‌شده: پشتیبانی از چند دستگاه (اندروید + دسکتاپ + PWA)
// و جلوگیری از overwrite شدن subscription دستگاه‌های دیگر.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';

$response = ['success' => false, 'message' => ''];

if (!isset($_SESSION['user_id'])) {
    $response['message'] = 'Not authenticated';
    echo json_encode($response);
    exit();
}

$userId = $_SESSION['user_id'];
$input  = file_get_contents('php://input');
$data   = json_decode($input, true);

if (!$data) {
    $response['message'] = 'Invalid input';
    echo json_encode($response);
    exit();
}

$endpoint = $data['endpoint'] ?? '';
$p256dh   = $data['p256dh'] ?? '';
$auth     = $data['auth'] ?? '';

// نرمال‌سازی کلیدها به base64url (URL-safe، بدون padding).
// کتابخانه‌ی WebPush کلیدها را با Base64Url decode می‌کند؛ اگر کلاینت
// نسخه‌ی قدیمی با base64 معمولی (+ / =) بفرستد اینجا اصلاح می‌شود تا
// رمزنگاری push سالم بماند و نوتیفیکیشن در حالت بسته بودن اپ هم برسد.
if (!function_exists('_avapay_to_base64url')) {
function _avapay_to_base64url($s) {
    $s = trim((string)$s);
    if ($s === '') return $s;
    $s = strtr($s, '+/', '-_');
    $s = rtrim($s, '=');
    return $s;
}
}
$p256dh = _avapay_to_base64url($p256dh);
$auth   = _avapay_to_base64url($auth);

if (empty($endpoint) || empty($p256dh) || empty($auth)) {
    $response['message'] = 'Missing subscription fields';
    echo json_encode($response);
    exit();
}

/*
 * جدول اکنون به‌جای UNIQUE(user_id) از UNIQUE(endpoint) استفاده می‌کند.
 * هر دستگاه یک endpoint منحصربه‌فرد دارد، پس یک کاربر می‌تواند چند
 * دستگاه (اندروید، PWA، دسکتاپ) را هم‌زمان ثبت کند و push به همه برسد.
 */
$conn->query("CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    endpoint TEXT NOT NULL,
    p256dh TEXT NOT NULL,
    auth TEXT NOT NULL,
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user (user_id)
)");

/*
 * مهاجرت خودکار از ساختار قدیمی:
 * اگر ایندکس unique قدیمی unique_user وجود دارد آن را حذف می‌کنیم
 * تا دستگاه‌های متعدد یک کاربر بازنویسی نشوند.
 */
$idxCheck = $conn->query("SHOW INDEX FROM push_subscriptions WHERE Key_name = 'unique_user'");
if ($idxCheck && $idxCheck->num_rows > 0) {
    @$conn->query("ALTER TABLE push_subscriptions DROP INDEX unique_user");
}
// اطمینان از وجود UNIQUE روی endpoint (با طول محدود چون TEXT است)
$epIdx = $conn->query("SHOW INDEX FROM push_subscriptions WHERE Key_name = 'unique_endpoint'");
if (!$epIdx || $epIdx->num_rows === 0) {
    @$conn->query("ALTER TABLE push_subscriptions ADD UNIQUE KEY unique_endpoint (endpoint(191))");
}

$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

$stmt = $conn->prepare("INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, user_agent)
                        VALUES (?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                        user_id   = VALUES(user_id),
                        p256dh    = VALUES(p256dh),
                        auth      = VALUES(auth),
                        user_agent= VALUES(user_agent),
                        updated_at= NOW()");
$stmt->bind_param("issss", $userId, $endpoint, $p256dh, $auth, $userAgent);

if ($stmt->execute()) {
    $response = ['success' => true, 'message' => 'Subscription saved'];
} else {
    $response['message'] = 'Database error: ' . $stmt->error;
}

$stmt->close();
echo json_encode($response);
