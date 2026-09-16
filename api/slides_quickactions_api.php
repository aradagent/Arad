<?php
// api/slides_quickactions_api.php - نسخه اصلاح شده با قابلیت حداقل موجودی
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once '../config/database.php';
require_once __DIR__ . '/../includes/notify_helper.php';
require_once __DIR__ . '/../includes/upload_paths.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Auto-create tables
$conn->query("CREATE TABLE IF NOT EXISTS `dashboard_slides` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT,
    `bg_color` VARCHAR(100) DEFAULT 'linear-gradient(135deg,#6C40C5,#FF4D8D)',
    `icon` VARCHAR(100) DEFAULT 'fas fa-star',
    `image_url` VARCHAR(500) DEFAULT '',
    `link` VARCHAR(500) DEFAULT '',
    `sort_order` INT DEFAULT 0,
    `is_active` TINYINT DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("ALTER TABLE `dashboard_slides` ADD COLUMN IF NOT EXISTS `image_url` VARCHAR(500) DEFAULT ''");

/* ===== (جدید) بنرهای جای‌گذاری‌شده در داشبورد =====
   slot          : کلید بخشی از داشبورد که بنر زیر آن نمایش داده می‌شود
   action_type   : link | quick_action | modal | none
   action_target : شناسه‌ی quick action یا کلید مودال داخلی
   style         : p1..p4 یا custom (گرادیان دلخواه در bg_color)
   layout        : banner (نواری، مثل بنرهای داخلی) | slide (اسلاید بزرگ قدیمی)
*/
$conn->query("ALTER TABLE `dashboard_slides` ADD COLUMN IF NOT EXISTS `slot` VARCHAR(60) DEFAULT 'top'");
$conn->query("ALTER TABLE `dashboard_slides` ADD COLUMN IF NOT EXISTS `action_type` VARCHAR(20) DEFAULT 'link'");
$conn->query("ALTER TABLE `dashboard_slides` ADD COLUMN IF NOT EXISTS `action_target` VARCHAR(120) DEFAULT ''");
$conn->query("ALTER TABLE `dashboard_slides` ADD COLUMN IF NOT EXISTS `style` VARCHAR(20) DEFAULT 'p1'");
$conn->query("ALTER TABLE `dashboard_slides` ADD COLUMN IF NOT EXISTS `layout` VARCHAR(20) DEFAULT 'banner'");

$conn->query("CREATE TABLE IF NOT EXISTS `quick_actions` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `label` VARCHAR(100) NOT NULL,
    `icon` VARCHAR(100) NOT NULL,
    `icon_image` VARCHAR(500) DEFAULT '',
    `color` VARCHAR(100) DEFAULT '#6C40C5',
    `sort_order` INT DEFAULT 0,
    `is_active` TINYINT DEFAULT 1,
    `min_balance` DECIMAL(15,2) DEFAULT 0,
    `min_balance_currency` ENUM('USD','EUR','USDT','IRR') DEFAULT 'USD',
    `deduct_balance` TINYINT DEFAULT 0,
    `deduct_amount` DECIMAL(15,2) DEFAULT 0,
    `deduct_currency` ENUM('USD','EUR','USDT','IRR') DEFAULT 'USD',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Migration: add new columns if missing
$conn->query("ALTER TABLE `quick_actions` ADD COLUMN IF NOT EXISTS `icon_image` VARCHAR(500) DEFAULT ''");
$conn->query("ALTER TABLE `quick_actions` ADD COLUMN IF NOT EXISTS `min_balance` DECIMAL(15,2) DEFAULT 0");
$conn->query("ALTER TABLE `quick_actions` ADD COLUMN IF NOT EXISTS `min_balance_currency` ENUM('USD','EUR','USDT','IRR') DEFAULT 'USD'");
$conn->query("ALTER TABLE `quick_actions` ADD COLUMN IF NOT EXISTS `deduct_balance` TINYINT DEFAULT 0");
$conn->query("ALTER TABLE `quick_actions` ADD COLUMN IF NOT EXISTS `deduct_amount` DECIMAL(15,2) DEFAULT 0");
$conn->query("ALTER TABLE `quick_actions` ADD COLUMN IF NOT EXISTS `deduct_currency` ENUM('USD','EUR','USDT','IRR') DEFAULT 'USD'");
// متن کوتاه تبلیغاتی که در بنر انیمیشنی خدمات محبوب (زیر نرخ‌های مورد علاقه) نمایش داده می‌شود
$conn->query("ALTER TABLE `quick_actions` ADD COLUMN IF NOT EXISTS `promo_text` VARCHAR(160) DEFAULT ''");

$conn->query("CREATE TABLE IF NOT EXISTS `quick_action_fields` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `action_id` INT NOT NULL,
    `field_label` VARCHAR(200) NOT NULL,
    `field_type` VARCHAR(50) DEFAULT 'text',
    `field_placeholder` VARCHAR(200) DEFAULT '',
    `field_options` TEXT DEFAULT NULL,
    `is_required` TINYINT DEFAULT 1,
    `sort_order` INT DEFAULT 0,
    FOREIGN KEY (action_id) REFERENCES quick_actions(id) ON DELETE CASCADE
)");
// مهاجرت: افزودن ستون گزینه‌های دراپ‌داون اگر جدول قدیمی است
$optCol = $conn->query("SHOW COLUMNS FROM quick_action_fields LIKE 'field_options'");
if (!$optCol || $optCol->num_rows === 0) {
    @$conn->query("ALTER TABLE quick_action_fields ADD COLUMN field_options TEXT DEFAULT NULL");
}

$conn->query("CREATE TABLE IF NOT EXISTS `quick_action_submissions` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `action_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `form_data` JSON NOT NULL,
    `status` ENUM('pending','approved','rejected') DEFAULT 'pending',
    `is_read` TINYINT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_action (action_id)
)");

// اصلاح سرعت: ALTER/DELETE زیر قبلاً روی هر درخواست اجرا می‌شدند؛ حالا throttle شده‌اند
require_once __DIR__ . '/../includes/perf_helpers.php';
if (avapay_throttled('quick_action_migrate_cleanup', 1800)) {
    // Migration: add status column if missing
    $conn->query("ALTER TABLE `quick_action_submissions` ADD COLUMN IF NOT EXISTS `status` ENUM('pending','approved','rejected') DEFAULT 'pending'");

    $idxChk = $conn->query("SHOW INDEX FROM quick_action_submissions WHERE Key_name = 'idx_created_at'");
    if ($idxChk && $idxChk->num_rows === 0) {
        try { $conn->query("ALTER TABLE quick_action_submissions ADD INDEX idx_created_at (created_at)"); } catch (\Throwable $e) {}
    }

    // Auto-delete submissions older than 1 month
    $conn->query("DELETE FROM quick_action_submissions WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 MONTH)");
}

$action = $_GET['action'] ?? '';

// ========== PUBLIC: Get slides ==========
if ($action === 'get_slides') {
    $result = $conn->query("SELECT * FROM dashboard_slides WHERE is_active=1 ORDER BY sort_order ASC, id ASC");
    $slides = [];
    while ($row = $result->fetch_assoc()) {
        // مقادیر پیش‌فرض برای رکوردهای قدیمی که ستون‌های جدید ندارند
        $row['slot']          = $row['slot']          ?: 'top';
        $row['action_type']   = $row['action_type']   ?: 'link';
        $row['action_target'] = $row['action_target'] ?? '';
        $row['style']         = $row['style']         ?: 'p1';
        $row['layout']        = $row['layout']        ?: 'banner';
        $slides[] = $row;
    }
    echo json_encode(['success' => true, 'slides' => $slides], JSON_UNESCAPED_UNICODE);
    exit();
}

// ========== PUBLIC: Get quick actions ==========
if ($action === 'get_quick_actions') {
    $result = $conn->query("SELECT * FROM quick_actions WHERE is_active=1 ORDER BY sort_order ASC");
    $actions = [];
    while ($row = $result->fetch_assoc()) {
        $fields_r = $conn->query("SELECT * FROM quick_action_fields WHERE action_id={$row['id']} ORDER BY sort_order ASC");
        $fields = [];
        while ($f = $fields_r->fetch_assoc()) $fields[] = $f;
        $row['fields'] = $fields;
        $actions[] = $row;
    }
    echo json_encode(['success' => true, 'actions' => $actions]);
    exit();
}

// Auth required for below
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}
$userId = $_SESSION['user_id'];

// ========== Helper: Send real push to a user ==========
// این تابع اکنون به هاب مرکزی includes/notify_helper.php واگذار می‌شود
// تا رفتار push روی اندروید یکسان و اصلاح‌شده باشد.
function sendRealPush($conn, $targetUserId, $title, $body, $url = '/ledor/dashboard.php', $type = 'general') {
    $sent = sendPushToUser($conn, $targetUserId, $title, $body, $type, $url, null);
    return $sent > 0;
}

// ========== Submit quick action form (with balance check and deduction) ==========
if ($action === 'submit_form' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $actionId = intval($data['action_id'] ?? 0);
    $formData = json_encode($data['form_data'] ?? []);
    if ($actionId <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid action']); exit(); }

    // Get action details including min_balance
    $actionRow = $conn->query("SELECT * FROM quick_actions WHERE id=$actionId AND is_active=1")->fetch_assoc();
    if (!$actionRow) { echo json_encode(['success' => false, 'message' => 'Action not found']); exit(); }

    // Check minimum balance requirement
    $minBalance = floatval($actionRow['min_balance'] ?? 0);
    $minCurrency = $actionRow['min_balance_currency'] ?? 'USD';
    if ($minBalance > 0) {
        $balField = 'balance_' . strtolower($minCurrency);
        $userBal = $conn->query("SELECT $balField FROM users WHERE id=$userId")->fetch_assoc();
        $currentBal = floatval($userBal[$balField] ?? 0);
        if ($currentBal < $minBalance) {
            echo json_encode([
                'success' => false,
                'message' => "موجودی شما کافی نیست. حداقل موجودی لازم: $minBalance $minCurrency (موجودی فعلی: " . number_format($currentBal, 2) . " $minCurrency)"
            ]);
            exit();
        }
    }

    // Deduct balance if configured
    $deductBalance = intval($actionRow['deduct_balance'] ?? 0);
    $deductAmount = floatval($actionRow['deduct_amount'] ?? 0);
    $deductCurrency = $actionRow['deduct_currency'] ?? 'USD';

    if ($deductBalance && $deductAmount > 0) {
        $balField = 'balance_' . strtolower($deductCurrency);
        $userBal = $conn->query("SELECT $balField FROM users WHERE id=$userId")->fetch_assoc();
        $currentBal = floatval($userBal[$balField] ?? 0);
        if ($currentBal < $deductAmount) {
            echo json_encode([
                'success' => false,
                'message' => "موجودی شما برای ارسال این درخواست کافی نیست. مبلغ مورد نیاز: $deductAmount $deductCurrency"
            ]);
            exit();
        }
        // Deduct from user balance
        $conn->query("UPDATE users SET $balField = $balField - $deductAmount WHERE id=$userId");
        // Record deduction transaction
        $txId = 'QA' . time() . rand(1000,9999);
        $desc = $conn->real_escape_string("[Quick Action] کسر موجودی بابت درخواست: {$actionRow['label']}");
        $adminId = $conn->query("SELECT id FROM users WHERE is_admin=1 LIMIT 1")->fetch_assoc()['id'] ?? $userId;
        $conn->query("INSERT INTO transactions (transaction_id, sender_id, receiver_id, amount, currency, type, description, status)
            VALUES ('$txId', $userId, $adminId, $deductAmount, '$deductCurrency', 'withdrawal', '$desc', 'completed')");
    }

    // Save submission
    $stmt = $conn->prepare("INSERT INTO quick_action_submissions (action_id, user_id, form_data) VALUES (?,?,?)");
    $stmt->bind_param("iis", $actionId, $userId, $formData);
    $stmt->execute();
    $submissionId = $conn->insert_id;

    // Get user info
    $userRow = $conn->query("SELECT first_name, last_name FROM users WHERE id=$userId")->fetch_assoc();
    $userName = trim(($userRow['first_name'] ?? '') . ' ' . ($userRow['last_name'] ?? ''));
    $actionLabel = $actionRow['label'] ?? 'Form';

    // Notify all admins: DB + real push
    $adminResult = $conn->query("SELECT id FROM users WHERE is_admin=1");
    while ($admin = $adminResult->fetch_assoc()) {
        $adminId = $admin['id'];
        $ntitle = $conn->real_escape_string("📬 درخواست جدید: $actionLabel");
        $nmsg   = $conn->real_escape_string("کاربر $userName یک فرم ارسال کرد." . ($deductBalance && $deductAmount > 0 ? " مبلغ $deductAmount $deductCurrency از موجودی کسر شد." : ""));
        $conn->query("INSERT INTO user_notifications (user_id, type, title, message) VALUES ($adminId,'quick_action','$ntitle','$nmsg')");
        sendRealPush($conn, $adminId, "📬 درخواست جدید: $actionLabel", "کاربر $userName یک فرم ارسال کرد.", '/ledor/admin_panel.php', 'admin_form');
    }

    echo json_encode([
        'success' => true,
        'submission_id' => $submissionId,
        'deducted' => $deductBalance && $deductAmount > 0 ? ['amount' => $deductAmount, 'currency' => $deductCurrency] : null
    ]);
    exit();
}

// ========== ADMIN ONLY below ==========
$adminCheck = $conn->query("SELECT is_admin FROM users WHERE id=$userId")->fetch_assoc();
if (!$adminCheck || !$adminCheck['is_admin']) {
    echo json_encode(['success' => false, 'message' => 'Admin only']);
    exit();
}

// ========== ADMIN: Send toast notification (with real push) ==========
if ($action === 'send_toast' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $targetUserId = intval($data['target_user_id'] ?? 0); // 0 = all
    $title   = $conn->real_escape_string($data['title'] ?? 'Notification');
    $message = $conn->real_escape_string($data['message'] ?? '');

    $rawTitle = $data['title'] ?? 'Notification';
    $rawBody  = $data['message'] ?? '';

    $sentPush  = 0;
    $sentEmail = 0;
    $targets   = 0;

    $opts = [
        'type'  => 'admin_toast',
        'url'   => '/ledor/dashboard.php',
        'push'  => true,
        'email' => true,
        'db'    => true,
    ];

    if ($targetUserId > 0) {
        $r = notifyUser($conn, $targetUserId, $rawTitle, $rawBody, $opts);
        $targets   = 1;
        $sentPush  += ($r['push']  > 0) ? 1 : 0;
        $sentEmail += ($r['email']) ? 1 : 0;
    } else {
        $usersR = $conn->query("SELECT id FROM users WHERE is_admin=0");
        while ($u = $usersR->fetch_assoc()) {
            $r = notifyUser($conn, (int)$u['id'], $rawTitle, $rawBody, $opts);
            $targets++;
            $sentPush  += ($r['push']  > 0) ? 1 : 0;
            $sentEmail += ($r['email']) ? 1 : 0;
        }
    }

    echo json_encode([
        'success'    => true,
        'targets'    => $targets,
        'push_sent'  => $sentPush,
        'email_sent' => $sentEmail
    ]);
    exit();
}

if ($action === 'get_all_users') {
    $result = $conn->query("SELECT id, first_name, last_name, telegram_id FROM users ORDER BY first_name");
    $users = [];
    while ($row = $result->fetch_assoc()) $users[] = $row;
    echo json_encode(['success' => true, 'users' => $users]);
    exit();
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
