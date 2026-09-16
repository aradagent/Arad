<?php
// api/discount_api.php - مدیریت تخفیف‌های کاربران با ارسال نوتیفیکیشن به ربات

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

$userId = $_SESSION['user_id'];
$ADMIN_TELEGRAM_ID = '5330629504';
$isAdmin = false;

// ==================== تنظیمات ربات تلگرام ====================
// توکن ربات خود را در اینجا وارد کنید
$BOT_TOKEN = '5937135973:AAEwK4lxar3xRM_mwvapLWNuw26VUv2c6e4'; // مثال: '1234567890:ABCdefGHIjklmNOPqrstUVwxyz'

// تابع ارسال پیام به تلگرام
function sendTelegramNotification($chatId, $message, $botToken) {
    if (empty($botToken) || $botToken == 'YOUR_BOT_TOKEN_HERE') {
        return false; // توکن تنظیم نشده
    }
    
    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML'
    ];
    
    $options = [
        'http' => [
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query($data),
            'timeout' => 5
        ]
    ];
    
    $context = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);
    if ($result === false) {
        return false;
    }
    return json_decode($result, true);
}

// ==================== بررسی ادمین بودن ====================
$checkSql = "SELECT telegram_id FROM users WHERE id = ?";
$checkStmt = $conn->prepare($checkSql);
$checkStmt->bind_param("i", $userId);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();
if ($checkResult->num_rows > 0) {
    $userData = $checkResult->fetch_assoc();
    if ($userData['telegram_id'] == $ADMIN_TELEGRAM_ID) {
        $isAdmin = true;
    }
}

if (!$isAdmin && $userId == 5330629504) {
    $isAdmin = true;
}

$action = $_GET['action'] ?? '';

// ==================== کمیسیون/تخفیف کاربر جاری (برای نمایش در صفحه‌ی تبادل ارزی) ====================
if ($action === 'my_commission_summary' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $discountPercent = 0;
    $discountInfo = null;
    $chk = $conn->query("SHOW TABLES LIKE 'user_discounts'");
    if ($chk && $chk->num_rows > 0) {
        $stmt = $conn->prepare("SELECT discount_percent, description, expires_at FROM user_discounts WHERE user_id = ? AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY id DESC LIMIT 1");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows > 0) {
            $discountInfo = $res->fetch_assoc();
            $discountPercent = floatval($discountInfo['discount_percent']);
        }
    }

    $rules = [];
    $chkRules = $conn->query("SHOW TABLES LIKE 'user_commission_rules'");
    if ($chkRules && $chkRules->num_rows > 0) {
        $rstmt = $conn->prepare("SELECT currency, unit, min_amount, max_amount, rule_type, value FROM user_commission_rules WHERE user_id = ? ORDER BY unit, currency, min_amount");
        $rstmt->bind_param("i", $userId);
        $rstmt->execute();
        $rres = $rstmt->get_result();
        while ($r = $rres->fetch_assoc()) $rules[] = $r;
    }

    echo json_encode([
        'success' => true,
        'discount_percent' => $discountPercent,
        'discount_description' => $discountInfo['description'] ?? null,
        'discount_expires_at' => $discountInfo['expires_at'] ?? null,
        'rules' => $rules,
    ]);
    exit();
}

// ==================== جستجوی کاربران ====================
if ($action === 'search_users' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!$isAdmin) {
        echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']);
        exit();
    }

    $search = isset($_GET['search']) ? '%' . $_GET['search'] . '%' : '%%';
    $sql = "SELECT u.id, u.first_name, u.last_name, u.email, u.telegram_id, u.phone_number,
                   d.discount_percent, d.description AS discount_description, d.expires_at AS discount_expires_at
            FROM users u
            LEFT JOIN user_discounts d ON d.user_id = u.id AND (d.expires_at IS NULL OR d.expires_at > NOW())
            WHERE u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.telegram_id LIKE ?
            ORDER BY u.id DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssss", $search, $search, $search, $search);
    $stmt->execute();
    $result = $stmt->get_result();
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    
    echo json_encode(['success' => true, 'users' => $users]);
    exit();
}

