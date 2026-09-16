<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
<meta name="theme-color" content="#1A0B2E">
<title>نصب AvaPay روی آیفون</title>
<link rel="icon" type="image/png" href="/ledor/AVAPAY.PNG">
<style>
    * { margin:0; padding:0; box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
    html, body { width:100%; min-height:100%; }
    body {
        font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', 'Helvetica Neue', sans-serif;
        background: radial-gradient(120% 120% at 50% 0%, #241556 0%, #150C36 45%, #0C0620 100%);
        color:#fff; min-height:100vh; display:flex; align-items:center; justify-content:center;
        padding:24px;
    }
    .wrap { width:100%; max-width:400px; text-align:center; }
    .icon {
        width:96px; height:96px; border-radius:24px; margin:0 auto 22px; display:block;
        box-shadow:0 16px 40px rgba(124,58,237,.45), 0 0 0 1px rgba(255,255,255,.12);
    }
    h1 { font-size:1.35rem; font-weight:800; margin-bottom:10px; }
    p.lead { color:rgba(255,255,255,.6); font-size:.9rem; line-height:1.9; margin-bottom:28px; }
    .install-btn {
        display:flex; align-items:center; justify-content:center; gap:10px;
        width:100%; padding:17px; border-radius:18px; text-decoration:none;
        background:linear-gradient(135deg,#7C3AED,#A855F7); color:#fff;
        font-size:1.05rem; font-weight:800;
        box-shadow:0 14px 34px rgba(124,58,237,.5);
        border:1px solid rgba(255,255,255,.18);
        transition:transform .15s ease;
    }
    .install-btn:active { transform:scale(.97); }
    .steps {
        margin-top:30px; text-align:right;
        background:linear-gradient(160deg,rgba(255,255,255,.08),rgba(255,255,255,.03));
        border:1px solid rgba(255,255,255,.12); border-radius:20px; padding:18px 20px;
    }
    .steps .steps-title { font-size:.82rem; font-weight:700; color:rgba(255,255,255,.75); margin-bottom:12px; }
    .step { display:flex; align-items:flex-start; gap:10px; margin-bottom:12px; font-size:.8rem; color:rgba(255,255,255,.62); line-height:1.8; }
    .step:last-child { margin-bottom:0; }
    .step-num {
        flex-shrink:0; width:22px; height:22px; border-radius:50%; background:rgba(168,85,247,.22);
        color:#c084fc; font-size:.72rem; font-weight:800; display:flex; align-items:center; justify-content:center;
    }
    .note { margin-top:18px; font-size:.7rem; color:rgba(255,255,255,.35); line-height:1.8; }
</style>
</head>
<body>
    <div class="wrap">
        <img class="icon" src="/ledor/apple-touch-icon.png" alt="AvaPay">
        <h1>نصب AvaPay روی آیفون</h1>
        <p class="lead">با زدن دکمه‌ی زیر، آیکون AvaPay مثل یک اپ واقعی (بدون نوار آدرس سافاری) به صفحه اصلی گوشی شما اضافه می‌شود.</p>

        <a class="install-btn" href="/ledor/webclip.php">
            <span>نصب با یک کلیک</span>
        </a>

        <div class="steps">
            <div class="steps-title">بعد از زدن دکمه:</div>
            <div class="step"><span class="step-num">۱</span><span>در پیام «Install Profile»‌ که تنظیمات گوشی نشان می‌دهد، روی <b>Install</b> بزنید.</span></div>
            <div class="step"><span class="step-num">۲</span><span>رمز گوشی یا فیس‌آیدی را برای تایید نهایی وارد کنید.</span></div>
            <div class="step"><span class="step-num">۳</span><span>آیکون AvaPay روی صفحه اصلی گوشی‌تان ظاهر می‌شود — همین!</span></div>
        </div>

        <p class="note">این پروفایل امضا نشده و ممکن است برچسب «Not Verified» ببینید — این فقط یک هشدار استاندارد اپل برای هر پروفایل امضانشده است و کاملاً بی‌خطر است. هر زمان بخواهید، از Settings › General › VPN &amp; Device Management می‌توانید آن را حذف کنید.</p>
    </div>
</body>
</html>
