<?php


// api/unpaid_invoice_api.php - API برای مدیریت فیش‌های پرداخت نشده
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once '../config/database.php';
require_once __DIR__ . '/../includes/notify_helper.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// ========== AUTO-CREATE TABLE ==========
$conn->query("CREATE TABLE IF NOT EXISTS `unpaid_invoices` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `currency` ENUM('USD','EUR','USDT','IRR') NOT NULL DEFAULT 'USD',
    `amount` DECIMAL(15,2) NOT NULL,
    `description` TEXT,
    `status` ENUM('pending','approved','paid','finalized','rejected') DEFAULT 'pending',
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

// اصلاح سرعت: این DELETE قبلاً روی هر درخواست اجرا می‌شد؛ حالا throttle شده
require_once __DIR__ . '/../includes/perf_helpers.php';
if (avapay_throttled('unpaid_invoice_cleanup', 1800)) {
    $idxChk = $conn->query("SHOW INDEX FROM unpaid_invoices WHERE Key_name = 'idx_created_at'");
    if ($idxChk && $idxChk->num_rows === 0) {
        try { $conn->query("ALTER TABLE unpaid_invoices ADD INDEX idx_created_at (created_at)"); } catch (\Throwable $e) {}
    }
    $conn->query("DELETE FROM unpaid_invoices WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 MONTH)");
}
// (جدید) ستون‌های invoice_type/source_type را همیشه (نه فقط داخل اکشن
// create_invoice) مطمئن می‌شویم وجود دارند، چون finalize_invoice/admin_get_invoices
// هم به source_type نیاز دارند و ممکن است قبل از هر create_invoice تازه اجرا شوند.
if (avapay_throttled('unpaid_invoice_migrate_source_type', 1800)) {
    $hasType = $conn->query("SHOW COLUMNS FROM unpaid_invoices LIKE 'invoice_type'");
    if (!$hasType || $hasType->num_rows === 0) {
        try { $conn->query("ALTER TABLE unpaid_invoices ADD COLUMN invoice_type VARCHAR(30) DEFAULT 'other' AFTER description"); } catch (\Throwable $e) {}
    }
    $hasSourceType = $conn->query("SHOW COLUMNS FROM unpaid_invoices LIKE 'source_type'");
    if (!$hasSourceType || $hasSourceType->num_rows === 0) {
        try { $conn->query("ALTER TABLE unpaid_invoices ADD COLUMN source_type VARCHAR(10) DEFAULT 'general' AFTER invoice_type"); } catch (\Throwable $e) {}
    }
}

$action = $_GET['action'] ?? '';

// ========== Auth required ==========
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}
$userId = $_SESSION['user_id'];

// ========== Helper: Send push notification (اکنون از هاب مرکزی) ==========
function sendPushNotif($conn, $targetUserId, $title, $body, $url = '/ledor/dashboard.php', $type = 'invoice', $relatedId = null) {
    // اگر همین اعلان همین چند ثانیه پیش ارسال شده (مثلاً دوبار کلیک تصادفی ادمین)، دوباره نمی‌فرستیم
    if (function_exists('_avapay_notify_dedup_hit') && _avapay_notify_dedup_hit($conn, (int)$targetUserId, $type, $relatedId)) return true;

    // احترام به تنظیم toast/push کاربر
    $prefCol = $conn->query("SHOW COLUMNS FROM users LIKE 'notify_toast'");
    $pushAllowed = true;
    if ($prefCol && $prefCol->num_rows > 0) {
        $pref = $conn->query("SELECT notify_toast FROM users WHERE id=" . intval($targetUserId))->fetch_assoc();
        if ($pref && (int)$pref['notify_toast'] === 0) $pushAllowed = false;
    }

    $isAdminTarget = (strpos($type, 'admin') !== false)
                     || (strpos($url, 'admin_panel') !== false);

    if ($pushAllowed) {
        sendPushToUser($conn, $targetUserId, $title, $body, $type, $url, null);
    }
    // ایمیل فقط برای رویدادهای کاربر (نه پیام‌های ادمین)
    if (!$isAdminTarget) {
        sendEmailNotification($conn, $targetUserId, $title, $body);
    }
    return true;
}

