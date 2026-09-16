<?php
// api/send_verification_code.php
require_once '../config/database.php';
header('Content-Type: application/json');

// شروع سشن
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// دریافت ورودی
$input = file_get_contents('php://input');
$data = json_decode($input, true);
$telegram_id = trim($data['telegram_id'] ?? '');

// لاگ برای دیباگ
error_log("Send verification request for: " . $telegram_id);

if (empty($telegram_id)) {
    echo json_encode(['success' => false, 'message' => 'Telegram ID is required']);
    exit();
}

try {
    // بررسی وجود کاربر
    $stmt = $conn->prepare("SELECT id, first_name, last_name FROM users WHERE telegram_id = ?");
    $stmt->bind_param("s", $telegram_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $isNewUser = ($result->num_rows === 0);
    $userName = $isNewUser ? 'User' : ($result->fetch_assoc()['first_name'] ?? 'User');
    
    // تولید کد 6 رقمی
    $verificationCode = sprintf("%06d", mt_rand(0, 999999));
    
    // ذخیره در سشن
    $_SESSION['verification'] = [
        'telegram_id' => $telegram_id,
        'code' => $verificationCode,
        'expires' => time() + 300, // 5 دقیقه
        'is_new_user' => $isNewUser
    ];
    
    // ارسال از طریق تلگرام
    $message = "🔐 <b>Verification Code for AvaPay</b>\n\n";
    $message .= "👤 Dear $userName\n\n";
    $message .= "📱 Your verification code is:\n";
    $message .= "<b><code>$verificationCode</code></b>\n\n";
    $message .= "⏰ This code expires in 5 minutes.\n";
    $message .= "🔒 Never share this code with anyone.";
    
    // تابع sendTelegramMessage باید در database.php تعریف شده باشد
    if (function_exists('sendTelegramMessage')) {
        $result = sendTelegramMessage($telegram_id, $message);
    } else {
        // ارسال مستقیم
        $botToken = '5937135973:AAEwK4lxar3xRM_mwvapLWNuw26VUv2c6e4';
        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
        
        $postData = [
            'chat_id' => $telegram_id,
            'text' => $message,
            'parse_mode' => 'HTML'
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $result = ($httpCode === 200);
    }
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Verification code sent to your Telegram',
            'requires_verification' => true
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to send verification code. Please check your Telegram ID and make sure you have started the bot.'
        ]);
    }
    
} catch (Exception $e) {
    error_log("Send verification error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>