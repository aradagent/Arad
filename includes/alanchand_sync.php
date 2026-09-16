<?php
/**
 * همگام‌سازی نرخ ارزها از سایت alanchand.com
 * ------------------------------------------------------------------
 * - قیمت‌ها به‌صورت لحظه‌ای از صفحه‌ی فارسی الان‌چند اسکن می‌شوند.
 * - فقط «قیمت فروش» هر ارز ذخیره می‌شود (ستون سوم جدول سایت).
 * - همه‌ی ارزهای اصلی صفحه‌ی الان‌چند اسکن می‌شوند تا کاربر بتواند هرکدام را
 *   به «نرخ‌های مورد علاقه» اضافه کند. تتر از صفحه‌ی ارز دیجیتال گرفته می‌شود.
 * - قیمت‌های صفحه‌ی فارسی الان‌چند به «تومان» است، دقیقاً هم‌مقیاس با اپ.
 * - بدون کرون: هر بار داشبورد باز شود و بیش از ۵ دقیقه از آخرین آپدیت
 *   گذشته باشد، اجرا می‌شود. در صورت خطای شبکه، مقادیر قبلی حفظ می‌شوند.
 */

if (!function_exists('ava_alanchand_currency_map')) {
    /** نگاشت کد ارز → نام دقیق فارسی در جدول الان‌چند + ضریب واحد (۱۰۰تایی‌ها) */
    function ava_alanchand_currency_map() {
        // 'code' => ['fa' => نام فارسی, 'per' => تعداد واحد در ردیف (۱ یا ۱۰۰)]
        return [
            'USD' => ['fa' => 'دلار آمریکا',   'per' => 1],
            'EUR' => ['fa' => 'یورو',           'per' => 1],
            'AED' => ['fa' => 'درهم',           'per' => 1],
            'TRY' => ['fa' => 'لیر ترکیه',      'per' => 1],
            'GBP' => ['fa' => 'پوند انگلیس',    'per' => 1],
            'CNY' => ['fa' => 'یوان چین',       'per' => 1],
            'CAD' => ['fa' => 'دلار کانادا',    'per' => 1],
            'AUD' => ['fa' => 'دلار استرالیا',  'per' => 1],
            'RUB' => ['fa' => 'روبل روسیه',     'per' => 1],
            'IQD' => ['fa' => 'صد دینار عراق',  'per' => 100],
            'MYR' => ['fa' => 'رینگیت مالزی',   'per' => 1],
            'GEL' => ['fa' => 'لاری گرجستان',   'per' => 1],
            'AZN' => ['fa' => 'منات آذربایجان', 'per' => 1],
            'AMD' => ['fa' => 'صد درام ارمنستان','per' => 100],
            'THB' => ['fa' => 'بات تایلند',     'per' => 1],
            'OMR' => ['fa' => 'ریال عمان',      'per' => 1],
            'INR' => ['fa' => 'روپیه هند',      'per' => 1],
            'PKR' => ['fa' => 'روپیه پاکستان',  'per' => 1],
            'JPY' => ['fa' => 'صد ین ژاپن',     'per' => 100],
            'SAR' => ['fa' => 'ریال عربستان',   'per' => 1],
            'AFN' => ['fa' => 'افغانی',         'per' => 1],
            'SEK' => ['fa' => 'کرون سوئد',      'per' => 1],
            'CHF' => ['fa' => 'فرانک سوئیس',    'per' => 1],
            'QAR' => ['fa' => 'ریال قطر',       'per' => 1],
            'KRW' => ['fa' => 'صد وون کره جنوبی','per' => 100],
            'NOK' => ['fa' => 'کرون نروژ',      'per' => 1],
            'NZD' => ['fa' => 'دلار نیوزلند',   'per' => 1],
            'SGD' => ['fa' => 'دلار سنگاپور',   'per' => 1],
            'HKD' => ['fa' => 'دلار هنگ کنگ',   'per' => 1],
            'KWD' => ['fa' => 'دینار کویت',     'per' => 1],
            'DKK' => ['fa' => 'کرون دانمارک',   'per' => 1],
            'BHD' => ['fa' => 'دینار بحرین',    'per' => 1],
        ];
    }
}

