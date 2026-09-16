// assets/js/push-init.js
// نسخه نهایی با چک کردن وضعیت نوتیفیکیشن و دیتابیس

// ---------------------------------------------------------------------------
// پشتیبانی امن از Notification API
// iOS Safari این API را اصلاً تعریف نمی‌کند (به‌جز PWA نصب‌شده روی iOS 16.4+)،
// بنابراین دسترسی مستقیم به `Notification` روی آیفون ReferenceError می‌دهد
// («Can't find variable: Notification»). این دو تابع همیشه امن هستند.
// ---------------------------------------------------------------------------
function avaNotifSupported() {
    return typeof Notification !== 'undefined';
}
function avaNotifPermission() {
    return avaNotifSupported() ? Notification.permission : 'unsupported';
}

const VAPID_PUBLIC_KEY = 'BFYQrNpREIzsQ5kfKXCXB0RPRmH9U3wrQkcbfZP5UC90bMgABYu9J-3XQNoOaatmEO-lSaOjOpmLKihMO381GkM';

// تبدیل base64 به Uint8Array
function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; i++) {
        outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
}

// تبدیل ArrayBuffer به base64url (URL-safe، بدون padding)
// کتابخانه‌ی WebPush سمت سرور کلیدها را با Base64Url decode می‌کند؛
// اگر با base64 معمولی (+ / =) ذخیره شوند، رمزنگاری push خراب می‌شود و
// وقتی اپ بسته است هیچ نوتیفیکیشنی تحویل داده نمی‌شود.
function arrayBufferToBase64Url(buffer) {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.length; i++) {
        binary += String.fromCharCode(bytes[i]);
    }
    return window.btoa(binary)
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/, '');
}

// ذخیره اشتراک در سرور
async function saveSubscriptionToServer(subscription) {
    try {
        const response = await fetch('/ledor/api/save_push_subscription.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                endpoint: subscription.endpoint,
                p256dh: arrayBufferToBase64Url(subscription.getKey('p256dh')),
                auth: arrayBufferToBase64Url(subscription.getKey('auth'))
            })
        });
        const result = await response.json();
        return result.success;
    } catch (error) {
        console.error('Save error:', error);
        return false;
    }
}

// چک کردن وجود اشتراک در دیتابیس سرور
async function checkSubscriptionInDatabase() {
    try {
        const response = await fetch('/ledor/api/check_push_subscription.php');
        const result = await response.json();
        return result.has_subscription;
    } catch (error) {
        console.error('Check subscription error:', error);
        return false;
    }
}

// ثبت Background Sync (یک‌باره) + Periodic Background Sync (دوره‌ای ۵ دقیقه)
// هدف: وقتی PWA نصب شده و اپ بسته/در بک‌گراند است، هر ~۵ دقیقه یک‌بار
// اپ در پس‌زمینه بیدار شود، آپدیت‌ها را از سرور بگیرد و نوتیف‌های
// نمایش‌داده‌نشده را به‌صورت toast روی لاک‌اسکرین/نوتیف‌سنتر نشان دهد.
const BG_PERIODIC_TAG = 'avapay-notif-sync';
const BG_PERIODIC_MIN_INTERVAL = 5 * 60 * 1000; // ۵ دقیقه

async function registerBackgroundSync() {
    if (!('serviceWorker' in navigator)) return false;

    let ok = false;
    let registration;
    try {
        registration = await navigator.serviceWorker.ready;
    } catch (e) {
        return false;
    }

    // 1) یک‌باره‌ی معمولی (fallback برای مرورگرهایی که periodicSync ندارند)
    if ('SyncManager' in window && registration.sync) {
        try {
            await registration.sync.register('check-notifications');
            ok = true;
        } catch (error) {
        }
    }

    // 2) Periodic Background Sync (کروم/اندروید، فقط وقتی PWA نصب شده)
    await registerPeriodicSync(registration);

    return ok;
}