// ==================== (جدید) حجم معاملاتِ یک کاربر: واقعی + دستی ====================
if ($action === 'get_user_volume' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!$isAdmin) { echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']); exit(); }
    $uid = (int)($_GET['user_id'] ?? 0);
    if ($uid <= 0) { echo json_encode(['success' => false, 'message' => 'کاربر نامعتبر']); exit(); }
    $currency = avapay_get_tier_currency($conn);
    $manual = avapay_get_user_manual_volume($conn, $uid, $currency);
    $total  = avapay_get_user_volume_toman($conn, $uid); // real + manual
    $real   = max(0, $total - $manual);
    echo json_encode([
        'success'  => true,
        'currency' => $currency,
        'real_volume'   => $real,
        'manual_volume' => $manual,
        'total_volume'  => $total,
    ]);
    exit();
}

// ==================== (جدید) ثبت/ویرایش حجمِ دستیِ معاملات یک کاربر ====================
if ($action === 'set_manual_volume' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) { echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']); exit(); }
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $uid    = (int)($in['user_id'] ?? 0);
    $amount = (float)($in['amount'] ?? -1);
    if ($uid <= 0 || $amount < 0) { echo json_encode(['success' => false, 'message' => 'مقدار نامعتبر']); exit(); }
    $currency = avapay_get_tier_currency($conn);
    $ok = avapay_set_user_manual_volume($conn, $uid, $amount, $currency);
    echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'حجم دستی ثبت شد' : 'خطا در ثبت']);
    exit();
}

// ==================== لیست کاربران دارای تخفیف (+حذف خودکار منقضی‌ها) ====================
if ($action === 'list_discounts' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!$isAdmin) { echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']); exit(); }

    // اطمینان از وجود جدول
    $conn->query("CREATE TABLE IF NOT EXISTS `user_discounts` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `user_id` INT NOT NULL,
        `discount_percent` DECIMAL(5,2) NOT NULL DEFAULT 0,
        `description` TEXT,
        `created_by` INT NOT NULL,
        `expires_at` DATETIME,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `unique_user_discount` (`user_id`)
    )");

    // حذف خودکار تخفیف‌های منقضی‌شده
    $conn->query("DELETE FROM user_discounts WHERE expires_at IS NOT NULL AND expires_at <= NOW()");

    $sql = "SELECT d.user_id, d.discount_percent, d.description, d.expires_at, d.created_at,
                   u.first_name, u.last_name, u.telegram_id, u.email
            FROM user_discounts d
            JOIN users u ON d.user_id = u.id
            ORDER BY d.updated_at DESC";
    $res = $conn->query($sql);
    $list = [];
    while ($res && $row = $res->fetch_assoc()) $list[] = $row;

    echo json_encode(['success' => true, 'discounts' => $list]);
    exit();
}

