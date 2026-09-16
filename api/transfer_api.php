<?php
// api/transfer_api.php - نسخه نهایی کامل
error_reporting(0);
header('Content-Type: application/json');

require_once '../config/database.php';
require_once __DIR__ . '/../includes/invoice_sync.php';
require_once __DIR__ . '/../includes/upload_paths.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'لطفاً وارد شوید']);
    exit();
}

$userId = $_SESSION['user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? null;

if (!$action) {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? null;
}

// ایجاد جدول
$conn->query("CREATE TABLE IF NOT EXISTS `money_transfers` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `tracking_code` VARCHAR(20) NOT NULL UNIQUE,
    `user_id` INT NOT NULL,
    `country` VARCHAR(100) NOT NULL,
    `country_code` VARCHAR(5) NOT NULL,
    `currency` VARCHAR(10) DEFAULT 'EUR',
    `full_name` VARCHAR(200) NOT NULL,
    `iban` VARCHAR(100) NOT NULL,
    `bank_name` VARCHAR(200) NOT NULL,
    `amount` DECIMAL(20,2) NOT NULL,
    `short_info` TEXT,
    `status` VARCHAR(50) DEFAULT 'pending',
    `reject_reason` TEXT,
    `payment_receipt` VARCHAR(500),
    `settlement_receipt` VARCHAR(500),
    `completed_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// اضافه کردن ستون completed_at اگر وجود نداشت
$checkColumn = $conn->query("SHOW COLUMNS FROM money_transfers LIKE 'completed_at'");
if ($checkColumn && $checkColumn->num_rows == 0) {
    $conn->query("ALTER TABLE money_transfers ADD COLUMN completed_at TIMESTAMP NULL");
}

// ستون‌های مرحله‌ی جدید «ارسال شماره‌حساب توسط ادمین + آپلود چند فیش توسط کاربر»
// admin_accounts : JSON آرایه‌ای از شماره‌حساب‌هایی که ادمین برای واریز اعلام می‌کند
// payment_receipts : JSON آرایه‌ای از مسیر فیش‌های آپلودشده توسط کاربر (چند عکس)
$addCols = [
    'admin_accounts'      => "ALTER TABLE money_transfers ADD COLUMN admin_accounts TEXT NULL",
    'payment_receipts'    => "ALTER TABLE money_transfers ADD COLUMN payment_receipts TEXT NULL",
    // settlement_receipts : JSON آرایه‌ای از فیش‌های تسویه‌ای که ادمین آپلود می‌کند (چند عکس)
    'settlement_receipts' => "ALTER TABLE money_transfers ADD COLUMN settlement_receipts TEXT NULL",
];
foreach ($addCols as $col => $ddl) {
    $chk = $conn->query("SHOW COLUMNS FROM money_transfers LIKE '$col'");
    if ($chk && $chk->num_rows == 0) $conn->query($ddl);
}

// -----------------------------------------------------------------------
// FIX (باگ «ارسال شماره‌حساب به کاربر کار نمی‌کند»):
// در دیتابیس‌های قدیمی ستون status از نوع ENUM بوده و شامل مقدار
// 'awaiting_payment' نبود (فقط 'waiting_payment' که مخصوص جدول topup_requests
// است). در نتیجه UPDATE ... SET status='awaiting_payment' رد می‌شد یا مقدار
// نامعتبر ذخیره می‌کرد و دکمه‌ی «ارسال به کاربر» عملاً بی‌اثر بود. این بخش
// ستون را به VARCHAR تبدیل می‌کند تا هر وضعیتی که در کد استفاده می‌شود بدون
// محدودیت قابل ذخیره باشد و رکوردهای قدیمی را نیز اصلاح می‌کند.
$colInfo = $conn->query("SHOW COLUMNS FROM money_transfers LIKE 'status'");
if ($colInfo && ($colRow = $colInfo->fetch_assoc())) {
    if (stripos($colRow['Type'], 'enum') !== false && stripos($colRow['Type'], 'awaiting_payment') === false) {
        $conn->query("ALTER TABLE money_transfers MODIFY COLUMN status VARCHAR(30) NOT NULL DEFAULT 'pending'");
        $conn->query("UPDATE money_transfers SET status = 'awaiting_payment' WHERE status = 'waiting_payment'");
    }
}

function generateCode() {
    return 'TR' . date('Ymd') . rand(100, 999);
}

// تابع ساده تلگرام
function sendTelegramSimple($chatId, $message) {
    try {
        $botToken = BOT_TOKEN;
        if (empty($chatId)) return false;
        
        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
        $data = ['chat_id' => $chatId, 'text' => $message, 'parse_mode' => ''];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_exec($ch);
        curl_close($ch);
        
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// ==================== ایجاد درخواست جدید ====================
if ($action === 'create') {
    $__banChk = $conn->query("SHOW COLUMNS FROM users LIKE 'is_banned'");
    if ($__banChk && $__banChk->num_rows > 0) {
        $__banRow = $conn->query("SELECT is_banned, ban_reason FROM users WHERE id = " . (int)$userId)->fetch_assoc();
        if (!empty($__banRow['is_banned'])) {
            echo json_encode(['success' => false, 'message' => 'حساب شما مسدود شده و امکان ثبت درخواست حواله ندارید' . (!empty($__banRow['ban_reason']) ? ' (' . $__banRow['ban_reason'] . ')' : '')]);
            exit();
        }
    }

    $input = json_decode(file_get_contents('php://input'), true);
    
    $code = generateCode();
    $country = $conn->real_escape_string($input['country'] ?? '');
    $countryCode = $conn->real_escape_string($input['country_code'] ?? '');
    $currency = $conn->real_escape_string($input['currency'] ?? 'EUR');
    $fullName = $conn->real_escape_string($input['full_name'] ?? '');
    $iban = $conn->real_escape_string($input['iban'] ?? '');
    $bankName = $conn->real_escape_string($input['bank_name'] ?? '');
    $amount = floatval($input['amount'] ?? 0);
    $shortInfo = $conn->real_escape_string($input['short_info'] ?? '');
    
    if (empty($country) || empty($fullName) || empty($iban) || empty($bankName) || $amount < 10) {
        echo json_encode(['success' => false, 'message' => 'لطفاً تمام فیلدها را پر کنید']);
        exit();
    }

    // ===== (اصلاح ۳) بررسی کفایت موجودی کیف پول پیش از ثبت درخواست حواله =====
    // اگر کاربر در ارز انتخابی موجودی کافی نداشته باشد، اجازه ثبت داده نمی‌شود
    // و باید ابتدا افزایش موجودی دهد.
    $currLower = strtolower($currency);
    $balMap = ['irr'=>'balance_irr','toman'=>'balance_irr','tmn'=>'balance_irr',
               'usd'=>'balance_usd','eur'=>'balance_eur','usdt'=>'balance_usdt'];
    $balField = $balMap[$currLower] ?? null;
    if ($balField) {
        // اطمینان از وجود ستون
        $colChk = $conn->query("SHOW COLUMNS FROM users LIKE '$balField'");
        if ($colChk && $colChk->num_rows === 0) {
            $conn->query("ALTER TABLE users ADD COLUMN $balField DECIMAL(20,2) DEFAULT 0");
        }
        $balRow = $conn->query("SELECT $balField AS bal FROM users WHERE id = " . (int)$userId)->fetch_assoc();
        $userBal = (float)($balRow['bal'] ?? 0);
        if ($userBal < $amount) {
            echo json_encode([
                'success'      => false,
                'code'         => 'insufficient_balance',
                'need_topup'   => true,
                'balance'      => $userBal,
                'currency'     => $currency,
                'message'      => 'موجودی کیف پول شما برای این حواله کافی نیست. لطفاً ابتدا افزایش موجودی دهید.'
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }
    }

    $stmt = $conn->prepare("INSERT INTO money_transfers (tracking_code, user_id, country, country_code, currency, full_name, iban, bank_name, amount, short_info) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sissssssds", $code, $userId, $country, $countryCode, $currency, $fullName, $iban, $bankName, $amount, $shortInfo);
    
    if ($stmt->execute()) {
        $userQuery = $conn->query("SELECT first_name, last_name, telegram_id FROM users WHERE id = $userId");
        $userInfo = $userQuery->fetch_assoc();
        
        $adminMsg = "🏦 درخواست حواله جدید\nکد: $code\nکاربر: {$userInfo['first_name']} {$userInfo['last_name']}\nمبلغ: $amount $currency\nکشور: $country\nگیرنده: $fullName";
        sendTelegramSimple(ADMIN_TELEGRAM_ID, $adminMsg);
        
        if (!empty($userInfo['telegram_id'])) {
            $userMsg = "✅ درخواست حواله شما ثبت شد\nکد پیگیری: $code\nمبلغ: $amount $currency\nوضعیت: در انتظار تایید";
            sendTelegramSimple($userInfo['telegram_id'], $userMsg);
        }
        
        echo json_encode(['success' => true, 'message' => "درخواست ثبت شد. کد: $code"]);
        exit();
    } else {
        echo json_encode(['success' => false, 'message' => 'خطا در ثبت: ' . $conn->error]);
        exit();
    }
}

// ==================== ارسال شماره‌حساب(ها) توسط ادمین ====================
// بعد از تایید درخواست، ادمین یک یا چند شماره‌حساب برای واریز اعلام می‌کند.
// وضعیت به awaiting_payment تغییر می‌کند و به کاربر اطلاع داده می‌شود.
if ($action === 'admin_send_accounts') {
    if (!isAdmin($userId)) {
        echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']);
        exit();
    }

    // اطمینان از وجود ستون admin_accounts (خوددرمان برای دیتابیس‌های قدیمی)
    $chkCol = $conn->query("SHOW COLUMNS FROM money_transfers LIKE 'admin_accounts'");
    if ($chkCol && $chkCol->num_rows == 0) {
        $conn->query("ALTER TABLE money_transfers ADD COLUMN admin_accounts TEXT NULL");
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $requestId = intval($input['request_id'] ?? 0);
    $accounts  = $input['accounts'] ?? []; // آرایه‌ای از {name, card}

    if (!is_array($accounts)) $accounts = [];
    $clean = [];
    foreach ($accounts as $a) {
        if (is_array($a)) {
            $name = trim((string)($a['name'] ?? ''));
            $card = trim((string)($a['card'] ?? ''));
        } else {
            // سازگاری با نسخه‌ی قدیمی (رشته‌ی خالص)
            $name = '';
            $card = trim((string)$a);
        }
        if ($card !== '' || $name !== '') {
            $clean[] = ['name' => $name, 'card' => $card];
        }
    }
    if (empty($clean)) {
        echo json_encode(['success' => false, 'message' => 'حداقل یک شماره‌کارت وارد کنید']);
        exit();
    }

    $transfer = $conn->query("SELECT t.*, u.telegram_id, u.first_name, u.last_name
                              FROM money_transfers t JOIN users u ON t.user_id = u.id
                              WHERE t.id = $requestId")->fetch_assoc();
    if (!$transfer) {
        echo json_encode(['success' => false, 'message' => 'درخواست یافت نشد']);
        exit();
    }

    $accountsJson = $conn->real_escape_string(json_encode($clean, JSON_UNESCAPED_UNICODE));
    $ok = $conn->query("UPDATE money_transfers SET admin_accounts = '$accountsJson', status = 'awaiting_payment' WHERE id = $requestId");
    if (!$ok) {
        echo json_encode(['success' => false, 'message' => 'خطا در ذخیره: ' . $conn->error]);
        exit();
    }

    // همان درخواست پرداخت را در کادر «صورت‌حساب‌ها»ی داشبورد کاربر هم ثبت می‌کنیم
    if (function_exists('avapay_sync_payment_invoice')) {
        $__first = $clean[0] ?? [];
        avapay_sync_payment_invoice($conn, 'transfer', $requestId, (int)$transfer['user_id'],
            (float)$transfer['amount'], (string)$transfer['currency'],
            "حواله ارزی {$transfer['tracking_code']}",
            ['card_number' => (string)($__first['card'] ?? ''),
             'recipient_name' => (string)($__first['name'] ?? '')]);
    }

    if (!empty($transfer['telegram_id'])) {
        $accLines = '';
        foreach ($clean as $i => $acc) {
            $accLines .= "\n" . ($i + 1) . ") ";
            if ($acc['name'] !== '') $accLines .= "به نام " . $acc['name'] . "\n   ";
            $accLines .= "شماره کارت: " . $acc['card'];
        }
        $userMsg  = "🏦 حواله شما تایید شد و آماده‌ی پرداخت است\n";
        $userMsg .= "کد پیگیری: {$transfer['tracking_code']}\n";
        $userMsg .= "مبلغ: {$transfer['amount']} {$transfer['currency']}\n\n";
        $userMsg .= "لطفاً مبلغ را به یکی از کارت‌های زیر واریز کرده و فیش(ها) را در اپلیکیشن آپلود کنید:{$accLines}";
        sendTelegramSimple($transfer['telegram_id'], $userMsg);
    }

    echo json_encode(['success' => true, 'message' => 'شماره‌کارت‌ها برای کاربر ارسال شد', 'accounts' => $clean]);
    exit();
}

// ==================== آپلود فیش (چند فایل) ====================
if ($action === 'upload_receipt') {
    $requestId = intval($_POST['request_id'] ?? 0);

    $check = $conn->query("SELECT user_id, tracking_code, payment_receipts, payment_receipt FROM money_transfers WHERE id = $requestId");
    if (!$check || $check->num_rows == 0) {
        echo json_encode(['success' => false, 'message' => 'درخواست یافت نشد']);
        exit();
    }

    $req = $check->fetch_assoc();
    if ($req['user_id'] != $userId) {
        echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']);
        exit();
    }

    // پشتیبانی از هر دو حالت: چند فایل (file[]) یا تک فایل (file)
    $files = [];
    if (isset($_FILES['file'])) {
        if (is_array($_FILES['file']['name'])) {
            $count = count($_FILES['file']['name']);
            for ($i = 0; $i < $count; $i++) {
                if ($_FILES['file']['error'][$i] === 0) {
                    $files[] = [
                        'name' => $_FILES['file']['name'][$i],
                        'tmp'  => $_FILES['file']['tmp_name'][$i],
                    ];
                }
            }
        } elseif ($_FILES['file']['error'] === 0) {
            $files[] = ['name' => $_FILES['file']['name'], 'tmp' => $_FILES['file']['tmp_name']];
        }
    }

    if (empty($files)) {
        echo json_encode(['success' => false, 'message' => 'حداقل یک فایل انتخاب کنید']);
        exit();
    }

    // طبق درخواست: فیش‌ها دیگر داخل پوشه‌ی کد (uploads/) ذخیره نمی‌شوند —
    // بلکه در پوشه‌ی avapay_uploads/transfer_receipts کنار /ledor در ریشه‌ی
    // public_html می‌نشینند (avapay_upload_dir()، همان الگوی بقیه‌ی آپلودها)
    $uploadDir = avapay_upload_dir('transfer_receipts');

    // فیش‌های قبلی (اگر موجود بود) را نگه می‌داریم و جدیدها را اضافه می‌کنیم
    $existing = [];
    if (!empty($req['payment_receipts'])) {
        $decoded = json_decode($req['payment_receipts'], true);
        if (is_array($decoded)) $existing = $decoded;
    }
    if (empty($existing) && !empty($req['payment_receipt'])) {
        $existing[] = $req['payment_receipt']; // سازگاری با رکوردهای قدیمی
    }

    $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
    $saved = [];
    foreach ($files as $idx => $f) {
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt)) continue;
        $filename = 'payment_' . $requestId . '_' . time() . '_' . $idx . '.' . $ext;
        if (move_uploaded_file($f['tmp'], $uploadDir . $filename)) {
            // مسیرِ ذخیره‌شده در دیتابیس همان قالب قبلی (uploads/<sub>/<file>)
            // است — چون کدهای نمایشی همه‌جا '/ledor/' + این مقدار را می‌سازند؛
            // فایل فیزیکی جای دیگری‌ست، اما uploads/.htaccess این مسیر را
            // به‌صورت شفاف به همان‌جا هدایت می‌کند.
            $saved[] = 'uploads/transfer_receipts/' . $filename;
        }
    }

    if (empty($saved)) {
        echo json_encode(['success' => false, 'message' => 'خطا در آپلود فایل‌ها']);
        exit();
    }

    $all = array_values(array_merge($existing, $saved));
    $allJson = $conn->real_escape_string(json_encode($all, JSON_UNESCAPED_UNICODE));
    $firstReceipt = $conn->real_escape_string($all[0]); // برای سازگاری با ستون قدیمی
    $conn->query("UPDATE money_transfers
                  SET payment_receipts = '$allJson', payment_receipt = '$firstReceipt', status = 'payment_submitted'
                  WHERE id = $requestId");

    $userInfo = $conn->query("SELECT first_name, last_name FROM users WHERE id = $userId")->fetch_assoc();
    $adminMsg = "📎 فیش پرداخت ارسال شد (" . count($saved) . " فایل)\nکد: {$req['tracking_code']}\nکاربر: {$userInfo['first_name']} {$userInfo['last_name']}";
    sendTelegramSimple(ADMIN_TELEGRAM_ID, $adminMsg);

    echo json_encode(['success' => true, 'message' => count($saved) . ' فیش ارسال شد', 'receipts' => $all]);
    exit();
}

// ==================== بروزرسانی وضعیت (ادمین) ====================
if ($action === 'admin_update_status') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        echo json_encode(['success' => false, 'message' => 'داده ارسال نشده']);
        exit();
    }
    
    $requestId = intval($input['request_id'] ?? 0);
    $status = $conn->real_escape_string($input['status'] ?? '');
    $reason = $conn->real_escape_string($input['reject_reason'] ?? '');
    
    $valid = ['pending', 'approved', 'awaiting_payment', 'rejected', 'payment_submitted', 'completed'];
    if (!in_array($status, $valid)) {
        echo json_encode(['success' => false, 'message' => 'وضعیت نامعتبر']);
        exit();
    }
    
    // دریافت اطلاعات درخواست
    $transferQuery = $conn->query("SELECT t.*, u.telegram_id, u.first_name, u.last_name 
                                   FROM money_transfers t 
                                   JOIN users u ON t.user_id = u.id 
                                   WHERE t.id = $requestId");
    $transfer = $transferQuery->fetch_assoc();
    
    if (!$transfer) {
        echo json_encode(['success' => false, 'message' => 'درخواست یافت نشد']);
        exit();
    }
    
    $sql = "UPDATE money_transfers SET status = '$status'";
    if ($reason) $sql .= ", reject_reason = '$reason'";
    if ($status == 'completed') $sql .= ", completed_at = NOW()";
    $sql .= " WHERE id = $requestId";
    
    if ($conn->query($sql)) {
        // ارسال پیام به کاربر
        if ($transfer['telegram_id']) {
            if ($status == 'approved') {
                $msg = "✅ درخواست حواله شما تایید شد\nکد: {$transfer['tracking_code']}\nمبلغ: {$transfer['amount']} {$transfer['currency']}\nشماره‌حساب(های) واریز به‌زودی برای شما ارسال می‌شود.";
                sendTelegramSimple($transfer['telegram_id'], $msg);
            } elseif ($status == 'rejected') {
                $msg = "❌ درخواست حواله شما رد شد\nکد: {$transfer['tracking_code']}\nدلیل: $reason";
                sendTelegramSimple($transfer['telegram_id'], $msg);
            } elseif ($status == 'completed') {
                $msg = "🎉 حواله شما تکمیل شد!\nکد: {$transfer['tracking_code']}\nمبلغ: {$transfer['amount']} {$transfer['currency']}";
                sendTelegramSimple($transfer['telegram_id'], $msg);
            }
        }
        
        echo json_encode(['success' => true, 'message' => 'وضعیت تغییر کرد']);
    } else {
        echo json_encode(['success' => false, 'message' => 'خطا در تغییر وضعیت']);
    }
    exit();
}

// ==================== آپلود فیش تسویه (ادمین) ====================
if ($action === 'upload_settlement') {
    $requestId = intval($_POST['request_id'] ?? 0);
    
    $transferQuery = $conn->query("SELECT t.*, u.telegram_id FROM money_transfers t JOIN users u ON t.user_id = u.id WHERE t.id = $requestId");
    $transfer = $transferQuery->fetch_assoc();
    
    if (!$transfer) {
        echo json_encode(['success' => false, 'message' => 'درخواست یافت نشد']);
        exit();
    }
    
    // پشتیبانی از چند فایل (file[]) و همچنین تک‌فایل قدیمی (file)
    $files = [];
    if (isset($_FILES['file'])) {
        if (is_array($_FILES['file']['name'])) {
            $count = count($_FILES['file']['name']);
            for ($i = 0; $i < $count; $i++) {
                if ($_FILES['file']['error'][$i] === 0) {
                    $files[] = ['name' => $_FILES['file']['name'][$i], 'tmp' => $_FILES['file']['tmp_name'][$i]];
                }
            }
        } elseif ($_FILES['file']['error'] === 0) {
            $files[] = ['name' => $_FILES['file']['name'], 'tmp' => $_FILES['file']['tmp_name']];
        }
    }

    if (empty($files)) {
        echo json_encode(['success' => false, 'message' => 'حداقل یک فایل انتخاب کنید']);
        exit();
    }

    // همان تغییر: فیش‌های تسویه هم بیرون از پوشه‌ی کد ذخیره می‌شوند
    $uploadDir = avapay_upload_dir('transfer_settlements');

    // فیش‌های تسویه‌ی قبلی را نگه می‌داریم و جدیدها را اضافه می‌کنیم
    $existing = [];
    if (!empty($transfer['settlement_receipts'])) {
        $decoded = json_decode($transfer['settlement_receipts'], true);
        if (is_array($decoded)) $existing = $decoded;
    }
    if (empty($existing) && !empty($transfer['settlement_receipt'])) {
        $existing[] = $transfer['settlement_receipt']; // سازگاری با رکوردهای قدیمی
    }

    $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
    $saved = [];
    foreach ($files as $idx => $f) {
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt)) continue;
        $filename = 'settlement_' . $requestId . '_' . time() . '_' . $idx . '.' . $ext;
        if (move_uploaded_file($f['tmp'], $uploadDir . $filename)) {
            $saved[] = 'uploads/transfer_settlements/' . $filename;
        }
    }

    if (empty($saved)) {
        echo json_encode(['success' => false, 'message' => 'خطا در آپلود فایل‌ها']);
        exit();
    }

    $all = array_values(array_merge($existing, $saved));
    $allJson = $conn->real_escape_string(json_encode($all, JSON_UNESCAPED_UNICODE));
    $firstReceipt = $conn->real_escape_string($all[0]); // برای سازگاری با ستون قدیمی

    // ===== (اصلاح ۲) کسر مبلغ حواله از کیف پول کاربر هنگام تکمیل توسط ادمین =====
    // فقط اگر قبلاً کسر نشده باشد (status هنوز completed نبوده)
    $alreadyCompleted = ($transfer['status'] ?? '') === 'completed';
    if (!$alreadyCompleted) {
        $curr = strtolower($transfer['currency'] ?? '');
        $map  = ['irr'=>'balance_irr','toman'=>'balance_irr','tmn'=>'balance_irr',
                 'usd'=>'balance_usd','دلار'=>'balance_usd',
                 'eur'=>'balance_eur','یورو'=>'balance_eur',
                 'usdt'=>'balance_usdt','تتر'=>'balance_usdt'];
        $balField = $map[$curr] ?? null;
        $amount   = (float)($transfer['amount'] ?? 0);
        if ($balField && $amount > 0) {
            // اطمینان از وجود ستون موجودی
            $colChk = $conn->query("SHOW COLUMNS FROM users LIKE '$balField'");
            if ($colChk && $colChk->num_rows === 0) {
                $conn->query("ALTER TABLE users ADD COLUMN $balField DECIMAL(20,2) DEFAULT 0");
            }
            // کسر امن با شرط کفایت موجودی (جلوگیری از منفی‌شدن)
            $uid = (int)$transfer['user_id'];
            $conn->query("UPDATE users SET $balField = $balField - $amount
                          WHERE id = $uid AND $balField >= $amount");
            // اگر به‌خاطر کمبود موجودی کسر نشد، همان مقدار موجود را صفر نکنیم؛
            // فقط در صورت کفایت کسر می‌شود. (بررسی موجودی در ثبت درخواست انجام شده است.)
        }
    }

    $conn->query("UPDATE money_transfers
                  SET settlement_receipts = '$allJson', settlement_receipt = '$firstReceipt', status = 'completed', completed_at = NOW()
                  WHERE id = $requestId");

    // حواله تکمیل شد → صورت‌حساب مرتبط از «در انتظار پرداخت» به آرشیو می‌رود
    if (function_exists('avapay_close_payment_invoice')) {
        avapay_close_payment_invoice($conn, 'transfer', $requestId);
    }

    if ($transfer['telegram_id']) {
        $msg = "🎉 حواله شما تکمیل شد!\nکد: {$transfer['tracking_code']}\nمبلغ: {$transfer['amount']} {$transfer['currency']}\n" . count($all) . " فیش تسویه در پنل موجود است";
        sendTelegramSimple($transfer['telegram_id'], $msg);
    }

    echo json_encode(['success' => true, 'message' => count($saved) . ' فیش تسویه ثبت شد', 'receipts' => $all]);
    exit();
}

// ==================== لیست فیش‌های تسویه (برای نمایش در مودال کاربر) ====================
if ($action === 'get_settlement_receipts') {
    $requestId = intval($_GET['request_id'] ?? 0);
    $result = $conn->query("SELECT settlement_receipts, settlement_receipt, user_id FROM money_transfers WHERE id = $requestId");
    $row = $result ? $result->fetch_assoc() : null;

    if (!$row || !($row['user_id'] == $userId || isAdmin($userId))) {
        echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز', 'receipts' => []]);
        exit();
    }

    $list = [];
    if (!empty($row['settlement_receipts'])) {
        $decoded = json_decode($row['settlement_receipts'], true);
        if (is_array($decoded)) $list = $decoded;
    }
    if (empty($list) && !empty($row['settlement_receipt'])) {
        $list[] = $row['settlement_receipt'];
    }
    echo json_encode(['success' => true, 'receipts' => array_values($list)]);
    exit();
}

// ==================== دانلود فیش تسویه ====================
if ($action === 'download_receipt') {
    $requestId = intval($_GET['request_id'] ?? 0);
    $index     = intval($_GET['index'] ?? 0);
    $result = $conn->query("SELECT settlement_receipts, settlement_receipt, user_id FROM money_transfers WHERE id = $requestId");
    $row = $result ? $result->fetch_assoc() : null;

    if ($row && ($row['user_id'] == $userId || isAdmin($userId))) {
        // ساخت لیست کامل فیش‌ها
        $list = [];
        if (!empty($row['settlement_receipts'])) {
            $decoded = json_decode($row['settlement_receipts'], true);
            if (is_array($decoded)) $list = $decoded;
        }
        if (empty($list) && !empty($row['settlement_receipt'])) {
            $list[] = $row['settlement_receipt'];
        }
        if (isset($list[$index])) {
            $rel  = $list[$index];
            $file = __DIR__ . '/../' . $rel;
            if (file_exists($file)) {
                $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION)) ?: 'jpg';
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="receipt_' . $requestId . '_' . ($index + 1) . '.' . $ext . '"');
                readfile($file);
                exit();
            }
        }
    }
    echo "فایل یافت نشد";
    exit();
}

echo json_encode(['success' => false, 'message' => 'اکشن نامعتبر']);
?>