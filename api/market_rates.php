<?php
/**
 * api/market_rates.php
 * ------------------------------------------------------------------
 * نرخ لحظه‌ای بازار از alanchand.com — بدون iframe.
 *
 *   action=list    → همه‌ی نرخ‌ها (ارز / طلا و سکه / ارز دیجیتال) + واچ‌لیست کاربر
 *   action=watch   → افزودن/حذف یک نماد به واچ‌لیست  (POST: {code, category})
 *
 * قیمت‌ها در جدول market_rates کش می‌شوند و حداکثر هر ۱۸۰ ثانیه یک‌بار
 * از سایت مرجع تازه می‌شوند؛ بنابراین صفحه سریع باز می‌شود و در عین حال
 * داده‌ها تقریباً لحظه‌ای هستند.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/session_boot.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$userId = (int)($_SESSION['user_id'] ?? 0);
$action = $_GET['action'] ?? 'list';

/* ================= جدول کش نرخ‌ها ================= */
// اصلاح سرعت: این CREATE TABLE روی *هر* درخواست اجرا می‌شد و چون این فایل
// هر ۶۰ ثانیه توسط همه‌ی کاربران آنلاین (mrTimer در footer_menu.php) صدا
// زده می‌شود، یعنی این کوئری مدام برای هیچ‌کاری اجرا می‌شد.
require_once __DIR__ . '/../includes/perf_helpers.php';
if (avapay_throttled('market_rates_schema_ensure', 1800)) {
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS `market_rates` (
            `code`       VARCHAR(40) NOT NULL PRIMARY KEY,
            `name_fa`    VARCHAR(90) NOT NULL,
            `category`   VARCHAR(12) NOT NULL DEFAULT 'currency',
            `buy`        DECIMAL(22,2) DEFAULT 0,
            `sell`       DECIMAL(22,2) DEFAULT 0,
            `change_pct` DECIMAL(8,2)  DEFAULT 0,
            `unit`       VARCHAR(10)   DEFAULT 'toman',
            `sort_order` INT DEFAULT 100,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY `cat` (`category`)
        ) DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { error_log('market_rates table: ' . $e->getMessage()); }
}

/* ================= تاگل واچ‌لیست ================= */
if ($action === 'watch') {
    if ($userId <= 0) { http_response_code(401); echo json_encode(['error' => 'login required']); exit; }
    $in   = json_decode(file_get_contents('php://input'), true) ?: [];
    $code = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', (string)($in['code'] ?? '')));
    $cat  = preg_replace('/[^a-z]/', '', (string)($in['category'] ?? 'currency'));
    if ($code === '') { http_response_code(400); echo json_encode(['error' => 'bad code']); exit; }

    try {
        // ستون market در نسخه‌های قدیمی VARCHAR(10) است و مقادیر بلندتر در حالت
        // strict خطای «Data too long» می‌دهند → افزودن ارز دیجیتال شکست می‌خورد.
        try { $conn->query("ALTER TABLE `user_favorite_rates` MODIFY COLUMN `market` VARCHAR(20) NOT NULL DEFAULT 'fiat'"); } catch (Throwable $e) {}

        $st = $conn->prepare("SELECT id FROM user_favorite_rates WHERE user_id = ? AND currency = ?");
        $st->bind_param("is", $userId, $code); $st->execute();
        $exists = $st->get_result()->num_rows > 0; $st->close();

        if ($exists) {
            $st = $conn->prepare("DELETE FROM user_favorite_rates WHERE user_id = ? AND currency = ?");
            $st->bind_param("is", $userId, $code); $st->execute(); $st->close();
            echo json_encode(['success' => true, 'watched' => false]);
        } else {
            // مقدار کوتاه ('crypto') تا در هر عرض ستونی جا شود و با محدودیت
            // ۴تایی کارت «بازار» داشبورد (که market='cryptomarket' را می‌شمارد) تداخل نکند
            $market = ($cat === 'crypto') ? 'crypto' : 'fiat';
            $st = $conn->prepare("INSERT INTO user_favorite_rates (user_id, currency, market) VALUES (?,?,?)");
            $st->bind_param("iss", $userId, $code, $market); $st->execute(); $st->close();
            echo json_encode(['success' => true, 'watched' => true]);
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'db', 'message' => $e->getMessage()]);
    }
    exit;
}

