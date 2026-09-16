// sw.js - نسخه نهایی با سیستم آپدیت و Push Notification + Periodic Background Sync
const CACHE_NAME = 'avapay-v3.3.6';   // لودرِ کاملاً جدید (عدد+نوارِ درخشان به‌جای رینگ) +
                                        // لانچ‌اسکرین‌های بومیِ iOS (apple-touch-startup-image) —
                                        // بدونِ این بامپ، کسی که splash.html را قبلاً دیده همچنان
                                        // نسخه‌ی کش‌شده‌ی قدیمی را می‌بیند
// فاصله‌ی حداقلی بیدارشدن دوره‌ای (بر حسب میلی‌ثانیه) - ۵ دقیقه.
// مرورگر ممکن است بازه‌ی واقعی را کمی طولانی‌تر کند (بسته به وضعیت باتری/شبکه).
const BG_SYNC_INTERVAL = 5 * 60 * 1000;
const BG_NOTIF_ENDPOINT = '/ledor/api/background_notifications.php?action=pending';
const ADMIN_TELEGRAM_ID = '5330629504';

// فقط فایل‌های ثابت پیش‌کش می‌شوند.
// صفحه‌های PHP (ورود/داشبورد/arad) وابسته به سشن‌اند و ممکن است ریدایرکت شوند،
// پس هرگز پیش‌کش نمی‌شوند تا کاربر خارج‌شده دوباره به داشبورد برنگردد.
const urlsToCache = [
  '/ledor/offline.html',
  '/ledor/version.json',
  '/ledor/assets/css/cards.css',
  '/ledor/assets/css/friends.css',
  '/ledor/assets/js/app.js',
  '/ledor/assets/js/check-auth.js',
  '/ledor/assets/js/notif.js',
  '/ledor/assets/js/friends.js',
  '/ledor/AVAPAY.PNG'
];

// منابع خارجی (CDN) جداگانه و «بهترین تلاش» کش می‌شوند.
// دلیل: cache.addAll اتمیک است و اگر فقط یکی از آدرس‌ها در دسترس نباشد،
// کل نصب Service Worker شکست می‌خورد. چون دسترسی به CDNها همیشه برقرار
// نیست، قرار دادن آن‌ها در لیست اصلی باعث می‌شد حالت آفلاین و
// نوتیفیکیشن‌ها برای کاربر کاملاً از کار بیفتد.
const optionalUrlsToCache = [
  'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css'
];

// نصب Service Worker
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => {
        // فایل‌های محلی: حیاتی هستند
        return cache.addAll(urlsToCache).then(() => {
          // منابع CDN: اگر در دسترس نبودند، نصب نباید شکست بخورد
          return Promise.all(
            optionalUrlsToCache.map(url =>
              cache.add(url).catch(() => { /* بی‌اهمیت: بعداً از شبکه خوانده می‌شود */ })
            )
          );
        });
      })
      .then(() => {
        return self.skipWaiting();
      })
      .catch(error => {
        console.error('❌ Installation failed:', error);
      })
  );
});

// فعال شدن Service Worker
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames.map(cacheName => {
          if (cacheName !== CACHE_NAME) {
            return caches.delete(cacheName);
          }
        })
      );
    }).then(() => {
      return self.clients.claim();
    })
  );
});

