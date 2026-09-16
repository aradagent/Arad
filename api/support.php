<?php
// ==================== 1. Initial setup ====================
error_reporting(E_ALL);
ini_set('display_errors', 0); // Disable in production
require_once __DIR__ . '/../config/database.php';

// ==================== 2. Bot settings ====================
define('SUPPORT_BOT_TOKEN', '5937135973:AAEwK4lxar3xRM_mwvapLWNuw26VUv2c6e4');
define('ADMIN_CHAT_ID', ADMIN_TELEGRAM_ID);
define('SUPPORT_ONLINE', true);

// Session start if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ==================== 3. Helper functions ====================
function sendToTelegram($chatId, $text = '', $filePath = null, $replyToMessageId = null, $replyMarkup = null) {
    $botToken = SUPPORT_BOT_TOKEN;
    $url = "https://api.telegram.org/bot{$botToken}/";
    
    $postFields = [
        'chat_id' => $chatId,
        'reply_to_message_id' => $replyToMessageId,
    ];
    
    if ($filePath && file_exists($filePath)) {
        $url .= 'sendDocument';
        $postFields['document'] = new CURLFile($filePath);
        if (!empty($text)) {
            $postFields['caption'] = $text;
        }
    } else {
        $url .= 'sendMessage';
        $postFields['text'] = $text;
        $postFields['parse_mode'] = 'HTML';
    }
    
    if ($replyMarkup) {
        $postFields['reply_markup'] = json_encode($replyMarkup);
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $response = curl_exec($ch);
    curl_close($ch);

    // برگرداندن message_id تلگرام (برای تطبیق پاسخِ ادمین)
    $decoded = json_decode($response, true);
    if (is_array($decoded) && !empty($decoded['ok']) && isset($decoded['result']['message_id'])) {
        return $decoded['result']['message_id'];
    }
    return $response;
}

function downloadTelegramFile($fileId) {
    $botToken = SUPPORT_BOT_TOKEN;
    $url = "https://api.telegram.org/bot{$botToken}/getFile?file_id={$fileId}";
    $response = file_get_contents($url);
    $data = json_decode($response, true);
    if (!$data['ok']) return null;
    
    $filePath = $data['result']['file_path'];
    $fileUrl = "https://api.telegram.org/file/bot{$botToken}/{$filePath}";
    
    $localDir = avapay_upload_dir('chat');
    
    $ext = pathinfo($filePath, PATHINFO_EXTENSION);
    $localFile = $localDir . uniqid('chat_') . '.' . $ext;
    file_put_contents($localFile, file_get_contents($fileUrl));
    
    return 'uploads/chat/' . basename($localFile);
}

// تابع جدید برای دریافت اطلاعات کاربر
function getUserNameById($userId) {
    global $conn;
    $stmt = $conn->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    if ($user) {
        $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        return !empty($name) ? $name : 'کاربر شماره ' . $userId;
    }
    return 'کاربر شماره ' . $userId;
}

// ==================== 4. Telegram Webhook ====================
$input = file_get_contents('php://input');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($input)) {
    $update = json_decode($input, true);
    
    if (isset($update['callback_query'])) {
        $callback = $update['callback_query'];
        $data = $callback['data'];
        $message = $callback['message'];
        $chatId = $message['chat']['id'];
        $messageId = $message['message_id'];
        
        if (strpos($data, 'reply_to_') === 0) {
            $userId = intval(substr($data, 9));
            $userName = getUserNameById($userId);
            $forceReply = ['force_reply' => true, 'selective' => true];
            $text = "✍️ در حال پاسخ به: {$userName}\n\nپاسخ خود را ارسال کنید:";
            $promptMsgId = sendToTelegram($chatId, $text, null, $messageId, $forceReply);
            
            if ($promptMsgId) {
                $stmt = $conn->prepare("INSERT INTO admin_reply_prompts (user_id, prompt_message_id) VALUES (?, ?)");
                $stmt->bind_param("ii", $userId, $promptMsgId);
                $stmt->execute();
            }
        }
        
        $botToken = SUPPORT_BOT_TOKEN;
        $__ctx = stream_context_create(['http' => ['timeout' => 8], 'https' => ['timeout' => 8]]);
        @file_get_contents("https://api.telegram.org/bot{$botToken}/answerCallbackQuery?callback_query_id={$callback['id']}", false, $__ctx);
        exit;
    }
    
    if (isset($update['message'])) {
        $msg = $update['message'];
        $chatId = $msg['chat']['id'];
        $text = $msg['text'] ?? '';
        $caption = $msg['caption'] ?? '';
        $replyTo = $msg['reply_to_message']['message_id'] ?? null;
        
        if ($chatId == ADMIN_CHAT_ID && $replyTo) {
            $stmt = $conn->prepare("SELECT user_id FROM admin_reply_prompts WHERE prompt_message_id = ? LIMIT 1");
            $stmt->bind_param("i", $replyTo);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                $userId = $row['user_id'];
                $del = $conn->prepare("DELETE FROM admin_reply_prompts WHERE prompt_message_id = ?");
                $del->bind_param("i", $replyTo);
                $del->execute();
            } else {
                $stmt2 = $conn->prepare("SELECT user_id FROM chat_messages WHERE telegram_message_id = ? AND sender = 'user' LIMIT 1");
                $stmt2->bind_param("i", $replyTo);
                $stmt2->execute();
                $result2 = $stmt2->get_result();
                if ($row2 = $result2->fetch_assoc()) {
                    $userId = $row2['user_id'];
                } else {
                    exit;
                }
            }
        }
        // حالت جایگزین: ادمین بدون «reply» مستقیماً پیام می‌فرستد → به آخرین کاربری که پیام داده می‌رود
        elseif ($chatId == ADMIN_CHAT_ID && !$replyTo && ($text !== '' || isset($msg['document']) || isset($msg['photo'])) && strpos($text, '/') !== 0) {
            $recent = $conn->query("SELECT user_id FROM chat_messages WHERE sender = 'user' ORDER BY created_at DESC LIMIT 1");
            $rr = $recent ? $recent->fetch_assoc() : null;
            if ($rr) { $userId = $rr['user_id']; } else { exit; }
        }

        if (isset($userId) && $userId && $chatId == ADMIN_CHAT_ID) {
            
            $messageText = '';
            $filePath = null;
            $fileName = null;
            $fileSize = null;
            
            if (isset($msg['document'])) {
                $fileId = $msg['document']['file_id'];
                $fileName = $msg['document']['file_name'];
                $fileSize = $msg['document']['file_size'];
                $filePath = downloadTelegramFile($fileId);
                $messageText = $caption ?: '';
            } elseif (isset($msg['photo'])) {
                $photos = $msg['photo'];
                $largest = end($photos);
                $fileId = $largest['file_id'];
                $fileName = 'photo.jpg';
                $fileSize = $largest['file_size'];
                $filePath = downloadTelegramFile($fileId);
                $messageText = $caption ?: '';
            } else {
                $messageText = $text;
            }
            
            $insStmt = $conn->prepare("INSERT INTO chat_messages (user_id, sender, message, file_path, file_name, file_size, created_at) VALUES (?, 'admin', ?, ?, ?, ?, NOW())");
            $insStmt->bind_param("isssi", $userId, $messageText, $filePath, $fileName, $fileSize);
            $insStmt->execute();

            // اطلاع به کاربر: هم در اپ (polling) و هم در تلگرام اگر آیدی داشته باشد
            $uRow = $conn->query("SELECT telegram_id, first_name FROM users WHERE id = " . intval($userId) . " LIMIT 1");
            $uData = $uRow ? $uRow->fetch_assoc() : null;
            if ($uData && !empty($uData['telegram_id'])) {
                $notifyText = "💬 <b>پاسخ پشتیبانی</b>\n\n";
                if ($messageText) $notifyText .= $messageText . "\n";
                if ($filePath)    $notifyText .= "📎 فایل پیوست ارسال شد.\n";
                $fullFilePath = $filePath ? __DIR__ . '/../' . $filePath : null;
                // اگر فایل هست همان فایل را برای کاربر بفرست، وگرنه فقط متن
                sendToTelegram($uData['telegram_id'], $notifyText, $fullFilePath);
            }

            // تأیید برای ادمین
            sendToTelegram(ADMIN_CHAT_ID, "✅ پاسخ شما برای کاربر ارسال شد.");
        }
    }
    
    http_response_code(200);
    echo 'OK';
    exit;
}

