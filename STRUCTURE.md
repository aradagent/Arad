# ساختار پروژه AVA PAY (نسخه تمیزشده)  /  Project Structure

> این نقشه به شما نشان می‌دهد هر فایل چه‌کاری می‌کند و از کدام نوع است (PHP / JS / CSS).
> بخش‌های واقعی برنامه از منوی پایین (footer menu) قابل دسترسی‌اند:
> **arad.php (تبادل ارزی) · dashboard.php (Home) · transactions.php · profile.php · currency rate (iframe)**

---

## 🟢 صفحات اصلی (Entry Pages – PHP)
این‌ها صفحاتی هستند که کاربر مستقیماً می‌بیند و در منوی پایین لینک شده‌اند.

| فایل | نقش |
|------|-----|
| `index.php` | ورودی سایت – ریدایرکت به login |
| `login.php` | صفحه ورود / ثبت‌نام |
| `dashboard.php` | خانه (Home) – کیف پول، فیش‌ها، Quick Actions |
| `arad.php` | تبادل ارزی (Exchange) |
| `transactions.php` | تاریخچه تراکنش‌ها |
| `profile.php` | پروفایل و تنظیمات کاربر |
| `money_transfer.php` | انتقال وجه (از داخل arad باز می‌شود) |
| `admin_panel.php` | پنل مدیریت (فیش‌ها، Top-up، Quick Actions، اسلایدها) |
| `manifest.php` | تولید مانیفست PWA (splash/آیکن) |
| `version.php` | مدیریت نسخهٔ PWA (توسط admin_panel و sw.js) |

## 🔵 بک‌اند / APIها (PHP در پوشهٔ `api/`)
هر فایل یک endpoint است که با fetch از سمت جاوااسکریپت صدا زده می‌شود.

| فایل | استفاده |
|------|---------|
| `api/login.php`, `register.php`, `logout.php`, `check-auth.php` | احراز هویت |
| `api/send_verification_code.php`, `verify_code.php` | کد تأیید |
| `api/user.php` | اطلاعات کاربر |
| `api/transaction.php` | تراکنش‌ها |
| `api/withdraw.php` | برداشت وجه (withdrawal modal) |
| `api/transfer_api.php` | انتقال وجه (money_transfer) |
| `api/topup_api.php` | شارژ حساب (Top-up) |
| `api/unpaid_invoice_api.php` | فیش‌های پرداخت‌نشده |
| `api/cards.php` | کارت‌های بانکی شرکت |
| `api/support.php`, `download.php` | پشتیبانی و دانلود |
| `api/notification_api.php` | نوتیفیکیشن‌ها (ایمیل/toast) |
| `api/save_push_subscription.php` | ثبت اشتراک push |
| `api/offer_api.php`, `deals_api.php`, `discount_api.php`, `ads_api.php` | موتور تبادل ارزی (arad) |
| `api/slides_quickactions_api.php` | اسلایدها و Quick Actionهای داشبورد |
| `api/currencyrate.php` | نرخ لحظه‌ای ارز (iframe منوی پایین) |
| `api/version_sync.php` | همگام‌سازی نسخهٔ PWA |
| `api/friends-api.php` | دوستان (توسط friends.js که service worker کش می‌کند) |

## 🟡 قطعات مشترک (PHP در پوشهٔ `includes/`)
| فایل | نقش |
|------|-----|
| `includes/footer_menu.php` | منوی پایین + لودینگ + نوتیف تبادل (در همهٔ صفحات) |
| `includes/logo_helper.php` | لوگوی داینامیک |
| `includes/withdrawal_modal_system.php` | مودال برداشت وجه (در arad) |

## 🎨 استایل‌ها (CSS در `assets/css/`)
| فایل | مربوط به |
|------|----------|
| `style.css` | استایل عمومی همهٔ صفحات |
| `aradphp.css` | صفحهٔ تبادل ارزی (arad) |
| `cards.css` | کارت‌ها در داشبورد |
| `friends.css` | بخش دوستان در داشبورد |
| `mainpage.css` | داشبورد |
| `notif.css` | نوتیفیکیشن‌ها |
| `ref.css` | بخش معرفی/referral |

## ⚙️ اسکریپت‌ها (JS در `assets/js/`)
| فایل | مربوط به |
|------|----------|
| `app.js` | منطق عمومی (dashboard, profile) |
| `check-auth.js` | بررسی احراز هویت |
| `notif.js` | سیستم نوتیفیکیشن داشبورد |
| `notification-system.js` | نوتیفیکیشن صفحهٔ تبادل (arad) |
| `push-init.js` | راه‌اندازی Web Push |
| `ref.js` | بخش معرفی/referral |
| `friends.js` | دوستان (کش‌شده توسط service worker) |
| `version-check.js` | مودال آپدیت اجباری نسخه (از footer_menu لود می‌شود) |

## 🗄️ پیکربندی و دیتابیس
| فایل | نقش |
|------|-----|
| `config/database.php` | اتصال به دیتابیس (توسط همه استفاده می‌شود) |
| `aradexch_app.sql` | دامپ کامل دیتابیس شما |

## 📱 فایل‌های PWA (ثابت – root)
`manifest.json` · `sw.js` (service worker) · `offline.html` · `splash.html` · `version.json`
و تصاویر: `AVAPAY.PNG`, `default-avatar.png`, `android-install.PNG.png`, `ios-install.PNG`

## 📦 وابستگی‌ها
`vendor/` (کتابخانه‌های Composer برای Web Push) · `composer.json` · `composer.lock`
> `composer.phar` حذف شد چون با `composer install` دوباره ساخته می‌شود.

## 📁 آپلودها
`uploads/` – فایل‌های آپلودی کاربران (آواتار، فیش، چت، اسلاید و ...).

---

## 🗃️ پوشهٔ `_ARCHIVE/`
فایل‌هایی که **از منوی پایین قابل دسترسی نیستند** به این پوشه منتقل شدند (حذف نشدند تا چیزی از دست نرود).
جزئیات در `_ARCHIVE/README.md`.
