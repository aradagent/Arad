-- ============================================================
--  AvaPay Dashboard – Schema Fix / Migration
--  ایمن برای اجرای چندباره (idempotent). در phpMyAdmin روی دیتابیس
--  aradexch_app ایمپورت کنید. ستون‌ها/جداول گم‌شده را می‌سازد و
--  داده‌های موجود را دست نمی‌زند.
-- ============================================================

SET NAMES utf8mb4;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- روال کمکی برای افزودن ستون فقط در صورت نبودن آن
DROP PROCEDURE IF EXISTS ava_add_col;
DELIMITER //
CREATE PROCEDURE ava_add_col(
    IN p_table VARCHAR(64),
    IN p_col   VARCHAR(64),
    IN p_def   TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND COLUMN_NAME = p_col
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_col, '` ', p_def);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

-- ============================================================
-- 1) NEWS  (اخبار بازار)
-- ============================================================
CREATE TABLE IF NOT EXISTS `news` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(400) NOT NULL,
  `content` TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CALL ava_add_col('news', 'title_en',    "VARCHAR(400) DEFAULT NULL");
CALL ava_add_col('news', 'content_en',  "TEXT DEFAULT NULL");
CALL ava_add_col('news', 'image',       "VARCHAR(500) DEFAULT NULL");
CALL ava_add_col('news', 'link',        "VARCHAR(500) DEFAULT NULL");
CALL ava_add_col('news', 'active',      "TINYINT(1) DEFAULT 1");
CALL ava_add_col('news', 'sort_order',  "INT(11) DEFAULT 0");
CALL ava_add_col('news', 'created_at',  "TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP");
CALL ava_add_col('news', 'updated_at',  "TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");

-- ============================================================
-- 2) DASHBOARD_SLIDES  (بنرهای پایین داشبورد)
-- ============================================================
CREATE TABLE IF NOT EXISTS `dashboard_slides` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CALL ava_add_col('dashboard_slides', 'description', "TEXT DEFAULT NULL");
CALL ava_add_col('dashboard_slides', 'bg_color',    "VARCHAR(100) DEFAULT 'linear-gradient(135deg,#6C40C5,#FF4D8D)'");
CALL ava_add_col('dashboard_slides', 'icon',        "VARCHAR(100) DEFAULT 'fas fa-star'");
CALL ava_add_col('dashboard_slides', 'link',        "VARCHAR(500) DEFAULT ''");
CALL ava_add_col('dashboard_slides', 'image_url',   "VARCHAR(500) DEFAULT ''");
CALL ava_add_col('dashboard_slides', 'sort_order',  "INT(11) DEFAULT 0");
CALL ava_add_col('dashboard_slides', 'is_active',   "TINYINT(4) DEFAULT 1");
CALL ava_add_col('dashboard_slides', 'created_at',  "TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP");

