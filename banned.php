<?php
/**
 * banned.php — صفحه‌ی اعلام مسدودیت حساب.
 *
 * تنها صفحه‌ای (کنار خروج از حساب) که کاربر مسدود به آن دسترسی دارد.
 * عمداً هیچ فرم، دکمه‌ی عملیاتی، یا درخواست AJAX در آن وجود ندارد —
 * فقط اعلام وضعیت و راه خروج.
 */
require_once __DIR__ . '/includes/session_boot.php';
require_once __DIR__ . '/config/database.php';

$userId = (int)($_SESSION['user_id'] ?? 0);

$banReason = (string)($_SESSION['avapay_ban_reason'] ?? '');
$bannedAt  = $_SESSION['avapay_ban_at'] ?? null;
$isBanned  = false;

if ($userId > 0) {
    require_once __DIR__ . '/includes/ban_guard.php';
    $info      = avapay_user_ban_info($conn, $userId);
    $isBanned  = !empty($info['banned']);
    $banReason = $info['reason'] !== '' ? $info['reason'] : $banReason;
    $bannedAt  = $info['at'] ?? $bannedAt;
}

// اگر مسدود نیست (یا مسدودیت برداشته شده) به صفحه‌ی اصلی برگردد
if ($userId > 0 && !$isBanned) {
    header('Location: dashboard.php');
    exit();
}
if ($userId <= 0) {
    header('Location: login.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>حساب مسدود شده | Arad Exchange</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    :root{
        --bg:#070314; --glass:rgba(255,255,255,.05); --line:rgba(255,255,255,.1);
        --ink:#F3EFFB; --mut:rgba(243,239,251,.55); --danger:#FF6B7A; --gold:#F6D68A;
    }
    *{ box-sizing:border-box; }
    body{
        margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
        padding:20px; font-family:'Vazirmatn',system-ui,-apple-system,'Segoe UI',Tahoma,sans-serif;
        color:var(--ink);
        background:
            radial-gradient(70% 50% at 15% -5%, rgba(255,107,122,.16), transparent 60%),
            radial-gradient(60% 45% at 100% 10%, rgba(108,77,255,.14), transparent 55%),
            var(--bg);
    }
    .card{
        width:100%; max-width:440px; text-align:center;
        background:var(--glass); border:1px solid var(--line); border-radius:26px;
        padding:34px 26px 28px;
        backdrop-filter:blur(22px) saturate(150%); -webkit-backdrop-filter:blur(22px) saturate(150%);
        box-shadow:0 40px 90px -34px rgba(0,0,0,.85);
        animation:in .5s cubic-bezier(.16,1,.3,1) both;
    }
    @keyframes in{ from{ opacity:0; transform:translateY(18px) scale(.97); } to{ opacity:1; transform:none; } }
    .icon{
        width:76px; height:76px; margin:0 auto 18px; border-radius:50%;
        display:flex; align-items:center; justify-content:center; font-size:2rem; color:#fff;
        background:linear-gradient(135deg,#FF6B7A,#C2410C);
        box-shadow:0 18px 40px -16px rgba(255,107,122,.7);
    }
    h1{ font-size:1.25rem; margin:0 0 10px; font-weight:800; letter-spacing:-.02em; }
    p{ font-size:.86rem; line-height:1.9; color:var(--mut); margin:0 0 18px; }
    .reason{
        background:rgba(255,107,122,.1); border:1px solid rgba(255,107,122,.28);
        border-radius:14px; padding:12px 14px; margin-bottom:16px;
        font-size:.82rem; text-align:right; color:var(--ink);
    }
    .reason b{ display:block; font-size:.68rem; color:var(--danger); margin-bottom:5px; font-weight:800; }
    .meta{ font-size:.7rem; color:var(--mut); margin-bottom:20px; }
    .btn{
        display:flex; align-items:center; justify-content:center; gap:8px;
        width:100%; padding:13px; border-radius:15px; text-decoration:none;
        font-size:.84rem; font-weight:800; margin-bottom:10px;
        transition:transform .2s cubic-bezier(.16,1,.3,1), filter .2s;
    }
    .btn:hover{ transform:translateY(-2px); filter:brightness(1.08); }
    .btn-support{ background:linear-gradient(135deg,var(--gold),#D9A441); color:#1A0E3D; }
    .btn-logout{ background:rgba(255,255,255,.07); border:1px solid var(--line); color:var(--ink); }
</style>
</head>
<body>
    <div class="card">
        <div class="icon"><i class="fas fa-user-lock"></i></div>
        <h1>حساب کاربری شما مسدود شده است</h1>
        <p>در حال حاضر امکان استفاده از هیچ‌کدام از خدمات پلتفرم برای شما وجود ندارد. برای بررسی وضعیت حساب با پشتیبانی تماس بگیرید.</p>

        <?php if ($banReason !== ''): ?>
        <div class="reason">
            <b>دلیل مسدودسازی</b>
            <?php echo htmlspecialchars($banReason); ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($bannedAt)): ?>
        <div class="meta"><i class="fas fa-clock"></i> تاریخ مسدودسازی: <?php echo htmlspecialchars(date('Y/m/d - H:i', strtotime($bannedAt))); ?></div>
        <?php endif; ?>

        <a class="btn btn-support" href="https://t.me/<?php echo defined('SUPPORT_TELEGRAM') ? SUPPORT_TELEGRAM : 'aradexchange_support'; ?>" target="_blank" rel="noopener">
            <i class="fab fa-telegram"></i> تماس با پشتیبانی
        </a>
        <a class="btn btn-logout" href="logout.php"><i class="fas fa-right-from-bracket"></i> خروج از حساب</a>
    </div>
</body>
</html>