// ==================== ذخیره تخفیف ====================
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']);
        exit();
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $targetUserId = intval($input['user_id'] ?? 0);
    $discountPercent = floatval($input['discount_percent'] ?? 0);
    $description = isset($input['description']) ? $conn->real_escape_string($input['description']) : '';
    $expiresAt = isset($input['expires_at']) && !empty($input['expires_at']) ? $input['expires_at'] : null;
    
    if ($targetUserId <= 0) {
        echo json_encode(['success' => false, 'message' => 'کاربر نامعتبر است']);
        exit();
    }
    
    if ($discountPercent < 0 || $discountPercent > 100) {
        echo json_encode(['success' => false, 'message' => 'درصد تخفیف باید بین 0 تا 100 باشد']);
        exit();
    }
    
    // دریافت اطلاعات کاربر هدف
    $userInfoSql = "SELECT first_name, last_name, telegram_id FROM users WHERE id = ?";
    $userInfoStmt = $conn->prepare($userInfoSql);
    $userInfoStmt->bind_param("i", $targetUserId);
    $userInfoStmt->execute();
    $targetUser = $userInfoStmt->get_result()->fetch_assoc();
    
    // دریافت اطلاعات ادمین
    $adminInfoSql = "SELECT first_name, last_name, telegram_id FROM users WHERE id = ?";
    $adminInfoStmt = $conn->prepare($adminInfoSql);
    $adminInfoStmt->bind_param("i", $userId);
    $adminInfoStmt->execute();
    $adminUser = $adminInfoStmt->get_result()->fetch_assoc();
    
    // ایجاد جدول اگر وجود نداشته باشد
    $conn->query("CREATE TABLE IF NOT EXISTS `user_discounts` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `user_id` INT NOT NULL,
        `discount_percent` DECIMAL(5,2) NOT NULL DEFAULT 0,
        `description` TEXT,
        `created_by` INT NOT NULL,
        `expires_at` DATETIME,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
        UNIQUE KEY `unique_user_discount` (`user_id`)
    )");
    
    // بررسی آیا تخفیف قبلاً وجود داشته
    $wasExisting = false;
    $oldDiscountPercent = 0;
    
    $checkExistingSql = "SELECT discount_percent FROM user_discounts WHERE user_id = ?";
    $checkExistingStmt = $conn->prepare($checkExistingSql);
    $checkExistingStmt->bind_param("i", $targetUserId);
    $checkExistingStmt->execute();
    $existingResult = $checkExistingStmt->get_result();
    if ($existingResult->num_rows > 0) {
        $wasExisting = true;
        $oldDiscountPercent = $existingResult->fetch_assoc()['discount_percent'];
    }
    
    // حذف تخفیف اگر درصد صفر است
    if ($discountPercent == 0) {
        $sql = "DELETE FROM user_discounts WHERE user_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $targetUserId);
        $stmt->execute();
        
        // ارسال نوتیفیکیشن حذف تخفیف به کاربر
        if ($targetUser && !empty($targetUser['telegram_id'])) {
            $message = "❌ <b>حذف تخفیف کمیسیون</b>\n\n" .
                       "👤 کاربر: <b>" . htmlspecialchars($targetUser['first_name'] . ' ' . $targetUser['last_name']) . "</b>\n" .
                       "📊 تخفیف قبلی شما: <b>" . $oldDiscountPercent . "%</b>\n" .
                       "🔄 وضعیت: <b>تخفیف شما حذف شد</b>\n" .
                       "👨‍💼 مدیر: " . htmlspecialchars($adminUser['first_name'] . ' ' . $adminUser['last_name']) . "\n" .
                       "📅 تاریخ: " . date('Y/m/d H:i:s');
            
            sendTelegramNotification($targetUser['telegram_id'], $message, $BOT_TOKEN);
        }
        
        echo json_encode(['success' => true, 'message' => 'تخفیف کاربر حذف شد']);
        exit();
    }
    
    // ایجاد یا به‌روزرسانی تخفیف
    $sql = "INSERT INTO user_discounts (user_id, discount_percent, description, created_by, expires_at) 
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            discount_percent = VALUES(discount_percent),
            description = VALUES(description),
            expires_at = VALUES(expires_at),
            updated_at = CURRENT_TIMESTAMP";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("idsss", $targetUserId, $discountPercent, $description, $userId, $expiresAt);
    
    if ($stmt->execute()) {
        // ارسال نوتیفیکیشن به کاربر
        if ($targetUser && !empty($targetUser['telegram_id'])) {
            $expiresText = $expiresAt ? "\n📅 اعتبار تا: " . date('Y/m/d', strtotime($expiresAt)) : "\n📅 اعتبار: نامحدود";
            $actionText = $wasExisting ? "✏️ ویرایش تخفیف" : "🎉 اعمال تخفیف جدید";
            $percentChange = "";
            if ($wasExisting && $oldDiscountPercent != $discountPercent) {
                $percentChange = "\n📈 تغییر تخفیف: از <b>" . $oldDiscountPercent . "%</b> به <b>" . $discountPercent . "%</b>";
            }
            
            $message = $actionText . " کمیسیون\n\n" .
                       "👤 کاربر: <b>" . htmlspecialchars($targetUser['first_name'] . ' ' . $targetUser['last_name']) . "</b>\n" .
                       "📊 درصد تخفیف: <b>" . $discountPercent . "%</b>" . $percentChange . "\n" .
                       "📝 توضیحات: " . ($description ? htmlspecialchars($description) : "ندارد") . "\n" .
                       $expiresText . "\n" .
                       "👨‍💼 مدیر: " . htmlspecialchars($adminUser['first_name'] . ' ' . $adminUser['last_name']) . "\n" .
                       "📅 تاریخ: " . date('Y/m/d H:i:s') . "\n\n" .
                       "🔰 <i> این تخفیف از کمیسیون پایه شما کسر خواهد شد. و فقط مخصوص معامله در پلتفورم معاملاتی Ava Pay میباشد</i>";
            
            sendTelegramNotification($targetUser['telegram_id'], $message, $BOT_TOKEN);
        }
        
        // ارسال پیام تأیید به ادمین
        $expiresText = $expiresAt ? " تا تاریخ " . date('Y/m/d', strtotime($expiresAt)) : " (نامحدود)";
        $adminMessage = "✅ <b>تخفیف با موفقیت ثبت شد</b>\n\n" .
                        "👤 کاربر: <b>" . htmlspecialchars($targetUser['first_name'] . ' ' . $targetUser['last_name']) . "</b>\n" .
                        "🆔 آیدی کاربر: " . $targetUserId . "\n" .
                        "📊 درصد تخفیف: <b>" . $discountPercent . "%</b>\n" .
                        "📝 توضیحات: " . ($description ? htmlspecialchars($description) : "ندارد") . "\n" .
                        "📅 اعتبار: " . $expiresText . "\n" .
                        "📅 تاریخ ثبت: " . date('Y/m/d H:i:s');
        
        sendTelegramNotification($ADMIN_TELEGRAM_ID, $adminMessage, $BOT_TOKEN);
        
        $responseMessage = $wasExisting ? 'تخفیف با موفقیت به‌روزرسانی شد' : 'تخفیف با موفقیت ثبت شد';
        if (!empty($targetUser['telegram_id'])) {
            $responseMessage .= ' و به کاربر اطلاع داده شد';
        }
        
        echo json_encode(['success' => true, 'message' => $responseMessage]);
    } else {
        echo json_encode(['success' => false, 'message' => 'خطا در ثبت تخفیف: ' . $conn->error]);
    }
    exit();
}

