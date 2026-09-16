<?php
/**
 * includes/price_alert_heartbeat.php
 * ------------------------------------------------------------------
 * بررسی خودکار هشدارهای قیمت و ارسال اطلاع‌رسانی — بدون نیاز به کرون.
 *
 * این فایل در dashboard.php اینکلود می‌شود. هر بار (با احتمال کم) اجرا شده
 * و هشدارهای فعالی را که «شرطشان برقرار است» و «در یک ساعت گذشته اطلاع‌رسانی
 * نشده‌اند» پیدا کرده و از طریق کانال‌های انتخاب‌شده (تلگرام / ایمیل / توست)
 * برای صاحبِ هشدار ارسال می‌کند. چون خودِ هشدار در دیتابیس است، این کار
 * مستقل از آنلاین بودن کاربر انجام می‌شود.
 *
 * دو نوع هشدار:
 *   - ad   : مقایسه با قیمت آگهی‌های فعال بازار (user_ads / exchange_ads)
 *   - rate : مقایسه با نرخ لحظه‌ای ارز (currency_rates — از الان‌چند)
 *
 * نیازمند $conn فعال در scope فراخوانی.
 * ------------------------------------------------------------------
 */

if (!isset($conn) || !($conn instanceof mysqli)) return;

// نکته: throttle تصادفی حذف شد. کوئری سبک زیر فقط وقتی کاری هست ادامه می‌دهد،
// و به‌روزرسانی last_checked_at تضمین می‌کند هر هشدار فقط طبق بازه‌ی خودش بررسی شود.

// آیا اصلاً هشدار فعالی هست که «زمان بررسی‌اش» رسیده باشد؟ (کوئری سبک)
$__due = @$conn->query("SELECT COUNT(*) AS c FROM price_alerts
                        WHERE is_active = 1
                          AND (last_checked_at IS NULL
                               OR last_checked_at <= DATE_SUB(NOW(), INTERVAL COALESCE(check_interval,60) MINUTE))");
if (!$__due) return;
$__row = $__due->fetch_assoc();
if (!$__row || (int)$__row['c'] === 0) return;

// اطمینان از بارگذاری هاب اعلان
if (!function_exists('notifyUser')) {
    $__nh = __DIR__ . '/notify_helper.php';
    if (file_exists($__nh)) @require_once $__nh;
}

// نرخ‌های لحظه‌ای ارزها (برای هشدارهای نوع rate)
$__rates = [];
$__rr = @$conn->query("SELECT currency, price FROM currency_rates");
if ($__rr) while ($x = $__rr->fetch_assoc()) $__rates[strtoupper($x['currency'])] = (float)$x['price'];

// قیمت لحظه‌ای ارزهای دیجیتال (برای هشدارهای نوع crypto) — از کش سرویس CoinGecko
// نکته: فقط به کشِ «تازه» اعتماد می‌کنیم؛ در غیر این صورت قیمت مستقیماً گرفته می‌شود،
// وگرنه ممکن است هشدار با قیمت چند ساعت پیش مقایسه شود.
$__cryptoPrices = [];
$__ccFile = sys_get_temp_dir() . '/ava_crypto_market_cache.json';
$__ccMaxAge = 300; // ۵ دقیقه
if (is_readable($__ccFile)) {
    $__cc = json_decode(@file_get_contents($__ccFile), true);
    if (is_array($__cc) && (time() - (int)($__cc['updated_at'] ?? 0)) <= $__ccMaxAge) {
        foreach (array_merge($__cc['market'] ?? [], $__cc['gainers'] ?? []) as $__coin) {
            if (isset($__coin['id'], $__coin['price'])) $__cryptoPrices[strtolower($__coin['id'])] = (float)$__coin['price'];
        }
    }
}
// اگر قیمت ارز دیجیتالی که هشدار فعال دارد در کش نبود، مستقیم از CoinGecko بگیر
$__needCoins = [];
$__ncq = @$conn->query("SELECT DISTINCT currency FROM price_alerts WHERE is_active = 1 AND alert_type = 'crypto'");
if ($__ncq) {
    while ($__nc = $__ncq->fetch_assoc()) {
        $cid = strtolower($__nc['currency']);
        if ($cid !== '' && !isset($__cryptoPrices[$cid])) $__needCoins[$cid] = true;
    }
}
if (!empty($__needCoins) && function_exists('curl_init')) {
    $ids = implode(',', array_map('rawurlencode', array_keys($__needCoins)));
    $ch = curl_init("https://api.coingecko.com/api/v3/simple/price?ids=$ids&vs_currencies=usd");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>8, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_SSL_VERIFYPEER=>false]);
    $__raw = curl_exec($ch); curl_close($ch);
    if ($__raw) { $__j = json_decode($__raw, true); if (is_array($__j)) foreach ($__j as $cid=>$obj) if (isset($obj['usd'])) $__cryptoPrices[strtolower($cid)] = (float)$obj['usd']; }
}

