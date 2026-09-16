<?php
// api/notification_api.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'لطفاً وارد شوید']);
    exit();
}

$userId = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';

// ایجاد جدول
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

// ==================== دریافت 5 نوتیفیکیشن آخر ====================
if ($action === 'get') {
    // فقط 5 تا آخرین نوتیفیکیشن رو نمایش بده
    $limit = 5;
    
    $stmt = $conn->prepare("SELECT * FROM user_notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
    $stmt->bind_param("ii", $userId, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $notifications = [];
    while ($row = $result->fetch_assoc()) {
        // محاسبه زمان نسبی
        $row['time_ago'] = timeAgo($row['created_at']);
        $notifications[] = $row;
    }
    
    // تعداد کل نخوانده‌ها (برای نمایش روی زنگوله)
    $unreadStmt = $conn->prepare("SELECT COUNT(*) as unread FROM user_notifications WHERE user_id = ? AND is_read = 0");
    $unreadStmt->bind_param("i", $userId);
    $unreadStmt->execute();
    $unreadCount = $unreadStmt->get_result()->fetch_assoc()['unread'];
    
    // تعداد کل نوتیفیکیشن‌ها (برای نمایش "مشاهده همه")
    $totalStmt = $conn->prepare("SELECT COUNT(*) as total FROM user_notifications WHERE user_id = ?");
    $totalStmt->bind_param("i", $userId);
    $totalStmt->execute();
    $totalCount = $totalStmt->get_result()->fetch_assoc()['total'];
    
    echo json_encode([
        'success' => true, 
        'notifications' => $notifications, 
        'unread_count' => $unreadCount,
        'total_count' => $totalCount,
        'has_more' => $totalCount > 5
    ]);
    exit();
}

// ==================== دریافت نوتیفیکیشن‌های بیشتر (برای صفحه مشاهده همه) ====================
if ($action === 'get_all' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $limit = 20;
    $offset = ($page - 1) * $limit;
    
    $stmt = $conn->prepare("SELECT * FROM user_notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $stmt->bind_param("iii", $userId, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $notifications = [];
    while ($row = $result->fetch_assoc()) {
        $row['time_ago'] = timeAgo($row['created_at']);
        $notifications[] = $row;
    }
    
    // تعداد کل
    $totalStmt = $conn->prepare("SELECT COUNT(*) as total FROM user_notifications WHERE user_id = ?");
    $totalStmt->bind_param("i", $userId);
    $totalStmt->execute();
    $totalCount = $totalStmt->get_result()->fetch_assoc()['total'];
    
    echo json_encode([
        'success' => true,
        'notifications' => $notifications,
        'total_count' => $totalCount,
        'current_page' => $page,
        'has_more' => ($offset + $limit) < $totalCount
    ]);
    exit();
}

// ==================== علامت زدن به عنوان خوانده شده ====================
// اضافه کردن به notification_api.php
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $notificationId = intval($input['notification_id'] ?? 0);
    
    if ($notificationId > 0) {
        $stmt = $conn->prepare("DELETE FROM user_notifications WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $notificationId, $userId);
        $stmt->execute();
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'شناسه نامعتبر']);
    }
    exit();
}


if ($action === 'mark_read') {
    $input = json_decode(file_get_contents('php://input'), true);
    $notificationId = intval($input['notification_id'] ?? 0);
    
    if ($notificationId > 0) {
        $stmt = $conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $notificationId, $userId);
        $stmt->execute();
    }
    
    echo json_encode(['success' => true]);
    exit();
}

// ==================== علامت زدن همه به عنوان خوانده شده ====================
if ($action === 'mark_all_read') {
    $stmt = $conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    
    echo json_encode(['success' => true]);
    exit();
}

// ==================== دریافت تصویر فیش برای نمایش فوری با کلیک روی نوتیفیکیشن ====================
if ($action === 'get_receipt_preview' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $type      = $_GET['type'] ?? '';
    $relatedId = (int)($_GET['related_id'] ?? 0);

    if (!$relatedId || !in_array($type, ['topup_receipt', 'invoice_paid'], true)) {
        echo json_encode(['success' => false, 'message' => 'درخواست نامعتبر']);
        exit();
    }

    // اطمینان از اینکه این نوتیفیکیشن واقعاً متعلق به همین کاربر است
    // (جلوگیری از حدس‌زدن related_id برای دیدن فیش دیگران)
    $ownStmt = $conn->prepare("SELECT id FROM user_notifications WHERE user_id = ? AND type = ? AND related_id = ? LIMIT 1");
    $ownStmt->bind_param("isi", $userId, $type, $relatedId);
    $ownStmt->execute();
    if ($ownStmt->get_result()->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']);
        exit();
    }

    $images = [];
    $raw = null;
    if ($type === 'topup_receipt') {
        $s = $conn->prepare("SELECT receipt_image FROM topup_requests WHERE id = ?");
        $s->bind_param("i", $relatedId);
        $s->execute();
        $row = $s->get_result()->fetch_assoc();
        $raw = $row['receipt_image'] ?? null;
        if ($raw) {
            $decoded = json_decode($raw, true);
            $images = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [$raw];
        }
    } elseif ($type === 'invoice_paid') {
        $s = $conn->prepare("SELECT receipt_image FROM unpaid_invoices WHERE id = ?");
        $s->bind_param("i", $relatedId);
        $s->execute();
        $row = $s->get_result()->fetch_assoc();
        $raw = $row['receipt_image'] ?? null;
        if ($raw) {
            $images = array_map('trim', explode(',', $raw));
        }
    }
    $images = array_values(array_filter($images));

    echo json_encode(['success' => !empty($images), 'images' => $images]);
    exit();
}

