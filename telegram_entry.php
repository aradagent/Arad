<?php
/**
 * telegram_entry.php
 * ---------------------------------------------------------------
 * تک‌فایلِ کاملِ «ورود مستقیم تلگرام» — همه‌چیز اینجاست: هم صفحه‌ای
 * که از دکمه‌ی ربات باز می‌شود، هم API ورودی که همان صفحه با POST
 * به خودش می‌زند. قبلاً این منطق در ۴ فایل جدا (telegram_entry.php،
 * api/telegram_webapp_auth.php، api/telegram_token_login.php،
 * includes/telegram_login_common.php) پخش بود؛ حالا همه در همین یک
 * فایل جمع شده تا نگهداری‌اش ساده‌تر باشد.
 *
 * حالت صفحه (GET): HTML/JS اسپلش که خودش با POST به همین آدرس، ورود
 * خودکار را انجام می‌دهد و کاربر را به داشبورد می‌فرستد.
 *
 * حالت API (POST) — بدنه‌ی JSON:
 *   - دارای `login_token` → مسیر اصلی: دکمه‌ی لینک معمولی
 *     («url»، نه «web_app») که داخل تلگرام به‌صورت مرورگر معمولی باز
 *     می‌شود. چون این نوع دکمه initData نمی‌دهد، خودِ ربات
 *     (mainbot/update.php) یک توکن یک‌بارمصرف و کوتاه‌عمر در جدول
 *     telegram_login_tokens ذخیره کرده و در URL گذاشته است؛ اینجا
 *     همان توکن اعتبارسنجی و بلافاصله «مصرف‌شده» می‌شود.
 *   - دارای `initData` → مسیر قدیمی/پشتیبان: اگر این صفحه به‌عنوان
 *     Telegram Mini App (Web App) باز شده باشد، امضای initData با
 *     توکن ربات بررسی می‌شود.
 *
 * در هر دو مسیر، پیدا/ساخت کاربر و ساخت سشن دقیقاً یکسان است:
 *   ۱) کاربر جدید: نام/نام‌خانوادگی فقط همین یک‌بار از تلگرام گرفته
 *      می‌شود (اگر تلگرام چیزی نداده بود، خالی می‌ماند — نه مقدار
 *      ساختگی مثل «کاربر تلگرام»).
 *   ۲) کاربر قبلاً ثبت‌نام کرده: نام/نام‌خانوادگی هرگز از تلگرام
 *      بازنویسی نمی‌شود — چون ممکن است کاربر خودش این اطلاعات را از
 *      داخل پروفایل ویرایش کرده باشد و آن مقدار باید همیشه همان‌جا
 *      بماند و همه‌جا (آگهی‌ها، درخواست خرید/فروش و ...) نمایش داده
 *      شود. شماره تلفن هم فقط وقتی از تلگرام پر می‌شود که کاربر تا
 *      الان خودش شماره‌ای ثبت نکرده باشد.
 * ---------------------------------------------------------------
 */

require_once __DIR__ . '/includes/session_boot.php';

/* =====================================================================
 * توابع کمکی مشترک بین دو مسیر ورود (login_token و initData)
 * ===================================================================== */

if (!function_exists('avapay_verify_telegram_webapp')) {
    /**
     * اعتبارسنجی initData ارسال‌شده توسط Telegram Web App طبق الگوریتم
     * رسمی تلگرام:
     * https://core.telegram.org/bots/webapps#validating-data-received-via-the-web-app
     *
     * @return array|false  در صورت معتبر بودن: initData دیکد شده (شامل کلید 'user')
     */
    function avapay_verify_telegram_webapp(string $initData, string $botToken, int $maxAgeSec = 86400) {
        if ($initData === '' || $botToken === '') {
            return false;
        }

        parse_str($initData, $parsed);
        if (!is_array($parsed) || empty($parsed['hash'])) {
            return false;
        }

        $receivedHash = $parsed['hash'];
        unset($parsed['hash']);

        $pairs = [];
        foreach ($parsed as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }
        sort($pairs, SORT_STRING);
        $dataCheckString = implode("\n", $pairs);

        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $calculatedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (!hash_equals($calculatedHash, $receivedHash)) {
            return false;
        }

        $authDate = isset($parsed['auth_date']) ? (int)$parsed['auth_date'] : 0;
        if ($authDate <= 0 || (time() - $authDate) > $maxAgeSec) {
            return false;
        }

        if (!empty($parsed['user'])) {
            $user = json_decode($parsed['user'], true);
            if (!is_array($user) || empty($user['id'])) {
                return false;
            }
            $parsed['user'] = $user;
        } else {
            return false;
        }

        return $parsed;
    }
}

