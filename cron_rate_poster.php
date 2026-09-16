<?php
/**
 * cron_rate_poster.php
 * ---------------------------------------------------------------------------
 * اسنپ‌شات روزانه‌ی نرخ‌ها برای «عکس نرخ لحظه‌ای».
 *
 * دو نوبت در روز اجرا می‌شود و نرخ همان لحظه را فریز می‌کند:
 *     ۰۶:۰۰ صبح  → slot = morning
 *     ۱۵:۰۰ عصر  → slot = afternoon
 *
 * کران‌جاب پیشنهادی (وقت تهران):
 *     0 6  * * *  php /home/aradexch/public_html/ledor/cron_rate_poster.php >/dev/null 2>&1
 *     0 15 * * *  php /home/aradexch/public_html/ledor/cron_rate_poster.php >/dev/null 2>&1
 *
 * اگر سرور هاست فقط اجرای وب دارد، با کلید هم قابل صدا زدن است:
 *     https://.../ledor/cron_rate_poster.php?key=ARAD-cron-7X29pLqz
 *
 * پارامتر اختیاری slot=morning|afternoon اسلات را دستی تعیین می‌کند؛
 * در غیر این صورت از ساعت اجرا حدس زده می‌شود.
 * ---------------------------------------------------------------------------
 */

@set_time_limit(120);
@ignore_user_abort(true);
date_default_timezone_set('Asia/Tehran');

define('AVAPAY_POSTER_CRON_KEY', 'ARAD-cron-7X29pLqz'); // ← همان کلید کران‌های دیگر

$__isCli = (php_sapi_name() === 'cli');

if (!$__isCli) {
    header('Content-Type: application/json; charset=UTF-8');
    if (!hash_equals(AVAPAY_POSTER_CRON_KEY, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        exit;
    }
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/rate_poster_helper.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    echo json_encode(['error' => 'db']);
    exit;
}

// ---- تعیین اسلات ----
$slot = (string)($_GET['slot'] ?? ($argv[1] ?? ''));
if ($slot !== 'morning' && $slot !== 'afternoon') {
    $h = (int)date('G');
    // پنجره‌ی تحمل: اگر کران چند دقیقه دیرتر اجرا شد، باز هم درست دسته‌بندی شود
    $slot = ($h >= 12) ? 'afternoon' : 'morning';
}

// قفل مشترک با «تولید دستی» تا دو اسکن سنگین هم‌زمان اجرا نشوند
$lock = rp_lock_acquire();
if ($lock === false) {
    echo json_encode(['success' => false, 'reason' => 'busy', 'time' => date('c')]) . ($__isCli ? "\n" : '');
    exit;
}

$snap = rp_build_snapshot($conn, true);

$filled = 0;
foreach ($snap['items'] as $it) if (($it['price'] ?? 0) > 0) $filled++;

// اگر هیچ نرخی نگرفتیم، اسنپ‌شات خالی ذخیره نکن — اسنپ‌شات دیروز بهتر از هیچ است
if ($filled === 0) {
    rp_lock_release($lock);
    echo json_encode(['success' => false, 'reason' => 'no rates fetched', 'time' => date('c')])
        . ($__isCli ? "\n" : '');
    exit;
}

$id = rp_store_snapshot($conn, $snap, $slot, null);

// ---- اطلاع به ادمین‌ها ----
// نکته: کران مرورگر ندارد، پس خودِ فایل PNG را نمی‌سازد. اینجا فقط خبر می‌دهد
// که اسنپ‌شات آماده است؛ ادمین با یک کلیک در پنل، عکس را به تلگرام می‌فرستد.
try {
    $slotFa = ($slot === 'morning') ? 'صبح ۰۶:۰۰' : 'بعدازظهر ۱۵:۰۰';
    $link   = 'https://aradexchange.com/ledor/rate_poster.php?id=' . (int)$id;
    $msg    = "📊 <b>نرخ‌های " . $slotFa . " آماده شد</b>\n"
            . "🗓 " . $snap['title_date'] . " — ساعت " . $snap['title_time'] . "\n"
            . "✅ " . $filled . " از " . count($snap['items']) . " نرخ دریافت شد\n\n"
            . "برای ارسال عکس، در پنل ادمین بخش «عکس نرخ لحظه‌ای» را باز کنید.\n"
            . "<a href='" . $link . "'>مشاهده‌ی قالب</a>";

    $q = @$conn->query("SELECT telegram_id FROM users WHERE is_admin = 1 AND telegram_id IS NOT NULL AND telegram_id <> ''");
    if ($q) while ($r = $q->fetch_assoc()) {
        if (function_exists('sendTelegramMessage')) {
            @sendTelegramMessage($r['telegram_id'], $msg);
        }
    }
} catch (Throwable $e) { error_log('rate poster cron notify: ' . $e->getMessage()); }

echo json_encode([
    'success' => true,
    'id'      => $id,
    'slot'    => $slot,
    'filled'  => $filled,
    'total'   => count($snap['items']),
    'time'    => date('c'),
], JSON_UNESCAPED_UNICODE) . ($__isCli ? "\n" : '');

rp_lock_release($lock);