// =====================================================================
// قوانین پلکانی کمیسیون (برای هر کاربر، به تفکیک بازهٔ مبلغ و ارز)
// مثال ۱: از ۱ تا ۱۰۰۰ (ارز انتخابی) => کمیسیون ثابت ۵ واحد از همان ارز
// مثال ۲: بیشتر از ۱۰۰۰ یورو => کمیسیون ۱ درصد
// این قوانین اگر برای بازهٔ مبلغ درخواستی و ارز معامله پیدا شوند، جایگزین
// فرمول پیش‌فرض کمیسیون می‌شوند (نه فقط یک درصد تخفیف روی آن).
// =====================================================================
function ax_ensureRulesTable($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS `user_commission_rules` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `user_id` INT NOT NULL,
        `currency` VARCHAR(10) NOT NULL DEFAULT 'ALL',
        `unit` ENUM('currency','toman') NOT NULL DEFAULT 'currency',
        `min_amount` DECIMAL(20,2) NOT NULL DEFAULT 0,
        `max_amount` DECIMAL(20,2) NULL,
        `rule_type` ENUM('fixed','percent') NOT NULL DEFAULT 'fixed',
        `value` DECIMAL(20,4) NOT NULL,
        `created_by` INT NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    )");
}

// ---- لیست قوانین یک کاربر ----
if ($action === 'list_rules' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!$isAdmin) { echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']); exit(); }
    ax_ensureRulesTable($conn);
    $targetUserId = intval($_GET['user_id'] ?? 0);
    $stmt = $conn->prepare("SELECT * FROM user_commission_rules WHERE user_id = ? ORDER BY currency, min_amount");
    $stmt->bind_param("i", $targetUserId);
    $stmt->execute();
    $rows = [];
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    echo json_encode(['success' => true, 'rules' => $rows]);
    exit();
}

