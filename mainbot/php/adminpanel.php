<?php

// --------------------------------------------------------------------------------
// --- ۱. تابع کمکی (clean_text) ---
// --------------------------------------------------------------------------------

/**
 * پاکسازی متن از منشن‌ها و حذف فضاهای اضافی.
 * @param string|null $text
 * @return string
 */
function clean_text($text) {
    if (empty($text)) {
        return '';
    }
    // حذف هرگونه mention در متن (مانند @username)
    $cleaned = preg_replace('/\s*@[\p{L}\p{N}_]+/u', '', $text);
    return trim($cleaned);
}

// --------------------------------------------------------------------------------
// --- ۲. تابع اصلی هندلر ادمین (handle_admin_logic) ---
// --------------------------------------------------------------------------------

/**
 * مدیریت تمامی منطق‌های مربوط به دستورات ادمین و وضعیت‌های مرحله‌ای.
 * @param object $bot شیء اصلی ربات حاوی توابع ارسال پیام و دیتابیس (Db).
 * @return bool
 */
function handle_admin_logic($bot) {

    // بررسی وجود پیام
    if (!isset($bot->update->message)) {
        return false;
    }
    
    // --- تعاریف اولیه: متغیرها و ثوابت ---
    $filePath = '/home/aradexch/public_html/ledor/msg.json';
    $adminId = 484167219; // آیدی ادمین مورد نظر شما (حتما این مقدار را با آیدی واقعی خود چک کنید)
    $chatId = $bot->update->message->chat->id ?? null;
    $message = $bot->update->message;
    $messageText = trim($bot->update->message->text ?? '');
    $caption = $message->caption ?? ''; // کپشن (برای عکس یا فایل)

    $isAdmin = (int)$chatId === (int)$adminId; 

    // --- بازیابی وضعیت ادمین از دیتابیس ---
    $adminState = ['state' => '', 'last_cleanup_timestamp' => 0, 'target_user_id' => null];
    if (isset($bot->Db)) {
        $adminAccountData = $bot->Db->get('account', 'data', ['chat_id' => $adminId]);
        $adminState = json_decode($adminAccountData, true) ?? $adminState;
        $adminState['target_user_id'] = $adminState['target_user_id'] ?? null; 
    }

    // --------------------------------------------------------------------------------
    // --- منطق پاکسازی دیتابیس (ساعت ۲۴:۰۰ هر روز) ---
    // --------------------------------------------------------------------------------
    // این بخش پاکسازی خودکار روزانه را انجام می‌دهد (اگرچه دستور /start_forward_save نیز اکنون پاکسازی می‌کند)
    if (isset($bot->Db)) {
        $lastCleanupDate = date('Y-m-d', $adminState['last_cleanup_timestamp'] ?? 0);
        $currentDate = date('Y-m-d');
        $currentTime = date('H:i');

        if ($currentDate > $lastCleanupDate && $currentTime >= '00:00' && $currentTime <= '00:30') {
            try {
                // پاکسازی جدول محتوای عمومی
                $bot->Db->query("TRUNCATE TABLE pushed_content"); 
                $adminState['last_cleanup_timestamp'] = time();
                
                $bot->Db->update('account', [
                    'data' => json_encode($adminState, JSON_UNESCAPED_UNICODE)
                ], ['chat_id' => $adminId]);
            } catch (Exception $e) {
                // گزارش خطا (اختیاری)
            }
        }
    }
    // --------------------------------------------------------------------------------
    
    // فیلتر کردن پیام‌های بدون محتوا
    if (empty($messageText) && !isset($message->photo) && !isset($message->document) && !isset($message->video)) {
        return false;
    }


    // --------------------------------------------------------------------------------
    // --- کیبوردهای پرکاربرد ---
    // --------------------------------------------------------------------------------
    
    $cancelKeyboard = [
        'keyboard' => [[['text' => "انصراف"]]],
        'resize_keyboard' => true,
        'one_time_keyboard' => true
    ];
    
    $exitSaveKeyboard = [
        'keyboard' => [[['text' => "خروج از ذخیره"]]],
        'resize_keyboard' => true,
        'one_time_keyboard' => true
    ];

    $adminKeyboard = [
        'keyboard' => [
            [['text' => "📢 Broadcast"], ['text' => "📝 Push"]],
            [['text' => "/start_forward_save"], ['text' => "/senddocc"]],
            [['text' => "🧾 تنظیم کمیسیون"], ['text' => "📱 آپدیت شماره کاربر"]],
            [['text' => "بازگشت ⬅️"]]
        ],
        'resize_keyboard' => true,
        'one_time_keyboard' => true
    ];

    // کیبورد انتخاب نوع کمیسیون (ثابت یا پلکانی)
    $commissionModeKeyboard = [
        'keyboard' => [
            [['text' => "درصد ثابت"], ['text' => "کمیسیون پلکانی (بر اساس مبلغ)"]],
            [['text' => "انصراف"]]
        ],
        'resize_keyboard' => true,
        'one_time_keyboard' => true
    ];
    
    // --------------------------------------------------------------------------------
    
    
    // --- منطق های ادمین ---
    
    // --- ۱. ورود به منوی مدیریت (/admin) ---
    if ($messageText === '/admin') {
        if (!$isAdmin) {
            // اگر ادمین نباشد
            $bot->send_message("❌ فقط ادمین می‌تواند وارد منوی مدیریت شود.", $chatId);
            return true;
        }
        // اگر ادمین باشد
        $bot->send_message("👮‍♂️ منوی مدیریت:", $chatId, $adminKeyboard);
        return true;
    }

    // --- ۲. انصراف (لغو همه حالت‌های انتظار) ---
    if (($messageText === "انصراف" || $messageText === "خروج از ذخیره") && $isAdmin) {
        
        $adminState['state'] = '';
        $adminState['target_user_id'] = null; 
        
        $bot->Db->update('account', [
            'data' => json_encode($adminState, JSON_UNESCAPED_UNICODE)
        ], ['chat_id' => $adminId]);
        
        $bot->send_message('✅ عملیات لغو شد. به ربات خوش آمدید...', $chatId, kboard: 'home', new_step: null);
        return true;
    }
    
    // --------------------------------------------------------------------------------
    // --- ۳. شروع دستورات مدیریتی ---
    // --------------------------------------------------------------------------------
    
    // --- A. شروع دستور /senddocc ---
    if ($messageText === '/senddocc' && $isAdmin) {
        $bot->send_message(
            "📝 **لطفاً یوزر آیدی (عددی) کاربر مورد نظر را وارد کنید:**",
            $chatId,
            $cancelKeyboard
        );
        $adminState['state'] = 'waiting_senddocc_user_id';
        $adminState['target_user_id'] = null;
        $bot->Db->update('account', ['data' => json_encode($adminState, JSON_UNESCAPED_UNICODE)], ['chat_id' => $adminId]);
        return true;
    }
    
    // --- B. شروع Push Command ---
    if ($messageText === '📝 Push' && $isAdmin) {
        $bot->send_message("📝 لطفاً متن یا عکس موردنظر برای ذخیره در فایل msg.json را ارسال کنید:", $chatId, $cancelKeyboard);
        $adminState['state'] = 'waiting_push_content';
        $bot->Db->update('account', ['data' => json_encode($adminState, JSON_UNESCAPED_UNICODE)], ['chat_id' => $adminId]);
        return true;
    }
    
    // --- C. شروع Broadcast ---
    if ($messageText === "📢 Broadcast" && $isAdmin) {
        $bot->send_message("📢 لطفاً متن یا عکس موردنظر برای ارسال همگانی را ارسال کنید:", $chatId, $cancelKeyboard);
        $adminState['state'] = 'waiting_broadcast';
        $bot->Db->update('account', ['data' => json_encode($adminState, JSON_UNESCAPED_UNICODE)], ['chat_id' => $adminId]);
        return true;
    }

    // --- C-2. مدیریت کمیسیون (تعیین درصد و ارز کمیسیون توسط ادمین) ---
    if ($messageText === "🧾 تنظیم کمیسیون" && $isAdmin) {
        $cfg   = commission_config($bot);
        $tiers = commission_tiers($bot);

        $currentText = !empty($tiers)
            ? "نوع فعلی کمیسیون: *پلکانی*\n" . commission_tiers_display($tiers)
            : "نوع فعلی کمیسیون: *درصد ثابت*\nدرصد فعلی: *{$cfg['percent']}٪*";

        $bot->send_message(
            "🧾 *تنظیمات کمیسیون*\n\n" .
            "{$currentText}\n" .
            "ارز محاسبه: *{$cfg['currency']}*\n\n" .
            "این کمیسیون از *هر دو طرف* (آگهی‌دهنده و پیشنهاددهنده) گرفته می‌شود:\n" .
            "🔹 در حالت *تومان*: از کل مبلغ تومانی معامله محاسبه می‌شود (خریدار مبلغ کل + کمیسیون می‌پردازد، فروشنده مبلغ کل − کمیسیون دریافت می‌کند).\n" .
            "🔹 در حالت *ارز انتخابی*: از مقدار ارزِ همان معامله محاسبه می‌شود (خریدار مقدار ارز − کمیسیون دریافت می‌کند، فروشنده مقدار ارز + کمیسیون می‌پردازد).\n\n" .
            "لطفاً نوع محاسبه‌ی کمیسیون را انتخاب کنید:\n" .
            "🔸 *درصد ثابت*: یک درصد یکسان برای همه‌ی مبالغ.\n" .
            "🔸 *کمیسیون پلکانی*: می‌توانید بازه‌های مبلغ/مقدار متفاوت با درصد متفاوت تعریف کنید؛ مثلاً «از 1 تا 1000 دلار، 2٪» و «بیشتر از 1000 دلار، 1٪».",
            $chatId,
            $commissionModeKeyboard
        );
        $adminState['state'] = 'waiting_commission_mode';
        $bot->Db->update('account', ['data' => json_encode($adminState, JSON_UNESCAPED_UNICODE)], ['chat_id' => $adminId]);
        return true;
    }

    // --- C-3. شروع آپدیت شماره تلفن کاربر توسط ادمین ---
    if ($messageText === "📱 آپدیت شماره کاربر" && $isAdmin) {
        $bot->send_message(
            "📱 *آپدیت شماره تلفن کاربر*\n\nلطفاً آیدی عددی (chat_id) کاربر مورد نظر را وارد کنید:",
            $chatId,
            $cancelKeyboard
        );
        $adminState['state'] = 'waiting_update_phone_userid';
        $adminState['target_user_id'] = null;
        $bot->Db->update('account', ['data' => json_encode($adminState, JSON_UNESCAPED_UNICODE)], ['chat_id' => $adminId]);
        return true;
    }

    // --- D. شروع Forward Save (ذخیره چندگانه) ---
   $startForwardSaveCommand = '/start_forward_save'; 
if ($messageText === $startForwardSaveCommand && $isAdmin) {
    
    // <<< پاکسازی جدول در دیتابیس aradexch_app >>>
    try {
        // ایجاد اتصال به دیتابیس aradexch_app
        require_once __DIR__ . '/../../includes/fast_mysqli.php';
        $appDb = avapay_fast_mysqli('localhost', 'aradexch_app', 'VIJVC9Gn5z9Y?D.$', 'aradexch_app', 3);
        if (!$appDb) { throw new \Exception('اتصال به دیتابیس اپ برقرار نشد'); }
        
        // پاکسازی کامل جدول pushed_content در دیتابیس aradexch_app
        $appDb->query("TRUNCATE TABLE pushed_content"); 
        $appDb->close();
        
        $bot->send_message("🗑️ جدول **pushed_content** در دیتابیس app با موفقیت پاکسازی شد.", $chatId);
    } catch (Exception $e) {
        $bot->send_message("❌ خطا در پاکسازی جدول دیتابیس app: " . $e->getMessage(), $chatId);
        // در صورت خطا، اتصال را ببندید
        if (isset($appDb)) {
            $appDb->close();
        }
    }
    // <<< پایان پاکسازی >>>

    $bot->send_message("📷 حالت ذخیره‌سازی چندگانه فعال شد. لطفاً پیام‌های حاوی عکس را ارسال کنید. \n\n" . "**برای خروج، دکمه «خروج از ذخیره» را بزنید.**", $chatId, $exitSaveKeyboard);
    $adminState['state'] = 'waiting_forward_save_multiple';
    $bot->Db->update('account', ['data' => json_encode($adminState, JSON_UNESCAPED_UNICODE)], ['chat_id' => $adminId]);
    return true;
}
    
    // --------------------------------------------------------------------------------
    // --- ۴. پردازش پیام‌های ارسالی در حالت انتظار (Admin Handlers) ---
    // --------------------------------------------------------------------------------
    
    if ($isAdmin) {
        $currentState = $adminState['state'] ?? '';

        // --- جلوگیری از اجرای تکراری دستورات مدیریتی در حالت انتظار ---
        $adminCommands = ['📝 Push', '/start_forward_save', '📢 Broadcast', 'انصراف', '/admin', 'خروج از ذخیره', '/senddocc', '🧾 تنظیم کمیسیون', '📱 آپدیت شماره کاربر'];
        if (in_array($messageText, $adminCommands) && $currentState !== '' && !in_array($messageText, ["انصراف", "خروج از ذخیره"])) { 
            $bot->send_message("⚠️ لطفاً محتوای مورد نیاز را ارسال کنید. برای لغو عملیات، «انصراف» را ارسال کنید.", $chatId);
            return true;
        }

        // ------------------------------------
        // --- A. هندلرهای /senddocc (ذخیره در جدول admin_documents) ---
        // ------------------------------------

        // A.1. هندلر مرحله اول /senddocc: دریافت یوزر آیدی
        if ($currentState === 'waiting_senddocc_user_id') {
            $targetId = (int)trim($messageText);
            
            if ($targetId > 0) {
                $adminState['state'] = 'waiting_senddocc_document';
                $adminState['target_user_id'] = $targetId;
                
                $bot->Db->update('account', ['data' => json_encode($adminState, JSON_UNESCAPED_UNICODE)], ['chat_id' => $adminId]);
                
                $bot->send_message("✅ **یوزر آیدی کاربر ($targetId) ذخیره شد.**\n\n**لطفاً عکس، فایل (سند/Document) یا ویدئو مورد نظر خود را ارسال کنید:**", $chatId, $cancelKeyboard);
            } else {
                $bot->send_message("❌ **خطا:** یوزر آیدی وارد شده معتبر نیست. لطفاً یک عدد صحیح وارد کنید.", $chatId);
            }
            return true;
        }
        
        // A.2. هندلر مرحله دوم /senddocc: دریافت مدرک و ذخیره در دیتابیس (اصلاح شده)
        if ($currentState === 'waiting_senddocc_document') {
            
            if (!isset($bot->Db)) {
                 $bot->send_message("❌ **خطا:** اتصال به دیتابیس برقرار نیست.", $chatId);
                 return true;
            }

            $targetUserId = $adminState['target_user_id'];
            $fileId = null;
            $messageType = null;
            
            // تشخیص نوع فایل ارسالی
            if (isset($message->document) && !empty($message->document)) {
                $fileId = $message->document->file_id;
                $messageType = 'document';
            } elseif (isset($message->photo) && is_array($message->photo) && !empty($message->photo)) {
                $fileId = end($message->photo)->file_id;
                $messageType = 'photo';
            } elseif (isset($message->video) && !empty($message->video)) {
                $fileId = $message->video->file_id;
                $messageType = 'video';
            }

            if ($fileId && $targetUserId) {
                
                try {
                    // ***اصلاح کلیدی: استفاده از جدول admin_documents***
                    $bot->Db->insert('admin_documents', [
                        'sender_admin_id' => $adminId,      // آیدی ادمین فرستنده
                        'chat_id' => $targetUserId,     // یوزر آیدی کاربر مقصد
                        'message_type' => $messageType,        // نوع پیام (photo, document, video)
                        'file_id' => $fileId,              // فایل آیدی تلگرام
                        'caption' => clean_text($caption),    // کپشن پیام
                        'status' => 'pending',             // وضعیت انتظار برای ارسال
                    ]);

                    // اتمام فرآیند و پاکسازی وضعیت
                    $adminState['state'] = '';
                    $adminState['target_user_id'] = null; 
                    $bot->Db->update('account', ['data' => json_encode($adminState, JSON_UNESCAPED_UNICODE)], ['chat_id' => $adminId]);
                    
                    $bot->send_message("🎉 **موفقیت!** محتوای شما با موفقیت برای کاربر با یوزر آیدی **$targetUserId** در جدول **admin_documents** ذخیره شد.", $chatId, kboard: 'home', new_step: null);

                } catch (Exception $e) {
                     $bot->send_message("❌ **خطا در ذخیره‌سازی دیتابیس:** " . $e->getMessage(), $chatId);
                }
                
            } else {
                $bot->send_message("❌ **خطا:** لطفا یک فایل **سند (Document)**، **عکس** یا **ویدئو** ارسال کنید.", $chatId);
            }
            return true;
        }
        
        // ------------------------------------
        // --- B. هندلر ذخیره محتوای Push در JSON (waiting_push_content) ---
        // ------------------------------------
     if ($currentState === 'waiting_forward_save_multiple') {
    // ایجاد اتصال جدید به دیتابیس aradexch_app
    try {
        require_once __DIR__ . '/../../includes/fast_mysqli.php';
        $appDb = avapay_fast_mysqli('localhost', 'aradexch_app', 'VIJVC9Gn5z9Y?D.$', 'aradexch_app', 3);
        if (!$appDb) { throw new \Exception('اتصال برقرار نشد یا بیش از حد طول کشید'); }
    } catch (Exception $e) {
        $bot->send_message("❌ **خطا:** اتصال به دیتابیس app برقرار نیست: " . $e->getMessage(), $chatId, $exitSaveKeyboard);
        return true;
    }
    
    $fileId = null;
    $messageType = null;
    $mediaGroupId = $message->media_group_id ?? null;
    $currentCaption = $message->caption ?? '';
    
    // --- منطق استخراج عنوان و کپشن (همانند قبل) ---
    $title = 'بدون عنوان';
    $caption = null;
    
    if ($mediaGroupId) {
        if (!empty($currentCaption)) {
            $lines = explode("\n", trim($currentCaption));
            $title = clean_text(array_shift($lines));
            $caption = clean_text(implode("\n", $lines));
            $adminState['media_group_caption'][$mediaGroupId] = ['title' => $title, 'caption' => $caption];
            $bot->Db->update('account', ['data' => json_encode($adminState, JSON_UNESCAPED_UNICODE)], ['chat_id' => $adminId]);
        } elseif (isset($adminState['media_group_caption'][$mediaGroupId])) {
            $title = $adminState['media_group_caption'][$mediaGroupId]['title'];
            $caption = $adminState['media_group_caption'][$mediaGroupId]['caption'];
        }
    } else {
        if (!empty($currentCaption)) {
            $lines = explode("\n", trim($currentCaption));
            $title = clean_text(array_shift($lines));
            $caption = clean_text(implode("\n", $lines));
        }
    }
    
    // --- تشخیص نوع فایل ---
    if (isset($message->photo) && is_array($message->photo) && !empty($message->photo)) {
        $fileId = end($message->photo)->file_id;
        $messageType = 'photo';
    } elseif (isset($message->video) && !empty($message->video)) {
        $fileId = $message->video->file_id;
        $messageType = 'video';
    }
    
    if ($fileId && $messageType) {
        try {
            // **ذخیره در دیتابیس aradexch_app**
            $stmt = $appDb->prepare("INSERT INTO pushed_content (title, caption, file_id, message_type, media_group_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $title, $caption, $fileId, $messageType, $mediaGroupId);
            $stmt->execute();
            
            $typeFa = ($messageType === 'photo' ? 'عکس' : ($messageType === 'video' ? 'ویدئو' : 'فایل'));
            $responseMessage = "✅ **$typeFa** در دیتابیس app ذخیره شد. عنوان: **" . htmlspecialchars($title) . "**" . ($mediaGroupId ? " (آلبوم)" : "");
            $bot->send_message($responseMessage, $chatId, $exitSaveKeyboard);
            
            $stmt->close();
            
        } catch (Exception $e) {
            $bot->send_message("❌ خطا در ذخیره‌سازی دیتابیس app: " . $e->getMessage(), $chatId, $exitSaveKeyboard);
        }
        
        // بستن اتصال
        $appDb->close();
        
    } elseif ($messageText === 'خروج از ذخیره') {
        $appDb->close(); // بستن اتصال قبل از خروج
        return false;
    } else {
        $bot->send_message("⚠️ لطفا فقط پیام حاوی **عکس**، **ویدئو** یا **آلبوم** را ارسال کنید. برای خروج، «خروج از ذخیره» را بفرستید.", $chatId, $exitSaveKeyboard);
        $appDb->close(); // بستن اتصال
    }
    
    return true;
}
        // ------------------------------------
        // --- D. ارسال همگانی (waiting_broadcast) ---
        // ------------------------------------
        if ($currentState === 'waiting_broadcast') {
            
            $allUsers = $bot->Db->select('account', ['chat_id']);
            $success = false;

            if (isset($message->photo)) {
                $fileId = end($message->photo)->file_id;
                $caption = clean_text($caption); 
                foreach ($allUsers as $user) {
                    $bot->send_photo($fileId, $caption, $user['chat_id']);
                }
                $success = true;

            } elseif (!empty($messageText)) {
                $messageText = clean_text($messageText); 
                foreach ($allUsers as $user) {
                    $bot->send_message($messageText, $user['chat_id']);
                }
                $success = true;
            }

            if ($success) {  
                $adminState['state'] = '';
                $bot->Db->update('account', ['data' => json_encode($adminState, JSON_UNESCAPED_UNICODE)], ['chat_id' => $adminId]);
                $bot->send_message('✅ ارسال همگانی با موفقیت انجام شد.', $chatId, kboard: 'home', new_step: null);
            } else {
                $bot->send_message("⚠️ فقط متن یا عکس قابل ارسال است.", $chatId, ['remove_keyboard' => true]);
            }
            return true;
        }

        // ------------------------------------
        // --- E-0. انتخاب نوع کمیسیون (ثابت / پلکانی) ---
        // ------------------------------------
        if ($currentState === 'waiting_commission_mode') {
            $choice = trim($messageText);

            if ($choice === 'درصد ثابت') {
                // درصد ثابت انتخاب شد: هر Tier پلکانی قبلی حذف می‌شود
                commission_tiers_clear($bot);
                $bot->send_message(
                    "لطفاً *درصد جدید* کمیسیون را وارد کنید (مثلاً 0.5 یعنی نیم درصد):",
                    $chatId,
                    $cancelKeyboard
                );
                $adminState['state'] = 'waiting_commission_percent';
                $bot->Db->update('account', ['data' => json_encode($adminState, JSON_UNESCAPED_UNICODE)], ['chat_id' => $adminId]);
                return true;
            }

            if ($choice === 'کمیسیون پلکانی (بر اساس مبلغ)') {
                $bot->send_message(
                    "🧾 *تعریف کمیسیون پلکانی*\n\n" .
                    "هر بازه را در یک خط وارد کنید. کمیسیون هر بازه می‌تواند *درصدی* یا *مقدار ثابت* باشد:\n\n" .
                    "🔹 *کمیسیون درصدی* (پیش‌فرض):\n" .
                    "`حد_پایین-حد_بالا:درصد`\n" .
                    "`حد_پایین+:درصد`  (برای آخرین بازه‌ی بدون سقف)\n\n" .
                    "🔹 *کمیسیون ثابت* (یک عدد ثابت، مستقل از اندازه‌ی معامله؛ به همان ارز خودِ معامله):\n" .
                    "برای این حالت، بعد از عدد، حرف *F* یا کلمه‌ی *ثابت* را اضافه کنید:\n" .
                    "`حد_پایین-حد_بالا:مقدارF`\n" .
                    "`حد_پایین+:مقدارF`\n\n" .
                    "مثال (بر اساس یورو):\n" .
                    "`1-1000:5F`\n" .
                    "`1000-5000:2`\n" .
                    "`5000+:1`\n\n" .
                    "یعنی: از 1 تا 1000 یورو، همیشه *5 یورو* کمیسیون ثابت گرفته می‌شود؛ از 1000 تا 5000 یورو، *2٪* و بیشتر از 5000 یورو، *1٪* کمیسیون گرفته می‌شود.\n\n" .
                    "همه‌ی بازه‌ها را در یک پیام و هر کدام در یک خط ارسال کنید:",
                    $chatId,
                    $cancelKeyboard
                );
                $adminState['state'] = 'waiting_commission_tiers';
                $bot->Db->update('account', ['data' => json_encode($adminState, JSON_UNESCAPED_UNICODE)], ['chat_id' => $adminId]);
                return true;
            }

            $bot->send_message("❌ لطفاً یکی از دو گزینه‌ی «درصد ثابت» یا «کمیسیون پلکانی (بر اساس مبلغ)» را انتخاب کنید.", $chatId, $commissionModeKeyboard);
            return true;
        }

        // ------------------------------------
        // --- E-1. دریافت و ذخیره‌ی بازه‌های کمیسیون پلکانی ---
        // ------------------------------------
        if ($currentState === 'waiting_commission_tiers') {
            $tiers = commission_parse_tiers_input($messageText);

            if ($tiers === null) {
                $bot->send_message(
                    "❌ فرمت ورودی معتبر نیست. لطفاً هر بازه را طبق نمونه‌ی زیر، هر کدام در یک خط، دوباره ارسال کنید:\n\n" .
                    "`1-1000:5F` (کمیسیون ثابت 5 واحد)\n`1000-5000:2` (کمیسیون درصدی 2٪)\n`5000+:1`",
                    $chatId
                );
                return true;
            }

            $saved = commission_tiers_set($bot, $tiers);
            if (!$saved) {
                $err = get_setting_error();
                $bot->send_message(
                    "❌ ذخیره‌سازی کمیسیون پلکانی در دیتابیس ناموفق بود؛ کمیسیون *تعریف نشد*.\n" .
                    ($err ? "جزئیات خطا:\n`{$err}`\n\n" : '') .
                    "لطفاً این خطا را به توسعه‌دهنده‌ی ربات اطلاع دهید.",
                    $chatId,
                    kboard: 'home',
                    new_step: null
                );
                return true;
            }

            $currencyKeyboard = [
                'keyboard' => [
                    [['text' => "تومان"], ['text' => "ارز انتخابی"]],
                    [['text' => "انصراف"]]
                ],
                'resize_keyboard' => true,
                'one_time_keyboard' => true
            ];

            $bot->send_message(
                "✅ بازه‌های کمیسیون پلکانی ذخیره شد:\n\n" .
                commission_tiers_display($tiers) . "\n\n" .
                "حالا نوع کمیسیون را انتخاب کنید:\n" .
                "🔹 *تومان*: کمیسیون از روی کل مبلغ تومانی معامله محاسبه می‌شود؛ خریدار مبلغ کل + کمیسیون را پرداخت و فروشنده مبلغ کل − کمیسیون را دریافت می‌کند.\n" .
                "🔹 *ارز انتخابی*: کمیسیون از روی مقدار ارزِ همان معامله (دلار/یورو/...) محاسبه می‌شود؛ خریدار مقدار ارز − کمیسیون را دریافت و فروشنده مقدار ارز + کمیسیون را پرداخت می‌کند.",
                $chatId,
                $currencyKeyboard
            );
            $adminState['state'] = 'waiting_commission_currency';
            $bot->Db->update('account', ['data' => json_encode($adminState, JSON_UNESCAPED_UNICODE)], ['chat_id' => $adminId]);
            return true;
        }

        // ------------------------------------
        // --- E. تعیین درصد کمیسیون ---
        // ------------------------------------
        if ($currentState === 'waiting_commission_percent') {
            $percent = str_replace(['٪', '%', ' '], '', trim($messageText));
            $percent = str_replace('،', '.', $percent); // پشتیبانی از ممیز فارسی

            if (!is_numeric($percent) || $percent < 0 || $percent > 100) {
                $bot->send_message("❌ لطفاً یک عدد معتبر بین 0 تا 100 وارد کنید (مثلاً 0.5).", $chatId);
                return true;
            }

            $saved = set_setting($bot, 'commission_percent', (float) $percent);
            if (!$saved) {
                $err = get_setting_error();
                $bot->send_message(
                    "❌ ذخیره‌سازی درصد کمیسیون در دیتابیس ناموفق بود؛ کمیسیون *تعریف نشد*.\n" .
                    ($err ? "جزئیات خطا:\n`{$err}`\n\n" : '') .
                    "لطفاً این خطا را به توسعه‌دهنده‌ی ربات اطلاع دهید (احتمالاً جدول `settings` در دیتابیس مشکل دارد).",
                    $chatId,
                    kboard: 'home',
                    new_step: null
                );
                return true;
            }

            $currencyKeyboard = [
                'keyboard' => [
                    [['text' => "تومان"], ['text' => "ارز انتخابی"]],
                    [['text' => "انصراف"]]
                ],
                'resize_keyboard' => true,
                'one_time_keyboard' => true
            ];

            $bot->send_message(
                "✅ درصد کمیسیون روی *{$percent}٪* تنظیم شد.\n\n" .
                "حالا نوع کمیسیون را انتخاب کنید:\n" .
                "🔹 *تومان*: کمیسیون از روی کل مبلغ تومانی معامله محاسبه می‌شود؛ خریدار مبلغ کل + کمیسیون را پرداخت و فروشنده مبلغ کل − کمیسیون را دریافت می‌کند.\n" .
                "🔹 *ارز انتخابی*: کمیسیون از روی مقدار ارزِ همان معامله (دلار/یورو/...) محاسبه می‌شود؛ خریدار مقدار ارز − کمیسیون را دریافت و فروشنده مقدار ارز + کمیسیون را پرداخت می‌کند.",
                $chatId,
                $currencyKeyboard
            );
            $adminState['state'] = 'waiting_commission_currency';
            $bot->Db->update('account', ['data' => json_encode($adminState, JSON_UNESCAPED_UNICODE)], ['chat_id' => $adminId]);
            return true;
        }

        // ------------------------------------
        // --- F. تعیین ارز کمیسیون ---
        // ------------------------------------
        if ($currentState === 'waiting_commission_currency') {
            $currency = trim($messageText);

            if ($currency === '' || mb_strlen($currency) > 20) {
                $bot->send_message("❌ لطفاً یکی از دو گزینه «تومان» یا «ارز انتخابی» را انتخاب کنید.", $chatId);
                return true;
            }

            // فقط دو حالت معتبر است: تومان یا ارز انتخابی (همان ارز معامله). هر مقدار دیگری هم به‌عنوان «ارز انتخابی» در نظر گرفته می‌شود.
            if ($currency !== 'تومان') {
                $currency = 'ارز انتخابی';
            }

            $saved = set_setting($bot, 'commission_currency', $currency);
            if (!$saved) {
                $err = get_setting_error();
                $bot->send_message(
                    "❌ ذخیره‌سازی نوع کمیسیون در دیتابیس ناموفق بود؛ کمیسیون *تعریف نشد*.\n" .
                    ($err ? "جزئیات خطا:\n`{$err}`\n\n" : '') .
                    "لطفاً این خطا را به توسعه‌دهنده‌ی ربات اطلاع دهید (احتمالاً جدول `settings` در دیتابیس مشکل دارد).",
                    $chatId,
                    kboard: 'home',
                    new_step: null
                );
                return true;
            }

            $cfg   = commission_config($bot);
            $tiers = commission_tiers($bot);
            $adminState['state'] = '';
            $bot->Db->update('account', ['data' => json_encode($adminState, JSON_UNESCAPED_UNICODE)], ['chat_id' => $adminId]);

            $modeDesc = $cfg['currency'] === 'تومان'
                ? 'کمیسیون از روی کل مبلغ تومانی معامله محاسبه می‌شود (به مبلغ خریدار افزوده و از مبلغ فروشنده کسر می‌شود).'
                : 'کمیسیون از روی مقدار ارزِ همان معامله محاسبه می‌شود (از مقدار دریافتی خریدار کسر و به مقدار پرداختی فروشنده افزوده می‌شود).';

            $percentText = !empty($tiers)
                ? "نوع کمیسیون: *پلکانی*\n" . commission_tiers_display($tiers)
                : "درصد کمیسیون: *{$cfg['percent']}٪*";

            $bot->send_message(
                "✅ تنظیمات کمیسیون ذخیره شد.\n\n" .
                "{$percentText}\n" .
                "ارز محاسبه: *{$cfg['currency']}*\n" .
                "{$modeDesc}\n\n" .
                "این مقدار از این پس در پیام‌های ثبت آگهی و ثبت پیشنهاد، از هر دو طرف اعمال می‌شود.",
                $chatId,
                kboard: 'home',
                new_step: null
            );
            return true;
        }

        // ------------------------------------
        // --- G. آپدیت شماره تلفن کاربر توسط ادمین ---
        // ------------------------------------

        // G.1. دریافت آیدی عددی کاربر
        if ($currentState === 'waiting_update_phone_userid') {
            $targetId = trim($messageText);

            if (!ctype_digit($targetId) && !(substr($targetId, 0, 1) === '-' && ctype_digit(substr($targetId, 1)))) {
                $bot->send_message("❌ لطفاً یک آیدی عددی (chat_id) معتبر وارد کنید.", $chatId);
                return true;
            }

            $targetId = (int) $targetId;

            if (!$bot->Db->has('account', ['chat_id' => $targetId])) {
                $bot->send_message("❌ کاربری با این آیدی در دیتابیس یافت نشد. لطفاً دوباره بررسی کنید یا «انصراف» را بزنید.", $chatId);
                return true;
            }

            $adminState['target_user_id'] = $targetId;
            $adminState['state'] = 'waiting_update_phone_number';
            $bot->Db->update('account', ['data' => json_encode($adminState, JSON_UNESCAPED_UNICODE)], ['chat_id' => $adminId]);

            $currentPhone = $bot->Db->get('account', 'phone', ['chat_id' => $targetId]);
            $bot->send_message(
                "✅ کاربر با آیدی *{$targetId}* پیدا شد.\n" .
                "شماره فعلی: " . ($currentPhone ? "`{$currentPhone}`" : 'ثبت نشده') . "\n\n" .
                "لطفاً شماره تلفن جدید را وارد کنید (مثلاً 09123456789):",
                $chatId,
                $cancelKeyboard
            );
            return true;
        }

        // G.2. دریافت و ذخیره‌ی شماره تلفن جدید
        if ($currentState === 'waiting_update_phone_number') {
            $phone = trim($messageText);
            $phoneNormalized = str_replace([' ', '-'], '', $phone);

            if (!preg_match('/^\+?[0-9]{8,15}$/', $phoneNormalized)) {
                $bot->send_message("❌ فرمت شماره تلفن معتبر نیست. لطفاً فقط عدد (و در صورت نیاز + در ابتدا) وارد کنید.", $chatId);
                return true;
            }

            $targetId = $adminState['target_user_id'] ?? null;
            if (!$targetId) {
                $bot->send_message("❌ خطایی رخ داد؛ آیدی کاربر یافت نشد. لطفاً از ابتدا شروع کنید.", $chatId, kboard: 'home', new_step: null);
                $adminState['state'] = '';
                $bot->Db->update('account', ['data' => json_encode($adminState, JSON_UNESCAPED_UNICODE)], ['chat_id' => $adminId]);
                return true;
            }

            $bot->Db->update('account', ['phone' => $phoneNormalized], ['chat_id' => $targetId]);

            $adminState['state'] = '';
            $adminState['target_user_id'] = null;
            $bot->Db->update('account', ['data' => json_encode($adminState, JSON_UNESCAPED_UNICODE)], ['chat_id' => $adminId]);

            $bot->send_message(
                "🎉 شماره تلفن کاربر *{$targetId}* با موفقیت به `{$phoneNormalized}` به‌روزرسانی شد.",
                $chatId,
                kboard: 'home',
                new_step: null
            );
            return true;
        }
    }
    
    return false;
}