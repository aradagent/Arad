<?php
/**
 * api/broadcast_ads_to_channel.php
 * ---------------------------------------------------------------
 * جمع‌آوری آگهی‌های فعال «تبادل ارزی» و ارسال آن‌ها به کانال تلگرام،
 * هر یک ساعت یک‌بار — فقط در صورت وجود آگهی فعال.
 *
 * روش اجرا (یکی از این دو):
 *   1) کرون‌جاب (پیشنهادی):
 *        0 * * * * curl -s "https://aradexchange.com/ledor/api/broadcast_ads_to_channel.php?key=YOUR_SECRET"
 *      یا با php-cli:
 *        0 * * * * php /path/to/ledor/api/broadcast_ads_to_channel.php
 *
 *   2) بدون کرون (self-scheduling): هر بار که این فایل صدا زده شود،
 *      اگر از آخرین ارسال کمتر از ۱ ساعت گذشته باشد، کاری نمی‌کند.
 *      می‌توانید آن را از footer یا یک درخواست دوره‌ای فرانت هم صدا بزنید.
 *
 * تنظیمات (channel_id و ...) در جدول app_settings ذخیره می‌شود و از
 * پنل ادمین قابل تغییر است.
 * ---------------------------------------------------------------
 */

require_once __DIR__ . '/../config/database.php';

// ------------------------------------------------------------------
// امنیت: اگر از طریق وب صدا زده شد، یک کلید ساده لازم است.
// در CLI نیازی به کلید نیست.
// ------------------------------------------------------------------
define('BROADCAST_SECRET', 'avapay_ads_2026'); // ← این را عوض کنید

$isCli = (PHP_SAPI === 'cli');
// اگر از داخل یک اسکریپت ادمین include شده باشد، این ثابت تعریف می‌شود و
// نیازی به بررسی کلید وب نیست.
$trustedInclude = defined('AVAPAY_TRUSTED_BROADCAST');

if (!$isCli && !$trustedInclude) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    $key = $_GET['key'] ?? '';
    if ($key !== BROADCAST_SECRET) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'forbidden']);
        exit();
    }
}

// ------------------------------------------------------------------
// جدول تنظیمات ساده (key/value)
// ------------------------------------------------------------------
$conn->query("CREATE TABLE IF NOT EXISTS `app_settings` (
    `k` VARCHAR(64) PRIMARY KEY,
    `v` TEXT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function setting_get($conn, $key, $default = null) {
    $stmt = $conn->prepare("SELECT v FROM app_settings WHERE k = ? LIMIT 1");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $r ? $r['v'] : $default;
}
function setting_set($conn, $key, $val) {
    $stmt = $conn->prepare("INSERT INTO app_settings (k, v) VALUES (?, ?)
                            ON DUPLICATE KEY UPDATE v = VALUES(v)");
    $stmt->bind_param("ss", $key, $val);
    $stmt->execute();
    $stmt->close();
}

// ------------------------------------------------------------------
// خواندن تنظیمات
// ------------------------------------------------------------------
// شناسه کانال: می‌تواند @channelusername یا -100xxxxxxxxxx باشد
$channelId       = trim((string) setting_get($conn, 'ads_channel_id', ''));
$broadcastEnabled = setting_get($conn, 'ads_broadcast_enabled', '1') === '1';
$lastSent        = intval(setting_get($conn, 'ads_last_broadcast_ts', '0'));
$intervalSeconds = 43200; // یک ساعت

$forced = (!$isCli && isset($_GET['force']) && $_GET['force'] == '1');

// اگر کانال تنظیم نشده باشد
if ($channelId === '') {
    $out = ['success' => false, 'message' => 'شناسه کانال تنظیم نشده است. از پنل ادمین → تنظیمات، آن را وارد کنید.'];
    if ($isCli) { echo json_encode($out, JSON_UNESCAPED_UNICODE) . "\n"; } else { echo json_encode($out, JSON_UNESCAPED_UNICODE); }
    exit();
}

if (!$broadcastEnabled) {
    $out = ['success' => false, 'message' => 'ارسال خودکار غیرفعال است.'];
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit();
}

// کنترل بازه یک‌ساعته (مگر force)
if (!$forced && (time() - $lastSent) < $intervalSeconds) {
    $remaining = $intervalSeconds - (time() - $lastSent);
    $out = ['success' => true, 'skipped' => true, 'message' => "هنوز یک ساعت نگذشته. {$remaining} ثانیه دیگر.", 'next_in_seconds' => $remaining];
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit();
}

// ------------------------------------------------------------------
// جمع‌آوری آگهی‌های فعال
// ------------------------------------------------------------------
$currencyLabels = [
    'USD'  => 'دلار',
    'EUR'  => 'یورو',
    'USDT' => 'تتر',
    'GBP'  => 'پوند',
    'AED'  => 'درهم',
    'TRY'  => 'لیر',
];

$sql = "SELECT type, currency, amount, price_per_unit
        FROM user_ads
        WHERE status = 'active'
        ORDER BY created_at DESC";
$res = $conn->query($sql);

$ads = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $ads[] = $row;
    }
}

