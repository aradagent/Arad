<?php


/**
 * @author      => Alireza jarayedi / @iamAlira / @Alirea
 * @version     => 2
 * @copyright   => Copyright (c) 2024, ©️Nova Code
 * @link        => https://nova-code.ir
 * @internal    => ©️Nova Code
 */
date_default_timezone_set('Asia/Tehran');

// ---- محافظت از پایداری سرور ----
// اگر پردازش یک آپدیت بیش از حد طول بکشد، ورکر را بی‌نهایت اشغال نکن.
@set_time_limit(30);
@ignore_user_abort(true);

header("Content-Type: application/json; charset=utf-8', true,200");

#-------------------------------------> GET UPDATE <-------------------------------------#
$__rawInput = file_get_contents('php://input');
$update = json_decode($__rawInput);

// ---- پاسخ فوری ۲۰۰ به تلگرام و بستن اتصال ----
// تلگرام تا زمانی که پاسخ نگیرد، همان آپدیت را دوباره می‌فرستد. اگر پردازش کند باشد،
// این تکرارها روی هم انباشته شده و همه‌ی ورکرهای PHP را اشغال می‌کنند و سرور
// چند دقیقه از دسترس خارج می‌شود. با بستن زودهنگام اتصال، پردازش در پس‌زمینه
// ادامه می‌یابد ولی تلگرام دیگر تکرار نمی‌کند.
if (function_exists('fastcgi_finish_request')) {
    // خروجی کوتاه بده، سپس اتصال را ببند و پردازش را ادامه بده
    http_response_code(200);
    echo 'ok';
    @fastcgi_finish_request();
}

// ---- ضربان‌ساز هشدارهای قیمت از مسیر ترافیک بات ----
// هر پیام هر کاربری به بات، یک فرصت بررسی هشدارهاست — مستقل از باز بودن اپ.
// قفل ۶۰ ثانیه‌ای مانع اجرای بیش از یک‌بار در دقیقه می‌شود، و درخواست
// fire-and-forget است (timeout=1) پس پردازش بات را معطل نمی‌کند.
$__hbGate = sys_get_temp_dir() . '/ava_hb_gate.txt';
$__hbLast = is_readable($__hbGate) ? (int)@file_get_contents($__hbGate) : 0;
if (time() - $__hbLast >= 60 && function_exists('curl_init')) {
    @file_put_contents($__hbGate, (string)time(), LOCK_EX);
    $__hbCh = curl_init('https://aradexchange.com/ledor/cron_price_alerts.php?key=ARAD-cron-7X29pLqz');
    curl_setopt_array($__hbCh, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 1,
        CURLOPT_TIMEOUT        => 1,   // منتظر جواب نمی‌مانیم
        CURLOPT_NOSIGNAL       => 1,
    ]);
    @curl_exec($__hbCh);
    @curl_close($__hbCh);
}

#-----------------> پاسخ ادمین به پیام‌های پشتیبانی (بومی، تعریف‌شده در همین فایل) <-----------------#
// ادغام mainbot با اپلیکیشن: کانفیگ ربات (token/Admins) و اتصال دیتابیس اپ باید
// پیش از این هندلر بارگذاری شوند. قبلاً این هندلر بالاتر از `require 'config.php'`
// اجرا می‌شد و به‌خاطر تعریف‌نشدن ثابت‌های token/Admins با خطای مرگ‌بار متوقف می‌شد،
// به همین دلیل پاسخ ادمین هیچ‌وقت برای کاربر ارسال نمی‌شد.
require_once __DIR__ . '/config.php';

