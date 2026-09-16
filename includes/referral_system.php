<?php
/**
 * includes/referral_system.php
 * ---------------------------------------------------------------
 * سیستم «زیرمجموعه‌گیری» / معرفی دوستان (Referral System)
 *
 * منطق کلی:
 *  - هر کاربر یک کد معرف اختصاصی دارد (users.referral_code) و لینک
 *    دعوتش به‌صورت لینک بات تلگرام است: https://t.me/<BOT>?start=ref_<CODE>
 *  - وقتی کاربر جدیدی از این لینک وارد شود و ثبت‌نام کند، ستون
 *    users.referred_by مقدار شناسه‌ی معرف را می‌گیرد و بلافاصله
 *    «جایزه خوش‌آمدگویی» (پیش‌فرض ۳ یورو، قابل تغییر توسط ادمین) به
 *    کیف‌پول یورویی معرف (users.balance_eur) واریز می‌شود.
 *  - به ازای هر «معامله‌ی موفق» (تکمیل یک ad_deal) از سوی کاربر
 *    دعوت‌شده، مبلغ کمیسیون معرفی (پیش‌فرض ۰٫۵ یورو، قابل تغییر) به
 *    کیف‌پول یورویی معرف او واریز می‌شود — این کار برای هر دو طرف
 *    معامله (خریدار/فروشنده) که کاربر دعوت‌شده باشند جداگانه انجام
 *    می‌شود و idempotent است (هر معامله فقط یک‌بار برای هر طرف پاداش
 *    می‌دهد، حتی اگر تابع چندبار صدا زده شود).
 *
 * این فایل فقط تعریف تابع/جدول است و هیچ خروجی مستقیمی ندارد؛ باید
 * در صفحاتی که نیاز دارند require_once شود (arad.php, admin_panel.php,
 * telegram_entry.php, includes/offer_actions.php, mainbot/update.php).
 */

if (!function_exists('ava_ref_ensure_schema')) {
    function ava_ref_ensure_schema(mysqli $conn) {
        static $done = false;
        if ($done) return;
        $done = true;

        // ستون‌های جدید روی users
        $cols = [];
        $r = @$conn->query("SHOW COLUMNS FROM users");
        if ($r) { while ($c = $r->fetch_assoc()) $cols[strtolower($c['Field'])] = true; }

        if (!isset($cols['referral_code'])) {
            @$conn->query("ALTER TABLE users ADD COLUMN referral_code VARCHAR(16) DEFAULT NULL");
            @$conn->query("ALTER TABLE users ADD UNIQUE KEY uniq_referral_code (referral_code)");
        }
        if (!isset($cols['referred_by'])) {
            @$conn->query("ALTER TABLE users ADD COLUMN referred_by INT DEFAULT NULL");
            @$conn->query("ALTER TABLE users ADD INDEX idx_referred_by (referred_by)");
        }

        // تنظیمات قابل ویرایش توسط ادمین (یک ردیف ثابت id=1)
        @$conn->query("CREATE TABLE IF NOT EXISTS `referral_settings` (
            `id` INT PRIMARY KEY,
            `welcome_bonus_eur` DECIMAL(10,2) NOT NULL DEFAULT 3.00,
            `commission_per_tx_eur` DECIMAL(10,2) NOT NULL DEFAULT 0.50,
            `min_settlement_eur` DECIMAL(10,2) NOT NULL DEFAULT 5.00,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) DEFAULT CHARSET=utf8mb4");
        @$conn->query("INSERT IGNORE INTO referral_settings (id, welcome_bonus_eur, commission_per_tx_eur, min_settlement_eur) VALUES (1, 3.00, 0.50, 5.00)");

        // لاگ کامل تمام پاداش‌های معرفی (برای نمودار/گزارش و جلوگیری از پاداش تکراری)
        // نکته‌ی طراحی: deal_id برای پاداش خوش‌آمدگویی همیشه NULL است و برای پاداش
        // تراکنش، شناسه‌ی همان معامله است؛ چون MySQL مقادیر NULL را در UNIQUE KEY
        // متمایز در نظر می‌گیرد (هر NULL با NULL دیگر برابر شمرده نمی‌شود)، برای
        // جلوگیری از پاداش خوش‌آمدگویی تکراری از یک UNIQUE KEY جدا (بدون deal_id)
        // استفاده می‌کنیم که با یک ستون کمکی ثابت (fixed_key) شبیه‌سازی می‌شود.
        @$conn->query("CREATE TABLE IF NOT EXISTS `referral_earnings` (
            `id` INT PRIMARY KEY AUTO_INCREMENT,
            `referrer_id` INT NOT NULL,
            `referred_user_id` INT NOT NULL,
            `type` ENUM('welcome','transaction') NOT NULL,
            `amount_eur` DECIMAL(10,2) NOT NULL,
            `deal_id` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_referrer (referrer_id),
            INDEX idx_referred (referred_user_id),
            INDEX idx_deal (deal_id),
            UNIQUE KEY uniq_reward (referrer_id, referred_user_id, type, deal_id)
        ) DEFAULT CHARSET=utf8mb4");
    }
}

