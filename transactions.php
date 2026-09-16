<?php
require_once __DIR__ . '/includes/camera_headers.php';
require_once __DIR__ . '/includes/session_boot.php';
require_once __DIR__ . '/includes/logo_helper.php';
$appLogo = getAppLogo();
require_once 'config/database.php';

// بازسازی سشن از روی توکن ۳۰ روزه
avapay_restore_session_from_token($conn);

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$userId = $_SESSION['user_id'];

// Get user full data for PDF export
$userSql = "SELECT first_name, last_name, avatar, account_number, iban_number, phone_number, created_at FROM users WHERE id = ?";
$userStmt = $conn->prepare($userSql);
$userStmt->bind_param("i", $userId);
$userStmt->execute();
$userResult = $userStmt->get_result();
$user = $userResult->fetch_assoc();

// Get pagination parameters
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Get filter parameters
$filterType = isset($_GET['type']) ? $_GET['type'] : 'all';
$filterDate = isset($_GET['date']) ? $_GET['date'] : '';
$filterStatus = isset($_GET['status']) ? $_GET['status'] : '';
$filterMonth = isset($_GET['month']) ? $_GET['month'] : '';
$filterYear = isset($_GET['year']) ? $_GET['year'] : '';
$filterCurrency = isset($_GET['currency']) ? $_GET['currency'] : 'all';

// Build SQL query with filters
$sql = "SELECT 
    t.*,
    s.first_name as sender_name,
    s.last_name as sender_last_name,
    s.avatar as sender_avatar,
    r.first_name as receiver_name,
    r.last_name as receiver_last_name,
    r.avatar as receiver_avatar
FROM transactions t
LEFT JOIN users s ON t.sender_id = s.id
LEFT JOIN users r ON t.receiver_id = r.id
WHERE (t.sender_id = ? OR t.receiver_id = ?)";

$params = [$userId, $userId];
$paramTypes = "ii";

// Apply type filter
if ($filterType !== 'all') {
    switch ($filterType) {
        case 'sent':
            $sql .= " AND t.sender_id = ?";
            $params[] = $userId;
            $paramTypes .= "i";
            break;
        case 'received':
            $sql .= " AND t.receiver_id = ?";
            $params[] = $userId;
            $paramTypes .= "i";
            break;
        case 'deposit':
            $sql .= " AND t.type = 'deposit'";
            break;
        case 'withdrawal':
            $sql .= " AND t.type = 'withdrawal'";
            break;
    }
}

// Apply month/year filter
if ($filterMonth && $filterYear) {
    $sql .= " AND MONTH(t.created_at) = ? AND YEAR(t.created_at) = ?";
    $params[] = $filterMonth;
    $params[] = $filterYear;
    $paramTypes .= "ii";
} elseif ($filterMonth) {
    $sql .= " AND MONTH(t.created_at) = ?";
    $params[] = $filterMonth;
    $paramTypes .= "i";
} elseif ($filterYear) {
    $sql .= " AND YEAR(t.created_at) = ?";
    $params[] = $filterYear;
    $paramTypes .= "i";
}

// Apply currency filter
if ($filterCurrency && $filterCurrency !== 'all') {
    $sql .= " AND t.currency = ?";
    $params[] = $filterCurrency;
    $paramTypes .= "s";
}

// Apply date filter
if ($filterDate) {
    $sql .= " AND DATE(t.created_at) = ?";
    $params[] = $filterDate;
    $paramTypes .= "s";
}

// Apply status filter
if ($filterStatus && $filterStatus !== 'all') {
    $sql .= " AND t.status = ?";
    $params[] = $filterStatus;
    $paramTypes .= "s";
}

$sql .= " ORDER BY t.created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$paramTypes .= "ii";

// Prepare and execute query
$stmt = $conn->prepare($sql);
$stmt->bind_param($paramTypes, ...$params);
$stmt->execute();
$transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ---------------------------------------------------------------
// دیتای کامل (بدون صفحه‌بندی) برای گزارش/صورت‌حساب PDF: قبلاً PDF فقط
// همان ۲۰ ردیف صفحه‌ی جاری را نشان می‌داد که برای یک "صورت‌حساب" واقعی
// ناقص بود. این‌جا همان فیلترهای فعال (نوع/ماه/سال/ارز/تاریخ/وضعیت) را
// دوباره اعمال می‌کنیم ولی بدون LIMIT صفحه، با یک سقف امن (۵۰۰ ردیف) تا
// خروجی خیلی سنگین نشود.
$pdfSql = str_replace(" ORDER BY t.created_at DESC LIMIT ? OFFSET ?", " ORDER BY t.created_at DESC LIMIT 500", $sql);
$pdfParams = array_slice($params, 0, -2);
$pdfParamTypes = substr($paramTypes, 0, -2);
$pdfStmt = $conn->prepare($pdfSql);
if (count($pdfParams) > 0) {
    $pdfStmt->bind_param($pdfParamTypes, ...$pdfParams);
}
$pdfStmt->execute();
$pdfTransactions = $pdfStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get total count with same filters
$countSql = "SELECT COUNT(*) as total FROM transactions t WHERE (t.sender_id = ? OR t.receiver_id = ?)";
$countParams = [$userId, $userId];
$countParamTypes = "ii";

if ($filterType !== 'all') {
    switch ($filterType) {
        case 'sent':
            $countSql .= " AND t.sender_id = ?";
            $countParams[] = $userId;
            $countParamTypes .= "i";
            break;
        case 'received':
            $countSql .= " AND t.receiver_id = ?";
            $countParams[] = $userId;
            $countParamTypes .= "i";
            break;
        case 'deposit':
            $countSql .= " AND t.type = 'deposit'";
            break;
        case 'withdrawal':
            $countSql .= " AND t.type = 'withdrawal'";
            break;
    }
}

if ($filterMonth && $filterYear) {
    $countSql .= " AND MONTH(t.created_at) = ? AND YEAR(t.created_at) = ?";
    $countParams[] = $filterMonth;
    $countParams[] = $filterYear;
    $countParamTypes .= "ii";
} elseif ($filterMonth) {
    $countSql .= " AND MONTH(t.created_at) = ?";
    $countParams[] = $filterMonth;
    $countParamTypes .= "i";
} elseif ($filterYear) {
    $countSql .= " AND YEAR(t.created_at) = ?";
    $countParams[] = $filterYear;
    $countParamTypes .= "i";
}

if ($filterCurrency && $filterCurrency !== 'all') {
    $countSql .= " AND t.currency = ?";
    $countParams[] = $filterCurrency;
    $countParamTypes .= "s";
}

if ($filterDate) {
    $countSql .= " AND DATE(t.created_at) = ?";
    $countParams[] = $filterDate;
    $countParamTypes .= "s";
}

if ($filterStatus && $filterStatus !== 'all') {
    $countSql .= " AND t.status = ?";
    $countParams[] = $filterStatus;
    $countParamTypes .= "s";
}

$countStmt = $conn->prepare($countSql);
if ($countParams) {
    $countStmt->bind_param($countParamTypes, ...$countParams);
}
$countStmt->execute();
$countResult = $countStmt->get_result();
$total = $countResult->fetch_assoc()['total'];
$totalPages = ceil($total / $limit);

// Calculate actual totals for financial summary
$summarySql = "SELECT 
    SUM(CASE WHEN sender_id = ? AND status = 'completed' THEN amount ELSE 0 END) as total_sent,
    SUM(CASE WHEN receiver_id = ? AND status = 'completed' THEN amount ELSE 0 END) as total_received,
    SUM(CASE WHEN type = 'deposit' AND status = 'completed' THEN amount ELSE 0 END) as total_deposits,
    SUM(CASE WHEN type = 'withdrawal' AND status = 'completed' THEN amount ELSE 0 END) as total_withdrawals
FROM transactions 
WHERE (sender_id = ? OR receiver_id = ?)";

$summaryStmt = $conn->prepare($summarySql);
$summaryStmt->bind_param("iiii", $userId, $userId, $userId, $userId);
$summaryStmt->execute();
$summaryResult = $summaryStmt->get_result();
$summary = $summaryResult->fetch_assoc();

// Calculate net flow
$totalSent = $summary['total_sent'] ?? 0;
$totalReceived = $summary['total_received'] ?? 0;
$netFlow = $totalReceived - $totalSent;

// Functions
function getInitials($firstName, $lastName) {
    return strtoupper(substr($firstName, 0, 1) . substr($lastName, 0, 1));
}

function formatDate($dateString) {
    $date = new DateTime($dateString);
    $now = new DateTime();
    $interval = $now->diff($date);
    
    if ($interval->days === 0) {
        return 'Today, ' . $date->format('H:i');
    } elseif ($interval->days === 1) {
        return 'Yesterday, ' . $date->format('H:i');
    } elseif ($interval->days < 7) {
        return $date->format('D, H:i');
    } else {
        return $date->format('M d, Y H:i');
    }
}

function getStatusColor($status) {
    switch ($status) {
        case 'completed': return 'rgba(76, 217, 100, 0.2)';
        case 'pending': return 'rgba(255, 193, 7, 0.2)';
        case 'failed': return 'rgba(255, 59, 48, 0.2)';
        case 'cancelled': return 'rgba(142, 142, 147, 0.2)';
        default: return 'rgba(255, 255, 255, 0.05)';
    }
}

