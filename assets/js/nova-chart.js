/* ================================================================
   AvaPay · نمودار سطحی مشترک (Nova)
   AvaPay.spark را با یک نمودار سطحی SVG جایگزین می‌کند.
   ورودی/خروجی دقیقاً مثل نسخه‌ی میله‌ای است، پس هر صفحه‌ای که
   قبلاً از AV.spark استفاده می‌کرد بدون تغییر کار می‌کند.
   ================================================================ */
/* ==== نمودار سطحیِ «نبض بازار» ====
   AV.spark پیش‌فرض میله‌ای است. اینجا فقط روی همین صفحه با یک نمودار
   سطحیِ SVG جایگزین می‌شود؛ ورودی و خروجی دقیقاً مثل قبل است، پس هیچ
   بخشی از منطق صفحه تغییر نمی‌کند. */
(function () {
  var _t = 0;
  function install() {
    if (!window.AvaPay) { if (_t++ < 60) return setTimeout(install, 60); return; }
    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function chartHeight() {
      var w = window.innerWidth;
      return w >= 1024 ? 124 : (w >= 681 ? 112 : 96);
    }

    // منحنی نرم از میان نقاط (Catmull-Rom → Bézier)
    function smoothPath(pts) {
      if (pts.length < 2) return '';
      var d = 'M' + pts[0][0].toFixed(1) + ',' + pts[0][1].toFixed(1);
      for (var i = 0; i < pts.length - 1; i++) {
        var p0 = pts[i - 1] || pts[i], p1 = pts[i], p2 = pts[i + 1], p3 = pts[i + 2] || p2;
        var c1x = p1[0] + (p2[0] - p0[0]) / 6, c1y = p1[1] + (p2[1] - p0[1]) / 6;
        var c2x = p2[0] - (p3[0] - p1[0]) / 6, c2y = p2[1] - (p3[1] - p1[1]) / 6;
        d += 'C' + c1x.toFixed(1) + ',' + c1y.toFixed(1) + ' ' +
                   c2x.toFixed(1) + ',' + c2y.toFixed(1) + ' ' +
                   p2[0].toFixed(1) + ',' + p2[1].toFixed(1);
      }
      return d;
    }

    window.AvaPay.spark = function (data, opts) {
      opts = opts || {};
      var box = document.createElement('div');
      var lastW = 0, lastPts = [];

      function render() {
        var host = box.parentElement;
        var w = Math.round((box.clientWidth || (host && host.clientWidth) || 320));
        if (w < 60) w = 320;
        lastW = w;

        var h = chartHeight(), padT = 14, padB = 10;
        var vals = data.map(function (d) { return Number(d.v) || 0; });
        var max = opts.max || Math.max.apply(null, vals) || 1;
        var n = vals.length, step = n > 1 ? w / (n - 1) : w;

        var pts = vals.map(function (v, i) {
          var x = n > 1 ? i * step : w / 2;
          var y = h - padB - (v / max) * (h - padT - padB);
          return [x, y];
        });
        // نقاط لبه کمی به داخل کشیده می‌شوند تا نشانگرها بریده نشوند
        if (pts.length) { pts[0][0] = Math.max(pts[0][0], 4); pts[pts.length - 1][0] = Math.min(pts[pts.length - 1][0], w - 4); }

        lastPts = pts;
        var line = smoothPath(pts);
        var area = line + 'L' + pts[pts.length - 1][0].toFixed(1) + ',' + h + 'L' + pts[0][0].toFixed(1) + ',' + h + 'Z';

        // خط‌کش‌های افقی — چهار سطح
        var grid = '';
        for (var g = 1; g <= 3; g++) {
          var gy = padT + ((h - padT - padB) / 4) * g;
          grid += '<line class="grid" x1="0" y1="' + gy.toFixed(1) + '" x2="' + w + '" y2="' + gy.toFixed(1) + '"/>';
        }

        var dots = pts.map(function (p, i) {
          var last = i === pts.length - 1;
          var out = '';
          if (last) out += '<circle class="halo" cx="' + p[0].toFixed(1) + '" cy="' + p[1].toFixed(1) + '" r="9"/>';
          out += '<circle class="dot' + (last ? ' last' : '') + '" cx="' + p[0].toFixed(1) + '" cy="' + p[1].toFixed(1) +
                 '" r="' + (last ? 5 : 3.2) + '" style="animation-delay:' + (reduced ? 0 : 0.55 + i * 0.07) + 's">' +
                 '<title>' + (data[i].label || '') + ': ' + (vals[i] || 0).toLocaleString('en-US') + '</title></circle>';
          return out;
        }).join('');

        var len = Math.round(w * 1.6);
        box.innerHTML =
          '<svg class="axn-area" viewBox="0 0 ' + w + ' ' + h + '" width="' + w + '" height="' + h + '" role="img" aria-label="نمودار حجم معاملات ۶ ماه اخیر">' +
            '<defs>' +
              '<linearGradient id="axAreaGrad" x1="0" y1="0" x2="0" y2="1">' +
                '<stop offset="0%" stop-color="#7C5CFF" stop-opacity="0.42"/>' +
                '<stop offset="55%" stop-color="#FF4FA3" stop-opacity="0.14"/>' +
                '<stop offset="100%" stop-color="#FF4FA3" stop-opacity="0"/>' +
              '</linearGradient>' +
              '<linearGradient id="axLineGrad" x1="0" y1="0" x2="1" y2="0">' +
                '<stop offset="0%" stop-color="#7C5CFF"/>' +
                '<stop offset="60%" stop-color="#FF4FA3"/>' +
                '<stop offset="100%" stop-color="#F6D68A"/>' +
              '</linearGradient>' +
            '</defs>' +
            grid +
            '<line class="base" x1="0" y1="' + (h - padB) + '" x2="' + w + '" y2="' + (h - padB) + '"/>' +
            '<path class="fill" d="' + area + '" fill="url(#axAreaGrad)"/>' +
            '<path class="line" d="' + line + '" style="--len:' + len + ';' + (reduced ? 'animation:none;stroke-dashoffset:0;' : '') + '"/>' +
            dots +
          '</svg>' +
          '<div class="axn-tip" hidden></div>' +
          '<div class="axn-xlabels">' + data.map(function (d) { return '<span>' + (d.label || '') + '</span>'; }).join('') + '</div>';
      }

      // با کلیک/لمس روی نمودار، عدد همان ماه نشان داده می‌شود
      box.style.position = 'relative';
      function pick(ev) {
        var svg = box.querySelector('svg');
        if (!svg) return;
        var r = svg.getBoundingClientRect();
        var cx = (ev.touches && ev.touches[0] ? ev.touches[0].clientX : ev.clientX) - r.left;
        var best = 0, bestD = Infinity;
        lastPts.forEach(function (p, i) {
          var d = Math.abs(p[0] - cx);
          if (d < bestD) { bestD = d; best = i; }
        });
        var tip = box.querySelector('.axn-tip');
        if (!tip) return;
        var d0 = data[best] || {};
        var v = Number(d0.v) || 0;
        var html = '<span class="axn-tip-m">' + (d0.label || '') + '</span>';
        if (d0.meta && d0.meta.length) {
          html += d0.meta.map(function (m) {
            return '<span class="axn-tip-r' + (m.c ? ' ' + m.c : '') + '"><em>' + m.k + '</em><b>' + m.v + '</b></span>';
          }).join('');
        } else {
          html += '<span class="axn-tip-r"><em>ارزش</em><b>' + v.toLocaleString('en-US') + '</b></span>';
        }
        tip.innerHTML = html;
        tip.hidden = false;

        // جایگذاری با محدودسازی داخل قاب — در موبایل حباب از صفحه بیرون نزند
        var px = lastPts[best][0], py = lastPts[best][1];
        var bw = box.clientWidth || r.width;
        tip.style.left = '0px'; tip.style.top = '0px';
        tip.classList.remove('below');
        var tw = tip.offsetWidth, th = tip.offsetHeight, pad = 6;
        var left = px;
        var half = tw / 2;
        if (bw > tw + pad * 2) {
            left = Math.min(Math.max(px, half + pad), bw - half - pad);
        } else {
            left = bw / 2;   // قاب باریک‌تر از حباب: وسط‌چین
        }
        var top = py - 12;
        if (top - th < 0) { top = py + 12; tip.classList.add('below'); }   // بالا جا نشد ⇒ زیر نقطه
        tip.style.left = left + 'px';
        tip.style.top = top + 'px';
        box.querySelectorAll('.axn-area .dot').forEach(function (d, i) { d.classList.toggle('sel', i === best); });
        clearTimeout(tipTimer);
        tipTimer = setTimeout(function () { tip.hidden = true; }, 3200);
      }
      var tipTimer = 0;
      box.addEventListener('click', pick);
      box.addEventListener('touchstart', pick, { passive: true });

      render();
      requestAnimationFrame(render); // بعد از چیده‌شدن در DOM، با عرض واقعی دوباره رسم شود

      if (window.ResizeObserver) {
        var ro = new ResizeObserver(function () {
          var w = box.clientWidth || 0;
          if (Math.abs(w - lastW) > 12) render();
        });
        ro.observe(box);
      }
      return box;
    };
  }
  install();
})();
