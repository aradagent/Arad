<?php
/**
 * includes/ban_guard.php
 * ---------------------------------------------------------------
 * گاردِ سراسری مسدودیت کاربر.
 *
 * فلسفه‌ی طراحی: به‌جای اینکه در تک‌تک APIها چک مسدودیت بگذاریم (که همیشه
 * یکی‌شان از قلم می‌افتد و همان یکی راه نفوذ می‌شود)، این گارد در
 * config/database.php — یعنی نقطه‌ای که *هر* صفحه و *هر* API بدون استثنا
 * از آن عبور می‌کند — یک‌بار اجرا می‌شود و جلوی همه‌چیز را می‌گیرد.
 *
 * قاعده: کاربر مسدود «هیچ» درخواستی نمی‌تواند بفرستد. تنها استثناها:
 *   - خروج از حساب (تا بتواند از سیستم بیرون برود)
 *   - صفحه‌ی اعلام مسدودیت (تا دلیل را ببیند)
 *   - صفحات ورود/ثبت‌نام (خودشان جداگانه مسدودیت را رد می‌کنند)
 * ---------------------------------------------------------------
 */

if (!function_exists('avapay_ban_is_json_request')) {
    /**
     * آیا این درخواست انتظار پاسخ JSON دارد؟ (API / AJAX / fetch)
     * برای اینکه به کلاینت به‌جای HTML، خطای ساختاریافته بدهیم.
     */
    function avapay_ban_is_json_request(): bool {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (stripos($uri, '/api/') !== false)                 return true;
        if (stripos($uri, 'ajax_action=') !== false)          return true;

        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (stripos($accept, 'application/json') !== false)   return true;

        $xrw = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        if (strcasecmp($xrw, 'XMLHttpRequest') === 0)         return true;

        // fetch() مرورگر این هدر را می‌فرستد؛ ناوبری واقعی مقدارش 'document' است
        $mode = $_SERVER['HTTP_SEC_FETCH_MODE'] ?? '';
        if ($mode !== '' && $mode !== 'navigate')             return true;

        return false;
    }
}

if (!function_exists('avapay_ban_current_script')) {
    function avapay_ban_current_script(): string {
        $s = $_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? '');
        return strtolower(basename($s));
    }
}

if (!function_exists('avapay_ban_is_allowlisted')) {
    /**
     * تنها مسیرهایی که کاربر مسدود هم اجازه‌ی دیدنشان را دارد.
     * عمداً بسیار کوتاه است — هرچه اینجا اضافه شود، یک راه فعالیت باز می‌شود.
     */
    function avapay_ban_is_allowlisted(): bool {
        static $allow = [
            'logout.php',      // خروج از حساب
            'banned.php',      // صفحه‌ی اعلام مسدودیت
            'login.php',       // ورود (خودش مسدودیت را رد می‌کند)
            'register.php',    // ثبت‌نام
            'manifest.php',    // مانیفست PWA (فایل ایستا، بدون هیچ عملیاتی)
            'telegram_entry.php',       // ورود خودکار از دکمه‌ی تلگرام (صفحه + API ورود، هر دو در همین یک فایل — خودش مسدودیت را جداگانه رد می‌کند)
        ];
        return in_array(avapay_ban_current_script(), $allow, true);
    }
}

if (!function_exists('avapay_user_ban_info')) {
    /**
     * وضعیت مسدودیت کاربر را از دیتابیس می‌خواند.
     * اگر ستون‌ها هنوز ساخته نشده باشند (دیتابیس قدیمی) خودش می‌سازد.
     *
     * @return array{banned:bool,reason:string,at:?string}
     */
    function avapay_user_ban_info($conn, int $userId): array {
        $none = ['banned' => false, 'reason' => '', 'at' => null];
        if (!($conn instanceof mysqli) || $userId <= 0) return $none;

        try {
            // خوددرمانی ستون‌ها — فقط یک‌بار در هر سشن بررسی می‌شود
            if (empty($_SESSION['avapay_ban_cols'])) {
                $chk = $conn->query("SHOW COLUMNS FROM `users` LIKE 'is_banned'");
                if ($chk && $chk->num_rows === 0) {
                    @$conn->query("ALTER TABLE `users` ADD COLUMN `is_banned` TINYINT(1) NOT NULL DEFAULT 0");
                    @$conn->query("ALTER TABLE `users` ADD COLUMN `ban_reason` VARCHAR(255) DEFAULT NULL");
                    @$conn->query("ALTER TABLE `users` ADD COLUMN `banned_at` DATETIME DEFAULT NULL");
                }
                if ($chk) $chk->free();
                $_SESSION['avapay_ban_cols'] = 1;
            }

            $stmt = $conn->prepare("SELECT is_banned, ban_reason, banned_at FROM users WHERE id = ? LIMIT 1");
            if (!$stmt) return $none;
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row) return $none;
            return [
                'banned' => !empty($row['is_banned']),
                'reason' => (string)($row['ban_reason'] ?? ''),
                'at'     => $row['banned_at'] ?? null,
            ];
        } catch (\Throwable $e) {
            error_log('avapay_user_ban_info: ' . $e->getMessage());
            return $none;   // خطای دیتابیس نباید کاربر سالم را قفل کند
        }
    }
}

