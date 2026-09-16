<?php
/**
 * includes/mozayede_bridge.php
 * ---------------------------------------------------------------
 * پُلِ بین آگهی‌های ثبت‌شده در mainbot (جدولِ mozayede در دیتابیسِ
 * aradexch_bot، که در کانالِ تلگرام پست می‌شوند) و پیشنهادهای ارسالی از
 * اپلیکیشنِ AvaPay (جدولِ ad_offers در دیتابیسِ aradexch_app).
 *
 * وقتی آگهی‌ای در mainbot ثبت می‌شود، همان لحظه یک ردیفِ آینه در
 * user_ads (دیتابیسِ اپ) هم ساخته می‌شود (این بخش از قبل در
 * mainbot/update.php وجود داشت) — این فایل سه تا کارِ تازه را اضافه
 * می‌کند:
 *   ۱) وقتی کاربرِ اپ روی چنین آگهی‌ای پیشنهاد می‌فرستد، همان پیشنهاد را
 *      (بی‌صدا، بدون ادیتِ کانال) در جدولِ review ربات هم می‌نویسد — تا
 *      وقتی بعداً قبول/رد شود، در فهرستِ «پیشنهادهای ارسال‌شده»ی پستِ
 *      کانال هم دیده شود.
 *   ۲) وقتی آگهی‌دهنده (از داخلِ اپ) پیشنهاد را قبول/رد می‌کند، همان
 *      ردیفِ review آپدیت و پستِ کانال با متنِ تازه + دکمه‌ی مناسب
 *      ویرایش می‌شود («در حال انجام است» موقعِ قبول، دوباره «ارسال
 *      پیشنهاد» موقعِ رد).
 *   ۳) وقتی ادمین از پنل، معامله را «تکمیل» می‌کند، دکمه‌ی پستِ کانال به
 *      «با موفقیت انجام شد» تغییر می‌کند.
 *
 * این فایل عمداً کاملاً مستقل و defensive نوشته شده: هر خطایی در اتصال
 * به دیتابیسِ ربات یا در خودِ تلگرام، فقط لاگ می‌شود و هیچ‌وقت جریانِ
 * اصلیِ قبول/رد/تکمیلِ پیشنهاد در اپ را نمی‌شکند.
 * ---------------------------------------------------------------
 */

if (!defined('MOZ_BOT_DB_HOST')) define('MOZ_BOT_DB_HOST', 'localhost');
if (!defined('MOZ_BOT_DB_USER')) define('MOZ_BOT_DB_USER', 'aradexch_bot');
if (!defined('MOZ_BOT_DB_PASS')) define('MOZ_BOT_DB_PASS', 'dA!G&&aSq7-1');
if (!defined('MOZ_BOT_DB_NAME')) define('MOZ_BOT_DB_NAME', 'aradexch_bot');
if (!defined('MOZ_CHANNEL_USERNAME')) define('MOZ_CHANNEL_USERNAME', 'AradTransfer'); // برابرِ ثابتِ CHANNEL در mainbot/config.php
if (!defined('MOZ_BOT_USERNAME')) define('MOZ_BOT_USERNAME', 'aradexchange_bot'); // برابرِ ثابتِ user_bot در mainbot/config.php

if (!function_exists('moz_botConn')) {
    /** اتصالِ (کش‌شده در طولِ همین درخواست) به دیتابیسِ خودِ ربات — با مهلتِ کوتاه */
    function moz_botConn() {
        static $conn = null;
        static $tried = false;
        if ($conn instanceof mysqli) return $conn;
        if ($tried) return null; // یک‌بار در این درخواست تلاش کافی‌ست؛ اگر ناموفق بود دوباره تلاش نکن (از هنگ‌کردنِ تکراری جلوگیری می‌کند)
        $tried = true;
        require_once __DIR__ . '/fast_mysqli.php';
        $conn = avapay_fast_mysqli(MOZ_BOT_DB_HOST, MOZ_BOT_DB_USER, MOZ_BOT_DB_PASS, MOZ_BOT_DB_NAME, 3);
        return $conn;
    }
}