// مدیریت درخواست‌ها
self.addEventListener('fetch', event => {
  const request = event.request;
  const url = new URL(request.url);

  // ------------------------------------------------------------------
  // درخواست‌های ناوبری (باز کردن یک صفحه) هرگز از کش سرو نمی‌شوند.
  //
  // چرا؟ اگر پاسخِ کش‌شده حاصل یک ریدایرکت سرور باشد (redirected = true)،
  // مرورگر خطای «Response served by service worker has redirections» می‌دهد
  // و صفحه بالا نمی‌آید. ضمناً صفحه‌ی ورود/داشبورد وابسته به سشن هستند و
  // نسخه‌ی کش‌شده‌شان می‌تواند کاربر خارج‌شده را دوباره به داشبورد ببرد.
  // پس: همیشه از شبکه بگیر و فقط هنگام قطعی اینترنت صفحه‌ی آفلاین را بده.
  // ------------------------------------------------------------------
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request).catch(() => caches.match('/ledor/offline.html'))
    );
    return;
  }

  if (url.pathname.includes('.php') &&
      !url.pathname.includes('version.php')) {
    return;
  }

  if (request.method !== 'GET') return;
  if (url.pathname.includes('/api/')) return;

  const isImportantFile = url.pathname.includes('version.json') ||
                          url.pathname.includes('manifest.json') ||
                          url.pathname.includes('sw.js');

  if (isImportantFile) {
    event.respondWith(
      fetch(request)
        .then(response => {
          const responseClone = response.clone();
          caches.open(CACHE_NAME).then(cache => { cache.put(request, responseClone); });
          return response;
        })
        .catch(() => caches.match(request))
    );
  } else {
    event.respondWith(
      caches.match(request)
        .then(cachedResponse => {
          if (cachedResponse) {
            fetch(request).then(networkResponse => {
              if (networkResponse && networkResponse.status === 200 && !networkResponse.redirected) {
                const responseToCache = networkResponse.clone();
                caches.open(CACHE_NAME).then(cache => { cache.put(request, responseToCache); });
              }
            }).catch(() => {});
            return cachedResponse;
          }

          return fetch(request)
            .then(response => {
              if (!response || response.status !== 200 || response.redirected) return response;

              const isStaticFile = /\.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot|json)$/.test(url.pathname);
              if (isStaticFile) {
                const responseToCache = response.clone();
                caches.open(CACHE_NAME).then(cache => { cache.put(request, responseToCache); });
              }

              return response;
            })
            .catch(error => {
              if (request.headers.get('accept') && request.headers.get('accept').includes('text/html')) {
                return caches.match('/ledor/offline.html');
              }
            });
        })
    );
  }
});

// ==================== سازنده‌ی مشترک نوتیفیکیشن ====================
// هم مسیر push (سرور VAPID) و هم مسیر periodic background sync از این
// تابع استفاده می‌کنند تا ظاهر و رفتار توست‌ها یکسان بماند.
async function showNotifFromData(data) {
  data = data || {};
  const type = data.type || 'general';

  // ------- actions (فقط اگر مرورگر پشتیبانی کند) -------
  let actions = [];
  const actionsSupported =
    ('actions' in (self.Notification ? Notification.prototype : {})) ||
    (typeof Notification !== 'undefined' && Notification.maxActions);

  if (actionsSupported) {
    if (type === 'offer_received') {
      actions = [
        { action: 'view_offer', title: 'مشاهده پیشنهاد' },
        { action: 'dismiss', title: 'بستن' }
      ];
    } else if (type === 'offer_accepted') {
      actions = [{ action: 'view_deal', title: 'مشاهده معامله' }];
    } else if (type === 'offer_rejected') {
      actions = [{ action: 'view_market', title: 'مشاهده بازار' }];
    } else if (type === 'version_update') {
      actions = [
        { action: 'update', title: 'آپدیت کن' },
        { action: 'later', title: 'بعداً' }
      ];
    }
    const maxA = (typeof Notification !== 'undefined' && Notification.maxActions) ? Notification.maxActions : 2;
    if (actions.length > maxA) actions = actions.slice(0, maxA);
  }

  // ------- vibrate -------
  let vibrate = [200, 100, 200];
  if (type === 'offer_received') vibrate = [300, 100, 300, 100, 300];
  else if (type === 'offer_accepted') vibrate = [500, 100, 500];

  const options = {
    body: data.body || 'اعلان جدید دریافت شد',
    icon: data.icon || '/ledor/AVAPAY.PNG',
    badge: data.badge || '/ledor/AVAPAY.PNG',
    vibrate: vibrate,
    tag: type + '_' + (data.related_id || data.id || Date.now()),
    renotify: true,
    requireInteraction: (type === 'offer_received' || type === 'offer_accepted'),
    data: {
      url: data.url || '/ledor/arad.php',
      type: type,
      offerId: data.related_id || null,
      timestamp: Date.now()
    }
  };

  try { options.dir = 'rtl'; } catch (e) {}
  try { options.lang = 'fa'; } catch (e) {}
  if (actions.length) options.actions = actions;

  const title = data.title || '📩 AvaPay';

  try {
    await self.registration.showNotification(title, options);
  } catch (err) {
    try {
      await self.registration.showNotification(title, {
        body: data.body || 'اعلان جدید دریافت شد',
        icon: '/ledor/AVAPAY.PNG',
        badge: '/ledor/AVAPAY.PNG',
        data: { url: data.url || '/ledor/arad.php' }
      });
    } catch (e) {
      console.error('showNotification failed:', e);
    }
  }
}

