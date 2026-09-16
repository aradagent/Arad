<?php
/**
 * rate_poster.php
 * ---------------------------------------------------------------------------
 * رندر قالب «قیمت لحظه‌ای ارزها» از روی یک اسنپ‌شات ذخیره‌شده.
 *
 *   rate_poster.php?id=12          → نمایش قالب اسنپ‌شات شماره ۱۲
 *   rate_poster.php?id=12&bare=1   → بدون پس‌زمینه‌ی صفحه (برای گرفتن عکس)
 *
 * فقط ادمین اجازه‌ی دیدن دارد. عددها از اسنپ‌شات فریزشده می‌آیند، نه از
 * نرخ لحظه‌ی جاری — پس عکسِ ساعت ۶ صبح همیشه عددهای ۶ صبح را نشان می‌دهد.
 *
 * ---------------------------------------------------------------------------
 * روش کار (نسخه‌ی «عکس خام»): از شهریور ۱۴۰۵ به بعد، پس‌زمینه دیگر عکسِ
 * نمونه‌ی از قبل پرشده نیست — کاربر خودِ عکسِ خام (بدون غلط املایی، بدون
 * هیچ عدد/درصد/نموداری) را فرستاد. پس دیگر چیزی برای «پوشاندن» نیست: فقط
 * روی مختصات دقیقِ اندازه‌گیری‌شده‌ی هر کارت، سه چیز مستقیماً چاپ می‌شود —
 * قیمت (متنِ ساده، بدون پچِ پشت‌زمینه)، یک نمودارِ کوچکِ تزئینی (چون فقط
 * قیمتِ لحظه‌ای + قیمتِ اسنپ‌شاتِ قبلی ذخیره می‌شود، نه سری‌ی زمانیِ واقعی)،
 * و درصدِ تغییر (داخل همان باکسِ خالیِ گردگوشه‌ای که از قبل روی عکس هست).
 * مختصات‌ها با اندازه‌گیری پیکسلی مستقیم روی خودِ عکس به‌دست آمده‌اند
 * (rp_card_geo() و rp_badge_box() در rate_poster_helper.php).
 * ---------------------------------------------------------------------------
 */

require_once __DIR__ . '/includes/session_boot.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/rate_poster_helper.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) { http_response_code(403); exit('forbidden'); }
$st = $conn->prepare("SELECT is_admin FROM users WHERE id = ?");
$st->bind_param("i", $userId); $st->execute();
$u = $st->get_result()->fetch_assoc(); $st->close();
if (!$u || (int)$u['is_admin'] !== 1) { http_response_code(403); exit('forbidden'); }

rp_ensure_table($conn);

$id = (int)($_GET['id'] ?? 0);
$snap = null;
if ($id > 0) {
    $st = $conn->prepare("SELECT payload FROM rate_posters WHERE id = ?");
    $st->bind_param("i", $id); $st->execute();
    $r = $st->get_result()->fetch_assoc(); $st->close();
    if ($r) $snap = json_decode($r['payload'], true);
}
if (!$snap) { $snap = rp_build_snapshot($conn, false); }

$items = $snap['items'] ?? [];

/** قیمت را با ارقام فارسی و جداکننده‌ی هزارگان قالب‌بندی می‌کند */
function rp_price($it) {
    $p = (float)($it['price'] ?? 0);
    if ($p <= 0) return '—';
    $dec = (int)($it['dec'] ?? 0);
    $s = number_format($p, $dec);
    // بیت‌کوین/اتریوم بر حسب تتر با ارقام لاتین‌اند (مثل بقیه‌ی ارز دیجیتال)،
    // بقیه‌ی ردیف‌ها با ارقام فارسی
    $latin = in_array($it['code'] ?? '', ['BTC', 'ETH'], true);
    $s = $latin ? $s : rp_fa_num($s);
    if (!empty($it['unit'])) $s .= ' ' . $it['unit'];
    return $s;
}

