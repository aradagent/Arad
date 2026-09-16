<?php
/**
 * includes/perf_helpers.php
 * ------------------------------------------------------------------
 * کمک‌تابع‌های سبک برای جلوگیری از اجرای عملیات سنگین/غیرضروری روی
 * *هر* درخواست API.
 *
 * چرا لازم شد؟
 *   چند فایل API (topup_api.php، unpaid_invoice_api.php و ...) روی
 *   ابتدای فایل — یعنی برای *هر* اکشن، حتی یک poll ساده‌ی هر ۸ ثانیه —
 *   یک DELETE با شرط تاریخ (بدون ایندکس روی created_at) اجرا می‌کردند.
 *   با چند کاربر آنلاین که هر کدام هر ۸ ثانیه poll می‌زنند، این یعنی
 *   دائماً یک DELETE با اسکن کامل جدول روی دیتابیس اجرا می‌شود —
 *   یکی از علت‌های اصلیِ کند شدن اپ زیر بار.
 *
 * راه‌حل: این DELETEها فقط باید هر چند دقیقه یک‌بار اجرا شوند، نه هر بار.
 * از یک قفل فایلی سبک (همان الگوی ava_hb_gate.txt که برای هشدار قیمت
 * استفاده شده) برای throttle کردن استفاده می‌کنیم — بدون نیاز به کران
 * جداگانه یا جدول اضافه در دیتابیس.
 * ------------------------------------------------------------------
 */

if (!function_exists('avapay_throttled')) {
    /**
     * فقط اگر حداقل $intervalSeconds از آخرین اجرای موفق این $key گذشته
     * باشد، true برمی‌گرداند (و زمان را آپدیت می‌کند). در غیر این صورت
     * false — یعنی این درخواست باید از اجرای عملیات سنگین صرف‌نظر کند.
     *
     * @param string $key             شناسه‌ی یکتا برای این عملیات (مثلاً 'topup_cleanup')
     * @param int    $intervalSeconds حداقل فاصله‌ی زمانی بین دو اجرا
     * @return bool
     */
    function avapay_throttled(string $key, int $intervalSeconds): bool {
        $safeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key);
        $gateFile = sys_get_temp_dir() . '/ava_gate_' . $safeKey . '.txt';
        $last = is_readable($gateFile) ? (int)@file_get_contents($gateFile) : 0;
        if (time() - $last < $intervalSeconds) {
            return false;
        }
        @file_put_contents($gateFile, (string)time(), LOCK_EX);
        return true;
    }
}
