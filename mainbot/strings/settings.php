<?php
/**
 * @author      => Alireza jarayedi / @iamAlira / @Alirea
 * @version     => 2
 * @copyright   => Copyright (c) 2024, ©️Nova Code
 * @link        => https://nova-code.ir
 * @internal    => ©️Nova Code
 *
 * تنظیمات کمیسیون قابل مدیریت توسط ادمین
 * ------------------------------------------------------------------
 * این فایل یک لایه‌ی ساده key/value روی جدول `settings` فراهم می‌کند
 * تا ادمین بتواند «درصد کمیسیون» و «ارز کمیسیون» را در ربات تعیین کند
 * و همین مقدار در تمام پیام‌های ثبت آگهی و ثبت پیشنهاد اعمال شود.
 */

// پل کمیسیون AvaPay: هر قانونی که ادمین در پنل سایت تعریف کرده باشد
// (کلی یا شخصی) بر تنظیمات داخلی ربات اولویت دارد.
@require_once __DIR__ . '/avapay_commission.php';

if (!function_exists('ensure_settings_table')) {
    /**
     * اطمینان از وجود جدول settings در دیتابیس. اگر جدول ساخته نشده باشد
     * (که علت اصلیِ «ذخیره نشدن بی‌صدای» کمیسیون است)، اینجا ساخته می‌شود.
     */
    function ensure_settings_table($bot): void
    {
        static $checked = false;
        if ($checked) return;

        try {
            $pdo = $bot->Db->pdo ?? null;
            if ($pdo) {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (
                    `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    `name` VARCHAR(70) NOT NULL UNIQUE,
                    `value` TEXT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
            }
        } catch (Throwable $e) {
            // اگر ساخت جدول هم شکست بخورد، خطا توسط get/set_setting گزارش می‌شود
            $GLOBALS['__last_setting_error'] = $e->getMessage();
        }

        $checked = true;
    }
}

if (!function_exists('ensure_mozayede_reminder_columns')) {
    /**
     * اطمینان از وجود ستون‌های `reminded` و `reminded_at` در جدول `mozayede`.
     * این ستون‌ها برای چرخه‌ی «یادآوری تمدید/حذف پس از ۲۴ ساعت» لازم‌اند.
     * (اگر ستون‌ها از قبل باشند، هیچ عملیاتی انجام نمی‌شود.)
     */
    function ensure_mozayede_reminder_columns($bot): void
    {
        static $checked = false;
        if ($checked) return;
        $checked = true;

        try {
            $pdo = $bot->Db->pdo ?? null;
            if (!$pdo) return;

            $cols = $pdo->query("SHOW COLUMNS FROM `mozayede`")->fetchAll(PDO::FETCH_COLUMN);
            if (is_array($cols)) {
                if (!in_array('reminded', $cols, true)) {
                    $pdo->exec("ALTER TABLE `mozayede` ADD COLUMN `reminded` TINYINT(1) NOT NULL DEFAULT 0");
                }
                if (!in_array('reminded_at', $cols, true)) {
                    $pdo->exec("ALTER TABLE `mozayede` ADD COLUMN `reminded_at` DATETIME NULL DEFAULT NULL");
                }
            }
        } catch (Throwable $e) {
            // بدون خطای مرگبار ادامه می‌دهیم؛ در بدترین حالت منطق یادآوری غیرفعال می‌ماند.
        }
    }
}

if (!function_exists('get_setting_error')) {
    /**
     * آخرین خطای ثبت‌شده هنگام خواندن/نوشتن تنظیمات (برای نمایش به ادمین جهت رفع اشکال).
     */
    function get_setting_error(): ?string
    {
        return $GLOBALS['__last_setting_error'] ?? null;
    }
}

if (!function_exists('get_setting')) {

    /**
     * خواندن یک مقدار از جدول settings
     */
    function get_setting($bot, string $key, $default = null)
    {
        ensure_settings_table($bot);
        try {
            $row = $bot->Db->get('settings', 'value', ['name' => $key]);
            return ($row === null || $row === false) ? $default : $row;
        } catch (Throwable $e) {
            $GLOBALS['__last_setting_error'] = $e->getMessage();
            return $default;
        }
    }

    /**
     * ذخیره‌ی یک مقدار در جدول settings (اگر وجود داشت آپدیت، در غیر اینصورت درج می‌شود).
     * @return bool آیا ذخیره‌سازی واقعاً موفق بوده است یا نه (برخلاف نسخه‌ی قبلی که همیشه void بود
     *              و شکست را بی‌صدا می‌بلعید، این نسخه نتیجه‌ی واقعی را برمی‌گرداند).
     */
    function set_setting($bot, string $key, $value): bool
    {
        ensure_settings_table($bot);
        $GLOBALS['__last_setting_error'] = null;
        try {
            if ($bot->Db->has('settings', ['name' => $key])) {
                $bot->Db->update('settings', ['value' => (string) $value], ['name' => $key]);
            } else {
                $bot->Db->insert('settings', ['name' => $key, 'value' => (string) $value]);
            }

            // برخی نسخه‌های Medoo به‌جای exception، فقط errorInfo را ست می‌کنند؛ آن را هم چک می‌کنیم
            $error = method_exists($bot->Db, 'error') ? $bot->Db->error() : null;
            if (!empty($error) && !empty($error[1])) {
                $GLOBALS['__last_setting_error'] = $error[2] ?? 'خطای نامشخص دیتابیس';
                return false;
            }

            // تأیید نهایی: واقعاً مقدار ذخیره شده را دوباره می‌خوانیم
            $saved = $bot->Db->get('settings', 'value', ['name' => $key]);
            if ((string) $saved !== (string) $value) {
                $GLOBALS['__last_setting_error'] = 'مقدار پس از ذخیره، در دیتابیس تأیید نشد.';
                return false;
            }

            return true;
        } catch (Throwable $e) {
            $GLOBALS['__last_setting_error'] = $e->getMessage();
            return false;
        }
    }
}

if (!function_exists('commission_config')) {

    /**
     * پیکربندی فعلی کمیسیون را برمی‌گرداند.
     * percent : درصدی از کل مبلغ که به عنوان کمیسیون در نظر گرفته می‌شود
     * currency: ارزی که کمیسیون با آن محاسبه/نمایش داده می‌شود (تومان، دلار، یورو، تتر و ...)
     */
    function commission_config($bot): array
    {
        $percent  = (float) get_setting($bot, 'commission_percent', 0);   // مثال: 0.5 یعنی نیم درصد
        $currency = (string) get_setting($bot, 'commission_currency', 'تومان');

        if ($percent < 0)   $percent = 0;
        if ($percent > 100) $percent = 100;
        if ($currency === '') $currency = 'تومان';

        return ['percent' => $percent, 'currency' => $currency];
    }

    /**
     * محاسبه‌ی مبلغ کمیسیون بر اساس «کل مبلغ» معامله (نسخه‌ی قدیمی، صرفاً برای سازگاری با کدهای قبلی).
     * توجه: این تابع همیشه کمیسیون را روی مبلغ تومانی حساب می‌کند و برای حالت «ارز انتخابی» مناسب نیست.
     * برای منطق کامل و صحیح از commission_apply استفاده کنید.
     *
     * @param float $totalAmount کل مبلغ معامله (مقدار ارز × نرخ)
     * @return array [percent, currency, amount]
     */
    function commission_calc($bot, float $totalAmount): array
    {
        $cfg    = commission_config($bot);
        $amount = $totalAmount * ($cfg['percent'] / 100);

        return [
            'percent'  => $cfg['percent'],
            'currency' => $cfg['currency'],
            'amount'   => $amount,
        ];
    }

    /**
     * یک خط متن آماده‌ی نمایش کمیسیون برای درج در پیام‌ها.
     */
    function commission_line($bot, float $totalAmount): string
    {
        $c = commission_calc($bot, $totalAmount);
        if ($c['percent'] <= 0) {
            return '';
        }
        $formatted = number_format($c['amount']);
        return "🧾 کمیسیون ({$c['percent']}٪) از هر طرف: {$formatted} {$c['currency']}";
    }
}

if (!function_exists('commission_tiers')) {

    /**
     * کمیسیون پلکانی (بر اساس بازه‌ی مبلغ/مقدار معامله).
     * ------------------------------------------------------------------
     * هر Tier به شکل زیر است:
     *   ['min' => float, 'max' => float|null, 'type' => 'percent'|'fixed', 'value' => float]
     * max === null یعنی «به بالا» (بدون سقف).
     * type === 'percent' یعنی مقدار کمیسیون درصدی از مبلغ/مقدار معامله است.
     * type === 'fixed'   یعنی مقدار کمیسیون یک عدد ثابت (به همان ارز معامله، مثلاً ۵ یورو)
     *                    است و مستقل از اندازه‌ی معامله همیشه همان عدد گرفته می‌شود.
     * اگر هیچ Tier ای تعریف نشده باشد (آرایه خالی)، سیستم به درصد ثابتِ
     * commission_config برمی‌گردد (سازگاری کامل با نسخه‌ی قبلی).
     */
    function commission_tiers($bot): array
    {
        $raw = get_setting($bot, 'commission_tiers', '');
        if ($raw === '' || $raw === null) return [];

        $tiers = json_decode((string) $raw, true);
        if (!is_array($tiers)) return [];

        $clean = [];
        foreach ($tiers as $t) {
            if (!isset($t['min'])) continue;

            // سازگاری با ساختار قدیمی (فقط 'percent')
            if (isset($t['percent']) && !isset($t['type'])) {
                $type  = 'percent';
                $value = (float) $t['percent'];
            } elseif (isset($t['type'], $t['value'])) {
                $type  = ($t['type'] === 'fixed') ? 'fixed' : 'percent';
                $value = (float) $t['value'];
            } else {
                continue;
            }

            $clean[] = [
                'min'   => (float) $t['min'],
                'max'   => (isset($t['max']) && $t['max'] !== null) ? (float) $t['max'] : null,
                'type'  => $type,
                'value' => $value,
            ];
        }

        usort($clean, fn($a, $b) => $a['min'] <=> $b['min']);
        return $clean;
    }

    /**
     * ذخیره‌ی آرایه‌ی Tier ها در دیتابیس (به‌صورت JSON).
     */
    function commission_tiers_set($bot, array $tiers): bool
    {
        usort($tiers, fn($a, $b) => $a['min'] <=> $b['min']);
        return set_setting($bot, 'commission_tiers', json_encode($tiers, JSON_UNESCAPED_UNICODE));
    }

    /**
     * حذف کامل کمیسیون پلکانی (بازگشت به حالت درصد ثابت).
     */
    function commission_tiers_clear($bot): bool
    {
        return set_setting($bot, 'commission_tiers', '');
    }

    /**
     * تجزیه‌ی متن وارد شده توسط ادمین به آرایه‌ی Tier ها.
     *
     * فرمت هر خط:
     *   حد_پایین-حد_بالا:مقدار      مثال: 1-1000:2   یا   1-1000:2%   (کمیسیون درصدی، ۲٪)
     *   حد_پایین+:مقدار             مثال: 1000+:1.5          (بازه‌ی نامحدود/آخر، درصدی)
     *   حد_پایین-حد_بالا:مقداrF     مثال: 1-1000:5F  یا  1-1000:5ثابت  (کمیسیون ثابت، ۵ واحد ارز)
     *   حد_پایین+:مقدارF            مثال: 1000+:10F          (بازه‌ی نامحدود/آخر، ثابت)
     *
     * یعنی اگر بعد از عدد، حرف F یا کلمه‌ی «ثابت» بیاید، آن بازه «کمیسیون ثابت» (مثلاً ۵ یورو
     * فارغ از اندازه‌ی معامله) در نظر گرفته می‌شود؛ در غیر این صورت (چیزی نباشد یا % / ٪ باشد)
     * همان عدد به‌عنوان درصد کمیسیون استفاده می‌شود.
     *
     * @return array|null   آرایه‌ی Tier های معتبر، یا null اگر ورودی نامعتبر باشد.
     */
    function commission_parse_tiers_input(string $text): ?array
    {
        $text = str_replace(['،', 'ثابت', 'fixed', 'FIXED'], ['.', 'F', 'F', 'F'], $text);
        $lines = preg_split('/[\r\n]+/', trim($text));
        $tiers = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            $rangeMatch = preg_match('/^([0-9.]+)\s*-\s*([0-9.]+)\s*:\s*([0-9.]+)\s*(%|٪|F)?$/ui', $line, $m);
            $openMatch  = !$rangeMatch && preg_match('/^([0-9.]+)\s*\+\s*:\s*([0-9.]+)\s*(%|٪|F)?$/ui', $line, $m2);

            if (!$rangeMatch && !$openMatch) {
                return null; // خط نامعتبر
            }

            if ($rangeMatch) {
                $min    = (float) $m[1];
                $max    = (float) $m[2];
                $value  = (float) $m[3];
                $marker = $m[4] ?? '';
                if ($max <= $min) return null;
            } else {
                $min    = (float) $m2[1];
                $max    = null;
                $value  = (float) $m2[2];
                $marker = $m2[3] ?? '';
            }

            $type = (strtoupper($marker) === 'F') ? 'fixed' : 'percent';

            if ($type === 'percent' && ($value < 0 || $value > 100)) return null;
            if ($type === 'fixed' && $value < 0) return null;

            $tiers[] = ['min' => $min, 'max' => $max, 'type' => $type, 'value' => $value];
        }

        if (empty($tiers)) return null;

        usort($tiers, fn($a, $b) => $a['min'] <=> $b['min']);
        return $tiers;
    }

    /**
     * متن آماده‌ی نمایش لیست Tier ها به ادمین/در پیام‌های تنظیمات.
     */
    function commission_tiers_display(array $tiers): string
    {
        if (empty($tiers)) return '';

        $lines = [];
        foreach ($tiers as $t) {
            $range = ($t['max'] === null)
                ? "بیشتر از " . fmt_amount($t['min'])
                : "از " . fmt_amount($t['min']) . " تا " . fmt_amount($t['max']);

            $amountText = ($t['type'] === 'fixed')
                ? "*" . fmt_amount($t['value']) . "* (مقدار ثابت)"
                : "*{$t['value']}٪*";

            $lines[] = "🔸 {$range} : {$amountText}";
        }
        return implode("\n", $lines);
    }

    /**
     * Tier منطبق با یک مبلغ/مقدار مشخص را از بین Tier ها برمی‌گرداند (یا null).
     */
    function commission_match_tier(array $tiers, float $amount): ?array
    {
        if (empty($tiers)) return null;

        foreach ($tiers as $tier) {
            $min = $tier['min'];
            $max = $tier['max'];
            if ($amount >= $min && ($max === null || $amount <= $max)) {
                return $tier;
            }
        }

        // مبلغ در هیچ بازه‌ای قرار نگرفت: از نزدیک‌ترین Tier (آخرین/بالاترین) استفاده می‌شود
        $last = end($tiers);
        return $last ?: null;
    }
}

if (!function_exists('fmt_amount')) {
    /**
     * فرمت کردن اعداد اعشاری (مقدار ارز) به شکل خوانا، بدون صفرهای اضافه‌ی انتهایی.
     */
    function fmt_amount(float $number): string
    {
        if (abs($number - round($number)) < 0.0000001) {
            return number_format((float) round($number));
        }
        return rtrim(rtrim(number_format($number, 2, '.', ','), '0'), '.');
    }
}

if (!function_exists('commission_arz_code')) {
    /**
     * تبدیل نام فارسیِ ارز (که در ربات استفاده می‌شود) به کد استانداردِ سایت.
     */
    function commission_arz_code(string $arz): string
    {
        $arz = trim($arz);
        $map = [
            'دلار' => 'USD', 'دلار آمریکا' => 'USD', 'usd' => 'USD', 'USD' => 'USD',
            'یورو' => 'EUR', 'eur' => 'EUR', 'EUR' => 'EUR',
            'تتر'  => 'USDT', 'usdt' => 'USDT', 'USDT' => 'USDT',
            'درهم' => 'AED', 'aed' => 'AED', 'AED' => 'AED',
            'لیر'  => 'TRY', 'try' => 'TRY', 'TRY' => 'TRY',
            'پوند' => 'GBP', 'gbp' => 'GBP', 'GBP' => 'GBP',
            'تومان' => 'IRR', 'ریال' => 'IRR', 'irr' => 'IRR', 'IRR' => 'IRR',
        ];
        return $map[$arz] ?? strtoupper($arz);
    }
}

if (!function_exists('commission_apply')) {

    /**
     * محاسبه‌ی دقیق کمیسیون بر اساس نوع تعیین‌شده توسط ادمین و تفکیک مبلغ/مقدار برای «خریدار» و «فروشنده».
     *
     * کمیسیون می‌تواند یکی از دو نوع باشد:
     *   - درصدی (percent): درصدی از مبلغ/مقدار معامله.
     *   - ثابت (fixed): یک عدد ثابت به همان ارز معامله (مثلاً همیشه ۵ یورو)، صرف‌نظر از
     *     اندازه‌ی معامله، تا زمانی که مقدار معامله داخل همان بازه باشد.
     *
     * حالت ۱ - کمیسیون به تومان:
     *      خریدار: مبلغ کل + کمیسیون(به‌تومان) را پرداخت می‌کند (مقدار ارز بدون تغییر)
     *      فروشنده: مبلغ کل − کمیسیون(به‌تومان) را دریافت می‌کند (مقدار ارز بدون تغییر)
     *      اگر کمیسیون «ثابت» باشد، همان مقدار ثابت (که به ارز معامله تعریف شده) با نرخ
     *      معامله (تومان به ازای هر واحد ارز) به تومان تبدیل می‌شود.
     *
     * حالت ۲ - کمیسیون ارز انتخابی (همان ارز معامله):
     *      خریدار: مقدار ارز − کمیسیون را دریافت می‌کند (مبلغ تومانی بدون تغییر)
     *      فروشنده: مقدار ارز + کمیسیون را پرداخت می‌کند (مبلغ تومانی بدون تغییر)
     *
     * @param float  $totalAmount کل مبلغ معامله به تومان (مقدار ارز × نرخ)
     * @param float  $meghdar     مقدار ارز معامله
     * @param string $arz         نام ارز معامله (دلار / یورو / ...)
     */
    function commission_apply($bot, float $totalAmount, float $meghdar, string $arz, $telegramId = null): array
    {
        $cfg         = commission_config($bot);
        $isTomanMode = ($cfg['currency'] === 'تومان');

        // اگر ادمین کمیسیون پلکانی (بر اساس بازه‌ی مقدار ارز معامله، مثلاً دلار) تعریف کرده باشد،
        // Tier منطبق انتخاب می‌شود؛ در غیر این‌صورت درصد ثابتِ تنظیم‌شده استفاده می‌شود.
        $tiers = commission_tiers($bot);
        $tier  = commission_match_tier($tiers, $meghdar);

        $type       = $tier['type']  ?? 'percent';
        $value      = $tier['value'] ?? $cfg['percent'];
        $ratePerUnit = ($meghdar > 0) ? ($totalAmount / $meghdar) : 0.0; // نرخ تومان به ازای هر واحد ارز

        // ── همگام‌سازی با AvaPay ──────────────────────────────────────────────
        // هر کمیسیونی که ادمین در پنل AvaPay تعریف کرده باشد (کلی یا شخصی) بر
        // تنظیماتِ داخلیِ خودِ ربات اولویت دارد، تا کمیسیون در سایت و ربات یکی باشد.
        if (function_exists('avapay_commission_lookup')) {
            $arzCode = commission_arz_code($arz);
            $avaRule = avapay_commission_lookup($telegramId, $meghdar, $arzCode, $totalAmount);
            if ($avaRule) {
                $type  = $avaRule['type'];
                $value = $avaRule['value'];

                // اگر قانون بر اساس تومان تعریف شده، محاسبه در حالت تومانی انجام می‌شود
                // و اگر بر اساس ارز باشد، در حالت ارزی — صرف‌نظر از تنظیم داخلی ربات.
                $isTomanMode = ($avaRule['unit'] === 'toman');

                // کمیسیون ثابتِ شخصی ممکن است به ارزی غیر از ارز معامله تعریف شده باشد
                // (مثلاً همیشه ۳ یورو). در آن صورت ابتدا به ارز/واحد جاری تبدیل می‌شود.
                if ($type === 'fixed' && !empty($avaRule['fixed_currency'])) {
                    $fc = $avaRule['fixed_currency'];
                    if ($fc !== $arzCode) {
                        $fixedToman = ($fc === 'IRR')
                            ? $value
                            : $value * avapay_rate_to_toman($fc, 0.0);
                        if ($fixedToman > 0) {
                            if ($isTomanMode) {
                                $value = $fixedToman;
                            } elseif ($ratePerUnit > 0) {
                                $value = $fixedToman / $ratePerUnit;
                            }
                        }
                    }
                }
            }
        }
        // ─────────────────────────────────────────────────────────────────────

        if ($isTomanMode) {
            if ($type === 'fixed') {
                // مقدار ثابت به ارز معامله تعریف شده؛ برای نمایش تومانی، با نرخ معامله تبدیل می‌شود
                $amount  = $value * $ratePerUnit;
                $percent = 0.0;
            } else {
                $percent = $value;
                $amount  = $totalAmount * ($percent / 100);
            }

            return [
                'percent'        => $percent,
                'type'           => $type,
                'value'          => $value,
                'mode'           => 'toman',
                'currency'       => 'تومان',
                'amount'         => $amount,
                'buyer_toman'    => $totalAmount + $amount,
                'seller_toman'   => $totalAmount - $amount,
                'buyer_meghdar'  => $meghdar,
                'seller_meghdar' => $meghdar,
            ];
        }

        // حالت ارز انتخابی: کمیسیون بر مبنای مقدار ارزِ خودِ معامله محاسبه می‌شود
        if ($type === 'fixed') {
            $amount  = $value; // مستقیماً همان مقدار ثابت به ارز معامله
            $percent = 0.0;
        } else {
            $percent = $value;
            $amount  = $meghdar * ($percent / 100);
        }

        return [
            'percent'        => $percent,
            'type'           => $type,
            'value'          => $value,
            'mode'           => 'currency',
            'currency'       => $arz,
            'amount'         => $amount,
            'buyer_toman'    => $totalAmount,
            'seller_toman'   => $totalAmount,
            'buyer_meghdar'  => $meghdar - $amount,
            'seller_meghdar' => $meghdar + $amount,
        ];
    }

    /**
     * ساخت خط متنی کمیسیون برای درج در پیام‌ها.
     */
    function commission_note(array $com): string
    {
        if (($com['amount'] ?? 0) <= 0) {
            return '';
        }
        $formatted = fmt_amount($com['amount']);
        $label = (($com['type'] ?? 'percent') === 'fixed')
            ? "کمیسیون (مقدار ثابت)"
            : "کمیسیون ({$com['percent']}٪)";
        return "\n🧾 {$label}: {$formatted} {$com['currency']}";
    }
}
