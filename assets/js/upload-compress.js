/*
 * upload-compress.js — (اصلاح ۶) فشرده‌سازی سمت کلاینت پیش از آپلود
 * ----------------------------------------------------------------
 * عکس‌های گوشی اغلب ۵ تا ۱۵ مگابایت هستند؛ آپلود خامِ آن‌ها روی
 * اینترنت موبایل بسیار کند است. این ابزار قبل از ارسال، تصویر را
 * روی canvas بازترسیم و با کیفیت مناسب فشرده می‌کند تا حجم به چند
 * صد کیلوبایت برسد و آپلود چند برابر سریع‌تر شود.
 *
 * PDF و فایل‌های غیرتصویری بدون تغییر عبور می‌کنند.
 *
 * استفاده:
 *   const small = await AvaCompressImage(file);           // یک فایل
 *   const list  = await AvaCompressFiles(fileListOrArray); // چند فایل
 *   AvaAppendFiles(formData, 'file[]', await AvaCompressFiles(files));
 */
(function (global) {
    'use strict';

    var DEFAULTS = {
        maxWidth: 1600,      // حداکثر عرض/ارتفاع خروجی (برای فیش کاملاً خواناست)
        maxHeight: 1600,
        quality: 0.72,       // کیفیت JPEG
        // اگر فایل کوچکتر از این بود، اصلاً فشرده نکن (اتلاف وقت است)
        skipUnderBytes: 300 * 1024,
        mime: 'image/jpeg'
    };

    function isImage(file) {
        return file && typeof file.type === 'string' && file.type.indexOf('image/') === 0
            // GIF را دست‌نخورده بگذار (انیمیشن از بین می‌رود)
            && file.type !== 'image/gif';
    }

    function readAsDataURL(file) {
        return new Promise(function (resolve, reject) {
            var r = new FileReader();
            r.onload = function () { resolve(r.result); };
            r.onerror = function () { reject(new Error('read_failed')); };
            r.readAsDataURL(file);
        });
    }

    function loadImage(dataUrl) {
        return new Promise(function (resolve, reject) {
            var img = new Image();
            img.onload = function () { resolve(img); };
            img.onerror = function () { reject(new Error('img_failed')); };
            img.src = dataUrl;
        });
    }

    function canvasToBlob(canvas, mime, quality) {
        return new Promise(function (resolve) {
            if (canvas.toBlob) {
                canvas.toBlob(function (blob) { resolve(blob); }, mime, quality);
            } else {
                // فالبک برای مرورگرهای خیلی قدیمی
                try {
                    var durl = canvas.toDataURL(mime, quality);
                    var bin = atob(durl.split(',')[1]);
                    var arr = new Uint8Array(bin.length);
                    for (var i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
                    resolve(new Blob([arr], { type: mime }));
                } catch (e) { resolve(null); }
            }
        });
    }

    /* یک فایل تصویری را فشرده و به‌صورت File برمی‌گرداند. غیرتصویری = بدون تغییر. */
    function compressImage(file, opts) {
        opts = Object.assign({}, DEFAULTS, opts || {});
        return new Promise(function (resolve) {
            try {
                if (!isImage(file)) { resolve(file); return; }
                if (file.size && file.size <= opts.skipUnderBytes) { resolve(file); return; }

                readAsDataURL(file)
                    .then(loadImage)
                    .then(function (img) {
                        var w = img.naturalWidth || img.width;
                        var h = img.naturalHeight || img.height;
                        if (!w || !h) { resolve(file); return null; }

                        var scale = Math.min(1, opts.maxWidth / w, opts.maxHeight / h);
                        var nw = Math.max(1, Math.round(w * scale));
                        var nh = Math.max(1, Math.round(h * scale));

                        var canvas = document.createElement('canvas');
                        canvas.width = nw; canvas.height = nh;
                        var ctx = canvas.getContext('2d');
                        // پس‌زمینه سفید برای شفاف‌ها (PNG → JPEG)
                        ctx.fillStyle = '#ffffff';
                        ctx.fillRect(0, 0, nw, nh);
                        ctx.drawImage(img, 0, 0, nw, nh);

                        return canvasToBlob(canvas, opts.mime, opts.quality).then(function (blob) {
                            if (!blob) { resolve(file); return; }
                            // اگر فشرده‌شده از اصل بزرگ‌تر شد، اصل را بفرست
                            if (blob.size >= file.size) { resolve(file); return; }
                            var newName = (file.name || 'image').replace(/\.[^.]+$/, '') + '.jpg';
                            var out;
                            try {
                                out = new File([blob], newName, { type: opts.mime, lastModified: Date.now() });
                            } catch (e) {
                                // بعضی مرورگرها سازندهٔ File را ندارند → از Blob استفاده کن و نام را ضمیمه کن
                                out = blob; out.name = newName;
                            }
                            resolve(out);
                        });
                    })
                    .catch(function () { resolve(file); });
            } catch (e) { resolve(file); }
        });
    }

    /* آرایه/FileList از فایل‌ها را به‌صورت موازی فشرده می‌کند. */
    function compressFiles(files, opts) {
        var arr = Array.prototype.slice.call(files || []);
        return Promise.all(arr.map(function (f) { return compressImage(f, opts); }));
    }

    /* کمک‌کننده: فایل‌های فشرده‌شده را به FormData اضافه می‌کند. */
    function appendFiles(formData, field, files) {
        (files || []).forEach(function (f) {
            // اگر Blob بدون نام بود، نام بده
            if (f && !f.name) { formData.append(field, f, 'receipt.jpg'); }
            else { formData.append(field, f, f.name); }
        });
        return formData;
    }

    global.AvaCompressImage = compressImage;
    global.AvaCompressFiles = compressFiles;
    global.AvaAppendFiles = appendFiles;
})(window);
