// assets/js/version-check.js
// Version Manager - Force Update System

class VersionManager {
    constructor() {
        this.currentVersion = null;
        this.userVersion = null;
        this.updateModal = null;
        this.checkInterval = null;
        this.isChecking = false;
        this.updateInProgress = false;
        
        this.init();
    }
    
    async init() {
        
        // دریافت نسخه کاربر از دیتابیس
        await this.getUserVersionFromDB();
        
        // دریافت آخرین نسخه از سرور
        await this.getServerVersion();
        
        // بررسی و نمایش مودال در صورت نیاز
        await this.checkVersion();
        
        // بررسی دوره‌ای هر 10 ثانیه
        this.checkInterval = setInterval(() => this.checkVersion(), 10000);
        
        // بررسی هنگام برگشتن به تب
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) this.checkVersion();
        });
        
        // بررسی هنگام آنلاین شدن
        window.addEventListener('online', () => this.checkVersion());
        
        this.addStyles();
        
    }
    
    async getUserVersionFromDB() {
        try {
            const res = await fetch('/ledor/api/version_sync.php?action=get_version', { 
                cache: 'no-cache',
                headers: { 'Cache-Control': 'no-cache' }
            });
            
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            
            const data = await res.json();
            this.userVersion = data.version || '1.0.0';
            localStorage.setItem('app_version', this.userVersion);
            
        } catch (err) {
            console.error('Failed to get user version from DB:', err);
            this.userVersion = localStorage.getItem('app_version') || '1.0.0';
        }
        return this.userVersion;
    }
    
    async getServerVersion() {
        try {
            const res = await fetch('/ledor/version.json?t=' + Date.now(), { 
                cache: 'no-cache',
                headers: { 'Cache-Control': 'no-cache' }
            });
            
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            
            const data = await res.json();
            this.currentVersion = data.version || '1.0.0';
            localStorage.setItem('server_version', this.currentVersion);
            
            return this.currentVersion;
        } catch (err) {
            console.error('Failed to get server version:', err);
            this.currentVersion = localStorage.getItem('server_version') || '1.0.0';
            return this.currentVersion;
        }
    }
    
    async getVersionData() {
        try {
            const res = await fetch('/ledor/version.json?t=' + Date.now());
            return await res.json();
        } catch {
            return { 
                update_message: 'نسخه جدید با ویژگی‌های شگفت‌انگیز و بهبودهای عملکرد منتشر شده است.', 
                update_type: 'major',
                version: this.currentVersion
            };
        }
    }
    
    async checkVersion() {
        if (this.isChecking || this.updateInProgress) return;
        this.isChecking = true;
        
        try {
            // اطمینان از به‌روز بودن مقادیر
            if (!this.userVersion) await this.getUserVersionFromDB();
            if (!this.currentVersion) await this.getServerVersion();
            
            // مقایسه نسخه‌ها
            const needUpdate = this.compareVersions(this.currentVersion, this.userVersion) > 0;
            
            
            if (needUpdate) {
                const versionData = await this.getVersionData();

                // بررسی اینکه آیا لوگوی جدیدی هم تعریف شده است
                const lastSeenLogoVersion = localStorage.getItem('app_logo_version') || '0';
                const hasNewLogo = !!versionData.logo_url && String(versionData.logo_version || '0') !== lastSeenLogoVersion;

                this.showUpdateModal({
                    version: this.currentVersion,
                    message: versionData.update_message || 'نسخه جدید با امکانات فوق‌العاده منتشر شده است!',
                    update_type: versionData.update_type || 'major',
                    hasNewLogo: hasNewLogo,
                    logoVersion: versionData.logo_version || 0
                });
            }
        } catch (err) {
            console.error('Version check failed:', err);
        } finally {
            this.isChecking = false;
        }
    }
    
    compareVersions(v1, v2) {
        const p1 = v1.split('.').map(Number);
        const p2 = v2.split('.').map(Number);
        
        for (let i = 0; i < 3; i++) {
            const n1 = p1[i] || 0;
            const n2 = p2[i] || 0;
            if (n1 > n2) return 1;
            if (n1 < n2) return -1;
        }
        return 0;
    }
    
    showUpdateModal(versionData) {
        // اگر مودال قبلاً نمایش داده شده، از نمایش مجدد جلوگیری کن
        if (this.updateModal && document.body.contains(this.updateModal)) return;

        this.pendingVersionData = versionData;
        
        const messages = [
            "✨ نسخه جدید با ویژگی‌های شگفت‌انگیز!",
            "🚀 سریع‌تر از همیشه! آپدیت در دسترس",
            "🔧 رفع باگ‌ها و بهبود عملکرد",
            "🎉 ویژگی‌های جدید و جذاب اضافه شد",
            "💪 قدرت بیشتر، سرعت بیشتر! همین حالا آپدیت کن",
            "⚡️ سرعت بارگذاری ۵۰٪ افزایش یافته",
            "🔒 امنیت و حریم خصوصی بهبود یافته",
            "💎 نسخه جدید با طراحی زیباتر",
            "🌈 ویژگی‌های شگفت‌انگیز در انتظار شماست",
            "⭐️ بهترین نسخه تا به امروز"
        ];
        
        const randomMsg = messages[Math.floor(Math.random() * messages.length)];
        
        let icon = '🚀';
        let color = '#FF3B30';
        let title = 'آپدیت اجباری';
        
        switch(versionData.update_type) {
            case 'major':
                icon = '🎉';
                color = '#FF3B30';
                title = 'آپدیت بزرگ';
                break;
            case 'minor':
                icon = '✨';
                color = '#FFC107';
                title = 'آپدیت جزئی';
                break;
            case 'patch':
                icon = '🔧';
                color = '#4CD964';
                title = 'به‌روزرسانی رفع باگ';
                break;
            case 'security':
                icon = '🔒';
                color = '#007AFF';
                title = 'آپدیت امنیتی';
                break;
            default:
                icon = '🚀';
                color = '#FF3B30';
                title = 'آپدیت اجباری';
        }
        
        this.updateModal = document.createElement('div');
        this.updateModal.className = 'update-modal-overlay';
        this.updateModal.innerHTML = `
            <div class="update-modal" style="border-top: 5px solid ${color}">
                <div class="update-modal-header">
                    <div class="update-icon" style="background: ${color}20; color: ${color}">
                        ${icon}
                    </div>
                    <div class="update-title">
                        <h2>⚠️ ${title} نسخه ${versionData.version}</h2>
                        <p class="update-subtitle">تاریخ انتشار: ${new Date().toLocaleDateString('fa-IR')}</p>
                    </div>
                </div>
                
                <div class="update-modal-body">
                    <div class="update-message" style="border-color: ${color}">
                        ✨ ${randomMsg}
                    </div>
                    <div class="update-details">
                        <strong>📋 تغییرات جدید:</strong>
                        <br>
                        ${this.formatMessage(versionData.message)}
                    </div>
                    ${versionData.hasNewLogo ? `
                    <div class="update-message" style="border-color: #FFD700;">
                        🖼️ لوگوی جدید برنامه نیز همراه این آپدیت اعمال می‌شود
                    </div>` : ''}
                    <div class="update-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>⚠️ این آپدیت اجباری است</strong>
                        <p>برای ادامه استفاده از برنامه، باید آپدیت را انجام دهید</p>
                        <p style="margin-top: 10px; font-size: 0.85rem; color: rgba(255,255,255,0.5);">
                            🔒 تا زمانی که آپدیت نکنید، نمی‌توانید این پنجره را ببندید
                        </p>
                    </div>
                </div>
                
                <div class="update-modal-footer">
                    <button class="update-btn-now" onclick="window.versionManager.applyUpdate()">
                        <i class="fas fa-download"></i> آپدیت هم‌اکنون
                    </button>
                </div>
            </div>
        `;
        
        document.body.appendChild(this.updateModal);
        
        // قفل کردن صفحه
        document.body.style.overflow = 'hidden';
        document.body.style.position = 'fixed';
        document.body.style.width = '100%';
        document.body.style.height = '100%';
        
        // نمایش مودال با انیمیشن
        setTimeout(() => this.updateModal.classList.add('show'), 50);
        
        // جلوگیری از بستن مودال با کلیک روی overlay
        this.updateModal.addEventListener('click', (e) => {
            if (e.target === this.updateModal) {
                e.preventDefault();
                this.showForceMessage('⚠️ برای ادامه، روی دکمه "آپدیت هم‌اکنون" کلیک کنید!');
            }
        });
        
        // جلوگیری از بستن با کلید ESC
        const escHandler = (e) => {
            if (e.key === 'Escape') {
                e.preventDefault();
                this.showForceMessage('⚠️ نمی‌توانید این پنجره را ببندید! لطفاً آپدیت را انجام دهید.');
            }
        };
        document.addEventListener('keydown', escHandler);
        this.updateModal._escHandler = escHandler;
        
        // جلوگیری از دکمه بازگشت
        window.history.pushState(null, null, window.location.href);
        const popHandler = () => {
            window.history.pushState(null, null, window.location.href);
            this.showForceMessage('⚠️ برای ادامه، روی دکمه "آپدیت هم‌اکنون" کلیک کنید!');
        };
        window.addEventListener('popstate', popHandler);
        this.updateModal._popHandler = popHandler;
        
        // جلوگیری از کلیک روی هر چیزی خارج از مودال
        const disableClicks = (e) => {
            if (!e.target.closest('.update-modal')) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            }
        };
        document.addEventListener('click', disableClicks, true);
        this.updateModal._disableClicks = disableClicks;
        
        // پخش صدای هشدار
        this.playAlertSound();
        
    }
    
    showForceMessage(text) {
        const message = document.createElement('div');
        message.className = 'force-message';
        message.innerHTML = `
            <i class="fas fa-exclamation-circle"></i>
            ${text || '⚠️ این آپدیت اجباری است! لطفاً روی دکمه "آپدیت هم‌اکنون" کلیک کنید'}
        `;
        document.body.appendChild(message);
        
        setTimeout(() => message.classList.add('show'), 10);
        setTimeout(() => {
            message.classList.remove('show');
            setTimeout(() => message.remove(), 300);
        }, 3500);
    }
    
    // پاکسازی کوکی‌های قابل‌دسترسی توسط جاوااسکریپت، به‌جز کوکی‌های ضروری ورود.
    //
    // ⚠️ توجه مهم (اصلاح‌شده): فرض قبلی این بود که کوکی سشن اصلی PHP
    // همیشه HttpOnly است و جاوااسکریپت اصلاً نمی‌تواند آن را ببیند/حذف
    // کند، پس نیازی به اضافه کردنش به لیست keepCookies نبود. اما در
    // عمل، هیچ‌کدام از صفحات لایو (dashboard.php, arad.php,
    // transactions.php, profile.php, config/database.php) از
    // config/session_init.php استفاده نمی‌کنند و فقط session_start()
    // ساده صدا می‌زنند؛ یعنی نام کوکی سشن واقعی همان نام پیش‌فرض PHP
    // (PHPSESSID) است، نه AVAPAY_SESSION. این کوکی همیشه HttpOnly
    // نیست (به تنظیمات php.ini سرور بستگی دارد)، پس این تابع داشت
    // همان کوکی سشن واقعی کاربر را هم پاک می‌کرد و باعث می‌شد درخواست
    // بعدی به version_sync.php با خطای "not_authenticated" مواجه شود.
    // برای اطمینان، نام‌های شناخته‌شده‌ی کوکی سشن هم به لیست محافظت‌شده
    // اضافه شدند.
    clearAllCookies() {
        const keepCookies = ['auth_token', 'user_id', 'PHPSESSID', 'AVAPAY_SESSION'];
        try {
            const cookies = document.cookie.split(';');
            cookies.forEach(cookie => {
                const eqPos = cookie.indexOf('=');
                const name = (eqPos > -1 ? cookie.substr(0, eqPos) : cookie).trim();
                if (!name || keepCookies.includes(name)) return;

                // حذف کوکی برای مسیرها و دامنه‌های مختلف ممکن
                const expire = 'expires=Thu, 01 Jan 1970 00:00:00 GMT';
                document.cookie = `${name}=; ${expire}; path=/;`;
                document.cookie = `${name}=; ${expire}; path=/ledor/;`;
                document.cookie = `${name}=; ${expire}; path=/; domain=${window.location.hostname};`;
            });
        } catch (err) {
            console.warn('Cookie clear warning:', err);
        }
    }

    // به‌روزرسانی فوری آیکون/لوگوی برنامه در همین صفحه (بدون نیاز به رفرش)
    // بعد از آپدیت موفق صدا زده می‌شود تا favicon، apple-touch-icon و
    // همه‌ی لوگوهای داخل صفحه (img[data-app-logo]) بلافاصله عوض شوند.
    // آیکون نصب‌شده‌ی PWA هم توسط مرورگر از manifest.php (که همیشه
    // آخرین لوگو را برمی‌گرداند) به‌مرور به‌روزرسانی می‌شود.
    async refreshAppLogo() {
        try {
            const data = await this.getVersionData();
            if (!data || !data.logo_url) return;

            const logoVersion = data.logo_version || Date.now();
            const newUrl = '/ledor/' + String(data.logo_url).replace(/^\/+/, '') + '?v=' + logoVersion;

            // ۱) favicon و apple-touch-icon های صفحه
            document.querySelectorAll('link[rel="icon"], link[rel="shortcut icon"], link[rel="apple-touch-icon"]').forEach(link => {
                link.href = newUrl;
            });

            // اگر صفحه اصلاً favicon نداشت، یکی اضافه کن
            if (!document.querySelector('link[rel="icon"]')) {
                const link = document.createElement('link');
                link.rel = 'icon';
                link.href = newUrl;
                document.head.appendChild(link);
            }

            // ۲) لوگوهای نمایش‌داده‌شده داخل صفحه
            document.querySelectorAll('img[data-app-logo]').forEach(img => {
                img.src = newUrl;
            });

            // ۳) ذخیره نسخه لوگو تا مودال دوباره «لوگوی جدید» را اعلام نکند
            localStorage.setItem('app_logo_version', String(logoVersion));

        } catch (err) {
            console.warn('Logo refresh warning:', err);
        }
    }

    async applyUpdate() {
        if (this.updateInProgress) return;
        this.updateInProgress = true;
        
        
        const btn = this.updateModal?.querySelector('.update-btn-now');
        if (btn) {
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> در حال آپدیت...';
            btn.disabled = true;
        }
        
        try {
            // نمایش پیام در حال آپدیت
            const updatingDiv = document.createElement('div');
            updatingDiv.className = 'updating-message';
            updatingDiv.innerHTML = `
                <div class="updating-spinner"></div>
                <div>
                    <strong>🔄 در حال آپدیت برنامه...</strong>
                    <br>
                    <small>لطفاً صبر کنید، این عملیات چند ثانیه طول می‌کشد</small>
                </div>
            `;
            document.body.appendChild(updatingDiv);
            setTimeout(() => updatingDiv.classList.add('show'), 50);
            
            // 1. پاکسازی کش‌های مرورگر
            if ('caches' in window) {
                try {
                    const keys = await caches.keys();
                    await Promise.all(keys.map(key => caches.delete(key)));
                } catch(e) {
                    console.warn('Cache clear warning:', e);
                }
            }
            
            // 2. لغو ثبت Service Workers
            if ('serviceWorker' in navigator) {
                try {
                    const registrations = await navigator.serviceWorker.getRegistrations();
                    await Promise.all(registrations.map(reg => reg.unregister()));
                } catch(e) {
                    console.warn('Service Worker unregister warning:', e);
                }
            }
            
            // 3. دریافت مجدد نسخه جدید
            const newVersion = await this.getServerVersion();
            
            // 4. به‌روزرسانی نسخه در دیتابیس
            // نکته: این کار عمداً قبل از clearAllCookies() انجام می‌شود تا حتی
            // اگر در آینده نام کوکی سشن عوض شود یا منطق پاکسازی کوکی تغییر کند،
            // این درخواست احراز هویت‌شده هیچ‌وقت با خطای "not_authenticated"
            // مواجه نشود.
            const updateRes = await fetch('/ledor/api/version_sync.php?action=update_version', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ version: newVersion })
            });

            const rawResponseText = await updateRes.text();
            let updateData;
            try {
                updateData = JSON.parse(rawResponseText);
            } catch (parseErr) {
                // اگر سرور به‌جای JSON چیز دیگری (مثلاً خطای PHP) برگردانده باشد،
                // این پیام دقیقاً همان متن خام را در کنسول نشان می‌دهد تا علت
                // واقعی مشخص شود (به‌جای خطای مبهم "Unexpected token")
                console.error('❌ Server did not return valid JSON:', rawResponseText.substring(0, 300));
                throw new Error('پاسخ نامعتبر از سرور دریافت شد. جزئیات در کنسول مرورگر موجود است.');
            }

            if (!updateRes.ok || !updateData.success) {
                throw new Error(updateData.error || updateData.detail || `HTTP ${updateRes.status}`);
            }


            // 4.5 پاکسازی کوکی‌های قدیمی (به‌جز کوکی‌های لاگین) — حالا که آپدیت
            //     نسخه با موفقیت در دیتابیس ذخیره شد، می‌توانیم با خیال راحت
            //     کوکی‌های غیرضروری را هم پاک کنیم بدون اینکه ریسکی برای
            //     درخواست بالا داشته باشد
            this.clearAllCookies();
            
            // 5. ذخیره در localStorage
            localStorage.setItem('app_version', newVersion);
            if (this.pendingVersionData && this.pendingVersionData.logoVersion) {
                localStorage.setItem('app_logo_version', String(this.pendingVersionData.logoVersion));
            }
            localStorage.removeItem('pending_update');
            sessionStorage.setItem('updated', 'true');

            // 5.5 تعویض فوری آیکون/لوگو در همین صفحه (favicon + لوگوهای داخل صفحه)
            //     تا کاربر قبل از ریلود هم آیکون جدید را ببیند. بعد از ریلود،
            //     PHP خودش لوگوی جدید را از version.json می‌خواند و مرورگر هم
            //     آیکون نصب‌شده‌ی PWA را از manifest.php به‌روزرسانی می‌کند.
            await this.refreshAppLogo();
            
            // 6. نمایش پیام موفقیت
            this.showSuccessToast('✅ آپدیت با موفقیت انجام شد! صفحه مجدداً بارگذاری می‌شود.');
            
            // 7. آزادسازی صفحه
            document.body.style.overflow = '';
            document.body.style.position = '';
            document.body.style.width = '';
            document.body.style.height = '';
            
            if (this.updateModal) {
                if (this.updateModal._disableClicks) {
                    document.removeEventListener('click', this.updateModal._disableClicks, true);
                }
                if (this.updateModal._escHandler) {
                    document.removeEventListener('keydown', this.updateModal._escHandler);
                }
                if (this.updateModal._popHandler) {
                    window.removeEventListener('popstate', this.updateModal._popHandler);
                }
                
                this.updateModal.classList.remove('show');
                setTimeout(() => {
                    if (this.updateModal && this.updateModal.parentNode) {
                        this.updateModal.remove();
                    }
                    this.updateModal = null;
                }, 300);
            }
            
            // 8. حذف پیام در حال آپدیت
            if (updatingDiv && updatingDiv.parentNode) {
                updatingDiv.classList.remove('show');
                setTimeout(() => updatingDiv.remove(), 300);
            }
            
            // 9. ریلود هارد (معادل Ctrl+Shift+R)
            setTimeout(() => {
                window.location.reload(true);
            }, 1500);
            
        } catch (err) {
            console.error('❌ Update failed:', err);
            this.updateInProgress = false;
            
            if (btn) {
                btn.innerHTML = '<i class="fas fa-sync-alt"></i> تلاش مجدد';
                btn.disabled = false;
            }
            
            // نمایش پیام خطا
            const errorDiv = document.createElement('div');
            errorDiv.className = 'update-error-message';
            errorDiv.innerHTML = `
                <i class="fas fa-exclamation-circle"></i>
                <div>
                    <strong>❌ آپدیت ناموفق!</strong>
                    <p style="font-size: 0.8rem; color: rgba(255,255,255,0.6); margin-top: 4px;">${err.message.substring(0, 150)}</p>
                    <button onclick="this.parentElement.parentElement.remove(); window.versionManager.applyUpdate()">
                        <i class="fas fa-redo"></i> تلاش مجدد
                    </button>
                </div>
            `;
            document.body.appendChild(errorDiv);
            setTimeout(() => errorDiv.classList.add('show'), 50);
            
            // حذف خودکار پیام خطا بعد از 10 ثانیه
            setTimeout(() => {
                errorDiv.classList.remove('show');
                setTimeout(() => errorDiv.remove(), 300);
            }, 10000);
        }
    }
    
    showSuccessToast(message) {
        const toast = document.createElement('div');
        toast.className = 'success-toast';
        toast.innerHTML = `
            <i class="fas fa-check-circle"></i>
            <span>${message}</span>
        `;
        document.body.appendChild(toast);
        
        setTimeout(() => toast.classList.add('show'), 50);
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 500);
        }, 2500);
    }
    
    formatMessage(message) {
        if (!message) return 'نسخه جدید با امکانات فوق‌العاده منتشر شده است!';
        return message.replace(/\n/g, '<br>');
    }
    
    playAlertSound() {
        try {
            const audioContext = new (window.AudioContext || window.webkitAudioContext)();
            
            // پخش دو بوق متوالی
            for (let i = 0; i < 2; i++) {
                const oscillator = audioContext.createOscillator();
                const gainNode = audioContext.createGain();
                
                oscillator.connect(gainNode);
                gainNode.connect(audioContext.destination);
                
                oscillator.frequency.value = i === 0 ? 880 : 1100;
                oscillator.type = 'sine';
                
                gainNode.gain.setValueAtTime(0.3, audioContext.currentTime + i * 0.4);
                gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + i * 0.4 + 0.3);
                
                oscillator.start(audioContext.currentTime + i * 0.4);
                oscillator.stop(audioContext.currentTime + i * 0.4 + 0.3);
            }
        } catch(e) {
        }
    }
    
    addStyles() {
        if (document.getElementById('version-manager-styles')) return;
        
        const style = document.createElement('style');
        style.id = 'version-manager-styles';
        style.textContent = `
            /* === OVERLAY === */
            .update-modal-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.95);
                backdrop-filter: blur(20px);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 999999;
                opacity: 0;
                transition: opacity 0.4s ease;
                padding: 20px;
            }
            
            .update-modal-overlay.show {
                opacity: 1;
            }
            
            /* === MODAL === */
            .update-modal {
                background: linear-gradient(145deg, #1A0B2E 0%, #2A0D3F 100%);
                border-radius: 28px;
                max-width: 520px;
                width: 100%;
                position: relative;
                overflow: hidden;
                transform: scale(0.92) translateY(20px);
                transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
                box-shadow: 0 30px 80px rgba(0, 0, 0, 0.6);
                border: 1px solid rgba(255, 255, 255, 0.08);
            }
            
            .update-modal-overlay.show .update-modal {
                transform: scale(1) translateY(0);
            }
            
            /* === HEADER === */
            .update-modal-header {
                display: flex;
                align-items: center;
                gap: 16px;
                padding: 28px 28px 16px;
            }
            
            .update-icon {
                width: 64px;
                height: 64px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 2rem;
                flex-shrink: 0;
                animation: iconPulse 1.5s ease-in-out infinite;
            }
            
            @keyframes iconPulse {
                0%, 100% { transform: scale(1); }
                50% { transform: scale(1.08); }
            }
            
            .update-title h2 {
                margin: 0 0 4px 0;
                font-size: 1.25rem;
                color: #fff;
                font-weight: 700;
            }
            
            .update-subtitle {
                margin: 0;
                color: rgba(255, 255, 255, 0.4);
                font-size: 0.8rem;
            }
            
            /* === BODY === */
            .update-modal-body {
                padding: 0 28px 20px;
            }
            
            .update-message {
                background: rgba(255, 255, 255, 0.05);
                border-radius: 16px;
                padding: 16px 20px;
                margin-bottom: 14px;
                font-size: 1rem;
                font-weight: 600;
                color: #FFC107;
                text-align: center;
                border: 1px solid rgba(255, 193, 7, 0.15);
            }
            
            .update-details {
                background: rgba(255, 255, 255, 0.04);
                border-radius: 14px;
                padding: 16px 18px;
                margin-bottom: 14px;
                color: rgba(255, 255, 255, 0.8);
                line-height: 1.7;
                font-size: 0.88rem;
                border: 1px solid rgba(255, 255, 255, 0.06);
            }
            
            .update-details strong {
                color: #a78bfa;
            }
            
            .update-warning {
                background: rgba(255, 59, 48, 0.12);
                border-radius: 14px;
                padding: 16px 18px;
                text-align: center;
                border: 1px solid rgba(255, 59, 48, 0.25);
            }
            
            .update-warning i {
                font-size: 1.8rem;
                color: #FF3B30;
                margin-bottom: 6px;
                display: inline-block;
            }
            
            .update-warning strong {
                display: block;
                color: #FF3B30;
                margin-bottom: 4px;
                font-size: 0.95rem;
            }
            
            .update-warning p {
                margin: 0;
                color: rgba(255, 255, 255, 0.5);
                font-size: 0.82rem;
            }
            
            /* === FOOTER === */
            .update-modal-footer {
                padding: 0 28px 28px;
            }
            
            .update-btn-now {
                width: 100%;
                padding: 16px;
                background: linear-gradient(135deg, #FF3B30, #FF4D8D);
                border: none;
                border-radius: 14px;
                color: white;
                font-size: 1rem;
                font-weight: 700;
                cursor: pointer;
                transition: all 0.3s ease;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 10px;
                letter-spacing: 0.5px;
                box-shadow: 0 4px 20px rgba(255, 59, 48, 0.3);
            }
            
            .update-btn-now:hover {
                transform: translateY(-2px);
                box-shadow: 0 8px 30px rgba(255, 59, 48, 0.5);
            }
            
            .update-btn-now:active {
                transform: scale(0.97);
            }
            
            .update-btn-now:disabled {
                opacity: 0.6;
                cursor: not-allowed;
                transform: none !important;
            }
            
            /* === FORCE MESSAGE === */
            .force-message {
                position: fixed;
                top: 30px;
                left: 50%;
                transform: translateX(-50%) translateY(-100px);
                background: linear-gradient(135deg, #FF3B30, #FF4D8D);
                border-radius: 12px;
                padding: 14px 24px;
                color: white;
                display: flex;
                align-items: center;
                gap: 12px;
                z-index: 1000000;
                opacity: 0;
                transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
                box-shadow: 0 8px 30px rgba(255, 59, 48, 0.4);
                font-weight: 600;
                font-size: 0.9rem;
                max-width: 90%;
                text-align: center;
            }
            
            .force-message i {
                font-size: 1.3rem;
            }
            
            .force-message.show {
                transform: translateX(-50%) translateY(0);
                opacity: 1;
            }
            
            /* === UPDATING MESSAGE === */
            .updating-message {
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%) scale(0.9);
                background: linear-gradient(145deg, #1A0B2E, #2A0D3F);
                border-radius: 20px;
                padding: 32px 40px;
                display: flex;
                align-items: center;
                gap: 20px;
                z-index: 1000001;
                opacity: 0;
                transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
                box-shadow: 0 30px 80px rgba(0, 0, 0, 0.6);
                border: 1px solid rgba(108, 64, 197, 0.3);
            }
            
            .updating-message.show {
                opacity: 1;
                transform: translate(-50%, -50%) scale(1);
            }
            
            .updating-spinner {
                width: 44px;
                height: 44px;
                border: 4px solid rgba(108, 64, 197, 0.2);
                border-top: 4px solid #6C40C5;
                border-radius: 50%;
                animation: spin 0.8s linear infinite;
            }
            
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
            
            .updating-message strong {
                color: #fff;
                font-size: 1.05rem;
            }
            
            .updating-message small {
                color: rgba(255, 255, 255, 0.4);
                font-size: 0.8rem;
            }
            
            /* === SUCCESS TOAST === */
            .success-toast {
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%) scale(0.9);
                background: linear-gradient(135deg, #22d3a0, #059669);
                border-radius: 16px;
                padding: 20px 32px;
                display: flex;
                align-items: center;
                gap: 14px;
                z-index: 1000002;
                opacity: 0;
                transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4);
                color: white;
                font-weight: 600;
                font-size: 1rem;
                text-align: center;
                max-width: 90%;
            }
            
            .success-toast i {
                font-size: 1.8rem;
            }
            
            .success-toast.show {
                opacity: 1;
                transform: translate(-50%, -50%) scale(1);
            }
            
            /* === ERROR MESSAGE === */
            .update-error-message {
                position: fixed;
                bottom: 30px;
                right: 30px;
                background: linear-gradient(145deg, #2A0D3F, #1A0B2E);
                border-radius: 14px;
                padding: 18px 20px;
                max-width: 380px;
                display: flex;
                gap: 14px;
                z-index: 1000000;
                transform: translateX(120%);
                transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
                border-left: 4px solid #FF3B30;
                box-shadow: 0 10px 40px rgba(0, 0, 0, 0.4);
                border: 1px solid rgba(255, 59, 48, 0.2);
            }
            
            .update-error-message.show {
                transform: translateX(0);
            }
            
            .update-error-message > i {
                color: #FF3B30;
                font-size: 1.5rem;
                flex-shrink: 0;
            }
            
            .update-error-message strong {
                color: #FF3B30;
                display: block;
                font-size: 0.9rem;
            }
            
            .update-error-message button {
                background: linear-gradient(135deg, #FF3B30, #FF4D8D);
                border: none;
                padding: 6px 16px;
                border-radius: 8px;
                color: white;
                cursor: pointer;
                margin-top: 6px;
                font-size: 0.8rem;
                font-weight: 600;
                transition: all 0.2s;
            }
            
            .update-error-message button:hover {
                transform: translateY(-1px);
                box-shadow: 0 4px 15px rgba(255, 59, 48, 0.3);
            }
            
            /* === RESPONSIVE === */
            @media (max-width: 600px) {
                .update-modal {
                    max-width: 100%;
                    border-radius: 20px;
                }
                
                .update-modal-header {
                    padding: 22px 20px 12px;
                    gap: 12px;
                }
                
                .update-icon {
                    width: 52px;
                    height: 52px;
                    font-size: 1.5rem;
                }
                
                .update-title h2 {
                    font-size: 1.05rem;
                }
                
                .update-modal-body {
                    padding: 0 20px 16px;
                }
                
                .update-modal-footer {
                    padding: 0 20px 22px;
                }
                
                .update-message {
                    font-size: 0.9rem;
                    padding: 12px 16px;
                }
                
                .update-details {
                    font-size: 0.8rem;
                    padding: 14px 16px;
                }
                
                .update-btn-now {
                    font-size: 0.9rem;
                    padding: 14px;
                }
                
                .force-message {
                    font-size: 0.8rem;
                    padding: 12px 18px;
                    top: 20px;
                }
                
                .updating-message {
                    padding: 24px 28px;
                    max-width: 90%;
                }
                
                .updating-message strong {
                    font-size: 0.95rem;
                }
                
                .success-toast {
                    font-size: 0.85rem;
                    padding: 16px 24px;
                }
                
                .update-error-message {
                    bottom: 20px;
                    right: 20px;
                    left: 20px;
                    max-width: none;
                }
            }
        `;
        
        document.head.appendChild(style);
    }
}