// دریافت نوتیفیکیشن‌های نمایش‌داده‌نشده از سرور و نمایش آن‌ها به‌صورت توست.
// این تابع هم توسط periodicsync و هم توسط sync معمولی و هم توسط پیام
// دستی از صفحه (MANUAL_BG_CHECK) قابل فراخوانی است.
async function pullBackgroundNotifications() {
  try {
    const resp = await fetch(BG_NOTIF_ENDPOINT, {
      method: 'GET',
      credentials: 'include',
      headers: { 'Accept': 'application/json' },
      cache: 'no-store'
    });
    if (!resp.ok) return 0;

    const json = await resp.json();
    if (!json || !json.success || !Array.isArray(json.notifications)) return 0;

    let shown = 0;
    for (const n of json.notifications) {
      await showNotifFromData(n);
      shown++;
    }
    return shown;
  } catch (e) {
    console.error('pullBackgroundNotifications failed:', e);
    return 0;
  }
}

// ==================== PUSH NOTIFICATION ====================
// یک event listener واحد برای push (بدون تداخل)
// نسخه‌ی مقاوم‌شده برای اندروید/FCM:
//   - همیشه showNotification صدا زده می‌شود (حتی اگر payload خالی/خراب باشد)
//   - actions فقط وقتی ست می‌شود که مرورگر پشتیبانی کند (اندروید قدیمی
//     در صورت وجود actions ناسازگار گاهی کل نوتیف را drop می‌کرد)
//   - مقادیر مشکل‌ساز (dir/lang) با try/catch محافظت شده‌اند
/* ------------------------------------------------------------------ */
/* تمدید خودکار اشتراک push                                            */
/* ------------------------------------------------------------------ */
// مرورگر (به‌ویژه اندروید/FCM) گاهی اشتراک push را باطل می‌کند و یکی جدید
// صادر می‌کند. اگر این رویداد مدیریت نشود، از آن لحظه به بعد هیچ
// نوتیفیکیشنی نمی‌رسد — و چون اپ بسته است، کاربر متوجه نمی‌شود.
// اینجا بلافاصله دوباره مشترک می‌شویم و اشتراک جدید را به سرور می‌فرستیم.
const AVAPAY_VAPID_PUBLIC = 'BFYQrNpREIzsQ5kfKXCXB0RPRmH9U3wrQkcbfZP5UC90bMgABYu9J-3XQNoOaatmEO-lSaOjOpmLKihMO381GkM';

function avapayUrlB64ToUint8Array(base64String) {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const rawData = atob(base64);
  const outputArray = new Uint8Array(rawData.length);
  for (let i = 0; i < rawData.length; ++i) outputArray[i] = rawData.charCodeAt(i);
  return outputArray;
}

function avapayBufToB64Url(buffer) {
  if (!buffer) return '';
  const bytes = new Uint8Array(buffer);
  let binary = '';
  for (let i = 0; i < bytes.byteLength; i++) binary += String.fromCharCode(bytes[i]);
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

self.addEventListener('pushsubscriptionchange', event => {
  event.waitUntil((async () => {
    try {
      const oldEndpoint = (event.oldSubscription && event.oldSubscription.endpoint) || '';
      // اشتراک جدید را بگیر؛ اگر مرورگر خودش نداده بود، دوباره مشترک شو
      let newSub = event.newSubscription;
      if (!newSub) {
        newSub = await self.registration.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: avapayUrlB64ToUint8Array(AVAPAY_VAPID_PUBLIC)
        });
      }
      if (!newSub) return;
      await fetch('/ledor/api/push_resubscribe.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({
          oldEndpoint: oldEndpoint,
          endpoint: newSub.endpoint,
          p256dh: avapayBufToB64Url(newSub.getKey('p256dh')),
          auth:   avapayBufToB64Url(newSub.getKey('auth'))
        })
      });
    } catch (e) {
      console.error('pushsubscriptionchange failed:', e);
    }
  })());
});

