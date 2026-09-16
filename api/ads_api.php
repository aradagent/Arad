<?php
// api/ads_api.php
// مدیریت کامل آگهی‌ها - ثبت، لیست، ویرایش، حذف، و آگهی‌های من
// با قابلیت دریافت آگهی‌های جدید برای اعلان‌های لحظه‌ای
// نسخه 2.0 با پشتیبانی از حذف آگهی توسط ادمین

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// بررسی لاگین بودن کاربر
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'لطفاً وارد شوید']);
    exit();
}

$userId = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';

// اطمینان از وجود ستون‌های مورد نیاز نمای بازار (نشان تایید هویت و امتیاز کاربر)
// اصلاح سرعت: این چک روی هر درخواست (از جمله action=list که هر ۵ ثانیه توسط
// پولینگ arad.php برای هر کاربر آنلاین صدا زده می‌شود) اجرا می‌شد؛ حالا throttle شده.
require_once __DIR__ . '/../includes/perf_helpers.php';
if (avapay_throttled('ads_api_users_cols_ensure', 1800)) {
    $__col = $conn->query("SHOW COLUMNS FROM users LIKE 'kyc_status'");
    if ($__col && $__col->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN kyc_status ENUM('none','pending','approved','rejected') DEFAULT 'none'");
    }
}

// بررسی مسدودیت کاربر برای ثبت آگهی جدید
if ($action === 'create') {
    $__banChk = $conn->query("SHOW COLUMNS FROM users LIKE 'is_banned'");
    if ($__banChk && $__banChk->num_rows > 0) {
        $__banRow = $conn->query("SELECT is_banned, ban_reason FROM users WHERE id = " . (int)$userId)->fetch_assoc();
        if (!empty($__banRow['is_banned'])) {
            echo json_encode(['success' => false, 'message' => 'حساب شما مسدود شده و امکان ثبت آگهی ندارید' . (!empty($__banRow['ban_reason']) ? ' (' . $__banRow['ban_reason'] . ')' : '')]);
            exit();
        }
    }
}

// ==================== تعیین ادمین ====================
$ADMIN_TELEGRAM_ID = '5330629504';
$isAdmin = false;

$telegramSql = "SELECT telegram_id FROM users WHERE id = ?";
$telegramStmt = $conn->prepare($telegramSql);
$telegramStmt->bind_param("i", $userId);
$telegramStmt->execute();
$telegramResult = $telegramStmt->get_result();

if ($telegramResult->num_rows > 0) {
    $userTelegram = $telegramResult->fetch_assoc();
    $userTelegramId = $userTelegram['telegram_id'] ?? '';
    if ($userTelegramId == $ADMIN_TELEGRAM_ID) {
        $isAdmin = true;
    }
}

if (!$isAdmin && $userId == 5330629504) {
    $isAdmin = true;
}

// ==================== 1. ثبت آگهی جدید ====================
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $type = $input['type'] ?? '';
    $currency = $input['currency'] ?? '';
    $amount = floatval($input['amount'] ?? 0);
    $price = floatval($input['price_per_unit'] ?? 0);
    $description = isset($input['description']) ? $conn->real_escape_string($input['description']) : '';
    
    if (!in_array($type, ['buy', 'sell'])) {
        echo json_encode(['success' => false, 'message' => 'نوع آگهی نامعتبر است']);
        exit();
    }
    
    if (empty($currency)) {
        echo json_encode(['success' => false, 'message' => 'لطفاً ارز را انتخاب کنید']);
        exit();
    }
    
    if ($amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'مقدار ارز باید بیشتر از صفر باشد']);
        exit();
    }
    
    if ($price <= 0) {
        echo json_encode(['success' => false, 'message' => 'قیمت باید بیشتر از صفر باشد']);
        exit();
    }
    
    // بررسی وجود جدول و ایجاد آن اگر وجود ندارد
    $tableCheck = $conn->query("SHOW TABLES LIKE 'user_ads'");
    if ($tableCheck->num_rows == 0) {
        $conn->query("CREATE TABLE IF NOT EXISTS `user_ads` (
            `id` INT PRIMARY KEY AUTO_INCREMENT,
            `user_id` INT NOT NULL,
            `type` ENUM('buy', 'sell') NOT NULL,
            `currency` VARCHAR(10) NOT NULL,
            `amount` DECIMAL(20,6) NOT NULL,
            `price_per_unit` DECIMAL(20,2) NOT NULL,
            `description` TEXT,
            `status` ENUM('active', 'completed', 'cancelled') DEFAULT 'active',
            `views_count` INT DEFAULT 0,
            `offer_count` INT DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        )");
    }
    
    $sql = "INSERT INTO user_ads (user_id, type, currency, amount, price_per_unit, description) 
            VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("issdds", $userId, $type, $currency, $amount, $price, $description);
    
    if ($stmt->execute()) {
        $newAdId = $conn->insert_id;
        
        // دریافت اطلاعات آگهی جدید برای بازگشت
        $sql2 = "SELECT a.*, u.first_name, u.last_name, u.telegram_id, u.avatar 
                 FROM user_ads a
                 JOIN users u ON a.user_id = u.id
                 WHERE a.id = ?";
        $stmt2 = $conn->prepare($sql2);
        $stmt2->bind_param("i", $newAdId);
        $stmt2->execute();
        $newAd = $stmt2->get_result()->fetch_assoc();
        
        if ($newAd) {
            $newAd['full_name'] = $newAd['first_name'] . ' ' . $newAd['last_name'];
            $newAd['formatted_amount'] = number_format($newAd['amount']);
            $newAd['formatted_price'] = number_format($newAd['price_per_unit']);
            $newAd['is_owner'] = ($newAd['user_id'] == $userId);
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'آگهی با موفقیت ثبت شد',
            'ad_id' => $newAdId,
            'ad' => $newAd
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'خطا در ثبت آگهی: ' . $conn->error]);
    }
    exit();
}

