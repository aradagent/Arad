<?php
/**
 * includes/session_boot.php
 * ---------------------------------------------------------------
 * راه‌اندازی سشن ماندگار (Persistent Session)
 *
 * این فایل باید «قبل از هر session_start» در تمام صفحات include شود.
 * کاری که می‌کند:
 *   1) پارامترهای کوکی سشن را روی ۳۰ روز تنظیم می‌کند (قبل از start).
 *   2) سشن را استارت می‌کند.
 *   3) اگر $_SESSION['user_id'] خالی بود ولی کوکی auth_token معتبر
 *      در جدول sessions وجود داشت، سشن را از روی توکن بازسازی می‌کند.
 *
 * نتیجه: کاربر تا زمانی که توکن ۳۰ روزه معتبر است لاگین می‌ماند،
 * حتی اگر گاربیج‌کالکتور PHP سشن سرور را پاک کند یا مرورگر بسته شود.
 * ---------------------------------------------------------------
 */

if (!defined('AVAPAY_SESSION_LIFETIME')) {
    define('AVAPAY_SESSION_LIFETIME', 60 * 60 * 24 * 30); // ۳۰ روز
}

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {

    // طول عمر گاربیج‌کالکتور و کوکی سشن = ۳۰ روز
    @ini_set('session.gc_maxlifetime', AVAPAY_SESSION_LIFETIME);
    @ini_set('session.cookie_lifetime', AVAPAY_SESSION_LIFETIME);
    @ini_set('session.use_strict_mode', 1);

    // پارامترهای کوکی سشن را قبل از start ثابت کن
    $secure = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    );

    session_set_cookie_params([
        'lifetime' => AVAPAY_SESSION_LIFETIME,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();

    // هر بار که کاربر فعال است، عمر کوکی سشن را تازه کن (rolling session)
    if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
        $params = session_get_cookie_params();
        @setcookie(session_name(), session_id(), [
            'expires'  => time() + AVAPAY_SESSION_LIFETIME,
            'path'     => $params['path'] ?: '/',
            'domain'   => $params['domain'] ?? '',
            'secure'   => $params['secure'] ?? $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

/**
 * ثبت حضور کاربر (وضعیت آنلاین + زمان ورود) در جدول users.
 *
 * $isFreshOpen = true یعنی «باز شدن تازه‌ی برنامه» (بازسازی سشن از روی توکن
 * یا اولین بازدید در این سشن) و در این حالت last_login هم به‌روز می‌شود تا
 * کاربر در لیست «وضعیت آنلاین و زمان ورود» پنل ادمین دیده شود — حتی اگر
 * قبلاً لاگین کرده باشد و دیگر از صفحه‌ی ورود عبور نکند.
 */
if (!function_exists('avapay_touch_presence')) {
    function avapay_touch_presence($conn, $isFreshOpen = false) {
        if (!($conn instanceof mysqli)) return;
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) return;

        // اگر ادمین در حال ورود مخفیانه به داشبورد این کاربر است (api/admin_impersonate.php)،
        // هرگز last_seen/last_login این کاربر را به‌روز نکن — وگرنه خودِ همین آپدیت لو می‌دهد
        // که کسی به حسابش وارد شده، دقیقاً همان چیزی که این قابلیت قرار است از آن جلوگیری کند.
        if (!empty($_SESSION['impersonator_admin_id'])) return;

        // ------------------------------------------------------------------
        // نکته‌ی مهم: در PHP 8 درایور mysqli خطاها را به‌صورت Exception پرتاب
        // می‌کند و عملگر @ جلوی آن را نمی‌گیرد. بنابراین:
        //   ۱) ابتدا با SHOW COLUMNS بررسی می‌کنیم ستون هست یا نه
        //   ۲) کل بلوک داخل try/catch است تا هیچ خطای دیتابیسی صفحه را نشکند
        // ------------------------------------------------------------------
        try {
            if (empty($_SESSION['avapay_presence_col'])) {
                $hasCol = false;
                $chk = $conn->query("SHOW COLUMNS FROM `users` LIKE 'last_seen'");
                if ($chk) {
                    $hasCol = ($chk->num_rows > 0);
                    $chk->free();
                }
                if (!$hasCol) {
                    try {
                        $conn->query("ALTER TABLE `users` ADD COLUMN `last_seen` DATETIME NULL DEFAULT NULL");
                    } catch (Throwable $e) {
                        // ستون همزمان توسط درخواست دیگری ساخته شده — بی‌اهمیت
                    }
                }
                $_SESSION['avapay_presence_col'] = 1;
            }

            // «باز شدن تازه‌ی برنامه» یعنی: یا سشن از روی توکن بازسازی شده، یا
            // بیش از ۳۰ دقیقه از آخرین فعالیت گذشته (کاربر اپ را بسته و دوباره باز کرده).
            $lastSeenWrite = (int)($_SESSION['avapay_seen_write'] ?? 0);
            $gapIsNewVisit = ($lastSeenWrite === 0) || ((time() - $lastSeenWrite) > 1800);

            if ($isFreshOpen || $gapIsNewVisit) {
                $conn->query("UPDATE users SET last_seen = NOW(), last_login = NOW() WHERE id = {$uid}");
                $_SESSION['avapay_seen_write'] = time();
                return;
            }

            // در ادامه‌ی همان بازدید: فقط هر ۶۰ ثانیه یک‌بار last_seen را تازه کن
            if ((time() - $lastSeenWrite) > 60) {
                $conn->query("UPDATE users SET last_seen = NOW() WHERE id = {$uid}");
                $_SESSION['avapay_seen_write'] = time();
            }
        } catch (Throwable $e) {
            // ثبت حضور هرگز نباید باعث خطای صفحه شود
            error_log('avapay_touch_presence: ' . $e->getMessage());
        }
    }
}

/**
 * اگر سشن کاربر خالی است، تلاش کن از روی کوکی auth_token آن را بازسازی کنی.
 * این تابع به یک اتصال mysqli فعال ($conn) نیاز دارد؛ اگر موجود نبود،
 * بی‌سروصدا رد می‌شود و صفحه طبق روال قبلی رفتار می‌کند.
 */
if (!function_exists('avapay_restore_session_from_token')) {
    function avapay_restore_session_from_token($conn) {
        if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
            avapay_touch_presence($conn, false);   // ثبت حضور برای سشن فعال
            return true; // سشن از قبل معتبر است
        }
        if (!($conn instanceof mysqli)) {
            return false;
        }

        // توکن را از کوکی یا هدر Authorization بگیر
        $token = null;
        if (isset($_COOKIE['auth_token']) && $_COOKIE['auth_token'] !== '') {
            $token = trim($_COOKIE['auth_token']);
        }
        if (!$token && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            if (preg_match('/Bearer\s+(.+)$/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
                $token = trim($m[1]);
            }
        }
        if (!$token) {
            return false;
        }

        // بررسی توکن در جدول sessions (باید معتبر و منقضی‌نشده باشد)
        $stmt = $conn->prepare(
            "SELECT user_id FROM sessions WHERE token = ? AND expires_at > NOW() LIMIT 1"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            $restoredId = intval($row['user_id']);
            $stmt->close();

            // ---- کاربر مسدود هرگز نباید از روی توکن ماندگار دوباره وارد شود ----
            // بدون این بررسی، کاربر مسدود با کوکی auth_token سشن تازه می‌ساخت.
            if (is_readable(__DIR__ . '/ban_guard.php')) {
                require_once __DIR__ . '/ban_guard.php';
                if (function_exists('avapay_user_ban_info')) {
                    $banInfo = avapay_user_ban_info($conn, $restoredId);
                    if (!empty($banInfo['banned'])) {
                        avapay_revoke_all_sessions($conn, $restoredId);   // توکن‌ها را باطل کن
                        @setcookie('auth_token', '', time() - 3600, '/'); // کوکی را پاک کن
                        return false;                                     // سشن ساخته نمی‌شود
                    }
                }
            }

            $_SESSION['user_id']       = $restoredId;
            $_SESSION['last_activity'] = time();

            // تمدید خودکار توکن تا ۳۰ روز دیگر (کاربر فعال است)
            $newExpiry = date('Y-m-d H:i:s', time() + AVAPAY_SESSION_LIFETIME);
            $upd = $conn->prepare("UPDATE sessions SET expires_at = ? WHERE token = ?");
            if ($upd) {
                $upd->bind_param("ss", $newExpiry, $token);
                $upd->execute();
                $upd->close();
            }

            // این یک «باز شدن تازه‌ی برنامه» است → ثبت در وضعیت آنلاین و زمان ورود
            avapay_touch_presence($conn, true);
            return true;
        }
        $stmt->close();
        return false;
    }
}

/* ------------------------------------------------------------------
 * توکن CSRF یکتا برای کل سشن (نه هر صفحه جدا) — یک‌بار ساخته می‌شود و
 * تا پایان سشن ثابت می‌ماند. صفحاتی که آن را در <meta name="csrf-token">
 * چاپ می‌کنند و fetch-wrapper خودشان را دارند (arad.php، dashboard.php)
 * آن را به‌صورت خودکار در هدر X-CSRF-Token می‌فرستند؛ includes/csrf.php
 * همین مقدار را برای اعتبارسنجی می‌خواند.
 * ------------------------------------------------------------------ */
if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['csrf_token_arad'])) {
    $_SESSION['csrf_token_arad'] = bin2hex(random_bytes(32));
}