/* ================= ابزارهای واکشی ================= */
function mr_http($url) {
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122 Safari/537.36',
        CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: fa,en;q=0.8'],
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code >= 200 && $code < 300 && $res) ? $res : null;
}

function mr_num($s) {
    $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $ar = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    $en = ['0','1','2','3','4','5','6','7','8','9'];
    return str_replace($ar, $en, str_replace($fa, $en, $s));
}

function mr_text($html) {
    $t = preg_replace('/<(script|style)[^>]*>.*?<\/\1>/is', ' ', $html);
    $t = preg_replace('/<[^>]+>/', ' ', $t);
    $t = html_entity_decode($t, ENT_QUOTES, 'UTF-8');
    $t = preg_replace('/[ \t\x{00A0}]+/u', ' ', $t);
    return $t;
}

/** فهرست ارزهای صفحه‌ی الان‌چند: کد => [نام فارسی, تعداد واحد در ردیف, ترتیب] */
function mr_currency_map() {
    return [
        'USD' => ['دلار آمریکا', 1, 1],   'EUR' => ['یورو', 1, 2],
        'GBP' => ['پوند انگلیس', 1, 3],   'AED' => ['درهم', 1, 4],
        'TRY' => ['لیر ترکیه', 1, 5],     'CAD' => ['دلار کانادا', 1, 6],
        'AUD' => ['دلار استرالیا', 1, 7], 'CHF' => ['فرانک سوئیس', 1, 8],
        'CNY' => ['یوان چین', 1, 9],      'JPY' => ['صد ین ژاپن', 100, 10],
        'RUB' => ['روبل روسیه', 1, 11],   'IQD' => ['صد دینار عراق', 100, 12],
        'SAR' => ['ریال عربستان', 1, 13], 'QAR' => ['ریال قطر', 1, 14],
        'KWD' => ['دینار کویت', 1, 15],   'BHD' => ['دینار بحرین', 1, 16],
        'OMR' => ['ریال عمان', 1, 17],    'INR' => ['روپیه هند', 1, 18],
        'PKR' => ['روپیه پاکستان', 1, 19],'AFN' => ['افغانی', 1, 20],
        'MYR' => ['رینگیت مالزی', 1, 21], 'THB' => ['بات تایلند', 1, 22],
        'SGD' => ['دلار سنگاپور', 1, 23], 'HKD' => ['دلار هنگ کنگ', 1, 24],
        'KRW' => ['صد وون کره جنوبی', 100, 25], 'SEK' => ['کرون سوئد', 1, 26],
        'NOK' => ['کرون نروژ', 1, 27],    'DKK' => ['کرون دانمارک', 1, 28],
        'NZD' => ['دلار نیوزلند', 1, 29], 'GEL' => ['لاری گرجستان', 1, 30],
        'AZN' => ['منات آذربایجان', 1, 31], 'AMD' => ['صد درام ارمنستان', 100, 32],
    ];
}

/** طلا و سکه: کد => [نام فارسی, واحد, ترتیب] */
function mr_gold_map() {
    return [
        'COIN_EMAMI'   => ['سکه امامی (طرح جدید)', 'toman', 1],
        'COIN_BAHAR'   => ['سکه بهار آزادی (طرح قدیم)', 'toman', 2],
        'COIN_HALF'    => ['نیم سکه', 'toman', 3],
        'COIN_QUARTER' => ['ربع سکه', 'toman', 4],
        'COIN_GRAM'    => ['سکه گرمی', 'toman', 5],
        'GOLD_18'      => ['گرم طلای 18 عیار', 'toman', 6],
        'GOLD_24'      => ['گرم طلای 24 عیار', 'toman', 7],
        'GOLD_MESGHAL' => ['آبشده(مثقال طلا)', 'toman', 8],
        'GOLD_OUNCE'   => ['انس طلا', 'usd', 9],
    ];
}

