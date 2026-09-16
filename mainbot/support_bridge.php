<?php
/**
 * mainbot/support_bridge.php
 * ------------------------------------------------------------------
 * پل پشتیبانی برای ربات اصلی (mainbot).
 *
 * چون وبهوک تلگرام فقط می‌تواند به یک آدرس وصل باشد و این ربات وبهوک
 * فعال است، پاسخ‌های ادمین به پیام‌های پشتیبانیِ کاربران (که از داشبورد
 * آوای‌پی ارسال شده) به همین‌جا می‌رسند. این فایل آن‌ها را پردازش می‌کند:
 *   - کلیک روی دکمه‌ی «✍️ پاسخ» (callback: reply_to_<userId>)
 *   - پیام متنی/فایل ادمین در پاسخ (reply) به آن پیام
 * سپس پاسخ را در chat_messages ذخیره و برای کاربر (اپ + تلگرام) می‌فرستد.
 *
 * این فایل باید در ابتدای mainbot/update.php و پیش از منطق ربات فراخوانی شود:
 *     require_once __DIR__ . '/support_bridge.php';
 *     if (avapay_support_bridge()) return; // پیام پشتیبانی بود و پردازش شد
 *
 * از اتصال دیتابیس اپ اصلی استفاده می‌کند تا مستقل از لایه‌ی Medoo ربات باشد.
 */