// همه‌ی هشدارهایی که زمان بررسی‌شان رسیده
// نکته: مقایسه‌ی «آخرین ارسال» هم داخل SQL انجام می‌شود، نه با time() در PHP.
// اگر ساعت MySQL و PHP اختلاف داشته باشند (که روی بعضی هاست‌ها هست)،
// مقایسه‌ی PHP همیشه true می‌شد و اعلان‌ها برای همیشه مسدود می‌شدند.
$__alerts = @$conn->query("SELECT id, user_id, currency, target_price, direction, alert_type, coin_name,
                                  notify_telegram, notify_email, notify_toast,
                                  COALESCE(check_interval,60) AS check_interval, last_notified_at,
                                  (last_notified_at IS NULL
                                   OR last_notified_at <= DATE_SUB(NOW(), INTERVAL COALESCE(check_interval,60) MINUTE)
                                  ) AS notify_ok
                           FROM price_alerts
                           WHERE is_active = 1
                           LIMIT 200");
if (!$__alerts) return;

while ($al = $__alerts->fetch_assoc()) {
    $cur = strtoupper($al['currency']);
    $tp  = (float)$al['target_price'];
    $dir = $al['direction'] === 'below' ? 'below' : 'above';
    $type = $al['alert_type'] ?? 'ad';
    if (!in_array($type, ['ad','rate','crypto'], true)) $type = 'ad';
    $curEsc = $conn->real_escape_string($cur);
    $op  = $dir === 'below' ? '<=' : '>=';
    $found = null;

    if ($type === 'crypto') {
        // مقایسه با قیمت لحظه‌ای ارز دیجیتال (دلار)
        $cid = strtolower($al['currency']);
        if (isset($__cryptoPrices[$cid])) {
            $price = $__cryptoPrices[$cid];
            if (($dir === 'below' && $price <= $tp) || ($dir === 'above' && $price >= $tp)) $found = $price;
        }
    } elseif ($type === 'rate') {
        // مقایسه با نرخ لحظه‌ای ارز
        if (isset($__rates[$cur])) {
            $price = $__rates[$cur];
            if (($dir === 'below' && $price <= $tp) || ($dir === 'above' && $price >= $tp)) {
                $found = $price;
            }
        }
    } else {
        // مقایسه با قیمت آگهی‌های فعال بازار
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

    // زمان آخرین بررسی را همیشه به‌روز کن
    @$conn->query("UPDATE price_alerts SET last_checked_at = NOW() WHERE id = " . (int)$al['id']);

    if ($found === null) continue; // شرط برقرار نیست

    // جلوگیری از ارسال تکراری در همان بازه (محاسبه‌شده سمت MySQL)
    if ((int)($al['notify_ok'] ?? 1) !== 1) continue;

    $dirTxt = $dir === 'below' ? 'پایین‌تر از' : 'بالاتر از';
    if ($type === 'crypto') {
        $label = !empty($al['coin_name']) ? $al['coin_name'] : $cur;
        $title = '🔔 هشدار قیمت ارز دیجیتال';
        $body  = "$label به $dirTxt $" . rtrim(rtrim(number_format($tp, 4), '0'), '.') . " رسید.\n"
               . "قیمت لحظه‌ای: $" . rtrim(rtrim(number_format($found, 4), '0'), '.');
    } else {
        $title  = '🔔 هشدار قیمت';
        $srcTxt = $type === 'rate' ? 'نرخ لحظه‌ای' : 'قیمت آگهی بازار';
        $body   = "ارز $cur به $dirTxt " . number_format($tp) . " تومان رسید.\n"
                . "$srcTxt: " . number_format($found) . " تومان";
    }

    // ارسال از طریق کانال‌های انتخاب‌شده
    if (function_exists('notifyUser')) {
        @notifyUser($conn, (int)$al['user_id'], $title, $body, [
            'type'       => 'price_alert',
            'url'        => '/ledor/dashboard.php',
            'related_id' => (int)$al['id'],
            'telegram'  => (int)$al['notify_telegram'] === 1,
            'email'     => (int)$al['notify_email'] === 1,
            // توست داخل اپ = ذخیره در نوتیفیکیشن‌های دیتابیس تا در اپ دیده شود
            'db'        => (int)$al['notify_toast'] === 1,
            // push همیشه ارسال می‌شود (تنها کانال نمایش با اپِ بسته)
            'push'      => true,
            // ارسال فوری بدون وابستگی به صف
            'immediate' => true,
        ]);
    }

    // ثبت زمان اطلاع‌رسانی
    @$conn->query("UPDATE price_alerts SET last_notified_at = NOW(), triggered_at = NOW()
                   WHERE id = " . (int)$al['id']);
}

/* ------------------------------------------------------------------ */
/* fallback صف اعلان‌ها                                                */
/* ------------------------------------------------------------------ */
// ارسال واقعی اعلان‌ها وظیفه‌ی کران است. اینجا فقط چند مورد محدود را
// پردازش می‌کنیم تا اگر کران هنوز تنظیم نشده، سیستم از کار نیفتد.
//
// مهم: اعلان‌های کهنه (بیش از ۳۰ دقیقه) داخل processNotificationQueue
// منقضی می‌شوند، بنابراین با باز کردن اپ بعد از مدت طولانی، «رگبار
// اعلان قدیمی» دریافت نمی‌شود؛ فقط موارد تازه ارسال می‌شوند.
if (function_exists('processNotificationQueue')) {
    $__lastDrain = sys_get_temp_dir() . '/ava_queue_drain.txt';
    $__lastTs    = is_readable($__lastDrain) ? (int)@file_get_contents($__lastDrain) : 0;
    // حداکثر هر ۳۰ ثانیه یک‌بار و فقط ۳ اعلان در هر بار
    if (time() - $__lastTs >= 30) {
        @file_put_contents($__lastDrain, (string)time(), LOCK_EX);
        try { processNotificationQueue($conn, 3); } catch (\Throwable $e) {}
    }
}
