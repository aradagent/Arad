<?php
/**
 * webclip.php
 * ---------------------------------------------------------------
 * نصب یک‌کلیکیِ AvaPay روی صفحه‌ی اصلی آیفون.
 *
 * سافاری روی iOS اجازه نمی‌دهد یک صفحه‌ی وب با جاوااسکریپت خودش را
 * مثل اندروید نصب کند (beforeinstallprompt در iOS وجود ندارد) — تنها
 * راه، منوی دستیِ Share → Add to Home Screen است که خیلی از کاربران
 * پیدایش نمی‌کنند. نزدیک‌ترین چیز به «نصب با یک کلیک» که اپل واقعاً
 * اجازه می‌دهد، یک Configuration Profile از نوع Web Clip است: کاربر
 * روی همین لینک می‌زند، صفحه‌ی «Install Profile» در تنظیمات باز
 * می‌شود، تایید می‌کند، و یک آیکون تمام‌صفحه (بدون نوار آدرس سافاری،
 * دقیقاً مثل یک اپ واقعی) روی صفحه‌ی اصلی می‌نشیند.
 *
 * توجه: این پروفایل امضا نشده (unsigned) است، پس iOS یک برچسب
 * «Not Verified» رویش نشان می‌دهد و کاربر باید یک بار «Install» را
 * تایید کند (و روی iOS 12+ رمز/فیس‌آیدی هم می‌خواهد) — این محدودیت
 * امنیتیِ خودِ اپل است و با هیچ تنظیماتی از بین نمی‌رود. برای حذف کامل
 * این برچسب باید پروفایل با یک گواهیِ توسعه‌دهنده‌ی اپل امضا شود
 * (نیازمند حساب Apple Developer و ابزار امضا — خارج از دسترس یک
 * اسکریپت PHP ساده). با این حال همین حالت هم بسیار ساده‌تر از پیدا
 * کردن دستیِ «Add to Home Screen» است: فقط یک لینک + چند تاییدِ متوالی.
 *
 * PayloadUUID/PayloadIdentifier عمداً ثابت هستند (نه رندوم در هر
 * درخواست) تا اگر کاربر دوباره همین لینک را باز کند، iOS آن را همان
 * پروفایلِ قبلی بشناسد (به‌روزرسانی) نه یک پروفایل تکراری جدید.
 * ---------------------------------------------------------------
 */

$appUrl   = 'https://aradexchange.com/ledor/splash.html';
$appLabel = 'AvaPay';
$iconPath = __DIR__ . '/apple-touch-icon.png'; // ۱۸۰×۱۸۰، بدون شفافیت — دقیقاً سایز پیشنهادی اپل برای Web Clip

$iconData = is_file($iconPath) ? file_get_contents($iconPath) : '';
$iconBase64 = $iconData !== '' ? base64_encode($iconData) : '';

// UUID های ثابت — این‌ها را عوض نکنید مگر بخواهید پروفایل قبلاً نصب‌شده
// را از حالت «به‌روزرسانی خودکار» خارج کنید و به‌جایش یک پروفایل کاملاً
// جدید و جدا بسازید.
$profileUuid = 'E0262A53-7EA2-4609-9FA4-64ABD4D4B7A6';
$webClipUuid = 'AF0D20CC-6995-4767-A24C-96EFBA914965';

$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
     . '<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">' . "\n"
     . '<plist version="1.0">' . "\n"
     . '<dict>' . "\n"
     . '    <key>PayloadContent</key>' . "\n"
     . '    <array>' . "\n"
     . '        <dict>' . "\n"
     . '            <key>PayloadType</key>' . "\n"
     . '            <string>com.apple.webClip.managed</string>' . "\n"
     . '            <key>PayloadVersion</key>' . "\n"
     . '            <integer>1</integer>' . "\n"
     . '            <key>PayloadIdentifier</key>' . "\n"
     . '            <string>com.aradexchange.avapay.webclip</string>' . "\n"
     . '            <key>PayloadUUID</key>' . "\n"
     . '            <string>' . $webClipUuid . '</string>' . "\n"
     . '            <key>PayloadDisplayName</key>' . "\n"
     . '            <string>' . $appLabel . ' Web Clip</string>' . "\n"
     . '            <key>Label</key>' . "\n"
     . '            <string>' . $appLabel . '</string>' . "\n"
     . '            <key>URL</key>' . "\n"
     . '            <string>' . $appUrl . '</string>' . "\n"
     . '            <key>IsRemovable</key>' . "\n"
     . '            <true/>' . "\n"
     . '            <key>FullScreen</key>' . "\n"
     . '            <true/>' . "\n"
     . '            <key>Precomposed</key>' . "\n"
     . '            <true/>' . "\n"
     . ($iconBase64 !== ''
        ? '            <key>Icon</key>' . "\n"
        . '            <data>' . "\n" . chunk_split($iconBase64, 76, "\n") . '            </data>' . "\n"
        : '')
     . '        </dict>' . "\n"
     . '    </array>' . "\n"
     . '    <key>PayloadDisplayName</key>' . "\n"
     . '    <string>نصب ' . $appLabel . ' روی صفحه اصلی</string>' . "\n"
     . '    <key>PayloadDescription</key>' . "\n"
     . '    <string>یک آیکون تمام‌صفحه از ' . $appLabel . ' روی صفحه اصلی آیفون شما اضافه می‌کند — بدون نوار آدرس سافاری، دقیقاً مثل یک اپ.</string>' . "\n"
     . '    <key>PayloadIdentifier</key>' . "\n"
     . '    <string>com.aradexchange.avapay.profile</string>' . "\n"
     . '    <key>PayloadOrganization</key>' . "\n"
     . '    <string>Arad Exchange</string>' . "\n"
     . '    <key>PayloadRemovalDisallowed</key>' . "\n"
     . '    <false/>' . "\n"
     . '    <key>PayloadType</key>' . "\n"
     . '    <string>Configuration</string>' . "\n"
     . '    <key>PayloadUUID</key>' . "\n"
     . '    <string>' . $profileUuid . '</string>' . "\n"
     . '    <key>PayloadVersion</key>' . "\n"
     . '    <integer>1</integer>' . "\n"
     . '</dict>' . "\n"
     . '</plist>' . "\n";

header('Content-Type: application/x-apple-aspen-config; charset=utf-8');
header('Content-Disposition: attachment; filename="AvaPay.mobileconfig"');
header('Content-Length: ' . strlen($xml));
echo $xml;