if (!function_exists('ava_ref_settings')) {
    function ava_ref_settings(mysqli $conn) {
        ava_ref_ensure_schema($conn);
        $row = ['welcome_bonus_eur' => 3.00, 'commission_per_tx_eur' => 0.50, 'min_settlement_eur' => 5.00];
        $r = @$conn->query("SELECT welcome_bonus_eur, commission_per_tx_eur, min_settlement_eur FROM referral_settings WHERE id = 1 LIMIT 1");
        if ($r && $r->num_rows === 1) $row = $r->fetch_assoc();
        return [
            'welcome_bonus_eur'     => (float)$row['welcome_bonus_eur'],
            'commission_per_tx_eur' => (float)$row['commission_per_tx_eur'],
            'min_settlement_eur'    => (float)$row['min_settlement_eur'],
        ];
    }
}

if (!function_exists('ava_ref_get_or_create_code')) {
    function ava_ref_get_or_create_code(mysqli $conn, $userId) {
        ava_ref_ensure_schema($conn);
        $userId = (int)$userId;
        $st = $conn->prepare("SELECT referral_code FROM users WHERE id = ? LIMIT 1");
        $st->bind_param("i", $userId);
        $st->execute();
        $res = $st->get_result()->fetch_assoc();
        if ($res && !empty($res['referral_code'])) return $res['referral_code'];

        // ساخت کد ۸ کاراکتری یکتا بر پایه‌ی شناسه‌ی کاربر + رشته‌ی تصادفی
        for ($i = 0; $i < 5; $i++) {
            $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6)) . dechex($userId);
            $code = strtoupper(substr($code, 0, 10));
            $chk = $conn->prepare("SELECT id FROM users WHERE referral_code = ? LIMIT 1");
            $chk->bind_param("s", $code);
            $chk->execute();
            if ($chk->get_result()->num_rows === 0) {
                $up = $conn->prepare("UPDATE users SET referral_code = ? WHERE id = ?");
                $up->bind_param("si", $code, $userId);
                $up->execute();
                return $code;
            }
        }
        // fallback بسیار نامحتمل
        $code = 'U' . $userId . 'X' . rand(100, 999);
        $up = $conn->prepare("UPDATE users SET referral_code = ? WHERE id = ?");
        $up->bind_param("si", $code, $userId);
        $up->execute();
        return $code;
    }
}

if (!function_exists('ava_ref_link')) {
    function ava_ref_link($code) {
        // لینک دعوت مستقیماً به صفحه‌ی ثبت‌نام خودِ سایت (login.php) می‌رود —
        // عمداً کاربر را به تلگرام نمی‌فرستد؛ کد معرف با پارامتر ?ref= حمل می‌شود
        // و login.php آن را تا لحظه‌ی ثبت‌نام (api/register.php) همراه خودش نگه می‌دارد.
        return 'https://aradexchange.com/ledor/login.php?ref=' . $code;
    }
}

if (!function_exists('ava_ref_find_referrer_by_code')) {
    function ava_ref_find_referrer_by_code(mysqli $conn, $code) {
        ava_ref_ensure_schema($conn);
        $code = trim((string)$code);
        if ($code === '') return null;
        $st = $conn->prepare("SELECT id FROM users WHERE referral_code = ? LIMIT 1");
        $st->bind_param("s", $code);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        return $row ? (int)$row['id'] : null;
    }
}

