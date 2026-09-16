<?php
/**
 * mainbot/php/manage_panel.php
 * ---------------------------------------------------------------------------
 * منوی مدیریت اختصاصی برای ادمین 5330629504
 *
 * قابلیت‌ها:
 *   ۱) ویرایش اطلاعات کاربر (نام، نام خانوادگی، شماره تلفن، ایمیل) با دادن
 *      آیدی عددی کاربر — تغییرات هم در دیتابیس ربات و هم در AvaPay اعمال می‌شود.
 *   ۲) مدیریت وضعیت آگهی: فعال‌سازی (up)، لغو (cancel)، و مسدودسازی کاربر (ban)
 *   ۳) ارسال پیام به یک کاربر خاص یا ارسال همگانی به همه‌ی کاربران
 *
 * این فایل کاملاً مستقل از php/adminpanel.php است (که مخصوص ادمین 484167219
 * است) تا منطق آن دست‌نخورده بماند و دو پنل با هم تداخل نکنند.
 * ---------------------------------------------------------------------------
 */

if (!defined('AVAPAY_MANAGE_ADMIN_ID')) {
    define('AVAPAY_MANAGE_ADMIN_ID', 5330629504);
}

/* ---------------------------------------------------------------------------
 * اتصال به دیتابیس سایت AvaPay (برای همگام‌سازی اطلاعات کاربر)
 * ------------------------------------------------------------------------- */
if (!function_exists('mp_avapay_db')) {
    function mp_avapay_db(): ?mysqli {
        static $conn = null, $tried = false;
        if ($tried) return $conn;
        $tried = true;
        try {
            require_once __DIR__ . '/../../includes/fast_mysqli.php';
            $conn = avapay_fast_mysqli('localhost', 'aradexch_app', 'VIJVC9Gn5z9Y?D.$', 'aradexch_app', 3);
        } catch (\Throwable $e) { $conn = null; }
        return $conn;
    }
}

/** خواندن/نوشتن وضعیت گفت‌وگوی ادمین (روی ستون data همان ردیف اکانت) */
if (!function_exists('mp_get_state')) {
    function mp_get_state($bot, $adminId): array {
        $raw = $bot->Db->get('account', 'data', ['chat_id' => $adminId]);
        $st  = json_decode((string)$raw, true);
        if (!is_array($st)) $st = [];
        return $st;
    }
}
if (!function_exists('mp_set_state')) {
    function mp_set_state($bot, $adminId, array $st): void {
        $bot->Db->update('account', ['data' => json_encode($st, JSON_UNESCAPED_UNICODE)], ['chat_id' => $adminId]);
    }
}
if (!function_exists('mp_clear_state')) {
    function mp_clear_state($bot, $adminId): void {
        $st = mp_get_state($bot, $adminId);
        unset($st['mp_state'], $st['mp_target'], $st['mp_field']);
        mp_set_state($bot, $adminId, $st);
    }
}

/**
 * خواندن همه‌ی اطلاعات ثبت‌شده‌ی یک کاربر از دیتابیس AvaPay با آیدی تلگرام،
 * شامل موجودی کیف‌پول‌ها (ستون‌های balance_* که به‌صورت داینامیک کشف می‌شوند
 * — دقیقاً همان روشی که خود سایت در dashboard.php استفاده می‌کند).
 */
if (!function_exists('mp_fetch_avapay_user')) {
    function mp_fetch_avapay_user($telegramId): ?array {
        $conn = mp_avapay_db();
        if (!$conn) return null;

        $st = $conn->prepare("SELECT * FROM users WHERE telegram_id = ? LIMIT 1");
        if (!$st) return null;
        $tg = (string)$telegramId;
        $st->bind_param('s', $tg);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$row) return null;

        // موجودی کیف‌پول‌ها: ستون‌های balance_* روی همان ردیف
        $wallets = [];
        foreach ($row as $col => $val) {
            if (strpos($col, 'balance_') === 0 && (float)$val != 0) {
                $wallets[strtoupper(substr($col, 8))] = (float)$val;
            }
        }
        $row['__wallets'] = $wallets;
        return $row;
    }
}

/**
 * همگام‌سازی یک فیلد کاربر با دیتابیس AvaPay.
 * نگاشت فیلدهای ربات به ستون‌های جدول users در سایت.
 */
