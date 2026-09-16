<?php
/**
 * includes/pwa_device_gate.php
 * ---------------------------------------------------------------
 * ورود فقط از موبایل/تبلت مجاز است، یا از دسکتاپ فقط وقتی
 * اپلیکیشن به‌صورت PWA نصب‌شده (Standalone) باز شده باشد. اگر کسی از
 * یک مرورگر معمولیِ دسکتاپ (تب معمولی کروم/اج/فایرفاکس و غیره — نه
 * اپ نصب‌شده) وارد شود، به‌جای فرم ورود، یک کارت خوشگل با راهنمای
 * نصب اپلیکیشن روی کل صفحه نمایش داده می‌شود.
 *
 * تشخیص «نصب‌شده بودن» (display-mode: standalone) فقط در مرورگر خودِ
 * کاربر ممکن است، نه در سمت سرور — پس این گیت کاملاً سمت کلاینت
 * (جاوااسکریپت) کار می‌کند. include این فایل بلافاصله بعد از باز شدن
 * <body> کافی است؛ خودش یک overlay تمام‌صفحه با z-index بالا می‌سازد
 * که در صورت لازم بودن، کل صفحه‌ی زیرین را می‌پوشاند و امکان تعامل با
 * آن را می‌گیرد.
 *
 * برای صفحاتی که پیش از تعامل کاربر هم کاری خودکار انجام می‌دهند
 * (مثل ورود خودکار در telegram_entry.php)، از متغیر سراسری
 * `window.__avaGateBlocked` استفاده کنید تا آن اسکریپت خودکار، در
 * صورت مسدود بودن، اصلاً اجرا نشود.
 * ---------------------------------------------------------------
 */
if (!function_exists('getAppLogo')) {
    require_once __DIR__ . '/logo_helper.php';
}
$__avaGateLogo = getAppLogo();
$__avaGateIcon = ($__avaGateLogo ? htmlspecialchars($__avaGateLogo['url']) : '/ledor/AVAPAY.PNG');
?>
<div id="avaGateOverlay" style="display:none;position:fixed;inset:0;z-index:2147483000;background:radial-gradient(circle at 30% 20%,#2d1454 0%,#1A0B2E 55%,#150822 100%);color:#fff;font-family:'Segoe UI',Tahoma,sans-serif;align-items:center;justify-content:center;padding:20px;overflow-y:auto;">
    <div style="max-width:420px;width:100%;margin:auto;text-align:center;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:26px;padding:32px 26px;box-shadow:0 20px 60px rgba(0,0,0,0.45);">
        <img src="<?php echo $__avaGateIcon; ?>" alt="AvaPay" style="width:76px;height:76px;border-radius:20px;box-shadow:0 10px 30px rgba(108,64,197,.5);margin-bottom:18px;">
        <div style="font-size:1.15rem;font-weight:800;margin-bottom:10px;">📲 برای ورود، اپلیکیشن AVA PAY را نصب کنید</div>
        <div style="font-size:.86rem;line-height:2;color:#cfc4e0;margin-bottom:20px;">
            برای امنیت و تجربه‌ی بهتر، AVA PAY فقط از طریق <b style="color:#fff;">موبایل یا تبلت</b>، یا از دسکتاپ فقط به‌صورت <b style="color:#fff;">اپلیکیشن نصب‌شده</b> در دسترس است — نه از داخل یک تب معمولیِ مرورگر.
        </div>

        <button id="avaGateInstallBtn" onclick="window.__avaGateInstall && window.__avaGateInstall()" style="display:none;width:100%;padding:14px;border:none;border-radius:16px;background:linear-gradient(135deg,#6C40C5,#8B5CF6);color:#fff;font-size:.9rem;font-weight:700;cursor:pointer;margin-bottom:14px;box-shadow:0 8px 24px rgba(108,64,197,.4);">
            ⬇️ نصب اپلیکیشن روی دسکتاپ
        </button>

        <div style="text-align:right;background:rgba(255,255,255,0.05);border-radius:16px;padding:14px 16px;margin-bottom:14px;font-size:.78rem;line-height:2;color:#e4dcf5;">
            <div style="font-weight:700;color:#fff;margin-bottom:6px;">🖥️ نصب دستی روی دسکتاپ (Chrome / Edge):</div>
            ۱. گوشه‌ی راست نوار آدرس، روی آیکون نصب <b>⊕</b> بزنید<br>
            ۲. یا از منوی مرورگر، گزینه‌ی «Install AvaPay…» را انتخاب کنید<br>
            ۳. اپلیکیشن مثل یک برنامه‌ی مستقل روی دسکتاپتان باز می‌شود
        </div>

        <div style="font-size:.78rem;color:rgba(255,255,255,0.5);">
            یا همین لینک را روی <b style="color:#cfc4e0;">گوشی یا تبلت</b> خود باز کنید.
        </div>
    </div>
</div>
<script>
(function () {
    function isMobileOrTabletDevice() {
        var ua = navigator.userAgent || navigator.vendor || '';
        if (/Android|iPhone|iPad|iPod|Windows Phone|BlackBerry|IEMobile|Opera Mini/i.test(ua)) return true;
        // iPadOS 13+ گزارش می‌دهد Macintosh است ولی صفحه‌ی لمسی دارد
        if (/Macintosh/i.test(ua) && navigator.maxTouchPoints && navigator.maxTouchPoints > 1) return true;
        return false;
    }
    function isStandalonePwa() {
        try {
            if (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) return true;
        } catch (e) {}
        if (window.navigator && window.navigator.standalone === true) return true; // iOS Safari نصب‌شده
        return false;
    }

    var blocked = !isMobileOrTabletDevice() && !isStandalonePwa();
    window.__avaGateBlocked = blocked;

    if (blocked) {
        var el = document.getElementById('avaGateOverlay');
        if (el) el.style.display = 'flex';
        document.documentElement.style.overflow = 'hidden';
    }

    // دکمه‌ی نصب یک‌کلیکی دسکتاپ — در مرورگرهایی که beforeinstallprompt را
    // پشتیبانی می‌کنند (کروم/اج دسکتاپ)؛ در غیر این صورت فقط راهنمای دستی
    // بالا نمایش داده می‌شود.
    var deferredInstall = null;
    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        deferredInstall = e;
        var btn = document.getElementById('avaGateInstallBtn');
        if (btn) btn.style.display = 'block';
    });
    window.__avaGateInstall = function () {
        if (!deferredInstall) return;
        deferredInstall.prompt();
        deferredInstall.userChoice.finally(function () { deferredInstall = null; });
    };
})();
</script>