if (!function_exists('ava_ref_credit_wallet')) {
    /** واریز مبلغ به کیف‌پول یورویی کاربر + ثبت در جدول transactions (لاگ رسمی) */
    function ava_ref_credit_wallet(mysqli $conn, $userId, $amountEur, $description) {
        $userId = (int)$userId;
        $amountEur = round((float)$amountEur, 2);
        if ($amountEur <= 0) return false;

        $conn->query("UPDATE users SET balance_eur = balance_eur + " . $amountEur . " WHERE id = " . $userId);

        $txId = 'REF' . time() . rand(1000, 9999);
        $st = $conn->prepare("INSERT INTO transactions (transaction_id, sender_id, receiver_id, amount, currency, type, description, status, completed_at)
                               VALUES (?, NULL, ?, ?, 'EUR', 'admin_credit', ?, 'completed', NOW())");
        $st->bind_param("sids", $txId, $userId, $amountEur, $description);
        $st->execute();

        if (function_exists('oa_dbNotify')) {
            @oa_dbNotify($conn, $userId, 'referral_reward', 'پاداش زیرمجموعه‌گیری 🎁', $description);
        }
        if (function_exists('sendPushToUser')) {
            @sendPushToUser($conn, $userId, 'پاداش زیرمجموعه‌گیری 🎁', $description, 'referral_reward', '/ledor/arad.php');
        }
        return true;
    }
}

if (!function_exists('ava_ref_register_new_user')) {
    /**
     * باید بلافاصله بعد از ساخته‌شدن یک کاربر جدید (ثبت‌نام) صدا زده شود.
     * اگر کد معرفِ معتبری داده شده باشد: referred_by را ست می‌کند و به
     * هر دو طرف (هم معرف و هم کاربر تازه‌ثبت‌نام‌شده) جایزه‌ی خوش‌آمدگویی
     * یکسان واریز می‌کند. مقدار برگشتی: مبلغ جایزه‌ای که به خودِ کاربر
     * تازه‌وارد داده شده (برای نمایش toast در لحظه‌ی ثبت‌نام) یا 0 اگر
     * پاداشی رد‌و‌بدل نشد.
     */
    function ava_ref_register_new_user(mysqli $conn, $newUserId, $refCode) {
        ava_ref_ensure_schema($conn);
        $newUserId = (int)$newUserId;
        $refCode = trim((string)$refCode);
        if ($newUserId <= 0 || $refCode === '') return 0.0;

        $referrerId = ava_ref_find_referrer_by_code($conn, $refCode);
        if (!$referrerId || $referrerId === $newUserId) return 0.0;

        // یک کاربر فقط یک‌بار (در لحظه‌ی ثبت‌نام) می‌تواند معرف داشته باشد
        $st = $conn->prepare("UPDATE users SET referred_by = ? WHERE id = ? AND referred_by IS NULL");
        $st->bind_param("ii", $referrerId, $newUserId);
        $st->execute();
        if ($st->affected_rows <= 0) return 0.0;

        $settings = ava_ref_settings($conn);
        $bonus = $settings['welcome_bonus_eur'];
        if ($bonus <= 0) return 0.0;

        $newUserBonusGiven = 0.0;

        // idempotent: هر (referrer, referred, welcome, deal_id=0) فقط یک‌بار
        $ins = $conn->prepare("INSERT IGNORE INTO referral_earnings (referrer_id, referred_user_id, type, amount_eur, deal_id) VALUES (?, ?, 'welcome', ?, 0)");
        $ins->bind_param("iid", $referrerId, $newUserId, $bonus);
        $ins->execute();
        if ($ins->affected_rows > 0) {
            $nameRow = $conn->query("SELECT first_name, last_name FROM users WHERE id = " . $newUserId)->fetch_assoc();
            $name = trim(($nameRow['first_name'] ?? '') . ' ' . ($nameRow['last_name'] ?? ''));
            if ($name === '') $name = 'کاربر جدید';
            // پاداش سمت معرف (کسی که دعوت کرده)
            ava_ref_credit_wallet($conn, $referrerId, $bonus,
                "🎉 پاداش خوش‌آمدگویی دعوت «{$name}» — " . number_format($bonus, 2) . " یورو به کیف‌پول شما اضافه شد");

            // پاداش سمت کاربر تازه‌وارد (کسی که با لینک دعوت ثبت‌نام کرده) — همان مبلغ
            $referrerNameRow = $conn->query("SELECT first_name, last_name FROM users WHERE id = " . $referrerId)->fetch_assoc();
            $referrerName = trim(($referrerNameRow['first_name'] ?? '') . ' ' . ($referrerNameRow['last_name'] ?? ''));
            if ($referrerName === '') $referrerName = 'یک دوست';
            ava_ref_credit_wallet($conn, $newUserId, $bonus,
                "🎁 خوش آمدید! چون از لینک دعوت «{$referrerName}» ثبت‌نام کردید، " . number_format($bonus, 2) . " یورو به کیف‌پول شما اضافه شد");
            $newUserBonusGiven = $bonus;

        }
        return $newUserBonusGiven;
    }
}

if (!function_exists('ava_ref_process_transaction')) {
    /**
     * باید بعد از تکمیل موفق یک معامله (ad_deal) صدا زده شود.
     * برای هر یک از دو طرف معامله (خریدار/فروشنده) که کاربر دعوت‌شده
     * باشد (referred_by ست باشد)، کمیسیون تراکنش به معرفِ او واریز
     * می‌شود. idempotent بر اساس (referrer, referred_user, deal_id).
     */
    function ava_ref_process_transaction(mysqli $conn, $dealId, array $userIds) {
        ava_ref_ensure_schema($conn);
        $dealId = (int)$dealId;
        $settings = ava_ref_settings($conn);
        $amount = $settings['commission_per_tx_eur'];
        if ($amount <= 0) return;

        $userIds = array_unique(array_map('intval', $userIds));
        foreach ($userIds as $uid) {
            if ($uid <= 0) continue;
            $u = $conn->query("SELECT referred_by, first_name, last_name FROM users WHERE id = " . $uid)->fetch_assoc();
            if (!$u || empty($u['referred_by'])) continue;
            $referrerId = (int)$u['referred_by'];

            $ins = $conn->prepare("INSERT IGNORE INTO referral_earnings (referrer_id, referred_user_id, type, amount_eur, deal_id) VALUES (?, ?, 'transaction', ?, ?)");
            $ins->bind_param("iidi", $referrerId, $uid, $amount, $dealId);
            $ins->execute();
            if ($ins->affected_rows > 0) {
                $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                if ($name === '') $name = 'زیرمجموعه‌ی شما';
                ava_ref_credit_wallet($conn, $referrerId, $amount,
                    "💰 پورسانت معامله‌ی موفق «{$name}» — " . number_format($amount, 2) . " یورو به کیف‌پول شما اضافه شد");
            }
        }
    }
}

if (!function_exists('ava_ref_my_stats')) {
    /** آمار کلی زیرمجموعه‌گیری برای یک کاربر (برای کارت داشبورد/تبادل ارزی) */
    function ava_ref_my_stats(mysqli $conn, $userId) {
        ava_ref_ensure_schema($conn);
        $userId = (int)$userId;
        $code = ava_ref_get_or_create_code($conn, $userId);

        $totalReferred = 0;
        $r = $conn->query("SELECT COUNT(*) c FROM users WHERE referred_by = " . $userId);
        if ($r) $totalReferred = (int)$r->fetch_assoc()['c'];

        $totalEarned = 0.0;
        $r = $conn->query("SELECT COALESCE(SUM(amount_eur),0) s FROM referral_earnings WHERE referrer_id = " . $userId);
        if ($r) $totalEarned = (float)$r->fetch_assoc()['s'];

        $welcomeEarned = 0.0; $txEarned = 0.0;
        $r = $conn->query("SELECT type, COALESCE(SUM(amount_eur),0) s FROM referral_earnings WHERE referrer_id = {$userId} GROUP BY type");
        if ($r) { while ($row = $r->fetch_assoc()) { if ($row['type'] === 'welcome') $welcomeEarned = (float)$row['s']; else $txEarned = (float)$row['s']; } }

        return [
            'code'            => $code,
            'link'            => ava_ref_link($code),
            'total_referred'  => $totalReferred,
            'total_earned'    => round($totalEarned, 2),
            'welcome_earned'  => round($welcomeEarned, 2),
            'tx_earned'       => round($txEarned, 2),
        ];
    }
}

if (!function_exists('ava_ref_my_referrals_list')) {
    /** لیست کاربرانی که این کاربر دعوت کرده، به‌همراه تعداد تراکنش موفق و مبلغ کسب‌شده از هرکدام */
    function ava_ref_my_referrals_list(mysqli $conn, $userId) {
        ava_ref_ensure_schema($conn);
        $userId = (int)$userId;
        $sql = "SELECT u.id, u.first_name, u.last_name, u.avatar, u.created_at,
                       COALESCE(e_tx.cnt, 0)      AS tx_count,
                       COALESCE(e_tx.sum_eur, 0)  AS tx_earned,
                       COALESCE(e_w.sum_eur, 0)   AS welcome_earned
                FROM users u
                LEFT JOIN (
                    SELECT referred_user_id, COUNT(*) cnt, SUM(amount_eur) sum_eur
                    FROM referral_earnings WHERE referrer_id = ? AND type = 'transaction'
                    GROUP BY referred_user_id
                ) e_tx ON e_tx.referred_user_id = u.id
                LEFT JOIN (
                    SELECT referred_user_id, SUM(amount_eur) sum_eur
                    FROM referral_earnings WHERE referrer_id = ? AND type = 'welcome'
                    GROUP BY referred_user_id
                ) e_w ON e_w.referred_user_id = u.id
                WHERE u.referred_by = ?
                ORDER BY u.created_at DESC";
        $st = $conn->prepare($sql);
        $st->bind_param("iii", $userId, $userId, $userId);
        $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as &$row) {
            $row['full_name']    = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: 'کاربر AvaPay';
            $row['total_earned'] = round((float)$row['tx_earned'] + (float)$row['welcome_earned'], 2);
        }
        return $rows;
    }
}

