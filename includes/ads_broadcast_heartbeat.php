<?php
/**
 * includes/ads_broadcast_heartbeat.php
 * ---------------------------------------------------------------
 * ضربان‌ساز (heartbeat) بدون نیاز به کرون.
 *
 * این فایل را در انتهای صفحاتی که کاربران زیاد باز می‌کنند
 * (مثل arad.php و dashboard.php) include کنید. هر بار که با احتمال کم
 * انتخاب شود، یک درخواست «آتش‌کن و فراموش‌کن» به اسکریپت ارسال می‌فرستد.
 * خود آن اسکریپت بازهٔ یک‌ساعته را کنترل می‌کند، پس اگر هنوز یک ساعت
 * نگذشته یا آگهی فعالی نباشد چیزی ارسال نمی‌شود.
 *
 * چون درخواست جداگانه است، exit() داخل اسکریپت ارسال هیچ تأثیری روی
 * خروجی صفحهٔ فعلی ندارد.
 *
 * نیازمند وجود $conn فعال در scope فراخوانی.
 * ---------------------------------------------------------------
 */

if (!isset($conn) || !($conn instanceof mysqli)) {
    return;
}

// فقط گاهی بررسی کن تا سربار هر رکوئست ناچیز بماند (حدود ۱ از هر ۸)
if (mt_rand(1, 8) !== 1) {
    return;
}

// آیا کانالی تنظیم شده، فعال است و بیش از یک ساعت گذشته؟ (یک کوئری سبک)
$row = null;
$res = @$conn->query("SELECT
        MAX(CASE WHEN k='ads_channel_id' THEN v END) AS ch,
        MAX(CASE WHEN k='ads_broadcast_enabled' THEN v END) AS en,
        MAX(CASE WHEN k='ads_last_broadcast_ts' THEN v END) AS ts
    FROM app_settings
    WHERE k IN ('ads_channel_id','ads_broadcast_enabled','ads_last_broadcast_ts')");
if ($res) {
    $row = $res->fetch_assoc();
}
if (!$row) {
    return;
}

$ch = trim((string)($row['ch'] ?? ''));
$en = ($row['en'] ?? '1') === '1';
$ts = intval($row['ts'] ?? 0);

if ($ch === '' || !$en || (time() - $ts) < 3600) {
    return; // چیزی برای انجام نیست
}

// درخواست fire-and-forget به اسکریپت ارسال (سکرت سمت سرور، در دید کاربر نیست)
$secret = 'avapay_ads_2026'; // باید با BROADCAST_SECRET یکسان باشد

$scheme = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
) ? 'https' : 'http';

$host = $_SERVER['HTTP_HOST'] ?? 'aradexchange.com';

$basePath = '/ledor';
if (!empty($_SERVER['SCRIPT_NAME'])) {
    $dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    if ($dir !== '') $basePath = $dir;
}

$url = $scheme . '://' . $host . $basePath . '/api/broadcast_ads_to_channel.php?key=' . urlencode($secret);

if (function_exists('curl_init')) {
    $ch2 = curl_init($url);
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS     => 300,
        CURLOPT_NOSIGNAL       => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    @curl_exec($ch2);
    @curl_close($ch2);
} else {
    $ctx = stream_context_create(['http' => ['timeout' => 1, 'method' => 'GET']]);
    @file_get_contents($url, false, $ctx);
}