if (!function_exists('moz_ensureLinkColumns')) {
    /** ستون‌های اتصال به mozayede روی user_ads — یک‌بار برای همیشه، self-healing */
    function moz_ensureLinkColumns($conn) {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $cols = [];
            $r = $conn->query("SHOW COLUMNS FROM `user_ads`");
            if ($r) { while ($c = $r->fetch_assoc()) $cols[strtolower($c['Field'])] = true; }
            if (!isset($cols['mozayede_id']))         $conn->query("ALTER TABLE `user_ads` ADD COLUMN `mozayede_id` INT DEFAULT NULL, ADD INDEX `idx_mozayede_id` (`mozayede_id`)");
            if (!isset($cols['mozayede_channel']))     $conn->query("ALTER TABLE `user_ads` ADD COLUMN `mozayede_channel` VARCHAR(64) DEFAULT NULL");
            if (!isset($cols['mozayede_message_id']))  $conn->query("ALTER TABLE `user_ads` ADD COLUMN `mozayede_message_id` BIGINT DEFAULT NULL");
        } catch (\Throwable $e) { error_log('moz_ensureLinkColumns error: ' . $e->getMessage()); }
    }
}

if (!function_exists('moz_ensureReviewTable')) {
    function moz_ensureReviewTable($botConn) {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $botConn->query("CREATE TABLE IF NOT EXISTS `review` (
                `id` INT PRIMARY KEY AUTO_INCREMENT,
                `mozayede` INT NOT NULL,
                `meghdar` DECIMAL(20,2) DEFAULT NULL,
                `meghdar_arz` DECIMAL(20,6) DEFAULT NULL,
                `mozayede_chat_id` BIGINT DEFAULT NULL,
                `message` TEXT,
                `chat_id` BIGINT DEFAULT NULL,
                `name` VARCHAR(191) DEFAULT NULL,
                `lastname` VARCHAR(191) DEFAULT NULL,
                `ok` TINYINT DEFAULT NULL,
                `date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_mozayede` (`mozayede`)
            )");
        } catch (\Throwable $e) { error_log('moz_ensureReviewTable error: ' . $e->getMessage()); }
    }
}