if (!function_exists('avapay_revoke_all_sessions')) {
    /**
     * تمام توکن‌های ۳۰ روزه‌ی کاربر را باطل می‌کند.
     * بدون این کار، کاربر مسدود می‌توانست با کوکی auth_token سشن تازه بسازد.
     */
    function avapay_revoke_all_sessions($conn, int $userId): void {
        if (!($conn instanceof mysqli) || $userId <= 0) return;
        try {
            $stmt = $conn->prepare("DELETE FROM sessions WHERE user_id = ?");
            if ($stmt) { $stmt->bind_param("i", $userId); $stmt->execute(); $stmt->close(); }
        } catch (\Throwable $e) {
            error_log('avapay_revoke_all_sessions: ' . $e->getMessage());
        }
    }
}

if (!function_exists('avapay_ban_block_request')) {
    /**
     * درخواست را قطع می‌کند: JSON برای API، ریدایرکت برای صفحات.
     * هیچ کدی بعد از این تابع اجرا نمی‌شود.
     */
    function avapay_ban_block_request(array $info): void {
        $reason = trim($info['reason'] ?? '');
        $msg = 'حساب کاربری شما مسدود شده است و امکان انجام هیچ عملیاتی وجود ندارد.';
        if ($reason !== '') $msg .= ' دلیل: ' . $reason;

        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
        }

        if (avapay_ban_is_json_request()) {
            if (!headers_sent()) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'success'   => false,
                'banned'    => true,
                'code'      => 'ACCOUNT_BANNED',
                'message'   => $msg,
                'reason'    => $reason,
                'banned_at' => $info['at'] ?? null,
                'redirect'  => 'banned.php',
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }

        // درخواست معمولی صفحه → به صفحه‌ی اعلام مسدودیت
        if (!headers_sent()) {
            header('Location: banned.php');
            exit();
        }
        // اگر هدر قبلاً ارسال شده بود، دست‌کم صفحه را همین‌جا متوقف کن
        echo '<script>location.replace("banned.php");</script>';
        exit();
    }
}

if (!function_exists('avapay_ban_guard')) {
    /**
     * نقطه‌ی ورود اصلی. از انتهای config/database.php صدا زده می‌شود،
     * یعنی روی *هر* درخواستی که به سیستم می‌رسد.
     */
    function avapay_ban_guard($conn): void {
        if (PHP_SAPI === 'cli')                 return;   // اسکریپت‌های کران
        if (defined('AVAPAY_BAN_GUARD_RAN'))    return;   // فقط یک‌بار در هر درخواست
        if (!($conn instanceof mysqli))         return;

        define('AVAPAY_BAN_GUARD_RAN', 1);

        if (session_status() !== PHP_SESSION_ACTIVE) return;

        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) return;               // مهمان — چیزی برای مسدود کردن نیست

        if (avapay_ban_is_allowlisted()) return;

        $info = avapay_user_ban_info($conn, $userId);
        if (!$info['banned']) return;

        // کاربر مسدود است: توکن‌های ماندگارش را هم باطل کن تا با بستن مرورگر
        // یا از دستگاه دیگر نتواند دوباره وارد شود.
        avapay_revoke_all_sessions($conn, $userId);
        $_SESSION['avapay_ban_reason'] = $info['reason'];
        $_SESSION['avapay_ban_at']     = $info['at'];

        avapay_ban_block_request($info);
    }
}