/** ارزهای دیجیتال: کد => [نام فارسی, نماد, ترتیب] */
function mr_crypto_map() {
    return [
        'BTC'  => ['بیت کوین', 'Bitcoin', 1],   'ETH'  => ['اتریوم', 'Ethereum', 2],
        'USDT' => ['تتر', 'Tether', 3],          'BNB'  => ['بایننس کوین', 'BNB', 4],
        'XRP'  => ['ریپل', 'XRP', 5],            'SOL'  => ['سولانا', 'Solana', 6],
        'ADA'  => ['کاردانو', 'Cardano', 7],     'DOGE' => ['دوج کوین', 'Dogecoin', 8],
        'TRX'  => ['ترون', 'TRON', 9],           'TON'  => ['تون کوین', 'Toncoin', 10],
        'AVAX' => ['آوالانچ', 'Avalanche', 11],  'DOT'  => ['پولکادات', 'Polkadot', 12],
        'MATIC'=> ['پالیگان', 'Polygon', 13],    'SHIB' => ['شیبا اینو', 'Shiba', 14],
        'LTC'  => ['لایت کوین', 'Litecoin', 15], 'LINK' => ['چین لینک', 'Chainlink', 16],
    ];
}

/* ================= واکشی از الان‌چند ================= */
function mr_scrape() {
    $rows = [];

    // ---------- ارزها (خرید و فروش) ----------
    $html = mr_http('https://alanchand.com/currencies-price');
    if ($html) {
        $text = mr_num(mr_text($html));
        foreach (mr_currency_map() as $code => $m) {
            [$fa, $per, $ord] = $m;
            $per = max(1, (int)$per);
            // نام ارز، سپس دو عدد: خرید و فروش
            $pat = '/(?<!\S)' . preg_quote($fa, '/') . '\s+([0-9][0-9,\.]{1,})\s+([0-9][0-9,\.]{1,})/u';
            if (preg_match($pat, $text, $mm)) {
                $buy  = (float)str_replace(',', '', $mm[1]);
                $sell = (float)str_replace(',', '', $mm[2]);
                if ($sell > 0) {
                    $rows[] = [
                        'code' => $code, 'name' => $fa, 'cat' => 'currency',
                        'buy'  => round($per > 1 ? $buy / $per : $buy, 2),
                        'sell' => round($per > 1 ? $sell / $per : $sell, 2),
                        'chg'  => 0.0, 'unit' => 'toman', 'ord' => $ord,
                    ];
                }
            }
        }
    }

    // ---------- طلا و سکه ----------
    $ghtml = mr_http('https://alanchand.com/gold-price');
    if ($ghtml) {
        $gtext = mr_num(mr_text($ghtml));
        foreach (mr_gold_map() as $code => $m) {
            [$fa, $unit, $ord] = $m;
            $q = preg_quote($fa, '/');
            $buy = $sell = 0.0; $chg = 0.0;

            if ($unit === 'usd') {
                if (preg_match('/' . $q . '\s+([0-9][0-9,\.]*)\s*\$\s*(-?[0-9\.]+)?/us', $gtext, $mm)) {
                    $sell = $buy = (float)str_replace(',', '', $mm[1]);
                    $chg  = isset($mm[2]) ? (float)$mm[2] : 0.0;
                }
            } else {
                // حالت دو قیمتی (خرید و فروش)
                if (preg_match('/' . $q . '\s+([0-9][0-9,]{3,})\s+([0-9][0-9,]{3,})/us', $gtext, $mm)) {
                    $buy  = (float)str_replace(',', '', $mm[1]);
                    $sell = (float)str_replace(',', '', $mm[2]);
                } elseif (preg_match('/' . $q . '\s+([0-9][0-9,]{3,})\s*تومان\s*(-?[0-9\.]+)?/us', $gtext, $mm)) {
                    $sell = $buy = (float)str_replace(',', '', $mm[1]);
                    $chg  = isset($mm[2]) ? (float)$mm[2] : 0.0;
                }
            }

            if ($sell > 0) {
                $rows[] = [
                    'code' => $code, 'name' => $fa, 'cat' => 'gold',
                    'buy' => $buy ?: $sell, 'sell' => $sell,
                    'chg' => $chg, 'unit' => $unit, 'ord' => $ord,
                ];
            }
        }
    }

    // ---------- ارز دیجیتال ----------
    $chtml = mr_http('https://alanchand.com/crypto-price');
    if ($chtml) {
        $ctext = mr_num(mr_text($chtml));
        foreach (mr_crypto_map() as $code => $m) {
            [$fa, $sym, $ord] = $m;
            $price = 0.0; $chg = 0.0;

            // «نام فارسی ... قیمت تومان» یا «SYMBOL ... قیمت تومان»
            if (preg_match('/' . preg_quote($fa, '/') . '.{0,60}?([0-9][0-9,\.]{2,})\s*تومان\s*(-?[0-9\.]+)?/us', $ctext, $mm)) {
                $price = (float)str_replace(',', '', $mm[1]);
                $chg   = isset($mm[2]) ? (float)$mm[2] : 0.0;
            } elseif (preg_match('/(?<![A-Z])' . $code . '(?![A-Z]).{0,60}?([0-9][0-9,\.]{2,})\s*تومان/us', $ctext, $mm)) {
                $price = (float)str_replace(',', '', $mm[1]);
            }

            if ($price > 0) {
                $rows[] = [
                    'code' => $code, 'name' => $fa, 'cat' => 'crypto',
                    'buy' => $price, 'sell' => $price,
                    'chg' => $chg, 'unit' => 'toman', 'ord' => $ord,
                ];
            }
        }
    }

    return $rows;
}

