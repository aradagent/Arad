<?php
// api/withdraw.php
// سیستم کامل برداشت و مدیریت آن توسط ادمین با قابلیت آپلود فیش و ذخیره گیرنده

// ============================================
// 1. ERROR REPORTING - غیرفعال برای خروجی JSON
// ============================================
error_reporting(0);
ini_set('display_errors', 0);

// ============================================
// 2. START SESSION
// ============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================
// 3. SET JSON HEADER
// ============================================
header('Content-Type: application/json; charset=utf-8');

// ============================================
// 4. REQUIRE CONFIG
// ============================================
require_once dirname(__DIR__) . '/config/database.php';

// ============================================
// 5. TELEGRAM BOT CONFIG
// ============================================
define('TELEGRAM_BOT_TOKEN', '5937135973:AAEwK4lxar3xRM_mwvapLWNuw26VUv2c6e4');

// ============================================
// 6. FUNCTION: ارسال پیام به تلگرام
// ============================================
if (!function_exists('sendTelegramMessage')) {
    function sendTelegramMessage($telegramId, $message) {
        if (empty($telegramId) || TELEGRAM_BOT_TOKEN === 'YOUR_BOT_TOKEN_HERE') {
            return false;
        }
        
        $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendMessage";
        
        $data = [
            'chat_id' => $telegramId,
            'text' => $message,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true
        ];
        
        $options = [
            'http' => [
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'method' => 'POST',
                'content' => http_build_query($data),
                'timeout' => 10
            ]
        ];
        
        $context = stream_context_create($options);
        $result = file_get_contents($url, false, $context);
        
        return json_decode($result, true);
    }
}

// ============================================
// 7. CHECK DATABASE CONNECTION
// ============================================
if (!$conn || $conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// ============================================
// 8. FUNCTION: CHECK ADMIN
// ============================================
if (!function_exists('isAdmin')) {
    function isAdmin($conn, $userId) {
        $ADMIN_TELEGRAM_ID = '5330629504';
        $sql = "SELECT is_admin, telegram_id FROM users WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return false;
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            if ($user['telegram_id'] == $ADMIN_TELEGRAM_ID || $user['is_admin'] == 1) {
                return true;
            }
        }
        return false;
    }
}

// ============================================
// 9. INITIALIZE RESPONSE
// ============================================
$response = [
    'success' => false,
    'message' => '',
    'data' => []
];

// ============================================
// 10. GET ACTION
// ============================================
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ============================================
// 11. CHECK AUTHENTICATION
// ============================================
$isAuthenticated = isset($_SESSION['user_id']);
$userId = $isAuthenticated ? $_SESSION['user_id'] : null;

// ============================================
// 12. CREATE TABLES IF NOT EXISTS
// ============================================
$conn->query("CREATE TABLE IF NOT EXISTS `withdrawal_requests` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `amount` DECIMAL(20,2) NOT NULL,
    `currency` VARCHAR(10) NOT NULL,
    `iban_number` VARCHAR(50),
    `card_number` VARCHAR(24),
    `bank_name` VARCHAR(100),
    `recipient_name` VARCHAR(200),
    `target_user_id` INT NULL,
    `target_user_name` VARCHAR(200) NULL,
    `notes` TEXT,
    `status` ENUM('pending', 'approved', 'completed', 'rejected') DEFAULT 'pending',
    `admin_id` INT DEFAULT NULL,
    `admin_notes` TEXT,
    `receipt_file` VARCHAR(500),
    `receipt_uploaded_at` DATETIME DEFAULT NULL,
    `admin_action_at` DATETIME DEFAULT NULL,
    `completed_at` DATETIME DEFAULT NULL,
    `card_id` INT DEFAULT NULL,
    `transaction_id` VARCHAR(100) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_target_user (target_user_id)
)");

// اطمینان از وجود ستون‌های جدید
// اصلاح سرعت: قبلاً این ۴ کوئری DDL روی جدول withdrawal_requests برای *هر*
// اکشن (حتی get_pending/get_user_requests که مکرراً صدا زده می‌شوند) اجرا
// می‌شدند؛ حالا throttle شده‌اند.
require_once dirname(__DIR__) . '/includes/perf_helpers.php';
if (avapay_throttled('withdraw_schema_ensure', 1800)) {
    $conn->query("ALTER TABLE `withdrawal_requests` ADD COLUMN IF NOT EXISTS `target_user_id` INT NULL");
    $conn->query("ALTER TABLE `withdrawal_requests` ADD COLUMN IF NOT EXISTS `target_user_name` VARCHAR(200) NULL");
    $conn->query("ALTER TABLE `withdrawal_requests` ADD COLUMN IF NOT EXISTS `completed_at` DATETIME NULL");
    $conn->query("ALTER TABLE `withdrawal_requests` ADD INDEX IF NOT EXISTS `idx_target_user` (`target_user_id`)");
}