// ==================== 2. دریافت لیست آگهی‌های فعال ====================
if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
    $type = isset($_GET['type']) ? $_GET['type'] : 'all';
    $currency = isset($_GET['currency']) ? $_GET['currency'] : 'all';
    $lastId = isset($_GET['last_id']) ? intval($_GET['last_id']) : 0;
    $getNewOnly = isset($_GET['new_only']) && $_GET['new_only'] == '1';
    
    // اگر فقط آگهی‌های جدیدتر از last_id می‌خواهیم
    if ($lastId > 0) {
        $sql = "SELECT a.*, u.first_name, u.last_name, u.telegram_id, u.avatar, u.kyc_status, u.completed_orders_count 
                FROM user_ads a
                JOIN users u ON a.user_id = u.id
                WHERE a.status = 'active' AND a.id > ?";
        
        $params = [$lastId];
        $types = "i";
        
        if ($type !== 'all') {
            $sql .= " AND a.type = ?";
            $params[] = $type;
            $types .= "s";
        }
        
        if ($currency !== 'all') {
            $sql .= " AND a.currency = ?";
            $params[] = $currency;
            $types .= "s";
        }
        
        $sql .= " ORDER BY a.id ASC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    } 
    // بارگذاری کامل آگهی‌ها
    else {
        $sql = "SELECT a.*, u.first_name, u.last_name, u.telegram_id, u.avatar, u.kyc_status, u.completed_orders_count 
                FROM user_ads a
                JOIN users u ON a.user_id = u.id
                WHERE a.status = 'active'";
        
        if ($type !== 'all') {
            $sql .= " AND a.type = '$type'";
        }
        
        if ($currency !== 'all') {
            $sql .= " AND a.currency = '$currency'";
        }
        
        $sql .= " ORDER BY a.created_at DESC LIMIT $limit";
        
        $result = $conn->query($sql);
    }
    
    $ads = [];
    $maxId = 0;
    
    while ($row = $result->fetch_assoc()) {
        $row['full_name'] = $row['first_name'] . ' ' . $row['last_name'];
        $row['formatted_amount'] = number_format($row['amount']);
        $row['formatted_price'] = number_format($row['price_per_unit']);
        $row['is_owner'] = ($row['user_id'] == $userId);
        $ads[] = $row;
        
        if ($row['id'] > $maxId) {
            $maxId = $row['id'];
        }
    }
    
    if ($lastId > 0) {
        echo json_encode([
            'success' => true, 
            'ads' => $ads,
            'last_id' => $maxId,
            'has_new' => count($ads) > 0,
            'new_count' => count($ads)
        ]);
    } else {
        echo json_encode([
            'success' => true, 
            'ads' => $ads,
            'last_id' => $maxId
        ]);
    }
    exit();
}

