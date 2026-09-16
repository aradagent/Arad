<?php
// api/deals_api.php - با PUSH Notification برای تکمیل معامله

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/notification_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'لطفاً وارد شوید']);
    exit();
}

$userId = (int)$_SESSION['user_id'];  // FIX: تبدیل به عدد؛ مقایسه === با رشته طرف معامله را اشتباه تشخیص می‌داد
$action = $_GET['action'] ?? '';

$BOT_TOKEN = '5937135973:AAEwK4lxar3xRM_mwvapLWNuw26VUv2c6e4';
$ADMIN_TELEGRAM_ID = '5330629504';

// بررسی ادمین (هماهنگ با پنل مدیریت: is_admin یا آیدی تلگرام ادمین)
$isAdmin = false;
$checkSql = "SELECT telegram_id, is_admin FROM users WHERE id = ?";
$checkStmt = $conn->prepare($checkSql);
$checkStmt->bind_param("i", $userId);
$checkStmt->execute();
$userData = $checkStmt->get_result()->fetch_assoc();
if ($userData) {
    if (
        (isset($userData['is_admin']) && (int)$userData['is_admin'] === 1) ||
        ($userData['telegram_id'] == $ADMIN_TELEGRAM_ID) ||
        ((string)$userId === (string)$ADMIN_TELEGRAM_ID)
    ) {
        $isAdmin = true;
    }
}

