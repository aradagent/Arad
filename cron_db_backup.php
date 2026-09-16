<?php
/**
 * cron_db_backup.php
 * ------------------------------------------------------------------
 * بک‌آپ خودکار روزانه‌ی دیتابیس.
 *
 * چرا PHP خالص (بدون mysqldump/shell_exec)؟
 * روی خیلی از هاست‌های اشتراکی exec()/shell_exec() غیرفعال است، پس این
 * اسکریپت با mysqli خودش ساختار (CREATE TABLE) و داده‌ی هر جدول را
 * می‌خواند و یک فایل .sql.gz معتبر (قابل import با phpMyAdmin/CLI) می‌سازد.
 *
 * سه راه اجرا:
 *   ۱) Cron واقعی هاست (بهترین حالت، ترجیحاً CLI):
 *        php /path/to/ledor/cron_db_backup.php
 *      یا اگر هاست فقط Cron مبتنی بر URL دارد:
 *        php -q .../cron_db_backup.php?key=...
 *   ۲) خودکار بدون نیاز به تنظیم Cron: پنل ادمین (admin_panel.php) در هر بار
 *      باز شدن چک می‌کند که آیا از آخرین بک‌آپ ۲۴ ساعت گذشته یا نه (از طریق
 *      avapay_throttled('daily_db_backup', 86400)) و اگر بله، همین تابع
 *      avapay_run_db_backup() را در پس‌زمینه (بعد از ارسال پاسخ به ادمین،
 *      اگر fastcgi_finish_request در دسترس باشد) صدا می‌زند. یعنی حتی بدون
 *      هیچ Cron Job روی هاست، تا وقتی ادمین حداقل یک‌بار در روز پنل را باز
 *      کند، بک‌آپ روزانه خودکار انجام می‌شود.
 *   ۳) دستی از بخش «داده ← بکاپ» در پنل ادمین (دکمه‌ی «بک‌آپ فوری»).
 *
 * خروجی همیشه در backups/ داخل ریشه‌ی اصلی هاست (public_html) ذخیره
 * می‌شود — نه کنار خودِ این فایل — طبق درخواست صریح، تا مستقل از پوشه‌ی
 * اپ (/ledor/) در دسترس باشد. مسیر دقیق در avapay_backup_dir() محاسبه
 * می‌شود. آن پوشه با
 * .htaccess از دسترسی مستقیم وب محافظت شده. فقط ۱۴ بک‌آپ آخر نگه داشته
 * می‌شود (rotation خودکار) تا فضای دیسک هاست پر نشود.
 * ------------------------------------------------------------------
 */

// این کلید فقط برای حالت Cron مبتنی بر URL لازم است (وقتی از مرورگر/HTTP
// مستقیم به این فایل درخواست زده می‌شود) — تا کسی دیگر نتواند با باز کردن
// این آدرس یک دامپ کامل دیتابیس تولید/دانلود کند. این کلید را عوض کنید و
// همان را در آدرس Cron Job هاست خود قرار دهید، مثال:
//   https://aradexchange.com/ledor/cron_db_backup.php?key=یک-رشته-تصادفی-طولانی-اینجا
define('AVAPAY_BACKUP_SECRET', 'CHANGE_ME_TO_A_LONG_RANDOM_STRING');

