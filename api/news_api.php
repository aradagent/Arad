<?php
// api/news_api.php
// مدیریت اخبار دو زبانه (فارسی/انگلیسی) با پشتیبانی از عکس
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// جلوگیری از شکستن JSON توسط خطاهای PHP
error_reporting(E_ALL);
ini_set('display_errors', '0');
ob_start();
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        while (ob_get_level()) ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $err['message']]);
    }
});

require_once '../config/database.php';
require_once __DIR__ . '/../includes/upload_paths.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// پاک‌کردن هر خروجی ناخواسته (warning/notice) قبل از JSON
while (ob_get_level() > 1) ob_end_clean();
if (ob_get_length()) ob_clean();

// اطمینان از وجود جدول اخبار (بدون کامنت داخل SQL)
$conn->query("CREATE TABLE IF NOT EXISTS `news` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `lang` VARCHAR(5) NOT NULL DEFAULT 'fa',
    `title` VARCHAR(255) NOT NULL,
    `body` MEDIUMTEXT NOT NULL,
    `image` VARCHAR(255) DEFAULT NULL,
    `is_published` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_lang (lang),
    INDEX idx_pub (is_published)
)");

$userId = $_SESSION['user_id'] ?? 0;
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

function isAdminUser($conn, $userId) {
    if (!$userId) return false;
    $r = $conn->query("SELECT is_admin FROM users WHERE id = " . intval($userId));
    $row = $r ? $r->fetch_assoc() : null;
    return $row && (int)$row['is_admin'] === 1;
}

/* ---------- عمومی: دریافت اخبار بر اساس زبان ---------- */
if ($action === 'get_news') {
    $lang = ($_GET['lang'] ?? 'fa') === 'en' ? 'en' : 'fa';
    $stmt = $conn->prepare("SELECT id, lang, title, body, image, created_at
                            FROM news WHERE lang = ? AND is_published = 1
                            ORDER BY created_at DESC LIMIT 50");
    $stmt->bind_param("s", $lang);
    $stmt->execute();
    $res = $stmt->get_result();
    $news = [];
    while ($row = $res->fetch_assoc()) {
        $news[] = [
            'id'         => (int)$row['id'],
            'lang'       => $row['lang'],
            'title'      => $row['title'],
            'body'       => $row['body'],
            'image'      => $row['image'] ? '/ledor/' . ltrim($row['image'], '/') : null,
            'created_at' => $row['created_at'],
        ];
    }
    echo json_encode(['success' => true, 'news' => $news]);
    exit();
}

/* ---------- عمومی: یک خبر کامل ---------- */
if ($action === 'get_one') {
    $id = intval($_GET['id'] ?? 0);
    $stmt = $conn->prepare("SELECT id, lang, title, body, image, created_at FROM news WHERE id = ? AND is_published = 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) { echo json_encode(['success' => false, 'message' => 'یافت نشد']); exit(); }
    $row['image'] = $row['image'] ? '/ledor/' . ltrim($row['image'], '/') : null;
    echo json_encode(['success' => true, 'news' => $row]);
    exit();
}

/* ================= از اینجا به بعد فقط ادمین ================= */
if (!isAdminUser($conn, $userId)) {
    echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']);
    exit();
}

/* ---------- ادمین: لیست همه اخبار (شامل منتشرنشده) ---------- */
if ($action === 'admin_list') {
    $lang = $_GET['lang'] ?? '';
    $sql = "SELECT id, lang, title, image, is_published, created_at FROM news";
    if ($lang === 'fa' || $lang === 'en') $sql .= " WHERE lang = '" . $conn->real_escape_string($lang) . "'";
    $sql .= " ORDER BY created_at DESC LIMIT 200";
    $res = $conn->query($sql);
    $news = [];
    while ($row = $res->fetch_assoc()) {
        $row['image'] = $row['image'] ? '/ledor/' . ltrim($row['image'], '/') : null;
        $news[] = $row;
    }
    echo json_encode(['success' => true, 'news' => $news]);
    exit();
}

/* ---------- ادمین: ساخت/ویرایش خبر ---------- */
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id    = intval($_POST['id'] ?? 0);
    $lang  = ($_POST['lang'] ?? 'fa') === 'en' ? 'en' : 'fa';
    $title = trim($_POST['title'] ?? '');
    $body  = trim($_POST['body'] ?? '');
    $pub   = isset($_POST['is_published']) ? (int)$_POST['is_published'] : 1;

    if ($title === '' || $body === '') {
        echo json_encode(['success' => false, 'message' => 'عنوان و متن الزامی است']);
        exit();
    }

    // آپلود عکس (اختیاری)
    $imagePath = null;
    if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = avapay_upload_dir('news');
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif','webp'];
        if (in_array($ext, $allowed)) {
            $fname = 'news_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $fname)) {
                $imagePath = 'uploads/news/' . $fname;
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'فرمت عکس مجاز نیست']);
            exit();
        }
    }

    if ($id > 0) {
        // ویرایش
        if ($imagePath !== null) {
            $stmt = $conn->prepare("UPDATE news SET lang=?, title=?, body=?, image=?, is_published=? WHERE id=?");
            $stmt->bind_param("ssssii", $lang, $title, $body, $imagePath, $pub, $id);
        } else {
            $stmt = $conn->prepare("UPDATE news SET lang=?, title=?, body=?, is_published=? WHERE id=?");
            $stmt->bind_param("sssii", $lang, $title, $body, $pub, $id);
        }
        $stmt->execute();
        echo json_encode(['success' => true, 'id' => $id, 'message' => 'خبر بروزرسانی شد']);
    } else {
        // جدید
        $stmt = $conn->prepare("INSERT INTO news (lang, title, body, image, is_published) VALUES (?,?,?,?,?)");
        $stmt->bind_param("ssssi", $lang, $title, $body, $imagePath, $pub);
        $stmt->execute();
        echo json_encode(['success' => true, 'id' => $conn->insert_id, 'message' => 'خبر منتشر شد']);
    }
    exit();
}

/* ---------- ادمین: حذف خبر ---------- */
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = intval($data['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'شناسه نامعتبر']); exit(); }
    // حذف عکس فایل
    $r = $conn->query("SELECT image FROM news WHERE id = $id");
    $row = $r ? $r->fetch_assoc() : null;
    if ($row && !empty($row['image'])) { @unlink('../' . $row['image']); }
    $conn->query("DELETE FROM news WHERE id = $id");
    echo json_encode(['success' => true]);
    exit();
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
