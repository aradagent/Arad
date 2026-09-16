<?php
// /ledor/api/friends-api.php
session_start();
header('Content-Type: application/json');

require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit();
}

$userId = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';

// دیباگ لاگ
error_log("Friends API called - Action: $action, User ID: $userId");

switch ($action) {
    case 'test':
        echo json_encode(['status' => 'success', 'message' => 'Friends API is working']);
        break;
        
    case 'get_recent_friends':
        $limit = $_GET['limit'] ?? 10;
        
        $sql = "
            SELECT DISTINCT 
                u.id,
                u.first_name,
                u.last_name,
                CONCAT(u.first_name, ' ', u.last_name) as full_name,
                u.avatar,
                u.account_number,
                u.telegram_id,
                u.phone_number,
                COUNT(t.id) as transaction_count,
                COALESCE(SUM(CASE WHEN t.sender_id = ? THEN t.amount ELSE 0 END), 0) as total_sent,
                COALESCE(SUM(CASE WHEN t.receiver_id = ? THEN t.amount ELSE 0 END), 0) as total_received,
                MAX(t.created_at) as last_transaction_date
            FROM users u
            JOIN transactions t ON (
                (t.sender_id = ? AND t.receiver_id = u.id) OR 
                (t.receiver_id = ? AND t.sender_id = u.id)
            )
            WHERE u.id != ?
            GROUP BY u.id
            ORDER BY last_transaction_date DESC
            LIMIT ?
        ";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log("SQL Error: " . $conn->error);
            echo json_encode(['status' => 'error', 'message' => 'SQL Error: ' . $conn->error]);
            exit();
        }
        
        $stmt->bind_param("iiiiii", $userId, $userId, $userId, $userId, $userId, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $friends = [];
        while ($row = $result->fetch_assoc()) {
            $friends[] = $row;
        }
        
        echo json_encode([
            'status' => 'success', 
            'message' => 'Friends loaded successfully',
            'data' => $friends,
            'count' => count($friends)
        ]);
        break;
        
    case 'get_friend_details':
    case 'get_friend_history':
        $friendId = intval($_GET['friend_id'] ?? 0);
        
        if (!$friendId) {
            echo json_encode(['status' => 'error', 'message' => 'Friend ID required']);
            exit();
        }
        
        error_log("Getting details for friend ID: $friendId, requested by user ID: $userId");
        
        // Get friend info
        $userSql = "SELECT id, first_name, last_name, 
                           CONCAT(first_name, ' ', last_name) as full_name,
                           avatar, account_number, telegram_id, phone_number,
                           email, created_at 
                    FROM users WHERE id = ?";
        $userStmt = $conn->prepare($userSql);
        if (!$userStmt) {
            error_log("User SQL Error: " . $conn->error);
            echo json_encode(['status' => 'error', 'message' => 'User SQL Error']);
            exit();
        }
        
        $userStmt->bind_param("i", $friendId);
        $userStmt->execute();
        $userResult = $userStmt->get_result();
        
        if ($userResult->num_rows === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Friend not found']);
            exit();
        }
        
        $friend = $userResult->fetch_assoc();
        
        // Get transaction stats between current user and friend
        $statsSql = "
            SELECT 
                COUNT(*) as transaction_count,
                COALESCE(SUM(CASE WHEN sender_id = ? THEN amount ELSE 0 END), 0) as total_sent,
                COALESCE(SUM(CASE WHEN receiver_id = ? THEN amount ELSE 0 END), 0) as total_received,
                MIN(created_at) as first_transaction,
                MAX(created_at) as last_transaction
            FROM transactions
            WHERE (sender_id = ? AND receiver_id = ?) 
               OR (receiver_id = ? AND sender_id = ?)
            AND status = 'completed'
        ";
        
        $statsStmt = $conn->prepare($statsSql);
        if (!$statsStmt) {
            error_log("Stats SQL Error: " . $conn->error);
            echo json_encode(['status' => 'error', 'message' => 'Stats SQL Error']);
            exit();
        }
        
        $statsStmt->bind_param("iiiiii", $userId, $userId, $userId, $friendId, $userId, $friendId);
        $statsStmt->execute();
        $statsResult = $statsStmt->get_result();
        $stats = $statsResult->fetch_assoc();
        
        if (!$stats) {
            $stats = [
                'transaction_count' => 0,
                'total_sent' => 0,
                'total_received' => 0,
                'first_transaction' => null,
                'last_transaction' => null
            ];
        }
        
        // Calculate net balance
        $stats['net_balance'] = $stats['total_received'] - $stats['total_sent'];
        
        // Get all transactions between users
        $txSql = "
            SELECT t.*,
                   CASE 
                       WHEN t.sender_id = ? THEN 'sent'
                       WHEN t.receiver_id = ? THEN 'received'
                   END as transaction_type,
                   CASE 
                       WHEN t.sender_id = ? THEN 'to ' || f.first_name
                       WHEN t.receiver_id = ? THEN 'from ' || f.first_name
                   END as direction
            FROM transactions t
            LEFT JOIN users f ON (
                (t.sender_id = ? AND f.id = t.receiver_id) OR 
                (t.receiver_id = ? AND f.id = t.sender_id)
            )
            WHERE (t.sender_id = ? AND t.receiver_id = ?) 
               OR (t.receiver_id = ? AND t.sender_id = ?)
            ORDER BY t.created_at DESC
            LIMIT 20
        ";
        
        $txStmt = $conn->prepare($txSql);
        if (!$txStmt) {
            error_log("Transactions SQL Error: " . $conn->error);
            echo json_encode(['status' => 'error', 'message' => 'Transactions SQL Error']);
            exit();
        }
        
        $txStmt->bind_param("iiiiiiiiii", 
            $userId, $userId, $userId, $userId, 
            $userId, $userId, $userId, $friendId, 
            $userId, $friendId
        );
        $txStmt->execute();
        $txResult = $txStmt->get_result();
        
        $transactions = [];
        while ($row = $txResult->fetch_assoc()) {
            // Format amount based on transaction type
            if ($row['sender_id'] == $userId) {
                $row['amount_display'] = '-' . number_format($row['amount'], 2);
                $row['amount_class'] = 'negative';
            } else {
                $row['amount_display'] = '+' . number_format($row['amount'], 2);
                $row['amount_class'] = 'positive';
            }
            
            // Format date
            $row['formatted_date'] = date('M d, Y', strtotime($row['created_at']));
            $row['formatted_time'] = date('H:i', strtotime($row['created_at']));
            
            $transactions[] = $row;
        }
        
        // Also get recent transactions with other users for comparison
        $recentSql = "
            SELECT COUNT(*) as total_transactions,
                   COUNT(DISTINCT CASE WHEN sender_id = ? THEN receiver_id ELSE sender_id END) as unique_contacts
            FROM transactions
            WHERE sender_id = ? OR receiver_id = ?
        ";
        
        $recentStmt = $conn->prepare($recentSql);
        $recentStmt->bind_param("iii", $userId, $userId, $userId);
        $recentStmt->execute();
        $recentResult = $recentStmt->get_result();
        $recentStats = $recentResult->fetch_assoc();
        
        echo json_encode([
            'status' => 'success',
            'message' => 'Friend details loaded successfully',
            'friend' => $friend,
            'stats' => $stats,
            'transactions' => $transactions,
            'transaction_count' => count($transactions),
            'user_stats' => $recentStats
        ]);
        break;
        
    case 'get_friends_stats':
        $sql = "
            SELECT 
                COUNT(DISTINCT CASE WHEN sender_id = ? THEN receiver_id ELSE sender_id END) as contacts_count,
                COALESCE(SUM(CASE WHEN sender_id = ? THEN amount ELSE 0 END), 0) as total_sent_all,
                COALESCE(SUM(CASE WHEN receiver_id = ? THEN amount ELSE 0 END), 0) as total_received_all,
                COUNT(*) as total_transactions
            FROM transactions
            WHERE sender_id = ? OR receiver_id = ?
        ";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iiiii", $userId, $userId, $userId, $userId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $stats = $result->fetch_assoc();
        
        echo json_encode([
            'status' => 'success',
            'message' => 'Friends stats loaded',
            'stats' => $stats
        ]);
        break;
        
    case 'get_all_friends':
        $sql = "
            SELECT DISTINCT 
                u.id,
                u.first_name,
                u.last_name,
                CONCAT(u.first_name, ' ', u.last_name) as full_name,
                u.avatar,
                u.account_number,
                u.telegram_id,
                COUNT(t.id) as transaction_count,
                MAX(t.created_at) as last_transaction
            FROM users u
            JOIN transactions t ON (
                (t.sender_id = ? AND t.receiver_id = u.id) OR 
                (t.receiver_id = ? AND t.sender_id = u.id)
            )
            WHERE u.id != ?
            GROUP BY u.id
            ORDER BY last_transaction DESC
        ";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iii", $userId, $userId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $friends = [];
        while ($row = $result->fetch_assoc()) {
            $friends[] = $row;
        }
        
        echo json_encode([
            'status' => 'success',
            'message' => 'All friends loaded',
            'data' => $friends,
            'count' => count($friends)
        ]);
        break;
        
    default:
        echo json_encode([
            'status' => 'error', 
            'message' => 'Invalid action. Available actions: test, get_recent_friends, get_friend_details, get_friend_history, get_friends_stats, get_all_friends'
        ]);
}


?>