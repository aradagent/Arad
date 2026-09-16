/* ================================================================
   AvaPay · Modern UI enhancer
   ----------------------------------------------------------------
   نمودارها و انیمیشن‌های زنده برای سه بخش مالی. داده‌ها از همان
   اطلاعاتی که روی صفحه رندر شده یا از API پنل مدیریت خوانده می‌شود؛
   هیچ منطق موجود یا آی‌دی فرمی تغییر نمی‌کند.
   ================================================================ */
(function () {
  'use strict';

  const AV = (window.AvaPay = window.AvaPay || {});
  const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------- ابزار ---------- */
  function el(tag, cls, html) {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (html != null) n.innerHTML = html;
    return n;
  }
  function fa(n) {
    // فرمت خوانا برای اعداد بزرگ (تومان/ارز)
    n = Number(n) || 0;
    if (n >= 1e9) return (n / 1e9).toFixed(1).replace(/\.0$/, '') + 'B';
    if (n >= 1e6) return (n / 1e6).toFixed(1).replace(/\.0$/, '') + 'M';
    if (n >= 1e3) return (n / 1e3).toFixed(1).replace(/\.0$/, '') + 'K';
    return String(n);
  }

  /* ---------- شمارنده انیمیشنی ---------- */
  AV.animateCount = function (node, to, opts) {
    opts = opts || {};
    to = Number(to) || 0;
    if (prefersReduced) { node.textContent = opts.format ? opts.format(to) : to.toLocaleString('en-US'); return; }
    const dur = opts.duration || 900;
    const start = performance.now();
    function tick(now) {
      const p = Math.min(1, (now - start) / dur);
      const e = 1 - Math.pow(1 - p, 3);
      const val = Math.round(to * e);
      node.textContent = opts.format ? opts.format(val) : val.toLocaleString('en-US');
      if (p < 1) requestAnimationFrame(tick);
    }
    requestAnimationFrame(tick);
  };

  /* ---------- نمودار دونات ---------- */
  // segments: [{label, value, color}]  centerLabel, centerValue
  AV.donut = function (opts) {
    const size = 108, sw = 12, r = (size - sw) / 2, cx = size / 2;
    const circ = 2 * Math.PI * r;
    const total = opts.segments.reduce((s, x) => s + (Number(x.value) || 0), 0) || 1;

    const wrap = el('div', 'av-donut-wrap');
    const donut = el('div', 'av-donut');

    // هر بخش یک دایره‌ی کامل با چرخش شروع متفاوت؛ با gap صفر پشت هم می‌نشینند
    const segData = [];
    let acc = 0;
    opts.segments.forEach((seg, i) => {
      const frac = (Number(seg.value) || 0) / total;
      const dash = frac * circ;
      segData.push({ color: seg.color, dash, start: acc, delay: i * 0.14 });
      acc += dash;
    });

    let rings = '';
    segData.forEach((s) => {
      // strokeDasharray = [dash, rest]; شروع خالی (offset = circ) و بعد انیمیت می‌شود به offset نهایی
      rings += `<circle class="ring" r="${r}" cx="${cx}" cy="${cx}"
        data-dash="${s.dash}" data-start="${s.start}"
        style="--circ:${circ}; stroke:${s.color}; stroke-dasharray:0 ${circ}; stroke-dashoffset:${-s.start}; transition-delay:${s.delay}s"></circle>`;
    });

    donut.innerHTML =
      `<svg viewBox="0 0 ${size} ${size}">
        <circle class="track" r="${r}" cx="${cx}" cy="${cx}"></circle>
        ${rings}
      </svg>
      <div class="av-donut-center"><b data-count="${opts.centerValue || 0}">0</b><small>${opts.centerLabel || ''}</small></div>`;

    const legend = el('div', 'av-legend');
    opts.segments.forEach((seg) => {
      legend.appendChild(el('div', 'row',
        `<span class="dot" style="background:${seg.color}"></span>${seg.label}<b>${seg.display != null ? seg.display : seg.value}</b>`));
    });

    wrap.appendChild(donut);
    wrap.appendChild(legend);

    // انیمیشن پرشدن حلقه‌ها با تغییر dasharray
    requestAnimationFrame(() => {
      donut.querySelectorAll('.ring').forEach((c) => {
        const dash = parseFloat(c.dataset.dash) || 0;
        c.style.transition = 'stroke-dasharray 1.1s ' + (getComputedStyle(document.documentElement).getPropertyValue('--av-ease') || 'ease');
        if (prefersReduced) {
          c.style.strokeDasharray = dash + ' ' + circ;
        } else {
          requestAnimationFrame(() => { c.style.strokeDasharray = dash + ' ' + circ; });
        }
      });
      const centerB = donut.querySelector('.av-donut-center b');
      AV.animateCount(centerB, Number(centerB.dataset.count), { format: (v) => v.toLocaleString('en-US') });
    });
    return wrap;
  };

  /* ---------- نمودار میله‌ای مینی (روند) ---------- */
  // data: [{v, label}], max optional, alt(boolean color)
  AV.spark = function (data, opts) {
    opts = opts || {};
    const max = opts.max || Math.max(1, ...data.map((d) => Number(d.v) || 0));
    const bars = el('div', 'av-spark');
    data.forEach((d, i) => {
      const h = Math.max(6, ((Number(d.v) || 0) / max) * 84);
      const b = el('div', 'bar' + (opts.alt ? ' alt' : ''));
      b.style.height = h + 'px';
      b.style.animationDelay = (i * 0.06) + 's';
      b.title = (d.label || '') + ': ' + (d.v || 0);
      bars.appendChild(b);
    });
    const box = el('div');
    box.appendChild(bars);
    if (data.some((d) => d.label)) {
      const labels = el('div', 'av-spark-labels');
      data.forEach((d) => labels.appendChild(el('span', null, d.label || '')));
      box.appendChild(labels);
    }
    return box;
  };

  /* ---------- خواندن شمارش‌های زنده از پنل مدیریت ---------- */
  AV.fetchAdminCounts = function () {
    return fetch('api/admin_exchange_api.php?action=counts', { cache: 'no-store' })
      .then((r) => r.json())
      .then((d) => (d && d.success ? d.counts : null))
      .catch(() => null);
  };

  /* ---------- نقشه پیشرفت وضعیت -> تعداد گام روشن ---------- */
  AV.statusToSteps = function (status, total) {
    total = total || 5;
    const map = {
      pending: 2, approved: 3, awaiting_payment: 3,
      payment_submitted: 4, completed: total, rejected: 2, active: total - 1
    };
    return map[status] != null ? map[status] : 1;
  };

  /* ---------- ساخت نوار گام مینی روی کارت‌ها ---------- */
  AV.stepsMini = function (on, total) {
    total = total || 5;
    const wrap = el('div', 'av-steps-mini');
    for (let i = 1; i <= total; i++) {
      const seg = el('div', 'seg' + (i < on ? ' on' : i === on ? ' cur' : ''));
      wrap.appendChild(seg);
    }
    return wrap;
  };

  /* ---------- IntersectionObserver برای فعال‌سازی انیمیشن هنگام دیده‌شدن ---------- */
  AV.revealOnView = function (selector) {
    if (!('IntersectionObserver' in window) || prefersReduced) return;
    const io = new IntersectionObserver((entries) => {
      entries.forEach((e) => { if (e.isIntersecting) { e.target.style.animationPlayState = 'running'; io.unobserve(e.target); } });
    }, { threshold: 0.15 });
    document.querySelectorAll(selector).forEach((n) => io.observe(n));
  };

  AV.ready = function (fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  };
})();