/* ================= کش: تازه‌سازی حداکثر هر ۱۸۰ ثانیه ================= */
$force   = isset($_GET['force']) && $_GET['force'] === '1';
$lastUpd = null;
try {
    $q = $conn->query("SELECT MAX(updated_at) AS m, COUNT(*) AS c FROM market_rates");
    if ($q && ($r = $q->fetch_assoc())) {
        $lastUpd = $r['m'];
        if ((int)$r['c'] === 0) $force = true;
    }
} catch (Throwable $e) {}

$needsSync = $force || !$lastUpd || (strtotime($lastUpd) < time() - 180);

if ($needsSync) {
    try {
        $rows = mr_scrape();
        if (!empty($rows)) {
            $st = $conn->prepare(
                "INSERT INTO market_rates (code, name_fa, category, buy, sell, change_pct, unit, sort_order, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,NOW())
                 ON DUPLICATE KEY UPDATE name_fa=VALUES(name_fa), category=VALUES(category),
                     buy=VALUES(buy), sell=VALUES(sell), change_pct=VALUES(change_pct),
                     unit=VALUES(unit), sort_order=VALUES(sort_order), updated_at=NOW()"
            );
            if ($st) {
                foreach ($rows as $r) {
                    $st->bind_param("sssdddsi", $r['code'], $r['name'], $r['cat'],
                        $r['buy'], $r['sell'], $r['chg'], $r['unit'], $r['ord']);
                    $st->execute();
                }
                $st->close();
            }
        }
    } catch (Throwable $e) {
        error_log('market_rates sync: ' . $e->getMessage());
    }
}

/* ================= خروجی ================= */
$items = [];
try {
    $q = $conn->query("SELECT code, name_fa, category, buy, sell, change_pct, unit,
                              UNIX_TIMESTAMP(updated_at) AS ts
                       FROM market_rates ORDER BY category, sort_order, code");
    if ($q) while ($r = $q->fetch_assoc()) {
        $items[] = [
            'code'     => $r['code'],
            'name'     => $r['name_fa'],
            'category' => $r['category'],
            'buy'      => (float)$r['buy'],
            'sell'     => (float)$r['sell'],
            'change'   => (float)$r['change_pct'],
            'unit'     => $r['unit'],
            'ts'       => (int)$r['ts'],
        ];
    }
} catch (Throwable $e) {}

$watch = [];
if ($userId > 0) {
    try {
        $st = $conn->prepare("SELECT currency FROM user_favorite_rates WHERE user_id = ?");
        $st->bind_param("i", $userId); $st->execute();
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) $watch[] = $r['currency'];
        $st->close();
    } catch (Throwable $e) {}
}

echo json_encode([
    'success'  => true,
    'items'    => $items,
    'watch'    => $watch,
    'updated'  => $lastUpd,
    'synced'   => $needsSync,
], JSON_UNESCAPED_UNICODE);
