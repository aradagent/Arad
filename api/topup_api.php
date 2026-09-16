<?php
// api/topup_api.php - نسخه نهایی اصلاح‌شده
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once '../config/database.php';
require_once __DIR__ . '/../includes/notify_helper.php';
require_once __DIR__ . '/../includes/invoice_sync.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ========== AUTH CHECK ==========
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}
$userId = intval($_SESSION['user_id']);
$action = $_GET['action'] ?? '';

// ========== AUTO-CREATE TABLE (با finalized در ENUM) ==========
$conn->query("CREATE TABLE IF NOT EXISTS `topup_requests` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `currency` ENUM('USD','EUR','USDT') NOT NULL DEFAULT 'USD',
    `amount` DECIMAL(15,2) NOT NULL,
    `status` ENUM('pending','approved','waiting_payment','payment_received','completed','rejected','finalized') DEFAULT 'pending',
    `reject_reason` TEXT DEFAULT NULL,
    `bank_name` VARCHAR(255) DEFAULT NULL,
    `account_number` VARCHAR(100) DEFAULT NULL,
    `card_number` VARCHAR(30) DEFAULT NULL,
    `recipient_name` VARCHAR(255) DEFAULT NULL,
    `iban` VARCHAR(100) DEFAULT NULL,
    `receipt_image` VARCHAR(500) DEFAULT NULL,
    `admin_id` INT DEFAULT NULL,
    `admin_final_note` TEXT DEFAULT NULL,
    `admin_final_image` VARCHAR(500) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_status (status)
)");

// ========== AUTO-DELETE پیام‌های قدیمی ==========
// اصلاح سرعت: قبلاً این DELETE روی *هر* درخواست (حتی poll هر ۸ ثانیه) اجرا
// می‌شد و چون created_at ایندکس ندارد، یعنی اسکن کامل جدول روی هر تک
// درخواست. حالا حداکثر هر ۳۰ دقیقه یک‌بار اجرا می‌شود.
require_once __DIR__ . '/../includes/perf_helpers.php';
if (avapay_throttled('topup_cleanup', 1800)) {
    // ایندکس روی created_at تا خودِ همین DELETE هم دیگر اسکن کامل جدول نباشد
    // نکته: @ خطای mysqli_sql_exception را در PHP 8 خاموش نمی‌کند، پس باید
    // اول با SHOW INDEX چک کنیم (طبق درس تکرارشده‌ی همین پروژه).
    $idxChk = $conn->query("SHOW INDEX FROM topup_requests WHERE Key_name = 'idx_created_at'");
    if ($idxChk && $idxChk->num_rows === 0) {
        try { $conn->query("ALTER TABLE topup_requests ADD INDEX idx_created_at (created_at)"); } catch (\Throwable $e) {}
    }
    $conn->query("DELETE FROM topup_requests WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 MONTH)");
}

// ========== HELPER: Push Notification (اکنون از هاب مرکزی استفاده می‌کند) ==========
// push روی اندروید اصلاح شده و ایمیل هم ارسال می‌شود.
function sendPushNotif($conn, $targetUserId, $title, $body, $url = '/ledor/dashboard.php', $type = 'topup', $relatedId = null) {
    // اگر همین اعلان همین چند ثانیه پیش ارسال شده (مثلاً دوبار کلیک تصادفی ادمین)، دوباره نمی‌فرستیم
    if (function_exists('_avapay_notify_dedup_hit') && _avapay_notify_dedup_hit($conn, (int)$targetUserId, $type, $relatedId)) return true;

    // برای گیرنده‌های ادمین ایمیل ارسال نمی‌کنیم تا اسپم نشود؛
    // فقط برای رویدادهای کاربر ایمیل می‌رود.
    $isAdminTarget = (strpos($type, 'admin') !== false)
                     || (strpos($url, 'admin_panel') !== false);
    sendPushToUser($conn, $targetUserId, $title, $body, $type, $url, null);
    if (!$isAdminTarget) {
        sendEmailNotification($conn, $targetUserId, $title, $body);
    }
    return true;
}