async function registerPeriodicSync(registration) {
    try {
        if (!registration) registration = await navigator.serviceWorker.ready;
        if (!('periodicSync' in registration)) {
            return false;
        }

        // بررسی/درخواست مجوز
        let status = { state: 'granted' };
        if (navigator.permissions && navigator.permissions.query) {
            try {
                status = await navigator.permissions.query({ name: 'periodic-background-sync' });
            } catch (e) {
                // برخی مرورگرها این نام مجوز را نمی‌شناسند؛ مستقیم تلاش می‌کنیم.
                status = { state: 'granted' };
            }
        }

        if (status.state !== 'granted') {
            return false;
        }

        // اگر قبلاً ثبت شده، دوباره ثبت نکن
        try {
            const tags = await registration.periodicSync.getTags();
            if (tags && tags.includes(BG_PERIODIC_TAG)) {
                return true;
            }
        } catch (e) {}

        await registration.periodicSync.register(BG_PERIODIC_TAG, {
            minInterval: BG_PERIODIC_MIN_INTERVAL
        });
        return true;
    } catch (error) {
        return false;
    }
}

// درخواست دستی بررسی نوتیف در پس‌زمینه (fallback وقتی اپ باز/فوکوس می‌شود
// یا وقتی periodicSync در دسترس نیست). سرویس‌ورکر را وادار می‌کند نوتیف‌های
// معوق را بکشد و در صورت بسته‌بودن اپ به‌صورت توست نشان دهد.
async function triggerManualBgCheck() {
    try {
        if (!('serviceWorker' in navigator)) return;
        const reg = await navigator.serviceWorker.ready;
        if (reg.active) {
            reg.active.postMessage({ type: 'MANUAL_BG_CHECK' });
        }
    } catch (e) {
    }
}
window.triggerManualBgCheck = triggerManualBgCheck;

// درخواست و ثبت اشتراک جدید
async function requestAndSubscribe() {
    
    if (!('Notification' in window)) {
        showToast('مرورگر شما از نوتیفیکیشن پشتیبانی نمی‌کند', 'error');
        return false;
    }
    
    if (!('serviceWorker' in navigator)) {
        showToast('Service Worker پشتیبانی نمی‌شود', 'error');
        return false;
    }
    
    if (!('PushManager' in window)) {
        showToast('Push Notification پشتیبانی نمی‌شود', 'error');
        return false;
    }
    
    if (!avaNotifSupported()) {
        showToast('مرورگر شما از نوتیفیکیشن پشتیبانی نمی‌کند', 'error');
        return false;
    }

    let permission = await Notification.requestPermission();
    
    if (permission !== 'granted') {
        showToast('برای دریافت نوتیفیکیشن باید اجازه دهید', 'error');
        return false;
    }
    
    try {
        const registration = await navigator.serviceWorker.ready;
        let subscription = await registration.pushManager.getSubscription();
        
        if (!subscription) {
            subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC_KEY)
            });
        } else {
        }
        
        const saved = await saveSubscriptionToServer(subscription);
        
        if (saved) {
            await registerBackgroundSync();
            showToast('✅ نوتیفیکیشن با موفقیت فعال شد!', 'success');
            
            // حذف پرامپت اگر وجود دارد
            const promptDiv = document.getElementById('push-prompt');
            if (promptDiv) promptDiv.remove();
            
            return true;
        } else {
            showToast('خطا در ذخیره اشتراک', 'error');
            return false;
        }
    } catch (error) {
        console.error('Subscription error:', error);
        showToast('خطا در فعال‌سازی نوتیفیکیشن: ' + error.message, 'error');
        return false;
    }
}

