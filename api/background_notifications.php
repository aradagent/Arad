<?php
// api/background_notifications.php
// ------------------------------------------------------------------
// Endpoint سبک برای Periodic Background Sync سرویس‌ورکر.
//
// هدف: وقتی اپ بسته/در بک‌گراند است، سرویس‌ورکر هر چند دقیقه یک‌بار
// این فایل را صدا می‌زند و نوتیفیکیشن‌های «هنوز به‌صورت توست نمایش داده
// نشده» را می‌گیرد تا آن‌ها را به‌صورت toast روی لاک‌اسکرین/نوتیف‌سنتر
// نمایش دهد. برای اینکه هر توست فقط یک‌بار نشان داده شود، یک ستون
// bg_pushed روی جدول user_notifications نگه می‌داریم.
//
// action=pending  -> لیست نوتیف‌های نمایش‌داده‌نشده را برمی‌گرداند
//                    و آن‌ها را bg_pushed=1 علامت می‌زند (تحویل یک‌باره).
// action=peek     -> فقط تعداد/لیست را می‌دهد بدون علامت‌گذاری (اختیاری).
// ------------------------------------------------------------------

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    // سرویس‌ورکر با credentials درخواست می‌دهد؛ اگر session نبود یعنی
    // کاربر logout است و چیزی برای نمایش نداریم.
    echo json_encode(['success' => false, 'message' => 'not_authenticated', 'notifications' => []]);
    exit();
}

$userId = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? 'pending';

// اطمینان از وجود جدول (هم‌راستا با notification_api.php)
$conn->query("CREATE TABLE IF NOT EXISTS `user_notifications` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `type` VARCHAR(50) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `message` TEXT NOT NULL,
    `related_id` INT DEFAULT NULL,
    `is_read` TINYINT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_is_read (is_read)
)");

// افزودن ستون bg_pushed در صورت نبودن (مهاجرت خودکار و بی‌خطر)
$col = $conn->query("SHOW COLUMNS FROM `user_notifications` LIKE 'bg_pushed'");
if (!$col || $col->num_rows === 0) {
    @$conn->query("ALTER TABLE `user_notifications` ADD COLUMN `bg_pushed` TINYINT DEFAULT 0");
    // ردیف‌های قدیمی را دیده‌شده فرض کن تا موقع فعال‌سازی سیل توست نیاید.
    @$conn->query("UPDATE `user_notifications` SET `bg_pushed` = 1 WHERE `bg_pushed` = 0");
}

function ba_map_url($type, $relatedId) {
    switch ($type) {
        case 'offer_received': return '/ledor/arad.php?tab=offers&section=received';
        case 'offer_accepted': return '/ledor/arad.php?tab=offers&section=sent';
        case 'offer_rejected': return '/ledor/arad.php?tab=market';
        case 'transaction':    return '/ledor/transactions.php';
        default:               return '/ledor/dashboard.php';
    }
}

// حداکثر تعداد توست در هر بیدارشدن (جلوگیری از اسپم روی لاک‌اسکرین)
$MAX = 5;

// فقط نوتیف‌های نمایش‌داده‌نشده (bg_pushed=0). خوانده‌شدن یا نشدن مهم نیست؛
// هدف نمایش توست است، نه شمارش زنگوله.
$stmt = $conn->prepare(
    "SELECT id, type, title, message, related_id, created_at
     FROM user_notifications
     WHERE user_id = ? AND bg_pushed = 0
     ORDER BY created_at ASC
     LIMIT ?"
);
$stmt->bind_param("ii", $userId, $MAX);
$stmt->execute();
$res = $stmt->get_result();

$items = [];
$ids   = [];
while ($row = $res->fetch_assoc()) {
    $type = $row['type'] ?: 'general';
    $ids[] = (int)$row['id'];
    $items[] = [
        'id'         => (int)$row['id'],
        'type'       => $type,
        'title'      => $row['title'] ?: '📩 AvaPay',
        'body'       => $row['message'] ?: 'اعلان جدید',
        'related_id' => $row['related_id'] !== null ? (int)$row['related_id'] : null,
        'url'        => ba_map_url($type, $row['related_id']),
        'icon'       => '/ledor/AVAPAY.PNG',
        'badge'      => '/ledor/AVAPAY.PNG',
    ];
}
$stmt->close();

// در حالت pending، نوتیف‌های استخراج‌شده را دیده‌شده علامت بزن تا در
// بیدارشدن بعدی دوباره توست نشوند.
if ($action === 'pending' && !empty($ids)) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $upd = $conn->prepare("UPDATE user_notifications SET bg_pushed = 1 WHERE id IN ($in)");
    $upd->bind_param($types, ...$ids);
    $upd->execute();
    $upd->close();
}

echo json_encode([
    'success'       => true,
    'count'         => count($items),
    'notifications' => $items,
    'server_time'   => time(),
], JSON_UNESCAPED_UNICODE);