if (!function_exists('avapay_fetch_telegram_avatar')) {
    /**
     * دانلود آخرین عکس پروفایل عمومیِ کاربر از تلگرام (Bot API) و ذخیره در
     * uploads/avatars. تلگرام برخلاف شماره تلفن، عکس پروفایل عمومی هر
     * کاربری که با ربات چت کرده را بدون نیاز به اقدام خاصی از او در
     * اختیار می‌گذارد.
     *
     * این تابع عمداً هرگز Exception پرتاب نمی‌کند — هر خطایی (شبکه، عدم
     * وجود عکس، خطای نوشتن فایل، هرچیز غیرمنتظره) باید فقط null برگرداند
     * تا صدا‌زننده بدون هیچ ریسکی همیشه به آواتار پیش‌فرض اپ برگردد و
     * ثبت‌نام کاربر هرگز به‌خاطر این مرحله‌ی جانبی با خطا مواجه نشود.
     *
     * @return string|null  مسیر نسبی فایل (مثل 'uploads/avatars/tg_123_...jpg') یا null
     */
    function avapay_fetch_telegram_avatar(string $botToken, string $telegramId, string $uploadsAvatarsDir): ?string {
        try {
            if ($botToken === '' || $telegramId === '') return null;

            // (اصلاح) این سه فراخوانیِ متوالی قبلاً هیچ‌کدام timeout نداشتند —
            // یعنی در بدترین حالت (تلگرام کند/بی‌پاسخ) می‌توانستند تا ۳ برابرِ
            // مهلتِ پیش‌فرضِ سیستم (که خودش می‌تواند ۶۰ ثانیه یا بیشتر باشد)
            // یعنی چند دقیقه، یک workerِ PHP-FPM را روی مسیرِ لاگین قفل کنند.
            $__avCtx = stream_context_create(['http' => ['timeout' => 6], 'https' => ['timeout' => 6]]);

            $photosJson = @file_get_contents("https://api.telegram.org/bot{$botToken}/getUserProfilePhotos?user_id=" . urlencode($telegramId) . "&limit=1", false, $__avCtx);
            if ($photosJson === false || $photosJson === '') return null;
            $photos = json_decode($photosJson, true);
            if (!is_array($photos) || empty($photos['ok'])) return null;
            $sizes = $photos['result']['photos'][0] ?? null;
            if (empty($sizes) || !is_array($sizes)) return null;

            $largest = end($sizes); // بزرگ‌ترین سایز، آخرین آیتم آرایه است
            $fileId = $largest['file_id'] ?? null;
            if (!$fileId) return null;

            $fileInfoJson = @file_get_contents("https://api.telegram.org/bot{$botToken}/getFile?file_id=" . urlencode($fileId), false, $__avCtx);
            if ($fileInfoJson === false || $fileInfoJson === '') return null;
            $fileInfoArr = json_decode($fileInfoJson, true);
            $filePath = is_array($fileInfoArr) ? ($fileInfoArr['result']['file_path'] ?? null) : null;
            if (!$filePath) return null;

            $imgData = @file_get_contents("https://api.telegram.org/file/bot{$botToken}/{$filePath}", false, $__avCtx);
            if ($imgData === false || strlen($imgData) < 100) return null;

            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) { $ext = 'jpg'; }

            if (!is_dir($uploadsAvatarsDir)) {
                if (!@mkdir($uploadsAvatarsDir, 0755, true) && !is_dir($uploadsAvatarsDir)) return null;
            }
            $fname = 'tg_' . preg_replace('/[^a-zA-Z0-9]/', '', $telegramId) . '_' . time() . '.' . $ext;
            $fullPath = rtrim($uploadsAvatarsDir, '/') . '/' . $fname;
            if (@file_put_contents($fullPath, $imgData) === false) return null;
            if (!@is_file($fullPath) || @filesize($fullPath) < 100) return null; // اطمینان نهایی از سالم بودن فایل نوشته‌شده

            return 'uploads/avatars/' . $fname;
        } catch (Throwable $e) {
            error_log('avapay_fetch_telegram_avatar error: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('avapay_telegram_find_or_create_user')) {
    /**
     * @return array{banned: bool, ban_message: string, user: array|null, user_id: int, is_new_user: bool}
     */
    function avapay_telegram_find_or_create_user(mysqli $conn, string $telegramId, string $firstName, string $lastName, ?string $contactPhone = null): array {
        $firstName = trim($firstName);
        $lastName  = trim($lastName);

        $stmt = $conn->prepare("SELECT * FROM users WHERE telegram_id = ? LIMIT 1");
        $stmt->bind_param("s", $telegramId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->num_rows === 1 ? $result->fetch_assoc() : null;
        $stmt->close();

        $isNewUser = ($user === null);

        if ($isNewUser) {
            // ثبت‌نام خودکار — نام/نام‌خانوادگی فقط همین یک‌بار از تلگرام
            // گرفته می‌شود؛ اگر خالی بود، خالی ذخیره می‌شود (نه مقدار ساختگی).
            $accountNumber = generateAccountNumber();
            $ibanNumber    = generateIBAN();
            $avatar        = 'default-avatar.png'; // مقدار پیش‌فرض و امن — هر خطایی زیر رخ بدهد همین می‌ماند
            $phoneToSave   = $contactPhone ?? '';

            // عکس پروفایل تلگرام هم فقط همین یک‌بار، در لحظه‌ی ثبت‌نام گرفته
            // می‌شود — دقیقاً مثل نام. avapay_fetch_telegram_avatar هرگز
            // Exception پرتاب نمی‌کند و در هر مشکلی (شبکه، نبود عکس عمومی،
            // خطای نوشتن فایل و ...) فقط null برمی‌گرداند، پس اینجا هم با
            // یک لایه‌ی محافظتی اضافه: اگر چیزی غیرمنتظره رخ داد یا خروجی
            // معتبر نبود، آواتار پیش‌فرض دست‌نخورده باقی می‌ماند.
            try {
                $botTokenForAvatar = defined('BOT_TOKEN') ? BOT_TOKEN : '';
                $tgAvatarPath = avapay_fetch_telegram_avatar($botTokenForAvatar, $telegramId, avapay_upload_dir('avatars'));
                if (is_string($tgAvatarPath) && $tgAvatarPath !== '') {
                    $avatar = $tgAvatarPath;
                }
            } catch (Throwable $e) {
                error_log('telegram avatar fetch failed, falling back to default: ' . $e->getMessage());
                $avatar = 'default-avatar.png';
            }

            $insertSql = "INSERT INTO users (
                telegram_id, iban_number, account_number, first_name, last_name,
                phone_number, avatar, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            $ins = $conn->prepare($insertSql);
            if (!$ins) {
                throw new Exception('DB error: ' . $conn->error);
            }
            $ins->bind_param(
                "sssssss",
                $telegramId, $ibanNumber, $accountNumber, $firstName, $lastName, $phoneToSave, $avatar
            );
            if (!$ins->execute()) {
                throw new Exception('Registration failed: ' . $ins->error);
            }
            $userId = $ins->insert_id;
            $ins->close();

            if (defined('ADMIN_TELEGRAM_ID') && function_exists('sendTelegramMessage')) {
                $displayName = trim($firstName . ' ' . $lastName);
                if ($displayName === '') { $displayName = '(بدون نام)'; }
                $msg = "🎉 ثبت‌نام خودکار جدید:\n👤 {$displayName}\n📱 ID: {$telegramId}\n💳 حساب: {$accountNumber}";
                @sendTelegramMessage(ADMIN_TELEGRAM_ID, $msg);
            }

            $stmt = $conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } else {
            $userId = (int)$user['id'];

            // ---- کاربر مسدود، از هیچ مسیری نباید وارد شود ----
            if (!empty($user['is_banned'])) {
                $banReason = trim((string)($user['ban_reason'] ?? ''));
                $banMsg = 'حساب کاربری شما مسدود شده است و امکان ورود وجود ندارد.';
                if ($banReason !== '') { $banMsg .= ' دلیل: ' . $banReason; }
                return [
                    'banned'      => true,
                    'ban_message' => $banMsg,
                    'user'        => $user,
                    'user_id'     => $userId,
                    'is_new_user' => false,
                ];
            }

            // عمداً هیچ UPDATE ای روی first_name / last_name اینجا نیست —
            // این مقادیر فقط در لحظه‌ی ثبت‌نام از تلگرام گرفته می‌شوند و از
            // آن به بعد، پروفایلی که خود کاربر ثبت کرده منبع حقیقت است.
            if ($contactPhone !== null && $contactPhone !== '' && empty($user['phone_number'])) {
                $upd = $conn->prepare("UPDATE users SET phone_number = ? WHERE id = ?");
                $upd->bind_param("si", $contactPhone, $userId);
                $upd->execute();
                $upd->close();
                $user['phone_number'] = $contactPhone;
            }
        }

        return [
            'banned'      => false,
            'ban_message' => '',
            'user'        => $user,
            'user_id'     => $userId,
            'is_new_user' => $isNewUser,
        ];
    }
}

if (!function_exists('avapay_telegram_start_session')) {
    /**
     * توکن ماندگار ۳۰ روزه + کوکی auth_token + سشن (همان الگوی login.php).
     * @return string  توکن ساخته‌شده
     */
    function avapay_telegram_start_session(mysqli $conn, int $userId, string $telegramId, array $user): string {
        $conn->query("CREATE TABLE IF NOT EXISTS sessions (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            token VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL,
            ip_address VARCHAR(45),
            user_agent TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_token (token)
        )");

        $token      = bin2hex(random_bytes(32));
        $cookieLife = defined('AVAPAY_SESSION_LIFETIME') ? AVAPAY_SESSION_LIFETIME : 60 * 60 * 24 * 30;
        $expiresAt  = date('Y-m-d H:i:s', time() + $cookieLife);
        $ip         = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $ua         = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

        $sst = $conn->prepare("INSERT INTO sessions (user_id, token, expires_at, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
        $sst->bind_param("issss", $userId, $token, $expiresAt, $ip, $ua);
        $sst->execute();
        $sst->close();

        $conn->query("UPDATE users SET last_login = NOW() WHERE id = " . (int)$userId);

        $_SESSION['user_id']       = $userId;
        $_SESSION['telegram_id']   = $telegramId;
        $_SESSION['first_name']    = $user['first_name'] ?? '';
        $_SESSION['last_name']     = $user['last_name'] ?? '';
        $_SESSION['is_admin']      = !empty($user['is_admin']);
        $_SESSION['last_activity'] = time();

        $secureCookie = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        );
        @setcookie('auth_token', $token, [
            'expires'  => time() + $cookieLife,
            'path'     => '/',
            'secure'   => $secureCookie,
            'httponly' => false,
            'samesite' => 'Lax',
        ]);

        if (function_exists('avapay_touch_presence')) {
            avapay_touch_presence($conn, true);
        }

        return $token;
    }
}

/* =====================================================================
 * حالت API (POST) — همین فایل، صدا زده‌شده توسط جاوااسکریپت پایین صفحه
 * ===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/config/database.php';
    header('Content-Type: application/json; charset=utf-8');

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        if (isset($conn)) { $conn->close(); }
        exit();
    }

    require_once __DIR__ . '/includes/referral_system.php';

    $loginToken = trim((string)($input['login_token'] ?? ''));
    $initData   = trim((string)($input['initData'] ?? ''));

    try {
        $telegramId   = null;
        $firstName    = '';
        $lastName     = '';
        $contactPhone = null;
        $refCode      = '';

        if ($loginToken !== '') {
            // ---------------- مسیر اصلی: دکمه‌ی لینک معمولی ----------------
            if (!preg_match('/^[a-f0-9]{32,64}$/', $loginToken)) {
                echo json_encode(['success' => false, 'message' => 'login_token نامعتبر است']);
                if (isset($conn)) { $conn->close(); }
                exit();
            }

            // این جدول را خود ربات (mainbot/update.php) هم می‌سازد؛ این
            // IF NOT EXISTS فقط برای اطمینان روی محیط‌های تازه است.
            $conn->query("CREATE TABLE IF NOT EXISTS telegram_login_tokens (
                id INT PRIMARY KEY AUTO_INCREMENT,
                token VARCHAR(64) NOT NULL,
                telegram_id VARCHAR(64) NOT NULL,
                first_name VARCHAR(255) NOT NULL DEFAULT '',
                last_name VARCHAR(255) NOT NULL DEFAULT '',
                used TINYINT(1) NOT NULL DEFAULT 0,
                expires_at DATETIME NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_token (token)
            )");
            // ستون کد معرف (زیرمجموعه‌گیری) — اگر لینک دعوت از داخل ربات
            // استفاده شده باشد، کد معرف همین‌جا حمل می‌شود.
            // نکته‌ی مهم (باگ واقعی که همین‌جا بود): در PHP 8 درایور mysqli
            // خطاها را Exception پرتاب می‌کند و عملگر @ جلوی آن را نمی‌گیرد؛
            // یک ALTER TABLE بدون بررسی قبلیِ وجود ستون، از دومین اجرا به بعد
            // (که ستون از قبل هست) throw می‌کرد و کل ورود مستقیم را می‌شکست.
            // برای همین حالا اول SHOW COLUMNS چک می‌شود، هم علاوه بر آن یک
            // try/catch محافظ اضافه شده.
            try {
                $hasRefCol = false;
                $colChk = $conn->query("SHOW COLUMNS FROM telegram_login_tokens LIKE 'ref_code'");
                if ($colChk) { $hasRefCol = ($colChk->num_rows > 0); }
                if (!$hasRefCol) {
                    $conn->query("ALTER TABLE telegram_login_tokens ADD COLUMN ref_code VARCHAR(16) DEFAULT NULL");
                }
            } catch (Throwable $e) {
                error_log('telegram_login_tokens ref_code column check failed (non-fatal): ' . $e->getMessage());
            }

            $stmt = $conn->prepare("SELECT * FROM telegram_login_tokens WHERE token = ? LIMIT 1");
            $stmt->bind_param("s", $loginToken);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'لینک نامعتبر است. لطفاً دوباره از داخل ربات وارد شوید.']);
                if (isset($conn)) { $conn->close(); }
                exit();
            }
            if (!empty($row['used'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'این لینک قبلاً استفاده شده است. لطفاً دوباره از داخل ربات وارد شوید.']);
                if (isset($conn)) { $conn->close(); }
                exit();
            }
            if (strtotime($row['expires_at']) < time()) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'این لینک منقضی شده است. لطفاً دوباره از داخل ربات وارد شوید.']);
                if (isset($conn)) { $conn->close(); }
                exit();
            }

            // مصرف‌کردن فوری توکن — ضد استفاده‌ی دوباره (replay)
            $tokenRowId = (int)$row['id'];
            $upd = $conn->prepare("UPDATE telegram_login_tokens SET used = 1 WHERE id = ?");
            $upd->bind_param("i", $tokenRowId);
            $upd->execute();
            $upd->close();

            $telegramId = (string)$row['telegram_id'];
            $firstName  = (string)$row['first_name'];
            $lastName   = (string)$row['last_name'];
            $refCode    = trim((string)($row['ref_code'] ?? ''));

        } elseif ($initData !== '') {
            // ---------------- مسیر قدیمی/پشتیبان: Telegram Mini App ----------------
            $botToken = defined('BOT_TOKEN') ? BOT_TOKEN : '';
            $verified = avapay_verify_telegram_webapp($initData, $botToken);
            if ($verified === false) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Telegram verification failed. Please reopen the app from the bot.']);
                if (isset($conn)) { $conn->close(); }
                exit();
            }

            $tgUser     = $verified['user'];
            $telegramId = (string)$tgUser['id'];
            $firstName  = trim((string)($tgUser['first_name'] ?? ''));
            $lastName   = trim((string)($tgUser['last_name'] ?? ''));

            $contact = is_array($input['contact'] ?? null) ? $input['contact'] : null;
            if ($contact && isset($contact['user_id']) && (string)$contact['user_id'] === $telegramId) {
                $contactPhone = trim((string)($contact['phone_number'] ?? ''));
                if ($contactPhone === '') { $contactPhone = null; }
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'login_token or initData is required']);
            if (isset($conn)) { $conn->close(); }
            exit();
        }

        // شماره تلفن: تلگرام آن را برای هیچ رباتی فاش نمی‌کند مگر با اشتراک‌گذاری
        // دستیِ خودِ کاربر. تنها منبع واقعی که در دسترس داریم، شماره‌ای است که
        // کاربر قبلاً هنگام احراز هویت داخل خودِ ربات (mainbot) با دکمه‌ی
        // اشتراک مخاطب فرستاده و ادمین آن را تایید کرده (جدول account، دیتابیس
        // ربات). اگر هنوز از راه دیگری (initData legacy) شماره‌ای نداریم، همان‌جا
        // را چک می‌کنیم — کاملاً محافظت‌شده: هر خطایی رخ دهد فقط یعنی شماره
        // پیدا نمی‌شود، هیچ‌وقت ثبت‌نام را نمی‌شکند.
        if ($contactPhone === null) {
            try {
                require_once __DIR__ . '/includes/fast_mysqli.php';
                $botDbForPhone = avapay_fast_mysqli('localhost', 'aradexch_bot', 'dA!G&&aSq7-1', 'aradexch_bot', 3);
                if ($botDbForPhone) {
                    $bstmt = $botDbForPhone->prepare("SELECT phone FROM account WHERE chat_id = ? AND verified = 1 LIMIT 1");
                    if ($bstmt) {
                        $bstmt->bind_param("s", $telegramId);
                        $bstmt->execute();
                        $brow = $bstmt->get_result()->fetch_assoc();
                        $bstmt->close();
                        if ($brow && !empty($brow['phone'])) {
                            $contactPhone = trim((string)$brow['phone']);
                        }
                    }
                    $botDbForPhone->close();
                }
            } catch (Throwable $e) {
                error_log('bot-db phone lookup failed (non-fatal): ' . $e->getMessage());
            }
        }

        $r = avapay_telegram_find_or_create_user($conn, $telegramId, $firstName, $lastName, $contactPhone);

        if ($r['banned']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'banned' => true, 'code' => 'ACCOUNT_BANNED', 'message' => $r['ban_message']], JSON_UNESCAPED_UNICODE);
            if (isset($conn)) { $conn->close(); }
            exit();
        }

        $user   = $r['user'];
        $userId = $r['user_id'];

        // ---- زیرمجموعه‌گیری: اگر کاربر تازه ثبت‌نام کرده و از لینک دعوت
        // آمده باشد، به معرف او جایزه‌ی خوش‌آمدگویی واریز می‌شود ----
        if ($r['is_new_user'] && $refCode !== '') {
            try {
                ava_ref_register_new_user($conn, $userId, $refCode);
            } catch (Throwable $e) {
                error_log('referral welcome bonus error: ' . $e->getMessage());
            }
        }

        $token = avapay_telegram_start_session($conn, $userId, $telegramId, $user);

        unset($user['password']);
        echo json_encode([
            'success'     => true,
            'is_new_user' => $r['is_new_user'],
            'token'       => $token,
            'user'        => $user,
            'user_id'     => $userId,
            'redirect'    => 'dashboard.php',
            'message'     => $r['is_new_user'] ? 'خوش آمدید! حساب شما به‌صورت خودکار ساخته شد.' : 'ورود موفق',
        ], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        // پیام واقعیِ خطا هم در لاگ سرور و هم (مثل سایر APIهای این پروژه،
        // مثل referral_api.php و deal_settlement_api.php) مستقیماً در پاسخ
        // JSON برگردانده می‌شود — چون پیام کلی «خطای سرور» قبلی تشخیص علت
        // واقعی مشکل را از روی خودِ اپ غیرممکن می‌کرد.
        error_log('telegram_entry (API) error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'خطای سرور: ' . $e->getMessage()]);
    }

    if (isset($conn)) { $conn->close(); }
    exit();
}

/* =====================================================================
 * حالت صفحه (GET) — اسپلش صفحه‌ای که از دکمه‌ی ربات باز می‌شود
 * ===================================================================== */
require_once __DIR__ . '/includes/logo_helper.php';
$appLogo = getAppLogo();
$__appIcon = ($appLogo ? htmlspecialchars($appLogo['url']) : '/ledor/AVAPAY.PNG?v=' . (@filemtime(__DIR__ . '/AVAPAY.PNG') ?: time()));
$__loginToken = isset($_GET['login_token']) ? preg_replace('/[^a-f0-9]/', '', (string)$_GET['login_token']) : '';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<title>AvaPay</title>
<link rel="icon" type="image/png" href="<?php echo $__appIcon; ?>">
<script src="https://telegram.org/js/telegram-web-app.js"></script>
<style>
    * { box-sizing: border-box; }
    html, body {
        margin: 0; padding: 0; height: 100%;
        background: #1A0B2E;
        font-family: 'Segoe UI', Tahoma, sans-serif;
        display: flex; align-items: center; justify-content: center;
        color: #fff;
    }
    .wrap { text-align: center; padding: 20px; }
    .logo { width: 84px; height: 84px; border-radius: 22px; margin-bottom: 22px; box-shadow: 0 10px 30px rgba(108,64,197,.4); }
    .spinner {
        width: 40px; height: 40px; margin: 0 auto 18px;
        border: 4px solid rgba(255,255,255,.15);
        border-top: 4px solid #6C40C5;
        border-radius: 50%;
        animation: spin 0.9s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    .msg { font-size: 15px; color: #cfc4e0; min-height: 20px; }
    .err { color: #FF6B6B; font-size: 14px; margin-top: 14px; display: none; }
    .err a { color: #a58bf0; }
</style>
</head>
<body>
<?php require_once __DIR__ . '/includes/pwa_device_gate.php'; ?>
<div class="wrap">
    <img class="logo" src="<?php echo $__appIcon; ?>" alt="AvaPay">
    <div class="spinner"></div>
    <div class="msg" id="msg">در حال ورود خودکار...</div>
    <div class="err" id="err">
        مشکلی در ورود خودکار پیش آمد.
        <br><a href="login.php">ورود دستی به AVA PAY</a>
    </div>
</div>

<script>
(function () {
    if (window.__avaGateBlocked) { return; } // فقط موبایل/تبلت یا PWA نصب‌شده‌ی دسکتاپ اجازه‌ی ورود خودکار دارند
    var msgEl = document.getElementById('msg');
    var errEl = document.getElementById('err');
    var loginToken = <?php echo json_encode($__loginToken); ?>;
    // همین فایل، هم صفحه است هم API؛ کافی است با POST به خودش فچ بزنیم.
    var SELF_URL = 'telegram_entry.php';

    function fail(text) {
        document.querySelector('.spinner').style.display = 'none';
        msgEl.textContent = text || 'خطا در ورود';
        errEl.style.display = 'block';
    }

    function goToDashboard(target) {
        window.location.replace(target || 'dashboard.php');
    }

    function sendAuth(payload) {
        fetch(SELF_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data && data.success) {
                goToDashboard(data.redirect);
            } else if (data && data.banned) {
                fail(data.message || 'حساب شما مسدود شده است.');
            } else {
                fail((data && data.message) || 'ورود ناموفق بود.');
            }
        })
        .catch(function () {
            fail('ارتباط با سرور برقرار نشد.');
        });
    }

    // ---- مسیر اصلی: دکمه‌ی لینک معمولی (مرورگر داخل تلگرام) ----
    if (loginToken) {
        sendAuth({ login_token: loginToken });
        return;
    }

    // ---- مسیر قدیمی/پشتیبان: اگر این صفحه به‌عنوان Mini App باز شده ----
    var tg = window.Telegram && window.Telegram.WebApp ? window.Telegram.WebApp : null;
    var initData = tg ? tg.initData : '';
    if (!tg || !initData) {
        // نه login_token در URL هست و نه initData ای — یعنی بیرون از
        // تلگرام باز شده؛ به‌صورت امن به صفحه‌ی ورود معمولی می‌رویم.
        window.location.replace('login.php');
        return;
    }

    try { tg.ready(); tg.expand(); } catch (e) {}
    sendAuth({ initData: initData });
})();
</script>
</body>
</html>