// ---- ثبت قانون جدید ----
// ورودی نمونه: { user_id, currency:'USDT'|'ALL', unit:'currency'|'toman',
//                min_amount:1, max_amount:1000 (یا null برای «به بالا»),
//                rule_type:'fixed'|'percent', value:5 }
if ($action === 'save_rule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) { echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']); exit(); }
    ax_ensureRulesTable($conn);
    $input = json_decode(file_get_contents('php://input'), true);

    $targetUserId = intval($input['user_id'] ?? 0);
    $currency     = strtoupper(trim($input['currency'] ?? 'ALL'));
    $unit         = ($input['unit'] ?? 'currency') === 'toman' ? 'toman' : 'currency';
    $minAmount    = floatval($input['min_amount'] ?? 0);
    $maxAmount    = (isset($input['max_amount']) && $input['max_amount'] !== '' && $input['max_amount'] !== null)
                    ? floatval($input['max_amount']) : null;
    $ruleType     = ($input['rule_type'] ?? 'fixed') === 'percent' ? 'percent' : 'fixed';
    $value        = floatval($input['value'] ?? -1);

    if ($targetUserId <= 0) { echo json_encode(['success' => false, 'message' => 'کاربر نامعتبر است']); exit(); }
    if ($value < 0) { echo json_encode(['success' => false, 'message' => 'مقدار کمیسیون نامعتبر است']); exit(); }
    if ($maxAmount !== null && $maxAmount <= $minAmount) { echo json_encode(['success' => false, 'message' => 'بازهٔ مبلغ نامعتبر است']); exit(); }

    $stmt = $conn->prepare("INSERT INTO user_commission_rules
        (user_id, currency, unit, min_amount, max_amount, rule_type, value, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issddsdi", $targetUserId, $currency, $unit, $minAmount, $maxAmount, $ruleType, $value, $userId);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'قانون کمیسیون ثبت شد', 'rule_id' => $stmt->insert_id]);
    } else {
        echo json_encode(['success' => false, 'message' => 'خطا در ثبت: ' . $conn->error]);
    }
    exit();
}

// ---- حذف قانون ----
if ($action === 'delete_rule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) { echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']); exit(); }
    ax_ensureRulesTable($conn);
    $input = json_decode(file_get_contents('php://input'), true);
    $ruleId = intval($input['rule_id'] ?? 0);
    $stmt = $conn->prepare("DELETE FROM user_commission_rules WHERE id = ?");
    $stmt->bind_param("i", $ruleId);
    $stmt->execute();
    echo json_encode(['success' => true, 'message' => 'قانون حذف شد']);
    exit();
}

// =====================================================================
// کمیسیون پیش‌فرض برای همه‌ی کاربران (وقتی کاربر قانون اختصاصی خودش را
// نداشته باشد، این قانون به‌جای فرمول پایه‌ی ثابت اعمال می‌شود).
// ساختار دقیقاً مثل قوانین اختصاصیِ هر کاربر است، فقط بدون user_id.
// =====================================================================
function ax_ensureDefaultRulesTable($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS `default_commission_rules` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `unit` ENUM('currency','toman') NOT NULL DEFAULT 'currency',
        `min_amount` DECIMAL(20,2) NOT NULL DEFAULT 0,
        `max_amount` DECIMAL(20,2) NULL,
        `rule_type` ENUM('fixed','percent') NOT NULL DEFAULT 'fixed',
        `value` DECIMAL(20,4) NOT NULL,
        `created_by` INT NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
}