// نمایش توست
function showToast(message, type) {
    const existingToast = document.querySelector('.push-toast');
    if (existingToast) existingToast.remove();
    
    const toast = document.createElement('div');
    toast.className = 'push-toast';
    toast.style.cssText = `
        position: fixed;
        bottom: 100px;
        left: 50%;
        transform: translateX(-50%);
        background: ${type === 'success' ? '#4CD964' : type === 'error' ? '#FF3B30' : '#FFD700'};
        color: ${type === 'success' ? '#1a1a2e' : 'white'};
        padding: 12px 24px;
        border-radius: 50px;
        z-index: 10001;
        font-size: 0.9rem;
        white-space: nowrap;
        box-shadow: 0 4px 15px rgba(0,0,0,0.3);
        font-weight: bold;
    `;
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

// تابع اصلی - با چک کردن کامل وضعیت
window.initPushNotifications = async function() {
    
    // بررسی اگر کاربر قبلاً گفته "دیگر نمایش نده"
    if (localStorage.getItem('push_never_show') === 'true') {
        return;
    }
    
    // اگر مرورگر اصلاً Notification را پشتیبانی نکند (مثل Safari روی iOS)، بی‌صدا خارج می‌شویم
    if (!avaNotifSupported()) {
        return;
    }

    // بررسی وضعیت مجوز مرورگر
    const permission = avaNotifPermission();
    
    // حالت 1: کاربر قبلاً اجازه داده (granted)
    if (permission === 'granted') {
        
        // بررسی وجود Service Worker
        if (!('serviceWorker' in navigator)) {
            return;
        }
        
        const registration = await navigator.serviceWorker.ready;
        let subscription = await registration.pushManager.getSubscription();
        
        // چک کردن وجود اشتراک در دیتابیس سرور
        const hasInDatabase = await checkSubscriptionInDatabase();
        
        // اگر اشتراک در مرورگر وجود ندارد OR در دیتابیس وجود ندارد
        if (!subscription || !hasInDatabase) {
            
            // حذف اشتراک قدیمی اگر وجود دارد
            if (subscription) {
                try {
                    await subscription.unsubscribe();
                } catch(e) {
                }
            }
            
            // ایجاد اشتراک جدید
            try {
                subscription = await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC_KEY)
                });
                
                // ذخیره در سرور
                const saved = await saveSubscriptionToServer(subscription);
                if (saved) {
                    await registerBackgroundSync();
                    showToast('✅ نوتیفیکیشن مجدداً فعال شد!', 'success');
                } else {
                    // اگر ذخیره نشد، نمایش پرامپت برای تلاش مجدد
                    showNotificationPrompt();
                }
            } catch (error) {
                console.error('Error creating subscription:', error);
                showNotificationPrompt();
            }
        } else {
            // اطمینان از فعال بودن sync دوره‌ای حتی وقتی اشتراک از قبل هست
            await registerBackgroundSync();
        }
        return;
    }
    
    // حالت 2: کاربر قبلاً رد کرده (denied)
    if (permission === 'denied') {
        localStorage.setItem('push_denied', 'true');
        return;
    }
    
    // حالت 3: هنوز تصمیم نگرفته (default) - نمایش پرامپت
    if (permission === 'default') {
        setTimeout(() => {
            if (avaNotifPermission() === 'default') {
                showNotificationPrompt();
            }
        }, 2000);
    }
};

