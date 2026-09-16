<?php
// api/login.php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/session_boot.php';
require_once '../config/database.php';


// دریافت داده‌ها از هر دو روش GET و POST
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $telegram_id = $_GET['telegram_id'] ?? '';
} else {
    $data = json_decode(file_get_contents('php://input'), true);
    $telegram_id = $data['telegram_id'] ?? '';
}


// دریافت داده‌ها
$data = json_decode(file_get_contents('php://input'), true);
$telegram_id = $data['telegram_id'] ?? '';

if (empty($telegram_id)) {
    echo json_encode(['success' => false, 'message' => 'Telegram ID is required']);
    exit();
}

try {
    // بررسی وجود کاربر
    $stmt = $conn->prepare("SELECT * FROM users WHERE telegram_id = ?");
    $stmt->bind_param("s", $telegram_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        // کاربر وجود دارد
        $user = $result->fetch_assoc();

        // ---- کاربر مسدود اصلاً نباید بتواند وارد شود ----
        // این سومین درِ ورود است (کنار گاردِ سراسری و بازسازی سشن از روی توکن).
        if (!empty($user['is_banned'])) {
            $__banReason = trim((string)($user['ban_reason'] ?? ''));
            $__banMsg = 'حساب کاربری شما مسدود شده است و امکان ورود وجود ندارد.';
            if ($__banReason !== '') { $__banMsg .= ' دلیل: ' . $__banReason; }
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'banned'  => true,
                'code'    => 'ACCOUNT_BANNED',
                'message' => $__banMsg,
                'reason'  => $__banReason,
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }

        // تولید توکن
        $token = bin2hex(random_bytes(32));
        $expires_at = date('Y-m-d H:i:s', strtotime('+30 days'));
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        // ذخیره توکن در جدول sessions
        $sessionStmt = $conn->prepare("
            INSERT INTO sessions (user_id, token, expires_at, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            token = VALUES(token), 
            expires_at = VALUES(expires_at),
            ip_address = VALUES(ip_address),
            user_agent = VALUES(user_agent)
        ");
        
        $sessionStmt->bind_param("issss", $user['id'], $token, $expires_at, $ip_address, $user_agent);
        $sessionStmt->execute();

        // ثبت زمان آخرین ورود برای پنل ادمین
        $conn->query("UPDATE users SET last_login = NOW() WHERE id = " . intval($user['id']));

        // تنظیم سشن PHP ماندگار بلافاصله پس از ورود
        $_SESSION['user_id']       = intval($user['id']);
        $_SESSION['telegram_id']   = $user['telegram_id'] ?? '';
        $_SESSION['last_activity'] = time();

        // تنظیم کوکی auth_token سمت سرور (۳۰ روز) به‌عنوان پشتیبان لاگین ماندگار
        $secureCookie = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        );
        @setcookie('auth_token', $token, [
            'expires'  => time() + (defined('AVAPAY_SESSION_LIFETIME') ? AVAPAY_SESSION_LIFETIME : 60 * 60 * 24 * 30),
            'path'     => '/',
            'secure'   => $secureCookie,
            'httponly' => false, // JS هم برای check-auth به آن نیاز دارد
            'samesite' => 'Lax',
        ]);

        // حذف اطلاعات حساس
        unset($user['password']);
        
        echo json_encode([
            'success' => true,
            'token' => $token,
            'user' => $user,
            'user_id' => $user['id'],
            'needs_registration' => false,
            'message' => 'Login successful'
        ]);
        
    } else {
        // کاربر جدید
        echo json_encode([
            'success' => true,
            'needs_registration' => true,
            'message' => 'New user needs registration'
        ]);
    }
    
} catch (Exception $e) {
    // خطای عمومی
    error_log("Login error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage(),
        'needs_registration' => false
    ]);
}
?>