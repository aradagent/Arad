<?php
// includes/withdrawal_modal_system.php
// سیستم کامل تسویه حساب - نسخه نهایی با رفع مشکلات
error_reporting(E_ALL);
ini_set('display_errors', 0); // در تولید هرگز خطا مستقیم در مرورگر نمایش داده نشود
ini_set('log_errors', 1);
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/camera_headers.php';

// بررسی لاگین بودن کاربر
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$userId = $_SESSION['user_id'];

// دریافت اطلاعات کاربر
$sql = "SELECT * FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    session_destroy();
    header('Location: ../login.php');
    exit();
}

$user = $result->fetch_assoc();

// بررسی ادمین بودن
$ADMIN_TELEGRAM_ID = '5330629504';
$isAdmin = false;

$telegramSql = "SELECT telegram_id, is_admin FROM users WHERE id = ?";
$telegramStmt = $conn->prepare($telegramSql);
$telegramStmt->bind_param("i", $userId);
$telegramStmt->execute();
$telegramResult = $telegramStmt->get_result();

if ($telegramResult->num_rows > 0) {
    $userTelegram = $telegramResult->fetch_assoc();
    $userTelegramId = $userTelegram['telegram_id'] ?? '';
    if ($userTelegramId == $ADMIN_TELEGRAM_ID || ($userTelegram['is_admin'] ?? 0) == 1) {
        $isAdmin = true;
    }
}

if (!$isAdmin && $userId == 5330629504) {
    $isAdmin = true;
}

// اصلاح سرعت: این بلوک (۴ کوئری SHOW COLUMNS + یک CREATE TABLE + ۴ ALTER)
// قبلاً روی *هر* بار بارگذاری این فایل اجرا می‌شد — و چون این فایل در
// dashboard.php/arad.php/money_transfer.php/admin_panel.php یعنی تقریباً
// هر صفحه include می‌شود، یعنی ۹ کوئری DDL/schema-check اضافه روی هر تک
// بازدید صفحه، برای هیچ‌کاری (چون ستون‌ها معمولاً از قبل وجود دارند).
// حالا حداکثر هر ۳۰ دقیقه یک‌بار اجرا می‌شود.
require_once __DIR__ . '/perf_helpers.php';
if (avapay_throttled('withdrawal_modal_schema_ensure', 1800)) {
    // اطمینان از وجود فیلدهای موجودی
    $balanceFields = ['balance_irr', 'balance_usd', 'balance_eur', 'balance_usdt'];
    foreach ($balanceFields as $field) {
        $checkColumn = $conn->query("SHOW COLUMNS FROM users LIKE '$field'");
        if ($checkColumn && $checkColumn->num_rows == 0) {
            $conn->query("ALTER TABLE users ADD COLUMN $field DECIMAL(20,2) DEFAULT 0");
        }
    }

    // ایجاد جدول درخواست‌های تسویه اگر وجود ندارد
    $conn->query("CREATE TABLE IF NOT EXISTS `withdrawal_requests` (
        `id` INT PRIMARY KEY AUTO_INCREMENT,
        `user_id` INT NOT NULL,
        `amount` DECIMAL(20,2) NOT NULL,
        `currency` VARCHAR(10) NOT NULL,
        `iban_number` VARCHAR(50),
        `card_number` VARCHAR(24),
        `bank_name` VARCHAR(100),
        `recipient_name` VARCHAR(200),
        `target_user_id` INT NULL,
        `target_user_name` VARCHAR(200) NULL,
        `notes` TEXT,
        `status` ENUM('pending', 'approved', 'completed', 'rejected') DEFAULT 'pending',
        `admin_id` INT DEFAULT NULL,
        `admin_notes` TEXT,
        `receipt_file` VARCHAR(500),
        `receipt_uploaded_at` DATETIME DEFAULT NULL,
        `admin_action_at` DATETIME DEFAULT NULL,
        `completed_at` DATETIME DEFAULT NULL,
        `card_id` INT DEFAULT NULL,
        `transaction_id` VARCHAR(100) DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_id (user_id),
        INDEX idx_status (status),
        INDEX idx_target_user (target_user_id)
    )");

    // اطمینان از وجود ستون‌های جدید
    $conn->query("ALTER TABLE `withdrawal_requests` ADD COLUMN IF NOT EXISTS `target_user_id` INT NULL");
    $conn->query("ALTER TABLE `withdrawal_requests` ADD COLUMN IF NOT EXISTS `target_user_name` VARCHAR(200) NULL");
    $conn->query("ALTER TABLE `withdrawal_requests` ADD COLUMN IF NOT EXISTS `completed_at` DATETIME NULL");
    $conn->query("ALTER TABLE `withdrawal_requests` ADD INDEX IF NOT EXISTS `idx_target_user` (`target_user_id`)");
}

// اصلاح ستون id در جداول قدیمی که AUTO_INCREMENT نداشتند (رفع خطای «Field 'id' doesn't have a default value»)
$colId = $conn->query("SHOW COLUMNS FROM `withdrawal_requests` LIKE 'id'");
if ($colId && ($idInfo = $colId->fetch_assoc())) {
    if (stripos($idInfo['Extra'] ?? '', 'auto_increment') === false) {
        @$conn->query("ALTER TABLE `withdrawal_requests` MODIFY `id` INT NOT NULL AUTO_INCREMENT");
    }
}

// ==================== دریافت آخرین واریزی‌های کاربر ====================
$lastTransfers = [];

$transferSql = "SELECT 
                    w.target_user_id as receiver_id,
                    w.target_user_name as receiver_name,
                    w.recipient_name,
                    w.iban_number,
                    w.card_number,
                    w.bank_name,
                    DATE(w.completed_at) as last_transfer_date,
                    SUM(w.amount) as total_transferred,
                    w.currency as last_currency,
                    MAX(w.id) as last_id
                FROM withdrawal_requests w
                WHERE w.user_id = ? 
                    AND w.status = 'completed' 
                    AND w.completed_at IS NOT NULL
                GROUP BY 
                    CASE 
                        WHEN w.target_user_id IS NOT NULL AND w.target_user_id > 0 THEN w.target_user_id
                        ELSE w.recipient_name
                    END,
                    w.recipient_name,
                    w.iban_number,
                    w.card_number,
                    w.bank_name
                ORDER BY MAX(w.completed_at) DESC
                LIMIT 20";
$transferStmt = $conn->prepare($transferSql);
$transferStmt->bind_param("i", $userId);
$transferStmt->execute();
$lastTransfers = $transferStmt->get_result()->fetch_all(MYSQLI_ASSOC);

if (empty($lastTransfers)) {
    $transferSql2 = "SELECT 
                        0 as receiver_id,
                        w.recipient_name as receiver_name,
                        w.iban_number,
                        w.card_number,
                        w.bank_name,
                        DATE(w.completed_at) as last_transfer_date,
                        SUM(w.amount) as total_transferred,
                        w.currency as last_currency
                    FROM withdrawal_requests w
                    WHERE w.user_id = ? 
                        AND w.status = 'completed'
                        AND w.recipient_name IS NOT NULL
                        AND w.recipient_name != ''
                    GROUP BY w.recipient_name, w.iban_number, w.card_number, w.bank_name
                    ORDER BY MAX(w.completed_at) DESC
                    LIMIT 20";
    $transferStmt2 = $conn->prepare($transferSql2);
    $transferStmt2->bind_param("i", $userId);
    $transferStmt2->execute();
    $lastTransfers = $transferStmt2->get_result()->fetch_all(MYSQLI_ASSOC);
}

// دریافت اطلاعات کامل کاربر
foreach ($lastTransfers as $key => $transfer) {
    if (!empty($transfer['receiver_id']) && $transfer['receiver_id'] > 0) {
        $userInfoSql = "SELECT first_name, last_name, telegram_id, avatar FROM users WHERE id = ?";
        $userInfoStmt = $conn->prepare($userInfoSql);
        $userInfoStmt->bind_param("i", $transfer['receiver_id']);
        $userInfoStmt->execute();
        $userInfo = $userInfoStmt->get_result()->fetch_assoc();
        if ($userInfo) {
            $lastTransfers[$key]['first_name'] = $userInfo['first_name'];
            $lastTransfers[$key]['last_name'] = $userInfo['last_name'];
            $lastTransfers[$key]['telegram_id'] = $userInfo['telegram_id'];
            $lastTransfers[$key]['avatar'] = $userInfo['avatar'] ?? null;
        }
    }
}

// ===== آماره‌های تسویه برای نمودار زنده (از جدول withdrawal_requests کاربر) =====
$wdStats = ['pending' => 0, 'approved' => 0, 'completed' => 0, 'rejected' => 0];
$wdMonthly = array_fill(0, 6, 0);
$wdTotalCompleted = 0.0;
$wdNow = time();
$wdRes = $conn->query("SELECT status, amount, created_at, completed_at FROM withdrawal_requests WHERE user_id = " . intval($userId));
if ($wdRes) {
    while ($__w = $wdRes->fetch_assoc()) {
        $st = $__w['status'] ?? 'pending';
        if (isset($wdStats[$st])) $wdStats[$st]++;
        if ($st === 'completed') $wdTotalCompleted += (float)($__w['amount'] ?? 0);
        $ts = strtotime($__w['completed_at'] ?? $__w['created_at'] ?? 'now');
        $dm = (int)floor(($wdNow - $ts) / (30 * 86400));
        if ($dm >= 0 && $dm < 6) $wdMonthly[5 - $dm] += (float)($__w['amount'] ?? 0);
    }
}
$wdInsights = [
    'pending'   => $wdStats['pending'],
    'approved'  => $wdStats['approved'],
    'completed' => $wdStats['completed'],
    'rejected'  => $wdStats['rejected'],
    'total'     => array_sum($wdStats),
    'totalCompleted' => $wdTotalCompleted,
    'monthly'   => array_values($wdMonthly),
];

// تابع تبدیل عدد به حروف فارسی (پیشرفته برای اعداد بزرگ)


// تابع تبدیل عدد به حروف با واحد تومان


// تابع فرمت اعداد فارسی با جداکننده سه رقم
function formatPersianNumber($number) {
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    $isNegative = $number < 0;
    $absNumber = abs($number);
    $formatted = number_format($absNumber, 0);
    $result = str_replace($english, $persian, $formatted);
    return $isNegative ? '− ' . $result : $result;
}

// تابع مخفف‌سازی اعداد برای نمایش


