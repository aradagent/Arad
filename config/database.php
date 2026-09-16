<?php
// config/database.php
// تنظیمات دیتابیس - بدون شروع سشن در اینجا

// فقط اگر سشن فعال نیست و در محیط CLI نیستیم، تنظیمات رو اعمال کن
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    // تنظیمات session فقط در صورتی که سشن فعال نباشد
    ini_set('session.gc_maxlifetime', 60 * 60 * 24 * 30);
    ini_set('session.cookie_lifetime', 60 * 60 * 24 * 30);
    session_start();
} elseif (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
    // سشن فعال است، فقط session_start رو اجرا نکن
    // اما تنظیمات ini رو می‌توانیم تغییر بدیم (warning می‌دهد ولی بیخیال)
    @ini_set('session.gc_maxlifetime', 60 * 60 * 24 * 30);
    @ini_set('session.cookie_lifetime', 60 * 60 * 24 * 30);
}

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'aradexch_app');
define('DB_PASS', 'VIJVC9Gn5z9Y?D.$');
define('DB_NAME', 'aradexch_app');

// Telegram configuration
define('ADMIN_TELEGRAM_ID', '5330629504');
define('BOT_TOKEN', '5937135973:AAEwK4lxar3xRM_mwvapLWNuw26VUv2c6e4');

// Create connection
// (اصلاح) قبلاً new mysqli(...) بدون مهلتِ اتصال بود — این خط توسط *هر* صفحه
// و *هر* API در سایت اجرا می‌شود، پس اگر دیتابیس لحظه‌ای کند/شلوغ باشد (مثلاً
// زیر بارِ چند کاربرِ هم‌زمان)، این اتصال می‌توانست تا سقفِ پیش‌فرضِ سیستم بلاک
// شود؛ چون تعداد workerهای PHP-FPM محدود است، با چند درخواستِ هم‌زمانِ گیرکرده
// کلِ استخر پر می‌شد و کل سایت (نه فقط یک کاربر) از کار می‌افتاد تا زمانی که
// آن اتصال‌های معلق منقضی می‌شدند. حالا حداکثر ۳ ثانیه، مطابق همان الگویی که
// در includes/fast_mysqli.php برای بقیه‌ی نقاطِ پروژه استفاده شده.
require_once __DIR__ . '/../includes/fast_mysqli.php';
$conn = avapay_fast_mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, 3);

// Check connection
if (!$conn) {
    error_log("Database connection failed or timed out");
    die(json_encode(['success' => false, 'message' => 'Database connection error']));
}

// مسیرهای ذخیره‌سازیِ آپلود کاربران (KYC/فیش/چت/آواتار) — جدا از کدِ برنامه
require_once __DIR__ . '/../includes/upload_paths.php';

/* ------------------------------------------------------------------
   گاردِ سراسری مسدودیت کاربر.
   اینجا قرار گرفته چون *هر* صفحه و *هر* API بدون استثنا از این فایل
   عبور می‌کند؛ پس کاربر مسدود در همین نقطه متوقف می‌شود و هیچ درخواستی
   — از هیچ مسیری — به کد پایین‌دستی نمی‌رسد.
------------------------------------------------------------------ */
require_once __DIR__ . '/../includes/ban_guard.php';
avapay_ban_guard($conn);

/* ------------------------------------------------------------------
   گاردِ سراسری «حالت آپدیت سایت» — درست مثل ban_guard، همین‌جا (نقطه‌ای
   که هر صفحه/API بدون استثنا از آن عبور می‌کند) اجرا می‌شود تا وقتی
   ادمین از بخش «آپدیت سایت» یک بسته‌ی جدید آپلود می‌کند، همه‌ی
   بازدیدکنندگانِ غیرادمین پیامِ «در حال آپدیت» را ببینند.
------------------------------------------------------------------ */
require_once __DIR__ . '/../includes/maintenance_gate.php';
avapay_maintenance_guard($conn);

// Helper function to generate account number
function generateAccountNumber(): string {
    global $conn;
    
    // Use advisory lock to prevent race conditions
    $lockKey = 'account_number_generation';
    $conn->query("SELECT GET_LOCK('{$lockKey}', 5)");
    
    try {
        $prefix = 'AV5614'; // Fixed 6 characters prefix
        $maxAttempts = 100;
        
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            // Generate 4 random digits (total 10 digits with AV5614 prefix)
            $randomDigits = '';
            for ($i = 0; $i < 4; $i++) {
                $randomDigits .= random_int(0, 9);
            }
            
            $accountNumber = $prefix . $randomDigits;
            
            // Validate format: must be exactly 10 characters
            if (strlen($accountNumber) !== 10) {
                continue;
            }
            
            // Check if this account number already exists
            $stmt = $conn->prepare("SELECT id FROM users WHERE account_number = ?");
            $stmt->bind_param("s", $accountNumber);
            $stmt->execute();
            $result = $stmt->get_result();
            
            // If not found, we can use it
            if ($result->num_rows === 0) {
                return $accountNumber;
            }
        }
        
        // Fallback with timestamp
        return $prefix . substr(time(), -4);
        
    } finally {
        $conn->query("SELECT RELEASE_LOCK('{$lockKey}')");
    }
}