function formatCurrency($amount, $currency) {
    $symbols = [
        'USD' => '$',
        'EUR' => '€',
        'USDT' => '',
        'IRR' => '﷼ '
    ];
    
    $symbol = $symbols[$currency] ?? '';
    $absAmount = abs($amount);
    $prefix = $amount >= 0 ? '+' : '-';
    
    if ($currency === 'IRR') {
        if ($absAmount >= 1000000000) {
            $formatted = number_format($absAmount / 1000000000, 1) . 'B';
        } elseif ($absAmount >= 1000000) {
            $formatted = number_format($absAmount / 1000000, 1) . 'M';
        } elseif ($absAmount >= 1000) {
            $formatted = number_format($absAmount / 1000, 1) . 'K';
        } else {
            $formatted = number_format($absAmount, 0);
        }
    } else {
        if ($absAmount >= 1000000000) {
            $formatted = number_format($absAmount / 1000000000, 2) . 'B';
        } elseif ($absAmount >= 1000000) {
            $formatted = number_format($absAmount / 1000000, 2) . 'M';
        } elseif ($absAmount >= 1000) {
            $formatted = number_format($absAmount / 1000, 1) . 'K';
        } else {
            $formatted = number_format($absAmount, $currency === 'USDT' ? 2 : 0);
        }
    }
    
    if ($currency === 'USDT') {
        return $prefix . ' ' . $formatted . ' USDT';
    }
    
    return $prefix . ' ' . $symbol . $formatted;
}

// ============================================================
// (آپدیت ۴) داده‌ی نمودار به تفکیک ارز: دلار / یورو / تتر / تومان
// ۱۲ ماه اخیر — مجموع دریافتی و ارسالی هر ماه
// ============================================================
$chartCurs   = ['USD', 'EUR', 'USDT', 'IRR'];
$chartMonths = [];
$chartLabels = [];
for ($i = 11; $i >= 0; $i--) {
    $ts = strtotime(date('Y-m-01') . " -$i month");
    $chartMonths[] = date('Y-m', $ts);
    $chartLabels[] = date('M y', $ts);
}
$monthIdx = array_flip($chartMonths);

$chartData = [];
foreach ($chartCurs as $c) {
    $chartData[$c] = [
        'in'    => array_fill(0, 12, 0.0),
        'out'   => array_fill(0, 12, 0.0),
        'net'   => array_fill(0, 12, 0.0),
        'tin'   => 0.0,
        'tout'  => 0.0,
        'count' => 0,
    ];
}

$chartSql = "SELECT currency, DATE_FORMAT(created_at, '%Y-%m') AS ym, sender_id, receiver_id, amount, type
             FROM transactions
             WHERE (sender_id = ? OR receiver_id = ?) AND status = 'completed'
               AND created_at >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 11 MONTH)";
$chartStmt = $conn->prepare($chartSql);
if ($chartStmt) {
    $chartStmt->bind_param("ii", $userId, $userId);
    $chartStmt->execute();
    $chartRes = $chartStmt->get_result();
    while ($row = $chartRes->fetch_assoc()) {
        $cur = strtoupper($row['currency'] ?? '');
        if (!isset($chartData[$cur])) continue;
        if (!isset($monthIdx[$row['ym']])) continue;
        $k   = $monthIdx[$row['ym']];
        $amt = (float)$row['amount'];
        $isIn = ((int)$row['receiver_id'] === (int)$userId) || $row['type'] === 'deposit';
        if ($row['type'] === 'withdrawal') $isIn = false;
        if ($isIn) { $chartData[$cur]['in'][$k]  += $amt; $chartData[$cur]['tin']  += $amt; }
        else       { $chartData[$cur]['out'][$k] += $amt; $chartData[$cur]['tout'] += $amt; }
        $chartData[$cur]['count']++;
    }
}
foreach ($chartCurs as $c) {
    for ($k = 0; $k < 12; $k++) {
        $chartData[$c]['net'][$k] = round($chartData[$c]['in'][$k] - $chartData[$c]['out'][$k], 4);
        $chartData[$c]['in'][$k]  = round($chartData[$c]['in'][$k], 4);
        $chartData[$c]['out'][$k] = round($chartData[$c]['out'][$k], 4);
    }
}
$chartMeta = [
    'USD'  => ['label' => 'US Dollar',  'fa' => 'دلار', 'sym' => '$',    'color' => '#22C55E', 'icon' => 'fas fa-dollar-sign', 'dec' => 2],
    'EUR'  => ['label' => 'Euro',       'fa' => 'یورو', 'sym' => '€',    'color' => '#38BDF8', 'icon' => 'fas fa-euro-sign',   'dec' => 2],
    'USDT' => ['label' => 'Tether',     'fa' => 'تتر',  'sym' => '₮',    'color' => '#26A17B', 'icon' => 'fas fa-coins',       'dec' => 2],
    'IRR'  => ['label' => 'Toman',      'fa' => 'تومان','sym' => 'T',    'color' => '#A855F7', 'icon' => 'fas fa-money-bill-wave', 'dec' => 0],
];

// Get current date for PDF
$currentDate = date('F d, Y');
$currentTime = date('H:i:s');

