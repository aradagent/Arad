<?php
// api/cards.php
// مدیریت کارت‌های بانکی شرکت - نسخه نهایی با تشخیص خودکار فیلدها

error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'ابتدا وارد شوید']);
    exit();
}

$userId = $_SESSION['user_id'];

$dbPath = dirname(__DIR__) . '/config/database.php';
if (!file_exists($dbPath)) {
    echo json_encode(['success' => false, 'message' => 'فایل دیتابیس یافت نشد']);
    exit();
}

require_once $dbPath;

if (!$conn || $conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'خطا در اتصال به دیتابیس']);
    exit();
}

// بررسی ادمین بودن
$ADMIN_TELEGRAM_ID = '5330629504';
$isAdmin = false;

$adminSql = "SELECT telegram_id, is_admin FROM users WHERE id = ?";
$adminStmt = $conn->prepare($adminSql);
if ($adminStmt) {
    $adminStmt->bind_param("i", $userId);
    $adminStmt->execute();
    $adminResult = $adminStmt->get_result();
    if ($adminResult->num_rows > 0) {
        $userData = $adminResult->fetch_assoc();
        if ($userData['telegram_id'] == $ADMIN_TELEGRAM_ID || $userData['is_admin'] == 1) {
            $isAdmin = true;
        }
    }
    $adminStmt->close();
}

$action = $_GET['action'] ?? '';

if (!$isAdmin && $action != 'get_cards' && $action != 'get_card_stats' && $action != 'get_card_transactions') {
    echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز. فقط ادمین']);
    exit();
}

// بررسی نام فیلد شماره کارت/شبا (می‌تواند card_number یا iban باشد)
$fieldName = 'card_number';
$checkField = $conn->query("SHOW COLUMNS FROM company_cards LIKE 'card_number'");
if (!$checkField || $checkField->num_rows == 0) {
    $checkField2 = $conn->query("SHOW COLUMNS FROM company_cards LIKE 'iban'");
    if ($checkField2 && $checkField2->num_rows > 0) {
        $fieldName = 'iban';
    }
}

