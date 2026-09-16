<?php
require_once __DIR__ . '/includes/camera_headers.php';
require_once __DIR__ . '/includes/session_boot.php';
require_once 'config/database.php';

// بازسازی سشن از روی توکن ۳۰ روزه
avapay_restore_session_from_token($conn);

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$userId = $_SESSION['user_id'];
$user = $conn->query("SELECT * FROM users WHERE id = $userId")->fetch_assoc();

// بررسی ادمین
$isAdmin = false;
$telegram = $conn->query("SELECT telegram_id FROM users WHERE id = $userId")->fetch_assoc();
if ($telegram && ($telegram['telegram_id'] == '5330629504' || $userId == 5330629504)) {
    $isAdmin = true;
}

// حذف خودکار تراکنش‌های تکمیل شده بعد از 7 روز
$checkColumn = $conn->query("SHOW COLUMNS FROM money_transfers LIKE 'completed_at'");
if ($checkColumn->num_rows > 0) {
    $conn->query("DELETE FROM money_transfers WHERE status = 'completed' AND completed_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
} else {
    // اگر ستون وجود ندارد، فقط بر اساس created_at حذف کن
    $conn->query("DELETE FROM money_transfers WHERE status = 'completed' AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
}

// دریافت درخواست‌ها
$myTransfers = [];
$res = $conn->query("SELECT * FROM money_transfers WHERE user_id = $userId ORDER BY created_at DESC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $myTransfers[] = $row;
    }
}

/* ===== (آپدیت ۱) گیرندگان قبلیِ حواله برای «گوی دایره‌ای آخرین واریزی‌ها» =====
 * از روی حواله‌های تکمیل‌شده‌ی کاربر، گیرندگان یکتا استخراج می‌شوند تا دفعهٔ بعد
 * کاربر فقط با تغییر مبلغ دوباره برایشان حواله بزند. */
$mtRecipients = [];
$__seenRcp = [];
foreach ($myTransfers as $__r) {
    $key = mb_strtolower(trim(($__r['full_name'] ?? '') . '|' . ($__r['iban'] ?? '')));
    if ($key === '|' || isset($__seenRcp[$key])) continue;
    if (empty($__r['full_name']) && empty($__r['iban'])) continue;
    $__seenRcp[$key] = true;
    $mtRecipients[] = [
        'name'     => $__r['full_name'] ?? '',
        'iban'     => $__r['iban'] ?? '',
        'bank'     => $__r['bank_name'] ?? '',
        'country'  => $__r['country'] ?? '',
        'cc'       => $__r['country_code'] ?? '',
        'currency' => $__r['currency'] ?? 'EUR',
        'info'     => $__r['short_info'] ?? '',
        'date'     => $__r['created_at'] ?? '',
    ];
    if (count($mtRecipients) >= 12) break;
}

$allTransfers = [];
if ($isAdmin) {
    $res2 = $conn->query("SELECT t.*, u.first_name, u.last_name, u.telegram_id, u.email 
                          FROM money_transfers t 
                          JOIN users u ON t.user_id = u.id 
                          ORDER BY t.created_at DESC");
    if ($res2) {
        while ($row = $res2->fetch_assoc()) {
            $allTransfers[] = $row;
        }
    }
}

// ===== آماره‌های حواله برای نمودار زنده (متصل به همان داده‌ی جدول) =====
$mtStats = ['pending' => 0, 'awaiting_payment' => 0, 'payment_submitted' => 0, 'completed' => 0, 'rejected' => 0, 'approved' => 0];
$mtTotalAmount = 0;
$mtMonthly = array_fill(0, 6, 0); // ۶ ماه اخیر (شمارش)
$mtNow = time();
foreach ($myTransfers as $__t) {
    $st = $__t['status'] ?? 'pending';
    if (isset($mtStats[$st])) $mtStats[$st]++;
    $mtTotalAmount += (float)($__t['amount'] ?? 0);
    $ts = strtotime($__t['created_at'] ?? 'now');
    $diffMonths = (int)floor(($mtNow - $ts) / (30 * 86400));
    if ($diffMonths >= 0 && $diffMonths < 6) $mtMonthly[5 - $diffMonths]++;
}
$mtActive = $mtStats['pending'] + $mtStats['awaiting_payment'] + $mtStats['payment_submitted'] + $mtStats['approved'];
$mtCompleted = $mtStats['completed'];
$mtInsights = [
    'active' => $mtActive,
    'completed' => $mtCompleted,
    'rejected' => $mtStats['rejected'],
    'total' => count($myTransfers),
    'totalAmount' => $mtTotalAmount,
    'monthly' => array_values($mtMonthly),
];

$countries = [
    ['code' => 'DE', 'name' => 'آلمان', 'flag' => '🇩🇪', 'currency' => 'EUR', 'color' => '#FFD700'],
    ['code' => 'UA', 'name' => 'اوکراین', 'flag' => '🇺🇦', 'currency' => 'UAH', 'color' => '#FFD700'],
    ['code' => 'FR', 'name' => 'فرانسه', 'flag' => '🇫🇷', 'currency' => 'EUR', 'color' => '#2196F3'],
    ['code' => 'GB', 'name' => 'انگلستان', 'flag' => '🇬🇧', 'currency' => 'GBP', 'color' => '#9C27B0'],
    ['code' => 'IT', 'name' => 'ایتالیا', 'flag' => '🇮🇹', 'currency' => 'EUR', 'color' => '#FF9800'],
    ['code' => 'ES', 'name' => 'اسپانیا', 'flag' => '🇪🇸', 'currency' => 'EUR', 'color' => '#E91E63'],
    ['code' => 'TR', 'name' => 'ترکیه', 'flag' => '🇹🇷', 'currency' => 'TRY', 'color' => '#4CAF50'],
    ['code' => 'AE', 'name' => 'امارات', 'flag' => '🇦🇪', 'currency' => 'AED', 'color' => '#00BCD4'],
    ['code' => 'US', 'name' => 'آمریکا', 'flag' => '🇺🇸', 'currency' => 'USD', 'color' => '#3F51B5'],
];
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl"<?php echo (isset($_GET['embed']) ? ' data-embed="1"' : ''); ?>>
<head>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="dns-prefetch" href="https://cdnjs.cloudflare.com">
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="dns-prefetch" href="https://cdn.jsdelivr.net">
<?php if (isset($_GET['embed'])): ?>
<style>
.footer-menu,.bottom-nav,#footerMenu{display:none!important;}
/* حالت مدال سریع: فقط فرم ثبت درخواست حواله نمایش داده شود */
.nav-buttons-section,.header-actions,.home-btn{display:none!important;}
body{padding:12px 14px 24px!important;}
.app-header{margin-bottom:12px!important;}
</style>
<?php endif; ?>
<?php if (isset($_GET['new'])): ?>
<style>
/* فقط مدال ثبت درخواست — بقیه‌ی صفحه مخفی */
body{background:transparent!important;animation:none!important;padding:0!important;}
.header-container,.app-header,.nav-buttons-section,.header-actions,.home-btn,
.fab-add,.requests-section,.stats-bar,.av-stats,#notificationDropdown,
.notification-overlay,.av-mt-stats{display:none!important;}
#stepModal{display:flex!important;}
#stepModal .modal-container{max-height:94vh;}
</style>
<?php endif; ?>
<script>(function(){try{var t=localStorage.getItem("ava_theme")||"dark";document.documentElement.setAttribute("data-theme",t);}catch(e){}})();</script>
    <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token_arad'] ?? ''); ?>">
    <script>
    // اتصال خودکار توکن CSRF به هر درخواست POST/PUT/DELETE هم‌مبدأ
    (function(){
        var m = document.querySelector('meta[name="csrf-token"]');
        var token = m ? m.content : '';
        var origFetch = window.fetch;
        window.fetch = function(input, init){
            init = init || {};
            var method = (init.method || 'GET').toUpperCase();
            if (method !== 'GET' && method !== 'HEAD') {
                init.headers = Object.assign({}, init.headers || {}, { 'X-CSRF-Token': token });
            }
            return origFetch.call(this, input, init);
        };
    })();
    </script>
    <title>حواله ارزی | AvaPay</title>
    <link rel="stylesheet" href="/ledor/assets/css/theme-light.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/theme-light.css') ?: time(); ?>">
    <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
    <link rel="preload" href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css"></noscript>
    <link rel="stylesheet" href="assets/css/aradphp.css">
    <link rel="stylesheet" href="/ledor/assets/css/notif.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/notif.css') ?: time(); ?>">
    <link rel="stylesheet" href="assets/css/avapay-modern.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/avapay-modern.css') ?: time(); ?>">
    <script defer src="assets/js/avapay-modern.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/avapay-modern.js') ?: time(); ?>"></script>
    <script src="assets/js/upload-compress.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/upload-compress.js') ?: time(); ?>" defer></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            background: linear-gradient(115deg, #0069ff, #010cff, #27021a, #3c0235, #0a1f1a, #0a1628, #1a0a2e);
            background-size: 400% 400%;
            animation: gradientShift 15s ease infinite;
            min-height: 100vh;
            font-family: -apple-system, 'Segoe UI', 'Tahoma', system-ui, sans-serif;
            padding: 16px;
            padding-bottom: 100px;
            color: #ffffff;
        }
        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        .container {
            max-width: 500px;
            margin: 0 auto;
        }

        /* ========== هدر ========== */
        .app-header {
            background: rgba(10, 10, 25, 0.75);
            backdrop-filter: blur(20px);
            border-radius: 32px;
            padding: 12px 18px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid rgba(255, 215, 0, 0.2);
            margin-top: 10px;
        }

        .logo-icon {
            width: 44px;
            height: 44px;
            
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 6px 15px rgba(255, 215, 0, 0.25);
        }

        .logo-icon i {
            font-size: 1.4rem;
            color: #1a1a2e;
        }

        .logo-text h1 {
            font-size: 1.25rem;
            background: linear-gradient(135deg, #FFD700, #FFA500);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .logo-text p {
            font-size: 0.6rem;
            color: rgba(255, 255, 255, 0.45);
        }

        .header-actions {
            display: flex;
            gap: 8px;
        }

        /* ========== نوار ناوبری سه دکمه ========== */
        .nav-buttons-section {
            margin: 16px 0 20px 0;
        }
        
        .nav-buttons-large {
            display: flex;
            gap: 12px;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.08), rgba(108, 64, 197, 0.08));
            backdrop-filter: blur(20px);
            border-radius: 60px;
            padding: 6px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .nav-large-btn {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 12px 16px;
            background: transparent;
            border-radius: 50px;
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.3s ease;
        }
        
        .nav-large-btn.active {
            background: linear-gradient(135deg, #FFD700, #FFA500);
            color: #1a1a2e;
            box-shadow: 0 4px 15px rgba(255, 215, 0, 0.3);
        }
        
        .nav-large-btn:not(.active):hover {
            background: rgba(255, 255, 255, 0.12);
            color: white;
            transform: translateY(-2px);
        }
        
        @media (max-width: 600px) {
            .nav-large-btn {
                padding: 10px;
                font-size: 0.75rem;
                gap: 6px;
            }
            .nav-large-btn i {
                font-size: 0.9rem;
            }
        }

        /* ========== استپ پروسه (مراحل) ========== */
        .process-steps {
            background: rgba(255, 255, 255, 0.03);
            border-radius: 28px;
            padding: 16px;
            margin-bottom: 24px;
            border: 1px solid rgba(255, 215, 0, 0.1);
        }

        .steps-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: nowrap;
            gap: 5px;
        }

        .process-step {
            flex: 1;
            text-align: center;
            position: relative;
        }

        .step-icon-circle {
            width: 48px;
            height: 48px;
            background: rgba(255, 255, 255, 0.05);
            border: 2px solid rgba(255, 215, 0, 0.25);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 8px;
            transition: all 0.3s;
        }

        .process-step.active .step-icon-circle {
            background: linear-gradient(135deg, #FFD700, #FFA500);
            border-color: transparent;
            box-shadow: 0 0 15px rgba(255, 215, 0, 0.4);
            transform: scale(1.05);
        }

        .process-step.completed .step-icon-circle {
            background: #4CD964;
            border-color: transparent;
        }

        .step-icon-circle i {
            font-size: 1.2rem;
            color: white;
        }

        .process-step.active .step-icon-circle i {
            color: #1a1a2e;
        }

        .step-title {
            font-size: 0.6rem;
            font-weight: 600;
            color: rgba(255, 255, 255, 0.5);
        }

        .process-step.active .step-title {
            color: #FFD700;
        }

        .process-step.completed .step-title {
            color: #4CD964;
        }

        .step-connector-line {
            width: 30px;
            height: 2px;
            background: linear-gradient(90deg, #FFD700, #6C40C5);
            border-radius: 2px;
        }

        /* ========== دکمه افزودن درخواست جدید ========== */
        .fab-add {
            width: 100%;
            background: linear-gradient(135deg, #FFD700, #FFA500);
            border: none;
            border-radius: 60px;
            padding: 16px 24px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 8px 20px rgba(255, 215, 0, 0.3);
            font-weight: 700;
            font-size: 1rem;
            color: #1a1a2e;
        }

        .fab-add i {
            font-size: 1.2rem;
        }

        .fab-add:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 28px rgba(255, 215, 0, 0.4);
        }

        .fab-add:active {
            transform: translateY(1px);
        }

        /* ========== کارت درخواست ========== */
        .requests-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            padding: 0 4px;
        }

        .requests-header .title {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .requests-header .title i {
            color: #FFD700;
            font-size: 1.1rem;
        }

        .requests-header .title h3 {
            color: white;
            font-size: 0.95rem;
            font-weight: 600;
        }

        .requests-count {
            background: rgba(255, 215, 0, 0.15);
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.7rem;
            color: #FFD700;
        }

        .request-card-modern {
            background: rgba(255, 255, 255, 0.04);
            backdrop-filter: blur(10px);
            border-radius: 24px;
            padding: 16px;
            margin-bottom: 12px;
            transition: all 0.3s;
            border: 1px solid rgba(255, 215, 0, 0.1);
            position: relative;
            overflow: hidden;
            color: white;
        }

        .request-card-modern::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(180deg, #FFD700, #FFA500);
            border-radius: 4px 0 0 4px;
        }

        .request-card-modern:hover {
            transform: translateY(-2px);
            background: rgba(255, 255, 255, 0.06);
            border-color: rgba(255, 215, 0, 0.2);
        }

        .card-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 8px;
        }

        .tracking-badge {
            background: rgba(0, 0, 0, 0.5);
            padding: 5px 12px;
            border-radius: 30px;
            font-family: monospace;
            font-size: 0.7rem;
            color: rgba(255, 255, 255, 0.8);
        }

        .tracking-badge i {
            color: #FFD700;
            margin-left: 5px;
        }

        .status-badge-modern {
            padding: 5px 14px;
            border-radius: 30px;
            font-size: 0.65rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .status-pending { background: rgba(255, 193, 7, 0.15); color: #FFC107; border: 1px solid rgba(255, 193, 7, 0.3); }
        .status-approved { background: rgba(23, 162, 184, 0.15); color: #17a2b8; border: 1px solid rgba(23, 162, 184, 0.3); }
        .status-rejected { background: rgba(255, 59, 48, 0.15); color: #FF3B30; border: 1px solid rgba(255, 59, 48, 0.3); }
        .status-payment_submitted { background: rgba(108, 64, 197, 0.15); color: #B07CF5; border: 1px solid rgba(108, 64, 197, 0.3); }
        .status-completed { background: rgba(76, 217, 100, 0.15); color: #4CD964; border: 1px solid rgba(76, 217, 100, 0.3); }

        .card-details {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 12px;
        }

        .info-row {
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(0,0,0,0.25);
            padding: 6px 12px;
            border-radius: 20px;
        }

        .info-row i {
            color: #FFD700;
        }

        .info-row span {
            font-size: 0.75rem;
        }

        .amount-text {
            color: #FFD700;
            font-weight: bold;
        }

        .card-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn-action {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 215, 0, 0.3);
            padding: 8px 16px;
            border-radius: 40px;
            color: #FFD700;
            font-size: 0.7rem;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-action:hover {
            background: rgba(255, 215, 0, 0.15);
            transform: scale(1.02);
        }

        .reject-note {
            margin-top: 12px;
            padding: 8px 12px;
            background: rgba(255,59,48,0.1);
            border-radius: 14px;
            font-size: 0.65rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* ========== پنل مدیریت ========== */
        .admin-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            margin-top: 24px;
            padding: 0 4px;
        }

        .admin-header .title {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .admin-header .title i {
            color: #FFD700;
            font-size: 1.1rem;
        }

        .admin-header .title h3 {
            color: white;
            font-size: 0.95rem;
            font-weight: 600;
        }

        .admin-table-container {
            background: rgba(255, 255, 255, 0.03);
            border-radius: 24px;
            padding: 12px;
            overflow-x: auto;
            border: 1px solid rgba(255, 215, 0, 0.1);
        }

        .status-select {
            background: rgba(0, 0, 0, 0.5);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            padding: 5px 8px;
            font-size: 0.7rem;
            cursor: pointer;
        }

        /* ========== مودال ========== */
        .step-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.96);
            backdrop-filter: blur(24px);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .modal-container {
            width: 100%;
            max-width: 450px;
            height: 100%;
            max-height: 750px;
            display: flex;
            flex-direction: column;
            background: linear-gradient(145deg, #12122a, #1a1a2e);
            border-radius: 32px 32px 0 0;
            overflow: hidden;
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from { transform: translateY(100%); }
            to { transform: translateY(0); }
        }

        .modal-header-step {
            padding: 20px 20px 12px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            position: relative;
        }

        .modal-header-step .close-btn {
            position: absolute;
            top: 20px;
            left: 20px;
            background: rgba(255, 255, 255, 0.08);
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: white;
        }

        .modal-header-step h2 {
            color: white;
            font-size: 1.3rem;
            font-weight: 600;
            text-align: center;
            margin-top: 8px;
        }

        .modal-header-step p {
            text-align: center;
            font-size: 0.75rem;
            color: rgba(255, 255, 255, 0.5);
            margin-top: 4px;
        }

        .step-indicator {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px;
            background: rgba(0, 0, 0, 0.2);
        }

        .step-dot {
            flex: 1;
            text-align: center;
        }

        .step-circle-small {
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.08);
            border: 1.5px solid rgba(255, 215, 0, 0.3);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
        }

        .step-dot.active .step-circle-small {
            background: linear-gradient(135deg, #FFD700, #FFA500);
            border-color: transparent;
            box-shadow: 0 0 12px rgba(255, 215, 0, 0.4);
        }

        .step-dot.completed .step-circle-small {
            background: #4CD964;
            border-color: transparent;
        }

        .step-circle-small i {
            font-size: 1rem;
            color: white;
        }

        .step-dot.active .step-circle-small i {
            color: #1a1a2e;
        }

        .step-label-small {
            font-size: 0.6rem;
            color: rgba(255, 255, 255, 0.5);
            margin-top: 6px;
        }

        .step-dot.active .step-label-small {
            color: #FFD700;
        }

        .step-line {
            flex: 1;
            height: 2px;
            background: rgba(255, 215, 0, 0.2);
            margin: 0 5px;
        }

        .step-content {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
        }

        .country-list {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }

        .country-item-modal {
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 215, 0, 0.15);
            border-radius: 18px;
            padding: 12px;
            text-align: right;
            cursor: pointer;
            transition: all 0.2s;
            min-width: 0;
        }

        .country-item-modal:hover, .country-item-modal.selected {
            background: linear-gradient(135deg, rgba(255, 215, 0, 0.2), rgba(255, 165, 0, 0.1));
            border-color: #FFD700;
            transform: translateY(-2px);
        }

        .country-flag-modal {
            flex: none;
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.3rem;
            line-height: 1;
        }
        .country-item-modal .country-text-modal { flex: 1; min-width: 0; overflow: hidden; }
        .country-name-modal { font-size: 0.8rem; font-weight: 600; color: white; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .country-currency-modal { font-size: 0.66rem; color: #FFD700; margin-top: 4px; }

        @media (max-width: 380px) {
            .country-list { grid-template-columns: 1fr; }
        }

        .selected-country-info {
            background: rgba(255,215,0,0.1);
            border-radius: 18px;
            padding: 14px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .form-group-modal { margin-bottom: 18px; }
        .form-group-modal label {
            display: block;
            margin-bottom: 8px;
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.8rem;
        }
        .form-group-modal label i { margin-left: 8px; color: #FFD700; }

        .form-group-modal input, .form-group-modal textarea {
            width: 100%;
            padding: 14px 16px;
            background: rgba(0, 0, 0, 0.35);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 18px;
            color: white;
            font-size: 0.9rem;
        }

        .form-group-modal input:focus, .form-group-modal textarea:focus {
            outline: none;
            border-color: #FFD700;
        }

        .modal-footer {
            display: flex;
            gap: 12px;
            padding: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
        }

        .btn-next, .btn-prev {
            flex: 1;
            padding: 14px;
            border-radius: 60px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.25s;
            border: none;
        }

        .btn-next {
            background: linear-gradient(135deg, #FFD700, #FFA500);
            color: #1a1a2e;
        }

        .btn-prev {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: white;
        }

        .upload-zone {
            border: 2px dashed rgba(255, 215, 0, 0.3);
            border-radius: 20px;
            padding: 30px;
            text-align: center;
            cursor: pointer;
            color: wheat;
        }

        .upload-zone:hover {
            border-color: #FFD700;
            background: rgba(255, 215, 0, 0.05);
        }

        .toast-message {
            position: fixed;
            bottom: 80px;
            left: 16px;
            right: 16px;
            background: linear-gradient(135deg, #1a1a2e, #16213e);
            border-radius: 20px;
            padding: 14px 18px;
            color: white;
            z-index: 2000;
            transform: translateY(200px);
            transition: transform 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55);
            display: flex;
            align-items: center;
            gap: 12px;
            border-right: 4px solid #4CD964;
        }

        .toast-message.show { transform: translateY(0); }
        .toast-message.error { border-right-color: #FF3B30; }

        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: rgba(255, 255, 255, 0.4);
        }

        .empty-state i { font-size: 3rem; margin-bottom: 12px; display: block; }

        @media (max-width: 480px) {
            body { padding: 12px; }
            .step-icon-circle { width: 40px; height: 40px; }
            .step-icon-circle i { font-size: 1rem; }
            .step-title { font-size: 0.5rem; }
            .step-connector-line { width: 15px; }
            .country-list { gap: 8px; }
            .country-flag-modal { width: 40px; height: 40px; font-size: 1.8rem; }
            .btn-action { padding: 6px 12px; font-size: 0.65rem; }
            .fab-add { padding: 14px 20px; font-size: 0.9rem; }
        }

        /* ===== (آپدیت ۱) گوی دایره‌ای آخرین واریزی‌ها ===== */
        .mt-last-recipients{ margin:14px 0 6px; }
        .mt-lr-head{ display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; gap:8px; flex-wrap:wrap; }
        .mt-lr-head span{ color:#FFD700; font-weight:700; font-size:.9rem; display:flex; align-items:center; gap:8px; }
        .mt-lr-head small{ color:rgba(255,255,255,.45); font-size:.66rem; }
        .mt-lr-scroll{ display:flex; gap:14px; overflow-x:auto; padding:6px 2px 10px; -webkit-overflow-scrolling:touch; }
        .mt-lr-scroll::-webkit-scrollbar{ height:5px; }
        .mt-lr-scroll::-webkit-scrollbar-thumb{ background:rgba(255,215,0,.3); border-radius:10px; }
        .mt-lr-item{ display:flex; flex-direction:column; align-items:center; gap:6px; cursor:pointer; min-width:68px;
            transition:transform .15s ease; }
        .mt-lr-item:hover{ transform:translateY(-3px); }
        .mt-lr-item:active{ transform:scale(.95); }
        .mt-lr-avatar{ width:56px; height:56px; border-radius:50%; display:flex; align-items:center; justify-content:center;
            font-size:1.4rem; font-weight:800; color:#1a1a2e;
            background:linear-gradient(135deg,#FFD700,#FFA500); box-shadow:0 6px 16px rgba(255,165,0,.28);
            border:2px solid rgba(255,255,255,.15); }
        .mt-lr-name{ font-size:.66rem; color:#fff; max-width:64px; text-align:center; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .mt-lr-cc{ font-size:.58rem; color:#FFD700; background:rgba(255,215,0,.12); padding:1px 8px; border-radius:8px; }
    </style>
</head>
<body>

<!-- هدر حرفه‌ای AvaPay -->
<div class="avapay-header">
    <div class="header-container">
        <div class="logo-section">
            <div class="logo-icon">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 2L2 7L12 12L22 7L12 2Z" stroke="url(#grad1)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M2 17L12 22L22 17" stroke="url(#grad2)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M2 12L12 17L22 12" stroke="url(#grad1)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <defs>
                        <linearGradient id="grad1" x1="0%" y1="0%" x2="100%" y2="0%">
                            <stop offset="0%" style="stop-color:#FFD700"/>
                            <stop offset="100%" style="stop-color:#FFA500"/>
                        </linearGradient>
                        <linearGradient id="grad2" x1="0%" y1="0%" x2="100%" y2="0%">
                            <stop offset="0%" style="stop-color:#6C40C5"/>
                            <stop offset="100%" style="stop-color:#FF4D8D"/>
                        </linearGradient>
                    </defs>
                </svg>
            </div>
            <div class="logo-text">
                <h1>Ava Pay</h1>
                <span>ارسال حواله ارزی به تمام نقاط دنیا</span>
            </div>
        </div>
        
        <div class="header-actions">
            <div class="notification-bell" id="notificationBell">
                <div class="bell-icon">
                    <i class="fas fa-bell"></i>
                    <span class="bell-dot" id="notificationDot" style="display: none;"></span>
                    <span class="notification-badge" id="notificationBadge" style="display: none;">0</span>
                </div>
            </div>
            <a href="/ledor/dashboard.php" class="home-btn">
                <i class="fas fa-home"></i>
            </a>
        </div>
    </div>
</div>

<!-- دراپ‌داون نوتیفیکیشن -->
<div class="notification-dropdown" id="notificationDropdown">
    <div class="dropdown-header">
        <div class="header-title">
            <i class="fas fa-bell"></i>
            <span>اعلان‌ها</span>
        </div>
        <button class="mark-all-read-btn" onclick="markAllNotificationsRead()">
            <i class="fas fa-check-double"></i>
            <span>خواندن همه</span>
        </button>
    </div>
    <div class="dropdown-body" id="notificationList">
        <div class="loading-state">
            <div class="loading-spinner"></div>
            <p>در حال بارگذاری...</p>
        </div>
    </div>
</div>
<div class="notification-overlay" id="notificationOverlay"></div>

    <!-- نوار ناوبری سه دکمه -->
    <div class="nav-buttons-section">
        <div class="nav-buttons-large">
            <a href="arad.php" class="nav-large-btn">
                <i class="fas fa-exchange-alt"></i>
                <span>تبادل ارزی</span>
            </a>
            <a href="money_transfer.php" class="nav-large-btn active">
                <i class="fas fa-money-bill-transfer"></i>
                <span>حواله ارزی</span>
            </a>
            <a href="includes/withdrawal_modal_system.php" class="nav-large-btn">
                <i class="fas fa-wallet"></i>
                <span>تسویه حساب</span>
            </a>
        </div>
    </div>

    <!-- استپ پروسه (مراحل حواله) -->
    <?php
    require_once __DIR__ . '/includes/wallet_hero.php';
    renderWalletHero([
        'USD'  => (float)($user['balance_usd']  ?? 0),
        'EUR'  => (float)($user['balance_eur']  ?? 0),
        'USDT' => (float)($user['balance_usdt'] ?? 0),
        'IRR'  => (float)($user['balance_irr']  ?? 0),
    ]);
    ?>

    <!-- ==================== نمای کلی + نمودار زنده حواله‌ها ==================== -->
    <div class="av-scope" id="avTransferScope">
      <script>window.__AV_MT__ = <?php echo json_encode($mtInsights, JSON_UNESCAPED_UNICODE); ?>;</script>
      <div class="av-insights">
        <div class="av-card">
          <div class="av-card-head">
            <h3><i class="fas fa-chart-pie"></i> وضعیت حواله‌ها</h3>
            <span class="av-chip" id="avMtTotalChip">۰ درخواست</span>
          </div>
          <div id="avMtDonut"></div>
        </div>
        <div class="av-card">
          <div class="av-card-head">
            <h3><i class="fas fa-chart-column"></i> روند ۶ ماه اخیر</h3>
            <span class="av-chip" id="avMtSumChip">—</span>
          </div>
          <div id="avMtSpark"></div>
        </div>
      </div>
    </div>

    <div class="process-steps">
        <div class="steps-container">
            <div class="process-step" id="processStep1">
                <div class="step-icon-circle"><i class="fas fa-flag-checkered"></i></div>
                <div class="step-title">انتخاب کشور</div>
            </div>
            <div class="step-connector-line"></div>
            <div class="process-step" id="processStep2">
                <div class="step-icon-circle"><i class="fas fa-edit"></i></div>
                <div class="step-title">ثبت اطلاعات</div>
            </div>
            <div class="step-connector-line"></div>
            <div class="process-step" id="processStep3">
                <div class="step-icon-circle"><i class="fas fa-clock"></i></div>
                <div class="step-title">در انتظار تایید</div>
            </div>
            <div class="step-connector-line"></div>
            <div class="process-step" id="processStep4">
                <div class="step-icon-circle"><i class="fas fa-receipt"></i></div>
                <div class="step-title">پرداخت و فیش</div>
            </div>
            <div class="step-connector-line"></div>
            <div class="process-step" id="processStep5">
                <div class="step-icon-circle"><i class="fas fa-check-double"></i></div>
                <div class="step-title">تکمیل شده</div>
            </div>
        </div>
    </div>

    <!-- راهنمای معرفی حساب: اگر کاربر هنوز حسابی معرفی نکرده -->
    <?php
        $__mtBens = [];
        if (isset($conn) && ($conn instanceof mysqli) && !empty($_SESSION['user_id'])) {
            $__mq = @$conn->query("SELECT id, full_name, card_number, iban, bank_name FROM user_beneficiaries WHERE user_id = " . (int)$_SESSION['user_id'] . " ORDER BY id DESC LIMIT 100");
            if ($__mq) while ($__mr = $__mq->fetch_assoc()) $__mtBens[] = $__mr;
        }
        $__mtBenCnt = count($__mtBens);
    ?>
    <?php if ($__mtBenCnt === 0): ?>
    <div style="margin:10px 0;background:rgba(255,217,61,.08);border:1px solid rgba(255,217,61,.3);border-radius:14px;padding:11px 14px;font-size:.76rem;line-height:1.8;color:#FFD93D;">
        <i class="fas fa-lightbulb"></i>
        هنوز حسابی برای تسویه معرفی نکرده‌اید. با معرفی حساب، دریافت وجه سریع‌تر انجام می‌شود.
        <a href="dashboard.php#avaBeneCard" target="_parent" style="color:#38BDF8;font-weight:800;text-decoration:none;">معرفی حساب »</a>
    </div>
    <?php endif; ?>

    <!-- ===== (آپدیت ۱) گوی دایره‌ای آخرین واریزی‌ها (گیرندگان قبلی) ===== -->
    <?php if (!empty($mtRecipients)): ?>
    <div class="mt-last-recipients">
        <div class="mt-lr-head">
            <span><i class="fas fa-user-friends"></i> آخرین واریزی‌های شما</span>
            <small>برای واریز دوباره، روی گیرنده بزنید</small>
        </div>
        <div class="mt-lr-scroll">
            <?php foreach ($mtRecipients as $rc):
                $__name = trim($rc['name']) !== '' ? $rc['name'] : 'گیرنده';
                $__initial = mb_substr($__name, 0, 1, 'UTF-8');
                $__rcJson = htmlspecialchars(json_encode($rc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES);
            ?>
            <div class="mt-lr-item" onclick='openTransferForRecipient(<?php echo $__rcJson; ?>)'>
                <div class="mt-lr-avatar"><?php echo htmlspecialchars($__initial); ?></div>
                <div class="mt-lr-name"><?php echo htmlspecialchars(mb_substr($__name, 0, 12)); ?></div>
                <div class="mt-lr-cc"><?php echo htmlspecialchars($rc['currency']); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- دکمه افزودن درخواست جدید (استایل دار) -->
    <button class="fab-add" onclick="openTransferModal()">
        <i class="fas fa-plus-circle"></i>
        <span>درخواست حواله جدید</span>
        <i class="fas fa-arrow-left"></i>
    </button>

    <!-- درخواست‌های حواله من -->
    <div class="requests-header">
        <div class="title">
            <i class="fas fa-history"></i>
            <h3>درخواست‌های حواله من</h3>
        </div>
        <div class="requests-count"><?php echo count($myTransfers); ?> درخواست</div>
    </div>

    <div id="requestsList">
        <?php if (empty($myTransfers)): ?>
        <div class="empty-state">
            <i class="fas fa-inbox"></i>
            <p>هیچ درخواستی ثبت نشده</p>
            <small>درخواست جدید ثبت کنید</small>
        </div>
        <?php else: ?>
        <?php foreach ($myTransfers as $t): ?>
        <div class="request-card-modern" id="req-<?php echo $t['id']; ?>" data-status="<?php echo htmlspecialchars($t['status']); ?>">
            <div class="card-top">
                <span class="tracking-badge"><i class="fas fa-barcode"></i> <?php echo $t['tracking_code']; ?></span>
                <span class="status-badge-modern status-<?php echo $t['status']; ?>">
                    <i class="fas <?php 
                        echo $t['status'] == 'pending' ? 'fa-hourglass-half' : 
                             ($t['status'] == 'approved' ? 'fa-check-circle' : 
                             ($t['status'] == 'awaiting_payment' ? 'fa-money-check-dollar' :
                             ($t['status'] == 'completed' ? 'fa-check-double' : 
                             ($t['status'] == 'payment_submitted' ? 'fa-upload' : 'fa-times-circle')))); 
                    ?>"></i>
                    <?php
                        $statusMap = [
                            'pending' => 'در انتظار',
                            'approved' => 'تایید شده',
                            'awaiting_payment' => 'در انتظار پرداخت',
                            'rejected' => 'رد شده',
                            'payment_submitted' => 'فیش ارسال',
                            'completed' => 'تکمیل شده'
                        ];
                        echo $statusMap[$t['status']] ?? $t['status'];
                    ?>
                </span>
            </div>
            <div class="card-details">
                <div class="info-row">
                    <i class="fas fa-flag"></i>
                    <span><?php echo $t['country']; ?></span>
                </div>
                <div class="info-row">
                    <i class="fas fa-coins"></i>
                    <span class="amount-text"><?php echo number_format($t['amount']); ?> <?php echo $t['currency']; ?></span>
                </div>
                <div class="info-row">
                    <i class="fas fa-calendar"></i>
                    <span><?php echo date('Y/m/d', strtotime($t['created_at'])); ?></span>
                </div>
            </div>
            <?php
                // شماره‌حساب‌هایی که ادمین اعلام کرده (اگر ستون موجود بود)
                $adminAccounts = [];
                if (!empty($t['admin_accounts'])) {
                    $decoded = json_decode($t['admin_accounts'], true);
                    if (is_array($decoded)) $adminAccounts = $decoded;
                }
            ?>
            <?php if ($t['status'] == 'awaiting_payment' && !empty($adminAccounts)): ?>
            <div class="pay-accounts-box" style="background:rgba(108,64,197,0.12);border:1px solid rgba(108,64,197,0.3);border-radius:14px;padding:12px;margin-top:10px;">
                <div style="font-size:.8rem;color:#a78bfa;margin-bottom:8px;"><i class="fas fa-credit-card"></i> واریز به یکی از کارت‌های زیر:</div>
                <?php foreach ($adminAccounts as $i => $acc): ?>
                    <?php
                        // پشتیبانی از ساختار جدید {name, card} و نسخه‌ی قدیمی (رشته)
                        if (is_array($acc)) { $accName = $acc['name'] ?? ''; $accCard = $acc['card'] ?? ''; }
                        else { $accName = ''; $accCard = $acc; }
                    ?>
                    <div style="background:rgba(0,0,0,0.2);border-radius:8px;padding:8px 10px;margin-bottom:6px;">
                        <?php if (!empty($accName)): ?>
                        <div style="font-size:.72rem;color:#ccc;margin-bottom:4px;"><i class="fas fa-user"></i> <?php echo htmlspecialchars($accName); ?></div>
                        <?php endif; ?>
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
                            <code style="font-size:.82rem;direction:ltr;"><?php echo htmlspecialchars($accCard); ?></code>
                            <button type="button" class="btn-action" style="padding:4px 10px;" onclick="copyText('<?php echo htmlspecialchars(addslashes($accCard)); ?>')"><i class="fas fa-copy"></i></button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="card-actions">
                <?php if ($t['status'] == 'awaiting_payment'): ?>
                <button class="btn-action" onclick="openPaymentModal(<?php echo $t['id']; ?>, '<?php echo $t['tracking_code']; ?>')">
                    <i class="fas fa-cloud-upload-alt"></i> پرداخت کردم و آپلود فیش
                </button>
                <?php elseif ($t['status'] == 'payment_submitted'): ?>
                <button class="btn-action" onclick="openPaymentModal(<?php echo $t['id']; ?>, '<?php echo $t['tracking_code']; ?>')">
                    <i class="fas fa-plus"></i> افزودن فیش دیگر
                </button>
                <?php endif; ?>
                <?php if ($t['status'] == 'completed' && (!empty($t['settlement_receipt']) || !empty($t['settlement_receipts']))): ?>
                <button class="btn-action" onclick="openReceiptViewer(<?php echo $t['id']; ?>)">
                    <i class="fas fa-receipt"></i> دریافت فیش
                </button>
                <?php endif; ?>
            </div>
            <?php if ($t['reject_reason']): ?>
            <div class="reject-note">
                <i class="fas fa-comment"></i>
                <span>دلیل رد: <?php echo htmlspecialchars($t['reject_reason']); ?></span>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if ($isAdmin): ?>
    <!-- پنل مدیریت به admin_panel.php منتقل شد -->
    <div class="admin-header">
        <div class="title">
            <i class="fas fa-crown"></i>
            <h3>مدیریت حواله‌ها در پنل ادمین</h3>
        </div>
    </div>
    <div class="admin-table-container" style="text-align:center;padding:24px;">
        <p style="color:#aaa;margin-bottom:16px;">مدیریت حواله‌های ارزی به «پنل مدیریت» منتقل شده است.</p>
        <a href="/ledor/admin_panel.php#transfers" class="btn-next" style="display:inline-block;text-decoration:none;padding:12px 28px;border-radius:14px;">
            <i class="fas fa-external-link-alt"></i> ورود به پنل مدیریت
        </a>
    </div>

    <!-- مودال تسویه ادمین -->
    <div id="settlementAdminModal" class="step-modal">
        <div class="modal-container" style="height: auto; border-radius: 32px;">
            <div class="modal-header-step">
                <div class="close-btn" onclick="closeModal('settlementAdminModal')"><i class="fas fa-times"></i></div>
                <h2>تسویه نهایی</h2>
                <p>آپلود فیش تسویه حواله</p>
            </div>
            <div class="step-content">
                <div class="upload-zone" onclick="document.getElementById('settlementFile').click()">
                    <i class="fas fa-cloud-upload-alt" style="font-size: 2rem; color: #FFD700;"></i>
                    <p>آپلود فیش تسویه</p>
                    <small>JPG, PNG, PDF</small>
                </div>
                <input type="file" id="settlementFile" style="display: none;" accept="image/*,.pdf">
                <input type="hidden" id="settlementId">
            </div>
            <div class="modal-footer">
                <button class="btn-next" onclick="submitSettlement()">تکمیل تراکنش</button>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- مودال اصلی استپ به استپ -->
<div id="stepModal" class="step-modal">
    <div class="modal-container">
        <div class="modal-header-step">
            <div class="close-btn" onclick="closeModal('stepModal')"><i class="fas fa-times"></i></div>
            <h2>درخواست حواله</h2>
            <p>اطلاعات را وارد کنید</p>
        </div>
        <div class="step-indicator">
            <div class="step-dot" id="modalStep1"><div class="step-circle-small"><i class="fas fa-globe"></i></div><div class="step-label-small">کشور</div></div>
            <div class="step-line"></div>
            <div class="step-dot" id="modalStep2"><div class="step-circle-small"><i class="fas fa-edit"></i></div><div class="step-label-small">اطلاعات</div></div>
            <div class="step-line"></div>
            <div class="step-dot" id="modalStep3"><div class="step-circle-small"><i class="fas fa-check-circle"></i></div><div class="step-label-small">تایید</div></div>
        </div>

        <div class="step-content" id="stepContent1">
            <div class="country-list" id="countryListModal">
                <?php foreach ($countries as $c): ?>
                <div class="country-item-modal" data-code="<?php echo $c['code']; ?>" data-name="<?php echo $c['name']; ?>" data-flag="<?php echo $c['flag']; ?>" data-currency="<?php echo $c['currency']; ?>" onclick="selectCountryModal(this)">
                    <div class="country-flag-modal"><?php echo $c['flag']; ?></div>
                    <div class="country-text-modal">
                        <div class="country-name-modal"><?php echo $c['name']; ?></div>
                        <div class="country-currency-modal"><?php echo $c['currency']; ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="step-content" id="stepContent2" style="display: none;">
            <div class="selected-country-info" id="selectedCountryInfoModal">
                <span id="selectedFlagModal" style="font-size: 2rem;">🇩🇪</span>
                <div><div id="selectedNameModal" style="font-weight: bold; color: white;">آلمان</div><div id="selectedCurrencyModal" style="font-size: 0.75rem; color: #FFD700;">EUR</div></div>
                <button class="btn-action" onclick="goToStep(1)" style="margin-right: auto; padding: 5px 12px;">تغییر</button>
            </div>
            <!-- اطلاعات گیرنده از حساب‌های معرفی‌شده انتخاب می‌شود -->
            <input type="hidden" id="fullNameModal">
            <input type="hidden" id="ibanModal">
            <input type="hidden" id="bankNameModal">
            <div class="form-group-modal">
                <label><i class="fas fa-address-book"></i> انتخاب گیرنده (حساب‌های معرفی‌شده)</label>
                <?php if ($__mtBenCnt === 0): ?>
                <div id="mtBenEmptyNotice" style="margin-bottom:8px;background:rgba(255,217,61,.08);border:1px solid rgba(255,217,61,.3);border-radius:12px;padding:10px 12px;font-size:.74rem;line-height:1.8;color:#FFD93D;">
                    <i class="fas fa-lightbulb"></i> برای ارسال حواله ابتدا باید حساب گیرنده را معرفی کنید.
                </div>
                <?php endif; ?>
                <input type="text" id="mtBenSearch" placeholder="جستجوی نام گیرنده..." oninput="mtBenFilter()">
                <div id="mtBenList" style="display:flex;flex-direction:column;gap:8px;max-height:200px;overflow-y:auto;margin-top:10px;">
                    <?php foreach ($__mtBens as $__b): $__h = ((int)$__b['id']*47)%360; ?>
                    <div class="mt-ben-row" data-name="<?php echo htmlspecialchars(mb_strtolower($__b['full_name'])); ?>"
                         onclick='mtBenPick(this, <?php echo json_encode([
                             "name"=>$__b["full_name"],"bank"=>$__b["bank_name"],
                             "iban"=>$__b["iban"] ?: $__b["card_number"]], JSON_UNESCAPED_UNICODE); ?>)'
                         style="display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:14px;cursor:pointer;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.05);transition:.2s;">
                        <div style="width:36px;height:36px;border-radius:50%;flex:none;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,hsl(<?php echo $__h; ?>,70%,55%),hsl(<?php echo ($__h+40)%360; ?>,70%,40%));">
                            <svg width="19" height="19" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8.2" r="3.6" fill="#fff"/><path d="M4.6 19.4c.9-3.6 3.9-5.6 7.4-5.6s6.5 2 7.4 5.6c.15.6-.33 1.1-.95 1.1H5.55c-.62 0-1.1-.5-.95-1.1z" fill="#fff"/><path d="M18.2 3.1l-1.05 2.2 1.75.55-2.6 3.05.75-2.35-1.6-.5 2.75-2.95z" fill="#FFD93D"/></svg>
                        </div>
                        <div style="min-width:0;">
                            <div style="font-size:.8rem;font-weight:800;color:#fff;"><?php echo htmlspecialchars($__b['full_name']); ?></div>
                            <div style="font-size:.65rem;color:rgba(255,255,255,.5);"><?php echo htmlspecialchars($__b['bank_name'] ?: ($__b['iban'] ?: $__b['card_number'])); ?></div>
                        </div>
                        <i class="fas fa-check-circle mt-ben-check" style="margin-right:auto;color:#22C55E;opacity:0;transition:.2s;"></i>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div style="margin-top:8px;font-size:.7rem;">
                    <button type="button" onclick="mtBenAddOpen()" style="background:none;border:none;padding:0;color:#38BDF8;font-weight:800;cursor:pointer;font-family:inherit;font-size:inherit;"><i class="fas fa-plus-circle"></i> معرفی حساب جدید</button>
                </div>
                <div id="mtBenPicked" style="display:none;margin-top:8px;padding:10px 14px;border-radius:12px;background:rgba(34,197,94,.10);border:1px solid rgba(34,197,94,.35);color:#86EFAC;font-size:.75rem;font-weight:700;"></div>
            </div>

            <!-- مدال درجای معرفی حساب جدید -->
            <div id="mtBenAddModal" style="display:none;position:fixed;inset:0;z-index:99999;align-items:center;justify-content:center;background:rgba(10,6,28,.72);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);padding:18px;">
                <div style="width:100%;max-width:360px;background:linear-gradient(165deg,#241556,#140C33);border:1px solid rgba(255,255,255,.16);border-radius:22px;padding:20px;box-shadow:0 24px 60px rgba(0,0,0,.6);">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
                        <div style="color:#fff;font-weight:900;font-size:.95rem;"><i class="fas fa-user-plus" style="color:#A855F7;"></i> معرفی حساب جدید</div>
                        <button type="button" onclick="mtBenAddClose()" style="background:rgba(255,255,255,.08);border:none;color:#fff;width:30px;height:30px;border-radius:50%;cursor:pointer;"><i class="fas fa-times"></i></button>
                    </div>
                    <input type="text" id="mtBenName"  placeholder="نام و نام خانوادگی گیرنده *" style="width:100%;margin-bottom:9px;padding:11px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.06);color:#fff;">
                    <input type="text" id="mtBenCard"  placeholder="شماره کارت" style="width:100%;margin-bottom:9px;padding:11px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.06);color:#fff;">
                    <input type="text" id="mtBenIban"  placeholder="شبا / IBAN (هر فرمتی)" style="width:100%;margin-bottom:9px;padding:11px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.06);color:#fff;">
                    <input type="text" id="mtBenBank"  placeholder="نام بانک" style="width:100%;margin-bottom:9px;padding:11px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.06);color:#fff;">
                    <input type="text" id="mtBenNote"  placeholder="توضیحات (اختیاری)" style="width:100%;margin-bottom:10px;padding:11px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.06);color:#fff;">
                    <div id="mtBenAddErr" style="display:none;margin-bottom:10px;padding:9px 12px;border-radius:10px;background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.4);color:#FCA5A5;font-size:.74rem;font-weight:700;"></div>
                    <button type="button" id="mtBenAddSaveBtn" onclick="mtBenAddSave()" style="width:100%;padding:12px;border:none;border-radius:14px;background:linear-gradient(135deg,#7C3AED,#A855F7);color:#fff;font-weight:900;cursor:pointer;font-family:inherit;">ذخیره و انتخاب گیرنده</button>
                </div>
            </div>
            <div class="form-group-modal"><label><i class="fas fa-coins"></i> مبلغ <span id="currencyLabelModal">(EUR)</span></label><input type="number" id="amountModal" placeholder="مبلغ" step="10" oninput="mtCheckBalance()"><div id="mtBalanceHint" class="mt-balance-hint"></div></div>
            <div class="form-group-modal"><label><i class="fas fa-info-circle"></i> توضیحات</label><textarea id="shortInfoModal" placeholder="کد سوئیفت، شماره مرجع..."></textarea></div>
        </div>

        <div class="step-content" id="stepContent3" style="display: none;">
            <div style="background: rgba(255,215,0,0.08); border-radius: 24px; padding: 20px;">
                <div style="text-align: center; margin-bottom: 20px;">
                    <i class="fas fa-clipboard-list" style="font-size: 2.5rem; color: #FFD700;"></i>
                    <h3 style="color: white; margin-top: 10px;">تایید نهایی</h3>
                    <p style="color: rgba(255,255,255,0.5); font-size: 0.75rem;">لطفاً اطلاعات را بررسی کنید</p>
                </div>
                <div id="confirmDetails" style="background: rgba(0,0,0,0.3); border-radius: 18px; padding: 16px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid rgba(255,255,255,0.1);">
                        <span style="color: rgba(255,255,255,0.6);">کشور مقصد:</span><span id="confirmCountry" style="color: white;">-</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid rgba(255,255,255,0.1);">
                        <span style="color: rgba(255,255,255,0.6);">نام گیرنده:</span><span id="confirmName" style="color: white;">-</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid rgba(255,255,255,0.1);">
                        <span style="color: rgba(255,255,255,0.6);">شماره IBAN:</span><span id="confirmIban" style="color: white;">-</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid rgba(255,255,255,0.1);">
                        <span style="color: rgba(255,255,255,0.6);">بانک:</span><span id="confirmBank" style="color: white;">-</span>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: rgba(255,255,255,0.6);">مبلغ:</span><span id="confirmAmount" style="color: #FFD700; font-weight: 700;">-</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal-footer">
            <button class="btn-prev" id="prevBtn" onclick="prevStep()" style="display: none;">قبلی</button>
            <button class="btn-next" id="nextBtn" onclick="nextStep()">ادامه</button>
        </div>
    </div>
</div>

<!-- مودال آپلود فیش پرداخت -->
<div id="paymentModal" class="step-modal">
    <div class="modal-container" style="height: auto; border-radius: 32px;">
        <div class="modal-header-step">
            <div class="close-btn" onclick="closeModal('paymentModal')"><i class="fas fa-times"></i></div>
            <h2>ارسال فیش پرداخت</h2>
            <p>فیش واریز خود را آپلود کنید</p>
        </div>
        <div class="step-content">
            <div style="background: rgba(255,215,0,0.1); border-radius: 16px; padding: 12px; margin-bottom: 20px; text-align: center;">
                کد پیگیری: <strong id="paymentCodeModal" style="color: #FFD700;"></strong>
            </div>
            <div class="upload-zone" onclick="document.getElementById('paymentFileModal').click()">
                <i class="fas fa-cloud-upload-alt" style="font-size: 2rem; color: #FFD700;"></i>
                <p>برای آپلود کلیک کنید (می‌توانید چند فیش انتخاب کنید)</p>
                <small>JPG, PNG, PDF — چند فایل مجاز است</small>
            </div>
            <input type="file" id="paymentFileModal" style="display: none;" accept="image/*,.pdf" multiple onchange="showPaymentFilesPreview()">
            <div id="paymentFilesPreview" style="margin-top:10px;font-size:.78rem;color:#aaa;"></div>
            <input type="hidden" id="paymentIdModal">
        </div>
        <div class="modal-footer">
            <button class="btn-next" onclick="submitPaymentModal()">ارسال فیش</button>
        </div>
    </div>
</div>

<!-- مودال نمایش فیش‌های تسویه (پیش‌نمایش + دانلود) -->
<div id="receiptViewerModal" class="step-modal">
    <div class="modal-container" style="height: auto; border-radius: 32px; max-width: 460px;">
        <div class="modal-header-step">
            <div class="close-btn" onclick="closeModal('receiptViewerModal')"><i class="fas fa-times"></i></div>
            <h2>فیش تسویه</h2>
            <p>برای بزرگ‌نمایی روی هر فیش بزنید</p>
        </div>
        <div class="step-content">
            <div id="receiptViewerBody" style="display:flex;flex-wrap:wrap;gap:12px;justify-content:center;min-height:120px;align-items:center;">
                <i class="fas fa-spinner fa-spin" style="font-size:1.6rem;color:#FFD700;"></i>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-next" id="receiptDownloadAllBtn" onclick="downloadAllReceipts()">
                <i class="fas fa-download"></i> دریافت فایل برای دانلود
            </button>
        </div>
    </div>
</div>

<!-- لایت‌باکس بزرگ‌نمایی تصویر -->
<div id="imageLightbox" onclick="closeLightbox(event)" style="display:none;position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,0.92);align-items:center;justify-content:center;flex-direction:column;padding:16px;">
    <div style="position:absolute;top:16px;left:16px;color:#fff;font-size:1.6rem;cursor:pointer;" onclick="closeLightbox(event, true)"><i class="fas fa-times"></i></div>
    <img id="lightboxImg" src="" style="max-width:96%;max-height:82%;border-radius:14px;object-fit:contain;box-shadow:0 10px 40px rgba(0,0,0,0.6);">
    <a id="lightboxDownload" href="#" download style="margin-top:18px;background:#FFD700;color:#000;padding:10px 26px;border-radius:30px;font-weight:700;text-decoration:none;font-family:inherit;" onclick="event.stopPropagation();">
        <i class="fas fa-download"></i> دانلود این فیش
    </a>
</div>


<script>
// ==================== پروسه استپ (مراحل) ====================
function updateProcessSteps(status) {
    // غیرفعال کردن همه استپ‌ها
    for (let i = 1; i <= 5; i++) {
        const step = document.getElementById(`processStep${i}`);
        if (step) {
            step.classList.remove('active', 'completed');
        }
    }
    
    // فعال کردن استپ بر اساس وضعیت
    let activeStep = 1;
    if (status === 'pending') activeStep = 3;
    else if (status === 'approved') activeStep = 3;
    else if (status === 'payment_submitted') activeStep = 4;
    else if (status === 'completed') activeStep = 5;
    else if (status === 'rejected') activeStep = 3;
    
    // علامت گذاری مراحل قبل از مرحله فعال
    for (let i = 1; i <= activeStep; i++) {
        const step = document.getElementById(`processStep${i}`);
        if (step) {
            if (i < activeStep) {
                step.classList.add('completed');
            } else if (i === activeStep) {
                step.classList.add('active');
            }
        }
    }
}

// ==================== بقیه توابع ====================
let currentStep = 1;
let selectedCountryModal = { code: 'DE', name: 'آلمان', flag: '🇩🇪', currency: 'EUR' };

/* (اصلاح ۳) موجودی کیف پول کاربر برای بررسی سمت کلاینت */
const MT_BALANCES = {
    USD:  <?php echo (float)($user['balance_usd']  ?? 0); ?>,
    EUR:  <?php echo (float)($user['balance_eur']  ?? 0); ?>,
    USDT: <?php echo (float)($user['balance_usdt'] ?? 0); ?>,
    IRR:  <?php echo (float)($user['balance_irr']  ?? 0); ?>
};
function mtFmt(n){ try { return new Intl.NumberFormat('en-US').format(n); } catch(e){ return n; } }
function mtCheckBalance(){
    const hint = document.getElementById('mtBalanceHint');
    if (!hint) return true;
    const cur = (selectedCountryModal.currency || 'EUR').toUpperCase();
    const bal = MT_BALANCES.hasOwnProperty(cur) ? MT_BALANCES[cur] : null;
    const amt = parseFloat(document.getElementById('amountModal').value) || 0;
    if (bal === null){ hint.innerHTML = ''; return true; }
    if (amt <= 0){
        hint.className = 'mt-balance-hint';
        hint.innerHTML = '<i class="fas fa-wallet"></i> موجودی شما: ' + mtFmt(bal) + ' ' + cur;
        return true;
    }
    if (amt > bal){
        hint.className = 'mt-balance-hint insufficient';
        hint.innerHTML = '<i class="fas fa-triangle-exclamation"></i> موجودی ناکافی — موجودی شما: ' + mtFmt(bal) + ' ' + cur + ' (ابتدا افزایش موجودی دهید)';
        return false;
    }
    hint.className = 'mt-balance-hint ok';
    hint.innerHTML = '<i class="fas fa-circle-check"></i> موجودی کافی است — باقیمانده پس از حواله: ' + mtFmt(bal - amt) + ' ' + cur;
    return true;
}

function openTransferModal() {
    document.getElementById('stepModal').style.display = 'flex';
    currentStep = 1;
    updateModalUI();
}

/* (آپدیت ۱) باز کردن مدال حواله برای یک گیرندهٔ قبلی — فقط مبلغ را عوض کن */
function openTransferForRecipient(rc){
    if (typeof rc === 'string'){ try { rc = JSON.parse(rc); } catch(e){ return; } }
    document.getElementById('stepModal').style.display = 'flex';
    // انتخاب کشور بر اساس گیرندهٔ قبلی
    selectedCountryModal = {
        code: rc.cc || 'DE',
        name: rc.country || '',
        flag: '🏳️',
        currency: rc.currency || 'EUR'
    };
    // اگر کشور در لیست موجود بود، پرچم و اطلاعات را از همان بگیر
    var match = document.querySelector('.country-item-modal[data-code="' + (rc.cc||'') + '"]');
    if (match){
        selectedCountryModal.flag = match.getAttribute('data-flag');
        selectedCountryModal.name = match.getAttribute('data-name');
        selectedCountryModal.currency = match.getAttribute('data-currency');
        document.querySelectorAll('.country-item-modal').forEach(function(i){ i.classList.remove('selected'); });
        match.classList.add('selected');
    }
    // پرکردن فیلدهای اطلاعات گیرنده
    document.getElementById('selectedFlagModal').innerHTML = selectedCountryModal.flag;
    document.getElementById('selectedNameModal').innerHTML = selectedCountryModal.name || '—';
    document.getElementById('selectedCurrencyModal').innerHTML = selectedCountryModal.currency;
    document.getElementById('currencyLabelModal').innerHTML = '(' + selectedCountryModal.currency + ')';
    document.getElementById('fullNameModal').value = rc.name || '';
    document.getElementById('ibanModal').value = rc.iban || '';
    document.getElementById('bankNameModal').value = rc.bank || '';
    document.getElementById('shortInfoModal').value = rc.info || '';
    document.getElementById('amountModal').value = '';
    // پرش به مرحلهٔ ۲ (اطلاعات) تا کاربر فقط مبلغ را وارد کند
    currentStep = 2;
    updateModalUI();
    setTimeout(function(){ var a=document.getElementById('amountModal'); if(a) a.focus(); }, 200);
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
    // در حالت «ثبت درخواست سریع» (iframe داخل داشبورد)، بستن مدال یعنی بستن کل قاب
    try {
        var params = new URLSearchParams(window.location.search);
        if (modalId === 'stepModal' && params.get('new') === '1' && window.parent && window.parent !== window) {
            if (typeof window.parent.axCloseServiceModal === 'function') {
                window.parent.axCloseServiceModal();
            } else if (typeof window.parent.avaCloseFsModal === 'function') {
                window.parent.avaCloseFsModal('avaTransferModal');
            }
        }
    } catch (e) {}
}

/* (اصلاح) محافظ سراسری بستن: قبلاً مودال‌های stepModal / mtBenAddModal /
   paymentModal فقط با onclick روی دکمه بسته می‌شدند — نه با کلیک روی
   پس‌زمینه، نه با Escape. اگر همان onclick اجرا نمی‌شد کاربر گیر می‌کرد. */
(function(){
    var IDS = ['stepModal', 'mtBenAddModal', 'paymentModal'];
    function topOpenId(){
        for (var i = 0; i < IDS.length; i++) {
            var el = document.getElementById(IDS[i]);
            if (el && window.getComputedStyle(el).display !== 'none') return IDS[i];
        }
        return null;
    }
    document.addEventListener('click', function(e){
        if (!e.target || !e.target.id || IDS.indexOf(e.target.id) === -1) return;
        closeModal(e.target.id); // کلیک روی خودِ پس‌زمینه (نه محتوای داخلش)
    });
    document.addEventListener('keydown', function(e){
        if (e.key !== 'Escape') return;
        var id = topOpenId();
        if (id) closeModal(id);
    });
})();

function updateModalUI() {
    document.getElementById('stepContent1').style.display = currentStep === 1 ? 'block' : 'none';
    document.getElementById('stepContent2').style.display = currentStep === 2 ? 'block' : 'none';
    document.getElementById('stepContent3').style.display = currentStep === 3 ? 'block' : 'none';
    
    for (let i = 1; i <= 3; i++) {
        const stepDot = document.getElementById(`modalStep${i}`);
        if (i < currentStep) { stepDot.classList.add('completed'); stepDot.classList.remove('active'); }
        else if (i === currentStep) { stepDot.classList.add('active'); stepDot.classList.remove('completed'); }
        else { stepDot.classList.remove('active', 'completed'); }
    }
    
    const prevBtn = document.getElementById('prevBtn');
    const nextBtn = document.getElementById('nextBtn');
    prevBtn.style.display = currentStep === 1 ? 'none' : 'block';
    nextBtn.innerHTML = currentStep === 3 ? 'ثبت نهایی' : 'ادامه';
    
    if (currentStep === 3) {
        document.getElementById('confirmCountry').innerHTML = `${selectedCountryModal.flag} ${selectedCountryModal.name}`;
        document.getElementById('confirmName').innerHTML = document.getElementById('fullNameModal').value || '---';
        document.getElementById('confirmIban').innerHTML = document.getElementById('ibanModal').value || '---';
        document.getElementById('confirmBank').innerHTML = document.getElementById('bankNameModal').value || '---';
        document.getElementById('confirmAmount').innerHTML = `${document.getElementById('amountModal').value || '0'} ${selectedCountryModal.currency}`;
    }
}

function nextStep() {
    if (currentStep === 1) {
        if (!selectedCountryModal) { showToast('لطفاً کشور را انتخاب کنید', true); return; }
        currentStep = 2; updateModalUI();
    } else if (currentStep === 2) {
        const fullName = document.getElementById('fullNameModal').value.trim();
        const iban = document.getElementById('ibanModal').value.trim();
        const bankName = document.getElementById('bankNameModal').value.trim();
        const amount = document.getElementById('amountModal').value;
        if (!fullName || !iban) { showToast('لطفاً گیرنده را از فهرست حساب‌های معرفی‌شده انتخاب کنید', true); return; }
        if (!amount) { showToast('لطفاً مبلغ را وارد کنید', true); return; }
        if (parseFloat(amount) < 10) { showToast('حداقل مبلغ 10 واحد ارز است', true); return; }
        currentStep = 3; updateModalUI();
    } else if (currentStep === 3) { submitRequestFromModal(); }
}

function prevStep() { if (currentStep > 1) { currentStep--; updateModalUI(); } }
function goToStep(step) { currentStep = step; updateModalUI(); }

/* === انتخاب گیرنده از حساب‌های معرفی‌شده === */
function mtBenPick(el, b){
    const set = (id,v)=>{ const x=document.getElementById(id); if(x) x.value = v || ''; };
    set('fullNameModal', b.name); set('bankNameModal', b.bank); set('ibanModal', b.iban);
    document.querySelectorAll('.mt-ben-row').forEach(r=>{
        r.style.borderColor='rgba(255,255,255,.12)'; r.style.background='rgba(255,255,255,.05)';
        const c=r.querySelector('.mt-ben-check'); if(c) c.style.opacity='0';
    });
    el.style.borderColor='rgba(34,197,94,.6)'; el.style.background='rgba(34,197,94,.10)';
    const ck=el.querySelector('.mt-ben-check'); if(ck) ck.style.opacity='1';
    const pk=document.getElementById('mtBenPicked');
    if(pk){ pk.style.display='block'; pk.innerHTML='<i class="fas fa-check-circle"></i> گیرنده: '+(b.name||'')+(b.bank?' — '+b.bank:''); }
}
function mtBenFilter(){
    const q=(document.getElementById('mtBenSearch')?.value||'').trim().toLowerCase();
    document.querySelectorAll('.mt-ben-row').forEach(r=>{
        r.style.display = (!q || (r.dataset.name||'').includes(q)) ? '' : 'none';
    });
}
/* === مدال درجای معرفی حساب جدید === */
function mtBenAddOpen(){ const m=document.getElementById('mtBenAddModal'); if(m) m.style.display='flex'; }
function mtBenAddClose(){ const m=document.getElementById('mtBenAddModal'); if(m) m.style.display='none'; }
async function mtBenAddSave(){
    const val = id => (document.getElementById(id)?.value || '').trim();
    const err = document.getElementById('mtBenAddErr');
    const btn = document.getElementById('mtBenAddSaveBtn');
    const show = m => { if(err){ err.textContent=m; err.style.display='block'; } };
    if(err) err.style.display='none';
    const name = val('mtBenName'), card = val('mtBenCard'), iban = val('mtBenIban');
    if(!name) return show('نام گیرنده را وارد کنید');
    if(!card && !iban) return show('حداقل یکی از شماره کارت یا شبا لازم است');
    if(btn) btn.disabled = true;
    try{
        const r = await fetch('/ledor/dashboard.php?ava=ben_add', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ full_name:name, card_number:card, iban:iban, bank_name:val('mtBenBank'), note:val('mtBenNote') })
        });
        const d = await r.json();
        if(d.success){
            const hue = ((parseInt(d.id,10)||0)*47)%360;
            const bank = val('mtBenBank');
            const row = document.createElement('div');
            row.className = 'mt-ben-row';
            row.dataset.name = name.toLowerCase();
            row.style.cssText = 'display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:14px;cursor:pointer;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.05);transition:.2s;';
            row.innerHTML =
                '<div style="width:36px;height:36px;border-radius:50%;flex:none;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,hsl('+hue+',70%,55%),hsl('+((hue+40)%360)+',70%,40%));">'
              + '<svg width="19" height="19" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8.2" r="3.6" fill="#fff"/><path d="M4.6 19.4c.9-3.6 3.9-5.6 7.4-5.6s6.5 2 7.4 5.6c.15.6-.33 1.1-.95 1.1H5.55c-.62 0-1.1-.5-.95-1.1z" fill="#fff"/><path d="M18.2 3.1l-1.05 2.2 1.75.55-2.6 3.05.75-2.35-1.6-.5 2.75-2.95z" fill="#FFD93D"/></svg></div>'
              + '<div style="min-width:0;"><div style="font-size:.8rem;font-weight:800;color:#fff;"></div>'
              + '<div style="font-size:.65rem;color:rgba(255,255,255,.5);"></div></div>'
              + '<i class="fas fa-check-circle mt-ben-check" style="margin-right:auto;color:#22C55E;opacity:0;transition:.2s;"></i>';
            row.children[1].children[0].textContent = name;
            row.children[1].children[1].textContent = bank || iban || card;
            const b = { name:name, bank:bank, iban:iban || card };
            row.onclick = function(){ mtBenPick(row, b); };
            const list = document.getElementById('mtBenList');
            if(list) list.prepend(row);
            const notice = document.getElementById('mtBenEmptyNotice'); if(notice) notice.style.display='none';
            ['mtBenName','mtBenCard','mtBenIban','mtBenBank','mtBenNote'].forEach(i=>{ const x=document.getElementById(i); if(x) x.value=''; });
            mtBenAddClose();
            mtBenPick(row, b); // انتخاب خودکار گیرنده‌ی تازه
        } else show(d.error || 'خطا در ذخیره');
    }catch(e){ show('خطای ارتباط با سرور'); }
    if(btn) btn.disabled = false;
}

function selectCountryModal(element) {
    document.querySelectorAll('.country-item-modal').forEach(item => item.classList.remove('selected'));
    element.classList.add('selected');
    selectedCountryModal = {
        code: element.getAttribute('data-code'),
        name: element.getAttribute('data-name'),
        flag: element.getAttribute('data-flag'),
        currency: element.getAttribute('data-currency')
    };
    document.getElementById('selectedFlagModal').innerHTML = selectedCountryModal.flag;
    document.getElementById('selectedNameModal').innerHTML = selectedCountryModal.name;
    document.getElementById('selectedCurrencyModal').innerHTML = selectedCountryModal.currency;
    document.getElementById('currencyLabelModal').innerHTML = `(${selectedCountryModal.currency})`;
}

async function submitRequestFromModal() {
    const btn = document.querySelector('#stepModal .btn-next');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال ارسال...';
    try {
        const res = await fetch('api/transfer_api.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'create',
                country_code: selectedCountryModal.code,
                country: selectedCountryModal.name,
                currency: selectedCountryModal.currency,
                full_name: document.getElementById('fullNameModal').value.trim(),
                iban: document.getElementById('ibanModal').value.trim(),
                bank_name: document.getElementById('bankNameModal').value.trim(),
                amount: parseFloat(document.getElementById('amountModal').value),
                short_info: document.getElementById('shortInfoModal').value.trim()
            })
        });
        const result = await res.json();
        if (result.success) { showToast(result.message, false); closeModal('stepModal'); setTimeout(() => location.reload(), 2000); }
        else if (result.code === 'insufficient_balance' || result.need_topup) {
            // (اصلاح ۳) موجودی ناکافی: راهنمایی کاربر برای افزایش موجودی
            showToast(result.message || 'موجودی کافی نیست؛ ابتدا افزایش موجودی دهید.', true);
            if (confirm('موجودی کیف پول شما برای این حواله کافی نیست.\nبرای افزایش موجودی اقدام می‌کنید؟')) {
                try {
                    if (window.parent && window.parent !== window && typeof window.parent.avaOpenServiceModal === 'function') {
                        // داخل iframe داشبورد: مدال حواله را ببند و شیت افزایش موجودی را باز کن
                        if (typeof window.parent.avaCloseFsModal === 'function') window.parent.avaCloseFsModal('avaTransferModal');
                        if (typeof window.parent.avaOpenTopup === 'function') { window.parent.avaOpenTopup(); }
                        else { window.parent.location.href = 'dashboard.php'; }
                    } else if (window.parent && window.parent !== window && typeof window.parent.axCloseServiceModal === 'function') {
                        // داخل iframe صفحه تبادل ارزی (arad)
                        window.parent.axCloseServiceModal();
                        window.parent.location.href = 'dashboard.php';
                    } else {
                        window.location.href = 'dashboard.php';
                    }
                } catch(e) { window.location.href = 'dashboard.php'; }
            }
        }
        else { showToast(result.message, true); }
    } catch(e) { showToast('خطا در ارسال درخواست', true); }
    finally { btn.disabled = false; btn.innerHTML = 'ثبت نهایی'; }
}

function openPaymentModal(id, code) {
    document.getElementById('paymentIdModal').value = id;
    document.getElementById('paymentCodeModal').innerHTML = code;
    document.getElementById('paymentModal').style.display = 'flex';
}

function showPaymentFilesPreview() {
    const files = document.getElementById('paymentFileModal').files;
    const box = document.getElementById('paymentFilesPreview');
    if (!files.length) { box.innerHTML = ''; return; }
    box.innerHTML = '<i class="fas fa-paperclip"></i> ' + files.length + ' فایل انتخاب شد: ' +
        Array.from(files).map(f => f.name).join('، ');
}

function copyText(txt) {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(txt).then(() => showToast('کپی شد', false)).catch(() => {});
    } else {
        const ta = document.createElement('textarea');
        ta.value = txt; document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); showToast('کپی شد', false); } catch(e) {}
        document.body.removeChild(ta);
    }
}

async function submitPaymentModal() {
    const files = document.getElementById('paymentFileModal').files;
    const id = document.getElementById('paymentIdModal').value;
    if (!files.length) { showToast('لطفاً حداقل یک فایل انتخاب کنید', true); return; }
    const btn = document.querySelector('#paymentModal .btn-next') || (typeof event!=='undefined' && event ? event.target : null);
    const orig = btn ? btn.innerHTML : '';
    if (btn){ btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> فشرده‌سازی...'; }
    try {
        let out = Array.prototype.slice.call(files);
        if (typeof window.AvaCompressFiles === 'function'){ try { out = await window.AvaCompressFiles(files); } catch(e){} }
        if (btn){ btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ارسال...'; }
        const formData = new FormData();
        formData.append('action', 'upload_receipt');
        formData.append('request_id', id);
        out.forEach(function(f){ formData.append('file[]', f, f.name || 'receipt.jpg'); });
        if (typeof window.avaShowUploadProgress === 'function') window.avaShowUploadProgress('در حال آپلود فیش...');
        const result = (typeof window.avaUploadWithProgress === 'function')
            ? await window.avaUploadWithProgress('api/transfer_api.php', formData)
            : await (await fetch('api/transfer_api.php', { method: 'POST', body: formData })).json();
        if (typeof window.avaHideUploadProgress === 'function') window.avaHideUploadProgress(!!result.success, result.message);
        if (result.success) { showToast(result.message, false); closeModal('paymentModal'); setTimeout(() => location.reload(), 800); }
        else { showToast(result.message, true); if (btn){ btn.disabled=false; btn.innerHTML = orig; } }
    } catch(e) { if (typeof window.avaHideUploadProgress === 'function') window.avaHideUploadProgress(false); showToast('خطا', true); if (btn){ btn.disabled=false; btn.innerHTML = orig; } }
}


async function submitSettlement() {
    const fileEl = document.getElementById('settlementFile');
    const files = fileEl ? fileEl.files : null;
    const id = document.getElementById('settlementId').value;
    if (!files || !files.length) { showToast('لطفاً فایل را انتخاب کنید', true); return; }
    const btn = document.querySelector('#settlementAdminModal .btn-next') || (typeof event!=='undefined' && event ? event.target : null);
    const orig = btn ? btn.innerHTML : '';
    if (btn){ btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> فشرده‌سازی...'; }
    try {
        let out = Array.prototype.slice.call(files);
        if (typeof window.AvaCompressFiles === 'function'){ try { out = await window.AvaCompressFiles(files); } catch(e){} }
        if (btn){ btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ارسال...'; }
        const formData = new FormData();
        formData.append('action', 'upload_settlement');
        formData.append('request_id', id);
        // پشتیبانی از چند فیش تسویه
        out.forEach(function(f){ formData.append('file[]', f, f.name || 'settlement.jpg'); });
        const res = await fetch('api/transfer_api.php', { method: 'POST', body: formData });
        const result = await res.json();
        if (result.success) { showToast(result.message, false); closeModal('settlementAdminModal'); setTimeout(() => location.reload(), 800); }
        else { showToast(result.message, true); if (btn){ btn.disabled=false; btn.innerHTML = orig; } }
    } catch(e) { showToast('خطا', true); if (btn){ btn.disabled=false; btn.innerHTML = orig; } }
}


function downloadReceipt(id, index) { window.open(`api/transfer_api.php?action=download_receipt&request_id=${id}&index=${index||0}`, '_blank'); }

// ---------- نمایش فیش‌های تسویه به‌صورت پیش‌نمایش ----------
let _receiptViewerId = null;
let _receiptViewerList = [];

async function openReceiptViewer(id) {
    _receiptViewerId = id;
    _receiptViewerList = [];
    const body = document.getElementById('receiptViewerBody');
    body.innerHTML = '<i class="fas fa-spinner fa-spin" style="font-size:1.6rem;color:#FFD700;"></i>';
    document.getElementById('receiptViewerModal').style.display = 'flex';
    try {
        const res = await fetch(`api/transfer_api.php?action=get_settlement_receipts&request_id=${id}`, { cache:'no-store' });
        const data = await res.json();
        if (!data.success || !data.receipts || !data.receipts.length) {
            body.innerHTML = '<div style="color:#aaa;font-size:.85rem;">فیشی برای نمایش وجود ندارد</div>';
            document.getElementById('receiptDownloadAllBtn').style.display = 'none';
            return;
        }
        _receiptViewerList = data.receipts;
        document.getElementById('receiptDownloadAllBtn').style.display = '';
        body.innerHTML = data.receipts.map((r, i) => {
            const url = r.startsWith('http') ? r : r; // مسیر نسبی از ریشه‌ی همین صفحه (uploads/...)
            const isPdf = /\.pdf$/i.test(r);
            if (isPdf) {
                return `<a href="${url}" target="_blank" style="display:flex;flex-direction:column;align-items:center;gap:6px;background:rgba(255,255,255,0.06);border-radius:14px;padding:18px 22px;text-decoration:none;color:#fff;">
                            <i class="fas fa-file-pdf" style="font-size:2.4rem;color:#ff5a5f;"></i>
                            <span style="font-size:.72rem;">فیش ${i+1} (PDF)</span>
                        </a>`;
            }
            return `<div style="position:relative;cursor:pointer;" onclick="openLightbox('${url}', ${id}, ${i})">
                        <img src="${url}" style="width:120px;height:120px;object-fit:cover;border-radius:14px;border:1px solid rgba(255,255,255,0.12);">
                        <div style="position:absolute;bottom:6px;right:6px;background:rgba(0,0,0,0.6);color:#fff;font-size:.62rem;padding:2px 8px;border-radius:10px;">فیش ${i+1} <i class="fas fa-search-plus"></i></div>
                    </div>`;
        }).join('');
    } catch(e) {
        body.innerHTML = '<div style="color:#ff6b6b;font-size:.85rem;">خطا در دریافت فیش‌ها</div>';
    }
}

function downloadAllReceipts() {
    if (!_receiptViewerId || !_receiptViewerList.length) return;
    _receiptViewerList.forEach((r, i) => {
        setTimeout(() => downloadReceipt(_receiptViewerId, i), i * 400);
    });
    showToast('در حال دانلود ' + _receiptViewerList.length + ' فیش...', false);
}

function openLightbox(url, id, index) {
    const box = document.getElementById('imageLightbox');
    document.getElementById('lightboxImg').src = url;
    const dl = document.getElementById('lightboxDownload');
    if (id != null && index != null) {
        dl.href = `api/transfer_api.php?action=download_receipt&request_id=${id}&index=${index}`;
        dl.style.display = '';
    } else {
        dl.href = url;
        dl.style.display = '';
    }
    box.style.display = 'flex';
}

function closeLightbox(e, force) {
    // بستن با کلیک روی پس‌زمینه یا دکمه‌ی ضربدر (force=true)
    if (force || (e && e.target && e.target.id === 'imageLightbox')) {
        if (e) e.stopPropagation();
        document.getElementById('imageLightbox').style.display = 'none';
    }
}

function showToast(msg, isError = false) {
    const toast = document.createElement('div');
    toast.className = `toast-message ${isError ? 'error' : ''}`;
    toast.innerHTML = `<i class="fas ${isError ? 'fa-exclamation-triangle' : 'fa-check-circle'}"></i><span style="flex:1;">${msg}</span>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.classList.add('show'), 10);
    setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 300); }, 3000);
}

window.onclick = function(e) { if (e.target.classList.contains('step-modal')) e.target.style.display = 'none'; }

// بروزرسانی پروسه استپ‌ها بر اساس وضعیت هر درخواست
<?php foreach ($myTransfers as $t): ?>
updateProcessSteps('<?php echo $t['status']; ?>');
<?php break; ?>
<?php endforeach; ?>
</script>
<?php require_once 'includes/footer_menu.php'; renderFooterMenu('dashboard'); ?>
<script src="assets/js/notification-system.js"></script>
<script>
/* ==== نمودارها و پیشرفت گام‌ها برای صفحه حواله ارزی ==== */
(function () {
  var _avTries = 0;
  function boot() {
    try {
    if ((!window.AvaPay || !window.__AV_MT__)) { if (_avTries++ < 50) return setTimeout(boot, 80); return; }
    var AV = window.AvaPay, d = window.__AV_MT__;
    var faNum = function (n) { return Number(n || 0).toLocaleString('en-US'); };

    // چیپ‌ها
    var chip = document.getElementById('avMtTotalChip');
    if (chip) chip.textContent = faNum(d.total) + ' درخواست';
    // واحدهای فارسی: «۱.۳ میلیارد» به‌جای عدد خام
    var compact = function (n) {
      n = Number(n) || 0;
      if (n >= 1e9) return (n/1e9).toFixed(n >= 1e10 ? 0 : 1).replace(/\.0$/,'') + ' میلیارد';
      if (n >= 1e6) return (n/1e6).toFixed(n >= 1e7 ? 0 : 1).replace(/\.0$/,'') + ' میلیون';
      if (n >= 1e3) return (n/1e3).toFixed(n >= 1e4 ? 0 : 1).replace(/\.0$/,'') + ' هزار';
      return faNum(n);
    };
    var sum = document.getElementById('avMtSumChip');
    if (sum) sum.textContent = 'مجموع ' + compact(Math.round(d.totalAmount));

    // دونات وضعیت
    var donutHost = document.getElementById('avMtDonut');
    if (donutHost) {
      if ((d.total || 0) === 0) {
        donutHost.innerHTML = '<div style="color:rgba(255,255,255,.45);font-size:.8rem;text-align:center;padding:20px 0;">هنوز حواله‌ای ثبت نشده</div>';
      } else {
        donutHost.appendChild(AV.donut({
          centerValue: d.total, centerLabel: 'کل',
          segments: [
            { label: 'در جریان', value: d.active, color: '#38bdf8', display: faNum(d.active) },
            { label: 'تکمیل‌شده', value: d.completed, color: '#35d07f', display: faNum(d.completed) },
            { label: 'رد‌شده', value: d.rejected, color: '#ff5a6e', display: faNum(d.rejected) }
          ]
        }));
      }
    }

    // نمودار روند ماهانه
    var sparkHost = document.getElementById('avMtSpark');
    if (sparkHost) {
      var months = ['۵ ماه پیش', '۴ ماه', '۳ ماه', '۲ ماه', 'ماه قبل', 'این ماه'];
      var data = (d.monthly || []).map(function (v, i) { return { v: v, label: months[i] || '' }; });
      if (!data.length) data = months.map(function (m) { return { v: 0, label: m }; });
      sparkHost.appendChild(AV.spark(data, {}));
    }

    // نوار گام مینی روی هر کارت
    document.querySelectorAll('#requestsList [data-status]').forEach(function (card) {
      var st = card.getAttribute('data-status');
      var on = AV.statusToSteps(st, 5);
      var host = card.querySelector('.card-details') || card;
      var bar = AV.stepsMini(on, 5);
      host.parentNode.insertBefore(bar, host.nextSibling);
    });
    } catch (e) { if (window.console) console.warn("AvaPay charts:", e); }
  }
  boot();
})();
</script>
<?php if (isset($_GET['new'])): ?>
<script>
// حالت «ثبت درخواست سریع»: مدال درخواست را بلافاصله باز کن
document.addEventListener('DOMContentLoaded', function(){
    try {
        if (typeof openTransferModal === 'function') openTransferModal();
        else { var m = document.getElementById('stepModal'); if (m) m.style.display = 'flex'; }
    } catch(e){}
});
</script>
<?php endif; ?>

<!-- ==========================================================================
     AvaPay · «Nova» — تم مشترک. عمداً در انتهای <body>: بلوک‌های استایل بالای
     همین فایل با !important می‌نویسند و هر شیوه‌نامه‌ای در <head> بازنده‌ی کسکید است.
     ========================================================================== -->
<link rel="stylesheet" href="assets/css/nova-shared.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/nova-shared.css') ?: time(); ?>">
<script defer src="assets/js/nova-chart.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/nova-chart.js') ?: time(); ?>"></script>
</body>
</html>