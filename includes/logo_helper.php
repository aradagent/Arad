<?php
/**
 * includes/logo_helper.php
 * ---------------------------------------------------------
 * هلپر مشترک برای خواندن لوگوی فعلی برنامه.
 * لوگو همراه با اطلاعات نسخه در version.json ذخیره می‌شود
 * تا همزمان با هر آپدیت نسخه، در صورت تعریف لوگوی جدید توسط
 * ادمین، آن لوگو هم به‌صورت خودکار در سراسر برنامه نمایش
 * داده شود (چون توسط PHP در هر بار لود صفحه خوانده می‌شود).
 * ---------------------------------------------------------
 */

if (!function_exists('getAppLogo')) {
    /**
     * @return array|null  ['url' => string, 'version' => int]  یا null اگر لوگوی سفارشی تعریف نشده باشد
     */
    function getAppLogo() {
        require_once __DIR__ . '/upload_paths.php';

        $versionFile = __DIR__ . '/../version.json';

        if (!file_exists($versionFile)) {
            return null;
        }

        $data = json_decode(file_get_contents($versionFile), true);

        if (empty($data) || empty($data['logo_url'])) {
            return null;
        }

        // اگر فایل لوگو روی دیسک وجود نداشت (مثلا حذف شده) از لوگوی پیش‌فرض استفاده کن.
        // از این پس آپلود واقعی در avapay_uploads/logo (بیرون از /ledor) نوشته می‌شود،
        // پس ابتدا همان‌جا را چک می‌کنیم؛ اگر نبود، مسیر قدیمِ داخل /ledor/uploads/logo
        // را هم برای سازگاری با لوگوهای آپلودشده‌ی قبل از این تغییر بررسی می‌کنیم.
        $relativePath = ltrim($data['logo_url'], '/');            // مثل uploads/logo/xxx.png
        $sub          = preg_replace('#^uploads/#', '', $relativePath); // logo/xxx.png

        $newAbsolutePath = avapay_uploads_root() . '/' . $sub;
        $oldAbsolutePath = __DIR__ . '/../' . $relativePath;

        if (!file_exists($newAbsolutePath) && !file_exists($oldAbsolutePath)) {
            return null;
        }

        $version = isset($data['logo_version']) ? (int)$data['logo_version'] : time();

        return [
            // cache-buster بر اساس logo_version تا مرورگر همیشه نسخه جدید را بگیرد
            'url'     => '/ledor/' . $relativePath . '?v=' . $version,
            'version' => $version
        ];
    }
}