// اگر هیچ آگهی فعالی نبود → چیزی ارسال نکن
if (count($ads) === 0) {
    // زمان آخرین بررسی را ثبت می‌کنیم تا هر ساعت فقط یک‌بار چک شود
    setting_set($conn, 'ads_last_broadcast_ts', (string) time());
    $out = ['success' => true, 'sent' => false, 'message' => 'آگهی فعالی برای ارسال وجود ندارد.'];
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit();
}

// ------------------------------------------------------------------
// ساخت متن پیام مطابق نمونهٔ درخواستی
// ------------------------------------------------------------------
$lines = [];
$lines[] = "❇️ آگهی‌های موجود در سوپر اپلیکیشن Ava Pay";
$lines[] = "";

foreach ($ads as $ad) {
    $isSell = ($ad['type'] === 'sell');
    $bullet = $isSell ? '🔺' : '🔹';
    $action = $isSell ? 'فروش' : 'خرید';

    $curCode  = strtoupper($ad['currency']);
    $curLabel = $currencyLabels[$curCode] ?? $curCode;

    // مقدار ارز: بدون اعشار اضافه اگر عدد صحیح باشد
    $amount = (float) $ad['amount'];
    $amountStr = ($amount == floor($amount))
        ? number_format($amount)
        : number_format($amount, 2);

    $priceStr = number_format((float) $ad['price_per_unit']);

    // مثال: 🔺فروش 453 یورو با قیمت 208,000 تومان
    $lines[] = "{$bullet}{$action} {$amountStr} {$curLabel} با قیمت {$priceStr} تومان";
}

$lines[] = "";
$lines[] = "❇️برای ارسال درخواست لطفا از طریق منو ربات گزینه \"<a href=\"https://aradexchange.com/ledor/\">Ava pay</a>\" رو انتخاب کرده و وارد ربات شوید ویا از طریق اپلیکیشن pwa نصب شده در موبایلتان اقدام کنید.";

$messageText = implode("\n", $lines);

// ------------------------------------------------------------------
// دکمهٔ شیشه‌ای (inline glass button) برای ورود به بخش تبادل ارزی
// ------------------------------------------------------------------
$inlineKeyboard = [
    'inline_keyboard' => [
        [
            [
                'text' => '💱 ورود به بخش تبادل ارزی',
                'url'  => 'https://aradexchange.com/ledor/arad.php'
            ]
        ]
    ]
];

// ------------------------------------------------------------------
// ارسال به کانال (مستقیم، بدون فوتر اضافه)
// ------------------------------------------------------------------
function sendChannelMessage($chatId, $text, $inlineKeyboard) {
    $botToken = BOT_TOKEN;
    if (empty($botToken)) return ['ok' => false, 'error' => 'no_bot_token'];

    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
        'reply_markup' => json_encode($inlineKeyboard),
    ];

    // ترجیحاً cURL، در نبود آن file_get_contents
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($resp === false) return ['ok' => false, 'error' => $err];
        $json = json_decode($resp, true);
        return ['ok' => (bool)($json['ok'] ?? false), 'raw' => $json];
    }

    $options = [
        'http' => [
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => http_build_query($data),
            'timeout' => 20,
        ]
    ];
    $context = stream_context_create($options);
    $resp = @file_get_contents($url, false, $context);
    if ($resp === false) return ['ok' => false, 'error' => 'request_failed'];
    $json = json_decode($resp, true);
    return ['ok' => (bool)($json['ok'] ?? false), 'raw' => $json];
}

$sendResult = sendChannelMessage($channelId, $messageText, $inlineKeyboard);

// ثبت زمان ارسال (چه موفق چه ناموفق، تا از اسپم جلوگیری شود)
setting_set($conn, 'ads_last_broadcast_ts', (string) time());

if ($sendResult['ok']) {
    setting_set($conn, 'ads_last_broadcast_status', 'ok');
    $out = [
        'success' => true,
        'sent' => true,
        'ads_count' => count($ads),
        'message' => 'آگهی‌ها با موفقیت به کانال ارسال شد.'
    ];
} else {
    setting_set($conn, 'ads_last_broadcast_status', 'error: ' . json_encode($sendResult['raw'] ?? $sendResult['error'] ?? 'unknown', JSON_UNESCAPED_UNICODE));
    $out = [
        'success' => false,
        'sent' => false,
        'ads_count' => count($ads),
        'message' => 'ارسال ناموفق بود. جزئیات در تنظیمات ثبت شد.',
        'error' => $sendResult['raw'] ?? $sendResult['error'] ?? 'unknown'
    ];
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
