-- ================================================================
-- افزودن ستون‌های تسویه‌ی جداگانه‌ی معاملات به جدول ad_deals
--
-- نکته: اجرای این فایل اختیاری است — کد PHP ستون‌ها را به‌صورت
-- خوددرمان (self-healing) می‌سازد. این فایل برای اجرای دستی در
-- phpMyAdmin یا خط فرمان MySQL است.
--
-- اصلاح: نسخه‌ی قبلی از "ADD COLUMN IF NOT EXISTS" استفاده می‌کرد که
-- فقط روی MariaDB کار می‌کند و روی MySQL 5.7/8.0 خطای سینتکس می‌داد.
-- نسخه‌ی زیر روی هر دو کار می‌کند.
-- ================================================================

DROP PROCEDURE IF EXISTS `ad_deals_add_settlement_cols`;

DELIMITER $$
CREATE PROCEDURE `ad_deals_add_settlement_cols`()
BEGIN
    DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;

    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_deals' AND COLUMN_NAME = 'buyer_admin_accounts') THEN
        ALTER TABLE `ad_deals` ADD COLUMN `buyer_admin_accounts` TEXT NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_deals' AND COLUMN_NAME = 'seller_admin_accounts') THEN
        ALTER TABLE `ad_deals` ADD COLUMN `seller_admin_accounts` TEXT NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_deals' AND COLUMN_NAME = 'buyer_receipts') THEN
        ALTER TABLE `ad_deals` ADD COLUMN `buyer_receipts` TEXT NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_deals' AND COLUMN_NAME = 'seller_receipts') THEN
        ALTER TABLE `ad_deals` ADD COLUMN `seller_receipts` TEXT NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_deals' AND COLUMN_NAME = 'buyer_settlement_receipts') THEN
        ALTER TABLE `ad_deals` ADD COLUMN `buyer_settlement_receipts` TEXT NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_deals' AND COLUMN_NAME = 'seller_settlement_receipts') THEN
        ALTER TABLE `ad_deals` ADD COLUMN `seller_settlement_receipts` TEXT NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_deals' AND COLUMN_NAME = 'buyer_admin_note') THEN
        ALTER TABLE `ad_deals` ADD COLUMN `buyer_admin_note` TEXT NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_deals' AND COLUMN_NAME = 'seller_admin_note') THEN
        ALTER TABLE `ad_deals` ADD COLUMN `seller_admin_note` TEXT NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_deals' AND COLUMN_NAME = 'buyer_side_status') THEN
        ALTER TABLE `ad_deals` ADD COLUMN `buyer_side_status` VARCHAR(30) DEFAULT 'new';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_deals' AND COLUMN_NAME = 'seller_side_status') THEN
        ALTER TABLE `ad_deals` ADD COLUMN `seller_side_status` VARCHAR(30) DEFAULT 'new';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_deals' AND COLUMN_NAME = 'admin_completed') THEN
        ALTER TABLE `ad_deals` ADD COLUMN `admin_completed` TINYINT(1) DEFAULT 0;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_deals' AND COLUMN_NAME = 'buyer_completed') THEN
        ALTER TABLE `ad_deals` ADD COLUMN `buyer_completed` TINYINT(1) DEFAULT 0;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_deals' AND COLUMN_NAME = 'seller_completed') THEN
        ALTER TABLE `ad_deals` ADD COLUMN `seller_completed` TINYINT(1) DEFAULT 0;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_deals' AND COLUMN_NAME = 'completed_at') THEN
        ALTER TABLE `ad_deals` ADD COLUMN `completed_at` DATETIME NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_deals' AND COLUMN_NAME = 'completed_by') THEN
        ALTER TABLE `ad_deals` ADD COLUMN `completed_by` INT NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ad_deals' AND COLUMN_NAME = 'archived') THEN
        ALTER TABLE `ad_deals` ADD COLUMN `archived` TINYINT(1) NOT NULL DEFAULT 0;
    END IF;
END$$
DELIMITER ;

CALL `ad_deals_add_settlement_cols`();
DROP PROCEDURE IF EXISTS `ad_deals_add_settlement_cols`;