// ایجاد یا به‌روزرسانی جدول
$conn->query("CREATE TABLE IF NOT EXISTS `company_cards` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `card_number` VARCHAR(24) NULL,
    `iban` VARCHAR(24) NULL,
    `bank_name` VARCHAR(100) NOT NULL,
    `card_owner` VARCHAR(100) DEFAULT 'شرکت آراد',
    `description` TEXT,
    `is_active` TINYINT DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// اگر فیلد card_number خالی است اما iban پر است، داده را کپی کنید
$conn->query("UPDATE company_cards SET card_number = iban WHERE card_number IS NULL AND iban IS NOT NULL");

switch ($action) {
    
    case 'get_cards':
        $sql = "SELECT id, 
                       CASE 
                           WHEN card_number IS NOT NULL AND card_number != '' THEN card_number 
                           ELSE iban 
                       END as card_number,
                       bank_name, card_owner, description, is_active, created_at 
                FROM company_cards 
                ORDER BY is_active DESC, id DESC";
        $result = $conn->query($sql);
        $cards = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $cards[] = $row;
            }
        }
        echo json_encode(['success' => true, 'data' => $cards]);
        break;
        
    case 'add_card':
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        if (!$data) {
            echo json_encode(['success' => false, 'message' => 'داده ارسال نشده است']);
            break;
        }
        
        $cardNumber = preg_replace('/[^0-9]/', '', $data['card_number'] ?? $data['iban'] ?? '');
        $bankName = trim($data['bank_name'] ?? '');
        $cardOwner = trim($data['card_owner'] ?? 'شرکت آراد');
        $description = trim($data['description'] ?? '');
        $isActive = isset($data['is_active']) ? intval($data['is_active']) : 1;
        
        if (strlen($cardNumber) < 16 || strlen($cardNumber) > 24) {
            echo json_encode(['success' => false, 'message' => 'شماره کارت/شبا باید بین ۱۶ تا ۲۴ رقم باشد']);
            break;
        }
        if (empty($bankName)) {
            echo json_encode(['success' => false, 'message' => 'نام بانک الزامی است']);
            break;
        }
        
        $sql = "INSERT INTO company_cards (card_number, iban, bank_name, card_owner, description, is_active) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("sssssi", $cardNumber, $cardNumber, $bankName, $cardOwner, $description, $isActive);
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'حساب با موفقیت اضافه شد', 'id' => $conn->insert_id]);
            } else {
                echo json_encode(['success' => false, 'message' => 'خطا در افزودن حساب: ' . $stmt->error]);
            }
            $stmt->close();
        } else {
            echo json_encode(['success' => false, 'message' => 'خطا در آماده سازی query']);
        }
        break;
        
    case 'update_card':
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        if (!$data) {
            echo json_encode(['success' => false, 'message' => 'داده ارسال نشده است']);
            break;
        }
        
        $cardId = intval($data['card_id'] ?? 0);
        $cardNumber = preg_replace('/[^0-9]/', '', $data['card_number'] ?? $data['iban'] ?? '');
        $bankName = trim($data['bank_name'] ?? '');
        $cardOwner = trim($data['card_owner'] ?? '');
        $description = trim($data['description'] ?? '');
        $isActive = isset($data['is_active']) ? intval($data['is_active']) : 1;
        
        if ($cardId <= 0) {
            echo json_encode(['success' => false, 'message' => 'شناسه حساب نامعتبر است']);
            break;
        }
        if (strlen($cardNumber) < 16 || strlen($cardNumber) > 24) {
            echo json_encode(['success' => false, 'message' => 'شماره کارت/شبا باید بین ۱۶ تا ۲۴ رقم باشد']);
            break;
        }
        if (empty($bankName)) {
            echo json_encode(['success' => false, 'message' => 'نام بانک الزامی است']);
            break;
        }
        
        $sql = "UPDATE company_cards SET card_number=?, iban=?, bank_name=?, card_owner=?, description=?, is_active=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("sssssii", $cardNumber, $cardNumber, $bankName, $cardOwner, $description, $isActive, $cardId);
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'حساب با موفقیت ویرایش شد']);
            } else {
                echo json_encode(['success' => false, 'message' => 'خطا در ویرایش حساب: ' . $stmt->error]);
            }
            $stmt->close();
        } else {
            echo json_encode(['success' => false, 'message' => 'خطا در آماده سازی query']);
        }
        break;
        
    case 'delete_card':
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        if (!$data) {
            echo json_encode(['success' => false, 'message' => 'داده ارسال نشده است']);
            break;
        }
        
        $cardId = intval($data['card_id'] ?? 0);
        if ($cardId <= 0) {
            echo json_encode(['success' => false, 'message' => 'شناسه حساب نامعتبر است']);
            break;
        }
        
        $checkSql = "SELECT COUNT(*) as cnt FROM withdrawal_requests WHERE card_id = ?";
        $checkStmt = $conn->prepare($checkSql);
        if ($checkStmt) {
            $checkStmt->bind_param("i", $cardId);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            $checkRow = $checkResult->fetch_assoc();
            $checkStmt->close();
            
            if ($checkRow['cnt'] > 0) {
                echo json_encode(['success' => false, 'message' => 'این حساب دارای ' . $checkRow['cnt'] . ' تراکنش است و قابل حذف نیست']);
                break;
            }
        }
        
        $sql = "DELETE FROM company_cards WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $cardId);
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'حساب با موفقیت حذف شد']);
            } else {
                echo json_encode(['success' => false, 'message' => 'خطا در حذف حساب: ' . $stmt->error]);
            }
            $stmt->close();
        } else {
            echo json_encode(['success' => false, 'message' => 'خطا در آماده سازی query']);
        }
        break;
        
    case 'get_card_stats':
        $numberField = $fieldName == 'iban' ? 'iban' : 'card_number';
        $sql = "SELECT 
                    c.id, 
                    c.$numberField as card_number,
                    c.bank_name, 
                    c.card_owner,
                    COUNT(w.id) as transaction_count,
                    SUM(w.amount) as total_amount,
                    SUM(CASE WHEN w.currency = 'IRR' THEN w.amount ELSE 0 END) as total_irr,
                    SUM(CASE WHEN w.currency = 'USD' THEN w.amount ELSE 0 END) as total_usd,
                    SUM(CASE WHEN w.currency = 'EUR' THEN w.amount ELSE 0 END) as total_eur,
                    SUM(CASE WHEN w.currency = 'USDT' THEN w.amount ELSE 0 END) as total_usdt
                FROM company_cards c
                LEFT JOIN withdrawal_requests w ON c.id = w.card_id AND w.status = 'completed'
                WHERE c.is_active = 1
                GROUP BY c.id
                ORDER BY c.id DESC";
        $result = $conn->query($sql);
        $stats = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $stats[] = $row;
            }
        }
        echo json_encode(['success' => true, 'data' => $stats]);
        break;
        
    case 'get_card_transactions':
        $cardId = isset($_GET['card_id']) ? intval($_GET['card_id']) : 0;
        if ($cardId <= 0) {
            echo json_encode(['success' => false, 'message' => 'شناسه حساب نامعتبر است']);
            break;
        }
        
        $sql = "SELECT 
                    w.*, 
                    u.first_name, 
                    u.last_name, 
                    u.telegram_id 
                FROM withdrawal_requests w
                JOIN users u ON w.user_id = u.id
                WHERE w.card_id = ? AND w.status = 'completed'
                ORDER BY w.admin_action_at DESC";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $cardId);
            $stmt->execute();
            $result = $stmt->get_result();
            $transactions = [];
            while ($row = $result->fetch_assoc()) {
                $transactions[] = $row;
            }
            $stmt->close();
            echo json_encode(['success' => true, 'data' => $transactions]);
        } else {
            echo json_encode(['success' => false, 'message' => 'خطا در آماده سازی query']);
        }
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'عملیات نامعتبر']);
}

$conn->close();
?>