$bare = isset($_GET['bare']);
// ابعاد واقعیِ خودِ عکس مرجع — هیچ تغییری داده نشود، والا مختصات کارت‌ها
// (rp_card_geo) با تصویر هم‌خوانی خود را از دست می‌دهند.
$CANVAS_W = 1024;
$CANVAS_H = 1536;
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>قیمت لحظه‌ای ارزها — AVA PAY</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css">
<style>
    /* فونت را خودِ سرور میزبانی می‌کند (به‌جای فقط تکیه به CDN خارجی) —
       هم برای اینکه رندر همیشه دقیقاً با همون فونتی باشه که اندازه‌ی
       فونتِ هر عدد با آن اندازه‌گیری شده (rp_fit_font_size در
       rate_poster_helper.php)، هم برای اینکه اگر CDN کند/در دسترس نبود،
       باز هم فونت درست لود شود و اندازه‌ها به‌هم نریزد.
       CDN بالا هم به‌عنوان یک منبعِ اضافه (fallback سریع‌تر) نگه داشته شده. */
    @font-face{
        font-family:'VazirmatnLocal'; font-weight:800; font-style:normal;
        src:url('assets/fonts/Vazirmatn-Bold.ttf') format('truetype');
        font-display:block;
    }
</style>
<style>
    *{ box-sizing:border-box; margin:0; padding:0; }
    html,body{ width:100%; }
    body{
        font-family:'VazirmatnLocal','Vazirmatn',Tahoma,sans-serif; direction:rtl;
        background:<?php echo $bare ? 'transparent' : '#000'; ?>;
        display:flex; justify-content:flex-start; align-items:flex-start;
    }

    /* بومِ اصلی، دقیقاً هم‌سایز خودِ عکس مرجع */
    .rp{
        width:<?php echo $CANVAS_W; ?>px; height:<?php echo $CANVAS_H; ?>px;
        position:relative; overflow:hidden; flex:none;
    }
    .rp-bg{
        position:absolute; top:0; left:0;
        width:<?php echo $CANVAS_W; ?>px; height:<?php echo $CANVAS_H; ?>px;
        display:block; z-index:0;
    }

    /* روی عکسِ خام دیگر چیزی برای پوشاندن نیست، پس رنگ/پچِ پشتِ عدد لازم
       نیست — فقط خودِ متنِ سفید با یک سایه‌ی ملایم برای خوانایی روی
       پس‌زمینه‌ی تیره‌ی کارت. */
    .rp-price{
        position:absolute; z-index:2; direction:ltr; text-align:left;
        color:#fff; font-weight:800; line-height:1; white-space:nowrap;
        text-shadow:0 2px 5px rgba(0,0,0,.7);
    }

    /* باکسِ درصد از قبل روی خودِ عکس کشیده شده (rp_badge_box)؛ اینجا فقط
       یک رنگِ نیمه‌شفافِ متناسب با روند رویش می‌نشیند تا هم‌رنگِ سبز/قرمزِ
       آشنا باشد، بعد خودِ متن. */
    .rp-badge-tint{ position:absolute; z-index:1; border-radius:10px; }
    .rp-badge-tint.up  { background:rgba(34,211,122,.20); border:1px solid rgba(34,211,122,.45); }
    .rp-badge-tint.down{ background:rgba(255,77,109,.20); border:1px solid rgba(255,77,109,.45); }
    .rp-badge-tint.flat{ background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.25); }

    .rp-badge{
        position:absolute; z-index:3; direction:ltr;
        display:flex; align-items:center; justify-content:center; gap:3px;
        font-weight:800; line-height:1; white-space:nowrap;
    }
    .rp-badge.up  { color:#7df0b0; }
    .rp-badge.down{ color:#ff96a8; }
    .rp-badge.flat{ color:#cbbde8; }

    .rp-chart{ position:absolute; z-index:1; }

    /* پیل‌های تاریخ/ساعت هم روی عکسِ خام خالی‌اند، پس فقط متن رویشان
       می‌نشیند — نیازی به پچِ پوشاننده نیست. */
    .rp-meta-txt{
        position:absolute; z-index:2;
        display:flex; align-items:center; justify-content:center; gap:8px;
        color:#fff; font-size:1.05rem; font-weight:900; white-space:nowrap;
    }
    .rp-meta-txt .ic{ font-size:1rem; opacity:.9; }
</style>
</head>
<body>
<div class="rp" id="rpCanvas">
    <img class="rp-bg" src="assets/images/rate_poster_bg.png?v=<?php echo @filemtime(__DIR__ . '/assets/images/rate_poster_bg.png') ?: time(); ?>" alt="">

    <!-- ============ تاریخ / ساعت ============ -->
    <div class="rp-meta-txt" style="left:64px; top:368px; width:314px; height:50px; direction:rtl;">
        <span class="ic">🗓</span>
        <span><?php echo htmlspecialchars($snap['title_date'] ?? ''); ?></span>
    </div>

    <div class="rp-meta-txt" style="left:390px; top:368px; width:258px; height:50px; direction:ltr;">
        <span><?php echo htmlspecialchars($snap['title_time'] ?? ''); ?></span>
        <span class="ic">🕐</span>
    </div>

    <!-- ============ ۱۴ کارت ارز — روی عکسِ خام (بدون هیچ عدد/نموداری از قبل) ============ -->
    <?php
    $FONT_FILE = __DIR__ . '/assets/fonts/Vazirmatn-Bold.ttf';
    foreach ($items as $i => $it):
        [$cx, $cy, $cw] = rp_card_geo($i);
        $col = $i % 3;
        $trend = $it['trend'] ?? 'flat';
        $badgeCls = ($trend === 'up') ? 'up' : (($trend === 'down') ? 'down' : 'flat');
        $arrow = ($trend === 'up') ? '▲' : (($trend === 'down') ? '▼' : '–');
        $chgAbs = abs((float)($it['chg'] ?? 0));
        $priceTxt = rp_price($it);

        // اندازه‌ی فونتِ قیمت: به‌جای حدس‌زدن از روی تعداد کاراکتر (که باعث
        // می‌شد قیمت‌های بلند مثل «USD»ِ بیت‌کوین/اتریوم از کارت بیرون
        // بزنند یا نصفه دیده شوند)، عرضِ واقعیِ متن با همین فونت اندازه‌گیری
        // و فونت تا جایی کوچک می‌شود که مطمئناً داخل کارت جا شود.
        $priceMaxW   = $cw - 22;
        $priceCeil   = $cw >= 200 ? 30 : ($cw >= 170 ? 27 : 24);
        $priceFontPx = rp_fit_font_size($priceTxt, $priceMaxW, $FONT_FILE, $priceCeil, 15);

        [$bx, $by, $bw, $bh] = rp_badge_box($cx, $cy, $cw, $col);

        // اندازه‌ی فونتِ درصد هم به همین شکل: فقط بخشِ عددی (بدون فلش)
        // اندازه‌گیری می‌شود، و برای فلش+فاصله ۱۸px جا نگه داشته می‌شود.
        $badgeNumTxt  = rp_fa_num(number_format($chgAbs, 2)) . '%';
        $badgeMaxW    = $bw - 22; // ۱۰px پدینگ هر طرف + ~۱۸px جای فلش
        $badgeFontPx  = rp_fit_font_size($badgeNumTxt, $badgeMaxW, $FONT_FILE, 14, 9);

        // نمودار کوچک بین لبه‌ی چپِ کارت و باکسِ درصد، هم‌ردیفِ همان باکس
        $chartLeft  = $cx + 8;
        $chartRight = $bx - 8;
        $chartW     = max(20, $chartRight - $chartLeft);
        $chartH     = $bh + 6;
        $chartTop   = $by - 3;
        $sparkSeed  = $it['code'] . '|' . ($snap['title_date'] ?? '') . '|' . ($snap['title_time'] ?? '');
    ?>
    <div class="rp-price" style="left:<?php echo $cx + 12; ?>px; top:<?php echo $cy + 62; ?>px; font-size:<?php echo $priceFontPx; ?>px;">
        <?php echo $priceTxt; ?>
    </div>

    <div class="rp-chart" style="left:<?php echo $chartLeft; ?>px; top:<?php echo $chartTop; ?>px; width:<?php echo $chartW; ?>px; height:<?php echo $chartH; ?>px;">
        <?php echo rp_spark_svg($trend, $chartW, $chartH, $sparkSeed); ?>
    </div>

    <div class="rp-badge-tint <?php echo $badgeCls; ?>" style="left:<?php echo $bx; ?>px; top:<?php echo $by; ?>px; width:<?php echo $bw; ?>px; height:<?php echo $bh; ?>px;"></div>
    <div class="rp-badge <?php echo $badgeCls; ?>" style="left:<?php echo $bx; ?>px; top:<?php echo $by; ?>px; width:<?php echo $bw; ?>px; height:<?php echo $bh; ?>px; font-size:<?php echo $badgeFontPx; ?>px;">
        <span style="font-size:.85em;"><?php echo $arrow; ?></span><span><?php echo $badgeNumTxt; ?></span>
    </div>
    <?php endforeach; ?>

</div>
</body>
</html>