if (!function_exists('ava_ref_earnings_chart')) {
    /** مبلغ کسب‌شده از زیرمجموعه‌گیری به تفکیک ۳۰ روز اخیر (برای نمودار) */
    function ava_ref_earnings_chart(mysqli $conn, $userId, $days = 30) {
        ava_ref_ensure_schema($conn);
        $userId = (int)$userId;
        $days = max(7, min(90, (int)$days));
        $sql = "SELECT DATE(created_at) d, SUM(amount_eur) s
                FROM referral_earnings
                WHERE referrer_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                GROUP BY DATE(created_at) ORDER BY d ASC";
        $st = $conn->prepare($sql);
        $st->bind_param("ii", $userId, $days);
        $st->execute();
        $map = [];
        $res = $st->get_result();
        while ($row = $res->fetch_assoc()) $map[$row['d']] = (float)$row['s'];

        $labels = []; $values = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} day"));
            $labels[] = date('m/d', strtotime($d));
            $values[] = round($map[$d] ?? 0, 2);
        }
        return ['labels' => $labels, 'values' => $values];
    }
}

/* ===================== سمت ادمین ===================== */

if (!function_exists('ava_ref_admin_save_settings')) {
    function ava_ref_admin_save_settings(mysqli $conn, $welcomeBonus, $commissionPerTx, $minSettlement) {
        ava_ref_ensure_schema($conn);
        $welcomeBonus = max(0, round((float)$welcomeBonus, 2));
        $commissionPerTx = max(0, round((float)$commissionPerTx, 2));
        $minSettlement = max(0, round((float)$minSettlement, 2));
        $st = $conn->prepare("UPDATE referral_settings SET welcome_bonus_eur = ?, commission_per_tx_eur = ?, min_settlement_eur = ? WHERE id = 1");
        $st->bind_param("ddd", $welcomeBonus, $commissionPerTx, $minSettlement);
        return $st->execute();
    }
}