// ==================== 3. دریافت آگهی‌های من ====================
if ($action === 'my_ads' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $status = isset($_GET['status']) ? $_GET['status'] : 'all';
    
    $sql = "SELECT a.*, 
            (SELECT COUNT(*) FROM ad_offers WHERE ad_id = a.id) as total_offers,
            (SELECT COUNT(*) FROM ad_offers WHERE ad_id = a.id AND status = 'pending') as pending_offers
            FROM user_ads a
            WHERE a.user_id = ?";
    
    if ($status !== 'all') {
        $sql .= " AND a.status = '$status'";
    }
    
    $sql .= " ORDER BY a.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $ads = [];
    while ($row = $result->fetch_assoc()) {
        $row['formatted_amount'] = number_format($row['amount']);
        $row['formatted_price'] = number_format($row['price_per_unit']);
        $ads[] = $row;
    }
    
    echo json_encode(['success' => true, 'ads' => $ads]);
    exit();
}

// ==================== 4. دریافت جزئیات یک آگهی ====================
if ($action === 'detail' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $adId = isset($_GET['id']) ? intval($_GET['id']) : 0;
    
    if ($adId <= 0) {
        echo json_encode(['success' => false, 'message' => 'شناسه آگهی نامعتبر است']);
        exit();
    }
    
    // افزایش بازدید
    $conn->query("UPDATE user_ads SET views_count = views_count + 1 WHERE id = $adId");
    
    $sql = "SELECT a.*, u.first_name, u.last_name, u.telegram_id, u.avatar, u.phone_number
            FROM user_ads a
            JOIN users u ON a.user_id = u.id
            WHERE a.id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $adId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'آگهی یافت نشد']);
        exit();
    }
    
    $ad = $result->fetch_assoc();
    $ad['full_name'] = $ad['first_name'] . ' ' . $ad['last_name'];
    $ad['formatted_amount'] = number_format($ad['amount']);
    $ad['formatted_price'] = number_format($ad['price_per_unit']);
    $ad['is_owner'] = ($ad['user_id'] == $userId);
    
    echo json_encode(['success' => true, 'ad' => $ad]);
    exit();
}

// ==================== 5. ویرایش آگهی ====================
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $adId = intval($input['ad_id'] ?? 0);
    $amount = floatval($input['amount'] ?? 0);
    $price = floatval($input['price_per_unit'] ?? 0);
    $description = isset($input['description']) ? $conn->real_escape_string($input['description']) : '';
    
    if ($adId <= 0) {
        echo json_encode(['success' => false, 'message' => 'شناسه آگهی نامعتبر است']);
        exit();
    }
    
    if ($amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'مقدار ارز باید بیشتر از صفر باشد']);
        exit();
    }
    
    if ($price <= 0) {
        echo json_encode(['success' => false, 'message' => 'قیمت باید بیشتر از صفر باشد']);
        exit();
    }
    
    // بررسی مالکیت آگهی و فعال بودن
    $checkSql = "SELECT user_id, status FROM user_ads WHERE id = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("i", $adId);
    $checkStmt->execute();
    $ad = $checkStmt->get_result()->fetch_assoc();
    
    if (!$ad) {
        echo json_encode(['success' => false, 'message' => 'آگهی یافت نشد']);
        exit();
    }
    
    if ($ad['user_id'] != $userId) {
        echo json_encode(['success' => false, 'message' => 'شما دسترسی به ویرایش این آگهی ندارید']);
        exit();
    }
    
    if ($ad['status'] != 'active') {
        echo json_encode(['success' => false, 'message' => 'آگهی غیرفعال است و قابل ویرایش نمی‌باشد']);
        exit();
    }
    
    $sql = "UPDATE user_ads SET amount = ?, price_per_unit = ?, description = ?, updated_at = NOW() WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ddsi", $amount, $price, $description, $adId);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'آگهی با موفقیت ویرایش شد'
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'خطا در ویرایش آگهی: ' . $conn->error]);
    }
    exit();
}