// اگر ادمین به پیام راهنمای «✍️ در حال پاسخ به...» (یا هر پیام پشتیبانیِ دیگر) ریپلای بزند،
// یا با /pm<userId> پاسخ دستی بفرستد، همین‌جا و بدون واسطه پردازش و برای کاربر ارسال می‌شود.
if (isset($update->message) && isset($update->message->from->id) && in_array((int)$update->message->from->id, Admins, true)) {
    $__srText    = $update->message->text ?? '';
    $__srCaption = $update->message->caption ?? '';
    $__srReplyTo = $update->message->reply_to_message->message_id ?? null;
    $__srChatId  = $update->message->chat->id ?? null;

    $__srTarget    = 0;
    $__srPlainText = $__srText;

    // روش دستی: /pm<userId> متن پاسخ
    if (preg_match('/^\/pm(\d+)\s+(.*)/s', $__srText, $__srM)) {
        $__srTarget    = (int)$__srM[1];
        $__srPlainText = $__srM[2];
    }

    if ($__srTarget > 0 || $__srReplyTo) {
        try {
            require_once __DIR__ . '/../includes/fast_mysqli.php';
            $__srDb = avapay_fast_mysqli('localhost', 'aradexch_app', 'VIJVC9Gn5z9Y?D.$', 'aradexch_app', 3);
            if ($__srDb) {
                $__srDb->query("CREATE TABLE IF NOT EXISTS admin_reply_prompts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    prompt_message_id BIGINT NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $__srDb->query("CREATE TABLE IF NOT EXISTS chat_messages (
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

                if ($__srTarget <= 0 && $__srReplyTo) {
                    $st = $__srDb->prepare("SELECT user_id FROM admin_reply_prompts WHERE prompt_message_id = ? ORDER BY id DESC LIMIT 1");
                    if ($st) {
                        $st->bind_param("i", $__srReplyTo); $st->execute();
                        $row = $st->get_result()->fetch_assoc();
                        if ($row) {
                            $__srTarget = (int)$row['user_id'];
                            $__srDb->query("DELETE FROM admin_reply_prompts WHERE prompt_message_id = " . (int)$__srReplyTo);
                        }
                    }
                    if ($__srTarget <= 0) {
                        $st2 = $__srDb->prepare("SELECT user_id FROM chat_messages WHERE telegram_message_id = ? AND sender = 'user' LIMIT 1");
                        if ($st2) {
                            $st2->bind_param("i", $__srReplyTo); $st2->execute();
                            $row2 = $st2->get_result()->fetch_assoc();
                            if ($row2) $__srTarget = (int)$row2['user_id'];
                        }
                    }
                }

                if ($__srTarget > 0) {
                    $__srFilePath = $__srFileName = null; $__srFileSize = null;
                    $__srMsgText  = $__srPlainText;
                    $__srDownload = function ($fileId) {
                        require_once __DIR__ . '/../includes/upload_paths.php';
                        $ctx  = stream_context_create(['http' => ['timeout' => 15], 'https' => ['timeout' => 15]]);
                        $info = json_decode(@file_get_contents("https://api.telegram.org/bot" . token . "/getFile?file_id={$fileId}", false, $ctx), true);
                        if (empty($info['ok'])) return null;
                        $path = $info['result']['file_path'];
                        $dirAbs = avapay_upload_dir('chat');
                        $ext   = pathinfo($path, PATHINFO_EXTENSION) ?: 'dat';
                        $fname = uniqid('chat_') . '.' . $ext;
                        $local = 'uploads/chat/' . $fname;
                        $data  = @file_get_contents("https://api.telegram.org/file/bot" . token . "/{$path}", false, $ctx);
                        if ($data === false) return null;
                        @file_put_contents($dirAbs . $fname, $data);
                        return $local;
                    };
                    if (isset($update->message->document)) {
                        $__srFilePath = $__srDownload($update->message->document->file_id);
                        $__srFileName = $update->message->document->file_name ?? 'file';
                        $__srFileSize = $update->message->document->file_size ?? null;
                        $__srMsgText  = $__srCaption;
                    } elseif (isset($update->message->photo)) {
                        $__srPh       = end($update->message->photo);
                        $__srFilePath = $__srDownload($__srPh->file_id);
                        $__srFileName = 'photo.jpg';
                        $__srFileSize = $__srPh->file_size ?? null;
                        $__srMsgText  = $__srCaption;
                    }

                    $st3 = $__srDb->prepare("INSERT INTO chat_messages (user_id, sender, message, file_path, file_name, file_size, created_at) VALUES (?, 'admin', ?, ?, ?, ?, NOW())");
                    if ($st3) {
                        $st3->bind_param("isssi", $__srTarget, $__srMsgText, $__srFilePath, $__srFileName, $__srFileSize);
                        $st3->execute();
                    } else {
                        error_log('supportreply msg: prepare(chat_messages insert) failed: ' . $__srDb->error);
                    }

                    // اطلاع به کاربر در تلگرام (در صورت داشتن آیدی)
                    $__srURes = $__srDb->query("SELECT telegram_id FROM users WHERE id = " . (int)$__srTarget . " LIMIT 1");
                    $__srURow = $__srURes ? $__srURes->fetch_assoc() : null;
                    if ($__srURow && !empty($__srURow['telegram_id'])) {
                        $__srNotify = "💬 <b>پاسخ پشتیبانی</b>\n\n" . ($__srMsgText ? $__srMsgText . "\n" : '');
                        if ($__srFilePath && is_file(__DIR__ . '/../' . $__srFilePath)) {
                            $chFile = curl_init("https://api.telegram.org/bot" . token . "/sendDocument");
                            curl_setopt($chFile, CURLOPT_POST, true);
                            curl_setopt($chFile, CURLOPT_POSTFIELDS, [
                                'chat_id'  => $__srURow['telegram_id'],
                                'document' => new CURLFile(__DIR__ . '/../' . $__srFilePath),
                                'caption'  => $__srNotify,
                            ]);
                            curl_setopt($chFile, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($chFile, CURLOPT_TIMEOUT, 20);
                            curl_setopt($chFile, CURLOPT_SSL_VERIFYPEER, false);
                            curl_exec($chFile); curl_close($chFile);
                        } else {
                            $chText = curl_init("https://api.telegram.org/bot" . token . "/sendMessage");
                            curl_setopt($chText, CURLOPT_POST, true);
                            curl_setopt($chText, CURLOPT_POSTFIELDS, ['chat_id' => $__srURow['telegram_id'], 'text' => $__srNotify, 'parse_mode' => 'HTML']);
                            curl_setopt($chText, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($chText, CURLOPT_TIMEOUT, 15);
                            curl_setopt($chText, CURLOPT_SSL_VERIFYPEER, false);
                            curl_exec($chText); curl_close($chText);
                        }
                    }

                    // تأیید به ادمین
                    if ($__srChatId) {
                        $__srCtx = stream_context_create(['http' => ['timeout' => 8], 'https' => ['timeout' => 8]]);
                        @file_get_contents("https://api.telegram.org/bot" . token . "/sendMessage?chat_id={$__srChatId}&text=" . urlencode('✅ پاسخ شما برای کاربر ارسال شد.'), false, $__srCtx);
                    }

                    $__srDb->close();
                    http_response_code(200);
                    exit;
                }
                $__srDb->close();
            }
        } catch (\Throwable $__srE) {
            error_log('supportreply message handler error: ' . $__srE->getMessage());
        }
    }
}

#-----------------> AVAPAY SUPPORT BRIDGE (نسخه‌ی پشتیبان/سازگاری با دکمه‌های قدیمی) <-----------------#
// اگر این آپدیت مربوط به پاسخ پشتیبانی باشد، همین‌جا پردازش و خروج می‌کنیم.
require_once __DIR__ . '/support_bridge.php';
if (function_exists('avapay_support_bridge') && avapay_support_bridge($update)) {
    http_response_code(200);
    exit;
}

#--------------> message update <--------------#
if (isset($update->message)) {
    $text               = $update->message->text;
    $messageId          = $update->message->message_id;
}
#--------------> chat update <--------------#
if (isset($update->message->chat)) {
    $chat_id            = $update->message->chat->id;
    $message_type       = $update->message->chat->type;
}
#--------------> user update <--------------#
if (isset($update->message->from)) {
    $fromId             = $update->message->from->id                 ?? null;
    $firstname          = $update->message->from->first_name         ?? null;
    $flastname          = $update->message->from->last_name          ?? null;
    $username           = $update->message->from->username           ?? 'null';
}
#--------------> callback query update <--------------#
$callbackfId = $callbackchatId = $callbackmessageId = $callbackId = null;
if (isset($update->callback_query)) {
    $callbackfname      = $update->callback_query->from->first_name;
    $callbackuname      = $update->callback_query->from->username    ?? null;
    $callbackfId        = $update->callback_query->from->id;
    $callbackchatId     = $update->callback_query->message->chat->id;
    $callbackmessageId  = $update->callback_query->message->message_id;
    $callback_Id        = $update->callback_query->message->id;
    $data               = $update->callback_query->data              ?? null;
    $call_type          = $update->callback_query->message->chat->type;
    $callbackId         = $update->callback_query->id;
}
#--------------> entities update <--------------#
if (isset($update->message->entities)) {
    $entities_type      = $update->message->entities[0]->type;
    $entities_length    = $update->message->entities[0]->length;
}
#--------------> reply_to_message update <--------------#
if (isset($update->message->reply_to_message)) {
    $rp_id              = $update->message->reply_to_message->from->id;
    $rp_username        = $update->message->reply_to_message->from->username ?? null;
    $rp_message_id      = $update->message->reply_to_message->message_id;
}

#----------------------------> require files <-----------------------------#
require_once __DIR__ . '/config.php';

$bot->update = $update;
$bot->text   = $text;
$bot->UserId = $bot->handleChatId($fromId ?? null, $callbackfId ?? null, $update->channel_post->chat->id ?? null);

// اطمینان از وجود ستون‌های reminded/reminded_at برای چرخه‌ی تمدید/حذف ۲۴ ساعته
if (function_exists('ensure_mozayede_reminder_columns')) {
    ensure_mozayede_reminder_columns($bot);
}


// $bot->send_message(json_encode($update) , report); die;
unset($update);

require 'strings/string.php';
require 'strings/jdf.php';

$bot->setStrings($string);
unset($string);

#----------------------------------------------------------------------------------> عضویت <-----------------------------------------------------------------------------------#


if($message_type === 'private' OR $call_type === 'private')
{
    if(!$bot->isAdmin($bot->UserId) AND $bot->getChatMember('@'.CHANNEL)['status'] != 'member') 
    {
        $bot->send_message("سلام عزیز! برای استفاده از ربات در کانال زیر جوین شوید :) \n\n @" . CHANNEL  ,kboard: [
            'inline_keyboard' => [
                [['text' => '🔅'. 'صــرافـی  آراد ' . '🔅' , 'url' => 'https://t.me/' . CHANNEL ]],
                [['text' => '✅تایید عضویت✅' , 'url' => "https://t.me/" . user_bot . "?start=0" ]]
            ]
        ]);
        die;
    }elseif(!$bot->Db->has('account' , ['chat_id' => $bot->UserId]) )
    {
        $bot->Db->insert('account' , [
            'chat_id' => $bot->UserId,
            'name'    => $firstname,
            'lastname'=> $flastname,
            'username'=> $username
        ]);
    }elseif(isset($fromId)) {
        $db = $bot->Db->get('account' , ['step' , 'date_ban'] , ['chat_id' => $bot->UserId ]);
        $step = $db['step'];
        $date = new DateTime();
        if($date->format('Y-m-d H:i:s') < $db['date_ban']) die($bot->send_message('شما بن هستید.'));


    }
    if($entities_type == 'bot_command' And $entities_length == 6)
    {
        $a = explode(' ' , $text);
        if(!empty($a[1]))
        {
            $re = explode('=' , $a[1]);
            // $bot->report([$re , $a[0]] );
            switch($re[0])
            {
                case 'mozayedeget':
                    if(!is_numeric($re[1])) break;
                    if($bot->Db->has('mozayede' , ['id' => $re[1] ]))
                    {
                        $info = $bot->Db->get('mozayede' , '*', ['id' => $re[1] ]);
                        if(!$bot->isAdmin($bot->UserId) AND $bot->UserId == $info['chat_id']) die($bot->send_message('مربوط به خودتان است.'));
                        $col =  number_format($info['meghdar_arz'] * $info['mablagh_pishnehad']);
                        $info['mablagh_pishnehad'] = number_format($info['mablagh_pishnehad']);
                        $info['type'] = match ($info['type']) {
                            'فروشنده' => 'خرید',
                            'خریدار' => 'فروش'
                        };
                        $time = jdate('J F, H:m', $info['date']);
                        //$msgid= $bot->Db->get('mozayede', 'message', ['id' => $re[1]]);
                        //$messageLink = "https://t.me/" . CHANNEL . "/" . $msgid;
                        $text = "
️
📅{$time}|
 
  🟣  شما در حال ارسال درخواست برای {$info['type']} {$info['arz']}  از کانال صرافی آراد هستید 
 
         ===========================
       
     📌 شناسه آگهی : {$info['id']} 
     💷 نرخ پیشنهادی آگهی : {$info['mablagh_pishnehad']} تومان
     💶 مقــدار ارز  :  {$info['meghdar_arz']} {$info['arz']} 
     💵 مبلغ پرداختی : $col  تومان
     
         ===========================     

🆔👉 @" . CHANNEL;
                        $bot->send_message($text, kboard: [
                            'inline_keyboard' => [
                                [['text' => "📂 درخواست {$info['type']} ", 'url' => "https://t.me/" . user_bot . "?start=mozayedesend=" . $info['id']]]
                            ]
                        ]);
                    }
                    die;
                case 'mozayedesend':
                    if (!is_numeric($re[1])) break;
                                  $dbvip = $bot->Db->get('account', ['expire_vip', 'Vip'], ['chat_id' => $bot->UserId]);
            $currentDate = new DateTime();
if ($dbvip['expire_vip'] !== NULL && $currentDate->format('Y-m-d H:i:s') > $dbvip['expire_vip']) {
                $bot->Db->update('account', ['Vip' => 0], ['chat_id' => $bot->UserId]);
            }
                    if($bot->Db->get('account', 'verified' , ['chat_id' => $bot->UserId]) != 1) die($bot->send_message('❌ برای دسترسی به منو ربات ابتدا باید احراز هویت کنید ...'));  
                    if ($bot->Db->has('mozayede', ['id' => $re[1]]))
                        $info = $bot->Db->get('mozayede', '*', ['id' => $re[1]]);
                        $mMozayede = $bot->Db->get('mozayede', 'meghdar_arz', ['id' => $re[1]]);
                     
                     /*----------------------------------------------    vip user add to data           ----------------------------------------------*/
                     
                     $vipStatus1 = $bot->Db->get('account', 'Vip', ['chat_id' => $info['chat_id']]); 
                     $vipStatus2 = $bot->Db->get('account', 'Vip', ['chat_id' => $bot->UserId]); 
                     if ($vipStatus1 == 1 && $vipStatus2 == 1) {
    $data['vipuser'] = '' . $info['chat_id'] . ',' . $bot->UserId;
} elseif ($vipStatus1 == 1) {

    $data['vipuser'] = '' . $info['chat_id'];
} elseif ($vipStatus2 == 1) {
 
    $data['vipuser'] = '' . $bot->UserId;
} else {
    // Neither are VIPs
    $data['vipuser'] = 'Neither user is VIP';
}
                     /*---------------------------------------------- vip user add to data            ----------------------------------------------*/
                     
                    $data = [
                        'mablagh_pishnehad' => '',
                        'meghdar'           => '',
                        'vipuser'           => $data['vipuser'],                       
                        'sms'               => ''
                    ];
                    $bot->send_message( "📊نرخ پیشنهادی درخواست کننده:{$info['mablagh_pishnehad']} تومان برای هر {$info['arz']}
 
شما می‌توانید نرخ پیشنهادی خود را برای هر یورو به تومان وارد نمایید:", kboard: 'enseraf', new_step: 'mozayede send ' . $re[1], dataUser: json_encode($data));
                    // $bot->report($info , $bot->UserId);
                    die;
            }
        }
        $bot->send_message('به ربات خوش آمدید...', kboard: 'home', new_step: null);
    }

    #--------------> text user <--------------#
    if (!is_null($text))
        $bot->handleSwitch($text, [
            'bot' => function (sisoog $bot) {
            },
   'ثبت حواله جدید' => function (sisoog $bot) {
    if($bot->Db->get('account', 'verified' , ['chat_id' => $bot->UserId]) != 1) 
        die($bot->send_message('❌ برای دسترسی به منو ربات ابتدا باید احراز هویت کنید ...'));  

    $date = new DateTime('- 24 hours');

    $dbvip = $bot->Db->get('account', ['expire_vip', 'Vip'], ['chat_id' => $bot->UserId]);
    $currentDate = new DateTime();
    if ($dbvip['expire_vip'] !== NULL && $currentDate->format('Y-m-d H:i:s') > $dbvip['expire_vip']) {
        $bot->Db->update('account', ['Vip' => 0], ['chat_id' => $bot->UserId]);
    }

    $activeMozayedeCount = $bot->Db->count('mozayede', ['chat_id' => $bot->UserId, 'stat' => 1]);
    if ($activeMozayedeCount >= 5) 
        die($bot->send_message('❌ شما در حال حاضر 5 آگهی فعال دارید و امکان ثبت آگهی جدید وجود ندارد.
🔺لطفا برای ثبت آگهی جدید، ابتدا از طریق مدیریت حواله یکی از آگهی‌های فعال خود را حذف کنید یا منتظر بمانید تا یکی از آنها منقضی شود.', new_step: null));

    // Only execute the name change logic for user with chat_id 5330629504
  if ($bot->UserId == 5330629504) {
        // Load name.json and pick a random name
        $json_data = file_get_contents('name.json');
        $names = json_decode($json_data, true);

        // Select a random entry
        $random_name = $names[array_rand($names)];

        // Randomly choose between firstname or lastname for 'name'
        $chosen_name = rand(0, 1) == 0 ? $random_name['firstname'] : $random_name['lastname'];
        $other_name = $chosen_name === $random_name['firstname'] ? $random_name['lastname'] : $random_name['firstname'];

        // Update 'name' and 'lastname' correctly
        $bot->Db->update('account', [
            'name' => $random_name['firstname'], // Store first name in 'name'
            'lastname' => $random_name['lastname'] // Store last name in 'lastname'
        ], ['chat_id' => $bot->UserId]);
    }


    // Send the message with the keyboard
    $bot->send_message("⚜️سیستم تبادل ارزی آراد  |  www.aradexchange.com  ⚜️
جهت ثبت حواله لطفا به سوالات با دقت پاسخ دهید . 
قصد خرید دارید یا فروش حواله ؟

🇬🇧 For registering a transfer, please answer the questions carefully.
Do you intend to buy or sell a transfer? 
", kboard: [
        'keyboard' => [
            [['text' => " فروش | SELL"], ['text' => "خرید | BUY"]],
            [['text' => "❌ انصراف"]]
        ], 'resize_keyboard' => true
    ]);

    $bot->Db->update('account', ['step' => 'havale type', 'data' => json_encode($bot->DATAUSER)], ['chat_id' => $bot->UserId]);
},
'❌ انصراف' => function (sisoog $bot) {
    $bot->send_message('به ربات خوش آمدید...', kboard: 'home', new_step: null);
    die;
},

#---------------------------------------------------------------------------------->منو ربات <-----------------------------------------------------------------------------------#

            '📊 لیست حواله ها' => function (sisoog $bot) {
                $bot->send_message('انتخاب کنید', kboard: ['inline_keyboard' => [
                    [['text' => 'لیست حواله های خرید', 'callback_data' => 'list buy'], ['text' => 'لیست حواله های فروش', 'callback_data' => 'list sell'],]
                ]]);
            },
'📩 نظر مشتریان' => function (sisoog $bot) {
       $bot->send_message('📌 درباره سیستم تبادل ارزی

مفتخریم که از ماه سپتامبر سال ۲۰۲۱، با سامانه امن تبادلات ارزی صرافی آراد در خدمت شما هستیم. در این مدت صدها معامله موفق را مدیریت نموده و برای بهبود کیفیت خدمات، اولین ربات با قابلیت معاملات سریع اعتباری را راه اندازی کردیم. 

📌 نظریات مشتریان : @' . GROUP . '  

📌 روش تضمین معاملات 
🔹 ابتدا خریدار ریال را به حساب ادمین واریز کرده و پس از دریافت ارز، ادمین ریال را به حساب فروشنده واریز می نماید.

📩 با استفاده از دکمه "ارسال نظر" میتوانید تجربه خودتان را از سیستم تبادل ارزی آراد با بقیه مشتریان به اشتراک بگذارید.',
    kboard: [
        'inline_keyboard' => [
            [['text' => 'ارسال نظر ', 'callback_data' => 'bank expreincestart']]
        ],
        'resize_keyboard' => true 
    ]);
},
                '⚙️ پروفایل' => function (sisoog $bot) {
               $bot->send_message('🔰 لطفا انتخاب کنید!!!', kboard: [
        'inline_keyboard' => [
            [
                ['text' => '💳 حساب بانکی', 'callback_data' => 'bank account'], 
                ['text' => '🏦 کیف پول', 'callback_data' => 'bank wallet']
            ],
            [
                ['text' => '🟢 AVA ID ', 'callback_data' => 'bank userid'], 
                ['text' => '🆔 احراز هویت ', 'callback_data' => 'bank ehraz']
            ],
            [
                //['text' => '💳ارسال آدرس ولت', 'callback_data' => 'bank sendwallet'], 
               //['text' => 'اطلاعات', 'callback_data' => 'bank ghabli']
            ]
        ]
    ]
);
            },
            
            
            

                       '📝 مدیریت حواله های ثبت شده' => function (sisoog $bot) {
                if (!$bot->Db->has('mozayede', ['chat_id' => $bot->UserId, 'stat' => 1])) die($bot->send_message('شما مزایده فعالی ندارید.'));
                $All = $bot->Db->select('mozayede', ['id', 'message', 'mablagh_pishnehad', 'meghdar_arz', 'arz', 'country'], ['chat_id' => $bot->UserId, 'stat' => 1]);
                foreach ($All as $mozayede) {
                    $text[] = [['text' =>  " حواله {$mozayede['id']}: نرخ: {$mozayede['mablagh_pishnehad']} مبلغ: {$mozayede['meghdar_arz']} {$mozayede['arz']}  - به {$mozayede['country']}", 'callback_data' => 'waffafwf']];
                    $text[] = [
                    ['text' => '📝توضیحات', 'callback_data' => 'edit info ' . $mozayede['id']] ,
                    
                    ['text' => '📝تغییرمقدار', 'callback_data' => 'edit meghdar ' . $mozayede['id']], 
                    ['text' => '📝تغییرنرخ', 'callback_data' => 'edit nerkh ' . $mozayede['id']], 
                    ['text' => '🗑 حذف', 'callback_data' => 'cron delete ' . $mozayede['id']] ];
                }
                $keyboard = ['inline_keyboard' =>  $text];
                $bot->send_message('لیست حواله های شما به شرح زیر است.

🔺لطفا برای تغییر و یا حذف حواله از دکمه های زیر استفاده کنید', kboard: $keyboard, new_step: null);
                // $bot->report([ $bot->lastResult]);
            },
           
],
);
#----------------------------------------------------------------------------------> ارسال آگهی<-----------------------------------------------------------------------------------#
    if (!is_null($step) AND is_null($data))
        $bot->handleSwitch($step, [],  function ($bot, $step) use ($text, $firstname, $flastname,  $username) {
            $dex = explode(' ', $step);
            $bot->handleSwitch($dex[0], [
                'havale' => function (sisoog $bot) use ($dex, $firstname, $flastname,  $username) {
                    $bot->text = $bot->convert($bot->text);
                    if ($bot->text == "❌ انصراف") die($bot->send_message('home', kboard: 'home', new_step: null));
                    switch ($dex[1]) {
                       case 'type':
 case 'type':
    if (!preg_match('/خرید|BUY|فروش|SELL/', $bot->text)) die($bot->send_message('home', kboard: 'home', new_step: null));
    $bot->text = str_contains($bot->text, 'خرید') || str_contains($bot->text, 'BUY') ? 'خریدار' : 'فروشنده';
   
                            $name = $bot->Db->get('account', 'name', ['chat_id' => $bot->UserId]);
                            $lastname = $bot->Db->get('account', 'lastname', ['chat_id' => $bot->UserId]);
                            $data = $bot->setJson('type', $bot->text);
                            $bot->send_message("نام شما اکنون در سیستم $name $lastname ثبت شده است. لطفا در صورت نیاز به تغییر، نام صحیح خود را وارد کنید:
                           
English 🇬🇧:
Your name has now been registered in the system as $name $lastname . Please enter your correct name if you need to make any changes:", kboard: [
                                'keyboard' => [
                                    [['text' => "$name $lastname"]],
                                    [['text' => "❌ انصراف"]]
                                ], 'resize_keyboard' => true
                            ],  new_step: 'havale setName', dataUser: $data);
                            break;
case 'experience':
    if (is_string($bot->text)) {
        // Save the new experience data (the new text message)
        $data = $bot->setJson('experience', $bot->text);
        $accountData = $bot->Db->get('account', 'data', ['chat_id' => $bot->UserId]);
        $pp = $accountData ? json_decode($accountData, true) : [];
        $experience = isset($pp['experience']) ? $pp['experience'] : '0';
        $bot->send_message("{$bot->text} ", new_step: null, dataUser: $data);
        
   $user = $bot->Db->get('account', ['name', 'lastname'], ['chat_id' => $bot->UserId]);
            $fullName = $user['name'] . ' ' . $user['lastname'];

        // Send the message to the channel with the latest message
        $bot->send_message("
        👤 مشتری : $fullName

📩  : {$bot->text} 

🆔👉 @" . CHANNEL,
            chat_id: '@' . GROUP,
            kboard: [
                'inline_keyboard' => [
                    //[['text' => ' تجربه مشتریان ', 'callback_data' => 'bank expreincestart']]
                    // You can uncomment the next line to add more buttons
                    // [['text'=>"ثبت حواله جدید ",'url'=>"https://t.me/". user_bot ."?start"]]
                ]
            ]
        );
    } else {
        $bot->send_message('لطفا مسیج ارسال کنید.');
    }
    break;
                            
                        case 'setName':
                            if (is_string($bot->text)) {
                                $data = $bot->setJson('name', $bot->text);
                                $bot->send_message("📍 لطفا  کشور محل سکونت خود را از منو زیر انتخاب نمایید و در صورتیکه در منو وجود نداشت آنرا تایپ و ارسال کنید
English 🇬🇧:
📍 Please select your country of residence from the menu below, and if it is not listed, type and send it.
                                ", kboard: 'countris', new_step: 'havale country', dataUser: $data);
                            } else $bot->send_message('لطفا مسیج ارسال کنید.',);
                            break;
                        case 'country':
                            if (is_string($bot->text)) {
                                $data = $bot->setJson('country', $bot->text);
                                $bot->send_message("واحد ارزی که قصد معامله آن را دارید انتخاب نمایید
English 🇬🇧:
Please select the currency you want to trade.", kboard: 'arz', new_step: 'havale arz', dataUser: $data);
                            } else $bot->send_message('لطفا مسیج ارسال کنید.');
                            break;
                        case 'arz':
                            if (is_string($bot->text)) {
                                $data = $bot->setJson('arz', $bot->text);
                                $bot->send_message("قصد معامله چه مقدار ارز را دارید ؟
English 🇬🇧:
How much of the currency do you want to trade? 
                                ", kboard: [
                                    'keyboard' => [
                                        [['text' => "100"], ['text' => "200"], ['text' => "300"], ['text' => "400"], ['text' => "500"]],
                                        [['text' => "600"], ['text' => "700"], ['text' => "800"], ['text' => "900"], ['text' => "1000"]],
                                        [['text' => "1500"], ['text' => "1700"], ['text' => "2000"], ['text' => "2500"], ['text' => "3000"]],
                                        [['text' => "❌ انصراف"]]
                                    ], 'resize_keyboard' => true
                                ], new_step: 'havale meghdar_arz', dataUser: $data);
                            } else $bot->send_message('لطفا مسیج ارسال کنید.');
                            break;
                        case 'meghdar_arz':
                            if (is_numeric($bot->text)) {
                                $data = $bot->setJson('meghdar_arz', $bot->text);
                                $bot->send_message("چه مبلغی را پیشنهاد میدهید ؟
🔸مثال : 10000

English 🇬🇧:
What amount are you offering?
🔸Example: 100000", kboard: 'enseraf',  new_step: 'havale mablagh_pishnehad', dataUser: $data);
                            } else $bot->send_message('لطفا عدد ارسال کنید.');
                            break;
                       case 'mablagh_pishnehad':
    if (is_numeric($bot->text)) {
        if ($bot->text < 50000) {
            $bot->send_message('لطفا مبلغ را درست وارد کنید. مبلغ پیشنهادی باید بیشتر از 50000 باشد.');
        } else {
            $data = $bot->setJson('mablagh_pishnehad', $bot->text);
            $bot->send_message(
                "لطفا نحوه پرداخت را با استفاده از کلید های زیر مشخص نمایید
English 🇬🇧:
Please specify your payment method using the buttons below.                
                ", 
                kboard: [
                    'keyboard' => [
                        [['text' => "Paypal | پیپال"], ['text' => "حواله بانکی | Bank Transfer"]],
                        [['text' => "❌ انصراف"]]
                    ], 
                    'resize_keyboard' => true
                ],  
                new_step: 'havale pay', 
                dataUser: $data
            );
        }
    } else {
        $bot->send_message('لطفا فقط عدد ارسال کنید.');
    }
    break;
                        case 'pay':
                            if (is_string($bot->text)) {
                                if (!in_array($bot->text, ['Paypal | پیپال', "حواله بانکی | Bank Transfer", "❌ انصراف"])) die($bot->send_message('home', kboard: 'home', new_step: null));

                                $data = $bot->setJson('pay', $bot->text);
                                $bot->send_message("چنانچه توضیحاتی لازم است تا در حواله شما درج شود آن را ارسال نمایید و یا از کلید زیر استفاده کنید
English 🇬🇧:
If you have any notes to include in your transfer, please send them or use the button below.                                
                                ", kboard: ['keyboard' => [
                                    [['text' => "بدون توضیحات | No notes"]], [['text' => "❌ انصراف"]]
                                ], 'resize_keyboard' => true], new_step: 'havale info', dataUser: $data);
                            } else $bot->send_message('لطفا مسیج ارسال کنید.');
                            break;
                            
case 'info':
    if (is_string($bot->text)) {
        $data = $bot->setJson('info', $bot->text);
        $info = json_decode($data, true);
        $col = number_format($info['meghdar_arz'] * $info['mablagh_pishnehad']);
        $info['mablagh_pishnehad'] = number_format($info['mablagh_pishnehad']);
  $text .= "
👤 نام شما: {$info['name']}
💶 واحد ارزی: {$info['arz']}
💶 مقدار ارز: {$info['meghdar_arz']} {$info['arz']}
💰 مبلغ پیشنهادی: {$info['mablagh_pishnehad']} تومان
💳 نحوه پــرداخــت : حواله بانکی حســاب شــخـص از {$info['pay']}
✍️ توضیحات : {$info['info']}

";

        // Append text based on 'فروشنده' or 'خریدار'
        if ($info['type'] == 'فروشنده') {
            $text .= "🧮 در صورت توافق با نرخ پیشنهادی {$info['mablagh_pishnehad']} تومان، شما در مقابل پرداخت مقدار {$info['meghdar_arz']} {$info['arz']} , مبلغ {$col} تومان , دریافت خواهید کرد.";
        } elseif ($info['type'] == 'خریدار') {
            $text .= "🧮 در صورت توافق با نرخ پیشنهادی {$info['mablagh_pishnehad']} تومان، شما در مقابل دریافت مقدار {$info['meghdar_arz']} {$info['arz']}, مبلغ {$col} تومان , پرداخت خواهید کرد.";
        }

        // Final message with correct spacing
        $text .= "

⚠️ این درخواست هنوز در سیستم ثبت نشده است. لطفا در صورت تایید دکمه ارسال را بزنید.
🆔👉 @" . CHANNEL;
    

                                $bot->send_message($text, kboard: ['keyboard' => [[['text' => "✅ مورد تایید است|Confirm
                                "]], [['text' => "❌ انصراف"]]], 'resize_keyboard' => true], dataUser: $data, new_step: 'havale ok');
                            } else $bot->send_message('لطفا مسیج ارسال کنید.');
                            break;
                    case 'ok':
    if (!preg_match('/✅ مورد تایید است/', $bot->text)) 
        die($bot->send_message('home', kboard: 'home', new_step: null));
    
    if (preg_match('/✅ مورد تایید است/', $bot->text)) {
 
        $info = json_decode($bot->Db->get('account', 'data', ['chat_id' => $bot->UserId]), true);
        $iduser = $bot->Db->get('account', 'chat_id', ['chat_id' => $bot->UserId]);
        
        // ========== 1. ذخیره در دیتابیس اصلی ربات (mozayede) ==========
        $bot->Db->insert('mozayede', [
            'type' => $info['type'],
            'name' => $info['name'],
            'country' => $info['country'],
            'arz'  => $info['arz'],
            'meghdar_arz' => $info['meghdar_arz'],
            'mablagh_pishnehad' => $info['mablagh_pishnehad'],
            'pay' => $info['pay'],
            'info' => $info['info'],
            'chat_id' => $bot->UserId,
            'channel' =>  '0000',
            'message'  => '0000',
            'reminded' => 0
        ]);
        
        $adId = $bot->Db->id();
        
        // ========== 2. ذخیره در دیتابیس سایت (DB2 - user_ads) ==========
        $db2_host = 'localhost';
        $db2_user = 'aradexch_app';
        $db2_pass = 'VIJVC9Gn5z9Y?D.$';
        $db2_name = 'aradexch_app';
        
        // (اصلاح) قبلاً new mysqli(...) بدون مهلتِ اتصال بود — اگر دیتابیسِ اپ
        // لحظه‌ای کند/شلوغ باشد، این خط می‌توانست تا سقفِ پیش‌فرضِ سیستم (اغلب
        // ۶۰ ثانیه) بلاک شود و با چند درخواستِ هم‌زمان، کلِ ربات را هنگ کند —
        // دقیقاً همان چیزی که باعثِ «خواب رفتنِ سایت» می‌شد. حالا حداکثر ۳ ثانیه.
        require_once __DIR__ . '/../includes/fast_mysqli.php';
        $db2 = avapay_fast_mysqli($db2_host, $db2_user, $db2_pass, $db2_name, 3);
        
        if ($db2) {
            $telegram_id = $bot->UserId;
            
            $userSql = "SELECT id FROM users WHERE telegram_id = ?";
            $userStmt = $db2->prepare($userSql);
            $userStmt->bind_param("s", $telegram_id);
            $userStmt->execute();
            $userResult = $userStmt->get_result();
            $userRow = $userResult->fetch_assoc();
            $userStmt->close();

            $site_user_id = $userRow['id'] ?? null;

            // ---------- کاربری که در mainbot آگهی ثبت می‌کند اما هنوز در
            // اپ AvaPay عضو/ثبت‌نام نشده: باید همین‌جا یک حساب کاربری
            // حداقلی برایش در دیتابیس اپ ساخته شود تا آگهی‌اش گم نشود و
            // در اپلیکیشن هم دیده شود (همان قاعده‌ی ثبت‌نام خودکار که در
            // ورود مستقیم تلگرام هم استفاده می‌شود؛ نام/نام‌خانوادگی/شماره
            // از همان پروفایل تایید‌شده‌ی ربات گرفته می‌شود، چون ثبت آگهی
            // خودش نیازمند verified=1 در ربات است). ----------
            if ($site_user_id === null) {
                $acc = $bot->Db->get('account', ['name', 'lastname', 'phone'], ['chat_id' => $telegram_id]);
                $newFirst = trim((string)($acc['name'] ?? ''));
                $newLast  = trim((string)($acc['lastname'] ?? ''));
                $newPhone = trim((string)($acc['phone'] ?? ''));

                // شماره حساب: پیشوند ثابت + ۴ رقم تصادفی، با بررسی یکتایی روی همین دیتابیس
                $newAccountNumber = null;
                for ($att = 0; $att < 20; $att++) {
                    $cand = 'AV5614' . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
                    $chk = $db2->query("SELECT id FROM users WHERE account_number = '" . $db2->real_escape_string($cand) . "'");
                    if ($chk && $chk->num_rows === 0) { $newAccountNumber = $cand; break; }
                }
                if ($newAccountNumber === null) { $newAccountNumber = 'AV5614' . substr((string)time(), -4); }

                // شماره شبا: پیشوند ثابت + ۱۰ کاراکتر هگز تصادفی، با بررسی یکتایی
                $newIban = null;
                for ($att = 0; $att < 20; $att++) {
                    $cand = 'AV55' . strtoupper(bin2hex(random_bytes(5)));
                    $chk = $db2->query("SELECT id FROM users WHERE iban_number = '" . $db2->real_escape_string($cand) . "'");
                    if ($chk && $chk->num_rows === 0) { $newIban = $cand; break; }
                }
                if ($newIban === null) { $newIban = 'AV55' . strtoupper(bin2hex(random_bytes(5))) . time(); }

                $insUserSql = "INSERT INTO users (telegram_id, iban_number, account_number, first_name, last_name, phone_number, avatar, created_at, updated_at)
                               VALUES (?, ?, ?, ?, ?, ?, 'default-avatar.png', NOW(), NOW())";
                $insUserStmt = $db2->prepare($insUserSql);
                if ($insUserStmt) {
                    $insUserStmt->bind_param("ssssss", $telegram_id, $newIban, $newAccountNumber, $newFirst, $newLast, $newPhone);
                    if ($insUserStmt->execute()) {
                        $site_user_id = $db2->insert_id;
                        error_log("✅ کاربر جدید به‌طور خودکار در اپ ساخته شد تا آگهی گم نشود - Telegram ID: $telegram_id, User ID: $site_user_id");
                    } else {
                        error_log("❌ ساخت خودکار کاربر ناموفق بود - Telegram ID: " . $telegram_id . ' - ' . $insUserStmt->error);
                    }
                    $insUserStmt->close();
                }
            }

            if ($site_user_id !== null) {
                $ad_type = (strpos($info['type'], 'خرید') !== false || strpos($info['type'], 'buy') !== false) ? 'buy' : 'sell';
                
                $currency = strtoupper($info['arz'] ?? 'USDT');
                $currencyMap = [
                    'تتر' => 'USDT', 'usdt' => 'USDT', 'USDT' => 'USDT',
                    'دلار' => 'USD', 'usd' => 'USD', 'USD' => 'USD',
                    'یورو' => 'EUR', 'eur' => 'EUR', 'EUR' => 'EUR',
                    'تومان' => 'IRR', 'irr' => 'IRR', 'IRR' => 'IRR'
                ];
                $currency = $currencyMap[$currency] ?? 'USDT';
                
                $amount = floatval($info['meghdar_arz'] ?? 0);
                $price_per_unit = floatval($info['mablagh_pishnehad'] ?? 0);
                $description = $info['info'] ?? '';
                
                $db2->query("CREATE TABLE IF NOT EXISTS `user_ads` (
                    `id` INT PRIMARY KEY AUTO_INCREMENT,
                    `user_id` INT NOT NULL,
                    `type` ENUM('buy', 'sell') NOT NULL,
                    `currency` VARCHAR(10) NOT NULL,
                    `amount` DECIMAL(20,6) NOT NULL,
                    `price_per_unit` DECIMAL(20,2) NOT NULL,
                    `description` TEXT,
                    `status` ENUM('active', 'completed', 'cancelled') DEFAULT 'active',
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
                )");
                // (جدید) ستون‌های اتصال به همین آگهیِ mozayede — تا وقتی بعداً در
                // اپ پیشنهادی روی این آگهی قبول/رد/تکمیل شود، بشود پستِ کانال را
                // پیدا و ادیت کرد. self-healing با بررسیِ وجودِ ستون (نه IF NOT
                // EXISTS، که فقط روی MariaDB کار می‌کند و روی MySQL خالص خطا می‌دهد).
                $__uaCols = [];
                $__uaColsRes = $db2->query("SHOW COLUMNS FROM `user_ads`");
                if ($__uaColsRes) { while ($__c = $__uaColsRes->fetch_assoc()) $__uaCols[strtolower($__c['Field'])] = true; }
                if (!isset($__uaCols['mozayede_id']))        $db2->query("ALTER TABLE `user_ads` ADD COLUMN `mozayede_id` INT DEFAULT NULL");
                if (!isset($__uaCols['mozayede_channel']))    $db2->query("ALTER TABLE `user_ads` ADD COLUMN `mozayede_channel` VARCHAR(64) DEFAULT NULL");
                if (!isset($__uaCols['mozayede_message_id'])) $db2->query("ALTER TABLE `user_ads` ADD COLUMN `mozayede_message_id` BIGINT DEFAULT NULL");

                $insertSql = "INSERT INTO user_ads (user_id, type, currency, amount, price_per_unit, description, status, mozayede_id) 
                              VALUES (?, ?, ?, ?, ?, ?, 'active', ?)";
                $insertStmt = $db2->prepare($insertSql);
                $insertStmt->bind_param("issddsi", $site_user_id, $ad_type, $currency, $amount, $price_per_unit, $description, $adId);
                
                if ($insertStmt->execute()) {
                    $website_ad_id = $db2->insert_id;
                    error_log("✅ Ad saved in website DB - ID: $website_ad_id, User ID: $site_user_id");
                }
                $insertStmt->close();
            } else {
                error_log("❌ نه کاربر پیدا شد نه ساخته شد در دیتابیس اپ - Telegram ID: " . $telegram_id);
            }
        }
        if ($db2) $db2->close();
        
        // ========== 3. ارسال پیام به ادمین ==========
        $firstname = $info['firstname'] ?? '';
        $flastname = $info['flastname'] ?? '';
        $username = $info['username'] ?? '';
        
        $text = "
📩 پیام به #ادمین_آگهی

❇️ شناسه آگهی : {$adId}

👤 مشخصات آگهی دهنده :
$firstname $flastname | @$username | $iduser

❇️ حواله  : {$adId}
❇️ {$info['type']} {$info['arz']}

👤 {$info['type']}: {$info['name']}
💶 مقدار ارز: {$info['meghdar_arz']} {$info['arz']}
💰 مبلغ پیشنهادی: {$info['mablagh_pishnehad']} تومان
💳 نحوه پــرداخــت :حواله بانکی حســاب شــخـص از {$info['country']}
✍️ توضیحات : {$info['info']} ";
        
        $bot->send_message($text, Admins[0]);
        
        $formattedPrice = number_format($info['mablagh_pishnehad']);
        
        // ========== 4. ارسال پیام به کانال (بدون دکمه شیشه‌ای) ==========
        $bot->send_message(
            "

❇️ حواله  : {$adId} #{$info['type']}_{$info['arz']} 

👤 {$info['type']}: {$info['name']}
💶 مقدار ارز: {$info['meghdar_arz']} {$info['arz']}
💰 مبلغ پیشنهادی: {$formattedPrice} تومان
💳 نحوه پــرداخــت :حواله بانکی حســاب شــخـص از {$info['country']}
✍️ توضیحات : {$info['info']}

",
            chat_id: '@' . CHANNEL,
            kboard: [
                'inline_keyboard' => [
                    [['text' => '📂 ارسال پیشنهاد', 'url' => "https://t.me/" . user_bot . "?start=mozayedeget=" . $adId]]
                ]
            ]
        );
        
        // به‌روزرسانی اطلاعات کانال در دیتابیس ربات
        $__chChatId = $bot->lastResult['sender_chat']['id'] ?? $bot->lastResult['chat']['id'] ?? null;
        $__chMsgId  = $bot->lastResult['message_id'] ?? null;
        $bot->Db->update('mozayede', [
            'channel' => $__chChatId,
            'message' => $__chMsgId
        ], ['id' => $adId]);

        // (جدید) همین چت‌آیدی/پیام‌آیدیِ کانال را روی ردیفِ آینه‌ی این آگهی در
        // دیتابیسِ اپ (user_ads) هم ثبت کن — این همان چیزی‌ست که بعداً به
        // includes/mozayede_bridge.php اجازه می‌دهد پستِ کانال را پیدا و
        // ادیت کند (وقتی پیشنهادِ اپ قبول/رد/تکمیل می‌شود). $db2 قبلاً بسته
        // شده، پس یک اتصالِ کوتاه‌عمرِ تازه فقط برای همین آپدیت باز می‌شود.
        if ($__chChatId !== null && $__chMsgId !== null) {
            try {
                require_once __DIR__ . '/../includes/fast_mysqli.php';
                $__db3 = avapay_fast_mysqli('localhost', 'aradexch_app', 'VIJVC9Gn5z9Y?D.$', 'aradexch_app', 3);
                if ($__db3) {
                    $__upSt = $__db3->prepare("UPDATE user_ads SET mozayede_channel = ?, mozayede_message_id = ? WHERE mozayede_id = ?");
                    if ($__upSt) { $__upSt->bind_param("sii", $__chChatId, $__chMsgId, $adId); $__upSt->execute(); $__upSt->close(); }
                    $__db3->close();
                }
            } catch (\Throwable $e) { error_log('user_ads mozayede_channel sync error: ' . $e->getMessage()); }
        }
        
        // ========== 5. ارسال پیام موفقیت به کاربر با لینک سایت ==========
        $successMsg = "✅ آگهی شما با موفقیت ثبت شد!\n\n";
        $successMsg .= "🆔 شماره آگهی: {$adId}\n";
        $successMsg .= "🔗 مشاهده آگهی در سایت:\nhttps://aradexchange.com/ledor/arad.php\n\n";
        $successMsg .= "📱 برای مشاهده آگهی و مدیریت پیشنهادات وارد پنل کاربری سایت شوید.";
        
        $bot->send_message($successMsg, kboard: 'home');
        
    } elseif ($bot->text == "❌ انصراف") {
        $bot->Db->update('account', ['data' => json_encode([])], ['chat_id' => $bot->UserId]);
        $bot->send_message('❌ عملیات ثبت آگهی لغو شد.', kboard: 'home');
    }
    break;
                    }
                },
                'mozayede' => function (sisoog $bot) use ($dex) {
                    if ($bot->text == "❌ انصراف") die($bot->send_message('home', kboard: 'home', new_step: null));
                    switch ($dex[1]) {                   
                        case 'send':
                            if ($bot->is_num()) {               
                                if (!is_numeric($dex[2])) die;
                                $info = $bot->Db->get('mozayede', '*', ['id' => $dex[2]]);
                                if ($info['mablagh_pishnehad'] - 100000 > $bot->text) die($bot->send_message('پیام شما خیلی پایین تر از نرخ پیشنهادی میباشد لطفا مبلغ درست را وارد کنید'));
                                elseif ($info['mablagh_pishnehad'] + 300000 < $bot->text)  die($bot->send_message('مبلغ شما بیش از حد بالاتر از نرخ پیشنهادی است '));


                                $data = $bot->setJson('mablagh_pishnehad', $bot->text, $bot->Db->get('account', 'data', ['chat_id' => $bot->UserId]));
                                $bot->send_message( "مقدار {$info['meghdar_arz']} {$info['arz']} درخواست شده است. شما میتوانید مقدار مورد نیاز خودتان را وارد کنید:", new_step: 'mozayede meghdar ' . $dex[2], dataUser: $data);
                            } else $bot->send_message('لطفا عدد ارسال کنید.',);
                            break;
                       case 'meghdar':
    if ($bot->is_num()) {
        if (!is_numeric($dex[2])) die;
        $info = $bot->Db->get('mozayede', '*', ['id' => $dex[2]]);
        if ($info['meghdar_arz'] < $bot->text) {
            $bot->send_message('پیام شما بالاتر از موجودی است.' . PHP_EOL . 'موجودی:' .  $info['meghdar_arz']);
            break;
        }

        $data = $bot->setJson('meghdar', $bot->text, $bot->Db->get('account', 'data', ['chat_id' => $bot->UserId]));
        
         $bot->send_message('💶 پیام خود را وارد کنید ؟', new_step: 'mozayede sms ' . $dex[2], dataUser: $data);
                            } else $bot->send_message('لطفا عدد ارسال کنید.',);
                            
    break;
                        case 'sms':
                            if (is_string($bot->text)) {
                                if (!is_numeric($dex[2])) die;
                                $info = $bot->Db->get('mozayede', '*', ['id' => $dex[2]]);
                              
                                $data1 = $bot->setJson('sms', $bot->text, $bot->Db->get('account', 'data', ['chat_id' => $bot->UserId]));
                                $data = json_decode($data1, true);
                                $lastnamenameres0 = $bot->Db->get('account', 'lastname', ['chat_id' => $info['chat_id']]);
                                $nameres0 = $bot->Db->get('account', 'name', ['chat_id' => $info['chat_id']]);
                                 $lastnamenameres1 = $bot->Db->get('account', 'lastname', ['chat_id' => $bot->UserId]);
                                 $nameres1 = $bot->Db->get('account', 'name', ['chat_id' => $bot->UserId]);
                                 $type= $bot->Db->get('mozayede', 'type', ['id' => $dex[2]]);
                                  $msgid= $bot->Db->get('mozayede', 'message', ['id' => $dex[2]]);
                                 $messageLink = "https://t.me/" . CHANNEL . "/" . $msgid; 


#----------------------------------------------------------------------------------> کمیسیون هنگام درخواست<-----------------------------------------------------------------------------------#

// کل مبلغ معامله = مقدار ارز × نرخ پیشنهادی
$totalAmount = (float) $data['meghdar'] * (float) $data['mablagh_pishnehad'];

// محاسبه‌ی کمیسیون بر اساس درصد و نوع (تومان/ارز انتخابی) تعیین‌شده توسط ادمین
$com = commission_apply($bot, $totalAmount, (float) $data['meghdar'], $info['arz'], $bot->UserId);

$messageBase = "نرخ پیشنهادی {$data['mablagh_pishnehad']} تومان برای هر {$info['arz']} ، بابت حواله [{$dex[2]}]({$messageLink}) از {$nameres1} {$lastnamenameres1} دریافت شد.

پیام پیشنهاد دهنده:  

`{$data['sms']}`

";

// پیام مربوط به شخصی که پیشنهاد داده است
if ($type === 'خریدار') {
    $colBuyer = number_format($com['buyer_toman']);
    $meghdarBuyer = fmt_amount($com['buyer_meghdar']);
    $paymentText = "🧮 در صورت توافق با نرخ پیشنهادی *{$data['mablagh_pishnehad']}* تومان برای هر {$info['arz']} ، شما در مقابل دریافت مقدار *$meghdarBuyer {$info['arz']}* ، مبلغ *$colBuyer تومان* پرداخت خواهید کرد.";
} else {
    $colSeller = number_format($com['seller_toman']);
    $meghdarSeller = fmt_amount($com['seller_meghdar']);
    $paymentText = "🧮 در صورت توافق با نرخ پیشنهادی *{$data['mablagh_pishnehad']}* تومان برای هر {$info['arz']} ، شما در مقابل پرداخت مقدار *$meghdarSeller {$info['arz']}* ، مبلغ *$colSeller تومان* دریافت خواهید کرد.";
}

$paymentText .= commission_note($com);

$text = $messageBase . $paymentText;

                              $bot->sendMessageWithMarkdown($text, $bot->UserId, ['inline_keyboard' => [
                                  [['text' => "❇️ ارسال مجدد پیشنهاد",'url' => "https://t.me/" . user_bot . "?start=mozayedeget=" . $info['id']]],
                                                                  
                                ]]);   


if ($bot->text == "❌ انصراف") {
    $bot->Db->update('account', ['data' => json_encode([])], ['chat_id' => $bot->UserId]);
    $bot->send_message('home', kboard: 'home');
} else {
    $bot->Db->id();
    $bot->send_message('معامله شما ثبت شد، به صفحه اول بازگشتید.', kboard: 'home', new_step: null);
}
                          
                                

                    $type = $bot->Db->get('mozayede', 'type', ['id' => $dex[2]]);
                               $msgid= $bot->Db->get('mozayede', 'message', ['id' => $dex[2]]);
                                $score =$bot->GetinfoUser(user_id: $bot->UserId);
                                
                                
                                #---------------------->پیام بعد از درخواست یوزر اول برای پذیرش یا عدم پذیرش قیمت پیشنهادی <-----------------------#
                               
$messageLink = "https://t.me/" . CHANNEL . "/{$msgid}";

// Base message
$messageBase = "نرخ پیشنهادی {$data['mablagh_pishnehad']} تومان برای هر {$info['arz']} ، بابت حواله [{$dex[2]}]({$messageLink}) از {$nameres1} {$lastnamenameres1} دریافت شد.

پیام پیشنهاد دهنده:  

`{$data['sms']}`

";

// پیام مربوط به آگهی‌دهنده: نقش او دقیقاً همان type ثبت‌شده در جدول mozayede است
// اگر آگهی‌دهنده «خریدار» باشد: کمیسیون از او کسر می‌شود (طبق حالت تومان/ارز انتخابی)
// اگر آگهی‌دهنده «فروشنده» باشد: کمیسیون به او اضافه می‌شود (طبق حالت تومان/ارز انتخابی)
if ($type === 'خریدار') {
    $colBuyer = number_format($com['buyer_toman']);
    $meghdarBuyer = fmt_amount($com['buyer_meghdar']);
    $paymentText = "🧮 در صورت توافق با نرخ پیشنهادی *{$data['mablagh_pishnehad']}* تومان برای هر {$info['arz']} ، شما در مقابل دریافت مقدار *$meghdarBuyer {$info['arz']}* ، مبلغ *$colBuyer تومان* پرداخت خواهید کرد.";
} else {
    $colSeller = number_format($com['seller_toman']);
    $meghdarSeller = fmt_amount($com['seller_meghdar']);
    $paymentText = "🧮 در صورت توافق با نرخ پیشنهادی *{$data['mablagh_pishnehad']}* تومان برای هر {$info['arz']} ، شما در مقابل پرداخت مقدار *$meghdarSeller {$info['arz']}* ، مبلغ *$colSeller تومان* دریافت خواهید کرد.";
}

$paymentText .= commission_note($com);

#---------------------------------------------------------------------------------->پذیرش و عدم پذیرش پیشنهاد <-----------------------------------------------------------------------------------#
// Combine the base message and payment text
$text = $messageBase . $paymentText;

#------------------------------------------------------------------------> اطلاع‌رسانی به پیشنهاددهنده‌های قبلی که قیمتشان پایین‌تر است (Outbid) <------------------------------------------------------------------------#
// مبلغ پیشنهادی جدیدِ همین کاربر
$newBid = (float) $data['mablagh_pishnehad'];

// همه‌ی پیشنهادهای قبلیِ همین آگهی که هنوز پذیرفته/رد نشده‌اند (ok IS NULL)
// و مبلغشان از پیشنهاد جدید کمتر است، باید مطلع شوند که پیشنهاد بالاتری ثبت شده است.
try {
    $previousReviews = $bot->Db->select('review', ['chat_id', 'meghdar'], [
        'mozayede' => $dex[2],
        'ok'       => null,
        'chat_id[!]' => $bot->UserId, // خودِ پیشنهاددهنده‌ی جدید مطلع نشود
    ]);

    if (is_array($previousReviews)) {
        // برای اینکه به هر کاربر فقط یک‌بار پیام برود (حتی اگر چند پیشنهاد قبلی داشته باشد)
        $notified = [];
        foreach ($previousReviews as $prev) {
            $prevChatId = $prev['chat_id'];
            $prevAmount = (float) $prev['meghdar'];

            // فقط کسانی که پیشنهادشان پایین‌تر از پیشنهاد جدید است
            if ($prevAmount >= $newBid) continue;
            if (isset($notified[$prevChatId])) continue;
            $notified[$prevChatId] = true;

            $outbidText =
                "🔔 برای آگهی [{$dex[2]}]({$messageLink}) یک پیشنهاد قیمتی *بالاتر* از پیشنهاد شما ثبت شد.\n\n" .
                "💰 پیشنهاد شما: *" . number_format($prevAmount) . "* تومان\n" .
                "📈 بالاترین پیشنهاد فعلی: *" . number_format($newBid) . "* تومان\n\n" .
                "به این ترتیب پیشنهاد شما در اولویت قرار نگرفت. در صورت تمایل، لطفاً پیشنهاد بالاتری ارسال فرمایید.";

            $bot->sendMessageWithMarkdown($outbidText, $prevChatId, [
                'inline_keyboard' => [
                    [['text' => "❇️ ارسال پیشنهاد بالاتر", 'url' => "https://t.me/" . user_bot . "?start=mozayedeget=" . $dex[2]]]
                ]
            ]);
        }
    }
} catch (Throwable $e) {
    // در صورت خطا در اطلاع‌رسانی، روند ثبت پیشنهاد نباید متوقف شود
    if (method_exists($bot, 'report')) {
        $bot->report(['outbid_notify_error' => $e->getMessage()]);
    }
}
#------------------------------------------------------------------------> پایان اطلاع‌رسانی Outbid <------------------------------------------------------------------------#

          $bot->Db->insert('review', [
                                    'mozayede' => $dex[2],
                                    'meghdar'  => $data['mablagh_pishnehad'],
                                    'meghdar_arz' => $data['meghdar'],
                                    'mozayede_chat_id' => $info['chat_id'],
                                    'message'  => $data['sms'],
                                    'chat_id'  => $bot->UserId,
                                    'name'  =>  $nameres1,
                                    'lastname'  =>  $lastnamenameres1,
                                ]);
                             $bot->sendMessageWithMarkdown($text, $info['chat_id'], [
    'inline_keyboard' => [
        [
        ['text' => "❌ عدم پذیرش پیشنهاد", 'callback_data' => 'pishnehad no ' . $bot->Db->id()],
            ['text' => "✅ پذیرش پیشنهاد", 'callback_data' => 'pishnehad ok ' . $bot->Db->id()]            
        ],
        [
            ['text' => "🎎 مذاکره", 'callback_data' => 'pishnehad mozakere ' . $bot->Db->id()]
        ]
    ]
]);
                                // $bot->report([$info , $data]);
                            } else $bot->send_message('لطفا مسیج ارسال کنید.',);
                            break;

                    }
                },
                'pishnehad' => function (sisoog $bot) use ($dex) {
                    switch ($dex[1]) {
                        case 'mozakere':
                            if ($bot->text) {
                                $targetChatId = $dex[2];
                                $adId         = $dex[3] ?? null;

                                // لینک دادن به خودِ آگهی — شماره‌ی آگهی در متن پیام هم نوشته می‌شود
                                // و هم به پست همان آگهی در کانال لینک می‌شود، تا مشخص باشد این
                                // مذاکره برای کدام آگهی است.
                                $adRefText = '';
                                if ($adId !== null) {
                                    $adMsgId = $bot->Db->get('mozayede', 'message', ['id' => $adId]);
                                    if ($adMsgId) {
                                        $adLink = "https://t.me/" . CHANNEL . "/{$adMsgId}";
                                        $adRefText = " برای آگهی شماره [{$adId}]({$adLink})";
                                    } else {
                                        $adRefText = " برای آگهی شماره {$adId}";
                                    }
                                }

                                $message = "♦️یک پیام مذاکره{$adRefText} برای شما ارسال شد. لطفاً پیام را بخوانید و در صورت تمایل پاسخ دهید یا پیشنهاد خود را مجدداً ارسال نمایید:" . PHP_EOL . PHP_EOL . " 📩`" . $bot->text . "`";

                                #-----------------------------> دکمه‌های شیشه‌ای «پاسخ» و «ارسال مجدد پیشنهاد» <-----------------------------#
                                $negotiationKeyboard = ['inline_keyboard' => [
                                    [['text' => '↩️ پاسخ', 'callback_data' => 'mozakere reply ' . $bot->UserId . ($adId !== null ? ' ' . $adId : '')]],
                                ]];
                                if ($adId !== null) {
                                    $negotiationKeyboard['inline_keyboard'][] = [['text' => '❇️ ارسال مجدد پیشنهاد', 'url' => "https://t.me/" . user_bot . "?start=mozayedeget=" . $adId]];
                                }

                                $bot->send_message($message, $targetChatId, $negotiationKeyboard, parse_mode: 'Markdown', link_preview_options: ['is_disabled' => true]);
                                $bot->send_message('پیام با موفقیت ارسال شد...', new_step: null);
                            } else die($bot->send_message('فقط پیام'));
                            break;
                    }
                },
                'edit' => function (sisoog $bot) use ($dex) {
                    $bot->text = $bot->convert($bot->text);
                    $getAllres = function (sisoog $sisoog, int $id) {
                        if ($sisoog->Db->has('review', ['mozayede' => $id])) {
                            $result = $sisoog->Db->select('review', '*' , ['mozayede' => $id]);
                            foreach ($result as $res) {
                                if (is_null($res['ok'])) $stat = '🔸';
                                elseif ($res['ok']) $stat = '✅';
                                else $stat = '❌';
                                $date = jdate('j F, G:i', strtotime($res['date']));
                        $res['name'] = (string) $res['name'];
                                  $res['lastname'] = (string) $res['lastname'];
                                $first_lastname_char = mb_substr($res['lastname'], 0, 1, 'UTF-8');
                                $res['meghdar']  = number_format($res['meghdar']);
$text[] = "$stat {$res['meghdar']} تومان در $date توسط {$res['name']}.$first_lastname_char";                     
       }
                            return $text;
                        }
                        return [''];
                    };
                    $getinfo = function (sisoog $sisoog, int $id) {
                        $review = ['test'];
                        $mozayede = $sisoog->Db->get('mozayede', '*', ['id' => $id]);
                        return [
                            $review,
                            $mozayede
                        ];
                    };
                    switch ($dex[1]) {
                        case 'nerkh':
                            if (!$bot->is_num($bot->text)) die($bot->send_message('لطفا عدد وارد کنید.'));
                            $message = 'نرخ';
                            $update = 'mablagh_pishnehad';
                            break;
                        case 'meghdar':
                            if (!$bot->is_num($bot->text)) die($bot->send_message('لطفا عدد وارد کنید.'));
                            $message = 'مقدار';
                            $update = 'meghdar_arz';
                            break;
                        case 'info':
                            $message = 'توضیحات';
                            $update = 'info';
                            break;
                    }
                    if ($bot->Db->has('mozayede', ['id' => $dex[2], 'stat' => 1, 'chat_id' => $bot->UserId])) {
                        // ویرایش آگهی به‌منزله‌ی به‌روزرسانی آن است؛ پرچم یادآوری صفر و تاریخ تازه می‌شود
                        // تا چرخه‌ی ۲۴ ساعته از نو آغاز شود و آگهی زودهنگام حذف نگردد.
                        $bot->Db->update('mozayede', [
                            $update       => $bot->text,
                            'date'        => (new DateTime())->format('Y-m-d H:i:s'),
                            'reminded'    => 0,
                            'reminded_at' => null,
                        ],  ['id' => $dex[2]]);

                        $All = implode(PHP_EOL, $getAllres($bot, $dex[2]));

                        $res = $getinfo($bot, $dex[2]);

                        $info =  $res[1];

                        $user = $bot->GetinfoUser(user_id: $info['chat_id']);

                        $info[$update] = $bot->text;
                        $info['mablagh_pishnehad'] = number_format($info['mablagh_pishnehad']);
                        $time = jdate('h:m:s');
                        $bot->edit_Message(
                            "

❇️ حواله  : {$info['id']} #{$info['type']}_{$info['arz']}

👤 {$info['type']}: {$info['name']}
💶 مقدار ارز: {$info['meghdar_arz']} {$info['arz']}
💰 مبلغ پیشنهادی: {$info['mablagh_pishnehad']} تومان
💳 نحوه پــرداخــت :حواله بانکی حســاب شــخـص از {$info['country']}
✍️ توضیحات : {$info['info']}


$user

✍️ پیشنهادهای ارسال شده :

$All


 🆔👉 @" . CHANNEL,
                            chat_id: $res[1]['channel'],
                            message_id: $res[1]['message'],
                            kboard: [
                                'inline_keyboard' => [
                                    [['text' => '📂 ارسال پیشنهاد ', 'url' => "https://t.me/" . user_bot . "?start=mozayedeget=" . $info['id']]],
                                    //[['text'=>"ثبت حواله جدید ",'url'=>"https://t.me/". user_bot ."?start"]]
                                ]
                            ]
                        );
                        $bot->send_message('با موفقیت انجام شد...', new_step: null);
                    } else die($bot->send_message('خطایی رخ داد.'));
                },
                                                   
                
 #----------------------------------------------------------------------------------> فانکشن وریفای <-----------------------------------------------------------------------------------#               
                
                
                'verify' => function (sisoog $bot) use ($dex, $username, $firstname, $flastname) {
$bot->text = $bot->convert($bot->text);
switch ($dex[1]) {
    case 'name':
        if (is_string($bot->text) && preg_match('/^[\x{0600}-\x{06FF}\s]+$/u', $bot->text)) {
            // If the text is valid Persian (with or without spaces)
            $data = $bot->setJson('name', $bot->text);
            $bot->send_message('♦️ لطفا نام خانوادگی خودتان را ارسال کنید...
            (فقط به زبان فارسی)', kboard: ['keyboard' => [[['text' => "❌ انصراف"]]], 'resize_keyboard' => true], new_step: 'verify lastname', dataUser: $data);
        } else {
            // If the text contains invalid characters (not Persian)
            $bot->send_message('لطفا فقط نام خود را به زبان فارسی وارد کنید. بدون اعداد و کاراکترهای غیر فارسی.');
        }
        break;

    case 'lastname':
        if (is_string($bot->text) && preg_match('/^[\x{0600}-\x{06FF}\s]+$/u', $bot->text)) {
            // If the text is valid Persian (with or without spaces)
            $data = $bot->setJson('lastname', $bot->text);
            $bot->send_message('♦️ لطفا شماره خودتان را با انتخاب دکمه (✅ شماره من را بفرست) ارسال کنید', kboard: [
                'keyboard' => [
                    [['text' => '✅ شماره من را بفرست', 'request_contact' => true]],
                    [['text' => "❌ انصراف"]]
                ]
            ], new_step: 'verify number', dataUser: $data);
        } else {
            // If the text contains invalid characters (not Persian)
            $bot->send_message('لطفا فقط نام خانوادگی خود را به زبان فارسی وارد کنید. بدون اعداد و کاراکترهای غیر فارسی.');
        }
        break;
        


   case 'number':
   if (isset($bot->update->message->contact->phone_number)) {
            if ($bot->update->message->contact->user_id == $bot->UserId) {
                $data = $bot->setJson('number', $bot->update->message->contact->phone_number);

            // ارسال پیام برای مرحله بعد
            $bot->send_message(
                '♦️ لطفا آیدی عددی معرف خود را وارد کنید. در صورتی که معرف ندارید، روی "ندارم" بزنید.',
                new_step: 'verify inviter',
                kboard: [
                    'keyboard' => [
                        [['text' => 'ندارم']],
                        [['text' => "❌ انصراف"]]
                    ],
                    'resize_keyboard' => true
                ],
                dataUser: $data
            );
        } else {
            $bot->send_message('♦️ لطفا شماره خودتان را با انتخاب دکمه (✅ شماره من را بفرست) ارسال کنید.');
        }
    } else {
        $bot->send_message('لطفا روی گزینه ✅ شماره من را بفرست کلیک کنید.');
    }
    break;


case 'inviter':
    if (
        is_string($bot->text) &&
        (ctype_digit($bot->text) || $bot->text === 'ندارم')
    ) {
        // ذخیره معرف (آیدی عددی یا "ندارم شدی")
        $data = $bot->setJson('inviter', $bot->text);

        $bot->send_message(
            'لطفا اطلاعات هویتی خودتان را ارسال کنید...
مدارک قابل قبول:
✅ پاسپورت
✅ شناسنامه
❌ کارت ملی 
❌ مدارک می‌بایستی خوانا، بدون قلم‌خوردگی، بدون فتوشاپ، با چهار گوشه مشخص ارسال شوند، در غیر اینصورت پذیرفته نخواهند شد.',
            new_step: 'verify info',
            kboard: [
                'keyboard' => [
                    [['text' => "❌ انصراف"]]
                ],
                'resize_keyboard' => true
            ],
            dataUser: $data
        );
    } else {
        $bot->send_message(
            'لطفاً فقط آیدی عددی معرف را وارد کنید. در صورتی که معرف ندارید، روی "ندارم شدی" بزنید.',
            kboard: [
                'keyboard' => [
                    [['text' => 'ندارم']],
                    [['text' => "❌ انصراف"]]
                ],
                'resize_keyboard' => true
            ]
        );
    }
    break;
      
    
      case 'info':
  
    
    if (isset($bot->update->message->photo)) {
        // Get the largest photo size (highest resolution)
        $photos = $bot->update->message->photo;
        $largestPhoto = end($photos); // Get the last (largest) photo
        $file_id = $largestPhoto->file_id;

        if ($file_id) {
            // Set the photo and other data
            $data = $bot->setJson('photo', $file_id);

           
            $bot->send_message('با تشکر، اطلاعات شما ارسال شد ونتیجه احراز هویت بعد از بررسی به شما اطلاع رسانی خواهد شد. !', kboard: 'home', new_step: null,
                dataUser: $data);
            // Send the message to the user
           /*$bot->send_message(
                'با تشکر، اطلاعات شما ارسال شد ونتیجه احراز هویت بعد از بررسی به شما اطلاع رسانی خواهد شد. !',
                new_step: null,
                dataUser: $data
            );*/

            // Update user account verification status
            $bot->Db->update('account', ['verified' => 'wating'], ['chat_id' => $bot->UserId]);

            // Forward the message to the admin
            $bot->forwardMessage(Admins[0], $bot->UserId, $bot->update->message->message_id);

            // Decode the data for further processing
            $decodedData = json_decode($data, true);

            // Extract the necessary fields
            $name = $decodedData['name'];
            $lastname = $decodedData['lastname'];
            $number = $decodedData['number'];
            $firstname = $decodedData['firstname'];  // Assuming these fields exist
            $flastname = $decodedData['flastname'];  // Assuming these fields exist
            $username = $decodedData['username'];    // Assuming this field exists
            $inviter= $decodedData['inviter'];   
            // Send the extracted data to the admin
$bot->send_message(
    "*📋 User Information:*" . PHP_EOL .
    "👤 *First Name:* $name" . PHP_EOL .
    "👤 *Last Name:* $lastname" . PHP_EOL .
    "📱 *Telegram Number:* [Click Here](https://t.me/+$number)" . PHP_EOL .
    "👥 *User:* $firstname $flastname | @$username" . PHP_EOL .
    "🙋‍♂️ *Invited By:* $inviter" . PHP_EOL .
    "🆔 *User ID:* `{$bot->UserId}`",
    chat_id: Admins[0],
    parse_mode: 'Markdown',
    kboard: [
        'inline_keyboard' => [
            [
                ['text' => '✅ Approve User', 'callback_data' => 'verify ok ' . $bot->UserId],
                ['text' => '❌ Reject User', 'callback_data' => 'verify not ' . $bot->UserId],
            ]
        ]
    ]
);
 }
 }
        break;


    #----------------------------------------------------------------------------------> شماره حساب شخص سوم<-----------------------------------------------------------------------------------#
case '3rdpartyname':
    // Save user input to JSON
    $data = $bot->setJson('3rdpartyname', $bot->text);
    $currentDate = date('Y-m-d');
    $decodedData = json_decode($data, true) ?: [];
    
    $name = $bot->text;

    // Check if the user is a buyer or seller and set the next step
    if ($name === '✳️ خریدار') {
        $newStep = 'verify 3rdipayibanbuyer';
        // Send a prompt asking the user for their IBAN
        $message = "❗️شما به عنوان خریدار یورو ویا سایر ارز های دیگر هستید و میخواهید در ایران ریال پرداخت کرده و  شخص دیگری یورو از طرف شما یورو دریافت کند ؟";
    } elseif ($name === '✳️ فروشنده') {
        $newStep = 'verify 3rdipayibanseller';
        // Send a prompt asking the user for their IBAN
        $message = "❗️شما به عنوان فروشنده یورو ویا سایر ارز های دیگر هستید و میخواهید شخص دیگری در ایران ریال دریافت کند؟؟";
    } else {
        $newStep = 'verify 3rdamount'; // Default step if not specified
        $message = "❗ لطفاً مبلغ واریزی خود را وارد کنید.";
    }

    // Send the prompt message to the user and transition to the new step
    $bot->send_message(
        $message,
        kboard: [
            'keyboard' => [[['text' => "✅ بله , ادامه»"]],
            [['text' => "❌ انصراف"]]
            ],
            'resize_keyboard' => true
        ],
        new_step: $newStep,
        dataUser: $data
    );

    break;
    

case '3rdipayibanseller':
    $data = $bot->setJson('3rdipayiban', $bot->text);
  
    $message = "اینجانب .... با مشخصات درج شده در کارت شناسایی ذیل گواهی میدهم که واریزیهای  یورویی که از حساب خودم در کشور ... به حسابهایی که داده شده انجام میشود (طبق فیشهای ارسالی) و در مقابل واریزی معادل ریالی آنرا در حساب(های) ذکر شده زیر دریافت میکنم (طبق فیشهای ارسال شده) و تمام مسوولیتهای آن بر عهده من خواهد بود همچنین  تضمین میکنم در صورت بروز مشکل بابت این واریزیها همکاری لازم را داشته باشم.
1. حساب به نام ... نزد بانک ...
(2. حساب به نام ... نزد بانک ...)

🔴لطفا این متن را صاحب حساب یورو بنویسن و امضا کنن و در کنار کارت شناسایی گذاشته بشه وبه همراه تاریخ و امضا, عکسش را در پیام بعدی برای ما بفرستید.";

    $bot->send_message(
        $message,
        kboard: [
            'keyboard' => [[['text' => "❌ انصراف"]]],
            'resize_keyboard' => true
        ],
        new_step: 'verify 3rdipayconfirm',
        dataUser: $data
    );

    break;

case '3rdipayibanbuyer':
    $data = $bot->setJson('3rdipayiban', $bot->text);
   
    $message = "اینجانب .... با اطلاعات مندرج در کارت شناسایی ذیل گواهی میدهم که در تاریخ ...... مبلغ ...... از حساب خودم واریز نمودم (فیش واریزی جداگانه ارسال میشود) و در مقابل معادل یورویی آن بمبلغ ........ یورو را با رضایت خودم در حساب خانم / آقای ..... با اطلاعات  زیر
Name:
IBAN:
دریافت نمودم (طبق فیش ارسالی).

🔴لطفا این متن را لطفا بنویسید و تاریخ و امضا بزنید و در کنار کارت ملی (و یا کارت شناسایی معتبر) گذاشته بشه و عکسش را در پیام بعدی برای ما بفرستین.";

    $bot->send_message(
        $message,
        kboard: [
            'keyboard' => [[['text' => "❌ انصراف"]]],
            'resize_keyboard' => true
        ],
        new_step: 'verify 3rdipayconfirm',
        dataUser: $data
    );

    break;

case '3rdipayconfirm':
    $bot->forwardMessage(Admins[0], $bot->UserId, $bot->update->message->message_id);
    $currentDate = date('Y-m-d');
    $data = $bot->setJson('3rdipayconfirm', $bot->text);
   $user = $bot->Db->get('account', ['name', 'lastname'], ['chat_id' => $bot->UserId]);
        $fullName = $user['name'] . ' ' . $user['lastname'];

    
    $bot->send_message(
        "❇️ #پرداخت_شخص_ثالث

👤 مشتری:  $fullName\n\n#Date_$currentDate",
        chat_id: Admins[0],
        kboard: [
             'inline_keyboard' => [
                    [
                        ['text' => '✅ پرداخت با موفقیت انجام شد', 'callback_data' => "verify varizshod {$bot->UserId}"],
                        ['text' => '♻️ در حال انجام پرداختی', 'callback_data' => "verify process {$bot->UserId}"]
                    ],
                    [['text' => '❌ عدم تایید حساب', 'callback_data' => "verify hesabnot {$bot->UserId}"]]
                ]
        ]
    );

    break;

    
                        
#----------------------------------------------------------------------------------> ارسال شماره حساب<-----------------------------------------------------------------------------------#
case 'card':
    if (is_string($bot->text) && ctype_digit($bot->text)) {
        $data = $bot->setJson('card', $bot->text);
        $bot->send_message(
            '💳لطفا شماره شبا و یا IBAN گیرنده را وارد کنید مثال: IR122344566998776654324453465',
            kboard: [
                'keyboard' => [[['text' => "❌ انصراف"]]],
                'resize_keyboard' => true
            ],
            new_step: 'verify iban',
            dataUser: $data
        );
    } else {
        $bot->send_message('لطفا فقط اعداد وارد کنید.');
    }
    break;

case 'iban':
    if (is_string($bot->text) && preg_match('/^[a-zA-Z0-9]+$/', $bot->text)) {
        $data = $bot->setJson('iban', $bot->text);
        $bot->send_message(
            '🏦لطفا نوع ارز را مشخص کنید و همچنین نام بانک گیرنده را بنویسید مثال : ریال بانک ملت',
            kboard: [
                'keyboard' => [[['text' => "❌ انصراف"]]],
                'resize_keyboard' => true
            ],
            new_step: 'verify bankname',
            dataUser: $data
        );
    } else {
        $bot->send_message('لطفا فقط حروف و اعداد وارد کنید.');
    }
    break;

case 'bankname':
    if (is_string($bot->text) && preg_match("/^[\p{L}\s]+$/u", $bot->text)) {
        $data = $bot->setJson('bankname', $bot->text);
        $bot->send_message(
            '👤نام ونام خانوادگی گیرنده را وارد کنید !!',
            kboard: [
                'keyboard' => [[['text' => "❌ انصراف"]]],
                'resize_keyboard' => true
            ],
            new_step: 'verify empfanger',
            dataUser: $data
        );
    } else {
        $bot->send_message('لطفا فقط حروف فارسی یا انگلیسی وارد کنید و از اعداد خودداری کنید.');
    }
    break;

case 'empfanger':
    if (is_string($bot->text) && preg_match("/^[\p{L}\s]+$/u", $bot->text)) {
        $data = $bot->setJson('empfanger', $bot->text);
        $decodedData = json_decode($data, true);
        $user = $bot->Db->get('account', ['name', 'lastname'], ['chat_id' => $bot->UserId]);
        $fullName = $user['name'] . ' ' . $user['lastname'];
        $number = $bot->Db->get('account', 'phone', ['chat_id' => $bot->UserId]);
        $card = $decodedData['card'];
        $iban = $decodedData['iban'];
        $bankname = $decodedData['bankname'];
        $empfanger = $decodedData['empfanger'];

        $bot->send_message(
            'لطفا اطلاعات نهایی خود را تایید کنید:' . PHP_EOL .
            "👤 نام و نام خانوادگی : $fullName " . PHP_EOL .
            "📱 شماره تلفن: $number" . PHP_EOL .
            "💳 شماره کارت شما: $card" . PHP_EOL .
            "💳 شماره شبا یا ایبان نامبر شما: $iban" . PHP_EOL .
            "🏦 نام  ارز و نام بانک: $bankname" . PHP_EOL .
            "👥 نام گیرنده: $empfanger" . PHP_EOL,
            kboard: [
                'keyboard' => [
                    [['text' => "✅ تایید"]],
                    [['text' => "❌ انصراف"]]
                ],
                'resize_keyboard' => true
            ],
            new_step: 'verify infohesab',
            dataUser: $data
        );
    } else {
        $bot->send_message('لطفا مسیج ارسال کنید.');
    }
    break;

case 'infohesab':
    $data = $bot->setJson('info', 'ok');
    $decodedData = json_decode($data, true);
    $user = $bot->Db->get('account', ['name', 'lastname'], ['chat_id' => $bot->UserId]);
    $fullName = $user['name'] . ' ' . $user['lastname'];
    $number = $bot->Db->get('account', 'phone', ['chat_id' => $bot->UserId]);
    $card = $decodedData['card'];
    $iban = $decodedData['iban'];
    $bankname = $decodedData['bankname'];
    $empfanger = $decodedData['empfanger'];

    $bot->send_message('❇️ با تشکر، اطلاعات شماره حساب شما دریافت شد . !', kboard: 'home', new_step: null, dataUser: $data);

    $bot->send_message(
        'اطلاعات کاربری:' . PHP_EOL . PHP_EOL .
        "👤 نام و نام خانوادگی: $fullName " . PHP_EOL .
        "💳 شماره کارت: $card" . PHP_EOL .
        "💳 شماره شبا یا ایبان نامبر : $iban" . PHP_EOL .
        "🏦 نام ارز و نام بانک: $bankname" . PHP_EOL .
        "👥 گیرنده: $empfanger" . PHP_EOL,
        chat_id: Admins[0],
        kboard: [
            'inline_keyboard' => [
                [['text' => '✅ پرداخت با موفقیت انجام شد', 'callback_data' => 'verify varizshod ' . $bot->UserId],
                 ['text' => '♻️ در حال انجام پرداختی', 'callback_data' => 'verify hesabok ' . $bot->UserId]],
                [['text' => '❌ عدم تایید حساب', 'callback_data' => 'verify hesabnot ' . $bot->UserId]]
            ]
        ]
    );
 case 'infohesab1':
    $data = $bot->setJson('info', 'ok');
    $userdbinfo = $bot->Db->get('account', ['card', 'iban', 'bankname', 'name', 'empfanger', 'lastname'], ['chat_id' => $bot->UserId]);

    if ($userdbinfo) {
        $bot->send_message('❇️ با تشکر، اطلاعات شماره حساب شما دریافت شد!', kboard: 'home', new_step: null, dataUser: $data);

        $fullName = trim("{$userdbinfo['name']} {$userdbinfo['lastname']}");
        $userInfoText = "اطلاعات کاربری:\n\n👤 نام و نام خانوادگی: $fullName\n";

        $fieldLabels = [
            'card'      => '💳 شماره کارت',
            'iban'      => '💳 شماره شبا یا ایبان نامبر',
            'bankname'  => '🏦 نام ارز و نام بانک',
            'empfanger' => '👥 گیرنده'
        ];

        foreach ($fieldLabels as $key => $label) {
            if (!empty($userdbinfo[$key])) {
                $userInfoText .= "$label: {$userdbinfo[$key]}\n";
            }
        }

        $bot->send_message(
            $userInfoText,
             chat_id: Admins[0],
            kboard: [
                'inline_keyboard' => [
                    [
                        ['text' => '✅ پرداخت با موفقیت انجام شد', 'callback_data' => "verify varizshod {$bot->UserId}"],
                        ['text' => '♻️ در حال انجام پرداختی', 'callback_data' => "verify process {$bot->UserId}"]
                    ],
                    [['text' => '❌ عدم تایید حساب', 'callback_data' => "verify hesabnot {$bot->UserId}"]]
                ]
            ]
        );
    }
    break;   
    


}
                }
            ]);
        });

  #----------------------------------------------------------------------------------> فانکشن دکمه شیشه ای<-----------------------------------------------------------------------------------#  
    if (!is_null($data))
        $bot->handleSwitch($data, [],  function (sisoog $bot, $data) use ($callbackfId, $callbackchatId, $callbackmessageId, $callbackId) {
            $dex = explode(' ', $data);
            $getAllres = function (sisoog $sisoog, int $id) {
                $id     = $sisoog->Db->get('review', 'mozayede', ['id' => $id]);
                $result = $sisoog->Db->select('review', '*', ['mozayede' => $id, "ORDER" => "id"]);
                foreach ($result as $res) {
                    if (is_null($res['ok'])) $stat = '🔸';
                    elseif ($res['ok']) $stat = '✅';
                    else $stat = '❌';
                    $date = jdate('j F, G:i', strtotime($res['date']));
                      $res['name'] = (string) $res['name'];
                                $res['lastname'] = (string) $res['lastname'];
                                $first_lastname_char = mb_substr($res['lastname'], 0, 1, 'UTF-8');
                                $res['meghdar']  = number_format($res['meghdar']);
                               $text[] = "$stat {$res['meghdar']} تومان در $date توسط {$res['name']}.$first_lastname_char";
                            }
                return $text;
            };
            $getinfo = function (sisoog $sisoog, int $id) {
                $review = $sisoog->Db->get('review', '*', ['id' => $id]);
                $mozayede = $sisoog->Db->get('mozayede', '*', ['id' => $review['mozayede']]);
                return [
                    $review,
                    $mozayede
                ];
            };
$bot->handleSwitch($dex[0], [
    /* ===================================================================
     * ادغام با اپلیکیشن AVA PAY: پذیرش/رد پیشنهادهای بازار ارز (ad_offers)
     * callback_data:  "avapay accept {offerId}"  |  "avapay reject {offerId}"
     * این هندلر به دیتابیس اپلیکیشن (aradexch_app) وصل می‌شود و از منطق
     * مشترک includes/offer_actions.php استفاده می‌کند.
     * =================================================================== */
    'supportreply' => function (sisoog $bot) use ($dex, $callbackfId, $callbackchatId, $callbackmessageId, $callbackId) {
        $targetUser = intval($dex[1] ?? 0);

        // تابع کمکی برای answerCallbackQuery (نمایش پیام کوچک بالای صفحه)
        $answer = function ($text, $alert = false) use ($callbackId) {
            $u = "https://api.telegram.org/bot" . token . "/answerCallbackQuery";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $u);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, [
                'callback_query_id' => $callbackId,
                'text'              => $text,
                'show_alert'        => $alert ? 'true' : 'false',
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_exec($ch);
            curl_close($ch);
        };

        // فقط ادمین‌ها مجاز به پاسخ‌دادن هستند
        if (!in_array($callbackfId, Admins, true)) { $answer('دسترسی غیرمجاز است.', true); return; }
        if ($targetUser <= 0) { $answer('کاربر نامعتبر است.', true); return; }

        // همیشه اول جواب بده تا دکمه هیچ‌وقت روی «در حال بارگذاری» گیر نکند
        $answer('در حال آماده‌سازی...');

        try {
            // اتصال به دیتابیس اپلیکیشن AVA PAY (aradexch_app)
            require_once __DIR__ . '/../includes/fast_mysqli.php';
            $appDb = avapay_fast_mysqli('localhost', 'aradexch_app', 'VIJVC9Gn5z9Y?D.$', 'aradexch_app', 3);
            if (!$appDb) {
                $__ctx1801 = stream_context_create(['http' => ['timeout' => 8], 'https' => ['timeout' => 8]]);
                @file_get_contents("https://api.telegram.org/bot" . token . "/sendMessage?chat_id={$callbackchatId}&text=" . urlencode('⚠️ خطا در اتصال به سرور اپلیکیشن.'), false, $__ctx1801);
                return;
            }

            $appDb->query("CREATE TABLE IF NOT EXISTS admin_reply_prompts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                prompt_message_id BIGINT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // نام کاربر برای متن راهنما
            $ures = $appDb->query("SELECT first_name, last_name FROM users WHERE id = " . (int)$targetUser . " LIMIT 1");
            $urow = $ures ? $ures->fetch_assoc() : null;
            $name = $urow ? trim(($urow['first_name'] ?? '') . ' ' . ($urow['last_name'] ?? '')) : '';
            if ($name === '') $name = "کاربر #{$targetUser}";

            // ارسال پیام راهنما با force_reply؛ ادمین باید روی همین پیام ریپلای کند
            $promptText = "✍️ در حال پاسخ به: " . $name . "\n\nپاسخ خود را «ریپلای» کنید:";
            $ch = curl_init("https://api.telegram.org/bot" . token . "/sendMessage");
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, [
                'chat_id'             => $callbackchatId,
                'text'                => $promptText,
                'reply_to_message_id' => $callbackmessageId,
                'reply_markup'        => json_encode(['force_reply' => true, 'selective' => true]),
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $res = json_decode(curl_exec($ch), true);
            curl_close($ch);
            $promptId = $res['result']['message_id'] ?? null;

            if ($promptId) {
                $st = $appDb->prepare("INSERT INTO admin_reply_prompts (user_id, prompt_message_id) VALUES (?, ?)");
                if ($st) { $st->bind_param("ii", $targetUser, $promptId); $st->execute(); }
            } else {
                $__ctx1838 = stream_context_create(['http' => ['timeout' => 8], 'https' => ['timeout' => 8]]);
                @file_get_contents("https://api.telegram.org/bot" . token . "/sendMessage?chat_id={$callbackchatId}&text=" . urlencode('⚠️ ارسال درخواست پاسخ ناموفق بود. لطفاً دوباره تلاش کنید.'), false, $__ctx1838);
            }
            $appDb->close();
        } catch (\Throwable $e) {
            error_log('supportreply handler error: ' . $e->getMessage());
        }
        return;
    },

    'avapay' => function (sisoog $bot) use ($dex, $callbackfId, $callbackchatId, $callbackmessageId, $callbackId) {
        $sub     = $dex[1] ?? '';
        $offerId = intval($dex[2] ?? 0);

        // تابع کمکی برای answerCallbackQuery (نمایش پیام کوچک بالای صفحه)
        $answer = function ($text, $alert = false) use ($callbackId) {
            $u = "https://api.telegram.org/bot" . token . "/answerCallbackQuery";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $u);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, [
                'callback_query_id' => $callbackId,
                'text'              => $text,
                'show_alert'        => $alert ? 'true' : 'false',
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_exec($ch);
            curl_close($ch);
        };

        if ($sub === 'noop') { $answer('انجام شد.'); return; }
        if ($offerId <= 0 || !in_array($sub, ['accept', 'reject'], true)) {
            $answer('درخواست نامعتبر است.', true); return;
        }

        // اتصال به دیتابیس اپلیکیشن AVA PAY (aradexch_app)
        require_once __DIR__ . '/../includes/fast_mysqli.php';
        $appDb = avapay_fast_mysqli('localhost', 'aradexch_app', 'VIJVC9Gn5z9Y?D.$', 'aradexch_app', 3);
        if (!$appDb) {
            $answer('خطا در اتصال به سرور اپلیکیشن.', true); return;
        }

        // ثابت‌های موردنیاز offer_actions و بارگذاری منطق مشترک
        if (!defined('BOT_TOKEN'))         define('BOT_TOKEN', token);
        if (!defined('ADMIN_TELEGRAM_ID')) define('ADMIN_TELEGRAM_ID', (string) report);
        require_once __DIR__ . '/../includes/offer_actions.php';

        // پیدا کردن کاربر اپ بر اساس telegram_id فرستنده‌ی کلیک
        $tgId = $appDb->real_escape_string((string) $callbackfId);
        $ures = $appDb->query("SELECT id FROM users WHERE telegram_id = '{$tgId}' LIMIT 1");
        $urow = $ures ? $ures->fetch_assoc() : null;
        if (!$urow) {
            $answer('حساب شما در AVA PAY یافت نشد. لطفاً ابتدا در اپلیکیشن ثبت‌نام کنید.', true);
            $appDb->close(); return;
        }
        $actorId = (int) $urow['id'];

        if ($sub === 'accept') {
            $res = avapay_offer_accept($appDb, $offerId, $actorId);
        } else {
            $res = avapay_offer_reject($appDb, $offerId, $actorId, 'رد شده از طریق تلگرام');
        }

        if (!empty($res['success'])) {
            $done = ($sub === 'accept') ? '✅ پیشنهاد پذیرفته شد' : '❌ پیشنهاد رد شد';
            $answer($done);
            // غیرفعال‌کردن دکمه‌ها روی همان پیام
            oa_editTelegramMarkup($callbackchatId, $callbackmessageId, $done);
        } else {
            $answer($res['message'] ?? 'خطا در انجام عملیات', true);
        }
        $appDb->close();
        return;
    },

    'pishnehad' => function (sisoog $bot) use ($dex, $getAllres, $getinfo) {

        
           switch ($dex[1]) {
    case 'ok':
        // Update the review status to 'ok'
        $bot->Db->update('review', ['ok' => 1], ['id' => $dex[2]]);

        // Get all relevant data for the review and the user
        $All = implode(PHP_EOL, $getAllres($bot, $dex[2]));
        $res = $getinfo($bot, $dex[2]);

        // Modify types based on whether the user is a seller or buyer
        $res[1]['type'] = match ($res[1]['type']) {
            'فروشنده' => 'فروشنده',
            'خریدار' => 'خریدار'
        };

        $res[11]['type'] = match ($res[1]['type']) {
            'فروشنده' => 'فروش',
            'خریدار' => 'خرید'
        };

        $res[12]['type'] = match ($res[1]['type']) {
            'فروشنده' => 'خریدار',
            'خریدار' => 'فروشنده'
        };

        // Update auction status
        $bot->Db->update('mozayede', ['stat' => 0], ['id' => $res[1]['id']]);

        // Get user information
        $user = $bot->GetinfoUser(user_id: $res[2]['chat_id']);
        $ifo = $bot->Db->get('review', '*', ['id' => $dex[2]]);
        $datebids = $bot->Db->get('review', 'date', ['id' => $dex[2]]);
        $col = number_format($ifo['meghdar'] * $ifo['meghdar_arz']);
        $pish = number_format($ifo['meghdar']);
        // نکته مهم: $ifo['meghdar'] عمداً به رشته‌ی فرمت‌شده تبدیل نمی‌شود، چون پایین‌تر
        // برای محاسبه‌ی $totalAmount به‌صورت (float) استفاده می‌شود؛ اگر اینجا با کاما
        // فرمت شود، تبدیل به float مقدار را ناقص می‌کند (مثلاً "199,000" => 199.0)
        // و همین باعث نمایش اشتباه مبلغ کل (مثلاً 199.000 به‌جای 199.000.000) می‌شود.
        // برای نمایش، از متغیر فرمت‌شده‌ی $pish استفاده می‌شود.
        $fullName0 = $bot->Db->get('account', 'name', ['chat_id' => $res[0]['mozayede_chat_id']]) . ' ' . $bot->Db->get('account', 'lastname', ['chat_id' => $res[0]['mozayede_chat_id']]);
        $fullName1 = $bot->Db->get('account', 'name', ['chat_id' => $res[0]['chat_id']]) . ' ' . $bot->Db->get('account', 'lastname', ['chat_id' => $res[0]['chat_id']]);
        $getnumber0 = $bot->Db->get('account', 'phone', ['chat_id' => $res[0]['mozayede_chat_id']]);
        $getnumber1 = $bot->Db->get('account', 'phone', ['chat_id' => $res[0]['chat_id']]);
        $idads = $bot->Db->get('mozayede', 'type', ['id' => $res[1]['id']]);
        $date = $bot->Db->get('review', 'date', ['id' => $dex[2]]);

        // Get the current timestamp
        $currentDate = time();

        // Retrieve the review's date (assuming $date is a timestamp; if it's a string, you need to convert it)
        $reviewDate = strtotime($date);

        // Check if the review date is more than 25 hours older than the current time
        if ($currentDate - $reviewDate > 25 * 3600) {
            // Send message to the user that the offer has expired
            $userId = $res[0]['chat_id']; // Assuming this is the correct user ID
            $bot->send_message(
                "⚠️ این پیشنهاد منقضی شده است ,شما نمیتوانید آنرا بپذیرید.", 
                chat_id: $userId
            );
        
return;
      
      }  


                       
                         


  #----------------------------------------------------------------------------------> کمیسیون بخش ۲<-----------------------------------------------------------------------------------#  

// کل مبلغ معامله (نرخ پیشنهادی × مقدار ارز)
$totalAmount = (float) $ifo['meghdar'] * (float) $ifo['meghdar_arz'];

// کمیسیون بر عهده‌ی «خریدار» است (دقیقاً مطابق منطق AvaPay در offer_actions.php)،
// پس شناسه‌ی تلگرامِ خریدار را برای یافتن کمیسیونِ شخصیِ او پاس می‌دهیم.
$__buyerTgId = (isset($res[1]['type']) && $res[1]['type'] === 'خریدار')
    ? ($res[0]['mozayede_chat_id'] ?? null)
    : ($res[0]['chat_id'] ?? null);
$com = commission_apply($bot, $totalAmount, (float) $ifo['meghdar_arz'], $res[1]['arz'] ?? '', $__buyerTgId);

if (!function_exists('formatDealMessage')) {
    /**
     * ساخت پیام نهایی توافق برای هر طرف، به همراه کمیسیونِ تعیین‌شده توسط ادمین.
     * user['type'] === 'فروشنده' => این شخص ارز می‌دهد و تومان می‌گیرد.
     * در غیر این صورت (خریدار) => این شخص تومان می‌دهد و ارز می‌گیرد.
     */
    function formatDealMessage(array $user, $chatId, array $com, string $pish, array $res): string
    {
        $userTitle = $user['type'] === 'فروشنده' ? "فروشنده محترم" : "خریدار محترم";
        $arz = $res[1]['arz'] ?? '';

        if ($user['type'] === 'فروشنده') {
            $tomanShow = number_format($com['seller_toman']);
            $meghdarShow = fmt_amount($com['seller_meghdar']);
            $additionalMessage = "مبلغ پیشنهاد شده $pish تومان ، شما در مقابل پرداخت مقدار *$meghdarShow $arz* ، مبلغ `$tomanShow` تومان دریافت خواهید کرد.";
        } else {
            $tomanShow = number_format($com['buyer_toman']);
            $meghdarShow = fmt_amount($com['buyer_meghdar']);
            $additionalMessage = "مبلغ پیشنهاد شده $pish تومان ، شما در مقابل پرداخت `$tomanShow` تومان ، مقدار *$meghdarShow $arz* را دریافت خواهید کرد.";
        }

        $commissionLine = '';
        if (($com['amount'] ?? 0) > 0) {
            $label = (($com['type'] ?? 'percent') === 'fixed')
                ? "🧾 کمیسیون (مقدار ثابت): "
                : "🧾 کمیسیون ({$com['percent']}٪): ";
            $commissionLine = $label . fmt_amount($com['amount']) . " {$com['currency']}\n";
        }

        return "📩👤 $userTitle : [{$user['first_name']} {$user['last_name']}](https://t.me/+"
            . htmlspecialchars($user['phone']) . ")
✳️ به شماره آیدی: {$chatId}
🟢 شماره آگهی : {$res[0]['mozayede']}
{$commissionLine}
$additionalMessage

🆔👉 @" . CHANNEL;
    }
}

// Ensure that indices for $res[1] and $res[12] exist before accessing them
if (isset($res[1]) && isset($res[12])) {
    // آگهی‌دهنده
    $messageUser1 = formatDealMessage(
        [
            'type' => $res[1]['type'],
            'first_name' => $fullName0,
            'last_name' => $lastnamenameres0,
            'phone' => $getnumber0,
        ],
        $res[0]['mozayede_chat_id'],
        $com,
        $pish,
        $res
    );

    // پیشنهاددهنده
    $messageUser2 = formatDealMessage(
        [
            'type' => $res[12]['type'],
            'first_name' => $fullName1,
            'last_name' => $lastnamenameres1,
            'phone' => $getnumber1,
        ],
        $res[0]['chat_id'],
        $com,
        $pish,
        $res
    );

    // Send messages
    $bot->sendMessageWithMarkdown($messageUser1, Admins[0], ['parse_mode' => 'Markdown']);
    $bot->sendMessageWithMarkdown($messageUser1, $res[0]['mozayede_chat_id'], ['parse_mode' => 'Markdown']);

    $bot->sendMessageWithMarkdown($messageUser2, Admins[0], ['parse_mode' => 'Markdown']);
    $bot->sendMessageWithMarkdown($messageUser2, $res[0]['chat_id'], ['parse_mode' => 'Markdown']);
}


 #--------------------------------->بعد از تایید و پذیرش پیشنهاد برای درخواست حساب<----------------------------------#   


            
            

$bot->sendMessageWithMarkdown(
    '3✅ درخواست شما تایید و برای ادمین ارسال شد.

🔸 لطفاً جهت انجام هماهنگی به [مدیر هماهنگی تبادلات](https://t.me/aradtransfer_admin) پیام بدهید و در تلگرام آنلاین باشید. همچنین دقت کنید بدون هماهنگی هرگز هیچ مبلغی را پرداخت نکنید.

🔴💳 لطفاً اطلاعات حساب گیرنده را از طریق دکمه "📩 ارسال حساب" در منوی ربات برای ما ارسال کنید.',
    $res[0]['chat_id'],
    [
        'inline_keyboard' =>  [
        [
            ['text' => '📩 ارسال حساب', 'callback_data' => 'bank sendhesab'],
            ['text' => '🏦👥 واریز به شخص ثالث', 'callback_data' => 'bank 3rdparty']
        ],
        [
            ['text' => '⚠️ حساب قبلا فرستاده ام!!', 'callback_data' => 'bank ghabli'],
        ]
    ]
]);


$bot->sendMessageWithMarkdown(' 2✅شما پیشنهاد متقاضی را پذیرفتید !!
🔸لطفاجهت انجام هماهنگی به [مدیر هماهنگی تبادلات](https://t.me/aradtransfer_admin) پیام بدهید و در تلگرام آنلاین باشید.همچنین دقت کنید بدون هماهنگی هرگز هیچ مبلغی را پرداخت نکنید.

🔴💳 لطفا اطلاعات حساب گیرنده را از طریق دکمه "📩 ارسال حساب" در منو ربات برای ما ارسال کنید.
', 
$res[0]['mozayede_chat_id'], 
['inline_keyboard' =>  [
        [
            ['text' => '📩 ارسال حساب', 'callback_data' => 'bank sendhesab'],
            ['text' => '🏦👥 واریز به شخص ثالث', 'callback_data' => 'bank 3rdparty']
        ],
        [
            ['text' => '⚠️ حساب قبلا فرستاده ام!!', 'callback_data' => 'bank ghabli'],
        ]
    ]
]);


#----------------------------------------------------------------------------------> بعد ازپذیرش پیشنهاد آگهی<-----------------------------------------------------------------------------------#  

                            $info =  $res[1];
                            $bot->edit_Message("

❇️ حواله  : {$info['id']} #{$info['type']}_{$info['arz']}

👤 {$info['type']}: {$info['name']}
💶 مقدار ارز: {$info['meghdar_arz']} {$info['arz']}
💰 مبلغ پیشنهادی: {$info['mablagh_pishnehad']} تومان
💳 نحوه پــرداخــت :حواله بانکی حســاب شــخـص از {$info['country']}
✍️ توضیحات : {$info['info']}

$user

✍️ پیشنهادهای ارسال شده :

$All        
          
  🆔👉 @" . CHANNEL, kboard: ['inline_keyboard' => [
                                [['text' => 'اگهی درحال انجام است...', 'url' => 'https://t.me/' . user_bot]]
                            ]], chat_id: $res[1]['channel'], message_id: $res[1]['message']);
                            $bot->editMessageReplyMarkup(['iniline_keyboard' => ['text' => 'انجام شد.', 'callback_date' => 'okkkk']]);


                            break;
                        case 'no':

                            $bot->Db->update('review', ['ok' => 0], ['id' => $dex[2]]);
                            $All = implode(PHP_EOL, $getAllres($bot, $dex[2]));
                            $res = $getinfo($bot, $dex[2]);
                            $info =  $res[1];
                            $info['mablagh_pishnehad'] = number_format($info['mablagh_pishnehad']);
                            $user = $bot->GetinfoUser(user_id: $res[2]['chat_id']);

                            $bot->edit_Message("

❇️ حواله  : {$info['id']} #{$info['type']}_{$info['arz']}

👤 {$info['type']}: {$info['name']}
💶 مقدار ارز :{$info['meghdar_arz']} {$info['arz']}
💰 مبلغ پیشنهادی: {$info['mablagh_pishnehad']} تومان
💳 نحوه پــرداخــت :حواله بانکی حســاب شــخـص از {$info['country']}
✍️ توضیحات : {$info['info']}

$user

✍️ پیشنهادهای ارسال شده :

$All

 🆔👉 @" . CHANNEL, chat_id: $res[1]['channel'], message_id: $res[1]['message'], kboard: [
                                'inline_keyboard' => [
                                    [['text' => '📂 ارسال پیشنهاد ', 'url' => "https://t.me/" . user_bot . "?start=mozayedeget=" . $info['id']]],
                                    //[['text'=>"ثبت حواله جدید ",'url'=>"https://t.me/". user_bot ."?start"]]
                                ]
                            ]);
                         if (isset($info['id'], $res[0]['chat_id'])) {
    $message = "❌ متاسفانه پیشنهاد شما، با شناسه آگهی {$info['id']} پذیرفته نشد. لطفاً نرخ دیگری پیشنهاد دهید.";
    $bot->send_message($message, $res[0]['chat_id']);
}
                            $bot->editMessageReplyMarkup(['iniline_keyboard' => ['text' => 'انجام شد.', 'callback_date' => 'okkkk']]);
                            break;
                        case 'mozakere':
                            $All = implode(PHP_EOL, $getAllres($bot, $dex[2]));
                            $res = $getinfo($bot, $dex[2]);
                            $info =  $res[1];
                            $info['mablagh_pishnehad'] = number_format($info['mablagh_pishnehad']);
                            $user = $bot->GetinfoUser(user_id: $res[2]['chat_id']);

                            $bot->edit_Message("

❇️ حواله  : {$info['id']} #{$info['type']}_{$info['arz']}

👤 {$info['type']}: {$info['name']}
💶 مقدار ارز:{$info['meghdar_arz']} {$info['arz']}
💰 مبلغ پیشنهادی: {$info['mablagh_pishnehad']} تومان
💳 نحوه پــرداخــت :حواله بانکی حســاب شــخـص از {$info['country']}
✍️ توضیحات : {$info['info']}

$user
    
✍️ پیشنهادهای ارسال شده :

$All

 🆔👉 @" . CHANNEL, chat_id: $res[1]['channel'], message_id: $res[1]['message'], kboard: [
                                'inline_keyboard' => [
                                    [['text' => '📂 ارسال پیشنهاد ', 'url' => "https://t.me/" . user_bot . "?start=mozayedeget=" . $info['id']]],
                                    //[['text'=>"ثبت حواله جدید ",'url'=>"https://t.me/". user_bot ."?start"]]
                                ]
                            ]);
                            $bot->editMessageReplyMarkup(['iniline_keyboard' => ['text' => 'انجام شد.', 'callback_date' => 'okkkk']]);
                            $bot->send_message('لطفا پیشنهاد خود را به برای مذاکره ارسال کنید.', new_step: 'pishnehad mozakere ' . $res[0]['chat_id'] . ' ' . $res[1]['id'], kboard: 'enseraf');
                            break;
                    }
                },
                #----------------------------------------------------------------------------------> دکمه «پاسخ» در بخش مذاکره <-----------------------------------------------------------------------------------#
                'mozakere' => function (sisoog $bot) use ($dex) {
                    switch ($dex[1]) {
                        case 'reply':
                            $replyToChatId = $dex[2] ?? null;
                            $adId          = $dex[3] ?? null;

                            if (!$replyToChatId) die($bot->send_message('خطایی رخ داد.'));

                            // پاسخ کاربر دوباره از همان مسیر «مذاکره» ارسال می‌شود تا بحث ادامه پیدا کند
                            $newStep = 'pishnehad mozakere ' . $replyToChatId . ($adId !== null ? ' ' . $adId : '');
                            $bot->send_message('✍️ لطفاً پاسخ خود را وارد کنید:', new_step: $newStep, kboard: 'enseraf');
                            break;
                    }
                },
                'list' => function (sisoog $bot) use ($dex) {
                    switch ($dex[1]) {
                        case 'buy':
                            $filter = 'خریدار';
                            break;
                        case 'sell':
                            $filter = 'فروشنده';
                            break;
                    }
                    if ($bot->Db->has('mozayede', ['stat' => 1, 'type' => $filter]))
                        $All = $bot->Db->select("mozayede", ["id", 'message', 'date', 'meghdar_arz', 'arz', 'mablagh_pishnehad', 'country'], ['stat' => 1, 'type' => $filter]);
                    else die($bot->send_message('موجود نیست.'));
                    foreach ($All as $mozayede) {
                        $mozayede['meghdar_arz']  = number_format($mozayede['meghdar_arz']);
                        $text[] =  "🔸  [حواله {$mozayede['id']}](t.me/" . CHANNEL . "/{$mozayede['message']}): نرخ: {$mozayede['mablagh_pishnehad']} مبلغ: {$mozayede['meghdar_arz']} {$mozayede['arz']}  - به {$mozayede['country']}";
                    }
                    $text = implode(PHP_EOL, $text);
                    $bot->send_message("#لیست درخواستها:" . PHP_EOL . "👈 مناسب برای فروشندگان وخریداران حواله بانکی" . PHP_EOL . $text, parse_mode: 'Markdown', link_preview_options: [
                        'is_disabled' => true
                    ], chat_id: $bot->UserId);
                    // $bot->report($bot->lastResult);
                },
                'edit' => function (sisoog $bot) use ($dex) {
                    switch ($dex[1]) {
                        case 'nerkh':
                            $message = 'نرخ';
                            break;
                        case 'meghdar':
                            $message = 'مقدار';
                            break;
                        case 'info':
                            $message = 'توضیحات';
                            break;
                    }
                    if ($bot->Db->has('mozayede', ['id' => $dex[2], 'stat' => 1, 'chat_id' => $bot->UserId]))
                        $bot->send_message("لطفا $message را بنویسید..", new_step: implode(' ', $dex));
                    else die($bot->send_message('خطایی رخ داد.'));
                },
                

            
                'cron' => function (sisoog $bot) use ($dex, $getAllres, $getinfo) {
                    $date = new DateTime();
                    switch ($dex[1]) {
                        case 'revival':
                            // تمدید آگهی: تاریخ به «اکنون» به‌روزرسانی می‌شود تا چرخه‌ی ۵ روزه از نو آغاز شود
                            // و پرچم «یادآوری ارسال‌شده» صفر می‌شود تا چرخه‌ی یادآوری از نو آغاز شود.
                            $bot->Db->update('mozayede', [
                                'date'     => $date->format('Y-m-d H:i:s'),
                                'reminded' => 0,
                            ], ['id' => $dex[2]]);
                            $bot->editMessageReplyMarkup(['iniline_keyboard' => ['text' => 'انجام شد.', 'callback_date' => 'okkkk']]);

                            $bot->send_message('✅ آگهی شما با موفقیت برای ۵ روز دیگر تمدید شد.');
                            break;
                                     case 'delete':
    $allResults = $getAllres($bot, $dex[2]);
    $All = implode(PHP_EOL, (array) $allResults);

    $res = [0, $mozayede = $bot->Db->get('mozayede', '*', ['id' => $dex[2]])];
    $res[1]['type'] = match ($res[1]['type']) {
        'فروشنده' => 'فروشنده',
        'خریدار' => 'خریدار'
    };

                            $user = $bot->GetinfoUser(user_id: $res[1]['chat_id']);

                            $res[1]['mablagh_pishnehad'] = number_format($res[1]['mablagh_pishnehad']);
                            $col = number_format($res[1]['meghdar_arz'] * $res[1]['mablagh_pishnehad']);

                            $info =  $res[1];
                            $bot->edit_Message("

❇️ حواله  : {$info['id']} #{$info['type']}_{$info['arz']}

👤 {$info['type']}: {$info['name']}
💶 مقدار ارز :{$info['meghdar_arz']} {$info['arz']}
💰 مبلغ پیشنهادی: {$info['mablagh_pishnehad']} تومان
💳 نحوه پــرداخــت :حواله بانکی حســاب شــخـص از {$info['country']}
✍️ توضیحات : {$info['info']}

$user

   🆔👉 @" . CHANNEL, kboard: ['inline_keyboard' => [
                                [['text' => '❌این آگهی توسط کاربر حذف شد..', 'url' => 'https://t.me/' . user_bot]]
                            ]], chat_id: $res[1]['channel'], message_id: $res[1]['message']);
                            $bot->editMessageReplyMarkup(['iniline_keyboard' => ['text' => 'انجام شد.', 'callback_date' => 'okkkk']]);
                            $bot->Db->delete('mozayede' , ['id' => $res[1]['id']]);
                            $bot->Db->delete('review' , ['mozayede' => $res[1]['id']]);

                            break;
                    }
                },
  #---------------------------------------------------------------------------------->فانکشن بانک<-----------------------------------------------------------------------------------#   
    
 'bank' => function ($bot) use ($dex, $getAllres, $getinfo) {

    switch ($dex[1]) {
        case 'account':
            // Fetch the bank account details
            $hesab = $bot->Db->get('account', ['empfanger', 'bankname', 'iban', 'card'], ['chat_id' => $bot->UserId]);
            $user = $bot->Db->get('account', ['name', 'lastname'], ['chat_id' => $bot->UserId]);
            $fullName = $user['name'] . ' ' . $user['lastname'];

            // Update the inline keyboard
            $bot->editMessageReplyMarkup([
                'inline_keyboard' => [
                    ['text' => 'okk', 'callback_data' => 'teststsd'],
                ]
            ]);

            // Compose the message with the bank details
            $bankDetailsMessage = "💰 آخرین اطلاعات بانکی شما:\n\n" . 
                                  "💳 شماره کارت : {$hesab['card']}\n" . 
                                  "💳 شماره شبا یا ایبان نامبر : {$hesab['iban']}\n" . 
                                  "🏦 نام ارز و نام بانک: {$hesab['bankname']}\n" . 
                                  "👥 نام و نام خانوادگی گیرنده: {$hesab['empfanger']}\n\n" . 
                                  "🆔👉 @" . CHANNEL;

            // Send the success message with bank details
            $bot->send_message("👤 مشتری گرامی : $fullName \n\n" . $bankDetailsMessage, $dex[2]);
            break;

        case 'wallet':
            $wallet = $bot->Db->get('account', ['doller', 'euro', 'rial'], ['chat_id' => $bot->UserId]);
            $date_expire = $bot->Db->get('account', 'expire_vip', ['chat_id' => $bot->UserId]);
            $hesab = $bot->Db->get('account', ['empfanger', 'bankname', 'iban', 'card'], ['chat_id' => $bot->UserId]);
            $vip = $bot->Db->get('account', 'Vip', ['chat_id' => $bot->UserId]);

            // Determine VIP status
            $vipStatus = ($vip == 1) ? 'VIP 👑' : 'غیر VIP 🎖🎖';
$user = $bot->Db->get('account', ['name', 'lastname'], ['chat_id' => $bot->UserId]);
            $fullName = $user['name'] . ' ' . $user['lastname'];
            // Format wallet amounts
            $doller_amount = number_format($wallet['doller'], 0, '', '.');
            $euro_amount = number_format($wallet['euro'], 0, '', '.');
            $rial_amount = number_format($wallet['rial'], 0, '', '.');

            // Compose the message with wallet details
            $text = "
👤 مشتری گرامی : $fullName
👤 شماره کاربری : {$bot->UserId} 
🔰 وضعیت کاربر : $vipStatus
✳️ آخرین مهلت تخفیف : $date_expire

➖➖➖➖➖➖➖         
🏦 موجودی کیف پول :
        
💶 موجودی تومان:   [  $rial_amount ریال ] 
💵 موجودی دلار :   [ $doller_amount دلار ] 
💶 موجودی یورو :   [ € $euro_amount ] 

♦️ درخواست تسویه مینیموم 300 دلار یا یورو میباشد  

   
🆔👉 @" . poshtiban;

            // Send the message
            $bot->send_message($text);
            break;
            
case 'userid':

    // متن با Markdown و backticks برای کپی راحت
    $message = "";
    $message .= "👤  شماره کاربری شما (با یک کلیک کپی میشود) : \n 
     `". $bot->UserId . "`\n";
 

    // ارسال پیام بدون کیبورد
   $bot->sendMessageWithMarkdown($message, $bot->UserId);
    break;


            
            
            
            
            

  #----------------------------------------------------------------------------------> اولین پیام هر فانکشن برای استارت<-----------------------------------------------------------------------------------#  
case 'expreincestart': 
    if ($bot->Db->get('account', 'verified', ['chat_id' => $bot->UserId]) != 1) {
        $bot->send_message('❌ برای دسترسی به منو ربات ابتدا باید احراز هویت کنید ...');
        return;  // Prevent further execution
    } else {
        $bot->send_message('❇️ لطفا تجربه خودتان را از سیستم تبادل ارزی ما در یک پیام ارسال کنید.', kboard: [
            'keyboard' => [[['text' => "❌ انصراف"]]],  // Cancel option
            'resize_keyboard' => true
        ], new_step: 'havale experience');
    }
    break;
    case '3rdparty':
      $bot->send_message('✳️ لطفا انتخاب کنید شما فروشنده هستید یا خریدار ؟؟
', kboard: [
            'keyboard' => [
                [['text' => "✳️ فروشنده"]],[['text' => "✳️ خریدار "]],
                [['text' => "❌ انصراف"]]  // Added "✳️ ادامه" button here
            ],
            'resize_keyboard' => true
    ], new_step: 'verify 3rdpartyname');
    break;
        
        case 'sendhesab':                
            if ($bot->Db->get('account', 'verified', ['chat_id' => $bot->UserId]) != 1) {
                $bot->send_message('❌ برای دسترسی به منو ربات ابتدا باید احراز هویت کنید ...');
                return;  // Prevent further execution
            } else {
                $bot->send_message('♦️لطفا شماره کارت گیرنده پول را وارد کنید', kboard: [
            'keyboard' => [[['text' => "❌ انصراف"]]],  // Cancel option
            'resize_keyboard' => true
        ], new_step: 'verify card');
              
            }
            break;
           case 'ghabli':
    if ($bot->Db->get('account', 'verified', ['chat_id' => $bot->UserId]) != 1) {
        $bot->send_message('❌ برای دسترسی به منو ربات ابتدا باید احراز هویت کنید ...');
        return;  // Prevent further execution
    } else {
        $bot->send_message('⚠️ من بدینوسیله موافقت خودم رو اعلام میکنم که به حساب قبلی که تعریف کرده ام , وجه مورد نظر واریز گردد.

✳️شما میتوانید حساب تعریف شده قبلی را از طریق ربات > ⚙️ حساب من >💳 حساب بانکی مشاهده کنید.', kboard: [
            'keyboard' => [
                [['text' => "✳️ موافقم "]],
                [['text' => "❌ انصراف"]]  // Added "✳️ ادامه" button here
            ],
            'resize_keyboard' => true
        ], new_step: 'verify infohesab1');
    }
    break;
            
 case 'ehraz':
    // Retrieve verification status from the database
    $status = $bot->Db->get('account', 'verified', ['chat_id' => $bot->UserId]);

    // Check the status
    if ($status == 'wating') {
        $bot->send_message('مورد شما در حال بررسی است، لطفا صبر کنید...', new_step: null);
    } elseif ($status == 1) {
        $bot->send_message('احراز هویت شما تکمیل و تایید شده است....', new_step: null);
    } elseif ($status == 0) {
        $data = [
            'name'     => '',
            'lastname' => '',
            'number'   => '',
            'info'     => ''
        ];
        $bot->send_message('❇️ لطفا برای احراز هویت ابتدا نام خودتان (فقط به زبان فارسی) وارد کنید', kboard: [  
        'keyboard' => [[['text' => "❌ انصراف"]]], 
            'resize_keyboard' => true
        ], new_step: 'verify name');
    }

    break; 
    }
},
                
                
                
                
                
                
   #----------------------------------------------------------------------------------> فانکشن دکمه ها در بخش ها<-----------------------------------------------------------------------------------#                 
                
                
                
                
                'verify' => function (sisoog $bot) use ($dex, $getAllres, $getinfo) {
                    switch ($dex[1]) {
                     
                       case 'ok':
    $data = $bot->Db->get('account', 'data', ['chat_id' => $dex[2]]);
    $data = json_decode($data, true);

    // Make sure data was decoded properly
    if ($data && json_last_error() === JSON_ERROR_NONE) {

        // Set inviter to NULL if it's "ندارم" or not set
        $data['inviter'] = (isset($data['inviter']) && $data['inviter'] !== 'ندارم') ? $data['inviter'] : null;

        // Update account info
        $bot->Db->update('account', [
            'verified'  => 1,
            'phone'     => $data['number'],
            'inviter'   => $data['inviter'],
            'name'      => $data['name'],
            'lastname'  => $data['lastname'],
            'documents' => $data['photo'],
        ], ['chat_id' => $dex[2]]);

        // Update review info
        $bot->Db->update('review', [
            'name'     => $data['name'],
            'lastname' => $data['lastname'],
        ], ['chat_id' => $dex[2]]);

        // Update inline keyboard
        $bot->editMessageReplyMarkup([
            'inline_keyboard' => [
                [['text' => 'okk', 'callback_data' => 'teststsd']]
            ],
            'chat_id' => $dex[2],
            'message_id' => $message_id // Make sure this variable is available
        ]);

        $bot->editMessageReplyMarkup([
            'inline_keyboard' => [
                [['text' => 'انجام شد.', 'callback_data' => 'okkkk']]
            ],
            'chat_id' => $dex[2],
            'message_id' => $message_id // Again, ensure this exists
        ]);

   $bot->send_message('✅ حراز هویت شما با موفقیت انجام شد,هم اکنون میتوانید از کلیه امکانات ربات استفاده کنید!!😃' , $dex[2] );

    // -----------------------------------------------------------------
    // هماهنگ‌سازی با پروفایل کاربر در اپلیکیشن AVA PAY (اگر قبلاً از
    // طریق اپ هم ثبت‌نام کرده باشد): همان نام/نام‌خانوادگیِ تایید‌شده در
    // ربات، دقیقاً مثل تایید KYC داخل خودِ اپ، نام رسمی پروفایل می‌شود.
    // -----------------------------------------------------------------
    try {
        require_once __DIR__ . '/../includes/fast_mysqli.php';
        $__appDbKyc = avapay_fast_mysqli('localhost', 'aradexch_app', 'VIJVC9Gn5z9Y?D.$', 'aradexch_app', 3);
        if ($__appDbKyc) {
            $__kycTgId  = (string)$dex[2];
            $__kycName  = trim((string)$data['name']);
            $__kycLast  = trim((string)$data['lastname']);
            if ($__kycName !== '' && $__kycLast !== '') {
                $__kycUpd = $__appDbKyc->prepare("UPDATE users SET first_name = ?, last_name = ?, kyc_status = 'approved', updated_at = NOW() WHERE telegram_id = ?");
                if ($__kycUpd) {
                    $__kycUpd->bind_param("sss", $__kycName, $__kycLast, $__kycTgId);
                    $__kycUpd->execute();
                    $__kycUpd->close();
                }

                // --------------------------------------------------------------
                // «تازه‌واردان»: اگر کاربر هنوز در دیتابیس اپ وجود نداشته باشد،
                // UPDATE بالا هیچ ردیفی را تغییر نمی‌داد و نام/نام‌خانوادگی
                // هرگز همگام نمی‌شد. اینجا اگر کاربر نبود، همان‌طور که در مسیر
                // ثبت آگهی انجام می‌شود، یک حساب حداقلی برایش ساخته می‌شود.
                // --------------------------------------------------------------
                $__chk = $__appDbKyc->prepare("SELECT id FROM users WHERE telegram_id = ? LIMIT 1");
                if ($__chk) {
                    $__chk->bind_param("s", $__kycTgId);
                    $__chk->execute();
                    $__exists = $__chk->get_result()->fetch_assoc();
                    $__chk->close();

                    if (!$__exists) {
                        $__kycPhone = trim((string)($data['number'] ?? ''));

                        // شماره حساب و شبا یکتا (هم‌قاعده با ثبت‌نام خودکارِ مسیر آگهی)
                        $__accNo = null;
                        for ($__a = 0; $__a < 20; $__a++) {
                            $__c = 'AV5614' . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
                            $__r = $__appDbKyc->query("SELECT id FROM users WHERE account_number = '" . $__appDbKyc->real_escape_string($__c) . "'");
                            if ($__r && $__r->num_rows === 0) { $__accNo = $__c; break; }
                        }
                        if ($__accNo === null) { $__accNo = 'AV5614' . substr((string)time(), -4); }

                        $__iban = null;
                        for ($__a = 0; $__a < 20; $__a++) {
                            $__c = 'AV55' . strtoupper(bin2hex(random_bytes(5)));
                            $__r = $__appDbKyc->query("SELECT id FROM users WHERE iban_number = '" . $__appDbKyc->real_escape_string($__c) . "'");
                            if ($__r && $__r->num_rows === 0) { $__iban = $__c; break; }
                        }
                        if ($__iban === null) { $__iban = 'AV55' . strtoupper(bin2hex(random_bytes(5))) . time(); }

                        $__ins = $__appDbKyc->prepare("INSERT INTO users
                            (telegram_id, iban_number, account_number, first_name, last_name, phone_number, avatar, kyc_status, created_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, 'default-avatar.png', 'approved', NOW(), NOW())");
                        if ($__ins) {
                            $__ins->bind_param("ssssss", $__kycTgId, $__iban, $__accNo, $__kycName, $__kycLast, $__kycPhone);
                            if (!$__ins->execute()) {
                                error_log('mainbot kyc->app auto-create failed: ' . $__ins->error);
                            }
                            $__ins->close();
                        }
                    }
                }
            }
            $__appDbKyc->close();
        }
    } catch (Throwable $e) {
        error_log('mainbot verify->app profile sync error: ' . $e->getMessage());
    }

    } else {
        // Optional: handle error (log, notify, etc.)
        $bot->sendMessage([
            'chat_id' => $dex[2],
            'text' => "❗️خطا در پردازش اطلاعات کاربر."
        ]);
    }

    break;
                            
                            case 'hesabok':
                            $data = $bot->Db->get('account' , 'data' , ['chat_id' => $dex[2]]);
                            $data = json_decode($data , true);
                            $bot->Db->update('account', ['card' => $data['card'],'iban' => $data['iban'],'bankname' => $data['bankname'],'empfanger' => $data['empfanger'], ], ['chat_id' => $dex[2]]);
                            $bot->editMessageReplyMarkup(['inline_keyboard'=> [
                                ['text' => 'okk' , 'callback_data' => 'teststsd']
                            ] ]);
                             $bot->send_message('حساب در صف تسویه قرار گرفت' );
                            $bot->send_message(' ♻️  حساب شما در صف تسویه قرار گرفت لطفا منتظر دریافت فیش واریزی باشید ...' , $dex[2] );
                            break;
                            
   case '3rdparty':
    // Retrieve user data from the database
    $data = $bot->Db->get('account', 'data', ['chat_id' => $dex[2]]);

    // Decode JSON data
    $data = json_decode($data, true);

    // Extract required fields from the data
    $datapay = [
        '3rdpartyname'   => $data['3rdpartyname'] ?? null,
        '3rdpartyamount' => $data['3rdpartyamount'] ?? null,
        '3rdamountpay'   => $data['3rdamountpay'] ?? null,
        '3rdibaninfo'    => $data['3rdibaninfo'] ?? null,
        '3rdipayname'    => $data['3rdipayname'] ?? null,
        '3rdipayconfirm' => $data['3rdipayconfirm'] ?? null,
        '3rdipayiban'    => $data['3rdipayiban'] ?? null,
    ];

    // Update account information in the database
    $bot->Db->update('account', [
        'datapay' => json_encode($datapay), // Save the data as JSON
    ], ['chat_id' => $dex[2]]);

    // Update inline keyboard markup
    $bot->editMessageReplyMarkup([
        'inline_keyboard' => [
            ['text' => 'okk', 'callback_data' => 'teststsd']
        ]
    ]);

    // Send confirmation message to the user
     $bot->send_message('حساب در صف تسویه قرار گرفت' );
    $bot->send_message(
        '♻️ حساب شما  تایید ودر صف تسویه قرار گرفت لطفا منتظر دریافت فیش واریزی باشید ...',
        $dex[2]
    );
    break;
                            
                            
                               case 'varizshod':
                        
                            $bot->editMessageReplyMarkup(['inline_keyboard'=> [
                                ['text' => 'okk' , 'callback_data' => 'teststsd']
                            ] ]);
                             $bot->send_message('حساب  با موفقیت تسویه شد' );
                            $bot->send_message('✅ پرداخت با موفقیت انجام شد , چناچه پول هنوز به حساب شما نرسیده است لطفا مشکل را به ادمین گزارش دهید . ' , $dex[2] );
                            break;
                            
                             case 'process':
                        
                            $bot->editMessageReplyMarkup(['inline_keyboard'=> [
                                ['text' => 'okk' , 'callback_data' => 'teststsd']
                            ] ]);
                             $bot->send_message('حساب در صف تسویه قرار گرفت' );
                            $bot->send_message('♻️  حساب شما در صف تسویه قرار گرفت لطفا منتظر دریافت فیش واریزی باشید ...' , $dex[2] );
                            break;
                            
                            
                            case 'hesabnot':
                            $bot->editMessageReplyMarkup(['inline_keyboard'=> [
                                ['text' => 'okk' , 'callback_data' => 'teststsd']
                            ] ]);
                            $bot->send_message('☹️ متاسفانه اطلاعات حساب شما به علت نادرست بودن رد شد لطفا دوباره وارد کنید ' , $dex[2] );
                            $bot->editMessageReplyMarkup(['iniline_keyboard' => ['text' => 'انجام شد.', 'callback_date' => 'okkkk']]);
                            $bot->Db->update('account', ['iban' => 'null' ], ['chat_id' => $dex[2]]);
                            
                             break;
                              
                             
                        case 'not':
                            $bot->editMessageReplyMarkup(['inline_keyboard'=> [
                                ['text' => 'okk' , 'callback_data' => 'teststsd']
                            ] ]);
                            $bot->send_message('☹️ متاسفانه احراز هویت شما به یکی از دلایل زیر رد شد.

❌ تصویر بی کیفیت و یا تار است
❌ مدارک خواسته شده صحیح ارسال نشده است
 ❌ شماره تلفن یا نام و نام خانوادگی به درستی وارد نشده است.

✳️لطفا پس از رفع موارد بالا دوباره احراز هویت انجام دهید

.' , $dex[2] );
                            $bot->editMessageReplyMarkup(['iniline_keyboard' => ['text' => 'انجام شد.', 'callback_date' => 'okkkk']]);
                            $bot->Db->update('account', ['verified' => 0 ], ['chat_id' => $dex[2]]);
                        break;
                    }
                },
            ]);
        });
}
#----------------------> Admin <-----------------------#
if ($bot->isAdmin($bot->UserId)) {

    #----------------------> STEP <-----------------------#
    if (!is_null($step)) {
    }
    #----------------------> TEXT <-----------------------#
    if (!is_null($bot->text))
        $bot->handleSwitch($bot->text, [],  function (sisoog $bot) {
            $dex = explode(' ', $bot->text);
            $getAllres = function (sisoog $sisoog, int $id) {
                if ($sisoog->Db->has('review', ['mozayede' => $id])) {
                    $result = $sisoog->Db->select('review', '*' , ['mozayede' => $id, "ORDER" => "id"]);
                    foreach ($result as $res) {
                        if (is_null($res['ok'])) $stat = '🔸';
                        elseif ($res['ok']) $stat = '✅';
                        else $stat = '❌';
                        $date = jdate('j F, G:i', strtotime($res['date']));
                       

                          $res['name'] = (string) $res['name'];
                                   $res['lastname'] = (string) $res['lastname'];
                                $first_lastname_char = mb_substr($res['lastname'], 0, 1, 'UTF-8');
                                $res['meghdar']  = number_format($res['meghdar']);
                               $text[] = "$stat {$res['meghdar']} تومان در $date توسط {$res['name']}.$first_lastname_char";
                            }
                    return $text;
                }
                return [''];
            };
            $getinfo = function (sisoog $sisoog, int $id) {
                $review = ['fawfwa'];
                $mozayede = $sisoog->Db->get('mozayede', '*', ['id' => $id]);
                return [
                    $review,
                    $mozayede
                ];
            };
  #----------------------------------------------------------------------------------> کیف پول<-----------------------------------------------------------------------------------#  
   $bot->handleSwitch($dex[0], [
    'Add' => function (sisoog $bot) use ($dex) {

        function parseAmount($amount, $keepDecimal = false) {
            return $keepDecimal ? floatval($amount) : (int)str_replace('.', '', $amount);
        }

        $currentDate = date('Y-m-d');
        $currency = $dex[1];
        $rawAmount = $dex[2];
        $targetChatId = $dex[3] ?? $bot->UserId;

        switch ($currency) {
            case 'usd':
                $amount = parseAmount($rawAmount);
                $filter = ['doller[+]' => $amount];
                $currencyName = "دلار";
                break;

            case 'euroinviter':
                $amount = parseAmount($rawAmount, true);
                $filter = ['euroinviter[+]' => $amount];
                $currencyName = "یورو";
                break;

            case 'euro':
                $amount = parseAmount($rawAmount, true);
                $filter = ['euro[+]' => $amount];
                $currencyName = "یورو";
                break;

            case 'rial':
                $amount = parseAmount($rawAmount);
                $filter = ['rial[+]' => $amount];
                $currencyName = "تومان";
                break;

            default:
                $bot->reply("❌ ارز مدنظر شما وجود ندارد.");
                return;
        }

        if ($bot->Db->has('account', ['chat_id' => $targetChatId])) {
            // Update the balance
            $bot->Db->update('account', $filter, ['chat_id' => $targetChatId]);

            // Get updated balances
            $currentDollar = $bot->Db->get('account', 'doller', ['chat_id' => $targetChatId]);
            $currenteuroinviter = $bot->Db->get('account', 'euroinviter', ['chat_id' => $targetChatId]);
            $currentEuro = $bot->Db->get('account', 'euro', ['chat_id' => $targetChatId]);
            $currentRial = $bot->Db->get('account', 'rial', ['chat_id' => $targetChatId]);
            $Name = $bot->Db->get('account', 'name', ['chat_id' => $targetChatId]);
            $lastName = $bot->Db->get('account', 'lastname', ['chat_id' => $targetChatId]);

            $formattedRial = number_format($currentRial);

            $actionText = $amount < 0 ? "از حساب شما کسر شد" : "با موفقیت به حساب شما افزوده شد";

            $text = "🔸 #ادمین_موجودی\n\n"
                  . "✅ آیدی شماره {$targetChatId} ، بنام : $Name $lastName \n"
                  . "در تاریخ: $currentDate مقدار {$rawAmount} $currencyName\n"
                  . "$actionText.\n\n"
                  . "💵 موجودی فعلی تومان: $formattedRial تومان\n"
                  . "💰 موجودی فعلی دلار: $currentDollar دلار\n"
                  . "💶 موجودی فعلی یورو: $currentEuro یورو\n"
                  . "💶 موجودی درامد شما: $currenteuroinviter یورو\n\n"

                  . "🔗 @" . CHANNEL;

            $userText = "🔸 #موجودی_حساب\n\n"
                      . "👤 کاربر گرامی: $Name $lastName \n"
                      . "در تاریخ $currentDate مقدار {$rawAmount} $currencyName $actionText.\n\n\n"
                      . "💵 موجودی فعلی تومان: $formattedRial تومان\n"
                      . "💰 موجودی فعلی دلار: $currentDollar دلار\n"
                      . "💶 موجودی فعلی یورو: $currentEuro یورو\n"
                      . "💶 موجودی درامد شما: $currenteuroinviter یورو\n\n\n"
                      . "👈اعتماد شما بی پاسخ نمی ماند \n"
                      . "صرافی آراد \n\n"
                      . "🔗@" . CHANNEL;

            // Send admin + user messages
            $bot->send_message($text);
            $bot->send_message($userText, $targetChatId);
        } else {
            $bot->send_message('❌ یوزر آیدی مدنظر وجود ندارد.');
        
    }

},
  #------------------------------------------------------------------------>  اضافه کردن دستی یوزر یا بن کردن و vip<-----------------------------------------------------------------------------------#  
           
               'user' => function (sisoog $bot) use ($dex) {
                    $add_user = explode(PHP_EOL, $bot->convert($bot->text));
                    if (is_string($add_user[1]) and is_string($add_user[2]) and is_numeric($add_user[3])) {
                        if (!$bot->Db->has('account', ['chat_id' => $add_user[3]]))
                            $bot->Db->insert('account', [
                                'chat_id' => $add_user[3],
                                'name'    => $add_user[1],
                                'lastname' => $add_user[2],
                            ]);
                        else {
                            $bot->Db->update('account', [
                                'name' => $add_user[1],
                                'lastname' => $add_user[2]
                            ], ['chat_id' => $add_user[3]]);
                        }
                        $bot->send_message('با موفقیت انجام شد');
                    } else $bot->send_message('مقادیر مشکل دارد.');
                },
                'ban' => function (sisoog $bot) use ($dex) {
                    $userban = $bot->convert($bot->text);
                    if (is_numeric($dex[1]) and is_numeric($dex[2])) {
                        if ($bot->Db->has('account', ['chat_id' => $dex[1]])) {
                            $date = new DateTime("+ {$dex[2]} hours");
                            $bot->Db->update('account', [
                                'date_ban' => $date->format('Y-m-d H:i:s'),
                                'mozayede_Cancell[+]' => 1
                                
                            ], ['chat_id' => $dex[1]]);
                            $bot->send_message('با موفقیت انجام شد.');
                        } else $bot->send_message('کاربر وجود ندارد.');
                    } else $bot->send_message('مقادیر مشکل دارد.');
                },
'vip' => function (sisoog $bot) use ($dex) {
    // Validate inputs
    if (isset($dex[1]) && is_numeric($dex[1])) {
        $chatId = $dex[1];
        $vipAction = isset($dex[2]) ? $dex[2] : null;

        // Check if user exists
        if ($bot->Db->has('account', ['chat_id' => $chatId])) {
            // Fetch current user data
            $user = $bot->Db->get('account', ['name', 'lastname'], ['chat_id' => $chatId]);
            $fullName = $user['name'] . ' ' . $user['lastname'];

            if ($vipAction === 'immer') {
                // Update for permanent VIP
                $bot->Db->update('account', [
                    'expire_vip' => null,
                    'Vip' => 1
                ], ['chat_id' => $chatId]);

                $bot->send_message('کاربر با موفقیت به VIP دائمی ارتقا یافت.');
                $bot->send_message("👤 مشتری گرامی: $fullName!\n\n❇️ با افتخار به اطلاع شما می‌رسانیم که شما به صورت دائمی به جمع کاربران ویژه ما اضافه شدید.\n\n🆔👉 @" . CHANNEL, $chatId);
            } elseif ($vipAction === 'remove') {
                // Remove VIP status
                $bot->Db->update('account', [
                    'expire_vip' => null,
                    'Vip' => 0
                ], ['chat_id' => $chatId]);

                $bot->send_message('وضعیت VIP کاربر حذف شد.');
                $bot->send_message("👤 مشتری گرامی: $fullName!\n\n⚠️ وضعیت کاربری ویژه شما لغو شد و شما دیگر جزو کاربران VIP نمی‌باشید.\n\n🆔👉 @" . CHANNEL, $chatId);
            } elseif (DateTime::createFromFormat('d.m.Y', $vipAction)) {
                // Regular VIP with expiration
                $vipExpireDateObj = DateTime::createFromFormat('d.m.Y', $vipAction);
                $bot->Db->update('account', [
                    'expire_vip' => $vipExpireDateObj->format('Y-m-d'),
                    'Vip' => 1
                ], ['chat_id' => $chatId]);

                $bot->send_message('کاربر با موفقیت VIP شد.');
                $formattedExpireDate = $vipExpireDateObj->format('d.m.Y');
                $bot->send_message("👤 مشتری گرامی: $fullName!\n\n❇️ با افتخار به اطلاع شما می‌رسانیم که شما به جمع کاربران ویژه ما اضافه شدید و تا تاریخ $formattedExpireDate هر تراکنشی داشته باشید از تخفیف 40٪ کمیسیون برخوردار خواهید شد.\n\n🆔👉 @" . CHANNEL, $chatId);
            } else {
                $bot->send_message('مقادیر وارد شده مشکل دارد.');
            }
        } else {
            $bot->send_message('کاربر وجود ندارد.');
        }
    } else {
        $bot->send_message('مقادیر وارد شده مشکل دارد.');
    }
},
            

 'Up' => function (sisoog $bot) use ($dex, $getAllres, $getinfo) {
    $dex[1] = $bot->convert($dex[1]);
    if (is_numeric($dex[1])) {
        $date = new DateTime();
        if ($bot->Db->has('mozayede', ['id' => $dex[1]])) {
            $All = implode(PHP_EOL, $getAllres($bot, $dex[1]));
            $res = $getinfo($bot, $dex[1]);
            $user = $bot->GetinfoUser(user_id: $res[1]['chat_id']);
            $info = $res[1];
            $originalPrice = $info['mablagh_pishnehad'];
            $info['mablagh_pishnehad'] = number_format($info['mablagh_pishnehad']);
            
            $accountInfo = $bot->Db->get('account', ['name', 'lastname'], ['chat_id' => $res[1]['chat_id']]);   
            $fullName = $accountInfo['name'] . ' ' . $accountInfo['lastname'];
            $inviterChatId = $bot->Db->get('account', 'inviter', ['chat_id' => $info['chat_id']]);
            $accountInfoinviter = $bot->Db->get('account', ['name', 'lastname'], ['chat_id' => $inviterChatId]);
            $fullNameinviter = $accountInfoinviter['name'] . ' ' . $accountInfoinviter['lastname'];

            // ========== 1. به‌روزرسانی در دیتابیس ربات ==========
            $bot->Db->update('mozayede', [
                'date' => $date->format('Y-m-d H:i:s'),
                'stat' => 0,
                'ok' => 1,
            ], ['id' => $dex[1]]);
            
            $bot->Db->update('account', ['mozayede_ok[+]' => 1], ['chat_id' => $info['chat_id']]);
            
            // پورسانت به معرف
            if (!empty($inviterChatId) && is_numeric($inviterChatId)) {
                $bot->Db->update('account', ['euroinviter[+]' => 0.5], ['chat_id' => $inviterChatId]);
                $bot->send_message("👥 مشتری گرامی : $fullNameinviter

مبلغ ۰.۵۰ سنت به عنوان سهم پورسانت شما از معامله انجام‌شده توسط $fullName از اعضای معرفی‌شده شما، به حساب شما واریز گردید. از همکاری شما صمیمانه سپاسگزاریم.

🟢 برای افزایش درآمدتان، ما را به دوستان بیشتری معرفی کنید و از مزایای بیشتر بهره‌مند شوید!

🆔👉 @" . CHANNEL, $inviterChatId);
            }

            // ========== 2. به‌روزرسانی در دیتابیس سایت (DB2) - تغییر status به completed ==========
            $db2_host = 'localhost';
            $db2_user = 'aradexch_app';
            $db2_pass = 'VIJVC9Gn5z9Y?D.$';
            $db2_name = 'aradexch_app';
            
            require_once __DIR__ . '/../includes/fast_mysqli.php';
            $db2 = avapay_fast_mysqli($db2_host, $db2_user, $db2_pass, $db2_name, 3);
            
            if ($db2) {
                $telegram_id = $info['chat_id'];
                
                $userSql = "SELECT id FROM users WHERE telegram_id = ?";
                $userStmt = $db2->prepare($userSql);
                $userStmt->bind_param("s", $telegram_id);
                $userStmt->execute();
                $userResult = $userStmt->get_result();
                
                if ($userRow = $userResult->fetch_assoc()) {
                    $site_user_id = $userRow['id'];
                    
                    // تبدیل نوع آگهی
                    $ad_type = (strpos($info['type'], 'خرید') !== false || strpos($info['type'], 'buy') !== false) ? 'buy' : 'sell';
                    
                    // تبدیل ارز
                    $currency = strtoupper($info['arz'] ?? 'USDT');
                    $currencyMap = [
                        'تتر' => 'USDT', 'usdt' => 'USDT', 'USDT' => 'USDT',
                        'دلار' => 'USD', 'usd' => 'USD', 'USD' => 'USD',
                        'یورو' => 'EUR', 'eur' => 'EUR', 'EUR' => 'EUR',
                        'تومان' => 'IRR', 'irr' => 'IRR', 'IRR' => 'IRR'
                    ];
                    $currency = $currencyMap[$currency] ?? 'USDT';
                    
                    $amount = floatval(preg_replace('/[^0-9.]/', '', $info['meghdar_arz'] ?? '0'));
                    $price_per_unit = floatval($originalPrice);
                    
                    // به‌روزرسانی وضعیت آگهی به 'completed'
                    $updateSql = "UPDATE user_ads 
                                  SET status = 'completed', updated_at = NOW() 
                                  WHERE user_id = ? AND type = ? AND currency = ? 
                                  AND amount = ? AND price_per_unit = ? AND status = 'active'
                                  ORDER BY id DESC LIMIT 1";
                    
                    $updateStmt = $db2->prepare($updateSql);
                    $updateStmt->bind_param("issdd", $site_user_id, $ad_type, $currency, $amount, $price_per_unit);
                    
                    if ($updateStmt->execute() && $updateStmt->affected_rows > 0) {
                        error_log("✅ Ad completed in website DB - User ID: $site_user_id, Mozayede ID: " . $dex[1]);
                    } else {
                        // Fallback: آخرین آگهی فعال کاربر را آپدیت کن
                        $fallbackSql = "UPDATE user_ads SET status = 'completed', updated_at = NOW() 
                                        WHERE user_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1";
                        $fallbackStmt = $db2->prepare($fallbackSql);
                        $fallbackStmt->bind_param("i", $site_user_id);
                        $fallbackStmt->execute();
                        error_log("🔄 Fallback: Completed latest active ad for user ID: $site_user_id");
                        $fallbackStmt->close();
                    }
                    $updateStmt->close();
                } else {
                    error_log("❌ User not found in website DB - Telegram ID: " . $telegram_id);
                }
                $userStmt->close();
            } else {
                error_log("❌ DB2 Connection failed (timeout or unreachable)");
            }
            if ($db2) $db2->close();

            // ========== 3. ویرایش پیام در کانال ==========
            $bot->edit_Message("
❇️ حواله : {$info['id']} #{$info['type']}_{$info['arz']}

👤 {$info['type']}: {$info['name']}
💶 مقدار ارز: {$info['meghdar_arz']} {$info['arz']}
💰 مبلغ پیشنهادی: {$info['mablagh_pishnehad']} تومان
💳 نحوه پــرداخــت :حواله بانکی حســاب شــخـص از {$info['country']}
✍️ توضیحات : {$info['info']}

$user

✍️ پیشنهادهای ارسال شده :

$All

✅ وضعیت: این معامله با موفقیت انجام شد.

🆔👉 @" . CHANNEL, chat_id: $res[1]['channel'], message_id: $res[1]['message'], kboard: [
                'inline_keyboard' => [
                    [['text' => '✅ این درخواست با موفقیت انجام شد', 'callback_data' => 'done_' . $dex[1]]]
                ]
            ]);
            
            // ========== 4. ارسال پیام موفقیت به کاربر ==========
            $bot->send_message(
                "👤 مشتری گرامی: $fullName\n\n" .
                "✅ تراکنش شما به شماره آگهی: {$info['id']} با موفقیت انجام شد.\n\n" .
                "=======================\n" .
                "🔸 نظرات و پیشنهادات شما برای ما بسیار حائز اهمیت می‌باشد، لطفاً هرگونه نظر، انتقاد و یا پیشنهاد خودتان را از طریق دکمه 'ارسال نظر' ارسال کنید.\n\n" .
                "=======================\n\n" .
                "♦️ اعتماد شما بی‌پاسخ نمی‌ماند.\n\n" .
                "☑️ صرافی آراد 🔚",
                $res[1]['chat_id'],
                kboard: [
                    'inline_keyboard' => [
                        [['text' => '📝 ارسال نظر', 'callback_data' => 'bank_expreincestart']]
                    ],
                    'resize_keyboard' => true 
                ]
            );
            
            // ارسال لینک آگهی در سایت
            $bot->send_message(
                "🔗 مشاهده آگهی تکمیل شده در سایت:\nhttps://aradexchange.com/ledor/arad.php",
                $res[1]['chat_id']
            );

            $bot->send_message('✅ معامله با موفقیت انجام شد...');
        } else {
            $bot->send_message('❌ مزایده وجود ندارد.');
        }
    } else {
        $bot->send_message('❌ مقادیر مشکل دارد.');
    }
},
               'cancell' => function (sisoog $bot) use ($dex, $getAllres, $getinfo) {
                    $dex[1] = $bot->convert($dex[1]);
                    if (is_numeric($dex[1])) {
                        if ($bot->Db->has('mozayede', ['id' => $dex[1]])) {
                            $date = $date = new DateTime();


                            $All = implode(PHP_EOL, $getAllres($bot, $dex[1]));
                            $res = $getinfo($bot, $dex[1]);
                            $user = $bot->GetinfoUser(user_id: $res[1]['chat_id']);
                            $info =  $res[1];
                            $info['mablagh_pishnehad'] = number_format($info['mablagh_pishnehad']);
                            $bot->Db->update('mozayede', [
                                'date' => $date->format('Y-m-d H:i:s'),
                                'stat' => 1,
                            ], ['id' => $dex[1]]);
                            // $bot->Db->update('account' , ['mozayede_Cancell[+]' => 1] , ['chat_id' => $info['chat_id']]);

                            $bot->edit_Message("

❇️ حواله  : {$info['id']} #{$info['type']}_{$info['arz']}

👤 {$info['type']}: {$info['name']}
💶 مقدار ارز: {$info['meghdar_arz']} {$info['arz']}
💰 مبلغ پیشنهادی: {$info['mablagh_pishnehad']} تومان
💳 نحوه پــرداخــت :حواله بانکی حســاب شــخـص از {$info['country']}
✍️ توضیحات : {$info['info']}

$user

✍️ پیشنهادهای ارسال شده :

$All

🆔👉@" . CHANNEL, chat_id: $res[1]['channel'], message_id: $res[1]['message'], kboard: [
                                'inline_keyboard' => [
                                    [['text' => '📂 ارسال پیشنهاد ', 'url' => "https://t.me/" . user_bot . "?start=mozayedeget=" . $info['id']]],
                                    //[['text'=>"ثبت حواله جدید ",'url'=>"https://t.me/". user_bot ."?start"]]
                                ]
                            ]);
                            $bot->send_message('با موفقیت انجام شد...');
                        } else $bot->send_message('مزایده وجود ندارد.');
                    } else $bot->send_message('مقادیر مشکل دارد.');
                }
            ]);
        });


  #----------------------------------------------------------------------------------> قیمت ارز<-----------------------------------------------------------------------------------#  

    if (!is_null($data)) {
    }
}
if (!is_null($text))
    $bot->handleSwitch($text, [
        'bot' => function (sisoog $bot) {
        },
        '/price' => function (sisoog $bot) {
            // با timeout کوتاه تا اگر سرویس قیمت کند/قطع بود، ورکر بلاک نشود
            $__ctx = stream_context_create(['http' => ['timeout' => 8], 'https' => ['timeout' => 8]]);
            $data = @file_get_contents('https://one-api.ir/price/?token=964733:656625fa76a33', false, $__ctx);
            if ($data === false) { $bot->send_message('⚠️ دریافت قیمت لحظه‌ای موقتاً در دسترس نیست. کمی بعد دوباره تلاش کنید.'); return; }
            $data = json_decode($data, true);
            if (!is_array($data) || empty($data['result'])) { $bot->send_message('⚠️ دریافت قیمت لحظه‌ای موقتاً در دسترس نیست. کمی بعد دوباره تلاش کنید.'); return; }
            unset($data['result']['coin_blubber'], $data['result']['coin_retail'], $data['result']['oil'], $data['result']['metals'],  $data['result']['result.commodity']);
            $data = $data['result'];
            $arz = $data['currencies'];
            $seke = $data['coin'];
            $tala = $data['gold'];
            $text = "
🔴 ارز ها:

دلار💵: {$arz['dollar']['p']} تـومـان 
یورو💶: {$arz['eur']['p']} تـومـان 
درهم🇦🇪  امارات: {$arz['aed']['p']} تـومـان 
 پوند💷: {$arz['gbp']['p']} تـومـان 
لیر ترکیه🇹🇷: {$arz['try']['p']} تـومـان 
 دلار 🇨🇦کانادا:  {$arz['cad']['p']} تـومـان 
 دلار 🇦🇺استرالیا {$arz['aud']['p']} تـومـان 

🔴 طلا:

🌕24 عیار {$tala['geram24']['p']} تـومـان 
🌕18 عیار: {$tala['geram18']['p']} تـومـان 
🌕دسته دوم: {$tala['daste_doom']['p']} تـومـان 
🌕مثقال: {$tala['mesghal']['p']} تـومـان 
🌕آب شده: {$tala['ab_shode']['p']} تـومـان 
💰انس طلا: {$tala['ons']['p']}  تـومـان 
✨انس نقره: {$tala['silver']['p']}  تـومـان 
🌫انس پلاتین: {$tala['platinum']['p']}  تـومـان 
نس پالادیوم: {$tala['palladium']['p']}  تـومـان 

🔴 سکه:

🌕گرمی: {$seke['gerami']['p']}  تـومـان 
🌕ربع سکه: {$seke['rob']['p']}  تـومـان 
🌕نیم سکه: {$seke['nim']['p']}  تـومـان 
🌕بهار آزادی: {$seke['sekeb']['p']}  تـومـان 
🌕امامی: {$seke['sekee']['p']}  تـومـان 

            
🆔👉@" . CHANNEL;
            $bot->send_message($text);
        },


    ],  function (sisoog $bot) {
    });


if (isset($bot->update->message->new_chat_members)) {
    preg_match('/^(\d{5,12}):[\w\d_-]{30,50}$/', token, $match);
    if ($bot->update->message->new_chat_members[0]->id == $match[1]) {
        $bot->send_message('this group id: ' . $chat_id);
        $bot->report('join    ' . $chat_id);
    }
}

#----------------------> news <-----------------------#
if (!is_null($data)) {
    // your logic here, if needed
}

if (!is_null($text)) {
    $bot->handleSwitch($text, [
        'bot' => function (sisoog $bot) {
            // Optional: logic for 'bot' case
        },

        '📈 درآمد شما' => function (sisoog $bot) {
            $user = $bot->Db->get('account', ['name', 'lastname', 'euroinviter'], ['chat_id' => $bot->UserId]);
            $fullName = $user['name'] . ' ' . $user['lastname'];
            $euro_amount = number_format($user['euroinviter'], 2, '.', '');

            $text = "
👤 مشتری گرامی : $fullName
👤 شماره کاربری شما: {$bot->UserId}

    ➖➖➖➖➖➖➖         
🏦 موجودی درآمد شما :

        💶 موجودی یورو :   [ € $euro_amount ] 

    ➖➖➖➖➖➖➖

♦️ شرایط لازم :

❇️ اعضا که دعوت کردید حتما باید آیدی عددی شمارا هنگام احراز هویت وارد کنند.
❇️ شما و دوستانتان حتما باید در کانال عضو باشید.
❇️ بابت هر تراکنش موفقی که کاربر دعوت شده انجام دهد، مبلغ ۵۰ سنت به کیف پول شما در همان لحظه افزوده می‌شود.

🔴 سقف تسویه بین ۵ تا ۱۰ یورو می‌باشد.

✳️ شما نیز میتوانید کد معرف شخصی خودتان را از منو از قسمت 'لینک دعوت دوستان' دریافت کنید.

🆔👉 @" . poshtiban;

            $bot->send_message($text);
        },

        '/invite' => function (sisoog $bot) {
            $wallet = $bot->Db->get('account', ['euroinviter'], ['chat_id' => $bot->UserId]);
            $hesab = $bot->Db->get('account', ['empfanger', 'bankname', 'iban', 'card'], ['chat_id' => $bot->UserId]);
            $user = $bot->Db->get('account', ['name', 'lastname'], ['chat_id' => $bot->UserId]);
            $fullName = $user['name'] . ' ' . $user['lastname'];
            $euro_amount = number_format($wallet['euroinviter'], 2, '.', '');

            $text2 = "📥 پیام دعوت 📥

✳️🎉 به صرافی آراد بپیوندید!
با صرافی آراد، تجربه‌ای متفاوت از خرید و فروش ارزها را تجربه کنید.
🚀 نقل و انتقال سریع، امن و بدون دردسر
💸 بهترین نرخ‌ها، پشتیبانی حرفه‌ای و محیطی کاملاً کاربرپسند

    ➖➖➖➖➖➖➖
👥 کد کاربری معرف  : {$bot->UserId}
 
💡 حتماً موقع احراز هویت، کد معرف من را وارد کنید تا هم شما و هم من از مزایای بیشتر و سیستم تبادل ارزی صرافی آراد بهره مند شویم.

🆔 آدرس کانال : 👉@" . CHANNEL;

            
            $bot->send_message($text2);
        }
    ]);
}
        
  #----------------------------------------------------------------------------------> اطلاعات یوزر<-----------------------------------------------------------------------------------#  
$pdo = $bot->Db; // Use the existing database connection from your bot

// Get Telegram update content
$content = file_get_contents("php://input");
$update = json_decode($content, true);

// Make sure it's a message with text
if (isset($update['message']) && isset($update['message']['text'])) {
    $message = $update['message'];
    $chatId = $message['chat']['id'];
    $text = trim($message['text']);

    // Check if the message starts with "Id "
    if (preg_match('/^Id\s+(.+)/i', $text, $matches)) {
        $search = $matches[1];

        // Query the database using Medoo-style syntax, adding a condition for verified = 1
        $results = $bot->Db->select('account', [
            'name', 'lastname', 'network', 'wallet_adress',
            'chat_id', 'username', 'card', 'iban', 'bankname', 'empfanger',
            'phone', 'Vip', 'doller', 'euro', 'rial', 'data',
            'mozayede_ok', 'mozayede_Cancell', 'date_ban'
        ], [
            'OR' => [
                'name[~]'     => $search,
                'lastname[~]' => $search,
                'chat_id[~]'  => $search
            ],
            'verified' => 1 // Filter by verified = 1
        ]);

        // Format the response
        if ($results) {
            $response = "";

            // Get statistics for verified users
            $verifiedCount = $bot->Db->count('account', [
                'verified' => 1
            ]);


            // Get the total number of user IDs
            $totalUserIds = $bot->Db->count('account', 'id'); // Count the 'id' column

            // Add the verified user stats, total user count, and total user ID count to the response
            $response .= "\n📊 Verified User Stats:\n";
            $response .= "Total Verified Users: " . $verifiedCount . "\n";

            $response .= "\n📋 Total User IDs Count:\n";
            $response .= "Total User IDs: " . $totalUserIds . "\n"; // Display the count of user IDs
 $response .= "\n============================\n";
            // Loop through the results and add user information
            foreach ($results as $result) {
                $vipStatus = ($result['Vip'] == 1) ? 'VIP 👑' : 'Not VIP 🎖';

                $response .= "\n📝 User Info:\n";
                $response .= "👤 First Name: " . $result['name'] . "\n";
                $response .= "👤 Last Name: " . $result['lastname'] . "\n";
                $response .= "👤 Status: " . $vipStatus . "\n";
                $response .= "🆔 Chat ID: `" . $result['chat_id'] . "`\n";

                if (!empty($result['phone'])) {
                    $response .= "📞 Phone: [Click](https://t.me/+" . htmlspecialchars($result['phone']) . ")\n";
                }

                if (!empty($result['username'])) {
                    $response .= "👤 Username: [@" . htmlspecialchars($result['username']) . "](https://t.me/" . htmlspecialchars($result['username']) . ")\n";
                }

                $response .= "\n💰 Wallet Balance:\n";
                $response .= "💴 Rial: `" . number_format($result['rial']) . "`\n";
                $response .= "💵 Dollar: `" . number_format($result['doller'], 0, '', ',') . "`\n";
                $response .= "💶 Euro: `" . number_format($result['euro'], 0, '', ',') . "`\n";

                $response .= "\n🏦 Bank Info:\n";
                $response .= "💳 Card: `" . $result['card'] . "`\n";
                $response .= "🏦 IBAN: `" . $result['iban'] . "`\n";
                $response .= "🏦 Bank Name: `" . $result['bankname'] . "`\n";
                $response .= "👤 Account Holder: `" . $result['empfanger'] . "`\n";

                $response .= "\n🔐 Crypto Wallet:\n";
                $response .= "📬 Address: `" . $result['wallet_adress'] . "`\n";
                $response .= "🔗 Network: `" . $result['network'] . "`\n";

               
            }

            // Telegram message limit
            if (strlen($response) > 4000) {
                $response = substr($response, 0, 3900) . "\n\n⚠️ Message too long. Some data was truncated.";
            }
        } else {
            $response = "❌ No results found for '$search'.";
        }

        // Send message to Telegram
        $url = "https://api.telegram.org/bot{$bot->token}/sendMessage";
        $postData = [
            'chat_id' => $chatId,
            'text' => $response,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => true
        ];

        $options = [
            'http' => [
                'method'  => 'POST',
                'header'  => 'Content-Type: application/x-www-form-urlencoded',
                'content' => http_build_query($postData)
            ]
        ];

        file_get_contents($url, false, stream_context_create($options));
    }


 #----------------------------------------------------------------------------------> تجربه مشتریان<-----------------------------------------------------------------------------------#  


}

function broadcastMessageMedoo($bot, $text, $photoFileId = null) {
    // گرفتن همه chat_id ها از جدول account
    $allUsers = $bot->Db->select('account', 'chat_id');

    if(empty($allUsers)){
        return "⚠️ هیچ کاربری برای ارسال پیام یافت نشد.";
    }

    foreach($allUsers as $chatId){
        if($photoFileId){
            $bot->sendPhoto($chatId, $photoFileId, $text); // ارسال عکس با متن
        } else {
            $bot->sendMessage($chatId, $text); // ارسال متن ساده
        }
    }

    return "✅ پیام همگانی به ".count($allUsers)." کاربر ارسال شد.";
}


// بخش ادمین و ارسال اخبار
// --------------------------------------------------------------------------------

require_once __DIR__ . '/php/adminpanel.php'; // مسیر دقیق را بر اساس ساختار پوشه‌های خود تنظیم کنید.

// منوی مدیریت اختصاصی ادمین 5330629504 (ویرایش اطلاعات کاربر با همگام‌سازی
// در AvaPay، مدیریت وضعیت آگهی، و ارسال پیام تکی/همگانی).
// قبل از پنل قدیمی اجرا می‌شود تا با آن تداخل نداشته باشد؛ اگر پیام مربوط
// به این پنل نبود، false برمی‌گرداند و روال عادی ادامه پیدا می‌کند.
require_once __DIR__ . '/php/manage_panel.php';
if (handle_manage_panel($bot)) {
    exit;
}

// 2. منطق ادمین را اجرا کنید.
$stop_main_logic = handle_admin_logic($bot);

// 3. اگر دستور ادمین اجرا شد، از ادامه کار جلوگیری کنید.
if ($stop_main_logic) {
    exit; 
}


// یوزر آیدی
// --------------------------------------------------------------------------------

// فرض می‌کنیم کلاس sisoog قبلاً تعریف شده است

if (!is_null($text)) {
    // بررسی کنیم که آیا متن با /start شروع شده و پارامتر خاصی دارد
    if (strpos($text, '/start') === 0) {
        // استخراج پارامتر بعد از /start
        $parts = explode(' ', $text);
        if (isset($parts[1]) && $parts[1] === 'userid') {
            // اگر پارامتر userid بود، دستور /userid را اجرا کن
            sendUserIdInfo($bot);
        }
        // پارامتر ref_<CODE> → لینک دعوت زیرمجموعه‌گیری
        if (isset($parts[1]) && strpos($parts[1], 'ref_') === 0) {
            $__refCode = substr($parts[1], 4);
            sendReferralEntryLink($bot, $__refCode);
        }
    }
    
    // لیست دستورات و متونی که باید یکسان اجرا شوند
    $userIdCommands = [
        '/userid',
        '📲 Ava Pay',
        '📲AVA PAY',
        'ava pay',
        'Ava Pay',
        'AVA PAY',
        '📲 ایوا پی',
        'ایوا پی',
        'ava pay userid'
    ];
    
    // ساخت آرایه برای handleSwitch
    $handlers = [];
    foreach ($userIdCommands as $command) {
        $handlers[$command] = function (sisoog $bot) {
            sendUserIdInfo($bot);
        };
    }
    
    // ادامه handleSwitch برای دستورات دیگر
    $bot->handleSwitch($text, $handlers);
}

// تابع کمکی برای اجرای دستور
function sendUserIdInfo($bot)
{
    global $update;

    $message = "🆔 *AVA ID شما*\n\n"
             . "`{$bot->UserId}`\n\n"
             . "این شناسه، *کد اختصاصی شما در اپلیکیشن AVA PAY* می‌باشد.\n\n"
             . "🔐 *نکات امنیتی مهم:*\n"
             . "• از در اختیار قرار دادن این آیدی به اشخاص دیگر جداً خودداری کنید.\n"
             . "• مسئولیت حفظ و نگهداری این شناسه بر عهده کاربر می‌باشد.\n\n"
             . "📲 برای ورود مستقیم به داشبورد خودتان (بدون نیاز به کد تایید یا ثبت‌نام)، فقط روی دکمه‌ی زیر بزنید.";

    // دکمه از نوع url (نه web_app): عمداً به‌صورت مرورگر معمولیِ داخل
    // تلگرام باز می‌شود، نه Mini App. چون initData اینجا در دسترس نیست،
    // خودمان یک توکن یک‌بارمصرف کوتاه‌عمر می‌سازیم و در دیتابیس اپ
    // (aradexch_app) ذخیره می‌کنیم؛ خودِ telegram_entry.php (هم صفحه هم
    // API ورود در همین یک فایل) همان توکن را می‌گیرد و ورود خودکار را
    // انجام می‌دهد.
    $loginUrl = 'https://aradexchange.com/ledor/telegram_entry.php';
    try {
        $tgFirst = trim((string)($update->message->from->first_name ?? ''));
        $tgLast  = trim((string)($update->message->from->last_name ?? ''));

        require_once __DIR__ . '/../includes/fast_mysqli.php';
        $appDb = avapay_fast_mysqli('localhost', 'aradexch_app', 'VIJVC9Gn5z9Y?D.$', 'aradexch_app', 3);
        if ($appDb) {
            $appDb->set_charset('utf8mb4');
            $appDb->query("CREATE TABLE IF NOT EXISTS telegram_login_tokens (
                id INT PRIMARY KEY AUTO_INCREMENT,
                token VARCHAR(64) NOT NULL,
                telegram_id VARCHAR(64) NOT NULL,
                first_name VARCHAR(255) NOT NULL DEFAULT '',
                last_name VARCHAR(255) NOT NULL DEFAULT '',
                used TINYINT(1) NOT NULL DEFAULT 0,
                expires_at DATETIME NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_token (token)
            )");

            $loginToken = bin2hex(random_bytes(32));
            $expiresAt  = date('Y-m-d H:i:s', time() + 300); // ۵ دقیقه اعتبار

            $tokIns = $appDb->prepare("INSERT INTO telegram_login_tokens (token, telegram_id, first_name, last_name, expires_at) VALUES (?, ?, ?, ?, ?)");
            if ($tokIns) {
                $chatIdStr = (string)$bot->UserId;
                $tokIns->bind_param("sssss", $loginToken, $chatIdStr, $tgFirst, $tgLast, $expiresAt);
                if ($tokIns->execute()) {
                    $loginUrl .= '?login_token=' . $loginToken;
                }
                $tokIns->close();
            }
            $appDb->close();
        }
    } catch (Exception $e) {
        error_log('Failed to create telegram_login_token: ' . $e->getMessage());
    }

    $keyboard = [
        'inline_keyboard' => [
            [
                [
                    'text' => '🌐 ورود مستقیم به AVA PAY',
                    'url'  => $loginUrl
                ]
            ]
        ]
    ];

    // ارسال پیام با مارکدون
    $bot->sendMessageWithMarkdown($message, $bot->UserId, $keyboard);

    // ثبت کامنت با مدیریت خطا
    try {
        $bot->Db->insert('comments', [
            'chat_id'    => $bot->UserId,
            'comment'    => $bot->Text,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    } catch (Exception $e) {
        // لاگ خطا اما تجربه کاربری را مختل نکن
        error_log("Failed to insert comment: " . $e->getMessage());
    }
}

/**
 * ورود از طریق لینک دعوت زیرمجموعه‌گیری: /start ref_<CODE>
 * دقیقاً مثل sendUserIdInfo یک توکن یک‌بارمصرف می‌سازد و کاربر را
 * مستقیم وارد اپ می‌کند، با این تفاوت که کد معرف را هم در همان
 * توکن ذخیره می‌کند تا telegram_entry.php هنگام ثبت‌نام کاربر جدید
 * از آن استفاده کند (واریز جایزه‌ی خوش‌آمدگویی به معرف).
 */
function sendReferralEntryLink($bot, $refCode)
{
    global $update;

    $refCode = preg_replace('/[^A-Za-z0-9]/', '', (string)$refCode);

    $message = "🎁 *دعوت‌نامه‌ی AVA PAY*\n\n"
             . "شما با لینک دعوتِ یکی از کاربران AVA PAY وارد شدید!\n"
             . "با ثبت‌نام، هر دوی شما از مزایای برنامه‌ی زیرمجموعه‌گیری بهره‌مند می‌شوید.\n\n"
             . "📲 برای ورود / ثبت‌نام، فقط روی دکمه‌ی زیر بزنید.";

    $loginUrl = 'https://aradexchange.com/ledor/telegram_entry.php';
    try {
        $tgFirst = trim((string)($update->message->from->first_name ?? ''));
        $tgLast  = trim((string)($update->message->from->last_name ?? ''));

        require_once __DIR__ . '/../includes/fast_mysqli.php';
        $appDb = avapay_fast_mysqli('localhost', 'aradexch_app', 'VIJVC9Gn5z9Y?D.$', 'aradexch_app', 3);
        if ($appDb) {
            $appDb->set_charset('utf8mb4');
            $appDb->query("CREATE TABLE IF NOT EXISTS telegram_login_tokens (
                id INT PRIMARY KEY AUTO_INCREMENT,
                token VARCHAR(64) NOT NULL,
                telegram_id VARCHAR(64) NOT NULL,
                first_name VARCHAR(255) NOT NULL DEFAULT '',
                last_name VARCHAR(255) NOT NULL DEFAULT '',
                used TINYINT(1) NOT NULL DEFAULT 0,
                expires_at DATETIME NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_token (token)
            )");
            @$appDb->query("ALTER TABLE telegram_login_tokens ADD COLUMN ref_code VARCHAR(16) DEFAULT NULL");

            $loginToken = bin2hex(random_bytes(32));
            $expiresAt  = date('Y-m-d H:i:s', time() + 300); // ۵ دقیقه اعتبار

            $tokIns = $appDb->prepare("INSERT INTO telegram_login_tokens (token, telegram_id, first_name, last_name, expires_at, ref_code) VALUES (?, ?, ?, ?, ?, ?)");
            if ($tokIns) {
                $chatIdStr = (string)$bot->UserId;
                $tokIns->bind_param("ssssss", $loginToken, $chatIdStr, $tgFirst, $tgLast, $expiresAt, $refCode);
                if ($tokIns->execute()) {
                    $loginUrl .= '?login_token=' . $loginToken;
                }
                $tokIns->close();
            }
            $appDb->close();
        }
    } catch (Exception $e) {
        error_log('Failed to create referral telegram_login_token: ' . $e->getMessage());
    }

    $keyboard = [
        'inline_keyboard' => [
            [
                [
                    'text' => '🚀 ورود به AVA PAY',
                    'url'  => $loginUrl
                ]
            ]
        ]
    ];

    $bot->sendMessageWithMarkdown($message, $bot->UserId, $keyboard);
}