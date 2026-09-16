<?php
/**
 * includes/invoice_sync.php
 * ------------------------------------------------------------------
 * هر جا ادمین برای کاربر «شماره‌حساب برای پرداخت» می‌فرستد، همان درخواست
 * باید در کادر «صورت‌حساب‌ها»ی داشبورد کاربر هم دیده شود — چه از مسیر
 * تبادل ارزی باشد، چه Top-up، چه حواله، چه هر مسیر آینده.
 *
 * این فایل یک تابع مشترک فراهم می‌کند تا این منطق در چند جا تکرار (و
 * ناهماهنگ) نشود.
 *
 * هر صورت‌حساب با (source, source_id) به رکورد مبدأ گره می‌خورد تا:
 *   - با ارسال دوباره‌ی شماره‌حساب، صورت‌حساب تکراری ساخته نشود
 *   - بعد از تکمیل/پرداخت بتوان همان را بست و به آرشیو برد
 * ------------------------------------------------------------------
 */

if (!function_exists('avapay_invoice_schema')) {
    function avapay_invoice_schema($conn) {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $cols = [];
            $r = $conn->query("SHOW COLUMNS FROM `unpaid_invoices`");
            if ($r) { while ($row = $r->fetch_assoc()) { $cols[strtolower($row['Field'])] = true; } }

            // نکته: «ADD COLUMN IF NOT EXISTS» فقط در MariaDB کار می‌کند و از
            // PHP 8.1 خطاهای mysqli Exception هستند (و @ جلویشان را نمی‌گیرد)،
            // پس وجود ستون‌ها اول بررسی می‌شود.
            if (!isset($cols['source']))    $conn->query("ALTER TABLE `unpaid_invoices` ADD COLUMN `source` VARCHAR(20) DEFAULT NULL");
            if (!isset($cols['source_id'])) $conn->query("ALTER TABLE `unpaid_invoices` ADD COLUMN `source_id` INT DEFAULT NULL");

            $hasIdx = false;
            $ri = $conn->query("SHOW INDEX FROM `unpaid_invoices`");
            if ($ri) { while ($row = $ri->fetch_assoc()) { if (strtolower($row['Key_name']) === 'idx_source') { $hasIdx = true; break; } } }
            if (!$hasIdx) $conn->query("ALTER TABLE `unpaid_invoices` ADD INDEX `idx_source` (`source`, `source_id`)");
        } catch (\Throwable $e) {
            error_log('avapay_invoice_schema: ' . $e->getMessage());
        }
    }
}

if (!function_exists('avapay_invoice_currency')) {
    /** ارز را به مقادیر مجاز ENUM جدول صورت‌حساب‌ها محدود می‌کند */
    function avapay_invoice_currency($cur) {
        $cur = strtoupper(trim((string)$cur));
        if ($cur === 'تومان' || $cur === 'ریال' || $cur === 'TOMAN' || $cur === 'RIAL' || $cur === '') return 'IRR';
        return in_array($cur, ['USD', 'EUR', 'USDT', 'IRR'], true) ? $cur : 'IRR';
    }
}

if (!function_exists('avapay_sync_payment_invoice')) {
    /**
     * ایجاد/به‌روزرسانی صورت‌حسابِ «آماده پرداخت» برای یک درخواست پرداخت.
     *
     * @param mysqli $conn
     * @param string $source     مبدأ: 'topup' | 'transfer' | 'deal' | ...
     * @param int    $sourceId   شناسه‌ی رکورد مبدأ
     * @param int    $userId     کاربری که باید پرداخت کند
     * @param float  $amount     مبلغ قابل پرداخت
     * @param string $currency   ارز مبلغ
     * @param string $desc       توضیح صورت‌حساب
     * @param array  $pay        ['bank_name','account_number','card_number','recipient_name','iban']
     * @return bool
     */
    function avapay_sync_payment_invoice($conn, $source, $sourceId, $userId, $amount, $currency, $desc, array $pay = []) {
        try {
            avapay_invoice_schema($conn);

            $userId   = (int)$userId;
            $sourceId = (int)$sourceId;
            $amount   = (float)$amount;
            if ($userId <= 0 || $amount <= 0) return false;

            $cur  = avapay_invoice_currency($currency);
            $bank = (string)($pay['bank_name']      ?? '');
            $acct = (string)($pay['account_number'] ?? '');
            $card = (string)($pay['card_number']    ?? '');
            $name = (string)($pay['recipient_name'] ?? '');
            $iban = (string)($pay['iban']           ?? '');

            // آیا قبلاً برای همین مبدأ صورت‌حسابی ساخته شده؟
            $st = $conn->prepare("SELECT id, status FROM unpaid_invoices WHERE source = ? AND source_id = ? LIMIT 1");
            if (!$st) return false;
            $st->bind_param('si', $source, $sourceId);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();

            if ($row) {
                // اگر کاربر قبلاً پرداخت کرده یا نهایی شده، وضعیت را عقب نمی‌بریم
                if (in_array($row['status'], ['paid', 'finalized'], true)) return true;
                $up = $conn->prepare("UPDATE unpaid_invoices
                    SET amount=?, currency=?, description=?, bank_name=?, account_number=?,
                        card_number=?, recipient_name=?, iban=?, status='approved', updated_at=NOW()
                    WHERE id=?");
                if (!$up) return false;
                $up->bind_param('dsssssssi', $amount, $cur, $desc, $bank, $acct, $card, $name, $iban, $row['id']);
                $up->execute();
                $up->close();
                return true;
            }

            // وضعیت approved یعنی «اطلاعات پرداخت ارسال شده، آماده‌ی پرداخت» —
            // چون خودِ ادمین شماره‌حساب را فرستاده، نیازی به تأیید مجدد نیست.
            $ins = $conn->prepare("INSERT INTO unpaid_invoices
                (user_id, currency, amount, description, status, bank_name, account_number,
                 card_number, recipient_name, iban, source, source_id)
                VALUES (?, ?, ?, ?, 'approved', ?, ?, ?, ?, ?, ?, ?)");
            if (!$ins) return false;
            $ins->bind_param('isdsssssssi', $userId, $cur, $amount, $desc, $bank, $acct, $card, $name, $iban, $source, $sourceId);
            $ins->execute();
            $ins->close();
            return true;
        } catch (\Throwable $e) {
            // ثبت صورت‌حساب هرگز نباید جریان اصلیِ ادمین را متوقف کند
            error_log('avapay_sync_payment_invoice: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('avapay_close_payment_invoice')) {
    /** بستن صورت‌حساب یک مبدأ (از «در انتظار پرداخت» به آرشیو) */
    function avapay_close_payment_invoice($conn, $source, $sourceId) {
        try {
            avapay_invoice_schema($conn);
            $sourceId = (int)$sourceId;
            if ($sourceId <= 0) return;
            $st = $conn->prepare("UPDATE unpaid_invoices SET status='finalized', updated_at=NOW()
                                  WHERE source = ? AND source_id = ? AND status NOT IN ('finalized','rejected')");
            if (!$st) return;
            $st->bind_param('si', $source, $sourceId);
            $st->execute();
            $st->close();
        } catch (\Throwable $e) {
            error_log('avapay_close_payment_invoice: ' . $e->getMessage());
        }
    }
}
