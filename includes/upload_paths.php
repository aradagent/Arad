<?php
/**
 * includes/upload_paths.php
 * ------------------------------------------------------------------
 * از این پس فایل‌های آپلودیِ واقعیِ کاربران (KYC، فیش‌های پرداخت/تسویه،
 * چت پشتیبانی، آواتار) در یک پوشه‌ی کاملاً مستقل در ریشه‌ی public_html
 * ذخیره می‌شوند — یعنی خارج از پوشه‌ی /ledor (کدِ برنامه). دلیل:
 *   ۱) داده‌ی خصوصیِ کاربران هرگز داخل زیپِ کدِ تحویلی/بک‌آپ کد قرار نگیرد
 *   ۲) آپدیت‌کردنِ کد هیچ‌وقت فایل‌های کاربر را لمس نکند
 * دقیقاً همان الگوی avapay_backup_dir() در cron_db_backup.php.
 *
 * سازگاری با گذشته: هرچه قبلاً داخل /ledor/uploads/<sub>/ ذخیره شده،
 * همان‌جا می‌ماند و از همان مسیر سرو می‌شود — چون uploads/.htaccess یک
 * قانونِ rewrite دارد که *فقط* وقتی فایل در مسیر قدیم پیدا نشود، درخواست
 * را به‌صورت شفاف (بدون تغییر URL) به پوشه‌ی جدید هدایت می‌کند. پس هیچ
 * کدِ نمایشیِ دیگری در کل پروژه لازم نیست تغییر کند.
 * ------------------------------------------------------------------
 */

if (!function_exists('avapay_uploads_root')) {
    function avapay_uploads_root() {
        if (!empty($_SERVER['DOCUMENT_ROOT'])) {
            $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');
            if (is_dir($docRoot) && is_writable($docRoot)) {
                return $docRoot . '/avapay_uploads';
            }
        }
        // fallback (مثلاً اجرای CLI بدون DOCUMENT_ROOT): دو پله بالاتر از
        // includes/ برابر است با ریشه‌ی public_html (چون includes/ داخل
        // /ledor/ است و /ledor/ خودش داخل public_html است)
        return dirname(__DIR__, 2) . '/avapay_uploads';
    }
}

/**
 * مسیر فیزیکیِ نوشتن برای یک زیرشاخه (مثلاً 'kyc'، 'receipts').
 * پوشه را در صورت نبود می‌سازد و رشته‌ی پایان‌یافته با '/' برمی‌گرداند.
 */
if (!function_exists('avapay_upload_dir')) {
    function avapay_upload_dir($sub) {
        $sub = trim(str_replace('..', '', $sub), '/\\');
        $dir = avapay_uploads_root() . '/' . $sub;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . '/';
    }
}