// ---- لیست قوانین پیش‌فرض ----
if ($action === 'list_default_rules' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!$isAdmin) { echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']); exit(); }
    ax_ensureDefaultRulesTable($conn);
    $rows = [];
    $res = $conn->query("SELECT * FROM default_commission_rules ORDER BY unit, min_amount");
    while ($res && $r = $res->fetch_assoc()) $rows[] = $r;
    echo json_encode(['success' => true, 'rules' => $rows]);
    exit();
}

// ---- ثبت قانون پیش‌فرض جدید ----
// ورودی نمونه: { unit:'currency'|'toman', min_amount:1, max_amount:1000 (یا null),
//                rule_type:'fixed'|'percent', value:5 }
if ($action === 'save_default_rule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) { echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']); exit(); }
    ax_ensureDefaultRulesTable($conn);
    $input = json_decode(file_get_contents('php://input'), true);

    $unit      = ($input['unit'] ?? 'currency') === 'toman' ? 'toman' : 'currency';
    $minAmount = floatval($input['min_amount'] ?? 0);
    $maxAmount = (isset($input['max_amount']) && $input['max_amount'] !== '' && $input['max_amount'] !== null)
                 ? floatval($input['max_amount']) : null;
    $ruleType  = ($input['rule_type'] ?? 'fixed') === 'percent' ? 'percent' : 'fixed';
    $value     = floatval($input['value'] ?? -1);

    if ($value < 0) { echo json_encode(['success' => false, 'message' => 'مقدار کمیسیون نامعتبر است']); exit(); }
    if ($maxAmount !== null && $maxAmount <= $minAmount) { echo json_encode(['success' => false, 'message' => 'بازهٔ مبلغ نامعتبر است']); exit(); }

    $stmt = $conn->prepare("INSERT INTO default_commission_rules
        (unit, min_amount, max_amount, rule_type, value, created_by)
        VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sddsdi", $unit, $minAmount, $maxAmount, $ruleType, $value, $userId);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'کمیسیون پیش‌فرض ثبت شد', 'rule_id' => $stmt->insert_id]);
    } else {
        echo json_encode(['success' => false, 'message' => 'خطا در ثبت: ' . $conn->error]);
    }
    exit();
}

// ---- حذف قانون پیش‌فرض ----
if ($action === 'delete_default_rule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) { echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']); exit(); }
    ax_ensureDefaultRulesTable($conn);
    $input = json_decode(file_get_contents('php://input'), true);
    $ruleId = intval($input['rule_id'] ?? 0);
    $stmt = $conn->prepare("DELETE FROM default_commission_rules WHERE id = ?");
    $stmt->bind_param("i", $ruleId);
    $stmt->execute();
    echo json_encode(['success' => true, 'message' => 'حذف شد']);
    exit();
}

/* ====================================================================
   کمیسیون تخفیف ثابت — یک کاربر همیشه فقط یک مبلغ ثابت (در ارز دلخواه
   ادمین) به‌عنوان کمیسیون می‌پردازد، فارغ از حجم/ارز معامله‌اش، و همه‌ی
   قوانین دیگر (پلکانی/پیش‌فرض/تخفیف درصدی/تیر) برایش کاملاً بی‌اثر
   می‌شوند — اولویتِ این قانون در خودِ oa_commission() (includes/offer_actions.php)
   بالاتر از هر چیز دیگری است.
   ==================================================================== */
