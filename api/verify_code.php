<?php
// api/verify_code.php
require_once __DIR__ . '/../includes/session_boot.php';   // سشن ماندگار ۳۰ روزه (باید قبل از session_start باشد)
require_once '../config/database.php';
header('Content-Type: application/json');

// شروع سشن (اگر session_boot آن را استارت نکرده باشد)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// دریافت ورودی
$input = file_get_contents('php://input');
$data = json_decode($input, true);
$code = trim($data['code'] ?? '');


if (empty($code)) {
    echo json_encode(['success' => false, 'message' => 'Verification code is required']);
    exit();
}

// بررسی وجود سشن verification
if (!isset($_SESSION['verification'])) {
    echo json_encode(['success' => false, 'message' => 'No verification in progress. Please login again.']);
    exit();
}

$verification = $_SESSION['verification'];

// بررسی انقضا
if (time() > $verification['expires']) {
    unset($_SESSION['verification']);
    echo json_encode(['success' => false, 'message' => 'Verification code has expired. Please request a new code.']);
    exit();
}

// محدودیت تعداد تلاش (جلوگیری از حدس‌زدن کد با تلاش‌های پیاپی)
$maxAttempts = 5;
$attempts = (int)($verification['attempts'] ?? 0);
if ($attempts >= $maxAttempts) {
    unset($_SESSION['verification']);
    echo json_encode(['success' => false, 'message' => 'تعداد تلاش‌های مجاز به پایان رسید. لطفاً دوباره کد بگیرید.']);
    exit();
}

// بررسی صحت کد
if ($code !== $verification['code']) {
    $_SESSION['verification']['attempts'] = $attempts + 1;
    $remaining = $maxAttempts - ($attempts + 1);
    if ($remaining <= 0) {
        unset($_SESSION['verification']);
        echo json_encode(['success' => false, 'message' => 'تعداد تلاش‌های مجاز به پایان رسید. لطفاً دوباره کد بگیرید.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid verification code. Please try again.']);
    }
    exit();
}

$telegram_id = $verification['telegram_id'];

try {
    if ($verification['is_new_user']) {
        // کاربر جدید - نیاز به ثبت‌نام
        unset($_SESSION['verification']);
        echo json_encode([
            'success' => true,
            'needs_registration' => true,
            'telegram_id' => $telegram_id,
            'message' => 'Code verified. Please complete registration.'
        ]);
    } else {
        // کاربر موجود - لاگین
        $stmt = $conn->prepare("SELECT * FROM users WHERE telegram_id = ?");
        $stmt->bind_param("s", $telegram_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'User not found']);
            exit();
        }
        
        $user = $result->fetch_assoc();
        
        // تولید توکن
        $token = bin2hex(random_bytes(32));
        $expires_at = date('Y-m-d H:i:s', strtotime('+30 days'));
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        // بررسی وجود جدول sessions
        $checkTable = $conn->query("SHOW TABLES LIKE 'sessions'");
        if ($checkTable->num_rows === 0) {
            // ایجاد جدول sessions
            $conn->query("CREATE TABLE IF NOT EXISTS sessions (
                id INT PRIMARY KEY AUTO_INCREMENT,
                user_id INT NOT NULL,
                token VARCHAR(255) NOT NULL,
                expires_at DATETIME NOT NULL,
                ip_address VARCHAR(45),
                user_agent TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_token (token),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )");
        }
        
        // ذخیره توکن
        $sessionStmt = $conn->prepare("INSERT INTO sessions (user_id, token, expires_at, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
        $sessionStmt->bind_param("issss", $user['id'], $token, $expires_at, $ip_address, $user_agent);
        
        if (!$sessionStmt->execute()) {
            // اگر توکن تکراری بود، آپدیت کن
            $sessionStmt = $conn->prepare("UPDATE sessions SET token = ?, expires_at = ?, ip_address = ?, user_agent = ? WHERE user_id = ?");
            $sessionStmt->bind_param("ssssi", $token, $expires_at, $ip_address, $user_agent, $user['id']);
            $sessionStmt->execute();
        }
        
        // بروزرسانی آخرین لاگین
        $conn->query("UPDATE users SET last_login = NOW() WHERE id = {$user['id']}");
        
        // ذخیره در سشن PHP
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['telegram_id'] = $user['telegram_id'];
        $_SESSION['first_name'] = $user['first_name'];
        $_SESSION['last_name'] = $user['last_name'];
        $_SESSION['last_activity'] = time();

        // ---------------------------------------------------------------
        // کوکی auth_token ماندگار (۳۰ روزه)
        // بدون این کوکی، مرورگرهای درون‌برنامه‌ای (مثل مرورگر تلگرام) که
        // کوکی سشن را کوتاه‌مدت نگه می‌دارند، کاربر را هر بار بیرون می‌اندازند.
        // با این کوکی، session_boot.php سشن را از روی توکن بازسازی می‌کند.
        // ---------------------------------------------------------------
        $secureCookie = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        );
        $cookieLife = defined('AVAPAY_SESSION_LIFETIME') ? AVAPAY_SESSION_LIFETIME : 60 * 60 * 24 * 30;
        @setcookie('auth_token', $token, [
            'expires'  => time() + $cookieLife,
            'path'     => '/',
            'secure'   => $secureCookie,
            'httponly' => false, // JS (check-auth) هم به آن نیاز دارد
            'samesite' => 'Lax',
        ]);

        // پاک کردن verification از سشن
        unset($_SESSION['verification']);
        
        // حذف اطلاعات حساس
        unset($user['password']);
        
        echo json_encode([
            'success' => true,
            'needs_registration' => false,
            'token' => $token,
            'user' => $user,
            'user_id' => $user['id'],
            'message' => 'Login successful'
        ]);
    }
} catch (Exception $e) {
    error_log("Verify code error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>