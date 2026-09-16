<?php
// api/referral_api.php
// AJAX API برای بخش «زیرمجموعه‌های شما» در صفحه‌ی تبادل ارزی + پنل ادمین

error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/session_boot.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/referral_system.php';

if (!$conn || $conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'خطا در اتصال به دیتابیس']);
    exit();
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'لطفاً وارد شوید']);
    exit();
}

$userId = (int)$_SESSION['user_id'];

function ava_ref_is_admin($conn, $userId) {
    $ADMIN_TELEGRAM_ID = '5330629504';
    $st = $conn->prepare("SELECT is_admin, telegram_id FROM users WHERE id = ?");
    $st->bind_param("i", $userId);
    $st->execute();
    $u = $st->get_result()->fetch_assoc();
    if (!$u) return false;
    return ($u['is_admin'] == 1 || $u['telegram_id'] == $ADMIN_TELEGRAM_ID);
}

$action = $_GET['action'] ?? '';
$in = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    switch ($action) {

        case 'my_summary': {
            $stats = ava_ref_my_stats($conn, $userId);
            $wallet = $conn->query("SELECT balance_eur FROM users WHERE id = " . $userId)->fetch_assoc();
            $settings = ava_ref_settings($conn);
            echo json_encode([
                'success'  => true,
                'stats'    => $stats,
                'wallet_balance_eur' => round((float)($wallet['balance_eur'] ?? 0), 2),
                'min_settlement_eur' => $settings['min_settlement_eur'],
                'welcome_bonus_eur'  => $settings['welcome_bonus_eur'],
                'commission_per_tx_eur' => $settings['commission_per_tx_eur'],
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'my_referrals': {
            $list = ava_ref_my_referrals_list($conn, $userId);
            echo json_encode(['success' => true, 'items' => $list], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'my_chart': {
            $days = (int)($_GET['days'] ?? 30);
            $chart = ava_ref_earnings_chart($conn, $userId, $days);
            echo json_encode(['success' => true, 'chart' => $chart], JSON_UNESCAPED_UNICODE);
            break;
        }

        // ------------------- ادمین -------------------
        case 'admin_get_settings': {
            if (!ava_ref_is_admin($conn, $userId)) { echo json_encode(['success' => false, 'message' => 'دسترسی ادمین لازم است']); break; }
            echo json_encode(['success' => true, 'settings' => ava_ref_settings($conn)], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'admin_save_settings': {
            if (!ava_ref_is_admin($conn, $userId)) { echo json_encode(['success' => false, 'message' => 'دسترسی ادمین لازم است']); break; }
            $ok = ava_ref_admin_save_settings(
                $conn,
                $in['welcome_bonus_eur'] ?? 0,
                $in['commission_per_tx_eur'] ?? 0,
                $in['min_settlement_eur'] ?? 5
            );
            echo json_encode(['success' => (bool)$ok, 'settings' => ava_ref_settings($conn)], JSON_UNESCAPED_UNICODE);
            break;
        }

        case 'admin_list': {
            if (!ava_ref_is_admin($conn, $userId)) { echo json_encode(['success' => false, 'message' => 'دسترسی ادمین لازم است']); break; }
            $search = $_GET['q'] ?? '';
            $rows = ava_ref_admin_list($conn, $search);
            echo json_encode(['success' => true, 'items' => $rows], JSON_UNESCAPED_UNICODE);
            break;
        }

        default:
            echo json_encode(['success' => false, 'message' => 'اکشن نامعتبر است']);
    }
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'خطای سرور: ' . $e->getMessage()]);
}

$conn->close();