// دریافت کارت‌های شرکت برای ادمین
$companyCards = [];
if ($isAdmin) {
    $cardsSql = "SELECT * FROM company_cards WHERE is_active = 1 ORDER BY bank_name ASC";
    $cardsResult = $conn->query($cardsSql);
    if ($cardsResult) {
        while ($card = $cardsResult->fetch_assoc()) {
            $companyCards[] = $card;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl"<?php echo (isset($_GET['embed']) ? ' data-embed="1"' : ''); ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<?php if (isset($_GET['embed'])): ?>
<style>
/* (آپدیت ۳) حالت مدال داخل arad: مخفی‌کردن هدر و منوی ناوبری */
.footer-menu,.bottom-nav,#footerMenu,.nav-buttons-section,.avapay-header,.app-header,
.notification-dropdown,.notification-overlay{display:none!important;}
body{padding:12px 14px 24px!important;background:transparent!important;}
</style>
<?php endif; ?>
<script>
/* پر کردن خودکار فرم از حسابِ معرفی‌شده (وقتی از بخش «معرفی حساب» آمده باشیم) */
document.addEventListener('DOMContentLoaded', function(){
    try {
        const q = new URLSearchParams(location.search);
        if (!q.get('ben_name') && !q.get('ben_iban') && !q.get('ben_card')) return;
        const setV = (id, v) => { const el = document.getElementById(id); if (el && v) el.value = v; };
        setV('withdrawalRecipient', q.get('ben_name') || '');
        setV('withdrawalBank',      q.get('ben_bank') || '');
        setV('withdrawalIban',      q.get('ben_iban') || '');
        setV('withdrawalCard',      q.get('ben_card') || '');
        // خلاصه‌ی حساب انتخاب‌شده را نشان بده
        const pk = document.getElementById('wdBenPicked');
        if (pk && q.get('ben_name')) {
            pk.style.display = 'block';
            pk.innerHTML = '<i class="fas fa-check-circle"></i> حساب انتخاب‌شده: ' + q.get('ben_name')
                         + (q.get('ben_bank') ? ' — ' + q.get('ben_bank') : '');
        }
    } catch(e){}
});
// === انتخاب حساب معرفی‌شده در فرم تسویه ===
window.wdBenPick = function(el, b){
    const set = (id,v)=>{ const x=document.getElementById(id); if(x) x.value = v || ''; };
    set('withdrawalRecipient', b.name); set('withdrawalBank', b.bank);
    set('withdrawalIban', b.iban);      set('withdrawalCard', b.card);
    document.querySelectorAll('.wd-ben-row').forEach(r=>{
        r.style.borderColor='rgba(255,255,255,.12)'; r.style.background='rgba(255,255,255,.05)';
        const c=r.querySelector('.wd-ben-check'); if(c) c.style.opacity='0';
    });
    el.style.borderColor='rgba(34,197,94,.6)'; el.style.background='rgba(34,197,94,.10)';
    const ck=el.querySelector('.wd-ben-check'); if(ck) ck.style.opacity='1';
    const pk=document.getElementById('wdBenPicked');
    if(pk){ pk.style.display='block'; pk.innerHTML='<i class="fas fa-check-circle"></i> حساب انتخاب‌شده: '+(b.name||'')+(b.bank?' — '+b.bank:''); }
};
window.wdBenFilter = function(){
    const q=(document.getElementById('wdBenSearch')?.value||'').trim().toLowerCase();
    document.querySelectorAll('.wd-ben-row').forEach(r=>{
        r.style.display = (!q || (r.dataset.name||'').includes(q)) ? '' : 'none';
    });
};
// === مدال درجای «معرفی حساب جدید» ===
window.wdBenAddOpen  = function(){ const m=document.getElementById('wdBenAddModal'); if(m) m.style.display='flex'; };
window.wdBenAddClose = function(){ const m=document.getElementById('wdBenAddModal'); if(m) m.style.display='none'; };
window.wdBenAddSave  = async function(){
    const val = id => (document.getElementById(id)?.value || '').trim();
    const err = document.getElementById('wdBenAddErr');
    const btn = document.getElementById('wdBenAddSaveBtn');
    const show = m => { if(err){ err.textContent=m; err.style.display='block'; } };
    if(err) err.style.display='none';
    const name = val('wdBenName'), card = val('wdBenCard'), iban = val('wdBenIban');
    if(!name) return show('نام گیرنده را وارد کنید');
    if(!card && !iban) return show('حداقل یکی از شماره کارت یا شبا لازم است');
    if(btn) btn.disabled = true;
    try{
        const r = await fetch('/ledor/dashboard.php?ava=ben_add', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({ full_name:name, card_number:card, iban:iban, bank_name:val('wdBenBank'), note:val('wdBenNote') })
        });
        const d = await r.json();
        if(d.success){
            const hue = ((parseInt(d.id,10)||0)*47)%360;
            const bank = val('wdBenBank');
            const row = document.createElement('div');
            row.className = 'wd-ben-row';
            row.dataset.name = name.toLowerCase();
            row.style.cssText = 'display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:14px;cursor:pointer;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.05);transition:.2s;';
            row.innerHTML =
                '<div style="width:38px;height:38px;border-radius:50%;flex:none;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,hsl('+hue+',70%,55%),hsl('+((hue+40)%360)+',70%,40%));">'
              + '<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8.2" r="3.6" fill="#fff"/><path d="M4.6 19.4c.9-3.6 3.9-5.6 7.4-5.6s6.5 2 7.4 5.6c.15.6-.33 1.1-.95 1.1H5.55c-.62 0-1.1-.5-.95-1.1z" fill="#fff"/><path d="M18.2 3.1l-1.05 2.2 1.75.55-2.6 3.05.75-2.35-1.6-.5 2.75-2.95z" fill="#FFD93D"/></svg></div>'
              + '<div style="min-width:0;"><div style="font-size:.82rem;font-weight:800;color:#fff;"></div>'
              + '<div style="font-size:.66rem;color:rgba(255,255,255,.5);"></div></div>'
              + '<i class="fas fa-check-circle wd-ben-check" style="margin-right:auto;color:#22C55E;opacity:0;transition:.2s;"></i>';
            row.children[1].children[0].textContent = name;
            row.children[1].children[1].textContent = bank || iban || card;
            const b = { name:name, bank:bank, iban:iban, card:card };
            row.onclick = function(){ wdBenPick(row, b); };
            const list = document.getElementById('wdBenList');
            if(list) list.prepend(row);
            const notice = document.getElementById('wdBenEmptyNotice'); if(notice) notice.style.display='none';
            ['wdBenName','wdBenCard','wdBenIban','wdBenBank','wdBenNote'].forEach(i=>{ const x=document.getElementById(i); if(x) x.value=''; });
            wdBenAddClose();
            wdBenPick(row, b); // انتخاب خودکار حساب تازه
        } else show(d.error || 'خطا در ذخیره');
    }catch(e){ show('خطای ارتباط با سرور'); }
    if(btn) btn.disabled = false;
};
</script>
<?php if (isset($_GET['new'])): ?>
<style>
/* (آپدیت ۳) فقط مدال ثبت درخواست تسویه نمایش داده شود */
#withdrawalStepModal{display:flex!important;}
</style>
<?php endif; ?>
    <title>تسویه حساب | Arad Exchange</title>
    <link rel="stylesheet" href="/ledor/assets/css/theme-light.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/theme-light.css') ?: time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/avapay-modern.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/avapay-modern.css') ?: time(); ?>">
    <script defer src="../assets/js/avapay-modern.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/avapay-modern.js') ?: time(); ?>"></script>
    <script src="../assets/js/upload-compress.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/upload-compress.js') ?: time(); ?>"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        /* جلوگیری از زوم با انگشت در موبایل */
body {
    touch-action: pan-x pan-y; /* اجازه اسکرول عمودی و افقی ولی بدون زوم */
}

/* جلوگیری از زوم روی input ها (مخصوص موبایل) */
input, 
textarea, 
select, 
button {
    font-size: 16px; /* جلوگیری از زوم خودکار در iOS */
    touch-action: manipulation;
}

/* غیرفعال کردن زوم روی کل صفحه */
html {
    touch-action: pan-x pan-y;
}

/* برای جلوگیری از زوم در Webkit (مرورگرهای مبتنی بر کروم) */
@viewport {
    zoom: 1.0;
    width: extend-to-zoom;
}

@-ms-viewport {
    zoom: 1.0;
    width: extend-to-zoom;
}


/* جایگزین استایل body با این کد */

body {
    background: linear-gradient(115deg, #0069ff, #010cff, #27021a, #3c0235, #0a1f1a, #0a1628, #1a0a2e);
    background-size: 400% 400%;
    min-height: 100vh;
    font-family: -apple-system, 'Segoe UI', 'Tahoma', system-ui, sans-serif;
    padding: 12px;
    padding-bottom: 85px;
    margin: 0;
    overflow-x: hidden;
    color: #ffffff;
    animation: gradientShift 15s ease infinite;
}

/* انیمیشن حرکت رنگ‌ها */
@keyframes gradientShift {
    0% {
        background-position: 0% 50%;
    }
    50% {
        background-position: 100% 50%;
    }
    100% {
        background-position: 0% 50%;
    }
}
        
        @media (max-width: 600px) {
            body {
                padding: 10px;
                padding-bottom: 90px;
            }
        }
        
        /* هدر */
        .avapay-header {
            background: linear-gradient(295deg, rgb(43 26 63), rgb(44 28 63));
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 16px;
            margin-bottom: 16px;
            border: 1px solid rgba(255, 255, 255, 0.08);
                MARGIN-TOP: 22PX;
        }
        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .logo-section {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .logo-icon {
            width: 44px;
            height: 44px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .logo-text h1 {
            font-size: 1.2rem;
            font-weight: 700;
            margin: 0;
            background: linear-gradient(23deg, #ffffff, #c6ff06);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .logo-text span {
            font-size: 0.6rem;
            color: #aaa;
        }
        .home-btn {
            width: 38px;
            height: 38px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
        }
        
        /* نوار ناوبری */
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
        }
        
        /* کارت موجودی */
        .glass-balance-card {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.08), rgba(108, 64, 197, 0.08));
            backdrop-filter: blur(20px);
            border-radius: 28px;
            padding: 20px 16px;
            margin: 16px 0;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .currency-icon-small.irr { background: linear-gradient(135deg, #6C40C5, #4CD964); color: white; }
        .currency-icon-small.usd { background: linear-gradient(135deg, #2ecc71, #27ae60); color: white; }
        .currency-icon-small.eur { background: linear-gradient(135deg, #3498db, #2980b9); color: white; }
        .currency-icon-small.usdt { background: linear-gradient(135deg, #FFD700, #FFA500); color: #1a1a2e; }
        @media (max-width: 600px) {
            .currency-cards {
                grid-template-columns: repeat(2, 1fr);
                gap: 8px;
            }
        }
        
        /* اسکرول افقی گوی‌ها */
        .last-transfers-scroll {
            width: 100%;
            overflow-x: auto;
            overflow-y: hidden;
            white-space: nowrap;
            scrollbar-width: thin;
            scrollbar-color: #FFD700 rgba(255,255,255,0.1);
            -webkit-overflow-scrolling: touch;
            padding-bottom: 10px;
        }
        .last-transfers-scroll::-webkit-scrollbar {
            height: 4px;
        }
        .last-transfers-scroll::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.05);
            border-radius: 10px;
        }
        .last-transfers-scroll::-webkit-scrollbar-thumb {
            background: #FFD700;
            border-radius: 10px;
        }
        .last-transfers-container {
            display: inline-flex;
            gap: 18px;
            padding: 8px 4px;
            white-space: nowrap;
        }
        
        /* گوی دایره‌ای */
        .transfer-avatar-circle {
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 85px;
            text-align: center;
        }
        .transfer-avatar-circle:hover {
            transform: translateY(-5px);
        }
        .transfer-avatar-circle:active {
            transform: scale(0.96);
        }
        .avatar-circle {
            width: 70px;
            height: 70px;
            background: linear-gradient(145deg, #FFD700, #FFA500);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            font-weight: bold;
            color: #1a1a2e;
            box-shadow: 0 8px 20px rgba(255,215,0,0.3);
            margin-bottom: 8px;
            transition: all 0.3s ease;
            border: 2px solid rgba(255,255,255,0.2);
            overflow: hidden;
        }
        .avatar-circle img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }
        .transfer-name {
            font-size: 0.7rem;
            font-weight: 600;
            color: #FFD700;
            background: rgba(0,0,0,0.5);
            padding: 4px 8px;
            border-radius: 40px;
            backdrop-filter: blur(4px);
            max-width: 85px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .transfer-date {
            font-size: 0.55rem;
            color: rgba(255,255,255,0.5);
            margin-top: 4px;
        }
        .transfer-amount {
            font-size: 0.55rem;
            color: #4CD964;
            margin-top: 2px;
        }
        .empty-transfers {
            text-align: center;
            padding: 20px;
            color: rgba(255,255,255,0.5);
            width: 100%;
        }
        @media (max-width: 600px) {
            .avatar-circle {
                width: 60px;
                height: 60px;
                font-size: 1.5rem;
            }
            .transfer-avatar-circle {
                width: 75px;
            }
            .transfer-name {
                font-size: 0.6rem;
            }
        }
        
        /* بخش اصلی تسویه */
        .withdrawal-section {
            margin: 20px 0;
        }
        .withdrawal-glass-card {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.08), rgba(108, 64, 197, 0.08));
            backdrop-filter: blur(20px);
            border-radius: 28px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            overflow: hidden;
        }
        .withdrawal-glass-header {
            background: linear-gradient(135deg, rgba(108, 64, 197, 0.3), rgba(255, 77, 141, 0.15));
            padding: 16px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(255, 215, 0, 0.2);
            cursor: pointer;
        }
        .withdrawal-glass-header h3 {
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1rem;
            font-weight: 600;
            color: white;
        }
        .withdrawal-glass-header h3 i {
            color: #FFD700;
        }
        .withdrawal-badge {
            background: rgba(255, 193, 7, 0.2);
            border-radius: 30px;
            padding: 4px 10px;
            font-size: 0.65rem;
            color: #FFC107;
            border: 1px solid rgba(255, 193, 7, 0.3);
        }
        .create-withdrawal-btn {
            width: calc(100% - 40px);
            margin: 20px 20px 0 20px;
            padding: 14px;
            border: none;
            border-radius: 50px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            background: linear-gradient(135deg, #FFD700, #FFA500);
            color: #1a1a2e;
            font-size: 0.9rem;
        }
        .create-withdrawal-btn:hover {
            transform: translateY(-2px);
            filter: brightness(1.05);
            box-shadow: 0 5px 20px rgba(255, 215, 0, 0.3);
        }
        .withdrawal-history-list {
            padding: 20px;
        }
        .history-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        .history-title h4 {
            margin: 0;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 8px;
            color: rgba(255, 255, 255, 0.8);
        }
        .refresh-btn {
            background: rgba(255, 215, 0, 0.1);
            border: 1px solid rgba(255, 215, 0, 0.2);
            border-radius: 50%;
            width: 32px;
            height: 32px;
            cursor: pointer;
            color: #FFD700;
            transition: all 0.3s ease;
        }
        .refresh-btn:hover {
            transform: rotate(180deg);
            background: rgba(255, 215, 0, 0.2);
        }
        .withdrawal-history-item {
            background: rgba(0, 0, 0, 0.25);
            border-radius: 18px;
            padding: 14px;
            margin-bottom: 12px;
            border-right: 3px solid #4CD964;
        }
        .withdrawal-history-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            flex-wrap: wrap;
            gap: 8px;
        }
        .withdrawal-history-amount {
            font-weight: bold;
            font-size: 0.95rem;
        }
        .withdrawal-history-amount span {
            color: #4CD964;
        }
        .withdrawal-history-status {
            font-size: 0.65rem;
            padding: 3px 10px;
            border-radius: 30px;
            background: rgba(76, 217, 100, 0.2);
            color: #4CD964;
        }
        .withdrawal-history-details {
            font-size: 0.65rem;
            color: rgba(255, 255, 255, 0.5);
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 8px;
        }
        .withdrawal-receipt-link {
            margin-top: 10px;
            padding: 6px 10px;
            background: rgba(255, 215, 0, 0.08);
            border-radius: 12px;
            font-size: 0.65rem;
        }
        .withdrawal-receipt-link a {
            color: #FFD700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .empty-withdrawals {
            text-align: center;
            padding: 40px 20px;
            color: rgba(255, 255, 255, 0.5);
        }
        .empty-withdrawals i {
            font-size: 2.5rem;
            margin-bottom: 10px;
            opacity: 0.5;
        }
        
        /* کارت آمار تسویه شده */
        .stats-card {
            background: linear-gradient(135deg, rgba(76, 217, 100, 0.12), rgba(108, 64, 197, 0.08));
            border-radius: 18px;
            padding: 14px;
            text-align: center;
            border: 1px solid rgba(76, 217, 100, 0.2);
            transition: all 0.3s ease;
        }
        .stats-card:hover {
            transform: translateY(-3px);
            border-color: #4CD964;
        }
        .stats-card-icon {
            font-size: 1.8rem;
            margin-bottom: 5px;
        }
        .stats-card-label {
            font-size: 0.7rem;
            color: rgba(255, 255, 255, 0.6);
        }
        .stats-card-amount {
            font-size: 1rem;
            font-weight: 800;
            color: #4CD964;
            margin: 5px 0;
        }
        .stats-card-count {
            font-size: 0.6rem;
            color: rgba(255, 255, 255, 0.4);
        }
        .stats-grid-2cols {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }
        
        /* مودال استپ به استپ */
        .step-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.9);
            backdrop-filter: blur(20px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
        }
        .step-modal.active {
            display: flex;
        }
        .step-modal-content {
            background: linear-gradient(135deg, #1a0b2e, #0d0518);
            border: 1px solid rgba(255, 215, 0, 0.3);
            border-radius: 32px;
            width: 90%;
            max-width: 500px;
            max-height: 85vh;
            overflow-y: auto;
            animation: modalFadeIn 0.35s ease-out;
        }
        @keyframes modalFadeIn {
            from { opacity: 0; transform: scale(0.95) translateY(20px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
        .step-modal-header {
            padding: 18px 22px;
            border-bottom: 1px solid rgba(255, 215, 0, 0.15);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, rgba(108, 64, 197, 0.2), rgba(255, 77, 141, 0.1));
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .step-modal-header h2 {
            margin: 0;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 10px;
            color: white;
        }
        .step-modal-header h2 i {
            color: #FFD700;
        }
        .step-modal-close {
            background: none;
            border: none;
            color: rgba(255, 255, 255, 0.6);
            font-size: 1.3rem;
            cursor: pointer;
            transition: all 0.2s ease;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .step-modal-close:hover {
            color: #FF3B30;
            background: rgba(255, 59, 48, 0.1);
            transform: rotate(90deg);
        }
        .step-modal-body {
            padding: 22px;
        }
        
        /* استپ ایندیکاتور */
        .step-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            position: relative;
            padding: 0 5px;
        }
        .step-indicator::before {
            content: '';
            position: absolute;
            top: 22px;
            left: 15%;
            right: 15%;
            height: 2px;
            background: linear-gradient(90deg, rgba(255, 215, 0, 0.1), rgba(255, 215, 0, 0.4), rgba(255, 215, 0, 0.1));
            border-radius: 2px;
            z-index: 1;
        }
        .step {
            position: relative;
            z-index: 2;
            text-align: center;
            flex: 1;
            cursor: pointer;
        }
        .step-circle {
            width: 44px;
            height: 44px;
            background: rgba(255, 255, 255, 0.05);
            border: 2px solid rgba(255, 215, 0, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 8px;
            font-weight: bold;
            font-size: 1.1rem;
            color: rgba(255, 255, 255, 0.4);
            transition: all 0.3s ease;
        }
        .step.active .step-circle {
            background: linear-gradient(135deg, #FFD700, #FFA500);
            border-color: #FFD700;
            color: #1a1a2e;
            box-shadow: 0 0 20px rgba(255, 215, 0, 0.4);
            animation: stepPulse 2s infinite;
        }
        @keyframes stepPulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(255, 215, 0, 0.3); }
            50% { box-shadow: 0 0 0 8px rgba(255, 215, 0, 0); }
        }
        .step.completed .step-circle {
            background: linear-gradient(135deg, #4CD964, #2ecc71);
            border-color: #4CD964;
            color: white;
        }
        .step.completed .step-circle::after {
            content: '✓';
            font-size: 1.1rem;
            font-weight: bold;
        }
        .step-label {
            font-size: 0.65rem;
            color: rgba(255, 255, 255, 0.5);
            font-weight: 500;
        }
        .step.active .step-label {
            color: #FFD700;
            font-weight: 600;
        }
        .step.completed .step-label {
            color: #4CD964;
        }
        
        /* استپ محتوا */
        .step-content {
            display: none;
            animation: contentFadeIn 0.3s ease;
        }
        .step-content.active-step {
            display: block;
        }
        @keyframes contentFadeIn {
            from { opacity: 0; transform: translateX(10px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        /* انتخاب ارز */
        .currency-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin: 20px 0;
            justify-content: center;
        }
        .currency-select-card {
            flex: 1;
            min-width: 85px;
            background: rgba(0, 0, 0, 0.4);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 20px;
            padding: 14px 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .currency-select-card:hover {
            transform: translateY(-3px);
            border-color: rgba(255, 215, 0, 0.4);
            background: rgba(255, 215, 0, 0.05);
        }
        .currency-select-card.selected {
            border-color: #FFD700;
            background: linear-gradient(135deg, rgba(255, 215, 0, 0.15), rgba(255, 165, 0, 0.08));
            transform: scale(1.02);
        }
        .currency-name {
            font-weight: bold;
            font-size: 0.85rem;
            background: linear-gradient(135deg, #FFD700, #FFA500);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        .balance-display {
            font-size: 0.7rem;
            margin-top: 6px;
            color: #4CD964;
            font-weight: 500;
        }
        
        /* فیلد مبلغ */
        .amount-input {
            margin-top: 20px;
        }
        .amount-input label {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.8rem;
        }
        .amount-input label i {
            color: #FFD700;
        }
        .amount-input-wrapper {
            position: relative;
        }
        .amount-currency-symbol {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1.1rem;
            pointer-events: none;
        }
        .amount-input input {
            width: 100%;
            padding: 14px 45px 14px 15px;
            background: rgba(0, 0, 0, 0.4);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 50px;
            color: white;
            font-size: 1rem;
            text-align: center;
            transition: all 0.3s ease;
            direction: ltr;
        }
        .amount-input input:focus {
            outline: none;
            border-color: #FFD700;
            box-shadow: 0 0 15px rgba(255, 215, 0, 0.15);
        }
        .amount-input input::placeholder {
            color: rgba(255, 255, 255, 0.3);
            font-size: 0.85rem;
            text-align: center;
        }
        #amountError {
            color: #FF3B30;
            font-size: 0.7rem;
            margin-top: 8px;
            text-align: center;
            background: rgba(255, 59, 48, 0.1);
            padding: 8px;
            border-radius: 12px;
            display: none;
        }
        .live-balance {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 12px;
            padding: 8px 15px;
            background: rgba(76, 217, 100, 0.08);
            border-radius: 40px;
            border: 1px solid rgba(76, 217, 100, 0.15);
        }
        .live-balance span:first-child {
            font-size: 0.7rem;
            color: rgba(255, 255, 255, 0.6);
        }
        .live-balance span:last-child {
            font-size: 0.85rem;
            font-weight: bold;
            color: #4CD964;
        }
        
        /* فرم بانکی */
        .bank-form {
            margin-top: 20px;
        }
        .bank-form-group {
            margin-bottom: 16px;
        }
        .bank-form-group label {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 6px;
            color: rgba(255, 255, 255, 0.65);
            font-size: 0.75rem;
        }
        .bank-form-group label i {
            color: #FFD700;
        }
        .bank-form-group input,
        .bank-form-group textarea {
            width: 100%;
            padding: 12px 14px;
            background: rgba(0, 0, 0, 0.35);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            color: white;
            font-size: 0.85rem;
            transition: all 0.2s ease;
        }
        .bank-form-group input:focus,
        .bank-form-group textarea:focus {
            outline: none;
            border-color: #FFD700;
            background: rgba(0, 0, 0, 0.5);
        }
        
        /* دکمه‌های ناوبری */
        .step-buttons {
            display: flex;
            gap: 12px;
            margin-top: 30px;
        }
        .btn-step {
            flex: 1;
            padding: 13px;
            border: none;
            border-radius: 50px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 0.85rem;
        }
        .btn-step-next {
            background: linear-gradient(135deg, #FFD700, #FFA500);
            color: #1a1a2e;
            box-shadow: 0 4px 15px rgba(255, 215, 0, 0.2);
        }
        .btn-step-prev {
            background: rgba(255, 255, 255, 0.08);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .btn-step-submit {
            background: linear-gradient(135deg, #4CD964, #2ecc71);
            color: white;
            box-shadow: 0 4px 15px rgba(76, 217, 100, 0.2);
        }
        .btn-step-next:hover,
        .btn-step-submit:hover {
            transform: translateY(-2px);
            filter: brightness(1.05);
        }
        .btn-step-prev:hover {
            background: rgba(255, 255, 255, 0.12);
            transform: translateY(-2px);
        }
        
        /* خلاصه درخواست */
        .summary-card {
            background: linear-gradient(135deg, rgba(255, 215, 0, 0.05), rgba(108, 64, 197, 0.08));
            border-radius: 22px;
            padding: 18px;
            margin: 20px 0;
            border: 1px solid rgba(255, 215, 0, 0.12);
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }
        .summary-row:last-child {
            border-bottom: none;
        }
        .summary-label {
            color: rgba(255, 255, 255, 0.6);
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .summary-label i {
            color: #FFD700;
        }
        .summary-value {
            font-weight: bold;
            color: #FFD700;
            font-size: 0.85rem;
            text-align: left;
        }
        
        /* Toast */
        .toast {
            position: fixed;
            bottom: 100px;
            left: 50%;
            transform: translateX(-50%) translateY(100px);
            background: rgba(0, 0, 0, 0.95);
            backdrop-filter: blur(20px);
            padding: 10px 20px;
            border-radius: 50px;
            color: white;
            z-index: 9999;
            transition: all 0.3s ease;
            opacity: 0;
            font-size: 0.8rem;
            white-space: nowrap;
            border: 1px solid rgba(255, 215, 0, 0.2);
        }
        .toast.show {
            transform: translateX(-50%) translateY(0);
            opacity: 1;
        }
        .toast.success {
            background: linear-gradient(135deg, rgba(76, 217, 100, 0.95), rgba(46, 204, 113, 0.95));
        }
        .toast.error {
            background: linear-gradient(135deg, rgba(255, 59, 48, 0.95), rgba(255, 149, 0, 0.95));
        }
        
        /* مودال آپلود */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.95);
            backdrop-filter: blur(20px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2100;
        }
        .modal-overlay.active {
            display: flex;
        }
        .modal-content {
            background: linear-gradient(135deg, #2d1b3e, #1a0b2e);
            border: 1px solid rgba(255, 215, 0, 0.25);
            border-radius: 28px;
            width: 90%;
            max-width: 480px;
            max-height: 85vh;
            overflow-y: auto;
        }
        .modal-header {
            padding: 16px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h3 {
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
        }
        .modal-header h3 i {
            color: #FFD700;
        }
        .close-modal {
            background: none;
            border: none;
            color: rgba(255, 255, 255, 0.6);
            font-size: 1.3rem;
            cursor: pointer;
        }
        .close-modal:hover {
            color: #FF3B30;
        }
        .modal-body {
            padding: 20px;
        }
        .form-group {
            margin-bottom: 16px;
        }
        .form-group label {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 6px;
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.8rem;
        }
        .form-group label i {
            color: #FFD700;
        }
        .form-control {
            width: 100%;
            padding: 12px 14px;
            background: rgba(0, 0, 0, 0.35);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            color: white;
            font-size: 0.85rem;
        }
        .form-control:focus {
            outline: none;
            border-color: #FFD700;
        }
        .btn-submit {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 40px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            background: linear-gradient(135deg, #FFD700, #FFA500);
            color: #1a1a2e;
            font-size: 0.9rem;
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            filter: brightness(1.05);
        }
        
        /* ==================== پنل ادمین ==================== */
        .admin-withdrawal-container {
            background: linear-gradient(135deg, rgba(15, 10, 35, 0.9), rgba(10, 5, 25, 0.95));
            backdrop-filter: blur(20px);
            border-radius: 28px;
            border: 1px solid rgba(255, 215, 0, 0.2);
            margin-top: 25px;
            overflow: hidden;
        }
        .admin-withdrawal-header {
            background: linear-gradient(135deg, rgba(108, 64, 197, 0.3), rgba(255, 77, 141, 0.15));
            padding: 16px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            border-bottom: 1px solid rgba(255, 215, 0, 0.15);
            flex-wrap: wrap;
            gap: 10px;
        }
        .admin-withdrawal-header h3 {
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.95rem;
        }
        .admin-withdrawal-header h3 i {
            color: #FFD700;
        }
        .admin-withdrawal-body {
            padding: 20px;
            display: block;
        }
        .admin-withdrawal-body.collapsed {
            display: none;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 25px;
        }
        @media (max-width: 550px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        .stat-box {
            background: rgba(0, 0, 0, 0.3);
            border-radius: 18px;
            padding: 12px;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.06);
        }
        .add-card-btn {
            background: linear-gradient(135deg, #FFD700, #FFA500);
            border: none;
            border-radius: 30px;
            padding: 6px 15px;
            color: #1a1a2e;
            font-weight: bold;
            cursor: pointer;
            font-size: 0.7rem;
        }
        .cards-table {
            min-width: 500px;
            width: 100%;
            border-collapse: collapse;
            font-size: 0.7rem;
        }
        .cards-table th, .cards-table td {
            padding: 10px 8px;
            text-align: right;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        }
        .cards-table th {
            color: #FFD700;
            background: rgba(0, 0, 0, 0.3);
        }
        .btn-sm {
            padding: 4px 10px;
            border-radius: 20px;
            border: none;
            cursor: pointer;
            font-size: 0.6rem;
            margin: 2px;
        }
        .btn-edit {
            background: linear-gradient(135deg, #3498db, #2980b9);
            color: white;
        }
        .btn-delete {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: white;
        }
        
        /* کارت گزارش */
        .card-stats-card {
            background: linear-gradient(135deg, rgba(255, 215, 0, 0.08), rgba(108, 64, 197, 0.05));
            border-radius: 18px;
            margin-bottom: 15px;
            border: 1px solid rgba(255, 215, 0, 0.1);
            overflow: hidden;
        }
        .card-stats-header {
            background: rgba(0, 0, 0, 0.3);
            padding: 12px 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            cursor: pointer;
        }
        .card-stats-header .bank-name {
            font-weight: bold;
            color: #FFD700;
        }
        .card-stats-header .card-number {
            font-family: monospace;
            font-size: 0.7rem;
            color: rgba(255, 255, 255, 0.7);
        }
        .card-stats-details {
            padding: 15px;
            display: none;
        }
        .card-stats-details.show {
            display: block;
        }
        .amount-words {
            font-size: 0.7rem;
            color: #4CD964;
            margin-top: 5px;
            font-style: italic;
        }
        
        .withdrawal-table {
            min-width: 600px;
            width: 100%;
            border-collapse: collapse;
            font-size: 0.65rem;
        }
        .withdrawal-table th, .withdrawal-table td {
            padding: 10px 8px;
            text-align: right;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        }
        .withdrawal-table th {
            color: #FFD700;
            background: rgba(0, 0, 0, 0.3);
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.55rem;
        }
        .status-badge.pending {
            background: rgba(255, 193, 7, 0.2);
            color: #FFC107;
        }
        .status-badge.completed {
            background: rgba(76, 217, 100, 0.2);
            color: #4CD964;
        }
        .status-badge.rejected {
            background: rgba(255, 59, 48, 0.2);
            color: #FF3B30;
        }
        .btn-action {
            border: none;
            border-radius: 14px;
            padding: 4px 10px;
            cursor: pointer;
            font-size: 0.55rem;
            font-weight: 600;
            margin: 2px;
            transition: all 0.2s ease;
        }
        .btn-approve {
            background: linear-gradient(135deg, #4CD964, #34C759);
            color: white;
        }
        .btn-reject {
            background: linear-gradient(135deg, #FF3B30, #FF9500);
            color: white;
        }
        .btn-upload {
            background: linear-gradient(135deg, #FFD700, #FFA500);
            color: #1a1a2e;
        }
        .empty-state {
            text-align: center;
            padding: 30px;
            color: rgba(255, 255, 255, 0.5);
            font-size: 0.75rem;
        }
        
        /* گوی شناور */
        .floating-sphere {
            position: fixed;
            bottom: 80px;
            right: 20px;
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #FFD700, #6C40C5, #FF4D8D);
            border-radius: 50%;
            cursor: pointer;
            z-index: 9999;
            box-shadow: 0 5px 20px rgba(108, 64, 197, 0.4);
            animation: floatSphere 3s ease-in-out infinite;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        @keyframes floatSphere {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }
        .floating-sphere i {
            font-size: 24px;
            color: white;
        }
        
        @media (max-width: 600px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }
            .stats-grid-2cols {
                gap: 8px;
            }
            .currency-grid {
                gap: 8px;
            }
            .currency-select-card {
                min-width: 70px;
                padding: 10px 5px;
            }
            .currency-icon {
                font-size: 1.6rem;
            }
            .step-circle {
                width: 38px;
                height: 38px;
            }
            .toast {
                font-size: 0.7rem;
                bottom: 90px;
                white-space: normal;
                text-align: center;
                max-width: 90%;
            }
            .floating-sphere {
                bottom: 70px;
                right: 15px;
                width: 45px;
                height: 45px;
            }
            .floating-sphere i {
                font-size: 20px;
            }
        }
    </style>
</head>
<body>

<!-- هدر -->
<div class="avapay-header">
    <div class="header-container">
        <div class="logo-section">
            <div class="logo-icon">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 2L2 7L12 12L22 7L12 2Z" stroke="#FFD700" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M2 17L12 22L22 17" stroke="#FFD700" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M2 12L12 17L22 12" stroke="#FFD700" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <div class="logo-text">
                <h1>Ava Pay</h1>
                <span>سیستم تبادل ارزی هوشمند</span>
            </div>
        </div>
        <div class="header-actions">
            <a href="../dashboard.php" class="home-btn"><i class="fas fa-home"></i></a>
        </div>
    </div>
</div>

<!-- نوار ناوبری سه دکمه -->
<div class="nav-buttons-section">
    <div class="nav-buttons-large">
        <a href="../arad.php" class="nav-large-btn"><i class="fas fa-exchange-alt"></i><span>تبادل ارزی</span></a>
        <a href="../money_transfer.php" class="nav-large-btn"><i class="fas fa-money-bill-transfer"></i><span>حواله ارزی</span></a>
        <a href="withdrawal_modal_system.php" class="nav-large-btn active"><i class="fas fa-wallet"></i><span>تسویه حساب</span></a>
    </div>
</div>

<!-- کارت موجودی (طراحی جدید) -->
<?php
require_once __DIR__ . '/wallet_hero.php';
renderWalletHero([
    'USD'  => (float)($user['balance_usd']  ?? 0),
    'EUR'  => (float)($user['balance_eur']  ?? 0),
    'USDT' => (float)($user['balance_usdt'] ?? 0),
    'IRR'  => (float)($user['balance_irr']  ?? 0),
]);
?>

<!-- ==================== نمای کلی + نمودار زنده تسویه‌ها ==================== -->
<div class="av-scope" id="avSettlementScope" style="max-width:520px;margin:0 auto;">
  <script>window.__AV_WD__ = <?php echo json_encode($wdInsights, JSON_UNESCAPED_UNICODE); ?>;</script>
  <div class="av-insights">
    <div class="av-card">
      <div class="av-card-head">
        <h3><i class="fas fa-chart-pie"></i> وضعیت تسویه‌ها</h3>
        <span class="av-chip" id="avWdTotalChip">۰ درخواست</span>
      </div>
      <div id="avWdDonut"></div>
    </div>
    <div class="av-card">
      <div class="av-card-head">
        <h3><i class="fas fa-chart-column"></i> روند ۶ ماه اخیر</h3>
        <span class="av-chip" id="avWdSumChip">—</span>
      </div>
      <div id="avWdSpark"></div>
    </div>
  </div>
</div>

<!-- بخش اصلی تسویه حساب -->
<div class="withdrawal-section">
    <div class="withdrawal-glass-card">
        <div class="withdrawal-glass-header" onclick="toggleWithdrawalPanel()">
            <h3><i class="fas fa-money-bill-transfer"></i><span>💰 درخواست تسویه حساب</span></h3>
            <div style="display: flex; align-items: center; gap: 12px;">
                <div class="withdrawal-badge" id="withdrawalPendingBadge" style="display: none;"><i class="fas fa-clock"></i> <span id="withdrawalPendingCount">0</span> در انتظار</div>
                <i class="fas fa-chevron-down" id="withdrawalPanelIcon" style="transition: transform 0.3s ease;"></i>
            </div>
        </div>
        <div id="withdrawalPanelBody" style="display: block;">
            <button class="create-withdrawal-btn" onclick="openWithdrawalModal()"><i class="fas fa-plus-circle"></i> ثبت درخواست تسویه جدید</button>
            <div class="withdrawal-history-list">
                <div class="history-title"><h4><i class="fas fa-chart-line"></i> تاریخچه تسویه حساب</h4><button class="refresh-btn" onclick="loadUserWithdrawals()"><i class="fas fa-sync-alt"></i></button></div>
                <div id="userWithdrawalsList"><div class="empty-withdrawals"><i class="fas fa-spinner fa-spin"></i><p>در حال بارگذاری...</p></div></div>
            </div>
        </div>
    </div>
</div>


<!-- ========== بخش آخرین واریزی‌های شما - گوی‌های دایره‌ای ========== -->
<div class="glass-balance-card" style="margin-top: 16px;">
    <div class="withdrawal-glass-header" onclick="toggleLastTransfers()">
        <h3>
            <i class="fas fa-history"></i>
            <span>📋 آخرین واریزی‌های شما</span>
        </h3>
        <div style="display: flex; align-items: center; gap: 12px;">
            <div class="withdrawal-badge" id="lastTransfersBadge" style="display: none;">
                <i class="fas fa-users"></i> 
                <span id="lastTransfersCount">0</span> نفر
            </div>
            <i class="fas fa-chevron-down" id="lastTransfersIcon" style="transition: transform 0.3s ease;"></i>
        </div>
    </div>
  
    
    <div class="last-transfers-scroll">
        <div class="last-transfers-container" id="lastTransfersContainer">
            <?php if (!empty($lastTransfers)): ?>
    <?php foreach ($lastTransfers as $transfer): 
        $fullName = $transfer['receiver_name'] ?? '';
        if (empty($fullName)) {
            $fullName = trim(($transfer['first_name'] ?? '') . ' ' . ($transfer['last_name'] ?? ''));
        }
        if (empty($fullName)) $fullName = $transfer['recipient_name'] ?? 'گیرنده';
        $initial = mb_substr($fullName, 0, 1, 'UTF-8');
        $lastDate = $transfer['last_transfer_date'] ?? date('Y/m/d');
        
        // مسیر عکس پیشفرض - می‌توانید آدرس دلخواه خود را قرار دهید
        $defaultAvatar = '../assets/images/profile.png'; // مسیر عکس پیشفرض خود را تنظیم کنید
        $avatar = !empty($transfer['avatar']) && file_exists($transfer['avatar']) ? $transfer['avatar'] : $defaultAvatar;
        
        $totalAmount = $transfer['total_transferred'] ?? 0;
        $receiverId = $transfer['receiver_id'] ?? 0;
        
        $ibanNumber = $transfer['iban_number'] ?? '';
        $cardNumber = $transfer['card_number'] ?? '';
        $bankName = $transfer['bank_name'] ?? '';
    ?>
    <div class="transfer-avatar-circle" 
         onclick="openQuickWithdrawalForUser(
             <?php echo $receiverId; ?>, 
             '<?php echo addslashes($fullName); ?>',
             '<?php echo addslashes($ibanNumber); ?>',
             '<?php echo addslashes($cardNumber); ?>',
             '<?php echo addslashes($bankName); ?>'
         )">
        <div class="avatar-circle">
            <img src="<?php echo htmlspecialchars($avatar); ?>" alt="<?php echo htmlspecialchars($fullName); ?>" onerror="this.onerror=null; this.src='<?php echo $defaultAvatar; ?>'; this.style.objectFit='cover'">
        </div>
        <div class="transfer-name"><?php echo htmlspecialchars(mb_substr($fullName, 0, 12)); ?></div>
        <div class="transfer-date"><?php echo $lastDate; ?></div>
        <?php if ($totalAmount > 0): ?>
        <div class="transfer-amount"><?php echo number_format($totalAmount); ?> تومان</div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
<?php else: ?>
    <div class="empty-transfers">
        <i class="fas fa-info-circle"></i> هنوز واریزی به شخص خاصی نداشته‌اید<br>
        <small>پس از تکمیل اولین تسویه، گیرنده اینجا نمایش داده می‌شود</small>
    </div>
<?php endif; ?>
</div>
</div>
</div>
<?php if ($isAdmin): ?>
<!-- پنل مدیریت تسویه به admin_panel.php منتقل شد -->
<div class="admin-withdrawal-container">
    <div class="admin-withdrawal-header">
        <h3><i class="fas fa-shield-alt"></i> پنل مدیریت تسویه حساب</h3>
    </div>
    <div class="admin-withdrawal-body" style="display:block;text-align:center;padding:24px;">
        <p style="color:#aaa;margin-bottom:16px;">مدیریت درخواست‌های تسویه به «پنل مدیریت» منتقل شده است.</p>
        <a href="/ledor/admin_panel.php#settlements" style="display:inline-block;text-decoration:none;padding:12px 28px;border-radius:14px;background:linear-gradient(135deg,#6C40C5,#FF4D8D);color:#fff;">
            <i class="fas fa-external-link-alt"></i> ورود به پنل مدیریت
        </a>
    </div>
</div>
<?php endif; ?>

<!-- مودال استپ به استپ ثبت درخواست -->
<div id="withdrawalStepModal" class="step-modal">
    <div class="step-modal-content">
        <div class="step-modal-header">
            <h2><i class="fas fa-money-bill-transfer"></i> درخواست تسویه حساب</h2>
            <button class="step-modal-close" onclick="closeWithdrawalModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="step-modal-body">
            <!-- استپ ایندیکاتور -->
            <div class="step-indicator">
                <div class="step" id="step1Indicator" onclick="goToStep(1)"><div class="step-circle">1</div><div class="step-label">انتخاب ارز و مبلغ</div></div>
                <div class="step" id="step2Indicator" onclick="goToStep(2)"><div class="step-circle">2</div><div class="step-label">اطلاعات بانکی</div></div>
                <div class="step" id="step3Indicator" onclick="goToStep(3)"><div class="step-circle">3</div><div class="step-label">تایید نهایی</div></div>
            </div>
            
            <!-- استپ 1 -->
            <div id="step1" class="step-content active-step">
                <div class="currency-grid" id="withdrawalCurrencyGrid">
                    <div class="currency-select-card" data-currency="IRR" data-balance="<?php echo $user['balance_irr'] ?? 0; ?>"><div class="currency-name">تومان</div><div class="balance-display"><?php echo number_format($user['balance_irr'] ?? 0); ?> تومان</div></div>
                    <div class="currency-select-card" data-currency="USD" data-balance="<?php echo $user['balance_usd'] ?? 0; ?>"><div class="currency-name">دلار</div><div class="balance-display">$<?php echo number_format($user['balance_usd'] ?? 0, 2); ?></div></div>
                    <div class="currency-select-card" data-currency="EUR" data-balance="<?php echo $user['balance_eur'] ?? 0; ?>"><div class="currency-name">یورو</div><div class="balance-display">€<?php echo number_format($user['balance_eur'] ?? 0, 2); ?></div></div>
                    <div class="currency-select-card" data-currency="USDT" data-balance="<?php echo $user['balance_usdt'] ?? 0; ?>"><div class="currency-name">تتر</div><div class="balance-display">$<?php echo number_format($user['balance_usdt'] ?? 0, 2); ?></div></div>
                </div>
                <div class="amount-input">
                    <label><i class="fas fa-edit"></i> مبلغ برداشت</label>
                    <div class="amount-input-wrapper">
                        <span class="amount-currency-symbol" id="amountCurrencySymbol">💰</span>
                        <input type="text" id="withdrawalStepAmount" placeholder="مبلغ را وارد کنید" oninput="formatAmountInput(this)">
                    </div>
                    <div id="amountError"></div>
                    <div class="live-balance"><span><i class="fas fa-wallet"></i> موجودی قابل برداشت:</span><span id="liveBalanceAmount">0</span></div>
                    <div id="amountWordsDisplay" style="font-size: 0.7rem; color: #4CD964; margin-top: 8px; text-align: center; display: none;"></div>
                </div>
                <div class="step-buttons"><button class="btn-step btn-step-next" onclick="goToStep(2)">مرحله بعد <i class="fas fa-arrow-left"></i></button></div>
            </div>
            
            <!-- استپ 2 -->
            <div id="step2" class="step-content">
                <div class="bank-form">
                    <!-- اگر حساب معرفی‌شده‌ای وجود ندارد، کاربر را به «معرفی حساب» هدایت کن -->
                    <?php
                        // فهرست کامل حساب‌های معرفی‌شده برای انتخاب مستقیم
                        $__bens = [];
                        if (isset($conn) && ($conn instanceof mysqli) && !empty($_SESSION['user_id'])) {
                            $__bq = @$conn->query("SELECT id, full_name, card_number, iban, bank_name FROM user_beneficiaries WHERE user_id = " . (int)$_SESSION['user_id'] . " ORDER BY id DESC LIMIT 100");
                            if ($__bq) while ($__br = $__bq->fetch_assoc()) $__bens[] = $__br;
                        }
                        $__benCnt = count($__bens);
                    ?>
                    <?php if ($__benCnt === 0): ?>
                    <div id="wdBenEmptyNotice" class="bank-form-group" style="background:rgba(255,217,61,.08);border:1px solid rgba(255,217,61,.3);border-radius:14px;padding:12px 14px;">
                        <div style="font-size:.78rem;line-height:1.8;color:#FFD93D;">
                            <i class="fas fa-lightbulb"></i>
                            برای ثبت درخواست تسویه ابتدا باید حساب مقصد را معرفی کنید.
                            <button type="button" onclick="wdBenAddOpen()" style="background:none;border:none;padding:0;color:#38BDF8;font-weight:800;cursor:pointer;font-family:inherit;font-size:inherit;">معرفی حساب »</button>
                        </div>
                    </div>
                    <?php endif; ?>
                    <!-- اطلاعات حساب دیگر دستی وارد نمی‌شود؛ از حساب‌های معرفی‌شده انتخاب می‌شود -->
                    <input type="hidden" id="withdrawalRecipient">
                    <input type="hidden" id="withdrawalBank">
                    <input type="hidden" id="withdrawalIban">
                    <input type="hidden" id="withdrawalCard">

                    <div class="bank-form-group">
                        <label><i class="fas fa-address-book"></i> انتخاب حساب مقصد</label>
                        <input type="text" id="wdBenSearch" placeholder="جستجوی نام گیرنده..." oninput="wdBenFilter()"
                               style="width:100%;padding:10px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.06);color:#fff;">
                        <div id="wdBenList" style="display:flex;flex-direction:column;gap:8px;max-height:210px;overflow-y:auto;margin-top:10px;">
                            <?php foreach ($__bens as $__b): $__h = ((int)$__b['id']*47)%360; ?>
                            <div class="wd-ben-row" data-name="<?php echo htmlspecialchars(mb_strtolower($__b['full_name'])); ?>"
                                 onclick='wdBenPick(this, <?php echo json_encode([
                                     "name"=>$__b["full_name"],"bank"=>$__b["bank_name"],
                                     "iban"=>$__b["iban"],"card"=>$__b["card_number"]], JSON_UNESCAPED_UNICODE); ?>)'
                                 style="display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:14px;cursor:pointer;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.05);transition:.2s;">
                                <div style="width:38px;height:38px;border-radius:50%;flex:none;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,hsl(<?php echo $__h; ?>,70%,55%),hsl(<?php echo ($__h+40)%360; ?>,70%,40%));">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8.2" r="3.6" fill="#fff"/><path d="M4.6 19.4c.9-3.6 3.9-5.6 7.4-5.6s6.5 2 7.4 5.6c.15.6-.33 1.1-.95 1.1H5.55c-.62 0-1.1-.5-.95-1.1z" fill="#fff"/><path d="M18.2 3.1l-1.05 2.2 1.75.55-2.6 3.05.75-2.35-1.6-.5 2.75-2.95z" fill="#FFD93D"/></svg>
                                </div>
                                <div style="min-width:0;">
                                    <div style="font-size:.82rem;font-weight:800;color:#fff;"><?php echo htmlspecialchars($__b['full_name']); ?></div>
                                    <div style="font-size:.66rem;color:rgba(255,255,255,.5);"><?php echo htmlspecialchars($__b['bank_name'] ?: ($__b['iban'] ?: $__b['card_number'])); ?></div>
                                </div>
                                <i class="fas fa-check-circle wd-ben-check" style="margin-right:auto;color:#22C55E;opacity:0;transition:.2s;"></i>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div style="margin-top:8px;font-size:.7rem;">
                            <button type="button" onclick="wdBenAddOpen()" style="background:none;border:none;padding:0;color:#38BDF8;font-weight:800;cursor:pointer;font-family:inherit;font-size:inherit;"><i class="fas fa-plus-circle"></i> معرفی حساب جدید</button>
                        </div>
                    </div>
                    <div id="wdBenPicked" style="display:none;margin-top:2px;padding:10px 14px;border-radius:12px;background:rgba(34,197,94,.10);border:1px solid rgba(34,197,94,.35);color:#86EFAC;font-size:.76rem;font-weight:700;"></div>

                    <!-- مدال درجای معرفی حساب جدید -->
                    <div id="wdBenAddModal" style="display:none;position:fixed;inset:0;z-index:99999;align-items:center;justify-content:center;background:rgba(10,6,28,.72);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);padding:18px;">
                        <div style="width:100%;max-width:360px;background:linear-gradient(165deg,#241556,#140C33);border:1px solid rgba(255,255,255,.16);border-radius:22px;padding:20px;box-shadow:0 24px 60px rgba(0,0,0,.6);">
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
                                <div style="color:#fff;font-weight:900;font-size:.95rem;"><i class="fas fa-user-plus" style="color:#A855F7;"></i> معرفی حساب جدید</div>
                                <button type="button" onclick="wdBenAddClose()" style="background:rgba(255,255,255,.08);border:none;color:#fff;width:30px;height:30px;border-radius:50%;cursor:pointer;"><i class="fas fa-times"></i></button>
                            </div>
                            <input type="text" id="wdBenName"  placeholder="نام و نام خانوادگی گیرنده *" style="width:100%;margin-bottom:9px;padding:11px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.06);color:#fff;">
                            <input type="text" id="wdBenCard"  placeholder="شماره کارت" style="width:100%;margin-bottom:9px;padding:11px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.06);color:#fff;">
                            <input type="text" id="wdBenIban"  placeholder="شبا / IBAN (هر فرمتی)" style="width:100%;margin-bottom:9px;padding:11px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.06);color:#fff;">
                            <input type="text" id="wdBenBank"  placeholder="نام بانک" style="width:100%;margin-bottom:9px;padding:11px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.06);color:#fff;">
                            <input type="text" id="wdBenNote"  placeholder="توضیحات (اختیاری)" style="width:100%;margin-bottom:10px;padding:11px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.06);color:#fff;">
                            <div id="wdBenAddErr" style="display:none;margin-bottom:10px;padding:9px 12px;border-radius:10px;background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.4);color:#FCA5A5;font-size:.74rem;font-weight:700;"></div>
                            <button type="button" id="wdBenAddSaveBtn" onclick="wdBenAddSave()" style="width:100%;padding:12px;border:none;border-radius:14px;background:linear-gradient(135deg,#7C3AED,#A855F7);color:#fff;font-weight:900;cursor:pointer;font-family:inherit;">ذخیره و انتخاب حساب</button>
                        </div>
                    </div>
                    <div class="bank-form-group"><label><i class="fas fa-comment"></i> توضیحات اضافی</label><textarea id="withdrawalNotes" rows="2" placeholder="در صورت نیاز توضیحات خود را وارد کنید..."></textarea></div>
                </div>
                <div class="step-buttons">
                    <button class="btn-step btn-step-prev" onclick="goToStep(1)"><i class="fas fa-arrow-right"></i> مرحله قبل</button>
                    <button class="btn-step btn-step-next" onclick="goToStep(3)">مرحله بعد <i class="fas fa-arrow-left"></i></button>
                </div>
            </div>
            
            <!-- استپ 3 -->
            <div id="step3" class="step-content">
                <div class="summary-card">
                    <div class="summary-row"><div class="summary-label"><i class="fas fa-coins"></i> ارز</div><div class="summary-value" id="summaryCurrency">-</div></div>
                    <div class="summary-row"><div class="summary-label"><i class="fas fa-chart-line"></i> مبلغ برداشت</div><div class="summary-value" id="summaryAmount">-</div></div>
                    <div class="summary-row"><div class="summary-label"><i class="fas fa-font"></i> مبلغ به حروف</div><div class="summary-value" id="summaryAmountWords" style="font-size: 0.7rem;">-</div></div>
                    <div class="summary-row"><div class="summary-label"><i class="fas fa-user"></i> نام گیرنده</div><div class="summary-value" id="summaryRecipient">-</div></div>
                    <div class="summary-row"><div class="summary-label"><i class="fas fa-university"></i> نام بانک</div><div class="summary-value" id="summaryBank">-</div></div>
                    <div class="summary-row"><div class="summary-label"><i class="fas fa-hashtag"></i> شماره شبا</div><div class="summary-value" id="summaryIban">-</div></div>
                </div>
                <div class="step-buttons">
                    <button class="btn-step btn-step-prev" onclick="goToStep(2)"><i class="fas fa-arrow-right"></i> ویرایش اطلاعات</button>
                    <button class="btn-step btn-step-submit" onclick="submitWithdrawalRequest()"><i class="fas fa-check-circle"></i> ثبت درخواست</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- مودال سریع تسویه برای شخص خاص (آخرین واریزی‌ها) -->
<div id="quickWithdrawalModal" class="modal-overlay">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3><i class="fas fa-user-check"></i> درخواست تسویه برای <span id="quickTargetName">...</span></h3>
            <button class="close-modal" onclick="closeQuickWithdrawalModal()">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="quickTargetUserId">
            <input type="hidden" id="quickTargetNameHidden">
            
            <div class="form-group">
                <label><i class="fas fa-coins"></i> ارز</label>
                <select id="quickCurrency" class="form-control">
                    <option value="IRR">تومان (IRR)</option>
                    <option value="USD">دلار (USD)</option>
                    <option value="EUR">یورو (EUR)</option>
                    <option value="USDT">تتر (USDT)</option>
                </select>
            </div>
            
            <div class="form-group">
                <label><i class="fas fa-money-bill"></i> مبلغ</label>
                <input type="text" id="quickAmount" class="form-control" placeholder="مبلغ را وارد کنید" oninput="formatAmountInput(this)">
                <div class="info-text" id="quickAmountError" style="color: #FF3B30; font-size: 0.65rem; margin-top: 5px; display: none;"></div>
                <div id="quickAmountWords" style="font-size: 0.6rem; color: #4CD964; margin-top: 5px; text-align: center; display: none;"></div>
            </div>
            
            <!-- اطلاعات بانکی گیرنده (قابل ویرایش) -->
            <div class="form-group">
                <label><i class="fas fa-university"></i> نام بانک</label>
                <input type="text" id="quickBankName" class="form-control" placeholder="نام بانک">
            </div>
            
            <div class="form-group">
                <label><i class="fas fa-code-branch"></i> شماره شبا (IBAN)</label>
                <input type="text" id="quickIbanNumber" class="form-control" placeholder="IR...">
            </div>
            
            <div class="form-group">
                <label><i class="fas fa-credit-card"></i> شماره کارت</label>
                <input type="text" id="quickCardNumber" class="form-control" placeholder="شماره کارت (اختیاری)">
            </div>
            
            <div class="form-group">
                <label><i class="fas fa-comment"></i> توضیحات (اختیاری)</label>
                <textarea id="quickNotes" class="form-control" rows="2" placeholder="توضیحات اضافی..."></textarea>
            </div>
            
            <div class="live-balance" style="margin-bottom: 15px;">
                <span><i class="fas fa-wallet"></i> موجودی شما:</span>
                <span id="quickUserBalance">
                    <?php echo number_format($user['balance_irr'] ?? 0); ?> تومان
                </span>
            </div>
            
            <button type="button" class="btn-submit" id="submitQuickWithdrawalBtn" style="background: linear-gradient(135deg, #FFD700, #FFA500); color: #1a1a2e;">
                <i class="fas fa-paper-plane"></i> ثبت درخواست تسویه
            </button>
        </div>
    </div>
</div>

<!-- مودال آپلود فیش (برای ادمین) -->
<div id="uploadReceiptModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header"><h3><i class="fas fa-upload"></i> آپلود فیش پرداخت</h3><button class="close-modal" onclick="closeUploadReceiptModal()">&times;</button></div>
        <div class="modal-body">
            <div id="uploadReceiptInfo" style="margin-bottom: 15px; background: rgba(108,64,197,0.12); padding: 12px; border-radius: 16px;"></div>
            <form id="uploadReceiptForm" enctype="multipart/form-data" onsubmit="return false;">
                <input type="hidden" id="uploadWithdrawalId">
                <div class="form-group">
                    <label><i class="fas fa-credit-card"></i> کارت مبدأ (انتخاب کنید)</label>
                    <select id="receiptCardId" class="form-control" required>
                        <option value="">انتخاب کارت...</option>
                        <?php foreach ($companyCards as $card): ?>
                            <option value="<?php echo $card['id']; ?>"><?php echo htmlspecialchars($card['bank_name']); ?> - **** <?php echo substr($card['card_number'], -4); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-barcode"></i> شماره تراکنش/پیگیری</label>
                    <input type="text" id="receiptTransactionId" class="form-control" placeholder="شماره پیگیری یا تراکنش">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-image"></i> تصویر فیش</label>
                    <input type="file" id="receiptFile" name="receipt" accept="image/*,application/pdf" class="form-control">
                    <small style="color: rgba(255,255,255,0.4);">فرمت‌های مجاز: JPG, PNG, PDF (حداکثر 5MB)</small>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-info-circle"></i> توضیحات (اختیاری)</label>
                    <textarea id="receiptNotes" name="notes" class="form-control" rows="2" placeholder="توضیحات مربوط به فیش..."></textarea>
                </div>
                <div id="receiptPreview" style="margin-top: 10px; text-align: center;"></div>
                <button type="button" class="btn-submit" id="uploadReceiptBtn"><i class="fas fa-cloud-upload-alt"></i> آپلود فیش و تایید نهایی</button>
            </form>
        </div>
    </div>
</div>

<!-- مودال مشاهده فیش -->
<div id="viewReceiptModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header"><h3><i class="fas fa-receipt"></i> فیش پرداخت</h3><button class="close-modal" onclick="closeViewReceiptModal()">&times;</button></div>
        <div class="modal-body" id="viewReceiptContent"><div style="text-align: center;"><i class="fas fa-spinner fa-spin"></i> در حال بارگذاری...</div></div>
    </div>
</div>

<!-- مودال افزودن/ویرایش کارت -->
<div id="cardModal" class="modal-overlay">
    <div class="modal-content" style="max-width: 450px;">
        <div class="modal-header"><h3 id="cardModalTitle"><i class="fas fa-credit-card"></i> افزودن کارت جدید</h3><button class="close-modal" onclick="closeCardModal()">&times;</button></div>
        <div class="modal-body">
            <input type="hidden" id="editCardId">
            <div class="form-group"><label><i class="fas fa-credit-card"></i> شماره کارت (۱۶ رقم)</label><input type="text" id="cardNumber" class="form-control" maxlength="24" placeholder="**** **** **** ****" oninput="this.value=this.value.replace(/[^0-9]/g,'')"></div>
            <div class="form-group"><label><i class="fas fa-university"></i> نام بانک</label><input type="text" id="bankName" class="form-control" placeholder="مثال: ملی، ملت، صادرات"></div>
            <div class="form-group"><label><i class="fas fa-user-tie"></i> نام صاحب کارت</label><input type="text" id="cardOwner" class="form-control" placeholder="شرکت آراد"></div>
            <div class="form-group"><label><i class="fas fa-align-left"></i> توضیحات</label><textarea id="cardDescription" class="form-control" rows="2" placeholder="توضیحات (اختیاری)"></textarea></div>
            <div class="form-group"><label><i class="fas fa-toggle-on"></i> فعال</label><select id="cardIsActive" class="form-control"><option value="1">فعال</option><option value="0">غیرفعال</option></select></div>
            <button type="button" class="btn-submit" id="saveCardBtn"><i class="fas fa-save"></i> ذخیره کارت</button>
        </div>
    </div>
</div>

<!-- گوی شناور پشتیبانی -->
<div class="floating-sphere" onclick="window.location.href='../api/support.php'"><i class="fas fa-headset"></i></div>

<script>
// ==================== متغیرها ====================
let selectedCurrency = null;
let selectedBalance = 0;
let userWithdrawals = [];
let currentStep = 1;
let isSubmitting = false;
let companyCards = [];

// ==================== تابع تبدیل عدد به حروف فارسی جاوااسکریپت ====================
function convertNumberToPersianWordsJS(number) {
    if (number == 0) return 'صفر';
    number = Math.floor(Math.abs(number));
    const units = ['', 'یک', 'دو', 'سه', 'چهار', 'پنج', 'شش', 'هفت', 'هشت', 'نه'];
    const teens = ['ده', 'یازده', 'دوازده', 'سیزده', 'چهارده', 'پانزده', 'شانزده', 'هفده', 'هجده', 'نوزده'];
    const tens = ['', 'ده', 'بیست', 'سی', 'چهل', 'پنجاه', 'شصت', 'هفتاد', 'هشتاد', 'نود'];
    const thousands = ['', 'هزار', 'میلیون', 'میلیارد', 'تریلیون', 'کوادریلیون'];
    
    function convertThreeDigit(num) {
        let str = '';
        let hundred = Math.floor(num / 100);
        let remainder = num % 100;
        if (hundred > 0) {
            str += units[hundred] + 'صد';
            if (remainder > 0) str += ' و ';
        }
        if (remainder >= 20) {
            let ten = Math.floor(remainder / 10);
            let unit = remainder % 10;
            str += tens[ten];
            if (unit > 0) str += ' و ' + units[unit];
        } else if (remainder >= 10) {
            str += teens[remainder - 10];
        } else if (remainder > 0) {
            str += units[remainder];
        }
        return str;
    }
    
    let parts = [];
    let index = 0;
    let temp = number;
    
    while (temp > 0) {
        let threeDigit = temp % 1000;
        if (threeDigit > 0) {
            let converted = convertThreeDigit(threeDigit);
            if (index > 0) {
                converted += ' ' + thousands[index];
            }
            parts.unshift(converted);
        }
        temp = Math.floor(temp / 1000);
        index++;
    }
    
    return parts.join(' و ');
}

function convertToTomanWordsJS(number) {
    const num = Math.floor(Math.abs(number));
    if (num === 0) return 'صفر تومان';
    
    if (num >= 1000000000) {
        const billions = Math.floor(num / 1000000000);
        const remainder = num % 1000000000;
        if (remainder > 0) {
            return convertNumberToPersianWordsJS(billions) + ' میلیارد و ' + convertNumberToPersianWordsJS(remainder) + ' تومان';
        }
        return convertNumberToPersianWordsJS(billions) + ' میلیارد تومان';
    } else if (num >= 1000000) {
        const millions = Math.floor(num / 1000000);
        const remainder = num % 1000000;
        if (remainder > 0) {
            return convertNumberToPersianWordsJS(millions) + ' میلیون و ' + convertNumberToPersianWordsJS(remainder) + ' تومان';
        }
        return convertNumberToPersianWordsJS(millions) + ' میلیون تومان';
    } else if (num >= 1000) {
        const thousands = Math.floor(num / 1000);
        const remainder = num % 1000;
        if (remainder > 0) {
            return convertNumberToPersianWordsJS(thousands) + ' هزار و ' + convertNumberToPersianWordsJS(remainder) + ' تومان';
        }
        return convertNumberToPersianWordsJS(thousands) + ' هزار تومان';
    }
    return convertNumberToPersianWordsJS(num) + ' تومان';
}

// ==================== تابع فرمت مبلغ با جدا کننده سه رقم ====================
function formatAmountInput(input) {
    let value = input.value.replace(/[^0-9]/g, '');
    if (value === '') {
        input.value = '';
        return;
    }
    let number = parseInt(value, 10);
    let formatted = number.toLocaleString('en-US');
    input.value = formatted;
    
    // نمایش مبلغ به حروف (برای ریال)
    const currencySelect = document.getElementById('quickCurrency');
    const isIRR = currencySelect ? currencySelect.value === 'IRR' : selectedCurrency === 'IRR';
    
    if (isIRR && number > 0) {
        const wordsDiv = input.id === 'quickAmount' ? document.getElementById('quickAmountWords') : document.getElementById('amountWordsDisplay');
        if (wordsDiv) {
            wordsDiv.innerHTML = '<i class="fas fa-font"></i> ' + convertToTomanWordsJS(number);
            wordsDiv.style.display = 'block';
        }
    }
}

// ==================== توابع کمکی ====================
function showToast(message, type) {
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i> ${message}`;
    document.body.appendChild(toast);
    setTimeout(() => toast.classList.add('show'), 10);
    setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 300); }, 3500);
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatMoneyCompactJS(amount, currency) {
    const currencyName = currency === 'IRR' ? 'تومان' : currency;
    if (amount >= 1000000000) {
        const value = (amount / 1000000000).toFixed(1);
        return value + ' میلیارد ' + currencyName;
    } else if (amount >= 1000000) {
        const value = (amount / 1000000).toFixed(1);
        return value + ' میلیون ' + currencyName;
    } else if (amount >= 1000) {
        const value = (amount / 1000).toFixed(1);
        const unit = currency === 'IRR' ? 'هزار تومان' : 'هزار ' + currencyName;
        return value + ' ' + unit;
    }
    return amount.toLocaleString('fa-IR') + ' ' + currencyName;
}

function getCurrencyIcon(currency) {
    switch(currency) { case 'IRR': return '💰'; case 'USD': return '💵'; case 'EUR': return '💶'; case 'USDT': return '🪙'; default: return '💰'; }
}

// ==================== توابع مودال سریع تسویه برای آخرین واریزی‌ها ====================
let currentQuickTargetUserId = null;
let currentQuickTargetName = '';

function openQuickWithdrawalForUser(userId, userName, ibanNumber, cardNumber, bankName) {
    
    currentQuickTargetUserId = userId;
    currentQuickTargetName = userName;
    
    const targetNameSpan = document.getElementById('quickTargetName');
    if (targetNameSpan) {
        targetNameSpan.innerText = userName;
    }
    
    const targetUserIdInput = document.getElementById('quickTargetUserId');
    if (targetUserIdInput) {
        targetUserIdInput.value = userId;
    }
    
    const targetNameHidden = document.getElementById('quickTargetNameHidden');
    if (targetNameHidden) {
        targetNameHidden.value = userName;
    }
    
    const bankNameInput = document.getElementById('quickBankName');
    if (bankNameInput) {
        bankNameInput.value = bankName || '';
    }
    
    const ibanNumberInput = document.getElementById('quickIbanNumber');
    if (ibanNumberInput) {
        ibanNumberInput.value = ibanNumber || '';
    }
    
    const cardNumberInput = document.getElementById('quickCardNumber');
    if (cardNumberInput) {
        cardNumberInput.value = cardNumber || '';
    }
    
    const amountInput = document.getElementById('quickAmount');
    if (amountInput) {
        amountInput.value = '';
    }
    
    const notesInput = document.getElementById('quickNotes');
    if (notesInput) {
        notesInput.value = '';
    }
    
    const currencySelect = document.getElementById('quickCurrency');
    if (currencySelect) {
        currencySelect.value = 'IRR';
    }
    
    const errorDiv = document.getElementById('quickAmountError');
    if (errorDiv) {
        errorDiv.style.display = 'none';
    }
    
    const wordsDiv = document.getElementById('quickAmountWords');
    if (wordsDiv) {
        wordsDiv.style.display = 'none';
    }
    
    const modal = document.getElementById('quickWithdrawalModal');
    if (modal) {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    } else {
        console.error('Modal element not found!');
        showToast('خطا در باز کردن مودال', 'error');
    }
}

function closeQuickWithdrawalModal() {
    const modal = document.getElementById('quickWithdrawalModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
    currentQuickTargetUserId = null;
}

async function submitQuickWithdrawal() {
    if (!currentQuickTargetUserId && currentQuickTargetUserId !== 0) {
        showToast('خطا: کاربر مقصد مشخص نیست', 'error');
        return;
    }
    
    const currency = document.getElementById('quickCurrency').value;
    const amountRaw = document.getElementById('quickAmount').value;
    const amount = parseFloat(amountRaw.replace(/[^0-9]/g, '')) || 0;
    const bankName = document.getElementById('quickBankName').value.trim();
    const ibanNumber = document.getElementById('quickIbanNumber').value.trim();
    const cardNumber = document.getElementById('quickCardNumber').value.trim();
    const notes = document.getElementById('quickNotes').value.trim();
    const targetName = currentQuickTargetName;
    
    if (!amount || amount <= 0) {
        showToast('لطفاً مبلغ معتبر وارد کنید', 'error');
        return;
    }
    
    if (!bankName) {
        showToast('لطفاً نام بانک را وارد کنید', 'error');
        return;
    }
    
    if (!ibanNumber || ibanNumber.length < 10) {
        showToast('لطفاً شماره شبا معتبر وارد کنید (حداقل 10 کاراکتر)', 'error');
        return;
    }
    
    let minAmount = currency === 'IRR' ? 50000 : 10;
    if (amount < minAmount) {
        showToast(`حداقل مبلغ برداشت ${currency === 'IRR' ? '۵۰,۰۰۰ تومان' : minAmount + ' ' + currency} می‌باشد`, 'error');
        return;
    }
    
    const postData = {
        target_user_id: currentQuickTargetUserId,
        target_user_name: targetName,
        currency: currency,
        amount: amount,
        bank_name: bankName,
        iban_number: ibanNumber,
        card_number: cardNumber,
        notes: notes
    };
    
    
    const btn = document.getElementById('submitQuickWithdrawalBtn');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال ثبت...';
    
    try {
        const response = await fetch('../api/withdraw.php?action=quick_withdrawal', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(postData)
        });
        
        const result = await response.json();
        
        if (result.success) {
            showToast('✅ درخواست تسویه با موفقیت ثبت شد', 'success');
            closeQuickWithdrawalModal();
            loadUserWithdrawals();
            setTimeout(() => location.reload(), 2000);
        } else {
            showToast('❌ ' + result.message, 'error');
        }
    } catch(e) {
        console.error('Error:', e);
        showToast('❌ خطا در ثبت درخواست: ' + e.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

// ==================== پنل تسویه ====================
function toggleWithdrawalPanel() {
    const body = document.getElementById('withdrawalPanelBody');
    const icon = document.getElementById('withdrawalPanelIcon');
    if (body && icon) {
        if (body.style.display === 'none') { body.style.display = 'block'; icon.style.transform = 'rotate(0deg)'; }
        else { body.style.display = 'none'; icon.style.transform = 'rotate(180deg)'; }
    }
}

function openWithdrawalModal() {
    resetModal();
    const modal = document.getElementById('withdrawalStepModal');
    if (modal) { modal.classList.add('active'); modal.style.display = 'flex'; document.body.style.overflow = 'hidden'; }
}

function closeWithdrawalModal() {
    const modal = document.getElementById('withdrawalStepModal');
    if (modal) { modal.classList.remove('active'); modal.style.display = 'none'; document.body.style.overflow = ''; }
    // (آپدیت ۳) در حالت مدال سریع (iframe داخل arad/داشبورد)، بستن یعنی بستن کل قاب والد
    try {
        var params = new URLSearchParams(window.location.search);
        if (params.get('new') === '1' && window.parent && window.parent !== window) {
            if (typeof window.parent.axCloseServiceModal === 'function') window.parent.axCloseServiceModal();
            else if (typeof window.parent.avaCloseFsModal === 'function') window.parent.avaCloseFsModal('avaTransferModal');
        }
    } catch (e) {}
}

function resetModal() {
    currentStep = 1; selectedCurrency = null; selectedBalance = 0;
    document.querySelectorAll('.step').forEach((step, index) => { step.classList.remove('active', 'completed'); if (index === 0) step.classList.add('active'); });
    document.querySelectorAll('.step-content').forEach((content, index) => { content.classList.remove('active-step'); if (index === 0) content.classList.add('active-step'); });
    document.querySelectorAll('.currency-select-card').forEach(card => card.classList.remove('selected'));
    const amountInput = document.getElementById('withdrawalStepAmount'); if (amountInput) amountInput.value = '';
    const recipientInput = document.getElementById('withdrawalRecipient'); if (recipientInput) recipientInput.value = '';
    const bankInput = document.getElementById('withdrawalBank'); if (bankInput) bankInput.value = '';
    const ibanInput = document.getElementById('withdrawalIban'); if (ibanInput) ibanInput.value = '';
    const cardInput = document.getElementById('withdrawalCard'); if (cardInput) cardInput.value = '';
    const notesInput = document.getElementById('withdrawalNotes'); if (notesInput) notesInput.value = '';
    const errorDiv = document.getElementById('amountError'); if (errorDiv) { errorDiv.style.display = 'none'; errorDiv.innerHTML = ''; }
    const liveBalance = document.getElementById('liveBalanceAmount'); if (liveBalance) liveBalance.innerHTML = '0';
    const wordsDiv = document.getElementById('amountWordsDisplay'); if (wordsDiv) { wordsDiv.style.display = 'none'; wordsDiv.innerHTML = ''; }
}

function updateLiveBalance() {
    const liveBalanceSpan = document.getElementById('liveBalanceAmount');
    const currencySymbolSpan = document.getElementById('amountCurrencySymbol');
    if (selectedCurrency && liveBalanceSpan) {
        let symbol = '💰'; let formattedBalance = '';
        switch(selectedCurrency) {
            case 'IRR': symbol = '💰'; formattedBalance = selectedBalance.toLocaleString('fa-IR') + ' تومان'; break;
            case 'USD': symbol = '💵'; formattedBalance = '$' + selectedBalance.toLocaleString(); break;
            case 'EUR': symbol = '💶'; formattedBalance = '€' + selectedBalance.toLocaleString(); break;
            case 'USDT': symbol = '🪙'; formattedBalance = '$' + selectedBalance.toLocaleString() + ' USDT'; break;
        }
        liveBalanceSpan.innerHTML = formattedBalance;
        if (currencySymbolSpan) currencySymbolSpan.innerHTML = symbol;
    }
}

function initCurrencySelection() {
    const cards = document.querySelectorAll('.currency-select-card');
    if (cards.length === 0) return;
    cards.forEach(card => {
        card.addEventListener('click', function() {
            document.querySelectorAll('.currency-select-card').forEach(c => c.classList.remove('selected'));
            this.classList.add('selected');
            selectedCurrency = this.getAttribute('data-currency');
            selectedBalance = parseFloat(this.getAttribute('data-balance')) || 0;
            updateLiveBalance();
            validateAmount();
        });
    });
}

function validateAmount() {
    const amountInput = document.getElementById('withdrawalStepAmount');
    if (!amountInput) return false;
    const rawValue = amountInput.value.replace(/[^0-9]/g, '');
    const amount = parseFloat(rawValue) || 0;
    const errorDiv = document.getElementById('amountError');
    if (errorDiv) errorDiv.style.display = 'none';
    if (amount <= 0) return false;
    if (!selectedCurrency) {
        if (errorDiv) { errorDiv.style.display = 'block'; errorDiv.innerHTML = '✨ لطفاً ابتدا ارز مورد نظر را انتخاب کنید ✨'; }
        return false;
    }
    if (amount > selectedBalance) {
        if (errorDiv) { errorDiv.style.display = 'block'; errorDiv.innerHTML = `⚠️ موجودی ناکافی! موجودی شما: ${selectedBalance.toLocaleString('fa-IR')} ${selectedCurrency === 'IRR' ? 'تومان' : selectedCurrency} ⚠️`; }
        return false;
    }
    let minAmount = selectedCurrency === 'IRR' ? 50000 : 10;
    if (amount < minAmount) {
        if (errorDiv) { errorDiv.style.display = 'block'; errorDiv.innerHTML = `⚠️ حداقل مبلغ برداشت ${selectedCurrency === 'IRR' ? '۵۰,۰۰۰ تومان' : minAmount + ' ' + selectedCurrency} می‌باشد ⚠️`; }
        return false;
    }
    return true;
}

function goToStep(step) {
    if (step === 2) {
        if (!selectedCurrency) { showToast('لطفاً ارز مورد نظر را انتخاب کنید', 'error'); return; }
        const amountInput = document.getElementById('withdrawalStepAmount'); 
        const rawValue = amountInput ? amountInput.value.replace(/[^0-9]/g, '') : '0';
        const amount = parseFloat(rawValue) || 0;
        if (amount <= 0) { showToast('لطفاً مبلغ معتبر وارد کنید', 'error'); return; }
        if (!validateAmount()) return;
    }
    if (step === 3) {
        const recipient = document.getElementById('withdrawalRecipient')?.value.trim() || '';
        const bank = document.getElementById('withdrawalBank')?.value.trim() || '';
        const iban = document.getElementById('withdrawalIban')?.value.trim() || '';
        if (!recipient) { showToast('لطفاً حساب مقصد را از فهرست انتخاب کنید', 'error'); return; }
        const cardV = document.getElementById('withdrawalCard')?.value.trim() || '';
        if (!iban && !cardV) { showToast('حساب انتخاب‌شده شماره شبا/کارت ندارد', 'error'); return; }
        
        const amountInput = document.getElementById('withdrawalStepAmount');
        const rawValue = amountInput ? amountInput.value.replace(/[^0-9]/g, '') : '0';
        const amount = parseFloat(rawValue) || 0;
        
        const summaryCurrency = document.getElementById('summaryCurrency'); 
        const summaryAmount = document.getElementById('summaryAmount');
        const summaryAmountWords = document.getElementById('summaryAmountWords');
        const summaryRecipient = document.getElementById('summaryRecipient'); 
        const summaryBank = document.getElementById('summaryBank');
        const summaryIban = document.getElementById('summaryIban');
        
        if (summaryCurrency) summaryCurrency.innerHTML = selectedCurrency === 'IRR' ? 'تومان' : selectedCurrency;
        if (summaryAmount) summaryAmount.innerHTML = amount.toLocaleString('fa-IR') + (selectedCurrency === 'IRR' ? ' تومان' : ' ' + selectedCurrency);
        if (summaryAmountWords && selectedCurrency === 'IRR') {
            summaryAmountWords.innerHTML = convertToTomanWordsJS(amount);
        } else if (summaryAmountWords) {
            summaryAmountWords.innerHTML = '-';
        }
        if (summaryRecipient) summaryRecipient.innerHTML = escapeHtml(recipient);
        if (summaryBank) summaryBank.innerHTML = escapeHtml(bank);
        if (summaryIban) summaryIban.innerHTML = escapeHtml(iban);
    }
    document.querySelectorAll('.step').forEach((el, idx) => { el.classList.remove('active', 'completed'); if (idx + 1 < step) el.classList.add('completed'); else if (idx + 1 === step) el.classList.add('active'); });
    document.querySelectorAll('.step-content').forEach((el, idx) => { el.classList.remove('active-step'); if (idx + 1 === step) el.classList.add('active-step'); });
    currentStep = step;
}

async function submitWithdrawalRequest() {
    if (isSubmitting) return;
    const currency = selectedCurrency;
    const amountInput = document.getElementById('withdrawalStepAmount');
    const rawValue = amountInput ? amountInput.value.replace(/[^0-9]/g, '') : '0';
    const amount = parseFloat(rawValue) || 0;
    const recipientName = document.getElementById('withdrawalRecipient')?.value.trim() || '';
    const bankName = document.getElementById('withdrawalBank')?.value.trim() || '';
    const ibanNumber = document.getElementById('withdrawalIban')?.value.trim() || '';
    const cardNumber = document.getElementById('withdrawalCard')?.value.trim() || '';
    const notes = document.getElementById('withdrawalNotes')?.value.trim() || '';
    if (!currency || !amount || amount <= 0) { showToast('اطلاعات را کامل وارد کنید', 'error'); return; }
    if (!recipientName || !bankName || !ibanNumber) { showToast('لطفاً تمام اطلاعات بانکی را کامل وارد کنید', 'error'); return; }
    isSubmitting = true;
    const btn = document.querySelector('.btn-step-submit');
    if (!btn) return;
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال ثبت...';
    try {
        const response = await fetch('../api/withdraw.php?action=submit', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ amount, currency, iban_number: ibanNumber, card_number: cardNumber, bank_name: bankName, recipient_name: recipientName, notes }) });
        const result = await response.json();
        if (result.success) { showToast('🎉 ' + result.message, 'success'); closeWithdrawalModal(); loadUserWithdrawals(); setTimeout(() => location.reload(), 2000); }
        else { showToast('❌ ' + result.message, 'error'); }
    } catch(e) { console.error(e); showToast('❌ خطا در ارسال درخواست', 'error'); }
    finally { btn.disabled = false; btn.innerHTML = originalText; isSubmitting = false; }
}

async function loadUserWithdrawals() {
    const container = document.getElementById('userWithdrawalsList');
    if (!container) return;
    try {
        const response = await fetch('../api/withdraw.php?action=get_user_requests');
        const result = await response.json();
        if (result.success && result.data) {
            userWithdrawals = result.data;
            let totals = { IRR: { amount: 0, count: 0 }, USD: { amount: 0, count: 0 }, EUR: { amount: 0, count: 0 }, USDT: { amount: 0, count: 0 } };
            const completedWithdrawals = userWithdrawals.filter(w => w.status === 'completed');
            completedWithdrawals.forEach(w => { const currency = w.currency; const amount = parseFloat(w.amount); if (totals[currency]) { totals[currency].amount += amount; totals[currency].count++; } });
            const pendingCount = userWithdrawals.filter(w => w.status === 'pending').length;
            const badge = document.getElementById('withdrawalPendingBadge'); const pendingCountSpan = document.getElementById('withdrawalPendingCount');
            if (badge && pendingCountSpan && pendingCount > 0) { badge.style.display = 'inline-block'; pendingCountSpan.innerHTML = pendingCount; }
            else if (badge) { badge.style.display = 'none'; }
            if (completedWithdrawals.length === 0) {
                let pendingHtml = ''; const pendingWithdrawals = userWithdrawals.filter(w => w.status === 'pending');
                if (pendingWithdrawals.length > 0) { pendingHtml = `<div style="background: linear-gradient(135deg, rgba(255,193,7,0.1), rgba(108,64,197,0.05)); border-radius: 18px; padding: 14px; margin-bottom: 20px; text-align: center; border: 1px solid rgba(255,193,7,0.3);"><div style="font-size: 1rem; margin-bottom: 5px;">⏳</div><div style="font-size: 0.8rem; color: #FFC107; font-weight: 600;">${pendingWithdrawals.length} درخواست در انتظار تایید</div><div style="font-size: 0.6rem; color: rgba(255,255,255,0.4); margin-top: 5px;">پس از تایید، مبلغ به حساب شما واریز می‌شود</div></div>`; }
                container.innerHTML = pendingHtml + `<div class="empty-withdrawals"><i class="fas fa-chart-line"></i><p>هنوز تسویه‌ای انجام نشده است</p><small>پس از تایید درخواست‌ها، آمار تسویه شما اینجا نمایش داده می‌شود</small></div>`;
                return;
            }
            let statsHtml = '<div class="stats-grid-2cols">';
            const currencyOrder = [{ code: 'IRR', name: 'تومان', icon: '💰' }, { code: 'USD', name: 'دلار', icon: '💵' }, { code: 'EUR', name: 'یورو', icon: '💶' }, { code: 'USDT', name: 'تتر', icon: '🪙' }];
            currencyOrder.forEach(curr => { const total = totals[curr.code]; if (total.amount > 0) { statsHtml += `<div class="stats-card"><div class="stats-card-icon">${curr.icon}</div><div class="stats-card-label">مبلغ تسویه شده</div><div class="stats-card-amount">${formatMoneyCompactJS(total.amount, curr.code)}</div><div class="stats-card-count">${total.count} بار تسویه</div></div>`; } });
            statsHtml += '</div>';
            const detailsHtml = completedWithdrawals.map(w => { const amountFormatted = formatMoneyCompactJS(parseFloat(w.amount), w.currency); const currencyIcon = getCurrencyIcon(w.currency); const date = new Date(w.created_at).toLocaleDateString('fa-IR'); const _recs = collectWithdrawalReceipts(w); let receiptHtml = ''; if (_recs.length) { const _cnt = _recs.length > 1 ? ` (${_recs.length})` : ''; receiptHtml = `<div class="withdrawal-receipt-link"><a href="javascript:void(0)" onclick="viewWithdrawalReceipt(${w.id})"><i class="fas fa-receipt"></i> مشاهده فیش پرداخت${_cnt}</a></div>`; } return `<div class="withdrawal-history-item"><div class="withdrawal-history-header"><div class="withdrawal-history-amount"><span>${currencyIcon} ${amountFormatted}</span></div><div class="withdrawal-history-status"><i class="fas fa-check-circle"></i> تسویه شده</div></div><div class="withdrawal-history-details"><span><i class="fas fa-calendar"></i> ${date}</span><span><i class="fas fa-user"></i> ${escapeHtml(w.recipient_name || w.target_user_name || '-')}</span><span><i class="fas fa-university"></i> ${escapeHtml(w.bank_name || '-')}</span></div>${receiptHtml}</div>`; }).join('');
            const pendingWithdrawals = userWithdrawals.filter(w => w.status === 'pending'); let pendingHtml = ''; if (pendingWithdrawals.length > 0) { pendingHtml = `<div style="background: linear-gradient(135deg, rgba(255,193,7,0.1), rgba(108,64,197,0.05)); border-radius: 18px; padding: 14px; margin-bottom: 20px; text-align: center; border: 1px solid rgba(255,193,7,0.3);"><div style="font-size: 1rem; margin-bottom: 5px;">⏳</div><div style="font-size: 0.8rem; color: #FFC107; font-weight: 600;">${pendingWithdrawals.length} درخواست در انتظار تایید</div><div style="font-size: 0.6rem; color: rgba(255,255,255,0.4); margin-top: 5px;">پس از تایید، مبلغ به حساب شما واریز می‌شود</div></div>`; }
            container.innerHTML = pendingHtml + statsHtml + detailsHtml;
        } else { container.innerHTML = '<div class="empty-withdrawals"><i class="fas fa-inbox"></i><p>هیچ درخواست تسویه‌ای ندارید</p></div>'; }
    } catch(e) { console.error('loadUserWithdrawals error:', e); container.innerHTML = '<div class="empty-withdrawals"><i class="fas fa-exclamation-triangle"></i><p>خطا در بارگذاری: ' + e.message + '</p></div>'; }
}

// جمع‌آوری همه‌ی فیش‌های یک درخواست تسویه (پشتیبانی از چند فیش)
function collectWithdrawalReceipts(withdrawal) {
    let list = [];
    if (withdrawal.receipt_files) {
        try {
            const parsed = typeof withdrawal.receipt_files === 'string' ? JSON.parse(withdrawal.receipt_files) : withdrawal.receipt_files;
            if (Array.isArray(parsed)) list = parsed.filter(Boolean);
        } catch(e) { list = []; }
    }
    if (!list.length && withdrawal.receipt_file && withdrawal.receipt_file.trim() !== '') {
        list = [withdrawal.receipt_file];
    }
    return list.map(r => {
        let url = r;
        if (url && !url.startsWith('http') && !url.startsWith('/')) url = '../' + url;
        return { raw: r, url };
    });
}

async function viewWithdrawalReceipt(id) {
    const withdrawal = userWithdrawals.find(w => w.id == id);
    const receipts = withdrawal ? collectWithdrawalReceipts(withdrawal) : [];
    if (!receipts.length) { showToast('فیشی برای این درخواست وجود ندارد', 'error'); return; }
    const modal = document.getElementById('viewReceiptModal'); const content = document.getElementById('viewReceiptContent');
    if (!modal || !content) return;

    const items = receipts.map((rec, i) => {
        const fileExt = (rec.url.split('.').pop() || '').toLowerCase().split('?')[0];
        if (fileExt === 'pdf') {
            return `<div style="display:flex;flex-direction:column;align-items:center;gap:6px;background:rgba(255,255,255,0.05);border-radius:14px;padding:16px 20px;">
                        <i class="fas fa-file-pdf" style="font-size:2.6rem;color:#FF3B30;"></i>
                        <span style="font-size:.72rem;">فیش ${i+1} (PDF)</span>
                        <a href="${rec.url}" target="_blank" style="color:#4CD964;font-size:.72rem;text-decoration:none;"><i class="fas fa-download"></i> دانلود</a>
                    </div>`;
        }
        return `<div style="position:relative;cursor:pointer;" onclick="enlargeWithdrawalReceipt('${rec.url}')">
                    <img src="${rec.url}?t=${Date.now()}" alt="فیش ${i+1}" style="width:130px;height:130px;object-fit:cover;border-radius:14px;border:1px solid rgba(255,215,0,0.25);">
                    <div style="position:absolute;bottom:6px;right:6px;background:rgba(0,0,0,0.6);color:#fff;font-size:.6rem;padding:2px 8px;border-radius:10px;">فیش ${i+1} <i class="fas fa-search-plus"></i></div>
                </div>`;
    }).join('');

    content.innerHTML = `
        <div style="text-align:center;">
            <div style="font-size:.72rem;color:rgba(255,255,255,0.5);margin-bottom:12px;">${receipts.length} فیش تسویه — برای بزرگ‌نمایی روی هر تصویر بزنید</div>
            <div style="display:flex;flex-wrap:wrap;gap:12px;justify-content:center;margin-bottom:16px;">${items}</div>
            <button class="btn-submit" onclick="closeViewReceiptModal()" style="background: rgba(255,255,255,0.1); width:auto; padding:10px 24px;"><i class="fas fa-times"></i> بستن</button>
        </div>`;
    modal.style.display = 'flex'; document.body.style.overflow = 'hidden';
}

// بزرگ‌نمایی یک فیش در لایت‌باکس تمام‌صفحه
function enlargeWithdrawalReceipt(url) {
    let box = document.getElementById('wReceiptLightbox');
    if (!box) {
        box = document.createElement('div');
        box.id = 'wReceiptLightbox';
        box.style.cssText = 'position:fixed;inset:0;z-index:100001;background:rgba(0,0,0,0.93);display:flex;align-items:center;justify-content:center;flex-direction:column;padding:16px;';
        box.onclick = (e) => { if (e.target.id === 'wReceiptLightbox') box.style.display = 'none'; };
        document.body.appendChild(box);
    }
    box.innerHTML = `
        <div style="position:absolute;top:16px;left:16px;color:#fff;font-size:1.6rem;cursor:pointer;" onclick="document.getElementById('wReceiptLightbox').style.display='none'"><i class="fas fa-times"></i></div>
        <img src="${url}" style="max-width:96%;max-height:82%;border-radius:14px;object-fit:contain;box-shadow:0 10px 40px rgba(0,0,0,0.6);">
        <a href="${url}" download style="margin-top:18px;background:#FFD700;color:#000;padding:10px 26px;border-radius:30px;font-weight:700;text-decoration:none;"><i class="fas fa-download"></i> دانلود این فیش</a>`;
    box.style.display = 'flex';
}

function closeViewReceiptModal() { const modal = document.getElementById('viewReceiptModal'); if (modal) { modal.style.display = 'none'; document.body.style.overflow = ''; } }
function closeUploadReceiptModal() { const modal = document.getElementById('uploadReceiptModal'); if (modal) { modal.style.display = 'none'; document.body.style.overflow = ''; } }

function setupFilePreview() {
    const fileInput = document.getElementById('receiptFile');
    if (!fileInput) return;
    fileInput.addEventListener('change', function(e) { const file = e.target.files[0]; const preview = document.getElementById('receiptPreview'); if (!preview) return; if (file && file.type.startsWith('image/')) { const reader = new FileReader(); reader.onload = function(ev) { preview.innerHTML = `<img src="${ev.target.result}" style="max-width:100%; max-height:150px; border-radius:16px; border:1px solid rgba(255,215,0,0.3);">`; }; reader.readAsDataURL(file); } else if (file && file.type === 'application/pdf') { preview.innerHTML = `<div style="padding:12px; background:rgba(255,59,48,0.1); border-radius:14px;"><i class="fas fa-file-pdf" style="font-size:2rem; color:#FF3B30;"></i><br>فایل PDF انتخاب شد: ${file.name}</div>`; } else { preview.innerHTML = ''; } });
}

// ==================== توابع مدیریت کارت‌ها (ادمین) ====================
async function loadCompanyCards() {
    const container = document.getElementById('companyCardsList');
    if (!container) return;
    try {
        const response = await fetch('../api/cards.php?action=get_cards');
        const result = await response.json();
        if (result.success && result.data) {
            companyCards = result.data;
            if (result.data.length === 0) { container.innerHTML = '<div class="empty-state"><i class="fas fa-credit-card" style="font-size: 2.5rem; margin-bottom: 10px; opacity: 0.5;"></i><p>هیچ کارتی تعریف نشده است.</p><button class="add-card-btn" onclick="openAddCardModal()" style="margin-top: 15px;"><i class="fas fa-plus"></i> افزودن کارت جدید</button></div>'; return; }
            let html = '<div style="overflow-x: auto;"><table class="cards-table"><thead><tr><th>ردیف</th><th>شماره کارت</th><th>بانک</th><th>صاحب کارت</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>';
            let rowIndex = 1;
            for (const card of result.data) {
                const maskedCard = '**** **** **** ' + card.card_number.slice(-4);
                const statusHtml = card.is_active == 1 ? '<span style="color:#4CD964"><i class="fas fa-check-circle"></i> فعال</span>' : '<span style="color:#FF3B30"><i class="fas fa-ban"></i> غیرفعال</span>';
                html += `<tr><td style="text-align:center">${rowIndex++}</td><td style="direction:ltr">${maskedCard}</td><td>${escapeHtml(card.bank_name)}</td><td>${escapeHtml(card.card_owner)}</td><td>${statusHtml}</td><td><button class="btn-sm btn-edit" onclick="openEditCardModal(${card.id})"><i class="fas fa-edit"></i> ویرایش</button> <button class="btn-sm btn-delete" onclick="deleteCard(${card.id})"><i class="fas fa-trash"></i> حذف</button></td></tr>`;
            }
            html += '</tbody></table></div>';
            container.innerHTML = html;
        } else { container.innerHTML = '<div class="empty-state">خطا در بارگذاری کارت‌ها</div>'; }
    } catch(e) { console.error(e); container.innerHTML = '<div class="empty-state">خطا در بارگذاری کارت‌ها</div>'; }
}

async function loadCardsStats() {
    const container = document.getElementById('cardsStatsList');
    if (!container) return;
    const year = document.getElementById('reportYear')?.value || new Date().getFullYear();
    try {
        const response = await fetch('../api/withdraw.php?action=get_completed');
        const result = await response.json();
        if (result.success && result.data) {
            const yearData = result.data.filter(w => { const date = new Date(w.admin_action_at || w.created_at); return w.status === 'completed' && date.getFullYear() == year; });
            if (yearData.length === 0) { container.innerHTML = '<div class="empty-state">هیچ واریزی در سال ' + year + ' ثبت نشده است.</div>'; return; }
            const cardStats = {};
            for (const w of yearData) { const cardId = w.card_id || 0; if (!cardStats[cardId]) { cardStats[cardId] = { total_irr: 0, total_usd: 0, total_eur: 0, total_usdt: 0, transaction_count: 0, transactions: [] }; } const amount = parseFloat(w.amount); if (w.currency === 'IRR') cardStats[cardId].total_irr += amount; else if (w.currency === 'USD') cardStats[cardId].total_usd += amount; else if (w.currency === 'EUR') cardStats[cardId].total_eur += amount; else if (w.currency === 'USDT') cardStats[cardId].total_usdt += amount; cardStats[cardId].transaction_count++; cardStats[cardId].transactions.push(w); }
            const cardsResponse = await fetch('../api/cards.php?action=get_cards'); const cardsResult = await cardsResponse.json();
            const cards = cardsResult.success ? cardsResult.data : []; const cardMap = {}; cards.forEach(card => { cardMap[card.id] = { bank_name: card.bank_name, card_number: '**** **** **** ' + card.card_number.slice(-4) }; });
            let html = '';
            for (const [cardId, stats] of Object.entries(cardStats)) {
                const cardInfo = cardMap[cardId] || { bank_name: 'نامشخص', card_number: 'نامشخص' };
                const totalIRR = stats.total_irr; const totalUSD = stats.total_usd; const totalEUR = stats.total_eur; const totalUSDT = stats.total_usdt;
                let transactionsHtml = '';
                if (stats.transactions.length > 0) {
                    transactionsHtml = '<div style="overflow-x:auto"><table class="withdrawal-table"><thead><tr><th>تاریخ</th><th>کاربر</th><th>مبلغ</th><th>ارز</th><th>شماره پیگیری</th></tr></thead><tbody>';
                    for (const t of stats.transactions) {
                        const amountFormatted = t.currency === 'IRR' ? t.amount.toLocaleString('fa-IR') + ' تومان' : t.amount.toLocaleString() + ' ' + t.currency;
                        transactionsHtml += `<tr><td><small>${new Date(t.admin_action_at || t.created_at).toLocaleDateString('fa-IR')}</small></td><td>${escapeHtml(t.first_name || '')} ${escapeHtml(t.last_name || '')}</td><td style="color:#FFD700;">${amountFormatted}</td><td>${t.currency}</td><td>${escapeHtml(t.transaction_id || '-')}</td></tr>`;
                    }
                    transactionsHtml += '</tbody></table></div>';
                }
                const amountWords = totalIRR > 0 ? convertToTomanWordsJS(totalIRR) : '';
                html += `<div class="card-stats-card"><div class="card-stats-header" onclick="this.nextElementSibling.classList.toggle('show')"><div><span class="bank-name">${escapeHtml(cardInfo.bank_name)}</span> <span class="card-number">(${cardInfo.card_number})</span></div><div>💰 ${totalIRR > 0 ? totalIRR.toLocaleString('fa-IR') + ' تومان' : ''}</div></div><div class="card-stats-details"><div style="margin-bottom:15px; padding:10px; background:rgba(76,217,100,0.1); border-radius:12px;"><div><strong>جمع کل مبلغ واریز شده در سال ${year}:</strong> ${totalIRR.toLocaleString('fa-IR')} تومان</div>${amountWords ? `<div class="amount-words"><i class="fas fa-font"></i> ${amountWords}</div>` : ''}<div><strong>تعداد تراکنش‌ها:</strong> ${stats.transaction_count} بار</div></div><div><strong>📋 لیست تراکنش‌ها:</strong></div>${transactionsHtml}</div></div>`;
            }
            container.innerHTML = html;
        } else { container.innerHTML = '<div class="empty-state">هیچ داده آماری وجود ندارد</div>'; }
    } catch(e) { console.error(e); container.innerHTML = '<div class="empty-state">خطا در بارگذاری آمار</div>'; }
}

function openAddCardModal() {
    document.getElementById('cardModalTitle').innerHTML = '<i class="fas fa-credit-card"></i> افزودن کارت جدید';
    document.getElementById('editCardId').value = '';
    document.getElementById('cardNumber').value = '';
    document.getElementById('bankName').value = '';
    document.getElementById('cardOwner').value = 'شرکت آراد';
    document.getElementById('cardDescription').value = '';
    document.getElementById('cardIsActive').value = '1';
    document.getElementById('cardModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function openEditCardModal(cardId) {
    const card = companyCards.find(c => c.id == cardId);
    if (!card) return;
    document.getElementById('cardModalTitle').innerHTML = '<i class="fas fa-edit"></i> ویرایش کارت';
    document.getElementById('editCardId').value = card.id;
    document.getElementById('cardNumber').value = card.card_number;
    document.getElementById('bankName').value = card.bank_name;
    document.getElementById('cardOwner').value = card.card_owner;
    document.getElementById('cardDescription').value = card.description || '';
    document.getElementById('cardIsActive').value = card.is_active;
    document.getElementById('cardModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeCardModal() { document.getElementById('cardModal').style.display = 'none'; document.body.style.overflow = ''; }

async function saveCard() {
    const editId = document.getElementById('editCardId').value;
    const cardNumber = document.getElementById('cardNumber').value.trim();
    const bankName = document.getElementById('bankName').value.trim();
    const cardOwner = document.getElementById('cardOwner').value.trim();
    const description = document.getElementById('cardDescription').value;
    const isActive = document.getElementById('cardIsActive').value;
    if (cardNumber.length < 16) { showToast('شماره کارت باید حداقل ۱۶ رقم باشد', 'error'); return; }
    if (!bankName) { showToast('نام بانک الزامی است', 'error'); return; }
    const action = editId ? 'update_card' : 'add_card';
    const body = editId ? { card_id: editId, card_number: cardNumber, bank_name: bankName, card_owner: cardOwner, description, is_active: isActive } : { card_number: cardNumber, bank_name: bankName, card_owner: cardOwner, description, is_active: isActive };
    try {
        const response = await fetch(`../api/cards.php?action=${action}`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
        const result = await response.json();
        if (result.success) { showToast(result.message, 'success'); closeCardModal(); loadCompanyCards(); loadCardsStats(); }
        else { showToast(result.message, 'error'); }
    } catch(e) { console.error(e); showToast('خطا در ذخیره کارت', 'error'); }
}

async function deleteCard(cardId) {
    if (!confirm('آیا از حذف این کارت اطمینان دارید؟')) return;
    try {
        const response = await fetch('../api/cards.php?action=delete_card', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ card_id: cardId }) });
        const result = await response.json();
        if (result.success) { showToast(result.message, 'success'); loadCompanyCards(); loadCardsStats(); }
        else { showToast(result.message, 'error'); }
    } catch(e) { console.error(e); showToast('خطا در حذف کارت', 'error'); }
}

// ==================== توابع ادمین تسویه ====================


async function loadAdminPendingWithdrawals() {
    const container = document.getElementById('pendingWithdrawalsList');
    if (!container) return;
    try {
        const response = await fetch('../api/withdraw.php?action=get_pending');
        const result = await response.json();
        if (result.success && result.data && result.data.length > 0) {
            document.getElementById('statPendingCount').innerHTML = result.data.length;
            document.getElementById('adminPendingCount').innerHTML = result.data.length;
            document.getElementById('adminPendingBadge').style.display = 'inline-flex';
            let html = '<div style="overflow-x:auto"><table class="withdrawal-table"><thead><tr><th>کاربر</th><th>مبلغ</th><th>ارز</th><th>گیرنده</th><th>بانک</th><th>شبا</th><th>تاریخ</th><th>عملیات</th></tr></thead><tbody>';
            for (const w of result.data) {
                html += `<tr><td>${escapeHtml(w.first_name || '')} ${escapeHtml(w.last_name || '')}<br><small>${escapeHtml(w.telegram_id || '')}</small></td><td style="color:#FFD700;">${Number(w.amount).toLocaleString()}</span></td><td>${w.currency}</td><td>${escapeHtml(w.recipient_name || '-')}</td><td>${escapeHtml(w.bank_name || '-')}</td><td><small>${w.iban_number ? w.iban_number.substring(0,10)+'...' : '-'}</small></td><td><small>${new Date(w.created_at).toLocaleDateString('fa-IR')}</small></td><td><button class="btn-action btn-approve" onclick="approveWithdrawalReq(${w.id})">✅ تایید</button> <button class="btn-action btn-reject" onclick="rejectWithdrawalReq(${w.id})">❌ رد</button></td></tr>`;
            }
            html += '</tbody></table></div>';
            container.innerHTML = html;
        } else {
            document.getElementById('statPendingCount').innerHTML = '0';
            document.getElementById('adminPendingBadge').style.display = 'none';
            container.innerHTML = '<div class="empty-state">هیچ درخواست در انتظاری وجود ندارد</div>';
        }
    } catch(e) { console.error(e); container.innerHTML = '<div class="empty-state">خطا در بارگذاری</div>'; }
}

async function loadAdminApprovedWithdrawals() {
    const container = document.getElementById('approvedWithdrawalsList');
    if (!container) return;
    try {
        const response = await fetch('../api/withdraw.php?action=get_approved');
        const result = await response.json();
        if (result.success && result.data && result.data.length > 0) {
            let html = '<div style="overflow-x:auto"><table class="withdrawal-table"><thead><tr><th>کاربر</th><th>مبلغ</th><th>ارز</th><th>گیرنده</th><th>بانک</th><th>تاریخ</th><th>عملیات</th></tr></thead><tbody>';
            for (const w of result.data) {
                html += `<tr><td style="white-space: nowrap;">${escapeHtml(w.first_name || '')} ${escapeHtml(w.last_name || '')}<br><small>${escapeHtml(w.telegram_id || '')}</small></td><td style="color:#FFD700;">${Number(w.amount).toLocaleString()}</td><td>${w.currency}</td><td>${escapeHtml(w.recipient_name || '-')}</td><td>${escapeHtml(w.bank_name || '-')}</td><td><small>${new Date(w.admin_action_at).toLocaleDateString('fa-IR')}</small></td><td><button class="btn-action btn-upload" onclick="openUploadReceiptModalForWithdrawal(${w.id}, '${escapeHtml(w.first_name)} ${escapeHtml(w.last_name)}', ${w.amount}, '${w.currency}')"><i class="fas fa-upload"></i> آپلود فیش</button></td></tr>`;
            }
            html += '</tbody></tr></div>';
            container.innerHTML = html;
        } else {
            container.innerHTML = '<div class="empty-state">هیچ درخواست تایید شده‌ای در انتظار فیش وجود ندارد</div>';
        }
    } catch(e) { console.error(e); container.innerHTML = '<div class="empty-state">خطا در بارگذاری</div>'; }
}

async function loadAdminCompletedWithdrawals() {
    const container = document.getElementById('completedWithdrawalsList');
    if (!container) return;
    try {
        const response = await fetch('../api/withdraw.php?action=get_completed');
        const result = await response.json();
        if (result.success && result.data) {
            const completed = result.data.filter(w => w.status === 'completed');
            const rejected = result.data.filter(w => w.status === 'rejected');
            let totalAmount = 0;
            for (const w of result.data) totalAmount += Number(w.amount);
            document.getElementById('statCompletedCount').innerHTML = completed.length;
            document.getElementById('statRejectedCount').innerHTML = rejected.length;
            document.getElementById('statTotalAmount').innerHTML = totalAmount.toLocaleString('fa-IR');
            if (result.data.length === 0) { container.innerHTML = '<div class="empty-state">هیچ درخواستی وجود ندارد</div>'; return; }
            let html = '<div style="overflow-x:auto"><table class="withdrawal-table"><thead><tr><th>کاربر</th><th>مبلغ</th><th>ارز</th><th>گیرنده</th><th>وضعیت</th><th>تاریخ</th></tr></thead><tbody>';
            for (const w of result.data) {
                const statusColor = w.status === 'completed' ? '#4CD964' : '#FF3B30';
                const statusText = w.status === 'completed' ? 'تکمیل شده' : 'رد شده';
                html += `<tr><td style="white-space: nowrap;">${escapeHtml(w.first_name || '')} ${escapeHtml(w.last_name || '')}</td><td style="color:#FFD700;">${Number(w.amount).toLocaleString()}</td><td>${w.currency}</td><td>${escapeHtml(w.recipient_name || w.target_user_name || '-')}</td><td style="color:${statusColor}">${statusText}</td><td><small>${new Date(w.admin_action_at || w.created_at).toLocaleDateString('fa-IR')}</small></td></tr>`;
            }
            html += '</tbody></table></div>';
            container.innerHTML = html;
        } else { container.innerHTML = '<div class="empty-state">هیچ داده‌ای وجود ندارد</div>'; }
    } catch(e) { console.error(e); container.innerHTML = '<div class="empty-state">خطا در بارگذاری</div>'; }
}

async function approveWithdrawalReq(id) {
    if (!confirm('⚠️ آیا از تایید این درخواست اطمینان دارید؟')) return;
    try {
        const response = await fetch('../api/withdraw.php?action=approve', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ withdrawal_id: id }) });
        const result = await response.json();
        if (result.success) { showToast(result.message, 'success'); loadAdminPendingWithdrawals(); loadAdminApprovedWithdrawals(); loadAdminCompletedWithdrawals(); loadUserWithdrawals(); }
        else { showToast(result.message, 'error'); }
    } catch(e) { console.error(e); showToast('خطا در تایید', 'error'); }
}

async function rejectWithdrawalReq(id) {
    const reason = prompt('📝 لطفاً دلیل رد درخواست را وارد کنید:');
    if (!reason) return;
    try {
        const response = await fetch('../api/withdraw.php?action=reject', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ withdrawal_id: id, notes: reason }) });
        const result = await response.json();
        if (result.success) { showToast(result.message, 'success'); loadAdminPendingWithdrawals(); loadAdminCompletedWithdrawals(); loadUserWithdrawals(); }
        else { showToast(result.message, 'error'); }
    } catch(e) { console.error(e); showToast('خطا در رد', 'error'); }
}

function openUploadReceiptModalForWithdrawal(withdrawalId, userName, amount, currency) {
    const modal = document.getElementById('uploadReceiptModal');
    const infoDiv = document.getElementById('uploadReceiptInfo');
    const withdrawalIdInput = document.getElementById('uploadWithdrawalId');
    if (withdrawalIdInput) withdrawalIdInput.value = withdrawalId;
    if (infoDiv) infoDiv.innerHTML = `<strong>👤 کاربر:</strong> ${escapeHtml(userName)}<br><strong>💰 مبلغ:</strong> ${Number(amount).toLocaleString()} ${escapeHtml(currency)}<br><small>📌 لطفاً کارت مبدأ و فیش را انتخاب کنید.</small>`;
    if (modal) { modal.style.display = 'flex'; document.body.style.overflow = 'hidden'; }
}

async function uploadReceipt() {
    const withdrawalId = document.getElementById('uploadWithdrawalId').value;
    const cardId = document.getElementById('receiptCardId').value;
    const transactionId = document.getElementById('receiptTransactionId').value;
    const notes = document.getElementById('receiptNotes').value;
    const fileInput = document.getElementById('receiptFile');
    const file = fileInput.files[0];
    
    if (!withdrawalId) { showToast('شناسه درخواست نامعتبر', 'error'); return; }
    if (!cardId) { showToast('لطفاً کارت مبدأ را انتخاب کنید', 'error'); return; }
    
    const formData = new FormData();
    formData.append('withdrawal_id', withdrawalId);
    formData.append('card_id', cardId);
    formData.append('transaction_id', transactionId);
    formData.append('notes', notes);

    const btn = document.getElementById('uploadReceiptBtn');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> فشرده‌سازی...';

    // (اصلاح ۶) فشرده‌سازی سمت کلاینت قبل از آپلود
    if (file) {
        let outFile = file;
        if (typeof window.AvaCompressImage === 'function'){
            try { outFile = await window.AvaCompressImage(file); } catch(e){}
        }
        formData.append('receipt', outFile, outFile.name || 'receipt.jpg');
    }
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال آپلود...';

    try {
        const response = await fetch('../api/withdraw.php?action=upload_receipt', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        if (result.success) {
            showToast(result.message, 'success');
            closeUploadReceiptModal();
            loadAdminPendingWithdrawals();
            loadAdminApprovedWithdrawals();
            loadAdminCompletedWithdrawals();
            loadUserWithdrawals();
            setTimeout(() => location.reload(), 900);
        } else {
            showToast(result.message, 'error');
        }
    } catch(e) {
        console.error(e);
        showToast('خطا در آپلود فیش', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

// ==================== توابع دانلود ====================


// ==================== مقداردهی اولیه ====================
document.addEventListener('DOMContentLoaded', function() {
    initCurrencySelection();
    setupFilePreview();
    loadUserWithdrawals();

    // (آپدیت ۳) حالت «ثبت درخواست سریع»: مدال تسویه را بلافاصله باز کن
    try {
        var _p = new URLSearchParams(window.location.search);
        if (_p.get('new') === '1') {
            if (typeof openWithdrawalModal === 'function') openWithdrawalModal();
            else { var m = document.getElementById('withdrawalStepModal'); if (m){ m.classList.add('active'); m.style.display='flex'; } }
        }
    } catch(e){}
    
    const uploadBtn = document.getElementById('uploadReceiptBtn');
    if (uploadBtn) { 
        uploadBtn.addEventListener('click', function(e) { 
            e.preventDefault(); 
            uploadReceipt(); 
        }); 
    }
    
    const saveCardBtn = document.getElementById('saveCardBtn');
    if (saveCardBtn) { 
        saveCardBtn.addEventListener('click', function(e) { 
            e.preventDefault(); 
            saveCard(); 
        }); 
    }
    
    const quickWithdrawBtn = document.getElementById('submitQuickWithdrawalBtn');
    if (quickWithdrawBtn) {
        quickWithdrawBtn.addEventListener('click', function(e) {
            e.preventDefault();
            submitQuickWithdrawal();
        });
    }
    
    // اضافه کردن event listener برای تغییر ارز در مودال سریع
    const quickCurrencySelect = document.getElementById('quickCurrency');
    if (quickCurrencySelect) {
        quickCurrencySelect.addEventListener('change', function() {
            const amountInput = document.getElementById('quickAmount');
            const wordsDiv = document.getElementById('quickAmountWords');
            if (this.value === 'IRR' && amountInput && amountInput.value) {
                const rawValue = amountInput.value.replace(/[^0-9]/g, '');
                const number = parseInt(rawValue, 10) || 0;
                if (wordsDiv && number > 0) {
                    wordsDiv.innerHTML = '<i class="fas fa-font"></i> ' + convertToTomanWordsJS(number);
                    wordsDiv.style.display = 'block';
                }
            } else if (wordsDiv) {
                wordsDiv.style.display = 'none';
            }
        });
    }
    
    <?php if ($isAdmin): ?>
    loadCompanyCards();
    loadCardsStats();
    loadAdminPendingWithdrawals();
    loadAdminApprovedWithdrawals();
    loadAdminCompletedWithdrawals();
    setInterval(() => { 
        loadAdminPendingWithdrawals(); 
        loadAdminApprovedWithdrawals();
        loadAdminCompletedWithdrawals(); 
        loadCardsStats(); 
    }, 30000);
    <?php endif; ?>
    
    setInterval(() => { loadUserWithdrawals(); }, 30000);
});
</script>
<script>
/* ==== نمودارها برای صفحه تسویه حساب (متصل به جدول withdrawal_requests) ==== */
(function () {
  var _avTries = 0;
  function boot() {
    try {
    if ((!window.AvaPay || !window.__AV_WD__)) { if (_avTries++ < 50) return setTimeout(boot, 80); return; }
    var AV = window.AvaPay, d = window.__AV_WD__;
    var faNum = function (n) { return Number(n || 0).toLocaleString('en-US'); };
    var compact = function (n) {
      n = Number(n) || 0;
      if (n >= 1e9) return (n/1e9).toFixed(1).replace(/\.0$/,'')+'B';
      if (n >= 1e6) return (n/1e6).toFixed(1).replace(/\.0$/,'')+'M';
      if (n >= 1e3) return (n/1e3).toFixed(1).replace(/\.0$/,'')+'K';
      return faNum(n);
    };

    var chip = document.getElementById('avWdTotalChip');
    if (chip) chip.textContent = faNum(d.total) + ' درخواست';
    var sum = document.getElementById('avWdSumChip');
    if (sum) sum.textContent = 'تسویه‌شده ' + compact(d.totalCompleted);

    var donutHost = document.getElementById('avWdDonut');
    if (donutHost) {
      if ((d.total || 0) === 0) {
        donutHost.innerHTML = '<div style="color:rgba(255,255,255,.45);font-size:.8rem;text-align:center;padding:20px 0;">هنوز درخواست تسویه‌ای ثبت نشده</div>';
      } else {
        donutHost.appendChild(AV.donut({
          centerValue: d.total, centerLabel: 'کل',
          segments: [
            { label: 'در انتظار', value: (d.pending||0)+(d.approved||0), color: '#ffce54', display: faNum((d.pending||0)+(d.approved||0)) },
            { label: 'تسویه‌شده', value: d.completed, color: '#35d07f', display: faNum(d.completed) },
            { label: 'رد‌شده', value: d.rejected, color: '#ff5a6e', display: faNum(d.rejected) }
          ]
        }));
      }
    }

    var sparkHost = document.getElementById('avWdSpark');
    if (sparkHost) {
      var months = ['۵ ماه', '۴', '۳', '۲', 'ماه قبل', 'این ماه'];
      var data = (d.monthly || []).map(function (v, i) { return { v: v, label: months[i] || '' }; });
      if (!data.length) data = months.map(function (m) { return { v: 0, label: m }; });
      sparkHost.appendChild(AV.spark(data, {}));
    }
    } catch (e) { if (window.console) console.warn("AvaPay charts:", e); }
  }
  boot();
})();
</script>

</body>
</html>