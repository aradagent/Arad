<?php
// includes/footer_menu.php
// منوی فوتر مشترک برای تمام صفحات

// تابع تولید منوی فوتر
function renderFooterMenu($activePage = 'dashboard') {
    // اطلاعات کاربر جاری را برای اسکنر (دریافت/ارسال پول) می‌گیریم
    global $conn;
    $avaMe = ['account_number' => '', 'first_name' => '', 'last_name' => '', 'telegram_id' => ''];
    $avaTheme = '';
    if (isset($_SESSION['user_id']) && isset($conn) && $conn) {
        $uid = (int)$_SESSION['user_id'];
        $r = $conn->query("SELECT * FROM users WHERE id = $uid");
        if ($r && $row = $r->fetch_assoc()) { $avaMe = $row; $avaTheme = $row['theme'] ?? ''; }
    }
    $avaMeArr = [
        'account'  => $avaMe['account_number'] ?? '',
        'name'     => trim(($avaMe['first_name'] ?? '') . ' ' . ($avaMe['last_name'] ?? '')),
        'telegram' => $avaMe['telegram_id'] ?? '',
    ];

    /* ===== داده‌های لازم برای کشوی پروفایل سراسری (همه‌ی صفحات) ===== */
    $avaIsDashPage = (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'dashboard.php');
    $gFullName = trim(($avaMe['first_name'] ?? '') . ' ' . ($avaMe['last_name'] ?? ''));
    if ($gFullName === '') $gFullName = 'کاربر آواپی';
    $gAvatar   = $avaMe['avatar'] ?? '';
    if ($gAvatar === '' || $gAvatar === null) $gAvatar = '/ledor/default-avatar.png';
    $gOrders   = (int)($avaMe['completed_orders_count'] ?? 0);
    if     ($gOrders >= 50) $gLevel = 'ویژه';
    elseif ($gOrders >= 20) $gLevel = 'طلایی';
    elseif ($gOrders >= 5)  $gLevel = 'نقره‌ای';
    else                    $gLevel = 'عادی';
    $gPhone    = $avaMe['phone'] ?? ($avaMe['phone_number'] ?? '');
    $gIsAdmin  = isset($avaMe['is_admin']) && $avaMe['is_admin'] == 1;
    $gJoin     = !empty($avaMe['created_at']) ? date('Y', strtotime($avaMe['created_at'])) : '—';
    $gKyc      = $avaMe['kyc_status'] ?? 'none';
    $gKycMap   = [
        'approved' => ['تأیید شده', '#22C55E', 'fa-circle-check'],
        'pending'  => ['در انتظار بررسی', '#FBBF24', 'fa-hourglass-half'],
        'rejected' => ['رد شده', '#FF5A6E', 'fa-circle-xmark'],
    ];
    $gKycInfo  = $gKycMap[$gKyc] ?? ['انجام نشده', '#94A3B8', 'fa-id-card'];
    ?>
    <!-- اعمال فوری تم ذخیره‌شده (روشن/تاریک) -->
    <script>
    (function(){
        try {
            // اولویت: تم ذخیره‌شده در سرور، سپس localStorage
            var serverTheme = <?php echo json_encode($avaTheme ?: ''); ?>;
            var t = serverTheme || localStorage.getItem('ava_theme') || 'dark';
            if (serverTheme) { try { localStorage.setItem('ava_theme', serverTheme); } catch(e){} }
            document.documentElement.setAttribute('data-theme', t);
        } catch(e) {}
    })();
    window.avaSetTheme = function(theme){
        try { localStorage.setItem('ava_theme', theme); } catch(e){}
        document.documentElement.setAttribute('data-theme', theme);
        // ذخیره‌ی دائمی در سرور تا در همه‌ی دستگاه‌ها و همیشه بماند
        try {
            fetch('/ledor/api/notification_api.php?action=save_theme', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ theme: theme })
            });
        } catch(e){}
    };
    window.avaGetTheme = function(){
        try { return document.documentElement.getAttribute('data-theme') || localStorage.getItem('ava_theme') || 'dark'; } catch(e){ return 'dark'; }
    };
    // ===== قفل زوم با انگشت (شامل iOS که user-scalable=no را نادیده می‌گیرد) =====
    (function(){
        document.addEventListener('gesturestart', function(e){ e.preventDefault(); }, { passive: false });
        document.addEventListener('gesturechange', function(e){ e.preventDefault(); }, { passive: false });
        let lastTouchEnd = 0;
        document.addEventListener('touchend', function(e){
            const now = Date.now();
            if (now - lastTouchEnd <= 300) { e.preventDefault(); } // جلوگیری از double-tap zoom
            lastTouchEnd = now;
        }, { passive: false });
        document.addEventListener('touchmove', function(e){
            if (e.scale !== undefined && e.scale !== 1) { e.preventDefault(); } // pinch در iOS
        }, { passive: false });
        const st = document.createElement('style');
        st.textContent = 'html, body { touch-action: pan-x pan-y; }';
        document.head.appendChild(st);
    })();
    </script>

    <?php if (!empty($_SESSION['impersonator_admin_id'])):
        $gImpName = $gFullName;
    ?>
    <!-- نوار «در حال ورود مخفیانه به داشبورد کاربر» — فقط خودِ ادمین این را می‌بیند،
         چون شرطش impersonator_admin_id است که فقط در سشن خودِ ادمین ست می‌شود؛
         کاربر واقعی هیچ‌وقت این کد را با این شرط true اجرا نمی‌کند. -->
    <div id="avaImpersonateBar" style="position:fixed;top:0;inset-inline:0;z-index:200000;
        display:flex;align-items:center;justify-content:space-between;gap:10px;
        padding:calc(env(safe-area-inset-top) + 8px) 14px 8px;
        background:linear-gradient(90deg,#B45309,#D97706);color:#fff;font-size:.72rem;font-weight:700;
        box-shadow:0 4px 14px rgba(0,0,0,.35);">
        <span><i class="fas fa-user-secret"></i> در حال مشاهده‌ی داشبورد <?php echo htmlspecialchars($gImpName); ?> (به‌عنوان ادمین)</span>
        <button onclick="avaImpersonateStop()" style="background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.4);color:#fff;
            border-radius:8px;padding:6px 12px;font-size:.68rem;font-weight:800;cursor:pointer;font-family:inherit;white-space:nowrap;">
            <i class="fas fa-arrow-right-from-bracket"></i> بازگشت به پنل ادمین
        </button>
    </div>
    <script>
    function avaImpersonateStop(){
        fetch('/ledor/api/admin_impersonate.php?action=stop', { method: 'POST' })
            .then(function(r){ return r.json(); })
            .then(function(d){ window.location.href = '/ledor/' + (d.redirect || 'admin_panel.php'); })
            .catch(function(){ window.location.href = '/ledor/admin_panel.php'; });
    }
    // فاصله‌ی بالای صفحه به‌اندازه‌ی ارتفاع همین نوار زیاد شود تا چیزی زیرش قایم نشود
    document.addEventListener('DOMContentLoaded', function(){
        var bar = document.getElementById('avaImpersonateBar');
        if (bar) document.body.style.paddingTop = bar.offsetHeight + 'px';
    });
    </script>
    <?php endif; ?>

    <!-- ===== سیستم مدیریت آپدیت نسخه (مودال اجباری) ===== -->
    <!-- این اسکریپت قبلاً در هیچ یک از صفحات لایو لود نمی‌شد، به همین
         دلیل مودال آپدیت اجباری هیچ‌وقت اجرا نمی‌شد و کاربران فقط یک
         بنر قدیمی و خراب می‌دیدند که با کلیک روی دکمه‌اش صرفاً صفحه
         را رفرش می‌کرد و نسخه را در دیتابیس ذخیره نمی‌کرد. با قرار
         دادن include اینجا (به‌جای تکرار در هر صفحه)، تمام صفحاتی که
         renderFooterMenu() را صدا می‌زنند این سیستم را دریافت می‌کنند. -->
    <script src="/ledor/assets/js/version-check.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/version-check.js') ?: time(); ?>"></script>
    <!-- Global Loading Overlay — تم سکه‌ی طلا / تبادل ارز / حواله -->
    <div id="globalLoadingOverlay" class="global-loading-overlay" style="display: none;">
        <div class="avl-aura"></div>
        <div class="avl-spark-field">
            <span class="avl-spark p1"></span><span class="avl-spark p2"></span><span class="avl-spark p3"></span>
            <span class="avl-spark p4"></span><span class="avl-spark p5"></span><span class="avl-spark p6"></span>
            <span class="avl-spark p7"></span><span class="avl-spark p8"></span><span class="avl-spark p9"></span>
        </div>
        <div class="avl-box">
            <!-- صحنه‌ی سکه‌ی طلایی + حواله -->
            <div class="avl-coin-stage">
                <span class="avl-orbit-ring ring-a"></span>
                <span class="avl-orbit-ring ring-b"></span>

                <!-- سکه‌ی اصلی: چرخش سه‌بعدی بین نمادهای ارز -->
                <div class="avl-coin-main">
                    <div class="avl-coin-face f1">﷼</div>
                    <div class="avl-coin-face f2">$</div>
                    <div class="avl-coin-face f3">€</div>
                    <div class="avl-coin-face f4">₺</div>
                    <span class="avl-coin-glint"></span>
                </div>

                <!-- سکه‌های کوچک مداری -->
                <div class="avl-coin-mini m1">₿</div>
                <div class="avl-coin-mini m2">£</div>
                <div class="avl-coin-mini m3">₽</div>

                <!-- فلش‌های حواله/تبادل -->
                <div class="avl-swap">
                    <i class="fas fa-right-left"></i>
                </div>
            </div>

            <div class="avl-title" id="loadingMessage">در حال بارگذاری</div>

            <!-- نوار پیشرفت -->
            <div class="loading-progress"><div class="loading-progress-bar" id="loadingProgressBar"></div></div>

            <div class="avl-tip" id="loadingTips">
                <i class="fas fa-lightbulb"></i>
                <span id="tipMessage">با Ava Pay سریع و امن انتقال وجه انجام دهید</span>
            </div>
            <div id="currencyFluctuation" style="display:none"></div>
        </div>
    </div>

    <!-- ===== استوری تبلیغاتی (مثل اینستاگرام) ===== -->
    <button id="avaStoryBubble" class="ava-story-bubble" style="display:none;" onclick="avaStoryOpen()" aria-label="مشاهده استوری">
        <span class="ava-story-ring"></span>
        <img id="avaStoryBubbleThumb" src="" alt="">
        <i class="fas fa-bullhorn" id="avaStoryBubbleIcon"></i>
    </button>

    <div id="avaStoryViewer" class="ava-story-viewer">
        <div class="ava-story-bars" id="avaStoryBars"></div>
        <div class="ava-story-head">
            <span class="ava-story-head-label"><i class="fas fa-bullhorn"></i> تبلیغات AvaPay</span>
            <button type="button" class="ava-story-close" onclick="avaStoryClose()"><i class="fas fa-times"></i></button>
        </div>
        <div class="ava-story-media" id="avaStoryMedia"></div>
        <div class="ava-story-tap ava-story-tap-l" onclick="avaStoryPrev()"></div>
        <div class="ava-story-tap ava-story-tap-r" onclick="avaStoryNext()"></div>
        <div class="ava-story-foot" id="avaStoryFoot" style="display:none;">
            <a id="avaStoryLink" href="#" target="_blank" rel="noopener" class="ava-story-link-btn"><i class="fas fa-arrow-up-left-from-circle"></i> <span id="avaStoryLinkText">مشاهده</span></a>
        </div>
    </div>

    <!-- ===== لایت‌باکس نمایش فوری تصویر فیش (از کلیک روی نوتیفیکیشن) ===== -->
    <div id="avaReceiptLightbox" class="ava-rcpt-lb" onclick="if(event.target===this) avaCloseReceiptLightbox()">
        <div class="ava-rcpt-lb-box">
            <button type="button" class="ava-rcpt-lb-close" onclick="avaCloseReceiptLightbox()"><i class="fas fa-times"></i></button>
            <div class="ava-rcpt-lb-imgwrap">
                <div class="ava-rcpt-lb-loading"><div class="loading-spinner"></div></div>
                <img id="avaReceiptLightboxImg" src="" alt="فیش پرداخت" style="display:none;">
            </div>
            <div id="avaReceiptLightboxThumbs" class="ava-rcpt-lb-thumbs"></div>
            <a id="avaReceiptLightboxDownload" href="#" download class="ava-rcpt-lb-dl"><i class="fas fa-download"></i> دانلود تصویر</a>
        </div>
    </div>

    <!-- ===== Currency Rate Section — بومی، بدون iframe ===== -->
    <div id="currencyRateSection" style="display: none;">
        <div class="currency-rate-header">
            <button class="back-button" onclick="hideCurrencyRate()">
                <i class="fas fa-arrow-left"></i>
            </button>
            <div class="currency-rate-title">
                <i class="fas fa-chart-line"></i> Ava Pay
            </div>
            <button class="refresh-btn" onclick="refreshCurrencyRates()">
                <i class="fas fa-sync-alt" id="mrRefreshIcon"></i>
            </button>
        </div>

        <div class="mr-wrap" dir="rtl">
            <!-- واچ‌لیست کاربر -->
            <div class="mr-watch-box" id="mrWatchBox" style="display:none;">
                <div class="mr-watch-head">
                    <span><i class="fas fa-star" style="color:#F5B301;"></i> واچ‌لیست من</span>
                    <span class="mr-updated" id="mrUpdated"></span>
                </div>
                <div class="mr-watch-list" id="mrWatchList"></div>
            </div>

            <!-- تب دسته‌ها -->
            <div class="mr-tabs">
                <button class="mr-tab active" data-cat="currency" onclick="mrTab('currency', this)"><i class="fas fa-money-bill-wave"></i> ارز</button>
                <button class="mr-tab" data-cat="gold" onclick="mrTab('gold', this)"><i class="fas fa-coins"></i> طلا و سکه</button>
                <button class="mr-tab" data-cat="crypto" onclick="mrTab('crypto', this)"><i class="fab fa-bitcoin"></i> ارز دیجیتال</button>
            </div>

            <!-- جستجو -->
            <div class="mr-search">
                <i class="fas fa-magnifying-glass"></i>
                <input type="text" id="mrSearch" placeholder="جستجوی نماد یا نام…" oninput="mrRender()">
            </div>

            <div class="mr-hint"><i class="fas fa-star"></i> روی ستاره‌ی هر ردیف بزنید تا به واچ‌لیست اضافه شود</div>

            <div class="mr-thead">
                <span class="mr-th-name">نماد</span>
                <span class="mr-th-num">خرید</span>
                <span class="mr-th-num">فروش</span>
            </div>

            <div class="mr-list" id="mrList">
                <div class="mr-loading"><i class="fas fa-circle-notch fa-spin"></i> در حال دریافت نرخ‌ها…</div>
            </div>
        </div>
    </div>

    <!-- Bottom Navigation -->
    <nav class="bottom-nav">
        <button class="nav-item <?php echo $activePage === 'arad' ? 'active' : ''; ?>"
                data-page="arad"
                onclick="navigateWithLoading('arad')">
            <i class="fas fa-exchange-alt"></i>
            <span>تبادل ارزی</span>
        </button>

        <button class="nav-item <?php echo $activePage === 'dashboard' ? 'active' : ''; ?>"
                data-page="dashboard"
                onclick="navigateWithLoading('dashboard')">
            <i class="fas fa-home"></i>
            <span>Home</span>
        </button>

        <!-- دکمه اسکن مرکزی (دایره) -->
        <button class="nav-scan-fab" data-page="scan" onclick="openScanner()" aria-label="Scan">
            <i class="fas fa-qrcode"></i>
        </button>

        <button class="nav-item <?php echo $activePage === 'currency-rate' ? 'active' : ''; ?>"
                data-page="currency-rate"
                onclick="showCurrencyRate(event)">
            <i class="fas fa-chart-line"></i>
            <span>Currency</span>
        </button>

        <button class="nav-item <?php echo $activePage === 'profile' ? 'active' : ''; ?>"
                data-page="profile"
                onclick="return avaProfileNav(event);">
            <i class="fas fa-user"></i>
            <span>Profile</span>
        </button>
    </nav>

    <!-- ================================================================
         (آپدیت) کشوی پروفایل سراسری + مدال اعلان‌ها + مدال ویرایش/احراز هویت
         در همه‌ی صفحات کار می‌کند و با تم روز/شب هم‌خوانی دارد.
         ================================================================ -->
    <style>
    /* متغیرهای تم (اگر صفحه‌ای dashboard-ava.css را لود نکرده باشد) */
    :root{
        --ava-bg:#0A0520; --ava-bg2:#0E0828; --ava-card:#151034; --ava-card2:#1B1440;
        --ava-line:rgba(255,255,255,.07); --ava-line2:rgba(255,255,255,.12);
        --ava-txt:#EDEAFB; --ava-mut:#9C93C8; --ava-pur:#7C3AED; --ava-pur2:#A855F7;
        --ava-grn:#22C55E; --ava-red:#FF5A6E;
    }
    html[data-theme="light"]{
        --ava-bg:#F4F2FB; --ava-bg2:#FFFFFF; --ava-card:#FFFFFF; --ava-card2:#FAF9FF;
        --ava-line:rgba(16,8,40,.08); --ava-line2:rgba(16,8,40,.14);
        --ava-txt:#180F35; --ava-mut:#6B6390;
    }

    /* ===== مدال سراسری (هم‌خوان با تم روز/شب) ===== */
    .avag-modal{
        position:fixed; inset:0; z-index:99500; display:none; flex-direction:column;
        background:var(--ava-bg); direction:rtl;
    }
    .avag-modal.open{ display:flex; }
    .avag-modal-top{
        display:flex; align-items:center; justify-content:space-between; gap:10px;
        padding:calc(env(safe-area-inset-top) + 14px) 16px 14px;
        color:var(--ava-txt); font-weight:800; font-size:.9rem;
        border-bottom:1px solid var(--ava-line); background:var(--ava-bg2);
    }
    .avag-modal-top i{ color:var(--ava-pur2); }
    .avag-x{
        display:inline-flex; align-items:center; gap:6px; background:var(--ava-card2);
        border:1px solid var(--ava-line); color:var(--ava-txt); border-radius:12px;
        padding:8px 12px; font-size:.72rem; font-weight:700; cursor:pointer; font-family:inherit;
    }
    .avag-modal-body{ flex:1; overflow-y:auto; padding:14px; padding-bottom:calc(90px + env(safe-area-inset-bottom)); }

    /* لیست اعلان‌ها */
    .avag-nt-tools{ display:flex; gap:8px; margin-bottom:12px; }
    .avag-nt-tools button{
        flex:1; padding:10px; border-radius:12px; cursor:pointer; font-family:inherit; font-weight:700; font-size:.72rem;
        background:var(--ava-card); border:1px solid var(--ava-line); color:var(--ava-txt);
    }
    .avag-nt-tools button.pri{ background:linear-gradient(135deg,var(--ava-pur),var(--ava-pur2)); color:#fff; border-color:transparent; }
    .avag-nt-item{
        position:relative; background:var(--ava-card); border:1px solid var(--ava-line);
        border-radius:16px; padding:13px 14px; margin-bottom:9px; overflow:hidden;
        animation:avagUp .35s ease both;
    }
    .avag-nt-item.unread{ border-color:rgba(168,85,247,.45); }
    .avag-nt-item.unread::before{
        content:''; position:absolute; top:0; right:0; bottom:0; width:4px;
        background:linear-gradient(180deg,var(--ava-pur2),#38BDF8);
    }
    @keyframes avagUp{ from{ opacity:0; transform:translateY(10px); } to{ opacity:1; transform:none; } }
    .avag-nt-t{ font-size:.82rem; font-weight:800; color:var(--ava-txt); display:flex; align-items:center; gap:7px; }
    .avag-nt-t .dot{ width:7px; height:7px; border-radius:50%; background:var(--ava-pur2); }
    .avag-nt-m{ font-size:.74rem; color:var(--ava-mut); margin-top:5px; line-height:1.8; }
    .avag-nt-d{ font-size:.62rem; color:var(--ava-mut); opacity:.75; margin-top:7px; }
    .avag-empty{ text-align:center; padding:40px 16px; color:var(--ava-mut); font-size:.8rem; }
    .avag-empty i{ display:block; font-size:2rem; margin-bottom:10px; color:var(--ava-pur2); opacity:.6; }
    </style>

    <!-- مدال اعلان‌ها (در همه‌ی صفحات) -->
    <div class="avag-modal" id="avagNotifModal">
        <div class="avag-modal-top">
            <span><i class="fas fa-bell"></i> اعلان‌ها</span>
            <button class="avag-x" onclick="avaCloseGModal('avagNotifModal')"><i class="fas fa-times"></i> بستن</button>
        </div>
        <div class="avag-modal-body">
            <div class="avag-nt-tools">
                <button class="pri" onclick="avaNotifMarkAll()"><i class="fas fa-check-double"></i> خواندن همه</button>
                <button onclick="avaNotifLoad()"><i class="fas fa-sync-alt"></i> بروزرسانی</button>
            </div>
            <div id="avagNotifList">
                <div class="avag-empty"><i class="fas fa-circle-notch fa-spin"></i> در حال دریافت اعلان‌ها…</div>
            </div>
        </div>
    </div>

<?php if (!$avaIsDashPage): ?>
    <!-- مدال ویرایش پروفایل / احراز هویت (profile.php در iframe) -->
    <div class="avag-modal" id="avaProfileModal">
        <div class="avag-modal-top">
            <span id="avaProfileModalTitle"><i class="fas fa-user-edit"></i> ویرایش پروفایل</span>
            <button class="avag-x" onclick="avaCloseGModal('avaProfileModal')"><i class="fas fa-times"></i> بستن</button>
        </div>
        <iframe id="avaProfileFrame" src="" style="flex:1;width:100%;border:0;background:var(--ava-bg);"></iframe>
    </div>
<?php endif; ?>

    <script>
    /* ===== کشوی پروفایل + مدال‌های سراسری ===== */
    window.avaOpenGModal = function(id){
        var el = document.getElementById(id);
        if (el){ el.classList.add('open'); document.body.style.overflow = 'hidden'; }
    };
    window.avaCloseGModal = function(id){
        var el = document.getElementById(id);
        if (el){ el.classList.remove('open'); document.body.style.overflow = ''; }
        if (id === 'avaProfileModal'){ var f = document.getElementById('avaProfileFrame'); if (f) f.src = 'about:blank'; }
    };

    if (typeof window.avaOpenProfile !== 'function') {
        window.avaOpenProfile = function(){
            var d = document.getElementById('avaProfileDrawer');
            if (d){ d.classList.add('open'); document.body.style.overflow = 'hidden'; }
            avaNotifRefreshBadge();
        };
    }
    if (typeof window.avaCloseProfile !== 'function') {
        window.avaCloseProfile = function(){
            var d = document.getElementById('avaProfileDrawer');
            if (d){ d.classList.remove('open'); document.body.style.overflow = ''; }
        };
    }
    if (typeof window.avaOpenProfileModal !== 'function') {
        window.avaOpenProfileModal = function(section){
            section = (section === 'kyc' || section === 'notifications') ? section : 'edit';
            var f = document.getElementById('avaProfileFrame');
            var t = document.getElementById('avaProfileModalTitle');
            if (t) t.innerHTML = (section === 'kyc')
                ? '<i class="fas fa-id-card"></i> احراز هویت'
                : (section === 'notifications')
                ? '<i class="fas fa-bell"></i> تنظیمات اعلان‌ها'
                : '<i class="fas fa-user-edit"></i> ویرایش پروفایل';
            if (f) f.src = '/ledor/profile.php?embed=1&section=' + section;
            if (typeof avaCloseProfile === 'function') avaCloseProfile();
            avaOpenGModal('avaProfileModal');
        };
    }
    if (typeof window.avaLogout !== 'function') {
        window.avaLogout = async function(){
            try {
                var token = '';
                try { token = (document.cookie.match(/auth_token=([^;]+)/) || [])[1] || ''; } catch(e){}
                await fetch('/ledor/api/logout.php?t=' + Date.now() + (token ? ('&token=' + encodeURIComponent(token)) : ''), { method:'GET', credentials:'include', cache:'no-store' });
            } catch(e){}
            try { localStorage.clear(); sessionStorage.clear(); } catch(e){}
            window.location.replace('/ledor/login.php?logout=success&t=' + Date.now());
        };
    }

    /* ===== اعلان‌ها ===== */
    window.avaOpenNotifModal = function(){
        if (typeof avaCloseProfile === 'function') avaCloseProfile();
        avaOpenGModal('avagNotifModal');
        avaNotifLoad();
    };
    window.avaNotifEsc = function(s){
        return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    };
    window.avaNotifLoad = async function(){
        var box = document.getElementById('avagNotifList');
        if (!box) return;
        box.innerHTML = '<div class="avag-empty"><i class="fas fa-circle-notch fa-spin"></i> در حال دریافت اعلان‌ها…</div>';
        try {
            var r = await fetch('/ledor/api/notification_api.php?action=get_all&page=1&t=' + Date.now(), { credentials:'include', cache:'no-store' });
            var d = await r.json();
            var list = (d && d.notifications) ? d.notifications : [];
            if (!list.length){
                box.innerHTML = '<div class="avag-empty"><i class="fas fa-bell-slash"></i> اعلانی وجود ندارد</div>';
                return;
            }
            box.innerHTML = list.map(function(n, i){
                var unread = (String(n.is_read) === '0' || n.is_read === false);
                return '<div class="avag-nt-item' + (unread ? ' unread' : '') + '" style="animation-delay:' + (i * 0.03) + 's">'
                     + '<div class="avag-nt-t">' + (unread ? '<span class="dot"></span>' : '') + avaNotifEsc(n.title || 'اعلان') + '</div>'
                     + '<div class="avag-nt-m">' + avaNotifEsc(n.message || '') + '</div>'
                     + '<div class="avag-nt-d"><i class="fas fa-clock"></i> ' + avaNotifEsc(n.time_ago || n.created_at || '') + '</div>'
                     + '</div>';
            }).join('');
        } catch(e){
            box.innerHTML = '<div class="avag-empty"><i class="fas fa-wifi"></i> خطا در ارتباط با سرور</div>';
        }
    };
    window.avaNotifMarkAll = async function(){
        try {
            await fetch('/ledor/api/notification_api.php?action=mark_all_read', { method:'POST', credentials:'include' });
        } catch(e){}
        avaNotifLoad();
        avaNotifRefreshBadge();
    };
    window.avaNotifRefreshBadge = async function(){
        var b = document.getElementById('avagNotifBadge');
        try {
            var r = await fetch('/ledor/api/notification_api.php?action=unread_count&t=' + Date.now(), { credentials:'include', cache:'no-store' });
            var d = await r.json();
            var c = parseInt((d && (d.unread_count || d.count || d.unread)) || 0, 10);
            if (b) {
                if (c > 0){ b.style.display = 'inline-flex'; b.innerHTML = '<i class="fas fa-circle"></i> ' + c + ' جدید'; }
                else { b.style.display = 'none'; }
            }
            // Badging API: هر بار که اپ باز/فوکوس می‌شود، عدد روی آیکون هم با
            // همین شمارش واقعی هماهنگ می‌شود — مثلاً اگر کاربر نوتیف‌ها را از
            // داخل اپ خوانده باشد، همین‌جا badge پاک می‌شود، نه فقط منتظر push بعدی.
            if ('setAppBadge' in navigator) {
                try { c > 0 ? await navigator.setAppBadge(c) : await navigator.clearAppBadge(); } catch(e){}
            }
        } catch(e){ if (b) b.style.display = 'none'; }
    };
    document.addEventListener('DOMContentLoaded', function(){
        avaNotifRefreshBadge();
        // آیتم‌هایی که هندلرشان در این صفحه موجود نیست را پنهان کن
        document.querySelectorAll('.ava-prof-item[data-need]').forEach(function(el){
            if (typeof window[el.dataset.need] !== 'function') el.style.display = 'none';
        });
    });
    </script>

    <!-- ===== QR / Scanner + Send/Receive Overlay ===== -->
    <div id="avaScanOverlay" class="ava-scan-overlay" style="display:none;">
        <div class="ava-scan-header">
            <button class="ava-scan-close" onclick="closeScanner()"><i class="fas fa-times"></i></button>
            <div class="ava-scan-title" id="avaScanHeaderTitle"><i class="fas fa-qrcode"></i> Pay with QR</div>
            <button class="ava-scan-flip" id="avaScanFlip" onclick="flipScanCamera()" title="Switch camera" style="display:none;"><i class="fas fa-camera-rotate"></i></button>
        </div>

        <!-- صفحه انتخاب: ارسال یا دریافت -->
        <div id="avaScanChooser" class="ava-scan-chooser">
            <div class="ava-chooser-hero">
                <div class="ava-chooser-hero-icon"><i class="fas fa-qrcode"></i></div>
                <div class="ava-chooser-hero-title">Quick QR Payment</div>
                <div class="ava-chooser-hero-sub">Choose an option</div>
            </div>
            <div class="ava-chooser-cards">
                <button class="ava-chooser-card send" onclick="scanChoose('send')">
                    <div class="ava-chooser-ic"><i class="fas fa-paper-plane"></i></div>
                    <div class="ava-chooser-txt">
                        <div class="t">Send Money</div>
                        <div class="s">Scan recipient QR</div>
                    </div>
                    <i class="fas fa-chevron-left ava-chooser-arrow"></i>
                </button>
                <button class="ava-chooser-card receive" onclick="scanChoose('receive')">
                    <div class="ava-chooser-ic"><i class="fas fa-qrcode"></i></div>
                    <div class="ava-chooser-txt">
                        <div class="t">Receive Money</div>
                        <div class="s">Show your personal QR</div>
                    </div>
                    <i class="fas fa-chevron-left ava-chooser-arrow"></i>
                </button>
            </div>
        </div>

        <!-- صفحه دوربین (ارسال) -->
        <div id="avaScanCamera" class="ava-scan-camera" style="display:none;">
            <div class="ava-scan-stage">
                <video id="avaScanVideo" playsinline muted autoplay></video>
                <div class="ava-scan-frame"><span></span><span></span><span></span><span></span><div class="ava-scan-laser"></div></div>
            </div>

            <!-- حالت درخواست دسترسی / خطا -->
            <div id="avaScanPermit" class="ava-scan-permit" style="display:none;">
                <div class="ava-scan-permit-icon"><i class="fas fa-camera"></i></div>
                <div class="ava-scan-permit-title">Camera Access</div>
                <div class="ava-scan-permit-text" id="avaScanPermitText">
                    Please allow camera access to scan the QR code.
                </div>
                <button class="ava-scan-permit-btn" id="avaScanPermitBtn" onclick="requestScanCamera()">
                    <i class="fas fa-unlock"></i> Allow Access
                </button>
                <button class="ava-scan-permit-btn" id="avaScanFileBtn" style="display:none;margin-top:12px;background:linear-gradient(135deg,#6C40C5,#a855f7);" onclick="document.getElementById('avaScanFileInput').click()">
                    <i class="fas fa-image"></i> Pick QR image from gallery
                </button>
                <input type="file" id="avaScanFileInput" accept="image/*" style="display:none;" onchange="handleScanFile(this)">
                <div class="ava-scan-permit-hint" id="avaScanPermitHint" style="display:none;"></div>
            </div>

            <div class="ava-scan-foot" id="avaScanFoot">Point the camera at the QR code</div>
        </div>

        <!-- صفحه دریافت (نمایش QR اختصاصی کاربر) -->
        <div id="avaScanReceive" class="ava-scan-receive" style="display:none;">
            <div class="ava-receive-card">
                <div class="ava-receive-name" id="avaReceiveName">—</div>
                <div class="ava-receive-qr" id="avaReceiveQr"></div>
                <div class="ava-receive-acct-label">Account Number</div>
                <div class="ava-receive-acct" id="avaReceiveAcct">—</div>
                <button class="ava-receive-share" onclick="shareMyAccount()">
                    <i class="fas fa-share-alt"></i> Share Details
                </button>
                <div class="ava-receive-hint">Show this QR to the sender so they can pay you.</div>
            </div>
        </div>
    </div>

    <!-- Send Money via QR modal (وقتی روی صفحه‌ای غیر از داشبورد اسکن می‌شود) -->
    <div id="avaQrSendModal" class="ava-qr-send-modal" style="display:none;">
        <div class="ava-qr-send-sheet">
            <button class="ava-qr-send-close" onclick="closeQrSend()"><i class="fas fa-times"></i></button>
            <div class="ava-qr-send-title"><i class="fas fa-paper-plane"></i> Send Money</div>
            <div class="ava-qr-send-to">
                <div class="ava-qr-send-avatar" id="avaQrSendAvatar">?</div>
                <div>
                    <div class="ava-qr-send-name" id="avaQrSendName">—</div>
                    <div class="ava-qr-send-acct" id="avaQrSendAcct">—</div>
                </div>
            </div>
            <label class="ava-qr-lbl">Amount</label>
            <input type="number" id="avaQrAmount" class="ava-qr-input" placeholder="0.00" step="0.01" min="0.01">
            <label class="ava-qr-lbl">Currency</label>
            <select id="avaQrCurrency" class="ava-qr-input">
                <option value="USD">US Dollar (USD)</option>
                <option value="EUR">Euro (EUR)</option>
                <option value="USDT">Tether (USDT)</option>
                <option value="IRR">Iranian Rial (IRR)</option>
            </select>
            <label class="ava-qr-lbl">Description (optional)</label>
            <input type="text" id="avaQrDesc" class="ava-qr-input" placeholder="What for...">
            <button class="ava-qr-submit" id="avaQrSubmit" onclick="submitQrSend()">
                <i class="fas fa-paper-plane"></i> Send Money
            </button>
            <div class="ava-qr-feedback" id="avaQrFeedback"></div>
        </div>
    </div>

    <script>
    // اطلاعات کاربر جاری برای دریافت پول (QR اختصاصی)
    window.AVA_ME = <?php echo json_encode($avaMeArr, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    </script>

    <style>
    /* فضای پایین صفحه تا محتوا زیر منوی ثابت پنهان نشود (همه‌ی صفحات) */
    body { padding-bottom: calc(88px + env(safe-area-inset-bottom)) !important; }
    /* اصلاح منو پایین - از چپ به راست + فضای دکمه مرکزی */
    .bottom-nav {
        display: flex;
        flex-direction: row;
        justify-content: space-around;
        align-items: center;
        direction: ltr !important;
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        background: rgba(26, 11, 46, 0.95);
        backdrop-filter: blur(20px);
        border-top: 1px solid rgba(255, 255, 255, 0.1);
        padding: 10px 0;
        padding-bottom: max(10px, env(safe-area-inset-bottom));
        z-index: 100;
    }

    .nav-item {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        background: none;
        border: none;
        color: rgba(255, 255, 255, 0.5);
        font-size: 0.7rem;
        transition: all 0.3s ease;
        padding: 5px 10px;
        border-radius: 10px;
        cursor: pointer;
        direction: ltr !important;
        flex: 1;
        min-width: 0;
    }

    .nav-item i { font-size: 1.2rem; margin-bottom: 4px; }
    .nav-item span { direction: rtl; font-size: 0.68rem; white-space: nowrap; }
    .nav-item.active { color: var(--accent-purple); background: rgba(108, 64, 197, 0.1); }
    .nav-item:hover { color: var(--text-light); background: rgba(255, 255, 255, 0.05); }

    /* ===== دکمه اسکن مرکزی دایره‌ای ===== */
    .nav-scan-fab {
        flex: 0 0 auto;
        width: 62px;
        height: 62px;
        border-radius: 50%;
        border: 4px solid var(--primary-dark, #1a0b2e);
        background: linear-gradient(135deg, #22d3a0, #38bdf8);
        color: #fff;
        font-size: 1.5rem;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        margin-top: -34px;            /* بیرون‌زدگی از نوار برای حالت دایره‌ای وسط */
        box-shadow: 0 8px 22px rgba(56,189,248,0.45), 0 0 0 6px rgba(56,189,248,0.08);
        transition: transform .2s ease, box-shadow .2s ease;
        position: relative;
    }
    .nav-scan-fab:hover { transform: translateY(-2px) scale(1.04); }
    .nav-scan-fab:active { transform: scale(0.96); }
    .nav-scan-fab::after {
        content: 'Scan';
        position: absolute;
        bottom: -16px;
        left: 50%;
        transform: translateX(-50%);
        font-size: 0.6rem;
        color: rgba(255,255,255,0.6);
        font-weight: 700;
        letter-spacing: .04em;
    }

    /* ===== Scanner Overlay ===== */
    .ava-scan-overlay {
        position: fixed; inset: 0; z-index: 3000;
        background: #05030a;
        display: flex; flex-direction: column;
    }
    .ava-scan-header {
        display: flex; align-items: center; justify-content: space-between;
        padding: 14px 16px; padding-top: max(14px, env(safe-area-inset-top));
        color: #fff; direction: rtl;
    }
    .ava-scan-title { font-size: 1rem; font-weight: 700; display: flex; align-items: center; gap: 8px; }
    .ava-scan-title i { color: #38bdf8; }
    .ava-scan-close, .ava-scan-flip {
        width: 40px; height: 40px; border-radius: 50%;
        background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.12);
        color: #fff; font-size: 1rem; cursor: pointer; display: flex; align-items: center; justify-content: center;
    }
    .ava-scan-stage {
        flex: 1; position: relative; overflow: hidden;
        display: flex; align-items: center; justify-content: center; background: #000;
    }
    #avaScanVideo { width: 100%; height: 100%; object-fit: cover; }
    .ava-scan-frame {
        position: absolute; width: 62vw; max-width: 300px; aspect-ratio: 1; pointer-events: none;
    }
    .ava-scan-frame span {
        position: absolute; width: 34px; height: 34px; border: 3px solid #38bdf8;
    }
    .ava-scan-frame span:nth-child(1){ top:0; left:0; border-right:none; border-bottom:none; border-radius: 10px 0 0 0; }
    .ava-scan-frame span:nth-child(2){ top:0; right:0; border-left:none; border-bottom:none; border-radius: 0 10px 0 0; }
    .ava-scan-frame span:nth-child(3){ bottom:0; left:0; border-right:none; border-top:none; border-radius: 0 0 0 10px; }
    .ava-scan-frame span:nth-child(4){ bottom:0; right:0; border-left:none; border-top:none; border-radius: 0 0 10px 0; }
    .ava-scan-laser {
        position: absolute; left: 6%; right: 6%; height: 2px;
        background: linear-gradient(90deg, transparent, #38bdf8, transparent);
        box-shadow: 0 0 12px #38bdf8; top: 0; animation: avaLaser 2.2s ease-in-out infinite;
    }
    @keyframes avaLaser { 0%,100%{ top: 6%; } 50%{ top: 92%; } }
    .ava-scan-foot {
        text-align: center; color: rgba(255,255,255,0.7); font-size: .78rem;
        padding: 16px; padding-bottom: max(16px, env(safe-area-inset-bottom)); direction: rtl;
    }

    /* حالت درخواست دسترسی / خطا */
    .ava-scan-permit {
        position: absolute; inset: 0; z-index: 5;
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        text-align: center; padding: 28px; direction: rtl;
        background: linear-gradient(160deg, #1a0b2e, #05030a);
    }
    .ava-scan-permit-icon {
        width: 84px; height: 84px; border-radius: 50%;
        background: rgba(56,189,248,0.12); border: 1px solid rgba(56,189,248,0.35);
        display: flex; align-items: center; justify-content: center;
        font-size: 2rem; color: #38bdf8; margin-bottom: 18px;
    }
    .ava-scan-permit-title { color: #fff; font-size: 1.15rem; font-weight: 800; margin-bottom: 10px; }
    .ava-scan-permit-text { color: rgba(255,255,255,0.65); font-size: .85rem; line-height: 1.9; max-width: 320px; margin-bottom: 22px; }
    .ava-scan-permit-btn {
        background: linear-gradient(135deg, #22d3a0, #38bdf8); color: #fff; border: none;
        padding: 13px 30px; border-radius: 12px; font-size: .95rem; font-weight: 700; cursor: pointer;
        display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 8px 20px rgba(56,189,248,0.35);
    }
    .ava-scan-permit-btn:active { transform: scale(0.97); }
    .ava-scan-permit-hint {
        margin-top: 18px; color: #ffb4b4; font-size: .76rem; line-height: 1.9;
        background: rgba(255,59,48,0.08); border: 1px solid rgba(255,59,48,0.25);
        border-radius: 12px; padding: 12px 14px; max-width: 340px;
    }

    /* ===== Chooser (Send / Receive) ===== */
    .ava-scan-chooser { flex: 1; display: flex; flex-direction: column; justify-content: center; padding: 24px; direction: rtl; }
    .ava-chooser-hero { text-align: center; margin-bottom: 34px; }
    .ava-chooser-hero-icon {
        width: 90px; height: 90px; margin: 0 auto 18px; border-radius: 26px;
        background: linear-gradient(135deg, #6C40C5, #FF4D8D);
        display: flex; align-items: center; justify-content: center;
        font-size: 2.4rem; color: #fff; box-shadow: 0 12px 34px rgba(108,64,197,0.5);
    }
    .ava-chooser-hero-title { color: #fff; font-size: 1.3rem; font-weight: 800; margin-bottom: 6px; }
    .ava-chooser-hero-sub { color: rgba(255,255,255,0.5); font-size: .85rem; }
    .ava-chooser-cards { display: flex; flex-direction: column; gap: 14px; max-width: 420px; margin: 0 auto; width: 100%; }
    .ava-chooser-card {
        display: flex; align-items: center; gap: 14px; padding: 18px;
        border-radius: 18px; border: 1px solid rgba(255,255,255,0.10);
        background: rgba(255,255,255,0.04); cursor: pointer; text-align: right;
        transition: transform .15s, border-color .2s, background .2s;
    }
    .ava-chooser-card:active { transform: scale(0.98); }
    .ava-chooser-card.send:hover    { border-color: rgba(108,64,197,0.6);  background: rgba(108,64,197,0.10); }
    .ava-chooser-card.receive:hover { border-color: rgba(34,211,160,0.6);   background: rgba(34,211,160,0.10); }
    .ava-chooser-ic {
        width: 54px; height: 54px; border-radius: 16px; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center; font-size: 1.4rem; color: #fff;
    }
    .ava-chooser-card.send .ava-chooser-ic    { background: linear-gradient(135deg, #6C40C5, #8B5CF6); }
    .ava-chooser-card.receive .ava-chooser-ic { background: linear-gradient(135deg, #22d3a0, #38bdf8); }

    .ava-chooser-txt { flex: 1; }
    .ava-chooser-txt .t { color: #fff; font-size: 1.05rem; font-weight: 700; }
    .ava-chooser-txt .s { color: rgba(255,255,255,0.5); font-size: .78rem; margin-top: 3px; }
    .ava-chooser-arrow { color: rgba(255,255,255,0.3); font-size: .9rem; }

    .ava-scan-camera { flex: 1; display: flex; flex-direction: column; }

    /* ===== Receive (QR اختصاصی) ===== */
    .ava-scan-receive { flex: 1; display: flex; align-items: center; justify-content: center; padding: 24px; direction: rtl; }
    .ava-receive-card {
        background: #fff; border-radius: 26px; padding: 26px 24px; text-align: center;
        max-width: 340px; width: 100%; box-shadow: 0 20px 60px rgba(0,0,0,0.5);
    }
    .ava-receive-name { font-size: 1.2rem; font-weight: 800; color: #1a0b2e; margin-bottom: 18px; }
    .ava-receive-qr {
        width: 210px; height: 210px; margin: 0 auto 18px; display: flex;
        align-items: center; justify-content: center; background: #fff; border-radius: 14px;
    }
    .ava-receive-qr img, .ava-receive-qr canvas { max-width: 100%; max-height: 100%; }
    .ava-receive-acct-label { font-size: .68rem; color: #8a8a9a; text-transform: uppercase; letter-spacing: .05em; }
    .ava-receive-acct { font-size: 1.15rem; font-weight: 800; color: #6C40C5; letter-spacing: .05em; margin-top: 4px; margin-bottom: 18px; }
    .ava-receive-share {
        width: 100%; padding: 13px; border: none; border-radius: 14px; cursor: pointer;
        background: linear-gradient(135deg, #6C40C5, #FF4D8D); color: #fff; font-size: .95rem; font-weight: 700;
        display: flex; align-items: center; justify-content: center; gap: 8px;
    }
    .ava-receive-hint { font-size: .72rem; color: #8a8a9a; margin-top: 14px; line-height: 1.7; }

    /* ===== QR Send Modal ===== */
    .ava-qr-send-modal {
        position: fixed; inset: 0; z-index: 3200; background: rgba(0,0,0,0.7); backdrop-filter: blur(6px);
        display: flex; align-items: flex-end; justify-content: center; direction: rtl;
    }
    .ava-qr-send-sheet {
        background: linear-gradient(180deg, #1e0f38, #150a28); width: 100%; max-width: 460px;
        border-radius: 24px 24px 0 0; padding: 24px 20px calc(24px + env(safe-area-inset-bottom));
        border: 1px solid rgba(255,255,255,0.08); position: relative; max-height: 90vh; overflow-y: auto;
    }
    .ava-qr-send-close {
        position: absolute; top: 16px; left: 16px; width: 36px; height: 36px; border-radius: 50%;
        background: rgba(255,255,255,0.08); border: none; color: #fff; font-size: 1rem; cursor: pointer;
    }
    .ava-qr-send-title { color: #fff; font-size: 1.1rem; font-weight: 800; margin-bottom: 18px; display: flex; align-items: center; gap: 8px; }
    .ava-qr-send-title i { color: #FF4D8D; }
    .ava-qr-send-to {
        display: flex; align-items: center; gap: 12px; padding: 14px;
        background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.08);
        border-radius: 16px; margin-bottom: 20px;
    }
    .ava-qr-send-avatar {
        width: 46px; height: 46px; border-radius: 50%; flex-shrink: 0;
        background: linear-gradient(135deg, #6C40C5, #FF4D8D); color: #fff;
        display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 1.1rem;
    }
    .ava-qr-send-name { color: #fff; font-weight: 700; font-size: 1rem; }
    .ava-qr-send-acct { color: rgba(255,255,255,0.5); font-size: .78rem; margin-top: 2px; }
    .ava-qr-lbl { display: block; color: rgba(255,255,255,0.6); font-size: .78rem; margin-bottom: 6px; margin-top: 12px; }
    .ava-qr-input {
        width: 100%; padding: 13px 14px; border-radius: 12px; font-size: .95rem;
        background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.12); color: #fff;
        outline: none; box-sizing: border-box;
    }
    .ava-qr-input:focus { border-color: #6C40C5; }
    .ava-qr-submit {
        width: 100%; margin-top: 20px; padding: 15px; border: none; border-radius: 14px; cursor: pointer;
        background: linear-gradient(135deg, #6C40C5, #FF4D8D); color: #fff; font-size: 1rem; font-weight: 700;
        display: flex; align-items: center; justify-content: center; gap: 8px;
    }
    .ava-qr-submit:disabled { opacity: .7; }
    .ava-qr-feedback { text-align: center; font-size: .85rem; margin-top: 14px; min-height: 20px; }

    /* ===== Loader شیشه‌ای و انیمیشنی — تم سکه‌ی طلا / تبادل ارز / حواله ===== */
    .global-loading-overlay{
        position:fixed;inset:0;z-index:2000;display:flex;align-items:center;justify-content:center;
        background:radial-gradient(120% 120% at 50% 0%, #241556 0%, #150C36 45%, #0C0620 100%);
        backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);
        transition:opacity .45s ease;overflow:hidden;
    }
    /* هاله‌های رنگی پس‌زمینه (بنفش برند + گرمای طلایی) */
    .avl-aura{position:absolute;inset:0;pointer-events:none;
        background:
          radial-gradient(360px 360px at 22% 24%, rgba(168,85,247,.30), transparent 62%),
          radial-gradient(320px 320px at 78% 76%, rgba(56,189,248,.20), transparent 62%),
          radial-gradient(300px 300px at 50% 50%, rgba(251,191,36,.16), transparent 65%);
        animation:avlAura 8s ease-in-out infinite;}
    @keyframes avlAura{0%,100%{transform:scale(1);opacity:.85}50%{transform:scale(1.14);opacity:1}}

    /* ذرات طلایی شناور — به یاد سکه/جرقه‌ی طلا */
    .avl-spark-field{position:absolute;inset:0;pointer-events:none;overflow:hidden;z-index:1;}
    .avl-spark{position:absolute;bottom:-10px;width:5px;height:5px;border-radius:50%;
        background:radial-gradient(circle,#FFE9A8 0%,#FBBF24 55%,rgba(251,191,36,0) 75%);
        box-shadow:0 0 8px 1px rgba(251,191,36,.85);
        animation:avlSparkRise linear infinite;}
    .avl-spark.p1{left:8%; animation-duration:5.2s; animation-delay:0s;}
    .avl-spark.p2{left:18%;animation-duration:6.4s; animation-delay:.6s; width:3px;height:3px;}
    .avl-spark.p3{left:29%;animation-duration:4.6s; animation-delay:1.4s;}
    .avl-spark.p4{left:41%;animation-duration:7s;   animation-delay:.2s; width:4px;height:4px;}
    .avl-spark.p5{left:55%;animation-duration:5.6s; animation-delay:2s;}
    .avl-spark.p6{left:66%;animation-duration:6.8s; animation-delay:.9s; width:3px;height:3px;}
    .avl-spark.p7{left:77%;animation-duration:4.9s; animation-delay:1.7s;}
    .avl-spark.p8{left:88%;animation-duration:6.1s; animation-delay:.4s; width:4px;height:4px;}
    .avl-spark.p9{left:95%;animation-duration:5.4s; animation-delay:2.4s;}
    @keyframes avlSparkRise{
        0%{transform:translateY(0) scale(.4);opacity:0}
        12%{opacity:1}
        85%{opacity:.7}
        100%{transform:translateY(-320px) scale(1.1);opacity:0}
    }

    .avl-box{position:relative;z-index:2;text-align:center;width:90%;max-width:330px;padding:30px 24px;
        background:linear-gradient(160deg,rgba(255,255,255,.13),rgba(255,255,255,.05));
        border:1px solid rgba(255,255,255,.20);border-radius:28px;
        backdrop-filter:blur(24px) saturate(160%);-webkit-backdrop-filter:blur(24px) saturate(160%);
        box-shadow:0 24px 60px rgba(0,0,0,.55),inset 0 1px 0 rgba(255,255,255,.28);
        animation:avlIn .5s cubic-bezier(.22,1.4,.36,1);}
    @keyframes avlIn{0%{opacity:0;transform:translateY(24px) scale(.94)}100%{opacity:1;transform:none}}

    /* ===== صحنه‌ی سکه ===== */
    .avl-coin-stage{position:relative;width:150px;height:150px;margin:0 auto 20px;perspective:600px;}

    /* حلقه‌های مداری نازک دور صحنه */
    .avl-orbit-ring{position:absolute;border-radius:50%;border:1.5px dashed rgba(251,191,36,.35);}
    .avl-orbit-ring.ring-a{inset:2px;animation:avlSpin 9s linear infinite;}
    .avl-orbit-ring.ring-b{inset:16px;border-style:dashed;border-color:rgba(168,85,247,.30);animation:avlSpin 13s linear infinite reverse;}
    @keyframes avlSpin{to{transform:rotate(360deg)}}

    /* سکه‌ی طلایی اصلی — چرخش سه‌بعدی بین ۴ نمای ارز */
    .avl-coin-main{position:absolute;inset:38px;border-radius:50%;transform-style:preserve-3d;
        animation:avlCoinFlip 3.6s cubic-bezier(.45,.05,.35,1) infinite;
        box-shadow:0 0 30px rgba(251,191,36,.55), 0 10px 26px rgba(0,0,0,.4);}
    @keyframes avlCoinFlip{
        0%,18%{transform:rotateY(0deg)}
        25%,43%{transform:rotateY(90deg)}
        50%,68%{transform:rotateY(180deg)}
        75%,93%{transform:rotateY(270deg)}
        100%{transform:rotateY(360deg)}
    }
    .avl-coin-face{position:absolute;inset:0;border-radius:50%;
        display:flex;align-items:center;justify-content:center;
        font-size:1.7rem;font-weight:900;color:#7A4A00;
        background:
            radial-gradient(circle at 35% 30%, #FFF3CE 0%, #FDE68A 22%, #FBBF24 55%, #D97706 100%);
        border:3px solid #F3D27A;
        text-shadow:0 1px 0 rgba(255,255,255,.5);
        backface-visibility:hidden;
        box-shadow:inset 0 0 0 5px rgba(255,255,255,.18), inset 0 -6px 14px rgba(120,68,0,.35);
    }
    /* دندانه‌های لبه‌ی سکه با یک نوار نقطه‌چین دورش */
    .avl-coin-face::before{content:'';position:absolute;inset:5px;border-radius:50%;
        border:2px dotted rgba(122,74,0,.45);}
    .avl-coin-face.f1{transform:rotateY(0deg) translateZ(1px);}
    .avl-coin-face.f2{transform:rotateY(90deg) translateZ(1px);}
    .avl-coin-face.f3{transform:rotateY(180deg) translateZ(1px);}
    .avl-coin-face.f4{transform:rotateY(270deg) translateZ(1px);}

    /* درخشش/برق روی سکه که مدام از روی آن رد می‌شود */
    .avl-coin-glint{position:absolute;top:0;left:-60%;width:40%;height:100%;
        background:linear-gradient(100deg, transparent, rgba(255,255,255,.75), transparent);
        transform:skewX(-18deg);
        animation:avlGlint 2.4s ease-in-out infinite;pointer-events:none;mix-blend-mode:overlay;}
    @keyframes avlGlint{0%{left:-60%}45%,100%{left:130%}}

    /* سکه‌های کوچک مداری اطراف سکه‌ی اصلی */
    .avl-coin-mini{position:absolute;top:50%;left:50%;width:26px;height:26px;margin:-13px;border-radius:50%;
        display:flex;align-items:center;justify-content:center;
        font-size:.78rem;font-weight:900;color:#7A4A00;
        background:radial-gradient(circle at 35% 30%, #FFF3CE 0%, #FDE68A 25%, #FBBF24 60%, #D97706 100%);
        border:2px solid #F3D27A;box-shadow:0 0 12px rgba(251,191,36,.7);}
    .avl-coin-mini.m1{animation:avlOrbit 3.4s linear infinite;}
    .avl-coin-mini.m2{animation:avlOrbit 3.4s linear infinite;animation-delay:-1.13s;}
    .avl-coin-mini.m3{animation:avlOrbit 3.4s linear infinite;animation-delay:-2.26s;}
    @keyframes avlOrbit{from{transform:rotate(0) translateX(72px) rotate(0)}
                        to{transform:rotate(360deg) translateX(72px) rotate(-360deg)}}

    /* نماد تبادل/حواله زیر سکه، با تپش ملایم */
    .avl-swap{position:absolute;bottom:-6px;left:50%;transform:translateX(-50%);
        width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;
        background:linear-gradient(135deg,#7C3AED,#A855F7);color:#fff;font-size:.9rem;
        box-shadow:0 0 16px rgba(168,85,247,.75), 0 0 0 4px rgba(21,12,54,.9);
        animation:avlSwapPulse 1.6s ease-in-out infinite;}
    @keyframes avlSwapPulse{0%,100%{transform:translateX(-50%) scale(1)}50%{transform:translateX(-50%) scale(1.14)}}

    .avl-title{color:#fff;font-size:1rem;font-weight:800;letter-spacing:.02em;margin-bottom:16px;}
    .avl-title::after{content:'';animation:avlDots 1.4s steps(4,end) infinite;}
    @keyframes avlDots{0%{content:''}25%{content:'.'}50%{content:'..'}75%{content:'...'}}

    .loading-progress{width:100%;height:5px;border-radius:20px;overflow:hidden;
        background:rgba(255,255,255,.12);margin-bottom:16px;}
    .loading-progress-bar{height:100%;width:38%;border-radius:20px;
        background:linear-gradient(90deg,#7C3AED,#FBBF24,#38BDF8,#FF4D8D);background-size:300% 100%;
        animation:avlSlide 1.5s ease-in-out infinite, avlHue 3s linear infinite;}
    @keyframes avlSlide{0%{margin-left:-40%}100%{margin-left:100%}}
    @keyframes avlHue{to{background-position:300% 0}}

    .avl-tip{display:flex;align-items:center;justify-content:center;gap:8px;
        color:rgba(255,255,255,.62);font-size:.72rem;line-height:1.7;
        animation:avlFade 3.4s ease-in-out infinite;}
    .avl-tip i{color:#FFD93D;}
    @keyframes avlFade{0%,100%{opacity:.55}50%{opacity:1}}

    /* ===== استوری تبلیغاتی (مثل اینستاگرام) ===== */
    .ava-story-bubble{
        position:fixed;bottom:calc(88px + env(safe-area-inset-bottom));left:16px;z-index:8500;
        width:56px;height:56px;border-radius:50%;border:0;cursor:pointer;padding:0;
        background:#12061f;overflow:visible;
    }
    .ava-story-ring{
        position:absolute;inset:-3px;border-radius:50%;
        background:conic-gradient(from 220deg,#FF3B5C,#FF7A59,#FFD93D,#FF3B5C);
        -webkit-mask:radial-gradient(farthest-side,transparent calc(100% - 3px),#000 calc(100% - 3px));
        mask:radial-gradient(farthest-side,transparent calc(100% - 3px),#000 calc(100% - 3px));
        animation:avaStoryRingSpin 3s linear infinite;
    }
    .ava-story-bubble.seen .ava-story-ring{
        background:conic-gradient(#6b6b78,#6b6b78);animation:none;
    }
    @keyframes avaStoryRingSpin{to{transform:rotate(360deg)}}
    .ava-story-bubble img{
        position:absolute;inset:3px;width:calc(100% - 6px);height:calc(100% - 6px);
        border-radius:50%;object-fit:cover;display:none;
    }
    .ava-story-bubble img[src]:not([src=""]){display:block;}
    .ava-story-bubble i{
        position:relative;z-index:1;color:#fff;font-size:1.1rem;
        display:flex;align-items:center;justify-content:center;width:100%;height:100%;
    }
    .ava-story-bubble img[src]:not([src=""]) + i{display:none;}
    .ava-story-bubble:active{transform:scale(.93);}

    .ava-story-viewer{
        display:none;position:fixed;inset:0;z-index:200500;background:#000;
        flex-direction:column;
    }
    .ava-story-viewer.open{display:flex;}
    .ava-story-bars{
        display:flex;gap:4px;padding:calc(env(safe-area-inset-top) + 10px) 10px 0;
    }
    .ava-story-bar{flex:1;height:3px;border-radius:3px;background:rgba(255,255,255,.3);overflow:hidden;}
    .ava-story-bar-fill{height:100%;width:0%;background:#fff;border-radius:3px;}
    .ava-story-bar-fill.animating{transition:width linear;}
    .ava-story-head{
        display:flex;align-items:center;justify-content:space-between;gap:10px;
        padding:10px 14px;
    }
    .ava-story-head-label{color:#fff;font-size:.78rem;font-weight:700;display:flex;align-items:center;gap:6px;}
    .ava-story-head-label i{color:#FFD93D;}
    .ava-story-close{
        background:rgba(255,255,255,.14);border:0;color:#fff;width:32px;height:32px;border-radius:50%;
        display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.85rem;
    }
    .ava-story-media{
        flex:1;display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden;
    }
    .ava-story-media img, .ava-story-media video{
        max-width:100%;max-height:100%;object-fit:contain;
    }
    .ava-story-tap{position:absolute;top:60px;bottom:70px;width:35%;z-index:2;cursor:pointer;}
    .ava-story-tap-l{left:0;}
    .ava-story-tap-r{right:0;}
    .ava-story-foot{padding:14px;display:flex;justify-content:center;}
    .ava-story-link-btn{
        display:flex;align-items:center;gap:8px;color:#fff;background:rgba(255,255,255,.14);
        border:1px solid rgba(255,255,255,.3);border-radius:14px;padding:10px 20px;
        font-size:.78rem;font-weight:700;text-decoration:none;
    }

    /* ===== لایت‌باکس تصویر فیش (کلیک روی نوتیفیکیشن «فیش جدید دریافت شد») ===== */
    .ava-rcpt-lb{
        position:fixed; inset:0; z-index:5000; display:none;
        align-items:center; justify-content:center;
        background:radial-gradient(120% 120% at 50% 0%, rgba(36,21,86,.92) 0%, rgba(12,6,32,.96) 60%, rgba(6,3,16,.98) 100%);
        backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);
        animation:avaRcptLbFade .25s ease;
    }
    .ava-rcpt-lb.show{ display:flex; }
    @keyframes avaRcptLbFade{ from{opacity:0} to{opacity:1} }
    .ava-rcpt-lb-box{
        position:relative; width:92%; max-width:420px; max-height:88vh;
        background:linear-gradient(160deg,rgba(255,255,255,.10),rgba(255,255,255,.03));
        border:1px solid rgba(255,255,255,.16); border-radius:26px; padding:18px;
        box-shadow:0 24px 60px rgba(0,0,0,.55), inset 0 1px 0 rgba(255,255,255,.2);
        display:flex; flex-direction:column; gap:14px;
        animation:avaRcptLbIn .35s cubic-bezier(.22,1.4,.36,1);
    }
    @keyframes avaRcptLbIn{ 0%{opacity:0; transform:translateY(26px) scale(.94)} 100%{opacity:1; transform:none} }
    .ava-rcpt-lb-close{
        position:absolute; top:-14px; left:-14px; width:38px; height:38px; border-radius:50%;
        background:linear-gradient(135deg,#7C3AED,#EC4899); color:#fff; border:none; cursor:pointer;
        display:flex; align-items:center; justify-content:center; font-size:1rem;
        box-shadow:0 8px 20px rgba(124,58,237,.5); z-index:2;
    }
    .ava-rcpt-lb-imgwrap{
        position:relative; min-height:180px; border-radius:18px; overflow:hidden;
        background:rgba(0,0,0,.25); display:flex; align-items:center; justify-content:center;
    }
    .ava-rcpt-lb-imgwrap img{ width:100%; max-height:60vh; object-fit:contain; border-radius:18px; }
    .ava-rcpt-lb-loading{ display:flex; align-items:center; justify-content:center; padding:40px 0; }
    .ava-rcpt-lb-thumbs{ display:flex; gap:8px; overflow-x:auto; padding-bottom:2px; }
    .ava-rcpt-lb-thumbs img{
        width:52px; height:52px; object-fit:cover; border-radius:12px; cursor:pointer;
        border:2px solid transparent; opacity:.6; transition:.2s;
    }
    .ava-rcpt-lb-thumbs img.active{ border-color:#A855F7; opacity:1; }
    .ava-rcpt-lb-dl{
        display:flex; align-items:center; justify-content:center; gap:8px;
        background:linear-gradient(135deg,#7C3AED,#A855F7); color:#fff; text-decoration:none;
        padding:11px; border-radius:14px; font-size:.82rem; font-weight:600;
    }

    .fluctuation-item.up { color: #4CD964; }
    .fluctuation-item.down { color: #FF3B30; }
    
    #currencyRateSection {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100vh;
        z-index: 1000;
        background: var(--primary-dark);
        display: none;
    }
    
:root { --mr-header-h: 60px; }   /* ارتفاع هدر بخش نرخ — mr-wrap از همین استفاده می‌کند */

.currency-rate-header {
    height: var(--mr-header-h);
    background: var(--glass-bg);
    backdrop-filter: blur(20px);
    border-bottom: 1px solid var(--glass-border);
    display: flex;
    align-items: center;
    padding: 0 20px;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 1001;
}

/* اضافه کردن فاصله از بالای موبایل برای دستگاه‌های دارای ناچ */
@supports (padding-top: env(safe-area-inset-top)) {
    :root { --mr-header-h: calc(60px + env(safe-area-inset-top)); }
    .currency-rate-header {
        padding-top: env(safe-area-inset-top);
        height: var(--mr-header-h);
                background: #5f0466;
    }
}

/* برای موبایل‌های معمولی که ناچ ندارند */
@media (max-width: 768px) {
    /* ناچ باید اینجا هم لحاظ شود؛ این قانون بعد از @supports می‌آید و
       در غیر این صورت مقدار safe-area را خنثی می‌کرد و هدر روی محتوا می‌افتاد */
    :root { --mr-header-h: calc(70px + env(safe-area-inset-top, 0px)); }
    .currency-rate-header {
        padding-top: env(safe-area-inset-top, 10px);
        height: var(--mr-header-h);
                background: #5f0466;
    }
}

/* برای آیفون X و بالاتر */
@media only screen and (device-width: 375px) and (device-height: 812px) and (-webkit-device-pixel-ratio: 3),
       only screen and (device-width: 414px) and (device-height: 896px) and (-webkit-device-pixel-ratio: 3),
       only screen and (device-width: 390px) and (device-height: 844px) and (-webkit-device-pixel-ratio: 3),
       only screen and (device-width: 428px) and (device-height: 926px) and (-webkit-device-pixel-ratio: 3) {
    :root { --mr-header-h: 104px; }
    .currency-rate-header {
        padding-top: 44px;
        height: var(--mr-header-h);
                background: #5f0466;
    }
}
    .back-button {
        background: none;
        border: none;
        color: var(--text-light);
        font-size: 1.2rem;
        cursor: pointer;
        padding: 10px;
        display: flex;
        align-items: center;
        gap: 10px;
        transition: var(--transition);
    }
    
    .back-button:hover { color: var(--accent-purple); }
    
    .currency-rate-title {
        flex: 1;
        text-align: center;
        font-size: 1.1rem;
        font-weight: 600;
    }
    
    .refresh-btn {
        background: var(--glass-bg);
        border: 1px solid var(--glass-border);
        color: var(--text-light);
        padding: 8px 15px;
        border-radius: 10px;
        cursor: pointer;
        transition: var(--transition);
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.9rem;
    }
    
    .refresh-btn:hover {
        background: rgba(108, 64, 197, 0.1);
        border-color: var(--accent-purple);
    }
    
    /* ===== نرخ لحظه‌ای بازار (بومی — جایگزین iframe) ===== */
    .mr-wrap {
        position: absolute; top: var(--mr-header-h, 60px); left: 0; right: 0; bottom: 0;
        overflow-y: auto;
        /* فاصله‌ی بالای بیشتر تا باکس «واچ‌لیست من» زیر هدر پنهان نشود */
        padding: 22px 14px 14px;
        scroll-padding-top: 22px;
        display: flex; flex-direction: column; gap: 12px;
        background: #1A0B2E;
    }
    /* واچ‌لیست: کمی پایین‌تر از هدر تا کاملاً دیده شود */
    .mr-watch-box {
        background: rgba(245,179,1,.07); border: 1px solid rgba(245,179,1,.25);
        border-radius: 16px; padding: 12px; margin-top: 10px;
    }
    .mr-watch-head { display: flex; justify-content: space-between; align-items: center; font-size: .78rem; font-weight: 800; color: #fff; margin-bottom: 8px; }
    .mr-updated { font-size: .6rem; font-weight: 600; color: rgba(255,255,255,.45); }
    .mr-watch-list { display: flex; flex-direction: column; gap: 4px; }

    .mr-tabs { display: flex; gap: 8px; }
    .mr-tab {
        flex: 1; padding: 10px 6px; border-radius: 13px; cursor: pointer; font-family: inherit;
        background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.08);
        color: rgba(255,255,255,.6); font-size: .72rem; font-weight: 800; transition: .2s;
        display: flex; align-items: center; justify-content: center; gap: 5px;
    }
    .mr-tab.active { background: linear-gradient(135deg,#6C40C5,#A855F7); border-color: transparent; color: #fff; box-shadow: 0 6px 18px rgba(108,64,197,.35); }

    .mr-search { position: relative; }
    .mr-search i { position: absolute; right: 13px; top: 50%; transform: translateY(-50%); color: rgba(255,255,255,.35); font-size: .8rem; }
    .mr-search input {
        width: 100%; padding: 11px 38px 11px 13px; border-radius: 13px;
        background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.08);
        color: #fff; font-family: inherit; font-size: .8rem; direction: rtl;
    }
    .mr-search input:focus { outline: none; border-color: #A855F7; }

    .mr-hint { font-size: .62rem; color: rgba(255,255,255,.4); text-align: center; }
    .mr-hint i { color: #F5B301; }

    .mr-thead { display: flex; align-items: center; gap: 8px; padding: 0 12px; font-size: .62rem; font-weight: 800; color: rgba(255,255,255,.4); }
    .mr-th-name { flex: 1; padding-right: 40px; }
    .mr-th-num { width: 84px; text-align: center; }

    .mr-list { display: flex; flex-direction: column; gap: 6px;
        padding-bottom: calc(105px + env(safe-area-inset-bottom, 0px)); }
    .mr-loading { text-align: center; padding: 28px 10px; color: rgba(255,255,255,.45); font-size: .78rem; }

    .mr-row {
        display: flex; align-items: center; gap: 8px;
        background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.06);
        border-radius: 13px; padding: 10px 12px;
    }
    .mr-row.compact { background: transparent; border-color: transparent; padding: 6px 4px; }

    .mr-star { background: none; border: none; cursor: pointer; color: rgba(255,255,255,.28); font-size: .85rem; padding: 2px 4px; }
    .mr-star.on { color: #F5B301; }

    .mr-name { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
    .mr-name b { font-size: .76rem; font-weight: 800; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .mr-code { font-size: .58rem; color: rgba(255,255,255,.4); font-weight: 700; }
    .mr-chg { margin-right: 5px; font-weight: 800; }
    .mr-chg.up { color: #22C55E; }
    .mr-chg.dn { color: #FF5A6E; }

    .mr-price { width: 84px; text-align: center; font-size: .75rem; font-weight: 800; direction: ltr; }
    .mr-price.buy { color: #22C55E; }
    .mr-price.sell { color: #FF9F43; }

    @media (max-width: 400px) {
        .mr-price, .mr-th-num { width: 72px; font-size: .68rem; }
    }

    .main-content.hidden { display: none; }
    body.currency-rate-active { overflow: hidden; }

    /* منوی پایین باید در بخش Ava Pay (نرخ‌ها) هم دیده شود.
       بخش نرخ z-index:1000 دارد، پس منو باید بالاتر بیاید. */
    body.currency-rate-active .bottom-nav { z-index: 1002; }

    .toast {
        position: fixed;
        bottom: 90px;
        left: 50%;
        transform: translateX(-50%) translateY(100px);
        /* پس‌زمینه‌ی تیره و مات: روی اندروید backdrop-filter پشتیبانی نمی‌شود
           و باعث می‌شد توست کاملاً شفاف/نامرئی شود. رنگ ثابت تیره جایگزین شد. */
        background: #1a1a2e;
        background: rgba(20, 20, 35, 0.97);
        -webkit-backdrop-filter: blur(20px);
        backdrop-filter: blur(20px);
        padding: 10px 18px;
        border-radius: 30px;
        color: #ffffff;
        z-index: 100000;
        transition: transform 0.3s ease, opacity 0.3s ease;
        opacity: 0;
        font-size: 0.8rem;
        white-space: normal;
        max-width: 90%;
        text-align: center;
        border-right: 3px solid #FFC107;
        box-shadow: 0 5px 20px rgba(0,0,0,0.45);
        pointer-events: none;
    }
    
    .toast.show {
        transform: translateX(-50%) translateY(0);
        opacity: 1;
    }
    
    .toast.success { border-right-color: #4CD964; }
    .toast.error { border-right-color: #FF3B30; }
    .toast.info { border-right-color: #FFC107; }

    /* دایره قرمز برای نوتیفیکیشن تبادل ارزی */
    .arad-notification-dot {
        position: absolute;
        top: -5px;
        right: -5px;
        width: 12px;
        height: 12px;
        background: #FF3B30;
        border-radius: 50%;
        border: 2px solid #1a0b2e;
        animation: aradPulse 1s infinite;
    }
    
    @keyframes aradPulse {
        0% { transform: scale(1); opacity: 1; }
        50% { transform: scale(1.3); opacity: 0.7; }
        100% { transform: scale(1); opacity: 1; }
    }
    
    .bottom-nav .nav-item {
        position: relative;
    }
    </style>

    <script>
    // ==================== پیام‌های لودینگ ====================
    const loadingMessages = {
        'arad': ['به پلتفرم تبادل ارزی خوش آمدید...', 'در حال بارگذاری اطلاعات تبادل...', 'اتصال به بازارهای جهانی...', 'آماده برای تبادل هوشمند...'],
        'dashboard': ['به خانه خوش آمدید...', 'در حال بارگذاری داشبورد...', 'آماده‌سازی اطلاعات حساب...', 'در حال دریافت آخرین تراکنش‌ها...'],
        'transactions': ['در حال بارگذاری تراکنش‌ها...', 'آماده‌سازی تاریخچه مالی...', 'در حال دریافت آخرین انتقال‌ها...', 'بارگذاری گزارش تراکنش‌ها...'],
        'profile': ['در حال بارگذاری پروفایل...', 'آماده‌سازی اطلاعات کاربری...', 'در حال دریافت تنظیمات...', 'بارگذاری تصویر پروفایل...'],
        'currency': ['در حال دریافت نرخ‌های ارز...', 'اتصال به بازارهای جهانی...', 'در حال بروزرسانی قیمت‌ها...', 'دریافت آخرین نرخ‌ها...']
    };

    const loadingTips = {
        'arad': ['تبادل ارز با بهترین نرخ‌های لحظه‌ای', 'امنیت سطح بالا در تمام تراکنش‌ها', 'پشتیبانی از ارزهای دیجیتال و فیات', 'پرداخت‌های سریع و بدون واسطه'],
        'dashboard': ['با Ava Pay سریع و امن انتقال وجه انجام دهید', 'موجودی خود را در ارزهای مختلف مدیریت کنید', 'از دوستان خود برای انتقال وجه استفاده کنید', 'تاریخچه تراکنش‌ها را در هر زمان مشاهده کنید'],
        'transactions': ['تراکنش‌های خود را بر اساس تاریخ فیلتر کنید', 'گزارش PDF از تراکنش‌ها دریافت کنید', 'آمار دقیق دریافتی و پرداختی را ببینید', 'جستجوی پیشرفته بین تراکنش‌ها'],
        'profile': ['اطلاعات شخصی خود را به روز نگه دارید', 'تصویر پروفایل خود را تغییر دهید', 'تنظیمات امنیتی را فعال کنید', 'از آخرین بروزرسانی‌ها مطلع شوید'],
        'currency': ['نرخ‌ها هر ۶۰ ثانیه به‌روزرسانی می‌شوند', 'بهترین نرخ خرید و فروش را مقایسه کنید', 'نمودار تغییرات ارزها را مشاهده کنید', 'نوسانات بازار را زیر نظر داشته باشید']
    };

    function showGlobalLoading(pageType = 'dashboard') {
        const loadingOverlay = document.getElementById('globalLoadingOverlay');
        if (loadingOverlay) {
            const messages = loadingMessages[pageType] || loadingMessages['dashboard'];
            const tips = loadingTips[pageType] || loadingTips['dashboard'];
            startMessageRotation(messages, tips);
            loadingOverlay.style.display = 'flex';
            loadingOverlay.style.opacity = '1';
        }
    }
    
    function hideGlobalLoading() {
        const loadingOverlay = document.getElementById('globalLoadingOverlay');
        if (loadingOverlay) {
            loadingOverlay.style.opacity = '0';
            setTimeout(() => {
                loadingOverlay.style.display = 'none';
                loadingOverlay.style.opacity = '1';
                if (window.messageInterval) clearInterval(window.messageInterval);
            }, 500);
        }
    }

    // ===== لایت‌باکس نمایش فوری فیش از روی نوتیفیکیشن =====
    async function avaOpenReceiptFromNotif(type, relatedId) {
        const lb = document.getElementById('avaReceiptLightbox');
        const imgWrap = lb ? lb.querySelector('.ava-rcpt-lb-loading') : null;
        const img = document.getElementById('avaReceiptLightboxImg');
        const thumbs = document.getElementById('avaReceiptLightboxThumbs');
        const dl = document.getElementById('avaReceiptLightboxDownload');
        if (!lb || !img) return;

        lb.classList.add('show');
        if (imgWrap) imgWrap.style.display = 'flex';
        img.style.display = 'none';
        if (thumbs) thumbs.innerHTML = '';

        try {
            const res = await fetch(`api/notification_api.php?action=get_receipt_preview&type=${encodeURIComponent(type)}&related_id=${encodeURIComponent(relatedId)}`);
            const data = await res.json();
            if (data.success && data.images && data.images.length) {
                avaSetReceiptLightboxImage(data.images[0]);
                if (data.images.length > 1 && thumbs) {
                    thumbs.innerHTML = data.images.map((u, i) =>
                        `<img src="${u}" class="${i === 0 ? 'active' : ''}" onclick="avaSetReceiptLightboxImage('${u}', this)">`
                    ).join('');
                }
            } else {
                avaCloseReceiptLightbox();
                if (typeof showToast === 'function') showToast('تصویر فیش یافت نشد', 'error');
            }
        } catch (e) {
            avaCloseReceiptLightbox();
        }
    }
    function avaSetReceiptLightboxImage(url, thumbEl) {
        const imgWrap = document.querySelector('#avaReceiptLightbox .ava-rcpt-lb-loading');
        const img = document.getElementById('avaReceiptLightboxImg');
        const dl = document.getElementById('avaReceiptLightboxDownload');
        if (!img) return;
        img.onload = () => { if (imgWrap) imgWrap.style.display = 'none'; img.style.display = 'block'; };
        img.src = url;
        if (dl) dl.href = url;
        if (thumbEl) {
            document.querySelectorAll('#avaReceiptLightboxThumbs img').forEach(t => t.classList.remove('active'));
            thumbEl.classList.add('active');
        }
    }
    function avaCloseReceiptLightbox() {
        const lb = document.getElementById('avaReceiptLightbox');
        const img = document.getElementById('avaReceiptLightboxImg');
        if (lb) lb.classList.remove('show');
        if (img) { img.src = ''; img.style.display = 'none'; }
    }
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape') avaCloseReceiptLightbox(); });
    window.avaOpenReceiptFromNotif = avaOpenReceiptFromNotif;
    window.avaSetReceiptLightboxImage = avaSetReceiptLightboxImage;
    window.avaCloseReceiptLightbox = avaCloseReceiptLightbox;

    /* ==================== استوری تبلیغاتی (مثل اینستاگرام) ==================== */
    let avaStoryItems = [];
    let avaStoryIndex = 0;
    let avaStoryTimer = null;
    const AVA_STORY_MS = 60000; // شصت ثانیه

    async function avaStoryCheck(){
        try {
            const r = await fetch('/ledor/api/admin_stories_api.php?action=active', { credentials:'include', cache:'no-store' });
            const d = await r.json();
            const bubble = document.getElementById('avaStoryBubble');
            if (!bubble) return;
            if (!d.success || !d.items || !d.items.length) { bubble.style.display = 'none'; return; }
            bubble.style.display = '';
            bubble.classList.toggle('seen', !d.has_unseen);
            const thumb = document.getElementById('avaStoryBubbleThumb');
            const firstImg = d.items.find(it => it.media_type === 'image');
            if (thumb) thumb.src = firstImg ? ('/ledor/' + firstImg.media_path) : '';
        } catch(e){}
    }

    function avaStoryOpen(){
        fetch('/ledor/api/admin_stories_api.php?action=active', { credentials:'include', cache:'no-store' })
            .then(r => r.json())
            .then(d => {
                if (!d.success || !d.items || !d.items.length) return;
                avaStoryItems = d.items;
                // از اولین استوریِ دیده‌نشده شروع کن؛ اگر همه دیده شده‌اند، از اول
                let startIdx = avaStoryItems.findIndex(it => !it.viewed);
                avaStoryIndex = startIdx >= 0 ? startIdx : 0;
                const viewer = document.getElementById('avaStoryViewer');
                if (viewer) viewer.classList.add('open');
                avaStoryRenderBars();
                avaStoryShow(avaStoryIndex);
            })
            .catch(() => {});
    }

    function avaStoryRenderBars(){
        const wrap = document.getElementById('avaStoryBars');
        if (!wrap) return;
        wrap.innerHTML = avaStoryItems.map(function(_, i){
            return '<div class="ava-story-bar"><div class="ava-story-bar-fill" id="avaStoryBarFill' + i + '"></div></div>';
        }).join('');
    }

    function avaStoryShow(idx){
        if (avaStoryTimer) { clearTimeout(avaStoryTimer); avaStoryTimer = null; }
        if (idx < 0) { avaStoryClose(); return; }
        if (idx >= avaStoryItems.length) { avaStoryClose(); return; }
        avaStoryIndex = idx;
        const item = avaStoryItems[idx];

        // نوارهای قبلی = پر، فعلی = شروع پرشدن، بعدی‌ها = خالی
        avaStoryItems.forEach(function(_, i){
            const fill = document.getElementById('avaStoryBarFill' + i);
            if (!fill) return;
            fill.classList.remove('animating');
            fill.style.transition = 'none';
            fill.style.width = (i < idx) ? '100%' : '0%';
        });

        const media = document.getElementById('avaStoryMedia');
        if (media) {
            media.innerHTML = '';
            if (item.media_type === 'video') {
                const v = document.createElement('video');
                v.src = '/ledor/' + item.media_path;
                v.autoplay = true; v.muted = false; v.playsInline = true;
                v.addEventListener('ended', avaStoryNext);
                media.appendChild(v);
            } else {
                const im = document.createElement('img');
                im.src = '/ledor/' + item.media_path;
                media.appendChild(im);
            }
        }

        const foot = document.getElementById('avaStoryFoot');
        const link = document.getElementById('avaStoryLink');
        const linkText = document.getElementById('avaStoryLinkText');
        if (item.link_url) {
            if (foot) foot.style.display = 'flex';
            if (link) link.href = item.link_url;
            if (linkText) linkText.textContent = item.caption || 'مشاهده';
        } else if (foot) {
            foot.style.display = 'none';
        }

        // ثبت مشاهده (idempotent سمت سرور)
        fetch('/ledor/api/admin_stories_api.php?action=mark_viewed', {
            method: 'POST', headers: {'Content-Type':'application/json'}, credentials:'include',
            body: JSON.stringify({ story_id: item.id })
        }).catch(() => {});
        item.viewed = true;

        // پر شدنِ نوارِ همین استوری طیِ ۶۰ ثانیه، بعد خودکار برو بعدی
        requestAnimationFrame(function(){
            const fill = document.getElementById('avaStoryBarFill' + idx);
            if (fill) {
                fill.style.transition = 'width ' + AVA_STORY_MS + 'ms linear';
                fill.classList.add('animating');
                requestAnimationFrame(function(){ fill.style.width = '100%'; });
            }
        });
        avaStoryTimer = setTimeout(avaStoryNext, AVA_STORY_MS);
    }

    function avaStoryNext(){ avaStoryShow(avaStoryIndex + 1); }
    function avaStoryPrev(){ avaStoryShow(avaStoryIndex - 1); }

    function avaStoryClose(){
        if (avaStoryTimer) { clearTimeout(avaStoryTimer); avaStoryTimer = null; }
        const viewer = document.getElementById('avaStoryViewer');
        if (viewer) viewer.classList.remove('open');
        const media = document.getElementById('avaStoryMedia');
        if (media) media.innerHTML = '';
        avaStoryCheck(); // آپدیت حلقه‌ی گوی (احتمالاً الان «دیده‌شده» است)
    }

    window.avaStoryOpen = avaStoryOpen;
    window.avaStoryNext = avaStoryNext;
    window.avaStoryPrev = avaStoryPrev;
    window.avaStoryClose = avaStoryClose;

    avaStoryCheck();
    setInterval(avaStoryCheck, 120000); // هر ۲ دقیقه چک کن استوری جدیدی منتشر نشده باشد

    function startMessageRotation(messages, tips) {
        let msgIndex = 0, tipIndex = 0;
        const messageElement = document.getElementById('loadingMessage');
        const tipElement = document.getElementById('tipMessage');
        
        if (window.messageInterval) clearInterval(window.messageInterval);
        
        window.messageInterval = setInterval(() => {
            msgIndex = (msgIndex + 1) % messages.length;
            if (messageElement) {
                messageElement.style.opacity = '0';
                setTimeout(() => {
                    messageElement.textContent = messages[msgIndex];
                    messageElement.style.opacity = '1';
                }, 300);
            }
            
            if (msgIndex % 2 === 0) {
                tipIndex = (tipIndex + 1) % tips.length;
                if (tipElement) {
                    tipElement.style.opacity = '0';
                    setTimeout(() => {
                        tipElement.textContent = tips[tipIndex];
                        tipElement.style.opacity = '1';
                    }, 300);
                }
            }
        }, 2500);
    }

    // پروفایل: کشوی پروفایل حذف شده — حالا مستقیم به صفحه‌ی پروفایل می‌رود
    function avaProfileNav(ev) {
        if (ev) { ev.preventDefault(); ev.stopPropagation(); }
        window.location.href = '/ledor/profile.php';
        return false;
    }

    // ==================== ناوبری با ترتیب ثابت ====================
    function navigateWithLoading(page, extra) {
        showGlobalLoading(page);
        
        setTimeout(() => {
            switch(page) {
                case 'arad':
                    window.location.href = 'arad.php' + (extra || '');
                    break;
                case 'dashboard':
                    window.location.href = 'dashboard.php' + (extra || '');
                    break;
                case 'transactions':
                    window.location.href = 'transactions.php' + (extra || '');
                    break;
                case 'profile':
                    hideGlobalLoading();
                    if (typeof avaProfileNav === 'function') avaProfileNav(null);
                    break;
                default:
                    if (page && page.includes('.php')) window.location.href = page;
                    break;
            }
        }, 300);
    }

    // ==================== نمایش نرخ ارز با iframe ====================
    /* ==================== نرخ لحظه‌ای بازار (بومی — بدون iframe) ==================== */
    let mrItems = [];          // همه‌ی نرخ‌ها
    let mrWatch = [];          // کدهای واچ‌لیست کاربر
    let mrCat   = 'currency';  // تب فعال
    let mrTimer = null;        // تازه‌سازی خودکار

    function showCurrencyRate(event) {
        if (event) event.preventDefault();

        const currencySection = document.getElementById('currencyRateSection');
        const mainContent = document.querySelector('.main-content');

        if (currencySection) currencySection.style.display = 'block';
        if (mainContent) mainContent.classList.add('hidden');
        document.body.classList.add('currency-rate-active');

        document.querySelectorAll('.nav-item').forEach(item => {
            item.classList.remove('active');
            if (item.getAttribute('data-page') === 'currency-rate') item.classList.add('active');
        });

        history.pushState({ page: 'currency-rate' }, 'Currency Rate', '?page=currency-rate');

        mrLoad();
        if (mrTimer) clearInterval(mrTimer);
        mrTimer = setInterval(() => mrLoad(true), 60000);   // به‌روزرسانی آنی هر ۶۰ ثانیه
    }

    function hideCurrencyRate() {
        const currencySection = document.getElementById('currencyRateSection');
        const mainContent = document.querySelector('.main-content');

        if (currencySection) currencySection.style.display = 'none';
        if (mainContent) mainContent.classList.remove('hidden');
        document.body.classList.remove('currency-rate-active');

        if (mrTimer) { clearInterval(mrTimer); mrTimer = null; }

        document.querySelectorAll('.nav-item').forEach(item => {
            item.classList.remove('active');
            const currentPage = getCurrentPage();
            if (item.getAttribute('data-page') === currentPage) item.classList.add('active');
        });
    }

    function refreshCurrencyRates() {
        const ic = document.getElementById('mrRefreshIcon');
        if (ic) ic.classList.add('fa-spin');
        mrLoad(true, true).then(() => {
            if (typeof showGlobalToast === 'function') showGlobalToast('نرخ‌ها به‌روزرسانی شد', 'success');
        });
    }

    function mrEsc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    async function mrLoad(silent, force) {
        const list = document.getElementById('mrList');
        if (!list) return;
        if (!silent) list.innerHTML = '<div class="mr-loading"><i class="fas fa-circle-notch fa-spin"></i> در حال دریافت نرخ‌ها…</div>';
        try {
            const r = await fetch('/ledor/api/market_rates.php?action=list' + (force ? '&force=1' : '') + '&t=' + Date.now(), { cache: 'no-store', credentials: 'include' });
            const d = await r.json();
            if (d && d.success) {
                mrItems = d.items || [];
                mrWatch = d.watch || [];
                mrRender();
                mrRenderWatch();
                const up = document.getElementById('mrUpdated');
                if (up && mrItems.length) {
                    const ts = Math.max.apply(null, mrItems.map(i => i.ts || 0));
                    if (ts) up.textContent = 'به‌روزرسانی: ' + new Date(ts * 1000).toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' });
                }
            } else {
                list.innerHTML = '<div class="mr-loading"><i class="fas fa-triangle-exclamation"></i> دریافت نرخ‌ها ناموفق بود</div>';
            }
        } catch (e) {
            list.innerHTML = '<div class="mr-loading"><i class="fas fa-wifi"></i> خطا در ارتباط با سرور</div>';
        }
        const ic = document.getElementById('mrRefreshIcon');
        if (ic) ic.classList.remove('fa-spin');
    }

    function mrTab(cat, el) {
        mrCat = cat;
        document.querySelectorAll('.mr-tab').forEach(b => b.classList.toggle('active', b === el));
        mrRender();
    }

    function mrFmt(v, unit) {
        if (!v && v !== 0) return '—';
        if (unit === 'usd') return '$' + Number(v).toLocaleString('en-US', { maximumFractionDigits: 2 });
        return Number(v).toLocaleString('fa-IR', { maximumFractionDigits: 0 });
    }

    function mrRow(it, compact) {
        const on = mrWatch.indexOf(it.code) !== -1;
        const chg = Number(it.change || 0);
        const chgH = chg ? ('<span class="mr-chg ' + (chg >= 0 ? 'up' : 'dn') + '">' + (chg >= 0 ? '▲' : '▼') + ' ' + Math.abs(chg).toFixed(2) + '%</span>') : '';
        return '<div class="mr-row' + (compact ? ' compact' : '') + '">'
             + '<button class="mr-star' + (on ? ' on' : '') + '" onclick="mrToggleWatch(\'' + it.code + '\',\'' + it.category + '\')">'
             +   '<i class="' + (on ? 'fas' : 'far') + ' fa-star"></i></button>'
             + '<div class="mr-name"><b>' + mrEsc(it.name) + '</b>'
             +   '<span class="mr-code">' + mrEsc(it.code) + ' ' + chgH + '</span></div>'
             + '<div class="mr-price buy">' + mrFmt(it.buy, it.unit) + '</div>'
             + '<div class="mr-price sell">' + mrFmt(it.sell, it.unit) + '</div>'
             + '</div>';
    }

    function mrRender() {
        const list = document.getElementById('mrList');
        if (!list) return;
        const box = document.getElementById('mrSearch');
        const q = (box ? box.value : '').trim().toLowerCase();
        const rows = mrItems.filter(i =>
            i.category === mrCat &&
            (!q || (i.name || '').toLowerCase().indexOf(q) !== -1 || (i.code || '').toLowerCase().indexOf(q) !== -1)
        );
        list.innerHTML = rows.length
            ? rows.map(i => mrRow(i, false)).join('')
            : '<div class="mr-loading"><i class="fas fa-magnifying-glass"></i> موردی یافت نشد</div>';
    }

    function mrRenderWatch() {
        const box = document.getElementById('mrWatchBox');
        const list = document.getElementById('mrWatchList');
        if (!box || !list) return;
        const rows = mrItems.filter(i => mrWatch.indexOf(i.code) !== -1);
        if (!rows.length) { box.style.display = 'none'; return; }
        box.style.display = '';
        list.innerHTML = rows.map(i => mrRow(i, true)).join('');
    }

    async function mrToggleWatch(code, category) {
        try {
            const r = await fetch('/ledor/api/market_rates.php?action=watch', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ code: code, category: category })
            });
            const d = await r.json();
            if (!d || !d.success) {
                // خطا را پنهان نکن تا مشکل مشخص باشد (مثلاً نیاز به ورود مجدد)
                if (typeof showGlobalToast === 'function') {
                    showGlobalToast((d && d.error === 'login required')
                        ? 'برای واچ‌لیست ابتدا وارد حساب شوید'
                        : 'افزودن به واچ‌لیست انجام نشد', 'error');
                }
                return;
            }
            if (d.watched) { if (mrWatch.indexOf(code) === -1) mrWatch.push(code); }
            else { mrWatch = mrWatch.filter(c => c !== code); }
            mrRender();
            mrRenderWatch();
        } catch (e) {
            if (typeof showGlobalToast === 'function') showGlobalToast('خطای ارتباط با سرور', 'error');
        }
    }
    
    function getCurrentPage() {
        const path = window.location.pathname;
        if (path.includes('arad.php')) return 'arad';
        if (path.includes('dashboard.php')) return 'dashboard';
        if (path.includes('transactions.php')) return 'transactions';
        if (path.includes('profile.php')) return 'profile';
        return 'dashboard';
    }
    
    function showGlobalToast(message, type = 'info') {
        const existingToasts = document.querySelectorAll('.toast');
        existingToasts.forEach(toast => toast.remove());
        
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'bell'}"></i> ${message}`;
        document.body.appendChild(toast);
        setTimeout(() => toast.classList.add('show'), 10);
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    /* ====================================================
       شبکه‌ی ایمنی سراسری (همان الگویی که در پنل ادمین جواب داد):
       اگر یک خطای پیش‌بینی‌نشده‌ی جاوااسکریپت وسط یک عملیات کاربر
       (مثلاً بعد از اسکن موفق QR، وسط باز شدن فرم ارسال) رخ دهد،
       قبلاً بدون هیچ پیامی متوقف می‌شد و کاربر فقط می‌دید «هیچ
       اتفاقی نیفتاد». این دو هندلر هر خطای مدیریت‌نشده را می‌گیرند
       و همیشه یک پیام قابل‌مشاهده نشان می‌دهند.
       ==================================================== */
    let _avaLastGlobalErrToast = 0;
    window.addEventListener('error', function(ev){
        const now = Date.now();
        if (now - _avaLastGlobalErrToast < 2000) return;
        _avaLastGlobalErrToast = now;
        if (typeof showGlobalToast === 'function') {
            showGlobalToast('خطای غیرمنتظره: ' + (ev && ev.message ? ev.message : 'نامشخص'), 'error');
        }
    });
    window.addEventListener('unhandledrejection', function(ev){
        const now = Date.now();
        if (now - _avaLastGlobalErrToast < 2000) return;
        _avaLastGlobalErrToast = now;
        const r = ev && ev.reason;
        const msg = r && r.message ? r.message : (typeof r === 'string' ? r : 'نامشخص');
        if (typeof showGlobalToast === 'function') {
            showGlobalToast('خطای غیرمنتظره: ' + msg, 'error');
        }
    });

    // ==================== پولینگ برای نوتیفیکیشن تبادل ارزی ====================
    let globalPollingInterval = null;
    let lastGlobalPendingCount = 0;

    async function checkAradOffersGlobally() {
        if (window.location.pathname.includes('arad.php')) return;
        
        try {
            const response = await fetch('/ledor/api/offer_api.php?action=get_my_received_offers');
            const result = await response.json();
            
            if (result.success && result.offers) {
                const pendingCount = result.offers.filter(o => o.status === 'pending').length;
                
                if (pendingCount > lastGlobalPendingCount && lastGlobalPendingCount > 0) {
                    showGlobalToast('🔔 پیشنهاد جدید در بازار تبادل ارزی دریافت شد!', 'info');
                    playNotificationSound();
                    updateAradBadgeInMenu(true);
                }
                lastGlobalPendingCount = pendingCount;
                
                sessionStorage.setItem('arad_pending_received', pendingCount);
                sessionStorage.setItem('arad_last_check', Date.now());
                
                if (pendingCount > 0) {
                    updateAradBadgeInMenu(true);
                }
            }
        } catch(e) { console.error('Global polling error:', e); }
    }

    function playNotificationSound() {
        try {
            const AudioContext = window.AudioContext || window.webkitAudioContext;
            const ctx = new AudioContext();
            const oscillator = ctx.createOscillator();
            const gain = ctx.createGain();
            oscillator.connect(gain);
            gain.connect(ctx.destination);
            oscillator.frequency.value = 880;
            oscillator.type = 'sine';
            gain.gain.setValueAtTime(0.3, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.5);
            oscillator.start(ctx.currentTime);
            oscillator.stop(ctx.currentTime + 0.5);
            
            setTimeout(() => {
                const osc2 = ctx.createOscillator();
                const gain2 = ctx.createGain();
                osc2.connect(gain2);
                gain2.connect(ctx.destination);
                osc2.frequency.value = 660;
                osc2.type = 'sine';
                gain2.gain.setValueAtTime(0.2, ctx.currentTime);
                gain2.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.3);
                osc2.start(ctx.currentTime);
                osc2.stop(ctx.currentTime + 0.3);
            }, 200);
        } catch(e) {}
    }

    function updateAradBadgeInMenu(show) {
        const aradNavItem = document.querySelector('.bottom-nav .nav-item[data-page="arad"]');
        if (aradNavItem) {
            const existingBadge = aradNavItem.querySelector('.arad-notification-dot');
            if (existingBadge) existingBadge.remove();
            
            if (show) {
                const badge = document.createElement('span');
                badge.className = 'arad-notification-dot';
                aradNavItem.style.position = 'relative';
                aradNavItem.appendChild(badge);
            }
        }
    }

    // ==================== QR / Barcode Scanner ====================
    // اصلاح مشکل «عدم دسترسی به دوربین» روی اندروید/دسکتاپ و PWA:
    //  - درخواست مجوز فقط با کلیک کاربر (user gesture) انجام می‌شود تا
    //    مرورگر/PWA پرامپت واقعی دوربین را نشان دهد نه خطای مستقیم.
    //  - در صورت رد یا نبود دسترسی، راهنمای فعال‌سازی نمایش داده می‌شود.
    let avaScanStream = null;
    let avaScanRAF = null;
    let avaScanUsingBack = true;
    let avaScanDeviceId = null;
    let avaJsqrLoading = null;
    let _avaCanvas = null, _avaCtx = null;
    let _avaScanFrameCount = 0;
    let _avaScanStartTs = 0;

    function avaIsSecureForCamera() {
        // getUserMedia فقط در https یا localhost کار می‌کند
        return window.isSecureContext ||
               location.protocol === 'https:' ||
               location.hostname === 'localhost' ||
               location.hostname === '127.0.0.1';
    }

    function openScanner() {
        const overlay = document.getElementById('avaScanOverlay');
        if (!overlay) return;
        overlay.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        showScanScreen('chooser');
    }

    // نمایش یکی از سه صفحه: chooser | camera | receive
    function showScanScreen(which) {
        const chooser = document.getElementById('avaScanChooser');
        const camera  = document.getElementById('avaScanCamera');
        const receive = document.getElementById('avaScanReceive');
        const flip    = document.getElementById('avaScanFlip');
        const title   = document.getElementById('avaScanHeaderTitle');
        if (chooser) chooser.style.display = which === 'chooser' ? 'flex' : 'none';
        if (camera)  camera.style.display  = which === 'camera'  ? 'flex' : 'none';
        if (receive) receive.style.display = which === 'receive' ? 'flex' : 'none';
        if (flip)    flip.style.display    = which === 'camera'  ? 'flex' : 'none';
        if (title) {
            if (which === 'chooser') title.innerHTML = '<i class="fas fa-qrcode"></i> Pay with QR';
            else if (which === 'camera') title.innerHTML = '<i class="fas fa-paper-plane"></i> Send Money';
            else title.innerHTML = '<i class="fas fa-qrcode"></i> Receive Money';
        }
    }

    // انتخاب ارسال یا دریافت
    async function scanChoose(mode) {
        if (mode === 'receive') {
            showScanScreen('receive');
            renderMyReceiveQR();
            return;
        }
        // mode === 'send' → دوربین
        // مهم: getUserMedia باید در همین کلیک کاربر صدا زده شود تا مرورگر
        // (خصوصاً اندروید و PWA) پرامپت واقعی دوربین را نشان دهد.
        showScanScreen('camera');
        await startCameraFlow();
    }

    // ===== توابعی که قبلاً تعریف نشده بودند و باعث می‌شدند دوربین هرگز باز نشود =====
    // startScanCamera() در خط اول stopScanCamera() را صدا می‌زد؛ چون این تابع
    // وجود نداشت، یک ReferenceError پرتاب می‌شد و کل جریان دوربین متوقف می‌ماند.
    function stopScanCamera() {
        try { if (avaScanRAF) { cancelAnimationFrame(avaScanRAF); avaScanRAF = null; } } catch (e) {}
        try {
            if (avaScanStream) {
                avaScanStream.getTracks().forEach(function (t) { try { t.stop(); } catch (e) {} });
            }
        } catch (e) {}
        avaScanStream = null;
        _avaScanFrameCount = 0;
        var v = document.getElementById('avaScanVideo');
        if (v) {
            try { v.pause(); } catch (e) {}
            try { v.srcObject = null; } catch (e) {}
            try { v.removeAttribute('src'); } catch (e) {}
        }
    }

    function closeScanner() {
        stopScanCamera();
        var overlay = document.getElementById('avaScanOverlay');
        if (overlay) overlay.style.display = 'none';
        document.body.style.overflow = '';
        var permit = document.getElementById('avaScanPermit');
        if (permit) permit.style.display = 'none';
    }

    async function flipScanCamera() {
        avaScanUsingBack = !avaScanUsingBack;
        avaScanDeviceId = null;
        const ok = await startScanCamera();
        if (!ok) { avaScanUsingBack = !avaScanUsingBack; }
    }

    // تولید QR اختصاصی کاربر برای دریافت پول
    async function renderMyReceiveQR() {
        const me = window.AVA_ME || {};
        const nameEl = document.getElementById('avaReceiveName');
        const acctEl = document.getElementById('avaReceiveAcct');
        const qrEl   = document.getElementById('avaReceiveQr');
        if (nameEl) nameEl.textContent = me.name || 'User';
        if (acctEl) acctEl.textContent = me.account || '—';
        if (!qrEl) return;
        qrEl.innerHTML = '<i class="fas fa-spinner fa-spin" style="color:#6C40C5;font-size:1.5rem;"></i>';
        await ensureQRCodeLib();
        qrEl.innerHTML = '';
        // payload اسکن: فقط شماره حساب (ASCII).
        // نکته‌ی مهم: قبلاً نام کاربر هم داخل payload گذاشته می‌شد، اما کتابخانه‌ی
        // qrcodejs متن‌های چندبایتی (فارسی) را درست UTF-8 انکود نمی‌کند و خروجی
        // خراب تولید می‌کند؛ نتیجه این بود که دوربین کاملاً سالم کار می‌کرد، هر
        // فریم هم پردازش می‌شد، ولی هیچ اسکنری (از جمله jsQR خودمان) هیچ‌وقت کد
        // را نمی‌خواند و کاربر برای همیشه در حالت اسکن می‌ماند — دقیقاً همان
        // مشکلی که گزارش شد. این با هیچ اندازه یا سطح تصحیح خطایی حل نمی‌شد.
        // حالا payload فقط ASCII است و نام گیرنده بعد از اسکن از سرور گرفته
        // می‌شود (openQrSend از قبل این حالت را پشتیبانی می‌کند).
        const payload = JSON.stringify({ t: 'avapay_pay', acct: me.account || '' });
        try {
            if (window.QRCode) {
                // محافظ: اگر روزی چیزی غیر-ASCII وارد payload شود، به‌جای تولید یک
                // QR خرابِ غیرقابل‌اسکن (که هیچ خطایی هم نمی‌دهد)، آن را حذف می‌کنیم.
                const safePayload = /^[\x20-\x7E]*$/.test(payload)
                    ? payload
                    : JSON.stringify({ t: 'avapay_pay', acct: String(me.account || '').replace(/[^\x20-\x7E]/g, '') });
                // نکته‌ی مهم: قبلاً کد QR دقیقاً هم‌اندازه‌ی کانتینر (۲۱۰×۲۱۰) رندر می‌شد،
                // یعنی هیچ حاشیه‌ی سفید («quiet zone») دور خودش نداشت. هر اسکنر QR از
                // جمله jsQR برای پیدا کردن الگوهای finder به این حاشیه‌ی خالی نیاز دارد؛
                // بدون آن، دوربین می‌تواند کاملاً درست کار کند و هر فریم را هم پردازش
                // کند ولی هیچ‌وقت کد را قفل نکند — دقیقاً همان «دوربین باز می‌شود ولی
                // چیزی تشخیص نمی‌دهد» که گزارش شده بود. حالا QR کوچک‌تر از کانتینرش
                // ساخته می‌شود تا پس‌زمینه‌ی سفید کارت خودش حاشیه‌ی امن را تامین کند.
                new window.QRCode(qrEl, {
                    text: safePayload,
                    width: 168, height: 168,
                    colorDark: '#1a0b2e', colorLight: '#ffffff',
                    correctLevel: window.QRCode.CorrectLevel.M
                });
            } else {
                // قبلاً اینجا فقط شماره حساب را به‌عنوان متن ساده نشان می‌داد —
                // یعنی هیچ کد QR واقعی روی صفحه نبود، و طرف مقابل هر چقدر هم
                // دوربینش را می‌گرفت چیزی برای اسکن کردن وجود نداشت، بدون
                // اینکه هیچ‌کدام از دو کاربر متوجه شوند مشکل از کجاست.
                // حالا این حالت را واضح نشان می‌دهیم + دکمه‌ی تلاش دوباره.
                qrEl.innerHTML = '<div style="text-align:center;padding:10px;color:#FF6B6B;font-size:.78rem;line-height:1.7;">'
                    + '<i class="fas fa-triangle-exclamation" style="font-size:1.3rem;display:block;margin-bottom:6px;"></i>'
                    + 'QR code failed to load — check your connection.<br>'
                    + '<button type="button" onclick="renderMyReceiveQR()" style="margin-top:6px;padding:6px 14px;border-radius:10px;border:1px solid rgba(255,107,107,.4);background:rgba(255,107,107,.12);color:#FF6B6B;font-size:.75rem;cursor:pointer;">Retry</button>'
                    + '</div>';
                if (typeof showGlobalToast === 'function') showGlobalToast('QR code failed to generate', 'error');
            }
        } catch(e) {
            qrEl.innerHTML = '<div style="text-align:center;padding:10px;color:#FF6B6B;font-size:.78rem;line-height:1.7;">'
                + '<i class="fas fa-triangle-exclamation" style="font-size:1.3rem;display:block;margin-bottom:6px;"></i>'
                + 'QR code failed to load.<br>'
                + '<button type="button" onclick="renderMyReceiveQR()" style="margin-top:6px;padding:6px 14px;border-radius:10px;border:1px solid rgba(255,107,107,.4);background:rgba(255,107,107,.12);color:#FF6B6B;font-size:.75rem;cursor:pointer;">Retry</button>'
                + '</div>';
            if (typeof showGlobalToast === 'function') showGlobalToast('QR error: ' + (e && e.message ? e.message : 'unknown'), 'error');
        }
    }

    let _qrLibLoading = null;
    function ensureQRCodeLib() {
        if (window.QRCode) return Promise.resolve();
        if (_qrLibLoading) return _qrLibLoading;
        // مشابه jsQR، ابتدا نسخه‌ی محلی (بدون وابستگی به CDN فیلترشده) امتحان می‌شود
        const urls = [
            'assets/js/vendor/qrcode.min.js',
            'https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js'
        ];
        _qrLibLoading = new Promise((resolve) => {
            let i = 0;
            const tryNext = () => {
                if (window.QRCode || i >= urls.length) { resolve(); return; }
                const s = document.createElement('script');
                s.src = urls[i++];
                s.onload  = () => { window.QRCode ? resolve() : tryNext(); };
                s.onerror = () => tryNext();
                document.head.appendChild(s);
            };
            tryNext();
        });
        return _qrLibLoading;
    }

    async function shareMyAccount() {
        const me = window.AVA_ME || {};
        const text = `Ava Pay account\nName: ${me.name || ''}\nAccount: ${me.account || ''}`;
        if (navigator.share) {
            try { await navigator.share({ title: 'Ava Pay', text }); return; } catch(e){}
        }
        try {
            await navigator.clipboard.writeText(text);
            if (typeof showGlobalToast === 'function') showGlobalToast('Details copied', 'success');
        } catch(e) {
            if (typeof showGlobalToast === 'function') showGlobalToast(me.account || '', 'info');
        }
    }

    // راه‌اندازی جریان دوربین (همان منطق قبلی بررسی مجوز)
    async function startCameraFlow() {
        const permit  = document.getElementById('avaScanPermit');
        const hint    = document.getElementById('avaScanPermitHint');
        if (hint) { hint.style.display = 'none'; hint.textContent = ''; }

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            showScanPermit('Your browser does not support the camera. Please use a recent Chrome or Safari.', true);
            return;
        }
        if (!avaIsSecureForCamera()) {
            showScanPermit('The camera requires the site to be opened over HTTPS.', true);
            return;
        }

        // مستقیم درخواست بده: چون این کد داخل کلیک کاربر اجرا می‌شود،
        // مرورگر پرامپت دوربین را نمایش می‌دهد. اگر رد شد، صفحه‌ی راهنما می‌آید.
        if (permit) permit.style.display = 'none';
        const ok = await startScanCamera();
        if (!ok) {
            // handleCameraError خودش پیام مناسب را نشان داده است
            return;
        }
        if (permit) permit.style.display = 'none';
    }

    function showScanPermit(text, isError, showFileFallback) {
        const permit  = document.getElementById('avaScanPermit');
        const textEl  = document.getElementById('avaScanPermitText');
        const btn     = document.getElementById('avaScanPermitBtn');
        const hint    = document.getElementById('avaScanPermitHint');
        const foot    = document.getElementById('avaScanFoot');
        const fileBtn = document.getElementById('avaScanFileBtn');
        if (permit) permit.style.display = 'flex';
        if (foot)   foot.textContent = 'Point the camera at the QR code';
        if (isError) {
            if (textEl) textEl.textContent = 'Camera access required';
            if (hint) {
                hint.style.display = 'block';
                // حفظ خطوط جدید
                hint.innerHTML = '<i class="fas fa-info-circle"></i> ' +
                    String(text).replace(/\n/g, '<br>');
            }
        } else {
            if (textEl) textEl.textContent = text;
            if (hint) hint.style.display = 'none';
        }
        if (btn) btn.style.display = 'inline-flex';
        if (fileBtn) fileBtn.style.display = showFileFallback ? 'inline-flex' : 'none';
    }

    // اسکن QR از روی عکس انتخاب‌شده (fallback وقتی دوربین در دسترس نیست)
    async function handleScanFile(input) {
        const file = input.files && input.files[0];
        if (!file) return;
        await ensureJsQR();
        const img = new Image();
        const reader = new FileReader();
        reader.onload = (e) => {
            img.onload = () => {
                try {
                    const cv = document.createElement('canvas');
                    const ctx = cv.getContext('2d', { willReadFrequently: true });
                    cv.width = img.naturalWidth; cv.height = img.naturalHeight;
                    ctx.drawImage(img, 0, 0);
                    const data = ctx.getImageData(0, 0, cv.width, cv.height);
                    let code = null;
                    if (window.jsQR) code = window.jsQR(data.data, cv.width, cv.height);
                    if (code && code.data) { onScanResult(code.data); }
                    else {
                        if (typeof showGlobalToast === 'function') showGlobalToast('No QR code found in the image', 'error');
                        else alert('No QR code found in the image');
                    }
                } catch(err) {
                    if (typeof showGlobalToast === 'function') showGlobalToast('Error processing image', 'error');
                }
            };
            img.src = e.target.result;
        };
        reader.readAsDataURL(file);
        input.value = '';
    }

    // این تابع با کلیک کاربر صدا زده می‌شود => پرامپت واقعی دوربین باز می‌شود
    async function requestScanCamera() {
        const permit = document.getElementById('avaScanPermit');
        const btn    = document.getElementById('avaScanPermitBtn');
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Connecting...'; }
        const ok = await startScanCamera();
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-unlock"></i> Allow Access'; }
        if (ok && permit) permit.style.display = 'none';
    }

    async function startScanCamera() {
        stopScanCamera();
        const video = document.getElementById('avaScanVideo');
        const foot  = document.getElementById('avaScanFoot');

        // ===== تنظیمات ضروری برای اندروید: باید قبل از play اعمال شوند =====
        // برخی نسخه‌های اندروید کروم بدون این ویژگی‌ها ویدیوی دوربین را پخش نمی‌کنند
        if (video) {
            video.setAttribute('playsinline', '');
            video.setAttribute('webkit-playsinline', '');
            video.setAttribute('muted', '');
            video.setAttribute('autoplay', '');
            video.muted = true;
            video.playsInline = true;
        }

        // زنجیره‌ی constraintها: از دقیق به ساده. اگر اندروید environment دقیق را رد کند،
        // به گزینه‌های ساده‌تر برمی‌گردیم تا دوربین حتماً باز شود.
        const facing = avaScanUsingBack ? 'environment' : 'user';
        const constraintChain = [];
        if (avaScanDeviceId) constraintChain.push({ audio: false, video: { deviceId: { exact: avaScanDeviceId } } });
        constraintChain.push(
            { audio: false, video: { facingMode: { ideal: facing }, width: { ideal: 1280 }, height: { ideal: 720 } } },
            { audio: false, video: { facingMode: facing } },
            { audio: false, video: { facingMode: { ideal: facing } } },
            { audio: false, video: true }  // آخرین راه: هر دوربینی که موجود است
        );

        let lastErr = null;
        let gotStream = false;

        for (const constraints of constraintChain) {
            try {
                avaScanStream = await navigator.mediaDevices.getUserMedia(constraints);
                gotStream = true;
                break;
            } catch (err) {
                lastErr = err;
                // اگر مشکل مجوز/امنیت است، تلاش بیشتر بی‌فایده است
                if (err && (err.name === 'NotAllowedError' || err.name === 'SecurityError')) break;
                // در غیر این صورت با constraint ساده‌تر دوباره تلاش کن (مخصوص اندروید)
            }
        }

        // تلاش نهایی: انتخاب مستقیم دوربین پشت از فهرست دستگاه‌ها
        if (!gotStream && lastErr && lastErr.name !== 'NotAllowedError' && lastErr.name !== 'SecurityError') {
            try {
                const devs = await navigator.mediaDevices.enumerateDevices();
                const cams = devs.filter(d => d.kind === 'videoinput');
                if (cams.length) {
                    let pick = cams[0];
                    if (avaScanUsingBack) {
                        pick = cams.find(c => /back|rear|environment|behind/i.test(c.label || '')) || cams[cams.length - 1];
                    } else {
                        pick = cams.find(c => /front|user|face/i.test(c.label || '')) || cams[0];
                    }
                    avaScanDeviceId = pick.deviceId;
                    avaScanStream = await navigator.mediaDevices.getUserMedia({ audio: false, video: { deviceId: { exact: pick.deviceId } } });
                    gotStream = true;
                }
            } catch (e2) { lastErr = e2 || lastErr; }
        }

        if (!gotStream) {
            console.error('Camera error:', lastErr);
            handleCameraError(lastErr || new Error('Unknown camera error'));
            return false;
        }

        try {
            video.srcObject = avaScanStream;

            // منتظر آماده‌شدن متادیتای ویدیو بمان (اندروید بدون این، ابعاد صفر می‌دهد)
            await new Promise((resolve) => {
                let done = false;
                const finish = () => { if (!done) { done = true; resolve(); } };
                if (video.readyState >= 1) { finish(); return; }
                video.onloadedmetadata = finish;
                setTimeout(finish, 1500); // محافظ زمانی
            });

            // تلاش برای play با چند بار retry (برخی اندرویدها بار اول reject می‌کنند)
            try { await video.play(); }
            catch (e) {
                await new Promise(r => setTimeout(r, 150));
                try { await video.play(); } catch (e2) { /* ادامه بده */ }
            }

            if (foot) foot.textContent = 'Point the camera at the QR code';
            video.style.transform = avaScanUsingBack ? 'none' : 'scaleX(-1)';
            const flipBtn = document.getElementById('avaScanFlip');
            if (flipBtn) flipBtn.style.display = 'flex';

            // توجه: قبلاً اینجا از BarcodeDetector بومی مرورگر هم استفاده می‌شد،
            // اما رفتار آن بین دستگاه‌ها به‌شدت ناپایدار بود (روی برخی اندرویدها
            // خطا می‌داد، روی iOS اصلاً وجود ندارد) و تشخیص دقیق اینکه کدام مسیر
            // واقعاً در حال اجراست را سخت می‌کرد. حذف شد؛ حالا همه‌ی پلتفرم‌ها
            // از همان یک مسیر ساده و تست‌شده (jsQR روی فریم‌های canvas) عبور
            // می‌کنند تا رفتار یکسان و قابل‌پیش‌بینی باشد.
            _avaScanFrameCount = 0;
            _avaScanStartTs = Date.now();
            try {
                await ensureJsQR();
            } catch (e) { /* ادامه بده، scanLoop خودش خطا را نشان می‌دهد */ }
            if (!window.jsQR) {
                if (foot) foot.textContent = 'Scan library failed to load — check your connection and reopen this screen.';
            }
            scanLoop();
            return true;
        } catch (err) {
            console.error('Camera play error:', err);
            handleCameraError(err);
            return false;
        }
    }

    function handleCameraError(err) {
        const isStandalone = window.matchMedia('(display-mode: standalone)').matches
                             || window.navigator.standalone === true;
        const isAndroid = /android/i.test(navigator.userAgent);
        const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent);

        let msg;
        switch (err && err.name) {
            case 'NotAllowedError':
            case 'SecurityError':
                if (isStandalone && isAndroid) {
                    msg = 'Camera access is blocked for this app. To enable it in installed (PWA) mode:\n'
                        + '1) Open Chrome menu (three dots) -> Site settings.\n'
                        + '2) Or on phone: Settings -> Apps -> Chrome -> Permissions -> Camera -> Allow.\n'
                        + '3) Then fully close and reopen the app.\n\n'
                        + 'Or pick a QR image from the gallery below.';
                } else if (isStandalone && isIOS) {
                    msg = 'Camera access is blocked. Go to your phone Settings -> this app (or Safari) -> Camera -> Allow, then reopen the app.\n\n'
                        + 'Or pick a QR image from the gallery below.';
                } else {
                    msg = 'Camera access was denied. Tap the lock icon next to the address bar, set Camera to Allow, then try again.\n\n'
                        + 'Or pick a QR image from the gallery below.';
                }
                break;
            case 'NotFoundError':
            case 'OverconstrainedError':
                msg = 'No camera found. You can pick a QR image from the gallery.';
                break;
            case 'NotReadableError':
                msg = 'The camera is being used by another app. Close it and try again, or pick a QR image.';
                break;
            default:
                msg = 'Camera error. You can pick a QR image from the gallery.';
        }
        showScanPermit(msg, true, true); // showFileFallback = true
    }

    // کتابخانه‌ی jsQR: آدرس قبلی (jsQR.min.js) روی CDN وجود ندارد و ۴۰۴ می‌داد،
    // بنابراین حتی وقتی دوربین باز می‌شد هیچ کدی خوانده نمی‌شد. اینجا چند آدرس
    // پشت سر هم امتحان می‌شود تا حتماً یکی بارگذاری شود.
    function ensureJsQR() {
        if (window.jsQR) return Promise.resolve();
        if (avaJsqrLoading) return avaJsqrLoading;
        // نکته‌ی مهم: کتابخانه‌های jsQR/qrcodejs قبلاً از CDNهای خارجی
        // (jsdelivr/unpkg) بارگذاری می‌شدند که در شبکه‌ی ایران معمولاً
        // فیلتر/کند هستند و باعث می‌شد اسکنر QR هرگز کد را نخواند (چون
        // window.jsQR هیچ‌وقت تعریف نمی‌شد). حالا این فایل‌ها به‌صورت
        // محلی از assets/js/vendor/ سرو می‌شوند تا مستقل از فیلترینگ کار کنند.
        const urls = [
            'assets/js/vendor/jsQR.js',
            'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js',
            'https://unpkg.com/jsqr@1.4.0/dist/jsQR.js'
        ];
        avaJsqrLoading = new Promise((resolve) => {
            let i = 0;
            const tryNext = () => {
                if (window.jsQR || i >= urls.length) { resolve(); return; }
                const sc = document.createElement('script');
                sc.src = urls[i++];
                sc.onload  = () => { window.jsQR ? resolve() : tryNext(); };
                sc.onerror = () => tryNext();
                document.head.appendChild(sc);
            };
            tryNext();
        });
        return avaJsqrLoading;
    }

    async function scanLoop() {
        const video = document.getElementById('avaScanVideo');
        const foot  = document.getElementById('avaScanFoot');
        if (!video || !avaScanStream) return;

        try {
            if (!window.jsQR) {
                // کتابخانه هنوز لود نشده؛ یک بار دیگر امتحان کن (مثلاً اتصال اول قطع بوده)
                await ensureJsQR();
            }
            if (window.jsQR && video.readyState >= 2) {
                const w = video.videoWidth, h = video.videoHeight;
                if (w && h) {
                    if (!_avaCanvas) { _avaCanvas = document.createElement('canvas'); _avaCtx = _avaCanvas.getContext('2d', { willReadFrequently: true }); }
                    _avaCanvas.width = w; _avaCanvas.height = h;
                    _avaCtx.drawImage(video, 0, 0, w, h);
                    const img = _avaCtx.getImageData(0, 0, w, h);
                    const code = window.jsQR(img.data, w, h, { inversionAttempts: 'attemptBoth' });
                    _avaScanFrameCount++;
                    if (code && code.data) { onScanResult(code.data); return; }
                }
            }
        } catch (e) {
            // یک خطای واقعی رخ داده (نه فقط «کدی پیدا نشد») — روی صفحه نشانش بده
            // به‌جای بی‌صدا ادامه دادن، تا در صورت بروز دوباره‌ی مشکل بشود دید چرا.
            if (foot) foot.textContent = 'Scan error: ' + (e && e.message ? e.message : 'unknown') + ' — retrying…';
        }

        // اگر بعد از حدود ۸ ثانیه هنوز حتی یک فریم هم پردازش نشده، یعنی خود حلقه‌ی
        // تشخیص کار نمی‌کند (کتابخانه لود نشده/ویدیو آماده نیست). این حالت را جدا
        // نشان می‌دهیم چون راه‌حلش فرق دارد.
        if (_avaScanFrameCount === 0 && Date.now() - _avaScanStartTs > 8000 && foot) {
            foot.textContent = window.jsQR
                ? 'Still trying to read the frame — make sure the QR fills the frame and is well lit.'
                : 'Scan library did not load. Check your internet connection and reopen this screen.';
        }
        // مهم: قبلاً اگر فریم‌ها به‌درستی پردازش می‌شدند ولی هیچ‌وقت کدی داخلشان
        // پیدا نمی‌شد (یعنی دوربین/کتابخانه کاملاً سالم کار می‌کنند، فقط تصویر
        // روبه‌رویشان قابل‌تشخیص نیست)، این پیام هرگز نشان داده نمی‌شد چون شرط
        // بالا فقط حالت «هیچ فریمی پردازش نشده» را می‌گرفت — نتیجه این بود که
        // کاربر فقط متن اولیه‌ی ثابت را می‌دید و هیچ‌وقت هیچ پیام دیگری نمی‌آمد،
        // درست مثل چیزی که گزارش شد. حالا بعد از ۱۵ ثانیهٔ اسکن ناموفق (حتی اگر
        // فریم‌ها عادی پردازش شوند) یک راهنمای واقعی نشان داده می‌شود.
        else if (_avaScanFrameCount > 0 && Date.now() - _avaScanStartTs > 15000 && foot) {
            foot.textContent = 'No code found yet — move closer, avoid glare on the other screen, and make sure the whole QR is inside the frame.';
        }

        avaScanRAF = requestAnimationFrame(scanLoop);
    }

    function onScanResult(value) {
        // ویبره و توقف
        try { if (navigator.vibrate) navigator.vibrate(120); } catch(e){}
        stopScanCamera();
        const foot = document.getElementById('avaScanFoot');
        if (foot) foot.textContent = 'Code detected ✔';

        // مدیریت نتیجه: اگر لینک داخلی باشد برو، اگر شماره حساب باشد به ارسال وجه بده
        handleScannedValue(value);
    }

    async function handleScannedValue(value) {
      try {
        if (!value) { closeScanner(); return; }

        let acct = '';
        let name = '';

        // تلاش برای parse کردن payload JSON اپ
        try {
            const obj = JSON.parse(value);
            if (obj && obj.t === 'avapay_pay') {
                acct = String(obj.acct || '').trim();
                name = String(obj.name || '').trim();
            }
        } catch(e) { /* JSON نبود */ }

        // اگر JSON نبود، بررسی URL داخلی یا شماره حساب خام
        if (!acct) {
            try {
                const u = new URL(value, window.location.origin);
                if (u.origin === window.location.origin) {
                    const st = u.searchParams.get('send_to');
                    if (st) acct = st;
                    else { closeScanner(); window.location.href = u.href; return; }
                }
            } catch(e) {}
        }
        if (!acct) {
            const raw = String(value).trim();
            if (/^AV\d{4,}$/i.test(raw) || /^\d{4,}$/.test(raw)) acct = raw;
        }

        if (!acct) {
            if (typeof showGlobalToast === 'function') showGlobalToast('Invalid QR code', 'error');
            closeScanner();
            return;
        }

        // باز کردن فرم ارسال با اطلاعات گیرنده
        await openQrSend(acct, name);
      } catch(e) {
        // قبلاً یک خطای غیرمنتظره‌ی جاوااسکریپت اینجا کاملاً بی‌صدا بود:
        // اسکن با موفقیت کد را می‌خواند ولی چون این تابع بدون هیچ پیامی
        // متوقف می‌شد، کاربر فقط می‌دید «هیچ اتفاقی نیفتاد». حالا حتماً
        // یک پیام قابل‌مشاهده نشان داده می‌شود.
        if (typeof showGlobalToast === 'function') showGlobalToast('Error processing the code: ' + (e && e.message ? e.message : 'unknown'), 'error');
        closeScanner();
      }
    }

    // باز کردن مودال ارسال پول با اطلاعات گیرنده اسکن‌شده
    async function openQrSend(acct, name) {
      try {
        stopScanCamera();
        const overlay = document.getElementById('avaScanOverlay');
        if (overlay) overlay.style.display = 'none';

        const modal = document.getElementById('avaQrSendModal');
        if (!modal) {
            // fallback: به داشبورد برو
            window.location.href = '/ledor/dashboard.php?send_to=' + encodeURIComponent(acct);
            return;
        }
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';

        const nameEl = document.getElementById('avaQrSendName');
        const acctEl = document.getElementById('avaQrSendAcct');
        const avEl   = document.getElementById('avaQrSendAvatar');
        const fb     = document.getElementById('avaQrFeedback');
        if (fb) fb.innerHTML = '';
        if (acctEl) acctEl.textContent = acct;
        if (nameEl) nameEl.textContent = name || 'Loading name...';
        if (avEl) avEl.textContent = (name || '?').trim().charAt(0) || '?';

        window._qrSendAcct = acct;

        // اگر نام در payload نبود، از سرور بگیر
        if (!name) {
            try {
                const res = await fetch('/ledor/api/transaction.php?action=validateRecipient&recipient=' + encodeURIComponent(acct));
                const data = await res.json();
                if (data && data.success && data.user) {
                    const full = data.user.full_name || ((data.user.first_name || '') + ' ' + (data.user.last_name || ''));
                    if (nameEl) nameEl.textContent = full.trim() || acct;
                    if (avEl) avEl.textContent = (full.trim() || '?').charAt(0);
                    window._qrSendName = full.trim();
                } else {
                    if (nameEl) nameEl.textContent = (data && data.message) ? data.message : 'User not found';
                }
            } catch(e) {
                if (nameEl) nameEl.textContent = acct;
            }
        } else {
            window._qrSendName = name;
        }
      } catch(e) {
        if (typeof showGlobalToast === 'function') showGlobalToast('Error opening send form: ' + (e && e.message ? e.message : 'unknown'), 'error');
      }
    }

    function closeQrSend() {
        const modal = document.getElementById('avaQrSendModal');
        if (modal) modal.style.display = 'none';
        document.body.style.overflow = '';
    }

    async function submitQrSend() {
        const acct = window._qrSendAcct;
        const amount = parseFloat(document.getElementById('avaQrAmount').value || '0');
        const currency = document.getElementById('avaQrCurrency').value;
        const desc = document.getElementById('avaQrDesc').value || '';
        const fb = document.getElementById('avaQrFeedback');
        const btn = document.getElementById('avaQrSubmit');

        if (!amount || amount <= 0) {
            if (fb) { fb.style.color = '#FF6B6B'; fb.textContent = 'Please enter a valid amount'; }
            return;
        }
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال ارسال...'; }

        try {
            const res = await fetch('/ledor/api/transaction.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'send',
                    recipient: acct,
                    amount: amount,
                    currency: currency,
                    description: desc
                })
            });
            const data = await res.json();
            if (data && data.success) {
                if (fb) { fb.style.color = '#4CD964'; fb.innerHTML = '<i class="fas fa-check-circle"></i> ' + (data.message || 'Sent'); }
                setTimeout(() => { closeQrSend(); if (typeof loadInvoices === 'function') location.reload(); }, 1400);
            } else {
                if (fb) { fb.style.color = '#FF6B6B'; fb.textContent = (data && data.message) || 'Send failed'; }
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Money'; }
            }
        } catch(e) {
            if (fb) { fb.style.color = '#FF6B6B'; fb.textContent = 'Server connection error'; }
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Money'; }
        }
    }

    window.openScanner        = openScanner;
    window.closeScanner       = closeScanner;
    window.stopScanCamera     = stopScanCamera;
    window.requestScanCamera  = requestScanCamera;
    window.flipScanCamera     = flipScanCamera;
    window.handleScanFile     = handleScanFile;
    window.scanChoose         = scanChoose;
    window.renderMyReceiveQR  = renderMyReceiveQR;
    window.shareMyAccount     = shareMyAccount;
    window.closeQrSend        = closeQrSend;
    window.submitQrSend       = submitQrSend;

    // ==================== Event Listeners ====================
    document.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('page') === 'currency-rate') showCurrencyRate();
        
        window.addEventListener('popstate', function(event) {
            if (event.state && event.state.page === 'currency-rate') showCurrencyRate();
            else hideCurrencyRate();
        });
        
        const savedPending = sessionStorage.getItem('arad_pending_received');
        if (savedPending && parseInt(savedPending) > 0) updateAradBadgeInMenu(true);
        
        fetch('/ledor/api/offer_api.php?action=get_my_received_offers')
            .then(res => res.json())
            .then(result => {
                if (result.success && result.offers) {
                    lastGlobalPendingCount = result.offers.filter(o => o.status === 'pending').length;
                    if (lastGlobalPendingCount > 0) updateAradBadgeInMenu(true);
                }
            }).catch(console.error);
        
        if (!globalPollingInterval) {
            globalPollingInterval = setInterval(checkAradOffersGlobally, 10000);
        }
    });
    </script>
    <!-- ==================================================================
         AVA UPLOAD PROGRESS — نوار/رینگ درصد آپلود فیش + پیام موفقیت
         استفاده: avaShowUploadProgress(title) → avaUploadWithProgress(url, formData)
                  → (خودکار) → avaHideUploadProgress(true/false, message)
         ================================================================== -->
    <style>
    .ava-upload-progress-overlay{position:fixed;inset:0;background:rgba(10,6,20,.72);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);z-index:99999;display:none;align-items:center;justify-content:center;}
    .ava-upload-progress-overlay.show{display:flex;animation:avaUpFadeIn .2s ease;}
    @keyframes avaUpFadeIn{from{opacity:0;}to{opacity:1;}}
    .ava-upload-progress-card{width:min(300px,86vw);background:linear-gradient(145deg,#1c1030,#2a1650);border:1px solid rgba(255,255,255,.08);border-radius:22px;padding:26px 22px;text-align:center;box-shadow:0 20px 50px rgba(0,0,0,.5);}
    .ava-upload-progress-ring{position:relative;width:92px;height:92px;margin:0 auto 16px;}
    .ava-upload-progress-ring svg{transform:rotate(-90deg);width:92px;height:92px;}
    .ava-upload-progress-ring circle{fill:none;stroke-width:8;}
    .ava-upload-progress-ring .bg{stroke:rgba(255,255,255,.08);}
    .ava-upload-progress-ring .fg{stroke:url(#avaUpGrad);stroke-linecap:round;transition:stroke-dashoffset .2s ease;}
    .ava-upload-progress-pct{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:1.1rem;font-weight:800;color:#fff;}
    .ava-upload-progress-title{font-size:.92rem;font-weight:700;color:#fff;margin-bottom:4px;}
    .ava-upload-progress-sub{font-size:.74rem;color:rgba(255,255,255,.55);}
    .ava-upload-progress-check{font-size:2.3rem;color:#33d17a;animation:avaUpPop .4s ease;}
    @keyframes avaUpPop{0%{transform:scale(.3);opacity:0;}70%{transform:scale(1.15);opacity:1;}100%{transform:scale(1);opacity:1;}}
    </style>
    <div class="ava-upload-progress-overlay" id="avaUploadOverlay">
      <div class="ava-upload-progress-card">
        <div class="ava-upload-progress-ring" id="avaUploadRingWrap">
          <svg viewBox="0 0 92 92">
            <defs><linearGradient id="avaUpGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#8b5cf6"/><stop offset="100%" stop-color="#ec4899"/></linearGradient></defs>
            <circle class="bg" cx="46" cy="46" r="40"></circle>
            <circle class="fg" id="avaUploadRingFg" cx="46" cy="46" r="40" stroke-dasharray="251.2" stroke-dashoffset="251.2"></circle>
          </svg>
          <div class="ava-upload-progress-pct" id="avaUploadPct">0%</div>
        </div>
        <div class="ava-upload-progress-title" id="avaUploadTitle">در حال آپلود فیش...</div>
        <div class="ava-upload-progress-sub" id="avaUploadSub">لطفاً صبر کنید</div>
      </div>
    </div>
    <script>
    (function(){
      var R = 40, C = 2 * Math.PI * R;
      window.avaShowUploadProgress = function(title){
        var ov = document.getElementById('avaUploadOverlay');
        if (!ov) return;
        var t = document.getElementById('avaUploadTitle'); if (t) t.textContent = title || 'در حال آپلود فیش...';
        var s = document.getElementById('avaUploadSub'); if (s) s.textContent = 'لطفاً صبر کنید';
        var fg = document.getElementById('avaUploadRingFg');
        if (fg) { fg.setAttribute('stroke-dasharray', C); fg.setAttribute('stroke-dashoffset', C); }
        var pctEl = document.getElementById('avaUploadPct');
        if (pctEl) pctEl.innerHTML = '0%';
        ov.classList.add('show');
      };
      window.avaSetUploadProgress = function(pct){
        pct = Math.max(0, Math.min(100, Math.round(pct)));
        var fg = document.getElementById('avaUploadRingFg');
        if (fg) fg.setAttribute('stroke-dashoffset', C - (C * pct / 100));
        var pctEl = document.getElementById('avaUploadPct');
        if (pctEl) pctEl.textContent = pct + '%';
      };
      window.avaHideUploadProgress = function(success, message){
        var ov = document.getElementById('avaUploadOverlay');
        if (!ov) return;
        if (success) {
          var pctEl = document.getElementById('avaUploadPct');
          if (pctEl) pctEl.innerHTML = '<i class="fas fa-check ava-upload-progress-check"></i>';
          var t = document.getElementById('avaUploadTitle'); if (t) t.textContent = message || 'با موفقیت ارسال شد ✅';
          var s = document.getElementById('avaUploadSub'); if (s) s.textContent = '';
          setTimeout(function(){ ov.classList.remove('show'); }, 750);
        } else {
          ov.classList.remove('show');
        }
      };

      // آپلود با XMLHttpRequest چون fetch() رویداد پیشرفت آپلود (upload progress) ندارد
      window.avaUploadWithProgress = function(url, formData, opts){
        opts = opts || {};
        return new Promise(function(resolve, reject){
          var xhr = new XMLHttpRequest();
          xhr.open('POST', url, true);
          xhr.upload.onprogress = function(e){
            if (e.lengthComputable) {
              var pct = (e.loaded / e.total) * 100;
              window.avaSetUploadProgress(pct);
              if (typeof opts.onProgress === 'function') opts.onProgress(pct);
            }
          };
          xhr.onload = function(){
            try { resolve(JSON.parse(xhr.responseText)); }
            catch(e){ reject(new Error('پاسخ سرور نامعتبر است')); }
          };
          xhr.onerror = function(){ reject(new Error('خطای شبکه')); };
          xhr.send(formData);
        });
      };
    })();

    /* ===================================================================
       (آپدیت جدید) بازگشت با کشیدن انگشت از لبه‌ی چپ به راست
       -------------------------------------------------------------------
       اگر مدال/شیت بازی وجود داشته باشد، ابتدا رویی‌ترین مدال بسته می‌شود
       (به ترتیب z-index)، و فقط اگر هیچ مدالی باز نباشد به صفحه‌ی قبل
       برمی‌گردیم. این کار باعث می‌شود در مدال‌های تودرتو (مثل مدال اخبار
       و مدال خواندن خبر) هر بار یک لایه بسته شود، نه اینکه کاربر ناگهان
       از کل صفحه خارج شود.
       =================================================================== */
    /* =================================================================
       بستنِ تضمینیِ مودال‌های تمام‌صفحه (.ava-fs-modal)
       -----------------------------------------------------------------
       این بلوک عمداً کاملاً مستقل است و به هیچ تابعی در dashboard.php
       (مثل avaCloseFsModal) وابسته نیست. دلیلش:
         • دکمه‌ی «خروج» با onclick اینلاین کار می‌کرد؛ اگر آن تابع به هر
           دلیلی در دسترس نباشد یا اسکریپت صفحه وسط کار خطا بدهد، دکمه
           کاملاً بی‌اثر می‌شود و کاربر داخل مودال حبس می‌شود.
         • روی موبایل ممکن است رویداد click اصلاً ساخته نشود (لغزش انگشت)،
           پس touchend هم جداگانه پوشش داده شده است.
       بستن مودال فقط برداشتن کلاس open و آزادکردن اسکرول بدنه است، پس
       این پیاده‌سازیِ مستقل دقیقاً همان کار را می‌کند.
       ================================================================= */
    (function(){
      if (window.__avaFsCloseGuard) return;
      window.__avaFsCloseGuard = true;

      function closeFs(modal){
        if (!modal) return false;
        modal.classList.remove('open');
        // اگر مودال دیگری باز نمانده، اسکرول صفحه آزاد شود
        if (!document.querySelector('.ava-fs-modal.open')) {
          document.body.style.overflow = '';
        }
        // اگر تابع اختصاصی صفحه وجود دارد، پاک‌سازی‌های جانبی‌اش هم انجام شود
        if (typeof window.avaCloseFsModal === 'function' && modal.id) {
          try { window.avaCloseFsModal(modal.id); } catch (e) {}
        }
        return true;
      }

      function handleFrom(el){
        if (!el || !el.closest) return false;
        var btn = el.closest('.ava-fs-close');
        if (btn) return closeFs(btn.closest('.ava-fs-modal'));
        return false;
      }

      document.addEventListener('click', function(e){
        if (handleFrom(e.target)) { e.preventDefault(); e.stopPropagation(); }
      }, true);

      document.addEventListener('touchend', function(e){
        var t = e.changedTouches && e.changedTouches[0];
        if (!t) return;
        var el = document.elementFromPoint(t.clientX, t.clientY);
        handleFrom(el);
      }, { passive: true });
    })();

    (function(){
      if (window.__avaSwipeBackReady) return;
      window.__avaSwipeBackReady = true;

      var EDGE  = 44;   // پهنای ناحیه‌ی لبه در حالت «صفحه‌ی عادی»
      var DIST  = 64;   // حداقل مسافت افقی برای شمرده‌شدن به‌عنوان بازگشت
      var SLOPE = 0.7;  // حرکت باید عمدتاً افقی باشد نه عمودی

      var sx = 0, sy = 0;
      var tracking = false, committed = false;
      var scroller = null, scrollerStart = 0;

      /* نزدیک‌ترین والدی که واقعاً افقی اسکرول می‌شود (مثل ردیف عملیات سریع) */
      function horizontalScroller(node){
        while (node && node !== document.body && node.nodeType === 1) {
          if (node.scrollWidth > node.clientWidth + 4) {
            var ox = window.getComputedStyle(node).overflowX;
            if (ox === 'auto' || ox === 'scroll') return node;
          }
          node = node.parentNode;
        }
        return null;
      }

      /* رویی‌ترین لایه‌ی باز را پیدا و می‌بندد. اگر چیزی نبود false برمی‌گرداند. */
      function closeTopLayer(){
        var layers = [];

        function pushEl(el, closeFn){
          if (!el) return;
          layers.push({ el: el, close: closeFn || function(){ avaGenericCloseLayer(el); } });
        }

        // بستن عمومی و امن یک لایه: هم کلاس‌های نمایش برداشته می‌شود و هم اگر
        // با style اینلاین باز شده بود، همان پاک می‌شود.
        // نکته‌ی مهم: قبلاً برای .modal-overlay فقط style.display='none' ست
        // می‌شد در حالی که این مودال‌ها با کلاس «open» باز می‌شوند. نتیجه این
        // بود که کلاس open باقی می‌ماند و استایل اینلاین هم رویش می‌نشست، پس
        // آن مودال دیگر هیچ‌وقت باز نمی‌شد. حالا هر دو پاک می‌شوند.
        function avaGenericCloseLayer(el){
          el.classList.remove('open', 'active', 'show');
          if (el.style && el.style.display && el.style.display !== 'none') el.style.display = 'none';
          else if (el.style) el.style.display = '';
        }
        window.avaGenericCloseLayer = avaGenericCloseLayer;

        document.querySelectorAll('.ava-fs-modal.open').forEach(function(el){
          pushEl(el, function(){
            if (typeof avaCloseFsModal === 'function') avaCloseFsModal(el.id);
            else avaGenericCloseLayer(el);
          });
        });
        document.querySelectorAll('.ava-sheet.open').forEach(function(el){
          pushEl(el, function(){
            if (typeof avaCloseSheet === 'function') avaCloseSheet(el.id);
            else avaGenericCloseLayer(el);
          });
        });
        document.querySelectorAll('.news-modal-overlay.open').forEach(function(el){
          pushEl(el, function(){
            if (typeof closeNewsModal === 'function') closeNewsModal();
            else avaGenericCloseLayer(el);
          });
        });
        document.querySelectorAll('.tp-modal-overlay.open, .ava-sec-modal-overlay.open').forEach(function(el){
          pushEl(el);
        });

        // (اصلاح) این چند مودال قبلاً اصلاً در این لیست نبودند، پس کشیدن
        // انگشت برای بازگشت وقتی بازند یا کاری نمی‌کرد یا به‌جای بستن‌شان
        // مرورگر را از کل صفحه خارج می‌کرد:
        // - avag-modal: مودال اعلان‌ها/ویرایش پروفایل بالای صفحه
        // - ava-story-viewer: نمایش استوری
        // - arf-modal-overlay: پنل «زیرمجموعه‌های شما» که از روی بنر باز می‌شود
        document.querySelectorAll('.avag-modal.open').forEach(function(el){
          pushEl(el, function(){
            if (typeof avaCloseGModal === 'function') avaCloseGModal(el.id);
            else avaGenericCloseLayer(el);
          });
        });
        document.querySelectorAll('.ava-story-viewer.open').forEach(function(el){
          pushEl(el, function(){
            if (typeof avaStoryClose === 'function') avaStoryClose();
            else avaGenericCloseLayer(el);
          });
        });
        document.querySelectorAll('.arf-modal-overlay.arf-open').forEach(function(el){
          pushEl(el, function(){
            if (el.id === 'arfSettleModal' && typeof arfCloseSettleModal === 'function') arfCloseSettleModal();
            else if (typeof arfClosePanel === 'function') arfClosePanel();
            else el.classList.remove('arf-open');
          });
        });

        // مودال جزئیات دوست (friends.js): با insertAdjacentHTML ساخته می‌شود
        // و کلاس open ندارد — وجودش در صفحه یعنی باز است.
        var __friendModal = document.getElementById('friendDetailsModal');
        if (__friendModal) {
          pushEl(__friendModal, function(){
            if (window.friendsManager && typeof window.friendsManager.closeModal === 'function') window.friendsManager.closeModal();
            else __friendModal.remove();
          });
        }
        // مودال «رد پیشنهاد» (notification-system.js): با id پویا ساخته می‌شود
        document.querySelectorAll('[id^="rejectModal_"]').forEach(function(el){
          pushEl(el, function(){ el.remove(); });
        });

        // مودال «دسترسی سریع» پنل ادمین: باید با تابع اختصاصی خودش بسته شود،
        // چون هنگام باز شدن، محتوای تب را جابه‌جا کرده و باید سر جایش برگردد.
        document.querySelectorAll('.ax-quick-modal-overlay.open').forEach(function(el){
          pushEl(el, function(){
            if (typeof axCloseQuickModal === 'function') axCloseQuickModal();
            else avaGenericCloseLayer(el);
          });
        });

        // مودال‌های پنل ادمین که با کلاس open باز می‌شوند
        document.querySelectorAll('.modal-overlay.open').forEach(function(el){
          pushEl(el, function(){
            if (typeof closeModal === 'function' && el.id) closeModal(el.id);
            else avaGenericCloseLayer(el);
          });
        });

        // لایه‌هایی که فقط با style اینلاین نمایش داده می‌شوند
        // (مثل مودال جزئیات معامله، لایت‌باکس تصویر، ویرایشگرها)
        document.querySelectorAll('.axdeal-overlay, .ax-img-lightbox, .modal-overlay, #slideEditorModal, #qaEditorModal').forEach(function(el){
          if (el.classList.contains('open')) return; // بالاتر رسیدگی شد
          var d = window.getComputedStyle(el).display;
          if (d && d !== 'none') {
            pushEl(el, function(){
              if (el.id === 'axDealModal' && typeof axCloseDealModal === 'function') axCloseDealModal();
              else avaGenericCloseLayer(el);
            });
          }
        });

        // پنل اعلان‌ها
        document.querySelectorAll('.notification-overlay.active, .notification-dropdown.active').forEach(function(el){
          pushEl(el);
        });

        if (!layers.length) return false;

        // رویی‌ترین لایه بر اساس z-index محاسبه‌شده انتخاب می‌شود تا در
        // مودال‌های تودرتو هر بار فقط یک لایه بسته شود.
        var top = layers[0], topZ = -1;
        layers.forEach(function(l){
          var z = parseInt(window.getComputedStyle(l.el).zIndex, 10);
          if (isNaN(z)) z = 0;
          if (z >= topZ) { topZ = z; top = l; }
        });

        try { top.close(); } catch(e){ try { top.el.classList.remove('open','active'); } catch(e2){} }
        return true;
      }
      window.avaCloseTopLayer = closeTopLayer;

      function anyLayerOpen(){
        if (document.querySelector('.ava-fs-modal.open, .ava-sheet.open, .news-modal-overlay.open, '
            + '.tp-modal-overlay.open, .ava-sec-modal-overlay.open, .ax-quick-modal-overlay.open, '
            + '.modal-overlay.open, .notification-overlay.active, '
            + '.avag-modal.open, .ava-prof-drawer.open, .ava-story-viewer.open, .arf-modal-overlay.arf-open')) return true;
        if (document.getElementById('friendDetailsModal')) return true;
        if (document.querySelector('[id^="rejectModal_"]')) return true;
        var found = false;
        document.querySelectorAll('.modal-overlay, .axdeal-overlay, .ax-img-lightbox, #slideEditorModal, #qaEditorModal').forEach(function(el){
          if (!found) {
            var d = window.getComputedStyle(el).display;
            if (d && d !== 'none') found = true;
          }
        });
        return found;
      }

      document.addEventListener('touchstart', function(e){
        tracking = false; committed = false; scroller = null;
        if (e.touches.length !== 1) return;

        var t = e.touches[0];
        sx = t.clientX; sy = t.clientY;

        // اگر لمس روی یک کنترل تعاملی شروع شده (دکمه، لینک، ورودی، ...)،
        // اصلاً ژست بازگشت را دنبال نمی‌کنیم.
        // چرا مهم است: وقتی مدالی باز است ما لمس را از «هر جای صفحه» دنبال
        // می‌کنیم؛ اگر انگشت کاربر هنگام زدن دکمه کمی بلغزد، touchmove با
        // preventDefault جلوی تولید رویداد click را می‌گرفت و دکمه‌هایی مثل
        // «خروج» مدال بی‌اثر به‌نظر می‌رسیدند.
        if (t.target && t.target.closest &&
            t.target.closest('button, a, input, select, textarea, label, [role="button"], [onclick]')) {
          return;
        }

        // وقتی مدالی باز است، کشیدن از هر جای صفحه بازگشت محسوب می‌شود؛
        // در صفحه‌ی عادی فقط از لبه‌ی چپ، تا با اسکرول معمولی تداخل نکند.
        if (!anyLayerOpen() && t.clientX > EDGE) return;

        tracking = true;

        // اگر داخل یک ناحیه‌ی افقی‌اسکرول (مثل ردیف عملیات سریع) شروع شده،
        // موقعیت اسکرولش را ذخیره می‌کنیم تا بعداً بفهمیم کاربر لیست را
        // اسکرول کرده یا واقعاً قصد بازگشت داشته است.
        scroller = horizontalScroller(t.target);
        scrollerStart = scroller ? scroller.scrollLeft : 0;
      }, { passive: true });

      document.addEventListener('touchmove', function(e){
        if (!tracking || e.touches.length !== 1) return;
        var t = e.touches[0];
        var dx = t.clientX - sx;
        var dy = Math.abs(t.clientY - sy);

        if (!committed && dx > 16 && dy <= dx * SLOPE) {
          // اگر لیست افقی هنوز جا برای اسکرول دارد، اجازه می‌دهیم اسکرول شود
          if (scroller && scroller.scrollLeft !== scrollerStart) { tracking = false; return; }
          committed = true;
        }

        // جلوگیری از فعال‌شدن ژست بازگشتِ خودِ مرورگر (که باعث می‌شد
        // به‌جای بستن مدال، پس‌زمینه‌ی صفحه با انیمیشن بیرون بیاید)
        if (committed && e.cancelable) e.preventDefault();
      }, { passive: false });

      document.addEventListener('touchend', function(e){
        if (!tracking) return;
        tracking = false;

        var t = e.changedTouches && e.changedTouches[0];
        if (!t) { committed = false; return; }

        var dx = t.clientX - sx;
        var dy = Math.abs(t.clientY - sy);
        var wasCommitted = committed;
        committed = false;

        if (scroller && scroller.scrollLeft !== scrollerStart) return; // کاربر لیست را اسکرول کرد
        if (!wasCommitted && dx < DIST) return;
        if (dx < DIST) return;
        if (dy > dx * SLOPE) return;

        if (closeTopLayer()) return;               // اول لایه‌های باز

        // اگر هیچ لایه‌ای باز نبود، به صفحه‌ی قبل برمی‌گردیم.
        // اگر تاریخچه‌ای وجود نداشته باشد (مثلاً کاربر مستقیم وارد این صفحه شده
        // یا اپ به‌صورت PWA باز شده)، به‌جای اینکه هیچ اتفاقی نیفتد، به داشبورد
        // برمی‌گردیم تا کشیدن انگشت همیشه یک نتیجه‌ی قابل‌پیش‌بینی داشته باشد.
        var here = (location.pathname || '').toLowerCase();
        if (window.history.length > 1) {
          window.history.back();
        } else if (here.indexOf('dashboard.php') === -1) {
          window.location.href = '/ledor/dashboard.php';
        }
      }, { passive: true });

      document.addEventListener('touchcancel', function(){
        tracking = false; committed = false;
      }, { passive: true });
    })();
    </script>
    <?php
}
?>