// ==================== تکمیل معامله توسط ادمین (با PUSH به هر دو طرف) ====================
if ($action === 'complete_deal' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) {
        echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز']);
        exit();
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $dealId = intval($input['deal_id'] ?? 0);
    
    if ($dealId <= 0) {
        echo json_encode(['success' => false, 'message' => 'شناسه معامله نامعتبر است']);
        exit();
    }
    
    $conn->begin_transaction();
    
    try {
        // اطمینان از وجود ستون‌های موردنیاز (در دیتابیس‌های قدیمی ممکن است نباشند)
        require_once __DIR__ . '/../includes/perf_helpers.php';
        if (avapay_throttled('ad_deals_admin_cols_ensure', 1800)) {
            foreach ([
                'admin_completed' => "ALTER TABLE ad_deals ADD COLUMN admin_completed TINYINT(1) DEFAULT 0",
                'completed_at'    => "ALTER TABLE ad_deals ADD COLUMN completed_at DATETIME DEFAULT NULL",
                'completed_by'    => "ALTER TABLE ad_deals ADD COLUMN completed_by INT DEFAULT NULL",
            ] as $col => $ddl) {
                $chk = $conn->query("SHOW COLUMNS FROM ad_deals LIKE '$col'");
                if ($chk && $chk->num_rows === 0) { @$conn->query($ddl); }
            }
        }

        // دریافت اطلاعات معامله (بدون شرط سخت‌گیرانه‌ی status تا اگر وضعیت کمی متفاوت بود هم پیدا شود)
        $dealSql = "SELECT d.*, 
                    buyer.id as buyer_id, buyer.first_name as buyer_first, buyer.last_name as buyer_last, buyer.telegram_id as buyer_telegram,
                    seller.id as seller_id, seller.first_name as seller_first, seller.last_name as seller_last, seller.telegram_id as seller_telegram,
                    a.currency
                    FROM ad_deals d
                    JOIN users buyer ON d.buyer_id = buyer.id
                    JOIN users seller ON d.seller_id = seller.id
                    JOIN user_ads a ON d.ad_id = a.id
                    WHERE d.id = ?";
        $dealStmt = $conn->prepare($dealSql);
        if (!$dealStmt) { throw new Exception('خطای پایگاه‌داده: ' . $conn->error); }
        $dealStmt->bind_param("i", $dealId);
        $dealStmt->execute();
        $deal = $dealStmt->get_result()->fetch_assoc();
        
        if (!$deal) {
            throw new Exception('معامله یافت نشد');
        }
        if (($deal['status'] ?? '') === 'completed' && (int)($deal['admin_completed'] ?? 0) === 1) {
            $conn->rollback();
            echo json_encode(['success' => true, 'message' => 'این معامله قبلاً تکمیل شده است']);
            exit();
        }
        
        // به‌روزرسانی وضعیت معامله
        $sql = "UPDATE ad_deals SET 
                status = 'completed',
                admin_completed = 1,
                completed_by = ?,
                completed_at = NOW()
                WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) { throw new Exception('خطا در به‌روزرسانی: ' . $conn->error); }
        $stmt->bind_param("ii", $userId, $dealId);
        if (!$stmt->execute()) { throw new Exception('اجرای به‌روزرسانی ناموفق بود: ' . $stmt->error); }
        if ($stmt->affected_rows < 1) { throw new Exception('وضعیت معامله تغییر نکرد'); }
        
        $conn->commit();
        
        // ========== نوتیفیکیشن‌ها (خطای این بخش نباید تکمیل معامله را خراب کند) ==========
        try {
            $pushTitle = "🎉 معامله شما تکمیل شد!";
            $pushBody = "معامله با کد {$deal['deal_code']} به مبلغ " . number_format($deal['total_price']) . " تومان با موفقیت تکمیل شد.";
            sendPushToUser($conn, $deal['buyer_id'], $pushTitle, $pushBody, 'deal_completed', '/ledor/arad.php?tab=completed', $dealId);
            sendPushToUser($conn, $deal['seller_id'], $pushTitle, $pushBody, 'deal_completed', '/ledor/arad.php?tab=completed', $dealId);

            $message = "✅ <b>🎉 معامله شما با موفقیت تکمیل شد!</b>\n\n";
            $message .= "🔑 کد معامله: <code>" . $deal['deal_code'] . "</code>\n";
            $message .= "💰 مبلغ کل: " . number_format($deal['total_price']) . " تومان\n";
            $message .= "💱 ارز: " . $deal['currency'] . "\n";
            $message .= "📊 مقدار: " . number_format($deal['amount']) . "\n";
            if (!empty($deal['buyer_telegram'])) { sendTelegram($deal['buyer_telegram'], $message); }
            if (!empty($deal['seller_telegram']) && $deal['seller_telegram'] != $deal['buyer_telegram']) { sendTelegram($deal['seller_telegram'], $message); }
        } catch (Exception $notifyErr) { /* نادیده گرفتن خطای نوتیف */ }
        
        echo json_encode(['success' => true, 'message' => '✅ معامله با موفقیت تکمیل شد']);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// ==================== معاملات فعال کاربر (در انتظار تسویه) ====================
if ($action === 'get_my_active_deals') {
    // اصلاح سرعت: این بلوک ۱۰ کوئری SHOW COLUMNS جداگانه روی هر بار فراخوانی
    // اجرا می‌کرد — و چون این اکشن هر ۵ ثانیه توسط پولینگ صفحه‌ی معاملات
    // (arad.php) برای *هر* کاربر آنلاین صدا زده می‌شود، یعنی ۱۰ کوئری
    // متادیتای اضافه هر ۵ ثانیه به ازای هر کاربر — سنگین‌ترین مورد پیدا‌شده
    // در این اسکن. حالا حداکثر هر ۳۰ دقیقه یک‌بار بررسی می‌شود.
    require_once __DIR__ . '/../includes/perf_helpers.php';
    if (avapay_throttled('ad_deals_settlement_cols_ensure', 1800)) {
        // اطمینان از وجود ستون‌های تسویه (خوددرمان برای دیتابیس‌های قدیمی)
        $needCols = [
            'buyer_admin_accounts'       => "ALTER TABLE ad_deals ADD COLUMN buyer_admin_accounts TEXT NULL",
            'seller_admin_accounts'      => "ALTER TABLE ad_deals ADD COLUMN seller_admin_accounts TEXT NULL",
            'buyer_receipts'             => "ALTER TABLE ad_deals ADD COLUMN buyer_receipts TEXT NULL",
            'seller_receipts'            => "ALTER TABLE ad_deals ADD COLUMN seller_receipts TEXT NULL",
            'buyer_settlement_receipts'  => "ALTER TABLE ad_deals ADD COLUMN buyer_settlement_receipts TEXT NULL",
            'seller_settlement_receipts' => "ALTER TABLE ad_deals ADD COLUMN seller_settlement_receipts TEXT NULL",
            'buyer_admin_note'           => "ALTER TABLE ad_deals ADD COLUMN buyer_admin_note TEXT NULL",
            'seller_admin_note'          => "ALTER TABLE ad_deals ADD COLUMN seller_admin_note TEXT NULL",
            'buyer_side_status'          => "ALTER TABLE ad_deals ADD COLUMN buyer_side_status VARCHAR(30) DEFAULT 'new'",
            'seller_side_status'         => "ALTER TABLE ad_deals ADD COLUMN seller_side_status VARCHAR(30) DEFAULT 'new'",
        ];
        foreach ($needCols as $c => $ddl) {
            $chk = $conn->query("SHOW COLUMNS FROM ad_deals LIKE '$c'");
            if ($chk && $chk->num_rows === 0) { @$conn->query($ddl); }
        }
    }

    $sql = "SELECT d.*, a.currency, a.type as ad_type,
            buyer.first_name as buyer_first_name, buyer.last_name as buyer_last_name,
            seller.first_name as seller_first_name, seller.last_name as seller_last_name
            FROM ad_deals d
            JOIN user_ads a ON d.ad_id = a.id
            JOIN users buyer ON d.buyer_id = buyer.id
            JOIN users seller ON d.seller_id = seller.id
            WHERE (d.buyer_id = ? OR d.seller_id = ?) AND d.status = 'pending' AND d.admin_completed = 0
            ORDER BY d.created_at DESC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) { echo json_encode(['success' => false, 'message' => 'خطای پایگاه‌داده', 'deals' => []]); exit(); }
    $stmt->bind_param("ii", $userId, $userId);
    $stmt->execute();
    $deals = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // افزودن side_data برای همان کاربر تا کارت مستقیماً شماره‌حساب و فیش‌ها را نشان دهد
    $jarr = function ($v) { $d = json_decode($v ?? '', true); return is_array($d) ? $d : []; };
    foreach ($deals as &$d) {
        $side = ((int)$d['buyer_id'] === $userId) ? 'buyer' : 'seller';
        $p = $side . '_';
        $d['my_side'] = $side;
        $d['side_data'] = [
            'accounts'            => $jarr($d[$p . 'admin_accounts'] ?? ''),
            'receipts'            => $jarr($d[$p . 'receipts'] ?? ''),
            'settlement_receipts' => $jarr($d[$p . 'settlement_receipts'] ?? ''),
            'admin_note'          => $d[$p . 'admin_note'] ?? '',
            'side_status'         => $d[$p . 'side_status'] ?? 'new',
        ];
    }
    unset($d);

    echo json_encode(['success' => true, 'deals' => $deals]);
    exit();
}