self.addEventListener('push', event => {
  // کل بدنه‌ی نمایش نوتیف را داخل یک تابع async می‌گذاریم تا هر خطایی
  // در parse یا ساخت options باعث نشود showNotification اجرا نشود.
  event.waitUntil((async () => {
    let data = {};

    if (event.data) {
      try {
        data = event.data.json();
      } catch (e) {
        try {
          data = { title: '📩 AvaPay', body: event.data.text() };
        } catch (e2) {
          data = {};
        }
      }
    }

    // نمایش نوتیف با استفاده از سازنده‌ی مشترک.
    await showNotifFromData(data);

    // Badging API: عدد کوچک روی آیکون اپ — دقیقاً همان کاری که خیلی از اپ‌های
    // نیتیو (مثل تلگرام) انجام می‌دهند، ولی اینجا حتی وقتی اپ کاملاً بسته است
    // هم کار می‌کند چون از داخل Service Worker صدا زده می‌شود، نه از صفحه.
    // پشتیبانی مرورگر: کروم/اج (دسکتاپ+اندروید)، و iOS 16.4+ فقط وقتی PWA از
    // طریق Add to Home Screen نصب شده باشد — در غیر این صورت این API اصلاً
    // در self.navigator وجود ندارد، پس try/catch کافی و بی‌خطر است.
    if (typeof data.badge_count === 'number' && self.navigator && 'setAppBadge' in self.navigator) {
      try {
        if (data.badge_count > 0) await self.navigator.setAppBadge(data.badge_count);
        else await self.navigator.clearAppBadge();
      } catch (e) { /* مرورگرهایی که پشتیبانی نمی‌کنند یا اجازه نمی‌دهند */ }
    }
  })());
});

// ==================== NOTIFICATION CLICK ====================
self.addEventListener('notificationclick', event => {
  event.notification.close();

  const notifData = event.notification.data || {};
  const action = event.action;
  const type = notifData.type || 'general';

  let urlToOpen = notifData.url || '/ledor/arad.php';

  // تعیین URL بر اساس اکشن کلیک شده
  if (action === 'update') {
    // آپدیت کردن اپ
    event.waitUntil(
      self.clients.matchAll().then(clients => {
        clients.forEach(client => {
          client.postMessage({ type: 'UPDATE_NOW' });
        });
        return self.clients.openWindow('/ledor/arad.php');
      })
    );
    return;
  }

  if (action === 'view_offer' || type === 'offer_received') {
    urlToOpen = '/ledor/arad.php?tab=offers&section=received';
  } else if (action === 'view_deal' || type === 'offer_accepted') {
    urlToOpen = '/ledor/arad.php?tab=offers&section=sent';
  } else if (action === 'view_market' || type === 'offer_rejected') {
    urlToOpen = '/ledor/arad.php?tab=market';
  } else if (action === 'dismiss') {
    return; // فقط ببند
  }

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true })
      .then(windowClients => {
        // اگر تب باز هست، focus کن
        for (let client of windowClients) {
          if (client.url.includes('/ledor/') && 'focus' in client) {
            client.postMessage({
              type: 'NOTIFICATION_CLICKED',
              notifType: type,
              url: urlToOpen
            });
            return client.focus();
          }
        }
        // اگر تب باز نیست، باز کن
        return self.clients.openWindow(urlToOpen);
      })
  );
});

// ==================== MESSAGE HANDLER ====================
self.addEventListener('message', async event => {
  const data = event.data;

  if (data === 'skipWaiting') {
    self.skipWaiting();
    return;
  }

  if (data && data.type === 'CHECK_UPDATE') {
    try {
      const response = await fetch('/ledor/version.json?t=' + Date.now());
      const versionData = await response.json();
      const serverVersion = versionData.version;
      const currentVersion = CACHE_NAME.replace('avapay-v', '');
      const hasUpdate = serverVersion !== currentVersion;

      event.source.postMessage({
        type: 'UPDATE_STATUS',
        currentVersion,
        serverVersion,
        hasUpdate,
        updateMessage: versionData.update_message,
        forceUpdate: versionData.force_update || false,
        minVersion: versionData.min_version
      });

      if (versionData.force_update && hasUpdate) {
        const clients = await self.clients.matchAll();
        clients.forEach(client => {
          client.postMessage({ type: 'FORCE_UPDATE', version: serverVersion, message: versionData.update_message });
        });
      }
    } catch (error) {
      console.error('❌ Version check failed:', error);
      event.source.postMessage({ type: 'UPDATE_STATUS', error: true, message: error.message });
    }
  }

  if (data && data.type === 'UPDATE_NOW') {
    const cacheNames = await caches.keys();
    await Promise.all(
      cacheNames.map(cacheName => {
        if (cacheName !== CACHE_NAME) return caches.delete(cacheName);
      })
    );
    self.skipWaiting();
    const clients = await self.clients.matchAll();
    clients.forEach(client => {
      client.postMessage({ type: 'UPDATE_APPLIED', version: CACHE_NAME, timestamp: Date.now() });
    });
  }

  if (data && data.type === 'MANUAL_BG_CHECK') {
    // صفحه می‌تواند دستی یک بررسی نوتیف + آپدیت در پس‌زمینه را ماشه کند.
    const shown = await pullBackgroundNotifications();
    await checkVersionAndNotify();
    if (event.source && event.source.postMessage) {
      event.source.postMessage({ type: 'BG_CHECK_DONE', shown });
    }
    return;
  }

  if (data && data.type === 'GET_VERSION') {
    event.source.postMessage({
      type: 'VERSION_INFO',
      cacheName: CACHE_NAME,
      version: CACHE_NAME.replace('avapay-v', ''),
      urlsCached: urlsToCache.length
    });
  }
});

