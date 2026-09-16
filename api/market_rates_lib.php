<?php
/**
 * api/market_rates_lib.php
 * ---------------------------------------------------------------------------
 * توابع واکشی نرخ از الان‌چند، جدا شده از api/market_rates.php تا بتوان آن‌ها را
 * از کران و ابزارهای دیگر (مثل «عکس نرخ لحظه‌ای») هم صدا زد، بدون اینکه
 * خروجی JSON آن اندپوینت اجرا شود.
 *
 * همه‌ی تعریف‌ها با function_exists محافظت شده‌اند تا اگر این فایل در کنار
 * api/market_rates.php بارگذاری شد، خطای redeclare ندهد.
 * ---------------------------------------------------------------------------
 */

if (!function_exists('mr_http')) {

/* ================= ابزارهای واکشی ================= */
function mr_http($url) {
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
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
    $GLOBALS['__mr_gold_html'] = $ghtml;   // برای استفاده‌ی مجدد (مثلاً نقره) بدون دانلود دوباره
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
    $GLOBALS['__mr_crypto_html'] = $chtml;   // برای استفاده‌ی مجدد (قیمت دلاری) بدون دانلود دوباره
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

/** آخرین HTMLِ صفحه‌ی طلا که mr_scrape گرفته است (بدون درخواست جدید) */
function mr_last_gold_html() {
    return isset($GLOBALS['__mr_gold_html']) ? $GLOBALS['__mr_gold_html'] : null;
}

/** آخرین HTMLِ صفحه‌ی ارز دیجیتال که mr_scrape گرفته است */
function mr_last_crypto_html() {
    return isset($GLOBALS['__mr_crypto_html']) ? $GLOBALS['__mr_crypto_html'] : null;
}

} // end function_exists guard
