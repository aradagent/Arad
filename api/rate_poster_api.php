<?php
/**
 * api/rate_poster_api.php
 * ---------------------------------------------------------------------------
 * مدیریت «عکس نرخ لحظه‌ای» برای پنل ادمین.
 *
 *   ?action=list            → فهرست اسنپ‌شات‌های ساخته‌شده
 *   ?action=generate        → ساخت اسنپ‌شات دستی با نرخ همین لحظه
 *   ?action=delete&id=N     → حذف یک اسنپ‌شات
 * ---------------------------------------------------------------------------
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../includes/session_boot.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rate_poster_helper.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'لطفاً وارد شوید']);
    exit;
}
$st = $conn->prepare("SELECT is_admin FROM users WHERE id = ?");
$st->bind_param("i", $userId); $st->execute();
$u = $st->get_result()->fetch_assoc(); $st->close();
if (!$u || (int)$u['is_admin'] !== 1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'دسترسی ندارید']);
    exit;
}

rp_ensure_table($conn);
$action = $_GET['action'] ?? 'list';

/* ---------------- فهرست ---------------- */
if ($action === 'list') {
    $items = [];
    $labels = ['morning' => 'صبح ۶:۰۰', 'afternoon' => 'بعدازظهر ۱۵:۰۰', 'manual' => 'دستی'];
    try {
        $q = $conn->query("SELECT id, slot, slot_date, title_time, title_date,
                                  UNIX_TIMESTAMP(created_at) AS ts
                           FROM rate_posters ORDER BY created_at DESC LIMIT 60");
        if ($q) while ($r = $q->fetch_assoc()) {
            $items[] = [
                'id'         => (int)$r['id'],
                'slot'       => $r['slot'],
                'slot_label' => $labels[$r['slot']] ?? $r['slot'],
                'date'       => $r['slot_date'],
                'title_time' => $r['title_time'],
                'title_date' => $r['title_date'],
                'ts'         => (int)$r['ts'],
            ];
        }
    } catch (Throwable $e) {}
    echo json_encode(['success' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------------- تولید دستی ---------------- */
if ($action === 'generate') {
    @set_time_limit(90);
    @ignore_user_abort(true);

    // قفل: اگر تولید دیگری در جریان است، فوراً برگرد به‌جای اجرای موازی.
    // بدون این، چند بار زدن دکمه چند اسکن سنگین هم‌زمان می‌ساخت و سرور
    // برای چند دقیقه از دسترس خارج می‌شد.
    $lock = rp_lock_acquire();
    if ($lock === false) {
        echo json_encode([
            'success' => false,
            'busy'    => true,
            'message' => 'یک تولید دیگر در حال اجراست — چند لحظه صبر کنید',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $snap = rp_build_snapshot($conn, true);
        $filled = 0;
        foreach ($snap['items'] as $it) if (($it['price'] ?? 0) > 0) $filled++;
        if ($filled === 0) {
            echo json_encode(['success' => false, 'message' => 'دریافت نرخ‌ها ناموفق بود — بعداً تلاش کنید'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $id = rp_store_snapshot($conn, $snap, 'manual', $userId);
        echo json_encode([
            'success' => true,
            'id'      => $id,
            'filled'  => $filled,
            'total'   => count($snap['items']),
            'message' => 'نرخ‌ها گرفته شد (' . $filled . ' از ' . count($snap['items']) . ')',
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'خطا: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    } finally {
        rp_lock_release($lock);
    }
    exit;
}

/* ---------------- ارسال عکس به ربات تلگرام ادمین ----------------
   عکس در مرورگرِ ادمین با html2canvas ساخته و به‌صورت فایل اینجا
   آپلود می‌شود؛ سپس با sendPhoto به آیدی تلگرام همان ادمین می‌رود.
   دلیل این معماری: ساخت PNG فارسی روی سرور به فونت TTF و کتابخانه‌ی
   shaping نیاز دارد که روی هاست نصب نیست.                          */
if ($action === 'send_telegram') {
    // تشخیص دقیق علت نرسیدن فایل. حالت رایج: حجم POST از post_max_size
    // سرور بیشتر بوده؛ در این حالت PHP کل بدنه را دور می‌ریزد و هم $_FILES
    // و هم $_POST خالی می‌مانند، در حالی که CONTENT_LENGTH بزرگ است.
    if (empty($_FILES['image'])) {
        $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        $max = trim((string)ini_get('post_max_size'));
        if ($len > 0 && empty($_POST)) {
            echo json_encode([
                'success' => false,
                'message' => 'حجم عکس از حد مجاز سرور بیشتر است (post_max_size = ' . $max . '). '
                           . 'حجم ارسالی: ' . round($len / 1048576, 2) . ' مگابایت.',
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['success' => false, 'message' => 'فایل عکس دریافت نشد'], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    $errCode = (int)($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errCode !== UPLOAD_ERR_OK) {
        $errMap = [
            UPLOAD_ERR_INI_SIZE   => 'حجم عکس از upload_max_filesize سرور بیشتر است (' . ini_get('upload_max_filesize') . ')',
            UPLOAD_ERR_FORM_SIZE  => 'حجم عکس بیش از حد مجاز فرم است',
            UPLOAD_ERR_PARTIAL    => 'آپلود ناقص انجام شد — دوباره تلاش کنید',
            UPLOAD_ERR_NO_FILE    => 'فایلی ارسال نشد',
            UPLOAD_ERR_NO_TMP_DIR => 'پوشه‌ی موقت سرور در دسترس نیست',
            UPLOAD_ERR_CANT_WRITE => 'نوشتن فایل موقت روی سرور ممکن نشد',
        ];
        echo json_encode([
            'success' => false,
            'message' => $errMap[$errCode] ?? ('خطای آپلود (کد ' . $errCode . ')'),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (empty($_FILES['image']['tmp_name']) || !is_uploaded_file($_FILES['image']['tmp_name'])) {
        echo json_encode(['success' => false, 'message' => 'فایل عکس معتبر نیست'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ((int)$_FILES['image']['size'] > 9 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'حجم عکس بیش از حد مجاز است'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // آیدی تلگرام همین ادمین
    $st = $conn->prepare("SELECT telegram_id, first_name FROM users WHERE id = ?");
    $st->bind_param("i", $userId); $st->execute();
    $me = $st->get_result()->fetch_assoc(); $st->close();
    if (empty($me['telegram_id'])) {
        echo json_encode(['success' => false, 'message' => 'آیدی تلگرام شما در پروفایل ثبت نشده'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $caption = trim((string)($_POST['caption'] ?? ''));
    if ($caption === '') $caption = '📊 قیمت لحظه‌ای ارزها — AVA PAY';

    $token = defined('BOT_TOKEN') ? BOT_TOKEN : '';
    if ($token === '') {
        echo json_encode(['success' => false, 'message' => 'توکن ربات تنظیم نشده'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $post = [
        'chat_id'    => $me['telegram_id'],
        'caption'    => $caption,
        'parse_mode' => 'HTML',
        'photo'      => new CURLFile(
                            $_FILES['image']['tmp_name'],
                            ($_FILES['image']['type'] === 'image/jpeg' ? 'image/jpeg' : 'image/png'),
                            ($_FILES['image']['type'] === 'image/jpeg' ? 'AvaPay-Rates.jpg' : 'AvaPay-Rates.png')
                        ),
    ];

    $ch = curl_init("https://api.telegram.org/bot{$token}/sendPhoto");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    $ok = false; $desc = '';
    if ($res) {
        $j = json_decode($res, true);
        $ok = !empty($j['ok']);
        $desc = $j['description'] ?? '';
    }
    echo json_encode([
        'success' => $ok,
        'message' => $ok ? 'عکس به ربات تلگرام شما ارسال شد ✅'
                         : ('ارسال ناموفق: ' . ($desc ?: $err ?: 'خطای نامشخص')),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------------- حذف ---------------- */
if ($action === 'delete') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'شناسه نامعتبر']); exit; }
    $st = $conn->prepare("DELETE FROM rate_posters WHERE id = ?");
    $st->bind_param("i", $id); $ok = $st->execute(); $st->close();
    echo json_encode(['success' => (bool)$ok], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'action نامعتبر']);
