/* =========================================================================
   dashboard-enhance.js  —  افزونه‌ی داشبورد AvaPay
   ۱) پیمایش راحت گوی‌ها + نمودار انتقال‌های یک‌سال گذشته‌ی هر حساب
   ۲) بنرهای مدیریتی با جای‌گذاری آزاد و اتصال به عملیات سریع
   ۳) مخفی/بازگرداندن بخش‌ها با حفظ جای اصلی
   ۴) هشدار چشم‌گیر صورت‌حساب پرداخت‌نشده
   ========================================================================= */
(function () {
    'use strict';

    var BASE = '/ledor/';
    var LS_HIDDEN = 'avaHiddenSections';

    function $(s, r) { return (r || document).querySelector(s); }
    // نکته‌ی مهم: این تابع قبلاً هیچ‌جا تعریف نشده بود — یعنی هر بار که
    // نمودار «معرفی حساب» داده‌ی واقعی داشت (برای رسم تب‌های ارز) با خطای
    // «$$ is not defined» کرش می‌کرد و به پیام عمومی «اطلاعات نمودار در
    // دسترس نیست» می‌رسید، حتی وقتی پاسخ سرور کاملاً درست و HTTP 200 بود.
    function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function fa(s) {
        return String(s).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; });
    }
    function num(n, dec) {
        var v = Number(n) || 0;
        return fa(v.toLocaleString('en-US', {
            minimumFractionDigits: dec || 0,
            maximumFractionDigits: dec || 0
        }));
    }

    /* =====================================================================
       ۱) پیمایش راحت‌تر «گوی‌ها» (بخش معرفی حساب)
       ===================================================================== */

    /* ---------------------------------------------------------------
       پیمایش گوی‌ها

       دو باگی که باعث هنگ کردن روی موبایل/PWA می‌شد:

       ۱) کدِ کشیدن، رویدادهای لمسی را هم می‌گرفت و با preventDefault
          اسکرول بومی مرورگر را لغو می‌کرد، بعد خودش دستی scrollLeft را
          جابه‌جا می‌کرد. این هم کندتر از اسکرول بومی است و هم با اینرسی
          خود مرورگر تداخل داشت.
          → حالا لمس اصلاً دست نمی‌خورد؛ فقط ماوس (دسکتاپ) کنترل می‌شود.

       ۲) update() روی هر رویداد scroll اجرا می‌شد و scrollWidth /
          clientWidth می‌خواند. این خواندن باعث layout اجباری در هر فریم
          اسکرول می‌شد (forced synchronous reflow) — منبع اصلی لگ.
          → حالا اندازه‌ها کش می‌شوند و به‌روزرسانی با rAF محدود می‌شود.
       --------------------------------------------------------------- */
    var benScroll = {
        grid: null,
        wrap: null,
        hint: null,

        // اندازه‌های کش‌شده — در حین اسکرول از DOM خوانده نمی‌شوند
        maxScroll: 0,
        ticking: false,
        lastPrev: null,
        lastNext: null,
        faded: false,

        init: function () {
            var grid = $('#avaBenGrid');
            if (!grid || grid.dataset.enhanced === '1') return;
            grid.dataset.enhanced = '1';

            if (grid.children.length > 4) grid.classList.add('many');

            var wrap = document.createElement('div');
            wrap.className = 'ava-ben-scroll';
            grid.parentNode.insertBefore(wrap, grid);
            wrap.appendChild(grid);

            this.grid = grid;
            this.wrap = wrap;
            this.hint = $('.ava-ben-swipe-hint');

            var self = this;

            // فقط موقعیت را می‌خواند (ارزان)، اندازه‌ها از کش می‌آیند
            grid.addEventListener('scroll', function () { self.onScroll(); }, { passive: true });

            var ro = null;
            if (typeof ResizeObserver !== 'undefined') {
                ro = new ResizeObserver(function () { self.measure(); });
                ro.observe(grid);
            }
            window.addEventListener('resize', function () { self.measure(); }, { passive: true });
            window.addEventListener('orientationchange', function () {
                setTimeout(function () { self.measure(); }, 250);
            }, { passive: true });

            this.enableMouseDrag();   // ← فقط ماوس؛ لمس کاملاً بومی می‌ماند
            this.enableWheel();
            this.measure();

            var search = $('#avaBenSearch');
            if (search) search.addEventListener('input', function () {
                setTimeout(function () { self.measure(); }, 40);
            });
        },

        /* اندازه‌گیری — فقط وقتی چیدمان عوض می‌شود، نه حین اسکرول */
        measure: function () {
            var g = this.grid;
            if (!g) return;
            this.maxScroll = g.scrollWidth - g.clientWidth;
            var scrollable = this.maxScroll > 4;
            this.wrap.classList.toggle('scrollable', scrollable);
            if (!scrollable) {
                this.wrap.classList.remove('has-prev', 'has-next');
                this.lastPrev = this.lastNext = false;
                return;
            }
            this.lastPrev = this.lastNext = null;   // اجبار به به‌روزرسانی
            this.onScroll();
        },

        /* در حین اسکرول فقط scrollLeft خوانده می‌شود و کلاس‌ها
           تنها وقتی واقعاً تغییر کرده‌اند دست‌کاری می‌شوند */
        onScroll: function () {
            if (this.ticking || this.maxScroll <= 4) return;
            this.ticking = true;
            var self = this;
            requestAnimationFrame(function () {
                self.ticking = false;
                var pos = Math.abs(self.grid.scrollLeft);
                var ratio = pos / self.maxScroll;

                var prev = ratio > 0.02, next = ratio < 0.98;
                if (prev !== self.lastPrev) {
                    self.wrap.classList.toggle('has-prev', prev);
                    self.lastPrev = prev;
                }
                if (next !== self.lastNext) {
                    self.wrap.classList.toggle('has-next', next);
                    self.lastNext = next;
                }
                if (!self.faded && pos > 8 && self.hint) {
                    self.hint.classList.add('faded');
                    self.faded = true;
                }
            });
        },

        /* کشیدن با ماوس — روی لمس هیچ دخالتی نمی‌کند */
        enableMouseDrag: function () {
            var g = this.grid, self = this;
            var active = false, dragging = false, startX = 0, startScroll = 0, moved = 0;
            var lastX = 0, lastT = 0, velocity = 0, raf = null, pid = null;

            function stopGlide() { if (raf) { cancelAnimationFrame(raf); raf = null; } }

            function glide() {
                velocity *= 0.93;
                if (Math.abs(velocity) < 0.15) { raf = null; return; }
                g.scrollLeft -= velocity;
                raf = requestAnimationFrame(glide);
            }

            g.addEventListener('pointerdown', function (e) {
                // ← کلید حل مشکل: لمس و قلم را رها کن
                if (e.pointerType !== 'mouse') return;
                if (e.button !== 0) return;
                stopGlide();
                active = true; dragging = false; moved = 0; velocity = 0;
                startX = lastX = e.clientX;
                startScroll = g.scrollLeft;
                lastT = e.timeStamp;
                pid = e.pointerId;
                // توجه مهم: اینجا عمداً pointer capture گرفته نمی‌شود و کلاس dragging
                // اضافه نمی‌شود. اگر همین ابتدا capture گرفته شود، مرورگر رویداد click
                // را — حتی برای یک کلیک ساده و بدون هیچ جابه‌جایی — به خودِ گرید
                // (به‌جای آیتمِ زیرِ ماوس) هدایت می‌کند و onclick آیتم هرگز اجرا نمی‌شود.
                // به همین دلیل capture و کلاس dragging فقط پس از تشخیص یک کشیدنِ واقعی
                // (در pointermove) فعال می‌شوند.
            });

            g.addEventListener('pointermove', function (e) {
                if (!active || e.pointerType !== 'mouse') return;
                var dx = e.clientX - startX;
                if (Math.abs(dx) > moved) moved = Math.abs(dx);

                // فقط وقتی جابه‌جایی واقعی (بیش از چند پیکسل) رخ داد، وارد حالت کشیدن شو
                if (!dragging && moved > 4) {
                    dragging = true;
                    g.classList.add('dragging');
                    try { g.setPointerCapture(pid); } catch (err) {}
                }
                if (!dragging) return;

                g.scrollLeft = startScroll - dx;

                var dt = e.timeStamp - lastT;
                if (dt > 0) {
                    velocity = 0.7 * velocity + 0.3 * ((e.clientX - lastX) / dt * 16);
                    lastX = e.clientX;
                    lastT = e.timeStamp;
                }
                e.preventDefault();
            });

            function release(e) {
                if (!active) return;
                active = false;
                if (dragging) {
                    dragging = false;
                    g.classList.remove('dragging');
                    try { g.releasePointerCapture(e.pointerId); } catch (err) {}
                    if (Math.abs(velocity) > 0.6) glide();
                }
            }
            g.addEventListener('pointerup', release);
            g.addEventListener('pointercancel', release);

            // بعد از کشیدن با ماوس، کلیک نباید مدال را باز کند
            g.addEventListener('click', function (e) {
                if (moved > 6) { e.stopPropagation(); e.preventDefault(); }
                moved = 0;
            }, true);

            g.addEventListener('dragstart', function (e) { e.preventDefault(); });
            // شروع لمس، حرکت اینرسیِ ماوس را متوقف کند
            g.addEventListener('touchstart', stopGlide, { passive: true });
        },

        enableWheel: function () {
            var g = this.grid;
            g.addEventListener('wheel', function (e) {
                if (Math.abs(e.deltaY) <= Math.abs(e.deltaX)) return;
                if (g.scrollWidth <= g.clientWidth) return;
                e.preventDefault();
                g.scrollLeft += e.deltaY;
            }, { passive: false });
        }
    };

    /* =====================================================================
       ۱ب) نمودار انتقال‌های یک‌سال گذشته به هر حساب
       ===================================================================== */

    var benChart = {
        chart: null,
        data: null,
        active: '__all__',
        reqId: 0,
        hostId: 'avaBenChart',

        // رنگ ثابت برای هر ارز
        colorOf: function (cur) {
            var map = {
                IRR: '#22C55E', USD: '#38BDF8', EUR: '#A855F7', USDT: '#26A17B',
                GBP: '#F59E0B', AED: '#F472B6', TRY: '#EF4444', CAD: '#F87171',
                AUD: '#FBBF24', CNY: '#FB7185', JPY: '#818CF8'
            };
            if (map[cur]) return map[cur];
            // رنگ مشتق‌شده از نام ارز برای ارزهای ناشناخته
            var h = 0;
            for (var i = 0; i < cur.length; i++) h = (h * 31 + cur.charCodeAt(i)) % 360;
            return 'hsl(' + h + ',72%,62%)';
        },

        decOf: function (cur) { return cur === 'IRR' ? 0 : 2; },

        open: function (benId) {
            this.hostId = 'avaBenChart';
            var host = $('#' + this.hostId);
            if (!host) return;
            var rid = ++this.reqId;
            this.destroy();
            this.data = null;
            this.active = '__all__';
            host.innerHTML = '<div class="ava-benchart-load"><i class="fas fa-circle-notch"></i> در حال محاسبه…</div>';

            var self = this;
            var httpStatus = null;
            fetch(BASE + 'dashboard.php?ava=ben_chart&id=' + encodeURIComponent(benId), {
                credentials: 'same-origin'
            })
                .then(function (r) { httpStatus = r.status; return r.text(); })
                .then(function (raw) {
                    if (rid !== self.reqId) return;   // پاسخ منسوخ
                    var d;
                    try { d = JSON.parse(raw); }
                    catch (e) {
                        // پاسخ JSON معتبر نبود (مثلاً یک Warning/Notice سمت PHP قبل از JSON چاپ شده) —
                        // برای عیب‌یابی راحت‌تر، متن خام پاسخ هم در کنسول و هم مستقیم روی صفحه نشان داده می‌شود
                        // تا نیازی به باز کردن DevTools نباشد.
                        console.error('avaBenChart: invalid JSON response', httpStatus, raw);
                        var err = new Error('parse');
                        err.rawDiag = 'HTTP ' + httpStatus + ' — پاسخ JSON معتبر نبود:\n' + String(raw).slice(0, 500);
                        throw err;
                    }
                    if (!d || !d.success){
                        console.error('avaBenChart: server error', httpStatus, d);
                        var err2 = new Error('server');
                        err2.rawDiag = 'HTTP ' + httpStatus + ' — پیام سرور: ' + (d && d.error) + ' / ' + (d && d.message);
                        throw err2;
                    }
                    self.data = d;
                    self.render();
                })
                .catch(function (e) {
                    if (rid !== self.reqId) return;
                    var diag = (e && e.rawDiag) ? e.rawDiag : ('خطای شبکه' + (httpStatus ? (' (HTTP ' + httpStatus + ')') : ''));
                    host.innerHTML = '<div class="ava-benchart-empty"><i class="fas fa-chart-column"></i>' +
                        'اطلاعات نمودار در دسترس نیست' +
                        '<div style="margin-top:8px;font-size:.6rem;color:rgba(255,255,255,.4);direction:ltr;text-align:left;white-space:pre-wrap;word-break:break-all;">' + esc(diag) + '</div>' +
                        '</div>';
                });
        },

        // نسخه‌ی تجمیعی: مجموع واریزی‌ها به «همه»‌ی حساب‌های معرفی‌شده‌ی کاربر
        // (نه فقط یک حساب) در یک ظرفِ دلخواه — کاملاً همان کامپوننت، فقط منبع
        // داده و ظرف نمایش فرق می‌کند.
        openAll: function (hostId) {
            this.hostId = hostId || 'avaBenChart';
            var host = $('#' + this.hostId);
            if (!host) return;
            var rid = ++this.reqId;
            this.destroy();
            this.data = null;
            this.active = '__all__';
            host.innerHTML = '<div class="ava-benchart-load"><i class="fas fa-circle-notch"></i> در حال محاسبه…</div>';

            var self = this;
            var httpStatus = null;
            fetch(BASE + 'dashboard.php?ava=ben_chart_all', { credentials: 'same-origin' })
                .then(function (r) { httpStatus = r.status; return r.text(); })
                .then(function (raw) {
                    if (rid !== self.reqId) return;
                    var d;
                    try { d = JSON.parse(raw); }
                    catch (e) {
                        console.error('avaBenChart(all): invalid JSON response', httpStatus, raw);
                        var err = new Error('parse');
                        err.rawDiag = 'HTTP ' + httpStatus + ' — پاسخ JSON معتبر نبود:\n' + String(raw).slice(0, 500);
                        throw err;
                    }
                    if (!d || !d.success) {
                        console.error('avaBenChart(all): server error', httpStatus, d);
                        var err2 = new Error('server');
                        err2.rawDiag = 'HTTP ' + httpStatus + ' — پیام سرور: ' + (d && d.error) + ' / ' + (d && d.message);
                        throw err2;
                    }
                    self.data = d;
                    self.render();
                })
                .catch(function (e) {
                    if (rid !== self.reqId) return;
                    var diag = (e && e.rawDiag) ? e.rawDiag : ('خطای شبکه' + (httpStatus ? (' (HTTP ' + httpStatus + ')') : ''));
                    host.innerHTML = '<div class="ava-benchart-empty"><i class="fas fa-chart-column"></i>' +
                        'اطلاعات نمودار در دسترس نیست' +
                        '<div style="margin-top:8px;font-size:.6rem;color:rgba(255,255,255,.4);direction:ltr;text-align:left;white-space:pre-wrap;word-break:break-all;">' + esc(diag) + '</div>' +
                        '</div>';
                });
        },

        render: function () {
            var host = $('#' + this.hostId);
            var d = this.data;
            if (!host || !d) return;

            if (!d.items || !d.items.length) {
                host.innerHTML =
                    '<div class="ava-benchart-head">' +
                    '<div class="ava-benchart-title"><i class="fas fa-chart-column"></i> انتقال‌های یک سال گذشته</div>' +
                    '</div>' +
                    '<div class="ava-benchart-empty"><i class="fas fa-inbox"></i>' +
                    'در ۱۲ ماه گذشته انتقالی ثبت نشده است</div>';
                return;
            }

            // تب‌های کوچک: «همه» + هر ارز
            var tabs = '<button type="button" class="ava-benchart-tab all' +
                (this.active === '__all__' ? ' active' : '') + '" data-cur="__all__">' +
                '<i class="fas fa-layer-group"></i> همه' +
                '<span class="cnt">' + fa(d.items.length) + '</span></button>';

            d.items.forEach(function (it) {
                tabs += '<button type="button" class="ava-benchart-tab' +
                    (this.active === it.currency ? ' active' : '') + '" data-cur="' + esc(it.currency) + '">' +
                    '<span style="width:7px;height:7px;border-radius:50%;background:' + this.colorOf(it.currency) + '"></span>' +
                    esc(it.currency) +
                    '<span class="cnt">' + fa(it.count) + '</span></button>';
            }, this);

            var chartTabsId = this.hostId + 'Tabs';
            var chartSumId = this.hostId + 'Sum';
            var chartCanvasId = this.hostId + 'Canvas';
            var chartListId = this.hostId + 'List';

            host.innerHTML =
                '<div class="ava-benchart-head">' +
                '<div class="ava-benchart-title"><i class="fas fa-chart-column"></i> انتقال‌های یک سال گذشته</div>' +
                '<div class="ava-benchart-sub">۱۲ ماه اخیر</div>' +
                '</div>' +
                '<div class="ava-benchart-tabs" id="' + chartTabsId + '">' + tabs + '</div>' +
                '<div class="ava-benchart-sum" id="' + chartSumId + '"></div>' +
                '<div class="ava-benchart-canvas-wrap"><canvas id="' + chartCanvasId + '"></canvas></div>' +
                '<div class="ava-benchart-hint"><i class="fas fa-hand-pointer"></i> برای دیدن ریز واریزی‌ها روی نمودار بزنید</div>' +
                '<div class="ava-benchart-list" id="' + chartListId + '" style="display:none;"></div>';

            var self = this;
            $$('#' + chartTabsId + ' .ava-benchart-tab').forEach(function (b) {
                b.addEventListener('click', function () {
                    self.active = b.dataset.cur;
                    $$('#' + chartTabsId + ' .ava-benchart-tab').forEach(function (x) {
                        x.classList.toggle('active', x === b);
                    });
                    self.draw();
                    // با عوض‌شدن ارز، لیست باز قبلی دیگر معتبر نیست — بسته می‌شود
                    var listEl = $('#' + chartListId);
                    if (listEl) listEl.style.display = 'none';
                });
            });

            this.draw();
        },

        // لیست کامل «چه کسی، چه مبلغی، چه تاریخی» — زیر نمودار باز/بسته می‌شود
        toggleTxList: function () {
            var listEl = $('#' + this.hostId + 'List');
            if (!listEl) return;
            if (listEl.style.display !== 'none') { listEl.style.display = 'none'; return; }

            var d = this.data;
            if (!d) return;
            var isAll = this.active === '__all__';
            var items = isAll ? d.items : d.items.filter(function (i) { return i.currency === this.active; }, this);
            if (!items.length) items = d.items;

            // مسطح‌کردن همه‌ی تراکنش‌های همه‌ی ماه‌ها برای ارز(های) فعال
            var rows = [];
            items.forEach(function (it) {
                (it.tx || []).forEach(function (monthTx) {
                    (monthTx || []).forEach(function (tx) {
                        rows.push({ to: tx.to || '—', amount: tx.amount || 0, date: tx.date || '', currency: it.currency });
                    });
                });
            });
            // جدیدترین اول
            rows.sort(function (a, b) { return (b.date || '').localeCompare(a.date || ''); });

            var self = this;
            if (!rows.length) {
                listEl.innerHTML = '<div class="ava-benchart-list-empty"><i class="fas fa-inbox"></i> واریزی‌ای برای نمایش نیست</div>';
            } else {
                listEl.innerHTML = rows.map(function (r) {
                    var c = self.colorOf(r.currency);
                    return '<div class="ava-benchart-list-row">' +
                        '<div class="ava-benchart-list-to"><i class="fas fa-user" style="color:' + c + '"></i> ' + esc(r.to) + '</div>' +
                        '<div class="ava-benchart-list-meta"><span>' + esc(r.date) + '</span><b style="color:' + c + '" dir="ltr">' + num(r.amount, self.decOf(r.currency)) + ' ' + esc(r.currency) + '</b></div>' +
                        '</div>';
                }).join('');
            }
            listEl.style.display = '';
        },

        draw: function () {
            var d = this.data;
            var cv = $('#' + this.hostId + 'Canvas');
            var sum = $('#' + this.hostId + 'Sum');
            if (!d || !cv || typeof Chart === 'undefined') return;

            this.destroy();

            var isAll = this.active === '__all__';
            var shown = isAll ? d.items : d.items.filter(function (i) { return i.currency === this.active; }, this);
            if (!shown.length) shown = d.items;

            // خلاصه‌ی مجموع
            if (sum) {
                if (isAll) {
                    sum.innerHTML = shown.map(function (it) {
                        return '<b style="font-size:.8rem;color:' + this.colorOf(it.currency) + '">' +
                            num(it.total, this.decOf(it.currency)) + '</b><span>' + esc(it.currency) + '</span>';
                    }, this).join('<span style="opacity:.3;margin:0 2px">•</span>');
                } else {
                    var it = shown[0];
                    sum.innerHTML = '<b>' + num(it.total, this.decOf(it.currency)) + '</b>' +
                        '<span>' + esc(it.currency) + ' — مجموع ' + fa(it.count) + ' انتقال</span>';
                }
            }

            var datasets = shown.map(function (it) {
                var c = this.colorOf(it.currency);
                return {
                    label: it.currency,
                    data: it.series,
                    borderColor: c,
                    backgroundColor: isAll ? c + '22' : c + '33',
                    borderWidth: 2,
                    tension: 0.35,
                    fill: !isAll || shown.length === 1,
                    pointRadius: 0,
                    pointHoverRadius: 4,
                    pointHoverBackgroundColor: c
                };
            }, this);

            var light = document.documentElement.getAttribute('data-theme') === 'light';
            var gridCol = light ? 'rgba(0,0,0,.07)' : 'rgba(255,255,255,.07)';
            var tickCol = light ? 'rgba(40,25,70,.55)' : 'rgba(255,255,255,.45)';
            var self = this;

            this.chart = new Chart(cv.getContext('2d'), {
                type: 'line',
                data: { labels: d.labels, datasets: datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    onClick: function () { self.toggleTxList(); },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            // تولتیپ پیش‌فرض Chart.js یک div مطلق‌موقعیت است که می‌تواند
                            // از کادر نمودار (به‌خصوص داخل مودال‌های کوچک) بیرون بزند.
                            // با enabled:false + external، خودمان یک جعبه‌ی سفارشی می‌سازیم
                            // که همیشه داخل «.ava-benchart-canvas-wrap» محدود می‌ماند.
                            enabled: false,
                            external: function (context) { self.renderTooltip(context, shown); }
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: {
                                color: tickCol,
                                font: { family: 'Vazirmatn, sans-serif', size: 9 },
                                maxRotation: 0,
                                autoSkipPadding: 12
                            }
                        },
                        y: {
                            grid: { color: gridCol, drawTicks: false },
                            border: { display: false },
                            ticks: {
                                color: tickCol,
                                font: { family: 'Vazirmatn, sans-serif', size: 9 },
                                maxTicksLimit: 4,
                                callback: function (v) {
                                    if (Math.abs(v) >= 1e9) return fa((v / 1e9).toFixed(1)) + 'B';
                                    if (Math.abs(v) >= 1e6) return fa((v / 1e6).toFixed(1)) + 'M';
                                    if (Math.abs(v) >= 1e3) return fa((v / 1e3).toFixed(0)) + 'K';
                                    return fa(v);
                                }
                            }
                        }
                    }
                }
            });
        },

        // جعبه‌ی سفارشیِ نمایش «چه مبلغی و برای چه کسی» — همیشه داخل
        // «.ava-benchart-canvas-wrap» می‌ماند (کلمپ می‌شود، هرگز از کادر بیرون نمی‌زند)
        renderTooltip: function (context, shownItems) {
            var cv = $('#' + this.hostId + 'Canvas');
            if (!cv) return;
            var wrap = cv.parentNode;
            if (!wrap) return;
            var tipId = this.hostId + 'Tip';
            var tip = document.getElementById(tipId);
            if (!tip) {
                tip = document.createElement('div');
                tip.id = tipId;
                tip.className = 'ava-benchart-tip';
                wrap.appendChild(tip);
            }

            var t = context.tooltip;
            if (!t || t.opacity === 0) { tip.style.opacity = '0'; tip.style.pointerEvents = 'none'; return; }

            var dataIndex = (t.dataPoints && t.dataPoints[0]) ? t.dataPoints[0].dataIndex : null;
            if (dataIndex === null) { tip.style.opacity = '0'; return; }

            var self = this;
            var monthLabel = (t.title && t.title[0]) ? t.title[0] : '';
            var html = '<div class="ava-benchart-tip-title">' + esc(monthLabel) + '</div>';

            (t.dataPoints || []).forEach(function (dp) {
                var cur = dp.dataset.label;
                var item = null;
                for (var i = 0; i < shownItems.length; i++) { if (shownItems[i].currency === cur) { item = shownItems[i]; break; } }
                if (!item) return;
                var monthTotal = item.series[dataIndex] || 0;
                var txs = (item.tx && item.tx[dataIndex]) ? item.tx[dataIndex] : [];
                var c = self.colorOf(cur);

                html += '<div class="ava-benchart-tip-cur">' +
                    '<span style="width:7px;height:7px;border-radius:50%;background:' + c + ';display:inline-block;"></span>' +
                    '<b style="color:' + c + '">' + num(monthTotal, self.decOf(cur)) + '</b><span>' + esc(cur) + '</span>' +
                    '</div>';

                if (txs.length) {
                    var maxShow = 4;
                    txs.slice(0, maxShow).forEach(function (tx) {
                        var name = (tx.to || '—');
                        if (name.length > 16) name = name.slice(0, 16) + '…';
                        html += '<div class="ava-benchart-tip-row"><span>' + esc(name) + '</span><b dir="ltr">' + num(tx.amount, self.decOf(cur)) + '</b></div>';
                    });
                    if (txs.length > maxShow) {
                        html += '<div class="ava-benchart-tip-more">+' + fa(txs.length - maxShow) + ' مورد دیگر</div>';
                    }
                }
            });

            tip.innerHTML = html;
            tip.style.opacity = '1';
            tip.style.pointerEvents = 'none';

            // موقعیت‌دهی + کلمپ داخل کادر — هرگز اجازه نده جعبه از کادر بیرون بزند
            var wrapW = wrap.clientWidth, wrapH = wrap.clientHeight;
            var tipW = tip.offsetWidth, tipH = tip.offsetHeight;
            var left = t.caretX - tipW / 2;
            var top = t.caretY - tipH - 12;
            if (top < 4) top = t.caretY + 14; // اگر بالا جا نبود، پایین نقطه نشان بده
            if (top + tipH > wrapH - 4) top = Math.max(4, wrapH - tipH - 4);
            if (left < 4) left = 4;
            if (left + tipW > wrapW - 4) left = Math.max(4, wrapW - tipW - 4);
            tip.style.left = left + 'px';
            tip.style.top = top + 'px';
        },

        destroy: function () {
            if (this.chart) { try { this.chart.destroy(); } catch (e) {} this.chart = null; }
            var tip = document.getElementById(this.hostId + 'Tip');
            if (tip) tip.style.opacity = '0';
        }
    };

    /* =====================================================================
       ۲) بنرهای مدیریتی: جای‌گذاری آزاد + باز شدن عملیات سریع در مدال
       ===================================================================== */

    var slots = {
        // مودال‌های داخلی که ادمین می‌تواند مستقیم به بنر وصل کند
        modals: {
            transfer:   function () { call('avaOpenServiceModal', 'transfer'); },
            settlement: function () { call('avaOpenServiceModal', 'settlement'); },
            topup:      function () { call('avaOpenTopup'); },
            ad:         function () { call('avaOpenAdModal'); },
            alert:      function () { call('avaOpenSheet', 'avaAlertSheet'); },
            crypto:     function () { call('avaOpenCryptoModal'); },
            beneficiary:function () { call('avaBenOpenAdd'); },
            support:    function () { call('avaOpenSupport'); },
            profile:    function () { call('avaOpenProfile'); }
        },

        load: function () {
            var self = this;
            fetch(BASE + 'api/slides_quickactions_api.php?action=get_slides', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d || !d.success || !d.slides || !d.slides.length) return;
                    self.render(d.slides);
                })
                .catch(function () {});
        },

        render: function (list) {
            // گروه‌بندی بر اساس جایگاه
            var groups = {};
            list.forEach(function (s) {
                var k = s.slot || 'top';
                (groups[k] = groups[k] || []).push(s);
            });

            Object.keys(groups).forEach(function (key) {
                var host = document.querySelector('.ava-slot[data-slot="' + key + '"]');
                if (!host) return;
                host.innerHTML = '';
                groups[key]
                    .sort(function (a, b) { return (a.sort_order | 0) - (b.sort_order | 0); })
                    .forEach(function (s) { host.appendChild(slots.build(s)); });
            });
        },

        build: function (s) {
            var big = (s.layout === 'slide');
            var el = document.createElement('div');
            var style = s.style || 'p1';

            el.className = big
                ? 'ava-promo-big ava-promo-admin ' + style
                : 'ava-promo ava-promo-admin ' + style;

            if (style === 'custom' && s.bg_color) el.style.background = s.bg_color;

            var img = s.image_url
                ? '<img class="ava-promo-bgimg" src="' + esc(s.image_url) + '" alt="" onerror="this.remove()">'
                : '';
            var icoInner = s.icon
                ? '<i class="' + esc(s.icon) + '"></i>'
                : '<i class="fas fa-star"></i>';

            var opensModal = (s.action_type === 'quick_action' || s.action_type === 'modal');
            var badge = opensModal ? '<span class="ava-promo-badge"><i class="fas fa-bolt"></i> باز شدن سریع</span>' : '';

            if (big) {
                el.innerHTML = img + badge +
                    '<div class="ava-promo-big-ico">' + icoInner + '</div>' +
                    '<div class="ava-promo-big-t">' + esc(s.title || '') + '</div>' +
                    (s.description ? '<div class="ava-promo-big-s">' + esc(s.description) + '</div>' : '') +
                    (s.action_type !== 'none'
                        ? '<span class="ava-promo-big-cta">' +
                          (opensModal ? 'باز کردن' : 'مشاهده') +
                          ' <i class="fas fa-chevron-left"></i></span>'
                        : '');
            } else {
                el.innerHTML = img +
                    '<div class="ava-promo-glow"></div>' + badge +
                    '<div class="ava-promo-ico">' + icoInner + '</div>' +
                    '<div class="ava-promo-txt">' +
                    '<div class="ava-promo-t">' + esc(s.title || '') + '</div>' +
                    (s.description ? '<div class="ava-promo-s">' + esc(s.description) + '</div>' : '') +
                    '</div>' +
                    (s.action_type !== 'none' ? '<i class="fas fa-chevron-left ava-promo-arrow"></i>' : '');
            }

            if (s.action_type !== 'none') {
                el.addEventListener('click', function () { slots.fire(s); });
            } else {
                el.style.cursor = 'default';
            }
            return el;
        },

        fire: function (s) {
            var t = s.action_type || 'link';

            // ← خواسته‌ی اصلی: بنر مستقیم همان عملیات سریع را در مدال باز کند
            if (t === 'quick_action') {
                var id = parseInt(s.action_target, 10);
                if (id > 0 && typeof window.openQuickActionModal === 'function') {
                    window.openQuickActionModal(id);
                    return;
                }
            }
            if (t === 'modal') {
                var fn = this.modals[s.action_target];
                if (fn) { fn(); return; }
            }
            if (s.link) {
                if (/^https?:\/\//i.test(s.link)) window.open(s.link, '_blank', 'noopener');
                else window.location.href = s.link;
            }
        }
    };

    function call(name) {
        var args = Array.prototype.slice.call(arguments, 1);
        if (typeof window[name] === 'function') {
            try { window[name].apply(window, args); } catch (e) {}
        }
    }

    /* =====================================================================
       ۳) مخفی/بازگرداندن بخش‌ها — جای اصلی حفظ می‌شود
       ===================================================================== */

    var sections = {
        hidden: [],

        all: function () {
            return $$('[data-avasec]');
        },

        load: function () {
            // ابتدا از حافظه‌ی محلی تا صفحه بدون تأخیر درست نمایش داده شود
            try {
                var raw = localStorage.getItem(LS_HIDDEN);
                if (raw) this.hidden = JSON.parse(raw) || [];
            } catch (e) { this.hidden = []; }
            this.apply();

            // سپس همگام‌سازی با سرور (بین دستگاه‌ها)
            var self = this;
            fetch(BASE + 'dashboard.php?ava=sec_prefs_get', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d || !d.success || !Array.isArray(d.hidden)) return;
                    self.hidden = d.hidden;
                    try { localStorage.setItem(LS_HIDDEN, JSON.stringify(self.hidden)); } catch (e) {}
                    self.apply();
                })
                .catch(function () {});
        },

        save: function () {
            try { localStorage.setItem(LS_HIDDEN, JSON.stringify(this.hidden)); } catch (e) {}
            fetch(BASE + 'dashboard.php?ava=sec_prefs_set', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ hidden: this.hidden })
            }).catch(function () {});
        },

        // چون بخش‌ها از DOM حذف نمی‌شوند، بازگرداندن یعنی برداشتن کلاس
        // و بخش دقیقاً سر جای اولیه‌ی خودش ظاهر می‌شود.
        apply: function () {
            var h = this.hidden;
            this.all().forEach(function (el) {
                el.classList.toggle('ava-sec-hidden', h.indexOf(el.dataset.avasec) !== -1);
            });
            if ($('#avaSecMgrList')) this.renderList();

            // نشانگر تعداد بخش‌های مخفی، کنار «شخصی‌سازی داشبورد» در پروفایل
            var badge = $('#avaSecMgrBadge');
            if (badge) {
                if (h.length) {
                    badge.textContent = fa(h.length) + ' مخفی';
                    badge.style.display = '';
                } else {
                    badge.style.display = 'none';
                }
            }
        },

        toggle: function (key, show) {
            var i = this.hidden.indexOf(key);
            if (show && i !== -1) this.hidden.splice(i, 1);
            if (!show && i === -1) this.hidden.push(key);
            this.save();
            this.apply();
        },

        resetAll: function () {
            this.hidden = [];
            this.save();
            this.apply();
            call('avaToast', 'همه‌ی بخش‌ها به داشبورد برگشتند');
        },

        renderList: function () {
            var host = $('#avaSecMgrList');
            if (!host) return;
            var h = this.hidden;
            host.innerHTML = this.all().map(function (el, i) {
                var key = el.dataset.avasec;
                var title = el.dataset.avasecTitle || key;
                var icon = el.dataset.avasecIcon || 'fas fa-square';
                var on = h.indexOf(key) === -1;
                return '<div class="ava-secmgr-row' + (on ? '' : ' off') + '">' +
                    '<span class="ava-secmgr-ico"><i class="' + esc(icon) + '"></i></span>' +
                    '<span class="ava-secmgr-name">' + esc(title) +
                    '<span class="ava-secmgr-pos"> — جایگاه ' + fa(i + 1) + '</span></span>' +
                    '<label class="ava-switch">' +
                    '<input type="checkbox" data-sec="' + esc(key) + '"' + (on ? ' checked' : '') + '>' +
                    '<i></i></label>' +
                    '</div>';
            }).join('');

            var self = this;
            $$('#avaSecMgrList input[data-sec]').forEach(function (inp) {
                inp.addEventListener('change', function () {
                    self.toggle(inp.dataset.sec, inp.checked);
                });
            });
        },

        // دکمه‌ی × روی هر بخش در حالت ویرایش
        injectButtons: function () {
            var self = this;
            this.all().forEach(function (el) {
                if ($('.ava-sec-hide-btn', el)) return;
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'ava-sec-hide-btn';
                b.title = 'مخفی کردن این بخش';
                b.innerHTML = '<i class="fas fa-eye-slash"></i>';
                b.addEventListener('click', function (e) {
                    e.stopPropagation();
                    self.toggle(el.dataset.avasec, false);
                    call('avaToast', '«' + (el.dataset.avasecTitle || '') + '» مخفی شد — از شخصی‌سازی برگردانید');
                });
                el.appendChild(b);
            });
        },

        editMode: function (on) {
            document.body.classList.toggle('ava-editing', !!on);
            if (on) this.injectButtons();
        },

        openManager: function () {
            this.renderList();
            call('avaOpenSheet', 'avaSecMgrSheet');
        }
    };

    /* =====================================================================
       ۴) بنر اسلایدی هشدارها (صورت‌حساب / حساب در انتظار پرداخت / فیش / پیشنهاد تبادل)
       ===================================================================== */

    var alertCarousel = {
        idx: 0,
        timer: null,

        init: function () {
            // برجسته‌سازی صورت‌حساب‌های پرداخت‌نشده در همان کارت (رفتار قبلی حفظ شد)
            var invCard = $('#avaTopInvCard');
            if (invCard) {
                var items = $$('.ava-inv-pane .ava-inv-item', invCard);
                if (items.length) {
                    invCard.classList.add('ava-inv-alarm');
                    if (!$('.ava-inv-ribbon', invCard)) {
                        invCard.insertAdjacentHTML('afterbegin', '<div class="ava-inv-ribbon">پرداخت نشده</div>');
                    }
                    items.forEach(function (it) { it.classList.add('ava-inv-due-item'); });
                }
            }

            var carousel = $('#avaAlertCarousel');
            if (!carousel) return;

            this.refresh();

            // پیشنهاد تبادل دریافت‌شده: به‌صورت آسنکرون واکشی و در صورت وجود به اسلایدها اضافه می‌شود
            fetch('/ledor/api/offer_api.php?action=get_my_received_offers')
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.success || !res.offers) return;
                    var pending = res.offers.filter(function (o) { return o.status === 'pending'; }).length;
                    if (pending > 0) {
                        alertCarousel.addSlide({
                            icon: 'fas fa-right-left',
                            cls: 'ava-alert-offers',
                            t: fa(pending) + ' پیشنهاد تبادل جدید دریافت کرده‌اید',
                            s: 'برای مشاهده اینجا بزنید',
                            cta: 'مشاهده',
                            href: '/ledor/arad.php'
                        });
                    }
                })
                .catch(function () {});
        },

        addSlide: function (slide) {
            var track = $('#avaAlertTrack');
            if (!track) return;
            var el = document.createElement('div');
            el.className = 'ava-inv-topbar' + (slide.cls ? (' ' + slide.cls) : '');
            if (slide.target) el.dataset.target = slide.target;
            if (slide.href) el.dataset.href = slide.href;
            el.innerHTML =
                '<div class="ava-inv-topbar-ico"><i class="' + slide.icon + '"></i></div>' +
                '<div class="ava-inv-topbar-txt">' +
                '<div class="ava-inv-topbar-t">' + slide.t + '</div>' +
                '<div class="ava-inv-topbar-s">' + slide.s + '</div>' +
                '</div>' +
                '<span class="ava-inv-topbar-cta">' + slide.cta + '</span>';
            track.appendChild(el);
            this.refresh();
        },

        // اسکرول افقیِ فقط همین کارت (بدون هیچ اثری روی اسکرول عمودی کل صفحه)
        goTo: function (i) {
            var track = $('#avaAlertTrack');
            if (!track) return;
            var slides = $$('.ava-inv-topbar', track);
            var n = slides.length;
            if (!n) return;
            this.idx = (i + n) % n;
            var target = slides[this.idx];
            var rectC = track.getBoundingClientRect();
            var rectE = target.getBoundingClientRect();
            track.scrollLeft += Math.round(rectE.left - rectC.left);
        },

        refresh: function () {
            var carousel = $('#avaAlertCarousel');
            var track = $('#avaAlertTrack');
            if (!carousel || !track) return;
            var slides = $$('.ava-inv-topbar', track);
            if (!slides.length) { carousel.style.display = 'none'; return; }
            carousel.style.display = 'block';

            slides.forEach(function (bar) {
                if (bar.dataset.wired) return;
                bar.dataset.wired = '1';
                bar.addEventListener('click', function () {
                    if (bar.dataset.href) { window.location.href = bar.dataset.href; return; }

                    // بعضی مقصدها (مثل «حساب‌ها و فیش‌ها») داخل یک مودال هستند و
                    // در حالت عادی display:none دارند؛ scrollIntoView روی عنصر
                    // پنهان هیچ کاری نمی‌کند و کاربر فکر می‌کند دکمه خراب است.
                    // برای این‌ها باید خودِ مودال باز شود.
                    // صورت‌حساب پرداخت‌نشده: مستقیم مودال همان صورت‌حساب باز شود
                    // تا کاربر شماره‌حساب را ببیند، پرداخت کند و فیش بفرستد.
                    if (bar.dataset.invoice) {
                        if (typeof window.avaOpenInvoice === 'function') {
                            window.avaOpenInvoice(parseInt(bar.dataset.invoice, 10));
                            return;
                        }
                    }

                    // حساب در انتظار پرداخت با دقیقاً ۱ مورد: مستقیم مودال شماره‌حساب
                    // را باز کن (بدون مودال لیست به‌عنوان لایه‌ی واسط) تا بستنش
                    // یک‌راست کاربر را به داشبورد برگرداند.
                    if (bar.dataset.directpend) {
                        if (typeof window.avaOpenPendAcc === 'function') {
                            try {
                                var __pa = JSON.parse(bar.dataset.directpend);
                                window.avaOpenPendAcc(__pa, true);
                            } catch (e) {}
                            return;
                        }
                    }

                    if (bar.dataset.modal) {
                        if (typeof window.avaOpenFsModal === 'function') {
                            window.avaOpenFsModal(bar.dataset.modal);
                            var wantTab = bar.dataset.modaltab;
                            if (wantTab && typeof window.avaRecAccTab === 'function') {
                                var btn = document.querySelector('#' + bar.dataset.modal + ' [data-rectab="' + wantTab + '"]');
                                try { window.avaRecAccTab(wantTab, btn); } catch (e) {}
                            }
                        }
                        return;
                    }

                    var target = bar.dataset.target ? $(bar.dataset.target) : null;
                    if (target) {
                        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        target.animate(
                            [{ transform: 'scale(1)' }, { transform: 'scale(1.02)' }, { transform: 'scale(1)' }],
                            { duration: 600, easing: 'ease-in-out' }
                        );
                    }
                });
            });

            var self = this;
            this.goTo(self.idx);

            if (this.timer) clearInterval(this.timer);
            if (slides.length > 1) {
                this.timer = setInterval(function () {
                    self.syncIdx();
                    self.goTo(self.idx + 1);
                }, 4500);
            }
        },

        // هماهنگ‌کردن idx داخلی با موقعیت واقعیِ اسکرول (برای وقتی کاربر خودش با انگشت اسلاید را جابه‌جا کرده)
        syncIdx: function () {
            var track = $('#avaAlertTrack');
            if (!track) return;
            var slides = $$('.ava-inv-topbar', track);
            if (!slides.length) return;
            var rectC = track.getBoundingClientRect();
            var best = 0, bestDist = Infinity;
            slides.forEach(function (el, i) {
                var d = Math.abs(el.getBoundingClientRect().left - rectC.left);
                if (d < bestDist) { bestDist = d; best = i; }
            });
            this.idx = best;
        }
    };

    /* =====================================================================
       راه‌اندازی
       ===================================================================== */

    function boot() {
        try { benScroll.init(); } catch (e) {}
        try { sections.load(); } catch (e) {}
        try { slots.load(); } catch (e) {}
        try { alertCarousel.init(); } catch (e) {}
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    // دسترسی سراسری برای فراخوانی از HTML
    window.avaBenChart = benChart;
    window.avaSections = sections;
    window.avaSlots = slots;
    window.avaOpenSecMgr = function () { sections.openManager(); };
    window.avaToggleEdit = function () {
        var on = !document.body.classList.contains('ava-editing');
        sections.editMode(on);
        return on;
    };
})();
