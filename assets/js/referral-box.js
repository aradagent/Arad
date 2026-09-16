/* ==================== زیرمجموعه‌های شما — Referral Box JS ==================== */
(function () {
    'use strict';

    var arfBenList = [];
    var arfChartInstance = null;
    var arfDataLoaded = false;
    var arfChartLabels = null;
    var arfChartValues = null;

    function arfFmtDate(s) {
        try {
            var d = new Date(String(s).replace(' ', 'T'));
            return d.toLocaleDateString('fa-IR');
        } catch (e) { return ''; }
    }

    /* ---------------- باز/بسته کردن پنل کامل ---------------- */
    window.arfOpenPanel = function () {
        var overlay = document.getElementById('arfPanelOverlay');
        if (!overlay) return;
        overlay.classList.add('arf-open');
        document.body.style.overflow = 'hidden';
        if (!arfDataLoaded) {
            arfDataLoaded = true;
            arfLoadSummary();
            arfLoadChart();
            arfLoadList();
        }
    };

    window.arfClosePanel = function () {
        var overlay = document.getElementById('arfPanelOverlay');
        if (!overlay) return;
        overlay.classList.remove('arf-open');
        document.body.style.overflow = '';
    };

    /* ---------------- تب‌ها ---------------- */
    window.arfSwitchTab = function (name) {
        document.querySelectorAll('.arf-tab').forEach(function (t) {
            t.classList.toggle('active', t.getAttribute('data-arf-tab') === name);
        });
        document.getElementById('arfPaneUsers').classList.toggle('active', name === 'users');
        document.getElementById('arfPaneInvite').classList.toggle('active', name === 'invite');
        document.getElementById('arfPaneStats').classList.toggle('active', name === 'stats');
        if (name === 'stats') {
            // نمودار عمداً تا اولین باری که تب «آمار» واقعاً دیده می‌شود ساخته
            // نمی‌شود: ساختن Chart.js روی کانوسی که هنوز display:none است
            // باعث می‌شد ابعاد آن صفر محاسبه شود و نمودار عملاً خالی بماند.
            if (arfChartInstance) {
                setTimeout(function () { arfChartInstance.resize(); }, 60);
            } else if (arfChartLabels) {
                setTimeout(arfRenderChart, 60);
            }
        }
    };

    /* ---------------- کپی لینک از بنر (بدون باز کردن پنل) ---------------- */
    window.arfCopyBannerLink = function (evt) {
        if (evt) evt.stopPropagation();
        var input = document.getElementById('arfBannerLinkVal');
        if (!input) return;
        var val = input.value;
        var done = function () {
            var btn = document.getElementById('arfBannerCopyBtn');
            if (!btn) return;
            var old = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-check"></i> کپی شد';
            btn.classList.add('arf-copied');
            setTimeout(function () { btn.innerHTML = old; btn.classList.remove('arf-copied'); }, 1800);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(val).then(done).catch(function () {
                arfLegacyCopy(input, done);
            });
        } else {
            arfLegacyCopy(input, done);
        }
    };

    function arfLegacyCopy(input, done) {
        var wasHidden = input.type === 'hidden';
        if (wasHidden) input.type = 'text';
        input.select();
        input.setSelectionRange(0, 99999);
        try { document.execCommand('copy'); done(); } catch (e) {}
        if (wasHidden) input.type = 'hidden';
    }

    /* ---------------- کپی لینک ---------------- */
    window.arfCopyLink = function () {
        var input = document.getElementById('arfLinkInput');
        if (!input) return;
        input.select();
        input.setSelectionRange(0, 99999);
        var done = function () {
            var btn = document.getElementById('arfCopyBtn');
            if (!btn) return;
            var old = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-check"></i> کپی شد';
            btn.classList.add('arf-copied');
            setTimeout(function () { btn.innerHTML = old; btn.classList.remove('arf-copied'); }, 1800);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(input.value).then(done).catch(function () {
                try { document.execCommand('copy'); done(); } catch (e) {}
            });
        } else {
            try { document.execCommand('copy'); done(); } catch (e) {}
        }
    };

    function arfLoadSummary() {
        fetch('api/referral_api.php?action=my_summary')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) return;
                var elCount = document.getElementById('arfStatCount');
                var elEarned = document.getElementById('arfStatEarned');
                var elBadge = document.getElementById('arfCountBadge');
                if (elCount) elCount.textContent = data.stats.total_referred;
                if (elEarned) elEarned.textContent = Number(data.stats.total_earned).toFixed(2) + '€';
                if (elBadge) elBadge.textContent = data.stats.total_referred + ' نفر';
            }).catch(function () {});
    }

    function arfLoadChart() {
        fetch('api/referral_api.php?action=my_chart&days=30')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) return;
                var values = data.chart.values || [];
                var labels = data.chart.labels || [];
                arfChartLabels = labels;
                arfChartValues = values;

                // «درآمد این ماه» = مجموع درآمد ۳۰ روز اخیر، درصد تغییر = نیمه‌ی دوم نسبت به نیمه‌ی اول
                var monthTotal = values.reduce(function (a, b) { return a + b; }, 0);
                var half = Math.floor(values.length / 2) || 1;
                var firstHalf = values.slice(0, half).reduce(function (a, b) { return a + b; }, 0);
                var secondHalf = values.slice(half).reduce(function (a, b) { return a + b; }, 0);
                var pct = firstHalf > 0 ? Math.round(((secondHalf - firstHalf) / firstHalf) * 100) : (secondHalf > 0 ? 100 : 0);

                var elVal = document.getElementById('arfMonthValue');
                var elPct = document.getElementById('arfMonthPct');
                if (elVal) elVal.textContent = monthTotal.toFixed(2) + '€';
                if (elPct) {
                    var neg = pct < 0;
                    elPct.classList.toggle('arf-neg', neg);
                    elPct.innerHTML = '<i class="fas fa-arrow-' + (neg ? 'down' : 'up') + '"></i> ' + (neg ? '' : '+') + pct + '%';
                }

                // نمودار فقط وقتی ساخته می‌شود که تب «آمار» همین الان فعال/دیده‌شده باشد؛
                // در غیر این صورت arfSwitchTab('stats') خودش موقع باز شدن تب آن را می‌سازد.
                var statsPane = document.getElementById('arfPaneStats');
                if (statsPane && statsPane.classList.contains('active')) {
                    arfRenderChart();
                }
            }).catch(function () {});
    }

    function arfRenderChart() {
        var canvas = document.getElementById('arfChart');
        if (!canvas || typeof Chart === 'undefined' || !arfChartLabels) return;
        if (arfChartInstance) { arfChartInstance.destroy(); arfChartInstance = null; }
        arfChartInstance = new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: arfChartLabels,
                datasets: [{
                    label: 'درآمد زیرمجموعه‌گیری (یورو)',
                    data: arfChartValues,
                    borderColor: '#a855f7',
                    backgroundColor: 'rgba(168,85,247,.18)',
                    fill: true,
                    tension: .35,
                    pointRadius: 2,
                    pointHoverRadius: 7,
                    pointBackgroundColor: '#a855f7',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 1.5,
                    pointHitRadius: 18,
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                // با لمس/کلیک روی هر نقطه از نمودار (نه فقط دقیقاً روی خودِ نقطه)
                // نزدیک‌ترین مقدار همان روز نشان داده می‌شود — لازم برای گوشی
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        enabled: true,
                        backgroundColor: '#1e1040',
                        titleColor: '#c4a3ff',
                        bodyColor: '#fff',
                        borderColor: 'rgba(168,85,247,.4)',
                        borderWidth: 1,
                        padding: 10,
                        displayColors: false,
                        callbacks: {
                            title: function (items) { return items && items[0] ? items[0].label : ''; },
                            label: function (item) { return 'درآمد: ' + Number(item.parsed.y).toFixed(2) + '€'; }
                        }
                    }
                },
                scales: {
                    x: { ticks: { color: 'rgba(255,255,255,.5)', font: { size: 9 }, maxTicksLimit: 5 }, grid: { display: false } },
                    y: { ticks: { color: 'rgba(255,255,255,.5)', font: { size: 9 } }, grid: { color: 'rgba(255,255,255,.06)' }, beginAtZero: true }
                }
            }
        });
    }

    function arfLoadList() {
        var box = document.getElementById('arfList');
        if (!box) return;
        fetch('api/referral_api.php?action=my_referrals')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success || !data.items || !data.items.length) {
                    box.innerHTML = '<div class="arf-empty">هنوز کسی را دعوت نکرده‌اید — لینک دعوت را با دوستانتان به اشتراک بگذارید!</div>';
                    return;
                }
                var html = '';
                data.items.forEach(function (u) {
                    var avatar = u.avatar && u.avatar.indexOf('default') === -1
                        ? (u.avatar.indexOf('uploads/') === 0 ? u.avatar : 'uploads/avatars/' + u.avatar)
                        : '';
                    html += '<div class="arf-row">'
                        + (avatar
                            ? '<img class="arf-row-avatar" src="' + avatar + '" onerror="this.outerHTML=\'<div class=&quot;arf-row-avatar&quot;><i class=&quot;fas fa-user&quot;></i></div>\'">'
                            : '<div class="arf-row-avatar"><i class="fas fa-user"></i></div>')
                        + '<div class="arf-row-info">'
                        + '<div class="arf-row-name">' + (u.full_name || 'کاربر AvaPay') + '</div>'
                        + '<div class="arf-row-meta">' + (u.tx_count || 0) + ' تراکنش موفق · ' + arfFmtDate(u.created_at) + '</div>'
                        + '</div>'
                        + '<div class="arf-row-right"><span class="arf-row-earned">+' + Number(u.total_earned).toFixed(2) + '€</span><i class="fas fa-chevron-left arf-row-chevron"></i></div>'
                        + '</div>';
                });
                box.innerHTML = html;
            }).catch(function () {
                box.innerHTML = '<div class="arf-empty">خطا در بارگذاری لیست</div>';
            });
    }

    /* ---------------- مودال تسویه ---------------- */
    window.arfOpenSettleModal = function () {
        var modal = document.getElementById('arfSettleModal');
        if (!modal) return;
        modal.classList.add('arf-open');
        document.getElementById('arfModalError').textContent = '';
        document.getElementById('arfSettleAmount').value = '';

        fetch('api/referral_api.php?action=my_summary')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) return;
                var bal = Number(data.wallet_balance_eur || 0);
                var minS = Number(data.min_settlement_eur || 5);
                document.getElementById('arfModalBalance').textContent = bal.toFixed(2) + '€';
                document.getElementById('arfModalMinNote').textContent = 'حداقل مبلغ تسویه ' + minS.toFixed(2) + ' یورو است.';
                document.getElementById('arfSettleAmount').min = minS;
                window.__arfMinSettlement = minS;
                window.__arfWalletBalance = bal;
            }).catch(function () {});

        arfLoadBeneficiaries();
    };

    window.arfCloseSettleModal = function () {
        var modal = document.getElementById('arfSettleModal');
        if (!modal) return;
        modal.classList.remove('arf-open');
    };

    function arfLoadBeneficiaries() {
        var sel = document.getElementById('arfBenSelect');
        sel.innerHTML = '<option value="">در حال بارگذاری...</option>';
        fetch('dashboard.php?ava=ben_list')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                arfBenList = (data && data.items) ? data.items : [];
                if (!arfBenList.length) {
                    sel.innerHTML = '<option value="">حسابی ثبت نشده — یکی اضافه کنید</option>';
                    document.getElementById('arfAddBenBox').style.display = 'block';
                    return;
                }
                var html = '<option value="">— انتخاب حساب —</option>';
                arfBenList.forEach(function (b) {
                    html += '<option value="' + b.id + '">' + b.full_name + (b.bank_name ? ' — ' + b.bank_name : '') + '</option>';
                });
                sel.innerHTML = html;
            }).catch(function () {
                sel.innerHTML = '<option value="">خطا در بارگذاری حساب‌ها</option>';
            });
    }

    window.arfOpenAddBenInline = function () {
        var box = document.getElementById('arfAddBenBox');
        box.style.display = (box.style.display === 'none' || !box.style.display) ? 'block' : 'none';
    };

    window.arfSaveNewBen = function () {
        var name = document.getElementById('arfBenName').value.trim();
        var card = document.getElementById('arfBenCard').value.trim();
        var bank = document.getElementById('arfBenBank').value.trim();
        var errBox = document.getElementById('arfModalError');
        errBox.textContent = '';

        if (!name) { errBox.textContent = 'نام صاحب حساب را وارد کنید'; return; }
        if (!card) { errBox.textContent = 'شماره کارت یا شبا را وارد کنید'; return; }

        fetch('dashboard.php?ava=ben_add', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ full_name: name, card_number: card, iban: card.length >= 15 ? card : '', bank_name: bank })
        }).then(function (r) { return r.json(); })
          .then(function (data) {
            if (data.error) { errBox.textContent = data.error; return; }
            document.getElementById('arfAddBenBox').style.display = 'none';
            document.getElementById('arfBenName').value = '';
            document.getElementById('arfBenCard').value = '';
            document.getElementById('arfBenBank').value = '';
            arfLoadBeneficiaries();
          }).catch(function () { errBox.textContent = 'خطا در ثبت حساب'; });
    };

    window.arfSubmitSettle = function () {
        var errBox = document.getElementById('arfModalError');
        errBox.textContent = '';

        var benId = document.getElementById('arfBenSelect').value;
        var amount = parseFloat(document.getElementById('arfSettleAmount').value || '0');
        var minS = window.__arfMinSettlement || 5;
        var balance = window.__arfWalletBalance || 0;

        if (!benId) { errBox.textContent = 'یک حساب مقصد انتخاب کنید'; return; }
        if (!amount || amount < minS) { errBox.textContent = 'حداقل مبلغ تسویه ' + minS.toFixed(2) + ' یورو است'; return; }
        if (amount > balance) { errBox.textContent = 'موجودی کیف‌پول شما کافی نیست (موجودی: ' + balance.toFixed(2) + '€)'; return; }

        var ben = arfBenList.filter(function (b) { return String(b.id) === String(benId); })[0];
        if (!ben) { errBox.textContent = 'حساب انتخاب‌شده معتبر نیست'; return; }

        var ibanForApi = ben.iban && ben.iban.length >= 10 ? ben.iban : (ben.card_number || '');
        if (ibanForApi.length < 10) { errBox.textContent = 'شماره حساب انتخاب‌شده معتبر نیست'; return; }

        var btn = document.querySelector('.arf-submit-btn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال ارسال...';

        fetch('api/withdraw.php?action=submit', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                amount: amount,
                currency: 'EUR',
                iban_number: ibanForApi,
                card_number: ben.card_number || '',
                bank_name: ben.bank_name || 'نامشخص',
                recipient_name: ben.full_name,
                notes: 'درخواست تسویه کیف‌پول از بخش زیرمجموعه‌های شما'
            })
        }).then(function (r) { return r.json(); })
          .then(function (data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane"></i> ثبت درخواست تسویه';
            if (!data.success) { errBox.textContent = data.message || 'خطا در ثبت درخواست'; return; }
            window.arfCloseSettleModal();
            if (window.showToast) { window.showToast(data.message || 'درخواست تسویه ثبت شد', 'success'); }
            else { alert(data.message || 'درخواست تسویه با موفقیت ثبت شد'); }
            arfLoadSummary();
          }).catch(function () {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane"></i> ثبت درخواست تسویه';
            errBox.textContent = 'خطا در ارتباط با سرور';
          });
    };

    /* ---------------------------------------------------------------------------
       محافظ سراسری بستن مودال‌های «زیرمجموعه‌های شما».
       قبلاً دکمه‌ی بستن فقط با onclick اینلاین کار می‌کرد و هیچ راه دیگری
       برای خروج وجود نداشت (نه کلیک روی پس‌زمینه، نه Escape، نه پشتیبان لمسی).
       نتیجه: وقتی این مودال از روی بنر بالای صفحه باز می‌شد، اگر همان یک
       onclick به هر دلیلی اجرا نمی‌شد (مثلاً لغزش انگشت روی موبایل)، کاربر
       داخل مودال گیر می‌کرد. این بلوک دقیقاً همان الگوی امنِ مودال‌های
       تمام‌صفحه‌ی داشبورد (ava-fs-modal) را برای arf-modal-overlay هم اجرا می‌کند.
       --------------------------------------------------------------------------- */
    function arfCloseOverlay(overlay) {
        if (!overlay) return;
        if (overlay.id === 'arfSettleModal') { window.arfCloseSettleModal(); return; }
        window.arfClosePanel();
    }

    function arfTopOpenOverlay() {
        var list = document.querySelectorAll('.arf-modal-overlay.arf-open');
        if (!list.length) return null;
        // اگر مودال تسویه روی پنل اصلی باز شده باشد، باید همان اول بسته شود
        var settle = document.getElementById('arfSettleModal');
        if (settle && settle.classList.contains('arf-open')) return settle;
        return list[list.length - 1];
    }

    document.addEventListener('click', function (e) {
        var closeBtn = e.target.closest ? e.target.closest('.arf-card-close, .arf-modal-close') : null;
        if (closeBtn) {
            var m = closeBtn.closest('.arf-modal-overlay');
            if (m) { e.preventDefault(); e.stopPropagation(); if (e.stopImmediatePropagation) e.stopImmediatePropagation(); arfCloseOverlay(m); }
            return;
        }
        // کلیک روی خودِ پس‌زمینه‌ی مودال (نه کارت/فرم داخلش)
        if (e.target.classList && e.target.classList.contains('arf-modal-overlay') && e.target.classList.contains('arf-open')) {
            arfCloseOverlay(e.target);
        }
    }, true);

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var top = arfTopOpenOverlay();
        if (top) arfCloseOverlay(top);
    });

    /* پشتیبان لمسی: اگر روی موبایل انگشت هنگام زدن دکمه‌ی بستن کمی بلغزد و
       رویداد click تولید نشود، بستن با touchend هم انجام می‌شود. */
    document.addEventListener('touchend', function (e) {
        var t = e.changedTouches && e.changedTouches[0];
        if (!t) return;
        var el = document.elementFromPoint(t.clientX, t.clientY);
        var closeBtn = (el && el.closest) ? el.closest('.arf-card-close, .arf-modal-close') : null;
        if (!closeBtn) return;
        var m = closeBtn.closest('.arf-modal-overlay');
        if (m && m.classList.contains('arf-open')) arfCloseOverlay(m);
    }, { passive: true });

    function arfInit() {
        if (!document.getElementById('arfBanner')) return;
        // بارگذاری آمار پایه فقط برای مقدار اولیه بنر/بج؛ بارگذاری کامل هنگام باز شدن پنل انجام می‌شود
        arfLoadSummary();
    }

    if (document.readyState !== 'loading') arfInit();
    else document.addEventListener('DOMContentLoaded', arfInit);
})();