if (!function_exists('ava_ref_admin_list')) {
    /** برای پنل ادمین: لیست همه‌ی رابطه‌های معرف→دعوت‌شده */
    function ava_ref_admin_list(mysqli $conn, $search = '') {
        ava_ref_ensure_schema($conn);
        $search = trim((string)$search);
        $sql = "SELECT
                    ref.id AS referrer_id, ref.first_name AS referrer_first, ref.last_name AS referrer_last,
                    ref.referral_code,
                    u.id AS referred_id, u.first_name AS referred_first, u.last_name AS referred_last, u.created_at AS joined_at,
                    COALESCE(tx.cnt, 0) AS tx_count,
                    COALESCE(tx.sum_eur, 0) + COALESCE(w.sum_eur, 0) AS total_earned
                FROM users u
                JOIN users ref ON ref.id = u.referred_by
                LEFT JOIN (SELECT referred_user_id, COUNT(*) cnt, SUM(amount_eur) sum_eur FROM referral_earnings WHERE type='transaction' GROUP BY referred_user_id) tx ON tx.referred_user_id = u.id
                LEFT JOIN (SELECT referred_user_id, SUM(amount_eur) sum_eur FROM referral_earnings WHERE type='welcome' GROUP BY referred_user_id) w ON w.referred_user_id = u.id
                WHERE u.referred_by IS NOT NULL";
        if ($search !== '') {
            $esc = $conn->real_escape_string($search);
            $sql .= " AND (ref.first_name LIKE '%{$esc}%' OR ref.last_name LIKE '%{$esc}%' OR u.first_name LIKE '%{$esc}%' OR u.last_name LIKE '%{$esc}%' OR ref.referral_code LIKE '%{$esc}%')";
        }
        $sql .= " ORDER BY u.created_at DESC LIMIT 500";
        $rows = [];
        $r = $conn->query($sql);
        if ($r) { while ($row = $r->fetch_assoc()) $rows[] = $row; }
        return $rows;
    }
}