// ==================== PERIODIC BACKGROUND SYNC ====================
// هر ~۵ دقیقه یک‌بار (وقتی مرورگر اجازه دهد و PWA نصب شده باشد) اپ در
// بک‌گراند بیدار می‌شود، از سرور نوتیف‌های نمایش‌داده‌نشده را می‌گیرد و
// آن‌ها را به‌صورت toast روی لاک‌اسکرین/نوتیف‌سنتر نمایش می‌دهد — حتی اگر
// اپ کاملاً بسته باشد.
// نکته: این API فعلاً روی کروم/اندروید پشتیبانی می‌شود. روی iOS/سافاری،
// تحویل توست هنگام بسته‌بودن اپ از مسیر Web Push واقعی (push event بالا)
// انجام می‌شود که سرور با هر notifyUser آن را می‌فرستد.
self.addEventListener('periodicsync', event => {
  if (event.tag === 'avapay-notif-sync' || event.tag === 'check-notifications') {
    event.waitUntil((async () => {
      await pullBackgroundNotifications();
      await checkVersionAndNotify();
    })());
  }
});

// Background Sync (یک‌باره) — هم version و هم نوتیف‌های معوق را چک می‌کند.
// قبلاً فقط تگ 'check-version' هندل می‌شد در حالی که کلاینت
// 'check-notifications' ثبت می‌کرد؛ این ناسازگاری اصلاح شد.
self.addEventListener('sync', event => {
  if (event.tag === 'check-version') {
    event.waitUntil(checkVersionAndNotify());
  } else if (event.tag === 'check-notifications') {
    event.waitUntil(pullBackgroundNotifications());
  }
});

async function checkVersionAndNotify() {
  try {
    const response = await fetch('/ledor/version.json?t=' + Date.now(), { cache: 'no-store' });
    const versionData = await response.json();
    const serverVersion = versionData.version;

    // آخرین نسخه‌ای که به کاربر اطلاع داده‌ایم را در Cache Storage نگه می‌داریم
    // تا هربار به‌اشتباه پیام تکراری ندهیم (CACHE_NAME نسخه‌ی اپ نیست).
    let lastNotified = null;
    try {
      const metaCache = await caches.open('avapay-meta');
      const rec = await metaCache.match('last-version');
      if (rec) lastNotified = (await rec.text());
    } catch (e) {}

    if (serverVersion && serverVersion !== lastNotified) {
      // به همه‌ی کلاینت‌های باز اطلاع بده (اگر اپ باز است)
      const clients = await self.clients.matchAll({ includeUncontrolled: true });
      clients.forEach(client => {
        client.postMessage({ type: 'BACKGROUND_UPDATE_AVAILABLE', version: serverVersion, message: versionData.update_message });
      });

      // اگر هیچ کلاینتی باز نیست (اپ بسته)، یک نوتیف سیستمی نشان بده
      if (clients.length === 0 && self.registration && self.registration.showNotification) {
        try {
          await self.registration.showNotification('🚀 نسخه جدید AVA PAY', {
            body: (versionData.update_message || 'نسخه جدیدی در دسترس است').split('\n')[0],
            icon: '/ledor/AVAPAY.PNG',
            badge: '/ledor/AVAPAY.PNG',
            tag: 'avapay-update-' + serverVersion,
            data: { url: '/ledor/arad.php' }
          });
        } catch (e) {}
      }

      // ذخیره‌ی نسخه‌ی اطلاع‌داده‌شده
      try {
        const metaCache = await caches.open('avapay-meta');
        await metaCache.put('last-version', new Response(serverVersion));
      } catch (e) {}
    }
  } catch (error) {
    console.error('Background version check failed:', error);
  }
}

self.addEventListener('error', event => {
  console.error('SW Error:', event.error);
});