function addNotif($conn, $uid, $type, $title, $msg, $relatedId = null) {
    if (function_exists('_avapay_notify_dedup_hit') && _avapay_notify_dedup_hit($conn, (int)$uid, 'db_' . $type, $relatedId)) return;
    $title = $conn->real_escape_string($title);
    $msg   = $conn->real_escape_string($msg);
    $relSQL = $relatedId ? intval($relatedId) : 'NULL';
    $conn->query("INSERT INTO user_notifications (user_id, type, title, message, related_id) VALUES ($uid, '$type', '$title', '$msg', $relSQL)");
}

// ========== USER: Get My Invoices ==========
if ($action === 'get_my_invoices') {
    $result = $conn->query("SELECT * FROM unpaid_invoices WHERE user_id=$userId ORDER BY created_at DESC LIMIT 20");
    $invoices = [];
    while ($row = $result->fetch_assoc()) $invoices[] = $row;
    echo json_encode(['success' => true, 'invoices' => $invoices]);
    exit();
}

// ========== USER: Pay Invoice (Upload Receipt) ==========
if ($action === 'pay_invoice' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $invoiceId = intval($_POST['invoice_id'] ?? 0);
    
    $inv = $conn->query("SELECT * FROM unpaid_invoices WHERE id=$invoiceId AND user_id=$userId")->fetch_assoc();
    if (!$inv || $inv['status'] !== 'approved') {
        echo json_encode(['success' => false, 'message' => 'فیش معتبر نیست']);
        exit();
    }
    
    // ===== پشتیبانی از چند عکس: receipts[] (جدید) یا receipt (قدیمی) =====
    // ستون receipt_image را برای چند مسیر بزرگ می‌کنیم
    @$conn->query("ALTER TABLE unpaid_invoices MODIFY COLUMN receipt_image TEXT DEFAULT NULL");
    $filesInput = [];
    if (!empty($_FILES['receipts']['name'][0])) {
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
        $filesInput[] = $_FILES['receipt'];
    }
    
    if (empty($filesInput)) {
        echo json_encode(['success' => false, 'message' => 'فایلی ارسال نشده']);
        exit();
    }
    if (count($filesInput) > 6) {
        echo json_encode(['success' => false, 'message' => 'حداکثر ۶ عکس مجاز است']);
        exit();
    }
    
    $uploadDir = avapay_upload_dir('invoice_receipts');
    
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $savedPaths = [];
    foreach ($filesInput as $idx => $file) {
        if ($file['error'] !== UPLOAD_ERR_OK) continue;
        if (!in_array($file['type'], $allowed)) {
            echo json_encode(['success' => false, 'message' => 'فرمت فایل مجاز نیست: ' . $file['name']]);
            exit();
        }
        if ($file['size'] > 5 * 1024 * 1024) {
            echo json_encode(['success' => false, 'message' => 'حجم هر فایل حداکثر 5MB: ' . $file['name']]);
            exit();
        }
        $ext     = pathinfo($file['name'], PATHINFO_EXTENSION);
        $newName = 'invoice_' . $invoiceId . '_' . time() . '_' . $idx . '.' . $ext;
        if (move_uploaded_file($file['tmp_name'], $uploadDir . $newName)) {
            $savedPaths[] = '/ledor/uploads/invoice_receipts/' . $newName;
        }
    }
    
    if (empty($savedPaths)) {
        echo json_encode(['success' => false, 'message' => 'آپلود فایل ناموفق بود']);
        exit();
    }
    
    // ذخیره‌ی همه مسیرها (جداشده با کاما) — سازگار با نمایش تک‌عکسی قدیمی
    $joined  = implode(',', $savedPaths);
    $escaped = $conn->real_escape_string($joined);
    $conn->query("UPDATE unpaid_invoices SET receipt_image='$escaped', status='paid' WHERE id=$invoiceId");
    
    // Notify admins
    $admins = $conn->query("SELECT id FROM users WHERE is_admin=1");
    $userInfo = $conn->query("SELECT first_name, last_name FROM users WHERE id=$userId")->fetch_assoc();
    $userName = trim(($userInfo['first_name'] ?? '') . ' ' . ($userInfo['last_name'] ?? ''));
    $imgCount = count($savedPaths);
    while ($admin = $admins->fetch_assoc()) {
        $adminId = $admin['id'];
        addNotif($conn, $adminId, 'invoice_paid', "🧾 فیش پرداخت دریافت شد", "کاربر $userName $imgCount عکس فیش پرداخت #$invoiceId را ارسال کرد.", $invoiceId);
        sendPushNotif($conn, $adminId, "🧾 فیش پرداخت دریافت شد", "کاربر $userName $imgCount عکس فیش پرداخت #$invoiceId را ارسال کرد.", '/ledor/admin_panel.php', 'invoice_paid');
    }
    // مهم: ادمین باید حتماً از طریق ایمیل یا ربات تلگرام هم مطلع شود
    if (function_exists('notifyAdmins')) {
        notifyAdmins($conn, "🧾 فیش پرداخت دریافت شد", "کاربر $userName $imgCount عکس فیش پرداخت #$invoiceId را ارسال کرد.", [
            'type' => 'invoice_paid', 'url' => '/ledor/admin_panel.php', 'related_id' => $invoiceId,
            'db' => false, 'push' => false,
        ]);
    }
    
    echo json_encode(['success' => true, 'image_paths' => $savedPaths]);
    exit();
}