-- ============================================================
-- 3) QUICK_ACTIONS  (خدمات محبوب)
-- ============================================================
CREATE TABLE IF NOT EXISTS `quick_actions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `label` VARCHAR(100) NOT NULL,
  `icon` VARCHAR(100) NOT NULL DEFAULT 'fas fa-star'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CALL ava_add_col('quick_actions', 'color',                "VARCHAR(100) DEFAULT '#6C40C5'");
CALL ava_add_col('quick_actions', 'icon_image',           "VARCHAR(500) DEFAULT ''");
CALL ava_add_col('quick_actions', 'sort_order',           "INT(11) DEFAULT 0");
CALL ava_add_col('quick_actions', 'is_active',            "TINYINT(4) DEFAULT 1");
CALL ava_add_col('quick_actions', 'min_balance',          "DECIMAL(15,2) DEFAULT 0.00");
CALL ava_add_col('quick_actions', 'min_balance_currency', "ENUM('USD','EUR','USDT','IRR') DEFAULT 'USD'");
CALL ava_add_col('quick_actions', 'deduct_balance',       "TINYINT(4) DEFAULT 0");
CALL ava_add_col('quick_actions', 'deduct_amount',        "DECIMAL(15,2) DEFAULT 0.00");
CALL ava_add_col('quick_actions', 'deduct_currency',      "ENUM('USD','EUR','USDT','IRR') DEFAULT 'USD'");
CALL ava_add_col('quick_actions', 'created_at',           "TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP");

-- ============================================================
-- 4) USER_ADS  (سفارشات فعال)
-- ============================================================
CREATE TABLE IF NOT EXISTS `user_ads` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT(11) NOT NULL,
  `type` ENUM('buy','sell') NOT NULL,
  `currency` VARCHAR(10) NOT NULL,
  `amount` DECIMAL(20,6) NOT NULL,
  `price_per_unit` DECIMAL(20,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CALL ava_add_col('user_ads', 'description', "TEXT DEFAULT NULL");
CALL ava_add_col('user_ads', 'status',      "ENUM('active','completed','cancelled') DEFAULT 'active'");
CALL ava_add_col('user_ads', 'views_count', "INT(11) DEFAULT 0");
CALL ava_add_col('user_ads', 'offer_count', "INT(11) DEFAULT 0");
CALL ava_add_col('user_ads', 'created_at',  "TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP");
CALL ava_add_col('user_ads', 'updated_at',  "TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");

-- ============================================================
-- 5) MONEY_TRANSFERS  (وضعیت حواله‌ها)
-- ============================================================
CREATE TABLE IF NOT EXISTS `money_transfers` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `tracking_code` VARCHAR(20) NOT NULL,
  `user_id` INT(11) NOT NULL,
  `country` VARCHAR(100) NOT NULL,
  `country_code` VARCHAR(5) NOT NULL DEFAULT '',
  `full_name` VARCHAR(200) NOT NULL DEFAULT '',
  `iban` VARCHAR(100) NOT NULL DEFAULT '',
  `bank_name` VARCHAR(200) NOT NULL DEFAULT '',
  `amount` DECIMAL(20,2) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CALL ava_add_col('money_transfers', 'currency',   "VARCHAR(10) DEFAULT 'EUR'");
CALL ava_add_col('money_transfers', 'status',     "ENUM('pending','approved','rejected','waiting_payment','payment_submitted','completed') DEFAULT 'pending'");
CALL ava_add_col('money_transfers', 'created_at', "TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP");

-- ============================================================
-- 6) CURRENCY_RATES  (نرخ ارزها)
-- ============================================================
CREATE TABLE IF NOT EXISTS `currency_rates` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `currency` VARCHAR(10) NOT NULL,
  `price` DECIMAL(20,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CALL ava_add_col('currency_rates', 'change_24h', "DECIMAL(5,2) DEFAULT 0.00");
CALL ava_add_col('currency_rates', 'updated_at', "TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");

-- درج نرخ‌های پیش‌فرض فقط اگر جدول خالی باشد
INSERT INTO `currency_rates` (`currency`, `price`, `change_24h`)
SELECT * FROM (SELECT 'USDT' AS c, 153000.00 AS p, 2.50 AS ch) AS t
WHERE NOT EXISTS (SELECT 1 FROM `currency_rates`);
INSERT INTO `currency_rates` (`currency`, `price`, `change_24h`)
SELECT * FROM (SELECT 'USD', 154000.00, 2.50) AS t
WHERE NOT EXISTS (SELECT 1 FROM `currency_rates` WHERE currency='USD');
INSERT INTO `currency_rates` (`currency`, `price`, `change_24h`)
SELECT * FROM (SELECT 'EUR', 183000.00, 1.80) AS t
WHERE NOT EXISTS (SELECT 1 FROM `currency_rates` WHERE currency='EUR');

-- ============================================================
-- 7) جداول جدید داشبورد (هشدار قیمت / علاقه‌مندی / تاریخچه نرخ)
-- ============================================================
CREATE TABLE IF NOT EXISTS `price_alerts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT(11) NOT NULL,
  `currency` VARCHAR(10) NOT NULL,
  `target_price` DECIMAL(20,2) NOT NULL,
  `direction` ENUM('above','below') DEFAULT 'above',
  `is_active` TINYINT(1) DEFAULT 1,
  `triggered_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user` (`user_id`),
  INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_favorite_rates` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT(11) NOT NULL,
  `currency` VARCHAR(10) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_fav` (`user_id`,`currency`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `currency_rate_history` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `currency` VARCHAR(10) NOT NULL,
  `price` DECIMAL(20,2) NOT NULL,
  `recorded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_cur` (`currency`),
  INDEX `idx_rec` (`recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- پاکسازی روال کمکی
DROP PROCEDURE IF EXISTS ava_add_col;

-- پایان.

-- ============================================================
-- 8) جداول لازم برای آپدیت داشبورد (فیش‌ها / صورت‌حساب‌ها)
-- ============================================================
CREATE TABLE IF NOT EXISTS `receipt_views` (
  `user_id` INT(11) NOT NULL PRIMARY KEY,
  `last_seen` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payment_receipts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `offer_id` INT(11) NOT NULL DEFAULT 0,
  `user_id` INT(11) NOT NULL,
  `image_path` VARCHAR(500) NOT NULL DEFAULT '',
  `description` TEXT DEFAULT NULL,
  `status` ENUM('pending','approved','rejected') DEFAULT 'pending',
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `transfer_receipts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `request_id` INT(11) NOT NULL DEFAULT 0,
  `user_id` INT(11) NOT NULL,
  `type` ENUM('payment','settlement') NOT NULL DEFAULT 'payment',
  `file_path` VARCHAR(500) NOT NULL DEFAULT '',
  `file_name` VARCHAR(200) DEFAULT NULL,
  `note` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `unpaid_invoices` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT(11) NOT NULL,
  `currency` ENUM('USD','EUR','USDT','IRR') NOT NULL DEFAULT 'USD',
  `amount` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `description` TEXT DEFAULT NULL,
  `status` ENUM('pending','approved','paid','finalized','rejected') DEFAULT 'pending',
  `receipt_image` VARCHAR(500) DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ستون‌های احتمالی گم‌شده
DROP PROCEDURE IF EXISTS ava_add_col2;
DELIMITER //
CREATE PROCEDURE ava_add_col2(IN p_table VARCHAR(64), IN p_col VARCHAR(64), IN p_def TEXT)
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=p_table)
       AND NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=p_table AND COLUMN_NAME=p_col) THEN
        SET @s = CONCAT('ALTER TABLE `',p_table,'` ADD COLUMN `',p_col,'` ',p_def);
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    END IF;
END //
DELIMITER ;
CALL ava_add_col2('user_receipts','uploaded_by','INT(11) DEFAULT NULL');
CALL ava_add_col2('user_receipts','receipt_file','VARCHAR(500) NOT NULL DEFAULT ""');
CALL ava_add_col2('user_receipts','uploaded_at','TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP');
CALL ava_add_col2('price_alerts','triggered_at','DATETIME DEFAULT NULL');
DROP PROCEDURE IF EXISTS ava_add_col2;

-- پایان بخش آپدیت.
