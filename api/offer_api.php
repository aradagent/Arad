<?php
// api/offer_api.php
// مدیریت کامل پیشنهادات در پلتفرم آراد

error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/notify_helper.php';
require_once __DIR__ . '/../includes/offer_actions.php';
require_once __DIR__ . '/../includes/csrf.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'لطفاً وارد شوید']);
    exit();
}

$userId = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';

if (!defined('ADMIN_TELEGRAM_ID')) define('ADMIN_TELEGRAM_ID', '5330629504');

// بررسی مسدودیت کاربر برای ارسال پیشنهاد جدید
if ($action === 'create') {
    $__banChk = $conn->query("SHOW COLUMNS FROM users LIKE 'is_banned'");
    if ($__banChk && $__banChk->num_rows > 0) {
        $__banRow = $conn->query("SELECT is_banned, ban_reason FROM users WHERE id = " . (int)$userId)->fetch_assoc();
        if (!empty($__banRow['is_banned'])) {
            echo json_encode(['success' => false, 'message' => 'حساب شما مسدود شده و امکان ارسال پیشنهاد ندارید' . (!empty($__banRow['ban_reason']) ? ' (' . $__banRow['ban_reason'] . ')' : '')]);
            exit();
        }
    }
}

// تابع دریافت اطلاعات کاربر
function getUserInfo($conn, $userId) {
    $stmt = $conn->prepare("SELECT first_name, last_name, telegram_id, avatar, email, phone FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

// تابع ارسال پیام تلگرام
function sendTelegram($chatId, $message, $inlineKeyboard = null) {
    if (empty($chatId)) return false;
    $botToken = '5937135973:AAEwK4lxar3xRM_mwvapLWNuw26VUv2c6e4';
    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";

    $postData = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true
    ];

    if ($inlineKeyboard) {
        $postData['reply_markup'] = json_encode($inlineKeyboard);
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    curl_close($ch);
    return true;
}

// آیا کاربر اجازه‌ی نوتیفیکیشن تلگرام دارد؟ (به تنظیم پروفایل احترام می‌گذارد)
function offerTgAllowed($conn, $userId) {
    $col = $conn->query("SHOW COLUMNS FROM users LIKE 'notify_telegram'");
    if (!$col || $col->num_rows === 0) return true;
    $r = $conn->query("SELECT notify_telegram FROM users WHERE id=" . intval($userId))->fetch_assoc();
    return !$r || (int)($r['notify_telegram'] ?? 1) === 1;
}

// ارسال ایمیل اعلان (در صورت فعال بودن تنظیم کاربر) - از هاب مرکزی
function offerEmail($conn, $userId, $title, $body) {
    if (function_exists('sendEmailNotification')) {
        return sendEmailNotification($conn, $userId, $title, $body);
    }
    return false;
}


// ==================== تابع ارسال PUSH NOTIFICATION ====================
function sendPushToUser($conn, $userId, $title, $body, $type = 'general', $url = '/ledor/arad.php', $relatedId = null) {
    // بارگذاری کتابخانه WebPush
    $vendorPaths = [
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../vendor/autoload.php',
    ];

    $webPushLoaded = false;
    foreach ($vendorPaths as $path) {
        if (file_exists($path)) {
            require_once $path;
            $webPushLoaded = true;
            break;
        }
    }

    if (!$webPushLoaded) {
        error_log("WebPush library not found");
        return false;
    }

    // دریافت subscription های کاربر
    $stmt = $conn->prepare("SELECT endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        return false; // کاربر push subscription ندارد
    }

    $VAPID_PUBLIC_KEY  = 'BFYQrNpREIzsQ5kfKXCXB0RPRmH9U3wrQkcbfZP5UC90bMgABYu9J-3XQNoOaatmEO-lSaOjOpmLKihMO381GkM';
    $VAPID_PRIVATE_KEY = 'awsT3nzgG-RHKjP8eLqT5j4fVIk95d1YPf4MUPciHT4';

    $auth = [
        'VAPID' => [
            'subject' => 'https://aradexchange.com',
            'publicKey' => $VAPID_PUBLIC_KEY,
            'privateKey' => $VAPID_PRIVATE_KEY
        ]
    ];

    $webPush = new \Minishlink\WebPush\WebPush($auth);
    $webPush->setDefaultOptions(['TTL' => 86400, 'urgency' => 'high']);

    $payload = json_encode([
        'title'      => $title,
        'body'       => $body,
        'type'       => $type,
        'url'        => $url,
        'related_id' => $relatedId,
        'icon'       => '/ledor/AVAPAY.PNG',
        'badge'      => '/ledor/AVAPAY.PNG',
        'timestamp'  => time(),
        'vibrate'    => [200, 100, 200]
    ]);

    while ($sub = $result->fetch_assoc()) {
        try {
            $subscription = \Minishlink\WebPush\Subscription::create([
                'endpoint'  => $sub['endpoint'],
                'publicKey' => $sub['p256dh'],
                'authToken' => $sub['auth']
            ]);
            $webPush->queueNotification($subscription, $payload);
        } catch (Exception $e) {
            error_log("Push queue error: " . $e->getMessage());
        }
    }

    foreach ($webPush->flush() as $report) {
        if ($report->isSubscriptionExpired()) {
            $endpoint = $report->getRequest()->getUri()->__toString();
            $delStmt = $conn->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?");
            $delStmt->bind_param("s", $endpoint);
            $delStmt->execute();
        }
    }

    return true;
}

// تابع ارسال نوتیفیکیشن دیتابیس
function sendDatabaseNotification($conn, $userId, $type, $title, $message, $relatedId = null) {
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

    $checkColumn = $conn->query("SHOW COLUMNS FROM user_notifications LIKE 'type'");
    if ($checkColumn && $checkColumn->num_rows > 0) {
        $colInfo = $checkColumn->fetch_assoc();
        if (strpos($colInfo['Type'], 'enum') !== false) {
            $conn->query("ALTER TABLE user_notifications MODIFY COLUMN type VARCHAR(50) NOT NULL");
        }
    }

    $stmt = $conn->prepare("INSERT INTO user_notifications (user_id, type, title, message, related_id) VALUES (?, ?, ?, ?, ?)");
    if (!$stmt) return false;
    $stmt->bind_param("isssi", $userId, $type, $title, $message, $relatedId);
    return $stmt->execute();
}

// ==================== ایجاد جداول اصلی ====================
// اصلاح سرعت: این بلوک (۲ CREATE TABLE + ۲ ALTER روی جدول پرترافیک users)
// قبلاً روی *هر* درخواست offer_api.php اجرا می‌شد — و چون این فایل هر ۱۰
// ثانیه توسط پولینگ سراسری (checkAradOffersGlobally در footer_menu.php)
// برای *همه‌ی* کاربران آنلاین صدا زده می‌شود، یعنی این ۴ کوئری DDL مدام و
// برای هیچ‌کاری اجرا می‌شدند. حالا حداکثر هر ۳۰ دقیقه یک‌بار اجرا می‌شود.
require_once __DIR__ . '/../includes/perf_helpers.php';
if (avapay_throttled('offer_api_schema_ensure', 1800)) {
    $conn->query("CREATE TABLE IF NOT EXISTS `ad_offers` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `ad_id` INT NOT NULL,
        `buyer_id` INT NOT NULL,
        `seller_id` INT NOT NULL,
        `requested_amount` DECIMAL(20,6) NOT NULL,
        `offered_price` DECIMAL(20,2) NOT NULL,
        `message` TEXT,
        `status` ENUM('pending', 'accepted', 'rejected') DEFAULT 'pending',
        `reject_reason` TEXT,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `responded_at` DATETIME,
        INDEX idx_ad_id (ad_id),
        INDEX idx_seller_id (seller_id),
        INDEX idx_status (status)
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS `ad_deals` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `offer_id` INT NOT NULL,
        `ad_id` INT NOT NULL,
        `buyer_id` INT NOT NULL,
        `seller_id` INT NOT NULL,
        `currency` VARCHAR(10) NOT NULL,
        `amount` DECIMAL(20,6) NOT NULL,
        `price_per_unit` DECIMAL(20,2) NOT NULL,
        `total_price` DECIMAL(20,2) NOT NULL,
        `deal_code` VARCHAR(50) UNIQUE NOT NULL,
        `status` ENUM('pending', 'completed') DEFAULT 'pending',
        `admin_completed` TINYINT DEFAULT 0,
        `completed_at` DATETIME,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_deal_code (deal_code)
    )");

    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS `email` VARCHAR(255) DEFAULT NULL");
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS `phone` VARCHAR(20) DEFAULT NULL");
}

// ==================== پیش‌نمایش کمیسیون (برای خلاصهٔ پیشنهاد) ====================
// خروجی، دقیقاً همان مقداری است که در پیام تلگرام هم استفاده می‌شود (قوانین پلکانی
// و تخفیف درصدیِ کاربرِ پیشنهاددهنده را لحاظ می‌کند). فرانت‌اند دیگر فرمول محلی
// نمی‌سازد؛ همین منبع واحد را نمایش می‌دهد.
if ($action === 'commission_preview') {
    // پشتیبانی از GET و POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
    } else {
        $input = $_GET;
    }

    $adId            = intval($input['ad_id'] ?? 0);
    $requestedAmount = floatval($input['requested_amount'] ?? 0);
    $offeredPrice    = floatval($input['offered_price'] ?? 0);

    if ($adId <= 0 || $requestedAmount <= 0) {
        echo json_encode(['success' => false, 'message' => 'اطلاعات نامعتبر']);
        exit();
    }

    // اطلاعات آگهی (نوع + ارز)
    $adStmt = $conn->prepare("SELECT id, user_id, type, currency FROM user_ads WHERE id = ?");
    $adStmt->bind_param("i", $adId);
    $adStmt->execute();
    $ad = $adStmt->get_result()->fetch_assoc();
    if (!$ad) {
        echo json_encode(['success' => false, 'message' => 'آگهی یافت نشد']);
        exit();
    }

    $currency = strtoupper($ad['currency'] ?? 'ALL');

    // کمیسیون بر اساس قوانین کاربرِ پیشنهاددهنده (همان تابع مورد استفاده در تلگرام)
    // مبلغ کل به تومان هم پاس داده می‌شود تا قوانین پلکانیِ «بر اساس تومان» درست تطبیق داده شوند.
    $com = oa_commission($conn, (float)$requestedAmount, (int)$userId, (string)$currency, (float)($requestedAmount * $offeredPrice));
    $finalCommission = (float)$com['final'];
    $baseCommission  = (float)$com['base'];
    $discountPercent = (float)($com['discount'] ?? 0);
    $ruleApplied     = isset($com['rule_applied']);
    $ruleUnit        = $ruleApplied ? ($com['rule_applied']['unit'] ?? 'currency') : 'currency';
    $ruleType        = $ruleApplied ? ($com['rule_applied']['rule_type'] ?? '') : '';

    // نقش پیشنهاددهنده = عکسِ نوع آگهی
    // آگهیِ فروش (sell) ⇒ پیشنهاددهنده «خریدار» ⇒ کمیسیون از او کسر می‌شود
    // آگهیِ خرید  (buy)  ⇒ پیشنهاددهنده «فروشنده» ⇒ کمیسیون به او اضافه می‌شود
    $offererIsSeller = ($ad['type'] === 'buy');
    $role = $offererIsSeller ? 'seller' : 'buyer';

    // واحد کمیسیون: اولویت با «کمیسیون تخفیف ثابت» (اگر تعریف شده باشد) —
    // چون مبلغش می‌تواند در ارزی کاملاً متفاوت از ارز معامله باشد، معادل
    // تومانی‌اش را خودِ oa_commission() از قبل محاسبه کرده (final_toman).
    if (!empty($com['fixed_override'])) {
        $commissionUnit = $com['fixed_currency'];
        $commissionInToman = (float)$com['final_toman'];
    } elseif ($ruleApplied && $ruleUnit === 'toman') {
        $commissionUnit = 'IRR';
        $commissionInToman = $finalCommission;              // خودِ مقدار، تومانی است
    } else {
        $commissionUnit = ($currency === 'IRR' || $currency === '') ? 'IRR' : $currency;
        // برای محاسبهٔ مبلغ نهاییِ تومانی، ارزشِ کمیسیون (به ارز) × قیمت هر واحد
        $commissionInToman = ($commissionUnit === 'IRR') ? $finalCommission : ($finalCommission * $offeredPrice);
    }

    $total = $requestedAmount * $offeredPrice;
    $netToman = $offererIsSeller ? ($total + $commissionInToman) : ($total - $commissionInToman);

    echo json_encode([
        'success'            => true,
        'role'               => $role,
        'offerer_is_seller'  => $offererIsSeller,
        'currency'           => $currency,
        'base_commission'    => round($baseCommission, 4),
        'final_commission'   => round($finalCommission, 4),
        'discount_percent'   => $discountPercent,
        'rule_applied'       => $ruleApplied,
        'rule_type'          => $ruleType,
        'commission_unit'    => $commissionUnit,
        'commission_toman'   => round($commissionInToman, 2),
        'total_toman'        => round($total, 2),
        'net_toman'          => round($netToman, 2),
    ]);
    exit();
}

// ==================== پیش‌نمایش کمیسیون/تخفیف یک پیشنهادِ ثبت‌شده (برای طرف دریافت‌کننده) ====================
// بر خلاف commission_preview (که همیشه برای کاربر جاری محاسبه می‌کند)، اینجا کمیسیون
// دقیقاً برای «پیشنهاددهنده‌ی همان پیشنهاد» محاسبه می‌شود؛ چون وقتی صاحب آگهی یک
// پیشنهاد دریافتی را می‌بیند، کمیسیون/تخفیفِ طرف مقابل (نه خودش) باید نشان داده شود.
// فقط دو طرفِ همان پیشنهاد (پیشنهاددهنده و صاحب آگهی) اجازه‌ی مشاهده دارند.
if ($action === 'offer_commission_info' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $offerId = intval($_GET['offer_id'] ?? 0);
    if ($offerId <= 0) {
        echo json_encode(['success' => false, 'message' => 'شناسه نامعتبر است']);
        exit();
    }

    $ofStmt = $conn->prepare("SELECT o.buyer_id, o.seller_id, o.requested_amount, o.offered_price, a.currency, a.type
                               FROM ad_offers o JOIN user_ads a ON o.ad_id = a.id
                               WHERE o.id = ?");
    $ofStmt->bind_param("i", $offerId);
    $ofStmt->execute();
    $offer = $ofStmt->get_result()->fetch_assoc();

    if (!$offer) {
        echo json_encode(['success' => false, 'message' => 'پیشنهاد یافت نشد']);
        exit();
    }
    if ((int)$offer['buyer_id'] !== (int)$userId && (int)$offer['seller_id'] !== (int)$userId) {
        echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']);
        exit();
    }

    $offererId = (int)$offer['buyer_id']; // پیشنهاددهنده همیشه buyer_id این جدول است
    $currency  = strtoupper($offer['currency'] ?? 'ALL');
    $amount    = (float)$offer['requested_amount'];
    $price     = (float)$offer['offered_price'];

    $com = oa_commission($conn, $amount, $offererId, $currency, (float)($amount * $price));
    $finalCommission = (float)$com['final'];
    $discountPercent = (float)($com['discount'] ?? 0);
    $ruleApplied = isset($com['rule_applied']);
    $ruleUnit    = $ruleApplied ? ($com['rule_applied']['unit'] ?? 'currency') : 'currency';

    if (!empty($com['fixed_override'])) {
        $commissionUnit = $com['fixed_currency'];
    } elseif ($ruleApplied && $ruleUnit === 'toman') {
        $commissionUnit = 'IRR';
    } else {
        $commissionUnit = ($currency === 'IRR' || $currency === '') ? 'IRR' : $currency;
    }

    echo json_encode([
        'success'          => true,
        'final_commission' => round($finalCommission, 4),
        'discount_percent' => $discountPercent,
        'rule_applied'     => $ruleApplied,
        'commission_unit'  => $commissionUnit,
    ]);
    exit();
}

// ==================== 1. ارسال پیشنهاد جدید ====================
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    avapay_csrf_require();
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        echo json_encode(['success' => false, 'message' => 'داده ارسال نشده است']);
        exit();
    }

    $adId            = intval($input['ad_id'] ?? 0);
    $requestedAmount = floatval($input['requested_amount'] ?? 0);
    $offeredPrice    = floatval($input['offered_price'] ?? 0);
    $message         = isset($input['message']) ? trim($input['message']) : '';

    if ($adId <= 0 || $requestedAmount <= 0 || $offeredPrice <= 0) {
        echo json_encode(['success' => false, 'message' => 'اطلاعات نامعتبر']);
        exit();
    }

    $adSql = "SELECT a.*, u.first_name as seller_first, u.last_name as seller_last, u.telegram_id as seller_telegram,
                     u.email as seller_email, u.phone as seller_phone
              FROM user_ads a
              JOIN users u ON a.user_id = u.id
              WHERE a.id = ? AND a.status = 'active'";
    $adStmt = $conn->prepare($adSql);
    $adStmt->bind_param("i", $adId);
    $adStmt->execute();
    $adResult = $adStmt->get_result();
    $ad = $adResult->fetch_assoc();

    if (!$ad) {
        echo json_encode(['success' => false, 'message' => 'آگهی یافت نشد']);
        exit();
    }

    if ($ad['user_id'] == $userId) {
        echo json_encode(['success' => false, 'message' => 'شما نمی‌توانید به آگهی خودتان پیشنهاد دهید']);
        exit();
    }

    $buyer       = getUserInfo($conn, $userId);
    $totalAmount = $requestedAmount * $offeredPrice;

    $sql  = "INSERT INTO ad_offers (ad_id, buyer_id, seller_id, requested_amount, offered_price, message) VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiidds", $adId, $userId, $ad['user_id'], $requestedAmount, $offeredPrice, $message);

    if ($stmt->execute()) {
        $offerId = $conn->insert_id;
        $conn->query("UPDATE user_ads SET offer_count = IFNULL(offer_count, 0) + 1 WHERE id = $adId");

        // (جدید) اگر ریشه‌ی این آگهی یک آگهیِ mainbot باشد، همین پیشنهاد را
        // بی‌صدا (بدون ادیتِ کانال) در جدولِ review ربات هم ثبت کن تا وقتی
        // بعداً قبول/رد شد، در فهرستِ «پیشنهادهای ارسال‌شده»ی پستِ کانال دیده شود
        require_once __DIR__ . '/../includes/mozayede_bridge.php';
        moz_syncOfferCreated($conn, $adId, $offeredPrice, $requestedAmount, $buyer['first_name'] ?? '', $buyer['last_name'] ?? '');

        $notifTitle   = 'پیشنهاد جدید دریافت شد 🎯';
        $notifMessage = "کاربر {$buyer['first_name']} {$buyer['last_name']} برای {$ad['currency']} به مبلغ " . number_format($totalAmount) . " تومان پیشنهاد داد";

        // ذخیره در دیتابیس
        sendDatabaseNotification($conn, $ad['user_id'], 'offer_received', $notifTitle, $notifMessage, $offerId);

        // ارسال PUSH NOTIFICATION به فروشنده (حتی اگر اپ بسته باشد)
        sendPushToUser(
            $conn,
            $ad['user_id'],
            $notifTitle,
            $notifMessage,
            'offer_received',
            '/ledor/arad.php?tab=offers&section=received',
            $offerId
        );

        // تلگرام به فروشنده (با احترام به تنظیم کاربر)
        if (!empty($ad['seller_telegram']) && offerTgAllowed($conn, $ad['user_id'])) {
            // کمیسیونِ اپ (همان فرمول arad.php) برای نمایش به کاربر
            $com = oa_commission($conn, (float)$requestedAmount, (int)$userId, (string)$ad['currency'], (float)$totalAmount);
            $commUnit = !empty($com['fixed_override'])
                ? $com['fixed_currency']
                : ((isset($com['rule_applied']) && ($com['rule_applied']['unit'] ?? '') === 'toman')
                    ? 'تومان'
                    : (($ad['currency'] === 'IRR') ? 'تومان' : $ad['currency']));

            $telegramMsg  = "🎯 <b>پیشنهاد جدید برای آگهی شما</b>\n";
            $telegramMsg .= "📲 دریافت‌شده از طریق اپلیکیشن <b>AVA PAY</b>\n\n";
            $telegramMsg .= "👤 پیشنهاد‌دهنده: {$buyer['first_name']} {$buyer['last_name']}\n";
            $telegramMsg .= "💱 ارز: {$ad['currency']}\n";
            $telegramMsg .= "📊 مقدار: " . number_format($requestedAmount) . "\n";
            $telegramMsg .= "💰 قیمت: " . number_format($offeredPrice) . " تومان\n";
            $telegramMsg .= "💎 مبلغ کل: " . number_format($totalAmount) . " تومان\n";
            $telegramMsg .= "🧾 کمیسیون: " . number_format($com['final']) . " {$commUnit}\n\n";
            if (!empty($message)) {
                $telegramMsg .= "📝 پیام: " . htmlspecialchars($message) . "\n\n";
            }
            $telegramMsg .= "می‌توانید همین‌جا پاسخ دهید یا وارد اپلیکیشن AVA PAY شوید.";

            // دکمه‌های پذیرش/رد که مستقیماً توسط ربات (update.php) پردازش می‌شوند
            $customKeyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '✅ پذیرش پیشنهاد', 'callback_data' => 'avapay accept ' . $offerId],
                        ['text' => '❌ رد پیشنهاد',    'callback_data' => 'avapay reject ' . $offerId],
                    ],
                    [
                        ['text' => '🚀 ورود به AVA PAY', 'url' => 'https://aradexchange.com/ledor/arad.php']
                    ]
                ]
            ];
            sendTelegram($ad['seller_telegram'], $telegramMsg, $customKeyboard);
        }

        // ایمیل به فروشنده (در صورت فعال بودن)
        offerEmail($conn, $ad['user_id'], $notifTitle, $notifMessage);

        echo json_encode([
            'success'  => true,
            'message'  => '✅ پیشنهاد شما با موفقیت ارسال شد',
            'offer_id' => $offerId
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'خطا در ثبت پیشنهاد: ' . $conn->error]);
    }
    exit();
}

