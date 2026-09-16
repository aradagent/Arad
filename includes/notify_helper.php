<?php
/**
 * includes/notify_helper.php
 * ------------------------------------------------------------------
 * هاب مرکزی ارسال اعلان:
 *   1) Web Push  (با اصلاح مخصوص اندروید / FCM)
 *   2) Email     (نوتیفیکیشن از طریق ایمیل)
 *   3) Database  (ذخیره در user_notifications برای نمایش داخل اپ)
 *
 * تمام APIها به‌جای کپی‌کردن کد push، این فایل را include کرده و
 * از توابع زیر استفاده می‌کنند:
 *   - notifyUser($conn, $userId, $title, $body, $opts)   // هر سه کانال
 *   - sendPushToUser($conn, $userId, $title, $body, ...) // فقط push
 *   - sendEmailNotification($conn, $userId, $title, $body)// فقط ایمیل
 *   - sendDbNotification($conn, $userId, $type, $title, $body, $relatedId)
 * ------------------------------------------------------------------
 */

if (!defined('AVAPAY_VAPID_PUBLIC')) {
    define('AVAPAY_VAPID_PUBLIC',  'BFYQrNpREIzsQ5kfKXCXB0RPRmH9U3wrQkcbfZP5UC90bMgABYu9J-3XQNoOaatmEO-lSaOjOpmLKihMO381GkM');
    define('AVAPAY_VAPID_PRIVATE', 'awsT3nzgG-RHKjP8eLqT5j4fVIk95d1YPf4MUPciHT4');
    // subject باید یک mailto: یا آدرس معتبر باشد. FCM روی اندروید
    // اگر subject نامعتبر باشد گاهی پیام را drop می‌کند.
    define('AVAPAY_VAPID_SUBJECT', 'mailto:support@aradexchange.com');
}

if (!defined('AVAPAY_MAIL_FROM')) {
    define('AVAPAY_MAIL_FROM',      'notif@aradexchange.com');
    define('AVAPAY_MAIL_FROM_NAME', 'Ava Pay');
}

/* ---- تنظیمات SMTP (در صورت پیکربندی، ایمیل از طریق SMTP ارسال می‌شود) ---- */
/* برای فعال‌سازی، مقادیر زیر را با اطلاعات میل‌سرور هاست پر کنید.
   اگر AVAPAY_SMTP_HOST خالی بماند، از تابع mail() استفاده می‌شود. */
if (!defined('AVAPAY_SMTP_HOST')) {
    define('AVAPAY_SMTP_HOST', 'mail.aradexchange.com');   // میل‌سرور خروجی
    define('AVAPAY_SMTP_PORT', 587);                        // 587 = TLS
    define('AVAPAY_SMTP_USER', 'notif@aradexchange.com');   // نام کاربری ایمیل
    define('AVAPAY_SMTP_PASS', '%brhYxT1RBEwbpVm');         // رمز عبور ایمیل
    define('AVAPAY_SMTP_SECURE', 'tls');                    // 'tls' برای پورت 587
}

/* ------------------------------------------------------------------ */
/* بارگذاری کتابخانه WebPush                                          */
/* ------------------------------------------------------------------ */
if (!function_exists('_avapay_load_webpush')) {
function _avapay_load_webpush() {
    if (class_exists('\\Minishlink\\WebPush\\WebPush')) return true;
    $vendorPaths = [
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../vendor/autoload.php',
    ];
    foreach ($vendorPaths as $p) {
        if (file_exists($p)) { require_once $p; return class_exists('\\Minishlink\\WebPush\\WebPush'); }
    }
    error_log('AvaPay: WebPush library not found');
    return false;
}
}

/* ------------------------------------------------------------------ */
/* اطمینان از وجود ستون‌های لازم روی جدول push_subscriptions          */
/* (چند دستگاهی + اندروید)                                            */
/* ------------------------------------------------------------------ */
if (!function_exists('_avapay_ensure_push_table')) {
function _avapay_ensure_push_table($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS push_subscriptions (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        endpoint TEXT NOT NULL,
        p256dh TEXT NOT NULL,
        auth TEXT NOT NULL,
        user_agent TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    )");
}
}