// ==================== 5. Internal API ====================
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['error' => 'Not authenticated']);
        exit;
    }
    
    $userId = $_SESSION['user_id'];
    $action = $_GET['action'];
    
    if ($action === 'unread_count') {
        // تعداد پیام‌های خوانده‌نشده‌ی ارسالی از طرف پشتیبانی
        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM chat_messages WHERE user_id = ? AND sender = 'admin' AND is_read = 0");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $c = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        echo json_encode(['success' => true, 'unread' => $c]);
        exit;
    }

    if ($action === 'get_messages') {
        $lastId = isset($_GET['last_id']) ? intval($_GET['last_id']) : 0;
        $stmt = $conn->prepare("SELECT * FROM chat_messages WHERE user_id = ? AND id > ? ORDER BY created_at ASC");
        $stmt->bind_param("ii", $userId, $lastId);
        $stmt->execute();
        $result = $stmt->get_result();
        $messages = [];
        while ($row = $result->fetch_assoc()) {
            $messages[] = $row;
        }

        // اگر کاربر واقعاً پنل گفتگو را باز کرده (mark=1)، پیام‌های پشتیبانی را خوانده‌شده کن
        if (!empty($_GET['mark'])) {
            $mk = $conn->prepare("UPDATE chat_messages SET is_read = 1 WHERE user_id = ? AND sender = 'admin' AND is_read = 0");
            $mk->bind_param("i", $userId);
            $mk->execute();
        }

        $un = $conn->prepare("SELECT COUNT(*) AS c FROM chat_messages WHERE user_id = ? AND sender = 'admin' AND is_read = 0");
        $un->bind_param("i", $userId);
        $un->execute();
        $unread = (int)($un->get_result()->fetch_assoc()['c'] ?? 0);

        echo json_encode(['messages' => $messages, 'unread' => $unread]);
        exit;
    }
    
    if ($action === 'send_message') {
        $text = $_POST['message'] ?? '';
        $file = $_FILES['file'] ?? null;
        
        if (empty($text) && !$file) {
            echo json_encode(['error' => 'No message or file']);
            exit;
        }
        
        $filePath = null;
        $fileName = null;
        $fileSize = null;
        if ($file && $file['error'] === UPLOAD_ERR_OK) {
            $uploadDir = avapay_upload_dir('chat');
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $newName = uniqid('user_') . '.' . $ext;
            $destination = $uploadDir . $newName;
            if (move_uploaded_file($file['tmp_name'], $destination)) {
                $filePath = 'uploads/chat/' . $newName;
                $fileName = $file['name'];
                $fileSize = $file['size'];
            }
        }
        
        $stmt = $conn->prepare("INSERT INTO chat_messages (user_id, sender, message, file_path, file_name, file_size, created_at) VALUES (?, 'user', ?, ?, ?, ?, NOW())");
        $stmt->bind_param("isssi", $userId, $text, $filePath, $fileName, $fileSize);
        $stmt->execute();
        $messageId = $stmt->insert_id;
        
        // دریافت نام کاربر
        $userName = getUserNameById($userId);
        
        $adminText = "📩 <b>پیام جدید از کاربر</b>\n\n";
        $adminText .= "👤 <b>نام:</b> {$userName}\n";
        $adminText .= "🆔 <b>کد کاربری:</b> #{$userId}\n";
        $adminText .= "━━━━━━━━━━━━━━━━\n";
        
        if ($text) {
            $adminText .= "💬 <b>متن پیام:</b>\n{$text}\n\n";
        }
        if ($filePath) {
            $adminText .= "📎 <b>فایل:</b> {$fileName}\n";
        }
        
        $adminText .= "\n⬇️ <b>برای پاسخ کلیک کنید</b> ⬇️";
        
        $inlineKeyboard = [
            'inline_keyboard' => [[
                ['text' => '✍️ پاسخ به ' . $userName, 'callback_data' => 'supportreply ' . $userId]
            ]]
        ];
        
        $fullPath = $filePath ? __DIR__ . '/../' . $filePath : null;
        $tgMsgId = sendToTelegram(ADMIN_CHAT_ID, $adminText, $fullPath, null, $inlineKeyboard);
        
        if ($tgMsgId) {
            $upd = $conn->prepare("UPDATE chat_messages SET telegram_message_id = ? WHERE id = ?");
            $upd->bind_param("ii", $tgMsgId, $messageId);
            $upd->execute();
        }
        
        echo json_encode(['success' => true, 'message_id' => $messageId]);
        exit;
    }
    
    if ($action === 'mark_read') {
        $stmt = $conn->prepare("UPDATE chat_messages SET is_read = 1 WHERE user_id = ? AND sender = 'admin' AND is_read = 0");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        echo json_encode(['success' => true]);
        exit;
    }
    
    echo json_encode(['error' => 'Invalid action']);
    exit;
}