if (!function_exists('avapay_support_bridge')) {

function avapay_support_bridge($preUpdate = null)
{
    // اگر آپدیت از قبل خوانده شده، همان را استفاده کن (php://input فقط یک‌بار خواندنی است)
    if ($preUpdate !== null) {
        $update = is_object($preUpdate) ? json_decode(json_encode($preUpdate), true) : $preUpdate;
    } else {
        $raw = file_get_contents('php://input');
        if (!$raw) return false;
        $update = json_decode($raw, true);
    }
    if (!is_array($update)) return false;

    // پیش‌بررسی سریع: فقط اگر ممکن است پیام پشتیبانی باشد ادامه بده
    // (کال‌بک reply_to_ یا پیام ادمین). در غیر این‌صورت زود false برگردان.
    $ADMIN_IDS_PRE = [5330629504, 1016239559, 484167219];
    $maybeSupport = false;
    if (isset($update['callback_query']['data']) && strpos($update['callback_query']['data'], 'reply_to_') === 0) {
        $maybeSupport = true;
    } elseif (isset($update['message']['from']['id']) && in_array((int)$update['message']['from']['id'], $ADMIN_IDS_PRE, true)) {
        // فقط اگر ریپلای است یا با /pm شروع می‌شود
        $t = $update['message']['text'] ?? '';
        if (isset($update['message']['reply_to_message']) || strpos($t, '/pm') === 0) {
            $maybeSupport = true;
        }
    }
    if (!$maybeSupport) return false;

    // اتصال دیتابیس اپ اصلی (config/database.php یک پوشه بالاتر)
    $cfg = __DIR__ . '/../config/database.php';
    if (!is_file($cfg)) return false;
    require_once $cfg; // فراهم می‌کند: $conn (mysqli) و SUPPORT/BOT توکن‌ها
    global $conn;
    if (!($conn instanceof mysqli)) return false;

    $BOT_TOKEN = defined('BOT_TOKEN') ? BOT_TOKEN : '';
    $ADMIN_IDS = [5330629504, 1016239559, 484167219];
    if (defined('ADMIN_TELEGRAM_ID')) $ADMIN_IDS[] = (int)ADMIN_TELEGRAM_ID;
    // همچنین هر ادمین دیگری که در دیتابیس ثبت شده را هم معتبر بدان (مقاوم در برابر
    // تغییر/افزوده‌شدن ادمین‌ها بدون نیاز به ویرایش این فایل)
    try {
        $adminRes = $conn->query("SELECT telegram_id FROM users WHERE (role = 'admin' OR is_admin = 1) AND telegram_id IS NOT NULL AND telegram_id <> ''");
        if ($adminRes) {
            while ($arow = $adminRes->fetch_assoc()) {
                if (is_numeric($arow['telegram_id'])) $ADMIN_IDS[] = (int)$arow['telegram_id'];
            }
        }
    } catch (\Throwable $e) { /* اگر ستون‌ها متفاوت بود، لیست ثابت بالا همچنان کار می‌کند */ }
    $ADMIN_IDS = array_values(array_unique($ADMIN_IDS));

    // اطمینان از وجود جداول لازم
    $conn->query("CREATE TABLE IF NOT EXISTS chat_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        sender ENUM('user','admin') NOT NULL,
        message MEDIUMTEXT NULL,
        file_path VARCHAR(255) NULL,
        file_name VARCHAR(255) NULL,
        file_size INT NULL,
        telegram_message_id BIGINT NULL,
        is_read TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS admin_reply_prompts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        prompt_message_id BIGINT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ---------- ابزار تلگرام ----------
    $tg = function ($method, $fields) use ($BOT_TOKEN) {
        $url = "https://api.telegram.org/bot{$BOT_TOKEN}/{$method}";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $res = curl_exec($ch);
        curl_close($ch);
        return json_decode($res, true);
    };
    $tgSendText = function ($chatId, $text, $replyMarkup = null, $replyTo = null) use ($tg) {
        $f = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
        if ($replyTo) $f['reply_to_message_id'] = $replyTo;
        if ($replyMarkup) $f['reply_markup'] = json_encode($replyMarkup);
        $r = $tg('sendMessage', $f);
        return $r['result']['message_id'] ?? null;
    };
    $tgSendFile = function ($chatId, $localPath, $caption = '') use ($tg) {
        if (!is_file($localPath)) return null;
        $r = $tg('sendDocument', ['chat_id' => $chatId, 'document' => new CURLFile($localPath), 'caption' => $caption]);
        return $r['result']['message_id'] ?? null;
    };
    $tgDownload = function ($fileId) use ($BOT_TOKEN) {
        require_once __DIR__ . '/../includes/upload_paths.php';
        // با timeout تا واکشی فایل تلگرام ورکر را بلاک نکند
        $__ctx = stream_context_create(['http' => ['timeout' => 15], 'https' => ['timeout' => 15]]);
        $info = json_decode(@file_get_contents("https://api.telegram.org/bot{$BOT_TOKEN}/getFile?file_id={$fileId}", false, $__ctx), true);
        if (empty($info['ok'])) return null;
        $path = $info['result']['file_path'];
        $dirAbs = avapay_upload_dir('chat');
        $ext = pathinfo($path, PATHINFO_EXTENSION) ?: 'dat';
        $fname = uniqid('chat_') . '.' . $ext;
        $local = 'uploads/chat/' . $fname;
        $data = @file_get_contents("https://api.telegram.org/file/bot{$BOT_TOKEN}/{$path}", false, $__ctx);
        if ($data === false) return null;
        @file_put_contents($dirAbs . $fname, $data);
        return $local;
    };
    $userName = function ($uid) use ($conn) {
        $uid = (int)$uid;
        $r = $conn->query("SELECT first_name, last_name FROM users WHERE id = $uid LIMIT 1");
        $u = $r ? $r->fetch_assoc() : null;
        if ($u) { $n = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')); return $n !== '' ? $n : "کاربر #$uid"; }
        return "کاربر #$uid";
    };

    // ==================== callback: دکمه‌ی پاسخ ====================
    if (isset($update['callback_query'])) {
        $cb = $update['callback_query'];
        $data = $cb['data'] ?? '';
        $fromId = (int)($cb['from']['id'] ?? 0);
        $cbChatId = $cb['message']['chat']['id'] ?? null;
        $cbMsgId = $cb['message']['message_id'] ?? null;

        if (strpos($data, 'reply_to_') === 0 && in_array($fromId, $ADMIN_IDS, true)) {
            // اول از همه به تلگرام جواب بده تا دکمه هیچ‌وقت روی «در حال بارگذاری» گیر نکند،
            // حتی اگر مرحله‌ی بعد (ثبت در دیتابیس/ارسال پیام) با خطا مواجه شود.
            if ($cb['id'] ?? null) $tg('answerCallbackQuery', ['callback_query_id' => $cb['id']]);

            try {
                $targetUser = (int)substr($data, 9);
                $name = $userName($targetUser);
                $promptId = $tgSendText($cbChatId, "✍️ در حال پاسخ به: <b>$name</b>\n\nپاسخ خود را «ریپلای» کنید:", ['force_reply' => true, 'selective' => true], $cbMsgId);
                if ($promptId) {
                    $st = $conn->prepare("INSERT INTO admin_reply_prompts (user_id, prompt_message_id) VALUES (?, ?)");
                    if ($st) {
                        $st->bind_param("ii", $targetUser, $promptId);
                        $st->execute();
                    } else {
                        error_log('avapay_support_bridge: prepare(admin_reply_prompts insert) failed: ' . $conn->error);
                    }
                } else {
                    // اگر پیام راهنما ارسال نشد (مثلاً خطای شبکه/توکن)، به ادمین اطلاع بده
                    $tgSendText($cbChatId, '⚠️ خطا در ارسال درخواست پاسخ. لطفاً دوباره تلاش کنید یا مستقیماً روی پیام کاربر ریپلای بزنید.');
                }
            } catch (\Throwable $e) {
                error_log('avapay_support_bridge callback error: ' . $e->getMessage());
            }
            return true; // پردازش شد
        }
        return false; // callback مربوط به پشتیبانی نبود؛ بگذار ربات ادامه دهد
    }

    // ==================== پیام ادمین (پاسخ) ====================
    if (isset($update['message'])) {
        $msg = $update['message'];
        $fromId = (int)($msg['from']['id'] ?? 0);
        $text = $msg['text'] ?? '';
        $caption = $msg['caption'] ?? '';
        $replyTo = $msg['reply_to_message']['message_id'] ?? null;

        if (!in_array($fromId, $ADMIN_IDS, true)) return false; // فقط ادمین‌ها

        try {
            $targetUser = 0;
            if ($replyTo) {
                $st = $conn->prepare("SELECT user_id FROM admin_reply_prompts WHERE prompt_message_id = ? ORDER BY id DESC LIMIT 1");
                if ($st) {
                    $st->bind_param("i", $replyTo); $st->execute();
                    $row = $st->get_result()->fetch_assoc();
                } else { $row = null; error_log('avapay_support_bridge: prepare(admin_reply_prompts select) failed: ' . $conn->error); }
                if ($row) {
                    $targetUser = (int)$row['user_id'];
                    $conn->query("DELETE FROM admin_reply_prompts WHERE prompt_message_id = " . (int)$replyTo);
                } else {
                    $st2 = $conn->prepare("SELECT user_id FROM chat_messages WHERE telegram_message_id = ? AND sender = 'user' LIMIT 1");
                    if ($st2) {
                        $st2->bind_param("i", $replyTo); $st2->execute();
                        $r2 = $st2->get_result()->fetch_assoc();
                        if ($r2) $targetUser = (int)$r2['user_id'];
                    }
                }
            }
            // اگر ریپلای نبود ولی متن با /pm<userId> شروع شد (روش دستی)
            if (!$targetUser && preg_match('/^\/pm(\d+)\s+(.*)/s', $text, $m)) {
                $targetUser = (int)$m[1];
                $text = $m[2];
            }

            if (!$targetUser) return false; // پیام ادمین مربوط به پشتیبانی نبود

            // استخراج فایل در صورت وجود
            $filePath = $fileName = null; $fileSize = null; $messageText = $text;
            if (isset($msg['document'])) {
                $filePath = $tgDownload($msg['document']['file_id']);
                $fileName = $msg['document']['file_name'] ?? 'file';
                $fileSize = $msg['document']['file_size'] ?? null;
                $messageText = $caption;
            } elseif (isset($msg['photo'])) {
                $ph = end($msg['photo']);
                $filePath = $tgDownload($ph['file_id']);
                $fileName = 'photo.jpg';
                $fileSize = $ph['file_size'] ?? null;
                $messageText = $caption;
            }

            // ذخیره در chat_messages (کاربر در اپ می‌بیند)
            $st = $conn->prepare("INSERT INTO chat_messages (user_id, sender, message, file_path, file_name, file_size, created_at) VALUES (?, 'admin', ?, ?, ?, ?, NOW())");
            if ($st) {
                $st->bind_param("isssi", $targetUser, $messageText, $filePath, $fileName, $fileSize);
                $st->execute();
            } else {
                error_log('avapay_support_bridge: prepare(chat_messages insert) failed: ' . $conn->error);
            }

            // ارسال به تلگرام کاربر (اگر آیدی دارد)
            $ures = $conn->query("SELECT telegram_id FROM users WHERE id = $targetUser LIMIT 1");
            $u = $ures ? $ures->fetch_assoc() : null;
            if ($u && !empty($u['telegram_id'])) {
                $notify = "💬 <b>پاسخ پشتیبانی</b>\n\n" . ($messageText ? $messageText . "\n" : '');
                if ($filePath) { $tgSendFile($u['telegram_id'], __DIR__ . '/../' . $filePath, $notify); }
                else { $tgSendText($u['telegram_id'], $notify); }
            }

            // تأیید به ادمین
            $tgSendText($msg['chat']['id'], "✅ پاسخ شما برای کاربر ارسال شد.");
        } catch (\Throwable $e) {
            error_log('avapay_support_bridge message error: ' . $e->getMessage());
            try { $tgSendText($msg['chat']['id'] ?? null, '⚠️ خطایی در ارسال پاسخ رخ داد. لطفاً دوباره تلاش کنید.'); } catch (\Throwable $e2) {}
        }
        return true; // پردازش شد
    }

    return false;
}

}