function addNotif($conn, $uid, $type, $title, $msg, $relatedId = null) {
    if (function_exists('_avapay_notify_dedup_hit') && _avapay_notify_dedup_hit($conn, (int)$uid, 'db_' . $type, $relatedId)) return;
    $title  = $conn->real_escape_string($title);
    $msg    = $conn->real_escape_string($msg);
    $relSQL = $relatedId ? intval($relatedId) : 'NULL';
    $conn->query("INSERT INTO user_notifications (user_id, type, title, message, related_id)
                  VALUES ($uid, '$type', '$title', '$msg', $relSQL)");
}

// ========== USER: ثبت درخواست جدید ==========
if ($action === 'create_request' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $__banChk = $conn->query("SHOW COLUMNS FROM users LIKE 'is_banned'");
    if ($__banChk && $__banChk->num_rows > 0) {
        $__banRow = $conn->query("SELECT is_banned, ban_reason FROM users WHERE id = " . (int)$userId)->fetch_assoc();
        if (!empty($__banRow['is_banned'])) {
            echo json_encode(['success' => false, 'message' => 'حساب شما مسدود شده و امکان ثبت درخواست شارژ ندارید' . (!empty($__banRow['ban_reason']) ? ' (' . $__banRow['ban_reason'] . ')' : '')]);
            exit();
        }
    }

    $data     = json_decode(file_get_contents('php://input'), true);
    $currency = in_array($data['currency'] ?? '', ['USD','EUR','USDT']) ? $data['currency'] : 'USD';
    $amount   = floatval($data['amount'] ?? 0);

    if ($amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'مقدار نامعتبر است']);
        exit();
    }

    // FIX: جلوگیری از ارسال درخواست تکراری در حالت pending
    $existing = $conn->query("SELECT id FROM topup_requests WHERE user_id=$userId AND status='pending' LIMIT 1")->fetch_assoc();
    if ($existing) {
        echo json_encode(['success' => false, 'message' => 'یک درخواست در حال بررسی دارید. لطفاً منتظر بمانید.']);
        exit();
    }

    $stmt = $conn->prepare("INSERT INTO topup_requests (user_id, currency, amount, status) VALUES (?, ?, ?, 'pending')");
    $stmt->bind_param("isd", $userId, $currency, $amount);
    $stmt->execute();
    $requestId = $conn->insert_id;

    $userRow  = $conn->query("SELECT first_name, last_name FROM users WHERE id=$userId")->fetch_assoc();
    $userName = trim(($userRow['first_name'] ?? '') . ' ' . ($userRow['last_name'] ?? ''));

    $admins = $conn->query("SELECT id FROM users WHERE is_admin=1");
    while ($admin = $admins->fetch_assoc()) {
        $adminId = $admin['id'];
        addNotif($conn, $adminId, 'topup_request', "💰 درخواست Top-up جدید", "کاربر $userName درخواست شارژ $amount $currency ارسال کرد.", $requestId);
        sendPushNotif($conn, $adminId, "💰 درخواست Top-up جدید", "کاربر $userName درخواست شارژ $amount $currency ارسال کرد.", '/ledor/admin_panel.php', 'topup_admin');
    }
    // مهم: ادمین باید حتماً از طریق ایمیل یا ربات تلگرام هم مطلع شود (نه فقط پوش/دیتابیس)
    if (function_exists('notifyAdmins')) {
        notifyAdmins($conn, "💰 درخواست Top-up جدید", "کاربر $userName درخواست شارژ $amount $currency ارسال کرد.", [
            'type' => 'topup_request', 'url' => '/ledor/admin_panel.php', 'related_id' => $requestId,
            'db' => false, 'push' => false, // قبلاً بالا ارسال شد، اینجا فقط ایمیل/تلگرام اجباری اضافه می‌شود
        ]);
    }

    $newReq = $conn->query("SELECT * FROM topup_requests WHERE id=$requestId")->fetch_assoc();
    echo json_encode(['success' => true, 'request_id' => $requestId, 'request' => $newReq]);
    exit();
}

