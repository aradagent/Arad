<?php
require_once __DIR__ . '/includes/camera_headers.php';
// arad.php - صفحه اصلی پلتفرم آراد با معاملات فعال و طراحی مدرن

require_once __DIR__ . '/includes/session_boot.php';
require_once 'config/database.php';
require_once 'includes/logo_helper.php';
require_once __DIR__ . '/includes/referral_system.php';
$appLogo = getAppLogo();
error_reporting(E_ALL);
ini_set('display_errors', 0); // در تولید هرگز خطا مستقیم در مرورگر نمایش داده نشود
ini_set('log_errors', 1);

// بازسازی سشن از روی توکن ۳۰ روزه
avapay_restore_session_from_token($conn);

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$userId = (int)$_SESSION['user_id'];  // FIX: تبدیل به عدد برای تشخیص درست طرف معامله

// ضربان‌ساز ارسال ساعتی آگهی‌ها به کانال (بدون نیاز به کرون)
@include __DIR__ . '/includes/ads_broadcast_heartbeat.php';

// Get user data
$sql = "SELECT * FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    session_destroy();
    header('Location: login.php');
    exit();
}

$user = $result->fetch_assoc();

// حذف خودکار پیشنهادات قدیمی (بیش از 7 روز)
// FIX: ابتدا معاملات تکمیل‌شده‌ی قدیمی (ad_deals) که به این پیشنهادات وابسته‌اند حذف می‌شوند
// و سپس فقط پیشنهاداتی که دیگر هیچ معامله‌ای به آن‌ها ارجاع نمی‌دهد حذف می‌شوند تا خطای
// Foreign key constraint (ad_deals_ibfk_1) رخ ندهد.
try {
    $oldOfferIds = [];
    $oldOffersRes = $conn->query("SELECT id FROM ad_offers WHERE status IN ('accepted', 'rejected') AND responded_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
    if ($oldOffersRes) {
        while ($row = $oldOffersRes->fetch_assoc()) {
            $oldOfferIds[] = (int)$row['id'];
        }
    }

    if (!empty($oldOfferIds)) {
        $idsList = implode(',', $oldOfferIds);

        // معاملات تکمیل‌شده‌ی قدیمی مرتبط با این پیشنهادات، حذف می‌شوند
        $conn->query("DELETE FROM ad_deals WHERE offer_id IN ($idsList) AND status = 'completed' AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");

        // فقط پیشنهاداتی که دیگر در جدول ad_deals ارجاعی ندارند حذف می‌شوند
        $conn->query("DELETE FROM ad_offers WHERE id IN ($idsList) AND id NOT IN (SELECT DISTINCT offer_id FROM ad_deals WHERE offer_id IS NOT NULL)");
    }

    // سایر معاملات تکمیل‌شده‌ی قدیمی (بدون وابستگی به حذف بالا) نیز پاکسازی می‌شوند
    $conn->query("DELETE FROM ad_deals WHERE status = 'completed' AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
} catch (\Throwable $__cleanupErr) {
    // پاکسازی خودکار نباید هرگز باعث از کار افتادن کل صفحه شود
    error_log('arad.php auto-cleanup error: ' . $__cleanupErr->getMessage());
}

// Fix avatar path
if (empty($user['avatar']) || !file_exists($user['avatar'])) {
    $user['avatar'] = '/ledor/default-avatar.png';
}

// دریافت آمار کلی
$totalStats = $conn->query("SELECT COALESCE(SUM(total_price), 0) as total_volume FROM ad_deals WHERE status = 'completed'")->fetch_assoc();
$activeAdsCount = $conn->query("SELECT COUNT(*) as count FROM user_ads WHERE status = 'active'")->fetch_assoc()['count'];

// دریافت موجودی کاربر از دیتابیس
$balanceFields = ['balance_irr', 'balance_usd', 'balance_eur', 'balance_usdt'];
foreach ($balanceFields as $field) {
    $checkColumn = $conn->query("SHOW COLUMNS FROM users LIKE '$field'");
    if ($checkColumn->num_rows == 0) {
        $conn->query("ALTER TABLE users ADD COLUMN $field DECIMAL(20,2) DEFAULT 0");
    }
}

// تعداد پیشنهادات در انتظار
$pendingReceivedCount = $conn->query("SELECT COUNT(*) as count FROM ad_offers o JOIN user_ads a ON o.ad_id = a.id WHERE a.user_id = $userId AND o.status = 'pending'")->fetch_assoc()['count'];
$pendingSentCount = $conn->query("SELECT COUNT(*) as count FROM ad_offers o WHERE o.buyer_id = $userId AND o.status = 'pending'")->fetch_assoc()['count'];

// دریافت آخرین 5 پیشنهاد دریافت شده (جدیدترین اول)
$lastReceivedOffers = [];
$receivedSql = "SELECT o.*, u.first_name as buyer_first_name, u.last_name as buyer_last_name, 
                       u.telegram_id as buyer_telegram_id, u.avatar as buyer_avatar, a.currency
                FROM ad_offers o
                JOIN user_ads a ON o.ad_id = a.id
                JOIN users u ON o.buyer_id = u.id
                WHERE a.user_id = $userId AND o.status = 'pending'
                ORDER BY o.created_at DESC
                LIMIT 5";
$receivedResult = $conn->query($receivedSql);
if ($receivedResult) {
    while ($row = $receivedResult->fetch_assoc()) {
        $row['total_amount'] = $row['requested_amount'] * $row['offered_price'];
        $lastReceivedOffers[] = $row;
    }
}

// دریافت آخرین 5 پیشنهاد ارسال شده (جدیدترین اول)
$lastSentOffers = [];
$sentSql = "SELECT o.*, u_seller.first_name as seller_first_name, u_seller.last_name as seller_last_name,
                   u_seller.telegram_id as seller_telegram_id, a.currency
            FROM ad_offers o
            JOIN user_ads a ON o.ad_id = a.id
            JOIN users u_seller ON a.user_id = u_seller.id
            WHERE o.buyer_id = $userId
            ORDER BY o.created_at DESC
            LIMIT 5";
$sentResult = $conn->query($sentSql);
if ($sentResult) {
    while ($row = $sentResult->fetch_assoc()) {
        $row['total_amount'] = $row['requested_amount'] * $row['offered_price'];
        $lastSentOffers[] = $row;
    }
}

// نگاشت شناسه‌ی کاربر → تعداد معاملات تکمیل‌شده (برای نمایش «تعداد معامله» کنار آواتار در کارت پیشنهادها)
// و نگاشت شناسه‌ی آگهی → شناسه‌ی مالک آگهی (برای پیشنهادهای ارسالی که ممکن است seller_id مستقیم نداشته باشند)
$tradeCountMap = [];
$adOwnerMap = [];
try {
    $relevantUserIds = [];

    $buyerIdsRes = $conn->query("SELECT DISTINCT o.buyer_id FROM ad_offers o JOIN user_ads a ON o.ad_id = a.id WHERE a.user_id = $userId ORDER BY o.created_at DESC LIMIT 20");
    if ($buyerIdsRes) {
        while ($r = $buyerIdsRes->fetch_assoc()) { $relevantUserIds[] = (int)$r['buyer_id']; }
    }

    $sentOwnersRes = $conn->query("SELECT DISTINCT o.ad_id, a.user_id as owner_id FROM ad_offers o JOIN user_ads a ON o.ad_id = a.id WHERE o.buyer_id = $userId ORDER BY o.created_at DESC LIMIT 20");
    if ($sentOwnersRes) {
        while ($r = $sentOwnersRes->fetch_assoc()) {
            $adOwnerMap[(int)$r['ad_id']] = (int)$r['owner_id'];
            $relevantUserIds[] = (int)$r['owner_id'];
        }
    }

    $relevantUserIds = array_values(array_unique(array_filter($relevantUserIds)));
    if (!empty($relevantUserIds)) {
        $idsList = implode(',', $relevantUserIds);
        $tcRes = $conn->query("SELECT uid, COUNT(*) as c FROM (
                SELECT buyer_id as uid FROM ad_deals WHERE status = 'completed' AND buyer_id IN ($idsList)
                UNION ALL
                SELECT seller_id as uid FROM ad_deals WHERE status = 'completed' AND seller_id IN ($idsList)
            ) t GROUP BY uid");
        if ($tcRes) {
            while ($r = $tcRes->fetch_assoc()) { $tradeCountMap[(int)$r['uid']] = (int)$r['c']; }
        }
    }
} catch (\Throwable $__tcErr) {
    error_log('arad.php trade-count map error: ' . $__tcErr->getMessage());
}

// توکن CSRF یکتا برای کل سشن؛ در includes/session_boot.php ساخته می‌شود
$csrf_token = $_SESSION['csrf_token_arad'] ?? '';

// ==================== سیستم تخفیف کمیسیون ====================
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

// ایجاد جدول تخفیف‌ها
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

// دریافت تخفیف کاربر جاری
$userDiscount = 0;
$discountInfo = null;
$discountSql = "SELECT discount_percent, description, expires_at FROM user_discounts WHERE user_id = ? AND (expires_at IS NULL OR expires_at > NOW())";
$discountStmt = $conn->prepare($discountSql);
$discountStmt->bind_param("i", $userId);
$discountStmt->execute();
$discountResult = $discountStmt->get_result();
if ($discountResult->num_rows > 0) {
    $discountInfo = $discountResult->fetch_assoc();
    $userDiscount = floatval($discountInfo['discount_percent']);
}

// دریافت قوانین کمیسیون پلکانی کاربر جاری (برای نمایش در کارت تخفیف/کمیسیون تبادل ارزی)
$userCommissionRules = [];
$chkRulesTbl = $conn->query("SHOW TABLES LIKE 'user_commission_rules'");
if ($chkRulesTbl && $chkRulesTbl->num_rows > 0) {
    $ucrStmt = $conn->prepare("SELECT currency, unit, min_amount, max_amount, rule_type, value FROM user_commission_rules WHERE user_id = ? ORDER BY unit, currency, min_amount");
    $ucrStmt->bind_param("i", $userId);
    $ucrStmt->execute();
    $ucrRes = $ucrStmt->get_result();
    while ($r = $ucrRes->fetch_assoc()) $userCommissionRules[] = $r;
}

// کاربران دارای تخفیف برای ادمین
$usersWithDiscount = [];
if ($isAdmin) {
    $discountListSql = "SELECT d.*, u.first_name, u.last_name, u.email, u.telegram_id 
                        FROM user_discounts d
                        JOIN users u ON d.user_id = u.id
                        WHERE d.expires_at IS NULL OR d.expires_at > NOW()
                        ORDER BY d.created_at DESC";
    $discountListResult = $conn->query($discountListSql);
    while ($row = $discountListResult->fetch_assoc()) {
        $usersWithDiscount[] = $row;
    }
}

// ==================== معاملات کاربر ====================
$userDealsStats = $conn->query("SELECT COUNT(*) as total_deals FROM ad_deals WHERE (buyer_id = $userId OR seller_id = $userId) AND status = 'completed'")->fetch_assoc();
$userTotalDeals = $userDealsStats['total_deals'];

// معاملات فعال کاربر
// اطمینان از وجود ستون‌های تسویه‌ی جداگانه (خوددرمان برای دیتابیس‌های قدیمی)
$__dealCols = [
    'buyer_admin_accounts'       => "ALTER TABLE ad_deals ADD COLUMN buyer_admin_accounts TEXT NULL",
    'seller_admin_accounts'      => "ALTER TABLE ad_deals ADD COLUMN seller_admin_accounts TEXT NULL",
    'buyer_receipts'             => "ALTER TABLE ad_deals ADD COLUMN buyer_receipts TEXT NULL",
    'seller_receipts'            => "ALTER TABLE ad_deals ADD COLUMN seller_receipts TEXT NULL",
    'buyer_settlement_receipts'  => "ALTER TABLE ad_deals ADD COLUMN buyer_settlement_receipts TEXT NULL",
    'seller_settlement_receipts' => "ALTER TABLE ad_deals ADD COLUMN seller_settlement_receipts TEXT NULL",
    'buyer_admin_note'           => "ALTER TABLE ad_deals ADD COLUMN buyer_admin_note TEXT NULL",
    'seller_admin_note'          => "ALTER TABLE ad_deals ADD COLUMN seller_admin_note TEXT NULL",
    'buyer_side_status'          => "ALTER TABLE ad_deals ADD COLUMN buyer_side_status VARCHAR(30) DEFAULT 'new'",
    'seller_side_status'         => "ALTER TABLE ad_deals ADD COLUMN seller_side_status VARCHAR(30) DEFAULT 'new'",
    // FIX: این ستون‌ها در کوئری معاملات فعال استفاده می‌شوند و نبودشان کل صفحه را می‌شکست
    'admin_completed'            => "ALTER TABLE ad_deals ADD COLUMN admin_completed TINYINT(1) DEFAULT 0",
    'completed_at'               => "ALTER TABLE ad_deals ADD COLUMN completed_at DATETIME DEFAULT NULL",
    'completed_by'               => "ALTER TABLE ad_deals ADD COLUMN completed_by INT DEFAULT NULL",
];
foreach ($__dealCols as $__c => $__ddl) {
    $__chk = $conn->query("SHOW COLUMNS FROM ad_deals LIKE '$__c'");
    if ($__chk && $__chk->num_rows === 0) { @$conn->query($__ddl); }
}

$activeDealsSql = "SELECT d.*, a.currency, a.type as ad_type,
                   buyer.first_name as buyer_first_name, buyer.last_name as buyer_last_name,
                   seller.first_name as seller_first_name, seller.last_name as seller_last_name
                   FROM ad_deals d
                   JOIN user_ads a ON d.ad_id = a.id
                   JOIN users buyer ON d.buyer_id = buyer.id
                   JOIN users seller ON d.seller_id = seller.id
                   WHERE (d.buyer_id = ? OR d.seller_id = ?) AND d.status = 'pending' AND d.admin_completed = 0
                   ORDER BY d.created_at DESC";
$activeDealsStmt = $conn->prepare($activeDealsSql);
$activeDealsStmt->bind_param("ii", $userId, $userId);
$activeDealsStmt->execute();
$activeDeals = $activeDealsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ==================== خلاصه‌ی «آگهی‌های من» برای کارت فشرده‌ی بازار ====================
$myAdsCountsSql = "SELECT
        SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) as c_active,
        SUM(CASE WHEN status='active' AND (SELECT COUNT(*) FROM ad_offers WHERE ad_id=a.id AND status='pending')>0 THEN 1 ELSE 0 END) as c_pending,
        SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END) as c_expired
    FROM user_ads a WHERE a.user_id = ?";
$myAdsCountsStmt = $conn->prepare($myAdsCountsSql);
$myAdsCountsStmt->bind_param("i", $userId);
$myAdsCountsStmt->execute();
$myAdsCounts = $myAdsCountsStmt->get_result()->fetch_assoc() ?: ['c_active'=>0,'c_pending'=>0,'c_expired'=>0];

$myLatestAdSql = "SELECT * FROM user_ads WHERE user_id = ? ORDER BY created_at DESC LIMIT 1";
$myLatestAdStmt = $conn->prepare($myLatestAdSql);
$myLatestAdStmt->bind_param("i", $userId);
$myLatestAdStmt->execute();
$myLatestAd = $myLatestAdStmt->get_result()->fetch_assoc();

// معاملات تکمیل شده کاربر
$completedDealsSql = "SELECT d.*, a.currency,
                      buyer.first_name as buyer_first_name, buyer.last_name as buyer_last_name,
                      seller.first_name as seller_first_name, seller.last_name as seller_last_name
                      FROM ad_deals d
                      JOIN user_ads a ON d.ad_id = a.id
                      JOIN users buyer ON d.buyer_id = buyer.id
                      JOIN users seller ON d.seller_id = seller.id
                      WHERE (d.buyer_id = ? OR d.seller_id = ?) AND d.status = 'completed'
                      AND COALESCE(d.completed_at, d.created_at) > DATE_SUB(NOW(), INTERVAL 90 DAY)
                      ORDER BY COALESCE(d.completed_at, d.created_at) DESC LIMIT 20";
$completedDealsStmt = $conn->prepare($completedDealsSql);
$completedDealsStmt->bind_param("ii", $userId, $userId);
$completedDealsStmt->execute();
$completedDeals = $completedDealsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ==================== نرخ‌های فیات برای کارت‌های نرخ لحظه‌ای (EUR/IRR ، USD/IRR) ====================
$avaFiatRates = [];
$avaFiatRes = $conn->query("SELECT currency, price, change_24h FROM currency_rates WHERE currency IN ('EUR','USD','USDT','TETHER')");
if ($avaFiatRes) { while ($fr = $avaFiatRes->fetch_assoc()) { $avaFiatRates[$fr['currency']] = $fr; } }

// ===== آماره‌های تبادل ارزی برای نمودار زنده =====
// نکته: $completedDeals فقط ۲۰ ردیف اخیر و ۹۰ روز است (برای نمایش تاریخچه).
// نمودار «۶ ماه اخیر» باید ۱۸۰ روز کامل و بدون سقف تعداد را ببیند،
// پس داده‌اش را جداگانه و مستقیماً از دیتابیس می‌گیریم.
$exMonthly    = array_fill(0, 6, 0);  // حجم ماهانه (همه‌ی ارزها)
$exByCur      = [];                   // حجم ماهانه به تفکیک ارز
$exAmtByCur   = [];                   // مقدار خودِ ارز در هر ماه
$exBuyByCur   = [];                   // مقدار خریداری‌شده در هر ماه
$exSellByCur  = [];                   // مقدار فروخته‌شده در هر ماه
$exAmtAll     = array_fill(0, 6, 0);  // مقدار کل (همه‌ی ارزها) برای نمای «همه»
$exCurTotals  = [];                   // مجموع حجم هر ارز
$exCurAmounts = [];                   // مجموع مقدار خودِ ارز (نه تومان)
$exCurCounts  = [];                   // تعداد معامله‌ی هر ارز
$exNow = time();

$exChartSql = "SELECT COALESCE(a.currency, d.currency) AS cur,
                      d.amount, d.total_price, d.buyer_id,
                      d.completed_at AS ts
               FROM ad_deals d
               LEFT JOIN user_ads a ON d.ad_id = a.id
               WHERE (d.buyer_id = ? OR d.seller_id = ?)
                 AND d.status = 'completed'
                 AND d.completed_at IS NOT NULL
                 AND d.completed_at > DATE_SUB(NOW(), INTERVAL 190 DAY)";
$exChartStmt = $conn->prepare($exChartSql);
$exNowY = (int)date('Y');
$exNowM = (int)date('n');
if ($exChartStmt) {
    $exChartStmt->bind_param("ii", $userId, $userId);
    $exChartStmt->execute();
    $exChartRows = $exChartStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($exChartRows as $__d) {
        if (empty($__d['ts'])) continue; // فقط روزی که معامله واقعاً اعمال (تکمیل) شده لحاظ می‌شود
        $ts = strtotime($__d['ts']);
        // سطل‌بندی بر اساس ماه شمسی/میلادیِ واقعیِ روز تکمیل (نه بازه‌ی غلتان ۳۰ روزه)
        $dm = ($exNowY - (int)date('Y', $ts)) * 12 + ($exNowM - (int)date('n', $ts));
        if ($dm < 0 || $dm >= 6) continue;

        $slot = 5 - $dm;
        $val  = (float)($__d['total_price'] ?? 0);
        $cur  = strtoupper(trim((string)($__d['cur'] ?? ''))) ?: '—';

        $exMonthly[$slot] += $val;

        if (!isset($exByCur[$cur])) {
            $exByCur[$cur]      = array_fill(0, 6, 0);
            $exAmtByCur[$cur]   = array_fill(0, 6, 0);
            $exBuyByCur[$cur]   = array_fill(0, 6, 0);
            $exSellByCur[$cur]  = array_fill(0, 6, 0);
            $exCurTotals[$cur]  = 0;
            $exCurAmounts[$cur] = 0;
            $exCurCounts[$cur]  = 0;
        }
        $amt = (float)($__d['amount'] ?? 0);
        $isBuy = ((int)($__d['buyer_id'] ?? 0) === $userId);   // کاربر خریدار بوده یا فروشنده
        $exByCur[$cur][$slot] += $val;
        $exAmtByCur[$cur][$slot] += $amt;
        if ($isBuy) { $exBuyByCur[$cur][$slot] += $amt; } else { $exSellByCur[$cur][$slot] += $amt; }
        $exAmtAll[$slot]      += $amt;
        $exCurTotals[$cur]    += $val;
        $exCurAmounts[$cur]   += $amt;
        $exCurCounts[$cur]    += 1;
    }
}

// ارزها بر اساس بیشترین حجم مرتب شوند
arsort($exCurTotals);
$exCurrencies = [];
foreach ($exCurTotals as $__c => $__v) {
    $exCurrencies[] = [
        'code'        => $__c,
        'volume'      => (float)$__v,
        'amount'      => (float)$exCurAmounts[$__c],
        'count'       => (int)$exCurCounts[$__c],
        'monthly'     => array_values($exByCur[$__c]),
        'monthlyAmt'  => array_values($exAmtByCur[$__c]),
        'monthlyBuy'  => array_values($exBuyByCur[$__c]),
        'monthlySell' => array_values($exSellByCur[$__c]),
    ];
}

// برچسبِ تاریخِ واقعیِ هر یک از ۶ ماه (اولین روز ماه، به ترتیب از قدیم به جدید) — برای نمایش تاریخ روی نمودار
$exMonthLabels = [];
for ($i = 5; $i >= 0; $i--) {
    $mm = $exNowM - $i;
    $yy = $exNowY;
    while ($mm <= 0) { $mm += 12; $yy -= 1; }
    $exMonthLabels[] = sprintf('%04d-%02d-01', $yy, $mm);
}

$exInsights = [
    'active'       => count($activeDeals),
    'completed'    => (int)$userTotalDeals,
    'offersIn'     => (int)$pendingReceivedCount,
    'offersOut'    => (int)$pendingSentCount,
    'totalVolume'  => (float)($totalStats['total_volume'] ?? 0),
    'activeAds'    => (int)$activeAdsCount,
    'monthly'      => array_values($exMonthly),
    'monthlyAmt'   => array_values($exAmtAll),
    'monthlyLabels'=> $exMonthLabels,
    'monthlyTotal' => (float)array_sum($exMonthly),
    'currencies'   => $exCurrencies,
];

// تابع فرمت اعداد فارسی
function formatPersianNumber($number) {
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    $formatted = number_format(abs($number), 0);
    $result = str_replace($english, $persian, $formatted);
    if ($number < 0) {
        return '− ' . $result;
    }
    return $result;
}


