<?php
/**
 * cron_price_alerts.php
 * ------------------------------------------------------------------
 * بررسی مستقل هشدارهای قیمت — حتی وقتی هیچ کاربری آنلاین نیست.
 *
 * این فایل را با یک کران‌جاب (cron job) هر ۱ دقیقه یک‌بار صدا بزنید:
 *
 *   * * * * * php /home/aradexch/public_html/ledor/cron_price_alerts.php  >/dev/null 2>&1
 *
 * یا از طریق cURL/wget با یک توکن امنیتی:
 *
 *   * * * * * curl -s "https://aradexchange.com/ledor/cron_price_alerts.php?key=CRON_SECRET_KEY" >/dev/null 2>&1
 *
 * هر هشدار «بازه‌ی بررسی» (check_interval دقیقه‌ای) خودش را دارد؛ این اسکریپت
 * فقط هشدارهایی را بررسی می‌کند که از آخرین بررسی‌شان به‌اندازه‌ی آن بازه گذشته باشد.
 * ------------------------------------------------------------------
 */

@set_time_limit(120);
@ignore_user_abort(true);
header('Content-Type: application/json; charset=UTF-8');

// ---- امنیت: هنگام فراخوانی از طریق وب، کلید لازم است (از خط فرمان آزاد است) ----
define('AVAPAY_CRON_KEY', 'ARAD-cron-7X29pLqz'); // ← این کلید را تغییر دهید
$__isCli = (php_sapi_name() === 'cli');
if (!$__isCli) {
    $key = $_GET['key'] ?? '';
    if (!hash_equals(AVAPAY_CRON_KEY, (string)$key)) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        exit;
    }
}