if (!function_exists('moz_getAdLink')) {
    /** اگر این آگهیِ اپ ریشه‌اش یک آگهیِ mainbot باشد، اطلاعاتِ اتصالش را برمی‌گرداند؛ وگرنه null */
    function moz_getAdLink($conn, $adId) {
        moz_ensureLinkColumns($conn);
        $st = $conn->prepare("SELECT mozayede_id, mozayede_channel, mozayede_message_id FROM user_ads WHERE id = ? LIMIT 1");
        if (!$st) return null;
        $st->bind_param("i", $adId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        if (!$row || empty($row['mozayede_id']) || empty($row['mozayede_channel']) || empty($row['mozayede_message_id'])) return null;
        return $row;
    }
}

if (!function_exists('moz_editChannelMessage')) {
    /** ادیتِ مستقیمِ متن + دکمه‌ی پستِ کانال از طریق Telegram Bot API */
    function moz_editChannelMessage($chatId, $messageId, $text, $keyboard = null) {
        if (!defined('AVAPAY_BOT_TOKEN')) return false;
        $url = "https://api.telegram.org/bot" . AVAPAY_BOT_TOKEN . "/editMessageText";
        $payload = [
            'chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text,
            'disable_web_page_preview' => true,
        ];
        if ($keyboard !== null) $payload['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
            ]);
            $res = curl_exec($ch);
            curl_close($ch);
            $ok = $res !== false && (json_decode($res, true)['ok'] ?? false);
            if (!$ok) error_log('moz_editChannelMessage failed: ' . $res);
            return $ok;
        } catch (\Throwable $e) {
            error_log('moz_editChannelMessage exception: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('moz_buildOffersText')) {
    /** بازسازیِ لیستِ «پیشنهادهای ارسال شده» — دقیقاً مطابقِ همان قالبی که خودِ ربات می‌سازد */
    function moz_buildOffersText($botConn, $mozayedeId) {
        static $jdateLoaded = false;
        if (!$jdateLoaded && !function_exists('jdate')) {
            $jf = __DIR__ . '/../mainbot/strings/jdf.php';
            if (is_file($jf)) { @require_once $jf; }
            $jdateLoaded = true;
        }
        $st = $botConn->prepare("SELECT * FROM review WHERE mozayede = ? ORDER BY id ASC");
        if (!$st) return '';
        $st->bind_param("i", $mozayedeId);
        $st->execute();
        $res = $st->get_result();
        $lines = [];
        while ($row = $res->fetch_assoc()) {
            if ($row['ok'] === null) $stat = '🔸';
            elseif ((int)$row['ok'] === 1) $stat = '✅';
            else $stat = '❌';
            $date = function_exists('jdate') ? jdate('j F, G:i', strtotime($row['date'])) : date('Y-m-d H:i', strtotime($row['date']));
            $name = (string)($row['name'] ?? '');
            $lastname = (string)($row['lastname'] ?? '');
            $firstLastChar = mb_substr($lastname, 0, 1, 'UTF-8');
            $meghdar = number_format((float)$row['meghdar']);
            $lines[] = "$stat {$meghdar} تومان در {$date} توسط {$name}.{$firstLastChar}";
        }
        return implode(PHP_EOL, $lines);
    }
}

if (!function_exists('moz_buildAdText')) {
    /** متنِ اصلیِ پستِ آگهی — از روی ردیفِ mozayede + لیستِ پیشنهادها */
    function moz_buildAdText($mozRow, $offersText) {
        $mablagh = number_format((float)$mozRow['mablagh_pishnehad']);
        $text = "\n\n❇️ حواله  : {$mozRow['id']} #{$mozRow['type']}_{$mozRow['arz']}\n\n";
        $text .= "👤 {$mozRow['type']}: {$mozRow['name']}\n";
        $text .= "💶 مقدار ارز: {$mozRow['meghdar_arz']} {$mozRow['arz']}\n";
        $text .= "💰 مبلغ پیشنهادی: {$mablagh} تومان\n";
        $text .= "💳 نحوه پــرداخــت :حواله بانکی حســاب شــخـص از {$mozRow['country']}\n";
        $text .= "✍️ توضیحات : {$mozRow['info']}\n\n";
        $text .= "✍️ پیشنهادهای ارسال شده :\n\n";
        $text .= ($offersText !== '' ? $offersText : '—') . "\n\n";
        $text .= " 🆔👉 @" . MOZ_CHANNEL_USERNAME;
        return $text;
    }
}

if (!function_exists('moz_syncOfferCreated')) {
    /**
     * وقتی پیشنهادی از اپ روی یک آگهیِ ریشه‌دار‌ در mozayede ثبت می‌شود —
     * بی‌صدا (بدون ادیتِ کانال) در review هم ثبتش می‌کند تا بعداً در لیستِ
     * پیشنهادها دیده شود.
     */
    function moz_syncOfferCreated($conn, $adId, $offeredPrice, $requestedAmount, $buyerFirst, $buyerLast) {
        try {
            $link = moz_getAdLink($conn, $adId);
            if (!$link) return; // این آگهی ریشه‌اش mainbot نیست، کاری لازم نیست

            $botConn = moz_botConn();
            if (!$botConn) return;
            moz_ensureReviewTable($botConn);

            $mozRow = $botConn->query("SELECT chat_id FROM mozayede WHERE id = " . (int)$link['mozayede_id'])->fetch_assoc();
            $mozChatId = $mozRow['chat_id'] ?? null;

            $st = $botConn->prepare("INSERT INTO review (mozayede, meghdar, meghdar_arz, mozayede_chat_id, message, chat_id, name, lastname, ok) VALUES (?, ?, ?, ?, ?, NULL, ?, ?, NULL)");
            if (!$st) return;
            $msg = 'ارسال‌شده از اپلیکیشن AvaPay';
            $st->bind_param("iddisss", $link['mozayede_id'], $offeredPrice, $requestedAmount, $mozChatId, $msg, $buyerFirst, $buyerLast);
            $st->execute();
        } catch (\Throwable $e) {
            error_log('moz_syncOfferCreated error: ' . $e->getMessage());
        }
    }
}

if (!function_exists('moz_syncOfferResponded')) {
    /**
     * وقتی پیشنهادی (که ریشه‌اش در mozayede است) از داخلِ اپ قبول یا رد
     * می‌شود — جدیدترین ردیفِ pending مربوط به همین آگهی را در review
     * به‌روزرسانی می‌کند و پستِ کانال را ادیت می‌کند.
     * @param bool $accepted true=قبول، false=رد
     */
    function moz_syncOfferResponded($conn, $adId, $accepted) {
        try {
            $link = moz_getAdLink($conn, $adId);
            if (!$link) return;

            $botConn = moz_botConn();
            if (!$botConn) return;
            moz_ensureReviewTable($botConn);

            // آخرین پیشنهادِ pending همین آگهی که از اپ ثبت شده (chat_id IS NULL چون
            // پیشنهادِ اپ لزوماً chat_id تلگرامیِ خریدار را ندارد)
            $rid = $botConn->query(
                "SELECT id FROM review WHERE mozayede = " . (int)$link['mozayede_id'] . " AND ok IS NULL ORDER BY id DESC LIMIT 1"
            )->fetch_assoc();
            if ($rid && !empty($rid['id'])) {
                $okVal = $accepted ? 1 : 0;
                $botConn->query("UPDATE review SET ok = {$okVal} WHERE id = " . (int)$rid['id']);
            }

            $mozRow = $botConn->query("SELECT * FROM mozayede WHERE id = " . (int)$link['mozayede_id'])->fetch_assoc();
            if (!$mozRow) return;

            $offersText = moz_buildOffersText($botConn, $link['mozayede_id']);
            $text = moz_buildAdText($mozRow, $offersText);

            if ($accepted) {
                $keyboard = ['inline_keyboard' => [
                    [['text' => '🟡 آگهی در حال انجام است...', 'url' => 'https://t.me/' . MOZ_BOT_USERNAME]]
                ]];
            } else {
                $keyboard = ['inline_keyboard' => [
                    [['text' => '📂 ارسال پیشنهاد', 'url' => 'https://t.me/' . MOZ_BOT_USERNAME . '?start=mozayedeget=' . $link['mozayede_id']]]
                ]];
            }

            moz_editChannelMessage($link['mozayede_channel'], $link['mozayede_message_id'], $text, $keyboard);
        } catch (\Throwable $e) {
            error_log('moz_syncOfferResponded error: ' . $e->getMessage());
        }
    }
}

if (!function_exists('moz_syncDealCompleted')) {
    /** وقتی ادمین از پنل، معامله را «تکمیل» می‌زند — فقط دکمه‌ی پست کانال عوض می‌شود */
    function moz_syncDealCompleted($conn, $adId) {
        try {
            $link = moz_getAdLink($conn, $adId);
            if (!$link) return;

            $botConn = moz_botConn();
            if (!$botConn) return;
            moz_ensureReviewTable($botConn);

            $mozRow = $botConn->query("SELECT * FROM mozayede WHERE id = " . (int)$link['mozayede_id'])->fetch_assoc();
            if (!$mozRow) return;

            $offersText = moz_buildOffersText($botConn, $link['mozayede_id']);
            $text = moz_buildAdText($mozRow, $offersText);
            $keyboard = ['inline_keyboard' => [
                [['text' => '✅ این آگهی با موفقیت انجام شد', 'url' => 'https://t.me/' . MOZ_BOT_USERNAME]]
            ]];

            moz_editChannelMessage($link['mozayede_channel'], $link['mozayede_message_id'], $text, $keyboard);

            // وضعیتِ خودِ آگهی هم در دیتابیسِ ربات به‌عنوانِ غیرفعال ثبت شود
            $botConn->query("UPDATE mozayede SET stat = 0 WHERE id = " . (int)$link['mozayede_id']);
        } catch (\Throwable $e) {
            error_log('moz_syncDealCompleted error: ' . $e->getMessage());
        }
    }
}
