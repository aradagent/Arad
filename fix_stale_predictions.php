<?php
/**
 * fix_stale_predictions.php
 * ----------------------------------------------------------------
 * اسکریپت یک‌بارمصرف: تارگت‌های «pending»ای که قبل از اصلاحِ اخیر
 * (فرمول ۳۰روزه/ضریب ۰.۳) ثبت شده‌اند و هنوز به سررسید نرسیده‌اند را
 * پاک می‌کند. چون ثبتِ روزانه با INSERT IGNORE انجام می‌شود، تا وقتی
 * ردیفِ همان روز پاک نشود، دفعه‌ی بعد هم دوباره نوشته نمی‌شود — پس
 * بدون این پاک‌سازی، تارگت‌های دور از قیمتِ فعلی تا سررسیدِ قدیمی‌شان
 * (که می‌تواند تا ۳۰ روز باشد) روی صفحه می‌مانند.
 *
 * بعد از اجرا، همان لحظه که هرکسی داشبورد را باز کند، تارگت‌های تازه
 * (کوچک، نزدیک به قیمتِ فعلی، با سررسیدِ ۳ روزه) خودکار جایگزین می‌شوند.
 *
 * ⚠️ فقط یک‌بار اجرا کن (با باز کردن این آدرس در مرورگر)، بعد همین
 * فایل را از روی سرور حذف کن — چون بدونِ نیاز به لاگین اجرا می‌شود.
 */

require_once 'config/database.php';

header('Content-Type: text/plain; charset=utf-8');

$deleted1 = 0;
$deleted4h = 0;

try {
    $r = $conn->query("DELETE FROM ai_predictions_log WHERE status = 'pending'");
    $deleted1 = $conn->affected_rows;
} catch (\Throwable $e) {
    echo "خطا در پاک‌سازی ai_predictions_log: " . $e->getMessage() . "\n";
}

try {
    $r = $conn->query("DELETE FROM ai_predictions_4h WHERE status = 'pending'");
    $deleted4h = $conn->affected_rows;
} catch (\Throwable $e) {
    // اگر جدول ۴ساعته وجود نداشته باشد یا نامش فرق کند، بی‌ضرر رد شو
    echo "توجه: ai_predictions_4h پاک نشد (شاید وجود ندارد) — " . $e->getMessage() . "\n";
}

echo "تمام شد.\n";
echo "$deleted1 ردیف در ai_predictions_log پاک شد.\n";
echo "$deleted4h ردیف در ai_predictions_4h پاک شد.\n";
echo "حالا داشبورد را باز کن تا تارگت‌های تازه و نزدیک به قیمتِ فعلی ساخته شوند.\n";
echo "\nاین فایل را همین الان از روی سرور حذف کن.\n";