// ============================================================
// ایجاد نمونه و راه‌اندازی خودکار
// ============================================================

let versionManager = null;

(function() {
    versionManager = new VersionManager();
    window.versionManager = versionManager;
})();

// بررسی مجدد پس از لود کامل صفحه
window.addEventListener('load', () => {
    if (versionManager) {
        setTimeout(() => versionManager.checkVersion(), 500);
    }
});

// بررسی مجدد پس از تغییر URL (برای SPA)
window.addEventListener('hashchange', () => {
    if (versionManager) {
        setTimeout(() => versionManager.checkVersion(), 300);
    }
});

// جلوگیری از بستن صفحه در حین نمایش مودال آپدیت
window.addEventListener('beforeunload', (e) => {
    if (versionManager && versionManager.updateModal && document.body.contains(versionManager.updateModal)) {
        e.preventDefault();
        e.returnValue = '⚠️ شما باید آپدیت را انجام دهید تا بتوانید به استفاده از برنامه ادامه دهید.';
        return '⚠️ شما باید آپدیت را انجام دهید تا بتوانید به استفاده از برنامه ادامه دهید.';
    }
});

// پشتیبانی از رفرش عمیق
document.addEventListener('keydown', (e) => {
    // Ctrl+Shift+R یا Ctrl+F5 برای رفرش عمیق
    if ((e.ctrlKey && e.shiftKey && (e.key === 'r' || e.key === 'R')) || 
        (e.ctrlKey && e.key === 'F5')) {
        if (versionManager) {
            setTimeout(() => versionManager.checkVersion(), 500);
        }
    }
});