// ==================== 2. دریافت پیشنهادات آگهی ====================
if ($action === 'get_offers' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $adId = intval($_GET['ad_id'] ?? 0);

    $checkStmt = $conn->prepare("SELECT user_id FROM user_ads WHERE id = ?");
    $checkStmt->bind_param("i", $adId);
    $checkStmt->execute();
    $ad = $checkStmt->get_result()->fetch_assoc();

    if (!$ad || $ad['user_id'] != $userId) {
        echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']);
        exit();
    }

    $sql  = "SELECT o.*, u.first_name, u.last_name, u.telegram_id, u.avatar
             FROM ad_offers o
             JOIN users u ON o.buyer_id = u.id
             WHERE o.ad_id = ?
             ORDER BY FIELD(o.status, 'pending', 'accepted', 'rejected'), o.created_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $adId);
    $stmt->execute();
    $result = $stmt->get_result();

    $offers = [];
    while ($row = $result->fetch_assoc()) {
        $row['total_amount'] = $row['requested_amount'] * $row['offered_price'];
        $offers[] = $row;
    }

    echo json_encode(['success' => true, 'offers' => $offers]);
    exit();
}

// ==================== 3. دریافت پیشنهادات دریافت شده ====================
if ($action === 'get_my_received_offers' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;

    $sql  = "SELECT o.*, u.first_name as buyer_first_name, u.last_name as buyer_last_name,
                    u.telegram_id as buyer_telegram_id, u.avatar as buyer_avatar, a.currency
             FROM ad_offers o
             JOIN user_ads a ON o.ad_id = a.id
             JOIN users u ON o.buyer_id = u.id
             WHERE a.user_id = ?
             ORDER BY FIELD(o.status, 'pending', 'accepted', 'rejected'), o.created_at DESC
             LIMIT ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $userId, $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    $offers = [];
    while ($row = $result->fetch_assoc()) {
        $row['total_amount'] = $row['requested_amount'] * $row['offered_price'];
        $offers[] = $row;
    }

    echo json_encode(['success' => true, 'offers' => $offers]);
    exit();
}