// Helper function to generate IBAN
function generateIBAN() {
    global $conn;
    $prefix = 'AV55';
    
    do {
        $random = strtoupper(bin2hex(random_bytes(5)));
        $iban = $prefix . $random;
        
        $check = $conn->query("SELECT id FROM users WHERE iban_number = '{$iban}'");
    } while ($check && $check->num_rows > 0);
    
    return $iban;
}

// Helper function to check if user is admin
function isAdmin($userId) {
    global $conn;
    
    $sql = "SELECT telegram_id, is_admin FROM users WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        return ($user['telegram_id'] == '5330629504' || $user['is_admin']);
    }
    
    return false;
}

// Helper function to send Telegram message
function sendTelegramMessage($chatId, $message, $inlineKeyboard = null) {
    $botToken = BOT_TOKEN;
    if (empty($botToken) || $botToken === 'YOUR_BOT_TOKEN') {
        error_log("Telegram bot token not set");
        return false;
    }
    
    // اضافه کردن لینک اپلیکیشن AVA PAY در پایین پیام
    $message .= "\n\n━━━━━━━━━━━━━━━━━━━━\n";
    $message .= "📱 <b>اپلیکیشن AVA PAY</b>\n";
    $message .= "🔗 <a href='https://aradexchange.com/ledor/arad.php'>ورود به اپلیکیشن</a>\n";
    $message .= "━━━━━━━━━━━━━━━━━━━━";
    
    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true
    ];
    
    // اضافه کردن کیبورد سفارشی اگر وجود داشته باشد
    if ($inlineKeyboard !== null && is_array($inlineKeyboard)) {
        $data['reply_markup'] = json_encode($inlineKeyboard);
    }
    
    $options = [
        'http' => [
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => http_build_query($data),
            // (اصلاح) این تابع محتمل‌ترین جای کل پروژه است که صدا زده می‌شود
            // (تقریباً هر نوتیفیکیشنِ تلگرامیِ اپ از همین رد می‌شود) و تا الان
            // هیچ timeout ای نداشت — یعنی اگر تلگرام لحظه‌ای کند/بی‌پاسخ بود،
            // این تابع می‌توانست تا سقفِ پیش‌فرضِ سیستم (اغلب ۶۰ ثانیه) یک
            // workerِ PHP-FPM را قفل کند؛ با چند درخواستِ هم‌زمان، کل سایت
            // چند دقیقه هنگ می‌کرد. حالا حداکثر ۸ ثانیه.
            'timeout' => 8,
        ]
    ];
    
    $context = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);
    
    if ($result === FALSE) {
        error_log("Failed to send Telegram message to {$chatId}");
    }
    
    return $result !== FALSE;
}

// تابع برای استانداردسازی نوع تراکنش


// تابع برای ارسال اطلاعیه ورود کاربر به ادمین


// notifications


/**
 * Create transaction notification
 */


/* ------------------------------------------------------------------
   ضربان‌ساز سراسری هشدارهای قیمت (fallback بدون کران)
   ------------------------------------------------------------------
   این فایل توسط همه‌ی صفحات و APIها include می‌شود؛ بنابراین ترافیکِ
   «هر» کاربری (وب، API، وب‌هوک) می‌تواند هشدارهای «همه»‌ی کاربران را
   بررسی کند — حتی وقتی صاحبِ هشدار اپ را باز نکرده است.

   - قفل فایل ۶۰ ثانیه‌ای: حداکثر یک اجرا در دقیقه، بقیه فوراً رد می‌شوند.
   - register_shutdown_function + fastcgi_finish_request: بررسی بعد از
     ارسال پاسخ انجام می‌شود و هیچ صفحه‌ای را کند نمی‌کند.
   - اگر کران واقعی نصب باشد، این گیت عملاً همیشه بسته می‌ماند چون
     heartbeat داخل خودش هم گارد «کاری برای انجام هست؟» دارد.
------------------------------------------------------------------ */
if (!defined('AVAPAY_GLOBAL_HEARTBEAT') && isset($conn) && ($conn instanceof mysqli)) {
    define('AVAPAY_GLOBAL_HEARTBEAT', 1);
    $__avaHbGate = sys_get_temp_dir() . '/ava_hb_gate.txt';
    $__avaHbLast = is_readable($__avaHbGate) ? (int)@file_get_contents($__avaHbGate) : 0;
    if (time() - $__avaHbLast >= 60) {
        @file_put_contents($__avaHbGate, (string)time(), LOCK_EX);
        $__avaHbConn = $conn;
        register_shutdown_function(function() use ($__avaHbConn) {
            // پاسخ را کامل بفرست، بعد بررسی کن (کاربر معطل نمی‌ماند)
            if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
            $conn = $__avaHbConn;
            if (!($conn instanceof mysqli)) return;
            // اگر اتصال در طول درخواست بسته شده باشد، بی‌سروصدا رد شو
            $ok = false; try { $ok = @$conn->ping(); } catch (\Throwable $e) {}
            if (!$ok) return;
            @include __DIR__ . '/../includes/price_alert_heartbeat.php';
        });
    }
}

?>