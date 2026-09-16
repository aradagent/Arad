<?php
/**
 * includes/offer_actions.php
 * ---------------------------------------------------------------------------
 * منطق مشترک «پذیرش / رد» پیشنهادهای بازار ارز (ad_offers) که هم توسط
 * api/offer_api.php (اپلیکیشن AVA PAY) و هم توسط ربات تلگرام (mainbot/update.php)
 * استفاده می‌شود تا یک منبع واحد از حقیقت داشته باشیم.
 *
 * این فایل هیچ خروجی‌ای چاپ نمی‌کند و فقط توابع را تعریف می‌کند.
 * تمام توابع یک اتصال mysqli فعال به دیتابیس اپلیکیشن (aradexch_app) می‌گیرند.
 * ---------------------------------------------------------------------------
 */

if (!defined('AVAPAY_BOT_TOKEN')) {
    // اگر از داخل اپ فراخوانی شود BOT_TOKEN تعریف شده است؛ در غیر این‌صورت مقدار پیش‌فرض.
    define('AVAPAY_BOT_TOKEN', defined('BOT_TOKEN') ? BOT_TOKEN : '5937135973:AAEwK4lxar3xRM_mwvapLWNuw26VUv2c6e4');
}
if (!defined('AVAPAY_ADMIN_TG')) {
    define('AVAPAY_ADMIN_TG', defined('ADMIN_TELEGRAM_ID') ? ADMIN_TELEGRAM_ID : '5330629504');
}
if (!function_exists('avapay_get_user_tier_discount')) {
    require_once __DIR__ . '/tier_system.php';
}

require_once __DIR__ . '/referral_system.php';
if (!defined('AVAPAY_APP_URL')) {
    define('AVAPAY_APP_URL', 'https://aradexchange.com/ledor/arad.php');
}

/* ---------------------------------------------------------------------------
 * ارسال پیام تلگرام (نسخه‌ی مستقل، بدون وابستگی به offer_api)
 * ------------------------------------------------------------------------- */