if (!function_exists('mp_sync_to_avapay')) {
    function mp_sync_to_avapay($telegramId, string $field, string $value): array {
        $map = [
            'name'     => 'first_name',
            'lastname' => 'last_name',
            'phone'    => 'phone_number',
            'email'    => 'email',
        ];
        if (!isset($map[$field])) return [false, 'فیلد ناشناخته'];

        $conn = mp_avapay_db();
        if (!$conn) return [false, 'اتصال به دیتابیس AvaPay برقرار نشد'];

        $col = $map[$field];

        // اگر ستون در جدول نبود (مثلاً email در نسخه‌های قدیمی)، خطای گویا بده
        $has = false;
        $r = @$conn->query("SHOW COLUMNS FROM `users` LIKE '" . $conn->real_escape_string($col) . "'");
        if ($r && $r->num_rows > 0) $has = true;
        if (!$has) return [false, "ستون {$col} در جدول users سایت وجود ندارد"];

        $st = $conn->prepare("UPDATE users SET `{$col}` = ?, updated_at = NOW() WHERE telegram_id = ?");
        if (!$st) return [false, 'خطای آماده‌سازی کوئری'];
        $tg = (string)$telegramId;
        $st->bind_param('ss', $value, $tg);
        $st->execute();
        $affected = $st->affected_rows;
        $st->close();

        if ($affected < 1) {
            // ممکن است کاربر در سایت نباشد، یا مقدار قبلاً همین بوده
            $chk = $conn->prepare("SELECT id FROM users WHERE telegram_id = ? LIMIT 1");
            if ($chk) {
                $chk->bind_param('s', $tg);
                $chk->execute();
                $exists = $chk->get_result()->fetch_assoc();
                $chk->close();
                if (!$exists) return [false, 'این کاربر در سایت AvaPay ثبت نشده است'];
            }
            return [true, 'مقدار در سایت از قبل همین بود'];
        }
        return [true, 'در سایت هم به‌روزرسانی شد'];
    }
}

/**
 * گذاشتن یک دکمه‌ی شیشه‌ای (inline) زیر پستِ آگهی در کانال.
 *
 * شماره‌ی پیامِ پست کانال در ستون `message` جدول mozayede ذخیره شده است.
 * editMessageReplyMarkup فقط دکمه‌ها را عوض می‌کند و به متن آگهی دست نمی‌زند.
 *
 * @return array [bool موفق, string توضیح]
 */
