<?php
/**
 * includes/tier_system.php
 * ------------------------------------------------------------------
 * سیستم سطح‌بندی خودکار کاربران (Tier / VIP) بر اساس حجم معاملاتِ
 * تکمیل‌شده‌ی هر کاربر (مجموع total_price در ad_deals، یعنی معادل
 * تومانی — همان واحدی که موتور کمیسیون oa_commission() هم برای
 * قوانین «toman» استفاده می‌کند، تا هیچ نرخ ارز جداگانه‌ای لازم نباشد).
 *
 * سطوح در جدول tier_levels قابل تنظیم توسط ادمین‌اند (پیش‌فرض هنگام
 * اولین اجرا ساخته می‌شوند). هر سطح یک درصد تخفیف کمیسیونِ خودکار دارد
 * که در oa_commission() به‌عنوان "کف تخفیف" اعمال می‌شود — یعنی اگر
 * ادمین برای کاربر تخفیف دستیِ بالاتری تعریف کرده باشد، همان (تخفیف
 * دستی) اعمال می‌ماند؛ تخفیف تیر فقط وقتی اثر دارد که بیشتر از تخفیف
 * دستی فعلی کاربر باشد. این یعنی هیچ رفتار قبلیِ سیستم کمیسیون نقض
 * نمی‌شود، فقط یک لایه‌ی خودکارِ اضافه به نفع کاربر است.
 * ------------------------------------------------------------------
 */

