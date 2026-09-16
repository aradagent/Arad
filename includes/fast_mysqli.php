<?php
/**
 * includes/fast_mysqli.php
 * ---------------------------------------------------------------
 * چرا این فایل لازم شد:
 * هر جای کد که برای «ارتباط بین تبادل‌ارزی (mainbot) و اپ AvaPay» یک
 * اتصالِ mysqli تازه به دیتابیسِ دیگری باز می‌کرد (۱۸ نقطه در کل پروژه)،
 * از الگوی ساده‌ی `new mysqli($host,$user,$pass,$db)` استفاده می‌کرد —
 * که یعنی اگر آن دیتابیسِ دیگر (لحظه‌ای) کند باشد، به حداکثرِ تعداد
 * اتصالش رسیده باشد، یا شبکه/سرویس مشکل داشته باشد، PHP تا سقفِ
 * پیش‌فرضِ سیستم (معمولاً ۶۰ ثانیه یا بیشتر) منتظر می‌ماند — و چون
 * تعداد پردازه‌های PHP-FPM محدود است، اگر چند درخواست هم‌زمان به این
 * نقاط بخورند، کلِ استخرِ workerها قفل می‌شود و کل سایت (نه فقط همان
 * درخواست) چند دقیقه از کار می‌افتد. این دقیقاً همان چیزی بود که علتِ
 * «هنگ‌کردنِ سرور موقع کانتکتِ تبادل‌ارزی و AvaPay» بود.
 *
 * این تابع یک مهلتِ اتصالِ صریح و کوتاه (پیش‌فرض ۳ ثانیه) می‌گذارد؛ اگر
 * دیتابیسِ مقصد در همان چند ثانیه جواب ندهد، به‌جای هنگ‌کردن، فوراً
 * false برمی‌گردد و کدِ صدازننده (که همه‌جا از قبل try/catch یا چکِ
 * connect_error دارد) می‌تواند بی‌صدا ادامه بدهد.
 * ---------------------------------------------------------------
 */

if (!function_exists('avapay_fast_mysqli')) {
    function avapay_fast_mysqli(string $host, string $user, string $pass, string $db, int $timeoutSec = 3, string $charset = 'utf8mb4') {
        try {
            $m = mysqli_init();
            if (!$m) return null;
            $m->options(MYSQLI_OPT_CONNECT_TIMEOUT, $timeoutSec);
            // اگر روی این هاست هم فعال باشد، از هنگ‌کردنِ خودِ کوئری‌ها (نه فقط
            // اتصال) هم جلوگیری می‌کند؛ اگر پشتیبانی نشود، بی‌ضرر نادیده گرفته می‌شود.
            if (defined('MYSQLI_OPT_READ_TIMEOUT')) { @$m->options(MYSQLI_OPT_READ_TIMEOUT, $timeoutSec + 5); }
            $ok = @$m->real_connect($host, $user, $pass, $db);
            if (!$ok) return null;
            $m->set_charset($charset);
            return $m;
        } catch (\Throwable $e) {
            error_log('avapay_fast_mysqli connect error: ' . $e->getMessage());
            return null;
        }
    }
}
