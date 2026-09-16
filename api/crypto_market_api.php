<?php
// گاردِ سراسری مسدودیت: حتی این API فقط-خواندنی هم نباید به کاربر مسدود پاسخ دهد.
require_once __DIR__ . '/../config/database.php';
/**
 * api/crypto_market_api.php
 * ----------------------------------------------------------------------------
 * منبع داده‌ی بازار ارز دیجیتال برای داشبورد.
 *
 * داده‌ها از CoinGecko (که همان داده‌های CoinMarketCap را پوشش می‌دهد) به‌صورت
 * سمت‌سرور دریافت و برای ~۵۵ ثانیه کش می‌شود تا فشار روی API کم شود و فرانت‌اند
 * بتواند هر دقیقه به‌روزرسانی کند.
 *
 * خروجی:
 *   {
 *     success: true,
 *     updated_at: 1699999999,
 *     market:  [ {id,name,symbol,image,price,change24h,rank} , ... ],   // بازار (بر اساس ارزش بازار)
 *     gainers: [ {id,name,symbol,image,price,change24h}       , ... ]   // بیشترین رشد ۲۴ ساعت
 *   }
 * ----------------------------------------------------------------------------
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
error_reporting(E_ALL);
ini_set('display_errors', '0');

// ---- تنظیمات ----
$CACHE_FILE = sys_get_temp_dir() . '/ava_crypto_market_cache.json';
$CACHE_TTL  = 55; // ثانیه (کمی کمتر از ۱ دقیقه تا هر بار درخواست تازه بگیرد)

/**
 * دریافت URL با cURL (و در نبود cURL با file_get_contents).
 */
function ava_http_get($url) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'AvaPay-Dashboard/1.0 (+crypto-market)',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body !== false && $code >= 200 && $code < 300) return $body;
        return false;
    }
    // fallback
    $ctx = stream_context_create(['http' => [
        'timeout' => 12,
        'header'  => "Accept: application/json\r\nUser-Agent: AvaPay-Dashboard/1.0\r\n",
    ], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $body = @file_get_contents($url, false, $ctx);
    return $body !== false ? $body : false;
}

/**
 * دریافت داده‌ی بازار از CoinGecko و ساخت خروجی نهایی.
 */
function ava_fetch_crypto() {
    // ۱۰۰ ارز برتر بازار همراه با تغییر ۲۴ ساعته
    $url = 'https://api.coingecko.com/api/v3/coins/markets'
         . '?vs_currency=usd&order=market_cap_desc&per_page=100&page=1'
         . '&price_change_percentage=24h&sparkline=false';

    $raw = ava_http_get($url);
    if ($raw === false) return null;

    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data)) return null;

    $all = [];
    foreach ($data as $c) {
        if (!isset($c['id'])) continue;
        $chg = $c['price_change_percentage_24h'];
        if ($chg === null && isset($c['price_change_percentage_24h_in_currency'])) {
            $chg = $c['price_change_percentage_24h_in_currency'];
        }
        $all[] = [
            'id'        => (string)$c['id'],
            'name'      => (string)($c['name'] ?? ''),
            'symbol'    => strtoupper((string)($c['symbol'] ?? '')),
            'image'     => (string)($c['image'] ?? ''),
            'price'     => is_numeric($c['current_price'] ?? null) ? (float)$c['current_price'] : null,
            'change24h' => is_numeric($chg) ? round((float)$chg, 2) : 0.0,
            'rank'      => (int)($c['market_cap_rank'] ?? 0),
        ];
    }
    if (empty($all)) return null;

    // بازار: ۸ ارز برتر بر اساس ارزش بازار
    $market = array_slice($all, 0, 8);

    // بیشترین رشد ۲۴ ساعت: مرتب‌سازی نزولی بر اساس درصد تغییر
    $gainers = $all;
    usort($gainers, function ($a, $b) {
        return $b['change24h'] <=> $a['change24h'];
    });
    // فقط ارزهایی که رشد مثبت داشته‌اند (اگر همه منفی بودند، ۶ تای اول را می‌دهیم)
    $positive = array_values(array_filter($gainers, function ($x) { return $x['change24h'] > 0; }));
    $gainers  = array_slice(!empty($positive) ? $positive : $gainers, 0, 8);

    return [
        'success'    => true,
        'updated_at' => time(),
        'market'     => $market,
        'gainers'    => $gainers,
    ];
}

/* ============================================================================
 * اکشن history: نمودار تاریخی یک ارز برای مدال (۲۴ ساعت / چند روز)
 *   ورودی:  ?action=history&id=bitcoin&days=1|7|30
 *   خروجی:  { success, id, days, points:[ {t: ms, p: price}, ... ] }
 * ========================================================================== */
