<?php
/**
 * api/admin_stories_api.php
 * ------------------------------------------------------------------
 * استوری تبلیغاتی — دقیقاً مثل اینستاگرام: ادمین عکس/ویدیو آپلود
 * می‌کند، همه‌ی کاربران یک گوی شناور با حلقه‌ی قرمز می‌بینند (فقط وقتی
 * استوری فعالی هست)، با کلیک تا ۶۰ ثانیه نمایش داده می‌شود یا با تپ
 * می‌توان ردش کرد، و بعد از ۲۴ ساعت خودش پاک می‌شود.
 * ------------------------------------------------------------------
 */

header('Content-Type: application/json');

require_once '../config/database.php';
require_once __DIR__ . '/../includes/upload_paths.php';
require_once __DIR__ . '/../includes/perf_helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'لطفاً وارد شوید']);
    exit();
}
$userId = (int)$_SESSION['user_id'];

function stories_is_admin($conn, $userId) {
    $stmt = $conn->prepare("SELECT is_admin FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row && (int)$row['is_admin'] === 1;
}

$conn->query("CREATE TABLE IF NOT EXISTS `admin_stories` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `media_path` VARCHAR(255) NOT NULL,
    `media_type` ENUM('image','video') NOT NULL DEFAULT 'image',
    `caption` VARCHAR(255) DEFAULT NULL,
    `link_url` VARCHAR(255) DEFAULT NULL,
    `created_by` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `expires_at` DATETIME NOT NULL,
    INDEX idx_expires (expires_at)
)");
$conn->query("CREATE TABLE IF NOT EXISTS `admin_story_views` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `story_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `viewed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_view` (`story_id`, `user_id`)
)");

// پاک‌سازی استوری‌های منقضی — حداکثر هر یک ساعت یک‌بار (نه هر درخواست)
if (avapay_throttled('admin_stories_cleanup', 3600)) {
    try {
        $expired = $conn->query("SELECT id, media_path FROM admin_stories WHERE expires_at <= NOW()");
        if ($expired) {
            while ($row = $expired->fetch_assoc()) {
                // فایل فیزیکی را هم پاک کن — media_path به‌صورت 'uploads/stories/xxx' ذخیره شده
                $rel = preg_replace('#^uploads/#', '', $row['media_path']);
                $full = avapay_upload_dir('stories') . basename($rel);
                if (is_file($full)) @unlink($full);
            }
        }
        $conn->query("DELETE FROM admin_story_views WHERE story_id IN (SELECT id FROM admin_stories WHERE expires_at <= NOW())");
        $conn->query("DELETE FROM admin_stories WHERE expires_at <= NOW()");
    } catch (\Throwable $e) { error_log('admin_stories cleanup error: ' . $e->getMessage()); }
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/* ==================== کاربر: لیست استوری‌های فعال (با وضعیت دیده‌شده) ==================== */
if ($action === 'active') {
    $res = $conn->query("SELECT s.id, s.media_path, s.media_type, s.caption, s.link_url, s.created_at,
                                 (v.id IS NOT NULL) AS viewed
                          FROM admin_stories s
                          LEFT JOIN admin_story_views v ON v.story_id = s.id AND v.user_id = {$userId}
                          WHERE s.expires_at > NOW()
                          ORDER BY s.created_at ASC");
    $items = [];
    $hasUnseen = false;
    while ($res && $row = $res->fetch_assoc()) {
        $row['viewed'] = (bool)$row['viewed'];
        if (!$row['viewed']) $hasUnseen = true;
        $items[] = $row;
    }
    echo json_encode(['success' => true, 'items' => $items, 'has_unseen' => $hasUnseen]);
    exit();
}

/* ==================== کاربر: ثبت مشاهده ==================== */
if ($action === 'mark_viewed' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $storyId = (int)($input['story_id'] ?? 0);
    if ($storyId > 0) {
        $stmt = $conn->prepare("INSERT IGNORE INTO admin_story_views (story_id, user_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $storyId, $userId);
        $stmt->execute();
    }
    echo json_encode(['success' => true]);
    exit();
}

// ---- از این‌جا به بعد فقط ادمین ----
if (!stories_is_admin($conn, $userId)) {
    echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']);
    exit();
}

/* ==================== ادمین: لیست استوری‌ها + تعداد بازدید ==================== */
if ($action === 'admin_list') {
    $res = $conn->query("SELECT s.*, (SELECT COUNT(*) FROM admin_story_views v WHERE v.story_id = s.id) AS view_count
                          FROM admin_stories s
                          ORDER BY s.created_at DESC LIMIT 30");
    $items = [];
    while ($res && $row = $res->fetch_assoc()) $items[] = $row;
    echo json_encode(['success' => true, 'items' => $items]);
    exit();
}

/* ==================== ادمین: آپلود استوری جدید ==================== */
if ($action === 'admin_upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_FILES['media']['name']) || $_FILES['media']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'فایلی ارسال نشد']);
        exit();
    }
    $ext = strtolower(pathinfo($_FILES['media']['name'], PATHINFO_EXTENSION));
    $imageExts = ['jpg', 'jpeg', 'png', 'webp'];
    $videoExts = ['mp4', 'mov', 'webm'];
    if (in_array($ext, $imageExts, true)) {
        $mediaType = 'image';
        $maxSize = 10 * 1024 * 1024; // ۱۰ مگابایت
    } elseif (in_array($ext, $videoExts, true)) {
        $mediaType = 'video';
        $maxSize = 40 * 1024 * 1024; // ۴۰ مگابایت
    } else {
        echo json_encode(['success' => false, 'message' => 'فرمت مجاز نیست (jpg/png/webp یا mp4/mov/webm)']);
        exit();
    }
    if ($_FILES['media']['size'] > $maxSize) {
        echo json_encode(['success' => false, 'message' => 'حجم فایل بیش از حد مجاز است']);
        exit();
    }

    $uploadDir = avapay_upload_dir('stories');
    $fname = 'story_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['media']['tmp_name'], $uploadDir . $fname)) {
        echo json_encode(['success' => false, 'message' => 'خطا در ذخیره فایل']);
        exit();
    }
    $path = 'uploads/stories/' . $fname;

    $caption = trim($_POST['caption'] ?? '');
    $linkUrl = trim($_POST['link_url'] ?? '');

    $stmt = $conn->prepare("INSERT INTO admin_stories (media_path, media_type, caption, link_url, created_by, expires_at)
                             VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))");
    $stmt->bind_param("ssssi", $path, $mediaType, $caption, $linkUrl, $userId);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'استوری منتشر شد — تا ۲۴ ساعت دیگر فعال می‌ماند', 'story_id' => $stmt->insert_id]);
    } else {
        echo json_encode(['success' => false, 'message' => 'خطا در ثبت: ' . $conn->error]);
    }
    exit();
}

/* ==================== ادمین: حذف زودهنگام یک استوری ==================== */
if ($action === 'admin_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $storyId = (int)($input['story_id'] ?? 0);
    if ($storyId <= 0) { echo json_encode(['success' => false, 'message' => 'شناسه نامعتبر']); exit(); }

    $stmt = $conn->prepare("SELECT media_path FROM admin_stories WHERE id = ?");
    $stmt->bind_param("i", $storyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) {
        $rel = preg_replace('#^uploads/#', '', $row['media_path']);
        $full = avapay_upload_dir('stories') . basename($rel);
        if (is_file($full)) @unlink($full);
    }
    $conn->query("DELETE FROM admin_story_views WHERE story_id = {$storyId}");
    $del = $conn->prepare("DELETE FROM admin_stories WHERE id = ?");
    $del->bind_param("i", $storyId);
    $del->execute();
    echo json_encode(['success' => true, 'message' => 'استوری حذف شد']);
    exit();
}

echo json_encode(['success' => false, 'message' => 'اکشن نامعتبر است']);