if (!function_exists('avapay_run_db_backup')) {
/**
 * دامپ کامل دیتابیس را می‌گیرد، gzip می‌کند، در backups/ ذخیره می‌کند و
 * rotation را انجام می‌دهد. قابل فراخوانی هم از این فایل (CLI/URL cron) و
 * هم مستقیماً از api/data_management_api.php (بک‌آپ دستی/ایمنی قبل از ریستور).
 *
 * @param mysqli $conn
 * @param string $backupDir مسیر کامل پوشه‌ی backups
 * @param string $prefix    پیشوند نام فایل (پیش‌فرض backup_، برای بک‌آپ ایمنیِ
 *                          قبل از ریستور از pre_restore_safety_ استفاده می‌شود
 *                          تا با rotation عادی ۱۴تایی قاطی/پاک نشود)
 * @return array ['success'=>bool, 'file_name'=>string, 'table_count'=>int, 'total_rows'=>int, 'size_kb'=>float, 'message'=>string]
 */
function avapay_run_db_backup($conn, $backupDir, $prefix = 'backup_') {
    // جلوگیری از قطع‌شدن نیمه‌کاره‌ی بک‌آپ روی هاست‌های اشتراکی با
    // max_execution_time کوتاه (باعث فایل ناقص/خراب می‌شد)
    @set_time_limit(0);
    @ini_set('memory_limit', '512M');

    if (!is_dir($backupDir)) {
        @mkdir($backupDir, 0750, true);
    }
    $htaccessPath = $backupDir . '/.htaccess';
    if (!file_exists($htaccessPath)) {
        @file_put_contents($htaccessPath, "Require all denied\ndeny from all\n");
    }
    $indexPath = $backupDir . '/index.php';
    if (!file_exists($indexPath)) {
        @file_put_contents($indexPath, "<?php http_response_code(403); exit('Forbidden');");
    }

    $timestamp = date('Y-m-d_H-i-s');
    $fileName  = "{$prefix}{$timestamp}.sql.gz";
    $filePath  = $backupDir . '/' . $fileName;

    $gz = @gzopen($filePath, 'wb9');
    if (!$gz) {
        avapay_backup_fail('امکان ساخت فایل بک‌آپ وجود نداشت (مشکل دسترسی نوشتن روی دیسک).');
        return ['success' => false, 'message' => 'امکان ساخت فایل بک‌آپ وجود نداشت.'];
    }

    gzwrite($gz, "-- AvaPay automatic backup\n-- Generated: " . date('Y-m-d H:i:s') . "\n");
    gzwrite($gz, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

    $tablesResult = $conn->query("SHOW TABLES");
    if (!$tablesResult) {
        avapay_backup_fail('خطا در خواندن لیست جدول‌ها: ' . $conn->error);
        gzclose($gz);
        @unlink($filePath);
        return ['success' => false, 'message' => 'خطا در خواندن لیست جدول‌ها: ' . $conn->error];
    }

    $tableCount = 0;
    $totalRows  = 0;
    $failedTables = [];

    while ($row = $tablesResult->fetch_row()) {
        $table = $row[0];

        $createRes = $conn->query("SHOW CREATE TABLE `{$table}`");
        if (!$createRes) {
            // اگر ساختار جدول قابل خواندن نبود، این جدول کاملاً رد می‌شود
            // (نه فقط CREATE TABLE، بلکه دیتای آن هم) — چون نوشتن INSERT
            // برای جدولی که در همین فایل DROP/CREATE نشده، روی ریستور یعنی
            // «جدول وجود ندارد» و شکست می‌خورد. به‌جای این خطای گنگ روی
            // ریستور، همین‌جا با نام دقیق جدول لاگ می‌شود تا ادمین بفهمد
            // کدام جدول اصلاً بک‌آپ نشده.
            $failedTables[] = $table;
            error_log("AvaPay backup: SHOW CREATE TABLE failed for `{$table}` — این جدول رد شد: " . $conn->error);
            continue;
        }
        $tableCount++;
        $createRow = $createRes->fetch_row();
        gzwrite($gz, "\n-- ----------------------------\n-- Table: {$table}\n-- ----------------------------\n");
        gzwrite($gz, "DROP TABLE IF EXISTS `{$table}`;\n");
        gzwrite($gz, $createRow[1] . ";\n\n");

        $countRes = $conn->query("SELECT COUNT(*) AS c FROM `{$table}`");
        $rowCount = $countRes ? (int)$countRes->fetch_assoc()['c'] : 0;
        if ($rowCount === 0) continue;

        // ستون‌های GENERATED (مثل ad_offers.total_amount) را باید از INSERT
        // کنار گذاشت — MySQL اجازه‌ی مقداردهی صریح به آن‌ها را نمی‌دهد (روی
        // ریستور همین باعث خطای «value ... has been ignored» و توقف کامل
        // ریستور می‌شد). این ستون‌ها خودشان از روی بقیه‌ی مقادیر محاسبه می‌شوند.
        $generatedCols = [];
        $colsInfo = $conn->query("SHOW COLUMNS FROM `{$table}`");
        if ($colsInfo) {
            while ($ci = $colsInfo->fetch_assoc()) {
                if (stripos($ci['Extra'] ?? '', 'GENERATED') !== false) {
                    $generatedCols[$ci['Field']] = true;
                }
            }
        }

        // مهم — علت اصلیِ حادثه‌ی «همه‌چیز پاک شد»: قبلاً هر ۵۰۰ ردیف در یک
        // دستور INSERT غول‌پیکر نوشته می‌شد. روی ریستور، اگر آن یک دستور از
        // max_allowed_packet سرور MySQL (معمولاً ۱ تا ۴ مگابایت روی هاست‌های
        // اشتراکی) بزرگ‌تر بود، MySQL کانکشن را قطع می‌کرد («server has gone
        // away») و multi_query کل باقیِ فایل (شامل CREATE TABLE سایر جدول‌ها)
        // را دیگر هرگز اجرا نمی‌کرد — یعنی جدول‌هایی که DROP شده بودند دیگر
        // هیچ‌وقت دوباره ساخته نمی‌شدند. حالا هر INSERT فقط ۲۰ ردیف دارد و
        // اضافه بر آن، هر INSERT جداگانه هم اگر خودش حجیم بود (مثلاً ستون‌های
        // متنی بزرگ) باز هم شکسته می‌شود تا از حد امن (~256KB) رد نشود.
        $batchSize = 20;
        $maxStatementBytes = 250000;
        for ($offset = 0; $offset < $rowCount; $offset += $batchSize) {
            $dataRes = $conn->query("SELECT * FROM `{$table}` LIMIT {$batchSize} OFFSET {$offset}");
            if (!$dataRes) break;

            $rows = [];
            $r = null;
            $cols = null;
            $curBytes = 0;
            while ($r = $dataRes->fetch_assoc()) {
                if ($generatedCols) $r = array_diff_key($r, $generatedCols);
                if ($cols === null) $cols = '`' . implode('`,`', array_keys($r)) . '`';
                $vals = array_map(function ($v) use ($conn) {
                    if ($v === null) return 'NULL';
                    return "'" . $conn->real_escape_string($v) . "'";
                }, array_values($r));
                $rowSql = '(' . implode(',', $vals) . ')';
                // اگر افزودن این ردیف از حد امن رد شود، دستهٔ فعلی را همین‌جا
                // ببند و بنویس، بعد دسته‌ی جدید شروع کن (still همان OFFSET/LIMIT
                // بیرونی، فقط اینجا batch را زودتر flush می‌کنیم)
                if ($rows && ($curBytes + strlen($rowSql)) > $maxStatementBytes) {
                    gzwrite($gz, "INSERT INTO `{$table}` ({$cols}) VALUES\n" . implode(",\n", $rows) . ";\n");
                    $totalRows += count($rows);
                    $rows = [];
                    $curBytes = 0;
                }
                $rows[] = $rowSql;
                $curBytes += strlen($rowSql);
            }
            if ($rows && $cols !== null) {
                gzwrite($gz, "INSERT INTO `{$table}` ({$cols}) VALUES\n" . implode(",\n", $rows) . ";\n");
                $totalRows += count($rows);
            }
        }
    }

    gzwrite($gz, "\nSET FOREIGN_KEY_CHECKS=1;\n");
    // نشانه‌ی پایانِ سالم — اگر فایل به هر دلیلی (تایم‌اوت، قطع دیسک، ...)
    // نیمه‌کاره بماند، این خط هرگز نوشته نمی‌شود و ریستور بعداً همین را چک
    // می‌کند تا هرگز یک بک‌آپ ناقص را روی دیتابیس زنده اجرا نکند.
    gzwrite($gz, "-- AVAPAY_BACKUP_COMPLETE_OK\n");
    gzclose($gz);

    $fileSizeKb = round(filesize($filePath) / 1024, 1);

    // Rotation: فقط ۱۴ بک‌آپ آخر از هر پیشوند نگه داشته می‌شود
    $keepCount = 14;
    $existing = glob($backupDir . '/' . $prefix . '*.sql.gz');
    if ($existing && count($existing) > $keepCount) {
        usort($existing, function ($a, $b) { return filemtime($a) <=> filemtime($b); });
        $toDelete = array_slice($existing, 0, count($existing) - $keepCount);
        foreach ($toDelete as $old) { @unlink($old); }
    }

    $failedNote = $failedTables ? (' — هشدار: ' . count($failedTables) . ' جدول رد شد (' . implode('، ', $failedTables) . ')') : '';
    error_log("AvaPay backup OK: {$fileName} ({$tableCount} جدول، {$totalRows} ردیف، {$fileSizeKb} کیلوبایت){$failedNote}");

    if ($prefix === 'backup_' && function_exists('sendTelegramMessage') && defined('ADMIN_TELEGRAM_ID')) {
        @sendTelegramMessage(ADMIN_TELEGRAM_ID,
            "✅ بک‌آپ خودکار دیتابیس با موفقیت انجام شد.\n📦 {$fileName}\n📊 {$tableCount} جدول / {$totalRows} ردیف / {$fileSizeKb} KB" .
            ($failedTables ? "\n⚠️ " . count($failedTables) . " جدول رد شد: " . implode('، ', $failedTables) : ''));
    }

    return [
        'success'       => true,
        'file_name'     => $fileName,
        'table_count'   => $tableCount,
        'total_rows'    => $totalRows,
        'size_kb'       => $fileSizeKb,
        'failed_tables' => $failedTables,
        'message'       => "Backup completed: {$fileName} ({$tableCount} tables, {$totalRows} rows, {$fileSizeKb} KB)" . ($failedTables ? ' — ' . count($failedTables) . ' table(s) skipped: ' . implode(', ', $failedTables) : ''),
    ];
}
}

if (!function_exists('avapay_backup_fail')) {
function avapay_backup_fail($reason) {
    error_log('AvaPay backup FAILED: ' . $reason);
    if (function_exists('sendTelegramMessage') && defined('ADMIN_TELEGRAM_ID')) {
        @sendTelegramMessage(ADMIN_TELEGRAM_ID, "❌ بک‌آپ خودکار دیتابیس امروز با خطا مواجه شد:\n" . $reason);
    }
}
}

if (!function_exists('avapay_backup_dir')) {
/**
 * مسیر ثابتِ پوشه‌ی بک‌آپ‌ها — همیشه در ریشه‌ی اصلی هاست (public_html)،
 * نه داخل پوشه‌ی اپ (/ledor/). طبق درخواست صریح، بک‌آپ‌ها باید همیشه اینجا
 * ذخیره شوند، نه در backups/ کنار خودِ اپ.
 * اولویت با DOCUMENT_ROOT (قابل‌اعتمادترین راه، وقتی درخواست HTTP باشد)؛
 * اگر در دسترس نبود (مثلاً CLI/cron)، یک پوشه بالاتر از پوشه‌ی این فایل
 * در نظر گرفته می‌شود — چون این فایل همیشه در ریشه‌ی پوشه‌ی اپ (/ledor/)
 * است و طبق ساختار هاست، یک پوشه بالاتر از آن دقیقاً public_html است.
 */
function avapay_backup_dir() {
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');
        if (is_dir($docRoot) && is_writable($docRoot)) {
            return $docRoot . '/backups';
        }
    }
    return dirname(__DIR__) . '/backups';
}
}

