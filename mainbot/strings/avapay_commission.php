<?php
/**
 * ---------------------------------------------------------------------------
 *  پل کمیسیون AvaPay → mainbot
 * ---------------------------------------------------------------------------
 *  هدف: هر کمیسیونی که ادمین در پنل AvaPay تعریف کرده باشد — چه به‌صورت کلی
 *  (default_commission_rules) و چه به‌صورت شخصی برای یک کاربر
 *  (user_fixed_commission / user_commission_rules) — دقیقاً همان کمیسیون در
 *  ربات تلگرام (mainbot) هم اعمال شود.
 *
 *  اولویت‌ها عیناً مطابق تابع oa_commission در includes/offer_actions.php است:
 *    ۰) user_fixed_commission     → مبلغ کاملاً ثابت برای آن کاربر (بالاترین اولویت)
 *    ۱) user_commission_rules     → پلکانی شخصی، اول بر اساس تومان بعد بر اساس ارز
 *    ۲) default_commission_rules  → پلکانی کلی، اول بر اساس تومان بعد بر اساس ارز
 *    ۳) اگر هیچ‌کدام نبود → null برمی‌گرداند تا mainbot از تنظیمات خودش استفاده کند.
 * ---------------------------------------------------------------------------
 */

if (!function_exists('avapay_site_db')) {
    /**
     * اتصال (یک‌بار، cache‌شده) به دیتابیس سایت AvaPay.
     * در صورت عدم موفقیت null برمی‌گرداند تا ربات بدون خطا به روال عادی ادامه دهد.
     */
    function avapay_site_db(): ?mysqli
    {
        static $conn = null;
        static $tried = false;

        if ($tried) return $conn;
        $tried = true;

        $host = 'localhost';
        $user = 'aradexch_app';
        $pass = 'VIJVC9Gn5z9Y?D.$';
        $name = 'aradexch_app';

        // (اصلاح) قبلاً new mysqli(...) بدون مهلتِ اتصال بود؛ هر پیامِ ورودیِ
        // تلگرام از همین‌جا رد می‌شد، پس یک دیتابیسِ کندِ سایت می‌توانست کلِ
        // ربات را هنگ کند. حالا حداکثر ۳ ثانیه، مطابق includes/fast_mysqli.php.
        require_once __DIR__ . '/../../includes/fast_mysqli.php';
        $conn = avapay_fast_mysqli($host, $user, $pass, $name, 3);
        return $conn;
    }
}