// ========== USER: Get single invoice ==========
if ($action === 'get_invoice') {
    $invoiceId = intval($_GET['id'] ?? 0);
    $inv = $conn->query("SELECT * FROM unpaid_invoices WHERE id=$invoiceId AND user_id=$userId")->fetch_assoc();
    if (!$inv) {
        echo json_encode(['success' => false, 'message' => 'فیش یافت نشد']);
        exit();
    }
    echo json_encode(['success' => true, 'invoice' => $inv]);
    exit();
}

// ========== ADMIN ONLY below ==========
$adminCheck = $conn->query("SELECT is_admin FROM users WHERE id=$userId")->fetch_assoc();
if (!$adminCheck || !$adminCheck['is_admin']) {
    echo json_encode(['success' => false, 'message' => 'Admin only']);
    exit();
}

// ========== ADMIN: Create Invoice for User ==========
if ($action === 'create_invoice' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $targetUserId = intval($data['user_id'] ?? 0);
    $currency     = in_array($data['currency'] ?? '', ['USD','EUR','USDT','IRR']) ? $data['currency'] : 'USD';
    $amount       = floatval($data['amount'] ?? 0);
    $description  = $conn->real_escape_string($data['description'] ?? '');
    $allowedTypes = ['service','subscription','topup','penalty','other'];
    $invType      = in_array($data['type'] ?? '', $allowedTypes) ? $data['type'] : 'other';
    
    if ($targetUserId <= 0 || $amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'اطلاعات نامعتبر']);
        exit();
    }

    // Ensure the invoice_type column exists (auto-migrate)
    $hasType = $conn->query("SHOW COLUMNS FROM unpaid_invoices LIKE 'invoice_type'");
    if (!$hasType || $hasType->num_rows === 0) {
        $conn->query("ALTER TABLE unpaid_invoices ADD COLUMN invoice_type VARCHAR(30) DEFAULT 'other' AFTER description");
    }
    // (جدید) نوع منبع فیش: عمومی یا مربوط به یک معامله‌ی تبادل ارزی —
    // جدا از invoice_type (که یک دسته‌بندی‌ کسب‌وکاریِ دیگر است)
    $hasSourceType = $conn->query("SHOW COLUMNS FROM unpaid_invoices LIKE 'source_type'");
    if (!$hasSourceType || $hasSourceType->num_rows === 0) {
        $conn->query("ALTER TABLE unpaid_invoices ADD COLUMN source_type VARCHAR(10) DEFAULT 'general' AFTER invoice_type");
    }
    $sourceType = ($data['source_type'] ?? '') === 'exchange' ? 'exchange' : 'general';
    
    // اطلاعات پرداخت (اختیاری). اگر ادمین همین‌جا شماره‌حساب/کارت بدهد،
    // صورت‌حساب مستقیماً «آماده پرداخت» می‌شود و نیاز به تایید مجدد ندارد.
    $bankName      = $conn->real_escape_string($data['bank_name'] ?? '');
    $accountNumber = $conn->real_escape_string($data['account_number'] ?? '');
    $cardNumber    = $conn->real_escape_string($data['card_number'] ?? '');
    $recipientName = $conn->real_escape_string($data['recipient_name'] ?? '');
    $ibanNumber    = $conn->real_escape_string($data['iban'] ?? '');

    $hasPayInfo = ($bankName !== '' || $accountNumber !== '' || $cardNumber !== '' || $ibanNumber !== '');
    $newStatus  = $hasPayInfo ? 'approved' : 'pending';

    // (جدید) پیوند به یک معامله‌ی تبادل ارزی خاص — تا کاربر بتواند مستقیم از
    // صفحه‌ی همان معامله (نه فقط داشبورد) پرداخت و فیش را آپلود کند
    $hasDealCols = $conn->query("SHOW COLUMNS FROM unpaid_invoices LIKE 'deal_id'");
    if (!$hasDealCols || $hasDealCols->num_rows === 0) {
        @$conn->query("ALTER TABLE unpaid_invoices ADD COLUMN deal_id INT DEFAULT NULL");
        @$conn->query("ALTER TABLE unpaid_invoices ADD COLUMN deal_side VARCHAR(10) DEFAULT NULL");
    }
    $dealId   = !empty($data['deal_id']) ? intval($data['deal_id']) : null;
    $dealSide = in_array(($data['deal_side'] ?? ''), ['buyer','seller'], true) ? $data['deal_side'] : null;
    $dealIdSql   = $dealId ? $dealId : 'NULL';
    $dealSideSql = $dealSide ? "'$dealSide'" : 'NULL';

    $conn->query("INSERT INTO unpaid_invoices (user_id, currency, amount, description, invoice_type, source_type, status, admin_id, bank_name, account_number, card_number, recipient_name, iban, deal_id, deal_side) 
                  VALUES ($targetUserId, '$currency', $amount, '$description', '$invType', '$sourceType', '$newStatus', $userId, '$bankName', '$accountNumber', '$cardNumber', '$recipientName', '$ibanNumber', $dealIdSql, $dealSideSql)");
    $invoiceId = $conn->insert_id;
    
    // Notify user
    $userInfo = $conn->query("SELECT first_name, last_name FROM users WHERE id=$targetUserId")->fetch_assoc();
    $userName = trim(($userInfo['first_name'] ?? '') . ' ' . ($userInfo['last_name'] ?? ''));
    addNotif($conn, $targetUserId, 'invoice_created', "📄 فیش جدید برای شما صادر شد", "مبلغ $amount $currency - $description", $invoiceId);
    sendPushNotif($conn, $targetUserId, "📄 فیش جدید", "مبلغ $amount $currency برای شما صادر شد.", '/ledor/arad.php', 'invoice_created');
    
    echo json_encode(['success' => true, 'invoice_id' => $invoiceId]);
    exit();
}