/* ------------------------------------------------------------------
 * نقطه‌ی ورود مستقیم: این بخش فقط وقتی اجرا می‌شود که خودِ این فایل
 * مستقیماً درخواست/اجرا شده باشد (نه وقتی require/include شده — مثلاً از
 * api/data_management_api.php یا از خودکارسازی داخل admin_panel.php).
 * ------------------------------------------------------------------ */
$__isDirectRun = (php_sapi_name() === 'cli')
    ? (isset($argv[0]) && @realpath($argv[0]) === realpath(__FILE__))
    : (isset($_SERVER['SCRIPT_FILENAME']) && @realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__));

if ($__isDirectRun) {
    if (php_sapi_name() !== 'cli') {
        $providedKey = $_GET['key'] ?? '';
        if (AVAPAY_BACKUP_SECRET === 'CHANGE_ME_TO_A_LONG_RANDOM_STRING' || !hash_equals(AVAPAY_BACKUP_SECRET, $providedKey)) {
            http_response_code(403);
            die('دسترسی غیرمجاز. یا از CLI اجرا کنید یا کلید صحیح را در ?key= بدهید.');
        }
        header('Content-Type: text/plain; charset=UTF-8');
    }

    require_once __DIR__ . '/config/database.php';
    $backupDir = avapay_backup_dir();
    $result = avapay_run_db_backup($conn, $backupDir);
    echo ($result['message'] ?? ($result['success'] ? 'OK' : 'FAILED')) . "\n";
}
