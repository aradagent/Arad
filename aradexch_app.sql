-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Jun 30, 2026 at 08:41 PM
-- Server version: 11.4.12-MariaDB
-- PHP Version: 8.4.21

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `aradexch_app`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`aradexch`@`localhost` PROCEDURE `update_user_trade_stats` (IN `p_user_id` INT, IN `p_commission_rate` INT)   BEGIN
    INSERT INTO user_trade_stats (user_id, total_trades, trades_for_discount, commission_rate, discount_active, last_trade_at)
    VALUES (p_user_id, 1, 1, p_commission_rate, IF(p_commission_rate < 100, 1, 0), NOW())
    ON DUPLICATE KEY UPDATE
        total_trades = total_trades + 1,
        trades_for_discount = trades_for_discount + 1,
        commission_rate = CASE 
            WHEN (trades_for_discount + 1) >= 4 THEN 50
            ELSE 100
        END,
        discount_active = CASE 
            WHEN (trades_for_discount + 1) >= 4 THEN 1
            ELSE 0
        END,
        last_trade_at = NOW();
END$$

--
-- Functions
--
CREATE DEFINER=`aradexch`@`localhost` FUNCTION `calculate_commission` (`p_user_id` INT, `p_currency` VARCHAR(10), `p_amount` DECIMAL(20,6)) RETURNS DECIMAL(20,6) DETERMINISTIC BEGIN
    DECLARE v_trade_count INT DEFAULT 0;
    DECLARE v_commission_amount DECIMAL(20,6) DEFAULT 0;
    DECLARE v_commission_rate INT DEFAULT 100;
    
    -- اگر ارز تومان باشد، کمیسیون صفر است
    IF p_currency = 'IRR' THEN
        RETURN 0;
    END IF;
    
    -- دریافت تعداد معاملات موفق کاربر
    SELECT total_trades INTO v_trade_count 
    FROM user_trade_stats 
    WHERE user_id = p_user_id;
    
    IF v_trade_count IS NULL THEN
        SET v_trade_count = 0;
    END IF;
    
    -- تعیین نرخ کمیسیون: بعد از 4 معامله، 50% تخفیف
    IF v_trade_count >= 4 THEN
        SET v_commission_rate = 50;
    END IF;
    
    -- محاسبه مقدار کمیسیون (5 واحد پایه)
    SET v_commission_amount = 5;
    
    -- اعمال تخفیف
    SET v_commission_amount = v_commission_amount * (v_commission_rate / 100);
    
    RETURN v_commission_amount;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `account`
--

CREATE TABLE `account` (
  `id` int(11) NOT NULL,
  `name` varchar(70) DEFAULT NULL,
  `lastname` varchar(70) DEFAULT NULL,
  `username` varchar(70) DEFAULT NULL,
  `step` varchar(70) DEFAULT NULL,
  `chat_id` varchar(30) NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data`)),
  `doller` bigint(255) UNSIGNED NOT NULL DEFAULT 0,
  `euro` bigint(255) UNSIGNED NOT NULL DEFAULT 0,
  `mozayede_Cancell` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `mozayede_ok` smallint(6) NOT NULL DEFAULT 0,
  `date_ban` timestamp NULL DEFAULT NULL,
  `ban` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `verified` char(255) NOT NULL DEFAULT '0',
  `phone` char(255) DEFAULT NULL,
  `rial` bigint(255) NOT NULL DEFAULT 0,
  `Vip` int(20) NOT NULL DEFAULT 0,
  `empfanger` char(255) DEFAULT NULL,
  `bankname` char(50) DEFAULT NULL,
  `iban` char(30) DEFAULT NULL,
  `card` char(25) DEFAULT NULL,
  `expire_vip` timestamp NULL DEFAULT NULL,
  `network` char(40) DEFAULT NULL,
  `wallet_adress` char(255) DEFAULT NULL,
  `payint` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `documents` text DEFAULT NULL,
  `datapay` longtext DEFAULT NULL,
  `euroinviter` decimal(6,2) DEFAULT 0.00,
  `inviter` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `account`
--

INSERT INTO `account` (`id`, `name`, `lastname`, `username`, `step`, `chat_id`, `data`, `doller`, `euro`, `mozayede_Cancell`, `mozayede_ok`, `date_ban`, `ban`, `verified`, `phone`, `rial`, `Vip`, `empfanger`, `bankname`, `iban`, `card`, `expire_vip`, `network`, `wallet_adress`, `payint`, `documents`, `datapay`, `euroinviter`, `inviter`) VALUES
(7, 'محمد', 'سعادتمند', NULL, NULL, '484167219', '{\"mablagh_pishnehad\":\"162000\",\"meghdar\":\"300\",\"vipuser\":\"Neither user is VIP\",\"sms\":\"\",\"target_user_id\":null,\"state\":\"\",\"last_cleanup_timestamp\":1781123811}', 0, 100, 0, 10, NULL, 0, '1', '380669886231', 4500000, 0, 'رژین کریمی', 'ریال بانک تجارت', 'IR545454451518484515', '55455889662', NULL, NULL, NULL, '{\"verified\":1,\"phone\":null,\"name\":null,\"lastname\":null,\"info\":null,\"link\":\"https:\\/\\/python.main-dns-cloud.xyz:2083\\/cpsess2276940636\\/frontend\\/jupiter\\/filemanager\\/editit.html?file=string.php&fileop=&dir=%2Fhome%2Faradexch%2Fpublic_html%2Fmainbot%2Fstrings&dirop=&charset=&file_charset=_DETECT_&baseurl=&basedir=&edit=1\",\"Username\":\"\\u0639\\u0633\\u062b\\u0642\",\"Password\":\"\\u0634\\u0633\\u0634\\u0633\\u0634\",\"Amount\":\"100\",\"comments\":\"\\u0634\\u0633\"}', 'AgACAgIAAxkBAAEBXnxn9vTBPE8nyIgfWURTn6dNy1l41gACPu4xG_R9uEupLKP9G6SMxAEAAwIAA3kAAzYE', NULL, 0.00, '5330629504'),
(8, 'لیلا', 'شهریاری', 'AradTransfer_admin', NULL, '5330629504', '{\"mablagh_pishnehad\":\"157500\",\"meghdar\":\"500\",\"vipuser\":\"Neither user is VIP\",\"sms\":\"\"}', 1073, 11, 0, 12, NULL, 0, '1', '37253133195', 4950000, 0, 'حسین لطیفی', 'ریال بانک ملی', 'IR23376545578899865445', '1234567755', '2025-04-09 04:00:00', 'Trc20 usdt', '0xf24da4006f725a30a4500a9f73adebe9fdb0cc1e', '{\"verified\":1,\"phone\":null,\"name\":null,\"lastname\":null,\"info\":null,\"link\":\"Http:\\/\\/amazone.com\",\"Username\":\"Admin\",\"Password\":\"9414250123\",\"Amount\":\"100\",\"comments\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\"}', 'AgACAgIAAxkBAAEBRSFnoc0KtY8nFS1AJgTkMEbe2e1njAACj-UxG6IHEUkQNMupG4FdDwEAAwIAA3kAAzYE', '{\"3rdpartyname\":\"\\u0645\\u062d\\u0633\\u0646 \\u06a9\\u06cc\\u0627\\u06cc\\u06cc\",\"3rdpartyamount\":\"599 \\u06cc\\u0648\\u0631\\u0648\",\"3rdamountpay\":\"40.00.000 \\u062a\\u0648\\u0645\\u0627\\u0646\",\"3rdibaninfo\":null,\"3rdipayname\":\"\\u0645\\u0647\\u062f\\u06cc \\u0633\\u0644\\u0645\\u0627\\u0646\\u06cc\",\"3rdipayconfirm\":null,\"3rdipayiban\":\"1212123348655\"}', 4.50, NULL),
(10, 'حسین ', 'تقی زاده', NULL, NULL, '90440137', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"Hossein T\",\"country\":\"\\ud83c\\uddee\\ud83c\\uddf7 \\u0627\\u06cc\\u0631\\u0627\\u0646\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"291\",\"mablagh_pishnehad\":\"155000\",\"pay\":\"Paypal | \\u067e\\u06cc\\u067e\\u0627\\u0644\",\"info\":\"ok\"}', 0, 0, 0, 39, NULL, 0, '1', '989355860917', 3287000, 0, 'حسین تقی زاده', 'ریال بانک رسالت', 'IR890700001000118189546001', '5041721085132257', NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(11, 'Lilxanaxi', NULL, 'LilXanaxii', NULL, '5788733586', '{\"mablagh_pishnehad\":\"64000\",\"meghdar\":\"60\",\"sms\":\"\\u0631\\u06cc\\u0627\\u0644\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(12, 'حسین', 'خلیل پور', 'Arminjetz', NULL, '1102335790', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"Armin\",\"country\":\"\\ud83c\\udde9\\ud83c\\uddea \\u0622\\u0644\\u0645\\u0627\\u0646\",\"arz\":\"\\u062a\\u062a\\u0631\",\"meghdar_arz\":\"400\",\"mablagh_pishnehad\":\"175000\",\"pay\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc | Bank Transfer\",\"info\":\"ERC20-\\u0627\\u062a\\u0631\\u06cc\\u0648\\u0645\"}', 0, 0, 0, 33, NULL, 0, '1', '491630130237', 0, 0, 'ابراهیم خلیل پور', 'ریال بانک صادرات', 'IR850190000000212756337002', '6037691577781586', NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(13, 'احمد', 'هاشم زاده', 'null', NULL, '6952936329', '{\"mablagh_pishnehad\":\"158000\",\"meghdar\":\"500\",\"vipuser\":\"Neither user is VIP\",\"sms\":\"\"}', 0, 0, 1, 0, '2024-05-05 04:05:00', 0, '1', '491622653333', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgIAAxkBAAEBVFln0x9vPVj-1lbaGqcfjVrZqBVMkQACb_oxG4dAmUqmSDOgcQXz6gEAAwIAA3kAAzYE', NULL, 0.00, NULL),
(14, 'Scandar', '....', NULL, NULL, '6021873067', NULL, 0, 0, 1, 0, '2024-04-04 09:04:56', 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(20, 'زهرا', 'میرزایی', NULL, NULL, '5870609143', NULL, 0, 0, 0, 11, NULL, 0, '1', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(22, 'Arifi', NULL, 'null', NULL, '6808997258', '{\"mablagh_pishnehad\":\"\",\"meghdar\":\"\",\"sms\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(23, 'کیانا', 'علومی', NULL, 'havale ok', '5244587650', '{\"type\":\"\\u062e\\u0631\\u06cc\\u062f\\u0627\\u0631\",\"name\":\"\\u06a9\\u06cc\\u0627\\u0646\\u0627\",\"country\":\"\\ud83c\\uddee\\ud83c\\uddf9 \\u0627\\u06cc\\u062a\\u0627\\u0644\\u06cc\\u0627\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"1000\",\"mablagh_pishnehad\":\"168000\",\"pay\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc | Bank Transfer\",\"info\":\"\\u0628\\u062f\\u0648\\u0646 \\u062a\\u0648\\u0636\\u06cc\\u062d\\u0627\\u062a | No notes\"}', 0, 0, 1, 13, '2024-07-16 04:07:25', 0, '1', '393517020260', 0, 0, 'مسعود زارعی', 'ریال بانک ملی', 'IR540170000000366504577006', '6037991701580718', '2025-01-05 05:00:00', NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(24, 'علیرضا', 'میرزایی', 'stox_m', 'havale setName', '533486579', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"\",\"country\":\"\",\"arz\":\"\",\"meghdar_arz\":\"\",\"mablagh_pishnehad\":\"\",\"pay\":\"\",\"info\":\"\"}', 0, 0, 0, 9, NULL, 0, '1', '46720345110', 0, 0, 'نرگس میرزایی', 'بانک مسکن', 'IR800140040000710181332515', '6280231515230760', NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(25, 'Shahriyar', 'sh', 'sinagen', NULL, '80039950', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(26, 'قطعات یدکی دستگاه اسپرسو', NULL, 'null', NULL, '967774345', '{\"mablagh_pishnehad\":\"\",\"meghdar\":\"\",\"sms\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(27, 'علیرضا ', 'توسل', 'alirssol', 'havale ok', '212890900', '{\"type\":\"\\u062e\\u0631\\u06cc\\u062f\\u0627\\u0631\",\"name\":\"\\u0639\\u0644\\u06cc\\u0631\\u0636\\u0627  \\u062a\\u0648\\u0633\\u0644\",\"country\":\"\\ud83c\\uddee\\ud83c\\uddf7 \\u0627\\u06cc\\u0631\\u0627\\u0646\",\"arz\":\"\\u06af\\u0631\\u06cc\\u0648\\u0646\\u0627\",\"meghdar_arz\":\"5500\",\"mablagh_pishnehad\":\"1700\",\"pay\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc\",\"info\":\"\\u0628\\u062f\\u0648\\u0646 \\u062a\\u0648\\u0636\\u06cc\\u062d\\u0627\\u062a\"}', 0, 0, 0, 37, NULL, 0, '1', NULL, 0, 0, 'Койнак Тетяна Іванівна', 'pumb', 'UA133348510000026200116451252', '5355280014095937', NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(28, 'Ehsan', NULL, 'null', NULL, '5025114475', '{\"mablagh_pishnehad\":\"1700\",\"meghdar\":\"10000\",\"sms\":\"\\u0641\\u0631\\u0648\\u0634 \\u06af\\u0631\\u06cc\\u0648\\u0646\\u0627 \\u0646\\u0642\\u062f\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(29, '#Ꮇ', NULL, 'X_4_0_4_X', NULL, '1986828802', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(30, '𝑯𝒐𝒔𝒔𝒆𝒊𝒏', NULL, 'null', NULL, '1492071510', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(31, 'Jokababa', 'FX', 'jokababafx', NULL, '400506622', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(32, 'P', NULL, 'Amo_Rp2', NULL, '6152978862', '{\"name\":\"\\ud83d\\udcca \\u0644\\u06cc\\u0633\\u062a \\u062d\\u0648\\u0627\\u0644\\u0647 \\u0647\\u0627\",\"lastname\":\"\\/start\",\"number\":\"989307984207\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(33, 'اسحاق', 'محمدي', 'null', 'havale ok', '1683905962', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"\\u0627\\u0633\\u062d\\u0627\\u0642 \\u0645\\u062d\\u0645\\u062f\\u064a\",\"country\":\"\\ud83c\\udde7\\ud83c\\uddea \\u0628\\u0644\\u0698\\u06cc\\u06a9\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"1000\",\"mablagh_pishnehad\":\"180000\",\"pay\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc | Bank Transfer\",\"info\":\"\\u0641\\u0642\\u0637 \\u062d\\u0633\\u0627\\u0628 \\u0628\\u0644\\u0698\\u064a\\u0643\"}', 0, 0, 0, 8, NULL, 0, '1', '32472381042', 0, 1, 'مریم میرزایی', 'ریال \nبانگ تجارت', 'IR790180000000137568986117', '5859831046993670', NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(34, 'Behzad', 'K', 'Be67373', NULL, '5756681653', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(35, 'عمران', 'ح', 'null', NULL, '2098976409', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(36, 'my pc telegram id', NULL, 'null', NULL, '290629906', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(37, 'زهرا ', 'میرزایی', 'null', NULL, '7054359184', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"Zara\",\"country\":\"\\ud83c\\uddee\\ud83c\\uddf9 \\u0627\\u06cc\\u062a\\u0627\\u0644\\u06cc\\u0627\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"200\",\"mablagh_pishnehad\":\"200000\",\"pay\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc | Bank Transfer\",\"info\":\"ok\"}', 0, 0, 0, 27, NULL, 0, '1', NULL, 0, 0, 'فاطمه عبدی', 'ريال بانك ملت', 'IR340120000000009777619757', '6104338606774707', '2024-12-27 05:00:00', NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(38, 'رژین', 'کریمی', 'karimioph', NULL, '54247368', '{\"mablagh_pishnehad\":\"180000\",\"meghdar\":\"1000\",\"vipuser\":\"Neither user is VIP\",\"sms\":\"\"}', 0, 0, 0, 12, NULL, 0, '1', NULL, 0, 0, 'Rojin karimi', 'رولوت', 'LT773250058018491798', NULL, '2025-08-29 22:00:00', NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(39, 'زهرا ', 'افضلی', 'zaraafz', 'havale type', '1629117340', '{\"type\":\"\",\"name\":\"\",\"country\":\"\",\"arz\":\"\",\"meghdar_arz\":\"\",\"mablagh_pishnehad\":\"\",\"pay\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '1', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(40, '!Sun', NULL, 'isun_ua', NULL, '205967549', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(41, '✎ ◡̈⃝ - Dr.saji', NULL, 'Sajjad_jabbarzadeh', NULL, '968369328', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(42, 'مائده', 'امانی', 'The_Moongirll', NULL, '735971174', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"\\u0645\\u0627\\u0626\\u062f\\u0647\",\"country\":\"\\ud83c\\uddeb\\ud83c\\uddee \\u0641\\u0646\\u0644\\u0627\\u0646\\u062f\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"45\",\"mablagh_pishnehad\":\"166000\",\"pay\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc | Bank Transfer\",\"info\":\"\\u0628\\u062f\\u0648\\u0646 \\u062a\\u0648\\u0636\\u06cc\\u062d\\u0627\\u062a | No notes\"}', 0, 0, 0, 14, NULL, 0, '1', '989386336057', 0, 0, 'مانا امانی', 'ریال بانک پارسیان', 'IR930540106930100851041602', '6221061251912053', NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(43, 'سرور', 'پیراکه', 'null', 'mozayede sms 240', '563263817', '{\"mablagh_pishnehad\":\"65400\",\"meghdar\":\"300\",\"sms\":\".\"}', 0, 0, 0, 0, NULL, 0, '1', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(44, 'Lord of karaj', NULL, 'null', 'verify lastname', '7080366932', '{\"name\":\"\\/start\",\"lastname\":\"\",\"number\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(45, '', NULL, 'mohamad_hosein_darashti', NULL, '249264557', '{\"type\":\"\\u062e\\u0631\\u06cc\\u062f\\u0627\\u0631\",\"name\":\"\\u0645\\u062d\\u0645\\u062f \\u062d\\u0633\\u06cc\\u0646 \\u062f\\u0631\\u0634\\u062a\\u06cc\",\"country\":\"\\ud83c\\uddee\\ud83c\\uddf7 \\u0627\\u06cc\\u0631\\u0627\\u0646\",\"arz\":\"\\u06af\\u0631\\u06cc\\u0648\\u0646\\u0627\",\"meghdar_arz\":\"500\",\"mablagh_pishnehad\":\"850000\",\"pay\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc\",\"info\":\"\\u0628\\u0647 \\u043f\\u0440\\u0438\\u0432\\u0430\\u0442\\u0431\\u0430\\u043d\\u043a\"}', 0, 0, 0, 1, NULL, 0, '1', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(46, 'Troll', NULL, 'dr_trolam', 'verify 3rdipayconfirm', '1997896247', '{\"name\":\"\\u0627\\u0644\\u0648\",\"lastname\":\"\\u0646\\u0633\\u0646\\u0633\\u0645\\u0633\\u0645 \\u0645\\u0633\\u0645\\u0633\",\"number\":\"989157851576\",\"info\":\"\",\"3rdpartyname\":\"\\u2733\\ufe0f \\u062e\\u0631\\u06cc\\u062f\\u0627\\u0631\",\"3rdipayiban\":\"\\u2705 \\u0628\\u0644\\u0647 , \\u0627\\u062f\\u0627\\u0645\\u0647\\u00bb\",\"photo\":\"AgACAgQAAxkBAAEBTZ1nuakQZON_aqDmg3jGIcFncZlvBgACpsQxG6FUyFHR7KfwfOFzDwEAAwIAA3cAAzYE\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(47, 'Reza', 'Ghorbani', 'mmmrrr112233', 'verify name', '5865993884', '{\"name\":\"\",\"lastname\":\"\",\"number\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(48, 'C', NULL, 'null', 'verify info', '1671765390', '{\"name\":\"\\u06a9\\u06cc\\u0627\",\"lastname\":\"\\u06a9\\u06cc\\u0627 \\u0627\\u0631\\u062f\\u0644\\u0627\\u0646\",\"number\":\"31623404096\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(49, 'متین', 'اسلامی', 'matineslamibd', NULL, '103817741', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"\\u0645\\u062a\\u06cc\\u0646 \\u0627\\u0633\\u0644\\u0627\\u0645\\u06cc\",\"country\":\"\\ud83c\\uddee\\ud83c\\uddf7 \\u0627\\u06cc\\u0631\\u0627\\u0646\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"264\",\"mablagh_pishnehad\":\"88500\",\"pay\":\"\\u067e\\u0640\\u06cc\\u0640\\u067e\\u0640\\u0640\\u0627\\u0644\",\"info\":\"ok\"}', 0, 0, 0, 9, NULL, 0, '1', '989119273575', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(50, 'فاطمه', 'میرصانعی', 'theblueswan', NULL, '250577507', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"\\u0641\\u0627\\u0637\\u0645\\u0647 \\u0645\\u06cc\\u0631\\u0635\\u0627\\u0646\\u0639\\u06cc\",\"country\":\"\\ud83c\\uddeb\\ud83c\\uddee \\u0641\\u0646\\u0644\\u0627\\u0646\\u062f\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"113\",\"mablagh_pishnehad\":\"64700\",\"pay\":\"\\u067e\\u0640\\u06cc\\u0640\\u067e\\u0640\\u0640\\u0627\\u0644\",\"info\":\"\\u0628\\u062f\\u0648\\u0646 \\u062a\\u0648\\u0636\\u06cc\\u062d\\u0627\\u062a\"}', 0, 0, 0, 1, NULL, 0, '1', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(51, '.', NULL, 'KhorosVpn', 'verify name', '6575136531', '{\"name\":\"\",\"lastname\":\"\",\"number\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(52, 'Ali', NULL, 'sheshkovsky', 'verify number', '74145538', '{\"name\":\"\",\"lastname\":\"\\ud83d\\udc64 \\u0627\\u062d\\u0631\\u0627\\u0632 \\u0647\\u0648\\u06cc\\u062a\",\"number\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(53, 'Bano', 'Wahidi', 'null', NULL, '6121599810', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(54, 'Gard', NULL, 'Bo_rc', 'verify number', '5561079477', '{\"name\":\"\\u0633\\u06cc\\u062f \\u0645\\u0631\\u0636\\u06cc\\u0647 \\u0628\\u0637\\u062d\\u0627\\u0626\\u06cc\",\"lastname\":\"\\u0628\\u0637\\u062d\\u0627\\u0626\\u06cc\",\"number\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(55, 'مهدی', 'خرقانی', 'null', NULL, '5682186212', '{\"type\":\"\\u062e\\u0631\\u06cc\\u062f\\u0627\\u0631\",\"name\":\"\\u0645\\u0647\\u062f\\u06cc \\u062e\\u0631\\u0642\\u0627\\u0646\\u06cc\",\"country\":\"\\ud83c\\uddee\\ud83c\\uddf7 \\u0627\\u06cc\\u0631\\u0627\\u0646\",\"arz\":\"\\u062a\\u062a\\u0631\",\"meghdar_arz\":\"1000\",\"mablagh_pishnehad\":\"60000\",\"pay\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc\",\"info\":\"\\u0628\\u062f\\u0648\\u0646 \\u062a\\u0648\\u0636\\u06cc\\u062d\\u0627\\u062a\"}', 0, 0, 1, 1, '2024-05-25 06:05:21', 0, '1', '989173097834', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(56, 'mehdi', NULL, 'mehdi68dtp', 'verify number', '111843095', '{\"name\":\"\\ud83d\\udcca \\u0644\\u06cc\\u0633\\u062a \\u062d\\u0648\\u0627\\u0644\\u0647 \\u0647\\u0627\",\"lastname\":\"\\ud83d\\udcb0 \\u0642\\u06cc\\u0645\\u062a \\u0644\\u062d\\u0638\\u0647 \\u0627\\u06cc \\u0627\\u0631\\u0632\",\"number\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(57, 'Tz', NULL, 'Uziiico', 'verify info', '1768415341', '{\"name\":\"\\u0641\\u0627\\u0631\\u0633\\u06cc\",\"lastname\":\"\\u0627\\u0631\\u0645\\u06cc\\u0646 \\u0627\\u0634\\u0631\\u0627\\u0641\\u06cc\",\"number\":\"905051618932\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(58, 'Moein', 'Perspolis', 'null', NULL, '1480093429', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(59, 'Hadi', 'Farahani', 'Moshkani20', 'verify number', '46715684', '{\"name\":\"\\u0647\\u0627\\u062f\\u06cc \\u0645\\u0634\\u06a9\\u0627\\u0646\\u06cc \\u0641\\u0631\\u0627\\u0647\\u0627\\u0646\\u06cc\",\"lastname\":\"moshkani65@gmail.com\",\"number\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(60, 'TRS', NULL, 'W_trok', 'verify name', '6807162066', '{\"name\":\"\",\"lastname\":\"\",\"number\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(61, 'Silence', NULL, 'tarahi_clipha', NULL, '1002005114', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(62, 'محمدیار', NULL, 'null', NULL, '7031100267', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(63, '𝒴𝒶𝓈𝒾𝓃', NULL, 'Kurdistanie', NULL, '6597906291', '{\"name\":\"\\u0645\\u06cc\\u0644\\u0627\\u062f \\u0631\\u0633\\u062a\\u0645\\u06cc\",\"lastname\":\"\\u0631\\u0633\\u062a\\u0645\\u06cc\",\"number\":\"31617697094\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(64, 'Labkhand', NULL, 'I_am_off_2024', NULL, '5789794544', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(65, 'محمد عمر', 'جسور', 'Ahmad_shaikk', 'mozayede send 728', '5008887585', '{\"mablagh_pishnehad\":\"\",\"meghdar\":\"\",\"vipuser\":\"5008887585\",\"sms\":\"\"}', 0, 0, 0, 5, NULL, 0, '1', '491786390810', 0, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(66, 'Khadijah', NULL, 'null', 'verify number', '778464232', '{\"name\":\"\\u062e\\u062f\\u06cc\\u062c\\u0647 \\u0627\\u0628\\u0631\\u0627\\u0647\\u06cc\\u0645\\u06cc\",\"lastname\":\"\\u0627\\u0628\\u0631\\u0627\\u0647\\u06cc\\u0645\\u06cc\",\"number\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(67, 'Nasrin', 'Karoonian', 'null', 'verify lastname', '306113444', '{\"name\":\"\\ud83d\\udcca \\u0644\\u06cc\\u0633\\u062a \\u062d\\u0648\\u0627\\u0644\\u0647 \\u0647\\u0627\",\"lastname\":\"\",\"number\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(68, 'محمدرضا', 'قاسمی باغی', 'rezaghasemibaghi66', NULL, '445391849', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"Mohammadreza G\",\"country\":\"\\ud83c\\uddee\\ud83c\\uddf7 \\u0627\\u06cc\\u0631\\u0627\\u0646\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"420\",\"mablagh_pishnehad\":\"65900\",\"pay\":\"\\u067e\\u0640\\u06cc\\u0640\\u067e\\u0640\\u0640\\u0627\\u0644\",\"info\":\"\\u0648\\u0627\\u0631\\u06cc\\u0632 \\u0628\\u0647 \\u067e\\u06cc\\u067e\\u0627\\u0644\"}', 0, 0, 0, 2, NULL, 0, '1', '989112363160', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(69, 'Masoud', NULL, 'null', 'verify number', '65431396', '{\"name\":\"\\u0645\\u0633\\u0639\\u0648\\u062f\",\"lastname\":\"\\u062c\\u0627\\u0645\\u06cc\",\"number\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(70, 'علی سروش', 'قضوی', 'null', NULL, '5068589763', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"\\u0639\\u0644\\u06cc \\u0633\\u0631\\u0648\\u0634 \\u0642\\u0636\\u0648\\u06cc\",\"country\":\"\\ud83c\\udde9\\ud83c\\uddea \\u0622\\u0644\\u0645\\u0627\\u0646\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"400\",\"mablagh_pishnehad\":\"65900\",\"pay\":\"\\u067e\\u0640\\u06cc\\u0640\\u067e\\u0640\\u0640\\u0627\\u0644\",\"info\":\"\\u0648\\u0627\\u0631\\u06cc\\u0632 \\n\\u067e\\u06cc\\u200c\\u067e\\u0627\\u0644 \\u0627\\u062a\\u062d\\u0627\\u062f\\u06cc\\u0647 \\u0627\\u0631\\u0648\\u067e\\u0627 \\n\\u0648 \\u06cc\\u0627 \\u062d\\u0633\\u0627\\u0628 \\u0628\\u0627\\u0646\\u06a9\\u06cc \\u0622\\u0644\\u0645\\u0627\\u0646 \\u0648 \\u0627\\u06cc\\u0628\\u0646 \\u0622\\u0644\\u0645\\u0627\\u0646 \\n\\u062f\\u0631\\u06cc\\u0627\\u0641\\u062a \\u0622\\u0646\\u06cc \\u0631\\u06cc\\u0627\\u0644\"}', 0, 0, 0, 0, NULL, 0, '1', '989305719193', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(71, '.', '.', 'null', 'verify number', '7080266943', '{\"name\":\"\\u0641\\u0627\\u0637\\u0645\\u0647 \\u0639\\u0628\\u062f\\u0627\\u0644\\u062e\\u0627\\u0646\",\"lastname\":\"\\u0639\\u0628\\u062f\\u0627\\u0644\\u062e\\u0627\\u0646\",\"number\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(72, 'جهان', NULL, 'jahan1375', 'verify lastname', '7174356428', '{\"name\":\"\\ud83d\\udcb0 \\u0642\\u06cc\\u0645\\u062a \\u0644\\u062d\\u0638\\u0647 \\u0627\\u06cc \\u0627\\u0631\\u0632\",\"lastname\":\"\",\"number\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(73, 'karen', NULL, 'karen_id', NULL, '5978238023', '{\"name\":\"\",\"lastname\":\"\\ud83d\\udc64 \\u0627\\u062d\\u0631\\u0627\\u0632 \\u0647\\u0648\\u06cc\\u062a\",\"number\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(74, 'مینا', 'خادم زاده', 'null', 'mozayede sms 317', '289115807', '{\"mablagh_pishnehad\":\"74000\",\"meghdar\":\"300\",\"sms\":\"\\u0645\\u0634\\u06a9\\u0644\\u06cc \\u0646\\u06cc\\u0633\\u062a\"}', 0, 0, 0, 0, NULL, 0, '1', '989901221298', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(75, 'Mass', NULL, 'null', 'verify info', '31201710', '{\"name\":\"\\u0645\\u0633\\u0639\\u0648\\u062f \\u0639\\u0628\\u062f\\u06cc\",\"lastname\":\"\\/start\",\"number\":\"989351712590\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(76, 'Kamran', 'TRΞX', 'kamstar7', NULL, '619233435', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(77, 'صابر ', 'لطفی', 'saber253', NULL, '370798089', '{\"mablagh_pishnehad\":\"198000\",\"meghdar\":\"1000\",\"vipuser\":\"187246771\",\"sms\":\"\"}', 0, 0, 0, 0, NULL, 0, '1', '989143522380', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(78, 'mahi', NULL, 'mahimaedeez', 'verify lastname', '7198647768', '{\"name\":\"\\/start\",\"lastname\":\"\",\"number\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(79, 'Fariborz', 'Basiri', 'fbasiri44', 'verify number', '82893576', '{\"name\":\"\\/info\",\"lastname\":\"\\/start\",\"number\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(80, 'Mohammad', 'Yazdani', 'm_yazdaani', NULL, '138090218', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(81, 'سعید', 'اکبری', 'Dr_saeedakbari', 'havale ok', '5845430119', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"\\u0633\\u0639\\u06cc\\u062f \\u0627\\u06a9\\u0628\\u0631\\u06cc\",\"country\":\"\\ud83c\\uddf3\\ud83c\\uddf1 \\u0647\\u0644\\u0646\\u062f\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"150\",\"mablagh_pishnehad\":\"93000\",\"pay\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc\",\"info\":\"\\u0648\\u0627\\u0631\\u06cc\\u0632 \\u0622\\u0646\\u06cc \\u0628\\u0647 \\u0631\\u0648\\u0648\\u0644\\u0648\\u062a\"}', 0, 0, 0, 9, NULL, 0, '1', '31684917428', 0, 1, 'سعید اکبری', 'ریال بانک ملی', 'IR470170000000302266257001', '0302266257001', NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(82, 'Shahab', '🌊', 'null', 'verify number', '7097929036', '{\"name\":\"\\u0634\\u0647\\u0627\\u0628\",\"lastname\":\"\\u0644\\u0637\\u0641\\u06cc\",\"number\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(83, 'M', NULL, 'm18m26', 'verify lastname', '6820591830', '{\"name\":\"\\ud83d\\udcb0 \\u0642\\u06cc\\u0645\\u062a \\u0644\\u062d\\u0638\\u0647 \\u0627\\u06cc \\u0627\\u0631\\u0632\",\"lastname\":\"\",\"number\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(84, '𝓒𝓲𝓷𝓮𝓸𝓻', NULL, 'DmCineor', 'verify info', '7010535304', '{\"name\":\"\\u0639\\u0644\\u06cc \\u0627\\u0628\\u0631\\u0627\\u0647\\u06cc\\u0645\\u06cc\",\"lastname\":\"..\",\"number\":\"989380698907\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(85, 'zakria', 'yosufi', 'zekeriya5', NULL, '788669777', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(86, 'ARTA', 'Gr', 'artagr66', NULL, '7082216868', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(87, 'میلاد', 'برخورداری', 'Biktoki', NULL, '5368133149', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"\",\"country\":\"\",\"arz\":\"\",\"meghdar_arz\":\"\",\"mablagh_pishnehad\":\"\",\"pay\":\"\",\"info\":\"\"}', 0, 0, 0, 7, NULL, 0, '1', '989010109557', 0, 0, 'مریم کاوسی', 'ریال بانک سپه', 'IR870150000014370501339302', '5892101382163257', NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(88, 'کیمیا', 'ابراهیمی', 'kimia_err', NULL, '488321905', '{\"name\":\"\",\"lastname\":\"\\ud83d\\udc64 \\u0627\\u062d\\u0631\\u0627\\u0632 \\u0647\\u0648\\u06cc\\u062a\",\"number\":\"989134633869\",\"info\":\"ok\"}', 0, 0, 0, 0, NULL, 0, '1', '989134633869', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(89, 'Negaar', 'Zp', 'negaar_zpr', NULL, '82867059', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(90, 'Saboor', NULL, 'Lifechanger38', NULL, '5695011408', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(91, 'S̸E̸Y̸E̸D̸', 'M̸A̸H̸D̸I̸', 'SeyedMahdi44', NULL, '69318230', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(92, 'رحیم', 'سلیم فر', 'Rahimsalimfar', 'mozayede send 823', '399056834', '{\"mablagh_pishnehad\":\"\",\"meghdar\":\"\",\"vipuser\":\"Neither user is VIP\",\"sms\":\"\"}', 0, 0, 0, 3, NULL, 0, '1', '989175339213', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(93, 'Behzad', NULL, 'H_Arion', NULL, '113366976', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(94, 'Mohammad', 'A', 'Mohammad_Asgn', NULL, '89710153', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(95, 'yasmin_hosseini', NULL, 'yasmin_hosseini', 'verify number', '129022662', '{\"name\":\"\",\"lastname\":\"\\ud83d\\udc64 \\u0627\\u062d\\u0631\\u0627\\u0632 \\u0647\\u0648\\u06cc\\u062a\",\"number\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(96, 'میلاد', 'دانشمند', 'meneertje_niemand', NULL, '860941089', '{\"mablagh_pishnehad\":\"63500\",\"meghdar\":\"140\",\"sms\":\"salam mablaghe 150 euro mikham rial bezanam be hesab baki iran .140 ta ham ok hastesh .lotfan behem khabaresh ro bedin.mamnoon.\"}', 0, 0, 0, 0, NULL, 0, '1', '31639046637', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(97, 'Mohammad', 'Bagheri', 'Mohammadb021', 'verify name', '448805622', '{\"name\":\"\",\"lastname\":\"\",\"number\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(98, 'Arian', NULL, 'jarianj', NULL, '653003716', '{\"name\":\"\\u0627\\u06cc\\u0631\\u0627\\u0632\",\"lastname\":\"\\u062a\\u0628\\u0628\\u062a\",\"number\":\"491788640502\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(99, 'سیدعلی', 'حسینی', 'Sarafi_VTC', NULL, '5566724280', '{\"mablagh_pishnehad\":\"58900\",\"meghdar\":\"36\",\"sms\":\"\\u0631\\u0642\\u0645 \\u062c\\u062f\\u06cc\\u062f \\u0631\\u0648 \\u0648\\u0627\\u0631\\u062f \\u06a9\\u0631\\u062f\\u0645\"}', 0, 0, 1, 0, '2024-08-01 00:07:21', 0, '1', '265886180073', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(101, 'سروش', 'چکنی', 'Soroushchekani', NULL, '109821516', '{\"mablagh_pishnehad\":\"104000\",\"meghdar\":\"434\",\"vipuser\":\"Neither user is VIP\",\"sms\":\"\"}', 0, 0, 0, 0, NULL, 0, '1', '989120180830', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(102, '𓁽 𝔸𝕞𝕚ℝ ℤℙ 𓁽', NULL, 'AmiR_ZP1', 'verify info', '6209442413', '{\"name\":\"\\ud83d\\udcca \\u0644\\u06cc\\u0633\\u062a \\u062d\\u0648\\u0627\\u0644\\u0647 \\u0647\\u0627\",\"lastname\":\"\\ud83c\\udfe7 \\u06a9\\u06cc\\u0641 \\u067e\\u0648\\u0644\",\"number\":\"989914795817\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(104, 'M', 'Ekrami', 'mo_ekrami', NULL, '5792276903', '{\"name\":\"\",\"lastname\":\"\\ud83d\\udc64 \\u0627\\u062d\\u0631\\u0627\\u0632 \\u0647\\u0648\\u06cc\\u062a\",\"number\":\"989170878789\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(105, 'امید جان', 'قادری', 'null', NULL, '7074443341', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(106, 'Mohamad', 'Key', 'mohamadkey12', NULL, '103931158', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(107, 'systematic judge', NULL, 'AntiDuff', NULL, '1698493971', '{\"name\":\"\\/start\",\"lastname\":\"\\/start\",\"number\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(108, 'Naser', 'Babaei', 'null', NULL, '76146311', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(110, 'Amir Hosein', NULL, 'A_M_I_R_9_0', 'verify info', '97399824', '{\"name\":\"\\u0627\\u0645\\u06cc\\u0631\",\"lastname\":\"\\u0646\\u0627\\u0645\\u062f\\u0627\\u0631\\u06cc\",\"number\":\"4917673248110\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(111, 'پارسا', 'سلیم‌پور', 'Parsa_slmr', NULL, '76140952', '{\"type\":\"\",\"name\":\"\",\"country\":\"\",\"arz\":\"\",\"meghdar_arz\":\"\",\"mablagh_pishnehad\":\"\",\"pay\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '1', '4915566051958', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(112, 'Havva', NULL, 'Samangani1996', NULL, '594551157', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(113, 'علیرضا', 'شکوری', 'null', NULL, '329449081', '{\"name\":\"\\u0639\\u0644\\u06cc\\u0631\\u0636\\u0627\",\"lastname\":\"\\u0634\\u06a9\\u0648\\u0631\\u06cc\",\"number\":\"4915750482792\",\"info\":\"\",\"photo\":\"AgACAgQAAxkBAAEBSDFnp9UwKdTgloeC8H2NMMzqJG-lkwAC0MUxG2XKQFFRAQ_0tznFuAEAAwIAA3kAAzYE\"}', 0, 0, 0, 0, NULL, 0, '1', '4915750482792', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEBSDFnp9UwKdTgloeC8H2NMMzqJG-lkwAC0MUxG2XKQFFRAQ_0tznFuAEAAwIAA3kAAzYE', NULL, 0.00, NULL),
(114, 'هیثم', 'شاولی', 'Hshavali', 'havale ok', '442512457', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"Heisam Shavali\",\"country\":\"\\ud83c\\uddf8\\ud83c\\uddea \\u0633\\u0648\\u0626\\u062f\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"500\",\"mablagh_pishnehad\":\"183000\",\"pay\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc | Bank Transfer\",\"info\":\"\\u0628\\u062f\\u0648\\u0646 \\u062a\\u0648\\u0636\\u06cc\\u062d\\u0627\\u062a | No notes\"}', 0, 0, 0, 22, NULL, 0, '1', '989939624152', 0, 1, 'هیثم شاولی', 'تومان بانک صادرات', 'Ir310190000000338426986001', '310190000000338426986001', NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(115, 'سید مقبول', 'صافی', 'Mahnafas6', NULL, '6228809345', '{\"name\":\"\\u0633\\u06cc\\u062f \\u0645\\u0642\\u0628\\u0648\\u0644\",\"lastname\":\"\\u0635\\u0627\\u0641\\u06cc\",\"number\":\"989029519354\",\"info\":\"ok\"}', 0, 0, 0, 0, NULL, 0, '1', '989029519354', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(116, 'Laleh', NULL, 'null', 'verify name', '1670205847', '{\"name\":\"\",\"lastname\":\"\",\"number\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(117, 'NiMa', NULL, 'Import81', 'verify name', '625711591', '{\"name\":\"\",\"lastname\":\"\",\"number\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(118, 'هیثم', 'شاولی', 'null', 'havale ok', '7506201452', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"Heisam shavali\",\"country\":\"\\ud83c\\uddf8\\ud83c\\uddea \\u0633\\u0648\\u0626\\u062f\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"330\",\"mablagh_pishnehad\":\"203000\",\"pay\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc | Bank Transfer\",\"info\":\"\\u0628\\u062f\\u0648\\u0646 \\u062a\\u0648\\u0636\\u06cc\\u062d\\u0627\\u062a | No notes\"}', 0, 0, 0, 11, NULL, 0, '1', '46739931171', 0, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(119, 'lari', NULL, 'BRTBAT', 'verify number', '392577505', '{\"name\":\"Reza\",\"lastname\":\"Taj\",\"number\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(120, 'صنم', 'محصوری', 'hadejkl', NULL, '7010704291', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"\\u0635\\u0646\\u0645 \\u0645\\u062d\\u0635\\u0648\\u0631\\u06cc\",\"country\":\"\\ud83c\\uddee\\ud83c\\uddf7 \\u0627\\u06cc\\u0631\\u0627\\u0646\",\"arz\":\"\\u062a\\u062a\\u0631\",\"meghdar_arz\":\"2500\",\"mablagh_pishnehad\":\"60000\",\"pay\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc\",\"info\":\"\\u0628\\u062f\\u0648\\u0646 \\u062a\\u0648\\u0636\\u06cc\\u062d\\u0627\\u062a\"}', 0, 0, 1, 1, '2024-11-15 15:11:48', 0, '1', '989960798821', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(121, 'Milad', 'Garehkoor', 'null', NULL, '1828519180', '{\"name\":\"\\ud83d\\udc64 \\u0627\\u062d\\u0631\\u0627\\u0632 \\u0647\\u0648\\u06cc\\u062a\",\"lastname\":\"\\u0645\\u06cc\\u0644\\u0627\\u062f \\u0642\\u0631\\u0647 \\u06a9\\u0648\\u0631\",\"number\":\"4917645686427\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(122, 'Mr. Hope', NULL, 'Mr_Hope3', NULL, '88294997', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(123, 'MET', NULL, 'MET_MET_M', NULL, '515428018', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(124, 'A.R', NULL, 'null', NULL, '1086694465', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(125, 'میلاد', 'کاظمی', 'miladkzm96', NULL, '187246771', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"\\u0645\\u06cc\\u0644\\u0627\\u062f \\u06a9\\u0627\\u0638\\u0645\\u06cc\",\"country\":\"\\ud83c\\uddf3\\ud83c\\uddf1 \\u0647\\u0644\\u0646\\u062f\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"4000\",\"mablagh_pishnehad\":\"205000\",\"pay\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc | Bank Transfer\",\"info\":\"\\u0642\\u06cc\\u0645\\u062a \\u062a\\u0648\\u0627\\u0641\\u0642\\u06cc \\u0633\\u0627\\u06cc\\u062a \\u0628\\u0646\\u0628\\u0633\\u062a\"}', 0, 0, 0, 5, NULL, 0, '1', '31684688498', 0, 1, 'شبنم نوری شاللو', 'ریال بانک شهر', 'IR57061000004001000455261', '5047061072477165', NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(126, 'M', 'R', 'null', NULL, '1762318957', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(127, 'یوسف ', 'خورشیدی', 'Yosef1980', NULL, '468209332', '{\"mablagh_pishnehad\":\"72800\",\"meghdar\":\"400\",\"vipuser\":\"Neither user is VIP\",\"sms\":\"\\u0627\\u0648\\u06a9\\u06cc \\u0627\\u06cc\\u0646 \\u0645\\u0628\\u0644\\u063a \\u062c\\u0627 \\u0628\\u0647  \\u062c\\u0627 \\u0645\\u06cc\\u0634\\u0647\\u060c\"}', 0, 0, 0, 0, NULL, 0, '1', '4915257632170', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(128, 'طاها', 'بهرام پور', 'null', NULL, '7070471744', '{\"mablagh_pishnehad\":\"104500\",\"meghdar\":\"800\",\"vipuser\":\"442512457\",\"sms\":\"\",\"3rdpartyname\":\"\\u2733\\ufe0f \\u062e\\u0631\\u06cc\\u062f\\u0627\\u0631\"}', 0, 0, 0, 0, NULL, 0, '1', '33758084237', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEBV6Zn3t7HyYjWIhZqwyFOaAtt2255tgAC3skxG1ez-VJL1hpTAeaFcwEAAwIAA3kAAzYE', NULL, 0.00, NULL),
(129, 'Mehdi', NULL, 'Khezripour', 'verify info', '140999913', '{\"name\":\"\\u0645\\u0647\\u062f\\u06cc\",\"lastname\":\"\\u062e\\u0636\\u0631\\u06cc\",\"number\":\"989132476716\",\"info\":\"\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(130, 'Von der Welt', NULL, 'welt627', NULL, '253236993', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(131, 'امید', 'ابراهیمی', 'omid1hastam', NULL, '727601801', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"\\u0627\\u0645\\u06cc\\u062f \\u0627\\u0628\\u0631\\u0627\\u0647\\u06cc\\u0645\\u06cc\",\"country\":\"\\ud83c\\uddf8\\ud83c\\uddea \\u0633\\u0648\\u0626\\u062f\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"100\",\"mablagh_pishnehad\":\"66200\",\"pay\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc\",\"info\":\"\\u0627\\u0632 \\u0637\\u0631\\u06cc\\u0642 \\u0631\\u0648\\u0644\\u0648\\u062a\"}', 0, 0, 0, 0, NULL, 0, '1', '46768343877', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(132, 'Ehsan', 'Davari', 'ehsndvr', NULL, '5210530199', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(133, 'Data', 'Ninja 🥷', 'data_ninja_programmer', NULL, '7289479914', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(134, 'مهدی', 'علی نژادشیرازی', 'Mehd_shirazi', NULL, '5671773345', '{\"name\":\"\\u0645\\u0647\\u062f\\u06cc\",\"lastname\":\"\\u0639\\u0644\\u06cc \\u0646\\u0698\\u0627\\u062f\\u0634\\u06cc\\u0631\\u0627\\u0632\\u06cc\",\"number\":\"393489058542\",\"info\":\"ok\"}', 0, 0, 0, 0, NULL, 0, '1', '393489058542', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(135, 'پیمان', 'پیشگر', 'Bennjaminp', NULL, '148906659', '{\"name\":\"\\u067e\\u06cc\\u0645\\u0627\\u0646\",\"lastname\":\"\\u067e\\u06cc\\u0634\\u06af\\u0631\",\"number\":\"989127253183\",\"info\":\"ok\"}', 0, 0, 0, 0, NULL, 0, '1', '989127253183', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(136, 'Umulbanin', 'Khashee', 'null', NULL, '6918292126', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(137, 'هومن', 'سلیمانی', 'null', NULL, '7467043750', '{\"name\":\"\\u0647\\u0648\\u0645\\u0646\",\"lastname\":\"\\u0633\\u0644\\u06cc\\u0645\\u0627\\u0646\\u06cc\",\"number\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(138, '😶', '😶', 'null', NULL, '744304720', '{\"name\":\"Ali\",\"lastname\":\"\\u0639\\u0644\\u06cc\",\"number\":\"491631116975\",\"info\":\"ok\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(139, 'معین', 'همنوا', 'Moien0049', 'havale ok', '6835065356', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"\\u0645\\u0639\\u06cc\\u0646\",\"country\":\"\\ud83c\\udde9\\ud83c\\uddea \\u0622\\u0644\\u0645\\u0627\\u0646\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"1100\",\"mablagh_pishnehad\":\"186000\",\"pay\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc | Bank Transfer\",\"info\":\"\\u0648\\u0627\\u0631\\u06cc\\u0632 \\u0641\\u0648\\u0631\\u06cc \\u06cc\\u0648\\u0631\\u0648 \\u0628\\u0647 \\u062a\\u0645\\u0627\\u0645\\u06cc \\u062d\\u0633\\u0627\\u0628 \\u0647\\u0627 !!!\"}', 0, 0, 0, 7, NULL, 0, '1', '4915566460667', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(140, 'ت', NULL, 'null', NULL, '6885479684', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(142, 'دانیال', 'جعفری', 'jafariSE94', NULL, '7415876194', '{\"name\":\"\\u062f\\u0627\\u0646\\u06cc\\u0627\\u0644\",\"lastname\":\"\\u062c\\u0639\\u0641\\u0631\\u06cc\",\"number\":\"46724554545\",\"info\":\"ok\"}', 0, 0, 0, 0, NULL, 0, '1', '46724554545', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(143, 'علی', 'ملکی', 'null', NULL, '608642058', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(144, 'محسن', 'محبی', 'MOmohebi', NULL, '1076400102', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(145, 'مریم', 'علی پور', 'null', NULL, '5558745353', '{\"name\":\"\\u0645\\u0631\\u06cc\\u0645 \\u0639\\u0644\\u06cc \\u067e\\u0648\\u0631\",\"lastname\":\"\\u0639\\u0644\\u06cc \\u067e\\u0648\\u0631\",\"number\":\"989038794103\",\"info\":\"ok\",\"Wallet_Address\":\"TVKeqFXb7s9fkg39DSB3hbKUpATBYQm1zA\",\"Network_Coin\":\"Trc20\"}', 0, 0, 0, 0, NULL, 0, '1', '989038794103', 0, 0, NULL, NULL, NULL, NULL, NULL, 'Trc20', 'TVKeqFXb7s9fkg39DSB3hbKUpATBYQm1zA', NULL, NULL, NULL, 0.00, NULL),
(146, 'Mohades', NULL, 'Mohadessha', NULL, '6483741902', '{\"name\":\"Z.Sh\",\"lastname\":\"\\u0632\\u0647\\u0631\\u0627\",\"number\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(147, '@@@@', NULL, 'null', NULL, '7069875735', '{\"name\":\"\\ud83c\\udfe7 \\u06a9\\u06cc\\u0641 \\u067e\\u0648\\u0644\",\"lastname\":\"\\ud83d\\udcca \\u0644\\u06cc\\u0633\\u062a \\u062d\\u0648\\u0627\\u0644\\u0647 \\u0647\\u0627\",\"number\":\"989918302941\",\"info\":\"ok\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(148, 'Kasra', NULL, 'kasra_dv', 'verify info', '113212109', '{\"name\":\"\\u06a9\\u0633\\u0631\\u06cc \\u062f\\u0648\\u0627\\u062a\\u06af\\u0631\\u0627\\u0646\",\"lastname\":\"\\u06a9\\u06cc\\u0646\\u06af\",\"number\":\"989373217643\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(149, 'ندا', 'لطف اله زاده', 'null', NULL, '8008423940', '{\"name\":\"\\u0646\\u062f\\u0627\",\"lastname\":\"\\u0644\\u0637\\u0641 \\u0627\\u0644\\u0647 \\u0632\\u0627\\u062f\\u0647\",\"number\":\"989304362109\",\"info\":\"ok\"}', 0, 0, 0, 0, NULL, 0, '1', '989304362109', 3344000, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(150, 'Mojtaba', NULL, 'DrahigMT11', NULL, '5304861562', '{\"name\":\"\\u0645\\u062c\\u062a\\u0628\\u06cc\",\"lastname\":\"\\u062a\\u06cc\\u0645\\u0648\\u0631\\u06cc\",\"number\":\"491779284297\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(151, '-أمینك💸', NULL, 'iaminak_m', 'verify number', '939545836', '{\"name\":\"\\u0633\\u0647\\u0631\\u0627\\u0628\",\"lastname\":\"\\u0645\\u0648\\u0633\\u0648\\u06cc\",\"number\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(152, 'Tomarket 🍅', 'Ali', 'Beeneer_01', NULL, '5993500472', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(153, 'مدیریت سایت', '🌿 اومو', 'oomoomanager', 'verify number', '2035181111', '{\"name\":\"\\u0645\\u062d\\u0645\\u062f\",\"lastname\":\"\\u06cc\\u0632\\u062f\\u0627\\u0646\\u06cc\",\"number\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(154, 'Naser', 'Sh', 'null', NULL, '6888488846', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(155, 'S.A.B', NULL, 'Aseman13661396', 'verify lastname', '7138718425', '{\"name\":\"\\ud83d\\udcca \\u0644\\u06cc\\u0633\\u062a \\u062d\\u0648\\u0627\\u0644\\u0647 \\u0647\\u0627\",\"lastname\":\"\",\"number\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(156, 'Hossein', NULL, 'MeinAccount', NULL, '74984253', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(157, 'Mm', 'Mo', 'IRANINDIAEX', 'verify number', '184090430', '{\"name\":\"\\ud83c\\udfe7 \\u06a9\\u06cc\\u0641 \\u067e\\u0648\\u0644\",\"lastname\":\"\\/start\",\"number\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(158, 'محمد', 'تقی زاده ', 'null', NULL, '7267464794', '{\"name\":\"\\u0645\\u062d\\u0645\\u062f \\u062a\\u0642\\u06cc \\u0632\\u0627\\u062f\\u0647\",\"lastname\":\"\\u062a\\u0642\\u06cc \\u0632\\u0627\\u062f\\u0647\",\"number\":\"989966226774\",\"info\":\"ok\"}', 0, 0, 0, 0, NULL, 0, '1', '989966226774', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(159, 'Reza', NULL, 'Reza_Zavareh', NULL, '6234454809', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(160, 'ثریا', NULL, 'null', NULL, '1159328408', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(161, 'Zakaryia', NULL, 'null', NULL, '5507265541', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL);
INSERT INTO `account` (`id`, `name`, `lastname`, `username`, `step`, `chat_id`, `data`, `doller`, `euro`, `mozayede_Cancell`, `mozayede_ok`, `date_ban`, `ban`, `verified`, `phone`, `rial`, `Vip`, `empfanger`, `bankname`, `iban`, `card`, `expire_vip`, `network`, `wallet_adress`, `payint`, `documents`, `datapay`, `euroinviter`, `inviter`) VALUES
(162, 'Rasol', 'Lori', 'null', NULL, '5870968219', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(163, 'امیرعلی اکبری نامجو👍', NULL, 'Amirali00p0', NULL, '6378036970', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(164, 'Mohamad', NULL, 'MFtahm', 'verify lastname', '128068426', '{\"name\":\"\\ud83d\\udcb0 \\u0642\\u06cc\\u0645\\u062a \\u0644\\u062d\\u0638\\u0647 \\u0627\\u06cc \\u0627\\u0631\\u0632\",\"lastname\":\"\",\"number\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(165, 'Mehrnush', NULL, 'Mehrnush_bn', NULL, '5518457125', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(166, 'محمد نسیم', 'ذاهدی', 'Only_allah_51', NULL, '6748238492', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"\\u0645\\u062d\\u0645\\u062f \\u0646\\u0633\\u06cc\\u0645 \\u0630\\u0627\\u0647\\u062f\\u06cc\",\"country\":\"\\ud83c\\udde9\\ud83c\\uddea \\u0622\\u0644\\u0645\\u0627\\u0646\",\"arz\":\"\\u062a\\u062a\\u0631\",\"meghdar_arz\":\"200\",\"mablagh_pishnehad\":\"120000\",\"pay\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc | Bank Transfer\",\"info\":\"\\u0646\\u06a9\\u062a\\u0647 : \\u062f\\u0627\\u062e\\u0644 \\u0622\\u0644\\u0645\\u0627\\u0646 \\u0634\\u0647\\u0631 \\u0647\\u0627\\u06cc \\u0628\\u0631\\u0644\\u06cc\\u0646 \\u0645\\u06af\\u062f\\u06cc\\u0628\\u0648\\u0631\\u062f \\u0648 \\u06a9\\u0633\\u0644 \\u062d\\u0636\\u0648\\u0631\\u06cc \\u0647\\u0645 \\u0627\\u0646\\u062c\\u0627\\u0645 \\u0645\\u06cc\\u0634\\u0648\\u062f !\"}', 0, 0, 0, 0, NULL, 0, '1', '4915219651459', 0, 0, NULL, NULL, NULL, NULL, NULL, 'Trx', 'TLj8ZygD1VCXyNHXfnXi2167U32zx8tJKE', NULL, 'AgACAgQAAxkBAAEBQxVnmV8bTVN8kWUSc_QLXucnNLvVMwACNccxG1CyyVDAyV2kNy-VfwEAAwIAA3kAAzYE', NULL, 0.00, NULL),
(167, 'Ali', NULL, 'Allii2008', NULL, '1750477189', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(168, 'Shahe Najaf', NULL, 'ilea110', NULL, '96411297', '{\"name\":\"\\u0645\\u062d\\u0645\\u062f\",\"lastname\":\"\\u062c\\u0639\\u0641\\u0631\\u06cc\",\"number\":\"989392044122\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(169, 'افشین', 'غلامحسینی کهوری', 'null', NULL, '7400255080', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(170, 'Alireza', 'Omrani', 'Ali_Charismatic', NULL, '109765471', '{\"name\":\"\\u0639\\u0645\\u0631\\u0627\\u0646\\u06cc\",\"lastname\":\"\\u0645\\u062d\\u0645\\u062f\",\"number\":\"989121839577\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(171, 'ژیــــــــار', NULL, 'Rezaei13736', NULL, '1938227502', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(172, 'A l i', NULL, 'DrAliEmami', 'verify info', '86537749', '{\"name\":\"\\u0639\\u0644\\u06cc\",\"lastname\":\"\\u0627\\u0645\\u0627\\u0645\\u06cc\",\"number\":\"989132020532\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(173, 'Tna', NULL, 'Ttiitiii', NULL, '981707625', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(174, 'mamad', NULL, 'azizi_m146', NULL, '6686560353', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(175, 'Ebrahim', 'Yousefi', 'Ebi_yosefi', 'verify lastname', '656951154', '{\"name\":\"\\ud83d\\udcb0 \\u0642\\u06cc\\u0645\\u062a \\u0644\\u062d\\u0638\\u0647 \\u0627\\u06cc \\u0627\\u0631\\u0632\",\"lastname\":\"\",\"number\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(176, 'M', NULL, 'null', NULL, '148786742', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(177, '.', 'ملیسا', 'null', 'verify number', '7611499458', '{\"name\":\"\\u0633\\u0644\\u0627\\u0645\",\"lastname\":\"\\u06a9\\u06cc\\u0648\\u0627\\u0646 \\u0628\\u0631\\u0648\\u06a9\\u06cc \\u0645\\u06cc\\u0644\\u0627\\u0646\",\"number\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(178, 'fateme', NULL, 'Fateme_rahmatinejad', NULL, '380724999', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(179, 'مبین', 'سعیدی', 'mobin_sei', NULL, '530314886', '{\"name\":\"\",\"lastname\":\"\\ud83d\\udc64 \\u0627\\u062d\\u0631\\u0627\\u0632 \\u0647\\u0648\\u06cc\\u062a\",\"number\":\"989333542404\",\"info\":\"ok\"}', 0, 0, 0, 0, NULL, 0, '1', '989333542404', 0, 1, 'Дячищенко Ганна Миколаївна', 'Raifaissen Bank', 'UA453003350000002620611048181', '4149500022732468', '2024-12-17 05:00:00', NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(180, 'حمید', 'مظفری', 'hamid_mozafari68', NULL, '6491529412', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(181, 'Memo', NULL, 'Memo25252525', NULL, '23635372', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(182, 'H', 'Mokhtari', 'mokhtari_ie', NULL, '289856660', '{\"name\":\"\\ud83d\\udcca \\u0644\\u06cc\\u0633\\u062a \\u062d\\u0648\\u0627\\u0644\\u0647 \\u0647\\u0627\",\"lastname\":\"\\ud83c\\udfe7 \\u06a9\\u06cc\\u0641 \\u067e\\u0648\\u0644\",\"number\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(183, 'پدرام', 'نصیری', 'null', NULL, '514755516', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"\\u067e\\u062f\\u0631\\u0627\\u0645 \\u0646\\u0635\\u06cc\\u0631\\u06cc\",\"country\":\"\\ud83c\\uddee\\ud83c\\uddf7 \\u0627\\u06cc\\u0631\\u0627\\u0646\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"2300\",\"mablagh_pishnehad\":\"75000\",\"pay\":\"\\u0627\\u0633\\u06a9\\u0646\\u0627\\u0633\",\"info\":\"\\u067e\\u0631\\u062f\\u0627\\u062e\\u062a \\u062f\\u0631 \\u0634\\u0647\\u0631 \\u0631\\u0645 \\u0648 \\u0648\\u0627\\u0631\\u06cc\\u0632 \\u062f\\u0631 \\u062d\\u0633\\u0627\\u0628 \\u0627\\u06cc\\u0631\\u0627\\u0646\"}', 0, 0, 0, 0, NULL, 0, '1', '989128444265', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(184, 'Mu', 'BA', 'null', 'verify info', '750483712', '{\"name\":\"\\u0645\\u0635\\u062a\\u0641\\u06cc \\u0628\\u0627\\u0642\\u0631\\u06cc\",\"lastname\":\"\\u0628\\u0627\\u0642\\u0631\\u06cc\",\"number\":\"4917621486104\",\"info\":\"\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(185, 'فردین', 'اسفندیاری', 'fardinnes1', NULL, '5669717977', '{\"name\":\"\\u0641\\u0631\\u062f\\u06cc\\u0646 \\u0627\\u0633\\u0641\\u0646\\u062f\\u06cc\\u0627\\u0631\\u06cc\",\"lastname\":\"\\u0627\\u0633\\u0641\\u0646\\u062f\\u06cc\\u0627\\u0631\\u06cc\",\"number\":\"905488521431\",\"info\":\"ok\"}', 0, 0, 0, 0, NULL, 0, '1', '905488521431', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(186, 'Satiar', 'Tehrani', 'null', NULL, '229405949', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(188, 'رضا', 'جعفری', 'null', 'havale ok', '7628087502', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"\\u0631\\u0636\\u0627 \\u062c\\u0639\\u0641\\u0631\\u06cc\",\"country\":\"\\ud83c\\uddf3\\ud83c\\uddf1 \\u0647\\u0644\\u0646\\u062f\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"100\",\"mablagh_pishnehad\":\"76000\",\"pay\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc\",\"info\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc \\u0641\\u0642\\u0637 \\u062f\\u0627\\u062e\\u0644 \\u0647\\u0644\\u0646\\u062f\"}', 0, 0, 0, 0, NULL, 0, '1', '31685001441', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(189, 'g', NULL, 'gghh36666g', NULL, '7100257106', '{\"name\":\"\\ud83d\\udc64 \\u0627\\u062d\\u0631\\u0627\\u0632 \\u0647\\u0648\\u06cc\\u062a\",\"lastname\":\"\\/start\",\"number\":\"989017060483\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(190, 'ایمان', NULL, 'null', NULL, '7417861533', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(191, 'امیر ', 'هادی نژاد', 'AMIRHADINE', NULL, '5533928030', '{\"name\":\"\\/start\",\"lastname\":\"\\u0627\\u0645\\u06cc\\u0631 \\u0647\\u0627\\u062f\\u06cc \\u0646\\u0698\\u0627\\u062f\",\"number\":\"989904062998\",\"info\":\"ok\",\"card\":\"553052990262056400935623266\",\"iban\":\"IR830570190211513874193001\",\"bankname\":\"\\u06af\\u0631\\u06cc\\u0648\\u0646\\u0627 \\u067e\\u0631\\u06cc\\u0648\\u0627\\u062a \\u0628\\u0627\\u0646\\u06a9\",\"empfanger\":\"\\u0425\\u0432\\u0430\\u0456\\u0442\\u0435\\u0440 \\u041c\\u043e\\u0445\\u0430\\u043c\\u043c\\u0435\\u0434\"}', 0, 0, 0, 0, NULL, 0, '1', '989904062998', 0, 0, 'Хваітер Мохаммед', 'گریونا پریوات بانک', 'IR830570190211513874193001', '5530529902620564009356232', NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(192, 'محمد', 'همتي', 'MH_873', 'havale type', '5032068773', '{\"type\":\"\",\"name\":\"\",\"country\":\"\",\"arz\":\"\",\"meghdar_arz\":\"\",\"mablagh_pishnehad\":\"\",\"pay\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '1', '989126505323', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(193, 'امیرفرزان', 'کرامتی', 'AFK84', NULL, '327202923', '{\"mablagh_pishnehad\":\"81000\",\"meghdar\":\"5000\",\"vipuser\":\"Neither user is VIP\",\"sms\":\"\"}', 0, 0, 1, 0, '2026-02-28 18:02:51', 0, '1', '989126336080', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(194, 'Ismail', NULL, 'null', NULL, '5946581385', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(195, 'پالیزا', 'مرادی', 'null', 'mozayede send 826', '453766452', '{\"mablagh_pishnehad\":\"\",\"meghdar\":\"\",\"vipuser\":\"442512457\",\"sms\":\"\"}', 0, 0, 0, 1, NULL, 0, '1', '989011222703', 0, 0, 'Paliza Moradi', 'یورو\nRevolut', 'ES5715830001179088696661', '0000', NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEBeixocTZ25QcBDe6SCe0HPqzthrobXAACBckxGxdZiVNX60VU7_5lrgEAAwIAA3kAAzYE', NULL, 0.00, NULL),
(196, 'Diamond', NULL, 'Royanp', NULL, '960316298', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(197, 'Z', NULL, 'wwwdotxyzee', 'verify info', '6046652257', '{\"name\":\"\\u0632\\u0647\\u0631\\u0627\",\"lastname\":\"\\u0641\",\"number\":\"995595026760\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(198, 'سید محمد', 'حسینی موغاری', 'Hosseini_SM', NULL, '51458859', '{\"type\":\"\\u062e\\u0631\\u06cc\\u062f\\u0627\\u0631\",\"name\":\"\\u0645\\u062d\\u0645\\u062f\",\"country\":\"\\ud83c\\udde9\\ud83c\\uddea \\u0622\\u0644\\u0645\\u0627\\u0646\",\"arz\":\"\\u062a\\u062a\\u0631\",\"meghdar_arz\":\"10000\",\"mablagh_pishnehad\":\"80500\",\"pay\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc\",\"info\":\"\\u0628\\u062f\\u0648\\u0646 \\u062a\\u0648\\u0636\\u06cc\\u062d\\u0627\\u062a\"}', 0, 0, 0, 0, NULL, 0, '1', '989123205851', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(199, 'بهـروز', 'اکرام', 'Behrouz_xar71', NULL, '6725041473', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(200, 'وحید', 'رضایی', 'Vahid00666', NULL, '101851659', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"VR\",\"country\":\"\\ud83c\\uddf8\\ud83c\\uddea \\u0633\\u0648\\u0626\\u062f\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"864\",\"mablagh_pishnehad\":\"82500\",\"pay\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc\",\"info\":\"\\u0627\\u0646\\u062a\\u0642\\u0627\\u0644 \\u0633\\u0631\\u06cc\\u0639 \\u0627\\u0632 \\u0631\\u0648\\u0644\\u0648\\u062a\"}', 0, 0, 0, 0, NULL, 0, '1', '46725702463', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(201, 'Enayat', NULL, 'ahmena2024', NULL, '5550724732', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(202, 'M.r.l', NULL, 'maralhaddadi', 'verify name', '221113091', '{\"name\":\"\",\"lastname\":\"\",\"number\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(203, 'محمدرضا', 'ايران پور', 'irezaip', NULL, '610171899', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"\\u0645\\u062d\\u0645\\u062f\\u0631\\u0636\\u0627 \\u0627\\u064a\\u0631\\u0627\\u0646 \\u067e\\u0648\\u0631\",\"country\":\"\\ud83c\\udde9\\ud83c\\uddea \\u0622\\u0644\\u0645\\u0627\\u0646\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"600\",\"mablagh_pishnehad\":\"114000\",\"pay\":\"\\u0627\\u0633\\u06a9\\u0646\\u0627\\u0633\",\"info\":\"\\u0641\\u0648\\u0631\\u064a\"}', 0, 0, 1, 0, '0000-00-00 00:00:00', 0, '1', '491745480363', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEBTp1nwHphgOHwQ4Q32z_WC9dB96bRXwACv8cxG-JbCFJC7uOta3eoYwEAAwIAA3gAAzYE', NULL, 0.00, NULL),
(204, 'm', 'm', 'mirzaali_m', NULL, '180628754', '{\"name\":\"\\u0645\\u062d\\u0645\\u062f\",\"lastname\":\"\\u0645\",\"number\":\"989125959924\",\"info\":\"ok\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(205, 'علی', 'رضائی', 'Rabinhudi', NULL, '7495091227', '{\"name\":\"\\u0639\\u0644\\u06cc\",\"lastname\":\"\\u0631\\u0636\\u0627\\u0626\\u06cc\",\"number\":\"989301725842\",\"info\":\"ok\"}', 0, 0, 0, 0, NULL, 0, '1', '989301725842', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(206, '&', NULL, 'null', NULL, '8076128074', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(207, 'Emen', 'Aria', 'EmenAria', NULL, '888517743', '{\"name\":\"\\u0645\\u062d\\u0633\\u0646\",\"lastname\":\"\\u06a9\\u0631\\u06cc\\u0645\\u06cc\",\"number\":\"46761817560\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(208, 'مهرنوش', 'شوندی', 'mehrnoosh_sh96', NULL, '102524607', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"\\u0645\\u0647\\u0631\\u0646\\u0648\\u0634 \\u0634\\u0648\\u0646\\u062f\\u06cc\",\"country\":\"\\ud83c\\udde9\\ud83c\\uddea \\u0622\\u0644\\u0645\\u0627\\u0646\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"110\",\"mablagh_pishnehad\":\"86600\",\"pay\":\"\\u067e\\u0640\\u06cc\\u0640\\u067e\\u0640\\u0640\\u0627\\u0644\",\"info\":\"\\u0628\\u062f\\u0648\\u0646 \\u062a\\u0648\\u0636\\u06cc\\u062d\\u0627\\u062a\"}', 0, 0, 0, 0, NULL, 0, '1', '491737850024', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgIAAxkBAAEBP1tnkONzCBr4fKH_iCgVgqIuv6Cv3AACKesxGxm-iEihsRo_h6CoeAEAAwIAA3kAAzYE', NULL, 0.00, NULL),
(209, 'بهنام', 'بیگلری', 'B_beglari1992', NULL, '528726934', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(210, 'جاوید', 'حسن زاده', 'Jawedhassanzade', 'havale ok', '146629599', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"\\u062c\\u0627\\u0648\\u06cc\\u062f \\u062d\\u0633\\u0646 \\u0632\\u0627\\u062f\\u0647\",\"country\":\"\\ud83c\\udde9\\ud83c\\uddea \\u0622\\u0644\\u0645\\u0627\\u0646\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"100\",\"mablagh_pishnehad\":\"133000\",\"pay\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc | Bank Transfer\",\"info\":\"\\u0628\\u062f\\u0648\\u0646 \\u062a\\u0648\\u0636\\u06cc\\u062d\\u0627\\u062a | No notes\"}', 0, 0, 0, 0, NULL, 0, '1', '491743817974', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgIAAxkBAAEBqY9o6jPdI7vUZgRDTP8lNr1285QwxAACHvsxG7nKUEv6Qn71KXiR9AEAAwIAA3gAAzYE', NULL, 0.00, NULL),
(211, 'zm', 'zm', 'hm4411', 'verify name', '102842524', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(212, 'Kareem', '‌ Peter Pan paid helmet Donald Trump won\'t Chow pizza mad. Fuck', 'null', NULL, '6767310558', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(213, 'Alien', NULL, 'null', NULL, '35260437', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(214, 'بابک', 'موسوی', 'BabakM1200', 'havale ok', '778283030', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"\\u0628\\u0627\\u0628\\u06a9 \\u0645\\u0648\\u0633\\u0648\\u06cc\",\"country\":\"\\ud83c\\udde9\\ud83c\\uddea \\u0622\\u0644\\u0645\\u0627\\u0646\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"3000\",\"mablagh_pishnehad\":\"82500\",\"pay\":\"\\u0627\\u0633\\u06a9\\u0646\\u0627\\u0633\",\"info\":\"\\u0628\\u062f\\u0648\\u0646 \\u062a\\u0648\\u0636\\u06cc\\u062d\\u0627\\u062a\"}', 0, 0, 0, 0, NULL, 0, '1', '491729458662', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(215, 'سید میثم ', 'موسوی فرخی', 'null', NULL, '6554005796', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"\\u0633\\u06cc\\u062f \\u0645\\u06cc\\u062b\\u0645  \\u0645\\u0648\\u0633\\u0648\\u06cc \\u0641\\u0631\\u062e\\u06cc\",\"country\":\"\\ud83c\\uddf8\\ud83c\\uddea \\u0633\\u0648\\u0626\\u062f\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"100\",\"mablagh_pishnehad\":\"83400\",\"pay\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc\",\"info\":\"\\u0628\\u062f\\u0648\\u0646 \\u062a\\u0648\\u0636\\u06cc\\u062d\\u0627\\u062a\"}', 0, 0, 0, 0, NULL, 0, '1', '46729199789', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(216, 'میثم ', 'موسوی فرخی', NULL, NULL, '46729199789', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(217, 'Amir Hosein', 'Anousheh', 'Anousheh13088', NULL, '160405876', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(218, '♡', '...', 'null', 'verify info', '7273058249', '{\"name\":\"\\u067e\\u0646\\u0627\\u0647\\u06cc\",\"lastname\":\"\\u067e\\u0646\\u0627\\u0647\\u06cc\",\"number\":\"989918628582\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(219, 'Open World', NULL, 'NiddleOne1', NULL, '1698882678', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(220, 'گر میگزرد غمی نیست', 'تاجیک', 'null', NULL, '6708090227', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(221, 'A.l⁮⁯⁮⁯⁮⁯', 'A⁮⁯', 'Alirea', NULL, '1016239559', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(222, 'Bita', NULL, 'Bita1986', NULL, '6271111069', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(223, 'Sadegh', 'Bakhtiarzadeh', 'sadbakh', NULL, '646873916', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(224, 'Isatis', NULL, 'matihan', NULL, '69481369', '{\"name\":\"\\u062c\\u0648\\u0627\\u062f \\u0645\\u0646\\u0635\\u0648\\u0631\\u06cc\",\"lastname\":\"\\u0645\\u0646\\u0635\\u0648\\u0631\\u06cc\",\"number\":\"+989121151125\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(225, 'Vili', NULL, 'null', NULL, '7381228477', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(226, 'vas', NULL, 'Vaseane', NULL, '5908820866', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(227, 'ساسان', 'سیاوشی', 'svsasan', NULL, '123298515', '{\"name\":\"\\u0633\\u0627\\u0633\\u0627\\u0646\",\"lastname\":\"\\u0633\\u06cc\\u0627\\u0648\\u0634\\u06cc\",\"number\":\"380973092786\",\"info\":\"ok\"}', 0, 0, 0, 0, NULL, 0, '1', '380973092786', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(228, 'محمد', 'حسینی', 'null', NULL, '7208538669', '{\"type\":\"\\u062e\\u0631\\u06cc\\u062f\\u0627\\u0631\",\"name\":\"\\u0645\\u062d\\u0645\\u062f \\u062d\\u0633\\u06cc\\u0646\\u06cc\",\"country\":\"\\ud83c\\udde9\\ud83c\\uddea \\u0622\\u0644\\u0645\\u0627\\u0646\",\"arz\":\"\\u062a\\u062a\\u0631\",\"meghdar_arz\":\"1250\",\"mablagh_pishnehad\":\"79500\",\"pay\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc\",\"info\":\"\\u062a\\u0633\\u0648\\u06cc\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc \\u0645\\u0644\\u06cc \\u0622\\u0646\\u06cc\\u060c \\u0628\\u0642\\u06cc\\u0647 \\u0628\\u0627\\u0646\\u06a9 \\u0647\\u0627 \\u067e\\u0627\\u06cc\\u0627 \\u06cc\\u0627 \\u0633\\u0627\\u062a\\u0646\\u0627\"}', 0, 0, 0, 0, NULL, 0, '1', '4915755051700', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(229, 'Farzad', NULL, 'null', NULL, '7764454999', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(230, 'AMEX', NULL, 'Amex0098', 'verify info', '5412957149', '{\"name\":\"\\u0645\\u062d\\u0645\\u062f \\u0645\\u0647\\u062f\\u06cc\",\"lastname\":\"\\u0627\\u062d\\u0645\\u062f\\u0632\\u0627\\u062f\\u0647\",\"number\":\"989010610400\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(231, '.', NULL, 'dmytro_adm', NULL, '835058137', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(232, 'Behzad', 'Tehrani', 'Behzadteh', NULL, '94062643', '{\"name\":\"\\u0628\\u0647\\u0632\\u0627\\u062f\",\"lastname\":\"\\u0637\\u0647\\u0631\\u0627\\u0646\\u06cc\",\"number\":\"+989123933440\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(233, '.', NULL, 'null', NULL, '119146373', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(234, 'محمد', 'کریمیان', 'null', NULL, '5161580683', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"\\u0645\\u062d\\u0645\\u062f \\u06a9\\u0631\\u06cc\\u0645\\u06cc\\u0627\\u0646 \\u06a9\\u0631\\u06cc\\u0645\\u06cc\\u0627\\u0646\",\"country\":\"\\ud83c\\uddee\\ud83c\\uddf7 \\u0627\\u06cc\\u0631\\u0627\\u0646\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"310\",\"mablagh_pishnehad\":\"86500\",\"pay\":\"\\u067e\\u0640\\u06cc\\u0640\\u067e\\u0640\\u0640\\u0627\\u0644\",\"info\":\"ok\",\"card\":\"6037697511758499\",\"iban\":\"IR790190000000112823138008\",\"bankname\":\"\\u0631\\u06cc\\u0627\\u0644 \\u0628\\u0627\\u0646\\u06a9 \\u0635\\u0627\\u062f\\u0631\\u0627\\u062a\",\"empfanger\":\"\\u0645\\u062d\\u0645\\u062f \\u06a9\\u0631\\u06cc\\u0645\\u06cc\\u0627\\u0646\"}', 0, 0, 0, 0, NULL, 0, '1', '989381307899', 0, 0, 'محمد کریمیان', 'ریال بانک صادرات', 'IR790190000000112823138008', '6037697511758499', NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEBQFtnkkgnaai9zT6ZkhiUZwJ2YMlc2QAC6sUxG0efkVA-1s8AAZCMBUgBAAMCAAN5AAM2BA', NULL, 0.00, NULL),
(235, 'Javad', NULL, 'Gbs_ggjgbgbc', NULL, '6847734771', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(236, 'Hamsafar', 'H', 'Hamsafar_hamed', NULL, '122480417', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(237, 'حسام', 'صادقی', 'Hesamsadeghy', NULL, '71221453', '{\"name\":\"\\u062d\\u0633\\u0627\\u0645\",\"lastname\":\"\\u0635\\u0627\\u062f\\u0642\\u06cc\",\"number\":\"989112078037\",\"photo\":\"AgACAgQAAxkBAAEBQdJnlcrcVv2u0MX7FCkj3NtUAVpNnwACK8cxG01TwFHH5WdLqI1anAEAAwIAA3kAAzYE\"}', 0, 0, 0, 0, NULL, 0, '1', '989112078037', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEBQdJnlcrcVv2u0MX7FCkj3NtUAVpNnwACK8cxG01TwFHH5WdLqI1anAEAAwIAA3kAAzYE', NULL, 0.00, NULL),
(238, 'Ⓝⓘⓜⓐ', NULL, 'Ich_Nima', NULL, '168929782', '{\"name\":\"\\u0645\\u062d\\u0645\\u062f\",\"lastname\":\"\\u0642\\u06cc\\u0637\\u0627\\u0646\\u0686\\u06cc\",\"number\":\"4915751141522\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(239, '🕊️', NULL, 'samo_germ', 'verify name', '8065394798', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(240, 'Rojhhat', NULL, 'null', NULL, '6919904845', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(241, '⏳', NULL, 'Mumiiiii_9', NULL, '427441178', '{\"name\":\"\\u0645\\u06cc\\u0644\\u0627\\u062f\",\"lastname\":\"\\u06a9\\u06cc\\u0627\\u0646\",\"number\":\"491637926494\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(242, 'سروش', 'کرمی', 'Soroush_k94', NULL, '5379687355', '{\"mablagh_pishnehad\":\"119000\",\"meghdar\":\"1000\",\"vipuser\":\"442512457\",\"sms\":\"\"}', 0, 0, 0, 0, NULL, 0, '1', '989189934494', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEBRE1nnlX90Py_271P_5tRHkpEPZtuawACOsoxG5_j-VCPW3qp-qVHGgEAAwIAA3kAAzYE', NULL, 0.00, NULL),
(243, 'سعیده', 'نجف زاده', 'Sa_na_za', 'havale setName', '90336409', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"\",\"country\":\"\",\"arz\":\"\",\"meghdar_arz\":\"\",\"mablagh_pishnehad\":\"\",\"pay\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '1', '989352815391', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEBRIJnnn8f9yGoEAe0QuMQ3azsSj9FnwACqsgxG0Ma-FCJNW0t2wQRbAEAAwIAA3kAAzYE', NULL, 1.50, NULL),
(244, 'Amir_', NULL, 'null', NULL, '8058370237', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(245, 'Viva', NULL, 'Vivalavida_a', NULL, '1138560684', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(246, 'پروانه', 'معصومی', 'sevdalumm', NULL, '5496496822', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"p\",\"country\":\"\\ud83c\\udde9\\ud83c\\uddea \\u0622\\u0644\\u0645\\u0627\\u0646\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"1000\",\"mablagh_pishnehad\":\"100300\",\"pay\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc\",\"info\":\"\\u067e\\u064a\\u0634\\u0646\\u0647\\u0627\\u062f \\u0643\\u0645\\u062a\\u0631 \\u0627\\u0632 899 \\u0642\\u0628\\u0648\\u0644 \\u0646\\u0645\\u064a\\u0634\\u0648\\u062f\"}', 0, 0, 0, 1, NULL, 0, '1', '4917630355862', 0, 0, 'پويان مسلمي', 'ريال بانك ملت', 'IR070120010000009046752050', '6104338692611243', NULL, NULL, NULL, NULL, 'AgACAgIAAxkBAAEBR01np4yhDpgws8gvSB4PgNutxLpkJgACQvExG78DOEn7eAAB6mdvd60BAAMCAAN5AAM2BA', NULL, 0.00, NULL),
(247, 'Queen', NULL, 'callmequeeeeeeeen', NULL, '139540114', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(248, 'صدف', 'کریمی', 'sdf977697', NULL, '902191297', '{\"name\":\"\\u0635\\u062f\\u0641\",\"lastname\":\"\\u06a9\\u0631\\u06cc\\u0645\\u06cc\",\"number\":\"393519148433\",\"photo\":\"AgACAgQAAxkBAAEBSW5nrIEza8OzmsnBBqWxIivOjWpuswACVMYxG_-KaVH7ywACVLVU6AEAAwIAA3kAAzYE\",\"card\":\"6221061068261249\",\"iban\":\"0\",\"bankname\":\"\\u067e\\u0627\\u0631\\u0633\\u06cc\\u0627\\u0646\",\"empfanger\":\"\\u0633\\u06cc\\u0646\\u0627 \\u0645\\u0647\\u06cc\\u0646 \\u0641\\u0631\",\"info\":\"ok\"}', 0, 0, 0, 0, NULL, 0, '1', '393519148433', 0, 0, 'سینا مهین فر', 'پارسیان', '0', '6221061068261249', NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEBSW5nrIEza8OzmsnBBqWxIivOjWpuswACVMYxG_-KaVH7ywACVLVU6AEAAwIAA3kAAzYE', NULL, 0.00, NULL),
(249, 'Sahar Ka', NULL, 'Sahar_kama', NULL, '7619213184', '{\"name\":\"\\u0633\\u0645\\u06cc\\u0647\",\"lastname\":\"\\u062d\\u0642 \\u0648\\u0631\\u062f\\u06cc\",\"number\":\"989032084039\",\"photo\":\"AgACAgQAAxkBAAEBSsNnsgMhE6S69dMVNfx9SU9SFlRNFAACKskxGxITkVGELmNLwagzGQEAAwIAA3gAAzYE\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(250, 'Hosein', 'Bahreini', 'h_bahreini', NULL, '47410582', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(251, 'حامد', 'شیرازی', 'Hmdsrz', NULL, '42791669', '{\"mablagh_pishnehad\":\"170000\",\"meghdar\":\"500\",\"vipuser\":\"Neither user is VIP\",\"sms\":\"\"}', 0, 0, 0, 0, NULL, 0, '1', '989120960612', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEBS7FntKnLkZNrjFW13TtZ1YhGf2_DuAACMcQxGzJrqFHOI2k_3mE9zAEAAwIAA3kAAzYE', NULL, 0.00, NULL),
(252, 'مهدی', 'فتح آبادی', 'FATHEX', NULL, '227711492', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"\\u0645\\u0647\\u062f\\u06cc \\u0641\\u062a\\u062d \\u0622\\u0628\\u0627\\u062f\\u06cc\",\"country\":\"\\ud83c\\uddee\\ud83c\\uddf7 \\u0627\\u06cc\\u0631\\u0627\\u0646\",\"arz\":\"\\u062a\\u062a\\u0631\",\"meghdar_arz\":\"1000\",\"mablagh_pishnehad\":\"\",\"pay\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '1', '+989155517100', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEBTIlnthtreSebwTEy-u31dS49BvMxdgACdsMxGw37sVHEo9P4EeevkgEAAwIAA3kAAzYE', NULL, 0.00, NULL),
(253, 'god of war', NULL, 'LoverTHCC', 'verify info', '7356795591', '{\"name\":\"\\u0639\\u0644\\u06cc \\u0627\\u062d\\u0645\\u062f\\u06cc\",\"lastname\":\"\\u0627\\u062d\\u0645\\u062f\\u06cc\",\"number\":\"989014106464\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(254, 'Pourya', NULL, 'Pouryaov1', NULL, '6461955624', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(255, '...', NULL, 'm1381i', 'verify name', '5061634217', '{\"name\":\"\\u0645\\u0647\\u062f\\u06cc\",\"lastname\":\"\\u0631\\u0636\\u0627\\u06cc\\u06cc\",\"number\":\"33758743561\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(256, 'محمد', 'کردزنگنه', 'null', NULL, '6406534786', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(257, 'صفا گلکار', 'گلکار', 'safaglk', 'havale ok', '780535487', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"\\u0635\\u0641\\u0627 \\u06af\\u0644\",\"country\":\"\\ud83c\\uddee\\ud83c\\uddf7 \\u0627\\u06cc\\u0631\\u0627\\u0646\",\"arz\":\"\\u062f\\u0644\\u0627\\u0631 \\u06a9\\u0627\\u0646\\u0627\\u062f\\u0627\",\"meghdar_arz\":\"200\",\"mablagh_pishnehad\":\"73300\",\"pay\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc | Bank Transfer\",\"info\":\"E-Transfer\"}', 0, 0, 0, 0, NULL, 0, '1', '989147391380', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEBlHho0V2OhWWaLjzenJIUu-L_89XJ_AACgMgxGxCxkFLfR9jIYILWSwEAAwIAA3kAAzYE', NULL, 0.00, NULL),
(258, 'BEHNAM VOSUGHIAN', NULL, 'dift15', NULL, '214808885', '{\"name\":\"\\u0628\\u0647\\u0646\\u0627\\u0645\",\"lastname\":\"\\u0648\\u062b\\u0648\\u0642\\u06cc\\u0627\\u0646\",\"number\":\"4917641620982\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(259, 'محمد واقف', NULL, 'araz_sasii', NULL, '118896096', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(260, 'کامران', 'حاجی میراسماعیلی', 'KAMMIRES', NULL, '76774888', '{\"type\":\"\\u062e\\u0631\\u06cc\\u062f\\u0627\\u0631\",\"name\":\"\\u06a9\\u0627\\u0645\\u0631\\u0627\\u0646 \\u062d\\u0627\\u062c\\u06cc \\u0645\\u06cc\\u0631\\u0627\\u0633\\u0645\\u0627\\u0639\\u06cc\\u0644\\u06cc\",\"country\":\"\\ud83c\\uddee\\ud83c\\uddf7 \\u0627\\u06cc\\u0631\\u0627\\u0646\",\"arz\":\"\\u062a\\u062a\\u0631\",\"meghdar_arz\":\"500\",\"mablagh_pishnehad\":\"155000\",\"pay\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc | Bank Transfer\",\"info\":\"\\u0645\\u0628\\u062f\\u0627 \\u0648\\u0627\\u0631\\u06cc\\u0632\\u06cc \\u0627\\u0632 \\u0645\\u062d\\u0644\\u0647\\u0627\\u06cc \\u062a\\u0627\\u0632\\u0647 \\u062a\\u062d\\u0631\\u06cc\\u0645 \\u0634\\u062f\\u0647 \\u0646\\u0628\\u0627\\u0634\\u062f\"}', 0, 0, 0, 0, NULL, 0, '1', '989123081108', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEBoZto5UklUD1xgQXnUWljbZ_sqCnmAwACj8wxGyVYKVOqcH1okdh09QEAAwIAA3kAAzYE', NULL, 0.00, NULL),
(261, 'Sasan', NULL, 'Sasan_gh71', NULL, '396677884', '{\"name\":\"\\u062a\\u0642\\u06cc\",\"lastname\":\"\\u0642\\u0628\\u0627\\u062f\\u06cc\",\"number\":\"989127103953\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(262, 'Tohed', NULL, 'null', NULL, '8070695109', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(263, 'Parviz', NULL, 'Parvizlit', NULL, '7324395926', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(264, 'H', 'Yousefi', 'null', NULL, '343828324', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(265, 'محمد', 'مسائلی', 'null', 'havale ok', '7429329633', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"m.m\",\"country\":\"\\ud83c\\udde9\\ud83c\\uddea \\u0622\\u0644\\u0645\\u0627\\u0646\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"100\",\"mablagh_pishnehad\":\"98000\",\"pay\":\"\\u067e\\u0640\\u06cc\\u0640\\u067e\\u0640\\u0640\\u0627\\u0644\",\"info\":\"\\u0628\\u062f\\u0648\\u0646 \\u062a\\u0648\\u0636\\u06cc\\u062d\\u0627\\u062a\"}', 0, 0, 0, 0, NULL, 0, '1', '4917658881289', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgIAAxkBAAEBUelnzVkqpFPgDXnmBLM3b4JmhYVPAwAC-ucxG5H4cUomTYqb_1yIHwEAAwIAA3kAAzYE', NULL, 0.00, NULL),
(266, 'علی', 'ابراهیمی', 'null', NULL, '508607303', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"Ali\",\"country\":\"\\ud83c\\udde9\\ud83c\\uddea \\u0622\\u0644\\u0645\\u0627\\u0646\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"300\",\"mablagh_pishnehad\":\"100000\",\"pay\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc\",\"info\":\"\\u067e\\u0631\\u062f\\u0627\\u062e\\u062a \\u0622\\u0646\\u06cc\"}', 0, 0, 0, 0, NULL, 0, '1', '4915776499256', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgIAAxkBAAEBVBFn0wgt0R-LyPan0OZ39Ai34_Z8FQACj_ExGzBumErauo-4zMQi4gEAAwIAA3kAAzYE', NULL, 0.00, NULL),
(267, '❤︎', NULL, 'null', 'verify name', '467724528', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(268, 'Milad', NULL, 'Miladq7', NULL, '1057788458', '{\"name\":\"\\u0645\\u06cc\\u0644\\u0627\\u062f\",\"lastname\":\"\\u0642\\u062f\\u06cc\\u0645\\u06cc\",\"number\":\"989136474529\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(269, 'Milad', NULL, 'null', 'verify info', '8079212432', '{\"name\":\"\\u0645\\u06cc\\u0644\\u0627\\u062f\",\"lastname\":\"\\u0642\\u062f\\u06cc\\u0645\\u06cc\",\"number\":\"4915151802984\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(270, 'روح الله', 'محمدی', 'null', 'mozayede send 541', '7400837565', '{\"mablagh_pishnehad\":\"\",\"meghdar\":\"\",\"vipuser\":\"5008887585\",\"sms\":\"\"}', 0, 0, 0, 0, NULL, 0, '1', '989035463746', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEBYZxn-k4S3sEpDEXWLAHtBTN85JNr3QACesIxG0kT0VOSBU21WQABgxkBAAMCAAN5AAM2BA', NULL, 0.00, NULL),
(271, 'Taiga', NULL, 'PvTaiga', NULL, '7416237093', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(272, 'Mohamadreza', 'Alavi', 'Mohammadrezaalavi', 'verify info', '1103409776', '{\"name\":\"\\u0645\\u062d\\u0645\\u062f\",\"lastname\":\"\\u0639\\u0644\\u0648\\u06cc\",\"number\":\"46763497006\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(273, 'Mani', NULL, 'MA_AF_S_KH', NULL, '92327872', '{\"name\":\"\\u0645 \\u0627\",\"lastname\":\"\\u0627\",\"number\":\"989124940076\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(274, 'Artin', NULL, 'null', 'verify info', '24272379', '{\"name\":\"\\u0622\\u0631\\u062a\\u06cc\\u0646 \\u0641\\u0631\\u062e\\u0646\\u062f\\u0647\",\"lastname\":\"\\u0641\\u0631\\u062e\\u0646\\u062f\\u0647\",\"number\":\"36707903696\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(275, '𝓚𝓸𝓾℞𝓸𝓼𝓱ㅤ', NULL, 'Dvm_kourosh', NULL, '696186055', '{\"name\":\"\\u06a9\\u0648\\u0631\\u0634 \\u067e\\u0648\\u0631\\u0631\\u0645\\u0636\\u0627\\u0646\",\"lastname\":\"\\u06a9\\u0648\\u0631\\u0634 \\u067e\\u0648\\u0631\\u0631\\u0645\\u0636\\u0627\\u0646\",\"number\":\"989124401898\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(276, 'یاسر', 'امیری', 'Yaserchehelamirani', NULL, '177245294', '{\"type\":\"\",\"name\":\"\",\"country\":\"\",\"arz\":\"\",\"meghdar_arz\":\"\",\"mablagh_pishnehad\":\"\",\"pay\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '1', '989382079373', 0, 1, NULL, NULL, NULL, NULL, '2026-03-26 04:00:00', NULL, NULL, NULL, 'AgACAgQAAxkBAAEBV_pn4LfZTc7SdcXvXvGlAAFt6ER3MFsAAjvFMRt9BAhTwO0M-6yqTe0BAAMCAAN5AAM2BA', NULL, 0.00, NULL),
(277, 'A', 'a', 'aa118aa118', NULL, '5818043691', '{\"name\":\"\\u0639\\u0644\\u06cc\",\"lastname\":\"\\u0639\\u0628\\u062f\\u06cc\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(278, 'زهرا', 'اکبری صفی آبادی', 'zahraa_akbary', NULL, '6283711609', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"\\u0632\\u0647\\u0631\\u0627 \\u0627\\u06a9\\u0628\\u0631\\u06cc \\u0635\\u0641\\u06cc \\u0622\\u0628\\u0627\\u062f\\u06cc\",\"country\":\"\",\"arz\":\"\",\"meghdar_arz\":\"\",\"mablagh_pishnehad\":\"\",\"pay\":\"\",\"info\":\"\"}', 0, 0, 0, 1, NULL, 0, '1', '989192511686', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEBWHVn4eb_IZPExFb0qMxHvIqzUp5_6AACvsgxG4niEVOIQMh9iqtj8AEAAwIAA3kAAzYE', NULL, 0.00, NULL),
(279, 'სεнгυz 🇹🇷🇦🇿', '.: bır hata her şeyı değıştırır :.', 'BZ_TBZ', NULL, '131538886', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(280, '....و', '......', 'null', NULL, '7740335675', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(281, 'سارا', 'قربانی', 'sarly77', NULL, '492083645', '{\"type\":\"\\u062e\\u0631\\u06cc\\u062f\\u0627\\u0631\",\"name\":\"\\u062e\\u0631\\u06cc\\u062f\\u0627\\u0631\",\"country\":\"\\ud83c\\uddee\\ud83c\\uddf9 \\u0627\\u06cc\\u062a\\u0627\\u0644\\u06cc\\u0627\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"2000\",\"mablagh_pishnehad\":\"\",\"pay\":\"\",\"info\":\"\"}', 0, 0, 0, 3, NULL, 0, '1', '989199296558', 0, 0, 'سارا قربانی', 'ریال بلو بانک', 'IR100560611828005309111201', '6219861925515974', NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEBXUZn9LnVXPxzFeMKa9Gtnr9mhPbPVwACsMkxGxpdqFNADrQs-I__jwEAAwIAA3kAAzYE', NULL, 0.00, '90336409'),
(282, 'Rasool', NULL, 'rasool219', NULL, '1732024612', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(283, 'omid', NULL, 'tradingroom13_admin', 'verify info', '6121073849', '{\"name\":\"\\u0627\\u0645\\u06cc\\u062f\",\"lastname\":\"\\u0627\\u0645\\u06cc\\u0646\\u06cc\\u0627\\u0646\",\"number\":\"393513109001\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(284, 'Matin', NULL, 'null', NULL, '1599245558', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(285, 'A', NULL, 'Vahede_Posihtiban', NULL, '5860477850', '{\"name\":\"\\u0639\\u0633\\u06a9\\u0631\",\"lastname\":\"\\u0635\\u062f\\u0627\\u0642\\u062a\",\"number\":\"66991713700\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\",\"photo\":\"AgACAgUAAxkBAAEBYzFn_YSjo4SiFpm2duSGV1QIWUZPWAACR8gxG8lj6VePYo1_SZcZcQEAAwIAA20AAzYE\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(286, 'KMH', 'Tatar', 'kmhtatar', NULL, '2039314221', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(287, 'Ahmad', NULL, 'null', NULL, '7448937411', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(288, 'Ali', NULL, 'steelali87', NULL, '87126888', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(289, 'Saeed', 'Ch', 'SaeedChinich', 'verify info', '176035313', '{\"name\":\"\\u0633\\u0639\\u06cc\\u062f\",\"lastname\":\"\\u0686\",\"number\":\"+989122570401\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(290, 'جایر', 'اکبری', 'bdhhsbd', NULL, '6874072029', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(291, 'Ahmad11', NULL, 'null', 'verify number', '1555137744', '{\"name\":\"\\u0627\\u062d\\u0645\\u062f\",\"lastname\":\"\\u062d\\u0633\\u06cc\\u0646\\u06cc\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(292, 'علیرضا', 'نظری', 'beast0333', 'havale ok', '424573447', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"Ali\",\"country\":\"\\ud83c\\uddf8\\ud83c\\uddea \\u0633\\u0648\\u0626\\u062f\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"100\",\"mablagh_pishnehad\":\"91700\",\"pay\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc\",\"info\":\"Wise\"}', 0, 0, 0, 1, NULL, 0, '1', '46737129548', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEBZVVoDFHjVyHEeNXzBM055_gIVNslNgACHMoxG6S1YFB7uf4t9kvs5gEAAwIAA3kAAzYE', NULL, 0.00, NULL),
(293, 'Farbod Faraji', NULL, 'farbodfaraji', NULL, '98410472', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(294, 'Nova', NULL, 'Novasd', NULL, '5364956575', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(295, 'Mahdi Ardestani', NULL, 'M_Ardestani1992', 'verify info', '5293662479', '{\"name\":\"\\u0645\\u0647\\u062f\\u06cc \\u0627\\u0631\\u062f\\u0633\\u062a\\u0627\\u0646\\u06cc \\u0645\\u0642\\u062f\\u0645\",\"lastname\":\"\\u0627\\u0631\\u062f\\u0633\\u062a\\u0627\\u0646\\u06cc \\u0645\\u0642\\u062f\\u0645\",\"number\":\"989122713315\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(296, 'Vendita Ingrosso Doner Kebab', 'Hasan ( S.A )', 'null', NULL, '118678619', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(297, 'Jut', NULL, 'null', NULL, '8140914533', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(298, 'Amir', NULL, 'big_brother90', 'verify number', '743087186', '{\"3rdpartyname\":\"\\u2733\\ufe0f \\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"3rdipayiban\":\"\\u2705 \\u0628\\u0644\\u0647 , \\u0627\\u062f\\u0627\\u0645\\u0647\\u00bb\",\"name\":\"\\u0627\\u0645\\u06cc\\u0631\\u062d\\u0633\\u06cc\\u0646\",\"lastname\":\"\\u0631\\u0636\\u0627\\u06cc\\u06cc\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(299, 'Reza', NULL, 'realllreza', 'verify info', '115725928', '{\"name\":\"\\u0645\\u062d\\u0645\\u062f\\u0631\\u0636\\u0627\",\"lastname\":\"\\u0631\\u0636\\u0627\\u06cc\\u06cc\",\"number\":\"4917641993095\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(300, 'M', '...R', 'M_R_1976', NULL, '149448154', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(301, 'امیرحسین', 'نامجو', 'null', NULL, '6460314470', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(302, 'Farnaz', NULL, 'frz_phz', NULL, '568252756', '{\"name\":\"\\u0641\\u0631\\u0646\\u0627\\u0632\",\"lastname\":\"\\u067e\\u06cc\\u0631\\u0632\\u0627\\u062f\\u0647\",\"number\":\"989153819592\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(303, '☺️', NULL, 'aamrtt', NULL, '134046832', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(304, '⚜️Nahid⚜️', NULL, 'Nahiid7474', NULL, '189410600', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(305, 'APS', NULL, 'null', 'verify 3rdipayconfirm', '1067437700', '{\"3rdpartyname\":\"\\u2733\\ufe0f \\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"3rdipayiban\":\"Yes\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(306, 'سید مقبول', 'صافی', 'null', NULL, '7410010818', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"\\u0633\\u06cc\\u062f \\u0645\\u0642\\u0628\\u0648\\u0644 \\u0635\\u0627\\u0641\\u06cc\",\"country\":\"\\ud83c\\uddee\\ud83c\\uddf7 \\u0627\\u06cc\\u0631\\u0627\\u0646\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"423\",\"mablagh_pishnehad\":\"91000\",\"pay\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc\",\"info\":\"ok\",\"card\":\"5029381045472586\",\"iban\":\"720660000000306009389009\",\"bankname\":\"\\u0631\\u06cc\\u0627\\u0644 \\u0628\\u0627\\u0646\\u06a9 \\u062f\\u06cc\",\"empfanger\":\"\\u0633\\u06cc\\u062f \\u0645\\u0642\\u0628\\u0648\\u0644 \\u0635\\u0627\\u0641\\u06cc\"}', 0, 0, 0, 1, NULL, 0, '1', '989029519354', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEBaz9oKZ1kPKmE24tgLfg2SrfneY2XLgACRc0xG-xFUFGy-_1SbxJNjwEAAwIAA3kAAzYE', NULL, 0.00, NULL),
(307, 'Jan', 'Farzad', 'FarzadSH07', 'verify info', '5980775285', '{\"name\":\"\\u0641\\u0631\\u0632\\u0627\\u062f\",\"lastname\":\"\\u0634\\u06cc\\u0631\\u0632\\u0627\\u062f\",\"number\":\"93765787223\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(308, 'Hicham', 'KDR', 'null', NULL, '7048386957', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL);
INSERT INTO `account` (`id`, `name`, `lastname`, `username`, `step`, `chat_id`, `data`, `doller`, `euro`, `mozayede_Cancell`, `mozayede_ok`, `date_ban`, `ban`, `verified`, `phone`, `rial`, `Vip`, `empfanger`, `bankname`, `iban`, `card`, `expire_vip`, `network`, `wallet_adress`, `payint`, `documents`, `datapay`, `euroinviter`, `inviter`) VALUES
(309, 'جعفر', 'ماشابااوجی', 'Mash6', 'havale ok', '114838273', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"J.m\",\"country\":\"\\ud83c\\udde9\\ud83c\\uddea \\u0622\\u0644\\u0645\\u0627\\u0646\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"1000\",\"mablagh_pishnehad\":\"118000\",\"pay\":\"Paypal | \\u067e\\u06cc\\u067e\\u0627\\u0644\",\"info\":\"\\u0648\\u0627\\u0631\\u06cc\\u0632 \\u0627\\u0646\\u06cc \\u067e\\u06cc \\u067e\\u0644 \\u06cc\\u0627 \\u0634\\u0645\\u0627\\u0631\\u0647 \\u062d\\u0633\\u0627\\u0628 \\u0627\\u0644\\u0645\\u0627\\u0646\"}', 0, 0, 0, 9, NULL, 0, '1', '4915773535735', 0, 1, 'روزیتا ماشابااوجی', 'ریال بانک پاسارگاد', 'IR580570170680012456979101', '5022291049144858', '2025-09-04 22:00:00', NULL, NULL, NULL, 'AgACAgQAAxkBAAEBbNFoMAjazo8h3BbFXra49QI0ZrpnJQACEccxG4-CiFGVI8GTyUhrnAEAAwIAA3kAAzYE', NULL, 0.00, NULL),
(310, 'Mesi', 'Far', 'mefar2023', 'verify info', '112878445', '{\"name\":\"\\u0645\\u06cc\\u062b\\u0645\",\"lastname\":\"\\u0641\\u0631\\u062e\\u06cc \\u0641\\u0631\",\"number\":\"989214077938\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(311, 'صرافی ایران هند', 'Iran india exchange', 'IranIndiaexchange', NULL, '5631783085', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(312, 'Amir', NULL, 'amir_hhhosein', NULL, '215580903', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(313, 'ملودی', 'M', 'null', NULL, '6098307937', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(314, 'S_afg', NULL, 'Sh1371525', NULL, '6591757216', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(315, 'Y.M🦋', NULL, 'Ya3navi', NULL, '86120343', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(316, 'امید', 'آذر', 'omidazar69', 'verify info', '5629268434', '{\"name\":\"\\u0627\\u0645\\u06cc\\u062f\",\"lastname\":\"\\u0622\\u0630\\u0631\\u06cc\\u0627\\u0646\",\"number\":\"989141877116\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(317, 'Atiyeh', NULL, 'kratie20', 'verify info', '343649975', '{\"name\":\"\\u0639\\u0637\\u06cc\\u0647\",\"lastname\":\"\\u06a9\\u0631\\u06cc\\u0645\\u06cc\",\"number\":\"421944240564\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(318, 'صرافی', NULL, 'exchange473', NULL, '7813333859', '{\"name\":\"\\u0628\\u0646\\u06cc\\u0646\\u06cc\\u0646 \\u06cc\\u0646\\u06cc\\u0646\\u06cc\\u0645\",\"lastname\":\"\\u0628\\u0646\\u0628\\u0646\\u0628\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(319, '۱۶۱۶۱', NULL, 'null', NULL, '6594018541', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(320, 'Ali', 'Hosseini', 'Dwbwd', NULL, '1792184120', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(321, 'Reza', 'Alidoust', 'RezaAlidoust', NULL, '60716027', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(322, 'ابوالحسن', 'مخلص زاده', 'amukh', NULL, '299149307', '{\"name\":\"\\u0627\\u0628\\u0648\\u0627\\u0644\\u062d\\u0633\\u0646\",\"lastname\":\"\\u0645\\u062e\\u0644\\u0635 \\u0632\\u0627\\u062f\\u0647\",\"number\":\"46760723512\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\",\"photo\":\"AgACAgQAAxkBAAEBcW9oQxetZ98GJmL4XRV2d1X6tiPqGwACdcYxG6hjGVK1v0OiFCDsOwEAAwIAA3kAAzYE\"}', 0, 0, 0, 0, NULL, 0, '1', '46760723512', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEBcW9oQxetZ98GJmL4XRV2d1X6tiPqGwACdcYxG6hjGVK1v0OiFCDsOwEAAwIAA3kAAzYE', NULL, 0.00, NULL),
(323, 'تانتدپدد', NULL, 'Rjchcn', NULL, '7504287703', '{\"3rdpartyname\":\"\\u2733\\ufe0f \\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"3rdipayiban\":\"\\u2705 \\u0628\\u0644\\u0647 , \\u0627\\u062f\\u0627\\u0645\\u0647\\u00bb\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(324, 'Al', 'Az', 'null', 'verify name', '6826393501', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(325, 'Mahdi', NULL, 'Mahdi20s06', NULL, '6498035771', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(326, 'parsae', NULL, 'Golnarparsa', NULL, '5793676620', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(327, 'Ashkan', '04p', 'Ashkan_04p', NULL, '108824099', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(328, 'Davood', 'Sh', 'davoodsh1979', NULL, '5234348622', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(329, 'AMHACR', NULL, 'Golmoradok', NULL, '7499370331', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(330, 'بنفشه رحیمی', 'Wrtdvgdv', 'null', NULL, '7111128912', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(331, '𝑫𝒓.𝒎𝒐𝒉𝒂𝒎𝒎𝒆𝒅 𝒂𝒍𝒔𝒚𝒂𝒅𝒊', NULL, 'Dr_MOHAMMED_ALSYADY', NULL, '7909674759', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(332, 'علیرضا', 'عزیزی', 'null', 'havale mablagh_pishnehad', '1018935853', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"\\u0639\\u0644\\u06cc\\u0631\\u0636\\u0627 \\u0639\\u0632\\u06cc\\u0632\\u06cc\",\"country\":\"\\u0628\\u0642\\u06cc\\u0647 \\u06a9\\u0634\\u0648\\u0631 \\u0647\\u0627\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"1000\",\"mablagh_pishnehad\":\"\",\"pay\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '1', '37360253972', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgIAAxkBAAEBdopoZlaZ_AiLBMyvUtS34NhGAdEd6AACgwABMhutjTlL3ne9Ypys9usBAAMCAAN5AAM2BA', NULL, 0.00, NULL),
(333, 'مریم', 'دادگر', 'themimdal', 'havale ok', '5772271156', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"\\u0645\\u0631\\u06cc\\u0645 \\u062f\\u0627\",\"country\":\"\\ud83c\\uddee\\ud83c\\uddf7 \\u0627\\u06cc\\u0631\\u0627\\u0646\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"760\",\"mablagh_pishnehad\":\"104500\",\"pay\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc\",\"info\":\"\\u0628\\u062f\\u0648\\u0646 \\u062a\\u0648\\u0636\\u06cc\\u062d\\u0627\\u062a\"}', 0, 0, 0, 0, NULL, 0, '1', '989039138163', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEBd0ZoahJu-WSN8nt6drrJP4CCiNHpPQACh8sxG_n5UVM3RX-CdCPibQEAAwIAA3kAAzYE', NULL, 0.00, NULL),
(334, 'محمدرضا', 'روایی', 'null', NULL, '7677550730', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"\\u0645\\u062d\\u0645\\u062f\\u0631\\u0636\\u0627 \\u0631\\u0648\\u0627\\u06cc\\u06cc\",\"country\":\"\\ud83c\\uddee\\ud83c\\uddf9 \\u0627\\u06cc\\u062a\\u0627\\u0644\\u06cc\\u0627\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"200\",\"mablagh_pishnehad\":\"104000\",\"pay\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc\",\"info\":\"\\u0648\\u0627\\u0631\\u06cc\\u0632 \\u0631\\u0648\\u0648\\u0644\\u0648\\u062a\"}', 0, 0, 0, 0, NULL, 0, '1', '393381843793', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEBe39oeQecjAq36JlQnbWZI7Sa4y1bSgAC8M0xG_YkyFM5HPUAAcLO4zkBAAMCAAN5AAM2BA', NULL, 0.00, NULL),
(335, '𝗛𝗮𝘀𝗮𝗻 ʏᴇ ɴɪᴋ', NULL, 'JaaaC', NULL, '922746466', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(336, 'Ali', 'S', 'null', NULL, '6442279869', '{\"name\":\"\\u0639\\u0644\\u06cc\",\"lastname\":\"\\u0642\\u0636\\u0648\\u06cc\",\"number\":\"989153835921\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(337, 'Mohammad', 'Majidzadeh', 'momajidz', NULL, '90579715', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(338, 'میلاد', 'صادق سمیعی', 'null', NULL, '5460556288', '{\"type\":\"\\u062e\\u0631\\u06cc\\u062f\\u0627\\u0631\",\"name\":\"\\u0645\\u06cc\\u0644\\u0627\\u062f\",\"country\":\"\\ud83c\\uddee\\ud83c\\uddf7 \\u0627\\u06cc\\u0631\\u0627\\u0646\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"50\",\"mablagh_pishnehad\":\"104500\",\"pay\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc\",\"info\":\"\\u0641\\u0642\\u0637 \\u0631\\u0648\\u0644\\u0648\\u062a \\u0645\\u06cc\\u062e\\u0648\\u0627\\u0645\",\"3rdpartyname\":\"\\u2733\\ufe0f \\u062e\\u0631\\u06cc\\u062f\\u0627\\u0631\",\"3rdipayiban\":\"\\u2705 \\u0628\\u0644\\u0647 , \\u0627\\u062f\\u0627\\u0645\\u0647\\u00bb\"}', 0, 0, 0, 0, NULL, 0, '1', '37491287883', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEBfltojhnmYmNy57AJNN2ADcqbKjE-QQACksoxGyYEcFBSUyf08KvsKAEAAwIAA3kAAzYE', NULL, 0.00, NULL),
(339, 'سپهر', NULL, 'sepehr2242', NULL, '238430323', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(340, 'مریم', 'اسماعیلی', 'es_mine', 'verify infohesab', '122193585', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"\\u0645\\u0631\\u06cc\\u0645 \\u0627\\u0633\\u0645\\u0627\\u0639\\u06cc\\u0644\\u06cc\",\"country\":\"\\ud83c\\uddee\\ud83c\\uddf9 \\u0627\\u06cc\\u062a\\u0627\\u0644\\u06cc\\u0627\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"100\",\"mablagh_pishnehad\":\"105000\",\"pay\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc\",\"info\":\"\\u0631\\u0648\\u0648\\u0644\\u0648\\u062a \\u0628\\u0648\\u0646\\u06cc\\u0641\\u06cc\\u06a9\\u0648 \\u0648\\u0627\\u06cc\\u0632\",\"card\":\"6037691594607368\",\"iban\":\"IR450190000000336055819003\",\"bankname\":\"\\u0631\\u06cc\\u0627\\u0644 \\u0628\\u0627\\u0646\\u06a9 \\u0635\\u0627\\u062f\\u0631\\u0627\\u062a\",\"empfanger\":\"\\u0645\\u0631\\u06cc\\u0645 \\u0627\\u0633\\u0645\\u0627\\u0639\\u06cc\\u0644\\u06cc\"}', 0, 0, 0, 0, NULL, 0, '1', '989139199298', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEBgRdomNJ1s5yr1FC1ujGJOee7oweX5QACsMwxG2R8wVC0j2NzOKK2bgEAAwIAA3gAAzYE', NULL, 0.00, NULL),
(341, 'Majid', 'Rezaei', 'MajidRezaei5', NULL, '421330297', '{\"name\":\"\\u0639\\u0628\\u062f\\u0627\\u0644\\u0645\\u062c\\u06cc\\u062f\",\"lastname\":\"\\u0631\\u0636\\u0627\\u06cc\\u06cc\",\"number\":\"989172929935\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(342, 'میثم', 'گل سوار', 'meysam_golsavar', NULL, '51789689', '{\"mablagh_pishnehad\":\"119000\",\"meghdar\":\"125\",\"vipuser\":\"Neither user is VIP\",\"sms\":\"\"}', 0, 0, 1, 0, '2025-10-27 15:10:01', 0, '1', '989394005056', 0, 0, NULL, NULL, NULL, NULL, '2025-09-04 22:00:00', NULL, NULL, NULL, 'AgACAgQAAxkBAAEBgchonWD0FEhc3MLCY6Vl-a74KIPK6AAC3MoxG46H8VCxBQYPCpKTNwEAAwIAA3kAAzYE', NULL, 0.00, NULL),
(343, 'مسعود', 'آزادی', 'Masoud_Azadi1', NULL, '105933589', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(344, 'Amir', NULL, 'amirdraws', 'verify lastname', '121065700', '{\"name\":\"\\u0627\\u0645\\u06cc\\u0631 \\u0628\\u0631\\u0642\\u0639\\u06cc\",\"lastname\":\"\\u0628\\u0631\\u0642\\u0639\\u06cc\",\"number\":\"989366974163\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(345, 'Nima', NULL, 'Nima_panahandeh', 'verify name', '7345304468', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(346, 'ساناز ', 'سادات قریشی', 'sanaz_gh77', NULL, '433693463', '{\"type\":\"\\u062e\\u0631\\u06cc\\u062f\\u0627\\u0631\",\"name\":\"\\u0633\\u0627\\u0646\\u0627\\u0632  \\u0633\\u0627\\u062f\\u0627\\u062a \\u0642\\u0631\\u06cc\\u0634\\u06cc\",\"country\":\"\\ud83c\\udde9\\ud83c\\uddea \\u0622\\u0644\\u0645\\u0627\\u0646\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"100\",\"mablagh_pishnehad\":\"109000\",\"pay\":\"\\u067e\\u0640\\u06cc\\u0640\\u067e\\u0640\\u0640\\u0627\\u0644\",\"info\":\"\\u0627\\u0632 \\u0637\\u0631\\u06cc\\u0642 \\u0631\\u0648\\u0648\\u0644\\u0648\\u062a \\u0648 \\u067e\\u06cc \\u067e\\u0627\\u0644 \\u0645\\u06cc\\u062a\\u0648\\u0646\\u0645. \\u062f\\u0631\\u06cc\\u0627\\u0641\\u062a \\u06a9\\u0646\\u0645\"}', 0, 0, 0, 0, NULL, 0, '1', '989134629562', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEBha9orstbSj9Zl8L5sLJ09tDN8ZUbIwACucYxG5COeFEAAbA9fOl3bj0BAAMCAAN5AAM2BA', NULL, 0.00, NULL),
(347, 'Mehran', NULL, 'Mehran_k1984', NULL, '93550232', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(348, '@', NULL, 'ggpigfdg', NULL, '6622819040', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(349, 'Yashar', NULL, 'MrYashar', NULL, '1554012440', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(350, 'Sadeq', NULL, 'sadegh_h05', NULL, '91198072', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(351, 'Alireza', NULL, 'alirezxo', NULL, '6615288445', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(352, 'Pardis', NULL, 'null', NULL, '6805737428', '{\"name\":\"\\u067e\\u0631\\u062f\\u06cc\\u0633\",\"lastname\":\"\\u0635\\u0627\\u0631\\u0645\\u06cc\",\"number\":\"46793126294\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(353, 'Zvon', 'Ultra', 'g102102', NULL, '7649475312', '{\"3rdpartyname\":\"\\u2733\\ufe0f \\u062e\\u0631\\u06cc\\u062f\\u0627\\u0631\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(354, '*𝓣𝓾𝓻𝓴 𝓸𝓺𝓵𝓪𝓷*', NULL, 'Am13_sa', NULL, '6049170874', '{\"name\":\"\\u0627\\u0645\\u06cc\\u0631\\u062d\\u0633\\u06cc\\u0646\",\"lastname\":\"\\u0645\\u062d\\u0645\\u062f\\u06cc\\u0627\\u0646\",\"number\":\"989039637142\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(355, 'Mohammad', NULL, 'mmmdrrrrrrz', NULL, '510709504', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(356, 'Armia', 'A', 'armiaahh', 'verify info', '798947838', '{\"name\":\"\\u0622\\u0631\\u0645\\u06cc\\u0627\",\"lastname\":\"\\u0627\\u062d\\u0645\\u062f\\u06cc \\u062d\\u062f\\u0627\\u062f\",\"number\":\"393491654266\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(357, 'محمدرضا', 'نریمانی', 'Narimantaha', NULL, '6806988709', '{\"type\":\"\\u062e\\u0631\\u06cc\\u062f\\u0627\\u0631\",\"name\":\"\\u0645\\u062d\\u0645\\u062f\\u0631\\u0636\\u0627 \\u0646\\u0631\\u06cc\\u0645\\u0627\\u0646\\u06cc\",\"country\":\"\\ud83c\\uddee\\ud83c\\uddf7 \\u0627\\u06cc\\u0631\\u0627\\u0646\",\"arz\":\"\\u062a\\u062a\\u0631\",\"meghdar_arz\":\"1\",\"mablagh_pishnehad\":\"\",\"pay\":\"\",\"info\":\"\"}', 0, 0, 0, 0, NULL, 0, '1', '989218219896', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEBkG9oxs214RECQjkjg_ErMmotln6APwACwsoxG5kCMFKlP3Y8I3Hk0wEAAwIAA3kAAzYE', NULL, 0.00, NULL),
(358, 'حسین', 'نریمانی زمان آبادی', 'Elnzam800', NULL, '8274118230', '{\"name\":\"\\u062d\\u0633\\u06cc\\u0646\",\"lastname\":\"\\u0646\\u0631\\u06cc\\u0645\\u0627\\u0646\\u06cc \\u0632\\u0645\\u0627\\u0646 \\u0622\\u0628\\u0627\\u062f\\u06cc\",\"number\":\"989222770562\",\"inviter\":\"6806988709\",\"photo\":\"AgACAgQAAxkBAAEBkLFoxs_eBDH9u8iAre7r0-SBsg6QawACBs4xG8kzOFLcWa0Xr9gRiQEAAwIAA3gAAzYE\"}', 0, 0, 0, 0, NULL, 0, '1', '989222770562', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEBkLFoxs_eBDH9u8iAre7r0-SBsg6QawACBs4xG8kzOFLcWa0Xr9gRiQEAAwIAA3gAAzYE', NULL, 0.00, '6806988709'),
(359, 'Senior', NULL, 'senior_1990', NULL, '284607945', '{\"name\":\"\\u0633\\u0628\\u062d\\u0627\\u0646\",\"lastname\":\"\\u062f\\u0627\\u062f\\u0627\\u0644\\u0644\\u0647\\u06cc\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(360, 'مهدی', 'عارفی', 'MahdiArefi689', NULL, '2138749862', '{\"type\":\"\\u062e\\u0631\\u06cc\\u062f\\u0627\\u0631\",\"name\":\"\\u0645\\u0647\\u062f\\u06cc \\u0639\\u0627\\u0631\\u0641\\u06cc\",\"country\":\"\\u0627\\u0641\\u063a\\u0627\\u0646\\u0633\\u062a\\u0627\\u0646\",\"arz\":\"\\u062a\\u062a\\u0631\",\"meghdar_arz\":\"10\",\"mablagh_pishnehad\":\"9200000\",\"pay\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc | Bank Transfer\",\"info\":\"\\u0628\\u062f\\u0648\\u0646 \\u062a\\u0648\\u0636\\u06cc\\u062d\\u0627\\u062a | No notes\",\"3rdpartyname\":\"\\u2733\\ufe0f \\u062e\\u0631\\u06cc\\u062f\\u0627\\u0631\"}', 0, 0, 0, 0, NULL, 0, '1', '93772036238', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgUAAxkBAAEBkYBoyAP6gPYkwiimsWAUmSaJ_i27_QACjcIxGwJsuVdi3ReLIHiVHgEAAwIAA3kAAzYE', NULL, 0.00, NULL),
(361, 'ازکد', 'دزدوس', 'null', 'verify info', '7286010629', '{\"name\":\"\\u0639\\u0644\\u06cc\\u0631\\u0636\\u0627\",\"lastname\":\"\\u0642\\u0646\\u0628\\u0631\\u06cc\\u0627\\u0646\",\"number\":\"989932008074\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(362, 'اسماعیل', 'کریمی', 'Esmaeel78', NULL, '115763417', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"\\u0627\\u0633\\u0645\\u0627\\u0639\\u06cc\\u0644 \\u06a9\\u0631\\u06cc\\u0645\\u06cc\",\"country\":\"\\ud83c\\uddee\\ud83c\\uddf7 \\u0627\\u06cc\\u0631\\u0627\\u0646\",\"arz\":\"\\u062f\\u0644\\u0627\\u0631_\\u0622\\u0645\\u0631\\u06cc\\u06a9\\u0627\",\"meghdar_arz\":\"307\",\"mablagh_pishnehad\":\"172000\",\"pay\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc | Bank Transfer\",\"info\":\"\\u0648\\u0627\\u0631\\u06cc\\u0632 \\u062f\\u0644\\u0627\\u0631 \\\"\\u0641\\u0642\\u0637\\\" \\u0628\\u0647 \\u062d\\u0633\\u0627\\u0628 payoneer \\u0634\\u0645\\u0627\"}', 0, 0, 0, 0, NULL, 0, '1', '989307113479', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEBklBoybPA1rLjAAHN5ZHkeXATH8_oNwgAAs3IMRu181BSXFUZsq6KR0wBAAMCAAN5AAM2BA', NULL, 0.00, '54247368'),
(363, 'علی', 'چوپانی', 'SEObyACh', NULL, '1299387190', '{\"type\":\"\\u062e\\u0631\\u06cc\\u062f\\u0627\\u0631\",\"name\":\"\\u0639\\u0644\\u06cc \\u0686\\u0648\\u067e\\u0627\\u0646\\u06cc\",\"country\":\"\\ud83c\\uddee\\ud83c\\uddf7 \\u0627\\u06cc\\u0631\\u0627\\u0646\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"100\",\"mablagh_pishnehad\":\"127500\",\"pay\":\"Paypal | \\u067e\\u06cc\\u067e\\u0627\\u0644\",\"info\":\"\\u0646\\u06cc\\u0627\\u0632 \\u0647\\u0633\\u062a \\u062f\\u0631 \\u0633\\u0627\\u06cc\\u062a joker.com \\u0648\\u0627\\u0631\\u062f \\u0634\\u0648\\u06cc\\u062f \\u0648 \\u062d\\u0633\\u0627\\u0628 \\u0645\\u0646 \\u0631\\u0648 \\u0634\\u0627\\u0631\\u0698 \\u06a9\\u0646\\u06cc\\u062f.\"}', 0, 0, 0, 0, NULL, 0, '1', '+989350849485', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgEAAxkBAAEBkvtoy8p2l_cZ0qlXkyCi3UjbLwo0pwACAwtrGxf_WEZYiF_6mwyjhgEAAwIAA3kAAzYE', NULL, 0.00, NULL),
(364, 'نصرت', 'نریمانی زمان آبادی', 'null', NULL, '8435364995', '{\"name\":\"\\u0646\\u0635\\u0631\\u062a\",\"lastname\":\"\\u0646\\u0631\\u06cc\\u0645\\u0627\\u0646\\u06cc \\u0632\\u0645\\u0627\\u0646 \\u0622\\u0628\\u0627\\u062f\\u06cc\",\"number\":\"989233021045\",\"inviter\":\"6806988709\",\"photo\":\"AgACAgQAAxkBAAEBk3pozoz3Nu-PpNCdf2L23OS-8dmaSgACY8gxG05ieVLBz0KP547d_QEAAwIAA3kAAzYE\"}', 0, 0, 0, 0, NULL, 0, '1', '989233021045', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEBk3pozoz3Nu-PpNCdf2L23OS-8dmaSgACY8gxG05ieVLBz0KP547d_QEAAwIAA3kAAzYE', NULL, 0.00, '6806988709'),
(365, 'حسین ', 'حسینی', 'null', 'mozayede send 705', '505083262', '{\"mablagh_pishnehad\":\"\",\"meghdar\":\"\",\"vipuser\":\"442512457\",\"sms\":\"\"}', 0, 0, 0, 0, NULL, 0, '1', '46700368955', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEBlAho0D0qO54UvB5xH83CtnC_6dyevAACD8oxG_LbgFIWTQ8mDhFnxQEAAwIAA3kAAzYE', NULL, 0.00, '000'),
(366, 'Mr', 'Rock', 'null', 'verify info', '8277800318', '{\"name\":\"\\u0645\\u0639\\u06cc\\u0646\",\"lastname\":\"\\u0645\\u0639\\u06cc\\u0646\",\"number\":\"491601562519\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(367, '.', NULL, 'null', 'verify info', '296697757', '{\"3rdpartyname\":\"\\/start\",\"name\":\"\\u0641\\u0627\\u0637\\u0645\\u0647\",\"lastname\":\"\\u0631\\u062d\\u06cc\\u0645\\u06cc\",\"number\":\"989224636796\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(368, 'Alireza', 'Mehrdfar', 'AlirezaMehh', NULL, '41711522', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(369, 'ahmad', NULL, 'ahmad_Balocch', NULL, '6751027486', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(370, 'B', '.G', 'null', NULL, '5590787869', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(371, 'سما', 'وفائی', 'Sama7275', NULL, '234566142', '{\"mablagh_pishnehad\":\"158000\",\"meghdar\":\"35\",\"vipuser\":\"Neither user is VIP\",\"sms\":\"\"}', 0, 0, 0, 0, NULL, 0, '1', '989125119045', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEBn5Zo5CchqvURKKq7-0pcTksXplFF_QACJsoxG9pZIFMOsldpoKNUJgEAAwIAA3kAAzYE', NULL, 0.00, NULL),
(372, '.....', NULL, 'togadm', NULL, '8054166587', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(373, 'reza', 'Fathi', 'null', NULL, '6469659920', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(374, 'Reza', 'Zare Moghaddam', 'RezaZareMoghaddam', NULL, '39416975', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(375, 'M&$', NULL, 'null', NULL, '385914996', '{\"name\":\"\\u0645\\u062d\\u0645\\u062f\\u0631\\u0636\\u0627\",\"lastname\":\"\\u0634\\u0627\\u062f\\u0641\\u0631\",\"number\":\"989111383268\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(376, 'فرزاد', 'سگوند', 'null', NULL, '8457376979', '{\"name\":\"\\u0641\\u0631\\u0632\\u0627\\u062f\",\"lastname\":\"\\u0633\\u06af\\u0648\\u0646\\u062f\",\"number\":\"989120434829\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\",\"photo\":\"AgACAgQAAxkBAAEBqQ1o6ZCPZtcAAcPFJSZJA2Kfd7UHiG8AAk3cMRudSFBTAaTGxAsW4WoBAAMCAAN5AAM2BA\"}', 0, 0, 0, 0, NULL, 0, '1', '989120434829', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEBqQ1o6ZCPZtcAAcPFJSZJA2Kfd7UHiG8AAk3cMRudSFBTAaTGxAsW4WoBAAMCAAN5AAM2BA', NULL, 0.00, NULL),
(377, 'آنیل', 'کبل', 'null', NULL, '7293315778', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(378, 'امیر حسین', 'گراوندنیا', 'omidvanddd', NULL, '7635707915', '{\"name\":\"\\u0627\\u0645\\u06cc\\u0631\\u062d\\u0633\\u0646 \\u06af\\u0631\\u0627\\u0648\\u0646\\u062f\\u0646\\u06cc\\u0627\",\"lastname\":\"\\u06af\\u0631\\u0627\\u0648\\u0646\\u062f\\u0646\\u06cc\\u0627\",\"number\":\"989167085423\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\",\"photo\":\"AgACAgQAAxkBAAEBq8Fo7hpP8SlaTjTbRPtb7Mt5P8AUTQACdsgxGxyPeVPct-l44Md50wEAAwIAA3kAAzYE\"}', 0, 0, 0, 0, NULL, 0, '1', '989167085423', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEBq8Fo7hpP8SlaTjTbRPtb7Mt5P8AUTQACdsgxGxyPeVPct-l44Md50wEAAwIAA3kAAzYE', NULL, 0.00, NULL),
(379, 'S', 'Soltani', 'Rsoltani90', NULL, '8288060401', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(380, '🔱reza🔱', NULL, 'reza_khazaei1300', NULL, '6862799474', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(381, 'MOHAMAD', 'Aref', 'null', NULL, '7887507025', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(382, '𝗠𝗠𝗗', NULL, 'MMD_SRZ_4', NULL, '1066408607', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(383, 'a', NULL, 'null', NULL, '132957198', '{\"name\":\"\\u0639\",\"lastname\":\"\\u0647\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(384, 'محمدصالح دهقانپور', 'دهقانپور', 'MSDqp', NULL, '109733885', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"\\u0645\\u062d\\u0645\\u062f\\u0635\\u0627\\u0644\\u062d \\u062f\\u0647\\u0642\\u0627\\u0646\\u067e\\u0648\\u0631\",\"country\":\"\\ud83c\\uddee\\ud83c\\uddf7 \\u0627\\u06cc\\u0631\\u0627\\u0646\",\"arz\":\"\\u0644\\u06cc\\u0631 \\u062a\\u0631\\u06a9\\u06cc\\u0647\",\"meghdar_arz\":\"1000\",\"mablagh_pishnehad\":\"2540000\",\"pay\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc | Bank Transfer\",\"info\":\"2540 \\u0646\\u0631\\u062e \\u0641\\u0631\\u0648\\u0634 \\u0644\\u06cc\\u0631. \\u0645\\u0642\\u0627\\u062f\\u06cc\\u0631 \\u0628\\u0627\\u0644\\u0627\\u062a\\u0631 \\u0647\\u0645 \\u0645\\u0645\\u06a9\\u0646 \\u0627\\u0633\\u062a\"}', 0, 0, 0, 0, NULL, 0, '1', '989129381420', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEBsk5o_kypKo-Lc2sCPdfwXb6wEaqm9AACiQtrGymt8VPamGCpTlyP4gEAAwIAA3kAAzYE', NULL, 0.00, '357'),
(385, 'آیدا', 'خوشرفتار', 'null', NULL, '526948317', '{\"mablagh_pishnehad\":\"190000\",\"meghdar\":\"203\",\"vipuser\":\"Neither user is VIP\",\"sms\":\"\"}', 0, 0, 0, 1, NULL, 0, '1', '989116075646', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEBsvZpAgcqayQOGAsUD04PxaiZMm4eaAACYAtrGy8bEVCxb0SbaCBFwwEAAwIAA3kAAzYE', NULL, 0.00, NULL),
(386, 'Dr.Shiva♥️', NULL, 'null', NULL, '5994220772', NULL, 0, 10000, 0, 0, NULL, 0, '1', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(387, 'Reza', 'Hosseini', 'null', 'verify 3rdamount', '94385158', '{\"3rdpartyname\":\"Open\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(388, 'نوید', 'نادری', 'null', NULL, '106095484', '{\"mablagh_pishnehad\":\"135000\",\"meghdar\":\"1000\",\"vipuser\":\"7506201452\",\"sms\":\"\"}', 0, 0, 0, 0, NULL, 0, '1', '989155181186', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEBu-RpHwcP9c9b8CIv0CIICfAWJiO8bgACcwtrG3M0-VACGaNR7F7CQgEAAwIAA3kAAzYE', NULL, 0.00, NULL),
(389, 'amirhossein', NULL, 'amirhossein_unlimit', NULL, '1073688662', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(390, 'D.', 'T', 'Arazyoul', NULL, '7314626466', '{\"name\":\"\\u062f\\u0627\\u0631\\u06cc\\u0648\\u0634 \\u0637\\u0627\\u0647\\u0631\\u0639\\u0632\\u06cc\\u0632\",\"lastname\":\"\\u0637\\u0627\\u0647\\u0631\\u0639\\u0632\\u06cc\\u0632\",\"number\":\"989982559685\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(391, 'هاشم', 'مولوی', 'Hm_Mlvv', NULL, '48843500', '{\"mablagh_pishnehad\":\"154000\",\"meghdar\":\"1000\",\"vipuser\":\"Neither user is VIP\",\"sms\":\"\"}', 0, 0, 0, 0, NULL, 0, '1', '989132835363', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEBzLJpVgVsRoQpwhm1FXBNp9i6FNHZ0gACBAxrGxgbsVITmKlJoR06gAEAAwIAA3kAAzgE', NULL, 0.00, NULL),
(392, 'یدالله حسارعد', 'حسارعد', 'null', 'mozayede sms 749', '1810408708', '{\"mablagh_pishnehad\":\"129000\",\"meghdar\":\"100\",\"vipuser\":\"Neither user is VIP\",\"sms\":\"\"}', 0, 0, 1, 0, '2025-11-22 19:11:17', 0, '1', '989358345495', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEBuGJpG6FhAzKxLuVdGv-pVJ-UTMQYsAACCAxrG3FN4VDt5eHis1biJAEAAwIAA3kAAzYE', NULL, 0.00, NULL),
(393, 'غلامرضا', 'رستگار', 'Mahdi762778', 'verify 3rdipayconfirm', '8233624611', '{\"type\":\"\\u062e\\u0631\\u06cc\\u062f\\u0627\\u0631\",\"name\":\"\\u063a\\u0644\\u0627\\u0645\\u0631\\u0636\\u0627 \\u0631\\u0633\\u062a\\u06af\\u0627\\u0631\",\"country\":\"\\ud83c\\uddf9\\ud83c\\uddf7 \\u062a\\u0631\\u06a9\\u06cc\\u0647\",\"arz\":\"\\u062a\\u062a\\u0631\",\"meghdar_arz\":\"600\",\"mablagh_pishnehad\":\"149000\",\"pay\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc | Bank Transfer\",\"info\":\"\\u0628\\u062f\\u0648\\u0646 \\u062a\\u0648\\u0636\\u06cc\\u062d\\u0627\\u062a | No notes\",\"experience\":\"\\u062f\\u0631\\u0648\\u063a \\u0686\\u0631\\u0627 \\u0645\\u06cc\\u06af\\u06cc\\u062f\",\"3rdpartyname\":\"\\u2733\\ufe0f \\u062e\\u0631\\u06cc\\u062f\\u0627\\u0631\",\"3rdipayiban\":\"\\u2705 \\u0628\\u0644\\u0647 , \\u0627\\u062f\\u0627\\u0645\\u0647\\u00bb\"}', 0, 0, 0, 1, NULL, 0, '1', '905074551236', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEB0yNpZghAiJsjBmmXleRgP_JWTwm_cgACtgtrG-nOMVOcCQM2HqNb4AEAAwIAA3kAAzgE', NULL, 0.00, NULL),
(394, 'zxcv', NULL, 'kaakzz', NULL, '8493768007', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(395, 'Fereshte13 ', NULL, 'Fereshtes13', NULL, '6841909158', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(396, 'Roya', 'Qavi', 'Royaqv', NULL, '102708234', '{\"name\":\"\\u0631\\u0648\\u06cc\\u0627\",\"lastname\":\"\\u0642\\u0648\\u06cc\",\"number\":\"989155160459\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(397, 'کاوه ', 'علیمردانی تنها', 'digitalva', NULL, '5417484664', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"\\u06a9\\u0627\\u0648\\u0647  \\u0639\\u0644\\u06cc\\u0645\\u0631\\u062f\\u0627\\u0646\\u06cc \\u062a\\u0646\\u0647\\u0627\",\"country\":\"\\ud83c\\udde9\\ud83c\\uddea \\u0622\\u0644\\u0645\\u0627\\u0646\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"100\",\"mablagh_pishnehad\":\"207000\",\"pay\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc | Bank Transfer\",\"info\":\"\\u0648\\u0627\\u0631\\u06cc\\u0632 \\u0633\\u0631\\u06cc\\u0639\"}', 0, 0, 0, 2, NULL, 0, '1', '4571511760', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEBv2ppIfolGSm2Cp4HQN9iqV4Kgpsi7QAC1AtrG62r-FCpOgV8kLsBCgEAAwIAA3kAAzYE', NULL, 0.00, NULL),
(398, 'آتنا ', 'مومنی', 'Atenamomeni', NULL, '144214440', NULL, 0, 0, 0, 0, NULL, 0, '1', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(399, 'ᗩᙢᓮᖇ', 'ᗷᗩᕼᗩᖺ', 'Badzaban', NULL, '35629148', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(400, '💙💙یوسف تاجی💙💙', NULL, 'yoesf_risi', 'verify 3rdipayconfirm', '289270653', '{\"3rdpartyname\":\"\\u2733\\ufe0f \\u062e\\u0631\\u06cc\\u062f\\u0627\\u0631\",\"3rdipayiban\":\"\\/start\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(401, 'Maryam', 'Rad', 'null', NULL, '410723581', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(402, 'Mohammad', NULL, 'mohammedMo_user', NULL, '1307899888', '{\"name\":\"\\u0645\\u062d\\u0645\\u062f \\u0645\\u062d\\u0645\\u062f\\u06cc\",\"lastname\":\"\\u0645\\u062d\\u0645\\u062f\\u06cc\",\"number\":\"46708101454\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(403, 'Fereshteh', 'He', 'feresht_he_architect', 'verify info', '196788257', '{\"name\":\"\\u0641\\u0631\\u0634\\u062a\\u0647\",\"lastname\":\"\\u0647\\u0646\\u062f\\u06cc\",\"number\":\"989901181017\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(404, 'Dezmond', NULL, 'Dezmod94', 'verify info', '8222449063', '{\"name\":\"\\u0633\\u0631\\u0648\\u0634\",\"lastname\":\"\\u0645\\u062d\\u0645\\u062f\\u06cc\",\"number\":\"447404361064\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(405, 'Dr.shadi.F', '♥️', 'shadifallahzadeh', NULL, '1341129467', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 3120000, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(406, 'Pury', NULL, 'Pury800', 'verify number', '91912937', '{\"name\":\"\\u067e\\u0648\\u0631\\u06cc\\u0627 \\u0631\\u06cc\\u0627\\u062d\\u06cc\",\"lastname\":\"\\u0631\\u06cc\\u0627\\u062d\\u06cc\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(407, 'Hyperika', NULL, 'Hyperika', NULL, '77867201', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(408, 'Mozhgan', NULL, 'null', 'verify 3rdipayconfirm', '6132560609', '{\"3rdpartyname\":\"\\u2733\\ufe0f \\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"3rdipayiban\":\"\\u062e\\u0648\\u062f\\u0645\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(409, 'M', 'N', 'Bjjhhhgggggfffef', NULL, '7371812352', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(410, 'مهسا', 'رسولی', 'Mhsw_rs', NULL, '5546474452', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"\\u0645\\u0647\\u0633\\u0627 \\u0631\\u0633\\u0648\\u0644\\u06cc \\u0645\\u0647\\u0633\\u0627 \\u0631\\u0633\\u0648\\u0644\\u06cc\",\"country\":\"\\ud83c\\uddeb\\ud83c\\uddf7 \\u0641\\u0631\\u0627\\u0646\\u0633\\u0647\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"100\",\"mablagh_pishnehad\":\"168000\",\"pay\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc | Bank Transfer\",\"info\":\"ok\",\"card\":\"6219861964802663\",\"iban\":\"IR030560611828005649352601\",\"bankname\":\"\\u0628\\u0644\\u0648 \\u0628\\u0627\\u0646\\u06a9 \\u0633\\u0627\\u0645\\u0627\\u0646\",\"empfanger\":\"\\u0645\\u0647\\u0633\\u0627 \\u0631\\u0633\\u0648\\u0644\\u06cc\",\"3rdpartyname\":\"\\u2733\\ufe0f \\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"3rdipayiban\":\"\\u2705 \\u0628\\u0644\\u0647 , \\u0627\\u062f\\u0627\\u0645\\u0647\\u00bb\"}', 0, 0, 0, 1, NULL, 0, '1', '989333683624', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEBywdpUU1cEFzgl5kq6zFVlMbVpltDeQACfgtrG63qiFJOa39b4dYmUwEAAwIAA3kAAzYE', NULL, 0.00, NULL),
(411, 'Admin گروه دلار استرالیا', NULL, 'AUDIRT_Admin', 'verify name', '416395537', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(412, 'Amir', 'Afshar', 'AmirIRNLA', NULL, '7324542168', '{\"name\":\"\\u0622\\u0631\\u06cc\\u064e\\u0646\",\"lastname\":\"\\u0622\\u0631\\u06cc\\u0627\\u06cc\\u06cc\",\"number\":\"31641233425\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(413, '˙·٠•●♥️ ƸӜ̵̨ƷMahidaƸ̵̡Ӝ̵̨Ʒ ♥️●•٠·˙', NULL, 'Shahlazo', NULL, '457916724', '{\"3rdpartyname\":\"\\u2733\\ufe0f \\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"3rdipayiban\":\"\\u2705 \\u0628\\u0644\\u0647 , \\u0627\\u062f\\u0627\\u0645\\u0647\\u00bb\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(414, 'erfan', NULL, 'null', NULL, '8295821690', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(415, 'M', 'R', 'mohsenrab55', NULL, '86288595', '{\"name\":\"\\u0645\\u062d\\u0633\\u0646\",\"lastname\":\"\\u0631\\u0628\\u06cc\\u0639\\u06cc\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(416, 'Hamid', 'Hassanzadeh', 'Hamid_bln', NULL, '497920367', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(417, 'Fahime', NULL, 'Fahimes844', NULL, '7991229687', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(418, '𝐍𝐢𝐤𝐨𝐥𝐚𝐢 🇷🇺', '09:17', 'Auligh', NULL, '8588684180', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(419, 'Ehsan', NULL, 'null', NULL, '102723731', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(420, 'AliReza', NULL, 'alireza_3292', NULL, '258529332', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(421, 'aref', NULL, 'Arefjj77', 'verify info', '107639635', '{\"name\":\"\\u0645\\u0647\\u062f\\u06cc\",\"lastname\":\"\\u0645\\u0647\\u062f\\u06cc \\u062c\\u0639\\u0641\\u0631\\u06cc\",\"number\":\"31643809807\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(422, 'Persian post express', NULL, 'pakatusaeuropedubai', NULL, '6884491184', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(423, 'سبحان', 'مرادی', 'Sbmrd98', NULL, '1997499792', '{\"name\":\"\\u0633\\u0628\\u062d\\u0627\\u0646\",\"lastname\":\"\\u0645\\u0631\\u0627\\u062f\\u06cc\",\"number\":\"393516385442\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\",\"photo\":\"AgACAgQAAxkBAAEB2xFpkM-MMhVXC7ILllTlslQRyeWdjwACgg9rG7KJiVAnckYcEJ83RQEAAwIAA3kAAzoE\"}', 0, 0, 0, 0, NULL, 0, '1', '393516385442', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEB2xFpkM-MMhVXC7ILllTlslQRyeWdjwACgg9rG7KJiVAnckYcEJ83RQEAAwIAA3kAAzoE', NULL, 0.00, NULL),
(424, 'محمد', 'امیرفتاحی', 'FREE_venus90180', NULL, '37319031', '{\"name\":\"\\u0645\\u062d\\u0645\\u062f\",\"lastname\":\"\\u0627\\u0645\\u06cc\\u0631\\u0641\\u062a\\u0627\\u062d\\u06cc\",\"number\":\"989360558719\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\",\"photo\":\"AgACAgQAAxkBAAEB21tpkxjTvp-ztPexeKWT4wr5fBZ0PAACdg1rG156mVBHalrgwuqQcwEAAwIAA3kAAzoE\"}', 0, 0, 0, 0, NULL, 0, '1', '989360558719', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEB21tpkxjTvp-ztPexeKWT4wr5fBZ0PAACdg1rG156mVBHalrgwuqQcwEAAwIAA3kAAzoE', NULL, 0.00, NULL),
(425, 'مریم', 'جبرئیلیان', 'maryamjebbreilian', NULL, '897418150', '{\"mablagh_pishnehad\":\"185000\",\"meghdar\":\"100\",\"vipuser\":\"Neither user is VIP\",\"sms\":\"\"}', 0, 0, 0, 0, NULL, 0, '1', '989301229628', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEB3VZpmFbimjQpW_9aHp71orSH_R9GoAAC1wxrG8IjyFALZC4SEeM3CgEAAwIAA3kAAzoE', NULL, 0.00, NULL),
(426, '🧿📿حسبی الله🧿📿', NULL, 'my_telegram04', 'verify 3rdipayconfirm', '5492351038', '{\"3rdpartyname\":\"\\u2733\\ufe0f \\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"3rdipayiban\":\"\\u0628\\u0644\\u0647\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(427, 'Bitcoin', NULL, 'Bit_coin_for_iran', 'verify name', '966996799', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(428, 'Hack sup', NULL, 'HACKERSYBRE', NULL, '8384860706', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(429, 'Reza', 'Mohammadi', 'Reza_mohammadi125', NULL, '109623227', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(430, 'Mehraban', 'Rahimi', 'null', NULL, '5669229198', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(431, 'Aria', NULL, 'AriaRaessi', 'verify number', '234424672', '{\"name\":\"\\u0627\\u0631\\u06cc\\u0627\",\"lastname\":\"\\u0631\\u06cc\\u06cc\\u0633\\u06cc\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(432, 'مجتبی', 'عباسی', 'Mr_abbassi', 'havale ok', '6981659586', '{\"type\":\"\\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"name\":\"Mojtaba\",\"country\":\"\\ud83c\\uddee\\ud83c\\uddf7 \\u0627\\u06cc\\u0631\\u0627\\u0646\",\"arz\":\"\\u06cc\\u0648\\u0631\\u0648\",\"meghdar_arz\":\"500\",\"mablagh_pishnehad\":\"185000\",\"pay\":\"\\u062d\\u0648\\u0627\\u0644\\u0647 \\u0628\\u0627\\u0646\\u06a9\\u06cc | Bank Transfer\",\"info\":\"\\u0648\\u0627\\u0631\\u06cc\\u0632 \\u0627\\u0632 \\u0648\\u0627\\u06cc\\u0632 \\u06cc\\u0627 \\u0631\\u0648\\u0644\\u0648\\u062a\"}', 0, 0, 0, 0, NULL, 0, '1', '989211231896', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEB4e1pwpIp_JWfVwZ_4T8CEShySu-DrAACHg5rG4-jGVIkcilRPF5Z8AEAAwIAA3kAAzoE', NULL, 0.00, NULL),
(433, 'ـ', NULL, 'eliabdol', NULL, '1785794782', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(434, 'Ahmdoo🐺', NULL, 'null', NULL, '6350324298', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(435, 'Raf', NULL, 'RAF723', NULL, '59122208', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(436, 'AliReza', NULL, 'alir32aa', 'verify name', '103274402', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(437, 'شهام', 'شاکرین', 'Jorg_RRmartin', NULL, '951959437', '{\"name\":\"\\u0634\\u0647\\u0627\\u0645\",\"lastname\":\"\\u0634\\u0627\\u06a9\\u0631\\u06cc\\u0646\",\"number\":\"989135968859\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\",\"photo\":\"AgACAgQAAxkBAAEB5SRp3NhmefJovvEe4lNmAQHQl1lffwACvgxrG9M06FJ1qlAxHd9XDAEAAwIAA3kAAzsE\"}', 0, 0, 0, 0, NULL, 0, '1', '989135968859', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgQAAxkBAAEB5SRp3NhmefJovvEe4lNmAQHQl1lffwACvgxrG9M06FJ1qlAxHd9XDAEAAwIAA3kAAzsE', NULL, 0.00, NULL),
(438, 'MEYSAM', '🤵', 'mo12770', NULL, '6439417555', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(439, 'Saeid', NULL, 'Saeid365', 'verify name', '120427918', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(440, 'Mohammad', NULL, 'RIVANOXDEV', NULL, '7358000369', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(441, 'M', 'Kh', 'null', NULL, '8262050150', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(442, 'Amir', 'K', 'null', NULL, '139283734', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(443, 'Zahra', NULL, 'Zahra68401', 'verify info', '109598353', '{\"name\":\"\\u0632\\u0647\\u0631\\u0627 \\u0639\\u0628\\u0627\\u062f\\u06cc \\u0639\\u0646\\u0635\\u0631\\u0648\\u062f\\u06cc\",\"lastname\":\"\\u0639\\u0628\\u0627\\u062f\\u06cc \\u0639\\u0646\\u0635\\u0631\\u0648\\u062f\\u06cc\",\"number\":\"393338299115\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(444, 'Alireza', 'Moradian', 'AstroMN12', NULL, '6127401324', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(445, 'علیرضا', 'هاشمی', 'merealme1010', NULL, '5766757739', '{\"name\":\"\\u0639\\u0644\\u06cc\\u0631\\u0636\\u0627\",\"lastname\":\"\\u0647\\u0627\\u0634\\u0645\\u06cc\",\"number\":\"491606016956\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\",\"photo\":\"AgACAgIAAxkBAAEB78hqCBkI95aUFJRNDGq3pnpi70DNyAAC_BtrG8dMSUipbq3T3qGggQEAAwIAA3kAAzsE\"}', 0, 0, 0, 0, NULL, 0, '1', '491606016956', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgIAAxkBAAEB78hqCBkI95aUFJRNDGq3pnpi70DNyAAC_BtrG8dMSUipbq3T3qGggQEAAwIAA3kAAzsE', NULL, 0.00, NULL),
(446, 'Abolfazl', 'Okhravi', 'AbolfazlOkhravi', NULL, '5376412037', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(447, 'Poor VPN', NULL, 'poorVPN2', NULL, '7835658344', '{\"name\":\"\\u067e\\u0648\\u0631\",\"lastname\":\"\\u067e\\u0648\\u0631\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(448, 'AFSON📸', NULL, 'null', 'verify info', '6726786729', '{\"name\":\"\\u0641\\u0627\\u0637\\u0645\\u0647\",\"lastname\":\"\\u062f\\u0646\\u06cc\\u0627\\u06cc\\u06cc\",\"number\":\"989164002091\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(449, 'Pouriya', NULL, 'Pouryatadayoni', NULL, '8320532461', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(450, 'Zeinab', NULL, 'null', NULL, '7868357050', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(451, 'mohammad', 'izadi', 'null', NULL, '7655518949', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(452, 'Vpn_Support', NULL, 'abarlink_ads', NULL, '5237121916', '{\"name\":\"\\u067e\\u06cc\\u0645\\u0627\\u0646\",\"lastname\":\"\\u0633\\u06cc\\u0641\\u06cc\",\"number\":\"989167052017\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(453, 'M', NULL, 'Alex50997', NULL, '6366682051', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(454, 'Fatemeh', 'Jafari', 'Photovoltaic2025', 'verify info', '6787093070', '{\"name\":\"\\u0641\\u0627\\u0637\\u0645\\u0647 \\u062c\\u0639\\u0641\\u0631\\u06cc\",\"lastname\":\"\\u062c\\u0639\\u0641\\u0631\\u06cc\",\"number\":\"4915565044709\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\"}', 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(455, 'M', 'Gh', 'Mohammad_gh71', NULL, '220779701', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL),
(456, 'جمال', 'حیدری', 'Jhaidary', 'verify 3rdipayconfirm', '167423475', '{\"name\":\"\\u062c\\u0645\\u0627\\u0644\",\"lastname\":\"\\u062d\\u06cc\\u062f\\u0631\\u06cc\",\"number\":\"491792231327\",\"inviter\":\"\\u0646\\u062f\\u0627\\u0631\\u0645\",\"photo\":\"AgACAgIAAxkBAAEB-RxqKryEuxrO-6IMnJSKVEYgphYt2AACJh1rG2exUEmLwkEvRtrrXgEAAwIAA3kAAzsE\",\"3rdpartyname\":\"\\u2733\\ufe0f \\u0641\\u0631\\u0648\\u0634\\u0646\\u062f\\u0647\",\"3rdipayiban\":\"\\u2705 \\u0628\\u0644\\u0647 , \\u0627\\u062f\\u0627\\u0645\\u0647\\u00bb\"}', 0, 0, 0, 0, NULL, 0, '1', '491792231327', 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'AgACAgIAAxkBAAEB-RxqKryEuxrO-6IMnJSKVEYgphYt2AACJh1rG2exUEmLwkEvRtrrXgEAAwIAA3kAAzsE', NULL, 0.00, NULL),
(457, 'A', 'Es', 'null', NULL, '5008177512', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL);
INSERT INTO `account` (`id`, `name`, `lastname`, `username`, `step`, `chat_id`, `data`, `doller`, `euro`, `mozayede_Cancell`, `mozayede_ok`, `date_ban`, `ban`, `verified`, `phone`, `rial`, `Vip`, `empfanger`, `bankname`, `iban`, `card`, `expire_vip`, `network`, `wallet_adress`, `payint`, `documents`, `datapay`, `euroinviter`, `inviter`) VALUES
(458, 'Sh🤍', NULL, 'Shirinpob', NULL, '6983457380', NULL, 0, 0, 0, 0, NULL, 0, '0', NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `admin_documents`
--

CREATE TABLE `admin_documents` (
  `id` int(11) NOT NULL,
  `sender_admin_id` bigint(20) NOT NULL,
  `chat_id` bigint(20) NOT NULL,
  `message_type` varchar(20) NOT NULL,
  `file_id` varchar(255) NOT NULL,
  `caption` text DEFAULT NULL,
  `status` enum('pending','sent','failed') DEFAULT 'pending',
  `sent_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ads`
--

CREATE TABLE `ads` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `link` varchar(255) DEFAULT NULL,
  `position` varchar(50) DEFAULT NULL,
  `status` tinyint(4) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ad_deals`
--

CREATE TABLE `ad_deals` (
  `id` int(11) NOT NULL,
  `offer_id` int(11) NOT NULL,
  `ad_id` int(11) NOT NULL,
  `buyer_id` int(11) NOT NULL,
  `seller_id` int(11) NOT NULL,
  `currency` varchar(10) NOT NULL,
  `amount` decimal(20,6) NOT NULL,
  `price_per_unit` decimal(20,2) NOT NULL,
  `total_price` decimal(20,2) NOT NULL,
  `deal_code` varchar(50) NOT NULL,
  `status` enum('pending','completed') DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `admin_notified` tinyint(4) DEFAULT 0,
  `buyer_confirmed` tinyint(4) DEFAULT 0,
  `seller_confirmed` tinyint(4) DEFAULT 0,
  `offer_message` text DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `buyer_completed` tinyint(1) DEFAULT 0,
  `seller_completed` tinyint(1) DEFAULT 0,
  `admin_completed` tinyint(1) DEFAULT 0,
  `completed_at` datetime DEFAULT NULL,
  `completed_by` int(11) DEFAULT NULL,
  `buyer_admin_accounts` text DEFAULT NULL,
  `seller_admin_accounts` text DEFAULT NULL,
  `buyer_receipts` text DEFAULT NULL,
  `seller_receipts` text DEFAULT NULL,
  `buyer_settlement_receipts` text DEFAULT NULL,
  `seller_settlement_receipts` text DEFAULT NULL,
  `buyer_admin_note` text DEFAULT NULL,
  `seller_admin_note` text DEFAULT NULL,
  `buyer_side_status` varchar(30) DEFAULT 'new',
  `seller_side_status` varchar(30) DEFAULT 'new'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ad_offers`
--

CREATE TABLE `ad_offers` (
  `id` int(11) NOT NULL,
  `ad_id` int(11) NOT NULL,
  `buyer_id` int(11) NOT NULL,
  `seller_id` int(11) NOT NULL,
  `requested_amount` decimal(20,6) NOT NULL,
  `offered_price` decimal(20,2) NOT NULL,
  `total_amount` decimal(20,2) GENERATED ALWAYS AS (`requested_amount` * `offered_price`) STORED,
  `message` text DEFAULT NULL,
  `status` enum('pending','accepted','rejected') DEFAULT 'pending',
  `reject_reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `responded_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_messages`
--

CREATE TABLE `chat_messages` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `sender` enum('user','admin') NOT NULL,
  `message` text DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `telegram_message_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `is_read` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `chat_messages`
--

INSERT INTO `chat_messages` (`id`, `user_id`, `sender`, `message`, `file_path`, `file_name`, `file_size`, `telegram_message_id`, `created_at`, `is_read`) VALUES
(48, 1, 'admin', 'slm', NULL, NULL, NULL, NULL, '2026-02-21 09:25:13', 1),
(49, 35, 'user', 'Hi', NULL, NULL, NULL, 122571, '2026-02-21 13:00:22', 0),
(51, 18, 'admin', 'Hi', NULL, NULL, NULL, NULL, '2026-02-21 13:00:53', 0),
(52, 35, 'admin', 'Hi', NULL, NULL, NULL, NULL, '2026-02-21 13:01:29', 1),
(53, 1, 'user', 'سلام', NULL, NULL, NULL, 122643, '2026-02-26 14:02:36', 0),
(54, 1, 'admin', 'Hi', NULL, NULL, NULL, NULL, '2026-02-26 14:03:02', 1),
(55, 1, 'admin', 'چخبر', NULL, NULL, NULL, NULL, '2026-02-26 14:03:12', 1),
(56, 1, 'user', 'سلام', NULL, NULL, NULL, 123131, '2026-03-13 22:49:06', 0),
(57, 1, 'admin', 'Hi', NULL, NULL, NULL, NULL, '2026-03-13 22:49:42', 1),
(58, 17, 'admin', 'Hi', NULL, NULL, NULL, NULL, '2026-03-13 22:50:10', 1),
(59, 14, 'admin', 'خدمت شما', 'uploads/chat/admin_1774532784_69c538b052648.jpeg', 'IMG_1140.jpeg', 34768, NULL, '2026-03-26 13:46:24', 0),
(60, 14, 'admin', 'خدمت شما هفتاد ملیون', 'uploads/chat/admin_1774902841_69cade39d4914.jpeg', 'IMG_1170.jpeg', 74975, NULL, '2026-03-30 20:34:01', 0),
(61, 14, 'admin', 'تسویه شد', 'uploads/chat/admin_1775490189_69d3d48d1572e.jpeg', 'IMG_1224.jpeg', 100473, NULL, '2026-04-06 15:43:09', 0),
(62, 14, 'admin', 'تسویه ١۴١', 'uploads/chat/admin_1775815304_69d8ca88a751f.jpeg', 'IMG_1238.jpeg', 82539, NULL, '2026-04-10 10:01:44', 0),
(63, 37, 'admin', 'رسید مشتری', 'uploads/chat/admin_1776166197_69de2535e993e.png', 'IMG_1278.png', 655113, NULL, '2026-04-14 11:29:57', 1),
(64, 30, 'admin', 'تسویه ۴۰۰', 'uploads/chat/admin_1776333900_69e0b44c76e6f.jpeg', 'IMG_1287.jpeg', 95539, NULL, '2026-04-16 10:05:00', 0),
(65, 37, 'admin', 'زری رامشی - رسید مشتری ١١۴ ملیون و چهارصد', 'uploads/chat/admin_1777103843_69ec73e32d517.png', 'IMG_1342.png', 661548, NULL, '2026-04-25 07:57:23', 1),
(66, 37, 'admin', 'محمد نقیب ناصری -رسید مشتری ٩۶ ملیون', 'uploads/chat/admin_1777116883_69eca6d32ac3b.png', 'IMG_1354.png', 654251, NULL, '2026-04-25 11:34:43', 1),
(67, 14, 'admin', 'ابراهیم خلیل پور ٣٠ تومن رسیدد مشتری', 'uploads/chat/admin_1777321921_69efc7c1a4620.jpeg', 'IMG_1369.jpeg', 39268, NULL, '2026-04-27 20:32:01', 0),
(68, 14, 'admin', 'رضا خلیل پور ٢٨ تومان رسید مشتری', 'uploads/chat/admin_1777321946_69efc7da3583f.jpeg', 'IMG_1370.jpeg', 26782, NULL, '2026-04-27 20:32:26', 0),
(69, 1, 'admin', 'رضا خلیل پور ٢٨ تومان رسید مشتری', 'uploads/chat/admin_1777321980_69efc7fcb3ba1.jpeg', 'IMG_1370.jpeg', 26782, NULL, '2026-04-27 20:33:00', 1),
(70, 1, 'admin', 'Hi', NULL, NULL, NULL, NULL, '2026-05-09 07:04:23', 1),
(71, 1, 'user', 'سلام', NULL, NULL, NULL, 125981, '2026-05-09 09:06:45', 0),
(72, 1, 'user', 'ا', 'uploads/chat/user_69fef9411fad0.png', 'IMG_1477.png', 1448020, 125982, '2026-05-09 09:07:13', 0),
(73, 1, 'admin', 'hi', NULL, NULL, NULL, NULL, '2026-05-09 09:07:38', 1),
(74, 1, 'user', 'Hi', NULL, NULL, NULL, 125984, '2026-05-09 09:23:08', 0),
(75, 1, 'admin', 'ا', NULL, NULL, NULL, NULL, '2026-05-09 09:24:21', 1),
(76, 1, 'admin', 'سیس', NULL, NULL, NULL, NULL, '2026-05-09 09:24:41', 1),
(77, 1, 'user', 'سلام', 'uploads/chat/user_6a0387ce95dc5.jpeg', 'IMG_1515.jpeg', 34825, 0, '2026-05-12 20:04:30', 0),
(78, 1, 'user', 'های', NULL, NULL, NULL, NULL, '2026-05-12 20:11:31', 0),
(79, 1, 'user', 'چطوری', NULL, NULL, NULL, NULL, '2026-05-12 20:11:40', 0),
(80, 1, 'user', 'سلام', NULL, NULL, NULL, 0, '2026-05-12 20:22:53', 0),
(81, 1, 'user', 'تست', 'uploads/chat/user_6a038c4f845ab.jpeg', 'IMG_1515.jpeg', 34825, 0, '2026-05-12 20:23:43', 0),
(82, 1, 'admin', 'تست', 'uploads/chat/admin_1778617639_6a038d27b27b7.png', 'AVAPAY.PNG.PNG', 24819, NULL, '2026-05-12 20:27:19', 1),
(83, 12, 'admin', 'تسویه 300 یورو -طلب کار ٢.۵', 'uploads/chat/admin_1778617874_6a038e12af8c5.png', 'IMG_1517.png', 1305922, NULL, '2026-05-12 20:31:14', 0),
(84, 14, 'admin', 'تسویه ۵٠٠ دلار', 'uploads/chat/admin_1778708706_6a04f0e2debac.jpeg', 'IMG_1526.jpeg', 297038, NULL, '2026-05-13 21:45:06', 0),
(85, 17, 'user', 'سلام', NULL, NULL, NULL, 0, '2026-05-14 00:11:49', 0),
(86, 17, 'admin', 'سلام', NULL, NULL, NULL, NULL, '2026-05-15 07:33:35', 1),
(87, 17, 'admin', 'شس', NULL, NULL, NULL, NULL, '2026-05-15 21:52:25', 1),
(88, 17, 'admin', '', 'uploads/chat/admin_1778882155_6a07966b580e7.png', 'IMG_1540.PNG', 1360535, NULL, '2026-05-15 21:55:55', 1),
(89, 17, 'admin', 'سی', 'uploads/chat/admin_1778883175_6a079a6794413.png', 'IMG_1540.PNG', 1360535, NULL, '2026-05-15 22:12:55', 1),
(90, 17, 'admin', 'سی', 'uploads/chat/admin_1778884919_6a07a1375709d.png', 'IMG_1540.PNG', 1360535, NULL, '2026-05-15 22:41:59', 0),
(91, 37, 'admin', 'فاطمه زیدی ١٧۵ ملیون', 'uploads/chat/admin_1779390264_6a0f5738bbfed.png', 'IMG_1641.png', 645766, NULL, '2026-05-21 19:04:24', 1),
(92, 37, 'admin', 'پریسا نوروزیان ١۵٠ تومان', 'uploads/chat/admin_1779479450_6a10b39aae340.jpeg', '7d31faaf-e07c-4630-9bf5-45f4446f59d8.jpeg', 64747, NULL, '2026-05-22 19:50:50', 1),
(93, 37, 'admin', 'محمد اسمعیلی ۵٠ ملیون تومان', 'uploads/chat/admin_1779479580_6a10b41caed10.jpeg', 'c3a13585-79c1-40ba-a2fb-32ca56de58fc.jpeg', 42297, NULL, '2026-05-22 19:53:00', 1),
(94, 37, 'admin', 'محمد اسمعیلی ٢۵ ملیون', 'uploads/chat/admin_1779482422_6a10bf3613e81.jpeg', '55b56c66-364c-461b-adac-8e51eb9ef6c4.jpeg', 63812, NULL, '2026-05-22 20:40:22', 1),
(95, 41, 'user', 'سلام', NULL, NULL, NULL, 0, '2026-06-18 11:14:15', 0),
(96, 41, 'admin', 'سلام در خدمتیم', NULL, NULL, NULL, NULL, '2026-06-18 11:18:03', 1),
(97, 41, 'user', 'عه راهنمایی میکنین', NULL, NULL, NULL, 0, '2026-06-18 11:18:28', 0),
(98, 41, 'user', 'چطوری تتر بگیرم', NULL, NULL, NULL, 0, '2026-06-18 11:18:35', 0),
(99, 41, 'admin', 'لطفا از قسمت ثبت حواله برای خرید آگهی ثبت کنید قیمت تعیین کنید مشتریان و فروشنذه ها به شما پیام میدن باهاشون سر قیمت توافق کنیدبعد قبول پیشنهاد به ما متصل میشید ما انجام میدیم', NULL, NULL, NULL, NULL, '2026-06-18 11:19:35', 1),
(100, 41, 'user', 'درست بعداون تتری که میفرستن بکجا واریز میشه باید حساب ولت شخصی بدیم به فروشنده؟', NULL, NULL, NULL, 0, '2026-06-18 11:20:31', 0),
(101, 41, 'user', 'ما چه موقع پول واریز کنیم اونا چه موقع ارزو واریز میکنن؟', NULL, NULL, NULL, 0, '2026-06-18 11:21:00', 0),
(102, 41, 'admin', 'تتر رو ما واریز میکنیم بله از شما آدرس میگیریم', NULL, NULL, NULL, NULL, '2026-06-18 11:21:02', 1),
(103, 41, 'user', 'تومان هم‌باید به شما واریز کنیم؟', NULL, NULL, NULL, 0, '2026-06-18 11:21:57', 0),
(104, 41, 'admin', 'بله همیشه یک طرف به ما واریز میکنند و ما تراکنش رو انجام میدیم به منظورم جلوگیری از کلاهبرداری ما به عنوان واسط بین خریدارو فروشنده عمل میکنیم', NULL, NULL, NULL, NULL, '2026-06-18 11:22:52', 1),
(105, 41, 'admin', 'شما میتوانید داخل تلگرام و ربات ماهم همینطور فعالیت کنید', NULL, NULL, NULL, NULL, '2026-06-18 11:23:13', 1),
(106, 41, 'user', 'آیا شماره تلفن یا دفتری تو ایران دارین برای پشتیبانی و پاسخ گویی؟', NULL, NULL, NULL, 0, '2026-06-18 11:24:07', 0),
(107, 41, 'admin', 'ففط از طریق تلگرام با شماره زیر \r\n+372 5313 3195\r\nو یوزرنیم \r\n@AradTransfer_admin', NULL, NULL, NULL, NULL, '2026-06-18 11:25:25', 1),
(108, 41, 'user', 'کمیسیون هم میگیرین؟', NULL, NULL, NULL, 0, '2026-06-18 11:26:01', 0),
(109, 41, 'admin', 'بله ۵ تتر برای صد -تا هزار تتر', NULL, NULL, NULL, NULL, '2026-06-18 11:26:28', 1),
(110, 41, 'admin', 'بیشتر از هزار تتر یک و نیم درصد', NULL, NULL, NULL, NULL, '2026-06-18 11:26:45', 1),
(111, 41, 'admin', 'و یکی از مزایای ما این هست که ما سریع تتر را واریز میکنیم اگر با اکانت و نام احراز شده خودتون تراکنش واریزی رو انجام داده باشین', NULL, NULL, NULL, NULL, '2026-06-18 11:27:23', 1),
(112, 41, 'user', 'یورو هم همون ۵یورو؟', NULL, NULL, NULL, 0, '2026-06-18 11:28:17', 0),
(113, 41, 'admin', 'بله کل ارز ها', NULL, NULL, NULL, NULL, '2026-06-18 11:28:27', 1),
(114, 41, 'user', 'ازدوطرف همین مقدار دریافت میکنین ؟', NULL, NULL, NULL, 0, '2026-06-18 11:28:44', 0),
(115, 41, 'admin', 'اگرم در یک ماه بیشتر از سه بار خرید یا فروش داشته باشین پنجاه درصد تخفیف کمیسیون نیز دریافت میکنین', NULL, NULL, NULL, NULL, '2026-06-18 11:28:58', 1),
(116, 41, 'admin', 'بله', NULL, NULL, NULL, NULL, '2026-06-18 11:29:14', 1),
(117, 41, 'user', 'زیرصدتاهم میشه انجام داد؟', NULL, NULL, NULL, 0, '2026-06-18 11:29:36', 0),
(118, 41, 'admin', 'بله از پنجاه تتر شروع میشه', NULL, NULL, NULL, NULL, '2026-06-18 11:29:56', 1),
(119, 41, 'admin', 'ولی یورو نه باید خریدار باشه', NULL, NULL, NULL, NULL, '2026-06-18 11:30:13', 1),
(120, 41, 'user', 'اینکه از وایز و رولوت هم میشه ارسال کرد؟', NULL, NULL, NULL, 0, '2026-06-18 11:31:22', 0),
(121, 41, 'admin', 'بله', NULL, NULL, NULL, NULL, '2026-06-18 11:31:37', 1),
(122, 41, 'user', 'پس یک صرافی متمرکزین', NULL, NULL, NULL, 0, '2026-06-18 11:32:48', 0),
(123, 41, 'admin', 'ما سیستم تبادل ارزی رو داریم', NULL, NULL, NULL, NULL, '2026-06-18 11:33:18', 1),
(124, 41, 'admin', 'ما فقط واسط بین خریدار و فروشنده با اصل و تضمین واریزی هستیم', NULL, NULL, NULL, NULL, '2026-06-18 11:33:43', 1),
(125, 41, 'user', 'چه تضمینی دارین؟', NULL, NULL, NULL, 0, '2026-06-18 11:37:45', 0),
(126, 41, 'user', 'احرازهویت نکرده باشم نمیتوانم تبادل بامبادله انجام بدم؟', NULL, NULL, NULL, 0, '2026-06-18 11:41:13', 0),
(127, 41, 'admin', 'خیر متاسفانه بد‌ون احراز نمیتونین', NULL, NULL, NULL, NULL, '2026-06-18 11:42:14', 1),
(128, 41, 'admin', 'شما صحت کار مارو از  طریق اینستاگرام \r\nو تلگرام \r\n@aradtransfer جویا شوید', NULL, NULL, NULL, NULL, '2026-06-18 11:42:53', 1),
(129, 41, 'user', 'آیا با پاس و مدارک خارجی هم میشه احراز هویت کرد', NULL, NULL, NULL, 0, '2026-06-18 11:45:24', 0);

-- --------------------------------------------------------

--
-- Table structure for table `company_cards`
--

CREATE TABLE `company_cards` (
  `id` int(11) NOT NULL,
  `iban` varchar(34) NOT NULL,
  `bank_name` varchar(100) NOT NULL,
  `card_owner` varchar(100) DEFAULT 'شرکت آراد',
  `description` text DEFAULT NULL,
  `is_active` tinyint(4) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `card_number` varchar(24) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `company_cards`
--

INSERT INTO `company_cards` (`id`, `iban`, `bank_name`, `card_owner`, `description`, `is_active`, `created_at`, `card_number`) VALUES
(2, '760120020000003436957409', 'ملت', 'ندا لطف الله زاده', '', 1, '2026-05-25 09:11:29', '760120020000003436957409'),
(3, '5121110003460409', 'آزاد', 'شرکت آراد', 'آزاد', 1, '2026-06-02 17:05:10', '5121110003460409');

-- --------------------------------------------------------

--
-- Table structure for table `currency_rates`
--

CREATE TABLE `currency_rates` (
  `id` int(11) NOT NULL,
  `currency` varchar(10) NOT NULL,
  `price` decimal(20,2) NOT NULL,
  `change_24h` decimal(5,2) DEFAULT 0.00,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `currency_rates`
--

INSERT INTO `currency_rates` (`id`, `currency`, `price`, `change_24h`, `updated_at`) VALUES
(1, 'USDT', 153000.00, 2.50, '2026-04-06 10:38:25'),
(2, 'USD', 154000.00, 2.50, '2026-04-06 10:38:25'),
(3, 'EUR', 183000.00, 1.80, '2026-04-06 10:38:25');

-- --------------------------------------------------------

--
-- Table structure for table `dashboard_slides`
--

CREATE TABLE `dashboard_slides` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `bg_color` varchar(100) DEFAULT 'linear-gradient(135deg,#6C40C5,#FF4D8D)',
  `icon` varchar(100) DEFAULT 'fas fa-star',
  `link` varchar(500) DEFAULT '',
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(4) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `image_url` varchar(500) DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `dashboard_slides`
--

INSERT INTO `dashboard_slides` (`id`, `title`, `description`, `bg_color`, `icon`, `link`, `sort_order`, `is_active`, `created_at`, `image_url`) VALUES
(6, '', '', 'linear-gradient(135deg,#6C40C5,#FF4D8D)', '', '', 0, 1, '2026-06-29 19:01:24', '/ledor/uploads/slides/slide_6a42c103a246e.png'),
(7, 'کسب درامد با دعوت دوستانتان!.', 'دوستانتان را دعوت کنید، به ازای هر تراکنش موفق دوستانتان کسب درامد کنید', 'linear-gradient(135deg,#6C40C5,#FF4D8D)', 'fas fa-star', '', 0, 1, '2026-06-29 20:33:14', '');

-- --------------------------------------------------------

--
-- Table structure for table `exchange_ads`
--

CREATE TABLE `exchange_ads` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` enum('buy','sell') NOT NULL,
  `currency` varchar(10) NOT NULL,
  `amount` decimal(20,2) NOT NULL,
  `price` decimal(20,2) NOT NULL COMMENT 'قیمت به تومان',
  `payment_method` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','completed','cancelled') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `exchange_offers`
--

CREATE TABLE `exchange_offers` (
  `id` int(11) NOT NULL,
  `ad_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `price` decimal(20,2) NOT NULL COMMENT 'قیمت پیشنهادی به تومان',
  `message` text DEFAULT NULL,
  `status` enum('pending','accepted','rejected') DEFAULT 'pending',
  `reject_reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `money_transfers`
--

CREATE TABLE `money_transfers` (
  `id` int(11) NOT NULL,
  `tracking_code` varchar(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `country` varchar(100) NOT NULL,
  `country_code` varchar(5) NOT NULL,
  `currency` varchar(10) DEFAULT 'EUR',
  `full_name` varchar(200) NOT NULL,
  `iban` varchar(100) NOT NULL,
  `bank_name` varchar(200) NOT NULL,
  `amount` decimal(20,2) NOT NULL,
  `short_info` text DEFAULT NULL,
  `status` enum('pending','approved','rejected','waiting_payment','payment_submitted','completed') DEFAULT 'pending',
  `reject_reason` text DEFAULT NULL,
  `admin_note` text DEFAULT NULL,
  `payment_receipt` varchar(500) DEFAULT NULL,
  `settlement_receipt` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `approved_at` datetime DEFAULT NULL,
  `payment_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `news`
--

CREATE TABLE `news` (
  `id` int(11) NOT NULL,
  `title` varchar(400) NOT NULL,
  `title_en` varchar(400) DEFAULT NULL,
  `content` text NOT NULL,
  `content_en` text DEFAULT NULL,
  `image` varchar(500) DEFAULT NULL,
  `link` varchar(500) DEFAULT NULL,
  `active` tinyint(1) DEFAULT 1,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `news`
--

INSERT INTO `news` (`id`, `title`, `title_en`, `content`, `content_en`, `image`, `link`, `active`, `sort_order`, `created_at`, `updated_at`) VALUES
(7, 'هشدار مهم:درباره واریز ازولت های صرافی های ایرانی ', 'Important Warning ,Avoid Depositing crypto from Iranian Wallets ', 'با توجه به تشدید تحریم‌های اخیر آمریکا علیه برخی صرافی‌ها و کیف پول‌های مرتبط با ایران، متأسفانه احتمال مسدود شدن یا محدود شدن برخی تراکنش‌ها افزایش یافته است.\r\nلطفاً از واریز ارز دیجیتال از کیف پول‌ها و پلتفرم‌های ایرانی به آدرس‌های صرافی آراد خودداری فرمایید.بدیهی است در صورت واریز از کیف پول‌های ایرانی و بروز هرگونه مشکل، مسئولیتی بابت پرداخت معادل ریالی یا جبران خسارت متوجه صرافی آراد نخواهد بود.\r\n\r\nاز همکاری و همراهی شما سپاسگزاریم.\r\nتیم تخصصی تبادل ارزی آراد', 'Due to the recent escalation of U.S. sanctions against certain exchanges and wallets associated with Iran, the likelihood of some transactions being restricted or blocked has unfortunately increased.\\n\\nWe kindly ask you to refrain from transferring cryptocurrencies from Iranian wallets and platforms to your Arad Exchange deposit addresses.\\n\\nPlease note that if funds are deposited from Iranian wallets and any issues arise as a result, Arad Exchange will bear no responsibility for compensating the equivalent fiat value or covering any resulting losses.\\n\\nThank you for your cooperation and continued support.\\n\\nArad Currency Exchange Team', 'uploads/news/news_1780635804_8146.jpeg', '', 1, 0, '2026-06-05 05:06:04', '2026-06-05 05:10:25'),
(8, 'تخفیفات ویژه مشتریان وفادار', '📢 Special Announcement for Our Valued Customers', '📢 خبر ویژه برای مشتریان عزیز\r\n\r\nبا هدف قدردانی از همراهی شما، تخفیف‌ها و مزایای ویژه‌ای در نظر گرفته‌ایم:\r\n\r\n\r\n🎉 تخفیف خرید سوم در ماه مشتریانی که در طول یک ماه بیش از دو بار خرید داشته باشند، در خرید سوم خود از 50٪ تخفیف ویژه بهره‌مند خواهند شد.\r\n\r\n\r\n👥 طرح معرفی کاربر فعال در صورتی که یک کاربر فعال به ما معرفی کنید، از مزایای زیر برخوردار خواهید شد:\r\n✅ یک بار امکان خرید یورو با نرخی \r\nبسیار پایین‌تر از قیمت بازار\r\n✅ 50٪ تخفیف در کمیسیون\r\n\r\n📲 تخفیف ویژه حمایت از صفحات مادر صورتی که صفحات ما را فالو کرده و مطالب ما را استوری یا تبلیغ کنید، 50٪ تخفیف در کمیسیون دریافت خواهید کرد.\r\n\r\nℹ️ اطلاعات بیشترجهت دریافت جزئیات و شرایط استفاده از این طرح‌ها، می‌توانید از طریق Customer Service با ادمین در ارتباط باشید.\r\n\r\nبا تشکر از اعتماد شما \r\nتیم تخصصی صرافی آراد \r\nAva Pay', 'As a token of appreciation for your continued support, we are pleased to offer the following special promotions:\r\n\r\n🎉 50% Discount on Your Third Purchase\r\nCustomers who make more than two purchases within a month will receive a 50% discount on their third purchase.\r\n\r\n👥 Active User Referral Program\r\nIf you refer an active user to our platform, you will receive:\r\n✅ A one-time opportunity to buy Euros at a rate significantly lower than the market price.\r\n✅ 50% discount on commission fees.\r\n\r\n📲 Social Media Promotion Reward\r\nIf you follow our pages and support us by sharing our content or posting stories about our services, you will receive a 50% discount on commission fees.\r\n\r\nℹ️ For More Information\r\nPlease contact our Customer Service team or reach out to the admin for full details and terms of these promotions.\r\n\r\nThank you for your trust and continued support! ❤️', 'uploads/news/news_1780644834_7233.jpeg', '', 1, 0, '2026-06-05 07:33:57', '2026-06-05 07:40:30');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` enum('transaction','system','admin') NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data`)),
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `type`, `title`, `message`, `is_read`, `data`, `created_at`) VALUES
(30, 18, 'transaction', '💰 Money Received', 'You received 123 USD from  ', 1, '{\"transaction_id\":\"TX17685914767543\",\"amount\":123,\"currency\":\"USD\",\"type\":\"received\",\"contact_id\":1,\"timestamp\":1768591476}', '2026-01-16 19:24:36'),
(36, 21, 'transaction', '💰 Money Received', 'You received 1 USD from  ', 1, '{\"transaction_id\":\"TX17698587312209\",\"amount\":1,\"currency\":\"USD\",\"type\":\"received\",\"contact_id\":1,\"timestamp\":1769858731}', '2026-01-31 11:25:31'),
(38, 26, 'transaction', '💰 Money Received', 'You received 3 USD from  ', 1, '{\"transaction_id\":\"TX17701242321967\",\"amount\":3,\"currency\":\"USD\",\"type\":\"received\",\"contact_id\":1,\"timestamp\":1770124232}', '2026-02-03 13:10:32'),
(40, 27, 'transaction', '💰 Money Received', 'You received 3 USD from  ', 1, '{\"transaction_id\":\"TX17701243166569\",\"amount\":3,\"currency\":\"USD\",\"type\":\"received\",\"contact_id\":1,\"timestamp\":1770124316}', '2026-02-03 13:11:56'),
(54, 17, 'transaction', '💰 Money Received', 'You received 23 USD from  ', 0, '{\"transaction_id\":\"TX17811292582177\",\"amount\":23,\"currency\":\"USD\",\"type\":\"received\",\"contact_id\":1,\"timestamp\":1781129258}', '2026-06-10 22:07:38'),
(56, 17, 'transaction', '💰 Money Received', 'You received 1 USD from  ', 0, '{\"transaction_id\":\"TX17811293636886\",\"amount\":1,\"currency\":\"USD\",\"type\":\"received\",\"contact_id\":1,\"timestamp\":1781129363}', '2026-06-10 22:09:23'),
(58, 17, 'transaction', '💰 Money Received', 'You received 1 USD from  ', 0, '{\"transaction_id\":\"TX17811294218279\",\"amount\":1,\"currency\":\"USD\",\"type\":\"received\",\"contact_id\":1,\"timestamp\":1781129421}', '2026-06-10 22:10:21'),
(60, 17, 'transaction', '💰 Money Received', 'You received 1 USD from  ', 0, '{\"transaction_id\":\"TX17812122219841\",\"amount\":1,\"currency\":\"USD\",\"type\":\"received\",\"contact_id\":1,\"timestamp\":1781212221}', '2026-06-11 21:10:21');

-- --------------------------------------------------------

--
-- Table structure for table `payment_receipts`
--

CREATE TABLE `payment_receipts` (
  `id` int(11) NOT NULL,
  `offer_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `image_path` varchar(500) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `admin_notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payment_receipts`
--

INSERT INTO `payment_receipts` (`id`, `offer_id`, `user_id`, `image_path`, `description`, `status`, `admin_notes`, `created_at`, `updated_at`) VALUES
(9, 28, 1, 'uploads/receipts/receipt_1772805400_69aadd186efe7.jpg', '', 'pending', NULL, '2026-03-06 13:56:40', '2026-03-06 13:56:40'),
(10, 29, 17, 'uploads/receipts/receipt_1772805559_69aaddb74598a.png', '', 'pending', NULL, '2026-03-06 13:59:19', '2026-03-06 13:59:19'),
(11, 31, 17, 'uploads/receipts/receipt_1772816615_69ab08e7da8af.jpeg', '', 'pending', NULL, '2026-03-06 17:03:35', '2026-03-06 17:03:35'),
(12, 30, 1, 'uploads/receipts/receipt_1773409832_69b41628d80f2.jpg', '', 'pending', NULL, '2026-03-13 13:50:32', '2026-03-13 13:50:32'),
(13, 36, 1, 'uploads/receipts/receipt_1773500608_69b578c0b4f75.jpeg', '', 'pending', NULL, '2026-03-14 15:03:28', '2026-03-14 15:03:28'),
(14, 33, 17, 'uploads/receipts/receipt_1774727719_69c83227a0838.jpeg', '', 'approved', NULL, '2026-03-28 19:55:19', '2026-03-28 19:55:57');

-- --------------------------------------------------------

--
-- Table structure for table `personal_transactions`
--

CREATE TABLE `personal_transactions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` enum('income','expense') NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `currency` enum('USD','EUR','USDT','IRR') NOT NULL DEFAULT 'IRR',
  `description` varchar(255) NOT NULL,
  `transaction_date` date NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `personal_transactions`
--

INSERT INTO `personal_transactions` (`id`, `user_id`, `type`, `amount`, `currency`, `description`, `transaction_date`, `created_at`) VALUES
(36, 1, 'income', 1128.00, 'EUR', 'Stiftung tannenhof gehalt Feb', '2026-03-01', '2026-02-26 07:37:08'),
(37, 1, 'expense', 82.00, 'EUR', 'Tanken', '2026-03-02', '2026-02-26 07:38:37'),
(42, 1, 'income', 186.00, 'EUR', 'agentur fur Arbeit', '2026-03-01', '2026-02-27 08:01:02'),
(44, 1, 'expense', 224.00, 'EUR', 'Autos steuer', '2026-03-01', '2026-02-27 08:17:19'),
(45, 1, 'expense', 487.00, 'EUR', 'Miet wohnung', '2026-03-02', '2026-02-27 08:18:01'),
(47, 1, 'expense', 163.00, 'EUR', 'Santander', '2026-03-04', '2026-02-27 09:32:44'),
(49, 1, 'expense', 50.00, 'EUR', 'Otto rente', '2026-03-10', '2026-02-27 15:04:02'),
(50, 1, 'expense', 3.00, 'EUR', 'خرید', '2026-02-06', '2026-02-28 22:08:26'),
(51, 1, 'expense', 3.00, 'EUR', 'خرید', '2026-03-06', '2026-02-28 22:09:04'),
(52, 1, 'expense', 120.00, 'EUR', 'Auto versicherung', '2026-03-14', '2026-03-01 17:34:20'),
(53, 1, 'expense', 50.00, 'EUR', 'DHL', '2026-03-05', '2026-03-01 18:53:08'),
(54, 1, 'expense', 50.00, 'EUR', 'سعید جریمه', '2026-03-06', '2026-03-06 16:53:32'),
(55, 1, 'expense', 20.00, 'EUR', 'خرید', '2026-03-14', '2026-03-06 16:54:00'),
(56, 1, 'expense', 11.00, 'EUR', 'کمیسیون بانک', '2026-03-01', '2026-03-11 00:36:38'),
(57, 1, 'expense', 162.00, 'EUR', 'قسط ماشین', '2026-03-02', '2026-03-11 00:37:58'),
(58, 1, 'expense', 100.00, 'EUR', 'خرید', '2026-03-11', '2026-03-11 00:39:31'),
(59, 1, 'income', 500.00, 'EUR', 'Burger', '2026-03-15', '2026-03-16 01:18:00'),
(60, 1, 'expense', 69.00, 'EUR', 'تفریح', '2026-03-31', '2026-04-01 05:24:09'),
(61, 1, 'expense', 60.00, 'IRR', 'تفریح', '2026-03-28', '2026-04-01 05:24:39'),
(62, 1, 'income', 60.00, 'EUR', 'تفریح', '2026-03-28', '2026-04-01 05:25:18'),
(63, 1, 'expense', 174.00, 'EUR', 'Car loan', '2026-04-01', '2026-04-01 05:26:36'),
(64, 1, 'expense', 55.00, 'EUR', 'Radio', '2026-04-01', '2026-04-01 05:27:12');

-- --------------------------------------------------------

--
-- Table structure for table `push_subscriptions`
--

CREATE TABLE `push_subscriptions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `endpoint` text NOT NULL,
  `p256dh` text NOT NULL,
  `auth` text NOT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `push_subscriptions`
--

INSERT INTO `push_subscriptions` (`id`, `user_id`, `endpoint`, `p256dh`, `auth`, `user_agent`, `created_at`, `updated_at`) VALUES
(1592, 17, 'https://fcm.googleapis.com/fcm/send/fAZebaJxSpc:APA91bFFua6ou6JWXRiOOcIE0lAUjeRQtEmG0Ka92KzIALANBmrY4NLnCaTpxZzxWJHXWWujeI2IOVFXMkPW0Vdoy9AQ5Wi6bhpo1dqPUHdT_M0n5fsuSUpa-lFUHFpJRFc8x9sQFJC_', 'BNMfwZ2jFLQX5h2fR4TrwxFYkQJoxfgR/JxUyE0sbpLwhOhRY/FFOLF/D+VkKpnrMO7NDRtqHjyOs00hG9wh0uY=', 'PjvZMXyui8cy25AnxWVPNw==', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-28 10:15:01', '2026-06-28 10:15:01'),
(1594, 17, 'https://fcm.googleapis.com/fcm/send/ekftCgkTzIM:APA91bFByR0GZTaGETJBJTNChFreVePF7oEaPdyrFQjumHRde1tlGsgZjzwVnUSxO2nqgoiqXYBl2MDAIQeOoHuFvxbhRRouVyolf1lTE0PQdKMC18fNn8wVTS1ujCZXwFkaeDVoSVz-', 'BDq6qGz0DfO8HOtYnslTxt5TOGQDzAclLcp2T3oRz1Seq+/HobChTiChtLZr6bjwl0ST+ZN01eZicgo1dYt78Fw=', 'smq1Idggipw7DtMdiFmieQ==', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-28 10:18:06', '2026-06-28 10:18:06'),
(1595, 17, 'https://fcm.googleapis.com/fcm/send/e8htU4aPX9g:APA91bHEEfBIE0g5JOKC4KPX7n_psmzTr6QzsCLVckvWMtADmvTFxyyr5LFiZhsAsQPYIbhP5ABzjhQTkvgykROgZhgH63h8NFwAevcI6zDF82TVZPlhgi4jbkjiccWS-dFeyQv0cU_I', 'BGD8+wSYch+6y3Hp9+rjslbza6nBK9Y/lnuA7EkcUP6QFWoAj9QGRtl3MuL1sVmHYJ4xwunB4b8vpM5mONYxiAs=', 'Dyxbxm5RIT8VZqR9/24R3A==', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-28 10:18:10', '2026-06-28 10:18:10'),
(1599, 1, 'https://web.push.apple.com/QCzvNCxN2OSfoCQ3_Vn7KbIloJBabDTiot0C5_xVDBB4O1LeznC0J_cOVzrEJB0ZugN6w3GAJjD7MqLnkPnZM8sWWe6eiecL4QTXbNyL1Asb8RQIENUQkE3drlHi_zSUl7zw3iMsthY-my4plUs6QZIYTryu7tBif2uU1XiYVcg', 'BI3IonYaQuLYXySoZD9ve5msJa6CUg6pZBRR4gmUbOKWstbtMk6UbaoUDy1x+tK1hXmtTzfMy+1X9yP/CHM9trk=', 'ykuRStYPPNWX4c95Oe/lcA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 10:21:03', '2026-06-28 10:21:03'),
(1603, 1, 'https://web.push.apple.com/QERGp4aYCTt6DGo5qFgGLkdDjMmYe0s7U3uKo26Z-O8-bp_1HbddellrmiwXtTwvnnQ0JLwDNDjnX2oX_FeRyVDNMIaAf-y6uPfPaSnmEjms0LyRezJgb1pLexOjj6uxN05Bis6cHQwR18lGR7aEOjAxSe4OMWCzL8HrR2HTvWw', 'BEfttlWI/xxXXStI1urNVSlJLhxDcHhZ30bEyS1AbKvXlr6XkxqLUrgTcp/4pTAxf4IJYsm11UaHwQrRpkd4Ba0=', 't3paXI7AwNoD8HVAEtVmqg==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 10:34:21', '2026-06-28 10:34:21'),
(1604, 1, 'https://web.push.apple.com/QBD4SOCevVboRDYgfdBgCart9pUaykRtPCXVBZjSJtw99JEw9v9fTtus0hw8w_GcdkUGvaa5WeVhBR7jLKT60SdJklJWwJ52e4Gp1P12ap2BC5oNbqrcl6znPOz7mkau_xg5oSzdAxvqILgZBVFyIVmpq9OICxzyIm04JA5dGZc', 'BKe8gYfCBzEFcrGBM685depzSe1Qwmnof+/ByQxmfMyVgUVFCCIbIOmd2wkVd/KEoGUhnftENlScseOv9Bj1B2s=', 'aH0PrD5HgkrsU1kEFiCrdw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 10:34:26', '2026-06-28 10:34:26'),
(1611, 1, 'https://wns2-am3p.notify.windows.com/w/?token=BQYAAAAr3pthgVCDQZzToD%2f4UFAZixBdkEpuqU4ypcCnW8n5Iieg2E12y38v1qsk96AfvptubJxfnUi0%2b0rnEsVopBwwIaYTT5mzf15vhOaf93eJz4JMQ7h%2fq1S54r%2bsUNGz8txxymTIVbk1K5%2bU7wAE366EFRO84J9W1dSGQva95aOvGnvu0lTgxZfDDY%2fykcqFCRjpxRhZE4aiaApZkNn1FUei7tQd1OUZQtlVWcsb4ufK0ArXPKjGypkbB0p6sI5wCEfLONzUmT8y2%2b56v90DhTAnSSfAR8Xm%2bfsdR%2fq7TWDULykszIAbVcQQLNre4OAjy5s%3d', 'BOmTqez55/rE0oyrqFT8LQR3h54NYJNE2r3YLi+VWqZf8Eg6jYG+nzi8iAe95qkSqejZzE2rxlwJx5+WGJeoAYo=', 'bWKtLx+xpaJs1ySyZYFWeQ==', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-28 10:40:52', '2026-06-28 10:40:52'),
(1614, 1, 'https://web.push.apple.com/QO0BVCAWXviPhDcqhrCL6Y0YBaxEzfDipsxp224VJWGBV8vpPseHpEk0jyrFn6NR76RH3pnB8k_ovDxYuIIQjNfiPCn-pOul9b5LQ397sA7IVXTfCUVbwkFM2qSxYb3Ki3Kgo9yaGqvGdLTP6SaPsijv0MWcWq6-CBoe1wqxkYU', 'BAwz3hwNK6+J9T0dgzscsgA5AEQNOBtTg8Ao0nIRq5x3PQVdjioVOm6/lu2BNvazz9iq6Q3tw1n5EeG0qYKQ14Y=', 'WPihp2HuFQEpjy+cdj9QYw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 10:43:17', '2026-06-28 10:43:17'),
(1623, 1, 'https://web.push.apple.com/QM9Bh4acp_D8JuuZKA_aMtXHNfNHfeYZ7ZdDtjoIzRITlY38weKF340vD5pOdU-z8WR-8gZMa-Ygl4nI0M30KZgT7TmfwpJ1I2J3Yxk3kEoFYmmwcnVnb5GH4XWoFn8ARNCxV_v_2Xiyhr_Ua6pc0FI3-iUhhMs9T4Ldu1Fjm5s', 'BHXIkS7e9SCT+IUIv0zRygJrkxx/1+sLqPw3jXf/P47td0WvrxdmUQ58fZ9wXbWxGl6eDJLnmLvr67lRRvmF3Go=', 'eL+/9k/rT0vjnsr4l7h7Pg==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 10:53:06', '2026-06-28 10:53:06'),
(1624, 1, 'https://web.push.apple.com/QB-iyk2b79zapxmHkjHKBIcaso0hyDnYuqB5RxMnxeh7fUkq18yFYLLm-tkz4noj4hirkNVooVDfD31cwln1E-VftA7jtB0AolR_pl9_CrD1azWRx2ah2QiS0L5byDjvzoPL3AQG31smC4WXIkAQu3NltyF21WBTlV1wcixPHkc', 'BLDJL7Qe/Gfc5LBuD8J1r/Qax4kD8/OJNUlWxPKczXanaZECY1Aop38ZTRaluLZo3ErtfZBe93+MVWfKxbikIqQ=', 'k/FU/VBgpbbA6p44nzWQqQ==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 10:53:07', '2026-06-28 10:53:07'),
(1625, 1, 'https://web.push.apple.com/QH8SxMePnu69hqZEinULa3nr_idNc_4JR8C-aMR9Z3t27EYsA-NMi-XZlll58zz5q_Yt78Yuvdc2ajdLFqqey5Nt3xth0GyDWmeGd8QzkRG6yTuv7P4lgL1VUHCaRx-cJawm7K_bQXrDKwvIQRDmrV4acaWSLNtq3KWb2aQx-Zo', 'BN33T3DBgNTu0eSTHZjDJwk5rP0ni+M2H9wFi9kY+FDHIarXUzlcEFcetObr0Tptq6IS7cEw9bd3Dsn2MDbxwl8=', 'aCP2XqugV4vVbwk9GUSFIw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 10:53:13', '2026-06-28 10:53:13'),
(1626, 1, 'https://web.push.apple.com/QGkTBx5XmoZcokDTyw_9nPRDpr6pBgqzJOxjVvtc-XrdW84iw-Chh1pwDfTFuEesJ4OWqvWCsuXUvuNg5jMZuNjwAXxHaqhOS4A03B-rcwnJbWI0YoolfS-a3xIZMxpXVKP2_1Ke8PhRyWcFFmRROgcIQV9-ihhzgQS_Umt4RqQ', 'BMiaAe6k0OOtwqMymeEf4OvbNmrWZ01lgXkZY2UKClHaLKzsdzA2/AhLokDJNQ/D1sr94dI7zmEoE3a8Jwjr5gg=', 'loC1bKD6TaUdU3crOSlhvw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 10:53:14', '2026-06-28 10:53:14'),
(1627, 1, 'https://wns2-am3p.notify.windows.com/w/?token=BQYAAADGkABS2DG6SbMEXryHBN8w%2bnhmY1GNzhWXiivnjw1RZUoi7ZVZtqoOUbMxu%2fVSm%2fCo%2fqgHzQfXLsUcqmGn%2fpGIIQHKXFdxEIv14cvOIOULBG4MvePA%2f3qIF%2f99ZV9bWdFYBURkFmQ6It22PAZf9O9Obry9XM1s9%2faDecPlIGIknBMdza0CScM4TYYHTsAkLigAgDekzO7vV2%2bn95nN3H1IIBC3X1fjTrsu%2fjcb1k1Vjrt22%2f4vrgG9Zxgd2AoBBcSJEEdQPwb%2bg8U4065kqs%2fjtfNw1RTwF%2ft1AgE2lkzCRgS%2bw%2bY1A%2bKUPoxdBAf2iBA%3d', 'BATd5uQyHrxXu2LuXBniirzGUB1uolpOuCsKuTtUkhCFQUyQL58UE97oB48X5dehGDl9y0nKHI+8ML0vagVMhQM=', 'fYY38nTM07dtncWeyyzC7g==', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-28 11:19:27', '2026-06-28 11:19:27'),
(1628, 1, 'https://wns2-am3p.notify.windows.com/w/?token=BQYAAADCOLX152PKZXvy7CWhHXroBbKod5DbM8DgG2fj8ZkkQu073Eh6I7txWP3oZT1bKY1bZiow0aOomdI2T0OlLMEyBClh5qfPvu1t5KH95QTAIBSMD7%2fomzzamcwtvKpiqt69vtQf7m9QBQGUVQA%2bognQB432uCq8AcYMy9Onb8lLvYjh%2f9AtNhNdWygOKweYx8mU4EbmrUsZurbpKmPjHca6jNlKLCx%2fC1IvEkIvW%2bjXkMdyHHvNCdti6vTpshyPv3LcKd1GeGzM0lx8EFXL5bAvZj3zKVgUanHS%2f645PIc7zDP08ESmo0D%2bf4zaLkLOET4%3d', 'BPrTxYw9GGlkssOCSm9Sm1zjt2SRqxUy2cV3ECenghZ4x51eraWjm2BuObN3KucJBJhguNG6+DVK4aWy7kx7XFo=', 'VYA1o/+lC2kYPxTSvvb9nw==', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-28 11:19:28', '2026-06-28 11:19:28'),
(1635, 1, 'https://web.push.apple.com/QExekONNCulw-UZgP4W7xCmRVgVVphbZBF_FGx977Iz2vYnWE3NONnRoz8OSWT0hBPcNuXdfJRtVx0A4l0i_KRf-BswSKou5YLkLvBOVBB7iPM9GKhyIDzX29rjET2LwlugUEsE8VMi70_W0oUDm0zFTpF3z7TwszkF4QqE7on4', 'BPKlang6HI4p680dDihR/GdUlh+Irw9VawkaG7tGHzCFW7B6h6wrlYSezJ6JSGJb06VjlOXWEb7TYAAkxqfKQgY=', '+M4CrBSJUrdP0I2ZqGOiGg==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 11:22:44', '2026-06-28 11:22:44'),
(1636, 1, 'https://web.push.apple.com/QFXWL6JR-7MlLL8Ti320bl5hGVu9DvaL5pgtml8imC_lpW3uZjv_wtCP0pIoftEJi9VKfeHrorHDLvAIifFJEucUm7Rti4O4120DNkFghwfQvXAychHnimXeFSczmGxjns7L-GnRo0KXlpuTgXsZkSDhWRRYORFvWXVNNIEhxyw', 'BIrlwAZfjJBP1v8QDXV6osbDdTr5YiPc9ViO2RMAV//KKMli0lTtWKboC/1LX/zE3Is+fBznC4gb3mFc+1/1XPs=', 'Dzk3M/47JCshdF2gBY3m9w==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 11:22:44', '2026-06-28 11:22:44'),
(1639, 1, 'https://web.push.apple.com/QERZYKxT-WJDdwESRbbe4Xbs_3j6aF8asawP28VaZ4R3g9pddlaLV6V2stPspyX4ETjyiYrsTCsRMCZm8ZqH-Bnc8qJQeeFuqSCF818jXy06DdYst368T1xf22qI0gVH8ILlIUsWnsKEO-1KVQvKoVQnBUOMxI_eXeEVOfd3e18', 'BKqZwERpapPgaBwvOT17GvNKvZte7jjb1LZ/sl4HV0eNJZqA0+gofksxZnz5NlfSRfPBsxJRX79O3G99v+de4lI=', 'Hb5pXkmWtB35CL0adJD01Q==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 11:28:32', '2026-06-28 11:28:32'),
(1640, 1, 'https://web.push.apple.com/QLe_6fn5aQ5NOsrwIS_RhUka8DTk3MvuvnC9hNOCkDBAe2-7nymjZrLuPoEmvGF7NgzFgVcCvkCRmcqd3LrFYI9GFyv0fBkPx3Wtw_b07-GARcpfBFOgu1CIBcPE4-XNvrulNtBeZdg5fTXKh-hzfRDxfe63LKlRbhF-etyi8No', 'BG+V1brmeGL627WGinCNoqEE3vlV6CbahwWgjO/k55hDkH+5Irl5V1iWGcqq321rny7JnFS23EVO7tJIwFED5Fw=', '9vDwj3J98OgR7FoAJNYKhw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 11:28:33', '2026-06-28 11:28:33'),
(1641, 1, 'https://web.push.apple.com/QCpILllql2T9-PhTdRUZ2wT8vo5YlyGgRkQofhG7QdTymyek5N0a4FJ4jKE9beFKNIluO5PZxX9AMSd9KH8AFlezimCgreVqLtaNWueXhED_BfKUP9EeKkgoMfaGBecjZXqtODjLDBLt8muYPpdD3SioA5klipZlcaAV4eqXADg', 'BLmjlc838k8gjhtnbPCW69xuHCYH0/CVpZSX8TYG+XuBeKbHaBenn8VN0VfZdBvarow7HE3MA+Bg+oLx83xVq7A=', 'CqqS209vACZbe60wGn4pSQ==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 11:28:52', '2026-06-28 11:28:52'),
(1642, 1, 'https://web.push.apple.com/QKDYV2UDW30eLmW_5wAZ0Gj2kQ5EH86z24MdoyXNw2DAvHzgs047R5TVyROKjkobBwJyFaVA7AGaTgeurzFeLFM-lOMiNBiDjZUnijRpiBDBINFn3234ytN5L8i_ISTEDe-WXZFajn23DR9QAvAPgozjBp89F45x1sys1WHOxZs', 'BFDua/3/NlGKwyO1SWKJ15ANSilONu5deH/DlsZntNoemGbQeg8e13GchmW/oKd0Scr9diF3KPTmNQHL7eLeMbk=', 'LsCO9/G/cAXCP9o7YH3bNA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 11:28:53', '2026-06-28 11:28:53'),
(1643, 1, 'https://web.push.apple.com/QIlKEYC5nDOExItyHyybVf7SHNEVp2X4Gy99UKECODAocgq4OA_5j_BtbbObo7s2ZIhoeElzPK9yh8RTCSEFLRaPuBO5w6WNK7pD9jB2KxEYg9zA81vEz_9RXbHMEPv4xLhQH5cLrBWlpiuY-3-8ppdon_ri2mVl51WkWUgfXfw', 'BEORtepejGhB8dnOasGvpT8fdHJs3+F8yvCLsr1YTWQ6LODnc4oNTC/3gLmwW2NIIjgoQJz1uIKwsdd3c0SoMyU=', 'iWw9MU0fhFb5i34ZLawNJg==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 11:29:40', '2026-06-28 11:29:40'),
(1644, 1, 'https://web.push.apple.com/QFzzTWfDg5RYEv-dnleWqc-LTkhbUU0xs_uQX87xWDck_WlSWKmmaTFRxirjMtTts_THxe1QMiJ8grr83NA-76uNiabRr04Ra2H19CxmcW44Eo1K5aZbYApdp_wTk1XVhtdaeKmwIKFvxLv7NMwzyJ6BCljP9XczduiFiSwjsGE', 'BBVM6yRKq16Erq5P7hEWPjhmnQGwTf0A8fTkmqq+b5LQPowYHpwDhMC4ObmXbdZyEZwahez5uwUYZfi1itdv/Hk=', 'JtNL8kKxQm1wJltwZxdpZw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 11:29:40', '2026-06-28 11:29:40'),
(1645, 1, 'https://web.push.apple.com/QPql9tKWRwZ83vf9A022DJwmSsGvFJR2v_vATjL1tCv3lLBY-Rkeob3OynZbZZbDi0uOFLp-mOh5i7dQXPuf5yi98DaIlqyQLqfZncVL952ZPIooY2mvqY6AZl3u5IINUn8IOmTu2m9_GgHWJaDqWC00SogAhkj5hPfoqUt-IQc', 'BGKGBIKvLEuRlR7MP5+I0p4tDrL0nSPOvfmn7GgOeVPrms012EnqULTeKUGSPv2MxgUvIJVaBeppJcFcAfVI6a8=', '6P/FaJiYtTcevxrW+2EoLQ==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 11:29:45', '2026-06-28 11:29:45'),
(1647, 1, 'https://web.push.apple.com/QDPCtzf35-NcneSMsnN4dgVe3ATQhWU8qR8YFFI_WSoh2l8DRyHZ-WXFf5B11_w7Zk5P0SsA210kzP5uESvdHE6vd8-dIkTJ6tlaLZcCKhkgoazK99VxiBaTLc3YuJF5s1ZIXACbu-Ecbol_Rhqo6HNMQCz-S1L-Xs-j_r47Ljk', 'BLB4IjegDOkbYUu/LLDr300moPWNODYElrTBUESeXwCTyu5aeEOE0TpsnpC4b+IexRdHJdEYp+ZQ7zXiZeruJdw=', 'fmcY2YeEmv6YaVP8QsNqsg==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 11:43:23', '2026-06-28 11:43:23'),
(1648, 1, 'https://web.push.apple.com/QNV9N78_KbWEB_S6PQ0l1XprBv3p7umV4j1CLly9fWNeKZhDFmRt_-3O1Rsht4r1io7Hi5gFfmj04moD5ti6YIQjUVOawbPG87yLacNynK8hjjR38uXnl37ShgHMcsyNbl2kIUBJs7Xf9FTE92J-_v65LAPYGGajDDXdVZvbV1c', 'BGpIBpFYXAbplXT9eMMOO+R4TMHamGMQGPpV7xIdwARkbzWRepAw/l9CxMQM3CTcKO5QwfKHssxWEwZiYcUh5aA=', '5N9NMXPeI3vzfBCJpYzwvw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 11:43:23', '2026-06-28 11:43:23'),
(1649, 1, 'https://web.push.apple.com/QIe2dDzMc57RSBY968NsHFd24MO4alSo-S9JugEpAnilm7KUtJIY0d7M8_lj6xL2XgAzmAUrAEfbgFR0OUTBetln6ywb6z3yrLXQRjD9vp8U4qtpvHAW5hQRaS_oZTt7R6iZCcn94HLwmpsuZbrzytDPQ-HNV6uyFd-brGQNZl4', 'BAbJdKoZcTF6wG3vWpRprJdmLWwPHdvkDgw9nDFYvCvEPCugWzKivA3yQPflKnYdMBmSqyIn39Gms4lBLLhrkVs=', 'zkaTxBZk4nH+Qi+8VjjVUw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 11:44:59', '2026-06-28 11:44:59'),
(1650, 1, 'https://web.push.apple.com/QLKOityshH5aDGX1a60DAItgyQ0-JTSN8h6yIzz9Ul-5-6cVlUE6X6Pc_JpHMvD3-i5BycKRmI05Nw03jMgxqXJ7CBi62iyBPeygFIfA4W1hUWWAkaGtLGc52MWTMsltaZ63uT9SPlRXOV8sfR84Qgo6rAJn7NbNijC6Ot-0eTQ', 'BI7O0BTDcOQYzMzGEIn/MbWKa2OrSmXnwLKo1LeIWM1/XI1C3wI4/JNt6MgTgGZS2r9cOo4A+r8onCkVEGNxjBw=', 'Gkinm5BC54SnQQXRze5YoQ==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 11:44:59', '2026-06-28 11:44:59'),
(1651, 1, 'https://web.push.apple.com/QCooN8LcI-nDdHbR7NBm6oUNjYxcM9wdE-1jZ7ypwRS9YQLZQj_-wbIGmDfQLeyflcumDehdtvU4BPsD4S2qJcgKR_mGRHTe_f2h-y7wd0QHAz_3k-qK3zvtqULqNcPElFQTS37J2h6PKm2M8YQ-Ql4ISdhbp2MX_9HDpZyUttI', 'BLJ1+aNTjAE+SdtKWgwhAdvKxazmufhOzYQJm8iIeQZFd7iRJqBIfSVC5j8QbHwEgB7mR5oerV12WezHJqLFXbw=', '6EXw/mMoyaKCmgA5VLU4IQ==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 11:45:32', '2026-06-28 11:45:32'),
(1652, 1, 'https://web.push.apple.com/QC6f-qbgx_ROPl_uvPtcT8nE_Y8T-QBdma3jeQrl3ZDoEpgW6kfDj1IR8OInBYlkGMfyjMeMTacqMkaT8b4yuFu_BkhFshobS6fhq6JFEcWfiqlbdXCZS1Hq-1ax5FWacMk6JWo7vlCfgJ40RDJ8Y6g2qvvjcY_mQVWVi8ltbP4', 'BCT1oVxcYhWkCFXntuRMhGCY7nI8jbR/LvDVDaq/xIEXImUZl5PUbfYZPi3E8M7ub9nylsyhAQjGkO9h1pkQbsc=', 'yFTxXPdNeo8zZCH1Cg9Gfw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 11:45:33', '2026-06-28 11:45:33'),
(1653, 1, 'https://web.push.apple.com/QCdn0I4aL8B46Ink771aXIjfuZ3m_yzWt0gE_lLqk8LO9zST__BEkFu2u2SAN4F4M6_ZT28n-d6gATcRjWnxA6U41qfhSzYlEL8WsZ1fxKH_PFyBXNnSN_gHALm5iNaLQuQEfLtyRc-km8vUSH2OUly7vFa7HxP5RFhxjdpfBUg', 'BBFbzO0GJIB1x6EPraq1aBHaGekLA5NqFNnznlBleq6KWU1HyIIQVfGEd03FLcWZVvf7vPm/6sBfWdxGsspoT00=', 'eDpnx1Sk8f12RO9Bzv8uuw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 11:45:50', '2026-06-28 11:45:50'),
(1654, 1, 'https://web.push.apple.com/QB1cwxAUCGwc02uPHruPlu_M1SZsCGiBu0RfXxoyR0ysH4jHyAUBGYdsM_aFrNGZSS5CT5oxMdOxIhCMVVrl3mZJveqi8Zpgw-DM3KMZQkBkgIXTEQN1v_qlaM4zhpRuhJ_uAGqvcJk5AaWP9F7qnw8aTsB73ea2c64-0WxBgCQ', 'BFrfy/JTenv8p/Sz3g5KHSZQEd9D2af+ihHtZ4ExshPPFkirs1UozJLP7pRd7LFN/dIpjGGaA8t6B9V8l7yXPn4=', 'sQc0sDqj78CgvdFvfQeGoQ==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 11:45:51', '2026-06-28 11:45:51'),
(1655, 1, 'https://web.push.apple.com/QB0UKCiclujL7aZhAkjFxEnklpoaTuv2rjSS_NzPC_7RmUGyvl0qhtwWxJKNIaSY3xgeILjP-qhgnGqojCwUWYd1aT3xiGK7_hT0gCwoRz_eF0ohNzVJG6IzP0gndmXIjbcVfnrBSppknGEKNXfRlX_5QM-ivvIuyrm9QH4pjMU', 'BKqTdKHzQWmS7YIeEuUr45FtmrAQYVx+NTAeV7l2wfSosvbXC7VUtOiFjZTqii5KLEXLcJQ8GIv/mNLUpDqY/iU=', '9tD647rFLP+jP7WTfseJnQ==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 11:47:09', '2026-06-28 11:47:09'),
(1656, 1, 'https://web.push.apple.com/QCNbJWGE1ClnnThJ9Sf2BeNGrCw6V2aOFBWTA2Ff_1RdNo5Nn8M0nYTmOdajntAvLo9LbCckO_Jrpd26p5w4WphI9WJqfDn-DK3xNpvAse4KrKH9_EzZsbaMClJkQFcm_1z9vGVVAZkv-qU6dazGd_nTf7Vt2xDA7VPhGpX4EgI', 'BMx1DsXZQ4PYsHEXqnGD0R0JH8MOJ298wHyCmaT+Im12rlULwKi8nXiAGBUNgbA3EK5KqJdNBYFrj8a4bjvFDb8=', 'ZxgDMlEYI+rbIDi1Lx85mA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 11:47:10', '2026-06-28 11:47:10'),
(1657, 1, 'https://web.push.apple.com/QLBXZkdRv8a2T0pLzQ7pdOi7_op4o_o3zOKHvir_boEIEjo8gTo2hK1URJPCwm3QmzYT86I3wx6vN6XICJUjS_JmOcmsCtmzYyfGHj37DdMZbXAXmIMYQTey_2KDZObEXaLmfLKqvKqZd3rzkzFHuLFQTwsA3J2qJEJVm1i18zo', 'BH2xQ2AXvtxe8nCt82jWAuW8tFYnMW/H51yJzfObw0Y8qyzW7YfiD6TUdTjgKz3eT/hhXOJYpRlTLaQIFm6JZV8=', '1jdvuX67ZD3p+89D9viNFg==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 11:47:16', '2026-06-28 11:47:16'),
(1658, 1, 'https://web.push.apple.com/QORHBAfZgdo6ftxOcxiI_0bupjHNAsmbS605TR95gbbBUXcTmCTVXvWwmPFeRky8dsXLRqXyC8YH4NuC7RnDAXZkzGh3TeZCaB9KQH8RKDQM9KYEApSmVFu9qYw_tg3C51O6qYlUw3s0tQkMpum4QF-1Lnxm07EfnKi6rpovDMU', 'BMPvM0/AyqGcjOI5B43DRu50+EPu8wxyMHfTcRv76QlbbJJBYEMzRO19wJvk/vpAeGI0Bkr4GCcJVV1Npqp0Mzk=', 'NIfiHRPMMCfeZPrROyntyQ==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 11:47:17', '2026-06-28 11:47:17'),
(1659, 1, 'https://web.push.apple.com/QNWi8zj6Xs7Qx6KTd3ZXF0zuRuowrnasmeMfN2otUjyFdI40lC4y-US89ThQSRDo0iE4VN4-s_05QokG_lUmEp4K-oFnG_6BWP-2xPyV-Qh6GRi8lrUMGMDBF0MXX3q0uCYohlQFSn2HZFOh5GQeV-FNlDiGC5ze5Ky2ADVs3Us', 'BDsX1qTBbVZ+EeXr6bA/jmGwNKBeYrtI50KonO0PJERZebUcMczfSprsy7Q3HKctlsG7Pf0hXb+2QZY2PZi5lMg=', 'FNussRjB+2MJ61I2Dz4u0Q==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 11:47:36', '2026-06-28 11:47:36'),
(1660, 1, 'https://web.push.apple.com/QGhLDbWbJSq1jbPAO0fvmMeNtQMmeyUEnApOlWQlLVKfZBPcs6TZabked2ymJ3Q1LjOpG2Iac1qaJw80m8XdfDRs-cu0MijLKpMcji3HMWkEwGr9-GGPF_tYB-DVs-rviSNJzG73LPZVA1hmhixOy3SKzI7lFlAquksx0YCBk_A', 'BIrrBhkK5XPXUNrf3s8t6vroDr1PDYHciik7W8zCsDJbPqrkEUshFgA1SFHA2jf0dUwZTBVPjDVyzhzyfV0UaB8=', 'hqts4nfjQZ4dmCwW0GLEzg==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 11:47:37', '2026-06-28 11:47:37'),
(1661, 1, 'https://web.push.apple.com/QGpfwHt6Ww14T8iN9K5ETUgURlNUzALJP43bZ8FpysRegfC-MY1j5WzT8d2aFfBfFILpuQY9TqcGXlcEcOPhRYi5MWyWkw9wvC8QbUvum_LCdUP2wflQo8hQRVYK3AoxXOrQGnBn3RXA_dIwCXOMcv630vOElPunxw-MRzH59aw', 'BPVac0awYhi3mXxfIgD7mg8SkE4SKl27nzTwgGzCcDmZkNsMz5j3yJFq91Rg3D7FqVWPVZ9TeOIZUW212ovVDks=', 'OayV4NtzvHIp/4CP4urlqQ==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 11:47:45', '2026-06-28 11:47:45'),
(1662, 1, 'https://web.push.apple.com/QA32M7Xf4J4LmWn_C_9700J82lt_YzXlFoLgu8mIijkj1y1ywDs652hP_RDM-BUern4dVaKNdNOJpi2E8Lj9ybRhozk4SOX11Yd3-pM3FtKebX5breB07c-2m5aaWKAf77k9HFGLVHV6lhQornTzaOloOpBZlw3bPAX72GiolMA', 'BI2EDHigR5g1Yz8Kpc7nbsY7YuudVpOI1o9FOq7p+nmS0Z3Vk1h4HuTpI6csZ3L/JfiNdLVF3GO/y5uDi5uGglc=', '3BAyS+jNgZYadASS+hGx2A==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 11:47:46', '2026-06-28 11:47:46'),
(1663, 1, 'https://web.push.apple.com/QB6a7_Y8Mbh9Pd6brGU3vu5wQWGhbVa2rTILlaMIBmBFJPBqNv9WiOyww4H2ZAhHySPE6bTwayYchu78PUt5LqeEecC--xdaUYjCyKwNFWNbXVC18iuAattFWb053JmFidVbXA-0RbdcCDAsJm6s1fwe_QSRxV9-eYpSpCWyep8', 'BK6gqCdqj5d1b6mwtjf2ovHdHunWRPlc0wDAfhjqNs7T9bZXHVWswhaRS8ij7Ojmoy6XGRed6D9rQqMypcNVPU8=', 'dFsxJx0n+AUWqgv7uhb4Sg==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 11:50:12', '2026-06-28 11:50:12'),
(1664, 1, 'https://web.push.apple.com/QMfRc1eUVQ7Xrn13urfd_11_pXss6sjSanyENa-wrjoldl6YZWdfUrdXhcP2klKZQ8mRuIUnGg5DLJGMzmWEUc8TyNVZVXQ-wAI3MdqLtIaaagTTqEOwhDt8XGYQZ8AiqYgPvLoa2OIo1rrD3xo8LXAIxotZkICA6AlEBOr3MpA', 'BNMb0SHX+CtEI2Z8B4N74kEUVyf9hJVGysmmH8z5SmRSoUOHBWAii8afaFKO74qOub1eJubDw7UfEad7HvAUfbE=', '04RpJm5RKJhsCo/hQc5TJw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 11:50:13', '2026-06-28 11:50:13'),
(1665, 1, 'https://web.push.apple.com/QC1BrgbSNnQdwc4WS4ForR_4HVWs1DJ6GuykbxLxlIhWsIK5IVlBKDFXf8uRnLAKc46EiFdgknYQzb_sTY9ugFfkIHbIhs4rMxJHOsr7XGc07gfisM0ziI2bWNqXT4VU1yV7n2sEfxXeGKt6j5HZzKNWoFg6TH0J9FEabERptVg', 'BLt+EZ7AF0lUSi6YBmyBQkT8NfgakItaViwNBL/LrC8Q6wAWhMAMqfjI/uUNugSfjPXvyUaL7VuBOAMf0BSYN/g=', '3WhQI0MIY3Aaq0i1tGJo2Q==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 11:50:31', '2026-06-28 11:50:31'),
(1667, 1, 'https://web.push.apple.com/QKCYixU6LmT0ERA-LygyVOYMbhyKidntKTyU0MzRs5xyXNcDGnglC2TvIFy5fZTI70Ymjxf4kH1S5JsaLgo1acuw46NmWehxPwFKgG3svk7Q4t1Y9JHHW2goD6uFMJYjV7Pfll-6CjbUtowzUz8xyddY3EQ22ELI-vl49GkPh_g', 'BDBUzggsSykVqUZzrhVNL7FzOtKHIdZdEkSo1A3ehL+BOv6hgRtvpZJCPVFB87z1A/Xbq7HjsEhPYRb/rz9Les0=', 'nI2AK7pZQh2fqkh6VqK4tQ==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 11:50:50', '2026-06-28 11:50:50'),
(1669, 1, 'https://web.push.apple.com/QMP-dntrvU6GLP_DyR9gr8kgk7CeYNXtGnHppnEwfU_n2li8PjcTTEerS6TaS_QaLvEO0T13jOe-u1gjQyWX8OhVQfzj8C1GBgV7c-xBhBQTpu1BQ93cxiyblxNLGkiIIjgN2LK7RA4zx24ZhXZciDwb4RINFnaYD_XN0cHxucw', 'BJ12TPR6Wthw1QiVe0zOSEbu/jTyby6cqazaX2ErvSBPL4koXhoBVm8RPjsG4hN5JAJgBYb93laNSrepwd72UKQ=', '1i6nDBMhnE1hQ5gCdyjdOA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 11:51:35', '2026-06-28 11:51:35'),
(1670, 1, 'https://web.push.apple.com/QFiZ1W4rzGXM-q_TkUDTkqKDygkqdY2M88kkmQPYGqJPkzU6GzZVWB8ebSh_2YP6FONEm9n1a8PsQHWBE95us-bhV84OVpx7013-lf8SzZ4CCVhNLGifqcmFMr3reb4ZuJjpJ2FR4gjwTiS36OzH4Poi93SYLoDZu3JYqufFGc0', 'BFWzH6YcWY0VdVUJFIxjNyhMkH9ad0GqkeXDhH8HVYI3XZVlYTEpsbwOyMUKTHW6CHfoAAtgqedDOz0lhXaZ3qE=', 'LFbotnRxLJ2tY4Ujyb9tww==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 11:51:36', '2026-06-28 11:51:36'),
(1671, 1, 'https://web.push.apple.com/QBafJtmr4mUHmxnDCQ7nLvmtF_vNukJYD1U2-tshee4jvG5ptsbuaRMpthFoahFVPaB9bbx7VdL2HRL_RcxE8FQiD2ds4rPYIpbDjMCNylF_h9ND5jI4r6wN2D7Ct8h8ulIxrG97ETQXkRjZEmeSl7we2I0V4Rfm3mixu100gTE', 'BN5QiCe3SfNHWbXiRYuwh45pls2UrtwqWiCtfaXZDtgmcSlyn6AMppUZO/opygeJIKfaP4tXc19At0tJlldU8wU=', 'b0Vi8rv+D/gLpryLg//a/w==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 11:52:17', '2026-06-28 11:52:17'),
(1672, 1, 'https://web.push.apple.com/QEmJPNCRiejxrZjVeGSEOvcOCNb0m1aFQavw8zDrFO8Tce0AQZ1VoHoISXMYLbTWupjSfvtSSetA_PMpgIqymr45XxeNG2EwCY5_dBnIW2wFKe6Eu9RagBK6UDchg-Hkm0v3B6ZC885vwWMDx09g3Ep0OHROuKra8RY0rC5hDnE', 'BMPTxDBZ5LlAseM2vxQjwN8q4svWTaqNFJd8p+7SToO1faENwmqA37ewXzf6jzgwKm5q+eWw3cEomCigx6tzU3I=', 'N9pK4J85y+0UIOH3kSZPOA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 11:52:17', '2026-06-28 11:52:17'),
(1673, 1, 'https://web.push.apple.com/QIlz9ij5MZtUB__uwA3fFbvcWpdTgpZJ661npOxA0VLDRWwruUYotUl6YeyhHOp0DrT7hLuoHQipvJNDf79LGyuf9mEhxoVuwzzVFvMSqNrNSOTXHDUk-SunzLj6yvlSRo-aZZh3YzjYBxPblpYQw7VxmTpWWyPOQUewlrhl3Sk', 'BLXmtT5GplezDX/ZnPKuGrRyFyfPNvAhEqBqovlrB23KRuBYCr5T9RRBfXG9j1cpwuxzt7PXrSW0dxQNrq28zrM=', 'ICdQpYPbwsKZ3kwetLIc5Q==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 11:53:13', '2026-06-28 11:53:13'),
(1674, 1, 'https://web.push.apple.com/QFLlsHyfk7CNVTEP1m-b74N1W15Is8KI8i2Fa9Ry5xCu806Eud4DQAICjOWKJU4Hzwf58gPGvgkuv0dfaUCIljkSvITfLGKwSNfpSPUwVBIwLkkYsLFLt7vk27Y0MkKP9mZS7YOJEBKAdec-R5r5QTEwklyVDS-xQF8T4LCians', 'BJGl0lW2yYViEPAdaaunqd1UwXbc1st7UkTVstnUv7u7ef/4Ii8E4lUZPlxbu/ZRbCUi6pccStetYUzIFSljhpY=', 'EH3uidENKunc9Hk/ZVWFhQ==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 11:53:14', '2026-06-28 11:53:14'),
(1675, 1, 'https://web.push.apple.com/QCzgSPg94fPh4JKsv6mjTDdwvwoPqZKNpd2DoiaEm9nIgmMrsRaJQC3w0mGrWWNHe-5Lc-MIYYoJf_zoz4sk-dMNiNM9vlKEfYXeY59AUBZaC85HdhwTWWF9-2YJkFl-ToqLK-WipmvoflpiCa4pRtrwcvoNDrykQjdXaX-5Pro', 'BD1l1qQ21czgkNcf8aSgbaaANQhmgnThJHWRj0KfWPagqJhCqP/BzV/CVUq2WcuhjyQEoLxj9tjpFtV2gfGRsVA=', '38sI8WvKNZQN1iS6ct9L1g==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 12:56:46', '2026-06-28 12:56:46'),
(1676, 1, 'https://web.push.apple.com/QEqCdBT7wV06889Ly3zWm0uxjwOlAamugzoQgF2gNRbQW5et7uo0jJuQ0qS9Y3eCuoFW_UHyZCiL0872qKc5ShYuxO2OOOekt7dllw7yXZWC0tpoz8YHAVYIkGk00Rw7t2rLYo9LQnTjz9B1PoN5mrLBPCaZwKx93vxVXYayzUs', 'BDsfNRCLyv4DeuisIUpNuHBJBfFbAoKLyDMmYc/Z2asnluqhaemcfCsX5JBahDf1GHx8u8FpE7cQDmX0TMHJZpM=', 'AXQ/INbkD9j0xcNLCNsNPA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 12:56:47', '2026-06-28 12:56:47'),
(1681, 1, 'https://web.push.apple.com/QOCd0cA0Bw0Aj8zRG2h7iYRyYbKFXnZFJHAdcuQycu-ciQmFWEqqShGBfayRqeND-87qGAzFOa9YqhMYH2lWrvcNXFKBltKwWEZ_IaqeohJhPqjL8llABzALAQfLeqTf6M6n4CtcDCOVFMlSHOAkZAPu43CN0uZHl0NKqIbM97k', 'BA8cw2cSxfmFPBovrPb7uZTlBBj1cfhkOUvFbFMBfk2+E4a8LNjtQja3pNz8w5a9wafE8l9+3Hfi/uk3d43L/l8=', 'oi/la7SMPh8QUgAqiDVl1Q==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 13:07:36', '2026-06-28 13:07:36'),
(1689, 1, 'https://web.push.apple.com/QCqmAQlEMooQ56GQ0Otl679_3V8mVH19P4KgkKK8uwDcZUjhfoNo3OCBuyA2FPQukbYHaklT6lV8LF1UGNfpMOfiy91q0wlvqjJrcvPKMNFIXIukUIvk6gokxi0Vhhcyt3fJcwGQxLGV3yDH2u9WKtVqOtyiJR6BdAV1bYGSRSw', 'BNcxqABC6jd1gsv3stOzaNuMrsM3gLkqjF8w8AgN5vS+zYP7fRuer41quyXjHQQ7wxGzaZMUG9p1tcBd4kvyZPY=', 'h1Rx8A758c2e/YxEQswaNw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 13:34:23', '2026-06-28 13:34:23'),
(1690, 1, 'https://web.push.apple.com/QFJA-JRsOqsdRmxXDQLVY7JhhL7JyXMtK7JYmOuLtbOIMzo730c6Sa6qOkkOe1lyC9PV7sbryXEu-v1Y9BfrY47Az2voyxZBTtb4Lolfgo9XmdhgUCo6qQje_EydZJaDfmzDRSR8UG2uPAe7AxMyYzVdnfoTHyUenI65My_pyoE', 'BOsm0Ao7wVn9ZLLSUg3Nx2wMHtzr/kVk6FE4HQCce+6DoekbKlWzlK5hHFhhtzFVLq4XHXIT8T1HF+xjvwHG5aU=', 'bQbysOp2riGENtZ0R7szGg==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 13:34:24', '2026-06-28 13:34:24'),
(1691, 1, 'https://web.push.apple.com/QAFFtD6iugatjxzK0Kc8jyqPTyjnU1kgr2v-xj1YiqApBKTOajy3XzqCUXscuqqWWLJpTiQYnVkIFnlNAvAfwft2Wf44_zMqFN8XGVBb8rfClg-AKD1L-9VL0A0XIspfVbkiBsmkC8M5bgbr_AwDfle0Iozz1kCoIwfvnEvN2qo', 'BL1WZ0+CXCqea+KfBTwnHeKkQ1NMPgoDjnLKGGLsbSLMFJ2zmMoEVOJk62xlF7DVF8aRgq5u1BLKmklxOt83yKY=', 'hFwIWKeYOScoZeaxJjw6XQ==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 13:34:41', '2026-06-28 13:34:41'),
(1692, 1, 'https://web.push.apple.com/QEKYpaiZxYTUwA6Uby7taevQbKDWAbMRYAhuslYhTXauKyMwptgBh4Cte_AemLH9wbFpRSrMcEKdKl1rpqLCUakO5eZmBtg-DzvGr0TLxWkDMVoeU6w1Z7OiFI0SSB3RAIO_9skEuD3rlqkLSr_mwlTupZeyCoz9tA_hzC2JBfw', 'BN57gfi49C80a1t8Y6d8NKe5Fb5rhiSRvqMbv8EgVb9o+gZV7krRxH/n5+VL+oUEFa2Oizcr1MW1Cq3Q6BsyOgI=', 'yJW84NNR5aM4oNomh4hS/A==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 13:34:41', '2026-06-28 13:34:41'),
(1693, 1, 'https://web.push.apple.com/QP0FwvludKkK29ZSMHBH_cvqQbD75r1JnANBUlI3Zj3lHiCFZ-ZZukee8GFBZUv9L-1dCg8q_iCg9fTTedXr9aubndTz544rI-B-pJqrJykgh0K0rwDXHyjhzWlZlmrMyvOnnbWWL_s2tpP5Y2YgEHs1AKHxMzq-jRvJbbIfmQ8', 'BJA6NKAew2tDTXmmxHzWlvylIqdtA1MugkntRsSQmxjg9az/vhtsLE50JopGqHMI1Jq88SLMfo6ij6MZ//oDyNE=', 'nf1+P8D6/9WndJA0CCf2sw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 13:36:51', '2026-06-28 13:36:51'),
(1697, 1, 'https://web.push.apple.com/QP0Ld0ApWiQ7AEWitdwM-EQK2HCZrlTs1ZIYHk_5Q9Vj2MzH6OdTV9n75CGBnAuca0gusiD0UPf40jiA2AbZDyLWdeZSQdkT7FLAJkm3X8y3ILu7pTjPee3xfjYZWRa2meVo-D09WI0QOh4Vdmx-Ci7C-AraIi3BFlzPh9a_Ga8', 'BHammHNqJtswLKr65dE+fjTa8CXsvDgbSo4jsM9pJGaeWEhi6RBdEi1SJ1/xgRQ5tk033g/bq942aKjnx1OSrb8=', '37O4tMgCIuiH5sv8e2tYcA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 13:39:01', '2026-06-28 13:39:01'),
(1698, 1, 'https://web.push.apple.com/QPN8Vb-vxctAE12S2eeAp7WgsHuhCaJHhDWFCtKCptyL1BtE_iZdn-T1FrUO9aEwA2ZXCRQicw3qWaVCArD9llv-DoXqRwFwkZtma06GLBoh_cFp6gOddTA67Fx0CQYHFCxbKaW-qPXzCG5xFcifxJf0_kZGEi4SNN-dOxGM0Ck', 'BOoCAARWMJJR9ZC3gSVXB/blEzntnz0Q1wkAOivG3ddQs8M7ClXDbKfy/VDh9eHOQ4YqTulQEvJljgdhhYHQog8=', 'zy75F1JNL411j+wrAVMopQ==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 13:49:44', '2026-06-28 13:49:44'),
(1699, 1, 'https://web.push.apple.com/QD0wiHxSsva3Ja6Gop4g-ct8cjZCTV3ob-8VhwHEQl-wiDCxU5zNa_xQs4v6k7DhvCxZcLWVv1M0krmqT6k8vW-bODuDVGqvLBOR1s3zIjwL7kSWv_c-BaZbC23dZitF8hKw8Jc7rvP16y8QDdiKgoCBKIqvLpDvi2eXfOSEHJQ', 'BPF++wCDB5HU+E5Ng1814r1wrywtyp/XAhH4l25gugYmiRTEkToVXrTTl1wf4w1XzjucRhinAndtR4kcVao4eDk=', '9H4Xwx0mUWrVGsRh0fk5lA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 13:49:45', '2026-06-28 13:49:45'),
(1700, 1, 'https://web.push.apple.com/QIsHiKFeI4v8rsjdGhrHtMl19YujlZQWz75rlEtemhMdRy0vQ6FhERTzKgfFbkdWPeni51sHxQP0dpETVI_l4HYYgQ8emngIaS4GA8e--g9IRnmRB9CpZ97K_PG1se3kYc8E3eqk4JBaULEXJLhWY6CL-MLyB0OcGMbZLfd8pCY', 'BEKHPIntfzpttO/a25h30pOaxt0563csvbpj+fM8FcI+jq7n8TBfvhvea8ge9i3RsCbl05LCr6dwXwfd70420yQ=', 'nBQgPVZQGh5TFwkYAlcRnw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 13:49:54', '2026-06-28 13:49:54'),
(1701, 1, 'https://web.push.apple.com/QPMS3A795REnfcEpyt9FqVw90-1Cz6rT-FeCPHBJQjgQmIswYj45rMVBc40U1VNWq6BfjKPEJ4b2l7pRhhbmXFMue4slhKQ7QsHWZj9Ts3x5p9DzMLvZJY3kPtw7mJ6ujd-hC-zgYH-KcB3-dIR-z03UwVVhCMw0S3CXaOU9Dmc', 'BNA6MPvoxYVZ/QOikakl46yY/92j6qt1rD7UVTYbqc4OHkIqWOLivbDajWwJY0MbM+c2l30WGxlenVR0FmKsH1c=', 'OpV3K4bMe7IlewBNpFk4Qw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 13:49:55', '2026-06-28 13:49:55'),
(1706, 1, 'https://web.push.apple.com/QAJfhE40iT_BZCoDUHNGpK656iTLA78Ug6XdRiz625VlJFYXFkamIdCTEIFedxs2g08odG22CRZIp0sXTB3gRSnK_vkYWoxeNhWUWHN59BmKQNFPsvveWpARp5MlTfKfrY8bJ0RnBjYKW_6sdBoHXpOpq6bXeoCiT4Ez-2hU7K0', 'BHMoeuKzNt6Q5f4mq8TH1Ps52jqDw2Ll609VdFzpldQIMSYUOTdYQtAdGebl6Hukcc9jWD0U8DuwzhOOqSDRC9U=', 'GTLKrgsfCu25xn8fvz9bbA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 13:50:56', '2026-06-28 13:50:56'),
(1707, 1, 'https://web.push.apple.com/QHIot8Q-YNInvvP8ZlukIKK5hsOqaHzAAbj2lxj3NROcwDogRvJEpjkGRaP75UFx1CglB6rv8_Kk0waqXR-LwZbCsMhOgv-T76psl0LbUXM6WBR7mNXhI8yOBselrEJEiFQ4YbnBG9hUX7M1Z7ErDMMyTDTJ9DzOSXBNy-bzDLk', 'BHih+sT8vFIaylLQzMMQyPvo1kWi4d9xE6GsYm0m6fSw+aIWs2FbUrAdvp1CPIBo+dpIMSjFlPpFbFu+6bB5OtQ=', 'ZAECCSzuee7yZ6Xohcec5g==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 13:51:02', '2026-06-28 13:51:02'),
(1708, 1, 'https://web.push.apple.com/QHTdDJCyvx-TxbPM0itK8ma3yCoVS3Gee3IdqmQ1QVjcB7FivCugzRQgSg-W-2hK56_pGda3j7y3d1V0IV60VqzdptTi91RZTjPOrMTTbkAigQwrS4TLCo3B2hWTdSTaiaToJ-_JjEkzIYCXKJvWUfyFtaK_4QvfZ7fQ-zj99_A', 'BBvN7GREYNn7g4oQLBOpGP0g5czXAN7ZYxl9ClUBzSgNkF4qQbUoYO8aeCJqcCXzHsQPYI11XX5ds3Bw/PGtqmA=', '+RJTeFB11QFwZntxoey4hg==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 13:51:03', '2026-06-28 13:51:03'),
(1709, 1, 'https://web.push.apple.com/QGfAsYdQu39UFcGQJJuXIHZG6gZZL79722nVRBTWzDg9u_Wn1_ACnSgMR2nH_XFUf67uWtxeKHJ3dHwl_fVF3V7s_0g98-Kf9NidHQW58oy3bScPTieWnXpVHUwEEdy-obqwq_Jr01krmoQbtwRVFF64RlD8SRSBfF27SNU63Fc', 'BFK3CFkKH21wAqrxkO7frElbTYue4GBfgJd0PZzU0khsjMh9ttEjbg6YKBhLmxrFI0vYY8RBRWMP/pSiQRoVvd8=', '5erbjpcBrdWzfak4GmLoew==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 13:51:11', '2026-06-28 13:51:11'),
(1710, 1, 'https://web.push.apple.com/QIi0aJyjKhLThhcub8axhPw2otmELvDEyTiPUbJYl6k1LScMvNFtVRM_iGPeFuASxS1YI38tUbX-A33CNIogX51TiH8p-iGtlSUxRRZ4OmETaUup6Ko-3vZid-_8X9pELclDsr73l1UP5fkDLGWCecxE7evjf5l6dv9E2NzbCFo', 'BOaTZZZPaJlLFATV6zsW/pr0PEWXsaqs/v2e/RvjcAluxbpP8HRkzSEmQF3EnY4ARZgh5nTKPIrdPJH5wW3Q4fE=', 'PLJsZiSyt7PpJmiZYsPCxg==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 13:51:12', '2026-06-28 13:51:12'),
(1713, 1, 'https://web.push.apple.com/QLWiKLQh_3WheJzs2VqKw75YwWhR790RHabmTp7z1sPxMnvUFBpyU_Gi0jHvOTe5EQ5_rvyZysilzFvdywwePlWUhHC4nvCOEnm7_l64N1gqAOmkktsfG33oK4raWQiXDaMfufUwQz0taRfav21ponNaAuqeDvvq3WMpayO3Y-U', 'BPJbXS5aLvH93Vsf8bWROrZatpERJfiJoIxJT5b2v0KjeVrqLMpbmsovLX9HNjQCSVVTq1h+/z6ZpEVC8jMJIpE=', 'ZE7+6npJShAP8vsJG5ucNg==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 13:52:21', '2026-06-28 13:52:21'),
(1714, 1, 'https://web.push.apple.com/QGsEG89vDEsbV_E-eqe6KeQYV44EKSvY0voB_xFfxyIeqigKAoz-d3atp2ETRohbuDkCK2S4tJRx9ZWD8_CBUZ-ZucZ6yBop2l1GdEAR1jkS-mjSzJX3IrfDDowXSKU0usTuwkV_0rsXh34SeXC2T6cEReOWQnWhCp4V9NCd0RE', 'BEsJFrBrBzQaz6V/4uP+8x6hiRqADQIIxEHoESgOV61QU/vQBwiyf+AuKtcyuFFatN7GhKSkM97mubTthooj5NY=', 'hn2ZuBf6ZVkAd0dfPklntw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 13:52:22', '2026-06-28 13:52:22'),
(1715, 1, 'https://web.push.apple.com/QMHGiyj6J3spgBS-sXIf1Ev4K1iIFymJaf4Ao_gK2it--sXubTPon12IfaZ9k_ljCPnLT7yA2mf-DJU_GuX-H3ey63m4wIZwCvVH3MESHyNheBkHOhsGoPxZLwK1ooUC72eeynFBcKRh7iw1_kSFLKcBtkI-F79Bh1PG828xoFE', 'BPQ9Iu8nxutETue+LSnNy1fz4XqkfQw91l6pjn8oqjAYSF+bRJB+Ml856Yn41wrbo+SV3ePo4wy5416ejN894Pg=', '40uu1J3sKne0b+VYq4VICg==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 13:52:35', '2026-06-28 13:52:35'),
(1716, 1, 'https://web.push.apple.com/QG_a7X1Age8sE8TuqtvDGq0ZqSDkedfrsQlvq7JWyzWiSSM7A85C_dwL7ByjdXZ3CE76C5YkXXYBGLKjzCYAScGo9ll_FRP-vdMNgNy-scc3YqFku_VPEnDyfzZ6c5xnciM48fUITlYd3iWrXyXakyt0hhoUrz5rzIx1SNK36Fk', 'BNIVpUWH7q8rmYoCvenspOBcXc3mb0i5nPPDfVohL92lyyDxr3V2egNXOhMT6DyZEV9hG0p9ZN08GAM3U/jlAHE=', '8hvyGaTYZ6xChDuStbo9RA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 13:52:36', '2026-06-28 13:52:36'),
(1717, 1, 'https://web.push.apple.com/QKPSYxI2o6dxUN4anpH4M-wwCrLR9KF-jOGjIWTAYjlCvNaBqW-keBpKPRaLbiDxnJEpLPZC1_jDnu-4w305iA5NnY8zt24_TzVlDqHmUMss-_SlJp4VbAZuhQ5USUlqeIu6zjI4D_ZddhgE0Qoa3h64mrUzG7sg780CdvV5Ubc', 'BFTBD2y3GdteuaUrDI56+TOWc0PyI7ESDRjTxAIElh8FftGWJ6tkfMLcoaXJIbIVICjYOrGtxxBvOqVYS2jBLRE=', 'lMHlUaP+yJtzmNyXMulH+A==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 13:57:20', '2026-06-28 13:57:20'),
(1718, 1, 'https://web.push.apple.com/QMF3ZuO2p1GoOjznUnWESzTl4YySoR78pVqh6pLJFHdBRdigTC338J_gCz8jcf5bnlCTVSP6N7BEMSDUHW_zgdNgm2wFReCHwPZRa6H3OxyuO93VRUcCgAFrvSZDqBJlzQAjPCipRY7-MgcIqLfuMe6Hb7x7Na1byYtHqfFDZO4', 'BM0tCl6hQ+My9auYHw8zwDY9Ue1El+XnTQAnXkthvp0uZlA142iFoReM5UYbfGngl0gwGR8f7D+S83eMVDp2wVU=', 'o9l6fn8XoapWtX/c8uCn1A==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 13:57:21', '2026-06-28 13:57:21'),
(1739, 17, 'https://fcm.googleapis.com/fcm/send/cKQOrBYwUZg:APA91bE_Xq9pDQyJrKFLre9pea0eU6t-HvbJ8-Y_kbFhXv6wBKxud2bbuFJ2I7zV1XblJ3D-wxAtQH299zlt1PLUZgMuTKRcfL21AXOOFdKCtmpMbX3eZCzXTdY4QNcQMruRR2UhJmwJ', 'BOPQ990J9xbVZowf0jDo2S5BUf3feP3PjKFQ3AXNj6tyzZGWOfmVQcEGFszPqfMYDBzLlBoJGLurYahszKSITsA=', '6CfSmPTM49+LHoB1/sRg8g==', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36', '2026-06-28 14:30:18', '2026-06-28 14:30:18'),
(1749, 1, 'https://web.push.apple.com/QFO8qHFIzOntjUcjub3mAR88D-Ka5b467YB2suj4BcEHjGbFHl3RtXpS_qBD3A2Pn_DXqmWTy17qFlcbG3I-D_qVdM5WjxSRFbn5HXPdXT6TEEAcSKgN_GKRZKwd8Sx5yZlB9-HZ9vEjKRyZ4iEvpKAQiVpqIU9hcbUT8cgN260', 'BACdqi37aW0dJSt89csxoPa6+Mb534awLtVvCmMVGRmMP/5sWCFfiEF4nguB55bvkme3UBJ88Mw2+bbbLH1yymI=', 'ZR6kTncB7h7Y5GGSuzlOlg==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 14:36:11', '2026-06-28 14:36:11'),
(1750, 1, 'https://web.push.apple.com/QI4RKJ6k4BofAHf27gym3ZUT3uDPFkNXvuV_x_u-2jFj3qXDMgPtqb1JSIdtQ9VkDTyCFYf0rfVVVrvieESi0_OfX2aB3m5046zMlZ8Vcb2IO51DMTyJGzuDr55tvbEYFUcqVll0jKQG2BVE3uR1FKN40wrrRqH-2b9gPlDKgTA', 'BLeuq6D7RWateDV8EJTfTGvmQ7XjBaLuadfWbfBqR5v3xuf0X8ruXP3tp0MKNlgyADkYK7kjmeX7V0nAym46ZO0=', 'oFkCsRuRDAxI5/CZ90JeBg==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 14:36:54', '2026-06-28 14:36:54'),
(1751, 1, 'https://web.push.apple.com/QI24pRnK1MDc6ZuduK7DOVJFnQxTD5f3PM6QBMaVgBe9vJezGeqkMU1NFR4Yom5kmF7n4JgUgCtOEHPGaOkuRT_VsPgAeWP8kEOIgRENViGDFL399E7mPAP_dXJQHfeSO9l8uZJK67njv0Enjpmi27QGlUMr8SVqX_A6xg6wmB0', 'BLU6KceO4UbLvDN7YGpGATf/ZZqOMRFoDraxyltnZbTt9Kk7AlSORa7aQEJRi2dbVj/9S9D9Wu88J2r7c+vebCM=', '6VdKazPmQp108NfRtmqctA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 14:36:55', '2026-06-28 14:36:55'),
(1754, 1, 'https://web.push.apple.com/QCfp_W2A1hDvkRFKvqVUwxR-Fd7zcULQVP8uZxpFZcyK80sVMP0HxZRQN3JG7v8pEz2M_rpsfxFik9Ver3p3Wv4ijYvfPBHHEb49cAedbfUhz_35IcETac6p2nzfS1I7OtHbrmxhjd_4EI_A6j5CHaRPbHOcPFI-FgAcTHOJnxc', 'BCFAUIMo8CdzCrTF1x7qbIqVPJqWb8xQ8o2IUwtF7uLg71d/vvUmDV8Nb16N+RMPcBut0k5Ym96FV16tPi+mOGw=', 'QvMEn5Yp7o1aPMeoD6Mw5A==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 15:37:11', '2026-06-28 15:37:11'),
(1755, 1, 'https://web.push.apple.com/QDThVM3hn8unIIf9osBaDJK9YJ_v-XsAv3YugXNEix5K5GbqVoTVz1XDXV1kHUjB2jpLQi0s3JgPAmuYUv1VAmUJvEb-5z8I9WX1OP9ekDiqHDp2fa8IuCsbH_p9g43lb5ZP2z8oEmAUpm4h6a0sadHPXYWINkWXlCZKy3ZCJrQ', 'BI946uJu9I3lZB5KGVoBVDrXlLqU/79ap+lt9Lg4NeeV2j3XjNNgxb0dkaPwZXAJW7bx1TZrspZPyL6mF5AOJPo=', 'qZmTt+fn/LJbAEaNCWCAjw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 15:37:12', '2026-06-28 15:37:12'),
(1760, 1, 'https://web.push.apple.com/QJfXJZjRdAMOqXTL2Z2nAcg2OwaGalPYsxM83i130gIAwq0nrr3C3gKKHH8riwDLEBpMbpKnZ83HSpV9o8wpM4Vr5ISTulqmlA6gOeJdfORfDtjVrhvZ0yzAQvAnerHTHrVZyEhQt-Pr3ZOX9Mle1gYktCVxOGKDY9DkmleevKg', 'BOHrxpkXUrlD3d2ZuPXRTB7dS4KdSxgYxMzPnmxG28vwyl2TraimD/mv3f6RmY4CV2CbkCVz0JCl9N8IwKaMr+o=', 'CyQ/dRArfailb9XtpQU/bw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 15:43:06', '2026-06-28 15:43:06'),
(1761, 1, 'https://web.push.apple.com/QNXJeHEUKA0wXqdv1MPpV0rpbkawtYpbj_6AlTbb2YTuz4bKiULI6mAbBAhvX0y20-_vmIh8hjmoQcNQcv0veiAhGym70Tcs5-W15fNK7TXX1RkJQHRrMzHztFlx9LJdmpT_71mUHrpFP8_CAD47PExuZKG9gGdE2c5GvzvxcVs', 'BHO9ytMvM3osw9QJWBblonoBK8aXpkuMiNseHVTdjt7y0zjZW1ugBoXY++OLBrF3ucOMspP2u/iAjvluPRI0PKY=', 'fFtNLqsFNi+72q1MnmJAfQ==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 15:43:07', '2026-06-28 15:43:07'),
(1765, 1, 'https://web.push.apple.com/QEQMfdx2BeR2duSNfQikfjwa9Bl8Iy0tK2g_fD2nIrhDRy9nAw-hGxBoUDiIUJSvCTV2EBlsYDbVJ4JnV02mUiFcJJQatAL29M4hfo7HpOOTVvY9-VAlb-ooFo5cStm5d-IxJSm705vpolvyySUVlg-SwdbnXxmSszh-HHkNfFc', 'BNTXp32zP7RqQXLjRjI8CYl4dpmKPRMb7NDm041m0p3jNZX9LKVjnGrfPyRxZKxhK0HkUmoA6g9kMzv8sz/JkVU=', '1+lnWu0zA9PB/WPqkf4bnA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 15:58:58', '2026-06-28 15:58:58'),
(1767, 1, 'https://web.push.apple.com/QK-LyTLaCf4_xXhrgPx_mOE1f4EjY8hs9SAAEZDxKH70zP3NWG9AVUn5W-jKySjT9P0IxrYChXlg5gOCvGXLdRtM8htbF7HT1qyn0VBK7awlovthlQwonz8q1BsdZiRM11kXcGmNGhV89o1akGyMI0MbBTe29b0Fi5jjeaziaxA', 'BI0dZrsUiB6d0dvDY6n7S5EkAQGVwtNjvDNOTHxIR/Aztqp6V6rKN48YS5Zq/yTs4f/6BoJzXLNXOorW5xMgC/U=', 'r0uefOHFxbdzQWK3d+O/HQ==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 15:59:03', '2026-06-28 15:59:03'),
(1768, 1, 'https://web.push.apple.com/QNqEZsggN63r5oKM98izM5cu4KHy_-czr05WewFcRe0rfAZMU6LQEPgsk6a5IuWoKgs0e-k7tO-CUkw5yx0-THYLzQP29eUqZRcumfUjHN1RtOxPDLPlML_yvkL2T9AXyrky7cI8l7yxXiofQSRgJYizqytHzjYrIJfYqxSrekI', 'BOz5dHagOqUN93tB/9cIhxGbY8dzXYLRRhNLzbroAsIpacA8vL2KthOzYBiXwW2zuRagQDBI8YdF6INw6fFkodU=', 'vi/7qY3sTkKfiZm1MfMKZA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 15:59:04', '2026-06-28 15:59:04'),
(1783, 1, 'https://web.push.apple.com/QI9mVu57aTyYQAXs_fvys6mNJtRv9xUhAE57UNkZ9XfMd9wlYe_tKJY0R2_EWR1nZLQjjj8QcA1WMdXKiF_4piU3NI5lia79N8ryPKyjZmEZNKsLQg39kXnzpAhZDCGZJJrBZZ0IA5O-Z_djzW1CHwFUiArCFSiDJrQga4D6sQw', 'BDJ6tIw1+UaKDlWOqtu7IgIgx7xD69kKev7LD8g6+dOdhV1ZFmlGJVW5UZ2yclYYUjsoLl3hkW15LGQoNVZHLGI=', 'rUav3ur0nB0akqHwb/qrlA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 16:21:23', '2026-06-28 16:21:23'),
(1785, 1, 'https://web.push.apple.com/QDasBHyA0Cxu8sVGquhLJ0s86-p-3H78Mc3ANlpnxxJB49WPM38UFbKSYKQiAjL6qu0enedKKGKXoZkwtKYHcrwSF15v5rpYiZMx1beoJIdRs7y8qVS-f-IfbBVRWX-yCYJMRhY56dHcRsAAo2DyyUkZpD2WVovyuu8Z9rzZm5g', 'BJz4URLc7uaotf7O7FkLhOcSewxxH8OOE3CbkOP1U+M/cqj1C7yrPanWhlPDEyHZddXKfkx4XIGdmL+o0bDyRsg=', 'PjEHLMOqRpZfHFZyJN2YDw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 16:21:42', '2026-06-28 16:21:42'),
(1786, 1, 'https://web.push.apple.com/QF60SpzEyBwVFkFo9zkj3eWbUZtEZ8cAEfd9v4ewLhfUxQDGYllDYseHJ7lRw8Wga603czzkbjDtMYMPW5v4rBraKCor3mIMqSF55S4gwn1YqsyzIwMgxCMqD3N2a3kkCZN6U22bVt1wX5yfRUUWfX9_mKDFkFWQN6VUPpWYi7g', 'BAsaPrNmeOeLUMZg8TNuePyoEB9beT/i1rvV9UdcdQhZfI5rp1ETvTv4dVXTHu2TGN/NjiEZczJxmhomAHrg/ss=', '731xOYvFbqfkGdTHIDfcXA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 16:21:45', '2026-06-28 16:21:45'),
(1787, 1, 'https://web.push.apple.com/QEU3jmQMBm9u7-MMEJmeLP-pwL2XZoQnEB4vKlZ2l7n3L24kn1wXLfsOnGs-6CExSmosgm-6wEeCF409XsR9s2TEyyyNItikoXDT_I-p4Tzr8YAFcqPHA670UwdkifECx_7p5fUwrBseL-c7GOB6rBuDqxbZAzPWKDiDI-6mRQk', 'BF1DC63+L0/hFssKbeABRj8ACztiCq+x0Z9FQHkHENqL0CLs7HDKbCW5SK3+lVJ9oHgBcE/p48eOCKE880zsPYg=', 's1eXToI4Vo28epBPwrysFw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 16:21:46', '2026-06-28 16:21:46'),
(1788, 1, 'https://web.push.apple.com/QK4rhOvND8cIe_MrNswmGRKy61g3G_nDzVwJ4k7XhFXimURJCPYZw3B9WCSn3bMQc0KdXFyEjxBs5pEksrB8DlVWMFeSTN1KqPjbql3U6rBGlpTyvF5Dpm2xsj60dbxjtSL3voGpWaedvFV4cIMqaY9kMCdy0UztLKDEjyYQfug', 'BOxM4NKnOS7p+w8QQPbU+I3ijwfhjBrIjTVy9C7xi2pmHFpHgVQToSaktuFTEarzqZHpJIsIbakiXJL+szAVNnQ=', 'wCXCGPOnGOAJR8LkcLJglQ==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 16:22:31', '2026-06-28 16:22:31'),
(1789, 1, 'https://web.push.apple.com/QKaKDnFDH5ax-le1ZV8cBziHWNZRmSPQLCnvA2sIMpAeupl0dhes-2LTHxnet6sWo89eXlYqyA0Ovb9brvK4byTWsh2O2242Yr6UJ49oQw-Xjvyrr5_UPLPkfIlMSWYBWcCpPy7YB3xxRXOLey5oblnwSIgYIdBYTnAALGyLT6Q', 'BOgoITOtdUThcTFj6rAB1B12aVnzZtKRMglgHOkln/cMQiU4h/XV67iM647Q20CUsaJcp2vZL6S5cfBdHb2VhCI=', 'Jl7iboG6BjdMo8pb2N2feA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 16:22:32', '2026-06-28 16:22:32'),
(1790, 1, 'https://web.push.apple.com/QJXQAJKZyprgBLDNqUkwPcIY5TiI8Ows5rMR1vuKjxpev9UxTGKhXbPWX94P9yZFw7q3r6Pg-CScNE3w4dkoSragPSpYME0AL1aeUnnIioQI82184jF6BnNHGAjWtMPB77EvL_fqTrhM4MgpCAHKP5IgZiiCAtyb_n36xEM4Ay4', 'BEtCZTYX3pS5gUcBZr4H5bF3g1lDJ2LxzSnp2ka3mh7hdQKJeATJtfqSgSGb8RIJrFDw8S2RK3bsowjXnMpcx58=', 'XSpxGvJmpFdQ/8kcI+C7vA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 16:23:10', '2026-06-28 16:23:10'),
(1791, 1, 'https://web.push.apple.com/QLXXGl0C5ILvifnpihVIsHd9TNgo6rd0NZxTN2YdnLlcsTQuE8nw8qgs9sU3PQOWK_SDzBDxQj-Vne7z6-dvVDBpHbxrg-ENYdLMTllPE5ppCZLgj28a31DCZahKszAfqHWrmJUdEUly7l00gSnEzOSSStjPA1OghqC4_MD4yA8', 'BMpEX052A8jsriqzMvY2fFT4+Zlr3JKBsSc4RPLupKCv67UV3MFvK6RC1Ie9/9YhHnZgi14aDzPA6lJORk5SIOc=', 'rwfmAw+8+oxOdeDEo15v0A==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 16:23:11', '2026-06-28 16:23:11'),
(1792, 1, 'https://web.push.apple.com/QHzWNbW8g9wksHbppahPASQL2PJBojavcRU7HCv9kUba-1TMumQRhKnKmVBTYGFrnTh53GfU964vg_OjtL6QI5QjqXc_vuJBVGVYLeSGLUyC_LrMKxtPAp6uykBVVK_2RnLCbhBIRGJynqwL4NtWSUB170puxFgD72YcuPgWo60', 'BBkmyRmVv4+af1V1jipjyip/UOVNr/WKHgNtQ8zKiSa36TkB0OT2hhCO3/afP54svlpgdeog8luA16GrG8IW38Q=', 'cDC3m7086c6HuK+joAJNTw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 16:23:15', '2026-06-28 16:23:15'),
(1793, 1, 'https://web.push.apple.com/QIIRxtxZV_Knypa-XoDg_EToippWdgz4OAFa_W5kHkrMHCNBpire-kaP0c8u1ZLroU0gdw4bKqfNXGZLGBEp9MQJSFLPclXoCt1MQTFl0gntcCcIJAKKL_NvJiwBDBWZ4m2CJZCcCBPWe5f2DibLVRHY0mu-XmJoUoKsmO4fj3o', 'BPy5flvV+8PY3oFEYonK5c3IceKaVuKL9uINaqYk9dqE8Z/akv5GTImB27Bjzg5QT4UZl9wpOdB1WClfbfkXUy8=', 'JIih2p2ff9HPU8vEpVFPTA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 16:23:16', '2026-06-28 16:23:16'),
(1796, 1, 'https://web.push.apple.com/QI-ICDm6A3ubuhqYht2B8QulNGErVWGApm1zxGB6xbMiQm_CUvn4huxcAHNExTpYAOIJ1vBVXwZwBXrhsOIbRCsL2r5Hbc1PKRHvwx-ufKbLStD08C5XjF4wHILH0bydPF7j53F-k5L9IuBRCHiHTEMpdt4LsK86ZmVig9m-6KY', 'BGlQbYjRRrI9d4myVhlJ2LLdUBjNwm2f8CY97yIz/ba03S1D75uEqO4PLnvuGigy7au+kU9EBj7os56dVy2cylQ=', 'lVcQmx0u8WomaxO9c+9QgQ==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 16:23:53', '2026-06-28 16:23:53');
INSERT INTO `push_subscriptions` (`id`, `user_id`, `endpoint`, `p256dh`, `auth`, `user_agent`, `created_at`, `updated_at`) VALUES
(1797, 1, 'https://web.push.apple.com/QN5RBmRoVa-3lcX5exBnTFssIi0J1ucyqR_0_miQh4NcFD-6yIj70aQ0lJJN7ATiS3m7vwRxY0v42HwYYWsxElY1Bu4ratlI7FMBJMWYfiKaUUPDcNl9gqJvRF2PDSWLz6lKjiqhdSV19PDe8nBWhp2vGIm7-313UZO7yUaPVC0', 'BIvNMjwTiMLxOR+asASv/a/VKgE7HmnTtYeS/tH9gRI2Z+SKqwzLWV5eKgncyl1uNtfl8CtdkF9nYzjwbFJgh4M=', '49ADS+wIoeSt3SJTPReTLQ==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 16:23:56', '2026-06-28 16:23:56'),
(1798, 1, 'https://web.push.apple.com/QId5hj0tlZh_8Xv8sM7Ov6csf-V3twXlA_Vy-2u3wZsTGjTCyiSE1yZJh6SX45KsRn-ESCU4hhBbE1D64QwiMATp15LzFHe7rllRppYvbEeFwA2e-ou7319ukCtf7SPEgAcS_tDCL3qhEVbHgX0K8WdMb9g27X8di45YAs13hyQ', 'BBOsQ2erNCTN1eCTTB/ZfBd3KWp2diy9Kl4Yx2YkADM94yRicF0JdwNfN9rSWdgc9Un9rQjiGy82mY+V8sqZorI=', 'vwB8vQFiHOR/dRlOTxU4UA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 16:23:57', '2026-06-28 16:23:57'),
(1800, 1, 'https://wns2-am3p.notify.windows.com/w/?token=BQYAAAAwpcl5aZR0ZLtpuY7qkbQEGcPLRANZh%2bTq%2fW%2bOm%2b%2f1SbZJDglqz%2bEpcEGVSjfHJhHi40QVXi5hoMFKCTrzFN2FH5csj%2fWz2uVr0vm15g3HhsD0Tb9FJE%2bCjyQrzrkNPueW69nKRyhnkF0vgbLL3LTCAB5aYHGqSQtfOHBuN4yPIbB6obFsi8mVZdU%2boTK631l4HfgCMRGRHXFlOAK4zwBYLYjjry8GCdkPlRUkZaVujLMxd8PaxBNfBDe07et1Mpawv%2fryq9%2bZnhvIene7kYWPzlbsIzHyYp8ugpZ3cM15KeJVk%2bTpyNvhfgBlcgBNNe85GCXcEzWCAF154K%2bRKXfI', 'BBxc+7qTPmazVEAvY0gtTaXbt61BD8yavDbc25TP1QZ4X18H05eBR159UYb68lOky/0OxbtdQ1QCw8bnqIlvm9Q=', 'HNeqxSVLn2EtN81tz40Hrw==', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0', '2026-06-28 16:24:03', '2026-06-28 16:24:03'),
(1803, 1, 'https://web.push.apple.com/QLWLQmV32MJUfLkSZn4NT_O2dpfez3DoCLbNOMtFQDtHs5hq8FsJF5M5OYaKuv_NQ6aKYA1wlsYkZJrsZTD6SuwW52rQVsf9K0KqiRhyTXlFf6xM-PnmkXgq36xPqHYgkf45GNbT8GlvDMO8zGpjvQm1-c-MPppN1McGLs1gKtM', 'BMGyusWyRfc0nFuaAXZHJp9v1YrHZYRWusq7M0jGwJmqe2L/9PhNxVzIIHVTJSoy/ipeCL1gjSB7R8Ityn/+1l4=', 'qVkQnPq5jfE8MFeCmAD8/A==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 16:33:30', '2026-06-28 16:33:30'),
(1804, 1, 'https://web.push.apple.com/QDui5JFmIMdcpOj0by0nlUW58LbVsvSJO3qw8xPffoCkgoxLpE03X7V3Z7_E5Rq9OPPz_NamKzW-aFXGhy8OvUw2dvbsuVCEH9QLm8xtWFYAxtqYqIAORURHaSwvwxZ4pYWzi1IQ639V9973hde_FWYJHvbAN3twhrqyJyvl5po', 'BB6G1HqhehKRVz+c5m6EuEtMiau2tQ5S+ihQIhMKSohO9Xn2kpk/g6fEXug8kci+i07XtP43f/nGChw4yTimg/s=', '52bjisZ/D+KtN463Fmndjw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 16:33:30', '2026-06-28 16:33:30'),
(1805, 1, 'https://web.push.apple.com/QJ-g71Hof9GzNPrAhPVOe0jG2Ybb9GRPXfspAgWe0uo-QZcUMm8mzxMftmq50xxcdy_0xY89KGTORzkiZTCJRYKbfIWj4WMQhfD9hHnf84H8xSfkzbzbEmXg4KMKlJY7zWROtNY-XPdRtrPjsNmrvGRwbQUU101HMVoaAWLDufM', 'BBm748+reUbnbobwqA/9hZpwnPkO5qssPr/IWUWsoByzzbIIwghGWfrVsZ1EZ4ItO3KQL/sYGQBsg6+K8GTdpDQ=', '8WkojmdoUGvRNVsa6DOLIQ==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 16:33:41', '2026-06-28 16:33:41'),
(1806, 1, 'https://web.push.apple.com/QMtV7U-QGyjUP8M5fzKPK8EMC6TR9gdD63xm2-hSS_TZgSnDPkc1Z11GtJv19-rZtZHKroqHSypreRo531LO1GwH6-Sz_glpCZKJW9JW9cRPlhD_cY94mGxQnYiKVDRyI3S9Wg3txnQnfRcecJTxvNztClNd2Lu4-PlEgMFqqbw', 'BKt06tmd6w+dg6/pxp6Znq38QgrNiGysiMQ1wVCwte4XEQrL96MsQy7WjlfCen/ohXCtBCTwBb2Aq3TdyxqNIVc=', '+iNFLsuYgRuTGDIuIwZhKg==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 16:33:42', '2026-06-28 16:33:42'),
(1807, 1, 'https://web.push.apple.com/QPvJLcBbF1VmPI5V20D0TNBr0KbeTqb35m0wc0gq8Z-Fc1ADdj5bSiiqI1833bcch6KAywXgsFnsUQiw92HhcUK38Ja70MbFaF9rqtzPXEDE5F_HGc4AHWG6wY02CVEmdpl2l5AKxJFs9qPVy-ONzK-QoE4sC9UE0K84eOmsn9M', 'BIUT+KqtWezW9sZ4tdVxI8CksW/mfDPvXY3L4ZUVg8nMVwI1tJQ0jcoMbyrP2l6zA9goNl8UqAyGVZCi47WV1Hs=', '534AlfQV/bsiLXAIARcdoA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 16:35:54', '2026-06-28 16:35:54'),
(1808, 1, 'https://web.push.apple.com/QBAFVrmIs5I_zAOSQ1gnc46BSngRePy3q6gnP7vx7VHEmjD7GRgDsQMbOuIM5lzz2JlJ5KJNRgMTlE_QUZht3VcDKf0oegGpC2-3OX4f3Rne-iaG1rqDJns4C6BY16YR0yXnXaHaNwARqO3OQ7GNsTTGnzx7rQRC4bEJ7INZffk', 'BNKjqEM9pGU61oHf7IJLb7UylIRDCPWn5L/U/7XAJi+VCJ+GyY5mLjiwMbokttpzrU7H+a6yRIkGtDxmftUIj8Y=', 'tN7tK+DuM5oweCw8FRD6xA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 16:35:55', '2026-06-28 16:35:55'),
(1809, 1, 'https://web.push.apple.com/QCyAPWOo3JVKpZQoy6jM6X9-RgqXp0HC5At6e2osQoK6Y0ksNIGgcEALs0OR-5MeYNo8NGGccEUhOjlm8k2VkLaHDTKd7arXeSkde85dm-rGwVbWeF57v9yEywkt_oOqFwJYP7iMHb3zC_CnrdgwSgdz4t_hepPCtUotk3nGvjE', 'BN+oJqBLXdxa9yJkgsid3jhzTnrOdHAZ/wV+/PR5HcEd95kYAcSGubaZMD0eYTNISt0/FPl0I44tz4nrzI15KO0=', '3zicld+bdR5Hh6wBPQJOgQ==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 16:40:20', '2026-06-28 16:40:20'),
(1810, 1, 'https://web.push.apple.com/QHkwrnQkdj7rm41TvRWpjjAYYEMe9h06ntPNOatMzQVmmuyBLzmQ2HG2ErLhTYu3KpfpXUMHcHUA8LiDfYR51ELk9_E5EwaUiczaDXbSa1it-quOtlByjZ3vfJwaUXMjFACCaWD8ew6P72beeZ9huLzi0HRgadmEiOu0vflGwMM', 'BAy4++mfHIeyD5dQF6m8AmKpGHzJApURdiInlPq1XTiqMAUTyXfAClVNb4O6iL4oEkNX2Tnrq1sIKnmFy7xBID0=', 'M/jxauj1gIsiT3dhng+3yw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 16:40:50', '2026-06-28 16:40:50'),
(1811, 1, 'https://web.push.apple.com/QJvB5krV81kTCGzFdhkvCXxcHf2VyH-0q6Q7SZpUWb9aSUiYbmmvl7tfd644hVWvfbcaDvK1IJ5oh0Stqt7LMH4ecS40bOCs3WcUppnn1Pf_80td86yxx6naxy5khBYVYLY2IcQ0lRHcZGJSi2z-StkhYwFq4C5g4d7sc6LnukQ', 'BOMhYGoiyBXJ6m9+/4dqVbhD5DwZMOHbQsMuCwYfhXWWW03Js2brqf7Gu+PgRbagRzChajspfg7kRFXMjqtiJHI=', 'DkWc0oEqa/AcNdeHhyOxfQ==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 16:41:09', '2026-06-28 16:41:09'),
(1812, 1, 'https://web.push.apple.com/QN507niNu0_O2_T8sA4EMoqcPHNJ-mQERSpG0mvr0zot4JqAgJgulW7eTtWJL-obLNQ3kw3ZO_p7xk0ytxI5yMCs3HJL65uuoTn75SBeBgaXefDsgsoG2MXv_A-mpRsRlPBA2dGr17bBbuGl9on_2KcoRdPALVh9uurZoAkQfMk', 'BJJhUSlBIVGBiA29K3ZkNeOgJ4Pv0P2IyMLPPkvYZ5k0rji7njPvtbDv0nPMPED619KCtsNjEpP3xV4U+Nc3GOs=', 'BMygD7sIcgcDaTMLEFfMUg==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 16:42:28', '2026-06-28 16:42:28'),
(1813, 1, 'https://web.push.apple.com/QLIgO5Px6c-gMnjuNJ0Qk84wsa-UcNKEcC1FYkhgFJX8KYf2aRvfvUPfdSsnH-sogQGLUy9FSEcFrgT7M_RkVdrdn_6io7Iz4F8augDux3ThUq1OE3b3OO4S_1fSG8edY3PvHUVLu27hwPNLJRHP9v5sh8rPtr8F7XRMeYbkIpM', 'BNCKzaq+x0v8AUEu+9cJmBEBAi5GK3yhXs7aryPpNiTWtxJHh7kGoZNci3w4GuVXtVJXKwqGeTEsq1VB75cYX7o=', 'NHLCdmdHEIZY1PfHC+nQPQ==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 16:43:00', '2026-06-28 16:43:00'),
(1814, 1, 'https://web.push.apple.com/QINgGAy3LVgt6Hm-iN4o_guVc_wdfhSPzRWcxSH0FwftEyMWBurdDNKkhR9Jq4kIzNKQUr9FaeCFJmrabx0cwwAzNLTAXF6KYULjclk3B44oYtwMK6ktU41HqXQDGozzm4UBzyspvYu-tA2yG7QAZa3EUKSP9_PzGlnSbnycfzg', 'BFUQkA8Xvg2qW9bdOjn/cKGyR0VaUO6Gkepk6+2Qu6HcLUF1QCULeu8w2BeDyz6pI9g5SoTRluVHmcxFzkOGvMQ=', 'Wj7wtKUoqx7ZtW3LNHSAcA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 16:43:27', '2026-06-28 16:43:27'),
(1815, 1, 'https://web.push.apple.com/QF2vaUJeEsWEpQnQMJt4NWzQb_Qx_aGtn_4-zH2eWK2grSIL3JUk4ocOV3_VblGQlXYjJYk4gQCYSBKOh8LWSqIZz6QrMozaQJ9ZiOX5QYNUWhW8DutBAdf-oeluZsyB7OmvyNH1kuV_f98lo90rWcrj2hcHl3Z_grKfdcSHuuA', 'BDOy37iffAgwFqLvmR/4pGjGybhUOMKq913Dvh+7wIHaO+qe9f2Jt2AQNVkUXGdOkzgw/AwSXxIp3Fsb8NA16Vg=', 'P0KlfkLmHAfj57jhCyeO1A==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 16:43:45', '2026-06-28 16:43:45'),
(1816, 1, 'https://web.push.apple.com/QHxLh0Er8SxMQu6DKF9js-bLqKe-zUpF6ms1OOePYEasOtecsee0TvbQaFYlntxSkAFNLTNyJLjcOyCBZ2vLm-P_R7Xmh0T9H0S1Imq81orrUOFX4Oy-onsevCqCSgpYy51TMwPRSJGg-i09tV6XhgQhOv1jzqYfhpHM-z4BOcc', 'BJKwR2pbf468iVfMQYrQG6iGb/b8Cwik2JJ5NSUCpT4swiJ8zoRtCRd38RVauAQNCoi68tFpj4Qohm28F7P3keY=', 'TPdhMUEHU0dPYKk+N6qbeg==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 18:27:50', '2026-06-28 18:27:50'),
(1817, 1, 'https://web.push.apple.com/QA-jFYmeviM7WqpIzeTbtwVGgUhImWhlxLHCjYvytwEmmDVqvTdLMj7dWsVr28qJVaADAoJbGPiWap7IdsDVka6ySf4rxVJ5MBfQHygD4ggEirLXKMQDRvEk3dUsaX8PxSwp-R06XiL3R2h08-pvx5WHNtGSzkrPQLq7PSJlivI', 'BDqEvllIEFjtI3e+HWRZPJRFDBC9qZIiU94iTfXEO1OprUvF3w64h3/hpeN3wKqpesMWuoIaJJ+KMbkJsjcjF+I=', '+26KptqEo+oE/d2ydFN4uA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 19:11:51', '2026-06-28 19:11:51'),
(1819, 1, 'https://web.push.apple.com/QJC1RBmc-dp-KXj3WuRuEudfPa1h41i7APumEpi3WSoyGpujjeccs4JcTlSx7ySS5-9hfGaYWW0PG-p5u5S-TeqIs97dn82UzrPhLO1JuRxen8f3ZUZtI1jdylgLImVNiagSaqJRn-DsAToTZfnuWoT1r1sep-HTjc5svHf2T5M', 'BDo8KFosOkIRuvd0fateIh1Ip5CyFHK2q5eH2gYuDT+IUcVjQTpzwZVnzmJFJ9waBawleBV1uS8/Q1zkwf8nh7c=', 'V6F6GEEbfsWHTMh5XiEqsA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 19:11:59', '2026-06-28 19:11:59'),
(1828, 1, 'https://web.push.apple.com/QNfg59W_srML_A9w0eyGQUbzgMpNhaVgbKLhkkgmFHdWKoOSDyAlVp9OjPx_FxpHaEvEEw1gMC44R7Ah5SmgHhtoceHImsw3yEuDeHB-YKjEVg46PftqA_bS79D634J4IJnJDhxA7MtQSVQZ_aMKoRV7KTRr9EtShxlOVX564vo', 'BLKZ6+8F/tKuOd+72VvphmAfoZs487UEJpJSOGBWUJeLhtz+eioe2Q62cF4lTYhvcICdhZoO9zfkvGKhJmVPfFU=', 'nIdS1g9zwxRq3y5cmNPrnw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 23:03:46', '2026-06-28 23:03:46'),
(1829, 1, 'https://web.push.apple.com/QG6IgOwPbs77DY-8f5x6ye-DJGTUEzE7J1DqUmXYlT9RoDRHooQJc1Ey6_zeWQDpPWeTWakBdqhk55_VWBU6UhlCHwaXXkDW0vxdt9ID3c1PfXjW4Paj9AkL1COSLVIMNpr5p4mdS7bQKILlEDfaafMkXa3DGFBaoKdeSThcpYk', 'BEHYz465VC0t401LoHMzFVTJvu6q//0fevgcv1wEDiHtl3lUdObOERRHCpWNnmbamsdowoXnX4Tx/r2+j2Oisv0=', 'Otdz6JpwnYp09Sc0WyqXnw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 23:03:46', '2026-06-28 23:03:46'),
(1830, 1, 'https://web.push.apple.com/QJfD3_pniM_sZw_oxvokTlOECDZ-Iubov5OdVU35coA5OFy6Hxi_Xq3QB6JyxPElLvUDaptYCZt8s1RWtd38WRaQvCL4aXdlfJH0LMOyH-vZrKDpS3-7O3NnmelSH17ouUlciujYQGVYvoHpOPNCtTGwUNC8LDIvNRoyMunocSA', 'BI5xAp7iHYowVpLevqIJvvo+WXK3EQSoM65lIVSWAX+G/WDH0HQSXSdaNrM/iUFzGMLjTrEUq0EssiKr4/Lv0O0=', 'Nt2gxr5rLqTsmRxgKGNE4w==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 23:03:54', '2026-06-28 23:03:54'),
(1831, 1, 'https://web.push.apple.com/QLIo2mZDK_0VrF4I_A7UTjGdi8a3_P-LlphWDryBIo31_VbLVuvvLxtF4bcsk6G0srEhhG6Ti3qq7YXhd877WJT0sDpbfeUg-BNgyvnU9cWd02A3WEjDTKvL90FgFvZAjLTVkTQAvx5voIag5_OhvqgdR80lLdEZa-wR839BCRs', 'BCm2+7YIAb74qbI7WlLEyJUHHmomRgixl88fgUywaplK9qwgc63lAiy3DIv5h0DWze/XGD81lPansi13wbTrb/8=', 'F0K+CZDmmEgy4yf+E/IOVw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 23:03:55', '2026-06-28 23:03:55'),
(1832, 1, 'https://web.push.apple.com/QP2zAYw6_u3m7Hw2cBF_cePzocvtQp7y9uUIX9QZFUgMJfO5ChID2eVqTJVkgAjnRv6tukKdl0ZwJAIGjbEZ8Y9TdiTY88065c7sEP1YxQODyoFXXQdNT8LXlH-RnS0GvEXZk61O5sUdbqOXT8mOlEDs_ssgz5fqa-Ctr0JK1BY', 'BNfvr9jkroCDgWzV87dzVeePa/7AM+7grQtZsa6NCNxVpnCZP5b9RJ0ymXqKR60QCvzfvPTyaNENbhBaS93M6Ts=', 'AYMFGNyLWGSL6Uk85awDfg==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 23:20:48', '2026-06-28 23:20:48'),
(1834, 1, 'https://web.push.apple.com/QMCIMGkb7MSBI_CrsT6KuWg5Aj8X57t1u6I_K4-W3u1Ao6dSIeviOy-DUp7Q69THyF8BhW79EuV9Nvmg6uA_NDiPretHvcTx11m12xNM68OIOfX-Ov0x01_0ytzHpTHuqsHvvaHkxJ_Ydaivau_-5tDLXeW-3SCC_Xejm7O26OU', 'BDksipNg8UgqN44h7fatz1OGh3zhztf98+61hdiTCDfnHMzKcPlr98AMKIOqg1GRaSDkp9mbR33G4B+9z9CrAHo=', 'WvLBW1OQ+D3XPKTWZu67CA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 23:21:29', '2026-06-28 23:21:29'),
(1836, 1, 'https://web.push.apple.com/QNMP27nwGfprnbJ098LAUb7ZzagIwxW8gtft_fnlP_cad2aRp89jkB-4c7UGmaj6uEAmBIKqa6JtS6lUJXQVuz6HT0kWJ1A0beq5C751HDNopm47Df7FAPuArrhZQrpap6PoBw8Bc00_1fWBvPgkF-UxoEUBgVwUmdfvvtivj_c', 'BMbBMhvcNn0tZPGPvXvZfkjjC6qV1FNmTn51DaM5yeVtuSuOMCxXc2AfkGyo/msYnAzt8S0LFMxvwRnZgK1MEUw=', 'V9cnGS+M4RXam0T3+8WzxA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 23:21:34', '2026-06-28 23:21:34'),
(1837, 1, 'https://web.push.apple.com/QItG0VKdmTc0rtT56YQRDJnS9q8entqOeRPyI6IpBT7pc0pOQXU4Z5bTV2gRaw0PMY0N8yUTogro8mLBBUwckyzUMQgY6gZwJHPqj6raLXIWLPK8Hn0hvWuloTlS_bIaZ1go985DKEESiogRQFa29mj92Yk1P7PX4-pTrD_DvY4', 'BAmOBqlG8+wpGb3vgjSFO5x6BkP5gIHAxZuXx2gfI3nUeMlEn5Q3mEWwUBKuszNtL8Uj81MZl06V/iRwdZKUZXs=', 'c9QZEVh7k0WcXWU3qDdB9w==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 23:21:35', '2026-06-28 23:21:35'),
(1840, 1, 'https://web.push.apple.com/QPPtj05Vt8a2lCIuhbhp1I0SzijWxE0xopLD6--EiVx9qvN2NuXc0_p_eC6XvKkOa0dnGIVqyLJAhavuk3Yz_HwPsQgc2Lv37BnchrB5Ssf6kgZ6v2JzpzqFg9d2XA9HMtbaw6cM6aLuvtzDzlSKa13GLNfSDlXKr_vjzr4ucSU', 'BIMhEm9svPhSW2FygiCW9NWhTsyp9A8UsdhPDxdfYfqHuDbGfI5weHg9fqQhnBC4/hcKNzho/pYB980XJ7JH9BI=', '3MmITPa0m5+tXbHgNEmB8g==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 23:31:46', '2026-06-28 23:31:46'),
(1841, 1, 'https://web.push.apple.com/QLh2GlCXOM8mXHX33T7rhG3oWpfY1BLZ7VFhdJDY-up9pROVtIbWvUDvOa-rIMVli6gvv49HXds8bgsEJP3tVF8Q4oaWEtGgo2wBQjNmiJrQFplAGbC_U0WKSv9UhYEw9XA0GUx5lFlwbq_NSXyPDOHcQ3jz_xju4ycqfopXjxs', 'BA0FlwTIh//nrOirHmHurgb3sjHpdxSDJABVSOYxlw/DHWDdylWiPYiwJ8RsleKXRGV0+Xtxrt82eg6dEzvNntU=', '3/TYc0wN+ybeyt0ExAkYBA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 23:31:47', '2026-06-28 23:31:47'),
(1842, 1, 'https://web.push.apple.com/QDgT4sXjmWRLGXoeYmx4IjBU2WXnus2l8uoa0qzIhQzowQty0qXrO1725c48fklSDa-SrOQu953C_fOqt_bsmMBlUfjwILr9HLrsfzhrchPlFr7DO_qJDyNMfttPnstHslkxFzi0iiw5Xp2nxcgEsX8MBaPzurib1npaZG7_pLo', 'BADoQ9AxkV3241RBcBgMGdbafeYco4dYsmhi4661RE0GS6xjm8JE9ZaPNnLMUzxgPZy+w6o5eILHCA21lMldRmo=', 'X99Rkp4uS5E6cF8LPUjDvg==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 23:31:54', '2026-06-28 23:31:54'),
(1843, 1, 'https://web.push.apple.com/QEykKxDLvRKIsJxLh8Ye5XMF2GWrw9ovUlrEeuKUAS4SCdXq-KFU0sWlTvoy_7gVlhNBlmhfqN1ReMDKj0rKYsRljMFfpvW_e2z8JNr8CZce36afx06XlR0D3BHo6g5w79f2GEGEX43apLWQSSvPJorO6jaRZehqwoitH3LWpr0', 'BIk5CYz+xxCSmkz8BD3S5/zWdnT5/MQc5h1RgL5YSamxhh4C4CUJmLJo2/BPX0Mf5kcwgA7+1KBfdJrPrExedCc=', 'LmtuN86KoHXLKCjE2BgipA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 23:31:55', '2026-06-28 23:31:55'),
(1844, 1, 'https://web.push.apple.com/QOkEfjSURxZ2GA15Gz8B8z41TUUfYOKXdkn3XKGyZlqFlUQ13_PI_k3ErUzk2j-OBPW4jKaijoLBluQfWMi1rWhP_O5EzoN_ftrKKkB9Szt2rch3vKpMgDswvKIHx20eFHqvtoQrFeN6eS1AEeA0HmcVE7LaWwuW9AfJBqHtbhY', 'BNzcz2ooJLW9KCb9U4XPq2dAJQpdtieuhPamFkBNMajfbuaDKSnC+rnsKIOp+yDOMX3n78tg1MorsN8bKiFVlW4=', 'ZVo+WlxKvFNcoCWRTYJLvQ==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 23:36:13', '2026-06-28 23:36:13'),
(1845, 1, 'https://web.push.apple.com/QHMBQjvzvUmG5-gWy10d0rh40sDI2uMxPaDdeLesJ1QlC7l2qYxt23BPWJHL0maxqwBT2VM2VsW4Ofl_-GXPo02Oyfn_lERxvABi2bIekYBrm7cubY7tbXogQF7Sfy8G-OVAqBUNM0aJRWxxd6R2L2WrscPQXvpRdiX7IdP91v4', 'BPtEycccYVDozdF2YpYHbbvQKRSwmyHdvthCjPIMZ5ZfS1DFh7+7uW9h0c7ytq0cih1oiUVufoQQXINI8aeCIjU=', 'z7pf+qyNGXhry3G5oxzapw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 23:36:14', '2026-06-28 23:36:14'),
(1846, 1, 'https://web.push.apple.com/QEMRY1WUWPVSmBqCvyPDY9BerMHw2UGm1wwCYLRCfo-WBMpH-10n0cHFm7D-mcXJNsjn-4g2bpyMR-mab99MrLoJaGLsnArousMTBSnI9c0an4WfZEOLXGTSRjWpajFn2FaZTvwkV9-TAXUCFmu8pYsu02nt9nXw9cPGy1khF7c', 'BI9nLygqGdNb1nlcu8HKLZawJLck3XC2wPn0J+PtSM0RnrrjIsnOmnaxtDHtV7UXvEdwP+OA/zedc44J1WTdIUM=', '5OqKe4HTXJeN9hynqppgJQ==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 23:36:25', '2026-06-28 23:36:25'),
(1848, 1, 'https://web.push.apple.com/QEl5ojTlKoxiYr7SQUoJEVn3Ah8aJ4TiAb0ME5CUbRVl-ocYXr3BG-UqI3Xs3VfiYD8SOZRAySwnGuYyLOXNjZVlOHZdJIQ1pORQNG3wM5s0-PcoEtJmpMK2e2xTNoXeyTgcYklIsJOBr0l6pkWM0RKKca9mD1zADegekRauNEw', 'BKPBhCLa4KY+tnfzujuSiQAOENsR0OL3WyolCy30muMEyDfHQG1DUDJDPewFMAjPPMWUp97ROOXBZ2uE/WVwD5s=', 'jK/DHpFP6MrKi/xAY9cHvg==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 23:36:35', '2026-06-28 23:36:35'),
(1849, 1, 'https://web.push.apple.com/QCDuIw2rkOE9UWplSWUrMTSME06PnM3548Rfa0Nks5VTb8wZc_pRYIfC0CWW4c0Pf_ef5gZWZ7ag53STW_iFxOwiq_hPUy2OG0gXW_Vh6M7dm0Xnjg18SGNE1z-nPptJ-Pq9BjpWzC6vW-H7V8KIqelzhbsVXd_UQC51UXoQH8M', 'BLv1iLxXY4lAmqxOrIS3MyHKuqTnNsQ5PjfBFxKQdapnz7OUbyGLNrvMorpBhhxkXd6Fpwa7emjQjPHxBizA4AM=', 'DaiTPMkevyHcrPA8wjaDcQ==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-28 23:36:36', '2026-06-28 23:36:36'),
(1850, 1, 'https://web.push.apple.com/QGbs0puPWqs8gdAnBJc9co5kQGtSy01gwyQ67Tt-LqfyGx4lEPJ6puzWJTRY79tdtVci26ssRyzfcNMwm8q-hHQPzSFVcy0De3kTj-KD0FUkAHkHDGBciw4oSmbLxEKGAtULlc5I86WsRW0PJ8s5X_OGhnilm69DZuPXlhKtbBk', 'BOECT7dVawdS/IiktJu+YkeN0sa4nJJfGNbpzDIuGq2uUkaJTcHRIwWvOJXt7/wB0N2j3aZwKmSSLohGF6imyZ8=', 'pSeoKiEHZTUjr+eI6Gk1Dw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 00:24:15', '2026-06-29 00:24:15'),
(1851, 1, 'https://web.push.apple.com/QNdZ_o6_Hv5ZdvY4f-WQ_8KEjh3wEvFsjs3UtySoWxHhObjr79F9Q7kxT4s5qxOlWvhQ-7KAYaYjrm2QA889Zlt6d-piFMsQzUs8k6QT1ZutporhMyKpXWUC5b5ziClUNuE6xnTdN_Kt1Hhz7yyMvY9AMUoU_DeHuNPShbcLtLg', 'BKNuW7tUomZoNhrDUw0zytLCPayrsGaVHhlx+XfaiotSaJCMUymJEX34fa+KtUZt53U3H18yU/ByKJ7F7pNiTSA=', 'qIf5sZ38cYv+6bKTfHNf4g==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 00:24:16', '2026-06-29 00:24:16'),
(1856, 1, 'https://web.push.apple.com/QA6Ek-KyuSvXDvaY52L-HRTRU7alChZqod-LMxTyR1wWCIf1oSp9p0bGWFGU4e0f2Anhi-fBdxKG5oouLCAWcJ7jPviu8z1x6XLTCzHoZGSN-idSql0HR46tPoo-ZUfc-u4MJDLX01C645SkJLvNJFQp2hEh-vzFvWLPGvGrNJ0', 'BPZpqy9/l2l3n/oqB7aKKCETdZ++zr/KLE9nf0uZkB6fsA7b6FAlBO7bkx37uyyY+2mgiFkgYQ0SfK9QaiQDvpY=', 'JAE8Jda9tOXaSioM+ku/wg==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 05:12:03', '2026-06-29 05:12:03'),
(1858, 1, 'https://web.push.apple.com/QJ7rZTWXtCTXJx30WvfBksp5KFoq00JveBl9jds0QaGMduikBk6h4aRlokrARhCYjKuX3UjzC_4XEOhpYhw0IvNAtkMsit5bFvOL73T5Tlc6rzksH-v39DVt9i4rX0-5A87vCu3WcG8YC6uVcetQkM-n1sUz_GPdUVyXn9CgIiE', 'BPMIjMmAkh1YivDK0DjyvtUp4DpYUlJkuMARPlcel5ji6AcwpQHdOX7ZGELkZsigA5KXafC8IUWkApIjkAj6Atk=', 'tKzaaGWRN7/xEAkR6ILOBg==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 07:18:16', '2026-06-29 07:18:16'),
(1859, 1, 'https://web.push.apple.com/QLcCVjeUYvzSjbsI0NF9WtxzjPMUjiCLnby_qbev2psXlqQP_fa0twhiGj7STkOT6g2TP2Y7aj4V3L69aL-iSaSY9yg7u04SlP2cfP1sYQeeUKlVls1O9kMLNE27weOZKkAoTeo-Q8hRaLFD8E7qWZO0uzSK4LDlZpFwgRcK3lY', 'BPvSWOuP0G7jp1e/Bx5LDkUtNsF1nnPn9yGXAQDSyQTwcbCJQAyHUMi+wbr/g9hvclVHv5wAjrmZoLkSyzG3tCo=', 'fsEkLyKf8AHpm/Ec0HHX1Q==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 07:18:16', '2026-06-29 07:18:16'),
(1860, 1, 'https://web.push.apple.com/QDCqHMh8Nn-ydViLXjVhBvs8YwcYT82Fgo7pKxI1joKY4WJdvQiPuEmDlSbc61qdR3iNbh0DmERyRKi8y0HVTcHMYfZdcNQjnbJ7en2J1kl1O5Nrjx4h4UZSAFvzpDNmF4jEY3pAuiTD2NykJmZDkVR9bvB6YfLBiqNgLsRcbio', 'BFxQ9PBBBQQf6MIKGAvZIMDNAKTk2r7RW120l3F3QiT3rRJc0ZKfoLeeZc+vHW17VLPIsmQCVDZdcXBB8at5YoY=', 'iZvhN4N46bep/q+grmTXFQ==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 07:27:10', '2026-06-29 07:27:10'),
(1861, 1, 'https://web.push.apple.com/QHVM20Z249P-fkmLkqmG0SXCyyYv9tkCgsPOJHLaKpreC2M-7QbOunp5hEzC8QEIvIwG7kXEJo2MGB4og4zb1d7OAA1nws-4sWEirLyrCNwD-tWdmmbXWmtW_SYxuVXN0K8qB11PKwUYqisxzzwZ3s-VEyYqUhOYS__tuuOiAzQ', 'BOW8wQUugDbAZjlQ3JXiy02HVfKbJ3MM5zFYRZ+cov6QJL7asUfnhhPanhJN6hTSxfGxcR0xDxLmBWkRtGbVnTc=', 'f86+cmqYQEJ8sawZYXYhwQ==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 07:27:11', '2026-06-29 07:27:11'),
(1862, 1, 'https://web.push.apple.com/QFAwOAo-Ix8NX7E9CYUPjOleIJQiK2TZTTwguOjv6r5CnNbBo5YVMMhzy1BB5uv_ad3QO_Fp7CEh-LKFDmmbGdZLtcUhthZJJrACb7eg3S4-CkCLfFo7YXmwrGEPwaBpqnQoQdiIlVBXTxoiD2cGz41gR5Awv-gGdAzT8jOMk6Q', 'BFwn+DSijzZpbB7yIvKjhrHyMkDu2Ra0bGgdgapcxGEEzugiMRNe4mZcuqTa0LaAOvHetEkv0k04i3iD3bFVutc=', 'rHm8G3C3x+HLEMGjsY/efQ==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 07:29:48', '2026-06-29 07:29:48'),
(1863, 1, 'https://web.push.apple.com/QFXZWSADx_ErmDAjigLQLJWltfK4B7_a8o0VeuRKBkKl0dfB2tPPpdx__iZ8hrnmedqoYkgbGZJoDO_DjeCv6a0rSXdqX3V4K214zgiCrj_D4vVtQqF_Vud6Ch9rD1y8a-UZAJZfbsTrY7Ec2qNN6Al27xWFluDJgrMizr5CD6E', 'BJMocR0qSJvkD8xk7ERR/waucfQ9fdumgq9MwdZtN0049yvcIQktXBbb3bGTGMc4FXoQA+lZCN+G29XZDAfXeLQ=', 'kMvI1b8lCtyKSMRSt6tCjA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 07:29:49', '2026-06-29 07:29:49'),
(1864, 1, 'https://web.push.apple.com/QHduZO4dJVyuBBOTV354RgbUZLYj2D_qYCB6Qkl-nbsejWIdW3pC4HJflf1kjWsLnxz9obBNMuN-iagGtlrQDHLSgsqLYSWsSWJgQAoO-fPvubE6ffLklcAQar_yS1RMhUE2BgjtB0CtCSO91BLF5863f2EZEKkaB4oggafeYPc', 'BHLdOhRIeHHQ4jSodi5w5V4AcWl69hvcuVrN58mDcZYCgaJSGYOP3RTRS0AZeZ+Kxh4vFwKHzIofyUVcAEeHvJc=', 'F4BMr4yJfrZJGccYNYdQuA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 08:49:33', '2026-06-29 08:49:33'),
(1866, 1, 'https://web.push.apple.com/QD1-gGgHK6y1-8yu4e3mZsi6lUq9zzIB_klngXJsSGb2yGZtDhng9OdtURKGKZFAJsWG7eivUDuelZFB_2PCe_f4-Wv1l9uZbQoa41aC4NwxYCNnQUG2cCkDaydGJchflKebBfUCeslaXY_Y7GHeu7K7zf2CVFEZSU5bu3xpHPk', 'BLnHlPQwhriqS94W4yhBHhImn+xI7P+5HjxB00dyeTyEamfRxsqpeGigvLbKLth7J+FoxlqP5NvO+pJPIfTyozQ=', 'YFEnGNUNglAtZTOehDjE2A==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 09:17:14', '2026-06-29 09:17:14'),
(1868, 1, 'https://web.push.apple.com/QCb5ub8jsV9NUtJoRe9FT0dX2bScFTrwogG_hGei7Tl-4nlXKO5P6gIKD7cnjd8NCxdtq_VfbrSvZWGS4Cr1pU5-JTIoqfSyVod7MyhQ1In0i6HUOMN2yrKOZ7kP3E4dTAuI4rjSy0jwhrz50rij2ofz-8VIiuzEum9l_ir3LGA', 'BGcguib8m3cOY+D8zQudtea9TMV/vsM1LsjGjeaaTWYoZ7h+zaryitgJTKgCDgwYFAkELcXXIQP08BHTQGRt4TI=', '5fVbZsYbgZSu0/8JWapbYw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 09:17:20', '2026-06-29 09:17:20'),
(1869, 1, 'https://web.push.apple.com/QEf8OMi3gyaBGn37mXhN96NP6yPoTaTXB4kO9pX6MIFXej4pcj-vEeCciNjCIqqxdgPFzRDwTvwxYZZ2vz9X40ziHStj7uVuW88wHvX8BC0HkRzRxT9ly-yqASPZ4jvCIbJ4U8bgu6tz1reuwAgsAEtpmtOljcpBh40HjPBrZuE', 'BAYQkJ/Hgnyr/VtYD+GaX4VjrW4ZCMo6dUgOsLEEU4upffsbRRXqYUU9KADgA33FruHz0r5QMAR5ZqMKfrfkKX8=', 'ywR+rxzw9e8oz2LuEtgwBg==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 09:17:21', '2026-06-29 09:17:21'),
(1870, 1, 'https://web.push.apple.com/QCcTvSpTSffkjKqv_nhR1V9VwC2slzjaXbrPjhfM8x-LbFNmvvRrMvy4TASYHkzHMAgWS8bYJh5s7dL_SIcV7st1AnCdqj1oX6uZWcE4tHM32wQR5LE9v2iV89jg1mCBcJfzkZtws9tkiSqLSNQIg5OMFwGV4gpaN7-0ahv7_dg', 'BJY0ZpAE90hkcAd/xDCjs3bIwke16/4VSVfZFLaI5Yt9dywK+y275j+spfdMNLi2l6QDyayOHKyCJZIpSKPJM4c=', 'PKB5lKx3onPYKj9W0j+nxA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 09:19:26', '2026-06-29 09:19:26'),
(1871, 1, 'https://web.push.apple.com/QASaJiNdymeMOKMnhcAELn2_nVHRiMkwB31W-JBRLwtoRSOlGJrxgfyjK55GdxSY-WDR0HAruP--M7V1YxD4ci-lAiCF-zwwePstMLMjFwGDu2kD6U5n2rWnEIXLf2adpWssYBk9d_Sr-2hpISppWNAr9uipbJiExE5GaxJQhRY', 'BLprjtb0WE/TnWqE+GZyrG9nsw/GZc2U7WNzzFoJpjXkBivHJOEWgTI+5KnVxGwcTghcRF5H45WKpvnKdDHr8Eg=', '6MTW8ggteR2+OxzhhJbQpg==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 09:19:27', '2026-06-29 09:19:27'),
(1872, 1, 'https://web.push.apple.com/QGWGLswqY_VsiZVZlrCNm0f-UC48zWpEd3oD1_eU5gekm33oec7FU1pPS16B0ZNVyaW1DXIU6JKkMpBFGfohBMjM2wHIDOhE2UsuY6ik4I68LkdAYTDYXUKaPpkrgXJWIWxYAhrL98ZSRCKDCz62eN9rRnH0WODo5cQcspK_qLo', 'BG4aE18imPZHpb3IIxcBneZiAPYe3eAuQit/oe50AZLauOyB1rYLSaypMm8hlYOS2qWr5k9ICRgqdlEibM7BJq8=', 'yNnwvfEn1OdW/oCpy3uHSw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 10:24:07', '2026-06-29 10:24:07'),
(1874, 1, 'https://web.push.apple.com/QBNKoK4YQ0s6X4-zqzJGmSTi5DW9AkMqZX7axvoR7rVPddqSQgqh9F1VMqXlxX6Yg5fVF5s5C2TMzc8_HPwBCpihwXD0DzN2AIkl8ZZipjHo53A-1qd_allqbz4JJc-mY0mjPBiUXnQt4MdX9XIvxX5U9li1bBZg69cZiSLkHaU', 'BHwWDhQkYlZSRNH0voOT/VdbDeybF9Fk7axaJezfpfQBPIcXubyRRR2+MDmhPWBqcWOA4PpPx4q2Gh4JsTsPzXI=', 'QEBN6giPCPyqNU7I61p00g==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 10:24:16', '2026-06-29 10:24:16'),
(1876, 1, 'https://web.push.apple.com/QHqZX3EBG0wIcPqMxn9wDpCLxmXtC6ylpBMSyrjaaYmrtEph5a8ZMu5QUo1pSyBxncbHn_Q0wsQnTzuI-zhQWqgrV7_zh43B4N9OnUkMYgKNuTpY4PELw-GVgEEObLloPU_wJx96-J2_kg18SEfKVWrjcanrn0W2r9zByxU0wj0', 'BCJckojFJaSXGe3Sz3iry11KmKq1SNFtRGGGajVgCSU71ChwYMoBERTPXPSZdnMpGpatHMYnX3mdSiPJofh+0Xo=', 'Al90uv+DG3Xr+oxQF/MiXw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 10:46:17', '2026-06-29 10:46:17'),
(1877, 1, 'https://web.push.apple.com/QJSh1mpkQC0fxNKetnnDX-_m6JVKpzfV3ALCV4vv_Pwpin04QQaLLKsO124_2Xn9NyfbynhEWzhuOu7s_jVrl88AMRO6VhXO-_bILwOw4-kuj9tM8EOOljgo42ELuPuQaZ4seeRTwQa80ZG9Enveb-Vn0tLsOJtgg2fyLjV9uGc', 'BJOIgFgi7Rldc1b1uQI8p9je/n6cowl9UnU6ySBByK/vq+yZLDp9dvirz+ULM71zJVsP0bCqop02bjj/RIsQSiQ=', 'yopNv7atc0G7QALbDRvshQ==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 10:46:18', '2026-06-29 10:46:18'),
(1878, 1, 'https://web.push.apple.com/QP80__zISYq3fk0cQxcrfGqdb55HSneshUD_unl-dZwrBgdizyRflxrtP1v6wyuap7KIHppN7s1E8kJmhMSvjJtjRsip8A2uijJQA_erKw2aEAoOgot6C5Lpeu1rQ1Z1A3ewFypU8rKnAA5GnnNrRLJuRa9Qu9hhzTP7O-Gzc-E', 'BL4wwdKQfdBHOGANoJguXyfOqNkpxN0gEkR3fKm+qTeC3mu3UeWOjy+YL4X42pfTk6UGtUd149TMIPW9lqpKI5Q=', 'Ea+H0somoaQFl3UGp/thWw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 10:50:21', '2026-06-29 10:50:21'),
(1879, 1, 'https://web.push.apple.com/QIYdZdbEWqKOukKBV9ZeVZJr7xMDohrKYN1Q4iYzapKd8n0jJkEIMe_TBdB6Tzn7EajNjoIbb8ntQdfpZnDZDiGIfplwdYPiTslQ4fR_ISQ4Wa0UebAwYYTsDgBtpBe3_QSEWS2DvKApyBccQJwszI1MHbxT4-jcVKkwywuQfU8', 'BDjNvCGSK75aeOpsdprQU5LnbCCHxvljHyglaWB86re479Ou8Nb21wI5AoAEnQ/vExDMWbUoj/hJYO+JanTqYIc=', 'cPMeA1RnLnG7gmiu1ZqjNw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 10:50:22', '2026-06-29 10:50:22'),
(1880, 1, 'https://web.push.apple.com/QH_NRb6N7aIQnY8VZMckvr2lu__9pVHz48vQJb9L1OQXM3HDKS0pS38SGODrSkoj-pVLsGHUKznYImg9g6sgndzVy7_avclS4Wk7N1dZIm46rZ2KssBQr-a-AQgJCE7i04TxXQLG4Gu2_Ue3Jk1qX6mndBIqp1nKrP08r4brlTk', 'BPTIkg/1mN/P0Sa3KtsHEN8QwwaEMtoegNwv7qd+cTF/qi1oJTSDLRKUTNRVUHu9qc4toZdd19w5OlnrKLy5OJw=', 'cQWjx63o9WCoIqp84ioWCA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 10:50:37', '2026-06-29 10:50:37'),
(1882, 1, 'https://web.push.apple.com/QJgdzMT4vwjc9GA26SbTaU8tSUU7KlPbLwQ9P02VtN09oLIhoyCZV-7hifGdKPSJcToyuPxjFZ_8bUvnk1UTz644BE4RERsnAq8upoHm3BvBvLh-_NpsZ7_5Yym_Rx-g9M203CgdV-uVEqDjHB_kx6azb0g4Cm9pSeWhi5AOGQQ', 'BER3uW7Hl1e6LutOAO0L5Re5VhUzJdb+Iv8racvxTRlJZASYZvweYCcFYjnhC04KidQK4z9E0yqxgANATDBs+mE=', 'w1g1m+5Cf5qruPjGEEOdFg==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 10:50:45', '2026-06-29 10:50:45'),
(1883, 1, 'https://web.push.apple.com/QJHQYhKg8OJw_T8Cl4u5SE7GbjM4UTNDRn7uNPqIH8wXzyvDgX0X1Ae3vfhNOlnj92_TV_VTFK2t498s1gH6-y9CYKBpK0j3fZfCwxYlG8XcsNn2rcLLIMF5YDi8mhJfwNTE2O7CBJK2Bs-lcelEeAAEcT5xHlg3-dI007qghMI', 'BAgORPgEUda6qvTTXJLdt3I2bw7VVpnXE7pR0WnQG2T1JQfvsvenkOJWynQ6/3ti3NIWDf9uuQxgErTl1CAbYZw=', 'ZbnMXzVYa3mlFPHmShjSSw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 10:50:45', '2026-06-29 10:50:45'),
(1884, 1, 'https://web.push.apple.com/QPc1-7VVjiWOYKl6ucv6d_-XNkIjOzKVI68cg8XgCrGPwjWeiVosf0qNO94CnjOJhl-2iawRh9pdfGQnBp5il7uFo8eBPYfCA5Ij4ZAQErMYWTSd7s_rTbaHVcaRR7jw9yIxnDZfHt1yqUjgHmIlcPo5tUSqMxNAiOoxRNcDJAU', 'BHD3daSA+iB07+VgBUF5z8sKciDLQYhyJ8gorPfB7arkcHG3JSnsW99rWcFS0FyW3aeWc0ZtoGBNffMV/Y7UFC0=', 'LuSIP8aXmMJu66lmJ/Q6ag==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 13:21:24', '2026-06-29 13:21:24'),
(1885, 1, 'https://web.push.apple.com/QCUzZiB18hmwCtNRdVzOYKPJasYrhRDiPaEFSQGeScqyuRi-_PtZuxpEx7Fy5FB-cxFO_GMEm4y1XYJPNMyxZzAnb5lF4DFd_X4YCMuHimflpZc2AXzlMPv5lO6oR4MV1Vx4G8c_KtgnCz_WltY9_8QEhaCpuNd7kw3DJ6OfqWw', 'BPae8apVZyVKdLDlWACo2eytEpkp5fshLDrqkRCyDSqpyJhmbC6v1eBkQOKeN35PIaRDAyqYhVjqAq3Totk+s1w=', 'DeI52paHuQ1wsYTxPRgixQ==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 13:21:25', '2026-06-29 13:21:25'),
(1896, 1, 'https://web.push.apple.com/QCGaSDPkSReYGSkLqiFkVQFG0RUBCiDbiSqayKyNbFZUMSqmgXYNFGuJO7TsJviun80alRRIwY8m800bei6hIYMUcInNkd5lKgJN5Jh9OzZg6ff99ecVGNsl4qvB9LTLgYog3rwq0FI2vMzuIchzemu6w6yzGWAcjA-aE_4HCaM', 'BP9HXS3lfBKWx7SXSbkpI39SiErHFJJSTlZ6l1YVbIpdXfjKzvl+IbywUzfas65Pg0U/5nvV6D4e25w2/U8li1s=', 'gCyv+927Gwh8sJINhUPhrA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 19:14:45', '2026-06-29 19:14:45'),
(1897, 1, 'https://web.push.apple.com/QH0wwRsYV2gqENzph6K1M1ekg1Gwly09cU-WLhyO43ScDMOkp6Sj3XMBaBcy1DSCyz1MHUoTEiAFYlTu7zawwSy4yPIpCMfqqYxbcf9_sL-MaA-lCo0TLI-zWBcQtXS8EywNCdFEoB2MbhTfcAsE8wkk0ELr_uomWMDjX6BlwRE', 'BKfN+K2iU1O6OMFWLs2b7yCB88b24MkU2b7/zla0slEdOYLdEty/eHpESIGCaUOHM4CKVvemApC1Ox3x6gcjJSk=', '38ukndKnMf17MrBuKNUrgw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 19:14:46', '2026-06-29 19:14:46'),
(1898, 1, 'https://web.push.apple.com/QHbpa951g2sEU-qWL5EqeFXFRLYlep3EQMepPr6KJszg7dVaBU8ddiGcyLi3_crBUbDrJLd7tlCRgptYjylhAkItKsdtwL-C_Mef_vjQgITo0xcYvDOoqw5neLVQvZoooeFokPH0CM4MVI8fiqNfJ-OY8YCOi4wBdAPmmRYgaBw', 'BHSGgUj/bge8XKTEENNWVdwvwAnq0zIKo3oP9frajGhgh1rFiIa538DNmgrTSzdmKlWHIImuVtdlayaeklRDBgg=', 'vBVY4FMzQHpBGdFaerC1sw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 19:14:54', '2026-06-29 19:14:54'),
(1899, 1, 'https://web.push.apple.com/QHT29Jm0XEBxzGSrHRj5GYvXuGt1J_gy3_8WulIWePmmyUOeIQ-twFrWMq8DKHfr00FKXiFyk_MN_bqgWPDA3NvSLFWc4mPS1JLr34IJK_t50m1VDkYWnBW-3TxiWCisbxXAof3RcakvnXw0MzhSwGX1NuP5YdOVQQMkAoavNPM', 'BIncK71/YXPhnP11MP+hVtQGAJCFOKmwKKDOQ+QwtTfQOsJpe2Sp2WPvS//D+5LHTm3aQ+tf+ixHyhsniI213Og=', 'mQS5gfHSeS0ot/8xK21h6A==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 19:14:55', '2026-06-29 19:14:55'),
(1909, 1, 'https://web.push.apple.com/QEWle3yx8r7CPUktPB8Fde4W2Z3y9SWFJr-wideqpAl5-fJVdR-L6q2dVe3BeMsh6B3n2fqBcLPi4yI6j_0mJWvV5YOhNbeEJFn36bM5HvYEuOIw2iwn6M1URQEYyqXjtuyUlCW7PU6rc1FnGWlwGYkHM3JGENtm24zqI9fKCZk', 'BEKGSy/mKsoYAfMI5NLYlCouyU0tuH4Hp5mzVtQ1PwVku2Ui+hyr1wGNQsxFjxurdAWdI+gWlGZ3QrcaEiz+NV0=', 'n1kNzIxjos4jUrXlqc5CkQ==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 19:25:50', '2026-06-29 19:25:50'),
(1910, 1, 'https://web.push.apple.com/QM5NMvbzm7A6BV3qH9oIbEakDbymboHV_NIPsum91N2UsdxuTNo5TAux-tsTDdoppPVMm3CZzY3rcoy0ygNUHFyhAG5CsSIdu39TBWoE4vxgIMnOfO-_3PL0ure2AxvzeWzfTmyOy6kXqVM0eRh7IfHVrQ7RJgO_pAx0suYJFQQ', 'BHkSRgLZNLo1xshkYB0To3T9QaAAcM6J+ZHb8mNyGhOSKsPF/Xk4NDKdo+vJAOM0JDKVrbf9pN4KwQV5w9fnVRk=', '7Q99Nu2Uvv8Kbv9TpTZfFA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 19:25:51', '2026-06-29 19:25:51'),
(1913, 1, 'https://web.push.apple.com/QPjSKxQD6f2r4fr5XLeg_AbYi-mgIa1iOPqlsY1tWKo20AqifWZQoMZVshD3y3j4hajvCVRzphp5Jw6AB8_VdQ2jeHB8WvNDOMXZid8G_gyS_WynfigpMGqh6EsfraU858hbAmeeiccAXAZH5-CVMpfXNTZlTDcHKq2miQipeh8', 'BFdAjRqg9ePMbTv4xF8YRqeonYWqyPv71XXTQLPZ6u4YZw4jd6YcXjiW861Hhm8GlY8hnDN8UjwVq57hjtmNOwQ=', 'U0J6vkdws7DZ8SuYFtyA5Q==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 19:36:35', '2026-06-29 19:36:35'),
(1914, 1, 'https://web.push.apple.com/QEqYmO5yprrg_8OnazFZ9Q1_ydPWjVAWIqYYs0p7QDAMvw4Fbw2Rmb3uAW-acV2FFT_SAPRkIEqW7B0cSp63ZTjEPZc48OupFaEYE4BPyir9N7PsuCKiAAq5eyHB-VRllw7nFoi_OkHe73ZJ3Gf75QKGwiaaWY1NH38g8OoJmAQ', 'BOMjjKflzrgouJxzz73DvbUydFmXK/N1wdYfJgWZWhNrxqsi2Diset0NmqcJV//fGG29/CypN6+w5czMQc41Npw=', 'p3QnAbZdTrcgMK7PJ9wtVA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 19:36:36', '2026-06-29 19:36:36'),
(1915, 1, 'https://web.push.apple.com/QCQD_-D3Ff3GkX8HszV_K9dKarh-GJ-ro0IwoSJpWa0Ad6HRWrtH3rQC4XZM-SXG65bkfyENbFauWHZaqRyC9P0VfxaPJgWNfDAyzg5ffx6SsdejKQ5sIhRJAzYN5x5Cwe7JlrNo-Jvz65m4YKowJM6cLBNPQpMHJfDJlPKSksM', 'BIBg9STrCsA4iqwAtLVZIKCWrgBdQvqmoe+M3M7hDqOjdtsT0dcQ/wXaje2NNQN52cKoFpJJ4CWnmZ4cc6oYDeM=', '7HLAGE/JuOfaKymn7lc88A==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 19:37:09', '2026-06-29 19:37:09'),
(1916, 1, 'https://web.push.apple.com/QEnxwOe8ANlDjym_tMgLoc0pHUnbvXo_ZwMa-PJo7lSNQfXV3fRADG0hS5OpYhyPZmu1-gT731SAgsw_HaYsDjPB5kjI_Zv0j1A5UwVTk3_mux2ALPN05uEfH55VYyrt9W905OobQbzrh3_Nkfo-WtWjHyld8NmXOAe2eAs0ZtA', 'BBBK1aelxxgD/dZfZbUW+VuDsnmcnKKdAFyXI8XhixRtapsxzhYhSd12XGOoz0eyU4OZPx4DUSUZ3UvA/SbgH2k=', 'XPzrXXy6kxoN6u+kAX4Gog==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 19:37:10', '2026-06-29 19:37:10'),
(1939, 1, 'https://web.push.apple.com/QOeRVasRAlt_NIwg-dL1nT7jy5jw-19VjTNxyRzP7U42xfP3QuMzWnFkci-LlP9gEEmgHeudZTnrcy2CReYTZx3PZCgxn2p9DpVYiZO8uAZwLf1FRddTZs8gtfrhU_jqMMtdO74HJWMgaXUmsG1kr5D-XCsJjdP9w4MDhW_r6vc', 'BN/EfYgATlvgNhvAQgxInbrjFXpoaqD1Z+CZ8ymcNza401gaDe8go8I0q2IyTqSmx9JDyeVhBnDF6742xGnn7PI=', 'sa6zwOeg0O2z/p5cZIGXEA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 19:52:04', '2026-06-29 19:52:04'),
(1940, 1, 'https://web.push.apple.com/QJ0SHsmNm70W_iasY48TIruPGu1C_p4ukkQiEmpJRwKAY_LKEir2BtFzgBK3oXq67d2s3TqHSQuQAaWGCCPFu1KenWaPGUfDzzrHr92OnTm8yHQYuGG5W8KWWC54Xa-Wci1cICcltF3xPXdm2nydvDJH8886M6SkJkirabscRqM', 'BCJbz/MthjvfqkYy0g7+uBcLE5lqSm4xnD8hstcjUtUMGHn79prUfENgdUZILWrWgs55pmhAidD+xP48R1Z8iJc=', '5nW/HWhMx+I+X/9LczsEew==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 19:52:05', '2026-06-29 19:52:05'),
(1941, 1, 'https://web.push.apple.com/QGsgxRSf8Frp0_b_KvaDEUSxrmI7KLjDmlP8pHcKgHLv52LWXEvfAMxPPU1ckzw8hoeG_Gv_jw025g2rPA-suE_-0Z92ndkC7p5PQMRnR6A51KyAagq24iao4jXVvhympQDJtTa_Jp1jbdMWc3hFYdt9lbiSNCyIHuUHnV_L5T4', 'BOT/QuQX/81L0rBqx/UIFb3drb2PYMGBBLk8fsOu/E8M/o56aYhDzwCnrIh8cAjTf06cONvVePv7NCALjRwnqD8=', 'eE92uIT/rJlFW9pL3qpJ1Q==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 19:52:37', '2026-06-29 19:52:37'),
(1942, 1, 'https://web.push.apple.com/QJrllzVYeWrke5OPmT6txjQHDYwcNPLcU-lklXxZptYKAh1ql3K71Zq_tWu5SDF78HjIQlajfph71kkEW0-z-VaTMEQxxQKU-ulAtbDFpcNQ4aOeVczOmarAlZ4uKnqPR8w-m8rHD_hsJJsMpKGRCLhNUTOWmKhhVLJgE6Sylrg', 'BHtuyiCL6HU1SeWJL98ZNNCCoUi7bcL0PUgwjX1vVhWNJCjDW8P6w03LmDXcTSaxe/GocEDymTaKcpPwqS4b1lE=', '5AYFSI/42SfZoSfsX7SdLQ==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 19:52:44', '2026-06-29 19:52:44'),
(1943, 1, 'https://web.push.apple.com/QEEyLcCwY0pbrPEOWkPdJnR2CzZYkTmaa8ZxIf19arM1ariSibH3YqnyzAqu4ui_LEgcQ8k1SYp7bK4UlaT9xLUpqaXVdcHoN3QN15OjetirP0_eqL8BQBJghiYQ_aJA8cYivO5bUBVGx4d_UuAZOnOLBVFKsyQyaIjj8fUiPSA', 'BKsVsxYXjca5zLgotsenGjbfRYDphfobI0wWZweWK7zp+vl8pR/43GpvmbWYpMBe/eCNLOaLBvcZDc62+bfHxR8=', 'gdmSqTwgD8H0SpFbbPAdbQ==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 19:52:45', '2026-06-29 19:52:45'),
(1946, 1, 'https://web.push.apple.com/QNKezBL_vg8Swp1S_d3yrOHJ6I8TGlXwdl9kkkmo9a4_j1mnEGlpxp1tkbMhyRol_O7GftIvzQ2nXUVh0Sve2WWB3rrgw5yqEwd5LSM7F14T6aP5u24_pQIqIntG0MQ0jr4dTgG9TesVqYZ3lg0BJPCD1av5_cxqVo_55jre86U', 'BHo3cloSxipsfLJJLMuCu/THnaEnbhgHmOzQRffUaxfev7JBue/I3GZtIYWYeQjNfyTLLVGg4ugCvDhuxmStfCM=', '9Vlfi4uswggu/mkND4LhEA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 19:55:14', '2026-06-29 19:55:14'),
(1947, 1, 'https://web.push.apple.com/QNsr6vJDNOw7G-MIysCZ2KVs3yQBJ-vs3bvf1IocmlyyT0O2h_Tc4m9EryXDqGF-uOfaQ5W_uZlr03AmdxxO4jg_gJu209WHmk4rA5hSInyjkOz7O6qDgEtF_Mox0dqe8ny8rHH-vs5svWuu-bd4G5kjRzcOhJtzZlzoCmvaaLA', 'BIzDlAHRhOM/h1w1zmWuw0le9EpblksQ381VDGgZgYRa/ObGaEvp9WdGXgTo44YmPHOLF2uHcQgDpeYSEUf4Osw=', '9X+FgEqbqifJePEJb2o1VQ==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 19:55:15', '2026-06-29 19:55:15'),
(1948, 1, 'https://web.push.apple.com/QMuSFUH0sj466fJRdcdcfLmnIPcwqZKzZsmc6iEjC80CbQvRLX4FsGNYjEDjX4PHD81qo0FKDYjp3sohVAIpiBkjYKpVdPOuIPSiTDjm-4FYyfGm8dy0NMXSzIR_vxwkDaap4LgNJw-TqpkabrdeJDLBHt3mnf4SGPQkxVPSKiU', 'BPS08b5E+ftrkmAx3catN2xZMKjFxD1xjfQ8xBLmeq6VutIvtzZ2DEhdqrAugvs7KVvahNl4uOei8nillkb4WGg=', 'e9NsZZBTWGnZirgU1j4m7g==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 20:01:32', '2026-06-29 20:01:32'),
(1949, 1, 'https://web.push.apple.com/QKyPw4COawLgdmzM5tQP3XU8MHfCaqV689TrgSSedtc0GxjnuRLacNYbPGuf79MpEWcm6RlhfxD1E0AqoZaga28I10ZQMG_EuaYCrsXLHIXJbCevOGm8aNyZqcgOkyEr4CJRTLKHlrBbt5K6tJrIHd6OkqbGGiytzBJ_h6Lh2b4', 'BKWzAtn3IezIpCfqO9LHYOTKX+INAWQSQQHqiQlfR94vfBjWnz5hI6o3mqqs4byL/N0rgLLs3+LILuIO/aqn0cA=', 'xHEoOfMYFholsDlCxqOR8w==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 20:01:33', '2026-06-29 20:01:33'),
(1952, 1, 'https://fcm.googleapis.com/fcm/send/f5Hw_9U6_ck:APA91bGlO1D0fomXPonl3r4WpzPHllCiHMN1-5id94JpuQ_652FGP3J4uT5w6WA8I_HGsJSjekcqGKz2KAN9AEUkiDm4G0EfvbeEKAMC5doC3-hQIW9Elc3vDRr-ibHi0c-6_YKAuCmx', 'BL2+RPiTYsQNz1UC/HJJdQblHAxEhoVSmbMVpJVBww8wQW1BzFk0wIt2T4KUg4vk2gDyklqv8afp6DALw4nY6hM=', 'q0McFlx6LLaTKvW3Kfovzw==', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-29 20:06:28', '2026-06-29 20:06:28'),
(1964, 1, 'https://fcm.googleapis.com/fcm/send/cuA9J-sJLKM:APA91bFuwrPVfxODDCaZpwHPMnOMHN29x8tm8SolTWtdhylwGPfWusw4ktBJjYNvq-ZmDOPZJonf2-MqU-qHF4pILsI61WJn3CoteRrdbd18pkjKHs-_WXE0relOI6oGAJ6AcqEr2E0X', 'BLuRyOr7ijV+H2JnNbqTcWsdzIWdF8gpyoPAvr4/xXWuEILc2YhMRIZLL3CLpz2OUK55Zc40YMok3MGggPwiRE8=', 'rneFsqj7spvfgkMATIyGJw==', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-29 20:18:59', '2026-06-29 20:18:59'),
(1966, 1, 'https://fcm.googleapis.com/fcm/send/eCNVnqf7YTM:APA91bFoJ9UykjBCBco_OY5H_hMPgBkdEsmUM4c60q3yB2Vs4_6DQrm7f9huUhCyKp8fvYgQJBkjNszOkVJ8vqZYAIMXlmsvMD1EBdWluAbdXVXLS_QAXM7OgD8MOoZ3gCjQMsIuCSYI', 'BEMuWG22XyS85P2uw6LVos78VfBduzwBqaWUGUiOpx3HNNdqktA0e+blQA2D1gLidS839XJgOkDCxUSykkBZnNk=', 'WCHRiPaUtaYZi8MBnuqdIA==', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-29 20:19:05', '2026-06-29 20:19:05'),
(1970, 1, 'https://fcm.googleapis.com/fcm/send/dsYNOTWNI_g:APA91bEuNNAok4ya0Z7jhebSKoAdNq7OISFv2cmBcdycX6enLDmKg_yDqpB-WmWaiidd9FNJvi5uDvsH9yp7m_Y0w9TBFVQH3JX-b7JrGP3HF4ye9wKSV1dqPl1KmgehiF6arXlYk_Xc', 'BJ+WES77nR9GQ1lmbN1nxIheStQcPh8LbMhRrEcLAqwt84DYVFdO7dv1kDbPI9OfF5M/Rk1rYMvhxtCA2rXnmPY=', '1RgDx0RtEVxnEPKm0usEmA==', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-29 20:22:14', '2026-06-29 20:22:14'),
(1972, 1, 'https://fcm.googleapis.com/fcm/send/eDE2TnpD1A0:APA91bGStllUqmH1FznZnwvaEt2_-ySnQ-u1qUttoaXbyGlMwB9FKIqJlARO-KkNOQR1x_TG7QOGMfVI1JmpbNaH5d9Q9IzAJxDFgywuBfKUcSMmQ5L_RpKgDqHVUdl-8BW3ayDZN_od', 'BCSj8WK4UyXluat34oWwTWXv1CEtccyhUXfkMUcLYOsStS4/ndIio5BPXvD5Jof5+ApkcMNbnAj7sSmL2kugQuA=', 'n1gw8EUYSf51K0Q+3mpfgw==', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-29 20:22:23', '2026-06-29 20:22:23'),
(1974, 1, 'https://fcm.googleapis.com/fcm/send/ejTt9_h6e8E:APA91bEj6rMYLlUjPVDiHzu9DBIKGMNS9h5nz7M9woZdrg4I-GnfuaUxpmOCJ2GN77mD07eg3yMDRPSvjPpRa8OgnpBVEjU_W3BfNbqmTgRuuMiAgP7-ZmjySw9JJojbGpf6lr9mY_QS', 'BPeGXKw8BwlEjOyLCNNc3pvr4mR33rAAlXpWygnMFXp8XOc/tnjOkl1ERl4SKI+odLlZoVm7kEcIt6tRHeRM0Ek=', 'K2WJs+OvlHcoL5VqoD4lMw==', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-29 20:22:55', '2026-06-29 20:22:55'),
(1976, 1, 'https://fcm.googleapis.com/fcm/send/ex_wJmbdKGc:APA91bFuqUvjvTCh1J2AkjHibdcnze4uYgvs4aH8ENSEFU_v-CybVSvQHqSs9ZQ9wef_4Mh5cHfltGfBLsZe6SK-rVn7dQest9DgMVRAR8y-GFKl9B2VWRl0Hc2qiv5fUHLHj_eTfmmK', 'BAHN8QS5QjgePzt9qwT9t+O8VhLKVC94On9O6mUgneKXwJW5KaSEoOy6MGi4vZShRy4e47bs4elbixAfIUlq7vI=', '3AQzZPneFrZ4wOdfGAydSg==', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-29 20:28:25', '2026-06-29 20:28:25'),
(1980, 1, 'https://fcm.googleapis.com/fcm/send/f9u9GC9kEoI:APA91bGMiKLFHLU4CXgXyArcuBTPjKKCWbVnSSiWZ1Zn0z0Qjxp8WMlgfDp205-uxxZuoB8HjwHxQArmvXmp88cZzcUi1qMeQW0WgNG7TKgRsuLOE0VSZdzcfoJka9iguFyRBsaDNbYL', 'BPATnvL8Q+wGyr1bukR/3WhmplURaM5VjRYunhgSgTORmrlmoqF08Gy0d7wmsfPaugpw/4v2G3dKlVvD7H7p0zc=', 'TLmBtP6ezdCWyMg43UXliA==', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-29 20:28:43', '2026-06-29 20:28:43'),
(1981, 1, 'https://fcm.googleapis.com/fcm/send/fmf_kdRGmTY:APA91bHME4mHUXQmpJg9U6VYYydpzU-sPHEDSwn1GTuUeXzp-CAbiFzv0K5E0hASPCapNG_TbBI1m_UqFRH3PVoIR8anS3hGOinAgCytx8owIAY81I2aDbsF9-4hxnSFQ-qBN2sgX5mu', 'BFSTvpB9c1qbsuggJ+BvMp9sEZkB3cac75OPMzvjZMxPVRmrzeoJBgdN/3bHrzj6GmMDFWZvTDBgr0Ie7YnWUv0=', '+NKBTNu/tGey+DsaMOQcaQ==', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-29 20:28:44', '2026-06-29 20:28:44'),
(1982, 1, 'https://web.push.apple.com/QPpCYA7VlU40vtw2Xg3XbDiyEL9h-giCpm9X0C1my7htr5JnAw7M5GHcYBcGYhUhHtA2JLXpW4bkc6OJ1hIr1jjlyzXpvRuclqQf0bnqeoKCqGY-yQAumr4BcyIZ6eD6NWDmb8L4Dl8GH_hZTw0Hl4HEgVKPm-axD4N-Q9fG5O0', 'BH/JMkH8UbCQbYC2DFy/zsMuE/M6aD530cCh03P/hD6LJuLor6mCdy1Kn4gVYHJIPuDm26xDSCUO27tGLskbpj0=', 'NqwI7Vj36qxwXk0L85kJDA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 20:31:23', '2026-06-29 20:31:23'),
(1984, 1, 'https://web.push.apple.com/QEdiBGX3Yo13d5RWV-PDABPBz1pGLgknxXMuzPKXFjgWr4U73n3jP89kGc7-XEa6itQpVYck3y3dkZCNIPQwBEug9kwBWVaA6yWKcQL-9NKhOiefSgWdCj3ZHUtwn0XvWQpzoT8tWIyeVWMDG3M7g-4J87OnvB2D15n28Zx0GaM', 'BDPtYsflLeCx3fTGUDZqtIIVQgAhcoXcVjYE/8XQU3KmeSB+umIvI/SAuLuz9HLk7f3odncQaymAWuQvMC/jiE8=', 'wSGe+2gto9pLRj6AnL/wMA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 20:31:33', '2026-06-29 20:31:33'),
(1985, 1, 'https://web.push.apple.com/QKXbftjZnKVyo6alpIqrG-jd3ZCRUkub1zirVKurUIFmJGgKdPF01LXxi-ncwLtOEI4p0rlFBN60kSkbX_39Lx7COLJVNVYXx19Xu6Y8yDcZ16P6OcsunY3mT00NRrezvPyB-54erPWAz8NLtPDIIYtrdFLv8MDaFKX3Lw5mGtM', 'BGbp+3i7bzky0q1+PxLfRSmn5/jqDZCQqZMMCVZcQ9N9Q16St6Qtt44bIEd/6VglEcPV361NsYndFqueYpgGBzw=', 'XnktZy7rd/jXxwnIwCvS1A==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 20:31:33', '2026-06-29 20:31:33'),
(1986, 1, 'https://web.push.apple.com/QPKiM9z9f63m0ZeV4j2jZY6aOp3Q-ovniOEWWwebtpqb2TRdywElC4LqiQ5jBR9X34kTjLq0ZoDRbDdoyT7M9xghTDdgC4L3fr1cqzRCPIyD1jxyiCySGIqpexuiOeUdBQZvZzW2cXmhw7xs1XVKddZT77m-zZ699QfuYvRRSnA', 'BEpRv0nuRPVr8gD9gmdKwytpPbO5qJ37O3x8n47ptmTSzlAFjt+YeRGzhMdVZgyWmLd0boabL3mecDFu1Ji+ylE=', 'cUFlq4WHv7rUuvOcLgYP1w==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 20:33:21', '2026-06-29 20:33:21'),
(1987, 1, 'https://web.push.apple.com/QD4lYye0nNn8Y3I3BXfEYzYUVWYz-baI41kMyc1XDcB2IhZq5HVOw5i9r7ByYRd1CVG4ml5Pvs6yWHicXmYinHIneLcgacLjZ-qv6ED1fcdT_ARTsW2VJwHH0v5WaQPm02KNkfIqrQUo4SPI3ocElvgcfbdETcsGeUDoAXSUbNw', 'BCy5EWWWz0C2retw80C+zvm4+MJqb2xBlCbNIyUXHgnHJhXFBXRQidPRrcTLL74GmDSHDz2BzkZ/PwNrW3+yS1o=', 'VJWePjZbq1Od4D5EJLZIyA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 20:33:22', '2026-06-29 20:33:22'),
(1988, 1, 'https://web.push.apple.com/QKLLi_sNOGiRk0KTVb6Fpen5wyP_I3aR7M9Y57ZUSsyupSoOsGN7d4cC7Jq6u6qF_-JouY_0GwYmNISfpi1IcIJUpVrr2ajXFeUZoX5ggTSr692zEdTIp4Ffyrwd56XGpExhIgzGHD-fL0jV33azVENGXl_-2zpA3-aJMRIQr3k', 'BIqO+JXCLs3RGqC0YkS1+Jo+KWFlt3Ew3F58mFf99/UASMQPtyw9ExmgM/rk7Vpi7n/jPAvoUCoDmW7m7aCRJNM=', '7da5TJalsCQYoKheDVa+0w==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 20:34:17', '2026-06-29 20:34:17');
INSERT INTO `push_subscriptions` (`id`, `user_id`, `endpoint`, `p256dh`, `auth`, `user_agent`, `created_at`, `updated_at`) VALUES
(1989, 1, 'https://web.push.apple.com/QIfqYv0veMX5TbxQ-u9YGZj1gTOIdFILp6U-b4aqJymoSEzxRWT705kdJHopDHx6nJ8VRuKxr5V6l8d6MoXzEtdVbkxUM6FYhfgjxkFEbMI6KUg2Rl4bOjjQO7sxjAMvGypAEFvgqhKRWTu4f_nzcGko9KRwOFOTRg9oOPasRvU', 'BJ9SXJTUZ86S4gJ4MmEoE9aW9guVXIOcf9tuDZtTvXnAmjQRCVtGhE/6IuIaks19YlshRXkL3g6rE5cpi5zbZPE=', 'XVXG4ZVTWLZbX6fKFmlhnA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 20:34:18', '2026-06-29 20:34:18'),
(1990, 1, 'https://web.push.apple.com/QAU3T28bBIBMrk-mNxDc4bq-36HVBHZU8zqkLhPdIvYTWYUApTdHiwj8DuzTQUyPtGMP_wDxR0SWUHkXceje0zGuFIKL8jeFA-h4Yl3mkxjKITyyXRazGwOKI6Xku-4oK8desGsAYaRQ875qPW963msFFAWsCu6Ml74byMwkQI0', 'BIWhiNElV9h7DSJNd7BfptMbgqFzgFRZ6EOmbuflmjZ4jxn1u59fnoagoc9x8VZptGEmzjPKJ2Lea1nz2c2dG9Y=', 'kOT3SDDvFKu6fhF0hv7HUg==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 20:34:50', '2026-06-29 20:34:50'),
(1991, 1, 'https://web.push.apple.com/QFzTieRxSH4jQI5aQCStexTnEbCLgcbPmVo85rurbVmaFIO_qDGSc6fz66-FasamR74hSmEoz4A6X02LTmYeRJl-I5MlkJSEUmHxls9cWBFQ_t0v_QHVR7L_pJStvEjWmRFuXHj7HxnmJfoV10MmeWLWiDAJf4VvGcvtWcNEs3I', 'BPm8xfvWIraG/EdRw4sR7znOyywrypgQFq7+vlNrarimNDXhwaIE3737R1I04SwBpbP9denxfvTil1iMOi5npEc=', 'uYxqSbC1aYNU2refAKmTTA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 20:34:51', '2026-06-29 20:34:51'),
(1992, 1, 'https://web.push.apple.com/QLjVwTA4S7FyUX2qoOBqBJA2t34wFO0o_tOB62qiseB5SuinXwKIUTSEzicc2kL15LjGCGlPNFs3O1lc6b72AMp2aJSY90FL8FJJHOg-qauBKRULbnttqo5XT11jrWhphgEzQdy-KHX-IVLZ2WqsoSrFqkuLhaJVd1S4gEYhZzA', 'BGA3Zgsw8sxlI+G2jyf1oeOYtZ4eFN1zyHAssLGkPEwLx4SGCmMp7wXsZhSUGqXURSejP7paN1xoPmVxOCmzEcc=', 'A+5zKoVjfxcedhDGqO5Vvg==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 20:35:15', '2026-06-29 20:35:15'),
(1993, 1, 'https://web.push.apple.com/QFKVgfghBcU9bvsccondluORgZV5d6yHWU8K-GwhROGJoNIK-4S3t_IYVuv0RNpQFs0usabd7HlIr79AL0wpsrVZYD-9LWrUVHbB6110ajVjO96OVwQZY0jhtxzc4oe3I_0ES4Ge8zBsFGy_pFDNqZIkAYqlJNkzgOjXEJDDS7U', 'BPLCQA9Jpj1c1AVELZTBttdZuCBlmhQF+lwXbZSKMYS9XDNDWdfa6IUz3op+0DWggqGIQJykCQo378R9w7sx9pk=', 'i9+LEfda0NF7hCttYW3yUw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 20:35:16', '2026-06-29 20:35:16'),
(1994, 1, 'https://web.push.apple.com/QBV7U2AaUFW5ETGNk4lSDBzkn3eBYUc6r27JaNWtxL0HC4SdWAhMl23F_0QfBrlZT_mZJ5fCuYXQQExkoUTZpdrJbUKSZl2XBzxzGX1RVi4IRI15WjDPdqZQe8bbgrP9UCWgPMVvhTvuRlk_GjXPq_xSullPccGj6GdAx_2CYrM', 'BBwbDoGfRtTEi0v7AMSfcN6MxdQgpGbLC63yxvGbWt6S5Apx4HX44Tx7z3nKzdWXPGJqpvJffbteWxdqPOSqZzs=', 'Vx8Uw4O06xdw90d1AZGhNw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 20:35:22', '2026-06-29 20:35:22'),
(1995, 1, 'https://web.push.apple.com/QNeCitrAUCf49crtab572ogYevomFjlgTenklV6JFc1-AM-ghvGbCUXYjQJDeZBt9mJYrzq_qrOnX0mHj5Ts7mz2dpB94STh93hjasslj4xOJTKjbCSsW3zCqzFd5tF1wsNXELULBtjkZ9HpEY4mF7IXrEoDjARHLSDEZ9kWlko', 'BN2mVXKdROBzgHzNc7VCCweVkffIiuINtiJQSuPvQkVicBLVLgGMO4VmgK9e29LVYyc/G6eKNWJGKdgnF8S8SFU=', '3ZcAc+LmKD8on2jxUQLMEw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 20:35:23', '2026-06-29 20:35:23'),
(1996, 1, 'https://web.push.apple.com/QMnhZUQaFotMxP7QUWsjYm7W5_vQ0iBSKmMEv4k6Oj0fkRWsghimHf6BS7dVG-Q1O_CA6X4PxSgDHLpWba7cPXnXsXWu78l-5ubiM15JAQr_KKLfQQkJrPGb14G2_2TorLKtEIT7me5b1zJ0YOM4k2e5YP0AXdRthvdv6qiVw3g', 'BME56sOb66t3+QvCx1ljUuAJCkhWenpVECIN65sn2Y/Mn/5Fr988lniZaIM/E/KeZB5IL0zFF+C/mrpPvxEF6cA=', '7A2R9rUBS64QUZv9jynVAw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 20:35:29', '2026-06-29 20:35:29'),
(1997, 1, 'https://web.push.apple.com/QDLGnXHqDss6r11M3JW4ONMtZ36i5BxfKrwnbf_KSTVzj52UEDIQaTg-eE16MQqfM8-Il3WaqbXgmbOdFY5zOp-dni1ih913hGo-izAFRbHODeELheKCQ9juG5KSkPN4YEThAH-c6_92lf1KteNUy3ltb9Lo9k7Jo1xSBJy26xM', 'BNuMD0SX/eNIoWpimD+cTirQc1FJxR3pw7EUO94+3WwOwNnVSmb+wtrc3z0ix07ZC406Bfbb3ERPdGlM6EFwETM=', 'Ii50kE35gH/jv1NAXBdY+Q==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 20:35:30', '2026-06-29 20:35:30'),
(1998, 1, 'https://web.push.apple.com/QBavccO7ff1PmOWxDkWBXu5vnr9MhnELXwTnWWzFK2ouE942Y_wHC58Ajfa4R0d8OQ69chP6n0_4u4q68P7ddbjjOYy4sPsGw3EbueROjX4YBJNoTp2MebcyYB9f5-R0Sxcq9leyyBbg8m1Q5GdizqF5wdjOd0oH6z66XUmtfJQ', 'BCTyYQt7OzygMUZ770PRQLkRL+Ys3at0sWtBzUexoZhIAmW70XHWB4QDD357Ku0dYLP2EBDqG8RkOIxKEQIsfYU=', 'CnrYOA+sEWKjMzhqOBYGCA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 20:39:31', '2026-06-29 20:39:31'),
(1999, 1, 'https://web.push.apple.com/QJL_s8syVirz1HrEbKuN6Wdm2rMrv-4mROy8t0DbQ9J7kURU0-tWMrs-9uptj0hBDyRlcd0K-4GhQaB07OW6fozysuBgc5d3RFuVaHus0BHywDkTIIKeEbf8lsrGF4IVd8ixWJRwc-M-zzsSV6mSmszt2H2fs1ga3a861-6QgPc', 'BNwEl0DqxauNa1uASYNHeOCz5gEBv3pHLHpDQYPXh60/1e5iOnOOCp9/0n3AiEhsNUbO4lJAyVSyTXMr6AEkyEY=', 'BJK+HzB43d1NvP/d/YSsNw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 20:39:32', '2026-06-29 20:39:32'),
(2000, 1, 'https://web.push.apple.com/QOvzbsj_qeDUsJ4fstgahRgUHNuuXO7lrSH-eyoYQncooYtSM5GXHe0atdeqqgGjZV6hE2iWm9wjbFXCYMuQ8qQKGXJrcZg5WA3G8L1njcBONmIyViPyOcVJQfkL2VZgPzTB3IfqkU7ssICEMilOPHDrsZhCpll_54SG6lvhnTk', 'BPP5QlS6SIZy8tQiAG9ikjNu5bOfH/QxUvFaPt7EVfrQOVsgoTz29HMaFcA4ufbDDGR1SUy4i0fjoxZ3hOMT0JA=', 'OijAvc5bB+ojoaQSwtx+2A==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 20:40:10', '2026-06-29 20:40:10'),
(2001, 1, 'https://web.push.apple.com/QLbUm_KlN0OXIpBpOB0ZexYeSKJ67DCJ1VpLJoT4IUqPub_yj3sEf3n9iFMdVWs-YnrejGcpNTx5S2vfkdUdnqrC6JIjqL77WuVTJPxgHryqpNtirdX_gE-mqCZI_uuebIO2N_cRWO6mUGgAyueUE-RdF9w40Gl9IsAjHsIFQA0', 'BDeXYUNslfr+O9DeNIntbQh/i/QDA9ddtoJlEAEKdvR1mrJMDh07y5ioA+jzGm2/vQk35w2exroA2512TRsBRLE=', 'gTz6/dn/kRbOfTH+oMaaXA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 20:40:11', '2026-06-29 20:40:11'),
(2002, 1, 'https://web.push.apple.com/QJ_Tlzs9_HMwbsz4xBh1nUvqHBYQry4_J3gV6cnSn8H-lFIcUJxRuQBoP1cub62NXEvFqwp_s8gaYoDwks7Rk_wLEtlkVhiNdgkqpWodEMtAxunmMeBJOMJ4bomr3hQtM4rxqsF5NqFY9VUbMZfJzBUIi57qhlvvZcg7FoRUEEo', 'BFK3ofxUkn2Hi89SE/015VZjCz4AfGjaiLk5gvFbxYxNfQKYud/OkEg1JksXlmkVtYuOblkk+HPKUr7IxPP8UZc=', 'xWyhZ3QeJUZl8wchBglZ8A==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 20:40:41', '2026-06-29 20:40:41'),
(2008, 17, 'https://fcm.googleapis.com/fcm/send/eNmuhR9_hRM:APA91bHEKPz2-5CAgs9Qs03BPw35NQRFdt-kqwlyykeZrfwGZNX30y0WCYWaET4OjJoQ0erR3imESgh22ihyH-CW1yq29ZFNBkAJ-7KyAZYqLM6BONPH13fMPRiZmQl3SW5eBNcsPcPL', 'BAi21mIXIjzWQ7+01gPzg8x3p+90F3cZY19B2wUF3E3I4IYVp/scuQVnXr5onjdWicAGq6K0yPqZT074eXZc/IE=', 'wCwPjELnLiMuBq8QVSErQQ==', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36', '2026-06-29 20:51:22', '2026-06-29 20:51:22'),
(2010, 17, 'https://fcm.googleapis.com/fcm/send/f0OT7akW5H0:APA91bEZDwZ1K4P1soLfXQwaHAT8pob8eirA1RLPbiyVFloiRnpuV_y0KelUcWUKRMPX_6BX42RMEFIgMdsUjjIbGvJMAHnWD81_kxig1CE_reLJSKZnEOusay96YJP0tN6qqqbenfbO', 'BGGTlqdT3kT56BVcSyUwiYA8YcP6Lrf1pYH2rVscoDBMyWt2ba6IRQBDYJNxkFQvjSSIU4MDwhj0k5xLuOIBmT8=', 'qoUFGiIFZZ7h63xfHPD/Kw==', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36', '2026-06-29 20:51:27', '2026-06-29 20:51:27'),
(2011, 17, 'https://fcm.googleapis.com/fcm/send/ftpXklr1DCY:APA91bG9UzxszRPrUN18LZQOd1wKky6YlplgAsbxRleWKq2YaS0pxAnjfnWafAwnQ19hpD7wwz5C1Jr6tNJZSNY9_RbFHlAIAQFrKJpIcjfcLVe3COm6V1de2SC3cOJv553k6PZTc-3G', 'BAAkOp2lDPbcdtKQpKc42NbXEYup4PjJieSWPJ0vuqiiZuAn4a/2frqkqFHkF8U/SRq4+XvIrbq8HhwPw8eWVH8=', 'MPbCVhGe6ovB98C0MMDTbA==', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36', '2026-06-29 20:51:27', '2026-06-29 20:51:27'),
(2012, 17, 'https://fcm.googleapis.com/fcm/send/cVMYMTw4A3U:APA91bFeKUoEiR_XlwMEIxirJJd8YEWJ8Y7uMRVGL8xL_jDbyTpjAxAhUuNxuUpbFpcrQnSmBMUbq4uSoHxup6rko1uL86Sw5DQ3_JPRjsZsd2hx_FOF3rAMWIDWC6iKo7YF52aZoSCV', 'BLh3eCS/8EzIyeACeHiUumDtIb0vI0LGWlqkduTNdl/8vXta8jsOC6xSbB77KKb7TDaxHvFaaURidE624nO3t0w=', 'QO8dFdMB+G6aTAM0vJWY6Q==', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36', '2026-06-29 20:52:23', '2026-06-29 20:52:23'),
(2013, 17, 'https://fcm.googleapis.com/fcm/send/c2rjLPM8bFo:APA91bFIJBl-DxjJf6NPY5dLH_hmhaQpge14sRubyGV5kuVhAd1y-n1OxThp50P0NrQ-kad47MiWilJRlDHsp4D3h1AciWS6XbxrrXtBbfMzpiR1V1iZa_PDs8hd4eA8VUOZXtdInrcC', 'BIcdYJ/fMB2Jen8zq/VVU7YRDZOR/Jxaj0QvDgSL8txZ5BRozRr36hY6qbGUJQ4d3LTHY/rpW/l8nY6bZBHXviQ=', 'S7D8pMj1YMSG5wqkEa8veg==', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36', '2026-06-29 20:52:23', '2026-06-29 20:52:23'),
(2014, 1, 'https://web.push.apple.com/QMKSDVObCBoaFmneODFAiWqi17nCdLLaVmOS5AdvqvHEZRNYeGanS9VVoUXvj37drpFpjweaSJ_YtKIy-HS51Lt9_PsJBImGerzLDZA3nm4vCqC4JuCniut4MaLDCZU936q_mRNbVmz4P2B406Zad8P4SdaXTA6_WWBqQWYt4Yk', 'BLzHbsXQr4FORLLNcraZa2MCgGYax3VkIbz4yK3T6Jcol+uDitKw67sLs/90QyXIJnMKmV9Jx/vRjarQlFoWCHQ=', 'q2a0d2r9VkkfyBatsbOTxA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 21:16:08', '2026-06-29 21:16:08'),
(2015, 1, 'https://web.push.apple.com/QJhGL9DjakTs-4vl1XKYq-To0ThjJqYN0MbFdnmf7Y1p9FqA6K-CqEd8tVZVgfZ6Rk2JjoyZCvR4UWnTQJ-Fx6CHR0MLl-fatWtl62-yO05783BcfC_i9LScRJXWzH32ek47b7hlrGg7hqxfCvHfa6oSyea8GIycCwKIO64CA-o', 'BDQbnmh08iOFTi9M/kD1EG2mh9NEU6BZrmjBvXMPDF4Hh2fuSXicoTqazx9wdOt5E/0W02nkYHA7ab1E7XByV1w=', 'n9eGtyQCt65gsDN2wSsOug==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 21:16:09', '2026-06-29 21:16:09'),
(2016, 1, 'https://web.push.apple.com/QNK8IkjaG1cK8OZmEtjXbFfydM9SlQYxSa37U22-BLvdtjJdFeQQm0BD6KTeYyxsq9AeZczzqpPeiR57DJM2TcQQB-2ejcknl-LscbL3J7aeNNqLpQOHQunsBOywcQPGowvx7Td4WJlSisOo7rUXl8v-glU4DmC_4CfYHVnGH40', 'BKIljdMmuWmE27kQRTV+Nkg0sHOKv71u/VM/raxaEEqI2iysXvbGlSf/e45E6g9pAYlOWhuaLO1PwqtV4YxucUw=', 'oRp/fGvjBqOccZt7QIAs9w==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 22:21:38', '2026-06-29 22:21:38'),
(2018, 1, 'https://web.push.apple.com/QMqpA4cfkniuP9r0j389nejS3b8q65Jk_jXJqCAyvXFzEMU_eUkjVJWBiOLG0zBv9J_DIbx_fOvUZ7I-UrJjZ2UFRAVRuyPfqEeEryl0NLEtt93p7zUKhU9um8K7m_OnbWhyI_bg_cOwSWFAd8skPacT5mW9_Pt8o6thpRPqjPA', 'BIjJfAQ2k19leDVhw8DBwJvZ+S4Lb/nmwQNAsqq3Hceoi2O0KZWfR7vyURrdf9w1De+Ya8cCckleWxhP9QQ/yRM=', 'XPki+XTs4va720K0LxiqZw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 22:21:41', '2026-06-29 22:21:41'),
(2019, 1, 'https://web.push.apple.com/QBvVtbmnJD5VggrwQV8YZ9YQhdWXefMyIbyN5dhlqKheNsidxc5Ie6GOHuE3x8q7kN3yrOeBpZlfW30OL4B3Dhv2xhoafLTasOz5r1PuCmsMl9CC4D1Jm7ljLNORsZFCYKYvzHx_wzGHFPpRYpIWfTWjuy8XRhAnhB6GNIqav4Y', 'BKtFQhTTnys8dRKAFdhNwGR66DY3bATWJ/5NlUN1hPfhNuKcxRlwFWUwyWChKACoappKHoOwBHlrmKRt/156mf8=', 'vu9w1Jpxv30yLsCTXSih/w==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 22:21:42', '2026-06-29 22:21:42'),
(2020, 1, 'https://web.push.apple.com/QD-ZdP7Jh-EvnqxnkZAh4MNHnEmzjJ69Q4Vw_dSaaFAwIBYaoUCz5l7AwQHnhGJTf98bOHqgk6zEnvOq4-krHYBZzWj33ebTUrcrQEhRIIuNaH1evHaya_kfDsdInzZfnEqBaiMjvK8uLOKesACYfwx1HxChIaJc-9YdqlvXpA0', 'BLc0RQOZT4zKNzjsS7BLppg7tI1U28dCthRV+dRXRYS7O3yuvFD4hIzyzXmtHhKbzLUKEmB8MXfnobGZ3BLvoX8=', '3e3msoMjJfQumSKawmY7BA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 22:26:15', '2026-06-29 22:26:15'),
(2021, 1, 'https://web.push.apple.com/QO70T8d9w2Q84avEDM4zhqJXMnjK7hXzhAGCODRN0OqFy24_QbPNEZ-R2LddFaCr0n2obE_r_q8Nnwyt-6dmkLPKTBuXmah9suzc9zVL90BDxsyJEeOY7Y68Ubzy-1vXOjCJeTpDO6ifpj61uw_n9qo57WLMyukXrTVRReQhBfg', 'BLwvaeYKB6wwBXCQ38obFuMTtjUbg0m7K6O3Aj2XhcpWObuiD/DD5VQ1EmAiVg04rGDOainUJlKYJZaeRRtTmvs=', 's/ic0kdEEVOari3AxmxRFA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 22:26:16', '2026-06-29 22:26:16'),
(2022, 1, 'https://web.push.apple.com/QM_8LcPDswkyuTVwgUPOqfXg7B1MThScQeABQdr6AHNUGnMGDskqkC3rzVkhOjk3mfvFX94Xc-mX472WcfUZf7gK97GR0LyFsOQFZZCZ7lxsZsYV4T93Et5FVw-t4x9_CFKm0Qk_ww9aNnOase_I-vvfFmIKGALLK_hPuM-uaHk', 'BAKJxclsbz8IHifQkcwJmMC/D2K99mijHXrJelybPazbTKNe9ixRjfvzyIC8Fw8mUURV3I6WHmYvYLpJ0uCuq7k=', 'VMCyD2s2V6qMTgphRu4lhQ==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-29 22:41:26', '2026-06-29 22:41:26'),
(2024, 1, 'https://web.push.apple.com/QKOPbhZ4r2AMv9kEGPAV8EIxY0gSEQQ9oZSjtkEBXGLvsZ6juuDtDEiCHI1m0IsVNj_O_zdmZ6CGM51RlNDaOWoztIu1s81ukTa7h_Tf6bvTnqlVhYRXQn9LldKLTnZQq4pheYjKZvMfOV6-OliNM0oSueZ_wWGKLsIaG-cSBkY', 'BNzx8wcrSkVjmXKl/rHw+4o2wna+g4jYyBx3o7ka7124PQKiWMa6RuE3IXUt0lODRxzBddIk/p0mLevIulUX8Zw=', 'gpzJP9VdKKRzMANUUxJ27Q==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-30 07:41:56', '2026-06-30 07:41:56'),
(2025, 1, 'https://web.push.apple.com/QCXfLBkePvVd_W99C_dZLDdAdlSdQ4goan8am2zPihVjAaajFjD30FExFsn3h-7f13MMUJ-J6l0YKLJ5GE5___KE62j838fQL0tqQofrv_JXdKXpLdwcozGlFv0oorptMnzdQViUioQplgvbOybgC08iKvs_Cng_RA4nI84KLn0', 'BEyD+S5owG+uaJhmz038iTOhY4W/Z0Xrx2iBOzOMCQgG1U3ZZ328u9nHH8+zdAWfdVXoQQcJp/vF1WbCp0tK7Es=', 'SO+Icou45VSBKLx50/xlbw==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-30 07:41:57', '2026-06-30 07:41:57'),
(2026, 1, 'https://web.push.apple.com/QCfA2udqJXmtpIAdrZR6_6qdZWUk_pjKmdK7bVjPYsQitiZNVSa_A8mbO-cUD7O5h7r9wu7BKYr2MWnVlCG8FLz_OA1rFwKK0H3PuFaLFyZktGUAXQCUcW43T3SBetf0zXSWuf35SHzC2cB8il2Gv7FJPwHsaLvsU7Xg3Yi6mkg', 'BDGW2V0kW3fRMbavt4qOEgi1IVcCyHDgp+gImSID4alXJRPiw/5yXW3AmEEhmQ3st7VNhaXULtNf8ZhI5mpWSrM=', 'aQkhSmeRUARNKcKWeep7fg==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-30 07:42:34', '2026-06-30 07:42:34'),
(2027, 1, 'https://web.push.apple.com/QIqE-CayRhenOP2avMgQqCgz63RpXXdf869jysrgiHOd79kbnNt41e5QvPGxNQ0eN1rzy-LAWo-3_6YLhcZsooJOWspaX_73Q6-mH3rau_OSGl8RoXyjNYNLkff832Uu5mUq87_5gHQbpVxzDdfLO0bAbHY9fYhSMlRUJjwmamk', 'BPQ4YLTPXtFxhsnbj1dTYc+cgSlvWRb0tJqyyqGieALPDjiB9XKqKvQnzmmTSlBe9+YTmv/iPq0ACIqov5i2IY8=', 'wOxE9ZMHDv9NQXeZY826dg==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-30 07:42:35', '2026-06-30 07:42:35'),
(2028, 1, 'https://web.push.apple.com/QJilsrI9OaZ5bijoS0EPosBDYlGw5HCCGhnyLkQinWwMOkcDizT5taGpEHONuiHhr1z3wQR0Q02bAw_kdqOyFb0sbPRWeI1clI2LRthUtQbBEg8wqn8k64DBVfdXwDLXeIib2OT79cnFYOBLnzY0Mc21XsRop7fkTUkADw2-NSM', 'BMVPm60GTJtc0/FoGXUu1ptZiH1DpFAdUPzZWojRWsC4/iUfd+eWUMvUGVbJg/qMYeQFVhndjL6SUlsCnzM0Hjk=', 'ORTy/jhdG+sGoYZD4D0SrA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-30 10:01:12', '2026-06-30 10:01:12'),
(2030, 1, 'https://web.push.apple.com/QCQDr6-U5wYcCXKua1RwMjrs5kVqjNMXJpzRSMcYgBZwi6Gpo1xZXZ73cEKa4tcpk848-0C0TXOV8kvhQkP-5m3P0QvfGO_9_qff5oKfNhh3lZYfcp4_BBkKezX29mkWl4ZAzHbqj_0ooxsiyxnf5rGGzGDJHRWoaCzFCtlcxsY', 'BO7hIvlr8sIcPRIbpqydHhygctMXWlq/7ZVDHcThR/mb3HmXvj21xz58qJ4CS9zFcV5XteomdCNF8vHa0TDQ1tY=', 'hFXTjfdIL2pKAWQlLWcmKA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-30 10:08:20', '2026-06-30 10:08:20'),
(2031, 1, 'https://web.push.apple.com/QL0OpxIOohHsvqxNAMYvZwQ6z_2qX50wlwtfq1r3XSHJHpYsNmviIsCKTBaMyE8cgjeIy7PRKElOjThifHnr5Xb4vUCbjodSQ4X5tjV5rApy0Y22qI1fbz9BMfh6BGKboAlXYfpxVoqhTIv9mHnd9E4mlBhva1FOY3C2dgBVKfk', 'BAOMqVkaN+hKJJ+u6yAGWN3nJUvEJbzl8iokmi3Sqn0vGZbUI2liw0rXqQspyyyzzkqCIOCS1JFJzD2XoCm2U+0=', 'xlI+FmCj1FrZNY2hRtVqIQ==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-30 10:08:20', '2026-06-30 10:08:20'),
(2032, 1, 'https://web.push.apple.com/QGfkpnD5MfNY03aidhqVuZU9IlNSzwF-9XF2zLcXPgGf4XTtSPZZHBJXXeGEjqRFAgD-wbpknSFwAzi4DCKUfQEKCP1_DHJi4_LWGRR2sVsPsZtgaCGRWAr_GHtFLUjlMJcqmXvEKWZqPvHL5susxnUwBltqCM5Bf26WdLnEPDM', 'BHRb7OuVYGp06ILgHbzkitYN0Q7EJy+n+zSZ/lVghhon5Q7eXIe4PVihLUX/7KNT7rUAKO1at1VCsajNibw1Dfk=', 'XvNLXXSe7z+jzcIstw0uzQ==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-30 10:25:56', '2026-06-30 10:25:56'),
(2033, 1, 'https://web.push.apple.com/QLES1hZPUIyU5qTY1UWTbcwQzyFkaqB0Xvexvs0A2vnzGIWIJ6tIx4fS0TajTF35KZZd46MmWqXcv_OFtT6yQTT9L64UgbcREE9KTIVCTRo-f0P1uls-RlMAqvdXNvosdKZP2LNwTbBKA3kg_VKB0gBTP0TZ6W8VfiE2a6Vl3w4', 'BM+0vgY/ujX0SvpMsQpWjDiww7PTeB/2zjyjJspEm3LYVCO00hK0TYc0Heg6ldxfDlFj7ygZNqM7bLEPxRiH4x8=', 'wAA0jbXaNLRXMCtzYka6ag==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-30 10:25:57', '2026-06-30 10:25:57'),
(2034, 1, 'https://web.push.apple.com/QGNVLwU9afnVc_RUTnpfJFtkut2A5mM_PCOExtvWNrfIAXnSkJPX34nx3L8aDGFN9JPksuH2ULXrjWUYeGiNo7ltwJvdZUd9gWllrWWXZYyG9j6F8qTOSA2icFhbZqICTTYaos91Z88PkCAxzYOMHCsyeNMDetAe1TYFGIK9XVo', 'BESW31SbZm+4jfEBTTNxS6ToJLGStWXa1SwALIyJZJJOGnNdLxpTjC+RFdazM4E+5XGG49B4eXdH13nT/GSDwys=', 'uLw2mJzUgD4tieXGD30heA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-30 10:26:35', '2026-06-30 10:26:35'),
(2035, 1, 'https://web.push.apple.com/QCtEiqz40wGle9ETK9yCnpEO9oNlLkinNxUuj2lqCcMOk_7qT64eqnUE8hwFDZW9tvT0LDbk1H3g1L_VRR_sh1MF5m8EGscDk20gOVFGzMPjyfrqvAUWjuYhG9iu7BXp3IGG-1_HCw3qjeaUskQcaQbu30EO829pHr1nSjkyZ7s', 'BErsRqQTY8GyiKxp3uy+rYcOh/dMQWndQgjqxv1iKI00ihD5riFqkqTl1jT/Bl32RGk9YcffDmuQHJf2/q0T1yE=', 'a7hw4FZdNzezlnpKRO8L4g==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-30 10:26:36', '2026-06-30 10:26:36'),
(2036, 1, 'https://web.push.apple.com/QGK_JcPtNnOYqwK3Y6TLHaFCB-I70cb0U_WrctkpYMceL_e7a1MevtKlXw0PYVVG3XC5x_z5T3VgoCxUN6I3bFhFcCPUMBmjpyZtljqx57uL0og9IPrg0ELveLG8gfqBIUN4YMcYgUbkT8lf5Mcgm1kBQZ5-yLKKwDHouVpyF_w', 'BMcSFG6za/VK3RDKv/125iBXZn+KsgMK83PA2GnzIcYnhXejGNzoElzL/030WFr3gjWGIdVuPfcsQ00SvKCNugk=', 'wWRMKJTtWRHbTSRXKSH1zA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-30 11:00:35', '2026-06-30 11:00:35'),
(2037, 1, 'https://web.push.apple.com/QLCCXaG2D3zHwAUHt-2iREWAGyOGXbtRrfxNKvA2FSTHzyZ_PevMtAVl4Fkzu1rTnFXCyjEYwfwu7-m19hnyZWfjtxgBGRxmN279o0Ed84qnbzmWx8F_V6aoUXTiUuzupZV3zrbp3Cmgt7CO7msL9cuXuqkTLdLOH8DNLjz1dQg', 'BMeWdwj8OUXze9YW7b4SewfjW9T7XAzvfa/bDnT4ZqcmqrYPPskEtDP148DF6QO9gur+/GXsl6yXGeOvVb1/GDg=', '8PgstnFfzOAem4kcL+bDFA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-30 11:00:36', '2026-06-30 11:00:36'),
(2038, 1, 'https://web.push.apple.com/QFCwlVtQVux63YO93idmxDSM2seuatBW7mysB-6dhJ5XeBLxtUC4a91DWA_BIsKmnfbRRhx6HJeioKtl1jdQdUolxpSTtEbpiItcvfWT28Djl9ZpUMu4x6JBTLotXq0C_gxOf7KdpEA7mIgavy7xYDvAPeBXG3T2Pu9p_h9Xq0I', 'BFYzKZz+S+EODvzQKsdUY/QLj6hOykXlN7cTCSZ9tTk6oPcfCeXcEL9QtAs8oU5o0iKITuUJIwLMV8ZaK+PSQWQ=', 'RCeujEFLOF9C/Zv3NPmhRA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-30 12:31:40', '2026-06-30 12:31:40'),
(2040, 1, 'https://web.push.apple.com/QJxBU54ooq7vLHlEFyjdXxGUbwWs450NLcYyA1sHZw3qThZFscoC47FpAHP-YvXOXvGh062LkNISyTEjKJ8Y12tx58kuzmekHKFXETPppgUzeHQHdzzHe_No1sSg5laG8hP1oUTiki7VHkQvdBKj3HWaExZYol3iitxNyvpEV3I', 'BL+L4f14jzXxSfFSudrIMdVkeCwa3GyII9sEbNOEqdOMOxTtNNvjEZqoDuWJIR+qDA4SFH4bstisZHBNc9dbrdQ=', '2jqVRDP1vtRP7ztbXrlyUA==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-30 13:27:10', '2026-06-30 13:27:10'),
(2042, 1, 'https://web.push.apple.com/QNPd4jjRuc6PP06PquR6v_H_7WTINeAvnoNBcL-k8fEHQFJ7Y6e0JlPacBx9l_0raoxY-HPCivWnRfr5aMR6qktHrvH_Y26F8HVfObP1CKiXtSEqamOt51cd9YN_K7zfveNnfjdrhnb5M515Y0sg0BwPUdWa1V_QSv6UDz4lLSM', 'BEkhb6JdpskbzK59e7Nz2mPHoKpc35farQA7qu+eaeyym9ldH4yu1hz2+BVJ3eeOi4PPL4o8zXJx4mEqtCDBWxQ=', 'bDN3bNbOYrsWH6gVwDfm2Q==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-30 13:31:04', '2026-06-30 13:31:04'),
(2043, 1, 'https://web.push.apple.com/QDF-Wqk4FVO_sgvR8_j5RBtDqXlbf4tQMFUMXsEoqx40GW4za-3JFI1b2hZB9HtHF2ZG2Cr3JCe5tLQPl62nuS3Mg_nEeB6MlnqjedbXIYv8FkMsr0RkEI3WgLlWjAxWEU2icxafttcJP_DKN65mLvLZd_wBHNaSwxz0Wuj4jVI', 'BIPAIR4karqKTz//hANI3SUq4Qw6A3GL11mEvj8Y4LyV+nA9Mh6q6+8BCL+udPJfxgb/nuG9OrFUT3dp2qaiNvs=', 'zIFclCyBsF1PHkdWvwDxYg==', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1', '2026-06-30 13:31:05', '2026-06-30 13:31:05'),
(2044, 1, 'https://fcm.googleapis.com/fcm/send/e0VZdtV2Yyk:APA91bGyI3seFkVEtvbFkhbtI3fJHqQkOGHxfeu_eBxoFAjo6u37PcdZv36Jfin998D_C19qzZQA9NmHtgtl1egpGdG96N2kcj9xKb4_KxR4zJUXIsrSHqhEoGmP5imcJnMzWEk0M1-L', 'BGQlLmija7vHrto+RGlmcbHANQVUuIa0nYFvWqbY1LZa/ENE4yS5+2lN0PKuidKnlCEo2+nwdga0nfuOXOQe+Ew=', 'mCml+e8qCrerc89GGsLBCQ==', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-30 18:37:11', '2026-06-30 18:37:11'),
(2045, 1, 'https://fcm.googleapis.com/fcm/send/do1eIHYcJHI:APA91bEjDe1r8361RG_AezNwPpv8WPTeLzUO2YEl-meyLIk0ZX34KrPPWsTqNsFBJEkb0PtxzUWao0QshMizaYtG1p23l7EnJcV40LV3fQyOakYcmftyCIRxH_-BSv6kZxjurq4VtqcX', 'BCDE7jrQ5V2GFDg8cEOobY4EHbBEBrVm2KP1lnKEoQlBbLw2bGarHrGX2EzmcqNH3/O0l/5IH97yUH2eNMjURaA=', 'S90cN1xt1RKOE94J6556MA==', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-06-30 18:37:11', '2026-06-30 18:37:11');

-- --------------------------------------------------------

--
-- Table structure for table `pwa_update_logs`
--

CREATE TABLE `pwa_update_logs` (
  `id` int(11) NOT NULL,
  `version` varchar(20) NOT NULL,
  `message` text NOT NULL,
  `updated_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pwa_update_logs`
--

INSERT INTO `pwa_update_logs` (`id`, `version`, `message`, `updated_by`, `created_at`) VALUES
(79, '2.2.5', '✨ Performance improvements', 1, '2026-06-29 20:17:35'),
(80, '2.2.6', '✨ Performance improvements', 1, '2026-06-29 20:19:25');

-- --------------------------------------------------------

--
-- Table structure for table `quick_actions`
--

CREATE TABLE `quick_actions` (
  `id` int(11) NOT NULL,
  `label` varchar(100) NOT NULL,
  `icon` varchar(100) NOT NULL,
  `color` varchar(100) DEFAULT '#6C40C5',
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(4) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `icon_image` varchar(500) DEFAULT '',
  `min_balance` decimal(15,2) DEFAULT 0.00,
  `min_balance_currency` enum('USD','EUR','USDT','IRR') DEFAULT 'USD',
  `deduct_balance` tinyint(4) DEFAULT 0,
  `deduct_amount` decimal(15,2) DEFAULT 0.00,
  `deduct_currency` enum('USD','EUR','USDT','IRR') DEFAULT 'USD'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `quick_actions`
--

INSERT INTO `quick_actions` (`id`, `label`, `icon`, `color`, `sort_order`, `is_active`, `created_at`, `icon_image`, `min_balance`, `min_balance_currency`, `deduct_balance`, `deduct_amount`, `deduct_currency`) VALUES
(3, 'Western union', '', '#fff76b', 0, 1, '2026-06-29 07:22:11', '/ledor/uploads/icons/icon_6a421e7bd8a00.png', 0.00, 'USD', 0, 0.00, 'USD');

-- --------------------------------------------------------

--
-- Table structure for table `quick_action_fields`
--

CREATE TABLE `quick_action_fields` (
  `id` int(11) NOT NULL,
  `action_id` int(11) NOT NULL,
  `field_label` varchar(200) NOT NULL,
  `field_type` varchar(50) DEFAULT 'text',
  `field_placeholder` varchar(200) DEFAULT '',
  `is_required` tinyint(4) DEFAULT 1,
  `sort_order` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `quick_action_fields`
--

INSERT INTO `quick_action_fields` (`id`, `action_id`, `field_label`, `field_type`, `field_placeholder`, `is_required`, `sort_order`) VALUES
(38, 3, 'Sent To', 'text', 'Ex. Turkey', 1, 0),
(39, 3, 'Amount', 'text', '20€', 1, 1),
(40, 3, 'How will you reciever get it?', 'select', 'Bank , cash pickup', 1, 2),
(41, 3, 'Reciver first name', 'text', '', 1, 3),
(42, 3, 'Reciver last name', 'text', '', 1, 4),
(43, 3, 'Reciver full adress', 'text', '', 1, 5),
(44, 3, 'Reciver phone number', 'text', '', 1, 6),
(45, 3, 'Reciver IBAN number', 'text', '', 1, 7),
(46, 3, 'Additional information', 'text', '', 0, 8);

-- --------------------------------------------------------

--
-- Table structure for table `quick_action_submissions`
--

CREATE TABLE `quick_action_submissions` (
  `id` int(11) NOT NULL,
  `action_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `form_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`form_data`)),
  `is_read` tinyint(4) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `status` enum('pending','approved','rejected') DEFAULT 'pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `quick_action_submissions`
--

INSERT INTO `quick_action_submissions` (`id`, `action_id`, `user_id`, `form_data`, `is_read`, `created_at`, `status`) VALUES
(3, 2, 1, '{\"3\":\"10\"}', 0, '2026-06-28 14:20:16', 'pending'),
(4, 2, 1, '{\"3\":\"111\"}', 0, '2026-06-28 14:20:22', 'pending'),
(5, 2, 1, '{\"5\":\"2\"}', 0, '2026-06-28 14:21:23', 'pending'),
(6, 2, 1, '{\"5\":\"3\"}', 0, '2026-06-28 14:21:28', 'pending'),
(7, 2, 17, '{\"5\":\"55\"}', 0, '2026-06-28 14:37:41', 'pending'),
(8, 3, 1, '{\"38\":\"Turkey\",\"39\":\"20\",\"40\":\"Bank\",\"41\":\"H\",\"42\":\"Khh\",\"43\":\"Nhghj\",\"44\":\"Jghh\",\"45\":\"J\",\"46\":\"Nbg\"}', 0, '2026-06-29 20:38:19', 'pending');

-- --------------------------------------------------------

--
-- Table structure for table `sessions`
--

CREATE TABLE `sessions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sessions`
--

INSERT INTO `sessions` (`id`, `user_id`, `token`, `created_at`, `expires_at`, `ip_address`, `user_agent`) VALUES
(165, 14, 'e6cf7a605684100184d3fc29cacb5b4dbe6878c84914389370466b1db7e517b3', '2026-02-18 21:13:12', '2026-03-20 21:13:12', '91.13.70.89', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148'),
(166, 17, 'e05edc70137688052615931653504d897e06a560b9e449f314fbf07f2363b52b', '2026-02-20 08:12:52', '2026-03-22 08:12:52', '176.1.235.69', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36'),
(167, 1, '47b9208c72f027ba65995df4f3ec3a0b424ed0d6aff73618c8a2619eaa1d0726', '2026-02-20 08:15:30', '2026-03-22 08:15:30', '176.1.235.69', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36'),
(168, 1, '4e4dd31684fae64d3687d01f75eed898b2fe65ccdcfbacf27093a1c4e90497a3', '2026-02-20 10:07:37', '2026-03-22 10:07:37', '176.1.235.69', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.0.0'),
(169, 1, '3d3137169d8ae4d1a236b989cb8d424c9b651d72e25c38fc500b4f47ade8004f', '2026-02-20 10:10:40', '2026-03-22 10:10:40', '176.1.223.216', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Mobile/15E148 Safari/604.1'),
(170, 17, '6a1c7a2a8ed2861467e40e97f3a278b2d22768be308c6745062470d0f4ac6ce2', '2026-02-20 10:19:17', '2026-03-22 10:19:17', '176.1.223.216', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Mobile/15E148 Safari/604.1'),
(171, 1, '1e776e615b4d3bae9a55e6da1b4297f57faca06a42fb2fdc6933f9b5f08ca6a1', '2026-02-20 11:01:29', '2026-03-22 11:01:29', '176.1.223.216', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Mobile/15E148 Safari/604.1'),
(172, 1, 'ffa1a577fa6093e8c4ce47198f6130bc8b6f2b5da428caa01faeeb0098a3a9d8', '2026-02-20 11:22:09', '2026-03-22 11:22:09', '176.1.223.216', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Mobile/15E148 Safari/604.1'),
(173, 1, 'c1c5803759b2f87f702e8cb47cb798fdf7860b8d4880556a5d970b5cd411634c', '2026-02-20 23:04:01', '2026-03-22 23:04:01', '176.1.217.21', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Mobile/15E148 Safari/604.1'),
(174, 1, '622ff2e6f269bcbf5160239e7ddddfc5a5151d1fd5d489603e4685693244eea2', '2026-02-25 09:45:23', '2026-03-27 09:45:23', '176.1.226.236', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_2_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/145.0.7632.108 Mobile/15E148 Safari/604.1'),
(175, 1, '73c4bc179b6b074f41b0fbda28f32783a17245956f68374f7ca07cd0fa31a668', '2026-02-25 10:30:53', '2026-03-27 10:30:53', '176.1.226.236', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36'),
(176, 1, '9eade534fae53dcce06f39b2b00cb2ec9cca6b44546df1769dc75db7f82de449', '2026-02-25 10:31:21', '2026-03-27 10:31:21', '176.1.226.236', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36'),
(177, 1, '0967219ba80ad2bfc8fb28b91d58cd1bd72f519c50dfbb203710641ab304c1a4', '2026-02-25 10:31:44', '2026-03-27 10:31:44', '176.1.226.236', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36'),
(178, 1, '63530e348c7ab5692cee1651fa11d6f94a19dd3f9727097645c727023190122d', '2026-02-25 10:32:35', '2026-03-27 10:32:35', '176.1.226.236', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36'),
(179, 1, '2cf1879d9dc160f69ace6ecc754ed6f9a528276317ea7e487ca863be3b97d273', '2026-02-26 12:42:50', '2026-03-28 12:42:50', '176.1.208.212', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Mobile/15E148 Safari/604.1'),
(180, 1, '9852765c30fda711c1ebd21c44f8b7fe4127572993829de1a3c6f7a328abd514', '2026-02-27 09:23:12', '2026-03-29 09:23:12', '176.1.229.104', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Mobile/15E148 Safari/604.1'),
(181, 1, 'd95e3240f0ccf3f4e7f692fb47e0a9547150e4901166e3de47f6746663cbc439', '2026-02-27 09:28:56', '2026-03-29 09:28:56', '176.1.229.104', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36'),
(182, 26, '622d76e1df926f4c347322cedaa71e4a903f63cb4a05b2bd9c7e741f11e60d6c', '2026-02-27 19:27:04', '2026-03-29 19:27:04', '78.162.145.250', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Mobile Safari/537.36'),
(183, 1, '35e3fefdb53c693ddfecee68bbfbd8898f5f338db9b4550cc50c416383567bfa', '2026-03-02 08:46:38', '2026-04-01 08:46:38', '176.1.213.209', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36'),
(184, 1, 'fb92ed7b565cff2f0b7fe40be9d95fe411e86e1b8da8c9ca51a48f1d25506fc5', '2026-03-04 11:58:40', '2026-04-03 11:58:40', '176.1.229.8', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36'),
(185, 1, '42f8818f94682112d75ffea13927cfc1cbe1d2366237c9cbd3acca7bb3b1a365', '2026-03-05 16:47:01', '2026-04-04 16:47:01', '176.1.221.209', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1'),
(186, 17, '7d7889afca21d34d58bba583fea01de0036f73c531cad88712df60de85c98c62', '2026-03-06 11:04:53', '2026-04-05 11:04:53', '176.1.239.179', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148'),
(187, 17, 'f45c5c21a2f8ddf51ba8cf0bdfe3005028be4fc4f70ceaa1b5a73128a407a057', '2026-03-06 12:26:11', '2026-04-05 12:26:11', '176.1.239.179', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/23D127 Safari/604.1'),
(188, 17, '5d55d613c6bcfd060b19b67f2b0bf3436d98876cb51c3eb647f94979e80dbe9e', '2026-03-06 12:40:09', '2026-04-05 12:40:09', '176.1.239.179', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148'),
(189, 17, '499c6f0310cf15b03d0b88649bf77dc8c138d5636b066b14ca2a5ae606d22460', '2026-03-06 14:29:47', '2026-04-05 14:29:47', '176.1.239.179', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_3_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/145.0.7632.108 Mobile/15E148 Safari/604.1'),
(190, 17, '327ea75508117338c545831d6c0c4e067cf714ea918c0f72f227a4967a34d2bc', '2026-03-06 14:30:09', '2026-04-05 14:30:09', '176.1.239.179', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_3_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/145.0.7632.108 Mobile/15E148 Safari/604.1'),
(191, 17, '3baf251327b0a2cfd18b0e860a9a3f411ce63e2ad5ad9fff124c4c4e67401815', '2026-03-06 14:39:14', '2026-04-05 14:39:14', '176.1.239.179', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_3_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/145.0.7632.108 Mobile/15E148 Safari/604.1'),
(192, 17, '3854849c903dc1e2e06925759e626cd544c09205254e68f324cf9ddd4fe7b11d', '2026-03-06 14:50:17', '2026-04-05 14:50:17', '176.1.239.179', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_3_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/145.0.7632.108 Mobile/15E148 Safari/604.1'),
(193, 17, '1f03dfaf9bf390610d66b62facefe14d2e65d9c08708ec592b52e70f0cce2751', '2026-03-06 15:14:12', '2026-04-05 15:14:12', '176.1.239.179', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_3_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/145.0.7632.108 Mobile/15E148 Safari/604.1'),
(194, 17, 'ad171bc80301b7e09c9a2ee1fe18c9e1ed357c5efa8ff79ae278e86f67c32a9b', '2026-03-06 15:26:30', '2026-04-05 15:26:30', '176.1.239.179', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_3_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/145.0.7632.108 Mobile/15E148 Safari/604.1'),
(195, 17, 'a9f8dd1ebe795beb70845aa6db5d08d6a72fe0266ea499eb17988d9c051f7d3f', '2026-03-06 15:32:13', '2026-04-05 15:32:13', '176.1.239.179', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_3_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/145.0.7632.108 Mobile/15E148 Safari/604.1'),
(196, 1, '889f2c9157a526833a63ec2d260b5a010cb9d6d2bd553b454ae0b6ee97ef4d2d', '2026-03-06 15:36:05', '2026-04-05 15:36:05', '176.1.239.179', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1'),
(197, 1, 'fbe718cdca9bcb333c95a362820c89c15768a85e789bb26b7c21b16c1ac2de8b', '2026-03-06 16:47:12', '2026-04-05 16:47:12', '176.1.208.61', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1'),
(198, 1, '40110649e4967efa487be970f9b2d6b0e20c6a3ddc6c1c7405eb6a18a6aa963e', '2026-03-06 16:48:45', '2026-04-05 16:48:45', '176.1.208.61', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1'),
(199, 17, '1a82ef75292e06db7934957c8f7924eb9d27130506a2ff92bbe58f74a7e29b6a', '2026-03-13 07:21:07', '2026-04-12 07:21:07', '176.1.235.133', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Mobile Safari/537.36'),
(200, 1, 'ab4968f64458107c9ae3f757b822eda5a5a999362b2311dccb19e7f39424af0f', '2026-03-13 13:24:48', '2026-04-12 13:24:48', '176.1.235.133', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_3_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/145.0.7632.108 Mobile/15E148 Safari/604.1'),
(201, 1, 'eb6ca19ac5a844dcf18a43b265bc34d9ea82c83eb941efff60556f30feaa9931', '2026-03-14 10:39:20', '2026-04-13 10:39:20', '176.1.210.186', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36'),
(202, 1, '5983ce6cda335378839d756b603db0f7712b51de5285b8be0db0ad4bfa18ed2d', '2026-03-14 10:39:39', '2026-04-13 10:39:39', '176.1.210.186', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0'),
(203, 17, '25e2ac386a3950996f64ab476ffb74a6a019f4a08b088545f11584b8c6f4217b', '2026-03-14 10:43:43', '2026-04-13 10:43:43', '176.1.210.186', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0'),
(204, 1, '316d3c88807aa1af3565541241790f9e279e20e521fb49293dbaef294fcbb514', '2026-03-15 00:20:16', '2026-04-14 00:20:16', '176.1.141.187', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1'),
(205, 1, 'a020ad8743ac6ad053a4c710f0bb9475058ba68bdf272dd3b7c94ad438e995d7', '2026-03-15 00:20:44', '2026-04-14 00:20:44', '176.1.141.187', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_3_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/145.0.7632.108 Mobile/15E148 Safari/604.1'),
(206, 1, 'e5799f9e29805b8aa92dbcee9daf44e6022e1a1842f219e35b055e1775e20933', '2026-03-15 00:33:28', '2026-04-14 00:33:28', '176.1.210.186', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36'),
(207, 13, 'e0b953d8b435c7e6f86a331ac21c49faf5c048df988dea119958bda447c5f184', '2026-03-16 21:36:08', '2026-04-15 21:36:08', '92.208.31.111', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 Mobile/15E148 Safari/604.1'),
(208, 18, 'ea69d0a9a47b25568efaed88d58ee9ff37c581bcd80def4db6dea75aeeb002cb', '2026-03-16 21:37:40', '2026-04-15 21:37:40', '92.208.31.111', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 Mobile/15E148 Safari/604.1'),
(209, 14, 'ebf0d269f450b3858f4463fa499e2719570702419ca9d747990cddfee649fe21', '2026-03-25 09:48:10', '2026-04-24 09:48:10', '91.13.70.89', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1'),
(210, 17, 'de0c8f30322410b2fdc915871e82889991bdedd6fb5345d8ce44f2ecbfc75740', '2026-03-27 23:39:57', '2026-04-26 23:39:57', '176.1.237.186', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36'),
(211, 17, '638dd0f1a1ba01518d842f879e8326bbed86cc037ea4fbe8aa2814b016d38296', '2026-03-27 23:40:30', '2026-04-26 23:40:30', '176.1.237.186', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36'),
(212, 17, '4827e599efcd09d07a427dc0c0b098b84a6f4ce14e735b4012da86aa5a7828eb', '2026-03-27 23:40:55', '2026-04-26 23:40:55', '176.1.237.186', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36'),
(213, 17, 'c3d4030885f58f23c8da5b80b464444585bc0dc9e99847664f624b2267c08e82', '2026-03-27 23:41:41', '2026-04-26 23:41:41', '176.1.237.186', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36'),
(214, 17, 'd16705b7bc9ed04d7e9a52c052d68917b2e1e5d41bf47b0943e9c2e943b5ca5e', '2026-03-27 23:46:18', '2026-04-26 23:46:18', '176.1.237.186', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36'),
(215, 17, '7716f5654d0f81d716ad06d156d804ead2c4f38c0e5985ddcf97c7b06f7c2926', '2026-03-29 12:36:37', '2026-04-28 12:36:37', '176.1.221.143', 'Mozilla/5.0 (Linux; Android 16; 23117RA68G Build/BP2A.250605.031.A3; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/146.0.7680.119 Mobile Safari/537.36'),
(216, 1, '012742204015d55f28810477e4f29544f361052c3eb6e31df0e7a5b30a87eaa5', '2026-03-30 08:43:03', '2026-04-29 08:43:03', '176.1.212.234', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36'),
(217, 1, '7d1072f15995a5f36eac5a0d2a218581db39a821aef5a7b5e184e16bddf52dfc', '2026-03-30 09:21:38', '2026-04-29 09:21:38', '176.1.212.234', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1'),
(218, 1, '530a484507c65f62be4decb9b55f3be277cf73e240f343917c4a096a5254b0e4', '2026-03-30 09:24:24', '2026-04-29 09:24:24', '176.1.212.234', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1'),
(219, 35, '687ceffe1d2a8c752e765e776f53abd7dfe35268d3f64028bee1b1a5efbf07e9', '2026-03-30 12:47:23', '2026-04-29 12:47:23', '176.1.211.34', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_3_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/146.0.7680.151 Mobile/15E148 Safari/604.1'),
(220, 17, 'a228c58a19f7bdb760da24215efd162bd8743e5d9403cc8650c9a73e1f671127', '2026-03-30 22:40:48', '2026-04-29 22:40:48', '176.4.165.91', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 Edg/146.0.0.0'),
(221, 17, '6e511ba0d53246061c845507020fbdc3d67eaa4830fd0f7c03f641c42de3997f', '2026-04-07 11:43:34', '2026-05-07 11:43:34', '176.1.211.87', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Mobile/23D8133 Safari/604.1'),
(222, 30, '457ca2f5ca6cf4d73781dbd1a6e6a23f6056f2cca0cd54ac05ab8f2217c3218f', '2026-04-16 03:50:07', '2026-05-16 03:50:07', '68.132.227.13', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.7.5 Mobile/15E148 Safari/604.1'),
(223, 37, '6715829dd42c892eb2d655527c260e151a06e592c583d87e949cde8f733caafd', '2026-04-25 08:41:50', '2026-05-25 08:41:50', '94.234.84.253', 'Mozilla/5.0 (Linux; Android 11; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.7680.177 Mobile Safari/537.36'),
(224, 37, '6280f6c85918da8cac04c517da6185ce2d78c651568648c84e913ee9bc35854a', '2026-04-25 11:35:11', '2026-05-25 11:35:11', '94.234.84.253', 'Mozilla/5.0 (Linux; Android 16; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.7680.177 Mobile Safari/537.36'),
(225, 14, '73d8bacbd3bd2652220fe8ace830a16463063802413c004a23200e2d5534a2ba', '2026-04-27 21:09:32', '2026-05-27 21:09:32', '84.171.87.109', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.4 Mobile/15E148 Safari/604.1'),
(226, 1, '694fedb32be20a49a73ce591dc864b53b4c00cfd1527399fee9e3231dc49b708', '2026-04-29 20:16:08', '2026-05-29 20:16:08', '176.1.230.197', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1'),
(227, 17, 'c8bd4e69a9803d9a73c5c3cd930dfe754c223c5d2c742d784deaf08e78a9d863', '2026-05-06 23:23:19', '2026-06-05 23:23:19', '176.1.198.250', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36'),
(228, 1, '5e009e8e6500aa929aef0fa83d557f6dd86aad72b146553014205b5be0408c9b', '2026-05-07 08:35:52', '2026-06-06 08:35:52', '176.1.198.250', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1'),
(229, 17, '9979162a22a06e4bff916c52dbdfd4e5f50d9e3879fa8a6a28dc2f493f59c6b5', '2026-05-07 08:54:11', '2026-06-06 08:54:11', '176.1.198.250', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0'),
(230, 1, '5c85d3528df8fc8d104fe0e7be302a8865736ab06127cd54798be8eb853e7d1d', '2026-05-07 09:53:13', '2026-06-06 09:53:13', '176.1.198.250', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36'),
(231, 1, 'cb9cfadc25e39747b3de1ced13baa36b9f177c343790e75960ace3c75e3deceb', '2026-05-07 11:30:01', '2026-06-06 11:30:01', '176.1.198.250', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36'),
(232, 1, 'da95cb85f052bcd2702b782ec930f50fb802656e7bd6d078c61042b84b777e3b', '2026-05-07 11:51:59', '2026-06-06 11:51:59', '176.1.198.250', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36'),
(233, 1, '0e534c9b242f54f753b5f41be047bd8ba83005b2003225385c2883b68ea81790', '2026-05-07 11:52:29', '2026-06-06 11:52:29', '176.1.198.250', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36'),
(234, 1, 'd3dd6d9d0b0989d191c94f2a62b48a0daaaed7d074c5d0c2aa24c47dc8d19d0f', '2026-05-07 11:56:45', '2026-06-06 11:56:45', '176.1.198.250', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36'),
(235, 1, 'd5c6af2d622288442a4ad631f9aa03716aee4d9f70dd6644609c2e494751179a', '2026-05-07 11:58:20', '2026-06-06 11:58:20', '176.1.198.250', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36'),
(236, 1, '00e2309c85e05bd9269f20613d14143e9ead3c47a581176a3965cb4ab88b4321', '2026-05-07 12:07:08', '2026-06-06 12:07:08', '176.1.198.250', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36'),
(237, 17, '6a2518ef4567eaf699196f2768d8e431b91517c227dbf50ffcb01b558ca0c0d1', '2026-05-07 12:22:47', '2026-06-06 12:22:47', '176.1.198.250', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36'),
(238, 1, '1fd0ab6ee422ab77a9624e42e3a474e82891ef53e4c860adb2865d512dc81e96', '2026-05-07 15:05:58', '2026-06-06 15:05:58', '176.3.37.144', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Mobile/23D8133 Safari/604.1'),
(239, 1, '8442d3677666f1270171bbed4d5ededef70c7f796755e35111106efaf1d846af', '2026-05-07 19:53:09', '2026-06-06 19:53:09', '176.1.249.96', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36'),
(240, 1, '708c4f7c5df8d3fc99cd678676bdcb717b6cac1bf3877a8bbd61924a18fc79a9', '2026-05-07 20:03:31', '2026-06-06 20:03:31', '176.1.249.96', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36'),
(241, 17, '92e329e94f6098bf75c600c7f31170d945d2cf8685359eb35b6594eb05832574', '2026-05-07 20:16:10', '2026-06-06 20:16:10', '176.3.40.167', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1'),
(242, 1, '61e4951c41332e7acde2da0c0e234b4b241021324b85c126e995746dded9befb', '2026-05-08 06:21:50', '2026-06-07 06:21:50', '176.3.40.167', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1'),
(243, 12, 'b588959088287dfa017cb636bf8b0e35e3e617c4e17beab5490686c183090142', '2026-05-08 06:23:54', '2026-06-07 06:23:54', '172.80.155.145', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1'),
(244, 17, '8e75de5f338d53e385193c100a22c946241589625017e338d2ad3d1c18901e43', '2026-05-08 06:25:38', '2026-06-07 06:25:38', '176.3.40.167', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Mobile/23D8133 Safari/604.1'),
(245, 26, '2c10fdce8487956b729d82be46459f3bce079e5de2b4bcd76260a3e95165c03e', '2026-05-09 11:16:46', '2026-06-08 11:16:46', '78.162.42.243', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36'),
(246, 17, 'de7a425ce53f95379bc671784f9c6db275b4f7cdc4f171402ee8617e8f43c0e6', '2026-05-12 21:13:45', '2026-06-11 21:13:45', '176.1.246.111', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Mobile Safari/537.36'),
(247, 17, '58638cfa39cea61e50f38a86d78e6db9fa26687dfecf8b8bf08a2e65418327e7', '2026-05-14 09:45:21', '2026-06-13 09:45:21', '176.1.211.27', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1'),
(248, 1, 'e7a464bc6cfcec90474e822273d1f8172a0e6309bb81187976f7f381adb2e9d7', '2026-05-14 10:21:12', '2026-06-13 10:21:12', '176.1.211.27', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1'),
(249, 1, 'e163a4d3e4bcf81c11c6dd3b256865834973ea7c8da5e1a1b0a26647ee32d4c6', '2026-05-14 10:25:32', '2026-06-13 10:25:32', '176.1.211.27', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1'),
(250, 17, '9199344348280e7e509b7a5d73a0e6dad9bf813e368c8f5765e7482302502da7', '2026-05-14 10:28:06', '2026-06-13 10:28:06', '176.1.211.27', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36'),
(251, 1, 'ee6dccd0d17327dd3166619a48aa896e5b8894a80f8d8d95e3592a94bc7f68a6', '2026-05-14 10:48:39', '2026-06-13 10:48:39', '176.1.211.27', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1'),
(252, 17, '3ef42c37abaeb7ad6b5f03c06f23be674a09418aec892606e9049e08db8bc5ca', '2026-05-14 10:52:45', '2026-06-13 10:52:45', '176.1.211.27', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36'),
(253, 1, 'c314025f30456ff7c06714d25dee418c0261f876ba712fbc7ebc09884c7a28dd', '2026-05-14 11:43:13', '2026-06-13 11:43:13', '176.1.211.27', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1'),
(254, 1, '3f3594a0055fd73a299dbf7a81b65b2e54c0088f48f0836639e10fbc538dd652', '2026-05-14 11:45:53', '2026-06-13 11:45:53', '176.1.211.27', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1'),
(255, 17, 'b0dfd8864f6ceddcad469bf18ce968286ef9150caed3138d63a7240a8607fd96', '2026-05-14 11:52:53', '2026-06-13 11:52:53', '176.1.211.27', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.1 Safari/605.1.15'),
(256, 14, '38cd9ec5dc54d6eca4a14e97f974cb8c3477feb06fd90d154459631f5beb978c', '2026-05-14 13:40:16', '2026-06-13 13:40:16', '84.171.87.109', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.4.2 Mobile/23E261 Safari/604.1'),
(257, 1, '2a88f916e994c3b3860912d544e8ffb4ef4bee053cb3276fe3e8a0825ef52963', '2026-05-15 08:07:23', '2026-06-14 08:07:23', '176.1.241.22', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36'),
(258, 17, 'a1d7a5423f0916f41e2dcc96d88ff382837e7604fb2456c6376914eb9acbbe20', '2026-05-15 08:39:55', '2026-06-14 08:39:55', '176.1.203.10', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1'),
(259, 17, 'd637b3a56a672f6f18d1af8fa70ca90ee394575f00c48b32e80acd72f7412402', '2026-05-15 09:14:31', '2026-06-14 09:14:31', '176.1.241.22', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36'),
(260, 17, 'ab91386d0682e9ad1cf31ba84b862bf96ffdb39769edaff5266d5f18fe64f145', '2026-05-15 09:16:35', '2026-06-14 09:16:35', '176.1.241.22', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1'),
(261, 17, '533108d282a463ebeb6262b3337ada2bb8ed389f39146018c14f4dcf5eedfa3a', '2026-05-15 09:17:31', '2026-06-14 09:17:31', '176.1.241.22', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1'),
(262, 17, '2b5bfffd46e51ac2a55254a66ad8772292eadf85fe91fb804922b4251ad50284', '2026-05-15 09:18:41', '2026-06-14 09:18:41', '176.1.241.22', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1'),
(263, 17, '5f5d63ad610c7ad19450d09f562f0b833872b0b609ee7116a8244b63c021cc31', '2026-05-15 09:20:40', '2026-06-14 09:20:40', '176.1.241.22', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1'),
(264, 17, '4bb716b6427b18695863e1bdd9987d7a988f7e0431a86b6e30cb98c8cc28f888', '2026-05-15 09:21:32', '2026-06-14 09:21:32', '176.1.241.22', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1'),
(265, 17, '5e5fd83be5343e9a59b96c25562deb54cc6de7e5a6807fc732b6ac1ddb0e81d0', '2026-05-15 09:22:56', '2026-06-14 09:22:56', '176.1.241.22', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1'),
(266, 17, '2e811ab682871c2766ba36dc1b57995cee378a9d6cc9918941dd3188d592f1f2', '2026-05-15 09:24:09', '2026-06-14 09:24:09', '176.1.241.22', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1'),
(267, 17, 'd3674fe0132f0be693440e61269fbe85cdfccbbe6e4d4a209323f5485c90fbef', '2026-05-15 09:28:37', '2026-06-14 09:28:37', '176.1.241.22', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1'),
(268, 17, '7c2732062e0aa739e4af0e9a333e1456eb38654872b404d647d8ee4e7c78ab3d', '2026-05-15 09:30:11', '2026-06-14 09:30:11', '176.1.241.22', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1'),
(269, 17, '6025e709a0d5bb1534a7bfbbf6d9f965e36995d644878aa766397fa519920ddb', '2026-05-15 09:32:51', '2026-06-14 09:32:51', '176.1.241.22', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1'),
(270, 1, '032e584b951b789e823e548eec4e991d997e30dcc8fda185120efe2d3bfefa33', '2026-05-15 10:13:25', '2026-06-14 10:13:25', '176.1.203.10', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1'),
(271, 37, '489b49bf702b23784ec2d5584f1c70fc16c232b2c0e0d51dc88ae45136694a55', '2026-05-15 14:21:18', '2026-06-14 14:21:18', '94.234.87.112', 'Mozilla/5.0 (Linux; Android 16; Redmi Note 13 Pro 5G Build/BP2A.250605.031.A3) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.7049.79 Mobile Safari/537.36 XiaoMi/MiuiBrowser/14.55.0-gn'),
(272, 17, 'd013df0a0b2c9022d900d7066156aced4590acef686ce1c3640da522bd6b2bd5', '2026-05-15 20:09:02', '2026-06-14 20:09:02', '176.1.241.22', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36'),
(273, 1, 'b5b5d8b524871ce2a42a5aa1c96fc3aebc4a94e056fe24224edf585b3d9f9406', '2026-05-15 20:14:01', '2026-06-14 20:14:01', '176.1.241.22', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1'),
(274, 1, '7fd1fc02669fbc367ab8b53259020230af3caf90841b1c293298914ea88dbb3c', '2026-05-15 21:37:08', '2026-06-14 21:37:08', '176.1.241.22', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36'),
(275, 1, 'b1652e073b0e10b4d943e23b971bff8f1ecfd15aa491be7b6371a1b32b69f3e6', '2026-05-16 15:37:47', '2026-06-15 15:37:47', '176.1.198.194', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1'),
(276, 17, '62c1d6ffed34b965d2549f41c01a59059470a8f8b2d8f7dd9d01aae7e3179035', '2026-05-17 09:20:51', '2026-06-16 09:20:51', '176.1.251.195', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36'),
(277, 17, 'e0af810f78dfd1f3d8391af5eea4a0618098c9d0dbc85c303513be13433d9a9a', '2026-05-17 09:53:20', '2026-06-16 09:53:20', '176.1.251.195', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36'),
(278, 17, '6679685fca0706a41f797b7465b96a9bebcabb0fb780bed0ac3a1b1a0512d19c', '2026-05-17 10:17:34', '2026-06-16 10:17:34', '176.1.251.195', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36'),
(279, 17, 'b02035a3cd02d6827a9f7736ac923475e71942cd319336f51302f3d04e4be252', '2026-05-17 10:26:27', '2026-06-16 10:26:27', '176.1.251.195', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36'),
(280, 17, 'e3f7071aa2a0172fa4390a7b8176753c5e4400f3c2f53b0f01b7780116495026', '2026-05-17 10:36:00', '2026-06-16 10:36:00', '176.1.251.195', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36'),
(281, 17, '601707780300f1ff1177d128f220c959c7ebe4b41683314da1d45f7dfe4f94d1', '2026-05-17 13:51:19', '2026-06-16 13:51:19', '176.1.251.195', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Mobile/23D8133 Safari/604.1'),
(282, 17, 'a3b77564a09d3ce03d1ad165d91897140db893ff48d0ec8021bdcb0f0a79e11e', '2026-05-24 23:19:58', '2026-06-23 23:19:58', '176.1.243.215', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_3_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/148.0.7778.166 Mobile/15E148 Safari/604.1'),
(283, 17, 'f381524941b8da53d3eb7220800e2722447078b1a1df3691bf125f5f4daa8e64', '2026-05-31 14:34:02', '2026-06-30 14:34:02', '176.1.242.42', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36'),
(284, 17, '6d035af5107204859a71d215acd7f831d54be5a1f30408bb3c9d2dbefe1d8937', '2026-05-31 14:56:54', '2026-06-30 14:56:54', '176.1.213.251', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1'),
(285, 1, 'f232d3db03fefbba4c2eb61d4576b26b0f6f76cfcb2c21125a3466cdf12ca8ac', '2026-05-31 14:58:00', '2026-06-30 14:58:00', '176.1.213.251', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1'),
(286, 17, '1f69563ba132c9c058d7125ae966ec5771b6dd4d0a10ab0558ff29cf309d8718', '2026-05-31 15:00:56', '2026-06-30 15:00:56', '176.1.242.42', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36'),
(287, 1, '3a3613edb8108248db14348ec798e8e488d8e326034a5667a749d4c502abe4c3', '2026-05-31 15:07:59', '2026-06-30 15:07:59', '176.1.213.251', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1'),
(288, 1, '5085324e87296e3bc5195d09cc6e40d9a9751f7bd91ada52a2c6007f7f566b2b', '2026-05-31 15:08:39', '2026-06-30 15:08:39', '176.1.213.251', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_5_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/148.0.7778.166 Mobile/15E148 Safari/604.1'),
(289, 1, '70f2cb7b03e0d1563682e5495fb683ece32f6b8823ecf47dee3b0bb8e4a9bbde', '2026-05-31 15:12:40', '2026-06-30 15:12:40', '176.1.213.251', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_5_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/148.0.7778.166 Mobile/15E148 Safari/604.1'),
(290, 1, '6940e59aa8b095e6a331dbd990e3ef8fd51697fa003a694715ca5906d499fe7b', '2026-05-31 15:13:46', '2026-06-30 15:13:46', '176.1.213.251', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_5_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/148.0.7778.166 Mobile/15E148 Safari/604.1'),
(291, 1, '2d034944fbe5f8f0e91ae1a503bcc310d3b3d6d2b4996a88f77e826fe530d077', '2026-05-31 15:14:39', '2026-06-30 15:14:39', '176.1.213.251', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_5_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/148.0.7778.166 Mobile/15E148 Safari/604.1'),
(292, 1, '0d312ff6460e035e267ad1b7e00be6a1c684286259e56ae58eec547f82d6519b', '2026-05-31 15:15:52', '2026-06-30 15:15:52', '176.1.213.251', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_5_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/148.0.7778.166 Mobile/15E148 Safari/604.1'),
(293, 1, '35ed3795c69cd16ec253c5c87851d191cf7e1607b5c0ab1286df2a35a970657f', '2026-05-31 15:20:41', '2026-06-30 15:20:41', '176.1.213.251', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_5_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/148.0.7778.166 Mobile/15E148 Safari/604.1'),
(294, 1, '0f7d34c0f03944622e2d36c78f97f1fc3b7dec48be3e298d214c53654b95f0f6', '2026-05-31 15:21:30', '2026-06-30 15:21:30', '176.1.213.251', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_5_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/148.0.7778.166 Mobile/15E148 Safari/604.1'),
(295, 17, '20f72c394e2993bcc41c9639c9c99111d8a8d8883ea5547044c31a74687521aa', '2026-05-31 15:25:15', '2026-06-30 15:25:15', '176.1.242.42', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36'),
(296, 17, '096cbc0e56ae3db1329ac0ef2ceb1911986bea86949ac925b269800501553401', '2026-05-31 15:31:47', '2026-06-30 15:31:47', '176.1.242.42', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36'),
(297, 17, '6f46b46b21077f2bfb6484b6cb9cb9ceb03d419f93f26d68117c5a69d9eadc2c', '2026-05-31 15:35:30', '2026-06-30 15:35:30', '176.1.242.42', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36'),
(298, 17, '6f2eddf76fba7856b5e01b573a0b6418677134fa340518922c954fe64c5f5f4a', '2026-05-31 15:44:14', '2026-06-30 15:44:14', '176.1.242.42', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36'),
(299, 17, '6aa61f80d27c9fc618a117ee4ed847e3f75ab1fd531d793bb9ea299fa5fdbba9', '2026-06-04 07:51:34', '2026-07-04 07:51:34', '176.1.249.1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36'),
(300, 1, '27c438ec6badf89d7a5220fe2b5840745df66673fdd0a6e99bfd5e41eb99c602', '2026-06-04 07:59:18', '2026-07-04 07:59:18', '176.1.249.1', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1'),
(301, 1, 'cb007ccf404b5c56f57089c79227f429dd4945b64782400a7232039581804e11', '2026-06-04 08:38:42', '2026-07-04 08:38:42', '176.1.249.1', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1'),
(302, 17, 'b60cacc2c353ef906fc5bf648ce4b2d887b36720b43ec2a0b99ab1d031d89c2a', '2026-06-04 08:40:53', '2026-07-04 08:40:53', '176.1.249.1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36'),
(303, 1, '51cc7e529825e17c85088bdad5da923c77f8eb034e87415c0f42ff158b798cde', '2026-06-04 08:43:27', '2026-07-04 08:43:27', '176.1.249.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36'),
(304, 1, '41c3f6672c51f306100e3d3cb49b68067c3ef7b7a209f68b84cbecc14405a905', '2026-06-04 09:14:57', '2026-07-04 09:14:57', '176.1.249.1', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1'),
(305, 1, '85ad1feff22387c8da96d3dc21e1a9623f11fabb128aa9b48ac73fa7ee0f1823', '2026-06-04 09:25:14', '2026-07-04 09:25:14', '176.1.249.1', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_5_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/149.0.7827.45 Mobile/15E148 Safari/604.1'),
(306, 1, 'cf213cb04d250d444d66a9094e73651886483e6b6734d7f43f282d0aeb64350f', '2026-06-04 09:31:54', '2026-07-04 09:31:54', '176.1.249.1', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_5_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/149.0.7827.45 Mobile/15E148 Safari/604.1'),
(307, 17, '7ba585171f76a3609e580e3fb7f9e7147083347f180fd0167353a78de8c5cf47', '2026-06-04 10:02:22', '2026-07-04 10:02:22', '176.1.249.1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36'),
(308, 17, 'e7407b4bd919c62b5281973b3c838e873fff1688066b7eb061326f468736f0e1', '2026-06-04 18:28:07', '2026-07-04 18:28:07', '176.1.208.214', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36'),
(309, 1, '1edf0bdfd0cf41f980865dea0f83d474269c29344bb379e8537fdc0da08c2f69', '2026-06-04 19:00:46', '2026-07-04 19:00:46', '176.1.208.214', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1'),
(310, 1, 'd8a0f934a92401f44f8df762d0a56f8d50e447e8fa9824397d468855f6aee8d7', '2026-06-04 19:10:26', '2026-07-04 19:10:26', '176.1.208.214', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1'),
(311, 17, '0f7372bfbb254985ca2fbc9db01aab0d8765fceecb748739d8f423d2eed76309', '2026-06-05 05:12:04', '2026-07-05 05:12:04', '176.1.208.214', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36'),
(312, 17, '80ffeb739ce501b1e170e932d08250ad59714600b2b16065c0e73dc71c6fe395', '2026-06-05 10:03:00', '2026-07-05 10:03:00', '176.1.210.128', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_5_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/149.0.7827.45 Mobile/15E148 Safari/604.1'),
(313, 1, 'a163a7da5ba76160aac1300851fc50eb5e8b63c5f0366a8e6ddf571bf8031bb8', '2026-06-05 21:14:12', '2026-07-05 21:14:12', '176.1.255.147', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36'),
(314, 17, '8bc2bdf5eec7fb8e5091ca8aa23c072a6f3f9c34fcf4346d5f55d6e081d8df3f', '2026-06-06 18:53:17', '2026-07-06 18:53:17', '176.1.218.241', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1'),
(315, 1, '860bc8257c786bab6c9bbc5acb16aedc6c9ff9a018a65c4e285bdab270a3ff1b', '2026-06-07 10:15:49', '2026-07-07 10:15:49', '176.1.217.1', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1'),
(316, 18, '11e3d7b6c577fde6d702a1815802f2eba64fa959e568a475cf2d7261f86bd9e4', '2026-06-11 20:32:34', '2026-07-11 20:32:34', '92.208.31.198', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.7.5 Mobile/15E148 Safari/604.1'),
(317, 1, '24f2e9159fbea6a19f86269661f8f9ef4813e1d495ff2769656417c4755af26c', '2026-06-13 07:06:08', '2026-07-13 07:06:08', '176.1.197.181', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1'),
(318, 1, '4a39349a36903117ba8a139cbfb807c5f9b7f67a70e7d6888b57562626065253', '2026-06-13 07:10:54', '2026-07-13 07:10:54', '176.1.197.181', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_5_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/149.0.7827.45 Mobile/15E148 Safari/604.1'),
(319, 1, 'dd4914024a69464592bd1de5ee8d54b435c61f9a8a384d0f076e995e921e0693', '2026-06-13 07:16:23', '2026-07-13 07:16:23', '176.1.197.181', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_5_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/149.0.7827.45 Mobile/15E148 Safari/604.1'),
(320, 1, 'a5d58bc5b29b67e3a3ed4471d50f7a4a784fcf584b1cab09cd6ed9ae15dd0689', '2026-06-13 07:21:34', '2026-07-13 07:21:34', '176.1.197.181', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_5_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/149.0.7827.45 Mobile/15E148 Safari/604.1'),
(321, 1, 'e761e0c805d6b30f3d2c49724faf15836d587a488e3c709da3044c432276f052', '2026-06-13 07:23:15', '2026-07-13 07:23:15', '176.1.197.181', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_5_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/149.0.7827.45 Mobile/15E148 Safari/604.1'),
(322, 1, 'c64c68678bef28f532d41210bd755b512581f2ddf51ec4d106167221e8ecf8f0', '2026-06-13 07:28:17', '2026-07-13 07:28:17', '176.1.197.181', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_5_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/149.0.7827.45 Mobile/15E148 Safari/604.1'),
(323, 1, '068315fe3492a416f22d989275e7cfa3dca017e424aac0dc5b703dac7e3274d5', '2026-06-13 07:32:49', '2026-07-13 07:32:49', '176.1.197.181', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_5_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/149.0.7827.45 Mobile/15E148 Safari/604.1'),
(324, 1, '5a7d674a8f0bfe05aebd352e064ba6082e2a32623174613b5c65390829b7efce', '2026-06-13 07:38:31', '2026-07-13 07:38:31', '176.1.197.181', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_5_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/149.0.7827.45 Mobile/15E148 Safari/604.1'),
(325, 1, 'de89ae40eae4919f4dc52b7b53ffbd4ee941677734549ea1284d9e213aba6df8', '2026-06-13 07:51:33', '2026-07-13 07:51:33', '176.1.197.181', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1'),
(326, 1, '358096d56d500ef8530966db703e8171c79448545d58c75dfaeda2a1d1666e62', '2026-06-13 07:57:24', '2026-07-13 07:57:24', '176.1.197.181', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1'),
(327, 17, '9501a8d496540b79223785a2d52e20336fcdfd7a1399a8b3ce1b3ca46f34b5e5', '2026-06-13 08:03:46', '2026-07-13 08:03:46', '176.1.197.181', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_5_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/149.0.7827.45 Mobile/15E148 Safari/604.1'),
(328, 17, '228c7102cb930b1ae387bf47bbd76912f0403e1d64e61e230379a688226da3d4', '2026-06-14 16:48:12', '2026-07-14 16:48:12', '176.1.150.237', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_5_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/149.0.7827.137 Mobile/15E148 Safari/604.1'),
(329, 17, '2e87d5cc3fcd92b36d60f8779f760e197db1c9ed726c4d702a15ad81f3f376b9', '2026-06-14 17:10:33', '2026-07-14 17:10:33', '176.1.150.237', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_5_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/149.0.7827.137 Mobile/15E148 Safari/604.1'),
(330, 1, '6944b99072b530ca269bfc663a904606825caec4a459b88d21c742778bb34291', '2026-06-14 18:12:41', '2026-07-14 18:12:41', '176.1.150.237', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_5_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/149.0.7827.137 Mobile/15E148 Safari/604.1'),
(331, 1, 'd9a1aa1d616de1b540e461541a947ce076a389865c1726b76ab49541d9cc4a6e', '2026-06-14 18:15:48', '2026-07-14 18:15:48', '176.1.150.237', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1'),
(332, 1, 'c1b383cb899adb9cf5b067b12e1f3cd0b1e6978d18a1de54dd7ffd9c4cd60c6b', '2026-06-14 19:21:41', '2026-07-14 19:21:41', '176.1.150.237', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1'),
(333, 12, '84da1d3fc73701f7fcf303540518c19baab65aed7202bc0e3c259bc24262fa6e', '2026-06-15 19:58:14', '2026-07-15 19:58:14', '5.208.12.17', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1'),
(334, 1, '6fe104bcc4034a54ba026c060b0a5ae131fad9e5da2f6761a08cd4716367a84d', '2026-06-16 22:13:58', '2026-07-16 22:13:58', '176.1.217.140', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.1 Safari/605.1.15'),
(335, 1, '2e4c36ef56e2649ca45da787f535806b2cd11762d99b19a49773e4938d5e8339', '2026-06-18 09:09:17', '2026-07-18 09:09:17', '176.1.204.29', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1'),
(336, 41, '8d9d696e663e683652c5927b614fa572fa7533364d7e46bb67c5dc9320bb5780', '2026-06-18 11:09:55', '2026-07-18 11:09:55', '83.191.98.172', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1'),
(337, 37, '8544f90e98bcaace379275ca15efb2da4b8172cc0bbe3e5d2de5b697860b5c81', '2026-06-18 20:10:06', '2026-07-18 20:10:06', '94.234.87.176', 'Mozilla/5.0 (Linux; Android 16; Redmi Note 13 Pro 5G Build/BP2A.250605.031.A3) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.7049.79 Mobile Safari/537.36 XiaoMi/MiuiBrowser/14.56.0-gn'),
(338, 14, '61c6b8cac14da25c053438c335ad93af541b9f5c39c634ca6acc5d32d6c18516', '2026-06-19 09:21:52', '2026-07-19 09:21:52', '84.171.95.31', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/23F77 Safari/604.1'),
(339, 43, 'd110997194f362f82d2ab3a95607e26c9ba448540378e38ef1bfd517063f8cad', '2026-06-22 12:25:53', '2026-07-22 12:25:53', '176.77.152.249', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1'),
(340, 1, 'eb8045d0bd7dbbb12b79adc16b5f0ad65ece45e8f9205266ef3571076c43093a', '2026-06-26 09:14:12', '2026-07-26 09:14:12', '212.20.181.86', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36'),
(341, 1, '711c11af3675aea6ccfa0a953a59cf3713902eb2e50b38792086c7bfeb1ea1d4', '2026-06-27 12:46:38', '2026-07-27 12:46:38', '176.1.208.8', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1'),
(342, 1, 'f3d73468124bb9581231c52e969f0036746f9520cd030876483e6ecd1adccd5b', '2026-06-27 13:09:51', '2026-07-27 13:09:51', '176.1.223.83', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1'),
(343, 17, 'fb757ce4c87cdf6e610b505b1e905d8659f632c2d3b79b5109eecbdc983a7417', '2026-06-27 13:11:46', '2026-07-27 13:11:46', '176.1.223.83', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0'),
(344, 1, '90bef2b7c7ce34323ba064e0473fc8e9f0ad4a329127f7474c1342da92bf9dc5', '2026-06-27 13:24:18', '2026-07-27 13:24:18', '176.1.223.83', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1'),
(345, 17, '031982cabcf6e9c68f9a5658749f3b79a6bc99c834def653771198c082b20e2d', '2026-06-28 08:56:06', '2026-07-28 08:56:06', '176.1.195.60', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36'),
(346, 1, '3478acdec4974de5187fccf8760943d87ccc71f370417c084d55121863f38eb8', '2026-06-28 09:07:57', '2026-07-28 09:07:57', '176.1.195.60', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1'),
(347, 1, '6f2ef543ae05a19dc753b026dbee4c53acaebab7b5d6b86dd25687eec7bf9389', '2026-06-28 09:11:03', '2026-07-28 09:11:03', '176.1.195.60', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1');
INSERT INTO `sessions` (`id`, `user_id`, `token`, `created_at`, `expires_at`, `ip_address`, `user_agent`) VALUES
(348, 1, '74807e38b54b24e7f42db621c002da6790fca00502a650aabb6f2331819f50fb', '2026-06-28 09:23:03', '2026-07-28 09:23:03', '176.1.195.60', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1'),
(349, 1, '1239b25b6b0d6ad12fc6e9fc16a38ccd75fcfda01cf250db40bb8fff7a004de6', '2026-06-28 09:30:24', '2026-07-28 09:30:24', '176.1.195.60', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_5_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/149.0.7827.137 Mobile/15E148 Safari/604.1'),
(350, 1, '998bb60fcd4f5ad5f4a5dc6c5ad662a0b0f3543355e288a52b590fd25e45fc6b', '2026-06-28 09:33:40', '2026-07-28 09:33:40', '176.1.195.60', 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_5_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/149.0.7827.137 Mobile/15E148 Safari/604.1'),
(351, 17, '8b74de5f2f768455558870502b4f19f4b0dc208f4932dd67ee298b3f31012615', '2026-06-28 09:54:17', '2026-07-28 09:54:17', '176.1.195.60', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0'),
(352, 1, '9bcc1f91102aa37d58ea1832a8985ee7075e6c1bbdf51e81b360e011ac77f666', '2026-06-28 09:55:45', '2026-07-28 09:55:45', '176.1.195.60', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0'),
(353, 1, '91f4f42aeb718f9c651a63721a36bbc393bfa6175c7044ad0676ab4e9a485409', '2026-06-28 09:59:08', '2026-07-28 09:59:08', '176.1.195.60', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1'),
(354, 17, '7df5bc70e537512fb89b82e9b8bb8d32e7fe66527065d3a0e7af16d3767f751c', '2026-06-28 10:00:06', '2026-07-28 10:00:06', '176.1.195.60', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/23F77 Safari/604.1'),
(355, 1, 'd7d7cfa325ca4ba03abc72ae0989a6afde63021ba5ba8b8308b1c0bbd621824b', '2026-06-28 10:43:06', '2026-07-28 10:43:06', '176.1.195.60', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1'),
(356, 1, 'b5ab7641eefdd1cfe6274ce6e84bb70abbe296d5fd90d1f27bf05a54f53144a3', '2026-06-28 13:46:34', '2026-07-28 13:46:34', '176.1.195.60', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36'),
(357, 1, 'f8011393b2d5885fb1d13adcdcbf1845737f7e264472712b1c2619d5388a4065', '2026-06-28 14:14:57', '2026-07-28 14:14:57', '176.1.195.60', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.5 Mobile/15E148 Safari/604.1'),
(358, 17, '19d72f973dae952151fd1c0bd87e15b9cefdcbf2b4f6e8224775ccdca4b8c886', '2026-06-28 14:30:10', '2026-07-28 14:30:10', '176.1.195.60', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Mobile Safari/537.36'),
(359, 1, '0397d3d86bfedc43ab5abb0c5406a487cbaf73ec415df97fdfc705a544524d65', '2026-06-30 07:46:41', '2026-07-30 07:46:41', '212.20.181.86', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36');

-- --------------------------------------------------------

--
-- Table structure for table `subscribers`
--

CREATE TABLE `subscribers` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `status` enum('pending','active','expired','suspended') DEFAULT 'pending',
  `start_date` datetime DEFAULT NULL,
  `expiry_date` datetime DEFAULT NULL,
  `last_payment_date` datetime DEFAULT NULL,
  `next_billing_date` datetime DEFAULT NULL,
  `auto_renew` tinyint(1) DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `subscribers`
--

INSERT INTO `subscribers` (`id`, `user_id`, `plan_id`, `status`, `start_date`, `expiry_date`, `last_payment_date`, `next_billing_date`, `auto_renew`, `notes`, `created_at`, `updated_at`) VALUES
(18, 1, 4, 'expired', '2026-03-14 15:03:47', '2026-04-13 15:03:47', '2026-03-14 15:03:47', '2026-04-13 15:03:47', 0, NULL, '2026-03-06 13:55:49', '2026-05-07 01:07:20'),
(19, 17, 4, 'expired', '2026-03-28 19:55:57', '2026-04-27 19:55:57', '2026-03-28 19:55:57', '2026-04-27 19:55:57', 0, NULL, '2026-03-06 13:58:25', '2026-05-07 01:07:20');

-- --------------------------------------------------------

--
-- Table structure for table `subscription_offers`
--

CREATE TABLE `subscription_offers` (
  `id` int(11) NOT NULL,
  `subscriber_id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `price` decimal(20,2) NOT NULL COMMENT 'قیمت آفر',
  `bank_account` text DEFAULT NULL COMMENT 'شماره حساب برای پرداخت',
  `status` varchar(50) NOT NULL DEFAULT 'pending',
  `requested_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `subscription_offers`
--

INSERT INTO `subscription_offers` (`id`, `subscriber_id`, `admin_id`, `title`, `message`, `price`, `bank_account`, `status`, `requested_at`, `paid_at`, `confirmed_at`, `rejected_at`, `admin_notes`, `created_at`, `updated_at`) VALUES
(28, 18, 1, 'پرداخت اشتراک - اشتراک یورو ارزان قیمت', 'شماره حساب برای پرداخت اشتراک شما ارسال شد.', 600000.00, '760120020000003436957409', 'confirmed', NULL, NULL, NULL, NULL, NULL, '2026-03-06 13:56:10', '2026-03-06 13:57:14'),
(29, 19, 1, 'پرداخت اشتراک - اشتراک یورو ارزان قیمت', 'شماره حساب برای پرداخت اشتراک شما ارسال شد.', 600000.00, 'سلام وقتتون بخیر  امکان داره ١.٩٠٠.٠٠٠ تومان برای این کارت واریز کنید تسویه شیم   6104 3389 5220 5470 ملت  ‎760120020000003436957409‎   ندا لطف الله زاده', 'confirmed', NULL, NULL, NULL, NULL, NULL, '2026-03-06 13:58:40', '2026-03-06 13:59:47'),
(30, 18, 1, '۳۰۰ یورو', 'واریز از طریق پیپال رولوت و یا بانک', 59100000.00, '760120020000003436957409', 'rejected', NULL, NULL, NULL, NULL, 'hh', '2026-03-06 14:01:16', '2026-03-14 14:14:10'),
(31, 19, 1, '۳۰۰ یورو', 'واریز از طریق پیپال رولوت و یا بانک', 59100000.00, '760120020000003436957409', 'confirmed', NULL, NULL, NULL, NULL, NULL, '2026-03-06 14:01:16', '2026-03-06 17:04:15'),
(32, 18, 1, 'تمدید اشتراک - اشتراک یورو ارزان قیمت', 'درخواست تمدید اشتراک شما ثبت شد. پس از تایید ادمین، اطلاعات پرداخت ارسال می‌شود.', 600000.00, NULL, 'pending', NULL, NULL, NULL, NULL, NULL, '2026-03-14 10:53:33', '2026-03-14 10:53:33'),
(33, 19, 1, '۳۲۱ یورو', 'با قیمت پایین تر بخرید', 5520000.00, '٧٠ ملیون  به حساب رضا خلیل پور IR100120000000001834552380  شماره کارت 6104337842155481 ش ح  1834552380', 'confirmed', NULL, NULL, NULL, NULL, NULL, '2026-03-14 14:35:46', '2026-03-28 19:55:57'),
(34, 18, 1, 'تمدید اشتراک - اشتراک یورو ارزان قیمت', 'درخواست تمدید اشتراک شما ثبت شد. پس از تایید ادمین، اطلاعات پرداخت ارسال می‌شود.', 600000.00, NULL, 'pending', NULL, NULL, NULL, NULL, NULL, '2026-03-14 14:36:11', '2026-03-14 14:36:11'),
(35, 18, 1, '۳۳۳', 'قیمت جدید', 552000000.00, NULL, 'requested', NULL, NULL, NULL, NULL, NULL, '2026-03-14 14:36:36', '2026-03-14 14:59:22'),
(36, 18, 1, 'تمدید اشتراک - اشتراک یورو ارزان قیمت', 'درخواست تمدید اشتراک شما ثبت شد. پس از تایید ادمین، اطلاعات پرداخت ارسال می‌شود.', 600000.00, 'M7655', 'confirmed', NULL, NULL, NULL, NULL, NULL, '2026-03-14 15:01:00', '2026-03-14 15:03:47');

-- --------------------------------------------------------

--
-- Table structure for table `subscription_plans`
--

CREATE TABLE `subscription_plans` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(20,2) NOT NULL,
  `duration_days` int(11) NOT NULL COMMENT 'مدت اعتبار به روز',
  `features` text DEFAULT NULL COMMENT 'ویژگی‌های پلن',
  `is_active` tinyint(1) DEFAULT 1,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `subscription_plans`
--

INSERT INTO `subscription_plans` (`id`, `name`, `description`, `price`, `duration_days`, `features`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES
(4, 'اشتراک یورو ارزان قیمت', NULL, 600000.00, 30, 'شما با عضویت در این پنل با پرداخت ماهانه مبلغ پلن , پیشنهاد یورو با قیمت عالی و زیر قیمت بازار دریافت خواهید کرد', 1, 0, '2026-03-06 13:54:28', '2026-03-06 13:54:28');

-- --------------------------------------------------------

--
-- Table structure for table `temp_reject_reasons`
--

CREATE TABLE `temp_reject_reasons` (
  `user_id` varchar(50) NOT NULL,
  `offer_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `topup_requests`
--

CREATE TABLE `topup_requests` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `currency` enum('USD','EUR','USDT') NOT NULL DEFAULT 'USD',
  `amount` decimal(15,2) NOT NULL,
  `status` enum('pending','approved','waiting_payment','payment_received','completed','rejected') DEFAULT 'pending',
  `reject_reason` text DEFAULT NULL COMMENT 'دلیل رد ادمین',
  `bank_name` varchar(255) DEFAULT NULL COMMENT 'نام بانک گیرنده',
  `account_number` varchar(100) DEFAULT NULL COMMENT 'شماره حساب',
  `card_number` varchar(30) DEFAULT NULL COMMENT 'شماره کارت',
  `recipient_name` varchar(255) DEFAULT NULL COMMENT 'نام صاحب حساب',
  `iban` varchar(100) DEFAULT NULL COMMENT 'IBAN/شبا',
  `receipt_image` varchar(500) DEFAULT NULL COMMENT 'مسیر تصویر فیش واریز',
  `admin_id` int(11) DEFAULT NULL COMMENT 'آیدی ادمین مسئول',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `topup_requests`
--

INSERT INTO `topup_requests` (`id`, `user_id`, `currency`, `amount`, `status`, `reject_reason`, `bank_name`, `account_number`, `card_number`, `recipient_name`, `iban`, `receipt_image`, `admin_id`, `created_at`, `updated_at`) VALUES
(5, 17, 'USD', 50.00, 'completed', NULL, 'ندا لطفی', '55655454566', '5555555656598652315', 'شیسیسیسی', '', '/ledor/uploads/topup_receipts/topup_5_1782656305.jpeg', 1, '2026-06-28 14:17:46', '2026-06-28 14:18:43'),
(6, 17, 'USDT', 20.00, 'completed', NULL, 'تذلتت', 'ترلتت', '567789', '$?', '', '/ledor/uploads/topup_receipts/topup_6_1782766133_0.png', 1, '2026-06-28 14:38:45', '2026-06-29 20:50:09'),
(7, 1, 'USD', 50.00, 'rejected', '6554', NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-06-28 15:41:53', '2026-06-28 23:01:08'),
(8, 1, 'USD', 50.00, 'rejected', 'Rad', NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-06-28 16:21:58', '2026-06-28 16:22:23'),
(9, 1, 'USD', 50.00, 'rejected', 'خه', NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-06-28 23:01:17', '2026-06-29 09:19:05'),
(10, 1, 'USD', 50.00, 'rejected', 'SD', NULL, NULL, NULL, NULL, NULL, NULL, 1, '2026-06-29 19:00:08', '2026-06-29 19:23:41'),
(11, 1, 'USD', 50.00, 'completed', NULL, 'ندا لطفی', '55655454566', '5555555656598652315', 'شیسیسیسی', '', '/ledor/uploads/topup_receipts/topup_11_1782762513_0.png', 1, '2026-06-29 19:23:57', '2026-06-29 19:48:46'),
(12, 1, 'USD', 200.00, 'completed', NULL, 'ندا لطفی', '55655454566', '5555555656598652315', 'شیسیسیسی', '', '[\"\\/ledor\\/uploads\\/topup_receipts\\/topup_12_1782762615_0.png\",\"\\/ledor\\/uploads\\/topup_receipts\\/topup_12_1782762615_1.png\"]', 1, '2026-06-29 19:49:06', '2026-06-29 19:51:10'),
(13, 1, 'USD', 50.00, 'completed', NULL, 'سیس', '55655454566', '5555555656598652315', 'شیسیسیسی', '', '[\"\\/ledor\\/uploads\\/topup_receipts\\/topup_13_1782762773_0.jpeg\",\"\\/ledor\\/uploads\\/topup_receipts\\/topup_13_1782762773_1.jpeg\"]', 1, '2026-06-29 19:51:33', '2026-06-29 20:02:05'),
(14, 1, 'USDT', 100.00, 'completed', NULL, 'Test', '655677', '6556677', 'Hhghjbb', '', '/ledor/uploads/topup_receipts/topup_14_1782765302_0.png', 1, '2026-06-29 20:34:26', '2026-06-29 20:35:11');

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `id` int(11) NOT NULL,
  `transaction_id` varchar(50) NOT NULL,
  `sender_id` int(11) DEFAULT NULL,
  `receiver_id` int(11) DEFAULT NULL,
  `amount` decimal(15,6) DEFAULT NULL,
  `currency` enum('USD','EUR','USDT','IRR') DEFAULT NULL,
  `type` enum('send','receive','deposit','withdrawal','admin_credit','admin_debit') DEFAULT NULL,
  `description` mediumtext DEFAULT NULL,
  `status` enum('pending','completed','failed','cancelled') DEFAULT 'completed',
  `admin_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `completed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `transactions`
--

INSERT INTO `transactions` (`id`, `transaction_id`, `sender_id`, `receiver_id`, `amount`, `currency`, `type`, `description`, `status`, `admin_id`, `created_at`, `updated_at`, `completed_at`) VALUES
(130, 'TX17685914767543', 1, 18, 123.000000, 'USD', 'send', 'Love❤️', 'completed', NULL, '2026-01-16 19:24:36', '2026-01-16 19:24:36', NULL),
(131, 'TX17690894748510', 1, 17, 12.000000, 'USD', 'send', 'Darlehen', 'completed', NULL, '2026-01-22 13:44:34', '2026-01-22 13:44:34', NULL),
(132, 'TX17690895284863', 1, 17, 33.000000, 'USD', 'send', 'Test', 'completed', NULL, '2026-01-22 13:45:28', '2026-01-22 13:45:28', NULL),
(133, 'ADM17691556921487', 1, 15, 3287000.000000, 'IRR', 'deposit', '[ADMIN ACTION - Admin Added Funds] طلب شما از ما  | Amount: 3287000 IRR | Previous Balance: 0.00 IRR | New Balance: 3287000 IRR', 'completed', 1, '2026-01-23 08:08:12', '2026-01-23 08:08:12', NULL),
(134, 'ADM17691557295269', 1, 17, 0.010000, 'USD', 'deposit', '[ADMIN ACTION - Admin Added Funds] طلب شما از ما | Amount: 0.01 USD | Previous Balance: 45.00 USD | New Balance: 45.01 USD', 'completed', 1, '2026-01-23 08:08:49', '2026-01-23 08:08:49', NULL),
(135, 'ADM17691612223914', 1, 20, 1.000000, 'USD', 'deposit', '[ADMIN ACTION - Admin Added Funds] Admin balance adjustment | Amount: 1 USD | Previous Balance: 0.00 USD | New Balance: 1 USD', 'completed', 1, '2026-01-23 09:40:22', '2026-01-23 09:40:22', NULL),
(136, 'ADM17691612542491', 1, 17, 1.000000, 'USD', 'deposit', '[ADMIN ACTION - Admin Added Funds] Admin balance adjustment | Amount: 1 USD | Previous Balance: 45.01 USD | New Balance: 46.01 USD', 'completed', 1, '2026-01-23 09:40:54', '2026-01-23 09:40:54', NULL),
(137, 'ADM17691612774581', 1, 20, 1.000000, 'USD', 'deposit', '[ADMIN ACTION - Admin Added Funds] Admin balance adjustment | Amount: 1 USD | Previous Balance: 1.00 USD | New Balance: 2 USD', 'completed', 1, '2026-01-23 09:41:17', '2026-01-23 09:41:17', NULL),
(138, 'WD17691613964404', 17, NULL, 2.000000, 'USD', 'withdrawal', 'Withdrawal request to Hgg - AV55ADM000001667788765 (Pending approval)', 'completed', 1, '2026-01-23 09:43:16', '2026-01-23 09:43:43', '2026-01-23 10:43:43'),
(139, 'WD17691669878517', 17, NULL, 10.000000, 'USD', 'withdrawal', 'Withdrawal request to qwqw - 54545464654646455646545646455646 (Pending approval)', 'completed', 1, '2026-01-23 11:16:27', '2026-01-23 11:16:40', '2026-01-23 12:16:40'),
(140, 'TX17698587312209', 1, 21, 1.000000, 'USD', 'send', 'Test', 'completed', NULL, '2026-01-31 11:25:31', '2026-01-31 11:25:31', NULL),
(141, 'ADM17700606087586', 1, 12, 995.000000, 'EUR', 'deposit', '[ADMIN ACTION - Admin Added Funds] خرید یورو | Amount: 995 EUR | Previous Balance: 0.00 EUR | New Balance: 995 EUR', 'completed', 1, '2026-02-02 19:30:08', '2026-02-02 19:30:08', NULL),
(142, 'ADM17700606244715', 1, 1, 1.000000, 'USD', 'deposit', '[ADMIN ACTION - Admin Added Funds] Admin balance adjustment | Amount: 1 USD | Previous Balance: 100472.00 USD | New Balance: 100473 USD', 'completed', 1, '2026-02-02 19:30:24', '2026-02-02 19:30:24', NULL),
(143, 'ADM17701078079743', 12, 1, 995.000000, 'EUR', 'withdrawal', '[ADMIN ACTION - Admin Deducted Funds] تسویه خرید یورو | Amount: 995 EUR | Previous Balance: 995.00 EUR | New Balance: 0 EUR', 'completed', 1, '2026-02-03 08:36:47', '2026-02-03 08:36:47', NULL),
(144, 'TX17701242321967', 1, 26, 3.000000, 'USD', 'send', 'Gift', 'completed', NULL, '2026-02-03 13:10:32', '2026-02-03 13:10:32', NULL),
(145, 'TX17701243166569', 1, 27, 3.000000, 'USD', 'send', 'Gift', 'completed', NULL, '2026-02-03 13:11:56', '2026-02-03 13:11:56', NULL),
(146, 'TX17702063446046', 1, 17, 1.000000, 'USD', 'send', 'Transaction', 'completed', NULL, '2026-02-04 11:59:04', '2026-02-04 11:59:04', NULL),
(147, 'TX17702063735571', 1, 17, 1.000000, 'USD', 'send', 'Transaction', 'completed', NULL, '2026-02-04 11:59:33', '2026-02-04 11:59:33', NULL),
(148, 'TX17702064168133', 1, 17, 1.000000, 'USD', 'send', 'Transaction', 'completed', NULL, '2026-02-04 12:00:16', '2026-02-04 12:00:16', NULL),
(149, 'TX17702066667284', 1, 17, 1.000000, 'USD', 'send', 'Transaction', 'completed', NULL, '2026-02-04 12:04:26', '2026-02-04 12:04:26', NULL),
(150, 'ADM17707550165799', 1, 30, 714420000.000000, 'IRR', 'deposit', '[ADMIN ACTION - Admin Added Funds] فروش تتر  | Amount: 714420000 IRR | Previous Balance: 0.00 IRR | New Balance: 714420000 IRR', 'completed', 1, '2026-02-10 20:23:36', '2026-02-10 20:23:36', NULL),
(151, 'ADM17707676071278', 30, 1, 714420000.000000, 'IRR', 'withdrawal', '[ADMIN ACTION - Admin Deducted Funds] تسویه شد | Amount: 714420000 IRR | Previous Balance: 714420000.00 IRR | New Balance: 0 IRR', 'completed', 1, '2026-02-10 23:53:27', '2026-02-10 23:53:27', NULL),
(152, 'ADM17715341782137', 1, 1, 123000.000000, 'USD', 'deposit', '[ADMIN ACTION - Admin Added Funds]  | Amount: 123000 USD | Previous Balance: 100463.00 USD | New Balance: 223463 USD', 'completed', 1, '2026-02-19 20:49:38', '2026-02-19 20:49:38', NULL),
(153, 'TX17715752142162', 17, 1, 1.000000, 'USD', 'send', 'تست', 'completed', NULL, '2026-02-20 08:13:34', '2026-02-20 08:13:34', NULL),
(154, 'ADM17716158834187', 1, 1, 1.000000, 'USD', 'deposit', '[ADMIN ACTION - Admin Added Funds] Admin balance adjustment | Amount: 1 USD | Previous Balance: 223464.00 USD | New Balance: 223465 USD', 'completed', 1, '2026-02-20 19:31:23', '2026-02-20 19:31:23', NULL),
(155, 'ADM17716158875072', 1, 1, 1.000000, 'USD', 'deposit', '[ADMIN ACTION - Admin Added Funds] Admin balance adjustment | Amount: 1 USD | Previous Balance: 223465.00 USD | New Balance: 223466 USD', 'completed', 1, '2026-02-20 19:31:27', '2026-02-20 19:31:27', NULL),
(156, 'ADM17716159133010', 1, 1, 1.000000, 'USD', 'deposit', '[ADMIN ACTION - Admin Added Funds] Admin balance adjustment | Amount: 1 USD | Previous Balance: 223466.00 USD | New Balance: 223467 USD', 'completed', 1, '2026-02-20 19:31:53', '2026-02-20 19:31:53', NULL),
(157, 'ADM17716657832989', 1, 17, 21.000000, 'EUR', 'deposit', '[ADMIN ACTION - Admin Added Funds] Admin balance adjustment | Amount: 21 EUR | Previous Balance: 0.00 EUR | New Balance: 21 EUR', 'completed', 1, '2026-02-21 09:23:03', '2026-02-21 09:23:03', NULL),
(158, 'ADM17716658361028', 1, 17, 21.000000, 'EUR', 'deposit', '[ADMIN ACTION - Admin Added Funds] Admin balance adjustment | Amount: 21 EUR | Previous Balance: 21.00 EUR | New Balance: 42 EUR', 'completed', 1, '2026-02-21 09:23:56', '2026-02-21 09:23:56', NULL),
(159, 'ADM17716658699375', 1, 1, 187.000000, 'USD', 'deposit', '[ADMIN ACTION - Admin Added Funds] Admin balance adjustment | Amount: 187 USD | Previous Balance: 223467.00 USD | New Balance: 223654 USD', 'completed', 1, '2026-02-21 09:24:29', '2026-02-21 09:24:29', NULL),
(160, 'ADM17716658774686', 1, 1, 187.000000, 'USD', 'withdrawal', '[ADMIN ACTION - Admin Deducted Funds] Admin balance adjustment | Amount: 187 USD | Previous Balance: 223654.00 USD | New Balance: 223467 USD', 'completed', 1, '2026-02-21 09:24:37', '2026-02-21 09:24:37', NULL),
(161, 'ADM17716663859789', 1, 1, 12.000000, 'USD', 'deposit', '[Admin +] Admin balance adjustment: +12 USD (223467 → 223479)', 'completed', 1, '2026-02-21 09:33:05', '2026-02-21 09:33:05', NULL),
(162, 'ADM17716664998033', 1, 1, 1.000000, 'USD', 'deposit', '[ADMIN ACTION - Admin Added Funds] Admin balance adjustment | Amount: 1 USD | Previous Balance: 223479.00 USD | New Balance: 223480 USD', 'completed', 1, '2026-02-21 09:34:59', '2026-02-21 09:34:59', NULL),
(163, 'ADM17716665548782', 1, 1, 1.000000, 'USD', 'deposit', '[ADMIN ACTION - Admin Added Funds] Admin balance adjustment | Amount: 1 USD | Previous Balance: 223480.00 USD | New Balance: 223481 USD', 'completed', 1, '2026-02-21 09:35:54', '2026-02-21 09:35:54', NULL),
(164, 'ADM17716669996249', 1, 17, 21.000000, 'USD', 'deposit', '[Admin +] Admin balance adjustment: +21 USD (37 → 58)', 'completed', 1, '2026-02-21 09:43:19', '2026-02-21 09:43:19', NULL),
(165, 'ADM17716670447521', 1, 17, 25.000000, 'USD', 'deposit', '[Admin +] test: +25 USD (58 → 83)', 'completed', 1, '2026-02-21 09:44:04', '2026-02-21 09:44:04', NULL),
(166, 'ADM17716670678047', 17, 1, 50.000000, 'USD', 'withdrawal', '[Admin -] خرید: -50 USD (83 → 33)', 'completed', 1, '2026-02-21 09:44:27', '2026-02-21 09:44:27', NULL),
(167, 'ADM17716671751617', 1, 1, 21.000000, 'USD', 'deposit', '[Admin +] Admin balance adjustment: +21 USD (223481 → 223502)', 'completed', 1, '2026-02-21 09:46:15', '2026-02-21 09:46:15', NULL),
(168, 'ADM17716672967236', 1, 17, 1.000000, 'USD', 'deposit', '[ADMIN ACTION - Admin Added Funds] Admin balance adjustment | Amount: 1 USD | Previous Balance: 33.01 USD | New Balance: 34.01 USD', 'completed', 1, '2026-02-21 09:48:16', '2026-02-21 09:48:16', NULL),
(169, 'ADM17716678328006', 1, 17, 2.000000, 'USD', 'deposit', '[ADMIN ACTION - Admin Added Funds] Admin balance adjustment | Amount: 2 USD | Previous Balance: 34.01 USD | New Balance: 36.01 USD', 'completed', 1, '2026-02-21 09:57:12', '2026-02-21 09:57:12', NULL),
(170, 'TX17716679187070', 17, 1, 24.000000, 'EUR', 'send', 'Test', 'completed', NULL, '2026-02-21 09:58:38', '2026-02-21 09:58:38', NULL),
(171, 'ADM17716680063270', 1, 1, 21.000000, 'USD', 'deposit', '[Admin +] Admin balance adjustment: +21 USD (223502 → 223523)', 'completed', 1, '2026-02-21 10:00:06', '2026-02-21 10:00:06', NULL),
(172, 'WD17720496586371', 1, NULL, 22.000000, 'USD', 'withdrawal', 'Withdrawal request to Shi - Sd2234555543221223 (Pending approval)', 'completed', 1, '2026-02-25 20:00:58', '2026-02-25 20:02:10', '2026-02-25 21:02:10'),
(173, 'ADM17734085699085', 1, 1, 50555000.000000, 'IRR', 'deposit', '[Admin +] Admin balance adjustment: +50555000 IRR (1 → 50555001)', 'completed', 1, '2026-03-13 13:29:29', '2026-03-13 13:29:29', NULL),
(174, 'ADM17744319161293', 1, 14, 600.000000, 'USDT', 'deposit', '[Admin +] فروش تتر: +600 USDT (0 → 600)', 'completed', 1, '2026-03-25 09:45:16', '2026-03-25 09:45:16', NULL),
(175, 'WD17744327473467', 14, NULL, 600.000000, 'USDT', 'withdrawal', 'Withdrawal request to Mellat - IR100120000000001834552380 (Pending approval)', 'completed', 1, '2026-03-25 09:59:07', '2026-03-26 13:45:50', '2026-03-26 14:45:50'),
(176, 'ADM17753815012562', 1, 14, 1160.000000, 'USDT', 'deposit', '[Admin +] Admin balance adjustment: +1160 USDT (0 → 1160)', 'completed', 1, '2026-04-05 09:31:41', '2026-04-05 09:31:41', NULL),
(177, 'WD17753817276596', 14, NULL, 1160.000000, 'USDT', 'withdrawal', 'Withdrawal request to Mellat - IR100120000000001834552380 (Pending approval)', 'completed', 1, '2026-04-05 09:35:27', '2026-04-06 15:42:47', '2026-04-06 17:42:47'),
(178, 'ADM17783102171236', 1, 1, 162.000000, 'EUR', 'withdrawal', '[Admin -] Admin balance adjustment: -162 EUR (161.00 → (-1.00)) ⚠️ موجودی منفی شد! (بدهکار: 1.00 EUR)', 'completed', 1, '2026-05-09 07:03:37', '2026-05-09 07:03:37', NULL),
(179, 'ADM17783102853421', 1, 1, 162.000000, 'EUR', 'withdrawal', '[Admin -] Admin balance adjustment: -162 EUR ((-1.00) → (-163.00)) ⚠️ موجودی منفی شد! (بدهکار: 163.00 EUR)', 'completed', 1, '2026-05-09 07:04:45', '2026-05-09 07:04:45', NULL),
(180, 'ADM17786179486929', 12, 1, 2.500000, 'EUR', 'withdrawal', '[Admin -] طلبکار از فروش ٣٠٠ یورو : -2 EUR (0.00 → (-2.50)) ⚠️ موجودی منفی شد! (بدهکار: 2.50 EUR)', 'completed', 1, '2026-05-12 20:32:28', '2026-05-12 20:32:28', NULL),
(181, 'ADM17786718122416', 12, 1, 7.500000, 'EUR', 'withdrawal', '[Admin -] کمیسیون خرید 200 یورو : -7 EUR ((-2.50) → (-10.00)) ⚠️ موجودی منفی شد! (بدهکار: 10.00 EUR)', 'completed', 1, '2026-05-13 11:30:12', '2026-05-13 11:30:12', NULL),
(182, 'ADM17788849605305', 17, 1, 30.000000, 'USD', 'withdrawal', '[Admin decrease] تست: -30 USD (36.01 → 6.01)', 'completed', 1, '2026-05-15 22:42:40', '2026-05-15 22:42:40', NULL),
(183, 'ADM17788849803010', 1, 17, 30.000000, 'USD', 'deposit', '[Admin increase] سس: +30 USD (6.01 → 36.01)', 'completed', 1, '2026-05-15 22:43:00', '2026-05-15 22:43:00', NULL),
(184, 'ADM17791373429765', 1, 12, 10.000000, 'EUR', 'deposit', '[Admin increase] تسویه شد کمیسیون ١٠ یورو: +10 EUR (-10.00 → 0)', 'completed', 1, '2026-05-18 20:49:02', '2026-05-18 20:49:02', NULL),
(185, 'ADM17793838408476', 12, 1, 8.000000, 'EUR', 'withdrawal', '[Admin decrease] واریز ١٠٠٣ یورو : -8 EUR (0.00 → -8) ⚠️ Negative balance!', 'completed', 1, '2026-05-21 17:17:20', '2026-05-21 17:17:20', NULL),
(186, 'WD17794963184820', 1, NULL, 10.000000, 'USD', 'withdrawal', 'Withdrawal request to شسش - 6664452151515141245445 (Pending approval)', 'completed', 1, '2026-05-23 00:31:58', '2026-05-23 00:32:19', '2026-05-23 02:32:19'),
(187, 'WD17794968144012', 1, NULL, 5055001.000000, 'IRR', 'withdrawal', 'Withdrawal request to سیسسیسی - ۱۲۳۱۳۲۳۲۳۲۳۲۳۲۳۲ (Pending approval)', 'completed', 1, '2026-05-23 00:40:14', '2026-05-23 00:40:26', '2026-05-23 02:40:26'),
(188, 'WD17794973752561', 1, NULL, 123.000000, 'USD', 'withdrawal', 'Withdrawal request to سیسسیسی - ۳۲۲۳۳۳۳۳۳۳۳۳۳۳۳۳۳۳۳۳۳۳۳۳۳۳۳۳۳۳ (Pending approval)', 'completed', 1, '2026-05-23 00:49:35', '2026-05-23 00:49:48', '2026-05-23 02:49:48'),
(189, 'ADM17796891238376', 12, 1, 5.000000, 'EUR', 'withdrawal', '[Admin decrease] کمیسیون خرید ١٠١۵ یورو : -5 EUR (-8.00 → -13) ⚠️ Negative balance!', 'completed', 1, '2026-05-25 06:05:23', '2026-05-25 06:05:23', NULL),
(190, 'ADM17796891916865', 1, 12, 15.000000, 'EUR', 'deposit', '[Admin increase] تسویه کمیسیون و طلب شما ٢ یورو: +15 EUR (-13.00 → 2)', 'completed', 1, '2026-05-25 06:06:31', '2026-05-25 06:06:31', NULL),
(191, 'ADM17797061965066', 1, 17, 50000000.000000, 'IRR', 'deposit', '[Admin increase] Admin adjustment: +50000000 IRR (0.00 → 50000000)', 'completed', 1, '2026-05-25 10:49:56', '2026-05-25 10:49:56', NULL),
(192, 'ADM17797130388143', 1, 1, 500000000.000000, 'USD', 'deposit', '[Admin increase] Admin adjustment: +500000000 USD (223060.00 → 500223060)', 'completed', 1, '2026-05-25 12:43:58', '2026-05-25 12:43:58', NULL),
(193, 'ADM17797131473895', 1, 1, 500000000.000000, 'USD', 'deposit', '[Admin increase] Admin adjustment: +500000000 USD (500223060.00 → 1000223060)', 'completed', 1, '2026-05-25 12:45:47', '2026-05-25 12:45:47', NULL),
(194, 'ADM17804012943383', 1, 37, 97500000.000000, 'IRR', 'deposit', '[Admin increase] فروش یورو شماره آگهی 855 : +97500000 IRR (0.00 → 97500000)', 'completed', 1, '2026-06-02 11:54:54', '2026-06-02 11:54:54', NULL),
(195, 'ADM17806384034012', 1, 37, 196000000.000000, 'IRR', 'deposit', '[Admin increase] فروش یورو ١٠٠٠: +196000000 IRR (0.00 → 196000000)', 'completed', 1, '2026-06-05 05:46:43', '2026-06-05 05:46:43', NULL),
(196, 'ADM17806896359304', 12, 1, 5.000000, 'EUR', 'withdrawal', '[Admin decrease] کمیسیون آگهی ٨۵٨ : -5 EUR (2.00 → -3) ⚠️ Negative balance!', 'completed', 1, '2026-06-05 20:00:35', '2026-06-05 20:00:35', NULL),
(197, 'TX17811292582177', 1, 17, 23.000000, 'USD', 'send', '1', 'completed', NULL, '2026-06-10 22:07:38', '2026-06-10 22:07:38', NULL),
(198, 'TX17811293636886', 1, 17, 1.000000, 'USD', 'send', 'Transaction', 'completed', NULL, '2026-06-10 22:09:23', '2026-06-10 22:09:23', NULL),
(199, 'TX17811294218279', 1, 17, 1.000000, 'USD', 'send', 'Transaction', 'completed', NULL, '2026-06-10 22:10:21', '2026-06-10 22:10:21', NULL),
(200, 'TX17812122219841', 1, 17, 1.000000, 'USD', 'send', 'Transaction', 'completed', NULL, '2026-06-11 21:10:21', '2026-06-11 21:10:21', NULL),
(201, 'ADM17813442314382', 1, 37, 58200000.000000, 'IRR', 'deposit', '[Admin increase] فروش یورو: +58200000 IRR (0.00 → 58200000)', 'completed', 1, '2026-06-13 09:50:31', '2026-06-13 09:50:31', NULL),
(202, 'ADM17818053387257', 1, 37, 344000000.000000, 'IRR', 'deposit', '[Admin increase] 2000€ فروش: +344000000 IRR (0.00 → 344000000)', 'completed', 1, '2026-06-18 17:55:38', '2026-06-18 17:55:38', NULL),
(203, 'ADM17818606975127', 1, 14, 54950000.000000, 'IRR', 'deposit', '[Admin increase] فروش ٣۵٠ تتر : +54950000 IRR (0.00 → 54950000)', 'completed', 1, '2026-06-19 09:18:17', '2026-06-19 09:18:17', NULL),
(204, 'ADM17818723497502', 37, 1, 3.000000, 'EUR', 'withdrawal', '[Admin decrease] : -3 EUR (0.00 → -3) ⚠️ Negative balance!', 'completed', 1, '2026-06-19 12:32:29', '2026-06-19 12:32:29', NULL),
(205, 'ADM17818724097361', 37, 1, 3.000000, 'EUR', 'withdrawal', '[Admin decrease] : -3 EUR (-3.00 → -6) ⚠️ Negative balance!', 'completed', 1, '2026-06-19 12:33:29', '2026-06-19 12:33:29', NULL),
(206, 'ADM17818724099172', 37, 1, 3.000000, 'EUR', 'withdrawal', '[Admin decrease] : -3 EUR (-6.00 → -9) ⚠️ Negative balance!', 'completed', 1, '2026-06-19 12:33:29', '2026-06-19 12:33:29', NULL),
(207, 'ADM17818724581721', 1, 37, 6.000000, 'EUR', 'deposit', '[Admin increase] : +6 EUR (-9.00 → -3)', 'completed', 1, '2026-06-19 12:34:18', '2026-06-19 12:34:18', NULL),
(208, 'TOPUP17826545087583', 1, 17, 50.000000, 'USD', 'deposit', '[Top-up] شارژ 50 USD توسط ادمین تأیید شد', 'completed', 1, '2026-06-28 13:48:28', '2026-06-28 13:48:28', NULL),
(209, 'TOPUP17826563238826', 1, 17, 50.000000, 'USD', 'deposit', '[Top-up] شارژ 50 USD توسط ادمین تأیید شد', 'completed', 1, '2026-06-28 14:18:43', '2026-06-28 14:18:43', NULL),
(210, 'QA17826564162530', 1, 1, 50.000000, 'USD', 'withdrawal', '[Quick Action] کسر موجودی بابت درخواست: test', 'completed', NULL, '2026-06-28 14:20:16', '2026-06-28 14:20:16', NULL),
(211, 'QA17826564222613', 1, 1, 50.000000, 'USD', 'withdrawal', '[Quick Action] کسر موجودی بابت درخواست: test', 'completed', NULL, '2026-06-28 14:20:22', '2026-06-28 14:20:22', NULL),
(212, 'QA17826564835028', 1, 1, 50.000000, 'USD', 'withdrawal', '[Quick Action] کسر موجودی بابت درخواست: test', 'completed', NULL, '2026-06-28 14:21:23', '2026-06-28 14:21:23', NULL),
(213, 'QA17826564884919', 1, 1, 50.000000, 'USD', 'withdrawal', '[Quick Action] کسر موجودی بابت درخواست: test', 'completed', NULL, '2026-06-28 14:21:28', '2026-06-28 14:21:28', NULL),
(214, 'QA17826574619784', 17, 1, 50.000000, 'USD', 'withdrawal', '[Quick Action] کسر موجودی بابت درخواست: test', 'completed', NULL, '2026-06-28 14:37:41', '2026-06-28 14:37:41', NULL),
(215, 'INV17826621792527', 1, 17, 455.000000, 'USD', 'deposit', '[Invoice] تکمیل فیش #1 - 455 USD', 'completed', 1, '2026-06-28 15:56:19', '2026-06-28 15:56:19', NULL),
(216, 'ADM17827393257893', 1, 1, 100.000000, 'USD', 'withdrawal', '[Admin decrease] Admin adjustment: -100 USD (1000142834.00 → 1000142734)', 'completed', 1, '2026-06-29 13:22:05', '2026-06-29 13:22:05', NULL),
(217, 'TOPUP17827625262722', 1, 1, 50.000000, 'USD', 'deposit', '[Top-up] شارژ 50 USD توسط ادمین تأیید شد', 'completed', 1, '2026-06-29 19:48:46', '2026-06-29 19:48:46', NULL),
(218, 'TOPUP17827626704894', 1, 1, 200.000000, 'USD', 'deposit', '[Top-up] شارژ 200 USD توسط ادمین تأیید شد', 'completed', 1, '2026-06-29 19:51:10', '2026-06-29 19:51:10', NULL),
(219, 'TOPUP17827633257566', 1, 1, 50.000000, 'USD', 'deposit', '[Top-up] شارژ 50 USD توسط ادمین تأیید شد', 'completed', 1, '2026-06-29 20:02:05', '2026-06-29 20:02:05', NULL),
(220, 'TOPUP17827653111479', 1, 1, 100.000000, 'USDT', 'deposit', '[Top-up] شارژ 100 USDT توسط ادمین تأیید شد', 'completed', 1, '2026-06-29 20:35:11', '2026-06-29 20:35:11', NULL),
(221, 'TOPUP17827662092444', 1, 17, 20.000000, 'USDT', 'deposit', '[Top-up] شارژ 20 USDT توسط ادمین تأیید شد', 'completed', 1, '2026-06-29 20:50:09', '2026-06-29 20:50:09', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `transfer_receipts`
--

CREATE TABLE `transfer_receipts` (
  `id` int(11) NOT NULL,
  `request_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` enum('payment','settlement') NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_name` varchar(200) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `unpaid_invoices`
--

CREATE TABLE `unpaid_invoices` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `currency` enum('USD','EUR','USDT','IRR') NOT NULL DEFAULT 'USD',
  `amount` decimal(15,2) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('pending','approved','paid','finalized','rejected') DEFAULT 'pending',
  `reject_reason` text DEFAULT NULL,
  `bank_name` varchar(255) DEFAULT NULL,
  `account_number` varchar(100) DEFAULT NULL,
  `card_number` varchar(30) DEFAULT NULL,
  `recipient_name` varchar(255) DEFAULT NULL,
  `iban` varchar(100) DEFAULT NULL,
  `receipt_image` varchar(500) DEFAULT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `admin_final_note` text DEFAULT NULL,
  `admin_final_image` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `unpaid_invoices`
--

INSERT INTO `unpaid_invoices` (`id`, `user_id`, `currency`, `amount`, `description`, `status`, `reject_reason`, `bank_name`, `account_number`, `card_number`, `recipient_name`, `iban`, `receipt_image`, `admin_id`, `admin_final_note`, `admin_final_image`, `created_at`, `updated_at`) VALUES
(1, 17, 'USD', 455.00, '', 'finalized', NULL, 'صشسیسی', 'شیششیس', '۲۳۲۳۲', '۲۳۲۳۲۳۲۳', '', '/ledor/uploads/invoice_receipts/invoice_1_1782662164.jpeg', 1, 'سیسیسی', NULL, '2026-06-28 15:54:34', '2026-06-28 15:56:19'),
(2, 1, 'USD', 50.00, 'خرید چیزی', 'approved', NULL, 'تذل', 'ندل', '١٢٣۴', '»؟)', '', NULL, 1, NULL, NULL, '2026-06-28 16:23:04', '2026-06-28 16:23:41'),
(3, 1, 'USD', 32.00, '', 'approved', NULL, 'Hgf', 'Jvf', '356543', '!?(', '', NULL, 1, NULL, NULL, '2026-06-28 16:42:50', '2026-06-28 16:43:18'),
(4, 1, 'EUR', 54.00, 'KKL;', 'paid', NULL, 'SDSD', 'SDSDS', '23232324', '242434', '', '/ledor/uploads/invoice_receipts/invoice_4_1782761569.png', 1, NULL, NULL, '2026-06-28 23:01:41', '2026-06-29 19:32:49'),
(5, 1, 'EUR', 55.00, 'بابت وسترن یونیون به هرماه', 'approved', NULL, 'داات', 'ذلاا', 'دااتت', 'دذ', '', NULL, 1, NULL, NULL, '2026-06-29 20:39:23', '2026-06-29 20:40:04');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `telegram_id` varchar(50) NOT NULL,
  `iban_number` varchar(20) NOT NULL,
  `account_number` varchar(15) NOT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `balance_usd` decimal(15,2) DEFAULT 0.00,
  `balance_eur` decimal(15,2) DEFAULT 0.00,
  `balance_usdt` decimal(15,6) DEFAULT 0.000000,
  `balance_irr` decimal(15,2) DEFAULT 0.00,
  `is_admin` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_login` datetime DEFAULT NULL,
  `role` enum('user','admin') DEFAULT 'user',
  `completed_orders_count` int(11) DEFAULT 0,
  `loyalty_discount` int(11) DEFAULT 0,
  `app_version` varchar(20) DEFAULT '1.0.0',
  `phone` varchar(20) DEFAULT NULL,
  `push_enabled` tinyint(4) DEFAULT 1,
  `last_push_sync` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `telegram_id`, `iban_number`, `account_number`, `first_name`, `last_name`, `phone_number`, `email`, `avatar`, `balance_usd`, `balance_eur`, `balance_usdt`, `balance_irr`, `is_admin`, `created_at`, `updated_at`, `last_login`, `role`, `completed_orders_count`, `loyalty_discount`, `app_version`, `phone`, `push_enabled`, `last_push_sync`) VALUES
(1, '5330629504', 'AV55ADM000001', 'AV000015564', 'سارا', 'موحد', '17684064908', '', 'uploads/avatars/avatar_1_1771610957.jpeg', 1000143034.00, -163.00, 542.000000, 9999500000.00, 1, '2025-12-28 15:39:36', '2026-06-30 07:46:41', '2026-06-30 09:46:41', 'admin', 3, 0, '2.3.5', NULL, 1, '2026-06-04 20:04:20'),
(12, '54247368', 'AV5589B300B780', 'ACC2005', 'Mashaallah', 'Karimi', '09173510470', 'karimioph@gmail.com', 'default-avatar.png', 0.00, -3.00, 0.000000, 0.00, 0, '2025-12-31 17:18:08', '2026-06-18 21:59:48', '2026-06-15 21:58:14', 'user', 0, 0, '2.3.1', NULL, 1, NULL),
(13, '1341129467', '', 'SH23436576', 'Dr.shadi♥️', 'F', NULL, NULL, NULL, 0.00, 0.00, 0.000000, 3120000.00, 0, '2026-01-01 11:08:53', '2026-01-12 23:21:44', NULL, 'user', 0, 0, '1.0.0', NULL, 1, NULL),
(14, '1102335790', 'AV55F872886C82', 'ACC2006', 'Hossein', 'Khalilpour', '+49163 0130237', NULL, 'uploads/avatars/avatar_1768285563_6965e57b81437.jpeg', 0.00, 0.00, 0.000000, 0.00, 0, '2026-01-13 06:26:03', '2026-06-19 13:55:16', '2026-06-19 11:21:52', 'user', 0, 0, '2.2.9', NULL, 1, NULL),
(15, '90440137', 'AV55C5ADFE20B7', 'ACC2007', 'Hossein', 'Taghizadeh', '+989355860917', NULL, 'uploads/avatars/avatar_1768285741_6965e62de0ef1.jpeg', 0.00, 0.00, 0.000000, 3287000.00, 0, '2026-01-13 06:29:01', '2026-01-23 08:08:12', NULL, 'user', 0, 0, '1.0.0', NULL, 1, NULL),
(17, '484167219', 'AV55FD4106DD4A', 'AV66005607', 'Alireza ', 'Ahmadian', '017684064908', '', 'default-avatar.png', 567.01, 18.00, 20.000000, 8950000.00, 0, '2026-01-15 23:50:57', '2026-06-29 20:50:09', '2026-06-28 16:30:10', 'user', 0, 0, '2.3.6', NULL, 1, '2026-06-04 20:04:02'),
(18, '5994220772', 'AV55C3C0EA7718', 'AV66001231', 'Dr.shiva❤️', 'F', '01735984385', NULL, 'default-avatar.png', 0.00, 0.00, 0.000000, 0.00, 0, '2026-01-16 19:23:22', '2026-06-21 09:22:37', '2026-06-11 22:32:34', 'user', 0, 0, '1.0.0', NULL, 1, NULL),
(20, '09143522380', 'AV5525B5927F8B', 'AV66003521', 'Mohamad', 'Azzi', '484167219', NULL, 'default-avatar.png', 2.00, 0.00, 0.000000, 0.00, 0, '2026-01-23 09:39:51', '2026-01-23 09:41:17', NULL, 'user', 0, 0, '1.0.0', NULL, 1, NULL),
(21, '7991229687', 'AV557393EF5C59', 'ACC2008', 'Fahime', 'Safari', '017662994638', NULL, 'default-avatar.png', 1.00, 0.00, 0.000000, 0.00, 0, '2026-01-31 11:23:08', '2026-01-31 11:25:31', NULL, 'user', 0, 0, '1.0.0', NULL, 1, NULL),
(26, '258529332', 'AV55618F61D289', 'ACC2013', 'Alireza', 'Moradian', '09133295557', NULL, 'uploads/avatars/avatar_1770124054_6981f316b87ef.jpeg', 3.00, 0.00, 0.000000, 0.00, 0, '2026-02-03 13:07:34', '2026-05-09 11:18:14', '2026-05-09 13:16:46', 'user', 0, 0, '2.2.8', NULL, 1, NULL),
(27, '107639635', 'AV55A793CFB688', 'ACC2014', 'Mahdi', 'Jafari', '0643809807', NULL, 'default-avatar.png', 3.00, 0.00, 0.000000, 0.00, 0, '2026-02-03 13:11:07', '2026-02-03 13:11:56', NULL, 'user', 0, 0, '1.0.0', NULL, 1, NULL),
(29, '5978238023', 'AV55BE552B4612', 'AV56148329', 'amin', 'bayat', '+989122223568', NULL, 'default-avatar.png', 0.00, 0.00, 0.000000, 0.00, 0, '2026-02-05 09:08:45', '2026-02-05 09:08:45', NULL, 'user', 0, 0, '1.0.0', NULL, 1, NULL),
(30, '144214440', 'AV557F2350C720', 'AV56147011', 'Atena', 'Momeni', '0000000000', NULL, 'default-avatar.png', 0.00, 0.00, 0.000000, 0.00, 0, '2026-02-10 20:21:26', '2026-04-16 03:50:15', NULL, 'user', 0, 0, '2.2.7', NULL, 1, NULL),
(31, '7410010818', 'AV5546C49299B6', 'AV56141936', 'سید', 'صافی', '09029519354', NULL, 'default-avatar.png', 0.00, 0.00, 0.000000, 0.00, 0, '2026-02-12 00:23:33', '2026-02-12 00:23:33', NULL, 'user', 0, 0, '1.0.0', NULL, 1, NULL),
(32, '37319031', 'AV55A14CCA5677', 'AV56145464', 'محمد', 'امیرفتاحی', '+989360558719', NULL, 'default-avatar.png', 0.00, 0.00, 0.000000, 0.00, 0, '2026-02-16 13:21:17', '2026-02-16 13:21:17', NULL, 'user', 0, 0, '1.0.0', NULL, 1, NULL),
(35, 'Shiva', 'AV55F3AFD74742', 'AV56143741', 'Shiva', 'Fallahzadeh', '+49 173 5984385', NULL, 'default-avatar.png', 0.00, 0.00, 0.000000, 0.00, 0, '2026-02-21 12:57:04', '2026-03-30 12:47:30', NULL, 'user', 0, 0, '2.2.6', NULL, 1, NULL),
(36, '5669229198', 'AV554B60B8FD1A', 'AV56140073', 'Mehraban', 'Rahimi', '09012486716', NULL, 'default-avatar.png', 0.00, 0.00, 0.000000, 0.00, 0, '2026-03-16 21:39:53', '2026-03-16 21:39:53', NULL, 'user', 0, 0, '1.0.0', NULL, 1, NULL),
(37, '442512457', 'AV55438FA191B3', 'AV56140958', 'Issak', 'Soleyman', '0046739931171', NULL, 'default-avatar.png', 0.00, -3.00, 0.000000, 0.00, 0, '2026-04-14 11:27:30', '2026-06-19 12:34:18', '2026-06-18 22:10:06', 'user', 0, 0, '2.3.4', NULL, 1, NULL),
(38, '5766757739', 'AV553597DAF6DD', 'AV56147800', 'Alireza', 'Hashemi', '+491606016956', NULL, 'default-avatar.png', 0.00, 0.00, 0.000000, 0.00, 0, '2026-05-16 07:14:54', '2026-05-16 07:15:20', NULL, 'user', 0, 0, '2.2.9', NULL, 1, NULL),
(39, '220779701', 'AV557D222DAE44', 'AV56148596', 'mohammad', 'ghasemi', '+4917684089672', NULL, 'default-avatar.png', 0.00, 0.00, 0.000000, 0.00, 0, '2026-06-06 18:51:18', '2026-06-06 18:51:18', NULL, 'user', 0, 0, '1.0.0', NULL, 1, NULL),
(40, '76774888', 'AV553F783A78DE', 'AV56141226', 'Kamran', 'Miresmaeili', '09123081108', NULL, 'default-avatar.png', 0.00, 0.00, 0.000000, 0.00, 0, '2026-06-10 18:44:31', '2026-06-10 19:01:30', NULL, 'user', 0, 0, '2.3.5', NULL, 1, NULL),
(41, '743087186', 'AV5530B9A687CF', 'AV56143450', 'Amirhussein', 'Rezaie', '046737570790', NULL, 'default-avatar.png', 0.00, 0.00, 0.000000, 0.00, 0, '2026-06-18 11:08:21', '2026-06-18 11:15:04', '2026-06-18 13:09:55', 'user', 0, 0, '2.3.6', NULL, 1, NULL),
(42, '7485675123', 'AV555DA51E0082', 'AV56143012', 'Zahra', 'mohammadi', '09028346238', NULL, 'default-avatar.png', 0.00, 0.00, 0.000000, 0.00, 0, '2026-06-18 11:10:13', '2026-06-18 11:15:55', NULL, 'user', 0, 0, '2.3.6', NULL, 1, NULL),
(43, '234424672', 'AV554457695C96', 'AV56141314', 'Aria', 'Raessi', '0036205779738', NULL, 'default-avatar.png', 0.00, 0.00, 0.000000, 0.00, 0, '2026-06-22 12:22:48', '2026-06-22 12:25:53', '2026-06-22 14:25:53', 'user', 0, 0, '1.0.0', NULL, 1, NULL),
(44, '69213714', 'AV5540C4F800C0', 'AV56143919', 'Fereshteh', 'Naderi', '+989123984220', NULL, 'default-avatar.png', 0.00, 0.00, 0.000000, 0.00, 0, '2026-06-22 12:33:32', '2026-06-22 12:33:32', NULL, 'user', 0, 0, '1.0.0', NULL, 1, NULL),
(45, '115763417', 'AV5524FBA51278', 'AV56147483', 'Esmaeil', 'Karimi', '00989171003479', NULL, 'default-avatar.png', 0.00, 0.00, 0.000000, 0.00, 0, '2026-06-25 00:06:57', '2026-06-25 00:06:57', NULL, 'user', 0, 0, '1.0.0', NULL, 1, NULL);

--
-- Triggers `users`
--
DELIMITER $$
CREATE TRIGGER `after_user_balance_update` AFTER UPDATE ON `users` FOR EACH ROW BEGIN
    IF OLD.balance_usd != NEW.balance_usd 
    OR OLD.balance_eur != NEW.balance_eur 
    OR OLD.balance_usdt != NEW.balance_usdt 
    OR OLD.balance_irr != NEW.balance_irr THEN
        
        INSERT INTO user_balance_history 
        (user_id, balance_usd, balance_eur, balance_usdt, balance_irr)
        VALUES 
        (NEW.id, NEW.balance_usd, NEW.balance_eur, NEW.balance_usdt, NEW.balance_irr);
        
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `user_ads`
--

CREATE TABLE `user_ads` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` enum('buy','sell') NOT NULL,
  `currency` varchar(10) NOT NULL,
  `amount` decimal(20,6) NOT NULL,
  `price_per_unit` decimal(20,2) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','completed','cancelled') DEFAULT 'active',
  `views_count` int(11) DEFAULT 0,
  `offer_count` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_ads`
--

INSERT INTO `user_ads` (`id`, `user_id`, `type`, `currency`, `amount`, `price_per_unit`, `description`, `status`, `views_count`, `offer_count`, `created_at`, `updated_at`) VALUES
(28, 1, 'buy', 'USDT', 1000.000000, 165000.00, '', 'completed', 0, 6, '2026-05-17 10:32:49', '2026-05-17 13:41:17'),
(29, 17, 'sell', 'EUR', 200.000000, 2100000.00, '', 'completed', 0, 27, '2026-05-17 10:40:24', '2026-05-17 13:34:25'),
(30, 1, 'buy', 'USDT', 10000.000000, 165000.00, '', 'completed', 0, 1, '2026-05-17 13:49:23', '2026-05-17 15:04:17'),
(31, 17, 'buy', 'USDT', 1000.000000, 164000.00, '', 'completed', 0, 2, '2026-05-17 15:12:29', '2026-05-17 15:15:08'),
(32, 1, 'buy', 'USDT', 1000.000000, 1640000.00, '', 'completed', 0, 2, '2026-05-17 20:54:10', '2026-05-17 21:30:35'),
(37, 37, 'sell', 'EUR', 1372.000000, 206000.00, '', 'completed', 0, 1, '2026-05-21 08:22:06', '2026-05-22 17:36:38'),
(39, 37, 'sell', 'EUR', 1000.000000, 204000.00, 'بدون توضیحات | No notes', 'completed', 0, 0, '2026-05-22 08:34:45', '2026-05-22 20:40:44'),
(45, 37, 'sell', 'EUR', 924.000000, 199000.00, 'بدون توضیحات | No notes', 'completed', 0, 1, '2026-05-27 11:38:34', '2026-05-27 13:58:14'),
(50, 37, 'sell', 'EUR', 930.000000, 197000.00, 'بدون توضیحات | No notes', 'completed', 0, 0, '2026-06-02 17:04:03', '2026-06-03 09:44:07'),
(58, 37, 'sell', 'EUR', 530.000000, 198000.00, 'بدون توضیحات | No notes', 'completed', 0, 0, '2026-06-06 14:38:42', '2026-06-08 07:09:02'),
(66, 1, 'buy', 'EUR', 290.000000, 197000.00, '', 'completed', 0, 5, '2026-06-13 06:26:27', '2026-06-13 07:39:03'),
(67, 1, 'buy', 'EUR', 290.000000, 197000.00, '', 'completed', 0, 2, '2026-06-13 07:40:36', '2026-06-13 07:54:15'),
(69, 1, 'buy', 'EUR', 290.000000, 197000.00, '', 'completed', 0, 3, '2026-06-13 08:34:42', '2026-06-13 08:37:33'),
(72, 17, 'buy', 'EUR', 200.000000, 193000.00, '', 'completed', 0, 2, '2026-06-14 16:31:25', '2026-06-14 16:49:19'),
(73, 1, 'buy', 'EUR', 200.000000, 1900000.00, '', 'completed', 0, 10, '2026-06-14 16:49:44', '2026-06-14 17:36:19'),
(82, 14, 'sell', 'USDT', 350.000000, 157000.00, 'ERC20-اتریوم', 'completed', 0, 0, '2026-06-19 07:29:41', '2026-06-20 01:06:23'),
(92, 1, 'buy', 'USDT', 1000.000000, 165000.00, 'بدون توضیحات | No notes', 'active', 0, 0, '2026-06-30 11:58:54', '2026-06-30 11:58:54');

-- --------------------------------------------------------

--
-- Table structure for table `user_balance_history`
--

CREATE TABLE `user_balance_history` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `balance_usd` decimal(15,2) DEFAULT 0.00,
  `balance_eur` decimal(15,2) DEFAULT 0.00,
  `balance_usdt` decimal(15,6) DEFAULT 0.000000,
  `balance_irr` decimal(30,0) DEFAULT 0,
  `timestamp` datetime DEFAULT current_timestamp(),
  `change_type` enum('transaction','deposit','withdrawal','exchange','manual_adjustment','fee') DEFAULT 'transaction',
  `transaction_id` varchar(50) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_balance_history`
--

INSERT INTO `user_balance_history` (`id`, `user_id`, `balance_usd`, `balance_eur`, `balance_usdt`, `balance_irr`, `timestamp`, `change_type`, `transaction_id`, `description`) VALUES
(1, 1, 99930.00, 14.00, 0.000000, 0, '2026-01-01 12:02:36', 'transaction', NULL, NULL),
(2, 1, 99930.00, 137.00, 0.000000, 0, '2026-01-01 12:02:51', 'transaction', NULL, NULL),
(3, 1, 99930.00, 137.00, 1.000000, 0, '2026-01-01 12:02:59', 'transaction', NULL, NULL),
(4, 1, 99930.00, 137.00, 1.000000, 1, '2026-01-01 12:03:07', 'transaction', NULL, NULL),
(5, 1, 99430.00, 137.00, 1.000000, 1, '2026-01-01 12:03:14', 'transaction', NULL, NULL),
(6, 1, 99930.00, 137.00, 1.000000, 1, '2026-01-01 12:04:09', 'transaction', NULL, NULL),
(7, 1, 99807.00, 137.00, 1.000000, 1, '2026-01-01 12:04:46', 'transaction', NULL, NULL),
(9, 13, 0.00, 0.00, 0.000000, 3120000, '2026-01-01 12:23:17', 'transaction', NULL, NULL),
(10, 13, 0.00, 0.00, 0.000000, 6240000, '2026-01-01 12:36:59', 'transaction', NULL, NULL),
(12, 1, 99808.00, 137.00, 1.000000, 1, '2026-01-05 04:03:11', 'transaction', NULL, NULL),
(14, 1, 99810.00, 137.00, 1.000000, 1, '2026-01-05 20:02:37', 'transaction', NULL, NULL),
(22, 1, 99811.00, 137.00, 1.000000, 1, '2026-01-05 20:58:20', 'transaction', NULL, NULL),
(24, 1, 99812.00, 137.00, 1.000000, 1, '2026-01-05 21:05:42', 'transaction', NULL, NULL),
(26, 1, 99814.00, 137.00, 1.000000, 1, '2026-01-05 21:34:12', 'transaction', NULL, NULL),
(28, 1, 99815.00, 137.00, 1.000000, 1, '2026-01-05 21:37:54', 'transaction', NULL, NULL),
(30, 1, 99816.00, 137.00, 1.000000, 1, '2026-01-05 21:40:02', 'transaction', NULL, NULL),
(31, 1, 99814.00, 137.00, 1.000000, 1, '2026-01-05 21:40:57', 'transaction', NULL, NULL),
(34, 1, 99816.00, 137.00, 1.000000, 1, '2026-01-05 21:48:14', 'transaction', NULL, NULL),
(36, 1, 99818.00, 137.00, 1.000000, 1, '2026-01-05 21:48:43', 'transaction', NULL, NULL),
(38, 1, 99820.00, 137.00, 1.000000, 1, '2026-01-05 21:49:25', 'transaction', NULL, NULL),
(39, 1, 99800.00, 137.00, 1.000000, 1, '2026-01-05 21:50:16', 'transaction', NULL, NULL),
(41, 1, 99798.00, 137.00, 1.000000, 1, '2026-01-05 21:51:11', 'transaction', NULL, NULL),
(44, 1, 99800.00, 137.00, 1.000000, 1, '2026-01-05 22:21:43', 'transaction', NULL, NULL),
(50, 1, 99788.00, 137.00, 1.000000, 1, '2026-01-12 21:07:51', 'transaction', NULL, NULL),
(58, 1, 99789.00, 137.00, 1.000000, 1, '2026-01-12 22:30:20', 'transaction', NULL, NULL),
(59, 1, 99788.00, 137.00, 1.000000, 1, '2026-01-12 22:30:34', 'transaction', NULL, NULL),
(62, 1, 99663.00, 137.00, 1.000000, 1, '2026-01-12 23:22:06', 'transaction', NULL, NULL),
(63, 13, 0.00, 0.00, 0.000000, 3120000, '2026-01-13 00:21:44', 'transaction', NULL, NULL),
(65, 1, 99663.00, 137.00, 25.000000, 1, '2026-01-15 16:58:46', 'transaction', NULL, NULL),
(66, 1, 100663.00, 137.00, 25.000000, 1, '2026-01-15 17:00:08', 'transaction', NULL, NULL),
(67, 1, 100663.00, 137.00, 1025.000000, 1, '2026-01-15 17:01:08', 'transaction', NULL, NULL),
(68, 1, 100641.00, 137.00, 1025.000000, 1, '2026-01-15 17:04:17', 'transaction', NULL, NULL),
(70, 1, 100518.00, 137.00, 1025.000000, 1, '2026-01-16 20:24:36', 'transaction', NULL, NULL),
(71, 18, 123.00, 0.00, 0.000000, 0, '2026-01-16 20:24:36', 'transaction', NULL, NULL),
(72, 1, 100506.00, 137.00, 1025.000000, 1, '2026-01-22 14:44:34', 'transaction', NULL, NULL),
(73, 17, 12.00, 0.00, 0.000000, 0, '2026-01-22 14:44:34', 'transaction', NULL, NULL),
(74, 1, 100473.00, 137.00, 1025.000000, 1, '2026-01-22 14:45:28', 'transaction', NULL, NULL),
(75, 17, 45.00, 0.00, 0.000000, 0, '2026-01-22 14:45:28', 'transaction', NULL, NULL),
(76, 15, 0.00, 0.00, 0.000000, 3287000, '2026-01-23 09:08:12', 'transaction', NULL, NULL),
(77, 17, 45.01, 0.00, 0.000000, 0, '2026-01-23 09:08:49', 'transaction', NULL, NULL),
(79, 20, 1.00, 0.00, 0.000000, 0, '2026-01-23 10:40:22', 'transaction', NULL, NULL),
(80, 17, 46.01, 0.00, 0.000000, 0, '2026-01-23 10:40:54', 'transaction', NULL, NULL),
(81, 20, 2.00, 0.00, 0.000000, 0, '2026-01-23 10:41:17', 'transaction', NULL, NULL),
(82, 17, 44.01, 0.00, 0.000000, 0, '2026-01-23 10:43:43', 'transaction', NULL, NULL),
(83, 17, 34.01, 0.00, 0.000000, 0, '2026-01-23 12:16:40', 'transaction', NULL, NULL),
(84, 1, 100472.00, 137.00, 1025.000000, 1, '2026-01-31 12:25:31', 'transaction', NULL, NULL),
(85, 21, 1.00, 0.00, 0.000000, 0, '2026-01-31 12:25:31', 'transaction', NULL, NULL),
(86, 12, 0.00, 995.00, 0.000000, 0, '2026-02-02 20:30:08', 'transaction', NULL, NULL),
(87, 1, 100473.00, 137.00, 1025.000000, 1, '2026-02-02 20:30:24', 'transaction', NULL, NULL),
(88, 12, 0.00, 0.00, 0.000000, 0, '2026-02-03 09:36:47', 'transaction', NULL, NULL),
(89, 1, 100470.00, 137.00, 1025.000000, 1, '2026-02-03 14:10:32', 'transaction', NULL, NULL),
(90, 26, 3.00, 0.00, 0.000000, 0, '2026-02-03 14:10:32', 'transaction', NULL, NULL),
(91, 1, 100467.00, 137.00, 1025.000000, 1, '2026-02-03 14:11:56', 'transaction', NULL, NULL),
(92, 27, 3.00, 0.00, 0.000000, 0, '2026-02-03 14:11:56', 'transaction', NULL, NULL),
(93, 1, 100466.00, 137.00, 1025.000000, 1, '2026-02-04 12:59:04', 'transaction', NULL, NULL),
(94, 17, 35.01, 0.00, 0.000000, 0, '2026-02-04 12:59:04', 'transaction', NULL, NULL),
(95, 1, 100465.00, 137.00, 1025.000000, 1, '2026-02-04 12:59:33', 'transaction', NULL, NULL),
(96, 17, 36.01, 0.00, 0.000000, 0, '2026-02-04 12:59:33', 'transaction', NULL, NULL),
(97, 1, 100464.00, 137.00, 1025.000000, 1, '2026-02-04 13:00:16', 'transaction', NULL, NULL),
(98, 17, 37.01, 0.00, 0.000000, 0, '2026-02-04 13:00:16', 'transaction', NULL, NULL),
(99, 1, 100463.00, 137.00, 1025.000000, 1, '2026-02-04 13:04:26', 'transaction', NULL, NULL),
(100, 17, 38.01, 0.00, 0.000000, 0, '2026-02-04 13:04:26', 'transaction', NULL, NULL),
(101, 30, 0.00, 0.00, 0.000000, 714420000, '2026-02-10 21:23:36', 'transaction', NULL, NULL),
(102, 30, 0.00, 0.00, 0.000000, 0, '2026-02-11 00:53:27', 'transaction', NULL, NULL),
(103, 1, 223463.00, 137.00, 1025.000000, 1, '2026-02-19 21:49:38', 'transaction', NULL, NULL),
(104, 17, 37.01, 0.00, 0.000000, 0, '2026-02-20 09:13:34', 'transaction', NULL, NULL),
(105, 1, 223464.00, 137.00, 1025.000000, 1, '2026-02-20 09:13:34', 'transaction', NULL, NULL),
(106, 1, 223465.00, 137.00, 1025.000000, 1, '2026-02-20 20:31:23', 'transaction', NULL, NULL),
(107, 1, 223466.00, 137.00, 1025.000000, 1, '2026-02-20 20:31:27', 'transaction', NULL, NULL),
(108, 1, 223467.00, 137.00, 1025.000000, 1, '2026-02-20 20:31:53', 'transaction', NULL, NULL),
(109, 17, 37.01, 21.00, 0.000000, 0, '2026-02-21 10:23:03', 'transaction', NULL, NULL),
(110, 17, 37.01, 42.00, 0.000000, 0, '2026-02-21 10:23:56', 'transaction', NULL, NULL),
(111, 1, 223654.00, 137.00, 1025.000000, 1, '2026-02-21 10:24:29', 'transaction', NULL, NULL),
(112, 1, 223467.00, 137.00, 1025.000000, 1, '2026-02-21 10:24:37', 'transaction', NULL, NULL),
(113, 1, 223479.00, 137.00, 1025.000000, 1, '2026-02-21 10:33:05', 'transaction', NULL, NULL),
(114, 1, 223480.00, 137.00, 1025.000000, 1, '2026-02-21 10:34:59', 'transaction', NULL, NULL),
(115, 1, 223481.00, 137.00, 1025.000000, 1, '2026-02-21 10:35:54', 'transaction', NULL, NULL),
(116, 17, 58.01, 42.00, 0.000000, 0, '2026-02-21 10:43:19', 'transaction', NULL, NULL),
(117, 17, 83.01, 42.00, 0.000000, 0, '2026-02-21 10:44:04', 'transaction', NULL, NULL),
(118, 17, 33.01, 42.00, 0.000000, 0, '2026-02-21 10:44:27', 'transaction', NULL, NULL),
(119, 1, 223502.00, 137.00, 1025.000000, 1, '2026-02-21 10:46:15', 'transaction', NULL, NULL),
(120, 17, 34.01, 42.00, 0.000000, 0, '2026-02-21 10:48:16', 'transaction', NULL, NULL),
(121, 17, 36.01, 42.00, 0.000000, 0, '2026-02-21 10:57:12', 'transaction', NULL, NULL),
(122, 17, 36.01, 18.00, 0.000000, 0, '2026-02-21 10:58:38', 'transaction', NULL, NULL),
(123, 1, 223502.00, 161.00, 1025.000000, 1, '2026-02-21 10:58:38', 'transaction', NULL, NULL),
(124, 1, 223523.00, 161.00, 1025.000000, 1, '2026-02-21 11:00:06', 'transaction', NULL, NULL),
(125, 1, 223501.00, 161.00, 1025.000000, 1, '2026-02-25 21:02:10', 'transaction', NULL, NULL),
(126, 1, 223501.00, 161.00, 1025.000000, 50555001, '2026-03-13 14:29:29', 'transaction', NULL, NULL),
(127, 14, 0.00, 0.00, 600.000000, 0, '2026-03-25 10:45:16', 'transaction', NULL, NULL),
(128, 14, 0.00, 0.00, 0.000000, 0, '2026-03-26 14:45:50', 'transaction', NULL, NULL),
(129, 14, 0.00, 0.00, 1160.000000, 0, '2026-04-05 11:31:41', 'transaction', NULL, NULL),
(130, 14, 0.00, 0.00, 0.000000, 0, '2026-04-06 17:42:47', 'transaction', NULL, NULL),
(131, 1, 223501.00, -1.00, 1025.000000, 50555001, '2026-05-09 09:03:37', 'transaction', NULL, NULL),
(132, 1, 223501.00, -163.00, 1025.000000, 50555001, '2026-05-09 09:04:45', 'transaction', NULL, NULL),
(133, 12, 0.00, -2.50, 0.000000, 0, '2026-05-12 22:32:28', 'transaction', NULL, NULL),
(134, 12, 0.00, -10.00, 0.000000, 0, '2026-05-13 13:30:12', 'transaction', NULL, NULL),
(135, 17, 6.01, 18.00, 0.000000, 0, '2026-05-16 00:42:40', 'transaction', NULL, NULL),
(136, 17, 36.01, 18.00, 0.000000, 0, '2026-05-16 00:43:00', 'transaction', NULL, NULL),
(137, 12, 0.00, 0.00, 0.000000, 0, '2026-05-18 22:49:02', 'transaction', NULL, NULL),
(138, 12, 0.00, -8.00, 0.000000, 0, '2026-05-21 19:17:20', 'transaction', NULL, NULL),
(139, 1, 223491.00, -163.00, 1025.000000, 50555001, '2026-05-23 02:32:19', 'transaction', NULL, NULL),
(140, 1, 223491.00, -163.00, 1025.000000, 45500000, '2026-05-23 02:40:26', 'transaction', NULL, NULL),
(141, 1, 223368.00, -163.00, 1025.000000, 45500000, '2026-05-23 02:49:48', 'transaction', NULL, NULL),
(142, 1, 223368.00, -163.00, 902.000000, 45500000, '2026-05-23 03:18:49', 'transaction', NULL, NULL),
(143, 1, 223368.00, -163.00, 802.000000, 45500000, '2026-05-23 03:57:45', 'transaction', NULL, NULL),
(144, 1, 223368.00, -163.00, 802.000000, 0, '2026-05-23 04:38:36', 'transaction', NULL, NULL),
(145, 1, 223368.00, -163.00, 602.000000, 0, '2026-05-23 07:34:46', 'transaction', NULL, NULL),
(146, 1, 223368.00, -163.00, 552.000000, 0, '2026-05-23 07:35:56', 'transaction', NULL, NULL),
(147, 1, 223345.00, -163.00, 552.000000, 0, '2026-05-23 08:24:12', 'transaction', NULL, NULL),
(148, 1, 223325.00, -163.00, 552.000000, 0, '2026-05-23 09:24:07', 'transaction', NULL, NULL),
(149, 1, 223303.00, -163.00, 552.000000, 0, '2026-05-23 09:51:54', 'transaction', NULL, NULL),
(150, 1, 223103.00, -163.00, 552.000000, 0, '2026-05-23 10:00:58', 'transaction', NULL, NULL),
(151, 12, 0.00, -13.00, 0.000000, 0, '2026-05-25 08:05:23', 'transaction', NULL, NULL),
(152, 12, 0.00, 2.00, 0.000000, 0, '2026-05-25 08:06:31', 'transaction', NULL, NULL),
(153, 1, 223103.00, -163.00, 502.000000, 0, '2026-05-25 09:39:19', 'transaction', NULL, NULL),
(154, 1, 223103.00, -163.00, 492.000000, 0, '2026-05-25 10:07:50', 'transaction', NULL, NULL),
(155, 1, 223103.00, -163.00, 442.000000, 0, '2026-05-25 11:13:01', 'transaction', NULL, NULL),
(156, 17, 36.01, 18.00, 0.000000, 50000000, '2026-05-25 12:49:56', 'transaction', NULL, NULL),
(157, 17, 36.01, 18.00, 0.000000, 10000000, '2026-05-25 12:51:13', 'transaction', NULL, NULL),
(160, 17, 36.01, 18.00, 0.000000, 9000000, '2026-05-25 13:11:54', 'transaction', NULL, NULL),
(161, 17, 36.01, 18.00, 0.000000, 8950000, '2026-05-25 13:32:29', 'transaction', NULL, NULL),
(162, 1, 223081.00, -163.00, 442.000000, 0, '2026-05-25 14:41:14', 'transaction', NULL, NULL),
(163, 1, 223060.00, -163.00, 442.000000, 0, '2026-05-25 14:43:10', 'transaction', NULL, NULL),
(164, 1, 500223060.00, -163.00, 442.000000, 0, '2026-05-25 14:43:58', 'transaction', NULL, NULL),
(167, 1, 425.00, -163.00, 422.000000, 10000000000, '2026-05-25 14:45:47', 'transaction', NULL, NULL),
(168, 1, 1000143060.00, -163.00, 442.000000, 10000000000, '2026-05-25 14:57:54', 'transaction', NULL, NULL),
(169, 1, 1000143060.00, -163.00, 442.000000, 9999500000, '2026-05-25 15:57:19', 'transaction', NULL, NULL),
(170, 37, 0.00, 0.00, 0.000000, 97500000, '2026-06-02 13:54:54', 'transaction', NULL, NULL),
(171, 37, 0.00, 0.00, 0.000000, 32500000, '2026-06-02 19:06:41', 'transaction', NULL, NULL),
(172, 37, 0.00, 0.00, 0.000000, 0, '2026-06-02 22:11:38', 'transaction', NULL, NULL),
(173, 37, 0.00, 0.00, 0.000000, 196000000, '2026-06-05 07:46:43', 'transaction', NULL, NULL),
(174, 37, 0.00, 0.00, 0.000000, 115222500, '2026-06-05 18:02:34', 'transaction', NULL, NULL),
(175, 37, 0.00, 0.00, 0.000000, 0, '2026-06-05 18:09:17', 'transaction', NULL, NULL),
(176, 12, 0.00, -3.00, 0.000000, 0, '2026-06-05 22:00:35', 'transaction', NULL, NULL),
(177, 1, 1000143037.00, -163.00, 442.000000, 9999500000, '2026-06-11 00:07:38', 'transaction', NULL, NULL),
(178, 17, 59.01, 18.00, 0.000000, 8950000, '2026-06-11 00:07:38', 'transaction', NULL, NULL),
(179, 1, 1000143036.00, -163.00, 442.000000, 9999500000, '2026-06-11 00:09:23', 'transaction', NULL, NULL),
(180, 17, 60.01, 18.00, 0.000000, 8950000, '2026-06-11 00:09:23', 'transaction', NULL, NULL),
(181, 1, 1000143035.00, -163.00, 442.000000, 9999500000, '2026-06-11 00:10:21', 'transaction', NULL, NULL),
(182, 17, 61.01, 18.00, 0.000000, 8950000, '2026-06-11 00:10:21', 'transaction', NULL, NULL),
(183, 1, 1000143034.00, -163.00, 442.000000, 9999500000, '2026-06-11 23:10:21', 'transaction', NULL, NULL),
(184, 17, 62.01, 18.00, 0.000000, 8950000, '2026-06-11 23:10:21', 'transaction', NULL, NULL),
(185, 37, 0.00, 0.00, 0.000000, 0, '2026-06-12 09:27:59', 'transaction', NULL, NULL),
(186, 37, 0.00, 0.00, 0.000000, 58200000, '2026-06-13 11:50:31', 'transaction', NULL, NULL),
(187, 37, 0.00, 0.00, 0.000000, 0, '2026-06-14 12:43:35', 'transaction', NULL, NULL),
(188, 37, 0.00, 0.00, 0.000000, 344000000, '2026-06-18 19:55:38', 'transaction', NULL, NULL),
(189, 37, 0.00, 0.00, 0.000000, 298000000, '2026-06-18 23:47:23', 'transaction', NULL, NULL),
(190, 37, 0.00, 0.00, 0.000000, 148000000, '2026-06-18 23:49:09', 'transaction', NULL, NULL),
(191, 37, 0.00, 0.00, 0.000000, 92800000, '2026-06-18 23:49:45', 'transaction', NULL, NULL),
(192, 37, 0.00, 0.00, 0.000000, 0, '2026-06-18 23:59:14', 'transaction', NULL, NULL),
(193, 14, 0.00, 0.00, 0.000000, 54950000, '2026-06-19 11:18:17', 'transaction', NULL, NULL),
(194, 37, 0.00, -3.00, 0.000000, 0, '2026-06-19 14:32:29', 'transaction', NULL, NULL),
(195, 37, 0.00, -6.00, 0.000000, 0, '2026-06-19 14:33:29', 'transaction', NULL, NULL),
(196, 37, 0.00, -9.00, 0.000000, 0, '2026-06-19 14:33:29', 'transaction', NULL, NULL),
(197, 37, 0.00, -3.00, 0.000000, 0, '2026-06-19 14:34:18', 'transaction', NULL, NULL),
(198, 14, 0.00, 0.00, 0.000000, 0, '2026-06-19 15:55:16', 'transaction', NULL, NULL),
(199, 18, 0.00, 0.00, 0.000000, 0, '2026-06-21 11:22:37', 'transaction', NULL, NULL),
(200, 17, 112.01, 18.00, 0.000000, 8950000, '2026-06-28 15:48:28', 'transaction', NULL, NULL),
(201, 17, 162.01, 18.00, 0.000000, 8950000, '2026-06-28 16:18:43', 'transaction', NULL, NULL),
(202, 1, 1000142984.00, -163.00, 442.000000, 9999500000, '2026-06-28 16:20:16', 'transaction', NULL, NULL),
(203, 1, 1000142934.00, -163.00, 442.000000, 9999500000, '2026-06-28 16:20:22', 'transaction', NULL, NULL),
(204, 1, 1000142884.00, -163.00, 442.000000, 9999500000, '2026-06-28 16:21:23', 'transaction', NULL, NULL),
(205, 1, 1000142834.00, -163.00, 442.000000, 9999500000, '2026-06-28 16:21:28', 'transaction', NULL, NULL),
(206, 17, 112.01, 18.00, 0.000000, 8950000, '2026-06-28 16:37:41', 'transaction', NULL, NULL),
(207, 17, 567.01, 18.00, 0.000000, 8950000, '2026-06-28 17:56:19', 'transaction', NULL, NULL),
(208, 1, 1000142734.00, -163.00, 442.000000, 9999500000, '2026-06-29 15:22:05', 'transaction', NULL, NULL),
(209, 1, 1000142784.00, -163.00, 442.000000, 9999500000, '2026-06-29 21:48:46', 'transaction', NULL, NULL),
(210, 1, 1000142984.00, -163.00, 442.000000, 9999500000, '2026-06-29 21:51:10', 'transaction', NULL, NULL),
(211, 1, 1000143034.00, -163.00, 442.000000, 9999500000, '2026-06-29 22:02:05', 'transaction', NULL, NULL),
(212, 1, 1000143034.00, -163.00, 542.000000, 9999500000, '2026-06-29 22:35:11', 'transaction', NULL, NULL),
(213, 17, 567.01, 18.00, 20.000000, 8950000, '2026-06-29 22:50:09', 'transaction', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user_contacts`
--

CREATE TABLE `user_contacts` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `contact_id` int(11) DEFAULT NULL,
  `last_transaction_date` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `user_contacts`
--

INSERT INTO `user_contacts` (`id`, `user_id`, `contact_id`, `last_transaction_date`) VALUES
(75, 1, 18, '2026-01-16 19:24:36'),
(76, 18, 1, '2026-01-16 19:24:36'),
(77, 1, 17, '2026-01-22 13:44:34'),
(78, 17, 1, '2026-01-22 13:44:34'),
(81, 1, 21, '2026-01-31 11:25:31'),
(82, 21, 1, '2026-01-31 11:25:31'),
(83, 1, 26, '2026-02-03 13:10:32'),
(84, 26, 1, '2026-02-03 13:10:32'),
(85, 1, 27, '2026-02-03 13:11:56'),
(86, 27, 1, '2026-02-03 13:11:56');

-- --------------------------------------------------------

--
-- Table structure for table `user_discounts`
--

CREATE TABLE `user_discounts` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `discount_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `description` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_discounts`
--

INSERT INTO `user_discounts` (`id`, `user_id`, `discount_percent`, `description`, `created_by`, `expires_at`, `created_at`, `updated_at`) VALUES
(9, 12, 50.00, 'مشتری وفادار', 1, '2026-06-14 22:44:00', '2026-05-09 07:26:26', '2026-06-11 20:44:34'),
(10, 37, 40.00, 'مشتریان وفادار', 1, NULL, '2026-05-09 07:38:07', '2026-05-09 07:38:07'),
(11, 1, 50.00, 'مشتری وفادار', 1, '2026-05-13 06:30:00', '2026-05-13 04:22:04', '2026-05-13 04:22:04'),
(12, 14, 50.00, 'مشتری وفادار  . معامله فقط از طریق اپلیکیشن Ava pay', 1, '2026-05-17 19:44:00', '2026-05-14 17:44:23', '2026-05-14 17:44:23');

-- --------------------------------------------------------

--
-- Table structure for table `user_notifications`
--

CREATE TABLE `user_notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `type` varchar(50) NOT NULL,
  `related_id` int(11) DEFAULT NULL COMMENT 'آیدی مرتبط (مثلاً discount_id)',
  `is_read` tinyint(1) DEFAULT 0,
  `sent_to_telegram` tinyint(1) DEFAULT 0,
  `telegram_response` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_notifications`
--

INSERT INTO `user_notifications` (`id`, `user_id`, `title`, `message`, `type`, `related_id`, `is_read`, `sent_to_telegram`, `telegram_response`, `created_at`) VALUES
(271, 17, '💰 پیشنهاد جدید برای EUR', 'کاربر سارا موحد به مبلغ 52,900 تومان پیشنهاد داد', 'offer_received', 265, 0, 0, NULL, '2026-06-28 10:15:30'),
(273, 1, 'پیشنهاد جدید دریافت شد', 'کاربر Alireza  Ahmadian برای USDT به مبلغ 625 تومان پیشنهاد داد', 'offer_received', 267, 0, 0, NULL, '2026-06-28 10:20:24'),
(274, 17, 'پیشنهاد شما رد شد', 'پیشنهاد شما برای USDT رد شد. دلیل: Hg', 'offer_rejected', 267, 0, 0, NULL, '2026-06-28 10:21:08'),
(275, 1, 'پیشنهاد شما رد شد', 'پیشنهاد شما برای EUR رد شد. دلیل: نتاا', 'offer_rejected', 266, 0, 0, NULL, '2026-06-28 10:22:08'),
(276, 1, 'پیشنهاد جدید دریافت شد 🎯', 'کاربر Alireza  Ahmadian برای USDT به مبلغ 441 تومان پیشنهاد داد', 'offer_received', 268, 0, 0, NULL, '2026-06-28 10:41:30'),
(277, 1, 'پیشنهاد جدید دریافت شد 🎯', 'کاربر Alireza  Ahmadian برای USDT به مبلغ 625 تومان پیشنهاد داد', 'offer_received', 269, 0, 0, NULL, '2026-06-28 10:43:35'),
(278, 1, 'پیشنهاد جدید دریافت شد 🎯', 'کاربر Alireza  Ahmadian برای USDT به مبلغ 484 تومان پیشنهاد داد', 'offer_received', 270, 0, 0, NULL, '2026-06-28 10:50:20'),
(279, 1, 'پیشنهاد جدید دریافت شد 🎯', 'کاربر Alireza  Ahmadian برای USDT به مبلغ 484 تومان پیشنهاد داد', 'offer_received', 271, 0, 0, NULL, '2026-06-28 10:51:12'),
(280, 1, 'New Request: Bottom 2', 'User سارا موحد submitted a form.', 'quick_action', NULL, 0, 0, NULL, '2026-06-28 11:22:51'),
(281, 17, '📢 Announcement', 'Test', 'admin_toast', NULL, 0, 0, NULL, '2026-06-28 11:23:57'),
(282, 1, '📬 درخواست جدید: Bottom 2', 'کاربر سارا موحد یک فرم ارسال کرد.', 'quick_action', NULL, 0, 0, NULL, '2026-06-28 11:43:25'),
(283, 1, '💰 درخواست Top-up جدید', 'کاربر Alireza  Ahmadian درخواست شارژ 50 USD ارسال کرد.', 'topup_request', 1, 0, 0, NULL, '2026-06-28 13:33:36'),
(284, 1, '💰 درخواست Top-up جدید', 'کاربر Alireza  Ahmadian درخواست شارژ 50 USD ارسال کرد.', 'topup_request', 2, 0, 0, NULL, '2026-06-28 13:38:32'),
(285, 17, '📢 Announcement', 'Test', 'admin_toast', NULL, 0, 0, NULL, '2026-06-28 13:39:11'),
(286, 17, '📢 Announcement', 'Test', 'admin_toast', NULL, 0, 0, NULL, '2026-06-28 13:39:14'),
(287, 17, '✅ درخواست Top-up تأیید شد', 'درخواست شارژ 50.00 USD شما تأیید شد. اطلاعات حساب برای پرداخت ارسال شد.', 'topup_approved', 2, 0, 0, NULL, '2026-06-28 13:47:09'),
(288, 17, '✅ درخواست Top-up تأیید شد', 'درخواست شارژ 50.00 USD شما تأیید شد. اطلاعات حساب برای پرداخت ارسال شد.', 'topup_approved', 2, 0, 0, NULL, '2026-06-28 13:47:11'),
(289, 1, '🧾 فیش واریز دریافت شد', 'کاربر فیش پرداخت Top-up #2 را ارسال کرد.', 'topup_receipt', 2, 0, 0, NULL, '2026-06-28 13:48:03'),
(290, 17, '💰 شارژ حساب موفق', 'مبلغ 50 USD با موفقیت به حساب شما اضافه شد.', 'topup_completed', 2, 0, 0, NULL, '2026-06-28 13:48:28'),
(291, 1, '💰 درخواست Top-up جدید', 'کاربر Alireza  Ahmadian درخواست شارژ 50 USD ارسال کرد.', 'topup_request', 3, 0, 0, NULL, '2026-06-28 13:53:28'),
(292, 17, '✅ درخواست Top-up تأیید شد', 'درخواست شارژ 50.00 USD شما تأیید شد. اطلاعات حساب برای پرداخت ارسال شد.', 'topup_approved', 3, 0, 0, NULL, '2026-06-28 13:54:04'),
(293, 17, '❌ درخواست Top-up رد شد', 'درخواست شارژ 50.00 USD شما رد شد. دلیل: ;;', 'topup_rejected', 1, 0, 0, NULL, '2026-06-28 13:55:05'),
(294, 1, '💰 درخواست Top-up جدید', 'کاربر Alireza  Ahmadian درخواست شارژ 10 USD ارسال کرد.', 'topup_request', 4, 0, 0, NULL, '2026-06-28 13:55:12'),
(295, 17, '✅ درخواست Top-up تأیید شد', 'درخواست شارژ 10.00 USD شما تأیید شد. اطلاعات حساب برای پرداخت ارسال شد.', 'topup_approved', 4, 0, 0, NULL, '2026-06-28 13:55:32'),
(296, 1, '💰 درخواست Top-up جدید', 'کاربر Alireza  Ahmadian درخواست شارژ 50 USD ارسال کرد.', 'topup_request', 5, 0, 0, NULL, '2026-06-28 14:17:46'),
(297, 17, '✅ درخواست Top-up تأیید شد', 'درخواست شارژ 50.00 USD شما تأیید شد. اطلاعات حساب برای پرداخت ارسال شد.', 'topup_approved', 5, 0, 0, NULL, '2026-06-28 14:18:11'),
(298, 1, '🧾 فیش واریز دریافت شد', 'کاربر فیش پرداخت Top-up #5 را ارسال کرد.', 'topup_receipt', 5, 0, 0, NULL, '2026-06-28 14:18:25'),
(299, 17, '💰 شارژ حساب موفق', 'مبلغ 50 USD با موفقیت به حساب شما اضافه شد.', 'topup_completed', 5, 0, 0, NULL, '2026-06-28 14:18:43'),
(300, 1, '📬 درخواست جدید: test', 'کاربر سارا موحد یک فرم ارسال کرد. مبلغ 50 USD از موجودی کسر شد.', 'quick_action', NULL, 0, 0, NULL, '2026-06-28 14:20:16'),
(301, 1, '📬 درخواست جدید: test', 'کاربر سارا موحد یک فرم ارسال کرد. مبلغ 50 USD از موجودی کسر شد.', 'quick_action', NULL, 0, 0, NULL, '2026-06-28 14:20:22'),
(302, 1, '📬 درخواست جدید: test', 'کاربر سارا موحد یک فرم ارسال کرد. مبلغ 50 USD از موجودی کسر شد.', 'quick_action', NULL, 0, 0, NULL, '2026-06-28 14:21:23'),
(303, 1, '📬 درخواست جدید: test', 'کاربر سارا موحد یک فرم ارسال کرد. مبلغ 50 USD از موجودی کسر شد.', 'quick_action', NULL, 0, 0, NULL, '2026-06-28 14:21:28'),
(304, 17, '📢 Announcement', 'تست', 'admin_toast', NULL, 0, 0, NULL, '2026-06-28 14:28:10'),
(305, 17, '📢 Announcement', 'Test', 'admin_toast', NULL, 0, 0, NULL, '2026-06-28 14:30:36'),
(306, 17, '📢 Announcement', 'الو', 'admin_toast', NULL, 0, 0, NULL, '2026-06-28 14:31:06'),
(307, 17, '📢 Announcement', 'میم', 'admin_toast', NULL, 0, 0, NULL, '2026-06-28 14:31:28'),
(308, 17, '📢 Announcement', 'هههه', 'admin_toast', NULL, 0, 0, NULL, '2026-06-28 14:32:44'),
(309, 1, '📢 Announcement', 'تست', 'admin_toast', NULL, 0, 0, NULL, '2026-06-28 14:34:08'),
(310, 1, '📢 Announcement', 'نننسنسش', 'admin_toast', NULL, 0, 0, NULL, '2026-06-28 14:35:16'),
(311, 1, '📢 admin', 'ssss', 'admin_toast', NULL, 0, 0, NULL, '2026-06-28 14:36:34'),
(312, 1, '📬 درخواست جدید: test', 'کاربر Alireza  Ahmadian یک فرم ارسال کرد. مبلغ 50 USD از موجودی کسر شد.', 'quick_action', NULL, 0, 0, NULL, '2026-06-28 14:37:41'),
(313, 1, '💰 درخواست Top-up جدید', 'کاربر Alireza  Ahmadian درخواست شارژ 20 USDT ارسال کرد.', 'topup_request', 6, 0, 0, NULL, '2026-06-28 14:38:45'),
(314, 17, '✅ درخواست Top-up تأیید شد', 'درخواست شارژ 20.00 USDT شما تأیید شد. اطلاعات حساب برای پرداخت ارسال شد.', 'topup_approved', 6, 0, 0, NULL, '2026-06-28 14:39:12'),
(315, 1, '💰 درخواست Top-up جدید', 'کاربر سارا موحد درخواست شارژ 50 USD ارسال کرد.', 'topup_request', 7, 0, 0, NULL, '2026-06-28 15:41:53'),
(316, 17, '📄 فیش جدید برای شما صادر شد', 'مبلغ 455 USD - ', 'invoice_created', 1, 0, 0, NULL, '2026-06-28 15:54:34'),
(317, 17, '✅ فیش شما تأیید شد', 'اطلاعات پرداخت برای فیش #1 ارسال شد.', 'invoice_approved', 1, 0, 0, NULL, '2026-06-28 15:55:31'),
(318, 1, '🧾 فیش پرداخت دریافت شد', 'کاربر Alireza  Ahmadian فیش پرداخت فیش #1 را ارسال کرد.', 'invoice_paid', 1, 0, 0, NULL, '2026-06-28 15:56:04'),
(319, 17, '🏆 فیش شما تکمیل شد', 'مبلغ 455 USD به حساب شما اضافه شد.', 'invoice_finalized', 1, 0, 0, NULL, '2026-06-28 15:56:19'),
(320, 1, '💰 درخواست Top-up جدید', 'کاربر سارا موحد درخواست شارژ 50 USD ارسال کرد.', 'topup_request', 8, 0, 0, NULL, '2026-06-28 16:21:58'),
(321, 1, '❌ درخواست Top-up رد شد', 'درخواست شارژ 50.00 USD شما رد شد. دلیل: Rad', 'topup_rejected', 8, 0, 0, NULL, '2026-06-28 16:22:23'),
(322, 1, '📄 فیش جدید برای شما صادر شد', 'مبلغ 50 USD - خرید چیزی', 'invoice_created', 2, 0, 0, NULL, '2026-06-28 16:23:04'),
(323, 1, '✅ فیش شما تأیید شد', 'اطلاعات پرداخت برای فیش #2 ارسال شد.', 'invoice_approved', 2, 0, 0, NULL, '2026-06-28 16:23:41'),
(324, 1, '📄 فیش جدید برای شما صادر شد', 'مبلغ 32 USD - ', 'invoice_created', 3, 0, 0, NULL, '2026-06-28 16:42:50'),
(325, 1, '✅ فیش شما تأیید شد', 'اطلاعات پرداخت برای فیش #3 ارسال شد.', 'invoice_approved', 3, 0, 0, NULL, '2026-06-28 16:43:18'),
(326, 1, '❌ درخواست Top-up رد شد', 'درخواست شارژ 50.00 USD شما رد شد. دلیل: 6554', 'topup_rejected', 7, 0, 0, NULL, '2026-06-28 23:01:08'),
(327, 1, '💰 درخواست Top-up جدید', 'کاربر سارا موحد درخواست شارژ 50 USD ارسال کرد.', 'topup_request', 9, 0, 0, NULL, '2026-06-28 23:01:17'),
(328, 1, '📄 فیش جدید برای شما صادر شد', 'مبلغ 54 EUR - KKL;', 'invoice_created', 4, 0, 0, NULL, '2026-06-28 23:01:41'),
(329, 1, '✅ فیش شما تأیید شد', 'اطلاعات پرداخت برای فیش #4 ارسال شد.', 'invoice_approved', 4, 0, 0, NULL, '2026-06-28 23:01:49'),
(330, 26, '📢 Announcement', 'test', 'admin_toast', NULL, 0, 0, NULL, '2026-06-28 23:30:19'),
(331, 17, '📢 Announcement', 'Test', 'admin_toast', NULL, 0, 0, NULL, '2026-06-28 23:30:29'),
(332, 1, '❌ درخواست Top-up رد شد', 'درخواست شارژ 50.00 USD شما رد شد. دلیل: خه', 'topup_rejected', 9, 0, 0, NULL, '2026-06-29 09:19:05'),
(333, 1, '💰 درخواست Top-up جدید', 'کاربر سارا موحد درخواست شارژ 50 USD ارسال کرد.', 'topup_request', 10, 0, 0, NULL, '2026-06-29 19:00:08'),
(334, 1, '❌ درخواست Top-up رد شد', 'درخواست شارژ 50.00 USD شما رد شد. دلیل: SD', 'topup_rejected', 10, 0, 0, NULL, '2026-06-29 19:23:41'),
(335, 1, '💰 درخواست Top-up جدید', 'کاربر سارا موحد درخواست شارژ 50 USD ارسال کرد.', 'topup_request', 11, 0, 0, NULL, '2026-06-29 19:23:57'),
(336, 1, '✅ درخواست Top-up تأیید شد', 'درخواست شارژ 50.00 USD شما تأیید شد. اطلاعات حساب برای پرداخت ارسال شد.', 'topup_approved', 11, 0, 0, NULL, '2026-06-29 19:24:12'),
(337, 1, '🧾 فیش پرداخت دریافت شد', 'کاربر سارا موحد فیش پرداخت فیش #4 را ارسال کرد.', 'invoice_paid', 4, 0, 0, NULL, '2026-06-29 19:32:49'),
(338, 1, '🧾 فیش واریز دریافت شد', 'کاربر 1 فیش پرداخت Top-up #11 را ارسال کرد.', 'topup_receipt', 11, 0, 0, NULL, '2026-06-29 19:48:33'),
(339, 1, '💰 شارژ حساب موفق', 'مبلغ 50 USD با موفقیت به حساب شما اضافه شد.', 'topup_completed', 11, 0, 0, NULL, '2026-06-29 19:48:46'),
(340, 1, '💰 درخواست Top-up جدید', 'کاربر سارا موحد درخواست شارژ 200 USD ارسال کرد.', 'topup_request', 12, 0, 0, NULL, '2026-06-29 19:49:06'),
(341, 1, '✅ درخواست Top-up تأیید شد', 'درخواست شارژ 200.00 USD شما تأیید شد. اطلاعات حساب برای پرداخت ارسال شد.', 'topup_approved', 12, 0, 0, NULL, '2026-06-29 19:49:23'),
(342, 1, '🧾 فیش واریز دریافت شد', 'کاربر 2 فیش پرداخت Top-up #12 را ارسال کرد.', 'topup_receipt', 12, 0, 0, NULL, '2026-06-29 19:50:15'),
(343, 1, '💰 شارژ حساب موفق', 'مبلغ 200 USD با موفقیت به حساب شما اضافه شد.', 'topup_completed', 12, 0, 0, NULL, '2026-06-29 19:51:10'),
(344, 1, '💰 درخواست Top-up جدید', 'کاربر سارا موحد درخواست شارژ 50 USD ارسال کرد.', 'topup_request', 13, 0, 0, NULL, '2026-06-29 19:51:33'),
(345, 1, '✅ درخواست Top-up تأیید شد', 'درخواست شارژ 50.00 USD شما تأیید شد. اطلاعات حساب برای پرداخت ارسال شد.', 'topup_approved', 13, 0, 0, NULL, '2026-06-29 19:51:43'),
(346, 1, '🧾 فیش واریز دریافت شد', 'کاربر 2 فیش پرداخت Top-up #13 را ارسال کرد.', 'topup_receipt', 13, 0, 0, NULL, '2026-06-29 19:52:53'),
(347, 1, '💰 شارژ حساب موفق', 'مبلغ 50 USD با موفقیت به حساب شما اضافه شد.', 'topup_completed', 13, 0, 0, NULL, '2026-06-29 20:02:05'),
(348, 1, '💰 درخواست Top-up جدید', 'کاربر سارا موحد درخواست شارژ 100 USDT ارسال کرد.', 'topup_request', 14, 0, 0, NULL, '2026-06-29 20:34:26'),
(349, 1, '✅ درخواست Top-up تأیید شد', 'درخواست شارژ 100.00 USDT شما تأیید شد. اطلاعات حساب برای پرداخت ارسال شد.', 'topup_approved', 14, 0, 0, NULL, '2026-06-29 20:34:43'),
(350, 1, '🧾 فیش واریز دریافت شد', 'کاربر 1 فیش پرداخت Top-up #14 را ارسال کرد.', 'topup_receipt', 14, 0, 0, NULL, '2026-06-29 20:35:02'),
(351, 1, '💰 شارژ حساب موفق', 'مبلغ 100 USDT با موفقیت به حساب شما اضافه شد.', 'topup_completed', 14, 0, 0, NULL, '2026-06-29 20:35:11'),
(352, 1, '📬 درخواست جدید: Western union', 'کاربر سارا موحد یک فرم ارسال کرد.', 'quick_action', NULL, 0, 0, NULL, '2026-06-29 20:38:19'),
(353, 1, '📄 فیش جدید برای شما صادر شد', 'مبلغ 55 EUR - بابت وسترن یونیون به هرماه', 'invoice_created', 5, 0, 0, NULL, '2026-06-29 20:39:23'),
(354, 1, '✅ فیش شما تأیید شد', 'اطلاعات پرداخت برای فیش #5 ارسال شد.', 'invoice_approved', 5, 0, 0, NULL, '2026-06-29 20:40:04'),
(355, 1, '🧾 فیش واریز دریافت شد', 'کاربر 1 فیش پرداخت Top-up #6 را ارسال کرد.', 'topup_receipt', 6, 0, 0, NULL, '2026-06-29 20:48:53'),
(356, 17, '💰 شارژ حساب موفق', 'مبلغ 20 USDT با موفقیت به حساب شما اضافه شد.', 'topup_completed', 6, 0, 0, NULL, '2026-06-29 20:50:09'),
(357, 17, '📢 Announcement', 'Teest', 'admin_toast', NULL, 0, 0, NULL, '2026-06-29 20:50:41'),
(358, 17, '📢 Announcement', 'Test', 'admin_toast', NULL, 0, 0, NULL, '2026-06-29 20:51:05'),
(359, 17, '📢 Announcement', 'Hhhjjs', 'admin_toast', NULL, 0, 0, NULL, '2026-06-29 20:51:39');

-- --------------------------------------------------------

--
-- Table structure for table `user_receipts`
--

CREATE TABLE `user_receipts` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `withdrawal_id` int(11) DEFAULT NULL,
  `receipt_file` varchar(500) NOT NULL,
  `transaction_id` varchar(100) DEFAULT NULL,
  `card_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `uploaded_at` timestamp NULL DEFAULT current_timestamp(),
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `uploaded_by` int(11) DEFAULT NULL,
  `admin_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_trade_stats`
--

CREATE TABLE `user_trade_stats` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `total_trades` int(11) DEFAULT 0,
  `trades_for_discount` int(11) DEFAULT 0,
  `commission_rate` int(11) DEFAULT 100,
  `discount_active` tinyint(4) DEFAULT 0,
  `last_trade_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `withdrawal_requests`
--

CREATE TABLE `withdrawal_requests` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `amount` decimal(20,2) NOT NULL,
  `currency` varchar(10) NOT NULL,
  `iban_number` varchar(50) DEFAULT NULL,
  `card_number` varchar(24) DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `recipient_name` varchar(200) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `target_user_id` int(11) DEFAULT NULL COMMENT 'شناسه شخصی که پول به او واریز می‌شود',
  `target_user_name` varchar(200) DEFAULT NULL COMMENT 'نام شخص گیرنده',
  `status` enum('pending','approved','completed','rejected') DEFAULT 'pending',
  `receipt_file` varchar(500) DEFAULT NULL,
  `transaction_id` varchar(100) DEFAULT NULL,
  `card_id` int(11) DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `admin_id` int(11) DEFAULT NULL COMMENT 'شناسه ادمین تایید کننده',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `admin_action_at` timestamp NULL DEFAULT NULL,
  `admin_completed_at` timestamp NULL DEFAULT NULL COMMENT 'تاریخ تکمیل توسط ادمین',
  `receipt_uploaded_at` timestamp NULL DEFAULT NULL COMMENT 'تاریخ آپلود فیش',
  `receipt_uploaded_by` int(11) DEFAULT NULL COMMENT 'ادمین آپلود کننده فیش',
  `completed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin_documents`
--
ALTER TABLE `admin_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_sender` (`sender_admin_id`),
  ADD KEY `idx_recipient` (`chat_id`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `ads`
--
ALTER TABLE `ads`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `ad_deals`
--
ALTER TABLE `ad_deals`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `deal_code` (`deal_code`),
  ADD KEY `offer_id` (`offer_id`),
  ADD KEY `ad_id` (`ad_id`),
  ADD KEY `buyer_id` (`buyer_id`),
  ADD KEY `seller_id` (`seller_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `completed_by` (`completed_by`);

--
-- Indexes for table `ad_offers`
--
ALTER TABLE `ad_offers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ad_id` (`ad_id`),
  ADD KEY `buyer_id` (`buyer_id`),
  ADD KEY `seller_id` (`seller_id`);

--
-- Indexes for table `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `company_cards`
--
ALTER TABLE `company_cards`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `currency_rates`
--
ALTER TABLE `currency_rates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `currency` (`currency`);

--
-- Indexes for table `dashboard_slides`
--
ALTER TABLE `dashboard_slides`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `exchange_ads`
--
ALTER TABLE `exchange_ads`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_type` (`type`),
  ADD KEY `idx_currency` (`currency`);

--
-- Indexes for table `exchange_offers`
--
ALTER TABLE `exchange_offers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_ad` (`ad_id`);

--
-- Indexes for table `money_transfers`
--
ALTER TABLE `money_transfers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `tracking_code` (`tracking_code`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_tracking_code` (`tracking_code`);

--
-- Indexes for table `news`
--
ALTER TABLE `news`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `payment_receipts`
--
ALTER TABLE `payment_receipts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `offer_id` (`offer_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `personal_transactions`
--
ALTER TABLE `personal_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_date` (`user_id`,`transaction_date`);

--
-- Indexes for table `push_subscriptions`
--
ALTER TABLE `push_subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_endpoint` (`endpoint`(255)),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indexes for table `pwa_update_logs`
--
ALTER TABLE `pwa_update_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `updated_by` (`updated_by`);

--
-- Indexes for table `quick_actions`
--
ALTER TABLE `quick_actions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `quick_action_fields`
--
ALTER TABLE `quick_action_fields`
  ADD PRIMARY KEY (`id`),
  ADD KEY `action_id` (`action_id`);

--
-- Indexes for table `quick_action_submissions`
--
ALTER TABLE `quick_action_submissions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_action` (`action_id`);

--
-- Indexes for table `sessions`
--
ALTER TABLE `sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `idx_token` (`token`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_expires` (`expires_at`);

--
-- Indexes for table `subscribers`
--
ALTER TABLE `subscribers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `plan_id` (`plan_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_expiry` (`expiry_date`);

--
-- Indexes for table `subscription_offers`
--
ALTER TABLE `subscription_offers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `subscriber_id` (`subscriber_id`),
  ADD KEY `admin_id` (`admin_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `subscription_plans`
--
ALTER TABLE `subscription_plans`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `temp_reject_reasons`
--
ALTER TABLE `temp_reject_reasons`
  ADD PRIMARY KEY (`user_id`),
  ADD KEY `offer_id` (`offer_id`);

--
-- Indexes for table `topup_requests`
--
ALTER TABLE `topup_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `admin_id` (`admin_id`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `transaction_id` (`transaction_id`),
  ADD KEY `sender_id` (`sender_id`),
  ADD KEY `receiver_id` (`receiver_id`),
  ADD KEY `admin_id` (`admin_id`);

--
-- Indexes for table `transfer_receipts`
--
ALTER TABLE `transfer_receipts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_request_id` (`request_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `unpaid_invoices`
--
ALTER TABLE `unpaid_invoices`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `telegram_id` (`telegram_id`),
  ADD UNIQUE KEY `iban_number` (`iban_number`),
  ADD UNIQUE KEY `account_number` (`account_number`);

--
-- Indexes for table `user_ads`
--
ALTER TABLE `user_ads`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `user_balance_history`
--
ALTER TABLE `user_balance_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_timestamp` (`user_id`,`timestamp`),
  ADD KEY `idx_timestamp` (`timestamp`),
  ADD KEY `idx_user_currency` (`user_id`,`timestamp`,`balance_usd`,`balance_eur`,`balance_usdt`,`balance_irr`),
  ADD KEY `idx_transaction` (`transaction_id`);

--
-- Indexes for table `user_contacts`
--
ALTER TABLE `user_contacts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_contact` (`user_id`,`contact_id`),
  ADD KEY `contact_id` (`contact_id`);

--
-- Indexes for table `user_discounts`
--
ALTER TABLE `user_discounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_discount` (`user_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `user_notifications`
--
ALTER TABLE `user_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `user_receipts`
--
ALTER TABLE `user_receipts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `withdrawal_id` (`withdrawal_id`),
  ADD KEY `card_id` (`card_id`),
  ADD KEY `uploaded_by` (`uploaded_by`);

--
-- Indexes for table `user_trade_stats`
--
ALTER TABLE `user_trade_stats`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `withdrawal_requests`
--
ALTER TABLE `withdrawal_requests`
  ADD KEY `idx_target_user` (`target_user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin_documents`
--
ALTER TABLE `admin_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ads`
--
ALTER TABLE `ads`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ad_deals`
--
ALTER TABLE `ad_deals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `ad_offers`
--
ALTER TABLE `ad_offers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=272;

--
-- AUTO_INCREMENT for table `chat_messages`
--
ALTER TABLE `chat_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=130;

--
-- AUTO_INCREMENT for table `company_cards`
--
ALTER TABLE `company_cards`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `currency_rates`
--
ALTER TABLE `currency_rates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2661;

--
-- AUTO_INCREMENT for table `dashboard_slides`
--
ALTER TABLE `dashboard_slides`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `exchange_ads`
--
ALTER TABLE `exchange_ads`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `exchange_offers`
--
ALTER TABLE `exchange_offers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `money_transfers`
--
ALTER TABLE `money_transfers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `news`
--
ALTER TABLE `news`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=61;

--
-- AUTO_INCREMENT for table `payment_receipts`
--
ALTER TABLE `payment_receipts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `personal_transactions`
--
ALTER TABLE `personal_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=65;

--
-- AUTO_INCREMENT for table `push_subscriptions`
--
ALTER TABLE `push_subscriptions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2046;

--
-- AUTO_INCREMENT for table `pwa_update_logs`
--
ALTER TABLE `pwa_update_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=81;

--
-- AUTO_INCREMENT for table `quick_actions`
--
ALTER TABLE `quick_actions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `quick_action_fields`
--
ALTER TABLE `quick_action_fields`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=47;

--
-- AUTO_INCREMENT for table `quick_action_submissions`
--
ALTER TABLE `quick_action_submissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `sessions`
--
ALTER TABLE `sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=360;

--
-- AUTO_INCREMENT for table `subscribers`
--
ALTER TABLE `subscribers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `subscription_offers`
--
ALTER TABLE `subscription_offers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

--
-- AUTO_INCREMENT for table `subscription_plans`
--
ALTER TABLE `subscription_plans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `topup_requests`
--
ALTER TABLE `topup_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=222;

--
-- AUTO_INCREMENT for table `transfer_receipts`
--
ALTER TABLE `transfer_receipts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `unpaid_invoices`
--
ALTER TABLE `unpaid_invoices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=46;

--
-- AUTO_INCREMENT for table `user_ads`
--
ALTER TABLE `user_ads`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=93;

--
-- AUTO_INCREMENT for table `user_balance_history`
--
ALTER TABLE `user_balance_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=214;

--
-- AUTO_INCREMENT for table `user_contacts`
--
ALTER TABLE `user_contacts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=107;

--
-- AUTO_INCREMENT for table `user_discounts`
--
ALTER TABLE `user_discounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `user_notifications`
--
ALTER TABLE `user_notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=360;

--
-- AUTO_INCREMENT for table `user_receipts`
--
ALTER TABLE `user_receipts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_trade_stats`
--
ALTER TABLE `user_trade_stats`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `ad_deals`
--
ALTER TABLE `ad_deals`
  ADD CONSTRAINT `ad_deals_ibfk_1` FOREIGN KEY (`offer_id`) REFERENCES `ad_offers` (`id`),
  ADD CONSTRAINT `ad_deals_ibfk_2` FOREIGN KEY (`ad_id`) REFERENCES `user_ads` (`id`),
  ADD CONSTRAINT `ad_deals_ibfk_3` FOREIGN KEY (`buyer_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `ad_deals_ibfk_4` FOREIGN KEY (`seller_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `ad_deals_ibfk_5` FOREIGN KEY (`completed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `ad_offers`
--
ALTER TABLE `ad_offers`
  ADD CONSTRAINT `ad_offers_ibfk_1` FOREIGN KEY (`ad_id`) REFERENCES `user_ads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ad_offers_ibfk_2` FOREIGN KEY (`buyer_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `ad_offers_ibfk_3` FOREIGN KEY (`seller_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD CONSTRAINT `chat_messages_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `exchange_ads`
--
ALTER TABLE `exchange_ads`
  ADD CONSTRAINT `exchange_ads_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `exchange_offers`
--
ALTER TABLE `exchange_offers`
  ADD CONSTRAINT `exchange_offers_ibfk_1` FOREIGN KEY (`ad_id`) REFERENCES `exchange_ads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `exchange_offers_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `money_transfers`
--
ALTER TABLE `money_transfers`
  ADD CONSTRAINT `money_transfers_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payment_receipts`
--
ALTER TABLE `payment_receipts`
  ADD CONSTRAINT `payment_receipts_ibfk_1` FOREIGN KEY (`offer_id`) REFERENCES `subscription_offers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payment_receipts_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `personal_transactions`
--
ALTER TABLE `personal_transactions`
  ADD CONSTRAINT `personal_transactions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `push_subscriptions`
--
ALTER TABLE `push_subscriptions`
  ADD CONSTRAINT `push_subscriptions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `quick_action_fields`
--
ALTER TABLE `quick_action_fields`
  ADD CONSTRAINT `quick_action_fields_ibfk_1` FOREIGN KEY (`action_id`) REFERENCES `quick_actions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sessions`
--
ALTER TABLE `sessions`
  ADD CONSTRAINT `sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `subscribers`
--
ALTER TABLE `subscribers`
  ADD CONSTRAINT `subscribers_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `subscribers_ibfk_2` FOREIGN KEY (`plan_id`) REFERENCES `subscription_plans` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `subscription_offers`
--
ALTER TABLE `subscription_offers`
  ADD CONSTRAINT `subscription_offers_ibfk_1` FOREIGN KEY (`subscriber_id`) REFERENCES `subscribers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `subscription_offers_ibfk_2` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `temp_reject_reasons`
--
ALTER TABLE `temp_reject_reasons`
  ADD CONSTRAINT `temp_reject_reasons_ibfk_1` FOREIGN KEY (`offer_id`) REFERENCES `exchange_offers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `topup_requests`
--
ALTER TABLE `topup_requests`
  ADD CONSTRAINT `topup_requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `topup_requests_ibfk_2` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `transactions_ibfk_2` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `transactions_ibfk_3` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `transfer_receipts`
--
ALTER TABLE `transfer_receipts`
  ADD CONSTRAINT `transfer_receipts_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `money_transfers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `transfer_receipts_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_ads`
--
ALTER TABLE `user_ads`
  ADD CONSTRAINT `user_ads_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_balance_history`
--
ALTER TABLE `user_balance_history`
  ADD CONSTRAINT `user_balance_history_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_contacts`
--
ALTER TABLE `user_contacts`
  ADD CONSTRAINT `user_contacts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `user_contacts_ibfk_2` FOREIGN KEY (`contact_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `user_discounts`
--
ALTER TABLE `user_discounts`
  ADD CONSTRAINT `user_discounts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_discounts_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `user_notifications`
--
ALTER TABLE `user_notifications`
  ADD CONSTRAINT `user_notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_trade_stats`
--
ALTER TABLE `user_trade_stats`
  ADD CONSTRAINT `user_trade_stats_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

DELIMITER $$
--
-- Events
--
CREATE DEFINER=`aradexch`@`localhost` EVENT `expire_old_ads` ON SCHEDULE EVERY 1 HOUR STARTS '2026-05-07 01:36:54' ON COMPLETION NOT PRESERVE ENABLE DO BEGIN
    UPDATE `user_ads` 
    SET `status` = 'expired' 
    WHERE `status` = 'active' 
    AND `expires_at` IS NOT NULL 
    AND `expires_at` < NOW();
END$$

DELIMITER ;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
