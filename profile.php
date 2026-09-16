<?php
require_once __DIR__ . '/includes/camera_headers.php';

require_once __DIR__ . '/includes/session_boot.php';
require_once __DIR__ . '/includes/logo_helper.php';
$appLogo = getAppLogo();
require_once 'config/database.php';
require_once __DIR__ . '/includes/tier_system.php';
require_once __DIR__ . '/includes/referral_system.php';

// بازسازی سشن از روی توکن ۳۰ روزه
avapay_restore_session_from_token($conn);

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$userId = $_SESSION['user_id'];

// ===== دریافت اطلاعات کاربر =====
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    session_destroy();
    header('Location: login.php');
    exit();
}
$user = $result->fetch_assoc();

// ===== ستون‌های تنظیمات نوتیفیکیشن (auto-migrate) =====
foreach (['notify_email' => 1, 'notify_toast' => 1, 'notify_telegram' => 1] as $col => $def) {
    $chk = $conn->query("SHOW COLUMNS FROM users LIKE '$col'");
    if (!$chk || $chk->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN `$col` TINYINT(1) DEFAULT $def");
        $user[$col] = $def;
    }
}
$notifyEmailOn    = !isset($user['notify_email'])    || (int)$user['notify_email']    === 1;
$notifyToastOn    = !isset($user['notify_toast'])    || (int)$user['notify_toast']    === 1;
$notifyTelegramOn = !isset($user['notify_telegram']) || (int)$user['notify_telegram'] === 1;
$hasTelegram      = !empty($user['telegram_id']);

$success = '';
$error   = '';

// ===== آپلود آواتار =====
if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
    require_once __DIR__ . '/includes/upload_paths.php';
    $uploadDir = avapay_upload_dir('avatars');

    $file    = $_FILES['avatar'];
    $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    if (in_array($fileExt, $allowedExt)) {
        if ($file['size'] <= 5 * 1024 * 1024) {
            $newFileName     = 'avatar_' . $userId . '_' . time() . '.' . $fileExt;
            $fileDestination = $uploadDir . $newFileName;

            if (move_uploaded_file($file['tmp_name'], $fileDestination)) {
                if (!empty($user['avatar']) && $user['avatar'] !== 'default-avatar.png') {
                    $oldBase   = basename($user['avatar']);
                    $oldNew    = avapay_upload_dir('avatars') . $oldBase;
                    $oldLegacy = 'uploads/avatars/' . $oldBase;
                    if (is_file($oldNew)) { @unlink($oldNew); }
                    elseif (file_exists($oldLegacy)) { @unlink($oldLegacy); }
                }

                $avatarUrl  = 'uploads/avatars/' . $newFileName;
                $avatarStmt = $conn->prepare("UPDATE users SET avatar = ?, updated_at = NOW() WHERE id = ?");
                $avatarStmt->bind_param("si", $avatarUrl, $userId);
                if ($avatarStmt->execute()) {
                    $user['avatar'] = $avatarUrl;
                    $success = 'عکس پروفایل با موفقیت بروزرسانی شد';
                } else {
                    $error = 'خطا در ذخیره‌سازی عکس در دیتابیس';
                    if (file_exists($fileDestination)) unlink($fileDestination);
                }
            } else {
                $error = 'آپلود فایل ناموفق بود';
            }
        } else {
            $error = 'حجم فایل نباید بیشتر از ۵ مگابایت باشد';
        }
    } else {
        $error = 'فرمت فایل نامعتبر است (فقط JPG, PNG, GIF, WebP)';
    }
}

// ===== بروزرسانی اطلاعات پروفایل =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['first_name'])) {
    $kycApproved = (isset($user['kyc_status']) && $user['kyc_status'] === 'approved');
    if ($kycApproved) {
        $firstName = $user['first_name'] ?? '';
        $lastName  = $user['last_name'] ?? '';
    } else {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName  = trim($_POST['last_name'] ?? '');
    }
    $phoneNumber = trim($_POST['phone_number'] ?? '');
    $email       = trim($_POST['email'] ?? '');

    $checkColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'email'");
    $emailColumnExists = ($checkColumn && $checkColumn->num_rows > 0);

    if ($emailColumnExists) {
        $updateStmt = $conn->prepare("UPDATE users SET first_name=?, last_name=?, phone_number=?, email=?, updated_at=NOW() WHERE id=?");
        $updateStmt->bind_param("ssssi", $firstName, $lastName, $phoneNumber, $email, $userId);
    } else {
        $updateStmt = $conn->prepare("UPDATE users SET first_name=?, last_name=?, phone_number=?, updated_at=NOW() WHERE id=?");
        $updateStmt->bind_param("sssi", $firstName, $lastName, $phoneNumber, $userId);
    }

    if ($updateStmt->execute()) {
        $_SESSION['first_name'] = $firstName;
        $_SESSION['last_name']  = $lastName;
        $success = 'اطلاعات پروفایل بروزرسانی شد';
        $user['first_name']   = $firstName;
        $user['last_name']    = $lastName;
        $user['phone_number'] = $phoneNumber;
        if ($emailColumnExists) $user['email'] = $email;
    } else {
        $error = 'بروزرسانی ناموفق بود: ' . $conn->error;
    }
}

// ===== سطح کاربری / تخفیف =====
$tierInfo = avapay_get_user_tier($conn, $userId);
$tierCurLabels = ['IRR' => 'تومان', 'USD' => 'دلار', 'EUR' => 'یورو', 'USDT' => 'تتر'];
$tierCurLabel = $tierCurLabels[$tierInfo['volume_currency'] ?? 'IRR'] ?? 'تومان';

