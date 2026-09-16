<?php
/**
 * includes/rate_poster_helper.php
 * ---------------------------------------------------------------------------
 * «عکس نرخ لحظه‌ای» — توابع مشترک بین کران، API ادمین و صفحه‌ی رندر قالب.
 *
 * ایده‌ی کلی:
 *   • کران هر روز ساعت ۰۶:۰۰ و ۱۵:۰۰ اجرا می‌شود، نرخ‌های همان لحظه را
 *     می‌گیرد و به‌صورت یک «اسنپ‌شات» (JSON) در جدول rate_posters ذخیره می‌کند.
 *   • تصویر از روی همان اسنپ‌شاتِ فریزشده ساخته می‌شود، پس عددهای روی عکس
 *     دقیقاً نرخ ساعت ۶ صبح / ۳ بعدازظهر هستند — حتی اگر ادمین ساعت ۸ شب
 *     دانلود کند.
 *   • ادمین هر وقت بخواهد می‌تواند با دکمه‌ی «تولید همین الان» یک اسنپ‌شات
 *     دستی با نرخ لحظه‌ی جاری بسازد.
 * ---------------------------------------------------------------------------
 */

if (!function_exists('rp_ensure_table')) {

/** ساخت جدول اسنپ‌شات‌ها (یک‌بار، بی‌خطر برای اجرای مکرر) */
function rp_ensure_table($conn) {
    if (!$conn) return;
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS `rate_posters` (
            `id`         INT PRIMARY KEY AUTO_INCREMENT,
            `slot`       VARCHAR(16) NOT NULL DEFAULT 'manual',
            `slot_date`  DATE NOT NULL,
            `title_time` VARCHAR(10) NOT NULL DEFAULT '',
            `title_date` VARCHAR(40) NOT NULL DEFAULT '',
            `payload`    MEDIUMTEXT NOT NULL,
            `created_by` INT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `slot_once` (`slot`,`slot_date`),
            KEY `created` (`created_at`)
        ) DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { error_log('rate_posters table: ' . $e->getMessage()); }
}

/** تبدیل میلادی به شمسی — خروجی [سال, ماه, روز] */
function rp_jalali($ts) {
    $gy = (int)date('Y', $ts); $gm = (int)date('n', $ts); $gd = (int)date('j', $ts);
    $g_d_m = [0,31,59,90,120,151,181,212,243,273,304,334];
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + (int)(($gy2 + 3) / 4) - (int)(($gy2 + 99) / 100)
          + (int)(($gy2 + 399) / 400) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * (int)($days / 12053)); $days %= 12053;
    $jy += 4 * (int)($days / 1461); $days %= 1461;
    if ($days > 365) { $jy += (int)(($days - 1) / 365); $days = ($days - 1) % 365; }
    if ($days < 186) { $jm = 1 + (int)($days / 31); $jd = 1 + ($days % 31); }
    else             { $jm = 7 + (int)(($days - 186) / 30); $jd = 1 + (($days - 186) % 30); }
    return [$jy, $jm, $jd];
}

/** برچسب تاریخ شمسی مثل «۷ شهریور ۱۴۰۵» */
function rp_jalali_label($ts) {
    $months = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور',
               'مهر','آبان','آذر','دی','بهمن','اسفند'];
    [$jy, $jm, $jd] = rp_jalali($ts);
    return rp_fa_num($jd) . ' ' . ($months[$jm - 1] ?? '') . ' ' . rp_fa_num($jy);
}