if (!function_exists('mp_set_ad_button')) {
    function mp_set_ad_button($bot, $adId, $label): array {
        $msgId = $bot->Db->get('mozayede', 'message', ['id' => $adId]);
        if (!$msgId) return [false, 'شناسه‌ی پست کانال برای این آگهی ثبت نشده'];

        // نکته: این ربات متد answerCallbackQuery ندارد، بنابراین دکمه‌ی
        // callback_data بعد از لمس فقط می‌چرخد و پاسخی نمی‌گیرد. پس از دکمه‌ی
        // نوع url استفاده می‌کنیم که به خودِ همان پست لینک می‌دهد: عملاً یک
        // برچسب (badge) است، بدون چرخش و بدون نیاز به هندلر.
        $inlineKeyboard = ($label === null) ? null : [
            [['text' => $label, 'url' => 'https://t.me/' . CHANNEL . '/' . $msgId]],
        ];

        try {
            // آرایه‌ی خالی یعنی حذف کامل دکمه‌ها
            $markup = $inlineKeyboard === null ? new stdClass() : ['inline_keyboard' => $inlineKeyboard];
            $bot->editMessageReplyMarkup($markup, '@' . CHANNEL, $msgId);
            return [true, "پست کانال (#{$msgId}) به‌روزرسانی شد"];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }
}

/* ===========================================================================
 * منطق اصلی پنل
 * ========================================================================= */
function handle_manage_panel($bot) {
    if (!isset($bot->update->message)) return false;

    $adminId = AVAPAY_MANAGE_ADMIN_ID;
    $__tgMsg = $bot->update->message;
    $chatId  = $__tgMsg->chat->id ?? null;
    $text    = trim($__tgMsg->text ?? '');

    // (اصلاح) پیام همگانی باید از عکس + کپشن هم پشتیبانی کند، نه فقط متن.
    // پیام‌های حاوی عکس فیلد text ندارند (فقط caption)، پس باید جدا تشخیص داده شوند.
    $mpHasPhoto     = isset($__tgMsg->photo) && is_array($__tgMsg->photo) && !empty($__tgMsg->photo);
    $mpPhotoFileId  = $mpHasPhoto ? (end($__tgMsg->photo)->file_id ?? null) : null;
    $mpCaption      = trim($__tgMsg->caption ?? '');

    if ((int)$chatId !== (int)$adminId) return false; // فقط همین ادمین

    $state  = mp_get_state($bot, $adminId);
    $mp     = $state['mp_state'] ?? '';
    $target = $state['mp_target'] ?? null;

    $menuKb = [
        'keyboard' => [
            [['text' => '👤 ویرایش اطلاعات کاربر'], ['text' => '🔍 جستجوی کاربر']],
            [['text' => '📢 پیام به یک کاربر'], ['text' => '📣 پیام همگانی']],
            [['text' => '📝 مدیریت آگهی']],
            [['text' => '🚫 مسدودسازی کاربر'], ['text' => '✅ رفع مسدودی']],
            [['text' => '❌ خروج از مدیریت']],
        ],
        'resize_keyboard' => true,
    ];
    $cancelKb = [
        'keyboard' => [[['text' => '❌ خروج از مدیریت']]],
        'resize_keyboard' => true, 'one_time_keyboard' => true,
    ];

    /* ---------- ورود به منو ---------- */
    if ($text === '/manage' || $text === '/panel') {
        mp_clear_state($bot, $adminId);
        $bot->send_message("🛠 منوی مدیریت آواپی\n\nیک گزینه را انتخاب کنید:", $chatId, $menuKb);
        return true;
    }

    /* ---------- خروج ---------- */
    if ($text === '❌ خروج از مدیریت') {
        mp_clear_state($bot, $adminId);
        $bot->send_message('✅ از منوی مدیریت خارج شدید.', $chatId, kboard: 'home', new_step: null);
        return true;
    }

    /* =======================================================================
     * ۱) ویرایش اطلاعات کاربر
     * ===================================================================== */
    if ($text === '👤 ویرایش اطلاعات کاربر') {
        $state['mp_state'] = 'edit_ask_id';
        mp_set_state($bot, $adminId, $state);
        $bot->send_message("👤 آیدی عددی کاربر (chat_id تلگرام) را بفرستید:", $chatId, $cancelKb);
        return true;
    }

    if ($mp === 'edit_ask_id') {
        $uid = preg_replace('/\D/', '', $text);
        if ($uid === '') {
            $bot->send_message('❌ آیدی باید عددی باشد. دوباره بفرستید:', $chatId, $cancelKb);
            return true;
        }
        if (!$bot->Db->has('account', ['chat_id' => $uid])) {
            $bot->send_message("❌ کاربری با آیدی {$uid} در ربات پیدا نشد.", $chatId, $cancelKb);
            return true;
        }

        $acc = $bot->Db->get('account', ['name', 'lastname', 'phone', 'username'], ['chat_id' => $uid]);
        $info = "👤 اطلاعات فعلی کاربر {$uid}:\n"
              . "▫️ نام: " . (($acc['name'] ?? '') !== '' ? $acc['name'] : '—') . "\n"
              . "▫️ نام خانوادگی: " . (($acc['lastname'] ?? '') !== '' ? $acc['lastname'] : '—') . "\n"
              . "▫️ تلفن: " . (($acc['phone'] ?? '') !== '' ? $acc['phone'] : '—') . "\n"
              . "▫️ یوزرنیم: " . (($acc['username'] ?? '') !== '' ? '@' . $acc['username'] : '—') . "\n\n"
              . "کدام مورد را می‌خواهید تغییر دهید؟";

        $state['mp_state']  = 'edit_pick_field';
        $state['mp_target'] = $uid;
        mp_set_state($bot, $adminId, $state);

        $bot->send_message($info, $chatId, [
            'keyboard' => [
                [['text' => '✏️ نام'], ['text' => '✏️ نام خانوادگی']],
                [['text' => '✏️ شماره تلفن'], ['text' => '✏️ ایمیل']],
                [['text' => '❌ خروج از مدیریت']],
            ],
            'resize_keyboard' => true,
        ]);
        return true;
    }

    if ($mp === 'edit_pick_field') {
        $fields = [
            '✏️ نام'            => ['name', 'نام'],
            '✏️ نام خانوادگی'   => ['lastname', 'نام خانوادگی'],
            '✏️ شماره تلفن'     => ['phone', 'شماره تلفن'],
            '✏️ ایمیل'          => ['email', 'ایمیل'],
        ];
        if (!isset($fields[$text])) {
            $bot->send_message('❌ لطفاً یکی از دکمه‌های بالا را بزنید.', $chatId);
            return true;
        }
        $state['mp_state'] = 'edit_ask_value';
        $state['mp_field'] = $fields[$text][0];
        mp_set_state($bot, $adminId, $state);
        $bot->send_message("مقدار جدید برای «{$fields[$text][1]}» را بفرستید:", $chatId, $cancelKb);
        return true;
    }

    if ($mp === 'edit_ask_value') {
        $field = $state['mp_field'] ?? '';
        $value = trim($text);
        if ($value === '') { $bot->send_message('❌ مقدار خالی است.', $chatId, $cancelKb); return true; }

        // اعتبارسنجی مخصوص هر فیلد
        if ($field === 'phone') {
            $value = str_replace([' ', '-'], '', $value);
            if (!preg_match('/^\+?[0-9]{8,15}$/', $value)) {
                $bot->send_message('❌ فرمت شماره تلفن معتبر نیست.', $chatId, $cancelKb);
                return true;
            }
        }
        if ($field === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $bot->send_message('❌ فرمت ایمیل معتبر نیست.', $chatId, $cancelKb);
            return true;
        }

        // ۱) به‌روزرسانی در دیتابیس ربات (ستون email ممکن است وجود نداشته باشد)
        $botOk = true; $botNote = '';
        try {
            if ($field !== 'email') {
                $bot->Db->update('account', [$field => $value], ['chat_id' => $target]);
            } else {
                $botNote = 'ایمیل فقط در سایت ذخیره می‌شود';
            }
        } catch (\Throwable $e) {
            $botOk = false; $botNote = $e->getMessage();
        }

        // ۲) همگام‌سازی با AvaPay
        [$siteOk, $siteMsg] = mp_sync_to_avapay($target, $field, $value);

        mp_clear_state($bot, $adminId);

        $msg  = ($botOk && $siteOk) ? "🎉 به‌روزرسانی انجام شد.\n" : "⚠️ به‌روزرسانی با هشدار انجام شد.\n";
        $msg .= "کاربر: {$target}\nفیلد: {$field}\nمقدار جدید: {$value}\n\n";
        $msg .= "🤖 ربات: " . ($botOk ? ('موفق' . ($botNote ? " ({$botNote})" : '')) : "ناموفق ({$botNote})") . "\n";
        $msg .= "🌐 آواپی: " . ($siteOk ? "موفق ({$siteMsg})" : "ناموفق ({$siteMsg})");

        $bot->send_message($msg, $chatId, $menuKb);
        return true;
    }

    /* =======================================================================
     * (جدید) جستجوی کاربر — نمایش همه‌ی اطلاعات ثبت‌شده + دکمه‌ی چت مستقیم
     * ===================================================================== */
    if ($text === '🔍 جستجوی کاربر') {
        $state['mp_state'] = 'search_ask_id';
        mp_set_state($bot, $adminId, $state);
        $bot->send_message("🔍 آیدی عددی کاربر (chat_id تلگرام) را بفرستید:", $chatId, $cancelKb);
        return true;
    }

    if ($mp === 'search_ask_id') {
        $uid = preg_replace('/\D/', '', $text);
        if ($uid === '') {
            $bot->send_message('❌ آیدی باید عددی باشد. دوباره بفرستید:', $chatId, $cancelKb);
            return true;
        }

        $acc = $bot->Db->get('account', ['name', 'lastname', 'phone', 'username'], ['chat_id' => $uid]);
        $siteUser = mp_fetch_avapay_user($uid);

        if (!$acc && !$siteUser) {
            mp_clear_state($bot, $adminId);
            $bot->send_message("❌ کاربری با آیدی {$uid} نه در ربات و نه در سایت پیدا نشد.", $chatId, $menuKb);
            return true;
        }
        mp_clear_state($bot, $adminId);

        $info = "🔍 اطلاعات کاربر {$uid}\n";
        $info .= "━━━━━━━━━━━━━━\n";
        $info .= "🤖 از دیتابیس ربات:\n";
        if ($acc) {
            $info .= "▫️ نام: " . (($acc['name'] ?? '') !== '' ? $acc['name'] : '—') . "\n";
            $info .= "▫️ نام خانوادگی: " . (($acc['lastname'] ?? '') !== '' ? $acc['lastname'] : '—') . "\n";
            $info .= "▫️ تلفن: " . (($acc['phone'] ?? '') !== '' ? $acc['phone'] : '—') . "\n";
            $info .= "▫️ یوزرنیم: " . (($acc['username'] ?? '') !== '' ? '@' . $acc['username'] : '—') . "\n";
        } else {
            $info .= "▫️ در ربات ثبت‌نام نکرده است.\n";
        }
        $info .= "▫️ آیدی عددی: {$uid}\n";

        $info .= "\n🌐 از سایت AvaPay:\n";
        if ($siteUser) {
            $fullName = trim(($siteUser['first_name'] ?? '') . ' ' . ($siteUser['last_name'] ?? ''));
            $info .= "▫️ نام کامل: " . ($fullName !== '' ? $fullName : '—') . "\n";
            $info .= "▫️ ایمیل: " . (($siteUser['email'] ?? '') !== '' ? $siteUser['email'] : '—') . "\n";
            $info .= "▫️ شماره تلفن: " . (($siteUser['phone_number'] ?? '') !== '' ? $siteUser['phone_number'] : '—') . "\n";
            if (!empty($siteUser['account_number'])) $info .= "▫️ شماره حساب: {$siteUser['account_number']}\n";
            if (!empty($siteUser['iban_number']))    $info .= "▫️ شبا: {$siteUser['iban_number']}\n";
            if (isset($siteUser['kyc_status']) && $siteUser['kyc_status'] !== '') {
                $kycLabels = ['approved' => 'تاییدشده ✅', 'pending' => 'در انتظار بررسی ⏳', 'rejected' => 'ردشده ❌'];
                $info .= "▫️ احراز هویت: " . ($kycLabels[$siteUser['kyc_status']] ?? $siteUser['kyc_status']) . "\n";
            }
            if (!empty($siteUser['created_at'])) $info .= "▫️ تاریخ ثبت‌نام: {$siteUser['created_at']}\n";
            $wallets = $siteUser['__wallets'] ?? [];
            if (!empty($wallets)) {
                $info .= "▫️ موجودی کیف‌پول:\n";
                foreach ($wallets as $cur => $bal) {
                    $info .= "     • {$cur}: " . number_format($bal, ($cur === 'IRR' ? 0 : 2)) . "\n";
                }
            }
        } else {
            $info .= "▫️ در سایت AvaPay ثبت‌نام نکرده است.\n";
        }

        // دکمه‌ی «چت با کاربر»: tg://user?id= مستقیم به چت خصوصی کاربر در تلگرام باز می‌شود
        // (نیازی به یوزرنیم عمومی ندارد و روی اپ موبایل/دسکتاپ تلگرام کار می‌کند).
        $chatButtons = [[['text' => '💬 چت با کاربر', 'url' => "tg://user?id={$uid}"]]];
        if ($acc && !empty($acc['username'])) {
            $chatButtons[] = [['text' => '🔗 باز کردن از طریق یوزرنیم', 'url' => 'https://t.me/' . $acc['username']]];
        }

        $bot->send_message($info, $chatId, ['inline_keyboard' => $chatButtons]);
        // کیبورد اصلی پنل هم دوباره نشان داده شود تا کاربر بتواند ادامه دهد
        $bot->send_message('برای ادامه یکی از گزینه‌های زیر را انتخاب کنید:', $chatId, $menuKb);
        return true;
    }

    /* =======================================================================
     * ۲) مدیریت وضعیت آگهی
     * ===================================================================== */
    if ($text === '📝 مدیریت آگهی') {
        $state['mp_state'] = 'ad_ask_id';
        mp_set_state($bot, $adminId, $state);
        $bot->send_message("📝 شماره آگهی (ID) را بفرستید:", $chatId, $cancelKb);
        return true;
    }

    if ($mp === 'ad_ask_id') {
        $adId = preg_replace('/\D/', '', $text);
        if ($adId === '' || !$bot->Db->has('mozayede', ['id' => $adId])) {
            $bot->send_message('❌ آگهی با این شماره پیدا نشد. دوباره بفرستید:', $chatId, $cancelKb);
            return true;
        }
        $ad = $bot->Db->get('mozayede',
            ['id', 'chat_id', 'stat', 'arz', 'country', 'meghdar_arz', 'mablagh_pishnehad'],
            ['id' => $adId]);

        $statFa = ((int)($ad['stat'] ?? 0) === 1) ? '🟢 فعال' : '🔴 غیرفعال/لغو‌شده';
        $state['mp_state']  = 'ad_pick_action';
        $state['mp_target'] = $adId;
        mp_set_state($bot, $adminId, $state);

        $bot->send_message(
            "📝 آگهی #{$ad['id']}\n"
            . "کاربر: {$ad['chat_id']}\n"
            . "ارز: {$ad['arz']} — کشور: {$ad['country']}\n"
            . "مقدار: {$ad['meghdar_arz']} — نرخ: {$ad['mablagh_pishnehad']}\n"
            . "وضعیت: {$statFa}\n\nعملیات را انتخاب کنید:",
            $chatId,
            [
                'keyboard' => [
                    [['text' => '🟢 فعال‌سازی (up)'], ['text' => '🔴 لغو آگهی (cancel)']],
                    [['text' => '✅ اعلام انجام‌شدن آگهی']],
                    [['text' => '🚫 مسدودسازی صاحب آگهی (ban)']],
                    [['text' => '❌ خروج از مدیریت']],
                ],
                'resize_keyboard' => true,
            ]
        );
        return true;
    }

    if ($mp === 'ad_pick_action') {
        $adId = $target;
        $owner = $bot->Db->get('mozayede', 'chat_id', ['id' => $adId]);

        if ($text === '🟢 فعال‌سازی (up)') {
            $bot->Db->update('mozayede', ['stat' => 1], ['id' => $adId]);
            // آگهی دوباره فعال شده، پس دکمه‌ی «انجام شد» (اگر قبلاً گذاشته شده) برداشته می‌شود
            [$mOk, $mMsg] = mp_set_ad_button($bot, $adId, null);
            mp_clear_state($bot, $adminId);
            $bot->send_message("🟢 آگهی #{$adId} فعال شد.\n📣 کانال: " . ($mOk ? $mMsg : "به‌روزرسانی نشد ({$mMsg})"), $chatId, $menuKb);
            if ($owner) @$bot->send_message("🟢 آگهی شماره {$adId} شما توسط پشتیبانی فعال شد.", $owner);
            return true;
        }

        if ($text === '✅ اعلام انجام‌شدن آگهی') {
            // آگهی از لیست فعال خارج می‌شود و زیر پستش در کانال یک دکمه‌ی
            // شیشه‌ای «این آگهی با موفقیت انجام شد» می‌نشیند.
            $bot->Db->update('mozayede', ['stat' => 0], ['id' => $adId]);
            [$mOk, $mMsg] = mp_set_ad_button($bot, $adId, '✅ این آگهی با موفقیت انجام شد');
            mp_clear_state($bot, $adminId);
            $bot->send_message(
                "✅ آگهی #{$adId} به‌عنوان «انجام‌شده» علامت خورد.\n📣 کانال: " . ($mOk ? $mMsg : "به‌روزرسانی نشد ({$mMsg})"),
                $chatId, $menuKb
            );
            if ($owner) @$bot->send_message("✅ آگهی شماره {$adId} شما با موفقیت انجام شد و در کانال علامت‌گذاری گردید.", $owner);
            return true;
        }
        if ($text === '🔴 لغو آگهی (cancel)') {
            $bot->Db->update('mozayede', ['stat' => 0], ['id' => $adId]);
            [$mOk, $mMsg] = mp_set_ad_button($bot, $adId, '🔴 این آگهی لغو شد');
            mp_clear_state($bot, $adminId);
            $bot->send_message("🔴 آگهی #{$adId} لغو شد.\n📣 کانال: " . ($mOk ? $mMsg : "به‌روزرسانی نشد ({$mMsg})"), $chatId, $menuKb);
            if ($owner) @$bot->send_message("🔴 آگهی شماره {$adId} شما توسط پشتیبانی لغو شد.", $owner);
            return true;
        }
        if ($text === '🚫 مسدودسازی صاحب آگهی (ban)') {
            $bot->Db->update('mozayede', ['stat' => 0], ['id' => $adId]);
            if ($owner) {
                if (!$bot->Db->has('Block_list', ['chat_id' => $owner])) {
                    $bot->Db->insert('Block_list', ['chat_id' => $owner]);
                }
                @$bot->send_message("🚫 دسترسی شما به ربات توسط پشتیبانی مسدود شد.", $owner);
            }
            mp_clear_state($bot, $adminId);
            $bot->send_message("🚫 صاحب آگهی #{$adId} (کاربر {$owner}) مسدود شد و آگهی لغو گردید.", $chatId, $menuKb);
            return true;
        }

        $bot->send_message('❌ لطفاً یکی از دکمه‌های بالا را بزنید.', $chatId);
        return true;
    }

    /* =======================================================================
     * ۳) مسدودسازی / رفع مسدودی مستقیم
     * ===================================================================== */
    if ($text === '🚫 مسدودسازی کاربر' || $text === '✅ رفع مسدودی') {
        $state['mp_state'] = ($text === '🚫 مسدودسازی کاربر') ? 'ban_ask_id' : 'unban_ask_id';
        mp_set_state($bot, $adminId, $state);
        $bot->send_message('آیدی عددی کاربر را بفرستید:', $chatId, $cancelKb);
        return true;
    }

    if ($mp === 'ban_ask_id' || $mp === 'unban_ask_id') {
        $uid = preg_replace('/\D/', '', $text);
        if ($uid === '') { $bot->send_message('❌ آیدی باید عددی باشد.', $chatId, $cancelKb); return true; }

        if ($mp === 'ban_ask_id') {
            if (!$bot->Db->has('Block_list', ['chat_id' => $uid])) {
                $bot->Db->insert('Block_list', ['chat_id' => $uid]);
            }
            $bot->Db->update('mozayede', ['stat' => 0], ['chat_id' => $uid]); // آگهی‌های فعالش هم لغو شود
            mp_clear_state($bot, $adminId);
            $bot->send_message("🚫 کاربر {$uid} مسدود شد و آگهی‌های فعالش لغو گردید.", $chatId, $menuKb);
            @$bot->send_message('🚫 دسترسی شما به ربات توسط پشتیبانی مسدود شد.', $uid);
        } else {
            $bot->Db->delete('Block_list', ['chat_id' => $uid]);
            mp_clear_state($bot, $adminId);
            $bot->send_message("✅ مسدودی کاربر {$uid} برداشته شد.", $chatId, $menuKb);
            @$bot->send_message('✅ دسترسی شما به ربات دوباره فعال شد.', $uid);
        }
        return true;
    }

    /* =======================================================================
     * ۴) پیام به یک کاربر / پیام همگانی
     * ===================================================================== */
    if ($text === '📢 پیام به یک کاربر') {
        $state['mp_state'] = 'msg_ask_id';
        mp_set_state($bot, $adminId, $state);
        $bot->send_message('آیدی عددی کاربر گیرنده را بفرستید:', $chatId, $cancelKb);
        return true;
    }

    if ($mp === 'msg_ask_id') {
        $uid = preg_replace('/\D/', '', $text);
        if ($uid === '') { $bot->send_message('❌ آیدی باید عددی باشد.', $chatId, $cancelKb); return true; }
        $state['mp_state']  = 'msg_ask_text';
        $state['mp_target'] = $uid;
        mp_set_state($bot, $adminId, $state);
        $bot->send_message("متن پیام برای کاربر {$uid} را بفرستید:", $chatId, $cancelKb);
        return true;
    }

    if ($mp === 'msg_ask_text') {
        $uid = $target;
        mp_clear_state($bot, $adminId);
        $ok = @$bot->send_message($text, $uid);
        $bot->send_message($ok !== false
            ? "✅ پیام برای کاربر {$uid} ارسال شد."
            : "❌ ارسال پیام به {$uid} ناموفق بود (احتمالاً ربات را بلاک کرده است).",
            $chatId, $menuKb);
        return true;
    }

    if ($text === '📣 پیام همگانی') {
        $state['mp_state'] = 'bc_ask_text';
        mp_set_state($bot, $adminId, $state);
        $bot->send_message(
            "📣 متن پیام همگانی را بفرستید — یا یک عکس (همراه با کپشن دلخواه) ارسال کنید.\n"
            . "⚠️ این پیام برای همه‌ی کاربران ربات ارسال می‌شود.",
            $chatId, $cancelKb
        );
        return true;
    }

    if ($mp === 'bc_ask_text') {
        // (اصلاح) پشتیبانی از عکس + توضیحات، علاوه‌بر متن ساده.
        if ($mpHasPhoto) {
            if (!$mpPhotoFileId) {
                $bot->send_message('❌ دریافت عکس ناموفق بود، دوباره تلاش کنید.', $chatId, $cancelKb);
                return true;
            }
            $body    = $mpCaption; // کپشن اختیاری است
            $payload = ['type' => 'photo', 'file_id' => $mpPhotoFileId, 'caption' => $body];
        } elseif ($text !== '') {
            $body    = $text;
            $payload = ['type' => 'text', 'text' => $body];
        } else {
            $bot->send_message('⚠️ لطفاً یک متن یا یک عکس (همراه با کپشن دلخواه) بفرستید.', $chatId, $cancelKb);
            return true;
        }

        $state['mp_state']  = 'bc_confirm';
        $state['mp_target'] = json_encode($payload, JSON_UNESCAPED_UNICODE);
        mp_set_state($bot, $adminId, $state);
        // نکته: امضای count در Medoo به‌صورت count(table, join, column, where) است،
        // پس پاس‌دادن آرایه‌ی خالی به‌جای شرط، اشتباه تفسیر می‌شود. با یک آرگومان صدا می‌زنیم.
        $count = (int)$bot->Db->count('account');
        $previewLabel = $mpHasPhoto ? '📣 پیش‌نمایش پیام همگانی (همراه با عکس):' : '📣 پیش‌نمایش پیام همگانی:';
        $previewBody  = ($body !== '') ? $body : '(بدون متن)';
        $previewMsg   = "{$previewLabel}\n\n---\n{$previewBody}\n---\n\n"
            . "این پیام برای حدود {$count} کاربر ارسال می‌شود. تأیید می‌کنید؟";
        $confirmKb = ['keyboard' => [[['text' => '✅ تأیید و ارسال']], [['text' => '❌ خروج از مدیریت']]], 'resize_keyboard' => true];
        if ($mpHasPhoto) {
            $bot->send_photo($mpPhotoFileId, $previewMsg, $chatId, $confirmKb);
        } else {
            $bot->send_message($previewMsg, $chatId, $confirmKb);
        }
        return true;
    }

    if ($mp === 'bc_confirm') {
        if ($text !== '✅ تأیید و ارسال') {
            $bot->send_message('برای ارسال، دکمه‌ی «✅ تأیید و ارسال» را بزنید.', $chatId);
            return true;
        }
        $payload = json_decode((string)$target, true);
        mp_clear_state($bot, $adminId);
        // سازگاری با نسخه‌ی قبلی که مستقیماً متنِ خام را در mp_target ذخیره می‌کرد
        if (!is_array($payload) || !isset($payload['type'])) {
            $payload = ['type' => 'text', 'text' => (string)$target];
        }
        $bcIsPhoto = ($payload['type'] === 'photo') && !empty($payload['file_id']);

        $users = $bot->Db->select('account', ['chat_id']);
        $sent = 0; $failed = 0;
        foreach ($users as $u) {
            $uid = $u['chat_id'] ?? null;
            if (!$uid) continue;
            try {
                if ($bcIsPhoto) {
                    $bcCaption = ($payload['caption'] ?? '') !== '' ? $payload['caption'] : null;
                    $r = @$bot->send_photo($payload['file_id'], $bcCaption, $uid);
                } else {
                    $r = @$bot->send_message($payload['text'] ?? '', $uid);
                }
                if ($r === false) $failed++; else $sent++;
            } catch (\Throwable $e) { $failed++; }
            // رعایت محدودیت نرخ تلگرام (حدود ۳۰ پیام در ثانیه)
            usleep(40000);
        }
        $bot->send_message("📣 ارسال همگانی تمام شد.\n✅ موفق: {$sent}\n❌ ناموفق: {$failed}", $chatId, $menuKb);
        return true;
    }

    return false; // این پیام مربوط به پنل مدیریت نبود
}
