<?php
// api/check-auth.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// 🚨 **اول سشن ماندگار را شروع کن - بسیار مهم!**
require_once __DIR__ . '/../includes/session_boot.php';

require_once '../config/database.php';

// اگر سشن خالی بود، از روی کوکی auth_token بازسازی کن (لاگین ماندگار)
avapay_restore_session_from_token($conn);

/**
 * برای رفع مشکلات احراز هویت، از این استراتژی استفاده می‌کنیم:
 * 1. اول سشن PHP را چک می‌کنیم (اگر کاربر از طریق لاگین معمولی وارد شده)
 * 2. سپس توکن را چک می‌کنیم (برای API/JavaScript)
 * 3. اگر هیچکدام نبود، کاربر لاگین نکرده است
 */

// متغیرهای پاسخ
$authenticated = false;
$user = null;
$auth_method = 'none';

// **روش 1: چک کردن سشن PHP (اولویت اصلی)**
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    $user_id = intval($_SESSION['user_id']);
    
    $stmt = $conn->prepare("SELECT id, telegram_id, first_name, last_name, avatar, 
                                   balance_usd, balance_eur, balance_usdt, balance_irr,
                                   account_number, iban_number, phone_number, created_at,
                                   is_admin, last_login
                            FROM users WHERE id = ?");
    
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            $authenticated = true;
            $auth_method = 'session';
            
            // به‌روزرسانی زمان آخرین فعالیت
            $_SESSION['last_activity'] = time();

            // ثبت آخرین بازدید (برای وضعیت آنلاین در پنل ادمین) — حداکثر هر ۶۰ ثانیه یک‌بار
            if (!isset($_SESSION['last_seen_write']) || (time() - $_SESSION['last_seen_write']) > 60) {
                // ستون last_seen را فقط اگر واقعاً وجود ندارد بساز.
                // (mysqli در PHP 8 خطا را Exception پرتاب می‌کند و @ جلوی آن را نمی‌گیرد)
                try {
                    if (empty($_SESSION['avapay_presence_col'])) {
                        $__c = $conn->query("SHOW COLUMNS FROM `users` LIKE 'last_seen'");
                        if ($__c && $__c->num_rows === 0) {
                            try { $conn->query("ALTER TABLE `users` ADD COLUMN `last_seen` DATETIME NULL DEFAULT NULL"); } catch (Throwable $e) {}
                        }
                        $_SESSION['avapay_presence_col'] = 1;
                    }
                    $conn->query("UPDATE users SET last_seen = NOW() WHERE id = " . intval($user['id']));
                } catch (Throwable $e) { error_log('check-auth presence: ' . $e->getMessage()); }
                $_SESSION['last_seen_write'] = time();
            }
        }
        $stmt->close();
    }
}

// **روش 2: چک کردن توکن (اگر سشن وجود نداشت)**
if (!$authenticated) {
    $token = null;
    
    // دریافت توکن از هدر Authorization
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
        if (preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
            $token = trim($matches[1]);
        }
    }
    
    // یا از کوکی
    if (!$token && isset($_COOKIE['auth_token'])) {
        $token = trim($_COOKIE['auth_token']);
    }
    
    // یا از پارامتر GET (فقط برای دیباگ)
    if (!$token && isset($_GET['token']) && !empty($_GET['token'])) {
        $token = trim($_GET['token']);
    }
    
    if ($token) {
        // استراتژی 2.1: چک کردن جدول sessions (اگر وجود دارد)
        $checkTableQuery = "SHOW TABLES LIKE 'sessions'";
        $tableResult = $conn->query($checkTableQuery);
        
        if ($tableResult && $tableResult->num_rows > 0) {
            $stmt = $conn->prepare("SELECT u.*, s.expires_at 
                                   FROM users u
                                   JOIN sessions s ON u.id = s.user_id 
                                   WHERE s.token = ? AND s.expires_at > NOW()");
            
            if ($stmt) {
                $stmt->bind_param("s", $token);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $user = $result->fetch_assoc();
                    unset($user['password']);
                    unset($user['expires_at']);
                    
                    $authenticated = true;
                    $auth_method = 'token_session';
                    
                    // سشن PHP را نیز تنظیم کن
                    if (session_status() === PHP_SESSION_NONE) {
                        session_start();
                    }
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['last_activity'] = time();

                    if (!isset($_SESSION['last_seen_write']) || (time() - $_SESSION['last_seen_write']) > 60) {
                        try {
                            if (empty($_SESSION['avapay_presence_col'])) {
                                $__c2 = $conn->query("SHOW COLUMNS FROM `users` LIKE 'last_seen'");
                                if ($__c2 && $__c2->num_rows === 0) {
                                    try { $conn->query("ALTER TABLE `users` ADD COLUMN `last_seen` DATETIME NULL DEFAULT NULL"); } catch (Throwable $e) {}
                                }
                                $_SESSION['avapay_presence_col'] = 1;
                            }
                            $conn->query("UPDATE users SET last_seen = NOW(), last_login = IFNULL(last_login, NOW()) WHERE id = " . intval($user['id']));
                        } catch (Throwable $e) { error_log('check-auth presence: ' . $e->getMessage()); }
                        $_SESSION['last_seen_write'] = time();
                    }
                }
                $stmt->close();
            }
        } else {
            // استراتژی 2.2: چک مستقیم کاربر از طریق telegram_id
            $stmt = $conn->prepare("SELECT id, telegram_id, first_name, last_name, avatar, 
                                           balance_usd, balance_eur, balance_usdt, balance_irr,
                                           account_number, iban_number, phone_number, created_at,
                                           is_admin, last_login
                                    FROM users WHERE telegram_id = ?");
            
            if ($stmt) {
                $stmt->bind_param("s", $token);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $user = $result->fetch_assoc();
                    $authenticated = true;
                    $auth_method = 'token_direct';
                    
                    // سشن PHP را تنظیم کن
                    if (session_status() === PHP_SESSION_NONE) {
                        session_start();
                    }
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['telegram_id'] = $user['telegram_id'];
                    $_SESSION['last_activity'] = time();
                }
                $stmt->close();
            }
        }
    }
}

// **پاسخ نهایی**
if ($authenticated && $user) {
    // اطلاعات اضافی برای پاسخ
    $response = [
        'authenticated' => true,
        'user' => $user,
        'auth_method' => $auth_method,
        'session_id' => session_id(),
        'has_php_session' => isset($_SESSION['user_id']),
        'message' => 'Authenticated successfully'
    ];
    
    echo json_encode($response);
    
    // اگر کاربر احراز هویت شده، سشن را ثبت کن (لاگ)
    error_log("User authenticated: ID={$user['id']}, Method={$auth_method}, Session=" . session_id());
    
} else {
    // کاربر لاگین نکرده
    http_response_code(401);
    
    $response = [
        'authenticated' => false,
        'message' => 'Not authenticated',
        'session_active' => isset($_SESSION['user_id']),
        'session_id' => session_id() ?: 'none',
        'debug_info' => [
            'php_session' => session_status(),
            'session_user_id' => isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'not_set',
            'auth_header' => isset($_SERVER['HTTP_AUTHORIZATION']) ? 'present' : 'missing',
            'cookie_auth' => isset($_COOKIE['auth_token']) ? 'present' : 'missing',
            'session_vars' => isset($_SESSION) ? array_keys($_SESSION) : []
        ]
    ];
    
    echo json_encode($response);
    
    // لاگ برای دیباگ
    error_log("Authentication failed. Session status: " . session_status() . 
              ", Session user_id: " . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'not_set'));
}

// بستن اتصال
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
?>