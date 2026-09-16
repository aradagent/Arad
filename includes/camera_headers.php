<?php
/**
 * includes/camera_headers.php
 * هدر Permissions-Policy را ارسال می‌کند تا مرورگر و PWA اجازه‌ی
 * استفاده از دوربین (getUserMedia) را در این origin بدهند.
 * باید قبل از هر خروجی include شود.
 */
if (!headers_sent()) {
    // اجازه‌ی دوربین و میکروفن فقط برای همین سایت
    header('Permissions-Policy: camera=(self), microphone=(self)');
    // نسخه‌ی قدیمی‌تر برای سازگاری با مرورگرهای قدیمی اندروید
    header('Feature-Policy: camera *; microphone *');
}
