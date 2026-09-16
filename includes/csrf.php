<?php
/**
 * includes/csrf.php
 * ------------------------------------------------------------------
 * اعتبارسنجی توکن CSRF برای اکشن‌های حساس. توکن در arad.php روی سشن
 * ساخته می‌شود ($_SESSION['csrf_token_arad']) و به‌صورت خودکار توسط
 * fetch-wrapper همان صفحه در هدر X-CSRF-Token فرستاده می‌شود.
 *
 * فعلاً فقط روی اکشن‌های حساسِ صدا‌زده‌شده از arad.php (پیشنهادها) فعال
 * است. برای فعال‌سازی روی endpoint دیگر، همین تابع را همان‌جا صدا بزنید.
 * ------------------------------------------------------------------
 */

if (!function_exists('avapay_csrf_check')) {
    function avapay_csrf_check(): bool {
        $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
        $expected = $_SESSION['csrf_token_arad'] ?? '';
        if (empty($sent) || empty($expected)) return false;
        return hash_equals($expected, $sent);
    }
}

if (!function_exists('avapay_csrf_require')) {
    /** روی عدم تطبیق، پاسخ JSON 403 می‌دهد و اجرا را متوقف می‌کند. */
    function avapay_csrf_require(): void {
        if (!avapay_csrf_check()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'درخواست نامعتبر (CSRF). لطفاً صفحه را رفرش کنید.']);
            exit();
        }
    }
}