?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="dns-prefetch" href="https://cdnjs.cloudflare.com">
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="dns-prefetch" href="https://cdn.jsdelivr.net">
<script>(function(){try{var t=localStorage.getItem("ava_theme")||"dark";document.documentElement.setAttribute("data-theme",t);}catch(e){}})();</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token); ?>">
    <script>
    // اتصال خودکار توکن CSRF به هر درخواست POST/PUT/DELETE هم‌مبدأ —
    // بدون نیاز به تغییر تک‌تک فراخوانی‌های fetch در سراسر صفحه.
    (function(){
        var token = document.querySelector('meta[name="csrf-token"]').content;
        var origFetch = window.fetch;
        window.fetch = function(input, init){
            init = init || {};
            var method = (init.method || 'GET').toUpperCase();
            if (method !== 'GET' && method !== 'HEAD') {
                init.headers = Object.assign({}, init.headers || {}, { 'X-CSRF-Token': token });
            }
            return origFetch.call(this, input, init);
        };
    })();
    </script>
    <title>Arad Exchange | پلتفرم تبادل هوشمند</title>
    <link rel="preload" href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css"></noscript>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/style.css') ?: time(); ?>">
    <link rel="stylesheet" href="assets/css/aradphp.css">
    <link rel="stylesheet" href="/ledor/assets/css/notif.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/notif.css') ?: time(); ?>">
    <link rel="stylesheet" href="/ledor/assets/css/theme-light.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/theme-light.css') ?: time(); ?>">
    <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" defer></script>
    <link rel="stylesheet" href="assets/css/avapay-modern.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/avapay-modern.css') ?: time(); ?>">
    <script defer src="assets/js/avapay-modern.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/avapay-modern.js') ?: time(); ?>"></script>
    <script src="assets/js/upload-compress.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/upload-compress.js') ?: time(); ?>" defer></script>
    <link rel="stylesheet" href="assets/css/referral-box.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/referral-box.css') ?: time(); ?>">
    <link rel="manifest" href="manifest.php">
    <?php $__appIcon = ($appLogo ? htmlspecialchars($appLogo['url']) : '/ledor/AVAPAY.PNG?v=' . (@filemtime(__DIR__ . '/AVAPAY.PNG') ?: time())); ?>
    <link rel="icon" type="image/png" href="<?php echo $__appIcon; ?>">
    <link rel="apple-touch-icon" href="<?php echo $__appIcon; ?>">
    <meta name="theme-color" content="#1A0B2E">
    <style>
        /* پس‌زمینه‌ی ثابت مثل داشبورد (بدون انیمیشن رفت‌وبرگشتی گرادیانت) */
        html, body {
            background: var(--ava-bg, #0A0520) !important;
            background-image: none !important;
            background-size: auto !important;
            animation: none !important;
        }
        /* فضای کافی زیر محتوا تا منوی فوتر (نوار ناوبری ثابت پایین) هرگز روی
           آخرین کارت/ردیف صفحه ننشیند و پوشانده نشود */
        body {
            padding-bottom: calc(96px + env(safe-area-inset-bottom)) !important;
        }

        /* استایل دکمه‌های ناوبری بزرگ و جذاب */
        .nav-buttons-section {
            margin: 16px 0 20px 0;
        }
        
        .nav-buttons-large {
            display: flex;
            gap: 12px;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.08), rgba(108, 64, 197, 0.08));
            backdrop-filter: blur(20px);
            border-radius: 60px;
            padding: 6px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .nav-large-btn {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 12px 16px;
            background: transparent;
            border-radius: 50px;
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.3s ease;
        }
        
        .nav-large-btn.active {
            background: linear-gradient(135deg, #FFD700, #FFA500);
            color: #1a1a2e;
            box-shadow: 0 4px 15px rgba(255, 215, 0, 0.3);
        }
        
        .nav-large-btn:not(.active):hover {
            background: rgba(255, 255, 255, 0.12);
            color: white;
            transform: translateY(-2px);
        }
        
        @media (max-width: 600px) {
            .nav-large-btn {
                padding: 10px;
                font-size: 0.75rem;
                gap: 6px;
            }
            .nav-large-btn i {
                font-size: 0.9rem;
            }
        }
        
        /* استایل دکمه‌های نوتیفیکیشن در دراپ‌داون */
        .notification-actions {
            display: flex;
            gap: 8px;
            margin-top: 8px;
        }
        .action-btn {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .action-btn.accept {
            background: linear-gradient(135deg, #4CD964, #34C759);
            color: white;
        }
        .action-btn.reject {
            background: linear-gradient(135deg, #FF3B30, #FF9500);
            color: white;
        }
        .action-btn.view_deal, .action-btn.view_completed {
            background: linear-gradient(135deg, #FFD700, #FFA500);
            color: #1a1a2e;
        }
        .action-btn:hover {
            transform: translateY(-1px);
            opacity: 0.9;
        }
        .notification-item.removing {
            animation: fadeOut 0.2s ease forwards;
        }
        @keyframes fadeOut {
            to { opacity: 0; transform: translateX(-10px); }
        }
        
        @media (max-width: 768px) {
            .nav-buttons-large {
                gap: 12px;
            }
            .nav-large-btn {
                min-width: 140px;
                padding: 12px 20px;
                font-size: 0.9rem;
            }
            .nav-large-btn i {
                font-size: 1.1rem;
            }
        }
        
        @media (max-width: 480px) {
            .nav-large-btn {
                min-width: 120px;
                padding: 10px 16px;
                font-size: 0.8rem;
            }
        }
    </style>
</head>
<body>

<!-- هدر حرفه‌ای AvaPay -->
<div class="avapay-header">
    <div class="header-container">
        <div class="logo-section">
            <div class="logo-icon">
                <?php if ($appLogo): ?>
                <img data-app-logo src="<?php echo htmlspecialchars($appLogo['url']); ?>" alt="Logo" style="width:28px;height:28px;object-fit:contain;border-radius:6px;">
                <?php else: ?>
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 2L2 7L12 12L22 7L12 2Z" stroke="url(#grad1)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M2 17L12 22L22 17" stroke="url(#grad2)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M2 12L12 17L22 12" stroke="url(#grad1)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <defs>
                        <linearGradient id="grad1" x1="0%" y1="0%" x2="100%" y2="0%">
                            <stop offset="0%" style="stop-color:#FFD700"/>
                            <stop offset="100%" style="stop-color:#FFA500"/>
                        </linearGradient>
                        <linearGradient id="grad2" x1="0%" y1="0%" x2="100%" y2="0%">
                            <stop offset="0%" style="stop-color:#6C40C5"/>
                            <stop offset="100%" style="stop-color:#FF4D8D"/>
                        </linearGradient>
                    </defs>
                </svg>
                <?php endif; ?>
            </div>
            <div class="logo-text">
                <h1>AvaPay</h1>
                <span>سیستم تبادل ارزی هوشمند</span>
            </div>
        </div>
        
        <div class="header-actions">
            <div class="notification-bell" id="notificationBell">
                <div class="bell-icon">
                    <i class="fas fa-bell"></i>
                    <span class="bell-dot" id="notificationDot" style="display: none;"></span>
                    <span class="notification-badge" id="notificationBadge" style="display: none;">0</span>
                </div>
            </div>
            <button type="button" class="home-btn" style="border:none;cursor:pointer;position:relative;" onclick="axOpenSupport()" title="پشتیبانی">
                <i class="fas fa-headset"></i>
                <span class="bell-dot" id="axSupportDot" style="display:none;"></span>
                <span class="notification-badge" id="axSupportBadge" style="display:none;">0</span>
            </button>
            <a href="dashboard.php" class="home-btn">
                <i class="fas fa-home"></i>
            </a>
        </div>
    </div>
</div>

<!-- دراپ‌داون نوتیفیکیشن - نسخه نهایی -->
<!-- دراپ‌داون نوتیفیکیشن - نسخه نهایی -->
<div class="notification-dropdown" id="notificationDropdown">
    <div class="dropdown-header">
        <h3 class="dropdown-title">
            <i class="fas fa-bell"></i>
            اعلان‌ها
        </h3>
        <div class="header-actions">
            <button class="mark-all-read-btn" id="markAllReadBtn">
                <i class="fas fa-check-double"></i>
                <span>خواندن همه</span>
            </button>
            <button class="close-dropdown-btn" id="closeDropdownBtn">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>
    <div class="dropdown-body" id="notificationList">
        <div class="loading-state">
            <div class="loading-spinner"></div>
            <p>در حال بارگذاری...</p>
        </div>
    </div>
</div>
<div class="notification-overlay" id="notificationOverlay"></div>


<div class="main-container">
    <?php /* کارت هیجانی تخفیف — کوچک و متعادل، برای همه نمایش داده می‌شود (چه تخفیف داشته باشند چه نداشته باشند) */ ?>
        <?php if ($userDiscount > 0): ?>
        <div class="ax-disc-hero" id="axDiscCard">
            <div class="ax-disc-hero-bg"></div>
            <div class="ax-disc-hero-shine"></div>
            <span class="ax-disc-hero-badge"><i class="fas fa-crown"></i> تخفیف اختصاصی شما فعال است</span>
            <div class="ax-disc-hero-main">
                <div class="ax-disc-hero-gauge">
                    <canvas id="axDiscGauge" width="76" height="76"></canvas>
                    <div class="ax-disc-hero-pct"><?php echo $userDiscount; ?><span>٪</span></div>
                </div>
                <div class="ax-disc-hero-info">
                    <div class="ax-disc-hero-title"><i class="fas fa-fire"></i> تخفیف ویژه‌ی کمیسیون معاملات</div>
                    <?php if (!empty($discountInfo['description'])): ?>
                    <div class="ax-disc-hero-desc"><i class="fas fa-comment"></i> <?php echo htmlspecialchars($discountInfo['description']); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (!empty($discountInfo['expires_at'])): ?>
            <div class="ax-disc-hero-countdown" id="axDiscCountdown" data-expires="<?php echo date('c', strtotime($discountInfo['expires_at'])); ?>">
                <div class="ax-disc-hero-cd-label"><i class="fas fa-bolt"></i> این فرصت به‌زودی به پایان می‌رسد!</div>
                <div class="ax-disc-hero-cd-boxes">
                    <div class="ax-disc-hero-cd-box"><b id="axDiscCdD">۰۰</b><span>روز</span></div>
                    <span class="ax-disc-hero-cd-sep">:</span>
                    <div class="ax-disc-hero-cd-box"><b id="axDiscCdH">۰۰</b><span>ساعت</span></div>
                    <span class="ax-disc-hero-cd-sep">:</span>
                    <div class="ax-disc-hero-cd-box"><b id="axDiscCdM">۰۰</b><span>دقیقه</span></div>
                    <span class="ax-disc-hero-cd-sep">:</span>
                    <div class="ax-disc-hero-cd-box"><b id="axDiscCdS">۰۰</b><span>ثانیه</span></div>
                </div>
            </div>
            <?php else: ?>
            <div class="ax-disc-hero-forever"><i class="fas fa-infinity"></i> بدون تاریخ انقضا — همیشه فعال</div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="ax-disc-hero ax-disc-hero-empty" id="axDiscCard">
            <div class="ax-disc-hero-bg"></div>
            <div class="ax-disc-hero-empty-row">
                <span class="ax-disc-hero-empty-ic"><i class="fas fa-gift"></i></span>
                <div class="ax-disc-hero-empty-txt">
                    <b>هنوز تخفیفی برای شما فعال نشده</b>
                    <span>با معاملات بیشتر، شانس دریافت تخفیف اختصاصی کمیسیون رو داری!</span>
                </div>
            </div>
        </div>
        <?php endif; ?>

    <?php
        $__refStats = ava_ref_my_stats($conn, $userId);
        $__refSettings = ava_ref_settings($conn);
    ?>
    <!-- ==================== بنر کوچک دعوت دوستان ==================== -->
    <div class="arf-banner" id="arfBanner">
        <div class="arf-banner-top" onclick="arfOpenPanel()">
            <div class="arf-banner-ic"><i class="fas fa-user-plus"></i></div>
            <div class="arf-banner-txt">
                <div class="arf-banner-title">دوستاتو دعوت کن و کسب درآمد کن</div>
                <div class="arf-banner-sub"><?php echo htmlspecialchars($__refStats['link']); ?></div>
            </div>
            <button type="button" class="arf-banner-btn" onclick="event.stopPropagation(); arfOpenPanel();">
                <i class="fas fa-coins"></i> درآمد شما
            </button>
        </div>
        <button type="button" class="arf-banner-copy" id="arfBannerCopyBtn" onclick="arfCopyBannerLink(event)">
            <i class="fas fa-copy"></i> کپی لینک دعوت
        </button>
        <input type="hidden" id="arfBannerLinkVal" value="<?php echo htmlspecialchars($__refStats['link']); ?>">
    </div>

    <!-- ==================== زیرمجموعه‌های شما (پنل کامل، به‌صورت مودال باز می‌شود) ==================== -->
    <div class="arf-modal-overlay" id="arfPanelOverlay">
        <div class="arf-card" id="arfCard">
            <button type="button" class="arf-card-close" onclick="arfClosePanel()"><i class="fas fa-times"></i></button>
            <div class="arf-glow"></div>

            <div class="arf-head">
                <div class="arf-head-txt">
                    <div class="arf-head-title">زیرمجموعه‌های شما</div>
                    <div class="arf-head-sub">با دعوت دوستان، بیشتر درآمد کسب کنید</div>
                </div>
                <div class="arf-head-ic"><i class="fas fa-user-friends"></i></div>
            </div>

            <div class="arf-tabs">
                <button type="button" class="arf-tab active" data-arf-tab="users" onclick="arfSwitchTab('users')">
                    <i class="fas fa-users"></i> کاربران
                </button>
                <button type="button" class="arf-tab" data-arf-tab="invite" onclick="arfSwitchTab('invite')">
                    <i class="fas fa-link"></i> دعوت
                </button>
                <button type="button" class="arf-tab" data-arf-tab="stats" onclick="arfSwitchTab('stats')">
                    <i class="fas fa-chart-simple"></i> آمار
                </button>
            </div>

            <!-- ---------- تب کاربران ---------- -->
            <div class="arf-pane active" id="arfPaneUsers">
                <div class="arf-list-head">
                    <span>کاربران دعوت‌شده</span>
                    <span class="arf-count-badge" id="arfCountBadge"><?php echo (int)$__refStats['total_referred']; ?> نفر</span>
                </div>
                <div class="arf-list" id="arfList">
                    <div class="arf-empty">در حال بارگذاری...</div>
                </div>
            </div>

            <!-- ---------- تب دعوت ---------- -->
            <div class="arf-pane" id="arfPaneInvite">
                <div class="arf-invite-hero">
                    <div class="arf-gift-ic"><i class="fas fa-gift"></i></div>
                    <div class="arf-invite-title">دوستان خود را دعوت کنید</div>
                    <div class="arf-invite-sub">با هر دعوت موفق، درآمد و هدیه دریافت کنید</div>
                </div>

                <div class="arf-link-row">
                    <span class="arf-link-ic"><i class="fas fa-link"></i></span>
                    <input type="text" id="arfLinkInput" readonly value="<?php echo htmlspecialchars($__refStats['link']); ?>">
                    <button type="button" id="arfCopyBtn" onclick="arfCopyLink()"><i class="fas fa-copy"></i> کپی لینک</button>
                </div>

                <div class="arf-stats-grid arf-stats-grid-2">
                    <div class="arf-stat">
                        <div class="arf-stat-ic"><i class="fas fa-percentage"></i></div>
                        <div class="arf-stat-v"><?php echo number_format($__refSettings['commission_per_tx_eur'], 2); ?>€</div>
                        <div class="arf-stat-l">پورسانت هر تراکنش</div>
                    </div>
                    <div class="arf-stat">
                        <div class="arf-stat-ic"><i class="fas fa-gift"></i></div>
                        <div class="arf-stat-v"><?php echo number_format($__refSettings['welcome_bonus_eur'], 2); ?>€</div>
                        <div class="arf-stat-l">هدیه‌ی هر دعوت</div>
                    </div>
                </div>

                <div class="arf-howto">
                    <div class="arf-howto-head"><i class="fas fa-lightbulb"></i> نحوه کار:</div>
                    <div class="arf-howto-row"><span class="arf-howto-txt">لینک دعوت خود را برای دوستان بفرستید</span><span class="arf-howto-num">1</span></div>
                    <div class="arf-howto-row"><span class="arf-howto-txt">دوستان شما در آراد اکسچنج ثبت‌نام کنند</span><span class="arf-howto-num">2</span></div>
                    <div class="arf-howto-row"><span class="arf-howto-txt">با فعالیت آن‌ها، شما درآمد و هدیه دریافت می‌کنید</span><span class="arf-howto-num">3</span></div>
                </div>
            </div>

            <!-- ---------- تب آمار ---------- -->
            <div class="arf-pane" id="arfPaneStats">
                <div class="arf-stats-grid">
                    <div class="arf-stat">
                        <div class="arf-stat-ic"><i class="fas fa-coins"></i></div>
                        <div class="arf-stat-v" id="arfStatEarned"><?php echo number_format($__refStats['total_earned'], 2); ?>€</div>
                        <div class="arf-stat-l">کل درآمد شما</div>
                    </div>
                    <div class="arf-stat">
                        <div class="arf-stat-ic"><i class="fas fa-users"></i></div>
                        <div class="arf-stat-v" id="arfStatCount"><?php echo (int)$__refStats['total_referred']; ?></div>
                        <div class="arf-stat-l">نفر دعوت‌شده</div>
                    </div>
                    <div class="arf-stat">
                        <div class="arf-stat-ic"><i class="fas fa-percentage"></i></div>
                        <div class="arf-stat-v"><?php echo number_format($__refSettings['commission_per_tx_eur'], 2); ?>€</div>
                        <div class="arf-stat-l">پورسانت هر تراکنش</div>
                    </div>
                    <div class="arf-stat">
                        <div class="arf-stat-ic"><i class="fas fa-gift"></i></div>
                        <div class="arf-stat-v"><?php echo number_format($__refSettings['welcome_bonus_eur'], 2); ?>€</div>
                        <div class="arf-stat-l">هدیه‌ی هر دعوت</div>
                    </div>
                </div>

                <div class="arf-chart-card">
                    <div class="arf-chart-head">
                        <span class="arf-chart-title">درآمد این ماه</span>
                    </div>
                    <div class="arf-chart-val">
                        <span id="arfMonthValue">0.00€</span>
                        <span class="arf-chart-pct" id="arfMonthPct"><i class="fas fa-arrow-up"></i> 0%</span>
                    </div>
                    <div class="arf-chart-wrap">
                        <canvas id="arfChart" height="130"></canvas>
                    </div>
                </div>

                <button type="button" class="arf-settle-btn" onclick="arfOpenSettleModal()">
                    <i class="fas fa-wallet"></i> درخواست تسویه کیف‌پول
                </button>
            </div>
        </div>
    </div>

    <!-- مودال درخواست تسویه -->
    <div class="arf-modal-overlay" id="arfSettleModal">
        <div class="arf-modal">
            <div class="arf-modal-head">
                <span><i class="fas fa-hand-holding-usd"></i> درخواست تسویه کیف‌پول یورویی</span>
                <button type="button" class="arf-modal-close" onclick="arfCloseSettleModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="arf-modal-body">
                <div class="arf-modal-balance">موجودی فعلی کیف‌پول: <b id="arfModalBalance">0.00€</b></div>
                <div class="arf-modal-note" id="arfModalMinNote">حداقل مبلغ تسویه ۵ یورو است.</div>

                <label class="arf-field-label">حساب مقصد</label>
                <select id="arfBenSelect" class="arf-select">
                    <option value="">در حال بارگذاری حساب‌ها...</option>
                </select>
                <button type="button" class="arf-add-ben-btn" onclick="arfOpenAddBenInline()"><i class="fas fa-plus-circle"></i> معرفی حساب جدید</button>

                <div id="arfAddBenBox" style="display:none;">
                    <label class="arf-field-label">نام صاحب حساب</label>
                    <input type="text" id="arfBenName" class="arf-input" placeholder="نام و نام‌خانوادگی">
                    <label class="arf-field-label">شماره کارت / شبا</label>
                    <input type="text" id="arfBenCard" class="arf-input" placeholder="شماره کارت یا IBAN">
                    <label class="arf-field-label">نام بانک</label>
                    <input type="text" id="arfBenBank" class="arf-input" placeholder="نام بانک (اختیاری)">
                    <button type="button" class="arf-save-ben-btn" onclick="arfSaveNewBen()">ذخیره حساب</button>
                </div>

                <label class="arf-field-label">مبلغ تسویه (یورو)</label>
                <input type="number" id="arfSettleAmount" class="arf-input" placeholder="حداقل ۵ یورو" min="5" step="0.01">

                <div class="arf-modal-error" id="arfModalError"></div>

                <button type="button" class="arf-submit-btn" onclick="arfSubmitSettle()">
                    <i class="fas fa-paper-plane"></i> ثبت درخواست تسویه
                </button>
            </div>
        </div>
    </div>
    <?php /* پایان بخش زیرمجموعه‌های شما */ ?>

    <!-- بخش خلاصه‌ی ۴ کادری وضعیت حساب حذف شد -->
    <div class="av-scope" id="avExchangeScope" style="display:none;">
      <script>window.__AV_EX__ = <?php echo json_encode($exInsights, JSON_UNESCAPED_UNICODE); ?>;</script>
    </div>

    <!-- خط جداکننده -->
    <div class="av-divider"></div>

    <!-- ==================== AvaPay · بازار آگهی‌ها — طرح جدید (arn-) ==================== -->
    <style>
    /* ============ AvaPay · بازار آگهی‌ها — طرح جدید (arn-) ============ */
    .arn-wrap{ margin-top:16px; direction:rtl; }

    /* دو باکس مربعی ثبت آگهی خرید/فروش */
    .arn-cta-grid{ display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:20px; }
    .arn-cta{
        min-height:118px; max-height:150px; border-radius:22px; border:1px solid; position:relative; overflow:hidden;
        display:flex; flex-direction:column; align-items:center; justify-content:center; gap:8px;
        cursor:pointer; -webkit-tap-highlight-color:transparent; font-family:inherit; padding:16px 10px;
    }
    .arn-cta-buy{ background:linear-gradient(155deg,#4c2d8f 0%,#2a1554 70%,#170a2e 100%); border-color:rgba(168,132,255,.35); }
    .arn-cta-sell{ background:linear-gradient(155deg,#9a5c12 0%,#5c360b 70%,#2b1608 100%); border-color:rgba(255,180,90,.35); }
    .arn-cta::before{ content:''; position:absolute; inset:-40% -40% auto auto; width:70%; height:70%;
        background:radial-gradient(circle,rgba(255,255,255,.16),transparent 70%); }
    .arn-cta-ic{ width:44px; height:44px; border-radius:14px; background:rgba(255,255,255,.14);
        display:flex; align-items:center; justify-content:center; font-size:1.15rem; color:#fff;
        box-shadow:0 8px 20px -6px rgba(0,0,0,.5); flex:none; }
    .arn-cta-t{ font-size:.86rem; font-weight:800; color:#fff; }
    .arn-cta-s{ font-size:.6rem; color:rgba(255,255,255,.65); font-weight:600; }
    .arn-cta-corner{ position:absolute; top:10px; left:10px; width:24px; height:24px; border-radius:50%;
        background:rgba(255,255,255,.14); display:flex; align-items:center; justify-content:center; font-size:.66rem; color:#fff; }
    .arn-cta:active{ transform:scale(.97); }
    @media (min-width:640px){
        .arn-cta-grid{ max-width:520px; }
        .arn-cta{ min-height:110px; max-height:130px; }
    }

    /* سرچ و فیلتر */
    .arn-toolbar{ display:flex; gap:8px; margin-bottom:14px; }
    .arn-filter-btn{ position:relative; flex:none; width:46px; height:46px; border-radius:16px;
        background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); display:flex; align-items:center; justify-content:center; color:#fff; cursor:pointer; }
    .arn-filter-lbl{ display:none; }
    .arn-toolbar-solo .arn-filter-btn{ flex:1; width:auto; height:48px; gap:9px; border-radius:16px;
        background:linear-gradient(135deg, rgba(139,92,246,.14), rgba(139,92,246,.05));
        border:1px solid rgba(139,92,246,.28); }
    .arn-toolbar-solo .arn-filter-btn:hover{ border-color:rgba(139,92,246,.5); background:linear-gradient(135deg, rgba(139,92,246,.2), rgba(139,92,246,.08)); }
    .arn-toolbar-solo .arn-filter-lbl{ display:inline; font-size:.78rem; font-weight:700; color:#fff; }
    .arn-toolbar-solo .arn-filter-panel{ left:50%; right:auto; transform:translateX(-50%); max-width:calc(100vw - 32px); }
    .arn-filter-panel{ display:none; position:absolute; top:52px; left:0; min-width:170px; max-width:220px; z-index:60; box-sizing:border-box;
        background:#170B29; border:1px solid rgba(255,255,255,.1); border-radius:14px; padding:6px; box-shadow:0 18px 40px -12px rgba(0,0,0,.6); }
    .arn-filter-panel.open{ display:block; }
    .arn-filter-title{ font-size:.62rem; color:rgba(255,255,255,.4); font-weight:700; padding:6px 8px 2px; }
    .arn-filter-opt{ display:flex; align-items:center; gap:8px; padding:9px 8px; border-radius:10px; font-size:.72rem; font-weight:600; color:#fff; cursor:pointer; white-space:nowrap; }
    .arn-filter-opt:hover{ background:rgba(255,255,255,.06); }
    .arn-filter-sep{ height:1px; margin:6px 8px; background:rgba(255,255,255,.08); }

    .arn-listtitle{ display:flex; align-items:center; justify-content:space-between; gap:8px; font-size:.86rem; font-weight:800; color:#fff; margin:4px 0 12px; }
    .arn-listtitle .t{ display:flex; align-items:center; gap:8px; }
    .arn-listtitle i{ color:#FFD700; }
    .arn-count{ font-size:.66rem; color:rgba(255,255,255,.45); font-weight:600; }

    /* لیست آگهی — ردیف کامل مطابق طرح تأییدشده */
    .arn-list{ display:flex; flex-direction:column; gap:10px; }
    .arn-row{ position:relative; display:flex; align-items:center; justify-content:space-between; gap:12px;
        background:#150d29; border:1px solid rgba(255,255,255,.08); border-radius:20px; padding:14px;
        animation:arnRise .35s ease both; cursor:pointer; -webkit-tap-highlight-color:transparent; }
    .arn-row:focus-visible{ outline:2px solid #FFD700; outline-offset:-3px; }
    @keyframes arnRise{ from{opacity:0; transform:translateY(8px);} to{opacity:1; transform:translateY(0);} }

    .arn-row-info{ display:flex; align-items:center; gap:12px; min-width:0; flex:1; }
    .arn-avatar{ width:52px; height:52px; border-radius:50%; object-fit:cover; flex:none; border:1px solid rgba(255,255,255,.12); background:#2a1a45; }
    .arn-main{ flex:1; min-width:0; }
    .arn-name-row{ display:flex; align-items:center; gap:5px; margin-bottom:4px; }
    .arn-name-row b{ font-size:.84rem; font-weight:800; color:#fff; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .arn-name-row i.verified{ color:#22C55E; font-size:.72rem; flex:none; }
    .arn-meta{ display:flex; align-items:center; gap:5px; font-size:.66rem; color:rgba(255,255,255,.5); margin-bottom:6px; }
    .arn-meta .star{ color:#FFD700; display:flex; align-items:center; gap:3px; }
    .arn-cur{ display:inline-flex; align-items:center; gap:5px; font-size:.66rem; font-weight:700; color:#26d38a; }
    .arn-cur .dot{ width:16px; height:16px; border-radius:50%; color:#fff; font-size:.55rem; font-weight:900;
        display:flex; align-items:center; justify-content:center; flex:none; }

    .arn-right{ flex:none; display:flex; flex-direction:column; align-items:flex-start; gap:2px; }
    .arn-price-l{ font-size:.58rem; color:rgba(255,255,255,.45); }
    .arn-price-v{ font-size:1rem; font-weight:900; color:#fff; line-height:1.3; }
    .arn-price-unit{ font-size:.55rem; font-weight:600; color:rgba(255,255,255,.45); margin-right:3px; }
    .arn-amount-l{ font-size:.56rem; color:rgba(255,255,255,.45); margin-top:2px; }
    .arn-amount-v{ font-size:.76rem; font-weight:700; color:#FFD700; }
    .arn-action-btn{ border:none; border-radius:12px; padding:9px 20px; font-size:.76rem; font-weight:800; color:#fff;
        background:linear-gradient(135deg,#7c3aed,#5b21b6); cursor:pointer; font-family:inherit; margin-top:6px; }
    .arn-row.is-buyad .arn-action-btn{ background:linear-gradient(135deg,#fbbf24,#d97706); }

    .arn-row-del{ position:absolute; top:10px; left:10px; width:26px; height:26px; border-radius:9px;
        display:flex; align-items:center; justify-content:center; font-size:.62rem;
        background:rgba(255,107,122,.14); color:#FF6B7A; border:1px solid rgba(255,107,122,.28); z-index:2; }

    .arn-empty{ text-align:center; padding:34px 10px; color:rgba(255,255,255,.45); font-size:.78rem; }
    .arn-empty i{ font-size:1.6rem; display:block; margin-bottom:8px; color:rgba(255,255,255,.25); }
    .axm-skel{ height:96px; border-radius:20px; background:linear-gradient(100deg,rgba(255,255,255,.03) 30%,rgba(255,255,255,.07) 50%,rgba(255,255,255,.03) 70%);
        background-size:220% 100%; animation:axmShimmer 1.3s ease-in-out infinite; }
    @keyframes axmShimmer{ 0%{background-position:120% 0;} 100%{background-position:-20% 0;} }

    /* پیشنهادها: یک کادر با دو تب (دریافت‌شده پیش‌فرض فعال) — هم موبایل هم دسکتاپ */
    .arn-offers-title{ display:flex; align-items:center; gap:8px; font-size:.86rem; font-weight:800; color:#fff; margin:22px 0 12px; }
    .arn-offers-title i{ color:#A855F7; }
    .arn-offers-box{ background:#150d29; border:1px solid rgba(255,255,255,.08); border-radius:20px; padding:12px; }
    .arn-offers-tabs{ display:flex; gap:6px; background:rgba(255,255,255,.04); border-radius:14px; padding:4px; margin-bottom:12px; }
    .arn-offers-tab{ flex:1; display:flex; align-items:center; justify-content:center; gap:6px; border:none; background:transparent;
        color:rgba(255,255,255,.55); font-family:inherit; font-size:.7rem; font-weight:800; padding:9px 4px; border-radius:11px; cursor:pointer; }
    .arn-offers-tab i{ font-size:.72rem; }
    .arn-offers-tab.active{ color:#fff; background:linear-gradient(135deg, rgba(56,189,248,.22), rgba(56,189,248,.08)); box-shadow:inset 0 0 0 1px rgba(56,189,248,.4); }
    .arn-offers-tab.active i{ color:#38BDF8; }
    .arn-offers-tab#arnTabSent.active{ background:linear-gradient(135deg, rgba(168,85,247,.22), rgba(168,85,247,.08)); box-shadow:inset 0 0 0 1px rgba(168,85,247,.4); }
    .arn-offers-tab#arnTabSent.active i{ color:#A855F7; }
    .arn-offers-tab .cnt{ background:rgba(255,255,255,.12); border-radius:8px; padding:1px 7px; font-size:.6rem; font-weight:700; }
    .arn-offers-tab.has-new{ position:relative; }
    .arn-offers-tab.has-new::after{ content:''; position:absolute; top:6px; left:10px; width:8px; height:8px; border-radius:50%;
        background:#FF3B5C; box-shadow:0 0 0 0 rgba(255,59,92,.6); animation:arnTabDotBlink 1.1s ease-in-out infinite; }
    @keyframes arnTabDotBlink{ 0%,100%{ opacity:1; box-shadow:0 0 0 0 rgba(255,59,92,.55); } 50%{ opacity:.55; box-shadow:0 0 8px 2px rgba(255,59,92,.55); } }
    .arn-offers-body{ position:relative; }
    .arn-offers-pane{ display:none; }
    .arn-offers-pane.active{ display:block; animation:arnPaneIn .25s ease; }
    @keyframes arnPaneIn{ from{ opacity:0; transform:translateY(4px); } to{ opacity:1; transform:translateY(0); } }
    .arn-viewall{ margin-right:auto; background:transparent; border:none; color:rgba(255,255,255,.55); font-size:.6rem; font-weight:700;
        display:flex; align-items:center; gap:3px; cursor:pointer; font-family:inherit; padding:2px 0; }
    .arn-viewall i{ font-size:.55rem; }
    .arn-viewall:hover{ color:#fff; }
    .arn-viewall-full{ width:100%; justify-content:center; margin-top:10px; padding-top:10px; border-top:1px dashed rgba(255,255,255,.08); }

    .arn-offer-card{ position:relative; background:#1a1035; border:1px solid rgba(255,255,255,.08); border-radius:14px; padding:9px;
        transition:border-color .3s,box-shadow .3s; }
    .arn-offer-card + .arn-offer-card{ margin-top:8px; }
    /* افکت «چشمک‌زن» برای پیشنهاد تازه‌رسیده */
    .arn-offer-card.is-new{ border-color:rgba(56,189,248,.55); box-shadow:0 0 0 0 rgba(56,189,248,.5); animation:arnNewGlow 1.7s ease-in-out 4; }
    @keyframes arnNewGlow{
        0%,100%{ box-shadow:0 0 0 0 rgba(56,189,248,.0); border-color:rgba(56,189,248,.3); }
        50%{ box-shadow:0 0 20px 2px rgba(56,189,248,.55); border-color:rgba(56,189,248,.85); }
    }
    .arn-new-tag{ position:absolute; top:-8px; left:8px; background:linear-gradient(135deg,#FF3B5C,#FF7A45); color:#fff; font-size:.5rem; font-weight:900;
        padding:2px 8px; border-radius:20px; box-shadow:0 3px 8px -2px rgba(255,59,92,.6); animation:arnTagBlink 1s ease-in-out infinite; z-index:2; }
    @keyframes arnTagBlink{ 0%,100%{ opacity:1; transform:scale(1); } 50%{ opacity:.7; transform:scale(1.1); } }

    /* کارت: ردیف نام (تک‌خطی) ← ردیف مبلغ پیشنهادی (نه مبلغ کل) ← ردیف دکمه‌ها */
    .arn-offer-nameline{ display:flex; align-items:center; gap:6px; margin-bottom:7px; }
    .arn-offer-avatar{ width:24px; height:24px; border-radius:50%; object-fit:cover; flex:none; background:#2a1a45; }
    .arn-offer-name{ font-size:.66rem; font-weight:700; color:#fff; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; flex:1; min-width:0; }
    .arn-offer-priceline{ display:flex; flex-direction:column; gap:1px; margin-bottom:8px; }
    .arn-offer-pricelabel{ font-size:.56rem; color:rgba(255,255,255,.45); }
    .arn-offer-amt{ font-size:.74rem; font-weight:800; color:#FFD700; white-space:nowrap; }
    .arn-offer-card.is-clickable{ cursor:pointer; }
    .arn-offer-card.is-clickable:hover{ border-color:rgba(255,255,255,.22); }
    .arn-offer-btns{ display:flex; gap:5px; }
    .arn-offer-btn{ flex:1; border:none; border-radius:8px; padding:6px 2px; font-size:.58rem; font-weight:800;
        display:flex; align-items:center; justify-content:center; gap:3px; cursor:pointer; font-family:inherit; }
    .arn-offer-btn.reject{ background:rgba(255,90,110,.16); color:#FF5A6E; border:1px solid rgba(255,90,110,.28); }
    .arn-offer-btn.accept{ background:linear-gradient(135deg,#22C55E,#16A34A); color:#fff; }
    .arn-offer-btn.status{ width:100%; background:rgba(255,255,255,.08); color:rgba(255,255,255,.55); font-size:.56rem; white-space:nowrap; }
    .arn-offer-empty{ font-size:.6rem; color:rgba(255,255,255,.35); text-align:center; padding:16px 4px; }

    @media (max-width:360px){
        .arn-cta-t{ font-size:.82rem; } .arn-cta-s{ font-size:.58rem; }
        .arn-avatar{ width:44px; height:44px; }
        .arn-price-v{ font-size:.88rem; }
        .arn-offers-box{ padding:9px; }
    }

    /* استایل‌های کمکیِ مدال‌های «مشاهده همه» (آگهی‌ها/پیشنهادها/تاریخچه) — همچنان در جاوااسکریپت استفاده می‌شوند */
    .axs-empty-mini{ font-size:.7rem; color:rgba(255,255,255,.4); text-align:center; padding:14px 4px; }
    .axs-modal-row{ display:flex; align-items:center; gap:10px; background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.08); border-radius:14px; padding:10px 12px; margin-bottom:8px; }
    .axs-modal-row .amr-ic{ width:34px; height:34px; border-radius:10px; display:flex; align-items:center; justify-content:center; flex:none; }
    .axs-modal-row .amr-body{ flex:1; min-width:0; }
    .axs-modal-row .amr-t{ font-size:.78rem; font-weight:700; color:#fff; }
    .axs-modal-row .amr-s{ font-size:.66rem; color:rgba(255,255,255,.5); margin-top:2px; }
    .axs-modal-row .amr-edit{ flex:none; background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.14); color:#fff; font-size:.68rem; font-weight:700; padding:6px 12px; border-radius:9px; cursor:pointer; font-family:inherit; }
    </style>

    <!-- ==================== بازار آگهی‌ها ==================== -->
    <div class="arn-wrap" id="axmWrap">

        <!-- دو باکس مربعی ثبت آگهی خرید/فروش -->
        <div class="arn-cta-grid">
            <button type="button" class="arn-cta arn-cta-buy" onclick="openCreateAd('buy')">
                <span class="arn-cta-corner"><i class="fas fa-plus"></i></span>
                <span class="arn-cta-ic"><i class="fas fa-cart-shopping"></i></span>
                <span class="arn-cta-t">ثبت آگهی خرید</span>
                <span class="arn-cta-s">خرید ارز دیجیتال</span>
            </button>
            <button type="button" class="arn-cta arn-cta-sell" onclick="openCreateAd('sell')">
                <span class="arn-cta-corner"><i class="fas fa-plus"></i></span>
                <span class="arn-cta-ic"><i class="fas fa-sack-dollar"></i></span>
                <span class="arn-cta-t">ثبت آگهی فروش</span>
                <span class="arn-cta-s">فروش ارز دیجیتال</span>
            </button>
        </div>

        <!-- فیلتر -->
        <div class="arn-toolbar arn-toolbar-solo">
            <div class="arn-filter-btn" id="axmFilterBtn" onclick="axmToggleFilterPanel(event)">
                <i class="fas fa-sliders"></i>
                <span class="arn-filter-lbl">فیلتر و مرتب‌سازی</span>
                <div class="arn-filter-panel" id="axmFilterPanel">
                    <div class="arn-filter-title">نوع آگهی</div>
                    <div class="arn-filter-opt" onclick="axmSetType('all')"><i class="fas fa-layer-group"></i> همه‌ی آگهی‌ها</div>
                    <div class="arn-filter-opt" onclick="axmSetType('sell')"><i class="fas fa-tag"></i> فروشندگان (برای خرید)</div>
                    <div class="arn-filter-opt" onclick="axmSetType('buy')"><i class="fas fa-cart-shopping"></i> خریداران (برای فروش)</div>
                    <div class="arn-filter-sep"></div>
                    <div class="arn-filter-title">مرتب‌سازی</div>
                    <div class="arn-filter-opt" onclick="axmSort('new')"><i class="fas fa-clock"></i> جدیدترین</div>
                    <div class="arn-filter-opt" onclick="axmSort('price_high')"><i class="fas fa-arrow-up-wide-short"></i> بیشترین قیمت</div>
                    <div class="arn-filter-opt" onclick="axmSort('price_low')"><i class="fas fa-arrow-down-short-wide"></i> کمترین قیمت</div>
                    <div class="arn-filter-sep"></div>
                    <div class="arn-filter-opt" onclick="refreshAds()"><i class="fas fa-sync-alt"></i> بروزرسانی</div>
                </div>
            </div>
        </div>

        <div class="arn-listtitle">
            <span class="t"><i class="fas fa-bullhorn"></i> آگهی‌ها</span>
            <span class="arn-count" id="axmCount"></span>
        </div>

        <div id="adsContainer" class="arn-list">
            <div class="axm-skel"></div>
            <div class="axm-skel"></div>
            <div class="axm-skel"></div>
        </div>

        <!-- دسترسی سریع (کششی/اسکرول افقی) -->
        <div class="axsv-menu">
            <div class="axsv-title"><i class="fas fa-bolt"></i> دسترسی سریع</div>
            <div class="axsv-grid">
                <button type="button" class="axsv-item" onclick="axOpenServiceModal('topup')">
                    <span class="axsv-ic axsv-img"><img src="assets/images/qa/topup.png" alt="" loading="lazy"></span>
                    <span class="axsv-t">شارژ سریع کیف</span>
                </button>
                <button type="button" class="axsv-item" onclick="axOpenServiceModal('settlement')">
                    <span class="axsv-ic axsv-img"><img src="assets/images/qa/settlement.png" alt="" loading="lazy"></span>
                    <span class="axsv-t">تسویه حساب</span>
                </button>
                <button type="button" class="axsv-item" onclick="axOpenServiceModal('transfer')">
                    <span class="axsv-ic axsv-img"><img src="assets/images/qa/transfer.png" alt="" loading="lazy"></span>
                    <span class="axsv-t">حواله ارزی</span>
                </button>
                <button type="button" class="axsv-item" onclick="axOpenServiceModal('transactions')">
                    <span class="axsv-ic axsv-img"><img src="assets/images/qa/accounts.png" alt="" loading="lazy"></span>
                    <span class="axsv-t">تراکنش‌ها</span>
                </button>
                <button type="button" class="axsv-item" onclick="axOpenServiceModal('directbuy')">
                    <span class="axsv-ic axsv-img"><img src="assets/images/qa/directbuy.png" alt="" loading="lazy"></span>
                    <span class="axsv-t">خرید مستقیم</span>
                </button>
            </div>
        </div>
        <script>
        /* دسترسی سریع: اگر آیکون‌ها کل عرض ردیف را پر نکنند (اسکرول لازم نیست)،
           به‌جای اینکه از یک طرف چسبیده بمانند و فاصله‌ی خالی بگذارند، وسط‌چین
           می‌شوند. اگر روزی تعداد آیکون‌ها زیاد شود و از عرض صفحه بیشتر شوند،
           همین منطق خودکار غیرفعال می‌شود و ردیف با انگشت افقی اسکرول/سواپ
           می‌شود (رفتار پیش‌فرض overflow-x:auto، بدون نیاز به تغییر کد). */
        (function () {
            function fitAxsvGrid () {
                var grid = document.querySelector('.axsv-menu .axsv-grid');
                if (!grid) return;
                var overflowing = grid.scrollWidth > grid.clientWidth + 1;
                grid.classList.toggle('axsv-fit', !overflowing);
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', fitAxsvGrid);
            } else {
                fitAxsvGrid();
            }
            window.addEventListener('resize', fitAxsvGrid);
            if (document.fonts && document.fonts.ready) { document.fonts.ready.then(fitAxsvGrid).catch(function () {}); }
        })();
        </script>

        <!-- پیشنهادها: دو تب دریافت‌شده/ارسال‌شده (دریافت‌شده پیش‌فرض فعال) -->
        <div class="arn-offers-title"><i class="fas fa-handshake"></i> پیشنهادها</div>
        <div class="arn-offers-box">
            <div class="arn-offers-tabs">
                <button type="button" class="arn-offers-tab active" id="arnTabRecv" onclick="arnSwitchOfferTab('received')">
                    <i class="fas fa-inbox"></i> دریافت‌شده <span class="cnt" id="arnRecvCount" style="display:none;"></span>
                </button>
                <button type="button" class="arn-offers-tab" id="arnTabSent" onclick="arnSwitchOfferTab('sent')">
                    <i class="fas fa-paper-plane"></i> ارسال‌شده <span class="cnt" id="arnSentCount" style="display:none;"></span>
                </button>
            </div>
            <div class="arn-offers-body">
                <div id="arnOffersReceived" class="arn-offers-pane active"><div class="arn-offer-empty">در حال بارگذاری…</div></div>
                <div id="arnOffersSent" class="arn-offers-pane"><div class="arn-offer-empty">در حال بارگذاری…</div></div>
            </div>
            <button type="button" class="arn-viewall arn-viewall-full" onclick="openOffersListModal(arnActiveOfferTab)">مشاهده همه <i class="fas fa-chevron-left"></i></button>
        </div>

    </div>


    <!-- ==================== معاملات فعال ==================== -->
    <div class="deals-section<?php echo empty($activeDeals) ? '' : ' is-live'; ?>" id="dealsSectionAnchor"<?php echo empty($activeDeals) ? ' hidden' : ''; ?>>
        <div class="section-header">
            <h3><i class="fas fa-fire"></i> معاملات در انتظار تسویه</h3>
            <button class="refresh-btn" onclick="loadActiveDeals()"><i class="fas fa-sync-alt"></i></button>
        </div>
        <div id="activeDealsContainer" class="deals-container">
            <?php if (empty($activeDeals)): ?>
            <?php else: ?>
            <div class="deals-list">
                <?php foreach ($activeDeals as $index => $deal):
                    // تعیین طرف کاربر و داده‌های تسویه‌ی همان طرف
                    $mySide = ((int)$deal['buyer_id'] === (int)$userId) ? 'buyer' : 'seller';
                    $p = $mySide . '_';
                    $myAccounts = json_decode($deal[$p.'admin_accounts'] ?? '', true); if (!is_array($myAccounts)) $myAccounts = [];
                    $myReceipts = json_decode($deal[$p.'receipts'] ?? '', true); if (!is_array($myReceipts)) $myReceipts = [];
                    $mySettle   = json_decode($deal[$p.'settlement_receipts'] ?? '', true); if (!is_array($mySettle)) $mySettle = [];
                    $myNote     = $deal[$p.'admin_note'] ?? '';
                    $sideStatus = $deal[$p.'side_status'] ?? 'new';
                    // (جدید) فیشی که از طریق سیستم یکپارچه‌ی «ایجاد فیش جدید» و با
                    // پیوند مستقیم به همین معامله/طرف فرستاده شده — اگر باشد،
                    // اولویت با همین است (مسیر فعلی و درست)، نه فیلد قدیمیِ admin_accounts.
                    $myInvoice = null;
                    try {
                        $__ivSt = $conn->prepare("SELECT id, status, amount, currency, bank_name, account_number, card_number, recipient_name, iban, receipt_image
                                                    FROM unpaid_invoices WHERE deal_id = ? AND deal_side = ? ORDER BY created_at DESC LIMIT 1");
                        if ($__ivSt) { $__ivSt->bind_param("is", $deal['id'], $mySide); $__ivSt->execute(); $myInvoice = $__ivSt->get_result()->fetch_assoc(); }
                    } catch (\Throwable $e) { $myInvoice = null; }
                    $stLabels = ['new'=>'در انتظار حساب','awaiting_payment'=>'در انتظار پرداخت','receipt_submitted'=>'فیش ارسال شد','completed'=>'تسویه شد'];
                    $stClass  = ($sideStatus==='completed'?'done':($sideStatus==='receipt_submitted'?'submitted':'waiting'));
                ?>
                <div class="axd-item" data-deal-id="<?php echo $deal['id']; ?>" style="animation-delay:<?php echo $index * 0.06; ?>s;">
                    <?php
                        $axdOrder = ['new','awaiting_payment','receipt_submitted','completed'];
                        $axdIdx   = array_search($sideStatus, $axdOrder); if ($axdIdx === false) $axdIdx = 0;
                        $axdPct   = ($axdIdx / (count($axdOrder) - 1)) * 100;
                        $axdIcons = ['fa-file-invoice','fa-credit-card','fa-receipt','fa-circle-check'];
                        $axdShort = ['حساب','پرداخت','فیش','تسویه'];
                    ?>
                    <button type="button" class="axd-head" onclick="axdToggle(this)">
                        <span class="axd-code"><i class="fas fa-key"></i> <?php echo htmlspecialchars($deal['deal_code']); ?></span>
                        <span class="axd-sum"><b><?php echo number_format($deal['total_price']); ?></b> تومان · <?php echo number_format($deal['amount']); ?> <?php echo htmlspecialchars($deal['currency']); ?></span>
                        <span class="axd-state <?php echo $stClass; ?>"><?php echo $stLabels[$sideStatus] ?? 'در انتظار تسویه'; ?></span>
                        <i class="fas fa-chevron-down axd-caret"></i>
                    </button>

                    <!-- ریل مرحله: یک سطر، انیمیشنی -->
                    <div class="axd-rail <?php echo $stClass; ?>">
                        <div class="axd-track"><div class="axd-fill" style="--pct:<?php echo $axdPct; ?>%"></div></div>
                        <div class="axd-nodes">
                            <?php foreach ($axdOrder as $axdI => $axdKey):
                                $axdCls = $axdI < $axdIdx ? 'done' : ($axdI === $axdIdx ? 'cur' : 'todo');
                            ?>
                            <span class="axd-node <?php echo $axdCls; ?>" style="--d:<?php echo $axdI * 0.12; ?>s">
                                <i class="fas <?php echo $axdI < $axdIdx ? 'fa-check' : $axdIcons[$axdI]; ?>"></i>
                                <em><?php echo $axdShort[$axdI]; ?></em>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="axd-body">
                        <div class="axd-parties">
                            <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($deal['buyer_first_name'].' '.$deal['buyer_last_name']); ?> <em>پیشنهاددهنده</em></span>
                            <span><i class="fas fa-store"></i> <?php echo htmlspecialchars($deal['seller_first_name'].' '.$deal['seller_last_name']); ?> <em>آگهی‌دهنده</em></span>
                        </div>
                        <?php if ($sideStatus === 'completed'): ?>
                            <div class="dc-note ok"><i class="fas fa-check-double"></i> این معامله برای شما تسویه شد.</div>
                            <?php if ($myNote): ?><div class="dc-adminnote"><i class="fas fa-comment"></i> <?php echo htmlspecialchars($myNote); ?></div><?php endif; ?>
                            <?php if (!empty($mySettle)): ?>
                            <div class="dc-settle"><div class="dc-settle-title"><i class="fas fa-receipt"></i> فیش‌های تسویه</div><div class="dc-recs">
                                <?php foreach ($mySettle as $ri => $rp): ?>
                                    <a class="ud-thumb<?php echo preg_match('/\.pdf$/i',$rp)?' pdf':''; ?>" href="<?php echo preg_match('/\.pdf$/i',$rp)? ('api/deal_settlement_api.php?action=download_receipt&deal_id='.$deal['id'].'&kind=settlement&index='.$ri) : htmlspecialchars($rp); ?>" target="_blank"><?php echo preg_match('/\.pdf$/i',$rp)?'<i class="fas fa-file-pdf"></i>':'<img src="'.htmlspecialchars($rp).'" loading="lazy">'; ?></a>
                                <?php endforeach; ?>
                            </div></div>
                            <?php endif; ?>
                        <?php elseif ($myInvoice && $myInvoice['status'] === 'approved'): ?>
                            <div class="dc-settle">
                                <div class="dc-settle-title"><i class="fas fa-credit-card"></i> واریز به کارت زیر و ارسال فیش</div>
                                <div class="dc-acc" style="background:rgba(255,215,0,0.1);border:1px solid rgba(255,215,0,0.25);">
                                    <div class="dc-acc-info"><span class="dc-acc-name">💰 مبلغ قابل پرداخت</span><code><?php echo number_format($myInvoice['amount']); ?> <?php echo htmlspecialchars($myInvoice['currency'] === 'IRR' ? 'تومان' : $myInvoice['currency']); ?></code></div>
                                    <button type="button" class="dc-copy" onclick="copyDealText('<?php echo number_format($myInvoice['amount']); ?>')"><i class="fas fa-copy"></i> کپی</button>
                                </div>
                                <?php foreach ([['bank_name','recipient_name'],['account_number','bank_name'],['card_number','recipient_name'],['iban','recipient_name']] as $__pair): $__val = $myInvoice[$__pair[0]] ?? ''; if (empty($__val)) continue; ?>
                                <div class="dc-acc">
                                    <div class="dc-acc-info"><span class="dc-acc-name"><?php echo $__pair[0]==='iban' ? 'شبا' : ($__pair[0]==='account_number' ? 'شماره حساب' : (!empty($myInvoice[$__pair[1]]) ? htmlspecialchars($myInvoice[$__pair[1]]) : 'شماره کارت')); ?></span><code><?php echo htmlspecialchars($__val); ?></code></div>
                                    <button type="button" class="dc-copy" onclick="copyDealText('<?php echo htmlspecialchars($__val); ?>')"><i class="fas fa-copy"></i> کپی</button>
                                </div>
                                <?php endforeach; ?>
                                <input type="file" id="axInvFile_<?php echo (int)$myInvoice['id']; ?>" accept="image/*,.pdf" multiple style="display:none" onchange="axInvPicked(<?php echo (int)$myInvoice['id']; ?>)">
                                <div class="dc-actions">
                                    <button type="button" class="dc-upload-btn" onclick="document.getElementById('axInvFile_<?php echo (int)$myInvoice['id']; ?>').click()"><i class="fas fa-paperclip"></i> انتخاب فیش‌ها</button>
                                    <button type="button" class="dc-send-btn" onclick="axInvUpload(<?php echo (int)$myInvoice['id']; ?>)"><i class="fas fa-paper-plane"></i> ارسال فیش</button>
                                </div>
                                <span id="axInvPicked_<?php echo (int)$myInvoice['id']; ?>" class="dc-picked"></span>
                            </div>
                        <?php elseif ($myInvoice && $myInvoice['status'] === 'paid'): ?>
                            <div class="dc-note ok"><i class="fas fa-check-circle"></i> فیش شما ارسال شد و در انتظار تأیید نهایی پشتیبانی است.</div>
                        <?php elseif ($myInvoice && $myInvoice['status'] === 'finalized'): ?>
                            <div class="dc-note ok"><i class="fas fa-check-double"></i> تسویه‌ی این طرف از معامله تکمیل شده است.</div>
                        <?php elseif (!empty($myAccounts)): ?>
                            <div class="dc-settle">
                                <div class="dc-settle-title"><i class="fas fa-credit-card"></i> واریز به کارت زیر و ارسال فیش</div>
                                <?php $myPayAmount = $deal[$p.'payment_amount'] ?? null; $myPayCurrency = $deal[$p.'payment_currency'] ?? null; ?>
                                <?php if (!empty($myPayAmount)): ?>
                                <div class="dc-acc" style="background:rgba(255,215,0,0.1);border:1px solid rgba(255,215,0,0.25);">
                                    <div class="dc-acc-info"><span class="dc-acc-name">💰 مبلغ قابل پرداخت</span><code><?php echo number_format($myPayAmount); ?> <?php echo htmlspecialchars($myPayCurrency === 'IRR' ? 'تومان' : $myPayCurrency); ?></code></div>
                                    <button type="button" class="dc-copy" onclick="copyDealText('<?php echo number_format($myPayAmount); ?>')"><i class="fas fa-copy"></i> کپی</button>
                                </div>
                                <?php endif; ?>
                                <?php foreach ($myAccounts as $acc): ?>
                                <div class="dc-acc">
                                    <div class="dc-acc-info"><?php if(!empty($acc['name'])): ?><span class="dc-acc-name"><?php echo htmlspecialchars($acc['name']); ?></span><?php endif; ?><code><?php echo htmlspecialchars($acc['card']); ?></code></div>
                                    <button type="button" class="dc-copy" onclick="copyDealText('<?php echo htmlspecialchars($acc['card']); ?>')"><i class="fas fa-copy"></i> کپی</button>
                                </div>
                                <?php endforeach; ?>
                                <?php if (!empty($myReceipts)): ?>
                                <div class="dc-recs">
                                    <?php foreach ($myReceipts as $ri => $rp): ?>
                                        <a class="ud-thumb<?php echo preg_match('/\.pdf$/i',$rp)?' pdf':''; ?>" href="<?php echo preg_match('/\.pdf$/i',$rp)? ('api/deal_settlement_api.php?action=download_receipt&deal_id='.$deal['id'].'&kind=receipt&index='.$ri) : htmlspecialchars($rp); ?>" target="_blank"><?php echo preg_match('/\.pdf$/i',$rp)?'<i class="fas fa-file-pdf"></i>':'<img src="'.htmlspecialchars($rp).'" loading="lazy">'; ?></a>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                                <input type="file" id="dcFile_<?php echo $deal['id']; ?>" accept="image/*,.pdf" multiple style="display:none" onchange="dcPicked(<?php echo $deal['id']; ?>)">
                                <div class="dc-actions">
                                    <button type="button" class="dc-upload-btn" onclick="document.getElementById('dcFile_<?php echo $deal['id']; ?>').click()"><i class="fas fa-paperclip"></i> انتخاب فیش‌ها</button>
                                    <button type="button" class="dc-send-btn" onclick="dcUpload(<?php echo $deal['id']; ?>)"><i class="fas fa-paper-plane"></i> ارسال فیش</button>
                                </div>
                                <span id="dcPicked_<?php echo $deal['id']; ?>" class="dc-picked"></span>
                            </div>
                        <?php else: ?>
                            <div class="dc-note"><i class="fas fa-hourglass-half"></i> پشتیبانی هنوز شماره‌حساب واریز این معامله را ارسال نکرده است. به‌محض ارسال، همین‌جا نمایش داده می‌شود و می‌توانید مبلغ را واریز و فیش پرداخت را نیز مستقیماً از همین‌جا ارسال کنید.</div>
                        <?php endif; ?>
                        <button type="button" class="deal-manage-btn" onclick="openUserDeal(<?php echo (int)$deal['id']; ?>)"><i class="fas fa-eye"></i> مشاهده کامل وضعیت</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- بخش «معاملات تکمیل شده»‌ی تمام‌عرض حذف شد؛ خلاصه‌ی آن در کارت «تاریخچه معاملات» بالای همین صفحه و لیست کامل آن در صفحه‌ی تاریخچه‌ی معاملات نمایش داده می‌شود -->

    <!-- پنل مدیریت پایین صفحه حذف شد؛ مدیریت از admin_panel.php انجام می‌شود -->
</div>

<!-- مودال پشتیبانی (چت متصل به همان ربات تلگرام دشبورد) -->
<div id="axSupportModal" class="modal-overlay">
    <div class="modal-content" style="max-width:420px;display:flex;flex-direction:column;height:78vh;max-height:640px;">
        <div class="modal-header">
            <h3><i class="fas fa-headset"></i> پشتیبانی</h3>
            <button class="close-modal" onclick="axCloseSupport()">&times;</button>
        </div>
        <div class="modal-body" id="axSupportChatList" style="flex:1;overflow-y:auto;display:flex;flex-direction:column;gap:8px;">
            <div style="text-align:center;color:rgba(255,255,255,.4);font-size:.72rem;padding:20px 0;">در حال بارگذاری...</div>
        </div>
        <div style="display:flex;align-items:center;gap:8px;padding:10px 12px;border-top:1px solid rgba(255,255,255,.1);">
            <label for="axSupportFile" style="cursor:pointer;color:rgba(255,255,255,.55);font-size:1rem;">
                <i class="fas fa-paperclip"></i>
            </label>
            <input type="file" id="axSupportFile" style="display:none;" onchange="axSupportFilePicked()">
            <input type="text" id="axSupportText" placeholder="پیام خود را بنویسید..." style="flex:1;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:10px 12px;color:#fff;font-family:inherit;font-size:.78rem;" onkeydown="if(event.key==='Enter'){event.preventDefault();axSendSupport();}">
            <button onclick="axSendSupport()" style="flex:none;width:38px;height:38px;border-radius:12px;border:none;background:linear-gradient(135deg,#6C40C5,#FF4D8D);color:#fff;cursor:pointer;"><i class="fas fa-paper-plane"></i></button>
        </div>
        <div id="axSupportFileInfo" style="padding:0 12px 8px;font-size:.62rem;color:#FFD700;"></div>
    </div>
</div>

<style>
.ax-sup-msg{ max-width:80%; padding:9px 12px; border-radius:14px; font-size:.76rem; line-height:1.6; word-break:break-word; }
.ax-sup-msg.user{ align-self:flex-start; background:linear-gradient(135deg,#6C40C5,#4c2d8f); color:#fff; border-bottom-left-radius:4px; }
.ax-sup-msg.admin{ align-self:flex-end; background:rgba(255,255,255,.08); color:#fff; border-bottom-right-radius:4px; }
.ax-sup-msg a.ax-sup-file{ display:inline-flex; align-items:center; gap:6px; margin-top:6px; color:#FFD700; text-decoration:none; font-size:.7rem; }
</style>

<!-- مودال‌ها -->
<div id="createAdModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-plus-circle"></i> ثبت آگهی جدید</h3>
            <button class="close-modal" onclick="closeCreateAdModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form id="createAdFormModal">
                <div class="form-group">
                    <label>نوع آگهی</label>
                    <div class="type-selector">
                        <button type="button" id="modalTypeBuy" class="type-btn buy active" onclick="setModalAdType('buy')">خریدار</button>
                        <button type="button" id="modalTypeSell" class="type-btn sell" onclick="setModalAdType('sell')">فروشنده</button>
                    </div>
                    <input type="hidden" id="modalAdType" value="buy">
                </div>
                <div class="form-group">
                    <label>ارز</label>
                    <select id="modalAdCurrency" class="form-control" required>
                        <option value="">انتخاب کنید</option>
                        <option value="USD">دلار (USD)</option>
                        <option value="EUR">یورو (EUR)</option>
                        <option value="USDT">تتر (USDT)</option>
                        <option value="IRR">تومان (IRR)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>مقدار ارز</label>
                    <input type="number" id="modalAdAmount" class="form-control" step="0.01" min="0.01" required>
                </div>
                <div class="form-group">
                    <label>قیمت هر واحد (تومان)</label>
                    <input type="number" id="modalAdPrice" class="form-control" step="100" min="100" required>
                </div>
                <div class="form-group">
                    <label>توضیحات</label>
                    <textarea id="modalAdDesc" class="form-control" rows="3" placeholder="شرایط معامله..."></textarea>
                </div>
                <button type="submit" class="btn-submit">ثبت آگهی</button>
            </form>
        </div>
    </div>
</div>

<div id="editAdModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-edit"></i> ویرایش آگهی</h3>
            <button class="close-modal" onclick="closeEditModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div id="editAdInfo" style="background: rgba(108,64,197,0.1); padding: 10px; border-radius: 12px; margin-bottom: 15px; text-align: center;">
                <span id="editCurrencyBadge" style="display: inline-block; padding: 2px 8px; border-radius: 16px; background: rgba(76,217,100,0.2); color: #4CD964; font-size: 0.7rem;"></span>
                <div style="margin-top: 6px; font-size: 0.7rem; color: var(--text-gray);">مقدار و قیمت آگهی را تغییر دهید</div>
            </div>
            <form id="editAdForm" onsubmit="return false;">
                <input type="hidden" id="editAdId">
                <div class="edit-form-group">
                    <label><i class="fas fa-chart-line"></i> مقدار ارز جدید</label>
                    <input type="number" id="editAmount" class="edit-form-control" step="0.01" min="0.01" required>
                </div>
                <div class="edit-form-group">
                    <label><i class="fas fa-money-bill-wave"></i> قیمت هر واحد جدید (تومان)</label>
                    <input type="number" id="editPrice" class="edit-form-control" step="100" min="100" required>
                </div>
                <div class="edit-form-group">
                    <label><i class="fas fa-comment"></i> توضیحات جدید (اختیاری)</label>
                    <textarea id="editDescription" class="edit-form-control" rows="3" placeholder="شرایط جدید معامله را وارد کنید..."></textarea>
                </div>
                <div id="editNewTotalPreview" style="background: rgba(76,217,100,0.1); padding: 10px; border-radius: 12px; text-align: center; margin-bottom: 15px;">
                    <span style="font-size: 0.7rem; color: var(--text-gray);">💰 مبلغ کل جدید</span>
                    <div id="editTotalAmount" style="font-size: 1.2rem; font-weight: bold; color: #4CD964;">0 تومان</div>
                </div>
                <button type="button" class="btn-submit" onclick="submitEditAd()" style="background: linear-gradient(135deg, #FFD700, #FFA500); color: #1a1a2e;">
                    <i class="fas fa-save"></i> ذخیره تغییرات
                </button>
                <button type="button" class="btn-submit" onclick="deleteMyAd()" style="margin-top: 10px; background: linear-gradient(135deg, #dc2626, #991b1b); color: #fff;">
                    <i class="fas fa-trash-alt"></i> حذف آگهی
                </button>
                <button type="button" class="btn-submit" onclick="closeEditModal()" style="margin-top: 10px; background: transparent; border: 1px solid var(--glass-border);">
                    <i class="fas fa-times"></i> انصراف
                </button>
            </form>
        </div>
    </div>
</div>

<div id="myAdsAllModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-bullhorn"></i> آگهی‌های من</h3>
            <button class="close-modal" onclick="document.getElementById('myAdsAllModal').style.display='none';document.body.style.overflow='';">&times;</button>
        </div>
        <div class="modal-body" id="myAdsAllBody" style="max-height:70vh;overflow-y:auto;">
            <div style="text-align:center;padding:30px;"><i class="fas fa-spinner fa-spin"></i></div>
        </div>
    </div>
</div>

<div id="allOffersModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-inbox"></i> پیشنهادهای دریافتی</h3>
            <button class="close-modal" onclick="document.getElementById('allOffersModal').style.display='none';document.body.style.overflow='';">&times;</button>
        </div>
        <div class="modal-body" id="allOffersBody" style="max-height:70vh;overflow-y:auto;">
            <div style="text-align:center;padding:30px;"><i class="fas fa-spinner fa-spin"></i></div>
        </div>
    </div>
</div>

<div id="allHistoryModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-clock-rotate-left"></i> تاریخچه معاملات تبادل ارزی</h3>
            <button class="close-modal" onclick="document.getElementById('allHistoryModal').style.display='none';document.body.style.overflow='';">&times;</button>
        </div>
        <div class="modal-body" id="allHistoryBody" style="max-height:70vh;overflow-y:auto;">
            <div style="text-align:center;padding:30px;"><i class="fas fa-spinner fa-spin"></i></div>
        </div>
    </div>
</div>

<div id="offerModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-gavel"></i> ارسال پیشنهاد</h3>
            <button class="close-modal" onclick="closeOfferModal()">&times;</button>
        </div>
        <div class="modal-body">
            <!-- نشانگر مراحل -->
            <div style="display:flex;align-items:center;justify-content:center;gap:8px;margin-bottom:16px;">
                <div id="offerStepDot1" class="offer-step-dot active" style="display:flex;flex-direction:column;align-items:center;gap:4px;">
                    <div style="width:34px;height:34px;border-radius:50%;background:#6C40C5;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;">۱</div>
                    <span style="font-size:.62rem;">مقدار و قیمت</span>
                </div>
                <div style="width:40px;height:2px;background:rgba(255,255,255,0.15);"></div>
                <div id="offerStepDot2" class="offer-step-dot" style="display:flex;flex-direction:column;align-items:center;gap:4px;opacity:.5;">
                    <div style="width:34px;height:34px;border-radius:50%;background:rgba(255,255,255,0.1);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;">۲</div>
                    <span style="font-size:.62rem;">تایید نهایی</span>
                </div>
            </div>

            <div id="offerAdPreview" style="background: rgba(108,64,197,0.1); padding: 12px; border-radius: 12px; margin-bottom: 15px;"></div>

            <form id="offerForm" onsubmit="return false;">
                <input type="hidden" id="offerAdId">
                <input type="hidden" id="offerCurrency" value="">
                <input type="hidden" id="offerAdType" value="">

                <!-- مرحله ۱ -->
                <div id="offerStep1">
                    <div class="form-group">
                        <input type="number" id="offerAmount" class="form-control" step="0.01" min="0.01" required placeholder="مقدار درخواستی: 1000">
                    </div>
                    <div class="form-group">
                        <input type="number" id="offerPrice" class="form-control" step="100" min="100" required placeholder="قیمت پیشنهادی (تومان): 70000">
                    </div>
                    <div id="offerTotalPreview" style="background: rgba(76,217,100,0.1); padding: 10px; border-radius: 12px; text-align: center; margin-bottom: 12px;">
                        <span style="font-size: 0.7rem;">💰 مبلغ کل پیشنهادی</span>
                        <div id="offerTotalAmount" style="font-size: 1rem; font-weight: bold; color: #4CD964;">0 تومان</div>
                    </div>
                    <div class="form-group">
                        <textarea id="offerMessage" class="form-control" rows="3" placeholder="پیام: مثلا سلام من آماده معامله هستم."></textarea>
                    </div>
                    <button type="button" class="btn-submit" onclick="offerGoToStep2()"><i class="fas fa-arrow-left"></i> ادامه</button>
                </div>

                <!-- مرحله ۲: خلاصه و تایید -->
                <div id="offerStep2" style="display:none;">
                    <div style="background:rgba(0,0,0,0.25);border-radius:14px;padding:14px;margin-bottom:12px;">
                        <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid rgba(255,255,255,0.08);">
                            <span style="color:rgba(255,255,255,0.6);font-size:.78rem;">نقش شما:</span>
                            <span id="offerRoleLabel" style="font-weight:700;font-size:.8rem;">-</span>
                        </div>
                        <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid rgba(255,255,255,0.08);">
                            <span style="color:rgba(255,255,255,0.6);font-size:.78rem;">مقدار:</span>
                            <span id="offerSummaryAmount" style="font-weight:700;font-size:.8rem;">-</span>
                        </div>
                        <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid rgba(255,255,255,0.08);">
                            <span style="color:rgba(255,255,255,0.6);font-size:.78rem;">قیمت هر واحد:</span>
                            <span id="offerSummaryPrice" style="font-weight:700;font-size:.8rem;">-</span>
                        </div>
                        <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid rgba(255,255,255,0.08);">
                            <span style="color:rgba(255,255,255,0.6);font-size:.78rem;">مبلغ کل:</span>
                            <span id="offerSummaryTotal" style="font-weight:700;font-size:.8rem;color:#4CD964;">-</span>
                        </div>
                    </div>

                    <div id="commissionPreview" style="background: rgba(255,215,0,0.08); padding: 12px; border-radius: 12px; margin-bottom: 12px;">
                        <div style="font-size: 0.72rem; margin-bottom: 8px; text-align:center;"><i class="fas fa-percent"></i> جزئیات کمیسیون</div>
                        <div style="display:flex;justify-content:space-between;padding:4px 0;">
                            <span style="font-size:.72rem;color:rgba(255,255,255,0.6);">کمیسیون پایه:</span>
                            <span><span id="baseCommissionSpan" style="font-weight:bold;">0</span> <span id="baseCommissionCurrencySpan"></span></span>
                        </div>
                        <div style="display:flex;justify-content:space-between;padding:4px 0;">
                            <span style="font-size:.72rem;color:rgba(255,255,255,0.6);">تخفیف شما:</span>
                            <span id="userDiscountSpan" style="font-weight:bold;color:#FFD700;"><?php echo $userDiscount; ?>%</span>
                        </div>
                        <div style="display:flex;justify-content:space-between;padding:4px 0;border-top:1px solid rgba(255,255,255,0.1);margin-top:4px;padding-top:8px;">
                            <span style="font-size:.72rem;color:rgba(255,255,255,0.6);">کمیسیون نهایی:</span>
                            <span><span id="finalCommissionSpan" style="font-weight:bold;color:#FFD700;">0</span> <span id="finalCommissionCurrencySpan"></span></span>
                        </div>
                        <div id="commissionDirectionNote" style="margin-top:10px;padding:8px;border-radius:10px;background:rgba(0,0,0,0.25);font-size:.7rem;text-align:center;"></div>
                        <div style="display:flex;justify-content:space-between;padding:8px 0 0;margin-top:6px;">
                            <span style="font-size:.78rem;font-weight:700;" id="offerNetLabel">مبلغ نهایی:</span>
                            <span><span id="finalAmount" style="font-weight:bold;color:#4CD964;font-size:.95rem;">0</span> <span id="finalAmountCurrencySymbol">تومان</span></span>
                        </div>
                        <span id="commissionAmount" style="display:none;"></span><span id="commissionCurrencySymbol" style="display:none;"></span>
                    </div>

                    <div style="display:flex;gap:10px;">
                        <button type="button" class="btn-submit" style="flex:0 0 40%;background:rgba(255,255,255,0.08);" onclick="offerBackToStep1()"><i class="fas fa-arrow-right"></i> قبلی</button>
                        <button type="button" class="btn-submit" style="flex:1;" onclick="sendOffer()"><i class="fas fa-paper-plane"></i> ارسال پیشنهاد</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="rejectModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-times-circle"></i> رد پیشنهاد</h3>
            <button class="close-modal" onclick="closeRejectModal()">&times;</button>
        </div>
        <div class="modal-body">
            <textarea id="rejectReason" class="form-control" rows="4" placeholder="لطفاً دلیل رد پیشنهاد را وارد کنید..."></textarea>
            <div style="display: flex; gap: 8px; margin-top: 12px;">
                <button class="btn-submit" onclick="submitReject()" style="background: #FF3B30;">تأیید و ارسال</button>
                <button class="btn-submit" onclick="closeRejectModal()" style="background: transparent;">انصراف</button>
            </div>
        </div>
    </div>
</div>

<!-- ==================== مدال جزئیات (پیشنهاد/معامله) ==================== -->
<div id="detailModal" class="modal-overlay av-scope">
    <div class="modal-content dm-content">
        <div class="modal-header">
            <h3 id="detailModalTitle"><i class="fas fa-info-circle"></i> جزئیات</h3>
            <button class="close-modal" onclick="closeDetailModal()">&times;</button>
        </div>
        <div class="modal-body" id="detailModalBody"></div>
    </div>
</div>

<!-- ==================== مدال «مشاهده همه‌ی پیشنهادها» (دریافتی/ارسالی) ==================== -->
<div id="offersListModal" class="modal-overlay av-scope">
    <div class="modal-content dm-content">
        <div class="modal-header">
            <h3 id="offersListModalTitle"><i class="fas fa-handshake"></i> پیشنهادها</h3>
            <button class="close-modal" onclick="closeOffersListModal()">&times;</button>
        </div>
        <div class="modal-body" id="offersContainer"></div>
    </div>
</div>

<!-- ==================== مدال پرداخت/تسویه‌ی معامله (کاربر) ==================== -->
<div id="userDealModal" class="modal-overlay av-scope">
    <div class="modal-content dm-content" style="max-width:480px;">
        <div class="modal-header">
            <h3 id="userDealTitle"><i class="fas fa-money-check-dollar"></i> پرداخت و تسویه معامله</h3>
            <button class="close-modal" onclick="closeUserDeal()">&times;</button>
        </div>
        <div class="modal-body" id="userDealBody"></div>
    </div>
</div>

<!-- لایت‌باکس مشاهده فیش -->
<div id="dealLightbox" onclick="closeDealLightbox(event)" style="display:none;position:fixed;inset:0;z-index:100001;background:rgba(0,0,0,.92);align-items:center;justify-content:center;flex-direction:column;padding:16px;">
    <div style="position:absolute;top:16px;left:16px;color:#fff;font-size:1.6rem;cursor:pointer;" onclick="closeDealLightbox(event,true)"><i class="fas fa-times"></i></div>
    <img id="dealLightboxImg" src="" style="max-width:96%;max-height:82%;border-radius:14px;object-fit:contain;">
    <a id="dealLightboxDl" href="#" download style="margin-top:18px;background:#FFD700;color:#000;padding:10px 26px;border-radius:30px;font-weight:700;text-decoration:none;" onclick="event.stopPropagation();"><i class="fas fa-download"></i> دانلود</a>
</div>

<!-- ==================== (آپدیت ۳) مدال تمام‌صفحه خدمات (حواله/تسویه) ==================== -->
<div class="ax-fs-modal" id="axServiceModal">
    <div class="ax-fs-head">
        <span id="axServiceModalTitle"><i class="fas fa-money-bill-transfer"></i> حواله ارزی</span>
        <button class="ax-fs-close" onclick="axCloseServiceModal()"><i class="fas fa-times"></i> بستن</button>
    </div>
    <iframe id="axServiceFrame" src="about:blank" title="خدمات مالی"></iframe>
</div>

<style>
/* --- (آپدیت ۳) منوی خدمات مالی ---
   نکته‌ی مهم: تعریف اصلیِ .axsv-grid/.axsv-item/.axsv-ic/.axsv-t از اینجا حذف شد.
   قبلاً همین کلاس‌ها اینجا و هم در assets/css/arad-nova.css (که بعد از این
   بلوک لود می‌شود) تعریف می‌شدند؛ چون هر دو یک specificity دارند، تعریف
   arad-nova.css (عرض ثابت 78px، بدون justify-content) همیشه خاموش روی این
   تعریف اینجا (عرض کشسان) غالب می‌شد و باعث می‌شد آیکون‌ها فضای کامل ردیف
   را پر نکنند و سمت چپ خالی بماند. حالا فقط یک منبع حقیقت داریم:
   assets/css/arad-nova.css — همان‌جا هم اصلاح انجام شد (چیدمان وسط‌چین وقتی
   آیکون‌ها کل عرض را پر نمی‌کنند + اسکرول لمسی افقی وقتی تعدادشان زیاد شود). */
.axsv-menu{margin:18px 0;}
.axsv-title{display:flex;align-items:center;gap:8px;color:#FFD700;font-weight:700;font-size:.85rem;margin-bottom:12px;}

/* --- کارت هیجانی تخفیف اختصاصی (کوچک و متعادل) --- */
.ax-disc-hero{
    position:relative; overflow:hidden; direction:rtl; margin-bottom:14px;
    background:linear-gradient(155deg,#2d1b3e 0%,#1a0b2e 55%,#120821 100%);
    border:1px solid rgba(255,215,0,.35); border-radius:18px; padding:12px 14px;
    animation:axHeroIn .5s ease both, axHeroBorder 3s ease-in-out infinite;
}
@keyframes axHeroIn{ from{opacity:0; transform:translateY(10px) scale(.98);} to{opacity:1; transform:translateY(0) scale(1);} }
@keyframes axHeroBorder{
    0%,100%{ border-color:rgba(255,215,0,.3); box-shadow:0 0 0 0 rgba(255,215,0,.15); }
    50%{ border-color:rgba(255,215,0,.6); box-shadow:0 0 20px 2px rgba(255,215,0,.22); }
}
.ax-disc-hero-bg{
    position:absolute; inset:0; pointer-events:none;
    background:radial-gradient(circle at 15% 20%, rgba(168,132,255,.3), transparent 55%),
               radial-gradient(circle at 85% 85%, rgba(255,215,0,.2), transparent 55%);
    animation:axHeroBgMove 8s ease-in-out infinite;
}
@keyframes axHeroBgMove{ 0%,100%{ transform:translate(0,0) rotate(0deg); } 50%{ transform:translate(-4%,4%) rotate(6deg); } }
.ax-disc-hero-shine{
    position:absolute; top:0; left:-60%; width:40%; height:100%; pointer-events:none;
    background:linear-gradient(100deg, transparent, rgba(255,255,255,.12), transparent);
    animation:axHeroShine 3.4s ease-in-out infinite;
}
@keyframes axHeroShine{ 0%{ left:-60%; } 55%,100%{ left:130%; } }
.ax-disc-hero-badge{
    position:relative; z-index:1; display:inline-flex; align-items:center; gap:5px;
    background:linear-gradient(135deg,#FFD700,#FFA500); color:#3a2200; font-weight:900; font-size:.58rem;
    padding:4px 11px; border-radius:16px; margin-bottom:10px; box-shadow:0 5px 12px -4px rgba(255,180,0,.55);
    animation:axHeroBadgePulse 2s ease-in-out infinite;
}
@keyframes axHeroBadgePulse{ 0%,100%{ transform:scale(1); } 50%{ transform:scale(1.04); } }
.ax-disc-hero-main{ position:relative; z-index:1; display:flex; align-items:center; gap:12px; }
.ax-disc-hero-gauge{ position:relative; width:76px; height:76px; flex:none; display:flex; align-items:center; justify-content:center;
    filter:drop-shadow(0 0 12px rgba(255,215,0,.32)); }
.ax-disc-hero-pct{ position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
    font-size:1.15rem; font-weight:900; color:#FFD700; text-shadow:0 0 14px rgba(255,215,0,.55);
    animation:axHeroPctPop .6s cubic-bezier(.34,1.56,.64,1) .15s both; }
.ax-disc-hero-pct span{ font-size:.7rem; margin-right:1px; }
@keyframes axHeroPctPop{ from{ transform:scale(.3); opacity:0; } to{ transform:scale(1); opacity:1; } }
.ax-disc-hero-info{ flex:1; min-width:0; }
.ax-disc-hero-title{ font-size:.76rem; font-weight:800; color:#fff; margin-bottom:4px; display:flex; align-items:center; gap:5px; }
.ax-disc-hero-title i{ color:#FF7A45; font-size:.7rem; }
.ax-disc-hero-desc{ font-size:.62rem; color:rgba(255,255,255,.6); line-height:1.5; display:flex; align-items:flex-start; gap:5px; }
.ax-disc-hero-countdown{ position:relative; z-index:1; margin-top:12px; padding-top:10px; border-top:1px dashed rgba(255,255,255,.15); }
.ax-disc-hero-cd-label{ font-size:.58rem; font-weight:800; color:#FF7A45; display:flex; align-items:center; justify-content:center;
    gap:5px; margin-bottom:7px; animation:axHeroCdBlink 1.4s ease-in-out infinite; }
@keyframes axHeroCdBlink{ 0%,100%{ opacity:1; } 50%{ opacity:.6; } }
.ax-disc-hero-cd-boxes{ display:flex; align-items:center; justify-content:center; gap:4px; }
.ax-disc-hero-cd-box{ flex:1; max-width:54px; background:rgba(0,0,0,.28); border:1px solid rgba(255,215,0,.25); border-radius:9px;
    display:flex; flex-direction:column; align-items:center; padding:5px 2px; }
.ax-disc-hero-cd-box b{ font-size:.82rem; font-weight:900; color:#FFD700; line-height:1.2; font-variant-numeric:tabular-nums; }
.ax-disc-hero-cd-box span{ font-size:.46rem; color:rgba(255,255,255,.5); margin-top:1px; }
.ax-disc-hero-cd-sep{ color:rgba(255,215,0,.5); font-weight:900; font-size:.75rem; }
.ax-disc-hero-countdown.ended .ax-disc-hero-cd-label{ color:#FF5A6E; }
.ax-disc-hero-countdown.ended .ax-disc-hero-cd-box{ border-color:rgba(255,90,110,.35); }
.ax-disc-hero-countdown.ended .ax-disc-hero-cd-box b{ color:#FF5A6E; }
.ax-disc-hero-forever{ position:relative; z-index:1; margin-top:10px; padding-top:9px; border-top:1px dashed rgba(255,255,255,.15);
    font-size:.62rem; color:#22C55E; display:flex; align-items:center; gap:5px; font-weight:700; }

/* حالت خالی: کاربر تخفیف فعالی ندارد */
.ax-disc-hero.ax-disc-hero-empty{ padding:12px 14px; border-color:rgba(255,255,255,.12); animation:axHeroIn .5s ease both; }
.ax-disc-hero-empty-row{ position:relative; z-index:1; display:flex; align-items:center; gap:10px; }
.ax-disc-hero-empty-ic{ flex:none; width:38px; height:38px; border-radius:50%; background:rgba(255,255,255,.07);
    display:flex; align-items:center; justify-content:center; font-size:.9rem; color:rgba(255,255,255,.5); }
.ax-disc-hero-empty-txt{ flex:1; min-width:0; display:flex; flex-direction:column; gap:2px; }
.ax-disc-hero-empty-txt b{ font-size:.72rem; font-weight:800; color:#fff; }
.ax-disc-hero-empty-txt span{ font-size:.6rem; color:rgba(255,255,255,.5); line-height:1.5; }

@media (max-width:400px){
    .ax-disc-hero-cd-boxes{ gap:3px; }
    .ax-disc-hero-cd-box{ padding:4px 1px; }
}

.ax-services-menu{margin:18px 0;}
.ax-services-title{display:flex;align-items:center;gap:8px;color:#FFD700;font-weight:700;font-size:.95rem;margin-bottom:12px;}
.ax-services-title i{font-size:.9rem;}
.ax-services-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
@media(max-width:520px){.ax-services-grid{grid-template-columns:1fr;}}
.ax-service-btn{display:flex;align-items:center;gap:12px;padding:14px;border-radius:18px;
    background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);cursor:pointer;
    transition:transform .15s ease,background .2s ease,border-color .2s ease;width:100%;text-align:right;font-family:inherit;}
.ax-service-btn:hover{transform:translateY(-2px);background:rgba(255,255,255,0.07);border-color:rgba(255,215,0,0.35);}
.ax-service-btn:active{transform:scale(.98);}
.ax-service-ic{width:46px;height:46px;min-width:46px;border-radius:14px;display:flex;align-items:center;
    justify-content:center;color:#fff;font-size:1.15rem;box-shadow:0 6px 16px rgba(0,0,0,0.25);}
.ax-service-txt{display:flex;flex-direction:column;gap:3px;flex:1;}
.ax-service-t{color:#fff;font-weight:700;font-size:.9rem;}
.ax-service-s{color:rgba(255,255,255,0.5);font-size:.68rem;}
.ax-service-arrow{color:rgba(255,255,255,0.35);font-size:.8rem;}
/* --- مدال تمام‌صفحه خدمات --- */
.ax-fs-modal{position:fixed;inset:0;z-index:99999;background:var(--ava-bg,#0b0b16);
    display:none;flex-direction:column;}
.ax-fs-modal.open{display:flex;}
.ax-fs-head{display:flex;align-items:center;justify-content:space-between;padding:calc(env(safe-area-inset-top) + 14px) 16px 14px;
    background:rgba(0,0,0,0.35);border-bottom:1px solid rgba(255,255,255,0.08);}
.ax-fs-head>span{color:#fff;font-weight:700;font-size:.95rem;display:flex;align-items:center;gap:8px;}
.ax-fs-head>span i{color:#FFD700;}
.ax-fs-close{background:rgba(255,255,255,0.08);color:#fff;border:none;border-radius:12px;
    padding:8px 14px;font-family:inherit;font-size:.8rem;cursor:pointer;display:flex;align-items:center;gap:6px;}
.ax-fs-close:hover{background:rgba(255,90,110,0.25);}
.ax-fs-modal iframe{flex:1;width:100%;border:0;background:var(--ava-bg,#0b0b16);}
</style>

<?php require_once 'includes/footer_menu.php'; renderFooterMenu('arad'); ?>

<script>
/* ==================== (آپدیت ۳) باز/بسته کردن مدال خدمات مالی ==================== */
function axOpenServiceModal(kind){
    var modal = document.getElementById('axServiceModal');
    var frame = document.getElementById('axServiceFrame');
    var title = document.getElementById('axServiceModalTitle');
    if(!modal || !frame) return;
    if(kind === 'settlement'){
        title.innerHTML = '<i class="fas fa-hand-holding-dollar"></i> تسویه حساب';
        frame.src = 'includes/withdrawal_modal_system.php?embed=1&new=1';
    } else if(kind === 'topup'){
        title.innerHTML = '<i class="fas fa-wallet"></i> شارژ سریع کیف';
        frame.src = 'dashboard.php?open=wallet';
    } else if(kind === 'transactions'){
        title.innerHTML = '<i class="fas fa-receipt"></i> تراکنش‌ها';
        frame.src = 'transactions.php?embed=1';
    } else if(kind === 'directbuy'){
        title.innerHTML = '<i class="fas fa-bolt"></i> خرید مستقیم';
        frame.src = 'direct_buy.php?embed=1';
    } else if(kind === 'profile'){
        title.innerHTML = '<i class="fas fa-user"></i> پروفایل';
        frame.src = 'profile.php?embed=1';
    } else {
        title.innerHTML = '<i class="fas fa-money-bill-transfer"></i> حواله ارزی';
        frame.src = 'money_transfer.php?embed=1&new=1';
    }
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
    // (اصلاح) تا کشیدن انگشت از چپ به راست (ژست بازگشتِ لبه‌ای سیستم‌عامل/مرورگر)
    // این مودال را ببندد، نه هیچ‌کاری نکند — همان تکنیک dashboard.php:
    // باز شدن یک state تاریخچه push می‌کند تا popstate (که آن ژست تولید می‌کند)
    // چیزی برای گرفتن داشته باشد.
    try { history.pushState({avaLayer:true}, ''); } catch(e){}
}
function axCloseServiceModal(){
    var modal = document.getElementById('axServiceModal');
    var frame = document.getElementById('axServiceFrame');
    if(modal) modal.classList.remove('open');
    if(frame) frame.src = 'about:blank';
    document.body.style.overflow = '';
    // بروزرسانی لیست معاملات پس از بستن
    try { if (typeof loadActiveDeals === 'function') loadActiveDeals(); } catch(e){}
    if (!window.__axHandlingPopstate && history.state && history.state.avaLayer) {
        try { history.back(); } catch(e){}
    }
}
window.addEventListener('popstate', function(){
    var modal = document.getElementById('axServiceModal');
    if (!modal || !modal.classList.contains('open')) return;
    window.__axHandlingPopstate = true;
    try { axCloseServiceModal(); } finally { window.__axHandlingPopstate = false; }
});
/* اجازه بده iframeها بتوانند مدال والد را ببندند (مثل dashboard.avaCloseFsModal) */
window.avaCloseFsModal = window.avaCloseFsModal || function(id){ axCloseServiceModal(); };
</script>

<script src="assets/js/notification-system.js"></script>
<script src="assets/js/push-init.js"></script>

<script>
/* ==================== پشتیبانی (چت متصل به api/support.php) ==================== */
(function(){
    let axSupLastId = 0;
    let axSupTimer = null;
    let axSupPollTimer = null;

    function axSupEsc(t){ const d=document.createElement('div'); d.textContent = t||''; return d.innerHTML; }

    async function axSupLoadChat(mark){
        try{
            const r = await fetch(`api/support.php?action=get_messages&last_id=${axSupLastId}${mark ? '&mark=1' : ''}`, { cache:'no-store' });
            const d = await r.json();
            const box = document.getElementById('axSupportChatList');
            if (!box) return;
            if (d.messages && d.messages.length){
                if (axSupLastId === 0) box.innerHTML = '';
                d.messages.forEach(m=>{
                    axSupLastId = Math.max(axSupLastId, parseInt(m.id));
                    const el = document.createElement('div');
                    el.className = 'ax-sup-msg ' + (m.sender === 'admin' ? 'admin' : 'user');
                    let inner = m.message ? axSupEsc(m.message) : '';
                    if (m.file_path) inner += `<br><a href="${m.file_path}" target="_blank" class="ax-sup-file"><i class="fas fa-paperclip"></i> ${axSupEsc(m.file_name || 'فایل')}</a>`;
                    el.innerHTML = inner;
                    box.appendChild(el);
                });
                box.scrollTop = box.scrollHeight;
            } else if (axSupLastId === 0){
                box.innerHTML = '<div style="text-align:center;color:rgba(255,255,255,.4);font-size:.72rem;padding:20px 0;">هنوز پیامی ندارید. اولین پیام را بفرستید.</div>';
            }
        }catch(e){}
    }

    async function axSupCheckUnread(){
        try{
            const r = await fetch('api/support.php?action=unread_count', { cache:'no-store' });
            const d = await r.json();
            const unread = (d && d.unread) ? parseInt(d.unread, 10) : 0;
            const dot = document.getElementById('axSupportDot');
            const badge = document.getElementById('axSupportBadge');
            if (dot) dot.style.display = 'none'; // نقطه‌ی ساده جای خودش را به بج شماره‌دار داد
            if (badge) {
                if (unread > 0) {
                    badge.textContent = unread > 9 ? '9+' : String(unread);
                    badge.style.display = 'flex';
                } else {
                    badge.style.display = 'none';
                }
            }
        }catch(e){}
    }

    window.axOpenSupport = function(){
        const modal = document.getElementById('axSupportModal');
        if (!modal) return;
        modal.style.display = 'flex';
        axSupLoadChat(true);
        if (!axSupTimer) axSupTimer = setInterval(function(){ axSupLoadChat(true); }, 7000);
        const dot = document.getElementById('axSupportDot');
        if (dot) dot.style.display = 'none';
        const badge = document.getElementById('axSupportBadge');
        if (badge) badge.style.display = 'none';
    };
    window.axCloseSupport = function(){
        const modal = document.getElementById('axSupportModal');
        if (modal) modal.style.display = 'none';
        if (axSupTimer) { clearInterval(axSupTimer); axSupTimer = null; }
    };
    window.axSupportFilePicked = function(){
        const f = document.getElementById('axSupportFile').files;
        document.getElementById('axSupportFileInfo').textContent = f.length ? f[0].name : '';
    };
    window.axSendSupport = async function(){
        const input = document.getElementById('axSupportText');
        const fileEl = document.getElementById('axSupportFile');
        const text = input.value.trim();
        if (!text && !fileEl.files.length) return;
        const fd = new FormData();
        fd.append('message', text);
        if (fileEl.files.length) fd.append('file', fileEl.files[0]);
        input.value = '';
        document.getElementById('axSupportFileInfo').textContent = '';
        const hasFile = fileEl.files.length > 0;
        if (hasFile && typeof window.avaShowUploadProgress === 'function') window.avaShowUploadProgress('در حال آپلود فایل...');
        try{
            if (hasFile && typeof window.avaUploadWithProgress === 'function') {
                await window.avaUploadWithProgress('api/support.php?action=send_message', fd);
                if (typeof window.avaHideUploadProgress === 'function') window.avaHideUploadProgress(true, 'فایل ارسال شد ✅');
            } else {
                await fetch('api/support.php?action=send_message', { method:'POST', body: fd });
            }
            fileEl.value = '';
            axSupLoadChat(true);
        }catch(e){ if (hasFile && typeof window.avaHideUploadProgress === 'function') window.avaHideUploadProgress(false); }
    };

    // بررسی دوره‌ای پیام خوانده‌نشده حتی وقتی مودال بسته است
    axSupCheckUnread();
    axSupPollTimer = setInterval(axSupCheckUnread, 20000);
})();

/* ==================== شمارش معکوس تا پایان تخفیف ==================== */
(function(){
    var box = document.getElementById('axDiscCountdown');
    if (!box) return;
    var expiresAt = new Date(box.getAttribute('data-expires'));
    var elD = document.getElementById('axDiscCdD');
    var elH = document.getElementById('axDiscCdH');
    var elM = document.getElementById('axDiscCdM');
    var elS = document.getElementById('axDiscCdS');
    var faDigits = function(n){ return Number(n).toLocaleString('fa-IR', { minimumIntegerDigits: 2 }); };
    var timerId = null;
    function tick(){
        var diff = expiresAt.getTime() - Date.now();
        if (diff <= 0) {
            box.classList.add('ended');
            box.querySelector('.ax-disc-hero-cd-label').innerHTML = '<i class="fas fa-circle-exclamation"></i> اعتبار تخفیف شما به پایان رسیده است';
            if (elD) elD.textContent = '۰۰';
            if (elH) elH.textContent = '۰۰';
            if (elM) elM.textContent = '۰۰';
            if (elS) elS.textContent = '۰۰';
            if (timerId) clearInterval(timerId);
            return;
        }
        var days    = Math.floor(diff / 86400000);
        var hours   = Math.floor((diff % 86400000) / 3600000);
        var minutes = Math.floor((diff % 3600000) / 60000);
        var seconds = Math.floor((diff % 60000) / 1000);
        if (elD) elD.textContent = faDigits(days);
        if (elH) elH.textContent = faDigits(hours);
        if (elM) elM.textContent = faDigits(minutes);
        if (elS) elS.textContent = faDigits(seconds);
    }
    tick();
    timerId = setInterval(tick, 1000);
})();

/* ==================== نمودار حلقه‌ای درصد تخفیف ==================== */
(function(){
    var canvas = document.getElementById('axDiscGauge');
    if (canvas && typeof Chart !== 'undefined') {
        var p = <?php echo (float)$userDiscount; ?>;
        p = Math.max(0, Math.min(100, p));
        new Chart(canvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                datasets: [{
                    data: [p, 100 - p],
                    backgroundColor: ['#FFD700', 'rgba(255,255,255,.08)'],
                    borderWidth: 0
                }]
            },
            options: {
                cutout: '74%',
                rotation: -90,
                circumference: 360,
                plugins: { legend: { display: false }, tooltip: { enabled: false } },
                animation: { duration: 900, easing: 'easeOutCubic' }
            }
        });
    }
})();
</script>

<script>
// ==================== متغیرها ====================
let pollingInterval = null;
let lastReceivedCount = <?php echo $pendingReceivedCount; ?>;
let lastSentCount = <?php echo $pendingSentCount; ?>;
let currentRejectOfferId = null;
let modalAdType = 'buy';
let currentOffersTab = 'received';
let allReceivedOffers = [];
let allSentOffers = [];
// نگاشت شناسه‌ی کاربر → تعداد معاملات تکمیل‌شده و شناسه‌ی آگهی → مالک آگهی (برای نمایش «تعداد معامله» در کارت پیشنهادها)
window.__AV_TRADE_COUNTS__ = <?php echo json_encode($tradeCountMap, JSON_UNESCAPED_UNICODE); ?>;
window.__AV_AD_OWNERS__ = <?php echo json_encode($adOwnerMap, JSON_UNESCAPED_UNICODE); ?>;
// شناسه‌ی پیشنهادهایی که یک‌بار در کارت فشرده «پیشنهادهای دریافتی» رندر شده‌اند
// (برای تشخیص واقعاً-جدید بودن و نمایش افکت چشمگیر روی آن‌ها)
let axsSeenOfferIds = new Set(<?php echo json_encode(array_column($lastReceivedOffers, 'id')); ?>);
let isAdmin = <?php echo $isAdmin ? 'true' : 'false'; ?>;
let selectedUserId = null;
let lastAdCount = 0;

// ==================== وضعیت بازار آگهی‌ها (AvaPay Market) ====================
let axmTab = 'all';          // نوع آگهی: all | buy | sell (از پنل فیلتر تغییر می‌کند)
let axmCurrency = 'all';     // فیلتر ارز فعال (پیش‌فرض: نمایش همه ارزها)
let axmSortMode = 'new';     // new | price_high | price_low
let axmAdsCache = [];        // آخرین لیست آگهی‌های دریافتی (برای مرتب‌سازی/جستجوی سمت کلاینت)
let lastAllAdsCount = null;  // شمارش کل آگهی‌های بازار (بدون فیلتر) فقط برای تشخیص آگهی جدید در پولینگ
const AXM_CUR = {
    USDT:{ ic:'T', color:'#26A17B', name:'تتر' },
    USD: { ic:'$', color:'#22C55E', name:'دلار' },
    EUR: { ic:'€', color:'#38BDF8', name:'یورو' },
    IRR: { ic:'﷼', color:'#FFD700', name:'تومان' }
};

const USER_DISCOUNT = <?php echo $userDiscount; ?>;

function formatNumberCompactJS(number) {
    if (number >= 1000000000) return (number / 1000000000).toFixed(1) + 'B';
    else if (number >= 1000000) return (number / 1000000).toFixed(1) + 'M';
    else if (number >= 1000) return (number / 1000).toFixed(1) + 'K';
    else return number.toString();
}

function showToast(message, type) {
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i> ${message}`;
    document.body.appendChild(toast);
    setTimeout(() => toast.classList.add('show'), 10);
    setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 300); }, 3500);
}

function animateNumber(element, start, end, duration, isCompact = false) {
    if (!element) return;
    const range = end - start;
    if (range === 0) return;
    const increment = range / (duration / 16);
    let current = start;
    const timer = setInterval(() => {
        current += increment;
        if ((increment > 0 && current >= end) || (increment < 0 && current <= end)) {
            clearInterval(timer);
            current = end;
        }
        if (isCompact) element.innerText = formatNumberCompactJS(Math.floor(current));
        else element.innerText = Math.floor(current).toLocaleString('fa-IR');
    }, 16);
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function playNotificationSound() {
    try {
        const AudioContext = window.AudioContext || window.webkitAudioContext;
        const ctx = new AudioContext();
        const oscillator = ctx.createOscillator();
        const gain = ctx.createGain();
        oscillator.connect(gain);
        gain.connect(ctx.destination);
        oscillator.frequency.value = 880;
        oscillator.type = 'sine';
        gain.gain.setValueAtTime(0.2, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.5);
        oscillator.start(ctx.currentTime);
        oscillator.stop(ctx.currentTime + 0.5);
    } catch(e) {}
}

// ==================== توابع ادمین ====================


async function deleteAdByAdmin(adId) {
    if (!confirm('⚠️ آیا از حذف این آگهی اطمینان دارید؟\nاین عملیات غیرقابل بازگشت است.')) return;
    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    try {
        const response = await fetch('api/ads_api.php?action=delete', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ad_id: adId })
        });
        const result = await response.json();
        if (result.success) { showToast(result.message, 'success'); loadAds(); }
        else { showToast(result.message, 'error'); }
    } catch(e) { showToast('خطا در حذف آگهی', 'error'); }
    finally { btn.disabled = false; btn.innerHTML = originalText; }
}

// ==================== بارگذاری آگهی‌ها ====================
async function loadAds() {
    const container = document.getElementById('adsContainer');
    container.innerHTML = '<div class="axm-skel"></div><div class="axm-skel"></div><div class="axm-skel"></div>';
    try {
        // نگاشت مستقیم: تب «آگهی‌های خرید» دقیقاً آگهی‌های type=buy را نشان می‌دهد.
        // قبلاً اینجا معکوس می‌شد و باعث می‌شد آگهی خریدی که کاربر ثبت می‌کند
        // زیر تب «فروش» ظاهر شود و برعکس.
        const apiType = axmTab;
        const curPart = (axmCurrency && axmCurrency !== 'all') ? `&currency=${encodeURIComponent(axmCurrency)}` : '';
        const response = await fetch(`api/ads_api.php?action=list&limit=30&type=${apiType}${curPart}`);
        const result = await response.json();
        if (result.success && result.ads && result.ads.length > 0) {
            if (lastAdCount > 0 && result.ads.length > lastAdCount) {
                showToast(`📢 ${result.ads.length - lastAdCount} آگهی جدید ثبت شد!`, 'success');
            }
            lastAdCount = result.ads.length;
            axmAdsCache = result.ads;
            axmApplySort();
        } else {
            axmAdsCache = [];
            container.innerHTML = '<div class="arn-empty"><i class="fas fa-box-open"></i>هیچ آگهی فعالی در این بخش موجود نیست</div>';
            lastAdCount = 0;
        }
    } catch(e) { 
        console.error(e);
        container.innerHTML = '<div class="arn-empty"><i class="fas fa-triangle-exclamation"></i>خطا در بارگذاری آگهی‌ها</div>'; 
    }
}

// ==================== تب خرید/فروش ====================
function axmSetType(type) {
    axmTab = type;
    lastAdCount = 0;
    document.getElementById('axmFilterPanel')?.classList.remove('open');
    loadAds();
}
// سازگاری با کدهای قدیمی‌تر که هنوز این نام را صدا می‌زنند
function axmSwitchTab(type) { axmSetType(type); }

// ==================== انتخاب ارز ====================
function axmToggleCurrencyMenu(e) {
    e.stopPropagation();
    document.getElementById('axmCurMenu').classList.toggle('open');
    document.getElementById('axmFilterPanel')?.classList.remove('open');
}
function axmSetCurrency(code, label, color) {
    axmCurrency = code;
    lastAdCount = 0;
    const lbl = document.getElementById('axmCurLabel'); if (lbl) lbl.textContent = label;
    const dot = document.getElementById('axmCurDot');
    if (dot) { dot.textContent = code === 'all' ? '∀' : (AXM_CUR[code]?.ic || '؟'); dot.style.background = color; }
    document.getElementById('axmCurMenu')?.classList.remove('open');
    loadAds();
}

// ==================== فیلتر/مرتب‌سازی ====================
function axmToggleFilterPanel(e) {
    e.stopPropagation();
    document.getElementById('axmFilterPanel').classList.toggle('open');
    document.getElementById('axmCurMenu')?.classList.remove('open');
}
function axmSort(mode) {
    axmSortMode = mode;
    document.getElementById('axmFilterPanel').classList.remove('open');
    axmApplySort();
}
function axmApplySort() {
    let list = axmAdsCache.slice();
    if (axmSortMode === 'price_high') list.sort((a,b) => b.price_per_unit - a.price_per_unit);
    else if (axmSortMode === 'price_low') list.sort((a,b) => a.price_per_unit - b.price_per_unit);
    else list.sort((a,b) => b.id - a.id);
    displayAdsGrid(list);
}
document.addEventListener('click', function(){
    document.getElementById('axmCurMenu')?.classList.remove('open');
    document.getElementById('axmFilterPanel')?.classList.remove('open');
});

// ==================== مدال «مشاهده همه‌ی آگهی‌های من» ====================
async function axsShowAllMyAds() {
    const modal = document.getElementById('myAdsAllModal');
    const body = document.getElementById('myAdsAllBody');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    body.innerHTML = '<div style="text-align:center;padding:30px;"><i class="fas fa-spinner fa-spin"></i></div>';
    try {
        const res = await fetch('api/ads_api.php?action=my_ads&status=all');
        const result = await res.json();
        if (!result.success || !result.ads || result.ads.length === 0) {
            body.innerHTML = '<div class="axs-empty-mini" style="padding:30px;">هنوز آگهی‌ای ثبت نکرده‌اید</div>';
            return;
        }
        const stLbl  = { active: 'فعال', completed: 'تکمیل‌شده', cancelled: 'لغو شده' };
        const stCol  = { active: '#22C55E', completed: '#38BDF8', cancelled: '#FF5A6E' };
        body.innerHTML = result.ads.map(ad => {
            const isBuy = ad.type === 'buy';
            const desc  = ad.description || '';
            return `
            <div class="axs-modal-row">
                <div class="amr-ic" style="background:${isBuy ? 'rgba(124,58,237,.18)' : 'rgba(245,158,11,.18)'};color:${isBuy ? '#A855F7' : '#F59E0B'};">
                    <i class="fas fa-${isBuy ? 'cart-shopping' : 'tag'}"></i>
                </div>
                <div class="amr-body">
                    <div class="amr-t">${isBuy ? 'خرید' : 'فروش'} ${escapeHtml(ad.currency)}</div>
                    <div class="amr-s">${ad.formatted_amount || Number(ad.amount).toLocaleString()} در قیمت ${ad.formatted_price || Number(ad.price_per_unit).toLocaleString()} تومان ·
                        <span style="color:${stCol[ad.status] || '#94A3B8'};">${stLbl[ad.status] || ad.status}</span></div>
                </div>
                <button type="button" class="amr-edit" onclick="document.getElementById('myAdsAllModal').style.display='none';document.body.style.overflow='';openEditModal(${ad.id}, '${escapeHtml(ad.currency)}', ${ad.amount}, ${ad.price_per_unit}, '${escapeHtml(desc)}')"><i class="fas fa-pen"></i> ویرایش</button>
            </div>`;
        }).join('');
    } catch(e) {
        body.innerHTML = '<div class="axs-empty-mini" style="padding:30px;">خطا در بارگذاری آگهی‌ها</div>';
    }
}

// ==================== مدال «مشاهده همه‌ی پیشنهادهای دریافتی» ====================
async function axsShowAllOffers() {
    const modal = document.getElementById('allOffersModal');
    const body = document.getElementById('allOffersBody');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    body.innerHTML = '<div style="text-align:center;padding:30px;"><i class="fas fa-spinner fa-spin"></i></div>';
    try {
        const res = await fetch('api/offer_api.php?action=get_my_received_offers&limit=50');
        const result = await res.json();
        if (!result.success || !result.offers || result.offers.length === 0) {
            body.innerHTML = '<div class="axs-empty-mini" style="padding:30px;">پیشنهادی دریافت نشده است</div>';
            return;
        }
        const stLbl = { pending: 'در انتظار', accepted: 'پذیرفته شده', rejected: 'رد شده' };
        const stCol = { pending: '#FBBF24', accepted: '#22C55E', rejected: '#FF5A6E' };
        const rowHtml = (o) => {
            const name = `${o.buyer_first_name || ''} ${o.buyer_last_name || ''}`.trim() || 'کاربر';
            const total = (o.requested_amount * o.offered_price).toLocaleString();
            const actions = o.status === 'pending'
                ? `<div style="display:flex;gap:6px;">
                       <button type="button" class="axs-offer-btn accept" style="flex:none;padding:6px 10px;" onclick="acceptOffer(${o.id})">قبول</button>
                       <button type="button" class="axs-offer-btn reject" style="flex:none;padding:6px 10px;" onclick="openRejectModal(${o.id})">رد</button>
                   </div>`
                : `<span style="color:${stCol[o.status] || '#94A3B8'};font-size:.68rem;font-weight:700;">${stLbl[o.status] || o.status}</span>`;
            return `
            <div class="axs-modal-row">
                <img class="amr-ic" style="object-fit:cover;background:#2a1a45;" src="${o.buyer_avatar && o.buyer_avatar.trim() !== '' ? o.buyer_avatar : 'default-avatar.png'}" alt="" loading="lazy" onerror="this.src='default-avatar.png'">
                <div class="amr-body">
                    <div class="amr-t">${escapeHtml(name)} · ${escapeHtml(o.currency)}</div>
                    <div class="amr-s">${Number(o.requested_amount).toLocaleString()} در قیمت ${Number(o.offered_price).toLocaleString()} تومان (${total} تومان)</div>
                </div>
                ${actions}
            </div>`;
        };

        const pend = result.offers.filter(o => (o.status || 'pending') === 'pending');
        const arch = result.offers.filter(o => (o.status || 'pending') !== 'pending');
        let out = '';
        out += `<div class="axs-group-t"><i class="fas fa-hourglass-half"></i> در انتظار پاسخ <span>${pend.length}</span></div>`;
        out += pend.length ? pend.map(rowHtml).join('') : '<div class="axs-empty-mini">پیشنهاد در انتظاری ندارید</div>';
        if (arch.length) {
            out += `<div class="axs-group-t archive"><i class="fas fa-box-archive"></i> آرشیو <span>${arch.length}</span></div>`;
            out += arch.map(rowHtml).join('');
        }
        body.innerHTML = out;
    } catch(e) {
        body.innerHTML = '<div class="axs-empty-mini" style="padding:30px;">خطا در بارگذاری پیشنهادها</div>';
    }
}

// ==================== مدال «مشاهده همه‌ی تاریخچه‌ی معاملات تبادل ارزی» ====================
async function axsShowAllHistory() {
    const modal = document.getElementById('allHistoryModal');
    const body = document.getElementById('allHistoryBody');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    body.innerHTML = '<div style="text-align:center;padding:30px;"><i class="fas fa-spinner fa-spin"></i></div>';
    try {
        const res = await fetch('api/deals_api.php?action=get_my_completed_deals');
        const result = await res.json();
        if (!result.success || !result.deals || result.deals.length === 0) {
            body.innerHTML = '<div class="axs-empty-mini" style="padding:30px;">هنوز معامله‌ای تکمیل نشده است</div>';
            return;
        }
        const currentUserId = <?php echo $userId; ?>;
        body.innerHTML = result.deals.map(d => {
            const isBuyer = (d.buyer_id == currentUserId);
            const label = isBuyer ? 'خرید' : 'فروش';
            const date = new Date(d.completed_at || d.created_at).toLocaleDateString('fa-IR');
            return `
            <div class="axs-modal-row">
                <div class="amr-ic" style="background:rgba(34,197,94,.16);color:#22C55E;">
                    <i class="fas fa-${isBuyer ? 'cart-shopping' : 'tag'}"></i>
                </div>
                <div class="amr-body">
                    <div class="amr-t">${label} ${escapeHtml(d.currency)} · <span style="color:#22C55E;">موفق شد</span></div>
                    <div class="amr-s">${Number(d.total_price).toLocaleString()} تومان · ${date}</div>
                </div>
            </div>`;
        }).join('');
    } catch(e) {
        body.innerHTML = '<div class="axs-empty-mini" style="padding:30px;">خطا در بارگذاری تاریخچه</div>';
    }
}

function displayAdsGrid(ads) {
    const container = document.getElementById('adsContainer');
    container.innerHTML = '';
    const currentUserId = <?php echo $userId; ?>;

    if (!ads || ads.length === 0) {
        container.innerHTML = '<div class="arn-empty"><i class="fas fa-box-open"></i>هیچ آگهی فعالی در بازار نیست</div>';
        const c0 = document.getElementById('axmCount'); if (c0) c0.textContent = '';
        return;
    }

    const frag = document.createDocumentFragment();

    ads.forEach((ad, index) => {
        const isOwner   = (ad.user_id == currentUserId);
        const isBuyAd   = (ad.type === 'buy');           // آگهی خرید ⇒ طرف مقابل خریدار است
        const description = ad.description || '';
        const fullName  = (ad.full_name || `${ad.first_name || ''} ${ad.last_name || ''}`).trim() || 'کاربر';
        const cur       = AXM_CUR[ad.currency] || { ic:(ad.currency||'؟').charAt(0), color:'#A855F7', name: ad.currency };
        const curFa     = cur.name || ad.currency;
        const isVerified = ad.kyc_status === 'approved';
        const completed = parseInt(ad.completed_orders_count || 0, 10);
        const ratingVal = Math.min(5, (4 + Math.min(1, completed / 80))).toFixed(1);
        const amount    = Number(ad.amount);
        const price     = Number(ad.price_per_unit);
        const total     = Math.round(amount * price);
        const avatarSrc = (ad.avatar && ad.avatar.trim() !== '') ? ad.avatar : 'default-avatar.png';
        const roleLabel = isBuyAd ? 'خریدار' : 'فروشنده';

        const row = document.createElement('div');
        row.className = 'arn-row' + (isBuyAd ? ' is-buyad' : ' is-sellad');
        row.style.animationDelay = `${(index % 12) * 0.03}s`;

        // کل سطر کلیک‌پذیر است؛ دکمه‌ی جدا حذف شد تا فضا برای اطلاعات بماند
        row.onclick = isOwner
            ? function () { openEditModal(ad.id, ad.currency, ad.amount, ad.price_per_unit, description); }
            : function () { openOfferModal(ad.id, ad.type, ad.currency, ad.amount, ad.price_per_unit, fullName, description); };
        row.setAttribute('role', 'button');
        row.setAttribute('tabindex', '0');
        row.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); row.click(); } });
        row.classList.add('is-tappable');

        const deleteBtn = isAdmin ? `<button type="button" class="arn-row-del" onclick="event.stopPropagation(); deleteAdByAdmin(${ad.id})" title="حذف آگهی (ادمین)"><i class="fas fa-trash-alt"></i></button>` : '';

        row.innerHTML = `
            ${deleteBtn}
            <div class="arn-row-info">
                <img class="arn-avatar" src="${avatarSrc}" alt="" loading="lazy" onerror="this.src='default-avatar.png'">
                <div class="arn-main">
                    <div class="arn-name-row"><b>${escapeHtml(fullName)}</b>${isVerified ? '<i class="fas fa-circle-check verified" title="احراز هویت شده"></i>' : ''}</div>
                    <div class="arn-meta"><span class="star"><i class="fas fa-star"></i> ${ratingVal}</span><span>· ${completed.toLocaleString('en-US')} معامله</span></div>
                    <span class="arn-cur"><span class="dot" style="background:${cur.color}">${escapeHtml(cur.ic || curFa.charAt(0))}</span>${escapeHtml(curFa)} · ${roleLabel}</span>
                </div>
            </div>
            <div class="arn-right">
                <div class="arn-price-l">قیمت پیشنهادی</div>
                <div class="arn-price-v">${price.toLocaleString('en-US')}<span class="arn-price-unit">تومان</span></div>
                <div class="arn-amount-l">مقدار</div>
                <div class="arn-amount-v">${amount.toLocaleString('en-US')} ${escapeHtml(curFa)}</div>
                <button type="button" class="arn-action-btn">${isBuyAd ? 'بفروش' : 'بخر'}</button>
            </div>`;
        frag.appendChild(row);
    });

    container.appendChild(frag);

    const cEl = document.getElementById('axmCount');
    if (cEl) cEl.textContent = ads.length.toLocaleString('en-US') + ' آگهی';
}

// ==================== بارگذاری پیشنهادات ====================
async function loadAllOffers() {
    try {
        const receivedRes = await fetch('api/offer_api.php?action=get_my_received_offers&limit=20');
        const receivedResult = await receivedRes.json();
        const sentRes = await fetch('api/offer_api.php?action=get_my_sent_offers&limit=20');
        const sentResult = await sentRes.json();
        
        if (receivedResult.success) {
            // مرتب‌سازی نزولی بر اساس created_at (جدیدترین اول)
            allReceivedOffers = receivedResult.offers.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
            // برشِ ثابت حذف شد: فیلترِ «در انتظار» در زمان رندر انجام می‌شود
            
            const newPendingCount = receivedResult.offers.filter(o => o.status === 'pending').length;
            const badge = document.getElementById('receivedBadge');
            if (badge) {
                if (newPendingCount > 0) { 
                    badge.style.display = 'inline-block'; 
                    badge.textContent = newPendingCount;
                } else { 
                    badge.style.display = 'none'; 
                }
            }
            if (newPendingCount > lastReceivedCount) { 
                showToast('🔔 پیشنهاد جدید دریافت شد!', 'success');
                playNotificationSound();
            }
            lastReceivedCount = newPendingCount;
        }
        
        if (sentResult.success) {
            // مرتب‌سازی نزولی بر اساس created_at (جدیدترین اول)
            allSentOffers = sentResult.offers.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
            
            const newPendingCount = sentResult.offers.filter(o => o.status === 'pending').length;
            const badge = document.getElementById('sentBadge');
            if (badge) {
                if (newPendingCount > 0) { 
                    badge.style.display = 'inline-block'; 
                    badge.textContent = newPendingCount;
                } else { 
                    badge.style.display = 'none'; 
                }
            }
            lastSentCount = newPendingCount;
        }
        
        displayOffersByTab();
        renderOfferColumns();
    } catch(e) { console.error(e); }
}

// ==================== تب‌های پیشنهادها (دریافت‌شده/ارسال‌شده) ====================
let arnActiveOfferTab = 'received';
function arnSwitchOfferTab(tab) {
    arnActiveOfferTab = (tab === 'sent') ? 'sent' : 'received';
    const tabRecv = document.getElementById('arnTabRecv');
    const tabSent = document.getElementById('arnTabSent');
    const paneRecv = document.getElementById('arnOffersReceived');
    const paneSent = document.getElementById('arnOffersSent');
    if (tabRecv) tabRecv.classList.toggle('active', arnActiveOfferTab === 'received');
    if (tabSent) tabSent.classList.toggle('active', arnActiveOfferTab === 'sent');
    if (paneRecv) paneRecv.classList.toggle('active', arnActiveOfferTab === 'received');
    if (paneSent) paneSent.classList.toggle('active', arnActiveOfferTab === 'sent');
}

// ==================== رندر دو کادر کنار هم: پیشنهادهای دریافتی/ارسالی ====================
function renderOfferColumns() {
    const recvHost = document.getElementById('arnOffersReceived');
    const sentHost = document.getElementById('arnOffersSent');
    const recvCnt  = document.getElementById('arnRecvCount');
    const sentCnt  = document.getElementById('arnSentCount');
    if (!recvHost && !sentHost) return;

    // ---- دریافت‌شده: فقط در انتظار (پذیرفته/رد‌شده در «مشاهده همه» آرشیو می‌شوند) ----
    const pendingRecv = (allReceivedOffers || []).filter(o => (o.status || 'pending') === 'pending');
    if (recvCnt) {
        if (pendingRecv.length > 0) { recvCnt.style.display = ''; recvCnt.textContent = pendingRecv.length; }
        else recvCnt.style.display = 'none';
    }
    if (recvHost) {
        if (!pendingRecv.length) {
            recvHost.innerHTML = '<div class="arn-offer-empty">پیشنهاد در انتظاری ندارید</div>';
        } else {
            let anyNew = false;
            recvHost.innerHTML = pendingRecv.slice(0, 3).map(off => {
                const isNew = !axsSeenOfferIds.has(off.id);
                if (isNew) anyNew = true;
                const name  = `${off.buyer_first_name || ''} ${off.buyer_last_name || ''}`.trim() || 'کاربر';
                const avatar = off.buyer_avatar && off.buyer_avatar.trim() !== '' ? off.buyer_avatar : 'default-avatar.png';
                const offeredPrice = Number(off.offered_price || 0).toLocaleString('en-US');
                return `
                <div class="arn-offer-card${isNew ? ' is-new' : ''}" data-offer-id="${off.id}">
                    ${isNew ? '<span class="arn-new-tag"><i class="fas fa-bolt"></i> جدید</span>' : ''}
                    <div class="arn-offer-nameline">
                        <img class="arn-offer-avatar" src="${avatar}" alt="" loading="lazy" onerror="this.src='default-avatar.png'">
                        <span class="arn-offer-name">${escapeHtml(name)}</span>
                    </div>
                    <div class="arn-offer-priceline">
                        <span class="arn-offer-pricelabel">مبلغ پیشنهادی (هر ${escapeHtml(off.currency)})</span>
                        <span class="arn-offer-amt">${offeredPrice} تومان</span>
                    </div>
                    <div class="arn-offer-btns">
                        <button type="button" class="arn-offer-btn reject" onclick="openRejectModal(${off.id})"><i class="fas fa-times"></i> رد</button>
                        <button type="button" class="arn-offer-btn accept" onclick="acceptOffer(${off.id})"><i class="fas fa-check"></i> قبول</button>
                    </div>
                </div>`;
            }).join('');
            const tabRecv = document.getElementById('arnTabRecv');
            if (tabRecv) tabRecv.classList.toggle('has-new', anyNew);
        }
    }
    // بعد از رندر، همه را دیده‌شده علامت بزن (افکتِ «جدید» فقط یک‌بار نمایش داده می‌شود)
    (allReceivedOffers || []).forEach(o => axsSeenOfferIds.add(o.id));

    // ---- ارسال‌شده: همه‌ی وضعیت‌ها (با نشان وضعیت به‌جای دکمه) ----
    const sentTop = (allSentOffers || []).slice(0, 3);
    if (sentCnt) {
        const pendingSent = (allSentOffers || []).filter(o => (o.status || 'pending') === 'pending').length;
        if (pendingSent > 0) { sentCnt.style.display = ''; sentCnt.textContent = pendingSent; }
        else sentCnt.style.display = 'none';
    }
    if (sentHost) {
        if (!sentTop.length) {
            sentHost.innerHTML = '<div class="arn-offer-empty">پیشنهادی ارسال نشده است</div>';
        } else {
            // برای مدال جزئیات با کلیک روی هر کارت
            window.__offerCache = window.__offerCache || {};
            sentTop.forEach(o => { window.__offerCache[o.id] = { ...o, _tab: 'sent' }; });

            sentHost.innerHTML = sentTop.map(off => {
                const name = `${off.seller_first_name || ''} ${off.seller_last_name || ''}`.trim() || 'کاربر';
                const avatar = off.seller_avatar && off.seller_avatar.trim() !== '' ? off.seller_avatar : 'default-avatar.png';
                const offeredPrice = Number(off.offered_price || 0).toLocaleString('en-US');
                const st = off.status || 'pending';
                const stLabel = st === 'accepted' ? '<i class="fas fa-check"></i> پذیرفته شد' : (st === 'rejected' ? '<i class="fas fa-times"></i> رد شد' : '<i class="fas fa-hourglass-half"></i> در انتظار');
                return `
                <div class="arn-offer-card is-clickable" data-offer-id="${off.id}" onclick="openOfferDetail(${off.id})">
                    <div class="arn-offer-nameline">
                        <img class="arn-offer-avatar" src="${avatar}" alt="" loading="lazy" onerror="this.src='default-avatar.png'">
                        <span class="arn-offer-name">${escapeHtml(name)}</span>
                    </div>
                    <div class="arn-offer-priceline">
                        <span class="arn-offer-pricelabel">مبلغ پیشنهادی (هر ${escapeHtml(off.currency)})</span>
                        <span class="arn-offer-amt">${offeredPrice} تومان</span>
                    </div>
                    <div class="arn-offer-btns">
                        <button type="button" class="arn-offer-btn status">${stLabel}</button>
                    </div>
                </div>`;
            }).join('');
        }
    }
}



function formatDatePersian(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    return date.toLocaleDateString('fa-IR', { year: 'numeric', month: '2-digit', day: '2-digit' });
}

// ==================== مدال «مشاهده همه‌ی پیشنهادها» ====================
function openOffersListModal(tab) {
    currentOffersTab = tab === 'sent' ? 'sent' : 'received';
    const titleEl = document.getElementById('offersListModalTitle');
    if (titleEl) {
        titleEl.innerHTML = currentOffersTab === 'received'
            ? '<i class="fas fa-inbox"></i> همه‌ی پیشنهادهای دریافتی'
            : '<i class="fas fa-paper-plane"></i> همه‌ی پیشنهادهای ارسالی';
    }
    displayOffersByTab();
    const modal = document.getElementById('offersListModal');
    if (modal) modal.style.display = 'flex';
}
function closeOffersListModal() {
    const modal = document.getElementById('offersListModal');
    if (modal) modal.style.display = 'none';
}

async function loadOfferCommissionInfo(offerId){
    const box = document.getElementById('axOfferComm-' + offerId);
    if (!box) return;
    try{
        const res = await fetch('api/offer_api.php?action=offer_commission_info&offer_id=' + offerId, { cache: 'no-store' });
        const d = await res.json();
        if (!d || !d.success) return;
        const unitLabel = d.commission_unit === 'IRR' ? 'تومان' : d.commission_unit;
        const commEl = box.querySelector('.axOfferCommVal');
        const discEl = box.querySelector('.axOfferDiscVal');
        if (commEl) commEl.innerHTML = Number(d.final_commission).toLocaleString('fa-IR') + ' ' + unitLabel;
        if (discEl) discEl.innerHTML = d.discount_percent > 0 ? (Number(d.discount_percent).toLocaleString('fa-IR') + '%') : 'ندارد';
        box.style.display = 'flex';
    }catch(e){}
}

function displayOffersByTab() {
    const container = document.getElementById('offersContainer');
    if (!container) return;
    let offers = currentOffersTab === 'received' ? allReceivedOffers : allSentOffers;
    if (!offers || offers.length === 0) {
        container.innerHTML = `<div class="empty-state"><i class="fas fa-inbox"></i><p>${currentOffersTab === 'received' ? 'هیچ پیشنهادی دریافت نشده است' : 'هیچ پیشنهادی ارسال نشده است'}</p></div>`;
        return;
    }

    // نگه‌داری داده‌ها برای مدال جزئیات
    window.__offerCache = window.__offerCache || {};
    offers.forEach(o => { window.__offerCache[o.id] = { ...o, _tab: currentOffersTab }; });

    const pending  = offers.filter(o => o.status === 'pending');
    const resolved = offers.filter(o => o.status !== 'pending');

    container.innerHTML = '';

    // --- پیشنهادهای جدید (در انتظار) به‌صورت کارت کامل، بالای لیست ---
    pending.forEach((offer, index) => {
        const totalAmount = offer.requested_amount * offer.offered_price;
        const isRecv = currentOffersTab === 'received';
        const first = isRecv ? offer.buyer_first_name : offer.seller_first_name;
        const last  = isRecv ? offer.buyer_last_name  : offer.seller_last_name;
        const tg    = isRecv ? offer.buyer_telegram_id : offer.seller_telegram_id;
        const card = document.createElement('div');
        card.className = 'offer-card pending';
        card.style.animation = `fadeInUp 0.3s ease ${index * 0.05}s forwards`;
        card.innerHTML = `
            <div class="offer-new-flag"><i class="fas fa-bolt"></i> جدید</div>
            <div class="offer-header">
                <div class="offer-user">
                    <div class="offer-avatar">${(first?.charAt(0) || '')}${(last?.charAt(0) || '')}</div>
                    <div>
                        <div class="offer-user-name">${escapeHtml(first)} ${escapeHtml(last)}</div>
                        <div class="offer-user-telegram">@${escapeHtml(tg || 'نامشخص')}</div>
                    </div>
                </div>
                <span class="offer-status pending">در انتظار</span>
            </div>
            <div class="offer-details">
                <div class="offer-detail"><div class="offer-detail-label">📊 مقدار</div><div class="offer-detail-value">${Number(offer.requested_amount).toLocaleString()}</div></div>
                <div class="offer-detail"><div class="offer-detail-label">💰 قیمت</div><div class="offer-detail-value">${Number(offer.offered_price).toLocaleString()} تومان</div></div>
                <div class="offer-detail"><div class="offer-detail-label">💎 مبلغ کل</div><div class="offer-detail-value" style="color:#4CD964;">${totalAmount.toLocaleString()} تومان</div></div>
                <div class="offer-detail"><div class="offer-detail-label">📅 تاریخ</div><div class="offer-detail-value">${formatDatePersian(offer.created_at)}</div></div>
            </div>
            <div id="axOfferComm-${offer.id}" style="display:none;margin-top:8px;padding:8px 10px;background:rgba(168,132,255,.1);border:1px solid rgba(168,132,255,.25);border-radius:10px;font-size:.68rem;color:rgba(255,255,255,.7);display:flex;flex-wrap:wrap;gap:10px;">
                <span><i class="fas fa-percent" style="color:#A855F7;"></i> کمیسیون: <b class="axOfferCommVal" style="color:#FFD700;">-</b></span>
                <span><i class="fas fa-gift" style="color:#A855F7;"></i> تخفیف: <b class="axOfferDiscVal" style="color:#FFD700;">-</b></span>
            </div>
            ${offer.message ? `<div class="offer-message">${escapeHtml(offer.message)}</div>` : ''}
            ${isRecv ? `<div class="offer-actions"><button class="btn-accept" onclick="acceptOffer(${offer.id})">✅ پذیرش</button><button class="btn-reject" onclick="openRejectModal(${offer.id})">❌ رد</button></div>` : ''}
        `;
        container.appendChild(card);
        loadOfferCommissionInfo(offer.id);
    });

    // --- پیشنهادهای قبلی (پذیرفته/رد) به‌صورت ردیف فشرده و قابل‌کلیک ---
    if (resolved.length) {
        const head = document.createElement('div');
        head.className = 'compact-list-head';
        head.innerHTML = `<i class="fas fa-clock-rotate-left"></i> پیشنهادهای قبلی <span>${resolved.length}</span>`;
        container.appendChild(head);

        resolved.forEach(offer => {
            const isRecv = currentOffersTab === 'received';
            const first = isRecv ? offer.buyer_first_name : offer.seller_first_name;
            const last  = isRecv ? offer.buyer_last_name  : offer.seller_last_name;
            const total = (offer.requested_amount * offer.offered_price).toLocaleString();
            const stCls = offer.status;
            const stTxt = offer.status === 'accepted' ? 'پذیرفته شده' : 'رد شده';
            const row = document.createElement('div');
            row.className = `compact-row ${stCls}`;
            row.setAttribute('onclick', `openOfferDetail(${offer.id})`);
            row.innerHTML = `
                <div class="cr-dot"></div>
                <div class="cr-main">
                    <div class="cr-title">${escapeHtml(first)} ${escapeHtml(last)}</div>
                    <div class="cr-sub">${total} تومان · ${formatDatePersian(offer.created_at)}</div>
                </div>
                <span class="cr-status ${stCls}">${stTxt}</span>
                <i class="fas fa-chevron-left cr-arrow"></i>
            `;
            container.appendChild(row);
        });
    }
}

// ==================== مدال جزئیات پیشنهاد ====================
function openOfferDetail(id) {
    const o = (window.__offerCache || {})[id];
    if (!o) return;
    const isRecv = o._tab === 'received';
    const first = isRecv ? o.buyer_first_name : o.seller_first_name;
    const last  = isRecv ? o.buyer_last_name  : o.seller_last_name;
    const tg    = isRecv ? o.buyer_telegram_id : o.seller_telegram_id;
    const total = (o.requested_amount * o.offered_price).toLocaleString();
    const stTxt = o.status === 'accepted' ? 'پذیرفته شده' : (o.status === 'rejected' ? 'رد شده' : 'در انتظار');
    const body = document.getElementById('detailModalBody');
    document.getElementById('detailModalTitle').innerHTML = '<i class="fas fa-handshake"></i> جزئیات پیشنهاد';
    body.innerHTML = `
        <div class="dm-person"><div class="dm-avatar">${(first?.charAt(0)||'')}${(last?.charAt(0)||'')}</div><div><div class="dm-name">${escapeHtml(first)} ${escapeHtml(last)}</div><div class="dm-tg">@${escapeHtml(tg||'نامشخص')}</div></div><span class="offer-status ${o.status}" style="margin-inline-start:auto;">${stTxt}</span></div>
        <div class="dm-rows">
            <div class="dm-r"><span>مقدار</span><b>${Number(o.requested_amount).toLocaleString()}</b></div>
            <div class="dm-r"><span>قیمت واحد</span><b>${Number(o.offered_price).toLocaleString()} تومان</b></div>
            <div class="dm-r"><span>مبلغ کل</span><b style="color:#4CD964;">${total} تومان</b></div>
            <div class="dm-r"><span>تاریخ</span><b>${formatDatePersian(o.created_at)}</b></div>
            ${o.deal_code ? `<div class="dm-r"><span>کد معامله</span><b style="direction:ltr;">${escapeHtml(o.deal_code)}</b></div>` : ''}
        </div>
        ${o.message ? `<div class="offer-message" style="margin-top:12px;"><i class="fas fa-comment"></i> ${escapeHtml(o.message)}</div>` : ''}
        ${o.reject_reason ? `<div class="dm-reject"><i class="fas fa-circle-info"></i> دلیل رد: ${escapeHtml(o.reject_reason)}</div>` : ''}
    `;
    document.getElementById('detailModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closeDetailModal() {
    document.getElementById('detailModal').style.display = 'none';
    document.body.style.overflow = '';
}

// ==================== پرداخت و تسویه‌ی معامله (سمت کاربر) ====================
const DEAL_SETTLE_API = 'api/deal_settlement_api.php';
let currentUserDealId = null;

async function openUserDeal(dealId) {
    currentUserDealId = dealId;
    const modal = document.getElementById('userDealModal');
    const body = document.getElementById('userDealBody');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    body.innerHTML = '<div style="text-align:center;padding:30px;"><i class="fas fa-spinner fa-spin" style="font-size:1.6rem;color:#FFD700;"></i></div>';
    try {
        const res = await fetch(`${DEAL_SETTLE_API}?action=get_deal&deal_id=${dealId}`, { cache: 'no-store' });
        const data = await res.json();
        if (!data.success) { body.innerHTML = `<div class="empty-state"><p>${data.message || 'خطا'}</p></div>`; return; }
        body.innerHTML = renderUserDeal(data.deal);
    } catch (e) { body.innerHTML = '<div class="empty-state"><p>خطا در ارتباط</p></div>'; }
}
function closeUserDeal() {
    document.getElementById('userDealModal').style.display = 'none';
    document.body.style.overflow = '';
}

function renderUserDeal(deal) {
    const s = deal.side_data || {};
    const accounts = (s.accounts || []);
    const receipts = (s.receipts || []);
    const settle   = (s.settlement_receipts || []);
    const st = s.side_status || 'new';

    let html = `<div class="ud-summary"><span class="ud-code">${escapeHtml(deal.deal_code)}</span><span>${escapeHtml(deal.currency)} · ${Number(deal.total_price).toLocaleString()} تومان</span></div>`;

    // مراحل
    const steps = [
        {k:'new', t:'در انتظار حساب'},
        {k:'awaiting_payment', t:'واریز و آپلود فیش'},
        {k:'receipt_submitted', t:'بررسی پشتیبانی'},
        {k:'completed', t:'تسویه شد'}
    ];
    const order = {new:0, awaiting_payment:1, receipt_submitted:2, completed:3};
    const cur = order[st] ?? 0;
    html += '<div class="ud-steps">' + steps.map((x,i)=>`<div class="ud-step ${i<cur?'done':i===cur?'active':''}"><span>${i+1}</span><small>${x.t}</small></div>`).join('') + '</div>';

    if (st === 'completed') {
        html += `<div class="ud-note ok"><i class="fas fa-check-double"></i> این معامله برای شما تسویه شد.</div>`;
        if (s.admin_note) html += `<div class="ud-adminnote"><i class="fas fa-comment"></i> ${escapeHtml(s.admin_note)}</div>`;
        if (settle.length) {
            html += `<div class="ud-block"><div class="ud-title"><i class="fas fa-receipt"></i> فیش‌های تسویه</div><div class="ud-thumbs">` +
                settle.map((r,i)=>dealReceiptThumb(deal.id, 'settlement', r, i)).join('') + `</div></div>`;
        }
        return html;
    }

    // شماره‌حساب‌ها
    if (accounts.length) {
        html += `<div class="ud-block"><div class="ud-title"><i class="fas fa-credit-card"></i> واریز به یکی از کارت‌های زیر</div>`;
        if (s.payment_amount) {
            const payCurLabel = s.payment_currency === 'IRR' ? 'تومان' : (s.payment_currency || '');
            html += `<div class="ud-acc" style="background:rgba(255,215,0,0.1);"><div><div class="ud-acc-name">💰 مبلغ قابل پرداخت</div><code>${Number(s.payment_amount).toLocaleString()} ${escapeHtml(payCurLabel)}</code></div><button class="ud-copy" onclick="copyDealText('${Number(s.payment_amount).toLocaleString()}')"><i class="fas fa-copy"></i></button></div>`;
        }
        accounts.forEach(a => {
            html += `<div class="ud-acc"><div>${a.name?`<div class="ud-acc-name">${escapeHtml(a.name)}</div>`:''}<code>${escapeHtml(a.card)}</code></div><button class="ud-copy" onclick="copyDealText('${escapeHtml(a.card)}')"><i class="fas fa-copy"></i></button></div>`;
        });
        html += `</div>`;

        // آپلود فیش
        html += `<div class="ud-block"><div class="ud-title"><i class="fas fa-cloud-upload-alt"></i> آپلود فیش پرداخت (چند فایل مجاز است)</div>
            <div class="ud-upload" onclick="document.getElementById('udFile').click()"><i class="fas fa-plus-circle"></i><span>انتخاب فیش‌ها</span></div>
            <input type="file" id="udFile" accept="image/*,.pdf" multiple style="display:none" onchange="udPicked()">
            <div id="udPickedInfo" class="ud-muted"></div>`;
        if (receipts.length) {
            html += `<div class="ud-title" style="margin-top:10px;">فیش‌های ارسال‌شده</div><div class="ud-thumbs">` +
                receipts.map((r,i)=>dealReceiptThumb(deal.id,'receipt',r,i)).join('') + `</div>`;
        }
        html += `<button class="btn-submit" style="width:100%;margin-top:12px;" onclick="udUploadReceipts()">ارسال فیش پرداخت</button></div>`;
    } else {
        html += `<div class="ud-note"><i class="fas fa-hourglass-half"></i> پشتیبانی هنوز شماره‌حساب واریز این معامله را ارسال نکرده است. به‌محض ارسال، همین‌جا نمایش داده می‌شود و می‌توانید مبلغ را واریز و فیش پرداخت را نیز مستقیماً از همین‌جا ارسال کنید.</div>`;
    }
    return html;
}

function dealReceiptThumb(dealId, kind, path, i) {
    const isPdf = /\.pdf$/i.test(path);
    const dl = `${DEAL_SETTLE_API}?action=download_receipt&deal_id=${dealId}&kind=${kind}&index=${i}`;
    if (isPdf) return `<a class="ud-thumb pdf" href="${dl}" target="_blank"><i class="fas fa-file-pdf"></i></a>`;
    return `<div class="ud-thumb" onclick="openDealLightbox('${path}','${dl}')"><img src="${path}" loading="lazy"></div>`;
}
function udPicked() {
    const f = document.getElementById('udFile').files;
    document.getElementById('udPickedInfo').textContent = f.length ? (f.length + ' فایل انتخاب شد') : '';
}
async function udUploadReceipts() {
    const files = document.getElementById('udFile').files;
    if (!files.length) { showToast('لطفاً حداقل یک فایل انتخاب کنید', 'error'); return; }
    const btn = document.querySelector('#userDealModal .ud-send-btn') || (typeof event!=='undefined' && event ? event.target : null);
    const orig = btn ? btn.innerHTML : '';
    if (btn){ btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> فشرده‌سازی...'; }
    try {
        let out = Array.prototype.slice.call(files);
        if (typeof window.AvaCompressFiles === 'function'){ try { out = await window.AvaCompressFiles(files); } catch(e){} }
        if (btn){ btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ارسال...'; }
        const fd = new FormData();
        fd.append('action', 'upload_receipt');
        fd.append('deal_id', currentUserDealId);
        out.forEach(function(f){ fd.append('file[]', f, f.name || 'receipt.jpg'); });
        if (typeof window.avaShowUploadProgress === 'function') window.avaShowUploadProgress('در حال آپلود فیش...');
        const d = (typeof window.avaUploadWithProgress === 'function')
            ? await window.avaUploadWithProgress(`${DEAL_SETTLE_API}?action=upload_receipt`, fd)
            : await (await fetch(`${DEAL_SETTLE_API}?action=upload_receipt`, { method: 'POST', body: fd })).json();
        if (typeof window.avaHideUploadProgress === 'function') window.avaHideUploadProgress(!!d.success, d.message);
        showToast(d.message || (d.success ? 'ارسال شد' : 'خطا'), d.success ? 'success' : 'error');
        if (d.success) openUserDeal(currentUserDealId);
        else if (btn){ btn.disabled = false; btn.innerHTML = orig; }
    } catch (e) { if (typeof window.avaHideUploadProgress === 'function') window.avaHideUploadProgress(false); showToast('خطا در ارسال', 'error'); if (btn){ btn.disabled = false; btn.innerHTML = orig; } }
}
function copyDealText(txt) {
    if (navigator.clipboard) navigator.clipboard.writeText(txt).then(()=>showToast('کپی شد','success')).catch(()=>{});
}
function openDealLightbox(url, dl) {
    document.getElementById('dealLightboxImg').src = url;
    document.getElementById('dealLightboxDl').href = dl || url;
    document.getElementById('dealLightbox').style.display = 'flex';
}
function closeDealLightbox(e, force) {
    if (force || (e && e.target && e.target.id === 'dealLightbox')) {
        if (e) e.stopPropagation();
        document.getElementById('dealLightbox').style.display = 'none';
    }
}

// ==================== پذیرش/رد پیشنهاد (با آپدیت لحظه‌ای) ====================
// پس از قبول یا رد، کارت با محو‌شدن از لیستِ «در انتظار» برداشته می‌شود
function axsRemoveOfferCard(offerId) {
    const card = document.querySelector(`#arnOffersReceived .arn-offer-card[data-offer-id="${offerId}"]`);
    if (!card) return;
    card.style.transition = 'opacity .3s ease, transform .3s ease';
    card.style.opacity = '0';
    card.style.transform = 'translateX(-14px)';
    setTimeout(() => card.remove(), 300);
}

async function acceptOffer(offerId) {
    if (!confirm('آیا از پذیرش این پیشنهاد اطمینان دارید؟')) return;
    
    // پیدا کردن دکمه و غیرفعال کردن
    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    
    try {
        const response = await fetch('api/offer_api.php?action=accept', {
            method: 'POST', 
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ offer_id: offerId })
        });
        const result = await response.json();
        if (result.success) {
            showToast(result.message, 'success');
            axsRemoveOfferCard(offerId);
            // آپدیت لحظه‌ای لیست پیشنهادات
            await loadAllOffers();
            // آپدیت معاملات فعال
            await loadActiveDeals();
            // آپدیت معاملات کامل شده
            await loadCompletedDeals();
        } else { 
            showToast(result.message, 'error');
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    } catch(e) { 
        showToast('خطا در پردازش', 'error');
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

function openRejectModal(offerId) {
    currentRejectOfferId = offerId;
    document.getElementById('rejectReason').value = '';
    document.getElementById('rejectModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeRejectModal() {
    document.getElementById('rejectModal').style.display = 'none';
    document.body.style.overflow = '';
    currentRejectOfferId = null;
}

async function submitReject() {
    const reason = document.getElementById('rejectReason').value;
    if (!reason) { showToast('لطفاً دلیل رد را وارد کنید', 'error'); return; }
    
    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    
    try {
        const response = await fetch('api/offer_api.php?action=reject', {
            method: 'POST', 
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ offer_id: currentRejectOfferId, reason: reason })
        });
        const result = await response.json();
        if (result.success) { 
            showToast(result.message, 'success'); 
            axsRemoveOfferCard(currentRejectOfferId);
            closeRejectModal(); 
            // آپدیت لحظه‌ای لیست پیشنهادات
            await loadAllOffers();
        } else { 
            showToast(result.message, 'error');
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    } catch(e) { 
        showToast('خطا در ارسال', 'error');
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

// ==================== معاملات ====================
/**
 * بخش «معاملات در انتظار تسویه» فقط وقتی وجود دارد که واقعاً معامله‌ای در جریان باشد.
 * قبلاً همیشه رندر می‌شد و یک کادر خالی روی صفحه می‌ماند.
 */
function axShowDealsSection(show) {
    const sec = document.getElementById('dealsSectionAnchor');
    if (!sec) return;
    if (show) {
        sec.hidden = false;
        sec.classList.add('is-live');
    } else {
        sec.hidden = true;
        sec.classList.remove('is-live');
    }
}

async function loadActiveDeals() {
    const container = document.getElementById('activeDealsContainer');
    try {
        const response = await fetch('api/deals_api.php?action=get_my_active_deals', { cache: 'no-store' });
        const result = await response.json();
        if (result.success && result.deals.length > 0) {
            // side_data مستقیماً از همین پاسخ می‌آید (شماره‌حساب، فیش‌ها، وضعیت طرف کاربر)
            const dealsList = result.deals.map((deal, index) => {
                try { return renderActiveDealCard(deal, deal, index); }
                catch (err) { console.error('render deal error', err); return ''; }
            }).join('');
            container.innerHTML = `<div class="deals-list">${dealsList}</div>`;
            axShowDealsSection(true);
            const pendingSpan = document.getElementById('pendingDealsStat');
            if (pendingSpan) animateNumber(pendingSpan, parseInt(pendingSpan.innerText) || 0, result.deals.length, 500, false);
        } else {
            // معامله‌ای در جریان نیست ⇒ کل بخش پنهان می‌شود (نه اینکه کادر خالی نشان دهد).
            container.innerHTML = '';
            axShowDealsSection(false);
            const pendingSpan = document.getElementById('pendingDealsStat');
            if (pendingSpan) animateNumber(pendingSpan, parseInt(pendingSpan.innerText) || 0, 0, 500, false);
        }
    } catch(e) { console.error(e); }
}

/* (اصلاح) بروزرسانی خودکار «معاملات در انتظار تسویه» — تا وقتی پشتیبانی
   شماره‌حساب واریز را برای یک معامله می‌فرستد، کاربر بدون نیاز به رفرش
   دستی، بلافاصله (حداکثر با ۲۰ ثانیه تأخیر) شماره‌حساب و دکمه‌ی «ارسال
   فیش» را روی همین کارت ببیند — دقیقاً همان چیزی که پیام انتظار وعده
   می‌دهد. */
(function avaAutoRefreshActiveDeals(){
    setInterval(function(){
        if (document.hidden) return;
        var sec = document.getElementById('dealsSectionAnchor');
        if (!sec || sec.hidden) return;
        try { loadActiveDeals(); } catch(e){}
    }, 20000);
})();

function renderActiveDealCard(deal, detail, index) {
    const s = (detail && detail.side_data) ? detail.side_data : {};
    const accounts = s.accounts || [];
    const receipts = s.receipts || [];
    const st = s.side_status || 'new';

    // بلوک شماره‌حساب + کپی + آپلود مستقیم روی کارت
    let settleBlock = '';
    if (st === 'completed') {
        settleBlock = `<div class="dc-note ok"><i class="fas fa-check-double"></i> این معامله برای شما تسویه شد. برای مشاهده فیش‌ها روی دکمه زیر بزنید.</div>`;
    } else if (accounts.length) {
        const accHtml = accounts.map(a => `
            <div class="dc-acc">
                <div class="dc-acc-info">${a.name ? `<span class="dc-acc-name">${escapeHtml(a.name)}</span>` : ''}<code>${escapeHtml(a.card)}</code></div>
                <button class="dc-copy" onclick="copyDealText('${escapeHtml(a.card)}'); event.stopPropagation();"><i class="fas fa-copy"></i> کپی</button>
            </div>`).join('');
        const recHtml = receipts.length ? `<div class="dc-recs">${receipts.map((r,i)=>dealReceiptThumb(deal.id,'receipt',r,i)).join('')}</div>` : '';
        settleBlock = `
            <div class="dc-settle">
                <div class="dc-settle-title"><i class="fas fa-credit-card"></i> واریز به کارت زیر و ارسال فیش</div>
                ${accHtml}
                ${recHtml}
                <input type="file" id="dcFile_${deal.id}" accept="image/*,.pdf" multiple style="display:none" onchange="dcPicked(${deal.id})">
                <div class="dc-actions">
                    <button class="dc-upload-btn" onclick="document.getElementById('dcFile_${deal.id}').click()"><i class="fas fa-paperclip"></i> انتخاب فیش‌ها</button>
                    <button class="dc-send-btn" onclick="dcUpload(${deal.id})"><i class="fas fa-paper-plane"></i> ارسال فیش</button>
                </div>
                <span id="dcPicked_${deal.id}" class="dc-picked"></span>
            </div>`;
    } else {
        settleBlock = `<div class="dc-note"><i class="fas fa-hourglass-half"></i> پشتیبانی هنوز شماره‌حساب واریز این معامله را ارسال نکرده است. به‌محض ارسال، همین‌جا نمایش داده می‌شود و می‌توانید مبلغ را واریز و فیش پرداخت را نیز مستقیماً از همین‌جا ارسال کنید.</div>`;
    }

    // وضعیت نشان روی هدر
    const statusMap = {
        new: {t:'در انتظار حساب', c:'waiting'},
        awaiting_payment: {t:'در انتظار پرداخت', c:'waiting'},
        receipt_submitted: {t:'فیش ارسال شد', c:'submitted'},
        completed: {t:'تسویه شد', c:'done'}
    };
    const stInfo = statusMap[st] || statusMap.new;

    const order  = ['new','awaiting_payment','receipt_submitted','completed'];
    const icons  = ['fa-file-invoice','fa-credit-card','fa-receipt','fa-circle-check'];
    const shorts = ['حساب','پرداخت','فیش','تسویه'];
    let idx = order.indexOf(st); if (idx < 0) idx = 0;
    const pct = (idx / (order.length - 1)) * 100;

    const nodes = order.map((k, n) => {
        const cls = n < idx ? 'done' : (n === idx ? 'cur' : 'todo');
        return `<span class="axd-node ${cls}" style="--d:${n * 0.12}s"><i class="fas ${n < idx ? 'fa-check' : icons[n]}"></i><em>${shorts[n]}</em></span>`;
    }).join('');

    return `
        <div class="axd-item" data-deal-id="${deal.id}" style="animation-delay:${index * 0.06}s;">
            <button type="button" class="axd-head" onclick="axdToggle(this)">
                <span class="axd-code"><i class="fas fa-key"></i> ${escapeHtml(deal.deal_code)}</span>
                <span class="axd-sum"><b>${Number(deal.total_price).toLocaleString('en-US')}</b> تومان · ${Number(deal.amount).toLocaleString('en-US')} ${escapeHtml(deal.currency)}</span>
                <span class="axd-state ${stInfo.c}">${stInfo.t}</span>
                <i class="fas fa-chevron-down axd-caret"></i>
            </button>
            <div class="axd-rail ${stInfo.c}">
                <div class="axd-track"><div class="axd-fill" style="--pct:${pct}%"></div></div>
                <div class="axd-nodes">${nodes}</div>
            </div>
            <div class="axd-body">
                <div class="axd-parties">
                    <span><i class="fas fa-user"></i> ${escapeHtml(deal.buyer_first_name || '')} ${escapeHtml(deal.buyer_last_name || '')} <em>خریدار</em></span>
                    <span><i class="fas fa-store"></i> ${escapeHtml(deal.seller_first_name || '')} ${escapeHtml(deal.seller_last_name || '')} <em>فروشنده</em></span>
                </div>
                ${settleBlock}
                <button type="button" class="deal-manage-btn" onclick="openUserDeal(${deal.id})"><i class="fas fa-eye"></i> ${st==='completed'?'مشاهده فیش تسویه':'مشاهده کامل وضعیت'}</button>
            </div>
        </div>`;
}

// باز/بسته کردن جزئیات یک معامله
function axdToggle(btn) {
    const item = btn.closest('.axd-item');
    if (!item) return;
    const open = item.classList.toggle('open');
    if (open) {
        document.querySelectorAll('.axd-item.open').forEach(o => { if (o !== item) o.classList.remove('open'); });
    }
}

// آپلود فیش مستقیم از روی کارت
function dcPicked(dealId) {
    const f = document.getElementById('dcFile_' + dealId).files;
    document.getElementById('dcPicked_' + dealId).textContent = f.length ? (f.length + ' فایل انتخاب شد') : '';
}
async function dcUpload(dealId) {
    const input = document.getElementById('dcFile_' + dealId);
    const files = input ? input.files : null;
    if (!files || !files.length) { showToast('لطفاً حداقل یک فیش انتخاب کنید', 'error'); return; }
    let btn = null;
    try { btn = (window.event && window.event.target) ? window.event.target.closest('.dc-send-btn') : null; } catch(e){}
    let old = '';
    if (btn) { btn.disabled = true; old = btn.innerHTML; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; }
    try {
        let out = Array.prototype.slice.call(files);
        if (typeof window.AvaCompressFiles === 'function'){ try { out = await window.AvaCompressFiles(files); } catch(e){} }
        const fd = new FormData();
        fd.append('action', 'upload_receipt');
        fd.append('deal_id', dealId);
        out.forEach(function(f){ fd.append('file[]', f, f.name || 'receipt.jpg'); });
        if (typeof window.avaShowUploadProgress === 'function') window.avaShowUploadProgress('در حال آپلود فیش...');
        let d;
        try {
            d = (typeof window.avaUploadWithProgress === 'function')
                ? await window.avaUploadWithProgress(`${DEAL_SETTLE_API}?action=upload_receipt`, fd)
                : JSON.parse(await (await fetch(`${DEAL_SETTLE_API}?action=upload_receipt`, { method: 'POST', body: fd })).text());
        } catch(e){ d = { success:false, message:'پاسخ نامعتبر از سرور' }; }
        if (typeof window.avaHideUploadProgress === 'function') window.avaHideUploadProgress(!!d.success, d.message);
        showToast(d.message || (d.success ? 'فیش ارسال شد' : 'خطا در ارسال فیش'), d.success ? 'success' : 'error');
        if (d.success) { if (input) input.value=''; loadActiveDeals(); }
    } catch (e) { if (typeof window.avaHideUploadProgress === 'function') window.avaHideUploadProgress(false); showToast('خطا در ارسال فیش', 'error'); }
    finally { if (btn) { btn.disabled = false; btn.innerHTML = old; } }
}

/* (جدید) واریز و ارسال فیش مستقیم از همین‌جا، وقتی حساب از طریق سیستم فیش‌ها
   (نه مسیر قدیمیِ admin_accounts) برای این معامله/طرف فرستاده شده باشد. */
function axInvPicked(invId) {
    const f = document.getElementById('axInvFile_' + invId).files;
    document.getElementById('axInvPicked_' + invId).textContent = f.length ? (f.length + ' فایل انتخاب شد') : '';
}
async function axInvUpload(invId) {
    const input = document.getElementById('axInvFile_' + invId);
    const files = input ? input.files : null;
    if (!files || !files.length) { showToast('لطفاً حداقل یک فیش انتخاب کنید', 'error'); return; }
    let btn = null;
    try { btn = (window.event && window.event.target) ? window.event.target.closest('.dc-send-btn') : null; } catch(e){}
    let old = '';
    if (btn) { btn.disabled = true; old = btn.innerHTML; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; }
    try {
        let out = Array.prototype.slice.call(files);
        if (typeof window.AvaCompressFiles === 'function'){ try { out = await window.AvaCompressFiles(files); } catch(e){} }
        const fd = new FormData();
        fd.append('invoice_id', invId);
        out.forEach(function(f){ fd.append('receipts[]', f, f.name || 'receipt.jpg'); });
        if (typeof window.avaShowUploadProgress === 'function') window.avaShowUploadProgress('در حال آپلود فیش...');
        let d;
        try {
            d = (typeof window.avaUploadWithProgress === 'function')
                ? await window.avaUploadWithProgress('api/unpaid_invoice_api.php?action=pay_invoice', fd)
                : JSON.parse(await (await fetch('api/unpaid_invoice_api.php?action=pay_invoice', { method: 'POST', body: fd })).text());
        } catch(e){ d = { success:false, message:'پاسخ نامعتبر از سرور' }; }
        if (typeof window.avaHideUploadProgress === 'function') window.avaHideUploadProgress(!!d.success, d.message);
        showToast(d.message || (d.success ? 'فیش ارسال شد' : 'خطا در ارسال فیش'), d.success ? 'success' : 'error');
        if (d.success) { if (input) input.value=''; loadActiveDeals(); }
    } catch (e) { if (typeof window.avaHideUploadProgress === 'function') window.avaHideUploadProgress(false); showToast('خطا در ارسال فیش', 'error'); }
    finally { if (btn) { btn.disabled = false; btn.innerHTML = old; } }
}

async function loadCompletedDeals() {
    const container = document.getElementById('completedDealsContainer');
    try {
        const response = await fetch('api/deals_api.php?action=get_my_completed_deals');
        const result = await response.json();
        if (result.success && result.deals.length > 0) {
            window.__dealCache = window.__dealCache || {};
            result.deals.forEach(d => { window.__dealCache[d.deal_code] = d; });

            if (container) {
                const rows = result.deals.map((deal, index) => `
                    <div class="compact-row completed" style="animation: fadeInUp 0.3s ease ${index * 0.04}s forwards; opacity:0;" onclick="openDealDetail('${escapeHtml(deal.deal_code)}')">
                        <div class="cr-dot"></div>
                        <div class="cr-main">
                            <div class="cr-title">${Number(deal.total_price).toLocaleString()} تومان</div>
                            <div class="cr-sub">${escapeHtml(deal.buyer_first_name)} ${escapeHtml(deal.buyer_last_name)} ← ${escapeHtml(deal.seller_first_name)} ${escapeHtml(deal.seller_last_name)}</div>
                        </div>
                        <span class="cr-date">${new Date(deal.completed_at || deal.created_at).toLocaleDateString('fa-IR')}</span>
                        <i class="fas fa-chevron-left cr-arrow"></i>
                    </div>
                `).join('');
                container.innerHTML = `<div class="completed-deals-list compact">${rows}</div>`;
            }

            const totalSpan = document.getElementById('totalDealsStat');
            const userSpan = document.getElementById('userTotalDeals');
            if (totalSpan && result.stats && result.stats.total_deals) animateNumber(totalSpan, parseInt(totalSpan.innerText) || 0, result.stats.total_deals, 800, false);
            if (userSpan && result.stats && result.stats.total_deals) animateNumber(userSpan, parseInt(userSpan.innerText) || 0, result.stats.total_deals, 800, false);
        } else if (container) {
            container.innerHTML = `<div class="empty-deals"><i class="fas fa-calendar-check"></i><p>هنوز معامله تکمیل شده‌ای ندارید</p><span>پس از پرداخت و تسویه، معاملات اینجا نمایش داده می‌شوند</span></div>`;
        }
    } catch(e) { console.error(e); }
}

// ==================== مدال جزئیات معامله تکمیل‌شده ====================
function openDealDetail(code) {
    const d = (window.__dealCache || {})[code];
    if (!d) return;
    const body = document.getElementById('detailModalBody');
    document.getElementById('detailModalTitle').innerHTML = '<i class="fas fa-check-double" style="color:#4CD964;"></i> جزئیات معامله';
    body.innerHTML = `
        <div class="dm-hero"><span class="dm-hero-amount">${Number(d.total_price).toLocaleString()} تومان</span><span class="completed-badge"><i class="fas fa-check-double"></i> تکمیل شده</span></div>
        <div class="dm-rows">
            <div class="dm-r"><span>کد معامله</span><b style="direction:ltr;">${escapeHtml(d.deal_code)}</b></div>
            <div class="dm-r"><span>ارز</span><b>${escapeHtml(d.currency || '-')}</b></div>
            <div class="dm-r"><span>مقدار</span><b>${Number(d.amount).toLocaleString()}</b></div>
            <div class="dm-r"><span>پیشنهاددهنده</span><b>${escapeHtml(d.buyer_first_name)} ${escapeHtml(d.buyer_last_name)}</b></div>
            <div class="dm-r"><span>آگهی‌دهنده</span><b>${escapeHtml(d.seller_first_name)} ${escapeHtml(d.seller_last_name)}</b></div>
            <div class="dm-r"><span>تاریخ تکمیل</span><b>${new Date(d.completed_at || d.created_at).toLocaleDateString('fa-IR')}</b></div>
        </div>
    `;
    document.getElementById('detailModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

// ==================== مودال ارسال پیشنهاد (ویزارد دو مرحله‌ای) ====================
function openOfferModal(adId, type, currency, amount, price, sellerName, description) {
    document.getElementById('offerAdId').value = adId;
    document.getElementById('offerCurrency').value = currency;
    document.getElementById('offerAdType').value = type;
    document.getElementById('offerAdPreview').innerHTML = `<div style="text-align:center"><span style="display:inline-block;padding:2px 8px;border-radius:16px;background:${type === 'buy' ? 'rgba(76,217,100,0.2)' : 'rgba(255,59,48,0.2)'};color:${type === 'buy' ? '#4CD964' : '#FF3B30'};">${type === 'buy' ? 'خریدار' : 'فروشنده'}</span><div style="margin-top:6px;"><strong>💰 ${currency}</strong></div><div style="font-size:0.65rem;">📊 مقدار کل: ${Number(amount).toLocaleString()}</div><div style="font-size:0.65rem;">💵 قیمت اعلام شده: ${Number(price).toLocaleString()} تومان</div><div style="font-size:0.65rem;">👤 ${escapeHtml(sellerName)}</div>${description ? `<div style="margin-top:6px;font-size:0.6rem;background:rgba(0,0,0,0.2);padding:4px;border-radius:6px;">📝 ${escapeHtml(description)}</div>` : ''}</div>`;
    document.getElementById('offerAmount').value = '';
    document.getElementById('offerPrice').value = '';
    document.getElementById('offerMessage').value = '';
    document.getElementById('offerTotalAmount').innerHTML = '0 تومان';

    offerBackToStep1();
    document.getElementById('offerModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeOfferModal() {
    document.getElementById('offerModal').style.display = 'none';
    document.body.style.overflow = '';
}

function offerSetStepUI(step) {
    const dot1 = document.getElementById('offerStepDot1');
    const dot2 = document.getElementById('offerStepDot2');
    const c1 = dot1.querySelector('div'); const c2 = dot2.querySelector('div');
    if (step === 1) {
        document.getElementById('offerStep1').style.display = 'block';
        document.getElementById('offerStep2').style.display = 'none';
        dot1.style.opacity = '1'; dot2.style.opacity = '.5';
        c1.style.background = '#6C40C5'; c2.style.background = 'rgba(255,255,255,0.1)';
    } else {
        document.getElementById('offerStep1').style.display = 'none';
        document.getElementById('offerStep2').style.display = 'block';
        dot1.style.opacity = '1'; dot2.style.opacity = '1';
        c1.style.background = '#6C40C5'; c2.style.background = '#6C40C5';
    }
}
function offerBackToStep1() { offerSetStepUI(1); }

async function offerGoToStep2() {
    const amount = parseFloat(document.getElementById('offerAmount').value);
    const price = parseFloat(document.getElementById('offerPrice').value);
    if (isNaN(amount) || amount <= 0) { showToast('لطفاً مقدار معتبر وارد کنید', 'error'); return; }
    if (isNaN(price) || price <= 0) { showToast('لطفاً قیمت معتبر وارد کنید', 'error'); return; }
    offerSetStepUI(2);
    await buildOfferSummary(amount, price);
}

function calcOfferTotal() {
    const amount = parseFloat(document.getElementById('offerAmount').value) || 0;
    const price = parseFloat(document.getElementById('offerPrice').value) || 0;
    const total = amount * price;
    document.getElementById('offerTotalAmount').innerHTML = total.toLocaleString('fa-IR') + ' تومان';
}

function _faUnit(u) {
    if (!u || u === 'IRR') return 'تومان';
    return u;
}

// خلاصه‌ی نهایی — کمیسیون دقیقاً از سرور گرفته می‌شود (همان مقداری که در تلگرام است):
// قوانین پلکانی/درصدی + تخفیف کاربر لحاظ می‌شوند. آگهیِ فروش ⇒ پیشنهاددهنده خریدار (کسر)،
// آگهیِ خرید ⇒ پیشنهاددهنده فروشنده (اضافه).
async function buildOfferSummary(amount, price) {
    const total = amount * price;
    const adId = document.getElementById('offerAdId').value;
    const currency = document.getElementById('offerCurrency').value || '';

    // پرکردن اطلاعات پایه فوراً
    document.getElementById('offerSummaryAmount').innerHTML = amount.toLocaleString('fa-IR') + ' ' + (currency || '');
    document.getElementById('offerSummaryPrice').innerHTML = price.toLocaleString('fa-IR') + ' تومان';
    document.getElementById('offerSummaryTotal').innerHTML = total.toLocaleString('fa-IR') + ' تومان';
    document.getElementById('offerRoleLabel').innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    document.getElementById('finalCommissionSpan').innerHTML = '...';
    document.getElementById('commissionDirectionNote').innerHTML = 'در حال محاسبه کمیسیون...';
    document.getElementById('finalAmount').innerHTML = '...';

    let data = null;
    let fetchFailed = false;
    try {
        const res = await fetch('api/offer_api.php?action=commission_preview', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ ad_id: parseInt(adId), requested_amount: amount, offered_price: price })
        });
        data = await res.json();
        if (!data || !data.success) fetchFailed = true;
    } catch (e) { fetchFailed = true; }

    if (fetchFailed) {
        // کمیسیون باید همیشه دقیقاً همان چیزی باشد که سرور محاسبه می‌کند (به‌خصوص
        // وقتی ادمین برای این کاربر قانون کمیسیون تومانی گذاشته — آن‌وقت واحد
        // نمایش «باید» تومان باشد، نه ارز آگهی). یک فرمول محلیِ حدسی اینجا می‌تواند
        // واحد را اشتباه نشان دهد، پس به‌جای حدس زدن، خطا نشان می‌دهیم.
        document.getElementById('offerRoleLabel').innerHTML = '—';
        document.getElementById('commissionDirectionNote').innerHTML =
            '<span style="color:#FF6B6B;">خطا در دریافت کمیسیون از سرور — لطفاً دوباره تلاش کنید.</span>';
        document.getElementById('finalCommissionSpan').innerHTML = '—';
        document.getElementById('finalAmount').innerHTML = '—';
        return;
    }

    const offererIsSeller = !!data.offerer_is_seller;
    const unit = _faUnit(data.commission_unit);
    const roleLabel = offererIsSeller ? 'فروشنده (کمیسیون اضافه می‌شود)' : 'خریدار (کمیسیون کسر می‌شود)';

    document.getElementById('offerRoleLabel').innerHTML = roleLabel;
    document.getElementById('offerRoleLabel').style.color = offererIsSeller ? '#4CD964' : '#FF9F43';

    document.getElementById('baseCommissionSpan').innerHTML = Number(data.base_commission).toLocaleString('fa-IR');
    document.getElementById('finalCommissionSpan').innerHTML = Number(data.final_commission).toLocaleString('fa-IR');
    document.getElementById('baseCommissionCurrencySpan').innerHTML = unit;
    document.getElementById('finalCommissionCurrencySpan').innerHTML = unit;

    // درصد تخفیف واقعی از سرور
    const dp = Number(data.discount_percent || 0);
    document.getElementById('userDiscountSpan').innerHTML = dp.toLocaleString('fa-IR') + '%';

    let netNote, netLabel;
    if (offererIsSeller) {
        netNote = `شما فروشنده هستید: کمیسیون <b>${Number(data.final_commission).toLocaleString('fa-IR')} ${unit}</b> به مبلغ دریافتی شما اضافه می‌شود.`;
        netLabel = 'مبلغ نهایی دریافتی:';
    } else {
        netNote = `شما خریدار هستید: کمیسیون <b>${Number(data.final_commission).toLocaleString('fa-IR')} ${unit}</b> از مبلغ دریافتی شما کسر می‌شود.`;
        netLabel = 'مبلغ نهایی (پس از کسر کمیسیون):';
    }
    if (data.rule_applied) netNote += '<br><span style="color:#FFD700;font-size:.66rem;">✔ قانون کمیسیون اختصاصی شما اعمال شد</span>';

    document.getElementById('commissionDirectionNote').innerHTML = netNote;
    document.getElementById('offerNetLabel').innerHTML = netLabel;
    document.getElementById('finalAmount').innerHTML = Number(data.net_toman).toLocaleString('fa-IR');
    document.getElementById('finalAmountCurrencySymbol').innerHTML = 'تومان';
}

document.getElementById('offerAmount')?.addEventListener('input', calcOfferTotal);
document.getElementById('offerPrice')?.addEventListener('input', calcOfferTotal);

let isSendingOffer = false;
let lastOfferData = null;

async function sendOffer() {
    if (isSendingOffer) {
        showToast('در حال ارسال پیشنهاد، لطفاً صبر کنید...', 'warning');
        return;
    }
    
    const adId = document.getElementById('offerAdId').value;
    const amount = parseFloat(document.getElementById('offerAmount').value);
    const price = parseFloat(document.getElementById('offerPrice').value);
    const message = document.getElementById('offerMessage').value;
    
    if (!adId || adId == 0) { showToast('شناسه آگهی نامعتبر است', 'error'); return; }
    if (isNaN(amount) || amount <= 0) { showToast('لطفاً مقدار معتبر وارد کنید', 'error'); return; }
    if (isNaN(price) || price <= 0) { showToast('لطفاً قیمت معتبر وارد کنید', 'error'); return; }
    
    const offerKey = `${adId}_${amount}_${price}`;
    if (lastOfferData === offerKey) {
        showToast('شما همین الان این پیشنهاد را ارسال کردید!', 'warning');
        return;
    }
    
    const btn = document.querySelector('#offerModal .btn-submit');
    const originalText = btn.innerHTML;
    
    isSendingOffer = true;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال ارسال...';
    
    try {
        const response = await fetch('api/offer_api.php?action=create', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ ad_id: parseInt(adId), requested_amount: amount, offered_price: price, message: message, timestamp: Date.now() })
        });
        const result = await response.json();
        if (result.success) { 
            showToast(result.message, 'success');
            lastOfferData = offerKey;
            closeOfferModal(); 
            loadAllOffers();
            setTimeout(() => { lastOfferData = null; }, 5000);
        } else { 
            showToast(result.message, 'error');
        }
    } catch(e) { 
        showToast('خطا در ارسال پیشنهاد', 'error');
    } finally { 
        isSendingOffer = false;
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

// ==================== حالت embed (باز شدن از داشبورد در iframe) ====================
(function(){
    const q = new URLSearchParams(location.search);
    if (q.get('new') !== '1') return;
    document.addEventListener('DOMContentLoaded', function(){
        if (q.get('embed') === '1') {
            const st = document.createElement('style');
            st.textContent = 'body>*:not(#createAdModal){display:none!important}'
                + 'body{background:transparent!important;padding:0!important;overflow:hidden!important}'
                /* مدال در حالت embed تمام‌صفحه و مناسب موبایل می‌شود */
                + '#createAdModal{position:fixed;inset:0;display:flex!important;background:transparent!important;padding:0!important;align-items:stretch!important;justify-content:stretch!important}'
                + '#createAdModal .modal-content{width:100%!important;max-width:100%!important;height:100%!important;max-height:100%!important;margin:0!important;border-radius:0!important;display:flex!important;flex-direction:column!important;border:none!important}'
                + '#createAdModal .modal-body{flex:1!important;overflow-y:auto!important;-webkit-overflow-scrolling:touch;padding:16px 16px 90px!important}'
                + '#createAdModal .modal-header{border-radius:0!important;padding:14px 16px!important;flex:none}'
                + '#createAdModal .close-modal{display:none!important}' /* بستن با دکمه‌ی «خروج» مدال والد */
                + '.footer-menu,.bottom-nav,#footerMenu,.avapay-header,.app-header{display:none!important}';
            document.head.appendChild(st);
            // دکمه بستن مدال → بستن مدال والد
            window.closeCreateAdModal = function(){
                try { parent.avaCloseFsModal && parent.avaCloseFsModal('avaAdModal'); } catch(e){}
            };
        }
        if (typeof openCreateAd === 'function') openCreateAd();
    });
})();

// ==================== مودال ثبت آگهی ====================
function openCreateAd(presetType) {
    document.getElementById('createAdModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
    setModalAdType(presetType === 'sell' ? 'sell' : 'buy');
    document.getElementById('modalAdCurrency').value = '';
    document.getElementById('modalAdAmount').value = '';
    document.getElementById('modalAdPrice').value = '';
    document.getElementById('modalAdDesc').value = '';
}

function closeCreateAdModal() {
    document.getElementById('createAdModal').style.display = 'none';
    document.body.style.overflow = '';
}

function setModalAdType(type) {
    modalAdType = type;
    document.getElementById('modalAdType').value = type;
    const buyBtn = document.getElementById('modalTypeBuy');
    const sellBtn = document.getElementById('modalTypeSell');
    if (type === 'buy') { buyBtn.classList.add('active'); sellBtn.classList.remove('active'); }
    else { sellBtn.classList.add('active'); buyBtn.classList.remove('active'); }
}

document.getElementById('createAdFormModal')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = {
        type: document.getElementById('modalAdType').value,
        currency: document.getElementById('modalAdCurrency').value,
        amount: parseFloat(document.getElementById('modalAdAmount').value),
        price_per_unit: parseFloat(document.getElementById('modalAdPrice').value),
        description: document.getElementById('modalAdDesc').value
    };
    if (!formData.currency) { showToast('لطفاً ارز را انتخاب کنید', 'error'); return; }
    if (formData.amount <= 0) { showToast('مقدار نامعتبر است', 'error'); return; }
    if (formData.price_per_unit <= 0) { showToast('قیمت نامعتبر است', 'error'); return; }
    const btn = this.querySelector('button');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال ثبت...';
    try {
        const response = await fetch('api/ads_api.php?action=create', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(formData)
        });
        const result = await response.json();
        if (result.success) { 
            showToast(result.message, 'success'); 
            closeCreateAdModal(); 
            // لیست کامل بازار همیشه نمایش داده می‌شود، پس فقط تازه‌سازی لازم است
            loadAds();
        }
        else { showToast(result.message, 'error'); }
    } catch(e) { showToast('خطا در ثبت آگهی', 'error'); }
    finally { btn.disabled = false; btn.innerHTML = 'ثبت آگهی'; }
});

// ==================== مودال ویرایش آگهی ====================
let currentEditAdId = null;

function openEditModal(adId, currency, currentAmount, currentPrice, currentDesc) {
    currentEditAdId = adId;
    document.getElementById('editAdId').value = adId;
    document.getElementById('editCurrencyBadge').innerHTML = `<i class="fas fa-coins"></i> ارز: ${currency}`;
    document.getElementById('editAmount').value = currentAmount;
    document.getElementById('editPrice').value = currentPrice;
    document.getElementById('editDescription').value = currentDesc || '';
    calculateEditTotal();
    document.getElementById('editAdModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeEditModal() {
    document.getElementById('editAdModal').style.display = 'none';
    document.body.style.overflow = '';
    currentEditAdId = null;
}

function calculateEditTotal() {
    const amount = parseFloat(document.getElementById('editAmount').value) || 0;
    const price = parseFloat(document.getElementById('editPrice').value) || 0;
    const total = amount * price;
    document.getElementById('editTotalAmount').innerHTML = total.toLocaleString('fa-IR') + ' تومان';
}

async function submitEditAd() {
    const adId = document.getElementById('editAdId').value;
    const amount = parseFloat(document.getElementById('editAmount').value);
    const price = parseFloat(document.getElementById('editPrice').value);
    const description = document.getElementById('editDescription').value;
    
    if (!adId || adId == 0) { showToast('شناسه آگهی نامعتبر است', 'error'); return; }
    if (isNaN(amount) || amount <= 0) { showToast('مقدار ارز باید بیشتر از صفر باشد', 'error'); return; }
    if (isNaN(price) || price <= 0) { showToast('قیمت باید بیشتر از صفر باشد', 'error'); return; }
    
    const btn = document.querySelector('#editAdModal .btn-submit');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال ذخیره...';
    
    try {
        const response = await fetch('api/ads_api.php?action=edit', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ad_id: parseInt(adId), amount: amount, price_per_unit: price, description: description })
        });
        const result = await response.json();
        if (result.success) {
            showToast(result.message, 'success');
            closeEditModal();
            loadAds();
        } else {
            showToast(result.message, 'error');
        }
    } catch(e) {
        showToast('خطا در ویرایش آگهی: ' + e.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

document.getElementById('editAmount')?.addEventListener('input', calculateEditTotal);
document.getElementById('editPrice')?.addEventListener('input', calculateEditTotal);

// حذف آگهی توسط خودِ صاحب آگهی — از اکشن «cancel» بک‌اند استفاده می‌کند که از قبل
// وجود داشت و مالکیت را چک می‌کند (status آگهی را cancelled می‌کند)، فقط تا امروز
// هیچ دکمه‌ای در رابط کاربری به آن وصل نبود.
async function deleteMyAd() {
    const adId = document.getElementById('editAdId').value;
    if (!adId || adId == 0) { showToast('شناسه آگهی نامعتبر است', 'error'); return; }
    if (!confirm('آیا مطمئنید می‌خواهید این آگهی را حذف کنید؟ این عمل غیرقابل بازگشت است.')) return;

    const btn = document.querySelector('#editAdModal .btn-submit[onclick="deleteMyAd()"]');
    const originalText = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال حذف...'; }

    try {
        const response = await fetch('api/ads_api.php?action=cancel', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ad_id: parseInt(adId) })
        });
        const result = await response.json();
        if (result.success) {
            showToast(result.message || 'آگهی حذف شد', 'success');
            closeEditModal();
            loadAds();
        } else {
            showToast(result.message || 'خطا در حذف آگهی', 'error');
        }
    } catch (e) {
        showToast('خطا در حذف آگهی: ' + e.message, 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = originalText; }
    }
}

// ==================== توابع کمکی ====================
function refreshAds() { loadAds(); showToast('آگهی‌ها بروزرسانی شدند', 'success'); }


// ==================== پولینگ ====================
let lastOffersSignature = '';
let lastActiveDealsCount = <?php echo count($activeDeals); ?>;
let lastSettleSig = null;
function offersSignature(received, sent) {
    // امضایی از وضعیت همه‌ی پیشنهادها؛ هر تغییری (پذیرش/رد از تلگرام یا اپ) را تشخیص می‌دهد
    const map = o => o.id + ':' + o.status;
    return (received || []).map(map).join(',') + '|' + (sent || []).map(map).join(',');
}
function startPolling() {
    if (pollingInterval) clearInterval(pollingInterval);
    pollingInterval = setInterval(async () => {
        try {
            const adsRes = await fetch('api/ads_api.php?action=list&limit=30');
            const adsResult = await adsRes.json();
            if (adsResult.success && adsResult.ads) {
                if (lastAllAdsCount !== null && adsResult.ads.length !== lastAllAdsCount) { loadAds(); }
                lastAllAdsCount = adsResult.ads.length;
            }

            const [receivedRes, sentRes] = await Promise.all([
                fetch('api/offer_api.php?action=get_my_received_offers', { cache: 'no-store' }),
                fetch('api/offer_api.php?action=get_my_sent_offers', { cache: 'no-store' })
            ]);
            const receivedResult = await receivedRes.json();
            const sentResult = await sentRes.json();

            const received = (receivedResult.success ? receivedResult.offers : []) || [];
            const sent = (sentResult.success ? sentResult.offers : []) || [];

            const sig = offersSignature(received, sent);
            if (lastOffersSignature && sig !== lastOffersSignature) {
                // هر تغییری در وضعیت پیشنهادها (از جمله پاسخ از تلگرام) => بازخوانی فوری لیست‌ها
                loadAllOffers();
                // بروزرسانی زنگوله‌ی نوتیفیکیشن
                if (window.notificationManager) {
                    window.notificationManager.loadNotifications();
                    window.notificationManager.updateUnreadCount();
                }
            }
            // اگر پیشنهاد جدیدی اضافه شده باشد، صدا و توست پخش کن
            const newPendingReceived = received.filter(o => o.status === 'pending').length;
            if (lastOffersSignature && newPendingReceived > lastReceivedCount) {
                showToast('🔔 پیشنهاد جدید دریافت شد!', 'success');
                playNotificationSound();
            }
            lastReceivedCount = newPendingReceived;
            lastOffersSignature = sig;

            // ---- همگام‌سازی معاملات با پنل مدیریت ----
            // اگر ادمین معامله‌ای را «تکمیل» کند یا شماره‌حساب بفرستد، کارت به‌روز می‌شود.
            try {
                const dealsRes = await fetch('api/deals_api.php?action=get_my_active_deals', { cache: 'no-store' });
                const dealsData = await dealsRes.json();
                const activeDeals = (dealsData.success && dealsData.deals) ? dealsData.deals : [];
                const activeCount = activeDeals.length;

                // امضای وضعیت تسویه (از side_data که در همین پاسخ آمده)
                const settleSig = activeDeals.map(d => {
                    const s = d.side_data || {};
                    return `${d.id}:${(s.accounts||[]).length}:${(s.receipts||[]).length}:${s.side_status||''}`;
                }).join('|');

                const changed = (typeof lastActiveDealsCount === 'number' && activeCount !== lastActiveDealsCount)
                             || (lastSettleSig !== null && settleSig !== lastSettleSig);
                if (changed) {
                    loadActiveDeals();
                    loadCompletedDeals();
                    if (activeCount < lastActiveDealsCount) {
                        showToast('✅ یک معامله توسط پشتیبانی تسویه شد', 'success');
                        playNotificationSound();
                    } else if (lastSettleSig !== null && settleSig !== lastSettleSig) {
                        showToast('🔔 وضعیت تسویه‌ی معامله‌ی شما به‌روزرسانی شد', 'info');
                    }
                }
                lastActiveDealsCount = activeCount;
                lastSettleSig = settleSig;
            } catch(dealErr) { /* بی‌صدا */ }
        } catch(e) { console.error('Polling error:', e); }
    }, 5000);
}

// ==================== بستن مودال‌ها با کلیک خارج ====================
window.onclick = function(e) {
    if (e.target === document.getElementById('createAdModal')) closeCreateAdModal();
    if (e.target === document.getElementById('editAdModal')) closeEditModal();
    if (e.target === document.getElementById('offerModal')) closeOfferModal();
    if (e.target === document.getElementById('rejectModal')) closeRejectModal();
    if (e.target === document.getElementById('detailModal')) closeDetailModal();
    if (e.target === document.getElementById('userDealModal')) closeUserDeal();
    if (e.target === document.getElementById('offersListModal')) closeOffersListModal();
}

// ==================== تابع برای نوتیفیکیشن منیجر ====================
async function markAllNotificationsRead() {
    try {
        await fetch('api/notification_api.php?action=mark_all_read', { method: 'POST' });
        if (window.notificationManager) {
            window.notificationManager.loadNotifications();
            window.notificationManager.updateUnreadCount();
        }
        showToast('✅ همه اعلان‌ها خوانده شد', 'success');
    } catch(e) { console.error(e); }
}

// ==================== گزارش تخلف آگهی ====================
async function axReportAd(adId) {
    const reason = prompt('دلیل گزارش تخلف این آگهی را بنویسید:');
    if (!reason || !reason.trim()) return;
    try {
        const res = await fetch('api/ads_api.php?action=report', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ad_id: adId, reason: reason.trim() })
        });
        const result = await res.json();
        showToast(result.message || (result.success ? 'گزارش ثبت شد' : 'خطا در ثبت گزارش'), result.success ? 'success' : 'error');
    } catch(e) { showToast('خطا در ارتباط با سرور', 'error'); }
}

// ==================== شروع ====================
document.addEventListener('DOMContentLoaded', function() {
    loadAds();
    loadAllOffers();
    // معاملات فعال از قبل توسط PHP رندر شده‌اند؛ فقط در صورت تغییر (polling) به‌روزرسانی می‌شوند
    loadCompletedDeals();
    startPolling();
    
    const totalSpan = document.getElementById('totalDealsStat');
    if (totalSpan && totalSpan.innerText !== '0') {
        animateNumber(totalSpan, 0, parseInt(totalSpan.innerText), 800, false);
    }
    const pendingSpan = document.getElementById('pendingDealsStat');
    if (pendingSpan && pendingSpan.innerText !== '0') {
        animateNumber(pendingSpan, 0, parseInt(pendingSpan.innerText), 500, false);
    }
    const volumeSpan = document.getElementById('totalVolumeStat');
    if (volumeSpan && volumeSpan.innerText !== '0') {
        const volumeValue = <?php echo $totalStats['total_volume']; ?>;
        volumeSpan.innerText = formatNumberCompactJS(volumeValue);
    }
});

document.addEventListener('DOMContentLoaded', function() {
    setTimeout(() => {
        if (window.initPushNotifications) {
            const userId = <?php echo $userId; ?>;
            window.initPushNotifications(userId);
        }
    }, 3000);
});

</script>

<script defer src="assets/js/nova-chart.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/nova-chart.js') ?: time(); ?>"></script>

<script>
/* ==== نمودارها برای صفحه تبادل ارزی (متصل به داده‌ی معاملات و پنل مدیریت) ==== */
(function () {
  var _avTries = 0;
  function boot() {
    try {
    if ((!window.AvaPay || !window.__AV_EX__)) { if (_avTries++ < 50) return setTimeout(boot, 80); return; }
    var AV = window.AvaPay, d = window.__AV_EX__;
    var faNum = function (n) { return Number(n || 0).toLocaleString('en-US'); };
    // واحدهای فارسی: «۱.۳ میلیارد» به‌جای «1.3B»
    var compact = function (n) {
      n = Number(n) || 0;
      if (n >= 1e9) return (n/1e9).toFixed(n >= 1e10 ? 0 : 1).replace(/\.0$/,'') + ' میلیارد';
      if (n >= 1e6) return (n/1e6).toFixed(n >= 1e7 ? 0 : 1).replace(/\.0$/,'') + ' میلیون';
      if (n >= 1e3) return (n/1e3).toFixed(n >= 1e4 ? 0 : 1).replace(/\.0$/,'') + ' هزار';
      return faNum(n);
    };

    var total = (d.active||0) + (d.completed||0) + (d.offersIn||0) + (d.offersOut||0);
    var chip = document.getElementById('avExTotalChip');
    if (chip) chip.textContent = faNum(d.completed) + ' موفق';
    var sum = document.getElementById('avExSumChip');
    if (sum) sum.textContent = compact(d.totalVolume) + ' تومان';

    var donutHost = document.getElementById('avExDonut');
    if (donutHost) {
      if (total === 0) {
        donutHost.innerHTML = '<div style="color:rgba(255,255,255,.45);font-size:.8rem;text-align:center;padding:20px 0;">هنوز معامله‌ای ثبت نشده</div>';
      } else {
        donutHost.appendChild(AV.donut({
          centerValue: d.completed, centerLabel: 'موفق',
          segments: [
            { label: 'در انتظار تسویه', value: d.active, color: '#ffce54', display: faNum(d.active) },
            { label: 'تکمیل‌شده', value: d.completed, color: '#35d07f', display: faNum(d.completed) },
            { label: 'پیشنهاد باز', value: (d.offersIn||0)+(d.offersOut||0), color: '#b79cf5', display: faNum((d.offersIn||0)+(d.offersOut||0)) }
          ]
        }));
      }
    }

    var sparkHost = document.getElementById('avExSpark');
    var chipHost  = document.getElementById('avExCurChips');
    var metaHost  = document.getElementById('avExCurMeta');
    var sumChip   = document.getElementById('avExSumChip');

    if (sparkHost) {
      // برچسب‌های واقعیِ تاریخ (میلادیِ سرور → نمایش شمسی) برای هر یک از ۶ ماه؛ اگر سرور نفرستاد، برچسب نسبی جایگزین می‌شود
      var months = (Array.isArray(d.monthlyLabels) && d.monthlyLabels.length === 6)
        ? d.monthlyLabels.map(function (iso) {
            try { return new Date(iso + 'T00:00:00').toLocaleDateString('fa-IR', { year: 'numeric', month: 'long' }); }
            catch (e) { return iso; }
          })
        : ['۵ ماه پیش', '۴ ماه', '۳ ماه', '۲ ماه', 'ماه قبل', 'این ماه'];
      var curs   = Array.isArray(d.currencies) ? d.currencies : [];

      // فهرست انتخاب: «همه» + هر ارزی که در ۶ ماه اخیر معامله داشته
      var CUR_FA = { USDT:'تتر', USD:'دلار', EUR:'یورو', IRR:'تومان' };
      var views = [{
        code: '__all__', label: 'همه',
        monthly: d.monthly || [],
        monthlyAmt: d.monthlyAmt || [],
        volume: d.monthlyTotal || 0,
        count: curs.reduce(function (s, c) { return s + (c.count || 0); }, 0),
        amount: null
      }].concat(curs.map(function (c) {
        return { code: c.code, label: c.code, monthly: c.monthly || [],
                 monthlyAmt: c.monthlyAmt || [], monthlyBuy: c.monthlyBuy || [],
                 monthlySell: c.monthlySell || [],
                 volume: c.volume || 0, count: c.count || 0, amount: c.amount || 0 };
      }));

      var active = 0;

      function drawSpark(view) {
        var isAll = (view.code === '__all__');
        var curFa = isAll ? '' : (CUR_FA[view.code] || view.code);
        var data = (view.monthly || []).map(function (v, i) {
          var amt  = Number((view.monthlyAmt  || [])[i] || 0);
          var buy  = Number((view.monthlyBuy  || [])[i] || 0);
          var sell = Number((view.monthlySell || [])[i] || 0);
          // متن حبابِ کلیک: چقدر ارز، و از آن چقدر خرید و چقدر فروش
          var meta = [];
          if (isAll) {
            if (amt) meta.push({ k: 'مقدار کل', v: compact(amt) });
          } else {
            meta.push({ k: 'مقدار', v: compact(amt) + ' ' + curFa });
            if (buy)  meta.push({ k: 'خرید',  v: compact(buy)  + ' ' + curFa, c: 'buy' });
            if (sell) meta.push({ k: 'فروش',  v: compact(sell) + ' ' + curFa, c: 'sell' });
          }
          meta.push({ k: 'ارزش', v: compact(v) + ' تومان' });
          return { v: v, label: months[i] || '', meta: meta };
        });
        while (data.length < 6) data.push({ v: 0, label: months[data.length] || '' });
        sparkHost.innerHTML = '';
        if (data.every(function (x) { return !x.v; })) {
          sparkHost.innerHTML = '<div style="color:rgba(255,255,255,.45);font-size:.8rem;text-align:center;padding:20px 0;">'
            + (view.code === '__all__' ? 'در ۶ ماه اخیر معامله‌ای ثبت نشده' : 'در ۶ ماه اخیر معامله‌ای با ' + view.label + ' نداشته‌اید')
            + '</div>';
          return;
        }
        sparkHost.appendChild(AV.spark(data, { alt: true }));
      }

      function drawMeta(view) {
        if (!metaHost) return;
        if (view.code === '__all__') {
          metaHost.innerHTML = '<span><i class="fas fa-layer-group"></i> ' + faNum(view.count) + ' معامله در ' + faNum(curs.length) + ' ارز</span>';
        } else {
          metaHost.innerHTML =
            '<span><i class="fas fa-coins"></i> ' + compact(view.amount) + ' ' + view.label + '</span>' +
            '<span><i class="fas fa-hashtag"></i> ' + faNum(view.count) + ' معامله</span>' +
            '<span><i class="fas fa-money-bill-wave"></i> ' + compact(view.volume) + ' تومان</span>';
        }
      }

      function select(i) {
        active = i;
        var view = views[i];
        if (chipHost) {
          chipHost.querySelectorAll('.exc-chip').forEach(function (b, k) {
            b.classList.toggle('active', k === i);
          });
        }
        if (sumChip) sumChip.textContent = compact(view.volume) + ' تومان';
        drawSpark(view);
        drawMeta(view);
      }

      if (chipHost) {
        if (!curs.length) {
          chipHost.style.display = 'none';
        } else {
          chipHost.innerHTML = views.map(function (v, i) {
            return '<button type="button" class="exc-chip' + (i === 0 ? ' active' : '') + '" data-i="' + i + '">'
                 + v.label + '</button>';
          }).join('');
          chipHost.querySelectorAll('.exc-chip').forEach(function (b) {
            b.addEventListener('click', function () { select(parseInt(b.dataset.i, 10)); });
          });
        }
      }

      select(0);
    }

    // به‌روزرسانی شمارنده‌های آماری بالای صفحه با انیمیشن
    var vol = document.getElementById('totalVolumeStat');
    var deals = document.getElementById('totalDealsStat');
    var pend = document.getElementById('pendingDealsStat');
    if (deals) AV.animateCount(deals, d.completed);
    if (pend) AV.animateCount(pend, d.active);
    if (vol) { vol.textContent = compact(d.totalVolume); }
    } catch (e) { if (window.console) console.warn("AvaPay charts:", e); }
  }
  boot();
})();
</script>

<script>
/* ==== کنسول کشویی نبض بازار: هماهنگی نقطه‌ها با اسکرول ==== */
(function () {
  function init() {
    var deck = document.getElementById('axnDeck'), dots = document.getElementById('axnDots');
    if (!deck || !dots) return;
    var btns = Array.prototype.slice.call(dots.querySelectorAll('button'));

    function sync() {
      var i = Math.round(deck.scrollLeft / (deck.clientWidth || 1));
      i = Math.min(btns.length - 1, Math.max(0, Math.abs(i)));
      btns.forEach(function (b, k) { b.classList.toggle('on', k === i); });
    }
    var t;
    deck.addEventListener('scroll', function () { clearTimeout(t); t = setTimeout(sync, 60); }, { passive: true });
    btns.forEach(function (b) {
      b.addEventListener('click', function () {
        var i = parseInt(b.dataset.i, 10) || 0;
        var dir = getComputedStyle(deck).direction === 'rtl' ? -1 : 1;
        deck.scrollTo({ left: dir * i * deck.clientWidth, behavior: 'smooth' });
      });
    });
    // وقتی اسلاید نمودار دیده شد، دوباره با عرض درست رسم شود
    if (window.ResizeObserver) {
      var host = document.getElementById('avExSpark');
      if (host) new ResizeObserver(function () {}).observe(host);
    }
    sync();
  }
  if (document.readyState !== 'loading') init();
  else document.addEventListener('DOMContentLoaded', init);
})();
</script>

<!-- ==========================================================================
     AvaPay · «Arad Nova — میز معامله»
     تم کامل این صفحه. عمداً در انتهای <body> بارگذاری می‌شود: در بالای همین
     فایل چند بلوک استایل با !important روی همین کلاس‌ها می‌نویسند و هر شیوه‌نامه‌ای
     که در <head> بیاید بازنده‌ی کسکید می‌شود.
     ========================================================================== -->
<link rel="stylesheet" href="assets/css/arad-nova.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/arad-nova.css') ?: time(); ?>">
<script src="assets/js/referral-box.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/referral-box.js') ?: time(); ?>"></script>
</body>
</html>