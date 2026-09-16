<?php
require_once '../config/database.php';
require_once __DIR__ . '/../includes/notify_helper.php';

header('Content-Type: application/json');
$response = ['success' => false, 'message' => ''];

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $response['message'] = 'Not authenticated';
    echo json_encode($response);
    exit();
}

try {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    $userId = $_SESSION['user_id'];
    $action = $_GET['action'] ?? ($data['action'] ?? '');
    
    switch ($action) {
        case 'validateRecipient':
            // Validate a specific recipient
            $recipient = $conn->real_escape_string($_GET['recipient'] ?? '');
            
            if (empty($recipient)) {
                $response['message'] = 'Recipient is required';
                break;
            }
            
            $sql = "SELECT id, first_name, last_name, account_number, telegram_id, avatar 
                   FROM users 
                   WHERE account_number = ? OR telegram_id = ?
                   LIMIT 1";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ss", $recipient, $recipient);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                
                // Check if trying to send to self
                if ($user['id'] == $userId) {
                    $response['message'] = 'Cannot send money to yourself';
                } else {
                    $response['success'] = true;
                    $response['user'] = [
                        'id' => $user['id'],
                        'first_name' => $user['first_name'],
                        'last_name' => $user['last_name'],
                        'full_name' => $user['first_name'] . ' ' . $user['last_name'],
                        'account_number' => $user['account_number'],
                        'telegram_id' => $user['telegram_id'],
                        'avatar' => $user['avatar']
                    ];
                    $response['message'] = 'User found';
                }
            } else {
                $response['message'] = 'User not found';
            }
            break;
            
        case 'send':
            // Send money to another user
            $amount = floatval($data['amount'] ?? 0);
            $recipient = $conn->real_escape_string($data['recipient'] ?? '');
            $description = $conn->real_escape_string($data['description'] ?? '');
            $currency = $conn->real_escape_string($data['currency'] ?? 'USD');
            
            if ($amount <= 0) {
                $response['message'] = 'Invalid amount';
                break;
            }
            
            if (empty($recipient)) {
                $response['message'] = 'Recipient is required';
                break;
            }
            
            // Validate currency
            $validCurrencies = ['USD', 'EUR', 'USDT', 'IRR'];
            if (!in_array($currency, $validCurrencies)) {
                $response['message'] = 'Invalid currency';
                break;
            }
            
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Get sender's balance
                $balanceField = 'balance_' . strtolower($currency);
                $sql = "SELECT $balanceField, account_number, first_name, last_name FROM users WHERE id = ? FOR UPDATE";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $userId);
                $stmt->execute();
                $senderResult = $stmt->get_result();
                
                if ($senderResult->num_rows !== 1) {
                    throw new Exception('Sender not found');
                }
                
                $sender = $senderResult->fetch_assoc();
                $senderBalance = $sender[$balanceField];
                
                if ($senderBalance < $amount) {
                    throw new Exception('Insufficient balance');
                }
                
                // Find recipient by account number or Telegram ID
                $recipientSql = "SELECT id, $balanceField, telegram_id, first_name, last_name FROM users 
                               WHERE account_number = ? OR telegram_id = ?";
                $recipientStmt = $conn->prepare($recipientSql);
                $recipientStmt->bind_param("ss", $recipient, $recipient);
                $recipientStmt->execute();
                $recipientResult = $recipientStmt->get_result();
                
                if ($recipientResult->num_rows !== 1) {
                    throw new Exception('Recipient not found');
                }
                
                $recipientData = $recipientResult->fetch_assoc();
                $recipientId = $recipientData['id'];
                
                // Update sender's balance
                $newSenderBalance = $senderBalance - $amount;
                $updateSenderSql = "UPDATE users SET $balanceField = ?, updated_at = NOW() WHERE id = ?";
                $updateSenderStmt = $conn->prepare($updateSenderSql);
                $updateSenderStmt->bind_param("di", $newSenderBalance, $userId);
                $updateSenderStmt->execute();
                
                // Update recipient's balance
                $recipientBalance = $recipientData[$balanceField];
                $newRecipientBalance = $recipientBalance + $amount;
                $updateRecipientSql = "UPDATE users SET $balanceField = ?, updated_at = NOW() WHERE id = ?";
                $updateRecipientStmt = $conn->prepare($updateRecipientSql);
                $updateRecipientStmt->bind_param("di", $newRecipientBalance, $recipientId);
                $updateRecipientStmt->execute();
                
                // Generate transaction ID
                $transactionId = 'TX' . time() . rand(1000, 9999);
                
                // Record transaction
                $transactionSql = "INSERT INTO transactions (
                    transaction_id, 
                    sender_id, 
                    receiver_id, 
                    amount, 
                    currency, 
                    type, 
                    description, 
                    status, 
                    created_at
                ) VALUES (?, ?, ?, ?, ?, 'send', ?, 'completed', NOW())";
                
                $transactionStmt = $conn->prepare($transactionSql);
                $transactionStmt->bind_param(
                    "siidss",
                    $transactionId,
                    $userId,
                    $recipientId,
                    $amount,
                    $currency,
                    $description
                );
                $transactionStmt->execute();
                
                // Add to contacts
                $contactSql = "INSERT IGNORE INTO user_contacts (user_id, contact_id, last_transaction_date) 
                             VALUES (?, ?, NOW())";
                
                // Add recipient to sender's contacts
                $contactStmt1 = $conn->prepare($contactSql);
                $contactStmt1->bind_param("ii", $userId, $recipientId);
                $contactStmt1->execute();
                
                // Add sender to recipient's contacts
                $contactStmt2 = $conn->prepare($contactSql);
                $contactStmt2->bind_param("ii", $recipientId, $userId);
                $contactStmt2->execute();
                
                // ============ سیستم نوتیفیکیشن ============
                // (اصلاح) قبلاً اینجا مستقیم در جدول `notifications` درج می‌شد؛
                // اما زنگوله‌ی اعلان و پولینگ ۵ثانیه‌ای اپ فقط از جدول
                // `user_notifications` می‌خوانند (نگاه کنید api/notification_api.php)،
                // پس این اعلان‌ها هرگز در اپ دیده نمی‌شدند — گیرنده هیچ اعلان
                // درون‌اپی از دریافت پول نمی‌دید. حالا از هاب مرکزی نوتیفیکیشن
                // (notifyUser) استفاده می‌شود که در جدول درست می‌نویسد. کانال‌های
                // push/telegram/email عمداً اینجا خاموش‌اند چون چند خط پایین‌تر
                // برای همین رویداد به‌طور جداگانه (و از قبل کارآمد) ارسال می‌شوند
                // — تا پیامک/پوش/تلگرام دوبار ارسال نشود.
                $newTxnId = $conn->insert_id;
                if (function_exists('notifyUser')) {
                    @notifyUser($conn, $userId, '💰 Money Sent',
                        "You sent $amount $currency to " . $recipientData['first_name'] . " " . $recipientData['last_name'],
                        ['type' => 'sent', 'related_id' => $newTxnId, 'url' => '/ledor/transactions.php',
                         'db' => true, 'push' => false, 'telegram' => false, 'email' => false]);
                    @notifyUser($conn, $recipientId, '💰 Money Received',
                        "You received $amount $currency from " . ($_SESSION['first_name'] . ' ' . $_SESSION['last_name']),
                        ['type' => 'received', 'related_id' => $newTxnId, 'url' => '/ledor/transactions.php',
                         'db' => true, 'push' => false, 'telegram' => false, 'email' => false]);
                }
                // ============ پایان سیستم نوتیفیکیشن ============
                
                // Commit transaction
                $conn->commit();
                
                $response['success'] = true;
                $response['message'] = 'Transaction completed successfully';
                $response['transaction_id'] = $transactionId;
                $response['notification_sent'] = true;
                
                // Send Telegram notifications
                $senderName = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];
                $recipientName = $recipientData['first_name'] . ' ' . $recipientData['last_name'];
                
                // Notification to sender
                $senderMessage = "📤 Sent {$amount} {$currency} to {$recipientName}\n💳 Transaction ID: {$transactionId}";
                sendTelegramMessage($_SESSION['telegram_id'], $senderMessage);
                
                // Notification to recipient
                $recipientMessage = "📥 Received {$amount} {$currency} from {$senderName}\n💳 Transaction ID: {$transactionId}";
                sendTelegramMessage($recipientData['telegram_id'], $recipientMessage);

                // ===== Push + Email به گیرنده (نوتیفیکیشن واقعی حتی اگر اپ بسته باشد) =====
                if (function_exists('sendPushToUser')) {
                    sendPushToUser($conn, $recipientId, '💰 پول دریافت کردید',
                        "شما {$amount} {$currency} از {$senderName} دریافت کردید",
                        'money_received', '/ledor/transactions.php', $newTxnId);
                }
                if (function_exists('sendEmailNotification')) {
                    sendEmailNotification($conn, $recipientId, 'پول دریافت کردید',
                        "شما مبلغ {$amount} {$currency} از {$senderName} دریافت کردید. کد تراکنش: {$transactionId}");
                }
                
                // Notification to admin
                $adminMessage = "💰 Transaction Completed:\nFrom: {$senderName}\nTo: {$recipientName}\nAmount: {$amount} {$currency}\nID: {$transactionId}";
                sendTelegramMessage(ADMIN_TELEGRAM_ID, $adminMessage);
                
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
            break;
            
        case 'withdraw':
            // Handle withdrawal request
            $amount = floatval($data['amount'] ?? 0);
            $currency = $conn->real_escape_string($data['currency'] ?? 'USD');
            $iban = $conn->real_escape_string($data['iban'] ?? '');
            $bankName = $conn->real_escape_string($data['bank_name'] ?? '');
            $recipientName = $conn->real_escape_string($data['recipient_name'] ?? '');
            
            if ($amount <= 0) {
                $response['message'] = 'Invalid amount';
                break;
            }
            
            if (empty($iban) || empty($bankName) || empty($recipientName)) {
                $response['message'] = 'Bank details are required';
                break;
            }
            
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Check user balance
                $balanceField = 'balance_' . strtolower($currency);
                $sql = "SELECT $balanceField FROM users WHERE id = ? FOR UPDATE";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $userId);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows !== 1) {
                    throw new Exception('User not found');
                }
                
                $user = $result->fetch_assoc();
                $userBalance = $user[$balanceField];
                
                if ($userBalance < $amount) {
                    throw new Exception('Insufficient balance');
                }
                
                // Deduct from balance
                $newBalance = $userBalance - $amount;
                $updateSql = "UPDATE users SET $balanceField = ?, updated_at = NOW() WHERE id = ?";
                $updateStmt = $conn->prepare($updateSql);
                $updateStmt->bind_param("di", $newBalance, $userId);
                $updateStmt->execute();
                
                // Generate withdrawal ID
                $withdrawalId = 'WD' . time() . rand(1000, 9999);
                
                // Record withdrawal
                $withdrawalSql = "INSERT INTO transactions (
                    transaction_id,
                    sender_id,
                    amount,
                    currency,
                    type,
                    description,
                    status,
                    created_at
                ) VALUES (?, ?, ?, ?, 'withdrawal', ?, 'pending', NOW())";
                
                $description = "Withdrawal to $bankName - $recipientName";
                $withdrawalStmt = $conn->prepare($withdrawalSql);
                $withdrawalStmt->bind_param(
                    "sidds",
                    $withdrawalId,
                    $userId,
                    $amount,
                    $currency,
                    $description
                );
                $withdrawalStmt->execute();
                
                // ============ نوتیفیکیشن برداشت ============
                $notificationSql = "INSERT INTO notifications (
                    user_id, 
                    type, 
                    title, 
                    message, 
                    data
                ) VALUES (?, 'transaction', ?, ?, ?)";
                
                $notificationStmt = $conn->prepare($notificationSql);
                $notificationTitle = "🏧 Withdrawal Request";
                $notificationMessage = "Withdrawal of $amount $currency has been submitted and is pending approval";
                $notificationData = json_encode([
                    'transaction_id' => $withdrawalId,
                    'amount' => $amount,
                    'currency' => $currency,
                    'type' => 'withdrawal',
                    'status' => 'pending',
                    'bank_name' => $bankName,
                    'timestamp' => time()
                ]);
                $notificationStmt->bind_param("isss", $userId, $notificationTitle, $notificationMessage, $notificationData);
                $notificationStmt->execute();
                // ============ پایان نوتیفیکیشن برداشت ============
                
                // Commit transaction
                $conn->commit();
                
                $response['success'] = true;
                $response['message'] = 'Withdrawal request submitted successfully';
                $response['withdrawal_id'] = $withdrawalId;
                $response['notification_sent'] = true;
                
                // Send Telegram notification to user
                $userMessage = "🏧 Withdrawal Request:\nAmount: {$amount} {$currency}\nBank: {$bankName}\nStatus: Pending\nID: {$withdrawalId}";
                sendTelegramMessage($_SESSION['telegram_id'], $userMessage);
                
                // Send Telegram notification to admin
                $adminMessage = "🏧 New Withdrawal Request:\nUser: {$_SESSION['first_name']} {$_SESSION['last_name']}\nAmount: {$amount} {$currency}\nBank: {$bankName}\nID: {$withdrawalId}";
                sendTelegramMessage(ADMIN_TELEGRAM_ID, $adminMessage);
                
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
            break;
            
        case 'deposit':
            // Handle deposit request
            $amount = floatval($data['amount'] ?? 0);
            $currency = $conn->real_escape_string($data['currency'] ?? 'USD');
            $paymentMethod = $conn->real_escape_string($data['payment_method'] ?? '');
            $transactionProof = $conn->real_escape_string($data['transaction_proof'] ?? '');
            
            if ($amount <= 0) {
                $response['message'] = 'Invalid amount';
                break;
            }
            
            if (empty($paymentMethod)) {
                $response['message'] = 'Payment method is required';
                break;
            }
            
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Generate deposit ID
                $depositId = 'DP' . time() . rand(1000, 9999);
                
                // Record deposit
                $depositSql = "INSERT INTO transactions (
                    transaction_id,
                    receiver_id,
                    amount,
                    currency,
                    type,
                    description,
                    status,
                    created_at
                ) VALUES (?, ?, ?, ?, 'deposit', ?, 'pending', NOW())";
                
                $description = "Deposit via $paymentMethod";
                $depositStmt = $conn->prepare($depositSql);
                $depositStmt->bind_param(
                    "sidds",
                    $depositId,
                    $userId,
                    $amount,
                    $currency,
                    $description
                );
                $depositStmt->execute();
                
                // ============ نوتیفیکیشن واریز ============
                $notificationSql = "INSERT INTO notifications (
                    user_id, 
                    type, 
                    title, 
                    message, 
                    data
                ) VALUES (?, 'transaction', ?, ?, ?)";
                
                $notificationStmt = $conn->prepare($notificationSql);
                $notificationTitle = "💳 Deposit Request";
                $notificationMessage = "Deposit of $amount $currency has been submitted and is pending verification";
                $notificationData = json_encode([
                    'transaction_id' => $depositId,
                    'amount' => $amount,
                    'currency' => $currency,
                    'type' => 'deposit',
                    'status' => 'pending',
                    'payment_method' => $paymentMethod,
                    'timestamp' => time()
                ]);
                $notificationStmt->bind_param("isss", $userId, $notificationTitle, $notificationMessage, $notificationData);
                $notificationStmt->execute();
                // ============ پایان نوتیفیکیشن واریز ============
                
                // Commit transaction
                $conn->commit();
                
                $response['success'] = true;
                $response['message'] = 'Deposit request submitted successfully';
                $response['deposit_id'] = $depositId;
                $response['notification_sent'] = true;
                
                // Send Telegram notification to user
                $userMessage = "💳 Deposit Request:\nAmount: {$amount} {$currency}\nMethod: {$paymentMethod}\nStatus: Pending\nID: {$depositId}";
                sendTelegramMessage($_SESSION['telegram_id'], $userMessage);
                
                // Send Telegram notification to admin
                $adminMessage = "💳 New Deposit Request:\nUser: {$_SESSION['first_name']} {$_SESSION['last_name']}\nAmount: {$amount} {$currency}\nMethod: {$paymentMethod}\nID: {$depositId}";
                if (!empty($transactionProof)) {
                    $adminMessage .= "\nProof: {$transactionProof}";
                }
                sendTelegramMessage(ADMIN_TELEGRAM_ID, $adminMessage);
                
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
            break;
            
        case 'all':
            // Get all transactions with pagination
            $page = intval($_GET['page'] ?? 1);
            $limit = intval($_GET['limit'] ?? 20);
            $offset = ($page - 1) * $limit;
            
            $sql = "SELECT 
                    t.*,
                    s.first_name as sender_name,
                    s.avatar as sender_avatar,
                    r.first_name as receiver_name,
                    r.avatar as receiver_avatar
                FROM transactions t
                LEFT JOIN users s ON t.sender_id = s.id
                LEFT JOIN users r ON t.receiver_id = r.id
                WHERE t.sender_id = ? OR t.receiver_id = ?
                ORDER BY t.created_at DESC
                LIMIT ? OFFSET ?";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iiii", $userId, $userId, $limit, $offset);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $transactions = [];
            while ($row = $result->fetch_assoc()) {
                $transactions[] = $row;
            }
            
            // Get total count
            $countSql = "SELECT COUNT(*) as total FROM transactions WHERE sender_id = ? OR receiver_id = ?";
            $countStmt = $conn->prepare($countSql);
            $countStmt->bind_param("ii", $userId, $userId);
            $countStmt->execute();
            $countResult = $countStmt->get_result();
            $total = $countResult->fetch_assoc()['total'];
            
            $response['success'] = true;
            $response['transactions'] = $transactions;
            $response['total'] = $total;
            $response['page'] = $page;
            $response['total_pages'] = ceil($total / $limit);
            $response['message'] = 'All transactions retrieved';
            break;
            
        default:
            $response['message'] = 'Invalid action';
            break;
    }
    
} catch (Exception $e) {
    $response['message'] = 'Server error: ' . $e->getMessage();
}

echo json_encode($response);
?>