if (!function_exists('avapay_user_id_by_telegram')) {
    /**
     * تبدیل شناسه‌ی تلگرام به شناسه‌ی کاربر در سایت AvaPay.
     */
    function avapay_user_id_by_telegram($telegramId): int
    {
        $telegramId = trim((string) $telegramId);
        if ($telegramId === '') return 0;

        $conn = avapay_site_db();
        if (!$conn) return 0;

        $st = $conn->prepare("SELECT id FROM users WHERE telegram_id = ? LIMIT 1");
        if (!$st) return 0;
        $st->bind_param('s', $telegramId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();

        return $row ? (int) $row['id'] : 0;
    }
}

if (!function_exists('avapay_table_exists')) {
    function avapay_table_exists(mysqli $conn, string $table): bool
    {
        $r = @$conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
        return $r && $r->num_rows > 0;
    }
}

if (!function_exists('avapay_commission_lookup')) {
    /**
     * قانون کمیسیونِ تعریف‌شده در AvaPay را پیدا می‌کند.
     *
     * @param string $telegramId شناسه‌ی تلگرام کاربری که کمیسیون برای او حساب می‌شود
     * @param float  $meghdar    مقدار ارز معامله
     * @param string $arzCode    کد ارز معامله (USD/EUR/USDT/...)
     * @param float  $totalToman کل مبلغ معامله به تومان
     *
     * @return array|null  ['unit'=>'toman'|'currency', 'type'=>'percent'|'fixed',
     *                      'value'=>float, 'fixed_currency'=>?string, 'source'=>string]
     *                     یا null اگر هیچ قانونی در AvaPay تعریف نشده باشد.
     */
    function avapay_commission_lookup($telegramId, float $meghdar, string $arzCode, float $totalToman): ?array
    {
        $conn = avapay_site_db();
        if (!$conn) return null;

        $arzCode = strtoupper($arzCode);
        $userId  = avapay_user_id_by_telegram($telegramId);

        /* ۰) کمیسیون ثابتِ شخصی — بالاترین اولویت */
        if ($userId > 0 && avapay_table_exists($conn, 'user_fixed_commission')) {
            $st = $conn->prepare("SELECT fixed_amount, fixed_currency FROM user_fixed_commission WHERE user_id = ? LIMIT 1");
            if ($st) {
                $st->bind_param('i', $userId);
                $st->execute();
                $row = $st->get_result()->fetch_assoc();
                $st->close();
                if ($row) {
                    return [
                        'unit'           => 'currency',
                        'type'           => 'fixed',
                        'value'          => (float) $row['fixed_amount'],
                        'fixed_currency' => strtoupper((string) $row['fixed_currency']),
                        'source'         => 'avapay_user_fixed',
                    ];
                }
            }
        }

        /* ۱) قانون پلکانیِ شخصی — اول تومان، بعد ارز */
        if ($userId > 0 && avapay_table_exists($conn, 'user_commission_rules')) {
            $st = $conn->prepare("SELECT rule_type, value FROM user_commission_rules
                WHERE user_id = ? AND unit = 'toman'
                  AND min_amount <= ? AND (max_amount IS NULL OR max_amount >= ?)
                ORDER BY min_amount DESC LIMIT 1");
            if ($st) {
                $st->bind_param('idd', $userId, $totalToman, $totalToman);
                $st->execute();
                $row = $st->get_result()->fetch_assoc();
                $st->close();
                if ($row) {
                    return [
                        'unit'   => 'toman',
                        'type'   => ($row['rule_type'] === 'percent') ? 'percent' : 'fixed',
                        'value'  => (float) $row['value'],
                        'source' => 'avapay_user_rule_toman',
                    ];
                }
            }

            $st = $conn->prepare("SELECT rule_type, value FROM user_commission_rules
                WHERE user_id = ? AND unit = 'currency' AND (currency = ? OR currency = 'ALL')
                  AND min_amount <= ? AND (max_amount IS NULL OR max_amount >= ?)
                ORDER BY (currency = 'ALL') ASC, min_amount DESC LIMIT 1");
            if ($st) {
                $st->bind_param('isdd', $userId, $arzCode, $meghdar, $meghdar);
                $st->execute();
                $row = $st->get_result()->fetch_assoc();
                $st->close();
                if ($row) {
                    return [
                        'unit'   => 'currency',
                        'type'   => ($row['rule_type'] === 'percent') ? 'percent' : 'fixed',
                        'value'  => (float) $row['value'],
                        'source' => 'avapay_user_rule_currency',
                    ];
                }
            }
        }

        /* ۲) قانون پیش‌فرضِ کلی (برای همه‌ی کاربران) — اول تومان، بعد ارز */
        if (avapay_table_exists($conn, 'default_commission_rules')) {
            $st = $conn->prepare("SELECT rule_type, value FROM default_commission_rules
                WHERE unit = 'toman'
                  AND min_amount <= ? AND (max_amount IS NULL OR max_amount >= ?)
                ORDER BY min_amount DESC LIMIT 1");
            if ($st) {
                $st->bind_param('dd', $totalToman, $totalToman);
                $st->execute();
                $row = $st->get_result()->fetch_assoc();
                $st->close();
                if ($row) {
                    return [
                        'unit'   => 'toman',
                        'type'   => ($row['rule_type'] === 'percent') ? 'percent' : 'fixed',
                        'value'  => (float) $row['value'],
                        'source' => 'avapay_default_toman',
                    ];
                }
            }

            $st = $conn->prepare("SELECT rule_type, value FROM default_commission_rules
                WHERE unit = 'currency'
                  AND min_amount <= ? AND (max_amount IS NULL OR max_amount >= ?)
                ORDER BY min_amount DESC LIMIT 1");
            if ($st) {
                $st->bind_param('dd', $meghdar, $meghdar);
                $st->execute();
                $row = $st->get_result()->fetch_assoc();
                $st->close();
                if ($row) {
                    return [
                        'unit'   => 'currency',
                        'type'   => ($row['rule_type'] === 'percent') ? 'percent' : 'fixed',
                        'value'  => (float) $row['value'],
                        'source' => 'avapay_default_currency',
                    ];
                }
            }
        }

        return null;
    }
}

if (!function_exists('avapay_rate_to_toman')) {
    /**
     * نرخ فروشِ یک ارز به تومان از جدول واقعیِ نرخ‌های سایت (currency_rates).
     * اگر پیدا نشد، مقدار fallback برگردانده می‌شود.
     */
    function avapay_rate_to_toman(string $code, float $fallback = 0.0): float
    {
        $code = strtoupper($code);
        if ($code === 'IRR' || $code === 'تومان') return 1.0;

        $conn = avapay_site_db();
        if (!$conn) return $fallback;

        $st = $conn->prepare("SELECT price FROM currency_rates WHERE currency = ? LIMIT 1");
        if (!$st) return $fallback;
        $st->bind_param('s', $code);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();

        return ($row && (float) $row['price'] > 0) ? (float) $row['price'] : $fallback;
    }
}