// ==================== 6. حذف آگهی توسط ادمین (حذف فیزیکی) ====================
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $adId = isset($input['ad_id']) ? intval($input['ad_id']) : 0;
    
    if ($adId <= 0) {
        echo json_encode(['success' => false, 'message' => 'شناسه آگهی نامعتبر است']);
        exit();
    }
    
    // بررسی دسترسی ادمین
    if (!$isAdmin) {
        echo json_encode(['success' => false, 'message' => 'فقط ادمین می‌تواند آگهی را حذف کند']);
        exit();
    }
    
    // بررسی وجود آگهی
    $checkSql = "SELECT id, user_id, type, currency, amount FROM user_ads WHERE id = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("i", $adId);
    $checkStmt->execute();
    $ad = $checkStmt->get_result()->fetch_assoc();
    
    if (!$ad) {
        echo json_encode(['success' => false, 'message' => 'آگهی یافت نشد']);
        exit();
    }
    
    // حذف فیزیکی آگهی از دیتابیس
    // FIX: پیش از حذف آگهی، معاملات (ad_deals) و پیشنهادات (ad_offers) وابسته به آن نیز
    // حذف می‌شوند تا خطای Foreign key constraint (ad_deals_ibfk_1 / ad_deals_ibfk_2) رخ ندهد.
    try {
        $conn->begin_transaction();

        // حذف معاملات وابسته به این آگهی (چه از طریق ad_id و چه از طریق پیشنهادات آن)
        $delDealsSql = "DELETE d FROM ad_deals d
                        LEFT JOIN ad_offers o ON d.offer_id = o.id
                        WHERE d.ad_id = ? OR o.ad_id = ?";
        $delDealsStmt = $conn->prepare($delDealsSql);
        $delDealsStmt->bind_param("ii", $adId, $adId);
        $delDealsStmt->execute();

        // حذف پیشنهادات وابسته به این آگهی (در صورت عدم حذف خودکار توسط CASCADE)
        $delOffersStmt = $conn->prepare("DELETE FROM ad_offers WHERE ad_id = ?");
        $delOffersStmt->bind_param("i", $adId);
        $delOffersStmt->execute();

        // حذف خود آگهی
        $stmt = $conn->prepare("DELETE FROM user_ads WHERE id = ?");
        $stmt->bind_param("i", $adId);
        $stmt->execute();

        // ثبت لاگ حذف در یک جدول لاگ (اختیاری)
        $logSql = "INSERT INTO admin_logs (admin_id, action, target_id, details, created_at) 
                   VALUES (?, 'delete_ad', ?, ?, NOW())";
        $logStmt = $conn->prepare($logSql);
        $details = "حذف آگهی #{$ad['id']} - نوع: {$ad['type']} - ارز: {$ad['currency']} - مقدار: {$ad['amount']} - متعلق به کاربر: {$ad['user_id']}";
        $logStmt->bind_param("iis", $userId, $adId, $details);
        $logStmt->execute();

        $conn->commit();

        echo json_encode([
            'success' => true, 
            'message' => 'آگهی با موفقیت حذف شد',
            'deleted_ad_id' => $adId
        ]);
    } catch (\Throwable $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'خطا در حذف آگهی: ' . $e->getMessage()]);
    }
    exit();
}

// ==================== 7. حذف آگهی توسط صاحب آگهی (تغییر وضعیت به cancelled) ====================
if ($action === 'cancel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $adId = isset($input['ad_id']) ? intval($input['ad_id']) : 0;
    
    if ($adId <= 0) {
        echo json_encode(['success' => false, 'message' => 'شناسه آگهی نامعتبر است']);
        exit();
    }
    
    // بررسی مالکیت
    $checkSql = "SELECT id, status FROM user_ads WHERE id = ? AND user_id = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("ii", $adId, $userId);
    $checkStmt->execute();
    
    if ($checkStmt->get_result()->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'شما دسترسی به حذف این آگهی ندارید']);
        exit();
    }
    
    $sql = "UPDATE user_ads SET status = 'cancelled' WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $adId);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'آگهی با موفقیت غیرفعال شد']);
    } else {
        echo json_encode(['success' => false, 'message' => 'خطا در غیرفعال کردن آگهی']);
    }
    exit();
}

// ==================== 8. حذف فیزیکی آگهی (برای آگهی‌های غیرفعال - فقط ادمین) ====================
if ($action === 'force_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $adId = isset($input['ad_id']) ? intval($input['ad_id']) : 0;
    
    if ($adId <= 0) {
        echo json_encode(['success' => false, 'message' => 'شناسه آگهی نامعتبر است']);
        exit();
    }
    
    // فقط ادمین می‌تواند حذف فیزیکی کند
    if (!$isAdmin) {
        echo json_encode(['success' => false, 'message' => 'فقط ادمین می‌تواند آگهی را حذف کند']);
        exit();
    }
    
    // FIX: حذف معاملات و پیشنهادات وابسته پیش از حذف آگهی تا خطای Foreign key رخ ندهد
    try {
        $conn->begin_transaction();

        $delDealsStmt = $conn->prepare("DELETE d FROM ad_deals d
                        LEFT JOIN ad_offers o ON d.offer_id = o.id
                        WHERE d.ad_id = ? OR o.ad_id = ?");
        $delDealsStmt->bind_param("ii", $adId, $adId);
        $delDealsStmt->execute();

        $delOffersStmt = $conn->prepare("DELETE FROM ad_offers WHERE ad_id = ?");
        $delOffersStmt->bind_param("i", $adId);
        $delOffersStmt->execute();

        $stmt = $conn->prepare("DELETE FROM user_ads WHERE id = ?");
        $stmt->bind_param("i", $adId);
        $stmt->execute();

        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'آگهی با موفقیت حذف شد']);
    } catch (\Throwable $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'خطا در حذف آگهی: ' . $e->getMessage()]);
    }
    exit();
}

