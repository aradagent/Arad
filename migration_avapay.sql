-- =============================================================================
-- AVA PAY — مهاجرت دیتابیس برای مرحله‌ی جدید پرداخت حواله ارزی
-- دیتابیس: aradexch_app
-- این تغییرات به‌صورت خودکار توسط api/transfer_api.php هم اعمال می‌شوند،
-- اما اجرای دستی یک‌بار پیشنهاد می‌شود تا مطمئن شوید ستون‌ها ساخته شده‌اند.
-- =============================================================================

-- شماره‌حساب‌هایی که ادمین برای واریز اعلام می‌کند (JSON آرایه‌ای از رشته‌ها)
ALTER TABLE `money_transfers`
    ADD COLUMN IF NOT EXISTS `admin_accounts` TEXT NULL AFTER `settlement_receipt`;

-- فیش‌های پرداخت آپلودشده توسط کاربر (JSON آرایه‌ای از مسیرها — چند عکس)
ALTER TABLE `money_transfers`
    ADD COLUMN IF NOT EXISTS `payment_receipts` TEXT NULL AFTER `payment_receipt`;

-- نکته: وضعیت جدید 'awaiting_payment' به ستون status (VARCHAR) اضافه می‌شود؛
-- چون نوع ستون VARCHAR است نیازی به تغییر ENUM نیست.

-- (اختیاری) اطمینان از وجود ستون‌های موردنیاز برای نوتیفیکیشن تلگرام
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `notify_telegram` TINYINT(1) DEFAULT 1;

-- رفع خطای «Field 'id' doesn't have a default value» در جدول تسویه حساب
-- (اگر جدول قدیمی بدون AUTO_INCREMENT ساخته شده باشد)
ALTER TABLE `withdrawal_requests` MODIFY `id` INT NOT NULL AUTO_INCREMENT;

-- نکته درباره‌ی شماره‌کارت‌های ادمین در حواله ارزی:
-- ستون money_transfers.admin_accounts اکنون JSON آرایه‌ای از اشیاء است:
--   [{"name":"نام صاحب کارت","card":"شماره کارت"}, ...]
-- نسخه‌ی قدیمی (آرایه‌ای از رشته) نیز همچنان پشتیبانی می‌شود.

-- فیش‌های تسویه‌ی آپلودشده توسط ادمین (JSON آرایه‌ای از مسیرها — چند عکس)
ALTER TABLE `money_transfers`
    ADD COLUMN IF NOT EXISTS `settlement_receipts` TEXT NULL AFTER `settlement_receipt`;

-- فیش‌های تسویه‌ی چندگانه در جدول تسویه حساب (توسط ادمین)
ALTER TABLE `withdrawal_requests`
    ADD COLUMN IF NOT EXISTS `receipt_files` TEXT NULL AFTER `receipt_file`;

-- =============================================================================
-- نسخهٔ 2.9.0 — آرشیو درخواست‌ها + تخفیف با تاریخ انقضا
-- =============================================================================

-- ستون آرشیو برای مرتب‌سازی پنل ادمین (این‌ها به‌صورت خودکار هم ساخته می‌شوند)
ALTER TABLE `money_transfers`     ADD COLUMN IF NOT EXISTS `archived` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `withdrawal_requests` ADD COLUMN IF NOT EXISTS `archived` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `ad_deals`            ADD COLUMN IF NOT EXISTS `archived` TINYINT(1) NOT NULL DEFAULT 0;

-- یادآوری: تخفیف‌های منقضی‌شده به‌صورت خودکار توسط api/discount_api.php (action=list_discounts)
-- و همچنین در محاسبهٔ کمیسیون (شرط expires_at > NOW()) نادیده گرفته/حذف می‌شوند.
