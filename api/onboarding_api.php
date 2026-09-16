<?php
// api/onboarding_api.php
// ----------------------------------------------------------------------------
// وضعیت «آیا این کاربر آنبوردینگ را دیده یا نه» را به‌جای localStorage (که با
// عوض کردن مرورگر/دستگاه یا پاک‌کردن کش دوباره صفر می‌شد)، روی حساب کاربری
// خودش در دیتابیس نگه می‌دارد — یعنی واقعاً فقط یک‌بار برای هر کاربر نمایش
// داده می‌شود، مستقل از دستگاه.
//
// اکشن‌ها:
//   ?action=check      -> { success:true, seen:true|false }
//   ?action=mark_seen  -> { success:true }
// ----------------------------------------------------------------------------

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../includes/session_boot.php';
require_once __DIR__ . '/../config/database.php';

// اگر سشن خالی بود، از روی کوکی auth_token ماندگار بازسازی کن
avapay_restore_session_from_token($conn);

$userId = (int)($_SESSION['user_id'] ?? 0);
$action = $_GET['action'] ?? '';

try {
    $conn->query("CREATE TABLE IF NOT EXISTS `user_onboarding_seen` (
        `user_id` INT PRIMARY KEY,
        `seen_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (\Throwable $e) {
    // اگر همین ساختن جدول شکست بخورد، پایین‌تر با try/catcهای خودشان بی‌خطر رد می‌شوند
}

if ($action === 'check') {
    // کاربر مهمان/بدون سشن معتبر: امن‌ترین پیش‌فرض این است که آنبوردینگ را
    // «دیده نشده» در نظر بگیریم (در بدترین حالت یک‌بار بیشتر دیده می‌شود، نه
    // این‌که برای یک کاربر واقعی هیچ‌وقت نمایش داده نشود)
    if ($userId <= 0) { echo json_encode(['success' => true, 'seen' => false]); exit; }
    try {
        $st = $conn->prepare("SELECT 1 FROM user_onboarding_seen WHERE user_id = ? LIMIT 1");
        $st->bind_param("i", $userId);
        $st->execute();
        $seen = $st->get_result()->num_rows > 0;
        echo json_encode(['success' => true, 'seen' => $seen]); exit;
    } catch (\Throwable $e) {
        echo json_encode(['success' => true, 'seen' => false]); exit;
    }
}

if ($action === 'mark_seen') {
    if ($userId > 0) {
        try {
            $st = $conn->prepare("INSERT IGNORE INTO user_onboarding_seen (user_id) VALUES (?)");
            $st->bind_param("i", $userId);
            $st->execute();
        } catch (\Throwable $e) {}
    }
    echo json_encode(['success' => true]); exit;
}

echo json_encode(['success' => false, 'message' => 'اکشن نامعتبر']);
