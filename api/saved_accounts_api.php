<?php
// api/saved_accounts_api.php
// مدیریت «حساب‌های ذخیره‌شده» ادمین: حساب‌هایی که یک‌بار تعریف می‌شوند و در ارسال
// شماره‌حساب به کاربران (حواله ارزی / تسویه معاملات) با یک کلیک استفاده می‌شوند،
// بدون نیاز به وارد کردن دوباره‌ی نام و شماره کارت هر بار.

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

// بررسی ادمین بودن (همان الگوی سایر API های ادمین)
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

if (!$isAdmin) {
    echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز. فقط ادمین']);
    exit();
}

// ایجاد جدول در صورت نبود
$conn->query("CREATE TABLE IF NOT EXISTS `admin_saved_accounts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `card` VARCHAR(60) NOT NULL,
    `account_number` VARCHAR(60) DEFAULT NULL,
    `iban` VARCHAR(60) DEFAULT NULL,
    `bank_name` VARCHAR(100) DEFAULT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `is_active` TINYINT DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
// خودترمیمی: اگر جدول قبلاً با نسخه‌ی قدیمی (بدون این ستون‌ها) ساخته شده، ستون‌های جدید اضافه شود
foreach (['account_number' => "VARCHAR(60) DEFAULT NULL", 'iban' => "VARCHAR(60) DEFAULT NULL", 'bank_name' => "VARCHAR(100) DEFAULT NULL"] as $col => $ddl) {
    $chk = $conn->query("SHOW COLUMNS FROM admin_saved_accounts LIKE '$col'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query("ALTER TABLE admin_saved_accounts ADD COLUMN `$col` $ddl");
    }
}

$action = $_GET['action'] ?? '';

switch ($action) {

    case 'list':
        $sql = "SELECT id, name, card, account_number, iban, bank_name, description, is_active, created_at
                FROM admin_saved_accounts
                ORDER BY is_active DESC, id DESC";
        $result = $conn->query($sql);
        $accounts = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $accounts[] = $row;
            }
        }
        echo json_encode(['success' => true, 'data' => $accounts]);
        break;

    case 'add':
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        if (!$data) {
            echo json_encode(['success' => false, 'message' => 'داده ارسال نشده است']);
            break;
        }

        $name = trim($data['name'] ?? '');
        $card = trim($data['card'] ?? '');
        $accountNumber = trim($data['account_number'] ?? '');
        $iban = trim($data['iban'] ?? '');
        $bankName = trim($data['bank_name'] ?? '');
        $description = trim($data['description'] ?? '');

        if (empty($name)) {
            echo json_encode(['success' => false, 'message' => 'نام صاحب حساب الزامی است']);
            break;
        }
        if (empty($card) && empty($accountNumber) && empty($iban)) {
            echo json_encode(['success' => false, 'message' => 'حداقل یکی از شماره کارت، شماره حساب یا شبا الزامی است']);
            break;
        }

        $sql = "INSERT INTO admin_saved_accounts (name, card, account_number, iban, bank_name, description, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("ssssss", $name, $card, $accountNumber, $iban, $bankName, $description);
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'حساب ذخیره شد', 'id' => $conn->insert_id]);
            } else {
                echo json_encode(['success' => false, 'message' => 'خطا در ذخیره حساب: ' . $stmt->error]);
            }
            $stmt->close();
        } else {
            echo json_encode(['success' => false, 'message' => 'خطا در آماده‌سازی query']);
        }
        break;

    case 'update':
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        if (!$data) {
            echo json_encode(['success' => false, 'message' => 'داده ارسال نشده است']);
            break;
        }

        $id = intval($data['id'] ?? 0);
        $name = trim($data['name'] ?? '');
        $card = trim($data['card'] ?? '');
        $accountNumber = trim($data['account_number'] ?? '');
        $iban = trim($data['iban'] ?? '');
        $bankName = trim($data['bank_name'] ?? '');
        $description = trim($data['description'] ?? '');
        $isActive = isset($data['is_active']) ? intval($data['is_active']) : 1;

        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'شناسه حساب نامعتبر است']);
            break;
        }
        if (empty($name)) {
            echo json_encode(['success' => false, 'message' => 'نام صاحب حساب الزامی است']);
            break;
        }
        if (empty($card) && empty($accountNumber) && empty($iban)) {
            echo json_encode(['success' => false, 'message' => 'حداقل یکی از شماره کارت، شماره حساب یا شبا الزامی است']);
            break;
        }

        $sql = "UPDATE admin_saved_accounts SET name=?, card=?, account_number=?, iban=?, bank_name=?, description=?, is_active=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("ssssssii", $name, $card, $accountNumber, $iban, $bankName, $description, $isActive, $id);
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'حساب ویرایش شد']);
            } else {
                echo json_encode(['success' => false, 'message' => 'خطا در ویرایش حساب: ' . $stmt->error]);
            }
            $stmt->close();
        } else {
            echo json_encode(['success' => false, 'message' => 'خطا در آماده‌سازی query']);
        }
        break;

    case 'delete':
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        if (!$data) {
            echo json_encode(['success' => false, 'message' => 'داده ارسال نشده است']);
            break;
        }

        $id = intval($data['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'شناسه حساب نامعتبر است']);
            break;
        }

        $sql = "DELETE FROM admin_saved_accounts WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'حساب حذف شد']);
            } else {
                echo json_encode(['success' => false, 'message' => 'خطا در حذف حساب: ' . $stmt->error]);
            }
            $stmt->close();
        } else {
            echo json_encode(['success' => false, 'message' => 'خطا در آماده‌سازی query']);
        }
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'عملیات نامعتبر']);
}

$conn->close();