// Count active filters
$activeFilterCount = 0;
if ($filterType !== 'all') $activeFilterCount++;
if ($filterDate) $activeFilterCount++;
if ($filterStatus && $filterStatus !== 'all') $activeFilterCount++;
if ($filterMonth) $activeFilterCount++;
if ($filterYear) $activeFilterCount++;
if ($filterCurrency && $filterCurrency !== 'all') $activeFilterCount++;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="dns-prefetch" href="https://cdnjs.cloudflare.com">
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="dns-prefetch" href="https://cdn.jsdelivr.net">
<script>(function(){try{var t=localStorage.getItem("ava_theme")||"dark";document.documentElement.setAttribute("data-theme",t);}catch(e){}})();</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Aradexch NeoBank - Transactions</title>
    <link rel="stylesheet" href="/ledor/assets/css/theme-light.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/theme-light.css') ?: time(); ?>">
    <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
    <link rel="preload" href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css"></noscript>
    <link rel="stylesheet" href="assets/css/dashboard-ava.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/dashboard-ava.css') ?: time(); ?>">
    <meta name="theme-color" content="#1A0B2E">
    <meta name="color-scheme" content="dark">
    <link rel="manifest" href="manifest.php">
    <?php $__appIcon = ($appLogo ? htmlspecialchars($appLogo['url']) : '/ledor/AVAPAY.PNG?v=' . (@filemtime(__DIR__ . '/AVAPAY.PNG') ?: time())); ?>
    <link rel="icon" type="image/png" href="<?php echo $__appIcon; ?>">
    <link rel="apple-touch-icon" href="<?php echo $__appIcon; ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js" defer></script>
    <style>
        /* ============================================
           هماهنگ‌سازی با تم داشبورد (Ava Pay)
        ============================================ */
        :root{
            --ava-bg:#0A0520; --ava-bg2:#0E0828; --ava-card:rgba(255,255,255,.04);
            --ava-card2:rgba(255,255,255,.04); --ava-line:rgba(255,255,255,.08);
            --ava-txt:#fff; --ava-mut:rgba(255,255,255,.5);
            --ava-pur:#7C3AED; --ava-pur2:#A855F7;
        }
        html[data-theme="light"]{ --ava-bg:#F4F0FA; --ava-txt:#1a0b2e; }
        /* ============================================
           RESET & BASE STYLES
        ============================================ */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        html {
            background: transparent;
        }

        /* Body — هماهنگ با داشبورد */
       body {
    background: var(--ava-bg);
    min-height: 100vh;
    font-family: 'Vazirmatn', -apple-system, 'Segoe UI', 'Tahoma', system-ui, sans-serif;
    color: var(--ava-txt);
    padding: 12px;
    padding-bottom: 85px;
    margin: 0;
    overflow-x: hidden;
    -webkit-overflow-scrolling: touch;
    color: var(--ava-txt);
}

/* داخل iframeِ مدال (arad.php embed=1) اسکرول کل صفحه باید روی خودِ سند iframe
   انجام شود؛ اگر ارتفاع body کمتر از محتوای واقعی محاسبه شود (چون هدر/فوتر
   مخفی‌شده‌ی بیرونی حساب نشده)، اسکرول می‌تواند نصفه/گیر بیفتد. اطمینان از
   این‌که body هرگز از محتوای واقعی‌اش کوتاه‌تر نمی‌ماند: */
html { height: auto; }
body { height: auto; }

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

        /* Dashboard Container */
        .dashboard {
            max-width: 600px;
            margin: 0 auto;
            padding: 0 16px 90px 16px;
            position: relative;
            z-index: 1;
        }

        /* ============================================
           HEADER STYLES - بدون قسمت سفید
        ============================================ */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-top: max(55px, env(safe-area-inset-top, 25px));
            background: transparent;
            position: relative;
            z-index: 10;
        }

        .header,
        .header * {
            background: transparent;
        }

        .greeting h1 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 6px;
            color: #ffffff;
            letter-spacing: -0.3px;
        }

        .greeting p {
            color: rgba(255, 255, 255, 0.7);
            font-size: 14px;
            font-weight: 400;
        }

        /* Back Button - با فاصله مناسب از بالا */
        .header a {
            color: #ffffff;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 44px;
            height: 44px;
            min-width: 44px;
            min-height: 44px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(10px);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .header a:active {
            background: rgba(255, 255, 255, 0.25);
            transform: scale(0.94);
        }

        .header a i {
            font-size: 1.25rem;
        }

        .header > div:last-child {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        /* Filter Button */
        .filter-btn {
            background: rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: #ffffff;
            padding: 10px 18px;
            border-radius: 30px;
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .filter-btn:active {
            transform: scale(0.96);
        }

        .filter-badge {
            background: #6C40C5;
            color: white;
            font-size: 0.7rem;
            font-weight: bold;
            padding: 2px 8px;
            border-radius: 20px;
            margin-left: 5px;
        }

        /* ============================================
           FINANCIAL SUMMARY CARDS
        ============================================ */
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }

        .summary-card {
            background: rgba(255, 255, 255, 0.06);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 20px;
            padding: 16px;
            transition: all 0.3s ease;
        }

        .summary-card:active {
            transform: scale(0.98);
        }

        .summary-card .label {
            color: rgba(255, 255, 255, 0.6);
            font-size: 12px;
            margin-bottom: 8px;
            letter-spacing: 0.5px;
        }

        .summary-card .value {
            font-size: 20px;
            font-weight: 700;
        }

        .summary-card.sent .value { color: #FF5E5E; }
        .summary-card.received .value { color: #4CD964; }
        .summary-card.net .value { color: #6C40C5; }

        /* ============================================
           TRANSACTIONS SECTION
        ============================================ */
        .transactions-section {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 24px;
            padding: 20px;
            margin-top: 20px;
        }

        .section-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .section-title h2 {
            font-size: 18px;
            font-weight: 600;
            color: #ffffff;
        }

        .transactions-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .transaction-item {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 18px;
            padding: 16px;
            transition: all 0.3s ease;
        }

        .transaction-item:active {
            background: rgba(255, 255, 255, 0.08);
            transform: scale(0.99);
        }

        .transaction-avatar {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            background: linear-gradient(135deg, #6C40C5, #E1306C);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 18px;
            color: white;
            overflow: hidden;
            flex-shrink: 0;
        }

        .transaction-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .transaction-name {
            font-weight: 600;
            font-size: 16px;
            color: #ffffff;
        }

        .transaction-amount {
            font-weight: 700;
            font-size: 16px;
        }

        .amount-positive {
            color: #4CD964 !important;
        }

        .amount-negative {
            color: #FF5E5E !important;
        }

        .transaction-description {
            color: rgba(255, 255, 255, 0.5);
            font-size: 13px;
        }

        .transaction-date {
            color: rgba(255, 255, 255, 0.4);
            font-size: 11px;
        }

        .type-sent, .type-received, .type-deposit, .type-withdrawal {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
        }

        .type-sent {
            background: rgba(255, 94, 94, 0.15);
            color: #FF5E5E;
        }

        .type-received {
            background: rgba(76, 217, 100, 0.15);
            color: #4CD964;
        }

        .type-deposit {
            background: rgba(108, 64, 197, 0.15);
            color: #6C40C5;
        }

        .type-withdrawal {
            background: rgba(255, 193, 7, 0.15);
            color: #FFC107;
        }

        /* ============================================
           MODAL STYLES
        ============================================ */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(12px);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }

        .modal-container {
            background: linear-gradient(135deg, rgba(10, 10, 31, 0.95), rgba(26, 26, 46, 0.95));
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 28px;
            width: 100%;
            max-width: 500px;
            max-height: 85vh;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            overscroll-behavior: contain;
            animation: modalSlideIn 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(30px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            background: inherit;
            backdrop-filter: blur(20px);
            z-index: 1;
        }

        .modal-header h3 {
            margin: 0;
            color: #ffffff;
            font-size: 1.2rem;
            font-weight: 600;
        }

        .modal-header h3 i {
            color: #6C40C5;
            margin-right: 8px;
        }

        .modal-close {
            background: rgba(255, 255, 255, 0.08);
            border: none;
            color: rgba(255, 255, 255, 0.7);
            font-size: 1.2rem;
            cursor: pointer;
            padding: 8px;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .modal-close:active {
            background: rgba(255, 255, 255, 0.15);
            transform: scale(0.95);
        }

        .modal-body {
            padding: 24px;
        }

        .modal-footer {
            padding: 20px 24px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 16px;
        }

        .filter-box {
            margin-bottom: 16px;
        }

        .filter-box.full-width {
            grid-column: span 3;
        }

        .filter-label {
            display: block;
            margin-bottom: 8px;
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.85rem;
            font-weight: 500;
        }

        .filter-label i {
            margin-right: 5px;
            color: #6C40C5;
        }

        .filter-input-group {
            position: relative;
        }

        .filter-select {
            width: 100%;
            padding: 14px 12px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 14px;
            color: white;
            font-size: 0.95rem;
            appearance: none;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .filter-select:focus {
            outline: none;
            border-color: #6C40C5;
            box-shadow: 0 0 0 3px rgba(108, 64, 197, 0.2);
        }

        .filter-select option {
            background: #1a1a2e;
            color: white;
        }

        .filter-input-group::after {
            content: '\f078';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(255, 255, 255, 0.5);
            pointer-events: none;
            font-size: 12px;
        }

        .btn-primary, .btn-secondary {
            padding: 12px 24px;
            border-radius: 30px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #6C40C5, #8B5CF6);
            color: white;
        }

        .btn-primary:active {
            transform: scale(0.97);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #ffffff;
        }

        .btn-secondary:active {
            background: rgba(255, 255, 255, 0.15);
            transform: scale(0.97);
        }

        /* ============================================
           ACTIVE FILTERS & PAGINATION
        ============================================ */
        .active-filters {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin: 15px 0;
        }

        .active-filter-tag {
            background: rgba(108, 64, 197, 0.2);
            border: 1px solid rgba(108, 64, 197, 0.4);
            color: #6C40C5;
            padding: 6px 14px;
            border-radius: 30px;
            font-size: 0.85rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            backdrop-filter: blur(5px);
        }

        .remove-filter {
            background: none;
            border: none;
            color: #6C40C5;
            cursor: pointer;
            padding: 0;
            font-size: 1rem;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 20px;
            margin-top: 30px;
        }

        .pagination-btn {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #ffffff;
            padding: 10px 20px;
            border-radius: 30px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .pagination-btn:active {
            background: #6C40C5;
            transform: scale(0.96);
        }

        .pagination-info {
            color: rgba(255, 255, 255, 0.6);
            font-size: 14px;
        }

        /* ============================================
           LOADING & TOAST
        ============================================ */
        .filter-loading {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(8px);
            z-index: 9998;
            justify-content: center;
            align-items: center;
        }

        .filter-loading-spinner {
            width: 44px;
            height: 44px;
            border: 3px solid rgba(255, 255, 255, 0.2);
            border-top: 3px solid #6C40C5;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .no-results {
            text-align: center;
            padding: 60px 20px;
            color: rgba(255, 255, 255, 0.5);
        }

        .no-results i {
            font-size: 3.5rem;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        .toast {
            position: fixed;
            bottom: 100px;
            left: 50%;
            transform: translateX(-50%) translateY(100px);
            background: rgba(0, 0, 0, 0.9);
            backdrop-filter: blur(20px);
            color: white;
            padding: 12px 24px;
            border-radius: 40px;
            font-size: 0.9rem;
            font-weight: 500;
            z-index: 9999;
            opacity: 0;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.1);
            white-space: nowrap;
        }

        .toast.show {
            transform: translateX(-50%) translateY(0);
            opacity: 1;
        }

        .toast.success {
            background: #4CD964;
            color: #000000;
        }

        .toast.error {
            background: #FF5E5E;
            color: #ffffff;
        }

        .toast.info {
            background: #6C40C5;
            color: #ffffff;
        }

        .export-loading {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .export-loading .spinner {
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top: 2px solid white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        /* Hidden PDF Template */
        #pdfTemplate {
            display: none;
            position: absolute;
            left: -9999px;
            top: -9999px;
            width: 210mm;
            min-height: 297mm;
            background: white;
            color: #000;
            padding: 20mm;
            font-family: 'Arial', 'Helvetica', sans-serif;
            box-sizing: border-box;
        }

        /* ============================================
           BOTTOM NAVIGATION
        ============================================ */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(10, 10, 31, 0.95);
            backdrop-filter: blur(20px);
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            justify-content: space-around;
            padding: 10px 16px 20px;
            z-index: 100;
            max-width: 600px;
            margin: 0 auto;
        }

        .nav-item {
            background: none;
            border: none;
            color: rgba(255, 255, 255, 0.5);
            font-size: 11px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 5px;
            cursor: pointer;
            transition: all 0.2s ease;
            padding: 8px 16px;
            border-radius: 30px;
        }

        .nav-item i {
            font-size: 22px;
        }

        .nav-item.active {
            color: #6C40C5;
            background: rgba(108, 64, 197, 0.15);
        }

        .nav-item:active {
            transform: scale(0.95);
        }

        /* ============================================
           RESPONSIVE DESIGN
        ============================================ */
        @media (max-width: 600px) {
            .dashboard {
                padding: 0 14px 85px 14px;
            }
            
            .greeting h1 {
                font-size: 24px;
            }
            
            .greeting p {
                font-size: 12px;
            }
            
            .header a {
                width: 40px;
                height: 40px;
                min-width: 40px;
                min-height: 40px;
            }
            
            .header a i {
                font-size: 1.1rem;
            }
            
            .filter-btn {
                padding: 8px 14px;
                font-size: 0.85rem;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
                gap: 8px;
            }
            
            .filter-box.full-width {
                grid-column: span 1;
            }
            
            .modal-footer {
                flex-direction: column;
            }
            
            .modal-footer button {
                width: 100%;
                justify-content: center;
            }
            
            .summary-card .value {
                font-size: 18px;
            }
            
            .transaction-avatar {
                width: 46px;
                height: 46px;
                font-size: 16px;
            }
            
            .toast {
                white-space: normal;
                text-align: center;
                max-width: 80%;
                padding: 10px 20px;
                font-size: 0.85rem;
            }
            
            .section-title {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        @supports (padding-top: env(safe-area-inset-top)) {
            .header {
                padding-top: max(55px, env(safe-area-inset-top));
            }
            .bottom-nav {
                padding-bottom: max(20px, env(safe-area-inset-bottom));
            }
            body {
                padding-bottom: max(85px, env(safe-area-inset-bottom));
            }
        }

        @media (max-width: 380px) {
            .dashboard {
                padding: 0 12px 80px 12px;
            }
            .greeting h1 {
                font-size: 22px;
            }
            .summary-cards {
                gap: 8px;
            }
            .summary-card {
                padding: 12px;
            }
            .summary-card .value {
                font-size: 16px;
            }
        }

        /* Scrollbar */
        ::-webkit-scrollbar {
            width: 4px;
        }
        ::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb {
            background: rgba(108, 64, 197, 0.5);
            border-radius: 10px;
        }
        
        body {
            overscroll-behavior: none;
        }
        
        ::selection {
            background: rgba(108, 64, 197, 0.5);
            color: white;
        }

        /* ================================================================
   AVA SKIN v3 — هماهنگ با زبان طراحی داشبورد AvaPay
   (لایه‌ی رویی؛ استایل‌های قدیمی را بازنویسی می‌کند)
   ================================================================ */
body{
    background:
        radial-gradient(120% 120% at 50% 0%, #241556 0%, #150C36 45%, #0C0620 100%) fixed !important;
}
/* فاصله از هدر اپ: کل صفحه پایین‌تر شروع شود */
.dashboard{max-width:520px;margin:0 auto;padding:26px 14px 110px !important;}
.dashboard > .header{margin-top:14px !important;}

/* هدر صفحه: کارت شیشه‌ای */
.header{
    display:flex;align-items:center;justify-content:space-between;gap:10px;
    background:linear-gradient(160deg,rgba(255,255,255,.12),rgba(255,255,255,.04)) !important;
    border:1px solid rgba(255,255,255,.16) !important;border-radius:22px !important;
    padding:16px 18px !important;margin-bottom:16px !important;
    backdrop-filter:blur(20px) saturate(150%);-webkit-backdrop-filter:blur(20px) saturate(150%);
    box-shadow:0 14px 36px rgba(0,0,0,.4),inset 0 1px 0 rgba(255,255,255,.22) !important;
    animation:txIn .5s cubic-bezier(.22,1.2,.36,1) both;
}
.greeting h1{
    font-size:1.12rem !important;font-weight:900 !important;color:#fff !important;letter-spacing:.01em;
    background:linear-gradient(90deg,#fff,#C4B5FD);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;
}
.greeting p{font-size:.68rem !important;color:rgba(255,255,255,.5) !important;}
.filter-btn{
    background:linear-gradient(135deg,#7C3AED,#A855F7) !important;border:none !important;color:#fff !important;
    border-radius:16px !important;padding:10px 16px !important;font-weight:800 !important;font-size:.74rem !important;
    box-shadow:0 8px 20px rgba(124,58,237,.4) !important;transition:.2s !important;
}
.filter-btn:active{transform:scale(.95);}
.filter-badge{background:#FF4D8D !important;color:#fff !important;}

/* کارت‌های خلاصه */
.summary-cards{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px;}
.summary-card{
    background:linear-gradient(160deg,rgba(255,255,255,.10),rgba(255,255,255,.03)) !important;
    border:1px solid rgba(255,255,255,.14) !important;border-radius:18px !important;padding:13px 10px !important;
    backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);
    box-shadow:0 10px 26px rgba(0,0,0,.35),inset 0 1px 0 rgba(255,255,255,.18) !important;
    animation:txIn .55s cubic-bezier(.22,1.2,.36,1) both;
}
.summary-card:nth-child(2){animation-delay:.06s}
.summary-card:nth-child(3){animation-delay:.12s}
.summary-card.sent    {border-top:2px solid rgba(255,77,141,.65) !important;}
.summary-card.received{border-top:2px solid rgba(34,197,94,.65) !important;}
.summary-card.net     {border-top:2px solid rgba(168,85,247,.65) !important;}
.summary-card .label{font-size:.62rem !important;color:rgba(255,255,255,.55) !important;font-weight:700;}
.summary-card .value{font-size:.86rem !important;font-weight:900 !important;color:#fff !important;}

/* لیست تراکنش‌ها */
.transactions-list{background:transparent !important;border:none !important;box-shadow:none !important;padding:0 !important;}
.transaction-item{
    background:linear-gradient(160deg,rgba(255,255,255,.10),rgba(255,255,255,.03)) !important;
    border:1px solid rgba(255,255,255,.13) !important;border-radius:18px !important;
    padding:13px 14px !important;margin-bottom:10px !important;
    backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);
    box-shadow:0 8px 22px rgba(0,0,0,.3) !important;
    transition:transform .2s, border-color .2s !important;
    animation:txIn .5s cubic-bezier(.22,1.2,.36,1) both;
}
.transaction-item:nth-child(1){animation-delay:.02s}.transaction-item:nth-child(2){animation-delay:.06s}
.transaction-item:nth-child(3){animation-delay:.10s}.transaction-item:nth-child(4){animation-delay:.14s}
.transaction-item:nth-child(5){animation-delay:.18s}.transaction-item:nth-child(6){animation-delay:.22s}
.transaction-item:active{transform:scale(.98);}
.transaction-item:hover{border-color:rgba(168,85,247,.45) !important;}
@keyframes txIn{0%{opacity:0;transform:translateY(16px)}100%{opacity:1;transform:none}}

/* مودال فیلتر */
.modal-overlay{background:rgba(10,6,28,.72) !important;backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);}
.modal-container{
    background:linear-gradient(165deg,#241556,#140C33) !important;
    border:1px solid rgba(255,255,255,.16) !important;border-radius:24px !important;
    box-shadow:0 24px 60px rgba(0,0,0,.6) !important;color:#fff;
}
.modal-container input,.modal-container select{
    background:rgba(255,255,255,.06) !important;border:1px solid rgba(255,255,255,.15) !important;
    color:#fff !important;border-radius:12px !important;
}
.btn-primary{
    background:linear-gradient(135deg,#7C3AED,#A855F7) !important;border:none !important;
    border-radius:14px !important;font-weight:900 !important;color:#fff !important;
    box-shadow:0 8px 20px rgba(124,58,237,.4) !important;
}
.btn-secondary{background:rgba(255,255,255,.08) !important;border:1px solid rgba(255,255,255,.16) !important;color:#fff !important;border-radius:14px !important;}

/* صفحه‌بندی و حالت خالی */
.pagination-btn{
    background:rgba(255,255,255,.07) !important;border:1px solid rgba(255,255,255,.14) !important;
    color:#fff !important;border-radius:12px !important;font-weight:800 !important;
}
.pagination-btn.active,.pagination-btn:hover{background:linear-gradient(135deg,#7C3AED,#A855F7) !important;border-color:transparent !important;}
.no-results{color:rgba(255,255,255,.55) !important;background:rgba(255,255,255,.04) !important;border:1px dashed rgba(255,255,255,.16) !important;border-radius:18px !important;padding:26px !important;}

        /* ==========================================================
           (آپدیت ۴) طراحی رسپانسیو موبایل + دسکتاپ و کارت نمودار ارزها
           هم‌خوان با تم روز/شب
        ========================================================== */
        :root{
            --tx-card: rgba(255,255,255,0.06);
            --tx-card2: rgba(255,255,255,0.04);
            --tx-line: rgba(255,255,255,0.09);
            --tx-txt: #FFFFFF;
            --tx-mut: rgba(255,255,255,0.55);
        }
        html[data-theme="light"]{
            --tx-card: #FFFFFF;
            --tx-card2: #FAF9FF;
            --tx-line: rgba(16,8,40,0.10);
            --tx-txt: #180F35;
            --tx-mut: #6B6390;
        }

        /* ---- کارت نمودار ---- */
        .tx-chart-card{
            background: var(--tx-card);
            border: 1px solid var(--tx-line);
            border-radius: 24px;
            padding: 18px;
            margin-bottom: 20px;
            backdrop-filter: blur(10px);
            position: relative;
            overflow: hidden;
            animation: txFadeUp .5s cubic-bezier(.16,1,.3,1) both;
        }
        @keyframes txFadeUp{ from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:none} }
        .tx-chart-head{
            display:flex; align-items:center; justify-content:space-between; gap:10px;
            flex-wrap:wrap; margin-bottom:14px;
        }
        .tx-chart-title{
            display:flex; align-items:center; gap:8px;
            font-size:15px; font-weight:700; color:var(--tx-txt);
        }
        .tx-chart-title i{ color:#A855F7; }
        .tx-range{ display:flex; gap:6px; background:var(--tx-card2); border-radius:12px; padding:4px; }
        .tx-range button{
            border:0; background:transparent; color:var(--tx-mut); cursor:pointer;
            font-family:inherit; font-size:11px; font-weight:700; padding:6px 11px; border-radius:9px; transition:.2s;
        }
        .tx-range button.on{ background:linear-gradient(135deg,#7C3AED,#A855F7); color:#fff; }

        /* تب ارزها */
        .tx-cur-tabs{
            display:grid; grid-template-columns:repeat(4,1fr); gap:8px; margin-bottom:16px;
        }
        .tx-cur-tab{
            display:flex; flex-direction:column; align-items:center; gap:5px;
            padding:11px 6px; border-radius:16px; cursor:pointer; font-family:inherit;
            background:var(--tx-card2); border:1px solid var(--tx-line);
            color:var(--tx-mut); transition:transform .18s, border-color .2s, background .2s, box-shadow .2s;
        }
        .tx-cur-tab:active{ transform:scale(.96); }
        .tx-cur-tab i{ font-size:14px; }
        .tx-cur-tab .t{ font-size:11px; font-weight:800; letter-spacing:.03em; }
        .tx-cur-tab .s{ font-size:9px; opacity:.8; }
        .tx-cur-tab.on{
            color:#fff; border-color:transparent;
            box-shadow:0 8px 22px rgba(124,58,237,.32);
        }
        .tx-chart-wrap{ position:relative; height:230px; }
        .tx-chart-wrap canvas{ display:block; width:100% !important; }
        @media (min-width: 900px){ .tx-chart-wrap{ height:300px; } }
        .tx-chart-legend{
            display:flex; align-items:center; justify-content:center; gap:16px; margin-top:12px; flex-wrap:wrap;
            font-size:11px; color:var(--tx-mut); font-weight:700;
        }
        .tx-chart-legend span{ display:inline-flex; align-items:center; gap:6px; }
        .tx-chart-legend i{ width:10px; height:10px; border-radius:3px; display:inline-block; }
        .tx-mini-stats{
            display:grid; grid-template-columns:repeat(3,1fr); gap:8px; margin-top:14px;
        }
        .tx-mini{
            background:var(--tx-card2); border:1px solid var(--tx-line); border-radius:14px;
            padding:10px 8px; text-align:center;
        }
        .tx-mini .l{ font-size:9.5px; color:var(--tx-mut); font-weight:700; letter-spacing:.04em; }
        .tx-mini .v{ font-size:14px; font-weight:800; margin-top:4px; direction:ltr; }
        .tx-mini.in  .v{ color:#4CD964; }
        .tx-mini.out .v{ color:#FF5E5E; }
        .tx-mini.net .v{ color:#A855F7; }
        .tx-chart-empty{
            display:flex; flex-direction:column; align-items:center; justify-content:center;
            height:100%; color:var(--tx-mut); font-size:13px; gap:10px;
        }
        .tx-chart-empty i{ font-size:26px; opacity:.5; }

        /* ---- رسپانسیو: موبایل ---- */
        @media (max-width: 480px){
            .tx-chart-card{ padding:14px; border-radius:20px; }
            .tx-cur-tabs{ gap:6px; }
            .tx-cur-tab{ padding:9px 4px; border-radius:13px; }
            .tx-cur-tab .t{ font-size:10px; }
            .tx-cur-tab .s{ display:none; }
            .tx-chart-wrap{ height:190px; }
            .tx-mini .v{ font-size:12px; }
            .summary-cards{ grid-template-columns:repeat(2,1fr); gap:10px; }
            .summary-card{ padding:13px; border-radius:16px; }
            .summary-card .value{ font-size:17px; }
        }

        /* ---- رسپانسیو: تبلت ---- */
        @media (min-width: 700px){
            .dashboard{ max-width: 900px; padding: 0 22px 100px; }
            .summary-cards{ grid-template-columns:repeat(4,1fr); }
        }

        /* ---- رسپانسیو: دسکتاپ ---- */
        @media (min-width: 1024px){
            .dashboard{ max-width: 1180px; padding: 0 28px 110px; }
            .tx-desk-grid{
                display:grid;
                grid-template-columns: 1.15fr 1fr;
                gap:20px;
                align-items:start;
            }
            .summary-cards{ grid-template-columns:repeat(4,1fr); gap:14px; }
            .summary-card .value{ font-size:22px; }
            .transactions-section{ margin-top:0; }
            .tx-desk-grid > .active-filters,
            .tx-desk-grid > .modal-overlay{ grid-column: 1 / -1; }
            .transactions-list{ display:grid; grid-template-columns:1fr 1fr; gap:12px; }
            .transaction-item{ padding:14px; }
            .transaction-avatar{ width:44px; height:44px; font-size:15px; }
            .transaction-name{ font-size:14px; }
            .transaction-amount{ font-size:14px; }
            .greeting h1{ font-size:32px; }
        }
        @media (min-width: 1500px){
            .dashboard{ max-width: 1400px; }
            .transactions-list{ grid-template-columns:repeat(3,1fr); }
        }

        /* تم روشن برای اجزای موجود */
        html[data-theme="light"] .transactions-section,
        html[data-theme="light"] .summary-card{
            background: var(--tx-card) !important;
            border-color: var(--tx-line) !important;
        }
        html[data-theme="light"] .transaction-item{
            background: var(--tx-card2) !important;
            border-color: var(--tx-line) !important;
        }
        html[data-theme="light"] .section-title h2,
        html[data-theme="light"] .transaction-name,
        html[data-theme="light"] .greeting h1{ color: var(--tx-txt) !important; }
        html[data-theme="light"] .summary-card .label,
        html[data-theme="light"] .transaction-description,
        html[data-theme="light"] .transaction-date{ color: var(--tx-mut) !important; }
</style>
<?php if (isset($_GET['embed'])): ?>
<style>
/* حالت جاسازی در مدال arad.php/dashboard.php: حذف چروم و منوهای تکراری */
.header, .footer-menu, .bottom-nav, #footerMenu, .database-warning,
#avaScanOverlay, .ava-scan-overlay, #currencyRateModal,
.ava-prof-drawer, .avag-modal, #globalLoadingOverlay, #currencyRateSection { display:none !important; }
html, body { overflow-x:hidden; }
html { background:#0A0520 !important; }
body { padding:14px !important; background:#0A0520 !important; color:#fff; animation:none !important; }
html[data-theme="light"] { background:#F4F2FB !important; }
html[data-theme="light"] body { background:#F4F2FB !important; color:#1a0b2e; }
.dashboard { padding:0 !important; margin:0 !important; background:transparent !important; }
</style>
<?php endif; ?>
</head>
<body>
    <!-- Hidden PDF Template -->
    <div id="pdfTemplate">
        <div style="text-align: center; margin-bottom: 30px; border-bottom: 2px solid #6C40C5; padding-bottom: 20px;">
            <?php if (!empty($appLogo['url'])): ?>
            <img src="<?php echo htmlspecialchars($appLogo['url']); ?>" alt="AvaPay" style="width:56px;height:56px;border-radius:50%;object-fit:cover;margin-bottom:10px;">
            <?php endif; ?>
            <h1 style="color: #6C40C5; font-size: 24px; margin: 0;">Ava Pay</h1>
            <h2 style="color: #333; font-size: 18px; margin: 10px 0;">صورت‌حساب تراکنش‌ها</h2>
            <p style="color: #666; font-size: 12px;">تاریخ صدور: <?php echo $currentDate; ?> — <?php echo $currentTime; ?></p>
        </div>
        
        <div style="margin-bottom: 30px;">
            <h3 style="color: #333; font-size: 16px; border-bottom: 1px solid #ddd; padding-bottom: 10px;">اطلاعات حساب</h3>
            <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                <tr><td style="padding: 8px; background: #f5f5f5; width: 30%;"><strong>صاحب حساب:</strong></td><td style="padding: 8px;"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></td></tr>
                <tr><td style="padding: 8px; background: #f5f5f5;"><strong>شماره حساب:</strong></td><td style="padding: 8px;"><?php echo htmlspecialchars($user['account_number'] ?? 'N/A'); ?></td></tr>
                <tr><td style="padding: 8px; background: #f5f5f5;"><strong>شبا:</strong></td><td style="padding: 8px;"><?php echo htmlspecialchars($user['iban_number'] ?? 'N/A'); ?></td></tr>
                <tr><td style="padding: 8px; background: #f5f5f5;"><strong>شماره تماس:</strong></td><td style="padding: 8px;"><?php echo htmlspecialchars($user['phone_number'] ?? 'N/A'); ?></td></tr>
            </table>
        </div>
        
        <div style="margin-bottom: 30px;">
            <h3 style="color: #333; font-size: 16px; border-bottom: 1px solid #ddd; padding-bottom: 10px;">خلاصه مالی</h3>
            <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                <tr><td style="padding: 8px; background: #f5f5f5; width: 50%;"><strong>مجموع ارسالی:</strong></td><td style="padding: 8px; color: #FF3B30;"><?php echo formatCurrency($totalSent, 'USD'); ?></td></tr>
                <tr><td style="padding: 8px; background: #f5f5f5;"><strong>مجموع دریافتی:</strong></td><td style="padding: 8px; color: #4CD964;"><?php echo formatCurrency($totalReceived, 'USD'); ?></td></tr>
                <tr><td style="padding: 8px; background: #f5f5f5;"><strong>خالص جریان نقدی:</strong></td><td style="padding: 8px; <?php echo $netFlow >= 0 ? 'color: #4CD964;' : 'color: #FF3B30;'; ?>"><?php echo ($netFlow >= 0 ? '+' : '-') . formatCurrency(abs($netFlow), 'USD'); ?></td></tr>
                <tr><td style="padding: 8px; background: #f5f5f5;"><strong>مجموع واریزی‌ها:</strong></td><td style="padding: 8px; color: #6C40C5;"><?php echo formatCurrency($summary['total_deposits'] ?? 0, 'USD'); ?></td></tr>
                <tr><td style="padding: 8px; background: #f5f5f5;"><strong>مجموع برداشت‌ها:</strong></td><td style="padding: 8px; color: #FFC107;"><?php echo formatCurrency($summary['total_withdrawals'] ?? 0, 'USD'); ?></td></tr>
            </table>
        </div>
        
        <div>
            <h3 style="color: #333; font-size: 16px; border-bottom: 1px solid #ddd; padding-bottom: 10px;">
                تاریخچه تراکنش‌ها
                <?php if (count($pdfTransactions) >= 500): ?>
                <span style="font-size:10px;color:#999;font-weight:normal;">(۵۰۰ مورد آخر — برای بازه‌ی دقیق‌تر از فیلترها استفاده کنید)</span>
                <?php endif; ?>
            </h3>
            <table style="width: 100%; border-collapse: collapse; font-size: 10px;">
                <thead><tr style="background: #6C40C5; color: white;"><th style="padding: 8px; text-align: right;">تاریخ</th><th style="padding: 8px; text-align: right;">شرح</th><th style="padding: 8px; text-align: right;">نوع</th><th style="padding: 8px; text-align: right;">وضعیت</th><th style="padding: 8px; text-align: left;">مبلغ</th></tr></thead>
                <tbody>
                    <?php foreach ($pdfTransactions as $tx): ?>
                    <?php 
                    $isSent = $tx['sender_id'] == $userId;
                    $txType = $tx['type'] === 'deposit' ? 'واریز' : ($tx['type'] === 'withdrawal' ? 'برداشت' : ($isSent ? 'ارسالی' : 'دریافتی'));
                    $amountColor = $tx['type'] === 'deposit' || (!$isSent && $tx['type'] !== 'withdrawal') ? '#4CD964' : '#FF3B30';
                    $amountPrefix = $tx['type'] === 'deposit' || (!$isSent && $tx['type'] !== 'withdrawal') ? '+' : '-';
                    $txStatusFa = ['completed' => 'تکمیل‌شده', 'pending' => 'در انتظار', 'failed' => 'ناموفق', 'cancelled' => 'لغوشده'];
                    ?>
                    <tr style="border-bottom: 1px solid #eee;"><td style="padding: 6px;"><?php echo date('Y-m-d H:i', strtotime($tx['created_at'])); ?></td><td style="padding: 6px;"><?php echo htmlspecialchars($tx['description'] ?? 'تراکنش'); ?></td><td style="padding: 6px;"><?php echo $txType; ?></td><td style="padding: 6px;"><?php echo $txStatusFa[$tx['status']] ?? htmlspecialchars($tx['status']); ?></td><td style="padding: 6px; text-align: left; color: <?php echo $amountColor; ?>;"><?php echo $amountPrefix . ' ' . formatCurrency($tx['amount'], $tx['currency']); ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div style="margin-top: 30px; text-align: center; color: #999; font-size: 10px; border-top: 1px solid #ddd; padding-top: 15px;">
            <p>این گزارش به‌صورت خودکار توسط سامانه Ava Pay تولید شده است.</p>
            <p><?php echo count($pdfTransactions); ?> تراکنش | تاریخ تولید: <?php echo $currentDate; ?></p>
        </div>
    </div>
    
    <div class="dashboard">
        <!-- Header -->
        <div class="header">
            <div class="greeting">
                <h1>Transactions</h1>
                <p>All your financial activities</p>
            </div>
            <div style="display: flex; gap: 10px;">
                <button class="filter-btn" onclick="openFilterModal()">
                    <i class="fas fa-sliders-h"></i>
                    <span>Filter</span>
                    <?php if ($activeFilterCount > 0): ?>
                    <span class="filter-badge"><?php echo $activeFilterCount; ?></span>
                    <?php endif; ?>
                </button>
                <a href="dashboard.php">
                    <i class="fas fa-arrow-left"></i>
                </a>
            </div>
        </div>
        
        <!-- Financial Summary Cards -->
        <div class="summary-cards">
            <div class="summary-card sent"><div class="label">Total Sent</div><div class="value"><?php echo formatCurrency($totalSent, 'USD'); ?></div></div>
            <div class="summary-card received"><div class="label">Total Received</div><div class="value"><?php echo formatCurrency($totalReceived, 'USD'); ?></div></div>
            <div class="summary-card net"><div class="label">Net Flow</div><div class="value"><?php echo formatCurrency($netFlow, 'USD'); ?></div></div>
            <div class="summary-card"><div class="label">Transactions</div><div class="value"><?php echo $total; ?></div></div>
        </div>
        
        <!-- ===== (آپدیت ۴) کارت نمودار به تفکیک ارز ===== -->
        <div class="tx-desk-grid">
        <div class="tx-chart-card">
            <div class="tx-chart-head">
                <div class="tx-chart-title"><i class="fas fa-chart-area"></i> <span id="txChartTitle">Cash Flow</span></div>
                <div class="tx-range">
                    <button type="button" class="on" data-mode="net" onclick="txSetMode('net', this)">Net</button>
                    <button type="button" data-mode="both" onclick="txSetMode('both', this)">In / Out</button>
                    <button type="button" data-mode="bar" onclick="txSetMode('bar', this)">Bars</button>
                </div>
            </div>

            <div class="tx-cur-tabs" id="txCurTabs">
                <?php foreach ($chartCurs as $ci => $cc): $cm = $chartMeta[$cc]; ?>
                <button type="button" class="tx-cur-tab<?php echo $ci === 0 ? ' on' : ''; ?>"
                        data-cur="<?php echo $cc; ?>"
                        data-color="<?php echo $cm['color']; ?>"
                        onclick="txSelectCur('<?php echo $cc; ?>', this)">
                    <i class="<?php echo $cm['icon']; ?>"></i>
                    <span class="t"><?php echo $cc === 'IRR' ? 'TOMAN' : $cc; ?></span>
                    <span class="s"><?php echo $cm['fa']; ?></span>
                </button>
                <?php endforeach; ?>
            </div>

            <div class="tx-chart-wrap">
                <canvas id="txChart"></canvas>
                <div class="tx-chart-empty" id="txChartEmpty" style="display:none;">
                    <i class="fas fa-chart-line"></i>
                    <span>No data for this currency yet</span>
                </div>
            </div>

            <div class="tx-chart-legend" id="txLegend"></div>

            <div class="tx-mini-stats">
                <div class="tx-mini in"><div class="l">RECEIVED</div><div class="v" id="txMiniIn">—</div></div>
                <div class="tx-mini out"><div class="l">SENT</div><div class="v" id="txMiniOut">—</div></div>
                <div class="tx-mini net"><div class="l">NET</div><div class="v" id="txMiniNet">—</div></div>
            </div>
        </div>

        <!-- Filter Modal -->
        <div class="modal-overlay" id="filterModal">
            <div class="modal-container">
                <div class="modal-header">
                    <h3><i class="fas fa-sliders-h"></i> Filter Transactions</h3>
                    <button class="modal-close" onclick="closeFilterModal()"><i class="fas fa-times"></i></button>
                </div>
                <div class="modal-body">
                    <div class="filter-grid">
                        <div class="filter-box">
                            <label class="filter-label"><i class="fas fa-calendar-alt"></i> Month</label>
                            <div class="filter-input-group">
                                <select id="monthFilter" class="filter-select">
                                    <option value="">All Months</option>
                                    <option value="01">January</option><option value="02">February</option><option value="03">March</option>
                                    <option value="04">April</option><option value="05">May</option><option value="06">June</option>
                                    <option value="07">July</option><option value="08">August</option><option value="09">September</option>
                                    <option value="10">October</option><option value="11">November</option><option value="12">December</option>
                                </select>
                            </div>
                        </div>
                        <div class="filter-box">
                            <label class="filter-label"><i class="fas fa-calendar"></i> Year</label>
                            <div class="filter-input-group">
                                <select id="yearFilter" class="filter-select">
                                    <option value="">All Years</option>
                                    <?php for ($year = date('Y'); $year >= date('Y')-5; $year--): ?>
                                    <option value="<?php echo $year; ?>"><?php echo $year; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                        <div class="filter-box">
                            <label class="filter-label"><i class="fas fa-money-bill-wave"></i> Currency</label>
                            <div class="filter-input-group">
                                <select id="currencyFilter" class="filter-select">
                                    <option value="all">All Currencies</option>
                                    <option value="USD">USD ($)</option><option value="EUR">EUR (€)</option>
                                    <option value="IRR">IRR (﷼)</option><option value="USDT">USDT</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="filter-box full-width">
                        <label class="filter-label"><i class="fas fa-info-circle"></i> Status</label>
                        <div class="filter-input-group">
                            <select id="statusFilter" class="filter-select">
                                <option value="all">All Status</option>
                                <option value="completed">Completed</option><option value="pending">Pending</option>
                                <option value="failed">Failed</option><option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>
                    <div class="filter-box full-width">
                        <label class="filter-label"><i class="fas fa-exchange-alt"></i> Transaction Type</label>
                        <div class="filter-input-group">
                            <select id="typeFilter" class="filter-select">
                                <option value="all">All Transactions</option>
                                <option value="sent">Sent</option><option value="received">Received</option>
                                <option value="deposit">Deposits</option><option value="withdrawal">Withdrawals</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary" onclick="resetFilters()"><i class="fas fa-redo"></i> Reset</button>
                    <button class="btn-primary" onclick="applyFilters()"><i class="fas fa-check"></i> Apply Filters</button>
                </div>
            </div>
        </div>
        
        <!-- Active Filters Display -->
        <?php if ($activeFilterCount > 0): ?>
        <div class="active-filters">
            <?php if ($filterType !== 'all'): ?><div class="active-filter-tag">Type: <?php echo ucfirst($filterType); ?><button class="remove-filter" onclick="removeFilter('type')"><i class="fas fa-times"></i></button></div><?php endif; ?>
            <?php if ($filterMonth): ?><div class="active-filter-tag">Month: <?php $months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']; echo $months[(int)$filterMonth-1]; ?><button class="remove-filter" onclick="removeFilter('month')"><i class="fas fa-times"></i></button></div><?php endif; ?>
            <?php if ($filterYear): ?><div class="active-filter-tag">Year: <?php echo $filterYear; ?><button class="remove-filter" onclick="removeFilter('year')"><i class="fas fa-times"></i></button></div><?php endif; ?>
            <?php if ($filterCurrency && $filterCurrency !== 'all'): ?><div class="active-filter-tag">Currency: <?php echo $filterCurrency; ?><button class="remove-filter" onclick="removeFilter('currency')"><i class="fas fa-times"></i></button></div><?php endif; ?>
            <?php if ($filterStatus && $filterStatus !== 'all'): ?><div class="active-filter-tag">Status: <?php echo ucfirst($filterStatus); ?><button class="remove-filter" onclick="removeFilter('status')"><i class="fas fa-times"></i></button></div><?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- Transactions List -->
        <div class="transactions-section">
            <div class="section-title">
                <h2>All Transactions</h2>
                <div style="display: flex; gap: 10px;">
                    <button id="exportPdfBtn" class="btn-secondary" style="padding: 8px 15px; font-size: 0.9rem;"><i class="fas fa-file-pdf"></i> دانلود صورت‌حساب PDF</button>
                    <button class="btn-secondary" style="padding: 8px 15px; font-size: 0.9rem;" onclick="refreshTransactions()"><i class="fas fa-sync-alt"></i> Refresh</button>
                </div>
            </div>
            
            <div class="filter-loading" id="filterLoading"><div class="filter-loading-spinner"></div></div>
            
            <div class="transactions-list" id="transactionsList">
                <?php foreach ($transactions as $transaction): ?>
                <?php 
                $isSent = $transaction['sender_id'] == $userId;
                $isReceived = $transaction['receiver_id'] == $userId;
                if ($isSent) { $contactName = ($transaction['receiver_name'] ?? '') . ' ' . ($transaction['receiver_last_name'] ?? ''); $contactAvatar = $transaction['receiver_avatar'] ?? ''; } 
                else { $contactName = ($transaction['sender_name'] ?? '') . ' ' . ($transaction['sender_last_name'] ?? ''); $contactAvatar = $transaction['sender_avatar'] ?? ''; }
                if ($transaction['type'] === 'deposit') { $transactionType = 'deposit'; $isPositive = true; }
                elseif ($transaction['type'] === 'withdrawal') { $transactionType = 'withdrawal'; $isPositive = false; }
                else { $transactionType = $isSent ? 'sent' : 'received'; $isPositive = $isReceived; }
                $amountClass = $isPositive ? 'amount-positive' : 'amount-negative';
                $amountValue = $isPositive ? $transaction['amount'] : -$transaction['amount'];
                ?>
                <div class="transaction-item">
                    <div style="display: flex; align-items: center; width: 100%;">
                        <div style="margin-right: 15px;"><div class="transaction-avatar"><?php if (!empty($contactAvatar) && $contactAvatar !== 'default-avatar.png') { ?><img src="<?php echo htmlspecialchars($contactAvatar); ?>" alt="<?php echo htmlspecialchars($contactName); ?>" onerror="this.style.display='none'; this.parentElement.innerHTML='<?php echo getInitials($transaction['receiver_name'] ?? $transaction['sender_name'], $transaction['receiver_last_name'] ?? $transaction['sender_last_name']); ?>';"><?php } else { echo getInitials($transaction['receiver_name'] ?? $transaction['sender_name'], $transaction['receiver_last_name'] ?? $transaction['sender_last_name']); } ?></div></div>
                        <div style="flex: 1;"><div style="display: flex; justify-content: space-between; margin-bottom: 5px;"><div class="transaction-name"><?php echo htmlspecialchars($contactName ?: 'Unknown'); ?></div><div class="transaction-amount <?php echo $amountClass; ?>"><?php echo formatCurrency($amountValue, $transaction['currency']); ?></div></div><div style="display: flex; justify-content: space-between;"><div class="transaction-description"><?php echo htmlspecialchars($transaction['description'] ?? 'Transaction'); ?></div><div class="transaction-date"><?php echo formatDate($transaction['created_at']); ?></div></div><div style="margin-top: 5px; font-size: 0.8rem; display: flex; gap: 10px; flex-wrap: wrap;"><span class="type-<?php echo $transactionType; ?>"><?php echo ucfirst($transactionType); ?></span><?php if ($transaction['status']): ?><span style="background: <?php echo getStatusColor($transaction['status']); ?>; padding: 2px 10px; border-radius: 10px;"><?php echo ucfirst($transaction['status']); ?></span><?php endif; ?></div></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($transactions)): ?>
                <div class="no-results"><i class="fas fa-exchange-alt"></i><div style="font-size: 1.2rem; margin-bottom: 10px;">No transactions found</div><div style="margin-bottom: 20px;"><?php if ($activeFilterCount > 0): ?>Try changing your filters or <?php endif; ?>Start by sending or receiving money</div><?php if ($activeFilterCount > 0): ?><button class="btn-secondary" onclick="resetAllFilters()" style="padding: 10px 20px;"><i class="fas fa-redo"></i> Clear Filters</button><?php endif; ?></div>
                <?php endif; ?>
            </div>
            
            <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?><a href="?page=<?php echo $page-1; ?><?php echo $filterType !== 'all' ? '&type='.$filterType : ''; ?><?php echo $filterMonth ? '&month='.$filterMonth : ''; ?><?php echo $filterYear ? '&year='.$filterYear : ''; ?><?php echo ($filterCurrency && $filterCurrency !== 'all') ? '&currency='.$filterCurrency : ''; ?><?php echo $filterStatus ? '&status='.$filterStatus : ''; ?>" class="pagination-btn"><i class="fas fa-chevron-left"></i> Previous</a><?php endif; ?>
                <div class="pagination-info">Page <?php echo $page; ?> of <?php echo $totalPages; ?></div>
                <?php if ($page < $totalPages): ?><a href="?page=<?php echo $page+1; ?><?php echo $filterType !== 'all' ? '&type='.$filterType : ''; ?><?php echo $filterMonth ? '&month='.$filterMonth : ''; ?><?php echo $filterYear ? '&year='.$filterYear : ''; ?><?php echo ($filterCurrency && $filterCurrency !== 'all') ? '&currency='.$filterCurrency : ''; ?><?php echo $filterStatus ? '&status='.$filterStatus : ''; ?>" class="pagination-btn">Next <i class="fas fa-chevron-right"></i></a><?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        </div><!-- /tx-desk-grid -->
    </div>
    
    <?php require_once 'includes/footer_menu.php'; renderFooterMenu('Transactions'); ?>
    
    <script>
    /* ==========================================================
       (آپدیت ۴) نمودار تراکنش‌ها به تفکیک ارز (دلار/یورو/تتر/تومان)
       ========================================================== */
    const TX_DATA   = <?php echo json_encode($chartData, JSON_UNESCAPED_UNICODE); ?>;
    const TX_META   = <?php echo json_encode($chartMeta, JSON_UNESCAPED_UNICODE); ?>;
    const TX_LABELS = <?php echo json_encode($chartLabels); ?>;

    let txCur  = '<?php echo $chartCurs[0]; ?>';
    let txMode = 'net';
    let txChart = null;

    function txIsLight(){
        try { return (document.documentElement.getAttribute('data-theme') || localStorage.getItem('ava_theme')) === 'light'; }
        catch(e){ return false; }
    }
    function txFmt(v, dec){
        const a = Math.abs(Number(v) || 0);
        let out;
        if (a >= 1e9)      out = (a / 1e9).toFixed(2) + 'B';
        else if (a >= 1e6) out = (a / 1e6).toFixed(2) + 'M';
        else if (a >= 1e3) out = (a / 1e3).toFixed(1) + 'K';
        else               out = a.toLocaleString('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec });
        return (Number(v) < 0 ? '-' : '') + out;
    }
    function txHexA(hex, a){
        const h = hex.replace('#', '');
        const r = parseInt(h.substring(0,2), 16), g = parseInt(h.substring(2,4), 16), b = parseInt(h.substring(4,6), 16);
        return 'rgba(' + r + ',' + g + ',' + b + ',' + a + ')';
    }

    function txSelectCur(cur, el){
        txCur = cur;
        document.querySelectorAll('.tx-cur-tab').forEach(function(b){
            b.classList.remove('on');
            b.style.background = '';
        });
        if (el){
            el.classList.add('on');
            const c = el.dataset.color || '#A855F7';
            el.style.background = 'linear-gradient(135deg,' + txHexA(c, .95) + ',' + txHexA(c, .55) + ')';
        }
        txRender();
    }
    function txSetMode(mode, el){
        txMode = mode;
        document.querySelectorAll('.tx-range button').forEach(function(b){ b.classList.remove('on'); });
        if (el) el.classList.add('on');
        txRender();
    }

    function txRender(){
        const meta  = TX_META[txCur]  || { color:'#A855F7', dec:2, label:txCur, fa:txCur, sym:'' };
        const d     = TX_DATA[txCur]  || { in:[], out:[], net:[], tin:0, tout:0, count:0 };
        const light = txIsLight();
        const grid  = light ? 'rgba(16,8,40,.08)'  : 'rgba(255,255,255,.07)';
        const tick  = light ? '#6B6390'            : 'rgba(255,255,255,.5)';

        const title = document.getElementById('txChartTitle');
        if (title) title.textContent = (txCur === 'IRR' ? 'Toman' : meta.label) + ' — Cash Flow (12M)';

        // آمار پایین کارت
        const mi = document.getElementById('txMiniIn'), mo = document.getElementById('txMiniOut'), mn = document.getElementById('txMiniNet');
        const net = (Number(d.tin) || 0) - (Number(d.tout) || 0);
        if (mi) mi.textContent = '+' + txFmt(d.tin,  meta.dec);
        if (mo) mo.textContent = '-' + txFmt(d.tout, meta.dec);
        if (mn) mn.textContent = (net >= 0 ? '+' : '') + txFmt(net, meta.dec);

        // راهنما
        const lg = document.getElementById('txLegend');
        if (lg){
            lg.innerHTML = (txMode === 'net')
                ? '<span><i style="background:' + meta.color + '"></i> Net flow (' + (txCur === 'IRR' ? 'Toman' : txCur) + ')</span>'
                : '<span><i style="background:#4CD964"></i> Received</span><span><i style="background:#FF5E5E"></i> Sent</span>';
        }

        const empty = document.getElementById('txChartEmpty');
        const cv    = document.getElementById('txChart');
        const hasData = (Number(d.count) || 0) > 0;
        if (empty) empty.style.display = hasData ? 'none' : 'flex';
        if (cv)    cv.style.display    = hasData ? 'block' : 'none';
        if (!hasData){ if (txChart){ txChart.destroy(); txChart = null; } return; }

        let datasets;
        if (txMode === 'net'){
            datasets = [{
                label: 'Net',
                data: d.net,
                borderColor: meta.color,
                backgroundColor: txHexA(meta.color, .18),
                borderWidth: 2.4,
                tension: .38,
                fill: true,
                pointRadius: 0,
                pointHoverRadius: 5,
                pointHoverBackgroundColor: meta.color
            }];
        } else if (txMode === 'both'){
            datasets = [
                { label:'Received', data:d.in,  borderColor:'#4CD964', backgroundColor:'rgba(76,217,100,.15)', borderWidth:2.2, tension:.38, fill:true, pointRadius:0, pointHoverRadius:5 },
                { label:'Sent',     data:d.out, borderColor:'#FF5E5E', backgroundColor:'rgba(255,94,94,.13)',  borderWidth:2.2, tension:.38, fill:true, pointRadius:0, pointHoverRadius:5 }
            ];
        } else {
            datasets = [
                { label:'Received', data:d.in,  backgroundColor:'rgba(76,217,100,.75)', borderRadius:6, borderSkipped:false, maxBarThickness:16 },
                { label:'Sent',     data:d.out, backgroundColor:'rgba(255,94,94,.75)',  borderRadius:6, borderSkipped:false, maxBarThickness:16 }
            ];
        }

        const cfg = {
            type: (txMode === 'bar') ? 'bar' : 'line',
            data: { labels: TX_LABELS, datasets: datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 850, easing: 'easeOutQuart' },
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: light ? 'rgba(255,255,255,.96)' : 'rgba(12,6,30,.94)',
                        titleColor: light ? '#180F35' : '#fff',
                        bodyColor:  light ? '#3B3468' : 'rgba(255,255,255,.85)',
                        borderColor: txHexA(meta.color, .5),
                        borderWidth: 1,
                        padding: 11,
                        displayColors: true,
                        callbacks: {
                            label: function(ctx){
                                return ' ' + ctx.dataset.label + ': ' + txFmt(ctx.parsed.y, meta.dec) + ' ' + (txCur === 'IRR' ? 'Toman' : txCur);
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { display:false }, ticks: { color: tick, font: { size: 9.5 }, maxRotation: 0, autoSkipPadding: 8 } },
                    y: {
                        grid: { color: grid, drawBorder: false },
                        ticks: { color: tick, font: { size: 9.5 }, callback: function(v){ return txFmt(v, 0); } }
                    }
                }
            }
        };

        if (txChart){ txChart.destroy(); txChart = null; }
        try { txChart = new Chart(document.getElementById('txChart'), cfg); } catch(e){ console.error('chart', e); }
    }

    document.addEventListener('DOMContentLoaded', function(){
        const first = document.querySelector('.tx-cur-tab.on');
        if (first){
            const c = first.dataset.color || '#A855F7';
            first.style.background = 'linear-gradient(135deg,' + txHexA(c, .95) + ',' + txHexA(c, .55) + ')';
        }
        if (typeof Chart !== 'undefined') txRender();
        else setTimeout(txRender, 700);
    });
    // هنگام تغییر تم، نمودار را با رنگ‌های جدید بازسازی کن
    try {
        new MutationObserver(function(){ if (txChart || document.getElementById('txChart')) txRender(); })
            .observe(document.documentElement, { attributes:true, attributeFilter:['data-theme'] });
    } catch(e){}
    </script>

    <script>
        const exportPdfBtn = document.getElementById('exportPdfBtn');
        const filterLoading = document.getElementById('filterLoading');
        
        function openFilterModal() {
            document.getElementById('filterModal').style.display = 'flex';
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('month')) document.getElementById('monthFilter').value = urlParams.get('month');
            if (urlParams.get('year')) document.getElementById('yearFilter').value = urlParams.get('year');
            if (urlParams.get('currency')) document.getElementById('currencyFilter').value = urlParams.get('currency');
            if (urlParams.get('status')) document.getElementById('statusFilter').value = urlParams.get('status');
            if (urlParams.get('type')) document.getElementById('typeFilter').value = urlParams.get('type');
        }
        
        function closeFilterModal() { document.getElementById('filterModal').style.display = 'none'; }
        
        function applyFilters() {
            const month = document.getElementById('monthFilter').value;
            const year = document.getElementById('yearFilter').value;
            const currency = document.getElementById('currencyFilter').value;
            const status = document.getElementById('statusFilter').value;
            const type = document.getElementById('typeFilter').value;
            let params = [];
            if (month) params.push('month='+month);
            if (year) params.push('year='+year);
            if (currency && currency !== 'all') params.push('currency='+currency);
            if (status && status !== 'all') params.push('status='+status);
            if (type && type !== 'all') params.push('type='+type);
            params.push('page=1');
            showFilterLoading();
            setTimeout(() => { window.location.href = 'transactions.php?' + params.join('&'); }, 300);
        }
        
        function resetFilters() {
            document.getElementById('monthFilter').value = '';
            document.getElementById('yearFilter').value = '';
            document.getElementById('currencyFilter').value = 'all';
            document.getElementById('statusFilter').value = 'all';
            document.getElementById('typeFilter').value = 'all';
        }
        
        function removeFilter(filterType) {
            showFilterLoading();
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.delete(filterType);
            urlParams.set('page', '1');
            setTimeout(() => { window.location.href = 'transactions.php?' + urlParams.toString(); }, 300);
        }
        
        function resetAllFilters() { showFilterLoading(); setTimeout(() => { window.location.href = 'transactions.php?page=1'; }, 300); }
        function refreshTransactions() { showFilterLoading(); setTimeout(() => { window.location.reload(); }, 500); }
        function showFilterLoading() { if (filterLoading) filterLoading.style.display = 'flex'; }
        
        if (exportPdfBtn) exportPdfBtn.addEventListener('click', exportPDF);
        
        async function exportPDF() {
            try {
                const exportBtn = document.getElementById('exportPdfBtn');
                exportBtn.innerHTML = '<div class="export-loading"><div class="spinner"></div> Generating...</div>';
                exportBtn.disabled = true;
                showToast('Generating PDF report...', 'info');
                const pdfTemplate = document.getElementById('pdfTemplate');
                const canvas = await html2canvas(pdfTemplate, { scale: 2, useCORS: true, logging: false, backgroundColor: '#ffffff' });
                const imgData = canvas.toDataURL('image/png');
                const { jsPDF } = window.jspdf;
                const pdf = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
                const imgWidth = 190;
                const imgHeight = (canvas.height * imgWidth) / canvas.width;
                // نکته‌ی مهم: قبلاً کل تصویر (که می‌تواند برای صدها تراکنش خیلی بلند باشد)
                // فقط روی یک صفحه‌ی A4 چسبانده می‌شد و هر چیزی بعد از ارتفاع صفحه قطع
                // می‌شد. حالا تصویر بین چند صفحه تقسیم می‌شود تا کل صورت‌حساب کامل باشد.
                const pageHeight = 277; // ارتفاع قابل‌استفاده‌ی A4 (297) منهای حاشیه
                let heightLeft = imgHeight;
                let position = 10;
                pdf.addImage(imgData, 'PNG', 10, position, imgWidth, imgHeight);
                heightLeft -= pageHeight;
                while (heightLeft > 0) {
                    position = heightLeft - imgHeight + 10;
                    pdf.addPage();
                    pdf.addImage(imgData, 'PNG', 10, position, imgWidth, imgHeight);
                    heightLeft -= pageHeight;
                }
                const userName = "<?php echo htmlspecialchars($user['first_name'] . '_' . $user['last_name']); ?>";
                const dateStr = "<?php echo date('Y-m-d'); ?>";
                pdf.save(`AvaPay_Statement_${userName}_${dateStr}.pdf`);
                showToast('PDF generated successfully!', 'success');
            } catch (error) {
                console.error('PDF export error:', error);
                showToast('Error generating PDF. Please try again.', 'error');
            } finally {
                setTimeout(() => { const btn = document.getElementById('exportPdfBtn'); if(btn) { btn.innerHTML = '<i class="fas fa-file-pdf"></i> دانلود صورت‌حساب PDF'; btn.disabled = false; } }, 1000);
            }
        }
        
        function showToast(message, type = 'info') {
            document.querySelectorAll('.toast').forEach(t => t.remove());
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.textContent = message;
            document.body.appendChild(toast);
            setTimeout(() => toast.classList.add('show'), 10);
            setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 300); }, 3000);
        }
        
        window.addEventListener('click', function(event) { if (event.target === document.getElementById('filterModal')) closeFilterModal(); });
        window.addEventListener('beforeunload', function() { showFilterLoading(); });
        
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('month')) document.getElementById('monthFilter').value = urlParams.get('month');
            if (urlParams.get('year')) document.getElementById('yearFilter').value = urlParams.get('year');
            if (urlParams.get('currency')) document.getElementById('currencyFilter').value = urlParams.get('currency');
            if (urlParams.get('status')) document.getElementById('statusFilter').value = urlParams.get('status');
            if (urlParams.get('type')) document.getElementById('typeFilter').value = urlParams.get('type');
        });
    </script>

<!-- ==========================================================================
     AvaPay · «Nova» — تم مشترک. عمداً در انتهای <body>: بلوک‌های استایل بالای
     همین فایل با !important می‌نویسند و هر شیوه‌نامه‌ای در <head> بازنده‌ی کسکید است.
     ========================================================================== -->
<link rel="stylesheet" href="assets/css/nova-shared.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/nova-shared.css') ?: time(); ?>">
</body>
</html>