// نمایش پرامپت درخواست نوتیفیکیشن
function showNotificationPrompt() {
    // اگر قبلاً پرامپت نمایش داده شده
    if (document.getElementById('push-prompt')) {
        return;
    }
    
    // اگر کاربر قبلاً تصمیم گرفته
    if (avaNotifPermission() !== 'default') {
        return;
    }
    
    const promptDiv = document.createElement('div');
    promptDiv.id = 'push-prompt';
    promptDiv.innerHTML = `
        <div style="
            position: fixed;
            bottom: 20px;
            right: 20px;
            left: auto;
            max-width: 350px;
            background: linear-gradient(135deg, #1a1a2e, #16213e);
            border-radius: 16px;
            padding: 16px;
            z-index: 10000;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            border: 1px solid rgba(255,215,0,0.3);
            animation: slideUp 0.3s ease;
            direction: rtl;
        ">
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                <i class="fas fa-bell" style="font-size: 24px; color: #FFD700;"></i>
                <div style="flex: 1;">
                    <strong style="color: white; display: block;">فعال کردن نوتیفیکیشن</strong>
                    <p style="font-size: 12px; color: #aaa; margin: 0;">دریافت لحظه‌ای پیشنهادات و اطلاعیه‌ها</p>
                </div>
            </div>
            <div style="display: flex; gap: 10px;">
                <button id="push-enable" style="flex:1; background: linear-gradient(135deg,#FFD700,#FFA500); border:none; padding:10px; border-radius:10px; color:#1a1a2e; font-weight:bold; cursor:pointer;">
                    <i class="fas fa-check"></i> فعال کردن
                </button>
                <button id="push-deny" style="flex:1; background:rgba(255,59,48,0.2); border:1px solid #FF3B30; padding:10px; border-radius:10px; color:#FF3B30; cursor:pointer;">
                    <i class="fas fa-times"></i> بعداً
                </button>
            </div>
            <button id="push-never" style="width:100%; margin-top:10px; background:transparent; border:none; color:rgba(255,255,255,0.4); font-size:0.7rem; cursor:pointer; padding:5px;">
                دیگر نمایش نده
            </button>
        </div>
    `;
    document.body.appendChild(promptDiv);
    
    // دکمه فعال کردن
    document.getElementById('push-enable').onclick = async () => {
        promptDiv.remove();
        await requestAndSubscribe();
    };
    
    // دکمه بعداً
    document.getElementById('push-deny').onclick = () => {
        promptDiv.remove();
        sessionStorage.setItem('push_prompt_shown', 'true');
    };
    
    // دکمه دیگر نمایش نده
    document.getElementById('push-never').onclick = () => {
        localStorage.setItem('push_never_show', 'true');
        promptDiv.remove();
    };
}

// اضافه کردن استایل انیمیشن
if (!document.getElementById('push-prompt-style')) {
    const style = document.createElement('style');
    style.id = 'push-prompt-style';
    style.textContent = `
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(50px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @media (max-width: 600px) {
            #push-prompt > div {
                left: 20px !important;
                right: 20px !important;
                max-width: none !important;
            }
        }
    `;
    document.head.appendChild(style);
}

// ------------------------------------------------------------------
// Fallback دوره‌ای سمت صفحه (برای iOS/سافاری و هر مرورگری که Periodic
// Background Sync ندارد). تا وقتی صفحه/تب زنده است، هر ۵ دقیقه یک‌بار از
// سرور نوتیف‌های نمایش‌داده‌نشده را می‌کشد و در صورت granted بودن مجوز،
// آن‌ها را به‌صورت toast (سیستمی) نمایش می‌دهد. این پوشش مکملِ Web Push
// واقعی است که سرور هنگام بسته‌بودن اپ می‌فرستد.
const BG_FALLBACK_INTERVAL = 5 * 60 * 1000; // ۵ دقیقه
let _bgFallbackTimer = null;

function startBgFallbackPolling() {
    if (_bgFallbackTimer) return;
    // هر ۵ دقیقه یک‌بار، حتی اگر مجوز نوتیفیکیشن داده نشده باشد، سرویس‌ورکر را
    // وادار می‌کنیم نسخه‌ی جدید و نوتیف‌های معوق را بررسی کند (آپدیت/توست جدید).
    _bgFallbackTimer = setInterval(() => {
        triggerManualBgCheck();
    }, BG_FALLBACK_INTERVAL);
    // یک بار هم بلافاصله پس از راه‌اندازی
    setTimeout(triggerManualBgCheck, 4000);
}

// وقتی کاربر به اپ برمی‌گردد (focus/visible)، فوراً یک‌بار چک کن تا
// نوتیف‌هایی که در فاصله‌ی نبودن ثبت شده‌اند سریع نمایش داده شوند.
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
        triggerManualBgCheck();
    }
});
window.addEventListener('focus', () => {
    triggerManualBgCheck();
});

// راه‌اندازی خودکار هنگام لود صفحه
function _bootPush() {
    setTimeout(() => {
        if (window.initPushNotifications) {
            window.initPushNotifications();
        }
        startBgFallbackPolling();
    }, 3000);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', _bootPush);
} else {
    _bootPush();
}