// ============================================
// 13. MAIN SWITCH
// ============================================
try {
    
    // CASE 1: USER SUBMITS WITHDRAWAL REQUEST
    if ($action === 'submit' && $method === 'POST') {
        
        if (!$isAuthenticated) {
            throw new Exception('لطفاً وارد شوید');
        }

        $__banChk = $conn->query("SHOW COLUMNS FROM users LIKE 'is_banned'");
        if ($__banChk && $__banChk->num_rows > 0) {
            $__banRow = $conn->query("SELECT is_banned, ban_reason FROM users WHERE id = " . (int)$userId)->fetch_assoc();
            if (!empty($__banRow['is_banned'])) {
                throw new Exception('حساب شما مسدود شده و امکان ثبت درخواست برداشت ندارید' . (!empty($__banRow['ban_reason']) ? ' (' . $__banRow['ban_reason'] . ')' : ''));
            }
        }
        
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (!$data) {
            throw new Exception('داده ارسال نشده است');
        }
        
        $amount = floatval($data['amount'] ?? 0);
        $currency = $data['currency'] ?? '';
        $ibanNumber = $data['iban_number'] ?? '';
        $cardNumber = $data['card_number'] ?? '';
        $bankName = $data['bank_name'] ?? '';
        $recipientName = $data['recipient_name'] ?? '';
        $targetUserId = intval($data['target_user_id'] ?? 0);
        $targetUserName = $data['target_user_name'] ?? '';
        $notes = $data['notes'] ?? '';
        
        if ($amount <= 0) {
            throw new Exception('مبلغ باید بیشتر از صفر باشد');
        }
        
        $validCurrencies = ['USD', 'EUR', 'USDT', 'IRR'];
        if (!in_array($currency, $validCurrencies)) {
            throw new Exception('ارز نامعتبر است');
        }
        
        if (empty($ibanNumber) || strlen($ibanNumber) < 10) {
            throw new Exception('شماره شبا نامعتبر است');
        }
        
        if (empty($bankName)) {
            throw new Exception('نام بانک الزامی است');
        }
        
        if (empty($recipientName)) {
            throw new Exception('نام گیرنده الزامی است');
        }
        
        $balanceField = 'balance_' . strtolower($currency);
        
        $checkColumn = $conn->query("SHOW COLUMNS FROM users LIKE '$balanceField'");
        if ($checkColumn && $checkColumn->num_rows == 0) {
            $conn->query("ALTER TABLE users ADD COLUMN $balanceField DECIMAL(20,2) DEFAULT 0");
        }
        
        $sql = "SELECT id, $balanceField, first_name, last_name, telegram_id FROM users WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('خطا در دیتابیس: ' . $conn->error);
        }
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows !== 1) {
            throw new Exception('کاربر یافت نشد');
        }
        
        $user = $result->fetch_assoc();
        $balance = floatval($user[$balanceField] ?? 0);
        
        if ($balance < $amount) {
            throw new Exception('موجودی ناکافی. موجودی: ' . number_format($balance) . ' ' . $currency);
        }
        
        $withdrawalSql = "INSERT INTO withdrawal_requests (
            user_id, amount, currency, iban_number, card_number, 
            bank_name, recipient_name, target_user_id, target_user_name, notes, status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())";
        
        $withdrawalStmt = $conn->prepare($withdrawalSql);
        if (!$withdrawalStmt) {
            throw new Exception('خطا در ثبت درخواست: ' . $conn->error);
        }
        
        if ($targetUserId === 0 && !empty($targetUserName)) {
            $targetUserId = null;
        }
        
        $withdrawalStmt->bind_param(
            "idsssssiss",
            $userId, $amount, $currency, $ibanNumber, 
            $cardNumber, $bankName, $recipientName, 
            $targetUserId, $targetUserName, $notes
        );
        
        if (!$withdrawalStmt->execute()) {
            throw new Exception('خطا در ثبت درخواست: ' . $conn->error);
        }
        
        $withdrawalId = $withdrawalStmt->insert_id;
        
        // ارسال نوتیفیکیشن به کاربر
        $currencyName = $currency === 'IRR' ? 'تومان' : $currency;
        $formattedAmount = number_format($amount, 0);
        
        $telegramMessage = "🏦 <b>درخواست تسویه حساب ثبت شد</b>\n\n";
        $telegramMessage .= "👤 نام: {$user['first_name']} {$user['last_name']}\n";
        $telegramMessage .= "💰 مبلغ: {$formattedAmount} {$currencyName}\n";
        $telegramMessage .= "🏦 بانک: {$bankName}\n";
        $telegramMessage .= "👤 گیرنده: {$recipientName}\n";
        $telegramMessage .= "📅 تاریخ: " . date('Y/m/d H:i') . "\n\n";
        $telegramMessage .= "✅ درخواست شما با موفقیت ثبت شد.\n";
        $telegramMessage .= "⏳ پس از تایید ادمین، مبلغ به حساب شما واریز می‌شود.\n\n";
        $telegramMessage .= "🆔 شماره درخواست: {$withdrawalId}";
        
        sendTelegramMessage($user['telegram_id'], $telegramMessage);
        
        // ارسال به ادمین
        $adminTelegramId = '5330629504';
        $adminMessage = "🆕 <b>درخواست تسویه جدید</b>\n\n";
        $adminMessage .= "👤 کاربر: {$user['first_name']} {$user['last_name']}\n";
        $adminMessage .= "🆔 آیدی کاربر: {$userId}\n";
        $adminMessage .= "💰 مبلغ: {$formattedAmount} {$currencyName}\n";
        $adminMessage .= "🏦 بانک: {$bankName}\n";
        $adminMessage .= "👤 گیرنده: {$recipientName}\n";
        if ($ibanNumber) $adminMessage .= "🔢 شبا: {$ibanNumber}\n";
        if ($cardNumber) $adminMessage .= "💳 کارت: {$cardNumber}\n";
        $adminMessage .= "📅 تاریخ: " . date('Y/m/d H:i') . "\n\n";
        $adminMessage .= "🔗 برای بررسی به پنل ادمین مراجعه کنید.";
        
        sendTelegramMessage($adminTelegramId, $adminMessage);
        
        $response['success'] = true;
        $response['message'] = '✅ درخواست تسویه با موفقیت ثبت شد. پس از تایید ادمین، مبلغ به حساب شما واریز خواهد شد.';
        $response['data'] = [
            'withdrawal_id' => $withdrawalId,
            'user_balance' => $balance
        ];
        
    // CASE 2: GET USER REQUESTS
    } elseif ($action === 'get_user_requests' && $method === 'GET') {
        
        if (!$isAuthenticated) {
            throw new Exception('لطفاً وارد شوید');
        }
        
        $sql = "SELECT * FROM withdrawal_requests WHERE user_id = ? ORDER BY created_at DESC";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('خطا در دیتابیس: ' . $conn->error);
        }
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $withdrawals = [];
        while ($row = $result->fetch_assoc()) {
            $withdrawals[] = $row;
        }
        
        $response['success'] = true;
        $response['data'] = $withdrawals;
        
    // CASE 3: GET PENDING WITHDRAWALS (ADMIN)
    } elseif ($action === 'get_pending' && $method === 'GET') {
        
        if (!$isAuthenticated || !isAdmin($conn, $userId)) {
            throw new Exception('دسترسی غیرمجاز. فقط ادمین');
        }
        
        $sql = "SELECT wr.*, u.first_name, u.last_name, u.telegram_id
                FROM withdrawal_requests wr
                JOIN users u ON wr.user_id = u.id
                WHERE wr.status = 'pending'
                ORDER BY wr.created_at DESC";
        
        $result = $conn->query($sql);
        $withdrawals = [];
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $withdrawals[] = $row;
            }
        }
        
        $response['success'] = true;
        $response['data'] = $withdrawals;
        
    // CASE 4: GET APPROVED WITHDRAWALS (ADMIN) - منتظر آپلود فیش
    } elseif ($action === 'get_approved' && $method === 'GET') {
        
        if (!$isAuthenticated || !isAdmin($conn, $userId)) {
            throw new Exception('دسترسی غیرمجاز. فقط ادمین');
        }
        
        $sql = "SELECT wr.*, u.first_name, u.last_name, u.telegram_id
                FROM withdrawal_requests wr
                JOIN users u ON wr.user_id = u.id
                WHERE wr.status = 'approved'
                ORDER BY wr.admin_action_at DESC";
        
        $result = $conn->query($sql);
        $withdrawals = [];
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $withdrawals[] = $row;
            }
        }
        
        $response['success'] = true;
        $response['data'] = $withdrawals;
        
    // CASE 5: GET COMPLETED WITHDRAWALS (ADMIN)
    } elseif ($action === 'get_completed' && $method === 'GET') {
        
        if (!$isAuthenticated || !isAdmin($conn, $userId)) {
            throw new Exception('دسترسی غیرمجاز. فقط ادمین');
        }

        // اطمینان از وجود ستون archived
        $chkArch = $conn->query("SHOW COLUMNS FROM withdrawal_requests LIKE 'archived'");
        if ($chkArch && $chkArch->num_rows == 0) {
            $conn->query("ALTER TABLE withdrawal_requests ADD COLUMN archived TINYINT(1) NOT NULL DEFAULT 0");
        }

        $onlyArchived = (($_GET['view'] ?? '') === 'archived');
        $archWhere = $onlyArchived ? "wr.archived = 1" : "wr.archived = 0";

        $sql = "SELECT wr.*, u.first_name, u.last_name, u.telegram_id
                FROM withdrawal_requests wr
                JOIN users u ON wr.user_id = u.id
                WHERE wr.status IN ('completed', 'rejected') AND $archWhere
                ORDER BY wr.admin_action_at DESC
                LIMIT 100";
        
        $result = $conn->query($sql);
        $withdrawals = [];
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $withdrawals[] = $row;
            }
        }
        
        $response['success'] = true;
        $response['data'] = $withdrawals;
        
    // CASE 6: ADMIN APPROVES WITHDRAWAL (مرحله اول - تایید)
    } elseif ($action === 'approve' && $method === 'POST') {
        
        if (!$isAuthenticated || !isAdmin($conn, $userId)) {
            throw new Exception('دسترسی غیرمجاز. فقط ادمین');
        }
        
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (!$data || empty($data['withdrawal_id'])) {
            throw new Exception('شناسه درخواست الزامی است');
        }
        
        $withdrawalId = intval($data['withdrawal_id']);
        $notes = $data['notes'] ?? 'تایید شده توسط ادمین';
        
        $updateWithdrawalSql = "UPDATE withdrawal_requests 
                               SET status = 'approved', 
                                   admin_id = ?, 
                                   admin_notes = ?,
                                   admin_action_at = NOW()
                               WHERE id = ? AND status = 'pending'";
        
        $updateWithdrawalStmt = $conn->prepare($updateWithdrawalSql);
        $updateWithdrawalStmt->bind_param("isi", $userId, $notes, $withdrawalId);
        
        if (!$updateWithdrawalStmt->execute()) {
            throw new Exception('خطا در به‌روزرسانی وضعیت درخواست');
        }
        
        if ($updateWithdrawalStmt->affected_rows === 0) {
            throw new Exception('درخواست یافت نشد یا قبلاً پردازش شده است');
        }
        
        // توجه: این مرحله فقط تایید داخلی شماره‌حساب/درخواست توسط ادمین است
        // (مرحله اول)، نه واریز واقعی وجه. طبق درخواست صریح کاربر، در این
        // مرحله هیچ پیامی (نه تلگرام، نه ایمیل) نباید به کاربر ارسال شود؛
        // اطلاع‌رسانی واقعی وقتی اتفاق می‌افتد که فیش نهایی/تکمیل ارسال شود.
        $response['success'] = true;
        $response['message'] = "✅ درخواست تایید شد. اکنون می‌توانید فیش پرداخت را آپلود کنید.";
        $response['data'] = [
            'withdrawal_id' => $withdrawalId,
            'action' => 'approved'
        ];
        
    // CASE 7: UPLOAD RECEIPT AND COMPLETE (مرحله دوم - آپلود فیش و تکمیل)
    } elseif ($action === 'upload_receipt' && $method === 'POST') {
        
        if (!$isAuthenticated || !isAdmin($conn, $userId)) {
            throw new Exception('دسترسی غیرمجاز. فقط ادمین');
        }
        
        if (!isset($_POST['withdrawal_id'])) {
            throw new Exception('شناسه درخواست الزامی است');
        }
        
        $withdrawalId = intval($_POST['withdrawal_id']);
        $notes = $_POST['notes'] ?? '';
        $cardId = isset($_POST['card_id']) && $_POST['card_id'] !== '' ? intval($_POST['card_id']) : null;
        $transactionId = $_POST['transaction_id'] ?? '';
        
        // دریافت اطلاعات درخواست - فقط درخواست‌هایی که وضعیت 'approved' دارند
        $getWithdrawalSql = "SELECT wr.*, u.first_name, u.last_name, u.telegram_id, 
                                    u.balance_usd, u.balance_eur, u.balance_usdt, u.balance_irr
                             FROM withdrawal_requests wr
                             JOIN users u ON wr.user_id = u.id
                             WHERE wr.id = ? AND wr.status = 'approved'";
        $getStmt = $conn->prepare($getWithdrawalSql);
        $getStmt->bind_param("i", $withdrawalId);
        $getStmt->execute();
        $withdrawalData = $getStmt->get_result()->fetch_assoc();
        
        if (!$withdrawalData) {
            throw new Exception('درخواست یافت نشد یا در وضعیت مناسب نیست (وضعیت باید approved باشد)');
        }
        
        // آپلود فایل‌ها (پشتیبانی از چند فیش هم‌زمان + سازگاری با نسخهٔ قبلی تک‌فایلی)
        $uploadDir = avapay_upload_dir('receipts');

        // ستون جدید برای نگهداری چند فیش (JSON) - خوددرمان برای دیتابیس‌های قدیمی
        $chkMultiCol = $conn->query("SHOW COLUMNS FROM withdrawal_requests LIKE 'receipt_files'");
        if ($chkMultiCol && $chkMultiCol->num_rows == 0) {
            $conn->query("ALTER TABLE withdrawal_requests ADD COLUMN receipt_files TEXT NULL");
        }

        $webPath = null;      // اولین فیش (برای سازگاری با کدهای قبلی که فقط receipt_file را می‌خوانند)
        $allPaths = [];

        $filesToProcess = [];
        if (isset($_FILES['receipts']) && is_array($_FILES['receipts']['name'])) {
            // چند فایل ارسال‌شده با کلید receipts[]
            foreach ($_FILES['receipts']['name'] as $i => $name) {
                if ($_FILES['receipts']['error'][$i] === UPLOAD_ERR_OK && $_FILES['receipts']['size'][$i] > 0) {
                    $filesToProcess[] = [
                        'name'     => $name,
                        'tmp_name' => $_FILES['receipts']['tmp_name'][$i],
                        'size'     => $_FILES['receipts']['size'][$i],
                    ];
                }
            }
        } elseif (isset($_FILES['receipt']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK && $_FILES['receipt']['size'] > 0) {
            // سازگاری با نسخهٔ قدیمی (تک فایل با کلید receipt)
            $filesToProcess[] = $_FILES['receipt'];
        }

        foreach ($filesToProcess as $idx => $file) {
            $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($fileExt, ['jpg', 'jpeg', 'png', 'pdf'])) {
                throw new Exception('نوع فایل نامعتبر است. فقط JPG, PNG, PDF');
            }
            if ($file['size'] > 5 * 1024 * 1024) {
                throw new Exception('حجم یکی از فایل‌ها بیش از حد مجاز است. حداکثر 5MB.');
            }
            $fileName = 'receipt_' . $withdrawalId . '_' . time() . '_' . $idx . '.' . $fileExt;
            $filePath = $uploadDir . $fileName;
            $path = 'uploads/receipts/' . $fileName;
            if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                throw new Exception('خطا در ذخیره یکی از فایل‌ها');
            }
            $allPaths[] = $path;
        }
        if (!empty($allPaths)) {
            $webPath = $allPaths[0];
        }
        $receiptFilesJson = !empty($allPaths) ? json_encode($allPaths, JSON_UNESCAPED_UNICODE) : null;
        
        $conn->begin_transaction();
        
        try {
            // کم کردن موجودی کاربر
            $balanceField = 'balance_' . strtolower($withdrawalData['currency']);
            $currentBalance = floatval($withdrawalData[$balanceField] ?? 0);
            $amount = floatval($withdrawalData['amount']);
            $newBalance = $currentBalance - $amount;
            
            if ($currentBalance < $amount) {
                throw new Exception("موجودی کاربر ناکافی است. موجودی: {$currentBalance}");
            }
            
            $updateBalanceSql = "UPDATE users SET $balanceField = ? WHERE id = ?";
            $updateBalanceStmt = $conn->prepare($updateBalanceSql);
            $updateBalanceStmt->bind_param("di", $newBalance, $withdrawalData['user_id']);
            
            if (!$updateBalanceStmt->execute()) {
                throw new Exception('خطا در به‌روزرسانی موجودی کاربر');
            }
            
            // بروزرسانی درخواست - مقدار status به درستی 'completed' قرار می‌گیرد
            if ($webPath) {
                $updateSql = "UPDATE withdrawal_requests 
                              SET status = 'completed',
                                  receipt_file = ?, 
                                  receipt_files = ?,
                                  card_id = ?,
                                  transaction_id = ?,
                                  admin_notes = CONCAT(IFNULL(admin_notes, ''), ?),
                                  receipt_uploaded_at = NOW(),
                                  completed_at = NOW()
                              WHERE id = ?";
                $updateStmt = $conn->prepare($updateSql);
                $notesWithDate = "\n[فیش آپلود شد در " . date('Y/m/d H:i') . "] " . $notes;
                $updateStmt->bind_param("ssisis", $webPath, $receiptFilesJson, $cardId, $transactionId, $notesWithDate, $withdrawalId);
            } else {
                $updateSql = "UPDATE withdrawal_requests 
                              SET status = 'completed',
                                  card_id = ?,
                                  transaction_id = ?,
                                  admin_notes = CONCAT(IFNULL(admin_notes, ''), ?),
                                  receipt_uploaded_at = NOW(),
                                  completed_at = NOW()
                              WHERE id = ?";
                $updateStmt = $conn->prepare($updateSql);
                $notesWithDate = "\n[اطلاعات کارت و تراکنش ثبت شد در " . date('Y/m/d H:i') . "] " . $notes;
                $updateStmt->bind_param("issi", $cardId, $transactionId, $notesWithDate, $withdrawalId);
            }
            
            if (!$updateStmt->execute()) {
                throw new Exception('خطا در ثبت اطلاعات: ' . $conn->error);
            }
            
            $conn->commit();
            
            // ارسال نوتیفیکیشن به کاربر
            $currencyName = $withdrawalData['currency'] === 'IRR' ? 'تومان' : $withdrawalData['currency'];
            $formattedAmount = number_format($withdrawalData['amount'], 0);
            $recipientName = $withdrawalData['recipient_name'];
            
            $userMessage = "📄 <b>فیش پرداخت تسویه حساب آپلود شد</b>\n\n";
            $userMessage .= "💰 مبلغ: {$formattedAmount} {$currencyName}\n";
            $userMessage .= "👤 گیرنده: {$recipientName}\n";
            $userMessage .= "🆔 شماره درخواست: {$withdrawalId}\n";
            if ($transactionId) {
                $userMessage .= "🆔 شماره پیگیری/تراکنش: {$transactionId}\n";
            }
            $userMessage .= "\n✅ درخواست شما تکمیل شد. مبلغ از حساب شما کسر و به حساب بانکی گیرنده واریز گردید.\n";
            $userMessage .= "📎 برای مشاهده فیش به پنل کاربری خود مراجعه کنید.";
            
            sendTelegramMessage($withdrawalData['telegram_id'], $userMessage);
            
            $response['success'] = true;
            $response['message'] = '✅ فیش آپلود شد و درخواست تکمیل گردید. موجودی کاربر به‌روزرسانی شد.';
            $response['data'] = [
                'withdrawal_id' => $withdrawalId,
                'new_balance' => $newBalance
            ];
            
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
        
    // CASE 8: ADMIN REJECTS WITHDRAWAL
    } elseif ($action === 'reject' && $method === 'POST') {
        
        if (!$isAuthenticated || !isAdmin($conn, $userId)) {
            throw new Exception('دسترسی غیرمجاز. فقط ادمین');
        }
        
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (!$data || empty($data['withdrawal_id'])) {
            throw new Exception('شناسه درخواست الزامی است');
        }
        
        $withdrawalId = intval($data['withdrawal_id']);
        $reason = $data['notes'] ?? '';
        
        if (empty($reason)) {
            throw new Exception('لطفاً دلیل رد درخواست را وارد کنید');
        }
        
        // دریافت اطلاعات درخواست
        $getWithdrawalSql = "SELECT wr.*, u.first_name, u.last_name, u.telegram_id 
                             FROM withdrawal_requests wr
                             JOIN users u ON wr.user_id = u.id
                             WHERE wr.id = ? AND wr.status = 'pending'";
        $getStmt = $conn->prepare($getWithdrawalSql);
        $getStmt->bind_param("i", $withdrawalId);
        $getStmt->execute();
        $withdrawalData = $getStmt->get_result()->fetch_assoc();
        
        $updateWithdrawalSql = "UPDATE withdrawal_requests 
                               SET status = 'rejected', 
                                   admin_id = ?, 
                                   admin_notes = ?,
                                   admin_action_at = NOW()
                               WHERE id = ? AND status = 'pending'";
        
        $updateWithdrawalStmt = $conn->prepare($updateWithdrawalSql);
        $updateWithdrawalStmt->bind_param("isi", $userId, $reason, $withdrawalId);
        
        if (!$updateWithdrawalStmt->execute()) {
            throw new Exception('خطا در به‌روزرسانی وضعیت درخواست');
        }
        
        if ($updateWithdrawalStmt->affected_rows === 0) {
            throw new Exception('درخواست یافت نشد یا قبلاً پردازش شده است');
        }
        
        // ارسال نوتیفیکیشن به کاربر
        if ($withdrawalData) {
            $currencyName = $withdrawalData['currency'] === 'IRR' ? 'تومان' : $withdrawalData['currency'];
            $formattedAmount = number_format($withdrawalData['amount'], 0);
            
            $userMessage = "❌ <b>درخواست تسویه حساب شما رد شد</b>\n\n";
            $userMessage .= "💰 مبلغ: {$formattedAmount} {$currencyName}\n";
            $userMessage .= "📅 تاریخ درخواست: " . date('Y/m/d H:i', strtotime($withdrawalData['created_at'])) . "\n\n";
            $userMessage .= "📝 <b>دلیل رد:</b>\n";
            $userMessage .= "{$reason}\n\n";
            $userMessage .= "💡 در صورت نیاز می‌توانید مجدداً درخواست دهید.\n";
            $userMessage .= "🆔 شماره درخواست: {$withdrawalId}";
            
            sendTelegramMessage($withdrawalData['telegram_id'], $userMessage);
        }
        
        $response['success'] = true;
        $response['message'] = "❌ درخواست با موفقیت رد شد.";
        
    // CASE 9: GET SINGLE WITHDRAWAL
    } elseif ($action === 'get_withdrawal' && $method === 'GET') {
        
        if (!$isAuthenticated) {
            throw new Exception('لطفاً وارد شوید');
        }
        
        $withdrawalId = isset($_GET['id']) ? intval($_GET['id']) : 0;
        
        if (!$withdrawalId) {
            throw new Exception('شناسه درخواست الزامی است');
        }
        
        $sql = "SELECT * FROM withdrawal_requests WHERE id = ? AND (user_id = ? OR ? IN (SELECT id FROM users WHERE is_admin = 1))";
        $stmt = $conn->prepare($sql);
        $isAdminVal = isAdmin($conn, $userId) ? 1 : 0;
        $stmt->bind_param("iii", $withdrawalId, $userId, $isAdminVal);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows !== 1) {
            throw new Exception('درخواست یافت نشد');
        }
        
        $response['success'] = true;
        $response['data'] = $result->fetch_assoc();
        
    // CASE 10: QUICK WITHDRAWAL FOR SPECIFIC PERSON
    } elseif ($action === 'quick_withdrawal' && $method === 'POST') {
        
        if (!$isAuthenticated) {
            throw new Exception('لطفاً وارد شوید');
        }
        
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (!$data) {
            throw new Exception('داده ارسال نشده است');
        }
        
        $targetUserId = intval($data['target_user_id'] ?? 0);
        $targetUserName = trim($data['target_user_name'] ?? '');
        $currency = $data['currency'] ?? 'IRR';
        
        // حذف کاما از مبلغ
        $amountRaw = $data['amount'] ?? 0;
        if (is_string($amountRaw)) {
            $amountRaw = str_replace(',', '', $amountRaw);
        }
        $amount = floatval($amountRaw);
        
        $bankName = trim($data['bank_name'] ?? '');
        $ibanNumber = trim($data['iban_number'] ?? '');
        $cardNumber = trim($data['card_number'] ?? '');
        $notes = trim($data['notes'] ?? '');
        
        // لاگ برای دیباگ
        error_log("Quick Withdrawal Request - User: $targetUserId, Amount: $amount, Bank: $bankName, IBAN: $ibanNumber");
        
        // اعتبارسنجی
        if ($targetUserId <= 0 && empty($targetUserName)) {
            throw new Exception('شناسه یا نام گیرنده نامعتبر است');
        }
        
        if ($amount <= 0) {
            throw new Exception('مبلغ باید بیشتر از صفر باشد');
        }
        
        if (empty($targetUserName)) {
            throw new Exception('نام گیرنده الزامی است');
        }
        
        if (empty($bankName)) {
            throw new Exception('نام بانک الزامی است');
        }
        
        if (empty($ibanNumber) || strlen($ibanNumber) < 10) {
            throw new Exception('شماره شبا نامعتبر است');
        }
        
        $validCurrencies = ['USD', 'EUR', 'USDT', 'IRR'];
        if (!in_array($currency, $validCurrencies)) {
            throw new Exception('ارز نامعتبر است');
        }
        
        $balanceField = 'balance_' . strtolower($currency);
        
        $sql = "SELECT id, $balanceField, first_name, last_name, telegram_id FROM users WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        
        if (!$user) {
            throw new Exception('کاربر یافت نشد');
        }
        
        $balance = floatval($user[$balanceField] ?? 0);
        
        if ($balance < $amount) {
            throw new Exception('موجودی ناکافی. موجودی: ' . number_format($balance) . ' ' . $currency);
        }
        
        // بررسی وجود درخواست مشابه در حال انتظار برای همین گیرنده
        $checkDuplicateSql = "SELECT COUNT(*) as count FROM withdrawal_requests 
                              WHERE user_id = ? AND status = 'pending' 
                              AND (target_user_id = ? OR recipient_name = ?)";
        $checkStmt = $conn->prepare($checkDuplicateSql);
        $checkStmt->bind_param("iis", $userId, $targetUserId, $targetUserName);
        $checkStmt->execute();
        $duplicate = $checkStmt->get_result()->fetch_assoc();
        
        if ($duplicate && $duplicate['count'] > 0) {
            throw new Exception('شما قبلاً یک درخواست تسویه در انتظار برای این گیرنده دارید. لطفاً صبر کنید تا تایید شود.');
        }
        
        $withdrawalSql = "INSERT INTO withdrawal_requests (
            user_id, amount, currency, recipient_name, target_user_id, 
            target_user_name, bank_name, iban_number, card_number, notes, status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())";
        
        $withdrawalStmt = $conn->prepare($withdrawalSql);
        if (!$withdrawalStmt) {
            throw new Exception('خطا در آماده سازی کوئری: ' . $conn->error);
        }
        
        $withdrawalStmt->bind_param(
            "idssisssss", 
            $userId, $amount, $currency, $targetUserName, $targetUserId, 
            $targetUserName, $bankName, $ibanNumber, $cardNumber, $notes
        );
        
        if (!$withdrawalStmt->execute()) {
            throw new Exception('خطا در ثبت درخواست: ' . $conn->error);
        }
        
        $withdrawalId = $withdrawalStmt->insert_id;
        
        // ارسال به ادمین
        $currencyName = $currency === 'IRR' ? 'تومان' : $currency;
        $formattedAmount = number_format($amount, 0);
        
        $adminMessage = "🆕 <b>درخواست تسویه جدید (سریع)</b>\n\n";
        $adminMessage .= "👤 کاربر: {$user['first_name']} {$user['last_name']}\n";
        $adminMessage .= "🆔 آیدی کاربر: {$userId}\n";
        $adminMessage .= "💰 مبلغ: {$formattedAmount} {$currencyName}\n";
        $adminMessage .= "👤 گیرنده: {$targetUserName}\n";
        $adminMessage .= "🏦 بانک: {$bankName}\n";
        $adminMessage .= "🔢 شبا: {$ibanNumber}\n";
        if ($cardNumber) {
            $adminMessage .= "💳 کارت: {$cardNumber}\n";
        }
        if ($notes) {
            $adminMessage .= "📝 توضیحات: {$notes}\n";
        }
        $adminMessage .= "📅 تاریخ: " . date('Y/m/d H:i') . "\n\n";
        $adminMessage .= "🔗 برای بررسی به پنل ادمین مراجعه کنید.";
        
        sendTelegramMessage('5330629504', $adminMessage);
        
        $response['success'] = true;
        $response['message'] = '✅ درخواست تسویه با موفقیت ثبت شد.';
        $response['data'] = [
            'withdrawal_id' => $withdrawalId
        ];
        
    } else {
        $response['message'] = 'اکشن نامعتبر است';
        $response['available_actions'] = [
            'submit', 'get_user_requests', 'get_pending', 'get_approved',
            'get_completed', 'approve', 'reject', 'upload_receipt', 
            'get_withdrawal', 'quick_withdrawal'
        ];
    }
    
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

// ============================================
// 14. OUTPUT JSON
// ============================================
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit();
?>