// ========== USER: لیست درخواست‌های من ==========
if ($action === 'get_my_requests') {
    $result   = $conn->query("SELECT * FROM topup_requests WHERE user_id=$userId ORDER BY created_at DESC LIMIT 20");
    $requests = [];
    while ($row = $result->fetch_assoc()) $requests[] = $row;
    $latest = count($requests) > 0 ? $requests[0] : null;
    echo json_encode(['success' => true, 'requests' => $requests, 'latest' => $latest]);
    exit();
}

// ========== USER: Poll وضعیت درخواست ==========
if ($action === 'poll_request') {
    $requestId = intval($_GET['request_id'] ?? 0);
    if ($requestId <= 0) { echo json_encode(['success' => false]); exit(); }
    $req = $conn->query("SELECT * FROM topup_requests WHERE id=$requestId AND user_id=$userId")->fetch_assoc();
    if (!$req) { echo json_encode(['success' => false, 'message' => 'not found']); exit(); }
    echo json_encode(['success' => true, 'request' => $req]);
    exit();
}

// ========== USER: آپلود فیش پرداخت ==========
// FIX: status های مجاز برای آپلود: approved یا waiting_payment (برای آپلود مجدد)
if ($action === 'upload_receipt' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestId = intval($_POST['request_id'] ?? 0);

    if ($requestId <= 0) {
        echo json_encode(['success' => false, 'message' => 'شناسه درخواست نامعتبر است']);
        exit();
    }

    $req = $conn->query("SELECT * FROM topup_requests WHERE id=$requestId AND user_id=$userId")->fetch_assoc();
    if (!$req) {
        echo json_encode(['success' => false, 'message' => 'درخواست یافت نشد']);
        exit();
    }

    // آپلود مجاز است وقتی شماره‌حساب/مبلغ ارسال شده (waiting_payment) یا برای آپلود مجدد (payment_received)
    $allowedStatuses = ['waiting_payment', 'payment_received', 'approved'];
    if (!in_array($req['status'], $allowedStatuses)) {
        echo json_encode(['success' => false, 'message' => 'درخواست باید ابتدا توسط ادمین تأیید شود (وضعیت: ' . $req['status'] . ')']);
        exit();
    }

    // پشتیبانی از چند فایل: receipts[] یا receipt تکی
    $filesInput = [];
    if (!empty($_FILES['receipts']['name'][0])) {
        // حالت چندتایی: receipts[]
        $count = count($_FILES['receipts']['name']);
        for ($i = 0; $i < $count; $i++) {
            $filesInput[] = [
                'name'     => $_FILES['receipts']['name'][$i],
                'type'     => $_FILES['receipts']['type'][$i],
                'tmp_name' => $_FILES['receipts']['tmp_name'][$i],
                'error'    => $_FILES['receipts']['error'][$i],
                'size'     => $_FILES['receipts']['size'][$i],
            ];
        }
    } elseif (!empty($_FILES['receipt']['name'])) {
        // عقب‌سازگاری: receipt تکی
        $filesInput[] = $_FILES['receipt'];
    }

    if (empty($filesInput)) {
        echo json_encode(['success' => false, 'message' => 'فایلی ارسال نشده یا خطا در آپلود']);
        exit();
    }

    $allowed   = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $uploadDir = avapay_upload_dir('topup_receipts');

    // تشخیص mime بدون نیاز به finfo (سازگار با همه سرورها)
    if (!function_exists('detectMime')) {
        function detectMime($tmpPath) {
            if (function_exists('finfo_open')) {
                $fi = finfo_open(FILEINFO_MIME_TYPE);
                $m  = finfo_file($fi, $tmpPath);
                finfo_close($fi);
                return $m;
            }
            if (function_exists('mime_content_type')) {
                return mime_content_type($tmpPath);
            }
            $bytes = file_get_contents($tmpPath, false, null, 0, 12);
            if (substr($bytes,0,3) === "\xff\xd8\xff")          return 'image/jpeg';
            if (substr($bytes,0,8) === "\x89PNG\r\n\x1a\n")      return 'image/png';
            if (substr($bytes,0,6) === 'GIF87a' || substr($bytes,0,6) === 'GIF89a') return 'image/gif';
            if (substr($bytes,0,4) === 'RIFF' && substr($bytes,8,4) === 'WEBP')     return 'image/webp';
            return 'application/octet-stream';
        }
    }

    $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $uploadedPaths = [];

    foreach ($filesInput as $idx => $file) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'خطا در آپلود فایل ' . ($idx + 1)]);
            exit();
        }
        $realMime = detectMime($file['tmp_name']);
        if (!in_array($realMime, $allowed)) {
            echo json_encode(['success' => false, 'message' => 'فرمت فایل ' . ($idx + 1) . ' مجاز نیست (فقط JPG, PNG, GIF, WEBP)']);
            exit();
        }
        if ($file['size'] > 5 * 1024 * 1024) {
            echo json_encode(['success' => false, 'message' => 'حجم فایل ' . ($idx + 1) . ' بیشتر از 5MB است']);
            exit();
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts)) $ext = 'jpg';
        $newName = 'topup_' . $requestId . '_' . time() . '_' . $idx . '.' . $ext;
        $path    = $uploadDir . $newName;
        if (!move_uploaded_file($file['tmp_name'], $path)) {
            echo json_encode(['success' => false, 'message' => 'آپلود فایل ' . ($idx + 1) . ' ناموفق بود. لطفاً مجوزهای پوشه را بررسی کنید.']);
            exit();
        }
        $uploadedPaths[] = '/ledor/uploads/topup_receipts/' . $newName;
    }

    // ذخیره تصاویر: اگر چند فایل باشد JSON، اگر یک فایل باشد path مستقیم
    $firstImgPath = $uploadedPaths[0];
    $allImgsJson  = count($uploadedPaths) > 1 ? json_encode($uploadedPaths) : $firstImgPath;
    $escaped = $conn->real_escape_string($allImgsJson);

    $conn->query("UPDATE topup_requests SET receipt_image='$escaped', status='payment_received' WHERE id=$requestId AND user_id=$userId");

    $admins = $conn->query("SELECT id FROM users WHERE is_admin=1");
    while ($admin = $admins->fetch_assoc()) {
        $adminId = $admin['id'];
        $cnt = count($uploadedPaths);
        addNotif($conn, $adminId, 'topup_receipt', "🧾 فیش واریز دریافت شد", "کاربر $cnt فیش پرداخت Top-up #$requestId را ارسال کرد.", $requestId);
        sendPushNotif($conn, $adminId, "🧾 فیش واریز دریافت شد", "کاربر $cnt فیش پرداخت Top-up #$requestId را ارسال کرد.", '/ledor/admin_panel.php', 'topup_receipt');
    }
    // مهم: هر بار کاربر فیش آپلود می‌کند ادمین باید حتماً ایمیل یا پیام ربات دریافت کند
    if (function_exists('notifyAdmins')) {
        $cnt = count($uploadedPaths);
        notifyAdmins($conn, "🧾 فیش واریز دریافت شد", "کاربر $cnt فیش پرداخت Top-up #$requestId را ارسال کرد.", [
            'type' => 'topup_receipt', 'url' => '/ledor/admin_panel.php', 'related_id' => $requestId,
            'db' => false, 'push' => false,
        ]);
    }

    echo json_encode(['success' => true, 'image_path' => $firstImgPath, 'image_paths' => $uploadedPaths]);
    exit();
}