// ==================== دریافت تعداد نخوانده‌ها و آخرین نوتیفیکیشن ====================
if ($action === 'unread_count') {
    $stmt = $conn->prepare("SELECT COUNT(*) as unread FROM user_notifications WHERE user_id = ? AND is_read = 0");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $unreadCount = $stmt->get_result()->fetch_assoc()['unread'];
    
    // آخرین نوتیفیکیشن
    $lastStmt = $conn->prepare("SELECT * FROM user_notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
    $lastStmt->bind_param("i", $userId);
    $lastStmt->execute();
    $lastNotification = $lastStmt->get_result()->fetch_assoc();
    
    if ($lastNotification) {
        $lastNotification['time_ago'] = timeAgo($lastNotification['created_at']);
    }
    
    echo json_encode([
        'success' => true, 
        'unread_count' => $unreadCount, 
        'last_notification' => $lastNotification
    ]);
    exit();
}

// ==================== ذخیره تنظیمات نوتیفیکیشن (ایمیل / Toast) ====================
if ($action === 'save_prefs' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data    = json_decode(file_get_contents('php://input'), true);
    $field   = $data['field'] ?? '';
    $enabled = (int)($data['enabled'] ?? 1) === 1 ? 1 : 0;

    // Only allow known preference columns
    $allowed = ['notify_email', 'notify_toast', 'notify_telegram'];
    if (!in_array($field, $allowed, true)) {
        echo json_encode(['success' => false, 'message' => 'فیلد نامعتبر']);
        exit();
    }

    // Auto-add the column if it doesn't exist yet
    $col = $conn->query("SHOW COLUMNS FROM users LIKE '$field'");
    if (!$col || $col->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN `$field` TINYINT(1) DEFAULT 1");
    }

    $stmt = $conn->prepare("UPDATE users SET `$field` = ? WHERE id = ?");
    $stmt->bind_param("ii", $enabled, $userId);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'field' => $field, 'enabled' => $enabled]);
    } else {
        echo json_encode(['success' => false, 'message' => 'خطا در ذخیره‌سازی']);
    }
    exit();
}

// ==================== پاک‌کردن همه اعلان‌های کاربر ====================
if ($action === 'clear_all' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $conn->prepare("DELETE FROM user_notifications WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    echo json_encode(['success' => $stmt->execute()]);
    exit();
}

// ==================== ذخیره تم (روشن/تاریک) در سرور ====================
if ($action === 'save_theme' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data  = json_decode(file_get_contents('php://input'), true);
    $theme = ($data['theme'] ?? 'dark') === 'light' ? 'light' : 'dark';
    $col = $conn->query("SHOW COLUMNS FROM users LIKE 'theme'");
    if (!$col || $col->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN `theme` VARCHAR(10) DEFAULT 'dark'");
    }
    $stmt = $conn->prepare("UPDATE users SET theme = ? WHERE id = ?");
    $stmt->bind_param("si", $theme, $userId);
    echo json_encode(['success' => $stmt->execute(), 'theme' => $theme]);
    exit();
}

// ==================== دریافت تنظیمات نوتیفیکیشن ====================
if ($action === 'get_prefs' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    foreach (['notify_email', 'notify_toast', 'notify_telegram'] as $f) {
        $col = $conn->query("SHOW COLUMNS FROM users LIKE '$f'");
        if (!$col || $col->num_rows === 0) {
            $conn->query("ALTER TABLE users ADD COLUMN `$f` TINYINT(1) DEFAULT 1");
        }
    }
    $stmt = $conn->prepare("SELECT notify_email, notify_toast, notify_telegram FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    echo json_encode([
        'success'          => true,
        'notify_email'     => (int)($row['notify_email'] ?? 1),
        'notify_toast'     => (int)($row['notify_toast'] ?? 1),
        'notify_telegram'  => (int)($row['notify_telegram'] ?? 1)
    ]);
    exit();
}

// ==================== تابع کمکی برای زمان نسبی ====================
function timeAgo($timestamp) {
    $time = strtotime($timestamp);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return 'لحظاتی پیش';
    } elseif ($diff < 3600) {
        $minutes = floor($diff / 60);
        return $minutes . ' دقیقه پیش';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' ساعت پیش';
    } elseif ($diff < 2592000) {
        $days = floor($diff / 86400);
        return $days . ' روز پیش';
    } elseif ($diff < 31536000) {
        $months = floor($diff / 2592000);
        return $months . ' ماه پیش';
    } else {
        $years = floor($diff / 31536000);
        return $years . ' سال پیش';
    }
}

echo json_encode(['success' => false, 'message' => 'اکشن نامعتبر']);
?>