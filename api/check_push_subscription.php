<?php
// api/check_push_subscription.php
// بررسی اینکه آیا کاربر جاری حداقل یک push subscription فعال دارد.
// این فایل قبلاً وجود نداشت و باعث می‌شد منطق ثبت مجدد اشتراک در
// push-init.js (مخصوصاً روی اندروید) با خطا مواجه شود.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'has_subscription' => false, 'message' => 'Not authenticated']);
    exit();
}

$userId = $_SESSION['user_id'];

// اطمینان از وجود جدول (بدون خطا اگر قبلاً ساخته شده)
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

$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM push_subscriptions WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);

echo json_encode([
    'success'          => true,
    'has_subscription' => $count > 0,
    'device_count'     => $count
]);