if (!function_exists('ava_alanchand_fetch')) {

    function ava_alanchand_http($url) {
        if (!function_exists('curl_init')) return null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_ENCODING       => '',   // اجازه‌ی gzip
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122 Safari/537.36',
            CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: fa,en;q=0.8'],
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($code >= 200 && $code < 300 && $res) ? $res : null;
    }

    /** تبدیل ارقام فارسی/عربی به انگلیسی */
    function ava_fa_to_en_num($s) {
        $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
        $ar = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        $en = ['0','1','2','3','4','5','6','7','8','9'];
        return str_replace($ar, $en, str_replace($fa, $en, $s));
    }

    /** حذف تگ‌های HTML و فشرده‌سازی فاصله‌ها */
    function ava_strip($html) {
        $t = preg_replace('/<(script|style)[^>]*>.*?<\/\1>/is', ' ', $html);
        $t = preg_replace('/<[^>]+>/', ' ', $t);
        $t = html_entity_decode($t, ENT_QUOTES, 'UTF-8');
        $t = preg_replace('/[ \t\x{00A0}]+/u', ' ', $t);
        return $t;
    }

    /**
     * اسکن نرخ ارزها.
     * خروجی: ['USD'=>['price'=>..,'change'=>..], ...] — فقط «قیمت فروش».
     */
    function ava_alanchand_fetch() {
        $out    = [];
        $curMap = ava_alanchand_currency_map();

        // ---- صفحه‌ی ارزها ----
        $html = ava_alanchand_http('https://alanchand.com/currencies-price');
        if ($html) {
            $text = ava_fa_to_en_num(ava_strip($html));
            foreach ($curMap as $code => $meta) {
                $fa  = $meta['fa'];
                $per = max(1, (int)$meta['per']);
                // نام ارز، سپس دو عدد (خرید و فروش). عدد دوم = قیمت فروش.
                $pattern = '/(?<!\S)' . preg_quote($fa, '/')
                         . '\s+([0-9][0-9,\.]{1,})\s+([0-9][0-9,\.]{1,})/u';
                if (preg_match($pattern, $text, $mm)) {
                    $sell = (float)str_replace(',', '', $mm[2]); // قیمت فروش (تومان)
                    if ($sell > 0) {
                        // ردیف‌های ۱۰۰تایی → قیمت هر واحد
                        $unit = $per > 1 ? $sell / $per : $sell;
                        $out[$code] = ['price' => round($unit, 2), 'change' => 0.0];
                    }
                }
            }
        }

        // ---- صفحه‌ی ارز دیجیتال برای تتر ----
        $chtml = ava_alanchand_http('https://alanchand.com/crypto-price');
        if ($chtml) {
            $ctext = ava_fa_to_en_num(ava_strip($chtml));
            if (preg_match('/تتر.{0,40}?USDT.{0,40}?([0-9][0-9,\.]{2,})\s*تومان\s*([0-9\.]+)?\s*%?/us', $ctext, $mm)) {
                $price = (float)str_replace(',', '', $mm[1]);
                $chg   = isset($mm[2]) ? (float)$mm[2] : 0.0;
                if ($price > 0) $out['USDT'] = ['price' => $price, 'change' => $chg];
            } elseif (preg_match('/USDT.{0,60}?([0-9][0-9,]{2,})\s*تومان/us', $ctext, $mm)) {
                $price = (float)str_replace(',', '', $mm[1]);
                if ($price > 0) $out['USDT'] = ['price' => $price, 'change' => 0.0];
            }
        }

        // ---- صفحه‌ی طلا و سکه ----
        $ghtml = ava_alanchand_http('https://alanchand.com/gold-price');
        if ($ghtml) {
            $gtext = ava_fa_to_en_num(ava_strip($ghtml));
            // نگاشت کد → نام فارسی + واحد (تومان یا دلار)
            $goldMap = [
                'GOLD_MESGHAL' => ['fa' => 'آبشده(مثقال طلا)',    'usd' => false],
                'GOLD_18'      => ['fa' => 'گرم طلای 18 عیار',    'usd' => false],
                'COIN_EMAMI'   => ['fa' => 'سکه امامی (طرح جدید)', 'usd' => false],
                'GOLD_OUNCE'   => ['fa' => 'انس طلا',              'usd' => true],
            ];
            foreach ($goldMap as $code => $meta) {
                $fa = $meta['fa'];
                if (!empty($meta['usd'])) {
                    // انس طلا به دلار (عدد اعشاری + $)
                    if (preg_match('/' . preg_quote($fa, '/') . '\s+([0-9][0-9,\.]*)\s*\$\s*([0-9\.]+)?/us', $gtext, $mm)) {
                        $price = (float)str_replace(',', '', $mm[1]);
                        $chg   = isset($mm[2]) ? (float)$mm[2] : 0.0;
                        if ($price > 0) $out[$code] = ['price' => $price, 'change' => $chg];
                    }
                } else {
                    // قیمت به تومان
                    if (preg_match('/' . preg_quote($fa, '/') . '\s+([0-9][0-9,]{3,})\s*تومان\s*([0-9\.]+)?/us', $gtext, $mm)) {
                        $price = (float)str_replace(',', '', $mm[1]);
                        $chg   = isset($mm[2]) ? (float)$mm[2] : 0.0;
                        if ($price > 0) $out[$code] = ['price' => $price, 'change' => $chg];
                    }
                }
            }
        }

        return $out;
    }
}