// ========== ADMIN ONLY ==========
$adminCheck = $conn->query("SELECT is_admin FROM users WHERE id=$userId")->fetch_assoc();
if (!$adminCheck || !$adminCheck['is_admin']) {
    echo json_encode(['success' => false, 'message' => 'دسترسی فقط برای ادمین']);
    exit();
}

// ========== ADMIN: لیست همه درخواست‌ها ==========
if ($action === 'admin_get_requests') {
    $status = $conn->real_escape_string($_GET['status'] ?? '');
    $where  = $status ? "WHERE t.status='$status'" : '';
    $result = $conn->query("
        SELECT t.*, u.first_name, u.last_name, u.phone_number, u.email
        FROM topup_requests t
        JOIN users u ON t.user_id = u.id
        $where
        ORDER BY t.created_at DESC
        LIMIT 100
    ");
    $requests = [];
    while ($row = $result->fetch_assoc()) $requests[] = $row;
    echo json_encode(['success' => true, 'requests' => $requests]);
    exit();
}

// ========== ADMIN: تأیید درخواست + ارسال اطلاعات بانکی ==========
if ($action === 'approve_request' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data           = json_decode(file_get_contents('php://input'), true);
    $requestId      = intval($data['request_id'] ?? 0);
    $bankName       = $conn->real_escape_string($data['bank_name'] ?? '');
    $accountNumber  = $conn->real_escape_string($data['account_number'] ?? '');
    $cardNumber     = $conn->real_escape_string($data['card_number'] ?? '');
    $recipientName  = $conn->real_escape_string($data['recipient_name'] ?? '');
    $iban           = $conn->real_escape_string($data['iban'] ?? '');
    $paymentAmount  = floatval($data['payment_amount'] ?? 0);
    $paymentCurrency = $conn->real_escape_string($data['payment_currency'] ?? 'IRR');

    if ($requestId <= 0) {
        echo json_encode(['success' => false, 'message' => 'شناسه درخواست نامعتبر']);
        exit();
    }
    if ($paymentAmount <= 0) {
        echo json_encode(['success' => false, 'message' => 'لطفاً مبلغ قابل پرداخت را وارد کنید']);
        exit();
    }

    $req = $conn->query("SELECT * FROM topup_requests WHERE id=$requestId")->fetch_assoc();
    if (!$req) { echo json_encode(['success' => false, 'message' => 'درخواست یافت نشد']); exit(); }

    // اطمینان از وجود ستون‌های مبلغ/ارزِ قابل‌پرداخت (که خودِ ادمین تعیین می‌کند)
    $__colChk = $conn->query("SHOW COLUMNS FROM topup_requests LIKE 'payment_amount'");
    if ($__colChk && $__colChk->num_rows === 0) {
        $conn->query("ALTER TABLE topup_requests ADD COLUMN payment_amount DECIMAL(20,2) DEFAULT NULL");
        $conn->query("ALTER TABLE topup_requests ADD COLUMN payment_currency VARCHAR(10) DEFAULT NULL");
    }

    $conn->query("UPDATE topup_requests SET
        status='waiting_payment',
        bank_name='$bankName',
        account_number='$accountNumber',
        card_number='$cardNumber',
        recipient_name='$recipientName',
        iban='$iban',
        payment_amount=$paymentAmount,
        payment_currency='$paymentCurrency',
        admin_id=$userId
        WHERE id=$requestId");

    $targetUserId = intval($req['user_id']);
    $payAmountFmt = number_format($paymentAmount);

    // همان درخواست پرداخت را در کادر «صورت‌حساب‌ها»ی داشبورد کاربر هم ثبت
    // می‌کنیم تا کاربر آن را در «در انتظار پرداخت» ببیند و بتواند بدون
    // تأیید مجدد پرداخت کند.
    if (function_exists('avapay_sync_payment_invoice')) {
        avapay_sync_payment_invoice($conn, 'topup', $requestId, $targetUserId,
            $paymentAmount, $paymentCurrency,
            "شارژ کیف پول {$req['amount']} {$req['currency']}",
            ['bank_name' => $bankName, 'account_number' => $accountNumber,
             'card_number' => $cardNumber, 'recipient_name' => $recipientName, 'iban' => $iban]);
    }
    $accTitle = "✅ درخواست Top-up تأیید شد";
    $accBody  = "درخواست شارژ {$req['amount']} {$req['currency']} شما تأیید شد. لطفاً مبلغ {$payAmountFmt} {$paymentCurrency} را به شماره‌حساب ارسالی پرداخت کرده و فیش را بارگذاری کنید.";
    addNotif($conn, $targetUserId, 'topup_approved', $accTitle, $accBody, $requestId);
    sendPushNotif($conn, $targetUserId, $accTitle, "لطفاً مبلغ {$payAmountFmt} {$paymentCurrency} را پرداخت و فیش را ارسال کنید.", '/ledor/arad.php', 'topup_approved');
    // مهم: چون ادمین اینجا شماره‌حساب واریز را برای کاربر می‌فرستد، کاربر
    // حتماً حتماً باید ایمیل و پیام ربات دریافت کند (بدون توجه به تنظیمات شخصی‌اش)
    if (function_exists('notifyUser')) {
        notifyUser($conn, $targetUserId, $accTitle, $accBody, [
            'type' => 'topup_approved', 'url' => '/ledor/arad.php', 'related_id' => $requestId,
            'push' => false, 'db' => false, 'email' => true, 'telegram' => true, 'immediate' => true,
        ]);
    }

    echo json_encode(['success' => true]);
    exit();
}

// ========== ADMIN: رد درخواست ==========
if ($action === 'reject_request' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data      = json_decode(file_get_contents('php://input'), true);
    $requestId = intval($data['request_id'] ?? 0);
    $reason    = $conn->real_escape_string($data['reason'] ?? '');

    $req = $conn->query("SELECT * FROM topup_requests WHERE id=$requestId")->fetch_assoc();
    if (!$req) { echo json_encode(['success' => false, 'message' => 'درخواست یافت نشد']); exit(); }

    $conn->query("UPDATE topup_requests SET status='rejected', reject_reason='$reason', admin_id=$userId WHERE id=$requestId");

    // درخواست رد شد → صورت‌حساب مرتبط هم نباید در «در انتظار پرداخت» بماند
    if (function_exists('avapay_close_payment_invoice')) {
        avapay_close_payment_invoice($conn, 'topup', $requestId);
    }

    $targetUserId = intval($req['user_id']);
    addNotif($conn, $targetUserId, 'topup_rejected', "❌ درخواست Top-up رد شد", "درخواست شارژ {$req['amount']} {$req['currency']} شما رد شد. دلیل: $reason", $requestId);
    sendPushNotif($conn, $targetUserId, "❌ درخواست Top-up رد شد", "درخواست شما رد شد. دلیل: $reason", '/ledor/arad.php', 'topup_rejected');

    echo json_encode(['success' => true]);
    exit();
}

// ========== ADMIN: تکمیل top-up + شارژ موجودی کاربر ==========
if ($action === 'complete_topup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data      = json_decode(file_get_contents('php://input'), true);
    $requestId = intval($data['request_id'] ?? 0);

    $req = $conn->query("SELECT * FROM topup_requests WHERE id=$requestId")->fetch_assoc();
    if (!$req || $req['status'] !== 'payment_received') {
        echo json_encode(['success' => false, 'message' => 'وضعیت درخواست نامعتبر است (باید فیش پرداخت ارسال شده باشد)']);
        exit();
    }

    $targetUserId = intval($req['user_id']);
    $amount       = floatval($req['amount']);
    $currency     = $req['currency'];
    $balanceField = 'balance_' . strtolower($currency);

    $conn->query("UPDATE users SET $balanceField = $balanceField + $amount WHERE id=$targetUserId");

    $txId = 'TOPUP' . time() . rand(1000, 9999);
    $desc = $conn->real_escape_string("[Top-up] شارژ $amount $currency توسط ادمین تأیید شد");
    $conn->query("INSERT INTO transactions (transaction_id, sender_id, receiver_id, amount, currency, type, description, status, admin_id)
        VALUES ('$txId', $userId, $targetUserId, $amount, '$currency', 'deposit', '$desc', 'completed', $userId)");

    $conn->query("UPDATE topup_requests SET status='completed', admin_id=$userId WHERE id=$requestId");

    // صورت‌حساب مرتبط از «در انتظار پرداخت» خارج و آرشیو می‌شود
    if (function_exists('avapay_close_payment_invoice')) {
        avapay_close_payment_invoice($conn, 'topup', $requestId);
    }

    addNotif($conn, $targetUserId, 'topup_completed', "💰 شارژ حساب موفق", "مبلغ $amount $currency با موفقیت به حساب شما اضافه شد.", $requestId);
    sendPushNotif($conn, $targetUserId, "💰 شارژ حساب موفق", "مبلغ $amount $currency به حساب شما اضافه شد.", '/ledor/arad.php', 'topup_completed');

    echo json_encode(['success' => true, 'target_user_id' => $targetUserId, 'amount' => $amount, 'currency' => $currency]);
    exit();
}

// ========== Chart Data ==========
if ($action === 'chart_data') {
    $days        = intval($_GET['days'] ?? 30);
    $currency    = in_array($_GET['currency'] ?? '', ['USD', 'EUR', 'USDT']) ? $_GET['currency'] : 'USD';
    $chartUserId = intval($_GET['user_id'] ?? $userId);
    if ($chartUserId !== $userId) $chartUserId = $userId; // فقط ادمین می‌تواند user_id دیگری ببیند - بالا چک شد

    $result = $conn->query("
        SELECT DATE(created_at) as day, SUM(amount) as total, COUNT(*) as count
        FROM topup_requests
        WHERE currency='$currency' AND status='completed'
          AND user_id=$chartUserId
          AND created_at >= DATE_SUB(NOW(), INTERVAL $days DAY)
        GROUP BY DATE(created_at)
        ORDER BY day ASC
    ");
    $data = [];
    while ($row = $result->fetch_assoc()) $data[] = $row;
    echo json_encode(['success' => true, 'data' => $data]);
    exit();
}

// ========== ADMIN: موجودی کاربر ==========
if ($action === 'get_user_balance') {
    $targetId = intval($_GET['user_id'] ?? 0);
    if ($targetId <= 0) { echo json_encode(['success' => false]); exit(); }
    $u = $conn->query("SELECT balance_usd, balance_eur, balance_usdt, balance_irr, first_name, last_name FROM users WHERE id=$targetId")->fetch_assoc();
    if (!$u) { echo json_encode(['success' => false]); exit(); }
    echo json_encode([
        'success'  => true,
        'balances' => [
            'USD'  => floatval($u['balance_usd']),
            'EUR'  => floatval($u['balance_eur']),
            'USDT' => floatval($u['balance_usdt']),
            'IRR'  => floatval($u['balance_irr'])
        ],
        'name' => $u['first_name'] . ' ' . $u['last_name']
    ]);
    exit();
}

// ========== ADMIN: حذف یک درخواست از لیست ==========
if ($action === 'admin_delete_request' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $reqId = intval($data['request_id'] ?? 0);
    if ($reqId <= 0) { echo json_encode(['success' => false, 'message' => 'شناسه نامعتبر']); exit(); }
    $stmt = $conn->prepare("DELETE FROM topup_requests WHERE id = ?");
    $stmt->bind_param("i", $reqId);
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'خطا در حذف']);
    }
    exit();
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);