// ==================== معاملات تکمیل‌شده کاربر (تاریخچه) ====================
if ($action === 'get_my_completed_deals') {
    $sql = "SELECT d.*, a.currency,
            buyer.first_name as buyer_first_name, buyer.last_name as buyer_last_name,
            seller.first_name as seller_first_name, seller.last_name as seller_last_name
            FROM ad_deals d
            JOIN user_ads a ON d.ad_id = a.id
            JOIN users buyer ON d.buyer_id = buyer.id
            JOIN users seller ON d.seller_id = seller.id
            WHERE (d.buyer_id = ? OR d.seller_id = ?) AND d.status = 'completed'
            ORDER BY d.completed_at DESC, d.created_at DESC LIMIT 30";
    $stmt = $conn->prepare($sql);
    if (!$stmt) { echo json_encode(['success' => false, 'message' => 'خطای پایگاه‌داده', 'deals' => []]); exit(); }
    $stmt->bind_param("ii", $userId, $userId);
    $stmt->execute();
    $deals = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // آمار کل معاملات موفق کاربر
    $statSql = "SELECT COUNT(*) as total_deals FROM ad_deals WHERE (buyer_id = ? OR seller_id = ?) AND status = 'completed'";
    $statStmt = $conn->prepare($statSql);
    $total = 0;
    if ($statStmt) {
        $statStmt->bind_param("ii", $userId, $userId);
        $statStmt->execute();
        $total = (int)($statStmt->get_result()->fetch_assoc()['total_deals'] ?? 0);
    }
    echo json_encode(['success' => true, 'deals' => $deals, 'stats' => ['total_deals' => $total]]);
    exit();
}

// ==================== همه‌ی معاملات فعال (برای ادمین) ====================
if ($action === 'get_all_active_deals') {
    if (!$isAdmin) { echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز', 'deals' => []]); exit(); }
    $sql = "SELECT d.*, a.currency, a.type as ad_type,
            buyer.first_name as buyer_first_name, buyer.last_name as buyer_last_name, buyer.telegram_id as buyer_telegram,
            seller.first_name as seller_first_name, seller.last_name as seller_last_name, seller.telegram_id as seller_telegram
            FROM ad_deals d
            JOIN user_ads a ON d.ad_id = a.id
            JOIN users buyer ON d.buyer_id = buyer.id
            JOIN users seller ON d.seller_id = seller.id
            WHERE d.status = 'pending' AND d.admin_completed = 0
            ORDER BY d.created_at DESC";
    $res = $conn->query($sql);
    $deals = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    echo json_encode(['success' => true, 'deals' => $deals]);
    exit();
}

function sendTelegram($chatId, $message) {
    if (empty($chatId)) return false;
    global $BOT_TOKEN;
    $url = "https://api.telegram.org/bot{$BOT_TOKEN}/sendMessage";
    
    $postData = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    curl_close($ch);
    return true;
}

echo json_encode(['success' => false, 'message' => 'اکشن نامعتبر']);
?>