// ---- اجرای همگام‌سازی (هر ۵ دقیقه، بدون کرون) ----
if (isset($conn) && $conn) {
    $__acLast = null;
    $__q = @$conn->query("SELECT MAX(updated_at) AS m FROM currency_rates");
    if ($__q && ($__r = $__q->fetch_assoc())) $__acLast = $__r['m'];

    // آیا ردیف‌های طلا موجودند؟ اگر نه، صرف‌نظر از محدودیت زمانی، همگام‌سازی اجرا شود
    $__goldMissing = true;
    $__gq = @$conn->query("SELECT COUNT(*) AS c FROM currency_rates WHERE currency IN ('GOLD_MESGHAL','GOLD_18','COIN_EMAMI','GOLD_OUNCE')");
    if ($__gq && ($__gr = $__gq->fetch_assoc())) $__goldMissing = ((int)$__gr['c'] < 4);

    // بازه‌ی به‌روزرسانی: پیش‌فرض ۳۰۰ ثانیه؛ اگر $AVA_SYNC_MAX_AGE از بیرون تعریف شده
    // باشد (مثلاً توسط کران هشدارها)، همان استفاده می‌شود تا قیمت تازه‌تر گرفته شود.
    $__syncMaxAge = (isset($GLOBALS['AVA_SYNC_MAX_AGE']) && (int)$GLOBALS['AVA_SYNC_MAX_AGE'] > 0)
                    ? (int)$GLOBALS['AVA_SYNC_MAX_AGE'] : 300;

    if (!$__acLast || strtotime($__acLast) < time() - $__syncMaxAge || $__goldMissing || !empty($GLOBALS['AVA_FORCE_SYNC'])) {
        try {
            $rates = ava_alanchand_fetch();
            foreach ($rates as $code => $info) {
                if (empty($info['price'])) continue;
                $code = preg_replace('/[^A-Z0-9_]/', '', strtoupper($code));
                if ($code === '') continue;
                $p   = (float)$info['price'];
                $chg = (float)$info['change'];
                $exists = @$conn->query("SELECT id FROM currency_rates WHERE currency='$code' LIMIT 1");
                if ($exists && $exists->num_rows) {
                    @$conn->query("UPDATE currency_rates SET price=$p, change_24h=$chg, updated_at=NOW() WHERE currency='$code'");
                } else {
                    @$conn->query("INSERT INTO currency_rates (currency, price, change_24h) VALUES ('$code', $p, $chg)");
                }
            }
        } catch (\Throwable $e) { /* در صورت خطا، مقادیر قبلی حفظ می‌شوند */ }
    }
}
