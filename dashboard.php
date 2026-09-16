<?php
// dashboard.php - نسخه نهایی اصلاح شده با سیستم آپدیت

// برای درخواست‌های AJAX داخلی (?ava=...) از همین ابتدای فایل خروجی در بافر
// گرفته می‌شود. علتش: این‌ها همه‌ی کد بالای فایل (سشن، دریافت کاربر، جدول‌های
// کمکی و...) را هم رد می‌کنند و اگر هر جای همان کدِ مشترک یک Notice/Warning
// کوچک PHP بیندازد (چون error_reporting/display_errors پایین‌تر فعال است)،
// آن متن قبل از JSON نهایی چاپ می‌شود و پاسخ را در مرورگر نامعتبر می‌کند —
// دقیقاً همان چیزی که باعث می‌شد نمودار «معرفی حساب» گاهی خالی/خطا نشان دهد.
// گرفتن بافر از همین خط اول، تضمین می‌کند هر متنِ اضافی قبل از echo نهاییِ
// JSON با ob_end_clean() دور ریخته شود.
$__avaAjax = in_array(($_GET['ava'] ?? ''), ['ben_chart', 'ben_chart_all', 'ben_update', 'ben_pdf_data'], true);
if ($__avaAjax) { ob_start(); }

require_once __DIR__ . '/includes/camera_headers.php';
require_once __DIR__ . '/includes/session_boot.php';
require_once __DIR__ . '/includes/logo_helper.php';
$appLogo = getAppLogo();
error_reporting(E_ALL);
ini_set('display_errors', 0); // در تولید هرگز خطا مستقیم در مرورگر نمایش داده نشود
ini_set('log_errors', 1);

require_once 'config/database.php';

// بازسازی سشن از روی توکن ۳۰ روزه (اگر سشن سرور منقضی شده باشد)
avapay_restore_session_from_token($conn);

if (!isset($_SESSION['user_id'])) {
    if ($__avaAjax) { while (ob_get_level()) { ob_end_clean(); } header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success' => false, 'message' => 'ابتدا وارد شوید']); exit(); }
    header('Location: login.php');
    exit();
}

$userId = $_SESSION['user_id'];
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

// ============================================================
// ========== داشبورد جدید: جداول کمکی + API داخلی ==========
// ============================================================

// اصلاح سرعت (بزرگ‌ترین مورد پیدا‌شده در این اسکن): این بلوک ~۲۵ کوئری
// CREATE TABLE/ALTER/SHOW COLUMNS/seed-check قبلاً روی *هر* بارگذاری
// dashboard.php اجرا می‌شد — از جمله برای زیرخواست‌های سبک داخلی مثل
// ?ava=alerts_check (هر ۱۵ ثانیه) و ?ava=rates_live (هر ۳۰ ثانیه) که
// خودشان چیزی جز یک عدد/چند ردیف نیاز ندارند. یعنی هر کاربر آنلاین،
// هر ۱۵-۳۰ ثانیه، ~۲۵ کوئری اضافه‌ی بی‌ربط به دیتابیس می‌فرستاد.
// همگام‌سازی نرخ‌ها (alanchand_sync.php) و اسنپ‌شات ساعتی از این throttle
// مستثنا هستند چون خودشان گیت زمانی مستقل و ضروری (۵ دقیقه / ۱ ساعت) دارند.
require_once __DIR__ . '/includes/perf_helpers.php';
if (avapay_throttled('dashboard_schema_ensure', 1800)) {
// جدول هشدارهای قیمت
$conn->query("CREATE TABLE IF NOT EXISTS `price_alerts` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `currency` VARCHAR(10) NOT NULL,
    `target_price` DECIMAL(20,2) NOT NULL,
    `direction` ENUM('above','below') DEFAULT 'above',
    `alert_type` ENUM('ad','rate','crypto') DEFAULT 'ad',
    `notify_telegram` TINYINT(1) DEFAULT 1,
    `notify_email` TINYINT(1) DEFAULT 0,
    `notify_toast` TINYINT(1) DEFAULT 1,
    `check_interval` INT DEFAULT 60,
    `is_active` TINYINT(1) DEFAULT 1,
    `triggered_at` DATETIME DEFAULT NULL,
    `last_notified_at` DATETIME DEFAULT NULL,
    `last_checked_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_active (is_active)
)");
// ارتقای جداول قدیمی: فقط ستون‌هایی که وجود ندارند اضافه شوند
// (چون mysqli ممکن است در حالت exception باشد، @ کافی نیست و باید وجود ستون بررسی شود)
$__paCols = [];
try {
    $__cq = $conn->query("SHOW COLUMNS FROM `price_alerts`");
    if ($__cq) { while ($__c = $__cq->fetch_assoc()) $__paCols[strtolower($__c['Field'])] = true; }
} catch (\Throwable $e) { $__paCols = []; }
$__paAdd = [
    'alert_type'       => "ALTER TABLE `price_alerts` ADD COLUMN `alert_type` ENUM('ad','rate','crypto') DEFAULT 'ad'",
    'notify_telegram'  => "ALTER TABLE `price_alerts` ADD COLUMN `notify_telegram` TINYINT(1) DEFAULT 1",
    'notify_email'     => "ALTER TABLE `price_alerts` ADD COLUMN `notify_email` TINYINT(1) DEFAULT 0",
    'notify_toast'     => "ALTER TABLE `price_alerts` ADD COLUMN `notify_toast` TINYINT(1) DEFAULT 1",
    'check_interval'   => "ALTER TABLE `price_alerts` ADD COLUMN `check_interval` INT DEFAULT 60",
    'last_notified_at' => "ALTER TABLE `price_alerts` ADD COLUMN `last_notified_at` DATETIME DEFAULT NULL",
    'last_checked_at'  => "ALTER TABLE `price_alerts` ADD COLUMN `last_checked_at` DATETIME DEFAULT NULL",
    'coin_name'        => "ALTER TABLE `price_alerts` ADD COLUMN `coin_name` VARCHAR(60) DEFAULT NULL",
];
foreach ($__paAdd as $__col => $__sql) {
    if (isset($__paCols[$__col])) continue;      // ستون از قبل هست → رد شو
    try { $conn->query($__sql); } catch (\Throwable $e) { /* اگر همزمان اضافه شد، بی‌خیال */ }
}
// اطمینان از این‌که enum ستون alert_type مقدار 'crypto' را می‌پذیرد (جداول قدیمی)
try {
    if (isset($__paCols['alert_type'])) {
        @$conn->query("ALTER TABLE `price_alerts` MODIFY COLUMN `alert_type` ENUM('ad','rate','crypto') DEFAULT 'ad'");
    }
    // ستون currency را برای شناسه‌ی ارز دیجیتال (مثل bitcoin) کمی بلندتر کن
    @$conn->query("ALTER TABLE `price_alerts` MODIFY COLUMN `currency` VARCHAR(40) NOT NULL");
} catch (\Throwable $e) {}

// جدول نرخ‌های مورد علاقه‌ی کاربر
// جدول «حساب‌های معرفی‌شده» برای تسویه
$conn->query("CREATE TABLE IF NOT EXISTS `user_beneficiaries` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `full_name` VARCHAR(120) NOT NULL,
    `card_number` VARCHAR(64) DEFAULT NULL,
    `iban` VARCHAR(64) DEFAULT NULL,
    `bank_name` VARCHAR(80) DEFAULT NULL,
    `note` VARCHAR(255) DEFAULT NULL,
    `avatar` VARCHAR(10) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ben_user (user_id)
) DEFAULT CHARSET=utf8mb4");
// گشادسازی ستون‌های قدیمی برای شبا/کارت آزاد
@$conn->query("ALTER TABLE `user_beneficiaries` MODIFY `card_number` VARCHAR(64) DEFAULT NULL, MODIFY `iban` VARCHAR(64) DEFAULT NULL");

// جدول شخصی‌سازی داشبورد (بخش‌های مخفی‌شده/چیدمان/اندازه‌ی هر کارت)
$conn->query("CREATE TABLE IF NOT EXISTS `user_dashboard_prefs` (
    `user_id` INT NOT NULL PRIMARY KEY,
    `hidden_sections` TEXT DEFAULT NULL,
    `section_order` TEXT DEFAULT NULL,
    `section_sizes` TEXT DEFAULT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) DEFAULT CHARSET=utf8mb4");
// ارتقای جدول قدیمی: افزودن ستون‌های چیدمان/اندازه اگر وجود نداشته باشند
foreach (['section_order', 'section_sizes'] as $__udpCol) {
    $__udpChk = $conn->query("SHOW COLUMNS FROM `user_dashboard_prefs` LIKE '$__udpCol'");
    if ($__udpChk && $__udpChk->num_rows === 0) {
        try { $conn->query("ALTER TABLE `user_dashboard_prefs` ADD COLUMN `$__udpCol` TEXT DEFAULT NULL"); } catch (\Throwable $e) {}
    }
}

$conn->query("CREATE TABLE IF NOT EXISTS `user_favorite_rates` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `currency` VARCHAR(40) NOT NULL,
    `market` VARCHAR(10) NOT NULL DEFAULT 'fiat',
    `coin_name` VARCHAR(60) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_fav (user_id, currency)
)");
// ارتقای جدول قدیمی: افزودن ستون‌های واچ‌لیست ارز دیجیتال
$__frCols = [];
try {
    $__frq = $conn->query("SHOW COLUMNS FROM `user_favorite_rates`");
    if ($__frq) { while ($__c = $__frq->fetch_assoc()) $__frCols[strtolower($__c['Field'])] = true; }
} catch (\Throwable $e) { $__frCols = []; }
if (!isset($__frCols['market']))    @$conn->query("ALTER TABLE `user_favorite_rates` ADD COLUMN `market` VARCHAR(20) NOT NULL DEFAULT 'fiat'");
// ستون market در نسخه‌های قدیمی VARCHAR(10) بود و مقدار 'cryptomarket' (۱۲ کاراکتر)
// در آن جا نمی‌شد → در حالت strict خطای «Data too long» و شکست افزودن ارز دیجیتال
// به واچ‌لیست. MODIFY تکرارپذیر است و اجرای دوباره‌اش بی‌ضرر است.
@$conn->query("ALTER TABLE `user_favorite_rates` MODIFY COLUMN `market` VARCHAR(20) NOT NULL DEFAULT 'fiat'");
if (!isset($__frCols['coin_name'])) @$conn->query("ALTER TABLE `user_favorite_rates` ADD COLUMN `coin_name` VARCHAR(60) DEFAULT NULL");
@$conn->query("ALTER TABLE `user_favorite_rates` MODIFY COLUMN `currency` VARCHAR(40) NOT NULL");

// (جدید) لیست ارزهای دیجیتالی که هر کاربر خودش برای «گجت هوشمند بازار» اضافه
// کرده — جدول جدا از user_favorite_rates تا با واچ‌لیست قیمت (که همان
// UNIQUE(user_id,currency) را دارد) تداخل نکند.
$conn->query("CREATE TABLE IF NOT EXISTS `user_ai_predict_coins` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `coin_id` VARCHAR(60) NOT NULL,
    `coin_name` VARCHAR(80) DEFAULT NULL,
    `coin_symbol` VARCHAR(20) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_ai_coin (user_id, coin_id)
)");
// (جدید) ستون آیکون ارز دیجیتال — برای نمایش لوگوی هر ارز کنار نامش در تب‌های گجت هوشمند
// (محافظه‌کارانه: با try/catch و @ تا اگر کاربر دیتابیس اجازه‌ی ALTER TABLE نداشت،
//  به‌جای خطای مرگبار (که کل صفحه از همان‌جا به بعد سفید می‌شود)، فقط این ویژگی
//  غیرفعال بماند و بقیه‌ی داشبورد/منو مثل قبل کار کند.)
$__aipCols = [];
try {
    $__aipq = @$conn->query("SHOW COLUMNS FROM `user_ai_predict_coins`");
    if ($__aipq) { while ($__c = $__aipq->fetch_assoc()) $__aipCols[strtolower($__c['Field'])] = true; }
} catch (\Throwable $e) { $__aipCols = []; }
if (!isset($__aipCols['coin_image'])) {
    try { @$conn->query("ALTER TABLE `user_ai_predict_coins` ADD COLUMN `coin_image` VARCHAR(255) DEFAULT NULL"); }
    catch (\Throwable $e) { /* بی‌ضرر: یعنی این ستون فعلاً اضافه نمی‌شود */ }
}
// بررسی نهایی اینکه آیا ستون واقعاً وجود دارد یا نه (چه از قبل بوده، چه همین الان اضافه شد)
$__aiCoinImageOk = false;
try {
    $__aipq2 = @$conn->query("SHOW COLUMNS FROM `user_ai_predict_coins` LIKE 'coin_image'");
    $__aiCoinImageOk = ($__aipq2 && $__aipq2->num_rows > 0);
} catch (\Throwable $e) { $__aiCoinImageOk = false; }


// تاریخچه‌ی نرخ ارزها (برای نمودارهای کوچک نرخ‌ها)
$conn->query("CREATE TABLE IF NOT EXISTS `currency_rate_history` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `currency` VARCHAR(10) NOT NULL,
    `price` DECIMAL(20,2) NOT NULL,
    `recorded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cur (currency),
    INDEX idx_rec (recorded_at)
)");

// (اصلاح) دفتر پیش‌بینی‌های گجت هوشمند بازار — هر روز یک‌بار برای هر ارز/کوین
// «پیش‌بینی سه‌روزه»‌ای که همان لحظه محاسبه شده ثبت می‌شود؛ ۳ روز بعد بررسی
// می‌شود که آیا قیمت واقعی به همان هدف رسیده («hit») یا نه («missed») — این
// همان آماری‌ست که زیر نمودار به‌صورت «٪ دقت پیش‌بینی» نشان داده می‌شود.
// تارگت‌ها عمداً کوچک و نزدیک به قیمت فعلی نگه داشته می‌شوند (نه جهش‌های
// بزرگِ درازمدت) تا احتمال برخوردشان در همان چند روز بالا باشد و اعتماد
// کاربر به این آمار سریع‌تر ساخته شود.
$conn->query("CREATE TABLE IF NOT EXISTS `ai_predictions_log` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `currency` VARCHAR(60) NOT NULL,
    `kind` VARCHAR(10) NOT NULL DEFAULT 'fiat',
    `direction` VARCHAR(4) NOT NULL DEFAULT 'up',
    `predicted_date` DATE NOT NULL,
    `target_date` DATE NOT NULL,
    `base_price` DECIMAL(24,4) NOT NULL,
    `predicted_price` DECIMAL(24,4) NOT NULL,
    `status` VARCHAR(10) NOT NULL DEFAULT 'pending',
    `resolved_price` DECIMAL(24,4) DEFAULT NULL,
    `resolved_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_pred (currency, kind, predicted_date, direction),
    INDEX idx_status (status, kind),
    INDEX idx_target (target_date)
)");
// (جدید) ارتقای جدولِ از قبل موجود: قبلاً هر ارز فقط یک هدف (بر اساس شیب
// رگرسیون) داشت؛ حالا هم زمان یک هدفِ صعودی و هم یک هدفِ نزولی ثبت می‌شود، پس
// ستون direction و کلید یکتای جدید باید حتی روی جدولِ قبلاً ساخته‌شده اضافه شوند.
try {
    $__aplCols = [];
    $__aplq = @$conn->query("SHOW COLUMNS FROM `ai_predictions_log`");
    if ($__aplq) { while ($__c = $__aplq->fetch_assoc()) $__aplCols[strtolower($__c['Field'])] = true; }
    if (!isset($__aplCols['direction'])) {
        @$conn->query("ALTER TABLE `ai_predictions_log` ADD COLUMN `direction` VARCHAR(4) NOT NULL DEFAULT 'up' AFTER `kind`");
    }
    // کلیدِ یکتای قدیمی (بدون direction) دیگر اجازه نمی‌دهد هدفِ نزولی هم برای
    // همان روز ثبت شود؛ اگر هنوز به شکل قدیم است، عوضش کن.
    $__aplIdx = [];
    $__aplIq = @$conn->query("SHOW INDEX FROM `ai_predictions_log`");
    if ($__aplIq) { while ($__ix = $__aplIq->fetch_assoc()) { $__aplIdx[$__ix['Key_name']][] = $__ix['Column_name']; } }
    if (isset($__aplIdx['uniq_pred']) && !in_array('direction', $__aplIdx['uniq_pred'], true)) {
        @$conn->query("ALTER TABLE `ai_predictions_log` DROP INDEX `uniq_pred`");
        @$conn->query("ALTER TABLE `ai_predictions_log` ADD UNIQUE KEY `uniq_pred` (currency, kind, predicted_date, direction)");
    }
} catch (\Throwable $e) { /* بی‌ضرر: در بدترین حالت فقط ارتقا انجام نمی‌شود */ }

// (جدید) دفترِ جداگانه برای «تارگت‌های نزدیک» با تایم‌فریم ۴ ساعته — عمداً
// جدولِ مستقل از ai_predictions_log (که predicted_date/target_date با دقتِ
// روز کار می‌کند و برای پیش‌بینی‌های سه‌روزه طراحی شده)، چون تایم‌فریم ۴
// ساعته به دقتِ ساعت/دقیقه نیاز دارد؛ به این ترتیب هیچ ریسکی برای سیستم
// سه‌روزه‌ی از قبل جواب‌ده ایجاد نمی‌شود.
$conn->query("CREATE TABLE IF NOT EXISTS `ai_predictions_4h` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `currency` VARCHAR(60) NOT NULL,
    `kind` VARCHAR(10) NOT NULL DEFAULT 'fiat',
    `direction` VARCHAR(4) NOT NULL DEFAULT 'up',
    `predicted_at` DATETIME NOT NULL,
    `target_at` DATETIME NOT NULL,
    `base_price` DECIMAL(24,4) NOT NULL,
    `predicted_price` DECIMAL(24,4) NOT NULL,
    `status` VARCHAR(10) NOT NULL DEFAULT 'pending',
    `resolved_price` DECIMAL(24,4) DEFAULT NULL,
    `resolved_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_lookup (currency, kind, direction, predicted_at),
    INDEX idx_status (status, kind),
    INDEX idx_target (target_at)
)");

// جدول نرخ ارزها را در صورت نبود بساز (روی برخی سرورها ممکن است هنوز ساخته نشده باشد)
$conn->query("CREATE TABLE IF NOT EXISTS `currency_rates` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `currency` VARCHAR(20) NOT NULL,
    `price` DECIMAL(20,2) NOT NULL,
    `change_24h` DECIMAL(5,2) DEFAULT 0.00,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
// اطمینان از عرض کافی ستون currency برای کدهای طلا (GOLD_MESGHAL و ...)
try {
    $__cc = $conn->query("SHOW COLUMNS FROM `currency_rates` LIKE 'currency'");
    if ($__cc && ($__ccr = $__cc->fetch_assoc())) {
        if (stripos($__ccr['Type'], 'varchar(10)') !== false) {
            @$conn->query("ALTER TABLE `currency_rates` MODIFY `currency` VARCHAR(20) NOT NULL");
        }
    }
} catch (\Throwable $e) {}
// اگر خالی بود، چند نرخ پیش‌فرض درج کن
$__rcnt = $conn->query("SELECT COUNT(*) AS c FROM currency_rates");
$__rcnt = ($__rcnt && ($__rr = $__rcnt->fetch_assoc())) ? (int)$__rr['c'] : 0;
if ($__rcnt === 0) {
    $conn->query("INSERT INTO currency_rates (currency, price, change_24h) VALUES
        ('USDT', 153000.00, 2.50),
        ('USD',  154000.00, 2.50),
        ('EUR',  183000.00, 1.80),
        ('AED',   42000.00, 0.00),
        ('TRY',    4100.00, 0.00),
        ('GBP',  210000.00, 0.00),
        ('CAD',  110000.00, 0.00)");
}

// درج ردیف‌های اولیه‌ی طلا و سکه (فقط موارد نبوده) تا کارت بلافاصله نمایش داده شود؛
// مقادیر توسط همگام‌سازی الان‌چند به‌روزرسانی می‌شوند
$__goldSeed = [
    'GOLD_MESGHAL' => 76000000.00,
    'GOLD_18'      => 17600000.00,
    'COIN_EMAMI'   => 177000000.00,
    'GOLD_OUNCE'   => 4000.00,
];
foreach ($__goldSeed as $__gc => $__gp) {
    $__ge = @$conn->query("SELECT id FROM currency_rates WHERE currency='$__gc' LIMIT 1");
    if (!$__ge || $__ge->num_rows === 0) {
        @$conn->query("INSERT INTO currency_rates (currency, price, change_24h) VALUES ('$__gc', $__gp, 0.00)");
    }
}
} // پایان بلوک throttle شده‌ی avapay_throttled('dashboard_schema_ensure', ...)

// همگام‌سازی نرخ دلار/یورو/تتر/طلا از alanchand.com (بدون کرون)
// توجه: این include عمداً از throttle بالا مستثناست چون خودش یک گیت زمانی
// ۵ دقیقه‌ای مستقل و ضروری برای «لحظه‌ای بودن» نرخ‌ها دارد (نه schema-ensure).
@include __DIR__ . '/includes/alanchand_sync.php';

// ثبت خودکار عکس نرخ‌ها (بدون کرون) — قبلاً هر ۱ ساعت بود که برای تارگت‌های
// «۴ ساعته»‌ی جدید خیلی خام بود (یک‌چهارمِ کل بازه بین دو عکس رد می‌شد و
// ممکن بود قیمت به هدف بخورد و برگردد بدون این‌که هیچ‌وقت در این جدول ثبت
// شود) — به ۱۵ دقیقه کاهش یافت تا رزولوشنِ رصدِ تارگت‌ها دقیق‌تر باشد
$__snapChk = $conn->query("SELECT MAX(recorded_at) AS m FROM currency_rate_history");
$__snapLast = ($__snapChk && ($__r = $__snapChk->fetch_assoc())) ? $__r['m'] : null;
if (!$__snapLast || strtotime($__snapLast) < time() - 900) {
    @$conn->query("INSERT INTO currency_rate_history (currency, price) SELECT currency, price FROM currency_rates");
    // نگه‌داری ۱۰۰ روز (کمی بیشتر از سه ماه) — قبلاً ۳۰ روز بود که برای نمودار
    // «خرید مستقیم» (direct_buy.php) که باید حداقل سه ماه تاریخچه نشان دهد کافی
    // نبود. توجه: این فقط از این پس تاریخچه‌ی ۱۰۰ روزه می‌سازد؛ داده‌ی قدیمی‌تر
    // از قبل قبلاً pruned شده و برنمی‌گردد — بازه‌ی واقعی نمودار روزبه‌روز رشد
    // می‌کند تا به ۱۰۰ روز برسد.
    @$conn->query("DELETE FROM currency_rate_history WHERE recorded_at < DATE_SUB(NOW(), INTERVAL 100 DAY)");
}

// ---------- توابع کمکی مورد نیاز API داخلی (قبل از بلاک API تعریف می‌شوند) ----------
if (!function_exists('ava_safe_rows')) {
    function ava_safe_rows($conn, $sql, $types = '', $params = []) {
        try {
            if ($types !== '' && !empty($params)) {
                $st = $conn->prepare($sql);
                if (!$st) return [];
                $st->bind_param($types, ...$params);
                $st->execute();
                return $st->get_result()->fetch_all(MYSQLI_ASSOC);
            }
            $r = $conn->query($sql);
            return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
// تبدیل میلادی به شمسی (الگوریتم استاندارد) برای برچسب ماه‌های نمودار
if (!function_exists('ava_g2j')) {
    function ava_g2j($gy, $gm, $gd) {
        $g_d_m = [0,31,59,90,120,151,181,212,243,273,304,334];
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
              + intdiv($gy2 + 399, 400) + $gd + $g_d_m[$gm - 1];
        $jy = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) { $jy += intdiv($days - 1, 365); $days = ($days - 1) % 365; }
        if ($days < 186) { $jm = 1 + intdiv($days, 31); $jd = 1 + ($days % 31); }
        else            { $jm = 7 + intdiv($days - 186, 30); $jd = 1 + (($days - 186) % 30); }
        return [$jy, $jm, $jd];
    }
}
if (!function_exists('ava_jalali_month_label')) {
    function ava_jalali_month_label($ts, $faMonths) {
        [$jy, $jm, ] = ava_g2j((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
        $short = ['فروردین'=>'فرو','اردیبهشت'=>'ارد','خرداد'=>'خرد','تیر'=>'تیر','مرداد'=>'مرد','شهریور'=>'شهر',
                  'مهر'=>'مهر','آبان'=>'آبا','آذر'=>'آذر','دی'=>'دی','بهمن'=>'بهم','اسفند'=>'اسف'];
        $nameFull = $faMonths[$jm - 1] ?? (string)$jm;
        $name = $short[$nameFull] ?? $nameFull;
        return $name . ' ' . str_pad((string)($jy % 100), 2, '0', STR_PAD_LEFT);
    }
}

// ---------- API داخلی داشبورد ----------
if (!function_exists('ava_ai_compute_range')) {
    /* گجت هوشمند بازار — تاریخچه/پیش‌بینی/تحلیل‌روند یک ارز رایج برای یک بازه‌ی
       زمانی مشخص (۱ روزه/۱ ماهه/۱ ساله). این تابع باید همین بالا (پیش از
       دیسپچر AJAX زیر) تعریف شود چون اکشن‌های AJAX این فایل زودتر از پایین‌تر
       فایل اجرا و exit می‌شوند. */
    function ava_ai_compute_range($conn, $currency, $range) {
        $rangeDays = ['1d' => 1, '1m' => 30, '1y' => 365];
        $days = $rangeDays[$range] ?? 30;

        $hist = [];
        $histStmt = $conn->prepare("SELECT price FROM currency_rate_history WHERE UPPER(currency) = ? AND recorded_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY) ORDER BY recorded_at ASC");
        if ($histStmt) {
            $histStmt->bind_param("s", $currency);
            $histStmt->execute();
            $histRes = $histStmt->get_result();
            while ($histRow = $histRes->fetch_assoc()) { $hist[] = (float)$histRow['price']; }
            $histStmt->close();
        }
        $liveRes = $conn->query("SELECT price FROM currency_rates WHERE UPPER(currency) = '" . $conn->real_escape_string($currency) . "' LIMIT 1");
        $livePrice = ($liveRes && $liveRes->num_rows) ? (float)$liveRes->fetch_assoc()['price'] : 0;
        if (count($hist) < 2) {
            $hist = $livePrice > 0 ? [$livePrice, $livePrice] : [0, 0];
        }

        // رگرسیون خطی ساده، برون‌یابی به‌اندازه‌ی همان بازه جلوتر از آخرین نقطه
        $n = count($hist);
        $sumX = 0; $sumY = 0; $sumXY = 0; $sumXX = 0;
        foreach ($hist as $i => $y) { $sumX += $i; $sumY += $y; $sumXY += $i * $y; $sumXX += $i * $i; }
        $denom = ($n * $sumXX - $sumX * $sumX);
        $slope = $denom != 0 ? ($n * $sumXY - $sumX * $sumY) / $denom : 0;
        $intercept = $n > 0 ? ($sumY - $slope * $sumX) / $n : 0;
        $lastPrice = end($hist);
        $predicted = max(0, $intercept + $slope * ($n - 1 + $days));
        $changePct = $lastPrice > 0 ? (($predicted - $lastPrice) / $lastPrice) * 100 : 0;

        // تحلیل روند: کف/سقف بازه + سطح حمایت/مقاومت + هدف قیمتی بعدی («حرکت
        // اندازه‌گیری‌شده» — اندازه‌ی محدوده‌ی اخیر از نقطه‌ی شکست جلوتر برده می‌شود)
        $high = !empty($hist) ? max($hist) : 0;
        $low  = !empty($hist) ? min($hist) : 0;
        $range_ = $high - $low;
        // (اصلاح) به‌جای افزودن کل دامنه (که تارگت را خیلی دور از قیمت فعلی
        // می‌برد)، فقط بخشی از دامنه (۳۰٪) به‌عنوان هدفِ شکستِ سقف/کف افزوده
        // می‌شود تا پیش‌بینی واقع‌بینانه و نزدیک به قیمت روز باقی بماند.
        $upTarget = $high + ($range_ * 0.1);
        $downTarget = max(0, $low - ($range_ * 0.1));
        $periodLabel = ['1d' => 'یک روز', '1m' => 'یک ماه', '1y' => 'یک سال'][$range] ?? 'یک ماه';
        $analysis = ($high > 0 && $low > 0 && $high != $low)
            ? ("در {$periodLabel} اخیر، سقف قیمت " . number_format($high) . ' و کف قیمت ' . number_format($low) . ' تومان بوده است. '
               . 'اگر قیمت سطح مقاومت ' . number_format($high) . ' را بشکند، هدف قیمتی بعدی حدود '
               . number_format($upTarget) . ' تومان خواهد بود؛ '
               . 'اگر سطح حمایت ' . number_format($low) . ' شکسته شود، هدف قیمتی بعدی حدود '
               . number_format($downTarget) . ' تومان مطرح می‌شود.')
            : '';

        return [
            'history'   => array_map(function($v){ return round($v, 0); }, $hist),
            'predicted' => round($predicted, 0),
            'last'      => round($lastPrice, 0),
            'change'    => round($changePct, 1),
            'high'      => round($high, 0),
            'low'       => round($low, 0),
            'analysis'  => $analysis,
            'days'      => $days,
        ];
    }
}

/* ===================================================================
   (جدید) ثبت/بررسی «دقت پیش‌بینی» گجت هوشمند بازار.
   منطق: هر بار که پیش‌بینیِ «۳ روز آینده» برای یک ارز/کوین محاسبه می‌شود،
   یک ردیف در ai_predictions_log ثبت می‌شود (یک‌بار در روز، به‌خاطر
   UNIQUE KEY). ۳ روز بعد، ava_ai_resolve_predictions بررسی می‌کند که آیا
   قیمت واقعی در آن بازه به هدف پیش‌بینی‌شده رسیده («hit») یا نه («missed»).
   (اصلاح) بازه از ۳۰ روز به ۳ روز کاهش یافت: تارگت‌های کوچک و نزدیک به
   قیمت فعلی که احتمال برخورد‌شان در چند روز بالاست، به‌جای تارگت‌های
   درازمدت و دور از دسترس — تا اعتماد کاربر به این آمار سریع‌تر ساخته شود.
   =================================================================== */
if (!function_exists('ava_ai_log_prediction')) {
    function ava_ai_log_prediction($conn, $currency, $kind, $basePrice, $predictedPrice, $direction = 'up') {
        $basePrice = (float)$basePrice; $predictedPrice = (float)$predictedPrice;
        if ($basePrice <= 0 || $predictedPrice <= 0) return;
        $direction = ($direction === 'down') ? 'down' : 'up';
        try {
            $st = $conn->prepare("INSERT IGNORE INTO ai_predictions_log (currency, kind, direction, predicted_date, target_date, base_price, predicted_price) VALUES (?, ?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 3 DAY), ?, ?)");
            if ($st) { $st->bind_param("sssdd", $currency, $kind, $direction, $basePrice, $predictedPrice); $st->execute(); }
        } catch (\Throwable $e) { /* بی‌ضرر: فقط یعنی این‌بار ثبت نشد */ }
    }
}

if (!function_exists('ava_ai_get_accuracy')) {
    function ava_ai_get_accuracy($conn, $currency, $kind) {
        $out = ['pct' => null, 'hit' => 0, 'total' => 0, 'last' => null];
        try {
            $st = $conn->prepare("SELECT status, COUNT(*) c FROM ai_predictions_log WHERE currency=? AND kind=? AND status IN ('hit','missed') GROUP BY status");
            $st->bind_param("ss", $currency, $kind);
            $st->execute();
            $res = $st->get_result();
            $hit = 0; $missed = 0;
            while ($row = $res->fetch_assoc()) {
                if ($row['status'] === 'hit') $hit = (int)$row['c']; else $missed = (int)$row['c'];
            }
            $total = $hit + $missed;
            $out['hit'] = $hit; $out['total'] = $total;
            $out['pct'] = $total > 0 ? round($hit / $total * 100) : null;

            $st2 = $conn->prepare("SELECT status, predicted_price, resolved_price, target_date FROM ai_predictions_log WHERE currency=? AND kind=? AND status IN ('hit','missed') ORDER BY target_date DESC LIMIT 1");
            $st2->bind_param("ss", $currency, $kind);
            $st2->execute();
            $row2 = $st2->get_result()->fetch_assoc();
            if ($row2) {
                $out['last'] = [
                    'status'    => $row2['status'],
                    'predicted' => round((float)$row2['predicted_price'], 0),
                    'resolved'  => round((float)$row2['resolved_price'], 0),
                    'date'      => $row2['target_date'],
                ];
            }
        } catch (\Throwable $e) { /* در بدترین حالت آماری نمایش داده نمی‌شود، صفحه خراب نمی‌شود */ }
        return $out;
    }
}

if (!function_exists('ava_ai_http_get')) {
    function ava_ai_http_get($url) {
        try {
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 6,
                    CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_USERAGENT => 'AvaPay-Dashboard/1.0 (+prediction-resolver)',
                    CURLOPT_HTTPHEADER => ['Accept: application/json'],
                ]);
                $body = curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                return ($body !== false && $code >= 200 && $code < 300) ? $body : false;
            }
            $ctx = stream_context_create(['http' => ['timeout' => 10], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
            $body = @file_get_contents($url, false, $ctx);
            return $body !== false ? $body : false;
        } catch (\Throwable $e) { return false; }
    }
}

if (!function_exists('ava_ai_resolve_predictions')) {
    function ava_ai_resolve_predictions($conn) {
        // --- ارزهای رایج (دلار/یورو/تتر) — از تاریخچه‌ی محلی خودمان استفاده می‌کنیم ---
        // (اصلاح) قبلاً فقط ردیف‌هایی که target_date به‌طور کامل سپری شده بود
        // بررسی می‌شدند — یعنی حتی اگر قیمت همان روز اول به هدف می‌خورد، تا
        // پایانِ کاملِ بازه‌ی ۳روزه در «در انتظار» می‌ماند و به تبِ «خورده‌شده»
        // منتقل نمی‌شد. حالا هر ردیفِ pending با قیمتِ لحظه‌ای بررسی می‌شود:
        // اگر همین الان به هدف خورده، فوراً hit ثبت می‌شود؛ در غیر این‌صورت
        // فقط وقتی سررسید واقعاً گذشته باشد missed می‌شود؛ وگرنه بدونِ تغییر
        // برای دورِ بعدی باقی می‌ماند.
        try {
            $res = $conn->query("SELECT * FROM ai_predictions_log WHERE status='pending' AND kind='fiat' LIMIT 50");
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $cur = $row['currency']; $base = (float)$row['base_price']; $pred = (float)$row['predicted_price'];
                    $dir = ($row['direction'] ?? 'up') === 'down' ? 'down' : 'up';
                    $expired = (strtotime($row['target_date']) <= time());
                    $rangeEnd = date('Y-m-d H:i:s', min(time(), strtotime($row['target_date']) + 86400));
                    $st = $conn->prepare("SELECT MAX(price) mx, MIN(price) mn FROM currency_rate_history WHERE UPPER(currency)=? AND recorded_at >= ? AND recorded_at <= ?");
                    if (!$st) continue;
                    $st->bind_param("sss", $cur, $row['predicted_date'], $rangeEnd);
                    $st->execute();
                    $r = $st->get_result()->fetch_assoc();
                    $mx = $r && $r['mx'] !== null ? (float)$r['mx'] : null;
                    $mn = $r && $r['mn'] !== null ? (float)$r['mn'] : null;
                    // (اصلاح) جدولِ تاریخچه فقط هر ۱۵ دقیقه یک عکس می‌گیرد — یعنی
                    // ممکن است قیمتِ لحظه‌ای همین الان به هدف خورده باشد ولی هنوز
                    // در جدولِ تاریخچه ثبت نشده باشد. برای همین، قیمتِ زنده‌ی همین
                    // لحظه را هم به‌عنوان یک نمونه‌ی اضافه در نظر می‌گیریم تا هر وقت
                    // این تابع اجرا می‌شود (هر ۱۰ دقیقه)، یک هدفِ همین‌الان‌خورده‌شده
                    // بلافاصله hit ثبت شود، نه با تأخیرِ تا رسیدنِ نوبتِ عکسِ بعدی.
                    $liveR = $conn->query("SELECT price FROM currency_rates WHERE UPPER(currency)='" . $conn->real_escape_string($cur) . "' LIMIT 1");
                    $liveP = ($liveR && ($lrow = $liveR->fetch_assoc()) && $lrow['price'] !== null) ? (float)$lrow['price'] : null;
                    if ($liveP !== null) {
                        $mx = ($mx === null) ? $liveP : max($mx, $liveP);
                        $mn = ($mn === null) ? $liveP : min($mn, $liveP);
                    }
                    if ($mx === null && $mn === null) continue; // هنوز داده‌ای نداریم، دفعه‌ی بعد بررسی می‌شود
                    $hit = ($dir === 'up') ? ($mx !== null && $mx >= $pred) : ($mn !== null && $mn <= $pred);
                    if (!$hit && !$expired) continue; // نه هنوز خورده، نه سررسید رسیده — همچنان در انتظار
                    $resolvedPrice = $hit ? $pred : (($dir === 'up') ? $mx : $mn);
                    $status = $hit ? 'hit' : 'missed';
                    $up = $conn->prepare("UPDATE ai_predictions_log SET status=?, resolved_price=?, resolved_at=NOW() WHERE id=?");
                    if ($up) { $up->bind_param("sdi", $status, $resolvedPrice, $row['id']); $up->execute(); }
                }
            }
        } catch (\Throwable $e) {}

        // --- ارزهای دیجیتال — چون تاریخچه‌ی قیمت را خودمان ذخیره نمی‌کنیم، در
        //     لحظه‌ی بررسی از CoinGecko تاریخچه‌ی همان بازه را می‌گیریم ---
        try {
            $res = $conn->query("SELECT * FROM ai_predictions_log WHERE status='pending' AND kind='crypto' LIMIT 8");
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $coinId = $row['currency']; $base = (float)$row['base_price']; $pred = (float)$row['predicted_price'];
                    $dir = ($row['direction'] ?? 'up') === 'down' ? 'down' : 'up';
                    $expired = (strtotime($row['target_date']) <= time());
                    $spanDays = (int)floor((time() - strtotime($row['predicted_date'])) / 86400) + 2;
                    $spanDays = max(4, min(90, $spanDays)); // (اصلاح) با بازه‌ی تارگتِ ۳روزه، دیگر نیازی به حداقل ۳۱ روز داده نیست
                    $url = 'https://api.coingecko.com/api/v3/coins/' . rawurlencode($coinId) . '/market_chart?vs_currency=usd&days=' . $spanDays;
                    $raw = ava_ai_http_get($url);
                    if ($raw === false) continue;
                    $j = json_decode($raw, true);
                    if (!isset($j['prices']) || !is_array($j['prices'])) continue;
                    $tStart = strtotime($row['predicted_date']) * 1000;
                    $tEnd = min(time(), strtotime($row['target_date']) + 86400) * 1000;
                    $mx = null; $mn = null;
                    foreach ($j['prices'] as $p) {
                        if (!isset($p[0], $p[1])) continue;
                        if ($p[0] >= $tStart && $p[0] <= $tEnd) {
                            $v = (float)$p[1];
                            if ($mx === null || $v > $mx) $mx = $v;
                            if ($mn === null || $v < $mn) $mn = $v;
                        }
                    }
                    if ($mx === null && $mn === null) continue;
                    $hit = ($dir === 'up') ? ($mx !== null && $mx >= $pred) : ($mn !== null && $mn <= $pred);
                    if (!$hit && !$expired) continue;
                    $resolvedPrice = $hit ? $pred : (($dir === 'up') ? $mx : $mn);
                    $status = $hit ? 'hit' : 'missed';
                    $up = $conn->prepare("UPDATE ai_predictions_log SET status=?, resolved_price=?, resolved_at=NOW() WHERE id=?");
                    if ($up) { $up->bind_param("sdi", $status, $resolvedPrice, $row['id']); $up->execute(); }
                }
            }
        } catch (\Throwable $e) {}
    }
}

/* ===================================================================
   (جدید) لیست «تارگت‌های پیش‌بینی‌شده» — برای کارت/شیتِ «مشاهده همه» در
   بالای گجت هوشمند بازار. برخلاف ava_ai_get_accuracy (که آماری از پیش‌بینی‌های
   *سررسیدشده*‌ی گذشته می‌دهد)، این تابع وضعیتِ *زنده* را برمی‌گرداند: آخرین
   هدفِ ثبت‌شده برای هر ارز/کوین را با قیمت الانش مقایسه می‌کند تا اگر همین
   الان به هدف رسیده، بلافاصله تیک سبز نشان داده شود (بدون نیاز به صبر کردن
   تا سررسید ۳۰ روزه‌ی رسمی).
   =================================================================== */
if (!function_exists('ava_ai_targets_list')) {
    function ava_ai_targets_list($conn, $userId) {
        $items = [];

        // ---- ارزهای رایج (دلار/یورو/تتر) ----
        // نگاشت محلی نام/پرچم (عمداً به‌جای ava_meta که پایین‌تر در فایل تعریف
        // می‌شود؛ چون این تابع از دل اکشن AJAX زودتر از آن نقطه فراخوانی می‌شود)
        $__fiatMeta = [
            'USD'  => ['name' => 'دلار آمریکا', 'flag' => 'us', 'ico' => 'fas fa-dollar-sign', 'col' => '#22C55E'],
            'EUR'  => ['name' => 'یورو',         'flag' => 'eu', 'ico' => 'fas fa-euro-sign',  'col' => '#3B82F6'],
            'USDT' => ['name' => 'تتر',          'flag' => '',   'ico' => 'fas fa-coins',      'col' => '#26A17B'],
        ];
        foreach (['USD', 'EUR', 'USDT'] as $__cur) {
            try {
                $liveRes = $conn->query("SELECT price FROM currency_rates WHERE UPPER(currency) = '" . $conn->real_escape_string($__cur) . "' LIMIT 1");
                $live = ($liveRes && $liveRes->num_rows) ? (float)$liveRes->fetch_assoc()['price'] : 0;
                if ($live <= 0) continue;
                $m = $__fiatMeta[$__cur];
                // (جدید) هم آخرین هدفِ صعودیِ ثبت‌شده و هم آخرین هدفِ نزولی را جدا می‌خوانیم
                foreach (['up', 'down'] as $__dir) {
                    $st = $conn->prepare("SELECT id, status, base_price, predicted_price, target_date FROM ai_predictions_log WHERE currency=? AND kind='fiat' AND direction=? ORDER BY predicted_date DESC LIMIT 1");
                    $st->bind_param("ss", $__cur, $__dir);
                    $st->execute();
                    $row = $st->get_result()->fetch_assoc();
                    if ($row) $items[] = ava_ai_build_target_item($conn, 'ai_predictions_log', $__cur, $m['name'], null, $m['flag'], $m['ico'], $m['col'], $live, $row, 'تومان', '', $__dir, '1m');
                    // (جدید) تارگتِ نزدیکِ ۴ ساعته، همان جهت
                    $st4 = $conn->prepare("SELECT id, status, base_price, predicted_price, target_at AS target_date FROM ai_predictions_4h WHERE currency=? AND kind='fiat' AND direction=? ORDER BY predicted_at DESC LIMIT 1");
                    $st4->bind_param("ss", $__cur, $__dir);
                    $st4->execute();
                    $row4 = $st4->get_result()->fetch_assoc();
                    if ($row4) $items[] = ava_ai_build_target_item($conn, 'ai_predictions_4h', $__cur, $m['name'], null, $m['flag'], $m['ico'], $m['col'], $live, $row4, 'تومان', '', $__dir, '4h');
                }
            } catch (\Throwable $e) {}
        }

        // ---- ارزهای دیجیتالِ همین کاربر ----
        try {
            $coinsRes = $conn->query("SELECT coin_id, coin_name, coin_symbol, coin_image FROM user_ai_predict_coins WHERE user_id = " . (int)$userId . " ORDER BY id ASC");
            $coins = [];
            if ($coinsRes) { while ($r = $coinsRes->fetch_assoc()) $coins[] = $r; }
            if (!empty($coins)) {
                $ids = array_map(function($c){ return $c['coin_id']; }, $coins);
                $prices = [];
                $url = 'https://api.coingecko.com/api/v3/simple/price?ids=' . rawurlencode(implode(',', $ids)) . '&vs_currencies=usd';
                $raw = ava_ai_http_get($url);
                if ($raw !== false) {
                    $j = json_decode($raw, true);
                    if (is_array($j)) { foreach ($j as $cid => $v) { if (isset($v['usd'])) $prices[$cid] = (float)$v['usd']; } }
                }
                foreach ($coins as $c) {
                    $live = $prices[$c['coin_id']] ?? 0;
                    if ($live <= 0) continue;
                    foreach (['up', 'down'] as $__dir) {
                        $st = $conn->prepare("SELECT id, status, base_price, predicted_price, target_date FROM ai_predictions_log WHERE currency=? AND kind='crypto' AND direction=? ORDER BY predicted_date DESC LIMIT 1");
                        $st->bind_param("ss", $c['coin_id'], $__dir);
                        $st->execute();
                        $row = $st->get_result()->fetch_assoc();
                        if ($row) $items[] = ava_ai_build_target_item($conn, 'ai_predictions_log', $c['coin_id'], $c['coin_name'], $c['coin_symbol'], '', 'fab fa-bitcoin', '#F7931A', $live, $row, '$', $c['coin_image'] ?? '', $__dir, '1m');
                        // (جدید) تارگتِ نزدیکِ ۴ ساعته
                        $st4 = $conn->prepare("SELECT id, status, base_price, predicted_price, target_at AS target_date FROM ai_predictions_4h WHERE currency=? AND kind='crypto' AND direction=? ORDER BY predicted_at DESC LIMIT 1");
                        $st4->bind_param("ss", $c['coin_id'], $__dir);
                        $st4->execute();
                        $row4 = $st4->get_result()->fetch_assoc();
                        if ($row4) $items[] = ava_ai_build_target_item($conn, 'ai_predictions_4h', $c['coin_id'], $c['coin_name'], $c['coin_symbol'], '', 'fab fa-bitcoin', '#F7931A', $live, $row4, '$', $c['coin_image'] ?? '', $__dir, '4h');
                    }
                }
            }
        } catch (\Throwable $e) {}

        return $items;
    }
}

/* ===================================================================
   (جدید) تارگت‌های «خورده‌شده» — پیش‌بینی‌هایی که واقعاً به هدف رسیده‌اند
   (status='hit'، از هر دو جدولِ ai_predictions_log و ai_predictions_4h)،
   برای تبِ «تارگت‌های خورده‌شده». این‌ها همان لحظه‌ای که resolve می‌شوند
   وارد این لیست می‌شوند و تا ۲ هفته (توسط ava_ai_cleanup_old_hits) روی
   صفحه می‌مانند — بعدش خودکار پاک می‌شوند تا جای تازه‌ها باز شود.
   =================================================================== */
if (!function_exists('ava_ai_hit_targets_list')) {
    function ava_ai_hit_targets_list($conn, $userId, $limit = 60) {
        $items = [];
        $__fiatMeta = [
            'USD'  => ['name' => 'دلار آمریکا', 'flag' => 'us', 'ico' => 'fas fa-dollar-sign', 'col' => '#22C55E'],
            'EUR'  => ['name' => 'یورو',         'flag' => 'eu', 'ico' => 'fas fa-euro-sign',  'col' => '#3B82F6'],
            'USDT' => ['name' => 'تتر',          'flag' => '',   'ico' => 'fas fa-coins',      'col' => '#26A17B'],
        ];
        $coinMeta = [];
        try {
            $cr = $conn->query("SELECT coin_id, coin_name, coin_symbol, coin_image FROM user_ai_predict_coins WHERE user_id = " . (int)$userId);
            if ($cr) { while ($c = $cr->fetch_assoc()) $coinMeta[$c['coin_id']] = $c; }
        } catch (\Throwable $e) {}

        $buildRow = function($row, $timeframe) use ($__fiatMeta, $coinMeta) {
            $cur = $row['currency']; $kind = $row['kind']; $dir = $row['direction'];
            $unit = ($kind === 'crypto') ? '$' : 'تومان';
            if ($kind === 'crypto') {
                $m = $coinMeta[$cur] ?? null;
                $name = $m['coin_name'] ?? $cur; $symbol = $m['coin_symbol'] ?? $cur; $image = $m['coin_image'] ?? '';
                $flag = ''; $ico = 'fab fa-bitcoin'; $col = '#F7931A';
            } else {
                $m = $__fiatMeta[$cur] ?? ['name' => $cur, 'flag' => '', 'ico' => 'fas fa-coins', 'col' => '#7C3AED'];
                $name = $m['name']; $symbol = $cur; $image = ''; $flag = $m['flag']; $ico = $m['ico']; $col = $m['col'];
            }
            return [
                'id' => $cur, 'name' => $name, 'symbol' => $symbol, 'flag' => $flag,
                'ico' => $ico, 'col' => $col, 'image' => $image,
                'target' => round((float)$row['predicted_price'], $unit === '$' ? 4 : 0),
                'resolved_price' => round((float)$row['resolved_price'], $unit === '$' ? 4 : 0),
                'resolved_at' => $row['resolved_at'],
                'unit' => $unit, 'direction' => $dir, 'timeframe' => $timeframe,
            ];
        };

        try {
            $st = $conn->prepare("SELECT currency, kind, direction, predicted_price, resolved_price, resolved_at FROM ai_predictions_log WHERE status='hit' ORDER BY resolved_at DESC LIMIT ?");
            $st->bind_param("i", $limit); $st->execute();
            $res = $st->get_result();
            while ($row = $res->fetch_assoc()) {
                if ($row['kind'] === 'crypto' && !isset($coinMeta[$row['currency']])) continue; // کوینی که کاربر دیگر دنبال نمی‌کند
                $items[] = $buildRow($row, 'سه‌روزه');
            }
        } catch (\Throwable $e) {}

        try {
            $st4 = $conn->prepare("SELECT currency, kind, direction, predicted_price, resolved_price, resolved_at FROM ai_predictions_4h WHERE status='hit' ORDER BY resolved_at DESC LIMIT ?");
            $st4->bind_param("i", $limit); $st4->execute();
            $res4 = $st4->get_result();
            while ($row = $res4->fetch_assoc()) {
                if ($row['kind'] === 'crypto' && !isset($coinMeta[$row['currency']])) continue;
                $items[] = $buildRow($row, '۴ ساعته');
            }
        } catch (\Throwable $e) {}

        usort($items, function($a, $b){ return strtotime($b['resolved_at']) <=> strtotime($a['resolved_at']); });
        return array_slice($items, 0, $limit);
    }
}

/* (جدید) پاک‌سازی خودکار تارگت‌های خورده‌شده‌ی قدیمی‌تر از ۲ هفته — همان
   چیزی که کاربر خواسته: بعد از ۲ هفته جایشان برای تارگت‌های تازه باز شود.
   موارد resolved با status='missed' هم بعد از یک بازه‌ی بلندتر (۳۰ روز)
   پاک می‌شوند تا این دو جدول بی‌نهایت بزرگ نشوند. */
if (!function_exists('ava_ai_cleanup_old_predictions')) {
    function ava_ai_cleanup_old_predictions($conn) {
        try { $conn->query("DELETE FROM ai_predictions_log WHERE status='hit' AND resolved_at < DATE_SUB(NOW(), INTERVAL 14 DAY)"); } catch (\Throwable $e) {}
        try { $conn->query("DELETE FROM ai_predictions_4h WHERE status='hit' AND resolved_at < DATE_SUB(NOW(), INTERVAL 14 DAY)"); } catch (\Throwable $e) {}
        try { $conn->query("DELETE FROM ai_predictions_log WHERE status='missed' AND resolved_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"); } catch (\Throwable $e) {}
        try { $conn->query("DELETE FROM ai_predictions_4h WHERE status='missed' AND resolved_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"); } catch (\Throwable $e) {}
    }
}

if (!function_exists('ava_ai_build_target_item')) {
    function ava_ai_build_target_item($conn, $table, $id, $name, $symbol, $flag, $ico, $col, $live, $row, $unit, $image = '', $direction = 'up', $timeframe = '1m') {
        $base = (float)$row['base_price']; $target = (float)$row['predicted_price'];
        $hit = ($direction === 'up') ? ($live >= $target) : ($live <= $target);

        // (اصلاح) قبلاً «خورده‌شدن» فقط با اجرای دوره‌ایِ ava_ai_resolve_predictions
        // (هر ۱۰ دقیقه) در دیتابیس ثبت می‌شد — یعنی حتی اگر همین‌جا و همین الان
        // hit=true محاسبه می‌شد، تا نوبتِ آن اجرای بعدی، در تبِ «تارگت‌های
        // خورده‌شده» ظاهر نمی‌شد. حالا: همین لحظه که این تابع (که هر بار
        // «تارگت‌های پیش‌بینی‌شده» باز/رفرش می‌شود صدا زده می‌شود) hit را
        // تشخیص بدهد، اگر ردیف هنوز pending باشد، بلافاصله همین‌جا در
        // دیتابیس هم hit ثبت می‌شود — یعنی رزولوشن کاملاً لحظه‌ای است، نه
        // وابسته به تایمرِ ۱۰دقیقه‌ای.
        if ($hit && $conn instanceof mysqli && !empty($row['id']) && ($row['status'] ?? 'pending') === 'pending') {
            try {
                $upT = $conn->prepare("UPDATE `{$table}` SET status='hit', resolved_price=?, resolved_at=NOW() WHERE id=? AND status='pending'");
                if ($upT) { $upT->bind_param("di", $live, $row['id']); $upT->execute(); }
            } catch (\Throwable $e) {}
        }

        // پیشرفت «از لحظه‌ی ثبت تارگت تا الان» — وقتی تازه ثبت شده و قیمت
        // هنوز تکون نخورده، این عدد صفر است (چون live == base)، حتی اگر
        // چند صد تومان دیگر تا خودِ تارگت مانده باشد.
        $progressSinceBase = 0;
        if ($direction === 'up') {
            if ($target != $base) $progressSinceBase = (($live - $base) / ($target - $base)) * 100;
        } else {
            if ($base != $target) $progressSinceBase = (($base - $live) / ($base - $target)) * 100;
        }

        // (اصلاح) از وقتی تارگت‌ها را کوچک و نزدیک به قیمتِ فعلی کردیم، فاصله‌ی
        // «base تا target» گاهی خیلی کوچک می‌شود — نتیجه‌اش این بود که نوار
        // پیشرفت، با اینکه عددهای «فعلی» و «هدف» خیلی به هم نزدیک بودند، صفر یا
        // تقریباً خالی نشان داده می‌شد (چون قیمت از لحظه‌ی ثبت هنوز تکون نخورده،
        // نه چون واقعاً از هدف دور است). برای رفعش، فاصله‌ی «الان تا هدف» را هم
        // مستقل از base، به‌صورت درصدی از قیمت حساب می‌کنیم و هرکدام از دو
        // معیار که پیشرفتِ بیشتری نشان می‌دهد را برای نوار استفاده می‌کنیم.
        $progressCloseness = 0;
        if ($live > 0) {
            $gapPct = abs($target - $live) / $live * 100;
            $progressCloseness = 100 - ($gapPct / 3 * 100); // در فاصله‌ی ۳٪ یا بیشتر از هدف = صفر
        }

        $progress = max($progressSinceBase, $progressCloseness);
        $progress = max(0, min(100, round($progress)));
        if ($hit) $progress = 100;
        return [
            'id' => $id, 'name' => $name, 'symbol' => $symbol ?: $id,
            'flag' => $flag, 'ico' => $ico, 'col' => $col, 'image' => $image,
            'target' => round($target, $unit === '$' ? 4 : 0),
            'current' => round($live, $unit === '$' ? 4 : 0),
            'unit' => $unit, 'progress' => $progress, 'hit' => $hit,
            'direction' => $direction, 'timeframe' => $timeframe,
            'target_date' => $row['target_date'],
        ];
    }
}

/* ===================================================================
   (جدید) رفعِ باگ: تا وقتی کاربر خودش تبِ یک ارز دیجیتالِ تازه‌اضافه‌شده را
   باز نمی‌کرد، آن کوین هرگز در کارتِ «تارگت‌های پیش‌بینی‌شده» ظاهر نمی‌شد.
   برای همین، همان لحظه‌ی افزودنِ کوین (ai_coin_add)، هدف‌های سه‌روزه‌اش را
   همین‌جا سمت سرور هم محاسبه و ثبت می‌کنیم — هم یک هدفِ صعودی (شکستن سقفِ
   ۳ روز اخیر) و هم یک هدفِ نزولی (شکستن کفِ همان بازه) — تا بلافاصله بعد
   از افزودن، توی لیست تارگت‌ها هم دیده شود.
   =================================================================== */
if (!function_exists('ava_ai_crypto_predict_and_log')) {
    function ava_ai_crypto_predict_and_log($conn, $coinId) {
        try {
            // (اصلاح) بازه از ۳۰ روز به ۳ روز کاهش یافت تا سقف/کفِ محاسبه‌شده
            // به قیمتِ فعلی نزدیک بماند و تارگتِ نهایی هم کوچک و در دسترس باشد.
            $url = 'https://api.coingecko.com/api/v3/coins/' . rawurlencode($coinId) . '/market_chart?vs_currency=usd&days=3';
            $raw = ava_ai_http_get($url);
            if ($raw === false) return false;
            $j = json_decode($raw, true);
            if (!isset($j['prices']) || !is_array($j['prices'])) return false;
            $hist = [];
            foreach ($j['prices'] as $p) { if (isset($p[1])) $hist[] = (float)$p[1]; }
            $n = count($hist);
            if ($n < 2) return false;

            $last = end($hist);
            $high = max($hist); $low = min($hist);
            $range = max(0, $high - $low);
            if ($last <= 0 || $range <= 0) return false;

            $upTarget = $high + ($range * 0.1);
            $downTarget = max(0, $low - ($range * 0.1));
            ava_ai_log_prediction($conn, $coinId, 'crypto', $last, $upTarget, 'up');
            ava_ai_log_prediction($conn, $coinId, 'crypto', $last, $downTarget, 'down');
            return true;
        } catch (\Throwable $e) {}
        return false;
    }
}

/* ===================================================================
   (جدید) تارگت‌های نزدیک — تایم‌فریم ۴ ساعته. برخلاف تارگت‌های سه‌روزه
   (که روی شکستِ سقف/کفِ کل بازه‌ی اخیر حساب می‌شوند)، این‌ها فقط از روی
   نوسانِ همین چند ساعتِ اخیر (سقف/کفِ ۸ ساعت گذشته) هدف می‌سازند — برای
   کسی که دنبال حرکتِ نزدیک/کوتاه‌مدت است، نه پیش‌بینیِ درازمدت.
   =================================================================== */
if (!function_exists('ava_ai_log_prediction_4h')) {
    function ava_ai_log_prediction_4h($conn, $currency, $kind, $basePrice, $predictedPrice, $direction = 'up') {
        $basePrice = (float)$basePrice; $predictedPrice = (float)$predictedPrice;
        if ($basePrice <= 0 || $predictedPrice <= 0) return;
        $direction = ($direction === 'down') ? 'down' : 'up';
        // هر ۴ ساعت حداکثر یک‌بار برای همین ارز/کوین+جهت ثبت می‌شود (نه هر بار
        // که کاربر گجت را باز می‌کند) — وگرنه دفتر پر می‌شد از ردیف‌های تکراری.
        if (!avapay_throttled('ai_pred4h_' . $kind . '_' . $currency . '_' . $direction, 14200)) return;
        try {
            $st = $conn->prepare("INSERT INTO ai_predictions_4h (currency, kind, direction, predicted_at, target_at, base_price, predicted_price) VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 4 HOUR), ?, ?)");
            if ($st) { $st->bind_param("sssdd", $currency, $kind, $direction, $basePrice, $predictedPrice); $st->execute(); }
        } catch (\Throwable $e) {}
    }
}

if (!function_exists('ava_ai_fiat_predict_4h')) {
    function ava_ai_fiat_predict_4h($conn, $currency) {
        try {
            $hist = [];
            $st = $conn->prepare("SELECT price FROM currency_rate_history WHERE UPPER(currency) = ? AND recorded_at >= DATE_SUB(NOW(), INTERVAL 8 HOUR) ORDER BY recorded_at ASC");
            if (!$st) return;
            $st->bind_param("s", $currency);
            $st->execute();
            $res = $st->get_result();
            while ($row = $res->fetch_assoc()) { $hist[] = (float)$row['price']; }
            if (count($hist) < 2) return; // داده‌ی کافی برای ۸ ساعتِ اخیر نیست
            $last = end($hist);
            $high = max($hist); $low = min($hist);
            $range = max(0, $high - $low);
            if ($last <= 0 || $range <= 0) return;
            $upTarget = $high + ($range * 0.1);
            $downTarget = max(0, $low - ($range * 0.1));
            ava_ai_log_prediction_4h($conn, $currency, 'fiat', $last, $upTarget, 'up');
            ava_ai_log_prediction_4h($conn, $currency, 'fiat', $last, $downTarget, 'down');
        } catch (\Throwable $e) {}
    }
}

if (!function_exists('ava_ai_crypto_predict_4h')) {
    function ava_ai_crypto_predict_4h($conn, $coinId) {
        try {
            // days=1 از CoinGecko معمولاً با گام ~۵ دقیقه برمی‌گردد؛ کافی برای ۸ ساعتِ اخیر
            $url = 'https://api.coingecko.com/api/v3/coins/' . rawurlencode($coinId) . '/market_chart?vs_currency=usd&days=1';
            $raw = ava_ai_http_get($url);
            if ($raw === false) return;
            $j = json_decode($raw, true);
            if (!isset($j['prices']) || !is_array($j['prices'])) return;
            $cutoff = (time() - 8 * 3600) * 1000;
            $hist = [];
            foreach ($j['prices'] as $p) {
                if (isset($p[0], $p[1]) && $p[0] >= $cutoff) $hist[] = (float)$p[1];
            }
            if (count($hist) < 2) return;
            $last = end($hist);
            $high = max($hist); $low = min($hist);
            $range = max(0, $high - $low);
            if ($last <= 0 || $range <= 0) return;
            $upTarget = $high + ($range * 0.1);
            $downTarget = max(0, $low - ($range * 0.1));
            ava_ai_log_prediction_4h($conn, $coinId, 'crypto', $last, $upTarget, 'up');
            ava_ai_log_prediction_4h($conn, $coinId, 'crypto', $last, $downTarget, 'down');
        } catch (\Throwable $e) {}
    }
}

if (!function_exists('ava_ai_resolve_predictions_4h')) {
    function ava_ai_resolve_predictions_4h($conn) {
        // --- ارزهای رایج: از همان تاریخچه‌ی ساعتیِ محلی ---
        // (اصلاح) همان مشکلِ نسخه‌ی ۳روزه اینجا هم بود — رفع شد: بررسی زودهنگام
        // به‌جای صبر تا سررسیدِ کاملِ ۴ساعته.
        try {
            $res = $conn->query("SELECT * FROM ai_predictions_4h WHERE status='pending' AND kind='fiat' LIMIT 50");
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $cur = $row['currency']; $pred = (float)$row['predicted_price'];
                    $dir = ($row['direction'] ?? 'up') === 'down' ? 'down' : 'up';
                    $expired = (strtotime($row['target_at']) <= time());
                    $rangeEnd = date('Y-m-d H:i:s', min(time(), strtotime($row['target_at'])));
                    $st = $conn->prepare("SELECT MAX(price) mx, MIN(price) mn FROM currency_rate_history WHERE UPPER(currency)=? AND recorded_at >= ? AND recorded_at <= ?");
                    if (!$st) continue;
                    $st->bind_param("sss", $cur, $row['predicted_at'], $rangeEnd);
                    $st->execute();
                    $r = $st->get_result()->fetch_assoc();
                    $mx = $r && $r['mx'] !== null ? (float)$r['mx'] : null;
                    $mn = $r && $r['mn'] !== null ? (float)$r['mn'] : null;
                    // (اصلاح) همون دلیلِ نسخه‌ی ۳روزه — قیمتِ لحظه‌ای را هم به‌عنوان
                    // نمونه‌ی اضافه در نظر بگیر تا با تأخیرِ عکسِ ۱۵دقیقه‌ای گیر نکند
                    $liveR = $conn->query("SELECT price FROM currency_rates WHERE UPPER(currency)='" . $conn->real_escape_string($cur) . "' LIMIT 1");
                    $liveP = ($liveR && ($lrow = $liveR->fetch_assoc()) && $lrow['price'] !== null) ? (float)$lrow['price'] : null;
                    if ($liveP !== null) {
                        $mx = ($mx === null) ? $liveP : max($mx, $liveP);
                        $mn = ($mn === null) ? $liveP : min($mn, $liveP);
                    }
                    if ($mx === null && $mn === null) continue;
                    $hit = ($dir === 'up') ? ($mx !== null && $mx >= $pred) : ($mn !== null && $mn <= $pred);
                    if (!$hit && !$expired) continue;
                    $resolvedPrice = $hit ? $pred : (($dir === 'up') ? $mx : $mn);
                    $status = $hit ? 'hit' : 'missed';
                    $up = $conn->prepare("UPDATE ai_predictions_4h SET status=?, resolved_price=?, resolved_at=NOW() WHERE id=?");
                    if ($up) { $up->bind_param("sdi", $status, $resolvedPrice, $row['id']); $up->execute(); }
                }
            }
        } catch (\Throwable $e) {}

        // --- ارزهای دیجیتال: تاریخچه‌ی همان بازه از CoinGecko ---
        try {
            $res = $conn->query("SELECT * FROM ai_predictions_4h WHERE status='pending' AND kind='crypto' LIMIT 8");
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $coinId = $row['currency']; $pred = (float)$row['predicted_price'];
                    $dir = ($row['direction'] ?? 'up') === 'down' ? 'down' : 'up';
                    $expired = (strtotime($row['target_at']) <= time());
                    $url = 'https://api.coingecko.com/api/v3/coins/' . rawurlencode($coinId) . '/market_chart?vs_currency=usd&days=1';
                    $raw = ava_ai_http_get($url);
                    if ($raw === false) continue;
                    $j = json_decode($raw, true);
                    if (!isset($j['prices']) || !is_array($j['prices'])) continue;
                    $tStart = strtotime($row['predicted_at']) * 1000;
                    $tEnd = min(time(), strtotime($row['target_at'])) * 1000;
                    $mx = null; $mn = null;
                    foreach ($j['prices'] as $p) {
                        if (!isset($p[0], $p[1])) continue;
                        if ($p[0] >= $tStart && $p[0] <= $tEnd) {
                            $v = (float)$p[1];
                            if ($mx === null || $v > $mx) $mx = $v;
                            if ($mn === null || $v < $mn) $mn = $v;
                        }
                    }
                    if ($mx === null && $mn === null) continue;
                    $hit = ($dir === 'up') ? ($mx !== null && $mx >= $pred) : ($mn !== null && $mn <= $pred);
                    if (!$hit && !$expired) continue;
                    $resolvedPrice = $hit ? $pred : (($dir === 'up') ? $mx : $mn);
                    $status = $hit ? 'hit' : 'missed';
                    $up = $conn->prepare("UPDATE ai_predictions_4h SET status=?, resolved_price=?, resolved_at=NOW() WHERE id=?");
                    if ($up) { $up->bind_param("sdi", $status, $resolvedPrice, $row['id']); $up->execute(); }
                }
            }
        } catch (\Throwable $e) {}
    }
}

/* ===================================================================
   (جدید) «عمق بازار» گجت هوشمند — به‌جای حجم معاملات (که برای این ارزها
   نداریم چون AvaPay معامله‌ی مستقیم انجام نمی‌دهد، فقط آگهی)، از تعداد
   واقعیِ آگهی‌های فعال در هر بازه‌ی قیمتی استفاده می‌شود — مفهوم مشابه
   «نقدشوندگی/رقابت در هر قیمت»، اما با داده‌ی واقعی AvaPay.
   =================================================================== */
if (!function_exists('ava_ai_market_depth')) {
    function ava_ai_market_depth($conn, $currency) {
        $rows = [];
        $stmt = $conn->prepare("SELECT price_per_unit AS p, type FROM user_ads WHERE UPPER(currency) = ? AND status = 'active' AND price_per_unit > 0");
        if ($stmt) {
            $stmt->bind_param("s", $currency);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) { $rows[] = $r; }
            $stmt->close();
        }
        if (empty($rows)) return ['buckets' => [], 'labels' => [], 'bestBucket' => -1];

        $prices = array_map(function($r){ return (float)$r['p']; }, $rows);
        $min = min($prices); $max = max($prices);
        if ($min == $max) { $max = $min * 1.02; $min = $min * 0.98; }
        $bucketCount = 8;
        $bucketSize = ($max - $min) / $bucketCount;
        $buckets = array_fill(0, $bucketCount, ['buy' => 0, 'sell' => 0]);
        $cheapestSellPrice = null; $cheapestSellBucket = -1;
        foreach ($rows as $r) {
            $p = (float)$r['p'];
            $idx = $bucketSize > 0 ? (int)min($bucketCount - 1, floor(($p - $min) / $bucketSize)) : 0;
            if ($r['type'] === 'buy') { $buckets[$idx]['buy']++; }
            else {
                $buckets[$idx]['sell']++;
                if ($cheapestSellPrice === null || $p < $cheapestSellPrice) { $cheapestSellPrice = $p; $cheapestSellBucket = $idx; }
            }
        }
        $labels = [];
        for ($i = 0; $i < $bucketCount; $i++) { $labels[] = round($min + $i * $bucketSize); }

        return ['buckets' => $buckets, 'labels' => $labels, 'bestBucket' => $cheapestSellBucket];
    }
}

/* اسپارک‌لاین کوچک SVG (ثابت، سمت سرور) برای گجت خلاصه‌ی داشبورد — بدون
   جاوااسکریپت/Chart.js، چون فقط یک پیش‌نمایش تزئینی هنگام لود صفحه است. */
if (!function_exists('ava_ai_sparkline_svg')) {
    function ava_ai_sparkline_svg($hist, $color = '#A855F7', $w = 100, $h = 32) {
        $hist = array_values($hist ?: [0, 0]);
        $n = count($hist);
        if ($n < 2) $hist = [$hist[0] ?? 0, $hist[0] ?? 0];
        $n = count($hist);
        $min = min($hist); $max = max($hist);
        $range = ($max - $min) ?: 1;
        $pts = [];
        foreach ($hist as $i => $v) {
            $x = $n > 1 ? ($i / ($n - 1)) * $w : 0;
            $y = $h - (($v - $min) / $range) * $h;
            $pts[] = round($x, 1) . ',' . round($y, 1);
        }
        $path = 'M' . implode(' L', $pts);
        $c = htmlspecialchars($color, ENT_QUOTES);
        return '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '" preserveAspectRatio="none">'
             . '<path d="' . $path . '" fill="none" stroke="' . $c . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
             . '</svg>';
    }
}

/* (جدید) نسخه‌ی «ناحیه‌ای» اسپارک‌لاین — برای پس‌زمینه‌ی گجت هوشمند بازار:
   همان خط، به‌علاوه‌ی یک ناحیه‌ی پرشده با گرادیانت محو زیرش، مناسب برای
   استفاده به‌عنوان یک لایه‌ی بزرگ و کم‌رنگ پشت متن (نه یک آیکون کوچک). */
if (!function_exists('ava_ai_sparkline_area_svg')) {
    function ava_ai_sparkline_area_svg($hist, $color = '#22C55E', $w = 340, $h = 110) {
        $hist = array_values($hist ?: [0, 0]);
        $n = count($hist);
        if ($n < 2) $hist = [$hist[0] ?? 0, $hist[0] ?? 0];
        $n = count($hist);
        $min = min($hist); $max = max($hist);
        $range = ($max - $min) ?: 1;
        $pad = 6;
        $pts = [];
        foreach ($hist as $i => $v) {
            $x = $n > 1 ? ($i / ($n - 1)) * $w : 0;
            $y = ($h - $pad) - (($v - $min) / $range) * ($h - $pad * 2);
            $pts[] = round($x, 1) . ',' . round($y, 1);
        }
        $line = 'M' . implode(' L', $pts);
        $area = $line . ' L' . $w . ',' . $h . ' L0,' . $h . ' Z';
        $c = htmlspecialchars($color, ENT_QUOTES);
        $gid = 'avaGadgetGrad' . substr(md5($color), 0, 6);
        return '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" height="100%" preserveAspectRatio="none">'
             . '<defs><linearGradient id="' . $gid . '" x1="0" y1="0" x2="0" y2="1">'
             . '<stop offset="0%" stop-color="' . $c . '" stop-opacity="0.5"/>'
             . '<stop offset="100%" stop-color="' . $c . '" stop-opacity="0"/>'
             . '</linearGradient></defs>'
             . '<path d="' . $area . '" fill="url(#' . $gid . ')" stroke="none"/>'
             . '<path d="' . $line . '" fill="none" stroke="' . $c . '" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" opacity="0.95"/>'
             . '</svg>';
    }
}

if (isset($_GET['ava'])) {
    header('Content-Type: application/json; charset=utf-8');
    $act = $_GET['ava'];
    $in  = json_decode(file_get_contents('php://input'), true) ?: [];

    if ($act === 'alert_add') {
        $cur  = trim($in['currency'] ?? '');
        $type = $in['alert_type'] ?? 'ad';
        if (!in_array($type, ['ad','rate','crypto'], true)) $type = 'ad';
        // برای ارز دیجیتال شناسه به‌صورت حروف کوچک (coingecko id)؛ برای بقیه حروف بزرگ
        $cur  = ($type === 'crypto') ? strtolower(preg_replace('/[^a-z0-9\-]/i', '', $cur)) : strtoupper($cur);
        $coinName = $type === 'crypto' ? trim((string)($in['coin_name'] ?? '')) : null;
        $tp   = (float)($in['target_price'] ?? 0);
        $dir  = ($in['direction'] ?? 'above') === 'below' ? 'below' : 'above';
        $nTg  = !empty($in['notify_telegram']) ? 1 : 0;
        $nEm  = !empty($in['notify_email'])    ? 1 : 0;
        $nTo  = !empty($in['notify_toast'])    ? 1 : 0;
        if ($nTg === 0 && $nEm === 0 && $nTo === 0) $nTo = 1; // حداقل یک کانال
        // بازه‌ی بررسی (دقیقه) — بین ۱ تا ۱۴۴۰ دقیقه (۲۴ ساعت)
        $interval = (int)($in['check_interval'] ?? 60);
        $allowed  = [1, 5, 15, 30, 60, 180, 360, 720, 1440];
        if (!in_array($interval, $allowed, true)) $interval = 60;
        if ($cur === '' || $tp <= 0) { http_response_code(400); echo json_encode(['error' => 'داده نامعتبر']); exit; }
        $st = $conn->prepare("INSERT INTO price_alerts
            (user_id, currency, target_price, direction, alert_type, coin_name, notify_telegram, notify_email, notify_toast, check_interval)
            VALUES (?,?,?,?,?,?,?,?,?,?)");
        $st->bind_param("isdsssiiii", $userId, $cur, $tp, $dir, $type, $coinName, $nTg, $nEm, $nTo, $interval);
        $st->execute();
        echo json_encode(['success' => true, 'id' => $conn->insert_id]); exit;
    }

    /* ===== حساب‌های معرفی‌شده (تسویه) ===== */
    if ($act === 'ben_add') {
        $name = trim((string)($in['full_name'] ?? ''));
        // شبا و کارت آزاد: هر ترکیبی از حروف و اعداد با هر طولی پذیرفته می‌شود
        // (برای پشتیبانی از حساب‌های خارجی، IBAN کشورهای مختلف، شماره حساب داخلی و ...)
        $card = trim((string)($in['card_number'] ?? ''));
        $iban = trim((string)($in['iban'] ?? ''));
        $bank = trim((string)($in['bank_name'] ?? ''));
        $note = trim((string)($in['note'] ?? ''));
        if ($name === '') { http_response_code(400); echo json_encode(['error' => 'نام گیرنده الزامی است']); exit; }
        if ($card === '' && $iban === '') { http_response_code(400); echo json_encode(['error' => 'شماره کارت یا شبا را وارد کنید']); exit; }
        if (mb_strlen($card) > 64)  $card = mb_substr($card, 0, 64);
        if (mb_strlen($iban) > 64)  $iban = mb_substr($iban, 0, 64);
        // انتخاب تصادفی یک آواتار (ایموجی) برای تمایز بصری
        // آواتار اختصاصی AvaPay سمت نمایش رندر می‌شود؛ رنگ هر شخص از شناسه‌اش مشتق می‌شود
        $avatar  = '';
        $st = $conn->prepare("INSERT INTO user_beneficiaries (user_id, full_name, card_number, iban, bank_name, note, avatar)
                              VALUES (?,?,?,?,?,?,?)");
        $st->bind_param("issssss", $userId, $name, $card, $iban, $bank, $note, $avatar);
        $st->execute();
        echo json_encode(['success' => true, 'id' => $conn->insert_id, 'avatar' => $avatar]); exit;
    }

    if ($act === 'ben_delete') {
        $id = (int)($in['id'] ?? 0);
        $st = $conn->prepare("DELETE FROM user_beneficiaries WHERE id = ? AND user_id = ?");
        $st->bind_param("ii", $id, $userId);
        $st->execute();
        echo json_encode(['success' => true]); exit;
    }

    if ($act === 'ben_list') {
        $rows = [];
        $st = $conn->prepare("SELECT id, full_name, card_number, iban, bank_name, note, avatar
                              FROM user_beneficiaries WHERE user_id = ? ORDER BY full_name ASC");
        $st->bind_param("i", $userId);
        $st->execute();
        $rs = $st->get_result();
        while ($r = $rs->fetch_assoc()) $rows[] = $r;
        echo json_encode(['success' => true, 'items' => $rows], JSON_UNESCAPED_UNICODE); exit;
    }

    if ($act === 'ben_update') {
        $id   = (int)($in['id'] ?? 0);
        $name = trim((string)($in['full_name'] ?? ''));
        $card = trim((string)($in['card_number'] ?? ''));
        $iban = trim((string)($in['iban'] ?? ''));
        $bank = trim((string)($in['bank_name'] ?? ''));
        $note = trim((string)($in['note'] ?? ''));
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'حساب نامعتبر']); exit; }
        if ($name === '') { http_response_code(400); echo json_encode(['error' => 'نام گیرنده الزامی است']); exit; }
        if ($card === '' && $iban === '') { http_response_code(400); echo json_encode(['error' => 'شماره کارت یا شبا را وارد کنید']); exit; }
        if (mb_strlen($card) > 64) $card = mb_substr($card, 0, 64);
        if (mb_strlen($iban) > 64) $iban = mb_substr($iban, 0, 64);
        $st = $conn->prepare("UPDATE user_beneficiaries SET full_name=?, card_number=?, iban=?, bank_name=?, note=? WHERE id=? AND user_id=?");
        $st->bind_param("sssssii", $name, $card, $iban, $bank, $note, $id, $userId);
        $st->execute();
        if ($st->affected_rows <= 0) {
            // ممکن است چیزی تغییر نکرده باشد (همان مقادیر قبلی) — این خودش خطا نیست
            $chk = $conn->prepare("SELECT id FROM user_beneficiaries WHERE id=? AND user_id=?");
            $chk->bind_param("ii", $id, $userId);
            $chk->execute();
            if ($chk->get_result()->num_rows === 0) { http_response_code(404); echo json_encode(['error' => 'حساب یافت نشد']); exit; }
        }
        echo json_encode(['success' => true]); exit;
    }

    /* ===== (جدید) نمودار انتقال‌های یک‌سال گذشته به یک حساب معرفی‌شده ===== */
    if ($act === 'ben_chart') {
        // خروجی این بخش را در بافر می‌گیریم تا هر Notice/Warning احتمالی PHP
        // (که چون display_errors فعال است می‌تواند قبل از JSON چاپ شود و پاسخ
        // را در سمت مرورگر نامعتبر/خالی نشان دهد) هرگز وارد پاسخ نهایی نشود.
        ob_start();
        try {
            $bid = (int)($_GET['id'] ?? $in['id'] ?? 0);
            $ben = null;
            if ($bid > 0) {
                $st = $conn->prepare("SELECT id, full_name, card_number, iban FROM user_beneficiaries WHERE id = ? AND user_id = ?");
                $st->bind_param("ii", $bid, $userId);
                $st->execute();
                $ben = $st->get_result()->fetch_assoc();
            }
            if (!$ben) {
                while (ob_get_level()) { ob_end_clean(); }
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'حساب یافت نشد']);
                exit;
            }

            // نرمال‌سازی: فقط حروف و ارقام، برای مقایسه‌ی مقاوم به فاصله و خط تیره
            $norm = function ($v) {
                $v = (string)($v ?? '');
                $v = preg_replace('/[^0-9A-Za-z\x{0600}-\x{06FF}]/u', '', $v);
                return mb_strtolower((string)$v);
            };
            $bCard = $norm($ben['card_number'] ?? '');
            $bIban = $norm($ben['iban'] ?? '');
            $bName = $norm($ben['full_name'] ?? '');

            // ۱۲ ماه گذشته (کلید: YYYY-MM)
            $months = [];
            for ($i = 11; $i >= 0; $i--) $months[] = date('Y-m', strtotime("-$i month"));
            $monthIndex = array_flip($months);
            $since = date('Y-m-01 00:00:00', strtotime('-11 month'));

            $byCur = [];   // currency => ['series'=>[12], 'total'=>x, 'count'=>n, 'tx'=>[12][]]
            $touch = function (&$byCur, $cur, $mKey, $amount, $to = '', $date = '') use ($monthIndex, $months) {
                $cur = strtoupper(trim((string)$cur)) ?: 'IRR';
                if (!isset($byCur[$cur])) $byCur[$cur] = ['series' => array_fill(0, count($months), 0.0), 'total' => 0.0, 'count' => 0, 'tx' => array_fill(0, count($months), [])];
                if (isset($monthIndex[$mKey])) {
                    $mi = $monthIndex[$mKey];
                    $byCur[$cur]['series'][$mi] += (float)$amount;
                    $byCur[$cur]['tx'][$mi][] = ['to' => (string)$to, 'amount' => round((float)$amount, 2), 'date' => (string)$date];
                }
                $byCur[$cur]['total'] += (float)$amount;
                $byCur[$cur]['count']++;
            };
            // تبدیل ایمن تاریخ به کلید YYYY-MM؛ اگر مقدار خالی/نامعتبر باشد null برمی‌گرداند
            // (به‌جای دادن null به strtotime که در PHP 8.1+ یک Deprecation چاپ می‌کند)
            $safeMonthKey = function ($dt) {
                $dt = (string)($dt ?? '');
                if ($dt === '') return null;
                $ts = strtotime($dt);
                return $ts ? date('Y-m', $ts) : null;
            };

            // منبع ۱: درخواست‌های تسویه (withdrawal_requests)
            // توجه: بازه‌ی زمانی روی رکوردهای «ایجادشده» فیلتر می‌شود، نه لزوماً تکمیل‌شده،
            // اما فقط رکوردهایی که ادمین تأیید (approved) یا تکمیل (completed) کرده لحاظ می‌شوند.
            $rows = ava_safe_rows($conn, "SELECT amount, currency, card_number, iban_number, recipient_name, created_at
                                          FROM withdrawal_requests
                                          WHERE user_id = ? AND created_at >= ?
                                            AND status IN ('approved','completed')", "is", [$userId, $since]);
            foreach ($rows as $r) {
                $hit = ($bCard !== '' && $norm($r['card_number'] ?? '') === $bCard)
                    || ($bIban !== '' && $norm($r['iban_number'] ?? '') === $bIban)
                    || ($bName !== '' && $norm($r['recipient_name'] ?? '') === $bName);
                if (!$hit) continue;
                $mKey = $safeMonthKey($r['created_at'] ?? null);
                if ($mKey === null) continue;
                $touch($byCur, $r['currency'] ?? 'IRR', $mKey, $r['amount'] ?? 0, $r['recipient_name'] ?? $ben['full_name'], date('Y/m/d', strtotime($r['created_at'] ?? 'now')));
            }

            // منبع ۲: حواله‌های ارزی (money_transfers)
            // وضعیت‌های معتبر: approved / awaiting_payment / payment_submitted / completed
            $rows2 = ava_safe_rows($conn, "SELECT amount, currency, iban, full_name, created_at
                                           FROM money_transfers
                                           WHERE user_id = ? AND created_at >= ?
                                             AND status IN ('approved','awaiting_payment','payment_submitted','completed')", "is", [$userId, $since]);
            foreach ($rows2 as $r) {
                $hit = ($bIban !== '' && $norm($r['iban'] ?? '') === $bIban)
                    || ($bName !== '' && $norm($r['full_name'] ?? '') === $bName);
                if (!$hit) continue;
                $mKey = $safeMonthKey($r['created_at'] ?? null);
                if ($mKey === null) continue;
                $touch($byCur, $r['currency'] ?? 'EUR', $mKey, $r['amount'] ?? 0, $r['full_name'] ?? $ben['full_name'], date('Y/m/d', strtotime($r['created_at'] ?? 'now')));
            }

            // مرتب‌سازی بر اساس مجموع (بزرگ‌ترین اول)
            uasort($byCur, function ($a, $b) { return $b['total'] <=> $a['total']; });

            $faMonths = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
            $labels = [];
            foreach ($months as $m) {
                $ts = strtotime($m . '-15');
                $labels[] = ava_jalali_month_label($ts, $faMonths);
            }

            $out = [];
            foreach ($byCur as $cur => $d) {
                $out[] = [
                    'currency' => $cur,
                    'series'   => array_map(function ($v) { return round($v, 2); }, $d['series']),
                    'total'    => round($d['total'], 2),
                    'count'    => $d['count'],
                    'tx'       => $d['tx'],
                ];
            }

            while (ob_get_level()) { ob_end_clean(); }
            echo json_encode([
                'success'  => true,
                'name'     => $ben['full_name'],
                'labels'   => $labels,
                'items'    => $out,
            ], JSON_UNESCAPED_UNICODE); exit;
        } catch (\Throwable $e) {
            while (ob_get_level()) { ob_end_clean(); }
            echo json_encode(['success' => false, 'error' => 'خطا در محاسبه نمودار']); exit;
        }
    }

    /* ===== (جدید) نمودار تجمیعی: مجموع واریزی‌ها به همه‌ی حساب‌های معرفی‌شده در یک‌سال گذشته ===== */
    if ($act === 'ben_chart_all') {
        ob_start();
        try {
            // ۱۲ ماه گذشته (کلید: YYYY-MM)
            $months = [];
            for ($i = 11; $i >= 0; $i--) $months[] = date('Y-m', strtotime("-$i month"));
            $monthIndex = array_flip($months);
            $since = date('Y-m-01 00:00:00', strtotime('-11 month'));

            $byCur = [];
            $touch = function (&$byCur, $cur, $mKey, $amount, $to = '', $date = '') use ($monthIndex, $months) {
                $cur = strtoupper(trim((string)$cur)) ?: 'IRR';
                if (!isset($byCur[$cur])) $byCur[$cur] = ['series' => array_fill(0, count($months), 0.0), 'total' => 0.0, 'count' => 0, 'tx' => array_fill(0, count($months), [])];
                if (isset($monthIndex[$mKey])) {
                    $mi = $monthIndex[$mKey];
                    $byCur[$cur]['series'][$mi] += (float)$amount;
                    $byCur[$cur]['tx'][$mi][] = ['to' => (string)$to, 'amount' => round((float)$amount, 2), 'date' => (string)$date];
                }
                $byCur[$cur]['total'] += (float)$amount;
                $byCur[$cur]['count']++;
            };
            $safeMonthKey = function ($dt) {
                $dt = (string)($dt ?? '');
                if ($dt === '') return null;
                $ts = strtotime($dt);
                return $ts ? date('Y-m', $ts) : null;
            };

            // منبع ۱: همه‌ی درخواست‌های تسویه‌ی این کاربر (به هر حساب معرفی‌شده‌ای)
            $rows = ava_safe_rows($conn, "SELECT amount, currency, recipient_name, created_at
                                          FROM withdrawal_requests
                                          WHERE user_id = ? AND created_at >= ?
                                            AND status IN ('approved','completed')", "is", [$userId, $since]);
            foreach ($rows as $r) {
                $mKey = $safeMonthKey($r['created_at'] ?? null);
                if ($mKey === null) continue;
                $touch($byCur, $r['currency'] ?? 'IRR', $mKey, $r['amount'] ?? 0, $r['recipient_name'] ?? '—', date('Y/m/d', strtotime($r['created_at'] ?? 'now')));
            }

            // منبع ۲: همه‌ی حواله‌های ارزی این کاربر
            $rows2 = ava_safe_rows($conn, "SELECT amount, currency, full_name, created_at
                                           FROM money_transfers
                                           WHERE user_id = ? AND created_at >= ?
                                             AND status IN ('approved','awaiting_payment','payment_submitted','completed')", "is", [$userId, $since]);
            foreach ($rows2 as $r) {
                $mKey = $safeMonthKey($r['created_at'] ?? null);
                if ($mKey === null) continue;
                $touch($byCur, $r['currency'] ?? 'EUR', $mKey, $r['amount'] ?? 0, $r['full_name'] ?? '—', date('Y/m/d', strtotime($r['created_at'] ?? 'now')));
            }

            uasort($byCur, function ($a, $b) { return $b['total'] <=> $a['total']; });

            $faMonths = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
            $labels = [];
            foreach ($months as $m) {
                $ts = strtotime($m . '-15');
                $labels[] = ava_jalali_month_label($ts, $faMonths);
            }

            $out = [];
            foreach ($byCur as $cur => $d) {
                $out[] = [
                    'currency' => $cur,
                    'series'   => array_map(function ($v) { return round($v, 2); }, $d['series']),
                    'total'    => round($d['total'], 2),
                    'count'    => $d['count'],
                    'tx'       => $d['tx'],
                ];
            }

            while (ob_get_level()) { ob_end_clean(); }
            echo json_encode([
                'success' => true,
                'name'    => 'همه حساب‌های معرفی‌شده',
                'labels'  => $labels,
                'items'   => $out,
            ], JSON_UNESCAPED_UNICODE); exit;
        } catch (\Throwable $e) {
            while (ob_get_level()) { ob_end_clean(); }
            echo json_encode(['success' => false, 'error' => 'خطا در محاسبه نمودار']); exit;
        }
    }

    /* ===== (جدید) داده‌ی PDF کلیه واریزی‌ها در یک بازه‌ی زمانی دلخواه ===== */
    if ($act === 'ben_pdf_data') {
        ob_start();
        try {
            $from = trim((string)($in['from'] ?? ''));
            $to   = trim((string)($in['to']   ?? ''));
            $fromDt = $from !== '' ? ($from . ' 00:00:00') : date('Y-m-d 00:00:00', strtotime('-1 year'));
            $toDt   = $to   !== '' ? ($to   . ' 23:59:59') : date('Y-m-d 23:59:59');

            $items = [];
            $rows = ava_safe_rows($conn, "SELECT amount, currency, recipient_name, card_number, iban_number, created_at
                                          FROM withdrawal_requests
                                          WHERE user_id = ? AND created_at BETWEEN ? AND ?
                                            AND status IN ('approved','completed')
                                          ORDER BY created_at DESC", "iss", [$userId, $fromDt, $toDt]);
            foreach ($rows as $r) {
                $items[] = [
                    'date'     => date('Y/m/d', strtotime($r['created_at'])),
                    'type'     => 'تسویه',
                    'to'       => $r['recipient_name'] ?: '—',
                    'amount'   => round((float)$r['amount'], 2),
                    'currency' => strtoupper($r['currency'] ?: 'IRR'),
                ];
            }
            $rows2 = ava_safe_rows($conn, "SELECT amount, currency, full_name, created_at
                                           FROM money_transfers
                                           WHERE user_id = ? AND created_at BETWEEN ? AND ?
                                             AND status IN ('approved','awaiting_payment','payment_submitted','completed')
                                           ORDER BY created_at DESC", "iss", [$userId, $fromDt, $toDt]);
            foreach ($rows2 as $r) {
                $items[] = [
                    'date'     => date('Y/m/d', strtotime($r['created_at'])),
                    'type'     => 'حواله ارزی',
                    'to'       => $r['full_name'] ?: '—',
                    'amount'   => round((float)$r['amount'], 2),
                    'currency' => strtoupper($r['currency'] ?: 'EUR'),
                ];
            }
            // مرتب‌سازی نهایی بر اساس تاریخ (نزولی)
            usort($items, function ($a, $b) { return strcmp($b['date'], $a['date']); });

            $totals = [];
            foreach ($items as $it) {
                $c = $it['currency'];
                if (!isset($totals[$c])) $totals[$c] = 0.0;
                $totals[$c] += $it['amount'];
            }
            $totalsOut = [];
            foreach ($totals as $c => $t) $totalsOut[] = ['currency' => $c, 'total' => round($t, 2)];

            while (ob_get_level()) { ob_end_clean(); }
            echo json_encode([
                'success'  => true,
                'name'     => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: '—',
                'account'  => $user['account_number'] ?? '—',
                'from'     => $from ?: date('Y/m/d', strtotime($fromDt)),
                'to'       => $to ?: date('Y/m/d', strtotime($toDt)),
                'items'    => $items,
                'totals'   => $totalsOut,
            ], JSON_UNESCAPED_UNICODE); exit;
        } catch (\Throwable $e) {
            while (ob_get_level()) { ob_end_clean(); }
            echo json_encode(['success' => false, 'error' => 'خطا در آماده‌سازی اطلاعات']); exit;
        }
    }

    /* ===== (جدید) ذخیره/خواندن چیدمان، اندازه و بخش‌های مخفی داشبورد ===== */
    if ($act === 'sec_prefs_get') {
        $st = $conn->prepare("SELECT hidden_sections, section_order, section_sizes FROM user_dashboard_prefs WHERE user_id = ?");
        $st->bind_param("i", $userId);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $hidden = $r ? json_decode($r['hidden_sections'] ?: '[]', true) : [];
        $order  = $r ? json_decode($r['section_order'] ?: '[]', true) : [];
        $sizes  = $r ? json_decode($r['section_sizes'] ?: '{}', true) : [];
        echo json_encode([
            'success' => true,
            'hidden'  => is_array($hidden) ? array_values($hidden) : [],
            'order'   => is_array($order) ? array_values($order) : [],
            'sizes'   => is_array($sizes) ? $sizes : [],
        ], JSON_UNESCAPED_UNICODE); exit;
    }

    if ($act === 'sec_prefs_set') {
        $cleanKey = function ($k) { return preg_replace('/[^a-z0-9_\-]/i', '', (string)$k); };
        $hidden = $in['hidden'] ?? null;
        $order  = $in['order'] ?? null;
        $sizes  = $in['sizes'] ?? null;

        // اگر یکی از سه فیلد ارسال نشده باشد، مقدار فعلی‌اش دست‌نخورده باقی می‌ماند
        // (مثلاً وقتی فقط اندازه‌ی یک کارت عوض شده، لازم نیست hidden/order هم دوباره فرستاده شود)
        $cur = null;
        if ($hidden === null || $order === null || $sizes === null) {
            $stc = $conn->prepare("SELECT hidden_sections, section_order, section_sizes FROM user_dashboard_prefs WHERE user_id = ?");
            $stc->bind_param("i", $userId);
            $stc->execute();
            $cur = $stc->get_result()->fetch_assoc();
        }
        if ($hidden === null) $hidden = $cur ? json_decode($cur['hidden_sections'] ?: '[]', true) : [];
        if ($order === null)  $order  = $cur ? json_decode($cur['section_order'] ?: '[]', true) : [];
        if ($sizes === null)  $sizes  = $cur ? json_decode($cur['section_sizes'] ?: '{}', true) : [];

        if (!is_array($hidden)) $hidden = [];
        if (!is_array($order)) $order = [];
        if (!is_array($sizes)) $sizes = [];

        $hidden = array_values(array_unique(array_map($cleanKey, $hidden)));
        $order  = array_values(array_unique(array_map($cleanKey, $order)));
        $sizesClean = [];
        foreach ($sizes as $k => $v) {
            $k = $cleanKey($k);
            if ($k === '') continue;
            $sizesClean[$k] = ($v === 'compact') ? 'compact' : 'normal';
        }

        $hiddenJson = json_encode($hidden, JSON_UNESCAPED_UNICODE);
        $orderJson  = json_encode($order, JSON_UNESCAPED_UNICODE);
        $sizesJson  = json_encode($sizesClean, JSON_UNESCAPED_UNICODE);
        $st = $conn->prepare("INSERT INTO user_dashboard_prefs (user_id, hidden_sections, section_order, section_sizes)
                              VALUES (?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE hidden_sections = VALUES(hidden_sections), section_order = VALUES(section_order), section_sizes = VALUES(section_sizes)");
        $st->bind_param("isss", $userId, $hiddenJson, $orderJson, $sizesJson);
        $st->execute();
        echo json_encode(['success' => true, 'hidden' => $hidden, 'order' => $order, 'sizes' => $sizesClean], JSON_UNESCAPED_UNICODE); exit;
    }

    if ($act === 'sec_prefs_reset') {
        $st = $conn->prepare("DELETE FROM user_dashboard_prefs WHERE user_id = ?");
        $st->bind_param("i", $userId);
        $st->execute();
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE); exit;
    }

    /* ===== (جدید) گجت هوشمند بازار: آگهی‌های نزدیک قیمتِ کلیک‌شده روی نمودار =====
       با کلیک روی یک نقطه از نمودار «پیش‌بینی هوشمند ارز»، آگهی‌های فعال همان
       ارز که قیمتشان در بازه‌ی ±۵٪ آن نقطه است برگردانده می‌شود تا کاربر
       بتواند مستقیماً روی یکی از آن‌ها درخواست ارسال کند. */
    /* ===== (جدید) افزودن/حذف ارز دیجیتال کاربر برای «گجت هوشمند بازار» ===== */
    /* ===== (جدید) گرفتن تاریخچه/پیش‌بینی یک ارز رایج برای بازه‌ی دیگر
       (۱ روزه/۱ ساله) — وقتی کاربر تب بازه‌ی زمانی را روی گجت هوشمند عوض می‌کند ===== */
    if ($act === 'ai_history') {
        $hCur = strtoupper(trim($_GET['currency'] ?? ''));
        $hRange = in_array(($_GET['range'] ?? ''), ['1d', '1m', '1y'], true) ? $_GET['range'] : '1m';
        if (!in_array($hCur, ['USD', 'EUR', 'USDT'], true)) {
            echo json_encode(['success' => false, 'message' => 'ارز نامعتبر']); exit;
        }
        $hData = ava_ai_compute_range($conn, $hCur, $hRange);
        // (جدید) آمار دقتِ پیش‌بینی این ارز — مستقل از بازه‌ی زمانیِ انتخاب‌شده
        $hData['accuracy'] = ava_ai_get_accuracy($conn, $hCur, 'fiat');
        echo json_encode(array_merge(['success' => true], $hData), JSON_UNESCAPED_UNICODE); exit;
    }

    // (اصلاح) ثبتِ پیش‌بینیِ سه‌روزه‌ی یک ارز دیجیتال (که سمت کلاینت با
    // رگرسیون محاسبه شده) + برگرداندن آمار دقتِ همان کوین
    if ($act === 'ai_crypto_predict_log') {
        $coinId = strtolower(preg_replace('/[^a-z0-9\-]/i', '', trim($in['coin_id'] ?? '')));
        $cLast = (float)($in['last'] ?? 0);
        $cUpTarget = (float)($in['up_target'] ?? 0);
        $cDownTarget = (float)($in['down_target'] ?? 0);
        if ($coinId === '' || $cLast <= 0 || $cUpTarget <= 0) {
            echo json_encode(['success' => false]); exit;
        }
        ava_ai_log_prediction($conn, $coinId, 'crypto', $cLast, $cUpTarget, 'up');
        if ($cDownTarget > 0) {
            ava_ai_log_prediction($conn, $coinId, 'crypto', $cLast, $cDownTarget, 'down');
        }
        $cAcc = ava_ai_get_accuracy($conn, $coinId, 'crypto');
        echo json_encode(['success' => true, 'accuracy' => $cAcc], JSON_UNESCAPED_UNICODE); exit;
    }

    // (جدید) فقط خواندن آمار دقتِ یک ارز دیجیتال (وقتی بازه‌ی غیر از ۱ ماهه انتخاب شده)
    if ($act === 'ai_crypto_accuracy') {
        $coinId = strtolower(preg_replace('/[^a-z0-9\-]/i', '', trim($_GET['coin_id'] ?? '')));
        if ($coinId === '') { echo json_encode(['success' => false]); exit; }
        $cAcc = ava_ai_get_accuracy($conn, $coinId, 'crypto');
        echo json_encode(['success' => true, 'accuracy' => $cAcc], JSON_UNESCAPED_UNICODE); exit;
    }

    // (جدید) لیست زنده‌ی تارگت‌های پیش‌بینی‌شده (دلار/یورو/تتر + ارزهای دیجیتالِ خودِ کاربر)
    if ($act === 'ai_targets_list') {
        $items = ava_ai_targets_list($conn, $userId);
        echo json_encode(['success' => true, 'items' => $items], JSON_UNESCAPED_UNICODE); exit;
    }

    // (جدید) تبِ «تارگت‌های خورده‌شده» — تاریخچه‌ی پیش‌بینی‌هایی که واقعاً به هدف رسیده‌اند
    if ($act === 'ai_targets_hit_list') {
        $items = ava_ai_hit_targets_list($conn, $userId);
        echo json_encode(['success' => true, 'items' => $items], JSON_UNESCAPED_UNICODE); exit;
    }

    if ($act === 'ai_coin_add') {
        $coinId = strtolower(preg_replace('/[^a-z0-9\-]/i', '', trim($in['coin_id'] ?? '')));
        $coinName = trim((string)($in['coin_name'] ?? ''));
        $coinSym  = strtoupper(trim((string)($in['coin_symbol'] ?? '')));
        // آدرس لوگوی ارز دیجیتال (از coingecko، همان چیزی که در لیست انتخاب ارز نشان داده می‌شود)
        $coinImg  = trim((string)($in['coin_image'] ?? ''));
        if ($coinImg !== '' && !preg_match('#^https://#i', $coinImg)) $coinImg = '';
        if ($coinId === '') { echo json_encode(['success' => false, 'message' => 'ارز نامعتبر']); exit; }
        // حداکثر ۶ ارز دیجیتال برای هر کاربر (جلوگیری از شلوغی بیش‌ازحد تب‌ها)
        $cntRes = $conn->query("SELECT COUNT(*) AS c FROM user_ai_predict_coins WHERE user_id = " . (int)$userId);
        $cnt = $cntRes ? (int)$cntRes->fetch_assoc()['c'] : 0;
        if ($cnt >= 6) { echo json_encode(['success' => false, 'message' => 'حداکثر ۶ ارز دیجیتال قابل افزودن است']); exit; }
        try {
            if (!empty($__aiCoinImageOk)) {
                $st = $conn->prepare("INSERT IGNORE INTO user_ai_predict_coins (user_id, coin_id, coin_name, coin_symbol, coin_image) VALUES (?, ?, ?, ?, ?)");
                $st->bind_param("issss", $userId, $coinId, $coinName, $coinSym, $coinImg);
            } else {
                $st = $conn->prepare("INSERT IGNORE INTO user_ai_predict_coins (user_id, coin_id, coin_name, coin_symbol) VALUES (?, ?, ?, ?)");
                $st->bind_param("isss", $userId, $coinId, $coinName, $coinSym);
            }
            $st->execute();
        } catch (\Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'خطا در افزودن ارز']); exit;
        }
        // (اصلاح) بلافاصله پیش‌بینیِ سه‌روزه‌اش را هم بساز و ثبت کن تا همین حالا
        // در کارت «تارگت‌های پیش‌بینی‌شده» دیده شود، نه فقط بعد از باز کردن تبش
        ava_ai_crypto_predict_and_log($conn, $coinId);
        ava_ai_crypto_predict_4h($conn, $coinId);
        echo json_encode(['success' => true]); exit;
    }
    if ($act === 'ai_coin_remove') {
        $coinId = strtolower(preg_replace('/[^a-z0-9\-]/i', '', trim($in['coin_id'] ?? '')));
        if ($coinId === '') { echo json_encode(['success' => false]); exit; }
        $st = $conn->prepare("DELETE FROM user_ai_predict_coins WHERE user_id = ? AND coin_id = ?");
        $st->bind_param("is", $userId, $coinId);
        $st->execute();
        echo json_encode(['success' => true]); exit;
    }

    if ($act === 'ads_at_price') {
        $aiCur = strtoupper(trim($_GET['currency'] ?? ''));
        $aiPrice = (float)($_GET['price'] ?? 0);
        if ($aiCur === '' || $aiPrice <= 0) {
            while (ob_get_level()) { ob_end_clean(); }
            echo json_encode(['success' => false, 'message' => 'پارامتر نامعتبر'], JSON_UNESCAPED_UNICODE); exit;
        }
        $aiTolerance = $aiPrice * 0.05;
        $aiLo = $aiPrice - $aiTolerance;
        $aiHi = $aiPrice + $aiTolerance;
        $aiAds = [];
        $aiStmt = $conn->prepare("SELECT a.id, a.type, a.currency, a.amount, a.price_per_unit,
                                          u.first_name, u.last_name, u.avatar
                                   FROM user_ads a JOIN users u ON a.user_id = u.id
                                   WHERE UPPER(a.currency) = ? AND a.status = 'active' AND a.user_id <> ?
                                     AND a.price_per_unit BETWEEN ? AND ?
                                   ORDER BY ABS(a.price_per_unit - ?) ASC LIMIT 15");
        if ($aiStmt) {
            $aiStmt->bind_param("siddd", $aiCur, $userId, $aiLo, $aiHi, $aiPrice);
            $aiStmt->execute();
            $aiRes = $aiStmt->get_result();
            while ($aiRow = $aiRes->fetch_assoc()) {
                $aiSeller = trim(($aiRow['first_name'] ?? '') . ' ' . ($aiRow['last_name'] ?? ''));
                if ($aiSeller === '') $aiSeller = 'کاربر';
                $aiAvatar = !empty($aiRow['avatar']) ? $aiRow['avatar'] : 'default-avatar.png';
                $aiAvatarSrc = (strpos($aiAvatar, 'http') === 0 || strpos($aiAvatar, '/') === 0)
                    ? $aiAvatar : ('/ledor/' . ltrim($aiAvatar, '/'));
                $aiAds[] = [
                    'id'       => (int)$aiRow['id'],
                    'type'     => $aiRow['type'],
                    'currency' => strtoupper($aiRow['currency']),
                    'amount'   => (float)$aiRow['amount'],
                    'price'    => (float)$aiRow['price_per_unit'],
                    'seller'   => $aiSeller,
                    'avatar'   => $aiAvatarSrc,
                ];
            }
            $aiStmt->close();
        }
        while (ob_get_level()) { ob_end_clean(); }
        echo json_encode(['success' => true, 'ads' => $aiAds, 'price' => $aiPrice, 'currency' => $aiCur], JSON_UNESCAPED_UNICODE); exit;
    }

    if ($act === 'alert_toggle') {
        $id = (int)($in['id'] ?? 0);
        $on = !empty($in['is_active']) ? 1 : 0;
        $st = $conn->prepare("UPDATE price_alerts SET is_active = ? WHERE id = ? AND user_id = ?");
        $st->bind_param("iii", $on, $id, $userId);
        $st->execute();
        echo json_encode(['success' => true]); exit;
    }

    if ($act === 'alert_delete') {
        $id = (int)($in['id'] ?? 0);
        $st = $conn->prepare("DELETE FROM price_alerts WHERE id = ? AND user_id = ?");
        $st->bind_param("ii", $id, $userId);
        $st->execute();
        echo json_encode(['success' => true]); exit;
    }

    if ($act === 'fav_toggle') {
        $mIn = $in['market'] ?? 'fiat';
        $market = in_array($mIn, ['crypto','cryptomarket'], true) ? $mIn : 'fiat';
        $isCryptoLike = ($market === 'crypto' || $market === 'cryptomarket');
        $coinId = $isCryptoLike
            ? strtolower(preg_replace('/[^a-z0-9\-]/i', '', trim($in['currency'] ?? '')))
            : strtoupper(trim($in['currency'] ?? ''));
        // برای جلوگیری از تداخل کلید یکتا، انتخاب‌های «بازار» با پیشوند mkt: ذخیره می‌شوند
        $cur = ($market === 'cryptomarket') ? ('mkt:' . $coinId) : $coinId;
        $coinName = $isCryptoLike ? trim((string)($in['coin_name'] ?? '')) : null;
        if ($coinId === '') { http_response_code(400); echo json_encode(['error' => 'ارز نامعتبر']); exit; }
        // محدودیت ۴ ارز برای تب «بازار»
        if ($market === 'cryptomarket') {
            $chk0 = $conn->prepare("SELECT id FROM user_favorite_rates WHERE user_id = ? AND currency = ?");
            $chk0->bind_param("is", $userId, $cur); $chk0->execute();
            $exists = $chk0->get_result()->num_rows > 0;
            if (!$exists) {
                $cntRes = @$conn->query("SELECT COUNT(*) AS c FROM user_favorite_rates WHERE user_id = " . (int)$userId . " AND market = 'cryptomarket'");
                $cntRow = $cntRes ? $cntRes->fetch_assoc() : null;
                if (((int)($cntRow['c'] ?? 0)) >= 4) { echo json_encode(['error' => 'حداکثر ۴ ارز می‌توانید انتخاب کنید', 'limit' => true]); exit; }
            }
        }
        $chk = $conn->prepare("SELECT id FROM user_favorite_rates WHERE user_id = ? AND currency = ?");
        $chk->bind_param("is", $userId, $cur);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $d = $conn->prepare("DELETE FROM user_favorite_rates WHERE user_id = ? AND currency = ?");
            $d->bind_param("is", $userId, $cur); $d->execute();
            echo json_encode(['success' => true, 'fav' => false]); exit;
        }
        $a = $conn->prepare("INSERT INTO user_favorite_rates (user_id, currency, market, coin_name) VALUES (?,?,?,?)");
        $a->bind_param("isss", $userId, $cur, $market, $coinName); $a->execute();
        echo json_encode(['success' => true, 'fav' => true]); exit;
    }

    // بررسی هشدارهای قیمت در برابر آگهی‌های فعال تبادل ارزی
    if ($act === 'alerts_check') {
        // اطمینان از بارگذاری هاب اعلان (تا ایمیل/تلگرام واقعاً ارسال شود)
        if (!function_exists('notifyUser')) {
            $__nh = __DIR__ . '/includes/notify_helper.php';
            if (file_exists($__nh)) @require_once $__nh;
        }
        $triggered = [];

        // ===== (اصلاح) دریافت آنیِ پول از طریق QR/انتقال داخلی =====
        // به‌جای ساختن یک مکانیزم پولینگ جداگانه، از همین چرخه‌ی هر-۱۵-ثانیه‌ای
        // موجود استفاده می‌شود: هر بار، اعلان‌های تازه‌ی نوع «received» (که
        // api/transaction.php هنگام واریز پول از کاربر دیگر ثبت می‌کند) از
        // آخرین باری که کلاینت چک کرده را برمی‌گردانیم، به‌همراه موجودی
        // به‌روزِ همان ارز، تا هم توست آنی نشان داده شود هم عدد موجودی در
        // کارت داشبورد بدون رفرش صفحه به‌روزرسانی شود.
        $moneyReceived = [];
        $__since = trim($_GET['since'] ?? '');
        if ($__since !== '' && strtotime($__since) !== false) {
            $__sinceEsc = $conn->real_escape_string(date('Y-m-d H:i:s', strtotime($__since)));
            $__mrRes = @$conn->query("SELECT id, message, related_id, created_at FROM user_notifications
                                       WHERE user_id = " . (int)$userId . " AND type='received' AND created_at > '$__sinceEsc'
                                       ORDER BY created_at ASC LIMIT 5");
            if ($__mrRes) {
                while ($__mr = $__mrRes->fetch_assoc()) {
                    $__txnId = (int)($__mr['related_id'] ?? 0);
                    $__txn = $__txnId ? $conn->query("SELECT amount, currency, sender_id FROM transactions WHERE id={$__txnId} LIMIT 1")->fetch_assoc() : null;
                    if (!$__txn) continue;
                    $__cur = strtoupper($__txn['currency']);
                    $__balField = 'balance_' . strtolower($__cur);
                    $__balRow = @$conn->query("SELECT `$__balField` AS bal FROM users WHERE id=" . (int)$userId)->fetch_assoc();
                    $__senderRow = $conn->query("SELECT first_name, last_name FROM users WHERE id=" . (int)$__txn['sender_id'])->fetch_assoc();
                    $__senderName = $__senderRow ? trim(($__senderRow['first_name'] ?? '') . ' ' . ($__senderRow['last_name'] ?? '')) : '';
                    $moneyReceived[] = [
                        'amount'      => (float)$__txn['amount'],
                        'currency'    => $__cur,
                        'from_name'   => $__senderName !== '' ? $__senderName : 'کاربر دیگر',
                        'new_balance' => $__balRow ? (float)$__balRow['bal'] : null,
                    ];
                }
            }
        }

        try {
            $q = $conn->query("SELECT id, currency, target_price, direction,
                                      COALESCE(alert_type,'ad') AS alert_type,
                                      coin_name,
                                      COALESCE(notify_telegram,1) AS notify_telegram,
                                      COALESCE(notify_email,0)   AS notify_email,
                                      COALESCE(notify_toast,1)   AS notify_toast,
                                      COALESCE(check_interval,60) AS check_interval,
                                      last_notified_at,
                                      (last_notified_at IS NULL
                                       OR last_notified_at <= DATE_SUB(NOW(), INTERVAL COALESCE(check_interval,60) MINUTE)
                                      ) AS notify_ok
                               FROM price_alerts
                               WHERE user_id = " . (int)$userId . " AND is_active = 1");
            // نرخ‌های لحظه‌ای ارزها (برای هشدارهای نوع rate)
            $__liveRates = [];
            $__rr = @$conn->query("SELECT currency, price FROM currency_rates");
            if ($__rr) while ($x = $__rr->fetch_assoc()) $__liveRates[strtoupper($x['currency'])] = (float)$x['price'];

            // قیمت لحظه‌ای ارزهای دیجیتال (از کش سرویس CoinGecko که هر دقیقه به‌روز می‌شود)
            $__cryptoPrices = [];
            $__ccFile = sys_get_temp_dir() . '/ava_crypto_market_cache.json';
            if (is_readable($__ccFile)) {
                $__cc = json_decode(@file_get_contents($__ccFile), true);
                if (is_array($__cc)) {
                    foreach (array_merge($__cc['market'] ?? [], $__cc['gainers'] ?? []) as $__coin) {
                        if (isset($__coin['id'], $__coin['price'])) $__cryptoPrices[strtolower($__coin['id'])] = (float)$__coin['price'];
                    }
                }
            }

            while ($q && ($al = $q->fetch_assoc())) {
                $cur  = $conn->real_escape_string($al['currency']);
                $curU = strtoupper($al['currency']);
                $tp   = (float)$al['target_price'];
                $dir  = $al['direction'] === 'below' ? 'below' : 'above';
                $op   = $dir === 'below' ? '<=' : '>=';
                $type = $al['alert_type'] ?? 'ad';
                if (!in_array($type, ['ad','rate','crypto'], true)) $type = 'ad';
                $found = null;

                if ($type === 'crypto') {
                    // قیمت دلاری ارز دیجیتال از کش CoinGecko
                    $cid = strtolower($al['currency']);
                    if (isset($__cryptoPrices[$cid])) {
                        $price = $__cryptoPrices[$cid];
                        if (($dir === 'below' && $price <= $tp) || ($dir === 'above' && $price >= $tp)) $found = $price;
                    }
                } elseif ($type === 'rate') {
                    // فقط نرخ لحظه‌ای همان ارز از alanchand (currency_rates) — بدون بررسی آگهی
                    if (isset($__liveRates[$curU])) {
                        $price = $__liveRates[$curU];
                        if (($dir === 'below' && $price <= $tp) || ($dir === 'above' && $price >= $tp)) $found = $price;
                    }
                } else {
                    // فقط قیمت آگهی‌های فعال بازار
                    $r1 = @$conn->query("SELECT price FROM exchange_ads
                                         WHERE currency = '$cur' AND status = 'active' AND price $op $tp
                                         ORDER BY created_at DESC LIMIT 1");
                    if ($r1 && $r1->num_rows) $found = (float)$r1->fetch_assoc()['price'];
                    if ($found === null) {
                        $r2 = @$conn->query("SELECT price_per_unit AS price FROM user_ads
                                             WHERE currency = '$cur' AND status = 'active' AND price_per_unit $op $tp
                                             ORDER BY created_at DESC LIMIT 1");
                        if ($r2 && $r2->num_rows) $found = (float)$r2->fetch_assoc()['price'];
                    }
                }

                if ($found === null) continue;

                // فقط اگر کاربر «توست/اعلان درون‌اپ» را انتخاب کرده باشد، در اپ نمایش بده
                if ((int)$al['notify_toast'] === 1) {
                    $triggered[] = [
                        'id' => (int)$al['id'], 'currency' => $al['currency'],
                        'coin_name' => $al['coin_name'] ?? null,
                        'target_price' => $tp, 'direction' => $dir,
                        'ad_price' => $found, 'alert_type' => $type
                    ];
                }

                // اطلاع‌رسانی فقط اگر بیش از ۱ دقیقه از آخرین ارسال گذشته باشد
                // مقایسه سمت MySQL (اختلاف ساعت PHP/MySQL نباید اعلان را مسدود کند)
                if ((int)($al['notify_ok'] ?? 1) === 1) {
                    $dirTxt = $dir === 'below' ? 'پایین‌تر از' : 'بالاتر از';
                    if ($type === 'crypto') {
                        $label = !empty($al['coin_name']) ? $al['coin_name'] : $al['currency'];
                        $title = '🔔 هشدار قیمت ارز دیجیتال';
                        $body  = "{$label} به $dirTxt $"
                             . rtrim(rtrim(number_format($tp, 4), '0'), '.') . " رسید.\nقیمت لحظه‌ای: $"
                             . rtrim(rtrim(number_format($found, 4), '0'), '.');
                    } else {
                        $srcTxt = $type === 'rate' ? 'نرخ لحظه‌ای' : 'قیمت آگهی';
                        $title = '🔔 هشدار قیمت';
                        $body  = "ارز {$al['currency']} به $dirTxt "
                             . number_format($tp) . " تومان رسید.\n$srcTxt: " . number_format($found) . " تومان";
                    }
                    // ارسال یکپارچه از طریق هاب اعلان (ایمیل/تلگرام/توست بر اساس انتخاب کاربر)
                    if (function_exists('notifyUser')) {
                        @notifyUser($conn, (int)$userId, $title, $body, [
                            'type'       => 'price_alert',
                            'url'        => '/ledor/dashboard.php',
                            'related_id' => (int)$al['id'],
                            'telegram' => (int)$al['notify_telegram'] === 1,
                            'email'    => (int)$al['notify_email'] === 1,
                            'db'       => (int)$al['notify_toast'] === 1,
                            'push'     => true,
                            // ارسال فوری بدون وابستگی به صف
                            'immediate' => true,
                        ]);
                    }
                    @$conn->query("UPDATE price_alerts SET triggered_at = NOW(), last_notified_at = NOW() WHERE id = " . (int)$al['id']);
                }
            }
        } catch (\Throwable $e) {}
        echo json_encode(['success' => true, 'triggered' => $triggered, 'moneyReceived' => $moneyReceived, 'serverNow' => date('Y-m-d H:i:s')]); exit;
    }

    // علامت‌گذاری فیش‌های دریافتی به‌عنوان دیده‌شده
    if ($act === 'receipts_seen') {
        $conn->query("CREATE TABLE IF NOT EXISTS `receipt_views` (
            `user_id` INT PRIMARY KEY, `last_seen` DATETIME NOT NULL
        )");
        $conn->query("INSERT INTO receipt_views (user_id, last_seen) VALUES (" . (int)$userId . ", NOW())
                      ON DUPLICATE KEY UPDATE last_seen = NOW()");
        echo json_encode(['success' => true]); exit;
    }

    // نرخ‌های لحظه‌ای (برای به‌روزرسانی زنده‌ی کارت نرخ‌ها بدون رفرش صفحه)
    if ($act === 'rates_live') {
        // مطمئن شو همگام‌سازی اجرا شده (اگر بیش از ۵ دقیقه گذشته باشد داخل include انجام می‌شود)
        @include __DIR__ . '/includes/alanchand_sync.php';
        $rows = [];
        $rs = @$conn->query("SELECT currency, price, change_24h, updated_at FROM currency_rates ORDER BY id ASC");
        if ($rs) while ($r = $rs->fetch_assoc()) {
            $__code = strtoupper($r['currency']);
            $__m = function_exists('ava_meta') ? ava_meta($__code) : [];
            $rows[$__code] = [
                'price'   => (float)$r['price'],
                'change'  => (float)$r['change_24h'],
                'updated' => $r['updated_at'],
                'usd'     => !empty($__m['usd']),
            ];
        }
        echo json_encode(['success' => true, 'rates' => $rows]); exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'اکشن نامعتبر']);
    exit;
}


// ضربان‌ساز ارسال ساعتی آگهی‌ها به کانال (بدون نیاز به کرون)
@include __DIR__ . '/includes/ads_broadcast_heartbeat.php';

// ضربان‌ساز بررسی ساعتی هشدارهای قیمت و اطلاع‌رسانی خودکار (بدون نیاز به کرون)
@include __DIR__ . '/includes/price_alert_heartbeat.php';

if (empty($user['avatar']) || !file_exists($user['avatar'])) {
    $user['avatar'] = '/ledor/default-avatar.png';
}

$transactionSql = "SELECT 
    t.*,
    CASE WHEN t.sender_id = ? THEN 'sent' WHEN t.receiver_id = ? THEN 'received' END AS transaction_type,
    CASE WHEN t.sender_id = ? THEN r.first_name ELSE s.first_name END AS contact_name,
    CASE WHEN t.sender_id = ? THEN COALESCE(r.avatar, '/ledor/default-avatar.png') ELSE COALESCE(s.avatar, '/ledor/default-avatar.png') END AS contact_avatar
FROM transactions t
LEFT JOIN users s ON t.sender_id = s.id
LEFT JOIN users r ON t.receiver_id = r.id
WHERE (t.sender_id = ? OR t.receiver_id = ?)
ORDER BY t.created_at DESC LIMIT 10";
$transactionStmt = $conn->prepare($transactionSql);
$transactionStmt->bind_param("iiiiii", $userId, $userId, $userId, $userId, $userId, $userId);
$transactionStmt->execute();
$transactions = $transactionStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ===== GET USER BALANCES =====
$userCurrencies = [];
$balanceDisplay = [];
foreach (['USD', 'EUR', 'USDT', 'IRR'] as $currency) {
    $balanceField = 'balance_' . strtolower($currency);
    $balanceDisplay[$currency] = isset($user[$balanceField]) ? floatval($user[$balanceField]) : 0;
    if ($balanceDisplay[$currency] > 0) {
        $userCurrencies[] = $currency;
    }
}

// ===== BALANCE HISTORY از روی تراکنش‌ها (هر افزایش/کاهش ثبت‌شده) =====
// روش: از بالانس فعلی به عقب برمی‌گردیم و با دلتای هر تراکنش، بالانس هر روز را می‌سازیم.
$balanceHistory = ['USD'=>[], 'EUR'=>[], 'USDT'=>[], 'IRR'=>[]];
$__curBal = ['USD'=>($balanceDisplay['USD']??0), 'EUR'=>($balanceDisplay['EUR']??0), 'USDT'=>($balanceDisplay['USDT']??0), 'IRR'=>($balanceDisplay['IRR']??0)];

// تراکنش‌های ۳۰ روز اخیر کاربر (کامل‌شده)
$__uid = intval($userId);
$__txRes = $conn->query("SELECT amount, currency, type, sender_id, receiver_id, DATE(created_at) as d, created_at
                         FROM transactions
                         WHERE (sender_id = $__uid OR receiver_id = $__uid)
                           AND status = 'completed'
                           AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                         ORDER BY created_at DESC");
$__deltasByDay = []; // day => [cur => net delta that day]
if ($__txRes) {
    while ($__t = $__txRes->fetch_assoc()) {
        $cur = $__t['currency']; if (!isset($__curBal[$cur])) continue;
        $amt = (float)$__t['amount'];
        // ورودی برای کاربر؟ (دریافت‌کننده) => +، خروجی (فرستنده) => -
        $signed = ((int)$__t['receiver_id'] === $__uid) ? $amt : -$amt;
        $day = $__t['d'];
        if (!isset($__deltasByDay[$day])) $__deltasByDay[$day] = ['USD'=>0,'EUR'=>0,'USDT'=>0,'IRR'=>0];
        $__deltasByDay[$day][$cur] += $signed;
    }
}

// ساخت سری روزانه‌ی ۱۴ روز اخیر با برگشت از بالانس فعلی
$__series = ['USD'=>[], 'EUR'=>[], 'USDT'=>[], 'IRR'=>[]];
$__running = $__curBal; // بالانس امروز
for ($i = 0; $i <= 14; $i++) {
    $day = date('Y-m-d', strtotime("-$i day"));
    foreach (['USD','EUR','USDT','IRR'] as $cur) {
        // مقدار پایان روز = running (که شامل تغییرات همان روز هست)
        $__series[$cur][] = ['d'=>$day, 'v'=>round($__running[$cur], 4)];
        // برای رفتن به روز قبل، دلتای امروز را کم کن
        if (isset($__deltasByDay[$day][$cur])) $__running[$cur] -= $__deltasByDay[$day][$cur];
    }
}
// معکوس تا قدیمی‌ترین اول باشد
foreach (['USD','EUR','USDT','IRR'] as $cur) {
    $balanceHistory[$cur] = array_reverse($__series[$cur]);
}

// ===== AUTO-CREATE TABLES =====
// بهبود سرعت: این بلوک (ساخت جدول‌ها + مهاجرت ستون‌ها + پاک‌سازی رکوردهای
// قدیمی) قبلاً روی *هر* بارگذاری داشبورد اجرا می‌شد. این کارها ذاتاً یک‌باره
// هستند و نیازی نیست برای هر کاربر و هر رفرش تکرار شوند؛ حالا مثل بلوک
// مهاجرت بالا، هر ۳۰ دقیقه یک‌بار اجرا می‌شوند.
if (avapay_throttled('dashboard_tables_ensure', 1800)) {
$conn->query("CREATE TABLE IF NOT EXISTS `topup_requests` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `currency` ENUM('USD','EUR','USDT') NOT NULL DEFAULT 'USD',
    `amount` DECIMAL(15,2) NOT NULL,
    `status` ENUM('pending','approved','waiting_payment','payment_received','completed','rejected','finalized') DEFAULT 'pending',
    `reject_reason` TEXT DEFAULT NULL,
    `bank_name` VARCHAR(255) DEFAULT NULL,
    `account_number` VARCHAR(100) DEFAULT NULL,
    `card_number` VARCHAR(30) DEFAULT NULL,
    `recipient_name` VARCHAR(255) DEFAULT NULL,
    `iban` VARCHAR(100) DEFAULT NULL,
    `receipt_image` VARCHAR(500) DEFAULT NULL,
    `admin_id` INT DEFAULT NULL,
    `admin_final_note` TEXT DEFAULT NULL,
    `admin_final_image` VARCHAR(500) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_status (status)
)");

$conn->query("CREATE TABLE IF NOT EXISTS `unpaid_invoices` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `currency` ENUM('USD','EUR','USDT','IRR') NOT NULL DEFAULT 'USD',
    `amount` DECIMAL(15,2) NOT NULL,
    `description` TEXT,
    `status` ENUM('pending','approved','paid','finalized','rejected') DEFAULT 'pending',
    `reject_reason` TEXT DEFAULT NULL,
    `bank_name` VARCHAR(255) DEFAULT NULL,
    `account_number` VARCHAR(100) DEFAULT NULL,
    `card_number` VARCHAR(30) DEFAULT NULL,
    `recipient_name` VARCHAR(255) DEFAULT NULL,
    `iban` VARCHAR(100) DEFAULT NULL,
    `receipt_image` VARCHAR(500) DEFAULT NULL,
    `admin_id` INT DEFAULT NULL,
    `admin_final_note` TEXT DEFAULT NULL,
    `admin_final_image` VARCHAR(500) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_status (status)
)");

// صورت‌حساب‌هایی که از معامله‌ی تبادل ارزی ساخته می‌شوند به معامله گره می‌خورند
// از PHP 8.1 به بعد mysqli خطاها را Exception پرتاب می‌کند و @ جلوی آن را
// نمی‌گیرد؛ بنابراین این مهاجرت باید داخل try/catch باشد وگرنه یک خطای
// جزئی می‌تواند کل داشبورد را از کار بیندازد.
try {
    $conn->query("ALTER TABLE `unpaid_invoices` ADD COLUMN IF NOT EXISTS `deal_id` INT DEFAULT NULL");
    $conn->query("ALTER TABLE `unpaid_invoices` ADD COLUMN IF NOT EXISTS `deal_side` VARCHAR(10) DEFAULT NULL");
} catch (Throwable $e) {
    error_log('unpaid_invoices deal columns migration: ' . $e->getMessage());
}

// ===== FIX: AUTO-DELETE REQUESTS OLDER THAN 1 MONTH =====
$conn->query("DELETE FROM topup_requests WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 MONTH)");
$conn->query("DELETE FROM unpaid_invoices WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 MONTH)");

// ---- ایندکس‌های عملکردی روی جدول‌هایی که در هر بارگذاری داشبورد خوانده می‌شوند ----
// بخش «فیش‌های دریافتی» در هر لود چند کوئری روی این جدول‌ها می‌زند و بدون
// ایندکس روی user_id، هر کدام یک full table scan بود.
$__perfIdx = [
    ['user_receipts',       'idx_ur_user',   '(`user_id`)'],
    ['ad_deals',            'idx_ad_buyer',  '(`buyer_id`)'],
    ['ad_deals',            'idx_ad_seller', '(`seller_id`)'],
    ['ad_deals',            'idx_ad_status', '(`status`)'],
    ['withdrawal_requests', 'idx_wr_user',   '(`user_id`)'],
    ['money_transfers',     'idx_mt_user',   '(`user_id`)'],
];
foreach ($__perfIdx as $__ix) {
    try {
        $__has = false;
        $__r = @$conn->query("SHOW INDEX FROM `{$__ix[0]}`");
        if ($__r) { while ($__row = $__r->fetch_assoc()) { if (strtolower($__row['Key_name']) === $__ix[1]) { $__has = true; break; } } }
        if (!$__has) @$conn->query("ALTER TABLE `{$__ix[0]}` ADD INDEX `{$__ix[1]}` {$__ix[2]}");
    } catch (\Throwable $e) { /* جدول ممکن است وجود نداشته باشد — بی‌اهمیت */ }
}

} // پایان throttle بلوک ساخت/پاک‌سازی جدول‌ها

// ============================================================
// ========== سیستم مدیریت آپدیت نسخه ==========
// ============================================================

// ========== مدیریت درخواست‌های AJAX برای آپدیت ==========
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    // دریافت نسخه‌ی کاربر
    if ($_GET['action'] === 'get_version') {
        $stmt = $conn->prepare("SELECT app_version FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            echo json_encode(['version' => $row['app_version']]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'User not found']);
        }
        $stmt->close();
        exit;
    }
    
    // به‌روزرسانی نسخه‌ی کاربر
    if ($_GET['action'] === 'update_version') {
        $input = json_decode(file_get_contents('php://input'), true);
        $newVersion = $input['version'] ?? null;
        
        if (!$newVersion || !preg_match('/^\d+\.\d+\.\d+$/', $newVersion)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid version format']);
            exit;
        }
        
        $stmt = $conn->prepare("UPDATE users SET app_version = ? WHERE id = ?");
        $stmt->bind_param("si", $newVersion, $userId);
        if ($stmt->execute()) {
            $_SESSION['app_version'] = $newVersion;
            echo json_encode(['success' => true]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Database update failed: ' . $stmt->error]);
        }
        $stmt->close();
        exit;
    }
    
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
    exit;
}

// ========== بارگذاری نسخه کاربر از دیتابیس ==========
$userVersion = $_SESSION['app_version'] ?? null;
if (!$userVersion) {
    $stmt = $conn->prepare("SELECT app_version FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $userVersion = $row['app_version'];
        $_SESSION['app_version'] = $userVersion;
    }
    $stmt->close();
}

// ============================================================

$isUserAdmin = isset($user['is_admin']) && $user['is_admin'] == 1;

// ============================================================
// ========== داشبورد جدید: واکشی داده‌ها از دیتابیس ==========
// ============================================================

// --- تبدیل اعداد به فارسی ---
if (!function_exists('ava_fa')) {
    function ava_fa($str) {
        return str_replace(['0','1','2','3','4','5','6','7','8','9'],
                           ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'], (string)$str);
    }
}
if (!function_exists('ava_num')) {
    function ava_num($n, $dec = 0) { return ava_fa(number_format((float)$n, $dec)); }
}
if (!function_exists('ava_ago')) {
    function ava_ago($ts) {
        $d = time() - strtotime($ts);
        if ($d < 60)     return 'همین حالا';
        if ($d < 3600)   return ava_fa(floor($d/60)) . ' دقیقه پیش';
        if ($d < 86400)  return ava_fa(floor($d/3600)) . ' ساعت پیش';
        if ($d < 2592000) return ava_fa(floor($d/86400)) . ' روز پیش';
        return ava_fa(date('Y/m/d', strtotime($ts)));
    }
}

// --- متادیتای ارزها ---
$avaMeta = [
    'IRR'  => ['name'=>'تومان',        'unit'=>'تومان', 'code'=>'IRT',  'flag'=>'ir', 'dec'=>0],
    'USD'  => ['name'=>'دلار آمریکا',  'unit'=>'دلار',  'code'=>'USD',  'flag'=>'us', 'dec'=>0],
    'EUR'  => ['name'=>'یورو',         'unit'=>'یورو',  'code'=>'EUR',  'flag'=>'eu', 'dec'=>0],
    'AED'  => ['name'=>'درهم امارات',  'unit'=>'درهم',  'code'=>'AED',  'flag'=>'ae', 'dec'=>0],
    'TRY'  => ['name'=>'لیر ترکیه',    'unit'=>'لیر',   'code'=>'TRY',  'flag'=>'tr', 'dec'=>0],
    'GBP'  => ['name'=>'پوند انگلیس',  'unit'=>'پوند',  'code'=>'GBP',  'flag'=>'gb', 'dec'=>0],
    'CAD'  => ['name'=>'دلار کانادا',  'unit'=>'دلار',  'code'=>'CAD',  'flag'=>'ca', 'dec'=>0],
    'USDT' => ['name'=>'تتر',          'unit'=>'تتر',   'code'=>'USDT', 'flag'=>'',   'dec'=>2, 'ico'=>'fas fa-coins',   'col'=>'#26A17B'],
    'BTC'  => ['name'=>'بیت‌کوین',      'unit'=>'بیت‌کوین','code'=>'BTC','flag'=>'',   'dec'=>6, 'ico'=>'fab fa-bitcoin', 'col'=>'#F7931A'],
    'ETH'  => ['name'=>'اتریوم',       'unit'=>'اتریوم','code'=>'ETH',  'flag'=>'',   'dec'=>4, 'ico'=>'fab fa-ethereum','col'=>'#627EEA'],
    'CNY'  => ['name'=>'یوان چین',      'unit'=>'یوان',  'code'=>'CNY',  'flag'=>'cn', 'dec'=>0],
    'AUD'  => ['name'=>'دلار استرالیا', 'unit'=>'دلار',  'code'=>'AUD',  'flag'=>'au', 'dec'=>0],
    'RUB'  => ['name'=>'روبل روسیه',    'unit'=>'روبل',  'code'=>'RUB',  'flag'=>'ru', 'dec'=>0],
    'IQD'  => ['name'=>'دینار عراق',    'unit'=>'دینار', 'code'=>'IQD',  'flag'=>'iq', 'dec'=>0],
    'MYR'  => ['name'=>'رینگیت مالزی',  'unit'=>'رینگیت','code'=>'MYR',  'flag'=>'my', 'dec'=>0],
    'GEL'  => ['name'=>'لاری گرجستان',  'unit'=>'لاری',  'code'=>'GEL',  'flag'=>'ge', 'dec'=>0],
    'AZN'  => ['name'=>'منات آذربایجان','unit'=>'منات',  'code'=>'AZN',  'flag'=>'az', 'dec'=>0],
    'AMD'  => ['name'=>'درام ارمنستان', 'unit'=>'درام',  'code'=>'AMD',  'flag'=>'am', 'dec'=>0],
    'THB'  => ['name'=>'بات تایلند',    'unit'=>'بات',   'code'=>'THB',  'flag'=>'th', 'dec'=>0],
    'OMR'  => ['name'=>'ریال عمان',     'unit'=>'ریال',  'code'=>'OMR',  'flag'=>'om', 'dec'=>0],
    'INR'  => ['name'=>'روپیه هند',     'unit'=>'روپیه', 'code'=>'INR',  'flag'=>'in', 'dec'=>0],
    'PKR'  => ['name'=>'روپیه پاکستان', 'unit'=>'روپیه', 'code'=>'PKR',  'flag'=>'pk', 'dec'=>0],
    'JPY'  => ['name'=>'ین ژاپن',       'unit'=>'ین',    'code'=>'JPY',  'flag'=>'jp', 'dec'=>0],
    'SAR'  => ['name'=>'ریال عربستان',  'unit'=>'ریال',  'code'=>'SAR',  'flag'=>'sa', 'dec'=>0],
    'AFN'  => ['name'=>'افغانی',        'unit'=>'افغانی','code'=>'AFN',  'flag'=>'af', 'dec'=>0],
    'SEK'  => ['name'=>'کرون سوئد',     'unit'=>'کرون',  'code'=>'SEK',  'flag'=>'se', 'dec'=>0],
    'CHF'  => ['name'=>'فرانک سوئیس',   'unit'=>'فرانک', 'code'=>'CHF',  'flag'=>'ch', 'dec'=>0],
    'QAR'  => ['name'=>'ریال قطر',      'unit'=>'ریال',  'code'=>'QAR',  'flag'=>'qa', 'dec'=>0],
    'KRW'  => ['name'=>'وون کره جنوبی', 'unit'=>'وون',   'code'=>'KRW',  'flag'=>'kr', 'dec'=>0],
    'NOK'  => ['name'=>'کرون نروژ',     'unit'=>'کرون',  'code'=>'NOK',  'flag'=>'no', 'dec'=>0],
    'NZD'  => ['name'=>'دلار نیوزلند',  'unit'=>'دلار',  'code'=>'NZD',  'flag'=>'nz', 'dec'=>0],
    'SGD'  => ['name'=>'دلار سنگاپور',  'unit'=>'دلار',  'code'=>'SGD',  'flag'=>'sg', 'dec'=>0],
    'HKD'  => ['name'=>'دلار هنگ‌کنگ',  'unit'=>'دلار',  'code'=>'HKD',  'flag'=>'hk', 'dec'=>0],
    'KWD'  => ['name'=>'دینار کویت',    'unit'=>'دینار', 'code'=>'KWD',  'flag'=>'kw', 'dec'=>0],
    'DKK'  => ['name'=>'کرون دانمارک',  'unit'=>'کرون',  'code'=>'DKK',  'flag'=>'dk', 'dec'=>0],
    'BHD'  => ['name'=>'دینار بحرین',   'unit'=>'دینار', 'code'=>'BHD',  'flag'=>'bh', 'dec'=>0],
    // --- طلا و سکه (از alanchand) ---
    'GOLD_MESGHAL' => ['name'=>'آبشده (مثقال طلا)', 'unit'=>'تومان', 'code'=>'مثقال', 'flag'=>'', 'dec'=>0, 'ico'=>'fas fa-bars-staggered', 'col'=>'#F5B400', 'gold'=>1],
    'GOLD_18'      => ['name'=>'طلای ۱۸ عیار (گرم)', 'unit'=>'تومان', 'code'=>'گرم', 'flag'=>'', 'dec'=>0, 'ico'=>'fas fa-ring', 'col'=>'#F5B400', 'gold'=>1],
    'COIN_EMAMI'   => ['name'=>'سکه امامی (جدید)', 'unit'=>'تومان', 'code'=>'سکه', 'flag'=>'', 'dec'=>0, 'ico'=>'fas fa-coins', 'col'=>'#E0A400', 'gold'=>1],
    'GOLD_OUNCE'   => ['name'=>'انس طلا', 'unit'=>'دلار', 'code'=>'XAU', 'flag'=>'', 'dec'=>2, 'ico'=>'fas fa-globe', 'col'=>'#F5B400', 'gold'=>1, 'usd'=>1],
];

// --- کوئری ایمن: در صورت نبودِ ستون/جدول، به‌جای Fatal یک آرایه خالی برمی‌گرداند ---
if (!function_exists('ava_safe_rows')) {
    function ava_safe_rows($conn, $sql, $types = '', $params = []) {
        try {
            if ($types !== '' && !empty($params)) {
                $st = $conn->prepare($sql);
                if (!$st) return [];
                $st->bind_param($types, ...$params);
                $st->execute();
                return $st->get_result()->fetch_all(MYSQLI_ASSOC);
            }
            $r = $conn->query($sql);
            return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('ava_meta')) {
    function ava_meta($code) {
        global $avaMeta;
        return $avaMeta[$code] ?? ['name'=>$code,'unit'=>$code,'code'=>$code,'flag'=>'','dec'=>2,'ico'=>'fas fa-coins','col'=>'#7C3AED'];
    }
}

/* ===================================================================
   (آپدیت) گجت هوشمند بازار — محاسبه‌ی تاریخچه/پیش‌بینی/تحلیل‌روند برای یک
   ارز رایج (دلار/یورو/تتر)، بسته به بازه‌ی زمانی انتخابی کاربر (روز/ماه/سال).
   این تابع هم برای رندر اولیه‌ی صفحه (بازه‌ی پیش‌فرض «۱ ماهه») و هم برای
   اکشن AJAX «ai_history» (وقتی کاربر تب بازه‌ی دیگری را می‌زند) استفاده
   می‌شود، تا منطق یک‌بار نوشته شود و برای هر بازه یکسان کار کند.
   صادقانه: «پیش‌بینی» یک مدل هوش مصنوعی واقعی نیست — یک رگرسیون خطی ساده
   (کمترین مربعات) روی تاریخچه‌ی همان بازه است که به همان اندازه‌ی بازه
   جلوتر برون‌یابی می‌شود (مثلاً برای «۱ ماهه»، ۳۰ روز جلوتر).
   =================================================================== */
// --- سطح کاربری (بر اساس تعداد سفارش‌های تکمیل‌شده) ---
$avaOrdersDone = (int)($user['completed_orders_count'] ?? 0);
if     ($avaOrdersDone >= 50) $avaLevel = 'ویژه';
elseif ($avaOrdersDone >= 20) $avaLevel = 'طلایی';
elseif ($avaOrdersDone >= 5)  $avaLevel = 'نقره‌ای';
else                          $avaLevel = 'عادی';
$avaFullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
if ($avaFullName === '') $avaFullName = 'کاربر آواپی';

// تاریخ عضویت به‌صورت سال شمسی تقریبی (بدون کتابخانه‌ی خارجی)
if (!function_exists('jdate_safe')) {
    function jdate_safe($datetime) {
        $ts = strtotime($datetime);
        if (!$ts) return '—';
        // تبدیل تقریبی سال میلادی به شمسی
        $gy = (int)date('Y', $ts); $gm = (int)date('n', $ts); $gd = (int)date('j', $ts);
        // الگوریتم استاندارد میلادی→جلالی
        $g_d_m = [0,31,59,90,120,151,181,212,243,273,304,334];
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = 355666 + (365 * $gy) + (int)(($gy2 + 3) / 4) - (int)(($gy2 + 99) / 100)
              + (int)(($gy2 + 399) / 400) + $gd + $g_d_m[$gm - 1];
        $jy = -1595 + (33 * (int)($days / 12053)); $days %= 12053;
        $jy += 4 * (int)($days / 1461); $days %= 1461;
        if ($days > 365) { $jy += (int)(($days - 1) / 365); }
        return (string)$jy;
    }
}

// --- ولت‌های کاربر: به‌صورت داینامیک از ستون‌های balance_* جدول users ---
$avaBalances = [];
$__cols = $conn->query("SHOW COLUMNS FROM users LIKE 'balance\\_%'");
if ($__cols) {
    while ($c = $__cols->fetch_assoc()) {
        $code = strtoupper(substr($c['Field'], 8));
        $avaBalances[$code] = (float)($user[$c['Field']] ?? 0);
    }
}
// ترتیب نمایش (در RTL اولین آیتم سمت راست است)
$avaOrderPref = ['IRR','USDT','AED','EUR','USD','TRY','GBP','CAD','BTC','ETH'];
$avaWallets = [];
foreach ($avaOrderPref as $c) if (isset($avaBalances[$c])) $avaWallets[$c] = $avaBalances[$c];
foreach ($avaBalances as $c => $v) if (!isset($avaWallets[$c])) $avaWallets[$c] = $v;

// --- نرخ ارزها ---
$avaRates = [];
foreach (ava_safe_rows($conn, "SELECT currency, price, change_24h, updated_at FROM currency_rates ORDER BY id ASC") as $r) {
    $avaRates[strtoupper($r['currency'])] = $r;
}

// --- علاقه‌مندی‌های کاربر (ارز/طلا) + واچ‌لیست + انتخاب ارزهای بازار ---
$avaFavs = [];         // ارزهای فیات مورد علاقه (کدهای بزرگ)
$avaCryptoWatch = [];  // واچ‌لیست ارز دیجیتال: [ ['id'=>..., 'name'=>...], ... ]
$avaMarketPick = [];   // ارزهای انتخاب‌شده برای تب «بازار» (حداکثر ۴)
foreach (ava_safe_rows($conn,
    "SELECT currency, COALESCE(market,'fiat') AS market, coin_name
     FROM user_favorite_rates WHERE user_id = ?", "i", [$userId]) as $f) {
    $mk = $f['market'] ?? 'fiat';
    if ($mk === 'crypto') {
        $avaCryptoWatch[] = ['id' => strtolower($f['currency']), 'name' => $f['coin_name'] ?: $f['currency']];
    } elseif ($mk === 'cryptomarket') {
        $__mid = preg_replace('/^mkt:/', '', strtolower($f['currency']));
        if ($__mid !== '' && count($avaMarketPick) < 4) $avaMarketPick[] = $__mid;
    } else {
        $avaFavs[] = strtoupper($f['currency']);
    }
}

// --- حساب‌های معرفی‌شده برای تسویه ---
$avaBeneficiaries = ava_safe_rows($conn,
    "SELECT id, full_name, card_number, iban, bank_name, note, avatar
     FROM user_beneficiaries WHERE user_id = ? ORDER BY full_name ASC", "i", [$userId]);

// --- ارزش کل سبد دارایی به تومان ---
if (!function_exists('ava_rate_toman')) {
    function ava_rate_toman($code) {
        global $avaRates;
        if ($code === 'IRR') return 1.0;
        return isset($avaRates[$code]) ? (float)$avaRates[$code]['price'] : 0.0;
    }
}
// --- بالانس فعلی هر ولت (برای نمایش «بالانس فعلی» به‌جای ارزش کل) ---
$avaTotalToman = 0;
foreach ($avaWallets as $c => $b) $avaTotalToman += $b * ava_rate_toman($c);

// --- سری زمانی بالانس هر ولت (۳۶۵ روز، از روی تراکنش‌های کاربر) ---
// هر ارز، «میزان موجودی همان ولت» را در طول زمان نشان می‌دهد (نه ارزش ریالی).
$__days   = 365;
$__run    = $avaWallets;                 // بالانس امروز هر ارز
$__delta  = [];                          // day => [cur => delta]
$__tq = $conn->prepare("SELECT amount, currency, sender_id, receiver_id, DATE(created_at) AS d
                        FROM transactions
                        WHERE (sender_id = ? OR receiver_id = ?) AND status = 'completed'
                          AND created_at >= DATE_SUB(NOW(), INTERVAL 365 DAY)");
$__tq->bind_param("ii", $userId, $userId); $__tq->execute();
$__tr = $__tq->get_result();
while ($t = $__tr->fetch_assoc()) {
    $c = strtoupper($t['currency']);
    if (!isset($__run[$c])) continue;
    $sgn = ((int)$t['receiver_id'] === (int)$userId) ? 1 : -1;
    $__delta[$t['d']][$c] = ($__delta[$t['d']][$c] ?? 0) + $sgn * (float)$t['amount'];
}
$avaSeries = [];        // نگه‌داری برای محاسبه‌ی سود/زیان کلی (به تومان)
$avaSeriesByCur = [];   // cur => [بالانس همان ولت در هر روز] قدیمی → جدید
// همه‌ی ولت‌های کاربر پیل جداگانه دارند
$__portCurs = array_keys($avaWallets);
foreach ($__portCurs as $c) $avaSeriesByCur[$c] = [];
for ($i = 0; $i <= $__days; $i++) {
    $day = date('Y-m-d', strtotime("-$i day"));
    $tot = 0;
    foreach ($__run as $c => $b) $tot += $b * ava_rate_toman($c);
    $avaSeries[] = ['d' => $day, 'v' => round($tot)];
    // بالانس هر ولت در این روز
    foreach ($__portCurs as $c) {
        $dec = ava_meta($c)['dec'] ?? 2;
        $avaSeriesByCur[$c][] = round((float)($__run[$c] ?? 0), $dec);
    }
    foreach ($__run as $c => $b) {
        if (isset($__delta[$day][$c])) $__run[$c] -= $__delta[$day][$c];
    }
}
$avaSeries = array_reverse($avaSeries);
foreach ($avaSeriesByCur as $c => $arr) $avaSeriesByCur[$c] = array_reverse($arr);

// --- تولید نمودار خطی کوچک (sparkline) سبز/قرمز برای یک سری عددی ---
if (!function_exists('ava_spark_svg')) {
    function ava_spark_svg($series, $w = 90, $h = 26) {
        $series = array_values(array_filter($series, function($v){ return is_numeric($v); }));
        $series = array_slice($series, -14);
        if (count($series) < 2) {
            // اگر داده کافی نیست، خط صاف خنثی
            $series = [0, 0];
        }
        $up   = ($series[count($series)-1] >= $series[0]);
        $col  = $up ? '#22C55E' : '#FF5A6E';
        $min  = min($series); $max = max($series); $rng = ($max - $min) ?: 1;
        $n    = count($series);
        $pts  = [];
        foreach ($series as $i => $v) {
            $x = round($i * ($w / ($n - 1)), 1);
            $y = round($h - 3 - (($v - $min) / $rng) * ($h - 6), 1);
            $pts[] = "$x,$y";
        }
        $path = 'M' . implode(' L', $pts);
        $area = $path . " L$w,$h L0,$h Z";
        $gid  = 'wsg' . substr(md5(implode(',', $series) . $col), 0, 6);
        return '<svg class="ava-wal-mini-spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" aria-hidden="true">'
             . '<defs><linearGradient id="' . $gid . '" x1="0" y1="0" x2="0" y2="1">'
             . '<stop offset="0%" stop-color="' . $col . '" stop-opacity="0.25"/>'
             . '<stop offset="100%" stop-color="' . $col . '" stop-opacity="0"/></linearGradient></defs>'
             . '<path d="' . $area . '" fill="url(#' . $gid . ')"/>'
             . '<path d="' . $path . '" fill="none" stroke="' . $col . '" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>'
             . '</svg>';
    }
}


// --- سفارشات فعال کاربر ---
$avaOrders = [];
$avaOrders = ava_safe_rows($conn, "SELECT id, type, currency, amount, price_per_unit, status, created_at
                        FROM user_ads WHERE user_id = ? AND status = 'active'
                        ORDER BY created_at DESC LIMIT 5", "i", [$userId]);

// --- سفارش‌های ثبت‌شده از «خدمات محبوب» ---
$avaServiceOrders = ava_safe_rows($conn,
    "SELECT s.id, s.form_data, s.status, s.created_at,
            q.label AS service_label, q.icon AS service_icon, q.icon_image AS service_icon_image, q.color AS service_color
     FROM quick_action_submissions s
     LEFT JOIN quick_actions q ON s.action_id = q.id
     WHERE s.user_id = ?
     ORDER BY s.created_at DESC LIMIT 6", "i", [$userId]);

// --- هشدارهای قیمت (یک لیست واحد؛ همه‌ی انواع با هم) ---
$avaAlerts = ava_safe_rows($conn, "SELECT id, currency, target_price, direction, is_active,
                                          COALESCE(alert_type,'ad') AS alert_type,
                                          coin_name,
                                          COALESCE(notify_telegram,1) AS notify_telegram,
                                          COALESCE(notify_email,0) AS notify_email,
                                          COALESCE(notify_toast,1) AS notify_toast,
                                          COALESCE(check_interval,60) AS check_interval
                        FROM price_alerts WHERE user_id = ? ORDER BY created_at DESC LIMIT 30", "i", [$userId]);

// آیکون اختصاصی کاربریِ AvaPay برای حساب‌های معرفی‌شده (SVG)
function avaBenIcon($size = 26) {
    // آیکون حساب‌های معرفی‌شده: لوگوی سکه‌ای AVA PAY
    $v = @filemtime(__DIR__ . '/assets/images/ben-avatar.png') ?: time();
    return '<img class="ava-ben-glyph" src="assets/images/ben-avatar.png?v=' . $v . '"'
         . ' width="' . $size . '" height="' . $size . '" alt="AVA PAY" loading="lazy">';
}

// --- حواله‌های فعال (در جریانِ تبادل ارزی) ---
$avaTransfers = ava_safe_rows($conn, "SELECT id, tracking_code, country, currency, amount, status, created_at
                        FROM money_transfers
                        WHERE user_id = ? AND status IN ('pending','approved','waiting_payment','payment_submitted')
                        ORDER BY created_at DESC LIMIT 6", "i", [$userId]);
$avaTrStatus = [
    'pending'           => ['t'=>'در حال بررسی', 'c'=>'wait', 'i'=>'fa-clock'],
    'approved'          => ['t'=>'تأیید شده',    'c'=>'ok',   'i'=>'fa-check'],
    'waiting_payment'   => ['t'=>'در انتظار پرداخت','c'=>'wait','i'=>'fa-hourglass-half'],
    'payment_submitted' => ['t'=>'رسید ارسال شد','c'=>'wait', 'i'=>'fa-receipt'],
    'completed'         => ['t'=>'تکمیل شده',    'c'=>'ok',   'i'=>'fa-check-circle'],
    'rejected'          => ['t'=>'رد شده',       'c'=>'no',   'i'=>'fa-times-circle'],
];

// --- فاکتورها/صورت‌حساب‌ها ---
$avaInvHistory = ava_safe_rows($conn, "SELECT id, currency, amount, description, status, created_at
                        FROM unpaid_invoices
                        WHERE user_id = ? AND status IN ('paid','finalized','rejected')
                        ORDER BY created_at DESC LIMIT 6", "i", [$userId]);
$avaInvPending = ava_safe_rows($conn, "SELECT id, currency, amount, description, status, created_at
                        FROM unpaid_invoices
                        WHERE user_id = ? AND status IN ('pending','approved')
                        ORDER BY created_at DESC LIMIT 6", "i", [$userId]);

// --- فیش‌های دریافتی (فیش‌هایی که ادمین/سیستم برای کاربر ثبت کرده) ---
$avaReceipts = [];

// نرمال‌سازی مسیر فایل به URL قابل نمایش
$__recUrl = function($file) {
    $f = trim((string)$file);
    if ($f === '') return '';
    if (preg_match('#^https?://#i', $f)) return $f;
    $f = ltrim($f, '/');
    if (stripos($f, 'ledor/') === 0) $f = substr($f, 6);
    return '/ledor/' . ltrim($f, '/');
};

// افزودن یک «فیش» که می‌تواند شامل چند فایل باشد (گروه‌بندی‌شده)
$__addReceipt = function($type, $icon, $color, $files, $date) use (&$avaReceipts, $__recUrl) {
    if (!is_array($files)) $files = [$files];
    $urls = [];
    foreach ($files as $f) { $u = $__recUrl($f); if ($u !== '') $urls[] = $u; }
    if (empty($urls)) return;
    $avaReceipts[] = ['type'=>$type, 'icon'=>$icon, 'color'=>$color, 'files'=>$urls, 'date'=>$date];
};

// (۱) تبادل ارزی (payment_receipts — قدیمی)
foreach (ava_safe_rows($conn, "SELECT id, image_path AS file, created_at FROM payment_receipts
                               WHERE user_id = ? ORDER BY created_at DESC LIMIT 8", "i", [$userId]) as $r) {
    $__addReceipt('تبادل ارزی', 'fa-right-left', '#38BDF8', $r['file'], $r['created_at']);
}
// (۲) حواله ارز / تسویه (transfer_receipts)
foreach (ava_safe_rows($conn, "SELECT id, file_path AS file, type, created_at FROM transfer_receipts
                               WHERE user_id = ? ORDER BY created_at DESC LIMIT 8", "i", [$userId]) as $r) {
    $isSettle = ($r['type'] ?? '') === 'settlement';
    $__addReceipt($isSettle ? 'تسویه حساب' : 'حواله ارز',
                  $isSettle ? 'fa-file-invoice-dollar' : 'fa-globe',
                  $isSettle ? '#FBBF24' : '#A855F7', $r['file'], $r['created_at']);
}
// (۳) تسویه حساب (user_receipts) — شامل فیش‌هایی که ادمین مستقیماً برای کاربر می‌فرستد
// نکته: این جدول ستون created_at ندارد؛ ستون زمان آن uploaded_at است.
// (قبلاً created_at خوانده می‌شد و کوئری بی‌صدا خطا می‌داد، پس فیش‌های ارسالی ادمین هرگز نمایش داده نمی‌شدند.)
foreach (ava_safe_rows($conn, "SELECT id, receipt_file AS file, uploaded_at AS d FROM user_receipts
                               WHERE user_id = ? AND uploaded_by IS NOT NULL
                               ORDER BY uploaded_at DESC LIMIT 8", "i", [$userId]) as $r) {
    $__addReceipt('تسویه حساب', 'fa-file-invoice-dollar', '#FBBF24', $r['file'], $r['d'] ?? null);
}
// (۴) تسویه حساب / برداشت (withdrawal_requests) — فیشی که ادمین هنگام تکمیل آپلود می‌کند
foreach (ava_safe_rows($conn, "SELECT id, receipt_file, receipt_files, receipt_uploaded_at, completed_at
                               FROM withdrawal_requests
                               WHERE user_id = ? AND (receipt_file IS NOT NULL OR receipt_files IS NOT NULL)
                               ORDER BY id DESC LIMIT 8", "i", [$userId]) as $r) {
    $when = $r['receipt_uploaded_at'] ?? ($r['completed_at'] ?? null);
    $files = [];
    if (!empty($r['receipt_files'])) { $dec = json_decode($r['receipt_files'], true); if (is_array($dec)) $files = $dec; }
    if (empty($files) && !empty($r['receipt_file'])) $files = [$r['receipt_file']];
    $__addReceipt('تسویه حساب', 'fa-money-check-dollar', '#FBBF24', $files, $when);
}
// (۵) معاملات تبادل ارزی (ad_deals) — فیش تسویه‌ای که ادمین برای طرفِ معامله آپلود می‌کند
foreach (ava_safe_rows($conn, "SELECT id, buyer_id, seller_id, deal_code,
                                      buyer_settlement_receipts, seller_settlement_receipts,
                                      completed_at, created_at
                               FROM ad_deals
                               WHERE (buyer_id = ? OR seller_id = ?)
                               ORDER BY id DESC LIMIT 15", "ii", [$userId, $userId]) as $r) {
    $when = $r['completed_at'] ?? ($r['created_at'] ?? null);
    $side = ((int)$r['buyer_id'] === (int)$userId) ? 'buyer' : 'seller';
    $col  = $side . '_settlement_receipts';
    if (!empty($r[$col])) {
        $dec = json_decode($r[$col], true);
        if (is_array($dec) && !empty($dec)) $__addReceipt('تسویه معامله', 'fa-handshake', '#34D399', $dec, $when);
    }
}
// (۶) حواله ارز (money_transfers) — فیش تسویه‌ی نهایی که ادمین پس از پرداخت کاربر آپلود می‌کند
foreach (ava_safe_rows($conn, "SELECT id, settlement_receipts, settlement_receipt, completed_at, created_at
                               FROM money_transfers
                               WHERE user_id = ?
                                 AND (settlement_receipts IS NOT NULL OR settlement_receipt IS NOT NULL)
                               ORDER BY id DESC LIMIT 10", "i", [$userId]) as $r) {
    $when = $r['completed_at'] ?? ($r['created_at'] ?? null);
    $files = [];
    if (!empty($r['settlement_receipts'])) { $dec = json_decode($r['settlement_receipts'], true); if (is_array($dec)) $files = $dec; }
    if (empty($files) && !empty($r['settlement_receipt'])) $files = [$r['settlement_receipt']];
    $__addReceipt('حواله ارز', 'fa-globe', '#A855F7', $files, $when);
}

// مرتب‌سازی بر اساس تاریخ (جدیدترین اول)
usort($avaReceipts, function($a,$b){ return strtotime($b['date'] ?? '0') <=> strtotime($a['date'] ?? '0'); });
$avaReceiptsAll = $avaReceipts;   // آرشیو کامل (همه‌ی فیش‌ها)

// آخرین زمان دیده‌شدن (فیش‌های خوانده‌شده در کارت نمایش داده نمی‌شوند، فقط در آرشیو می‌مانند)
$avaReceiptLastSeen = 0;
$__rv = @$conn->query("SELECT last_seen FROM receipt_views WHERE user_id = " . (int)$userId);
if ($__rv && $__rv->num_rows) $avaReceiptLastSeen = strtotime($__rv->fetch_assoc()['last_seen']);

// کارت فقط فیش‌های «خوانده‌نشده» (جدیدتر از آخرین بازدید) را نشان می‌دهد
$avaReceiptsUnseen = array_values(array_filter($avaReceiptsAll, function($r) use ($avaReceiptLastSeen) {
    return strtotime($r['date'] ?? '0') > $avaReceiptLastSeen;
}));
$avaReceipts      = array_slice($avaReceiptsUnseen, 0, 8);  // نمایش در کارت
$avaHasNewReceipt = !empty($avaReceiptsUnseen);

/* ================================================================
 * (آپدیت ۲) حساب‌های ارسالی ادمین که منتظر پرداخت کاربر هستند
 * از دو منبع: حواله ارزی (money_transfers) و تبادل ارزی (ad_deals)
 * هر آیتم: {source, ref_id, title, code, amount, currency, accounts[], date, note, needs_balance}
 * ---------------------------------------------------------------- */
$avaPendingAccounts = [];

// (۱) حواله ارزی: ادمین شماره‌حساب فرستاده و کاربر هنوز فیش نداده
foreach (ava_safe_rows($conn,
    "SELECT id, tracking_code, currency, amount, admin_accounts, admin_note, status,
            approved_at, created_at, payment_receipts, payment_receipt
     FROM money_transfers
     WHERE user_id = ? AND admin_accounts IS NOT NULL AND admin_accounts <> ''
       AND status IN ('approved','waiting_payment','awaiting_payment')",
    'i', [$userId]) as $mt) {
    $accs = json_decode($mt['admin_accounts'] ?? '', true); if (!is_array($accs)) $accs = [];
    if (empty($accs)) continue;
    // اگر کاربر قبلاً فیش داده، دیگر «در انتظار پرداخت» نیست
    $hasReceipt = (!empty($mt['payment_receipts']) && $mt['payment_receipts'] !== '[]') || !empty($mt['payment_receipt']);
    $avaPendingAccounts[] = [
        'source'        => 'transfer',
        'ref_id'        => (int)$mt['id'],
        'title'         => 'حواله ارزی',
        'code'          => $mt['tracking_code'] ?? '',
        'amount'        => (float)($mt['amount'] ?? 0),
        'currency'      => $mt['currency'] ?? '',
        'accounts'      => $accs,
        'note'          => $mt['admin_note'] ?? '',
        'date'          => $mt['approved_at'] ?? ($mt['created_at'] ?? date('Y-m-d H:i:s')),
        'needs_balance' => true,     // حواله از ولت کسر می‌شود
        'has_receipt'   => $hasReceipt,
    ];
}

// (۲) تبادل ارزی: ادمین برای طرف کاربر شماره‌حساب فرستاده (side_status = awaiting_payment)
// ستون‌های «مبلغ قابل پرداخت» را ادمین پر می‌کند؛ در دیتابیس‌های قدیمی ممکن است نباشند
$__hasPayCols = false;
$__c1 = $conn->query("SHOW COLUMNS FROM ad_deals LIKE 'buyer_payment_amount'");
if ($__c1 && $__c1->num_rows > 0) $__hasPayCols = true;
$__paySel = $__hasPayCols
    ? ", buyer_payment_amount, seller_payment_amount, buyer_payment_currency, seller_payment_currency"
    : "";

foreach (ava_safe_rows($conn,
    "SELECT id, deal_code, currency, amount, total_price,
            buyer_id, seller_id,
            buyer_admin_accounts, seller_admin_accounts,
            buyer_admin_note, seller_admin_note,
            buyer_side_status, seller_side_status,
            buyer_receipts, seller_receipts, created_at{$__paySel}
     FROM ad_deals
     WHERE (buyer_id = ? OR seller_id = ?) AND status <> 'completed'",
    'ii', [$userId, $userId]) as $dl) {
    $side  = ((int)$dl['buyer_id'] === (int)$userId) ? 'buyer' : 'seller';
    $accs  = json_decode($dl[$side.'_admin_accounts'] ?? '', true); if (!is_array($accs)) $accs = [];
    if (empty($accs)) continue;
    $st    = $dl[$side.'_side_status'] ?? 'new';
    if (!in_array($st, ['awaiting_payment'], true)) continue; // فقط منتظر پرداخت
    $rcp   = json_decode($dl[$side.'_receipts'] ?? '', true); if (!is_array($rcp)) $rcp = [];

    // مبلغی که باید پرداخت شود، همانی است که ادمین برای همین طرفِ معامله تعیین کرده؛
    // ارزش کل معامله (total_price) لزوماً همان مبلغ نیست.
    $dlPayAmount   = !empty($dl[$side.'_payment_amount'])
        ? (float)$dl[$side.'_payment_amount']
        : (float)($dl['total_price'] ?? 0);
    $dlPayCurrency = !empty($dl[$side.'_payment_currency']) ? $dl[$side.'_payment_currency'] : 'IRR';
    if ($dlPayCurrency === 'IRR') $dlPayCurrency = 'تومان';

    $avaPendingAccounts[] = [
        'source'        => 'deal',
        'ref_id'        => (int)$dl['id'],
        'title'         => 'تبادل ارزی',
        'code'          => $dl['deal_code'] ?? '',
        'amount'        => $dlPayAmount,
        'currency'      => $dlPayCurrency,
        'accounts'      => $accs,
        'note'          => $dl[$side.'_admin_note'] ?? '',
        'date'          => $dl['created_at'] ?? date('Y-m-d H:i:s'),
        'needs_balance' => false,    // تبادل ارزی نیازی به بالانس ندارد
        'has_receipt'   => !empty($rcp),
    ];
}

// (۳) شارژ حساب (Top-up): ادمین اطلاعات بانکی و مبلغ قابل پرداخت را فرستاده و کاربر هنوز فیش نداده
foreach (ava_safe_rows($conn,
    "SELECT * FROM topup_requests
     WHERE user_id = ? AND status IN ('approved','waiting_payment','payment_received')",
    'i', [$userId]) as $tp) {

    // ساخت فهرست حساب‌ها از ستون‌های جداگانه‌ی این جدول
    $tpAccs = [];
    if (!empty($tp['card_number']))    $tpAccs[] = ['name' => $tp['recipient_name'] ?? '', 'card' => $tp['card_number']];
    if (!empty($tp['account_number'])) $tpAccs[] = ['name' => trim(($tp['bank_name'] ?? '') . ' — شماره حساب'), 'card' => $tp['account_number']];
    if (!empty($tp['iban']))           $tpAccs[] = ['name' => 'شبا', 'card' => $tp['iban']];
    if (empty($tpAccs)) continue;

    // مبلغ قابل پرداخت را ادمین تعیین می‌کند؛ اگر ثبت نشده بود، مقدار درخواستی نمایش داده می‌شود
    $tpPayAmount   = !empty($tp['payment_amount'])   ? (float)$tp['payment_amount']   : (float)($tp['amount'] ?? 0);
    $tpPayCurrency = !empty($tp['payment_currency']) ? $tp['payment_currency']        : ($tp['currency'] ?? '');
    if ($tpPayCurrency === 'IRR') $tpPayCurrency = 'تومان';

    $avaPendingAccounts[] = [
        'source'        => 'topup',
        'ref_id'        => (int)$tp['id'],
        'title'         => 'شارژ حساب',
        'code'          => 'TP-' . (int)$tp['id'],
        'amount'        => $tpPayAmount,
        'currency'      => $tpPayCurrency,
        'accounts'      => $tpAccs,
        'note'          => '',
        'date'          => $tp['created_at'] ?? date('Y-m-d H:i:s'),
        'needs_balance' => false,
        'has_receipt'   => !empty($tp['receipt_image']),
    ];
}

// (۴) فیش‌ها/صورت‌حساب‌ها: ادمین از طریق «ایجاد فیش جدید» حساب فرستاده
// (unpaid_invoices، وضعیت approved) — این همان مسیر یکپارچه‌ای‌ست که هم
// فیش‌های عمومی و هم فیش‌های «تبادل ارزی» از آن رد می‌شوند؛ قبلاً این منبع
// اصلاً این‌جا دیده نمی‌شد.
foreach (ava_safe_rows($conn,
    "SELECT id, currency, amount, description, status, bank_name, account_number,
            card_number, recipient_name, iban, receipt_image, created_at
     FROM unpaid_invoices
     WHERE user_id = ? AND status = 'approved'",
    'i', [$userId]) as $iv) {
    $ivAccs = [];
    if (!empty($iv['card_number']))    $ivAccs[] = ['name' => $iv['recipient_name'] ?? '', 'card' => $iv['card_number']];
    if (!empty($iv['account_number'])) $ivAccs[] = ['name' => trim(($iv['bank_name'] ?? '') . ' — شماره حساب'), 'card' => $iv['account_number']];
    if (!empty($iv['iban']))           $ivAccs[] = ['name' => 'شبا', 'card' => $iv['iban']];
    if (empty($ivAccs)) continue;

    $ivCurrency = ($iv['currency'] === 'IRR') ? 'تومان' : $iv['currency'];
    $avaPendingAccounts[] = [
        'source'        => 'invoice',
        'ref_id'        => (int)$iv['id'],
        'title'         => 'صورتحساب',
        'code'          => 'INV-' . (int)$iv['id'],
        'amount'        => (float)($iv['amount'] ?? 0),
        'currency'      => $ivCurrency,
        'accounts'      => $ivAccs,
        'note'          => $iv['description'] ?? '',
        'date'          => $iv['created_at'] ?? date('Y-m-d H:i:s'),
        'needs_balance' => false,
        'has_receipt'   => !empty($iv['receipt_image']),
    ];
}

// مرتب‌سازی: جدیدترین اول
usort($avaPendingAccounts, function($a,$b){ return strtotime($b['date'] ?? '0') <=> strtotime($a['date'] ?? '0'); });
$avaPendingAccountsAll = $avaPendingAccounts;                     // برای آرشیو «مشاهده همه»
// آیتم‌هایی که کاربر هنوز فیش نداده = چشمک‌زن؛ اگر تعداد زیاد شد بقیه به آرشیو
$avaPendingActive   = array_values(array_filter($avaPendingAccountsAll, function($x){ return empty($x['has_receipt']); }));
$avaPendingAccounts = array_slice($avaPendingActive, 0, 4);       // نمایش در کادر
$avaHasPendingAcc   = !empty($avaPendingAccounts);

// --- آگهی‌های فعال بازار (۵ آگهی آخر که متعلق به خود کاربر نیست) برای ارسال پیشنهاد قیمت ---
$avaActiveAds = ava_safe_rows(
    $conn,
    "SELECT a.id, a.type, a.currency, a.amount, a.price_per_unit, a.created_at,
            u.first_name, u.last_name, u.avatar
     FROM user_ads a JOIN users u ON a.user_id = u.id
     WHERE a.status = 'active' AND a.user_id <> ?
     ORDER BY a.created_at DESC LIMIT 5",
    "i", [$userId]
);

// --- بنرهای تبلیغاتی ---
$avaSlides = [];
$avaSlides = ava_safe_rows($conn, "SELECT id, title, description, bg_color, icon, image_url, link FROM dashboard_slides
                       WHERE is_active = 1 ORDER BY sort_order ASC, id ASC LIMIT 6");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="dns-prefetch" href="https://cdnjs.cloudflare.com">
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="dns-prefetch" href="https://cdn.jsdelivr.net">
<script>(function(){try{var t=localStorage.getItem("ava_theme")||"dark";document.documentElement.setAttribute("data-theme",t);}catch(e){}})();</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token_arad'] ?? ''); ?>">
    <script>
    // اتصال خودکار توکن CSRF به هر درخواست POST/PUT/DELETE هم‌مبدأ
    (function(){
        var m = document.querySelector('meta[name="csrf-token"]');
        var token = m ? m.content : '';
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
    <title>Ava Pay - Dashboard</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/style.css') ?: time(); ?>">
    <link rel="stylesheet" href="/ledor/assets/css/theme-light.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/theme-light.css') ?: time(); ?>">
    <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
    <link rel="manifest" href="manifest.php">
    <?php $__appIcon = ($appLogo ? htmlspecialchars($appLogo['url']) : '/ledor/AVAPAY.PNG?v=' . (@filemtime(__DIR__ . '/AVAPAY.PNG') ?: time())); ?>
    <link rel="icon" type="image/png" href="<?php echo $__appIcon; ?>">
    <link rel="apple-touch-icon" href="<?php echo $__appIcon; ?>">
    <meta name="theme-color" content="#1A0B2E">
    <meta name="color-scheme" content="dark">
    <meta name="apple-mobile-web-app-status-bar-style" content="black">
    <link rel="stylesheet" href="/ledor/assets/css/notif.css?v=<?php echo filemtime('assets/css/notif.css'); ?>">
    <link rel="stylesheet" href="/ledor/assets/css/cards.css?v=<?php echo filemtime('assets/css/cards.css'); ?>">
    <link rel="stylesheet" href="/ledor/assets/css/friends.css?v=<?php echo filemtime('assets/css/friends.css'); ?>">
    <link rel="stylesheet" href="/ledor/assets/css/mainpage.css?v=<?php echo filemtime('assets/css/mainpage.css'); ?>">
    <link rel="stylesheet" href="/ledor/assets/css/ref.css?v=<?php echo filemtime('assets/css/ref.css'); ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@2.0.1/dist/chartjs-plugin-zoom.min.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js" defer></script>
    <link rel="stylesheet" href="assets/css/avapay-modern.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/avapay-modern.css') ?: time(); ?>">
    <link rel="stylesheet" href="assets/css/dashboard-ava.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/dashboard-ava.css') ?: time(); ?>">
    <link rel="stylesheet" href="assets/css/dashboard-enhance.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/dashboard-enhance.css') ?: time(); ?>">
    <link rel="preload" href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css"></noscript>
    <script src="assets/js/check-auth.js" defer></script>
    <script src="assets/js/upload-compress.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/upload-compress.js') ?: time(); ?>" defer></script>
    <!-- سیستم نوتیفیکیشن مشترک با صفحه تبادل ارزی (همان زنگوله و همان اعلان‌ها) -->
    <script src="assets/js/notification-system.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/notification-system.js') ?: time(); ?>" defer></script>
    <script src="assets/js/ref.js" defer></script>
    <script src="assets/js/app.js" defer></script>
    

<style>
/* پس‌زمینه‌ی متحرک/رفت‌وبرگشتی (gradientFlow از style.css مشترک) عمداً فقط برای
   داشبورد خاموش می‌شود — بقیه‌ی صفحات (login/profile/arad) دست‌نخورده می‌مانند.
   قبلاً اینجا یک گرادیانِ دو-رنگِ مستقل (#1A0B2E→#2A0D3F) روی body ست می‌شد که
   با پس‌زمینه‌ی تکرنگِ خودِ کانتینر داشبورد (--ava-bg: #0A0520) یکی نبود — روی
   صفحات عریض (کنارهای خارج از max-width:1280px) و در بازگشت کشِ لبه‌ای آی‌او‌اس
   (overscroll bounce) این دو رنگ پشت‌سرهم دیده می‌شدند. الان body دقیقاً همان
   تک‌رنگِ کانتینر را دارد تا فقط یک پس‌زمینه دیده شود، نه دو رنگ جدا. */
html:not([data-theme="light"]) body {
    background: #0A0520 !important;
    background-size: 100% 100% !important;
    animation: none !important;
}

/* ============================================
   TOP-UP SECTION — Clean Step-based Design
   ============================================ */
.topup-section { margin: 0 0 22px 0; }

.topup-panel {
    background: linear-gradient(160deg, rgba(108,64,197,0.08) 0%, rgba(26,11,46,0.6) 100%);
    border: 1px solid rgba(108,64,197,0.3);
    border-radius: 20px;
    padding: 20px;
    position: relative;
    overflow: hidden;
}
.topup-panel::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: linear-gradient(90deg, #6C40C5, #FF4D8D);
    border-radius: 20px 20px 0 0;
}

/* --- Header row --- */
.tp-header-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 18px;
}
.tp-title {
    font-size: .8rem;
    font-weight: 700;
    color: rgba(255,255,255,0.7);
    text-transform: uppercase;
    letter-spacing: .07em;
    display: flex;
    align-items: center;
    gap: 7px;
    margin: 0;
}
.tp-title i { color: #a78bfa; font-size: .9rem; }
.tp-btn-history {
    background: rgba(108,64,197,0.18);
    border: 1px solid rgba(108,64,197,0.35);
    color: #a78bfa;
    font-size: .6rem;
    font-weight: 600;
    cursor: pointer;
    padding: 4px 10px;
    border-radius: 8px;
    transition: background .2s;
    white-space: nowrap;
}
.tp-btn-history:hover { background: rgba(108,64,197,0.3); }

/* --- Stepper --- */
.tp-stepper { display: none; margin-bottom: 18px; }
.tp-stepper.visible { display: block; }
.tp-stepper-track {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    position: relative;
    padding-top: 0;
}
.tp-stepper-track::before {
    content: '';
    position: absolute;
    top: 16px; left: 16px; right: 16px;
    height: 2px;
    background: rgba(255,255,255,0.07);
    z-index: 0;
}
.tp-stepper-bar {
    position: absolute;
    top: 16px; left: 16px;
    height: 2px;
    background: linear-gradient(90deg, #6C40C5, #22d3a0);
    transition: width .5s cubic-bezier(.4,0,.2,1);
    z-index: 0;
}
.tp-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 5px;
    position: relative;
    z-index: 1;
    flex: 1;
}
.tp-step-circle {
    width: 32px; height: 32px;
    border-radius: 50%;
    background: rgba(255,255,255,0.06);
    border: 2px solid rgba(255,255,255,0.1);
    display: flex; align-items: center; justify-content: center;
    font-size: .7rem;
    color: rgba(255,255,255,0.25);
    transition: all .3s;
}
.tp-step.active .tp-step-circle {
    background: linear-gradient(135deg,#6C40C5,#8B5CF6);
    border-color: transparent;
    color: #fff;
    box-shadow: 0 0 14px rgba(108,64,197,0.55);
}
.tp-step.done .tp-step-circle {
    background: #22d3a0;
    border-color: transparent;
    color: #0a1a14;
}
.tp-step.rej .tp-step-circle {
    background: #ff4d6d;
    border-color: transparent;
    color: #fff;
}
.tp-step-lbl {
    font-size: .48rem;
    font-weight: 600;
    color: rgba(255,255,255,0.22);
    text-transform: uppercase;
    letter-spacing: .03em;
    text-align: center;
    white-space: nowrap;
}
.tp-step.active .tp-step-lbl { color: #a78bfa; }
.tp-step.done .tp-step-lbl  { color: #22d3a0; }

/* --- Status bar --- */
.tp-active-status-bar {
    display: none;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border-radius: 10px;
    padding: 9px 14px;
    margin-bottom: 16px;
    font-size: .72rem;
    font-weight: 600;
    text-align: center;
    border: 1px solid;
    transition: all .3s;
}
.tp-active-status-bar.visible { display: flex; }

/* --- Currency tabs --- */
.tp-cur-tabs {
    display: flex;
    background: rgba(0,0,0,0.25);
    border-radius: 12px;
    padding: 4px;
    gap: 3px;
    margin-bottom: 14px;
}
.tp-cur-tab {
    flex: 1;
    padding: 8px 4px;
    border: none;
    border-radius: 9px;
    background: transparent;
    color: rgba(255,255,255,0.35);
    font-size: .68rem;
    font-weight: 700;
    cursor: pointer;
    transition: all .2s;
    text-align: center;
}
.tp-cur-tab.active[data-cur="USD"]  { background: rgba(74,222,128,0.18);  color: #4ade80; }
.tp-cur-tab.active[data-cur="EUR"]  { background: rgba(56,189,248,0.18);  color: #38bdf8; }
.tp-cur-tab.active[data-cur="USDT"] { background: rgba(240,180,41,0.18);  color: #f0b429; }

/* --- Amount input --- */
.tp-amount-wrap { position: relative; margin-bottom: 12px; }
.tp-amount-wrap input {
    width: 100%;
    padding: 13px 50px 13px 14px;
    background: rgba(255,255,255,0.06);
    border: 1.5px solid rgba(108,64,197,0.3);
    border-radius: 12px;
    color: #fff;
    font-size: 1.05rem;
    font-weight: 600;
    outline: none;
    transition: border-color .2s, box-shadow .2s;
    box-sizing: border-box;
}
.tp-amount-wrap input:focus {
    border-color: #6C40C5;
    box-shadow: 0 0 0 3px rgba(108,64,197,0.15);
}
.tp-amount-wrap input::placeholder { color: rgba(255,255,255,0.2); font-weight: 400; font-size: .85rem; }
.tp-cur-badge {
    position: absolute;
    right: 14px; top: 50%;
    transform: translateY(-50%);
    font-size: .65rem;
    font-weight: 700;
    color: rgba(255,255,255,0.3);
    pointer-events: none;
}

/* --- Quick amounts --- */
.tp-quick-amts {
    display: grid;
    grid-template-columns: repeat(4,1fr);
    gap: 6px;
    margin-bottom: 14px;
}
.tp-quick-amt {
    padding: 7px 4px;
    border: 1.5px solid rgba(108,64,197,0.2);
    border-radius: 9px;
    background: rgba(108,64,197,0.06);
    color: rgba(255,255,255,0.45);
    font-size: .68rem;
    font-weight: 600;
    cursor: pointer;
    text-align: center;
    transition: all .15s;
}
.tp-quick-amt:hover, .tp-quick-amt.sel {
    border-color: #6C40C5;
    color: #c4b5fd;
    background: rgba(108,64,197,0.18);
}

/* --- Submit button --- */
.tp-btn-submit {
    width: 100%;
    padding: 12px;
    background: linear-gradient(135deg, #6C40C5, #FF4D8D);
    border: none;
    border-radius: 12px;
    color: #fff;
    font-size: .82rem;
    font-weight: 700;
    cursor: pointer;
    transition: opacity .2s, transform .1s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    letter-spacing: .02em;
}
.tp-btn-submit:active { transform: scale(.97); opacity: .9; }
.tp-btn-submit:disabled { opacity: .6; cursor: not-allowed; }

/* --- Step content containers --- */
.tp-step-body {
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.07);
    border-radius: 14px;
    padding: 18px;
    text-align: center;
}

/* pending / waiting state */
.tp-state-icon {
    font-size: 2.2rem;
    margin-bottom: 10px;
    display: block;
}
.tp-state-title {
    font-size: .85rem;
    font-weight: 700;
    margin-bottom: 4px;
    display: block;
}
.tp-state-sub {
    font-size: .7rem;
    color: rgba(255,255,255,0.4);
    line-height: 1.5;
    display: block;
}

/* --- Payment info grid (step 3) --- */
.tp-payment-header {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: .72rem;
    font-weight: 700;
    color: #38bdf8;
    margin-bottom: 10px;
}
.tp-payment-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    margin-bottom: 14px;
}
.tp-payment-item {
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.07);
    border-radius: 10px;
    padding: 9px 11px;
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.tp-payment-item.full-width { grid-column: 1 / -1; }
.tp-payment-item .label {
    font-size: .54rem;
    color: rgba(255,255,255,0.3);
    text-transform: uppercase;
    letter-spacing: .05em;
}
.tp-payment-item .value {
    font-size: .82rem;
    font-weight: 700;
    color: #fff;
    word-break: break-all;
}
.tp-payment-item .value.highlight { color: #a78bfa; }
.tp-payment-item.full-width .value { color: #4ade80; font-size: .9rem; }
.tp-payment-item .copy-btn {
    position: absolute;
    top: 8px;
    right: 8px;
    background: rgba(167,139,250,0.15);
    border: 1px solid rgba(167,139,250,0.3);
    border-radius: 6px;
    color: #a78bfa;
    font-size: .65rem;
    padding: 2px 7px;
    cursor: pointer;
    opacity: 0;
    transition: opacity .2s, background .2s;
    white-space: nowrap;
    line-height: 1.6;
}
.tp-payment-item:hover .copy-btn { opacity: 1; }
.tp-payment-item .copy-btn:active, .tp-payment-item .copy-btn.copied {
    background: rgba(74,222,128,0.2);
    border-color: #4ade80;
    color: #4ade80;
}
.tp-payment-item { position: relative; }

/* --- Upload area --- */
.tp-upload-area {
    border: 1.5px dashed rgba(108,64,197,0.35);
    border-radius: 12px;
    padding: 16px 12px;
    text-align: center;
    cursor: pointer;
    transition: all .2s;
    background: rgba(108,64,197,0.04);
}
.tp-upload-area:hover {
    border-color: #6C40C5;
    background: rgba(108,64,197,0.1);
}
.tp-upload-area i { font-size: 1.4rem; color: #FF4D8D; display: block; margin-bottom: 5px; }
.tp-upload-area span { font-size: .68rem; color: rgba(255,255,255,0.4); }
.tp-upload-area input { display: none; }
.tp-receipt-preview {
    width: 100%;
    max-height: 120px;
    object-fit: cover;
    border-radius: 10px;
    margin-top: 10px;
    display: none;
    border: 1px solid rgba(255,255,255,0.1);
}
.tp-btn-send {
    width: 100%;
    padding: 11px;
    background: linear-gradient(135deg, #22d3a0, #38bdf8);
    border: none;
    border-radius: 12px;
    color: #0a1a14;
    font-size: .78rem;
    font-weight: 700;
    cursor: pointer;
    margin-top: 10px;
    display: none;
    transition: opacity .2s, transform .1s;
}
.tp-btn-send:active { transform: scale(.97); }

/* --- Reject box --- */
.tp-reject-box {
    display: none;
    background: rgba(255,77,109,0.08);
    border: 1px solid rgba(255,77,109,0.35);
    border-radius: 14px;
    padding: 16px;
    text-align: center;
}
.tp-reject-box i { font-size: 1.8rem; color: #ff4d6d; display: block; margin-bottom: 8px; }
.tp-reject-box .rej-title { font-size: .82rem; font-weight: 700; color: #ff4d6d; display: block; margin-bottom: 4px; }
.tp-reject-box .rej-reason { font-size: .72rem; color: rgba(255,255,255,0.5); display: block; margin-bottom: 12px; }
.tp-btn-retry {
    background: rgba(255,77,109,0.15);
    border: 1px solid rgba(255,77,109,0.4);
    border-radius: 8px;
    color: #ff4d6d;
    padding: 6px 16px;
    font-size: .7rem;
    font-weight: 600;
    cursor: pointer;
    transition: background .2s;
}
.tp-btn-retry:hover { background: rgba(255,77,109,0.25); }

/* --- Done box --- */
.tp-done-box {
    display: none;
    background: rgba(34,211,160,0.06);
    border: 1px solid rgba(34,211,160,0.25);
    border-radius: 14px;
    padding: 20px;
    text-align: center;
}
.tp-done-box i { font-size: 2.4rem; color: #22d3a0; display: block; margin-bottom: 8px; }
.tp-done-box .done-title { font-size: .88rem; font-weight: 700; color: #22d3a0; display: block; margin-bottom: 4px; }
.tp-done-box .done-sub { font-size: .7rem; color: rgba(255,255,255,0.4); display: block; margin-bottom: 14px; }
.tp-btn-new {
    width: 100%;
    padding: 10px;
    background: rgba(34,211,160,0.15);
    border: 1px solid rgba(34,211,160,0.35);
    border-radius: 10px;
    color: #22d3a0;
    font-size: .75rem;
    font-weight: 700;
    cursor: pointer;
    transition: background .2s;
}
.tp-btn-new:hover { background: rgba(34,211,160,0.25); }

/* receipt thumb in waiting step */
.tp-receipt-thumb {
    width: 100%;
    max-height: 100px;
    object-fit: cover;
    border-radius: 10px;
    margin-top: 10px;
    border: 1px solid rgba(255,255,255,0.08);
    display: none;
}

/* Wallet — redesigned premium layout */
.wallet-display-section { margin: 0 0 22px 0; }
.wallet-header { display: flex; align-items: center; gap: 8px; margin-bottom: 14px; }
.wallet-header i { color: #FF4D8D; font-size: .9rem; }
.wallet-header span { font-size: .78rem; font-weight: 700; color: rgba(255,255,255,0.6); text-transform: uppercase; letter-spacing: .06em; flex: 1; }
.wallet-header .refresh-btn { background: rgba(255,255,255,0.05); border: none; color: rgba(255,255,255,0.4); width: 32px; height: 32px; border-radius: 50%; cursor: pointer; font-size: .85rem; }

/* کارت اصلی (Hero) */
.wallet-hero {
    position: relative;
    border-radius: 22px;
    padding: 20px 20px 18px;
    background:
        radial-gradient(120% 140% at 0% 0%, rgba(108,64,197,0.55), transparent 60%),
        radial-gradient(120% 140% at 100% 100%, rgba(255,77,141,0.45), transparent 55%),
        linear-gradient(135deg, #241041, #12061f);
    border: 1px solid rgba(255,255,255,0.10);
    overflow: hidden;
    box-shadow: 0 14px 40px rgba(23,10,45,0.55);
    margin-bottom: 10px;
}
.wallet-hero::after {
    content: '';
    position: absolute; top: -40%; right: -20%;
    width: 220px; height: 220px; border-radius: 50%;
    background: rgba(255,255,255,0.05); filter: blur(10px);
    pointer-events: none;
}
.wallet-hero-top { display: flex; align-items: center; justify-content: space-between; position: relative; z-index: 1; }
.wallet-hero-label { font-size: .62rem; font-weight: 700; letter-spacing: .12em; color: rgba(255,255,255,0.55); text-transform: uppercase; }
.wallet-hero-chip {
    display: inline-flex; align-items: center; gap: 5px;
    background: rgba(255,255,255,0.10); border: 1px solid rgba(255,255,255,0.14);
    padding: 4px 10px; border-radius: 20px; font-size: .6rem; font-weight: 700; color: #fff;
}
.wallet-hero-chip .dot { width: 6px; height: 6px; border-radius: 50%; background: #4ade80; box-shadow: 0 0 8px #4ade80; }
.wallet-hero-amount {
    position: relative; z-index: 1;
    font-size: 2rem; font-weight: 800; color: #fff; margin: 14px 0 2px;
    letter-spacing: -.02em; display: flex; align-items: baseline; gap: 8px;
}
.wallet-hero-amount .cur-tag { font-size: .8rem; font-weight: 700; color: rgba(255,255,255,0.5); }
.wallet-hero-eye { background: none; border: none; color: rgba(255,255,255,0.45); cursor: pointer; font-size: .85rem; margin-left: 4px; }
.wallet-hero-switch { position: relative; z-index: 1; display: flex; gap: 6px; margin-top: 14px; flex-wrap: wrap; }
.wallet-hero-switch button {
    background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.10);
    color: rgba(255,255,255,0.6); font-size: .62rem; font-weight: 700;
    padding: 5px 12px; border-radius: 20px; cursor: pointer; transition: all .2s;
}
.wallet-hero-switch button.active { background: #fff; color: #1a0b2e; border-color: #fff; }
.wallet-card.selected { border-color: rgba(255,255,255,0.35); background: rgba(255,255,255,0.07); }
.wallet-icon.usd { background: rgba(74,222,128,0.15); color: #4ade80; }
.wallet-icon.eur { background: rgba(56,189,248,0.15); color: #38bdf8; }
.wallet-icon.usdt { background: rgba(240,180,41,0.15); color: #f0b429; }
.wallet-icon.irr { background: rgba(255,255,255,0.1); color: rgba(255,255,255,0.6); }
.wallet-info .cur { font-size: .58rem; font-weight: 700; color: rgba(255,255,255,0.35); text-transform: uppercase; letter-spacing: .05em; }
.wallet-info .bal { font-size: .98rem; font-weight: 800; color: #fff; margin-top: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.wallet-info .bal.usd { color: #4ade80; }
.wallet-info .bal.eur { color: #38bdf8; }
.wallet-info .bal.usdt { color: #f0b429; }
.wallet-info .bal.irr { color: rgba(255,255,255,0.85); }
.news-lang-tabs { display: flex; gap: 6px; background: rgba(0,0,0,0.2); padding: 3px; border-radius: 20px; }
.news-lang-tab {
    background: transparent; border: none; color: rgba(255,255,255,0.55);
    font-size: .72rem; font-weight: 700; padding: 6px 16px; border-radius: 20px; cursor: pointer; transition: all .2s;
}
.news-lang-tab.active { background: linear-gradient(135deg, #38bdf8, #6C40C5); color: #fff; box-shadow: 0 3px 10px rgba(56,189,248,0.3); }
.news-list { display: flex; flex-direction: column; gap: 8px; }
.news-item {
    display: flex; align-items: center; gap: 12px; padding: 12px;
    background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.07);
    border-radius: 14px; cursor: pointer; transition: transform .15s, border-color .2s;
}
.news-item:hover { border-color: rgba(56,189,248,0.4); transform: translateY(-1px); }
.news-item-thumb {
    width: 48px; height: 48px; border-radius: 12px; flex-shrink: 0; object-fit: cover;
    background: rgba(56,189,248,0.12); display: flex; align-items: center; justify-content: center; color: #38bdf8;
}
.news-item-body { flex: 1; min-width: 0; }
.news-item-title { font-size: .88rem; font-weight: 700; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.news-item-date { font-size: .62rem; color: rgba(255,255,255,0.4); margin-top: 3px; }
.news-item-arrow { color: rgba(255,255,255,0.3); font-size: .8rem; flex-shrink: 0; }
.news-empty { text-align: center; padding: 24px 0; color: rgba(255,255,255,0.25); font-size: .78rem; }
.news-empty i { font-size: 1.4rem; display: block; margin-bottom: 8px; }
/* News modal */
.news-modal-overlay {
    position: fixed; inset: 0; z-index: 100050; background: rgba(0,0,0,0.7); backdrop-filter: blur(6px);
    display: none; align-items: flex-end; justify-content: center;
}
.news-modal-overlay.open { display: flex; }
.news-modal-content {
    background: linear-gradient(180deg, #1e0f38, #150a28); width: 100%; max-width: 480px;
    border-radius: 24px 24px 0 0; padding: 0 0 calc(20px + env(safe-area-inset-bottom));
    max-height: 90vh; overflow-y: auto; border: 1px solid rgba(255,255,255,0.08); position: relative;
}
.news-modal-close {
    position: absolute; top: 14px; left: 14px; z-index: 2; width: 38px; height: 38px; border-radius: 50%;
    background: rgba(0,0,0,0.4); border: none; color: #fff; font-size: 1rem; cursor: pointer;
}
.news-modal-img { width: 100%; max-height: 240px; object-fit: cover; display: block; }
.news-modal-pad { padding: 20px; }
.news-modal-title { font-size: 1.2rem; font-weight: 800; color: #fff; margin-bottom: 8px; line-height: 1.5; }
.news-modal-date { font-size: .72rem; color: rgba(255,255,255,0.4); margin-bottom: 16px; }
.news-modal-body { font-size: .92rem; line-height: 2; color: rgba(255,255,255,0.85); white-space: pre-wrap; }

.invoice-section { margin: 0 0 22px 0; direction: rtl; }
.invoice-panel { background: rgba(255,255,255,0.04); border: 1px solid rgba(255,77,141,0.25); border-radius: 20px; padding: 16px; position: relative; overflow: hidden; }
.invoice-panel::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, #FF4D8D, #f0b429); border-radius: 20px 20px 0 0; }
.invoice-title { font-size: .72rem; font-weight: 700; color: rgba(255,255,255,0.5); text-transform: uppercase; letter-spacing: .06em; display: flex; align-items: center; gap: 6px; margin-bottom: 12px; }
.invoice-title i { color: #FF4D8D; }

/* ===== دو ستونه: فیش‌های پرداخت‌نشده | تاریخچه ===== */
.invoice-columns {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    align-items: start;
}
.invoice-col { min-width: 0; display: flex; flex-direction: column; gap: 6px; }
.invoice-col-head {
    font-size: .6rem; font-weight: 800; text-transform: uppercase; letter-spacing: .05em;
    display: flex; align-items: center; gap: 5px; margin-bottom: 4px; padding-bottom: 6px;
    border-bottom: 1px solid rgba(255,255,255,0.08);
}
.invoice-col-head.unpaid { color: #ff8fb3; }
.invoice-col-head.history { color: rgba(255,255,255,0.45); }
.invoice-col-head i { font-size: .7rem; }
.invoice-col-more {
    text-align: center; font-size: .58rem; color: rgba(167,139,250,0.9);
    padding: 6px; cursor: pointer; border-radius: 8px; background: rgba(108,64,197,0.08);
    border: 1px solid rgba(108,64,197,0.2); transition: background .2s;
}
.invoice-col-more:hover { background: rgba(108,64,197,0.18); }

.invoice-list { display: flex; flex-direction: column; gap: 6px; }
.invoice-amount.USD { color: #4ade80; }
.invoice-amount.EUR { color: #38bdf8; }
.invoice-amount.USDT { color: #f0b429; }
.invoice-amount.IRR { color: rgba(255,255,255,0.7); }
.invoice-empty { text-align: center; padding: 20px 0; color: rgba(255,255,255,0.2); font-size: .7rem; }
.invoice-empty i { font-size: 1.4rem; display: block; margin-bottom: 6px; }
.invoice-col .invoice-empty { padding: 14px 4px; font-size: .62rem; }
.invoice-col .invoice-empty i { font-size: 1.1rem; margin-bottom: 4px; }
.inv-badge { font-size: .55rem; font-weight: 700; padding: 2px 8px; border-radius: 20px; white-space: nowrap; }
.inv-badge.pending    { background:rgba(245,158,11,.15); color:#f59e0b; }
.inv-badge.approved   { background:rgba(56,189,248,.15); color:#38bdf8; }
.inv-badge.paid       { background:rgba(108,64,197,.15); color:#a78bfa; }
.inv-badge.finalized  { background:rgba(34,211,160,.15); color:#22d3a0; }
.inv-badge.rejected   { background:rgba(255,77,109,.15); color:#ff4d6d; }

/* کارت جمع‌وجور برای ستون‌ها (مخصوص موبایل) */
.inv-cell {
    background: rgba(255,255,255,0.04); border-radius: 12px; padding: 10px;
    cursor: pointer; border: 1px solid rgba(255,255,255,0.07);
    transition: border-color .2s, transform .15s; position: relative; overflow: hidden;
}
.inv-cell:hover { border-color: rgba(108,64,197,0.45); transform: translateY(-1px); }

/* ===== فیش‌های پرداخت‌نشده: استایل متمایز و برجسته ===== */
.inv-cell.open {
    background:
        linear-gradient(135deg, rgba(255,77,141,0.16), rgba(240,180,41,0.08)),
        rgba(255,255,255,0.02);
    border: 1px solid rgba(255,77,141,0.45);
    box-shadow: 0 6px 18px rgba(255,77,141,0.12), inset 0 0 0 1px rgba(255,255,255,0.03);
    animation: invPulse 2.6s ease-in-out infinite;
}
.inv-cell.open::before {
    content: '';
    position: absolute; top: 0; left: 0; bottom: 0; width: 4px;
    background: linear-gradient(180deg, #FF4D8D, #f0b429);
}
.inv-cell.open::after {
    content: '\f0f6';
    font-family: 'Font Awesome 6 Free'; font-weight: 900;
    position: absolute; top: 8px; left: 8px;
    font-size: 1.6rem; color: rgba(255,77,141,0.10);
}
@keyframes invPulse {
    0%,100% { box-shadow: 0 6px 18px rgba(255,77,141,0.12), inset 0 0 0 1px rgba(255,255,255,0.03); }
    50%     { box-shadow: 0 6px 24px rgba(255,77,141,0.28), inset 0 0 0 1px rgba(255,255,255,0.05); }
}
.inv-cell-amount { font-size: .95rem; font-weight: 800; line-height: 1.1; position: relative; z-index: 1; }
.inv-cell-amount.USD { color: #4ade80; }
.inv-cell-amount.EUR { color: #38bdf8; }
.inv-cell-amount.USDT { color: #f0b429; }
.inv-cell-amount.IRR { color: #fff; }
.inv-cell-desc { font-size: .58rem; color: rgba(255,255,255,0.45); margin-top: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; position: relative; z-index: 1; }
.inv-cell-foot { display: flex; align-items: center; justify-content: space-between; margin-top: 8px; gap: 4px; position: relative; z-index: 1; }

/* دکمه پرداخت داخل فیش پرداخت‌نشده */
.inv-pay-hint {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: .55rem; font-weight: 800; color: #fff;
    background: linear-gradient(135deg, #FF4D8D, #FF6B35);
    padding: 3px 9px; border-radius: 20px;
    box-shadow: 0 3px 8px rgba(255,77,141,0.35);
}

/* در موبایل ستون‌ها باریک‌ترند ولی همچنان کنار هم می‌مانند */
@media (max-width: 480px) {
    .invoice-columns { gap: 8px; }
    .invoice-panel { padding: 12px; }
    .inv-cell { padding: 8px; }
    .inv-cell-amount { font-size: .82rem; }
    .inv-cell-desc { font-size: .54rem; }
    .invoice-col-head { font-size: .55rem; }
}
@keyframes invPulse { 0%,100%{opacity:1;} 50%{opacity:.4;} }
.inv-feat-amount.USD { color: #4ade80; }
.inv-feat-amount.EUR { color: #38bdf8; }
.inv-feat-amount.USDT { color: #f0b429; }
.inv-feat-amount.IRR { color: #fff; }

/* Quick Actions */
.quick-actions-section { margin: 0 0 22px 0; }
.quick-actions-header { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
.quick-actions-header i { color: #FF4D8D; font-size: .9rem; }
.quick-actions-header span { font-size: .78rem; font-weight: 700; color: rgba(255,255,255,0.6); text-transform: uppercase; letter-spacing: .06em; }
.quick-actions-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 18px 10px; background: rgba(255,255,255,.03); border-radius: 24px; padding: 20px 16px; border: 1px solid rgba(255,255,255,.07); }

/* Header */
.header { display: flex; justify-content: space-between; align-items: center; padding: 16px 0; border-bottom: 1px solid rgba(255,255,255,0.05); margin-bottom: 18px; }
.greeting h1 { font-size: 1.2rem; font-weight: 700; margin: 0; }
.greeting p { font-size: .7rem; color: rgba(255,255,255,0.4); margin: 2px 0 0; }
.avatar { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2px solid rgba(108,64,197,0.3); flex-shrink: 0; }
/* Notification bell */
.notification-bell { cursor: pointer; position: relative; }
.notification-bell .bell-icon { width: 36px; height: 36px; border-radius: 50%; background: rgba(255,255,255,0.05); display: flex; align-items: center; justify-content: center; color: rgba(255,255,255,0.7); font-size: 1rem; position: relative; transition: background .2s; }
.notification-bell:hover .bell-icon { background: rgba(255,255,255,0.12); }
.bell-dot { position: absolute; top: 8px; right: 9px; width: 8px; height: 8px; background: #FF3B30; border-radius: 50%; border: 2px solid #1a0b2e; }
.notification-bell .notification-badge { position: absolute; top: -4px; right: -4px; background: #FF3B30; color: #fff; font-size: .58rem; font-weight: 700; min-width: 16px; height: 16px; padding: 0 4px; border-radius: 10px; display: flex; align-items: center; justify-content: center; }
.notification-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.3); z-index: 8999; display: none; }
.notification-overlay.show { display: block; }
.notification-badge { position: absolute; top: -2px; right: -2px; background: #FF3B30; color: #fff; font-size: .55rem; font-weight: 700; min-width: 18px; height: 18px; border-radius: 50%; display: flex; align-items: center; justify-content: center; padding: 0 4px; }
@keyframes crownGlow { 0%{text-shadow:0 0 5px rgba(255,215,0,.3);} 100%{text-shadow:0 0 20px rgba(255,215,0,.8);} }

/* SLIDER */
.dashboard-slider-section { margin:18px 0 0; border-radius:24px; overflow:hidden; position:relative; }
.slider-wrapper { border-radius:24px; overflow:hidden; }
.slider-track { display:flex; transition:transform .5s cubic-bezier(.4,0,.2,1); will-change:transform; }
.slider-slide { flex:0 0 100%; min-height:180px; padding:30px 24px; display:flex; flex-direction:column; justify-content:flex-end; cursor:pointer; position:relative; overflow:hidden; }
.slider-slide::before { content:''; position:absolute; top:-40px; right:-40px; width:220px; height:220px; border-radius:50%; background:rgba(255,255,255,.07); }
.slider-slide::after { content:''; position:absolute; bottom:-60px; left:-30px; width:180px; height:180px; border-radius:50%; background:rgba(255,255,255,.05); }
.slider-slide-icon { font-size:2.2rem; margin-bottom:12px; color:rgba(255,255,255,.9); position:relative; z-index:1; }
.slider-slide-title { font-size:1.15rem; font-weight:700; color:#fff; margin-bottom:5px; position:relative; z-index:1; }
.slider-slide-desc { font-size:.82rem; color:rgba(255,255,255,.75); line-height:1.5; position:relative; z-index:1; }
.slider-dots { display:flex; justify-content:center; gap:6px; padding:10px 0 4px; }
.slider-dot { width:7px; height:7px; border-radius:50%; background:rgba(255,255,255,.25); cursor:pointer; transition:all .35s; border:none; }
.slider-dot.active { background:#fff; width:22px; border-radius:4px; }

.qa-item { display: flex; flex-direction: column; align-items: center; gap: 8px; cursor: pointer; transition: transform .2s; }
.qa-item:active { transform: scale(.92); }
.qa-circle { width: 58px; height: 58px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; color: #fff; box-shadow: 0 4px 16px rgba(0,0,0,.3); transition: box-shadow .2s, transform .2s; }

/* TP MODALS */
.tp-modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(6px); z-index: 99999; display: flex; align-items: center; justify-content: center; pointer-events: none; opacity: 0; transition: opacity .3s; padding: 16px; }
.tp-modal-overlay.open { pointer-events: all; opacity: 1; }
.tp-modal-sheet { background: linear-gradient(135deg,#1A0B2E,#2A0D3F); border: 1px solid rgba(255,255,255,0.1); border-radius: 24px; padding: 20px 20px 28px; width: 100%; max-width: 480px; max-height: 88vh; overflow-y: auto; transform: scale(0.92) translateY(20px); transition: transform .35s cubic-bezier(.4,0,.2,1); }
.tp-modal-overlay.open .tp-modal-sheet { transform: scale(1) translateY(0); }
.tp-modal-handle { width: 40px; height: 4px; background: rgba(255,255,255,0.2); border-radius: 4px; margin: 0 auto 16px; }
.tp-modal-close { position: absolute; top: 16px; right: 20px; background: none; border: none; color: #fff; font-size: 1.3rem; cursor: pointer; }
.tp-modal-title { font-size: 1rem; font-weight: 700; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }

.tp-req-item { background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.07); border-radius: 12px; padding: 12px 14px; margin-bottom: 8px; display: flex; align-items: center; justify-content: space-between; cursor: pointer; }
.tp-req-amount { font-size: .9rem; font-weight: 700; }
.tp-req-amount.USD { color: #4ade80; }
.tp-req-amount.EUR { color: #38bdf8; }
.tp-req-amount.USDT { color: #f0b429; }
.tp-req-date { font-size: .6rem; color: rgba(255,255,255,0.3); margin-top: 2px; }
.tp-badge { font-size: .62rem; font-weight: 700; padding: 3px 10px; border-radius: 20px; }
.tp-badge.pending { background:rgba(245,158,11,0.15); color:#f59e0b; }
.tp-badge.approved { background:rgba(56,189,248,0.15); color:#38bdf8; }
.tp-badge.waiting_payment { background:rgba(108,64,197,0.15); color:#a78bfa; }
.tp-badge.payment_received { background:rgba(108,64,197,0.2); color:#c4b5fd; }
.tp-badge.completed, .tp-badge.finalized { background:rgba(34,211,160,0.15); color:#22d3a0; }
.tp-badge.rejected { background:rgba(255,77,109,0.15); color:#ff4d6d; }
.notification-actions { display: flex; gap: 4px; }
.notification-item { padding: 10px 12px; border-radius: 10px; margin-bottom: 4px; cursor: pointer; }
.notification-item.unread { background: rgba(108,64,197,0.08); border-right: 3px solid #6C40C5; }
.notification-item .title { font-size: .75rem; font-weight: 600; margin-bottom: 2px; }
.notification-item .msg { font-size: .7rem; color: rgba(255,255,255,0.5); }
.notification-item .time { font-size: .55rem; color: rgba(255,255,255,0.25); margin-top: 4px; }
.notification-footer { text-align: center; padding-top: 8px; border-top: 1px solid rgba(255,255,255,0.05); margin-top: 8px; }

/* Currency Rate */
#currencyRateSection { display: none; position: fixed; inset: 0; background: #1A0B2E; z-index: 9999; flex-direction: column; }
#currencyRateSection.visible { display: flex; }
.currency-rate-header { display: flex; align-items: center; padding: 16px 20px; background: rgba(255,255,255,0.03); border-bottom: 1px solid rgba(255,255,255,0.05); gap: 12px; }
.back-button { background: rgba(255,255,255,0.05); border: none; color: rgba(255,255,255,0.5); width: 36px; height: 36px; border-radius: 50%; cursor: pointer; font-size: 1rem; display: flex; align-items: center; justify-content: center; }
.currency-rate-title { font-size: 1rem; font-weight: 700; flex: 1; display: flex; align-items: center; gap: 8px; }
.refresh-btn { background: rgba(255,255,255,0.05); border: none; color: rgba(255,255,255,0.4); width: 36px; height: 36px; border-radius: 50%; cursor: pointer; font-size: .9rem; }
/* استایل بخش نرخ بازار در includes/footer_menu.php است */
.dashboard.hidden { display: none; }
.modal-overlay .close-modal { background: none; border: none; color: rgba(255,255,255,0.4); font-size: 1.4rem; cursor: pointer; }

/* Quick Action Balance Warning */
.qa-balance-warning {
    background: rgba(255,77,109,0.1);
    border: 1px solid rgba(255,77,109,0.3);
    border-radius: 10px;
    padding: 10px 12px;
    font-size: .72rem;
    color: #ff4d6d;
    margin-bottom: 10px;
    display: none;
}
.qa-balance-info {
    background: rgba(108,64,197,0.1);
    border: 1px solid rgba(108,64,197,0.3);
    border-radius: 10px;
    padding: 10px 12px;
    font-size: .72rem;
    color: #a78bfa;
    margin-bottom: 10px;
    display: none;
}
.update-notification.visible { display: flex; }

/* ===== صورت‌حساب‌ها: تب‌های سوییچ‌شونده ===== */
.ava-inv-switch{ display:flex; gap:8px; margin-bottom:12px; }
.ava-inv-tab{
    flex:1; min-height:44px; box-sizing:border-box;
    display:flex; align-items:center; justify-content:center; gap:6px;
    background:var(--ava-card2, rgba(255,255,255,0.06)); border:1.5px solid var(--ava-line, rgba(255,255,255,0.14));
    color:var(--ava-mut, rgba(255,255,255,0.65)); border-radius:12px; padding:10px 10px;
    font-family:inherit; font-size:.8rem; line-height:1.3; font-weight:800; cursor:pointer; transition:all .2s;
}
.ava-inv-tab i{ font-size:.78rem; }
.ava-inv-tab.active{
    background:linear-gradient(135deg, var(--ava-pur, #7C3AED), var(--ava-pur2, #A855F7));
    border-color:transparent; color:#fff;
    box-shadow:0 6px 16px rgba(124,58,237,.4);
}
.ava-inv-count{
    background:#FF4D8D; color:#fff; font-size:.6rem; font-weight:800;
    min-width:17px; height:17px; border-radius:9px; padding:0 5px;
    display:inline-flex; align-items:center; justify-content:center;
}
.ava-inv-pane{ display:flex; flex-direction:column; gap:8px; }
.ava-inv-pane .ava-inv-item{
    display:flex; align-items:center; justify-content:space-between;
    gap:8px; margin-bottom:0; flex-wrap:wrap; min-width:0;
}
.ava-inv-pane .ava-inv-amt{ flex:0 0 auto; }
.ava-inv-pane .ava-inv-meta{
    flex:1 1 70px; min-width:0; margin:0 8px;
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
}
.ava-inv-pane .ava-badge{ flex:0 0 auto; }
/* دکمه‌ی «پرداخت کنید» در چیدمان ردیفی، margin-top نسخه‌ی ستونی را نباید نگه دارد؛
   همان مقدار باعث می‌شد دکمه از کادر بیرون بزند. اگر جا کم بود، به سطر بعدِ همان
   کارت می‌رود و تمام‌عرض می‌شود. */
.ava-inv-pane .ava-inv-item .ava-inv-pay{
    flex:0 0 auto; margin-top:0; white-space:nowrap; max-width:100%;
    justify-content:center;
}
@media (max-width:420px){
    .ava-inv-pane .ava-inv-item .ava-inv-pay{ flex:1 1 100%; margin-top:6px; }
}
/* برجسته‌سازی فیش دیده‌نشده */
.ava-receipt-new{ border-color:rgba(255,90,110,.55)!important; box-shadow:0 0 0 1px rgba(255,90,110,.25) inset; }

/* ===== فیش دریافتی: چند فیش + آرشیو ===== */
.ava-rec-count{
    display:inline-block; background:rgba(56,189,248,.18); color:#38BDF8; font-size:.52rem; font-weight:800;
    padding:1px 7px; border-radius:20px; margin:0 4px; vertical-align:middle;
}
.ava-rec-thumbs{
    display:flex; gap:8px; padding:10px 14px; overflow-x:auto; justify-content:center; flex-wrap:wrap;
    background:rgba(0,0,0,.15);
}
.ava-rec-thumb{
    width:56px; height:56px; border-radius:10px; overflow:hidden; border:2px solid transparent;
    background:var(--ava-card2, rgba(255,255,255,0.06)); cursor:pointer; padding:0; flex:none;
    display:flex; align-items:center; justify-content:center; color:var(--ava-mut, #999);
}
.ava-rec-thumb img{ width:100%; height:100%; object-fit:cover; }
.ava-rec-thumb.active{ border-color:var(--ava-pur2, #A855F7); }
.ava-arch-list{ display:flex; flex-direction:column; gap:8px; width:100%; max-width:560px; margin:0 auto; }
.ava-arch-item{
    display:flex; align-items:center; gap:10px; cursor:pointer;
    background:var(--ava-card2, rgba(255,255,255,0.04)); border:1px solid var(--ava-line, rgba(255,255,255,0.08));
    border-radius:12px; padding:11px 13px; transition:border-color .2s;
}
.ava-arch-item:hover{ border-color:var(--ava-pur2, #A855F7); }


/* ===== (آپدیت ۶) کارت موجودی چرخشی + مینی‌نمودار + مدال همه‌ی ارزها ===== */
.ava-wal-rot{ min-height:198px; }
.ava-hero-stage{ position:relative; z-index:2; min-height:104px; padding-bottom:36px; }
.ava-hero-cur{ display:flex; align-items:center; gap:7px; margin-top:12px; }
.ava-hero-flag{
    width:22px; height:22px; border-radius:50%; overflow:hidden; flex:0 0 22px;
    display:inline-flex; align-items:center; justify-content:center;
    background:rgba(255,255,255,.10); font-size:.7rem;
}
.ava-hero-flag img{ width:100%; height:100%; object-fit:cover; }
.ava-hero-name{ font-size:.72rem; font-weight:800; color:rgba(255,255,255,.85); letter-spacing:.04em; }
.ava-hero-chg{
    display:inline-flex; align-items:center; gap:4px; font-size:.6rem; font-weight:800;
    padding:3px 8px; border-radius:20px; direction:ltr;
}
.ava-hero-chg.up{ color:#22C55E; background:rgba(34,197,94,.14); border:1px solid rgba(34,197,94,.32); }
.ava-hero-chg.dn{ color:#FF5A6E; background:rgba(255,90,110,.14); border:1px solid rgba(255,90,110,.32); }
.ava-hero-amt{ direction:ltr; text-align:right; }
.ava-hero-eq{ font-size:.62rem; color:rgba(255,255,255,.45); margin-top:4px; font-weight:700; }
.ava-hero-spark{
    position:absolute; left:0; right:0; bottom:0; width:100%; height:38px;
    opacity:.95; pointer-events:none;
}
/* انیمیشن ورود/خروج مقدار */
.ava-hero-stage.swap-out .ava-hero-amt,
.ava-hero-stage.swap-out .ava-hero-cur,
.ava-hero-stage.swap-out .ava-wal-unit,
.ava-hero-stage.swap-out .ava-hero-eq{
    animation:avaHeroOut .28s cubic-bezier(.4,0,1,1) forwards;
}
.ava-hero-stage.swap-in .ava-hero-amt,
.ava-hero-stage.swap-in .ava-hero-cur,
.ava-hero-stage.swap-in .ava-wal-unit,
.ava-hero-stage.swap-in .ava-hero-eq{
    animation:avaHeroIn .46s cubic-bezier(.16,1,.3,1) both;
}
.ava-hero-stage.swap-in .ava-hero-cur{ animation-delay:.02s; }
.ava-hero-stage.swap-in .ava-wal-unit{ animation-delay:.06s; }
.ava-hero-stage.swap-in .ava-hero-eq{ animation-delay:.1s; }
@keyframes avaHeroOut{
    0%{ opacity:1; transform:translateY(0) rotateX(0) scale(1); filter:blur(0); }
    100%{ opacity:0; transform:translateY(-22px) rotateX(38deg) scale(.94); filter:blur(4px); }
}
@keyframes avaHeroIn{
    0%{ opacity:0; transform:translateY(26px) rotateX(-40deg) scale(.92); filter:blur(5px); }
    60%{ filter:blur(0); }
    100%{ opacity:1; transform:translateY(0) rotateX(0) scale(1); filter:blur(0); }
}
/* نقاط شاخص */
.ava-hero-dots{ display:flex; gap:5px; margin-top:14px; position:relative; z-index:2; }
.ava-hero-dot{
    width:7px; height:7px; border-radius:50%; cursor:pointer; border:0; padding:0;
    background:rgba(255,255,255,.22); transition:all .3s;
}
.ava-hero-dot.on{ width:22px; border-radius:20px; background:linear-gradient(90deg,#A855F7,#38BDF8); box-shadow:0 0 10px rgba(168,85,247,.7); }
.ava-hero-actions{ display:flex; gap:8px; position:relative; z-index:2; }
.ava-hero-actions .ava-wal-btn{ flex:1; margin-top:14px; padding:11px 12px; font-size:.72rem; }
.ava-hero-all{ background:linear-gradient(135deg,rgba(168,85,247,.42),rgba(56,189,248,.28)) !important; }
@media (max-width:520px){
    .ava-wal-rot{ min-height:210px; }
}
/* مدال همه‌ی ارزها */
.ava-allbal-grid{ display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:10px; width:100%; max-width:820px; margin:0 auto; }
.ava-allbal-item{
    background:var(--ava-card); border:1px solid var(--ava-line); border-radius:16px; padding:13px;
    animation:avaAllIn .4s cubic-bezier(.16,1,.3,1) both;
}
@keyframes avaAllIn{ from{ opacity:0; transform:translateY(14px) scale(.96); } to{ opacity:1; transform:none; } }
.ava-allbal-top{ display:flex; align-items:center; gap:8px; margin-bottom:9px; }
.ava-allbal-fl{ width:26px; height:26px; border-radius:50%; overflow:hidden; flex:0 0 26px; display:inline-flex; align-items:center; justify-content:center; background:var(--ava-card2); }
.ava-allbal-fl img{ width:100%; height:100%; object-fit:cover; }
.ava-allbal-n{ font-size:.74rem; font-weight:800; color:var(--ava-txt); }
.ava-allbal-c{ font-size:.58rem; color:var(--ava-mut); }
.ava-allbal-v{ font-size:1.02rem; font-weight:900; color:var(--ava-txt); direction:ltr; text-align:right; }
.ava-allbal-u{ font-size:.58rem; color:var(--ava-mut); margin-top:2px; }
.ava-allbal-row{ display:flex; align-items:flex-end; justify-content:space-between; gap:8px; margin-top:6px; }
.ava-allbal-chg{ font-size:.58rem; font-weight:800; padding:2px 7px; border-radius:20px; direction:ltr; }
.ava-allbal-chg.up{ color:#22C55E; background:rgba(34,197,94,.14); }
.ava-allbal-chg.dn{ color:#FF5A6E; background:rgba(255,90,110,.14); }
.ava-allbal-total{
    width:100%; max-width:820px; margin:0 auto 14px; padding:14px 16px; border-radius:18px;
    background:linear-gradient(135deg,rgba(124,58,237,.22),rgba(56,189,248,.14));
    border:1px solid rgba(168,85,247,.3); display:flex; align-items:center; justify-content:space-between; gap:10px;
}
.ava-allbal-total .l{ font-size:.7rem; color:var(--ava-mut); font-weight:700; }
.ava-allbal-total .v{ font-size:1.15rem; font-weight:900; color:var(--ava-txt); }

/* ===== (آپدیت ۲) کادر حساب‌های در انتظار پرداخت ===== */
.ava-pendacc-card{ border:1px solid rgba(255,215,0,.28); }
.ava-pendacc-card.blink{ animation: avaPendBlink 1.6s ease-in-out infinite; }
@keyframes avaPendBlink{
    0%,100%{ box-shadow:0 0 0 1px rgba(255,215,0,.25) inset, 0 0 0 rgba(255,215,0,0); border-color:rgba(255,215,0,.28); }
    50%{ box-shadow:0 0 0 2px rgba(255,215,0,.55) inset, 0 0 22px rgba(255,215,0,.28); border-color:rgba(255,215,0,.75); }
}
.ava-pendacc-list{ display:flex; flex-direction:column; gap:10px; margin-top:8px; }
.ava-pendacc-item{
    display:flex; align-items:center; gap:12px; padding:12px; border-radius:16px; cursor:pointer;
    background:var(--ava-card2, rgba(255,255,255,0.04)); border:1px solid var(--ava-line, rgba(255,255,255,0.08));
    transition:transform .15s ease, border-color .2s ease, background .2s ease;
}
.ava-pendacc-item:hover{ transform:translateY(-2px); border-color:rgba(255,215,0,.4); background:rgba(255,255,255,0.06); }
.ava-pendacc-item:active{ transform:scale(.98); }
.ava-pendacc-ic{ width:44px; height:44px; min-width:44px; border-radius:13px; display:flex; align-items:center; justify-content:center; font-size:1.1rem; }
.ava-pendacc-body{ flex:1; min-width:0; }
.ava-pendacc-t{ font-weight:700; color:var(--ava-txt,#fff); font-size:.86rem; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.ava-pendacc-code{ font-size:.62rem; color:#FFD700; background:rgba(255,215,0,.12); padding:2px 8px; border-radius:8px; font-family:monospace; }
.ava-pendacc-s{ font-size:.68rem; color:var(--ava-mut,rgba(255,255,255,0.55)); margin-top:3px; }
.ava-pendacc-cta{ font-size:.66rem; font-weight:700; color:#1a1a2e; background:linear-gradient(135deg,#FFD700,#FFA500); padding:8px 12px; border-radius:12px; white-space:nowrap; display:flex; align-items:center; gap:6px; }
/* ===== (اصلاح ۱) مدال واریز و ارسال فیش — استایل تمیز موبایل و دسکتاپ ===== */
.ava-pa-body{ align-items:flex-start; justify-content:center; overflow-y:auto; padding:16px; }
.ava-pa-inner{ width:100%; max-width:560px; margin:0 auto; }
.ava-pa-meta{ background:linear-gradient(135deg,rgba(255,215,0,.12),rgba(255,165,0,.06)); border:1px solid rgba(255,215,0,.25); border-radius:16px; padding:14px 16px; margin-bottom:16px; }
.ava-pa-meta-row{ display:flex; justify-content:space-between; align-items:center; gap:10px; padding:6px 0; }
.ava-pa-meta-row + .ava-pa-meta-row{ border-top:1px dashed rgba(255,215,0,.2); }
.ava-pa-meta-l{ color:var(--ava-mut,#bbb); font-size:.76rem; display:flex; align-items:center; gap:6px; }
.ava-pa-meta-code{ color:#FFD700; font-family:monospace; font-size:.85rem; letter-spacing:.5px; }
.ava-pa-meta-amount{ color:#FFD700; font-size:1.05rem; font-weight:800; }
.ava-pa-section{ margin-bottom:18px; }
.ava-pa-sec-title{ display:flex; align-items:center; gap:8px; font-size:.85rem; font-weight:700; color:var(--ava-txt,#fff); margin-bottom:4px; }
.ava-pa-sec-title i{ color:#FFD700; }
.ava-pa-sec-hint{ font-size:.68rem; color:var(--ava-mut,#999); margin-bottom:10px; }
.ava-pa-acclist{ display:flex; flex-direction:column; gap:10px; }
/* آیتم حساب داخل مدال (کل ردیف کپی‌شونده) */
.ava-acc-row{ display:flex; align-items:center; gap:12px; padding:14px; border-radius:16px; background:var(--ava-card2,rgba(255,255,255,0.05)); border:1px solid var(--ava-line,rgba(255,255,255,0.1)); cursor:pointer; transition:border-color .2s ease, background .2s ease, transform .1s ease; }
.ava-acc-row:hover{ border-color:rgba(255,215,0,.5); background:rgba(255,215,0,.06); }
.ava-acc-row:active{ transform:scale(.99); }
.ava-acc-info{ flex:1; min-width:0; }
.ava-acc-name{ font-size:.72rem; color:var(--ava-mut,#bbb); margin-bottom:5px; display:flex; align-items:center; gap:5px; }
.ava-acc-card{ font-size:1rem; font-weight:700; color:var(--ava-txt,#fff); font-family:monospace; letter-spacing:1.5px; direction:ltr; text-align:right; word-break:break-all; }
.ava-acc-copy{ display:flex; align-items:center; justify-content:center; background:rgba(255,215,0,.15); color:#FFD700; border-radius:12px; width:42px; height:42px; min-width:42px; font-size:1rem; }
.ava-acc-row:hover .ava-acc-copy{ background:rgba(255,215,0,.3); }
.ava-pa-note{ background:rgba(56,189,248,.1); border:1px solid rgba(56,189,248,.2); border-radius:12px; padding:10px 14px; font-size:.74rem; color:#7dd3fc; margin-top:12px; line-height:1.8; }
/* آپلود زون */
.ava-upzone{ border:2px dashed rgba(255,215,0,.4); border-radius:18px; padding:26px 16px; text-align:center; cursor:pointer; transition:border-color .2s ease, background .2s ease; }
.ava-upzone:hover{ border-color:rgba(255,215,0,.7); background:rgba(255,215,0,.05); }
.ava-upzone.has-files{ border-color:rgba(34,197,94,.6); background:rgba(34,197,94,.05); }
.ava-upzone-ic{ font-size:2rem; color:#FFD700; }
.ava-upzone.has-files .ava-upzone-ic{ color:#22C55E; }
.ava-upzone-t{ margin:10px 0 4px; font-size:.85rem; font-weight:700; color:var(--ava-txt,#fff); }
.ava-upzone-s{ font-size:.68rem; color:var(--ava-mut,#999); }
.ava-pa-preview{ display:flex; flex-wrap:wrap; gap:8px; margin-top:12px; }
.ava-pa-chip{ display:inline-flex; align-items:center; gap:6px; background:rgba(34,197,94,.14); color:#22C55E; border:1px solid rgba(34,197,94,.3); border-radius:10px; padding:6px 12px; font-size:.72rem; font-weight:600; }
.ava-pa-foot{ border-top:1px solid var(--ava-line,rgba(255,255,255,.08)); }
.ava-pa-submit{ display:flex; align-items:center; justify-content:center; gap:8px; width:100%; max-width:560px; margin:0 auto; padding:15px; border:none; border-radius:16px; background:linear-gradient(135deg,#FFD700,#FFA500); color:#1a1a2e; font-family:inherit; font-size:.92rem; font-weight:800; cursor:pointer; transition:transform .1s ease, box-shadow .2s ease; box-shadow:0 6px 18px rgba(255,165,0,.3); }
.ava-pa-submit:hover{ transform:translateY(-2px); box-shadow:0 10px 24px rgba(255,165,0,.4); }
.ava-pa-submit:active{ transform:scale(.98); }
.ava-pa-submit:disabled{ opacity:.6; cursor:not-allowed; transform:none; }
@media (max-width:600px){
    .ava-pa-body{ padding:12px; }
    .ava-acc-card{ font-size:.9rem; letter-spacing:1px; }
    .ava-pa-meta-amount{ font-size:.95rem; }
}

/* ===== هشدارهای قیمت: کانال‌های اطلاع‌رسانی ===== */
.ava-alert-pane{ display:block; }
.ava-alert-chans{ margin-right:6px; display:inline-flex; gap:6px; color:var(--ava-pur2, #A855F7); }
.ava-alert-chans i{ font-size:.72rem; }
.ava-alert-iv{ margin-right:8px; display:inline-flex; gap:4px; align-items:center; font-size:.6rem; color:var(--ava-mut, rgba(255,255,255,.5)); }
.ava-alert-badge{ display:inline-block; font-size:.55rem; font-weight:800; padding:2px 8px; border-radius:20px; margin-inline-start:6px; vertical-align:middle; }
.ava-chan-grid{ display:grid; grid-template-columns:1fr 1fr 1fr; gap:8px; }
.ava-chan{ position:relative; cursor:pointer; }
.ava-chan input{ position:absolute; opacity:0; pointer-events:none; }
.ava-chan span{
    display:flex; flex-direction:column; align-items:center; gap:5px; text-align:center;
    background:var(--ava-card2, rgba(255,255,255,0.04)); border:1px solid var(--ava-line, rgba(255,255,255,0.1));
    border-radius:12px; padding:10px 6px; font-size:.62rem; font-weight:700; color:var(--ava-mut, rgba(255,255,255,0.55));
    transition:all .2s;
}
.ava-chan span i{ font-size:1rem; }
.ava-chan input:checked + span{
    background:linear-gradient(135deg, rgba(124,58,237,.22), rgba(56,189,248,.14));
    border-color:var(--ava-pur2, #A855F7); color:var(--ava-txt, #fff);
}

/* ===== فیش‌ها + آگهی‌های فعال: چیدمان دو ستونه ===== */
/* تگ‌های اسکریپت هرگز نباید به‌صورت متن دیده شوند (حتی اگر فرزند مستقیم گرید/فلکس باشند) */
script{ display:none !important; }
/* (آپدیت ۱) کادر یکپارچه‌ی حساب‌ها و فیش‌ها */
.ava-recacc-card .ava-inv-tab{ font-size:.68rem; padding:9px 8px; }
.ava-recacc-card .ava-inv-tab .ava-new-badge{ padding:1px 5px; border-radius:8px; }
.ava-recacc-actions{ display:flex; align-items:center; }
.ava-recacc-act{ display:inline-flex; gap:10px; align-items:center; }
.ava-rec-pane{ display:block; }
.ava-rec-pane #avaRecPaneReceipts .ava-receipts,
#avaRecPaneReceipts .ava-receipts{ grid-template-columns:1fr; }

/* ===== شاخص ترس و طمع (گیج انیمیشنی) ===== */
.ava-fg-wrap{ display:flex; flex-direction:column; align-items:center; padding:6px 0 2px; }
.ava-fg-gauge{ position:relative; width:100%; max-width:280px; text-align:center; }
.ava-fg-svg{ width:100%; height:auto; overflow:visible; }
.ava-fg-value{ font-size:2.2rem; font-weight:900; line-height:1; margin-top:-6px; }
.ava-fg-label{ font-size:.9rem; font-weight:800; margin-top:4px; }
.ava-fg-scale{ display:flex; justify-content:space-between; width:100%; max-width:300px; margin:12px auto 4px; font-size:.56rem; font-weight:700; gap:2px; }
.ava-fg-scale span{ flex:1; text-align:center; }
@media (max-width:820px){ .ava-fg-value{ font-size:1.9rem; } .ava-fg-scale{ font-size:.5rem; } }

/* ===== (آپدیت ۲) کادر بازار ارز دیجیتال (یک کادر، دو دکمه‌ی سوییچ) ===== */
.ava-crypto-card{ display:flex; flex-direction:column; margin-bottom:12px; }
.ava-crypto-pane{ display:flex; flex-direction:column; flex:1; }
.ava-crypto-card .ava-crypto-live{
    display:inline-flex; align-items:center; gap:5px;
    font-size:.64rem; font-weight:800; color:var(--ava-mut, rgba(255,255,255,.6));
    background:var(--ava-card2, rgba(255,255,255,.04));
    border:1px solid var(--ava-line, rgba(255,255,255,.08));
    padding:3px 9px; border-radius:20px; white-space:nowrap;
}
.ava-crypto-dot{
    width:7px; height:7px; border-radius:50%; background:#22C55E;
    box-shadow:0 0 0 0 rgba(34,197,94,.6); animation:avaCryptoPulse 1.6s infinite;
}
@keyframes avaCryptoPulse{
    0%{ box-shadow:0 0 0 0 rgba(34,197,94,.55); }
    70%{ box-shadow:0 0 0 7px rgba(34,197,94,0); }
    100%{ box-shadow:0 0 0 0 rgba(34,197,94,0); }
}
.ava-crypto-list{ display:flex; flex-direction:column; gap:9px; flex:1; }
.ava-crypto-row{
    display:flex; align-items:center; gap:11px;
    background:var(--ava-card2, rgba(255,255,255,.03));
    border:1px solid var(--ava-line, rgba(255,255,255,.06));
    border-radius:14px; padding:11px 13px; cursor:pointer;
    transition:transform .15s, border-color .15s, background .15s;
}
.ava-crypto-row:hover{ transform:translateY(-1px); border-color:rgba(124,58,237,.45); background:rgba(124,58,237,.06); }
.ava-crypto-row:active{ transform:scale(.99); }
.ava-crypto-ic{
    width:36px; height:36px; border-radius:50%; flex:none; overflow:hidden;
    display:flex; align-items:center; justify-content:center;
    background:rgba(255,255,255,.06);
}
.ava-crypto-ic img{ width:100%; height:100%; object-fit:cover; }
.ava-crypto-ic i{ font-size:.95rem; color:#F7931A; }
.ava-crypto-body{ flex:1; min-width:0; }
.ava-crypto-name{ font-size:.85rem; font-weight:800; color:var(--ava-txt); display:flex; align-items:center; gap:6px; }
.ava-crypto-sym{ font-size:.62rem; font-weight:700; color:var(--ava-mut); text-transform:uppercase; }
.ava-crypto-price{ font-size:.74rem; color:var(--ava-mut); margin-top:3px; direction:ltr; }
.ava-crypto-chg{
    font-size:.78rem; font-weight:900; padding:4px 10px; border-radius:10px; direction:ltr;
    min-width:70px; text-align:center; flex:none;
}
.ava-crypto-chg.up{ color:#22C55E; background:rgba(34,197,94,.12); }
.ava-crypto-chg.dn{ color:#FF5A6E; background:rgba(255,90,110,.12); }
.ava-crypto-rank{
    width:24px; height:24px; border-radius:8px; flex:none; font-size:.68rem; font-weight:900;
    display:flex; align-items:center; justify-content:center;
    background:linear-gradient(135deg,#7C3AED,#A855F7); color:#fff;
}
.ava-crypto-rank.g1{ background:linear-gradient(135deg,#F59E0B,#FBBF24); color:#3a2a00; }
.ava-crypto-rank.g2{ background:linear-gradient(135deg,#9CA3AF,#E5E7EB); color:#333; }
.ava-crypto-rank.g3{ background:linear-gradient(135deg,#B45309,#D97706); color:#fff; }
.ava-crypto-foot{
    margin-top:12px; font-size:.62rem; color:var(--ava-mut, rgba(255,255,255,.5));
    display:flex; align-items:center; gap:6px; justify-content:center; text-align:center;
}
.ava-crypto-foot i{ font-size:.62rem; }
.ava-crypto-err{ font-size:.72rem; color:#FF5A6E; text-align:center; padding:14px; }

/* موبایل: تنظیمات ردیف‌ها */
@media (max-width:820px){
    .ava-crypto-name{ font-size:.82rem; }
    .ava-crypto-price{ font-size:.72rem; }
    .ava-crypto-chg{ min-width:62px; font-size:.74rem; padding:4px 8px; }
    .ava-crypto-ic{ width:34px; height:34px; }
    .ava-crypto-card .ava-inv-tab{ font-size:.66rem; padding:9px 6px; }
}
@media (max-width:400px){
    .ava-crypto-row{ padding:10px; gap:9px; }
    .ava-crypto-chg{ min-width:56px; font-size:.7rem; }
}

/* ===== مدال نمودار ارز دیجیتال ===== */
.ava-coin-head-t{ display:flex; align-items:center; gap:9px; }
.ava-coin-head-t .ava-crypto-ic{ width:30px; height:30px; }
.ava-coin-head-t #avaCoinName{ font-size:.9rem; font-weight:800; color:var(--ava-txt); }
.ava-fs-body.ava-coin-body{ align-items:flex-start; justify-content:flex-start; padding:16px; overflow-y:auto; }
.ava-coin-inner{ width:100%; max-width:760px; margin:0 auto; }
.ava-coin-pricebox{ display:flex; align-items:baseline; gap:12px; margin-bottom:14px; flex-wrap:wrap; }
.ava-coin-price{ font-size:1.7rem; font-weight:900; color:var(--ava-txt); direction:ltr; }
.ava-coin-chg{ font-size:.9rem; font-weight:900; padding:4px 12px; border-radius:12px; direction:ltr; }
.ava-coin-chg.up{ color:#22C55E; background:rgba(34,197,94,.14); }
.ava-coin-chg.dn{ color:#FF5A6E; background:rgba(255,90,110,.14); }
.ava-coin-ranges{ margin-bottom:16px; max-width:360px; }
.ava-coin-chartwrap{
    position:relative; width:100%; height:300px;
    background:var(--ava-card2, rgba(255,255,255,.02));
    border:1px solid var(--ava-line, rgba(255,255,255,.06));
    border-radius:16px; padding:14px;
}
.ava-coin-chartwrap canvas{ width:100%!important; height:100%!important; }
.ava-coin-chart-empty,.ava-coin-chart-load{
    position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
    gap:8px; color:var(--ava-mut); font-size:.8rem;
}
.ava-coin-chart-load i{ font-size:1.4rem; color:var(--ava-pur2,#A855F7); }
@media (max-width:820px){
    .ava-coin-price{ font-size:1.4rem; }
    .ava-coin-chartwrap{ height:240px; padding:10px; }
    .ava-coin-ranges{ max-width:none; }
}

/* ===== کارت نرخ‌ها: هم‌ارتفاع با سبد دارایی ===== */
.ava-rates-card{ display:flex; flex-direction:column; }
.ava-rates-list{ flex:1; overflow-y:auto; }
/* یکسان‌سازی ارتفاع «سبد دارایی من» و «نرخ‌های مورد علاقه» در همه‌ی اندازه‌ها */
.ava-mid-grid{ align-items:stretch; }
.ava-mid-grid > #avaPortCard,
.ava-mid-grid > #avaRatesCard{ height:auto; align-self:stretch; display:flex; flex-direction:column; }
#avaPortCard{ height:100%; }
#avaRatesCard{ height:100%; }
/* لیست نرخ‌ها فضای باقی‌مانده را پر می‌کند تا کارت‌ها هم‌قد شوند */
#avaRatesCard .ava-rates-list{ flex:1 1 auto; min-height:0; }
/* بخش انتهایی سبد دارایی فضای اضافی را می‌گیرد تا با کارت نرخ‌ها هم‌ارتفاع شود */
#avaPortCard .ava-port-foot{ margin-top:auto; }
.ava-rate-cur{ font-size:.7rem; opacity:.7; margin-right:2px; }
.ava-rate-live{ font-size:.58rem; color:var(--ava-grn, #22C55E); margin-left:8px; display:inline-flex; align-items:center; gap:5px; }
.ava-rate-live i{ font-size:.4rem; animation:avaBlink 1.4s ease-in-out infinite; }
/* انتخاب‌گر ارز */
.ava-rate-search{
    width:100%; box-sizing:border-box; background:var(--ava-card2, rgba(255,255,255,0.05));
    border:1px solid var(--ava-line2, rgba(255,255,255,0.12)); border-radius:12px;
    padding:10px 13px; color:var(--ava-txt, #fff); font-family:inherit; font-size:.8rem; margin-bottom:12px;
}
.ava-rate-search:focus{ outline:none; border-color:var(--ava-pur2, #A855F7); }
.ava-rate-picker-list{ max-height:52vh; overflow-y:auto; display:flex; flex-direction:column; gap:8px; }
.ava-rate-pick{
    display:flex; align-items:center; gap:10px;
    background:var(--ava-card2, rgba(255,255,255,0.04));
    border:1px solid var(--ava-line, rgba(255,255,255,0.07));
    border-radius:12px; padding:9px 12px;
}
.ava-rate-pick-btn{
    flex:none; font-family:inherit; font-size:.66rem; font-weight:700; cursor:pointer;
    border:1px solid var(--ava-pur2, #A855F7); color:var(--ava-pur2, #A855F7);
    background:transparent; border-radius:20px; padding:6px 12px; display:inline-flex; align-items:center; gap:5px;
}
.ava-rate-pick-btn.on{ background:linear-gradient(135deg, var(--ava-pur, #7C3AED), var(--ava-pur2, #A855F7)); color:#fff; border-color:transparent; }

/* دکمه + ثبت حواله جدید */
.ava-sec-add{
    width:30px;height:30px;flex:none;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;
    background:linear-gradient(135deg,var(--ava-pur,#7C3AED),var(--ava-pur2,#A855F7));
    color:#fff;text-decoration:none;font-size:.8rem;
    box-shadow:0 6px 16px rgba(124,58,237,.4);transition:.2s;
}
.ava-sec-add:hover{transform:translateY(-2px) rotate(90deg);}
.ava-sec-add:active{transform:scale(.94);}

/* لیست آگهی‌های فعال */
.ava-adlist{ display:flex; flex-direction:column; gap:8px; }
.ava-adrow{
    display:flex; align-items:center; gap:10px; cursor:pointer;
    background:var(--ava-card2, rgba(255,255,255,0.04));
    border:1px solid var(--ava-line, rgba(255,255,255,0.07));
    border-radius:12px; padding:10px 12px; transition:border-color .2s;
}
.ava-adrow:hover{ border-color:var(--ava-pur2, #A855F7); }
.ava-adrow-ic{ width:34px; height:34px; border-radius:50%; display:flex; align-items:center; justify-content:center; flex:none; font-size:.8rem; overflow:hidden; padding:0; box-sizing:border-box; }
.ava-adrow-ic img{ width:100%; height:100%; object-fit:cover; display:block; border-radius:50%; }
.ava-adrow-body{ flex:1; min-width:0; }
.ava-adrow-t{ font-size:.78rem; font-weight:800; color:var(--ava-txt, #fff); }
.ava-adrow-s{ font-size:.6rem; color:var(--ava-mut, rgba(255,255,255,0.4)); margin-top:2px; }
.ava-adrow-cta{
    flex:none; font-size:.64rem; font-weight:800; color:#fff;
    background:linear-gradient(135deg, var(--ava-pur, #7C3AED), var(--ava-pur2, #A855F7));
    border-radius:20px; padding:6px 11px; display:inline-flex; align-items:center; gap:5px;
}
.ava-adrow-cta i{ font-size:.58rem; }

/* شیت پیشنهاد قیمت */
.ava-offer-adinfo{
    background:var(--ava-card2, rgba(255,255,255,0.04));
    border:1px solid var(--ava-line, rgba(255,255,255,0.08));
    border-radius:12px; padding:10px 12px; margin:6px 0 14px;
}
.ava-offer-adline{ display:flex; justify-content:space-between; gap:10px; font-size:.72rem; padding:4px 0; }
.ava-offer-adline span{ color:var(--ava-mut, rgba(255,255,255,0.45)); }
.ava-offer-adline b{ color:var(--ava-txt, #fff); }
.ava-offer-field{ margin-bottom:12px; }
.ava-offer-field label{ display:block; font-size:.66rem; color:var(--ava-mut, rgba(255,255,255,0.55)); margin-bottom:6px; font-weight:700; }
.ava-offer-field input{
    width:100%; box-sizing:border-box; background:var(--ava-card2, rgba(255,255,255,0.05));
    border:1px solid var(--ava-line2, rgba(255,255,255,0.12)); border-radius:12px;
    padding:11px 13px; color:var(--ava-txt, #fff); font-family:inherit; font-size:.85rem; direction:rtl;
}
.ava-offer-field input:focus{ outline:none; border-color:var(--ava-pur2, #A855F7); }
.ava-offer-total{ min-height:20px; font-size:.75rem; color:var(--ava-mut, rgba(255,255,255,0.6)); margin-bottom:12px; text-align:center; }
.ava-offer-total b{ color:#22C55E; }

/* ===== (آپدیت جدید) کادر راهنمای بالای عملیات سریع ===== */
.ava-qa-hint{
    display:flex; align-items:flex-start; gap:10px;
    background:linear-gradient(135deg, rgba(124,58,237,.16), rgba(56,189,248,.10));
    border:1px solid rgba(168,85,247,.30);
    border-radius:16px; padding:11px 13px; margin:0 0 10px;
}
.ava-qa-hint-ico{
    flex:none; width:28px; height:28px; border-radius:50%;
    background:rgba(168,85,247,.22); color:#C4B5FD;
    display:flex; align-items:center; justify-content:center; font-size:.8rem;
}
.ava-qa-hint-txt{ font-size:.68rem; line-height:1.9; color:var(--ava-mut, rgba(255,255,255,.75)); }
.ava-qa-hint-txt b{ color:var(--ava-txt, #fff); }

/* ===== (اصلاح) چیدمانِ پایه‌ی گرید «عملیات سریع» — سه ستونه، مرتب و هم‌اندازه =====
   این بلوک به‌عمد تمام خواص کارتِ قدیمی (background/border/padding در
   dashboard-ava.css) را ریست می‌کند و یک آیتمِ تک‌شکل با ارتفاع ثابت
   می‌سازد؛ در غیر این‌صورت به‌خاطر طول متفاوتِ برچسب‌ها، کارت‌ها ارتفاع
   نامساوی می‌گرفتند و چیدمان به‌هم‌ریخته به‌نظر می‌رسید. */
.ava-qa{
    display:grid !important; grid-template-columns:repeat(3, 1fr) !important;
    gap:16px 6px !important; overflow:visible !important; padding-bottom:0 !important;
}
.ava-qa-item{
    position:relative; display:flex !important; flex-direction:column; align-items:center;
    justify-content:flex-start; gap:8px; cursor:pointer; text-align:center;
    flex:none !important; width:auto !important;
    background:transparent !important; border:none !important; padding:0 !important;
    border-radius:0 !important; min-height:108px;
}
.ava-qa-item:active{ transform:scale(.95); }
.ava-qa-item span:last-child{
    font-size:.68rem; color:var(--ava-txt, #fff); font-weight:600; line-height:1.35;
    white-space:normal !important; word-break:normal !important; overflow-wrap:break-word;
    hyphens:none; max-width:100%; width:100%;
    display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;
    overflow:hidden; min-height:2.7em;
}
.ava-qa-badge{
    width:52px; height:52px; border-radius:16px; flex:none;
    background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.1);
    display:flex; align-items:center; justify-content:center; overflow:hidden;
    margin:0 !important;
}
.ava-qa-img img{ width:26px; height:26px; object-fit:contain; }

/* ===== (آپدیت جدید) نشان به‌روزرسانی روی آیکون «حساب‌ها و فیش‌ها» در عملیات سریع ===== */
.ava-qa-item.ava-qa-pulse .ava-qa-badge{
    box-shadow:0 0 0 0 rgba(168,85,247,.55);
    animation:avaQaPulseRing 1.7s ease-out infinite;
}
@keyframes avaQaPulseRing{
    0%{ box-shadow:0 0 0 0 rgba(168,85,247,.55); }
    70%{ box-shadow:0 0 0 10px rgba(168,85,247,0); }
    100%{ box-shadow:0 0 0 0 rgba(168,85,247,0); }
}
.ava-qa-count{    position:absolute; top:2px; left:8px; z-index:2;
    min-width:18px; height:18px; padding:0 5px; border-radius:10px;
    background:linear-gradient(135deg,#FF5A6E,#FF8A3D); color:#fff;
    font-size:.6rem; font-weight:900; display:flex; align-items:center; justify-content:center;
    box-shadow:0 3px 8px rgba(255,90,110,.5); border:2px solid var(--ava-card2, #1B1440);
    animation:avaBlink 1.3s steps(2,start) infinite;
}

.ava-ordtr-pane{ display:block; }
.ava-ordtr-act{ display:inline-flex; }
/* ===== (آپدیت جدید) نسخه‌ی کوچک کادر سفارشات/حواله‌ها کنار خدمات محبوب ===== */
.ava-ordtr-mini .ava-inv-switch{ gap:6px; margin-bottom:10px; }
.ava-ordtr-mini .ava-inv-tab{ font-size:.64rem; padding:7px 4px; gap:3px; border-radius:10px; }
.ava-ordtr-mini .ava-inv-count{ font-size:.5rem; min-width:14px; height:14px; padding:0 4px; }
.ava-ordtr-mini .ava-order{ padding:8px; }
.ava-ordtr-mini .ava-order-t{ font-size:.66rem; }
.ava-ordtr-mini .ava-order-meta{ font-size:.55rem; }
.ava-ordtr-mini .ava-list-row{ padding:7px 0; }
@media (max-width:400px){
    .ava-ordtr-mini .ava-inv-tab{ font-size:.58rem; padding:6px 2px; }
}

/* ===== (آپدیت جدید) کادر بزرگ اخبار بازار + اخبار اقتصادی ایران ===== */
.ava-news-mega{ min-height:420px; }
.ava-news-pane{ display:block; }
.ava-news-mega-list .ava-news-item{ padding:13px 0; gap:13px; }
.ava-news-mega-list .ava-news-thumb{ width:58px; height:58px; border-radius:14px; font-size:1.05rem; }
.ava-news-mega-list .ava-news-title{ font-size:.8rem; line-height:1.85; }
.ava-news-mega-list .ava-news-date{ font-size:.64rem; margin-top:4px; }
.ava-news-mega .ava-irn-pager-head{ display:flex; justify-content:flex-end; margin-bottom:10px; }
.ava-news-mega .ava-irn-thumb{ width:86px; height:70px; }
.ava-news-mega .ava-irn-title{ font-size:.82rem; }
.ava-news-mega .ava-irn-row{ padding:12px 14px; }
@media (max-width:480px){
    .ava-news-mega{ min-height:360px; }
    .ava-news-mega-list .ava-news-thumb{ width:48px; height:48px; }
}

/* ===================================================================
   (آپدیت جدید) طراحی مجدد کادر «سبد دارایی من» — شیشه‌ای، بنفش تیره
   =================================================================== */
#avaPortCard{
    position:relative; overflow:hidden;
    background:
        radial-gradient(120% 100% at 100% 0%, rgba(168,85,247,.22), transparent 55%),
        radial-gradient(120% 100% at 0% 100%, rgba(56,189,248,.14), transparent 55%),
        linear-gradient(165deg,#211045 0%,#170D34 55%,#120A28 100%);
    border:1px solid rgba(168,85,247,.35);
    border-radius:24px;
    box-shadow:0 18px 44px rgba(0,0,0,.4), inset 0 1px 0 rgba(255,255,255,.08);
}
#avaPortCard::after{
    content:""; position:absolute; left:-46px; top:-46px; width:170px; height:170px;
    background:radial-gradient(circle, rgba(124,58,237,.45), transparent 68%);
    pointer-events:none; animation:avaWalGlow 6s ease-in-out infinite;
}
#avaPortCard .ava-sec-head{ position:relative; z-index:2; }
#avaPortCard .ava-sec-title{
    background:linear-gradient(120deg,#fff 30%,#D8B4FE 70%);
    -webkit-background-clip:text; background-clip:text; -webkit-text-fill-color:transparent;
}
#avaPortCard .ava-sec-title i{
    -webkit-text-fill-color:#A855F7; color:#A855F7;
    filter:drop-shadow(0 0 6px rgba(168,85,247,.6));
}

/* ---- (آپدیت) کارت نمودار جریان نقدی — عیناً پورت‌شده از transactions.php
   (.tx-chart-card و زیرمجموعه‌هایش)، اسکوپ‌شده زیر #avaPortCard تا با
   بقیه‌ی داشبورد تداخل نکند. رنگ‌ها/فاصله‌ها دقیقاً همان مقادیر اصلی. ---- */
#avaPortCard .tx-chart-head{
    position:relative; z-index:2;
    display:flex; align-items:center; justify-content:space-between; gap:10px;
    flex-wrap:wrap;
}
#avaPortCard .tx-chart-title{
    display:flex; align-items:center; gap:8px;
    font-size:15px; font-weight:700; color:#fff;
}
#avaPortCard .tx-chart-title i{ color:#A855F7; }
#avaPortCard .tx-range{ position:relative; z-index:2; display:flex; gap:6px; background:rgba(255,255,255,.05); border-radius:12px; padding:4px; }
#avaPortCard .tx-range button{
    border:0; background:transparent; color:rgba(255,255,255,.55); cursor:pointer;
    font-family:inherit; font-size:11px; font-weight:700; padding:6px 11px; border-radius:9px; transition:.2s;
}
#avaPortCard .tx-range button.on{ background:linear-gradient(135deg,#7C3AED,#A855F7); color:#fff; }

#avaPortCard .tx-cur-tabs{
    position:relative; z-index:2;
    display:grid; grid-template-columns:repeat(4,1fr); gap:8px; margin-bottom:14px;
}
#avaPortCard .tx-cur-tab{
    display:flex; flex-direction:column; align-items:center; gap:5px;
    padding:11px 6px; border-radius:16px; cursor:pointer; font-family:inherit;
    background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1);
    color:rgba(255,255,255,.55); transition:transform .18s, border-color .2s, background .2s, box-shadow .2s;
}
#avaPortCard .tx-cur-tab:active{ transform:scale(.96); }
#avaPortCard .tx-cur-tab i{ font-size:14px; }
#avaPortCard .tx-cur-tab .t{ font-size:11px; font-weight:800; letter-spacing:.03em; }
#avaPortCard .tx-cur-tab .s{ font-size:9px; opacity:.8; }
#avaPortCard .tx-cur-tab.on{
    color:#fff; border-color:transparent;
    box-shadow:0 8px 22px rgba(124,58,237,.32);
}
#avaPortCard .tx-chart-wrap{ position:relative; z-index:2; height:210px; }
#avaPortCard .tx-chart-wrap canvas{ display:block; width:100% !important; }
#avaPortCard .tx-chart-legend{
    position:relative; z-index:2;
    display:flex; align-items:center; justify-content:center; gap:16px; margin-top:12px; flex-wrap:wrap;
    font-size:11px; color:rgba(255,255,255,.55); font-weight:700;
}
#avaPortCard .tx-chart-legend span{ display:inline-flex; align-items:center; gap:6px; }
#avaPortCard .tx-chart-legend i{ width:10px; height:10px; border-radius:3px; display:inline-block; }
#avaPortCard .tx-mini-stats{
    position:relative; z-index:2;
    display:grid; grid-template-columns:repeat(3,1fr); gap:8px; margin-top:14px;
}
#avaPortCard .tx-mini{
    background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.08); border-radius:14px;
    padding:10px 8px; text-align:center;
}
#avaPortCard .tx-mini .l{ font-size:9.5px; color:rgba(255,255,255,.55); font-weight:700; letter-spacing:.04em; }
#avaPortCard .tx-mini .v{ font-size:14px; font-weight:800; margin-top:4px; direction:ltr; }
#avaPortCard .tx-mini.in  .v{ color:#4CD964; }
#avaPortCard .tx-mini.out .v{ color:#FF5E5E; }
#avaPortCard .tx-mini.net .v{ color:#A855F7; }
#avaPortCard .tx-chart-empty{
    position:relative; z-index:2;
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    height:100%; color:rgba(255,255,255,.55); font-size:13px; gap:10px;
}
#avaPortCard .tx-chart-empty i{ font-size:26px; opacity:.5; }
@media (max-width: 480px){
    #avaPortCard .tx-cur-tabs{ gap:6px; }
    #avaPortCard .tx-cur-tab{ padding:9px 4px; border-radius:13px; }
    #avaPortCard .tx-cur-tab .t{ font-size:10px; }
    #avaPortCard .tx-cur-tab .s{ display:none; }
    #avaPortCard .tx-chart-wrap{ height:190px; }
    #avaPortCard .tx-mini .v{ font-size:12px; }
}

#avaPortCard .ava-port-foot{ position:relative; z-index:2; }

/* ===== (آپدیت) گجت هوشمند بازار: پیش‌بینی قیمت + تمایل بازار ===== */

/* گجت خلاصه‌ی داشبورد — بدون قاب/کادر جدا، یک پنل شیشه‌ای یکپارچه با نمودار
   زمینه، مچ اصلی طراحی داشبورد (بنفش تیره + گرد iOS‑مانند)؛ با زدنش کل
   ابزار در مدال تمام‌صفحه باز می‌شود */
.ava-smart-gadget{
    position:relative; display:flex; flex-direction:column; cursor:pointer; overflow:hidden; isolation:isolate;
    background:
        radial-gradient(140% 130% at 105% -15%, rgba(192,132,252,.40), transparent 55%),
        radial-gradient(120% 120% at -10% 120%, rgba(88,28,199,.5), transparent 60%),
        linear-gradient(165deg,#1E1752 0%,#150F3D 55%,#0C0824 100%);
    border-radius:24px; padding:16px 18px 14px; margin-bottom:14px;
    box-shadow:0 16px 38px rgba(59,15,140,.32), inset 0 1px 0 rgba(255,255,255,.05);
}
.ava-smart-gadget::after{
    content:''; position:absolute; inset:0; border-radius:inherit; pointer-events:none; z-index:0;
    background:linear-gradient(180deg, rgba(255,255,255,.05), transparent 45%);
}
.ava-smart-gadget-spark-bg{
    position:absolute; inset:auto 0 0 0; height:62%; opacity:.5; pointer-events:none; z-index:0;
    -webkit-mask-image:linear-gradient(180deg, transparent, #000 35%);
    mask-image:linear-gradient(180deg, transparent, #000 35%);
}
.ava-smart-gadget-spark-bg svg{ display:block; width:100%; height:100%; }
.ava-smart-gadget-head{ display:flex; align-items:center; justify-content:space-between; gap:8px; position:relative; z-index:1; }
.ava-smart-gadget-badge{
    position:relative; flex:none; width:40px; height:40px; border-radius:13px;
    background:linear-gradient(135deg,#7C3AED,#C084FC); display:flex; align-items:center; justify-content:center;
    box-shadow:0 8px 18px rgba(124,58,237,.45); overflow:hidden;
}
.ava-smart-gadget-badge img{ width:100%; height:100%; object-fit:cover; border-radius:13px; }
.ava-smart-gadget-pulse{
    position:absolute; inset:-4px; border-radius:16px; border:2px solid rgba(192,132,252,.5);
    animation:avaSmartGadgetPulse 2s ease-out infinite;
}
@keyframes avaSmartGadgetPulse{
    0%{ transform:scale(.9); opacity:1; } 100%{ transform:scale(1.35); opacity:0; }
}
.ava-smart-gadget-chip{
    display:flex; align-items:center; gap:6px; font-size:.58rem; font-weight:900; color:#F3E8FF;
    background:rgba(168,85,247,.16); border:1px solid rgba(196,132,252,.3); padding:5px 11px 5px 9px; border-radius:99px;
}
.ava-smart-gadget-livedot{ width:5px; height:5px; border-radius:50%; background:#22C55E; animation:avaGadgetLiveDot 1.8s infinite; }
@keyframes avaGadgetLiveDot{
    0%{ box-shadow:0 0 0 0 rgba(34,197,94,.55); } 70%{ box-shadow:0 0 0 6px rgba(34,197,94,0); } 100%{ box-shadow:0 0 0 0 rgba(34,197,94,0); }
}
.ava-smart-gadget-fade{ transition:opacity .3s ease; position:relative; z-index:1; margin-top:16px; }
.ava-smart-gadget-fade.ava-fading{ opacity:0; }
.ava-smart-gadget-cur{
    font-size:.68rem; color:rgba(255,255,255,.55); font-weight:700; margin-bottom:3px;
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
}
.ava-smart-gadget-row{ display:flex; align-items:flex-end; gap:10px; flex-wrap:wrap; }
.ava-smart-gadget-price{ font-size:1.5rem; font-weight:900; color:#fff; direction:ltr; letter-spacing:-.2px; line-height:1; }
.ava-smart-gadget-price small{ font-size:.58rem; font-weight:700; color:rgba(255,255,255,.5); margin-inline-start:2px; }
.ava-smart-gadget-chg{
    display:inline-flex; align-items:center; gap:4px; font-size:.68rem; font-weight:900;
    padding:4px 9px; border-radius:10px; white-space:nowrap;
}
.ava-smart-gadget-chg i{ font-size:.58rem; }
.ava-smart-gadget-chg.up{ color:#22C55E; background:rgba(34,197,94,.14); }
.ava-smart-gadget-chg.down{ color:#FF5A6E; background:rgba(255,90,110,.14); }
.ava-smart-gadget-foot{ display:flex; align-items:center; justify-content:space-between; gap:10px; margin-top:16px; position:relative; z-index:1; }
.ava-smart-gadget-dots{ display:flex; gap:4px; flex:1; max-width:56px; }
.ava-smart-gadget-dots span{ flex:1; height:3px; border-radius:3px; background:rgba(255,255,255,.14); transition:.35s; }
.ava-smart-gadget-dots span.on{ background:linear-gradient(90deg,#A855F7,#EC4899); }
.ava-smart-gadget-cta{ flex:none; display:flex; align-items:center; gap:4px; font-size:.62rem; font-weight:800; color:rgba(255,255,255,.62); }
.ava-smart-gadget-cta i{ font-size:.55rem; color:#C084FC; }

/* هدر قیمت بزرگ زنده داخل مدال */
.ava-smart-hero{ position:relative; z-index:1; margin:14px 0 6px; }
.ava-smart-hero-row{ display:flex; align-items:baseline; gap:10px; }
.ava-smart-hero-val{ font-size:1.9rem; font-weight:900; color:#fff; direction:ltr; letter-spacing:-.5px; }
.ava-smart-hero-chg{ display:inline-flex; align-items:center; gap:4px; font-size:.76rem; font-weight:800; color:#22C55E; background:rgba(34,197,94,.12); padding:4px 9px; border-radius:8px; }
.ava-smart-hero-chg.down{ color:#FF5A6E; background:rgba(255,90,110,.12); }
.ava-smart-hero-sub{ font-size:.6rem; color:rgba(255,255,255,.4); margin-top:4px; }

.ava-smart-ohlc{ display:flex; justify-content:space-between; margin:12px 0 4px; position:relative; z-index:1; }
.ava-smart-ohlc div{ text-align:center; flex:1; }
.ava-smart-ohlc .l{ font-size:.56rem; color:rgba(255,255,255,.4); margin-bottom:3px; }
.ava-smart-ohlc .v{ font-size:.76rem; font-weight:800; color:#fff; direction:ltr; }
.ava-smart-ohlc .v.hi{ color:#22C55E; }
.ava-smart-ohlc .v.lo{ color:#FF5A6E; }

/* عمق بازار */
.ava-smart-depth{
    margin-top:14px; padding:16px;
    background: rgba(255,255,255,.045); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);
    border:1px solid rgba(255,255,255,.09); border-radius:18px; position:relative; z-index:1;
}
.ava-smart-depth-ttl{ display:flex; align-items:center; gap:6px; font-size:.7rem; font-weight:800; color:#38BDF8; margin-bottom:6px; }
.ava-smart-depth-sub{ font-size:.58rem; color:rgba(255,255,255,.4); margin-bottom:10px; line-height:1.8; }
.ava-smart-depth-bars{ display:flex; align-items:flex-end; gap:4px; height:64px; }
.ava-smart-depth-bar{ flex:1; border-radius:4px 4px 0 0; position:relative; cursor:pointer; min-height:3px; transition:transform .15s; }
.ava-smart-depth-bar:active{ transform:scaleY(.92); }
.ava-smart-depth-bar.sell{ background:linear-gradient(180deg, rgba(255,90,110,.9), rgba(255,90,110,.35)); }
.ava-smart-depth-bar.buy{ background:linear-gradient(180deg, rgba(34,197,94,.9), rgba(34,197,94,.35)); }
.ava-smart-depth-bar.best::after{
    content:'🏆'; position:absolute; top:-16px; left:50%; transform:translateX(-50%); font-size:.65rem;
}
.ava-smart-depth-axis{ display:flex; justify-content:space-between; margin-top:6px; }
.ava-smart-depth-axis span{ font-size:.52rem; color:rgba(255,255,255,.32); direction:ltr; }

.ava-smart-card{ position:relative; overflow:hidden; }
.ava-smart-card::before{
    content:''; position:absolute; top:-60%; left:-20%; width:70%; height:220%;
    background:radial-gradient(circle, rgba(168,85,247,.14), transparent 70%);
    pointer-events:none;
}
.ava-smart-info{
    width:24px; height:24px; border-radius:50%; background:rgba(255,255,255,.06);
    display:flex; align-items:center; justify-content:center; color:rgba(255,255,255,.4); font-size:.65rem; flex:none;
}
.ava-smart-badge{
    display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px;
    border-radius:8px; background:rgba(168,85,247,.18); font-size:.7rem; margin-right:4px; overflow:hidden;
}
.ava-smart-badge img{ width:100%; height:100%; object-fit:cover; border-radius:8px; }
.ava-smart-tabs{
    display:flex; flex-wrap:wrap; align-items:stretch; gap:10px; margin:14px 0 10px; position:relative; z-index:1;
}
.ava-smart-tab{
    flex:1 1 auto; min-width:110px; min-height:56px; box-sizing:border-box;
    display:flex; align-items:center; justify-content:center; gap:8px; text-align:center;
    padding:0 18px; border-radius:16px; cursor:pointer; font-family:inherit;
    background:rgba(20,12,36,.6); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
    border:1.5px solid rgba(255,255,255,.14);
    color:rgba(255,255,255,.75); font-size:1rem; line-height:1.3; font-weight:800; white-space:nowrap; transition:.2s;
}
.ava-smart-tab.on{
    background:linear-gradient(135deg,#7C3AED,#A855F7); color:#fff; border-color:transparent;
    box-shadow:0 8px 20px rgba(124,58,237,.45);

}
.ava-smart-tab.crypto.on{ background:linear-gradient(135deg,#F7931A,#EAB308); box-shadow:0 6px 16px rgba(247,147,26,.4); }
.ava-smart-tab-ico{
    flex:none; width:26px; height:26px; border-radius:50%; overflow:hidden;
    display:flex; align-items:center; justify-content:center; font-size:.85rem;
    background:rgba(255,255,255,.08);
}
.ava-smart-tab-ico img{ width:100%; height:100%; object-fit:cover; display:block; }

/* ===================================================================
   (جدید) پس‌زمینه‌ی تصویریِ اختصاصیِ مودالِ «گجت هوشمند بازار» + سیستم
   یکپارچه‌ی «کارت شیشه‌ای» (glass card) برای همه‌ی بخش‌های داخلش — تا
   ظاهرش به‌جای تکه‌های پراکنده، مثل یک اپ مالیِ حرفه‌ای و یک‌دست دیده شود.
   =================================================================== */
#avaSmartFullscreenModal{
    background-image: linear-gradient(180deg, rgba(8,4,22,.90), rgba(8,4,22,.95) 45%, rgba(8,4,22,.98) 80%), url('assets/img/ai-forecast-bg.jpg');
    background-size: cover; background-position: top center; background-repeat: no-repeat;
}
#avaSmartFullscreenModal .ava-fs-top{
    background: rgba(10,6,26,.5); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
}
.ava-glass-card{
    background: rgba(255,255,255,.045); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);
    border: 1px solid rgba(255,255,255,.09); border-radius: 18px; padding: 16px; margin-top: 14px;
    position: relative; z-index: 1;
}
.ava-glass-card:first-child{ margin-top: 0; }

/* (جدید) کارت + شیتِ «تارگت‌های پیش‌بینی‌شده» */
.ava-targets-card{
    margin:14px 0 4px; padding:16px; border-radius:18px;
    background: rgba(255,255,255,.045); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);
    border:1px solid rgba(168,85,247,.28); position:relative; z-index:1;
    box-shadow: 0 0 0 1px rgba(168,85,247,.06) inset;
}
.ava-targets-top{ display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; gap:8px; }
.ava-targets-ttl{ display:flex; align-items:center; gap:6px; font-size:.74rem; font-weight:900; color:#fff; white-space:nowrap; }
.ava-targets-ttl i{ color:#C084FC; }
.ava-targets-viewall{
    flex:none; background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.14); color:rgba(255,255,255,.8);
    font-size:.6rem; font-weight:700; padding:7px 14px; border-radius:99px; cursor:pointer; font-family:inherit;
}
.ava-targets-addcoin{
    flex:none; width:30px; height:30px; border-radius:50%; display:flex; align-items:center; justify-content:center;
    background:rgba(168,85,247,.18); border:1px solid rgba(168,85,247,.4); color:#C084FC; cursor:pointer; font-size:.7rem;
}
.ava-targets-list{ display:grid; grid-template-columns:1fr 1fr; gap:8px; }
.ava-targets-empty{ grid-column:1 / -1; text-align:center; padding:18px 0; color:rgba(255,255,255,.4); font-size:.65rem; }

.ava-target-row{
    display:flex; flex-direction:column; align-items:stretch; gap:7px; padding:10px; border-radius:14px;
    background:rgba(255,255,255,.03); border:1px solid rgba(255,255,255,.07); cursor:pointer; min-width:0;
}
.ava-target-row:active{ background:rgba(255,255,255,.06); }
.ava-target-row-head{ display:flex; align-items:center; gap:7px; }
.ava-target-ico{
    flex:none; width:30px; height:30px; border-radius:50%; overflow:hidden; display:flex; align-items:center;
    justify-content:center; background:rgba(255,255,255,.06); font-size:.85rem;
}
.ava-target-ico img{ width:100%; height:100%; object-fit:cover; display:block; }
.ava-target-name{ flex:1; min-width:0; }
.ava-target-sym{ font-size:.7rem; font-weight:900; color:#fff; }
.ava-target-fname{ font-size:.54rem; color:rgba(255,255,255,.45); margin-top:1px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.ava-target-mid{ min-width:0; }
.ava-target-prices{ display:flex; align-items:center; flex-wrap:wrap; font-size:.56rem; color:rgba(255,255,255,.55); margin-bottom:6px; gap:4px; }
.ava-target-prices span{ white-space:nowrap; }
.ava-target-prices span b{ color:#fff; font-weight:800; direction:ltr; display:inline-block; }
.ava-target-dir{
    flex:none; display:inline-flex; align-items:center; gap:3px; font-size:.52rem; font-weight:800;
    padding:2px 6px; border-radius:99px;
}
.ava-target-dir.up{ background:rgba(34,197,94,.15); color:#22C55E; }
.ava-target-dir.down{ background:rgba(255,90,110,.15); color:#FF5A6E; }
.ava-target-tf{
    flex:none; display:inline-flex; align-items:center; gap:3px; font-size:.52rem; font-weight:800;
    padding:2px 6px; border-radius:99px;
}
.ava-target-tf.near{ background:rgba(251,191,36,.15); color:#FBBF24; }
.ava-target-tf.far{ background:rgba(56,189,248,.15); color:#38BDF8; }
.ava-target-bar{ height:6px; border-radius:99px; background:rgba(255,255,255,.08); overflow:hidden; }
.ava-target-bar-fill{ height:100%; width:0%; border-radius:99px; background:linear-gradient(90deg,#7C3AED,#22C55E); transition:width .6s ease; }
.ava-target-bar-fill.down{ background:linear-gradient(90deg,#7C3AED,#FF5A6E); }
.ava-target-bar-fill.hit{ background:linear-gradient(90deg,#16A34A,#22C55E); }
.ava-target-bar-fill.down.hit{ background:linear-gradient(90deg,#C0293D,#FF5A6E); }
.ava-target-status{
    flex:none; width:20px; height:20px; border-radius:50%; display:flex; align-items:center; justify-content:center;
    font-size:.6rem; margin-right:auto;
}
@media (max-width:360px){
    .ava-targets-list{ grid-template-columns:1fr; }
}
/* ===== (جدید) تبِ «در انتظار» / «تارگت‌های خورده‌شده» داخل شیتِ «مشاهده همه» ===== */
.ava-targets-tabbar{ display:flex; gap:8px; margin-bottom:12px; }
.ava-targets-tabbtn{
    flex:1; text-align:center; padding:9px 10px; border-radius:12px; border:1px solid rgba(255,255,255,.08);
    background:rgba(255,255,255,.04); color:rgba(255,255,255,.65); font-size:.74rem; font-weight:700;
    cursor:pointer; transition:.2s;
}
.ava-targets-tabbtn.on{ background:rgba(124,58,237,.18); border-color:rgba(124,58,237,.5); color:#fff; }
.ava-target-row.hit-row{ opacity:.92; }
.ava-target-row .ava-target-hit-date{ font-size:.6rem; color:rgba(255,255,255,.45); font-weight:600; margin-top:2px; }
.ava-target-status.hit{ background:rgba(34,197,94,.18); color:#22C55E; }
.ava-target-status.pending{ background:rgba(255,255,255,.06); color:rgba(255,255,255,.4); }

/* (جدید) تقویمِ «تارگت‌های خورده‌شده» — به‌جای انباشته‌شدنِ همه‌ی تارگت‌های
   خورده‌شده زیر هم، هر روزی که تارگتی خورده یک نقطه/شمارنده روی تقویم دارد؛
   با زدن روی آن روز فقط تارگت‌های همان روز پایین‌تر نشان داده می‌شوند. */
.ava-cal-wrap{ grid-column:1 / -1; }
.ava-cal{
    background:rgba(255,255,255,.035); border:1px solid rgba(255,255,255,.08); border-radius:16px;
    padding:12px; margin-bottom:12px;
}
.ava-cal-head{ display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
.ava-cal-label{ font-size:.72rem; font-weight:900; color:#fff; }
.ava-cal-nav{
    width:28px; height:28px; border-radius:50%; border:1px solid rgba(255,255,255,.1); background:rgba(255,255,255,.05);
    color:rgba(255,255,255,.75); display:flex; align-items:center; justify-content:center; font-size:.62rem; cursor:pointer;
}
.ava-cal-nav:active{ background:rgba(168,85,247,.22); }
.ava-cal-week{ display:grid; grid-template-columns:repeat(7,1fr); margin-bottom:4px; }
.ava-cal-week span{ text-align:center; font-size:.56rem; font-weight:800; color:rgba(255,255,255,.4); }
.ava-cal-grid{ display:grid; grid-template-columns:repeat(7,1fr); gap:3px; }
.ava-cal-cell{
    position:relative; aspect-ratio:1/1; border-radius:11px; border:none; cursor:pointer; font-family:inherit;
    background:rgba(255,255,255,.03); display:flex; align-items:center; justify-content:center;
    color:rgba(255,255,255,.7); transition:.15s;
}
.ava-cal-cell.empty{ background:transparent; cursor:default; }
.ava-cal-daynum{ font-size:.62rem; font-weight:700; }
.ava-cal-cell.has-hit{ background:rgba(34,197,94,.12); color:#fff; font-weight:800; }
.ava-cal-cell.today{ box-shadow:inset 0 0 0 1.5px rgba(192,132,252,.55); }
.ava-cal-cell.sel{ background:linear-gradient(135deg,#16A34A,#22C55E); color:#06170C; }
.ava-cal-dot{
    position:absolute; bottom:3px; left:50%; transform:translateX(-50%);
    min-width:11px; height:11px; padding:0 2px; border-radius:99px; background:#22C55E; color:#06170C;
    font-size:.44rem; font-weight:900; display:flex; align-items:center; justify-content:center; line-height:1;
}
.ava-cal-cell.sel .ava-cal-dot{ background:rgba(6,23,12,.55); color:#fff; }
.ava-cal-daylist-ttl{
    display:flex; align-items:center; gap:6px; font-size:.66rem; font-weight:800; color:rgba(255,255,255,.75);
    margin:2px 2px 8px;
}
.ava-cal-daylist-ttl i{ color:#22C55E; }
.ava-cal-daylist-count{
    margin-inline-start:auto; background:rgba(34,197,94,.15); color:#22C55E; font-size:.58rem; font-weight:900;
    padding:2px 8px; border-radius:99px;
}
.ava-cal-daylist{ display:grid; grid-template-columns:1fr 1fr; gap:8px; }
@media (max-width:360px){
    .ava-cal-daylist{ grid-template-columns:1fr; }
}
.ava-smart-tab.add-tab{ border-style:dashed; color:#A855F7; }
.ava-smart-tab-x{ margin-right:2px; opacity:.6; font-size:.6rem; }
.ava-smart-tab-x:hover{ opacity:1; color:#FF5A6E; }
.ava-smart-tabs-div{ flex:none; width:1px; align-self:stretch; background:rgba(255,255,255,.1); margin:2px 2px; }
.ava-smart-tf-tabs{
    display:flex; gap:6px; margin:2px 0 10px; position:relative; z-index:1;
    background:rgba(255,255,255,.03); border-radius:12px; padding:4px;
}
.ava-smart-tf-tab{
    flex:1; text-align:center; padding:7px 4px; border-radius:9px; border:none; cursor:pointer; font-family:inherit;
    background:transparent; color:rgba(255,255,255,.5); font-size:.68rem; font-weight:800; transition:.2s;
}
.ava-smart-tf-tab.on{ background:linear-gradient(135deg,#A855F7,#7C3AED); color:#fff; }
.ava-smart-best{
    display:flex; align-items:center; gap:6px; margin-top:10px; padding:9px 12px; cursor:pointer;
    background:linear-gradient(90deg, rgba(34,197,94,.14), rgba(34,197,94,.04));
    border:1px solid rgba(34,197,94,.3); border-radius:12px; position:relative; z-index:1;
    font-size:.66rem; color:rgba(255,255,255,.7); font-weight:700;
}
.ava-smart-best i.fa-trophy{ color:#FBBF24; font-size:.7rem; }
.ava-smart-best b{ color:#22C55E; font-weight:900; direction:ltr; unicode-bidi:embed; }
.ava-smart-body{ display:flex; align-items:center; gap:4px; margin-top:14px; position:relative; z-index:1; }
.ava-smart-pred{ flex:none; width:88px; text-align:center; border-left:1px solid rgba(255,255,255,.08); padding-left:6px; }
.ava-smart-pred-lbl{ font-size:.56rem; color:rgba(255,255,255,.45); font-weight:700; line-height:1.6; }
.ava-smart-pred-val{ font-size:1rem; font-weight:900; color:#fff; margin-top:6px; direction:ltr; }
.ava-smart-pred-chg{ display:inline-flex; align-items:center; gap:3px; margin-top:5px; font-size:.66rem; font-weight:800; }
.ava-smart-pred-chg.up{ color:#22C55E; }
.ava-smart-pred-chg.down{ color:#FF5A6E; }
.ava-smart-chart-col{ flex:1; min-width:0; padding:0 6px; }
.ava-smart-chart-top{ display:flex; align-items:center; justify-content:space-between; margin-bottom:4px; }
.ava-smart-chart-top span{ font-size:.6rem; color:rgba(255,255,255,.45); font-weight:700; }
.ava-smart-chart-top-btns{ display:flex; gap:5px; }
.ava-smart-zoom-reset{
    border:0; background:rgba(255,255,255,.06); color:rgba(255,255,255,.5); width:20px; height:20px;
    border-radius:6px; font-size:.55rem; cursor:pointer; display:flex; align-items:center; justify-content:center;
}
.ava-smart-chart-wrap{ position:relative; height:100px; }
.ava-smart-chart-wrap canvas{ display:block; width:100% !important; cursor:crosshair; touch-action:none; -ms-touch-action:none; }
.ava-smart-legend{ display:flex; gap:12px; justify-content:center; margin-top:4px; flex-wrap:wrap; }
.ava-smart-full-body{ flex:1; display:flex; flex-direction:column; padding:16px; overflow-y:auto; }
.ava-smart-tf-row{ display:flex; align-items:center; gap:8px; }
.ava-smart-tf-row .ava-smart-tf-tabs{ flex:1; margin:0; }
.ava-smart-zoom-reset-full{ width:34px; height:34px; border-radius:10px; font-size:.75rem; flex:none; }
.ava-smart-chart-wrap-full{ flex:1; min-height:260px; height:auto !important; margin-top:6px; }
@media (min-width:600px){ .ava-smart-chart-wrap-full{ min-height:400px; } }
.ava-smart-legend span{ display:inline-flex; align-items:center; gap:4px; font-size:.55rem; color:rgba(255,255,255,.45); font-weight:700; }
.ava-smart-legend-dot{ width:8px; height:8px; border-radius:50%; display:inline-block; }
.ava-smart-legend-dash{ border-radius:2px; width:12px; height:3px; }
.ava-smart-gauge-col{ flex:none; width:88px; text-align:center; }
.ava-smart-gauge-wrap{ position:relative; width:76px; height:76px; margin:0 auto 6px; }
.ava-smart-gauge-wrap svg{ display:block; }
.ava-smart-gauge-pct{
    position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
    font-size:.92rem; font-weight:900; color:#fff; line-height:1; text-align:center;
}
.ava-smart-gauge-lbl{ font-size:.6rem; font-weight:800; color:rgba(255,255,255,.75); line-height:1.5; }
.ava-smart-gauge-sub{
    display:inline-flex; align-items:center; gap:4px; margin-top:5px;
    font-size:.53rem; color:rgba(255,255,255,.4); font-weight:700;
}
.ava-smart-ai-badge{
    width:13px; height:13px; border-radius:50%; background:rgba(168,85,247,.25);
    display:flex; align-items:center; justify-content:center; font-size:.45rem; color:#A855F7;
}
.ava-smart-hint{
    text-align:center; font-size:.58rem; color:rgba(255,255,255,.35); margin-top:12px;
    padding-top:12px; border-top:1px dashed rgba(255,255,255,.08); position:relative; z-index:1;
}
.ava-smart-hint i{ color:#A855F7; margin-left:4px; }

.ava-smart-chart-loading{
    position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
    color:rgba(255,255,255,.4); font-size:1rem; background:rgba(21,16,52,.6); border-radius:8px;
}
.ava-smart-price-box{
    position:absolute; top:0; left:0; z-index:5; pointer-events:auto; cursor:pointer;
    background:#1B1440; border:1.5px solid #A855F7; border-radius:12px; padding:6px 12px;
    transform:translate(-50%, -115%); white-space:nowrap; box-shadow:0 8px 20px rgba(0,0,0,.4);
    text-align:center;
}
.ava-smart-price-box::after{
    content:''; position:absolute; bottom:-6px; right:calc(50% - 6px); width:0; height:0;
    border-left:6px solid transparent; border-right:6px solid transparent; border-top:6px solid #A855F7;
}
.ava-smart-price-box-val{ font-size:.72rem; font-weight:900; color:#fff; direction:ltr; }
.ava-smart-price-box-ads{ font-size:.58rem; color:#A855F7; font-weight:700; margin-top:2px; }
.ava-smart-price-box-disabled{ cursor:default; border-color:rgba(255,255,255,.2); }
.ava-smart-price-box-disabled::after{ border-top-color:rgba(255,255,255,.2); }
.ava-smart-price-box-disabled .ava-smart-price-box-ads{ color:rgba(255,255,255,.4); }
.ava-smart-analysis{
    margin-top:14px; padding:16px;
    background: rgba(255,255,255,.045); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);
    border:1px solid rgba(56,189,248,.25); border-radius:18px; position:relative; z-index:1;
}
.ava-smart-analysis-ttl{ display:flex; align-items:center; gap:6px; font-size:.68rem; font-weight:800; color:#38BDF8; margin-bottom:6px; }
.ava-smart-analysis-txt{ font-size:.66rem; color:rgba(255,255,255,.72); line-height:2; }
.ava-smart-analysis-txt b{ font-weight:900; direction:ltr; display:inline-block; }
.ava-smart-analysis-txt b.up{ color:#22C55E; }
.ava-smart-analysis-txt b.down{ color:#FF5A6E; }

.ava-smart-accuracy{
    margin-top:14px; padding:16px;
    background: rgba(255,255,255,.045); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);
    border:1px solid rgba(168,85,247,.28); border-radius:18px; position:relative; z-index:1;
}
.ava-smart-accuracy-top{ display:flex; align-items:center; justify-content:space-between; gap:8px; }
.ava-smart-accuracy-ttl{ display:flex; align-items:center; gap:6px; font-size:.68rem; font-weight:800; color:#C084FC; }
.ava-smart-accuracy-pct{ font-size:1.05rem; font-weight:900; color:#fff; }
.ava-smart-accuracy-bar{ margin-top:9px; height:7px; border-radius:99px; background:rgba(255,255,255,.08); overflow:hidden; }
.ava-smart-accuracy-bar-fill{
    height:100%; width:0%; border-radius:99px; background:linear-gradient(90deg,#7C3AED,#22C55E);
    transition:width .6s ease;
}
.ava-smart-accuracy-sub{ margin-top:8px; font-size:.64rem; color:rgba(255,255,255,.6); line-height:1.9; }
.ava-smart-accuracy-last{
    margin-top:10px; padding-top:10px; border-top:1px dashed rgba(255,255,255,.12);
    display:flex; align-items:center; gap:7px; font-size:.66rem; font-weight:700;
}
.ava-smart-accuracy-last.hit{ color:#22C55E; }
.ava-smart-accuracy-last.missed{ color:#FF5A6E; }
.ava-smart-accuracy-last i{ font-size:.85rem; }

/* ===== انیمیشن‌های نمایشی ===== */
@keyframes avaSmartPulse{
    0%, 100% { box-shadow:0 0 0 0 rgba(34,197,94,.35); }
    50% { box-shadow:0 0 0 6px rgba(34,197,94,0); }
}
.ava-smart-best{ animation:avaSmartPulse 2.2s ease-in-out infinite; }
@keyframes avaSmartTrophy{
    0%, 100% { transform:rotate(0deg) scale(1); }
    50% { transform:rotate(-8deg) scale(1.15); }
}
.ava-smart-best i.fa-trophy{ display:inline-block; animation:avaSmartTrophy 1.8s ease-in-out infinite; }
@keyframes avaSmartShimmer{
    0% { background-position:-200% 0; }
    100% { background-position:200% 0; }
}
.ava-smart-badge{
    background:linear-gradient(90deg, rgba(168,85,247,.18) 25%, rgba(236,72,153,.35) 50%, rgba(168,85,247,.18) 75%);
    background-size:200% 100%; animation:avaSmartShimmer 3s linear infinite;
}
.ava-smart-fade{ animation:avaSmartFadeIn .5s ease-out both; }
@keyframes avaSmartFadeIn{ from{ opacity:0; transform:translateY(6px); } to{ opacity:1; transform:translateY(0); } }
.ava-smart-ads-foot{
    flex:none; display:flex; justify-content:center; padding:14px 16px calc(14px + env(safe-area-inset-bottom));
    border-top:1px solid rgba(255,255,255,.08);
}
.ava-smart-ads-close{
    position:static; margin:0; min-width:160px; justify-content:center;
}
.ava-smart-best-row{
    background:rgba(34,197,94,.08) !important; border:1px solid rgba(34,197,94,.3) !important;
    border-radius:14px;
}
.ava-smart-best-badge{
    display:inline-flex; align-items:center; gap:3px; margin-right:6px; padding:1px 8px;
    background:rgba(251,191,36,.16); color:#FBBF24; border-radius:10px; font-size:.6rem; font-weight:800;
}
@media (max-width:480px){
    .ava-smart-pred{ width:76px; }
    .ava-smart-gauge-col{ width:76px; }
    .ava-smart-gauge-wrap{ width:66px; height:66px; }
    .ava-smart-chart-wrap{ height:88px; }
}

/* ================================================================
   AVA BENTO — بازطراحی موزائیکی داشبورد (کادرهای کوچک/بزرگ کنار هم)
   فقط CSS، بدون تغییر منطق/آی‌دی‌های موجود؛ چیده‌شده روی همان کلاس‌ها
   تا با سیستم JS/شخصی‌سازی فعلی کاملاً سازگار بماند.
   ================================================================ */

/* ---------- ۱) عمق پس‌زمینه: هاله‌های محیطی ثابت (فقط تم تیره) ---------- */
html:not([data-theme="light"]) #mainDashboard.ava-dash{ position:relative; isolation:isolate; }
html:not([data-theme="light"]) #mainDashboard.ava-dash::before{
    content:"";
    position:fixed; inset:0; z-index:-1; pointer-events:none;
    background:
        radial-gradient(38% 26% at 12% 8%,  rgba(168,85,247,.20), transparent 68%),
        radial-gradient(34% 24% at 92% 14%, rgba(255,77,141,.14), transparent 68%),
        radial-gradient(40% 30% at 50% 96%, rgba(34,211,160,.10), transparent 70%);
}

/* ---------- ۲) کارت‌ها: شیشه‌ای، عمق‌دار، با واکنش لمسی ---------- */
.ava-dash .ava-card{
    background:linear-gradient(165deg, var(--ava-card2) 0%, var(--ava-card) 62%);
    border-color:var(--ava-line);
    box-shadow:0 10px 26px -14px rgba(6,3,20,.65), inset 0 1px 0 rgba(255,255,255,.05);
    transition:transform .18s ease, border-color .2s ease, box-shadow .2s ease;
}
[data-theme="light"] .ava-dash .ava-card{
    box-shadow:0 10px 24px -16px rgba(76,29,149,.22), inset 0 1px 0 rgba(255,255,255,.6);
}
@media (hover:hover) and (pointer:fine){
    .ava-dash .ava-card:hover{ border-color:rgba(168,85,247,.4); box-shadow:0 16px 34px -16px rgba(124,58,237,.35), inset 0 1px 0 rgba(255,255,255,.06); }
}
.ava-sec-title{ letter-spacing:.01em; }

/* ---------- ۳) ردیف بالا: کیف پول (بزرگ) + صورت‌حساب‌ها (کوچک) ---------- */
.ava-top-grid{ grid-template-columns:1.18fr .82fr; align-items:stretch; }
.ava-wallet-card{ min-height:206px; }
.ava-top-inv{ min-height:206px; }
@media (max-width:380px){ .ava-top-grid{ grid-template-columns:1fr; } }

/* ---------- ۴) نوار آماری کوچک: خلاصه‌ی وضعیت (سه کاشی موزائیکی) ---------- */
.ava-stat-strip{
    display:grid; grid-template-columns:1.2fr 1fr 1fr; gap:10px;
    margin:0 0 14px;
}
.ava-stat-strip > *{ min-width:0; }
.ava-stat-chip{
    position:relative; overflow:hidden; min-width:0;
    background:linear-gradient(160deg, rgba(124,58,237,.16), rgba(21,16,52,.92) 70%);
    border:1px solid var(--ava-line);
    border-radius:16px; padding:12px 12px 11px;
    display:flex; flex-direction:column; gap:7px;
    box-shadow:0 8px 20px -14px rgba(6,3,20,.6);
}
[data-theme="light"] .ava-stat-chip{ background:linear-gradient(160deg, rgba(124,58,237,.08), #FFFFFF 70%); }
.ava-stat-chip--accent{
    background:linear-gradient(155deg, rgba(255,77,141,.24), rgba(124,58,237,.26) 55%, rgba(21,16,52,.94));
    border-color:rgba(168,85,247,.42);
}
[data-theme="light"] .ava-stat-chip--accent{ background:linear-gradient(155deg, rgba(255,77,141,.14), rgba(124,58,237,.10) 55%, #FFFFFF); }
.ava-stat-ic{
    width:28px; height:28px; border-radius:9px; flex:none;
    display:flex; align-items:center; justify-content:center;
    background:rgba(255,255,255,.10); color:#D8B4FE; font-size:.76rem;
}
.ava-stat-v{ font-size:.92rem; font-weight:900; color:var(--ava-txt); line-height:1.3; overflow-wrap:break-word; }
.ava-stat-l{ font-size:.6rem; color:var(--ava-mut); font-weight:700; }

/* ---------- ۵) عملیات سریع: از کروسل افقی به کاشی‌های موزائیکی ۳×۲ ---------- */
.ava-qa{
    display:grid; grid-template-columns:repeat(3,1fr); gap:10px;
    overflow:visible; scroll-snap-type:none;
}
.ava-qa-item{ width:auto; }
@media (hover:hover) and (pointer:fine){
    .ava-qa-item:hover{ border-color:rgba(124,58,237,.55); transform:translateY(-2px); }
}

/* ---------- ۶) بنر تبلیغاتی: باریک‌تر و شیک‌تر ---------- */
.ava-promo{ box-shadow:0 12px 28px -16px rgba(6,3,20,.55); }

/* ---------- ۷) سبد دارایی + نرخ‌های مورد علاقه: بدون کروسل، کاشی‌های بزرگ پشت‌هم ---------- */
.ava-mid-grid{
    display:flex; flex-direction:column; overflow:visible;
    scroll-snap-type:none; padding-bottom:0;
}
.ava-mid-grid > *{ flex:1 1 auto; scroll-snap-align:none; }
@media (min-width:760px){
    .ava-mid-grid{ display:grid; grid-template-columns:1fr 1fr; align-items:stretch; }
}

/* ---------- ۸) فعالیت و دسترسی سریع: دو کاشی کوچک + یک کاشی بزرگ ----------
   ترتیب DOM: ۱) تراکنش‌های اخیر  ۲) سفارشات/حواله‌ها  ۳) هشدارهای قیمت
   کاشی سوم (هشدارها) تمام‌عرض می‌شود تا کنتراست کوچک/بزرگ موزائیکی شکل بگیرد. */
.ava-quad-scroll{
    display:grid; grid-template-columns:1fr 1fr; gap:12px;
    overflow:visible; scroll-snap-type:none; padding-bottom:0;
}
.ava-quad-scroll > *{ flex:initial; min-width:0; }
.ava-quad-scroll > *:nth-child(3){ grid-column:1 / -1; }
@media (max-width:360px){
    .ava-quad-scroll{ grid-template-columns:1fr; }
    .ava-quad-scroll > *:nth-child(3){ grid-column:auto; }
}

/* ---------- ۹) کاهش حرکت برای کاربران حساس ---------- */
@media (prefers-reduced-motion:reduce){
    html:not([data-theme="light"]) #mainDashboard.ava-dash::before{ display:none; }
    .ava-dash .ava-card{ transition:none; }
}

/* ---------- ۱۰) رفع مشکلات پوسته‌ی روشن ----------
   .ava-fs-modal (همه‌ی مودال‌های تمام‌صفحه: اخبار، فیش‌ها، معرفی حساب،
   آرشیوها و ...) همیشه یک پس‌زمینه‌ی تیرهٔ ثابت داشت، صرف‌نظر از تم — اما
   متن/آیکون داخلش از var(--ava-txt) استفاده می‌کند که در تم روشن به رنگ
   تیره تغییر می‌کند. نتیجه: متن تیره روی زمینه‌ی تقریباً مشکی، یعنی عملاً
   ناخوانا. همچنین چند کادر/دکمه‌ی کوچکِ دیگر فقط با یک لایه‌ی سفیدِ
   نیمه‌شفاف روی پس‌زمینه‌ی تیره طراحی شده بودند (مثل دکمه‌ی بستن شیت‌ها با
   آیکون سفید روی دایره‌ی تقریباً سفید، یا دایره‌ی پرچم/آیکون ارزها) که در
   تم روشن یا نامرئی می‌شدند یا با پس‌زمینه‌ی سفیدِ کارت یکی می‌شدند. */
[data-theme="light"] .ava-fs-modal{ background:#FFFFFF; }
[data-theme="light"] .ava-sheet-close{ background:rgba(0,0,0,.06); color:#1F1235; }
[data-theme="light"] .ava-flag{ background:rgba(0,0,0,.05); }
[data-theme="light"] .ava-order{ background:rgba(0,0,0,.025); }
[data-theme="light"] .ava-port-tabs,
[data-theme="light"] .ava-news-tabs{ background:rgba(0,0,0,.04); }
[data-theme="light"] .ava-cn-img{ background:rgba(0,0,0,.05); }
</style>

</head>
<body>
<div id="loadingOverlay"><div id="loadingSpinner"></div></div>

<!-- بخش «نرخ لحظه‌ای بازار» در includes/footer_menu.php تعریف شده است (بدون iframe) -->

<!-- ===== MAIN DASHBOARD ===== -->
<div class="dashboard ava-dash" id="mainDashboard" dir="rtl">

    <!-- ===== Header (طرح جدید) ===== -->
    <div class="ava-head">
        <div class="ava-user" onclick="avaOpenProfile()" style="cursor:pointer;">
            <img src="<?php echo htmlspecialchars($user['avatar']); ?>" alt="avatar" onerror="this.src='/ledor/default-avatar.png'">
            <div style="min-width:0;">
                <div class="ava-user-name"><?php echo htmlspecialchars($avaFullName); ?></div>
                <div class="ava-user-lvl">
                    <span>سطح کاربری: <?php echo $avaLevel; ?></span>
                    <span class="lvl-badge"><i class="fas fa-check"></i></span>
                </div>
            </div>
        </div>
        <div class="ava-head-acts">
            <?php if ($isUserAdmin): ?>
            <a href="admin_panel.php" class="ava-ico-btn" title="پنل مدیریت" style="color:gold;"><i class="fas fa-crown"></i></a>
            <?php endif; ?>
            <button class="ava-ico-btn" onclick="avaToggleTheme()" title="تغییر پوسته‌ی روشن/تیره" id="avaThemeBtn"><i class="fas fa-sun" id="avaThemeIcon"></i></button>
            <button class="ava-ico-btn" onclick="avaToggleLayoutEdit()" title="نمایش/مخفی‌کردن بخش‌های داشبورد" id="avaLayoutEditBtn"><i class="fas fa-eye"></i></button>
            <!-- زنگوله نوتیفیکیشن (همان سیستم قبلی) -->
            <div class="ava-ico-btn notification-bell" id="notificationBell">
                <div class="bell-icon">
                    <i class="fas fa-bell"></i>
                    <span class="bell-dot ava-dot" id="notificationDot" style="display:none;"></span>
                    <span class="notification-badge" id="notificationBadge" style="display:none;">0</span>
                </div>
            </div>
            <button class="ava-ico-btn" onclick="avaOpenSupport()" title="پشتیبانی" style="position:relative;"><i class="fas fa-headset"></i><span class="ava-dot" id="avaSupportDot" style="display:none;"></span><span class="notification-badge" id="avaSupportBadge" style="display:none;">0</span></button>
        </div>
    </div>

    <!-- دراپ‌داون نوتیفیکیشن (مشترک با صفحه تبادل ارزی) -->
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

    <!-- ===== (۴) بنر اسلایدی هشدارها: صورت‌حساب پرداخت‌نشده / حساب در انتظار پرداخت / فیش دریافتی / پیشنهاد تبادل ===== -->
    <?php
        $__alertSlides = [];
        if (!empty($avaInvPending)) {
            $__alertSlides[] = [
                'icon'   => 'fas fa-file-invoice-dollar',
                // صورت‌حساب پرداخت‌نشده هم نیازمند اقدام کاربر است → قرمزِ هشدار
                'cls'    => 'ava-alert-invoice ava-alert-danger',
                't'      => ava_fa(count($avaInvPending)) . ' صورت‌حساب پرداخت‌نشده دارید',
                's'      => 'برای مشاهده‌ی شماره‌حساب و پرداخت اینجا بزنید',
                'cta'    => 'پرداخت',
                // قبلاً فقط به کارت صورت‌حساب‌ها اسکرول می‌کرد؛ چون آن کارت
                // همان بالای صفحه است، عملاً هیچ اتفاقی دیده نمی‌شد. حالا
                // مستقیم مودال همان صورت‌حساب باز می‌شود تا کاربر شماره‌حساب
                // را ببیند، پرداخت کند و فیش را آپلود کند.
                'invoice' => (int)$avaInvPending[0]['id'],
            ];
        }
        if (!empty($avaPendingAccounts)) {
            $__alertSlides[] = [
                'icon'   => 'fas fa-building-columns',
                // «در انتظار پرداخت» یعنی کاربر باید کاری انجام دهد → قرمزِ هشدار
                'cls'    => 'ava-alert-accounts ava-alert-danger',
                't'      => ava_fa(count($avaPendingAccounts)) . ' حساب در انتظار پرداخت جدید دارید',
                's'      => 'شماره حساب توسط ادمین ارسال شده؛ برای مشاهده اینجا بزنید',
                'cta'    => 'مشاهده',
                // نکته: #avaRecAccCard اکنون داخل مودال (display:none) است، پس
                // scrollIntoView روی آن هیچ کاری نمی‌کرد. باید خود مودال باز شود.
                'modal'  => 'avaRecAccModal',
                'tab'    => 'pending',
                // (اصلاح) مهم نیست چند حساب در انتظار پرداخت باشد — کلیک روی
                // «مشاهده» در این بنر همیشه مستقیم مودال شماره‌حساب اولین مورد
                // را باز می‌کند، هرگز از مودال «لیست» به‌عنوان لایه‌ی واسط عبور
                // نمی‌کند. قبلاً این فقط برای حالت دقیقاً «۱ مورد» فعال بود؛
                // چون شمارش می‌توانست با متن نمایشی بنر همگام نباشد، همان مودالِ
                // «لیست پشتِ جزئیات» که کاربر خواسته بود کاملاً حذف شود دوباره
                // ظاهر می‌شد. مسیر مرورِ لیست («حساب‌ها و فیش‌ها» در عملیات
                // سریع → لیست → انتخاب یک مورد → بازگشت به لیست) دست‌نخورده و
                // جداست، چون آن یک UX معتبر و متفاوت است.
                'directPend' => $avaPendingAccounts[0] ?? null,
            ];
        }
        if (!empty($avaHasNewReceipt)) {
            $__alertSlides[] = [
                'icon'   => 'fas fa-receipt',
                'cls'    => 'ava-alert-receipts',
                't'      => ava_fa(count($avaReceipts)) . ' فیش دریافتی جدید دارید',
                's'      => 'برای مشاهده اینجا بزنید',
                'cta'    => 'مشاهده',
                'modal'  => 'avaRecAccModal',
                'tab'    => 'receipts',
            ];
        }
    ?>
    <div class="ava-alert-carousel" id="avaAlertCarousel" style="display:none;">
        <div class="ava-banner-track ava-alert-track" id="avaAlertTrack">
            <?php foreach ($__alertSlides as $__as): ?>
            <div class="ava-inv-topbar <?php echo htmlspecialchars($__as['cls']); ?>"
                 <?php if (!empty($__as['target'])): ?>data-target="<?php echo htmlspecialchars($__as['target']); ?>"<?php endif; ?>
                 <?php if (!empty($__as['modal'])): ?>data-modal="<?php echo htmlspecialchars($__as['modal']); ?>"<?php endif; ?>
                 <?php if (!empty($__as['invoice'])): ?>data-invoice="<?php echo (int)$__as['invoice']; ?>"<?php endif; ?>
                 <?php if (!empty($__as['tab'])): ?>data-modaltab="<?php echo htmlspecialchars($__as['tab']); ?>"<?php endif; ?>
                 <?php if (!empty($__as['directPend'])): ?>data-directpend="<?php echo htmlspecialchars(json_encode($__as['directPend'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES); ?>"<?php endif; ?>>
                <div class="ava-inv-topbar-ico"><i class="<?php echo htmlspecialchars($__as['icon']); ?>"></i></div>
                <div class="ava-inv-topbar-txt">
                    <div class="ava-inv-topbar-t"><?php echo htmlspecialchars($__as['t']); ?></div>
                    <div class="ava-inv-topbar-s"><?php echo htmlspecialchars($__as['s']); ?></div>
                </div>
                <span class="ava-inv-topbar-cta"><?php echo htmlspecialchars($__as['cta']); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>


    <!-- ===== موجودی کیف پول + بنر خرید و فروش ===== -->
    <div class="ava-top-grid">
<?php
        /* ===== (آپدیت) داده‌های کارت موجودی چرخشی: تومان/دلار/یورو/تتر + سایر ولت‌ها ===== */
        $avaHeroOrder = ['IRR','USD','EUR','USDT'];
        $avaHeroCurs  = []; $__hSeen = [];
        foreach ($avaHeroOrder as $__c) if (isset($avaWallets[$__c])) { $avaHeroCurs[] = $__c; $__hSeen[$__c] = 1; }
        foreach ($avaWallets as $__c => $__b) if (!isset($__hSeen[$__c])) $avaHeroCurs[] = $__c;
        if (empty($avaHeroCurs)) $avaHeroCurs = ['IRR'];
        $avaHeroData = [];
        foreach ($avaHeroCurs as $__c) {
            $__m   = ava_meta($__c);
            $__ser = array_slice(array_map('floatval', $avaSeriesByCur[$__c] ?? []), -14);
            if (count($__ser) < 2) $__ser = [0, 0];
            $__chg = 0.0;
            $__f = (float)$__ser[0]; $__l = (float)$__ser[count($__ser) - 1];
            if ($__f != 0) $__chg = (($__l - $__f) / abs($__f)) * 100;
            elseif ($__l != 0) $__chg = 100;
            $avaHeroData[] = [
                'code'   => $__c,
                'label'  => $__m['code'] ?? $__c,
                'name'   => $__m['name'] ?? $__c,
                'unit'   => $__m['unit'] ?? $__c,
                'flag'   => $__m['flag'] ?? '',
                'ico'    => $__m['ico'] ?? 'fas fa-coins',
                'col'    => $__m['col'] ?? '#A855F7',
                'dec'    => (int)($__m['dec'] ?? 2),
                'bal'    => (float)($avaWallets[$__c] ?? 0),
                'toman'  => round(((float)($avaWallets[$__c] ?? 0)) * ava_rate_toman($__c)),
                'chg'    => round($__chg, 2),
                'series' => $__ser,
            ];
        }
        ?>
        <div class="ava-wallet-card ava-wal-rot" data-avasec="balance" data-avasec-title="موجودی کیف پول" data-avasec-icon="fas fa-wallet">
            <div>
                <div class="ava-wal-top">
                    <span>موجودی کیف پول</span>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <button class="ava-wal-eye" onclick="avaToggleBalance()" title="نمایش/مخفی"><i class="fas fa-eye" id="avaEye"></i></button>
                    </div>
                </div>

                <div class="ava-hero-stage" id="avaHeroStage" onclick="avaOpenAllBalances()" title="مشاهده همه موجودی‌ها" style="cursor:pointer;">
                    <div class="ava-hero-cur" id="avaHeroCur">
                        <span class="ava-hero-flag" id="avaHeroFlag"></span>
                        <span class="ava-hero-name" id="avaHeroName"></span>
                        <span class="ava-hero-chg" id="avaHeroChg"></span>
                    </div>
                    <div class="ava-wal-amount ava-hero-amt" id="avaIrrAmount"></div>
                    <div class="ava-wal-unit" id="avaHeroUnit"></div>
                    <div class="ava-hero-eq" id="avaHeroEq"></div>
                    <svg class="ava-hero-spark" id="avaHeroSpark" viewBox="0 0 160 40" preserveAspectRatio="none" aria-hidden="true">
                        <defs>
                            <linearGradient id="avaHeroGrad" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="#22C55E" stop-opacity=".35"/>
                                <stop offset="100%" stop-color="#22C55E" stop-opacity="0"/>
                            </linearGradient>
                        </defs>
                        <path id="avaHeroArea" d="" fill="url(#avaHeroGrad)"></path>
                        <path id="avaHeroLine" d="" fill="none" stroke="#22C55E" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"></path>
                        <circle id="avaHeroDot" r="3" cx="0" cy="0" fill="#22C55E"></circle>
                    </svg>
                </div>

                <div class="ava-hero-dots" id="avaHeroDots"></div>
            </div>

            <i class="fas fa-wallet ava-wal-art"></i>
        </div>

        <!-- صورت‌حساب‌ها: دو گزینه‌ی سوییچ‌شونده (در انتظار پرداخت / تاریخچه) -->
        <div class="ava-card ava-top-inv" id="avaTopInvCard" data-avasec="invoices" data-avasec-title="صورت‌حساب‌ها" data-avasec-icon="fas fa-file-invoice">
            <div class="ava-sec-head">
                <div class="ava-sec-title"><i class="fas fa-file-invoice"></i> صورت‌حساب‌ها
                    <?php if (!empty($avaInvPending)): ?><span class="ava-inv-count"><?php echo ava_fa(count($avaInvPending)); ?></span><?php endif; ?>
                </div>
                <!-- تاریخچه در گوشه: کلیک → مدال تاریخچه کامل (استایل کوچک مثل «مشاهده همه») -->
                <button type="button" class="ava-more" onclick="avaOpenFsModal('avaInvHistModal')">
                    تاریخچه <i class="fas fa-chevron-left"></i>
                </button>
            </div>

            <!-- در انتظار پرداخت -->
            <div class="ava-inv-pane" id="avaInvPanePending">
                <?php if (empty($avaInvPending)): ?>
                    <div class="ava-empty"><i class="fas fa-check-circle"></i> صورت‌حساب پرداخت‌نشده‌ای ندارید</div>
                <?php else:
                    // (۴) جمع بدهی به تفکیک ارز، برای نمایش در بالای فهرست
                    $__due = [];
                    foreach ($avaInvPending as $iv) {
                        $c = strtoupper($iv['currency']);
                        $__due[$c] = ($__due[$c] ?? 0) + (float)$iv['amount'];
                    }
                    $__dueTxt = [];
                    foreach ($__due as $c => $sum) {
                        $mm = ava_meta($c);
                        $__dueTxt[] = ava_num($sum, $mm['dec']) . ' ' . $mm['code'];
                    }
                ?>
                <div class="ava-inv-due">
                    <span class="ava-inv-due-ico"><i class="fas fa-triangle-exclamation"></i></span>
                    <span class="ava-inv-due-txt">
                        <span class="ava-inv-due-t">مجموع پرداخت‌نشده</span>
                        <span class="ava-inv-due-v"><?php echo implode(' + ', $__dueTxt); ?></span>
                    </span>
                </div>
                <?php foreach ($avaInvPending as $iv):
                    $m = ava_meta(strtoupper($iv['currency']));
                    $ready = $iv['status'] === 'approved';   // آماده پرداخت → چشمک بزند
                    // pending یعنی ادمین هنوز اطلاعات پرداخت را نفرستاده (نه اینکه صورت‌حساب در حال بررسی باشد)
                    $stT = ['pending'=>'در انتظار اطلاعات پرداخت','approved'=>'آماده پرداخت'][$iv['status']] ?? $iv['status'];
                ?>
                <div class="ava-inv-item <?php echo $ready ? 'ava-inv-blink' : ''; ?>" style="cursor:pointer" onclick="avaOpenInvoice(<?php echo (int)$iv['id']; ?>)">
                    <div class="ava-inv-amt"><?php echo ava_num($iv['amount'], $m['dec']); ?> <?php echo $m['code']; ?></div>
                    <div class="ava-inv-meta"><?php echo htmlspecialchars(mb_substr($iv['description'] ?? '', 0, 30)); ?></div>
                    <span class="ava-badge <?php echo $ready ? 'ok' : 'wait'; ?>"><?php echo $stT; ?></span>
                    <?php if ($ready): ?>
                    <button type="button" class="ava-inv-pay" onclick="event.stopPropagation();avaOpenInvoice(<?php echo (int)$iv['id']; ?>)">
                        <i class="fas fa-credit-card"></i> پرداخت کنید
                    </button>
                    <?php endif; ?>
                </div>
                <?php endforeach; endif; ?>
            </div>

        </div>
    </div>

    <?php
        // ===== (جدید) نوار آماری کوچک: خلاصه‌ی وضعیت کاربر =====
        // از داده‌های همین بالا (avaHeroData/avaWallets/avaActiveAds) دوباره
        // استفاده می‌شود؛ هیچ کوئری جدیدی به دیتابیس زده نمی‌شود.
        $__avaTotalTomanAll = 0.0;
        foreach ($avaHeroData as $__hd) { $__avaTotalTomanAll += (float)($__hd['toman'] ?? 0); }
        $__avaCompactToman = function($n) {
            $n = (float)$n; $neg = $n < 0; $n = abs($n);
            if ($n >= 1000000000)      $s = number_format($n / 1000000000, 1) . ' میلیارد';
            elseif ($n >= 1000000)     $s = number_format($n / 1000000, 1) . ' میلیون';
            elseif ($n >= 1000)        $s = number_format($n / 1000, 1) . ' هزار';
            else                       $s = number_format($n);
            return ($neg ? '−' : '') . ava_fa($s);
        };
    ?>
    <div class="ava-stat-strip">
        <div class="ava-stat-chip ava-stat-chip--accent">
            <span class="ava-stat-ic"><i class="fas fa-sack-dollar"></i></span>
            <span class="ava-stat-v"><?php echo $__avaCompactToman($__avaTotalTomanAll); ?> <small style="font-size:.56rem;font-weight:700;color:var(--ava-mut);">تومان</small></span>
            <span class="ava-stat-l">ارزش کل دارایی</span>
        </div>
        <div class="ava-stat-chip">
            <span class="ava-stat-ic"><i class="fas fa-coins"></i></span>
            <span class="ava-stat-v"><?php echo ava_fa(count($avaWallets)); ?></span>
            <span class="ava-stat-l">ارز فعال</span>
        </div>
        <div class="ava-stat-chip">
            <span class="ava-stat-ic"><i class="fas fa-bullhorn"></i></span>
            <span class="ava-stat-v"><?php echo ava_fa(count($avaActiveAds)); ?></span>
            <span class="ava-stat-l">آگهی فعال بازار</span>
        </div>
    </div>

    <?php
        // ===== (آپدیت جدید) نشان آیکون «حساب‌ها و فیش‌ها» در عملیات سریع =====
        $avaAcctUpdateCount = (int)count($avaPendingActive) + (int)count($avaReceiptsUnseen);
        $avaAcctHasUpdate   = ($avaHasPendingAcc || $avaHasNewReceipt);
    ?>
    <!-- کادر راهنما/توضیحات بالای عملیات سریع -->
    <div class="ava-qa-hint">
        <span class="ava-qa-hint-ico"><i class="fas fa-circle-info"></i></span>
        <span class="ava-qa-hint-txt">
            برای مشاهده‌ی <b>حساب‌های در انتظار پرداخت</b> و <b>فیش‌های واریزی</b> خود، از آیکون
            «<b>حساب‌ها و فیش‌ها</b>» در پایین استفاده کنید<?php echo $avaAcctHasUpdate ? ' — به‌روزرسانی جدید دارید' : ''; ?>.
        </span>
    </div>

    <div class="ava-card ava-sec" data-avasec="quick" data-avasec-title="عملیات سریع" data-avasec-icon="fas fa-bolt">
        <div class="ava-sec-head">
            <div class="ava-sec-title"><i class="fas fa-bolt"></i> عملیات سریع</div>
        </div>
        <div class="ava-qa">
            <div class="ava-qa-item<?php echo $avaAcctHasUpdate ? ' ava-qa-pulse' : ''; ?>" style="order:<?php echo $avaAcctHasUpdate ? '-1' : '5'; ?>;" onclick="avaOpenFsModal('avaRecAccModal')">
                <span class="ava-qa-badge ava-qa-img"><img src="assets/images/qa/accounts.png" alt="" loading="lazy"></span>
                <?php if ($avaAcctHasUpdate): ?><span class="ava-qa-count"><?php echo ava_fa($avaAcctUpdateCount); ?></span><?php endif; ?>
                <span>حساب‌ها و فیش‌ها</span>
            </div>
            <div class="ava-qa-item" onclick="avaOpenServiceModal('transfer')">
                <span class="ava-qa-badge ava-qa-img"><img src="assets/images/qa/transfer.png" alt="" loading="lazy"></span>
                <span>حواله ارزی</span>
            </div>
            <div class="ava-qa-item" onclick="avaOpenServiceModal('settlement')">
                <span class="ava-qa-badge ava-qa-img"><img src="assets/images/qa/settlement.png" alt="" loading="lazy"></span>
                <span>تسویه حساب</span>
            </div>
            <div class="ava-qa-item" onclick="avaOpenTopup()">
                <span class="ava-qa-badge ava-qa-img"><img src="assets/images/qa/topup.png" alt="" loading="lazy"></span>
                <span>شارژ کیف پول</span>
            </div>
            <div class="ava-qa-item" onclick="avaOpenFsModal('avaBeneMainModal'); avaBenMainTab('add', document.querySelector('#avaBeneMainModal [data-bmtab=add]'));">
                <span class="ava-qa-badge ava-qa-img"><img src="assets/images/qa/bene.png" alt="" loading="lazy"></span>
                <span>معرفی حساب</span>
            </div>
            <div class="ava-qa-item" onclick="avaOpenFsModal('avaNewsMegaModal'); if(typeof avaNewsLoad==='function'){avaNewsLoad('fa');}">
                <span class="ava-qa-badge ava-qa-img"><img src="assets/images/qa/news.png" alt="" loading="lazy"></span>
                <span>اخبار</span>
            </div>
        </div>
    </div>

    <div class="ava-slot" data-slot="quick"></div>

    <!-- جایگاه بنرهای مدیریتی -->
    <div class="ava-slot" data-slot="top"></div>

    <div class="ava-slot" data-slot="wallets"></div>

    <!-- ===== (آپدیت جدید) حساب‌های در انتظار پرداخت + فیش‌های دریافتی: اکنون به‌صورت مدال، از آیکون عملیات سریع باز می‌شود ===== -->
    <?php
        // تعیین تب پیش‌فرض: هر کدام آیتم «new» داشته باشد، همان فعال می‌شود.
        // اولویت با حساب‌های در انتظار پرداخت جدید؛ سپس فیش دریافتی جدید.
        $avaRecTabDefault = $avaHasPendingAcc ? 'pending' : ($avaHasNewReceipt ? 'receipts' : 'pending');
    ?>
    <div class="ava-fs-modal" id="avaRecAccModal">
        <div class="ava-fs-top">
            <span><i class="fas fa-file-invoice"></i> حساب‌ها و فیش‌ها</span>
            <button type="button" class="ava-fs-close" onclick="avaCloseFsModal('avaRecAccModal')"><i class="fas fa-times"></i> خروج</button>
        </div>
        <div class="ava-fs-body" style="align-items:stretch;justify-content:flex-start;flex-direction:column;overflow-y:auto;padding:14px;">
        <div class="ava-recacc-card" style="width:100%;"
         id="avaRecAccCard" data-lastseen="<?php echo (int)$avaReceiptLastSeen; ?>" data-default="<?php echo $avaRecTabDefault; ?>">
            <div class="ava-recacc-actions" style="justify-content:flex-end;display:flex;margin-bottom:10px;">
                <!-- دکمه‌های اقدام مخصوص هر تب -->
                <span class="ava-recacc-act" data-for="pending">
                    <?php if (!empty($avaPendingAccountsAll)): ?>
                    <button class="ava-more" onclick="avaOpenPendAccArchive()"><i class="fas fa-list"></i> مشاهده همه</button>
                    <?php endif; ?>
                </span>
                <span class="ava-recacc-act" data-for="receipts" style="display:none;">
                    <button class="ava-more" onclick="avaOpenReceiptArchive()"><i class="fas fa-box-archive"></i> آرشیو</button>
                    <button class="ava-more" onclick="avaSeenReceipts()">خوانده شد</button>
                </span>
            </div>

            <!-- دو گزینه‌ی سوییچ‌شونده -->
        <div class="ava-inv-switch">
            <button type="button" class="ava-inv-tab" data-rectab="pending" onclick="avaRecAccTab('pending', this)">
                <i class="fas fa-file-invoice"></i> حساب‌های در انتظار پرداخت
                <?php if (!empty($avaPendingActive)): ?><span class="ava-inv-count"><?php echo ava_fa(count($avaPendingActive)); ?></span><?php endif; ?>
                <span class="ava-new-badge <?php echo $avaHasPendingAcc ? 'on' : ''; ?>" style="font-size:.5rem;margin-inline-start:4px;">NEW</span>
            </button>
            <button type="button" class="ava-inv-tab" data-rectab="receipts" onclick="avaRecAccTab('receipts', this)">
                <i class="fas fa-file-invoice-dollar"></i> فیش‌های دریافتی
                <?php if (!empty($avaReceipts)): ?><span class="ava-inv-count"><?php echo ava_fa(count($avaReceipts)); ?></span><?php endif; ?>
                <span class="ava-new-badge <?php echo $avaHasNewReceipt ? 'on' : ''; ?>" style="font-size:.5rem;margin-inline-start:4px;">NEW</span>
            </button>
        </div>

        <!-- پنل: حساب‌های در انتظار پرداخت -->
        <div class="ava-rec-pane" id="avaRecPanePending">
            <?php if (empty($avaPendingAccounts)): ?>
                <div class="ava-empty"><i class="fas fa-check-circle"></i> حسابی در انتظار پرداخت ندارید</div>
            <?php else: ?>
            <div class="ava-pendacc-list">
                <?php foreach ($avaPendingAccounts as $pa):
                    $__paJson = htmlspecialchars(json_encode($pa, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES);
                    $__accCnt = count($pa['accounts']);
                    $isDeal    = ($pa['source'] === 'deal');
                    $isTopup   = ($pa['source'] === 'topup');
                    $isInvoice = ($pa['source'] === 'invoice');
                    $__paBg   = $isTopup ? 'rgba(34,197,94,.16)' : ($isDeal ? 'rgba(56,189,248,.16)' : ($isInvoice ? 'rgba(255,215,0,.16)' : 'rgba(108,64,197,.16)'));
                    $__paFg   = $isTopup ? '#22C55E' : ($isDeal ? '#38bdf8' : ($isInvoice ? '#FFD700' : '#a78bfa'));
                    $__paIc   = $isTopup ? 'fa-coins' : ($isDeal ? 'fa-exchange-alt' : ($isInvoice ? 'fa-file-invoice' : 'fa-money-bill-transfer'));
                ?>
                <div class="ava-pendacc-item" onclick='avaOpenPendAcc(<?php echo $__paJson; ?>)'>
                    <div class="ava-pendacc-ic" style="background:<?php echo $__paBg; ?>;color:<?php echo $__paFg; ?>;">
                        <i class="fas <?php echo $__paIc; ?>"></i>
                    </div>
                    <div class="ava-pendacc-body">
                        <div class="ava-pendacc-t">
                            <?php echo htmlspecialchars($pa['title']); ?>
                            <span class="ava-pendacc-code"><?php echo htmlspecialchars($pa['code']); ?></span>
                        </div>
                        <div class="ava-pendacc-s">
                            <?php echo number_format($pa['amount']); ?> <?php echo htmlspecialchars($pa['currency']); ?>
                            · <?php echo ava_fa($__accCnt); ?> حساب برای واریز
                        </div>
                    </div>
                    <div class="ava-pendacc-cta"><i class="fas fa-upload"></i> واریز و فیش</div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- پنل: فیش‌های دریافتی -->
        <div class="ava-rec-pane" id="avaRecPaneReceipts" style="display:none;">
            <?php if (empty($avaReceipts)): ?>
                <div class="ava-empty"><?php echo empty($avaReceiptsAll) ? 'فیشی دریافت نکرده‌اید' : 'فیش خوانده‌نشده‌ای ندارید — برای مشاهده روی «آرشیو» بزنید'; ?></div>
            <?php else: ?>
            <div class="ava-receipts">
                <?php foreach ($avaReceipts as $rc):
                    $isNew = strtotime($rc['date'] ?? '0') > $avaReceiptLastSeen;
                    $__filesJson = htmlspecialchars(json_encode($rc['files'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES);
                    $__cnt = count($rc['files']);
                ?>
                <a class="ava-receipt-item<?php echo $isNew ? ' ava-receipt-new' : ''; ?>" href="javascript:void(0)"
                   onclick='avaViewReceipt(<?php echo $__filesJson; ?>, <?php echo htmlspecialchars(json_encode($rc['type'], JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>, this)'>
                    <div class="ava-list-ic" style="background:<?php echo $rc['color']; ?>22;color:<?php echo $rc['color']; ?>">
                        <i class="fas <?php echo $rc['icon']; ?>"></i>
                    </div>
                    <div class="ava-list-body">
                        <div class="ava-list-t"><?php echo $rc['type']; ?>
                            <?php if ($__cnt > 1): ?><span class="ava-rec-count"><?php echo ava_fa($__cnt); ?> فیش</span><?php endif; ?>
                            <span class="ava-new-badge <?php echo $isNew ? 'on' : ''; ?>" style="font-size:.5rem;">NEW</span>
                        </div>
                        <div class="ava-list-s">فیش دریافتی در <?php echo ava_ago($rc['date']); ?></div>
                    </div>
                    <i class="fas fa-expand" style="color:var(--ava-mut);font-size:.7rem"></i>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        </div><!-- /ava-recacc-card -->
        </div><!-- /ava-fs-body -->
    </div><!-- /avaRecAccModal -->

    <div class="ava-slot" data-slot="accounts"></div>

    <div class="ava-slot" data-slot="bene"></div>

    <!-- ===== بنر تبلیغاتی: هشدار قیمت ===== -->
    <div class="ava-promo p3" onclick="document.getElementById('avaAlertSheet') ? avaOpenSheet('avaAlertSheet') : null">
        <div class="ava-promo-glow"></div>
        <div class="ava-promo-ico"><i class="fas fa-bell"></i></div>
        <div class="ava-promo-txt">
            <div class="ava-promo-t">قیمت هدف شما، هشدار ما 🔔</div>
            <div class="ava-promo-s">با هشدار قیمتی AvaPay هیچ فرصتی را از دست ندهید</div>
        </div>
        <i class="fas fa-chevron-left ava-promo-arrow"></i>
    </div>

    <!-- ===== آگهی‌های فعال بازار (تمام‌عرض) ===== -->
    <div class="ava-card ava-sec" id="avaActiveAdsCard" data-avasec="ads" data-avasec-title="آگهی‌های فعال" data-avasec-icon="fas fa-bullhorn">
        <div class="ava-sec-head">
            <div class="ava-sec-title"><i class="fas fa-bullhorn"></i> آگهی‌های فعال</div>
            <div style="display:flex;align-items:center;gap:8px;">
                <a href="arad.php" class="ava-more">مشاهده همه <i class="fas fa-chevron-left"></i></a>
                <button type="button" onclick="avaOpenAdModal()" class="ava-sec-add" title="ثبت آگهی جدید"><i class="fas fa-plus"></i></button>
            </div>
        </div>
        <?php if (empty($avaActiveAds)): ?>
            <div class="ava-empty"><i class="fas fa-bullhorn"></i> آگهی فعالی موجود نیست</div>
        <?php else: ?>
        <div class="ava-adlist">
            <?php foreach ($avaActiveAds as $ad):
                $m = ava_meta(strtoupper($ad['currency']));
                $isBuy = ($ad['type'] === 'buy');
                $seller = trim(($ad['first_name'] ?? '') . ' ' . ($ad['last_name'] ?? ''));
                if ($seller === '') $seller = 'کاربر';
                $adAvatar = !empty($ad['avatar']) ? $ad['avatar'] : 'default-avatar.png';
                $adAvatarSrc = (strpos($adAvatar, 'http') === 0 || strpos($adAvatar, '/') === 0) ? $adAvatar : ('/ledor/' . ltrim($adAvatar, '/'));
            ?>
            <div class="ava-adrow" onclick="avaOpenOfferModal(<?php echo (int)$ad['id']; ?>, '<?php echo htmlspecialchars(strtoupper($ad['currency']), ENT_QUOTES); ?>', <?php echo (float)$ad['amount']; ?>, <?php echo (float)$ad['price_per_unit']; ?>, '<?php echo $isBuy ? 'buy' : 'sell'; ?>', '<?php echo htmlspecialchars($seller, ENT_QUOTES); ?>')">
                <div class="ava-adrow-ic" style="border:2px solid <?php echo $isBuy ? '#22C55E' : '#FF5A6E'; ?>;">
                    <img src="<?php echo htmlspecialchars($adAvatarSrc); ?>" alt="<?php echo htmlspecialchars($seller, ENT_QUOTES); ?>" loading="lazy" onerror="this.src='/ledor/default-avatar.png'">
                </div>
                <div class="ava-adrow-body">
                    <div class="ava-adrow-t"><?php echo ($isBuy ? 'خرید' : 'فروش') . ' ' . $m['name']; ?></div>
                    <div class="ava-adrow-s">مقدار: <?php echo ava_num($ad['amount'], $m['dec']); ?> · قیمت: <?php echo ava_num($ad['price_per_unit']); ?> تومان</div>
                </div>
                <span class="ava-adrow-cta"><i class="fas fa-tag"></i> پیشنهاد</span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="ava-slot" data-slot="ads"></div>

    <!-- ===== سبد دارایی + نرخ‌های مورد علاقه ===== -->
    <div class="ava-mid-grid">
        <div class="ava-card ava-rates-card" id="avaRatesCard" data-avasec="rates" data-avasec-title="نرخ‌های مورد علاقه" data-avasec-icon="fas fa-star">
            <div class="ava-sec-head">
                <div class="ava-sec-title"><i class="fas fa-star"></i> نرخ‌های مورد علاقه</div>
                <button class="ava-more" id="avaRateAddBtn" onclick="avaRateAddClick()"><i class="fas fa-plus"></i> افزودن</button>
            </div>
            <div class="ava-inv-switch">
                <button type="button" class="ava-inv-tab active" data-rtab="cur" onclick="avaRatesTab('cur', this)"><i class="fas fa-money-bill-wave"></i> نرخ ارز</button>
                <button type="button" class="ava-inv-tab" data-rtab="gold" onclick="avaRatesTab('gold', this)"><i class="fas fa-coins"></i> طلا و سکه</button>
                <button type="button" class="ava-inv-tab" data-rtab="crypto" onclick="avaRatesTab('crypto', this)"><i class="fab fa-bitcoin"></i> ارز دیجیتال</button>
            </div>
            <?php
            $__goldCodes = ['GOLD_MESGHAL','GOLD_18','COIN_EMAMI','GOLD_OUNCE'];
            // رندر یک ردیف نرخ (مشترک)
            $renderRate = function($code, $r, $removable = true) {
                $m   = ava_meta($code);
                $chg = (float)$r['change_24h'];
                $up  = $chg >= 0;
                $isUsd = !empty($m['usd']);
                ob_start(); ?>
                <div class="ava-rate-row" data-cur="<?php echo $code; ?>">
                    <?php if ($removable): ?>
                    <button class="ava-star" data-cur="<?php echo $code; ?>" onclick="avaToggleFav(this)" title="حذف از علاقه‌مندی‌ها"><i class="fas fa-star"></i></button>
                    <?php else: ?>
                    <span class="ava-star" style="opacity:.5;pointer-events:none;"><i class="fas fa-coins" style="color:<?php echo $m['col']; ?>"></i></span>
                    <?php endif; ?>
                    <div class="ava-rate-id">
                        <span class="ava-flag" style="width:24px;height:24px;font-size:.8rem;">
                            <?php if (!empty($m['flag'])): ?>
                                <img src="https://flagcdn.com/w40/<?php echo $m['flag']; ?>.png" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;" onerror="this.style.display='none'">
                            <?php else: ?>
                                <i class="<?php echo $m['ico'] ?? 'fas fa-coins'; ?>" style="color:<?php echo $m['col'] ?? '#7C3AED'; ?>"></i>
                            <?php endif; ?>
                        </span>
                        <div>
                            <div class="ava-rate-name"><?php echo $m['name']; ?></div>
                            <div class="ava-rate-code"><?php echo $m['code']; ?></div>
                        </div>
                    </div>
                    <div class="ava-rate-price" data-price><?php echo ava_num($r['price'], $isUsd ? 2 : 0); ?><?php echo $isUsd ? '<span class="ava-rate-cur">$</span>' : ''; ?></div>
                    <div class="ava-rate-chg <?php echo $up ? 'up' : 'dn'; ?>" data-chg><?php echo ($up ? '+' : '−') . ava_fa(number_format(abs($chg), 2)); ?>٪</div>
                </div>
                <?php return ob_get_clean();
            };
            ?>
            <!-- تب نرخ ارز -->
            <div class="ava-rates-list ava-rates-pane" id="avaRatesList">
            <?php
            // اصلاح: فقط نرخ‌های واقعاً مورد علاقه‌ی خودِ کاربر نمایش داده شود؛
            // قبلاً وقتی کاربر هیچ ارزی را ذخیره نکرده بود، یک فهرست ثابت
            // (دلار/یورو/تتر/...) به‌جای آن نشان داده می‌شد — با همان ستاره‌ی
            // «فعال»، انگار واقعاً انتخاب خودِ کاربر بودند. حالا اگر کاربر
            // هیچ علاقه‌مندی‌ای ذخیره نکرده باشد، فقط پیام «افزودن ارز» دیده
            // می‌شود، نه چند ارز ساختگی با ظاهر فعال/ستاره‌دار.
            $__favShow = $avaFavs;
            $__shown = 0;
            foreach ($__favShow as $code):
                if (!isset($avaRates[$code]) || in_array($code, $__goldCodes, true)) continue;
                if ($__shown++ >= 8) break;
                echo $renderRate($code, $avaRates[$code], true);
            endforeach; ?>
            <?php if ($__shown === 0): ?>
                <div class="ava-empty">ارزی انتخاب نشده است. با «افزودن ارز» شروع کنید.</div>
            <?php endif; ?>
            </div>
            <!-- تب طلا و سکه -->
            <div class="ava-rates-list ava-rates-pane" id="avaGoldList" style="display:none;">
            <?php
            $__gShown = 0;
            foreach ($__goldCodes as $code):
                if (!isset($avaRates[$code])) continue;
                $__gShown++;
                echo $renderRate($code, $avaRates[$code], false);
            endforeach; ?>
            <?php if ($__gShown === 0): ?>
                <div class="ava-empty">در حال دریافت قیمت طلا و سکه از الان‌چند...</div>
            <?php endif; ?>
            </div>
            <!-- تب ارز دیجیتال (واچ‌لیست) -->
            <div class="ava-rates-list ava-rates-pane" id="avaCryptoWatchList" style="display:none;"
                 data-watch='<?php echo htmlspecialchars(json_encode($avaCryptoWatch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES); ?>'>
                <?php if (empty($avaCryptoWatch)): ?>
                    <div class="ava-empty" id="avaCryptoWatchEmpty">ارز دیجیتالی در واچ‌لیست نیست. با «افزودن» شروع کنید.</div>
                <?php else: ?>
                    <div class="ava-empty"><i class="fas fa-spinner fa-spin"></i> در حال دریافت قیمت‌ها…</div>
                <?php endif; ?>
            </div>
            <div style="margin-top:auto;padding-top:10px;">
                <span class="ava-rate-live"><i class="fas fa-circle"></i> به‌روزرسانی لحظه‌ای</span>
                <button class="ava-more" onclick="showCurrencyRate()">مشاهده همه نرخ‌ها <i class="fas fa-chevron-left"></i></button>
            </div>
        </div>
        <div class="ava-card" id="avaPortCard" data-avasec="portfolio" data-avasec-title="سبد دارایی من" data-avasec-icon="fas fa-chart-pie">
            <div class="ava-sec-head">
                <div class="ava-sec-title"><i class="fas fa-chart-pie"></i> سبد دارایی من</div>
                <span style="width:26px;height:26px;border-radius:50%;background:rgba(168,85,247,.16);border:1px solid rgba(168,85,247,.3);display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-circle-info" style="color:#C4B5FD;font-size:.66rem"></i>
                </span>
            </div>

            <?php
            // ===============================================================
            // (آپدیت) داده‌ی نمودار «سبد دارایی من» — دقیقاً همان نمودار کارت
            // نمودار transactions.php («جریان نقدی» بر اساس رکوردهای واقعی
            // جدول transactions، نه بازسازی بالانس روزانه). عیناً همان منطق
            // محاسبه‌ی transactions.php (خط ~303 آن فایل) اینجا هم اجرا می‌شود
            // تا خروجی/رفتار دو کارت کاملاً یکی باشد.
            // ===============================================================
            $__avaChartCurs   = ['USD', 'EUR', 'USDT', 'IRR'];
            $__avaChartMonths = [];
            $__avaChartLabels = [];
            for ($__i = 11; $__i >= 0; $__i--) {
                $__ts = strtotime(date('Y-m-01') . " -$__i month");
                $__avaChartMonths[] = date('Y-m', $__ts);
                $__avaChartLabels[] = date('M y', $__ts);
            }
            $__avaMonthIdx = array_flip($__avaChartMonths);

            $__avaChartData = [];
            foreach ($__avaChartCurs as $__c) {
                $__avaChartData[$__c] = [
                    'in' => array_fill(0, 12, 0.0), 'out' => array_fill(0, 12, 0.0), 'net' => array_fill(0, 12, 0.0),
                    'tin' => 0.0, 'tout' => 0.0, 'count' => 0,
                ];
            }
            $__avaChartSql = "SELECT currency, DATE_FORMAT(created_at, '%Y-%m') AS ym, sender_id, receiver_id, amount, type
                         FROM transactions
                         WHERE (sender_id = ? OR receiver_id = ?) AND status = 'completed'
                           AND created_at >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 11 MONTH)";
            $__avaChartStmt = $conn->prepare($__avaChartSql);
            if ($__avaChartStmt) {
                $__avaChartStmt->bind_param("ii", $userId, $userId);
                $__avaChartStmt->execute();
                $__avaChartRes = $__avaChartStmt->get_result();
                while ($__row = $__avaChartRes->fetch_assoc()) {
                    $__cur = strtoupper($__row['currency'] ?? '');
                    if (!isset($__avaChartData[$__cur])) continue;
                    if (!isset($__avaMonthIdx[$__row['ym']])) continue;
                    $__k   = $__avaMonthIdx[$__row['ym']];
                    $__amt = (float)$__row['amount'];
                    $__isIn = ((int)$__row['receiver_id'] === (int)$userId) || $__row['type'] === 'deposit';
                    if ($__row['type'] === 'withdrawal') $__isIn = false;
                    if ($__isIn) { $__avaChartData[$__cur]['in'][$__k]  += $__amt; $__avaChartData[$__cur]['tin']  += $__amt; }
                    else         { $__avaChartData[$__cur]['out'][$__k] += $__amt; $__avaChartData[$__cur]['tout'] += $__amt; }
                    $__avaChartData[$__cur]['count']++;
                }
            }
            foreach ($__avaChartCurs as $__c) {
                for ($__k = 0; $__k < 12; $__k++) {
                    $__avaChartData[$__c]['net'][$__k] = round($__avaChartData[$__c]['in'][$__k] - $__avaChartData[$__c]['out'][$__k], 4);
                    $__avaChartData[$__c]['in'][$__k]  = round($__avaChartData[$__c]['in'][$__k], 4);
                    $__avaChartData[$__c]['out'][$__k] = round($__avaChartData[$__c]['out'][$__k], 4);
                }
            }
            $__avaChartMeta = [
                'USD'  => ['label' => 'US Dollar', 'fa' => 'دلار',  'sym' => '$', 'color' => '#22C55E', 'icon' => 'fas fa-dollar-sign',     'dec' => 2],
                'EUR'  => ['label' => 'Euro',      'fa' => 'یورو',  'sym' => '€', 'color' => '#38BDF8', 'icon' => 'fas fa-euro-sign',       'dec' => 2],
                'USDT' => ['label' => 'Tether',    'fa' => 'تتر',   'sym' => '₮', 'color' => '#26A17B', 'icon' => 'fas fa-coins',           'dec' => 2],
                'IRR'  => ['label' => 'Toman',     'fa' => 'تومان', 'sym' => 'T', 'color' => '#A855F7', 'icon' => 'fas fa-money-bill-wave', 'dec' => 0],
            ];
            ?>

            <div class="tx-cur-tabs" id="avaTxCurTabs">
                <?php foreach ($__avaChartCurs as $__ci => $__cc): $__cm = $__avaChartMeta[$__cc]; ?>
                <button type="button" class="tx-cur-tab<?php echo $__ci === 0 ? ' on' : ''; ?>"
                        data-cur="<?php echo $__cc; ?>" data-color="<?php echo $__cm['color']; ?>"
                        onclick="avaTxSelectCur('<?php echo $__cc; ?>', this)">
                    <i class="<?php echo $__cm['icon']; ?>"></i>
                    <span class="t"><?php echo $__cc === 'IRR' ? 'TOMAN' : $__cc; ?></span>
                    <span class="s"><?php echo $__cm['fa']; ?></span>
                </button>
                <?php endforeach; ?>
            </div>

            <div class="tx-chart-head" style="margin-bottom:10px;">
                <div class="tx-chart-title"><i class="fas fa-chart-area"></i> <span id="avaTxChartTitle">Cash Flow</span></div>
                <div class="tx-range">
                    <button type="button" class="on" data-mode="net" onclick="avaTxSetMode('net', this)">Net</button>
                    <button type="button" data-mode="both" onclick="avaTxSetMode('both', this)">In / Out</button>
                    <button type="button" data-mode="bar" onclick="avaTxSetMode('bar', this)">Bars</button>
                </div>
            </div>

            <div class="tx-chart-wrap">
                <canvas id="avaTxChart"></canvas>
                <div class="tx-chart-empty" id="avaTxChartEmpty" style="display:none;">
                    <i class="fas fa-chart-line"></i>
                    <span>هنوز تراکنشی برای این ارز ثبت نشده</span>
                </div>
            </div>

            <div class="tx-chart-legend" id="avaTxLegend"></div>

            <div class="tx-mini-stats">
                <div class="tx-mini in"><div class="l">دریافتی</div><div class="v" id="avaTxMiniIn">0</div></div>
                <div class="tx-mini out"><div class="l">ارسالی</div><div class="v" id="avaTxMiniOut">0</div></div>
                <div class="tx-mini net"><div class="l">خالص</div><div class="v" id="avaTxMiniNet">0</div></div>
            </div>

            <script>
                window.AVA_TX_DATA   = <?php echo json_encode($__avaChartData, JSON_UNESCAPED_UNICODE); ?>;
                window.AVA_TX_META   = <?php echo json_encode($__avaChartMeta, JSON_UNESCAPED_UNICODE); ?>;
                window.AVA_TX_LABELS = <?php echo json_encode($__avaChartLabels); ?>;
            </script>

            <div class="ava-port-foot" style="padding-top:10px;">
                <a href="transactions.php" class="ava-more">مشاهده جزئیات سبد دارایی <i class="fas fa-chevron-left"></i></a>
            </div>
        </div>

        <?php
        // فهرست ارزهای موجود برای انتخاب‌گر (فقط ارزها، بدون طلا)
        $__allCurs = [];
        $__seedRates = [];
        foreach ($avaRates as $code => $r) {
            $m = ava_meta($code);
            if (!in_array($code, $__goldCodes, true)) {
                $__allCurs[] = [
                    'code' => $code,
                    'name' => $m['name'],
                    'flag' => $m['flag'] ?? '',
                    'ico'  => $m['ico'] ?? 'fas fa-coins',
                    'col'  => $m['col'] ?? '#7C3AED',
                    'fav'  => in_array($code, $avaFavs),
                ];
            }
            $__seedRates[$code] = ['price' => round((float)$r['price'], 2), 'change' => round((float)$r['change_24h'], 2), 'usd' => !empty($m['usd'])];
        }
        ?>
    </div>
    <script>
        window.__avaAllCurs = <?php echo json_encode($__allCurs, JSON_UNESCAPED_UNICODE); ?>;
        window.__avaSeedRates = <?php echo json_encode($__seedRates, JSON_UNESCAPED_UNICODE); ?>;
        window.__avaGoldCodes = <?php echo json_encode($__goldCodes, JSON_UNESCAPED_UNICODE); ?>;
        <?php
        // آرشیو کامل فیش‌ها برای مدال آرشیو
        $__arch = [];
        foreach ($avaReceiptsAll as $rc) {
            $__arch[] = [
                'type'  => $rc['type'],
                'icon'  => $rc['icon'],
                'color' => $rc['color'],
                'files' => $rc['files'],
                'ago'   => 'دریافت در ' . ava_ago($rc['date']),
            ];
        }
        ?>
        window.__avaReceiptsAll = <?php echo json_encode($__arch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        // (آپدیت ۲) آرشیو کامل حساب‌های ارسالی ادمین
        window.__avaPendingAccountsAll = <?php echo json_encode($avaPendingAccountsAll, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    </script>

    <div class="ava-slot" data-slot="portfolio"></div>


    <!-- ===== نوار کشویی فعالیت و دسترسی سریع: تراکنش‌ها، هشدارها، خدمات، سفارشات ===== -->
    <div class="ava-quad-label"><i class="fas fa-layer-group"></i> فعالیت و دسترسی سریع شما</div>
    <div class="ava-quad-scroll">
        <!-- تراکنش‌های اخیر -->
        <div class="ava-card" data-avasec="tx" data-avasec-title="تراکنش‌های اخیر" data-avasec-icon="fas fa-receipt">
            <div class="ava-sec-head">
                <div class="ava-sec-title"><i class="fas fa-receipt"></i> تراکنش‌های اخیر</div>
                <a href="transactions.php" class="ava-more">مشاهده همه</a>
            </div>
            <?php if (empty($transactions)): ?>
                <div class="ava-empty">تراکنشی ثبت نشده است</div>
            <?php else: foreach (array_slice($transactions, 0, 4) as $tx):
                $isIn = ((int)($tx['receiver_id'] ?? 0) === (int)$userId);
                $m    = ava_meta(strtoupper($tx['currency']));
                $col  = $isIn ? '#22C55E' : '#FF5A6E';
                $lbl  = [
                    'send'=>'ارسال', 'receive'=>'دریافت', 'deposit'=>'واریز',
                    'withdrawal'=>'برداشت', 'admin_credit'=>'افزایش موجودی', 'admin_debit'=>'کاهش موجودی'
                ][$tx['type'] ?? ''] ?? 'تراکنش';
            ?>
            <div class="ava-list-row">
                <div class="ava-list-ic" style="background:<?php echo $isIn ? 'rgba(34,197,94,.14)' : 'rgba(255,90,110,.14)'; ?>;color:<?php echo $col; ?>">
                    <i class="fas <?php echo $isIn ? 'fa-arrow-down' : 'fa-arrow-up'; ?>"></i>
                </div>
                <div class="ava-list-body">
                    <div class="ava-list-t"><?php echo $lbl . ' ' . htmlspecialchars($m['code']); ?></div>
                    <div class="ava-list-s"><?php echo ava_ago($tx['created_at']); ?></div>
                </div>
                <div class="ava-list-v <?php echo $isIn ? 'up' : 'dn'; ?>">
                    <?php echo ($isIn ? '+' : '−') . ava_num($tx['amount'], $m['dec']); ?>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>

    <!-- ===== سفارشات و حواله‌های فعال (کادر کوچک، کنار خدمات محبوب) ===== -->
    <div class="ava-card ava-ordtr-mini" id="avaOrdTrCard" data-avasec="orders" data-avasec-title="سفارشات و حواله‌های فعال" data-avasec-icon="fas fa-list-check">
        <div class="ava-sec-head">
            <div class="ava-sec-title"><i class="fas fa-list-check"></i> سفارشات و حواله‌ها</div>
            <span class="ava-ordtr-act" data-for="transfers" style="display:none;">
                <button class="ava-add-mini" onclick="avaOpenTransferModal()" title="ارسال حواله جدید"><i class="fas fa-plus"></i></button>
            </span>
        </div>
        <?php
        $__stMap = ['pending'=>['t'=>'در انتظار','c'=>'wait'], 'approved'=>['t'=>'تأیید شده','c'=>'ok'], 'rejected'=>['t'=>'رد شده','c'=>'no']];
        $__hasOrders = !empty($avaOrders) || !empty($avaServiceOrders);
        $__ordCount  = count($avaOrders) + count($avaServiceOrders);
        $__trCount   = count($avaTransfers);
        ?>
        <div class="ava-inv-switch">
            <button type="button" class="ava-inv-tab active" data-ordtab="orders" onclick="avaOrdTrTab('orders', this)">
                سفارشات
                <?php if ($__ordCount > 0): ?><span class="ava-inv-count"><?php echo ava_fa($__ordCount); ?></span><?php endif; ?>
            </button>
            <button type="button" class="ava-inv-tab" data-ordtab="transfers" onclick="avaOrdTrTab('transfers', this)">
                حواله‌ها
                <?php if ($__trCount > 0): ?><span class="ava-inv-count"><?php echo ava_fa($__trCount); ?></span><?php endif; ?>
            </button>
        </div>

        <div class="ava-ordtr-pane" id="avaOrdPaneOrders">
        <?php if (!$__hasOrders): ?>
            <div class="ava-empty">سفارش فعالی ندارید</div>
        <?php else: ?>
            <?php foreach ($avaOrders as $o): $m = ava_meta(strtoupper($o['currency'])); ?>
            <div class="ava-order">
                <div class="ava-order-h">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span class="ava-flag" style="width:26px;height:26px;font-size:.8rem;">
                            <?php if (!empty($m['flag'])): ?>
                                <img src="https://flagcdn.com/w40/<?php echo $m['flag']; ?>.png" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                            <?php else: ?>
                                <i class="<?php echo $m['ico'] ?? 'fas fa-coins'; ?>" style="color:<?php echo $m['col'] ?? '#7C3AED'; ?>"></i>
                            <?php endif; ?>
                        </span>
                        <span class="ava-order-t"><?php echo ($o['type'] === 'buy' ? 'خرید' : 'فروش') . ' ' . htmlspecialchars($m['code']); ?></span>
                    </div>
                    <span class="ava-badge wait">در انتظار</span>
                </div>
                <div class="ava-order-meta">
                    مقدار: <?php echo ava_num($o['amount'], $m['dec']); ?><br>
                    قیمت: <?php echo ava_num($o['price_per_unit']); ?> تومان
                </div>
            </div>
            <?php endforeach; ?>

            <?php foreach ($avaServiceOrders as $so):
                $st = $__stMap[$so['status']] ?? ['t'=>$so['status'],'c'=>'wait'];
            ?>
            <div class="ava-order">
                <div class="ava-order-h">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span class="ava-flag" style="width:26px;height:26px;font-size:.8rem;overflow:hidden;">
                            <?php if (!empty($so['service_icon_image'])): ?>
                                <img src="<?php echo htmlspecialchars($so['service_icon_image']); ?>" alt="" style="width:100%;height:100%;object-fit:cover;">
                            <?php else: ?>
                                <i class="<?php echo htmlspecialchars($so['service_icon'] ?: 'fas fa-bolt'); ?>" style="color:<?php echo htmlspecialchars($so['service_color'] ?: '#7C3AED'); ?>"></i>
                            <?php endif; ?>
                        </span>
                        <span class="ava-order-t"><?php echo htmlspecialchars($so['service_label'] ?: 'سفارش خدمت'); ?></span>
                    </div>
                    <span class="ava-badge <?php echo $st['c']; ?>"><?php echo $st['t']; ?></span>
                </div>
                <div class="ava-order-meta">
                    در تاریخ <?php echo ava_fa(date('Y/m/d', strtotime($so['created_at']))); ?>
                    <span style="opacity:.7">— <?php echo ava_ago($so['created_at']); ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
        </div>

        <div class="ava-ordtr-pane" id="avaOrdPaneTransfers" style="display:none;">
        <?php if (empty($avaTransfers)): ?>
            <div class="ava-empty">حواله فعالی ندارید</div>
        <?php else: foreach ($avaTransfers as $t):
            $st = $avaTrStatus[$t['status']] ?? ['t'=>$t['status'],'c'=>'wait','i'=>'fa-clock'];
        ?>
        <div class="ava-list-row" style="cursor:pointer" onclick="avaOpenTransferModal()">
            <div class="ava-list-body">
                <div class="ava-list-t">حواله <?php echo htmlspecialchars($t['country']); ?></div>
                <div class="ava-list-s">شماره پیگیری: <?php echo ava_fa(htmlspecialchars($t['tracking_code'])); ?></div>
                <div class="ava-list-s" style="color:<?php echo $st['c'] === 'ok' ? 'var(--ava-grn)' : ($st['c'] === 'no' ? 'var(--ava-red)' : '#FBBF24'); ?>;font-weight:700;">
                    <?php echo $st['t']; ?>
                </div>
            </div>
            <div class="ava-list-ic" style="background:<?php echo $st['c'] === 'ok' ? 'rgba(34,197,94,.14)' : ($st['c'] === 'no' ? 'rgba(255,90,110,.14)' : 'rgba(251,191,36,.14)'); ?>;color:<?php echo $st['c'] === 'ok' ? '#22C55E' : ($st['c'] === 'no' ? '#FF5A6E' : '#FBBF24'); ?>">
                <i class="fas <?php echo $st['i']; ?>"></i>
            </div>
        </div>
        <?php endforeach; endif; ?>
        </div>
    </div>

        <!-- هشدارهای قیمت: یک لیست واحد (آگهی + نرخ ارز + ارز دیجیتال با هم) -->
        <div class="ava-card" data-avasec="alerts" data-avasec-title="هشدارهای قیمت" data-avasec-icon="fas fa-bell">
            <div class="ava-sec-head">
                <div class="ava-sec-title"><i class="fas fa-bell"></i> هشدارهای قیمت
                    <?php if (!empty($avaAlerts)): ?><span class="ava-inv-count"><?php echo ava_fa(count($avaAlerts)); ?></span><?php endif; ?>
                </div>
            </div>

            <?php
            // رندر یک ردیف هشدار (همه‌ی انواع در یک لیست؛ نوع با یک نشان مشخص می‌شود)
            $renderAlert = function($a) {
                $type = $a['alert_type'] ?? 'ad';
                $isCrypto = ($type === 'crypto');
                // متادیتای نمایش
                if ($isCrypto) {
                    $name = $a['coin_name'] ?: ucfirst($a['currency']);
                    $ico  = 'fab fa-bitcoin'; $icoCol = '#F7931A'; $flag = '';
                } else {
                    $m = ava_meta(strtoupper($a['currency']));
                    $name = $m['name']; $ico = $m['ico'] ?? 'fas fa-coins';
                    $icoCol = $m['col'] ?? '#7C3AED'; $flag = $m['flag'] ?? '';
                }
                // نشان نوع هشدار
                $badge = [
                    'ad'     => ['t'=>'آگهی',        'c'=>'#38BDF8', 'bg'=>'rgba(56,189,248,.14)'],
                    'rate'   => ['t'=>'نرخ ارز',     'c'=>'#A855F7', 'bg'=>'rgba(168,85,247,.14)'],
                    'crypto' => ['t'=>'ارز دیجیتال', 'c'=>'#F7931A', 'bg'=>'rgba(247,147,26,.14)'],
                ][$type] ?? ['t'=>'آگهی','c'=>'#38BDF8','bg'=>'rgba(56,189,248,.14)'];
                // واحد قیمت
                $priceTxt = $isCrypto
                    ? ('$' . rtrim(rtrim(number_format((float)$a['target_price'], 4), '0'), '.'))
                    : (ava_num($a['target_price']) . ' تومان');
                $chans = [];
                if ((int)$a['notify_telegram']) $chans[] = '<i class="fab fa-telegram" title="تلگرام"></i>';
                if ((int)$a['notify_email'])    $chans[] = '<i class="fas fa-envelope" title="ایمیل"></i>';
                if ((int)$a['notify_toast'])    $chans[] = '<i class="fas fa-bell" title="اعلان درون‌برنامه"></i>';
                $iv = (int)($a['check_interval'] ?? 60);
                $ivTxt = $iv < 60 ? ('هر ' . ava_fa($iv) . ' دقیقه')
                       : ($iv < 1440 ? ('هر ' . ava_fa(intdiv($iv, 60)) . ' ساعت') : 'هر ۲۴ ساعت');
                ob_start(); ?>
                <div class="ava-alert-row" data-id="<?php echo (int)$a['id']; ?>">
                    <span class="ava-flag" style="width:28px;height:28px;font-size:.8rem;">
                        <?php if (!empty($flag)): ?>
                            <img src="https://flagcdn.com/w40/<?php echo $flag; ?>.png" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;" onerror="this.style.display='none'">
                        <?php else: ?>
                            <i class="<?php echo $ico; ?>" style="color:<?php echo $icoCol; ?>"></i>
                        <?php endif; ?>
                    </span>
                    <div class="ava-list-body">
                        <div class="ava-list-t">
                            <?php echo htmlspecialchars($name); ?>
                            <span class="ava-alert-badge" style="color:<?php echo $badge['c']; ?>;background:<?php echo $badge['bg']; ?>;"><?php echo $badge['t']; ?></span>
                        </div>
                        <div class="ava-list-s">
                            وقتی قیمت <?php echo $a['direction'] === 'below' ? 'کمتر' : 'بیشتر'; ?> از
                            <?php echo $priceTxt; ?> برسد
                            <span class="ava-alert-chans"><?php echo implode(' ', $chans); ?></span>
                            <span class="ava-alert-iv"><i class="fas fa-clock"></i> <?php echo $ivTxt; ?></span>
                        </div>
                    </div>
                    <label class="ava-switch">
                        <input type="checkbox" <?php echo $a['is_active'] ? 'checked' : ''; ?> onchange="avaAlertToggle(<?php echo (int)$a['id']; ?>, this.checked)">
                        <i></i>
                    </label>
                    <button class="ava-alert-del" onclick="avaAlertDelete(<?php echo (int)$a['id']; ?>, this)" title="حذف"><i class="fas fa-trash-alt"></i></button>
                </div>
                <?php return ob_get_clean();
            };
            ?>

            <div class="ava-alert-pane" id="avaAlertList">
                <?php if (empty($avaAlerts)): ?>
                    <div class="ava-empty">هنوز هشداری ثبت نشده است</div>
                <?php else: foreach ($avaAlerts as $a) echo $renderAlert($a); endif; ?>
            </div>

            <button class="ava-add-alert" onclick="avaOpenAlertForm()"><i class="fas fa-plus-circle"></i> افزودن هشدار جدید</button>
        </div>

    </div>

    <?php
    // ===================================================================
    // (آپدیت) گجت هوشمند بازار — پیش‌بینی قیمت + تمایل بازار برای دلار/یورو/تتر
    // زیر نوار «فعالیت و دسترسی سریع شما». بازه‌ی پیش‌فرض رندر اولیه «۱ ماهه»
    // است؛ بازه‌های دیگر (۱ روزه/۱ ساله) با اکشن AJAX «ai_history» گرفته می‌شوند.
    // «تمایل بازار» و «بهترین قیمت» به بازه‌ی زمانی ربطی ندارند (از آگهی‌های
    // فعال همین الان محاسبه می‌شوند)، فقط یک‌بار در همین‌جا محاسبه می‌شوند.
    // ===================================================================
    $__aiCurs = ['USD', 'EUR', 'USDT'];
    $__aiData = [];
    // (جدید) بررسی پیش‌بینی‌هایی که زمانشان رسیده — سنگین نیست (حداکثر چند ردیف)
    // ولی همین‌قدر هم لازم نیست هر بار اجرا شود؛ هر ۱۰ دقیقه یک‌بار کافی‌ست.
    if (avapay_throttled('ai_predictions_resolve', 600)) {
        ava_ai_resolve_predictions($conn);
        ava_ai_resolve_predictions_4h($conn);
    }
    // (جدید) پاک‌سازی تارگت‌های خورده‌شده‌ی قدیمی‌تر از ۲ هفته — همان throttle
    // کافی‌ست، نیازی به اجرای هر بار نیست
    if (avapay_throttled('ai_predictions_cleanup', 3600)) {
        ava_ai_cleanup_old_predictions($conn);
    }
    // (جدید) تارگت‌های ۴ ساعته‌ی ارزهای دیجیتالِ خودِ همین کاربر را هم به‌روز نگه دار
    // (هر کوین خودش throttle ۴ساعته‌ی جداگانه دارد، پس این حلقه سبک است)
    try {
        $__myCoinsRes = $conn->query("SELECT coin_id FROM user_ai_predict_coins WHERE user_id = " . (int)$userId);
        if ($__myCoinsRes) {
            while ($__mc = $__myCoinsRes->fetch_assoc()) {
                ava_ai_crypto_predict_4h($conn, $__mc['coin_id']);
            }
        }
    } catch (\Throwable $e) {}
    foreach ($__aiCurs as $__ac) {
        $__rangeData = ava_ai_compute_range($conn, $__ac, '1d');
        // (جدید) به‌جای فقط یک هدف (بر اساس شیب رگرسیون)، هم یک هدفِ صعودی
        // (شکستن سقف = سقف+دامنه) و هم یک هدفِ نزولی (شکستن کف = کف-دامنه)
        // ثبت می‌شود — دقیقاً همان دو سناریویی که در «تحلیل روند» زیر نمودار
        // توضیح داده می‌شود، حالا واقعاً ردیابی و بعداً بررسی هم می‌شوند.
        $__rng = max(0, (float)$__rangeData['high'] - (float)$__rangeData['low']);
        if ($__rng > 0) {
            $__upT = (float)$__rangeData['high'] + ($__rng * 0.1);
            $__downT = max(0, (float)$__rangeData['low'] - ($__rng * 0.1));
            ava_ai_log_prediction($conn, $__ac, 'fiat', $__rangeData['last'], $__upT, 'up');
            ava_ai_log_prediction($conn, $__ac, 'fiat', $__rangeData['last'], $__downT, 'down');
        }
        // (جدید) تارگت‌های نزدیک ۴ ساعته — جداگانه، خودش throttle دارد
        ava_ai_fiat_predict_4h($conn, $__ac);
        $__accuracy = ava_ai_get_accuracy($conn, $__ac, 'fiat');

        // تمایل بازار: نسبت آگهی‌های خرید فعال به کل آگهی‌های فعال همین ارز
        $__buyCount = 0; $__sellCount = 0;
        $__adStmt = $conn->prepare("SELECT type, COUNT(*) AS c FROM user_ads WHERE UPPER(currency) = ? AND status = 'active' GROUP BY type");
        if ($__adStmt) {
            $__adStmt->bind_param("s", $__ac);
            $__adStmt->execute();
            $__adRes = $__adStmt->get_result();
            while ($__adRow = $__adRes->fetch_assoc()) {
                if ($__adRow['type'] === 'buy') $__buyCount = (int)$__adRow['c'];
                elseif ($__adRow['type'] === 'sell') $__sellCount = (int)$__adRow['c'];
            }
            $__adStmt->close();
        }
        $__totalAds = $__buyCount + $__sellCount;
        $__buyPct = $__totalAds > 0 ? (int)round(($__buyCount / $__totalAds) * 100) : 50; // بدون آگهی → خنثی

        // بهترین قیمت برای خرید ارز = ارزان‌ترین آگهی «فروش» فعال (فروشنده کم‌ترین قیمت)
        $__bestBuyPrice = 0;
        $__bestRes = $conn->query("SELECT MIN(price_per_unit) AS mn FROM user_ads WHERE UPPER(currency) = '" . $conn->real_escape_string($__ac) . "' AND status = 'active' AND type = 'sell'");
        if ($__bestRes && $__bestRes->num_rows) {
            $__bestRow = $__bestRes->fetch_assoc();
            $__bestBuyPrice = $__bestRow['mn'] !== null ? (float)$__bestRow['mn'] : 0;
        }

        // «باز شدن» برای ردیف OHLC: اولین قیمت ثبت‌شده در همین بازه
        $__openPrice = !empty($__rangeData['history']) ? $__rangeData['history'][0] : $__rangeData['last'];

        // عمق بازار: چگالی آگهی‌های فعال در بازه‌های قیمتی مختلف
        $__depth = ava_ai_market_depth($conn, $__ac);

        $__m = ava_meta($__ac);
        $__aiData[$__ac] = array_merge($__rangeData, [
            'buyPct'       => $__buyPct,
            'bestBuyPrice' => round($__bestBuyPrice, 0),
            'open'         => round($__openPrice, 0),
            'depth'        => $__depth,
            'name'         => $__m['name'],
            'accuracy'     => $__accuracy,
        ]);
    }

    // ارزهای دیجیتالی که کاربر خودش برای این ابزار اضافه کرده
    $__aiUserCoins = [];
    $__coinSelCols = $__aiCoinImageOk ? "coin_id, coin_name, coin_symbol, coin_image" : "coin_id, coin_name, coin_symbol";
    try {
        $__coinsRes = @$conn->query("SELECT {$__coinSelCols} FROM user_ai_predict_coins WHERE user_id = " . (int)$userId . " ORDER BY id ASC");
        if ($__coinsRes) { while ($__coinRow = $__coinsRes->fetch_assoc()) { $__aiUserCoins[] = $__coinRow; } }
    } catch (\Throwable $e) { $__aiUserCoins = []; }
    ?>

    <!-- ===== گجت خلاصه‌ی «پیش‌بینی هوشمند بازار» — با زدنش کل ابزار در مدال باز می‌شود ===== -->
    <div class="ava-quad-label"><i class="fas fa-brain"></i> پیش‌بینی هوشمند ارز</div>
    <?php $__gChg = (float)($__aiData['USD']['change'] ?? 0); ?>
    <div class="ava-smart-gadget" data-avasec="smartai" data-avasec-title="پیش‌بینی هوشمند ارز" data-avasec-icon="fas fa-brain" onclick="avaGadgetOpenFullscreen()">
        <div class="ava-smart-gadget-spark-bg" id="avaGadgetSparkBg"><?php echo ava_ai_sparkline_area_svg($__aiData['USD']['history'], $__gChg >= 0 ? '#22C55E' : '#FF5A6E', 340, 120); ?></div>

        <div class="ava-smart-gadget-head">
            <div class="ava-smart-gadget-badge"><img src="assets/img/forecast-icon.svg" alt="پیش‌بینی" loading="lazy"><span class="ava-smart-gadget-pulse"></span></div>
            <span class="ava-smart-gadget-chip"><span class="ava-smart-gadget-livedot"></span> زنده · تحلیل AI</span>
        </div>

        <div class="ava-smart-gadget-fade" id="avaGadgetFade">
            <div class="ava-smart-gadget-cur" id="avaGadgetCur"><?php echo htmlspecialchars($__aiData['USD']['name']); ?></div>
            <div class="ava-smart-gadget-row">
                <span class="ava-smart-gadget-price" id="avaGadgetPrice"><?php echo number_format($__aiData['USD']['last']); ?> <small>تومان</small></span>
                <span class="ava-smart-gadget-chg <?php echo $__gChg >= 0 ? 'up' : 'down'; ?>" id="avaGadgetChg">
                    <i class="fas fa-arrow-trend-<?php echo $__gChg >= 0 ? 'up' : 'down'; ?>"></i>
                    <?php echo ($__gChg >= 0 ? '+' : '') . $__gChg; ?>٪
                </span>
            </div>
        </div>

        <div class="ava-smart-gadget-foot">
            <div class="ava-smart-gadget-dots" id="avaGadgetDots">
                <span class="on"></span><span></span><span></span>
            </div>
            <span class="ava-smart-gadget-cta">تحلیل کامل <i class="fas fa-chevron-left"></i></span>
        </div>
    </div>

    <script>
        window.AVA_SMART_DATA = <?php echo json_encode($__aiData, JSON_UNESCAPED_UNICODE); ?>;
        window.AVA_SMART_COINS = <?php echo json_encode($__aiUserCoins, JSON_UNESCAPED_UNICODE); ?>;
    </script>

    <!-- ===== شیت افزودن ارز دیجیتال به «گجت هوشمند بازار» ===== -->
    <div class="ava-sheet" id="avaAiCoinPickerSheet">
        <div class="ava-sheet-bd" onclick="avaCloseSheet('avaAiCoinPickerSheet')"></div>
        <div class="ava-sheet-in">
            <button class="ava-sheet-close" onclick="avaCloseSheet('avaAiCoinPickerSheet')"><i class="fas fa-times"></i></button>
            <div class="ava-sec-title" style="margin-bottom:6px;"><i class="fas fa-brain" style="color:#A855F7;"></i> افزودن ارز دیجیتال</div>
            <div class="ava-list-s" style="margin-bottom:10px;color:var(--ava-mut);font-size:.62rem;">ارزی که اضافه کنید، نمودار و پیش‌بینی‌اش نسبت به تتر ساخته می‌شود (حداکثر ۶ ارز).</div>
            <input type="text" id="avaAiCoinSearch" class="ava-rate-search" placeholder="جستجوی ارز دیجیتال..." oninput="avaSmartRenderCoinPicker()" />
            <div id="avaAiCoinPickerList" class="ava-rate-picker-list"></div>
        </div>
    </div>

    <!-- ===== (جدید) شیت «مشاهده همه» تارگت‌های پیش‌بینی‌شده ===== -->
    <div class="ava-sheet" id="avaTargetsSheet">
        <div class="ava-sheet-bd" onclick="avaCloseSheet('avaTargetsSheet')"></div>
        <div class="ava-sheet-in">
            <button class="ava-sheet-close" onclick="avaCloseSheet('avaTargetsSheet')"><i class="fas fa-times"></i></button>
            <div class="ava-sec-title" style="margin-bottom:10px;"><i class="fas fa-bullseye" style="color:#C084FC;"></i> تارگت‌های پیش‌بینی‌شده</div>
            <!-- ===== (جدید) تبِ «در انتظار» / «خورده‌شده» ===== -->
            <div class="ava-targets-tabbar">
                <button type="button" class="ava-targets-tabbtn on" id="avaTargetsTabPending" onclick="avaTargetsSwitchTab('pending')">در انتظار</button>
                <button type="button" class="ava-targets-tabbtn" id="avaTargetsTabHit" onclick="avaTargetsSwitchTab('hit')"><i class="fas fa-check-circle"></i> تارگت‌های خورده‌شده</button>
            </div>
            <div class="ava-targets-list" id="avaTargetsSheetList">
                <div class="ava-targets-empty">در حال بارگذاری تارگت‌ها...</div>
            </div>
            <div class="ava-targets-list" id="avaTargetsHitList" style="display:none;">
                <div class="ava-targets-empty">در حال بارگذاری...</div>
            </div>
        </div>
    </div>

    <!-- ===== مدال تمام‌صفحه‌ی نمودار «پیش‌بینی هوشمند» (بزرگ‌نمایی) ===== -->
    <div class="ava-fs-modal" id="avaSmartFullscreenModal">
        <div class="ava-fs-top">
            <span id="avaSmartFullTitle"><i class="fas fa-brain"></i> گجت هوشمند بازار <span class="ava-smart-badge"><img src="assets/img/forecast-icon.svg" alt="پیش‌بینی" loading="lazy"></span></span>
            <button type="button" class="ava-fs-close" onclick="avaCloseFsModal('avaSmartFullscreenModal')"><i class="fas fa-times"></i> خروج</button>
        </div>
        <div class="ava-smart-full-body">

            <div class="ava-smart-tabs" id="avaSmartTabs">
                <?php foreach ($__aiCurs as $__i => $__ac): $__acm = ava_meta($__ac); ?>
                <button type="button" class="ava-smart-tab<?php echo $__i === 0 ? ' on' : ''; ?>" data-cur="<?php echo $__ac; ?>" data-kind="fiat" onclick="avaSmartSelectCur('<?php echo $__ac; ?>', 'fiat', this)">
                    <span class="ava-smart-tab-ico">
                        <?php if (!empty($__acm['flag'])): ?>
                            <img src="https://flagcdn.com/w40/<?php echo $__acm['flag']; ?>.png" alt="" onerror="this.parentNode.style.display='none'">
                        <?php else: ?>
                            <i class="<?php echo $__acm['ico'] ?? 'fas fa-coins'; ?>" style="color:<?php echo $__acm['col'] ?? '#7C3AED'; ?>"></i>
                        <?php endif; ?>
                    </span>
                    <?php echo $__aiData[$__ac]['name']; ?>
                </button>
                <?php endforeach; ?>
                <?php if (!empty($__aiUserCoins)): ?>
                <div class="ava-smart-tabs-div"></div>
                <?php foreach ($__aiUserCoins as $__coin): ?>
                <button type="button" class="ava-smart-tab crypto" data-cur="<?php echo htmlspecialchars($__coin['coin_id']); ?>" data-kind="crypto"
                        onclick="avaSmartSelectCur('<?php echo htmlspecialchars($__coin['coin_id']); ?>', 'crypto', this)">
                    <span class="ava-smart-tab-ico">
                        <?php if (!empty($__coin['coin_image'])): ?>
                            <img src="<?php echo htmlspecialchars($__coin['coin_image']); ?>" alt="" onerror="this.parentNode.style.display='none'">
                        <?php else: ?>
                            <i class="fab fa-bitcoin" style="color:#F7931A"></i>
                        <?php endif; ?>
                    </span>
                    <?php echo htmlspecialchars($__coin['coin_symbol'] ?: $__coin['coin_name']); ?>
                    <i class="fas fa-xmark ava-smart-tab-x" onclick="event.stopPropagation();avaSmartRemoveCoin('<?php echo htmlspecialchars($__coin['coin_id']); ?>')"></i>
                </button>
                <?php endforeach; ?>
                <?php endif; ?>
                <button type="button" class="ava-smart-tab add-tab" onclick="avaSmartOpenCoinPicker()"><i class="fas fa-plus"></i> افزودن ارز دیجیتال</button>
            </div>

            <!-- (جدید) کارت «تارگت‌های پیش‌بینی‌شده» — وضعیت زنده‌ی هدف همه‌ی ارز/کوین‌ها -->
            <div class="ava-targets-card">
                <div class="ava-targets-top">
                    <div class="ava-targets-ttl"><i class="fas fa-bullseye"></i> تارگت‌های پیش‌بینی‌شده</div>
                    <div style="display:flex; align-items:center; gap:6px;">
                        <button type="button" class="ava-targets-addcoin" onclick="avaSmartOpenCoinPicker()" title="افزودن ارز دیجیتال"><i class="fas fa-plus"></i></button>
                        <button type="button" class="ava-targets-viewall" onclick="avaOpenSheet('avaTargetsSheet'); avaTargetsSwitchTab('pending'); avaRenderTargets('avaTargetsSheetList', 99);">مشاهده همه</button>
                    </div>
                </div>
                <!-- ===== (جدید) تبِ «در انتظار» / «خورده‌شده» روی خودِ کادر ===== -->
                <div class="ava-targets-tabbar">
                    <button type="button" class="ava-targets-tabbtn on" id="avaTargetsPvTabPending" onclick="avaTargetsPreviewSwitchTab('pending')">در انتظار</button>
                    <button type="button" class="ava-targets-tabbtn" id="avaTargetsPvTabHit" onclick="avaTargetsPreviewSwitchTab('hit')"><i class="fas fa-check-circle"></i> تارگت‌های خورده‌شده</button>
                </div>
                <div class="ava-targets-list" id="avaTargetsPreviewList">
                    <div class="ava-targets-empty" id="avaTargetsPreviewEmpty">در حال بارگذاری تارگت‌ها...</div>
                </div>
                <div class="ava-targets-list" id="avaTargetsPreviewHitList" style="display:none;">
                    <div class="ava-targets-empty">در حال بارگذاری...</div>
                </div>
            </div>

            <!-- کارت قیمت لحظه‌ای -->
            <div class="ava-glass-card">
                <!-- هدر قیمت بزرگ زنده -->
                <div class="ava-smart-hero">
                    <div class="ava-smart-hero-row">
                        <div class="ava-smart-hero-val" id="avaSmartHeroVal">-</div>
                        <div class="ava-smart-hero-chg" id="avaSmartHeroChg">-</div>
                    </div>
                    <div class="ava-smart-hero-sub" id="avaSmartHeroSub">قیمت لحظه‌ای</div>
                </div>

                <div class="ava-smart-ohlc" id="avaSmartOhlc">
                    <div><div class="l">باز شدن</div><div class="v" id="avaSmartOhlcOpen">-</div></div>
                    <div><div class="l">سقف</div><div class="v hi" id="avaSmartOhlcHigh">-</div></div>
                    <div><div class="l">کف</div><div class="v lo" id="avaSmartOhlcLow">-</div></div>
                </div>
            </div>

            <!-- کارت نمودار + پیش‌بینی -->
            <div class="ava-glass-card">
                <div class="ava-smart-tf-row">
                    <div class="ava-smart-tf-tabs" id="avaSmartTfTabs">
                        <button type="button" class="ava-smart-tf-tab on" data-range="1d" onclick="avaSmartSelectRange('1d', this)">۱ روزه</button>
                        <button type="button" class="ava-smart-tf-tab" data-range="1m" onclick="avaSmartSelectRange('1m', this)">۱ ماهه</button>
                        <button type="button" class="ava-smart-tf-tab" data-range="1y" onclick="avaSmartSelectRange('1y', this)">۱ ساله</button>
                    </div>
                    <button type="button" class="ava-smart-zoom-reset ava-smart-zoom-reset-full" onclick="avaSmartResetZoom()" title="بازگشت به حالت اول"><i class="fas fa-magnifying-glass-minus"></i></button>
                </div>

                <div class="ava-smart-best" id="avaSmartBest" onclick="avaSmartOpenBestPrice()">
                    <i class="fas fa-trophy"></i>
                    <span>بهترین قیمت خرید همین الان:</span>
                    <b id="avaSmartBestVal">-</b>
                    <i class="fas fa-chevron-left" style="margin-right:auto;font-size:.6rem;opacity:.5;"></i>
                </div>

                <div class="ava-smart-chart-wrap ava-smart-chart-wrap-full">
                    <canvas id="avaSmartChart"></canvas>
                    <div class="ava-smart-chart-loading" id="avaSmartChartLoading" style="display:none;"><i class="fas fa-spinner fa-spin"></i></div>
                    <div class="ava-smart-price-box" id="avaSmartPriceBox" style="display:none;" onclick="avaSmartPriceBoxClick()">
                        <div class="ava-smart-price-box-val" id="avaSmartPriceBoxVal">-</div>
                        <div class="ava-smart-price-box-ads" id="avaSmartPriceBoxAds">-</div>
                    </div>
                </div>
                <div class="ava-smart-legend">
                    <span><i class="ava-smart-legend-dot" style="background:#A855F7;"></i> واقعی</span>
                    <span><i class="ava-smart-legend-dot ava-smart-legend-dash" style="background:#EAB308;"></i> پیش‌بینی</span>
                </div>

                <div class="ava-smart-hint" id="avaSmartHint"><i class="fas fa-hand-pointer"></i> روی نمودار بکشید تا قیمت و تعداد آگهی‌های آن نقطه را ببینید، سپس با زدن روی همان جعبه، آگهی‌ها را باز کنید</div>
            </div>

            <div class="ava-glass-card">
                <div class="ava-smart-body">
                    <div class="ava-smart-pred">
                    <div class="ava-smart-pred-lbl" id="avaSmartPredLbl">پیش‌بینی قیمت<br>۳ روز آینده</div>
                    <div class="ava-smart-pred-val" id="avaSmartPredVal">-</div>
                    <div class="ava-smart-pred-chg" id="avaSmartPredChg">-</div>
                </div>
                <div class="ava-smart-gauge-col" id="avaSmartGaugeCol">
                    <div class="ava-smart-gauge-wrap">
                        <svg width="76" height="76" viewBox="0 0 76 76">
                            <circle cx="38" cy="38" r="32" fill="none" stroke="rgba(255,255,255,.08)" stroke-width="6"/>
                            <circle id="avaSmartGaugeArc" cx="38" cy="38" r="32" fill="none" stroke="#22C55E" stroke-width="6" stroke-linecap="round"
                                    stroke-dasharray="201.06" stroke-dashoffset="201.06" transform="rotate(-90 38 38)" style="transition:stroke-dashoffset 1s cubic-bezier(.34,1.56,.64,1), stroke .3s;"/>
                        </svg>
                        <div class="ava-smart-gauge-pct" id="avaSmartGaugePct">-</div>
                    </div>
                    <div class="ava-smart-gauge-lbl">تمایل بازار<br><span id="avaSmartGaugeDir">-</span></div>
                    <div class="ava-smart-gauge-sub"><span class="ava-smart-ai-badge">AI</span> از روی آگهی‌های فعال</div>
                </div>
            </div>
            </div>

            <!-- عمق بازار: چگالی آگهی‌های فعال در هر بازه‌ی قیمتی (به‌جای حجم معاملات) -->
            <div class="ava-smart-depth" id="avaSmartDepth" style="display:none;">
                <div class="ava-smart-depth-ttl"><i class="fas fa-layer-group"></i> عمق بازار (آگهی‌های فعال)</div>
                <div class="ava-smart-depth-sub">تعداد آگهی‌های فعال در هر بازه‌ی قیمتی — ستون بلندتر یعنی رقابت/نقدشوندگی بیشتر؛ با زدن روی هر ستون آگهی‌های همان بازه را ببینید.</div>
                <div class="ava-smart-depth-bars" id="avaSmartDepthBars"></div>
                <div class="ava-smart-depth-axis" id="avaSmartDepthAxis"></div>
            </div>

            <div class="ava-smart-analysis" id="avaSmartAnalysis" style="display:none;">
                <div class="ava-smart-analysis-ttl"><i class="fas fa-chart-line"></i> تحلیل روند</div>
                <div class="ava-smart-analysis-txt" id="avaSmartAnalysisTxt"></div>
            </div>

            <!-- (جدید) دقت پیش‌بینی‌های قبلیِ هوش مصنوعی برای همین ارز/کوین -->
            <div class="ava-smart-accuracy" id="avaSmartAccuracy" style="display:none;">
                <div class="ava-smart-accuracy-top">
                    <div class="ava-smart-accuracy-ttl"><i class="fas fa-bullseye"></i> دقت پیش‌بینی هوش مصنوعی</div>
                    <div class="ava-smart-accuracy-pct" id="avaSmartAccPct">-</div>
                </div>
                <div class="ava-smart-accuracy-bar"><div class="ava-smart-accuracy-bar-fill" id="avaSmartAccBarFill"></div></div>
                <div class="ava-smart-accuracy-sub" id="avaSmartAccSub"></div>
                <div class="ava-smart-accuracy-last" id="avaSmartAccLast" style="display:none;"></div>
            </div>
        </div>

    </div>

    <!-- ===== مدال آگهی‌های نزدیک قیمتِ کلیک‌شده روی نمودار «پیش‌بینی هوشمند» ===== -->
    <div class="ava-fs-modal" id="avaSmartAdsModal">
        <div class="ava-fs-top">
            <span id="avaSmartAdsTitle"><i class="fas fa-layer-group"></i> آگهی‌های این قیمت</span>
        </div>
        <div style="flex:1;overflow-y:auto;padding:14px;">
            <div id="avaSmartAdsList"></div>
        </div>
        <div class="ava-smart-ads-foot">
            <button type="button" class="ava-fs-close ava-smart-ads-close" onclick="avaCloseFsModal('avaSmartAdsModal')"><i class="fas fa-times"></i> خروج</button>
        </div>
    </div>

    <div class="ava-slot" data-slot="grid"></div>

    <!-- ===== (آپدیت جدید) کادر بزرگ یکپارچه: اخبار بازار جهانی + اخبار اقتصادی ایران ===== -->
    <div class="ava-fs-modal" id="avaNewsMegaModal">
        <div class="ava-fs-top">
            <span><i class="fas fa-newspaper"></i> اخبار بازار و اقتصاد ایران</span>
            <button type="button" class="ava-fs-close" onclick="avaCloseFsModal('avaNewsMegaModal')"><i class="fas fa-times"></i> خروج</button>
        </div>
        <div class="ava-fs-body" style="align-items:stretch;justify-content:flex-start;flex-direction:column;overflow-y:auto;padding:14px;">
        <div class="ava-news-mega" id="avaNewsMegaCard" style="width:100%;">
            <div class="ava-recacc-actions" style="justify-content:flex-end;display:flex;margin-bottom:10px;">
                <button type="button" class="ava-more" id="avaNewsAllBtn" style="display:none;" onclick="avaMarketNewsOpenAll()">مشاهده همه <i class="fas fa-chevron-left"></i></button>
            </div>

            <div class="ava-inv-switch">
                <button type="button" class="ava-inv-tab active" data-newstab="market" onclick="avaNewsMegaTab('market', this)">
                    <i class="fas fa-globe"></i> اخبار بازار جهانی
                </button>
                <button type="button" class="ava-inv-tab" data-newstab="iran" onclick="avaNewsMegaTab('iran', this)">
                    <i class="fas fa-landmark"></i> اخبار اقتصادی ایران
                </button>
            </div>

            <!-- پنل: اخبار بازار جهانی -->
            <div class="ava-news-pane" id="avaNewsPaneMarket">
                <div class="ava-news-tabs">
                    <button class="ava-news-tab active" data-lang="fa" onclick="avaNewsLoad('fa', this)">فارسی</button>
                    <button class="ava-news-tab" data-lang="en" onclick="avaNewsLoad('en', this)">English</button>
                </div>
                <div id="avaNewsList" class="ava-news-mega-list">
                    <div class="ava-empty"><i class="fas fa-spinner fa-spin"></i></div>
                </div>
            </div>

            <!-- پنل: اخبار اقتصادی ایران -->
            <div class="ava-news-pane" id="avaNewsPaneIran" style="display:none;">
                <div class="ava-irn-pager-head">
                    <button type="button" class="ava-more" onclick="avaIrnLoad(true)"><i class="fas fa-rotate"></i> به‌روزرسانی</button>
                </div>
                <div class="ava-irn-pager" id="avaIrnStrip">
                    <div class="ava-empty" style="width:100%;"><i class="fas fa-circle-notch fa-spin"></i> در حال دریافت اخبار…</div>
                </div>
                <div class="ava-irn-dots" id="avaIrnDots"></div>
            </div>
        </div>
        </div><!-- /ava-fs-body -->
    </div><!-- /avaNewsMegaModal -->

    <div class="ava-slot" data-slot="irannews"></div>

    <div class="ava-slot" data-slot="bottom"></div>

    <!-- ===== (۳) شیت شخصی‌سازی داشبورد ===== -->
    <div class="ava-sheet" id="avaSecMgrSheet">
        <div class="ava-sheet-bd" onclick="avaCloseSheet('avaSecMgrSheet')"></div>
        <div class="ava-sheet-in">
            <button class="ava-sheet-close" onclick="avaCloseSheet('avaSecMgrSheet')"><i class="fas fa-times"></i></button>
            <div class="ava-sheet-title"><i class="fas fa-sliders"></i> شخصی‌سازی داشبورد</div>
            <div class="ava-list-s" style="color:var(--ava-mut);font-size:.66rem;line-height:1.9;margin-bottom:4px;">
                هر بخشی را که نمی‌خواهید خاموش کنید. با روشن کردن دوباره،
                همان بخش دقیقاً <b style="color:var(--ava-pur2)">سر جای قبلی خودش</b> به داشبورد برمی‌گردد.
            </div>

            <div class="ava-secmgr-list" id="avaSecMgrList"></div>

            <button class="ava-btn-ghost" onclick="avaToggleEdit() ? avaCloseSheet('avaSecMgrSheet') : null">
                <i class="fas fa-pen-to-square"></i> مخفی‌کردن مستقیم روی صفحه
            </button>
            <button class="ava-btn-ghost" onclick="avaSections.resetAll()">
                <i class="fas fa-rotate-left"></i> بازگرداندن همه‌ی بخش‌ها
            </button>
        </div>
    </div>

    <!-- نوار حالت ویرایش -->
    <div class="ava-edit-bar">
        <div>
            <i class="fas fa-hand-pointer" style="color:#A855F7"></i>
            <span>روی <i class="fas fa-eye-slash" style="color:#FF7A8A"></i> هر بخش بزنید تا مخفی شود</span>
            <button onclick="avaToggleEdit()">پایان</button>
        </div>
    </div>

    <!-- ===== مدال جزئیات خبر اقتصادی ایران ===== -->
    <div class="ava-fs-modal" id="avaIrnModal">
        <div class="ava-fs-top">
            <span><i class="fas fa-landmark" style="color:#22C55E;"></i> خبر اقتصادی</span>
            <button type="button" class="ava-fs-close" onclick="avaCloseFsModal('avaIrnModal')"><i class="fas fa-times"></i> خروج</button>
        </div>
        <div style="flex:1;overflow-y:auto;padding:16px;" id="avaIrnDetail"></div>
    </div>

    <!-- ===== (آپدیت ۲) مدال نمودار ارز دیجیتال ===== -->
    <div class="ava-fs-modal" id="avaCoinModal">
        <div class="ava-fs-top">
            <span class="ava-coin-head-t">
                <span class="ava-crypto-ic" id="avaCoinIcon"><i class="fab fa-bitcoin"></i></span>
                <span id="avaCoinName">—</span>
                <span class="ava-crypto-sym" id="avaCoinSym"></span>
            </span>
            <button type="button" class="ava-fs-close" onclick="avaCloseFsModal('avaCoinModal')"><i class="fas fa-times"></i> خروج</button>
        </div>
        <div class="ava-fs-body ava-coin-body">
            <div class="ava-coin-inner">
                <div class="ava-coin-pricebox">
                    <div class="ava-coin-price" id="avaCoinPrice">—</div>
                    <div class="ava-coin-chg" id="avaCoinChg">—</div>
                </div>
                <!-- سوییچ بازه‌ی زمانی -->
                <div class="ava-inv-switch ava-coin-ranges">
                    <button type="button" class="ava-inv-tab active" data-range="1"  onclick="avaCoinRange(1, this)">۲۴ ساعت</button>
                    <button type="button" class="ava-inv-tab" data-range="7"  onclick="avaCoinRange(7, this)">۷ روز</button>
                    <button type="button" class="ava-inv-tab" data-range="30" onclick="avaCoinRange(30, this)">۳۰ روز</button>
                </div>
                <div class="ava-coin-chartwrap">
                    <canvas id="avaCoinChart"></canvas>
                    <div class="ava-coin-chart-empty" id="avaCoinChartEmpty" style="display:none;">
                        <i class="fas fa-chart-line"></i> داده‌ی نمودار در دسترس نیست
                    </div>
                    <div class="ava-coin-chart-load" id="avaCoinChartLoad" style="display:none;">
                        <i class="fas fa-spinner fa-spin"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== فرم افزودن هشدار قیمت ===== -->
    <!-- شیت افزودن حساب معرفی‌شده -->
    <div class="ava-sheet" id="avaBenAddSheet">
        <div class="ava-sheet-bd" onclick="avaCloseSheet('avaBenAddSheet')"></div>
        <div class="ava-sheet-in">
            <button class="ava-sheet-close" onclick="avaCloseSheet('avaBenAddSheet')"><i class="fas fa-times"></i></button>
            <div class="ava-sheet-title" id="benSheetTitle"><i class="fas fa-user-plus"></i> معرفی حساب جدید</div>
            <div class="ava-ben-form">
                <input type="hidden" id="benEditId" value="">
                <label>نام و نام خانوادگی گیرنده <span class="req">*</span></label>
                <input type="text" id="benName" placeholder="مثال: علی رضایی">
                <label>شماره کارت</label>
                <input type="text" id="benCard" inputmode="numeric" maxlength="19" placeholder="۱۶ رقم">
                <label>شماره شبا</label>
                <input type="text" id="benIban" placeholder="شبا / IBAN (هر فرمتی)">
                <label>نام بانک</label>
                <input type="text" id="benBank" placeholder="مثال: ملت">
                <label>توضیحات</label>
                <input type="text" id="benNote" placeholder="اختیاری">
                <div class="ava-ben-err" id="benErr" style="display:none;"></div>
                <button class="ava-btn-main" id="benSaveBtn" onclick="avaBenSave()"><i class="fas fa-check"></i> ذخیره حساب</button>
            </div>
        </div>
    </div>

    <!-- شیت جزئیات حساب + درخواست تسویه -->
    <div class="ava-sheet" id="avaBenViewSheet">
        <div class="ava-sheet-bd" onclick="avaCloseSheet('avaBenViewSheet')"></div>
        <div class="ava-sheet-in">
            <button class="ava-sheet-close" onclick="avaCloseSheet('avaBenViewSheet')"><i class="fas fa-times"></i></button>
            <div class="ava-ben-view-head">
                <div class="ava-ben-avatar lg" id="benVAvatar"><?php echo avaBenIcon(42); ?></div>
                <div class="ava-ben-view-name" id="benVName">—</div>
                <div class="ava-ben-bank" id="benVBank"></div>
            </div>
            <div class="ava-ben-rows" id="benVRows"></div>

            <!-- (۱ب) نمودار انتقال‌های یک‌سال گذشته به این حساب -->
            <div class="ava-benchart" id="avaBenChart"></div>

            <button class="ava-btn-main" onclick="avaBenSettle()"><i class="fas fa-hand-holding-dollar"></i> درخواست تسویه به این حساب</button>
            <button class="ava-btn-ghost" onclick="avaBenOpenEdit()"><i class="fas fa-pen"></i> ویرایش حساب</button>
            <button class="ava-btn-ghost danger" onclick="avaBenDeleteConfirm()"><i class="fas fa-trash"></i> حذف حساب</button>
        </div>
    </div>

    <!-- مودال تاییدیه‌ی حذف حساب معرفی‌شده (نه confirm() بومی — در برخی وب‌ویوها کار نمی‌کند) -->
    <div class="modal-overlay" id="avaBenDeleteModal" style="display:none;position:fixed;inset:0;z-index:100020;background:rgba(0,0,0,.65);backdrop-filter:blur(6px);align-items:center;justify-content:center;">
        <div style="width:90%;max-width:340px;background:var(--ava-card);border:1px solid var(--ava-line);border-radius:20px;padding:22px;text-align:center;">
            <div style="font-size:1rem;font-weight:800;margin-bottom:8px;"><i class="fas fa-triangle-exclamation" style="color:#f87171;"></i> حذف حساب</div>
            <div style="font-size:.8rem;color:var(--ava-mut);line-height:1.9;margin-bottom:18px;">حساب <b id="avaBenDelName" style="color:var(--ava-txt);"></b> حذف شود؟ این عمل قابل بازگشت نیست.</div>
            <div style="display:flex;gap:8px;">
                <button class="ava-btn-ghost" style="flex:1;" onclick="document.getElementById('avaBenDeleteModal').style.display='none'">انصراف</button>
                <button class="ava-btn-main" style="flex:1;background:linear-gradient(135deg,#ef4444,#dc2626);" onclick="avaBenDelete()"><i class="fas fa-trash"></i> حذف</button>
            </div>
        </div>
    </div>

    <!-- ===== مودال جامع «معرفی حساب» (سه تب: افزودن، بیشترین واریزی، دانلود) ===== -->
    <div class="ava-fs-modal ava-bm-modal" id="avaBeneMainModal">
        <div class="ava-bm-aura"></div>
        <div class="ava-fs-top ava-bm-top">
            <span><i class="fas fa-address-book"></i> معرفی حساب</span>
            <button type="button" class="ava-fs-close" onclick="avaCloseFsModal('avaBeneMainModal')"><i class="fas fa-times"></i> خروج</button>
        </div>
        <div class="ava-bm-body">
            <div class="ava-inv-switch" style="margin-bottom:16px;">
                <button type="button" class="ava-inv-tab active" data-bmtab="add" onclick="avaBenMainTab('add', this)"><i class="fas fa-plus"></i> معرفی حساب</button>
                <button type="button" class="ava-inv-tab" data-bmtab="stats" onclick="avaBenMainTab('stats', this)"><i class="fas fa-chart-column"></i> بیشترین واریزی</button>
                <button type="button" class="ava-inv-tab" data-bmtab="pdf" onclick="avaBenMainTab('pdf', this)"><i class="fas fa-file-pdf"></i> دانلود</button>
            </div>

            <!-- تب ۱: + معرفی حساب + کارت جدای حساب‌های قبلی -->
            <div class="ava-bm-pane" id="avaBmPaneAdd">
                <button class="ava-ben-add-big" onclick="avaBenOpenAdd()">
                    <i class="fas fa-user-plus"></i> معرفی حساب جدید
                </button>

                <div class="ava-bm-card">
                    <div class="ava-bm-card-title"><i class="fas fa-address-book"></i> حساب‌های معرفی‌شده</div>

                    <div class="ava-ben-search">
                        <i class="fas fa-magnifying-glass"></i>
                        <input type="text" id="avaBenSearch" placeholder="جستجوی نام گیرنده..." oninput="avaBenFilter()">
                    </div>

                    <?php if (empty($avaBeneficiaries)): ?>
                        <div class="ava-empty" id="avaBenEmpty">
                            <i class="fas fa-user-plus"></i> هنوز حسابی معرفی نکرده‌اید
                            <div style="font-size:.68rem;margin-top:6px;opacity:.7">با معرفی حساب، تسویه فقط با یک کلیک انجام می‌شود</div>
                        </div>
                    <?php endif; ?>

                    <?php $__benMany = (count($avaBeneficiaries) > 4); ?>
                    <?php if ($__benMany): ?>
                        <div class="ava-ben-swipe-hint"><i class="fas fa-hand-pointer"></i> برای دیدن بقیه حساب‌ها انگشت را چپ و راست بکشید</div>
                    <?php endif; ?>
                    <div class="ava-ben-grid<?php echo $__benMany ? ' many' : ''; ?>" id="avaBenGrid">
                        <?php foreach ($avaBeneficiaries as $bn):
                            $bnJson = htmlspecialchars(json_encode([
                                'id'   => (int)$bn['id'],
                                'name' => $bn['full_name'],
                                'card' => $bn['card_number'],
                                'iban' => $bn['iban'],
                                'bank' => $bn['bank_name'],
                                'note' => $bn['note'],
                                'avatar' => $bn['avatar'],
                            ], JSON_UNESCAPED_UNICODE), ENT_QUOTES);
                        ?>
                        <div class="ava-ben-item" data-name="<?php echo htmlspecialchars(mb_strtolower($bn['full_name'])); ?>"
                             onclick='avaBenOpen(<?php echo $bnJson; ?>)'>
                            <div class="ava-ben-avatar">
                                <?php echo avaBenIcon(30); ?>
                            </div>
                            <div class="ava-ben-name"><?php echo htmlspecialchars($bn['full_name']); ?></div>
                            <?php if (!empty($bn['bank_name'])): ?><div class="ava-ben-bank"><?php echo htmlspecialchars($bn['bank_name']); ?></div><?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="ava-empty" id="avaBenNoResult" style="display:none;"><i class="fas fa-magnifying-glass"></i> نتیجه‌ای یافت نشد</div>
                </div>
            </div>

            <!-- تب ۲: نمودار «بیشترین واریزی در یک‌سال گذشته» (تجمیعی، همه حساب‌ها) -->
            <div class="ava-bm-pane" id="avaBmPaneStats" style="display:none;">
                <div class="ava-bm-card">
                    <div class="ava-benchart" id="avaBenStatsChart"></div>
                </div>
            </div>

            <!-- تب ۳: دانلود PDF کلیه واریزی‌ها در یک بازه‌ی زمانی دلخواه -->
            <div class="ava-bm-pane" id="avaBmPanePdf" style="display:none;">
                <div class="ava-bm-card">
                    <label class="ava-list-s">از تاریخ</label>
                    <input type="date" id="avaBenPdfFrom" style="width:100%;margin:6px 0 12px;padding:11px;border-radius:12px;background:var(--ava-card);border:1px solid var(--ava-line2);color:var(--ava-txt);font-family:inherit;">
                    <label class="ava-list-s">تا تاریخ</label>
                    <input type="date" id="avaBenPdfTo" style="width:100%;margin:6px 0 16px;padding:11px;border-radius:12px;background:var(--ava-card);border:1px solid var(--ava-line2);color:var(--ava-txt);font-family:inherit;">
                    <button class="ava-btn-main" id="avaBenPdfGenBtn" onclick="avaBenPdfGenerate()"><i class="fas fa-file-pdf"></i> ساخت PDF</button>

                    <div id="avaBenPdfResult" style="margin-top:16px;display:none;">
                        <div style="border-radius:16px;overflow:hidden;border:1px solid var(--ava-line);height:60vh;background:#fff;">
                            <iframe id="avaBenPdfFrame" style="width:100%;height:100%;border:0;"></iframe>
                        </div>
                        <div style="display:flex;gap:8px;margin-top:12px;">
                            <button class="ava-btn-main" style="flex:1;" onclick="avaBenPdfShare()"><i class="fas fa-share-nodes"></i> اشتراک‌گذاری</button>
                            <a class="ava-btn-ghost" style="flex:1;text-align:center;text-decoration:none;" id="avaBenPdfDownloadLink" download="AvaPay-Statement.pdf"><i class="fas fa-download"></i> دانلود</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- قالب پنهانِ صورت‌حساب (فقط برای رندر html2canvas → PDF) -->
        <div id="avaBenPdfTemplate" style="position:fixed;top:-9999px;left:-9999px;width:780px;background:#fff;font-family:'Vazirmatn',sans-serif;direction:rtl;"></div>
    </div>

    <div class="ava-sheet" id="avaAlertSheet">
        <div class="ava-sheet-bd" onclick="avaCloseSheet('avaAlertSheet')"></div>
        <div class="ava-sheet-in" style="max-height:auto;">
            <button class="ava-sheet-close" onclick="avaCloseSheet('avaAlertSheet')"><i class="fas fa-times"></i></button>
            <div class="ava-sec-title" style="margin-bottom:14px;"><i class="fas fa-bell"></i> افزودن هشدار قیمت</div>

            <label class="ava-list-s">نوع هشدار</label>
            <div class="ava-inv-switch" style="margin:6px 0 12px;flex-wrap:wrap;">
                <button type="button" class="ava-inv-tab active" data-atype="ad" onclick="avaAlertType('ad', this)"><i class="fas fa-bullhorn"></i> قیمت آگهی</button>
                <button type="button" class="ava-inv-tab" data-atype="rate" onclick="avaAlertType('rate', this)"><i class="fas fa-star"></i> نرخ ارز</button>
                <button type="button" class="ava-inv-tab" data-atype="crypto" onclick="avaAlertType('crypto', this)"><i class="fab fa-bitcoin"></i> ارز دیجیتال</button>
            </div>
            <div class="ava-list-s" id="avaAlertTypeHint" style="margin-bottom:12px;color:var(--ava-mut);font-size:.62rem;">
                هشدار بر اساس قیمت آگهی‌های فعال بازار بررسی می‌شود.
            </div>

            <!-- انتخاب ارز فیات/طلا (برای آگهی و نرخ) -->
            <div id="avaAlertFiatWrap">
                <label class="ava-list-s">ارز</label>
                <select id="avaAlertCur" style="width:100%;margin:6px 0 12px;padding:11px;border-radius:12px;background:var(--ava-card);border:1px solid var(--ava-line2);color:var(--ava-txt);font-family:inherit;">
                    <?php foreach ($avaRates as $code => $r): $m = ava_meta($code); ?>
                    <option value="<?php echo $code; ?>"><?php echo $m['name'] . ' (' . $m['code'] . ')'; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- انتخاب ارز دیجیتال (فقط برای نوع crypto) -->
            <div id="avaAlertCryptoWrap" style="display:none;">
                <label class="ava-list-s">ارز دیجیتال</label>
                <select id="avaAlertCoin" style="width:100%;margin:6px 0 12px;padding:11px;border-radius:12px;background:var(--ava-card);border:1px solid var(--ava-line2);color:var(--ava-txt);font-family:inherit;">
                    <option value="">در حال بارگذاری فهرست ارزها…</option>
                </select>
            </div>

            <label class="ava-list-s">شرط</label>
            <select id="avaAlertDir" style="width:100%;margin:6px 0 12px;padding:11px;border-radius:12px;background:var(--ava-card);border:1px solid var(--ava-line2);color:var(--ava-txt);font-family:inherit;">
                <option value="above">وقتی قیمت بیشتر شود</option>
                <option value="below">وقتی قیمت کمتر شود</option>
            </select>
            <label class="ava-list-s" id="avaAlertPriceLbl">قیمت هدف (تومان)</label>
            <input type="number" step="any" id="avaAlertPrice" placeholder="90000" style="width:100%;margin:6px 0 14px;padding:11px;border-radius:12px;background:var(--ava-card);border:1px solid var(--ava-line2);color:var(--ava-txt);font-family:inherit;">

            <label class="ava-list-s">هر چند وقت یک‌بار قیمت بررسی شود؟</label>
            <select id="avaAlertInterval" style="width:100%;margin:6px 0 14px;padding:11px;border-radius:12px;background:var(--ava-card);border:1px solid var(--ava-line2);color:var(--ava-txt);font-family:inherit;">
                <option value="1">هر ۱ دقیقه</option>
                <option value="5">هر ۵ دقیقه</option>
                <option value="15">هر ۱۵ دقیقه</option>
                <option value="30">هر ۳۰ دقیقه</option>
                <option value="60" selected>هر ۱ ساعت</option>
                <option value="180">هر ۳ ساعت</option>
                <option value="360">هر ۶ ساعت</option>
                <option value="720">هر ۱۲ ساعت</option>
                <option value="1440">هر ۲۴ ساعت</option>
            </select>

            <label class="ava-list-s">روش اطلاع‌رسانی (حتی وقتی اپ بسته است)</label>
            <div class="ava-chan-grid">
                <label class="ava-chan"><input type="checkbox" id="avaChanTg" checked><span><i class="fab fa-telegram"></i> تلگرام</span></label>
                <label class="ava-chan"><input type="checkbox" id="avaChanEmail"><span><i class="fas fa-envelope"></i> ایمیل</span></label>
                <label class="ava-chan"><input type="checkbox" id="avaChanToast" checked><span><i class="fas fa-bell"></i> اعلان اپ</span></label>
            </div>

            <button class="ava-wal-btn" style="width:100%;justify-content:center;margin-top:14px;" onclick="avaAlertSave()"><i class="fas fa-check"></i> ثبت هشدار</button>
        </div>
    </div>


    <!-- ===== مدال تمام‌صفحه‌ی نمایش فیش ===== -->
    <div class="ava-fs-modal" id="avaReceiptModal">
        <div class="ava-fs-top">
            <span id="avaReceiptTitle">فیش دریافتی</span>
            <button type="button" class="ava-fs-close" onclick="avaCloseFsModal('avaReceiptModal')"><i class="fas fa-times"></i> خروج</button>
        </div>
        <div class="ava-fs-body">
            <img id="avaReceiptImg" src="" alt="فیش" style="max-width:100%;max-height:100%;object-fit:contain;border-radius:10px;">
        </div>
        <!-- بندانگشتی‌های چند فیش (وقتی بیش از یک فیش وجود دارد) -->
        <div class="ava-rec-thumbs" id="avaReceiptThumbs" style="display:none;"></div>
        <div class="ava-fs-foot">
            <a id="avaReceiptDownload" href="#" download class="ava-wal-btn" style="justify-content:center;"><i class="fas fa-download"></i> دانلود فیش</a>
        </div>
    </div>

    <!-- ===== مدال آرشیو فیش‌های دریافتی ===== -->
    <div class="ava-fs-modal" id="avaReceiptArchiveModal">
        <div class="ava-fs-top">
            <span><i class="fas fa-box-archive"></i> آرشیو فیش‌های دریافتی</span>
            <button type="button" class="ava-fs-close" onclick="avaCloseFsModal('avaReceiptArchiveModal')"><i class="fas fa-times"></i> خروج</button>
        </div>
        <div class="ava-fs-body" style="align-items:stretch;justify-content:flex-start;overflow-y:auto;padding:14px;">
            <div class="ava-arch-list" id="avaReceiptArchiveList"></div>
        </div>
    </div>

    <!-- ===== (آپدیت ۲) مدال جزئیات حساب ارسالی ادمین + آپلود سریع فیش ===== -->
    <div class="ava-fs-modal ava-pa-modal" id="avaPendAccModal">
        <div class="ava-fs-top">
            <span id="avaPendAccTitle"><i class="fas fa-file-invoice"></i> واریز و ارسال فیش</span>
            <button type="button" class="ava-fs-close" onclick="avaCloseFsModal('avaPendAccModal')"><i class="fas fa-times"></i> خروج</button>
        </div>
        <div class="ava-fs-body ava-pa-body">
            <div class="ava-pa-inner">
                <div id="avaPendAccMeta" class="ava-pa-meta"></div>

                <div class="ava-pa-section">
                    <div class="ava-pa-sec-title"><i class="fas fa-university"></i> حساب‌های اعلامی برای واریز</div>
                    <div class="ava-pa-sec-hint">روی هر کارت بزنید تا کپی شود</div>
                    <div id="avaPendAccList" class="ava-pa-acclist"></div>
                    <div id="avaPendAccNote" class="ava-pa-note" style="display:none;"></div>
                </div>

                <div class="ava-pa-section">
                    <div class="ava-pa-sec-title"><i class="fas fa-cloud-upload-alt"></i> آپلود فیش واریزی</div>
                    <div class="ava-upzone" id="avaPendAccUpZone" onclick="document.getElementById('avaPendAccFile').click()">
                        <i class="fas fa-cloud-upload-alt ava-upzone-ic"></i>
                        <div class="ava-upzone-t">برای انتخاب فیش(ها) کلیک کنید</div>
                        <div class="ava-upzone-s">JPG · PNG · PDF — چند فایل مجاز است</div>
                    </div>
                    <input type="file" id="avaPendAccFile" style="display:none;" accept="image/*,.pdf" multiple onchange="avaPendAccPreview()">
                    <div id="avaPendAccPreview" class="ava-pa-preview"></div>
                </div>
            </div>
        </div>
        <div class="ava-fs-foot ava-pa-foot">
            <button class="ava-pa-submit" onclick="avaPendAccSubmit()"><i class="fas fa-paper-plane"></i> ارسال فیش برای پشتیبانی</button>
        </div>
    </div>

    <!-- ===== (آپدیت ۲) مدال آرشیو حساب‌های ارسالی ادمین ===== -->
    <div class="ava-fs-modal" id="avaPendAccArchiveModal">
        <div class="ava-fs-top">
            <span><i class="fas fa-list"></i> همه حساب‌های ارسالی</span>
            <button type="button" class="ava-fs-close" onclick="avaCloseFsModal('avaPendAccArchiveModal')"><i class="fas fa-times"></i> خروج</button>
        </div>
        <div class="ava-fs-body" style="align-items:stretch;justify-content:flex-start;overflow-y:auto;padding:14px;">
            <div class="ava-pendacc-list" id="avaPendAccArchiveList"></div>
        </div>
    </div>

    <!-- ===== مدال ارسال حواله (money_transfer.php) ===== -->
    <div class="ava-fs-modal" id="avaTransferModal">
        <div class="ava-fs-top">
            <span id="avaTransferModalTitle"><i class="fas fa-globe"></i> ثبت درخواست حواله ارزی</span>
            <button type="button" class="ava-fs-close" onclick="avaCloseFsModal('avaTransferModal')"><i class="fas fa-times"></i> خروج</button>
        </div>
        <iframe id="avaTransferFrame" src="" style="flex:1;width:100%;border:0;background:var(--ava-bg);"></iframe>
    </div>

    <!-- ===== مدال تاریخچه‌ی کامل صورت‌حساب‌ها ===== -->
    <div class="ava-fs-modal" id="avaInvHistModal">
        <div class="ava-fs-top">
            <span><i class="fas fa-clock-rotate-left"></i> تاریخچه صورت‌حساب‌ها</span>
            <button type="button" class="ava-fs-close" onclick="avaCloseFsModal('avaInvHistModal')"><i class="fas fa-times"></i> خروج</button>
        </div>
        <div style="flex:1;overflow-y:auto;padding:16px;">
            <?php if (empty($avaInvHistory)): ?>
                <div class="ava-empty"><i class="fas fa-clock-rotate-left"></i> تاریخچه‌ای وجود ندارد</div>
            <?php else: foreach ($avaInvHistory as $iv):
                $m = ava_meta(strtoupper($iv['currency']));
                $stC = $iv['status'] === 'rejected' ? 'no' : 'ok';
                $stT = ['paid'=>'پرداخت شد','finalized'=>'نهایی شد','rejected'=>'رد شد'][$iv['status']] ?? $iv['status'];
            ?>
            <div class="ava-inv-item" style="cursor:pointer;margin-bottom:10px;" onclick="avaOpenInvoice(<?php echo (int)$iv['id']; ?>)">
                <div class="ava-inv-amt"><?php echo ava_num($iv['amount'], $m['dec']); ?> <?php echo $m['code']; ?></div>
                <div class="ava-inv-meta"><?php echo htmlspecialchars(mb_substr($iv['description'] ?? '', 0, 40)); ?> · <?php echo ava_ago($iv['created_at']); ?></div>
                <span class="ava-badge <?php echo $stC; ?>"><?php echo $stT; ?></span>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <!-- ===== مدال ثبت آگهی جدید (arad.php در iframe) ===== -->
    <div class="ava-fs-modal" id="avaAdModal">
        <div class="ava-fs-top">
            <span><i class="fas fa-plus-circle"></i> ثبت آگهی جدید</span>
            <button type="button" class="ava-fs-close" onclick="avaCloseFsModal('avaAdModal')"><i class="fas fa-times"></i> خروج</button>
        </div>
        <iframe id="avaAdFrame" src="" style="flex:1;width:100%;border:0;background:var(--ava-bg);"></iframe>
    </div>

    <!-- ===== مدال پروفایل / احراز هویت (profile.php در iframe) ===== -->
    <div class="ava-fs-modal" id="avaProfileModal">
        <div class="ava-fs-top">
            <span id="avaProfileModalTitle"><i class="fas fa-user-edit"></i> ویرایش پروفایل</span>
            <button type="button" class="ava-fs-close" onclick="avaCloseFsModal('avaProfileModal')"><i class="fas fa-times"></i> خروج</button>
        </div>
        <iframe id="avaProfileFrame" src="" style="flex:1;width:100%;border:0;background:var(--ava-bg);"></iframe>
    </div>

    <!-- ===== (آپدیت ۶) مدال موجودی همه‌ی ارزها ===== -->
    <div class="ava-fs-modal" id="avaAllBalModal">
        <div class="ava-fs-top">
            <span><i class="fas fa-layer-group"></i> موجودی همه‌ی ارزها</span>
            <button type="button" class="ava-fs-close" onclick="avaCloseFsModal('avaAllBalModal')"><i class="fas fa-times"></i> خروج</button>
        </div>
        <div class="ava-fs-body" style="align-items:flex-start;justify-content:flex-start;flex-direction:column;overflow-y:auto;">
            <div class="ava-allbal-total">
                <span class="l"><i class="fas fa-coins"></i> ارزش کل سبد دارایی</span>
                <span class="v ava-hideable"><?php echo ava_num($avaTotalToman); ?> <span style="font-size:.62rem;font-weight:700;color:var(--ava-mut)">تومان</span></span>
            </div>
            <div class="ava-allbal-grid" id="avaAllBalGrid"></div>
        </div>
    </div>

    <!-- ===== شیت افزودن ارز به نرخ‌های مورد علاقه ===== -->
    <div class="ava-sheet" id="avaRatePickerSheet">
        <div class="ava-sheet-bd" onclick="avaCloseSheet('avaRatePickerSheet')"></div>
        <div class="ava-sheet-in">
            <button class="ava-sheet-close" onclick="avaCloseSheet('avaRatePickerSheet')"><i class="fas fa-times"></i></button>
            <div class="ava-sec-title" style="margin-bottom:10px;"><i class="fas fa-star"></i> افزودن / حذف ارز</div>
            <input type="text" id="avaRateSearch" class="ava-rate-search" placeholder="جستجوی ارز..." oninput="avaRenderRatePicker()" />
            <div id="avaRatePickerList" class="ava-rate-picker-list"></div>
        </div>
    </div>

    <!-- ===== شیت افزودن ارز دیجیتال به واچ‌لیست ===== -->
    <div class="ava-sheet" id="avaCryptoPickerSheet">
        <div class="ava-sheet-bd" onclick="avaCloseSheet('avaCryptoPickerSheet')"></div>
        <div class="ava-sheet-in">
            <button class="ava-sheet-close" onclick="avaCloseSheet('avaCryptoPickerSheet')"><i class="fas fa-times"></i></button>
            <div class="ava-sec-title" style="margin-bottom:10px;"><i class="fab fa-bitcoin" style="color:#F7931A;"></i> افزودن ارز دیجیتال به واچ‌لیست</div>
            <input type="text" id="avaCryptoSearch" class="ava-rate-search" placeholder="جستجوی ارز دیجیتال..." oninput="avaRenderCryptoPicker()" />
            <div id="avaCryptoPickerList" class="ava-rate-picker-list"></div>
        </div>
    </div>

    <!-- ===== مدال ارسال پیشنهاد قیمت روی آگهی ===== -->
    <div class="ava-sheet" id="avaOfferSheet">
        <div class="ava-sheet-bd" onclick="avaCloseSheet('avaOfferSheet')"></div>
        <div class="ava-sheet-in">
            <button class="ava-sheet-close" onclick="avaCloseSheet('avaOfferSheet')"><i class="fas fa-times"></i></button>
            <div class="ava-sec-title" style="margin-bottom:6px;"><i class="fas fa-tag"></i> ارسال پیشنهاد قیمت</div>
            <div id="avaOfferAdInfo" class="ava-offer-adinfo"></div>
            <div class="ava-offer-field">
                <label>مقدار درخواستی (<span id="avaOfferCurLbl">—</span>)</label>
                <input type="number" id="avaOfferAmount" inputmode="decimal" placeholder="مثلاً 100" />
            </div>
            <div class="ava-offer-field">
                <label>قیمت پیشنهادی هر واحد (تومان)</label>
                <input type="number" id="avaOfferPrice" inputmode="decimal" placeholder="مثلاً 92000" />
            </div>
            <div class="ava-offer-field">
                <label>پیام (اختیاری)</label>
                <input type="text" id="avaOfferMsg" maxlength="200" placeholder="توضیح کوتاه برای طرف مقابل" />
            </div>
            <div class="ava-offer-total" id="avaOfferTotal"></div>
            <button class="ava-wal-btn" style="width:100%;justify-content:center;" onclick="avaSubmitOffer()">
                <i class="fas fa-paper-plane"></i> ارسال پیشنهاد
            </button>
        </div>
    </div>

    <!-- ===== مدال پرداخت صورت‌حساب (invoice) ===== -->
    <div class="ava-sheet" id="avaInvoiceSheet">
        <div class="ava-sheet-bd" onclick="avaCloseSheet('avaInvoiceSheet')"></div>
        <div class="ava-sheet-in">
            <button class="ava-sheet-close" onclick="avaCloseSheet('avaInvoiceSheet')"><i class="fas fa-times"></i></button>
            <div class="ava-sec-title" style="margin-bottom:14px;"><i class="fas fa-file-invoice"></i> پرداخت صورت‌حساب</div>
            <div id="avaInvoiceBody">
                <div class="ava-empty"><i class="fas fa-spinner fa-spin"></i></div>
            </div>
        </div>
    </div>


    <!-- ===== شیت شارژ کیف پول / فیش‌ها / اخبار / پشتیبانی (سیستم قبلی) ===== -->
    <div class="ava-sheet" id="avaTopupSheet">
        <div class="ava-sheet-bd" onclick="avaCloseSheet('avaTopupSheet')"></div>
        <div class="ava-sheet-in">
            <button class="ava-sheet-close" onclick="avaCloseSheet('avaTopupSheet')"><i class="fas fa-times"></i></button>
    <!-- INVOICE SECTION -->
    <div class="invoice-section">
        <div class="invoice-panel">
            <div class="invoice-title"><i class="fas fa-file-invoice"></i> Unpaid Invoices</div>
            <div class="invoice-list" id="invoiceList">
                <div class="invoice-empty"><i class="fas fa-spinner fa-spin"></i></div>
            </div>
        </div>
    </div>

    <!-- ===== کارت هاب تب‌دار (اخبار / شارژ / تراکنش‌ها / پشتیبانی) ===== -->
    <div class="hub-card">
        <div class="hub-tabs">
            <button class="hub-tab active" data-tab="news" style="--tc:#38bdf8" onclick="hubSwitch('news', this)"><i class="fas fa-newspaper"></i><span>اخبار</span></button>
            <button class="hub-tab" data-tab="topup" style="--tc:#35d07f" onclick="hubSwitch('topup', this)"><i class="fas fa-wallet"></i><span>شارژ</span></button>
            <button class="hub-tab" data-tab="tx" style="--tc:#FFD700" onclick="hubSwitch('tx', this)"><i class="fas fa-receipt"></i><span>تراکنش</span></button>
            <button class="hub-tab" data-tab="chat" style="--tc:#FF4D8D" onclick="hubSwitch('chat', this); hubChatInit();"><i class="fas fa-headset"></i><span>پشتیبانی</span><span class="hub-tab-badge" id="supportBadge" style="display:none">0</span></button>
        </div>

        <div class="hub-panel active" id="hub-news">
            <div class="news-lang-tabs" style="margin-bottom:12px;">
                <button class="news-lang-tab active" data-lang="fa" onclick="switchNewsLang('fa', this)">فارسی</button>
                <button class="news-lang-tab" data-lang="en" onclick="switchNewsLang('en', this)">English</button>
            </div>
            <div class="news-list" id="newsList">
                <div class="news-empty"><i class="fas fa-spinner fa-spin"></i></div>
            </div>
        </div>

        <div class="hub-panel" id="hub-topup">
        <!-- TOP-UP SECTION — Step by Step -->
        <div class="topup-section">
            <div class="topup-panel">

                <!-- Header -->
                <div class="tp-header-row">
                    <div class="tp-title"><i class="fas fa-plus-circle"></i> Top-up Account</div>
                    <button class="tp-btn-history" onclick="tpOpenMyRequests()"><i class="fas fa-history"></i> History</button>
                </div>

                <!-- Stepper (hidden until request exists) -->
                <div class="tp-stepper" id="tp-stepper">
                    <div class="tp-stepper-track">
                        <div class="tp-stepper-bar" id="tp-stepper-bar" style="width:0%"></div>
                        <div class="tp-step" id="tps-0">
                            <div class="tp-step-circle"><i class="fas fa-paper-plane"></i></div>
                            <div class="tp-step-lbl">Submit</div>
                        </div>
                        <div class="tp-step" id="tps-1">
                            <div class="tp-step-circle"><i class="fas fa-check-circle"></i></div>
                            <div class="tp-step-lbl">Approved</div>
                        </div>
                        <div class="tp-step" id="tps-2">
                            <div class="tp-step-circle"><i class="fas fa-university"></i></div>
                            <div class="tp-step-lbl">Pay</div>
                        </div>
                        <div class="tp-step" id="tps-3">
                            <div class="tp-step-circle"><i class="fas fa-image"></i></div>
                            <div class="tp-step-lbl">Receipt</div>
                        </div>
                        <div class="tp-step" id="tps-4">
                            <div class="tp-step-circle"><i class="fas fa-trophy"></i></div>
                            <div class="tp-step-lbl">Done</div>
                        </div>
                    </div>
                </div>

                <!-- Status bar -->
                <div class="tp-active-status-bar" id="tp-active-status-bar">
                    <i class="fas fa-circle-notch fa-spin"></i>
                    <span id="tp-active-status-text">Checking status...</span>
                </div>

                <!-- ── STEP 1: Enter Amount ── -->
                <div id="tp-step-amount">
                    <div class="tp-cur-tabs">
                        <button class="tp-cur-tab active" data-cur="USD">💵 USD</button>
                        <button class="tp-cur-tab" data-cur="EUR">💶 EUR</button>
                        <button class="tp-cur-tab" data-cur="USDT">₮ USDT</button>
                    </div>
                    <div class="tp-amount-wrap">
                        <input type="number" id="tp-amount" placeholder="Enter amount..." min="1" step="any">
                        <span class="tp-cur-badge" id="tp-cur-badge">USD</span>
                    </div>
                    <div class="tp-quick-amts" id="tp-quick-amts"></div>
                    <button class="tp-btn-submit" id="tp-btn-submit" onclick="tpSubmitRequest()">
                        <i class="fas fa-paper-plane"></i> Submit Request
                    </button>
                </div>

                <!-- ── STEP 2: Pending ── -->
                <div id="tp-step-pending" style="display:none;">
                    <div class="tp-step-body">
                        <i class="tp-state-icon fas fa-hourglass-half" style="color:#f59e0b;"></i>
                        <span class="tp-state-title" style="color:#f59e0b;">Waiting for admin approval</span>
                        <span class="tp-state-sub">Your request is being reviewed.<br>You'll be notified when approved.</span>
                    </div>
                </div>

                <!-- ── STEP 3: Payment Details ── -->
                <div id="tp-step-payment" style="display:none;">
                    <div class="tp-payment-header">
                        <i class="fas fa-university"></i> Payment Information
                    </div>
                    <div class="tp-payment-grid" id="tp-payment-details"></div>
                    <div class="tp-upload-area" onclick="document.getElementById('tp-file').click()" ondragover="event.preventDefault()" ondrop="tpHandleDrop(event)">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <span id="tp-upload-label">Click or drag to upload receipt(s)</span>
                        <input type="file" id="tp-file" accept="image/*" multiple onchange="tpPreviewReceipt(this)">
                    </div>
                    <div id="tp-previews-container" style="display:none;margin-top:10px;"></div>
                    <button class="tp-btn-send" id="tp-btn-send" onclick="tpUploadReceipt()">
                        <i class="fas fa-check"></i> Send Receipt to Admin
                    </button>
                </div>

                <!-- ── STEP 4: Waiting confirmation ── -->
                <div id="tp-step-waiting" style="display:none;">
                    <div class="tp-step-body">
                        <i class="tp-state-icon fas fa-clock" style="color:#a78bfa;"></i>
                        <span class="tp-state-title" style="color:#a78bfa;">Receipt submitted!</span>
                        <span class="tp-state-sub">Waiting for admin to confirm your payment.<br>You'll be notified once verified.</span>
                    </div>
                    <img class="tp-receipt-thumb" id="tp-receipt-thumb" src="" alt="Receipt">
                </div>

                <!-- ── Rejected ── -->
                <div class="tp-reject-box" id="tp-reject-box">
                    <i class="fas fa-times-circle"></i>
                    <span class="rej-title">Request Rejected</span>
                    <span class="rej-reason" id="tp-reject-reason"></span>
                    <button class="tp-btn-retry" onclick="tpResetToAmount()"><i class="fas fa-redo"></i> Try Again</button>
                </div>

                <!-- ── Completed ── -->
                <div class="tp-done-box" id="tp-step-done">
                    <i class="fas fa-trophy"></i>
                    <span class="done-title">Top-up Completed!</span>
                    <span class="done-sub">Your balance has been updated successfully.</span>
                    <button class="tp-btn-new" onclick="tpResetToAmount()"><i class="fas fa-plus-circle"></i> New Top-up Request</button>
                </div>

            </div>
        </div>
        </div>

        <div class="hub-panel" id="hub-tx">
            <div class="hub-tx-list" id="hubTxList">
                <?php if (empty($transactions)): ?>
                    <div class="hub-empty"><i class="fas fa-receipt"></i><p>تراکنشی وجود ندارد</p></div>
                <?php else: foreach (array_slice($transactions, 0, 8) as $tx):
                    $isIn = ((int)($tx['receiver_id'] ?? 0) === (int)$userId);
                    $sign = $isIn ? '+' : '-';
                    $col  = $isIn ? '#35d07f' : '#ff5a6e';
                    $ic   = $isIn ? 'fa-arrow-down' : 'fa-arrow-up';
                ?>
                    <div class="hub-tx">
                        <div class="hub-tx-ic" style="background:<?php echo $isIn?'rgba(53,208,127,.15)':'rgba(255,90,110,.15)'; ?>;color:<?php echo $col; ?>"><i class="fas <?php echo $ic; ?>"></i></div>
                        <div class="hub-tx-main">
                            <div class="hub-tx-desc"><?php echo htmlspecialchars(mb_substr($tx['description'] ?? ($tx['type'] ?? 'تراکنش'),0,40)); ?></div>
                            <div class="hub-tx-date"><?php echo date('Y/m/d H:i', strtotime($tx['created_at'])); ?></div>
                        </div>
                        <div class="hub-tx-amt" style="color:<?php echo $col; ?>"><?php echo $sign . number_format((float)$tx['amount'], 2) . ' ' . htmlspecialchars($tx['currency']); ?></div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <div class="hub-panel" id="hub-chat">
            <div id="dbChatList" class="db-chat-list"><div class="db-chat-empty">در حال بارگذاری...</div></div>
            <div class="db-chat-input">
                <input type="text" id="dbChatText" placeholder="پیام خود را بنویسید..." onkeydown="if(event.key==='Enter')dbSendChat()">
                <label class="db-chat-file" title="پیوست فایل"><i class="fas fa-paperclip"></i><input type="file" id="dbChatFile" accept="image/*,.pdf" style="display:none" onchange="dbChatFilePicked()"></label>
                <button type="button" onclick="dbSendChat()"><i class="fas fa-paper-plane"></i></button>
            </div>
            <span id="dbChatFileInfo" class="db-chat-fileinfo"></span>
        </div>
    </div>
    <script>
    let _hubChatStarted = false;
    function hubChatInit(){
        if(!_hubChatStarted && typeof dbStartChat==='function'){ _hubChatStarted = true; dbStartChat(); }
        else if (typeof dbLoadChat === 'function') dbLoadChat();
        hubSetSupportBadge(0);   // باز کردن تب یعنی خوانده شد
    }

    /* ---- شمارنده‌ی پیام‌های خوانده‌نشده‌ی پشتیبانی (دایره‌ی قرمز) ---- */
    function hubSetSupportBadge(n){
        const b = document.getElementById('supportBadge');
        if (!b) return;
        n = Number(n) || 0;
        if (n > 0){
            b.textContent = n > 99 ? '+۹۹' : n.toLocaleString('fa-IR');
            b.style.display = '';
        } else {
            b.style.display = 'none';
        }
    }
    window.hubSetSupportBadge = hubSetSupportBadge;

    async function hubPollSupportUnread(){
        // اگر تب پشتیبانی باز است، پیام‌ها همان‌جا خوانده می‌شوند
        const panel = document.getElementById('hub-chat');
        if (panel && panel.classList.contains('active')) { hubSetSupportBadge(0); return; }
        try {
            const r = await fetch('api/support.php?action=unread_count', { cache:'no-store' });
            const d = await r.json();
            if (d && d.success) hubSetSupportBadge(d.unread);
        } catch(e){}
    }
    hubPollSupportUnread();
    setInterval(hubPollSupportUnread, 15000);
    // وقتی کاربر به اپ برمی‌گردد، فوراً بررسی کن
    document.addEventListener('visibilitychange', function(){ if (!document.hidden) hubPollSupportUnread(); });

    function hubSwitch(tab, btn){
        document.querySelectorAll('.hub-tab').forEach(b=>b.classList.remove('active'));
        btn.classList.add('active');
        document.querySelectorAll('.hub-panel').forEach(p=>p.classList.remove('active'));
        const el = document.getElementById('hub-'+tab);
        if (el) el.classList.add('active');
    }
    </script>

        </div>
    </div>

    <!-- بخش‌های قدیمی: مخفی ولی فعال (اسکریپت‌های موجود به این عناصر وابسته‌اند) -->
    <div id="avaLegacyHidden" aria-hidden="true">
    <!-- WALLET DISPLAY -->
    <div class="wallet-display-section" data-avasec="wallet_detail" data-avasec-title="جزئیات کیف پول" data-avasec-icon="fas fa-wallet">
        <div class="wallet-header">
            <i class="fas fa-wallet"></i>
            <span>Wallet Balance</span>
            <button class="refresh-btn" onclick="refreshWalletBalance()" title="Refresh balance">
                <i class="fas fa-sync-alt" id="walletRefreshIcon"></i>
            </button>
        </div>

        <?php
            $curMeta = [
                'USD'  => ['icon' => 'fa-dollar-sign', 'label' => 'USD',  'name' => 'US Dollar'],
                'EUR'  => ['icon' => 'fa-euro-sign',   'label' => 'EUR',  'name' => 'Euro'],
                'USDT' => ['icon' => 'fa-coins',       'label' => 'USDT', 'name' => 'Tether'],
                'IRR'  => ['icon' => 'fa-rial',        'label' => 'IRR',  'name' => 'Toman'],
            ];
            $heroCode = 'USD';
            $heroBal  = $balanceDisplay[$heroCode] ?? 0;
        ?>

        <!-- کارت اصلی (Hero) -->
        <div class="wallet-hero">
            <!-- نمودار روند بالانس (بالای مبلغ، متصل به سوییچر ارز) -->
            <div class="wallet-chart-wrap">
                <canvas id="walletBalanceChart"></canvas>
            </div>
            <div class="wallet-hero-top">
                <span class="wallet-hero-label" id="walletHeroName"><?php echo $curMeta[$heroCode]['name']; ?> Balance</span>
                <span class="wallet-hero-chip"><span class="dot"></span> Active</span>
            </div>
            <div class="wallet-hero-amount">
                <span id="walletHeroAmount"><?php echo number_format($heroBal, 2); ?></span>
                <span class="cur-tag" id="walletHeroCur"><?php echo $heroCode; ?></span>
                <button class="wallet-hero-eye" onclick="toggleWalletVisibility()" title="Hide/Show"><i class="fas fa-eye" id="walletEyeIcon"></i></button>
            </div>
            <div class="wallet-hero-switch" id="walletHeroSwitch">
                <?php foreach ($curMeta as $code => $info): ?>
                <button class="<?php echo $code === $heroCode ? 'active' : ''; ?>"
                        data-code="<?php echo $code; ?>"
                        data-name="<?php echo $info['name']; ?>"
                        data-bal="<?php echo htmlspecialchars($balanceDisplay[$code] ?? 0); ?>"
                        onclick="selectHeroCurrency(this)"><?php echo $info['label']; ?></button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- گرید ارزها حذف شد؛ فقط کارت اصلی (Hero) باقی می‌ماند -->
    </div>

    <script>
    // ===== نمودار روند بالانس داخل کارت WALLET (رفع باگ بزرگ‌شدن) =====
    window.__balanceHistory = window.__balanceHistory || <?php echo json_encode($balanceHistory, JSON_UNESCAPED_UNICODE); ?>;
    let _walletChart = null;
    function renderWalletChart(cur) {
        const canvas = document.getElementById('walletBalanceChart');
        if (!canvas || typeof Chart === 'undefined') return;
        const data = (window.__balanceHistory[cur] || []);
        const labels = data.map(x => { const d = new Date(x.d); return (d.getMonth()+1) + '/' + d.getDate(); });
        const values = data.map(x => Number(x.v));
        // اگر داده کافی نیست، حداقل دو نقطه بساز تا خط دیده شود
        if (values.length < 2) {
            const base = values.length ? values[0] : 0;
            labels.length = 0; values.length = 0;
            labels.push('دیروز', 'امروز'); values.push(base, base);
        }
        // رفع باگ: به‌جای new هر بار، اگر چارت هست فقط داده را آپدیت کن
        if (_walletChart) {
            _walletChart.data.labels = labels;
            _walletChart.data.datasets[0].data = values;
            _walletChart.data.datasets[0].label = cur;
            _walletChart.update('none');
            return;
        }
        const ctx = canvas.getContext('2d');
        const grad = ctx.createLinearGradient(0, 0, 0, 120);
        grad.addColorStop(0, 'rgba(255,215,0,.30)');
        grad.addColorStop(1, 'rgba(255,215,0,0)');
        _walletChart = new Chart(ctx, {
            type: 'line',
            data: { labels, datasets: [{ label: cur, data: values, borderColor: '#FFD700', backgroundColor: grad, borderWidth: 2, fill: true, tension: .4, pointRadius: 2.5, pointBackgroundColor: '#FFD700' }] },
            options: {
                responsive: true,
                maintainAspectRatio: false,   // ارتفاع از کانتینر می‌آید (ثابت)
                animation: { duration: 300 },
                interaction: { intersect: false, mode: 'index' },
                plugins: { legend: { display: false }, tooltip: { rtl: true } },
                scales: {
                    x: { ticks: { color: 'rgba(255,255,255,.45)', font: { size: 9 }, maxRotation: 0 }, grid: { display: false } },
                    y: { ticks: { color: 'rgba(255,255,255,.45)', font: { size: 9 }, maxTicksLimit: 4 }, grid: { color: 'rgba(255,255,255,.06)' } }
                }
            }
        });
    }

    // انتخاب ارز اصلی در کارت Hero
    let _walletHidden = false;
    function selectHeroCurrency(btn) {
        document.querySelectorAll('#walletHeroSwitch button').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        const code = btn.dataset.code;
        const name = btn.dataset.name;
        const bal  = parseFloat(btn.dataset.bal || '0');
        const amtEl = document.getElementById('walletHeroAmount');
        document.getElementById('walletHeroCur').textContent  = code;
        document.getElementById('walletHeroName').textContent = name + ' Balance';
        const formatted = code === 'IRR'
            ? bal.toLocaleString('en-US', {maximumFractionDigits:0})
            : bal.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
        amtEl.dataset.real = formatted;
        amtEl.textContent = _walletHidden ? '••••••' : formatted;
        renderWalletChart(code);   // نمودار با تعویض ارز عوض می‌شود
    }
    function toggleWalletVisibility() {
        _walletHidden = !_walletHidden;
        const icon = document.getElementById('walletEyeIcon');
        const amtEl = document.getElementById('walletHeroAmount');
        if (icon) icon.className = _walletHidden ? 'fas fa-eye-slash' : 'fas fa-eye';
        if (_walletHidden) {
            amtEl.dataset.real = amtEl.textContent;
            amtEl.textContent = '••••••';
        } else {
            amtEl.textContent = amtEl.dataset.real || amtEl.textContent;
        }
    }
    document.addEventListener('DOMContentLoaded', function(){ renderWalletChart('<?php echo $heroCode; ?>'); });
    </script>

    <!-- QUICK ACTIONS -->
    <div class="quick-actions-section">
        <div class="quick-actions-header">
            <i class="fas fa-bolt"></i>
            <span>Quick Actions</span>
        </div>
        <div class="quick-actions-grid">
            <div class="qa-item" data-action="send">
                <div class="qa-circle" style="background:linear-gradient(135deg,#6C40C5,#8B5CF6)"><i class="fas fa-paper-plane"></i></div>
                <span>Send</span>
            </div>
            <div class="qa-item" data-action="withdraw">
                <div class="qa-circle" style="background:linear-gradient(135deg,#FF4D8D,#FF6B35)"><i class="fas fa-money-bill-wave"></i></div>
                <span>Withdraw</span>
            </div>
           
            <div class="qa-item" onclick="window.location.href='transactions.php'">
                <div class="qa-circle" style="background:linear-gradient(135deg,#f0b429,#FF6B35)"><i class="fas fa-receipt"></i></div>
                <span>Transactions</span>
            </div>
            <div id="dynamicActionsContainer" style="display:contents;"></div>
        </div>
    </div>

    </div>

</div><!-- end dashboard -->

<!-- TOP-UP MODALS -->
<div class="tp-modal-overlay" id="tp-my-req-modal">
    <div class="tp-modal-sheet">
        <div class="tp-modal-handle"></div>
        <button class="tp-modal-close" onclick="tpCloseModal('tp-my-req-modal')"><i class="fas fa-times"></i></button>
        <div class="tp-modal-title"><i class="fas fa-history"></i> My Top-up Requests</div>
        <div class="tp-req-list" id="tp-req-list">
            <div style="text-align:center;color:rgba(255,255,255,.3);padding:20px;font-size:.78rem;"><i class="spin fas fa-spinner"></i></div>
        </div>
    </div>
</div>

<div class="tp-modal-overlay" id="tp-detail-modal">
    <div class="tp-modal-sheet">
        <div class="tp-modal-handle"></div>
        <button class="tp-modal-close" onclick="tpCloseModal('tp-detail-modal')"><i class="fas fa-times"></i></button>
        <div class="tp-modal-title"><i class="fas fa-file-invoice-dollar"></i> Request Details</div>
        <div id="tp-detail-body"></div>
    </div>
</div>

<div class="tp-modal-overlay" id="invoice-detail-modal">
    <div class="tp-modal-sheet">
        <div class="tp-modal-handle"></div>
        <button class="tp-modal-close" onclick="tpCloseModal('invoice-detail-modal')"><i class="fas fa-times"></i></button>
        <div class="tp-modal-title"><i class="fas fa-file-invoice"></i> Invoice Details</div>
        <div id="invoice-detail-body"></div>
    </div>
</div>

<div class="tp-modal-overlay" id="invoice-list-modal">
    <div class="tp-modal-sheet">
        <div class="tp-modal-handle"></div>
        <button class="tp-modal-close" onclick="tpCloseModal('invoice-list-modal')"><i class="fas fa-times"></i></button>
        <div class="tp-modal-title" id="invoice-list-modal-title"><i class="fas fa-file-invoice"></i> فیش‌ها</div>
        <div id="invoice-list-modal-body" style="max-height:60vh;overflow-y:auto;"></div>
    </div>
</div>

<!-- News Modal -->
<div class="news-modal-overlay" id="news-modal">
    <div class="news-modal-content">
        <button class="news-modal-close" onclick="closeNewsModal()"><i class="fas fa-times"></i></button>
        <img class="news-modal-img" id="news-modal-img" src="" alt="" style="display:none;">
        <div class="news-modal-pad">
            <div class="news-modal-title" id="news-modal-title"></div>
            <div class="news-modal-date" id="news-modal-date"></div>
            <div class="news-modal-body" id="news-modal-body"></div>
        </div>
    </div>
</div>

<!-- ===== مدال «مشاهده همه» اخبار بازار ===== -->
<div class="ava-fs-modal" id="avaMarketNewsAllModal">
    <div class="ava-fs-top">
        <span><i class="fas fa-newspaper"></i> همه اخبار بازار</span>
        <button type="button" class="ava-fs-close" onclick="avaCloseFsModal('avaMarketNewsAllModal')"><i class="fas fa-times"></i> خروج</button>
    </div>
    <div style="flex:1;overflow-y:auto;padding:16px;" id="avaMarketNewsAllList">
        <div class="ava-empty"><i class="fas fa-spinner fa-spin"></i></div>
    </div>
</div>


<!-- Quick Action Dynamic Modal -->
<div class="modal-overlay" id="quickActionModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);backdrop-filter:blur(6px);z-index:99999;align-items:center;justify-content:center;">
    <div style="background:linear-gradient(135deg,#1A0B2E,#2A0D3F);border:1px solid rgba(255,255,255,0.15);border-radius:24px;padding:28px 22px;width:90%;max-width:440px;max-height:85vh;overflow-y:auto;position:relative;">
        <button onclick="document.getElementById('quickActionModal').style.display='none'" style="position:absolute;top:16px;right:18px;background:none;border:none;color:#fff;font-size:1.4rem;cursor:pointer;">&times;</button>
        <div class="tp-modal-title" id="quickActionModalTitle" style="margin-bottom:16px;font-size:1rem;font-weight:700;"></div>
        <div id="qa-balance-info" class="qa-balance-info"></div>
        <div id="qa-balance-warning" class="qa-balance-warning"></div>
        <div id="quickActionModalBody"></div>
    </div>
</div>

<!-- Existing Modals -->
<div class="modal-overlay" id="sendModal"><div class="modal-content"><div class="modal-header"><h2><i class="fas fa-paper-plane"></i> Send Money</h2><button class="close-modal">&times;</button></div><div class="modal-body"><form id="sendForm"><div class="form-group"><label for="recipient"><i class="fas fa-user"></i> Recipient</label><input type="text" id="recipient" name="recipient" required placeholder="Account number or Telegram ID" autocomplete="off"><div id="recipientInfo"></div></div><div class="form-group"><label for="amount"><i class="fas fa-money-bill-wave"></i> Amount</label><input type="number" id="amount" name="amount" step="0.01" min="0.01" required placeholder="0.00"></div><div class="form-group"><label for="currency"><i class="fas fa-coins"></i> Currency</label><select id="currency" name="currency" required><option value="USD">US Dollar (USD)</option><option value="EUR">Euro (EUR)</option><option value="USDT">Tether (USDT)</option><option value="IRR">Iranian Rial (IRR)</option></select></div><div class="form-group"><label for="description"><i class="fas fa-comment"></i> Description (Optional)</label><input type="text" id="description" name="description" placeholder="What's this for?"></div><button type="submit" class="btn"><i class="fas fa-paper-plane"></i> Send Money</button></form></div></div></div>
<div class="modal-overlay" id="withdrawModal"><div class="modal-content"><div class="modal-header"><h2><i class="fas fa-money-bill-wave"></i> Withdraw Money</h2><button class="close-modal">&times;</button></div><div class="modal-body"><form id="withdrawForm"><div class="form-group"><label for="withdraw_amount"><i class="fas fa-money-bill-wave"></i> Amount</label><input type="number" id="withdraw_amount" name="amount" step="0.01" min="0.01" required placeholder="0.00"></div><div class="form-group"><label for="withdraw_currency"><i class="fas fa-coins"></i> Currency</label><select id="withdraw_currency" name="currency" required><option value="USD">US Dollar (USD)</option><option value="EUR">Euro (EUR)</option><option value="USDT">Tether (USDT)</option><option value="IRR">Iranian Rial (IRR)</option></select></div><div class="form-group"><label for="iban_number"><i class="fas fa-credit-card"></i> IBAN Number</label><input type="text" id="iban_number" name="iban_number" required placeholder="AV55XXXXXX"></div><div class="form-group"><label for="card_number"><i class="fas fa-id-card"></i> Card Number (Optional)</label><input type="text" id="card_number" name="card_number" placeholder="XXXX-XXXX-XXXX-XXXX"></div><div class="form-group"><label for="bank_name"><i class="fas fa-university"></i> Bank Name</label><input type="text" id="bank_name" name="bank_name" required placeholder="Enter bank name"></div><div class="form-group"><label for="recipient_name"><i class="fas fa-user-tie"></i> Recipient Name</label><input type="text" id="recipient_name" name="recipient_name" required placeholder="Full name as in bank account"></div><button type="submit" class="btn"><i class="fas fa-paper-plane"></i> Submit Withdrawal</button></form></div></div></div>

<script>
window.__balanceHistory = <?php echo json_encode($balanceHistory, JSON_UNESCAPED_UNICODE); ?>;
(function(){

  // ---- گفتگو (متصل به api/support.php که با تلگرام کار می‌کند) ----
  let dbLastId = 0;
  let dbChatTimer = null;
  async function dbLoadChat(){
    try{
      // اگر پنل گفتگو باز است، پیام‌های پشتیبانی خوانده‌شده علامت می‌خورند
      const panel = document.getElementById('hub-chat');
      const isOpen = panel ? panel.classList.contains('active') : true;
      const r = await fetch(`api/support.php?action=get_messages&last_id=${dbLastId}${isOpen ? '&mark=1' : ''}`, {cache:'no-store'});
      const d = await r.json();
      if (typeof window.hubSetSupportBadge === 'function') {
        window.hubSetSupportBadge(isOpen ? 0 : (d.unread || 0));
      }
      const box = document.getElementById('dbChatList');
      if (!box) return;
      if (d.messages && d.messages.length){
        if (dbLastId===0) box.innerHTML='';
        d.messages.forEach(m=>{
          dbLastId = Math.max(dbLastId, parseInt(m.id));
          const el = document.createElement('div');
          el.className = 'db-msg ' + (m.sender==='admin'?'admin':'user');
          let inner = m.message ? escapeHtmlDb(m.message) : '';
          if (m.file_path) inner += `<a href="${m.file_path}" target="_blank" class="db-msg-file"><i class="fas fa-paperclip"></i> ${escapeHtmlDb(m.file_name||'فایل')}</a>`;
          el.innerHTML = `<div class="db-msg-b">${inner}</div>`;
          box.appendChild(el);
        });
        box.scrollTop = box.scrollHeight;
      } else if (dbLastId===0){
        box.innerHTML = '<div class="db-chat-empty">هنوز پیامی ندارید. اولین پیام را بفرستید.</div>';
      }
    }catch(e){}
  }
  function escapeHtmlDb(t){ const d=document.createElement('div'); d.textContent=t; return d.innerHTML; }
  window.dbChatFilePicked = function(){ const f=document.getElementById('dbChatFile').files; document.getElementById('dbChatFileInfo').textContent = f.length? f[0].name : ''; };
  window.dbSendChat = async function(){
    const input = document.getElementById('dbChatText');
    const fileEl = document.getElementById('dbChatFile');
    const text = input.value.trim();
    if (!text && !fileEl.files.length) return;
    const fd = new FormData();
    fd.append('message', text);
    if (fileEl.files.length) fd.append('file', fileEl.files[0]);
    input.value=''; document.getElementById('dbChatFileInfo').textContent='';
    const hasFile = fileEl.files.length > 0;
    if (hasFile && typeof window.avaShowUploadProgress === 'function') window.avaShowUploadProgress('در حال آپلود فایل...');
    try{
      if (hasFile && typeof window.avaUploadWithProgress === 'function') {
        await window.avaUploadWithProgress('api/support.php?action=send_message', fd);
        if (typeof window.avaHideUploadProgress === 'function') window.avaHideUploadProgress(true, 'فایل ارسال شد ✅');
      } else {
        await fetch('api/support.php?action=send_message', {method:'POST', body:fd});
      }
      fileEl.value='';
      dbLoadChat();
    }catch(e){ if (hasFile && typeof window.avaHideUploadProgress === 'function') window.avaHideUploadProgress(false); }
  };
  // شروع فقط وقتی تب پشتیبانی باز شود (lazy)
  window.dbLoadChat  = dbLoadChat;
  window.dbStartChat = function(){ dbLoadChat(); if(!dbChatTimer) dbChatTimer = setInterval(dbLoadChat, 7000); };
})();
</script>

<?php require_once 'includes/footer_menu.php'; renderFooterMenu('Home'); ?>

<!-- ============================================================ -->
<!-- نوتیفیکیشن قدیمی آپدیت حذف شد: این بنر فقط صفحه را رفرش می‌کرد
     و نسخه را در دیتابیس به‌روزرسانی نمی‌کرد. مدیریت آپدیت اکنون
     کاملاً توسط VersionManager (assets/js/version-check.js) انجام
     می‌شود که هم مودال صحیح را نشان می‌دهد و هم نسخه را در دیتابیس
     ذخیره می‌کند. -->
<!-- ============================================================ -->

<script>
// ====================================================
// GLOBAL VARIABLES
// ====================================================
window.currentUserId = <?php echo $user['id']; ?>;
window.currentRecipient = null;
window.currentFriendId = null;
window.currentFriendName = null;
window.currentAppVersion = '<?php echo $userVersion ?? '1.0.0'; ?>';

const TP_API  = '/ledor/api/topup_api.php';
const INV_API = '/ledor/api/unpaid_invoice_api.php';
let tpCurrency   = 'USD';
let tpReqId      = null;
let tpRecFiles   = [];
let tpPrevStatus = null;

const TP_QUICK = { USD:[50,100,200,500], EUR:[50,100,200,500], USDT:[20,50,100,250] };
const TP_STEP_MAP = {
    pending:         { step:0, pct:'0%'   },
    approved:        { step:1, pct:'25%'  },
    waiting_payment: { step:2, pct:'50%'  },
    payment_received:{ step:3, pct:'75%'  },
    completed:       { step:4, pct:'100%' },
    finalized:       { step:4, pct:'100%' },
    rejected:        { step:-1, pct:'0%'  }
};

// ====================================================
// UTILITY FUNCTIONS
// ====================================================
function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => toast.classList.add('show'), 10);
    setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 300); }, 3500);
}
function tpToast(msg, type = 'info') { showToast(msg, type); }

function openModal(id)  { document.getElementById(id).style.display = 'flex'; }
function closeModal(id) { document.getElementById(id).style.display = 'none'; }

function tpOpenModal(id) { document.getElementById(id).classList.add('open'); document.body.style.overflow = 'hidden'; }
function tpCloseModal(id) { document.getElementById(id).classList.remove('open'); document.body.style.overflow = ''; }

function tpFmtDate(dt) {
    if (!dt) return '';
    try { return new Date(dt).toLocaleString('en-US', { year:'numeric', month:'short', day:'numeric', hour:'2-digit', minute:'2-digit' }); }
    catch(e) { return dt; }
}
function tpStatusLabel(s) {
    const map = {
        pending:          '⏳ Pending',
        approved:         '✅ Approved',
        waiting_payment:  '📤 Awaiting Confirmation',
        payment_received: '🧾 Receipt Received',
        completed:        '🏆 Completed',
        finalized:        '🏆 Completed',
        rejected:         '❌ Rejected'
    };
    return map[s] || s;
}

document.querySelectorAll('.tp-modal-overlay').forEach(ov => {
    ov.addEventListener('click', e => { if (e.target === ov) tpCloseModal(ov.id); });
});

// ====================================================
// TOP-UP — CURRENCY TABS
// ====================================================
document.querySelectorAll('.tp-cur-tab').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.tp-cur-tab').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        tpCurrency = btn.dataset.cur;
        const badge = document.getElementById('tp-cur-badge');
        if (badge) badge.textContent = tpCurrency;
        tpRenderQuickAmts();
    });
});

function tpRenderQuickAmts() {
    const c = document.getElementById('tp-quick-amts');
    if (!c) return;
    c.innerHTML = TP_QUICK[tpCurrency].map(v =>
        `<button class="tp-quick-amt" data-val="${v}" onclick="tpPickAmt(${v})">${v}</button>`
    ).join('');
}

function tpPickAmt(v) {
    const inp = document.getElementById('tp-amount');
    if (inp) inp.value = v;
    document.querySelectorAll('.tp-quick-amt').forEach(b =>
        b.classList.toggle('sel', parseFloat(b.dataset.val) === v)
    );
}

// ====================================================
// STEP DISPLAY HELPER
// ====================================================
function tpShowStep(step) {
    ['tp-step-amount','tp-step-pending','tp-step-payment','tp-step-waiting','tp-step-done','tp-reject-box'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });
    const map = {
        amount:   'tp-step-amount',
        pending:  'tp-step-pending',
        payment:  'tp-step-payment',
        waiting:  'tp-step-waiting',
        done:     'tp-step-done',
        rejected: 'tp-reject-box'
    };
    const target = map[step];
    if (target) {
        const el = document.getElementById(target);
        if (el) el.style.display = (step === 'rejected') ? 'block' : 'block';
    }
}

function tpResetToAmount() {
    tpReqId = null;
    tpPrevStatus = null;
    tpRecFiles = [];
    const statusBar = document.getElementById('tp-active-status-bar');
    if (statusBar) statusBar.classList.remove('visible');
    const stepper = document.getElementById('tp-stepper');
    if (stepper) stepper.classList.remove('visible');
    tpShowStep('amount');
}

// ====================================================
// TOP-UP — SUBMIT REQUEST
// ====================================================
async function tpSubmitRequest() {
    const inp    = document.getElementById('tp-amount');
    const amount = parseFloat(inp ? inp.value : 0);
    if (!amount || amount <= 0) { tpToast('Please enter an amount', 'error'); return; }

    const btn = document.getElementById('tp-btn-submit');
    btn.innerHTML = '<i class="spin fas fa-spinner"></i> Submitting...';
    btn.disabled  = true;
    try {
        const res  = await fetch(TP_API + '?action=create_request', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ currency: tpCurrency, amount })
        });
        const data = await res.json();
        if (data.success) {
            tpReqId      = data.request_id;
            tpPrevStatus = 'pending';
            tpToast('Request submitted successfully!', 'success');
            if (inp) inp.value = '';
            document.querySelectorAll('.tp-quick-amt').forEach(b => b.classList.remove('sel'));
            tpUpdateActiveStatusBar('pending');
            tpShowStepper('pending');
            tpShowStep('pending');
        } else {
            tpToast(data.message || 'Error submitting request', 'error');
        }
    } catch(e) {
        tpToast('Server error. Please try again.', 'error');
    }
    btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request';
    btn.disabled  = false;
}

// ====================================================
// TOP-UP — ACTIVE STATUS BAR
// ====================================================
function tpUpdateActiveStatusBar(status) {
    const bar = document.getElementById('tp-active-status-bar');
    const txt = document.getElementById('tp-active-status-text');
    if (!bar || !txt) return;

    const statusMap = {
        pending:          { text: '⏳ Waiting for admin approval...', color: '#f59e0b' },
        approved:         { text: '✅ Approved — Please pay and upload receipt', color: '#38bdf8' },
        waiting_payment:  { text: '📤 Receipt uploaded — Awaiting admin confirmation', color: '#a78bfa' },
        payment_received: { text: '🧾 Payment received — Being processed', color: '#c4b5fd' },
        completed:        { text: '🏆 Top-up completed successfully!', color: '#22d3a0' },
        finalized:        { text: '🏆 Top-up finalized!', color: '#22d3a0' },
        rejected:         { text: '❌ Request rejected', color: '#ff4d6d' }
    };
    const info = statusMap[status] || { text: status, color: '#a78bfa' };
    txt.textContent = info.text;
    bar.style.color = info.color;
    bar.style.borderColor = info.color + '55';
    bar.style.background = info.color + '15';
    bar.classList.add('visible');
}

// ====================================================
// TOP-UP — STEPPER
// ====================================================
function tpShowStepper(status) {
    const stepper = document.getElementById('tp-stepper');
    if (!stepper) return;
    stepper.classList.add('visible');

    const info = TP_STEP_MAP[status] || TP_STEP_MAP.pending;
    const bar  = document.getElementById('tp-stepper-bar');
    if (bar) bar.style.width = info.pct;

    for (let i = 0; i <= 4; i++) {
        const dot = document.getElementById('tps-' + i);
        if (!dot) continue;
        dot.classList.remove('active', 'done', 'rej');
        if (status === 'rejected') {
            dot.classList.add(i === 0 ? 'done' : 'rej');
        } else {
            if (i < info.step)        dot.classList.add('done');
            else if (i === info.step) dot.classList.add('active');
        }
    }
}

// ====================================================
// TOP-UP — SHOW PAYMENT INFO
// ====================================================
function tpShowPaymentInfo(req) {
    tpReqId = req.id;

    const payAmount = req.payment_amount ? Number(req.payment_amount).toLocaleString('en-US') : null;
    const payCurrencyLabel = req.payment_currency === 'IRR' ? 'تومان' : (req.payment_currency || '');

    const items = [
        { label:'Bank',      value: req.bank_name      || '-', highlight: false, full: false },
        { label:'Account',   value: req.account_number || '-', highlight: true,  full: false },
        { label:'Card',      value: req.card_number    || '-', highlight: true,  full: false },
        { label:'Recipient', value: req.recipient_name || '-', highlight: false, full: false },
        { label:'IBAN',      value: req.iban            || '-', highlight: true,  full: false },
        { label:'مبلغ قابل پرداخت', value: payAmount ? (payAmount + ' ' + payCurrencyLabel) : (req.amount + ' ' + req.currency), highlight: true, full: true }
    ];
    const detailsEl = document.getElementById('tp-payment-details');
    if (detailsEl) {
        detailsEl.innerHTML = items.map(item => {
            const canCopy = item.value && item.value !== '-';
            const copyBtn = canCopy
                ? `<button class="copy-btn" onclick="tpCopyValue(this,'${item.value.replace(/'/g,"\'")}')" title="Copy">Copy</button>`
                : '';
            return `<div class="tp-payment-item ${item.full ? 'full-width' : ''}">
                <span class="label">${item.label}</span>
                <span class="value ${item.highlight ? 'highlight' : ''}">${item.value}</span>
                ${copyBtn}
            </div>`;
        }).join('');
    }

    tpShowStepper(req.status);
    tpUpdateActiveStatusBar(req.status);

    if (req.status === 'approved') {
        tpShowStep('payment');
        const uploadArea = document.querySelector('#tp-step-payment .tp-upload-area');
        if (uploadArea) uploadArea.style.display = 'block';
    } else if (['waiting_payment','payment_received'].includes(req.status)) {
        tpShowStep('waiting');
        if (req.receipt_image) {
            const thumb = document.getElementById('tp-receipt-thumb');
            if (thumb) { thumb.src = req.receipt_image; }
        }
    }
}

// ====================================================
// TOP-UP — FILE PREVIEW & DRAG DROP
// ====================================================
function tpPreviewReceipt(input) {
    if (!input.files || input.files.length === 0) return;
    tpRecFiles = Array.from(input.files);
    tpRenderPreviews();
    const lbl = document.getElementById('tp-upload-label');
    if (lbl) lbl.textContent = tpRecFiles.length + ' file(s) selected';
    const sendBtn = document.getElementById('tp-btn-send');
    if (sendBtn) sendBtn.style.display = 'block';
}

function tpRenderPreviews() {
    const container = document.getElementById('tp-previews-container');
    if (!container) return;
    container.innerHTML = '';
    container.style.display = tpRecFiles.length ? 'flex' : 'none';
    container.style.flexWrap = 'wrap';
    container.style.gap = '8px';
    tpRecFiles.forEach((file, idx) => {
        const wrapper = document.createElement('div');
        wrapper.style.cssText = 'position:relative;width:80px;height:80px;border-radius:8px;overflow:hidden;border:2px solid #38bdf8;';
        const img = document.createElement('img');
        img.style.cssText = 'width:100%;height:100%;object-fit:cover;';
        img.src = URL.createObjectURL(file);
        const del = document.createElement('button');
        del.innerHTML = '×';
        del.style.cssText = 'position:absolute;top:2px;right:4px;background:rgba(0,0,0,0.6);color:#fff;border:none;border-radius:50%;width:18px;height:18px;cursor:pointer;font-size:12px;line-height:1;padding:0;';
        del.onclick = (e) => { e.stopPropagation(); tpRecFiles.splice(idx,1); tpRenderPreviews(); const lbl=document.getElementById('tp-upload-label'); if(lbl) lbl.textContent = tpRecFiles.length ? tpRecFiles.length+' file(s) selected' : 'Click or drag to upload receipt(s)'; };
        wrapper.appendChild(img);
        wrapper.appendChild(del);
        container.appendChild(wrapper);
    });
}

function tpHandleDrop(e) {
    e.preventDefault();
    const dropped = Array.from(e.dataTransfer.files).filter(f => f.type.startsWith('image/'));
    if (!dropped.length) return;
    tpRecFiles = tpRecFiles.concat(dropped);
    tpRenderPreviews();
    const lbl = document.getElementById('tp-upload-label');
    if (lbl) lbl.textContent = tpRecFiles.length + ' file(s) selected';
    const sendBtn = document.getElementById('tp-btn-send');
    if (sendBtn) sendBtn.style.display = 'block';
}

// ====================================================
// TOP-UP — COPY VALUE
// ====================================================
function tpCopyValue(btn, val) {
    navigator.clipboard.writeText(val).then(() => {
        const orig = btn.textContent;
        btn.textContent = '✓ Copied';
        btn.classList.add('copied');
        setTimeout(() => { btn.textContent = orig; btn.classList.remove('copied'); }, 1800);
    }).catch(() => {
        const ta = document.createElement('textarea');
        ta.value = val;
        ta.style.cssText = 'position:fixed;opacity:0';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        const orig = btn.textContent;
        btn.textContent = '✓ Copied';
        btn.classList.add('copied');
        setTimeout(() => { btn.textContent = orig; btn.classList.remove('copied'); }, 1800);
    });
}

// ====================================================
// TOP-UP — UPLOAD RECEIPT
// ====================================================
async function tpUploadReceipt() {
    if (!tpRecFiles.length) { tpToast('Please select a receipt image', 'error'); return; }
    if (!tpReqId)           { tpToast('No active top-up request found', 'error'); return; }

    const btn = document.getElementById('tp-btn-send');
    btn.innerHTML = '<i class="spin fas fa-spinner"></i>';
    btn.disabled  = true;

    const form = new FormData();
    tpRecFiles.forEach((file, idx) => form.append('receipts[]', file));
    form.append('request_id', tpReqId);
    if (typeof window.avaShowUploadProgress === 'function') window.avaShowUploadProgress('در حال آپلود فیش...');
    try {
        const data = (typeof window.avaUploadWithProgress === 'function')
            ? await window.avaUploadWithProgress(TP_API + '?action=upload_receipt', form)
            : await (await fetch(TP_API + '?action=upload_receipt', { method:'POST', body:form })).json();
        if (data.success) {
            if (typeof window.avaHideUploadProgress === 'function') window.avaHideUploadProgress(true, 'فیش با موفقیت ارسال شد ✅');
            tpToast('Receipt(s) uploaded successfully!', 'success');
            tpPrevStatus = 'waiting_payment';
            tpShowStepper('waiting_payment');
            tpUpdateActiveStatusBar('waiting_payment');
            tpShowStep('waiting');
            const thumb = document.getElementById('tp-receipt-thumb');
            if (thumb && data.image_path) { thumb.src = data.image_path; }
        } else {
            if (typeof window.avaHideUploadProgress === 'function') window.avaHideUploadProgress(false);
            tpToast(data.message || 'Upload failed', 'error');
        }
    } catch(e) {
        if (typeof window.avaHideUploadProgress === 'function') window.avaHideUploadProgress(false);
        tpToast('Server error', 'error');
    }
    btn.innerHTML = '<i class="fas fa-check"></i> Send Receipt';
    btn.disabled  = false;
}

// ====================================================
// TOP-UP — MY REQUESTS MODAL
// ====================================================
async function tpOpenMyRequests() {
    tpOpenModal('tp-my-req-modal');
    const list = document.getElementById('tp-req-list');
    list.innerHTML = '<div style="text-align:center;color:rgba(255,255,255,.3);padding:20px;"><i class="spin fas fa-spinner"></i></div>';
    try {
        const res  = await fetch(TP_API + '?action=get_my_requests');
        const data = await res.json();
        if (!data.success || !data.requests.length) {
            list.innerHTML = '<div style="text-align:center;color:rgba(255,255,255,.3);padding:20px;font-size:.78rem;">No requests found</div>';
            return;
        }
        list.innerHTML = data.requests.map(r => `
            <div class="tp-req-item" onclick="tpOpenDetail(${JSON.stringify(r).replace(/"/g,'&quot;')})">
                <div>
                    <div class="tp-req-amount ${r.currency}">${Number(r.amount).toFixed(2)} ${r.currency}</div>
                    <div class="tp-req-date">${tpFmtDate(r.created_at)}</div>
                </div>
                <span class="tp-badge ${r.status}">${tpStatusLabel(r.status)}</span>
            </div>
        `).join('');
    } catch(e) {
        list.innerHTML = '<div style="text-align:center;color:#ff4d6d;padding:20px;">Error loading requests</div>';
    }
}

// ====================================================
// TOP-UP — DETAIL MODAL
// ====================================================
function tpOpenDetail(req) {
    tpCloseModal('tp-my-req-modal');
    const body = document.getElementById('tp-detail-body');
    let paymentInfo = '';
    const showBankStatuses = ['approved','waiting_payment','payment_received','completed','finalized'];
    if (showBankStatuses.includes(req.status)) {
        const payAmount = req.payment_amount ? Number(req.payment_amount).toLocaleString('en-US') : null;
        const payCurrencyLabel = req.payment_currency === 'IRR' ? 'تومان' : (req.payment_currency || '');
        paymentInfo = `
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 12px;background:rgba(255,255,255,0.03);border-radius:8px;padding:10px;margin-top:6px;font-size:.78rem;">
                ${payAmount ? `<div style="grid-column:1/-1;background:rgba(255,215,0,0.1);border-radius:6px;padding:6px 8px;"><span style="color:rgba(255,255,255,0.5);">مبلغ قابل پرداخت:</span> <span style="font-weight:800;color:#FFD700;">${payAmount} ${payCurrencyLabel}</span></div>` : ''}
                ${req.bank_name      ? `<div><span style="color:rgba(255,255,255,0.4);">Bank:</span> <span style="font-weight:600;">${req.bank_name}</span></div>` : ''}
                ${req.account_number ? `<div><span style="color:rgba(255,255,255,0.4);">Account:</span> <span style="font-weight:600;color:#a78bfa;">${req.account_number}</span></div>` : ''}
                ${req.card_number    ? `<div><span style="color:rgba(255,255,255,0.4);">Card:</span> <span style="font-weight:600;color:#a78bfa;">${req.card_number}</span></div>` : ''}
                ${req.iban           ? `<div><span style="color:rgba(255,255,255,0.4);">IBAN:</span> <span style="font-weight:600;color:#a78bfa;">${req.iban}</span></div>` : ''}
                ${req.recipient_name ? `<div><span style="color:rgba(255,255,255,0.4);">Recipient:</span> <span style="font-weight:600;">${req.recipient_name}</span></div>` : ''}
            </div>`;
    }
    const amtColor = req.currency==='USD' ? '#4ade80' : req.currency==='EUR' ? '#38bdf8' : '#f0b429';
    body.innerHTML = `
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
            <span style="font-size:1.05rem;font-weight:700;color:${amtColor};">${Number(req.amount).toFixed(2)} ${req.currency}</span>
            <span class="tp-badge ${req.status}">${tpStatusLabel(req.status)}</span>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 12px;font-size:.78rem;padding:4px 0;">
            <div><span style="color:rgba(255,255,255,0.4);">ID:</span> <span style="font-weight:600;">#${req.id}</span></div>
            <div><span style="color:rgba(255,255,255,0.4);">Date:</span> <span style="font-weight:600;">${tpFmtDate(req.created_at)}</span></div>
            ${req.reject_reason ? `<div style="grid-column:1/-1;"><span style="color:rgba(255,255,255,0.4);">Reason:</span> <span style="color:#ff4d6d;">${req.reject_reason}</span></div>` : ''}
        </div>
        ${paymentInfo}
        ${req.receipt_image ? `<img src="${req.receipt_image}" style="width:100%;border-radius:10px;max-height:180px;object-fit:cover;margin-top:10px;">` : ''}
        ${req.admin_final_note ? `
            <div style="background:rgba(34,211,160,0.08);border:1px solid rgba(34,211,160,0.2);border-radius:10px;padding:12px;margin-top:10px;">
                <span style="color:#22d3a0;font-weight:600;">Admin Note:</span>
                <p style="font-size:.75rem;color:rgba(255,255,255,0.7);margin-top:4px;">${req.admin_final_note}</p>
                ${req.admin_final_image ? `<img src="${req.admin_final_image}" style="width:100%;max-height:200px;object-fit:cover;border-radius:8px;margin-top:8px;">` : ''}
            </div>` : ''}
    `;
    tpOpenModal('tp-detail-modal');
}

// ====================================================
// INVOICE FUNCTIONS
// ====================================================
// ===== NEWS =====
let _newsLang = 'fa';  // پیش‌فرض: فارسی
async function loadNews(lang) {
    _newsLang = lang || _newsLang;
    const list = document.getElementById('newsList');
    if (!list) return;
    list.innerHTML = '<div class="news-empty"><i class="fas fa-spinner fa-spin"></i></div>';
    try {
        const res = await fetch('/ledor/api/news_api.php?action=get_news&lang=' + _newsLang);
        const data = await res.json();
        if (!data.success || !data.news.length) {
            const msg = _newsLang === 'fa' ? 'خبری موجود نیست' : 'No news available';
            list.innerHTML = `<div class="news-empty"><i class="fas fa-newspaper"></i>${msg}</div>`;
            return;
        }
        window._newsCache = {};
        list.innerHTML = data.news.map(n => {
            window._newsCache[n.id] = n;
            const dir = n.lang === 'fa' ? 'rtl' : 'ltr';
            const thumb = n.image
                ? `<img class="news-item-thumb" src="${n.image}" alt="">`
                : `<div class="news-item-thumb"><i class="fas fa-newspaper"></i></div>`;
            const d = new Date(n.created_at.replace(' ', 'T'));
            const dateStr = isNaN(d) ? '' : d.toLocaleDateString(n.lang === 'fa' ? 'fa-IR' : 'en-US');
            return `
                <div class="news-item" dir="${dir}" onclick="openNewsModal(${n.id})">
                    ${thumb}
                    <div class="news-item-body">
                        <div class="news-item-title">${escapeNewsHtml(n.title)}</div>
                        <div class="news-item-date"><i class="far fa-clock"></i> ${dateStr}</div>
                    </div>
                    <i class="fas fa-chevron-${n.lang === 'fa' ? 'left' : 'right'} news-item-arrow"></i>
                </div>`;
        }).join('');
    } catch(e) {
        list.innerHTML = '<div class="news-empty"><i class="fas fa-exclamation-triangle"></i>Error</div>';
    }
}
function switchNewsLang(lang, el) {
    document.querySelectorAll('.news-lang-tab').forEach(t => t.classList.remove('active'));
    if (el) el.classList.add('active');
    loadNews(lang);
}
function openNewsModal(id) {
    const n = (window._newsCache || {})[id];
    if (!n) return;
    const dir = n.lang === 'fa' ? 'rtl' : 'ltr';
    const img = document.getElementById('news-modal-img');
    if (n.image) { img.src = n.image; img.style.display = 'block'; } else { img.style.display = 'none'; }
    const titleEl = document.getElementById('news-modal-title');
    const bodyEl = document.getElementById('news-modal-body');
    const dateEl = document.getElementById('news-modal-date');
    titleEl.textContent = n.title; titleEl.setAttribute('dir', dir);
    bodyEl.textContent = n.body; bodyEl.setAttribute('dir', dir);
    const d = new Date(n.created_at.replace(' ', 'T'));
    dateEl.textContent = isNaN(d) ? '' : d.toLocaleDateString(n.lang === 'fa' ? 'fa-IR' : 'en-US');
    document.getElementById('news-modal').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeNewsModal() {
    document.getElementById('news-modal').classList.remove('open');
    const stillOpenModal = document.querySelector('.ava-fs-modal.open, .modal-overlay[style*="flex"], .ava-sheet.open');
    document.body.style.overflow = stillOpenModal ? 'hidden' : '';
}
function escapeNewsHtml(s) {
    const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML;
}

async function loadInvoices() {
    const list = document.getElementById('invoiceList');
    if (!list) return;
    try {
        const res  = await fetch(INV_API + '?action=get_my_invoices');
        const data = await res.json();
        if (!data.success || !data.invoices.length) {
            list.innerHTML = `<div class="invoice-empty"><i class="fas fa-file-invoice"></i><span>No invoices</span></div>`;
            return;
        }

        // پرداخت‌نشده = هنوز نیازمند اقدام کاربر (pending / approved)
        const OPEN_STATUSES = ['pending','approved'];
        const openInvoices    = data.invoices.filter(i => OPEN_STATUSES.includes(i.status));
        // تاریخچه = paid / finalized / rejected
        const historyInvoices = data.invoices.filter(i => !OPEN_STATUSES.includes(i.status));

        // برای مدال "مشاهده همه"
        window._allOpenInvoices    = openInvoices;
        window._allHistoryInvoices = historyInvoices;

        const MAX = 3;

        const cell = (inv, isOpen) => {
            const dec = inv.currency === 'IRR' ? 0 : 2;
            const js  = JSON.stringify(inv).replace(/"/g,'&quot;');
            return `
                <div class="inv-cell ${isOpen ? 'open' : ''}" onclick="openInvoiceDetail(${js})">
                    <div class="inv-cell-amount ${inv.currency}">${Number(inv.amount).toFixed(dec)} ${inv.currency}</div>
                    <div class="inv-cell-desc">${inv.description || 'Invoice'} · ${tpFmtDate(inv.created_at)}</div>
                    <div class="inv-cell-foot">
                        <span class="inv-badge ${inv.status}">${invStatusLabel(inv.status)}</span>
                        ${isOpen ? '<span class="inv-pay-hint"><i class="fas fa-hand-pointer"></i> پرداخت</span>' : ''}
                    </div>
                </div>`;
        };

        // ستون فیش‌های پرداخت‌نشده
        let openCol = openInvoices.length
            ? openInvoices.slice(0, MAX).map(inv => cell(inv, true)).join('')
            : `<div class="invoice-empty"><i class="fas fa-check-circle"></i><span>موردی نیست</span></div>`;
        if (openInvoices.length > MAX) {
            openCol += `<div class="invoice-col-more" onclick="openInvoiceListModal('open')">مشاهده همه (${openInvoices.length})</div>`;
        }

        // ستون تاریخچه
        let histCol = historyInvoices.length
            ? historyInvoices.slice(0, MAX).map(inv => cell(inv, false)).join('')
            : `<div class="invoice-empty"><i class="fas fa-history"></i><span>موردی نیست</span></div>`;
        if (historyInvoices.length > MAX) {
            histCol += `<div class="invoice-col-more" onclick="openInvoiceListModal('history')">مشاهده همه (${historyInvoices.length})</div>`;
        }

        list.innerHTML = `
            <div class="invoice-columns">
                <div class="invoice-col">
                    <div class="invoice-col-head unpaid"><i class="fas fa-circle"></i> پرداخت‌نشده</div>
                    ${openCol}
                </div>
                <div class="invoice-col">
                    <div class="invoice-col-head history"><i class="fas fa-history"></i> تاریخچه</div>
                    ${histCol}
                </div>
            </div>`;
    } catch(e) {
        list.innerHTML = `<div class="invoice-empty"><i class="fas fa-exclamation-triangle"></i><span>Error loading invoices</span></div>`;
    }
}

// مدال نمایش کامل یک ستون (وقتی بیش از ۳ مورد باشد)
function openInvoiceListModal(which) {
    const items = which === 'open' ? (window._allOpenInvoices || []) : (window._allHistoryInvoices || []);
    const title = which === 'open' ? 'فیش‌های پرداخت‌نشده' : 'تاریخچه فیش‌ها';
    const body  = document.getElementById('invoice-list-modal-body');
    const titleEl = document.getElementById('invoice-list-modal-title');
    if (!body) return;
    if (titleEl) titleEl.innerHTML = `<i class="fas fa-file-invoice"></i> ${title}`;

    body.innerHTML = items.map(inv => {
        const dec = inv.currency === 'IRR' ? 0 : 2;
        const js  = JSON.stringify(inv).replace(/"/g,'&quot;');
        const isOpen = ['pending','approved'].includes(inv.status);
        return `
            <div class="inv-cell ${isOpen ? 'open' : ''}" style="margin-bottom:8px;" onclick="tpCloseModal('invoice-list-modal'); openInvoiceDetail(${js})">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
                    <div style="min-width:0;">
                        <div class="inv-cell-amount ${inv.currency}">${Number(inv.amount).toFixed(dec)} ${inv.currency}</div>
                        <div class="inv-cell-desc">${inv.description || 'Invoice'} · ${tpFmtDate(inv.created_at)}</div>
                    </div>
                    <span class="inv-badge ${inv.status}">${invStatusLabel(inv.status)}</span>
                </div>
            </div>`;
    }).join('') || '<div class="invoice-empty">موردی یافت نشد</div>';

    tpOpenModal('invoice-list-modal');
}

function invStatusLabel(s) {
    const map = { pending:'⏳ Pending', approved:'✅ Approved', paid:'📤 Paid', finalized:'🏆 Completed', rejected:'❌ Rejected' };
    return map[s] || s;
}

function openInvoiceDetail(inv) {
    const body = document.getElementById('invoice-detail-body');
    if (!body) return;
    let paymentInfo = '';
    if (['approved','paid','finalized'].includes(inv.status)) {
        paymentInfo = `
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 12px;background:rgba(255,255,255,0.03);border-radius:8px;padding:10px;margin-top:6px;font-size:.78rem;">
                ${inv.bank_name      ? `<div><span style="color:rgba(255,255,255,0.4);">Bank:</span> <span style="font-weight:600;">${inv.bank_name}</span></div>` : ''}
                ${inv.account_number ? `<div><span style="color:rgba(255,255,255,0.4);">Account:</span> <span style="font-weight:600;color:#a78bfa;">${inv.account_number}</span></div>` : ''}
                ${inv.card_number    ? `<div><span style="color:rgba(255,255,255,0.4);">Card:</span> <span style="font-weight:600;color:#a78bfa;">${inv.card_number}</span></div>` : ''}
                ${inv.iban           ? `<div><span style="color:rgba(255,255,255,0.4);">IBAN:</span> <span style="font-weight:600;color:#a78bfa;">${inv.iban}</span></div>` : ''}
                ${inv.recipient_name ? `<div><span style="color:rgba(255,255,255,0.4);">Recipient:</span> <span style="font-weight:600;">${inv.recipient_name}</span></div>` : ''}
            </div>`;
    }
    let actionBtn = '';
    if (inv.status === 'approved') {
        actionBtn = `
            <div class="tp-upload-area" onclick="document.getElementById('inv-file').click()" style="margin-top:12px;">
                <i class="fas fa-images"></i>
                <span id="inv-upload-label">Upload Payment Receipt(s) — up to 6 images</span>
                <input type="file" id="inv-file" accept="image/*" multiple onchange="invPreviewReceipt(this, ${inv.id})">
            </div>
            <div id="inv-receipt-previews" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;"></div>
            <button class="tp-btn-send" id="inv-btn-send" onclick="invSubmitReceipt(${inv.id})" style="display:none;">
                <i class="fas fa-check"></i> Send Receipt
            </button>`;
    }
    const invColor = inv.currency==='USD'?'#4ade80':inv.currency==='EUR'?'#38bdf8':inv.currency==='USDT'?'#f0b429':'rgba(255,255,255,0.7)';
    body.innerHTML = `
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
            <span style="font-size:1.05rem;font-weight:700;color:${invColor};">${Number(inv.amount).toFixed(inv.currency==='IRR'?0:2)} ${inv.currency}</span>
            <span class="inv-badge ${inv.status}">${invStatusLabel(inv.status)}</span>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 12px;font-size:.78rem;padding:4px 0;">
            <div><span style="color:rgba(255,255,255,0.4);">ID:</span> <span style="font-weight:600;">#${inv.id}</span></div>
            <div><span style="color:rgba(255,255,255,0.4);">Date:</span> <span style="font-weight:600;">${tpFmtDate(inv.created_at)}</span></div>
            ${inv.description  ? `<div style="grid-column:1/-1;"><span style="color:rgba(255,255,255,0.4);">Description:</span> <span style="font-weight:600;">${inv.description}</span></div>` : ''}
            ${inv.reject_reason? `<div style="grid-column:1/-1;"><span style="color:rgba(255,255,255,0.4);">Reason:</span> <span style="color:#ff4d6d;">${inv.reject_reason}</span></div>` : ''}
        </div>
        ${paymentInfo}
        ${inv.receipt_image ? String(inv.receipt_image).split(',').map(p => `<img src="${p.trim()}" style="width:100%;border-radius:10px;max-height:180px;object-fit:cover;margin-top:10px;">`).join('') : ''}
        ${inv.admin_final_note ? `<div style="background:rgba(34,211,160,0.08);border:1px solid rgba(34,211,160,0.2);border-radius:10px;padding:12px;margin-top:10px;"><span style="color:#22d3a0;font-weight:600;">Admin Note:</span><p style="font-size:.75rem;color:rgba(255,255,255,0.7);margin-top:4px;">${inv.admin_final_note}</p>${inv.admin_final_image ? `<img src="${inv.admin_final_image}" style="width:100%;max-height:200px;object-fit:cover;border-radius:8px;margin-top:8px;">` : ''}</div>` : ''}
        ${actionBtn}
    `;
    tpOpenModal('invoice-detail-modal');
}

let invRecFiles = [];
let invRecId    = null;

function invPreviewReceipt(input, invId) {
    if (!input.files || !input.files.length) return;
    invRecId = invId;
    // اضافه‌کردن فایل‌های جدید به لیست (حداکثر ۶)
    for (const f of input.files) {
        if (invRecFiles.length >= 6) { tpToast('Maximum 6 images', 'error'); break; }
        invRecFiles.push(f);
    }
    input.value = '';
    renderInvPreviews();
}

function renderInvPreviews() {
    const wrap = document.getElementById('inv-receipt-previews');
    const lbl  = document.getElementById('inv-upload-label');
    const btn  = document.getElementById('inv-btn-send');
    if (!wrap) return;
    wrap.innerHTML = '';
    invRecFiles.forEach((f, i) => {
        const cell = document.createElement('div');
        cell.style.cssText = 'position:relative;width:76px;height:76px;border-radius:10px;overflow:hidden;border:2px solid rgba(56,189,248,0.5);';
        const img = document.createElement('img');
        img.src = URL.createObjectURL(f);
        img.style.cssText = 'width:100%;height:100%;object-fit:cover;';
        const del = document.createElement('button');
        del.innerHTML = '&times;';
        del.style.cssText = 'position:absolute;top:2px;right:2px;width:20px;height:20px;border:none;border-radius:50%;background:rgba(0,0,0,0.65);color:#fff;cursor:pointer;font-size:13px;line-height:1;padding:0;';
        del.onclick = (e) => { e.stopPropagation(); invRecFiles.splice(i, 1); renderInvPreviews(); };
        cell.appendChild(img); cell.appendChild(del);
        wrap.appendChild(cell);
    });
    if (lbl) lbl.textContent = invRecFiles.length
        ? `${invRecFiles.length} image(s) selected — tap to add more`
        : 'Upload Payment Receipt(s) — up to 6 images';
    if (btn) btn.style.display = invRecFiles.length ? 'block' : 'none';
}

async function invSubmitReceipt(invId) {
    if (!invRecFiles.length) { tpToast('Please select at least one receipt', 'error'); return; }
    const btn = document.getElementById('inv-btn-send');
    btn.innerHTML = '<i class="spin fas fa-spinner"></i>';
    btn.disabled  = true;
    const form = new FormData();
    invRecFiles.forEach(f => form.append('receipts[]', f));
    form.append('invoice_id', invId);
    if (typeof window.avaShowUploadProgress === 'function') window.avaShowUploadProgress('در حال آپلود فیش...');
    try {
        const data = (typeof window.avaUploadWithProgress === 'function')
            ? await window.avaUploadWithProgress(INV_API + '?action=pay_invoice', form)
            : await (await fetch(INV_API + '?action=pay_invoice', { method:'POST', body:form })).json();
        if (data.success) {
            if (typeof window.avaHideUploadProgress === 'function') window.avaHideUploadProgress(true, 'فیش با موفقیت ارسال شد ✅');
            tpToast('Receipt(s) sent successfully!', 'success');
            invRecFiles = [];
            tpCloseModal('invoice-detail-modal');
            loadInvoices();
        } else {
            if (typeof window.avaHideUploadProgress === 'function') window.avaHideUploadProgress(false);
            tpToast(data.message || 'Error', 'error');
        }
    } catch(e) {
        if (typeof window.avaHideUploadProgress === 'function') window.avaHideUploadProgress(false);
        tpToast('Server error', 'error');
    }
    btn.innerHTML = '<i class="fas fa-check"></i> Send Receipt';
    btn.disabled  = false;
}

// ====================================================
// WALLET REFRESH
// ====================================================
async function refreshWalletBalance() {
    const icon = document.getElementById('walletRefreshIcon');
    if (icon) icon.classList.add('fa-spin');
    try {
        const res  = await fetch('/ledor/api/get_balance.php?user_id=' + window.currentUserId);
        const data = await res.json();
        if (data.success && data.balances) {
            // به‌روزرسانی دکمه‌های ارز در کارت Hero
            document.querySelectorAll('#walletHeroSwitch button').forEach(btn => {
                const cur = btn.dataset.code;
                if (data.balances[cur] !== undefined) btn.dataset.bal = data.balances[cur];
            });
            // به‌روزرسانی مبلغ نمایش‌داده‌شده‌ی فعلی
            const active = document.querySelector('#walletHeroSwitch button.active');
            if (active && typeof selectHeroCurrency === 'function') selectHeroCurrency(active);
        }
    } catch(e) {}
    if (icon) setTimeout(() => icon.classList.remove('fa-spin'), 500);
}

// ====================================================
// POLLING — بررسی وضعیت درخواست هر 8 ثانیه
// ====================================================
setInterval(async () => {
    if (!tpReqId) return;
    try {
        const res  = await fetch(TP_API + '?action=poll_request&request_id=' + tpReqId);
        const data = await res.json();
        if (!data.success || !data.request) return;
        const req = data.request;
        if (req.status === tpPrevStatus) return;

        tpPrevStatus = req.status;
        tpUpdateActiveStatusBar(req.status);

        if (req.status === 'approved') {
            tpShowPaymentInfo(req);
            tpToast('✅ Your request has been approved! Payment details shown.', 'success');
        } else if (req.status === 'rejected') {
            const reason = document.getElementById('tp-reject-reason');
            if (reason) reason.textContent = req.reject_reason || '';
            tpShowStepper('rejected');
            tpShowStep('rejected');
            tpToast('❌ Request rejected', 'error');
        } else if (req.status === 'completed' || req.status === 'finalized') {
            tpShowStepper('completed');
            tpShowStep('done');
            tpToast('🏆 Top-up completed successfully! Balance updated.', 'success');
            refreshWalletBalance();
        } else {
            tpShowStepper(req.status);
        }
    } catch(e) {}
}, 8000);

// ====================================================
// LOAD LATEST REQUEST
// ====================================================
async function tpLoadLatestRequest() {
    try {
        const res  = await fetch(TP_API + '?action=get_my_requests');
        const data = await res.json();
        if (!data.success || !data.latest) return;

        const req = data.latest;
        const agedays = (Date.now() - new Date(req.created_at).getTime()) / 86400000;
        if (agedays > 30) return;
        if (['completed','finalized'].includes(req.status) && agedays > 3) return;

        tpReqId      = req.id;
        tpPrevStatus = req.status;

        tpUpdateActiveStatusBar(req.status);

        if (req.status === 'approved') {
            tpShowPaymentInfo(req);
            tpShowStepper(req.status);
        } else if (req.status === 'rejected') {
            const reason = document.getElementById('tp-reject-reason');
            if (reason) reason.textContent = req.reject_reason || '';
            tpShowStepper('rejected');
            tpShowStep('rejected');
        } else if (req.status === 'completed' || req.status === 'finalized') {
            tpShowStepper('completed');
            tpShowStep('done');
        } else if (['waiting_payment','payment_received'].includes(req.status)) {
            tpShowStepper(req.status);
            tpShowStep('waiting');
            if (req.receipt_image) {
                const thumb = document.getElementById('tp-receipt-thumb');
                if (thumb) { thumb.src = req.receipt_image; }
            }
            if (req.bank_name) tpShowPaymentInfo(req);
        } else if (req.status === 'pending') {
            tpShowStepper('pending');
            tpShowStep('pending');
        }
    } catch(e) {
        console.warn('tpLoadLatestRequest failed:', e);
    }
}

// ====================================================
// QUICK ACTIONS (Static)
// ====================================================
function handleQuickAction(action) {
    switch(action) {
        case 'send':     openModal('sendModal');     break;
        case 'withdraw': openModal('withdrawModal'); break;
        default: showToast('Feature coming soon!', 'info');
    }
}
document.querySelectorAll('.qa-item[data-action]').forEach(btn => {
    btn.addEventListener('click', () => handleQuickAction(btn.dataset.action));
});

// ====================================================
// DYNAMIC QUICK ACTIONS
// ====================================================
let currentUserBalances = { USD: 0, EUR: 0, USDT: 0, IRR: 0 };

async function loadUserBalancesForQA() {
    try {
        const res = await fetch('/ledor/api/get_balance.php?user_id=' + window.currentUserId);
        const data = await res.json();
        if (data.success && data.balances) {
            currentUserBalances = data.balances;
        }
    } catch(e) {}
}

(async function() {
    try {
        await loadUserBalancesForQA();
        const res  = await fetch('/ledor/api/slides_quickactions_api.php?action=get_quick_actions');
        const data = await res.json();
        if (!data.success || !data.actions?.length) return;
        const c = document.getElementById('dynamicActionsContainer');
        data.actions.forEach(act => {
            const div  = document.createElement('div');
            div.className = 'qa-item';
            const icon = act.icon_image
                ? `<img src="${act.icon_image}" style="width:58px;height:58px;object-fit:cover;border-radius:6px;">`
                : `<i class="${act.icon || 'fas fa-bolt'}"></i>`;
            div.innerHTML = `<div class="qa-circle" style="background:${act.color};">${icon}</div><span>${act.label}</span>`;
            div.onclick   = () => openQuickActionForm(act);
            c.appendChild(div);
        });
    } catch(e) {}
})();

function openQuickActionForm(action) {
    const icon = action.icon_image
        ? `<img src="${action.icon_image}" style="width:22px;height:22px;object-fit:cover;border-radius:4px;vertical-align:middle;">`
        : `<i class="${action.icon||'fas fa-bolt'}"></i>`;
    document.getElementById('quickActionModalTitle').innerHTML = `${icon} ${action.label}`;

    const balInfoEl = document.getElementById('qa-balance-info');
    const balWarnEl = document.getElementById('qa-balance-warning');
    balInfoEl.style.display = 'none';
    balWarnEl.style.display = 'none';

    const minBalance  = parseFloat(action.min_balance || 0);
    const minCurrency = action.min_balance_currency || 'USD';
    const deductBalance = parseInt(action.deduct_balance || 0);
    const deductAmount  = parseFloat(action.deduct_amount || 0);
    const deductCurrency = action.deduct_currency || 'USD';

    if (minBalance > 0) {
        const userBal = parseFloat(currentUserBalances[minCurrency] || 0);
        if (userBal < minBalance) {
            balWarnEl.innerHTML = `⚠️ Insufficient balance. Minimum required: <strong>${minBalance} ${minCurrency}</strong> (Your balance: ${userBal.toFixed(2)} ${minCurrency})`;
            balWarnEl.style.display = 'block';
        } else {
            let infoText = `💰 Your ${minCurrency} balance: <strong>${parseFloat(currentUserBalances[minCurrency] || 0).toFixed(2)} ${minCurrency}</strong>`;
            if (deductBalance && deductAmount > 0) {
                infoText += `<br>⚡ Amount to deduct: <strong>${deductAmount} ${deductCurrency}</strong>`;
            }
            balInfoEl.innerHTML = infoText;
            balInfoEl.style.display = 'block';
        }
    } else if (deductBalance && deductAmount > 0) {
        const userBal = parseFloat(currentUserBalances[deductCurrency] || 0);
        let infoText = `💰 Your ${deductCurrency} balance: <strong>${userBal.toFixed(2)} ${deductCurrency}</strong><br>⚡ Amount to deduct: <strong>${deductAmount} ${deductCurrency}</strong>`;
        if (userBal < deductAmount) {
            balWarnEl.innerHTML = `⚠️ Insufficient balance for this action. Required: <strong>${deductAmount} ${deductCurrency}</strong>`;
            balWarnEl.style.display = 'block';
        } else {
            balInfoEl.innerHTML = infoText;
            balInfoEl.style.display = 'block';
        }
    }

    const body = document.getElementById('quickActionModalBody');
    if (!action.fields?.length) {
        body.innerHTML = '<p style="text-align:center;color:#B8B8D1;padding:20px;">No form fields configured.</p>';
    } else {
        // ===== ویزارد چندمرحله‌ای: هر مرحله ۳ فیلد =====
        const PER_STEP = 3;
        const steps = [];
        for (let i = 0; i < action.fields.length; i += PER_STEP) {
            steps.push(action.fields.slice(i, i + PER_STEP));
        }

        const fieldHtml = (f) => {
            let inner = '';
            if (f.field_type === 'textarea') {
                inner = `<textarea name="${f.id}" class="form-input" placeholder="${f.field_placeholder || ''}" ${f.is_required ? 'required' : ''}></textarea>`;
            } else if (f.field_type === 'select') {
                const opts = String(f.field_options || '').split(',').map(o => o.trim()).filter(Boolean);
                inner = `<select name="${f.id}" class="form-input" ${f.is_required ? 'required' : ''}>
                    <option value="" disabled selected>${f.field_placeholder || 'انتخاب کنید...'}</option>
                    ${opts.map(o => `<option value="${o.replace(/"/g,'&quot;')}">${o}</option>`).join('')}
                </select>`;
            } else {
                inner = `<input type="${f.field_type}" name="${f.id}" class="form-input" placeholder="${f.field_placeholder || ''}" ${f.is_required ? 'required' : ''}>`;
            }
            return `<div class="form-group"><label>${f.field_label}${f.is_required ? '<span style="color:#FF3B30"> *</span>' : ''}</label>${inner}</div>`;
        };

        let html = '<form id="dynActionForm">';
        // نوار پیشرفت
        html += `<div id="qaWizardProgress" style="display:flex;gap:6px;margin-bottom:16px;">` +
            steps.map((_, si) => `<div class="qa-wiz-dot" data-step="${si}" style="flex:1;height:5px;border-radius:4px;background:${si === 0 ? 'linear-gradient(90deg,#6C40C5,#FF4D8D)' : 'rgba(255,255,255,0.12)'};transition:background .3s;"></div>`).join('') +
            `</div>`;
        html += `<div style="text-align:center;color:#B8B8D1;font-size:.72rem;margin-bottom:12px;" id="qaWizardCounter">مرحله ۱ از ${steps.length}</div>`;

        steps.forEach((stepFields, si) => {
            html += `<div class="qa-wiz-step" data-step="${si}" style="display:${si === 0 ? 'block' : 'none'};">`;
            stepFields.forEach(f => { html += fieldHtml(f); });
            html += '</div>';
        });

        // دکمه‌های ناوبری
        html += `<div style="display:flex;gap:8px;margin-top:14px;">
            <button type="button" id="qaWizPrev" class="btn" style="flex:1;background:rgba(255,255,255,0.08);display:none;"><i class="fas fa-arrow-right"></i> قبلی</button>
            <button type="button" id="qaWizNext" class="btn" style="flex:2;">مرحله بعد <i class="fas fa-arrow-left"></i></button>
            <button type="submit" id="qaWizSubmit" class="btn" style="flex:2;display:none;"><i class="fas fa-paper-plane"></i> ارسال</button>
        </div></form>`;
        body.innerHTML = html;

        let curStep = 0;
        const totalSteps = steps.length;
        const showStep = (n) => {
            curStep = n;
            body.querySelectorAll('.qa-wiz-step').forEach(el => {
                el.style.display = Number(el.dataset.step) === n ? 'block' : 'none';
            });
            body.querySelectorAll('.qa-wiz-dot').forEach(el => {
                el.style.background = Number(el.dataset.step) <= n ? 'linear-gradient(90deg,#6C40C5,#FF4D8D)' : 'rgba(255,255,255,0.12)';
            });
            const counter = document.getElementById('qaWizardCounter');
            if (counter) counter.textContent = `مرحله ${n + 1} از ${totalSteps}`;
            document.getElementById('qaWizPrev').style.display = n > 0 ? 'block' : 'none';
            document.getElementById('qaWizNext').style.display = n < totalSteps - 1 ? 'block' : 'none';
            document.getElementById('qaWizSubmit').style.display = n === totalSteps - 1 ? 'block' : 'none';
        };
        const validateStep = (n) => {
            const stepEl = body.querySelector(`.qa-wiz-step[data-step="${n}"]`);
            let ok = true;
            stepEl.querySelectorAll('input, textarea, select').forEach(inp => {
                if (inp.required && !inp.value.trim()) {
                    inp.style.borderColor = '#FF3B30';
                    ok = false;
                } else if (inp.required && !inp.checkValidity()) {
                    inp.style.borderColor = '#FF3B30';
                    ok = false;
                } else {
                    inp.style.borderColor = '';
                }
            });
            if (!ok) showToast('لطفاً فیلدهای الزامی این مرحله را کامل کنید', 'error');
            return ok;
        };
        document.getElementById('qaWizNext').onclick = () => {
            if (validateStep(curStep)) showStep(curStep + 1);
        };
        document.getElementById('qaWizPrev').onclick = () => showStep(curStep - 1);
        showStep(0);

        document.getElementById('dynActionForm').onsubmit = async (e) => {
            e.preventDefault();
            if (!validateStep(curStep)) return;
            const fd  = {};
            new FormData(e.target).forEach((v, k) => fd[k] = v);
            const btn = document.getElementById('qaWizSubmit');
            btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
            try {
                const res  = await fetch('/ledor/api/slides_quickactions_api.php?action=submit_form', {
                    method: 'POST', headers: {'Content-Type':'application/json'},
                    body: JSON.stringify({action_id:action.id, form_data:fd})
                });
                const data = await res.json();
                if (data.success) {
                    document.getElementById('quickActionModal').style.display='none';
                    showToast('Your request has been submitted!','success');
                    if (data.deducted) {
                        showToast(`💰 ${data.deducted.amount} ${data.deducted.currency} deducted from your balance`, 'info');
                        refreshWalletBalance();
                        await loadUserBalancesForQA();
                    }
                } else {
                    showToast(data.message || 'Error submitting form','error');
                }
            } catch(e) { showToast('Network error','error'); }
            finally { btn.disabled=false; btn.innerHTML='<i class="fas fa-paper-plane"></i> ارسال'; }
        };
    }
    document.getElementById('quickActionModal').style.display = 'flex';
}

// ====================================================
// SLIDES — بارگذاری اسلایدها از API
// ====================================================
async function loadDashboardSlides() {
    // بازنشسته شد: اسلایدها اکنون به‌صورت بنرهای جای‌گذاری‌شده در
    // assets/js/dashboard-enhance.js رندر می‌شوند (هر بنر زیر بخش دلخواه ادمین).
    // این تابع فقط برای سازگاری با اسکریپت‌های قدیمی باقی مانده است.
    return;
    /* eslint-disable no-unreachable */
    try {
        const res  = await fetch('/ledor/api/slides_quickactions_api.php?action=get_slides');
        const data = await res.json();
        if (!data.success || !data.slides?.length) return;

        const track = document.getElementById('sliderTrack');
        const dots  = document.getElementById('sliderDots');
        if (!track || !dots) return;

        track.innerHTML = '';
        dots.innerHTML  = '';

        data.slides.forEach((slide, i) => {
            const content = slide.image_url
                ? `<img src="${slide.image_url}" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:.6;">`
                : `<div class="slider-slide-icon"><i class="${slide.icon || 'fas fa-star'}"></i></div>`;

            const slideEl = document.createElement('div');
            slideEl.className = 'slider-slide';
            slideEl.style.background = slide.bg_color || 'linear-gradient(135deg,#6C40C5,#FF4D8D)';
            if (slide.link) slideEl.onclick = () => window.location.href = slide.link;
            slideEl.innerHTML = `
                ${content}
                <div class="slider-slide-title" style="position:relative;z-index:1;">${slide.title || ''}</div>
                <div class="slider-slide-desc" style="position:relative;z-index:1;">${slide.description || ''}</div>
            `;
            track.appendChild(slideEl);

            const dot = document.createElement('button');
            dot.className = 'slider-dot' + (i === 0 ? ' active' : '');
            dot.onclick = () => goToSlide(i);
            dots.appendChild(dot);
        });

        let currentSlide = 0;
        const totalSlides = data.slides.length;
        function goToSlide(n) {
            currentSlide = n;
            track.style.transform = `translateX(-${n * 100}%)`;
            dots.querySelectorAll('.slider-dot').forEach((d, i) => d.classList.toggle('active', i === n));
        }
        if (totalSlides > 1) {
            setInterval(() => goToSlide((currentSlide + 1) % totalSlides), 4000);
        }
    } catch(e) {}
}

// ====================================================
// سیستم مدیریت آپدیت نسخه اکنون به‌طور کامل توسط
// VersionManager در assets/js/version-check.js انجام می‌شود
// (مودال اجباری + ذخیره‌ی واقعی نسخه در دیتابیس از طریق
// api/version_sync.php). توابع قدیمی checkForUpdates /
// showUpdateNotification که فقط صفحه را رفرش می‌کردند حذف شدند.
// ====================================================
// DOMContentLoaded — راه‌اندازی همه چیز
// ====================================================
// ===== هم‌ارتفاع‌سازی اجباری کارت‌های نوار «فعالیت و دسترسی سریع» =====
// align-items:stretch در CSS باید همین کار را بکند، اما چون این چهار کارت
// محتوای متفاوت دارند (بعضی عکس/آیکون async لود می‌کنند و ارتفاعشان بعد از
// لود تغییر می‌کند) و روی برخی مرورگرها/دستگاه‌ها stretch به‌تنهایی کافی
// نبود، اینجا صراحتاً ارتفاع همه را با اندازه‌ی بلندترین کارت یکی می‌کنیم.
function avaEqualizeQuadHeights() {
    const wrap = document.querySelector('.ava-quad-scroll');
    if (!wrap) return;
    const cards = Array.from(wrap.children).filter(el => el.classList.contains('ava-card'));
    if (cards.length < 2) return;
    cards.forEach(c => { c.style.minHeight = ''; });
    let max = 0;
    cards.forEach(c => { max = Math.max(max, c.offsetHeight); });
    if (max > 0) cards.forEach(c => { c.style.minHeight = max + 'px'; });
}

document.addEventListener('DOMContentLoaded', () => {
    tpRenderQuickAmts();
    tpLoadLatestRequest();
    loadInvoices();
    loadNews('fa');
    loadDashboardSlides();

    // اگر از اسکنر QR شماره حساب آمده، فرم ارسال وجه را باز کن
    try {
        const _sendTo = new URLSearchParams(window.location.search).get('send_to');
        if (_sendTo) {
            const rcp = document.getElementById('recipient');
            if (rcp) {
                rcp.value = _sendTo;
                openModal('sendModal');
                rcp.dispatchEvent(new Event('input'));
            }
        }
    } catch(e) {}

    // اگر از منوی پایین (کیف پول) آمده، مستقیماً کارت کیف پول باز شود
    try {
        const _openTarget = new URLSearchParams(window.location.search).get('open');
        if (_openTarget === 'wallet' && typeof avaOpenTopup === 'function') {
            setTimeout(() => avaOpenTopup(), 250);
        } else if (_openTarget === 'support' && typeof avaOpenSupport === 'function') {
            setTimeout(() => avaOpenSupport(), 250);
        }
    } catch(e) {}

    // ===== راه‌اندازی سیستم آپدیت =====
    // VersionManager (از assets/js/version-check.js) خودش در سازنده‌ی
    // کلاس init() را صدا می‌زند و چک نسخه + مودال اجباری + ذخیره در
    // دیتابیس را مدیریت می‌کند. اینجا فقط برای اطمینان یک بار دیگر هم
    // چک می‌کنیم.
    if (window.versionManager) {
        window.versionManager.checkVersion();
        
        setInterval(() => {
            if (window.versionManager) {
                window.versionManager.checkVersion();
            }
        }, 5000);
    }
    
    console.log('📱 PWA Mode:', window.matchMedia('(display-mode: standalone)').matches);

    // اجرای هم‌ارتفاع‌سازی نوار «فعالیت و دسترسی سریع»: موقع لود اولیه، بعد از
    // تغییر سایز صفحه، بعد از لود هر عکس داخل این کارت‌ها (چون عکس‌ها می‌توانند
    // ارتفاع طبیعی را عوض کنند)، و بعد از آماده‌شدن فونت Vazirmatn (که دیرتر
    // لود می‌شود و می‌تواند عرض/ارتفاع متن‌ها را کمی عوض کند).
    avaEqualizeQuadHeights();
    window.addEventListener('resize', avaEqualizeQuadHeights);
    document.querySelectorAll('.ava-quad-scroll img').forEach(img => {
        if (img.complete) return;
        img.addEventListener('load', avaEqualizeQuadHeights, { once: true });
        img.addEventListener('error', avaEqualizeQuadHeights, { once: true });
    });
    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(avaEqualizeQuadHeights).catch(() => {});
    }
});
</script>
<script src="/ledor/assets/js/push-init.js"></script>
<script>
const currentUserId = <?php echo $userId; ?>;
document.addEventListener('DOMContentLoaded', () => setTimeout(() => window.initPushNotifications?.(currentUserId), 2000));
</script>

<!-- ===== اسکریپت داشبورد جدید ===== -->
<script>
/* --- اعداد فارسی --- */
function avaFa(s){ return String(s).replace(/[0-9]/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]); }

/* --- شیت‌ها ---
   (اصلاح) هم اینجا هم مودال‌های تمام‌صفحه‌ی پایین‌تر، باز شدن یک state تاریخچه
   push می‌کند — طبق درخواست کاربر که «کشیدن انگشت از چپ به راست» (ژست بازگشت
   خودِ سیستم‌عامل/مرورگر) باید همیشه مودال را ببندد، نه هیچ‌کاری نکند یا از
   کل صفحه خارج شود. چون این ژست لبه‌ای، رویدادِ سطحِ سیستم‌عامل است نه صرفاً
   touchmove معمولی، مطمئن‌ترین راه استاندارد این است که خودِ مکانیزم بازگشتِ
   مرورگر (popstate) را هدف بگیریم، نه اینکه سعی کنیم touch را دستی تشخیص
   بدهیم و با آن رقابت کنیم. */
function avaOpenSheet(id){ const el=document.getElementById(id); if(el){ el.classList.add('open'); document.body.style.overflow='hidden'; try{ history.pushState({avaLayer:true}, ''); }catch(e){} } }
function avaCloseSheet(id){
    id = id || 'avaTopupSheet';
    // (رفع باگ) همان محافظِ ضدِ فراخوانیِ دوبار که avaCloseFsModal دارد — بدون
    // آن، یک دابل‌تپ سریع روی دکمه‌ی بستن می‌توانست دوبار history.back() بزند
    // و لایه‌ی زیرین (مثلاً مودال اصلیِ پیش‌بینی) را هم به‌اشتباه ببندد.
    const now = Date.now();
    if (!window.__avaLastCloseAt) window.__avaLastCloseAt = {};
    if (window.__avaLastCloseAt[id] && (now - window.__avaLastCloseAt[id]) < 400) return;
    window.__avaLastCloseAt[id] = now;

    const el=document.getElementById(id);
    if(el){ el.classList.remove('open'); document.body.style.overflow=''; }
    if(id==='avaBenViewSheet' && window.avaBenChart){ window.avaBenChart.destroy(); }
    document.querySelectorAll('.bottom-nav').forEach(function(nav){
        nav.style.pointerEvents = 'none';
        setTimeout(function(){ nav.style.pointerEvents = ''; }, 450);
    });
    avaConsumeLayerHistoryState();
}
function avaSheetMode(mode){
    const sheet = document.getElementById('avaTopupSheet');
    if (sheet) sheet.classList.remove('ava-only-topup','ava-only-chat');
    if (sheet && mode) sheet.classList.add('ava-only-' + mode);
}
function avaOpenTopup(){ avaSheetMode('topup'); avaOpenSheet('avaTopupSheet'); try{ hubSwitch('topup', document.querySelector('.hub-tab[data-tab="topup"]')); }catch(e){} }

function avaOpenSupport(){ avaSheetMode('chat'); avaOpenSheet('avaTopupSheet'); try{ hubSwitch('chat', document.querySelector('.hub-tab[data-tab="chat"]')); hubChatInit(); }catch(e){} avaSetHeadsetDot(0); }


/* --- اخبار بازار: تب فارسی/انگلیسی + مدال --- */
window.__avaNewsCache = window.__avaNewsCache || {};
window.__avaNewsAllItems = window.__avaNewsAllItems || [];
async function avaNewsLoad(lang, el){
    if (el){ document.querySelectorAll('.ava-news-tab').forEach(t=>t.classList.remove('active')); el.classList.add('active'); }
    const box = document.getElementById('avaNewsList');
    const allBtn = document.getElementById('avaNewsAllBtn');
    if (!box) return;
    box.innerHTML = '<div class="ava-empty"><i class="fas fa-spinner fa-spin"></i></div>';
    if (allBtn) allBtn.style.display = 'none';
    try {
        const res = await fetch('/ledor/api/news_api.php?action=get_news&lang=' + (lang || 'fa'));
        const data = await res.json();
        const items = (data && (data.news || data.items)) || [];
        if (!items.length){
            box.innerHTML = '<div class="ava-empty">' + (lang === 'en' ? 'No news available' : 'خبری موجود نیست') + '</div>';
            window.__avaNewsAllItems = [];
            return;
        }
        window.__avaNewsAllItems = items;
        const renderNewsItem = n => {
            window.__avaNewsCache[n.id] = n;
            const dir = (n.lang === 'en') ? 'ltr' : 'rtl';
            const thumb = n.image
                ? `<div class="ava-news-thumb"><img src="${n.image}" alt=""></div>`
                : `<div class="ava-news-thumb"><i class="fas fa-newspaper"></i></div>`;
            let dateStr = '';
            try { const d = new Date((n.created_at||'').replace(' ','T')); if(!isNaN(d)) dateStr = d.toLocaleDateString(dir==='ltr'?'en-US':'fa-IR'); } catch(e){}
            const title = (n.title || '').toString();
            return `<div class="ava-news-item" dir="${dir}" onclick="avaNewsOpen(${n.id})">
                        ${thumb}
                        <div style="flex:1;min-width:0;">
                            <div class="ava-news-title">${avaEsc(title)}</div>
                            <div class="ava-news-date"><i class="far fa-clock"></i> ${dateStr}</div>
                        </div>
                        <i class="fas fa-chevron-${dir==='ltr'?'right':'left'}" style="color:var(--ava-mut);font-size:.6rem;"></i>
                    </div>`;
        };
        box.innerHTML = items.slice(0, 2).map(renderNewsItem).join('');
        if (allBtn) allBtn.style.display = (items.length > 2) ? '' : 'none';
    } catch(e){
        box.innerHTML = '<div class="ava-empty">خطا در بارگذاری اخبار</div>';
    }
}
function avaMarketNewsOpenAll(){
    const list = document.getElementById('avaMarketNewsAllList');
    const items = window.__avaNewsAllItems || [];
    if (list){
        if (!items.length){
            list.innerHTML = '<div class="ava-empty">خبری موجود نیست</div>';
        } else {
            list.innerHTML = items.map(n => {
                window.__avaNewsCache[n.id] = n;
                const dir = (n.lang === 'en') ? 'ltr' : 'rtl';
                const thumb = n.image
                    ? `<div class="ava-news-thumb"><img src="${n.image}" alt=""></div>`
                    : `<div class="ava-news-thumb"><i class="fas fa-newspaper"></i></div>`;
                let dateStr = '';
                try { const d = new Date((n.created_at||'').replace(' ','T')); if(!isNaN(d)) dateStr = d.toLocaleDateString(dir==='ltr'?'en-US':'fa-IR'); } catch(e){}
                const title = (n.title || '').toString();
                return `<div class="ava-news-item" style="margin-bottom:10px;" dir="${dir}" onclick="avaNewsOpen(${n.id})">
                            ${thumb}
                            <div style="flex:1;min-width:0;">
                                <div class="ava-news-title">${avaEsc(title)}</div>
                                <div class="ava-news-date"><i class="far fa-clock"></i> ${dateStr}</div>
                            </div>
                            <i class="fas fa-chevron-${dir==='ltr'?'right':'left'}" style="color:var(--ava-mut);font-size:.6rem;"></i>
                        </div>`;
            }).join('');
        }
    }
    avaOpenFsModal('avaMarketNewsAllModal');
}
/* نسخه‌ی امن‌تر: کوتیشن‌ها را هم escape می‌کند (نسخه‌ی مبتنی بر innerHTML این کار را نمی‌کرد) */
function avaEsc(s){ return String(s==null?'':s).replace(/[&<>"']/g, function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }
function avaNewsOpen(id){
    const n = window.__avaNewsCache[id];
    if (!n) return;
    const dir = (n.lang === 'en') ? 'ltr' : 'rtl';
    const img = document.getElementById('news-modal-img');
    if (img){ if (n.image){ img.src = n.image; img.style.display='block'; } else { img.style.display='none'; } }
    const titleEl = document.getElementById('news-modal-title');
    const bodyEl  = document.getElementById('news-modal-body');
    const dateEl  = document.getElementById('news-modal-date');
    if (titleEl){ titleEl.textContent = n.title || ''; titleEl.setAttribute('dir', dir); }
    if (bodyEl){ bodyEl.textContent = (n.body || n.content || ''); bodyEl.setAttribute('dir', dir); }
    if (dateEl){ let ds=''; try{ const d=new Date((n.created_at||'').replace(' ','T')); if(!isNaN(d)) ds=d.toLocaleDateString(dir==='ltr'?'en-US':'fa-IR'); }catch(e){} dateEl.textContent = ds; }
    const modal = document.getElementById('news-modal');
    if (modal){ modal.classList.add('open'); document.body.style.overflow='hidden'; }
}
document.addEventListener('DOMContentLoaded', function(){ avaNewsLoad('fa'); avaIrnLoad(); });

/* --- کارت «نرخ‌های مورد علاقه» باید همیشه کارت فعال/اول در اسلایدر سبد دارایی/نرخ‌ها باشد ---
   توجه: عمداً از scrollIntoView استفاده نمی‌شود چون می‌تواند باعث اسکرول عمودیِ کل صفحه هم بشود
   (که باعث می‌شد داشبورد هنگام باز شدن از وسط صفحه نشان داده شود). فقط scrollLeft همین
   کانتینر افقی را مستقیماً تنظیم می‌کنیم تا اسکرول عمودی صفحه دست‌نخورده بماند. */
function avaScrollContainerToStart(container, el){
    if (!container || !el) return;
    var rectC = container.getBoundingClientRect();
    var rectE = el.getBoundingClientRect();
    container.scrollLeft += Math.round(rectE.left - rectC.left);
}
document.addEventListener('DOMContentLoaded', function(){
    var midGrid = document.querySelector('.ava-mid-grid');
    var ratesCard = document.getElementById('avaRatesCard');
    if (midGrid && ratesCard) avaScrollContainerToStart(midGrid, ratesCard);
});

/* --- تضمین اینکه باز شدن داشبورد همیشه از بالای صفحه باشد، نه از وسط --- */
if ('scrollRestoration' in history) { try { history.scrollRestoration = 'manual'; } catch(e){} }
window.addEventListener('load', function(){ window.scrollTo(0, 0); });

/* ============================================================
 * (آپدیت ۲) بازار ارز دیجیتال + بهترین عملکرد ارزی امروز
 * دریافت از api/crypto_market_api.php و به‌روزرسانی هر دقیقه
 * با کلیک روی هر ارز، مدال نمودار (۲۴ ساعت / ۷ / ۳۰ روز) باز می‌شود
 * ============================================================ */
window.__avaCoinMap = {};   // id -> coin data (برای مدال)
let avaCoinChart = null;    // نمونه‌ی نمودار مدال
let avaCoinCur   = null;    // ارز فعال در مدال

(function(){
    let avaCryptoTimer = null;

    // این تابع فقط برای به‌روزرسانی «واچ‌لیست ارز دیجیتال» (بخش هشدارهای قیمت)
    // باقی مانده — رندر خودِ کارت بازار/برترین‌ها/اخبار که قبلاً اینجا بود،
    // همراه با حذف بنر و مدال «بازار ارز دیجیتال جهانی» حذف شد.
    async function avaLoadCrypto(){
        try {
            const res = await fetch('/ledor/api/crypto_market_api.php', { cache:'no-store' });
            const d = await res.json();
            if (!d || !d.success){ throw new Error('bad'); }
            window.__avaMarketAll = [].concat(d.market || [], d.gainers || []);
            window.__avaMarketAll.forEach(c=>{ if (c && c.id) window.__avaCoinMap[c.id] = c; });
            // قیمت‌های واچ‌لیست ارز دیجیتال را از همین پاسخ به‌روز کن
            window.__avaMarketAll.forEach(c=>{
                if (c && c.id) window.__avaCryptoPrices[c.id] = { price:c.price, change24h:c.change24h, image:c.image, name:c.name, symbol:c.symbol };
            });
            if (typeof avaRenderCryptoWatch === 'function' && document.getElementById('avaCryptoWatchList'))
                avaRenderCryptoWatch();
        } catch(e){}
    }

    function startCrypto(){
        // اگر واچ‌لیست ارز دیجیتال روی صفحه نیست، کاری نکن
        if (!document.getElementById('avaCryptoWatchList')) return;
        avaLoadCrypto();
        if (avaCryptoTimer) clearInterval(avaCryptoTimer);
        avaCryptoTimer = setInterval(avaLoadCrypto, 60000); // هر ۶۰ ثانیه
        document.addEventListener('visibilitychange', function(){
            if (!document.hidden) avaLoadCrypto();
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', startCrypto);
    else startCrypto();
})();

/* ==================== اخبار اقتصادی ایران ==================== */
let avaIrnItems = [];
let avaIrnLoaded = false;

async function avaIrnLoad(force){
    if (avaIrnLoaded && !force) return;
    const strip = document.getElementById('avaIrnStrip');
    if (force && strip) strip.innerHTML = '<div class="ava-empty" style="width:100%;"><i class="fas fa-circle-notch fa-spin"></i> در حال دریافت اخبار…</div>';
    try {
        const r = await fetch('api/iran_econ_news.php?action=list');
        const d = await r.json();
        avaIrnItems = (d && d.items) ? d.items : [];
        avaIrnLoaded = true;
        avaIrnRender();
    } catch(e){
        if (strip) strip.innerHTML = '<div class="ava-empty" style="width:100%;"><i class="fas fa-wifi"></i> خطا در دریافت اخبار</div>';
    }
}

function avaIrnRow(n){
    const thumb = n.image
        ? '<div class="ava-irn-thumb"><img src="'+n.image+'" loading="lazy" onerror="this.parentNode.classList.add(\'ph\');this.remove()"></div>'
        : '<div class="ava-irn-thumb ph"></div>';
    return '<div class="ava-irn-row" onclick="avaIrnOpen(\''+n.id+'\')">'
         + thumb
         + '<div class="ava-irn-body">'
         +   '<div class="ava-irn-title">'+avaEsc(n.title)+'</div>'
         +   '<div class="ava-irn-meta"><span class="ava-irn-src">'+avaEsc(n.source)+'</span><span>'+avaEsc(n.date)+'</span></div>'
         + '</div>'
         + '<button type="button" class="ava-irn-like'+(n.liked?' on':'')+'" data-nid="'+n.id+'" onclick="event.stopPropagation();avaIrnLike(\''+n.id+'\', this)">'
         + '<i class="'+(n.liked?'fas':'far')+' fa-heart"></i> <span>'+(n.likes||0)+'</span></button>'
         + '</div>';
}

const AVA_IRN_PER_PAGE = 3;   // تعداد خبر در هر صفحه (با کشیدن انگشت صفحه بعد)

function avaIrnRender(){
    const pager = document.getElementById('avaIrnStrip');
    const dots  = document.getElementById('avaIrnDots');
    if (!pager) return;
    if (!avaIrnItems.length){
        pager.innerHTML = '<div class="ava-empty" style="width:100%;"><i class="fas fa-newspaper"></i> فعلاً خبری در دسترس نیست</div>';
        if (dots) dots.innerHTML = '';
        return;
    }

    // تقسیم اخبار به صفحه‌های ۳تایی
    const pages = [];
    for (let i = 0; i < avaIrnItems.length; i += AVA_IRN_PER_PAGE){
        pages.push(avaIrnItems.slice(i, i + AVA_IRN_PER_PAGE));
    }
    pager.innerHTML = pages.map(p =>
        '<div class="ava-irn-page">' + p.map(avaIrnRow).join('') + '</div>'
    ).join('');

    // نقطه‌های راهنمای صفحه
    if (dots){
        dots.innerHTML = pages.map((_, i) =>
            '<span class="ava-irn-dot'+(i===0?' on':'')+'" data-pi="'+i+'"></span>'
        ).join('');
        if (!pager._dotsBound){
            pager.addEventListener('scroll', () => {
                const w  = pager.clientWidth || 1;
                const at = Math.round(Math.abs(pager.scrollLeft) / w);
                dots.querySelectorAll('.ava-irn-dot').forEach((d, i) => d.classList.toggle('on', i === at));
            }, { passive:true });
            pager._dotsBound = true;
        }
    }
}

async function avaIrnOpen(id){
    const n = avaIrnItems.find(x => x.id === id);
    if (!n) return;
    const box = document.getElementById('avaIrnDetail');
    if (box){
        // نمایش فوری خلاصه + اسپینر ادامه‌ی خبر
        box.innerHTML =
            (n.image ? '<img src="'+n.image+'" style="width:100%;border-radius:16px;margin-bottom:14px;" onerror="this.style.display=\'none\'">' : '')
          + '<div style="font-size:1rem;font-weight:900;color:#fff;line-height:1.9;margin-bottom:8px;">'+avaEsc(n.title)+'</div>'
          + '<div style="font-size:.68rem;color:rgba(255,255,255,.5);margin-bottom:14px;"><span style="color:#22C55E;font-weight:800;">'+avaEsc(n.source)+'</span> · '+avaEsc(n.date)+'</div>'
          + '<div id="avaIrnBody" style="font-size:.85rem;color:rgba(255,255,255,.88);line-height:2.1;margin-bottom:14px;white-space:pre-line;">'+avaEsc(n.summary || '')+'</div>'
          + '<div id="avaIrnMoreSpin" class="ava-empty" style="padding:8px 0;"><i class="fas fa-circle-notch fa-spin"></i> در حال دریافت ادامه‌ی خبر…</div>'
          + '<div style="display:flex;gap:10px;align-items:center;">'
          + '<button type="button" class="ava-irn-like lg'+(n.liked?' on':'')+'" data-nid="'+n.id+'" onclick="avaIrnLike(\''+n.id+'\', this)">'
          + '<i class="'+(n.liked?'fas':'far')+' fa-heart"></i> <span>'+(n.likes||0)+'</span></button>'
          + '<a href="'+n.link+'" target="_blank" rel="noopener" style="flex:1;text-align:center;padding:12px;border-radius:14px;background:linear-gradient(135deg,#059669,#22C55E);color:#fff;font-weight:900;font-size:.8rem;text-decoration:none;"><i class="fas fa-arrow-up-right-from-square"></i> مطالعه در منبع</a>'
          + '</div>';
    }
    avaOpenFsModal('avaIrnModal');

    // واکشی ادامه‌ی خبر از سرور (متن کامل صفحه‌ی منبع)
    try {
        const r = await fetch('api/iran_econ_news.php?action=full&id=' + encodeURIComponent(id));
        const d = await r.json();
        const spin = document.getElementById('avaIrnMoreSpin');
        if (spin) spin.remove();
        if (d && d.success && d.body){
            const bodyEl = document.getElementById('avaIrnBody');
            if (bodyEl && d.body.length > (n.summary || '').length){
                bodyEl.textContent = d.body;
            }
            // اگر فید تصویر نداشت ولی صفحه‌ی خبر داشت
            if (d.image && !n.image){
                n.image = d.image;
                const boxEl = document.getElementById('avaIrnDetail');
                if (boxEl && !boxEl.querySelector('img')){
                    const im = document.createElement('img');
                    im.src = d.image;
                    im.style.cssText = 'width:100%;border-radius:16px;margin-bottom:14px;';
                    boxEl.prepend(im);
                }
            }
        }
    } catch(e){
        const spin = document.getElementById('avaIrnMoreSpin');
        if (spin) spin.innerHTML = '<i class="fas fa-wifi"></i> ادامه‌ی خبر در دسترس نبود — از دکمه «مطالعه در منبع» استفاده کنید';
    }
}

async function avaIrnLike(id, btn){
    try {
        const r = await fetch('api/iran_econ_news.php?action=like', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ id: id })
        });
        const d = await r.json();
        if (!d.success) return;
        const n = avaIrnItems.find(x => x.id === id);
        if (n){ n.liked = d.liked; n.likes = d.likes; }
        // همگام‌سازی همه‌ی دکمه‌های همین خبر (کارت + مدال)
        document.querySelectorAll('.ava-irn-like[data-nid="'+id+'"]').forEach(b=>{
            b.classList.toggle('on', d.liked);
            const ic = b.querySelector('i'); if (ic) ic.className = (d.liked?'fas':'far')+' fa-heart';
            const sp = b.querySelector('span'); if (sp) sp.textContent = d.likes;
        });
    } catch(e){}
}


/* --- مدال نمودار ارز دیجیتال (استایل نمودار سبد دارایی) --- */
function avaOpenCoin(id){
    const c = window.__avaCoinMap[id];
    if (!c) return;
    avaCoinCur = c;
    // هدر
    const nameEl = document.getElementById('avaCoinName');
    const symEl  = document.getElementById('avaCoinSym');
    const icEl   = document.getElementById('avaCoinIcon');
    const prEl   = document.getElementById('avaCoinPrice');
    const chgEl  = document.getElementById('avaCoinChg');
    if (nameEl) nameEl.textContent = c.name || '';
    if (symEl)  symEl.textContent  = c.symbol || '';
    if (icEl){
        icEl.innerHTML = c.image
            ? `<img src="${c.image}" alt="" onerror="this.parentNode.innerHTML='<i class=\\'fab fa-bitcoin\\'></i>'">`
            : `<i class="fab fa-bitcoin"></i>`;
    }
    if (prEl){
        const p = Number(c.price);
        prEl.textContent = isNaN(p) ? '—'
            : (p >= 1 ? '$'+p.toLocaleString('en-US',{maximumFractionDigits:2})
                      : '$'+p.toPrecision(4));
    }
    if (chgEl){
        const up = (Number(c.change24h)||0) >= 0;
        chgEl.textContent = (up?'+':'') + (Number(c.change24h)||0).toFixed(2) + '%';
        chgEl.className = 'ava-coin-chg ' + (up?'up':'dn');
    }
    // بازه‌ی پیش‌فرض: ۲۴ ساعت
    document.querySelectorAll('#avaCoinModal .ava-coin-ranges .ava-inv-tab').forEach(t=>{
        t.classList.toggle('active', t.dataset.range === '1');
    });
    avaOpenFsModal('avaCoinModal');
    avaLoadCoinChart(id, 1);
}

function avaCoinRange(days, el){
    document.querySelectorAll('#avaCoinModal .ava-coin-ranges .ava-inv-tab').forEach(t=>t.classList.remove('active'));
    if (el) el.classList.add('active');
    if (avaCoinCur) avaLoadCoinChart(avaCoinCur.id, days);
}

async function avaLoadCoinChart(id, days){
    const cv    = document.getElementById('avaCoinChart');
    const empty = document.getElementById('avaCoinChartEmpty');
    const load  = document.getElementById('avaCoinChartLoad');
    if (!cv) return;
    if (empty) empty.style.display = 'none';
    if (load)  load.style.display  = 'flex';
    if (avaCoinChart){ avaCoinChart.destroy(); avaCoinChart = null; }
    try {
        const res = await fetch('/ledor/api/crypto_market_api.php?action=history&id='
                    + encodeURIComponent(id) + '&days=' + days, { cache:'no-store' });
        const d = await res.json();
        if (load) load.style.display = 'none';
        const pts = (d && d.points) || [];
        if (!d || !d.success || pts.length < 2){
            if (empty) empty.style.display = 'flex';
            return;
        }
        // برچسب‌ها: برای ۲۴ ساعت زمان، برای بازه‌ی بلندتر تاریخ
        const labels = pts.map(x=>{
            const dt = new Date(x.t);
            return days == 1
                ? dt.toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit'})
                : (dt.getMonth()+1)+'/'+dt.getDate();
        });
        const values = pts.map(x=>Number(x.p));
        // رنگ بر اساس روند کل بازه (سبز اگر صعودی، قرمز اگر نزولی) — مثل نمودار سبد دارایی
        const up = values[values.length-1] >= values[0];
        const line = up ? '#22C55E' : '#FF5A6E';
        const ctx = cv.getContext('2d');
        const grad = ctx.createLinearGradient(0, 0, 0, 300);
        grad.addColorStop(0, up ? 'rgba(34,197,94,.30)' : 'rgba(255,90,110,.30)');
        grad.addColorStop(1, up ? 'rgba(34,197,94,0)'   : 'rgba(255,90,110,0)');
        avaCoinChart = new Chart(ctx, {
            type:'line',
            data:{ labels, datasets:[{
                label:(avaCoinCur && avaCoinCur.symbol) || '', data:values,
                borderColor:line, backgroundColor:grad, borderWidth:2,
                fill:true, tension:.4, pointRadius:0, pointHoverRadius:4,
                pointBackgroundColor:line
            }]},
            options:{
                responsive:true, maintainAspectRatio:false,
                animation:{ duration:300 },
                interaction:{ intersect:false, mode:'index' },
                plugins:{ legend:{display:false}, tooltip:{ rtl:true,
                    callbacks:{ label:(c)=>{
                        const v = c.raw;
                        return (v >= 1 ? '$'+v.toLocaleString('en-US',{maximumFractionDigits:2}) : '$'+v.toPrecision(4));
                    } } } },
                scales:{
                    x:{ ticks:{ color:'rgba(255,255,255,.45)', font:{size:9}, maxRotation:0, maxTicksLimit:6 }, grid:{ display:false } },
                    y:{ ticks:{ color:'rgba(255,255,255,.45)', font:{size:9}, maxTicksLimit:5,
                        callback:(v)=> (v >= 1 ? '$'+Number(v).toLocaleString('en-US',{maximumFractionDigits:0}) : '$'+Number(v).toPrecision(2)) },
                        grid:{ color:'rgba(255,255,255,.06)' } }
                }
            }
        });
    } catch(e){
        if (load)  load.style.display  = 'none';
        if (empty) empty.style.display = 'flex';
    }
}

/* --- بخش نرخ زنده (iframe) --- */
/* بخش نرخ لحظه‌ای بازار (showCurrencyRate/mrLoad/...) در includes/footer_menu.php تعریف شده است */


/* ===== (آپدیت ۶) کارت موجودی چرخشی + مینی‌نمودار + مدال همه‌ی ارزها ===== */
window.AVA_HERO = <?php echo json_encode(array_values($avaHeroData), JSON_UNESCAPED_UNICODE); ?>;
let avaHeroIdx = 0;
let avaHeroTimer = null;
let avaHeroPaused = false;

function avaHeroFmt(v, dec){
    try {
        return avaFa(new Intl.NumberFormat('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec }).format(v));
    } catch(e){ return String(v); }
}
function avaHeroFlagHtml(it){
    if (it.flag) return '<img src="https://flagcdn.com/w40/' + it.flag + '.png" alt="' + it.label + '" onerror="this.style.display=\'none\'">';
    return '<i class="' + (it.ico || 'fas fa-coins') + '" style="color:' + (it.col || '#A855F7') + '"></i>';
}
function avaHeroSpark(series, up){
    const w = 160, h = 40, col = up ? '#22C55E' : '#FF5A6E';
    const line = document.getElementById('avaHeroLine');
    const area = document.getElementById('avaHeroArea');
    const dot  = document.getElementById('avaHeroDot');
    const gs   = document.querySelectorAll('#avaHeroGrad stop');
    if (!line || !area) return;
    let ser = (series || []).map(Number).filter(v => !isNaN(v));
    if (ser.length < 2) ser = [0, 0];
    const min = Math.min.apply(null, ser), max = Math.max.apply(null, ser);
    const rng = (max - min) || 1, n = ser.length;
    const pts = ser.map((v, i) => {
        const x = (i * (w / (n - 1))).toFixed(1);
        const y = (h - 4 - ((v - min) / rng) * (h - 10)).toFixed(1);
        return x + ',' + y;
    });
    const d = 'M' + pts.join(' L');
    line.setAttribute('d', d);
    area.setAttribute('d', d + ' L' + w + ',' + h + ' L0,' + h + ' Z');
    line.setAttribute('stroke', col);
    if (gs && gs.length === 2){ gs[0].setAttribute('stop-color', col); gs[1].setAttribute('stop-color', col); }
    if (dot){
        const last = pts[pts.length - 1].split(',');
        dot.setAttribute('cx', last[0]); dot.setAttribute('cy', last[1]); dot.setAttribute('fill', col);
    }
    // انیمیشن ترسیم خط
    try {
        const len = line.getTotalLength();
        line.style.transition = 'none';
        line.style.strokeDasharray = len; line.style.strokeDashoffset = len;
        area.style.opacity = 0;
        requestAnimationFrame(() => {
            line.style.transition = 'stroke-dashoffset .85s cubic-bezier(.22,1,.36,1)';
            line.style.strokeDashoffset = 0;
            area.style.transition = 'opacity .7s ease .15s';
            area.style.opacity = 1;
        });
    } catch(e){}
}
function avaHeroPaint(i, animate){
    const data = window.AVA_HERO || [];
    if (!data.length) return;
    avaHeroIdx = ((i % data.length) + data.length) % data.length;
    const it = data[avaHeroIdx];
    const stage = document.getElementById('avaHeroStage');
    const apply = () => {
        const amt  = document.getElementById('avaIrrAmount');
        const unit = document.getElementById('avaHeroUnit');
        const name = document.getElementById('avaHeroName');
        const flag = document.getElementById('avaHeroFlag');
        const chg  = document.getElementById('avaHeroChg');
        const eq   = document.getElementById('avaHeroEq');
        const txt  = avaHeroFmt(it.bal, it.dec);
        if (amt){
            amt.dataset.real = txt;
            amt.textContent = document.body.classList.contains('ava-hidden-bal') ? '••••••' : txt;
        }
        if (unit) unit.textContent = it.unit;
        if (name) name.textContent = it.name + ' · ' + it.label;
        if (flag) flag.innerHTML = avaHeroFlagHtml(it);
        const up = Number(it.chg) >= 0;
        if (chg){
            chg.className = 'ava-hero-chg ' + (up ? 'up' : 'dn');
            chg.innerHTML = '<i class="fas fa-caret-' + (up ? 'up' : 'down') + '"></i> ' + avaFa(Math.abs(Number(it.chg)).toFixed(2)) + '%';
        }
        if (eq){
            eq.textContent = (it.code === 'IRR')
                ? 'موجودی اصلی حساب'
                : '≈ ' + avaFa(Number(it.toman).toLocaleString('en-US')) + ' تومان';
        }
        avaHeroSpark(it.series, up);
        document.querySelectorAll('#avaHeroDots .ava-hero-dot').forEach((d, k) => d.classList.toggle('on', k === avaHeroIdx));
    };
    if (!animate || !stage){ apply(); return; }
    stage.classList.remove('swap-in');
    stage.classList.add('swap-out');
    setTimeout(() => {
        apply();
        stage.classList.remove('swap-out');
        stage.classList.add('swap-in');
        setTimeout(() => stage.classList.remove('swap-in'), 520);
    }, 270);
}
function avaHeroNext(){ avaHeroPaint(avaHeroIdx + 1, true); avaHeroRestart(); }
function avaHeroPrev(){ avaHeroPaint(avaHeroIdx - 1, true); avaHeroRestart(); }
function avaHeroRestart(){
    if (avaHeroTimer) clearInterval(avaHeroTimer);
    avaHeroTimer = setInterval(() => { if (!avaHeroPaused) avaHeroPaint(avaHeroIdx + 1, true); }, 4200);
}
function avaHeroInit(){
    const data = window.AVA_HERO || [];
    const dots = document.getElementById('avaHeroDots');
    if (dots){
        dots.innerHTML = data.map((it, k) =>
            '<button type="button" class="ava-hero-dot' + (k === 0 ? ' on' : '') + '" title="' + it.name + '" onclick="avaHeroPaint(' + k + ',true);avaHeroRestart()"></button>'
        ).join('');
    }
    avaHeroPaint(0, false);
    if (data.length > 1) avaHeroRestart();
    const card = document.querySelector('.ava-wal-rot');
    if (card){
        card.addEventListener('mouseenter', () => { avaHeroPaused = true; });
        card.addEventListener('mouseleave', () => { avaHeroPaused = false; });
        // سوایپ روی موبایل
        let sx = 0;
        card.addEventListener('touchstart', e => { sx = e.touches[0].clientX; }, { passive: true });
        card.addEventListener('touchend', e => {
            const dx = e.changedTouches[0].clientX - sx;
            if (Math.abs(dx) > 45) { dx < 0 ? avaHeroNext() : avaHeroPrev(); }
        }, { passive: true });
    }
}
document.addEventListener('DOMContentLoaded', avaHeroInit);

function avaOpenAllBalances(){
    const grid = document.getElementById('avaAllBalGrid');
    const data = window.AVA_HERO || [];
    if (grid){
        grid.innerHTML = data.map((it, k) => {
            const up = Number(it.chg) >= 0;
            return '<div class="ava-allbal-item" style="animation-delay:' + (k * 0.04) + 's">'
                 + '<div class="ava-allbal-top"><span class="ava-allbal-fl">' + avaHeroFlagHtml(it) + '</span>'
                 + '<span><span class="ava-allbal-n">' + it.name + '</span><br><span class="ava-allbal-c">' + it.label + '</span></span></div>'
                 + '<div class="ava-allbal-row">'
                 + '<span class="ava-allbal-chg ' + (up ? 'up' : 'dn') + '">' + (up ? '▲' : '▼') + ' ' + avaFa(Math.abs(Number(it.chg)).toFixed(2)) + '%</span>'
                 + '<span><span class="ava-allbal-v ava-hideable">' + avaHeroFmt(it.bal, it.dec) + '</span>'
                 + '<span class="ava-allbal-u">' + it.unit + (it.code !== 'IRR' ? (' · ≈ ' + avaFa(Number(it.toman).toLocaleString('en-US')) + ' تومان') : '') + '</span></span>'
                 + '</div></div>';
        }).join('');
    }
    if (document.body.classList.contains('ava-hidden-bal')){
        setTimeout(() => {
            document.querySelectorAll('#avaAllBalGrid .ava-hideable').forEach(el => {
                if (!el.dataset.real) el.dataset.real = el.textContent;
                el.textContent = '••••••';
            });
        }, 0);
    }
    avaOpenFsModal('avaAllBalModal');
}

/* --- مخفی/نمایش مبالغ --- */
function avaToggleBalance(){
    const hidden = document.body.classList.toggle('ava-hidden-bal');
    document.querySelectorAll('.ava-hideable, #avaIrrAmount').forEach(el=>{
        if(hidden){ if(!el.dataset.real) el.dataset.real = el.textContent; el.textContent='••••••'; }
        else if(el.dataset.real){ el.textContent = el.dataset.real; }
    });
    const i=document.getElementById('avaEye');
    if(i) i.className = hidden ? 'fas fa-eye-slash' : 'fas fa-eye';
    try{ localStorage.setItem('ava_hide_bal', hidden?'1':'0'); }catch(e){}
}
if (localStorage.getItem('ava_hide_bal') === '1') document.addEventListener('DOMContentLoaded', avaToggleBalance);

/* --- پروفایل: به‌جای کشوی حذف‌شده، مستقیم به صفحه‌ی پروفایل می‌رود --- */
function avaOpenProfile(){ window.location.href = 'profile.php'; }
function avaCloseProfile(){ /* دیگر کشویی برای بستن وجود ندارد — نگه‌داشته شده فقط برای سازگاری با کدهای قدیمی که هنوز این تابع را صدا می‌زنند */ }
async function avaLogout(){
    try {
        let token = '';
        try { token = (document.cookie.match(/auth_token=([^;]+)/) || [])[1] || ''; } catch(e){}
        await fetch('api/logout.php?t=' + Date.now() + (token ? ('&token=' + encodeURIComponent(token)) : ''), {
            method:'GET', credentials:'include', cache:'no-store'
        });
    } catch(e){}

    // پاکسازی سمت کلاینت + کش PWA تا صفحه‌ی کش‌شده کاربر را برنگرداند
    try { localStorage.clear(); sessionStorage.clear(); } catch(e){}
    try {
        document.cookie.split(';').forEach(function(c){
            const name = c.replace(/^ +/, '').replace(/=.*/, '');
            const exp = '=;expires=' + new Date(0).toUTCString();
            document.cookie = name + exp + ';path=/';
            document.cookie = name + exp + ';path=/ledor/';
        });
    } catch(e){}
    try {
        if ('caches' in window) {
            const keys = await caches.keys();
            await Promise.all(keys.map(k => caches.delete(k)));
        }
    } catch(e){}

    window.location.replace('login.php?logout=success&t=' + Date.now());
}

/* --- نمودار «سبد دارایی من» — عیناً همان کارت نمودار transactions.php
   («جریان نقدی» بر اساس رکوردهای واقعی جدول transactions به تفکیک ارز)،
   با همان نام‌گذاری تابع‌ها (پیشوند avaTx) تا با نسخه‌ی transactions.php
   یکی باشد و در آینده هم‌زمان نگه داشتن رفتار دو کارت ساده بماند. --- */
let avaTxCur  = 'USD';
let avaTxMode = 'net';
let avaTxChart = null;

function avaTxIsLight(){
    try { return (document.documentElement.getAttribute('data-theme') || localStorage.getItem('ava_theme')) === 'light'; }
    catch(e){ return false; }
}
function avaTxFmt(v, dec){
    const a = Math.abs(Number(v) || 0);
    let out;
    if (a >= 1e9)      out = (a / 1e9).toFixed(2) + 'B';
    else if (a >= 1e6) out = (a / 1e6).toFixed(2) + 'M';
    else if (a >= 1e3) out = (a / 1e3).toFixed(1) + 'K';
    else               out = a.toLocaleString('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec });
    return (Number(v) < 0 ? '-' : '') + out;
}
function avaTxHexA(hex, a){
    const h = hex.replace('#', '');
    const r = parseInt(h.substring(0,2), 16), g = parseInt(h.substring(2,4), 16), b = parseInt(h.substring(4,6), 16);
    return 'rgba(' + r + ',' + g + ',' + b + ',' + a + ')';
}

function avaTxSelectCur(cur, el){
    avaTxCur = cur;
    document.querySelectorAll('#avaTxCurTabs .tx-cur-tab').forEach(function(b){
        b.classList.remove('on');
        b.style.background = '';
    });
    if (el){
        el.classList.add('on');
        const c = el.dataset.color || '#A855F7';
        el.style.background = 'linear-gradient(135deg,' + avaTxHexA(c, .95) + ',' + avaTxHexA(c, .55) + ')';
    }
    avaTxRender();
}
function avaTxSetMode(mode, el){
    avaTxMode = mode;
    if (el) {
        const wrap = el.closest('.tx-range');
        if (wrap) wrap.querySelectorAll('button').forEach(b => b.classList.remove('on'));
        el.classList.add('on');
    }
    avaTxRender();
}

function avaTxRender(){
    const DATA = window.AVA_TX_DATA || {}, META = window.AVA_TX_META || {}, LABELS = window.AVA_TX_LABELS || [];
    const meta  = META[avaTxCur]  || { color:'#A855F7', dec:2, label:avaTxCur, fa:avaTxCur, sym:'' };
    const d     = DATA[avaTxCur]  || { in:[], out:[], net:[], tin:0, tout:0, count:0 };
    const light = avaTxIsLight();
    const grid  = light ? 'rgba(16,8,40,.08)'  : 'rgba(255,255,255,.07)';
    const tick  = light ? '#6B6390'            : 'rgba(255,255,255,.5)';

    const title = document.getElementById('avaTxChartTitle');
    if (title) title.textContent = (avaTxCur === 'IRR' ? 'Toman' : meta.label) + ' — Cash Flow (12M)';

    const mi = document.getElementById('avaTxMiniIn'), mo = document.getElementById('avaTxMiniOut'), mn = document.getElementById('avaTxMiniNet');
    const net = (Number(d.tin) || 0) - (Number(d.tout) || 0);
    if (mi) mi.textContent = '+' + avaTxFmt(d.tin,  meta.dec);
    if (mo) mo.textContent = '-' + avaTxFmt(d.tout, meta.dec);
    if (mn) mn.textContent = (net >= 0 ? '+' : '') + avaTxFmt(net, meta.dec);

    const lg = document.getElementById('avaTxLegend');
    if (lg){
        lg.innerHTML = (avaTxMode === 'net')
            ? '<span><i style="background:' + meta.color + '"></i> Net flow (' + (avaTxCur === 'IRR' ? 'Toman' : avaTxCur) + ')</span>'
            : '<span><i style="background:#4CD964"></i> Received</span><span><i style="background:#FF5E5E"></i> Sent</span>';
    }

    const empty = document.getElementById('avaTxChartEmpty');
    const cv    = document.getElementById('avaTxChart');
    const hasData = (Number(d.count) || 0) > 0;
    if (empty) empty.style.display = hasData ? 'none' : 'flex';
    if (cv)    cv.style.display    = hasData ? 'block' : 'none';
    if (!hasData){ if (avaTxChart){ avaTxChart.destroy(); avaTxChart = null; } return; }

    let datasets;
    if (avaTxMode === 'net'){
        datasets = [{
            label: 'Net', data: d.net, borderColor: meta.color, backgroundColor: avaTxHexA(meta.color, .18),
            borderWidth: 2.4, tension: .38, fill: true, pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: meta.color
        }];
    } else if (avaTxMode === 'both'){
        datasets = [
            { label:'Received', data:d.in,  borderColor:'#4CD964', backgroundColor:'rgba(76,217,100,.15)', borderWidth:2.2, tension:.38, fill:true, pointRadius:0, pointHoverRadius:5 },
            { label:'Sent',     data:d.out, borderColor:'#FF5E5E', backgroundColor:'rgba(255,94,94,.13)',  borderWidth:2.2, tension:.38, fill:true, pointRadius:0, pointHoverRadius:5 }
        ];
    } else {
        datasets = [
            { label:'Received', data:d.in,  backgroundColor:'rgba(76,217,100,.75)', borderRadius:6, borderSkipped:false, maxBarThickness:16 },
            { label:'Sent',     data:d.out, backgroundColor:'rgba(255,94,94,.75)',  borderRadius:6, borderSkipped:false, maxBarThickness:16 }
        ];
    }

    const cfg = {
        type: (avaTxMode === 'bar') ? 'bar' : 'line',
        data: { labels: LABELS, datasets: datasets },
        options: {
            responsive: true, maintainAspectRatio: false,
            animation: { duration: 850, easing: 'easeOutQuart' },
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: light ? 'rgba(255,255,255,.96)' : 'rgba(12,6,30,.94)',
                    titleColor: light ? '#180F35' : '#fff',
                    bodyColor:  light ? '#3B3468' : 'rgba(255,255,255,.85)',
                    borderColor: avaTxHexA(meta.color, .5), borderWidth: 1, padding: 11, displayColors: true,
                    callbacks: {
                        label: function(ctx){
                            return ' ' + ctx.dataset.label + ': ' + avaTxFmt(ctx.parsed.y, meta.dec) + ' ' + (avaTxCur === 'IRR' ? 'Toman' : avaTxCur);
                        }
                    }
                }
            },
            scales: {
                x: { grid: { display:false }, ticks: { color: tick, font: { size: 9.5 }, maxRotation: 0, autoSkipPadding: 8 } },
                y: { grid: { color: grid, drawBorder: false }, ticks: { color: tick, font: { size: 9.5 }, callback: function(v){ return avaTxFmt(v, 0); } } }
            }
        }
    };

    if (avaTxChart){ avaTxChart.destroy(); avaTxChart = null; }
    try { avaTxChart = new Chart(document.getElementById('avaTxChart'), cfg); } catch(e){ console.error('avaTx chart', e); }
}

/* ====================================================================
   گجت هوشمند بازار — پیش‌بینی قیمت + تمایل بازار (دلار/یورو/تتر) +
   ارزهای دیجیتالی که خودِ کاربر اضافه می‌کند (نسبت به تتر).
   کلیک روی نقطه‌ی نمودار → آگهی‌های نزدیک همان قیمت را می‌گیرد و مدال
   باز می‌کند (فقط برای دلار/یورو/تتر که آگهی واقعی دارند).
   ==================================================================== */
let avaSmartCur = 'USD';
let avaSmartKind = 'fiat'; // 'fiat' | 'crypto'
let avaSmartRange = '1d'; // '1d' | '1m' | '1y' — پیش‌فرض روزانه: نزدیک‌ترین و واقعی‌ترین تارگت به قیمت فعلی
let avaSmartChart = null;
let avaSmartCryptoCache = {}; // cache تاریخچه‌ی هر کوین+بازه در همین session تا هر بار دوباره fetch نشود
let avaSmartFiatCache = {};   // cache نتیجه‌ی ai_history برای هر ارز+بازه

function avaSmartFmt(v){
    const n = Math.round(Number(v) || 0);
    return n.toLocaleString('en-US');
}

// رگرسیون خطی ساده (least squares) — همان منطق سمت سرور، برای ارز دیجیتال سمت کلاینت
function avaSmartRegress(hist, daysAhead){
    const n = hist.length;
    let sumX=0, sumY=0, sumXY=0, sumXX=0;
    hist.forEach((y, i) => { sumX+=i; sumY+=y; sumXY+=i*y; sumXX+=i*i; });
    const denom = (n*sumXX - sumX*sumX);
    const slope = denom !== 0 ? (n*sumXY - sumX*sumY)/denom : 0;
    const intercept = n>0 ? (sumY - slope*sumX)/n : 0;
    const last = hist[hist.length-1] || 0;
    const predicted = Math.max(0, intercept + slope*(n-1+daysAhead));
    const change = last>0 ? ((predicted-last)/last)*100 : 0;
    return { predicted, last, change };
}
function avaSmartAnalysisText(high, low, unit, periodLabel){
    if (!(high>0) || !(low>0) || high === low) return '';
    const range = high - low;
    const upTarget = high + (range * 0.1);
    const downTarget = Math.max(0, low - (range * 0.1));
    return 'در ' + (periodLabel || 'یک ماه') + ' اخیر، سقف قیمت ' + avaSmartFmt(high) + ' و کف قیمت ' + avaSmartFmt(low) + ' ' + unit + ' بوده است. '
         + 'اگر قیمت سطح مقاومت ' + avaSmartFmt(high) + ' را بشکند، هدف قیمتی بعدی حدود ' + avaSmartFmt(upTarget) + ' ' + unit + ' خواهد بود؛ '
         + 'اگر سطح حمایت ' + avaSmartFmt(low) + ' شکسته شود، هدف قیمتی بعدی حدود ' + avaSmartFmt(downTarget) + ' ' + unit + ' مطرح می‌شود.';
}

function avaSmartSelectCur(cur, kind, el){
    avaSmartCur = cur;
    avaSmartKind = kind || 'fiat';
    document.querySelectorAll('#avaSmartTabs .ava-smart-tab').forEach(b => b.classList.remove('on'));
    if (el) el.classList.add('on');
    const fullTitle = document.getElementById('avaSmartFullTitle');
    if (fullTitle) fullTitle.innerHTML = '<i class="fas fa-brain"></i> ' + (el ? el.textContent.trim() : cur);
    avaSmartRender();
}

function avaSmartSelectRange(range, el, isFull){
    avaSmartRange = range;
    document.querySelectorAll('#avaSmartTfTabs .ava-smart-tf-tab').forEach(b => {
        b.classList.toggle('on', b.dataset.range === range);
    });
    avaSmartRender();
}

function avaSmartToggleCryptoUI(isCrypto){
    // برای ارز دیجیتال: بهترین قیمت / گیج تمایل بازار / راهنمای کلیک‌روی‌نقطه معنی ندارند
    // (چون AvaPay آگهی خرید/فروش ارز دیجیتال ندارد) — فقط برای دلار/یورو/تتر نشان داده می‌شوند.
    const best = document.getElementById('avaSmartBest');
    const gauge = document.getElementById('avaSmartGaugeCol');
    const hint = document.getElementById('avaSmartHint');
    if (best) best.style.display = isCrypto ? 'none' : 'flex';
    if (gauge) gauge.style.display = isCrypto ? 'none' : 'block';
    if (hint) hint.style.display = isCrypto ? 'none' : 'block';
}

const AVA_SMART_RANGE_DAYS = { '1d': 1, '1m': 30, '1y': 365 };
const AVA_SMART_RANGE_LABEL = { '1d': 'یک روز', '1m': 'یک ماه', '1y': 'یک سال' };

async function avaSmartRender(){
    if (avaSmartKind === 'crypto') { avaSmartRenderCrypto(avaSmartCur, avaSmartRange); return; }
    avaSmartToggleCryptoUI(false);

    if (avaSmartRange === '1m' && window.AVA_SMART_DATA && window.AVA_SMART_DATA[avaSmartCur]){
        // بازه‌ی پیش‌فرض («۱ ماهه») از همون داده‌ای که موقع لود صفحه آماده شده، بدون AJAX
        avaSmartApplyData(window.AVA_SMART_DATA[avaSmartCur], 'تومان');
        return;
    }

    const cacheKey = avaSmartCur + ':' + avaSmartRange;
    if (avaSmartFiatCache[cacheKey]){ avaSmartApplyData(avaSmartFiatCache[cacheKey], 'تومان'); return; }

    // (اصلاح) قبل از شروع fetch، ارز/بازه‌ی درخواست‌شده را نگه می‌داریم تا بعد از
    // برگشتن پاسخ بررسی کنیم کاربر در همین فاصله تب دیگری نزده باشد — قبلاً
    // اینجا به‌اشتباه avaSmartCur با خودش مقایسه می‌شد (همیشه true)، یعنی این
    // چک هیچ‌وقت واقعاً کار نمی‌کرد و ممکن بود جواب یک تب قدیمی روی تب جدید بشینه.
    const requestedCur = avaSmartCur;
    const requestedRange = avaSmartRange;
    const requestedKind = avaSmartKind;

    const loadingEl = document.getElementById('avaSmartChartLoading');
    if (loadingEl) loadingEl.style.display = 'flex';
    try {
        const res = await fetch('dashboard.php?ava=ai_history&currency=' + encodeURIComponent(requestedCur) + '&range=' + encodeURIComponent(requestedRange));
        const j = await res.json();
        if (loadingEl) loadingEl.style.display = 'none';
        if (avaSmartCur !== requestedCur || avaSmartRange !== requestedRange || avaSmartKind !== requestedKind) return; // کاربر تب را عوض کرده
        if (j && j.success){
            // بهترین‌قیمت/تمایل‌بازار به بازه ربطی ندارند، از داده‌ی اولیه‌ی همون ارز برمی‌داریم
            const base = (window.AVA_SMART_DATA && window.AVA_SMART_DATA[requestedCur]) || {};
            const merged = Object.assign({}, j, { buyPct: base.buyPct, bestBuyPrice: base.bestBuyPrice });
            avaSmartFiatCache[cacheKey] = merged;
            avaSmartApplyData(merged, 'تومان');
        }
    } catch(e){ if (loadingEl) loadingEl.style.display = 'none'; }
}

async function avaSmartRenderCrypto(coinId, range){
    avaSmartToggleCryptoUI(true);
    const loadingEl = document.getElementById('avaSmartChartLoading');
    if (loadingEl) loadingEl.style.display = 'flex';

    const days = AVA_SMART_RANGE_DAYS[range] || 30;
    const cacheKey = coinId + ':' + range;
    let points = avaSmartCryptoCache[cacheKey];
    if (!points){
        try {
            const res = await fetch('api/crypto_market_api.php?action=history&id=' + encodeURIComponent(coinId) + '&days=' + days);
            const j = await res.json();
            if (j && j.success && j.points && j.points.length){
                points = j.points.map(p => p.p);
                avaSmartCryptoCache[cacheKey] = points;
            }
        } catch(e){}
    }
    if (loadingEl) loadingEl.style.display = 'none';
    if (avaSmartCur !== coinId || avaSmartKind !== 'crypto') return; // کاربر تا زمان لود شدن، تب دیگری زده

    if (!points || points.length < 2){
        avaSmartApplyData({ history: [0,0], predicted:0, last:0, change:0, high:0, low:0, analysis:'' }, '$');
        return;
    }
    const reg = avaSmartRegress(points, days);
    const high = Math.max.apply(null, points);
    const low  = Math.min.apply(null, points);
    avaSmartApplyData({
        history: points,
        predicted: reg.predicted,
        last: reg.last,
        change: Math.round(reg.change * 10) / 10,
        high: high,
        low: low,
        analysis: avaSmartAnalysisText(high, low, '$', AVA_SMART_RANGE_LABEL[range]),
        days: days,
    }, '$');

    // (اصلاح) دقتِ پیش‌بینی این کوین: فقط برای بازه‌ی «روزانه» (پیش‌فرض) هدف‌های
    // تازه‌ی سه‌روزه (هم صعودی، هم نزولی، بر اساس شکستنِ سقف/کفِ همین بازه‌ی
    // روزانه) ثبت می‌کنیم (یک‌بار در روز)؛ برای بازه‌های دیگر فقط آمار موجود
    // را می‌خوانیم — تارگتِ کوچک و نزدیک به قیمتِ فعلی، برای اعتمادسازی سریع‌تر.
    if (range === '1d') {
        const rng = Math.max(0, high - low);
        if (rng > 0) avaSmartLogCryptoPrediction(coinId, reg.last, high + (rng * 0.1), Math.max(0, low - (rng * 0.1)));
    } else {
        avaSmartFetchCryptoAccuracy(coinId);
    }
}

async function avaSmartLogCryptoPrediction(coinId, last, upTarget, downTarget){
    try {
        const res = await fetch('dashboard.php?ava=ai_crypto_predict_log', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ coin_id: coinId, last: last, up_target: upTarget, down_target: downTarget })
        });
        const j = await res.json();
        if (j && j.success && avaSmartCur === coinId && avaSmartKind === 'crypto') {
            avaSmartRenderAccuracy(j.accuracy, '$');
        }
    } catch(e){}
}

async function avaSmartFetchCryptoAccuracy(coinId){
    try {
        const res = await fetch('dashboard.php?ava=ai_crypto_accuracy&coin_id=' + encodeURIComponent(coinId));
        const j = await res.json();
        if (j && j.success && avaSmartCur === coinId && avaSmartKind === 'crypto') {
            avaSmartRenderAccuracy(j.accuracy, '$');
        }
    } catch(e){}
}

function avaSmartApplyData(d, unit){
    // هدر قیمت بزرگ زنده
    const heroVal = document.getElementById('avaSmartHeroVal');
    const heroChg = document.getElementById('avaSmartHeroChg');
    const heroSub = document.getElementById('avaSmartHeroSub');
    if (heroVal) avaSmartCountUp(heroVal, Math.round(d.last || 0));
    if (heroChg){
        // درصد تغییر «امروز» را از روی دو نقطه‌ی آخر تاریخچه تخمین می‌زنیم
        const hist = d.history || [];
        const prev = hist.length > 1 ? hist[hist.length - 2] : (d.last || 0);
        const dayChg = prev > 0 ? ((d.last - prev) / prev) * 100 : 0;
        const up = dayChg >= 0;
        heroChg.textContent = (up ? '+' : '') + (Math.round(dayChg * 100) / 100) + '٪ ' + (up ? '▲' : '▼');
        heroChg.className = 'ava-smart-hero-chg' + (up ? '' : ' down');
    }
    if (heroSub) heroSub.textContent = 'قیمت لحظه‌ای ' + (avaSmartKind === 'crypto' ? '' : '') + ' (' + unit + ')';

    const openEl = document.getElementById('avaSmartOhlcOpen');
    const highEl = document.getElementById('avaSmartOhlcHigh');
    const lowEl  = document.getElementById('avaSmartOhlcLow');
    if (openEl) openEl.textContent = avaSmartFmt(d.open != null ? d.open : (d.history ? d.history[0] : 0));
    if (highEl) highEl.textContent = avaSmartFmt(d.high || 0);
    if (lowEl)  lowEl.textContent  = avaSmartFmt(d.low || 0);

    const predEl = document.getElementById('avaSmartPredVal');
    const chgEl  = document.getElementById('avaSmartPredChg');
    if (predEl) avaSmartCountUp(predEl, Math.round(d.predicted || 0));
    if (chgEl){
        const up = Number(d.change) >= 0;
        chgEl.textContent = (up ? '+' : '') + d.change + '٪ ' + (up ? '↗' : '↘');
        chgEl.className = 'ava-smart-pred-chg ' + (up ? 'up' : 'down');
    }

    // بهترین قیمت خرید همین الان (فقط دلار/یورو/تتر)
    const bestEl = document.getElementById('avaSmartBestVal');
    const bestBox = document.getElementById('avaSmartBest');
    if (bestEl && avaSmartKind === 'fiat'){
        if (d.bestBuyPrice > 0){
            bestEl.textContent = avaSmartFmt(d.bestBuyPrice) + ' تومان';
            if (bestBox) bestBox.style.display = 'flex';
        } else if (bestBox) bestBox.style.display = 'none';
    } else if (bestBox) bestBox.style.display = 'none';

    // گیج تمایل بازار (فقط دلار/یورو/تتر، به بازه‌ی زمانی ربطی ندارد)
    if (avaSmartKind === 'fiat'){
        const pct = Math.max(0, Math.min(100, Number(d.buyPct) || 50));
        const arc = document.getElementById('avaSmartGaugeArc');
        const pctEl = document.getElementById('avaSmartGaugePct');
        const dirEl = document.getElementById('avaSmartGaugeDir');
        const full = 201.06; // 2*PI*32
        const color = pct >= 55 ? '#22C55E' : (pct <= 45 ? '#FF5A6E' : '#FBBF24');
        if (arc){
            arc.style.stroke = color; arc.style.strokeDasharray = full; arc.style.strokeDashoffset = full;
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    arc.style.strokeDashoffset = String(full - full * (pct / 100));
                });
            });
        }
        if (pctEl){ pctEl.textContent = avaFa(pct) + '٪'; pctEl.style.color = color; }
        if (dirEl){ dirEl.textContent = pct >= 55 ? 'خرید' : (pct <= 45 ? 'فروش' : 'خنثی'); dirEl.style.color = color; }
    }

    // عمق بازار (فقط دلار/یورو/تتر — برای ارز دیجیتال آگهی نداریم)
    avaSmartRenderDepth(d);

    // تحلیل روند (کف/سقف + هدف قیمتی بعدی)
    const anBox = document.getElementById('avaSmartAnalysis');
    const anTxt = document.getElementById('avaSmartAnalysisTxt');
    if (anBox && anTxt){
        if (d.analysis){
            anTxt.innerHTML = String(d.analysis)
                .replace(avaSmartFmt(d.high), '<b class="up">' + avaSmartFmt(d.high) + '</b>')
                .replace(avaSmartFmt(d.low), '<b class="down">' + avaSmartFmt(d.low) + '</b>');
            anBox.style.display = 'block';
            anBox.classList.remove('ava-smart-fade'); void anBox.offsetWidth; anBox.classList.add('ava-smart-fade');
        } else {
            anBox.style.display = 'none';
        }
    }

    // (جدید) دقت پیش‌بینی‌های قبلی
    avaSmartRenderAccuracy(d.accuracy, unit);

    avaSmartRenderChart(d, unit, 'avaSmartChart', 'avaSmartPriceBox');
}

/**
 * نمایش «٪ دقت پیش‌بینی» و نتیجه‌ی آخرین پیش‌بینیِ سررسیدشده برای ارز/کوینِ
 * جاری. acc = { pct, hit, total, last:{status,predicted,resolved,date} } | null
 */
function avaSmartRenderAccuracy(acc, unit){
    const box = document.getElementById('avaSmartAccuracy');
    const pctEl = document.getElementById('avaSmartAccPct');
    const barEl = document.getElementById('avaSmartAccBarFill');
    const subEl = document.getElementById('avaSmartAccSub');
    const lastEl = document.getElementById('avaSmartAccLast');
    if (!box) return;
    box.style.display = 'block';

    if (!acc || !acc.total){
        if (pctEl){ pctEl.textContent = '—'; pctEl.style.color = '#fff'; }
        if (barEl) barEl.style.width = '0%';
        if (subEl) subEl.textContent = 'هنوز پیش‌بینیِ سه‌روزه‌ای به سررسید نرسیده تا دقتش سنجیده شود؛ این آمار خودش را طی روزهای آینده تکمیل می‌کند.';
        if (lastEl) lastEl.style.display = 'none';
        return;
    }

    const pct = Number(acc.pct) || 0;
    const color = pct >= 60 ? '#22C55E' : (pct <= 40 ? '#FF5A6E' : '#FBBF24');
    if (pctEl){ pctEl.textContent = avaFa(pct) + '٪'; pctEl.style.color = color; }
    if (barEl){ barEl.style.width = '0%'; requestAnimationFrame(() => requestAnimationFrame(() => { barEl.style.width = pct + '%'; })); }
    if (subEl) subEl.textContent = 'از ' + avaFa(acc.total) + ' پیش‌بینیِ سه‌روزه‌ی بررسی‌شده، ' + avaFa(acc.hit) + ' مورد دقیقاً به هدف رسیده است.';

    if (lastEl && acc.last){
        const hitOk = acc.last.status === 'hit';
        lastEl.style.display = 'flex';
        lastEl.className = 'ava-smart-accuracy-last ' + (hitOk ? 'hit' : 'missed');
        const dateTxt = avaFa(new Date(acc.last.date).toLocaleDateString('fa-IR'));
        lastEl.innerHTML = '<i class="fas ' + (hitOk ? 'fa-circle-check' : 'fa-circle-xmark') + '"></i> '
            + 'آخرین پیش‌بینیِ سررسیدشده (هدف ' + avaSmartFmt(acc.last.predicted) + (unit === '$' ? '$' : ' تومان') + ') در '
            + dateTxt + (hitOk ? ' دقیقاً به تارگت خورد ✅' : ' به تارگت نرسید');
    } else if (lastEl) {
        lastEl.style.display = 'none';
    }
}

function avaSmartRenderDepth(d){
    const box = document.getElementById('avaSmartDepth');
    const barsEl = document.getElementById('avaSmartDepthBars');
    const axisEl = document.getElementById('avaSmartDepthAxis');
    if (!box || !barsEl || !axisEl) return;
    const depth = d.depth;
    if (avaSmartKind !== 'fiat' || !depth || !depth.buckets || !depth.buckets.length){
        box.style.display = 'none';
        return;
    }
    box.style.display = 'block';
    const buckets = depth.buckets;
    const labels = depth.labels || [];
    const maxCount = Math.max(1, ...buckets.map(b => b.buy + b.sell));
    barsEl.innerHTML = buckets.map((b, i) => {
        const total = b.buy + b.sell;
        const h = Math.max(6, Math.round((total / maxCount) * 100));
        const isSellDominant = b.sell >= b.buy;
        const isBest = i === depth.bestBucket;
        const price = labels[i] || 0;
        return '<div class="ava-smart-depth-bar ' + (isSellDominant ? 'sell' : 'buy') + (isBest ? ' best' : '') + '" '
             + 'style="height:' + h + '%" title="' + total + ' آگهی نزدیک ' + avaSmartFmt(price) + '" '
             + 'onclick="avaSmartOpenAdsAtPrice(avaSmartCur, ' + price + ')"></div>';
    }).join('');
    axisEl.innerHTML = '<span>' + avaSmartFmt(labels[0] || 0) + '</span><span>' + avaSmartFmt(labels[labels.length - 1] || 0) + '</span>';
}


function avaSmartRenderChart(d, unit, canvasId, boxIdPrefix){
    const priceBox = document.getElementById(boxIdPrefix);
    if (priceBox) priceBox.style.display = 'none'; // با هر رندر جدید، جعبه‌ی قبلی مخفی شود

    const days = d.days || AVA_SMART_RANGE_DAYS[avaSmartRange] || 30;
    const hist = (d.history || []).slice();
    if (!hist.length) hist.push(0, 0);
    const histLen = hist.length;

    // رگرسیون خطی ساده روی همین تاریخچه، برای رسم چند نقطه‌ی میانی مسیر آینده
    const rg = (function(){
        let sumX=0,sumY=0,sumXY=0,sumXX=0;
        hist.forEach((y,i)=>{ sumX+=i; sumY+=y; sumXY+=i*y; sumXX+=i*i; });
        const denom = (histLen*sumXX - sumX*sumX);
        const slope = denom!==0 ? (histLen*sumXY - sumX*sumY)/denom : 0;
        const intercept = histLen>0 ? (sumY - slope*sumX)/histLen : 0;
        return { slope, intercept };
    })();

    // نقاط بیشتر روی مسیر پیش‌بینی تا خط طلایی روی نمودار کوتاه/فشرده به‌نظر
    // نرسد — تقریباً هم‌عرض بخش تاریخچه، تا واقعاً «کشیده» دیده شود
    const futureSteps = Math.max(14, histLen);
    const labels = hist.map((_, i) => i);
    const historyData = hist.slice();
    const futureData = new Array(histLen - 1).fill(null);
    futureData.push(hist[histLen - 1]); // نقطه‌ی اتصال، دقیقاً همان آخرین نقطه‌ی واقعی

    // (اصلاح) به‌جای نویز ریزِ زیگزاگی، مسیر پیش‌بینی یک موج بزرگ و واضح دارد
    // (بره بالا، بیاد پایین، دوباره بره بالا — یا برعکس، بسته به جهت روند)،
    // نه چندین نوسان ریز؛ نقطه‌ی پایانی همیشه دقیقاً همان عدد «پیش‌بینی» می‌ماند.
    const histHigh = Math.max.apply(null, hist);
    const histLow  = Math.min.apply(null, hist);
    const wiggleAmp = Math.max((histHigh - histLow) * 0.22, hist[histLen - 1] * 0.008);
    for (let s = 1; s <= futureSteps; s++){
        const dayOffset = (s / futureSteps) * days;
        const idx = histLen - 1 + dayOffset;
        const trendVal = Math.max(0, rg.intercept + rg.slope * idx);
        const isLast = (s === futureSteps);
        const damp = 1 - (s / futureSteps); // نوسان نزدیک هدف نهایی به صفر می‌رسد
        const wiggle = isLast ? 0 : Math.sin((s / futureSteps) * Math.PI * 2.3) * wiggleAmp * damp;
        const val = Math.max(0, trendVal + wiggle);
        labels.push(histLen - 1 + dayOffset);
        historyData.push(null);
        futureData.push(val);
    }

    const cv = document.getElementById(canvasId);
    if (!cv) return;

    if (avaSmartDashFrame) { cancelAnimationFrame(avaSmartDashFrame); avaSmartDashFrame = null; }
    if (avaSmartChart){ avaSmartChart.destroy(); avaSmartChart = null; }
    // اطمینان از ثبت افزونه‌ی زوم (بعضی نسخه‌های UMD نیاز به ثبت صریح دارند)
    try {
        const zoomPlugin = window.ChartZoom || window['chartjs-plugin-zoom'] || window.chartjsPluginZoom;
        if (zoomPlugin && Chart.registry && !Chart.registry.plugins.get('zoom')) Chart.register(zoomPlugin);
    } catch(e){}
    try {
        avaSmartChart = new Chart(cv, {
            type: 'line',
            data: { labels: labels, datasets: [
                {
                    label: 'history', data: historyData, borderColor: '#A855F7', backgroundColor: 'rgba(168,85,247,.14)',
                    borderWidth: 2, fill: true, tension: .35, spanGaps: false,
                    pointRadius: 0, pointBorderColor: '#0A0520', pointBorderWidth: 1.2,
                    pointHoverRadius: 5, pointHoverBackgroundColor: '#38BDF8'
                },
                {
                    label: 'future', data: futureData, borderColor: '#EAB308', backgroundColor: 'transparent',
                    borderWidth: 2.2, borderDash: [6, 4], fill: false, tension: .35, spanGaps: false,
                    pointRadius: (ctx) => (ctx.dataIndex === labels.length - 1 ? 4 : 0),
                    pointBackgroundColor: '#EAB308', pointBorderColor: '#0A0520',
                    pointHoverRadius: 5, pointHoverBackgroundColor: '#EAB308'
                }
            ]},
            options: {
                responsive: true, maintainAspectRatio: false,
                animation: { duration: 700 },
                plugins: {
                    legend: { display:false },
                    tooltip: {
                        enabled: false,
                        external: function(context){ avaSmartExternalTooltip(context, unit, historyData, histLen, boxIdPrefix); }
                    },
                    zoom: {
                        // pan با یک انگشت همون ژست کشیدن-انگشت-برای-دیدن-قیمت را می‌قاپد؛
                        // فقط زوم با دو انگشت (پینچ) فعاله، یک‌انگشتی برای دیدن قیمت آزاد می‌ماند.
                        pan: { enabled: false },
                        zoom: { pinch: { enabled: true }, wheel: { enabled: false }, mode: 'x' },
                        limits: { x: { minRange: 5 } }
                    }
                },
                scales: { x:{ display:false }, y:{ display:false } },
                interaction: { intersect:false, mode:'index' }
            }
        });
        avaSmartAnimateFutureDash();
    } catch(e){ console.error('avaSmart chart', e); }
}

// خط نقطه‌چینِ مسیر پیش‌بینی را «متحرک» می‌کند (مثل مورچه‌های در حال حرکت روی خط)
let avaSmartDashFrame = null;
let avaSmartDashOffset = 0;
function avaSmartAnimateFutureDash(){
    if (!avaSmartChart || !avaSmartChart.data || !avaSmartChart.data.datasets[1]) return;
    avaSmartDashOffset -= 0.6;
    avaSmartChart.data.datasets[1].borderDashOffset = avaSmartDashOffset;
    try { avaSmartChart.update('none'); } catch(e){ return; }
    avaSmartDashFrame = requestAnimationFrame(avaSmartAnimateFutureDash);
}

function avaSmartCountUp(el, to){
    let cur = 0;
    const step = Math.max(1, Math.round(to / 24));
    if (el.__avaCountTimer) clearInterval(el.__avaCountTimer);
    el.__avaCountTimer = setInterval(() => {
        cur += step;
        if (cur >= to) { cur = to; clearInterval(el.__avaCountTimer); }
        el.textContent = avaSmartFmt(cur);
    }, 16);
}

function avaSmartResetZoom(){
    if (avaSmartChart && typeof avaSmartChart.resetZoom === 'function') avaSmartChart.resetZoom();
}
function avaSmartOpenBestPrice(){
    const DATA = window.AVA_SMART_DATA || {};
    const d = DATA[avaSmartCur] || {};
    if (d.bestBuyPrice > 0) avaSmartOpenAdsAtPrice(avaSmartCur, d.bestBuyPrice);
}

/* ---- باز کردن کل ابزار در مدال تمام‌صفحه (از روی گجت خلاصه‌ی داشبورد) ---- */
function avaSmartOpenFullscreen(){
    avaOpenFsModal('avaSmartFullscreenModal');
    document.querySelectorAll('#avaSmartTfTabs .ava-smart-tf-tab').forEach(b => {
        b.classList.toggle('on', b.dataset.range === avaSmartRange);
    });
    // با یک تأخیر کوتاه رندر می‌کنیم تا مودال کاملاً باز و اندازه‌گیری شده باشد
    setTimeout(avaSmartRender, 60);
    avaLoadTargets();
}

/* ---- (جدید) تارگت‌های پیش‌بینی‌شده: کارت خلاصه + شیت «مشاهده همه» ---- */
let avaTargetsCache = null;

async function avaLoadTargets(){
    try {
        const res = await fetch('dashboard.php?ava=ai_targets_list');
        const j = await res.json();
        avaTargetsCache = (j && j.success && Array.isArray(j.items)) ? j.items : [];
    } catch(e){ avaTargetsCache = []; }
    avaRenderTargets('avaTargetsPreviewList', 40);
    const sheet = document.getElementById('avaTargetsSheet');
    if (sheet && sheet.classList.contains('open')) avaRenderTargets('avaTargetsSheetList', 99);
}

function avaRenderTargets(containerId, limit){
    const el = document.getElementById(containerId);
    if (!el) return;
    if (avaTargetsCache === null){
        el.innerHTML = '<div class="ava-targets-empty">در حال بارگذاری تارگت‌ها...</div>';
        return;
    }
    const items = avaTargetsCache.slice(0, limit);
    if (!items.length){
        el.innerHTML = '<div class="ava-targets-empty">هنوز تارگتی برای نمایش نیست — با باز کردن تب هر ارز (بازه‌ی ۱ ماهه)، پیش‌بینی‌اش این‌جا ثبت می‌شود.</div>';
        return;
    }
    el.innerHTML = items.map(it => {
        const iconHtml = it.image
            ? '<img src="' + avaEsc(it.image) + '" alt="" onerror="this.style.display=\'none\'">'
            : (it.flag
                ? '<img src="https://flagcdn.com/w40/' + avaEsc(it.flag) + '.png" alt="" onerror="this.style.display=\'none\'">'
                : '<i class="' + avaEsc(it.ico || 'fas fa-coins') + '" style="color:' + avaEsc(it.col || '#7C3AED') + '"></i>');
        const unitTxt = it.unit === '$' ? '$' : ' تومان';
        const isUp = it.direction !== 'down';
        const statusHtml = it.hit
            ? '<div class="ava-target-status hit"><i class="fas fa-check"></i></div>'
            : '<div class="ava-target-status pending"><i class="fas fa-circle-notch fa-spin"></i></div>';
        const barCls = 'ava-target-bar-fill' + (it.hit ? ' hit' : '') + (isUp ? '' : ' down');
        const kind = it.unit === '$' ? 'crypto' : 'fiat';
        const dirChip = isUp
            ? '<span class="ava-target-dir up"><i class="fas fa-arrow-trend-up"></i> صعودی</span>'
            : '<span class="ava-target-dir down"><i class="fas fa-arrow-trend-down"></i> نزولی</span>';
        const tfChip = it.timeframe === '4h'
            ? '<span class="ava-target-tf near"><i class="fas fa-bolt"></i> ۴ ساعته</span>'
            : '<span class="ava-target-tf far"><i class="fas fa-calendar-days"></i> سه‌روزه</span>';
        return '<div class="ava-target-row" onclick="avaSmartSelectCurFromTargets(\'' + it.id + '\', \'' + kind + '\')">'
            + '<div class="ava-target-row-head">'
                + '<div class="ava-target-ico">' + iconHtml + '</div>'
                + '<div class="ava-target-name"><div class="ava-target-sym">' + avaEsc(it.symbol) + '</div><div class="ava-target-fname">' + avaEsc(it.name) + '</div></div>'
                + statusHtml
            + '</div>'
            + '<div class="ava-target-mid">'
                + '<div class="ava-target-prices">' + dirChip + tfChip + '</div>'
                + '<div class="ava-target-prices"><span>هدف <b>' + avaSmartFmt(it.target) + unitTxt + '</b></span><span>فعلی <b>' + avaSmartFmt(it.current) + unitTxt + '</b></span></div>'
                + '<div class="ava-target-bar"><div class="' + barCls + '" style="width:' + it.progress + '%"></div></div>'
            + '</div>'
        + '</div>';
    }).join('');
}

/* ===== (جدید) تبِ «تارگت‌های خورده‌شده» — تقویمِ هدف‌هایی که واقعاً رسیده‌اند =====
   قبلاً همه‌ی تارگت‌های خورده‌شده زیر هم انباشته می‌شدند و با گذشت زمان لیست
   خیلی بلند/شلوغ می‌شد. حالا هر روز فقط یک خانه‌ی تقویم با تعداد است؛ با زدن
   روی یک روز، فقط تارگت‌های همان روز پایینِ تقویم نشان داده می‌شوند. */
let avaTargetsHitCache = null;
let avaTargetsActiveTab = 'pending';
let avaTargetsPreviewActiveTab = 'pending';
let __avaCalState = {}; // وضعیتِ تقویم به ازای هر containerId، مستقل از هم (کارتِ کوچک + شیتِ «مشاهده همه»)

function avaCalKey(d){
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}
function avaCalKeyToDate(key){
    const p = String(key).split('-').map(Number);
    return new Date(p[0], p[1] - 1, p[2]);
}
const AVA_CAL_LATIN_FMT = new Intl.DateTimeFormat('fa-IR-u-ca-persian-nu-latn', { year: 'numeric', month: 'numeric', day: 'numeric' });
function avaJalaliParts(d){
    const parts = AVA_CAL_LATIN_FMT.formatToParts(d);
    const o = {};
    parts.forEach(p => { if (p.type !== 'literal') o[p.type] = parseInt(p.value, 10); });
    return o;
}
function avaJalaliMonthStart(d){
    const p = avaJalaliParts(d);
    const nd = new Date(d.getFullYear(), d.getMonth(), d.getDate());
    nd.setDate(nd.getDate() - (p.day - 1));
    return nd;
}
function avaJalaliMonthDays(monthStart){
    const p0 = avaJalaliParts(monthStart);
    let count = 0;
    const d = new Date(monthStart);
    while (count < 32){
        const p = avaJalaliParts(d);
        if (p.year !== p0.year || p.month !== p0.month) break;
        count++;
        d.setDate(d.getDate() + 1);
    }
    return count;
}
function avaJalaliMonthLabel(d){
    return new Intl.DateTimeFormat('fa-IR-u-ca-persian', { month: 'long', year: 'numeric' }).format(d);
}

function avaCalNav(containerId, dir){
    const state = __avaCalState[containerId];
    if (!state) return;
    const ms = avaJalaliMonthStart(state.anchor);
    const probe = new Date(ms);
    if (dir > 0) probe.setDate(probe.getDate() + avaJalaliMonthDays(ms));
    else probe.setDate(probe.getDate() - 1);
    state.anchor = probe;
    state.selectedKey = null;
    avaRenderHitTargets(containerId);
}
function avaCalSelectDay(containerId, key){
    const state = __avaCalState[containerId];
    if (!state) return;
    state.selectedKey = key;
    avaRenderHitTargets(containerId);
}

function avaHitRowHtml(it){
    const iconHtml = it.image
        ? '<img src="' + avaEsc(it.image) + '" alt="" onerror="this.style.display=\'none\'">'
        : (it.flag
            ? '<img src="https://flagcdn.com/w40/' + avaEsc(it.flag) + '.png" alt="" onerror="this.style.display=\'none\'">'
            : '<i class="' + avaEsc(it.ico || 'fas fa-coins') + '" style="color:' + avaEsc(it.col || '#7C3AED') + '"></i>');
    const unitTxt = it.unit === '$' ? '$' : ' تومان';
    const isUp = it.direction !== 'down';
    const dirChip = isUp
        ? '<span class="ava-target-dir up"><i class="fas fa-arrow-trend-up"></i> صعودی</span>'
        : '<span class="ava-target-dir down"><i class="fas fa-arrow-trend-down"></i> نزولی</span>';
    const tfChip = it.timeframe === '۴ ساعته'
        ? '<span class="ava-target-tf near"><i class="fas fa-bolt"></i> ۴ ساعته</span>'
        : '<span class="ava-target-tf far"><i class="fas fa-calendar-days"></i> سه‌روزه</span>';
    let timeTxt = '';
    try {
        const d = new Date(it.resolved_at.replace(' ', 'T'));
        timeTxt = d.toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' });
    } catch(e){ timeTxt = ''; }
    return '<div class="ava-target-row hit-row">'
        + '<div class="ava-target-row-head">'
            + '<div class="ava-target-ico">' + iconHtml + '</div>'
            + '<div class="ava-target-name"><div class="ava-target-sym">' + avaEsc(it.symbol) + '</div><div class="ava-target-fname">' + avaEsc(it.name) + '</div></div>'
            + '<div class="ava-target-status hit"><i class="fas fa-check"></i></div>'
        + '</div>'
        + '<div class="ava-target-mid">'
            + '<div class="ava-target-prices">' + dirChip + tfChip + '</div>'
            + '<div class="ava-target-prices"><span>هدف <b>' + avaSmartFmt(it.target) + unitTxt + '</b></span><span>رسیده به <b>' + avaSmartFmt(it.resolved_price) + unitTxt + '</b></span></div>'
            + '<div class="ava-target-hit-date"><i class="fas fa-clock"></i> ' + avaEsc(timeTxt) + '</div>'
        + '</div>'
    + '</div>';
}

function avaCalBuildGrid(containerId, byDate){
    const state = __avaCalState[containerId];
    const monthStart = avaJalaliMonthStart(state.anchor);
    const daysInMonth = avaJalaliMonthDays(monthStart);
    const leading = (monthStart.getDay() + 1) % 7;
    const todayKey = avaCalKey(new Date());
    const weekDays = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];

    let cells = '';
    for (let i = 0; i < leading; i++) cells += '<div class="ava-cal-cell empty"></div>';
    for (let day = 1; day <= daysInMonth; day++){
        const gd = new Date(monthStart);
        gd.setDate(gd.getDate() + (day - 1));
        const key = avaCalKey(gd);
        const count = (byDate[key] || []).length;
        const cls = 'ava-cal-cell'
            + (count ? ' has-hit' : '')
            + (key === todayKey ? ' today' : '')
            + (key === state.selectedKey ? ' sel' : '');
        cells += '<button type="button" class="' + cls + '" onclick="avaCalSelectDay(\'' + containerId + '\',\'' + key + '\')">'
            + '<span class="ava-cal-daynum">' + avaFa(day) + '</span>'
            + (count ? '<span class="ava-cal-dot">' + avaFa(count > 9 ? 9 : count) + (count > 9 ? '+' : '') + '</span>' : '')
        + '</button>';
    }

    return '<div class="ava-cal">'
        + '<div class="ava-cal-head">'
            + '<button type="button" class="ava-cal-nav" onclick="avaCalNav(\'' + containerId + '\',-1)"><i class="fas fa-chevron-right"></i></button>'
            + '<span class="ava-cal-label">' + avaJalaliMonthLabel(monthStart) + '</span>'
            + '<button type="button" class="ava-cal-nav" onclick="avaCalNav(\'' + containerId + '\',1)"><i class="fas fa-chevron-left"></i></button>'
        + '</div>'
        + '<div class="ava-cal-week">' + weekDays.map(w => '<span>' + w + '</span>').join('') + '</div>'
        + '<div class="ava-cal-grid">' + cells + '</div>'
    + '</div>';
}

function avaTargetsSwitchTab(tab){
    avaTargetsActiveTab = tab;
    const btnPending = document.getElementById('avaTargetsTabPending');
    const btnHit = document.getElementById('avaTargetsTabHit');
    const listPending = document.getElementById('avaTargetsSheetList');
    const listHit = document.getElementById('avaTargetsHitList');
    if (tab === 'hit'){
        if (btnPending) btnPending.classList.remove('on');
        if (btnHit) btnHit.classList.add('on');
        if (listPending) listPending.style.display = 'none';
        if (listHit) listHit.style.display = '';
        if (avaTargetsHitCache === null) avaLoadHitTargets();
        else avaRenderHitTargets('avaTargetsHitList');
    } else {
        if (btnPending) btnPending.classList.add('on');
        if (btnHit) btnHit.classList.remove('on');
        if (listPending) listPending.style.display = '';
        if (listHit) listHit.style.display = 'none';
    }
}

// (جدید) همین تب، ولی روی خودِ کادرِ کوچکِ داشبورد (بدون نیاز به باز کردنِ «مشاهده همه»)
function avaTargetsPreviewSwitchTab(tab){
    avaTargetsPreviewActiveTab = tab;
    const btnPending = document.getElementById('avaTargetsPvTabPending');
    const btnHit = document.getElementById('avaTargetsPvTabHit');
    const listPending = document.getElementById('avaTargetsPreviewList');
    const listHit = document.getElementById('avaTargetsPreviewHitList');
    if (tab === 'hit'){
        if (btnPending) btnPending.classList.remove('on');
        if (btnHit) btnHit.classList.add('on');
        if (listPending) listPending.style.display = 'none';
        if (listHit) listHit.style.display = '';
        if (avaTargetsHitCache === null) avaLoadHitTargets();
        else avaRenderHitTargets('avaTargetsPreviewHitList');
    } else {
        if (btnPending) btnPending.classList.add('on');
        if (btnHit) btnHit.classList.remove('on');
        if (listPending) listPending.style.display = '';
        if (listHit) listHit.style.display = 'none';
    }
}

async function avaLoadHitTargets(){
    const el = document.getElementById('avaTargetsHitList');
    if (el) el.innerHTML = '<div class="ava-targets-empty">در حال بارگذاری...</div>';
    try {
        const res = await fetch('dashboard.php?ava=ai_targets_hit_list');
        const j = await res.json();
        avaTargetsHitCache = (j && j.success && Array.isArray(j.items)) ? j.items : [];
    } catch(e){ avaTargetsHitCache = []; }
    avaRenderHitTargets('avaTargetsHitList');
    avaRenderHitTargets('avaTargetsPreviewHitList');
}

function avaRenderHitTargets(containerId){
    const el = document.getElementById(containerId);
    if (!el) return;
    const items = avaTargetsHitCache;
    if (items === null){
        el.innerHTML = '<div class="ava-targets-empty">در حال بارگذاری...</div>';
        return;
    }
    if (!items.length){
        el.innerHTML = '<div class="ava-targets-empty">هنوز هیچ تارگتی به هدف نرسیده — به محض برخورد اولین پیش‌بینی، همین‌جا نشان داده می‌شود (تا ۲ هفته).</div>';
        return;
    }

    // گروه‌بندی تارگت‌های خورده‌شده بر اساس روزِ رسیدن (برای خانه‌های تقویم)
    const byDate = {};
    items.forEach(it => {
        let key = null;
        try { key = avaCalKey(new Date(String(it.resolved_at || '').replace(' ', 'T'))); } catch(e){}
        if (!key) return;
        (byDate[key] = byDate[key] || []).push(it);
    });

    if (!__avaCalState[containerId]){
        const keys = Object.keys(byDate).sort();
        const lastKey = keys[keys.length - 1] || avaCalKey(new Date());
        __avaCalState[containerId] = { anchor: avaCalKeyToDate(lastKey), selectedKey: lastKey };
    }
    const state = __avaCalState[containerId];
    const dayItems = state.selectedKey ? (byDate[state.selectedKey] || []) : [];

    let dayLabel = '';
    let listHtml = '<div class="ava-targets-empty">روزی را که روی تقویم علامت‌دار است انتخاب کنید تا تارگت‌های همان روز را ببینید.</div>';
    if (state.selectedKey){
        try {
            const dd = avaCalKeyToDate(state.selectedKey);
            dayLabel = (avaCalKey(dd) === avaCalKey(new Date())) ? 'امروز' : dd.toLocaleDateString('fa-IR');
        } catch(e){ dayLabel = 'این روز'; }
        listHtml = dayItems.length
            ? dayItems.map(avaHitRowHtml).join('')
            : '<div class="ava-targets-empty">تارگتی برای این روز ثبت نشده.</div>';
    }

    el.innerHTML = '<div class="ava-cal-wrap">'
        + avaCalBuildGrid(containerId, byDate)
        + (state.selectedKey
            ? '<div class="ava-cal-daylist-ttl"><i class="fas fa-calendar-day"></i> تارگت‌های ' + avaEsc(dayLabel) + '<span class="ava-cal-daylist-count">' + avaFa(dayItems.length) + '</span></div>'
            : '')
        + '<div class="ava-cal-daylist">' + listHtml + '</div>'
    + '</div>';
}

function avaSmartSelectCurFromTargets(id, kind){
    avaCloseSheet('avaTargetsSheet');
    const btn = document.querySelector('#avaSmartTabs .ava-smart-tab[data-cur="' + id + '"]');
    if (btn) {
        avaSmartSelectCur(id, kind, btn);
        btn.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
    }
}

/* ---- افزودن/حذف ارز دیجیتال کاربر ---- */
function avaSmartOpenCoinPicker(){
    avaOpenSheet('avaAiCoinPickerSheet');
    avaSmartRenderCoinPicker();
}
async function avaSmartRenderCoinPicker(){
    const box = document.getElementById('avaAiCoinPickerList');
    if (!box) return;
    if (!window.__avaMarketAll || !window.__avaMarketAll.length){
        box.innerHTML = '<div class="ava-empty"><i class="fas fa-spinner fa-spin"></i> در حال بارگذاری فهرست ارزها…</div>';
        try {
            const res = await fetch('api/crypto_market_api.php');
            const j = await res.json();
            if (j && j.success){
                window.__avaMarketAll = [].concat(j.market || [], j.gainers || []);
            }
        } catch(e){}
    }
    const list = window.__avaMarketAll || [];
    const q = (document.getElementById('avaAiCoinSearch') || {}).value || '';
    const qLower = q.trim().toLowerCase();
    const already = (window.AVA_SMART_COINS || []).map(c => c.coin_id);
    const filtered = list.filter(c => !qLower || (c.name||'').toLowerCase().includes(qLower) || (c.symbol||'').toLowerCase().includes(qLower)).slice(0, 30);
    if (!filtered.length){ box.innerHTML = '<div class="ava-empty">ارزی پیدا نشد</div>'; return; }
    box.innerHTML = filtered.map(function(c){
        const added = already.includes(c.id);
        const img = c.image ? '<img src="' + c.image + '" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;" onerror="this.style.display=\'none\'">' : '<i class="fab fa-bitcoin" style="color:#F7931A"></i>';
        return '<div class="ava-rate-pick">'
            + '<span class="ava-flag" style="width:26px;height:26px;font-size:.8rem;">' + img + '</span>'
            + '<div style="flex:1;min-width:0;">'
            + '<div class="ava-rate-name">' + avaEsc(c.name) + '</div>'
            + '<div class="ava-rate-code">' + avaEsc((c.symbol||'').toUpperCase()) + '</div>'
            + '</div>'
            + '<button class="ava-rate-pick-btn ' + (added ? 'on' : '') + '" ' + (added ? 'disabled' : '') + ' onclick="avaSmartAddCoin(\'' + c.id + '\', \'' + avaEsc(c.name).replace(/'/g,'') + '\', \'' + avaEsc(c.symbol).replace(/'/g,'') + '\', \'' + (c.image ? c.image.replace(/'/g,'') : '') + '\', this)">'
            + '<i class="fas ' + (added ? 'fa-check' : 'fa-plus') + '"></i> ' + (added ? 'افزوده‌شده' : 'افزودن')
            + '</button></div>';
    }).join('');
}
async function avaSmartAddCoin(id, name, symbol, image, btn){
    if (btn) btn.disabled = true;
    try {
        const res = await fetch('dashboard.php?ava=ai_coin_add', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ coin_id: id, coin_name: name, coin_symbol: symbol, coin_image: image })
        });
        const d = await res.json();
        if (d && d.success){
            avaToast('✅ ' + name + ' اضافه شد');
            setTimeout(() => window.location.reload(), 700);
        } else {
            avaToast(d.message || 'خطا در افزودن ارز');
            if (btn) btn.disabled = false;
        }
    } catch(e){
        avaToast('خطا در ارتباط با سرور');
        if (btn) btn.disabled = false;
    }
}
async function avaSmartRemoveCoin(id){
    try {
        const res = await fetch('dashboard.php?ava=ai_coin_remove', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ coin_id: id })
        });
        const d = await res.json();
        if (d && d.success) window.location.reload();
    } catch(e){}
}

/* ====================================================================
   جعبه‌ی شناور قیمت روی نمودار: با کشیدن انگشت روی نمودار، به‌جای باز شدن
   مستقیم مدال، این جعبه قیمت همان نقطه + تعداد آگهی‌های نزدیکش را نشان
   می‌دهد؛ با زدن روی خودِ جعبه، مدال آگهی‌ها باز می‌شود.
   ==================================================================== */
let avaSmartBoxPrice = 0;
let avaSmartAdsCountCache = {}; // کش تعداد آگهی هر قیمت (گرد‌شده) در همین session
let avaSmartAdsCountTimer = null;

function avaSmartExternalTooltip(context, unit, historyData, histLen, boxIdPrefix){
    boxIdPrefix = boxIdPrefix || 'avaSmartPriceBox';
    const tip = context.tooltip;
    const box = document.getElementById(boxIdPrefix);
    if (!box) return;
    if (!tip || tip.opacity === 0 || !tip.dataPoints || !tip.dataPoints.length){
        box.style.display = 'none';
        return;
    }
    const dp = tip.dataPoints[0];
    const idx = dp.dataIndex;
    const isFuture = idx >= histLen;
    const price = isFuture ? dp.parsed.y : historyData[idx];
    if (!(price > 0)){ box.style.display = 'none'; return; }

    avaSmartBoxPrice = Math.round(price);
    const valEl = document.getElementById('avaSmartPriceBoxVal');
    const adsEl = document.getElementById('avaSmartPriceBoxAds');
    if (valEl) valEl.textContent = (isFuture ? 'پیش‌بینی: ' : '') + avaSmartFmt(price) + ' ' + unit;

    // موقعیت جعبه دقیقاً بالای نقطه‌ی زیرِ انگشت
    const canvasRect = context.chart.canvas.getBoundingClientRect();
    const wrapRect = context.chart.canvas.parentNode.getBoundingClientRect();
    box.style.left = (canvasRect.left - wrapRect.left + tip.caretX) + 'px';
    box.style.top = (canvasRect.top - wrapRect.top + tip.caretY) + 'px';
    box.style.display = 'block';

    if (avaSmartKind !== 'fiat' || isFuture){
        // برای ارز دیجیتال یا نقطه‌ی روی مسیر پیش‌بینی، آگهی واقعی وجود ندارد
        if (adsEl) adsEl.textContent = isFuture ? 'قابل معامله نیست' : '';
        box.classList.toggle('ava-smart-price-box-disabled', true);
        return;
    }
    box.classList.toggle('ava-smart-price-box-disabled', false);

    // تعداد آگهی‌ها را با یک تأخیر کوتاه (debounce) می‌گیریم تا حین کشیدن
    // سریع انگشت، درخواست‌های زیاد به سرور نره
    const bucket = Math.round(price / 50) * 50; // گرد کردن برای استفاده‌ی بهتر از کش
    if (avaSmartAdsCountCache[avaSmartCur + ':' + bucket] !== undefined){
        if (adsEl) adsEl.textContent = avaSmartAdsCountCache[avaSmartCur + ':' + bucket] + ' آگهی نزدیک این قیمت';
        return;
    }
    if (adsEl) adsEl.textContent = 'در حال بررسی آگهی‌ها…';
    if (avaSmartAdsCountTimer) clearTimeout(avaSmartAdsCountTimer);
    const curAtRequest = avaSmartCur;
    avaSmartAdsCountTimer = setTimeout(async () => {
        try {
            const res = await fetch('dashboard.php?ava=ads_at_price&currency=' + encodeURIComponent(curAtRequest) + '&price=' + encodeURIComponent(price));
            const d = await res.json();
            const count = (d && d.success && d.ads) ? d.ads.length : 0;
            avaSmartAdsCountCache[curAtRequest + ':' + bucket] = count;
            // فقط اگر هنوز همون قیمت زیر انگشته، متن رو به‌روز کن
            const adsElNow = document.getElementById('avaSmartPriceBoxAds');
            if (avaSmartBoxPrice === Math.round(price) && adsElNow){
                adsElNow.textContent = count + ' آگهی نزدیک این قیمت';
            }
        } catch(e){}
    }, 220);
}

function avaSmartPriceBoxClick(){
    const box = document.getElementById('avaSmartPriceBox');
    if (box && box.classList.contains('ava-smart-price-box-disabled')) return;
    if (avaSmartKind !== 'fiat' || !(avaSmartBoxPrice > 0)) return;
    avaSmartOpenAdsAtPrice(avaSmartCur, avaSmartBoxPrice);
}

async function avaSmartOpenAdsAtPrice(cur, price){
    const listEl = document.getElementById('avaSmartAdsList');
    const titleEl = document.getElementById('avaSmartAdsTitle');
    if (titleEl) titleEl.innerHTML = '<i class="fas fa-layer-group"></i> آگهی‌های نزدیک ' + avaSmartFmt(price) + ' تومان';
    if (listEl) listEl.innerHTML = '<div class="ava-empty"><i class="fas fa-spinner fa-spin"></i> در حال جست‌وجوی آگهی‌ها…</div>';
    avaOpenFsModal('avaSmartAdsModal');
    try {
        const res = await fetch('dashboard.php?ava=ads_at_price&currency=' + encodeURIComponent(cur) + '&price=' + encodeURIComponent(price));
        const d = await res.json();
        if (!d || !d.success){ throw new Error('bad'); }
        if (!d.ads || !d.ads.length){
            if (listEl) listEl.innerHTML = '<div class="ava-empty">آگهی‌ای نزدیک این قیمت پیدا نشد</div>';
            return;
        }
        if (listEl){
            // «هوشمند بهترین قیمت‌ها برای خرید»: آگهی‌های فروش (که خریدار باهاشون طرفه) را
            // از ارزان به گران مرتب کن و ارزان‌ترین‌شون را با نشان «بهترین قیمت» مشخص کن؛
            // آگهی‌های خرید بعد از آن‌ها می‌آیند (به همان ترتیب نزدیکی به قیمت کلیک‌شده).
            const sells = d.ads.filter(a => a.type !== 'buy').sort((a, b) => a.price - b.price);
            const buys  = d.ads.filter(a => a.type === 'buy');
            const ordered = sells.concat(buys);
            const bestSellId = sells.length ? sells[0].id : null;

            listEl.innerHTML = ordered.map(function(ad){
                const isBuy = ad.type === 'buy';
                const isBest = (ad.id === bestSellId);
                const seller = avaEsc(ad.seller || 'کاربر').replace(/'/g, '');
                return '<div class="ava-list-row' + (isBest ? ' ava-smart-best-row' : '') + '" style="cursor:pointer" onclick="avaOpenOfferModal(' + ad.id + ', \'' + ad.currency + '\', ' + ad.amount + ', ' + ad.price + ', \'' + ad.type + '\', \'' + seller + '\')">'
                    + '<div class="ava-adrow-ic" style="border:2px solid ' + (isBuy ? '#22C55E' : '#FF5A6E') + ';">'
                    + '<img src="' + ad.avatar + '" alt="" loading="lazy" onerror="this.src=\'/ledor/default-avatar.png\'">'
                    + '</div>'
                    + '<div class="ava-list-body">'
                    + '<div class="ava-list-t">' + (isBuy ? 'خرید' : 'فروش') + ' ' + ad.currency + ' — ' + avaEsc(ad.seller || 'کاربر')
                    + (isBest ? ' <span class="ava-smart-best-badge"><i class="fas fa-trophy"></i> بهترین قیمت</span>' : '') + '</div>'
                    + '<div class="ava-list-s">' + avaSmartFmt(ad.amount) + ' ' + ad.currency + ' · ' + avaSmartFmt(ad.price) + ' تومان</div>'
                    + '</div>'
                    + '<i class="fas fa-chevron-left" style="color:rgba(255,255,255,.35);font-size:.7rem;"></i>'
                    + '</div>';
            }).join('');
        }
    } catch(e){
        if (listEl) listEl.innerHTML = '<div class="ava-empty">خطا در دریافت آگهی‌ها</div>';
    }
}

document.addEventListener('DOMContentLoaded', function(){
    if (typeof Chart !== 'undefined') avaSmartRender();
    else setTimeout(avaSmartRender, 700);
});

/* ====================================================================
   گجت خلاصه‌ی داشبورد: چرخش خودکار بین دلار/یورو/تتر (بدون نیاز به باز
   کردن مدال) — هر چند ثانیه یک‌بار با محو‌شدن ملایم، ارز بعدی نشان داده می‌شود.
   ==================================================================== */
let avaGadgetIdx = 0;
const AVA_GADGET_CURS = ['USD', 'EUR', 'USDT'];
let avaGadgetTimer = null;
let avaGadgetRotating = false; // جلوگیری از هم‌پوشانی دو چرخش هم‌زمان که باعث پرش/بهم‌ریختگی می‌شد
function avaGadgetSparklinePath(hist, w, h){
    hist = (hist && hist.length >= 2) ? hist : [0, 0];
    const n = hist.length;
    const min = Math.min.apply(null, hist), max = Math.max.apply(null, hist);
    const range = (max - min) || 1;
    const pts = hist.map((v, i) => {
        const x = n > 1 ? (i / (n - 1)) * w : 0;
        const y = h - ((v - min) / range) * h;
        return Math.round(x * 10) / 10 + ',' + Math.round(y * 10) / 10;
    });
    return 'M' + pts.join(' L');
}
// (جدید) همون مسیر بالا، به‌علاوه‌ی یک ناحیه‌ی پرشده‌ی گرادیانتی زیرش — برای
// نسخه‌ی پس‌زمینه‌ی بزرگِ گجت (avaGadgetSparkBg)، هم‌ارز نسخه‌ی PHP‌ی
// ava_ai_sparkline_area_svg، چون این تابع در سمت کلاینت هم موقع چرخش خودکار
// ارز صدا زده می‌شود.
function avaGadgetSparkAreaSvg(hist, color, w, h){
    hist = (hist && hist.length >= 2) ? hist : [0, 0];
    const n = hist.length;
    const min = Math.min.apply(null, hist), max = Math.max.apply(null, hist);
    const range = (max - min) || 1;
    const pad = 6;
    const pts = hist.map((v, i) => {
        const x = n > 1 ? (i / (n - 1)) * w : 0;
        const y = (h - pad) - ((v - min) / range) * (h - pad * 2);
        return Math.round(x * 10) / 10 + ',' + Math.round(y * 10) / 10;
    });
    const line = 'M' + pts.join(' L');
    const area = line + ' L' + w + ',' + h + ' L0,' + h + ' Z';
    const gid = 'avaGadgetGradJs' + (color === '#22C55E' ? 'up' : 'down');
    return '<svg viewBox="0 0 ' + w + ' ' + h + '" width="100%" height="100%" preserveAspectRatio="none">'
        + '<defs><linearGradient id="' + gid + '" x1="0" y1="0" x2="0" y2="1">'
        + '<stop offset="0%" stop-color="' + color + '" stop-opacity="0.5"/>'
        + '<stop offset="100%" stop-color="' + color + '" stop-opacity="0"/>'
        + '</linearGradient></defs>'
        + '<path d="' + area + '" fill="url(#' + gid + ')" stroke="none"/>'
        + '<path d="' + line + '" fill="none" stroke="' + color + '" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" opacity="0.95"/>'
        + '</svg>';
}
function avaGadgetRotate(){
    // اگر صفحه در پس‌زمینه است یا چرخش قبلی هنوز تمام نشده، این نوبت را رد کن
    // تا چند به‌روزرسانی هم‌زمان روی هم نیفتند (همان چیزی که ظاهر گجت را
    // موقع تعویض ارز بهم‌ریخته/پرشی نشان می‌داد).
    if (document.hidden || avaGadgetRotating) return;
    const DATA = window.AVA_SMART_DATA || {};
    const nextIdx = (avaGadgetIdx + 1) % AVA_GADGET_CURS.length;
    const cur = AVA_GADGET_CURS[nextIdx];
    const d = DATA[cur];
    if (!d) return;
    const fade = document.getElementById('avaGadgetFade');
    if (!fade) return;

    avaGadgetRotating = true;
    fade.classList.add('ava-fading');
    setTimeout(() => {
        avaGadgetIdx = nextIdx;
        const curEl = document.getElementById('avaGadgetCur');
        const priceEl = document.getElementById('avaGadgetPrice');
        const chgEl = document.getElementById('avaGadgetChg');
        const sparkBgEl = document.getElementById('avaGadgetSparkBg');
        const up = Number(d.change) >= 0;
        if (curEl) curEl.textContent = d.name || cur;
        if (priceEl) priceEl.innerHTML = avaSmartFmt(d.last) + ' <small>تومان</small>';
        if (chgEl){
            chgEl.innerHTML = '<i class="fas fa-arrow-trend-' + (up ? 'up' : 'down') + '"></i> ' + (up ? '+' : '') + d.change + '٪';
            chgEl.className = 'ava-smart-gadget-chg ' + (up ? 'up' : 'down');
        }
        if (sparkBgEl){
            const color = up ? '#22C55E' : '#FF5A6E';
            sparkBgEl.innerHTML = avaGadgetSparkAreaSvg(d.history, color, 340, 120);
        }
        document.querySelectorAll('#avaGadgetDots span').forEach((s, i) => s.classList.toggle('on', i === avaGadgetIdx));
        fade.classList.remove('ava-fading');
        // بعد از این‌که ترنزیشن fade-in هم کامل تمام شد، اجازه‌ی چرخش بعدی را بده
        setTimeout(() => { avaGadgetRotating = false; }, 320);
    }, 300);
}
// با زدن روی گجت، دقیقاً همان ارزی که همین الان روی گجت نمایش داده می‌شود در
// مدال باز شود — قبلاً همیشه دلار باز می‌شد، حتی اگر گجت یورو/تتر را نشان
// می‌داد، که با چیزی که کاربر می‌بیند هم‌خوانی نداشت و گیج‌کننده بود.
function avaGadgetOpenFullscreen(){
    const cur = AVA_GADGET_CURS[avaGadgetIdx] || 'USD';
    avaSmartCur = cur;
    avaSmartKind = 'fiat';
    document.querySelectorAll('#avaSmartTabs .ava-smart-tab').forEach(b => {
        b.classList.toggle('on', b.dataset.cur === cur && b.dataset.kind === 'fiat');
    });
    avaSmartOpenFullscreen();
}
document.addEventListener('DOMContentLoaded', function(){
    if (document.getElementById('avaGadgetFade') && !avaGadgetTimer){
        avaGadgetTimer = setInterval(avaGadgetRotate, 4000);
    }
});

/* ====================================================================
   تغییر پوسته‌ی روشن/تیره — دکمه‌ی خورشید/ماه در هدر. از توابع سراسری
   avaSetTheme/avaGetTheme که در includes/footer_menu.php تعریف شده‌اند
   استفاده می‌کند (همان‌ها که تم را در سرور/localStorage ذخیره می‌کنند).
   ==================================================================== */
(function(){
    function syncThemeIcon(){
        const icon = document.getElementById('avaThemeIcon');
        if (!icon) return;
        const cur = (typeof window.avaGetTheme === 'function')
            ? window.avaGetTheme()
            : (document.documentElement.getAttribute('data-theme') || 'dark');
        icon.className = (cur === 'light') ? 'fas fa-moon' : 'fas fa-sun';
    }
    window.avaToggleTheme = function(){
        const cur = (typeof window.avaGetTheme === 'function')
            ? window.avaGetTheme()
            : (document.documentElement.getAttribute('data-theme') || 'dark');
        const next = (cur === 'light') ? 'dark' : 'light';
        if (typeof window.avaSetTheme === 'function') {
            window.avaSetTheme(next);
        } else {
            document.documentElement.setAttribute('data-theme', next);
            try { localStorage.setItem('ava_theme', next); } catch(e){}
        }
        syncThemeIcon();
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', syncThemeIcon);
    } else {
        syncThemeIcon();
    }
})();

/* ====================================================================
   نمایش/مخفی‌کردن بخش‌های داشبورد — یک مودال ساده با لیست همه‌ی بخش‌ها
   (از اول تا آخر داشبورد) و یک کلید روشن/خاموش برای هر کدام
   ==================================================================== */
(function(){
    const SEC_SEL = '[data-avasec]';
    let avaSecPrefs = { hidden: [] };
    let avaSaveTimer = null;

    function avaSecKey(el){ return el.getAttribute('data-avasec'); }

    window.avaToggleLayoutEdit = function(){
        let modal = document.getElementById('avaSecModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'avaSecModal';
            modal.className = 'ava-sec-modal-overlay';
            modal.innerHTML = `
                <div class="ava-sec-modal-sheet">
                    <div class="ava-sec-modal-head">
                        <span><i class="fas fa-eye"></i> نمایش/مخفی‌کردن بخش‌های داشبورد</span>
                        <button type="button" onclick="avaCloseSecModal()"><i class="fas fa-times"></i></button>
                    </div>
                    <div class="ava-sec-modal-list" id="avaSecModalList"></div>
                    <button type="button" class="ava-sec-modal-reset" onclick="avaSecResetAll()"><i class="fas fa-rotate-left"></i> بازگشت به حالت اول (نمایش همه)</button>
                </div>`;
            document.body.appendChild(modal);
            modal.addEventListener('click', function(e){ if (e.target === modal) avaCloseSecModal(); });
        }
        avaRenderSecList();
        modal.classList.add('show');
    };

    window.avaCloseSecModal = function(){
        const modal = document.getElementById('avaSecModal');
        if (modal) modal.classList.remove('show');
    };

    // لیست را دقیقاً به همان ترتیبی که در خودِ صفحه (از اول تا آخر داشبورد) ظاهر می‌شوند می‌سازد
    function avaRenderSecList(){
        const list = document.getElementById('avaSecModalList');
        if (!list) return;
        const sections = Array.from(document.querySelectorAll(SEC_SEL));
        list.innerHTML = sections.map(el => {
            const key = avaSecKey(el);
            const title = el.getAttribute('data-avasec-title') || key;
            const icon = el.getAttribute('data-avasec-icon') || 'fas fa-square';
            const isOn = !avaSecPrefs.hidden.includes(key);
            return `
                <div class="ava-sec-modal-row">
                    <span class="ava-sec-modal-row-title"><i class="${icon}"></i> ${title}</span>
                    <label class="ava-sec-switch">
                        <input type="checkbox" ${isOn ? 'checked' : ''} onchange="avaSecToggleHide('${key}', this.checked)">
                        <span class="ava-sec-switch-track"><span class="ava-sec-switch-thumb"></span></span>
                    </label>
                </div>`;
        }).join('');
    }

    function avaQueueSave(){
        clearTimeout(avaSaveTimer);
        avaSaveTimer = setTimeout(function(){
            fetch('dashboard.php?ava=sec_prefs_set', {
                method: 'POST', headers: {'Content-Type':'application/json'},
                body: JSON.stringify({ hidden: avaSecPrefs.hidden })
            }).catch(()=>{});
        }, 400);
    }

    window.avaSecToggleHide = function(key, isOn){
        const idx = avaSecPrefs.hidden.indexOf(key);
        if (isOn) { if (idx !== -1) avaSecPrefs.hidden.splice(idx, 1); }
        else { if (idx === -1) avaSecPrefs.hidden.push(key); }
        avaApplyHidden();
        avaQueueSave();
    };

    function avaApplyHidden(){
        document.querySelectorAll(SEC_SEL).forEach(el => {
            el.style.display = avaSecPrefs.hidden.includes(avaSecKey(el)) ? 'none' : '';
        });
        // اگر یکی از چهار کارت نوار «فعالیت و دسترسی سریع» مخفی/آشکار شد،
        // بقیه باید دوباره هم‌ارتفاع شوند
        if (typeof avaEqualizeQuadHeights === 'function') avaEqualizeQuadHeights();
    }

    window.avaSecResetAll = function(){
        if (!confirm('نمایش همه‌ی بخش‌های داشبورد بازگردانده شود؟')) return;
        fetch('dashboard.php?ava=sec_prefs_reset', { method: 'POST' })
            .then(() => location.reload())
            .catch(() => location.reload());
    };

    async function avaLoadSecPrefs(){
        try {
            const res = await fetch('dashboard.php?ava=sec_prefs_get');
            const data = await res.json();
            if (!data.success) return;
            avaSecPrefs = { hidden: data.hidden || [] };
            avaApplyHidden();
        } catch (e) {}
    }

    document.addEventListener('DOMContentLoaded', avaLoadSecPrefs);
})();
</script>
<style>
.ava-sec-modal-overlay{display:none;position:fixed;inset:0;z-index:99997;background:rgba(8,4,18,.75);backdrop-filter:blur(6px);align-items:flex-end;justify-content:center;}
.ava-sec-modal-overlay.show{display:flex;}
.ava-sec-modal-sheet{width:100%;max-width:480px;max-height:78vh;background:linear-gradient(165deg,#211037,#160c2c);border:1px solid rgba(139,92,246,.3);border-radius:22px 22px 0 0;padding:16px;display:flex;flex-direction:column;box-shadow:0 -10px 40px rgba(0,0,0,.5);}
.ava-sec-modal-head{display:flex;align-items:center;justify-content:space-between;color:#fff;font-weight:700;font-size:.92rem;margin-bottom:10px;}
.ava-sec-modal-head button{background:rgba(255,255,255,.08);border:none;color:#fff;width:30px;height:30px;border-radius:50%;cursor:pointer;}
.ava-sec-modal-list{overflow-y:auto;flex:1;display:flex;flex-direction:column;gap:6px;}
.ava-sec-modal-row{display:flex;align-items:center;justify-content:space-between;background:rgba(255,255,255,.04);border-radius:12px;padding:11px 14px;}
.ava-sec-modal-row-title{color:#e5e0f5;font-size:.82rem;display:flex;align-items:center;gap:8px;}
.ava-sec-modal-row-title i{color:#a78bfa;width:16px;text-align:center;}
.ava-sec-switch{position:relative;display:inline-block;width:42px;height:24px;flex-shrink:0;}
.ava-sec-switch input{opacity:0;width:0;height:0;position:absolute;}
.ava-sec-switch-track{position:absolute;inset:0;background:rgba(255,255,255,.15);border-radius:20px;transition:.2s;}
.ava-sec-switch-thumb{position:absolute;top:3px;right:3px;width:18px;height:18px;background:#fff;border-radius:50%;transition:.2s;}
.ava-sec-switch input:checked + .ava-sec-switch-track{background:linear-gradient(135deg,#8b5cf6,#ec4899);}
.ava-sec-switch input:checked + .ava-sec-switch-track .ava-sec-switch-thumb{transform:translateX(-18px);}
.ava-sec-modal-reset{margin-top:12px;border:none;border-radius:12px;padding:11px;background:rgba(255,255,255,.06);color:#e5e0f5;font-size:.78rem;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;}
</style>
<script>
document.addEventListener('DOMContentLoaded', function(){
    const first = document.querySelector('#avaTxCurTabs .tx-cur-tab.on');
    if (first){
        avaTxCur = first.dataset.cur || avaTxCur;
        const c = first.dataset.color || '#A855F7';
        first.style.background = 'linear-gradient(135deg,' + avaTxHexA(c, .95) + ',' + avaTxHexA(c, .55) + ')';
    }
    if (typeof Chart !== 'undefined') avaTxRender();
    else setTimeout(avaTxRender, 700);
});
// هنگام تغییر تم، نمودار را با رنگ‌های جدید بازسازی کن
try {
    new MutationObserver(function(){ if (avaTxChart || document.getElementById('avaTxChart')) avaTxRender(); })
        .observe(document.documentElement, { attributes:true, attributeFilter:['data-theme'] });
} catch(e){}

/* --- هشدارهای قیمت --- */
let avaAlertCurType = 'ad';
let avaAlertActiveTab = 'ad';
function avaOpenAlertForm(){
    // نوع پیش‌فرض فرم: قیمت آگهی
    avaAlertType('ad', document.querySelector('#avaAlertSheet .ava-inv-tab[data-atype="ad"]'));
    avaOpenSheet('avaAlertSheet');
}

/* --- (آپدیت ۱) سوییچ تب حساب‌های در انتظار پرداخت / فیش‌های دریافتی --- */
function avaRecAccTab(which, el){
    const pend = document.getElementById('avaRecPanePending');
    const recp = document.getElementById('avaRecPaneReceipts');
    if (pend) pend.style.display = (which === 'pending') ? 'block' : 'none';
    if (recp) recp.style.display = (which === 'receipts') ? 'block' : 'none';
    document.querySelectorAll('#avaRecAccCard .ava-inv-tab[data-rectab]').forEach(t=>t.classList.remove('active'));
    if (el) el.classList.add('active');
    // دکمه‌های اقدام مخصوص هر تب را نشان/پنهان کن
    document.querySelectorAll('#avaRecAccCard .ava-recacc-act').forEach(a=>{
        a.style.display = (a.dataset.for === which) ? 'inline-flex' : 'none';
    });
}
// فعال‌سازی خودکار تب پیش‌فرض هنگام بارگذاری (تبی که آیتم جدید دارد)
(function(){
    function initRecAcc(){
        const card = document.getElementById('avaRecAccCard');
        if (!card) return;
        const def = card.getAttribute('data-default') || 'pending';
        const btn = card.querySelector('.ava-inv-tab[data-rectab="'+def+'"]');
        avaRecAccTab(def, btn);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initRecAcc);
    else initRecAcc();
})();

/* --- (آپدیت جدید) سوییچ تب سفارشات فعال / حواله‌های فعال --- */
function avaOrdTrTab(which, el){
    const ord = document.getElementById('avaOrdPaneOrders');
    const tr  = document.getElementById('avaOrdPaneTransfers');
    if (ord) ord.style.display = (which === 'orders') ? 'block' : 'none';
    if (tr)  tr.style.display  = (which === 'transfers') ? 'block' : 'none';
    document.querySelectorAll('#avaOrdTrCard .ava-inv-tab[data-ordtab]').forEach(t=>t.classList.remove('active'));
    if (el) el.classList.add('active');
    document.querySelectorAll('#avaOrdTrCard .ava-ordtr-act').forEach(a=>{
        a.style.display = (a.dataset.for === which) ? 'inline-flex' : 'none';
    });
    // تعویض تب می‌تواند ارتفاع این کارت را عوض کند (سفارشات vs حواله‌ها تعداد
    // ردیف متفاوتی دارند) — نوار «فعالیت و دسترسی سریع» را دوباره هم‌ارتفاع کن
    if (typeof avaEqualizeQuadHeights === 'function') avaEqualizeQuadHeights();
}

/* --- (آپدیت جدید) سوییچ تب اخبار بازار جهانی / اخبار اقتصادی ایران --- */
function avaNewsMegaTab(which, el){
    const mk = document.getElementById('avaNewsPaneMarket');
    const ir = document.getElementById('avaNewsPaneIran');
    if (mk) mk.style.display = (which === 'market') ? 'block' : 'none';
    if (ir) ir.style.display = (which === 'iran') ? 'block' : 'none';
    document.querySelectorAll('#avaNewsMegaCard .ava-inv-tab[data-newstab]').forEach(t=>t.classList.remove('active'));
    if (el) el.classList.add('active');
    const allBtn = document.getElementById('avaNewsAllBtn');
    if (allBtn) allBtn.style.display = (which === 'market' && window.__avaNewsAllItems && window.__avaNewsAllItems.length > 2) ? '' : 'none';
    if (which === 'iran' && typeof avaIrnLoad === 'function') avaIrnLoad();
}

let __avaCoinListLoaded = false;
function avaAlertType(which, el){
    avaAlertCurType = (which === 'rate') ? 'rate' : (which === 'crypto' ? 'crypto' : 'ad');
    document.querySelectorAll('#avaAlertSheet .ava-inv-tab[data-atype]').forEach(t=>t.classList.remove('active'));
    if (el) el.classList.add('active');
    const hint = document.getElementById('avaAlertTypeHint');
    if (hint) hint.textContent =
        avaAlertCurType === 'crypto' ? 'هشدار بر اساس قیمت لحظه‌ای ارز دیجیتال (دلار) بررسی می‌شود.'
      : avaAlertCurType === 'rate'   ? 'هشدار بر اساس نرخ لحظه‌ای ارز (از الان‌چند) بررسی می‌شود.'
      :                                'هشدار بر اساس قیمت آگهی‌های فعال بازار بررسی می‌شود.';
    // نمایش انتخاب‌گر مناسب
    const fiatW = document.getElementById('avaAlertFiatWrap');
    const cryW  = document.getElementById('avaAlertCryptoWrap');
    const isCrypto = avaAlertCurType === 'crypto';
    if (fiatW) fiatW.style.display = isCrypto ? 'none' : 'block';
    if (cryW)  cryW.style.display  = isCrypto ? 'block' : 'none';
    // برچسب قیمت هدف (تومان یا دلار)
    const lbl = document.getElementById('avaAlertPriceLbl');
    if (lbl) lbl.textContent = isCrypto ? 'قیمت هدف (دلار)' : 'قیمت هدف (تومان)';
    const pr = document.getElementById('avaAlertPrice');
    if (pr) pr.placeholder = isCrypto ? '65000' : '90000';
    // فهرست ارزهای دیجیتال را یک‌بار از سرویس بگیر
    if (isCrypto && !__avaCoinListLoaded) avaLoadCoinList();
}
async function avaLoadCoinList(){
    const sel = document.getElementById('avaAlertCoin');
    if (!sel) return;
    try {
        const res = await fetch('/ledor/api/crypto_market_api.php', { cache:'no-store' });
        const d = await res.json();
        const coins = [].concat(d.market || [], d.gainers || []);
        // حذف تکراری‌ها
        const seen = {}; const uniq = [];
        coins.forEach(c=>{ if(c && c.id && !seen[c.id]){ seen[c.id]=1; uniq.push(c); } });
        if (!uniq.length){ sel.innerHTML = '<option value="">فهرست در دسترس نیست</option>'; return; }
        sel.innerHTML = uniq.map(c=>`<option value="${c.id}" data-name="${(c.name||'').replace(/"/g,'')}">${c.name} (${(c.symbol||'').toUpperCase()})</option>`).join('');
        __avaCoinListLoaded = true;
    } catch(e){
        sel.innerHTML = '<option value="">خطا در دریافت فهرست</option>';
    }
}
async function avaAlertSave(){
    const direction = document.getElementById('avaAlertDir').value;
    const target_price = parseFloat(document.getElementById('avaAlertPrice').value || '0');
    const notify_telegram = document.getElementById('avaChanTg').checked ? 1 : 0;
    const notify_email = document.getElementById('avaChanEmail').checked ? 1 : 0;
    const notify_toast = document.getElementById('avaChanToast').checked ? 1 : 0;
    const check_interval = parseInt(document.getElementById('avaAlertInterval').value || '60', 10);
    let currency, coin_name = '';
    if (avaAlertCurType === 'crypto') {
        const sel = document.getElementById('avaAlertCoin');
        currency = sel ? sel.value : '';
        const opt = sel && sel.selectedOptions[0];
        coin_name = opt ? (opt.dataset.name || opt.textContent) : '';
        if (!currency) { avaToast('یک ارز دیجیتال انتخاب کنید'); return; }
    } else {
        currency = document.getElementById('avaAlertCur').value;
    }
    if (!currency || !(target_price > 0)) { avaToast('قیمت هدف را وارد کنید'); return; }
    if (!notify_telegram && !notify_email && !notify_toast) { avaToast('حداقل یک روش اطلاع‌رسانی را انتخاب کنید'); return; }
    try {
        const r = await fetch('dashboard.php?ava=alert_add', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ currency, coin_name, direction, target_price, alert_type: avaAlertCurType, notify_telegram, notify_email, notify_toast, check_interval })
        });
        const d = await r.json();
        if (d.success) location.reload(); else avaToast(d.error || 'خطا در ثبت هشدار');
    } catch(e){ avaToast('خطای شبکه'); }
}
async function avaAlertToggle(id, on){
    try {
        await fetch('dashboard.php?ava=alert_toggle', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ id, is_active: on ? 1 : 0 })
        });
    } catch(e){}
}
async function avaAlertDelete(id, el){
    if (!confirm('این هشدار حذف شود؟')) return;
    try {
        const r = await fetch('dashboard.php?ava=alert_delete', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ id })
        });
        const d = await r.json();
        if (d.success){
            const row = el.closest('.ava-alert-row');
            const pane = row ? row.closest('.ava-alert-pane') : null;
            if (row) row.remove();
            if (pane && !pane.querySelector('.ava-alert-row')){
                pane.innerHTML = '<div class="ava-empty">هشداری در این بخش ثبت نشده است</div>';
            }
        }
    } catch(e){}
}

/* --- بررسی هشدارها در برابر آگهی‌های تبادل ارزی + Toast --- */
// (اصلاح) دریافت آنیِ پول: از هر بار بعد از بارگذاری صفحه، هر اعلان تازه‌ی
// «دریافت پول» (که با ارسال از طریق QR یا هر انتقال داخلی دیگری ایجاد
// می‌شود) در همین چرخه‌ی ۱۵ثانیه‌ای شناسایی و به کاربر نشان داده می‌شود —
// بدون نیاز به رفرش صفحه، هم موجودی کارت بالای داشبورد آپدیت می‌شود هم توست
// نمایش داده می‌شود.
let avaLastMoneyCheck = new Date().toISOString();
async function avaCheckAlerts(){
    try {
        const r = await fetch('dashboard.php?ava=alerts_check&since=' + encodeURIComponent(avaLastMoneyCheck), { cache:'no-store' });
        const d = await r.json();
        if (d && d.success && d.triggered && d.triggered.length){
            d.triggered.forEach(t=>{
                const dir = t.direction === 'below' ? 'پایین‌تر از' : 'بالاتر از';
                if (t.alert_type === 'crypto') {
                    const nm = t.coin_name || t.currency;
                    const p = avaFa(new Intl.NumberFormat('en-US',{maximumFractionDigits:4}).format(t.target_price));
                    avaToast(`قیمت ${nm} ${dir} $${p} رسید`);
                } else {
                    avaToast(`نرخ ${t.currency} ${dir} ${avaFa(new Intl.NumberFormat('en-US').format(t.target_price))} تومان در بازار ثبت شد`, 'arad.php');
                }
            });
        }
        if (d && d.success && d.moneyReceived && d.moneyReceived.length){
            d.moneyReceived.forEach(m=>{
                const amt = new Intl.NumberFormat('en-US', { maximumFractionDigits: 4 }).format(m.amount);
                avaToast(`💰 ${avaFa(amt)} ${m.currency} از ${m.from_name} دریافت کردید`, 'transactions.php');
                avaApplyLiveBalance(m.currency, m.new_balance);
            });
            // نوتیفیکیشن‌های داخل‌اپ تازه رسیده‌اند — نشان دادگر تعداد نخوانده را هم بلافاصله به‌روز کن
            if (window.notificationManager && typeof window.notificationManager.updateUnreadCount === 'function') {
                try { window.notificationManager.updateUnreadCount(); } catch(e){}
            }
        }
        if (d && d.serverNow) avaLastMoneyCheck = d.serverNow.replace(' ', 'T');
    } catch(e){}
}

/* به‌روزرسانی زنده‌ی کارت موجودی (بدون رفرش صفحه) وقتی پول جدید می‌رسد */
function avaApplyLiveBalance(currency, newBalance){
    if (newBalance === null || newBalance === undefined) return;
    const data = window.AVA_HERO || [];
    let idx = -1;
    for (let i = 0; i < data.length; i++){ if (data[i].code === currency){ idx = i; break; } }
    if (idx === -1) return;
    data[idx].bal = newBalance;
    if (typeof avaHeroIdx !== 'undefined' && avaHeroIdx === idx && typeof avaHeroPaint === 'function'){
        avaHeroPaint(idx, true);
    }
}
/* تابع واحد نمایش پیام.
   پارامتر دوم دو حالت دارد (چون در کد از هر دو شکل استفاده شده است):
     - رشته  → آدرس مقصد؛ با کلیک روی پیام به آن صفحه می‌رود
     - true  → پیام خطا (قرمز)
   قبلاً دو نسخه‌ی جداگانه از این تابع با امضای متفاوت تعریف شده بود و
   نسخه‌ی دوم نسخه‌ی اول را بازنویسی می‌کرد؛ در نتیجه فراخوانی‌هایی که
   لینک پاس می‌دادند، لینک را به‌عنوان «خطا» تفسیر می‌کردند و به‌جای پیام
   عادیِ قابل‌کلیک، یک پیام خطای قرمزِ بی‌اثر نشان داده می‌شد. */
function avaToast(text, opt){
    const isErr = (opt === true);
    const link  = (typeof opt === 'string' && opt) ? opt : null;

    let host = document.getElementById('avaToastHost');
    if (!host){ host = document.createElement('div'); host.id='avaToastHost'; host.className='ava-toast-host'; document.body.appendChild(host); }
    const el = document.createElement('div');
    el.className = 'ava-toast' + (isErr ? ' ava-toast-err' : '');
    el.innerHTML = `<i class="fas ${isErr ? 'fa-circle-exclamation' : 'fa-bell'}"></i><span>${avaEsc(text)}</span>`;
    if (link) { el.style.cursor='pointer'; el.onclick = ()=>{ window.location.href = link; }; }
    host.appendChild(el);
    setTimeout(()=>{ el.classList.add('show'); }, 30);
    setTimeout(()=>{ el.classList.remove('show'); setTimeout(()=>el.remove(), 300); }, isErr ? 4000 : 6000);
}

/* --- مدال پیشنهاد قیمت روی آگهی‌های فعال --- */
let avaOfferAd = { id:0, currency:'', amount:0, price:0, type:'sell' };
function avaOpenOfferModal(id, currency, amount, price, type, seller){
    avaOfferAd = { id:id, currency:currency, amount:amount, price:price, type:type };
    const info = document.getElementById('avaOfferAdInfo');
    const typeFa = (type === 'buy') ? 'خرید' : 'فروش';
    if (info){
        info.innerHTML = `
            <div class="ava-offer-adline"><span>آگهی</span><b>${typeFa} ${avaEsc(currency)}</b></div>
            <div class="ava-offer-adline"><span>صاحب آگهی</span><b>${avaEsc(seller || 'کاربر')}</b></div>
            <div class="ava-offer-adline"><span>مقدار آگهی</span><b>${avaFa(new Intl.NumberFormat('en-US').format(amount))} ${avaEsc(currency)}</b></div>
            <div class="ava-offer-adline"><span>قیمت آگهی</span><b>${avaFa(new Intl.NumberFormat('en-US').format(price))} تومان</b></div>`;
    }
    const curLbl = document.getElementById('avaOfferCurLbl'); if (curLbl) curLbl.textContent = currency;
    const amtEl = document.getElementById('avaOfferAmount');
    const prcEl = document.getElementById('avaOfferPrice');
    if (amtEl) amtEl.value = '';
    if (prcEl) prcEl.value = price || '';
    const msgEl = document.getElementById('avaOfferMsg'); if (msgEl) msgEl.value = '';
    avaOfferRecalc();
    if (amtEl) amtEl.oninput = avaOfferRecalc;
    if (prcEl) prcEl.oninput = avaOfferRecalc;
    avaOpenSheet('avaOfferSheet');
}
function avaOfferRecalc(){
    const amt = parseFloat((document.getElementById('avaOfferAmount')||{}).value || '0');
    const prc = parseFloat((document.getElementById('avaOfferPrice')||{}).value || '0');
    const box = document.getElementById('avaOfferTotal');
    if (!box) return;
    if (amt > 0 && prc > 0){
        box.innerHTML = 'مبلغ کل: <b>' + avaFa(new Intl.NumberFormat('en-US').format(Math.round(amt*prc))) + ' تومان</b>';
    } else {
        box.innerHTML = '';
    }
}
async function avaSubmitOffer(){
    const amt = parseFloat((document.getElementById('avaOfferAmount')||{}).value || '0');
    const prc = parseFloat((document.getElementById('avaOfferPrice')||{}).value || '0');
    const msg = ((document.getElementById('avaOfferMsg')||{}).value || '').trim();
    if (!(amt > 0)){ avaToast('مقدار درخواستی را وارد کنید'); return; }
    if (!(prc > 0)){ avaToast('قیمت پیشنهادی را وارد کنید'); return; }
    try {
        const r = await fetch('api/offer_api.php?action=create', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ ad_id: avaOfferAd.id, requested_amount: amt, offered_price: prc, message: msg })
        });
        const d = await r.json();
        if (d.success){ avaToast(d.message || 'پیشنهاد ارسال شد'); avaCloseSheet('avaOfferSheet'); }
        else avaToast(d.message || 'خطا در ارسال پیشنهاد');
    } catch(e){ avaToast('خطای شبکه'); }
}

/* --- فیش‌های دریافتی: علامت‌گذاری همه به‌عنوان خوانده‌شده --- */
async function avaSeenReceipts(){
    try { await fetch('dashboard.php?ava=receipts_seen', { method:'POST' }); } catch(e){}
    // نشان NEW روی تب فیش‌ها خاموش شود
    const recTabBtn = document.querySelector('.ava-inv-tab[data-rectab="receipts"] .ava-new-badge');
    if (recTabBtn) recTabBtn.classList.remove('on');
    // کل لیست کارت پاک شود؛ همه‌ی فیش‌ها همچنان در آرشیو در دسترس‌اند
    const list = document.querySelector('#avaRecPaneReceipts .ava-receipts');
    if (list){
        list.outerHTML = '<div class="ava-empty">فیش خوانده‌نشده‌ای ندارید — برای مشاهده‌ی فیش‌ها روی «آرشیو» بزنید</div>';
    }
    avaToast('همه‌ی فیش‌ها خوانده شد و به آرشیو منتقل شدند');
}

/* --- مدال‌های تمام‌صفحه --- */
function avaOpenFsModal(id){ const el=document.getElementById(id); if(el){ el.classList.add('open'); document.body.style.overflow='hidden'; try{ history.pushState({avaLayer:true}, ''); }catch(e){} } }
/* ---------------------------------------------------------------------------
   محافظ سراسری برای بستن مودال‌های تمام‌صفحه.
   دکمه‌ی «خروج» این مودال‌ها با onclick اینلاین کار می‌کند؛ اگر به هر دلیلی
   (مثلاً وقتی مودال از مسیر دیگری مثل بنر بالای صفحه باز شده و روی عنصر
   کلیک‌شده لایه‌ای افتاده) آن onclick اجرا نشود، کاربر داخل مودال گیر می‌کند.
   این هندلر واگذارشده تضمین می‌کند دکمه‌ی خروج همیشه کار کند، و کلیک روی
   پس‌زمینه و کلید Escape هم مودال را می‌بندند.
   --------------------------------------------------------------------------- */
document.addEventListener('click', function (e) {
    var closeBtn = e.target.closest ? e.target.closest('.ava-fs-close') : null;
    if (closeBtn) {
        var m = closeBtn.closest('.ava-fs-modal');
        if (m && m.id) { e.preventDefault(); e.stopPropagation(); if (e.stopImmediatePropagation) e.stopImmediatePropagation(); avaCloseFsModal(m.id); }
        return;
    }
    // کلیک روی خودِ پس‌زمینه‌ی مودال (نه محتوای داخلش)
    if (e.target.classList && e.target.classList.contains('ava-fs-modal') && e.target.id) {
        avaCloseFsModal(e.target.id);
    }
}, true);

document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var open = document.querySelector('.ava-fs-modal.open');
    if (open && open.id) avaCloseFsModal(open.id);
});

/* پشتیبان لمسی: روی موبایل اگر به هر دلیلی رویداد click تولید نشود
   (مثلاً وقتی انگشت هنگام زدن دکمه کمی می‌لغزد)، بستن مدال با touchend
   هم انجام می‌شود تا کاربر هیچ‌وقت داخل مدال گیر نکند. */
document.addEventListener('touchend', function (e) {
    var t = e.changedTouches && e.changedTouches[0];
    if (!t) return;
    var el = document.elementFromPoint(t.clientX, t.clientY);
    var closeBtn = (el && el.closest) ? el.closest('.ava-fs-close') : null;
    if (!closeBtn) return;
    var m = closeBtn.closest('.ava-fs-modal');
    if (m && m.id && m.classList.contains('open')) avaCloseFsModal(m.id);
}, { passive: true });

// (اصلاح) قفل اسکرول body را فقط وقتی باز کن که واقعاً هیچ مودال/شیت
// تمام‌صفحه‌ی دیگری باز نمانده باشد — قبلاً همیشه overflow='' می‌شد، یعنی
// اگر یک مودال روی مودال دیگر باز شده بود، با بستن لایه‌ی رویی، قفل اسکرول
// پس‌زمینه هم زودتر از موعد باز می‌شد.
function avaAnyFsModalStillOpen(){
    return !!(document.querySelector('.ava-fs-modal.open, .ava-sheet.open'));
}
function avaCloseFsModal(id){
    // (رفع باگ) این تابع می‌تواند برای همان مودال، در همان کلیک/لمس، بیش از
    // یک‌بار صدا زده شود — چون هم onclick اینلاین دکمه‌ی «خروج» و هم شنونده‌ی
    // سراسری capture-phase پایین همین فایل، هر دو روی همان دکمه‌ی .ava-fs-close
    // تطبیق پیدا می‌کنند. اگر این‌جا جلوگیری نشود، avaConsumeLayerHistoryState
    // هم دوبار history.back() می‌زند و کاربر به‌جای یک لایه، دو لایه به عقب
    // برمی‌گردد — دقیقاً همان باگی که وقتی مدال «عمق بازار» (آگهی‌های یک قیمت)
    // روی مدال «نمودار پیش‌بینی» باز بود و کاربر «خروج» می‌زد، به‌جای برگشتن
    // به مدال نمودار، مستقیم به داشبورد برمی‌گشت.
    const now = Date.now();
    if (!window.__avaLastCloseAt) window.__avaLastCloseAt = {};
    if (window.__avaLastCloseAt[id] && (now - window.__avaLastCloseAt[id]) < 400) return;
    window.__avaLastCloseAt[id] = now;

    const el=document.getElementById(id);
    if(el){
        el.classList.remove('open');
        if (!avaAnyFsModalStillOpen()) document.body.style.overflow='';
    }
    // (رفع باگ) روی بعضی مرورگرهای موبایل، بعد از بسته‌شدن مودال یک کلیک/تاچ
    // «شبح» با کمی تأخیر روی همون مختصات صفحه ثبت می‌شود — اگر دکمه‌ی بستن
    // دقیقاً بالای یکی از آیتم‌های منوی پایین صفحه باشد، آن کلیک ناخواسته به
    // منو می‌خورد. با غیرفعال‌کردن موقت pointer-events روی خودِ منو، این
    // کلیکِ اضافه خنثی می‌شود بدون این‌که تعامل واقعی کاربر مختل شود.
    document.querySelectorAll('.bottom-nav').forEach(function(nav){
        nav.style.pointerEvents = 'none';
        setTimeout(function(){ nav.style.pointerEvents = ''; }, 450);
    });
    if(id==='avaTransferModal'){ const f=document.getElementById('avaTransferFrame'); if(f) f.src='about:blank'; }
    if(id==='avaAdModal'){ const f=document.getElementById('avaAdFrame'); if(f) f.src='about:blank'; }
    if(id==='avaProfileModal'){ const f=document.getElementById('avaProfileFrame'); if(f) f.src='about:blank'; }
    if(id==='avaPendAccModal'){
        avaCurPendAcc=null;
        avaPendAccOpenedDirect = false;
        const fi=document.getElementById('avaPendAccFile'); if(fi) fi.value='';
        const pv=document.getElementById('avaPendAccPreview'); if(pv) pv.innerHTML='';
        // (اصلاح قطعی) صرف‌نظر از این‌که این مودال چطور باز شده، بستنش دیگر
        // هرگز نباید مودال «حساب‌ها و فیش‌ها» را پشتش نمایان بگذارد — طبق
        // درخواست صریح و تکرارشده‌ی کاربر. قبلاً این فقط وقتی اتفاق می‌افتاد
        // که یک پرچم JS جداگانه (avaPendAccOpenedDirect) درست ست شده باشد؛
        // چون آن پرچم در فایل جداگانه‌ی assets/js/dashboard-enhance.js تنظیم
        // می‌شد (که برخلاف خودِ dashboard.php ممکن است در حافظه‌ی پنهانِ
        // service worker/مرورگر قدیمی بماند)، گاهی این شرط برقرار نمی‌شد و
        // مودال لیست پشت باقی می‌ماند. حالا بدون هیچ شرطی همیشه بسته می‌شود.
        const rec=document.getElementById('avaRecAccModal');
        if (rec) rec.classList.remove('open');
        if (!avaAnyFsModalStillOpen()) document.body.style.overflow='';
    }
    if(id==='avaCoinModal'){ if(typeof avaCoinChart!=='undefined' && avaCoinChart){ avaCoinChart.destroy(); avaCoinChart=null; } avaCoinCur=null; }
    avaConsumeLayerHistoryState();
}

/* ---------------------------------------------------------------------------
   (اصلاح) کشیدن انگشت از چپ به راست برای بازگشت — وقتی مودال/شیتی باز است —
   قبلاً کار نمی‌کرد (نه مودال بسته می‌شد، نه به داشبورد برمی‌گشت). علتش این
   بود که فقط با تشخیص دستیِ touchmove سعی می‌کردیم این ژست را بگیریم، در
   حالی که ژست لبه‌ای بازگشت در iOS/اندروید یک ژست سطحِ سیستم‌عامل/مرورگر است
   و می‌تواند مستقیماً navigation واقعی (رفتن به تاریخچه‌ی قبلی) را راه بیندازد،
   جدا از این‌که JS خودمان چه تشخیصی می‌دهد. راه‌حل قابل‌اعتماد: با باز شدن هر
   مودال/شیت یک state تاریخچه push می‌کنیم (بالا، در avaOpenFsModal/avaOpenSheet)؛
   حالا هر مسیری که باعث «بازگشت» شود — کشیدن انگشت از لبه، دکمه‌ی بازگشتِ
   اندروید، یا حتی دکمه‌ی back خودِ مرورگر — یک رویداد popstate می‌سازد که
   اینجا می‌گیریم و مودال/شیت باز را می‌بندیم، به‌جای اینکه اجازه بدهیم کاربر
   واقعاً از صفحه خارج شود.
   --------------------------------------------------------------------------- */
function avaConsumeLayerHistoryState(){
    // وقتی بستن مودال از طریق دکمه‌ی X/کلیک پس‌زمینه اتفاق می‌افتد (نه از طریق
    // popstate)، همان state ای که موقع باز شدن push کرده بودیم را هم مصرف
    // می‌کنیم تا تاریخچه تمیز بماند و فشردن دوباره‌ی back کاربر را به یک
    // صفحه‌ی قبلی نامرتبط نبرد.
    if (window.__avaHandlingPopstate) return; // از حلقه‌ی بی‌نهایت جلوگیری می‌کند
    if (history.state && history.state.avaLayer) {
        // (رفع باگ) این پرچم یعنی «popstate بعدی، خودمان با زدن دکمه‌ی بستن
        // ایجاد کردیم، نه یک ژست بازگشتِ واقعیِ کاربر». بدون این پرچم، وقتی
        // یک شیت/مودال روی مودال دیگری (مثلاً «تارگت‌ها» روی مودال اصلیِ
        // پیش‌بینی) باز بود و کاربر آن لایه‌ی رویی را می‌بست، popstateِ
        // ناشیِ از همین history.back() به‌اشتباه مودالِ زیرین را هم می‌بست —
        // چون شنونده‌ی popstate هر مودال/شیتِ بازِ دیگری را که پیدا می‌کرد،
        // می‌بست، بدون این‌که بداند این popstate نتیجه‌ی بستنِ دستیِ همان لایه
        // است و لایه‌ی زیرین اصلاً نباید دست بخورد.
        window.__avaExpectingPopstate = true;
        try { history.back(); } catch(e){ window.__avaExpectingPopstate = false; }
    }
}
window.addEventListener('popstate', function(){
    if (window.__avaExpectingPopstate) {
        // این popstate نتیجه‌ی history.back()ای است که خودمان بابت بستنِ
        // دستیِ یک لایه (بالا) صدا زدیم؛ آن لایه از قبل بسته شده — هیچ لایه‌ی
        // دیگری نباید بسته شود (وگرنه مودال زیرین هم می‌بندد).
        window.__avaExpectingPopstate = false;
        return;
    }
    var openModal = document.querySelector('.ava-fs-modal.open');
    var openSheet = document.querySelector('.ava-sheet.open');
    if (!openModal && !openSheet) return; // چیزی برای بستن نبود، بگذار بازگشتِ عادی انجام شود
    window.__avaHandlingPopstate = true;
    try {
        if (openModal && openModal.id) avaCloseFsModal(openModal.id);
        else if (openSheet && openSheet.id) avaCloseSheet(openSheet.id);
    } finally {
        window.__avaHandlingPopstate = false;
    }
});

/* --- مدال ویرایش پروفایل / احراز هویت (داخل iframe) --- */
function avaOpenProfileModal(section){
    section = (section === 'kyc' || section === 'notifications') ? section : 'edit';
    const f = document.getElementById('avaProfileFrame');
    const t = document.getElementById('avaProfileModalTitle');
    if (t) t.innerHTML = (section === 'kyc')
        ? '<i class="fas fa-id-card"></i> احراز هویت'
        : (section === 'notifications')
        ? '<i class="fas fa-bell"></i> تنظیمات اعلان‌ها'
        : '<i class="fas fa-user-edit"></i> ویرایش پروفایل';
    if (f) f.src = 'profile.php?embed=1&section=' + section;
    // کشوی پروفایل را ببند و مدال را باز کن
    avaCloseProfile();
    avaOpenFsModal('avaProfileModal');
}

/* --- نمایش فیش به‌صورت تمام‌صفحه (پشتیبانی از چند فیش) --- */
function avaShowReceiptFile(url){
    const img = document.getElementById('avaReceiptImg');
    const dl  = document.getElementById('avaReceiptDownload');
    if (img) img.src = url;
    if (dl) dl.href = url;
    document.querySelectorAll('#avaReceiptThumbs .ava-rec-thumb').forEach(t=>{
        t.classList.toggle('active', t.dataset.url === url);
    });
}
function avaViewReceipt(files, title, el){
    // files می‌تواند یک رشته یا آرایه‌ای از URLها باشد
    if (typeof files === 'string') files = files ? [files] : [];
    if (!Array.isArray(files) || files.length === 0){ avaToast('فایل فیش موجود نیست'); return; }
    const tl = document.getElementById('avaReceiptTitle');
    if (tl) tl.textContent = 'فیش ' + (title || '') + (files.length > 1 ? ' ('+avaFa(files.length)+' مورد)' : '');
    // بندانگشتی‌ها
    const th = document.getElementById('avaReceiptThumbs');
    if (th){
        if (files.length > 1){
            th.style.display = 'flex';
            th.innerHTML = files.map((u,i)=>`<button class="ava-rec-thumb" data-url="${u}" onclick="avaShowReceiptFile('${u.replace(/'/g,"\\'")}')"><img src="${u}" alt="فیش ${i+1}" onerror="this.parentNode.innerHTML='<i class=\\'fas fa-file\\'></i>'"></button>`).join('');
        } else {
            th.style.display = 'none';
            th.innerHTML = '';
        }
    }
    avaShowReceiptFile(files[0]);
    avaOpenFsModal('avaReceiptModal');
    // این فیش را «دیده‌شده» علامت بزن
    if (el){
        el.classList.remove('ava-receipt-new');
        el.querySelectorAll('.ava-new-badge').forEach(b=>b.classList.remove('on'));
    }
    const remaining = document.querySelectorAll('#avaRecPaneReceipts .ava-receipt-new').length;
    if (remaining === 0){
        const recTabBtn = document.querySelector('.ava-inv-tab[data-rectab="receipts"] .ava-new-badge');
        if (recTabBtn) recTabBtn.classList.remove('on');
        try { fetch('dashboard.php?ava=receipts_seen', { method:'POST' }); } catch(e){}
    }
}

/* --- آرشیو فیش‌های دریافتی --- */
function avaOpenReceiptArchive(){
    const box = document.getElementById('avaReceiptArchiveList');
    const all = window.__avaReceiptsAll || [];
    if (box){
        if (!all.length){
            box.innerHTML = '<div class="ava-empty">فیشی در آرشیو نیست</div>';
        } else {
            box.innerHTML = all.map(rc=>{
                const cnt = (rc.files||[]).length;
                const filesAttr = JSON.stringify(rc.files||[]).replace(/"/g,'&quot;');
                const badge = cnt>1 ? `<span class="ava-rec-count">${avaFa(cnt)} فیش</span>` : '';
                return `<div class="ava-arch-item" onclick='avaViewReceipt(${JSON.stringify(rc.files||[])}, ${JSON.stringify(rc.type||"")})'>
                    <div class="ava-list-ic" style="background:${rc.color}22;color:${rc.color}"><i class="fas ${rc.icon}"></i></div>
                    <div class="ava-list-body">
                        <div class="ava-list-t">${avaEsc(rc.type||"")} ${badge}</div>
                        <div class="ava-list-s">${avaEsc(rc.ago||"")}</div>
                    </div>
                    <i class="fas fa-expand" style="color:var(--ava-mut);font-size:.7rem"></i>
                </div>`;
            }).join('');
        }
    }
    avaOpenFsModal('avaReceiptArchiveModal');
}


/* --- مدال ثبت آگهی جدید (درجا) --- */
function avaOpenAdModal(){
    const f = document.getElementById('avaAdFrame');
    if (f) f.src = 'arad.php?new=1&embed=1';
    avaOpenFsModal('avaAdModal');
}

/* --- مدال ارسال حواله --- */
function avaOpenTransferModal(){
    const f = document.getElementById('avaTransferFrame');
    if (f) f.src = 'money_transfer.php?embed=1&new=1';
    avaOpenFsModal('avaTransferModal');
}

/* (اصلاح ۴) باز کردن مدال حواله یا تسویه از عملیات سریع */
/* ==================== معرفی حساب (ذی‌نفعان تسویه) ==================== */
let avaBenCur = null;
window.AVA_APP_LOGO = <?php echo json_encode($appLogo['url'] ?? 'AVAPAY.PNG', JSON_UNESCAPED_SLASHES); ?>;

function avaBenOpenAdd(){
    ['benName','benCard','benIban','benBank','benNote'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
    document.getElementById('benEditId').value = '';
    const t = document.getElementById('benSheetTitle'); if (t) t.innerHTML = '<i class="fas fa-user-plus"></i> معرفی حساب جدید';
    const b = document.getElementById('benSaveBtn'); if (b) b.innerHTML = '<i class="fas fa-check"></i> ذخیره حساب';
    const err = document.getElementById('benErr'); if (err) err.style.display = 'none';
    avaOpenSheet('avaBenAddSheet');
}

function avaBenOpenEdit(){
    if (!avaBenCur) return;
    document.getElementById('benEditId').value = avaBenCur.id;
    document.getElementById('benName').value = avaBenCur.name || '';
    document.getElementById('benCard').value = avaBenCur.card || '';
    document.getElementById('benIban').value = avaBenCur.iban || '';
    document.getElementById('benBank').value = avaBenCur.bank || '';
    document.getElementById('benNote').value = avaBenCur.note || '';
    const t = document.getElementById('benSheetTitle'); if (t) t.innerHTML = '<i class="fas fa-pen"></i> ویرایش حساب';
    const b = document.getElementById('benSaveBtn'); if (b) b.innerHTML = '<i class="fas fa-check"></i> ذخیره تغییرات';
    const err = document.getElementById('benErr'); if (err) err.style.display = 'none';
    avaCloseSheet('avaBenViewSheet');
    avaOpenSheet('avaBenAddSheet');
}

async function avaBenSave(){
    const err = document.getElementById('benErr');
    const btn = document.getElementById('benSaveBtn');
    const val = id => (document.getElementById(id)?.value || '').trim();
    const showErr = m => { if (err){ err.textContent = m; err.style.display = 'block'; } };
    err.style.display = 'none';

    const editId = val('benEditId');
    const name = val('benName');
    const card = val('benCard');            // آزاد: هر تعداد رقم/حرف
    const iban = val('benIban');            // آزاد: هر فرمت شبا/IBAN
    if (!name) return showErr('نام گیرنده را وارد کنید');
    if (!card && !iban) return showErr('حداقل یکی از شماره کارت یا شبا لازم است');

    btn.disabled = true;
    try {
        const action = editId ? 'ben_update' : 'ben_add';
        const body = { full_name: name, card_number: card, iban: iban, bank_name: val('benBank'), note: val('benNote') };
        if (editId) body.id = editId;
        const r = await fetch('dashboard.php?ava=' + action, {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(body)
        });
        const d = await r.json();
        if (d.success) {
            // مودال «معرفی حساب» بعد از رفرش کامل صفحه دوباره باز شود تا کاربر
            // بلافاصله حساب جدید/ویرایش‌شده را در تب ۱ ببیند
            sessionStorage.setItem('avaReopenBeneModal', '1');
            location.reload();
        }
        else showErr(d.error || 'خطا در ذخیره');
    } catch(e){ showErr('خطای ارتباط با سرور'); }
    btn.disabled = false;
}

function avaBenOpen(b){
    avaBenCur = b;
    (function(){
        const av = document.getElementById('benVAvatar');
        // پس‌زمینه‌ی رنگی حذف شد؛ لوگوی سکه‌ای AVA PAY کل دایره را پر می‌کند
        if (av) av.style.background = '#12061f';
    })();
    document.getElementById('benVName').textContent = b.name || '—';
    document.getElementById('benVBank').textContent = b.bank || '';
    const fmt = c => (c || '').replace(/(\d{4})(?=\d)/g, '$1-');
    const rows = [];
    if (b.card) rows.push(['شماره کارت', fmt(b.card)]);
    if (b.iban) rows.push(['شبا', b.iban]);
    if (b.note) rows.push(['توضیحات', b.note]);
    document.getElementById('benVRows').innerHTML = rows.map(r =>
        '<div class="ava-ben-row"><span>' + r[0] + '</span><b dir="ltr">' + avaEsc(r[1]) + '</b></div>').join('') || '';
    avaOpenSheet('avaBenViewSheet');
    // (۱ب) نمودار ارزهای منتقل‌شده به این حساب در یک سال گذشته
    // نکته: بررسی typeof هم اضافه شده — اگر به هر دلیلی نسخه‌ی قدیمی/ناقص
    // dashboard-enhance.js لود شده باشد (کش مرورگر یا آپلود نشدن فایل جدید)،
    // به‌جای کرش کردن با خطای «is not a function»، پیام روشنی داخل خودِ
    // باکس نمودار نشان داده می‌شود تا مشکل فوراً قابل تشخیص باشد.
    const benChartHost = document.getElementById('avaBenChart');
    if (window.avaBenChart && typeof window.avaBenChart.open === 'function' && b.id) {
        window.avaBenChart.open(b.id);
    } else if (benChartHost) {
        benChartHost.innerHTML = '<div class="ava-benchart-empty"><i class="fas fa-triangle-exclamation"></i>'
            + 'فایل نمودار به‌روز نشده — لطفاً assets/js/dashboard-enhance.js را دوباره آپلود و صفحه را رفرش کامل (Ctrl+F5) کنید</div>';
    }
}

function avaBenSettle(){
    if (!avaBenCur) return;
    avaCloseSheet('avaBenViewSheet');
    // فرم تسویه با مشخصات این حساب از پیش پر می‌شود
    const q = new URLSearchParams({
        embed: '1', new: '1',
        ben_name: avaBenCur.name || '', ben_bank: avaBenCur.bank || '',
        ben_iban: avaBenCur.iban || '', ben_card: avaBenCur.card || ''
    });
    const f = document.getElementById('avaTransferFrame');
    const t = document.getElementById('avaTransferModalTitle');
    if (t) t.innerHTML = '<i class="fas fa-hand-holding-dollar"></i> تسویه به ' + avaEsc(avaBenCur.name || '');
    if (f) f.src = 'includes/withdrawal_modal_system.php?' + q.toString();
    avaOpenFsModal('avaTransferModal');
}

function avaBenDeleteConfirm(){
    if (!avaBenCur) return;
    document.getElementById('avaBenDelName').textContent = avaBenCur.name || '';
    document.getElementById('avaBenDeleteModal').style.display = 'flex';
}

async function avaBenDelete(){
    if (!avaBenCur) return;
    try {
        await fetch('dashboard.php?ava=ben_delete', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ id: avaBenCur.id })
        });
        sessionStorage.setItem('avaReopenBeneModal', '1');
        location.reload();
    } catch(e){}
}

function avaBenFilter(){
    const q = (document.getElementById('avaBenSearch')?.value || '').trim().toLowerCase();
    let shown = 0;
    document.querySelectorAll('#avaBenGrid .ava-ben-item').forEach(el => {
        const hit = !q || (el.dataset.name || '').includes(q);
        el.style.display = hit ? '' : 'none';
        if (hit) shown++;
    });
    const nores = document.getElementById('avaBenNoResult');
    if (nores) nores.style.display = (q && shown === 0) ? '' : 'none';
}

/* ==================== مودال جامع «معرفی حساب» — سوییچ تب‌ها ==================== */
function avaBenMainTab(tab, el){
    document.querySelectorAll('#avaBeneMainModal .ava-inv-tab').forEach(b => b.classList.remove('active'));
    if (el) el.classList.add('active');
    const panes = { add: 'avaBmPaneAdd', stats: 'avaBmPaneStats', pdf: 'avaBmPanePdf' };
    Object.keys(panes).forEach(k => {
        const p = document.getElementById(panes[k]);
        if (p) p.style.display = (k === tab) ? '' : 'none';
    });
    if (tab === 'stats' && window.avaBenChart && typeof window.avaBenChart.openAll === 'function') {
        window.avaBenChart.openAll('avaBenStatsChart');
    }
}

// اگر بعد از ذخیره/ویرایش/حذف حساب صفحه رفرش شده، دوباره همان مودال را باز کن
document.addEventListener('DOMContentLoaded', function(){
    if (sessionStorage.getItem('avaReopenBeneModal') === '1') {
        sessionStorage.removeItem('avaReopenBeneModal');
        if (typeof avaOpenFsModal === 'function') avaOpenFsModal('avaBeneMainModal');
    }
});

/* ==================== تب ۳: دانلود PDF کلیه واریزی‌ها در بازه‌ی دلخواه ==================== */
let avaBenPdfBlob = null;

function avaBenRenderPdfTemplate(d){
    const tpl = document.getElementById('avaBenPdfTemplate');
    if (!tpl) return;
    const rowsHtml = (d.items || []).map(it =>
        '<tr style="border-bottom:1px solid #eee;">' +
        '<td style="padding:7px;">' + avaEsc(it.date) + '</td>' +
        '<td style="padding:7px;">' + avaEsc(it.type) + '</td>' +
        '<td style="padding:7px;">' + avaEsc(it.to) + '</td>' +
        '<td style="padding:7px;text-align:left;color:#6C40C5;font-weight:700;" dir="ltr">' + Number(it.amount).toLocaleString('en-US') + ' ' + avaEsc(it.currency) + '</td>' +
        '</tr>'
    ).join('') || '<tr><td colspan="4" style="padding:18px;text-align:center;color:#666;">در این بازه واریزی ثبت نشده است</td></tr>';

    const totalsHtml = (d.totals || []).map(t =>
        '<span style="display:inline-block;margin:4px 10px;font-weight:800;color:#6C40C5;" dir="ltr">' +
        Number(t.total).toLocaleString('en-US') + ' <span style="font-size:11px;color:#555;">' + avaEsc(t.currency) + '</span></span>'
    ).join('') || '<span style="color:#666;">—</span>';

    tpl.innerHTML =
        '<div style="padding:32px;color:#222;">' +
        '<div style="text-align:center;margin-bottom:26px;border-bottom:3px solid #6C40C5;padding-bottom:18px;">' +
            (window.AVA_APP_LOGO ? '<img src="' + window.AVA_APP_LOGO + '" style="width:54px;height:54px;border-radius:50%;object-fit:cover;margin-bottom:8px;" onerror="this.style.display=\'none\'">' : '') +
            '<h1 style="color:#6C40C5;font-size:22px;margin:0;">Ava Pay</h1>' +
            '<h2 style="color:#333;font-size:16px;margin:8px 0 4px;">صورت‌حساب واریزی‌های معرفی‌شده</h2>' +
            '<p style="color:#555;font-size:11px;">بازه: ' + avaEsc(d.from) + ' تا ' + avaEsc(d.to) + '</p>' +
        '</div>' +
        '<table style="width:100%;border-collapse:collapse;font-size:12px;margin-bottom:22px;">' +
            '<tr><td style="padding:8px;background:#f5f5f5;width:32%;"><strong>صاحب حساب:</strong></td><td style="padding:8px;">' + avaEsc(d.name) + '</td></tr>' +
            '<tr><td style="padding:8px;background:#f5f5f5;"><strong>شناسه کاربری:</strong></td><td style="padding:8px;" dir="ltr">' + avaEsc(d.account) + '</td></tr>' +
        '</table>' +
        '<div style="margin-bottom:22px;">' +
            '<h3 style="color:#333;font-size:14px;border-bottom:1px solid #ddd;padding-bottom:8px;">مجموع واریزی‌ها</h3>' +
            '<div style="padding:10px 0;">' + totalsHtml + '</div>' +
        '</div>' +
        '<div>' +
            '<h3 style="color:#333;font-size:14px;border-bottom:1px solid #ddd;padding-bottom:8px;">جزئیات واریزی‌ها</h3>' +
            '<table style="width:100%;border-collapse:collapse;font-size:11px;">' +
                '<thead><tr style="background:#6C40C5;color:#fff;"><th style="padding:8px;text-align:right;">تاریخ</th><th style="padding:8px;text-align:right;">نوع</th><th style="padding:8px;text-align:right;">به حساب</th><th style="padding:8px;text-align:left;">مبلغ</th></tr></thead>' +
                '<tbody>' + rowsHtml + '</tbody>' +
            '</table>' +
        '</div>' +
        '<div style="margin-top:30px;display:flex;align-items:center;justify-content:space-between;gap:20px;border-top:2px solid #6C40C5;padding-top:20px;">' +
            '<div style="flex:1;">' +
                '<div style="font-weight:800;color:#6C40C5;font-size:13px;margin-bottom:6px;">AvaPay را روی گوشی خود نصب کنید</div>' +
                '<div style="font-size:10px;color:#555;line-height:1.8;">تبادل ارز، حواله بین‌المللی و تسویه حساب سریع و امن — همه در یک اپلیکیشن.</div>' +
            '</div>' +
            '<img src="assets/images/install_qr.png" crossorigin="anonymous" style="width:76px;height:76px;flex:none;" onerror="this.style.display=\'none\'">' +
        '</div>' +
        '<div style="margin-top:18px;text-align:center;color:#777;font-size:9px;">این گزارش به‌صورت خودکار توسط سامانه AvaPay تولید شده است.</div>' +
        '</div>';
}

async function avaBenPdfGenerate(){
    const btn = document.getElementById('avaBenPdfGenBtn');
    const fromEl = document.getElementById('avaBenPdfFrom');
    const toEl = document.getElementById('avaBenPdfTo');
    if (!btn || !fromEl || !toEl) { console.error('avaBenPdfGenerate: عناصر لازم پیدا نشدند'); return; }
    const from = fromEl.value;
    const to = toEl.value;

    // اگر کتابخانه‌های PDF هنوز کامل بارگذاری نشده‌اند (defer)، به‌جای هنگ کردن، پیام روشن بده
    if (typeof html2canvas === 'undefined' || typeof window.jspdf === 'undefined' || !window.jspdf.jsPDF) {
        alert('کتابخانه‌ی ساخت PDF هنوز کاملاً بارگذاری نشده — چند ثانیه صبر کنید و دوباره امتحان کنید.');
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال آماده‌سازی...';

    // ضامن ایمنی: اگر به هر دلیل غیرمنتظره‌ای پایین گیر کرد، حداکثر بعد از ۲۵ ثانیه دکمه را آزاد کن
    // تا کاربر هرگز با یک دکمه‌ی برای همیشه قفل‌شده مواجه نشود.
    let watchdogFired = false;
    const watchdog = setTimeout(() => {
        watchdogFired = true;
        console.error('avaBenPdfGenerate: زمان زیادی طول کشید — دکمه بازنشانی شد');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-file-pdf"></i> ساخت PDF';
        alert('ساخت PDF بیش از حد طول کشید — دوباره تلاش کنید.');
    }, 25000);

    try {
        console.log('avaBenPdfGenerate: در حال دریافت اطلاعات از سرور…');
        const r = await fetch('dashboard.php?ava=ben_pdf_data', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ from: from, to: to })
        });
        if (!r.ok) throw new Error('پاسخ سرور نامعتبر بود (HTTP ' + r.status + ')');
        const d = await r.json();
        if (!d.success) throw new Error(d.error || 'خطا در دریافت اطلاعات');

        console.log('avaBenPdfGenerate: اطلاعات دریافت شد، در حال ساخت قالب…', d);
        avaBenRenderPdfTemplate(d);
        // یک لحظه صبر برای بارگذاری کامل تصاویر (لوگو + کیوآر) داخل قالب پنهان
        await new Promise(res => setTimeout(res, 350));

        const tpl = document.getElementById('avaBenPdfTemplate');
        if (!tpl) throw new Error('قالب PDF پیدا نشد');

        console.log('avaBenPdfGenerate: در حال رندر تصویر (html2canvas)…');
        // scale پایین‌تر + خروجی JPEG فشرده به‌جای PNG بدون فشرده‌سازی — تفاوت حجم
        // برای یک سند متن‌محور مثل این، معمولاً ۱۰ برابر یا بیشتر است.
        const canvas = await html2canvas(tpl, { scale: 1.5, useCORS: true, allowTaint: true, logging: false, backgroundColor: '#ffffff' });
        const imgData = canvas.toDataURL('image/jpeg', 0.82);

        console.log('avaBenPdfGenerate: در حال ساخت فایل PDF (jsPDF)…');
        const { jsPDF } = window.jspdf;
        const pdf = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4', compress: true });
        const imgWidth = 190;
        const imgHeight = (canvas.height * imgWidth) / canvas.width;
        const pageHeight = 277;
        let heightLeft = imgHeight;
        let position = 10;
        pdf.addImage(imgData, 'JPEG', 10, position, imgWidth, imgHeight);
        heightLeft -= pageHeight;
        while (heightLeft > 0) {
            position = heightLeft - imgHeight + 10;
            pdf.addPage();
            pdf.addImage(imgData, 'JPEG', 10, position, imgWidth, imgHeight);
            heightLeft -= pageHeight;
        }

        avaBenPdfBlob = pdf.output('blob');
        console.log('avaBenPdfGenerate: حجم PDF نهایی: ' + Math.round(avaBenPdfBlob.size / 1024) + ' KB');
        const url = URL.createObjectURL(avaBenPdfBlob);
        const frame = document.getElementById('avaBenPdfFrame');
        const dl = document.getElementById('avaBenPdfDownloadLink');
        if (frame) frame.src = url;
        if (dl) dl.href = url;
        const resultBox = document.getElementById('avaBenPdfResult');
        if (resultBox) resultBox.style.display = '';
        console.log('avaBenPdfGenerate: PDF با موفقیت ساخته شد ✅');
    } catch (e) {
        console.error('avaBenPdfGenerate error:', e);
        alert('خطا در ساخت PDF: ' + (e && e.message ? e.message : 'دوباره تلاش کنید'));
    } finally {
        clearTimeout(watchdog);
        if (!watchdogFired) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-file-pdf"></i> ساخت PDF';
        }
    }
}

async function avaBenPdfShare(){
    if (!avaBenPdfBlob) return;
    try {
        const file = new File([avaBenPdfBlob], 'AvaPay-Statement.pdf', { type: 'application/pdf' });
        if (navigator.canShare && navigator.canShare({ files: [file] })) {
            await navigator.share({ files: [file], title: 'صورت‌حساب AvaPay' });
        } else {
            // مرورگرهایی که Share فایل را پشتیبانی نمی‌کنند: دانلود مستقیم
            const dl = document.getElementById('avaBenPdfDownloadLink');
            if (dl) dl.click();
        }
    } catch (e) { /* کاربر انصراف داد یا خطای جزئی — نیازی به پیام نیست */ }
}

function avaOpenServiceModal(kind){
    const f = document.getElementById('avaTransferFrame');
    const t = document.getElementById('avaTransferModalTitle');
    if (kind === 'settlement'){
        if (t) t.innerHTML = '<i class="fas fa-hand-holding-dollar"></i> درخواست تسویه حساب';
        if (f) f.src = 'includes/withdrawal_modal_system.php?embed=1&new=1';
    } else {
        if (t) t.innerHTML = '<i class="fas fa-globe"></i> ثبت درخواست حواله ارزی';
        if (f) f.src = 'money_transfer.php?embed=1&new=1';
    }
    avaOpenFsModal('avaTransferModal');
}

/* ==================== (آپدیت ۲) حساب‌های در انتظار پرداخت ==================== */
let avaCurPendAcc = null;

function avaFmtNum(n){ try { return new Intl.NumberFormat('en-US').format(Math.round(n||0)); } catch(e){ return n; } }

// وقتی مودال شماره‌حساب مستقیماً (بدون مودال لیست زیرش) باز شده باشد، این
// true می‌شود تا avaCloseFsModal بداند لازم نیست چیزی زیرش باز بماند —
// نمونه: کلیک از بنر «۱ حساب در انتظار پرداخت» (نگاه کنید dashboard-enhance.js).
let avaPendAccOpenedDirect = false;

function avaOpenPendAcc(pa, direct){
    if (typeof pa === 'string'){ try { pa = JSON.parse(pa); } catch(e){ return; } }
    avaCurPendAcc = pa;
    avaPendAccOpenedDirect = !!direct;
    const isDeal = pa.source === 'deal';
    const isTopup = pa.source === 'topup';
    const isInvoice = pa.source === 'invoice';
    const paIcon = isTopup ? 'fa-coins' : (isDeal ? 'fa-exchange-alt' : (isInvoice ? 'fa-file-invoice' : 'fa-money-bill-transfer'));
    // عنوان
    document.getElementById('avaPendAccTitle').innerHTML =
        '<i class="fas ' + paIcon + '"></i> ' + (pa.title || 'واریز و ارسال فیش');
    // متادیتا (کد پیگیری + مبلغ)
    const metaBox = document.getElementById('avaPendAccMeta');
    metaBox.innerHTML =
        (pa.code ? '<div class="ava-pa-meta-row"><span class="ava-pa-meta-l"><i class="fas fa-hashtag"></i> کد پیگیری</span><b class="ava-pa-meta-code">' + avaEsc(pa.code) + '</b></div>' : '') +
        '<div class="ava-pa-meta-row"><span class="ava-pa-meta-l"><i class="fas fa-coins"></i> مبلغ قابل واریز</span><b class="ava-pa-meta-amount">' + avaFmtNum(pa.amount) + ' ' + avaEsc(pa.currency||'') + '</b></div>';
    // لیست حساب‌ها
    const list = document.getElementById('avaPendAccList');
    const accs = Array.isArray(pa.accounts) ? pa.accounts : [];
    list.innerHTML = accs.map(function(a){
        const name = (a && a.name) ? a.name : '';
        const card = (a && a.card) ? a.card : (typeof a === 'string' ? a : '');
        const safeCard = String(card).replace(/'/g,"\\'");
        return '<div class="ava-acc-row" onclick="avaCopyText(\'' + safeCard + '\')">' +
                 '<div class="ava-acc-info">' +
                    (name ? '<div class="ava-acc-name"><i class="fas fa-user"></i> ' + avaEsc(name) + '</div>' : '') +
                    '<div class="ava-acc-card">' + avaEsc(card) + '</div>' +
                 '</div>' +
                 '<span class="ava-acc-copy" title="کپی"><i class="fas fa-copy"></i></span>' +
               '</div>';
    }).join('') || '<div class="ava-empty">حسابی ثبت نشده</div>';
    // یادداشت ادمین
    const noteBox = document.getElementById('avaPendAccNote');
    if (pa.note && String(pa.note).trim() !== ''){ noteBox.style.display='block'; noteBox.innerHTML = '<i class="fas fa-comment-dots"></i> ' + avaEsc(pa.note); }
    else { noteBox.style.display='none'; }
    // ریست آپلود
    const fi = document.getElementById('avaPendAccFile'); if (fi) fi.value = '';
    const uz = document.getElementById('avaPendAccUpZone'); if (uz) uz.classList.remove('has-files');
    document.getElementById('avaPendAccPreview').innerHTML = '';
    avaOpenFsModal('avaPendAccModal');
}

/* تعریف تکراری avaEsc حذف شد — نسخه‌ی واحد بالاتر تعریف شده است. */

function avaCopyText(txt){
    if (navigator.clipboard && navigator.clipboard.writeText){
        navigator.clipboard.writeText(txt).then(function(){ avaToast('کپی شد'); }).catch(function(){});
    } else {
        const ta=document.createElement('textarea'); ta.value=txt; document.body.appendChild(ta); ta.select();
        try{ document.execCommand('copy'); avaToast('کپی شد'); }catch(e){} document.body.removeChild(ta);
    }
}

function avaPendAccPreview(){
    const files = document.getElementById('avaPendAccFile').files;
    const box = document.getElementById('avaPendAccPreview');
    const uz = document.getElementById('avaPendAccUpZone');
    if (!files || !files.length){ box.innerHTML=''; if(uz) uz.classList.remove('has-files'); return; }
    if(uz) uz.classList.add('has-files');
    box.innerHTML = Array.from(files).map(function(f){
        const isPdf = /\.pdf$/i.test(f.name);
        return '<span class="ava-pa-chip"><i class="fas ' + (isPdf?'fa-file-pdf':'fa-file-image') + '"></i> ' + avaEsc(f.name.length>20?f.name.slice(0,18)+'…':f.name) + '</span>';
    }).join('');
}

async function avaPendAccSubmit(){
    if (!avaCurPendAcc){ return; }
    const fileEl = document.getElementById('avaPendAccFile');
    const files = fileEl ? fileEl.files : null;
    if (!files || !files.length){ avaToast('لطفاً حداقل یک فیش انتخاب کنید', true); return; }
    const pa = avaCurPendAcc;
    const btn = document.querySelector('#avaPendAccModal .ava-pa-submit');
    const orig = btn ? btn.innerHTML : '';
    if (btn){ btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال فشرده‌سازی...'; }
    try {
        // (اصلاح ۶) فشرده‌سازی سمت کلاینت برای آپلود سریع‌تر
        let outFiles = Array.prototype.slice.call(files);
        if (typeof window.AvaCompressFiles === 'function'){
            try { outFiles = await window.AvaCompressFiles(files); } catch(e){}
        }
        if (btn){ btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال ارسال...'; }

        const fd = new FormData();
        fd.append('action', 'upload_receipt');
        let url;
        let fileField = 'file[]';
        if (pa.source === 'deal'){
            url = 'api/deal_settlement_api.php?action=upload_receipt';
            fd.append('deal_id', pa.ref_id);
        } else if (pa.source === 'topup'){
            url = 'api/topup_api.php?action=upload_receipt';
            fd.append('request_id', pa.ref_id);
            fileField = 'receipts[]';   // topup_api انتظار receipts[] دارد
        } else if (pa.source === 'invoice'){
            url = 'api/unpaid_invoice_api.php?action=pay_invoice';
            fd.append('invoice_id', pa.ref_id);
            fileField = 'receipts[]';   // unpaid_invoice_api هم receipts[] انتظار دارد
        } else {
            url = 'api/transfer_api.php';
            fd.append('request_id', pa.ref_id);
        }
        outFiles.forEach(function(f){ fd.append(fileField, f, f.name || 'receipt.jpg'); });

        if (typeof window.avaShowUploadProgress === 'function') window.avaShowUploadProgress('در حال آپلود فیش...');
        const data = (typeof window.avaUploadWithProgress === 'function')
            ? await window.avaUploadWithProgress(url, fd)
            : await (await fetch(url, { method:'POST', body: fd })).json();
        if (data.success){
            if (typeof window.avaHideUploadProgress === 'function') window.avaHideUploadProgress(true, data.message || 'فیش با موفقیت ارسال شد ✅');
            avaToast(data.message || 'فیش ارسال شد');
            avaCloseFsModal('avaPendAccModal');
            setTimeout(function(){ location.reload(); }, 800);
        } else {
            if (typeof window.avaHideUploadProgress === 'function') window.avaHideUploadProgress(false);
            avaToast(data.message || 'خطا در ارسال فیش', true);
            if (btn){ btn.disabled=false; btn.innerHTML = orig; }
        }
    } catch(e){
        if (typeof window.avaHideUploadProgress === 'function') window.avaHideUploadProgress(false);
        avaToast('خطا در ارتباط با سرور', true);
        if (btn){ btn.disabled=false; btn.innerHTML = orig; }
    }
}

function avaOpenPendAccArchive(){
    const list = document.getElementById('avaPendAccArchiveList');
    const all = window.__avaPendingAccountsAll || [];
    if (!all.length){ list.innerHTML = '<div class="ava-empty">حسابی ثبت نشده است</div>'; }
    else {
        list.innerHTML = all.map(function(pa){
            const isDeal = pa.source === 'deal';
            const isTopup = pa.source === 'topup';
            const isInvoice = pa.source === 'invoice';
            const paBg = isTopup ? 'rgba(34,197,94,.16)' : (isDeal ? 'rgba(56,189,248,.16)' : (isInvoice ? 'rgba(255,215,0,.16)' : 'rgba(108,64,197,.16)'));
            const paFg = isTopup ? '#22C55E' : (isDeal ? '#38bdf8' : (isInvoice ? '#FFD700' : '#a78bfa'));
            const paIc = isTopup ? 'fa-coins' : (isDeal ? 'fa-exchange-alt' : (isInvoice ? 'fa-file-invoice' : 'fa-money-bill-transfer'));
            const paid = pa.has_receipt;
            return '<div class="ava-pendacc-item" onclick=\'avaOpenPendAcc(' + JSON.stringify(pa) + ')\'>' +
                '<div class="ava-pendacc-ic" style="background:' + paBg + ';color:' + paFg + ';">' +
                    '<i class="fas ' + paIc + '"></i></div>' +
                '<div class="ava-pendacc-body"><div class="ava-pendacc-t">' + avaEsc(pa.title) +
                    ' <span class="ava-pendacc-code">' + avaEsc(pa.code) + '</span></div>' +
                    '<div class="ava-pendacc-s">' + avaFmtNum(pa.amount) + ' ' + avaEsc(pa.currency) +
                    (paid ? ' · <span style="color:#22C55E;">فیش ارسال شده</span>' : ' · <span style="color:#FFD700;">در انتظار پرداخت</span>') + '</div></div>' +
                '<div class="ava-pendacc-cta"><i class="fas fa-eye"></i> مشاهده</div></div>';
        }).join('');
    }
    avaOpenFsModal('avaPendAccArchiveModal');
}

/* توست سبک اگر تابع سراسری موجود نباشد */
/* نسخه‌ی دومِ avaToast حذف شد — تعریف واحد در بالای همین فایل قرار دارد
   و هر دو حالت (لینک و خطا) را پشتیبانی می‌کند. */

/* --- سوییچ تب صورت‌حساب‌ها (در انتظار پرداخت / تاریخچه) --- */


/* --- مدال پرداخت صورت‌حساب --- */
let avaCurInvoiceId = 0;
async function avaOpenInvoice(id){
    avaCurInvoiceId = id;
    avaOpenSheet('avaInvoiceSheet');
    const box = document.getElementById('avaInvoiceBody');
    box.innerHTML = '<div class="ava-empty"><i class="fas fa-spinner fa-spin"></i></div>';
    try {
        const r = await fetch('api/unpaid_invoice_api.php?action=get_invoice&id=' + id, { cache:'no-store' });
        const d = await r.json();
        if (!d.success){ box.innerHTML = '<div class="ava-empty">'+ (d.message||'خطا') +'</div>'; return; }
        const inv = d.invoice;
        const cur = inv.currency || '';
        const amt = new Intl.NumberFormat('en-US').format(inv.amount);
        const st  = inv.status;
        let html = `<div class="ava-inv-detail">
            <div class="ava-inv-row"><span>مبلغ</span><b>${avaFa(amt)} ${cur}</b></div>
            ${inv.description ? `<div class="ava-inv-row"><span>توضیح</span><b>${avaEsc(inv.description)}</b></div>` : ''}`;
        if (st === 'approved'){
            const fields = [
                ['بانک', inv.bank_name], ['نام صاحب حساب', inv.recipient_name],
                ['شماره کارت', inv.card_number], ['شماره حساب', inv.account_number], ['شبا', inv.iban]
            ];
            html += `<div class="ava-inv-bankbox"><div class="ava-inv-bank-h">اطلاعات پرداخت — کپی کنید و مبلغ را واریز نمایید</div>`;
            fields.forEach(([lbl,val])=>{
                if (!val) return;
                html += `<div class="ava-inv-copy" onclick="avaCopy('${String(val).replace(/'/g,"")}', this)">
                            <div><div class="ava-inv-copy-l">${lbl}</div><div class="ava-inv-copy-v">${avaEsc(val)}</div></div>
                            <i class="fas fa-copy"></i>
                         </div>`;
            });
            html += `</div>
                <div class="ava-inv-up">
                    <label class="ava-list-s">آپلود فیش پرداخت (حداکثر ۶ عکس)</label>
                    <input type="file" id="avaInvFiles" accept="image/*" multiple style="width:100%;margin:6px 0 12px;color:var(--ava-txt);">
                    <button class="ava-wal-btn" style="width:100%;justify-content:center;" onclick="avaInvoicePay()"><i class="fas fa-upload"></i> ارسال فیش برای ادمین</button>
                </div>`;
        } else if (st === 'pending'){
            html += `<div class="ava-inv-note"><i class="fas fa-clock"></i> اطلاعات پرداخت این صورت‌حساب هنوز ارسال نشده است. به‌محض ارسال توسط ادمین، اینجا نمایش داده می‌شود.</div>`;
        } else if (st === 'paid'){
            html += `<div class="ava-inv-note ok"><i class="fas fa-check-circle"></i> فیش شما ارسال شد و در انتظار تأیید نهایی ادمین است.</div>`;
        } else if (st === 'finalized'){
            html += `<div class="ava-inv-note ok"><i class="fas fa-check-double"></i> این صورت‌حساب تسویه و نهایی شده است.</div>`;
        } else if (st === 'rejected'){
            html += `<div class="ava-inv-note no"><i class="fas fa-times-circle"></i> ${avaEsc(inv.reject_reason || 'این صورت‌حساب رد شده است.')}</div>`;
        }
        html += `</div>`;
        box.innerHTML = html;
    } catch(e){ box.innerHTML = '<div class="ava-empty">خطا در بارگذاری</div>'; }
}
function avaCopy(text, el){
    navigator.clipboard.writeText(text).then(()=>{
        if (el){ const i = el.querySelector('i'); if(i){ i.className='fas fa-check'; setTimeout(()=>i.className='fas fa-copy', 1500); } }
        avaToast('کپی شد');
    }).catch(()=>{ avaToast('کپی ناموفق'); });
}
async function avaInvoicePay(){
    const inp = document.getElementById('avaInvFiles');
    if (!inp || !inp.files.length){ avaToast('حداقل یک عکس فیش انتخاب کنید'); return; }
    const fd = new FormData();
    fd.append('invoice_id', avaCurInvoiceId);
    for (let i=0; i<inp.files.length && i<6; i++) fd.append('receipts[]', inp.files[i]);
    if (typeof window.avaShowUploadProgress === 'function') window.avaShowUploadProgress('در حال آپلود فیش...');
    try {
        const d = (typeof window.avaUploadWithProgress === 'function')
            ? await window.avaUploadWithProgress('api/unpaid_invoice_api.php?action=pay_invoice', fd)
            : await (await fetch('api/unpaid_invoice_api.php?action=pay_invoice', { method:'POST', body: fd })).json();
        if (d.success){
            if (typeof window.avaHideUploadProgress === 'function') window.avaHideUploadProgress(true, 'فیش با موفقیت ارسال شد ✅');
            avaToast('فیش ارسال شد'); avaCloseSheet('avaInvoiceSheet'); setTimeout(()=>location.reload(), 1200);
        }
        else { if (typeof window.avaHideUploadProgress === 'function') window.avaHideUploadProgress(false); avaToast(d.message || 'خطا در ارسال فیش'); }
    } catch(e){ if (typeof window.avaHideUploadProgress === 'function') window.avaHideUploadProgress(false); avaToast('خطای شبکه'); }
}

/* --- بج شماره‌دار قرمز روی آیکون هدفون پشتیبانی (بلادرنگ) --- */
function avaSetHeadsetDot(n){
    const dot = document.getElementById('avaSupportDot');
    if (dot) dot.style.display = 'none'; // نقطه‌ی ساده جای خودش را به بج شماره‌دار داد
    const badge = document.getElementById('avaSupportBadge');
    if (!badge) return;
    const count = Number(n) || 0;
    if (count > 0) {
        badge.textContent = count > 9 ? '9+' : String(count);
        badge.style.display = 'flex';
    } else {
        badge.style.display = 'none';
    }
}
async function avaPollSupport(){
    const sheet = document.getElementById('avaTopupSheet');
    if (sheet && sheet.classList.contains('open') && sheet.classList.contains('ava-only-chat')){ avaSetHeadsetDot(0); return; }
    try {
        const r = await fetch('api/support.php?action=unread_count', { cache:'no-store' });
        const d = await r.json();
        if (d && d.success) avaSetHeadsetDot(d.unread);
    } catch(e){}
}
document.addEventListener('DOMContentLoaded', function(){
    avaCheckAlerts();
    setInterval(avaCheckAlerts, 15000); // بررسی هر ۱۵ ثانیه — بدون نیاز به رفرش صفحه
    avaPollSupport();
    setInterval(avaPollSupport, 8000);
    document.addEventListener('visibilitychange', function(){ if (!document.hidden){ avaPollSupport(); avaCheckAlerts(); } });
});

/* --- نرخ‌های مورد علاقه: افزودن/حذف ارز از فهرست الان‌چند --- */
function avaOpenRatePicker(){
    const s = document.getElementById('avaRateSearch'); if (s) s.value = '';
    avaRenderRatePicker();
    avaOpenSheet('avaRatePickerSheet');
}
function avaRenderRatePicker(){
    const box = document.getElementById('avaRatePickerList');
    if (!box) return;
    const all = window.__avaAllCurs || [];
    const q = ((document.getElementById('avaRateSearch')||{}).value || '').trim().toLowerCase();
    const list = all.filter(c => !q || (c.name||'').toLowerCase().includes(q) || (c.code||'').toLowerCase().includes(q));
    if (!list.length){ box.innerHTML = '<div class="ava-empty">ارزی یافت نشد</div>'; return; }
    box.innerHTML = list.map(c => {
        const flag = c.flag
            ? `<img src="https://flagcdn.com/w40/${c.flag}.png" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;" onerror="this.style.display='none'">`
            : `<i class="${c.ico}" style="color:${c.col}"></i>`;
        return `<div class="ava-rate-pick" data-cur="${c.code}">
            <span class="ava-flag" style="width:26px;height:26px;font-size:.8rem;">${flag}</span>
            <div style="flex:1;min-width:0;">
                <div class="ava-rate-name">${avaEsc(c.name)}</div>
                <div class="ava-rate-code">${avaEsc(c.code)}</div>
            </div>
            <button class="ava-rate-pick-btn ${c.fav ? 'on' : ''}" onclick="avaPickFav('${c.code}', this)">
                <i class="fas ${c.fav ? 'fa-check' : 'fa-plus'}"></i> ${c.fav ? 'افزوده‌شده' : 'افزودن'}
            </button>
        </div>`;
    }).join('');
}
async function avaPickFav(currency, btn){
    try {
        const r = await fetch('dashboard.php?ava=fav_toggle', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ currency })
        });
        const d = await r.json();
        if (d.success){
            const item = (window.__avaAllCurs || []).find(c => c.code === currency);
            if (item) item.fav = d.fav;
            if (btn){
                btn.classList.toggle('on', d.fav);
                btn.innerHTML = `<i class="fas ${d.fav ? 'fa-check' : 'fa-plus'}"></i> ${d.fav ? 'افزوده‌شده' : 'افزودن'}`;
            }
            avaReloadRatesList();
        }
    } catch(e){}
}
async function avaToggleFav(btn){
    // ستاره‌ی حذف در خود کارت نرخ‌ها
    const currency = btn.dataset.cur;
    try {
        const r = await fetch('dashboard.php?ava=fav_toggle', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ currency })
        });
        const d = await r.json();
        if (d.success){
            const item = (window.__avaAllCurs || []).find(c => c.code === currency);
            if (item) item.fav = d.fav;
            avaReloadRatesList();
        }
    } catch(e){}
}
/* رندر مجدد فهرست نرخ‌های کارت از روی window.__avaAllCurs + آخرین نرخ‌ها */
let avaLiveRates = window.__avaSeedRates || {};
function avaReloadRatesList(){
    const list = document.getElementById('avaRatesList');
    if (!list) return;
    let favs = (window.__avaAllCurs || []).filter(c => c.fav);
    if (!favs.length){
        const defaults = ['USD','EUR','USDT','GBP','TRY','AED'];
        favs = (window.__avaAllCurs || []).filter(c => defaults.includes(c.code));
    }
    favs = favs.slice(0, 8);
    if (!favs.length){ list.innerHTML = '<div class="ava-empty">ارزی انتخاب نشده است. با «افزودن ارز» شروع کنید.</div>'; }
    else list.innerHTML = favs.map(c => {
        const rt = avaLiveRates[c.code] || {};
        const price = (rt.price != null) ? avaFa(new Intl.NumberFormat('en-US').format(Math.round(rt.price))) : '—';
        const chg = (rt.change != null) ? rt.change : 0;
        const up = chg >= 0;
        const chgTxt = (up ? '+' : '−') + avaFa(Math.abs(chg).toFixed(2)) + '٪';
        const flag = c.flag
            ? `<img src="https://flagcdn.com/w40/${c.flag}.png" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;" onerror="this.style.display='none'">`
            : `<i class="${c.ico}" style="color:${c.col}"></i>`;
        return `<div class="ava-rate-row" data-cur="${c.code}">
            <button class="ava-star" data-cur="${c.code}" onclick="avaToggleFav(this)" title="حذف"><i class="fas fa-star"></i></button>
            <div class="ava-rate-id">
                <span class="ava-flag" style="width:24px;height:24px;font-size:.8rem;">${flag}</span>
                <div><div class="ava-rate-name">${avaEsc(c.name)}</div><div class="ava-rate-code">${avaEsc(c.code)}</div></div>
            </div>
            <div class="ava-rate-price" data-price>${price}</div>
            <div class="ava-rate-chg ${up ? 'up' : 'dn'}" data-chg>${chgTxt}</div>
        </div>`;
    }).join('');
    // به‌روزرسانی زنده‌ی قیمت طلا و سکه (ردیف‌های سرور-رندر)
    (window.__avaGoldCodes || []).forEach(code => {
        const row = document.querySelector(`#avaGoldList .ava-rate-row[data-cur="${code}"]`);
        if (!row) return;
        const rt = avaLiveRates[code];
        if (!rt || rt.price == null) return;
        const isUsd = !!rt.usd;
        const pEl = row.querySelector('[data-price]');
        const cEl = row.querySelector('[data-chg]');
        if (pEl) pEl.innerHTML = avaFa(new Intl.NumberFormat('en-US', {minimumFractionDigits: isUsd?2:0, maximumFractionDigits: isUsd?2:0}).format(rt.price)) + (isUsd ? '<span class="ava-rate-cur">$</span>' : '');
        if (cEl){ const up = (rt.change||0) >= 0; cEl.className = 'ava-rate-chg ' + (up?'up':'dn'); cEl.textContent = (up?'+':'−') + avaFa(Math.abs(rt.change||0).toFixed(2)) + '٪'; }
    });
}
/* سوییچ تب نرخ ارز / طلا و سکه / ارز دیجیتال */
let avaRatesActiveTab = 'cur';
function avaRatesTab(which, el){
    avaRatesActiveTab = which;
    const cur    = document.getElementById('avaRatesList');
    const gold   = document.getElementById('avaGoldList');
    const crypto = document.getElementById('avaCryptoWatchList');
    if (cur)    cur.style.display    = (which === 'cur')    ? 'block' : 'none';
    if (gold)   gold.style.display   = (which === 'gold')   ? 'block' : 'none';
    if (crypto) crypto.style.display = (which === 'crypto') ? 'block' : 'none';
    document.querySelectorAll('#avaRatesCard .ava-inv-tab[data-rtab]').forEach(t=>t.classList.remove('active'));
    if (el) el.classList.add('active');
    // متن دکمه‌ی افزودن را با تب هماهنگ کن
    const addBtn = document.getElementById('avaRateAddBtn');
    if (addBtn) addBtn.innerHTML = '<i class="fas fa-plus"></i> ' + (which === 'crypto' ? 'افزودن ارز دیجیتال' : 'افزودن ارز');
    if (which === 'crypto') avaRenderCryptoWatch();
}

/* دکمه‌ی «افزودن» متن‌آگاه: بسته به تب فعال، پیکر مناسب را باز می‌کند */
function avaRateAddClick(){
    if (avaRatesActiveTab === 'crypto') avaOpenCryptoPicker();
    else avaOpenRatePicker();
}

/* --- واچ‌لیست ارز دیجیتال --- */
window.__avaCryptoWatch = (function(){
    try { return JSON.parse(document.getElementById('avaCryptoWatchList').getAttribute('data-watch') || '[]'); }
    catch(e){ return []; }
})();
window.__avaMarketPick = <?php echo json_encode(array_values($avaMarketPick ?? []), JSON_UNESCAPED_SLASHES); ?>;
window.__avaCryptoPrices = {}; // id -> {price, change24h, image, name, symbol}

function avaFmtUsd(p){
    if (p == null || isNaN(p)) return '—';
    p = Number(p);
    if (p >= 1000) return '$' + p.toLocaleString('en-US',{maximumFractionDigits:0});
    if (p >= 1)    return '$' + p.toLocaleString('en-US',{maximumFractionDigits:2});
    if (p >= 0.01) return '$' + p.toFixed(4);
    return '$' + p.toPrecision(2);
}
function avaRenderCryptoWatch(){
    const box = document.getElementById('avaCryptoWatchList');
    if (!box) return;
    const watch = window.__avaCryptoWatch || [];
    if (!watch.length){
        box.innerHTML = '<div class="ava-empty">ارز دیجیتالی در واچ‌لیست نیست. با «افزودن» شروع کنید.</div>';
        return;
    }
    box.innerHTML = watch.map(w=>{
        const p = window.__avaCryptoPrices[w.id] || {};
        const chg = (p.change24h != null) ? Number(p.change24h) : null;
        const up = (chg == null) ? true : chg >= 0;
        const chgTxt = (chg == null) ? '—' : (up?'+':'−') + avaFa(Math.abs(chg).toFixed(2)) + '٪';
        const price = (p.price != null) ? avaFmtUsd(p.price) : '…';
        const img = p.image
            ? `<img src="${p.image}" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;" onerror="this.parentNode.innerHTML='<i class=&quot;fab fa-bitcoin&quot; style=&quot;color:#F7931A&quot;></i>'">`
            : `<i class="fab fa-bitcoin" style="color:#F7931A"></i>`;
        const nm = p.name || w.name || w.id;
        const sym = p.symbol ? p.symbol.toUpperCase() : '';
        return `<div class="ava-rate-row" data-coin="${w.id}" style="cursor:pointer" onclick="avaOpenCoin('${w.id}')">
            <button class="ava-star" onclick="event.stopPropagation();avaRemoveCryptoWatch('${w.id}', this)" title="حذف از واچ‌لیست"><i class="fas fa-star"></i></button>
            <div class="ava-rate-id">
                <span class="ava-flag" style="width:24px;height:24px;font-size:.8rem;">${img}</span>
                <div><div class="ava-rate-name">${avaEsc(nm)}</div><div class="ava-rate-code">${avaEsc(sym)}</div></div>
            </div>
            <div class="ava-rate-price" data-price>${price}</div>
            <div class="ava-rate-chg ${up ? 'up' : 'dn'}" data-chg>${chgTxt}</div>
        </div>`;
    }).join('');
}
async function avaRemoveCryptoWatch(id, btn){
    try {
        const r = await fetch('dashboard.php?ava=fav_toggle', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ currency:id, market:'crypto' })
        });
        const d = await r.json();
        if (d.success){
            window.__avaCryptoWatch = (window.__avaCryptoWatch || []).filter(w=>w.id !== id);
            avaRenderCryptoWatch();
        }
    } catch(e){}
}
async function avaAddCryptoWatch(id, name, btn){
    try {
        const r = await fetch('dashboard.php?ava=fav_toggle', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ currency:id, market:'crypto', coin_name:name })
        });
        const d = await r.json();
        if (d.success){
            if (d.fav){
                if (!(window.__avaCryptoWatch||[]).some(w=>w.id===id))
                    window.__avaCryptoWatch.push({ id, name });
            } else {
                window.__avaCryptoWatch = (window.__avaCryptoWatch||[]).filter(w=>w.id!==id);
            }
            if (btn){
                btn.classList.toggle('on', d.fav);
                btn.innerHTML = `<i class="fas ${d.fav ? 'fa-check' : 'fa-plus'}"></i> ${d.fav ? 'افزوده‌شده' : 'افزودن'}`;
            }
            avaRenderCryptoWatch();
        }
    } catch(e){}
}

/* پیکر انتخاب ارز دیجیتال (از فهرست سرویس CoinGecko) */
let __avaCryptoPickerLoaded = false;
function avaOpenCryptoPicker(){
    const s = document.getElementById('avaCryptoSearch'); if (s) s.value = '';
    avaOpenSheet('avaCryptoPickerSheet');
    if (!__avaCryptoPickerLoaded) avaLoadCryptoPicker();
    else avaRenderCryptoPicker();
}
async function avaLoadCryptoPicker(){
    const box = document.getElementById('avaCryptoPickerList');
    if (box) box.innerHTML = '<div class="ava-empty"><i class="fas fa-spinner fa-spin"></i></div>';
    try {
        const res = await fetch('/ledor/api/crypto_market_api.php', { cache:'no-store' });
        const d = await res.json();
        const coins = [].concat(d.market || [], d.gainers || []);
        const seen = {}; window.__avaCryptoPickerCoins = [];
        coins.forEach(c=>{ if(c && c.id && !seen[c.id]){ seen[c.id]=1; window.__avaCryptoPickerCoins.push(c);
            window.__avaCryptoPrices[c.id] = { price:c.price, change24h:c.change24h, image:c.image, name:c.name, symbol:c.symbol };
        }});
        __avaCryptoPickerLoaded = true;
        avaRenderCryptoPicker();
    } catch(e){
        if (box) box.innerHTML = '<div class="ava-empty">خطا در دریافت فهرست ارزها</div>';
    }
}
function avaRenderCryptoPicker(){
    const box = document.getElementById('avaCryptoPickerList');
    if (!box) return;
    const all = window.__avaCryptoPickerCoins || [];
    const q = ((document.getElementById('avaCryptoSearch')||{}).value || '').trim().toLowerCase();
    const list = all.filter(c => !q || (c.name||'').toLowerCase().includes(q) || (c.symbol||'').toLowerCase().includes(q));
    if (!list.length){ box.innerHTML = '<div class="ava-empty">ارزی یافت نشد</div>'; return; }
    const watchIds = (window.__avaCryptoWatch||[]).map(w=>w.id);
    box.innerHTML = list.map(c=>{
        const fav = watchIds.includes(c.id);
        const img = c.image
            ? `<img src="${c.image}" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;" onerror="this.style.display='none'">`
            : `<i class="fab fa-bitcoin" style="color:#F7931A"></i>`;
        return `<div class="ava-rate-pick">
            <span class="ava-flag" style="width:26px;height:26px;font-size:.8rem;">${img}</span>
            <div style="flex:1;min-width:0;">
                <div class="ava-rate-name">${avaEsc(c.name)}</div>
                <div class="ava-rate-code">${avaEsc((c.symbol||'').toUpperCase())} · ${avaFmtUsd(c.price)}</div>
            </div>
            <button class="ava-rate-pick-btn ${fav ? 'on' : ''}" onclick="avaAddCryptoWatch('${c.id}', '${(c.name||'').replace(/'/g,'')}', this)">
                <i class="fas ${fav ? 'fa-check' : 'fa-plus'}"></i> ${fav ? 'افزوده‌شده' : 'افزودن'}
            </button>
        </div>`;
    }).join('');
}

/* به‌روزرسانی لحظه‌ای نرخ‌ها */
async function avaFetchLiveRates(){
    try {
        const r = await fetch('dashboard.php?ava=rates_live', { cache:'no-store' });
        const d = await r.json();
        if (d && d.success && d.rates){
            // ادغام نرخ‌های زنده با پرچم usd از seed
            Object.keys(d.rates).forEach(k => {
                if (window.__avaSeedRates && window.__avaSeedRates[k] && window.__avaSeedRates[k].usd) d.rates[k].usd = true;
            });
            avaLiveRates = d.rates;
            avaReloadRatesList();
        }
    } catch(e){}
}
document.addEventListener('DOMContentLoaded', function(){
    // نرخ اولیه از HTML موجود است؛ اولین واکشی زنده بعد از چند ثانیه، سپس هر ۳۰ ثانیه
    setTimeout(avaFetchLiveRates, 4000);
    setInterval(avaFetchLiveRates, 30000);
    document.addEventListener('visibilitychange', function(){ if (!document.hidden) avaFetchLiveRates(); });
});

</script>

<script src="assets/js/dashboard-enhance.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/dashboard-enhance.js') ?: time(); ?>"></script>

</body>
</html>