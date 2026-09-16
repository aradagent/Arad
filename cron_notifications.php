<?php
/**
 * cron_notifications.php
 * ---------------------------------------------------------------------------
 * کارگرِ صف اعلان‌ها — تنها نقطه‌ی ارسال واقعی نوتیفیکیشن‌ها.
 *
 * همه‌ی بخش‌های برنامه (پنل مدیریت، تبادل ارزی، پیشنهادها، تسویه، KYC،
 * برداشت، شارژ، هشدار قیمت و ...) اعلان‌هایشان را با notifyUser() در صف
 * `notification_queue` ثبت می‌کنند. این فایل صف را خالی می‌کند و ایمیل،
 * تلگرام و Web Push را می‌فرستد.
 *
 * نصب کران‌جاب (هر ۱ دقیقه):
 *   * * * * * php /home/aradexch/public_html/ledor/cron_notifications.php >/dev/null 2>&1
 *
 * یا از طریق وب:
 *   * * * * * curl -s "https://aradexchange.com/ledor/cron_notifications.php?key=CRON_KEY" >/dev/null 2>&1
 *
 * بررسی وضعیت (در مرورگر):
 *   cron_notifications.php?key=CRON_KEY&status=1
 * ---------------------------------------------------------------------------
 */

@set_time_limit(120);
@ignore_user_abort(true);

define('AVAPAY_NOTIF_CRON_KEY', 'ARAD-cron-7X29pLqz'); // ← همان کلید کران خودتان

$__isCli = (php_sapi_name() === 'cli');

if (!$__isCli) {
    header('Content-Type: application/json; charset=UTF-8');
    $key = $_GET['key'] ?? '';
    if (!hash_equals(AVAPAY_NOTIF_CRON_KEY, (string)$key)) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        exit;
    }
}

// ---- ثبت زمان اجرا برای تشخیص فعال بودن کران ----
$__runFile = sys_get_temp_dir() . '/ava_cron_notif_last_run.txt';

require_once __DIR__ . '/config/database.php';
if (!isset($conn) || !($conn instanceof mysqli)) {
    if (!$__isCli) echo json_encode(['error' => 'db']);
    exit;
}
require_once __DIR__ . '/includes/notify_helper.php';

// ---- حالت وضعیت ----
if (!$__isCli && !empty($_GET['status'])) {
    $last = is_readable($__runFile) ? (int)@file_get_contents($__runFile) : 0;
    $counts = ['pending' => 0, 'sent' => 0, 'failed' => 0];
    $q = @$conn->query("SELECT status, COUNT(*) AS c FROM notification_queue GROUP BY status");
    if ($q) while ($r = $q->fetch_assoc()) $counts[$r['status']] = (int)$r['c'];
    echo json_encode([
        'last_run'    => $last ? date('c', $last) : null,
        'seconds_ago' => $last ? (time() - $last) : null,
        'running'     => ($last && (time() - $last) < 180),
        'queue'       => $counts,
        'server_time' => date('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

@file_put_contents($__runFile, (string)time(), LOCK_EX);

// ---- پردازش صف ----
// در هر اجرا حداکثر ۳۰ اعلان تا کران از یک دقیقه فراتر نرود
$stat = function_exists('processNotificationQueue')
    ? processNotificationQueue($conn, 30)
    : ['processed' => 0, 'sent' => 0, 'failed' => 0, 'error' => 'helper missing'];

// ---- پاک‌سازی: حذف اعلان‌های ارسال‌شده‌ی قدیمی‌تر از ۷ روز ----
@$conn->query("DELETE FROM notification_queue
               WHERE status = 'sent' AND sent_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");

$out = ['success' => true] + $stat + ['time' => date('c')];

if ($__isCli) {
    echo json_encode($out, JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
}