// ==================== 4. پذیرش پیشنهاد ====================
if ($action === 'accept' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    avapay_csrf_require();
    $input   = json_decode(file_get_contents('php://input'), true);
    $offerId = intval($input['offer_id'] ?? 0);

    // منطق مشترک با ربات تلگرام (includes/offer_actions.php)
    $result = avapay_offer_accept($conn, $offerId, $userId);
    unset($result['offer']); // اطلاعات داخلی را به کلاینت برنگردان
    echo json_encode($result);
    exit();
}

// ==================== 5. رد پیشنهاد ====================
if ($action === 'reject' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    avapay_csrf_require();
    $input   = json_decode(file_get_contents('php://input'), true);
    $offerId = intval($input['offer_id'] ?? 0);
    $reason  = trim($input['reason'] ?? '');

    if (empty($reason)) {
        echo json_encode(['success' => false, 'message' => 'لطفاً دلیل رد را وارد کنید']);
        exit();
    }

    // منطق مشترک با ربات تلگرام (includes/offer_actions.php)
    $result = avapay_offer_reject($conn, $offerId, $userId, $reason);
    unset($result['offer']);
    echo json_encode($result);
    exit();
}

// ==================== 6. دریافت پیشنهادات ارسال شده (با قابلیت limit) ====================
if ($action === 'get_my_sent_offers' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;

    $sql  = "SELECT o.*, u_seller.first_name as seller_first_name, u_seller.last_name as seller_last_name,
                    u_seller.telegram_id as seller_telegram_id, a.currency
             FROM ad_offers o
             JOIN user_ads a ON o.ad_id = a.id
             JOIN users u_seller ON a.user_id = u_seller.id
             WHERE o.buyer_id = ?
             ORDER BY FIELD(o.status, 'pending', 'accepted', 'rejected'), o.created_at DESC
             LIMIT ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $userId, $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    $offers = [];
    while ($row = $result->fetch_assoc()) {
        $row['total_amount'] = $row['requested_amount'] * $row['offered_price'];
        $offers[] = $row;
    }

    echo json_encode(['success' => true, 'offers' => $offers]);
    exit();
}

// اگر هیچ اکشنی match نشد
echo json_encode(['success' => false, 'message' => 'اکشن نامعتبر: ' . $action]);
?>