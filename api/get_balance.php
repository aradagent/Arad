<?php
// api/get_balance.php
// موجودی کیف‌پول کاربر را برمی‌گرداند — توسط dashboard.php برای رفرش خودکار
// موجودی‌ها (بعد از تکمیل Top-up و غیره) فراخوانی می‌شود.
// خروجی: { success:true, balances:{ USD:.., EUR:.., USDT:.., IRR:.. } }

error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/config/database.php';

if (!$conn || $conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'خطا در اتصال به دیتابیس']);
    exit();
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'ابتدا وارد شوید']);
    exit();
}

// توجه امنیتی: موجودی فقط برای کاربر لاگین‌شده برگردانده می‌شود؛ حتی اگر
// user_id دیگری در query string بیاید، نادیده گرفته می‌شود تا امکان
// مشاهده‌ی موجودی سایر کاربران وجود نداشته باشد.
$userId = $_SESSION['user_id'];

$sql = "SELECT balance_usd, balance_eur, balance_usdt, balance_irr FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'خطا در آماده‌سازی query']);
    exit();
}
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    echo json_encode(['success' => false, 'message' => 'کاربر یافت نشد']);
    exit();
}

$row = $result->fetch_assoc();

echo json_encode([
    'success'  => true,
    'balances' => [
        'USD'  => (float)($row['balance_usd'] ?? 0),
        'EUR'  => (float)($row['balance_eur'] ?? 0),
        'USDT' => (float)($row['balance_usdt'] ?? 0),
        'IRR'  => (float)($row['balance_irr'] ?? 0),
    ],
]);

$stmt->close();
$conn->close();