// ========== ADMIN: Approve Invoice (send bank info) ==========
if ($action === 'approve_invoice' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $invoiceId     = intval($data['invoice_id'] ?? 0);
    $bankName      = $conn->real_escape_string($data['bank_name'] ?? '');
    $accountNumber = $conn->real_escape_string($data['account_number'] ?? '');
    $cardNumber    = $conn->real_escape_string($data['card_number'] ?? '');
    $recipientName = $conn->real_escape_string($data['recipient_name'] ?? '');
    $iban          = $conn->real_escape_string($data['iban'] ?? '');
    
    $inv = $conn->query("SELECT * FROM unpaid_invoices WHERE id=$invoiceId")->fetch_assoc();
    if (!$inv) {
        echo json_encode(['success' => false, 'message' => 'فیش یافت نشد']);
        exit();
    }
    
    $conn->query("UPDATE unpaid_invoices SET 
        status='approved',
        bank_name='$bankName',
        account_number='$accountNumber',
        card_number='$cardNumber',
        recipient_name='$recipientName',
        iban='$iban',
        admin_id=$userId
        WHERE id=$invoiceId");
    
    $targetUserId = $inv['user_id'];
    addNotif($conn, $targetUserId, 'invoice_approved', "✅ فیش شما تأیید شد", "اطلاعات پرداخت برای فیش #$invoiceId ارسال شد.", $invoiceId);
    sendPushNotif($conn, $targetUserId, "✅ فیش تأیید شد", "اطلاعات پرداخت را مشاهده کنید.", '/ledor/arad.php', 'invoice_approved');
    
    echo json_encode(['success' => true]);
    exit();
}

// ========== ADMIN: Reject Invoice ==========
if ($action === 'reject_invoice' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $invoiceId = intval($data['invoice_id'] ?? 0);
    $reason    = $conn->real_escape_string($data['reason'] ?? '');
    
    $inv = $conn->query("SELECT * FROM unpaid_invoices WHERE id=$invoiceId")->fetch_assoc();
    if (!$inv) {
        echo json_encode(['success' => false, 'message' => 'فیش یافت نشد']);
        exit();
    }
    
    $conn->query("UPDATE unpaid_invoices SET status='rejected', reject_reason='$reason', admin_id=$userId WHERE id=$invoiceId");
    
    $targetUserId = $inv['user_id'];
    addNotif($conn, $targetUserId, 'invoice_rejected', "❌ فیش شما رد شد", "دلیل: $reason", $invoiceId);
    sendPushNotif($conn, $targetUserId, "❌ فیش رد شد", "دلیل: $reason", '/ledor/arad.php', 'invoice_rejected');
    
    echo json_encode(['success' => true]);
    exit();
}

// ========== ADMIN: Finalize Invoice (send final note/image) ==========
if ($action === 'finalize_invoice' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $invoiceId = intval($data['invoice_id'] ?? 0);
    $finalNote = $conn->real_escape_string($data['final_note'] ?? '');
    $finalImage = isset($data['final_image']) ? $conn->real_escape_string($data['final_image']) : null;
    
    $inv = $conn->query("SELECT * FROM unpaid_invoices WHERE id=$invoiceId")->fetch_assoc();
    if (!$inv || $inv['status'] !== 'paid') {
        echo json_encode(['success' => false, 'message' => 'وضعیت نامعتبر']);
        exit();
    }
    
    $sql = "UPDATE unpaid_invoices SET status='finalized', admin_final_note='$finalNote'";
    if ($finalImage) {
        $sql .= ", admin_final_image='$finalImage'";
    }
    $sql .= " WHERE id=$invoiceId";
    $conn->query($sql);
    
    // (جدید) فیش‌های نوع «تبادل ارزی» یک واریزِ واقعی بین دو کاربر خارج از
    // کیف‌پول اپ هستند (کاربر B مستقیم به حساب بانکیِ کاربر A پول واریز
    // می‌کند) — پس نباید موجودیِ کیف‌پولِ کاربرِ گیرنده‌ی این فیش (که همان
    // پرداخت‌کننده/کاربر B است) داخل اپ افزایش پیدا کند؛ فقط وضعیت «تکمیل‌شده»
    // ثبت می‌شود.
    if (($inv['source_type'] ?? 'general') === 'exchange') {
        addNotif($conn, $inv['user_id'], 'invoice_finalized', "🏆 تسویه‌ی معامله تکمیل شد", "تسویه‌ی معامله‌ی تبادل ارزی #$invoiceId تکمیل شد.", $invoiceId);
        sendPushNotif($conn, $inv['user_id'], "🏆 تسویه تکمیل شد", "تسویه‌ی معامله‌ی تبادل ارزی شما تکمیل شد.", '/ledor/arad.php', 'invoice_finalized');
        echo json_encode(['success' => true]);
        exit();
    }

    // Add balance to user
    $targetUserId = $inv['user_id'];
    $amount       = floatval($inv['amount']);
    $currency     = $inv['currency'];
    $balanceField = 'balance_' . strtolower($currency);
    $conn->query("UPDATE users SET $balanceField = $balanceField + $amount WHERE id=$targetUserId");
    
    // Record transaction
    $txId = 'INV' . time() . rand(1000, 9999);
    $desc = $conn->real_escape_string("[Invoice] تکمیل فیش #$invoiceId - $amount $currency");
    $conn->query("INSERT INTO transactions (transaction_id, sender_id, receiver_id, amount, currency, type, description, status, admin_id)
        VALUES ('$txId', $userId, $targetUserId, $amount, '$currency', 'deposit', '$desc', 'completed', $userId)");
    
    // Notify user
    addNotif($conn, $targetUserId, 'invoice_finalized', "🏆 فیش شما تکمیل شد", "مبلغ $amount $currency به حساب شما اضافه شد.", $invoiceId);
    sendPushNotif($conn, $targetUserId, "🏆 فیش تکمیل شد", "مبلغ $amount $currency به حساب شما اضافه شد.", '/ledor/arad.php', 'invoice_finalized');
    
    echo json_encode(['success' => true]);
    exit();
}

// ========== ADMIN: Get All Invoices ==========
if ($action === 'admin_get_invoices') {
    $status = $conn->real_escape_string($_GET['status'] ?? '');
    $sourceTypeFilter = $conn->real_escape_string($_GET['source_type'] ?? '');
    $conds = [];
    if ($status !== '') $conds[] = "i.status='$status'";
    if ($sourceTypeFilter !== '') $conds[] = "i.source_type='$sourceTypeFilter'";
    $where = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';
    $result = $conn->query("
        SELECT i.*, u.first_name, u.last_name, u.phone_number, u.email
        FROM unpaid_invoices i
        JOIN users u ON i.user_id = u.id
        $where
        ORDER BY i.created_at DESC
        LIMIT 100
    ");
    $invoices = [];
    while ($row = $result->fetch_assoc()) $invoices[] = $row;
    echo json_encode(['success' => true, 'invoices' => $invoices]);
    exit();
}

// ========== ADMIN: Upload final image ==========
if ($action === 'upload_final_image' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $uploadDir = '../uploads/invoice_final/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
    
    if (!isset($_FILES['final_image'])) {
        echo json_encode(['success' => false, 'message' => 'No file']);
        exit();
    }
    
    $file = $_FILES['final_image'];
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($file['type'], $allowed)) {
        echo json_encode(['success' => false, 'message' => 'Invalid type']);
        exit();
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'File too large']);
        exit();
    }
    
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $newName = 'final_' . uniqid() . '.' . $ext;
    $path = $uploadDir . $newName;
    
    if (move_uploaded_file($file['tmp_name'], $path)) {
        echo json_encode(['success' => true, 'url' => '/ledor/uploads/invoice_final/' . $newName]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Upload failed']);
    }
    exit();
}

// ========== ADMIN: Get user balance ==========
if ($action === 'get_user_balance') {
    $targetId = intval($_GET['user_id'] ?? 0);
    if ($targetId <= 0) {
        echo json_encode(['success' => false]);
        exit();
    }
    $u = $conn->query("SELECT balance_usd, balance_eur, balance_usdt, balance_irr, first_name, last_name FROM users WHERE id=$targetId")->fetch_assoc();
    if (!$u) {
        echo json_encode(['success' => false]);
        exit();
    }
    echo json_encode([
        'success' => true,
        'balances' => [
            'USD' => floatval($u['balance_usd']),
            'EUR' => floatval($u['balance_eur']),
            'USDT' => floatval($u['balance_usdt']),
            'IRR' => floatval($u['balance_irr'])
        ],
        'name' => $u['first_name'] . ' ' . $u['last_name']
    ]);
    exit();
}

// ========== ADMIN: Get all users ==========
if ($action === 'get_all_users') {
    $result = $conn->query("SELECT id, first_name, last_name, telegram_id FROM users ORDER BY first_name");
    $users = [];
    while ($row = $result->fetch_assoc()) $users[] = $row;
    echo json_encode(['success' => true, 'users' => $users]);
    exit();
}

// ========== ADMIN: حذف یک فیش از لیست ==========
if ($action === 'admin_delete_invoice' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $invId = intval($data['invoice_id'] ?? 0);
    if ($invId <= 0) { echo json_encode(['success' => false, 'message' => 'شناسه نامعتبر']); exit(); }
    $stmt = $conn->prepare("DELETE FROM unpaid_invoices WHERE id = ?");
    $stmt->bind_param("i", $invId);
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'خطا در حذف']);
    }
    exit();
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
?>