// ===== خلاصه‌ی صورتحساب‌های پرداخت‌نشده و حساب‌های در انتظار پرداخت =====
// (همان منطق dashboard.php، ساده‌شده به‌صورت شمارش/جمع برای این صفحه)
function ava_prof_safe_rows($conn, $sql, $types = '', $params = []) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    if ($types !== '') { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $res = $stmt->get_result();
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

$avaInvPending = ava_prof_safe_rows($conn, "SELECT id, currency, amount, description, created_at
                        FROM unpaid_invoices
                        WHERE user_id = ? AND status IN ('pending','approved')
                        ORDER BY created_at DESC", "i", [$userId]);

$avaPendingAccounts = [];
foreach (ava_prof_safe_rows($conn,
    "SELECT id, tracking_code, currency, amount, admin_accounts, created_at, payment_receipts, payment_receipt
     FROM money_transfers
     WHERE user_id = ? AND admin_accounts IS NOT NULL AND admin_accounts <> ''
       AND status IN ('approved','waiting_payment','awaiting_payment')",
    'i', [$userId]) as $mt) {
    $accs = json_decode($mt['admin_accounts'] ?? '', true); if (!is_array($accs)) $accs = [];
    if (empty($accs)) continue;
    $hasReceipt = (!empty($mt['payment_receipts']) && $mt['payment_receipts'] !== '[]') || !empty($mt['payment_receipt']);
    if ($hasReceipt) continue;
    $avaPendingAccounts[] = ['title' => 'حواله ارزی', 'amount' => (float)($mt['amount'] ?? 0), 'currency' => $mt['currency'] ?? ''];
}
$__hasPayCols = false;
$__c1 = $conn->query("SHOW COLUMNS FROM ad_deals LIKE 'buyer_payment_amount'");
if ($__c1 && $__c1->num_rows > 0) $__hasPayCols = true;
$__paySel = $__hasPayCols ? ", buyer_payment_amount, seller_payment_amount, buyer_payment_currency, seller_payment_currency" : "";
foreach (ava_prof_safe_rows($conn,
    "SELECT id, total_price, buyer_id, seller_id, buyer_admin_accounts, seller_admin_accounts,
            buyer_side_status, seller_side_status, buyer_receipts, seller_receipts{$__paySel}
     FROM ad_deals WHERE (buyer_id = ? OR seller_id = ?) AND status <> 'completed'",
    'ii', [$userId, $userId]) as $dl) {
    $side = ((int)$dl['buyer_id'] === (int)$userId) ? 'buyer' : 'seller';
    $accs = json_decode($dl[$side.'_admin_accounts'] ?? '', true); if (!is_array($accs)) $accs = [];
    if (empty($accs)) continue;
    if (($dl[$side.'_side_status'] ?? 'new') !== 'awaiting_payment') continue;
    $rcp = json_decode($dl[$side.'_receipts'] ?? '', true); if (!is_array($rcp)) $rcp = [];
    if (!empty($rcp)) continue;
    $amt = !empty($dl[$side.'_payment_amount']) ? (float)$dl[$side.'_payment_amount'] : (float)($dl['total_price'] ?? 0);
    $cur = !empty($dl[$side.'_payment_currency']) ? $dl[$side.'_payment_currency'] : 'تومان';
    if ($cur === 'IRR') $cur = 'تومان';
    $avaPendingAccounts[] = ['title' => 'تبادل ارزی', 'amount' => $amt, 'currency' => $cur];
}
foreach (ava_prof_safe_rows($conn,
    "SELECT id, amount, currency, payment_amount, payment_currency, card_number, account_number, iban, receipt_image
     FROM topup_requests WHERE user_id = ? AND status IN ('approved','waiting_payment','payment_received')",
    'i', [$userId]) as $tp) {
    if (empty($tp['card_number']) && empty($tp['account_number']) && empty($tp['iban'])) continue;
    if (!empty($tp['receipt_image'])) continue;
    $amt = !empty($tp['payment_amount']) ? (float)$tp['payment_amount'] : (float)($tp['amount'] ?? 0);
    $cur = !empty($tp['payment_currency']) ? $tp['payment_currency'] : ($tp['currency'] ?? '');
    if ($cur === 'IRR') $cur = 'تومان';
    $avaPendingAccounts[] = ['title' => 'شارژ حساب', 'amount' => $amt, 'currency' => $cur];
}

// ===== معرفی دوستان (استفاده از سیستم واقعی referral_system.php) =====
$__refStats    = ava_ref_my_stats($conn, $userId);
$__refSettings = ava_ref_settings($conn);

$fullName  = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
if ($fullName === '') $fullName = 'کاربر آواپی';
$avatarSrc = !empty($user['avatar']) ? htmlspecialchars($user['avatar']) : 'default-avatar.png';
$joinYear  = !empty($user['created_at']) ? date('Y', strtotime($user['created_at'])) : '—';
$ordersDone = (int)($user['completed_orders_count'] ?? 0);

$gKycMap = [
    'approved' => ['تایید شده',      '#22C55E', 'fa-circle-check'],
    'pending'  => ['در انتظار بررسی', '#FBBF24', 'fa-hourglass-half'],
    'rejected' => ['رد شده',          '#FF5A6E', 'fa-circle-xmark'],
];
$gKyc     = $user['kyc_status'] ?? 'none';
$gKycInfo = $gKycMap[$gKyc] ?? ['انجام نشده', '#94A3B8', 'fa-id-card'];

$checkColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'email'");
$emailColumnExists = ($checkColumn && $checkColumn->num_rows > 0);
$kycLocked = (isset($user['kyc_status']) && $user['kyc_status'] === 'approved');

$__embed = isset($_GET['embed']);
$__sec   = $_GET['section'] ?? 'all';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="dns-prefetch" href="https://cdnjs.cloudflare.com">
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="dns-prefetch" href="https://cdn.jsdelivr.net">
<script>(function(){try{var t=localStorage.getItem("ava_theme")||"dark";document.documentElement.setAttribute("data-theme",t);}catch(e){}})();</script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<title>پروفایل من | AvaPay</title>
<link rel="stylesheet" href="assets/css/style.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/style.css') ?: time(); ?>">
<link rel="stylesheet" href="/ledor/assets/css/theme-light.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/theme-light.css') ?: time(); ?>">
    <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
    <link rel="preload" href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css"></noscript>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" defer></script>
<link rel="stylesheet" href="assets/css/referral-box.css?v=<?php echo @filemtime(__DIR__ . '/assets/css/referral-box.css') ?: time(); ?>">
<link rel="manifest" href="manifest.php">
<?php $__appIcon = ($appLogo ? htmlspecialchars($appLogo['url']) : '/ledor/AVAPAY.PNG?v=' . (@filemtime(__DIR__ . '/AVAPAY.PNG') ?: time())); ?>
<link rel="icon" type="image/png" href="<?php echo $__appIcon; ?>">
<link rel="apple-touch-icon" href="<?php echo $__appIcon; ?>">
<style>
:root{
  --ap-purple:#6C40C5; --ap-purple-light:#9B6BE0; --ap-purple-deep:#3B0F6E;
  --ap-bg1:#120B24; --ap-bg2:#1D1035;
  --ap-card:rgba(255,255,255,.05); --ap-card-b:rgba(255,255,255,.09);
  --ap-text:#F1ECFB; --ap-dim:#B4A9CE;
  --ap-danger:#F87171; --ap-gold:#FFC94A;
}
*{box-sizing:border-box;}
html,body{background:transparent;}
body{
  font-family:'Vazirmatn',-apple-system,BlinkMacSystemFont,sans-serif;
  background:
    radial-gradient(circle at 15% 0%, rgba(124,58,237,.35), transparent 45%),
    radial-gradient(circle at 90% 15%, rgba(37,99,235,.20), transparent 40%),
    linear-gradient(160deg, var(--ap-bg1) 0%, var(--ap-bg2) 55%, var(--ap-purple-deep) 130%);
  color:var(--ap-text); min-height:100vh; margin:0; padding-bottom:100px;
}
html[data-theme="light"] body{ background:#F4F2FB; color:#1a0b2e; }
.apWrap{max-width:520px;margin:0 auto;padding:16px 16px 0;}

.apTop{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;padding-top:env(safe-area-inset-top,10px);}
.apTop h1{font-size:18px;font-weight:800;margin:0;}
.apIcoBtn{width:40px;height:40px;border-radius:14px;background:var(--ap-card);border:1px solid var(--ap-card-b);
  display:flex;align-items:center;justify-content:center;color:var(--ap-text);text-decoration:none;backdrop-filter:blur(10px);}

/* --- هیرو / کارت پروفایل --- */
.apHero{position:relative;border-radius:28px;padding:26px 20px 22px;overflow:hidden;text-align:center;
  background:linear-gradient(135deg, var(--ap-purple) 0%, #4C1D95 60%, #1E1240 120%);
  box-shadow:0 20px 45px -15px rgba(124,58,237,.55);}
.apHero::before{content:"";position:absolute;inset:0;background:radial-gradient(circle at 80% -10%, rgba(255,255,255,.18), transparent 55%);}
.apAvWrap{position:relative;width:104px;height:104px;margin:0 auto 14px;}
.apAvRing{position:absolute;inset:-5px;border-radius:50%;
  background:conic-gradient(from 0deg, var(--ap-gold), #FF9F45, var(--ap-gold), #FF9F45, var(--ap-gold));
  animation:apSpin 6s linear infinite;}
@keyframes apSpin{to{transform:rotate(360deg);}}
.apAvatar{position:relative;width:104px;height:104px;border-radius:50%;overflow:hidden;
  border:4px solid var(--ap-bg1);z-index:1;background:#1A0F33;}
.apAvatar img{width:100%;height:100%;object-fit:cover;}
.apCamBtn{position:absolute;bottom:2px;left:2px;width:32px;height:32px;border-radius:11px;background:#fff;
  color:var(--ap-purple-deep);display:flex;align-items:center;justify-content:center;font-size:14px;
  box-shadow:0 4px 10px rgba(0,0,0,.35);z-index:2;border:2px solid var(--ap-bg1);cursor:pointer;}
.apName{font-size:19px;font-weight:800;margin:2px 0 4px;position:relative;}
.apSub{font-size:12.5px;color:rgba(255,255,255,.75);margin-bottom:12px;position:relative;}
.apBadgeRow{display:flex;align-items:center;justify-content:center;gap:6px;flex-wrap:wrap;position:relative;}
.apTierBadge{display:inline-flex;align-items:center;gap:6px;font-weight:800;font-size:12.5px;color:#3B1D00;
  padding:6px 14px;border-radius:100px;box-shadow:0 6px 16px -4px rgba(255,159,69,.6);}
.apVerified{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;padding:4px 10px;border-radius:100px;}

.apStats{display:flex;gap:10px;margin-top:18px;position:relative;}
.apStat{flex:1;background:rgba(255,255,255,.10);backdrop-filter:blur(8px);border-radius:18px;padding:12px 8px;
  text-align:center;border:1px solid rgba(255,255,255,.14);}
.apStat b{display:block;font-size:15px;}
.apStat span{font-size:10.5px;color:rgba(255,255,255,.75);}

/* --- هشدارها --- */
.apAlerts{margin-top:14px;display:flex;flex-direction:column;gap:10px;}
.apAlert{display:flex;align-items:center;gap:12px;padding:13px 14px;border-radius:18px;border:1px solid;
  backdrop-filter:blur(10px);text-decoration:none;color:inherit;}
.apAlert.inv{background:rgba(248,113,113,.10);border-color:rgba(248,113,113,.28);}
.apAlert.acc{background:rgba(147,197,253,.10);border-color:rgba(147,197,253,.28);}
.apAlert .ic{width:36px;height:36px;border-radius:11px;display:flex;align-items:center;justify-content:center;
  font-size:14px;flex-shrink:0;}
.apAlert.inv .ic{background:rgba(248,113,113,.20);color:var(--ap-danger);}
.apAlert.acc .ic{background:rgba(147,197,253,.20);color:#93C5FD;}
.apAlert .txt{flex:1;min-width:0;}
.apAlert .txt b{display:block;font-size:13.5px;font-weight:700;}
.apAlert .txt span{font-size:11.5px;color:var(--ap-dim);}

/* --- کارت سکشن عمومی --- */
.apSection{background:var(--ap-card);border:1px solid var(--ap-card-b);border-radius:22px;padding:6px;
  margin-top:16px;backdrop-filter:blur(10px);}
.apSecTitle{font-size:13px;color:var(--ap-dim);font-weight:700;margin:12px 4px 8px;padding:0 4px;}
.apRow{display:flex;align-items:center;gap:12px;padding:13px 12px;border-radius:16px;}
.apRow+.apRow{border-top:1px solid rgba(255,255,255,.06);}
.apRow .ic{width:38px;height:38px;border-radius:12px;display:flex;align-items:center;justify-content:center;
  font-size:15px;flex-shrink:0;}
.ic-purple{background:rgba(124,58,237,.22);color:#B794F6;}
.ic-green{background:rgba(52,211,153,.18);color:#6EE7B7;}
.ic-blue{background:rgba(59,130,246,.22);color:#93C5FD;}
.ic-gold{background:rgba(255,201,74,.18);color:var(--ap-gold);}
.ic-red{background:rgba(248,113,113,.18);color:var(--ap-danger);}
.apRow .txt{flex:1;min-width:0;}
.apRow .txt b{display:block;font-size:14px;font-weight:700;}
.apRow .txt span{font-size:11.5px;color:var(--ap-dim);}
.apChev{color:var(--ap-dim);font-size:13px;}
.apMiniBadge{font-size:10.5px;background:rgba(52,211,153,.18);color:#6EE7B7;padding:3px 8px;border-radius:100px;font-weight:700;}

/* --- کارت سطح/تخفیف --- */
.apTierCard{padding:16px 14px 14px;}
.apTierHead{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;gap:8px;}
.apTierCur{display:flex;align-items:center;gap:8px;min-width:0;}
.apTierDot{width:34px;height:34px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:15px;flex:none;color:#fff;}
.apTierCur b{display:block;font-size:14.5px;font-weight:800;}
.apTierCur span{font-size:11px;color:var(--ap-dim);}
.apTierDisc{font-size:20px;font-weight:800;color:var(--ap-gold);text-align:left;flex:none;}
.apTierDisc small{font-size:10.5px;color:var(--ap-dim);font-weight:500;display:block;}
.apTrack{position:relative;height:8px;border-radius:100px;background:rgba(255,255,255,.08);margin:26px 6px 8px;}
.apTrack .fill{position:absolute;top:0;right:0;height:100%;border-radius:100px;background:linear-gradient(90deg,#38bdf8,#FFD700,#9CA3AF,#B08D57);}
.apStop{position:absolute;top:50%;transform:translate(50%,-50%);width:22px;height:22px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;font-size:9.5px;border:2px solid var(--ap-bg1);color:#1f2937;}
.apStop.cur{width:26px;height:26px;font-size:11px;box-shadow:0 0 0 4px rgba(255,201,74,.25);}
.apLabels{display:flex;justify-content:space-between;padding:0 2px;margin-bottom:14px;}
.apLabels span{font-size:9.5px;color:var(--ap-dim);flex:1;text-align:center;}
.apLabels span.on{color:var(--ap-gold);font-weight:800;}
.apTierNote{background:rgba(255,255,255,.06);border-radius:14px;padding:11px 12px;font-size:12px;color:var(--ap-dim);
  display:flex;align-items:center;gap:8px;line-height:1.9;}
.apTierNote b{color:#fff;}

/* --- فرم ویرایش --- */
.apField{margin-bottom:14px;}
.apField label{display:block;margin-bottom:7px;color:var(--ap-dim);font-size:12.5px;font-weight:600;}
.apField input{width:100%;padding:13px 14px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);
  border-radius:14px;color:#fff;font-size:14px;font-family:inherit;}
.apField input:focus{outline:none;border-color:var(--ap-purple-light);}
.apField input:disabled{opacity:.6;}
.apBtn{width:100%;padding:14px;border:none;border-radius:16px;color:#fff;font-size:14.5px;font-weight:800;
  cursor:pointer;background:linear-gradient(135deg,var(--ap-purple),#EC4899);font-family:inherit;}
.apBtnGhost{width:100%;padding:13px;border-radius:16px;font-size:14px;font-weight:700;cursor:pointer;
  background:transparent;border:1px solid var(--ap-danger);color:var(--ap-danger);font-family:inherit;}
.apKycOk{background:rgba(34,211,160,.12);border:1px solid rgba(34,211,160,.35);color:#22d3a0;border-radius:14px;
  padding:11px 14px;margin-bottom:14px;font-size:12.5px;display:flex;align-items:center;gap:8px;}

/* --- toggle --- */
.apToggleRow{display:flex;align-items:center;justify-content:space-between;padding:14px 12px;gap:10px;}
.apToggleRow+.apToggleRow{border-top:1px solid rgba(255,255,255,.06);}
.apToggleRow .txt b{display:block;font-size:13.5px;font-weight:700;}
.apToggleRow .txt span{font-size:11px;color:var(--ap-dim);}
.apSwitch{position:relative;display:inline-block;width:46px;height:26px;flex:none;}
.apSwitch input{opacity:0;width:0;height:0;}
.apSlider{position:absolute;inset:0;background:rgba(255,255,255,.15);transition:.25s;border-radius:100px;cursor:pointer;}
.apSlider:before{content:"";position:absolute;height:20px;width:20px;right:3px;bottom:3px;background:#fff;
  transition:.25s;border-radius:50%;}
.apSwitch input:checked+.apSlider{background:linear-gradient(90deg,var(--ap-purple),#EC4899);}
.apSwitch input:checked+.apSlider:before{transform:translateX(-20px);}
.apSwitch input:disabled+.apSlider{opacity:.4;cursor:not-allowed;}

.apAlertMsg{border-radius:16px;padding:12px 14px;margin-bottom:14px;font-size:13px;display:flex;align-items:center;gap:8px;}
.apAlertMsg.ok{background:rgba(52,211,153,.12);border:1px solid rgba(52,211,153,.3);color:#6EE7B7;}
.apAlertMsg.err{background:rgba(248,113,113,.12);border:1px solid rgba(248,113,113,.3);color:#FCA5A5;}

/* --- گوی‌های شبکه‌ی اجتماعی --- */
.apSocialRow{display:flex;justify-content:center;gap:18px;margin-top:20px;}
.apSocialOrb{width:54px;height:54px;border-radius:50%;display:flex;align-items:center;justify-content:center;
  font-size:22px;color:#fff;text-decoration:none;box-shadow:0 8px 20px -6px rgba(0,0,0,.5);
  border:1px solid rgba(255,255,255,.14);transition:transform .2s;}
.apSocialOrb:active{transform:scale(.92);}
.apSocialOrb.tg{background:radial-gradient(circle at 30% 25%, #4FC3F7, #29A9EB 55%, #1B84C6);}
.apSocialOrb.ig{background:radial-gradient(circle at 30% 25%, #FED576, #F47133 45%, #BC3081 70%, #4F5BD5);}

.apToast{position:fixed;bottom:90px;left:50%;transform:translateX(-50%) translateY(100px);
  background:rgba(20,10,40,.95);color:#fff;padding:12px 22px;border-radius:100px;z-index:1200;
  transition:transform .3s;white-space:nowrap;font-size:13px;border:1px solid rgba(255,255,255,.12);}
.apToast.show{transform:translateX(-50%) translateY(0);}

/* --- حالت جاسازی در مدال (embed) --- */
<?php if ($__embed): ?>
body{padding:14px !important;background:#0A0520 !important;}
html[data-theme="light"] body{background:#F4F2FB !important;color:#1a0b2e;}
.apWrap{padding:0 !important;max-width:none;}
.apTop, .apHero, .apAlerts, #apTierSection, #apAccountSection, #apDangerSection, #apSocialSection{display:none !important;}
<?php if ($__sec === 'kyc'): ?>
#apKycSection{display:block !important;}
<?php elseif ($__sec === 'edit'): ?>
#apEditSection{display:block !important;}
<?php elseif ($__sec === 'notifications'): ?>
#apNotifSection{display:block !important;}
<?php else: ?>
.apTop, .apHero, .apAlerts, #apTierSection, #apAccountSection, #apDangerSection, #apSocialSection{display:block !important;}
<?php endif; ?>
<?php endif; ?>
</style>
</head>
<body>
<div class="apWrap">

  <div class="apTop">
    <a href="dashboard.php" class="apIcoBtn"><i class="fas fa-arrow-right"></i></a>
    <h1>پروفایل من</h1>
    <label for="avatarUpload" class="apIcoBtn"><i class="fas fa-pen"></i></label>
  </div>

  <?php if ($success): ?><div class="apAlertMsg ok"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="apAlertMsg err"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>

  <!-- ===== هیرو / کارت پروفایل ===== -->
  <div class="apHero">
    <div class="apAvWrap">
      <div class="apAvRing"></div>
      <div class="apAvatar">
        <img id="apAvatarImg" src="<?php echo $avatarSrc; ?>" alt="avatar" onerror="this.src='/ledor/default-avatar.png'">
      </div>
      <label for="avatarUpload" class="apCamBtn"><i class="fas fa-camera"></i></label>
      <form id="avatarForm" method="POST" enctype="multipart/form-data">
        <input type="file" id="avatarUpload" name="avatar" accept=".jpg,.jpeg,.png,.gif,.webp" style="display:none;" onchange="apUploadAvatar()">
      </form>
    </div>
    <div class="apName"><?php echo htmlspecialchars($fullName); ?></div>
    <div class="apSub">عضو از سال <?php echo htmlspecialchars($joinYear); ?></div>
    <div class="apBadgeRow">
      <?php if ($tierInfo['current']): $c = $tierInfo['current']; ?>
      <span class="apTierBadge" style="background:linear-gradient(90deg,<?php echo htmlspecialchars($c['badge_color']); ?>,#FF9F45);">
        <i class="fas <?php echo htmlspecialchars($c['badge_icon']); ?>"></i> کاربر <?php echo htmlspecialchars($c['name']); ?>
      </span>
      <?php endif; ?>
      <span class="apVerified" style="background:rgba(<?php echo $gKyc==='approved'?'52,211,153,.18':'148,163,184,.18'; ?>);color:<?php echo htmlspecialchars($gKycInfo[1]); ?>;border:1px solid <?php echo htmlspecialchars($gKycInfo[1]); ?>55;">
        <i class="fas <?php echo htmlspecialchars($gKycInfo[2]); ?>"></i> <?php echo htmlspecialchars($gKycInfo[0]); ?>
      </span>
    </div>

    <div class="apStats">
      <div class="apStat"><b><?php echo number_format($ordersDone); ?></b><span>معامله موفق</span></div>
      <div class="apStat"><b><?php echo number_format($tierInfo['volume_toman']); ?></b><span>حجم معاملات (<?php echo $tierCurLabel; ?>)</span></div>
      <div class="apStat"><b><?php echo $tierInfo['current'] ? htmlspecialchars($tierInfo['current']['name']) : 'عادی'; ?></b><span>سطح فعلی</span></div>
    </div>
  </div>

  <!-- ===== هشدار: صورتحساب پرداخت‌نشده / حساب در انتظار پرداخت ===== -->
  <?php if (!empty($avaInvPending) || !empty($avaPendingAccounts)): ?>
  <div class="apAlerts">
    <?php if (!empty($avaInvPending)): ?>
    <a class="apAlert inv" href="dashboard.php#avaTopInvCard">
      <div class="ic"><i class="fas fa-file-invoice"></i></div>
      <div class="txt">
        <b><?php echo count($avaInvPending); ?> صورتحساب پرداخت‌نشده دارید</b>
        <span>برای مشاهده و پرداخت ضربه بزنید</span>
      </div>
      <i class="fas fa-chevron-left apChev"></i>
    </a>
    <?php endif; ?>
    <?php if (!empty($avaPendingAccounts)): ?>
    <a class="apAlert acc" href="dashboard.php#avaRecAccCard">
      <div class="ic"><i class="fas fa-building-columns"></i></div>
      <div class="txt">
        <b><?php echo count($avaPendingAccounts); ?> شماره حساب در انتظار پرداخت دارید</b>
        <span>ادمین شماره حساب ارسال کرده — واریز خود را ثبت کنید</span>
      </div>
      <i class="fas fa-chevron-left apChev"></i>
    </a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- ===== سطح کاربری و تخفیف (نمودار) ===== -->
  <div class="apSection apTierCard" id="apTierSection">
    <div class="apTierHead">
      <div class="apTierCur">
        <div class="apTierDot" id="apTierDot" style="background:<?php echo $tierInfo['current'] ? htmlspecialchars($tierInfo['current']['badge_color']) : '#6C40C5'; ?>;">
          <i class="fas <?php echo $tierInfo['current'] ? htmlspecialchars($tierInfo['current']['badge_icon']) : 'fa-medal'; ?>"></i>
        </div>
        <div>
          <b><?php echo $tierInfo['current'] ? htmlspecialchars($tierInfo['current']['name']) : 'کاربر عادی'; ?></b>
          <span>حجم معاملات: <?php echo number_format($tierInfo['volume_toman']); ?> <?php echo $tierCurLabel; ?></span>
        </div>
      </div>
      <div class="apTierDisc">
        <?php echo $tierInfo['current'] ? number_format((float)$tierInfo['current']['discount_percent'], 0) : 0; ?>٪
        <small>تخفیف کارمزد فعلی</small>
      </div>
    </div>

    <div class="apTrack" id="apTierTrack"></div>
    <div class="apLabels" id="apTierLabels"></div>

    <div class="apTierNote" id="apTierNote">
      <?php if ($tierInfo['next']): ?>
        <i class="fas fa-circle-info"></i>
        تا رسیدن به سطح <b><?php echo htmlspecialchars($tierInfo['next']['name']); ?> (<?php echo number_format((float)$tierInfo['next']['discount_percent'],0); ?>٪ تخفیف)</b>
        فقط <b><?php echo number_format($tierInfo['remaining_toman']); ?> <?php echo $tierCurLabel; ?></b> حجم معامله دیگر لازم داری.
      <?php else: ?>
        <i class="fas fa-crown"></i> شما به بالاترین سطح رسیده‌اید 🎉
      <?php endif; ?>
    </div>
  </div>

  <!-- ===== حساب کاربری ===== -->
  <div class="apSection" id="apAccountSection">
    <div class="apSecTitle">حساب کاربری</div>
    <div class="apRow" id="apEditRowBtn" style="cursor:pointer;" onclick="apToggleEdit()">
      <div class="ic ic-purple"><i class="fas fa-id-card"></i></div>
      <div class="txt"><b>اطلاعات هویتی</b><span>نام، شماره موبایل، ایمیل</span></div>
      <i class="fas fa-chevron-left apChev" id="apEditChev"></i>
    </div>
    <div class="apRow" id="apKycRowBtn" style="cursor:pointer;" onclick="apToggleKyc()">
      <div class="ic ic-green"><i class="fas fa-shield-halved"></i></div>
      <div class="txt"><b>احراز هویت (KYC)</b><span><?php echo htmlspecialchars($gKycInfo[0]); ?></span></div>
      <?php if ($gKyc === 'approved'): ?><span class="apMiniBadge">تایید شده</span>
      <?php else: ?><i class="fas fa-chevron-left apChev"></i><?php endif; ?>
    </div>
    <div class="apRow" style="cursor:pointer;" onclick="window.arfOpenPanel && window.arfOpenPanel()">
      <div class="ic ic-purple"><i class="fas fa-user-plus"></i></div>
      <div class="txt"><b>دعوت دوستان</b><span>به ازای هر دعوت <?php echo number_format($__refSettings['welcome_bonus_eur'],2); ?>€ هدیه بگیرید</span></div>
      <i class="fas fa-chevron-left apChev"></i>
    </div>
    <div class="apRow" style="cursor:pointer;" onclick="apToggleNotif()">
      <div class="ic ic-purple"><i class="fas fa-bell"></i></div>
      <div class="txt"><b>تنظیمات اعلان‌ها</b><span>ایمیل، تلگرام، درون‌برنامه‌ای</span></div>
      <i class="fas fa-chevron-left apChev" id="apNotifChev"></i>
    </div>
    <a class="apRow" href="javascript:void(0)" onclick="(typeof avaOpenSupport==='function')?avaOpenSupport():(window.location.href='/ledor/dashboard.php?open=support')" style="text-decoration:none;color:inherit;">
      <div class="ic ic-purple"><i class="fas fa-headset"></i></div>
      <div class="txt"><b>پشتیبانی</b><span>گفتگو با تیم پشتیبانی</span></div>
      <i class="fas fa-chevron-left apChev"></i>
    </a>
  </div>

  <!-- ===== فرم ویرایش اطلاعات (باز/بسته‌شونده) ===== -->
  <div class="apSection" id="apEditSection" style="display:none;padding:16px 14px;">
    <?php if ($kycLocked): ?>
    <div class="apKycOk"><i class="fas fa-shield-check"></i> هویت شما تایید شده؛ نام و نام‌خانوادگی قابل تغییر نیست.</div>
    <?php endif; ?>
    <form method="POST" action="profile.php<?php echo $__embed ? ('?embed=1&section=' . htmlspecialchars($__sec, ENT_QUOTES)) : ''; ?>" id="profileForm">
      <div class="apField">
        <label><i class="fas fa-user"></i> نام</label>
        <input type="text" name="first_name" value="<?php echo htmlspecialchars($user['first_name'] ?? ''); ?>" required <?php echo $kycLocked ? 'readonly' : ''; ?>>
      </div>
      <div class="apField">
        <label><i class="fas fa-user"></i> نام خانوادگی</label>
        <input type="text" name="last_name" value="<?php echo htmlspecialchars($user['last_name'] ?? ''); ?>" required <?php echo $kycLocked ? 'readonly' : ''; ?>>
      </div>
      <div class="apField">
        <label><i class="fas fa-phone"></i> شماره موبایل</label>
        <input type="tel" name="phone_number" value="<?php echo htmlspecialchars($user['phone_number'] ?? ''); ?>" required dir="ltr">
      </div>
      <?php if ($emailColumnExists): ?>
      <div class="apField">
        <label><i class="fas fa-envelope"></i> ایمیل (اختیاری)</label>
        <input type="email" name="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" dir="ltr">
      </div>
      <?php endif; ?>
      <button type="submit" class="apBtn"><i class="fas fa-save"></i> ذخیره تغییرات</button>
    </form>
  </div>

  <!-- ===== KYC ===== -->
  <div class="apSection" id="apKycSection" style="display:none;padding:16px 14px;">
    <div id="kycBox"><div style="text-align:center;color:var(--ap-dim);"><i class="fas fa-spinner fa-spin"></i></div></div>
  </div>

  <!-- ===== تنظیمات اعلان‌ها ===== -->
  <div class="apSection" id="apNotifSection" style="display:none;">
    <div class="apToggleRow">
      <div class="txt"><b><i class="fas fa-envelope" style="color:#38bdf8;"></i> اعلان ایمیلی</b><span>دریافت اعلان‌ها از طریق ایمیل</span></div>
      <label class="apSwitch"><input type="checkbox" id="notifyEmailToggle" <?php echo $notifyEmailOn?'checked':''; ?>><span class="apSlider"></span></label>
    </div>
    <div class="apToggleRow">
      <div class="txt"><b><i class="fas fa-comment-dots" style="color:var(--ap-gold);"></i> اعلان درون‌برنامه‌ای</b><span>نمایش Toast داخل اپلیکیشن</span></div>
      <label class="apSwitch"><input type="checkbox" id="notifyToastToggle" <?php echo $notifyToastOn?'checked':''; ?>><span class="apSlider"></span></label>
    </div>
    <div class="apToggleRow">
      <div class="txt"><b><i class="fab fa-telegram" style="color:#29A9EB;"></i> اعلان تلگرامی</b><span><?php echo $hasTelegram ? 'دریافت اعلان‌ها در تلگرام' : 'ابتدا حساب تلگرام را متصل کنید'; ?></span></div>
      <label class="apSwitch"><input type="checkbox" id="notifyTelegramToggle" <?php echo $notifyTelegramOn?'checked':''; ?> <?php echo $hasTelegram?'':'disabled'; ?>><span class="apSlider"></span></label>
    </div>
    <div id="notifPrefFeedback" style="padding:10px 12px;font-size:12.5px;"></div>
  </div>

  <!-- ===== خروج از حساب ===== -->
  <div class="apSection" id="apDangerSection" style="padding:14px;">
    <button type="button" class="apBtnGhost" onclick="apShowLogoutConfirm()"><i class="fas fa-arrow-right-from-bracket"></i> خروج از حساب</button>
  </div>

  <!-- ===== شبکه‌های اجتماعی ===== -->
  <div class="apSocialRow" id="apSocialSection">
    <a class="apSocialOrb tg" href="https://t.me/aradtransfer" target="_blank" rel="noopener" aria-label="Telegram">
      <i class="fab fa-telegram"></i>
    </a>
    <a class="apSocialOrb ig" href="https://instagram.com/avapay.co" target="_blank" rel="noopener" aria-label="Instagram">
      <i class="fab fa-instagram"></i>
    </a>
  </div>

</div>

<?php if (!$__embed): ?>
<!-- ===== پنل کامل «معرفی دوستان» (سیستم واقعی referral_system.php) ===== -->
<?php include __DIR__ . '/_arf_overlay_block.php'; ?>

<?php require_once 'includes/footer_menu.php'; renderFooterMenu('Profile'); ?>
<?php endif; ?>

<!-- ===== مودال تایید خروج ===== -->
<div id="apLogoutOverlay" style="display:none;position:fixed;inset:0;background:rgba(5,2,15,.75);backdrop-filter:blur(6px);z-index:2000;align-items:center;justify-content:center;">
  <div style="background:#1a0f33;border:1px solid rgba(255,255,255,.1);border-radius:22px;width:88%;max-width:360px;padding:22px;">
    <div style="font-weight:800;font-size:15px;margin-bottom:8px;"><i class="fas fa-arrow-right-from-bracket" style="color:var(--ap-danger);"></i> خروج از حساب</div>
    <p style="color:var(--ap-dim);font-size:13px;line-height:2;margin:0 0 18px;">آیا مطمئن هستید می‌خواهید از حساب خود خارج شوید؟</p>
    <div style="display:flex;gap:10px;">
      <button type="button" class="apBtnGhost" style="border-color:rgba(255,255,255,.2);color:#fff;" onclick="apCloseLogoutConfirm()">انصراف</button>
      <button type="button" class="apBtn" style="background:linear-gradient(135deg,#F87171,#EF4444);" onclick="apLogoutNow()">خروج</button>
    </div>
  </div>
</div>

<script src="assets/js/app.js"></script>
<script src="assets/js/referral-box.js?v=<?php echo @filemtime(__DIR__ . '/assets/js/referral-box.js') ?: time(); ?>" defer></script>
<script>
function apToast(msg, type) {
  var t = document.createElement('div');
  t.className = 'apToast';
  t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(function(){ t.classList.add('show'); }, 10);
  setTimeout(function(){ t.classList.remove('show'); setTimeout(function(){ t.remove(); }, 300); }, 2800);
}

/* ---------- آواتار ---------- */
function apUploadAvatar() {
  var input = document.getElementById('avatarUpload');
  if (!input.files.length) return;
  var reader = new FileReader();
  reader.onload = function(e){ document.getElementById('apAvatarImg').src = e.target.result; };
  reader.readAsDataURL(input.files[0]);
  setTimeout(function(){ document.getElementById('avatarForm').submit(); }, 100);
}

/* ---------- باز/بسته‌کردن بخش‌های آکاردئونی ---------- */
function apCloseAllPanels(exceptId) {
  ['apEditSection','apKycSection','apNotifSection'].forEach(function(id){
    if (id !== exceptId) document.getElementById(id).style.display = 'none';
  });
  document.getElementById('apEditChev').className = 'fas fa-chevron-left apChev';
  document.getElementById('apNotifChev').className = 'fas fa-chevron-left apChev';
}
function apToggleEdit() {
  var el = document.getElementById('apEditSection');
  var open = el.style.display !== 'none';
  apCloseAllPanels(open ? null : 'apEditSection');
  el.style.display = open ? 'none' : 'block';
  document.getElementById('apEditChev').className = open ? 'fas fa-chevron-left apChev' : 'fas fa-chevron-down apChev';
  if (!open) el.scrollIntoView({behavior:'smooth', block:'center'});
}
var apKycLoaded = false;
function apToggleKyc() {
  var el = document.getElementById('apKycSection');
  var open = el.style.display !== 'none';
  apCloseAllPanels(open ? null : 'apKycSection');
  el.style.display = open ? 'none' : 'block';
  if (!open) { el.scrollIntoView({behavior:'smooth', block:'center'}); if (!apKycLoaded) { apKycLoaded = true; loadKyc(); } }
}
function apToggleNotif() {
  var el = document.getElementById('apNotifSection');
  var open = el.style.display !== 'none';
  apCloseAllPanels(open ? null : 'apNotifSection');
  el.style.display = open ? 'none' : 'block';
  document.getElementById('apNotifChev').className = open ? 'fas fa-chevron-left apChev' : 'fas fa-chevron-down apChev';
  if (!open) el.scrollIntoView({behavior:'smooth', block:'center'});
}

/* ---------- نمودار سطح/تخفیف (پویا از api/tier_api.php) ---------- */
(function(){
  fetch('api/tier_api.php?action=list_public').then(function(r){return r.json();}).then(function(d){
    if (!d || !d.success || !Array.isArray(d.tiers) || !d.tiers.length) return;
    fetch('api/tier_api.php?action=get_my_tier').then(function(r){return r.json();}).then(function(m){
      if (!m || !m.success) return;
      var tiers = d.tiers;
      var track = document.getElementById('apTierTrack');
      var labels = document.getElementById('apTierLabels');
      var curId = m.current ? m.current.id : null;
      var maxVol = parseFloat(tiers[tiers.length-1].min_volume_toman) || 1;
      var vol = parseFloat(m.volume_toman) || 0;
      var fillPct = Math.min(100, (vol / maxVol) * 100);

      track.innerHTML = '<div class="fill" style="width:' + fillPct + '%;"></div>';
      labels.innerHTML = '';
      tiers.forEach(function(t, i){
        var pct = (parseFloat(t.min_volume_toman) / maxVol) * 100;
        var isDone = vol >= parseFloat(t.min_volume_toman);
        var isCur = curId === t.id;
        var stop = document.createElement('div');
        stop.className = 'apStop' + (isCur ? ' cur' : '');
        stop.style.right = pct + '%';
        stop.style.background = isDone ? (t.badge_color || '#9CA3AF') : '#2A1E4D';
        stop.innerHTML = '<i class="fas ' + (t.badge_icon || 'fa-medal') + '"></i>';
        track.appendChild(stop);

        var lab = document.createElement('span');
        lab.textContent = t.name;
        if (isCur) lab.className = 'on';
        labels.appendChild(lab);
      });
    }).catch(function(){});
  }).catch(function(){});
})();

/* ---------- تنظیمات اعلان‌ها ---------- */
async function apSaveNotifPref(field, enabled) {
  var fb = document.getElementById('notifPrefFeedback');
  try {
    var res = await fetch('api/notification_api.php?action=save_prefs', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ field: field, enabled: enabled ? 1 : 0 })
    });
    var data = await res.json();
    if (data.success) {
      fb.style.color = '#6EE7B7'; fb.innerHTML = '<i class="fas fa-check-circle"></i> تنظیمات ذخیره شد';
      apToast('تنظیمات نوتیفیکیشن ذخیره شد');
    } else {
      fb.style.color = '#FCA5A5'; fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> خطا در ذخیره';
    }
  } catch(e) {
    fb.style.color = '#FCA5A5'; fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> خطا در ارتباط با سرور';
  }
  setTimeout(function(){ fb.innerHTML=''; }, 4000);
}
var __apEmailT = document.getElementById('notifyEmailToggle');
if (__apEmailT) __apEmailT.addEventListener('change', function(){ apSaveNotifPref('notify_email', this.checked); });
var __apToastT = document.getElementById('notifyToastToggle');
if (__apToastT) __apToastT.addEventListener('change', function(){ try{localStorage.setItem('notifyToast', this.checked?'1':'0');}catch(e){} apSaveNotifPref('notify_toast', this.checked); });
var __apTgT = document.getElementById('notifyTelegramToggle');
if (__apTgT) __apTgT.addEventListener('change', function(){ apSaveNotifPref('notify_telegram', this.checked); });

/* ---------- KYC (منطق واقعی api/kyc_api.php، بدون تغییر) ---------- */
const KYC_API = 'api/kyc_api.php';
async function loadKyc() {
    const box = document.getElementById('kycBox');
    if (!box) return;
    try {
        const res = await fetch(KYC_API + '?action=status');
        const data = await res.json();
        const req = data.request;
        if (req && req.status === 'approved') {
            box.innerHTML = `
                <div style="text-align:center;padding:10px;">
                    <div style="width:64px;height:64px;margin:0 auto 12px;border-radius:50%;background:rgba(52,211,153,0.15);display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-check-circle" style="font-size:1.8rem;color:#6EE7B7;"></i>
                    </div>
                    <div style="color:#6EE7B7;font-weight:700;font-size:1rem;">هویت شما تایید شده است ✓</div>
                </div>`;
            return;
        }
        if (req && req.status === 'pending' && req.selfie_image) {
            box.innerHTML = `
                <div style="text-align:center;padding:10px;">
                    <div style="width:64px;height:64px;margin:0 auto 12px;border-radius:50%;background:rgba(245,158,11,0.15);display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-hourglass-half" style="font-size:1.6rem;color:#f59e0b;"></i>
                    </div>
                    <div style="color:#f59e0b;font-weight:700;">در انتظار بررسی ادمین</div>
                    <p style="color:var(--ap-dim);font-size:.8rem;margin-top:8px;">درخواست شما ثبت شده و به‌زودی بررسی می‌شود.</p>
                </div>`;
            return;
        }
        const rejected = req && req.status === 'rejected';
        const step = (req && req.status === 'pending' && !req.selfie_image) ? 2 : 1;
        renderKycForm(step, req, rejected);
    } catch(e) {
        box.innerHTML = '<div style="color:#ff6b6b;text-align:center;">خطا در بارگذاری</div>';
    }
}
function renderKycForm(step, req, rejected) {
    const box = document.getElementById('kycBox');
    const rejNote = rejected ? `<div style="background:rgba(248,113,113,0.1);border:1px solid rgba(248,113,113,0.3);color:#ff8a8a;border-radius:10px;padding:10px 12px;font-size:.78rem;margin-bottom:14px;"><i class="fas fa-info-circle"></i> درخواست قبلی شما رد شد${req && req.admin_note ? ': ' + req.admin_note : ''}. لطفاً دوباره تلاش کنید.</div>` : '';
    if (step === 1) {
        box.innerHTML = `
            ${rejNote}
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;">
                <span style="background:#6C40C5;color:#fff;width:22px;height:22px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:700;">1</span>
                <span style="color:#fff;font-weight:600;font-size:.9rem;">اطلاعات شخصی</span>
                <span style="color:#555;">—</span>
                <span style="background:rgba(255,255,255,0.1);color:#888;width:22px;height:22px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:.7rem;">2</span>
                <span style="color:#888;font-size:.8rem;">عکس با کارت</span>
            </div>
            <input type="text" id="kycFirst" placeholder="نام" value="${req ? (req.first_name||'') : ''}" style="width:100%;padding:12px;border-radius:10px;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.12);color:#fff;margin-bottom:10px;box-sizing:border-box;">
            <input type="text" id="kycLast" placeholder="نام خانوادگی" value="${req ? (req.last_name||'') : ''}" style="width:100%;padding:12px;border-radius:10px;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.12);color:#fff;margin-bottom:10px;box-sizing:border-box;">
            <input type="email" id="kycEmail" placeholder="ایمیل" value="${req ? (req.email||'') : ''}" style="width:100%;padding:12px;border-radius:10px;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.12);color:#fff;margin-bottom:10px;box-sizing:border-box;" dir="ltr">
            <input type="tel" id="kycPhone" placeholder="شماره تلفن" value="${req ? (req.phone||'') : ''}" style="width:100%;padding:12px;border-radius:10px;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.12);color:#fff;margin-bottom:14px;box-sizing:border-box;" dir="ltr">
            <button onclick="kycSubmitInfo()" id="kycStep1Btn" style="width:100%;padding:13px;border:none;border-radius:12px;background:linear-gradient(135deg,#6C40C5,#EC4899);color:#fff;font-weight:700;cursor:pointer;font-size:.95rem;">
                ادامه <i class="fas fa-arrow-left"></i>
            </button>
            <div id="kycFb" style="text-align:center;margin-top:10px;font-size:.8rem;"></div>`;
    } else {
        box.innerHTML = `
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;">
                <span style="background:rgba(52,211,153,0.2);color:#6EE7B7;width:22px;height:22px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:.7rem;"><i class="fas fa-check" style="font-size:.6rem;"></i></span>
                <span style="color:#888;font-size:.8rem;">اطلاعات شخصی</span>
                <span style="color:#555;">—</span>
                <span style="background:#6C40C5;color:#fff;width:22px;height:22px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:700;">2</span>
                <span style="color:#fff;font-weight:600;font-size:.9rem;">عکس با کارت شناسایی</span>
            </div>
            <p style="color:#aaa;font-size:.8rem;line-height:1.8;margin-bottom:14px;">
                یک عکس از خودتان بگیرید در حالی که <b style="color:#fff;">کارت شناسایی</b> را کنار صورت‌تان نگه داشته‌اید. چهره و اطلاعات کارت باید واضح باشند.
            </p>
            <input type="file" id="kycSelfie" accept="image/*" capture="user" style="display:none;" onchange="kycPreview(this)">
            <button onclick="document.getElementById('kycSelfie').click()" style="width:100%;padding:14px;border:2px dashed rgba(255,255,255,0.25);border-radius:14px;background:rgba(255,255,255,0.03);color:#ccc;cursor:pointer;font-size:.88rem;">
                <i class="fas fa-camera" style="color:#38bdf8;"></i> گرفتن / انتخاب عکس
            </button>
            <div id="kycSelfiePreview" style="margin-top:12px;text-align:center;"></div>
            <button onclick="kycSubmitPhoto()" id="kycStep2Btn" style="width:100%;margin-top:14px;padding:13px;border:none;border-radius:12px;background:linear-gradient(135deg,#22d3a0,#38bdf8);color:#fff;font-weight:700;cursor:pointer;font-size:.95rem;" disabled>
                <i class="fas fa-paper-plane"></i> ارسال برای بررسی
            </button>
            <div id="kycFb" style="text-align:center;margin-top:10px;font-size:.8rem;"></div>`;
    }
}
async function kycSubmitInfo() {
    const fb = document.getElementById('kycFb');
    const btn = document.getElementById('kycStep1Btn');
    const payload = {
        first_name: document.getElementById('kycFirst').value.trim(),
        last_name:  document.getElementById('kycLast').value.trim(),
        email:      document.getElementById('kycEmail').value.trim(),
        phone:      document.getElementById('kycPhone').value.trim()
    };
    if (!payload.first_name || !payload.last_name || !payload.email || !payload.phone) {
        fb.style.color = '#ff6b6b'; fb.textContent = 'همه فیلدها الزامی است'; return;
    }
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    try {
        const res = await fetch(KYC_API + '?action=submit_info', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.success) { renderKycForm(2, payload, false); }
        else { fb.style.color = '#ff6b6b'; fb.textContent = data.message || 'خطا'; btn.disabled = false; btn.innerHTML = 'ادامه <i class="fas fa-arrow-left"></i>'; }
    } catch(e) { fb.style.color = '#ff6b6b'; fb.textContent = 'خطا در ارتباط'; btn.disabled = false; btn.innerHTML = 'ادامه'; }
}
function kycPreview(input) {
    const file = input.files && input.files[0];
    const prev = document.getElementById('kycSelfiePreview');
    const btn = document.getElementById('kycStep2Btn');
    if (!file) return;
    const reader = new FileReader();
    reader.onload = e => {
        prev.innerHTML = `<img src="${e.target.result}" style="max-width:100%;max-height:220px;border-radius:12px;border:2px solid rgba(56,189,248,0.4);">`;
        btn.disabled = false;
    };
    reader.readAsDataURL(file);
}
async function kycSubmitPhoto() {
    const fb = document.getElementById('kycFb');
    const btn = document.getElementById('kycStep2Btn');
    const input = document.getElementById('kycSelfie');
    const file = input.files && input.files[0];
    if (!file) { fb.style.color = '#ff6b6b'; fb.textContent = 'ابتدا عکس را انتخاب کنید'; return; }
    const fd = new FormData();
    fd.append('selfie', file);
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال ارسال...';
    if (typeof window.avaShowUploadProgress === 'function') window.avaShowUploadProgress('در حال آپلود عکس...');
    try {
        const data = (typeof window.avaUploadWithProgress === 'function')
            ? await window.avaUploadWithProgress(KYC_API + '?action=submit_photo', fd)
            : await (await fetch(KYC_API + '?action=submit_photo', { method: 'POST', body: fd })).json();
        if (data.success) { if (typeof window.avaHideUploadProgress === 'function') window.avaHideUploadProgress(true, 'ارسال شد ✅'); loadKyc(); }
        else { if (typeof window.avaHideUploadProgress === 'function') window.avaHideUploadProgress(false); fb.style.color = '#ff6b6b'; fb.textContent = data.message || 'خطا'; btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> ارسال برای بررسی'; }
    } catch(e) { if (typeof window.avaHideUploadProgress === 'function') window.avaHideUploadProgress(false); fb.style.color = '#ff6b6b'; fb.textContent = 'خطا در ارتباط'; btn.disabled = false; btn.innerHTML = 'ارسال'; }
}

/* ---------- حالت جاسازی: بازکردن مستقیم بخش موردنظر ---------- */
<?php if ($__embed && $__sec === 'kyc'): ?> apToggleKyc(); document.getElementById('apKycSection').style.display='block'; loadKyc(); apKycLoaded = true;
<?php elseif ($__embed && $__sec === 'edit'): ?> document.getElementById('apEditSection').style.display='block';
<?php elseif ($__embed && $__sec === 'notifications'): ?> document.getElementById('apNotifSection').style.display='block';
<?php endif; ?>

/* ---------- خروج از حساب ---------- */
function apShowLogoutConfirm() {
    document.getElementById('apLogoutOverlay').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function apCloseLogoutConfirm() {
    document.getElementById('apLogoutOverlay').style.display = 'none';
    document.body.style.overflow = '';
}
async function apLogoutNow() {
    apCloseLogoutConfirm();
    apToast('در حال خروج...');
    try {
        await fetch('api/logout.php?all=1&t=' + Date.now(), { method:'GET', credentials:'include', cache:'no-store' });
    } catch(e) { console.warn('Server logout failed:', e); }
    try { localStorage.clear(); sessionStorage.clear(); } catch(e) {}
    document.cookie.split(";").forEach(function(c){
        var name = c.replace(/^ +/, "").replace(/=.*/, "");
        var exp = "=;expires=" + new Date(0).toUTCString();
        document.cookie = name + exp + ";path=/";
        document.cookie = name + exp + ";path=/ledor/";
    });
    try {
        if ('caches' in window) {
            var keys = await caches.keys();
            await Promise.all(keys.map(function(k){ return caches.delete(k); }));
        }
    } catch(e) {}
    window.location.replace('login.php?logout=success&t=' + Date.now());
}
</script>
</body>
</html>
