<?php
/**
 * cron_rates_sync.php
 * ---------------------------------------------------------------------------
 * همگام‌سازی نرخ ارز، طلا و سکه از الان‌چند — به‌صورت مستقل از باز بودن اپ.
 *
 * چرا لازم است؟
 *   فایل includes/alanchand_sync.php فقط هنگام باز شدن داشبورد اجرا می‌شد.
 *   اگر هیچ کاربری آنلاین نباشد، نرخ‌ها به‌روز نمی‌شوند و در نتیجه
 *   «هشدارهای نرخ ارز» با قیمت کهنه مقایسه می‌شوند یا اصلاً فعال نمی‌شوند.
 *
 * کران‌جاب پیشنهادی (هر ۵ دقیقه):
 *   هر-۵-دقیقه  php /home/aradexch/public_html/ledor/cron_rates_sync.php >/dev/null 2>&1
 *   (الگوی زمانی را در پنل هاست روی «هر ۵ دقیقه» بگذارید)
 * ---------------------------------------------------------------------------
 */

@set_time_limit(90);
@ignore_user_abort(true);

define('AVAPAY_RATES_CRON_KEY', 'ARAD-cron-7X29pLqz'); // ← همان کلید کران خودتان

$__isCli = (php_sapi_name() === 'cli');

if (!$__isCli) {
    header('Content-Type: application/json; charset=UTF-8');
    if (!hash_equals(AVAPAY_RATES_CRON_KEY, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        exit;
    }
}

require_once __DIR__ . '/config/database.php';
if (!isset($conn) || !($conn instanceof mysqli)) {
    echo json_encode(['error' => 'db']);
    exit;
}

// اجبار به همگام‌سازی: سن مجاز کش را صفر می‌کنیم تا حتماً تازه بگیرد
$GLOBALS['AVA_SYNC_MAX_AGE'] = 0;

$before = null;
$q = @$conn->query("SELECT MAX(updated_at) AS m FROM currency_rates");
if ($q && ($r = $q->fetch_assoc())) $before = $r['m'];

@include __DIR__ . '/includes/alanchand_sync.php';

$after = null; $count = 0;
$q2 = @$conn->query("SELECT MAX(updated_at) AS m, COUNT(*) AS c FROM currency_rates");
if ($q2 && ($r2 = $q2->fetch_assoc())) { $after = $r2['m']; $count = (int)$r2['c']; }

$out = [
    'success'      => true,
    'rates_count'  => $count,
    'updated_from' => $before,
    'updated_to'   => $after,
    'changed'      => ($before !== $after),
    'time'         => date('c'),
];

echo json_encode($out, JSON_UNESCAPED_UNICODE) . ($__isCli ? "\n" : '');