/** ارقام انگلیسی → فارسی */
function rp_fa_num($s) {
    return str_replace(['0','1','2','3','4','5','6','7','8','9'],
                       ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'], (string)$s);
}

/**
 * اندازه‌ی فونتِ متن را طوری کوچک می‌کند که داخل عرضِ مشخص‌شده جا شود —
 * با اندازه‌گیریِ واقعیِ عرضِ متن روی همان فایلِ فونتِ باندل‌شده (Vazirmatn)،
 * نه حدسیِ بر اساس تعداد کاراکتر. این دقیقاً همان باگی را رفع می‌کند که
 * چند تا از اعداد (خصوصاً وقتی رقم‌ها زیاد می‌شدند، مثل «USD» کنار قیمت
 * بیت‌کوین/اتریوم) از لبه‌ی کارت بیرون می‌زدند یا نصفه‌کاره قطع می‌شدند:
 * قبلاً فونت فقط بر اساس «طول رشته» حدس زده می‌شد، نه عرضِ واقعیِ رندرشده.
 */
function rp_fit_font_size($text, $maxWidthPx, $fontFile, $maxFontPx = 32, $minFontPx = 13) {
    if (!$text || !is_file($fontFile) || !function_exists('imagettfbbox')) return $maxFontPx;
    $size = $maxFontPx;
    while ($size > $minFontPx) {
        $box = @imagettfbbox($size, 0, $fontFile, $text);
        if ($box) {
            $w = abs($box[2] - $box[0]);
            if ($w <= $maxWidthPx) break;
        }
        $size--;
    }
    return $size;
}

/**
 * ردیف‌های قالب — همان ۱۴ ارزی که در طرح مرجع هست، به همان ترتیب دقیق
 * (چون آیکون/پرچم/نام همه از روی خودِ تصویر پس‌زمینه می‌آیند، اینجا فقط
 * کد نمایشی + کد منبع نرخ لازم است).
 */
function rp_poster_rows() {
    return [
        ['EUR', 'EUR'],
        ['USD', 'USD'],
        ['TRY', 'TRY'],
        ['AED', 'AED'],
        ['CAD', 'CAD'],
        ['GBP', 'GBP'],
        ['AUD', 'AUD'],
        ['CHF', 'CHF'],
        ['CNY', 'CNY'],
        ['RUB', 'RUB'],
        ['IQD', 'IQD'],
        ['XAU', 'GOLD_18'],
        ['BTC', 'BTC'],
        ['ETH', 'ETH'],
    ];
}

/**
 * مختصات دقیق هر کارت روی تصویر پس‌زمینه‌ی ثابت (assets/images/rate_poster_bg.png,
 * سایز واقعی ۱۰۲۴×۱۵۳۶). این نسخه از عکسِ «خام» (بدون غلط املایی، بدون هیچ
 * عدد/نمودار/درصدی از قبل چاپ‌شده) استفاده می‌کند — یعنی هیچ‌چیزی برای
 * پوشاندن نیست؛ فقط باید عدد/نمودار/درصد در جای درستش نشسته شود.
 * تنها چیزی که از قبل روی عکس کشیده شده، یک باکسِ خالیِ گردگوشه برای
 * درصدِ تغییر است (همان‌جایی که rp_badge_box() برمی‌گرداند).
 *
 * این عددها با اندازه‌گیری پیکسلی مستقیم روی خودِ عکسِ جدید به‌دست آمده‌اند.
 *
 * خروجی: [x کارت, y کارت, عرض کارت]
 */
function rp_card_geo($index) {
    $rowsY = [439, 614, 781, 948, 1117];
    $cols  = [
        ['x' => 78,  'w' => 211],
        ['x' => 300, 'w' => 178],
        ['x' => 490, 'w' => 160],
    ];
    $row = intdiv($index, 3);
    $col = $index % 3;
    $c   = $cols[$col];
    return [$c['x'], $rowsY[$row] ?? 439, $c['w']];
}

/**
 * مختصات دقیقِ باکسِ خالیِ از‌قبل‌کشیده‌شده برای درصدِ تغییر — همان جعبه‌ی
 * گردگوشه‌ای که در خودِ عکس پیداست (نیازی به پچِ رنگیِ ما ندارد، فقط باید
 * رویش رنگ/متن مناسب بنشیند). اندازه‌اش کمی با عرض هر ستون فرق دارد.
 *
 * خروجی: [x باکس, y باکس, عرض باکس, ارتفاع باکس]
 */
function rp_badge_box($cx, $cy, $cw, $col) {
    $widths  = [90, 84, 80];
    $w = $widths[$col] ?? 84;
    $x = $cx + $cw - 9 - $w;   // ۹ پیکسل فاصله از لبه‌ی راستِ کارت
    $y = $cy + 102;
    $h = 42;
    return [$x, $y, $w, $h];
}

/**
 * یک نمودار کوچکِ خطی (اسپارک‌لاین) تزئینی می‌سازد. چون فقط قیمتِ لحظه‌ای
 * و قیمتِ اسنپ‌شاتِ قبلی ذخیره می‌شود (نه یک سری‌ی زمانیِ واقعی)، این خط
 * یک واک تصادفیِ کوچک با گرایش به سمتِ روند (صعودی/نزولی/ثابت) است —
 * دقیقاً همان‌طور که در طرحِ اصلی هم این خط‌ها تزئینی بودند، نه دادهٔ خام.
 * با seedِ ثابت (کد ارز + تاریخِ اسنپ‌شات) ساخته می‌شود تا در یک روزِ
 * مشخص، شکلِ نمودار هر ارز عوض نشود؛ فقط با تاریخ/اسنپ‌شاتِ جدید تغییر می‌کند.
 */
function rp_spark_svg($trend, $w, $h, $seedStr) {
    mt_srand(crc32($seedStr));
    $n = 9;
    $y = $h * 0.62;
    $pts = [];
    $drift = ($trend === 'up') ? -1.7 : (($trend === 'down') ? 1.7 : 0);
    for ($k = 0; $k < $n; $k++) {
        $y += $drift + (mt_rand(-100, 100) / 100) * ($h * 0.2);
        $y  = max(3, min($h - 3, $y));
        $pts[] = round($k / ($n - 1) * $w, 1) . ',' . round($y, 1);
    }
    mt_srand(); // بازگرداندن seedِ تصادفی به حالت عادی برای بقیه‌ی اجرای درخواست
    $color = ($trend === 'up') ? '#22d37a' : (($trend === 'down') ? '#ff4d6d' : '#9b8fc7');
    return '<svg width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" '
         . 'xmlns="http://www.w3.org/2000/svg" style="position:absolute;left:0;top:0;">'
         . '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="' . $color . '" '
         . 'stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" opacity=".95"/></svg>';
}

/**
 * واکشی «گرم نقره» از HTMLِ *از قبل گرفته‌شده‌ی* صفحه‌ی طلا.
 * قبلاً این تابع خودش دوباره صفحه را دانلود می‌کرد؛ یعنی هر بار تولید عکس
 * چهار درخواست شبکه می‌زد به‌جای سه‌تا. همین یکی از دلایل سنگین‌شدن سرور بود.
 */
function rp_parse_silver($goldHtml) {
    if (!$goldHtml || !function_exists('mr_text')) return 0.0;
    $text = mr_num(mr_text($goldHtml));
    foreach (['گرم نقره 999', 'گرم نقره', 'نقره'] as $fa) {
        $q = preg_quote($fa, '/');
        if (preg_match('/' . $q . '\s+([0-9][0-9,]{3,})\s+([0-9][0-9,]{3,})/us', $text, $m)) {
            return (float)str_replace(',', '', $m[2]);
        }
        if (preg_match('/' . $q . '\s+([0-9][0-9,]{3,})\s*تومان/us', $text, $m)) {
            return (float)str_replace(',', '', $m[1]);
        }
    }
    return 0.0;
}

/**
 * قفل ساده‌ی فایلی: جلوی اجرای هم‌زمانِ چند «تولید عکس» را می‌گیرد.
 * بدون این، هر بار که ادمین دکمه را چند بار می‌زد، چند اسکنِ سنگین هم‌زمان
 * روی سرور اجرا می‌شد و سرور برای چند دقیقه از دسترس خارج می‌شد.
 * خروجی: منبع قفل در صورت موفقیت، یا false اگر کار دیگری در حال اجراست.
 */
function rp_lock_acquire() {
    $f = @fopen(sys_get_temp_dir() . '/avapay_rate_poster.lock', 'c');
    if (!$f) return null;               // اگر قفل ممکن نبود، مانع کار نشو
    if (!@flock($f, LOCK_EX | LOCK_NB)) { @fclose($f); return false; }
    return $f;
}
function rp_lock_release($f) {
    if ($f) { @flock($f, LOCK_UN); @fclose($f); }
}

/**
 * ساخت اسنپ‌شات: نرخ لحظه‌ای را می‌گیرد و آرایه‌ی آماده‌ی رندر برمی‌گرداند.
 * $fresh=true یعنی اول market_rates را مجبور به همگام‌سازی کن.
 */
function rp_build_snapshot($conn, $fresh = true) {
    // ۱) تازه‌سازی جدول market_rates از الان‌چند
    if ($fresh) {
        require_once __DIR__ . '/../api/market_rates_lib.php';
        try {
            $rows = mr_scrape();
            if (!empty($rows)) {
                $st = $conn->prepare(
                    "INSERT INTO market_rates (code, name_fa, category, buy, sell, change_pct, unit, sort_order, updated_at)
                     VALUES (?,?,?,?,?,?,?,?,NOW())
                     ON DUPLICATE KEY UPDATE buy=VALUES(buy), sell=VALUES(sell),
                         change_pct=VALUES(change_pct), updated_at=NOW()"
                );
                if ($st) {
                    foreach ($rows as $r) {
                        $st->bind_param("sssdddsi", $r['code'], $r['name'], $r['cat'],
                            $r['buy'], $r['sell'], $r['chg'], $r['unit'], $r['ord']);
                        $st->execute();
                    }
                    $st->close();
                }
            }
            // نکته: قیمت دلاری دیگر لازم نیست — طبق درخواست، بیت‌کوین و
            // اتریوم بر حسب تتر نمایش داده می‌شوند (محاسبه‌شده پایین‌تر
            // از همین قیمت‌های تومانیِ خودِ market_rates، بدون درخواست جدید).
        } catch (Throwable $e) { error_log('rp snapshot sync: ' . $e->getMessage()); }
    }

    // ۲) خواندن مقادیر از جدول
    $vals = [];
    try {
        $q = $conn->query("SELECT code, sell, unit FROM market_rates");
        if ($q) while ($r = $q->fetch_assoc()) {
            $vals[$r['code']] = ['sell' => (float)$r['sell'], 'unit' => $r['unit']];
        }
    } catch (Throwable $e) {}

    // ۳) قیمت‌های اسنپ‌شات قبلی، برای تعیین صعودی/نزولی بودن هر ارز
    $prev = [];
    try {
        $q = $conn->query("SELECT payload FROM rate_posters ORDER BY created_at DESC LIMIT 1");
        if ($q && ($r = $q->fetch_assoc())) {
            $old = json_decode($r['payload'], true);
            if (!empty($old['items'])) {
                foreach ($old['items'] as $oi) {
                    if (isset($oi['code'])) $prev[$oi['code']] = (float)($oi['price'] ?? 0);
                }
            }
        }
    } catch (Throwable $e) {}

    $usd = isset($GLOBALS['__rp_usd']) && is_array($GLOBALS['__rp_usd']) ? $GLOBALS['__rp_usd'] : [];

    // نرخ تتر به تومان — برای تبدیل قیمت بیت‌کوین/اتریوم به تتر همین‌جا
    // از دو قیمت تومانیِ موجود ساخته می‌شود (بدون نیاز به درخواست شبکه‌ی
    // اضافه): قیمت‌تومانیِ ارز ÷ قیمت‌تومانیِ یک تتر = قیمت به تتر.
    $usdtToman = isset($vals['USDT']) ? (float)$vals['USDT']['sell'] : 0.0;

    // ۴) چیدن ردیف‌های قالب
    $items = [];
    foreach (rp_poster_rows() as $row) {
        [$disp, $srcCode] = $row;
        $price = isset($vals[$srcCode]) ? $vals[$srcCode]['sell'] : 0.0;
        $unit  = null;

        // طبق طرح جدید (عکس مرجع): بیت‌کوین و اتریوم بر حسب تتر محاسبه می‌شوند
        // ولی روی کارت با برچسب «USD» چاپ می‌شوند (دقیقاً مطابق طرح جدید)،
        // نه تومان — قیمت تومانی برای این دو اصلاً چاپ نمی‌شود.
        if (($disp === 'BTC' || $disp === 'ETH') && $price > 0 && $usdtToman > 0) {
            $price = $price / $usdtToman;
            $unit  = 'USD';
        }

        // درصد تغییر نسبت به اسنپ‌شات قبلی (روی همان واحدی که چاپ می‌شود)
        $chg = 0.0; $trend = 'flat';
        if ($price > 0 && !empty($prev[$disp]) && $prev[$disp] > 0) {
            $chg = (($price - $prev[$disp]) / $prev[$disp]) * 100;
            if ($chg > 0.02)       $trend = 'up';
            elseif ($chg < -0.02)  $trend = 'down';
        }

        $items[] = [
            'code'  => $disp,
            'price' => $price,
            'unit'  => $unit,   // 'USDT' برای BTC/ETH، در غیر این‌صورت خالی
            'chg'   => round($chg, 2),
            'trend' => $trend,
            // اعشار فقط وقتی نمایش داده می‌شود که واقعاً بخش اعشاری داشته باشد
            'dec'   => ($price > 0 && abs($price - round($price)) > 0.001) ? 1 : 0,
        ];
    }

    return [
        'items'      => $items,
        'ts'         => time(),
        'title_time' => date('H:i'),
        'title_date' => rp_jalali_label(time()),
    ];
}

/**
 * ذخیره‌ی اسنپ‌شات در دیتابیس.
 * $slot: 'morning' | 'afternoon' | 'manual'
 * برای اسلات‌های خودکار، هر روز فقط یک ردیف (کلید یکتا slot+slot_date).
 */
function rp_store_snapshot($conn, $snapshot, $slot = 'manual', $userId = null) {
    rp_ensure_table($conn);
    $payload = json_encode($snapshot, JSON_UNESCAPED_UNICODE);
    $date    = date('Y-m-d', $snapshot['ts']);
    $tTime   = $snapshot['title_time'];
    $tDate   = $snapshot['title_date'];

    if ($slot === 'manual') {
        // دستی: همیشه ردیف جدید (slot_date را یکتا نگه می‌داریم با افزودن ثانیه)
        $st = $conn->prepare("INSERT INTO rate_posters (slot, slot_date, title_time, title_date, payload, created_by)
                              VALUES ('manual', ?, ?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE title_time=VALUES(title_time),
                                  title_date=VALUES(title_date), payload=VALUES(payload),
                                  created_by=VALUES(created_by), created_at=NOW()");
    } else {
        $st = $conn->prepare("INSERT INTO rate_posters (slot, slot_date, title_time, title_date, payload, created_by)
                              VALUES (?, ?, ?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE title_time=VALUES(title_time),
                                  title_date=VALUES(title_date), payload=VALUES(payload), created_at=NOW()");
    }
    if (!$st) return 0;

    if ($slot === 'manual') {
        $st->bind_param("ssssi", $date, $tTime, $tDate, $payload, $userId);
    } else {
        $st->bind_param("sssssi", $slot, $date, $tTime, $tDate, $payload, $userId);
    }
    $ok = $st->execute();
    $id = $ok ? ($conn->insert_id ?: 0) : 0;
    $st->close();

    if (!$id) {
        // ON DUPLICATE UPDATE → insert_id صفر است؛ آی‌دی ردیف را پیدا کن
        $s2 = $conn->prepare("SELECT id FROM rate_posters WHERE slot=? AND slot_date=? LIMIT 1");
        if ($s2) {
            $s2->bind_param("ss", $slot, $date);
            $s2->execute();
            $r = $s2->get_result()->fetch_assoc();
            $id = $r ? (int)$r['id'] : 0;
            $s2->close();
        }
    }
    return $id;
}

} // end function_exists guard