function ax_ensureFixedCommissionTable($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS `user_fixed_commission` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `user_id` INT NOT NULL,
        `fixed_amount` DECIMAL(20,2) NOT NULL,
        `fixed_currency` VARCHAR(10) NOT NULL DEFAULT 'EUR',
        `description` VARCHAR(255) DEFAULT NULL,
        `created_by` INT NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `unique_user_fixed` (`user_id`)
    )");
}

// ---- کمیسیون ثابت فعلیِ یک کاربر خاص (برای پرکردن فرم) ----
if ($action === 'get_fixed_commission' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!$isAdmin) { echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']); exit(); }
    ax_ensureFixedCommissionTable($conn);
    $targetUserId = intval($_GET['user_id'] ?? 0);
    $stmt = $conn->prepare("SELECT fixed_amount, fixed_currency, description FROM user_fixed_commission WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $targetUserId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    echo json_encode(['success' => true, 'item' => $row ?: null]);
    exit();
}

// ---- لیست کاربران دارای کمیسیون ثابت ----
if ($action === 'list_fixed_commission' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!$isAdmin) { echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']); exit(); }
    ax_ensureFixedCommissionTable($conn);
    $res = $conn->query("SELECT f.user_id, f.fixed_amount, f.fixed_currency, f.description, f.updated_at,
                                 u.first_name, u.last_name, u.telegram_id
                          FROM user_fixed_commission f
                          JOIN users u ON f.user_id = u.id
                          ORDER BY f.updated_at DESC");
    $list = [];
    while ($res && $row = $res->fetch_assoc()) $list[] = $row;
    echo json_encode(['success' => true, 'items' => $list]);
    exit();
}

// ---- ذخیره/به‌روزرسانی کمیسیون ثابت یک کاربر ----
if ($action === 'save_fixed_commission' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) { echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']); exit(); }
    ax_ensureFixedCommissionTable($conn);

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $targetUserId = intval($input['user_id'] ?? 0);
    $fixedAmount  = floatval($input['fixed_amount'] ?? 0);
    $fixedCurrency = strtoupper(trim($input['fixed_currency'] ?? 'EUR'));
    $description  = trim($input['description'] ?? '');

    if ($targetUserId <= 0) { echo json_encode(['success' => false, 'message' => 'کاربر نامعتبر است']); exit(); }
    if ($fixedAmount <= 0) { echo json_encode(['success' => false, 'message' => 'مبلغ ثابت باید بزرگ‌تر از صفر باشد']); exit(); }
    if (!in_array($fixedCurrency, ['IRR', 'USD', 'EUR', 'USDT'], true)) {
        echo json_encode(['success' => false, 'message' => 'ارز نامعتبر است']); exit();
    }

    $stmt = $conn->prepare("INSERT INTO user_fixed_commission (user_id, fixed_amount, fixed_currency, description, created_by)
                             VALUES (?, ?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE fixed_amount = VALUES(fixed_amount),
                                 fixed_currency = VALUES(fixed_currency), description = VALUES(description)");
    $stmt->bind_param("idssi", $targetUserId, $fixedAmount, $fixedCurrency, $description, $userId);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'کمیسیون ثابت ذخیره شد']);
    } else {
        echo json_encode(['success' => false, 'message' => 'خطا در ذخیره: ' . $conn->error]);
    }
    exit();
}

// ---- حذف کمیسیون ثابت یک کاربر (برمی‌گردد به قوانین معمول) ----
if ($action === 'delete_fixed_commission' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) { echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']); exit(); }
    ax_ensureFixedCommissionTable($conn);
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $targetUserId = intval($input['user_id'] ?? 0);
    $stmt = $conn->prepare("DELETE FROM user_fixed_commission WHERE user_id = ?");
    $stmt->bind_param("i", $targetUserId);
    $stmt->execute();
    echo json_encode(['success' => true, 'message' => 'حذف شد — این کاربر دوباره طبق قوانین معمول محاسبه می‌شود']);
    exit();
}

echo json_encode(['success' => false, 'message' => 'اکشن نامعتبر است']);
?>