if (isset($_GET['action']) && $_GET['action'] === 'history') {
    $id   = preg_replace('/[^a-z0-9\-]/i', '', (string)($_GET['id'] ?? ''));
    $days = (int)($_GET['days'] ?? 1);
    if (!in_array($days, [1, 7, 30, 90], true)) $days = 1;
    if ($id === '') { echo json_encode(['success'=>false,'message'=>'id لازم است']); exit; }

    $hCache = sys_get_temp_dir() . '/ava_crypto_hist_' . $id . '_' . $days . '.json';
    $hTtl   = $days == 1 ? 120 : 900; // ۲۴ ساعت: ۲ دقیقه؛ بازه‌های بلندتر: ۱۵ دقیقه

    if (is_readable($hCache)) {
        $c = json_decode(@file_get_contents($hCache), true);
        if (is_array($c) && isset($c['_ts']) && (time() - (int)$c['_ts']) < $hTtl) {
            unset($c['_ts']); $c['cached'] = true;
            echo json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
        }
    }

    $url = 'https://api.coingecko.com/api/v3/coins/' . rawurlencode($id)
         . '/market_chart?vs_currency=usd&days=' . $days;
    // برای ۲۴ ساعت، گرانولاریتی خودکار CoinGecko داده‌ی ۵ دقیقه‌ای می‌دهد
    $raw = ava_http_get($url);
    $out = ['success'=>false, 'id'=>$id, 'days'=>$days, 'points'=>[]];

    if ($raw !== false) {
        $j = json_decode($raw, true);
        if (isset($j['prices']) && is_array($j['prices'])) {
            $pts = [];
            foreach ($j['prices'] as $row) {
                if (!isset($row[0], $row[1])) continue;
                $pts[] = ['t' => (float)$row[0], 'p' => (float)$row[1]];
            }
            $out['success'] = !empty($pts);
            $out['points']  = $pts;
        }
    }

    if ($out['success']) {
        $store = $out; $store['_ts'] = time();
        @file_put_contents($hCache, json_encode($store, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    } elseif (is_readable($hCache)) {
        $c = json_decode(@file_get_contents($hCache), true);
        if (is_array($c)) { unset($c['_ts']); $c['stale'] = true; echo json_encode($c, JSON_UNESCAPED_UNICODE); exit; }
    }

    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ============================================================================
 * اکشن feargreed: شاخص ترس و طمع بازار کریپتو (از Alternative.me)
 *   خروجی: { success, value:0..100, label:'...', label_fa:'...' }
 * ========================================================================== */
if (isset($_GET['action']) && $_GET['action'] === 'feargreed') {
    $fgCache = sys_get_temp_dir() . '/ava_crypto_feargreed.json';
    $fgTtl   = 1800; // نیم‌ساعت (این شاخص روزانه به‌روز می‌شود)

    if (is_readable($fgCache)) {
        $c = json_decode(@file_get_contents($fgCache), true);
        if (is_array($c) && isset($c['_ts']) && (time() - (int)$c['_ts']) < $fgTtl) {
            unset($c['_ts']); $c['cached'] = true;
            echo json_encode($c, JSON_UNESCAPED_UNICODE); exit;
        }
    }

    $raw = ava_http_get('https://api.alternative.me/fng/?limit=1');
    $out = ['success' => false];
    if ($raw !== false) {
        $j = json_decode($raw, true);
        if (isset($j['data'][0]['value'])) {
            $val = (int)$j['data'][0]['value'];
            $lbl = (string)($j['data'][0]['value_classification'] ?? '');
            // ترجمه‌ی برچسب به فارسی
            $map = [
                'Extreme Fear'  => 'ترس شدید',
                'Fear'          => 'ترس',
                'Neutral'       => 'خنثی',
                'Greed'         => 'طمع',
                'Extreme Greed' => 'طمع شدید',
            ];
            $out = ['success' => true, 'value' => $val, 'label' => $lbl, 'label_fa' => $map[$lbl] ?? $lbl];
        }
    }

    if ($out['success']) {
        $store = $out; $store['_ts'] = time();
        @file_put_contents($fgCache, json_encode($store, JSON_UNESCAPED_UNICODE), LOCK_EX);
    } elseif (is_readable($fgCache)) {
        $c = json_decode(@file_get_contents($fgCache), true);
        if (is_array($c)) { unset($c['_ts']); $c['stale'] = true; echo json_encode($c, JSON_UNESCAPED_UNICODE); exit; }
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- کش: اگر تازه است، همان را بده ----
$now = time();
if (is_readable($CACHE_FILE)) {
    $cached = json_decode(@file_get_contents($CACHE_FILE), true);
    if (is_array($cached) && isset($cached['updated_at'])
        && ($now - (int)$cached['updated_at']) < $CACHE_TTL) {
        $cached['cached'] = true;
        echo json_encode($cached, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// ---- دریافت تازه ----
$fresh = ava_fetch_crypto();

if ($fresh === null) {
    // در صورت خطا، اگر کش قدیمی داریم همان را (با علامت stale) بده
    if (is_readable($CACHE_FILE)) {
        $cached = json_decode(@file_get_contents($CACHE_FILE), true);
        if (is_array($cached) && isset($cached['market'])) {
            $cached['stale'] = true;
            echo json_encode($cached, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }
    echo json_encode([
        'success' => false,
        'message' => 'عدم دسترسی به سرویس قیمت‌ها',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ذخیره‌ی کش (بی‌صدا اگر نشد)
@file_put_contents($CACHE_FILE, json_encode($fresh, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

echo json_encode($fresh, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