// ==================== 9. دریافت آمار آگهی‌های کاربر ====================
if ($action === 'stats' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $sql = "SELECT 
                COUNT(*) as total_ads,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_ads,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_ads,
                SUM(offer_count) as total_offers_received
            FROM user_ads 
            WHERE user_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc();
    
    echo json_encode(['success' => true, 'stats' => $stats]);
    exit();
}

// ==================== 10. چک کردن وجود آگهی جدید (برای پولینگ سریع) ====================
if ($action === 'check_new' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $lastId = isset($_GET['last_id']) ? intval($_GET['last_id']) : 0;
    
    if ($lastId <= 0) {
        echo json_encode(['success' => false, 'message' => 'last_id required']);
        exit();
    }
    
    $sql = "SELECT COUNT(*) as new_count, MAX(id) as max_id 
            FROM user_ads 
            WHERE status = 'active' AND id > ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $lastId);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    
    echo json_encode([
        'success' => true,
        'has_new' => $data['new_count'] > 0,
        'new_count' => $data['new_count'],
        'latest_id' => $data['max_id'] ?? $lastId
    ]);
    exit();
}

// ==================== 11. دریافت لیست کامل آگهی‌ها برای ادمین (شامل آگهی‌های غیرفعال) ====================
if ($action === 'admin_list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!$isAdmin) {
        echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']);
        exit();
    }
    
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
    $status = isset($_GET['status']) ? $_GET['status'] : 'all';
    
    $sql = "SELECT a.*, u.first_name, u.last_name, u.telegram_id, u.email
            FROM user_ads a
            JOIN users u ON a.user_id = u.id";
    
    if ($status !== 'all') {
        $sql .= " WHERE a.status = '$status'";
    }
    
    $sql .= " ORDER BY a.created_at DESC LIMIT $limit";
    
    $result = $conn->query($sql);
    $ads = [];
    
    while ($row = $result->fetch_assoc()) {
        $row['full_name'] = $row['first_name'] . ' ' . $row['last_name'];
        $row['formatted_amount'] = number_format($row['amount']);
        $row['formatted_price'] = number_format($row['price_per_unit']);
        $ads[] = $row;
    }
    
    echo json_encode(['success' => true, 'ads' => $ads]);
    exit();
}

// ==================== گزارش تخلف آگهی ====================
if ($action === 'report' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $adId = isset($input['ad_id']) ? intval($input['ad_id']) : 0;
    $reason = trim((string)($input['reason'] ?? ''));

    if ($adId <= 0) {
        echo json_encode(['success' => false, 'message' => 'شناسه آگهی نامعتبر است']);
        exit();
    }
    if ($reason === '') {
        echo json_encode(['success' => false, 'message' => 'لطفاً دلیل گزارش را بنویسید']);
        exit();
    }

    $conn->query("CREATE TABLE IF NOT EXISTS ad_reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ad_id INT NOT NULL,
        reporter_id INT NOT NULL,
        reason TEXT NOT NULL,
        status ENUM('pending','reviewed','dismissed') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $checkSql = "SELECT id FROM user_ads WHERE id = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("i", $adId);
    $checkStmt->execute();
    if (!$checkStmt->get_result()->fetch_assoc()) {
        echo json_encode(['success' => false, 'message' => 'آگهی یافت نشد']);
        exit();
    }

    $stmt = $conn->prepare("INSERT INTO ad_reports (ad_id, reporter_id, reason) VALUES (?, ?, ?)");
    $stmt->bind_param("iis", $adId, $userId, $reason);
    $stmt->execute();

    echo json_encode(['success' => true, 'message' => 'گزارش شما ثبت شد. تیم پشتیبانی بررسی خواهد کرد.']);
    exit();
}

// ==================== اگر اکشن نامعتبر بود ====================
echo json_encode(['success' => false, 'message' => 'اکشن نامعتبر است']);
?>