if (!function_exists('avapay_ensure_tier_tables')) {
    function avapay_ensure_tier_tables($conn) {
        $conn->query("CREATE TABLE IF NOT EXISTS `tier_levels` (
            `id` INT PRIMARY KEY AUTO_INCREMENT,
            `name` VARCHAR(50) NOT NULL,
            `min_volume_toman` DECIMAL(20,2) NOT NULL DEFAULT 0,
            `discount_percent` DECIMAL(5,2) NOT NULL DEFAULT 0,
            `badge_icon` VARCHAR(50) DEFAULT 'fa-medal',
            `badge_color` VARCHAR(20) DEFAULT '#B8860B',
            `sort_order` INT DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $check = $conn->query("SELECT COUNT(*) AS c FROM tier_levels");
        $count = $check ? (int)$check->fetch_assoc()['c'] : 0;
        if ($count === 0) {
            // سطوح پیش‌فرض — ادمین بعداً می‌تواند از پنل ویرایششان کند
            $conn->query("INSERT INTO tier_levels (name, min_volume_toman, discount_percent, badge_icon, badge_color, sort_order) VALUES
                ('برنزی',   0,             0,   'fa-medal',  '#B08D57', 1),
                ('نقره‌ای', 50000000,      3,   'fa-award',  '#9CA3AF', 2),
                ('طلایی',   200000000,     6,   'fa-trophy', '#FFD700', 3),
                ('الماسی',  500000000,     10,  'fa-gem',    '#38bdf8', 4)");
        }
    }
}

if (!function_exists('avapay_ensure_tier_settings_table')) {
    function avapay_ensure_tier_settings_table($conn) {
        // از همان جدول کلید/مقدار عمومی که بخش‌های دیگر پنل ادمین هم استفاده
        // می‌کنند (broadcast_ads_to_channel.php و channel_settings_api.php) —
        // برای جلوگیری از ساخت یک جدول تک‌مصرفی جدید.
        $conn->query("CREATE TABLE IF NOT EXISTS `app_settings` (
            `k` VARCHAR(64) PRIMARY KEY,
            `v` TEXT NULL,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

if (!function_exists('avapay_get_tier_currency')) {
    /** ارزی که سطح‌بندی بر اساس آن حساب می‌شود — پیش‌فرض تومان (IRR)، قابل تغییر توسط ادمین */
    function avapay_get_tier_currency($conn) {
        avapay_ensure_tier_settings_table($conn);
        $stmt = $conn->prepare("SELECT v FROM app_settings WHERE k = 'tier_volume_currency' LIMIT 1");
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $v = $row ? strtoupper(trim((string)$row['v'])) : '';
        return in_array($v, ['IRR', 'USD', 'EUR', 'USDT'], true) ? $v : 'IRR';
    }
}

if (!function_exists('avapay_set_tier_currency')) {
    function avapay_set_tier_currency($conn, $currency) {
        avapay_ensure_tier_settings_table($conn);
        $currency = strtoupper(trim((string)$currency));
        if (!in_array($currency, ['IRR', 'USD', 'EUR', 'USDT'], true)) return false;
        $stmt = $conn->prepare("INSERT INTO app_settings (k, v) VALUES ('tier_volume_currency', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)");
        $stmt->bind_param("s", $currency);
        return $stmt->execute();
    }
}

if (!function_exists('avapay_get_user_volume_toman')) {
    /**
     * مجموع حجم معاملات تکمیل‌شده‌ی کاربر — واحدِ محاسبه توسط ادمین قابل
     * انتخاب است (تومان/دلار/یورو/تتر، از app_settings.tier_volume_currency):
     *  - IRR (پیش‌فرض): مجموع total_price همان‌طور که قبلاً بود (معادل تومانیِ کل معامله)
     *  - غیر IRR: مجموع amount فقط برای معاملاتی که ارزشان دقیقاً همان ارز انتخابی است
     *    (یعنی اگر ادمین یورو را انتخاب کند، فقط معاملات یورویی کاربر جمع زده می‌شود —
     *    نه معادل‌سازی سایر ارزها به یورو، چون این اپ نرخ لحظه‌ای بین‌ارزی ذخیره نمی‌کند)
     */
    function avapay_get_user_volume_toman($conn, $userId) {
        $currency = avapay_get_tier_currency($conn);
        if ($currency === 'IRR') {
            $stmt = $conn->prepare("SELECT COALESCE(SUM(total_price),0) AS v FROM ad_deals WHERE status = 'completed' AND (buyer_id = ? OR seller_id = ?)");
            if (!$stmt) return 0.0;
            $stmt->bind_param("ii", $userId, $userId);
        } else {
            $stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS v FROM ad_deals WHERE status = 'completed' AND currency = ? AND (buyer_id = ? OR seller_id = ?)");
            if (!$stmt) return 0.0;
            $stmt->bind_param("sii", $currency, $userId, $userId);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $real = (float)($row['v'] ?? 0);
        // (جدید) اگر ادمین برای این کاربر حجمِ دستی هم ثبت کرده، به‌عنوانِ
        // افزونه روی حجمِ واقعی جمع می‌شود (نه جایگزینِ آن)
        $manual = avapay_get_user_manual_volume($conn, $userId, $currency);
        return $real + $manual;
    }
}

if (!function_exists('avapay_get_user_tier')) {
    /** سطح فعلی کاربر (بالاترین سطحی که حجمش به آن رسیده) به‌همراه حجم و سطح بعدی */
    function avapay_get_user_tier($conn, $userId) {
        avapay_ensure_tier_tables($conn);
        $volume = avapay_get_user_volume_toman($conn, $userId);

        $res = $conn->query("SELECT * FROM tier_levels ORDER BY min_volume_toman ASC");
        $tiers = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

        $current = null;
        $next = null;
        foreach ($tiers as $t) {
            if ($volume >= (float)$t['min_volume_toman']) {
                $current = $t;
            } elseif ($next === null) {
                $next = $t;
            }
        }

        $progressPercent = 100;
        $remainingToman = 0;
        if ($next) {
            $base = $current ? (float)$current['min_volume_toman'] : 0;
            $span = (float)$next['min_volume_toman'] - $base;
            $progressPercent = $span > 0 ? min(100, max(0, (($volume - $base) / $span) * 100)) : 0;
            $remainingToman = max(0, (float)$next['min_volume_toman'] - $volume);
        }

        return [
            'volume_toman'     => $volume,
            'volume_currency'  => avapay_get_tier_currency($conn),
            'current'          => $current,
            'next'             => $next,
            'progress_percent' => round($progressPercent, 1),
            'remaining_toman'  => $remainingToman,
        ];
    }
}

if (!function_exists('avapay_ensure_manual_volume_table')) {
    /** (جدید) امکان واردکردن دستیِ حجمِ معاملات توسط ادمین — به‌عنوان یک
     *  «افزونه» روی حجم واقعی (نه جایگزینِ آن)، برای مواردی مثل معاملاتِ
     *  قدیمی/خارج از اپ که در ad_deals ثبت نشده‌اند. هر کاربر برای هر
     *  واحدِ سطح‌بندی (تومان/دلار/یورو/تتر) یک مقدارِ دستیِ جداگانه دارد. */
    function avapay_ensure_manual_volume_table($conn) {
        $conn->query("CREATE TABLE IF NOT EXISTS `user_manual_volume` (
            `user_id` INT NOT NULL,
            `currency` VARCHAR(10) NOT NULL,
            `amount` DECIMAL(20,2) NOT NULL DEFAULT 0,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, currency)
        )");
    }
}

if (!function_exists('avapay_get_user_manual_volume')) {
    function avapay_get_user_manual_volume($conn, $userId, $currency = null) {
        avapay_ensure_manual_volume_table($conn);
        $currency = $currency ?: avapay_get_tier_currency($conn);
        $stmt = $conn->prepare("SELECT amount FROM user_manual_volume WHERE user_id = ? AND currency = ?");
        if (!$stmt) return 0.0;
        $stmt->bind_param("is", $userId, $currency);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return (float)($row['amount'] ?? 0);
    }
}

if (!function_exists('avapay_set_user_manual_volume')) {
    function avapay_set_user_manual_volume($conn, $userId, $amount, $currency = null) {
        avapay_ensure_manual_volume_table($conn);
        $currency = $currency ?: avapay_get_tier_currency($conn);
        $amount = max(0, (float)$amount);
        $stmt = $conn->prepare("INSERT INTO user_manual_volume (user_id, currency, amount) VALUES (?, ?, ?)
                                 ON DUPLICATE KEY UPDATE amount = VALUES(amount)");
        if (!$stmt) return false;
        $stmt->bind_param("isd", $userId, $currency, $amount);
        return $stmt->execute();
    }
}

    /** فقط درصد تخفیفِ سطح فعلی کاربر (برای هوک‌شدن داخل oa_commission) */
if (!function_exists('avapay_get_user_tier_discount')) {
    function avapay_get_user_tier_discount($conn, $userId) {
        $info = avapay_get_user_tier($conn, $userId);
        return $info['current'] ? (float)$info['current']['discount_percent'] : 0.0;
    }
}