// ---- حالت وضعیت: بررسی این‌که کران واقعاً اجرا می‌شود ----
// آدرس: cron_price_alerts.php?key=...&status=1
$__runFile = sys_get_temp_dir() . '/ava_cron_last_run.txt';
if (!$__isCli && !empty($_GET['status'])) {
    $last = is_readable($__runFile) ? (int)@file_get_contents($__runFile) : 0;
    echo json_encode([
        'last_run'     => $last ? date('c', $last) : null,
        'seconds_ago'  => $last ? (time() - $last) : null,
        'running'      => ($last && (time() - $last) < 180), // اگر کمتر از ۳ دقیقه پیش اجرا شده یعنی کران فعال است
        'server_time'  => date('c'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/config/database.php';
if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['error' => 'db']);
    exit;
}

// ثبت زمان اجرا (برای تشخیص فعال بودن کران)
@file_put_contents($__runFile, (string)time(), LOCK_EX);

// ---- به‌روزرسانی نرخ‌ها از الان‌چند (ارز + طلا) قبل از بررسی ----
// چون این کران هر دقیقه اجرا می‌شود، قیمت‌ها را حداکثر ۵۰ ثانیه کهنه نگه می‌داریم
// تا هشدارهای «هر ۱ دقیقه» واقعاً بر اساس قیمت تازه بررسی شوند.
$GLOBALS['AVA_SYNC_MAX_AGE'] = 50;
@include __DIR__ . '/includes/alanchand_sync.php';

// ---- بارگذاری توابع اطلاع‌رسانی ----
$__nh = __DIR__ . '/includes/notify_helper.php';
if (file_exists($__nh)) @require_once $__nh;

// ---- نرخ‌های لحظه‌ای (برای هشدارهای نوع rate) ----
$rates = [];
$rr = @$conn->query("SELECT currency, price FROM currency_rates");
if ($rr) while ($x = $rr->fetch_assoc()) $rates[strtoupper($x['currency'])] = (float)$x['price'];

// ---- قیمت لحظه‌ای ارزهای دیجیتال (برای هشدارهای نوع crypto) ----
// ابتدا از کش سرویس CoinGecko (که داشبورد هر دقیقه به‌روز می‌کند) می‌خوانیم؛
// اگر کش تازه نبود، مستقیماً از CoinGecko قیمت ارزهای موردنیاز را می‌گیریم.
$cryptoPrices = [];
$__ccFile = sys_get_temp_dir() . '/ava_crypto_market_cache.json';
if (is_readable($__ccFile)) {
    $__cc = json_decode(@file_get_contents($__ccFile), true);
    // فقط کشِ تازه (حداکثر ۵ دقیقه) معتبر است تا هشدار با قیمت کهنه مقایسه نشود
    if (is_array($__cc) && (time() - (int)($__cc['updated_at'] ?? 0)) <= 300) {
        foreach (array_merge($__cc['market'] ?? [], $__cc['gainers'] ?? []) as $__coin) {
            if (isset($__coin['id'], $__coin['price'])) $cryptoPrices[strtolower($__coin['id'])] = (float)$__coin['price'];
        }
    }
}
// تابع دریافت HTTP (cURL یا file_get_contents)
if (!function_exists('ava_cron_http_get')) {
    function ava_cron_http_get($url) {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT => 'AvaPay-Cron/1.0', CURLOPT_HTTPHEADER => ['Accept: application/json'],
            ]);
            $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            return ($body !== false && $code >= 200 && $code < 300) ? $body : false;
        }
        $ctx = stream_context_create(['http'=>['timeout'=>12,'header'=>"Accept: application/json\r\nUser-Agent: AvaPay-Cron/1.0\r\n"],'ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]]);
        $b = @file_get_contents($url, false, $ctx);
        return $b !== false ? $b : false;
    }
}
// شناسه‌ی ارزهای دیجیتالی که هشدار فعال دارند و قیمتشان در کش نیست را جمع کن
$__needCoins = [];
$__cq = @$conn->query("SELECT DISTINCT currency FROM price_alerts WHERE is_active = 1 AND alert_type = 'crypto'");
if ($__cq) {
    while ($__c = $__cq->fetch_assoc()) {
        $cid = strtolower($__c['currency']);
        if ($cid !== '' && !isset($cryptoPrices[$cid])) $__needCoins[$cid] = true;
    }
}
if (!empty($__needCoins)) {
    $ids = implode(',', array_map('rawurlencode', array_keys($__needCoins)));
    $raw = ava_cron_http_get("https://api.coingecko.com/api/v3/simple/price?ids=$ids&vs_currencies=usd");
    if ($raw !== false) {
        $j = json_decode($raw, true);
        if (is_array($j)) foreach ($j as $cid => $obj) {
            if (isset($obj['usd'])) $cryptoPrices[strtolower($cid)] = (float)$obj['usd'];
        }
    }
}

// ---- انتخاب هشدارهایی که «زمان بررسی‌شان» رسیده است ----
// هشدار وقتی بررسی می‌شود که: فعال باشد و از last_checked_at به‌اندازه‌ی check_interval دقیقه گذشته باشد.
$sql = "SELECT id, user_id, currency, target_price, direction,
               COALESCE(alert_type,'ad')       AS alert_type,
               coin_name,
               COALESCE(notify_telegram,1)     AS notify_telegram,
               COALESCE(notify_email,0)        AS notify_email,
               COALESCE(notify_toast,1)        AS notify_toast,
               COALESCE(check_interval,60)     AS check_interval,
               last_checked_at, last_notified_at,
               (last_notified_at IS NULL
                OR last_notified_at <= DATE_SUB(NOW(), INTERVAL COALESCE(check_interval,60) MINUTE)
               ) AS notify_ok
        FROM price_alerts
        WHERE is_active = 1
        LIMIT 500";
$alerts = @$conn->query($sql);

$checked = 0; $fired = 0;
if ($alerts) {
    while ($al = $alerts->fetch_assoc()) {
        $checked++;
        $id   = (int)$al['id'];
        $cur  = strtoupper($al['currency']);
        $curEsc = $conn->real_escape_string($cur);
        $tp   = (float)$al['target_price'];
        $dir  = $al['direction'] === 'below' ? 'below' : 'above';
        $op   = $dir === 'below' ? '<=' : '>=';
        $type = $al['alert_type'] ?? 'ad';
        if (!in_array($type, ['ad','rate','crypto'], true)) $type = 'ad';
        $found = null;

        if ($type === 'crypto') {
            // قیمت دلاری ارز دیجیتال از CoinGecko
            $cid = strtolower($al['currency']);
            if (isset($cryptoPrices[$cid])) {
                $price = $cryptoPrices[$cid];
                if (($dir === 'below' && $price <= $tp) || ($dir === 'above' && $price >= $tp)) $found = $price;
            }
        } elseif ($type === 'rate') {
            // فقط نرخ لحظه‌ای همان ارز (currency_rates از الان‌چند)
            if (isset($rates[$cur])) {
                $price = $rates[$cur];
                if (($dir === 'below' && $price <= $tp) || ($dir === 'above' && $price >= $tp)) $found = $price;
            }
        } else {
            // فقط قیمت آگهی‌های فعال بازار
            $r1 = @$conn->query("SELECT price_per_unit AS price FROM user_ads
                                 WHERE currency = '$curEsc' AND status = 'active' AND price_per_unit $op $tp
                                 ORDER BY created_at DESC LIMIT 1");
            if ($r1 && $r1->num_rows) $found = (float)$r1->fetch_assoc()['price'];
            if ($found === null) {
                $r2 = @$conn->query("SELECT price FROM exchange_ads
                                     WHERE currency = '$curEsc' AND status = 'active' AND price $op $tp
                                     ORDER BY created_at DESC LIMIT 1");
                if ($r2 && $r2->num_rows) $found = (float)$r2->fetch_assoc()['price'];
            }
        }

        // زمان آخرین بررسی را همیشه به‌روز کن (چه شرط برقرار باشد چه نه)
        @$conn->query("UPDATE price_alerts SET last_checked_at = NOW() WHERE id = $id");

        if ($found === null) continue; // شرط برقرار نیست

        // جلوگیری از ارسال تکراری (مقایسه سمت MySQL تا اختلاف ساعت PHP/MySQL اثر نگذارد)
        if ((int)($al['notify_ok'] ?? 1) !== 1) continue;

        $dirTxt = $dir === 'below' ? 'پایین‌تر از' : 'بالاتر از';
        if ($type === 'crypto') {
            $label = !empty($al['coin_name']) ? $al['coin_name'] : $cur;
            $title = '🔔 هشدار قیمت ارز دیجیتال';
            $body  = "$label به $dirTxt $" . rtrim(rtrim(number_format($tp, 4), '0'), '.') . " رسید.\n"
                   . "قیمت لحظه‌ای: $" . rtrim(rtrim(number_format($found, 4), '0'), '.');
        } else {
            $srcTxt = $type === 'rate' ? 'نرخ لحظه‌ای' : 'قیمت آگهی بازار';
            $title  = '🔔 هشدار قیمت';
            $body   = "ارز $cur به $dirTxt " . number_format($tp) . " تومان رسید.\n"
                    . "$srcTxt: " . number_format($found) . " تومان";
        }

        if (function_exists('notifyUser')) {
            @notifyUser($conn, (int)$al['user_id'], $title, $body, [
                'type'       => 'price_alert',
                'url'        => '/ledor/dashboard.php',
                'related_id' => (int)$al['id'],
                'telegram' => (int)$al['notify_telegram'] === 1,
                'email'    => (int)$al['notify_email'] === 1,
                'db'       => (int)$al['notify_toast'] === 1,
                // push همیشه ارسال می‌شود: این تنها کانالی است که با اپِ بسته
                // روی گوشی نمایش داده می‌شود و نباید به تنظیم «توست» وابسته باشد.
                'push'     => true,
                // ارسال فوری: ایمیل/تلگرام همان لحظه‌ی تشخیص ارسال شوند، بدون وابستگی به پردازش صف
                'immediate' => true,
            ]);
        }
        @$conn->query("UPDATE price_alerts SET last_notified_at = NOW(), triggered_at = NOW() WHERE id = $id");
        $fired++;
    }
}

// ---- خالی کردن صف اعلان‌ها ----
// اعلان‌های ساخته‌شده در بالا (و هر اعلان دیگری از بخش‌های برنامه) در صف
// notification_queue می‌نشینند. اینجا همان‌جا ارسالشان می‌کنیم تا نصب یک
// کران کافی باشد و نیازی به کران دوم نباشد.
$queueStat = ['processed' => 0, 'sent' => 0, 'failed' => 0];
if (function_exists('processNotificationQueue')) {
    try { $queueStat = processNotificationQueue($conn, 30); } catch (\Throwable $e) {}
}

// پاک‌سازی اعلان‌های ارسال‌شده‌ی قدیمی
@$conn->query("DELETE FROM notification_queue
               WHERE status = 'sent' AND sent_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");

echo json_encode([
    'success' => true,
    'checked' => $checked,
    'fired'   => $fired,
    'queue'   => $queueStat,
    'time'    => date('c'),
], JSON_UNESCAPED_UNICODE);