/* ------------------------------------------------------------------ */
/* ارسال Web Push به همه‌ی دستگاه‌های یک کاربر                        */
/* ------------------------------------------------------------------ */
if (!function_exists('sendPushToUser')) {
function sendPushToUser($conn, $userId, $title, $body, $type = 'general', $url = '/ledor/dashboard.php', $relatedId = null) {
    if (!_avapay_load_webpush()) return 0;
    _avapay_ensure_push_table($conn);

    $stmt = $conn->prepare("SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) return 0;

    $auth = [
        'VAPID' => [
            'subject'    => AVAPAY_VAPID_SUBJECT,
            'publicKey'  => AVAPAY_VAPID_PUBLIC,
            'privateKey' => AVAPAY_VAPID_PRIVATE,
        ],
    ];

    try {
        $webPush = new \Minishlink\WebPush\WebPush($auth);
        // ---- تنظیمات مهم برای اندروید / FCM ----
        // TTL بالا تا وقتی گوشی offline بود پیام نگه‌داشته شود،
        // urgency=high تا FCM آن را فوری تحویل دهد،
        // topic تا پیام‌های تکراری collapse شوند (اختیاری).
        $webPush->setDefaultOptions([
            'TTL'      => 86400,
            'urgency'  => 'high',
            'topic'    => substr('avapay_' . $type, 0, 32),
        ]);
        // اجازه‌ی موازی‌سازی و افزایش timeout برای اتصال‌های کند اندروید
        if (method_exists($webPush, 'setReuseVAPIDHeaders')) {
            $webPush->setReuseVAPIDHeaders(true);
        }
    } catch (\Throwable $e) {
        error_log('AvaPay WebPush init error: ' . $e->getMessage());
        return 0;
    }

    // payload مسطح و کامل تا Service Worker همیشه بتواند نوتیف را بسازد.
    // badge_count: تعداد کل نوتیف‌های خوانده‌نشده‌ی همین کاربر تا همین لحظه —
    // Service Worker این عدد را مستقیماً روی آیکون اپ می‌گذارد (Badging API)،
    // حتی وقتی اپ کاملاً بسته است. همان کوئری‌ای که api/notification_api.php
    // برای badge زنگوله‌ی داخل اپ استفاده می‌کند، اینجا هم عیناً تکرار شده تا
    // دو منبع هیچ‌وقت با هم ناهماهنگ نشوند.
    $badgeCount = 0;
    $bStmt = $conn->prepare("SELECT COUNT(*) AS c FROM user_notifications WHERE user_id = ? AND is_read = 0");
    if ($bStmt) {
        $bStmt->bind_param("i", $userId);
        $bStmt->execute();
        $badgeCount = (int)($bStmt->get_result()->fetch_assoc()['c'] ?? 0);
    }

    $payload = json_encode([
        'title'       => (string)$title,
        'body'        => (string)$body,
        'type'        => $type,
        'url'         => $url,
        'related_id'  => $relatedId,
        'icon'        => '/ledor/AVAPAY.PNG',
        'badge'       => '/ledor/AVAPAY.PNG',
        'badge_count' => $badgeCount,
        'timestamp'   => time(),
        'vibrate'     => [200, 100, 200],
    ], JSON_UNESCAPED_UNICODE);

    $queued = [];
    while ($sub = $result->fetch_assoc()) {
        try {
            // نرمال‌سازی کلیدها به base64url؛ ردیف‌های قدیمی ممکن است با
            // base64 معمولی (+ / =) ذخیره شده باشند که رمزنگاری را خراب می‌کند.
            $p256 = rtrim(strtr((string)$sub['p256dh'], '+/', '-_'), '=');
            $ath  = rtrim(strtr((string)$sub['auth'],   '+/', '-_'), '=');
            // اگر کلید یا auth ناقص بود این ردیف را رد کن (خطای اندروید)
            if ($p256 === '' || $ath === '') {
                error_log('AvaPay push: skipping sub with empty keys, id=' . $sub['id']);
                continue;
            }
            // نکته مهم برای اندروید:
            // contentEncoding را دستی روی aes128gcm قفل نمی‌کنیم. کتابخانه‌ی
            // WebPush بر اساس endpoint (FCM/Mozilla/…) خودش رمزنگاری درست را
            // انتخاب می‌کند. قفل‌کردن دستی باعث می‌شد روی برخی گوشی‌های اندروید
            // پیام drop شود و در حالت بسته بودن اپ هیچ نوتیفیکیشنی نرسد.
            $subscription = \Minishlink\WebPush\Subscription::create([
                'endpoint'  => $sub['endpoint'],
                'publicKey' => $p256,
                'authToken' => $ath,
                'contentEncoding' => 'aes128gcm',
            ]);
            $webPush->queueNotification($subscription, $payload);
            $queued[$sub['endpoint']] = $sub['id'];
        } catch (\Throwable $e) {
            error_log('AvaPay push queue error: ' . $e->getMessage());
        }
    }

    $sent = 0;
    try {
        foreach ($webPush->flush() as $report) {
            $endpoint = method_exists($report, 'getEndpoint')
                ? $report->getEndpoint()
                : (string)$report->getRequest()->getUri();

            if ($report->isSuccess()) {
                $sent++;
            } else {
                // فقط subscription های واقعاً منقضی/نامعتبر را حذف کن
                // (404/410). خطاهای موقتی اندروید را حذف نکن.
                $expired = $report->isSubscriptionExpired();
                if ($expired) {
                    $del = $conn->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?");
                    $del->bind_param("s", $endpoint);
                    $del->execute();
                }
                error_log('AvaPay push not delivered: ' . $report->getReason());
            }
        }
    } catch (\Throwable $e) {
        error_log('AvaPay push flush error: ' . $e->getMessage());
    }

    return $sent;
}
}

/* ------------------------------------------------------------------ */
/* ذخیره‌ی اعلان در دیتابیس (نمایش داخل اپ)                            */
/* ------------------------------------------------------------------ */
if (!function_exists('sendDbNotification')) {
function sendDbNotification($conn, $userId, $type, $title, $message, $relatedId = null) {
    $conn->query("CREATE TABLE IF NOT EXISTS `user_notifications` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `user_id` INT NOT NULL,
        `type` VARCHAR(50) NOT NULL,
        `title` VARCHAR(255) NOT NULL,
        `message` TEXT NOT NULL,
        `related_id` INT DEFAULT NULL,
        `is_read` TINYINT DEFAULT 0,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_id (user_id),
        INDEX idx_is_read (is_read)
    )");

    $stmt = $conn->prepare("INSERT INTO user_notifications (user_id, type, title, message, related_id) VALUES (?,?,?,?,?)");
    $stmt->bind_param("isssi", $userId, $type, $title, $message, $relatedId);
    return $stmt->execute();
}
}

/* ------------------------------------------------------------------ */
/* ارسال ایمیل اعلان                                                  */
/* ------------------------------------------------------------------ */
if (!function_exists('sendEmailNotification')) {

/* ارسال ایمیل خام از طریق SMTP (بدون کتابخانه‌ی خارجی) */
function ava_smtp_send($to, $subject, $htmlBody) {
    $host = AVAPAY_SMTP_HOST;
    if ($host === '') return false;   // SMTP پیکربندی نشده
    $port   = (int)AVAPAY_SMTP_PORT;
    $user   = AVAPAY_SMTP_USER;
    $pass   = AVAPAY_SMTP_PASS;
    $secure = AVAPAY_SMTP_SECURE;
    $from   = AVAPAY_MAIL_FROM;
    $fromNm = AVAPAY_MAIL_FROM_NAME;

    $remote = ($secure === 'ssl') ? "ssl://$host:$port" : "$host:$port";
    $errno = 0; $errstr = '';
    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
    $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) { $GLOBALS['AVA_SMTP_ERR'] = "connect failed: $errstr ($errno)"; error_log("AvaPay SMTP connect failed: $errstr ($errno)"); return false; }
    stream_set_timeout($fp, 15);

    $read = function() use ($fp) {
        $data = '';
        while ($line = fgets($fp, 515)) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $data;
    };
    $cmd = function($c) use ($fp, $read) { fwrite($fp, $c . "\r\n"); return $read(); };

    $read(); // بنر
    $cmd("EHLO aradexchange.com");
    if ($secure === 'tls') {
        $r = $cmd("STARTTLS");
        if (strpos($r, '220') !== 0) { $GLOBALS['AVA_SMTP_ERR'] = 'STARTTLS rejected: ' . trim($r); fclose($fp); return false; }
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { $GLOBALS['AVA_SMTP_ERR'] = 'TLS handshake failed'; fclose($fp); return false; }
        $cmd("EHLO aradexchange.com");
    }
    // احراز هویت
    $cmd("AUTH LOGIN");
    $cmd(base64_encode($user));
    $rAuth = $cmd(base64_encode($pass));
    if (strpos($rAuth, '235') !== 0) { $GLOBALS['AVA_SMTP_ERR'] = 'auth failed: ' . trim($rAuth); error_log('AvaPay SMTP auth failed'); fclose($fp); return false; }

    $rFrom = $cmd("MAIL FROM:<$from>");
    if (strpos($rFrom, '250') !== 0) { $GLOBALS['AVA_SMTP_ERR'] = 'MAIL FROM rejected: ' . trim($rFrom); fclose($fp); return false; }
    $rRcpt = $cmd("RCPT TO:<$to>");
    if (strpos($rRcpt, '250') !== 0 && strpos($rRcpt, '251') !== 0) { $GLOBALS['AVA_SMTP_ERR'] = 'RCPT TO rejected: ' . trim($rRcpt); fclose($fp); return false; }
    $rData = $cmd("DATA");
    if (strpos($rData, '354') !== 0) { $GLOBALS['AVA_SMTP_ERR'] = 'DATA rejected: ' . trim($rData); fclose($fp); return false; }

    $subjEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers = "From: $fromNm <$from>\r\n"
             . "To: <$to>\r\n"
             . "Subject: $subjEnc\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/html; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: base64\r\n";
    $bodyEnc = chunk_split(base64_encode($htmlBody));
    fwrite($fp, $headers . "\r\n" . $bodyEnc . "\r\n.\r\n");
    $rEnd = $read();
    $cmd("QUIT");
    fclose($fp);
    $okSend = (strpos($rEnd, '250') === 0);
    if (!$okSend) $GLOBALS['AVA_SMTP_ERR'] = 'send rejected: ' . trim($rEnd);
    return $okSend;
}

function sendEmailNotification($conn, $userId, $title, $body, $force = false, $emailDetails = null) {
    // احترام به تنظیمات کاربر (اگر ستون notify_email وجود دارد)
    // اگر $force=true باشد (مثلاً کاربر برای همین هشدار «ایمیل» را انتخاب کرده) تنظیم سراسری نادیده گرفته می‌شود
    $email = null; $name = ''; $allow = 1;
    $col = $conn->query("SHOW COLUMNS FROM users LIKE 'notify_email'");
    $hasPref = ($col && $col->num_rows > 0);

    $sql = $hasPref
        ? "SELECT email, first_name, last_name, notify_email FROM users WHERE id = ?"
        : "SELECT email, first_name, last_name FROM users WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) { $GLOBALS['AVA_SMTP_ERR'] = 'user not found'; return false; }

    $email = trim($row['email'] ?? '');
    $name  = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    if ($hasPref) $allow = (int)($row['notify_email'] ?? 1);

    if (!$force && $allow !== 1) { $GLOBALS['AVA_SMTP_ERR'] = 'user disabled email notifications'; return false; }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $GLOBALS['AVA_SMTP_ERR'] = 'user has no valid email address'; return false; }

    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeBody  = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
    $safeName  = htmlspecialchars($name ?: 'کاربر گرامی', ENT_QUOTES, 'UTF-8');

    // آواتار/لوگوی ایمیل: از همان لوگوی فعال برنامه (getAppLogo) استفاده می‌شود تا اگر
    // ادمین لوگو را از پنل عوض کند، ایمیل‌ها هم خودکار به‌روز شوند؛ در غیر این صورت
    // آیکن پیش‌فرض AvaPay. باید URL کامل (نه نسبی) باشد چون کلاینت‌های ایمیل صفحه را
    // نسبت به دامنه‌ی خودشان resolve نمی‌کنند.
    $logoHelperPath = __DIR__ . '/logo_helper.php';
    if (file_exists($logoHelperPath)) require_once $logoHelperPath;
    // لوگوی پیش‌فرض: نسخه‌ی دایره‌ای برند (اگر ادمین از پنل لوگوی سفارشی تعریف کرده باشد، همان جایگزین می‌شود)
    $logoUrl = 'https://aradexchange.com/ledor/assets/images/brand/avapay-logo-circle.png';
    if (function_exists('getAppLogo')) {
        $logo = getAppLogo();
        if ($logo && !empty($logo['url'])) {
            $logoUrl = 'https://aradexchange.com' . $logo['url'];
        }
    }

    // ===================================================================
    // قالب تیره‌ی برندشده (مطابق طرح جدید تیم — بک‌گراند مشکی/بنفش تیره،
    // کارت هیرو گرادیانی با نشان/آیکون، باکس جزئیات ستونی، باکس پشتیبانی،
    // نوار ویژگی‌ها، فوتر تیره با آیکون‌های شبکه‌های اجتماعی).
    // از جدول (table) استفاده شده چون اکثر کلاینت‌های ایمیل (به‌خصوص
    // Outlook) از flexbox/grid پشتیبانی نمی‌کنند.
    // ===================================================================
    $logoUrlAttr = htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8');
    $siteUrl     = 'https://aradexchange.com';
    $telegramUrl = 'https://t.me/aradtransfer';
    $instaUrl    = 'https://instagram.com/avapay.co';

    // تاریخ شمسی امروز برای زیرِ نام کاربر در هدر — از همان تابع jalali
    // مشترکِ پروژه (rate_poster_helper.php) استفاده می‌شود تا یک تبدیل‌گر
    // جدید دوباره ساخته نشود.
    $rpHelperPath = __DIR__ . '/rate_poster_helper.php';
    if (file_exists($rpHelperPath)) require_once $rpHelperPath;
    $todayJalali = function_exists('rp_jalali_label') ? rp_jalali_label(time()) : date('Y/m/d');
    $safeDate    = htmlspecialchars($todayJalali, ENT_QUOTES, 'UTF-8');
    $jalaliYear  = function_exists('rp_jalali') ? htmlspecialchars((function_exists('rp_fa_num') ? rp_fa_num(rp_jalali(time())[0]) : rp_jalali(time())[0]), ENT_QUOTES, 'UTF-8') : date('Y');

    // برچسب کوچک صورتی بالای عنوان (اختیاری، مثلاً «تأیید شد») و آیکون دایره‌ای
    // هیرو (اموجی — چون کلاینت‌های ایمیل فونت آیکون را رندر نمی‌کنند)؛ اگر
    // caller ندهد، خط برچسب اصلاً نمایش داده نمی‌شود و آیکون پیش‌فرض 🔔 است.
    $badgeText = is_array($emailDetails) ? trim((string)($emailDetails['badge'] ?? '')) : '';
    $heroIcon  = is_array($emailDetails) ? trim((string)($emailDetails['icon']  ?? '')) : '';
    if ($heroIcon === '') $heroIcon = '🔔';
    $safeBadge = htmlspecialchars($badgeText, ENT_QUOTES, 'UTF-8');
    $safeIconHero = htmlspecialchars($heroIcon, ENT_QUOTES, 'UTF-8');
    // بج پیل کوچک بالای عنوان (طرح تأییدشده) — پس‌زمینه‌ی کرم‌رنگ ملایم +
    // متن قهوه‌ای‌مایل‌به‌نارنجی تیره، چون کل هیرو حالا سفید است نه گرادیانی
    $badgeRowHtml = $safeBadge !== ''
        ? '<div style="margin-bottom:14px;"><span style="display:inline-block;background:#FAECE7;color:#993C1D;'
        . 'font-size:12px;font-weight:800;padding:4px 14px;border-radius:20px;">' . $safeBadge . '</span></div>'
        : '';

    // ===================================================================
    // باکس جزئیات ساختاریافته (اختیاری، جدا از کارت هیرو):
    //   - تا ۳ ردیف → چیدمان ستونی با آیکون دایره‌ای (مثل «تاریخ/مبلغ/وضعیت»)
    //   - بیش از ۳ ردیف (مثلاً چند شماره‌کارت) → همان لیست عمودیِ قبلی با
    //     جداکننده‌ی نقطه‌چین، بدون تغییر در رفتار caller های موجود.
    // اگر $emailDetails داده نشده باشد، فقط متن ساده (subtitle) نمایش داده می‌شود.
    // subtitle وسط‌چین و با max-width است چون کل بخش هیرو الان وسط‌چین است.
    // ===================================================================
    $subtitleHtml = '<div style="color:#6B6390;font-size:14px;line-height:1.9;max-width:380px;margin:0 auto;">' . $safeBody . '</div>';
    $detailsCardHtml = '';
    $noteBoxHtml = '';

    if (is_array($emailDetails) && !empty($emailDetails['rows'])) {
        $intro = trim((string)($emailDetails['intro'] ?? ''));
        $note  = trim((string)($emailDetails['note']  ?? ''));
        $rows  = array_values(array_filter($emailDetails['rows'], function($r){ return trim((string)($r['value'] ?? '')) !== ''; }));
        $count = count($rows);

        if ($intro !== '') {
            $subtitleHtml = '<div style="color:#6B6390;font-size:14px;line-height:1.9;max-width:380px;margin:0 auto;">' . nl2br(htmlspecialchars($intro, ENT_QUOTES, 'UTF-8')) . '</div>';
        }

        if ($count > 0 && $count <= 3) {
            // ---- چیدمان ستونیِ کوتاه (مثل «تاریخ / مبلغ / وضعیت») ----
            $colW = (int)floor(100 / $count);
            $colsHtml = '';
            foreach ($rows as $i => $r) {
                $icon  = (string)($r['icon']  ?? '•');
                $label = trim((string)($r['label'] ?? ''));
                $value = trim((string)($r['value'] ?? ''));
                $valueColor = (string)($r['color'] ?? '#180F35');

                $safeIcon  = htmlspecialchars($icon, ENT_QUOTES, 'UTF-8');
                $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
                $safeValue = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
                $safeColor = htmlspecialchars($valueColor, ENT_QUOTES, 'UTF-8');
                $dividerStyle = ($i < $count - 1) ? 'border-left:1px solid rgba(16,8,40,.08);' : '';

                $colsHtml .= <<<COL
                <td width="{$colW}%" align="center" valign="top" style="padding:6px 4px;{$dividerStyle}">
                  <div style="width:40px;height:40px;line-height:40px;border-radius:50%;background:#EEEDFE;
                              font-size:16px;text-align:center;margin:0 auto 10px;">{$safeIcon}</div>
                  <div class="ava-tint-m2" style="color:#8A82AC;font-size:11px;margin-bottom:5px;">{$safeLabel}</div>
                  <div style="color:{$safeColor};font-size:13px;font-weight:800;">{$safeValue}</div>
                </td>
COL;
            }
            $detailsCardHtml = <<<CARD
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                   style="background:#F9F8FD;border:1px solid #EFEDF7;border-radius:18px;margin-bottom:14px;">
              <tr><td style="padding:18px 14px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>{$colsHtml}</tr></table>
              </td></tr>
            </table>
CARD;
        } elseif ($count > 3) {
            // ---- لیست عمودیِ قدیمی (تعداد ردیف متغیر، مثل چند شماره‌کارت) ----
            $rowsHtml = '';
            foreach ($rows as $i => $r) {
                $icon  = (string)($r['icon']  ?? '•');
                $label = trim((string)($r['label'] ?? ''));
                $value = trim((string)($r['value'] ?? ''));

                $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
                $safeValue = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
                $safeIcon  = htmlspecialchars($icon, ENT_QUOTES, 'UTF-8');

                $borderStyle = ($i > 0) ? 'border-top:1px dashed rgba(16,8,40,.1);' : '';
                $copyIcon = !empty($r['copy']) ? ' <span style="font-size:11px;opacity:.55;">🗐</span>' : '';
                $labelHtml = $safeLabel !== ''
                    ? '<span class="ava-tint-m2" style="color:#8A82AC;font-size:12px;font-weight:600;">' . $safeLabel . '</span> '
                    : '';

                $rowsHtml .= <<<ROW
                <tr><td style="padding:13px 4px;{$borderStyle}">
                  <table role="presentation" width="100%" dir="rtl" cellpadding="0" cellspacing="0"><tr>
                    <td align="right" width="34" valign="middle" style="font-size:15px;">{$safeIcon}</td>
                    <td align="right" valign="middle" style="padding-right:6px;">{$labelHtml}</td>
                    <td align="left" valign="middle" dir="ltr" style="color:#180F35;font-size:14px;font-weight:800;white-space:nowrap;">{$safeValue}{$copyIcon}</td>
                  </tr></table>
                </td></tr>
ROW;
            }
            $detailsCardHtml = <<<CARD
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                   style="background:#F9F8FD;border:1px solid #EFEDF7;border-radius:18px;margin-bottom:14px;">
              {$rowsHtml}
            </table>
CARD;
        }

        if ($note !== '') {
            $safeNote = nl2br(htmlspecialchars($note, ENT_QUOTES, 'UTF-8'));
            $noteBoxHtml = <<<NOTE
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                   style="background:#FEF7E0;border:1px solid #F5E6B8;border-radius:14px;margin-bottom:14px;">
              <tr><td style="padding:14px 16px;">
                <table role="presentation" width="100%" dir="rtl" cellpadding="0" cellspacing="0"><tr>
                  <td valign="top" align="right" style="color:#7A5B00;font-size:12.5px;line-height:2.1;">{$safeNote}</td>
                  <td width="26" valign="top" align="left" style="font-size:15px;">ℹ️</td>
                </tr></table>
              </td></tr>
            </table>
NOTE;
        }
    }

    $html = <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>{$safeTitle}</title>
<style>
  /* این ایمیل همیشه پس‌زمینه‌ی روشن/سفید دارد (فقط یک تم) — با اعلام صریح
     color-scheme:light از اینورت خودکار رنگ توسط dark-mode خودِ کلاینت ایمیل
     (که می‌تواند متن را روی پس‌زمینه‌ی نامناسب ناخوانا کند) جلوگیری می‌کنیم. */
  :root { color-scheme: light; supported-color-schemes: light; }
  /* Outlook.com و برخی کلاینت‌های دیگر به‌جای prefers-color-scheme از این
     دو attribute استفاده می‌کنند؛ رنگ‌های واقعی را همان‌جا هم اجباری می‌کنیم. */
  [data-ogsc] body, [data-ogsc] table { background-color:#FFFFFF !important; }
  [data-ogsc] .ava-tint-w  { color:#180F35 !important; }
  [data-ogsc] .ava-tint-m  { color:#4B4470 !important; }
  [data-ogsc] .ava-tint-m2 { color:#6B6390 !important; }
</style>
</head>
<body bgcolor="#EDEBF3" style="margin:0;padding:0;background:#EDEBF3;font-family:Tahoma,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="#EDEBF3" style="background:#EDEBF3;">
<tr><td align="center" style="padding:32px 12px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">

  <!-- نوار باریک بالا -->
  <tr><td style="padding:0 6px 14px;">
    <div class="ava-tint-m2" style="color:#9B93B8;font-size:11px;text-align:right;">امن، سریع و مطمئن</div>
  </td></tr>

  <tr><td style="background:#FFFFFF;border-radius:26px;overflow:hidden;box-shadow:0 10px 34px rgba(76,29,149,.12);">

  <!-- هدر: احوال‌پرسی+تاریخ در راست، لوگو+نام در چپ (یک جدول dir=rtl واحد، بدون سوییچ جهت) -->
  <tr><td style="padding:22px 24px 4px;">
    <table role="presentation" width="100%" dir="rtl" cellpadding="0" cellspacing="0"><tr>
      <td valign="middle" align="right">
        <div class="ava-tint-w" style="color:#180F35;font-size:12.5px;line-height:1.8;">سلام {$safeName} 👋</div>
        <div class="ava-tint-m2" style="color:#8A82AC;font-size:10.5px;line-height:1.8;margin-top:2px;">{$safeDate}</div>
      </td>
      <td valign="middle" align="left" width="150">
        <table role="presentation" dir="rtl" cellpadding="0" cellspacing="0" align="left"><tr>
          <td valign="middle" style="padding-left:8px;">
            <span class="ava-tint-w" style="color:#180F35;font-size:16px;font-weight:800;">Ava Pay</span>
          </td>
          <td valign="middle">
            <img src="{$logoUrlAttr}" alt="Ava Pay" width="40" height="40"
                 style="width:40px;height:40px;border-radius:12px;display:block;object-fit:cover;background:#F4F2FB;">
          </td>
        </tr></table>
      </td>
    </tr></table>
  </td></tr>

  <!-- بخش هیرو: آیکون دایره‌ای گرادیانی، بج، عنوان و توضیح، همه وسط‌چین روی
       پس‌زمینه‌ی سفید (طرح جدید تأییدشده — بدون کارت رنگی تمام‌عرض) -->
  <tr><td align="center" style="padding:8px 30px 32px;">
    <div style="width:72px;height:72px;line-height:72px;border-radius:50%;margin:10px auto 20px;
                background:linear-gradient(135deg,#F472B6,#A855F7);text-align:center;font-size:30px;">{$safeIconHero}</div>
    {$badgeRowHtml}
    <div class="ava-tint-w" style="color:#180F35;font-size:21px;font-weight:800;line-height:1.6;margin:0 0 10px;">{$safeTitle}</div>
    {$subtitleHtml}
    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px auto 0;"><tr><td>
      <a href="{$siteUrl}/ledor/dashboard.php"
         style="display:inline-block;background:linear-gradient(135deg,#EC4899,#A855F7);color:#FFFFFF;
                text-decoration:none;padding:13px 38px;border-radius:14px;font-size:14px;font-weight:700;
                font-family:Tahoma,Arial,sans-serif;white-space:nowrap;">ورود به حساب</a>
    </td></tr></table>
  </td></tr>

  <!-- باکس جزئیات (اختیاری) -->
  <tr><td style="padding:16px 20px 0;">
    {$detailsCardHtml}
    {$noteBoxHtml}
  </td></tr>

  <!-- باکس پشتیبانی -->
  <tr><td style="padding:2px 20px 18px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
           style="background:#F9F8FD;border:1px solid #EFEDF7;border-radius:18px;">
      <tr><td style="padding:16px 18px;">
        <table role="presentation" width="100%" dir="rtl" cellpadding="0" cellspacing="0"><tr>
          <td width="44" valign="middle">
            <div style="width:38px;height:38px;line-height:38px;border-radius:50%;background:#EEEDFE;text-align:center;font-size:16px;">🎧</div>
          </td>
          <td valign="middle" style="padding-right:10px;">
            <div class="ava-tint-w" style="color:#180F35;font-size:13px;font-weight:800;line-height:1.8;">نیاز به کمک دارید؟</div>
            <div class="ava-tint-m2" style="color:#6B6390;font-size:11px;line-height:1.8;margin-top:3px;">تیم پشتیبانی ما ۲۴ ساعته در کنار شماست.</div>
          </td>
          <td valign="middle" align="left" width="130">
            <a href="{$siteUrl}/ledor/dashboard.php?open=support"
               style="display:inline-block;border:1px solid #8B5CF6;color:#7C3AED;text-decoration:none;
                      padding:9px 16px;border-radius:11px;font-size:11.5px;font-weight:700;white-space:nowrap;
                      font-family:Tahoma,Arial,sans-serif;">تماس با پشتیبانی</a>
          </td>
        </tr></table>
      </td></tr>
    </table>
  </td></tr>

  <!-- نوار ویژگی‌ها -->
  <tr><td style="padding:0 20px 22px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
      <td width="25%" align="center">
        <div style="width:36px;height:36px;line-height:36px;border-radius:50%;background:#EEEDFE;text-align:center;font-size:14px;margin:0 auto 8px;">⚡</div>
        <div class="ava-tint-w" style="color:#180F35;font-size:11px;font-weight:800;">سریع</div>
        <div class="ava-tint-m2" style="color:#8A82AC;font-size:9.5px;margin-top:2px;">تراکنش در چند ثانیه</div>
      </td>
      <td width="25%" align="center">
        <div style="width:36px;height:36px;line-height:36px;border-radius:50%;background:#EEEDFE;text-align:center;font-size:14px;margin:0 auto 8px;">🛡️</div>
        <div class="ava-tint-w" style="color:#180F35;font-size:11px;font-weight:800;">امن</div>
        <div class="ava-tint-m2" style="color:#8A82AC;font-size:9.5px;margin-top:2px;">با بالاترین استانداردها</div>
      </td>
      <td width="25%" align="center">
        <div style="width:36px;height:36px;line-height:36px;border-radius:50%;background:#EEEDFE;text-align:center;font-size:14px;margin:0 auto 8px;">✅</div>
        <div class="ava-tint-w" style="color:#180F35;font-size:11px;font-weight:800;">مطمئن</div>
        <div class="ava-tint-m2" style="color:#8A82AC;font-size:9.5px;margin-top:2px;">پرداخت بدون ریسک</div>
      </td>
      <td width="25%" align="center">
        <div style="width:36px;height:36px;line-height:36px;border-radius:50%;background:#EEEDFE;text-align:center;font-size:13px;margin:0 auto 8px;">24</div>
        <div class="ava-tint-w" style="color:#180F35;font-size:11px;font-weight:800;">همیشه در دسترس</div>
        <div class="ava-tint-m2" style="color:#8A82AC;font-size:9.5px;margin-top:2px;">۲۴ ساعته، ۷ روز هفته</div>
      </td>
    </tr></table>
  </td></tr>

  <!-- فوتر (dir=rtl واحد: برند راست، آیکون‌های اجتماعی چپ) -->
  <tr><td style="background:#FAF9FD;border-top:1px solid #EFEDF7;padding:18px 24px;">
    <table role="presentation" width="100%" dir="rtl" cellpadding="0" cellspacing="0"><tr>
      <td valign="middle" align="right">
        <div class="ava-tint-w" style="color:#180F35;font-size:13px;font-weight:800;line-height:1.8;">Ava Pay</div>
        <div class="ava-tint-m2" style="color:#8A82AC;font-size:10.5px;line-height:1.8;margin-top:2px;">امن، سریع و مطمئن</div>
      </td>
      <td valign="middle" align="left" width="120">
        <table role="presentation" dir="rtl" cellpadding="0" cellspacing="0" align="left"><tr>
          <td valign="middle" style="padding-left:8px;">
            <a href="{$siteUrl}" style="text-decoration:none;display:inline-block;">
              <span style="display:inline-block;width:32px;height:32px;border-radius:50%;background:#EEEDFE;text-align:center;line-height:32px;font-size:14px;">🌐</span>
            </a>
          </td>
          <td valign="middle" style="padding-left:8px;">
            <a href="{$telegramUrl}" style="text-decoration:none;display:inline-block;">
              <span style="display:inline-block;width:32px;height:32px;border-radius:50%;background:#EEEDFE;text-align:center;line-height:32px;font-size:14px;">✈️</span>
            </a>
          </td>
          <td valign="middle">
            <a href="{$instaUrl}" style="text-decoration:none;display:inline-block;">
              <span style="display:inline-block;width:32px;height:32px;border-radius:50%;background:#EEEDFE;text-align:center;line-height:32px;font-size:14px;">📷</span>
            </a>
          </td>
        </tr></table>
      </td>
    </tr></table>
  </td></tr>

  </td></tr>

  <!-- کپی‌رایت/توضیح پایین صفحه -->
  <tr><td align="center" style="padding:16px 12px 4px;">
    <div class="ava-tint-m2" style="color:#A79FC4;font-size:10.5px;">© Ava Pay {$jalaliYear} تمامی حقوق محفوظ است.</div>
    <div class="ava-tint-m2" style="color:#B8B1D1;font-size:10px;margin-top:4px;">این ایمیل به‌صورت خودکار ارسال شده است. لطفاً به این پیام پاسخ ندهید.</div>
  </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
HTML;


    $headers  = 'MIME-Version: 1.0' . "\r\n";
    $headers .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
    $headers .= 'From: ' . AVAPAY_MAIL_FROM_NAME . ' <' . AVAPAY_MAIL_FROM . ">\r\n";
    $headers .= 'Reply-To: ' . AVAPAY_MAIL_FROM . "\r\n";

    // encode subject به‌صورت UTF-8 تا فارسی خراب نشود
    $subject = '=?UTF-8?B?' . base64_encode($title) . '?=';

    // ابتدا SMTP (اگر پیکربندی شده)؛ در غیر این صورت تابع mail()
    if (defined('AVAPAY_SMTP_HOST') && AVAPAY_SMTP_HOST !== '') {
        $ok = ava_smtp_send($email, $title, $html);
        if ($ok) return true;
        error_log('AvaPay: SMTP send failed for user ' . $userId . ', trying mail()');
    }

    $ok = @mail($email, $subject, $html, $headers);
    if (!$ok) error_log('AvaPay: mail() failed for user ' . $userId);
    return $ok;
}
}

/* ------------------------------------------------------------------ */
/* ارسال اعلان تلگرام                                                 */
/* ------------------------------------------------------------------ */
if (!function_exists('sendTelegramNotification')) {
function sendTelegramNotification($conn, $userId, $title, $body, $force = false) {
    // بررسی وجود تابع ارسال تلگرام (در config/database.php تعریف شده)
    if (!function_exists('sendTelegramMessage')) { $GLOBALS['AVA_TG_ERR'] = 'sendTelegramMessage() not available'; return false; }

    // احترام به تنظیم کاربر
    $hasPref = false;
    $col = $conn->query("SHOW COLUMNS FROM users LIKE 'notify_telegram'");
    $hasPref = ($col && $col->num_rows > 0);

    $sql = $hasPref
        ? "SELECT telegram_id, notify_telegram FROM users WHERE id = ?"
        : "SELECT telegram_id FROM users WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) { $GLOBALS['AVA_TG_ERR'] = 'user not found'; return false; }

    $chatId = trim($row['telegram_id'] ?? '');
    if ($chatId === '') { $GLOBALS['AVA_TG_ERR'] = 'user has no telegram_id'; return false; }
    // اگر $force=true باشد (کاربر برای همین هشدار «تلگرام» را انتخاب کرده)،
    // تنظیم سراسری کاربر نادیده گرفته می‌شود — دقیقاً مثل رفتار ایمیل.
    if (!$force && $hasPref && (int)($row['notify_telegram'] ?? 1) !== 1) {
        $GLOBALS['AVA_TG_ERR'] = 'user disabled telegram notifications';
        return false;
    }

    $msg = "🔔 <b>" . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . "</b>\n\n"
         . htmlspecialchars($body, ENT_QUOTES, 'UTF-8');

    $sent = sendTelegramMessage($chatId, $msg);
    if (!$sent) $GLOBALS['AVA_TG_ERR'] = 'telegram API call failed';
    return $sent;
}
}

/* ------------------------------------------------------------------ */
/* تابع یکپارچه: هر چهار کانال با یک فراخوانی                          */
/*                                                                    */
/* $opts = [                                                          */
/*   'type'       => 'admin_toast',                                   */
/*   'url'        => '/ledor/dashboard.php',                          */
/*   'related_id' => 12,                                              */
/*   'push'       => true,                                            */
/*   'email'      => true,                                            */
/*   'telegram'   => true,                                            */
/*   'db'         => true,                                            */
/* ]                                                                  */
/* ------------------------------------------------------------------ */
if (!function_exists('notifyUser')) {
/* ================================================================== */
/*  صف اعلان‌ها (Notification Queue)                                   */
/*  ------------------------------------------------------------------ */
/*  همه‌ی اعلان‌ها (تلگرام / ایمیل / push) به‌جای ارسال درجا، در یک صف    */
/*  ثبت می‌شوند و یک کران‌جاب آن‌ها را ارسال می‌کند.                      */
/*                                                                     */
/*  چرا؟                                                               */
/*   ۱) ارسال SMTP و درخواست تلگرام کند است و اگر داخل بارگذاری صفحه    */
/*      انجام شود، ورکر PHP را قفل می‌کند (همان علت کند/خواب شدن سرور). */
/*   ۲) در صورت خطا، دوباره تلاش می‌شود (retry) و اعلان گم نمی‌شود.      */
/*   ۳) همه‌ی کدهای ارسال در همین یک فایل متمرکز است.                   */
/* ================================================================== */
if (!function_exists('_avapay_ensure_queue_table')) {
function _avapay_ensure_queue_table($conn) {
    static $done = false;
    if ($done) return;
    $done = true;
    @$conn->query("CREATE TABLE IF NOT EXISTS `notification_queue` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `user_id` INT NOT NULL,
        `title` VARCHAR(255) NOT NULL,
        `body` TEXT NOT NULL,
        `type` VARCHAR(50) DEFAULT 'general',
        `url` VARCHAR(255) DEFAULT '/ledor/dashboard.php',
        `related_id` INT DEFAULT NULL,
        `ch_push` TINYINT(1) DEFAULT 1,
        `ch_email` TINYINT(1) DEFAULT 1,
        `ch_telegram` TINYINT(1) DEFAULT 1,
        `force_email` TINYINT(1) DEFAULT 0,
        `force_telegram` TINYINT(1) DEFAULT 0,
        `status` ENUM('pending','sent','failed') DEFAULT 'pending',
        `attempts` INT DEFAULT 0,
        `last_error` VARCHAR(255) DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `sent_at` DATETIME DEFAULT NULL,
        INDEX idx_status (status),
        INDEX idx_user (user_id)
    ) DEFAULT CHARSET=utf8mb4");
    // ارتقای جدول‌های قدیمی (اگر ستون تازه وجود نداشت)
    $cols = [];
    $cq = @$conn->query("SHOW COLUMNS FROM `notification_queue`");
    if ($cq) while ($c = $cq->fetch_assoc()) $cols[strtolower($c['Field'])] = true;
    if (!isset($cols['force_telegram']))
        @$conn->query("ALTER TABLE `notification_queue` ADD COLUMN `force_telegram` TINYINT(1) DEFAULT 0");
    if (!isset($cols['email_details_json']))
        @$conn->query("ALTER TABLE `notification_queue` ADD COLUMN `email_details_json` MEDIUMTEXT DEFAULT NULL");
}
}

/**
 * افزودن یک اعلان به صف (ارسال واقعی توسط کران انجام می‌شود).
 */
if (!function_exists('queueNotification')) {
function queueNotification($conn, $userId, $title, $body, $opts = []) {
    _avapay_ensure_queue_table($conn);
    $type       = $opts['type']       ?? 'general';
    $url        = $opts['url']        ?? '/ledor/dashboard.php';
    $relatedId  = $opts['related_id'] ?? null;
    $chPush     = array_key_exists('push',     $opts) ? (int)(bool)$opts['push']     : 1;
    $chEmail    = array_key_exists('email',    $opts) ? (int)(bool)$opts['email']    : 1;
    $chTg       = array_key_exists('telegram', $opts) ? (int)(bool)$opts['telegram'] : 1;
    $forceEmail = (array_key_exists('email', $opts) && (bool)$opts['email']) ? 1 : 0;
    $forceTg    = (array_key_exists('telegram', $opts) && (bool)$opts['telegram']) ? 1 : 0;
    // باکس ساختاریافته‌ی ایمیل (اختیاری) — برای زمانی که کران بعداً ارسال می‌کند هم حفظ شود
    $emailDetailsJson = !empty($opts['email_details']) ? json_encode($opts['email_details'], JSON_UNESCAPED_UNICODE) : null;

    if (!$chPush && !$chEmail && !$chTg) return 0; // چیزی برای ارسال نیست

    $st = $conn->prepare("INSERT INTO notification_queue
        (user_id, title, body, type, url, related_id, ch_push, ch_email, ch_telegram, force_email, force_telegram, email_details_json)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    if (!$st) return 0;
    $st->bind_param("issssiiiiiis", $userId, $title, $body, $type, $url, $relatedId,
                    $chPush, $chEmail, $chTg, $forceEmail, $forceTg, $emailDetailsJson);
    $st->execute();
    return (int)$conn->insert_id;
}
}

/**
 * ارسال فوری یک ردیف صف (توسط کران یا در حالت immediate).
 * خروجی: true اگر حداقل یک کانال موفق بود.
 */
if (!function_exists('dispatchNotificationNow')) {
function dispatchNotificationNow($conn, $userId, $title, $body, $opts = []) {
    $type       = $opts['type']       ?? 'general';
    $url        = $opts['url']        ?? '/ledor/dashboard.php';
    $relatedId  = $opts['related_id'] ?? null;
    $doPush     = array_key_exists('push',     $opts) ? (bool)$opts['push']     : true;
    $doEmail    = array_key_exists('email',    $opts) ? (bool)$opts['email']    : true;
    $doTg       = array_key_exists('telegram', $opts) ? (bool)$opts['telegram'] : true;
    $forceEmail = !empty($opts['force_email']) || (array_key_exists('email', $opts) && (bool)$opts['email']);
    // اگر فرستنده صراحتاً تلگرام را خواسته باشد، تنظیم سراسری کاربر نادیده گرفته شود
    $forceTg = !empty($opts['force_telegram']) || (array_key_exists('telegram', $opts) && (bool)$opts['telegram']);

    $ok = false;
    if ($doPush)  { $n = sendPushToUser($conn, $userId, $title, $body, $type, $url, $relatedId); $ok = $ok || ($n > 0); }
    if ($doEmail) { $ok = sendEmailNotification($conn, $userId, $title, $body, $forceEmail, $opts['email_details'] ?? null) || $ok; }
    if ($doTg)    { $ok = sendTelegramNotification($conn, $userId, $title, $body, $forceTg) || $ok; }
    return $ok;
}
}

/**
 * پردازش صف: ارسال اعلان‌های در انتظار.
 * این تابع هم توسط کران و هم به‌عنوان fallback در بارگذاری صفحه صدا زده می‌شود.
 *
 * @param int $limit حداکثر تعداد اعلان در هر اجرا
 * @return array آمار پردازش
 */
if (!function_exists('processNotificationQueue')) {
function processNotificationQueue($conn, $limit = 25) {
    _avapay_ensure_queue_table($conn);
    $limit = max(1, (int)$limit);
    $stat  = ['processed' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];

    $q = @$conn->query("SELECT * FROM notification_queue
                        WHERE status = 'pending' AND attempts < 5
                        ORDER BY id ASC LIMIT $limit");
    if (!$q) return $stat;

    while ($row = $q->fetch_assoc()) {
        $id = (int)$row['id'];
        // قفل ساده: بلافاصله attempts را زیاد کن تا دو پردازش هم‌زمان تکراری نفرستند
        @$conn->query("UPDATE notification_queue SET attempts = attempts + 1 WHERE id = $id");
        $stat['processed']++;

        /* ---- اعتبارسنجی پیش از ارسال ---- */

        // ۱) اعلان کهنه: اگر بیش از ۳۰ دقیقه در صف مانده، دیگر ارسالش بی‌معنی است.
        //    (جلوگیری از «رگبار اعلان» هنگام باز شدن اپ بعد از مدت طولانی)
        $tooOld = false;
        if (!empty($row['created_at'])) {
            $ageQ = @$conn->query("SELECT (created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)) AS old
                                   FROM notification_queue WHERE id = $id");
            if ($ageQ && ($ar = $ageQ->fetch_assoc())) $tooOld = ((int)$ar['old'] === 1);
        }
        if ($tooOld) {
            @$conn->query("UPDATE notification_queue
                           SET status = 'failed', last_error = 'expired (older than 30m)'
                           WHERE id = $id");
            $stat['skipped']++;
            continue;
        }

        // ۲) هشدار قیمت غیرفعال‌شده: اگر کاربر بعد از ثبت در صف، هشدار را خاموش
        //    کرده باشد، نباید اعلانش ارسال شود.
        if (($row['type'] ?? '') === 'price_alert' && !empty($row['related_id'])) {
            $rid = (int)$row['related_id'];
            $chk = @$conn->query("SELECT is_active FROM price_alerts WHERE id = $rid");
            if ($chk && ($cr = $chk->fetch_assoc()) && (int)$cr['is_active'] !== 1) {
                @$conn->query("UPDATE notification_queue
                               SET status = 'failed', last_error = 'alert disabled by user'
                               WHERE id = $id");
                $stat['skipped']++;
                continue;
            }
        }

        $ok = false;
        try {
            $ok = dispatchNotificationNow($conn, (int)$row['user_id'], $row['title'], $row['body'], [
                'type'        => $row['type'],
                'url'         => $row['url'],
                'related_id'  => $row['related_id'],
                'push'        => (int)$row['ch_push'] === 1,
                'email'       => (int)$row['ch_email'] === 1,
                'telegram'    => (int)$row['ch_telegram'] === 1,
                'force_email'    => (int)$row['force_email'] === 1,
                'force_telegram' => (int)($row['force_telegram'] ?? 0) === 1,
                'email_details'  => !empty($row['email_details_json']) ? json_decode($row['email_details_json'], true) : null,
            ]);
        } catch (\Throwable $e) {
            $err = $conn->real_escape_string(substr($e->getMessage(), 0, 240));
            @$conn->query("UPDATE notification_queue SET last_error = '$err' WHERE id = $id");
        }

        if ($ok) {
            @$conn->query("UPDATE notification_queue SET status = 'sent', sent_at = NOW() WHERE id = $id");
            $stat['sent']++;
        } elseif (!empty($GLOBALS['AVA_TG_ERR']) || !empty($GLOBALS['AVA_SMTP_ERR'])) {
            // علت شکست ایمیل را ثبت کن تا در صفحه‌ی سلامت دیده شود
            $parts = [];
            if (!empty($GLOBALS['AVA_SMTP_ERR'])) $parts[] = 'email: ' . $GLOBALS['AVA_SMTP_ERR'];
            if (!empty($GLOBALS['AVA_TG_ERR']))   $parts[] = 'telegram: ' . $GLOBALS['AVA_TG_ERR'];
            $e2 = $conn->real_escape_string(substr(implode(' | ', $parts), 0, 240));
            @$conn->query("UPDATE notification_queue
                           SET last_error = '$e2', status = IF(attempts >= 5, 'failed', 'pending')
                           WHERE id = $id");
            $GLOBALS['AVA_SMTP_ERR'] = null; $GLOBALS['AVA_TG_ERR'] = null;
            $stat['failed']++;
        } else {
            // بعد از ۵ تلاش ناموفق، failed علامت بزن
            @$conn->query("UPDATE notification_queue
                           SET status = IF(attempts >= 5, 'failed', 'pending')
                           WHERE id = $id");
            $stat['failed']++;
        }
    }
    return $stat;
}
}

/* ------------------------------------------------------------------ */
/* اعلان به همه‌ی ادمین‌ها (ایمیل + تلگرام اجباری)                       */
/* ------------------------------------------------------------------ */
/**
 * هر وقت کاربر یک درخواست جدید یا فیش پرداخت آپلود می‌کند باید حتماً
 * به همه‌ی ادمین‌ها از طریق ایمیل یا ربات تلگرام اطلاع داده شود — نه فقط
 * نوتیفیکیشن داخل‌برنامه/پوش که ممکن است دیده نشود.
 * force_email/force_telegram=true یعنی تنظیمات شخصی ادمین (اگر خاموش
 * کرده باشد) نادیده گرفته می‌شود؛ چون این پیام‌ها برای کسب‌وکار حیاتی‌اند.
 */
if (!function_exists('notifyAdmins')) {
function notifyAdmins($conn, $title, $body, $opts = []) {
    $type      = $opts['type']       ?? 'admin_alert';
    $url       = $opts['url']        ?? '/ledor/admin_panel.php';
    $relatedId = $opts['related_id'] ?? null;
    $immediate = array_key_exists('immediate', $opts) ? (bool)$opts['immediate'] : false;
    // برای جلوگیری از دوبله‌شدنِ نوتیف داخل‌برنامه/پوش وقتی کد فراخوان قبلاً
    // خودش addNotif/sendPushNotif را صدا زده — با db=>false / push=>false غیرفعال می‌شود.
    $doDb      = array_key_exists('db',   $opts) ? (bool)$opts['db']   : true;
    $doPush    = array_key_exists('push', $opts) ? (bool)$opts['push'] : true;

    $sent = 0;
    $admins = $conn->query("SELECT id FROM users WHERE is_admin=1");
    if (!$admins) return 0;
    while ($admin = $admins->fetch_assoc()) {
        notifyUser($conn, (int)$admin['id'], $title, $body, [
            'type'       => $type,
            'url'        => $url,
            'related_id' => $relatedId,
            'push'       => $doPush,
            'email'      => true,     // force_email → همیشه ایمیل می‌رود، حتی اگر ادمین خاموش کرده باشد
            'telegram'   => true,     // force_telegram → همیشه ربات تلگرام هم پیام می‌فرستد
            'db'         => $doDb,
            'immediate'  => $immediate,
        ]);
        $sent++;
    }
    return $sent;
}
}

/* ------------------------------------------------------------------ */
/* هاب اصلی: همه‌ی کدهای برنامه این تابع را صدا می‌زنند                  */
/* ------------------------------------------------------------------ */
/**
 * ارسال اعلان به کاربر از همه‌ی کانال‌ها.
 *
 * رفتار پیش‌فرض: اعلان داخل‌برنامه (db) فوراً ثبت می‌شود تا کاربر آنلاین
 * بلافاصله ببیند؛ ایمیل/تلگرام/push به صف می‌روند تا کران ارسال کند.
 *
 * گزینه‌ی 'immediate' => true  ارسال را همان لحظه انجام می‌دهد
 * (برای مواردی مثل کد تأیید/OTP که نباید حتی یک دقیقه تأخیر داشته باشند).
 */
/* ------------------------------------------------------------------ */
/* محافظ ضدتکرار: اگر ادمین (یا هر بخش دیگر) به اشتباه دوبار روی یک    */
/* دکمه بزند، اعلان/ایمیل/پیام تلگرام فقط یک‌بار ارسال شود.            */
/* ------------------------------------------------------------------ */
if (!function_exists('_avapay_notify_dedup_hit')) {
function _avapay_notify_dedup_hit($conn, $userId, $type, $relatedId, $windowSeconds = 20) {
    static $tableReady = false;
    if (!$tableReady) {
        @$conn->query("CREATE TABLE IF NOT EXISTS `notification_dedup` (
            `dedup_key` VARCHAR(191) PRIMARY KEY,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) DEFAULT CHARSET=utf8mb4");
        // پاک‌سازی رکوردهای قدیمی تا جدول بی‌رویه بزرگ نشود
        @$conn->query("DELETE FROM notification_dedup WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
        $tableReady = true;
    }

    $windowSeconds = max(1, (int)$windowSeconds);
    // کلید به «بازه‌ی زمانی» گره می‌خورد (نه فقط user+type+related)، تا اگر همان
    // اعلان واقعاً دوباره لازم شد (مثلاً چند دقیقه بعد)، دوباره ارسال شود؛
    // فقط کلیک‌های تصادفیِ نزدیک به هم در همان چند ثانیه فیلتر می‌شوند.
    $bucket = intdiv(time(), $windowSeconds);
    $key = $userId . ':' . (string)$type . ':' . ($relatedId === null ? '0' : $relatedId) . ':' . $bucket;
    $key = substr($key, 0, 191);

    $ins = $conn->prepare("INSERT IGNORE INTO notification_dedup (dedup_key) VALUES (?)");
    if (!$ins) return false; // در صورت خطای غیرمنتظره، ارسال را مسدود نمی‌کنیم (fail-open)
    $ins->bind_param('s', $key);
    $ins->execute();
    $wasNew = ($conn->affected_rows === 1);
    $ins->close();

    return !$wasNew; // اگر ردیف جدید ثبت نشد، یعنی همین اعلان به‌تازگی ارسال شده است
}
}

/* ------------------------------------------------------------------ */
/* سیاست کانال‌های اعلان                                                */
/* ------------------------------------------------------------------ */
/* همه‌ی رویدادها ارزش ایمیل/تلگرام ندارند. رویدادهای «اطلاعی» که کاربر  */
/* لازم نیست کاری انجام دهد، فقط داخل خود اپ ثبت می‌شوند تا صندوق ایمیل  */
/* و تلگرام کاربر شلوغ نشود. رویدادهای پولی یا نیازمند اقدام، از همه‌ی    */
/* کانال‌ها ارسال می‌شوند.                                               */
if (!function_exists('_avapay_notify_policy')) {
function _avapay_notify_policy($type, array $opts) {
    // فقط اعلان داخل‌برنامه (بدون ایمیل/تلگرام/پوش)
    static $inAppOnly = [
        'deal_receipt',     // طرف مقابل فیش آپلود کرد — ادمین رسیدگی می‌کند
        'invoice_paid',     // اطلاع‌رسانی به ادمین که فیش آمده
        'topup_receipt',    // فیش شارژ ثبت شد
        'topup_request',    // درخواست شارژ ثبت شد
        'received',         // دریافت وجه داخلی (در اپ دیده می‌شود)
        'general',
    ];
    // رویدادهای مهم که همیشه باید از همه‌ی کانال‌ها برود
    static $alwaysAll = [
        'deal_accounts',    // شماره‌حساب برای پرداخت ارسال شد (نیازمند اقدام)
        'admin_receipt',    // ادمین فیش فرستاد
        'topup_approved',   // پول به حساب نشست
        'withdrawal',
        'deal_completed',
        'deposit',
        'sent',
    ];

    // اگر فراخوان صراحتاً کانالی را تعیین کرده باشد، به آن دست نمی‌زنیم.
    $explicit = array_key_exists('email', $opts) || array_key_exists('telegram', $opts) || array_key_exists('push', $opts);

    if (in_array($type, $alwaysAll, true)) return $opts;
    if (!$explicit && in_array($type, $inAppOnly, true)) {
        $opts['email']    = false;
        $opts['telegram'] = false;
        $opts['push']     = false;
    }
    return $opts;
}
}

function notifyUser($conn, $userId, $title, $body, $opts = []) {
    $type      = $opts['type']       ?? 'general';
    $relatedId = $opts['related_id'] ?? null;

    // اعمال سیاست کانال‌ها قبل از هر تصمیم دیگری
    $opts = _avapay_notify_policy($type, $opts);

    $doDb      = array_key_exists('db', $opts) ? (bool)$opts['db'] : true;
    $immediate = !empty($opts['immediate']);

    // اگر این «همان» اعلان است که همین چند ثانیه پیش برای همین کاربر ارسال شد
    // (مثلاً بر اثر دوبار کلیک تصادفی ادمین روی دکمه)، از ارسال مجدد صرف‌نظر می‌شود.
    if (_avapay_notify_dedup_hit($conn, (int)$userId, $type, $relatedId)) {
        return ['db' => false, 'queued' => 0, 'push' => 0, 'email' => false, 'telegram' => false, 'deduped' => true];
    }

    $result = ['db' => false, 'queued' => 0, 'push' => 0, 'email' => false, 'telegram' => false];

    // اعلان داخل‌برنامه فوری ثبت می‌شود (یک INSERT سریع، بدون تماس شبکه)
    if ($doDb) $result['db'] = sendDbNotification($conn, $userId, $type, $title, $body, $relatedId);

    if ($immediate) {
        // ارسال بی‌درنگ (کد تأیید و موارد حساس به زمان)
        $doPush  = array_key_exists('push',     $opts) ? (bool)$opts['push']     : true;
        $doEmail = array_key_exists('email',    $opts) ? (bool)$opts['email']    : true;
        $doTg    = array_key_exists('telegram', $opts) ? (bool)$opts['telegram'] : true;
        $forceEmail = array_key_exists('email', $opts) && (bool)$opts['email'];
        $forceTg    = array_key_exists('telegram', $opts) && (bool)$opts['telegram'];
        if ($doPush)  $result['push']     = sendPushToUser($conn, $userId, $title, $body, $type, $opts['url'] ?? '/ledor/dashboard.php', $relatedId);
        if ($doEmail) $result['email']    = sendEmailNotification($conn, $userId, $title, $body, $forceEmail, $opts['email_details'] ?? null);
        if ($doTg)    $result['telegram'] = sendTelegramNotification($conn, $userId, $title, $body, $forceTg);
        return $result;
    }

    // حالت عادی: به صف بسپار تا کران ارسال کند (صفحه معطل SMTP/تلگرام نمی‌ماند)
    $result['queued'] = queueNotification($conn, $userId, $title, $body, $opts);
    return $result;
}
}
