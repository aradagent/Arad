<?php
require_once __DIR__ . '/includes/session_boot.php';   // سشن ماندگار ۳۰ روزه
require_once __DIR__ . '/includes/logo_helper.php';

/* ------------------------------------------------------------------
 * ورود خودکار (Persistent Login)
 * ------------------------------------------------------------------
 * اگر کاربر قبلاً وارد شده و کوکی auth_token معتبر دارد، سشن از روی
 * توکن بازسازی و مستقیم به داشبورد هدایت می‌شود — بدون نیاز به وارد
 * کردن دوباره‌ی آیدی و کد تأیید. این همان چیزی است که در مرورگرهای
 * درون‌برنامه‌ای (مثل مرورگر تلگرام) لازم است.
 * همچنین avapay_restore_session_from_token حضور کاربر را در لیست
 * «وضعیت آنلاین و زمان ورود» ثبت می‌کند.
 * ---------------------------------------------------------------- */
if (!headers_sent()) {
    $__dbLogin = __DIR__ . '/config/database.php';
    if (file_exists($__dbLogin)) {
        @require_once $__dbLogin;
        if (isset($conn) && ($conn instanceof mysqli)) {
            // اگر کاربر تازه خروج زده، هرگز ورود خودکار انجام نده و
            // باقی‌مانده‌ی سشن/کوکی را هم پاک کن (لایه‌ی اطمینان)
            if (isset($_GET['logout'])) {
                $_SESSION = [];
                @session_destroy();
                $__sec = (
                    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                    (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
                );
                @setcookie('auth_token', '', ['expires' => time() - 42000, 'path' => '/', 'secure' => $__sec, 'httponly' => false, 'samesite' => 'Lax']);
                @setcookie('user_id', '', ['expires' => time() - 42000, 'path' => '/', 'secure' => $__sec, 'httponly' => false, 'samesite' => 'Lax']);
                header('Cache-Control: no-store, no-cache, must-revalidate');
            } elseif (avapay_restore_session_from_token($conn) && !empty($_SESSION['user_id'])) {
                header('Location: dashboard.php');
                exit();
            }
        }
    }
}

/* ------------------------------------------------------------------
 * ضربان‌ساز هشدار قیمت روی صفحه‌ی عمومی لاگین
 * ------------------------------------------------------------------
 * هشدارها را هر بازدیدکننده‌ای (حتی بدون ورود) پیش می‌برد، نه فقط
 * کاربران واردشده به داشبورد. این وابستگی به «باز کردن اپ» را کم می‌کند.
 * راه‌حل اصلی همچنان کران‌جاب است، اما این یک لایه‌ی پشتیبان است.
 * ---------------------------------------------------------------- */
if (!headers_sent()) {
    $__db = __DIR__ . '/config/database.php';
    if (file_exists($__db)) {
        @require_once $__db;
        if (isset($conn) && ($conn instanceof mysqli)) {
            @include __DIR__ . '/includes/price_alert_heartbeat.php';
        }
    }
}
$appLogo = getAppLogo();
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
    <title>AvaPay - Login</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
    <link rel="manifest" href="manifest.php">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#1A0B2E">
    <meta name="color-scheme" content="dark">
    <?php $__appIcon = ($appLogo ? htmlspecialchars($appLogo['url']) : '/ledor/AVAPAY.PNG?v=' . (@filemtime(__DIR__ . '/AVAPAY.PNG') ?: time())); ?>
    <link rel="icon" type="image/png" href="<?php echo $__appIcon; ?>">
    <link rel="apple-touch-icon" href="<?php echo $__appIcon; ?>">
    <style>
        .loading {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: radial-gradient(120% 120% at 50% 0%, #241556 0%, #150C36 45%, #0C0620 100%);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            justify-content: center;
            align-items: center;
            z-index: 10000;
        }
        
        /* طراحی اسپینر از assets/css/style.css (.loading-spinner) به ارث می‌رسد؛
           این بازنویسی قدیمی که آن را به یک دایره‌ی ساده تبدیل می‌کرد حذف شد. */
        
        .toast {
            position: fixed;
            bottom: 100px;
            left: 50%;
            transform: translateX(-50%) translateY(100px);
            background: rgba(26, 11, 46, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 15px 20px;
            color: white;
            font-weight: 500;
            z-index: 1000;
            opacity: 0;
            transition: transform 0.3s, opacity 0.3s;
            max-width: 90%;
            text-align: center;
        }
        
        .toast.show {
            transform: translateX(-50%) translateY(0);
            opacity: 1;
        }
        
        .toast.success { border-left: 4px solid #4CD964; }
        .toast.error { border-left: 4px solid #FF3B30; }
        .toast.info { border-left: 4px solid #6C40C5; }
        .toast.warning { border-left: 4px solid #FFC107; }
        
        /* ================= دکمه‌ی شناور نصب (دایره‌ای، تشخیص خودکار دستگاه) ================= */
        .ava-install-fab{
            position:fixed;bottom:26px;inset-inline-end:20px;z-index:9000;
            width:60px;height:60px;border-radius:50%;border:none;cursor:pointer;
            display:none;align-items:center;justify-content:center;
            background:linear-gradient(135deg,#7C3AED,#A855F7);color:#fff;
            box-shadow:0 10px 28px rgba(124,58,237,.55);
            font-size:1.3rem;
        }
        .ava-install-fab::before{
            content:'';position:absolute;inset:-6px;border-radius:50%;
            border:2px solid rgba(168,85,247,.55);
            animation:avaInstallPulse 2.2s ease-out infinite;
        }
        @keyframes avaInstallPulse{
            0%{transform:scale(.85);opacity:.9}
            80%{transform:scale(1.35);opacity:0}
            100%{transform:scale(1.35);opacity:0}
        }
        .ava-install-fab:active{transform:scale(.94);}
        .ava-install-fab .ava-install-ring{position:absolute;inset:0;transform:rotate(-90deg);}
        .ava-install-fab .ava-install-ring circle{
            fill:none;stroke-width:3;stroke-linecap:round;
        }
        .ava-install-fab .ava-install-ring .track{stroke:rgba(255,255,255,.18);}
        .ava-install-fab .ava-install-ring .bar{
            stroke:#FFD93D;stroke-dasharray:163;stroke-dashoffset:163;
            transition:stroke-dashoffset .25s linear;
        }
        .ava-install-fab .ava-install-ico{position:relative;z-index:1;}

        .ava-install-panel{
            position:fixed;bottom:96px;inset-inline-end:20px;z-index:9001;
            width:min(300px, calc(100vw - 40px));
            background:linear-gradient(160deg,rgba(40,22,74,.97),rgba(18,10,38,.98));
            border:1px solid rgba(255,255,255,.14);border-radius:20px;
            padding:18px;box-shadow:0 20px 50px rgba(0,0,0,.55);
            opacity:0;transform:translateY(12px) scale(.96);pointer-events:none;
            transition:opacity .2s ease,transform .2s ease;
        }
        .ava-install-panel.open{opacity:1;transform:none;pointer-events:auto;}
        .ava-install-panel-title{color:#fff;font-weight:800;font-size:.88rem;margin-bottom:6px;display:flex;align-items:center;gap:7px;}
        .ava-install-panel-title i{color:#A855F7;}
        .ava-install-panel-desc{color:rgba(255,255,255,.6);font-size:.7rem;line-height:1.8;margin-bottom:14px;}
        .ava-install-status{
            display:flex;align-items:center;gap:10px;
            background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);
            border-radius:14px;padding:10px 12px;font-size:.75rem;color:#fff;font-weight:700;
        }
        .ava-install-status .pct{margin-inline-start:auto;color:#FFD93D;font-weight:800;}
        .ava-install-status.done{color:#4CD964;}
        .ava-install-status.done i{color:#4CD964;}
        .ava-install-ios-steps{margin-top:12px;font-size:.68rem;color:rgba(255,255,255,.65);line-height:1.9;}
        .ava-install-ios-steps b{color:#fff;}
        .ava-install-step-line{margin-bottom:10px;}
        .ava-install-step-line:last-child{margin-bottom:0;}

        /* مودال آموزش iOS — قبل از شروع نصب */
        .ava-install-tut-overlay{
            display:none;position:fixed;inset:0;z-index:9500;
            background:rgba(6,3,18,.88);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);
            align-items:center;justify-content:center;padding:20px;
        }
        .ava-install-tut-overlay.open{display:flex;}
        .ava-install-tut-card{
            width:100%;max-width:360px;
            background:linear-gradient(160deg,rgba(45,24,82,.98),rgba(18,10,38,.99));
            border:1px solid rgba(255,255,255,.14);border-radius:22px;
            padding:26px 22px;text-align:center;
            box-shadow:0 24px 60px rgba(0,0,0,.55);
        }
        .ava-install-tut-icon{
            width:56px;height:56px;border-radius:50%;margin:0 auto 14px;
            background:linear-gradient(135deg,#7C3AED,#A855F7);color:#fff;font-size:1.4rem;
            display:flex;align-items:center;justify-content:center;
            box-shadow:0 10px 26px rgba(124,58,237,.5);
        }
        .ava-install-tut-title{color:#fff;font-weight:800;font-size:.95rem;margin-bottom:14px;}
        .ava-install-tut-steps{
            text-align:right;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);
            border-radius:14px;padding:14px 16px;font-size:.75rem;color:rgba(255,255,255,.75);
            line-height:2;margin-bottom:18px;
        }
        .ava-install-tut-steps b{color:#fff;}
        .ava-install-tut-btn{
            width:100%;padding:13px;border:0;border-radius:14px;cursor:pointer;
            font-family:inherit;font-size:.85rem;font-weight:800;color:#fff;
            background:linear-gradient(135deg,#7C3AED,#A855F7);
            box-shadow:0 10px 26px rgba(124,58,237,.45);
        }
        
        /* Verification Modal */
        .verification-modal {
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
            z-index: 20000;
            padding: 20px;
        }
        
        .verification-modal.active {
            display: flex;
            animation: fadeInModal 0.3s ease;
        }
        
        @keyframes fadeInModal {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .verification-content {
            background: linear-gradient(135deg, #2d1b3e, #1a0b2e);
            border: 2px solid var(--accent-purple);
            border-radius: 30px;
            padding: 30px;
            max-width: 400px;
            width: 100%;
            text-align: center;
            box-shadow: 0 20px 60px rgba(108, 64, 197, 0.3);
            animation: slideUpModal 0.4s ease;
            position: relative;
            overflow: hidden;
            transition: transform .7s cubic-bezier(.34,1.4,.64,1), box-shadow .5s ease;
        }

        /* ===== انیمیشن ورود موفق ===== */
        .verification-content.success-spin {
            transform: rotateY(360deg) scale(1.03);
            box-shadow: 0 25px 80px rgba(34, 197, 94, .45);
            border-color: #22C55E;
        }
        .verify-success {
            position: absolute;
            inset: 0;
            background: linear-gradient(150deg, #05261a 0%, #0b3b2a 45%, #123f2c 100%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 6px;
            opacity: 0;
            visibility: hidden;
            transition: opacity .45s ease;
            z-index: 5;
        }
        .verify-success.show { opacity: 1; visibility: visible; }

        .vs-rings { position: absolute; width: 150px; height: 150px; }
        .vs-rings span {
            position: absolute; inset: 0; border-radius: 50%;
            border: 2px solid rgba(34, 197, 94, .5);
            animation: vsRing 2s ease-out infinite;
        }
        .vs-rings span:nth-child(2) { animation-delay: .45s; }
        .vs-rings span:nth-child(3) { animation-delay: .9s; }
        @keyframes vsRing {
            0%   { transform: scale(.5); opacity: .9; }
            100% { transform: scale(1.7); opacity: 0; }
        }

        .vs-check {
            width: 82px; height: 82px; border-radius: 50%;
            background: linear-gradient(135deg, #059669, #22C55E);
            display: flex; align-items: center; justify-content: center;
            font-size: 2.3rem; color: #fff; margin-bottom: 14px;
            box-shadow: 0 10px 34px rgba(34, 197, 94, .55);
            transform: scale(0) rotate(-180deg);
            animation: vsPop .75s cubic-bezier(.34,1.56,.64,1) .25s forwards;
        }
        @keyframes vsPop {
            0%   { transform: scale(0) rotate(-180deg); }
            65%  { transform: scale(1.22) rotate(12deg); }
            100% { transform: scale(1) rotate(0); }
        }

        .vs-title {
            font-size: 1.45rem; font-weight: 900; color: #fff;
            opacity: 0; transform: translateY(14px);
            animation: vsUp .55s ease .75s forwards;
            text-shadow: 0 3px 14px rgba(0,0,0,.4);
        }
        .vs-sub {
            font-size: .85rem; color: rgba(255,255,255,.75);
            opacity: 0; transform: translateY(14px);
            animation: vsUp .55s ease .95s forwards;
        }
        @keyframes vsUp { to { opacity: 1; transform: translateY(0); } }

        .vs-bar {
            width: 165px; height: 5px; border-radius: 4px;
            background: rgba(255,255,255,.14); overflow: hidden; margin-top: 18px;
            opacity: 0; animation: vsUp .4s ease 1.05s forwards;
        }
        .vs-bar i {
            display: block; height: 100%; width: 0;
            background: linear-gradient(90deg, #22C55E, #86EFAC);
            border-radius: 4px;
            animation: vsFill 1.5s ease 1.1s forwards;
        }
        @keyframes vsFill { to { width: 100%; } }
        
        @keyframes slideUpModal {
            from {
                opacity: 0;
                transform: translateY(50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .verification-header {
            margin-bottom: 25px;
        }
        
        .verification-header i {
            font-size: 3rem;
            color: var(--accent-purple);
            margin-bottom: 10px;
        }
        
        .verification-header h2 {
            font-size: 1.5rem;
            color: white;
        }
        
        .verification-header p {
            color: var(--text-gray);
            font-size: 0.9rem;
            margin-top: 10px;
        }
        
        .code-input {
            width: 100%;
            padding: 18px;
            background: rgba(255, 255, 255, 0.08);
            border: 2px solid var(--glass-border);
            border-radius: 20px;
            color: white;
            font-size: 2rem;
            text-align: center;
            letter-spacing: 10px;
            font-weight: bold;
            margin-bottom: 20px;
        }
        
        .code-input:focus {
            outline: none;
            border-color: var(--accent-purple);
            box-shadow: 0 0 0 3px rgba(108, 64, 197, 0.2);
        }
        
        .code-input.error {
            border-color: #FF3B30;
            animation: shake 0.3s ease;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }
        
        .verification-actions {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .verification-actions .btn,
        .verification-actions .btn-secondary {
            flex: 1;
        }
        
        .timer-text {
            color: var(--text-gray);
            font-size: 0.85rem;
            margin-top: 15px;
        }
        
        .timer-seconds {
            color: var(--accent-purple);
            font-weight: bold;
            font-size: 1.1rem;
        }
        
        .resend-btn {
            background: none;
            border: none;
            color: var(--accent-purple);
            cursor: pointer;
            margin-top: 10px;
            font-size: 0.85rem;
        }
        
        .resend-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* Tutorial Modal Styles */
        .tutorial-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.95);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 10001;
            display: none;
            padding: 10px;
        }
        
        .tutorial-content {
            background: #ffffff;
            border-radius: 20px;
            width: 100%;
            max-width: 450px;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
        }
        
        .tutorial-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 20px 20px 0 0;
            position: sticky;
            top: 0;
        }
        
        .close-tutorial {
            position: absolute;
            top: 15px;
            right: 15px;
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 20px;
        }
        
        .steps-section { padding: 20px; background: #f8f9fa; }
        .step-card {
            background: white;
            border-radius: 12px;
            padding: 18px;
            margin-bottom: 15px;
            border-left: 4px solid #667eea;
        }
        .step-number {
            display: inline-flex;
            width: 28px;
            height: 28px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 50%;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
        }
        .tips-section {
            background: linear-gradient(135deg, #fff9e6 0%, #fff3cd 100%);
            padding: 18px;
            margin: 20px;
            border-radius: 12px;
        }
        
        .features {
            display: flex;
            justify-content: space-between;
            margin-top: 30px;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .feature-item {
            flex: 1;
            text-align: center;
            padding: 12px;
                background: rgb(160 180 194);
            border-radius: 12px;
            color: #6C40C5;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.3s ease;
            min-width: 100px;
        }
        
        .feature-item:hover {
            background: rgba(108, 64, 197, 0.2);
            transform: translateY(-2px);
        }
        
        .feature-item i {
            display: block;
            font-size: 1.2rem;
            margin-bottom: 5px;
        }
        
        /* ================ MODAL INSTALL شیشه‌ای + انیمیشنی ================ */
        .glass-install{position:fixed;inset:0;z-index:100000;display:none;align-items:center;justify-content:center;padding:18px}
        .glass-install.open{display:flex}
        .gi-backdrop{position:absolute;inset:0;background:rgba(8,4,20,.72);backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);animation:giFade .35s ease}
        @keyframes giFade{from{opacity:0}to{opacity:1}}
        .gi-card{position:relative;width:100%;max-width:400px;max-height:88vh;overflow-y:auto;
            background:linear-gradient(160deg,rgba(255,255,255,.14),rgba(255,255,255,.05));
            border:1px solid rgba(255,255,255,.22);border-radius:26px;padding:26px 22px 20px;
            backdrop-filter:blur(26px) saturate(160%);-webkit-backdrop-filter:blur(26px) saturate(160%);
            box-shadow:0 24px 60px rgba(0,0,0,.55),inset 0 1px 0 rgba(255,255,255,.28);
            animation:giPop .55s cubic-bezier(.22,1.4,.36,1)}
        @keyframes giPop{0%{opacity:0;transform:translateY(34px) scale(.93)}100%{opacity:1;transform:none}}
        .gi-glow{position:absolute;top:-70px;left:50%;transform:translateX(-50%);width:220px;height:150px;
            background:radial-gradient(circle,rgba(76,175,80,.55),transparent 70%);filter:blur(30px);pointer-events:none;
            animation:giGlow 4s ease-in-out infinite}
        @keyframes giGlow{0%,100%{opacity:.55}50%{opacity:1}}
        .gi-close{position:absolute;top:12px;right:14px;width:34px;height:34px;border-radius:50%;
            background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);color:#fff;font-size:1.4rem;
            line-height:1;cursor:pointer;transition:.2s}
        .gi-close:hover{background:rgba(255,90,110,.3)}
        .gi-head{text-align:center;margin-bottom:16px;position:relative}
        .gi-icon{width:60px;height:60px;margin:0 auto 12px;border-radius:19px;display:flex;align-items:center;justify-content:center;
            background:linear-gradient(135deg,#4CAF50,#2E9E52);font-size:1.6rem;color:#fff;
            box-shadow:0 10px 26px rgba(76,175,80,.45);animation:giFloat 3s ease-in-out infinite}
        @keyframes giFloat{0%,100%{transform:translateY(0)}50%{transform:translateY(-7px)}}
        .gi-head h3{color:#fff;font-size:1.22rem;margin:0 0 5px;font-weight:800}
        .gi-head p{color:rgba(255,255,255,.66);font-size:.8rem;margin:0;line-height:1.6}
        .gi-tabs{display:flex;gap:8px;margin-bottom:16px}
        .gi-tab{flex:1;padding:11px 8px;border-radius:14px;cursor:pointer;font-family:inherit;font-size:.82rem;font-weight:700;
            background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.16);color:rgba(255,255,255,.72);transition:.25s}
        .gi-tab.active{background:linear-gradient(135deg,#4CAF50,#2E9E52);border-color:transparent;color:#fff;
            box-shadow:0 8px 20px rgba(76,175,80,.4)}
        .gi-steps{display:flex;flex-direction:column;gap:10px}
        .gi-onetap{display:flex;align-items:center;justify-content:center;gap:9px;text-decoration:none;
            width:100%;padding:14px;border-radius:16px;font-weight:800;font-size:.92rem;color:#fff;
            background:linear-gradient(135deg,#7C3AED,#A855F7);box-shadow:0 10px 26px rgba(124,58,237,.45);
            border:1px solid rgba(255,255,255,.16);}
        .gi-onetap i{font-size:.85rem}
        .gi-or{display:flex;align-items:center;gap:10px;margin:4px 0 2px;
            color:rgba(255,255,255,.4);font-size:.72rem;}
        .gi-or::before,.gi-or::after{content:'';flex:1;height:1px;background:rgba(255,255,255,.12)}
        .gi-step{display:flex;gap:12px;align-items:flex-start;background:rgba(255,255,255,.07);
            border:1px solid rgba(255,255,255,.13);border-radius:16px;padding:12px 13px;
            opacity:0;animation:giSlide .5s ease forwards;animation-delay:var(--d,0s)}
        @keyframes giSlide{from{opacity:0;transform:translateX(-18px)}to{opacity:1;transform:none}}
        .gi-num{flex:none;width:27px;height:27px;border-radius:9px;display:flex;align-items:center;justify-content:center;
            background:linear-gradient(135deg,#4CAF50,#2E9E52);color:#fff;font-weight:800;font-size:.82rem;
            box-shadow:0 4px 12px rgba(76,175,80,.4)}
        .gi-txt{display:flex;flex-direction:column;gap:2px}
        .gi-txt b{color:#fff;font-size:.87rem;font-weight:700}
        .gi-txt span{color:rgba(255,255,255,.58);font-size:.74rem;line-height:1.5}
        .gi-inline{font-size:.75rem;color:#4CAF50;margin:0 3px}
        .gi-cta{width:100%;margin-top:16px;padding:14px;border:0;border-radius:16px;cursor:pointer;font-family:inherit;
            font-size:.92rem;font-weight:800;color:#fff;background:linear-gradient(135deg,#4CAF50,#2E9E52);
            box-shadow:0 12px 28px rgba(76,175,80,.42);animation:giPulse 2.2s ease-in-out infinite}
        @keyframes giPulse{0%,100%{transform:scale(1)}50%{transform:scale(1.03)}}
        .gi-note{margin-top:13px;text-align:center;color:rgba(255,255,255,.5);font-size:.7rem;line-height:1.6}
        .gi-note i{color:#4CAF50;margin-left:4px}
        
        .image-modal.active {
            display: flex;
            animation: fadeInModal 0.3s ease;
        }
        
        .device-btn.ios {
            background: linear-gradient(135deg, #000000, #1a1a1a);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .device-btn.ios:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.3);
            background: linear-gradient(135deg, #1a1a1a, #2a2a2a);
        }
        
        .device-btn.android {
            background: linear-gradient(135deg, #3DDC84, #2e9c62);
            color: white;
        }
        
        .device-btn.android:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(61, 220, 132, 0.3);
            background: linear-gradient(135deg, #4eea94, #32b06e);
        }
        
        @media (max-width: 768px) {
            .close-image-modal {
                top: -10px;
                right: -10px;
                width: 40px;
                height: 40px;
                font-size: 20px;
            }
            
            .device-buttons {
                flex-direction: column;
                gap: 10px;
            }
            
            .device-btn {
                padding: 12px;
                font-size: 0.9rem;
            }
            
            .image-modal-content {
                padding: 20px;
            }
        }
        
        @media (max-width: 480px) {
            .features { flex-direction: column; }
            .verification-content { padding: 25px; margin: 15px; }
            .code-input { font-size: 1.5rem; letter-spacing: 5px; padding: 15px; }
            .verification-actions { flex-direction: column; }
        }

        /* ==================================================================
           صفحه‌ی لاگین بازطراحی‌شده (Round 62) — ناوبار + هیرو دو ستونه در
           دسکتاپ، فقط کارت لاگین در موبایل. کلاس‌های قدیمی login-container/
           login-card/brand-logo/features/feature-item/input-group/btn عمداً
           همان اسم قبلی نگه داشته شدند (تا هیچ id/onclick جاوااسکریپتی که
           جای دیگر این فایل به آن‌ها وابسته است نشکند) ولی ظاهرشان این‌جا
           کامل بازنویسی شده.
           ================================================================== */
        .ava-lp { position: relative; min-height: 100vh; overflow-x: hidden;
            background:
                linear-gradient(115deg, rgba(10,6,24,.92) 0%, rgba(20,10,40,.72) 42%, rgba(20,10,40,.42) 68%, rgba(10,6,24,.55) 100%),
                url('assets/img/login-bg.jpg?v=<?php echo (int) @filemtime(__DIR__ . '/assets/img/login-bg.jpg'); ?>') no-repeat center 30% / cover;
            background-color: #0a0618; }
        .ava-lp-nav { position: relative; z-index: 5; display: flex; align-items: center; justify-content: space-between;
            padding: 22px clamp(20px, 5vw, 64px); padding-top: max(22px, env(safe-area-inset-top)); }
        .ava-lp-nav-logo { display: flex; align-items: center; gap: 10px; font-weight: 800; font-size: 1.05rem; }
        .ava-lp-nav-logo img { height: 34px; width: auto; display: block; }
        .ava-lp-nav-logo .lp-word { display: flex; flex-direction: column; line-height: 1; }
        .ava-lp-nav-logo .lp-word b { background: linear-gradient(90deg, #6C63FF, #FF4D8D); -webkit-background-clip: text; -webkit-text-fill-color: transparent; letter-spacing: .5px; }
        .ava-lp-nav-logo .lp-word span { font-size: .6rem; letter-spacing: .3em; color: var(--text-gray); margin-top: 1px; }
        .ava-lp-nav-links { display: flex; align-items: center; gap: 34px; list-style: none; }
        .ava-lp-nav-links a { color: rgba(255,255,255,.82); text-decoration: none; font-size: .92rem; transition: color .2s; }
        .ava-lp-nav-links a:hover { color: #fff; }
        .ava-lp-nav-right { display: flex; align-items: center; gap: 14px; }
        .ava-lp-lang { display: flex; align-items: center; gap: 8px; padding: 9px 16px; border-radius: 100px;
            background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.12); color: #fff; font-size: .85rem; cursor: pointer; }
        .ava-lp-hamburger { display: none; width: 40px; height: 40px; border-radius: 12px; align-items: center; justify-content: center;
            background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.12); color: #fff; font-size: 1.1rem; cursor: pointer; }

        .ava-lp-body { position: relative; z-index: 2; display: flex; align-items: center; justify-content: center; gap: 56px;
            max-width: 1280px; margin: 0 auto; padding: 24px clamp(20px, 5vw, 64px) 70px; min-height: calc(100vh - 84px); }

        /* ---- ستون هیرو (فقط دسکتاپ) ---- */
        .ava-lp-hero { flex: 1 1 520px; max-width: 560px; position: relative; }
        .ava-lp-eyebrow { display: inline-flex; align-items: center; gap: 8px; padding: 6px 14px; border-radius: 100px;
            background: rgba(108,99,255,.14); border: 1px solid rgba(108,99,255,.3); color: #C9B8FF; font-size: .75rem; font-weight: 700; letter-spacing: .04em; margin-bottom: 22px; }
        .ava-lp-eyebrow::before { content: ''; width: 7px; height: 7px; border-radius: 50%; background: #FF4D8D; box-shadow: 0 0 8px #FF4D8D; }
        .ava-lp-hero h1 { font-size: clamp(2.1rem, 3.6vw, 2.85rem); font-weight: 800; line-height: 1.16; letter-spacing: -.01em; margin-bottom: 18px; }
        .ava-lp-hero h1 .grad { background: linear-gradient(90deg, #7C6CFF, #FF4D8D); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .ava-lp-hero > p { color: var(--text-gray); font-size: 1.02rem; line-height: 1.75; max-width: 460px; margin-bottom: 34px; }
        .ava-lp-feats { display: flex; gap: 26px; margin-bottom: 44px; flex-wrap: wrap; }
        .ava-lp-feat { display: flex; flex-direction: column; align-items: flex-start; gap: 10px; max-width: 150px; }
        .ava-lp-feat .ic { width: 46px; height: 46px; border-radius: 14px; display: flex; align-items: center; justify-content: center;
            background: rgba(124,108,255,.14); border: 1px solid rgba(124,108,255,.3); color: #9C8CFF; font-size: 1.1rem; }
        .ava-lp-feat b { font-size: .88rem; font-weight: 700; }
        .ava-lp-feat span { font-size: .74rem; color: var(--text-gray); }
        .ava-lp-powered { display: inline-flex; align-items: center; gap: 8px; padding: 9px 16px; border-radius: 100px;
            background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.1); color: rgba(255,255,255,.8); font-size: .78rem; }
        .ava-lp-powered i { color: #7C6CFF; }

        /* ---- کارت لاگین (بازطراحی‌شده) ---- */
        .login-container { min-height: auto; padding: 0; background: none; flex: 0 0 auto; }
        .login-card { width: 380px; max-width: 100%; padding: 34px 30px; border-radius: 26px;
            background: linear-gradient(165deg, rgba(58,26,90,.55), rgba(20,10,38,.7));
            border: 1px solid rgba(255,255,255,.12); backdrop-filter: blur(24px); -webkit-backdrop-filter: blur(24px);
            box-shadow: 0 30px 70px -20px rgba(0,0,0,.65), 0 0 0 1px rgba(255,255,255,.03) inset; }
        .login-card .brand-logo { margin-bottom: 22px; }
        .login-card .brand-logo img { filter: drop-shadow(0 8px 24px rgba(255,77,141,.35)); }
        .login-card .brand-logo h1 { font-size: 1.5rem; }
        .login-card .brand-logo .lp-card-title { font-size: 1.3rem; font-weight: 800; color: #fff; margin: 6px 0 6px; }
        .login-card .brand-logo p { font-size: .85rem; }
        .login-card .input-group label { display: flex; align-items: center; gap: 6px; font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: rgba(255,255,255,.65); }
        .login-card .input-group input { background: rgba(0,0,0,.28); border-radius: 14px; padding: 15px 16px 15px 44px; position: relative; }
        .login-card .input-group { position: relative; }
        .login-card .input-group .ig-ic { position: absolute; left: 16px; top: 42px; color: rgba(255,255,255,.4); pointer-events: none; }
        .login-card .btn { border-radius: 14px; box-shadow: 0 14px 30px -10px rgba(124,64,197,.55); }
        .lp-divider { display: flex; align-items: center; gap: 12px; margin: 22px 0 16px; color: rgba(255,255,255,.4); font-size: .74rem; }
        .lp-divider::before, .lp-divider::after { content: ''; flex: 1; height: 1px; background: rgba(255,255,255,.12); }
        .login-card .features { flex-direction: column; margin-top: 0; gap: 10px; }
        .login-card .feature-item { display: flex !important; align-items: center; gap: 10px; text-align: left; justify-content: flex-start;
            padding: 13px 14px; border-radius: 14px; background: rgba(255,255,255,.05) !important; border: 1px solid rgba(255,255,255,.08);
            font-size: .85rem; font-weight: 600; }
        .login-card .feature-item i { margin-bottom: 0; font-size: 1rem; flex-shrink: 0; }
        .login-card .feature-item.lp-info { cursor: default; background: rgba(255,255,255,.03) !important; align-items: flex-start; }
        .login-card .feature-item.lp-info .lp-info-txt { display: flex; flex-direction: column; gap: 2px; }
        .login-card .feature-item.lp-info .lp-info-txt span { font-weight: 400; color: var(--text-gray); font-size: .72rem; }
        .login-card .feature-item.lp-info i { color: #9C8CFF; margin-top: 2px; }

        @media (max-width: 980px) {
            .ava-lp-hero { display: none; }
            .ava-lp-nav { padding-top: max(30px, calc(env(safe-area-inset-top) + 14px)); }
            .ava-lp-nav-links { display: none; position: absolute; top: 70px; left: 16px; right: 16px; flex-direction: column;
                gap: 2px; background: rgba(20,10,38,.97); border: 1px solid rgba(255,255,255,.12); border-radius: 16px; padding: 10px; z-index: 20; }
            .ava-lp-nav-links.lp-open { display: flex; }
            .ava-lp-nav-links a { padding: 10px 12px; border-radius: 10px; }
            .ava-lp-nav-links a:hover { background: rgba(255,255,255,.06); }
            .ava-lp-hamburger { display: flex; }
            .ava-lp-body { flex-direction: column; padding-top: 8px; min-height: auto; gap: 30px; }
        }
        .ava-lp-mobile-powered { display: none; justify-content: center; margin-top: 8px; }
        @media (max-width: 980px) { .ava-lp-mobile-powered { display: flex; } }

        @media (max-width: 420px) {
            .login-card { padding: 28px 20px; }
            .ava-lp-nav { padding: 18px 16px; }
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/pwa_device_gate.php'; ?>
    <!-- Loading Overlay -->
    <div class="loading" id="loadingOverlay">
        <div class="loading-spinner"></div>
    </div>
    
    <!-- PWA Install Button — دایره‌ای، شناور، تشخیص خودکار iOS/Android -->
    <button class="ava-install-fab" id="avaInstallFab" onclick="avaInstallFabClick()" aria-label="Install App">
        <svg class="ava-install-ring" width="60" height="60" viewBox="0 0 60 60">
            <circle class="track" cx="30" cy="30" r="26"></circle>
            <circle class="bar" id="avaInstallRingBar" cx="30" cy="30" r="26"></circle>
        </svg>
        <i class="fas fa-download ava-install-ico" id="avaInstallFabIcon"></i>
    </button>

    <div class="ava-install-panel" id="avaInstallPanel" dir="rtl">
        <div class="ava-install-panel-title"><i class="fas fa-mobile-screen-button"></i> نصب اپلیکیشن AvaPay در صفحه اصلی</div>
        <div class="ava-install-panel-desc" dir="ltr" style="text-align:right;">Ava Pay — Your smart, secure, and fast digital payment app, always at your fingertips.</div>
        <div class="ava-install-status" id="avaInstallStatus">
            <i class="fas fa-circle-notch"></i>
            <span id="avaInstallStatusText">آماده برای نصب</span>
            <span class="pct" id="avaInstallPct"></span>
        </div>
        <div class="ava-install-ios-steps" id="avaInstallIosSteps" style="display:none;" dir="rtl">
            <div class="ava-install-step-line"><b>بعد از زدن دکمه:</b></div>
            <div class="ava-install-step-line">۱. در پیام «<bdi>Install Profile</bdi>» که تنظیمات گوشی نشان می‌دهد، روی <b><bdi>Allow</bdi></b> یا <b><bdi>Install</bdi></b> بزنید.</div>
            <div class="ava-install-step-line">۲. وارد <bdi>Settings</bdi> شوید و از قسمت <b><bdi>Profile Downloaded</bdi></b> روی نصب <bdi>AvaPay</bdi> بزنید، رمز گوشی‌تان را وارد کنید و نصب کنید.</div>
        </div>
    </div>

    <!-- مودال آموزش نصب iOS — قبل از هر اقدامی نمایش داده می‌شود -->
    <div class="ava-install-tut-overlay" id="avaInstallIosTutModal">
        <div class="ava-install-tut-card" dir="rtl">
            <div class="ava-install-tut-icon"><i class="fab fa-apple"></i></div>
            <div class="ava-install-tut-title">نصب اپلیکیشن AvaPay در صفحه اصلی</div>
            <div class="ava-install-tut-steps" dir="rtl">
                <div class="ava-install-step-line"><b>بعد از زدن دکمه:</b></div>
                <div class="ava-install-step-line">در پیام «<bdi>Install Profile</bdi>» روی <b><bdi>Allow</bdi></b> یا <b><bdi>Install</bdi></b> بزنید.</div>
                <div class="ava-install-step-line">۲. وارد <bdi>Settings</bdi> شوید و از قسمت <b><bdi>Profile Downloaded</bdi></b> روی نصب <bdi>AvaPay</bdi> بزنید و رمز گوشی را وارد کنید و گزینه‌ی <b><bdi>Install</bdi></b> را دوباره بزنید — اپلیکیشن در صفحه‌ی اصلی نصب می‌شود.</div>
            </div>
            <button class="ava-install-tut-btn" onclick="avaInstallIosTutConfirm()"><i class="fas fa-check"></i> فهمیدم</button>
        </div>
    </div>

    <a href="webclip.php" id="avaInstallIosLink" style="display:none;"></a>
    
    <!-- Install Tutorial Modal -->
    <div class="tutorial-modal" id="installTutorialModal">
        <div class="tutorial-content">
            <div class="tutorial-header">
                <h2><i class="fas fa-download"></i> How to Install App</h2>
                <button class="close-tutorial">&times;</button>
            </div>
            <div class="steps-section">
                <div class="step-card">
                    <span class="step-number">1</span>
                    <span class="step-text">Open in Browser</span>
                    <p class="step-desc">Open AvaPay in Safari (iOS) or Chrome (Android)</p>
                </div>
                <div class="step-card">
                    <span class="step-number">2</span>
                    <span class="step-text">Tap Share Button</span>
                    <p class="step-desc">Look for the share icon in your browser menu</p>
                </div>
                <div class="step-card">
                    <span class="step-number">3</span>
                    <span class="step-text">Add to Home Screen</span>
                    <p class="step-desc">Select "Add to Home Screen" from the options</p>
                </div>
                <div class="step-card">
                    <span class="step-number">4</span>
                    <span class="step-text">Launch & Enjoy</span>
                    <p class="step-desc">Open AvaPay from your home screen like a native app</p>
                </div>
            </div>
            <div class="tips-section">
                <div class="tips-title"><i class="fas fa-lightbulb"></i> Tips</div>
                <p>• Works offline after first launch<br>• No app store download needed<br>• Automatic updates</p>
            </div>
        </div>
    </div>
    
    <!-- ================ MODAL INSTALL (شیشه‌ای + انیمیشنی) ================ -->
    <div class="glass-install" id="installImageModal">
        <div class="gi-backdrop" onclick="closeImageModal()"></div>
        <div class="gi-card" role="dialog" aria-modal="true" aria-label="Install App">
            <button class="gi-close" onclick="closeImageModal()" aria-label="Close">&times;</button>

            <div class="gi-glow"></div>

            <div class="gi-head">
                <div class="gi-icon"><i class="fas fa-mobile-screen-button"></i></div>
                <h3>Install AvaPay</h3>
                <p>Add to your Home Screen for a full app experience</p>
            </div>

            <div class="gi-tabs">
                <button class="gi-tab active" data-os="ios" onclick="giTab('ios', this)"><i class="fab fa-apple"></i> iPhone</button>
                <button class="gi-tab" data-os="android" onclick="giTab('android', this)"><i class="fab fa-android"></i> Android</button>
            </div>

            <!-- iOS -->
            <div class="gi-steps" id="giIos">
                <a href="/ledor/webclip.php" class="gi-onetap">
                    <i class="fas fa-bolt"></i>
                    <span>نصب با یک کلیک</span>
                </a>
                <div class="gi-or"><span>یا به‌صورت دستی:</span></div>
                <div class="gi-step" style="--d:.05s">
                    <span class="gi-num">1</span>
                    <div class="gi-txt"><b>Open in Safari</b><span>AvaPay must be opened in Safari</span></div>
                </div>
                <div class="gi-step" style="--d:.13s">
                    <span class="gi-num">2</span>
                    <div class="gi-txt"><b>Tap Share <i class="fas fa-arrow-up-from-bracket gi-inline"></i></b><span>The share icon at the bottom bar</span></div>
                </div>
                <div class="gi-step" style="--d:.21s">
                    <span class="gi-num">3</span>
                    <div class="gi-txt"><b>Add to Home Screen</b><span>Scroll down and choose it</span></div>
                </div>
                <div class="gi-step" style="--d:.29s">
                    <span class="gi-num">4</span>
                    <div class="gi-txt"><b>Tap Add</b><span>The icon appears on your Home Screen</span></div>
                </div>
            </div>

            <!-- Android -->
            <div class="gi-steps" id="giAndroid" style="display:none">
                <div class="gi-step" style="--d:.05s">
                    <span class="gi-num">1</span>
                    <div class="gi-txt"><b>Open in Chrome</b><span>AvaPay must be opened in Chrome</span></div>
                </div>
                <div class="gi-step" style="--d:.13s">
                    <span class="gi-num">2</span>
                    <div class="gi-txt"><b>Tap Menu <i class="fas fa-ellipsis-vertical gi-inline"></i></b><span>Top-right three dots</span></div>
                </div>
                <div class="gi-step" style="--d:.21s">
                    <span class="gi-num">3</span>
                    <div class="gi-txt"><b>Install app</b><span>Or "Add to Home screen"</span></div>
                </div>
                <div class="gi-step" style="--d:.29s">
                    <span class="gi-num">4</span>
                    <div class="gi-txt"><b>Confirm Install</b><span>The icon appears on your Home Screen</span></div>
                </div>
            </div>

            <!-- نصب یک‌کلیکی (اگر مرورگر پشتیبانی کند) -->
            <button class="gi-cta" id="giDirectInstall" style="display:none" onclick="giDirectInstall()">
                <i class="fas fa-download"></i> Install Now
            </button>
            <div class="gi-note"><i class="fas fa-bell"></i> Installing enables notifications while the app is closed</div>
        </div>
    </div>

    <div class="ava-lp">
    <nav class="ava-lp-nav">
        <div class="ava-lp-nav-logo">
            <?php if ($appLogo): ?>
                <img src="<?php echo htmlspecialchars($appLogo['url']); ?>" alt="AvaPay">
            <?php else: ?>
                <i class="fas fa-university" style="font-size:1.6rem;color:#7C6CFF;"></i>
            <?php endif; ?>
            <div class="lp-word"><b>AVA PAY</b><span>EXCHANGE</span></div>
        </div>
        <ul class="ava-lp-nav-links">
            <li><a href="#">Home</a></li>
            <li><a href="#ava-lp-feats-anchor">Features</a></li>
            <li><a href="#">Security</a></li>
            <li><a href="javascript:void(0)" onclick="document.getElementById('installModalBtn')?.click()">Support</a></li>
        </ul>
        <div class="ava-lp-nav-right">
            <div class="ava-lp-lang"><i class="fas fa-globe"></i> English <i class="fas fa-chevron-down" style="font-size:.65rem;"></i></div>
            <button class="ava-lp-hamburger" aria-label="Menu" onclick="document.querySelector('.ava-lp-nav-links').classList.toggle('lp-open')"><i class="fas fa-bars"></i></button>
        </div>
    </nav>

    <div class="ava-lp-body">
        <div class="ava-lp-hero">
            <span class="ava-lp-eyebrow">WELCOME TO AVA PAY</span>
            <h1>The First Currency Exchange<br>System <span class="grad">in Iran</span></h1>
            <p>A secure, fast and reliable platform for currency exchange and digital payments.</p>
            <div class="ava-lp-feats" id="ava-lp-feats-anchor">
                <div class="ava-lp-feat"><div class="ic"><i class="fas fa-shield-alt"></i></div><b>Secure & Encrypted</b><span>Bank-level security</span></div>
                <div class="ava-lp-feat"><div class="ic"><i class="fas fa-bolt"></i></div><b>Fast Transactions</b><span>Instant exchange</span></div>
                <div class="ava-lp-feat"><div class="ic"><i class="fas fa-headset"></i></div><b>24/7 Support</b><span>Always with you</span></div>
            </div>
            <div class="ava-lp-powered"><i class="fas fa-shield-halved"></i> Powered by Arad Transfer</div>
        </div>

        <div class="login-container">
            <div class="login-card">
                <div class="brand-logo">
                    <?php if ($appLogo): ?>
                        <img data-app-logo src="<?php echo htmlspecialchars($appLogo['url']); ?>" alt="AvaPay Logo" style="max-width:76px;max-height:76px;object-fit:contain;display:block;margin:0 auto 14px;border-radius:22px;">
                    <?php else: ?>
                        <h1><i class="fas fa-university"></i> Ava Pay</h1>
                    <?php endif; ?>
                    <div class="lp-card-title">Login to your account</div>
                    <p>Welcome back! Please enter your details.</p>
                </div>

                <form id="loginForm">
                    <div class="input-group">
                        <label for="telegram_id">AVA ID</label>
                        <i class="fas fa-user ig-ic"></i>
                        <input type="text" id="telegram_id" name="telegram_id" placeholder="Enter your AVA ID" required autocomplete="off">
                    </div>
                    <button type="submit" class="btn" id="loginBtn">
                        <i class="fas fa-arrow-right-to-bracket"></i> Login / Register
                    </button>
                </form>

                <div class="lp-divider">or continue with</div>

                <div class="features">
                    <div class="feature-item" onclick="window.open('https://t.me/aradexchange_bot?start=userid', '_blank')">
                        <i class="fas fa-id-card"></i> Get Ava ID
                    </div>
                    <div class="feature-item" id="installModalBtn" style="cursor:pointer;">
                        <i class="fas fa-download"></i> Install App
                    </div>
                    <div class="feature-item lp-info">
                        <i class="fas fa-shield-alt"></i>
                        <div class="lp-info-txt"><b>Secure & Encrypted Connection</b><span>Your data is protected with bank-level security.</span></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="ava-lp-mobile-powered">
        <div class="ava-lp-powered"><i class="fas fa-shield-halved"></i> Powered by Arad Transfer</div>
    </div>
    </div>
    
    <!-- Verification Modal (2FA) -->
    <div class="verification-modal" id="verificationModal">
        <div class="verification-content">
            <div class="verification-header">
                <i class="fas fa-key"></i>
                <h2>Enter Verification Code</h2>
                <p>A 6-digit code has been sent to your Telegram</p>
            </div>
            
            <input type="text" id="verificationCode" class="code-input" placeholder="••••••" maxlength="6" autocomplete="off">
            
            <div class="verification-actions">
                <button class="btn-secondary" onclick="closeVerificationModal()">Cancel</button>
                <button class="btn" onclick="verifyCode()"><i class="fas fa-check"></i> Verify</button>
            </div>
            
            <div class="timer-text">
                <i class="fas fa-hourglass-half"></i> Code expires in <span class="timer-seconds" id="timerSeconds">300</span> seconds
            </div>
            
            <button class="resend-btn" id="resendBtn" onclick="resendCode()">
                <i class="fas fa-redo"></i> Didn't receive code? Resend
            </button>

            <!-- لایه‌ی انیمیشن ورود موفق -->
            <div class="verify-success" id="verifySuccess">
                <div class="vs-rings"><span></span><span></span><span></span></div>
                <div class="vs-check"><i class="fas fa-check"></i></div>
                <div class="vs-title">خوش آمدید 🎉</div>
                <div class="vs-sub" id="vsSub">در حال ورود به داشبورد…</div>
                <div class="vs-bar"><i></i></div>
            </div>
        </div>
    </div>
    
    <!-- Registration Modal -->
    <div class="modal-overlay" id="registrationModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-user-plus"></i> Complete Registration</h2>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form id="registrationForm" enctype="multipart/form-data">
                    <input type="hidden" id="reg_telegram_id" name="telegram_id">
                    <input type="hidden" id="reg_ref_code" name="ref_code" value="">
                    
                    <div class="form-group">
                        <label for="first_name"><i class="fas fa-user"></i> First Name *</label>
                        <input type="text" id="first_name" name="first_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="last_name"><i class="fas fa-user"></i> Last Name *</label>
                        <input type="text" id="last_name" name="last_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="phone_number"><i class="fas fa-phone"></i> Phone Number *</label>
                        <input type="tel" id="phone_number" name="phone_number" placeholder="+1234567890" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="avatar"><i class="fas fa-camera"></i> Profile Photo (Optional)</label>
                        <input type="file" id="avatar" name="avatar" accept="image/*" onchange="previewAvatar(event)">
                        <div style="text-align: center; margin-top: 10px;">
                            <img id="previewImage" src="#" alt="Preview" style="max-width: 150px; max-height: 150px; border-radius: 50%; border: 3px solid #6C40C5; display: none;">
                        </div>
                    </div>
                    
                    <button type="submit" class="btn" id="registerBtn">
                        <i class="fas fa-check"></i> Complete Registration
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // ==================== GLOBAL VARIABLES ====================
        let deferredPrompt = null;
        let currentTelegramId = '';
        let timerInterval = null;
        
        // ==================== REFERRAL CODE (زیرمجموعه‌گیری) ====================
        // اگر کاربر از لینک دعوت (?ref=CODE) وارد این صفحه شده باشد، کد معرف
        // را در sessionStorage نگه می‌داریم تا حتی اگر کاربر اول AVA ID را
        // امتحان کند و بعد مودال ثبت‌نام باز شود، کد همچنان در دسترس باشد.
        (function captureReferralCode() {
            try {
                const params = new URLSearchParams(window.location.search);
                const refFromUrl = (params.get('ref') || '').trim();
                if (refFromUrl) {
                    sessionStorage.setItem('avapay_ref_code', refFromUrl);
                }
                const storedRef = sessionStorage.getItem('avapay_ref_code') || '';
                const hiddenInput = document.getElementById('reg_ref_code');
                if (hiddenInput) hiddenInput.value = storedRef;
            } catch (e) { /* ignore — عدم وجود کد معرف هرگز نباید ثبت‌نام را بشکند */ }
        })();
        
        // ==================== INITIALIZATION ====================
        document.addEventListener('DOMContentLoaded', function() {
            initPWA();
            checkExistingAuth();
            setupEventListeners();
            initNetworkStatus();
        });
        
        // ==================== PWA FUNCTIONS ====================
        function initPWA() {
            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.register('sw.js')
                    .then(registration => console.log('✅ Service Worker registered:', registration.scope))
                    .catch(error => console.error('❌ Service Worker registration failed:', error));
            }

            window.addEventListener('beforeinstallprompt', (e) => {
                e.preventDefault();
                deferredPrompt = e;
                window.deferredPrompt = e;   // برای مودال نصب شیشه‌ای
                const giCta = document.getElementById('giDirectInstall');
                if (giCta) giCta.style.display = 'block';
                avaInstallShowFab();
            });

            // تایید واقعی نصب (اندروید/کروم) — دقیق‌تر از فقط تکیه به userChoice
            window.addEventListener('appinstalled', () => {
                avaInstallSetProgress(100);
                avaInstallSetStatus('done', 'نصب با موفقیت انجام شد', 'fa-check');
                deferredPrompt = null; window.deferredPrompt = null;
            });

            avaInstallInit();
        }

        /* ===================================================================
           دکمه‌ی شناور نصب — تشخیص خودکار iOS/Android + انیمیشن پیشرفت
           =================================================================== */
        const AVA_INSTALL_RING_LEN = 163; // 2*PI*26، شعاع دایره‌ی SVG

        function avaInstallIsIOS() {
            return /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
        }
        function avaInstallIsStandalone() {
            try {
                if (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) return true;
            } catch (e) {}
            return window.navigator && window.navigator.standalone === true;
        }

        function avaInstallInit() {
            // اگر از قبل به‌صورت اپ نصب‌شده باز شده، دکمه‌ی نصب اصلاً لازم نیست
            if (avaInstallIsStandalone()) return;

            if (avaInstallIsIOS()) {
                // iOS هرگز beforeinstallprompt نمی‌دهد — دکمه بلافاصله نمایش داده می‌شود
                avaInstallShowFab();
            }
            // برای اندروید/کروم، نمایش دکمه به رویداد beforeinstallprompt در initPWA موکول شده
        }

        function avaInstallShowFab() {
            const fab = document.getElementById('avaInstallFab');
            if (fab) fab.style.display = 'flex';
        }

        function avaInstallSetProgress(pct) {
            const bar = document.getElementById('avaInstallRingBar');
            const pctEl = document.getElementById('avaInstallPct');
            if (bar) bar.style.strokeDashoffset = AVA_INSTALL_RING_LEN - (AVA_INSTALL_RING_LEN * Math.min(100, pct) / 100);
            if (pctEl) pctEl.textContent = pct > 0 && pct < 100 ? Math.round(pct) + '%' : '';
        }

        function avaInstallSetStatus(kind, text, icon) {
            const box = document.getElementById('avaInstallStatus');
            const txt = document.getElementById('avaInstallStatusText');
            const ic = box ? box.querySelector('i') : null;
            if (box) box.className = 'ava-install-status' + (kind === 'done' ? ' done' : '');
            if (txt) txt.textContent = text;
            if (ic) ic.className = 'fas ' + (icon || 'fa-circle-notch');
        }

        let avaInstallTimer = null;
        function avaInstallAnimateTo(target, durationMs, onDone) {
            if (avaInstallTimer) clearInterval(avaInstallTimer);
            const start = performance.now();
            const from = 0;
            avaInstallTimer = setInterval(() => {
                const t = Math.min(1, (performance.now() - start) / durationMs);
                avaInstallSetProgress(from + (target - from) * t);
                if (t >= 1) {
                    clearInterval(avaInstallTimer);
                    avaInstallTimer = null;
                    if (onDone) onDone();
                }
            }, 60);
        }

        function avaInstallFabClick() {
            const isIOS = avaInstallIsIOS();

            if (isIOS) {
                // برای iOS اول مودال آموزش نشان داده می‌شود؛ عملیات نصب واقعی
                // فقط بعد از زدن «فهمیدم» شروع می‌شود (avaInstallIosTutConfirm)
                const tut = document.getElementById('avaInstallIosTutModal');
                if (tut) tut.classList.add('open');
                return;
            }

            const panel = document.getElementById('avaInstallPanel');
            if (panel) panel.classList.add('open');
            avaInstallStartAndroid();
        }

        function avaInstallIosTutConfirm() {
            const tut = document.getElementById('avaInstallIosTutModal');
            if (tut) tut.classList.remove('open');

            const panel = document.getElementById('avaInstallPanel');
            const iosSteps = document.getElementById('avaInstallIosSteps');
            if (iosSteps) iosSteps.style.display = 'block';
            if (panel) panel.classList.add('open');

            avaInstallStartIOS();
        }

        function avaInstallStartAndroid() {
            if (!window.deferredPrompt) {
                // مرورگر از نصب مستقیم پشتیبانی نمی‌کند — راهنمای دستی را نشان بده
                avaInstallSetStatus('', 'راهنمای نصب دستی را باز کن', 'fa-circle-info');
                openImageModal();
                return;
            }
            avaInstallSetStatus('', 'در انتظار تایید شما…', 'fa-circle-notch fa-spin');
            avaInstallSetProgress(0);
            window.deferredPrompt.prompt();
            window.deferredPrompt.userChoice.then(choice => {
                if (choice.outcome === 'accepted') {
                    avaInstallSetStatus('', 'در حال نصب…', 'fa-circle-notch fa-spin');
                    avaInstallAnimateTo(95, 1800, () => {
                        // ۹۵٪ نگه داشته می‌شود؛ ۱۰۰٪ واقعی با رویداد appinstalled ثبت می‌شود
                        // (اگر آن رویداد به هر دلیلی دیر برسد، حداکثر بعد از ۴ ثانیه خودمان تکمیل می‌کنیم)
                        setTimeout(() => {
                            avaInstallSetProgress(100);
                            avaInstallSetStatus('done', 'نصب با موفقیت انجام شد', 'fa-check');
                        }, 4000);
                    });
                } else {
                    avaInstallSetStatus('', 'نصب لغو شد', 'fa-circle-xmark');
                    avaInstallSetProgress(0);
                }
                deferredPrompt = null; window.deferredPrompt = null;
            }).catch(() => {
                avaInstallSetStatus('', 'خطا در نصب — دوباره تلاش کنید', 'fa-triangle-exclamation');
            });
        }

        function avaInstallStartIOS() {
            avaInstallSetStatus('', 'در حال آماده‌سازی پروفایل…', 'fa-circle-notch fa-spin');
            avaInstallSetProgress(0);
            // webclip.php با هدر Content-Disposition:attachment پاسخ می‌دهد، پس این
            // کلیک فقط دانلود/نصب پروفایل را شروع می‌کند و از صفحه خارج نمی‌شویم
            const link = document.getElementById('avaInstallIosLink');
            if (link) link.click();
            avaInstallAnimateTo(100, 2200, () => {
                avaInstallSetStatus('done', 'نصب با موفقیت انجام شد', 'fa-check');
            });
        }

        // بستن پنل با کلیک بیرون از آن
        document.addEventListener('click', function (e) {
            const panel = document.getElementById('avaInstallPanel');
            const fab = document.getElementById('avaInstallFab');
            if (!panel || !panel.classList.contains('open')) return;
            if (panel.contains(e.target) || (fab && fab.contains(e.target))) return;
            panel.classList.remove('open');
        });

        
        // ================ توابع نمایش عکس برای iOS و Android ================
        // ---- مودال نصب شیشه‌ای ----
        function openImageModal() {
            const modal = document.getElementById('installImageModal');
            if (!modal) return;
            // تشخیص خودکار سیستم‌عامل و انتخاب تب مناسب
            const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
            const os = isIOS ? 'ios' : 'android';
            giTab(os, document.querySelector('.gi-tab[data-os="' + os + '"]'));
            // اگر مرورگر از نصب مستقیم پشتیبانی می‌کند، دکمه را نشان بده
            const cta = document.getElementById('giDirectInstall');
            if (cta) cta.style.display = window.deferredPrompt ? 'block' : 'none';
            modal.classList.add('open');
            document.body.style.overflow = 'hidden';
        }

        // سوییچ تب iPhone / Android با ری‌استارت انیمیشن مراحل
        function giTab(os, el) {
            const ios = document.getElementById('giIos');
            const and = document.getElementById('giAndroid');
            if (ios) ios.style.display = (os === 'ios') ? 'flex' : 'none';
            if (and) and.style.display = (os === 'android') ? 'flex' : 'none';
            document.querySelectorAll('.gi-tab').forEach(t => t.classList.remove('active'));
            if (el) el.classList.add('active');
            // ری‌استارت انیمیشن ورود مراحل
            const box = (os === 'ios') ? ios : and;
            if (box) box.querySelectorAll('.gi-step').forEach(st => {
                st.style.animation = 'none';
                void st.offsetWidth;
                st.style.animation = '';
            });
        }

        // نصب یک‌کلیکی (اندروید/کروم)
        async function giDirectInstall() {
            if (!window.deferredPrompt) return;
            window.deferredPrompt.prompt();
            try {
                const { outcome } = await window.deferredPrompt.userChoice;
                if (outcome === 'accepted') {
                    showToast('App installed successfully', 'success');
                    closeImageModal();
                }
            } catch (e) {}
            window.deferredPrompt = null;
            const cta = document.getElementById('giDirectInstall');
            if (cta) cta.style.display = 'none';
        }

        function closeImageModal() {
            const modal = document.getElementById('installImageModal');
            if (modal) {
                modal.classList.remove('open');
                document.body.style.overflow = '';
            }
        }
        
        // ==================== AUTH FUNCTIONS ====================
        async function checkExistingAuth() {
            let token = localStorage.getItem('auth_token') || getCookie('auth_token');
            let userId = localStorage.getItem('user_id') || getCookie('user_id');
            
            if (!token && !userId) return false;
            
            try {
                const response = await fetch('api/check-auth.php?' + new URLSearchParams({ token: token || '', user_id: userId || '' }), {
                    method: 'GET',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const result = await response.json();
                
                if (result.authenticated) {
                    if (result.user) {
                        localStorage.setItem('user_data', JSON.stringify(result.user));
                        localStorage.setItem('user_id', result.user.id);
                        setCookie('user_id', result.user.id, 30);
                    }
                    showToast('Welcome back! Redirecting...', 'info');
                    setTimeout(() => window.location.href = 'arad.php', 500);
                    return true;
                } else {
                    clearAuthData();
                    return false;
                }
            } catch (error) {
                console.error('Auth check error:', error);
                clearAuthData();
                return false;
            }
        }
        
        function clearAuthData() {
            localStorage.removeItem('auth_token');
            localStorage.removeItem('user_data');
            localStorage.removeItem('user_id');
            eraseCookie('auth_token');
            eraseCookie('user_id');
        }
        
        function saveAuthData(result) {
            if (result.token) {
                localStorage.setItem('auth_token', result.token);
                setCookie('auth_token', result.token, 30);
            }
            if (result.user) {
                localStorage.setItem('user_data', JSON.stringify(result.user));
                localStorage.setItem('user_id', result.user.id);
                setCookie('user_id', result.user.id, 30);
            } else if (result.user_id) {
                localStorage.setItem('user_id', result.user_id);
                setCookie('user_id', result.user_id, 30);
            }
        }
        
        // ==================== 2FA VERIFICATION FUNCTIONS ====================
        function showVerificationModal() {
            const modal = document.getElementById('verificationModal');
            const codeInput = document.getElementById('verificationCode');
            
            if (!modal) {
                console.error('Verification modal not found!');
                return;
            }
            
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
            
            if (codeInput) {
                codeInput.value = '';
                codeInput.classList.remove('error');
                setTimeout(() => {
                    codeInput.focus();
                }, 100);
            }
            
            startTimer(300);
        }
        
        function closeVerificationModal() {
            const modal = document.getElementById('verificationModal');
            if (modal) modal.classList.remove('active');
            document.body.style.overflow = '';
            if (timerInterval) clearInterval(timerInterval);
        }
        
        function startTimer(seconds) {
            if (timerInterval) clearInterval(timerInterval);
            let remaining = seconds;
            const timerSpan = document.getElementById('timerSeconds');
            const resendBtn = document.getElementById('resendBtn');
            
            timerInterval = setInterval(() => {
                remaining--;
                if (timerSpan) timerSpan.textContent = remaining;
                
                if (remaining <= 0) {
                    clearInterval(timerInterval);
                    if (resendBtn) resendBtn.disabled = false;
                    showToast('Code expired. Please request a new code.', 'warning');
                } else {
                    if (resendBtn) resendBtn.disabled = true;
                }
            }, 1000);
        }
        
        async function verifyCode() {
            const codeInput = document.getElementById('verificationCode');
            const code = codeInput ? codeInput.value.trim() : '';
            
            
            if (codeInput) codeInput.classList.remove('error');
            
            if (!code || code.length !== 6) {
                if (codeInput) codeInput.classList.add('error');
                showToast('❌ Please enter the 6-digit verification code', 'error');
                return;
            }
            
            showLoading();
            
            try {
                
                const response = await fetch('api/verify_code.php', {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ code: code })
                });
                
                
                const result = await response.json();
                
                if (result.success) {
                    if (result.needs_registration) {
                        showToast('✅ Code verified! Please complete registration.', 'success');
                        closeVerificationModal();
                        const regTelegramInput = document.getElementById('reg_telegram_id');
                        if (regTelegramInput) {
                            regTelegramInput.value = result.telegram_id;
                        }
                        openModal('registrationModal');
                    } else {
                        saveAuthData(result);
                        // انیمیشن ورود موفق: چرخش کادر + تیک + خوش‌آمدگویی، سپس داشبورد
                        showVerifySuccess(result);
                    }
                } else {
                    if (codeInput) codeInput.classList.add('error');
                    showToast('❌ ' + (result.message || 'Invalid verification code. Please try again.'), 'error');
                    if (codeInput) {
                        codeInput.value = '';
                        codeInput.focus();
                    }
                }
            } catch (error) {
                console.error('Verification error:', error);
                showToast('❌ Network error: ' + error.message, 'error');
            } finally {
                hideLoading();
            }
        }
        
        // انیمیشن ورود موفق داخل کادر وریفای
        function showVerifySuccess(result){
            const content = document.querySelector('#verificationModal .verification-content');
            const layer   = document.getElementById('verifySuccess');
            const sub     = document.getElementById('vsSub');

            // اگر مارک‌آپ انیمیشن نبود، مثل قبل مستقیم برو
            if (!content || !layer){
                window.location.href = 'dashboard.php';
                return;
            }

            // نام کاربر را در پیام خوش‌آمد نشان بده
            try {
                const u = result && result.user ? result.user : null;
                const name = u ? [u.first_name, u.last_name].filter(Boolean).join(' ').trim() : '';
                if (sub) sub.textContent = name
                    ? name + ' عزیز، در حال ورود به داشبورد…'
                    : 'در حال ورود به داشبورد…';
            } catch(e){}

            // ۱) چرخش کادر  ۲) نمایش لایه موفقیت
            content.classList.add('success-spin');
            setTimeout(() => layer.classList.add('show'), 320);

            // ارتعاش کوتاه (روی موبایل) + صدای موفقیت ملایم
            try { if (navigator.vibrate) navigator.vibrate([25, 45, 25]); } catch(e){}
            try {
                const ac = new (window.AudioContext || window.webkitAudioContext)();
                [660, 880].forEach((f, i) => {
                    const o = ac.createOscillator(), g = ac.createGain();
                    o.connect(g); g.connect(ac.destination);
                    o.frequency.value = f; o.type = 'sine';
                    g.gain.setValueAtTime(0.18, ac.currentTime + i * 0.16);
                    g.gain.exponentialRampToValueAtTime(0.01, ac.currentTime + i * 0.16 + 0.3);
                    o.start(ac.currentTime + i * 0.16);
                    o.stop(ac.currentTime + i * 0.16 + 0.3);
                });
            } catch(e){}

            // ۳) ورود به داشبورد پس از پایان انیمیشن
            setTimeout(() => { window.location.href = 'dashboard.php'; }, 2700);
        }

        async function resendCode() {
            if (!currentTelegramId) {
                showToast('Please try logging in again', 'error');
                const loginForm = document.getElementById('loginForm');
                if (loginForm) loginForm.dispatchEvent(new Event('submit'));
                return;
            }
            
            showLoading();
            
            try {
                const response = await fetch('api/send_verification_code.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ telegram_id: currentTelegramId })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showToast('✅ New verification code sent to your Telegram!', 'success');
                    if (timerInterval) clearInterval(timerInterval);
                    startTimer(300);
                    const codeInput = document.getElementById('verificationCode');
                    if (codeInput) {
                        codeInput.value = '';
                        codeInput.classList.remove('error');
                        codeInput.focus();
                    }
                } else {
                    showToast('❌ ' + (result.message || 'Failed to send code'), 'error');
                }
            } catch (error) {
                console.error('Resend error:', error);
                showToast('❌ Network error. Please try again.', 'error');
            } finally {
                hideLoading();
            }
        }
        
        // ==================== FORM HANDLERS ====================
        function setupEventListeners() {
            document.getElementById('loginForm')?.addEventListener('submit', handleLogin);
            document.getElementById('registrationForm')?.addEventListener('submit', handleRegistration);
            document.getElementById('installTutorialBtn')?.addEventListener('click', openInstallTutorialModal);
            
            // EVENT برای دکمه Install (باز کردن مودال با دو دکمه)
            const installModalBtn = document.getElementById('installModalBtn');
            if (installModalBtn) {
                installModalBtn.addEventListener('click', function() {
                    openImageModal();
                });
            }
            
            document.querySelector('.close-modal')?.addEventListener('click', () => closeModal('registrationModal'));
            document.querySelectorAll('.close-tutorial').forEach(btn => {
                btn.addEventListener('click', closeAllTutorialModals);
            });
            document.getElementById('registrationModal')?.addEventListener('click', (e) => {
                if (e.target === e.currentTarget) closeModal('registrationModal');
            });
            document.querySelectorAll('.tutorial-modal').forEach(modal => {
                modal.addEventListener('click', (e) => {
                    if (e.target === e.currentTarget) closeAllTutorialModals();
                });
            });
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    closeModal('registrationModal');
                    closeVerificationModal();
                    closeAllTutorialModals();
                    closeImageModal();
                }
            });
            
            document.getElementById('verificationCode')?.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') verifyCode();
            });
        }
        
        async function handleLogin(e) {
            e.preventDefault();
            const telegramId = document.getElementById('telegram_id').value.trim();
            
            if (!telegramId) {
                showToast('❌ Please enter your AVA ID', 'error');
                return;
            }
            
            currentTelegramId = telegramId;
            
            try {
                showLoading();
                updateButtonState('loginBtn', true, 'Sending code...');
                
                const response = await fetch('api/send_verification_code.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ telegram_id: telegramId })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showToast('✅ Verification code sent to your Telegram!', 'success');
                    showVerificationModal();
                } else {
                    showToast('❌ ' + (result.message || 'Failed to send verification code'), 'error');
                }
            } catch (error) {
                console.error('Login error:', error);
                showToast('❌ Network error. Please try again.', 'error');
            } finally {
                hideLoading();
                updateButtonState('loginBtn', false, 'Login / Register');
            }
        }
        
        async function handleRegistration(e) {
            e.preventDefault();
            
            const firstName = document.getElementById('first_name').value.trim();
            const lastName = document.getElementById('last_name').value.trim();
            const phoneNumber = document.getElementById('phone_number').value.trim();
            
            if (!firstName || !lastName || !phoneNumber) {
                showToast('❌ Please fill all required fields', 'error');
                return;
            }
            
            try {
                showLoading();
                updateButtonState('registerBtn', true, 'Processing...');
                
                const formData = new FormData(e.target);
                const response = await fetch('api/register.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showToast('✅ Registration successful! Redirecting...', 'success');
                    var hasBonus = result.referral_bonus_eur && parseFloat(result.referral_bonus_eur) > 0;
                    if (hasBonus) {
                        setTimeout(function () {
                            showToast('🎁 ' + parseFloat(result.referral_bonus_eur).toFixed(2) + '€ خوش‌آمدگویی به کیف‌پول شما اضافه شد!', 'success');
                        }, 900);
                    }
                    saveAuthData(result);
                    setTimeout(() => window.location.href = 'arad.php', hasBonus ? 2600 : 1500);
                } else {
                    showToast('❌ ' + (result.message || 'Registration failed'), 'error');
                }
            } catch (error) {
                console.error('Registration error:', error);
                showToast('❌ Network error. Please try again.', 'error');
            } finally {
                hideLoading();
                updateButtonState('registerBtn', false, 'Complete Registration');
            }
        }
        
        // ==================== TUTORIAL FUNCTIONS ====================
        function openInstallTutorialModal() {
            closeAllTutorialModals();
            const modal = document.getElementById('installTutorialModal');
            if (modal) {
                modal.style.display = 'flex';
                document.body.style.overflow = 'hidden';
            }
        }
        
        function closeAllTutorialModals() {
            document.querySelectorAll('.tutorial-modal').forEach(modal => {
                modal.style.display = 'none';
            });
            document.body.style.overflow = '';
        }
        
        // ==================== NETWORK FUNCTIONS ====================
        function initNetworkStatus() {
            window.addEventListener('online', () => showToast('You are back online', 'success'));
            window.addEventListener('offline', () => showToast('You are offline. Some features may not work.', 'warning'));
        }
        
        // ==================== UTILITY FUNCTIONS ====================
        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'flex';
                document.body.style.overflow = 'hidden';
            }
        }
        
        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = '';
                const form = document.getElementById('registrationForm');
                if (form) form.reset();
                const preview = document.getElementById('previewImage');
                if (preview) preview.style.display = 'none';
            }
        }
        
        function showLoading() {
            document.getElementById('loadingOverlay').style.display = 'flex';
        }
        
        function hideLoading() {
            document.getElementById('loadingOverlay').style.display = 'none';
        }
        
        function updateButtonState(buttonId, isLoading, text) {
            const button = document.getElementById(buttonId);
            if (button) {
                button.disabled = isLoading;
                button.innerHTML = isLoading 
                    ? `<i class="fas fa-spinner fa-spin"></i> ${text}`
                    : text;
            }
        }
        
        function previewAvatar(event) {
            const input = event.target;
            const preview = document.getElementById('previewImage');
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = (e) => {
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                };
                reader.readAsDataURL(input.files[0]);
            } else {
                preview.style.display = 'none';
            }
        }
        
        function setCookie(name, value, days) {
            let expires = "";
            if (days) {
                const date = new Date();
                date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
                expires = "; expires=" + date.toUTCString();
            }
            document.cookie = `${name}=${encodeURIComponent(value || "")}${expires}; path=/; SameSite=Lax`;
        }
        
        function getCookie(name) {
            const nameEQ = name + "=";
            const ca = document.cookie.split(';');
            for(let i = 0; i < ca.length; i++) {
                let c = ca[i];
                while (c.charAt(0) === ' ') c = c.substring(1, c.length);
                if (c.indexOf(nameEQ) === 0) return decodeURIComponent(c.substring(nameEQ.length, c.length));
            }
            return null;
        }
        
        function eraseCookie(name) {
            document.cookie = name + '=; Path=/; Expires=Thu, 01 Jan 1970 00:00:01 GMT;';
        }
        
        function showToast(message, type = 'info') {
            const existingToasts = document.querySelectorAll('.toast');
            existingToasts.forEach(toast => toast.remove());
            
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.textContent = message;
            document.body.appendChild(toast);
            
            setTimeout(() => toast.classList.add('show'), 10);
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }
    </script>
</body>
</html>