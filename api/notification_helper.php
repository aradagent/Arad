<?php
/**
 * api/notification_helper.php
 * ------------------------------------------------------------------
 * پل سازگاری (shim)
 *
 * چند فایل داخل api/ این مسیر را require می‌کردند در حالی که پیاده‌سازی
 * واقعی توابع اعلان در includes/notify_helper.php قرار دارد. نبودِ این
 * فایل باعث «Fatal error: require_once(): Failed opening required» می‌شد
 * و در نتیجه خروجی API به‌جای JSON، صفحه‌ی خطای HTML بود؛ به همین دلیل
 * پنل مدیریت هنگام باز کردن «مدیریت تسویه» پیام «خطا» نشان می‌داد.
 *
 * این فایل صرفاً پیاده‌سازی اصلی را بارگذاری می‌کند و در صورت نبودِ آن،
 * نسخه‌ی بی‌اثر (no-op) از توابع را تعریف می‌کند تا هیچ‌گاه Fatal رخ ندهد.
 * ------------------------------------------------------------------
 */

$__notifyHelperPaths = [
    __DIR__ . '/../includes/notify_helper.php',
    __DIR__ . '/../../includes/notify_helper.php',
    __DIR__ . '/notify_helper.php',
];

foreach ($__notifyHelperPaths as $__p) {
    if (is_file($__p)) { require_once $__p; break; }
}
unset($__notifyHelperPaths, $__p);

/* ------------------------------------------------------------------ */
/* Fallbackها: اگر به هر دلیلی فایل اصلی پیدا نشد، به‌جای Fatal error    */
/* توابع خالی تعریف می‌شوند تا جریان اصلی (ذخیره در دیتابیس) نشکند.     */
/* ------------------------------------------------------------------ */

if (!function_exists('sendDbNotification')) {
    function sendDbNotification($conn, $userId, $type, $title, $message, $relatedId = null) {
        try {
            $userId = (int)$userId;
            $type    = $conn->real_escape_string((string)$type);
            $title   = $conn->real_escape_string((string)$title);
            $message = $conn->real_escape_string((string)$message);
            $rid     = $relatedId === null ? 'NULL' : (int)$relatedId;
            return (bool)@$conn->query(
                "INSERT INTO user_notifications (user_id, type, title, message, related_id, is_read, created_at)
                 VALUES ($userId, '$type', '$title', '$message', $rid, 0, NOW())"
            );
        } catch (Throwable $e) { return false; }
    }
}

if (!function_exists('sendPushToUser')) {
    function sendPushToUser($conn, $userId, $title, $body, $type = 'general', $url = '/ledor/dashboard.php', $relatedId = null) {
        return 0;
    }
}

if (!function_exists('sendEmailNotification')) {
    function sendEmailNotification($conn, $userId, $title, $body) { return false; }
}

if (!function_exists('sendTelegramNotification')) {
    function sendTelegramNotification($conn, $userId, $title, $body) { return false; }
}

if (!function_exists('notifyUser')) {
    function notifyUser($conn, $userId, $title, $body, $opts = []) {
        $type      = $opts['type']       ?? 'general';
        $relatedId = $opts['related_id'] ?? null;
        return ['db' => sendDbNotification($conn, $userId, $type, $title, $body, $relatedId),
                'push' => 0, 'email' => false, 'telegram' => false];
    }
}
