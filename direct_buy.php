<?php
/**
 * direct_buy.php
 * ---------------------------------------------------------------------------
 * «خرید مستقیم»: کاربر ارز (USD/EUR/USDT) را انتخاب می‌کند، از روی نمودار
 * لحظه‌ای قیمت هدف را با کشیدن انگشت مشخص می‌کند، مقدار مورد نظر را وارد
 * می‌کند و درخواست را ثبت می‌کند. درخواست به پنل ادمین می‌رود؛ ادمین هر وقت
 * آماده بود «موجود است» را می‌زند و صورت‌حساب می‌سازد (از همان سیستم فیش
 * موجود در dashboard.php — بنر «صورت‌حساب پرداخت‌نشده» به‌طور خودکار روی
 * داشبورد ظاهر می‌شود، چیزی برای پرداخت از نو ساخته نشده).
 * ---------------------------------------------------------------------------
 */
require_once __DIR__ . '/includes/camera_headers.php';
require_once __DIR__ . '/includes/session_boot.php';
require_once 'config/database.php';

avapay_restore_session_from_token($conn);

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
$userId = $_SESSION['user_id'];
$embed  = isset($_GET['embed']);

// قیمت‌های لحظه‌ای سه ارز — برای رندر اولیه‌ی کارت‌های انتخاب ارز (بدون نیاز به AJAX اول کار)
$dbCurMeta = [
    'USD'  => ['name' => 'دلار آمریکا', 'symbol' => '$', 'color' => '#22C55E'],
    'EUR'  => ['name' => 'یورو',        'symbol' => '€', 'color' => '#38BDF8'],
    'USDT' => ['name' => 'تتر',         'symbol' => '₮', 'color' => '#26A17B'],
];
$dbLive = [];
foreach ($dbCurMeta as $code => $meta) {
    $lookup = ($code === 'USDT') ? "IN ('USDT','TETHER')" : ("= '" . $conn->real_escape_string($code) . "'");
    $row = $conn->query("SELECT price, change_24h FROM currency_rates WHERE currency $lookup ORDER BY id DESC LIMIT 1")->fetch_assoc();
    $dbLive[$code] = [
        'price'  => $row ? (float)$row['price'] : 0.0,
        'change' => $row ? (float)$row['change_24h'] : 0.0,
    ];
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>خرید مستقیم — آواپی</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root{
    --db-bg:#0A0520; --db-card:#150B2E; --db-card2:#1B1038; --db-line:rgba(255,255,255,.08);
    --db-txt:#fff; --db-mut:rgba(255,255,255,.55); --db-violet:#8B5CF6; --db-violet2:#A855F7;
    --db-green:#22C55E; --db-red:#FF5A6E; --db-gold:#FFD700;
}
*{box-sizing:border-box; -webkit-tap-highlight-color:transparent;}
html,body{margin:0;padding:0;background:var(--db-bg);color:var(--db-txt);font-family:'Vazirmatn',Tahoma,sans-serif;}
body{padding:14px;padding-bottom:calc(24px + env(safe-area-inset-bottom));min-height:100vh;}
<?php if ($embed): ?>
body{padding-top:14px;}
<?php else: ?>
body{padding-top:calc(14px + env(safe-area-inset-top));}
<?php endif; ?>

.db-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;}
.db-back{width:38px;height:38px;border-radius:12px;background:var(--db-card2);border:1px solid var(--db-line);
    display:flex;align-items:center;justify-content:center;color:#fff;cursor:pointer;font-size:.95rem;}
.db-title{font-weight:800;font-size:.95rem;display:flex;align-items:center;gap:8px;}
.db-title i{color:var(--db-violet2);}
.db-spacer{width:38px;}

/* ---------- مرحله‌ی ۱: انتخاب ارز ---------- */
#dbStepPick .db-hint{font-size:.74rem;color:var(--db-mut);margin-bottom:14px;line-height:1.9;}
.db-cur-list{display:flex;flex-direction:column;gap:10px;}
.db-cur-card{display:flex;align-items:center;gap:12px;padding:14px;border-radius:18px;
    background:linear-gradient(135deg,var(--db-card),var(--db-card2));border:1px solid var(--db-line);cursor:pointer;
    transition:transform .15s ease, border-color .2s ease;}
.db-cur-card:active{transform:scale(.98);}
.db-cur-ic{width:48px;height:48px;border-radius:16px;display:flex;align-items:center;justify-content:center;
    font-size:1.2rem;font-weight:800;color:#fff;flex-shrink:0;}
.db-cur-body{flex:1;min-width:0;}
.db-cur-name{font-weight:800;font-size:.86rem;}
.db-cur-code{font-size:.62rem;color:var(--db-mut);margin-top:2px;}
.db-cur-price{text-align:left;}
.db-cur-price b{font-size:.92rem;display:block;}
.db-cur-chg{font-size:.66rem;font-weight:700;margin-top:2px;}
.db-cur-chg.up{color:var(--db-green);} .db-cur-chg.down{color:var(--db-red);}
.db-cur-card i.fa-chevron-left{color:var(--db-mut);font-size:.8rem;}

/* ---------- مرحله‌ی ۲: نمودار + فرم ---------- */
#dbStepChart{display:none;}
.db-price-hero{margin-bottom:6px;}
.db-price-live{display:flex;align-items:center;gap:6px;font-size:.68rem;color:var(--db-mut);margin-bottom:6px;}
.db-price-dot{width:7px;height:7px;border-radius:50%;background:var(--db-green);box-shadow:0 0 8px var(--db-green);}
.db-price-big{font-size:2rem;font-weight:800;display:flex;align-items:baseline;gap:8px;}
.db-price-big small{font-size:.9rem;color:var(--db-mut);font-weight:700;}
.db-price-chg{font-size:.8rem;font-weight:700;margin-top:2px;}
.db-price-chg.up{color:var(--db-green);} .db-price-chg.down{color:var(--db-red);}

.db-chart-wrap{position:relative;margin:16px 0 10px;background:var(--db-card);border:1px solid var(--db-line);
    border-radius:18px;padding:10px 6px 6px;touch-action:none;}
.db-chart-wrap svg{display:block;width:100%;height:auto;}
.db-chart-range{text-align:center;font-size:.62rem;color:var(--db-mut);margin-bottom:2px;}
.db-chart-loading{display:flex;align-items:center;justify-content:center;height:220px;color:var(--db-mut);font-size:.8rem;}
.db-target-tooltip{position:absolute;background:#1B1038;border:1px solid var(--db-violet2);border-radius:12px;
    padding:8px 12px;font-size:.72rem;text-align:center;pointer-events:none;transform:translate(-50%,-115%);
    white-space:nowrap;box-shadow:0 8px 20px rgba(0,0,0,.4);z-index:5;}
.db-target-tooltip .l{color:var(--db-mut);font-size:.62rem;margin-bottom:2px;}
.db-target-tooltip .v{font-weight:800;color:var(--db-gold);font-size:.82rem;}

.db-info-box{display:flex;align-items:flex-start;gap:10px;background:var(--db-card);border:1px solid var(--db-line);
    border-radius:16px;padding:14px;margin-bottom:14px;font-size:.72rem;color:var(--db-mut);line-height:2;}
.db-info-box i{color:var(--db-green);margin-top:2px;flex-shrink:0;}

.db-form-box{background:var(--db-card);border:1px solid var(--db-line);border-radius:18px;padding:16px;margin-bottom:14px;}
.db-field{margin-bottom:14px;}
.db-field:last-child{margin-bottom:0;}
.db-field-label{display:flex;align-items:center;justify-content:space-between;font-size:.72rem;color:var(--db-mut);margin-bottom:8px;}
.db-field-label .edit-ic{color:var(--db-violet2);cursor:pointer;font-size:.8rem;}
.db-target-value{font-size:1.25rem;font-weight:800;color:var(--db-gold);}
.db-target-input{width:100%;background:var(--db-card2);border:1px solid var(--db-line);border-radius:12px;
    padding:12px 14px;color:#fff;font-size:1rem;font-weight:800;font-family:inherit;text-align:left;direction:ltr;display:none;}
.db-amount-row{display:flex;align-items:center;gap:8px;background:var(--db-card2);border:1px solid var(--db-line);
    border-radius:14px;padding:4px 4px 4px 14px;}
.db-amount-row input{flex:1;background:none;border:none;color:#fff;font-size:1.05rem;font-weight:800;font-family:inherit;
    padding:10px 0;outline:none;text-align:left;direction:ltr;min-width:0;}
.db-amount-unit{background:var(--db-violet);color:#fff;font-size:.7rem;font-weight:800;padding:8px 12px;border-radius:10px;white-space:nowrap;}
.db-total-row{display:flex;justify-content:space-between;align-items:center;font-size:.72rem;color:var(--db-mut);
    margin-top:10px;padding-top:10px;border-top:1px dashed var(--db-line);}
.db-total-row b{color:#fff;font-size:.86rem;}

.db-submit-btn{width:100%;border:none;border-radius:16px;padding:16px;font-family:inherit;font-size:.92rem;font-weight:800;
    color:#0b0b16;background:linear-gradient(135deg,var(--db-green),#16A34A);cursor:pointer;display:flex;align-items:center;
    justify-content:center;gap:8px;box-shadow:0 10px 24px -8px rgba(34,197,94,.5);}
.db-submit-btn:disabled{opacity:.5;cursor:not-allowed;}
.db-submit-btn:active{transform:scale(.98);}
.db-secure-note{text-align:center;font-size:.65rem;color:var(--db-mut);margin-top:12px;display:flex;align-items:center;justify-content:center;gap:6px;}
.db-secure-note i{color:var(--db-green);}

/* ---------- درخواست‌های من ---------- */
.db-myreq-title{font-size:.82rem;font-weight:800;margin:22px 0 10px;display:flex;align-items:center;gap:8px;}
.db-myreq-title i{color:var(--db-violet2);}
.db-myreq-list{display:flex;flex-direction:column;gap:10px;}
.db-myreq-item{background:var(--db-card);border:1px solid var(--db-line);border-radius:14px;padding:12px 14px;}
.db-myreq-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;}
.db-myreq-cur{font-weight:800;font-size:.82rem;}
.db-myreq-badge{font-size:.62rem;font-weight:800;padding:4px 10px;border-radius:20px;white-space:nowrap;}
.db-myreq-badge.pending{background:rgba(255,215,0,.15);color:var(--db-gold);}
.db-myreq-badge.available{background:rgba(56,189,248,.15);color:#38BDF8;}
.db-myreq-badge.invoiced{background:rgba(34,197,94,.15);color:var(--db-green);}
.db-myreq-badge.completed{background:rgba(34,197,94,.2);color:var(--db-green);}
.db-myreq-badge.cancelled{background:rgba(255,90,110,.15);color:var(--db-red);}
.db-myreq-detail{font-size:.68rem;color:var(--db-mut);line-height:1.8;}
.db-myreq-actions{display:flex;gap:8px;margin-top:10px;}
.db-myreq-actions button{flex:1;border:none;border-radius:10px;padding:9px;font-family:inherit;font-size:.68rem;font-weight:700;cursor:pointer;}
.db-myreq-cancel{background:rgba(255,90,110,.15);color:var(--db-red);}
.db-myreq-pay{background:linear-gradient(135deg,var(--db-green),#16A34A);color:#0b0b16;}
.db-empty{text-align:center;color:var(--db-mut);font-size:.76rem;padding:26px 0;}

.db-toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(20px);background:#1B1038;
    border:1px solid var(--db-line);border-radius:14px;padding:12px 20px;font-size:.78rem;opacity:0;transition:all .25s ease;
    z-index:9999;display:flex;align-items:center;gap:8px;box-shadow:0 10px 30px rgba(0,0,0,.5);max-width:90vw;}
.db-toast.show{opacity:1;transform:translateX(-50%) translateY(0);}
.db-toast.err{border-color:var(--db-red);} .db-toast.err i{color:var(--db-red);}
.db-toast:not(.err) i{color:var(--db-green);}
</style>
</head>
<body>

<div class="db-header">
    <div class="db-back" id="dbBackBtn" onclick="dbGoBack()"><i class="fas fa-arrow-right"></i></div>
    <div class="db-title"><i class="fas fa-bolt"></i> خرید مستقیم</div>
    <div class="db-spacer"></div>
</div>

<!-- ================= مرحله ۱: انتخاب ارز ================= -->
<div id="dbStepPick">
    <div class="db-hint">ارزی که می‌خواهید بخرید را انتخاب کنید — سپس از روی نمودار لحظه‌ای، قیمتی که مدنظرتان است را با کشیدن انگشت مشخص می‌کنید.</div>
    <div class="db-cur-list">
        <?php foreach ($dbCurMeta as $code => $meta): $live = $dbLive[$code]; $up = $live['change'] >= 0; ?>
        <div class="db-cur-card" onclick="dbSelectCurrency('<?php echo $code; ?>')">
            <div class="db-cur-ic" style="background:<?php echo $meta['color']; ?>22;color:<?php echo $meta['color']; ?>;"><?php echo $meta['symbol']; ?></div>
            <div class="db-cur-body">
                <div class="db-cur-name"><?php echo $meta['name']; ?></div>
                <div class="db-cur-code"><?php echo $code; ?> / تومان</div>
            </div>
            <div class="db-cur-price">
                <b><?php echo number_format($live['price']); ?></b>
                <div class="db-cur-chg <?php echo $up ? 'up' : 'down'; ?>"><?php echo ($up ? '+' : '') . number_format($live['change'], 2); ?>%</div>
            </div>
            <i class="fas fa-chevron-left"></i>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="db-myreq-title"><i class="fas fa-list-check"></i> درخواست‌های من</div>
    <div class="db-myreq-list" id="dbMyReqList"><div class="db-empty">در حال بارگذاری…</div></div>
</div>

<!-- ================= مرحله ۲: نمودار + فرم ثبت ================= -->
<div id="dbStepChart">
    <div class="db-price-hero">
        <div class="db-price-live"><span class="db-price-dot"></span> قیمت لحظه‌ای <span id="dbCurLabel"></span></div>
        <div class="db-price-big"><span id="dbLivePrice">0</span><small>IRT</small></div>
        <div class="db-price-chg" id="dbLiveChg"></div>
    </div>

    <div class="db-chart-range" id="dbChartRange"></div>
    <div class="db-chart-wrap" id="dbChartWrap">
        <div class="db-chart-loading" id="dbChartLoading"><i class="fas fa-spinner fa-spin"></i>&nbsp; در حال بارگذاری نمودار…</div>
    </div>

    <div class="db-info-box">
        <i class="fas fa-circle-info"></i>
        <div>شما با ثبت درخواست خرید مستقیم، وقتی ارزی برای فروش در این نقاط در اختیار ما قرار گیرد فورا برای شما یه اطلاعیه صادر می‌شه و درصورت پذیرش، شما تراکنش را انجام می‌شود.</div>
    </div>

    <div class="db-form-box">
        <div class="db-field">
            <div class="db-field-label">
                <span>قیمت هدف (تومان)</span>
                <i class="fas fa-pen edit-ic" id="dbEditPriceIc" onclick="dbToggleManualPrice()"></i>
            </div>
            <div class="db-target-value" id="dbTargetValueShow">—</div>
            <input type="number" class="db-target-input" id="dbTargetValueInput" inputmode="decimal" placeholder="مثلاً 230000">
        </div>
        <div class="db-field">
            <div class="db-field-label"><span>مقدار</span></div>
            <div class="db-amount-row">
                <input type="number" id="dbAmountInput" inputmode="decimal" placeholder="مثلاً 100" oninput="dbUpdateTotal()">
                <span class="db-amount-unit" id="dbAmountUnit">EUR</span>
            </div>
            <div class="db-total-row">
                <span>مبلغ کل تقریبی</span>
                <b id="dbTotalShow">0 تومان</b>
            </div>
        </div>
    </div>

    <button type="button" class="db-submit-btn" id="dbSubmitBtn" onclick="dbSubmitRequest()">
        <i class="fas fa-paper-plane"></i> ثبت درخواست خرید مستقیم
    </button>
    <div class="db-secure-note"><i class="fas fa-shield-halved"></i> اطلاعات شما کاملاً امن و رمزگذاری‌شده است.</div>
</div>

<div class="db-toast" id="dbToast"></div>

<script>
const DB_CUR_META = <?php echo json_encode($dbCurMeta, JSON_UNESCAPED_UNICODE); ?>;
let dbCurrentCurrency = null;
let dbSeries = [];       // [{x, p}] مختصات نگاشت‌شده روی نمودار
let dbSeriesRaw = [];    // [{p, t}] خام از سرور
let dbTargetPrice = 0;
let dbChartMinP = 0, dbChartMaxP = 0;
const DB_CHART_VW = 340, DB_CHART_VH = 200, DB_CHART_PAD_L = 54, DB_CHART_PAD_R = 8, DB_CHART_PAD_T = 14, DB_CHART_PAD_B = 22;

function dbGoBack(){
    if (document.getElementById('dbStepChart').style.display === 'block'){
        document.getElementById('dbStepChart').style.display = 'none';
        document.getElementById('dbStepPick').style.display = 'block';
        dbLoadMyRequests();
        return;
    }
    if (window.parent && window.parent !== window && typeof window.parent.axCloseServiceModal === 'function'){
        window.parent.axCloseServiceModal();
        return;
    }
    window.history.back();
}

function dbToast(msg, isErr){
    const t = document.getElementById('dbToast');
    t.innerHTML = '<i class="fas ' + (isErr ? 'fa-circle-exclamation' : 'fa-circle-check') + '"></i><span>' + msg + '</span>';
    t.className = 'db-toast show' + (isErr ? ' err' : '');
    clearTimeout(t._t);
    t._t = setTimeout(()=>{ t.classList.remove('show'); }, isErr ? 4200 : 3000);
}

function dbFmt(n, dec){
    dec = dec || 0;
    n = Number(n) || 0;
    return n.toLocaleString('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec });
}

/* ==================== انتخاب ارز و بارگذاری نمودار ==================== */
async function dbSelectCurrency(cur){
    dbCurrentCurrency = cur;
    const meta = DB_CUR_META[cur];
    document.getElementById('dbStepPick').style.display = 'none';
    document.getElementById('dbStepChart').style.display = 'block';
    document.getElementById('dbCurLabel').textContent = '(' + meta.name + ')';
    document.getElementById('dbAmountUnit').textContent = cur;
    document.getElementById('dbAmountInput').value = '';
    document.getElementById('dbTotalShow').textContent = '0 تومان';
    document.getElementById('dbTargetValueInput').style.display = 'none';
    document.getElementById('dbTargetValueShow').style.display = 'block';

    const wrap = document.getElementById('dbChartWrap');
    wrap.innerHTML = '<div class="db-chart-loading" id="dbChartLoading"><i class="fas fa-spinner fa-spin"></i>&nbsp; در حال بارگذاری نمودار…</div>';
    const rangeElReset = document.getElementById('dbChartRange');
    if (rangeElReset) rangeElReset.textContent = '';

    try{
        const r = await fetch('api/direct_buy_api.php?action=price_history&currency=' + cur, { cache: 'no-store' });
        const d = await r.json();
        if (!d.success || !d.series || !d.series.length){
            wrap.innerHTML = '<div class="db-chart-loading">داده‌ی کافی برای رسم نمودار موجود نیست</div>';
            return;
        }
        dbSeriesRaw = d.series;
        document.getElementById('dbLivePrice').textContent = dbFmt(d.live);
        const chgEl = document.getElementById('dbLiveChg');
        const up = d.change24h >= 0;
        chgEl.textContent = (up ? '+' : '') + dbFmt(d.change24h, 2) + '% تغییرات روزانه';
        chgEl.className = 'db-price-chg ' + (up ? 'up' : 'down');

        // بازه‌ی زمانی نمودار (از قدیمی‌ترین تا جدیدترین نقطه) — تا کاربر بداند
        // این نمودار چند ماه از تاریخچه‌ی قیمت را نشان می‌دهد
        const rangeEl = document.getElementById('dbChartRange');
        if (rangeEl && dbSeriesRaw.length > 1){
            const fmtDate = (t) => new Date(t.replace(' ', 'T')).toLocaleDateString('fa-IR', { year: 'numeric', month: 'long', day: 'numeric' });
            rangeEl.textContent = 'از ' + fmtDate(dbSeriesRaw[0].t) + ' تا امروز';
        } else if (rangeEl) {
            rangeEl.textContent = '';
        }

        dbTargetPrice = d.live || dbSeriesRaw[dbSeriesRaw.length - 1].p;
        dbBuildChart(wrap);
    } catch(e){
        wrap.innerHTML = '<div class="db-chart-loading">خطا در دریافت اطلاعات نمودار</div>';
    }
}

/* ==================== ساخت نمودار SVG + تعامل کشیدن انگشت ==================== */
function dbBuildChart(wrap){
    const prices = dbSeriesRaw.map(s => s.p);
    let minP = Math.min.apply(null, prices), maxP = Math.max.apply(null, prices);
    if (minP === maxP){ minP -= 1; maxP += 1; }
    const pad = (maxP - minP) * 0.12 || 1;
    dbChartMinP = minP - pad;
    dbChartMaxP = maxP + pad;

    const plotW = DB_CHART_VW - DB_CHART_PAD_L - DB_CHART_PAD_R;
    const plotH = DB_CHART_VH - DB_CHART_PAD_T - DB_CHART_PAD_B;
    const n = dbSeriesRaw.length;

    dbSeries = dbSeriesRaw.map((s, i) => ({
        x: DB_CHART_PAD_L + (n === 1 ? plotW : (plotW * i / (n - 1))),
        p: s.p,
    }));

    const priceToY = (p) => DB_CHART_PAD_T + plotH - ((p - dbChartMinP) / (dbChartMaxP - dbChartMinP)) * plotH;

    let linePoints = dbSeries.map(pt => pt.x + ',' + priceToY(pt.p).toFixed(1)).join(' ');
    let areaPoints = linePoints + ' ' + dbSeries[n-1].x + ',' + (DB_CHART_PAD_T + plotH) + ' ' + dbSeries[0].x + ',' + (DB_CHART_PAD_T + plotH);

    // خطوط راهنمای افقی قیمت (۳ خط)
    let gridLines = '';
    for (let i = 0; i <= 2; i++){
        const gp = dbChartMinP + ((dbChartMaxP - dbChartMinP) * i / 2);
        const gy = priceToY(gp);
        gridLines += '<line x1="' + DB_CHART_PAD_L + '" y1="' + gy.toFixed(1) + '" x2="' + (DB_CHART_VW - DB_CHART_PAD_R) + '" y2="' + gy.toFixed(1) + '" stroke="rgba(255,255,255,.06)" stroke-width="1"/>';
        gridLines += '<text x="' + (DB_CHART_PAD_L - 6) + '" y="' + (gy + 3).toFixed(1) + '" text-anchor="end" font-size="8" fill="rgba(255,255,255,.4)">' + dbFmt(gp) + '</text>';
    }

    const svg = '<svg viewBox="0 0 ' + DB_CHART_VW + ' ' + DB_CHART_VH + '" xmlns="http://www.w3.org/2000/svg">' +
        '<defs><linearGradient id="dbAreaGrad" x1="0" y1="0" x2="0" y2="1">' +
            '<stop offset="0%" stop-color="#22C55E" stop-opacity="0.35"/>' +
            '<stop offset="100%" stop-color="#22C55E" stop-opacity="0"/>' +
        '</linearGradient></defs>' +
        gridLines +
        '<polygon points="' + areaPoints + '" fill="url(#dbAreaGrad)"/>' +
        '<polyline points="' + linePoints + '" fill="none" stroke="#22C55E" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>' +
        '<line id="dbGuideLine" x1="' + DB_CHART_PAD_L + '" y1="0" x2="0" y2="0" stroke="#FFD700" stroke-width="1" stroke-dasharray="4,3" opacity="0"/>' +
        '<circle id="dbTargetDot" cx="0" cy="0" r="12" fill="rgba(255,215,0,.18)"/>' +
        '<circle id="dbTargetDotCore" cx="0" cy="0" r="5" fill="#FFD700" stroke="#fff" stroke-width="1.5"/>' +
        '<rect id="dbOverlay" x="' + DB_CHART_PAD_L + '" y="0" width="' + plotW + '" height="' + DB_CHART_VH + '" fill="transparent" style="cursor:ns-resize;"/>' +
        '</svg>' +
        '<div class="db-target-tooltip" id="dbTooltip" style="display:none;"><div class="l">قیمت هدف</div><div class="v" id="dbTooltipVal">0</div></div>';

    wrap.innerHTML = svg;

    dbYToPrice = (y) => dbChartMaxP - ((y - DB_CHART_PAD_T) / plotH) * (dbChartMaxP - dbChartMinP);
    dbPriceToY = priceToY;

    dbUpdateTargetVisual(dbTargetPrice);
    dbWireChartDrag(wrap, plotH);
}

let dbYToPrice = null, dbPriceToY = null;

function dbFindCrossingX(targetPrice){
    const n = dbSeries.length;
    for (let i = n - 1; i > 0; i--){
        const p1 = dbSeries[i-1].p, p2 = dbSeries[i].p;
        const x1 = dbSeries[i-1].x, x2 = dbSeries[i].x;
        if ((p1 <= targetPrice && p2 >= targetPrice) || (p1 >= targetPrice && p2 <= targetPrice)){
            if (p1 === p2) return x2;
            const ratio = (targetPrice - p1) / (p2 - p1);
            return x1 + (x2 - x1) * ratio;
        }
    }
    const first = dbSeries[0], last = dbSeries[n-1];
    if (targetPrice > Math.max(first.p, last.p) || targetPrice < Math.min(first.p, last.p)){
        return Math.abs(targetPrice - last.p) < Math.abs(targetPrice - first.p) ? last.x : first.x;
    }
    return last.x;
}

function dbUpdateTargetVisual(targetPrice){
    dbTargetPrice = Math.max(1, targetPrice);
    const y = dbPriceToY(dbTargetPrice);
    const x = dbFindCrossingX(dbTargetPrice);

    const guide = document.getElementById('dbGuideLine');
    const dot = document.getElementById('dbTargetDot');
    const dotCore = document.getElementById('dbTargetDotCore');
    if (guide){ guide.setAttribute('y1', y.toFixed(1)); guide.setAttribute('y2', y.toFixed(1)); guide.setAttribute('x2', x.toFixed(1)); guide.setAttribute('opacity', '1'); }
    if (dot){ dot.setAttribute('cx', x.toFixed(1)); dot.setAttribute('cy', y.toFixed(1)); }
    if (dotCore){ dotCore.setAttribute('cx', x.toFixed(1)); dotCore.setAttribute('cy', y.toFixed(1)); }

    const wrap = document.getElementById('dbChartWrap');
    const tooltip = document.getElementById('dbTooltip');
    if (tooltip && wrap){
        const wrapW = wrap.clientWidth || DB_CHART_VW;
        const scale = wrapW / DB_CHART_VW;
        tooltip.style.left = (x * scale) + 'px';
        tooltip.style.top = (y * scale) + 'px';
        tooltip.style.display = 'block';
        document.getElementById('dbTooltipVal').textContent = dbFmt(dbTargetPrice);
    }

    document.getElementById('dbTargetValueShow').textContent = dbFmt(dbTargetPrice) + ' تومان';
    const manualInput = document.getElementById('dbTargetValueInput');
    if (manualInput && document.activeElement !== manualInput) manualInput.value = Math.round(dbTargetPrice);
    dbUpdateTotal();
}

function dbWireChartDrag(wrap, plotH){
    const svg = wrap.querySelector('svg');
    const overlay = document.getElementById('dbOverlay');
    if (!svg || !overlay) return;
    let dragging = false;

    function clientToSvgY(clientY){
        const rect = svg.getBoundingClientRect();
        const scaleY = DB_CHART_VH / rect.height;
        return (clientY - rect.top) * scaleY;
    }

    function handleMove(clientY){
        let y = clientToSvgY(clientY);
        y = Math.max(DB_CHART_PAD_T, Math.min(DB_CHART_PAD_T + plotH, y));
        dbUpdateTargetVisual(dbYToPrice(y));
    }

    overlay.addEventListener('pointerdown', function(e){
        dragging = true;
        try { overlay.setPointerCapture(e.pointerId); } catch(err){}
        handleMove(e.clientY);
    });
    overlay.addEventListener('pointermove', function(e){
        if (!dragging) return;
        handleMove(e.clientY);
    });
    overlay.addEventListener('pointerup', function(){ dragging = false; });
    overlay.addEventListener('pointercancel', function(){ dragging = false; });
}

/* ==================== ویرایش دستی قیمت هدف ==================== */
function dbToggleManualPrice(){
    const show = document.getElementById('dbTargetValueShow');
    const input = document.getElementById('dbTargetValueInput');
    const isEditing = input.style.display !== 'none';
    if (isEditing){
        const v = parseFloat(input.value);
        if (v > 0) dbUpdateTargetVisual(v);
        input.style.display = 'none';
        show.style.display = 'block';
    } else {
        input.value = Math.round(dbTargetPrice);
        input.style.display = 'block';
        show.style.display = 'none';
        input.focus();
        input.select();
    }
}
document.addEventListener('DOMContentLoaded', function(){
    const input = document.getElementById('dbTargetValueInput');
    if (input){
        input.addEventListener('keydown', function(e){ if (e.key === 'Enter') dbToggleManualPrice(); });
    }
});

/* ==================== مجموع تقریبی ==================== */
function dbUpdateTotal(){
    const amt = parseFloat(document.getElementById('dbAmountInput').value) || 0;
    const total = amt * dbTargetPrice;
    document.getElementById('dbTotalShow').textContent = dbFmt(total) + ' تومان';
}

/* ==================== ثبت درخواست ==================== */
async function dbSubmitRequest(){
    const amt = parseFloat(document.getElementById('dbAmountInput').value);
    if (!dbCurrentCurrency){ dbToast('ابتدا یک ارز انتخاب کنید', true); return; }
    if (!dbTargetPrice || dbTargetPrice <= 0){ dbToast('قیمت هدف نامعتبر است', true); return; }
    if (!amt || amt <= 0){ dbToast('مقدار را وارد کنید', true); return; }

    const btn = document.getElementById('dbSubmitBtn');
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال ثبت…';

    try{
        const r = await fetch('api/direct_buy_api.php?action=submit', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ currency: dbCurrentCurrency, target_price: dbTargetPrice, amount: amt })
        });
        const d = await r.json();
        if (d.success){
            dbToast(d.message || 'درخواست شما ثبت شد');
            document.getElementById('dbAmountInput').value = '';
            dbUpdateTotal();
            dbGoBack();
        } else {
            dbToast(d.message || 'خطا در ثبت درخواست', true);
        }
    } catch(e){
        dbToast('خطای شبکه', true);
    } finally {
        btn.disabled = false;
        btn.innerHTML = orig;
    }
}

/* ==================== درخواست‌های من ==================== */
const DB_STATUS_LABEL = {
    pending: 'در انتظار بررسی', available: 'ارز موجود شد', invoiced: 'صورت‌حساب صادر شد',
    completed: 'تکمیل شد', cancelled: 'لغو شد'
};
async function dbLoadMyRequests(){
    const box = document.getElementById('dbMyReqList');
    try{
        const r = await fetch('api/direct_buy_api.php?action=my_list', { cache: 'no-store' });
        const d = await r.json();
        if (!d.success || !d.requests || !d.requests.length){
            box.innerHTML = '<div class="db-empty">هنوز درخواستی ثبت نکرده‌اید</div>';
            return;
        }
        box.innerHTML = d.requests.map(function(req){
            const st = req.status;
            const label = DB_STATUS_LABEL[st] || st;
            let actions = '';
            if (st === 'pending'){
                actions = '<div class="db-myreq-actions"><button class="db-myreq-cancel" onclick="dbCancelRequest(' + req.id + ')"><i class="fas fa-xmark"></i> لغو درخواست</button></div>';
            } else if (st === 'invoiced' || st === 'available'){
                actions = '<div class="db-myreq-actions"><button class="db-myreq-pay" onclick="window.location.href=\'dashboard.php\'"><i class="fas fa-file-invoice"></i> مشاهده در داشبورد</button></div>';
            }
            return '<div class="db-myreq-item">' +
                '<div class="db-myreq-top"><span class="db-myreq-cur">' + req.currency + '</span>' +
                '<span class="db-myreq-badge ' + st + '">' + label + '</span></div>' +
                '<div class="db-myreq-detail">مقدار: ' + dbFmt(req.amount, 4) + ' ' + req.currency + '<br>' +
                'قیمت هدف: ' + dbFmt(req.target_price) + ' تومان &nbsp;•&nbsp; جمع: ' + dbFmt(req.total_toman) + ' تومان</div>' +
                actions +
                '</div>';
        }).join('');
    } catch(e){
        box.innerHTML = '<div class="db-empty">خطا در دریافت درخواست‌ها</div>';
    }
}

async function dbCancelRequest(id){
    try{
        const r = await fetch('api/direct_buy_api.php?action=cancel', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: id })
        });
        const d = await r.json();
        if (d.success){ dbToast('درخواست لغو شد'); dbLoadMyRequests(); }
        else dbToast(d.message || 'خطا در لغو درخواست', true);
    } catch(e){ dbToast('خطای شبکه', true); }
}

dbLoadMyRequests();
</script>
</body>
</html>
