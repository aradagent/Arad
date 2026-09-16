<?php
// api/logout.php
// ------------------------------------------------------------------
// خروج کامل از حساب:
//   ۱) حذف توکن(ها) از جدول sessions  (all=1 → خروج از همه‌ی دستگاه‌ها)
//   ۲) پاک کردن کوکی auth_token و user_id با همان پارامترهایی که ست شده‌اند
//   ۳) تخریب کامل سشن PHP و کوکی سشن
//
// نکته‌ی مهم: کوکی سشن PHP معمولاً HttpOnly است و جاوااسکریپت نمی‌تواند
// آن را پاک کند. اگر خروج فقط سمت کلاینت انجام شود، سشن سرور زنده می‌ماند
// و صفحه‌ی ورود دوباره کاربر را به داشبورد برمی‌گرداند. بنابراین خروج
// حتماً باید از این فایل (سمت سرور) انجام شود.
// ------------------------------------------------------------------

require_once __DIR__ . '/../includes/session_boot.php';
require_once __DIR__ . '/../config/database.php';

$wantsJson = !isset($_GET['redirect']);
if ($wantsJson) header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$userId = (int)($_SESSION['user_id'] ?? 0);

// توکن از پارامتر یا کوکی
$token = $_GET['token'] ?? null;
if (!$token && isset($_COOKIE['auth_token'])) {
    $token = $_COOKIE['auth_token'];
}

// خروج از همه‌ی دستگاه‌ها؟
$logoutAll = isset($_GET['all']) && $_GET['all'] === '1';

try {
    if ($logoutAll && $userId > 0) {
        $st = $conn->prepare("DELETE FROM sessions WHERE user_id = ?");
        if ($st) { $st->bind_param("i", $userId); $st->execute(); $st->close(); }
    } elseif ($token) {
        $st = $conn->prepare("DELETE FROM sessions WHERE token = ?");
        if ($st) { $st->bind_param("s", $token); $st->execute(); $st->close(); }
    }

    // کاربر دیگر آنلاین نیست
    if ($userId > 0) {
        try { $conn->query("UPDATE users SET last_seen = NULL WHERE id = {$userId}"); } catch (Throwable $e) {}
    }
} catch (Throwable $e) {
    error_log('logout: ' . $e->getMessage());
}

// ---- پاک کردن کوکی‌ها با همان پارامترهای زمان ساخت ----
$secure = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
);
$killOpts = [
    'expires'  => time() - 42000,
    'path'     => '/',
    'secure'   => $secure,
    'httponly' => false,
    'samesite' => 'Lax',
];
@setcookie('auth_token', '', $killOpts);
@setcookie('user_id', '', $killOpts);
// نسخه‌ی ساده هم برای اطمینان (کوکی‌های قدیمی بدون samesite)
@setcookie('auth_token', '', time() - 42000, '/');
@setcookie('user_id', '', time() - 42000, '/');

// ---- تخریب سشن PHP ----
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    @setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $p['path'] ?: '/',
        'domain'   => $p['domain'] ?? '',
        'secure'   => $p['secure'] ?? $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
@session_destroy();

// خروج با ریدایرکت مستقیم (برای لینک‌های ساده) یا JSON (برای fetch)
if (!$wantsJson) {
    header('Location: /ledor/login.php?logout=success&t=' . time());
    exit();
}

echo json_encode(['success' => true, 'message' => 'Logged out successfully']);