if (!function_exists('oa_sendTelegram')) {
    function oa_sendTelegram($chatId, $message, $inlineKeyboard = null) {
        if (empty($chatId)) return false;
        $url = "https://api.telegram.org/bot" . AVAPAY_BOT_TOKEN . "/sendMessage";
        $postData = [
            'chat_id'                  => $chatId,
            'text'                     => $message,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
        ];
        if ($inlineKeyboard) {
            $postData['reply_markup'] = json_encode($inlineKeyboard);
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_exec($ch);
        curl_close($ch);
        return true;
    }
}

/* ---------------------------------------------------------------------------
 * ویرایش دکمه‌های یک پیام تلگرام (برای غیرفعال‌کردن دکمه‌ها بعد از اقدام)
 * ------------------------------------------------------------------------- */
if (!function_exists('oa_editTelegramMarkup')) {
    function oa_editTelegramMarkup($chatId, $messageId, $text) {
        if (empty($chatId) || empty($messageId)) return false;
        $url = "https://api.telegram.org/bot" . AVAPAY_BOT_TOKEN . "/editMessageReplyMarkup";
        $markup = ['inline_keyboard' => [[['text' => $text, 'callback_data' => 'avapay_noop']]]];
        $postData = [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'reply_markup' => json_encode($markup),
        ];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_exec($ch);
        curl_close($ch);
        return true;
    }
}

/* ---------------------------------------------------------------------------
 * آیا کاربر اجازه‌ی نوتیفیکیشن تلگرام دارد؟ (احترام به تنظیم پروفایل)
 * ------------------------------------------------------------------------- */
if (!function_exists('oa_tgAllowed')) {
    function oa_tgAllowed($conn, $userId) {
        $col = $conn->query("SHOW COLUMNS FROM users LIKE 'notify_telegram'");
        if (!$col || $col->num_rows === 0) return true; // ستون نیست => مجاز
        $r = $conn->query("SELECT notify_telegram FROM users WHERE id=" . intval($userId));
        $row = $r ? $r->fetch_assoc() : null;
        return !$row || (int)($row['notify_telegram'] ?? 1) === 1;
    }
}

/* ---------------------------------------------------------------------------
 * محاسبه‌ی کمیسیونِ اپ (همان فرمول arad.php):
 *   کمیسیون پایه = مقدار ≤ 1000 ? 5 : مقدار × 0.01
 *   کمیسیون نهایی = پایه − (پایه × درصد تخفیف کاربر / 100)
 * $forUserId اینجا «پیشنهاد‌دهنده (buyer)» است، چون تخفیف روی او اعمال می‌شود.
 * $totalToman (اختیاری): مبلغ کل معامله به تومان (مقدار × قیمت واحد). برای تطبیق
 *   قوانین پلکانیِ «بر اساس تومان» لازم است؛ چون آن قوانین باید صرف‌نظر از نوع ارز
 *   معامله (دلار/یورو/تتر/...) با بازه‌ی تومانیِ خودشان مقایسه شوند، نه با مقدار ارز.
 * ------------------------------------------------------------------------- */
if (!function_exists('oa_commission')) {
    function oa_commission($conn, $requestedAmount, $forUserId, $currency = 'ALL', $totalToman = null) {
        $base = ($requestedAmount <= 1000) ? 5 : ($requestedAmount * 0.01);
        $currency = strtoupper((string)$currency);

        // ۰) اولویتِ مطلق: «کمیسیون تخفیف ثابت» — اگر ادمین برای این کاربر یک مبلغ
        //    کاملاً ثابت (مثلاً همیشه ۳ یورو، فارغ از حجم/ارزِ معامله) تعریف کرده باشد،
        //    همه‌ی قوانین دیگر (پلکانی/پیش‌فرض/تخفیف درصدی/تیر) کاملاً نادیده گرفته
        //    می‌شوند — این کاربر همیشه فقط همان مبلغ ثابت را می‌پردازد.
        $chkFixed = $conn->query("SHOW TABLES LIKE 'user_fixed_commission'");
        if ($chkFixed && $chkFixed->num_rows > 0) {
            $fstmt = $conn->prepare("SELECT * FROM user_fixed_commission WHERE user_id = ? LIMIT 1");
            if ($fstmt) {
                $fstmt->bind_param("i", $forUserId);
                $fstmt->execute();
                $fixedRow = $fstmt->get_result()->fetch_assoc();
                if ($fixedRow) {
                    $fixedAmount   = (float)$fixedRow['fixed_amount'];
                    $fixedCurrency = strtoupper($fixedRow['fixed_currency']);

                    // معادل تومانیِ همین مبلغ ثابت (برای کسر درست از موجودی، حتی اگر
                    // کاربر با ارزی غیر از ارز کمیسیونِ ثابتش معامله کند) — بر اساس
                    // آخرین نرخ کش‌شده در market_rates؛ اگر IRR باشد، خودش تومان است.
                    $fixedToman = $fixedAmount;
                    if ($fixedCurrency !== 'IRR') {
                        $rr = $conn->prepare("SELECT sell FROM market_rates WHERE code = ? LIMIT 1");
                        if ($rr) {
                            $rr->bind_param("s", $fixedCurrency);
                            $rr->execute();
                            $rateRow = $rr->get_result()->fetch_assoc();
                            if ($rateRow && (float)$rateRow['sell'] > 0) {
                                $fixedToman = $fixedAmount * (float)$rateRow['sell'];
                            }
                        }
                    }

                    return [
                        'base'           => $base,
                        'discount'       => 0,
                        'final'          => $fixedAmount,
                        'fixed_override' => true,
                        'fixed_currency' => $fixedCurrency,
                        'final_toman'    => $fixedToman,
                    ];
                }
            }
        }

        $chkRules = $conn->query("SHOW TABLES LIKE 'user_commission_rules'");
        if ($chkRules && $chkRules->num_rows > 0) {
            // ۱) اول قانون پلکانیِ «بر اساس تومان» را بررسی می‌کنیم (اگر مبلغ کل تومانی
            //    در دسترس باشد). این قانون برای کاربر ثابت است و صرف‌نظر از اینکه با چه
            //    ارزی معامله می‌کند (دلار/یورو/تتر/...) اعمال می‌شود.
            if ($totalToman !== null) {
                $tstmt = $conn->prepare("SELECT * FROM user_commission_rules
                    WHERE user_id = ? AND unit = 'toman'
                      AND min_amount <= ? AND (max_amount IS NULL OR max_amount >= ?)
                    ORDER BY min_amount DESC
                    LIMIT 1");
                if ($tstmt) {
                    $tstmt->bind_param("idd", $forUserId, $totalToman, $totalToman);
                    $tstmt->execute();
                    $tomanRule = $tstmt->get_result()->fetch_assoc();
                    if ($tomanRule) {
                        $final = ($tomanRule['rule_type'] === 'percent')
                            ? ($totalToman * (floatval($tomanRule['value']) / 100))
                            : floatval($tomanRule['value']);
                        return ['base' => $base, 'discount' => 0, 'final' => $final, 'rule_applied' => $tomanRule];
                    }
                }
            }

            // ۲) در غیر این‌صورت، قانون پلکانیِ «بر اساس ارز» برای همین کاربر/ارز/بازهٔ مقدار.
            //    ثابت (fixed) => همان مقدار جایگزین کمیسیون پایه می‌شود.
            //    درصدی (percent) => آن درصد روی مبلغ درخواستی اعمال می‌شود.
            $rstmt = $conn->prepare("SELECT * FROM user_commission_rules
                WHERE user_id = ? AND unit = 'currency' AND (currency = ? OR currency = 'ALL')
                  AND min_amount <= ? AND (max_amount IS NULL OR max_amount >= ?)
                ORDER BY (currency = 'ALL') ASC, min_amount DESC
                LIMIT 1");
            if ($rstmt) {
                $rstmt->bind_param("isdd", $forUserId, $currency, $requestedAmount, $requestedAmount);
                $rstmt->execute();
                $rule = $rstmt->get_result()->fetch_assoc();
                if ($rule) {
                    $final = ($rule['rule_type'] === 'percent')
                        ? ($requestedAmount * (floatval($rule['value']) / 100))
                        : floatval($rule['value']);
                    return ['base' => $base, 'discount' => 0, 'final' => $final, 'rule_applied' => $rule];
                }
            }
        }

        // ۳) در غیر این‌صورت، قانونِ «کمیسیون پیش‌فرض برای همه‌ی کاربران» را بررسی می‌کنیم
        //    (تنظیم‌شده در پنل ادمین، بخش تخفیف و کمیسیون). دقیقاً همان اولویتِ
        //    تومان-اول → ارز، اما این‌بار مستقل از کاربر، برای هر کسی که قانون
        //    اختصاصیِ خودش را نداشته باشد.
        $chkDefault = $conn->query("SHOW TABLES LIKE 'default_commission_rules'");
        if ($chkDefault && $chkDefault->num_rows > 0) {
            if ($totalToman !== null) {
                $dtstmt = $conn->prepare("SELECT * FROM default_commission_rules
                    WHERE unit = 'toman'
                      AND min_amount <= ? AND (max_amount IS NULL OR max_amount >= ?)
                    ORDER BY min_amount DESC
                    LIMIT 1");
                if ($dtstmt) {
                    $dtstmt->bind_param("dd", $totalToman, $totalToman);
                    $dtstmt->execute();
                    $defTomanRule = $dtstmt->get_result()->fetch_assoc();
                    if ($defTomanRule) {
                        $final = ($defTomanRule['rule_type'] === 'percent')
                            ? ($totalToman * (floatval($defTomanRule['value']) / 100))
                            : floatval($defTomanRule['value']);
                        return ['base' => $base, 'discount' => 0, 'final' => $final, 'rule_applied' => $defTomanRule];
                    }
                }
            }

            $drstmt = $conn->prepare("SELECT * FROM default_commission_rules
                WHERE unit = 'currency'
                  AND min_amount <= ? AND (max_amount IS NULL OR max_amount >= ?)
                ORDER BY min_amount DESC
                LIMIT 1");
            if ($drstmt) {
                $drstmt->bind_param("dd", $requestedAmount, $requestedAmount);
                $drstmt->execute();
                $defRule = $drstmt->get_result()->fetch_assoc();
                if ($defRule) {
                    $final = ($defRule['rule_type'] === 'percent')
                        ? ($requestedAmount * (floatval($defRule['value']) / 100))
                        : floatval($defRule['value']);
                    return ['base' => $base, 'discount' => 0, 'final' => $final, 'rule_applied' => $defRule];
                }
            }
        }

        // ۴) در غیر این‌صورت، تخفیف درصدیِ ساده روی کمیسیون پایه (رفتار قبلی)
        $discount = 0.0;
        $chk = $conn->query("SHOW TABLES LIKE 'user_discounts'");
        if ($chk && $chk->num_rows > 0) {
            $stmt = $conn->prepare("SELECT discount_percent FROM user_discounts WHERE user_id = ? AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY id DESC LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("i", $forUserId);
                $stmt->execute();
                $res = $stmt->get_result()->fetch_assoc();
                if ($res) $discount = floatval($res['discount_percent']);
            }
        }

        // تخفیف خودکار بر اساس سطح/تیر کاربر (Tier/VIP بر پایه‌ی حجم معاملات).
        // فقط اگر از تخفیف دستیِ فعلی کاربر بیشتر باشد اعمال می‌شود، یعنی
        // تخفیف دستیِ ادمین همیشه حداقل تضمین‌شده باقی می‌ماند و این فقط
        // یک لایه‌ی خودکارِ اضافه به نفع کاربر است.
        if (function_exists('avapay_get_user_tier_discount')) {
            $tierDiscount = avapay_get_user_tier_discount($conn, $forUserId);
            if ($tierDiscount > $discount) $discount = $tierDiscount;
        }

        $final = $base - ($base * $discount / 100);
        return ['base' => $base, 'discount' => $discount, 'final' => $final];
    }
}

/* ---------------------------------------------------------------------------
 * ثبت نوتیفیکیشن دیتابیسی (اگر تابع اپ در دسترس نبود، خودمان می‌نویسیم)
 * ------------------------------------------------------------------------- */
if (!function_exists('oa_dbNotify')) {
    function oa_dbNotify($conn, $userId, $type, $title, $message, $relatedId = null) {
        $conn->query("CREATE TABLE IF NOT EXISTS `user_notifications` (
            `id` INT PRIMARY KEY AUTO_INCREMENT,
            `user_id` INT NOT NULL,
            `type` VARCHAR(50) NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `message` TEXT,
            `related_id` INT DEFAULT NULL,
            `is_read` TINYINT(1) DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id)
        )");
        $stmt = $conn->prepare("INSERT INTO user_notifications (user_id, type, title, message, related_id) VALUES (?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("isssi", $userId, $type, $title, $message, $relatedId);
            $stmt->execute();
        }
    }
}

/* ===========================================================================
 * پذیرش پیشنهاد
 * $offerId : شناسه‌ی پیشنهاد
 * $actorUserId : کاربری که اقدام را انجام می‌دهد (باید فروشنده/آگهی‌دهنده باشد)
 *                از داخل ربات، این همان seller است که با telegram_id تطبیق داده شده.
 * خروجی: ['success'=>bool, 'message'=>string, 'deal_code'=>?string]
 * =========================================================================== */
if (!function_exists('avapay_offer_accept')) {
    function avapay_offer_accept($conn, $offerId, $actorUserId) {
        $offerId = intval($offerId);

        $sql = "SELECT o.*, a.currency, a.user_id AS seller_id,
                       ub.id AS buyer_id, ub.first_name AS buyer_first, ub.last_name AS buyer_last,
                       ub.telegram_id AS buyer_telegram, ub.email AS buyer_email, ub.phone AS buyer_phone,
                       us.first_name AS seller_first, us.last_name AS seller_last,
                       us.telegram_id AS seller_telegram, us.email AS seller_email, us.phone AS seller_phone
                FROM ad_offers o
                JOIN user_ads a ON o.ad_id = a.id
                JOIN users ub ON o.buyer_id = ub.id
                JOIN users us ON a.user_id = us.id
                WHERE o.id = ? AND o.status = 'pending'";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $offerId);
        $stmt->execute();
        $offer = $stmt->get_result()->fetch_assoc();

        if (!$offer) {
            return ['success' => false, 'message' => 'پیشنهاد یافت نشد یا قبلاً پاسخ داده شده است'];
        }
        if ((int)$offer['seller_id'] !== (int)$actorUserId) {
            return ['success' => false, 'message' => 'شما اجازه‌ی پاسخ به این پیشنهاد را ندارید'];
        }

        $totalPrice = $offer['requested_amount'] * $offer['offered_price'];
        $dealCode   = 'ARAD' . date('Ymd') . rand(10000, 99999);
        $com        = oa_commission($conn, (float)$offer['requested_amount'], (int)$offer['buyer_id'], (string)$offer['currency'], (float)$totalPrice);

        $conn->query("UPDATE ad_offers SET status = 'accepted', responded_at = NOW() WHERE id = $offerId");

        // بستن نوتیفیکیشن «پیشنهاد دریافتی» در زنگولهٔ آگهی‌دهنده (حتی اگر پذیرش از تلگرام انجام شده باشد)
        // تا دیگر دکمه‌های پذیرش/رد برای این پیشنهاد نمایش داده نشوند.
        $conn->query("UPDATE user_notifications SET is_read = 1 WHERE type = 'offer_received' AND related_id = $offerId");


        $dealSql  = "INSERT INTO ad_deals (offer_id, ad_id, buyer_id, seller_id, currency, amount, price_per_unit, total_price, deal_code)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $dealStmt = $conn->prepare($dealSql);
        $dealStmt->bind_param("iiiisddds", $offerId, $offer['ad_id'], $offer['buyer_id'], $offer['seller_id'],
            $offer['currency'], $offer['requested_amount'], $offer['offered_price'], $totalPrice, $dealCode);
        $dealStmt->execute();
        $__dealInsertId = $conn->insert_id;

        // ---- ذخیره‌ی کمیسیونِ همین معامله (برای گزارش درآمد ادمین) ----
        // کمیسیون در لحظه‌ی پذیرش با قوانین *همان زمان* محاسبه و برای همیشه
        // ثبت می‌شود؛ اگر بعداً قوانین کمیسیون تغییر کند، معاملات قدیمی
        // دست‌نخورده می‌مانند (که درست هم همین است).
        // درآمد ادمین از هر معامله = کمیسیون خریدار + کمیسیون فروشنده.
        try {
            // نکته: ADD COLUMN IF NOT EXISTS فقط در MariaDB کار می‌کند و ADD بدون
            // بررسی روی ستون موجود خطا می‌دهد؛ چون از PHP 8.1 خطاهای mysqli
            // Exception هستند (و @ جلویشان را نمی‌گیرد)، اینجا اول وجود ستون
            // بررسی می‌شود تا این بلوک هیچ‌وقت پذیرش پیشنهاد را خراب نکند.
            $__cols = [];
            $__cr = $conn->query("SHOW COLUMNS FROM `ad_deals`");
            if ($__cr) { while ($__c = $__cr->fetch_assoc()) { $__cols[strtolower($__c['Field'])] = true; } }
            if (!isset($__cols['commission_amount']))   $conn->query("ALTER TABLE `ad_deals` ADD COLUMN `commission_amount` DECIMAL(20,6) DEFAULT NULL");
            if (!isset($__cols['commission_currency'])) $conn->query("ALTER TABLE `ad_deals` ADD COLUMN `commission_currency` VARCHAR(10) DEFAULT NULL");

            // واحد کمیسیونِ محاسبه‌شده برای هر طرف
            $__unitOf = function($c) use ($offer) {
                if (!empty($c['fixed_override'])) return $c['fixed_currency'];
                if (isset($c['rule_applied']) && ($c['rule_applied']['unit'] ?? '') === 'toman') return 'IRR';
                return ($offer['currency'] === 'IRR') ? 'IRR' : $offer['currency'];
            };

            // کمیسیون فروشنده (خریدار قبلاً در $com محاسبه شده است)
            $comSeller = oa_commission($conn, (float)$offer['requested_amount'], (int)$offer['seller_id'], (string)$offer['currency'], (float)$totalPrice);

            $__buyerUnit  = $__unitOf($com);
            $__sellerUnit = $__unitOf($comSeller);
            $__buyerAmt   = (float)($com['final'] ?? 0);
            $__sellerAmt  = (float)($comSeller['final'] ?? 0);

            // اگر هر دو طرف در یک ارز باشند، جمع می‌شوند. اگر ارزشان فرق کند،
            // برای جلوگیری از جمعِ اشتباهِ دو ارز متفاوت، فقط ارز خریدار مبنا
            // قرار می‌گیرد و سهم فروشنده جداگانه در همان ارز خودش نگه داشته
            // نمی‌شود (این حالت در عمل نادر است چون قوانین معمولاً یکسان‌اند).
            if ($__buyerUnit === $__sellerUnit) {
                $__commAmt  = $__buyerAmt + $__sellerAmt;
                $__commUnit = $__buyerUnit;
            } else {
                $__commAmt  = $__buyerAmt;
                $__commUnit = $__buyerUnit;
                error_log("ad_deals #{$__dealInsertId}: buyer/seller commission currencies differ ({$__buyerUnit} vs {$__sellerUnit}); stored buyer side only.");
            }

            $__cst = $conn->prepare("UPDATE ad_deals SET commission_amount = ?, commission_currency = ? WHERE id = ?");
            if ($__cst) { $__cst->bind_param('dsi', $__commAmt, $__commUnit, $__dealInsertId); $__cst->execute(); $__cst->close(); }
        } catch (\Throwable $e) {
            error_log('ad_deals commission persist error: ' . $e->getMessage());
        }

        $conn->query("UPDATE user_ads SET status = 'completed' WHERE id = " . intval($offer['ad_id']));

        // ---- پورسانت زیرمجموعه‌گیری: اگر خریدار و/یا فروشنده کاربر دعوت‌شده باشند،
        // به معرف هرکدام (جداگانه) کمیسیون تراکنش موفق واریز می‌شود ----
        try {
            ava_ref_process_transaction($conn, $__dealInsertId, [(int)$offer['buyer_id'], (int)$offer['seller_id']]);
        } catch (\Throwable $e) {
            error_log('referral commission error: ' . $e->getMessage());
        }

        $commUnitApplied = !empty($com['fixed_override'])
            ? $com['fixed_currency']
            : ((isset($com['rule_applied']) && ($com['rule_applied']['unit'] ?? '') === 'toman')
                ? 'تومان'
                : (($offer['currency'] === 'IRR') ? 'تومان' : $offer['currency']));
        $commLine = "🧾 کمیسیون: " . number_format($com['final']) . " " . $commUnitApplied . "\n";

        // ---- پیام به ادمین ----
        $adminMessage  = "✅ <b>معامله جدید در پلتفرم AVA PAY</b>\n";
        $adminMessage .= "🔑 کد معامله: <code>{$dealCode}</code>\n";
        $adminMessage .= "💱 ارز: {$offer['currency']}\n";
        $adminMessage .= "👤 خریدار: {$offer['buyer_first']} {$offer['buyer_last']} (@" . ($offer['buyer_telegram'] ?? '-') . ")\n";
        $adminMessage .= "👤 فروشنده: {$offer['seller_first']} {$offer['seller_last']} (@" . ($offer['seller_telegram'] ?? '-') . ")\n";
        $adminMessage .= "📊 مقدار: " . number_format($offer['requested_amount']) . "\n";
        $adminMessage .= "💵 قیمت واحد: " . number_format($offer['offered_price']) . " تومان\n";
        $adminMessage .= "💎 مبلغ کل: " . number_format($totalPrice) . " تومان\n";
        $adminMessage .= $commLine;
        oa_sendTelegram(AVAPAY_ADMIN_TG, $adminMessage);

        // ---- نوتیفیکیشن دیتابیسی به خریدار ----
        $acceptTitle   = 'پیشنهاد شما پذیرفته شد ✅';
        $acceptMessage = "پیشنهاد شما برای {$offer['currency']} با کد معامله {$dealCode} پذیرفته شد. مبلغ کل: " . number_format($totalPrice) . " تومان";
        oa_dbNotify($conn, $offer['buyer_id'], 'offer_accepted', $acceptTitle, $acceptMessage, $offerId);

        // پوش به خریدار (اگر تابع اپ موجود بود)
        if (function_exists('sendPushToUser')) {
            @sendPushToUser($conn, $offer['buyer_id'], $acceptTitle, $acceptMessage, 'offer_accepted', '/ledor/arad.php?tab=offers&section=sent', $offerId);
        }

        $enterBtn = ['inline_keyboard' => [[['text' => '🚀 ورود به AVA PAY', 'url' => AVAPAY_APP_URL]]]];

        // ---- تلگرام به خریدار (پیشنهاد‌دهنده) ----
        if (!empty($offer['buyer_telegram']) && oa_tgAllowed($conn, $offer['buyer_id'])) {
            $buyerMsg  = "✅ <b>پیشنهاد شما پذیرفته شد!</b>\n";
            $buyerMsg .= "📲 دریافت‌شده از طریق اپلیکیشن <b>AVA PAY</b>\n\n";
            $buyerMsg .= "🎯 ارز: {$offer['currency']}\n";
            $buyerMsg .= "📊 مقدار: " . number_format($offer['requested_amount']) . "\n";
            $buyerMsg .= "💰 قیمت: " . number_format($offer['offered_price']) . " تومان\n";
            $buyerMsg .= "💎 مبلغ کل: " . number_format($totalPrice) . " تومان\n";
            $buyerMsg .= $commLine;
            $buyerMsg .= "🔑 کد معامله: <code>{$dealCode}</code>\n\n";
            $buyerMsg .= "👤 فروشنده: {$offer['seller_first']} {$offer['seller_last']}\n";
            $buyerMsg .= "🆔 تلگرام: @" . ($offer['seller_telegram'] ?? '-');
            oa_sendTelegram($offer['buyer_telegram'], $buyerMsg, $enterBtn);
        }

        // ---- تلگرام به فروشنده (آگهی‌دهنده) ----
        if (!empty($offer['seller_telegram']) && oa_tgAllowed($conn, $offer['seller_id'])) {
            $sellerMsg  = "✅ <b>شما این پیشنهاد را پذیرفتید</b>\n";
            $sellerMsg .= "📲 دریافت‌شده از طریق اپلیکیشن <b>AVA PAY</b>\n\n";
            $sellerMsg .= "🎯 ارز: {$offer['currency']}\n";
            $sellerMsg .= "💎 مبلغ کل: " . number_format($totalPrice) . " تومان\n";
            $sellerMsg .= $commLine;
            $sellerMsg .= "🔑 کد معامله: <code>{$dealCode}</code>\n\n";
            $sellerMsg .= "👤 خریدار: {$offer['buyer_first']} {$offer['buyer_last']}\n";
            $sellerMsg .= "🆔 تلگرام: @" . ($offer['buyer_telegram'] ?? '-');
            oa_sendTelegram($offer['seller_telegram'], $sellerMsg, $enterBtn);
        }

        // ایمیل (اگر تابع اپ موجود بود)
        if (function_exists('sendEmailNotification')) {
            $em = $conn->query("SELECT email FROM users WHERE id=" . intval($offer['buyer_id']));
            $emRow = $em ? $em->fetch_assoc() : null;
            if ($emRow && !empty($emRow['email'])) {
                @sendEmailNotification($emRow['email'], $acceptTitle, $acceptMessage);
            }
        }

        // (جدید) اگر ریشه‌ی این آگهی یک آگهیِ mainbot باشد، پستِ کانال را
        // با متنِ تازه (شاملِ همین قبولی در فهرستِ پیشنهادها) و دکمه‌ی
        // «آگهی در حال انجام است» ادیت کن
        require_once __DIR__ . '/../includes/mozayede_bridge.php';
        moz_syncOfferResponded($conn, (int)$offer['ad_id'], true);

        return ['success' => true, 'message' => '✅ پیشنهاد پذیرفته شد', 'deal_code' => $dealCode, 'offer' => $offer];
    }
}

/* ===========================================================================
 * رد پیشنهاد
 * =========================================================================== */
if (!function_exists('avapay_offer_reject')) {
    function avapay_offer_reject($conn, $offerId, $actorUserId, $reason = 'بدون توضیح') {
        $offerId = intval($offerId);
        $reason  = trim($reason) === '' ? 'بدون توضیح' : trim($reason);

        $sql = "SELECT o.*, a.user_id AS seller_id, a.currency,
                       ub.id AS buyer_id, ub.telegram_id AS buyer_telegram,
                       ub.first_name AS buyer_first, ub.last_name AS buyer_last, ub.email AS buyer_email
                FROM ad_offers o
                JOIN user_ads a ON o.ad_id = a.id
                JOIN users ub ON o.buyer_id = ub.id
                WHERE o.id = ? AND o.status = 'pending'";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $offerId);
        $stmt->execute();
        $offer = $stmt->get_result()->fetch_assoc();

        if (!$offer) {
            return ['success' => false, 'message' => 'پیشنهاد یافت نشد یا قبلاً پاسخ داده شده است'];
        }
        if ((int)$offer['seller_id'] !== (int)$actorUserId) {
            return ['success' => false, 'message' => 'شما اجازه‌ی پاسخ به این پیشنهاد را ندارید'];
        }

        $upd = $conn->prepare("UPDATE ad_offers SET status = 'rejected', reject_reason = ?, responded_at = NOW() WHERE id = ?");
        $upd->bind_param("si", $reason, $offerId);
        $upd->execute();

        // بستن نوتیفیکیشن «پیشنهاد دریافتی» در زنگولهٔ آگهی‌دهنده (حتی اگر رد از تلگرام انجام شده باشد)
        $conn->query("UPDATE user_notifications SET is_read = 1 WHERE type = 'offer_received' AND related_id = $offerId");

        $rejectTitle   = 'پیشنهاد شما رد شد ❌';
        $rejectMessage = "پیشنهاد شما برای {$offer['currency']} رد شد. دلیل: " . mb_substr($reason, 0, 100);
        oa_dbNotify($conn, $offer['buyer_id'], 'offer_rejected', $rejectTitle, $rejectMessage, $offerId);

        if (function_exists('sendPushToUser')) {
            @sendPushToUser($conn, $offer['buyer_id'], $rejectTitle, $rejectMessage, 'offer_rejected', '/ledor/arad.php?tab=market', $offerId);
        }

        if (!empty($offer['buyer_telegram']) && oa_tgAllowed($conn, $offer['buyer_id'])) {
            $msg  = "❌ <b>پیشنهاد شما رد شد</b>\n";
            $msg .= "📲 دریافت‌شده از طریق اپلیکیشن <b>AVA PAY</b>\n\n";
            $msg .= "🎯 ارز: {$offer['currency']}\n";
            $msg .= "📊 مقدار: " . number_format($offer['requested_amount']) . "\n";
            $msg .= "💰 قیمت: " . number_format($offer['offered_price']) . " تومان\n";
            $msg .= "📝 دلیل: {$reason}\n\n";
            $msg .= "می‌توانید پیشنهاد جدیدی ثبت کنید.";
            $enterBtn = ['inline_keyboard' => [[['text' => '🚀 ورود به AVA PAY', 'url' => AVAPAY_APP_URL]]]];
            oa_sendTelegram($offer['buyer_telegram'], $msg, $enterBtn);
        }

        if (function_exists('sendEmailNotification') && !empty($offer['buyer_email'])) {
            @sendEmailNotification($offer['buyer_email'], $rejectTitle, $rejectMessage);
        }

        // (جدید) اگر ریشه‌ی این آگهی یک آگهیِ mainbot باشد، پستِ کانال را با
        // متنِ تازه (شاملِ همین ردِ پیشنهاد) و برگرداندنِ دکمه‌ی «ارسال
        // پیشنهاد» (آگهی دوباره باز می‌شود) ادیت کن
        require_once __DIR__ . '/../includes/mozayede_bridge.php';
        moz_syncOfferResponded($conn, (int)$offer['ad_id'], false);

        return ['success' => true, 'message' => '✅ پیشنهاد رد شد', 'offer' => $offer];
    }
}