// ==================== 6. Display chat page ====================
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'menu';

if ($mode === 'chat') {
    $stmt = $conn->prepare("SELECT * FROM chat_messages WHERE user_id = ? ORDER BY created_at ASC");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

if ($mode === 'files') {
    $filesStmt = $conn->prepare("SELECT * FROM chat_messages WHERE user_id = ? AND sender = 'admin' AND file_path IS NOT NULL ORDER BY created_at DESC");
    $filesStmt->bind_param("i", $userId);
    $filesStmt->execute();
    $files = $filesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function formatChatTime($timestamp) {
    $date = new DateTime($timestamp);
    $now = new DateTime();
    $diff = $now->diff($date);
    if ($diff->days == 0) {
        return $date->format('H:i');
    } elseif ($diff->days == 1) {
        return 'دیروز ' . $date->format('H:i');
    } else {
        return $date->format('Y/m/d H:i');
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
    <meta name="theme-color" content="#7C3AED">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="manifest" href="/ledor/manifest.php">
    <title>پشتیبانی آنلاین - AvaPay</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        * {
            -webkit-tap-highlight-color: transparent;
        }
        
        body {
            margin: 0;
            padding: 0;
            background: linear-gradient(135deg, #0F0F1A 0%, #1A1A2E 100%);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Helvetica Neue', sans-serif;
            color: #FFFFFF;
            height: 100vh;
            overflow: hidden;
            position: fixed;
            width: 100%;
        }
        
        .dashboard {
            height: 100%;
            display: flex;
            flex-direction: column;
            position: relative;
            background: linear-gradient(295deg, rgb(43 26 63), rgb(44 28 63));
        }
        
        .header {
            background: rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(20px);
            padding: 12px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .chat-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: transparent;
            overflow: hidden;
            position: relative;
            height: 100%;
        }
        
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            -webkit-overflow-scrolling: touch;
        }
        
        .chat-messages::-webkit-scrollbar {
            width: 4px;
        }
        
        .chat-messages::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
        }
        
        .chat-messages::-webkit-scrollbar-thumb {
            background: rgba(124, 58, 237, 0.5);
            border-radius: 4px;
        }
        
        .message {
            display: flex;
            flex-direction: column;
            max-width: 85%;
            animation: fadeInUp 0.3s ease;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .message.user {
            align-self: flex-end;
        }
        
        .message.admin {
            align-self: flex-start;
        }
        
        .message-bubble {
            padding: 10px 14px;
            border-radius: 20px;
            word-wrap: break-word;
            word-break: break-word;
            position: relative;
        }
        
        .message.user .message-bubble {
            background: linear-gradient(135deg, #7C3AED, #9F67FF);
            color: white;
            border-bottom-right-radius: 4px;
        }
        
        .message.admin .message-bubble {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-bottom-left-radius: 4px;
        }
        
        .message-meta {
            font-size: 0.65rem;
            color: rgba(255, 255, 255, 0.5);
            margin-top: 4px;
            padding: 0 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .file-attachment {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 6px;
            padding: 8px 12px;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 12px;
            text-decoration: none;
            color: inherit;
            transition: all 0.2s;
        }
        
        .file-attachment:active {
            transform: scale(0.98);
        }
        
        .chat-input-area {
            background: rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(20px);
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            padding: 12px 16px;
            padding-bottom: max(12px, env(safe-area-inset-bottom));
            display: flex;
            gap: 8px;
            align-items: flex-end;
            position: relative;
            z-index: 10;
        }
        
        .input-wrapper {
            flex: 1;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 24px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: all 0.2s;
        }
        
        .input-wrapper:focus-within {
            border-color: #7C3AED;
            background: rgba(255, 255, 255, 0.12);
        }
        
        .chat-input {
            width: 100%;
            background: transparent;
            border: none;
            padding: 10px 16px;
            color: white;
            font-size: 16px;
            font-family: inherit;
            resize: none;
            outline: none;
            max-height: 100px;
            line-height: 1.4;
        }
        
        .chat-input::placeholder {
            color: rgba(255, 255, 255, 0.5);
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        
        .chat-send-btn, .chat-attach-btn {
            background: #7C3AED;
            border: none;
            color: white;
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            flex-shrink: 0;
        }
        
        .chat-attach-btn {
            background: rgba(255, 255, 255, 0.1);
        }
        
        .chat-send-btn:active, .chat-attach-btn:active {
            transform: scale(0.95);
        }
        
        .file-preview {
            display: none;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            margin: 0 16px;
            background: rgba(0, 0, 0, 0.5);
            border-radius: 12px;
            position: relative;
            z-index: 10;
        }
        
        .file-preview.show {
            display: flex;
        }
        
        .menu-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 20px;
            gap: 16px;
            max-width: 400px;
            margin: 0 auto;
            width: 100%;
        }
        
        .menu-card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 24px;
            padding: 24px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            color: inherit;
        }
        
        .menu-card:active {
            transform: scale(0.98);
            background: rgba(255, 255, 255, 0.08);
        }
        
        .online-indicator {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 12px;
            padding: 6px 12px;
            background: rgba(0, 0, 0, 0.3);
            border-radius: 50px;
        }
        
        .online-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #4CD964;
            box-shadow: 0 0 8px #4CD964;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .offline-dot {
            background: #FF3B30;
            animation: none;
        }
        
        .no-messages {
            text-align: center;
            color: rgba(255, 255, 255, 0.4);
            padding: 40px;
        }
        
        .files-container {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
        }
        
        .file-item {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        @media (max-width: 768px) {
            .message {
                max-width: 90%;
            }
            
            .menu-card {
                padding: 20px;
            }
            
            input, textarea, select {
                font-size: 16px !important;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <div class="header">
            <div class="header-right">
                <i class="fas fa-headset"></i>
                <span>پشتیبانی</span>
            </div>
            <div class="header-left">
                <?php if ($mode === 'chat' || $mode === 'files'): ?>
                    <a href="?mode=menu" style="color: white; text-decoration: none;">
                        <i class="fas fa-arrow-right"></i>
                    </a>
                <?php else: ?>
                    <a href="../arad.php" style="color: white; text-decoration: none;">
                        <i class="fas fa-arrow-left"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($mode === 'menu'): ?>
            <div class="menu-container">
                <a href="?mode=chat" class="menu-card">
                    <i class="fas fa-comments" style="font-size: 48px; color: #7C3AED;"></i>
                    <h3 style="margin: 12px 0 8px;">ارسال فیش پرداختی</h3>
                    <p style="color: rgba(255,255,255,0.6); margin: 0;">گفتگوی آنلاین با پشتیبانی</p>
                    <div class="online-indicator">
                        <span class="online-dot <?php echo SUPPORT_ONLINE ? '' : 'offline-dot'; ?>"></span>
                        <span style="font-size: 12px;"><?php echo SUPPORT_ONLINE ? 'آنلاین' : 'آفلاین'; ?></span>
                    </div>
                </a>
                <a href="?mode=files" class="menu-card">
                    <i class="fas fa-folder-open" style="font-size: 48px; color: #7C3AED;"></i>
                    <h3 style="margin: 12px 0 8px;">دانلود فیش تسویه</h3>
                    <p style="color: rgba(255,255,255,0.6); margin: 0;">دریافت رسیدهای پرداخت</p>
                </a>
            </div>

        <?php elseif ($mode === 'chat'): ?>
            <div class="chat-container">
                <div class="chat-messages" id="chatMessages">
                    <?php if (empty($messages)): ?>
                        <div class="no-messages">
                            <i class="fas fa-comments" style="font-size: 48px; margin-bottom: 16px;"></i>
                            <p>پیامی وجود ندارد</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($messages as $msg): ?>
                            <div class="message <?php echo $msg['sender']; ?>" data-id="<?php echo $msg['id']; ?>">
                                <div class="message-bubble">
                                    <?php if ($msg['message']): ?>
                                        <div><?php echo nl2br(htmlspecialchars($msg['message'])); ?></div>
                                    <?php endif; ?>
                                    <?php if ($msg['file_path']): ?>
                                        <a href="download.php?file=<?php echo urlencode(basename($msg['file_path'])); ?>" target="_blank" class="file-attachment">
                                            <i class="fas fa-paperclip"></i>
                                            <span><?php echo htmlspecialchars($msg['file_name'] ?: 'فایل'); ?></span>
                                        </a>
                                    <?php endif; ?>
                                </div>
                                <div class="message-meta">
                                    <span><?php echo formatChatTime($msg['created_at']); ?></span>
                                    <?php if ($msg['sender'] == 'user' && $msg['is_read']): ?>
                                        <i class="fas fa-check-double" style="color: #4CD964; font-size: 10px;"></i>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <div class="file-preview" id="filePreview">
                    <i class="fas fa-file"></i>
                    <span id="fileName" style="flex: 1; font-size: 13px;"></span>
                    <i class="fas fa-times" onclick="removeSelectedFile()" style="cursor: pointer; padding: 8px;"></i>
                </div>
                
                <div class="chat-input-area">
                    <div class="action-buttons">
                        <label for="fileInput" class="chat-attach-btn">
                            <i class="fas fa-paperclip"></i>
                        </label>
                        <input type="file" id="fileInput" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.txt" style="display: none;">
                    </div>
                    
                    <div class="input-wrapper">
                        <textarea class="chat-input" id="messageInput" placeholder="پیام خود را بنویسید..." rows="1"></textarea>
                    </div>
                    
                    <button class="chat-send-btn" id="sendBtn">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
            </div>

            <script>
                const chatMessages = document.getElementById('chatMessages');
                const messageInput = document.getElementById('messageInput');
                const fileInput = document.getElementById('fileInput');
                const filePreview = document.getElementById('filePreview');
                const fileNameSpan = document.getElementById('fileName');
                const sendBtn = document.getElementById('sendBtn');
                
                let lastMessageId = <?php echo $messages ? end($messages)['id'] : 0; ?>;
                let pollingInterval;
                
                function autoResizeTextarea() {
                    messageInput.style.height = 'auto';
                    const newHeight = Math.min(messageInput.scrollHeight, 100);
                    messageInput.style.height = newHeight + 'px';
                }
                
                messageInput.addEventListener('input', autoResizeTextarea);
                
                async function sendMessage() {
                    const text = messageInput.value.trim();
                    const file = fileInput.files[0];
                    
                    if (!text && !file) return;
                    
                    sendBtn.disabled = true;
                    sendBtn.style.opacity = '0.5';
                    
                    const formData = new FormData();
                    formData.append('message', text);
                    if (file) formData.append('file', file);
                    
                    try {
                        const response = await fetch('?action=send_message', {
                            method: 'POST',
                            body: formData
                        });
                        const result = await response.json();
                        if (result.success) {
                            messageInput.value = '';
                            messageInput.style.height = 'auto';
                            fileInput.value = '';
                            filePreview.classList.remove('show');
                        }
                    } catch (err) {
                        console.error('Error:', err);
                    } finally {
                        sendBtn.disabled = false;
                        sendBtn.style.opacity = '1';
                        messageInput.focus();
                    }
                }
                
                fileInput.addEventListener('change', function() {
                    if (this.files.length > 0) {
                        fileNameSpan.textContent = this.files[0].name;
                        filePreview.classList.add('show');
                    } else {
                        filePreview.classList.remove('show');
                    }
                });
                
                function removeSelectedFile() {
                    fileInput.value = '';
                    filePreview.classList.remove('show');
                }
                
                messageInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        sendMessage();
                    }
                });
                
                sendBtn.addEventListener('click', sendMessage);
                
                async function pollMessages() {
                    try {
                        const response = await fetch(`?action=get_messages&last_id=${lastMessageId}`);
                        const data = await response.json();
                        if (data.messages && data.messages.length > 0) {
                            for (const msg of data.messages) {
                                appendMessage(msg);
                                lastMessageId = Math.max(lastMessageId, msg.id);
                            }
                            scrollToBottom();
                            fetch('?action=mark_read');
                        }
                    } catch (err) {
                        console.error('Polling error', err);
                    }
                }
                
                function appendMessage(msg) {
                    const msgDiv = document.createElement('div');
                    msgDiv.className = `message ${msg.sender}`;
                    msgDiv.setAttribute('data-id', msg.id);
                    
                    const bubble = document.createElement('div');
                    bubble.className = 'message-bubble';
                    
                    if (msg.message) {
                        bubble.innerHTML += `<div>${escapeHtml(msg.message).replace(/\n/g, '<br>')}</div>`;
                    }
                    if (msg.file_path) {
                        bubble.innerHTML += `<a href="download.php?file=${encodeURIComponent(getFileName(msg.file_path))}" target="_blank" class="file-attachment">
                            <i class="fas fa-paperclip"></i>
                            <span>${escapeHtml(msg.file_name || 'فایل')}</span>
                        </a>`;
                    }
                    
                    msgDiv.appendChild(bubble);
                    
                    const meta = document.createElement('div');
                    meta.className = 'message-meta';
                    meta.innerHTML = `<span>${formatTime(msg.created_at)}</span>`;
                    if (msg.sender === 'user' && msg.is_read) {
                        meta.innerHTML += ' <i class="fas fa-check-double" style="color:#4CD964; font-size:10px;"></i>';
                    }
                    msgDiv.appendChild(meta);
                    
                    chatMessages.appendChild(msgDiv);
                }
                
                function getFileName(path) {
                    return path.split('/').pop();
                }
                
                function escapeHtml(text) {
                    const div = document.createElement('div');
                    div.textContent = text;
                    return div.innerHTML;
                }
                
                function formatTime(timestamp) {
                    const d = new Date(timestamp.replace(' ', 'T'));
                    const now = new Date();
                    if (d.toDateString() === now.toDateString()) {
                        return d.toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' });
                    }
                    return d.toLocaleDateString('fa-IR') + ' ' + d.toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' });
                }
                
                function scrollToBottom() {
                    chatMessages.scrollTop = chatMessages.scrollHeight;
                }
                
                setInterval(pollMessages, 2000);
                scrollToBottom();
                
                let originalHeight = window.innerHeight;
                window.addEventListener('resize', () => {
                    if (window.innerHeight < originalHeight) {
                        setTimeout(scrollToBottom, 100);
                    }
                    originalHeight = window.innerHeight;
                });
            </script>

        <?php elseif ($mode === 'files'): ?>
            <div class="files-container">
                <h3 style="margin-bottom: 20px; text-align: center;">📁 فایل‌های دریافتی</h3>
                <?php if (empty($files)): ?>
                    <div class="no-messages">
                        <i class="fas fa-folder-open" style="font-size: 48px; margin-bottom: 16px;"></i>
                        <p>هیچ فایلی دریافت نکرده‌اید</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($files as $file): ?>
                        <div class="file-item">
                            <div class="file-info">
                                <i class="fas fa-file" style="font-size: 24px; color: #7C3AED;"></i>
                                <div style="margin-right: 12px;">
                                    <div style="font-weight: bold;"><?php echo htmlspecialchars($file['file_name'] ?: 'فایل'); ?></div>
                                    <div style="font-size: 11px; color: rgba(255,255,255,0.5);"><?php echo formatChatTime($file['created_at']); ?></div>
                                    <?php if (!empty($file['message'])): ?>
                                        <div style="font-size: 12px; margin-top: 6px; color: rgba(255,255,255,0.7);"><?php echo htmlspecialchars($file['message']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <a href="download.php?file=<?php echo urlencode(basename($file['file_path'])); ?>" class="file-download" style="background: #7C3AED; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; text-decoration: none;">
                                <i class="fas fa-download" style="color: white;"></i>
                            </a>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/sw.js').then(reg => {
            });
        }
    </script>
</body>
</html>