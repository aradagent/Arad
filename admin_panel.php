<?php
// admin_panel.php - نسخه نهایی کامل با مدیریت Top-up + فیش‌های پرداخت نشده
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
require_once 'config/database.php';
require_once __DIR__ . '/includes/referral_system.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// ==================== API HANDLER ====================
if (isset($_GET['ajax_action'])) {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        exit();
    }
    
    $adminId = $_SESSION['user_id'];
    $adminCheck = $conn->query("SELECT is_admin FROM users WHERE id = $adminId");
    if (!$adminCheck->fetch_assoc()['is_admin']) {
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit();
    }
    
    $ajaxAction = $_GET['ajax_action'];
    
    // API 1: دریافت موجودی کاربر
    if ($ajaxAction === 'get_balances') {
        $userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
        if ($userId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
            exit();
        }
        $user = $conn->query("SELECT balance_usd, balance_eur, balance_usdt, balance_irr FROM users WHERE id = $userId")->fetch_assoc();
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found']);
            exit();
        }
        echo json_encode([
            'success' => true,
            'balances' => [
                'USD' => floatval($user['balance_usd']),
                'EUR' => floatval($user['balance_eur']),
                'USDT' => floatval($user['balance_usdt']),
                'IRR' => floatval($user['balance_irr'])
            ]
        ]);
        exit();
    }
    
    // API 2: دریافت تراکنش‌های کاربر
    if ($ajaxAction === 'get_transactions') {
        $userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
        if ($userId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
            exit();
        }
        $sql = "SELECT * FROM transactions WHERE sender_id = $userId OR receiver_id = $userId ORDER BY created_at DESC LIMIT $limit";
        $transactions = [];
        $result = $conn->query($sql);
        while($row = $result->fetch_assoc()) $transactions[] = $row;
        echo json_encode(['success' => true, 'transactions' => $transactions]);
        exit();
    }
    
    // API 3: دریافت پیام‌های چت
    if ($ajaxAction === 'get_chat_messages') {
        $userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
        $lastId = isset($_GET['last_id']) ? intval($_GET['last_id']) : 0;
        if ($userId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
            exit();
        }
        $sql = "SELECT * FROM chat_messages WHERE user_id = $userId AND id > $lastId ORDER BY created_at ASC";
        $messages = [];
        $result = $conn->query($sql);
        while($row = $result->fetch_assoc()) $messages[] = $row;
        echo json_encode(['success' => true, 'messages' => $messages]);
        exit();
    }
    
    // API 4: ارسال پیام چت
    if ($ajaxAction === 'send_chat_message') {
        $userId = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        $message = isset($_POST['message']) ? $conn->real_escape_string($_POST['message']) : '';
        $file = isset($_FILES['file']) ? $_FILES['file'] : null;
        if ($userId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
            exit();
        }
        $filePath = $fileName = $fileSize = null;
        if ($file && $file['error'] === UPLOAD_ERR_OK) {
            $uploadDirAbs = avapay_upload_dir('chat');
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $newName = 'admin_' . time() . '_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $uploadDirAbs . $newName)) {
                $filePath = 'uploads/chat/' . $newName;
                $fileName = $file['name'];
                $fileSize = $file['size'];
            }
        }
        $conn->query("INSERT INTO chat_messages (user_id, sender, message, file_path, file_name, file_size, created_at) VALUES ($userId, 'admin', '$message', " . ($filePath ? "'$filePath'" : "NULL") . ", " . ($fileName ? "'$fileName'" : "NULL") . ", " . ($fileSize ? $fileSize : "NULL") . ", NOW())");
        $userInfo = $conn->query("SELECT telegram_id, first_name FROM users WHERE id = $userId")->fetch_assoc();
        if ($userInfo && !empty($userInfo['telegram_id'])) {
            $adminInfo = $conn->query("SELECT first_name, last_name FROM users WHERE id = $adminId")->fetch_assoc();
            $tgMsg = "📩 New message from support:\n\n" . ($message ?: "A file has been sent to you.") . "\n\nCheck your user panel to view it.";
            sendTelegramMessage($userInfo['telegram_id'], $tgMsg);
        }
        echo json_encode(['success' => true]);
        exit();
    }
    
    // API 5: نشانه‌گذاری پیام‌ها به عنوان خوانده شده
    if ($ajaxAction === 'mark_chat_read') {
        $userId = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        // نکته: باید پیام‌های خودِ کاربر (sender='user') که ادمین الان می‌بیند خوانده‌شده علامت بخورند،
        // نه پیام‌های خودِ ادمین. قبلاً اشتباهاً sender='admin' فیلتر می‌شد و شمارنده/چشمک هرگز صفر نمی‌شد.
        if ($userId > 0) $conn->query("UPDATE chat_messages SET is_read = 1 WHERE user_id = $userId AND sender = 'user' AND is_read = 0");
        echo json_encode(['success' => true]);
        exit();
    }

    // API 6: شمارش پیام‌های خوانده‌نشده به تفکیک کاربر (برای نشانگر لیست «Chat with User»)
    if ($ajaxAction === 'get_unread_chat_counts') {
        $counts = [];
        $r = $conn->query("SELECT user_id, COUNT(*) as cnt FROM chat_messages WHERE sender='user' AND is_read=0 GROUP BY user_id");
        if ($r) { while ($row = $r->fetch_assoc()) { $counts[(int)$row['user_id']] = (int)$row['cnt']; } }
        echo json_encode(['success' => true, 'counts' => $counts]);
        exit();
    }

    // ==================== مسدودسازی کاربر از ارسال هر نوع درخواست ====================
    // اطمینان از وجود ستون‌های لازم (خوددرمان برای دیتابیس‌های قدیمی)
    $__banColCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'is_banned'");
    if ($__banColCheck && $__banColCheck->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN is_banned TINYINT(1) DEFAULT 0");
        $conn->query("ALTER TABLE users ADD COLUMN ban_reason VARCHAR(255) DEFAULT NULL");
        $conn->query("ALTER TABLE users ADD COLUMN banned_at DATETIME DEFAULT NULL");
    }

    // API 7: مسدود / رفع مسدودیت یک کاربر
    if ($ajaxAction === 'toggle_ban') {
        $userId = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        $ban    = isset($_POST['ban']) ? intval($_POST['ban']) : 0;
        $reason = isset($_POST['reason']) ? trim($conn->real_escape_string($_POST['reason'])) : '';
        if ($userId <= 0) { echo json_encode(['success' => false, 'message' => 'کاربر نامعتبر است']); exit(); }

        if ($ban) {
            $conn->query("UPDATE users SET is_banned = 1, ban_reason = '$reason', banned_at = NOW() WHERE id = $userId");
            // توکن‌های ماندگار کاربر فوراً باطل می‌شوند تا مسدودیت بلافاصله اعمال شود
            // و کاربر نتواند با کوکی auth_token روی دستگاه‌های دیگر فعال بماند.
            $conn->query("DELETE FROM sessions WHERE user_id = $userId");
            $msg = 'کاربر مسدود شد. تمام نشست‌های فعالش بسته شد و دیگر هیچ درخواستی از هیچ مسیری نمی‌تواند ارسال کند.';
        } else {
            $conn->query("UPDATE users SET is_banned = 0, ban_reason = NULL, banned_at = NULL WHERE id = $userId");
            $msg = 'مسدودیت کاربر برداشته شد.';
        }
        echo json_encode(['success' => true, 'message' => $msg]);
        exit();
    }

    // API 8: دریافت وضعیت مسدودیت یک کاربر
    if ($ajaxAction === 'get_ban_status') {
        $userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
        if ($userId <= 0) { echo json_encode(['success' => false]); exit(); }
        $r = $conn->query("SELECT is_banned, ban_reason, banned_at FROM users WHERE id = $userId");
        $row = $r ? $r->fetch_assoc() : null;
        echo json_encode(['success' => true, 'is_banned' => (int)($row['is_banned'] ?? 0), 'ban_reason' => $row['ban_reason'] ?? '', 'banned_at' => $row['banned_at'] ?? null]);
        exit();
    }

    // API 9: لیست کاربران مسدودشده
    if ($ajaxAction === 'get_banned_users') {
        $rows = [];
        $r = $conn->query("SELECT id, first_name, last_name, telegram_id, ban_reason, banned_at FROM users WHERE is_banned = 1 ORDER BY banned_at DESC");
        if ($r) { while ($row = $r->fetch_assoc()) { $rows[] = $row; } }
        echo json_encode(['success' => true, 'users' => $rows]);
        exit();
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit();
}

// ==================== MAIN PAGE ====================
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }

$adminId = $_SESSION['user_id'];
$adminCheck = $conn->query("SELECT is_admin FROM users WHERE id = $adminId");
if (!$adminCheck->fetch_assoc()['is_admin']) { header('Location: dashboard.php'); exit(); }

$adminInfo = $conn->query("SELECT first_name, last_name, avatar FROM users WHERE id = $adminId")->fetch_assoc();

// ==================== بک‌آپ خودکار روزانه (بدون نیاز به Cron هاست) ====================
// هر بار که ادمین پنل را باز می‌کند چک می‌شود که آیا ۲۴ ساعت از آخرین بک‌آپ
// گذشته یا نه. اگر بله، بک‌آپ در پس‌زمینه (بعد از ارسال کامل پاسخ به مرورگر
// ادمین، اگر fastcgi_finish_request در دسترس باشد) اجرا می‌شود تا باز شدن
// پنل ادمین کندتر نشود. جزئیات کامل در بالای cron_db_backup.php.
require_once __DIR__ . '/includes/perf_helpers.php';
if (avapay_throttled('daily_db_backup', 86400)) {
    register_shutdown_function(function () use ($conn) {
        if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
        require_once __DIR__ . '/cron_db_backup.php';
        if (function_exists('avapay_run_db_backup')) {
            @avapay_run_db_backup($conn, avapay_backup_dir());
        }
    });
}

$usersResult = $conn->query("SELECT id, first_name, last_name, telegram_id FROM users ORDER BY first_name");

// شمارش پیام‌های خوانده‌نشده‌ی هر کاربر، برای نمایش نشانگر در لیست «Chat with User»
$unreadByUser = [];
$unreadChatRes = $conn->query("SELECT user_id, COUNT(*) as cnt FROM chat_messages WHERE sender='user' AND is_read=0 GROUP BY user_id");
if ($unreadChatRes) {
    while ($ur = $unreadChatRes->fetch_assoc()) { $unreadByUser[(int)$ur['user_id']] = (int)$ur['cnt']; }
}

// ===== CREATE TABLES IF NOT EXISTS =====
$conn->query("CREATE TABLE IF NOT EXISTS `unpaid_invoices` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `currency` ENUM('USD','EUR','USDT','IRR') NOT NULL DEFAULT 'USD',
    `amount` DECIMAL(15,2) NOT NULL,
    `description` TEXT,
    `status` ENUM('pending','approved','paid','finalized','rejected') DEFAULT 'pending',
    `reject_reason` TEXT DEFAULT NULL,
    `bank_name` VARCHAR(255) DEFAULT NULL,
    `account_number` VARCHAR(100) DEFAULT NULL,
    `card_number` VARCHAR(30) DEFAULT NULL,
    `recipient_name` VARCHAR(255) DEFAULT NULL,
    `iban` VARCHAR(100) DEFAULT NULL,
    `receipt_image` VARCHAR(500) DEFAULT NULL,
    `admin_id` INT DEFAULT NULL,
    `admin_final_note` TEXT DEFAULT NULL,
    `admin_final_image` VARCHAR(500) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_status (status)
)");

$conn->query("CREATE TABLE IF NOT EXISTS `topup_requests` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `currency` ENUM('USD','EUR','USDT') NOT NULL DEFAULT 'USD',
    `amount` DECIMAL(15,2) NOT NULL,
    `status` ENUM('pending','approved','waiting_payment','payment_received','completed','rejected','finalized') DEFAULT 'pending',
    `reject_reason` TEXT DEFAULT NULL,
    `bank_name` VARCHAR(255) DEFAULT NULL,
    `account_number` VARCHAR(100) DEFAULT NULL,
    `card_number` VARCHAR(30) DEFAULT NULL,
    `recipient_name` VARCHAR(255) DEFAULT NULL,
    `iban` VARCHAR(100) DEFAULT NULL,
    `receipt_image` VARCHAR(500) DEFAULT NULL,
    `admin_id` INT DEFAULT NULL,
    `admin_final_note` TEXT DEFAULT NULL,
    `admin_final_image` VARCHAR(500) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_status (status)
)");

// ===== AUTO-DELETE OLD RECORDS =====
$conn->query("DELETE FROM topup_requests WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 MONTH)");
$conn->query("DELETE FROM unpaid_invoices WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 MONTH)");

// Get version info
$versionFile = 'version.json';
$currentVersion = file_exists($versionFile) ? json_decode(file_get_contents($versionFile), true)['version'] : '1.0.0';

// ===== STATS =====
// اطمینان از وجود ستون last_seen برای وضعیت آنلاین


// شمارش امن درخواست‌های KYC (اگر جدول وجود داشته باشد)
function safeCount($conn, $sql) {
    $r = @$conn->query($sql);
    if (!$r) return 0;
    $row = $r->fetch_assoc();
    return $row ? (int)$row['count'] : 0;
}

// اطمینان از وجود جدول خرید مستقیم پیش از هر کوئری‌ای که به آن رجوع می‌کند
// (safeCount زیر و کوئری یونیون «آخرین درخواست‌ها» پایین‌تر) — طبق قانون این
// پروژه @ جلوی خطاهای mysqli در PHP 8 را نمی‌گیرد، پس اگر این جدول هنوز
// ساخته نشده باشد (کاربری هنوز از این قابلیت استفاده نکرده)، کل صفحه‌ی ادمین
// با یک exception ناگرفته کرش می‌کرد.
$conn->query("CREATE TABLE IF NOT EXISTS `direct_buy_requests` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `currency` ENUM('USD','EUR','USDT') NOT NULL,
    `target_price` DECIMAL(18,2) NOT NULL,
    `amount` DECIMAL(18,4) NOT NULL,
    `total_toman` DECIMAL(18,2) NOT NULL,
    `status` ENUM('pending','available','invoiced','completed','cancelled') NOT NULL DEFAULT 'pending',
    `admin_note` TEXT DEFAULT NULL,
    `invoice_id` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_status (status)
)");

$stats = [
    'total_users' => $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'],
    'pending_topups' => $conn->query("SELECT COUNT(*) as count FROM topup_requests WHERE status='pending'")->fetch_assoc()['count'],
    'pending_invoices' => $conn->query("SELECT COUNT(*) as count FROM unpaid_invoices WHERE status='pending'")->fetch_assoc()['count'],
    'paid_invoices' => $conn->query("SELECT COUNT(*) as count FROM unpaid_invoices WHERE status='paid'")->fetch_assoc()['count'],
    'approved_invoices' => $conn->query("SELECT COUNT(*) as count FROM unpaid_invoices WHERE status='approved'")->fetch_assoc()['count'],
    'pending_kyc' => safeCount($conn, "SELECT COUNT(*) as count FROM kyc_requests WHERE status='pending'"),
    'pending_withdrawals' => safeCount($conn, "SELECT COUNT(*) as count FROM withdrawal_requests WHERE status='pending'"),
    'pending_directbuy' => safeCount($conn, "SELECT COUNT(*) as count FROM direct_buy_requests WHERE status='pending'"),
    'unread_chats' => safeCount($conn, "SELECT COUNT(DISTINCT user_id) as count FROM chat_messages WHERE sender='user' AND is_read=0"),
    'online_users' => safeCount($conn, "SELECT COUNT(*) as count FROM users WHERE last_seen IS NOT NULL AND last_seen >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)"),
    'today_signups' => safeCount($conn, "SELECT COUNT(*) as count FROM users WHERE DATE(created_at) = CURDATE()"),
    'today_logins' => safeCount($conn, "SELECT COUNT(*) as count FROM users WHERE DATE(last_login) = CURDATE()"),
    // معاملات (تبادل ارزی) ثبت‌شده امروز — برای کارت آماری «معاملات امروز»
    'today_deals' => safeCount($conn, "SELECT COUNT(*) as count FROM ad_deals WHERE DATE(created_at) = CURDATE()"),
];

// ==================== دید کلی موجودی کاربران (مثبت/منفی) ====================
$balanceOverview = ['USD'=>['neg'=>0,'pos'=>0,'neg_sum'=>0], 'EUR'=>['neg'=>0,'pos'=>0,'neg_sum'=>0], 'USDT'=>['neg'=>0,'pos'=>0,'neg_sum'=>0], 'IRR'=>['neg'=>0,'pos'=>0,'neg_sum'=>0]];
$balOvRes = $conn->query("SELECT
    SUM(balance_usd<0) neg_usd, SUM(balance_usd>0) pos_usd, SUM(CASE WHEN balance_usd<0 THEN balance_usd ELSE 0 END) sum_usd,
    SUM(balance_eur<0) neg_eur, SUM(balance_eur>0) pos_eur, SUM(CASE WHEN balance_eur<0 THEN balance_eur ELSE 0 END) sum_eur,
    SUM(balance_usdt<0) neg_usdt, SUM(balance_usdt>0) pos_usdt, SUM(CASE WHEN balance_usdt<0 THEN balance_usdt ELSE 0 END) sum_usdt,
    SUM(balance_irr<0) neg_irr, SUM(balance_irr>0) pos_irr, SUM(CASE WHEN balance_irr<0 THEN balance_irr ELSE 0 END) sum_irr
    FROM users");
if ($balOvRes && ($r = $balOvRes->fetch_assoc())) {
    $balanceOverview = [
        'USD'  => ['neg'=>(int)$r['neg_usd'],  'pos'=>(int)$r['pos_usd'],  'neg_sum'=>(float)$r['sum_usd']],
        'EUR'  => ['neg'=>(int)$r['neg_eur'],  'pos'=>(int)$r['pos_eur'],  'neg_sum'=>(float)$r['sum_eur']],
        'USDT' => ['neg'=>(int)$r['neg_usdt'], 'pos'=>(int)$r['pos_usdt'], 'neg_sum'=>(float)$r['sum_usdt']],
        'IRR'  => ['neg'=>(int)$r['neg_irr'],  'pos'=>(int)$r['pos_irr'],  'neg_sum'=>(float)$r['sum_irr']],
    ];
}
// بدترین ۱۵ کاربر از نظر بدهی (بر اساس مجموع موجودی منفی ارزهای سخت — دلار/یورو/تتر)
$negUsersList = [];
$negUsersRes = $conn->query("SELECT id, first_name, last_name, telegram_id, balance_usd, balance_eur, balance_usdt, balance_irr
    FROM users
    WHERE balance_usd<0 OR balance_eur<0 OR balance_usdt<0 OR balance_irr<0
    ORDER BY (LEAST(balance_usd,0)+LEAST(balance_eur,0)+LEAST(balance_usdt,0)) ASC, balance_irr ASC
    LIMIT 15");
if ($negUsersRes) { while ($row = $negUsersRes->fetch_assoc()) { $negUsersList[] = $row; } }

// کاربرانی که تخفیف (دستی یا خودکار بر اساس سطح) فعال دارند — دقیقاً همان
// جدولی که api/discount_api.php برای فرم تخفیف تک‌به‌تک استفاده می‌کند
$discountUsersList = [];
$conn->query("CREATE TABLE IF NOT EXISTS `user_discounts` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `discount_percent` DECIMAL(5,2) NOT NULL DEFAULT 0,
    `description` TEXT,
    `created_by` INT NOT NULL,
    `expires_at` DATETIME,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_user_discount` (`user_id`)
)");
$conn->query("DELETE FROM user_discounts WHERE expires_at IS NOT NULL AND expires_at <= NOW()");
$discUsersRes = $conn->query("SELECT d.user_id, d.discount_percent, d.description, d.expires_at,
                                      u.first_name, u.last_name, u.telegram_id
                               FROM user_discounts d
                               JOIN users u ON d.user_id = u.id
                               ORDER BY d.discount_percent DESC
                               LIMIT 30");
if ($discUsersRes) { while ($row = $discUsersRes->fetch_assoc()) { $discountUsersList[] = $row; } }

// ==================== آخرین درخواست‌ها (جمع از همه‌ی بخش‌ها) ====================
// برای بخش «دسترسی سریع» در صفحه‌ی داشبورد: آخرین درخواست‌های در انتظار از
// شش منبع مختلف (شارژ کیف‌پول، فیش پرداخت، احراز هویت، تسویه حساب،
// حواله ارزی، معاملات تبادل ارزی) با هم ترکیب و بر اساس تاریخ مرتب می‌شوند.
$latestRequests = [];
$lrRes = @$conn->query("
    SELECT 'topup' AS req_type, id, user_id, amount, currency, created_at FROM topup_requests WHERE status='pending'
    UNION ALL
    SELECT 'invoice', id, user_id, amount, currency, created_at FROM unpaid_invoices WHERE status='pending'
    UNION ALL
    SELECT 'kyc', id, user_id, NULL, NULL, created_at FROM kyc_requests WHERE status='pending'
    UNION ALL
    SELECT 'withdrawal', id, user_id, amount, currency, created_at FROM withdrawal_requests WHERE status='pending'
    UNION ALL
    SELECT 'transfer', id, user_id, amount, currency, created_at FROM money_transfers WHERE status='pending'
    UNION ALL
    SELECT 'deal', id, buyer_id, amount, currency, created_at FROM ad_deals WHERE status='pending' AND admin_completed = 0
    UNION ALL
    SELECT 'directbuy', id, user_id, amount, currency, created_at FROM direct_buy_requests WHERE status='pending'
    ORDER BY created_at DESC
    LIMIT 10
");
if ($lrRes) {
    while ($row = $lrRes->fetch_assoc()) $latestRequests[] = $row;
    // نام کاربران مرتبط را یک‌جا واکشی می‌کنیم تا به ازای هر ردیف کوئری جدا نزنیم
    $lrUserIds = array_unique(array_filter(array_map(fn($r) => (int)$r['user_id'], $latestRequests)));
    $lrUserNames = [];
    if ($lrUserIds) {
        $idsEsc = implode(',', $lrUserIds);
        $unRes = @$conn->query("SELECT id, first_name, last_name FROM users WHERE id IN ($idsEsc)");
        if ($unRes) { while ($u = $unRes->fetch_assoc()) $lrUserNames[$u['id']] = trim($u['first_name'] . ' ' . $u['last_name']); }
    }
    foreach ($latestRequests as &$lr) { $lr['user_name'] = $lrUserNames[(int)$lr['user_id']] ?? 'کاربر'; }
    unset($lr);
}
$axLatestReqMeta = [
    'topup'      => ['label' => 'شارژ کیف پول',        'icon' => 'fa-wallet',              'color' => '#22C55E', 'tab' => 'topups'],
    'invoice'    => ['label' => 'فیش پرداخت',           'icon' => 'fa-file-invoice',        'color' => '#38bdf8', 'tab' => 'invoices'],
    'kyc'        => ['label' => 'احراز هویت',           'icon' => 'fa-id-card',             'color' => '#a78bfa', 'tab' => 'kyc'],
    'withdrawal' => ['label' => 'درخواست تسویه',        'icon' => 'fa-hand-holding-dollar',  'color' => '#f59e0b', 'tab' => 'settlements'],
    'transfer'   => ['label' => 'حواله ارزی',           'icon' => 'fa-money-bill-transfer',  'color' => '#FF4D8D', 'tab' => 'transfers'],
    'deal'       => ['label' => 'معامله تبادل ارزی',    'icon' => 'fa-exchange-alt',         'color' => '#2dd4bf', 'tab' => 'exchange'],
    'directbuy'  => ['label' => 'خرید مستقیم',          'icon' => 'fa-bolt',                 'color' => '#22C55E', 'tab' => 'directbuy'],
];

// لیست کاربران آنلاین/اخیر برای بخش وضعیت آنلاین
$onlineUsersList = [];
$ouRes = @$conn->query("SELECT id, first_name, last_name, telegram_id, avatar, account_number, last_seen, last_login
                        FROM users
                        WHERE last_seen IS NOT NULL OR last_login IS NOT NULL
                        ORDER BY COALESCE(last_seen, last_login) DESC
                        LIMIT 60");
if ($ouRes) { while ($r = $ouRes->fetch_assoc()) $onlineUsersList[] = $r; }

// Handle balance update
$successMsg = $errorMsg = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['balance_action'])) {
    require_once __DIR__ . '/includes/notify_helper.php';
    $userId = intval($_POST['user_id']);
    $amount = floatval($_POST['amount']);
    $currency = $_POST['currency'];
    $operation = $_POST['operation'];
    $description = $_POST['description'] ?? 'Admin adjustment';
    $allowNegative = isset($_POST['allow_negative']);
    $notifyUserChecked = isset($_POST['notify_user']);
    
    $balanceField = 'balance_' . strtolower($currency);
    $user = $conn->query("SELECT $balanceField, first_name, last_name, telegram_id FROM users WHERE id=$userId")->fetch_assoc();
    
    if($user) {
        $current = $user[$balanceField];
        $newBalance = $operation == 'increase' ? $current + $amount : $current - $amount;
        
        if($operation == 'decrease' && $newBalance < 0 && !$allowNegative) {
            $errorMsg = "Insufficient balance! Available: " . number_format($current,2) . " $currency";
        } else {
            $conn->query("UPDATE users SET $balanceField = $newBalance, updated_at = NOW() WHERE id=$userId");
            $txId = 'ADM' . time() . rand(1000,9999);
            $type = $operation == 'increase' ? 'deposit' : 'withdrawal';
            $senderId = $operation == 'increase' ? $adminId : $userId;
            $receiverId = $operation == 'increase' ? $userId : $adminId;
            $negativeNote = ($operation == 'decrease' && $newBalance < 0) ? " ⚠️ Negative balance!" : "";
            $fullDesc = "[Admin $operation] $description: " . ($operation=='increase'?'+':'-') . "$amount $currency ($current → $newBalance)$negativeNote";
            $conn->query("INSERT INTO transactions (transaction_id, sender_id, receiver_id, amount, currency, type, description, status, admin_id, created_at) VALUES ('$txId', $senderId, $receiverId, $amount, '$currency', '$type', '$fullDesc', 'completed', $adminId, NOW())");
            $successMsg = "Balance updated: " . number_format($current,2) . " → " . number_format($newBalance,2) . " $currency";
            if ($notifyUserChecked) {
                $ntTitle = $operation == 'increase' ? '💰 افزایش موجودی' : '📉 کاهش موجودی';
                $ntBody  = "موجودی حساب شما در $currency " . ($operation=='increase'?'افزایش':'کاهش') . " یافت: " . ($operation=='increase'?'+':'-') . number_format($amount,2) . " $currency\nموجودی جدید: " . number_format($newBalance,2) . " $currency";
                if ($newBalance < 0) $ntBody .= "\n⚠️ موجودی شما اکنون منفی است.";
                notifyUser($conn, $userId, $ntTitle, $ntBody, ['type' => 'balance_adjust', 'related_id' => null, 'immediate' => true]);
            }
        }
    } else { $errorMsg = "User not found"; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="dns-prefetch" href="https://cdnjs.cloudflare.com">
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="dns-prefetch" href="https://cdn.jsdelivr.net">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Admin Panel</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <!-- Chart.js: ابتدا از نسخه‌ی محلی (مستقل از فیلترینگ CDN)، و اگر نبود از CDN.
         دلیل: CDNهای خارجی در شبکه‌ی ایران معمولاً کند/فیلترند و قبلاً همین موضوع
         باعث شده بود کتابخانه‌ی اسکنر QR اصلاً بارگذاری نشود. نمودار درآمد هم
         نباید به دسترسی به CDN وابسته باشد. -->
    <script src="assets/js/vendor/chart.umd.min.js" defer></script>
    <script>
    window.addEventListener('DOMContentLoaded', function(){
        if (typeof Chart === 'undefined') {
            var s = document.createElement('script');
            s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js';
            s.onload = function(){ if (typeof axLoadRevenue === 'function' && document.getElementById('revenueChart')) { try { axLoadRevenue(); } catch(e){} } };
            document.head.appendChild(s);
        }
    });
    </script>
    <script src="assets/js/upload-compress.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/upload-compress.js') ?: time(); ?>" defer></script>
    <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
    <style>
        /* ===== BASE STYLES ===== */
        * { margin:0; padding:0; box-sizing:border-box; }
        html, body { overflow-x: hidden; max-width: 100%; }
        body { background: linear-gradient(135deg, #1A0B2E, #2A0D3F); color: white; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
        .dashboard { max-width: 1600px; margin: 0 auto; padding: 20px; }
        /* نکته‌ی مهم: قبلاً minmax(480px,1fr) بود که روی گوشی (عرض واقعی معمولاً ۳۲۰–۴۳۰px)
           هیچ‌وقت جا نمی‌شد و کل صفحه را مجبور به اسکرول افقی می‌کرد. حالا کارت‌ها زیر ۷۰۰px
           عرض تک‌ستونه می‌شوند و صفحه دیگر چپ‌وراست نمی‌رود. */
        .admin-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(480px, 100%), 1fr)); gap: 25px; margin-bottom: 25px; }
        .admin-card { background: rgba(255,255,255,0.05); backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.1); border-radius: 20px; padding: 25px; transition: border-color .2s, box-shadow .2s; }
        .admin-card:hover { border-color: rgba(255,255,255,0.16); box-shadow: 0 14px 32px -18px rgba(108,64,197,.55); }
        .admin-card-title { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; font-size: 1.2rem; font-weight: 600; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 12px; }
        .admin-card-title i { color: #FFD700; }
        .form-input, .form-select { width: 100%; padding: 12px 14px; background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.15); border-radius: 12px; color: white; font-size: 0.9rem; margin-bottom: 12px; transition: border-color .15s, background .15s; }
        .form-input:focus, .form-select:focus { outline: none; border-color: #6C40C5; background: rgba(255,255,255,0.12); box-shadow: 0 0 0 3px rgba(108,64,197,.18); }
        .btn { width: 100%; padding: 12px; background: linear-gradient(45deg, #6C40C5, #FF4D8D); border: none; border-radius: 12px; color: white; font-weight: 600; cursor: pointer; margin-top: 10px; transition: transform .12s, box-shadow .12s, filter .12s; box-shadow: 0 10px 24px -12px rgba(108,64,197,.7); }
        .btn:hover { filter: brightness(1.08); box-shadow: 0 14px 28px -12px rgba(108,64,197,.85); }
        .btn:active { transform: scale(.98); }
        .btn-sm { padding: 8px 15px; border-radius: 8px; font-weight: 600; cursor: pointer; background: rgba(108,64,197,0.2); border: 1px solid #6C40C5; color: #6C40C5; transition: background .15s; }
        .btn-sm:hover { background: rgba(108,64,197,0.32); }
        .alert { padding: 12px 15px; border-radius: 12px; margin-bottom: 15px; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: rgba(76,217,100,0.2); border: 1px solid #4CD964; color: #4CD964; }
        .alert-error { background: rgba(255,59,48,0.2); border: 1px solid #FF3B30; color: #FF3B30; }
        .user-balance-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 12px; margin-bottom: 20px; padding: 15px; background: rgba(0,0,0,0.3); border-radius: 16px; display: none; }
        .user-balance-grid.show { display: grid; }
        .balance-card { text-align: center; padding: 12px; background: rgba(255,255,255,0.05); border-radius: 12px; }
        .balance-card .currency { font-size: 0.7rem; color: rgba(255,255,255,0.6); margin-bottom: 6px; }
        .balance-card .amount { font-size: 1rem; font-weight: bold; color: #4CD964; }
        .user-transactions { margin-top: 20px; padding-top: 15px; border-top: 1px solid rgba(255,255,255,0.1); display: none; }
        .user-transactions.show { display: block; }
        .tx-table { width: 100%; border-collapse: collapse; font-size: 0.7rem; }
        .tx-table th, .tx-table td { padding: 8px 6px; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .tx-table th { color: rgba(255,255,255,0.6); }
        .scrollable-tx { max-height: 300px; overflow-y: auto; }
        .scrollable-tx::-webkit-scrollbar { width: 4px; }
        .scrollable-tx::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); }
        .scrollable-tx::-webkit-scrollbar-thumb { background: #6C40C5; border-radius: 4px; }
        .operation-buttons { display: flex; gap: 10px; margin: 10px 0; }
        .operation-btn { flex: 1; padding: 10px; border-radius: 10px; font-weight: 600; cursor: pointer; background: transparent; border: 2px solid; }
        .operation-btn.add { border-color: #4CD964; color: #4CD964; }
        .operation-btn.add.active { background: #4CD964; color: #1A0B2E; }
        .operation-btn.subtract { border-color: #FF3B30; color: #FF3B30; }
        .operation-btn.subtract.active { background: #FF3B30; color: white; }
        .checkbox-group { display: flex; align-items: center; gap: 10px; padding: 10px; background: rgba(255,59,48,0.1); border-radius: 10px; margin: 10px 0; }
        .balance-preview { background: rgba(0,0,0,0.3); border-radius: 12px; padding: 15px; margin-top: 15px; display: none; }
        .balance-preview.show { display: block; }
        .balance-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid rgba(255,255,255,0.08); }

        /* ===== CHAT ===== */
        .chat-container { max-height: 400px; overflow-y: auto; margin-bottom: 15px; padding: 15px; background: rgba(0,0,0,0.3); border-radius: 16px; display: none; }
        .chat-container::-webkit-scrollbar { width: 5px; }
        .chat-container::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); border-radius: 10px; }
        .chat-container::-webkit-scrollbar-thumb { background: #6C40C5; border-radius: 10px; }
        .chat-message { margin-bottom: 15px; animation: fadeInUp 0.3s ease; }
        .chat-message.admin { text-align: right; }
        .chat-message.user { text-align: left; }
        .message-bubble { display: inline-block; max-width: 80%; padding: 10px 15px; border-radius: 18px; word-wrap: break-word; }
        .chat-message.admin .message-bubble { background: linear-gradient(135deg, #6C40C5, #8B5CF6); color: white; border-bottom-right-radius: 4px; }
        .chat-message.user .message-bubble { background: rgba(255,255,255,0.1); color: white; border-bottom-left-radius: 4px; }
        .message-time { font-size: 0.6rem; opacity: 0.6; margin-top: 4px; display: block; }
        .message-file { margin-top: 8px; font-size: 0.7rem; }
        .message-file a { color: #FFD700; text-decoration: none; }
        .file-preview { font-size: 0.7rem; color: #FFC107; margin-top: 5px; padding: 5px; background: rgba(0,0,0,0.3); border-radius: 8px; display: none; }
        .file-preview.show { display: flex; align-items: center; gap: 8px; }

        /* ===== VERSION ===== */
        .current-version { background: linear-gradient(135deg, #6C40C5, #FF4D8D); border-radius: 20px; padding: 30px; text-align: center; margin-bottom: 30px; }
        .version-number { font-size: 3rem; font-weight: bold; margin: 10px 0; }
        .update-form { background: rgba(255,255,255,0.05); backdrop-filter: blur(10px); border-radius: 20px; padding: 25px; margin-bottom: 30px; border: 1px solid rgba(255,255,255,0.1); }
        .logs-table { width: 100%; border-collapse: collapse; }
        .logs-table th, .logs-table td { padding: 12px; text-align: right; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .logs-table th { color: #B8B8D1; font-weight: 600; }
        .btn-approve { background: rgba(76,217,100,0.2); border: 1px solid #4CD964; color: #4CD964; padding: 8px 15px; border-radius: 8px; cursor: pointer; }
        .btn-reject { background: rgba(255,59,48,0.2); border: 1px solid #FF3B30; color: #FF3B30; padding: 8px 15px; border-radius: 8px; cursor: pointer; }

        /* ===== TOP-UP & INVOICE STYLES ===== */
        .admin-topup-section { direction: rtl; }
        .filter-tabs { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 16px; }
        .filter-tab {
            padding: 5px 12px;
            border: 1.5px solid rgba(108,64,197,0.3);
            border-radius: 20px;
            background: transparent;
            color: #9b94b8;
            font-size: .72rem;
            cursor: pointer;
            transition: all .2s;
        }
        .filter-tab.active { background: linear-gradient(135deg,#6C40C5,#FF4D8D); border-color:transparent; color:#fff; }
        .topup-request-card {
            background: rgba(0,0,0,0.3);
            border: 1px solid rgba(108,64,197,0.2);
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 12px;
            transition: box-shadow .2s;
        }
        .topup-request-card:hover { box-shadow: 0 4px 24px rgba(108,64,197,0.15); }
        .req-card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; flex-wrap: wrap; }
        .req-user-info { display: flex; align-items: center; gap: 10px; }
        .req-avatar {
            width: 36px; height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg,#6C40C5,#FF4D8D);
            display: flex; align-items: center; justify-content: center;
            font-size: .8rem; color: #fff; font-weight: 700;
            flex-shrink: 0;
        }
        .req-user-name { font-size: .85rem; font-weight: 700; color: #f0eeff; }
        .req-user-sub { font-size: .68rem; color: #9b94b8; }
        .req-amount-badge {
            font-size: 1rem;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 20px;
        }
        .req-amount-badge.USD { background:rgba(74,222,128,0.15); color:#4ade80; }
        .req-amount-badge.EUR { background:rgba(56,189,248,0.15); color:#38bdf8; }
        .req-amount-badge.USDT { background:rgba(240,180,41,0.15); color:#f0b429; }
        .req-amount-badge.IRR { background:rgba(255,255,255,0.1); color:rgba(255,255,255,0.7); }
        .req-card-body { margin-bottom: 12px; }
        .req-meta { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 8px; }
        .req-meta-item { font-size: .68rem; color: #9b94b8; }
        .req-meta-item strong { color: #f0eeff; }
        .receipt-thumb {
            width: 80px; height: 60px;
            object-fit: cover;
            border-radius: 8px;
            cursor: pointer;
            border: 2px solid rgba(108,64,197,0.3);
            transition: border-color .2s;
        }
        .receipt-thumb:hover { border-color: #6C40C5; }
        .admin-action-row { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
        .btn-admin {
            padding: 8px 16px;
            border: none;
            border-radius: 10px;
            font-size: .75rem;
            font-weight: 700;
            cursor: pointer;
            transition: opacity .2s, transform .1s;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .btn-admin:hover { opacity: .88; }
        .btn-admin:active { transform: scale(.96); }
        .btn-admin-approve { background: #22d3a0; color: #111; }
        .btn-admin-reject { background: #ff4d6d; color: #fff; }
        .btn-admin-complete { background: linear-gradient(135deg,#6C40C5,#FF4D8D); color: #fff; }
        .btn-admin-view-receipt { background: #21213a; color: #9b94b8; border: 1px solid rgba(108,64,197,0.3); }
        .btn-admin-finalize { background: linear-gradient(135deg,#f0b429,#FF4D8D); color: #111; }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: .65rem;
            font-weight: 700;
        }
        .pill-pending { background:rgba(245,158,11,0.15); color:#f59e0b; }
        .pill-approved { background:rgba(56,189,248,0.15); color:#38bdf8; }
        .pill-waiting_payment { background:rgba(108,64,197,0.15); color:#a78bfa; }
        .pill-payment_received { background:rgba(108,64,197,0.2); color:#c4b5fd; }
        .pill-completed { background:rgba(34,211,160,0.15); color:#22d3a0; }
        .pill-finalized { background:rgba(34,211,160,0.15); color:#22d3a0; }
        .pill-paid { background:rgba(108,64,197,0.15); color:#a78bfa; }
        .pill-rejected { background:rgba(255,77,109,0.15); color:#ff4d6d; }

        .empty-state { text-align:center; padding:40px 20px; color:#5a5475; font-size:.8rem; }
        .empty-state i { font-size:2rem; opacity:.3; display:block; margin-bottom:8px; }

        /* ===== Compact admin list rows (Top-up & Invoices) ===== */
        .admin-row {
            display:flex; align-items:center; justify-content:space-between; gap:10px;
            padding:11px 12px; border-radius:10px; margin-bottom:7px; cursor:pointer;
            background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.07);
            transition:background .18s, border-color .18s;
        }
        .admin-row:hover { background:rgba(108,64,197,0.12); border-color:rgba(108,64,197,0.4); }
        .admin-row-main { min-width:0; display:flex; align-items:center; gap:10px; flex:1; }
        .admin-row-avatar {
            width:34px; height:34px; border-radius:9px; flex-shrink:0;
            display:flex; align-items:center; justify-content:center;
            font-size:.72rem; font-weight:700; color:#fff;
            background:linear-gradient(135deg,#6C40C5,#FF4D8D);
        }
        .admin-row-txt { min-width:0; }
        .admin-row-title { font-size:.84rem; font-weight:600; color:#fff; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .admin-row-sub { font-size:.68rem; color:#9b94b8; margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .admin-row-right { display:flex; align-items:center; gap:8px; flex-shrink:0; }
        .admin-row-amount { font-size:.82rem; font-weight:700; white-space:nowrap; }
        .admin-row-amount.USD  { color:#4ade80; }
        .admin-row-amount.EUR  { color:#38bdf8; }
        .admin-row-amount.USDT { color:#f0b429; }
        .admin-row-amount.IRR  { color:#e2e2f0; }
        .admin-row-chev { color:rgba(255,255,255,0.25); font-size:.7rem; }
        .admin-row-new { background:#FF3B30; color:#fff; font-size:.55rem; font-weight:800; padding:2px 7px; border-radius:20px; letter-spacing:.04em; }
        .skeleton {
            background: linear-gradient(90deg, #21213a 25%, #2a2a4a 50%, #21213a 75%);
            background-size: 200% 100%;
            animation: skeleton-loading 1.4s infinite;
            border-radius: 8px;
            height: 16px;
            margin-bottom: 6px;
        }
        @keyframes skeleton-loading { to { background-position: -200% 0; } }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        /* ===== MODALS ===== */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.7);
            backdrop-filter: blur(6px);
            z-index: 99999;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.open { display: flex; }
        .modal-sheet {
            background: linear-gradient(135deg,#1A0B2E,#2A0D3F);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 24px;
            padding: 30px 24px;
            width: 90%;
            max-width: 480px;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
        }
        .modal-handle { width: 40px; height: 4px; background: rgba(255,255,255,0.2); border-radius: 4px; margin: 0 auto 16px; }
        .modal-close {
            position: absolute;
            top: 16px; right: 20px;
            background: none; border: none;
            color: #fff; font-size: 1.3rem;
            cursor: pointer;
        }
        .modal-title {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .admin-form-group { margin-bottom: 12px; }
        .admin-form-label { font-size: .72rem; color: #9b94b8; margin-bottom: 5px; display: block; }
        .admin-form-input {
            width: 100%;
            padding: 10px 12px;
            background: rgba(255,255,255,0.08);
            border: 1.5px solid rgba(108,64,197,0.2);
            border-radius: 10px;
            color: #f0eeff;
            font-size: .85rem;
            outline: none;
            transition: border-color .2s;
        }
        .admin-form-input:focus { border-color: #6C40C5; }
        .btn-primary { width:100%; padding:12px; background:linear-gradient(45deg,#6C40C5,#FF4D8D); border:none; border-radius:12px; color:#fff; font-weight:600; cursor:pointer; margin-top:10px; }
        .btn-success { width:100%; padding:12px; background:#22d3a0; border:none; border-radius:12px; color:#111; font-weight:600; cursor:pointer; margin-top:10px; }
        .btn-danger { width:100%; padding:12px; background:#ff4d6d; border:none; border-radius:12px; color:#fff; font-weight:600; cursor:pointer; margin-top:10px; }
        .btn-warning { width:100%; padding:12px; background:linear-gradient(135deg,#f0b429,#FF4D8D); border:none; border-radius:12px; color:#111; font-weight:600; cursor:pointer; margin-top:10px; }
        .upload-area {
            border: 1.5px dashed rgba(108,64,197,0.3);
            border-radius: 10px;
            padding: 12px;
            text-align: center;
            cursor: pointer;
            transition: all .2s;
        }
        .upload-area:hover { border-color: #6C40C5; background: rgba(108,64,197,0.05); }
        .upload-area i { font-size: 1.2rem; color: #FF4D8D; display: block; margin-bottom: 4px; }
        .upload-area span { font-size: .68rem; color: rgba(255,255,255,0.3); }
        .upload-area input { display: none; }
        .final-image-preview {
            width: 100%;
            max-height: 150px;
            object-fit: cover;
            border-radius: 8px;
            margin-top: 8px;
        }
        .toast {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: rgba(26,11,46,0.95);
            backdrop-filter: blur(20px);
            border: 1px solid #4CD964;
            border-radius: 14px;
            padding: 14px 20px;
            color: #4CD964;
            z-index: 999999;
            font-weight: 600;
            box-shadow: 0 8px 30px rgba(0,0,0,0.5);
            transition: all 0.3s;
            max-width: 90%;
        }
        .toast.error { border-color: #FF3B30; color: #FF3B30; }
        .toast.info { border-color: #5AC8FA; color: #5AC8FA; }
        
        /* ============================================================
           SIDEBAR NAVIGATION — بازطراحی کامل پنل ادمین
           گروه‌بندی موضوعی + منوی کشویی موبایل به‌جای نوار افقی قدیمی
           ============================================================ */
        /* سایدبار ناوبری کاملاً حذف شد؛ همه‌چیز از طریق داشبورد + مودال «دسترسی سریع» انجام می‌شود */
        .admin-shell{ max-width:1600px; margin:0 auto; padding:0 20px 40px; }
        .admin-main{ min-width:0; }
        .admin-sidebar{
            flex:none; width:248px; position:sticky; top:84px;
            background:linear-gradient(165deg, rgba(108,64,197,.10), rgba(255,77,141,.04));
            border:1px solid rgba(255,255,255,.08); border-radius:20px; padding:14px;
            max-height:calc(100vh - 104px); overflow-y:auto;
        }
        .admin-sidebar-group{ margin-bottom:6px; }
        .admin-sidebar-group-head{
            display:flex; align-items:center; justify-content:space-between; gap:8px;
            padding:9px 10px; cursor:pointer; border-radius:10px; user-select:none;
            color:rgba(255,255,255,.45); font-size:.66rem; font-weight:800; letter-spacing:.02em;
            text-transform:uppercase;
        }
        .admin-sidebar-group-head:hover{ background:rgba(255,255,255,.04); }
        .admin-sidebar-group-head i.chev{ font-size:.62rem; transition:transform .2s; }
        .admin-sidebar-group.collapsed .admin-sidebar-group-head i.chev{ transform:rotate(-90deg); }
        .admin-sidebar-group-items{ display:flex; flex-direction:column; gap:2px; overflow:hidden; }
        .admin-sidebar-group.collapsed .admin-sidebar-group-items{ display:none; }



        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 12px; margin-bottom: 20px; }
        .stat-card { background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.06); border-radius: 16px; padding: 14px; text-align: center; }
        .stat-card .number { font-size: 1.4rem; font-weight: 700; }
        .stat-card .label { font-size: .65rem; color: rgba(255,255,255,0.4); margin-top: 4px; }
        .stat-card .number.pending { color: #f59e0b; }
        .stat-card .number.approved { color: #38bdf8; }
        .stat-card .number.completed { color: #22d3a0; }
        .stat-card .number.total { color: #a78bfa; }

        /* ============================================================
           صفحه‌ی «داشبورد»: کارت‌های آماری + دسترسی سریع + آخرین درخواست‌ها
           ============================================================ */
        .ax-hero-stats{ display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:20px; }
        .ax-hero-stat{ background:rgba(255,255,255,.04); border:1px solid rgba(255,255,255,.07); border-radius:18px; padding:16px 10px; text-align:center; }
        .ax-hero-ic{ width:44px; height:44px; border-radius:14px; margin:0 auto 10px; display:flex; align-items:center; justify-content:center; color:#fff; font-size:1.05rem; box-shadow:0 8px 18px -8px rgba(0,0,0,.5); }
        .ax-hero-num{ color:#fff; font-weight:900; font-size:1.3rem; }
        .ax-hero-lbl{ color:rgba(255,255,255,.5); font-size:.65rem; font-weight:700; margin-top:4px; display:flex; align-items:center; justify-content:center; gap:4px; }
        @media (max-width:520px){ .ax-hero-stats{ grid-template-columns:repeat(2,1fr); } }

        .ax-quick-panel{ background:rgba(255,255,255,.03); border:1px solid rgba(255,255,255,.06); border-radius:20px; padding:16px; margin-bottom:18px; }
        .ax-quick-head{ display:flex; align-items:center; justify-content:space-between; color:#fff; font-weight:800; font-size:.85rem; margin-bottom:14px; }
        .ax-quick-head i{ color:rgba(255,255,255,.35); }
        /* دسترسی سریع: هر بخش (گروه) جدا، و داخل هر بخش آیکون‌ها به‌صورت
           گرید چندستونه (نه اسکرول افقی) کنار هم چیده می‌شوند تا همه با
           یک نگاه دیده شوند و پیدا کردنشان راحت باشد. */
        .ax-quick-grid{
            display:grid; grid-template-columns:repeat(4,1fr); gap:10px;
        }
        @media (min-width:640px){ .ax-quick-grid{ grid-template-columns:repeat(6,1fr); } }
        @media (min-width:960px){ .ax-quick-grid{ grid-template-columns:repeat(8,1fr); } }
        .ax-quick-item{ background:rgba(255,255,255,.03); border:1px solid rgba(255,255,255,.06); border-radius:16px; padding:12px 6px; display:flex; flex-direction:column; align-items:center; gap:8px; cursor:pointer; transition:transform .12s,background .15s; width:100%; }
        .ax-quick-item:hover{ background:rgba(255,255,255,.06); }
        .ax-quick-item:active{ transform:scale(.95); }
        .ax-quick-ic{ position:relative; width:42px; height:42px; border-radius:13px; display:flex; align-items:center; justify-content:center; color:#fff; font-size:1rem; }
        .ax-quick-badge{ position:absolute; top:-6px; left:-6px; min-width:17px; height:17px; padding:0 4px; background:#ff4d6d; color:#fff; border-radius:20px; font-size:.58rem; font-weight:800; display:flex; align-items:center; justify-content:center; border:2px solid #150826; }
        .ax-quick-badge.blink{ animation: axBadgeBlink 1.1s ease-in-out infinite; }
        @keyframes axBadgeBlink { 0%,100%{ opacity:1; transform:scale(1); } 50%{ opacity:.55; transform:scale(1.15); } }
        /* ===== تب درآمد ادمین: کارت‌های ارزی + تب‌های انتخاب ارز نمودار ===== */
        .rev-cur-grid{ display:grid; grid-template-columns:repeat(2,1fr); gap:12px; margin-bottom:16px; }
        @media (min-width:860px){ .rev-cur-grid{ grid-template-columns:repeat(4,1fr); } }
        .rev-cur-card{
            position:relative; overflow:hidden; border-radius:18px; padding:16px 14px;
            border:1px solid rgba(255,255,255,.08);
            background:linear-gradient(160deg,rgba(255,255,255,.05),rgba(255,255,255,.02));
        }
        .rev-cur-card::after{
            content:""; position:absolute; inset-inline-start:-30px; top:-30px; width:110px; height:110px;
            border-radius:50%; opacity:.55; filter:blur(6px); pointer-events:none;
        }
        .rev-cur-card .rev-cur-head{ position:relative; z-index:2; display:flex; align-items:center; gap:8px; margin-bottom:10px; }
        .rev-cur-badge{ width:32px; height:32px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:.82rem; color:#fff; font-weight:800; }
        .rev-cur-name{ font-size:.74rem; font-weight:800; color:rgba(255,255,255,.82); }
        .rev-cur-total{ position:relative; z-index:2; font-size:1.22rem; font-weight:900; color:#fff; letter-spacing:-.3px; word-break:break-all; }
        .rev-cur-break{ position:relative; z-index:2; margin-top:10px; padding-top:10px; border-top:1px dashed rgba(255,255,255,.12); display:flex; flex-direction:column; gap:5px; }
        .rev-cur-row{ display:flex; align-items:center; justify-content:space-between; font-size:.66rem; color:rgba(255,255,255,.6); }
        .rev-cur-row b{ color:rgba(255,255,255,.9); font-weight:700; }
        .rev-cur-tabs{ display:flex; gap:6px; flex-wrap:wrap; }
        .rev-cur-tab{
            background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1);
            color:rgba(255,255,255,.6); font-size:.7rem; font-weight:700; font-family:inherit;
            padding:6px 14px; border-radius:20px; cursor:pointer; transition:.15s;
        }
        .rev-cur-tab.active{ background:linear-gradient(135deg,#6C40C5,#FF4D8D); color:#fff; border-color:transparent; }
        .rev-entry-row{
            display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:12px;
            background:rgba(255,255,255,.03); border:1px solid rgba(255,255,255,.07);
        }
        .rev-entry-amt{ font-size:.82rem; font-weight:800; color:#22d3a0; white-space:nowrap; }
        .rev-entry-meta{ flex:1; min-width:0; }
        .rev-entry-actions{ display:flex; gap:6px; flex:none; }
        .rev-entry-actions button{
            width:30px; height:30px; border-radius:9px; border:1px solid rgba(255,255,255,.12);
            background:rgba(255,255,255,.05); color:#fff; cursor:pointer; font-size:.7rem;
        }

        .ax-quick-lbl{ color:rgba(255,255,255,.75); font-size:.66rem; font-weight:700; text-align:center; line-height:1.3; }

        /* مودال «دسترسی سریع»: به‌جای سوییچ کامل تب/اسکرول به پایین صفحه */
        .ax-quick-modal-overlay{ display:none; position:fixed; inset:0; background:rgba(5,2,12,.72); backdrop-filter:blur(3px); z-index:1200; align-items:flex-end; justify-content:center; }
        .ax-quick-modal-overlay.open{ display:flex; }
        .ax-quick-modal{ background:#180a2c; width:100%; max-width:720px; max-height:88vh; border-radius:22px 22px 0 0; overflow:hidden; display:flex; flex-direction:column; box-shadow:0 -12px 40px rgba(0,0,0,.5); }
        @media (min-width:720px){ .ax-quick-modal-overlay{ align-items:center; } .ax-quick-modal{ border-radius:22px; max-height:85vh; } }
        .ax-quick-modal-head{ display:flex; align-items:center; justify-content:space-between; padding:16px 18px; font-weight:800; color:#fff; font-size:.95rem; border-bottom:1px solid rgba(255,255,255,.08); flex:none; }
        .ax-quick-modal-close{ background:rgba(255,255,255,.08); border:none; color:#fff; width:32px; height:32px; border-radius:10px; cursor:pointer; }
        .ax-modal-pills{ display:flex; gap:8px; padding:10px 18px 0; flex:none; }
        .ax-modal-pill{ background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.1); color:rgba(255,255,255,.6); font-size:.75rem; font-weight:700; padding:8px 16px; border-radius:20px; cursor:pointer; }
        .ax-modal-pill.active{ background:linear-gradient(135deg,#6C40C5,#FF4D8D); color:#fff; border-color:transparent; }
        .ax-quick-modal-body{ padding:16px 18px 24px; overflow-y:auto; overflow-x:hidden; flex:1; min-width:0; }
        /* محتوایی که موقتاً از جای اصلی‌اش (که عرض بزرگ‌تری داشت) داخل این مودال
           جابه‌جا می‌شود، مجبور می‌شود عرض مودال را رعایت کند — وگرنه گریدهای
           عریض (مثل .admin-grid) باعث اسکرول افقی داخل مودال یا کش‌آمدن آن می‌شدند. */
        .ax-quick-modal-body .tab-content{ width:100%; max-width:100%; box-sizing:border-box; }
        .ax-quick-modal-body .admin-grid{ grid-template-columns:1fr !important; }

        .ax-latestreq-list{ display:flex; flex-direction:column; gap:8px; }
        .ax-latestreq-row{ display:flex; align-items:center; gap:10px; background:rgba(255,255,255,.03); border:1px solid rgba(255,255,255,.06); border-radius:14px; padding:10px 12px; }
        .ax-latestreq-ic{ flex:none; width:38px; height:38px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:.9rem; }
        .ax-latestreq-info{ flex:1; min-width:0; }
        .ax-latestreq-info .t{ color:#fff; font-size:.78rem; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .ax-latestreq-info .s{ color:rgba(255,255,255,.45); font-size:.66rem; margin-top:3px; }
        .ax-latestreq-btn{ flex:none; background:rgba(124,92,255,.16); border:1px solid rgba(124,92,255,.35); color:#C9BAFF; font-size:.68rem; font-weight:800; padding:7px 12px; border-radius:10px; cursor:pointer; white-space:nowrap; }
        .ax-latestreq-btn:hover{ background:rgba(124,92,255,.28); }

        .admin-tabs { display: none; } /* نوار افقی قدیمی — دیگر استفاده نمی‌شود، با سایدبار گروه‌بندی‌شده جایگزین شد */
        .admin-tab {
            padding: 10px 12px; border: none; border-radius: 12px; background: transparent; color: rgba(255,255,255,0.55);
            font-size: .78rem; font-weight: 600; cursor: pointer; transition: all .15s; white-space: nowrap;
            display: flex; align-items: center; gap: 9px; width:100%; text-align:right;
        }
        .admin-tab i:first-child{ width:18px; text-align:center; flex:none; color:rgba(255,255,255,.4); transition:color .15s; font-size:.85rem; }
        .admin-tab:hover{ background:rgba(255,255,255,.05); color:#fff; }
        .admin-tab.active { background: linear-gradient(135deg, #6C40C5, #FF4D8D); color: #fff; box-shadow:0 6px 18px -8px rgba(108,64,197,.7); }
        .admin-tab.active i:first-child{ color:#fff; }
        .admin-tab .badge { background: rgba(255,77,109,0.2); color: #ff4d6d; font-size: .6rem; padding: 1px 8px; border-radius: 10px; font-weight: 700; margin-inline-start:auto; }
        .admin-tab.active .badge { background: rgba(255,255,255,0.22); color: #fff; }
        /* نقطه‌ی قرمز چشمک‌زن «جدید» کنار بج عددی روی هر ردیف دیگر نمایش داده نمی‌شود
           (تکراری/شلوغ‌کننده بود، چون بج عددی همان اطلاعات را می‌رساند)؛ فقط در
           کارت‌های دسترسی سریع/نمای کلی داشبورد که بج عددی ندارند همچنان دیده می‌شود. */
        .admin-tab .req-dot{ display:none !important; }

        @media (max-width: 960px) {
            .admin-shell{ padding:0 14px 30px; flex-direction:column; }
            /* منوی همبرگری/دراور حذف شد: سایدبار دیگر position:fixed نیست و مثل یک
               بخش عادی بالای محتوای اصلی قرار می‌گیرد (گروه‌ها هنوز جمع‌شونده‌اند
               پس خیلی جا نمی‌گیرد)؛ ناوبری اصلی روی موبایل هم از همین‌جا و هم از
               کاشی‌های «دسترسی سریع» در تب داشبورد انجام می‌شود. */
            .admin-sidebar{ position:static; width:100%; max-width:none; max-height:none; }
        }
        /* چراغ قرمز چشمک‌زن برای درخواست‌های جدید */
        .req-dot { display:none; width:10px; height:10px; border-radius:50%; background:#ff2d55; margin-inline-start:6px; box-shadow:0 0 0 0 rgba(255,45,85,0.7); animation: reqDotPulse 1.1s infinite; vertical-align:middle; }
        .req-dot.on { display:inline-block; }
        @keyframes reqDotPulse {
            0%   { box-shadow:0 0 0 0 rgba(255,45,85,0.65); opacity:1; }
            70%  { box-shadow:0 0 0 7px rgba(255,45,85,0);   opacity:.55; }
            100% { box-shadow:0 0 0 0 rgba(255,45,85,0);     opacity:1; }
        }
        .ov-tile { position:relative; }
        .ov-tile .req-dot { position:absolute; top:8px; inset-inline-end:8px; margin:0; }
        .tab-content { display: none; animation: fadeIn .3s ease; }
        .tab-content.active { display: block; }
        /* ===== پنل‌های ادغام‌شده (حواله/تسویه/تبادل) ===== */
        .ax-filterbar { display:flex; gap:8px; flex-wrap:wrap; }
        .ax-filter { padding:7px 14px; border-radius:20px; border:1px solid rgba(255,255,255,0.12);
            background:rgba(255,255,255,0.05); color:rgba(255,255,255,0.7); font-size:.78rem; cursor:pointer;
            font-family:inherit; transition:all .2s; }
        .ax-filter.active { background:linear-gradient(135deg,#6C40C5,#FF4D8D); color:#fff; border-color:transparent; }
        .ax-empty { text-align:center; color:rgba(255,255,255,0.4); padding:26px 0; font-size:.85rem; }
        .ax-item { background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.08);
            border-radius:16px; padding:14px; margin-bottom:12px; }
        .ax-item-head { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:10px; }
        .ax-code { font-family:monospace; font-size:.72rem; color:#FFD700; }
        .ax-badge { font-size:.68rem; padding:3px 10px; border-radius:12px; white-space:nowrap; }
        .ax-b-pending { background:rgba(255,193,7,0.18); color:#FFC107; }
        .ax-b-approved { background:rgba(56,189,248,0.18); color:#38bdf8; }
        .ax-b-await { background:rgba(255,140,0,0.18); color:#ff9800; }
        .ax-b-paid { background:rgba(139,92,246,0.2); color:#a78bfa; }
        .ax-b-done { background:rgba(76,217,100,0.18); color:#4CD964; }
        .ax-b-rej { background:rgba(255,59,48,0.18); color:#FF3B30; }
        .ax-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:6px 14px; font-size:.78rem; color:rgba(255,255,255,0.75); }
        .ax-grid b { color:#fff; }
        .ax-actions { display:flex; gap:8px; flex-wrap:wrap; margin-top:12px; }
        .ax-btn { padding:7px 13px; border-radius:10px; border:none; cursor:pointer; font-size:.76rem;
            font-family:inherit; color:#fff; display:inline-flex; align-items:center; gap:5px; }
        .ax-btn-approve { background:linear-gradient(135deg,#4CD964,#2ecc71); }
        .ax-btn-reject  { background:linear-gradient(135deg,#FF3B30,#e74c3c); }
        .ax-btn-accounts{ background:linear-gradient(135deg,#6C40C5,#8B5CF6); }
        .ax-btn-settle  { background:linear-gradient(135deg,#f59e0b,#d97706); }
        .ax-btn-view    { background:rgba(255,255,255,0.1); }
        .ax-btn-complete{ background:linear-gradient(135deg,#4CD964,#2ecc71); }
        .ax-thumbs { display:flex; gap:6px; flex-wrap:wrap; margin-top:8px; }
        .ax-thumbs a { display:inline-block; }
        .ax-thumbs img { width:52px; height:52px; object-fit:cover; border-radius:8px; border:1px solid rgba(255,255,255,0.15); }
        .ax-acc-row { display:flex; gap:6px; margin-bottom:6px; }
        .ax-acc-row input { flex:1; padding:8px 10px; border-radius:8px; background:rgba(0,0,0,0.25);
            border:1px solid rgba(255,255,255,0.12); color:#fff; font-family:inherit; font-size:.8rem; }
        .ax-searchbar { display:flex; gap:8px; }
        .ax-searchbar input { flex:1; padding:9px 12px; border-radius:10px; background:rgba(0,0,0,0.25);
            border:1px solid rgba(255,255,255,0.12); color:#fff; font-family:inherit; }

        /* ===== فرم‌های عمومی (تخفیف/کمیسیون و مشابه) ===== */
        .ax-form-row { display:flex; flex-wrap:wrap; gap:6px; }
        .ax-input, .ax-select, .ax-form-row input, .ax-form-row select {
            flex:1; min-width:100px; padding:9px 12px; border-radius:10px;
            background:rgba(0,0,0,0.25); border:1px solid rgba(255,255,255,0.12);
            color:#fff; font-family:inherit; font-size:.8rem;
        }
        .ax-select, .ax-form-row select { cursor:pointer; appearance:none; -webkit-appearance:none;
            background-image:url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='%23ffffff99'%3E%3Cpath fill-rule='evenodd' d='M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z' clip-rule='evenodd'/%3E%3C/svg%3E");
            background-repeat:no-repeat; background-position:left 10px center; background-size:16px; padding-left:32px; }
        .ax-select option, .ax-form-row select option { background:#1a0b2e; color:#fff; }
        .ax-input:focus, .ax-select:focus, .ax-form-row input:focus, .ax-form-row select:focus { outline:none; border-color:#8B5CF6; }
        .ax-form-section { margin-top:12px; padding-top:12px; border-top:1px solid rgba(255,255,255,0.08); }
        .ax-form-section:first-of-type { margin-top:0; padding-top:0; border-top:none; }
        .ax-form-label { font-size:.74rem; font-weight:700; color:rgba(255,255,255,0.65); margin-bottom:8px; display:flex; align-items:center; gap:6px; }
        .ax-form-hint { font-size:.62rem; color:rgba(255,255,255,0.4); margin-top:4px; }

        /* ===== Overview tiles ===== */
        .ov-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; }
        .ov-tile { position: relative; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); border-radius: 16px; padding: 16px 14px; cursor: pointer; transition: transform .15s, box-shadow .15s, border-color .15s; overflow: hidden; }
        .ov-tile:hover { transform: translateY(-3px); border-color: rgba(108,64,197,0.55); box-shadow: 0 10px 26px rgba(108,64,197,0.22); }
        .ov-tile:active { transform: scale(.97); }
        .ov-ic { width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; color: #fff; margin-bottom: 10px; }
        .ov-kyc .ov-ic { background: linear-gradient(135deg,#f59e0b,#FF6B35); }
        .ov-topup .ov-ic { background: linear-gradient(135deg,#6C40C5,#8B5CF6); }
        .ov-invoice .ov-ic { background: linear-gradient(135deg,#0ea5e9,#3b82f6); }
        .ov-chat .ov-ic { background: linear-gradient(135deg,#ec4899,#FF4D8D); }
        .ov-online .ov-ic { background: linear-gradient(135deg,#22c55e,#16a34a); }
        .ov-users .ov-ic { background: linear-gradient(135deg,#14b8a6,#0d9488); }
        .ov-num { font-size: 1.7rem; font-weight: 800; color: #fff; line-height: 1; }
        .ov-lbl { font-size: .72rem; color: rgba(255,255,255,0.55); margin-top: 6px; }
        .ov-pulse { position: absolute; top: 12px; left: 12px; width: 10px; height: 10px; border-radius: 50%; background: #FF3B30; box-shadow: 0 0 0 0 rgba(255,59,48,0.6); animation: ovPulse 1.6s infinite; }
        @keyframes ovPulse { 0%{box-shadow:0 0 0 0 rgba(255,59,48,0.6);} 70%{box-shadow:0 0 0 10px rgba(255,59,48,0);} 100%{box-shadow:0 0 0 0 rgba(255,59,48,0);} }
        .ov-mini-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 10px; }
        .ov-mini { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.06); border-radius: 12px; padding: 12px; text-align: center; }
        .ov-mini-num { display: block; font-size: 1.3rem; font-weight: 800; color: #fff; }
        .ov-mini-lbl { display: block; font-size: .68rem; color: rgba(255,255,255,0.5); margin-top: 4px; }

        /* ===== Balance overview (positive/negative) ===== */
        .bal-ov-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 10px; }
        .bal-ov-card { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.06); border-radius: 14px; padding: 12px; }
        .bal-ov-cur { font-size: .78rem; font-weight: 800; color: #C084FC; margin-bottom: 8px; }
        .bal-ov-row { display: flex; align-items: center; justify-content: space-between; font-size: .74rem; margin-bottom: 3px; }
        .bal-ov-pos { color: #4CD964; font-weight: 700; }
        .bal-ov-neg { color: #FF3B30; font-weight: 700; }
        .bal-ov-lbl { color: rgba(255,255,255,0.4); font-size: .66rem; }
        .bal-ov-debt { margin-top: 6px; font-size: .66rem; color: #FF3B30; border-top: 1px dashed rgba(255,59,48,0.25); padding-top: 6px; }
        .bal-ov-neglist-head { display: flex; align-items: center; justify-content: space-between; margin-top: 16px; padding: 10px 12px; background: rgba(255,59,48,0.08); border: 1px solid rgba(255,59,48,0.2); border-radius: 12px; cursor: pointer; color: #FF6B60; font-size: .78rem; font-weight: 700; }
        .bal-ov-neglist-head i.fa-chevron-down { transition: transform .2s; }
        .bal-ov-neglist-head i.fa-chevron-down.rot { transform: rotate(180deg); }
        .bal-ov-neglist { display: none; flex-direction: column; gap: 8px; margin-top: 10px; max-height: 320px; overflow-y: auto; }
        .bal-ov-neglist.open { display: flex; }
        .bal-ov-negrow { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.06); border-radius: 12px; padding: 10px 12px; cursor: pointer; transition: .2s; }
        .bal-ov-negrow:hover { background: rgba(255,59,48,0.08); border-color: rgba(255,59,48,0.25); }
        .bal-ov-negname { font-size: .8rem; font-weight: 700; color: #fff; margin-bottom: 6px; }
        .bal-ov-negamts { display: flex; gap: 6px; flex-wrap: wrap; }
        .bal-ov-negchip { font-size: .66rem; background: rgba(255,59,48,0.15); color: #FF6B60; padding: 3px 8px; border-radius: 8px; font-weight: 700; }

        /* ===== Online users list ===== */
        .online-list { display: flex; flex-direction: column; gap: 8px; }
        .online-row { display: flex; align-items: center; gap: 12px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.06); border-radius: 12px; padding: 10px 12px; }
        .online-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
        .online-dot.on { background: #22c55e; box-shadow: 0 0 8px rgba(34,197,94,0.8); }
        .online-dot.off { background: rgba(255,255,255,0.2); }
        .online-info { flex: 1; min-width: 0; }
        .online-name { font-size: .84rem; font-weight: 700; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .online-sub { font-size: .66rem; color: rgba(255,255,255,0.4); margin-top: 2px; }
        .online-times { text-align: left; display: flex; flex-direction: column; gap: 3px; align-items: flex-end; }
        .online-badge { font-size: .62rem; padding: 2px 8px; border-radius: 10px; white-space: nowrap; }
        .online-badge.on { background: rgba(34,197,94,0.15); color: #22c55e; }
        .online-badge.on i { font-size: .5rem; }
        .online-badge.off { background: rgba(255,255,255,0.06); color: rgba(255,255,255,0.55); }
        .online-login { font-size: .6rem; color: rgba(255,255,255,0.4); white-space: nowrap; }
        @media (max-width: 480px) { .online-times { max-width: 42%; } .online-badge, .online-login { font-size: .56rem; } }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }

        .admin-header {
            display: flex; justify-content: space-between; align-items: center; gap: 12px;
            padding: 14px 20px;
            padding-top: max(14px, env(safe-area-inset-top));
            background: rgba(255,255,255,0.03); border-bottom: 1px solid rgba(255,255,255,0.05);
            position: sticky; top: 0; z-index: 100; backdrop-filter: blur(10px);
        }
        .admin-header .logo { font-size: 1.1rem; font-weight: 700; display: flex; align-items: center; gap: 10px; }
        .admin-header .logo-box {
            width: 42px; height: 42px; border-radius: 14px; flex: none;
            background: rgba(255,215,0,.08); border: 1px solid rgba(255,215,0,.3);
            display: flex; align-items: center; justify-content: center; color: gold; font-size: 1.05rem;
        }
        .admin-header .logo-txt .t{ font-size: .96rem; font-weight: 800; color:#fff; }
        .admin-header .logo-txt .s{ font-size: .68rem; color: rgba(255,255,255,.45); font-weight: 600; margin-top:1px; }
        .admin-header .admin-info { display: flex; align-items: center; gap: 10px; }
        .admin-header .admin-id { text-align: left; }
        .admin-header .admin-id .n{ font-size:.8rem; font-weight:800; color:#fff; display:flex; align-items:center; gap:5px; justify-content:flex-end; }
        .admin-header .admin-id .n .dot{ width:7px; height:7px; border-radius:50%; background:#22c55e; box-shadow:0 0 6px #22c55e; }
        .admin-header .admin-id .r{ font-size:.62rem; color:rgba(255,255,255,.45); font-weight:600; }
        .admin-header .admin-info .avatar { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2px solid rgba(108,64,197,0.4); }
        .admin-header .header-bell {
            position: relative; width: 38px; height: 38px; border-radius: 12px; flex: none;
            background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.1); color: #fff;
            display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: .95rem;
        }
        .admin-header .header-bell .hb-badge {
            position: absolute; top: -6px; left: -6px; min-width: 18px; height: 18px; padding: 0 4px;
            background: #ff4d6d; color: #fff; border-radius: 20px; font-size: .6rem; font-weight: 800;
            display: flex; align-items: center; justify-content: center; border: 2px solid #1A0B2E;
        }
        .admin-header .back-btn { background: rgba(255,255,255,0.05); border: none; color: rgba(255,255,255,0.5); padding: 8px 14px; border-radius: 8px; cursor: pointer; font-size: .8rem; display: flex; align-items: center; gap: 6px; transition: background .2s; }
        .admin-header .back-btn:hover { background: rgba(255,255,255,0.1); }
        .panel { background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.06); border-radius: 16px; padding: 16px; margin-bottom: 16px; }
        .list-item .info { flex: 1; min-width: 120px; }
        .list-item .info .title { font-size: .85rem; font-weight: 600; }
        .list-item .info .sub { font-size: .7rem; color: rgba(255,255,255,0.4); }
        .list-item .actions { display: flex; gap: 6px; flex-wrap: wrap; }
        .list-item .actions button { padding: 4px 10px; border: none; border-radius: 6px; font-size: .65rem; font-weight: 600; cursor: pointer; transition: opacity .2s; }
        .list-item .actions button:hover { opacity: .8; }
        .list-item .actions .approve { background: rgba(34,211,160,0.15); color: #22d3a0; }
        .list-item .actions .reject { background: rgba(255,77,109,0.15); color: #ff4d6d; }
        .list-item .actions .view { background: rgba(108,64,197,0.15); color: #a78bfa; }
        .list-item .actions .finalize { background: rgba(56,189,248,0.15); color: #38bdf8; }

        .detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 16px; font-size: .78rem; padding: 8px 0; }
        .detail-grid .label { color: rgba(255,255,255,0.4); }
        .detail-grid .value { font-weight: 600; word-break: break-all; }
        .detail-grid .value.highlight { color: #a78bfa; }

        .spin { animation: spin .7s linear infinite; display: inline-block; }
        @keyframes spin { to { transform: rotate(360deg); } }

        @media (max-width: 768px) { .admin-grid { grid-template-columns: 1fr; } .user-balance-grid { grid-template-columns: repeat(2,1fr); } }
    </style>
</head>
<body>

<!-- ===== HEADER ===== -->
<div class="admin-header">
    <div class="logo">
        <div class="logo-box"><i class="fas fa-crown"></i></div>
        <div class="logo-txt">
            <div class="t">Admin Panel</div>
            <div class="s">خوش آمدید 👋</div>
        </div>
    </div>
    <div class="admin-info">
        <div class="admin-id">
            <div class="n"><span class="dot"></span> <?php echo htmlspecialchars($adminInfo['first_name'] ?: 'مدیر'); ?></div>
            <div class="r">Administrator</div>
        </div>
        <img src="<?php echo htmlspecialchars($adminInfo['avatar'] ?? '/ledor/default-avatar.png'); ?>" alt="Admin" class="avatar" onerror="this.src='/ledor/default-avatar.png'">
        <div class="header-bell" onclick="axGoToTab('overview');" title="درخواست‌ها">
            <i class="fas fa-bell"></i>
            <?php $__axTotalPending = count($latestRequests); if ($__axTotalPending > 0): ?>
                <span class="hb-badge"><?php echo $__axTotalPending > 9 ? '9+' : $__axTotalPending; ?></span>
            <?php endif; ?>
        </div>
        <button class="back-btn" onclick="location.href='dashboard.php'">
            <i class="fas fa-arrow-left"></i> Back
        </button>
    </div>
</div>

<!-- ===== MAIN CONTENT ===== -->
<div class="admin-shell">

    <div class="admin-main">

    <!-- ==================== دسترسی سریع: بخش‌های جداگانه، هرکدام گرید ستونیِ آیکون‌ها ==================== -->
    <?php
    $axQuickGroups = [
        [
            'title' => 'نمای کلی',
            'icon'  => 'fa-gauge-high',
            'items' => [
                ['tab' => 'overview', 'label' => 'داشبورد', 'icon' => 'fa-gauge-high', 'color' => 'linear-gradient(135deg,#7C3AED,#6C40C5)', 'mode' => 'tab'],
            ],
        ],
        [
            'title' => 'کاربران و دسترسی',
            'icon'  => 'fa-users',
            'items' => [
                ['tab' => 'users',       'label' => 'کاربران',              'icon' => 'fa-users',          'color' => 'linear-gradient(135deg,#0EA5E9,#0369A1)', 'multi' => [['online','آنلاین'], ['kyc','ویرایش اطلاعات']]],
                ['tab' => 'blockusers',  'label' => 'مسدودسازی',            'icon' => 'fa-user-lock',      'color' => 'linear-gradient(135deg,#7C3AED,#a78bfa)', 'badge' => 0],
                ['tab' => 'kyc',         'label' => 'KYC',                  'icon' => 'fa-address-card',   'color' => 'linear-gradient(135deg,#2563EB,#1D4ED8)', 'badge' => $stats['pending_kyc']],
                ['tab' => 'impersonate', 'label' => 'ورود به داشبورد کاربر', 'icon' => 'fa-user-secret',   'color' => 'linear-gradient(135deg,#0EA5E9,#0284C7)'],
            ],
        ],
        [
            'title' => 'مالی و درآمد',
            'icon'  => 'fa-sack-dollar',
            'items' => [
                ['tab' => 'manage',      'label' => 'مالی',           'icon' => 'fa-sack-dollar',         'color' => 'linear-gradient(135deg,#16A34A,#15803D)'],
                ['tab' => 'manage',      'label' => 'موجودی',          'icon' => 'fa-wallet',              'color' => 'linear-gradient(135deg,#2563EB,#1D4ED8)'],
                ['tab' => 'topups',      'label' => 'واریزی‌ها',        'icon' => 'fa-credit-card',         'color' => 'linear-gradient(135deg,#D97706,#B45309)', 'badge' => $stats['pending_topups']],
                ['tab' => 'settlements', 'label' => 'تسویه حساب',      'icon' => 'fa-hand-holding-dollar', 'color' => 'linear-gradient(135deg,#16A34A,#15803D)', 'badge' => $stats['pending_withdrawals'], 'badgeId' => 'settlementsBadge'],
                ['tab' => 'revenue',     'label' => 'درآمد ادمین',     'icon' => 'fa-chart-line',          'color' => 'linear-gradient(135deg,#16A34A,#0EA5E9)'],
            ],
        ],
        [
            'title' => 'تبادل و حواله ارزی',
            'icon'  => 'fa-coins',
            'items' => [
                ['tab' => 'exchange',   'label' => 'تبادل ارزی',      'icon' => 'fa-coins',      'color' => 'linear-gradient(135deg,#DB2777,#BE185D)', 'badgeId' => 'exchangeBadge'],
                ['tab' => 'exchange',   'label' => 'تخفیف و کمیسیون', 'icon' => 'fa-percent',    'color' => 'linear-gradient(135deg,#F59E0B,#D97706)', 'anchor' => 'axDiscountAnchor'],
                ['tab' => 'transfers',  'label' => 'حواله ارزی',      'icon' => 'fa-right-left', 'color' => 'linear-gradient(135deg,#DB2777,#BE185D)', 'badgeId' => 'transfersBadge'],
                ['tab' => 'directbuy',  'label' => 'خرید مستقیم',     'icon' => 'fa-bolt',       'color' => 'linear-gradient(135deg,#16A34A,#15803D)', 'badge' => $stats['pending_directbuy'], 'badgeId' => 'directbuyBadge'],
            ],
        ],
        [
            'title' => 'فیش و صورت‌حساب',
            'icon'  => 'fa-file-lines',
            'items' => [
                ['tab' => 'invoices', 'label' => 'فیش‌ها',           'icon' => 'fa-file-lines', 'color' => 'linear-gradient(135deg,#7C3AED,#6D28D9)', 'badge' => $stats['pending_invoices'], 'badgeId' => 'invoiceBadge'],
                ['tab' => 'receipts', 'label' => 'ارسال فیش',        'icon' => 'fa-paperclip',  'color' => 'linear-gradient(135deg,#22d3a0,#6C40C5)'],
            ],
        ],
        [
            'title' => 'بازاریابی و محتوا',
            'icon'  => 'fa-bullhorn',
            'items' => [
                ['tab' => 'stories',   'label' => 'استوری تبلیغاتی',   'icon' => 'fa-circle-play', 'color' => 'linear-gradient(135deg,#FF3B5C,#FF7A59)'],
                ['tab' => 'news',      'label' => 'اخبار',              'icon' => 'fa-newspaper',   'color' => 'linear-gradient(135deg,#7C3AED,#5B21B6)'],
                ['tab' => 'chat',      'label' => 'محتوا و ارتباطات',  'icon' => 'fa-comment',     'color' => 'linear-gradient(135deg,#2563EB,#1D4ED8)', 'badge' => $stats['unread_chats']],
                ['tab' => 'rateposter', 'label' => 'عکس نرخ لحظه‌ای', 'icon' => 'fa-image',      'color' => 'linear-gradient(135deg,#A855F7,#7C3AED)', 'mode' => 'tab'],
                ['tab' => 'referrals', 'label' => 'زیرمجموعه‌ها',       'icon' => 'fa-user-group',  'color' => 'linear-gradient(135deg,#0EA5E9,#0284C7)'],
                ['tab' => 'tiers',     'label' => 'سطح‌بندی',           'icon' => 'fa-gem',         'color' => 'linear-gradient(135deg,#F59E0B,#B45309)'],
            ],
        ],
        [
            'title' => 'سیستم',
            'icon'  => 'fa-gear',
            'items' => [
                ['tab' => 'data',     'label' => 'داده',      'icon' => 'fa-database', 'color' => 'linear-gradient(135deg,#0F766E,#134E4A)', 'multi' => [['userwipe','پاک‌سازی کاربر'], ['backup','بکاپ']]],
                ['tab' => 'siteupdate', 'label' => 'آپدیت سایت', 'icon' => 'fa-cloud-arrow-up', 'color' => 'linear-gradient(135deg,#7C3AED,#4C1D95)', 'mode' => 'tab'],
                ['tab' => 'settings', 'label' => 'تنظیمات',   'icon' => 'fa-gear',     'color' => 'linear-gradient(135deg,#6B7280,#4B5563)'],
            ],
        ],
    ];
    foreach ($axQuickGroups as $grp):
    ?>
    <div class="ax-quick-panel">
        <div class="ax-quick-head"><span><?php echo htmlspecialchars($grp['title']); ?></span> <i class="fas <?php echo $grp['icon']; ?>"></i></div>
        <div class="ax-quick-grid">
            <?php foreach ($grp['items'] as $qi):
                $badge = $qi['badge'] ?? null;
                if (isset($qi['multi'])) {
                    $tabIds = array_map(function($m){ return $m[0]; }, $qi['multi']);
                    $labels = array_map(function($m){ return $m[1]; }, $qi['multi']);
                    $onclick = "axOpenQuickModal('" . htmlspecialchars($qi['label'], ENT_QUOTES) . "', " . json_encode($tabIds) . ", " . json_encode($labels, JSON_UNESCAPED_UNICODE) . ")";
                } elseif (($qi['mode'] ?? '') === 'tab') {
                    $onclick = "axGoToTab('{$qi['tab']}');";
                } else {
                    $onclick = "axOpenQuickModal('" . htmlspecialchars($qi['label'], ENT_QUOTES) . "', " . json_encode([$qi['tab']]) . ", " . json_encode([$qi['label']], JSON_UNESCAPED_UNICODE) . ", " . json_encode($qi['anchor'] ?? null) . ")";
                }
            ?>
                <button type="button" class="ax-quick-item" onclick="<?php echo htmlspecialchars($onclick, ENT_QUOTES); ?>">
                    <span class="ax-quick-ic" style="background:<?php echo $qi['color']; ?>;">
                        <i class="fas <?php echo $qi['icon']; ?>"></i>
                        <?php if (isset($qi['badgeId'])): ?>
                            <span class="ax-quick-badge blink" id="<?php echo $qi['badgeId']; ?>" style="<?php echo ($badge > 0) ? '' : 'display:none;'; ?>"><?php echo $badge > 9 ? '9+' : $badge; ?></span>
                        <?php elseif ($badge !== null && $badge > 0): ?>
                            <span class="ax-quick-badge blink"><?php echo $badge > 9 ? '9+' : $badge; ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="ax-quick-lbl"><?php echo $qi['label']; ?></span>
                </button>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- ==================== آخرین درخواست‌ها (جمع‌شده از همه‌ی بخش‌ها) ==================== -->
    <div class="ax-quick-panel">
        <div class="ax-quick-head"><span>آخرین درخواست‌ها</span> <i class="fas fa-list-check"></i></div>
        <?php if (empty($latestRequests)): ?>
            <div class="ax-empty" style="padding:24px 0;">درخواست در انتظاری وجود ندارد 🎉</div>
        <?php else: ?>
            <div class="ax-latestreq-list">
                <?php foreach ($latestRequests as $lr):
                    $meta = $axLatestReqMeta[$lr['req_type']] ?? ['label' => $lr['req_type'], 'icon' => 'fa-circle-question', 'color' => '#888', 'tab' => 'overview'];
                    $amountTxt = ($lr['amount'] !== null) ? number_format((float)$lr['amount'], ($lr['currency'] === 'IRR' ? 0 : 2)) . ' ' . htmlspecialchars($lr['currency'] ?? '') : '';
                    $agoMin = max(0, floor((time() - strtotime($lr['created_at'])) / 60));
                    $agoTxt = $agoMin < 60 ? ($agoMin . ' دقیقه پیش') : (floor($agoMin/60) . ' ساعت پیش');
                ?>
                <div class="ax-latestreq-row">
                    <div class="ax-latestreq-ic" style="background:<?php echo $meta['color']; ?>22;color:<?php echo $meta['color']; ?>;"><i class="fas <?php echo $meta['icon']; ?>"></i></div>
                    <div class="ax-latestreq-info">
                        <div class="t"><?php echo htmlspecialchars($meta['label']); ?> <span style="color:rgba(255,255,255,.4);font-weight:600;">— <?php echo htmlspecialchars($lr['user_name']); ?></span></div>
                        <div class="s"><?php echo $agoTxt; ?><?php echo $amountTxt ? ' · ' . $amountTxt : ''; ?></div>
                    </div>
                    <button type="button" class="ax-latestreq-btn" onclick="axGoToTab('<?php echo $meta['tab']; ?>');">مشاهده</button>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if($successMsg): ?><div class="alert alert-success">✅ <?php echo $successMsg; ?></div><?php endif; ?>
    <?php if($errorMsg): ?><div class="alert alert-error">❌ <?php echo $errorMsg; ?></div><?php endif; ?>

    <!-- ============================================================
         TAB: OVERVIEW (تابلوی کلی + درخواست‌های جدید)
         ============================================================ -->
    <div class="tab-content active" id="tab-overview">
        <div class="admin-card">
            <div class="admin-card-title"><i class="fas fa-chart-simple"></i> آمار کلی</div>
            <div class="ov-mini-grid">
                <div class="ov-mini"><span class="ov-mini-num"><?php echo $stats['total_users']; ?></span><span class="ov-mini-lbl">کل کاربران</span></div>
                <div class="ov-mini"><span class="ov-mini-num"><?php echo $stats['today_logins']; ?></span><span class="ov-mini-lbl">ورود امروز</span></div>
                <div class="ov-mini"><span class="ov-mini-num"><?php echo $stats['pending_withdrawals']; ?></span><span class="ov-mini-lbl">برداشت در انتظار</span></div>
                <div class="ov-mini"><span class="ov-mini-num"><?php echo $stats['approved_invoices']; ?></span><span class="ov-mini-lbl">فیش تاییدشده</span></div>
            </div>
        </div>

        <!-- ==================== دید کلی موجودی کاربران (مثبت/منفی) ==================== -->
        <div class="admin-card">
            <div class="admin-card-title"><i class="fas fa-scale-balanced"></i> وضعیت موجودی کاربران</div>
            <div class="bal-ov-grid">
                <?php foreach ($balanceOverview as $cur => $bo): ?>
                <div class="bal-ov-card">
                    <div class="bal-ov-cur"><?php echo $cur; ?></div>
                    <div class="bal-ov-row"><span class="bal-ov-pos"><i class="fas fa-arrow-up"></i> <?php echo number_format($bo['pos']); ?></span><span class="bal-ov-lbl">مثبت</span></div>
                    <div class="bal-ov-row"><span class="bal-ov-neg"><i class="fas fa-arrow-down"></i> <?php echo number_format($bo['neg']); ?></span><span class="bal-ov-lbl">منفی</span></div>
                    <?php if ($bo['neg'] > 0): ?>
                    <div class="bal-ov-debt">بدهی: <?php echo number_format($bo['neg_sum'], $cur === 'IRR' ? 0 : 2); ?> <?php echo $cur; ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($negUsersList)): ?>
            <div class="bal-ov-neglist-head" onclick="document.getElementById('balOvNegList').classList.toggle('open'); this.querySelector('i.fa-chevron-down').classList.toggle('rot');">
                <span><i class="fas fa-triangle-exclamation"></i> کاربران دارای موجودی منفی (<?php echo count($negUsersList); ?>)</span>
                <i class="fas fa-chevron-down"></i>
            </div>
            <div id="balOvNegList" class="bal-ov-neglist">
                <?php foreach ($negUsersList as $nu):
                    $fullName = trim(($nu['first_name'] ?? '') . ' ' . ($nu['last_name'] ?? ''));
                    if ($fullName === '') $fullName = '@' . ($nu['telegram_id'] ?? '—');
                ?>
                <div class="bal-ov-negrow" onclick="axGoToTab('manage'); setTimeout(()=>{ const s=document.getElementById('userSelect'); if(s){ s.value='<?php echo (int)$nu['id']; ?>'; s.dispatchEvent(new Event('change')); } }, 250);">
                    <div class="bal-ov-negname"><?php echo htmlspecialchars($fullName); ?></div>
                    <div class="bal-ov-negamts">
                        <?php foreach (['usd'=>'USD','eur'=>'EUR','usdt'=>'USDT','irr'=>'IRR'] as $f=>$lbl): $v=(float)$nu['balance_'.$f]; if ($v<0): ?>
                        <span class="bal-ov-negchip"><?php echo number_format($v, $f==='irr'?0:2); ?> <?php echo $lbl; ?></span>
                        <?php endif; endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($discountUsersList)): ?>
            <div class="bal-ov-neglist-head" style="margin-top:10px;" onclick="document.getElementById('balOvDiscList').classList.toggle('open'); this.querySelector('i.fa-chevron-down').classList.toggle('rot');">
                <span><i class="fas fa-percent"></i> کاربران دارای تخفیف (<?php echo count($discountUsersList); ?>)</span>
                <i class="fas fa-chevron-down"></i>
            </div>
            <div id="balOvDiscList" class="bal-ov-neglist">
                <?php foreach ($discountUsersList as $du):
                    $dFullName = trim(($du['first_name'] ?? '') . ' ' . ($du['last_name'] ?? ''));
                    if ($dFullName === '') $dFullName = '@' . ($du['telegram_id'] ?? '—');
                ?>
                <div class="bal-ov-negrow" onclick="axGoToTab('manage'); setTimeout(()=>{ const s=document.getElementById('userSelect'); if(s){ s.value='<?php echo (int)$du['user_id']; ?>'; s.dispatchEvent(new Event('change')); } }, 250);">
                    <div class="bal-ov-negname"><?php echo htmlspecialchars($dFullName); ?></div>
                    <div class="bal-ov-negamts">
                        <span class="bal-ov-negchip" style="color:#4CD964;border-color:rgba(76,217,100,.35);background:rgba(76,217,100,.1);">%<?php echo number_format((float)$du['discount_percent'], 1); ?> تخفیف</span>
                        <?php if (!empty($du['expires_at'])): ?>
                        <span class="bal-ov-negchip" style="opacity:.7;"><i class="fas fa-clock"></i> تا <?php echo date('Y/m/d', strtotime($du['expires_at'])); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>


    <!-- ============================================================
         TAB: ONLINE (وضعیت آنلاین / زمان ورود کاربران)
         ============================================================ -->
    <div class="tab-content" id="tab-online">
        <div class="admin-card">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px;">
                <div class="admin-card-title" style="margin:0;"><i class="fas fa-users"></i> وضعیت آنلاین و زمان ورود کاربران</div>
                <button onclick="location.reload()" style="background:rgba(255,255,255,0.05);border:1px solid rgba(108,64,197,0.3);color:#9b94b8;padding:6px 12px;border-radius:8px;cursor:pointer;font-size:.72rem;">
                    <i class="fas fa-sync-alt"></i> بروزرسانی
                </button>
            </div>
            <p style="color:rgba(255,255,255,0.45);font-size:.72rem;margin:0 0 14px;">کاربرانی که در ۵ دقیقه گذشته فعال بوده‌اند «آنلاین» علامت خورده‌اند.</p>
            <div class="online-list">
                <?php if (empty($onlineUsersList)): ?>
                    <div style="text-align:center;padding:24px;color:rgba(255,255,255,0.4);">هنوز داده‌ای برای نمایش نیست.</div>
                <?php else: foreach ($onlineUsersList as $ou):
                    $seenTs = !empty($ou['last_seen']) ? strtotime($ou['last_seen']) : 0;
                    $loginTs = !empty($ou['last_login']) ? strtotime($ou['last_login']) : 0;
                    $isOnline = $seenTs && (time() - $seenTs) <= 300;
                    $fullName = trim(($ou['first_name'] ?? '') . ' ' . ($ou['last_name'] ?? ''));
                    if ($fullName === '') $fullName = '@' . ($ou['telegram_id'] ?? '—');
                ?>
                <div class="online-row">
                    <div class="online-dot <?php echo $isOnline ? 'on' : 'off'; ?>"></div>
                    <div class="online-info">
                        <div class="online-name"><?php echo htmlspecialchars($fullName); ?></div>
                        <div class="online-sub">
                            <?php if (!empty($ou['account_number'])): ?>حساب: <?php echo htmlspecialchars($ou['account_number']); ?> · <?php endif; ?>
                            @<?php echo htmlspecialchars($ou['telegram_id'] ?? '—'); ?>
                        </div>
                    </div>
                    <div class="online-times">
                        <?php if ($isOnline): ?>
                            <span class="online-badge on"><i class="fas fa-circle"></i> آنلاین</span>
                        <?php elseif ($seenTs): ?>
                            <span class="online-badge off">آخرین بازدید: <?php echo date('Y/m/d H:i', $seenTs); ?></span>
                        <?php endif; ?>
                        <?php if ($loginTs): ?>
                            <span class="online-login"><i class="fas fa-right-to-bracket"></i> ورود: <?php echo date('Y/m/d H:i', $loginTs); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <!-- ============================================================
         TAB 1: TOP-UPS
         ============================================================ -->
    <div class="tab-content" id="tab-topups">
        <div class="admin-card">
            <div class="admin-card-title"><i class="fas fa-coins"></i> مدیریت درخواست‌های Top-up</div>
            <div class="admin-topup-section">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px;">
                    <div></div>
                    <button onclick="adminLoadTopups(adminCurrentFilter)" style="background:rgba(255,255,255,0.05);border:1px solid rgba(108,64,197,0.3);color:#9b94b8;padding:6px 12px;border-radius:8px;cursor:pointer;font-size:.72rem;">
                        <i class="fas fa-sync-alt"></i> بروزرسانی
                    </button>
                </div>

                <div class="filter-tabs">
                    <button class="filter-tab active" onclick="adminLoadTopups('', this)">همه</button>
                    <button class="filter-tab" onclick="adminLoadTopups('pending', this)">⏳ در انتظار</button>
                    <button class="filter-tab" onclick="adminLoadTopups('approved', this)">✅ تأیید شده</button>
                    <button class="filter-tab" onclick="adminLoadTopups('waiting_payment', this)">📤 در انتظار فیش</button>
                    <button class="filter-tab" onclick="adminLoadTopups('payment_received', this)">🧾 فیش ارسال شده</button>
                    <button class="filter-tab" onclick="adminLoadTopups('completed', this)">🏆 تکمیل شده</button>
                    <button class="filter-tab" onclick="adminLoadTopups('rejected', this)">❌ رد شده</button>
                </div>

                <div id="admin-topup-list">
                    <div class="skeleton" style="height:90px;"></div>
                    <div class="skeleton" style="height:90px;margin-top:12px;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         TAB 2: INVOICES (فیش‌های پرداخت نشده)
         ============================================================ -->
    <div class="tab-content" id="tab-invoices">
        <div class="admin-card">
            <div class="admin-card-title"><i class="fas fa-images"></i> عکس فیش‌های آپلودی (در انتظار بررسی)</div>
            <p style="font-size:.72rem;color:rgba(255,255,255,0.5);margin:0 0 12px;line-height:1.8;">
                همین که کاربری برای یک صورتحساب عکسِ رسید پرداخت آپلود کند، آن عکس این‌جا هم می‌آید — برای مرور سریع، بدون نیاز به باز کردن تک‌تک فیش‌ها. با زدن روی هر عکس، همان فیش برای تأیید یا رد باز می‌شود.
            </p>
            <div id="axReceiptGallery" class="ax-receipt-gallery">
                <div class="skeleton" style="height:70px;"></div>
            </div>
        </div>

        <div class="admin-card">
            <div class="admin-card-title"><i class="fas fa-file-invoice"></i> مدیریت فیش‌های پرداخت نشده</div>
            
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;">
                <button class="btn-sm" onclick="adminLoadInvoices('', this)" style="background:linear-gradient(135deg,#6C40C5,#FF4D8D);color:#fff;border:none;">همه</button>
                <button class="btn-sm" onclick="adminLoadInvoices('pending', this)">⏳ در انتظار</button>
                <button class="btn-sm" onclick="adminLoadInvoices('approved', this)">✅ تأیید شده</button>
                <button class="btn-sm" onclick="adminLoadInvoices('paid', this)">📤 پرداخت شده</button>
                <button class="btn-sm" onclick="adminLoadInvoices('finalized', this)">🏆 تکمیل</button>
                <button class="btn-sm" onclick="adminLoadInvoices('rejected', this)">❌ رد</button>
                <button class="btn-sm" onclick="openCreateInvoice()" style="background:#22d3a0;color:#111;border:none;">
                    <i class="fas fa-plus"></i> فیش جدید
                </button>
            </div>

            <div id="admin-invoice-list">
                <div class="skeleton" style="height:90px;"></div>
                <div class="skeleton" style="height:90px;margin-top:12px;"></div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         TAB 3: MANAGE BALANCE
         ============================================================ -->
    <div class="tab-content" id="tab-manage">
        <div class="admin-card">
            <div class="admin-card-title"><i class="fas fa-money-bill-wave"></i> Manage User Balance</div>
            <form method="POST">
                <input type="hidden" name="balance_action" value="1">
                <input type="hidden" id="operationInput" name="operation" value="increase">
                
                <select name="user_id" id="userSelect" class="form-select" required>
                    <option value="">-- Select User --</option>
                    <?php $usersResult->data_seek(0); while($u = $usersResult->fetch_assoc()): ?>
                    <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['first_name'].' '.$u['last_name'].' (@'.$u['telegram_id'].')'); ?></option>
                    <?php endwhile; ?>
                </select>
                
                <div id="userBalanceGrid" class="user-balance-grid">
                    <div class="balance-card"><div class="currency">USD</div><div class="amount" id="bal_usd">—</div></div>
                    <div class="balance-card"><div class="currency">EUR</div><div class="amount" id="bal_eur">—</div></div>
                    <div class="balance-card"><div class="currency">USDT</div><div class="amount" id="bal_usdt">—</div></div>
                    <div class="balance-card"><div class="currency">IRR</div><div class="amount" id="bal_irr">—</div></div>
                </div>
                
                <div id="userTransactions" class="user-transactions">
                    <h4 style="font-size:.8rem;color:#FFD700;margin-bottom:10px;"><i class="fas fa-history"></i> Last 20 Transactions</h4>
                    <div class="scrollable-tx">
                        <table class="tx-table">
                            <thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Currency</th><th>Description</th></tr></thead>
                            <tbody id="transactionsList"><tr><td colspan="5" style="text-align:center;color:#5a5475;">Select a user</td></tr></tbody>
                        </table>
                    </div>
                </div>
                
                <select name="currency" id="currencySelect" class="form-select" required>
                    <option value="USD">USD (Dollar)</option><option value="EUR">EUR (Euro)</option>
                    <option value="USDT">USDT (Tether)</option><option value="IRR">IRR (Rial)</option>
                </select>
                
                <input type="number" name="amount" id="amountInput" class="form-input" step="0.01" min="0.01" placeholder="Amount" required>
                
                <div class="operation-buttons">
                    <button type="button" class="operation-btn add active" onclick="setOp('increase')">➕ Increase</button>
                    <button type="button" class="operation-btn subtract" onclick="setOp('decrease')">➖ Decrease</button>
                </div>
                
                <div class="checkbox-group" id="allowNegativeGroup" style="display:none">
                    <input type="checkbox" name="allow_negative" id="allowNegative" value="1">
                    <label>⚠️ Allow negative balance (Overdraft)</label>
                </div>

                <div class="checkbox-group" style="background:rgba(124,58,237,0.12);">
                    <input type="checkbox" name="notify_user" id="notifyUserBalance" value="1">
                    <label><i class="fas fa-bell"></i> اطلاع‌رسانی به کاربر (از طریق ایمیل/تلگرام/اعلان درون‌برنامه، هرکدام که کاربر فعال کرده)</label>
                </div>
                
                <input type="text" name="description" class="form-input" placeholder="Description" value="Admin adjustment">
                
                <div class="balance-preview" id="balancePreview">
                    <div class="balance-row"><span>Current:</span><span id="currentBalance">0.00</span></div>
                    <div class="balance-row"><span>Operation:</span><span id="previewOp">Increase</span></div>
                    <div class="balance-row"><span>Amount:</span><span id="previewAmount">0.00</span></div>
                    <div class="balance-row"><span>New Balance:</span><span id="newBalance">0.00</span></div>
                    <div class="balance-row" id="warningRow" style="display:none;color:#FF3B30"><span>⚠️</span><span id="warningMsg"></span></div>
                </div>
                
                <button type="submit" class="btn"><i class="fas fa-save"></i> Update Balance</button>
            </form>
        </div>
    </div>

    <!-- ============================================================
         TAB: ورود مخفیانه به داشبورد کاربر
         ============================================================ -->
    <div class="tab-content" id="tab-impersonate">
        <div class="admin-card">
            <div class="admin-card-title"><i class="fas fa-user-secret"></i> ورود به داشبورد کاربر</div>
            <p style="font-size:.75rem;color:rgba(255,255,255,0.5);margin:0 0 14px;line-height:1.8;">
                یک کاربر را انتخاب کنید تا مستقیماً وارد داشبورد او شوید — دقیقاً همان چیزی که خودِ کاربر می‌بیند.
                این کار هیچ ردی برای خودِ کاربر باقی نمی‌گذارد (زمان آخرین ورود/فعالیتش تغییر نمی‌کند و هیچ اعلانی برایش ارسال نمی‌شود).
                با دکمه‌ی «بازگشت به پنل ادمین» که در نوار بالای داشبورد ظاهر می‌شود، به همین‌جا برمی‌گردید.
            </p>
            <select id="impersonateUserSelect" class="form-select">
                <option value="">-- انتخاب کاربر --</option>
                <?php $usersResult->data_seek(0); while ($u = $usersResult->fetch_assoc()): ?>
                <option value="<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars(trim($u['first_name'] . ' ' . $u['last_name']) . ' (@' . $u['telegram_id'] . ')'); ?></option>
                <?php endwhile; ?>
            </select>
            <button class="btn" style="margin-top:12px;" onclick="impersonateStart()"><i class="fas fa-right-to-bracket"></i> ورود به داشبورد این کاربر</button>
            <div id="impersonateMsg" style="margin-top:10px;font-size:.72rem;"></div>
        </div>
    </div>

    <!-- ============================================================
         TAB: استوری تبلیغاتی (مثل اینستاگرام)
         ============================================================ -->
    <div class="tab-content" id="tab-stories">
        <div class="admin-card">
            <div class="admin-card-title"><i class="fas fa-circle-play"></i> انتشار استوری جدید</div>
            <p style="font-size:.75rem;color:rgba(255,255,255,0.5);margin:0 0 14px;line-height:1.8;">
                عکس یا ویدیو آپلود کنید. برای همه‌ی کاربران یک گوی شناور با حلقه‌ی قرمز (فقط تا وقتی این استوری فعال است) نمایش داده می‌شود؛
                با کلیک روی گوی، تا ۶۰ ثانیه نمایش داده می‌شود یا کاربر می‌تواند تپ کند برود بعدی. دقیقاً ۲۴ ساعت بعد از انتشار، خودکار حذف می‌شود.
            </p>
            <input type="file" id="axStoryFile" accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/webm" class="form-input">
            <input type="text" id="axStoryCaption" class="form-input" placeholder="کپشن (اختیاری)" style="margin-top:8px;">
            <input type="url" id="axStoryLink" class="form-input" placeholder="لینک مقصد (اختیاری — دکمه «مشاهده» زیر استوری)" style="margin-top:8px;">
            <button class="btn" style="margin-top:12px;" onclick="axStoryUpload()" id="axStoryUploadBtn"><i class="fas fa-upload"></i> انتشار استوری</button>
            <div id="axStoryUploadMsg" style="margin-top:10px;font-size:.72rem;"></div>
        </div>

        <div class="admin-card">
            <div class="admin-card-title" style="display:flex;justify-content:space-between;align-items:center;">
                <span><i class="fas fa-list"></i> استوری‌های فعال</span>
                <button class="ax-btn ax-btn-view" onclick="axStoriesLoadList()"><i class="fas fa-sync"></i></button>
            </div>
            <div id="axStoriesList" style="margin-top:12px;"><div class="ax-empty"><i class="fas fa-spinner fa-spin"></i> در حال بارگذاری...</div></div>
        </div>
    </div>

    <!-- ============================================================
         TAB: BLOCK USERS (مسدودسازی)
         ============================================================ -->
    <div class="tab-content" id="tab-blockusers">
        <!-- ==================== مسدودسازی کاربر از ارسال هر نوع درخواست ==================== -->
        <div class="admin-card">
            <div class="admin-card-title"><i class="fas fa-user-lock"></i> مسدودسازی کاربر</div>
            <div style="font-size:.72rem;color:#B8B8D1;margin-bottom:12px;">
                کاربر مسدودشده دیگر نمی‌تواند آگهی ثبت کند، پیشنهاد بفرستد، یا درخواست برداشت/شارژ/حواله ارسال کند.
            </div>
            <select id="banUserSelect" class="form-select" style="margin-bottom:12px;" onchange="loadBanStatus()">
                <option value="">-- انتخاب کاربر --</option>
                <?php $usersResult->data_seek(0); while($u = $usersResult->fetch_assoc()): ?>
                <option value="<?php echo $u['id']; ?>">💬 <?php echo htmlspecialchars($u['first_name'].' '.$u['last_name']); ?> (@<?php echo $u['telegram_id']; ?>)</option>
                <?php endwhile; ?>
            </select>

            <div id="banStatusBox" style="display:none;margin-bottom:12px;padding:10px 12px;border-radius:12px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);">
                <div id="banStatusText" style="font-size:.78rem;font-weight:700;"></div>
                <div id="banReasonText" style="font-size:.7rem;color:#B8B8D1;margin-top:4px;"></div>
            </div>

            <textarea id="banReasonInput" class="form-input" rows="2" placeholder="دلیل مسدودسازی (اختیاری)" style="margin-bottom:12px;"></textarea>

            <div style="display:flex;gap:10px;">
                <button type="button" class="btn-sm" id="banBtn" style="flex:1;background:linear-gradient(135deg,#FF3B30,#C0392B);border:none;color:#fff;padding:10px;border-radius:10px;cursor:pointer;font-family:inherit;font-weight:700;" onclick="toggleBanUser(1)">
                    <i class="fas fa-ban"></i> مسدود کن
                </button>
                <button type="button" class="btn-sm" id="unbanBtn" style="flex:1;background:linear-gradient(135deg,#4CD964,#2ECC71);border:none;color:#fff;padding:10px;border-radius:10px;cursor:pointer;font-family:inherit;font-weight:700;" onclick="toggleBanUser(0)">
                    <i class="fas fa-check-circle"></i> رفع مسدودیت
                </button>
            </div>

            <div style="margin-top:20px;">
                <div style="font-size:.78rem;font-weight:700;color:#FFD700;margin-bottom:10px;"><i class="fas fa-users-slash"></i> کاربران مسدودشده</div>
                <div id="bannedUsersList" style="display:flex;flex-direction:column;gap:8px;">
                    <div style="text-align:center;color:#5a5475;font-size:.72rem;padding:10px;">در حال بارگذاری...</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         TAB 4: CHAT
         ============================================================ -->
    <div class="tab-content" id="tab-chat">
        <div class="admin-card">
            <div class="admin-card-title" style="display:flex;align-items:center;gap:8px;">
                <i class="fas fa-comments"></i> Chat with User
                <span id="chatUnreadTotalBadge" style="display:<?php echo array_sum($unreadByUser) > 0 ? 'inline-flex' : 'none'; ?>;align-items:center;gap:4px;background:#FF5A6E;color:#fff;font-size:.7rem;font-weight:800;padding:2px 9px;border-radius:20px;">
                    <?php echo array_sum($unreadByUser); ?> unread
                </span>
            </div>
            
            <select id="chatUserSelect" class="form-select" style="margin-bottom: 15px;">
                <option value="">-- Select User to Chat --</option>
                <?php
                $__chatUsers = [];
                $usersResult->data_seek(0);
                while ($u = $usersResult->fetch_assoc()) { $__chatUsers[] = $u; }
                usort($__chatUsers, function($a, $b) use ($unreadByUser) {
                    $ua = $unreadByUser[(int)$a['id']] ?? 0;
                    $ub = $unreadByUser[(int)$b['id']] ?? 0;
                    if ($ua !== $ub) return $ub <=> $ua; // بیشترین پیام خوانده‌نشده اول
                    return strcmp($a['first_name'] ?? '', $b['first_name'] ?? '');
                });
                foreach ($__chatUsers as $u):
                    $uCnt = $unreadByUser[(int)$u['id']] ?? 0;
                    $uLabel = $uCnt > 0 ? "🔴 ({$uCnt}) " : "💬 ";
                ?>
                <option value="<?php echo $u['id']; ?>" data-unread="<?php echo $uCnt; ?>"><?php echo $uLabel; ?><?php echo htmlspecialchars($u['first_name'].' '.$u['last_name']); ?> (@<?php echo $u['telegram_id']; ?>)</option>
                <?php endforeach; ?>
            </select>
            
            <div id="chatContainer" class="chat-container">
                <div style="text-align: center; color: #B8B8D1; padding: 40px;">
                    <i class="fas fa-comment-dots" style="font-size: 2rem; margin-bottom: 10px; opacity: 0.5;"></i>
                    <p>Select a user to start chatting</p>
                </div>
            </div>
            
            <form id="chatForm" style="display: none;">
                <input type="hidden" id="chatUserId" name="user_id">
                <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                    <textarea id="chatMessage" name="message" class="form-input" rows="2" placeholder="Type your message here..." style="flex: 1; margin-bottom: 0;"></textarea>
                    <button type="submit" class="btn-sm" style="padding: 12px 20px; background: linear-gradient(135deg, #6C40C5, #FF4D8D); border: none; color: white;">
                        <i class="fas fa-paper-plane"></i> Send
                    </button>
                </div>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <button type="button" class="btn-sm" onclick="document.getElementById('chatFile').click()" style="background: rgba(255,255,255,0.1); border: 1px solid #6C40C5;">
                        <i class="fas fa-paperclip"></i> Attach File
                    </button>
                    <input type="file" id="chatFile" name="file" accept=".jpg,.jpeg,.png,.gif,.pdf,.txt" style="display: none;">
                    <div id="filePreviewChat" class="file-preview" style="flex: 1;"></div>
                </div>
            </form>
        </div>
    </div>

    <!-- ============================================================
         TAB: KYC (احراز هویت)
         ============================================================ -->
    <div class="tab-content" id="tab-kyc">
        <div class="admin-card">
            <div class="admin-card-title"><i class="fas fa-id-card"></i> درخواست‌های احراز هویت</div>
            <div style="display:flex;gap:6px;background:rgba(0,0,0,0.2);padding:3px;border-radius:20px;width:fit-content;margin-bottom:16px;">
                <button class="kyc-admin-tab active" data-status="pending" onclick="adminLoadKyc('pending', this)" style="background:linear-gradient(135deg,#f59e0b,#FF6B35);border:none;color:#fff;padding:6px 14px;border-radius:16px;cursor:pointer;font-size:.72rem;">در انتظار</button>
                <button class="kyc-admin-tab" data-status="approved" onclick="adminLoadKyc('approved', this)" style="background:transparent;border:none;color:rgba(255,255,255,0.5);padding:6px 14px;border-radius:16px;cursor:pointer;font-size:.72rem;">تایید شده</button>
                <button class="kyc-admin-tab" data-status="rejected" onclick="adminLoadKyc('rejected', this)" style="background:transparent;border:none;color:rgba(255,255,255,0.5);padding:6px 14px;border-radius:16px;cursor:pointer;font-size:.72rem;">رد شده</button>
            </div>
            <div id="adminKycList">
                <div style="text-align:center;padding:20px;color:rgba(255,255,255,0.4);"><i class="fas fa-spinner fa-spin"></i></div>
            </div>
        </div>

        <div class="admin-card">
            <div class="admin-card-title" style="display:flex;justify-content:space-between;align-items:center;">
                <span><i class="fab fa-telegram"></i> همگام‌سازی اعضای ربات (mainbot)</span>
                <button class="ax-btn ax-btn-accounts" onclick="kycSyncBotVerified()"><i class="fas fa-sync"></i> همگام‌سازی الان</button>
            </div>
            <p style="font-size:.75rem;color:rgba(255,255,255,0.5);margin:8px 0 0;line-height:1.8;">
                همه‌ی کاربرانی که در ربات تلگرام (mainbot) عضو شده‌اند — نه فقط افرادی که احراز هویتشان تایید شده — بررسی می‌شوند و برای هرکس که از قبل در اپ هم حساب دارد، نام/نام‌خانوادگی/یوزرنیم دقیقاً همان چیزی می‌شود که در پروفایل تلگرامشان است. شماره تلفن فقط وقتی از ربات پر می‌شود که کاربر تا الان خودش شماره‌ای در اپ ثبت نکرده باشد. وضعیت KYC فقط برای کاربرانی که در ربات هم تایید شده‌اند «تایید شده» می‌شود. تاییدهای جدید خودکار همگام می‌شوند؛ این دکمه برای پوشش موارد قدیمی‌تر/عقب‌افتاده است.
            </p>
            <div id="kycBotSyncResult" style="margin-top:10px;font-size:.75rem;color:rgba(255,255,255,0.6);"></div>
        </div>

        <div class="admin-card">
            <div class="admin-card-title" style="display:flex;justify-content:space-between;align-items:center;">
                <span><i class="fas fa-image"></i> عکس پروفایل تلگرام → عکس پروفایل AVA PAY</span>
                <button class="ax-btn ax-btn-accounts" onclick="kycSyncTelegramAvatars()"><i class="fas fa-sync"></i> همگام‌سازی همه</button>
            </div>
            <p style="font-size:.75rem;color:rgba(255,255,255,0.5);margin:8px 0 0;line-height:1.8;">
                عکس پروفایل عمومیِ تلگرامِ کاربرانی که هنوز عکسی در اپ آپلود نکرده‌اند (عکس پیش‌فرض) دانلود و در اپ تنظیم می‌شود؛ عکسی که خود کاربر در پروفایلش گذاشته هرگز بازنویسی نمی‌شود. کاربرانی که عکس پروفایل عمومی در تلگرام ندارند رد می‌شوند و آواتار پیش‌فرض برایشان باقی می‌ماند. به‌خاطر محدودیت هاست، هر بار روی یک دسته از کاربران اجرا می‌شود و در صورت باقی‌ماندن کاربر، خودش دوباره تا پایان ادامه می‌دهد.
            </p>
            <div id="kycAvatarSyncResult" style="margin-top:10px;font-size:.75rem;color:rgba(255,255,255,0.6);"></div>
        </div>

        <div class="admin-card">
            <div class="admin-card-title"><i class="fas fa-user-edit"></i> ویرایش اطلاعات کاربر</div>
            <div class="ax-form-row">
                <input type="text" id="kycUserFilter" class="ax-input" placeholder="فیلتر لیست کاربران (نام / ایمیل / موبایل / آیدی تلگرام)..." oninput="kycFilterUserSelect()">
            </div>
            <div class="ax-form-row" style="margin-top:8px;">
                <select id="kycUserSelect" class="ax-select" onchange="kycUserPicked()">
                    <option value="">— یک کاربر را انتخاب کنید —</option>
                </select>
            </div>
            <div id="kycUserEditBox" style="margin-top:14px;"></div>
        </div>
    </div>

    <!-- ============================================================
         TAB: NEWS (اخبار)
         ============================================================ -->
    <div class="tab-content" id="tab-news">
        <div class="admin-card">
            <div class="admin-card-title"><i class="fas fa-newspaper"></i> مدیریت اخبار / News Management</div>

            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:16px;">
                <button class="btn" onclick="openNewsForm()" style="background:linear-gradient(135deg,#38bdf8,#6C40C5);">
                    <i class="fas fa-plus"></i> افزودن خبر جدید
                </button>
                <div style="display:flex;gap:6px;background:rgba(0,0,0,0.2);padding:3px;border-radius:20px;">
                    <button class="news-admin-langtab active" data-lang="" onclick="adminLoadNews('', this)" style="background:transparent;border:none;color:#fff;padding:6px 14px;border-radius:16px;cursor:pointer;font-size:.72rem;">همه</button>
                    <button class="news-admin-langtab" data-lang="en" onclick="adminLoadNews('en', this)" style="background:transparent;border:none;color:rgba(255,255,255,0.5);padding:6px 14px;border-radius:16px;cursor:pointer;font-size:.72rem;">English</button>
                    <button class="news-admin-langtab" data-lang="fa" onclick="adminLoadNews('fa', this)" style="background:transparent;border:none;color:rgba(255,255,255,0.5);padding:6px 14px;border-radius:16px;cursor:pointer;font-size:.72rem;">فارسی</button>
                </div>
            </div>

            <div id="adminNewsList">
                <div style="text-align:center;padding:20px;color:rgba(255,255,255,0.4);"><i class="fas fa-spinner fa-spin"></i></div>
            </div>
        </div>
    </div>

    <!-- News Form Modal (admin) -->
    <div class="modal-overlay" id="news-form-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:4000;align-items:center;justify-content:center;">
        <div style="background:linear-gradient(135deg,#1A0B2E,#2A0D3F);border:1px solid rgba(255,255,255,0.12);border-radius:20px;padding:24px;width:92%;max-width:520px;max-height:90vh;overflow-y:auto;position:relative;">
            <button onclick="closeNewsForm()" style="position:absolute;top:14px;left:14px;background:rgba(255,255,255,0.08);border:none;color:#fff;width:34px;height:34px;border-radius:50%;cursor:pointer;"><i class="fas fa-times"></i></button>
            <h2 style="color:#fff;font-size:1.1rem;margin-bottom:18px;"><i class="fas fa-newspaper" style="color:#38bdf8;"></i> <span id="newsFormTitle">افزودن خبر</span></h2>
            <input type="hidden" id="newsId" value="">
            <label style="color:rgba(255,255,255,0.6);font-size:.8rem;display:block;margin-bottom:6px;">زبان / Language</label>
            <select id="newsLangSel" style="width:100%;padding:11px;border-radius:10px;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.12);color:#fff;margin-bottom:14px;">
                <option value="en">English</option>
                <option value="fa">فارسی</option>
            </select>
            <label style="color:rgba(255,255,255,0.6);font-size:.8rem;display:block;margin-bottom:6px;">عنوان / Title</label>
            <input type="text" id="newsTitleInput" style="width:100%;padding:11px;border-radius:10px;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.12);color:#fff;margin-bottom:14px;box-sizing:border-box;">
            <label style="color:rgba(255,255,255,0.6);font-size:.8rem;display:block;margin-bottom:6px;">متن خبر / Body</label>
            <textarea id="newsBodyInput" rows="7" style="width:100%;padding:11px;border-radius:10px;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.12);color:#fff;margin-bottom:14px;box-sizing:border-box;resize:vertical;"></textarea>
            <label style="color:rgba(255,255,255,0.6);font-size:.8rem;display:block;margin-bottom:6px;">عکس (اختیاری) / Image</label>
            <input type="file" id="newsImageInput" accept="image/*" style="width:100%;padding:9px;border-radius:10px;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.12);color:#fff;margin-bottom:8px;box-sizing:border-box;">
            <div id="newsImagePreview" style="margin-bottom:14px;"></div>
            <button class="btn" id="newsSaveBtn" onclick="saveNews()" style="width:100%;background:linear-gradient(135deg,#38bdf8,#6C40C5);"><i class="fas fa-check"></i> ذخیره خبر</button>
            <div id="newsFormFeedback" style="text-align:center;margin-top:12px;font-size:.85rem;"></div>
        </div>
    </div>

    <!-- ============================================================
         TAB: TRANSFERS (حواله ارزی) — منتقل‌شده از money_transfer.php
         ============================================================ -->
    <div class="tab-content" id="tab-transfers">
        <div class="admin-card">
            <div class="admin-card-title"><i class="fas fa-money-bill-transfer"></i> مدیریت حواله‌های ارزی</div>
            <div class="ax-filterbar">
                <button class="ax-filter active" data-tfilter="all" onclick="axLoadTransfers('all', this)">همه</button>
                <button class="ax-filter" data-tfilter="pending" onclick="axLoadTransfers('pending', this)">در انتظار تایید</button>
                <button class="ax-filter" data-tfilter="active" onclick="axLoadTransfers('active', this)">در جریان</button>
                <button class="ax-filter" data-tfilter="completed" onclick="axLoadTransfers('completed', this)">تکمیل‌شده</button>
                <button class="ax-filter" data-tfilter="archived" onclick="axLoadTransfers('archived', this)">🗄 آرشیو</button>
            </div>
            <div id="axTransfersList" style="margin-top:14px;"><div class="ax-empty"><i class="fas fa-spinner fa-spin"></i> در حال بارگذاری...</div></div>
        </div>
    </div>

    <!-- ============================================================
         TAB: DIRECT BUY (خرید مستقیم) — درخواست‌های کاربران از direct_buy.php
         ============================================================ -->
    <div class="tab-content" id="tab-directbuy">
        <div class="admin-card">
            <div class="admin-card-title"><i class="fas fa-bolt"></i> درخواست‌های خرید مستقیم</div>
            <div class="ax-filterbar">
                <button class="ax-filter active" data-dbfilter="pending" onclick="axLoadDirectBuy('pending', this)">در انتظار بررسی</button>
                <button class="ax-filter" data-dbfilter="available" onclick="axLoadDirectBuy('available', this)">موجود شده</button>
                <button class="ax-filter" data-dbfilter="invoiced" onclick="axLoadDirectBuy('invoiced', this)">صورت‌حساب صادرشده</button>
                <button class="ax-filter" data-dbfilter="completed" onclick="axLoadDirectBuy('completed', this)">تکمیل‌شده</button>
                <button class="ax-filter" data-dbfilter="cancelled" onclick="axLoadDirectBuy('cancelled', this)">لغوشده</button>
                <button class="ax-filter" data-dbfilter="all" onclick="axLoadDirectBuy('all', this)">همه</button>
            </div>
            <div id="axDirectBuyList" style="margin-top:14px;"><div class="ax-empty"><i class="fas fa-spinner fa-spin"></i> در حال بارگذاری...</div></div>
        </div>
    </div>

    <!-- ============================================================
         TAB: SETTLEMENTS (تسویه حساب) — منتقل‌شده از withdrawal_modal_system.php
         ============================================================ -->
    <div class="tab-content" id="tab-settlements">
        <div class="admin-card">
            <div class="admin-card-title"><i class="fas fa-hand-holding-dollar"></i> مدیریت تسویه حساب</div>
            <div class="ax-filterbar">
                <button class="ax-filter active" data-sfilter="pending" onclick="axLoadSettlements('pending', this)">در انتظار تایید</button>
                <button class="ax-filter" data-sfilter="approved" onclick="axLoadSettlements('approved', this)">در انتظار فیش</button>
                <button class="ax-filter" data-sfilter="completed" onclick="axLoadSettlements('completed', this)">تکمیل‌شده</button>
                <button class="ax-filter" data-sfilter="archived" onclick="axLoadSettlements('archived', this)">🗄 آرشیو</button>
            </div>
            <div id="axSettlementsList" style="margin-top:14px;"><div class="ax-empty"><i class="fas fa-spinner fa-spin"></i> در حال بارگذاری...</div></div>
        </div>

        <div class="admin-card">
            <div class="admin-card-title"><i class="fas fa-credit-card"></i> کارت‌های بانکی شرکت</div>
            <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px;">
                <input type="text" id="axCardOwner" placeholder="به نام (صاحب کارت)" style="flex:1;min-width:120px;">
                <input type="text" id="axCardBank" placeholder="نام بانک" style="flex:1;min-width:120px;">
                <input type="text" id="axCardNumber" placeholder="شماره کارت/شبا" style="flex:1;min-width:140px;" inputmode="numeric">
                <button class="ax-btn ax-btn-accounts" onclick="axAddCompanyCard()"><i class="fas fa-plus"></i> افزودن کارت</button>
            </div>
            <div id="axCardsList"><div class="ax-empty"><i class="fas fa-spinner fa-spin"></i> در حال بارگذاری...</div></div>
        </div>

        <div class="admin-card">
            <div class="admin-card-title"><i class="fas fa-file-invoice-dollar"></i> صورتحساب واریزی به تفکیک ارز</div>
            <p style="color:rgba(255,255,255,0.5);font-size:.72rem;margin:0 0 10px;">جمع کل تسویه‌های تکمیل‌شده به هر کارت شرکت، به تفکیک ارز.</p>
            <div id="axCardStatsList"><div class="ax-empty"><i class="fas fa-spinner fa-spin"></i> در حال بارگذاری...</div></div>
        </div>
    </div>

    <!-- ============================================================
         TAB: EXCHANGE (تبادل ارزی) — منتقل‌شده از arad.php
         ============================================================ -->
    <div class="tab-content" id="tab-exchange">
        <div class="admin-card">
            <div class="admin-card-title"><i class="fas fa-handshake"></i> معاملات تازه‌توافق‌شده</div>
            <p style="font-size:.72rem;color:rgba(255,255,255,0.5);margin:0 0 12px;line-height:1.8;">
                وقتی آگهی‌دهنده و متقاضی روی یک قیمت توافق می‌کنند، همان معامله این‌جا می‌آید. با زدن روی نامِ هرکدام، فرمِ «ایجاد فیش جدید» (با نوعِ «تبادل ارزی») برای همان کاربر باز می‌شود تا حساب برایش بفرستید.
            </p>
            <div class="ax-filterbar">
                <button class="ax-filter active" data-dfilter="pending" onclick="axLoadDeals('pending', this)">در انتظار</button>
                <button class="ax-filter" data-dfilter="completed" onclick="axLoadDeals('completed', this)">تکمیل‌شده</button>
                <button class="ax-filter" data-dfilter="archived" onclick="axLoadDeals('archived', this)">🗄 آرشیو</button>
            </div>
            <div id="axDealsList" style="margin-top:14px;"><div class="ax-empty"><i class="fas fa-spinner fa-spin"></i> در حال بارگذاری...</div></div>
        </div>

        <div class="admin-card">
            <div class="admin-card-title"><i class="fas fa-exchange-alt"></i> معاملات در انتظار تسویه (تبادل ارزی)</div>
            <p style="font-size:.72rem;color:rgba(255,255,255,0.5);margin:0 0 12px;line-height:1.8;">
                این‌جا فقط فیش‌هایی نشان داده می‌شوند که هنگام ساختن، نوعشان «تبادل ارزی» انتخاب شده باشد — یعنی حسابی که برای تسویه‌ی یک معامله بین دو کاربر فرستاده شده. فیش‌های آپلودی کاربران را هم از همین‌جا یا از تب «فیش‌ها» بررسی و تأیید کنید.
            </p>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;">
                <button class="btn-sm ax-xfilter active" onclick="axLoadExchangeInvoices('', this)" style="background:linear-gradient(135deg,#6C40C5,#FF4D8D);color:#fff;border:none;">همه</button>
                <button class="btn-sm ax-xfilter" onclick="axLoadExchangeInvoices('pending', this)">⏳ در انتظار</button>
                <button class="btn-sm ax-xfilter" onclick="axLoadExchangeInvoices('approved', this)">✅ تأیید شده</button>
                <button class="btn-sm ax-xfilter" onclick="axLoadExchangeInvoices('paid', this)">📤 پرداخت شده</button>
                <button class="btn-sm ax-xfilter" onclick="axLoadExchangeInvoices('finalized', this)">🏆 تسویه‌شده</button>
                <button class="btn-sm ax-xfilter" onclick="axLoadExchangeInvoices('rejected', this)">❌ رد</button>
                <button class="btn-sm" onclick="axOpenCreateExchangeInvoice()" style="background:#22d3a0;color:#111;border:none;">
                    <i class="fas fa-plus"></i> حساب جدید برای تسویه
                </button>
            </div>
            <div id="axExchangeInvoiceList">
                <div class="skeleton" style="height:90px;"></div>
                <div class="skeleton" style="height:90px;margin-top:12px;"></div>
            </div>
        </div>

        <div class="admin-card">
            <div class="admin-card-title"><i class="fas fa-globe"></i> کمیسیون پیش‌فرض برای همه‌ی کاربران</div>
            <p style="font-size:.75rem;color:rgba(255,255,255,0.5);margin:0 0 12px;line-height:1.8;">
                این کمیسیون برای هر کاربری اعمال می‌شود که خودش قانون کمیسیون اختصاصی نداشته باشد (به‌جای فرمول پایه‌ی ثابت). دقیقاً مثل کمیسیون اختصاصی، «بر اساس ارز آگهی» روی هر ارزی که معامله می‌شود اعمال می‌شود و «بر اساس تومان» فقط و فقط تومان است.
            </p>
            <div class="ax-form-row">
                <select id="axDefRuleUnit" class="ax-select">
                    <option value="currency">بر اساس ارز آگهی (همه‌ی ارزها)</option>
                    <option value="toman">فقط بر اساس مبلغ کل معامله به تومان</option>
                </select>
            </div>
            <div class="ax-form-row" style="margin-top:6px;">
                <input type="number" id="axDefRuleMin" placeholder="از مبلغ">
                <input type="number" id="axDefRuleMax" placeholder="تا مبلغ (خالی=نامحدود)">
                <input type="number" id="axDefRuleVal" placeholder="مقدار کمیسیون (پلکانی)">
                <button class="ax-btn ax-btn-accounts" onclick="axSaveDefaultRule()"><i class="fas fa-plus"></i> افزودن</button>
            </div>
            <div id="axDefRuleList" style="margin-top:10px;font-size:.72rem;color:rgba(255,255,255,0.75);"></div>
        </div>

        <div class="admin-card">
            <div id="axDiscountAnchor"></div>
            <div class="admin-card-title"><i class="fas fa-percent"></i> تخفیف و کمیسیون</div>
            <div class="ax-form-row">
                <input type="text" id="axDiscountFilter" class="ax-input" placeholder="فیلتر لیست کاربران (نام / آیدی تلگرام)..." oninput="axFilterDiscountUserSelect()">
            </div>
            <div class="ax-form-row" style="margin-top:8px;">
                <select id="axDiscountUserSelect" class="ax-select" onchange="axDiscountUserPicked()">
                    <option value="">— یک کاربر را انتخاب کنید —</option>
                </select>
            </div>
            <div id="axDiscountResults" style="margin-top:14px;"></div>
        </div>

        <div class="admin-card">
            <div class="admin-card-title" style="display:flex;justify-content:space-between;align-items:center;">
                <span><i class="fas fa-users"></i> کاربران دارای تخفیف فعال</span>
                <button class="ax-btn ax-btn-view" onclick="axLoadDiscountList()"><i class="fas fa-sync"></i></button>
            </div>
            <div id="axDiscountList" style="margin-top:12px;"><div class="ax-empty"><i class="fas fa-spinner fa-spin"></i> در حال بارگذاری...</div></div>
        </div>
        <div class="admin-card">
            <div class="admin-card-title" style="display:flex;justify-content:space-between;align-items:center;">
                <span><i class="fas fa-lock"></i> کاربران دارای کمیسیون ثابت</span>
                <button class="ax-btn ax-btn-view" onclick="axLoadFixedCommissionList()"><i class="fas fa-sync"></i></button>
            </div>
            <div id="axFixedCommissionList" style="margin-top:12px;"><div class="ax-empty"><i class="fas fa-spinner fa-spin"></i> در حال بارگذاری...</div></div>
        </div>
    </div>

    <!-- ============================================================
         TAB: REFERRALS (کاربران دعوت‌شده / زیرمجموعه‌گیری)
         ============================================================ -->
    <div class="tab-content" id="tab-referrals">
        <div class="admin-card">
            <div class="admin-card-title"><i class="fas fa-sliders-h"></i> تنظیمات برنامه‌ی زیرمجموعه‌گیری</div>
            <p style="font-size:.75rem;color:rgba(255,255,255,0.5);margin:0 0 12px;line-height:1.8;">
                این مقادیر برای همه‌ی کاربران به‌صورت یکسان اعمال می‌شود. جایزه‌ی خوش‌آمدگویی هنگام ثبت‌نام کاربر دعوت‌شده و پورسانت تراکنش به‌ازای هر معامله‌ی موفق از سوی او، مستقیماً به کیف‌پول یورویی معرف واریز می‌شود.
            </p>
            <div class="ax-form-row">
                <input type="number" id="arfAdminWelcome" class="ax-input" placeholder="جایزه خوش‌آمدگویی (یورو)" step="0.01" min="0">
                <input type="number" id="arfAdminCommission" class="ax-input" placeholder="پورسانت هر تراکنش (یورو)" step="0.01" min="0">
                <input type="number" id="arfAdminMinSettle" class="ax-input" placeholder="حداقل مبلغ تسویه (یورو)" step="0.01" min="0">
                <button class="ax-btn ax-btn-accounts" onclick="arfAdminSaveSettings()"><i class="fas fa-save"></i> ذخیره</button>
            </div>
            <div id="arfAdminSettingsMsg" style="margin-top:10px;font-size:.72rem;"></div>
        </div>

        <div class="admin-card">
            <div class="admin-card-title" style="display:flex;justify-content:space-between;align-items:center;">
                <span><i class="fas fa-user-friends"></i> کاربران دعوت‌شده</span>
                <button class="ax-btn ax-btn-view" onclick="arfAdminLoadList()"><i class="fas fa-sync"></i></button>
            </div>
            <div class="ax-form-row" style="margin-top:10px;">
                <input type="text" id="arfAdminSearch" class="ax-input" placeholder="جستجو بر اساس نام معرف/دعوت‌شده یا کد معرف..." oninput="arfAdminSearchDebounced()">
            </div>
            <div id="arfAdminList" style="margin-top:14px;"><div class="ax-empty"><i class="fas fa-spinner fa-spin"></i> در حال بارگذاری...</div></div>
        </div>
    </div>

    <!-- ============================================================
         TAB: سطح‌بندی کاربران (Tier / VIP) — تخفیف کمیسیون خودکار
         بر اساس حجم معاملات تکمیل‌شده (تومان)
         ============================================================ -->
    <div class="tab-content" id="tab-tiers">
        <div class="admin-card">
            <div class="admin-card-title"><i class="fas fa-coins"></i> ارز مبنای سطح‌بندی</div>
            <p style="font-size:.75rem;color:rgba(255,255,255,0.5);margin:0 0 12px;line-height:1.8;">
                حجم معاملات هر کاربر بر اساس همین ارز محاسبه و با «حداقل حجم» هر سطح مقایسه می‌شود.
                اگر تومان انتخاب شود، مجموع ارزش تومانیِ کل معاملات کاربر حساب می‌شود؛ اگر یک ارز دیگر (مثلاً یورو) انتخاب شود،
                فقط مجموع معاملاتی که دقیقاً با همان ارز انجام شده‌اند حساب می‌شود.
            </p>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <select id="tierCurrencySelect" class="form-input" style="flex:1;min-width:160px;margin:0;">
                    <option value="IRR">تومان (IRR)</option>
                    <option value="USD">دلار (USD)</option>
                    <option value="EUR">یورو (EUR)</option>
                    <option value="USDT">تتر (USDT)</option>
                </select>
                <button class="ax-btn ax-btn-accounts" style="width:auto;" onclick="tierSaveCurrency()"><i class="fas fa-save"></i> ذخیره ارز</button>
            </div>
            <div id="tierCurrencyMsg" style="margin-top:10px;font-size:.72rem;"></div>
        </div>

        <div class="admin-card">
            <div class="admin-card-title" style="display:flex;justify-content:space-between;align-items:center;">
                <span><i class="fas fa-gem"></i> سطوح تعریف‌شده</span>
                <button class="ax-btn ax-btn-accounts" onclick="tierOpenForm(null)"><i class="fas fa-plus"></i> سطح جدید</button>
            </div>
            <p style="font-size:.75rem;color:rgba(255,255,255,0.5);margin:0 0 12px;line-height:1.8;">
                هر کاربر بر اساس مجموع حجم معاملات تکمیل‌شده‌اش (به ارز انتخاب‌شده در بالا) به‌صورت خودکار در یکی از این سطوح قرار می‌گیرد و
                درصد تخفیف کمیسیون آن سطح، فقط اگر از تخفیف دستی فعلی کاربر بیشتر باشد، به‌طور خودکار روی معاملاتش اعمال می‌شود.
            </p>
            <div id="tierListWrap"><div class="ax-empty"><i class="fas fa-spinner fa-spin"></i> در حال بارگذاری...</div></div>
        </div>

        <div class="admin-card" id="tierFormCard" style="display:none;">
            <div class="admin-card-title"><i class="fas fa-edit"></i> <span id="tierFormTitle">سطح جدید</span></div>
            <input type="hidden" id="tierFormId" value="">
            <input type="text" id="tierFormName" class="form-input" placeholder="نام سطح (مثال: طلایی)">
            <input type="number" id="tierFormMinVolume" class="form-input" placeholder="حداقل حجم معاملات" step="1" min="0">
            <input type="number" id="tierFormDiscount" class="form-input" placeholder="درصد تخفیف کمیسیون" step="0.1" min="0" max="100">
            <input type="text" id="tierFormIcon" class="form-input" placeholder="آیکن Font Awesome (مثال: fa-gem)" value="fa-medal">
            <input type="color" id="tierFormColor" class="form-input" style="height:44px;padding:4px;" value="#B8860B">
            <div style="display:flex;gap:10px;">
                <button class="btn" style="margin-top:0;" onclick="tierSave()"><i class="fas fa-save"></i> ذخیره</button>
                <button class="btn-sm" style="width:auto;" onclick="document.getElementById('tierFormCard').style.display='none';">انصراف</button>
            </div>
            <div id="tierFormMsg" style="margin-top:10px;font-size:.72rem;"></div>
        </div>
    </div>

    <!-- ============================================================
         TAB: داده — پاک‌سازی داده‌ی کاربر + بکاپ
         ============================================================ -->
    <div class="tab-content" id="tab-userwipe">
        <div class="admin-card">
            <div class="admin-card-title"><i class="fas fa-user-slash" style="color:#f97316;"></i> پاک‌سازی کامل تاریخچه‌ی ارزی یک کاربر</div>
            <p style="font-size:.75rem;color:rgba(255,255,255,0.55);margin:0 0 14px;line-height:1.9;">
                این عملیات تاریخچه‌ی تراکنش‌ها/معاملات/حواله‌ها/درخواست‌های کاربر و همچنین
                <b style="color:#fbbf24;">موجودی کیف پول او (همه‌ی ارزها)</b> را برای همیشه پاک/صفر می‌کند —
                فقط خودِ پروفایل (نام، ایمیل، شماره تلگرام و مشخصات ورود) دست‌نخورده باقی می‌ماند.
                <b style="color:#fbbf24;">این عملیات غیرقابل بازگشت است</b> (مگر با ریستور از یک بکاپ قدیمی‌تر از بخش «بکاپ»).
                توجه: چون برخی رکوردها (مثل معاملات تبادل ارزی) بین دو کاربر مشترک‌اند، طرف مقابل معامله هم دیگر آن را در تاریخچه‌ی خودش نخواهد دید.
            </p>
            <div style="position:relative;">
                <input type="text" id="dmUserSearch" class="form-input" placeholder="جستجوی کاربر (نام، ایمیل، تلفن، آیدی تلگرام)..." oninput="dmSearchUsers(this.value)" autocomplete="off">
                <div id="dmUserSearchResults" style="display:none;position:absolute;top:100%;right:0;left:0;background:#1c1030;border:1px solid rgba(255,255,255,0.1);border-radius:10px;margin-top:4px;max-height:280px;overflow-y:auto;z-index:20;"></div>
            </div>
            <div id="dmSelectedUserBox" style="display:none;margin-top:16px;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:14px;">
                <div id="dmSelectedUserInfo" style="font-size:.85rem;font-weight:700;color:#fff;margin-bottom:10px;"></div>
                <div id="dmSummaryList" style="display:flex;flex-direction:column;gap:6px;font-size:.75rem;"></div>
                <div style="margin-top:14px;display:flex;gap:8px;">
                    <button class="btn-admin" style="background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.45);color:#f87171;" onclick="dmOpenWipeConfirm()">
                        <i class="fas fa-trash-can"></i> پاک‌سازی کامل داده‌های این کاربر
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="tab-content" id="tab-backup">
        <div class="admin-card">
            <div class="admin-card-title" style="display:flex;justify-content:space-between;align-items:center;">
                <span><i class="fas fa-database" style="color:#14b8a6;"></i> بکاپ خودکار دیتابیس</span>
                <button class="ax-btn ax-btn-accounts" onclick="dmRunBackupNow()" id="dmBackupNowBtn"><i class="fas fa-cloud-arrow-up"></i> بک‌آپ فوری</button>
            </div>
            <p style="font-size:.75rem;color:rgba(255,255,255,0.55);margin:0 0 10px;line-height:1.9;">
                هر روز به‌صورت خودکار (بدون نیاز به تنظیم چیزی) از کل دیتابیس یک بک‌آپ گرفته می‌شود؛
                فقط کافی است حداقل یک‌بار در روز پنل ادمین باز شود. آخرین ۱۴ بک‌آپ نگه داشته می‌شود.
                اگر اطلاعاتی گم شد، از همین‌جا می‌توانید یکی از بکاپ‌های زیر را ریستور کنید، یا بکاپ‌های قدیمی‌ای که دیگر لازم ندارید را حذف کنید.
            </p>
            <div id="dmLastAutoInfo" style="font-size:.72rem;color:rgba(255,255,255,0.45);margin-bottom:12px;"></div>
            <div id="dmBackupList"><div class="ax-empty"><i class="fas fa-spinner fa-spin"></i> در حال بارگذاری...</div></div>
        </div>
    </div>

    <!-- ============================================================
         TAB: آپدیت سایت (آپلود zip → جایگزینیِ کاملِ فایل‌های سایت)
         ============================================================ -->
    <div class="tab-content" id="tab-siteupdate">
        <div class="admin-card">
            <div class="admin-card-title"><i class="fas fa-cloud-arrow-up" style="color:#A855F7;"></i> آپدیت سایت</div>
            <p style="font-size:.75rem;color:rgba(255,255,255,.55);margin:0 0 14px;line-height:1.9;">
                فایل zip کاملِ پروژه را اینجا آپلود کنید. همین که «شروع آپدیت» را بزنید، همه‌ی کاربران (به‌جز خودتان) پیامِ «سایت در حال آپدیت» را با شمارش‌معکوس می‌بینند.
                پیش از جایگزینیِ فایل‌ها، خودکار یک بکاپِ ایمنی از وضعیتِ فعلی گرفته می‌شود؛ اگر آپدیت به هر دلیلی با خطا مواجه شود، سایت خودکار به همان بکاپ بازمی‌گردد.
            </p>

            <div id="suActiveBanner" style="display:none; margin-bottom:14px; padding:12px; border-radius:12px; background:rgba(251,191,36,.12); border:1px solid rgba(251,191,36,.35); font-size:.75rem; color:#FBBF24;">
                <i class="fas fa-triangle-exclamation"></i> حالتِ آپدیت الان روشن است — کاربران عادی پیامِ شمارش‌معکوس می‌بینند.<br>
                <button type="button" class="ax-btn" style="margin-top:8px;background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.4);color:#f87171;" onclick="suEndMaintenance()"><i class="fas fa-power-off"></i> خاموش‌کردنِ دستیِ حالت آپدیت</button>
            </div>

            <label style="font-size:.72rem;color:rgba(255,255,255,.6);display:block;margin-bottom:6px;">فایل zip پروژه</label>
            <input type="file" id="suZipInput" accept=".zip" class="form-input" style="margin-bottom:12px;">

            <label style="font-size:.72rem;color:rgba(255,255,255,.6);display:block;margin-bottom:6px;">پیامی که کاربران می‌بینند</label>
            <textarea id="suMessage" class="form-input" rows="2" style="margin-bottom:12px;">سایت در حال آپدیت است. لطفاً چند لحظه‌ی دیگر دوباره سر بزنید.</textarea>

            <label style="font-size:.72rem;color:rgba(255,255,255,.6);display:block;margin-bottom:6px;">مدتِ نمایشِ شمارش‌معکوس (ثانیه)</label>
            <input type="number" id="suDuration" class="form-input" value="90" min="20" max="900" style="margin-bottom:12px;">

            <label style="display:flex;align-items:center;gap:8px;font-size:.72rem;color:rgba(255,255,255,.6);margin-bottom:8px;">
                <input type="checkbox" id="suIncludeConfig"> فایل‌های config/ (اطلاعات دیتابیس) هم بازنویسی شوند
            </label>
            <label style="display:flex;align-items:center;gap:8px;font-size:.72rem;color:rgba(255,255,255,.6);margin-bottom:16px;">
                <input type="checkbox" id="suIncludeUploads"> پوشه‌ی uploads/ (فیش‌ها و مدارکِ کاربران) هم بازنویسی شود
            </label>

            <button type="button" class="ax-btn ax-btn-accounts" style="width:100%;justify-content:center;" onclick="suOpenConfirm()" id="suStartBtn"><i class="fas fa-rocket"></i> شروع آپدیت</button>
            <div id="suProgressMsg" style="margin-top:10px;font-size:.75rem;"></div>
        </div>

        <div class="admin-card">
            <div class="admin-card-title"><i class="fas fa-clock-rotate-left" style="color:#14b8a6;"></i> بکاپ‌های پیش از آپدیت</div>
            <p style="font-size:.72rem;color:rgba(255,255,255,.5);margin:0 0 10px;">پیش از هر آپدیت، خودکار یک نسخه از فایل‌های فعلیِ سایت اینجا نگه داشته می‌شود.</p>
            <div id="suBackupList"><div class="ax-empty"><i class="fas fa-spinner fa-spin"></i> در حال بارگذاری...</div></div>
        </div>
    </div>

    <!-- مودال تاییدیه‌ی شروع آپدیت -->
    <div class="modal-overlay" id="suConfirmModal">
        <div class="modal-sheet">
            <div class="modal-handle"></div>
            <button class="modal-close" onclick="closeModal('suConfirmModal')"><i class="fas fa-times"></i></button>
            <div class="modal-title"><i class="fas fa-triangle-exclamation" style="color:#f87171;"></i> تاییدِ آپدیتِ کاملِ سایت</div>
            <p style="font-size:.8rem;color:rgba(255,255,255,0.7);line-height:1.9;">
                با این کار، فایل‌های فعلیِ سایت با محتوای این zip جایگزین می‌شوند و تا پایانِ عملیات، سایت برای کاربرانِ عادی در دسترس نخواهد بود.
                برای تایید، عبارت <b style="color:#f87171;">UPDATE</b> را دقیقاً تایپ کنید.
            </p>
            <input type="text" id="suConfirmInput" class="form-input" placeholder="UPDATE">
            <button class="btn-admin" style="background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.45);color:#f87171;margin-top:12px;width:100%;" onclick="suConfirmStart()" id="suConfirmBtn">
                <i class="fas fa-rocket"></i> بله، آپدیت کن
            </button>
            <div id="suConfirmMsg" style="margin-top:10px;font-size:.75rem;"></div>
        </div>
    </div>

    <!-- مودال تاییدیه‌ی بازگردانی به بکاپ قبلی -->
    <div class="modal-overlay" id="suRestoreModal">
        <div class="modal-sheet">
            <div class="modal-handle"></div>
            <button class="modal-close" onclick="closeModal('suRestoreModal')"><i class="fas fa-times"></i></button>
            <div class="modal-title"><i class="fas fa-clock-rotate-left" style="color:#f87171;"></i> تاییدِ بازگردانی</div>
            <p style="font-size:.8rem;color:rgba(255,255,255,0.7);line-height:1.9;">
                فایل‌های سایت با محتوای بکاپِ <b id="suRestoreFileShow" style="color:#fbbf24;"></b> جایگزین می‌شوند. برای تایید، عبارت <b style="color:#f87171;">RESTORE</b> را تایپ کنید.
            </p>
            <input type="text" id="suRestoreConfirmInput" class="form-input" placeholder="RESTORE">
            <button class="btn-admin" style="background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.45);color:#f87171;margin-top:12px;width:100%;" onclick="suConfirmRestore()" id="suRestoreConfirmBtn">
                <i class="fas fa-clock-rotate-left"></i> بله، بازگردان
            </button>
            <div id="suRestoreMsg" style="margin-top:10px;font-size:.75rem;"></div>
        </div>
    </div>

    <!-- مودال تاییدیه‌ی پاک‌سازی داده‌ی کاربر (نه confirm() بومی — طبق تجربه‌ی قبلی، در برخی WebViewها کار نمی‌کند) -->
    <div class="modal-overlay" id="dmWipeModal">
        <div class="modal-sheet">
            <div class="modal-handle"></div>
            <button class="modal-close" onclick="closeModal('dmWipeModal')"><i class="fas fa-times"></i></button>
            <div class="modal-title"><i class="fas fa-triangle-exclamation" style="color:#f87171;"></i> تایید پاک‌سازی کامل</div>
            <p style="font-size:.8rem;color:rgba(255,255,255,0.7);line-height:1.9;">
                برای تایید، شناسه‌ی عددی کاربر (<b id="dmWipeUserIdShow" style="color:#f87171;"></b>) را دقیقاً در کادر زیر تایپ کنید.
                این عملیات غیرقابل بازگشت است.
            </p>
            <input type="number" id="dmWipeConfirmInput" class="form-input" placeholder="شناسه‌ی کاربر را اینجا تایپ کنید">
            <button class="btn-admin" style="background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.45);color:#f87171;margin-top:12px;width:100%;" onclick="dmConfirmWipe()" id="dmWipeConfirmBtn">
                <i class="fas fa-trash-can"></i> بله، برای همیشه پاک کن
            </button>
            <div id="dmWipeMsg" style="margin-top:10px;font-size:.75rem;"></div>
        </div>
    </div>

    <!-- مودال تاییدیه‌ی ریستور بکاپ -->
    <div class="modal-overlay" id="dmRestoreModal">
        <div class="modal-sheet">
            <div class="modal-handle"></div>
            <button class="modal-close" onclick="closeModal('dmRestoreModal')"><i class="fas fa-times"></i></button>
            <div class="modal-title"><i class="fas fa-clock-rotate-left" style="color:#f87171;"></i> تایید ریستور بکاپ</div>
            <p style="font-size:.8rem;color:rgba(255,255,255,0.7);line-height:1.9;">
                با ریستور کردن فایل <b id="dmRestoreFileShow" style="color:#fbbf24;"></b>، تمام اطلاعات <b>فعلی</b> دیتابیس با محتوای این بکاپ جایگزین می‌شود.
                (پیش از اجرا، خودکار یک بک‌آپ ایمنی از وضعیت فعلی هم گرفته می‌شود.)
                برای تایید، عبارت <b style="color:#f87171;">RESTORE</b> را دقیقاً تایپ کنید.
            </p>
            <input type="text" id="dmRestoreConfirmInput" class="form-input" placeholder="RESTORE">
            <button class="btn-admin" style="background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.45);color:#f87171;margin-top:12px;width:100%;" onclick="dmConfirmRestore()" id="dmRestoreConfirmBtn">
                <i class="fas fa-clock-rotate-left"></i> بله، ریستور کن
            </button>
            <div id="dmRestoreMsg" style="margin-top:10px;font-size:.75rem;"></div>
        </div>
    </div>

    <!-- مودال تاییدیه‌ی حذف بکاپ (نه confirm() بومی) -->
    <div class="modal-overlay" id="dmDeleteModal">
        <div class="modal-sheet">
            <div class="modal-handle"></div>
            <button class="modal-close" onclick="closeModal('dmDeleteModal')"><i class="fas fa-times"></i></button>
            <div class="modal-title"><i class="fas fa-trash-can" style="color:#f87171;"></i> تایید حذف بکاپ</div>
            <p style="font-size:.8rem;color:rgba(255,255,255,0.7);line-height:1.9;">
                فایل بکاپ <b id="dmDeleteFileShow" style="color:#fbbf24;"></b> برای همیشه حذف می‌شود و دیگر قابل بازیابی نیست.
                این کار روی دیتابیس فعلی هیچ اثری ندارد — فقط همان فایل بکاپ روی دیسک پاک می‌شود.
            </p>
            <button class="btn-admin" style="background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.45);color:#f87171;margin-top:12px;width:100%;" onclick="dmConfirmDelete()" id="dmDeleteConfirmBtn">
                <i class="fas fa-trash-can"></i> بله، حذف کن
            </button>
            <div id="dmDeleteMsg" style="margin-top:10px;font-size:.75rem;"></div>
        </div>
    </div>

    <!-- ============================================================
         TAB 5: SETTINGS (Ads Channel, Toast, Version)
         ============================================================ -->
    <div class="tab-content" id="tab-settings">
        <div class="admin-grid" style="grid-template-columns:1fr;">

            <!-- ارسال آگهی‌ها به کانال تلگرام -->
            <div class="admin-card">
                <div class="admin-card-title"><i class="fab fa-telegram"></i> ارسال آگهی‌ها به کانال تلگرام</div>
                <p style="font-size:.8rem;color:rgba(255,255,255,0.5);margin:0 0 12px;line-height:1.7;">
                    آگهی‌های فعال بخش تبادل ارزی هر یک ساعت یک‌بار (فقط در صورت وجود آگهی فعال) به کانال شما با یک دکمهٔ شیشه‌ای ارسال می‌شوند.
                </p>
                <div style="margin-bottom:12px;">
                    <label style="display:block;font-size:.75rem;color:rgba(255,255,255,0.6);margin-bottom:6px;">شناسه کانال (مثال: <code>@YourChannel</code> یا <code>-1001234567890</code>)</label>
                    <input type="text" id="adsChannelId" placeholder="@YourChannel" dir="ltr"
                        style="width:100%;padding:12px;border-radius:12px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);color:#fff;font-size:.9rem;">
                    <p style="font-size:.68rem;color:rgba(255,255,255,0.4);margin:6px 0 0;">
                        ربات باید ادمین کانال باشد تا بتواند پیام ارسال کند.
                    </p>
                </div>
                <label style="display:flex;align-items:center;gap:8px;font-size:.8rem;color:rgba(255,255,255,0.7);margin-bottom:14px;cursor:pointer;">
                    <input type="checkbox" id="adsBroadcastEnabled" checked style="width:18px;height:18px;">
                    ارسال خودکار ساعتی فعال باشد
                </label>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <button class="btn" onclick="saveChannelSettings()" style="background:linear-gradient(45deg,#0088cc,#22d3a0);flex:1;min-width:140px;">
                        <i class="fas fa-save"></i> ذخیره تنظیمات
                    </button>
                    <button class="btn" onclick="sendAdsNow()" style="background:linear-gradient(45deg,#FF4D8D,#FF6B35);flex:1;min-width:140px;">
                        <i class="fas fa-paper-plane"></i> ارسال فوری آزمایشی
                    </button>
                </div>
                <div id="channelSettingsStatus" style="margin-top:12px;font-size:.75rem;color:rgba(255,255,255,0.5);"></div>
            </div>

            <!-- Toast Notification -->
            <div class="admin-card">
                <div class="admin-card-title"><i class="fas fa-bell"></i> Send Notification</div>
                <div style="margin-bottom:15px;">
                    <label style="display:block;margin-bottom:8px;color:#B8B8D1;font-size:.8rem;">Target</label>
                    <select id="toastTarget" class="form-select">
                        <option value="0">📢 All Users</option>
                        <?php $usersResult->data_seek(0); while($u = $usersResult->fetch_assoc()): ?>
                        <option value="<?php echo $u['id']; ?>">👤 <?php echo htmlspecialchars($u['first_name'].' '.$u['last_name']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div style="margin-bottom:15px;">
                    <label style="display:block;margin-bottom:8px;color:#B8B8D1;font-size:.8rem;">Title</label>
                    <input type="text" id="toastTitle" class="form-input" placeholder="Title..." value="📢 Announcement">
                </div>
                <div style="margin-bottom:15px;">
                    <label style="display:block;margin-bottom:8px;color:#B8B8D1;font-size:.8rem;">Message</label>
                    <textarea id="toastMessage" class="form-input" rows="3" placeholder="Message..."></textarea>
                </div>
                <button class="btn" onclick="sendToast()" style="background:linear-gradient(45deg,#FFD700,#FFA500);color:#1A0B2E;">
                    <i class="fas fa-paper-plane"></i> Send
                </button>
                <div id="toastFeedback" style="margin-top:10px;"></div>
            </div>

            <!-- Version Management -->
            <div class="admin-card">
                <div class="admin-card-title"><i class="fas fa-code-branch"></i> PWA Version</div>
                <div class="current-version">
                    <i class="fas fa-rocket" style="font-size:2rem;"></i>
                    <div class="version-number">v<?php echo htmlspecialchars($currentVersion); ?></div>
                    <div style="font-size:.8rem;opacity:.7;">Last update: <?php echo date('Y/m/d H:i:s'); ?></div>
                    <?php
                        $panelVersionData = file_exists($versionFile) ? json_decode(file_get_contents($versionFile), true) : [];
                        $panelLogoUrl = $panelVersionData['logo_url'] ?? null;
                        $panelLogoVersion = $panelVersionData['logo_version'] ?? 0;
                    ?>
                    <?php if (!empty($panelLogoUrl)): ?>
                    <div style="margin-top:12px;">
                        <img src="/ledor/<?php echo htmlspecialchars($panelLogoUrl); ?>?v=<?php echo (int)$panelLogoVersion; ?>" alt="Current Logo" style="width:50px;height:50px;border-radius:10px;object-fit:contain;background:rgba(255,255,255,0.15);padding:4px;">
                        <div style="font-size:.75rem;opacity:.7;margin-top:4px;">لوگوی فعلی</div>
                    </div>
                    <?php endif; ?>
                </div>
                <form method="POST" action="version.php" target="_blank" enctype="multipart/form-data">
                    <div style="margin-bottom:15px;">
                        <label style="display:block;margin-bottom:5px;color:#B8B8D1;font-size:.8rem;">لوگوی جدید (اختیاری)</label>
                        <input type="file" name="logo_file" class="form-input" accept=".png,.jpg,.jpeg,.webp,.svg">
                    </div>
                    <div style="margin-bottom:15px;">
                        <label style="display:block;margin-bottom:5px;color:#B8B8D1;font-size:.8rem;">New Version</label>
                        <input type="text" name="version" class="form-input" placeholder="2.0.1" required pattern="\d+\.\d+\.\d+">
                    </div>
                    <div style="margin-bottom:15px;">
                        <label style="display:block;margin-bottom:5px;color:#B8B8D1;font-size:.8rem;">Update Message</label>
                        <textarea name="update_message" class="form-input" rows="3" placeholder="Describe changes...">✨ Performance improvements</textarea>
                    </div>
                    <div style="margin-bottom:15px;">
                        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                            <input type="checkbox" name="force_update" value="1"> Force update
                        </label>
                    </div>
                    <button type="submit" class="btn" style="background:linear-gradient(45deg,#FFD700,#FFA500);color:#1A0B2E;">
                        <i class="fas fa-cloud-upload-alt"></i> Release
                    </button>
                </form>
            </div>

        </div>
    </div>

    <!-- ==================== تب جدید: ارسال فیش برای کاربر (مستقل از تنظیمات) ==================== -->
    <div class="tab-content" id="tab-receipts">
        <div class="admin-grid">
            <div class="admin-card">
                <div class="admin-card-title"><i class="fas fa-paperclip"></i> ارسال فیش برای کاربر</div>
                <div style="font-size:.78rem;color:#B8B8D1;line-height:1.9;margin-bottom:16px;">
                    یک کاربر را انتخاب کنید و یک یا چند عکس فیش برایش ارسال کنید. فیش‌های ارسالی مستقیماً در بخش
                    «حساب‌ها و فیش‌ها»ی داشبورد همان کاربر (فیش‌های دریافتی) نمایش داده می‌شود و به او اعلان می‌رود.
                </div>
                <button class="btn" onclick="openSendReceipt()" style="background:linear-gradient(45deg,#22d3a0,#6C40C5);width:100%;justify-content:center;">
                    <i class="fas fa-paperclip"></i> ارسال فیش جدید
                </button>
            </div>

            <div class="admin-card">
                <div class="admin-card-title"><i class="fas fa-clock-rotate-left"></i> آخرین فیش‌های ارسالی</div>
                <div id="sentReceiptsList" style="display:flex;flex-direction:column;gap:8px;"></div>
            </div>
        </div>
    </div>

    <!-- ==================== تب جدید: درآمد ادمین ==================== -->
    <div class="tab-content" id="tab-revenue">
        <!-- کارت‌های درآمد به تفکیک ارزهای اصلی -->
        <div class="rev-cur-grid" id="revCurCards"></div>

        <div class="admin-card" style="margin-bottom:16px;">
            <div class="admin-card-title" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                <span><i class="fas fa-chart-line"></i> روند درآمد ماهانه</span>
                <div class="rev-cur-tabs" id="revCurTabs"></div>
            </div>
            <div id="revChartMissingNote" style="display:none;font-size:.68rem;color:#8ea0c9;margin-bottom:10px;"></div>
            <div style="position:relative;height:280px;">
                <canvas id="revenueChart"></canvas>
            </div>
        </div>

        <div class="admin-card">
            <div class="admin-card-title" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                <span><i class="fas fa-pen-to-square"></i> رکوردهای درآمد دستی</span>
                <button class="btn-sm" onclick="revOpenEntry(null)" style="background:#22d3a0;color:#111;border:none;">
                    <i class="fas fa-plus"></i> افزودن
                </button>
            </div>
            <div style="font-size:.72rem;color:#B8B8D1;line-height:1.9;margin-bottom:12px;">
                درآمدهایی که به‌صورت خودکار محاسبه نمی‌شوند را می‌توانید اینجا دستی ثبت، ویرایش یا حذف کنید.
                این مبالغ در کارت‌های بالا و نمودار لحاظ می‌شوند.
            </div>
            <div id="revEntriesList" style="display:flex;flex-direction:column;gap:8px;"></div>
        </div>
    </div>

    </div><!-- end admin-main -->

</div><!-- end admin-shell -->


<!-- ============================================================
     MODALS
     ============================================================ -->

<!-- Modal: Approve Top-up -->
<div class="modal-overlay" id="admin-approve-modal">
    <div class="modal-sheet">
        <div class="modal-handle"></div>
        <button class="modal-close" onclick="closeModal('admin-approve-modal')"><i class="fas fa-times"></i></button>
        <div class="modal-title"><i class="fas fa-check-circle" style="color:#22d3a0"></i> تأیید درخواست Top-up</div>
        <input type="hidden" id="approve-req-id">
        <div class="admin-modal-inner">
            <div id="approveTopupSavedAccBox"></div>
            <div class="admin-form-group">
                <label class="admin-form-label">نام بانک <span style="color:#ff4d6d">*</span></label>
                <input type="text" id="approve-bank" class="admin-form-input" placeholder="مثال: بانک ملت">
            </div>
            <div class="admin-form-group">
                <label class="admin-form-label">شماره حساب <span style="color:#ff4d6d">*</span></label>
                <input type="text" id="approve-account" class="admin-form-input" placeholder="شماره حساب">
            </div>
            <div class="admin-form-group">
                <label class="admin-form-label">شماره کارت <span style="color:#ff4d6d">*</span></label>
                <input type="text" id="approve-card" class="admin-form-input" placeholder="xxxx-xxxx-xxxx-xxxx" maxlength="19">
            </div>
            <div class="admin-form-group">
                <label class="admin-form-label">نام صاحب حساب <span style="color:#ff4d6d">*</span></label>
                <input type="text" id="approve-recipient" class="admin-form-input" placeholder="نام و نام خانوادگی">
            </div>
            <div class="admin-form-group">
                <label class="admin-form-label">شبا (IBAN)</label>
                <input type="text" id="approve-iban" class="admin-form-input" placeholder="IR...">
            </div>
            <div class="admin-form-group" style="border-top:1px dashed rgba(255,255,255,0.15);padding-top:14px;margin-top:6px;">
                <label class="admin-form-label">مبلغ قابل پرداخت <span style="color:#ff4d6d">*</span></label>
                <div style="display:flex;gap:8px;">
                    <input type="number" id="approve-payment-amount" class="admin-form-input" placeholder="مثلاً 100000000" style="flex:2;">
                    <select id="approve-payment-currency" class="admin-form-input" style="flex:1;">
                        <option value="IRR">تومان</option>
                        <option value="EUR">EUR</option>
                        <option value="USD">USD</option>
                        <option value="USDT">USDT</option>
                    </select>
                </div>
                <div style="font-size:.66rem;color:#9b94b8;margin-top:4px;">مبلغی که کاربر باید دقیقاً پرداخت کند (مستقل از ارز/مقداری که برای شارژ کیف‌پول درخواست داده)</div>
            </div>
            <button class="btn-primary" onclick="adminApproveTopup()">
                <i class="fas fa-paper-plane"></i> ارسال اطلاعات به کاربر
            </button>
        </div>
    </div>
</div>

<!-- Modal: Reject Top-up -->
<div class="modal-overlay" id="admin-reject-modal">
    <div class="modal-sheet">
        <div class="modal-handle"></div>
        <button class="modal-close" onclick="closeModal('admin-reject-modal')"><i class="fas fa-times"></i></button>
        <div class="modal-title"><i class="fas fa-ban" style="color:#ff4d6d"></i> رد درخواست</div>
        <input type="hidden" id="reject-req-id">
        <div class="admin-modal-inner">
            <div class="admin-form-group">
                <label class="admin-form-label">دلیل رد <span style="color:#ff4d6d">*</span></label>
                <textarea id="reject-reason" class="admin-form-input" rows="3" placeholder="دلیل رد را بنویسید..."></textarea>
            </div>
            <button class="btn-danger" onclick="adminRejectTopup()">
                <i class="fas fa-times-circle"></i> تأیید رد
            </button>
        </div>
    </div>
</div>

<!-- Modal: View Receipt + Complete Top-up -->
<div class="modal-overlay" id="admin-receipt-modal">
    <div class="modal-sheet">
        <div class="modal-handle"></div>
        <button class="modal-close" onclick="closeModal('admin-receipt-modal')"><i class="fas fa-times"></i></button>
        <div class="modal-title"><i class="fas fa-receipt" style="color:#a78bfa"></i> فیش واریز کاربر</div>
        <input type="hidden" id="complete-req-id">
        <div class="admin-modal-inner">
            <div id="admin-receipt-imgs" style="display:flex;flex-direction:column;gap:10px;margin-bottom:10px;"></div>
            <div style="font-size:.72rem;color:#9b94b8;margin-bottom:12px;">
                پس از بررسی فیش واریز و اطمینان از پرداخت، روی دکمه «تکمیل Top-up» کلیک کنید تا بالانس کاربر افزایش یابد.
            </div>
            <div id="complete-req-info" style="background:rgba(0,0,0,0.3);border-radius:10px;padding:12px;margin-bottom:12px;font-size:.75rem;color:#9b94b8;"></div>
            <button class="btn-primary" onclick="adminCompleteTopup()">
                <i class="fas fa-check-double"></i> تکمیل Top-up و افزایش موجودی
            </button>
        </div>
    </div>
</div>

<!-- ============================================================
     TAB: RATEPOSTER (عکس نرخ لحظه‌ای)
     ============================================================ -->
<div class="tab-content" id="tab-rateposter">
    <div class="admin-card" style="margin-bottom:16px;">
        <div class="admin-card-title" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
            <span><i class="fas fa-image"></i> عکس نرخ لحظه‌ای</span>
            <button class="btn-sm" id="rpGenBtn" onclick="rpGenerate()"
                    style="background:linear-gradient(135deg,#a855f7,#7c3aed);color:#fff;border:none;">
                <i class="fas fa-bolt"></i> تولید همین الان
            </button>
        </div>
        <p style="font-size:.72rem;color:#8ea0c9;line-height:2;margin:0;">
            هر روز ساعت <b style="color:#c9b6ff">۰۶:۰۰</b> و <b style="color:#c9b6ff">۱۵:۰۰</b> نرخ همان لحظه
            به‌صورت خودکار فریز و اینجا ذخیره می‌شود. عددهای روی هر عکس دقیقاً نرخ همان ساعت هستند،
            حتی اگر بعداً دانلودش کنید. با دکمه‌ی «تولید همین الان» هم می‌توانید هر زمان یک عکس با نرخ لحظه بسازید.
        </p>
        <div id="rpMsg" style="margin-top:10px;font-size:.72rem;min-height:16px;"></div>
    </div>

    <div class="admin-card">
        <div class="admin-card-title"><i class="fas fa-clock-rotate-left"></i> عکس‌های ساخته‌شده</div>
        <div id="rpList" style="font-size:.75rem;color:#8ea0c9;">در حال بارگذاری…</div>
    </div>
</div>

<!-- مودال پیش‌نمایش و دانلود عکس -->
<div class="modal-overlay" id="rate-poster-modal">
    <div class="modal-sheet" style="max-width:760px;">
        <div class="modal-handle"></div>
        <button class="modal-close" onclick="closeModal('rate-poster-modal')"><i class="fas fa-times"></i></button>
        <div class="modal-title"><i class="fas fa-image" style="color:#a855f7"></i> پیش‌نمایش عکس نرخ</div>

        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px;">
            <button class="btn-sm" id="rpTgBtn" onclick="rpSendTelegram()"
                    style="background:linear-gradient(135deg,#229ED9,#1c7fb0);color:#fff;border:none;">
                <i class="fab fa-telegram"></i> ارسال به ربات تلگرام
            </button>
            <button class="btn-sm" id="rpDlBtn" onclick="rpDownload()"
                    style="background:rgba(255,255,255,.08);color:#fff;border:none;">
                <i class="fas fa-download"></i> دانلود
            </button>
            <a class="btn-sm" id="rpOpenBtn" href="#" target="_blank" rel="noopener"
               style="background:rgba(255,255,255,.08);color:#fff;border:none;text-decoration:none;display:inline-flex;align-items:center;gap:6px;">
                <i class="fas fa-up-right-from-square"></i> باز کردن
            </a>
        </div>
        <div id="rpDlMsg" style="font-size:.7rem;color:#8ea0c9;min-height:16px;margin-bottom:10px;"></div>

        <!-- iframe عمداً با ابعاد واقعی ۱۰۲۴×۱۵۳۶ ساخته می‌شود و فقط با
             transform کوچک نمایش داده می‌شود. اگر عرض iframe را کم کنیم،
             html2canvas قالب را در همان عرض باریک بازسازی می‌کند و چیدمان
             به‌هم می‌ریزد (همان خروجی خرابِ قبلی). -->
        <div id="rpStage" style="background:#07030f;border-radius:14px;overflow:hidden;position:relative;">
            <iframe id="rpFrame" src="about:blank" scrolling="no"
                    style="width:1024px;height:1536px;border:none;display:block;
                           transform-origin:top right;"></iframe>
        </div>
    </div>
</div>

<!-- ============================================================
     INVOICE MODALS
     ============================================================ -->

<!-- Modal: Create Invoice -->
<!-- مودال تأیید حذف کامل کاربر — جایگزین confirm()/prompt() چون در بعضی
     مرورگرهای درون‌برنامه‌ای (مثل WebView تلگرام) دیالوگ‌های بومی مرورگر
     اصلاً اجرا نمی‌شوند و باعث می‌شدند دکمه‌ی حذف کاملاً بی‌اثر به‌نظر برسد. -->
<div class="modal-overlay" id="deleteUserModal">
    <div class="modal-sheet">
        <div class="modal-handle"></div>
        <button class="modal-close" onclick="closeModal('deleteUserModal')"><i class="fas fa-times"></i></button>
        <div class="modal-title"><i class="fas fa-triangle-exclamation" style="color:#dc2626"></i> حذف کامل حساب کاربر</div>
        <p style="font-size:.78rem;color:rgba(255,255,255,.6);line-height:1.9;margin:0 0 14px;">
            حساب «<b id="delUserNameLabel" style="color:#fff;"></b>» (شناسه: <b id="delUserIdLabel" style="color:#fff;"></b>)
            برای همیشه حذف می‌شود و این عمل غیرقابل بازگشت است.<br>
            برای تأیید، شناسه‌ی عددی بالا را دقیقاً در کادر زیر وارد کنید:
        </p>
        <input type="text" id="delUserConfirmInput" class="admin-form-input" placeholder="شناسه‌ی عددی کاربر" autocomplete="off" inputmode="numeric">
        <div id="delUserMsg" style="margin-top:10px;font-size:.72rem;min-height:16px;"></div>
        <button type="button" class="btn" style="background:linear-gradient(135deg,#dc2626,#991b1b);margin-top:14px;" onclick="kycConfirmDeleteUser()">
            <i class="fas fa-trash-alt"></i> حذف برای همیشه
        </button>
    </div>
</div>

<div class="modal-overlay" id="create-invoice-modal">
    <div class="modal-sheet">
        <div class="modal-handle"></div>
        <button class="modal-close" onclick="closeModal('create-invoice-modal')"><i class="fas fa-times"></i></button>
        <div class="modal-title"><i class="fas fa-plus-circle" style="color:#22d3a0"></i> ایجاد فیش جدید</div>
        <form id="createInvoiceForm">
            <div class="admin-form-group">
                <label class="admin-form-label">کاربر <span style="color:#ff4d6d">*</span></label>
                <select id="inv_user_id" class="admin-form-input" required>
                    <option value="">-- انتخاب کاربر --</option>
                    <?php $usersResult->data_seek(0); while($u = $usersResult->fetch_assoc()): ?>
                    <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['first_name'].' '.$u['last_name'].' (@'.$u['telegram_id'].')'); ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div class="admin-form-group">
                    <label class="admin-form-label">ارز <span style="color:#ff4d6d">*</span></label>
                    <select id="inv_currency" class="admin-form-input" required>
                        <option value="USD">USD</option>
                        <option value="EUR">EUR</option>
                        <option value="USDT">USDT</option>
                        <option value="IRR">IRR</option>
                    </select>
                </div>
                <div class="admin-form-group">
                    <label class="admin-form-label">مبلغ <span style="color:#ff4d6d">*</span></label>
                    <input type="number" id="inv_amount" class="admin-form-input" step="0.01" min="0.01" required placeholder="0.00">
                </div>
            </div>
            <div class="admin-form-group">
                <label class="admin-form-label">نوع فیش <span style="color:#ff4d6d">*</span></label>
                <select id="inv_type" class="admin-form-input" required>
                    <option value="service">🛠️ خدمات (Service)</option>
                    <option value="subscription">⭐ اشتراک (Subscription)</option>
                    <option value="topup">💰 شارژ حساب (Top-up)</option>
                    <option value="penalty">⚠️ جریمه (Penalty)</option>
                    <option value="other">📄 سایر (Other)</option>
                </select>
            </div>
            <div class="admin-form-group">
                <label class="admin-form-label">این حساب برای چیه؟ <span style="color:#ff4d6d">*</span></label>
                <select id="inv_source_type" class="admin-form-input" required>
                    <option value="general">🌐 عمومی</option>
                    <option value="exchange">💱 تبادل ارزی (تسویه‌ی معامله‌ی بین دو کاربر)</option>
                </select>
            </div>
            <div class="admin-form-group">
                <label class="admin-form-label">توضیحات</label>
                <input type="text" id="inv_description" class="admin-form-input" placeholder="توضیحات فیش...">
            </div>

            <!-- اطلاعات پرداخت: اگر همین‌جا پر شود، فیش مستقیماً «آماده پرداخت»
                 می‌شود و دیگر نیازی به مرحله‌ی تأیید مجدد نیست. -->
            <div style="border-top:1px solid rgba(255,255,255,.12);margin:14px 0 10px;padding-top:12px;">
                <div style="font-size:.8rem;font-weight:700;color:#22d3a0;margin-bottom:4px;">
                    <i class="fas fa-credit-card"></i> اطلاعات پرداخت (اختیاری)
                </div>
                <div style="font-size:.72rem;color:#B8B8D1;margin-bottom:10px;">
                    اگر پر کنید، فیش بدون نیاز به تأیید مجدد مستقیماً «آماده پرداخت» به کاربر نمایش داده می‌شود.
                </div>
            </div>
            <div id="createInvSavedAccBox"></div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div class="admin-form-group">
                    <label class="admin-form-label">نام بانک</label>
                    <input type="text" id="inv_bank_name" class="admin-form-input" placeholder="مثلاً ملت">
                </div>
                <div class="admin-form-group">
                    <label class="admin-form-label">نام صاحب حساب</label>
                    <input type="text" id="inv_recipient_name" class="admin-form-input" placeholder="نام و نام خانوادگی">
                </div>
            </div>
            <div class="admin-form-group">
                <label class="admin-form-label">شماره کارت</label>
                <input type="text" id="inv_card_number" class="admin-form-input" placeholder="6037-xxxx-xxxx-xxxx">
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div class="admin-form-group">
                    <label class="admin-form-label">شماره حساب</label>
                    <input type="text" id="inv_account_number" class="admin-form-input" placeholder="اختیاری">
                </div>
                <div class="admin-form-group">
                    <label class="admin-form-label">شبا</label>
                    <input type="text" id="inv_iban" class="admin-form-input" placeholder="IR...">
                </div>
            </div>
            <button type="submit" class="btn-success"><i class="fas fa-paper-plane"></i> ایجاد فیش</button>
        </form>
    </div>
</div>

<!-- Modal: Add/Edit Manual Revenue Entry -->
<div class="modal-overlay" id="revenue-entry-modal">
    <div class="modal-sheet">
        <div class="modal-handle"></div>
        <button class="modal-close" onclick="closeModal('revenue-entry-modal')"><i class="fas fa-times"></i></button>
        <div class="modal-title"><i class="fas fa-sack-dollar" style="color:#22d3a0"></i> <span id="revEntryTitle">افزودن درآمد دستی</span></div>
        <form id="revenueEntryForm">
            <input type="hidden" id="rev_entry_id" value="">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div class="admin-form-group">
                    <label class="admin-form-label">ارز <span style="color:#ff4d6d">*</span></label>
                    <select id="rev_currency" class="admin-form-input" required>
                        <option value="IRR">تومان (IRR)</option>
                        <option value="USD">دلار (USD)</option>
                        <option value="EUR">یورو (EUR)</option>
                        <option value="USDT">تتر (USDT)</option>
                    </select>
                </div>
                <div class="admin-form-group">
                    <label class="admin-form-label">مبلغ <span style="color:#ff4d6d">*</span></label>
                    <input type="number" step="any" id="rev_amount" class="admin-form-input" placeholder="مثلاً 1500000" required>
                </div>
            </div>
            <div class="admin-form-group">
                <label class="admin-form-label">تاریخ</label>
                <input type="date" id="rev_date" class="admin-form-input">
            </div>
            <div class="admin-form-group">
                <label class="admin-form-label">توضیح (اختیاری)</label>
                <input type="text" id="rev_note" class="admin-form-input" placeholder="بابت چه چیزی؟">
            </div>
            <button type="submit" class="btn-success"><i class="fas fa-save"></i> ذخیره</button>
        </form>
    </div>
</div>

<!-- Modal: Send Receipt Directly to a User -->
<div class="modal-overlay" id="send-receipt-modal">
    <div class="modal-sheet">
        <div class="modal-handle"></div>
        <button class="modal-close" onclick="closeModal('send-receipt-modal')"><i class="fas fa-times"></i></button>
        <div class="modal-title"><i class="fas fa-paperclip" style="color:#22d3a0"></i> ارسال فیش برای کاربر</div>
        <div style="font-size:.74rem;color:#B8B8D1;margin-bottom:14px;">
            فیش(های) ارسالی مستقیماً در بخش «حساب‌ها و فیش‌ها»ی کاربر (فیش‌های دریافتی) نمایش داده می‌شود.
        </div>
        <form id="sendReceiptForm">
            <div class="admin-form-group">
                <label class="admin-form-label">کاربر <span style="color:#ff4d6d">*</span></label>
                <select id="rcpt_user_id" class="admin-form-input" required>
                    <option value="">-- انتخاب کاربر --</option>
                    <?php $usersResult->data_seek(0); while($u = $usersResult->fetch_assoc()): ?>
                    <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['first_name'].' '.$u['last_name'].' (@'.$u['telegram_id'].')'); ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="admin-form-group">
                <label class="admin-form-label">عکس فیش — یک یا چند عکس <span style="color:#ff4d6d">*</span></label>
                <input type="file" id="rcpt_files" accept="image/*" multiple class="admin-form-input" required>
                <div id="rcpt_files_picked" style="font-size:.7rem;color:#8ea0c9;margin-top:6px;"></div>
                <div id="rcpt_files_preview" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;"></div>
            </div>
            <div class="admin-form-group">
                <label class="admin-form-label">توضیح (اختیاری)</label>
                <input type="text" id="rcpt_note" class="admin-form-input" placeholder="مثلاً: فیش تسویه‌ی نهایی...">
            </div>
            <button type="submit" class="btn-success"><i class="fas fa-paper-plane"></i> ارسال فیش</button>
        </form>
    </div>
</div>

<!-- Modal: Approve Invoice (Send Bank Info) -->
<div class="modal-overlay" id="approve-invoice-modal">
    <div class="modal-sheet">
        <div class="modal-handle"></div>
        <button class="modal-close" onclick="closeModal('approve-invoice-modal')"><i class="fas fa-times"></i></button>
        <div class="modal-title"><i class="fas fa-check-circle" style="color:#22d3a0"></i> تأیید فیش</div>
        <input type="hidden" id="approve-inv-id">
        <div class="admin-modal-inner">
            <div id="approveInvSavedAccBox"></div>
            <div class="admin-form-group">
                <label class="admin-form-label">نام بانک <span style="color:#ff4d6d">*</span></label>
                <input type="text" id="inv-approve-bank" class="admin-form-input" placeholder="نام بانک">
            </div>
            <div class="admin-form-group">
                <label class="admin-form-label">شماره حساب <span style="color:#ff4d6d">*</span></label>
                <input type="text" id="inv-approve-account" class="admin-form-input" placeholder="شماره حساب">
            </div>
            <div class="admin-form-group">
                <label class="admin-form-label">شماره کارت <span style="color:#ff4d6d">*</span></label>
                <input type="text" id="inv-approve-card" class="admin-form-input" placeholder="xxxx-xxxx-xxxx-xxxx">
            </div>
            <div class="admin-form-group">
                <label class="admin-form-label">نام صاحب حساب <span style="color:#ff4d6d">*</span></label>
                <input type="text" id="inv-approve-recipient" class="admin-form-input" placeholder="نام و نام خانوادگی">
            </div>
            <div class="admin-form-group">
                <label class="admin-form-label">شبا (IBAN)</label>
                <input type="text" id="inv-approve-iban" class="admin-form-input" placeholder="IR...">
            </div>
            <button class="btn-success" onclick="adminApproveInvoice()">
                <i class="fas fa-paper-plane"></i> ارسال اطلاعات به کاربر
            </button>
        </div>
    </div>
</div>

<!-- Modal: Finalize Invoice (Send Final Note + Image) -->
<div class="modal-overlay" id="finalize-invoice-modal">
    <div class="modal-sheet">
        <div class="modal-handle"></div>
        <button class="modal-close" onclick="closeModal('finalize-invoice-modal')"><i class="fas fa-times"></i></button>
        <div class="modal-title"><i class="fas fa-star" style="color:#f0b429"></i> تکمیل فیش</div>
        <input type="hidden" id="finalize-inv-id">
        <div class="admin-modal-inner">
            <div class="admin-form-group">
                <label class="admin-form-label">یادداشت نهایی (اختیاری)</label>
                <textarea id="finalize-note" class="admin-form-input" rows="3" placeholder="پیام نهایی برای کاربر..."></textarea>
            </div>
            <div class="admin-form-group">
                <label class="admin-form-label">تصویر نهایی (اختیاری)</label>
                <div class="upload-area" onclick="document.getElementById('final-inv-image').click()">
                    <i class="fas fa-image"></i>
                    <span id="final-inv-label">آپلود تصویر نهایی</span>
                    <input type="file" id="final-inv-image" accept="image/*" onchange="previewFinalInvoiceImage(this)">
                </div>
                <img src="" alt="" class="final-image-preview" id="final-inv-preview" style="display:none;">
                <input type="hidden" id="final-inv-image-url">
            </div>
            <button class="btn-warning" onclick="adminFinalizeInvoice()">
                <i class="fas fa-check-circle"></i> تکمیل فیش و افزایش موجودی
            </button>
        </div>
    </div>
</div>

<!-- Modal: View Invoice Detail -->
<div class="modal-overlay" id="invoice-detail-modal">
    <div class="modal-sheet">
        <div class="modal-handle"></div>
        <button class="modal-close" onclick="closeModal('invoice-detail-modal')"><i class="fas fa-times"></i></button>
        <div class="modal-title"><i class="fas fa-file-invoice" style="color:#a78bfa"></i> جزئیات فیش</div>
        <div id="invoice-detail-body"></div>
    </div>
</div>

<!-- نمایشگر تمام‌صفحه‌ی فیش (Lightbox) — با دو کلیک: کلیک روی ردیف، بعد کلیک روی عکس فیش -->
<div class="ax-img-lightbox" id="axImgLightbox" onclick="if(event.target===this) axCloseImgLightbox()">
    <button class="ax-img-lightbox-close" onclick="axCloseImgLightbox()"><i class="fas fa-times"></i></button>
    <div class="ax-img-lightbox-toolbar">
        <a id="axImgLightboxDownload" href="#" download class="ax-img-lightbox-btn" title="دانلود"><i class="fas fa-download"></i></a>
    </div>
    <img id="axImgLightboxImg" src="" alt="فیش">
</div>
<style>
.ax-img-lightbox{position:fixed;inset:0;background:rgba(8,4,18,0.95);z-index:100000;display:none;align-items:center;justify-content:center;padding:24px;}
.ax-img-lightbox.show{display:flex;animation:axLbFade .15s ease;}
@keyframes axLbFade{from{opacity:0;}to{opacity:1;}}
.ax-img-lightbox img{max-width:100%;max-height:100%;border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,.6);object-fit:contain;}
.ax-img-lightbox-close{position:fixed;top:18px;left:18px;width:44px;height:44px;border-radius:50%;background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.15);color:#fff;font-size:1.1rem;display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:2;}
.ax-img-lightbox-close:active{background:rgba(255,255,255,0.2);}
.ax-img-lightbox-toolbar{position:fixed;top:18px;right:18px;display:flex;gap:8px;z-index:2;}
.ax-img-lightbox-btn{width:44px;height:44px;border-radius:50%;background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.15);color:#fff;font-size:1rem;display:flex;align-items:center;justify-content:center;text-decoration:none;}
</style>
<script>
function axOpenImgLightbox(url){
    if (!url) return;
    const lb = document.getElementById('axImgLightbox');
    const img = document.getElementById('axImgLightboxImg');
    const dl = document.getElementById('axImgLightboxDownload');
    if (!lb || !img) return;
    img.src = url;
    if (dl) dl.href = url;
    lb.classList.add('show');
}
function axCloseImgLightbox(){
    const lb = document.getElementById('axImgLightbox');
    if (lb) lb.classList.remove('show');
    const img = document.getElementById('axImgLightboxImg');
    if (img) img.src = '';
}
document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') axCloseImgLightbox();
});
</script>

<!-- Modal: Top-up Request Detail -->
<div class="modal-overlay" id="topup-detail-modal">
    <div class="modal-sheet">
        <div class="modal-handle"></div>
        <button class="modal-close" onclick="closeModal('topup-detail-modal')"><i class="fas fa-times"></i></button>
        <div class="modal-title"><i class="fas fa-wallet" style="color:#22d3a0"></i> جزئیات درخواست Top-up</div>
        <div id="topup-detail-body"></div>
    </div>
</div>

<!-- Modal: Reject Invoice -->
<div class="modal-overlay" id="reject-invoice-modal">
    <div class="modal-sheet">
        <div class="modal-handle"></div>
        <button class="modal-close" onclick="closeModal('reject-invoice-modal')"><i class="fas fa-times"></i></button>
        <div class="modal-title"><i class="fas fa-ban" style="color:#ff4d6d"></i> رد فیش</div>
        <input type="hidden" id="reject-inv-id">
        <div class="admin-modal-inner">
            <div class="admin-form-group">
                <label class="admin-form-label">دلیل رد <span style="color:#ff4d6d">*</span></label>
                <textarea id="reject-inv-reason" class="admin-form-input" rows="3" placeholder="دلیل رد را بنویسید..."></textarea>
            </div>
            <button class="btn-danger" onclick="adminRejectInvoice()">
                <i class="fas fa-times-circle"></i> تأیید رد فیش
            </button>
        </div>
    </div>
</div>


<!-- ==================== مودال «دسترسی سریع» ==================== -->
<div class="ax-quick-modal-overlay" id="axQuickModalOverlay" onclick="if (event.target === this) axCloseQuickModal();">
    <div class="ax-quick-modal">
        <div class="ax-quick-modal-head">
            <span id="axQuickModalTitle">عنوان</span>
            <button type="button" class="ax-quick-modal-close" onclick="axCloseQuickModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="ax-modal-pills" id="axQuickModalPills"></div>
        <div class="ax-quick-modal-body" id="axQuickModalBody"></div>
    </div>
</div>

<script>
// ====================================================
// GLOBAL VARIABLES
// ====================================================
let currentBalances = { USD:0, EUR:0, USDT:0, IRR:0 };
let currentUserId = null;
let chatPolling = null;
let lastChatId = 0;
let adminCurrentFilter = '';
let adminInvoiceFilter = '';

// ====================================================
// UTILITY FUNCTIONS
// ====================================================
function showAdminToast(msg, type = 'success') {
    const colors = { success:'#4CD964', error:'#FF3B30', info:'#5AC8FA' };
    const toast = document.createElement('div');
    toast.className = 'toast' + (type === 'error' ? ' error' : type === 'info' ? ' info' : '');
    toast.textContent = msg;
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(20px)';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

/* ====================================================
   شبکه‌ی ایمنی سراسری: تضمین اینکه هیچ عمل ادمین هرگز
   کاملاً بی‌صدا شکست نمی‌خورد.
   قبلاً اگر یک خطای پیش‌بینی‌نشده‌ی جاوااسکریپت (مثلاً یک
   null-reference) وسط یک تابع رخ می‌داد، آن تابع بدون هیچ
   پیامی متوقف می‌شد و ادمین فقط می‌دید «هیچ اتفاقی نیفتاد»،
   چون خطا هرگز به showAdminToast نمی‌رسید. این دو هندلر
   هر خطای مدیریت‌نشده (چه synchronous چه در یک Promise رد
   شده) را می‌گیرند و همیشه یک پیام قابل‌مشاهده نشان می‌دهند.
   ==================================================== */
let _axLastGlobalErrToast = 0;
function _axGlobalErrToast(msg) {
    // اگر چند خطا پشت‌سرهم بیاید، فقط یکی را نشان بده (هر ۲ ثانیه حداکثر یکی)
    const now = Date.now();
    if (now - _axLastGlobalErrToast < 2000) return;
    _axLastGlobalErrToast = now;
    if (typeof showAdminToast === 'function') {
        showAdminToast('خطای غیرمنتظره: ' + msg, 'error');
    }
}
window.addEventListener('error', function(ev){
    _axGlobalErrToast(ev && ev.message ? ev.message : 'خطای نامشخص');
});
window.addEventListener('unhandledrejection', function(ev){
    const r = ev && ev.reason;
    const msg = r && r.message ? r.message : (typeof r === 'string' ? r : 'خطای نامشخص');
    _axGlobalErrToast(msg);
});

function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatDate(dt) {
    if (!dt) return '';
    try { return new Date(dt).toLocaleString('fa-IR', { year:'numeric', month:'short', day:'numeric', hour:'2-digit', minute:'2-digit' }); }
    catch(e) { return dt; }
}

function statusLabel(s) {
    const map = {
        pending: '⏳ در انتظار',
        approved: '✅ تأیید شده',
        waiting_payment: '📤 منتظر فیش',
        payment_received: '🧾 فیش دریافت',
        completed: '🏆 تکمیل',
        finalized: '🏆 تکمیل',
        paid: '📤 پرداخت شده',
        rejected: '❌ رد شده'
    };
    return map[s] || s;
}

function statusClass(s) {
    return 'status-pill pill-' + s.replace('_', '');
}

// ====================================================
// SIDEBAR (گروه‌های تاشو — منوی همبرگری/دراور موبایل حذف شد)
// ====================================================
function adminToggleGroup(headEl) {
    const group = headEl.closest('.admin-sidebar-group');
    if (group) group.classList.toggle('collapsed');
}

function adminExpandGroupOf(tabBtn) {
    const group = tabBtn.closest('.admin-sidebar-group');
    if (group) group.classList.remove('collapsed');
}

// ====================================================
// TABS (سایدبار حذف شد؛ ناوبری اکنون فقط از طریق axGoToTab/مودال «دسترسی سریع» است)
// ====================================================

// تابع مشترک بارگذاری دیتای هر تب — هم از کلیک روی سایدبار و هم از
// باز شدن مودال «دسترسی سریع» صدا زده می‌شود تا منطق تکراری نشود.
function axRunTabLoader(tabName) {
    if (tabName === 'overview') { /* استاتیک - از PHP رندر شده */ }
    else if (tabName === 'online') { /* استاتیک - از PHP رندر شده */ }
    else if (tabName === 'topups') adminLoadTopups(adminCurrentFilter);
    else if (tabName === 'invoices') { adminLoadInvoices(adminInvoiceFilter); axLoadReceiptGallery(); }
    else if (tabName === 'manage') { /* already loaded */ }
    else if (tabName === 'impersonate') { /* PHP رندر شده - نیازی به بارگذاری اضافه نیست */ }
    else if (tabName === 'stories') { axStoriesLoadList(); }
    else if (tabName === 'blockusers') { loadBannedUsersList(); }
    else if (tabName === 'chat') { /* already loaded */ }
    else if (tabName === 'settings') { loadChannelSettings(); }
    else if (tabName === 'news') { adminLoadNews('', document.querySelector('.news-admin-langtab')); }
    else if (tabName === 'kyc') { adminLoadKyc('pending', document.querySelector('.kyc-admin-tab')); kycLoadUserOptions(); }
    else if (tabName === 'transfers') { axLoadTransfers('all'); }
    else if (tabName === 'settlements') { axLoadSettlements('pending'); axLoadCompanyCardsAdmin(); axLoadCardStats(); }
    else if (tabName === 'exchange') { axLoadDeals('pending'); axLoadExchangeInvoices(''); axLoadDiscountList(); axLoadDiscountUserOptions(); axLoadDefaultRules(); axLoadFixedCommissionList(); }
    else if (tabName === 'tiers') { tierLoadList(); }
    else if (tabName === 'referrals') { arfAdminLoadSettings(); arfAdminLoadList(); }
    else if (tabName === 'userwipe') { /* فقط با جستجوی ادمین بارگذاری می‌شود */ }
    else if (tabName === 'backup') { dmLoadBackups(); }
    else if (tabName === 'receipts') { axLoadSentReceipts(); }
    else if (tabName === 'revenue') { axLoadRevenue(); }
    else if (tabName === 'directbuy') { axLoadDirectBuy('pending'); }
    else if (tabName === 'siteupdate') { suRefreshStatus(); suLoadBackups(); }
}

// نگاشت شناسه‌ی تب → عنوان فارسی برای مودال (وقتی از راه‌های غیرمستقیم مثل
// دکمه‌ی «مشاهده»ی آخرین درخواست‌ها یا هش URL باز می‌شود)
const AX_TAB_LABELS = {
    overview:'داشبورد', online:'کاربران آنلاین', topups:'واریزی‌ها', invoices:'فیش‌ها',
    manage:'موجودی', settlements:'تسویه حساب', blockusers:'مسدودسازی', kyc:'KYC / ویرایش کاربر',
    referrals:'زیرمجموعه‌ها', tiers:'سطح‌بندی کاربران', exchange:'تبادل ارزی',
    transfers:'حواله ارزی', chat:'گفتگو با کاربران', news:'اخبار', settings:'تنظیمات',
    userwipe:'پاک‌سازی داده کاربر', backup:'بکاپ', stories:'استوری تبلیغاتی', impersonate:'ورود به داشبورد کاربر',
    receipts:'ارسال فیش', revenue:'درآمد ادمین', directbuy:'خرید مستقیم', siteupdate:'آپدیت سایت'
};
// جایگزین سراسری «کلیک روی تب سایدبار» که با حذف کامل سایدبار دیگر وجود ندارد:
// برای «داشبورد» (که همیشه به‌عنوان صفحه‌ی اصلی نمایش داده می‌شود) فقط اسکرول به بالا،
// برای بقیه، همان تب داخل مودال «دسترسی سریع» باز می‌شود.
function axGoToTab(tabName) {
    if (tabName === 'overview') { window.scrollTo({ top: 0, behavior: 'smooth' }); return; }
    const label = AX_TAB_LABELS[tabName] || tabName;
    axOpenQuickModal(label, [tabName], [label]);
}

// ====================================================
// مودال «دسترسی سریع»: به‌جای اسکرول به پایین صفحه/سوییچ کامل تب،
// همان المان tab-content مقصد را موقتاً داخل یک مودال جابه‌جا می‌کند
// و در بسته‌شدن، دقیقاً به همان جای قبلی‌اش برمی‌گرداند — بدون کپی یا
// بازسازی مجدد HTML/JS هر بخش.
// ====================================================
let axQuickModalMoved = []; // [{el, parent, next}]

// ایمنی: هر مودال «یتیم» که به هر دلیلی باز مانده باشد را می‌بندد.
// چرا لازم است: .modal-overlay در این پنل position:fixed با inset:0 و
// z-index:99999 است. اگر یکی از آن‌ها با کلاس open جا بماند (حتی اگر
// به‌نظر نامرئی بیاید) کل صفحه را می‌پوشاند و همه‌ی کلیک‌ها را می‌بلعد —
// که دقیقاً شبیه «هیچ‌کدام از مودال‌ها درست کار نمی‌کنند» به نظر می‌رسد.
function axCloseOrphanOverlays() {
    document.querySelectorAll('.modal-overlay.open').forEach(function (el) {
        el.classList.remove('open');
        if (el.style) el.style.display = '';
    });
}

function axOpenQuickModal(title, tabIds, labels, scrollToId) {
    if (axQuickModalMoved.length) axCloseQuickModal(); // ایمنی: اگر مودال قبلی درست بسته نشده بود
    axCloseOrphanOverlays();
    const overlay = document.getElementById('axQuickModalOverlay');
    const body = document.getElementById('axQuickModalBody');
    const pillsWrap = document.getElementById('axQuickModalPills');
    document.getElementById('axQuickModalTitle').textContent = title;
    body.innerHTML = '';
    pillsWrap.innerHTML = '';
    axQuickModalMoved = [];

    tabIds.forEach(function (tabId, idx) {
        const el = document.getElementById('tab-' + tabId);
        if (!el) return;
        axQuickModalMoved.push({ el: el, parent: el.parentNode, next: el.nextSibling });
        el.classList.add('active');
        el.style.display = idx === 0 ? 'block' : 'none';
        el.dataset.axModalTab = tabId;
        body.appendChild(el);
    });

    if (tabIds.length > 1) {
        tabIds.forEach(function (tabId, idx) {
            const pill = document.createElement('button');
            pill.type = 'button';
            pill.className = 'ax-modal-pill' + (idx === 0 ? ' active' : '');
            pill.textContent = labels[idx];
            pill.onclick = function () {
                pillsWrap.querySelectorAll('.ax-modal-pill').forEach(p => p.classList.remove('active'));
                pill.classList.add('active');
                body.querySelectorAll('[data-ax-modal-tab]').forEach(function (t) {
                    t.style.display = (t.dataset.axModalTab === tabId) ? 'block' : 'none';
                });
                axRunTabLoader(tabId);
            };
            pillsWrap.appendChild(pill);
        });
    }

    overlay.classList.add('open');
    axLockBodyScroll(true);
    axRunTabLoader(tabIds[0]);

    // اگر این آیتم برای یک زیربخش خاص (مثل «تخفیف و کمیسیون» داخل تب
    // «تبادل ارزی») یک anchor دارد، بعد از باز شدن مودال مستقیم به همان‌جا
    // اسکرول می‌شود — کاربر مجبور نیست وسط بخش‌های دیگر همان تب بگردد.
    if (scrollToId) {
        setTimeout(function () {
            const anchorEl = document.getElementById(scrollToId);
            if (anchorEl) anchorEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 80);
    }
}

function axCloseQuickModal() {
    // اگر مودالی از داخل این مودال باز شده بود، اول آن بسته می‌شود تا روی
    // داشبورد جا نماند و کلیک‌ها را نگیرد.
    axCloseOrphanOverlays();
    const overlay = document.getElementById('axQuickModalOverlay');
    axQuickModalMoved.forEach(function (item) {
        // نکته‌ی مهم: کلاس «active» (که هنگام باز شدن مودال اضافه شده بود) باید
        // برداشته شود، وگرنه چون قانون CSS «.tab-content.active{display:block}»
        // است، بعد از پاک‌کردن استایل اینلاین، این بخش برای همیشه نمایان می‌ماند
        // و چون در انتهای admin-main قرار دارد، انگار «به ته صفحه اضافه شده».
        item.el.classList.remove('active');
        item.el.style.display = '';
        delete item.el.dataset.axModalTab;
        if (item.next) item.parent.insertBefore(item.el, item.next);
        else item.parent.appendChild(item.el);
    });
    axQuickModalMoved = [];
    overlay.classList.remove('open');
    axLockBodyScroll(false);

    // ایمنی اضافه: اگر به هر دلیلی چیزی خارج از ردیابی بالا کلاس active را
    // نگه داشته باشد، این‌جا هم پاک می‌شود تا مطمئن شویم بعد از بستن مودال
    // هرگز چیزی جز داشبورد در صفحه نمایان نمی‌ماند.
    document.querySelectorAll('.tab-content.active').forEach(function (el) {
        if (el.id !== 'tab-overview') el.classList.remove('active');
    });
}

// قفل/بازکردن اسکرول پس‌زمینه هنگام باز بودن مودال — با جبران عرض اسکرول‌بار.
// بدون این جبران، وقتی overflow:hidden اسکرول‌بار عمودی را حذف می‌کند، کل
// صفحه چند پیکسل عریض‌تر می‌شود و گرید/کارت‌ها یک‌لحظه جابه‌جا/بزرگ به‌نظر
// می‌رسند («بقیه‌ی صفحه بهم می‌ریزد») — این دقیقاً همان چیزی بود که گزارش شده بود.
// بستن با کلید Escape و همچنین بستن مودال‌های بازِ داخل آن،
// تا هیچ لایه‌ای روی صفحه جا نماند.
document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var openInner = document.querySelector('.modal-overlay.open');
    if (openInner) { openInner.classList.remove('open'); return; }
    var quick = document.getElementById('axQuickModalOverlay');
    if (quick && quick.classList.contains('open')) axCloseQuickModal();
});

function axLockBodyScroll(lock) {
    if (lock) {
        const scrollbarWidth = window.innerWidth - document.documentElement.clientWidth;
        document.body.style.overflow = 'hidden';
        if (scrollbarWidth > 0) document.body.style.paddingInlineEnd = scrollbarWidth + 'px';
    } else {
        document.body.style.overflow = '';
        document.body.style.paddingInlineEnd = '';
    }
}


// ====================================================
// TAB: REFERRALS (کاربران دعوت‌شده / زیرمجموعه‌گیری)
// ====================================================
function arfAdminLoadSettings() {
    fetch('api/referral_api.php?action=admin_get_settings')
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            document.getElementById('arfAdminWelcome').value = data.settings.welcome_bonus_eur;
            document.getElementById('arfAdminCommission').value = data.settings.commission_per_tx_eur;
            document.getElementById('arfAdminMinSettle').value = data.settings.min_settlement_eur;
        }).catch(() => {});
}

function arfAdminSaveSettings() {
    const msg = document.getElementById('arfAdminSettingsMsg');
    msg.textContent = 'در حال ذخیره...'; msg.style.color = 'rgba(255,255,255,.6)';
    fetch('api/referral_api.php?action=admin_save_settings', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            welcome_bonus_eur: parseFloat(document.getElementById('arfAdminWelcome').value || 0),
            commission_per_tx_eur: parseFloat(document.getElementById('arfAdminCommission').value || 0),
            min_settlement_eur: parseFloat(document.getElementById('arfAdminMinSettle').value || 0)
        })
    }).then(r => r.json()).then(data => {
        if (data.success) { msg.textContent = '✅ تنظیمات ذخیره شد'; msg.style.color = '#4ade80'; if (typeof showAdminToast === 'function') showAdminToast('تنظیمات زیرمجموعه‌گیری ذخیره شد', 'success'); }
        else { msg.textContent = '❌ ' + (data.message || 'خطا در ذخیره'); msg.style.color = '#f87171'; }
        setTimeout(() => { msg.textContent = ''; }, 3000);
    }).catch(() => { msg.textContent = '❌ خطای ارتباط با سرور'; msg.style.color = '#f87171'; });
}

let arfAdminSearchTimer = null;
function arfAdminSearchDebounced() {
    clearTimeout(arfAdminSearchTimer);
    arfAdminSearchTimer = setTimeout(arfAdminLoadList, 350);
}

/* ==================== بخش «داده»: پاک‌سازی داده‌ی کاربر + بکاپ ==================== */
const DM_API = 'api/data_management_api.php';
let dmSelectedUserId = null;
let dmSearchTimer = null;

function dmSearchUsers(q) {
    clearTimeout(dmSearchTimer);
    const box = document.getElementById('dmUserSearchResults');
    if (!q || q.trim().length < 2) { box.style.display = 'none'; box.innerHTML = ''; return; }
    dmSearchTimer = setTimeout(async () => {
        try {
            const res = await fetch(DM_API + '?action=search_users&search=' + encodeURIComponent(q));
            const data = await res.json();
            if (!data.success || !data.users.length) {
                box.innerHTML = '<div style="padding:12px;font-size:.75rem;color:rgba(255,255,255,0.5);">کاربری یافت نشد</div>';
                box.style.display = 'block';
                return;
            }
            box.innerHTML = data.users.map(u => `
                <div style="padding:10px 12px;border-bottom:1px solid rgba(255,255,255,0.06);cursor:pointer;font-size:.78rem;" onclick="dmSelectUser(${u.id}, '${(u.first_name||'').replace(/'/g,"")} ${(u.last_name||'').replace(/'/g,"")}')">
                    <div style="color:#fff;font-weight:600;">#${u.id} — ${u.first_name||''} ${u.last_name||''}</div>
                    <div style="color:rgba(255,255,255,0.5);margin-top:2px;">${u.email||''} ${u.phone_number ? '· '+u.phone_number : ''}</div>
                </div>`).join('');
            box.style.display = 'block';
        } catch(e) { box.style.display = 'none'; }
    }, 300);
}

async function dmSelectUser(id, name) {
    dmSelectedUserId = id;
    document.getElementById('dmUserSearchResults').style.display = 'none';
    document.getElementById('dmUserSearch').value = name + ' (#' + id + ')';
    const infoEl = document.getElementById('dmSelectedUserInfo');
    const listEl = document.getElementById('dmSummaryList');
    const box = document.getElementById('dmSelectedUserBox');
    infoEl.textContent = 'در حال بارگذاری خلاصه‌ی داده‌ها...';
    listEl.innerHTML = '';
    box.style.display = 'block';
    try {
        const res = await fetch(DM_API + '?action=get_user_summary&user_id=' + id);
        const data = await res.json();
        if (!data.success) { infoEl.textContent = data.message || 'خطا'; return; }
        infoEl.textContent = `کاربر #${data.user.id} — ${data.user.first_name||''} ${data.user.last_name||''}`;
        const bal = data.balances || {};
        const balRows = Object.entries(bal).filter(([,v]) => v != 0).map(([cur,v]) => `
            <div style="display:flex;justify-content:space-between;background:rgba(239,68,68,0.06);border-radius:8px;padding:8px 10px;">
                <span style="color:rgba(255,255,255,0.7);">موجودی کیف پول (${cur})</span>
                <span style="color:#fbbf24;font-weight:700;">${Number(v).toLocaleString('fa-IR')}</span>
            </div>`).join('');
        listEl.innerHTML = balRows + Object.values(data.counts).map(c => `
            <div style="display:flex;justify-content:space-between;background:rgba(255,255,255,0.03);border-radius:8px;padding:8px 10px;">
                <span style="color:rgba(255,255,255,0.7);">${c.label}</span>
                <span style="color:${c.count>0?'#fbbf24':'rgba(255,255,255,0.35)'};font-weight:700;">${c.count.toLocaleString('fa-IR')}</span>
            </div>`).join('') + `
            <div style="display:flex;justify-content:space-between;padding:8px 10px;border-top:1px solid rgba(255,255,255,0.1);margin-top:2px;">
                <span style="color:#fff;font-weight:700;">مجموع رکورد</span>
                <span style="color:#f87171;font-weight:800;">${data.total.toLocaleString('fa-IR')}</span>
            </div>`;
    } catch(e) { infoEl.textContent = 'خطا در ارتباط با سرور'; }
}

function dmOpenWipeConfirm() {
    if (!dmSelectedUserId) return;
    document.getElementById('dmWipeUserIdShow').textContent = '#' + dmSelectedUserId;
    document.getElementById('dmWipeConfirmInput').value = '';
    document.getElementById('dmWipeMsg').textContent = '';
    openModal('dmWipeModal');
}

async function dmConfirmWipe() {
    const typed = parseInt(document.getElementById('dmWipeConfirmInput').value || '0', 10);
    const msg = document.getElementById('dmWipeMsg');
    if (typed !== dmSelectedUserId) { msg.style.color = '#f87171'; msg.textContent = 'شناسه‌ی واردشده مطابقت ندارد'; return; }
    const btn = document.getElementById('dmWipeConfirmBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال پاک‌سازی...';
    try {
        const res = await fetch(DM_API + '?action=wipe_user_data', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ user_id: dmSelectedUserId, confirm_user_id: typed })
        });
        const data = await res.json();
        if (data.success) {
            msg.style.color = '#4ade80';
            msg.textContent = `✅ ${data.total.toLocaleString('fa-IR')} رکورد پاک شد و موجودی کیف پول صفر شد`;
            showAdminToast('داده‌های کاربر پاک‌سازی شد', 'success');
            setTimeout(() => { closeModal('dmWipeModal'); dmSelectUser(dmSelectedUserId, document.getElementById('dmUserSearch').value); }, 1200);
        } else {
            msg.style.color = '#f87171'; msg.textContent = data.message || 'خطا';
        }
    } catch(e) { msg.style.color = '#f87171'; msg.textContent = 'خطا در ارتباط با سرور'; }
    btn.disabled = false; btn.innerHTML = '<i class="fas fa-trash-can"></i> بله، برای همیشه پاک کن';
}

/* ---------- بکاپ ---------- */
async function dmLoadBackups() {
    const wrap = document.getElementById('dmBackupList');
    const info = document.getElementById('dmLastAutoInfo');
    wrap.innerHTML = '<div class="ax-empty"><i class="fas fa-spinner fa-spin"></i> در حال بارگذاری...</div>';
    try {
        const res = await fetch(DM_API + '?action=list_backups');
        const data = await res.json();
        if (!data.success) { wrap.innerHTML = '<div class="ax-empty">خطا در بارگذاری</div>'; return; }
        info.textContent = data.last_auto ? ('آخرین بک‌آپ خودکار: ' + data.last_auto) : 'هنوز بک‌آپ خودکاری انجام نشده — با اولین باز شدن پنل انجام می‌شود.';
        if (!data.files.length) { wrap.innerHTML = '<div class="ax-empty"><i class="fas fa-box-open"></i> هنوز هیچ بک‌آپی وجود ندارد</div>'; return; }
        wrap.innerHTML = data.files.map(f => `
            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;background:rgba(255,255,255,0.03);border:1px solid ${f.kind==='safety'?'rgba(251,191,36,0.35)':'rgba(255,255,255,0.06)'};border-radius:10px;padding:10px 12px;margin-bottom:8px;">
                <div>
                    <div style="font-size:.78rem;color:#fff;font-weight:600;">
                        ${f.date}
                        ${f.kind==='safety' ? '<span style="margin-right:6px;font-size:.62rem;background:rgba(251,191,36,0.15);color:#fbbf24;padding:2px 7px;border-radius:20px;">بک‌آپ ایمنی قبل از ریستور</span>' : ''}
                    </div>
                    <div style="font-size:.68rem;color:rgba(255,255,255,0.45);">${f.sizeKb.toLocaleString('fa-IR')} KB</div>
                </div>
                <div style="display:flex;gap:6px;">
                    <a href="${DM_API}?action=download_backup&file=${encodeURIComponent(f.name)}" class="btn-sm" style="width:auto;padding:7px 10px;text-decoration:none;"><i class="fas fa-download"></i></a>
                    <button class="btn-sm" style="width:auto;padding:7px 10px;background:rgba(239,68,68,0.12);color:#f87171;" onclick="dmOpenRestoreConfirm('${f.name}')"><i class="fas fa-clock-rotate-left"></i> ریستور</button>
                    <button class="btn-sm" style="width:auto;padding:7px 10px;background:rgba(239,68,68,0.12);color:#f87171;" onclick="dmOpenDeleteConfirm('${f.name}')"><i class="fas fa-trash-can"></i></button>
                </div>
            </div>`).join('');
    } catch(e) { wrap.innerHTML = '<div class="ax-empty">خطا در ارتباط با سرور</div>'; }
}

async function dmRunBackupNow() {
    const btn = document.getElementById('dmBackupNowBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال گرفتن بک‌آپ...';
    try {
        const res = await fetch(DM_API + '?action=run_backup_now', { method: 'POST' });
        const data = await res.json();
        let msg = data.success ? 'بک‌آپ با موفقیت گرفته شد' : (data.message || 'خطا در گرفتن بک‌آپ');
        let kind = data.success ? 'success' : 'error';
        if (data.success && Array.isArray(data.failed_tables) && data.failed_tables.length) {
            msg = 'بک‌آپ گرفته شد، اما ' + data.failed_tables.length + ' جدول رد شد: ' + data.failed_tables.join('، ');
            kind = 'error';
        }
        showAdminToast(msg, kind);
        dmLoadBackups();
    } catch(e) { showAdminToast('خطا در ارتباط با سرور', 'error'); }
    btn.disabled = false; btn.innerHTML = '<i class="fas fa-cloud-arrow-up"></i> بک‌آپ فوری';
}

let dmRestoreFile = null;
function dmOpenRestoreConfirm(fileName) {
    dmRestoreFile = fileName;
    document.getElementById('dmRestoreFileShow').textContent = fileName;
    document.getElementById('dmRestoreConfirmInput').value = '';
    document.getElementById('dmRestoreMsg').textContent = '';
    openModal('dmRestoreModal');
}

async function dmConfirmRestore() {
    const typed = (document.getElementById('dmRestoreConfirmInput').value || '').trim();
    const msg = document.getElementById('dmRestoreMsg');
    if (typed !== 'RESTORE') { msg.style.color = '#f87171'; msg.textContent = 'عبارت تاییدیه دقیق نیست'; return; }
    const btn = document.getElementById('dmRestoreConfirmBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال ریستور...';
    try {
        const res = await fetch(DM_API + '?action=restore_backup', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ file: dmRestoreFile, confirm_text: typed })
        });
        const data = await res.json();
        if (data.success) {
            msg.style.color = '#4ade80'; msg.textContent = '✅ ریستور با موفقیت انجام شد';
            showAdminToast('دیتابیس ریستور شد', 'success');
            setTimeout(() => { closeModal('dmRestoreModal'); location.reload(); }, 1500);
        } else {
            msg.style.color = '#f87171'; msg.textContent = data.message || 'خطا در ریستور';
        }
    } catch(e) { msg.style.color = '#f87171'; msg.textContent = 'خطا در ارتباط با سرور'; }
    btn.disabled = false; btn.innerHTML = '<i class="fas fa-clock-rotate-left"></i> بله، ریستور کن';
}

let dmDeleteFile = null;
function dmOpenDeleteConfirm(fileName) {
    dmDeleteFile = fileName;
    document.getElementById('dmDeleteFileShow').textContent = fileName;
    document.getElementById('dmDeleteMsg').textContent = '';
    openModal('dmDeleteModal');
}

async function dmConfirmDelete() {
    const btn = document.getElementById('dmDeleteConfirmBtn');
    const msg = document.getElementById('dmDeleteMsg');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال حذف...';
    try {
        const res = await fetch(DM_API + '?action=delete_backup', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ file: dmDeleteFile })
        });
        const data = await res.json();
        if (data.success) {
            showAdminToast('بک‌آپ حذف شد', 'success');
            closeModal('dmDeleteModal');
            dmLoadBackups();
        } else {
            msg.style.color = '#f87171'; msg.textContent = data.message || 'خطا در حذف';
        }
    } catch(e) { msg.style.color = '#f87171'; msg.textContent = 'خطا در ارتباط با سرور'; }
    btn.disabled = false; btn.innerHTML = '<i class="fas fa-trash-can"></i> بله، حذف کن';
}

/* ==================== آپدیت سایت (آپلود zip) ==================== */
const SU_API = 'api/site_update_api.php';

async function suRefreshStatus(){
    try {
        const res = await fetch(SU_API + '?action=status', { cache: 'no-store' });
        const d = await res.json();
        const banner = document.getElementById('suActiveBanner');
        if (banner) banner.style.display = (d && d.active) ? '' : 'none';
    } catch(e){}
}

async function suEndMaintenance(){
    if (!confirm('حالت آپدیت خاموش شود؟ کاربران عادی دوباره سایت را عادی خواهند دید.')) return;
    try {
        const res = await fetch(SU_API + '?action=end_maintenance', { method: 'POST' });
        const d = await res.json();
        showAdminToast(d.message || (d.success ? 'انجام شد' : 'خطا'), d.success ? 'success' : 'error');
        suRefreshStatus();
    } catch(e){ showAdminToast('خطا در ارتباط با سرور', 'error'); }
}

function suOpenConfirm(){
    const fileInput = document.getElementById('suZipInput');
    if (!fileInput.files || !fileInput.files.length) { showAdminToast('اول فایل zip را انتخاب کنید', 'error'); return; }
    document.getElementById('suConfirmInput').value = '';
    document.getElementById('suConfirmMsg').textContent = '';
    openModal('suConfirmModal');
}

async function suConfirmStart(){
    const typed = (document.getElementById('suConfirmInput').value || '').trim();
    const msg = document.getElementById('suConfirmMsg');
    if (typed !== 'UPDATE') { msg.style.color = '#f87171'; msg.textContent = 'عبارت تاییدیه دقیق نیست'; return; }
    const fileInput = document.getElementById('suZipInput');
    if (!fileInput.files || !fileInput.files.length) { msg.style.color = '#f87171'; msg.textContent = 'فایل zip انتخاب نشده'; return; }

    const btn = document.getElementById('suConfirmBtn');
    const startBtn = document.getElementById('suStartBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال آپلود و اعمال...';
    if (startBtn) startBtn.disabled = true;

    const fd = new FormData();
    fd.append('action', 'start_update');
    fd.append('zip', fileInput.files[0]);
    fd.append('message', document.getElementById('suMessage').value || '');
    fd.append('duration', document.getElementById('suDuration').value || '90');
    fd.append('include_config', document.getElementById('suIncludeConfig').checked ? '1' : '');
    fd.append('include_uploads', document.getElementById('suIncludeUploads').checked ? '1' : '');

    document.getElementById('suProgressMsg').innerHTML = '<span style="color:#FBBF24;"><i class="fas fa-spinner fa-spin"></i> در حال آپلود و اعمالِ آپدیت — این پنجره را نبندید...</span>';
    suRefreshStatus();

    try {
        const res = await fetch(SU_API, { method: 'POST', body: fd });
        const d = await res.json();
        if (d.success) {
            msg.style.color = '#4ade80'; msg.textContent = '✅ با موفقیت انجام شد';
            document.getElementById('suProgressMsg').innerHTML = '<span style="color:#4ade80;">✅ آپدیت اعمال شد (' + (d.files_updated || 0) + ' فایل). بکاپ: ' + (d.backup_file || '-') + '</span>';
            showAdminToast('آپدیت با موفقیت اعمال شد', 'success');
            setTimeout(() => { closeModal('suConfirmModal'); }, 1200);
            suLoadBackups();
        } else {
            msg.style.color = '#f87171'; msg.textContent = d.message || 'خطا در آپدیت';
            document.getElementById('suProgressMsg').innerHTML = '<span style="color:#f87171;">❌ ' + (d.message || 'خطا در آپدیت') + '</span>';
        }
    } catch(e) {
        msg.style.color = '#f87171'; msg.textContent = 'خطا در ارتباط با سرور';
        document.getElementById('suProgressMsg').innerHTML = '<span style="color:#f87171;">❌ خطا در ارتباط با سرور</span>';
    }
    suRefreshStatus();
    btn.disabled = false; btn.innerHTML = '<i class="fas fa-rocket"></i> بله، آپدیت کن';
    if (startBtn) startBtn.disabled = false;
}

async function suLoadBackups(){
    const wrap = document.getElementById('suBackupList');
    if (!wrap) return;
    try {
        const res = await fetch(SU_API + '?action=list_backups');
        const d = await res.json();
        const items = (d && d.success && Array.isArray(d.items)) ? d.items : [];
        if (!items.length) { wrap.innerHTML = '<div class="ax-empty">هنوز بکاپی گرفته نشده</div>'; return; }
        wrap.innerHTML = items.map(it => {
            const sizeMb = (it.size / 1048576).toFixed(1);
            return '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px;border-radius:10px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.07);margin-bottom:8px;">'
                + '<div style="font-size:.7rem;color:rgba(255,255,255,.7);">'
                    + '<div style="font-weight:700;color:#fff;">' + it.time + '</div>'
                    + '<div style="color:rgba(255,255,255,.45);">' + sizeMb + ' MB</div>'
                + '</div>'
                + '<button type="button" class="ax-btn" style="background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.35);color:#f87171;" onclick="suOpenRestoreConfirm(\'' + it.file + '\')"><i class="fas fa-clock-rotate-left"></i> بازگردانی</button>'
            + '</div>';
        }).join('');
    } catch(e){ wrap.innerHTML = '<div class="ax-empty">خطا در بارگذاری</div>'; }
}

let suRestoreFile = null;
function suOpenRestoreConfirm(fileName){
    suRestoreFile = fileName;
    document.getElementById('suRestoreFileShow').textContent = fileName;
    document.getElementById('suRestoreConfirmInput').value = '';
    document.getElementById('suRestoreMsg').textContent = '';
    openModal('suRestoreModal');
}

async function suConfirmRestore(){
    const typed = (document.getElementById('suRestoreConfirmInput').value || '').trim();
    const msg = document.getElementById('suRestoreMsg');
    if (typed !== 'RESTORE') { msg.style.color = '#f87171'; msg.textContent = 'عبارت تاییدیه دقیق نیست'; return; }
    const btn = document.getElementById('suRestoreConfirmBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال بازگردانی...';
    try {
        const res = await fetch(SU_API + '?action=restore_backup', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ file: suRestoreFile, duration: 60 })
        });
        const d = await res.json();
        if (d.success) {
            msg.style.color = '#4ade80'; msg.textContent = '✅ بازگردانی انجام شد';
            showAdminToast('بازگردانی انجام شد', 'success');
            setTimeout(() => { closeModal('suRestoreModal'); }, 1200);
        } else {
            msg.style.color = '#f87171'; msg.textContent = d.message || 'خطا در بازگردانی';
        }
    } catch(e) { msg.style.color = '#f87171'; msg.textContent = 'خطا در ارتباط با سرور'; }
    btn.disabled = false; btn.innerHTML = '<i class="fas fa-clock-rotate-left"></i> بله، بازگردان';
    suRefreshStatus();
}

/* ==================== سطح‌بندی کاربران (Tier / VIP) ==================== */
const TIER_CURRENCY_LABELS = { IRR: 'تومان', USD: 'دلار', EUR: 'یورو', USDT: 'تتر' };
let tierCurrentCurrency = 'IRR';

function tierLoadList() {
    const box = document.getElementById('tierListWrap');
    box.innerHTML = '<div class="ax-empty"><i class="fas fa-spinner fa-spin"></i> در حال بارگذاری...</div>';
    fetch('api/tier_api.php?action=admin_list')
        .then(r => r.json())
        .then(data => {
            tierCurrentCurrency = data.volume_currency || 'IRR';
            const sel = document.getElementById('tierCurrencySelect');
            if (sel) sel.value = tierCurrentCurrency;
            const curLabel = TIER_CURRENCY_LABELS[tierCurrentCurrency] || 'تومان';

            if (!data.success || !data.tiers || !data.tiers.length) {
                box.innerHTML = '<div class="ax-empty">هنوز هیچ سطحی تعریف نشده</div>';
                return;
            }
            let html = '<div style="display:flex;flex-direction:column;gap:8px;">';
            data.tiers.forEach(t => {
                html += `<div style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:10px 12px;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div style="width:36px;height:36px;border-radius:11px;display:flex;align-items:center;justify-content:center;color:#fff;background:${escapeHtml(t.badge_color)};"><i class="fas ${escapeHtml(t.badge_icon)}"></i></div>
                        <div>
                            <b style="color:#fff;">${escapeHtml(t.name)}</b>
                            <div style="color:rgba(255,255,255,.5);font-size:.68rem;margin-top:2px;">حداقل حجم: ${parseFloat(t.min_volume_toman).toLocaleString('fa-IR')} ${curLabel} · تخفیف: %${t.discount_percent}</div>
                        </div>
                    </div>
                    <div style="display:flex;gap:6px;">
                        <button class="btn-sm" style="width:auto;" onclick='tierOpenForm(${JSON.stringify(t)})'><i class="fas fa-edit"></i></button>
                        <button class="btn-sm" style="width:auto;background:rgba(255,59,48,0.15);border-color:#FF3B30;color:#FF3B30;" onclick="tierDelete(${t.id})"><i class="fas fa-trash"></i></button>
                    </div>
                </div>`;
            });
            html += '</div>';
            box.innerHTML = html;
        })
        .catch(() => { box.innerHTML = '<div class="ax-empty">خطا در دریافت اطلاعات</div>'; });
}

function tierSaveCurrency() {
    const msg = document.getElementById('tierCurrencyMsg');
    const val = document.getElementById('tierCurrencySelect').value;
    msg.textContent = '';
    const fd = new FormData();
    fd.append('action', 'admin_set_currency');
    fd.append('volume_currency', val);
    fetch('api/tier_api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            msg.style.color = data.success ? '#4CD964' : '#FF3B30';
            msg.textContent = data.success ? 'ارز مبنای سطح‌بندی ذخیره شد' : (data.message || 'خطا');
            if (data.success) tierLoadList();
        })
        .catch(() => { msg.style.color = '#FF3B30'; msg.textContent = 'خطا در ارتباط با سرور'; });
}

function tierOpenForm(t) {
    document.getElementById('tierFormCard').style.display = 'block';
    document.getElementById('tierFormTitle').textContent = t ? 'ویرایش سطح' : 'سطح جدید';
    document.getElementById('tierFormId').value = t ? t.id : '';
    document.getElementById('tierFormName').value = t ? t.name : '';
    document.getElementById('tierFormMinVolume').value = t ? t.min_volume_toman : '';
    document.getElementById('tierFormMinVolume').placeholder = 'حداقل حجم معاملات (' + (TIER_CURRENCY_LABELS[tierCurrentCurrency] || 'تومان') + ')';
    document.getElementById('tierFormDiscount').value = t ? t.discount_percent : '';
    document.getElementById('tierFormIcon').value = t ? t.badge_icon : 'fa-medal';
    document.getElementById('tierFormColor').value = t ? t.badge_color : '#B8860B';
    document.getElementById('tierFormCard').scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function tierSave() {
    const msg = document.getElementById('tierFormMsg');
    msg.textContent = '';
    const fd = new FormData();
    fd.append('action', 'admin_save');
    fd.append('id', document.getElementById('tierFormId').value || '0');
    fd.append('name', document.getElementById('tierFormName').value.trim());
    fd.append('min_volume_toman', document.getElementById('tierFormMinVolume').value || '0');
    fd.append('discount_percent', document.getElementById('tierFormDiscount').value || '0');
    fd.append('badge_icon', document.getElementById('tierFormIcon').value.trim() || 'fa-medal');
    fd.append('badge_color', document.getElementById('tierFormColor').value || '#B8860B');
    fetch('api/tier_api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            msg.style.color = data.success ? '#4CD964' : '#FF3B30';
            msg.textContent = data.message || (data.success ? 'ذخیره شد' : 'خطا');
            if (data.success) { document.getElementById('tierFormCard').style.display = 'none'; tierLoadList(); }
        })
        .catch(() => { msg.style.color = '#FF3B30'; msg.textContent = 'خطا در ارتباط با سرور'; });
}

function tierDelete(id) {
    if (!confirm('این سطح حذف شود؟')) return;
    const fd = new FormData();
    fd.append('action', 'admin_delete');
    fd.append('id', id);
    fetch('api/tier_api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            showAdminToast(data.message || (data.success ? 'حذف شد' : 'خطا'), data.success ? 'success' : 'error');
            if (data.success) tierLoadList();
        })
        .catch(() => showAdminToast('خطا در ارتباط با سرور', 'error'));
}

function impersonateStart() {
    const sel = document.getElementById('impersonateUserSelect');
    const msg = document.getElementById('impersonateMsg');
    const uid = sel ? sel.value : '';
    if (!uid) { msg.style.color = '#FF3B30'; msg.textContent = 'یک کاربر را انتخاب کنید'; return; }
    msg.style.color = 'rgba(255,255,255,.6)';
    msg.textContent = 'در حال ورود...';
    const fd = new FormData();
    fd.append('action', 'start');
    fd.append('user_id', uid);
    fetch('api/admin_impersonate.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                window.location.href = data.redirect || 'dashboard.php';
            } else {
                msg.style.color = '#FF3B30';
                msg.textContent = data.message || 'خطا در ورود به داشبورد کاربر';
            }
        })
        .catch(() => { msg.style.color = '#FF3B30'; msg.textContent = 'خطا در ارتباط با سرور'; });
}

/* ==================== استوری تبلیغاتی (مثل اینستاگرام) ==================== */
async function axStoryUpload(){
    const fileEl = document.getElementById('axStoryFile');
    const msg = document.getElementById('axStoryUploadMsg');
    const btn = document.getElementById('axStoryUploadBtn');
    if (!fileEl.files || !fileEl.files.length) {
        msg.style.color = '#FF3B30'; msg.textContent = 'یک فایل انتخاب کنید';
        return;
    }
    const fd = new FormData();
    fd.append('action', 'admin_upload');
    fd.append('media', fileEl.files[0]);
    fd.append('caption', document.getElementById('axStoryCaption').value.trim());
    fd.append('link_url', document.getElementById('axStoryLink').value.trim());

    btn.disabled = true;
    msg.style.color = 'rgba(255,255,255,.6)';
    msg.textContent = 'در حال آپلود...';
    try {
        const res = await fetch('api/admin_stories_api.php', { method: 'POST', body: fd });
        const data = await res.json();
        msg.style.color = data.success ? '#4CD964' : '#FF3B30';
        msg.textContent = data.message || (data.success ? 'منتشر شد' : 'خطا');
        if (data.success) {
            fileEl.value = '';
            document.getElementById('axStoryCaption').value = '';
            document.getElementById('axStoryLink').value = '';
            axStoriesLoadList();
        }
    } catch(e) { msg.style.color = '#FF3B30'; msg.textContent = 'خطا در ارتباط با سرور'; }
    btn.disabled = false;
}

async function axStoriesLoadList(){
    const box = document.getElementById('axStoriesList');
    if (!box) return;
    box.innerHTML = '<div class="ax-empty"><i class="fas fa-spinner fa-spin"></i> در حال بارگذاری...</div>';
    try {
        const res = await fetch('api/admin_stories_api.php?action=admin_list', { cache:'no-store' });
        const data = await res.json();
        if (!data.success || !data.items || !data.items.length) {
            box.innerHTML = '<div class="ax-empty">هیچ استوری‌ای منتشر نشده</div>';
            return;
        }
        box.innerHTML = data.items.map(function(s){
            const isVideo = s.media_type === 'video';
            const isExpired = new Date(s.expires_at.replace(' ', 'T')) <= new Date();
            const thumb = isVideo
                ? '<div style="width:52px;height:52px;border-radius:12px;background:#000;display:flex;align-items:center;justify-content:center;color:#fff;"><i class="fas fa-video"></i></div>'
                : `<img src="/ledor/${s.media_path}" style="width:52px;height:52px;border-radius:12px;object-fit:cover;">`;
            return `<div class="ax-item" style="display:flex;align-items:center;gap:12px;padding:10px 12px;${isExpired ? 'opacity:.5;' : ''}">
                ${thumb}
                <div style="flex:1;min-width:0;">
                    <div style="color:#fff;font-size:.78rem;font-weight:700;">${axEsc(s.caption || '(بدون کپشن)')}</div>
                    <div style="font-size:.68rem;color:rgba(255,255,255,0.5);margin-top:2px;">
                        <i class="fas fa-eye"></i> ${s.view_count} بازدید ${isExpired ? '· منقضی‌شده' : '· تا ' + axEsc(s.expires_at)}
                    </div>
                </div>
                <button class="ax-btn ax-btn-view" style="width:auto;background:rgba(255,59,48,0.15);border-color:#FF3B30;color:#FF3B30;" onclick="axStoryDelete(${s.id})"><i class="fas fa-trash"></i></button>
            </div>`;
        }).join('');
    } catch(e){ box.innerHTML = '<div class="ax-empty">خطا در دریافت اطلاعات</div>'; }
}

async function axStoryDelete(storyId){
    if (!confirm('این استوری حذف شود؟')) return;
    try {
        const res = await fetch('api/admin_stories_api.php?action=admin_delete', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ story_id: storyId })
        });
        const data = await res.json();
        showAdminToast(data.message || (data.success ? 'حذف شد' : 'خطا'), data.success ? 'success' : 'error');
        if (data.success) axStoriesLoadList();
    } catch(e){ showAdminToast('خطا در ارتباط با سرور', 'error'); }
}

function arfAdminLoadList() {
    const box = document.getElementById('arfAdminList');
    const q = (document.getElementById('arfAdminSearch') || {}).value || '';
    box.innerHTML = '<div class="ax-empty"><i class="fas fa-spinner fa-spin"></i> در حال بارگذاری...</div>';
    fetch('api/referral_api.php?action=admin_list&q=' + encodeURIComponent(q))
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.items || !data.items.length) {
                box.innerHTML = '<div class="ax-empty">هنوز هیچ کاربری از طریق لینک دعوت ثبت‌نام نکرده است</div>';
                return;
            }
            let html = '<div style="display:flex;flex-direction:column;gap:8px;">';
            data.items.forEach(row => {
                const referrerName = escapeHtml((row.referrer_first || '') + ' ' + (row.referrer_last || '')) || 'کاربر';
                const referredName = escapeHtml((row.referred_first || '') + ' ' + (row.referred_last || '')) || 'کاربر';
                html += `<div style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:10px 12px;font-size:.75rem;color:#fff;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
                    <div>
                        <b>${referrerName}</b> <span style="color:rgba(255,255,255,.5);">دعوت کرد</span> <b style="color:#c4a3ff;">${referredName}</b>
                        <div style="color:rgba(255,255,255,.45);font-size:.68rem;margin-top:3px;">کد معرف: ${escapeHtml(row.referral_code || '-')} · تاریخ عضویت: ${formatDate(row.joined_at)}</div>
                    </div>
                    <div style="text-align:left;">
                        <div style="color:#4ade80;font-weight:800;">${parseFloat(row.total_earned).toFixed(2)}€</div>
                        <div style="color:rgba(255,255,255,.5);font-size:.68rem;">${row.tx_count} تراکنش موفق</div>
                    </div>
                </div>`;
            });
            html += '</div>';
            box.innerHTML = html;
        }).catch(() => { box.innerHTML = '<div class="ax-empty">خطا در بارگذاری لیست</div>'; });
}


// کلیک روی کادرهای تابلوی کلی => رفتن به تب مربوطه
document.querySelectorAll('.ov-tile[data-goto]').forEach(tile => {
    tile.addEventListener('click', function() {
        const target = this.dataset.goto;
        axGoToTab(target);
    });
});

// ====================================================
// TOP-UP MANAGEMENT
// ====================================================
const TOPUP_API = 'api/topup_api.php';

let topupCache = {};

// حذف یک درخواست top-up از لیست توسط ادمین
async function adminDeleteTopup(reqId) {
    if (!confirm('این درخواست از لیست حذف شود؟ این عمل قابل بازگشت نیست.')) return;
    try {
        const res = await fetch(TOPUP_API + '?action=admin_delete_request', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ request_id: parseInt(reqId) })
        });
        const data = await res.json();
        if (data.success) {
            const card = document.getElementById('topup-card-' + reqId);
            if (card) card.remove();
            showAdminToast('درخواست حذف شد', 'success');
            adminLoadTopups(adminCurrentFilter);
        } else {
            showAdminToast(data.message || 'خطا در حذف', 'error');
        }
    } catch (e) { showAdminToast('خطا در ارتباط', 'error'); }
}

async function adminLoadTopups(status, tabEl) {
    adminCurrentFilter = status;
    if (tabEl) {
        document.querySelectorAll('#admin-topup-list ~ .filter-tab, .admin-topup-section .filter-tab').forEach(b => b.classList.remove('active'));
        tabEl.classList.add('active');
    }
    const list = document.getElementById('admin-topup-list');
    list.innerHTML = '<div class="skeleton" style="height:56px;"></div><div class="skeleton" style="height:56px;margin-top:8px;"></div>';
    try {
        const url = TOPUP_API + '?action=admin_get_requests' + (status ? '&status=' + status : '');
        const res = await fetch(url);
        const data = await res.json();
        if (!data.success || !data.requests.length) {
            list.innerHTML = '<div class="empty-state"><i class="fas fa-inbox"></i>هیچ درخواستی پیدا نشد</div>';
            return;
        }
        topupCache = {};
        list.innerHTML = data.requests.map(r => { topupCache[r.id] = r; return buildTopupRow(r); }).join('');
    } catch(e) {
        list.innerHTML = '<div class="empty-state" style="color:#ff4d6d;"><i class="fas fa-exclamation-triangle"></i>خطا در بارگذاری</div>';
    }
}

// Compact one-line row for the top-up list
function buildTopupRow(r) {
    const initials = (((r.first_name||'')[0]||'') + ((r.last_name||'')[0]||'')).toUpperCase() || '?';
    const needsAction = (r.status === 'pending' || r.status === 'payment_received');
    return `
        <div class="admin-row" onclick="openTopupDetail(${r.id})">
            <div class="admin-row-main">
                <div class="admin-row-avatar">${initials}</div>
                <div class="admin-row-txt">
                    <div class="admin-row-title">#${r.id} — ${(r.first_name||'')} ${(r.last_name||'')}</div>
                    <div class="admin-row-sub">${formatDate(r.created_at)} · <span class="${statusClass(r.status)}">${statusLabel(r.status)}</span></div>
                </div>
            </div>
            <div class="admin-row-right">
                <span class="admin-row-amount ${r.currency}">${r.amount} ${r.currency}</span>
                ${needsAction ? '<span class="admin-row-new">NEW</span>' : '<i class="fas fa-chevron-left admin-row-chev"></i>'}
            </div>
        </div>`;
}

function openTopupDetail(id) {
    const r = topupCache[id];
    if (!r) return;
    document.getElementById('topup-detail-body').innerHTML = buildRequestCard(r);
    openModal('topup-detail-modal');
}

function buildRequestCard(r) {
    const initials = ((r.first_name||'')[0]||'') + ((r.last_name||'')[0]||'');
    const pillClass = 'pill-' + r.status;
    const pillLabel = statusLabel(r.status);
    const curClass = r.currency;

    let actions = '';
    if (r.status === 'pending') {
        actions = `
            <button class="btn-admin btn-admin-approve" onclick="openAdminApprove(${r.id})">
                <i class="fas fa-check"></i> تأیید و ارسال اطلاعات بانکی
            </button>
            <button class="btn-admin btn-admin-reject" onclick="openAdminReject(${r.id})">
                <i class="fas fa-times"></i> رد
            </button>
        `;
    } else if (r.status === 'waiting_payment' || r.status === 'payment_received') {
        // parse receipt_image
        let _ri = [];
        if (r.receipt_image) {
            try { const _p = JSON.parse(r.receipt_image); _ri = Array.isArray(_p) ? _p : [r.receipt_image]; }
            catch(e) { _ri = [r.receipt_image]; }
        }
        const receiptBtn = _ri.length > 0
            ? `<button class="btn-admin btn-admin-view-receipt"
                   data-req-id="${r.id}"
                   data-amount="${r.amount}"
                   data-currency="${r.currency}"
                   data-username="${(r.first_name||'')} ${(r.last_name||'')}"
                   data-imgs="${encodeURIComponent(JSON.stringify(_ri))}"
                   onclick="adminViewReceipt(this)">
                 <i class="fas fa-image"></i> مشاهده فیش${_ri.length > 1 ? ' (' + _ri.length + ')' : ''}
               </button>
               <button class="btn-admin btn-admin-complete" onclick="adminCompleteTopupFromCard(${r.id})">
                 <i class="fas fa-check-double"></i> تکمیل Top-up
               </button>`
            : '<span style="font-size:.68rem;color:#9b94b8;">در انتظار آپلود فیش کاربر...</span>';
        actions = receiptBtn;
    } else if (r.status === 'approved') {
        actions = `<button class="btn-admin btn-admin-view-receipt"
                   data-req-id="${r.id}" data-amount="${r.amount}" data-currency="${r.currency}"
                   data-username="${(r.first_name||'')} ${(r.last_name||'')}"
                   data-imgs="${encodeURIComponent('[]')}"
                   onclick="adminViewReceipt(this)"
                   style="background:#21213a;border:1px solid rgba(108,64,197,0.3);color:#9b94b8;">
            <i class="fas fa-info-circle"></i> مشاهده اطلاعات
        </button>`;
    }

    return `
        <div class="topup-request-card" id="topup-card-${r.id}">
            <div class="req-card-header">
                <div class="req-user-info">
                    <div class="req-avatar">${initials.toUpperCase() || '?'}</div>
                    <div>
                        <div class="req-user-name">${r.first_name || ''} ${r.last_name || ''}</div>
                        <div class="req-user-sub">${r.phone_number || r.email || ''}</div>
                    </div>
                </div>
                <div>
                    <div class="req-amount-badge ${curClass}">${r.amount} ${r.currency}</div>
                </div>
            </div>
            <div class="req-card-body">
                <div class="req-meta">
                    <div class="req-meta-item"><strong>#${r.id}</strong></div>
                    <div class="req-meta-item">${formatDate(r.created_at)}</div>
                    <div class="req-meta-item"><span class="${statusClass(r.status)}">${pillLabel}</span></div>
                </div>
                ${r.reject_reason ? `<div style="font-size:.72rem;color:#ff4d6d;margin-top:4px;">دلیل رد: ${r.reject_reason}</div>` : ''}
                ${(() => {
                    if (!r.receipt_image) return '';
                    let _ri2 = [];
                    try { const _p2 = JSON.parse(r.receipt_image); _ri2 = Array.isArray(_p2) ? _p2 : [r.receipt_image]; }
                    catch(e) { _ri2 = [r.receipt_image]; }
                    if (!_ri2.length) return '';
                    const _badge = _ri2.length > 1 ? `<span style="position:absolute;bottom:3px;right:3px;background:rgba(108,64,197,0.9);color:#fff;font-size:.6rem;padding:1px 5px;border-radius:4px;">${_ri2.length}</span>` : '';
                    const _enc = encodeURIComponent(JSON.stringify(_ri2));
                    return `<div style="position:relative;display:inline-block;margin-top:6px;">
                        <img src="${_ri2[0]}" class="receipt-thumb"
                             data-req-id="${r.id}" data-amount="${r.amount}" data-currency="${r.currency}"
                             data-username="${(r.first_name||'')} ${(r.last_name||'')}"
                             data-imgs="${_enc}"
                             onclick="adminViewReceipt(this)"
                             title="مشاهده فیش">${_badge}</div>`;
                })()}
            </div>
            ${actions ? `<div class="admin-action-row">${actions}</div>` : ''}
            <div class="admin-action-row" style="margin-top:6px;">
                <button class="btn-admin" onclick="adminDeleteTopup(${r.id})" style="background:rgba(255,59,48,0.12);border:1px solid rgba(255,59,48,0.4);color:#ff6b6b;">
                    <i class="fas fa-trash"></i> حذف از لیست
                </button>
            </div>
        </div>
    `;
}

function openAdminApprove(reqId) {
    document.getElementById('approve-req-id').value = reqId;
    document.getElementById('approve-bank').value = '';
    document.getElementById('approve-account').value = '';
    document.getElementById('approve-card').value = '';
    document.getElementById('approve-recipient').value = '';
    document.getElementById('approve-iban').value = '';
    document.getElementById('approve-payment-amount').value = '';
    document.getElementById('approve-payment-currency').value = 'IRR';
    document.getElementById('approveTopupSavedAccBox').innerHTML = axSavedAccPickerHtml('axSavedAcc_topup', 'topup');
    axSavedAccLoad('axSavedAcc_topup');
    openModal('admin-approve-modal');
}

async function adminApproveTopup() {
    const reqId = document.getElementById('approve-req-id').value;
    const bank = document.getElementById('approve-bank').value.trim();
    const acct = document.getElementById('approve-account').value.trim();
    const card = document.getElementById('approve-card').value.trim();
    const recip = document.getElementById('approve-recipient').value.trim();
    const iban = document.getElementById('approve-iban').value.trim();
    const paymentAmount = parseFloat(document.getElementById('approve-payment-amount').value);
    const paymentCurrency = document.getElementById('approve-payment-currency').value;

    if (!bank || !acct || !card || !recip) { showAdminToast('لطفاً اطلاعات الزامی را وارد کنید', 'error'); return; }
    if (!paymentAmount || paymentAmount <= 0) { showAdminToast('لطفاً مبلغ قابل پرداخت را وارد کنید', 'error'); return; }

    try {
        const res = await fetch(TOPUP_API + '?action=approve_request', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ request_id: parseInt(reqId), bank_name: bank, account_number: acct, card_number: card, recipient_name: recip, iban, payment_amount: paymentAmount, payment_currency: paymentCurrency })
        });
        const data = await res.json();
        if (data.success) {
            closeModal('admin-approve-modal');
            showAdminToast('اطلاعات بانکی و مبلغ با موفقیت به کاربر ارسال شد', 'success');
            closeModal('topup-detail-modal');
            adminLoadTopups(adminCurrentFilter);
        } else { showAdminToast(data.message || 'خطا', 'error'); }
    } catch(e) { showAdminToast('خطا در ارتباط', 'error'); }
}

function openAdminReject(reqId) {
    document.getElementById('reject-req-id').value = reqId;
    document.getElementById('reject-reason').value = '';
    openModal('admin-reject-modal');
}

async function adminRejectTopup() {
    const reqId = document.getElementById('reject-req-id').value;
    const reason = document.getElementById('reject-reason').value.trim();
    if (!reason) { showAdminToast('لطفاً دلیل رد را وارد کنید', 'error'); return; }

    try {
        const res = await fetch(TOPUP_API + '?action=reject_request', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ request_id: parseInt(reqId), reason })
        });
        const data = await res.json();
        if (data.success) {
            closeModal('admin-reject-modal');
            showAdminToast('درخواست رد شد و کاربر مطلع گردید', 'success');
            closeModal('topup-detail-modal');
            adminLoadTopups(adminCurrentFilter);
        } else { showAdminToast(data.message || 'خطا', 'error'); }
    } catch(e) { showAdminToast('خطا در ارتباط', 'error'); }
}

function adminViewReceipt(el) {
    // داده‌ها از data attribute خوانده می‌شوند
    const reqId    = el.dataset.reqId;
    const amount   = el.dataset.amount;
    const currency = el.dataset.currency;
    const userName = el.dataset.username;
    let list = [];
    try { list = JSON.parse(decodeURIComponent(el.dataset.imgs || '[]')); }
    catch(e) { list = []; }

    const container = document.getElementById('admin-receipt-imgs');
    if (container) {
        if (list.length === 0) {
            container.innerHTML = '<div style="color:#ff9f43;text-align:center;padding:20px 0;">⚠️ کاربر هنوز فیش واریز را آپلود نکرده است.</div>';
        } else {
            container.innerHTML = list.map((src, i) => `
                <div>
                    ${list.length > 1 ? `<div style="font-size:.65rem;color:#9b94b8;margin-bottom:4px;">فیش ${i+1} از ${list.length}</div>` : ''}
                    <img src="${src}" alt="Receipt ${i+1}"
                         style="width:100%;border-radius:10px;cursor:pointer;display:block;"
                         onclick="window.open(this.src,'_blank')"
                         title="کلیک برای بزرگنمایی">
                </div>
            `).join('');
        }
    }

    document.getElementById('complete-req-id').value = reqId;
    document.getElementById('complete-req-info').innerHTML = `
        <span style="color:#f0eeff;font-weight:700;">${userName}</span> — مبلغ: <span style="color:#6C40C5;font-weight:700;">${amount} ${currency}</span>
        ${list.length > 1 ? `<div style="color:#a78bfa;margin-top:4px;font-size:.72rem;">📎 ${list.length} فیش ارسال شده</div>` : ''}
    `;
    openModal('admin-receipt-modal');
}

async function adminCompleteTopup() {
    const reqId = document.getElementById('complete-req-id').value;
    await adminCompleteTopupAction(reqId);
}

async function adminCompleteTopupFromCard(reqId) {
    await adminCompleteTopupAction(reqId);
}

async function adminCompleteTopupAction(reqId) {
    try {
        const res = await fetch(TOPUP_API + '?action=complete_topup', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ request_id: parseInt(reqId) })
        });
        const data = await res.json();
        if (data.success) {
            closeModal('admin-receipt-modal');
            const msg = data.amount && data.currency
                ? `✅ موجودی ${data.amount} ${data.currency} به کاربر اضافه شد`
                : 'Top-up تکمیل شد و موجودی کاربر افزایش یافت';
            showAdminToast(msg, 'success');
            closeModal('topup-detail-modal');
            adminLoadTopups(adminCurrentFilter);
        } else { showAdminToast(data.message || 'خطا', 'error'); }
    } catch(e) { showAdminToast('خطا در ارتباط', 'error'); }
}

// Auto-refresh topups every 60 seconds
setInterval(() => adminLoadTopups(adminCurrentFilter), 60000);

// ====================================================
// INVOICE MANAGEMENT (فیش‌های پرداخت نشده)
// ====================================================
const INV_API = 'api/unpaid_invoice_api.php';
const AX_DIRECTBUY_API = 'api/direct_buy_api.php';

// حذف یک فیش از لیست توسط ادمین
async function adminDeleteInvoice(invId) {
    if (!confirm('این فیش از لیست حذف شود؟ این عمل قابل بازگشت نیست.')) return;
    try {
        const res = await fetch(INV_API + '?action=admin_delete_invoice', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ invoice_id: parseInt(invId) })
        });
        const data = await res.json();
        if (data.success) {
            const card = document.getElementById('invoice-card-' + invId);
            if (card) card.remove();
            showAdminToast('فیش حذف شد', 'success');
            adminLoadInvoices(adminInvoiceFilter);
        } else {
            showAdminToast(data.message || 'خطا در حذف', 'error');
        }
    } catch (e) { showAdminToast('خطا در ارتباط', 'error'); }
}

function openCreateInvoice() {
    document.getElementById('inv_user_id').value = '';
    document.getElementById('inv_currency').value = 'USD';
    document.getElementById('inv_amount').value = '';
    document.getElementById('inv_description').value = '';
    ['inv_bank_name','inv_account_number','inv_card_number','inv_recipient_name','inv_iban']
        .forEach(function(id){ var el = document.getElementById(id); if (el) el.value = ''; });
    var invTypeEl = document.getElementById('inv_type');
    if (invTypeEl) invTypeEl.value = 'service';
    var srcTypeEl = document.getElementById('inv_source_type');
    if (srcTypeEl) srcTypeEl.value = 'general';
    window.__axInvoiceDealLink = null; // ریست پیوند به معامله؛ اگر از axOpenInvoiceForDealUser صدا زده شده، خودش بعداً ست می‌شود
    // (جدید) حساب‌های ذخیره‌شده: تعریف یک‌بار توسط ادمین، بعد با یک کلیک
    // پر کردن فیلدهای بانکی — همراه با قابلیت ویرایش/حذف هر حساب
    var savedBox = document.getElementById('createInvSavedAccBox');
    if (savedBox) {
        savedBox.innerHTML = axSavedAccPickerHtml('axSavedAcc_createinv', 'create_invoice');
        axSavedAccLoad('axSavedAcc_createinv');
    }
    openModal('create-invoice-modal');
}

document.getElementById('createInvoiceForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = this.querySelector('button[type=submit]');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';
    btn.disabled = true;
    try {
        const dealLink = window.__axInvoiceDealLink;
        const bankName = document.getElementById('inv_bank_name').value.trim();
        const accountNumber = document.getElementById('inv_account_number').value.trim();
        const cardNumber = document.getElementById('inv_card_number').value.trim();
        const recipientName = document.getElementById('inv_recipient_name').value.trim();
        const iban = document.getElementById('inv_iban').value.trim();
        const cardVal = cardNumber || iban || accountNumber;

        let res, data;
        if (dealLink && dealLink.deal_id && dealLink.deal_side) {
            // (اصلاح) وقتی این فیش برای یک طرفِ معامله‌ی «تبادل ارزی» است، به‌جای
            // مسیر عمومیِ create_invoice (که فقط unpaid_invoices را می‌سازد و
            // ستون‌های خودِ معامله در ad_deals را دست‌نخورده می‌گذارد و همین باعث
            // می‌شد کارتِ «معاملات در انتظار تسویه» شماره‌حساب را نبیند)، مستقیماً
            // admin_send_accounts در deal_settlement_api.php صدا زده می‌شود که هم
            // ad_deals و هم unpaid_invoices را هم‌زمان و هماهنگ به‌روز می‌کند.
            if (!cardVal) {
                showAdminToast('برای فیشِ معامله، حداقل شماره‌کارت/حساب/شبا را وارد کنید', 'error');
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> ایجاد فیش';
                btn.disabled = false;
                return;
            }
            res = await fetch('api/deal_settlement_api.php?action=admin_send_accounts', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    deal_id: dealLink.deal_id,
                    side: dealLink.deal_side,
                    accounts: [{ name: recipientName, bank: bankName, card: cardVal }],
                    payment_amount: document.getElementById('inv_amount').value,
                    payment_currency: document.getElementById('inv_currency').value
                })
            });
            data = await res.json();
        } else {
            res = await fetch(INV_API + '?action=create_invoice', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    user_id: document.getElementById('inv_user_id').value,
                    currency: document.getElementById('inv_currency').value,
                    amount: document.getElementById('inv_amount').value,
                    type: (document.getElementById('inv_type') ? document.getElementById('inv_type').value : 'other'),
                    source_type: (document.getElementById('inv_source_type') ? document.getElementById('inv_source_type').value : 'general'),
                    description: document.getElementById('inv_description').value,
                    bank_name: bankName,
                    account_number: accountNumber,
                    card_number: cardNumber,
                    recipient_name: recipientName,
                    iban: iban,
                    deal_id: null,
                    deal_side: null
                })
            });
            data = await res.json();
        }
        if (data.success) {
            closeModal('create-invoice-modal');
            showAdminToast(data.message || 'فیش با موفقیت ایجاد شد', 'success');
            adminLoadInvoices(adminInvoiceFilter);
            // اگر تب «معاملات در انتظار تسویه» هم باز است، آن را هم به‌روز کن
            if (document.getElementById('axExchangeInvoiceList')) {
                axLoadExchangeInvoices('', null);
            }
            // اگر تب «معاملات» هم باز است، کارتِ همین معامله را هم به‌روز کن
            if (dealLink && dealLink.deal_id && typeof axLoadDeals === 'function' && document.getElementById('axDealsList')) {
                axLoadDeals(document.querySelector('[data-dfilter].active')?.dataset.dfilter || 'pending', null);
            }
            // اگر این فیش از طریق «خرید مستقیم» باز شده بود، درخواست مربوطه را
            // به همین فیش وصل کن تا وضعیتش invoiced شود (بدون این پیوند، خودِ
            // ساخت فیش همچنان کامل کار می‌کند — این فقط برای هم‌گام‌سازی وضعیت است).
            if (window.axDirectBuyPendingLinkId && data.invoice_id) {
                fetch(AX_DIRECTBUY_API + '?action=admin_link_invoice', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: window.axDirectBuyPendingLinkId, invoice_id: data.invoice_id })
                }).then(()=>{ try { axLoadDirectBuy(document.querySelector('[data-dbfilter].active')?.dataset.dbfilter || 'pending'); } catch(e){} })
                  .catch(()=>{});
                window.axDirectBuyPendingLinkId = null;
            }
            // نمایش تب فیش‌ها (که چون سایدبار قبلی حذف شده، از طریق مودال «دسترسی سریع» باز می‌شود)
            axGoToTab('invoices');
        } else {
            showAdminToast(data.message || 'خطا', 'error');
        }
    } catch(e) {
        showAdminToast('خطا در ارتباط', 'error');
    }
    btn.innerHTML = '<i class="fas fa-paper-plane"></i> ایجاد فیش';
    btn.disabled = false;
});

/* ===== ارسال فیش مستقیم برای یک کاربر (بدون معامله/فاکتور) ===== */
const RCPT_API = 'api/admin_send_receipt_api.php';

function openSendReceipt() {
    document.getElementById('rcpt_user_id').value = '';
    document.getElementById('rcpt_files').value = '';
    document.getElementById('rcpt_files_picked').textContent = '';
    document.getElementById('rcpt_files_preview').innerHTML = '';
    document.getElementById('rcpt_note').value = '';
    openModal('send-receipt-modal');
}

document.getElementById('rcpt_files').addEventListener('change', function() {
    const files = this.files ? Array.prototype.slice.call(this.files) : [];
    document.getElementById('rcpt_files_picked').textContent = files.length ? (files.length + ' عکس انتخاب شد') : '';

    const preview = document.getElementById('rcpt_files_preview');
    preview.innerHTML = '';
    files.forEach(function(file) {
        if (!file.type || file.type.indexOf('image/') !== 0) return;
        const url = URL.createObjectURL(file);
        const img = document.createElement('img');
        img.src = url;
        img.style.cssText = 'width:64px;height:64px;object-fit:cover;border-radius:10px;border:1px solid rgba(255,255,255,.15);';
        img.onload = function(){ URL.revokeObjectURL(url); };
        preview.appendChild(img);
    });
});

document.getElementById('sendReceiptForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = this.querySelector('button[type=submit]');
    const userId = document.getElementById('rcpt_user_id').value;
    const files  = document.getElementById('rcpt_files').files;

    if (!userId) { showAdminToast('کاربر را انتخاب کنید', 'error'); return; }
    if (!files || !files.length) { showAdminToast('حداقل یک عکس انتخاب کنید', 'error'); return; }
    const nonImg = Array.prototype.some.call(files, function(f){ return !f.type || f.type.indexOf('image/') !== 0; });
    if (nonImg) { showAdminToast('فقط فایل عکس مجاز است', 'error'); return; }

    const orig = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال ارسال...';
    btn.disabled = true;
    try {
        const fd = new FormData();
        fd.append('action', 'send_receipt');
        fd.append('user_id', userId);
        fd.append('note', document.getElementById('rcpt_note').value.trim());
        Array.prototype.forEach.call(files, function(f){ fd.append('file[]', f, f.name || 'receipt.jpg'); });

        const res = await fetch(RCPT_API + '?action=send_receipt', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            closeModal('send-receipt-modal');
            showAdminToast(data.message || 'فیش ارسال شد', 'success');
        } else {
            showAdminToast(data.message || 'خطا', 'error');
        }
    } catch(err) {
        showAdminToast('خطا در ارتباط', 'error');
    }
    btn.innerHTML = orig;
    btn.disabled = false;
});

/* ===== تب مستقل «ارسال فیش»: لیست آخرین فیش‌های ارسالی ===== */
async function axLoadSentReceipts() {
    const box = document.getElementById('sentReceiptsList');
    if (!box) return;
    box.innerHTML = '<div class="ax-empty" style="padding:14px 0;"><i class="fas fa-spinner fa-spin"></i></div>';
    try {
        const res = await fetch(RCPT_API + '?action=list_receipts');
        const data = await res.json();
        if (!data.success || !data.receipts || !data.receipts.length) {
            box.innerHTML = '<div class="ax-empty" style="padding:14px 0;">هنوز فیشی ارسال نشده</div>';
            return;
        }
        box.innerHTML = data.receipts.map(function(r) {
            const name = ((r.first_name || '') + ' ' + (r.last_name || '')).trim() || ('کاربر #' + r.user_id);
            const when = r.uploaded_at ? new Date(r.uploaded_at.replace(' ', 'T')).toLocaleString('fa-IR') : '';
            const note = r.notes ? ('<div style="font-size:.68rem;color:#8ea0c9;margin-top:2px;">' + axEsc(r.notes) + '</div>') : '';
            const fileUrl = '/ledor/' + String(r.receipt_file || '').replace(/^\/?ledor\//, '').replace(/^\/+/, '');
            return '<div style="display:flex;align-items:center;gap:10px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:10px 12px;">'
                 + '<a href="' + fileUrl + '" target="_blank" style="flex:none;width:44px;height:44px;border-radius:9px;overflow:hidden;display:block;background:rgba(255,255,255,.06);">'
                 + '<img src="' + fileUrl + '" style="width:100%;height:100%;object-fit:cover;" loading="lazy" onerror="this.style.display=\'none\'">'
                 + '</a>'
                 + '<div style="flex:1;min-width:0;">'
                 + '<div style="font-size:.78rem;font-weight:700;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + axEsc(name) + '</div>'
                 + '<div style="font-size:.66rem;color:#8ea0c9;">' + when + '</div>'
                 + note
                 + '</div>'
                 + '</div>';
        }).join('');
    } catch (e) {
        box.innerHTML = '<div class="ax-empty" style="padding:14px 0;">خطا در بارگذاری</div>';
    }
}

/* ===== تب مستقل «درآمد ادمین»: به تفکیک ارز + نمودار + رکوردهای دستی ===== */
const REV_API = 'api/admin_revenue_api.php';
let axRevenueChart = null;
let revData = null;
let revActiveCur = 'IRR';

const REV_META = {
    IRR:  { label: 'تومان', sym: '﷼', grad: 'linear-gradient(135deg,#16A34A,#15803D)', line: '#22d3a0', glow: 'rgba(34,211,160,.35)' },
    USD:  { label: 'دلار',  sym: '$', grad: 'linear-gradient(135deg,#2563EB,#1D4ED8)', line: '#60A5FA', glow: 'rgba(96,165,250,.35)' },
    EUR:  { label: 'یورو',  sym: '€', grad: 'linear-gradient(135deg,#7C3AED,#6D28D9)', line: '#A78BFA', glow: 'rgba(167,139,250,.35)' },
    USDT: { label: 'تتر',   sym: '₮', grad: 'linear-gradient(135deg,#0EA5E9,#0369A1)', line: '#38BDF8', glow: 'rgba(56,189,248,.35)' },
};

function revFmt(n, cur) {
    const v = Number(n || 0);
    // تومان معمولاً رقم اعشار ندارد؛ ارزهای دیگر تا ۲ رقم
    const opts = (cur === 'IRR') ? { maximumFractionDigits: 0 } : { maximumFractionDigits: 2 };
    return v.toLocaleString('fa-IR', opts);
}

async function axLoadRevenue() {
    try {
        const res = await fetch(REV_API + '?action=get_revenue&months=6');
        const data = await res.json();
        if (!data.success) { showAdminToast(data.message || 'خطا در دریافت درآمد', 'error'); return; }
        revData = data;

        // ---- کارت‌های ارزی ----
        const wrap = document.getElementById('revCurCards');
        wrap.innerHTML = data.currencies.map(function(c) {
            const m = REV_META[c] || REV_META.IRR;
            const t = data.totals[c] || { commission: 0, debt: 0, manual: 0, total: 0 };
            return '<div class="rev-cur-card" style="--g:' + m.glow + '">'
                 + '<div style="position:absolute;inset-inline-start:-30px;top:-30px;width:110px;height:110px;border-radius:50%;background:' + m.glow + ';filter:blur(18px);"></div>'
                 + '<div class="rev-cur-head">'
                 + '<span class="rev-cur-badge" style="background:' + m.grad + '">' + m.sym + '</span>'
                 + '<span class="rev-cur-name">' + m.label + ' (' + c + ')</span>'
                 + '</div>'
                 + '<div class="rev-cur-total">' + revFmt(t.total, c) + '</div>'
                 + '<div class="rev-cur-break">'
                 + '<div class="rev-cur-row"><span>کمیسیون</span><b>' + revFmt(t.commission, c) + '</b></div>'
                 + '<div class="rev-cur-row"><span>بدهی کاربران</span><b>' + revFmt(t.debt, c) + '</b></div>'
                 + '<div class="rev-cur-row"><span>ثبت دستی</span><b>' + revFmt(t.manual, c) + '</b></div>'
                 + '</div></div>';
        }).join('');

        // ---- تب‌های انتخاب ارز نمودار ----
        const tabs = document.getElementById('revCurTabs');
        tabs.innerHTML = data.currencies.map(function(c) {
            const m = REV_META[c] || REV_META.IRR;
            return '<button type="button" class="rev-cur-tab' + (c === revActiveCur ? ' active' : '') + '" data-cur="' + c + '" onclick="revSelectCur(\'' + c + '\')">' + m.label + '</button>';
        }).join('');

        // ---- یادداشت معاملات قدیمی بدون کمیسیون ثبت‌شده ----
        const noteEl = document.getElementById('revChartMissingNote');
        if (data.missing_count > 0) {
            noteEl.style.display = '';
            noteEl.innerHTML = '<i class="fas fa-circle-info"></i> '
                + Number(data.missing_count).toLocaleString('fa-IR')
                + ' معامله‌ی تکمیل‌شده‌ی قدیمی‌تر، کمیسیونشان قبل از فعال‌شدن این گزارش ثبت نشده و در محاسبه نیامده است.';
        } else {
            noteEl.style.display = 'none';
        }

        revDrawChart();
        revLoadEntries();
    } catch (e) {
        showAdminToast('خطا در ارتباط با سرور درآمد', 'error');
    }
}

function revSelectCur(cur) {
    revActiveCur = cur;
    document.querySelectorAll('#revCurTabs .rev-cur-tab').forEach(function(b) {
        b.classList.toggle('active', b.dataset.cur === cur);
    });
    revDrawChart();
}

function revDrawChart() {
    if (!revData) return;
    const ctx = document.getElementById('revenueChart');
    if (!ctx || typeof Chart === 'undefined') return;
    if (axRevenueChart) axRevenueChart.destroy();

    const cur = revActiveCur;
    const m = REV_META[cur] || REV_META.IRR;
    const grad = ctx.getContext('2d').createLinearGradient(0, 0, 0, 260);
    grad.addColorStop(0, m.glow);
    grad.addColorStop(1, 'rgba(0,0,0,0)');

    axRevenueChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: revData.monthly_labels,
            datasets: [{
                label: 'درآمد ' + m.label,
                data: revData.monthly[cur] || [],
                borderColor: m.line,
                backgroundColor: grad,
                borderWidth: 2.5,
                pointBackgroundColor: m.line,
                pointBorderColor: '#0b1220',
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 7,
                tension: 0.35,
                fill: true,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { intersect: false, mode: 'index' },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(20,10,40,.95)',
                    borderColor: 'rgba(255,255,255,.12)',
                    borderWidth: 1,
                    padding: 10,
                    callbacks: {
                        label: function(c) { return revFmt(c.parsed.y, cur) + ' ' + cur; }
                    }
                }
            },
            scales: {
                x: { ticks: { color: 'rgba(255,255,255,.55)', font: { size: 10 } }, grid: { color: 'rgba(255,255,255,.06)' } },
                y: {
                    ticks: { color: 'rgba(255,255,255,.55)', font: { size: 10 }, callback: function(v) { return revFmt(v, cur); } },
                    grid: { color: 'rgba(255,255,255,.06)' }
                }
            }
        }
    });
}

/* ---- رکوردهای درآمد دستی: فهرست / افزودن / ویرایش / حذف ---- */
async function revLoadEntries() {
    const box = document.getElementById('revEntriesList');
    if (!box) return;
    box.innerHTML = '<div class="ax-empty" style="padding:12px 0;"><i class="fas fa-spinner fa-spin"></i></div>';
    try {
        const res = await fetch(REV_API + '?action=list_entries');
        const data = await res.json();
        if (!data.success || !data.entries || !data.entries.length) {
            box.innerHTML = '<div class="ax-empty" style="padding:12px 0;">رکورد دستی ثبت نشده</div>';
            return;
        }
        box.innerHTML = data.entries.map(function(e) {
            const m = REV_META[e.currency] || REV_META.IRR;
            const note = e.note ? axEsc(e.note) : '<span style="opacity:.5">بدون توضیح</span>';
            return '<div class="rev-entry-row">'
                 + '<span class="rev-cur-badge" style="background:' + m.grad + ';flex:none;">' + m.sym + '</span>'
                 + '<div class="rev-entry-meta">'
                 + '<div style="font-size:.74rem;color:#fff;font-weight:700;">' + note + '</div>'
                 + '<div style="font-size:.64rem;color:#8ea0c9;margin-top:2px;">' + axEsc(e.entry_date || '') + '</div>'
                 + '</div>'
                 + '<div class="rev-entry-amt">' + revFmt(e.amount, e.currency) + ' ' + axEsc(e.currency) + '</div>'
                 + '<div class="rev-entry-actions">'
                 + '<button title="ویرایش" onclick=\'revOpenEntry(' + JSON.stringify(e).replace(/'/g, "&#39;") + ')\'><i class="fas fa-pen"></i></button>'
                 + '<button title="حذف" onclick="revDeleteEntry(' + Number(e.id) + ')"><i class="fas fa-trash"></i></button>'
                 + '</div></div>';
        }).join('');
    } catch (e) {
        box.innerHTML = '<div class="ax-empty" style="padding:12px 0;">خطا در بارگذاری</div>';
    }
}

function revOpenEntry(entry) {
    document.getElementById('revEntryTitle').textContent = entry ? 'ویرایش درآمد دستی' : 'افزودن درآمد دستی';
    document.getElementById('rev_entry_id').value = entry ? entry.id : '';
    document.getElementById('rev_currency').value = entry ? (entry.currency || 'IRR') : 'IRR';
    document.getElementById('rev_amount').value   = entry ? entry.amount : '';
    document.getElementById('rev_note').value     = entry ? (entry.note || '') : '';
    document.getElementById('rev_date').value     = entry ? (entry.entry_date || '') : new Date().toISOString().slice(0, 10);
    openModal('revenue-entry-modal');
}

async function revDeleteEntry(id) {
    if (!confirm('این رکورد درآمد حذف شود؟')) return;
    try {
        const res = await fetch(REV_API + '?action=delete_entry', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id })
        });
        const data = await res.json();
        if (data.success) { showAdminToast(data.message || 'حذف شد', 'success'); axLoadRevenue(); }
        else showAdminToast(data.message || 'خطا', 'error');
    } catch (e) { showAdminToast('خطا در ارتباط', 'error'); }
}

document.getElementById('revenueEntryForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = this.querySelector('button[type=submit]');
    const id  = document.getElementById('rev_entry_id').value;
    const payload = {
        id: id || undefined,
        currency:   document.getElementById('rev_currency').value,
        amount:     parseFloat(document.getElementById('rev_amount').value),
        note:       document.getElementById('rev_note').value.trim(),
        entry_date: document.getElementById('rev_date').value
    };
    if (!payload.amount) { showAdminToast('مبلغ را وارد کنید', 'error'); return; }

    const orig = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ذخیره...';
    btn.disabled = true;
    try {
        const act = id ? 'update_entry' : 'add_entry';
        const res = await fetch(REV_API + '?action=' + act, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.success) {
            closeModal('revenue-entry-modal');
            showAdminToast(data.message || 'ذخیره شد', 'success');
            axLoadRevenue();
        } else showAdminToast(data.message || 'خطا', 'error');
    } catch (err) { showAdminToast('خطا در ارتباط', 'error'); }
    btn.innerHTML = orig;
    btn.disabled = false;
});

let invoiceCache = {};

async function adminLoadInvoices(status, tabEl) {
    adminInvoiceFilter = status;
    if (tabEl) {
        document.querySelectorAll('#tab-invoices .btn-sm').forEach(b => b.style.background = '');
        tabEl.style.background = 'linear-gradient(135deg,#6C40C5,#FF4D8D)';
        tabEl.style.color = '#fff';
        tabEl.style.border = 'none';
    }
    const list = document.getElementById('admin-invoice-list');
    list.innerHTML = '<div class="skeleton" style="height:56px;"></div><div class="skeleton" style="height:56px;margin-top:8px;"></div>';
    try {
        const url = INV_API + '?action=admin_get_invoices' + (status ? '&status=' + status : '');
        const res = await fetch(url);
        const data = await res.json();
        if (!data.success || !data.invoices.length) {
            list.innerHTML = '<div class="empty-state"><i class="fas fa-inbox"></i>هیچ فیشی پیدا نشد</div>';
            return;
        }
        invoiceCache = {};
        list.innerHTML = data.invoices.map(inv => { invoiceCache[inv.id] = inv; return buildInvoiceRow(inv); }).join('');

        // Update badge
        const pending = data.invoices.filter(i => i.status === 'pending').length;
        const approved = data.invoices.filter(i => i.status === 'approved').length;
        const paid = data.invoices.filter(i => i.status === 'paid').length;
        const invBadgeEl = document.getElementById('invoiceBadge');
        if (invBadgeEl) {
            const total = pending + approved + paid;
            invBadgeEl.textContent = total > 9 ? '9+' : total;
            invBadgeEl.style.display = total > 0 ? '' : 'none';
        }
    } catch(e) {
        list.innerHTML = '<div class="empty-state" style="color:#ff4d6d;"><i class="fas fa-exclamation-triangle"></i>خطا در بارگذاری</div>';
    }
}

// Compact one-line row for the invoice list
/* (جدید) گالریِ عکسِ فیش‌های آپلودی — همه‌ی صورتحساب‌هایی که کاربر برایشان
   رسیدِ پرداخت فرستاده (status='paid'، یعنی در انتظار تأیید ادمین) را به‌شکل
   یک ردیف عکسِ کوچک نشان می‌دهد، بدون نیاز به باز کردن تک‌تک فیش‌ها. */
async function axLoadReceiptGallery(){
    const box = document.getElementById('axReceiptGallery');
    if (!box) return;
    box.innerHTML = '<div class="skeleton" style="height:70px;"></div>';
    try {
        const res = await fetch(INV_API + '?action=admin_get_invoices&status=paid', { cache:'no-store' });
        const data = await res.json();
        const list = (data.success && data.invoices) ? data.invoices : [];
        if (!list.length){ box.innerHTML = '<div class="ax-empty">فعلاً عکسی در انتظار بررسی نیست</div>'; return; }
        let html = '';
        list.forEach(inv => {
            invoiceCache[inv.id] = inv;
            if (!inv.receipt_image) return;
            String(inv.receipt_image).split(',').forEach(p => {
                const src = p.trim();
                if (!src) return;
                html += `<div class="ax-receipt-thumb" onclick="openInvoiceRowDetail(${inv.id})" title="فیش #${inv.id} — ${axEsc((inv.first_name||'')+' '+(inv.last_name||''))}">
                    <img src="${src}" loading="lazy">
                    <span class="ax-receipt-thumb-id">#${inv.id}</span>
                </div>`;
            });
        });
        box.innerHTML = html || '<div class="ax-empty">فعلاً عکسی در انتظار بررسی نیست</div>';
    } catch(e){ box.innerHTML = '<div class="ax-empty">خطا در ارتباط</div>'; }
}

function buildInvoiceRow(inv) {
    const initials = (((inv.first_name||'')[0]||'') + ((inv.last_name||'')[0]||'')).toUpperCase() || '?';
    const dec = inv.currency === 'IRR' ? 0 : 2;
    const needsAction = (inv.status === 'pending' || inv.status === 'paid');
    return `
        <div class="admin-row" onclick="openInvoiceRowDetail(${inv.id})">
            <div class="admin-row-main">
                <div class="admin-row-avatar">${initials}</div>
                <div class="admin-row-txt">
                    <div class="admin-row-title">#${inv.id} — ${(inv.first_name||'')} ${(inv.last_name||'')}</div>
                    <div class="admin-row-sub">${formatDate(inv.created_at)} · <span class="${statusClass(inv.status)}">${statusLabel(inv.status)}</span></div>
                </div>
            </div>
            <div class="admin-row-right">
                <span class="admin-row-amount ${inv.currency}">${Number(inv.amount).toFixed(dec)} ${inv.currency}</span>
                ${needsAction ? '<span class="admin-row-new">NEW</span>' : '<i class="fas fa-chevron-left admin-row-chev"></i>'}
            </div>
        </div>`;
}

function openInvoiceRowDetail(id) {
    const inv = invoiceCache[id];
    if (!inv) return;
    document.getElementById('invoice-detail-body').innerHTML = buildInvoiceCard(inv);
    openModal('invoice-detail-modal');
}

function buildInvoiceCard(inv) {
    const initials = ((inv.first_name||'')[0]||'') + ((inv.last_name||'')[0]||'');
    const pillClass = 'pill-' + inv.status;
    const pillLabel = statusLabel(inv.status);
    const curClass = inv.currency;

    let actions = '';
    if (inv.status === 'pending') {
        actions = `
            <button class="btn-admin btn-admin-approve" onclick="openApproveInvoice(${inv.id})">
                <i class="fas fa-check"></i> تأیید و ارسال اطلاعات
            </button>
            <button class="btn-admin btn-admin-reject" onclick="openRejectInvoice(${inv.id})">
                <i class="fas fa-times"></i> رد
            </button>
        `;
    } else if (inv.status === 'approved') {
        actions = `
            <button class="btn-admin btn-admin-view-receipt" onclick="viewInvoiceDetail(${inv.id})">
                <i class="fas fa-eye"></i> مشاهده
            </button>
        `;
    } else if (inv.status === 'paid') {
        actions = `
            <button class="btn-admin btn-admin-view-receipt" onclick="viewInvoiceDetail(${inv.id})">
                <i class="fas fa-eye"></i> مشاهده فیش کاربر
            </button>
            <button class="btn-admin btn-admin-finalize" onclick="openFinalizeInvoice(${inv.id})">
                <i class="fas fa-star"></i> تکمیل فیش
            </button>
        `;
    } else if (inv.status === 'finalized') {
        actions = `
            <button class="btn-admin btn-admin-view-receipt" onclick="viewInvoiceDetail(${inv.id})">
                <i class="fas fa-eye"></i> مشاهده
            </button>
        `;
    } else if (inv.status === 'rejected') {
        actions = `
            <button class="btn-admin btn-admin-view-receipt" onclick="viewInvoiceDetail(${inv.id})">
                <i class="fas fa-eye"></i> مشاهده
            </button>
        `;
    }

    return `
        <div class="topup-request-card" id="invoice-card-${inv.id}">
            <div class="req-card-header">
                <div class="req-user-info">
                    <div class="req-avatar">${initials.toUpperCase() || '?'}</div>
                    <div>
                        <div class="req-user-name">${inv.first_name || ''} ${inv.last_name || ''}</div>
                        <div class="req-user-sub">${inv.description || 'بدون توضیحات'}</div>
                    </div>
                </div>
                <div>
                    <div class="req-amount-badge ${curClass}">${Number(inv.amount).toFixed(inv.currency === 'IRR' ? 0 : 2)} ${inv.currency}</div>
                </div>
            </div>
            <div class="req-card-body">
                <div class="req-meta">
                    <div class="req-meta-item"><strong>#${inv.id}</strong></div>
                    <div class="req-meta-item">${formatDate(inv.created_at)}</div>
                    <div class="req-meta-item"><span class="${statusClass(inv.status)}">${pillLabel}</span></div>
                </div>
                ${inv.reject_reason ? `<div style="font-size:.72rem;color:#ff4d6d;margin-top:4px;">دلیل رد: ${inv.reject_reason}</div>` : ''}
                ${inv.receipt_image ? String(inv.receipt_image).split(',').map(p => `<img src="${p.trim()}" class="receipt-thumb" onclick="event.stopPropagation();axOpenImgLightbox('${p.trim()}')" title="مشاهده تمام‌صفحه فیش">`).join('') : ''}
                ${inv.admin_final_image ? `<img src="${inv.admin_final_image}" class="receipt-thumb" onclick="event.stopPropagation();axOpenImgLightbox('${inv.admin_final_image}')" title="تصویر نهایی ادمین">` : ''}
            </div>
            ${actions ? `<div class="admin-action-row">${actions}</div>` : ''}
            <div class="admin-action-row" style="margin-top:6px;">
                <button class="btn-admin" onclick="adminDeleteInvoice(${inv.id})" style="background:rgba(255,59,48,0.12);border:1px solid rgba(255,59,48,0.4);color:#ff6b6b;">
                    <i class="fas fa-trash"></i> حذف از لیست
                </button>
            </div>
        </div>
    `;
}

// ===== Approve Invoice =====
function openApproveInvoice(id) {
    document.getElementById('approve-inv-id').value = id;
    document.getElementById('inv-approve-bank').value = '';
    document.getElementById('inv-approve-account').value = '';
    document.getElementById('inv-approve-card').value = '';
    document.getElementById('inv-approve-recipient').value = '';
    document.getElementById('inv-approve-iban').value = '';
    document.getElementById('approveInvSavedAccBox').innerHTML = axSavedAccPickerHtml('axSavedAcc_invoice', 'invoice');
    axSavedAccLoad('axSavedAcc_invoice');
    openModal('approve-invoice-modal');
}

async function adminApproveInvoice() {
    const id = document.getElementById('approve-inv-id').value;
    const bank = document.getElementById('inv-approve-bank').value.trim();
    const acct = document.getElementById('inv-approve-account').value.trim();
    const card = document.getElementById('inv-approve-card').value.trim();
    const recip = document.getElementById('inv-approve-recipient').value.trim();
    const iban = document.getElementById('inv-approve-iban').value.trim();

    if (!bank || !acct || !card || !recip) { showAdminToast('لطفاً اطلاعات الزامی را وارد کنید', 'error'); return; }

    try {
        const res = await fetch(INV_API + '?action=approve_invoice', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                invoice_id: parseInt(id),
                bank_name: bank,
                account_number: acct,
                card_number: card,
                recipient_name: recip,
                iban: iban
            })
        });
        const data = await res.json();
        if (data.success) {
            closeModal('approve-invoice-modal');
            showAdminToast('فیش تأیید شد و اطلاعات به کاربر ارسال گردید', 'success');
            closeModal('invoice-detail-modal');
            adminLoadInvoices(adminInvoiceFilter);
        } else {
            showAdminToast(data.message || 'خطا', 'error');
        }
    } catch(e) {
        showAdminToast('خطا در ارتباط', 'error');
    }
}

// ===== Reject Invoice =====
function openRejectInvoice(id) {
    document.getElementById('reject-inv-id').value = id;
    document.getElementById('reject-inv-reason').value = '';
    openModal('reject-invoice-modal');
}

async function adminRejectInvoice() {
    const id = document.getElementById('reject-inv-id').value;
    const reason = document.getElementById('reject-inv-reason').value.trim();
    if (!reason) { showAdminToast('لطفاً دلیل رد را وارد کنید', 'error'); return; }

    try {
        const res = await fetch(INV_API + '?action=reject_invoice', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ invoice_id: parseInt(id), reason })
        });
        const data = await res.json();
        if (data.success) {
            closeModal('reject-invoice-modal');
            showAdminToast('فیش رد شد', 'success');
            closeModal('invoice-detail-modal');
            adminLoadInvoices(adminInvoiceFilter);
            axLoadReceiptGallery();
        } else {
            showAdminToast(data.message || 'خطا', 'error');
        }
    } catch(e) {
        showAdminToast('خطا در ارتباط', 'error');
    }
}

// ===== Finalize Invoice =====
function openFinalizeInvoice(id) {
    document.getElementById('finalize-inv-id').value = id;
    document.getElementById('finalize-note').value = '';
    document.getElementById('final-inv-image-url').value = '';
    document.getElementById('final-inv-preview').style.display = 'none';
    document.getElementById('final-inv-label').textContent = 'آپلود تصویر نهایی';
    openModal('finalize-invoice-modal');
}

function previewFinalInvoiceImage(input) {
    if (!input.files[0]) return;
    const file = input.files[0];
    const reader = new FileReader();
    reader.onload = function(e) {
        const preview = document.getElementById('final-inv-preview');
        preview.src = e.target.result;
        preview.style.display = 'block';
        document.getElementById('final-inv-label').textContent = file.name;
        uploadFinalInvoiceImage(file);
    };
    reader.readAsDataURL(file);
}

async function uploadFinalInvoiceImage(file) {
    const formData = new FormData();
    formData.append('final_image', file);
    if (typeof window.avaShowUploadProgress === 'function') window.avaShowUploadProgress('در حال آپلود تصویر...');
    try {
        const data = (typeof window.avaUploadWithProgress === 'function')
            ? await window.avaUploadWithProgress(INV_API + '?action=upload_final_image', formData)
            : await (await fetch(INV_API + '?action=upload_final_image', { method: 'POST', body: formData })).json();
        if (data.success) {
            if (typeof window.avaHideUploadProgress === 'function') window.avaHideUploadProgress(true, 'آپلود شد ✅');
            document.getElementById('final-inv-image-url').value = data.url;
        } else {
            if (typeof window.avaHideUploadProgress === 'function') window.avaHideUploadProgress(false);
            showAdminToast('آپلود تصویر ناموفق', 'error');
        }
    } catch(e) {
        if (typeof window.avaHideUploadProgress === 'function') window.avaHideUploadProgress(false);
        showAdminToast('خطا در آپلود', 'error');
    }
}

async function adminFinalizeInvoice() {
    const id = document.getElementById('finalize-inv-id').value;
    const note = document.getElementById('finalize-note').value.trim();
    const image = document.getElementById('final-inv-image-url').value || null;

    try {
        const res = await fetch(INV_API + '?action=finalize_invoice', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                invoice_id: parseInt(id),
                final_note: note,
                final_image: image
            })
        });
        const data = await res.json();
        if (data.success) {
            closeModal('finalize-invoice-modal');
            showAdminToast('فیش تکمیل شد و موجودی کاربر افزایش یافت', 'success');
            closeModal('invoice-detail-modal');
            adminLoadInvoices(adminInvoiceFilter);
            axLoadReceiptGallery();
        } else {
            showAdminToast(data.message || 'خطا', 'error');
        }
    } catch(e) {
        showAdminToast('خطا در ارتباط', 'error');
    }
}

// ===== View Invoice Detail =====
async function viewInvoiceDetail(id) {
    try {
        const res = await fetch(INV_API + '?action=get_invoice&id=' + id + '&admin=1');
        const data = await res.json();
        if (!data.success || !data.invoice) {
            showAdminToast('فیش یافت نشد', 'error');
            return;
        }
        const inv = data.invoice;
        let paymentInfo = '';
        if (inv.status === 'approved' || inv.status === 'paid' || inv.status === 'finalized') {
            paymentInfo = `
                <div class="detail-grid" style="grid-template-columns:1fr;background:rgba(255,255,255,0.03);border-radius:8px;padding:10px;margin-top:6px;">
                    ${inv.bank_name ? `<div><span class="label">بانک:</span> <span class="value">${inv.bank_name}</span></div>` : ''}
                    ${inv.account_number ? `<div><span class="label">شماره حساب:</span> <span class="value highlight">${inv.account_number}</span></div>` : ''}
                    ${inv.card_number ? `<div><span class="label">شماره کارت:</span> <span class="value highlight">${inv.card_number}</span></div>` : ''}
                    ${inv.iban ? `<div><span class="label">شبا:</span> <span class="value highlight">${inv.iban}</span></div>` : ''}
                    ${inv.recipient_name ? `<div><span class="label">نام گیرنده:</span> <span class="value">${inv.recipient_name}</span></div>` : ''}
                </div>
            `;
        }
        let finalNote = '';
        if (inv.admin_final_note || inv.admin_final_image) {
            finalNote = `
                <div style="background:rgba(34,211,160,0.08);border:1px solid rgba(34,211,160,0.2);border-radius:10px;padding:12px;margin-top:10px;">
                    <span style="color:#22d3a0;font-weight:600;">یادداشت ادمین:</span>
                    <p style="font-size:.75rem;color:rgba(255,255,255,0.7);margin-top:4px;">${inv.admin_final_note || ''}</p>
                    ${inv.admin_final_image ? `<img src="${inv.admin_final_image}" onclick="axOpenImgLightbox('${inv.admin_final_image}')" style="width:100%;max-height:200px;object-fit:cover;border-radius:8px;margin-top:8px;cursor:pointer;">` : ''}
                </div>
            `;
        }
        let detailActions = '';
        if (inv.status === 'pending') {
            detailActions = `
                <button class="btn-admin btn-admin-approve" onclick="openApproveInvoice(${inv.id})"><i class="fas fa-check"></i> تأیید و ارسال اطلاعات</button>
                <button class="btn-admin btn-admin-reject" onclick="openRejectInvoice(${inv.id})"><i class="fas fa-times"></i> رد</button>`;
        } else if (inv.status === 'paid') {
            detailActions = `<button class="btn-admin btn-admin-finalize" onclick="openFinalizeInvoice(${inv.id})"><i class="fas fa-star"></i> تکمیل فیش</button>`;
        }
        document.getElementById('invoice-detail-body').innerHTML = `
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                <span style="font-size:1.05rem;font-weight:700;color:${inv.currency === 'USD' ? '#4ade80' : inv.currency === 'EUR' ? '#38bdf8' : inv.currency === 'USDT' ? '#f0b429' : 'rgba(255,255,255,0.7)'};">${Number(inv.amount).toFixed(inv.currency === 'IRR' ? 0 : 2)} ${inv.currency}</span>
                <span class="${statusClass(inv.status)}">${statusLabel(inv.status)}</span>
            </div>
            <div class="detail-grid">
                <div><span class="label">شناسه:</span> <span class="value">#${inv.id}</span></div>
                <div><span class="label">کاربر:</span> <span class="value">${inv.first_name || ''} ${inv.last_name || ''}</span></div>
                <div><span class="label">تاریخ:</span> <span class="value">${formatDate(inv.created_at)}</span></div>
                <div><span class="label">توضیحات:</span> <span class="value">${inv.description || '-'}</span></div>
                ${inv.reject_reason ? `<div style="grid-column:1/-1;"><span class="label">دلیل رد:</span> <span class="value" style="color:#ff4d6d;">${inv.reject_reason}</span></div>` : ''}
            </div>
            ${paymentInfo}
            ${inv.receipt_image ? String(inv.receipt_image).split(',').map(p => `<img src="${p.trim()}" onclick="axOpenImgLightbox('${p.trim()}')" style="width:100%;border-radius:10px;max-height:180px;object-fit:cover;margin-top:10px;cursor:pointer;">`).join('') : ''}
            ${finalNote}
            ${detailActions ? `<div class="admin-action-row" style="margin-top:12px;">${detailActions}</div>` : ''}
        `;
        openModal('invoice-detail-modal');
    } catch(e) {
        showAdminToast('خطا در بارگذاری', 'error');
    }
}

// Auto-refresh invoices every 60 seconds
setInterval(() => adminLoadInvoices(adminInvoiceFilter), 60000);

// ====================================================
// USER BALANCE MANAGEMENT
// ====================================================
async function loadUserData(userId) {
    if (!userId) {
        document.getElementById('userBalanceGrid').classList.remove('show');
        document.getElementById('userTransactions').classList.remove('show');
        return;
    }
    currentUserId = userId;
    try {
        let res = await fetch(`?ajax_action=get_balances&user_id=${userId}`);
        let data = await res.json();
        if (data.success) {
            currentBalances = data.balances;
            document.getElementById('bal_usd').innerHTML = data.balances.USD.toFixed(2) + ' USD';
            document.getElementById('bal_eur').innerHTML = data.balances.EUR.toFixed(2) + ' EUR';
            document.getElementById('bal_usdt').innerHTML = data.balances.USDT.toFixed(6) + ' USDT';
            document.getElementById('bal_irr').innerHTML = data.balances.IRR.toFixed(2) + ' IRR';
            document.getElementById('userBalanceGrid').classList.add('show');
            updatePreview();
        }
    } catch(e) { console.error(e); }
    try {
        let res = await fetch(`?ajax_action=get_transactions&user_id=${userId}&limit=20`);
        let data = await res.json();
        if (data.success && data.transactions) {
            displayTransactions(data.transactions);
            document.getElementById('userTransactions').classList.add('show');
        }
    } catch(e) { console.error(e); }
}

function displayTransactions(transactions) {
    let html = '';
    transactions.forEach(tx => {
        let typeDisplay = tx.type;
        let amountPrefix = '';
        let color = '#4CD964';
        if (tx.sender_id == currentUserId) {
            amountPrefix = '-';
            typeDisplay = 'sent';
            color = '#FF3B30';
        } else if (tx.receiver_id == currentUserId) {
            amountPrefix = '+';
            typeDisplay = 'received';
            color = '#4CD964';
        } else if (tx.type == 'deposit' || tx.type == 'admin_credit') {
            amountPrefix = '+';
            color = '#FFC107';
        } else {
            amountPrefix = '-';
            color = '#FF6B6B';
        }
        html += `<tr>
            <td>${formatDate(tx.created_at)}</td>
            <td style="color:${color}">${typeDisplay}</td>
            <td>${amountPrefix} ${parseFloat(tx.amount).toFixed(2)}</td>
            <td>${tx.currency}</td>
            <td style="max-width:150px; overflow:hidden; text-overflow:ellipsis;">${escapeHtml(tx.description || '-')}</td>
        </tr>`;
    });
    document.getElementById('transactionsList').innerHTML = html;
}

function setOp(op) {
    document.getElementById('operationInput').value = op;
    let add = document.querySelector('.operation-btn.add');
    let sub = document.querySelector('.operation-btn.subtract');
    let negGroup = document.getElementById('allowNegativeGroup');
    if (op === 'increase') {
        add.classList.add('active'); sub.classList.remove('active');
        negGroup.style.display = 'none';
        document.getElementById('previewOp').innerHTML = 'Increase';
    } else {
        sub.classList.add('active'); add.classList.remove('active');
        negGroup.style.display = 'flex';
        document.getElementById('previewOp').innerHTML = 'Decrease';
    }
    updatePreview();
}

function updatePreview() {
    let userId = document.getElementById('userSelect').value;
    let currency = document.getElementById('currencySelect').value;
    let amount = parseFloat(document.getElementById('amountInput').value) || 0;
    let op = document.getElementById('operationInput').value;
    let allowNeg = document.getElementById('allowNegative')?.checked || false;
    if (!userId || amount <= 0 || !currentBalances[currency]) {
        document.getElementById('balancePreview').classList.remove('show');
        return;
    }
    let current = currentBalances[currency];
    let newBal = op === 'increase' ? current + amount : current - amount;
    let warningRow = document.getElementById('warningRow');
    if (op === 'decrease' && newBal < 0 && !allowNeg) {
        warningRow.style.display = 'flex';
        document.getElementById('warningMsg').innerHTML = `Balance will become NEGATIVE (${newBal.toFixed(2)}). Enable "Allow negative" to proceed.`;
    } else if (op === 'decrease' && newBal < 0 && allowNeg) {
        warningRow.style.display = 'flex';
        document.getElementById('warningMsg').innerHTML = `Balance will become NEGATIVE (${newBal.toFixed(2)}). This is allowed.`;
        document.getElementById('warningMsg').style.color = '#FFC107';
    } else {
        warningRow.style.display = 'none';
    }
    let decimals = currency === 'USDT' ? 6 : 2;
    document.getElementById('currentBalance').innerHTML = current.toFixed(decimals);
    document.getElementById('previewAmount').innerHTML = amount.toFixed(decimals) + ' ' + currency;
    document.getElementById('newBalance').innerHTML = newBal.toFixed(decimals);
    document.getElementById('balancePreview').classList.add('show');
}

document.getElementById('userSelect').addEventListener('change', (e) => loadUserData(e.target.value));
document.getElementById('currencySelect').addEventListener('change', updatePreview);
document.getElementById('amountInput').addEventListener('input', updatePreview);
document.getElementById('allowNegative').addEventListener('change', updatePreview);
setOp('increase');

// ====================================================
// CHAT FUNCTIONS
// ====================================================
document.getElementById('chatUserSelect').addEventListener('change', async function() {
    let userId = this.value;
    if (!userId) {
        document.getElementById('chatContainer').style.display = 'none';
        document.getElementById('chatForm').style.display = 'none';
        if (chatPolling) clearInterval(chatPolling);
        return;
    }
    document.getElementById('chatUserId').value = userId;
    document.getElementById('chatContainer').style.display = 'block';
    document.getElementById('chatForm').style.display = 'block';
    lastChatId = 0;
    await loadChatMessages(userId);
    if (chatPolling) clearInterval(chatPolling);
    chatPolling = setInterval(() => loadChatMessages(userId, true), 3000);
});

// ==================== نشانگر پیام‌های خوانده‌نشده در لیست کاربران چت ====================
async function refreshChatUnreadBadges() {
    try {
        const res = await fetch('?ajax_action=get_unread_chat_counts');
        const data = await res.json();
        if (!data.success) return;
        const counts = data.counts || {};
        let total = 0;
        const select = document.getElementById('chatUserSelect');
        if (select) {
            Array.from(select.options).forEach(opt => {
                if (!opt.value) return;
                const cnt = counts[opt.value] || 0;
                total += cnt;
                const baseName = (opt.textContent || '').replace(/^🔴 \(\d+\)\s*|^💬\s*/, '');
                opt.textContent = (cnt > 0 ? `🔴 (${cnt}) ` : '💬 ') + baseName;
                opt.setAttribute('data-unread', cnt);
            });
        }
        const badge = document.getElementById('chatUnreadTotalBadge');
        if (badge) {
            badge.textContent = total + ' unread';
            badge.style.display = total > 0 ? 'inline-flex' : 'none';
        }
    } catch(e) { /* بی‌صدا */ }
}
// هر ۱۵ ثانیه یک‌بار بررسی کن (برای پیام‌های جدیدِ کاربران دیگر هنگام باز بودن این تب)
setInterval(refreshChatUnreadBadges, 15000);
setTimeout(refreshChatUnreadBadges, 1500);

// ==================== مسدودسازی کاربر ====================
async function loadBanStatus() {
    const userId = document.getElementById('banUserSelect').value;
    const box = document.getElementById('banStatusBox');
    if (!userId) { box.style.display = 'none'; return; }
    try {
        const res = await fetch(`?ajax_action=get_ban_status&user_id=${userId}`);
        const data = await res.json();
        if (!data.success) return;
        box.style.display = 'block';
        const statusText = document.getElementById('banStatusText');
        const reasonText = document.getElementById('banReasonText');
        if (data.is_banned) {
            statusText.innerHTML = '<i class="fas fa-ban" style="color:#FF3B30;"></i> این کاربر در حال حاضر مسدود است';
            statusText.style.color = '#FF3B30';
            reasonText.textContent = data.ban_reason ? ('دلیل: ' + data.ban_reason) : '';
        } else {
            statusText.innerHTML = '<i class="fas fa-check-circle" style="color:#4CD964;"></i> این کاربر مسدود نیست';
            statusText.style.color = '#4CD964';
            reasonText.textContent = '';
        }
    } catch(e) { /* بی‌صدا */ }
}

async function toggleBanUser(ban) {
    const userId = document.getElementById('banUserSelect').value;
    if (!userId) { alert('لطفاً ابتدا یک کاربر انتخاب کنید'); return; }
    if (ban && !confirm('آیا از مسدودسازی این کاربر از ارسال هر نوع درخواست اطمینان دارید؟')) return;
    const reason = document.getElementById('banReasonInput').value.trim();
    try {
        const res = await fetch('?ajax_action=toggle_ban', {
            method: 'POST',
            body: new URLSearchParams({ user_id: userId, ban: ban ? '1' : '0', reason })
        });
        const data = await res.json();
        alert(data.message || (data.success ? 'انجام شد' : 'خطا'));
        if (data.success) {
            loadBanStatus();
            loadBannedUsersList();
            document.getElementById('banReasonInput').value = '';
        }
    } catch(e) { alert('خطا در ارتباط با سرور'); }
}

async function loadBannedUsersList() {
    const list = document.getElementById('bannedUsersList');
    if (!list) return;
    try {
        const res = await fetch('?ajax_action=get_banned_users');
        const data = await res.json();
        const badge = document.getElementById('bannedCountBadge');
        const count = (data.success && data.users) ? data.users.length : 0;
        if (badge) {
            badge.textContent = count;
            badge.style.display = count > 0 ? 'inline-block' : 'none';
        }
        if (!data.success || !data.users || data.users.length === 0) {
            list.innerHTML = '<div style="text-align:center;color:#5a5475;font-size:.72rem;padding:10px;">کاربر مسدودی وجود ندارد</div>';
            return;
        }
        list.innerHTML = data.users.map(u => `
            <div style="display:flex;align-items:center;gap:8px;background:rgba(255,59,48,0.08);border:1px solid rgba(255,59,48,0.25);border-radius:10px;padding:8px 10px;">
                <div style="flex:1;min-width:0;">
                    <div style="font-size:.74rem;font-weight:700;color:#fff;">${escapeHtml((u.first_name||'')+' '+(u.last_name||''))} <span style="color:#B8B8D1;font-weight:400;">(@${escapeHtml(u.telegram_id||'')})</span></div>
                    ${u.ban_reason ? `<div style="font-size:.66rem;color:#B8B8D1;margin-top:2px;">${escapeHtml(u.ban_reason)}</div>` : ''}
                </div>
                <button type="button" class="btn-sm" style="background:rgba(76,217,100,0.15);border:1px solid #4CD964;color:#4CD964;padding:5px 10px;" onclick="document.getElementById('banUserSelect').value='${u.id}';loadBanStatus();toggleBanUser(0);">
                    رفع مسدودیت
                </button>
            </div>
        `).join('');
    } catch(e) { list.innerHTML = '<div style="text-align:center;color:#5a5475;font-size:.72rem;padding:10px;">خطا در بارگذاری</div>'; }
}
document.addEventListener('DOMContentLoaded', loadBannedUsersList);

async function loadChatMessages(userId, isPoll = false) {
    if (!userId) return;
    try {
        let res = await fetch(`?ajax_action=get_chat_messages&user_id=${userId}&last_id=${lastChatId}`);
        let data = await res.json();
        if (data.success && data.messages.length > 0) {
            let container = document.getElementById('chatContainer');
            if (!isPoll && lastChatId === 0 && container.querySelector('.no-messages')) {
                container.innerHTML = '';
            }
            data.messages.forEach(msg => {
                let messageDiv = document.createElement('div');
                messageDiv.className = `chat-message ${msg.sender}`;
                let bubble = document.createElement('div');
                bubble.className = 'message-bubble';
                if (msg.message) bubble.innerHTML += `<div>${escapeHtml(msg.message)}</div>`;
                if (msg.file_path) bubble.innerHTML += `<div class="message-file"><i class="fas fa-paperclip"></i> <a href="${msg.file_path}" target="_blank">${escapeHtml(msg.file_name || 'Download File')}</a></div>`;
                bubble.innerHTML += `<span class="message-time"><i class="far fa-clock"></i> ${formatChatTime(msg.created_at)}</span>`;
                messageDiv.appendChild(bubble);
                container.appendChild(messageDiv);
                if (msg.id > lastChatId) lastChatId = msg.id;
            });
            container.scrollTop = container.scrollHeight;
            if (!isPoll && data.messages.length > 0) {
                fetch(`?ajax_action=mark_chat_read`, { method: 'POST', body: new URLSearchParams({ user_id: userId }) })
                    .then(() => refreshChatUnreadBadges())
                    .catch(() => {});
            }
        } else if (!isPoll && lastChatId === 0) {
            document.getElementById('chatContainer').innerHTML = `<div style="text-align: center; padding: 50px 20px;"><i class="fas fa-comment-dots" style="font-size: 3rem; margin-bottom: 15px; opacity: 0.3;"></i><p style="color: #B8B8D1;">No messages yet</p><p style="font-size: 0.8rem;">Send a message to start the conversation</p></div>`;
        }
    } catch(e) { console.error(e); }
}

function formatChatTime(dateStr) {
    let d = new Date(dateStr);
    let now = new Date();
    let diff = Math.floor((now - d) / 1000);
    if (diff < 60) return 'Just now';
    if (diff < 3600) return Math.floor(diff / 60) + ' min ago';
    if (diff < 86400) return Math.floor(diff / 3600) + ' hours ago';
    if (diff < 604800) return Math.floor(diff / 86400) + ' days ago';
    return d.toLocaleDateString();
}

document.getElementById('chatForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    let userId = document.getElementById('chatUserId').value;
    let message = document.getElementById('chatMessage').value.trim();
    let file = document.getElementById('chatFile').files[0];
    if (!message && !file) { showAdminToast('Please enter a message or select a file', 'error'); return; }
    let formData = new FormData();
    formData.append('user_id', userId);
    formData.append('message', message);
    if (file) formData.append('file', file);
    let btn = this.querySelector('button[type="submit"]');
    let originalText = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    if (file && typeof window.avaShowUploadProgress === 'function') window.avaShowUploadProgress('در حال آپلود فایل...');
    try {
        const data = (file && typeof window.avaUploadWithProgress === 'function')
            ? await window.avaUploadWithProgress(`?ajax_action=send_chat_message`, formData)
            : await (await fetch(`?ajax_action=send_chat_message`, { method: 'POST', body: formData })).json();
        if (file && typeof window.avaHideUploadProgress === 'function') window.avaHideUploadProgress(!!data.success);
        if (data.success) {
            document.getElementById('chatMessage').value = '';
            document.getElementById('chatFile').value = '';
            document.getElementById('filePreviewChat').innerHTML = '';
            document.getElementById('filePreviewChat').classList.remove('show');
            await loadChatMessages(userId);
        } else { showAdminToast('Error sending message', 'error'); }
    } catch(e) { if (file && typeof window.avaHideUploadProgress === 'function') window.avaHideUploadProgress(false); showAdminToast('Network error', 'error'); }
    finally { btn.disabled = false; btn.innerHTML = originalText; }
});

document.getElementById('chatFile').addEventListener('change', function() {
    let preview = document.getElementById('filePreviewChat');
    if (this.files.length) {
        preview.innerHTML = `<i class="fas fa-file"></i> ${escapeHtml(this.files[0].name)} <span onclick="this.parentElement.innerHTML=''; document.getElementById('chatFile').value='';" style="cursor:pointer; margin-left:10px; color:#FF3B30;">✖</span>`;
        preview.classList.add('show');
    } else {
        preview.innerHTML = '';
        preview.classList.remove('show');
    }
});

// ====================================================
// SLIDES MANAGEMENT
// ====================================================
// ==================== تنظیمات ارسال به کانال ====================
async function loadChannelSettings() {
    try {
        const res = await fetch('api/channel_settings_api.php?action=get');
        const data = await res.json();
        if (data.success) {
            document.getElementById('adsChannelId').value = data.channel_id || '';
            document.getElementById('adsBroadcastEnabled').checked = !!data.enabled;
            const statusEl = document.getElementById('channelSettingsStatus');
            let txt = '';
            if (data.last_ts) {
                const d = new Date(data.last_ts * 1000);
                txt += 'آخرین ارسال: ' + d.toLocaleString('fa-IR');
            }
            if (data.last_status) {
                txt += (txt ? ' — ' : '') + 'وضعیت: ' + data.last_status;
            }
            statusEl.textContent = txt;
        }
    } catch (e) { console.error(e); }
}

async function saveChannelSettings() {
    const channelId = document.getElementById('adsChannelId').value.trim();
    const enabled   = document.getElementById('adsBroadcastEnabled').checked;
    try {
        const res = await fetch('api/channel_settings_api.php?action=save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ channel_id: channelId, enabled: enabled })
        });
        const data = await res.json();
        document.getElementById('channelSettingsStatus').textContent = data.message || '';
        if (typeof showToast === 'function') showToast(data.message || 'ذخیره شد', data.success ? 'success' : 'error');
        else alert(data.message || 'ذخیره شد');
    } catch (e) { alert('خطا در ذخیره‌سازی'); }
}

async function sendAdsNow() {
    const statusEl = document.getElementById('channelSettingsStatus');
    statusEl.textContent = 'در حال ارسال...';
    try {
        const res = await fetch('api/channel_settings_api.php?action=send_now', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({})
        });
        const data = await res.json();
        const r = data.result || {};
        let msg = r.message || 'انجام شد';
        if (r.sent === false && r.message) msg = r.message;
        statusEl.textContent = msg;
        if (typeof showToast === 'function') showToast(msg, r.sent ? 'success' : 'info');
        else alert(msg);
    } catch (e) {
        statusEl.textContent = 'خطا در ارسال';
        alert('خطا در ارسال');
    }
}


// ====================================================
// SEND TOAST NOTIFICATION
// ====================================================
async function sendToast() {
    const target = parseInt(document.getElementById('toastTarget').value);
    const title = document.getElementById('toastTitle').value.trim();
    const message = document.getElementById('toastMessage').value.trim();
    if (!title || !message) { showAdminToast('Please enter title and message', 'error'); return; }
    const payload = { target_user_id: target, title, message };
    const res = await fetch('api/slides_quickactions_api.php?action=send_toast', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
    const data = await res.json();
    const fb = document.getElementById('toastFeedback');
    if (data.success) {
        const parts = [];
        if (data.push_sent  !== undefined) parts.push(`${data.push_sent} push`);
        if (data.email_sent !== undefined) parts.push(`${data.email_sent} email`);
        const info = parts.length ? ` (${parts.join(' · ')})` : '';
        fb.innerHTML = `<div style="color:#4CD964;padding:10px;background:rgba(76,217,100,0.1);border-radius:10px;"><i class="fas fa-check-circle"></i> Notification sent!${info}</div>`;
        showAdminToast('Notification sent!', 'success');
        document.getElementById('toastMessage').value = '';
    } else {
        fb.innerHTML = '<div style="color:#FF3B30;padding:10px;background:rgba(255,59,48,0.1);border-radius:10px;"><i class="fas fa-exclamation-circle"></i> Error</div>';
    }
    setTimeout(()=>{ if(fb) fb.innerHTML=''; }, 5000);
}

// ====================================================
// CLOSE MODALS ON OVERLAY CLICK
// ====================================================
document.querySelectorAll('.modal-overlay').forEach(el => {
    el.addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('open');
    });
});

// ====================================================
// INIT
// ====================================================
document.addEventListener('DOMContentLoaded', function() {
    adminLoadTopups('');
    adminLoadInvoices('');
});

// ====================================================
// NEWS MANAGEMENT
// ====================================================
const NEWS_API = 'api/news_api.php';
let adminNewsFilter = '';

async function adminLoadNews(lang, el) {
    adminNewsFilter = lang || '';
    document.querySelectorAll('.news-admin-langtab').forEach(t => {
        t.classList.remove('active'); t.style.background = 'transparent'; t.style.color = 'rgba(255,255,255,0.5)';
    });
    if (el) { el.classList.add('active'); el.style.background = 'linear-gradient(135deg,#38bdf8,#6C40C5)'; el.style.color = '#fff'; }
    const box = document.getElementById('adminNewsList');
    box.innerHTML = '<div style="text-align:center;padding:20px;color:rgba(255,255,255,0.4);"><i class="fas fa-spinner fa-spin"></i></div>';
    try {
        const res = await fetch(NEWS_API + '?action=admin_list&lang=' + adminNewsFilter);
        const data = await res.json();
        if (!data.success || !data.news.length) {
            box.innerHTML = '<div style="text-align:center;padding:20px;color:rgba(255,255,255,0.35);">خبری ثبت نشده</div>';
            return;
        }
        box.innerHTML = data.news.map(n => {
            const dir = n.lang === 'fa' ? 'rtl' : 'ltr';
            const langBadge = n.lang === 'fa'
                ? '<span style="background:rgba(108,64,197,0.2);color:#a78bfa;font-size:.6rem;padding:2px 8px;border-radius:10px;">فارسی</span>'
                : '<span style="background:rgba(56,189,248,0.2);color:#38bdf8;font-size:.6rem;padding:2px 8px;border-radius:10px;">EN</span>';
            const pub = Number(n.is_published) === 1
                ? '<span style="color:#4CD964;font-size:.6rem;">● منتشر شده</span>'
                : '<span style="color:#888;font-size:.6rem;">○ پیش‌نویس</span>';
            const thumb = n.image ? `<img src="${n.image}" style="width:44px;height:44px;border-radius:8px;object-fit:cover;flex-shrink:0;">` : `<div style="width:44px;height:44px;border-radius:8px;background:rgba(56,189,248,0.12);display:flex;align-items:center;justify-content:center;color:#38bdf8;flex-shrink:0;"><i class="fas fa-newspaper"></i></div>`;
            return `<div style="display:flex;align-items:center;gap:12px;padding:12px;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.07);border-radius:12px;margin-bottom:8px;" dir="${dir}">
                ${thumb}
                <div style="flex:1;min-width:0;">
                    <div style="color:#fff;font-weight:600;font-size:.85rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escapeHtml(n.title)}</div>
                    <div style="margin-top:4px;display:flex;gap:8px;align-items:center;">${langBadge} ${pub}</div>
                </div>
                <div style="display:flex;gap:6px;flex-shrink:0;">
                    <button onclick="editNews(${n.id})" style="background:rgba(56,189,248,0.15);border:1px solid rgba(56,189,248,0.35);color:#38bdf8;width:32px;height:32px;border-radius:8px;cursor:pointer;"><i class="fas fa-edit"></i></button>
                    <button onclick="deleteNews(${n.id})" style="background:rgba(255,59,48,0.12);border:1px solid rgba(255,59,48,0.35);color:#ff6b6b;width:32px;height:32px;border-radius:8px;cursor:pointer;"><i class="fas fa-trash"></i></button>
                </div>
            </div>`;
        }).join('');
    } catch(e) {
        box.innerHTML = '<div style="text-align:center;padding:20px;color:#ff6b6b;">خطا در بارگذاری</div>';
    }
}

function openNewsForm() {
    document.getElementById('newsId').value = '';
    document.getElementById('newsLangSel').value = 'en';
    document.getElementById('newsTitleInput').value = '';
    document.getElementById('newsBodyInput').value = '';
    document.getElementById('newsImageInput').value = '';
    document.getElementById('newsImagePreview').innerHTML = '';
    document.getElementById('newsFormFeedback').innerHTML = '';
    document.getElementById('newsFormTitle').textContent = 'افزودن خبر';
    document.getElementById('news-form-modal').style.display = 'flex';
}
function closeNewsForm() {
    document.getElementById('news-form-modal').style.display = 'none';
}
async function editNews(id) {
    try {
        const res = await fetch(NEWS_API + '?action=get_one&id=' + id);
        const data = await res.json();
        if (!data.success) { showAdminToast('خبر یافت نشد', 'error'); return; }
        const n = data.news;
        document.getElementById('newsId').value = n.id;
        document.getElementById('newsLangSel').value = n.lang;
        document.getElementById('newsTitleInput').value = n.title;
        document.getElementById('newsBodyInput').value = n.body;
        document.getElementById('newsImageInput').value = '';
        document.getElementById('newsImagePreview').innerHTML = n.image ? `<img src="${n.image}" style="max-width:100%;border-radius:10px;max-height:140px;">` : '';
        document.getElementById('newsFormFeedback').innerHTML = '';
        document.getElementById('newsFormTitle').textContent = 'ویرایش خبر';
        document.getElementById('news-form-modal').style.display = 'flex';
    } catch(e) { showAdminToast('خطا', 'error'); }
}
async function saveNews() {
    const fb = document.getElementById('newsFormFeedback');
    const btn = document.getElementById('newsSaveBtn');
    const title = document.getElementById('newsTitleInput').value.trim();
    const body = document.getElementById('newsBodyInput').value.trim();
    if (!title || !body) { fb.style.color = '#ff6b6b'; fb.textContent = 'عنوان و متن الزامی است'; return; }
    const fd = new FormData();
    fd.append('id', document.getElementById('newsId').value);
    fd.append('lang', document.getElementById('newsLangSel').value);
    fd.append('title', title);
    fd.append('body', body);
    fd.append('is_published', '1');
    const img = document.getElementById('newsImageInput').files[0];
    if (img) fd.append('image', img);
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ذخیره...'; }
    if (img && typeof window.avaShowUploadProgress === 'function') window.avaShowUploadProgress('در حال آپلود تصویر خبر...');
    try {
        let data;
        if (img && typeof window.avaUploadWithProgress === 'function') {
            data = await window.avaUploadWithProgress(NEWS_API + '?action=save', fd);
        } else {
            const res = await fetch(NEWS_API + '?action=save', { method: 'POST', body: fd });
            const text = await res.text();
            try { data = JSON.parse(text); }
            catch(pe) { fb.style.color = '#ff6b6b'; fb.textContent = 'پاسخ نامعتبر سرور: ' + text.substring(0, 120); data = null; }
        }
        if (img && typeof window.avaHideUploadProgress === 'function') window.avaHideUploadProgress(!!(data && data.success));
        if (data && data.success) {
            showAdminToast(data.message || 'ذخیره شد', 'success');
            closeNewsForm();
            adminLoadNews(adminNewsFilter, document.querySelector('.news-admin-langtab.active'));
        } else if (data) {
            fb.style.color = '#ff6b6b'; fb.textContent = data.message || 'خطا';
        }
    } catch(e) { if (img && typeof window.avaHideUploadProgress === 'function') window.avaHideUploadProgress(false); fb.style.color = '#ff6b6b'; fb.textContent = 'خطا در ارتباط: ' + (e.message || ''); }
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> ذخیره خبر'; }
}
async function deleteNews(id) {
    if (!confirm('این خبر حذف شود؟')) return;
    try {
        const res = await fetch(NEWS_API + '?action=delete', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id })
        });
        const data = await res.json();
        if (data.success) { showAdminToast('حذف شد', 'success'); adminLoadNews(adminNewsFilter, document.querySelector('.news-admin-langtab.active')); }
        else { showAdminToast(data.message || 'خطا', 'error'); }
    } catch(e) { showAdminToast('خطا', 'error'); }
}

// ====================================================
// KYC MANAGEMENT (احراز هویت)
// ====================================================
const KYC_API = 'api/kyc_api.php';
let adminKycFilter = 'pending';

async function adminLoadKyc(status, el) {
    adminKycFilter = status;
    document.querySelectorAll('.kyc-admin-tab').forEach(t => {
        t.style.background = 'transparent'; t.style.color = 'rgba(255,255,255,0.5)';
    });
    if (el) { el.style.background = 'linear-gradient(135deg,#f59e0b,#FF6B35)'; el.style.color = '#fff'; }
    const box = document.getElementById('adminKycList');
    box.innerHTML = '<div style="text-align:center;padding:20px;color:rgba(255,255,255,0.4);"><i class="fas fa-spinner fa-spin"></i></div>';
    try {
        const res = await fetch(KYC_API + '?action=admin_list&status=' + status);
        const data = await res.json();
        if (!data.success || !data.requests.length) {
            box.innerHTML = '<div style="text-align:center;padding:20px;color:rgba(255,255,255,0.35);">درخواستی وجود ندارد</div>';
            return;
        }
        box.innerHTML = data.requests.map(r => {
            const img = r.selfie_image
                ? `<a href="${r.selfie_image}" target="_blank"><img src="${r.selfie_image}" style="width:70px;height:70px;border-radius:10px;object-fit:cover;border:1px solid rgba(255,255,255,0.15);"></a>`
                : `<div style="width:70px;height:70px;border-radius:10px;background:rgba(255,255,255,0.05);display:flex;align-items:center;justify-content:center;color:#666;"><i class="fas fa-image"></i></div>`;
            const actions = r.status === 'pending' ? `
                <div style="display:flex;gap:6px;margin-top:10px;">
                    <button onclick="kycReview(${r.id}, 'approve')" style="flex:1;background:rgba(76,217,100,0.15);border:1px solid rgba(76,217,100,0.4);color:#4CD964;padding:8px;border-radius:10px;cursor:pointer;font-size:.75rem;"><i class="fas fa-check"></i> تایید</button>
                    <button onclick="kycReview(${r.id}, 'reject')" style="flex:1;background:rgba(255,59,48,0.12);border:1px solid rgba(255,59,48,0.4);color:#ff6b6b;padding:8px;border-radius:10px;cursor:pointer;font-size:.75rem;"><i class="fas fa-times"></i> رد</button>
                </div>` : `
                <div style="display:flex;gap:6px;margin-top:10px;">
                    <button onclick="kycDelete(${r.id})" style="background:rgba(255,59,48,0.12);border:1px solid rgba(255,59,48,0.35);color:#ff6b6b;padding:8px 14px;border-radius:10px;cursor:pointer;font-size:.75rem;"><i class="fas fa-trash"></i> حذف از لیست</button>
                </div>`;
            return `<div style="display:flex;gap:14px;padding:14px;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.07);border-radius:14px;margin-bottom:10px;" dir="rtl">
                ${img}
                <div style="flex:1;min-width:0;">
                    <div style="color:#fff;font-weight:700;font-size:.9rem;">${escapeHtml(r.first_name + ' ' + r.last_name)}</div>
                    <div style="color:#999;font-size:.72rem;margin-top:4px;" dir="ltr">${escapeHtml(r.email)} · ${escapeHtml(r.phone)}</div>
                    <div style="color:#777;font-size:.66rem;margin-top:3px;">حساب: ${escapeHtml(r.account_number || '-')} · ${r.created_at}</div>
                    ${actions}
                </div>
            </div>`;
        }).join('');
    } catch(e) {
        box.innerHTML = '<div style="text-align:center;padding:20px;color:#ff6b6b;">خطا در بارگذاری</div>';
    }
}
async function kycReview(id, decision) {
    let note = '';
    if (decision === 'reject') {
        note = prompt('دلیل رد (اختیاری):') || '';
    } else if (!confirm('این کاربر تایید شود؟')) return;
    try {
        const res = await fetch(KYC_API + '?action=admin_review', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ request_id: id, decision: decision, note: note })
        });
        const data = await res.json();
        if (data.success) { showAdminToast(decision === 'approve' ? 'تایید شد' : 'رد شد', 'success'); adminLoadKyc(adminKycFilter, document.querySelector('.kyc-admin-tab')); }
        else { showAdminToast(data.message || 'خطا', 'error'); }
    } catch(e) { showAdminToast('خطا', 'error'); }
}
async function kycDelete(id) {
    if (!confirm('این درخواست حذف شود؟')) return;
    try {
        const res = await fetch(KYC_API + '?action=admin_delete', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ request_id: id })
        });
        const data = await res.json();
        if (data.success) { showAdminToast('حذف شد', 'success'); adminLoadKyc(adminKycFilter, document.querySelector('.kyc-admin-tab')); }
    } catch(e) { showAdminToast('خطا', 'error'); }
}

/* ---------- ویرایش اطلاعات کاربر (دراپ‌داون در بخش KYC) ---------- */
let kycUsersCache = [];

async function kycLoadUserOptions() {
    const sel = document.getElementById('kycUserSelect');
    if (!sel) return;
    const keepId = sel.value;
    sel.innerHTML = '<option value="">در حال بارگذاری...</option>';
    try {
        const res = await fetch(KYC_API + '?action=admin_search_users&search=', { cache: 'no-store' });
        const data = await res.json();
        kycUsersCache = data.users || [];
        renderKycUserOptions(kycUsersCache);
        if (keepId) sel.value = keepId;
    } catch (e) { sel.innerHTML = '<option value="">خطا در بارگذاری لیست</option>'; }
}

function renderKycUserOptions(list) {
    const sel = document.getElementById('kycUserSelect');
    if (!sel) return;
    const opts = ['<option value="">— یک کاربر را انتخاب کنید —</option>'].concat(
        list.map(u => {
            const name = axEsc(((u.first_name || '') + ' ' + (u.last_name || '')).trim() || 'بدون نام');
            const tag = u.telegram_id ? (' — ' + axEsc(u.telegram_id)) : '';
            return `<option value="${u.id}">${name}${tag}</option>`;
        })
    );
    sel.innerHTML = opts.join('');
}

function kycFilterUserSelect() {
    const q = document.getElementById('kycUserFilter').value.trim().toLowerCase();
    if (!q) { renderKycUserOptions(kycUsersCache); return; }
    const filtered = kycUsersCache.filter(u =>
        ((u.first_name || '') + ' ' + (u.last_name || '')).toLowerCase().includes(q) ||
        (u.telegram_id || '').toLowerCase().includes(q) ||
        (u.email || '').toLowerCase().includes(q) ||
        (u.phone_number || '').toLowerCase().includes(q)
    );
    renderKycUserOptions(filtered);
}

async function kycUserPicked() {
    const id = document.getElementById('kycUserSelect').value;
    const box = document.getElementById('kycUserEditBox');
    if (!id) { box.innerHTML = ''; return; }
    box.innerHTML = '<div class="ax-empty"><i class="fas fa-spinner fa-spin"></i></div>';
    try {
        const res = await fetch(KYC_API + '?action=admin_get_user&user_id=' + id, { cache: 'no-store' });
        const data = await res.json();
        if (!data.success) { box.innerHTML = '<div class="ax-empty">' + (data.message || 'خطا') + '</div>'; return; }
        const u = data.user;
        const kycOptions = [
            ['none', 'بدون احراز هویت'],
            ['pending', 'در انتظار بررسی'],
            ['approved', 'تایید شده'],
            ['rejected', 'رد شده'],
        ].map(([v, label]) => `<option value="${v}"${u.kyc_status === v ? ' selected' : ''}>${label}</option>`).join('');

        box.innerHTML = `
            <div class="ax-item">
                <div class="ax-item-head">
                    <b style="color:#fff;">${axEsc((u.first_name || '') + ' ' + (u.last_name || ''))}</b>
                    <span style="font-size:.7rem;color:rgba(255,255,255,0.5);">${axEsc(u.telegram_id || u.account_number || '')}</span>
                </div>
                <div class="ax-form-section">
                    <div class="ax-form-row">
                        <input type="text" id="kycEditFirst-${u.id}" class="ax-input" placeholder="نام" value="${axEsc(u.first_name || '')}">
                        <input type="text" id="kycEditLast-${u.id}" class="ax-input" placeholder="نام‌خانوادگی" value="${axEsc(u.last_name || '')}">
                    </div>
                    <div class="ax-form-row" style="margin-top:8px;">
                        <input type="text" id="kycEditPhone-${u.id}" class="ax-input" placeholder="شماره موبایل" value="${axEsc(u.phone_number || '')}" dir="ltr">
                        <input type="email" id="kycEditEmail-${u.id}" class="ax-input" placeholder="ایمیل" value="${axEsc(u.email || '')}" dir="ltr">
                    </div>
                    <div class="ax-form-row" style="margin-top:8px;">
                        <select id="kycEditStatus-${u.id}" class="ax-select">${kycOptions}</select>
                        <button class="ax-btn ax-btn-accounts" onclick="kycSaveUser(${u.id})"><i class="fas fa-save"></i> ذخیره تغییرات</button>
                    </div>
                </div>
                <div class="ax-form-hint">این اطلاعات از این پس منبع اصلی پروفایل کاربر است و با ورود مجدد از تلگرام بازنویسی نمی‌شود.</div>

                <div style="margin-top:18px;padding-top:16px;border-top:1px dashed rgba(255,77,109,.3);">
                    <div style="color:#ff4d6d;font-size:.74rem;font-weight:800;margin-bottom:8px;"><i class="fas fa-triangle-exclamation"></i> منطقه‌ی خطر</div>
                    <button class="ax-btn" style="background:linear-gradient(135deg,#dc2626,#991b1b);width:100%;" onclick="kycDeleteUser(${u.id}, ${JSON.stringify(axEsc((u.first_name || '') + ' ' + (u.last_name || '')))})">
                        <i class="fas fa-user-slash"></i> حذف کامل این کاربر
                    </button>
                    <div style="color:rgba(255,255,255,.4);font-size:.66rem;margin-top:6px;line-height:1.7;">
                        این عمل غیرقابل بازگشت است: حساب کاربری، امکان ورود و اطلاعات پروفایل برای همیشه حذف می‌شود.
                        تاریخچه‌ی تراکنش‌های مشترک با سایر کاربران برای حفظ صحت گزارش‌های آن‌ها دست‌نخورده باقی می‌ماند.
                    </div>
                </div>
            </div>`;
    } catch (e) { box.innerHTML = '<div class="ax-empty">خطا در دریافت اطلاعات کاربر</div>'; }
}

async function kycSaveUser(userId) {
    const first_name = document.getElementById('kycEditFirst-' + userId).value.trim();
    const last_name = document.getElementById('kycEditLast-' + userId).value.trim();
    const phone_number = document.getElementById('kycEditPhone-' + userId).value.trim();
    const email = document.getElementById('kycEditEmail-' + userId).value.trim();
    const kyc_status = document.getElementById('kycEditStatus-' + userId).value;
    if (!first_name || !last_name) { showAdminToast('نام و نام‌خانوادگی الزامی است', 'error'); return; }
    try {
        const res = await fetch(KYC_API + '?action=admin_update_user', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user_id: userId, first_name, last_name, phone_number, email, kyc_status })
        });
        const data = await res.json();
        if (data.success) { showAdminToast('اطلاعات کاربر ذخیره شد', 'success'); kycLoadUserOptions(); }
        else showAdminToast(data.message || 'خطا', 'error');
    } catch (e) { showAdminToast('خطا در ذخیره اطلاعات', 'error'); }
}

// حذف کامل کاربر — به‌جای confirm()/prompt() بومی مرورگر (که در برخی
// وب‌ویوهای درون‌برنامه‌ای مثل تلگرام اصلاً اجرا نمی‌شوند و باعث می‌شدند
// دکمه هیچ واکنشی نداشته باشد)، یک مودال داخل‌صفحه‌ای برای تأیید دو مرحله‌ای
// (نمایش هشدار + وارد کردن دستیِ شناسه‌ی عددی) استفاده می‌شود.
let kycDeletePendingUser = null;

function kycDeleteUser(userId, fullName) {
    kycDeletePendingUser = { id: userId, name: fullName };
    document.getElementById('delUserNameLabel').textContent = fullName;
    document.getElementById('delUserIdLabel').textContent = userId;
    document.getElementById('delUserConfirmInput').value = '';
    document.getElementById('delUserMsg').textContent = '';
    openModal('deleteUserModal');
}

async function kycConfirmDeleteUser() {
    if (!kycDeletePendingUser) return;
    const { id: userId, name: fullName } = kycDeletePendingUser;
    const typed = (document.getElementById('delUserConfirmInput').value || '').trim();
    const msg = document.getElementById('delUserMsg');

    if (typed !== String(userId)) {
        msg.style.color = '#ff4d6d';
        msg.textContent = 'شناسه واردشده مطابقت نداشت';
        return;
    }

    const btn = document.querySelector('#deleteUserModal .btn');
    const originalText = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال حذف...'; }
    msg.style.color = 'rgba(255,255,255,.6)';
    msg.textContent = '';

    try {
        const res = await fetch(KYC_API + '?action=admin_delete_user', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ user_id: userId, confirm: typed })
        });
        const data = await res.json();
        if (data.success) {
            closeModal('deleteUserModal');
            showAdminToast(data.message || 'کاربر حذف شد', 'success');
            document.getElementById('kycUserEditBox').innerHTML = '';
            document.getElementById('kycUserSelect').value = '';
            kycLoadUserOptions();
        } else {
            msg.style.color = '#ff4d6d';
            msg.textContent = data.message || 'خطا در حذف کاربر';
        }
    } catch (e) {
        msg.style.color = '#ff4d6d';
        msg.textContent = 'خطا در ارتباط با سرور';
    }
    if (btn) { btn.disabled = false; btn.innerHTML = originalText; }
}

async function kycSyncBotVerified() {
    const box = document.getElementById('kycBotSyncResult');
    if (box) box.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال همگام‌سازی...';
    try {
        const res = await fetch(KYC_API + '?action=admin_sync_bot_kyc', { method: 'POST' });
        const data = await res.json();
        if (data.success) {
            showAdminToast('همگام‌سازی انجام شد', 'success');
            if (box) box.innerHTML = `بررسی‌شده: ${data.checked} — به‌روزرسانی‌شده: ${data.updated}`;
            kycLoadUserOptions();
        } else {
            showAdminToast(data.message || 'خطا', 'error');
            if (box) box.innerHTML = data.message || 'خطا در همگام‌سازی';
        }
    } catch (e) {
        showAdminToast('خطا در همگام‌سازی', 'error');
        if (box) box.innerHTML = 'خطا در ارتباط با سرور';
    }
}

async function kycSyncTelegramAvatars() {
    const box = document.getElementById('kycAvatarSyncResult');
    let totalChecked = 0, totalUpdated = 0, totalSkipped = 0;
    let round = 0;
    const maxRounds = 40; // سقف ایمنی — هر دور حداکثر ۲۵ کاربر، یعنی تا ۱۰۰۰ کاربر در یک اجرا
    // یک برچسب زمانی ثابت برای کل این اجرای دکمه — چون این اکشن الان همه‌ی
    // کاربران را هر بار دوباره sync می‌کند (نه فقط آواتارهای خالی)، سرور از
    // روی همین run_started تشخیص می‌دهد چه کسی «در همین اجرا» هنوز sync
    // نشده تا remaining درست به صفر برسد و حلقه بی‌نهایت نشود.
    const runStarted = new Date().toISOString().slice(0, 19).replace('T', ' ');
    if (box) box.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال همگام‌سازی...';
    try {
        while (round < maxRounds) {
            round++;
            const res = await fetch(KYC_API + '?action=admin_sync_telegram_avatars&run_started=' + encodeURIComponent(runStarted), { method: 'POST' });
            const data = await res.json();
            if (!data.success) {
                showAdminToast(data.message || 'خطا', 'error');
                if (box) box.innerHTML = data.message || 'خطا در همگام‌سازی';
                return;
            }
            totalChecked += data.checked || 0;
            totalUpdated += data.updated || 0;
            totalSkipped += data.skipped || 0;
            if (box) box.innerHTML = `در حال ادامه... بررسی‌شده: ${totalChecked} — عکس تنظیم‌شده: ${totalUpdated} — بدون عکس عمومی: ${totalSkipped}`;
            if (!data.checked || data.remaining <= 0) break; // دیگر کاربری برای بررسی نمانده
        }
        showAdminToast('همگام‌سازی عکس‌های پروفایل تمام شد', 'success');
        if (box) box.innerHTML = `تمام شد — بررسی‌شده: ${totalChecked} — عکس تنظیم‌شده: ${totalUpdated} — بدون عکس عمومی: ${totalSkipped}`;
        kycLoadUserOptions();
    } catch (e) {
        showAdminToast('خطا در همگام‌سازی', 'error');
        if (box) box.innerHTML = 'خطا در ارتباط با سرور';
    }
}

/* =====================================================================
 * پنل‌های ادغام‌شده: حواله ارزی / تسویه حساب / تبادل ارزی
 * ===================================================================== */
const AX_TRANSFER_API = 'api/admin_exchange_api.php';
const AX_TRANSFER_ACT = 'api/transfer_api.php';
const AX_WITHDRAW_API = 'api/withdraw.php';

/* خواندن پاسخ API به‌صورت امن: اگر سرور به‌جای JSON خطای HTML برگرداند،
   به‌جای «خطا»ی بی‌معنی، متن واقعی خطا نمایش داده می‌شود. */
async function axJson(res){
    const raw = await res.text();
    try { return JSON.parse(raw); }
    catch(e){
        const plain = raw.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
        return { success:false, message: plain ? ('پاسخ نامعتبر از سرور: ' + plain.slice(0, 220)) : ('خطای سرور (HTTP ' + res.status + ')') };
    }
}
const AX_DISCOUNT_API = 'api/discount_api.php';
const AX_SAVED_ACC_API = 'api/saved_accounts_api.php';

function axEsc(s){ return (s==null?'':String(s)).replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }
function axNum(n){ return Number(n||0).toLocaleString('fa-IR'); }

/* =====================================================================
 * حساب‌های ذخیره‌شده (Saved Accounts): تعریف یک‌بارِ حساب‌ها توسط ادمین
 * و استفاده‌ی مجدد آن‌ها با یک کلیک، در همه‌جایی که ادمین باید شماره‌حساب
 * برای کاربر ارسال کند: حواله ارزی، تسویه معاملات، تأیید Top-up و تأیید فیش.
 * قابلیت ویرایش و حذف هر حساب همیشه در دسترس است.
 * ===================================================================== */
function axSavedAccPickerHtml(pickerId, target, side){
    return `
    <div class="ax-savedacc-box" id="${pickerId}" data-target="${target}" data-side="${side||''}">
        <div class="ax-savedacc-title"><i class="fas fa-address-book"></i> حساب‌های ذخیره‌شده <span class="ax-savedacc-hint">(برای استفاده کلیک کنید)</span></div>
        <div class="ax-savedacc-list" id="${pickerId}_list"><div class="ax-empty" style="padding:6px 0;"><i class="fas fa-spinner fa-spin"></i></div></div>
        <div class="ax-savedacc-addrow">
            <input type="text" class="ax-savedacc-in" id="${pickerId}_newname" placeholder="نام صاحب حساب">
            <input type="text" class="ax-savedacc-in" id="${pickerId}_newbank" placeholder="نام بانک (اختیاری)">
            <input type="text" class="ax-savedacc-in" id="${pickerId}_newaccount" placeholder="شماره حساب (اختیاری)" style="direction:ltr;text-align:right;">
            <input type="text" class="ax-savedacc-in" id="${pickerId}_newcard" placeholder="شماره کارت" style="direction:ltr;text-align:right;">
            <input type="text" class="ax-savedacc-in" id="${pickerId}_newiban" placeholder="شبا (اختیاری)" style="direction:ltr;text-align:right;">
            <button type="button" class="ax-btn ax-btn-view ax-btn-sm" onclick="axSavedAccAdd('${pickerId}')"><i class="fas fa-plus"></i> ذخیره حساب جدید</button>
        </div>
    </div>`;
}
async function axSavedAccLoad(pickerId){
    const list = document.getElementById(pickerId+'_list');
    if (!list) return;
    list.innerHTML = '<div class="ax-empty" style="padding:6px 0;"><i class="fas fa-spinner fa-spin"></i></div>';
    try {
        const res = await fetch(AX_SAVED_ACC_API + '?action=list', { cache:'no-store' });
        const d = await axJson(res);
        const accounts = (d.data || []).filter(a => Number(a.is_active) === 1);
        if (!accounts.length){ list.innerHTML = '<div class="ax-empty" style="padding:4px 0;font-size:.68rem;">هنوز حسابی ذخیره نکرده‌اید</div>'; return; }
        list.innerHTML = accounts.map(a => {
            const bits = [a.bank_name, a.account_number, a.card, a.iban].filter(Boolean).map(axEsc).join(' · ');
            return `
            <div class="ax-savedacc-item" data-id="${a.id}" data-name="${axEsc(a.name)}" data-card="${axEsc(a.card)}" data-account="${axEsc(a.account_number)}" data-iban="${axEsc(a.iban)}" data-bank="${axEsc(a.bank_name)}">
                <div class="ax-savedacc-info" onclick="axSavedAccUse(this)"><b>${axEsc(a.name)}</b><code>${bits}</code></div>
                <div class="ax-savedacc-actions">
                    <button type="button" class="ax-savedacc-btn" title="ویرایش" onclick="event.stopPropagation();axSavedAccEditToggle(this)"><i class="fas fa-pen"></i></button>
                    <button type="button" class="ax-savedacc-btn del" title="حذف" onclick="event.stopPropagation();axSavedAccDelete(this,'${pickerId}')"><i class="fas fa-trash"></i></button>
                </div>
            </div>`;
        }).join('');
    } catch(e){ list.innerHTML = '<div class="ax-empty" style="padding:4px 0;">خطا در دریافت لیست حساب‌ها</div>'; }
}
function axSavedAccUse(el){
    const item = el.closest('.ax-savedacc-item');
    const box = el.closest('.ax-savedacc-box');
    const name = item.dataset.name, card = item.dataset.card, account = item.dataset.account, iban = item.dataset.iban, bank = item.dataset.bank;
    const target = box.dataset.target;
    if (target === 'transfer'){
        axAddAccRow(name, card || account || iban);
    } else if (target === 'topup' || target === 'invoice'){
        const p = target === 'topup' ? 'approve' : 'inv-approve';
        const set = (id, v) => { const el2 = document.getElementById(id); if (el2) el2.value = v || ''; };
        set(p+'-bank', bank);
        set(p+'-account', account);
        set(p+'-card', card);
        set(p+'-recipient', name);
        set(p+'-iban', iban);
    } else if (target === 'create_invoice'){
        const set = (id, v) => { const el2 = document.getElementById(id); if (el2) el2.value = v || ''; };
        set('inv_bank_name', bank);
        set('inv_account_number', account);
        set('inv_card_number', card);
        set('inv_recipient_name', name);
        set('inv_iban', iban);
    }
    showAdminToast('حساب به فرم اضافه شد', 'success');
}
function axSavedAccEditToggle(btn){
    const item = btn.closest('.ax-savedacc-item');
    if (item.querySelector('.ax-savedacc-editform')) return; // در حال ویرایش است
    const info = item.querySelector('.ax-savedacc-info');
    const actions = item.querySelector('.ax-savedacc-actions');
    info.style.display = 'none';
    actions.style.display = 'none';
    const form = document.createElement('div');
    form.className = 'ax-savedacc-editform';
    form.innerHTML = `
        <input type="text" class="ax-savedacc-in ax-se-name" value="${item.dataset.name}" placeholder="نام صاحب حساب">
        <input type="text" class="ax-savedacc-in ax-se-bank" value="${item.dataset.bank}" placeholder="نام بانک">
        <input type="text" class="ax-savedacc-in ax-se-account" value="${item.dataset.account}" placeholder="شماره حساب" style="direction:ltr;text-align:right;">
        <input type="text" class="ax-savedacc-in ax-se-card" value="${item.dataset.card}" placeholder="شماره کارت" style="direction:ltr;text-align:right;">
        <input type="text" class="ax-savedacc-in ax-se-iban" value="${item.dataset.iban}" placeholder="شبا" style="direction:ltr;text-align:right;">
        <div class="ax-savedacc-editbtns">
            <button type="button" class="ax-btn ax-btn-accounts ax-btn-sm" onclick="axSavedAccSaveEdit(this)"><i class="fas fa-check"></i> ذخیره</button>
            <button type="button" class="ax-btn ax-btn-view ax-btn-sm" onclick="axSavedAccCancelEdit(this)"><i class="fas fa-times"></i> انصراف</button>
        </div>`;
    item.appendChild(form);
}
function axSavedAccCancelEdit(btn){
    const item = btn.closest('.ax-savedacc-item');
    item.querySelector('.ax-savedacc-editform').remove();
    item.querySelector('.ax-savedacc-info').style.display = '';
    item.querySelector('.ax-savedacc-actions').style.display = '';
}
async function axSavedAccSaveEdit(btn){
    const item = btn.closest('.ax-savedacc-item');
    const box = btn.closest('.ax-savedacc-box');
    const form = item.querySelector('.ax-savedacc-editform');
    const name = form.querySelector('.ax-se-name').value.trim();
    const bank_name = form.querySelector('.ax-se-bank').value.trim();
    const account_number = form.querySelector('.ax-se-account').value.trim();
    const card = form.querySelector('.ax-se-card').value.trim();
    const iban = form.querySelector('.ax-se-iban').value.trim();
    if (!name || (!card && !account_number && !iban)){ showAdminToast('نام و حداقل یکی از شماره کارت/حساب/شبا الزامی است', 'error'); return; }
    const d = await (await fetch(AX_SAVED_ACC_API + '?action=update', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ id: item.dataset.id, name, card, account_number, iban, bank_name, is_active: 1 })
    })).json();
    if (d.success){ showAdminToast('حساب ویرایش شد', 'success'); axSavedAccLoad(box.id); }
    else showAdminToast(d.message||'خطا', 'error');
}
async function axSavedAccDelete(el,pickerId){
    if (!confirm('این حساب ذخیره‌شده حذف شود؟')) return;
    const item = el.closest('.ax-savedacc-item');
    const d = await (await fetch(AX_SAVED_ACC_API + '?action=delete', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ id: item.dataset.id })
    })).json();
    if (d.success){ showAdminToast('حساب حذف شد', 'success'); axSavedAccLoad(pickerId); }
    else showAdminToast(d.message||'خطا', 'error');
}
async function axSavedAccAdd(pickerId){
    const val = suf => (document.getElementById(pickerId+suf)?.value || '').trim();
    const name = val('_newname');
    const bank_name = val('_newbank');
    const account_number = val('_newaccount');
    const card = val('_newcard');
    const iban = val('_newiban');
    if (!name || (!card && !account_number && !iban)){ showAdminToast('نام و حداقل یکی از شماره کارت/حساب/شبا را وارد کنید', 'error'); return; }
    const d = await (await fetch(AX_SAVED_ACC_API + '?action=add', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ name, card, account_number, iban, bank_name })
    })).json();
    if (d.success){
        showAdminToast('حساب ذخیره شد', 'success');
        ['_newname','_newbank','_newaccount','_newcard','_newiban'].forEach(suf=>{ const el2=document.getElementById(pickerId+suf); if (el2) el2.value=''; });
        axSavedAccLoad(pickerId);
    } else showAdminToast(d.message||'خطا', 'error');
}

function axTransferBadge(status){
    const map = {
        pending:['ax-b-pending','در انتظار تایید'],
        approved:['ax-b-approved','تاییدشده'],
        awaiting_payment:['ax-b-await','در انتظار پرداخت'],
        payment_submitted:['ax-b-paid','فیش دریافت شد'],
        completed:['ax-b-done','تکمیل‌شده'],
        rejected:['ax-b-rej','رد شده']
    };
    const m = map[status] || ['ax-b-pending', status];
    return `<span class="ax-badge ${m[0]}">${m[1]}</span>`;
}

/* ---------- حواله ارزی ---------- */
async function axLoadTransfers(filter, btn){
    if (btn){ document.querySelectorAll('[data-tfilter]').forEach(b=>b.classList.remove('active')); btn.classList.add('active'); }
    const box = document.getElementById('axTransfersList');
    box.innerHTML = '<div class="ax-empty"><i class="fas fa-spinner fa-spin"></i> در حال بارگذاری...</div>';
    try {
        const res = await fetch(AX_TRANSFER_API + '?action=list_transfers&filter=' + encodeURIComponent(filter), { cache:'no-store' });
        const data = await res.json();
        if (!data.success){ box.innerHTML = '<div class="ax-empty">خطا در دریافت داده</div>'; return; }
        if (!data.transfers.length){ box.innerHTML = '<div class="ax-empty">حواله‌ای یافت نشد</div>'; return; }
        box.innerHTML = data.transfers.map(axRenderTransfer).join('');
    } catch(e){ box.innerHTML = '<div class="ax-empty">خطا در ارتباط</div>'; }
}

function axRenderTransfer(t){
    const accounts = t.admin_accounts_arr || [];
    const receipts = t.payment_receipts_arr || [];
    let receiptsHtml = '';
    if (receipts.length){
        receiptsHtml = '<div class="ax-thumbs">' + receipts.map(r=>{
            const url = '/ledor/' + r;
            const isPdf = /\.pdf$/i.test(r);
            return isPdf
                ? `<a href="${url}" target="_blank" class="ax-btn ax-btn-view"><i class="fas fa-file-pdf"></i> PDF</a>`
                : `<a href="${url}" target="_blank"><img src="${url}"></a>`;
        }).join('') + '</div>';
    }
    let accountsHtml = '';
    if (accounts.length){
        accountsHtml = '<div style="margin-top:8px;font-size:.74rem;color:rgba(255,255,255,0.7);">کارت‌های اعلام‌شده:<br>' +
            accounts.map(a=>{
                if (typeof a === 'string') return `<code>${axEsc(a)}</code>`;
                const nm = a.name ? axEsc(a.name)+' — ' : '';
                return `<div style="margin-top:3px;">${nm}<code>${axEsc(a.card||'')}</code></div>`;
            }).join('') + '</div>';
    }

    let actions = '';
    if (t.status === 'pending'){
        actions = `<button class="ax-btn ax-btn-approve" onclick="axApproveTransfer(${t.id})"><i class="fas fa-check"></i> تایید</button>
                   <button class="ax-btn ax-btn-reject" onclick="axRejectTransfer(${t.id})"><i class="fas fa-times"></i> رد</button>`;
    } else if (t.status === 'approved'){
        actions = `<button class="ax-btn ax-btn-accounts" onclick="axOpenAccounts(${t.id})"><i class="fas fa-paper-plane"></i> ارسال شماره‌حساب</button>`;
    } else if (t.status === 'awaiting_payment'){
        actions = `<button class="ax-btn ax-btn-accounts" onclick="axOpenAccounts(${t.id})"><i class="fas fa-edit"></i> ویرایش شماره‌حساب</button>
                   <span style="align-self:center;font-size:.72rem;color:#ff9800;">در انتظار پرداخت کاربر</span>`;
    } else if (t.status === 'payment_submitted'){
        actions = `<button class="ax-btn ax-btn-settle" onclick="axOpenSettleTransfer(${t.id})"><i class="fas fa-handshake"></i> تسویه نهایی</button>`;
    }

    // دکمه‌های آرشیو/حذف: برای موارد تکمیل/ردشده یا حالت آرشیو
    if (Number(t.archived) === 1) {
        actions += `<button class="ax-btn ax-btn-view" onclick="axUnarchive('transfer', ${t.id})"><i class="fas fa-box-open"></i> خروج از آرشیو</button>
                    <button class="ax-btn ax-btn-reject" onclick="axDeleteReq('transfer', ${t.id})"><i class="fas fa-trash"></i> حذف</button>`;
    } else if (t.status === 'completed' || t.status === 'rejected') {
        actions += `<button class="ax-btn ax-btn-view" onclick="axArchive('transfer', ${t.id})"><i class="fas fa-box-archive"></i> آرشیو</button>
                    <button class="ax-btn ax-btn-reject" onclick="axDeleteReq('transfer', ${t.id})"><i class="fas fa-trash"></i> حذف</button>`;
    }

    return `<div class="ax-item" id="ax-transfer-${t.id}">
        <div class="ax-item-head">
            <span class="ax-code">${axEsc(t.tracking_code)}</span>
            ${axTransferBadge(t.status)}
        </div>
        <div class="ax-grid">
            <div>کاربر: <b>${axEsc((t.first_name||'')+' '+(t.last_name||''))}</b></div>
            <div>کشور: <b>${axEsc(t.country)}</b></div>
            <div>مبلغ: <b>${axNum(t.amount)} ${axEsc(t.currency)}</b></div>
            <div>گیرنده: <b>${axEsc(t.full_name)}</b></div>
            <div style="grid-column:1/-1;">IBAN: <b>${axEsc(t.iban)}</b> — ${axEsc(t.bank_name)}</div>
        </div>
        ${accountsHtml}
        ${receiptsHtml}
        <div class="ax-actions">${actions}</div>
    </div>`;
}

async function axTransferPost(payload){
    const res = await fetch(AX_TRANSFER_ACT + '?action=' + payload.action, {
        method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)
    });
    return res.json();
}

async function axApproveTransfer(id){
    const d = await axTransferPost({ action:'admin_update_status', request_id:id, status:'approved' });
    if (d.success){ showAdminToast('تایید شد. اکنون شماره‌حساب بفرستید', 'success'); axReloadCurrentTransfers(); }
    else showAdminToast(d.message||'خطا', 'error');
}
async function axRejectTransfer(id){
    const reason = prompt('دلیل رد درخواست:');
    if (reason === null) return;
    const d = await axTransferPost({ action:'admin_update_status', request_id:id, status:'rejected', reject_reason: reason });
    if (d.success){ showAdminToast('رد شد', 'success'); axReloadCurrentTransfers(); }
    else showAdminToast(d.message||'خطا', 'error');
}

/* مودال ارسال چند شماره‌کارت (نام صاحب کارت + شماره کارت) */
function axOpenAccounts(id){
    let overlay = document.getElementById('axAccountsOverlay');
    if (overlay) overlay.remove();
    overlay = document.createElement('div');
    overlay.id = 'axAccountsOverlay';
    overlay.style = 'position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:99999;display:flex;align-items:center;justify-content:center;padding:16px;';
    overlay.innerHTML = `
        <div style="background:#1a1a2e;border-radius:20px;padding:20px;max-width:460px;width:100%;border:1px solid rgba(255,255,255,0.1);max-height:88vh;overflow-y:auto;">
            <h3 style="color:#fff;margin:0 0 14px;font-size:1rem;"><i class="fas fa-paper-plane"></i> ارسال شماره‌کارت برای واریز</h3>
            ${axSavedAccPickerHtml('axSavedAcc_transfer', 'transfer')}
            <div id="axAccRows"></div>
            <button class="ax-btn ax-btn-view" onclick="axAddAccRow()"><i class="fas fa-plus"></i> افزودن کارت دیگر</button>
            <div style="display:flex;gap:8px;margin-top:16px;">
                <button class="ax-btn ax-btn-accounts" style="flex:1;justify-content:center;" onclick="axSendAccounts(${id})"><i class="fas fa-check"></i> ارسال به کاربر</button>
                <button class="ax-btn ax-btn-view" onclick="document.getElementById('axAccountsOverlay').remove()">انصراف</button>
            </div>
        </div>`;
    document.body.appendChild(overlay);
    axSavedAccLoad('axSavedAcc_transfer');
    axAddAccRow(); // یک ردیف اولیه
}
function axAddAccRow(name, card){
    const wrap = document.getElementById('axAccRows');
    const div = document.createElement('div');
    div.style = 'background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:10px;margin-bottom:10px;position:relative;';
    div.innerHTML = `
        <div style="display:flex;flex-direction:column;gap:8px;">
            <input type="text" class="ax-acc-name" placeholder="نام و نام خانوادگی صاحب کارت" value="${axEsc(name)}" style="padding:9px 11px;border-radius:8px;background:rgba(0,0,0,0.25);border:1px solid rgba(255,255,255,0.12);color:#fff;font-family:inherit;font-size:.82rem;">
            <input type="text" class="ax-acc-card" placeholder="شماره کارت/شبا/آدرس کیف پول" value="${axEsc(card)}" style="padding:9px 11px;border-radius:8px;background:rgba(0,0,0,0.25);border:1px solid rgba(255,255,255,0.12);color:#fff;font-family:inherit;font-size:.82rem;direction:ltr;text-align:right;">
        </div>
        <button class="ax-btn ax-btn-reject" style="position:absolute;top:8px;left:8px;padding:4px 8px;" onclick="this.parentElement.remove()"><i class="fas fa-trash"></i></button>`;
    wrap.appendChild(div);
}
async function axSendAccounts(id){
    const rows = document.querySelectorAll('#axAccRows > div');
    const accounts = [];
    rows.forEach(r=>{
        const name = (r.querySelector('.ax-acc-name')?.value || '').trim();
        const card = (r.querySelector('.ax-acc-card')?.value || '').trim();
        if (name || card) accounts.push({ name, card });
    });
    if (!accounts.length || !accounts.some(a=>a.card)){ showAdminToast('حداقل یک شماره‌کارت وارد کنید', 'error'); return; }
    const d = await axTransferPost({ action:'admin_send_accounts', request_id:id, accounts });
    if (d.success){ showAdminToast('شماره‌کارت‌ها ارسال شد', 'success'); document.getElementById('axAccountsOverlay').remove(); axReloadCurrentTransfers(); }
    else showAdminToast(d.message||'خطا', 'error');
}

/* تسویه نهایی حواله (آپلود فیش تسویه توسط ادمین) */
function axOpenSettleTransfer(id){
    let overlay = document.getElementById('axSettleOverlay');
    if (overlay) overlay.remove();
    overlay = document.createElement('div');
    overlay.id = 'axSettleOverlay';
    overlay.style = 'position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:99999;display:flex;align-items:center;justify-content:center;padding:16px;';
    overlay.innerHTML = `
        <div style="background:#1a1a2e;border-radius:20px;padding:20px;max-width:420px;width:100%;border:1px solid rgba(255,255,255,0.1);">
            <h3 style="color:#fff;margin:0 0 14px;font-size:1rem;"><i class="fas fa-handshake"></i> تسویه نهایی حواله</h3>
            <div style="font-size:.75rem;color:rgba(255,255,255,0.6);margin-bottom:6px;">می‌توانید چند فیش (تصویر/PDF) انتخاب کنید</div>
            <input type="file" id="axSettleFile" accept="image/*,.pdf" multiple style="width:100%;color:#fff;margin-bottom:14px;">
            <div style="display:flex;gap:8px;">
                <button class="ax-btn ax-btn-settle" style="flex:1;justify-content:center;" onclick="axSubmitSettleTransfer(${id})"><i class="fas fa-check"></i> تکمیل تراکنش</button>
                <button class="ax-btn ax-btn-view" onclick="document.getElementById('axSettleOverlay').remove()">انصراف</button>
            </div>
        </div>`;
    document.body.appendChild(overlay);
}
async function axSubmitSettleTransfer(id){
    const files = document.getElementById('axSettleFile').files;
    if (!files.length){ showAdminToast('حداقل یک فایل انتخاب کنید', 'error'); return; }
    const btn = document.querySelector('#axSettleOverlay .ax-btn-settle');
    const orig = btn ? btn.innerHTML : '';
    if (btn){ btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> فشرده‌سازی...'; }
    try {
        let out = Array.prototype.slice.call(files);
        if (typeof window.AvaCompressFiles === 'function'){ try { out = await window.AvaCompressFiles(files); } catch(e){} }
        if (btn){ btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ارسال...'; }
        const fd = new FormData();
        fd.append('request_id', id);
        out.forEach(function(f){ fd.append('file[]', f, f.name || 'settlement.jpg'); });
        if (typeof window.avaShowUploadProgress === 'function') window.avaShowUploadProgress('در حال آپلود فیش تسویه...');
        const d = (typeof window.avaUploadWithProgress === 'function')
            ? await window.avaUploadWithProgress(AX_TRANSFER_ACT + '?action=upload_settlement', fd)
            : await (await fetch(AX_TRANSFER_ACT + '?action=upload_settlement', { method:'POST', body: fd })).json();
        if (typeof window.avaHideUploadProgress === 'function') window.avaHideUploadProgress(!!d.success, d.message);
        if (d.success){ showAdminToast('تسویه انجام شد', 'success'); document.getElementById('axSettleOverlay').remove(); axReloadCurrentTransfers(); }
        else { showAdminToast(d.message||'خطا', 'error'); if (btn){ btn.disabled=false; btn.innerHTML = orig; } }
    } catch(e){ if (typeof window.avaHideUploadProgress === 'function') window.avaHideUploadProgress(false); showAdminToast('خطا در ارسال', 'error'); if (btn){ btn.disabled=false; btn.innerHTML = orig; } }
}
function axReloadCurrentTransfers(){
    const active = document.querySelector('[data-tfilter].active');
    axLoadTransfers(active ? active.dataset.tfilter : 'all');
    axRefreshOverviewCounts();
}

/* ---------- آرشیو / حذف درخواست‌ها ---------- */
async function axArchiveApi(actionName, type, id){
    return (await fetch(AX_TRANSFER_API + '?action=' + actionName, {
        method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ type, id })
    })).json();
}
function axReloadByType(type){
    if (type === 'transfer') axReloadCurrentTransfers();
    else if (type === 'settlement') axReloadCurrentSettlements();
    else if (type === 'deal') { const a = document.querySelector('[data-dfilter].active'); axLoadDeals(a ? a.dataset.dfilter : 'pending'); axRefreshOverviewCounts(); }
}
async function axArchive(type, id){
    const d = await axArchiveApi('archive_request', type, id);
    if (d.success){ showAdminToast('به آرشیو منتقل شد', 'success'); axReloadByType(type); }
    else showAdminToast(d.message||'خطا', 'error');
}
async function axUnarchive(type, id){
    const d = await axArchiveApi('unarchive_request', type, id);
    if (d.success){ showAdminToast('از آرشیو خارج شد', 'success'); axReloadByType(type); }
    else showAdminToast(d.message||'خطا', 'error');
}
async function axDeleteReq(type, id){
    if (!confirm('این درخواست برای همیشه حذف شود؟ این عمل قابل بازگشت نیست.')) return;
    const d = await axArchiveApi('delete_request', type, id);
    if (d.success){ showAdminToast('حذف شد', 'success'); axReloadByType(type); }
    else showAdminToast(d.message||'خطا', 'error');
}

/* ---------- تسویه حساب (withdrawals) ---------- */
window.axSettlementsCache = {};
async function axLoadSettlements(filter, btn){
    if (btn){ document.querySelectorAll('[data-sfilter]').forEach(b=>b.classList.remove('active')); btn.classList.add('active'); }
    const box = document.getElementById('axSettlementsList');
    box.innerHTML = '<div class="ax-empty"><i class="fas fa-spinner fa-spin"></i> در حال بارگذاری...</div>';
    const actionMap = { pending:'get_pending', approved:'get_approved', completed:'get_completed', archived:'get_completed' };
    let url = AX_WITHDRAW_API + '?action=' + actionMap[filter];
    if (filter === 'archived') url += '&view=archived';
    try {
        const res = await fetch(url, { cache:'no-store' });
        const data = await res.json();
        const list = data.requests || data.data || [];
        if (!list.length){ box.innerHTML = '<div class="ax-empty">درخواستی یافت نشد</div>'; return; }
        list.forEach(w => { window.axSettlementsCache[w.id] = w; });
        box.innerHTML = list.map(w=>axRenderSettlement(w, filter)).join('');
    } catch(e){ box.innerHTML = '<div class="ax-empty">خطا در ارتباط</div>'; }
}
function axRenderSettlement(w, filter){
    let actions = '';
    if (filter === 'pending'){
        actions = `<button class="ax-btn ax-btn-approve" onclick="axApproveSettlement(${w.id})"><i class="fas fa-check"></i> تایید</button>
                   <button class="ax-btn ax-btn-reject" onclick="axRejectSettlement(${w.id})"><i class="fas fa-times"></i> رد</button>`;
    } else if (filter === 'approved'){
        actions = `<button class="ax-btn ax-btn-settle" onclick="axUploadSettlementReceipt(${w.id})"><i class="fas fa-upload"></i> آپلود فیش</button>`;
    } else if (filter === 'completed' || filter === 'archived'){
        if (Number(w.archived) === 1) {
            actions = `<button class="ax-btn ax-btn-view" onclick="axUnarchive('settlement', ${w.id})"><i class="fas fa-box-open"></i> خروج از آرشیو</button>
                       <button class="ax-btn ax-btn-reject" onclick="axDeleteReq('settlement', ${w.id})"><i class="fas fa-trash"></i> حذف</button>`;
        } else {
            actions = `<button class="ax-btn ax-btn-view" onclick="axArchive('settlement', ${w.id})"><i class="fas fa-box-archive"></i> آرشیو</button>
                       <button class="ax-btn ax-btn-reject" onclick="axDeleteReq('settlement', ${w.id})"><i class="fas fa-trash"></i> حذف</button>`;
        }
    }
    // (جدید) فوروارد حسابی که این کاربر برای دریافت وجه فرستاده، به یک کاربر
    // دیگر — برای وقتی ادمین می‌خواهد کاربر B مستقیماً به حساب کاربر A واریز کند
    if (filter === 'pending' || filter === 'approved') {
        actions += `<button class="ax-btn ax-btn-view" onclick="axForwardSettlementToUser(${w.id})"><i class="fas fa-share-nodes"></i> فوروارد این حساب به کاربر دیگر</button>`;
    }
    const badge = filter==='pending'?axTransferBadge('pending'):filter==='approved'?axTransferBadge('approved'):axTransferBadge('completed');
    let receiptsHtml = '';
    let receiptPaths = [];
    if (w.receipt_files){
        try { receiptPaths = JSON.parse(w.receipt_files) || []; } catch(e){ receiptPaths = []; }
    }
    if (!receiptPaths.length && w.receipt_file) receiptPaths = [w.receipt_file];
    if (receiptPaths.length){
        receiptsHtml = '<div class="ax-thumbs">' + receiptPaths.map(r=>{
            const url = '/ledor/' + r;
            const isPdf = /\.pdf$/i.test(r);
            return isPdf
                ? `<a href="${url}" target="_blank" class="ax-btn ax-btn-view"><i class="fas fa-file-pdf"></i> PDF</a>`
                : `<a href="${url}" target="_blank"><img src="${url}"></a>`;
        }).join('') + '</div>';
    }
    return `<div class="ax-item" id="ax-settle-${w.id}">
        <div class="ax-item-head">
            <span class="ax-code">#${w.id}</span>${badge}
        </div>
        <div class="ax-grid">
            <div>کاربر: <b>${axEsc((w.first_name||'')+' '+(w.last_name||''))}</b></div>
            <div>مبلغ: <b>${axNum(w.amount)} ${axEsc(w.currency||'تومان')}</b></div>
            <div>گیرنده: <b>${axEsc(w.recipient_name||'-')}</b></div>
            <div>بانک: <b>${axEsc(w.bank_name||'-')}</b></div>
            ${w.card_number?`<div style="grid-column:1/-1;">شماره کارت: <b>${axEsc(w.card_number)}</b></div>`:''}
            ${w.iban_number?`<div style="grid-column:1/-1;">شماره شبا: <b>${axEsc(w.iban_number)}</b></div>`:''}
            ${w.target_user_name?`<div style="grid-column:1/-1;">انتقال به کاربر: <b>${axEsc(w.target_user_name)}</b></div>`:''}
            ${w.notes?`<div style="grid-column:1/-1;">توضیحات کاربر: ${axEsc(w.notes)}</div>`:''}
        </div>
        ${receiptsHtml}
        <div class="ax-actions">${actions}</div>
    </div>`;
}
async function axApproveSettlement(id){
    const res = await fetch(AX_WITHDRAW_API + '?action=approve', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ withdrawal_id:id, notes:'تایید شده توسط ادمین' }) });
    const d = await res.json();
    if (d.success){ showAdminToast('تایید شد', 'success'); axReloadCurrentSettlements(); }
    else showAdminToast(d.message||'خطا', 'error');
}
async function axRejectSettlement(id){
    const reason = prompt('دلیل رد:');
    if (reason === null || reason.trim()==='') return;
    const res = await fetch(AX_WITHDRAW_API + '?action=reject', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ withdrawal_id:id, notes:reason }) });
    const d = await res.json();
    if (d.success){ showAdminToast('رد شد', 'success'); axReloadCurrentSettlements(); }
    else showAdminToast(d.message||'خطا', 'error');
}

/* (جدید) حسابی که کاربرِ درخواست‌دهنده برای دریافت وجه فرستاده (نام/بانک/
   کارت/شبا) و مبلغِ همین درخواست را در فرمِ استاندارد «ایجاد فیش جدید» از
   پیش پر می‌کند — ادمین فقط کاربرِ گیرنده‌ی این فیش را (کاربر دیگر) انتخاب
   می‌کند و می‌فرستد؛ یعنی کاربر دیگر مستقیم به حساب همین کاربر واریز می‌کند. */
function axForwardSettlementToUser(id){
    const w = window.axSettlementsCache && window.axSettlementsCache[id];
    if (!w){ showAdminToast('اطلاعات این درخواست در دسترس نیست', 'error'); return; }
    openCreateInvoice();
    const set = (elId, v) => { const el = document.getElementById(elId); if (el) el.value = v || ''; };
    const cur = (!w.currency || w.currency === 'تومان') ? 'IRR' : w.currency;
    set('inv_currency', cur);
    set('inv_amount', w.amount);
    set('inv_bank_name', w.bank_name);
    set('inv_card_number', w.card_number);
    set('inv_iban', w.iban_number);
    set('inv_recipient_name', w.recipient_name);
    const reqName = ((w.first_name||'')+' '+(w.last_name||'')).trim();
    set('inv_description', 'فوروارد حسابِ درخواست تسویه‌ی #' + w.id + (reqName?(' (کاربر '+reqName+')'):'') + ' — واریز مستقیم به همین حساب');
    showAdminToast('اطلاعات حساب پر شد؛ حالا کاربری که باید پرداخت کند را انتخاب کنید', 'success');
}

/* ====================================================================
   خرید مستقیم (direct_buy.php) — درخواست‌های ثبت‌شده از نمودار کاربر
   ==================================================================== */
window.axDirectBuyPendingLinkId = null;

async function axLoadDirectBuy(filter, btn){
    if (btn){ document.querySelectorAll('[data-dbfilter]').forEach(b=>b.classList.remove('active')); btn.classList.add('active'); }
    const box = document.getElementById('axDirectBuyList');
    box.innerHTML = '<div class="ax-empty"><i class="fas fa-spinner fa-spin"></i> در حال بارگذاری...</div>';
    try {
        const res = await fetch(AX_DIRECTBUY_API + '?action=admin_list&status=' + encodeURIComponent(filter), { cache:'no-store' });
        const data = await res.json();
        const list = data.requests || [];
        if (!list.length){ box.innerHTML = '<div class="ax-empty">درخواستی یافت نشد</div>'; return; }
        box.innerHTML = list.map(r=>axRenderDirectBuy(r)).join('');
    } catch(e){ box.innerHTML = '<div class="ax-empty">خطا در ارتباط</div>'; }
}

const AX_DB_STATUS_LABEL = {
    pending: ['در انتظار بررسی', '#FFD700'], available: ['موجود شد', '#38bdf8'],
    invoiced: ['صورت‌حساب صادر شد', '#22C55E'], completed: ['تکمیل شد', '#22C55E'], cancelled: ['لغو شد', '#ff4d6d']
};

function axRenderDirectBuy(r){
    const st = AX_DB_STATUS_LABEL[r.status] || [r.status, '#999'];
    const badge = `<span class="ax-badge" style="background:${st[1]}22;color:${st[1]};border-color:${st[1]}55;">${st[0]}</span>`;

    let actions = '';
    if (r.status === 'pending'){
        actions = `<button class="ax-btn ax-btn-approve" onclick="axDirectBuyMarkAvailable(${r.id})"><i class="fas fa-check"></i> موجود است</button>
                   <button class="ax-btn ax-btn-reject" onclick="axDirectBuyCancel(${r.id})"><i class="fas fa-times"></i> لغو</button>`;
    } else if (r.status === 'available'){
        actions = `<button class="ax-btn ax-btn-settle" onclick="axDirectBuyOpenInvoice(${r.id}, ${r.user_id}, '${axEsc(r.currency)}', ${r.amount}, ${r.total_toman})"><i class="fas fa-file-invoice"></i> صدور صورت‌حساب</button>
                   <button class="ax-btn ax-btn-reject" onclick="axDirectBuyCancel(${r.id})"><i class="fas fa-times"></i> لغو</button>`;
    }

    return `<div class="ax-item" id="ax-directbuy-${r.id}">
        <div class="ax-item-head">
            <span class="ax-code">#${r.id}</span>${badge}
        </div>
        <div class="ax-grid">
            <div>کاربر: <b>${axEsc((r.first_name||'')+' '+(r.last_name||''))}</b></div>
            <div>تلگرام: <b>${axEsc(r.telegram_id||'-')}</b></div>
            <div>ارز: <b>${axEsc(r.currency)}</b></div>
            <div>مقدار: <b>${axNum(r.amount)} ${axEsc(r.currency)}</b></div>
            <div>قیمت هدف: <b>${axNum(r.target_price)} تومان</b></div>
            <div>جمع کل: <b>${axNum(r.total_toman)} تومان</b></div>
            ${r.admin_note ? `<div style="grid-column:1/-1;">یادداشت ادمین: ${axEsc(r.admin_note)}</div>` : ''}
        </div>
        <div class="ax-actions">${actions}</div>
    </div>`;
}

async function axDirectBuyMarkAvailable(id){
    const note = prompt('یادداشت (اختیاری):') || '';
    const res = await fetch(AX_DIRECTBUY_API + '?action=admin_mark_available', {
        method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ id, note })
    });
    const d = await res.json();
    if (d.success){
        showAdminToast('کاربر مطلع شد که ارز موجود است', 'success');
        const activeBtn = document.querySelector('[data-dbfilter].active');
        axLoadDirectBuy(activeBtn ? activeBtn.dataset.dbfilter : 'pending');
        const badgeEl = document.getElementById('directbuyBadge');
        if (badgeEl){ const n = Math.max(0, (parseInt(badgeEl.textContent,10)||1) - 1); if (n>0){ badgeEl.textContent = n; } else badgeEl.style.display='none'; }
    } else showAdminToast(d.message||'خطا', 'error');
}

async function axDirectBuyCancel(id){
    const reason = prompt('دلیل لغو (اختیاری):') || '';
    const res = await fetch(AX_DIRECTBUY_API + '?action=admin_cancel', {
        method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ id, reason })
    });
    const d = await res.json();
    if (d.success){
        showAdminToast('درخواست لغو شد', 'success');
        const activeBtn = document.querySelector('[data-dbfilter].active');
        axLoadDirectBuy(activeBtn ? activeBtn.dataset.dbfilter : 'pending');
        const badgeEl = document.getElementById('directbuyBadge');
        if (badgeEl){ const n = Math.max(0, (parseInt(badgeEl.textContent,10)||1) - 1); if (n>0){ badgeEl.textContent = n; } else badgeEl.style.display='none'; }
    } else showAdminToast(d.message||'خطا', 'error');
}

/* پرکردن خودکار مودال «ایجاد فیش جدید» موجود از روی یک درخواست خرید مستقیم،
   به‌جای ساختن یک سیستم صورت‌حساب جداگانه — بعد از ساخت موفق فیش (در
   createInvoiceForm submit handler)، درخواست به همان فیش وصل می‌شود.
   نکته‌ی مهم (اصلاح‌شده): «تکمیل فیش» (finalize_invoice) دقیقاً همان
   currency و amount ثبت‌شده روی فیش را به کیف‌پول کاربر اضافه می‌کند — پس
   فیش باید با ارز و مقدار واقعیِ درخواست (مثلاً ۱۰۰ EUR) ساخته شود، نه با
   IRR و مبلغ تومانی، وگرنه به‌جای ارز، تومان به حساب کاربر اضافه می‌شود.
   مبلغ تومانیِ قابل واریز (که کاربر باید کارت‌به‌کارت کند) در توضیحات فیش
   نوشته می‌شود تا هم برای کاربر روشن باشد هم اعتبار کیف‌پول درست بماند. */
function axDirectBuyOpenInvoice(reqId, userId, currency, amount, totalToman){
    window.axDirectBuyPendingLinkId = reqId;
    const userSel = document.getElementById('inv_user_id');
    if (userSel) userSel.value = String(userId);
    const curSel = document.getElementById('inv_currency');
    if (curSel) curSel.value = currency;
    const amtInput = document.getElementById('inv_amount');
    if (amtInput) amtInput.value = (Math.round(Number(amount) * 100) / 100).toFixed(2);
    const descInput = document.getElementById('inv_description');
    if (descInput) descInput.value = 'مبلغ قابل واریز: ' + axNum(totalToman) + ' تومان — بابت خرید مستقیم ' + amount + ' ' + currency + ' (درخواست #' + reqId + ')';
    openModal('create-invoice-modal');
}

function axUploadSettlementReceipt(id){
    let overlay = document.getElementById('axWReceiptOverlay');
    if (overlay) overlay.remove();
    overlay = document.createElement('div');
    overlay.id = 'axWReceiptOverlay';
    overlay.style = 'position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:99999;display:flex;align-items:center;justify-content:center;padding:16px;';
    overlay.innerHTML = `
        <div style="background:#1a1a2e;border-radius:20px;padding:20px;max-width:420px;width:100%;border:1px solid rgba(255,255,255,0.1);">
            <h3 style="color:#fff;margin:0 0 14px;font-size:1rem;"><i class="fas fa-upload"></i> آپلود فیش‌های تسویه</h3>
            <div style="font-size:.75rem;color:rgba(255,255,255,0.6);margin-bottom:6px;">پرداخت از کدام کارت شرکت انجام شده؟</div>
            <select id="axWCardSelect" style="width:100%;padding:9px;border-radius:8px;background:rgba(0,0,0,0.25);border:1px solid rgba(255,255,255,0.12);color:#fff;margin-bottom:12px;"><option value="">در حال بارگذاری کارت‌ها...</option></select>
            <div style="font-size:.75rem;color:rgba(255,255,255,0.6);margin-bottom:6px;">می‌توانید چند فیش (تصویر/PDF) انتخاب کنید</div>
            <input type="file" id="axWReceiptFile" accept="image/*,.pdf" multiple style="width:100%;color:#fff;margin-bottom:14px;">
            <input type="text" id="axWTransactionId" placeholder="شماره پیگیری/تراکنش (اختیاری)" style="width:100%;padding:9px;border-radius:8px;background:rgba(0,0,0,0.25);border:1px solid rgba(255,255,255,0.12);color:#fff;margin-bottom:14px;">
            <div style="display:flex;gap:8px;">
                <button class="ax-btn ax-btn-settle" style="flex:1;justify-content:center;" onclick="axSubmitSettlementReceipt(${id})"><i class="fas fa-check"></i> ثبت و تکمیل</button>
                <button class="ax-btn ax-btn-view" onclick="document.getElementById('axWReceiptOverlay').remove()">انصراف</button>
            </div>
        </div>`;
    document.body.appendChild(overlay);
    axFillCompanyCards();
}
async function axFillCompanyCards(){
    const sel = document.getElementById('axWCardSelect');
    if (!sel) return;
    try {
        const res = await fetch('api/cards.php?action=get_cards', { cache:'no-store' });
        const d = await res.json();
        const cards = d.data || d.cards || [];
        sel.innerHTML = '<option value="">-- انتخاب نشود --</option>' +
            cards.map(c => `<option value="${c.id}">${axEsc(c.card_owner||'')} — ${axEsc(c.card_number||'')} (${axEsc(c.bank_name||'')})</option>`).join('');
    } catch(e){ sel.innerHTML = '<option value="">خطا در دریافت کارت‌ها</option>'; }
}
async function axSubmitSettlementReceipt(id){
    const files = document.getElementById('axWReceiptFile').files;
    if (!files.length){ showAdminToast('حداقل یک فایل انتخاب کنید', 'error'); return; }
    const cardId = document.getElementById('axWCardSelect').value;
    const transactionId = document.getElementById('axWTransactionId').value.trim();
    const btn = document.querySelector('#axWReceiptOverlay .ax-btn-settle') || document.querySelector('#axWReceiptOverlay button.ax-btn');
    const orig = btn ? btn.innerHTML : '';
    if (btn){ btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> فشرده‌سازی...'; }
    try {
        let out = Array.prototype.slice.call(files);
        if (typeof window.AvaCompressFiles === 'function'){ try { out = await window.AvaCompressFiles(files); } catch(e){} }
        if (btn){ btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ارسال...'; }
        const fd = new FormData();
        fd.append('withdrawal_id', id);
        out.forEach(function(f){ fd.append('receipts[]', f, f.name || 'receipt.jpg'); });
        if (cardId) fd.append('card_id', cardId);
        if (transactionId) fd.append('transaction_id', transactionId);
        if (typeof window.avaShowUploadProgress === 'function') window.avaShowUploadProgress('در حال آپلود فیش...');
        const d = (typeof window.avaUploadWithProgress === 'function')
            ? await window.avaUploadWithProgress(AX_WITHDRAW_API + '?action=upload_receipt', fd)
            : await (await fetch(AX_WITHDRAW_API + '?action=upload_receipt', { method:'POST', body: fd })).json();
        if (typeof window.avaHideUploadProgress === 'function') window.avaHideUploadProgress(!!d.success, d.message);
        if (d.success){ showAdminToast('تسویه تکمیل شد', 'success'); document.getElementById('axWReceiptOverlay').remove(); axReloadCurrentSettlements(); }
        else { showAdminToast(d.message||'خطا', 'error'); if (btn){ btn.disabled=false; btn.innerHTML = orig; } }
    } catch(e){ if (typeof window.avaHideUploadProgress === 'function') window.avaHideUploadProgress(false); showAdminToast('خطا در ارسال', 'error'); if (btn){ btn.disabled=false; btn.innerHTML = orig; } }
}
function axReloadCurrentSettlements(){
    const active = document.querySelector('[data-sfilter].active');
    axLoadSettlements(active ? active.dataset.sfilter : 'pending');
    axRefreshOverviewCounts();
}

/* ---------- معاملات تازه‌توافق‌شده (ad_deals) ----------
   وقتی آگهی‌دهنده و متقاضی به توافق می‌رسند (پیشنهاد پذیرفته می‌شود)، یک
   ردیف این‌جا می‌آید: فقط می‌گوید «چه کاربری با چه کاربری، سرِ چه قیمت و
   چه مقداری» توافق کرده‌اند. زدن روی نامِ هرکدام، فرمِ استاندارد «ایجاد فیش
   جدید» را (با نوعِ از پیش‌انتخاب‌شده‌ی «تبادل ارزی») برای همان کاربر باز
   می‌کند تا ادمین حساب برایش بفرستد — همان فیش، در کارتِ زیرین
   («معاملات در انتظار تسویه») هم دیده می‌شود. «تسویه این تراکنش» هم معامله
   را می‌بندد و یک معامله‌ی موفق به کارنامه‌ی هر دو طرف اضافه می‌کند (این
   شمارش خودکار و زنده از روی معاملاتِ completed انجام می‌شود). */
async function axLoadDeals(filter, btn){
    if (btn){ document.querySelectorAll('[data-dfilter]').forEach(b=>b.classList.remove('active')); btn.classList.add('active'); }
    const box = document.getElementById('axDealsList');
    box.innerHTML = '<div class="ax-empty"><i class="fas fa-spinner fa-spin"></i> در حال بارگذاری...</div>';
    try {
        const res = await fetch(AX_TRANSFER_API + '?action=list_deals&filter=' + encodeURIComponent(filter), { cache:'no-store' });
        const data = await axJson(res);
        if (!data.success){ box.innerHTML = '<div class="ax-empty">'+axEsc(data.message||'خطا')+'</div>'; return; }
        if (!data.deals || !data.deals.length){ box.innerHTML = '<div class="ax-empty">معامله‌ای یافت نشد</div>'; return; }
        box.innerHTML = data.deals.map(d=>axRenderDeal(d, filter)).join('');
    } catch(e){ box.innerHTML = '<div class="ax-empty">خطا در ارتباط</div>'; }
}
function axSideChip(d, side){
    const s = d[side + '_side'] || {};
    const label = side === 'buyer' ? 'متقاضی' : 'آگهی‌دهنده';
    const st = s.status || 'new';
    const map = { new:'در انتظار', awaiting_payment:'در انتظار پرداخت', receipt_submitted:'فیش دریافت شد', completed:'تکمیل شد' };
    const cls = st==='completed' ? 'done' : (st==='receipt_submitted' ? 'hot' : (st==='awaiting_payment' ? 'wait' : 'new'));
    let extra = '';
    if (Number(s.receipts_count) > 0) extra += ` <i class="fas fa-receipt"></i>${s.receipts_count}`;
    return `<span class="ax-sidechip ${cls}">${label}: ${map[st] || st}${extra}</span>`;
}
function axRenderDeal(d, filter){
    let actions = '';
    if (filter === 'pending') {
        actions += `<button class="ax-btn ax-btn-settle" style="width:100%;justify-content:center;" onclick="axFinalizeDeal(${d.id})"><i class="fas fa-flag-checkered"></i> تسویه این تراکنش</button>`;
    }
    if (Number(d.archived) === 1) {
        actions += `<button class="ax-btn ax-btn-view" onclick="axUnarchive('deal', ${d.id})"><i class="fas fa-box-open"></i> خروج از آرشیو</button>
                    <button class="ax-btn ax-btn-reject" onclick="axDeleteReq('deal', ${d.id})"><i class="fas fa-trash"></i> حذف</button>`;
    } else if (filter === 'completed') {
        actions += `<button class="ax-btn ax-btn-view" onclick="axArchive('deal', ${d.id})"><i class="fas fa-box-archive"></i> آرشیو</button>
                    <button class="ax-btn ax-btn-reject" onclick="axDeleteReq('deal', ${d.id})"><i class="fas fa-trash"></i> حذف</button>`;
    }
    const hot = (d.buyer_side && d.buyer_side.status === 'receipt_submitted') ||
                (d.seller_side && d.seller_side.status === 'receipt_submitted');
    const buyerName  = ((d.buyer_first_name||'')+' '+(d.buyer_last_name||'')).trim() || 'بدون‌نام';
    const sellerName = ((d.seller_first_name||'')+' '+(d.seller_last_name||'')).trim() || 'بدون‌نام';
    const partyRow = (side, name, roleLabel, userId) => `
        <div class="ax-party-row" onclick="axOpenInvoiceForDealUser(${userId}, '${axEsc(name).replace(/'/g,"\\'")}', '${axEsc(d.currency)}', ${Number(d.total_price)||0}, '${axEsc(d.deal_code)}', ${d.id}, '${side}')">
            <span class="ax-party-info"><i class="fas ${side==='buyer'?'fa-user':'fa-store'}"></i> ${axEsc(name)} <span class="ax-party-role">(${roleLabel})</span></span>
            ${axSideChip(d, side)}
            <i class="fas fa-file-invoice ax-party-arrow" title="ارسال صورتحساب"></i>
        </div>`;
    return `<div class="ax-item ${hot?'ax-item-hot':''}" id="ax-deal-${d.id}">
        <div class="ax-item-head">
            <span class="ax-code">${axEsc(d.deal_code)}</span>
            ${filter==='completed'?axTransferBadge('completed'):axTransferBadge('pending')}
        </div>
        <div class="ax-grid" style="margin-bottom:12px;">
            <div>ارز: <b>${axEsc(d.currency)}</b></div>
            <div>مقدار: <b>${axNum(d.amount)}</b></div>
            <div style="grid-column:1/-1;">قیمتِ توافق‌شده: <b>${axNum(d.total_price)} تومان</b></div>
        </div>
        ${partyRow('buyer', buyerName, 'خریدار', d.buyer_id)}
        ${partyRow('seller', sellerName, 'فروشنده', d.seller_id)}
        ${hot?'<div class="ax-sidechip hot" style="margin-top:8px;display:inline-block;"><i class="fas fa-bell"></i> فیش جدید در انتظار بررسی</div>':''}
        <div class="ax-actions" style="margin-top:10px;">${actions}</div>
    </div>`;
}
/* همان فرمِ استاندارد «ایجاد فیش جدید» را با کاربر/ارز/مبلغِ همان معامله از
   پیش پر می‌کند و نوعش را هم روی «تبادل ارزی» می‌گذارد. */
function axOpenInvoiceForDealUser(userId, userName, currency, totalPrice, dealCode, dealId, dealSide){
    openCreateInvoice();
    const userSel = document.getElementById('inv_user_id');
    if (userSel && userId) userSel.value = String(userId);
    const curSel = document.getElementById('inv_currency');
    if (curSel) curSel.value = 'IRR';
    const amtEl = document.getElementById('inv_amount');
    if (amtEl && totalPrice) amtEl.value = totalPrice;
    const srcTypeEl = document.getElementById('inv_source_type');
    if (srcTypeEl) srcTypeEl.value = 'exchange';
    const descEl = document.getElementById('inv_description');
    if (descEl) descEl.value = 'پرداخت معامله‌ی تبادل ارزی ' + (dealCode || '') + (userName ? (' — ' + userName) : '');
    // (جدید) این فیش را به همین معامله/طرف وصل کن تا کاربر بتواند مستقیم از
    // صفحه‌ی خودِ معامله (نه فقط از داشبورد) پرداخت و فیش را آپلود کند
    window.__axInvoiceDealLink = { deal_id: dealId || null, deal_side: dealSide || null };
}
async function axFinalizeDeal(dealId, force){
    if (!dealId){ showAdminToast('معامله انتخاب نشده است', 'error'); return; }
    if (!force && !confirm('این تراکنش تسویه شود؟ معامله از «در انتظار» خارج و به‌عنوان پرداخت‌شده در تاریخچه ثبت می‌شود؛ یک معامله‌ی موفق هم به کارنامه‌ی هر دو طرف اضافه می‌شود.')) return;
    try {
        const res = await fetch('api/deal_settlement_api.php?action=admin_finalize_deal', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ deal_id: dealId, force: !!force })
        });
        const d = await axJson(res);
        // (اصلاح) اگر هنوز فیشِ یکی از طرفین نرسیده، به‌جای بستنِ خاموشِ معامله،
        // یک هشدارِ واضح نشان بده و فقط با تأییدِ صریحِ دوباره ادامه بده.
        if (!d.success && d.need_confirm) {
            if (confirm((d.message || 'فیش برخی طرفین هنوز نرسیده است.') + '\n\nبا این حال معامله را بدون فیش تسویه می‌کنی؟')) {
                axFinalizeDeal(dealId, true);
            }
            return;
        }
        showAdminToast(d.message || (d.success?'تسویه شد':'خطا'), d.success?'success':'error');
        if (d.success){
            const active = document.querySelector('[data-dfilter].active');
            axLoadDeals(active ? active.dataset.dfilter : 'pending');
            axRefreshOverviewCounts();
        }
    } catch(e){ showAdminToast('خطا در ارتباط', 'error'); }
}

/* ---------- معاملات در انتظار تسویه (فیش‌های نوعِ تبادل ارزی) ----------
   سیستمِ صورتحساب‌های پرداخت‌نشده، فیلترشده روی source_type='exchange'.
   وقتی ادمین از فرمِ «ایجاد فیش جدید» نوع «تبادل ارزی» را انتخاب کند
   (چه از این‌جا، چه با زدن روی نامِ یک طرفِ معامله در کارتِ بالا)، همان فیش
   این‌جا دیده می‌شود؛ فیش‌های آپلودی کاربران هم از همین مسیرِ استاندارد
   (یا از تب «فیش‌ها») بررسی و تأیید می‌شوند. */
async function axLoadExchangeInvoices(status, btn){
    if (btn){ document.querySelectorAll('.ax-xfilter').forEach(b=>b.classList.remove('active')); btn.classList.add('active'); }
    const list = document.getElementById('axExchangeInvoiceList');
    if (!list) return;
    list.innerHTML = '<div class="ax-empty"><i class="fas fa-spinner fa-spin"></i> در حال بارگذاری...</div>';
    try {
        const url = INV_API + '?action=admin_get_invoices&source_type=exchange' + (status ? '&status=' + status : '');
        const res = await fetch(url, { cache:'no-store' });
        const data = await res.json();
        if (!data.success || !data.invoices || !data.invoices.length){
            list.innerHTML = '<div class="ax-empty">معامله‌ای یافت نشد</div>';
            return;
        }
        data.invoices.forEach(inv => { invoiceCache[inv.id] = inv; });
        list.innerHTML = data.invoices.map(inv => buildInvoiceRow(inv)).join('');
    } catch(e){ list.innerHTML = '<div class="ax-empty">خطا در ارتباط</div>'; }
}

/* همان فرمِ استاندارد «ایجاد فیش جدید» را باز می‌کند، فقط نوعِ فیش را از قبل
   روی «تبادل ارزی» می‌گذارد — چون این دکمه از داخل همین تب زده می‌شود. */
function axOpenCreateExchangeInvoice(){
    openCreateInvoice();
    const typeSel = document.getElementById('inv_source_type');
    if (typeSel) typeSel.value = 'exchange';
}


/* تخفیف کمیسیون */
let axDiscUsersCache = [];

async function axLoadDiscountUserOptions(){
    const sel = document.getElementById('axDiscountUserSelect');
    if (!sel) return;
    const keepId = sel.value;
    sel.innerHTML = '<option value="">در حال بارگذاری...</option>';
    try {
        const res = await fetch(AX_DISCOUNT_API + '?action=search_users&search=', { cache:'no-store' });
        const data = await res.json();
        axDiscUsersCache = data.users || data.results || [];
        renderDiscountUserOptions(axDiscUsersCache);
        if (keepId) sel.value = keepId;
    } catch(e){ sel.innerHTML = '<option value="">خطا در بارگذاری لیست</option>'; }
}

function renderDiscountUserOptions(list){
    const sel = document.getElementById('axDiscountUserSelect');
    if (!sel) return;
    const opts = ['<option value="">— یک کاربر را انتخاب کنید —</option>'].concat(
        list.map(u => {
            const name = axEsc(((u.first_name||'')+' '+(u.last_name||'')).trim() || 'بدون نام');
            const tag = u.telegram_id ? (' — ' + axEsc(u.telegram_id)) : '';
            const discTag = u.discount_percent > 0 ? (' 🎁' + axNum(u.discount_percent) + '%') : '';
            return `<option value="${u.id}">${name}${tag}${discTag}</option>`;
        })
    );
    sel.innerHTML = opts.join('');
}

function axFilterDiscountUserSelect(){
    const q = document.getElementById('axDiscountFilter').value.trim().toLowerCase();
    if (!q){ renderDiscountUserOptions(axDiscUsersCache); return; }
    const filtered = axDiscUsersCache.filter(u =>
        ((u.first_name||'')+' '+(u.last_name||'')).toLowerCase().includes(q) ||
        (u.telegram_id||'').toLowerCase().includes(q) ||
        (u.email||'').toLowerCase().includes(q)
    );
    renderDiscountUserOptions(filtered);
}

function axDiscountUserPicked(){
    const id = document.getElementById('axDiscountUserSelect').value;
    const box = document.getElementById('axDiscountResults');
    if (!id){ box.innerHTML = ''; return; }
    const u = axDiscUsersCache.find(x => String(x.id) === String(id));
    if (!u){ box.innerHTML = ''; return; }
    box.innerHTML = `
        <div class="ax-item">
            <div class="ax-item-head">
                <b style="color:#fff;">${axEsc((u.first_name||'')+' '+(u.last_name||''))}</b>
                <span style="font-size:.7rem;color:rgba(255,255,255,0.5);">${axEsc(u.telegram_id||u.account_number||'')}</span>
            </div>
            <div class="ax-form-section">
                <div class="ax-form-label">📊 حجم معاملات (برای سطح‌بندی/تخفیف خودکار)</div>
                <div id="axVolInfo-${u.id}" class="ax-form-hint" style="margin-bottom:6px;">در حال بارگذاری...</div>
                <div class="ax-form-row">
                    <input type="number" id="axVolManual-${u.id}" min="0" step="0.01" placeholder="حجم دستیِ اضافه‌شده">
                    <button class="ax-btn ax-btn-accounts" onclick="axSaveManualVolume(${u.id})"><i class="fas fa-save"></i> ثبت</button>
                </div>
                <div class="ax-form-hint">این مقدار به حجمِ واقعیِ محاسبه‌شده از معاملات تکمیل‌شده‌ی این کاربر <b>اضافه</b> می‌شود (برای معاملاتِ قدیمی/خارج از اپ) — جایگزینِ آن نیست.</div>
            </div>
            <div class="ax-form-section">
                <div class="ax-form-label">➕ کمیسیون پلکانی</div>
                <div class="ax-form-row">
                    <select id="axRuleUnit-${u.id}" class="ax-select">
                        <option value="currency">بر اساس ارز آگهی (همه‌ی ارزها — دلار/یورو/تتر/...)</option>
                        <option value="toman">فقط بر اساس مبلغ کل معامله به تومان</option>
                    </select>
                </div>
                <div class="ax-form-hint" style="margin-top:4px;">
                    «بر اساس ارز آگهی» یعنی این قانون برای هر ارزی که در آگهی نوشته شده اعمال می‌شود (اگر آگهی تتر باشد، تتر مبنا قرار می‌گیرد؛ اگر دلار باشد، دلار). «بر اساس مبلغ کل به تومان» فقط زمانی اعمال می‌شود که مبنای محاسبه، مبلغ تومانی کل معامله باشد.
                </div>
                <div class="ax-form-row" style="margin-top:6px;">
                    <input type="number" id="axRuleMin-${u.id}" placeholder="از مبلغ">
                    <input type="number" id="axRuleMax-${u.id}" placeholder="تا مبلغ (خالی=نامحدود)">
                    <input type="number" id="axRuleVal-${u.id}" placeholder="مقدار کمیسیون (پلکانی)">
                    <button class="ax-btn ax-btn-accounts" onclick="axSaveRule(${u.id})"><i class="fas fa-plus"></i> افزودن</button>
                </div>
                <div id="axRuleList-${u.id}" style="margin-top:8px;font-size:.72rem;color:rgba(255,255,255,0.75);"></div>
            </div>
            <div class="ax-form-section">
                <div class="ax-form-label">🎁 تخفیف درصدی (بعد از تعیین کمیسیون)</div>
                <div class="ax-form-row">
                    <input type="number" id="axDisc-${u.id}" min="0" max="100" step="0.5" placeholder="درصد تخفیف" value="${u.discount_percent||0}">
                    <input type="date" id="axDiscExp-${u.id}" title="اعتبار تا تاریخ (خالی=نامحدود)" value="${u.discount_expires_at ? String(u.discount_expires_at).slice(0,10) : ''}">
                    <button class="ax-btn ax-btn-accounts" onclick="axSaveDiscount(${u.id})"><i class="fas fa-save"></i> ثبت</button>
                </div>
                <input type="text" id="axDiscDesc-${u.id}" class="ax-input" placeholder="توضیحات (اختیاری)" value="${axEsc(u.discount_description||'')}" style="width:100%;margin-top:6px;">
                <div class="ax-form-hint">پس از تاریخ اعتبار، تخفیف به‌صورت خودکار حذف می‌شود.</div>
            </div>
            <div class="ax-form-section">
                <div class="ax-form-label">🔒 کمیسیون تخفیف ثابت</div>
                <div class="ax-form-hint" style="margin-bottom:6px;">
                    اگر فعال شود، این کاربر همیشه فقط همین مبلغ ثابت را به‌عنوان کمیسیون می‌پردازد — فارغ از حجم یا ارز معامله‌اش —
                    و کمیسیون پلکانی/پیش‌فرض/تخفیف درصدی/سطح‌بندی بالا هیچ‌کدام برایش اثری ندارند.
                </div>
                <div class="ax-form-row">
                    <input type="number" id="axFixedAmt-${u.id}" min="0" step="0.01" placeholder="مبلغ ثابت (مثال: 3)">
                    <select id="axFixedCur-${u.id}" class="ax-select">
                        <option value="EUR">یورو (EUR)</option>
                        <option value="USD">دلار (USD)</option>
                        <option value="USDT">تتر (USDT)</option>
                        <option value="IRR">تومان (IRR)</option>
                    </select>
                </div>
                <input type="text" id="axFixedDesc-${u.id}" class="ax-input" placeholder="توضیحات (اختیاری)" style="width:100%;margin-top:6px;">
                <div class="ax-form-row" style="margin-top:6px;">
                    <button class="ax-btn ax-btn-accounts" onclick="axSaveFixedCommission(${u.id})"><i class="fas fa-save"></i> فعال‌سازی / ذخیره</button>
                    <button class="ax-btn ax-btn-view" style="background:rgba(255,59,48,0.15);border-color:#FF3B30;color:#FF3B30;" onclick="axDeleteFixedCommission(${u.id})"><i class="fas fa-trash"></i> غیرفعال‌سازی</button>
                </div>
                <div id="axFixedMsg-${u.id}" style="margin-top:8px;font-size:.72rem;"></div>
            </div>
        </div>`;
    axLoadRules(u.id);
    axLoadFixedCommissionForUser(u.id);
    axLoadVolumeInfo(u.id);
}

async function axLoadVolumeInfo(userId){
    const infoEl = document.getElementById('axVolInfo-'+userId);
    if (!infoEl) return;
    try {
        const res = await fetch(AX_DISCOUNT_API + '?action=get_user_volume&user_id=' + userId, { cache:'no-store' });
        const d = await res.json();
        if (!d.success){ infoEl.textContent = 'خطا در دریافت حجم معاملات'; return; }
        const unit = d.currency === 'IRR' ? 'تومان' : d.currency;
        infoEl.innerHTML = `واقعی: <b style="color:#fff;">${axNum(d.real_volume)} ${unit}</b>`
            + (d.manual_volume > 0 ? ` + دستی: <b style="color:#FFD700;">${axNum(d.manual_volume)} ${unit}</b>` : '')
            + ` = مجموع: <b style="color:#35d07f;">${axNum(d.total_volume)} ${unit}</b>`;
        const manualEl = document.getElementById('axVolManual-'+userId);
        if (manualEl) manualEl.value = d.manual_volume || '';
    } catch(e){ infoEl.textContent = 'خطا در ارتباط با سرور'; }
}

async function axSaveManualVolume(userId){
    const el = document.getElementById('axVolManual-'+userId);
    const amount = parseFloat(el?.value);
    if (isNaN(amount) || amount < 0){ showAdminToast('مقدار حجم دستی نامعتبر است', 'error'); return; }
    try {
        const res = await fetch(AX_DISCOUNT_API + '?action=set_manual_volume', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ user_id: userId, amount })
        });
        const d = await res.json();
        showAdminToast(d.message || (d.success?'ثبت شد':'خطا'), d.success?'success':'error');
        if (d.success) axLoadVolumeInfo(userId);
    } catch(e){ showAdminToast('خطا در ارتباط با سرور', 'error'); }
}

async function axLoadFixedCommissionForUser(userId){
    try {
        const res = await fetch(AX_DISCOUNT_API + '?action=get_fixed_commission&user_id=' + userId, { cache:'no-store' });
        const data = await res.json();
        if (data.success && data.item) {
            const amtEl = document.getElementById('axFixedAmt-'+userId);
            const curEl = document.getElementById('axFixedCur-'+userId);
            const descEl = document.getElementById('axFixedDesc-'+userId);
            if (amtEl) amtEl.value = data.item.fixed_amount;
            if (curEl) curEl.value = data.item.fixed_currency;
            if (descEl) descEl.value = data.item.description || '';
        }
    } catch(e){}
}

async function axSaveFixedCommission(userId){
    const msg = document.getElementById('axFixedMsg-'+userId);
    const amount = parseFloat(document.getElementById('axFixedAmt-'+userId)?.value) || 0;
    const currency = document.getElementById('axFixedCur-'+userId)?.value || 'EUR';
    const description = (document.getElementById('axFixedDesc-'+userId)?.value || '').trim();
    if (amount <= 0) { msg.style.color = '#FF3B30'; msg.textContent = 'مبلغ ثابت باید بزرگ‌تر از صفر باشد'; return; }
    msg.style.color = 'rgba(255,255,255,.6)'; msg.textContent = 'در حال ذخیره...';
    try {
        const res = await fetch(AX_DISCOUNT_API + '?action=save_fixed_commission', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ user_id: userId, fixed_amount: amount, fixed_currency: currency, description: description })
        });
        const data = await res.json();
        msg.style.color = data.success ? '#4CD964' : '#FF3B30';
        msg.textContent = data.message || (data.success ? 'ذخیره شد' : 'خطا');
        if (data.success) axLoadFixedCommissionList();
    } catch(e){ msg.style.color = '#FF3B30'; msg.textContent = 'خطا در ارتباط با سرور'; }
}

async function axDeleteFixedCommission(userId){
    const msg = document.getElementById('axFixedMsg-'+userId);
    if (!confirm('کمیسیون ثابت این کاربر غیرفعال شود؟ (به قوانین معمول برمی‌گردد)')) return;
    try {
        const res = await fetch(AX_DISCOUNT_API + '?action=delete_fixed_commission', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ user_id: userId })
        });
        const data = await res.json();
        if (msg) { msg.style.color = data.success ? '#4CD964' : '#FF3B30'; msg.textContent = data.message || ''; }
        const amtEl = document.getElementById('axFixedAmt-'+userId);
        if (amtEl) amtEl.value = '';
        if (data.success) axLoadFixedCommissionList();
    } catch(e){ if (msg){ msg.style.color = '#FF3B30'; msg.textContent = 'خطا در ارتباط با سرور'; } }
}

async function axLoadFixedCommissionList(){
    const box = document.getElementById('axFixedCommissionList');
    if (!box) return;
    box.innerHTML = '<div class="ax-empty"><i class="fas fa-spinner fa-spin"></i> در حال بارگذاری...</div>';
    try {
        const res = await fetch(AX_DISCOUNT_API + '?action=list_fixed_commission', { cache:'no-store' });
        const data = await res.json();
        if (!data.success || !data.items || !data.items.length) {
            box.innerHTML = '<div class="ax-empty">هیچ کاربری کمیسیون ثابت ندارد</div>';
            return;
        }
        box.innerHTML = data.items.map(it => {
            const name = axEsc(((it.first_name||'')+' '+(it.last_name||'')).trim() || 'بدون نام');
            return `<div class="ax-item" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding:10px 12px;">
                <div>
                    <b style="color:#fff;">${name}</b>
                    <span style="font-size:.7rem;color:rgba(255,255,255,0.5);"> — @${axEsc(it.telegram_id||'')}</span>
                    <div style="font-size:.68rem;color:#4CD964;margin-top:2px;">همیشه ${axNum(it.fixed_amount)} ${axEsc(it.fixed_currency)}${it.description ? ' · ' + axEsc(it.description) : ''}</div>
                </div>
                <button class="ax-btn ax-btn-view" style="width:auto;background:rgba(255,59,48,0.15);border-color:#FF3B30;color:#FF3B30;" onclick="axDeleteFixedCommission(${it.user_id})"><i class="fas fa-trash"></i></button>
            </div>`;
        }).join('');
    } catch(e){ box.innerHTML = '<div class="ax-empty">خطا در دریافت اطلاعات</div>'; }
}

async function axSaveDiscount(userId){
    const pct = parseFloat(document.getElementById('axDisc-'+userId).value)||0;
    if (pct < 0 || pct > 100){ showAdminToast('درصد باید بین ۰ تا ۱۰۰ باشد', 'error'); return; }
    const expRaw = (document.getElementById('axDiscExp-'+userId)?.value || '').trim();
    const desc = (document.getElementById('axDiscDesc-'+userId)?.value || '').trim();
    // تبدیل تاریخ به انتهای همان روز
    const expires_at = expRaw ? (expRaw + ' 23:59:59') : null;
    const res = await fetch(AX_DISCOUNT_API + '?action=save', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ user_id:userId, discount_percent:pct, description:desc, expires_at }) });
    const d = await res.json();
    if (d.success){ showAdminToast(pct==0?'تخفیف حذف شد':'تخفیف ثبت شد', 'success'); axLoadDiscountList(); axLoadDiscountUserOptions(); }
    else showAdminToast(d.message||'خطا', 'error');
}

async function axLoadDiscountList(){
    const box = document.getElementById('axDiscountList');
    if (!box) return;
    box.innerHTML = '<div class="ax-empty"><i class="fas fa-spinner fa-spin"></i> در حال بارگذاری...</div>';
    try {
        const d = await (await fetch(AX_DISCOUNT_API + '?action=list_discounts', { cache:'no-store' })).json();
        const list = d.discounts || [];
        if (!list.length){ box.innerHTML = '<div class="ax-empty">کاربری با تخفیف فعال وجود ندارد</div>'; return; }
        box.innerHTML = list.map(x => {
            const name = axEsc((x.first_name||'')+' '+(x.last_name||''));
            const exp = x.expires_at ? new Date(x.expires_at.replace(' ','T')).toLocaleDateString('fa-IR') : 'نامحدود';
            const expColor = x.expires_at ? '#FF9F43' : '#4CD964';
            return `<div class="ax-item">
                <div class="ax-item-head">
                    <b style="color:#fff;">${name}</b>
                    <span class="ax-badge ax-b-done">${axNum(x.discount_percent)}%</span>
                </div>
                <div style="font-size:.72rem;color:rgba(255,255,255,0.6);margin-top:4px;">
                    ${x.telegram_id?('🆔 '+axEsc(x.telegram_id)+' • '):''}📅 اعتبار تا: <b style="color:${expColor};">${exp}</b>
                    ${x.description?('<br>📝 '+axEsc(x.description)):''}
                </div>
                <div class="ax-actions">
                    <button class="ax-btn ax-btn-reject" onclick="axRemoveDiscount(${x.user_id})"><i class="fas fa-trash"></i> حذف تخفیف</button>
                </div>
            </div>`;
        }).join('');
    } catch(e){ box.innerHTML = '<div class="ax-empty">خطا در دریافت لیست</div>'; }
}

async function axRemoveDiscount(userId){
    if (!confirm('تخفیف این کاربر حذف شود؟')) return;
    const res = await fetch(AX_DISCOUNT_API + '?action=save', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ user_id:userId, discount_percent:0, description:'', expires_at:null }) });
    const d = await res.json();
    if (d.success){ showAdminToast('تخفیف حذف شد', 'success'); axLoadDiscountList(); axLoadDiscountUserOptions(); }
    else showAdminToast(d.message||'خطا', 'error');
}

async function axLoadRules(userId){
    const box = document.getElementById('axRuleList-'+userId);
    if (!box) return;
    try {
        const res = await fetch(AX_DISCOUNT_API + '?action=list_rules&user_id=' + userId, { cache:'no-store' });
        const d = await res.json();
        const rules = d.rules || [];
        if (!rules.length){ box.innerHTML = '<i>هیچ قانونی ثبت نشده</i>'; return; }
        box.innerHTML = rules.map(r => {
            const rangeTxt = 'از ' + axNum(r.min_amount) + (r.max_amount ? ' تا ' + axNum(r.max_amount) : ' به بالا');
            const unitLabel = r.unit === 'toman' ? 'تومان (مبلغ کل)' : (r.currency === 'ALL' ? 'همه‌ی ارزها (طبق ارز آگهی)' : axEsc(r.currency));
            const valTxt = r.rule_type === 'percent' ? (r.value + '%') : (axNum(r.value) + (r.unit==='toman' ? ' تومان' : (r.currency === 'ALL' ? ' واحد ارز آگهی' : ' ' + r.currency)));
            return `<div style="display:flex;justify-content:space-between;align-items:center;padding:4px 0;border-bottom:1px dashed rgba(255,255,255,0.06);">
                <span>${unitLabel} | ${rangeTxt} => ${valTxt}</span>
                <button class="ax-btn ax-btn-reject" style="padding:2px 6px;" onclick="axDeleteRule(${r.id}, ${userId})"><i class="fas fa-trash"></i></button>
            </div>`;
        }).join('');
    } catch(e){ box.innerHTML = '<i>خطا در دریافت قوانین</i>'; }
}
async function axSaveRule(userId){
    const unit = document.getElementById('axRuleUnit-'+userId).value;
    // «بر اساس ارز» دیگر به یک ارز خاص محدود نیست — currency='ALL' یعنی این
    // قانون برای هر ارزی که در آگهی/معامله باشد اعمال می‌شود (سرور خودش هنگام
    // محاسبه‌ی کمیسیون، ارز واقعی همان معامله را با این قانون تطبیق می‌دهد).
    // برای «بر اساس تومان» هم currency بی‌اثر است؛ فقط unit='toman' مهم است
    // و oa_commission() آن را صرفاً روی مبلغ کل تومانی معامله اعمال می‌کند.
    const currency = 'ALL';
    const rule_type = 'fixed';
    const min_amount = parseFloat(document.getElementById('axRuleMin-'+userId).value) || 0;
    const maxRaw = document.getElementById('axRuleMax-'+userId).value;
    const max_amount = maxRaw === '' ? null : parseFloat(maxRaw);
    const value = parseFloat(document.getElementById('axRuleVal-'+userId).value);
    if (isNaN(value) || value < 0){ showAdminToast('مقدار کمیسیون را وارد کنید', 'error'); return; }
    const d = await (await fetch(AX_DISCOUNT_API + '?action=save_rule', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ user_id:userId, currency, unit, min_amount, max_amount, rule_type, value })
    })).json();
    if (d.success){ showAdminToast('قانون ثبت شد', 'success'); axLoadRules(userId); }
    else showAdminToast(d.message||'خطا', 'error');
}
async function axDeleteRule(ruleId, userId){
    const d = await (await fetch(AX_DISCOUNT_API + '?action=delete_rule', {
        method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ rule_id: ruleId })
    })).json();
    if (d.success){ showAdminToast('حذف شد', 'success'); axLoadRules(userId); }
    else showAdminToast(d.message||'خطا', 'error');
}

/* ---------- کمیسیون پیش‌فرض برای همه‌ی کاربران ---------- */
async function axLoadDefaultRules(){
    const box = document.getElementById('axDefRuleList');
    if (!box) return;
    box.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    try {
        const res = await fetch(AX_DISCOUNT_API + '?action=list_default_rules', { cache:'no-store' });
        const d = await res.json();
        const rules = d.rules || [];
        if (!rules.length){ box.innerHTML = '<i>هنوز کمیسیون پیش‌فرضی تعریف نشده — همه‌ی کاربرانِ بدون قانون اختصاصی از فرمول پایه استفاده می‌کنند</i>'; return; }
        box.innerHTML = rules.map(r => {
            const rangeTxt = 'از ' + axNum(r.min_amount) + (r.max_amount ? ' تا ' + axNum(r.max_amount) : ' به بالا');
            const unitLabel = r.unit === 'toman' ? 'تومان (مبلغ کل)' : 'همه‌ی ارزها (طبق ارز آگهی)';
            const valTxt = r.rule_type === 'percent' ? (r.value + '%') : (axNum(r.value) + (r.unit==='toman' ? ' تومان' : ' واحد ارز آگهی'));
            return `<div style="display:flex;justify-content:space-between;align-items:center;padding:4px 0;border-bottom:1px dashed rgba(255,255,255,0.06);">
                <span>${unitLabel} | ${rangeTxt} => ${valTxt}</span>
                <button class="ax-btn ax-btn-reject" style="padding:2px 6px;" onclick="axDeleteDefaultRule(${r.id})"><i class="fas fa-trash"></i></button>
            </div>`;
        }).join('');
    } catch(e){ box.innerHTML = '<i>خطا در دریافت لیست</i>'; }
}
async function axSaveDefaultRule(){
    const unit = document.getElementById('axDefRuleUnit').value === 'toman' ? 'toman' : 'currency';
    const rule_type = 'fixed';
    const min_amount = parseFloat(document.getElementById('axDefRuleMin').value) || 0;
    const maxRaw = document.getElementById('axDefRuleMax').value;
    const max_amount = maxRaw === '' ? null : parseFloat(maxRaw);
    const value = parseFloat(document.getElementById('axDefRuleVal').value);
    if (isNaN(value) || value < 0){ showAdminToast('مقدار کمیسیون را وارد کنید', 'error'); return; }
    const d = await (await fetch(AX_DISCOUNT_API + '?action=save_default_rule', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ unit, min_amount, max_amount, rule_type, value })
    })).json();
    if (d.success){
        showAdminToast('کمیسیون پیش‌فرض ثبت شد', 'success');
        document.getElementById('axDefRuleMin').value = '';
        document.getElementById('axDefRuleMax').value = '';
        document.getElementById('axDefRuleVal').value = '';
        axLoadDefaultRules();
    } else showAdminToast(d.message||'خطا', 'error');
}
async function axDeleteDefaultRule(ruleId){
    const d = await (await fetch(AX_DISCOUNT_API + '?action=delete_default_rule', {
        method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ rule_id: ruleId })
    })).json();
    if (d.success){ showAdminToast('حذف شد', 'success'); axLoadDefaultRules(); }
    else showAdminToast(d.message||'خطا', 'error');
}

/* ---------- کارت‌های بانکی شرکت + صورتحساب ---------- */
async function axLoadCompanyCardsAdmin(){
    const box = document.getElementById('axCardsList');
    if (!box) return;
    box.innerHTML = '<div class="ax-empty"><i class="fas fa-spinner fa-spin"></i></div>';
    try {
        const res = await fetch('api/cards.php?action=get_cards', { cache:'no-store' });
        const d = await res.json();
        const cards = d.data || [];
        if (!cards.length){ box.innerHTML = '<div class="ax-empty">کارتی ثبت نشده</div>'; return; }
        box.innerHTML = cards.map(c => `
            <div class="ax-item">
                <div class="ax-item-head">
                    <b style="color:#fff;">${axEsc(c.card_owner||'')}</b>
                    <span style="font-size:.7rem;color:${c.is_active==1?'#4caf50':'#ff5252'};">${c.is_active==1?'فعال':'غیرفعال'}</span>
                </div>
                <div>${axEsc(c.bank_name||'')} — <code>${axEsc(c.card_number||'')}</code></div>
                <div class="ax-actions"><button class="ax-btn ax-btn-reject" onclick="axDeleteCompanyCard(${c.id})"><i class="fas fa-trash"></i> حذف</button></div>
            </div>`).join('');
    } catch(e){ box.innerHTML = '<div class="ax-empty">خطا در دریافت کارت‌ها</div>'; }
}
async function axAddCompanyCard(){
    const card_owner = document.getElementById('axCardOwner').value.trim();
    const bank_name = document.getElementById('axCardBank').value.trim();
    const card_number = document.getElementById('axCardNumber').value.trim();
    if (!bank_name || !card_number){ showAdminToast('نام بانک و شماره کارت الزامی است', 'error'); return; }
    const d = await (await fetch('api/cards.php?action=add_card', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ card_owner, bank_name, card_number, is_active:1 })
    })).json();
    if (d.success){ showAdminToast('کارت افزوده شد', 'success'); document.getElementById('axCardOwner').value=''; document.getElementById('axCardBank').value=''; document.getElementById('axCardNumber').value=''; axLoadCompanyCardsAdmin(); }
    else showAdminToast(d.message||'خطا', 'error');
}
async function axDeleteCompanyCard(id){
    if (!confirm('حذف این کارت؟')) return;
    const d = await (await fetch('api/cards.php?action=delete_card', {
        method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ card_id:id })
    })).json();
    if (d.success){ showAdminToast('حذف شد', 'success'); axLoadCompanyCardsAdmin(); }
    else showAdminToast(d.message||'خطا', 'error');
}
async function axLoadCardStats(){
    const box = document.getElementById('axCardStatsList');
    if (!box) return;
    box.innerHTML = '<div class="ax-empty"><i class="fas fa-spinner fa-spin"></i></div>';
    try {
        const res = await fetch('api/cards.php?action=get_card_stats', { cache:'no-store' });
        const d = await res.json();
        const stats = d.data || [];
        if (!stats.length){ box.innerHTML = '<div class="ax-empty">داده‌ای موجود نیست</div>'; return; }
        box.innerHTML = stats.map(s => `
            <div class="ax-item">
                <div class="ax-item-head"><b style="color:#fff;">${axEsc(s.card_owner||'')} — ${axEsc(s.bank_name||'')}</b></div>
                <div style="font-size:.78rem;">تعداد تراکنش: ${axNum(s.transaction_count)}</div>
                <div class="ax-grid" style="margin-top:6px;">
                    <div>تومان: <b>${axNum(s.total_irr)}</b></div>
                    <div>USD: <b>${axNum(s.total_usd)}</b></div>
                    <div>EUR: <b>${axNum(s.total_eur)}</b></div>
                    <div>USDT: <b>${axNum(s.total_usdt)}</b></div>
                </div>
            </div>`).join('');
    } catch(e){ box.innerHTML = '<div class="ax-empty">خطا در دریافت صورتحساب</div>'; }
}

/* شمارنده‌های تابلوی کلی برای کاشی‌های جدید */
async function axRefreshOverviewCounts(){
    try {
        const res = await fetch(AX_TRANSFER_API + '?action=counts', { cache:'no-store' });
        const data = await res.json();
        if (!data.success) return;
        const c = data.counts;
        const set = (id, v)=>{ const el=document.getElementById(id); if (el) el.textContent = Number(v||0).toLocaleString('fa-IR'); };
        set('ovTransfersNum', c.transfers_new);
        set('ovSettlementsNum', c.settlements_new);
        set('ovDealsNum', c.deals_new);

        // چراغ قرمز چشمک‌زن: روشن اگر درخواست جدید باشد
        const dot = (id, on)=>{ const el=document.getElementById(id); if (el) el.classList.toggle('on', !!on); };
        dot('transfersDot',   c.transfers_new  > 0);
        dot('settlementsDot', c.settlements_new> 0);
        dot('exchangeDot',    c.deals_new      > 0);
        dot('ovTransfersDot',   c.transfers_new  > 0);
        dot('ovSettlementsDot', c.settlements_new> 0);
        dot('ovDealsDot',       c.deals_new      > 0);

        const tb = document.getElementById('transfersBadge');
        if (tb){ if (c.transfers_new>0){ tb.style.display=''; tb.textContent=c.transfers_new; } else tb.style.display='none'; }
        const eb = document.getElementById('exchangeBadge');
        if (eb){ if (c.deals_new>0){ eb.style.display=''; eb.textContent=c.deals_new; } else eb.style.display='none'; }
        const sb = document.getElementById('settlementsBadge');
        if (sb){ if (c.settlements_new>0){ sb.style.display=''; sb.textContent=c.settlements_new; } else sb.style.display='none'; }

        // فیش‌های پرداخت جدیدِ معاملات که منتظر بررسی ادمین هستند
        const rn = Number(c.deal_receipts_new || 0);
        const rEl = document.getElementById('ovDealReceipts');
        if (rEl){
            if (rn > 0){ rEl.style.display=''; rEl.innerHTML = '<i class="fas fa-receipt"></i> ' + rn.toLocaleString('fa-IR') + ' فیش در انتظار بررسی'; }
            else rEl.style.display='none';
        }
        if (rn > 0 && rn !== axLastDealReceipts && axLastDealReceipts !== null){
            showAdminToast('فیش پرداخت جدید برای معاملات بازار ارز رسید', 'success');
        }
        if (rn > 0) dot('exchangeDot', true);
        axLastDealReceipts = rn;
    } catch(e){}
}
let axLastDealReceipts = null;
axRefreshOverviewCounts();
setInterval(axRefreshOverviewCounts, 20000);

/* باز کردن تب مربوطه بر اساس هش URL (مثلاً admin_panel.php#transfers) */
(function(){
    function openTabFromHash(){
        const h = (window.location.hash || '').replace('#','');
        if (!h) return;
        axGoToTab(h);
    }
    window.addEventListener('hashchange', openTabFromHash);
    document.addEventListener('DOMContentLoaded', openTabFromHash);
    openTabFromHash();
})();
</script>

<style>
.ax-btn-sm{ font-size:.72rem !important; padding:8px 12px !important; margin:4px 4px 0 0; }

/* --- چیپ وضعیت هر طرف روی کارت معامله در لیست --- */
.ax-sidechip{ font-size:.62rem; font-weight:700; padding:4px 10px; border-radius:40px; background:rgba(255,255,255,.07); color:rgba(255,255,255,.7); white-space:nowrap; }
.ax-sidechip.new{ background:rgba(255,255,255,.07); color:rgba(255,255,255,.6); }
.ax-sidechip.wait{ background:rgba(255,193,7,.16); color:#ffce54; }
.ax-sidechip.hot{ background:rgba(255,77,141,.18); color:#ff7bab; }
.ax-sidechip.done{ background:rgba(53,208,127,.18); color:#35d07f; }
.ax-item-hot{ border-color:rgba(255,77,141,.45) !important; box-shadow:0 0 0 1px rgba(255,77,141,.2) inset; }

/* --- ردیفِ قابل‌کلیکِ هر طرفِ معامله روی کارتِ لیست — زدن روی هر کاربر
   مستقیم می‌برد به فرمِ ارسال حساب/فیش برای همان کاربر --- */
.ax-party-row{
    display:flex; align-items:center; justify-content:space-between; gap:10px;
    background:rgba(255,255,255,.035); border:1px solid rgba(255,255,255,.08); border-radius:12px;
    padding:10px 12px; margin-bottom:8px; cursor:pointer; transition:.15s;
}
.ax-party-row:last-of-type{ margin-bottom:0; }
.ax-party-row:hover{ background:rgba(255,255,255,.07); border-color:rgba(255,215,0,.3); }
.ax-party-row:active{ transform:scale(.99); }
.ax-party-info{ display:flex; align-items:center; gap:8px; font-size:.78rem; color:#fff; font-weight:700; }
.ax-party-info i{ color:#FFD700; font-size:.85rem; }
.ax-party-role{ font-size:.6rem; color:rgba(255,255,255,.45); font-weight:600; }
.ax-party-arrow{ color:rgba(255,255,255,.3); font-size:.7rem; }

/* --- گالریِ عکس فیش‌های آپلودی --- */
.ax-receipt-gallery{ display:flex; flex-wrap:wrap; gap:10px; }
.ax-receipt-thumb{
    position:relative; width:76px; height:76px; border-radius:12px; overflow:hidden; cursor:pointer;
    border:1px solid rgba(255,255,255,.15); background:rgba(0,0,0,.3); flex:none;
}
.ax-receipt-thumb img{ width:100%; height:100%; object-fit:cover; display:block; }
.ax-receipt-thumb:hover{ border-color:#FFD700; }
.ax-receipt-thumb-id{
    position:absolute; bottom:0; right:0; left:0; background:rgba(0,0,0,.6); color:#FFD700;
    font-size:.55rem; font-weight:800; text-align:center; padding:2px 0; direction:ltr;
}

/* ---------- حساب‌های ذخیره‌شده (Saved Accounts picker) ---------- */
.ax-savedacc-box{ background:rgba(120,80,220,.08); border:1px solid rgba(180,140,255,.25); border-radius:14px; padding:10px 12px; margin:10px 0; }
.ax-savedacc-title{ color:#c9a8ff; font-size:.74rem; font-weight:700; margin-bottom:8px; display:flex; align-items:center; gap:6px; }
.ax-savedacc-title i{ color:#a875ff; }
.ax-savedacc-hint{ color:rgba(255,255,255,.4); font-weight:400; font-size:.64rem; }
.ax-savedacc-list{ display:flex; flex-direction:column; gap:6px; max-height:200px; overflow-y:auto; }
.ax-savedacc-item{ display:flex; align-items:center; justify-content:space-between; gap:8px; background:rgba(0,0,0,.28); border:1px solid rgba(255,255,255,.08); border-radius:10px; padding:7px 10px; }
.ax-savedacc-info{ display:flex; flex-direction:column; gap:2px; cursor:pointer; flex:1; min-width:0; }
.ax-savedacc-info:hover{ opacity:.85; }
.ax-savedacc-info b{ color:#fff; font-size:.74rem; }
.ax-savedacc-info code{ color:#c9a8ff; font-size:.72rem; direction:ltr; text-align:right; word-break:break-all; }
.ax-savedacc-actions{ display:flex; gap:4px; flex-shrink:0; }
.ax-savedacc-btn{ width:26px; height:26px; border-radius:8px; border:none; background:rgba(255,255,255,.08); color:#ddd; cursor:pointer; display:grid; place-items:center; font-size:.7rem; }
.ax-savedacc-btn:hover{ background:rgba(255,255,255,.15); }
.ax-savedacc-btn.del:hover{ background:rgba(255,77,109,.25); color:#ff4d6d; }
.ax-savedacc-editform{ display:flex; flex-direction:column; gap:6px; width:100%; }
.ax-savedacc-editbtns{ display:flex; gap:6px; }
.ax-savedacc-addrow{ display:flex; flex-wrap:wrap; gap:6px; margin-top:10px; padding-top:10px; border-top:1px dashed rgba(255,255,255,.1); }
.ax-savedacc-in{ flex:1; min-width:100px; background:rgba(0,0,0,.3); border:1px solid rgba(255,255,255,.12); border-radius:8px; padding:8px 10px; color:#fff; font-family:inherit; font-size:.75rem; }
.ax-savedacc-in:focus{ outline:none; border-color:#a875ff; }
</style>

<!-- ===== عکس نرخ لحظه‌ای ===== -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js" defer></script>
<script>
/* ---------------------------------------------------------------------------
   «عکس نرخ لحظه‌ای»
   قالب در rate_poster.php رندر می‌شود؛ اینجا فقط فهرست، پیش‌نمایش و دانلود.
   دانلود با html2canvas از داخل همان iframe گرفته می‌شود تا فونت و استایل
   دقیقاً همان چیزی باشد که در پیش‌نمایش می‌بینید.
   --------------------------------------------------------------------------- */
var rpCurrentId = 0;

function rpMsg(text, ok) {
    var el = document.getElementById('rpMsg');
    if (!el) return;
    el.style.color = ok === false ? '#ff6b6b' : (ok ? '#22d3a0' : '#8ea0c9');
    el.textContent = text || '';
}

async function rpLoadList() {
    var box = document.getElementById('rpList');
    if (!box) return;
    try {
        var res  = await fetch('api/rate_poster_api.php?action=list', { cache: 'no-store' });
        var data = await res.json();
        if (!data.success || !data.items.length) {
            box.innerHTML = '<div style="padding:18px 0;text-align:center;">هنوز عکسی ساخته نشده — با دکمه‌ی «تولید همین الان» اولین عکس را بسازید.</div>';
            return;
        }
        box.innerHTML = data.items.map(function (it) {
            var color = it.slot === 'morning' ? '#F59E0B'
                      : it.slot === 'afternoon' ? '#0EA5E9' : '#A855F7';
            return '<div style="display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.06);">'
                 + '<span style="flex:none;padding:4px 10px;border-radius:8px;font-size:.66rem;font-weight:800;'
                 + 'background:' + color + '22;color:' + color + ';">' + it.slot_label + '</span>'
                 + '<div style="flex:1;min-width:0;">'
                 +   '<div style="color:#fff;font-weight:700;">' + it.title_date + ' — ساعت ' + it.title_time + '</div>'
                 + '</div>'
                 + '<button class="btn-sm" onclick="rpPreview(' + it.id + ')" '
                 +   'style="background:rgba(255,255,255,.08);color:#fff;border:none;"><i class="fas fa-eye"></i></button>'
                 + '<button class="btn-sm" onclick="rpDelete(' + it.id + ')" '
                 +   'style="background:rgba(255,77,109,.15);color:#ff4d6d;border:none;"><i class="fas fa-trash"></i></button>'
                 + '</div>';
        }).join('');
    } catch (e) {
        box.innerHTML = '<div style="color:#ff6b6b;padding:14px 0;">خطا در بارگذاری فهرست</div>';
    }
}

async function rpGenerate() {
    var btn = document.getElementById('rpGenBtn');
    var orig = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال دریافت نرخ‌ها…'; }
    rpMsg('در حال گرفتن نرخ لحظه‌ای از منبع…');
    try {
        var res  = await fetch('api/rate_poster_api.php?action=generate', { cache: 'no-store' });
        var data = await res.json();
        rpMsg(data.message || (data.success ? 'ساخته شد' : 'ناموفق'), !!data.success);
        if (data.success) {
            await rpLoadList();
            rpPreview(data.id);
            // طبق درخواست: بعد از تولید، عکس مستقیم به ربات تلگرام ادمین می‌رود
            rpMsg('نرخ‌ها گرفته شد — در حال ساخت و ارسال عکس به تلگرام…');
            await rpWaitFrame();
            await rpSendTelegram();
        }
    } catch (e) {
        rpMsg('خطا در ارتباط با سرور', false);
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = orig; }
    }
}

function rpFitPreview() {
    var stage = document.getElementById('rpStage');
    var frame = document.getElementById('rpFrame');
    if (!stage || !frame) return;
    var avail = stage.clientWidth || stage.offsetWidth;
    if (!avail) return;
    var scale = avail / 1024;
    frame.style.transform = 'scale(' + scale + ')';
    // ارتفاع ظرف را به اندازه‌ی تصویرِ کوچک‌شده تنظیم کن تا فضای خالی نماند
    stage.style.height = Math.round(1536 * scale) + 'px';
}
window.addEventListener('resize', rpFitPreview);

function rpPreview(id) {
    rpCurrentId = id;
    var frame = document.getElementById('rpFrame');
    var open  = document.getElementById('rpOpenBtn');
    var url   = 'rate_poster.php?id=' + id;
    if (frame) frame.src = url;
    if (open)  open.href = url;
    var m = document.getElementById('rpDlMsg'); if (m) m.textContent = '';
    openModal('rate-poster-modal');
    // مودال تازه باز شده، پس عرض ظرف الان قابل اندازه‌گیری است
    setTimeout(rpFitPreview, 60);
    setTimeout(rpFitPreview, 400);
}

async function rpDelete(id) {
    if (!confirm('این عکس حذف شود؟')) return;
    try {
        await fetch('api/rate_poster_api.php?action=delete&id=' + id, { cache: 'no-store' });
        rpLoadList();
    } catch (e) {}
}

/* منتظر می‌ماند تا قالب داخل iframe کاملاً بارگذاری و فونت‌هایش آماده شود */
function rpWaitFrame() {
    return new Promise(function (resolve) {
        var frame = document.getElementById('rpFrame');
        if (!frame) return resolve();
        var done = false;
        var finish = function () {
            if (done) return; done = true;
            var doc = frame.contentDocument;
            if (doc && doc.fonts && doc.fonts.ready) doc.fonts.ready.then(function(){ setTimeout(resolve, 250); }).catch(function(){ resolve(); });
            else setTimeout(resolve, 500);
        };
        if (frame.contentDocument && frame.contentDocument.readyState === 'complete') finish();
        frame.addEventListener('load', finish, { once: true });
        setTimeout(finish, 4000);   // سقف انتظار
    });
}

/* قالب داخل iframe را در ابعاد واقعی ۱۰۲۴×۱۵۳۶ به canvas تبدیل می‌کند */
async function rpRenderCanvas() {
    var frame = document.getElementById('rpFrame');
    if (!frame || !frame.contentWindow) throw new Error('قاب پیش‌نمایش آماده نیست');
    if (typeof html2canvas === 'undefined') throw new Error('کتابخانه‌ی تصویرسازی هنوز بارگذاری نشده');

    var doc = frame.contentDocument || frame.contentWindow.document;
    if (doc.fonts && doc.fonts.ready) { try { await doc.fonts.ready; } catch (e) {} }
    var target = doc.getElementById('rpCanvas');
    if (!target) throw new Error('قالب پیدا نشد');

    var prevTransform = frame.style.transform;
    frame.style.transform = 'none';
    try {
        return await html2canvas(target, {
            scale: 2,                 // خروجی ۱۸۲۶×۳۴۴۶
            useCORS: true,
            allowTaint: false,
            logging: false,
            backgroundColor: '#07030f',
            width: 1024,
            height: 1536,
            windowWidth: 1024,       // ← کلید ماجرا: بازسازی قالب در همین عرض
            windowHeight: 1536,
            scrollX: 0,
            scrollY: 0
        });
    } finally {
        frame.style.transform = prevTransform;
    }
}

function rpCanvasToBlob(canvas, type, quality) {
    return new Promise(function (resolve, reject) {
        canvas.toBlob(function (b) { b ? resolve(b) : reject(new Error('ساخت فایل ناموفق بود')); },
                      type || 'image/png', quality);
    });
}

/* عکس را می‌سازد و به ربات تلگرام ادمین می‌فرستد */
async function rpSendTelegram() {
    var msg = document.getElementById('rpDlMsg');
    var btn = document.getElementById('rpTgBtn');
    var orig = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال ارسال…'; }
    if (msg) { msg.style.color = '#8ea0c9'; msg.textContent = 'در حال ساخت عکس و ارسال به تلگرام…'; }

    try {
        var canvas = await rpRenderCanvas();
        // JPEG به‌جای PNG: PNGِ ۲۰۴۸×۳۰۷۲ حدود ۲.۳MB می‌شود و روی هاست‌هایی که
        // post_max_size آن‌ها ۲MB است، PHP کل آپلود را دور می‌ریزد و $_FILES
        // خالی می‌ماند — دقیقاً همان خطای «فایل دریافت نشد».
        // همین تصویر با JPEG کیفیت ۹۲٪ حدود ۰.۶MB است.
        var blob = await rpCanvasToBlob(canvas, 'image/jpeg', 0.92);

        var fd = new FormData();
        fd.append('image', blob, 'AvaPay-Rates.jpg');
        fd.append('caption', '📊 قیمت لحظه‌ای ارزها — AVA PAY');

        var res  = await fetch('api/rate_poster_api.php?action=send_telegram', { method: 'POST', body: fd });
        var data = await res.json();

        if (msg) {
            msg.style.color = data.success ? '#22d3a0' : '#ff6b6b';
            msg.textContent = data.message || (data.success ? 'ارسال شد' : 'ارسال ناموفق');
        }
        rpMsg(data.message || '', !!data.success);
    } catch (e) {
        if (msg) { msg.style.color = '#ff6b6b'; msg.textContent = 'خطا: ' + (e.message || 'دوباره تلاش کنید'); }
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = orig; }
    }
}

async function rpDownload() {
    var msg = document.getElementById('rpDlMsg');
    var btn = document.getElementById('rpDlBtn');
    var orig = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال ساخت…'; }
    if (msg) { msg.style.color = '#8ea0c9'; msg.textContent = 'رندر تصویر ممکن است چند ثانیه طول بکشد…'; }
    try {
        var canvas = await rpRenderCanvas();
        var blob   = await rpCanvasToBlob(canvas);
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'AvaPay-Rates-' + (rpCurrentId || Date.now()) + '.png';
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
        setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
        if (msg) { msg.style.color = '#22d3a0'; msg.textContent = 'عکس دانلود شد ✅'; }
    } catch (e) {
        if (msg) { msg.style.color = '#ff6b6b'; msg.textContent = 'خطا در ساخت تصویر: ' + (e.message || 'دوباره تلاش کنید'); }
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = orig; }
    }
}

document.addEventListener('DOMContentLoaded', rpLoadList);
</script>

<!-- ===== FOOTER ===== -->
<?php require_once 'includes/footer_menu.php'; renderFooterMenu('Home'); ?>

</body>
</html>