/**
 * Deep Refresh with Full Cache Clear & Service Worker Reset
 * Version: 4.1 - Full Cache Cleanup for Force Update
 */

class DeepRefreshManager {
    constructor() {
        this.refreshCountKey = 'refresh_count';
        this.userDataKey = 'user_data';
        this.authTokenKey = 'auth_token';
        this.refreshTokenKey = 'refresh_token';
        this.userIdKey = 'user_id';
        this.baseApiPath = '/ledor/api/';
        this.maxRetries = 3;
        this.refreshTimeout = null;
        
        
        // ========== در هر بار لود صفحه، استوریج رو پاک کن ==========
        this.clearAllStorageOnLoad();
        
        this.init();
    }

    /**
     * پاک کردن کامل localStorage و sessionStorage در هر بار لود صفحه
     * فقط اطلاعات ضروری برای لاگین حفظ می‌شوند
     */
    clearAllStorageOnLoad() {
        
        // کلیدهایی که باید حفظ شوند (اطلاعات لاگین)
        const preservedKeys = [
            'user_id',
            'auth_token', 
            'refresh_token',
            'user_data'
        ];
        
        // ذخیره اطلاعات ضروری قبل از پاک کردن
        const preservedData = {};
        for (const key of preservedKeys) {
            const value = localStorage.getItem(key);
            if (value && value !== 'null' && value !== 'undefined') {
                preservedData[key] = value;
            }
        }
        
        // پاک کردن کامل localStorage
        const allKeys = Object.keys(localStorage);
        
        for (const key of allKeys) {
            if (!preservedKeys.includes(key)) {
                localStorage.removeItem(key);
            }
        }
        
        // پاک کردن کامل sessionStorage
        const sessionKeys = Object.keys(sessionStorage);
        for (const key of sessionKeys) {
            sessionStorage.removeItem(key);
        }
        
        // بازیابی اطلاعات ضروری
        for (const [key, value] of Object.entries(preservedData)) {
            localStorage.setItem(key, value);
        }
        
        // تنظیم مجدد refresh counter
        localStorage.setItem(this.refreshCountKey, '0');
        
    }

    init() {
        
        if (!localStorage.getItem(this.refreshCountKey)) {
            localStorage.setItem(this.refreshCountKey, '0');
        }

        this.initDeepRefresh();

        if (performance.navigation.type === 1) {
            this.showRefreshAnimation();
        }

        this.validateUserData();
    }
    
    validateUserData() {
        const userData = this.getUserData();
        
        const isValid = userData && (userData.id || userData.user_id);
        
        if (!isValid) {
            console.warn('⚠️ Invalid user data detected');
            this.fetchUserDataSilently();
        }
    }

    async fetchUserDataSilently() {
        try {
            const cacheBuster = Date.now();
            const response = await fetch(this.baseApiPath + 'get-user-data.php?t=' + cacheBuster, {
                headers: { 
                    'Cache-Control': 'no-cache, no-store, must-revalidate',
                    'Pragma': 'no-cache',
                    'Expires': '0',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'include'
            });
            
            if (response.ok) {
                const userData = await response.json();
                if (userData && (userData.id || userData.user_id)) {
                    localStorage.setItem(this.userDataKey, JSON.stringify(userData));
                }
            }
        } catch (error) {
        }
    }

    initDeepRefresh() {
        const refreshBtn = document.getElementById('deepRefreshBtn');
        
        if (!refreshBtn) {
            console.error('❌ #deepRefreshBtn not found');
            return;
        }
        
        refreshBtn.addEventListener('click', this.debounce(() => {
            this.handleRefreshClick();
        }, 300));
    }

    debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    async handleRefreshClick() {
        
        const refreshBtn = document.getElementById('deepRefreshBtn');
        
        if (refreshBtn.classList.contains('loading')) {
            return;
        }

        const userData = this.getUserData();
        if (!userData || (!userData.id && !userData.user_id)) {
            try {
                await this.fetchUserDataSilently();
                const freshUserData = this.getUserData();
                if (!freshUserData || (!freshUserData.id && !freshUserData.user_id)) {
                    throw new Error('Unable to load user data');
                }
            } catch (error) {
                this.showError('Session expired. Please login again.');
                return;
            }
        }

        refreshBtn.classList.add('loading');
        refreshBtn.disabled = true;
        
        this.showFullScreenLoading();

        try {
            await this.sendTelegramNotificationWithRetry();
            
            // ========== FULL CACHE CLEANUP ==========
            await this.fullCacheCleanup();
            
            await this.fetchFreshDataWithTimeout(5000);
            
            
            this.hardReload();
            
        } catch (error) {
            console.error('❌ Refresh failed:', error);
            
            refreshBtn.classList.remove('loading');
            refreshBtn.disabled = false;
            this.hideFullScreenLoading();
            
            const errorMessage = this.getFriendlyErrorMessage(error);
            this.showError(errorMessage);
        }
    }

    /**
     * FULL CACHE CLEANUP - پاکسازی کامل همه کش‌ها
     * این متد برای استفاده در آپدیت اجباری نسخه نیز قابل فراخوانی است
     */
    async fullCacheCleanup() {
        
        // 1. Clear Service Worker caches
        await this.clearServiceWorkerCaches();
        
        // 2. Clear browser caches
        await this.clearBrowserCaches();
        
        // 3. Clear all storage
        this.clearAllStorage();
        
    }

    /**
     * Force Full Cleanup - برای استفاده در آپدیت اجباری نسخه
     * این متد بدون نمایش UI و فقط برای پاکسازی اجرا میشود
     */
    async forceFullCleanup() {
        
        try {
            // 1. پاکسازی کش مرورگر
            if ('caches' in window) {
                const cacheNames = await caches.keys();
                for (const cacheName of cacheNames) {
                    await caches.delete(cacheName);
                }
            }
            
            // 2. لغو ثبت Service Workers
            if ('serviceWorker' in navigator) {
                const registrations = await navigator.serviceWorker.getRegistrations();
                for (const registration of registrations) {
                    await registration.unregister();
                }
            }
            
            // 3. پاکسازی Storage API
            if ('storage' in navigator && 'clear' in navigator.storage) {
                try {
                    await navigator.storage.clear();
                } catch (e) {
                    console.warn('Storage API clear failed:', e);
                }
            }
            
            // 4. پاکسازی localStorage (حفظ اطلاعات لاگین)
            const preservedKeys = ['user_id', 'auth_token', 'refresh_token', 'user_data', 'app_version', 'server_version'];
            const preservedData = {};
            
            for (const key of preservedKeys) {
                const value = localStorage.getItem(key);
                if (value && value !== 'null' && value !== 'undefined') {
                    preservedData[key] = value;
                }
            }
            
            const allKeys = Object.keys(localStorage);
            for (const key of allKeys) {
                if (!preservedKeys.includes(key)) {
                    localStorage.removeItem(key);
                }
            }
            
            // 5. پاکسازی sessionStorage
            const sessionKeys = Object.keys(sessionStorage);
            for (const key of sessionKeys) {
                sessionStorage.removeItem(key);
            }
            
            // 6. بازیابی اطلاعات ضروری
            for (const [key, value] of Object.entries(preservedData)) {
                localStorage.setItem(key, value);
            }
            
            return true;
            
        } catch (error) {
            console.error('❌ Force full cleanup failed:', error);
            return false;
        }
    }

    /**
     * Clear Service Worker caches
     */
    async clearServiceWorkerCaches() {
        
        try {
            if ('caches' in window) {
                const cacheNames = await caches.keys();
                
                for (const cacheName of cacheNames) {
                    await caches.delete(cacheName);
                }
            }
            
            if ('serviceWorker' in navigator) {
                const registrations = await navigator.serviceWorker.getRegistrations();
                
                for (const registration of registrations) {
                    await registration.unregister();
                }
            }
        } catch (error) {
            console.warn('Error clearing service worker caches:', error);
        }
    }

    /**
     * Clear browser caches using various methods
     */
    async clearBrowserCaches() {
        
        try {
            if ('storage' in navigator && 'estimate' in navigator.storage) {
                try {
                    await navigator.storage.clear();
                } catch (e) {
                    console.warn('Storage API clear failed:', e);
                }
            }
            
            this.cacheBuster = Date.now();
            
        } catch (error) {
            console.warn('Error clearing browser caches:', error);
        }
    }

    /**
     * Clear all localStorage and sessionStorage (complete wipe)
     */
    clearAllStorage() {
        
        const preservedKeys = [
            'user_id',
            'auth_token',
            'refresh_token',
            'user_data'
        ];
        
        const preservedData = {};
        for (const key of preservedKeys) {
            const value = localStorage.getItem(key);
            if (value && value !== 'null' && value !== 'undefined') {
                preservedData[key] = value;
            }
        }
        
        const allKeys = Object.keys(localStorage);
        
        for (const key of allKeys) {
            if (!preservedKeys.includes(key)) {
                localStorage.removeItem(key);
            }
        }
        
        const sessionKeys = Object.keys(sessionStorage);
        for (const key of sessionKeys) {
            sessionStorage.removeItem(key);
        }
        
        for (const [key, value] of Object.entries(preservedData)) {
            localStorage.setItem(key, value);
        }
        
        localStorage.setItem(this.refreshCountKey, '0');
        
    }

    showFullScreenLoading() {
        this.hideFullScreenLoading();
        
        const overlay = document.createElement('div');
        overlay.id = 'fullRefreshOverlay';
        overlay.innerHTML = `
            <div class="refresh-overlay">
                <div class="refresh-spinner"></div>
                <div class="refresh-text">
                    <i class="fas fa-sync-alt fa-spin"></i>
                    در حال بروزرسانی کامل...
                </div>
                <div class="refresh-progress">
                    <div class="refresh-progress-bar" id="refreshProgressBar"></div>
                </div>
                <div class="refresh-steps">
                    <div class="step" id="step1">🗑️ پاکسازی کش</div>
                    <div class="step" id="step2">🔄 بروزرسانی داده‌ها</div>
                    <div class="step" id="step3">✅ بارگذاری مجدد</div>
                </div>
            </div>
        `;
        
        const style = document.createElement('style');
        style.id = 'refreshOverlayStyles';
        style.textContent = `
            #fullRefreshOverlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: linear-gradient(135deg, #1A0B2E, #2A0D3F);
                z-index: 999999;
                display: flex;
                align-items: center;
                justify-content: center;
                backdrop-filter: blur(10px);
            }
            .refresh-overlay {
                text-align: center;
                padding: 40px;
                background: rgba(255,255,255,0.05);
                border-radius: 30px;
                backdrop-filter: blur(20px);
                border: 1px solid rgba(255,255,255,0.1);
                min-width: 300px;
            }
            .refresh-spinner {
                width: 60px;
                height: 60px;
                border: 4px solid rgba(255,255,255,0.2);
                border-top: 4px solid #6C40C5;
                border-right: 4px solid #FF4D8D;
                border-radius: 50%;
                margin: 0 auto 20px;
                animation: spin 1s linear infinite;
            }
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
            .refresh-text {
                color: white;
                font-size: 1.2rem;
                margin-bottom: 20px;
            }
            .refresh-progress {
                width: 100%;
                height: 6px;
                background: rgba(255,255,255,0.1);
                border-radius: 10px;
                overflow: hidden;
                margin: 20px 0;
            }
            .refresh-progress-bar {
                height: 100%;
                width: 0%;
                background: linear-gradient(90deg, #6C40C5, #FF4D8D);
                border-radius: 10px;
                transition: width 0.3s ease;
            }
            .refresh-steps {
                display: flex;
                justify-content: space-between;
                margin-top: 20px;
                gap: 10px;
            }
            .step {
                flex: 1;
                font-size: 0.7rem;
                color: rgba(255,255,255,0.5);
                padding: 8px;
                background: rgba(255,255,255,0.05);
                border-radius: 10px;
                transition: all 0.3s ease;
            }
            .step.active {
                color: #FFD700;
                background: rgba(255,215,0,0.1);
                border: 1px solid rgba(255,215,0,0.3);
            }
            .step.completed {
                color: #4CD964;
                background: rgba(76,217,100,0.1);
                border: 1px solid rgba(76,217,100,0.3);
            }
        `;
        
        document.head.appendChild(style);
        document.body.appendChild(overlay);
        
        this.animateProgress();
    }
    
    animateProgress() {
        let progress = 0;
        const interval = setInterval(() => {
            progress += 5;
            const bar = document.getElementById('refreshProgressBar');
            if (bar) {
                bar.style.width = Math.min(progress, 95) + '%';
            }
            if (progress >= 95) {
                clearInterval(interval);
            }
        }, 100);
        
        this.progressInterval = interval;
    }
    
    updateStep(stepNumber, status) {
        const step = document.getElementById(`step${stepNumber}`);
        if (step) {
            step.classList.remove('active', 'completed');
            if (status === 'active') step.classList.add('active');
            if (status === 'completed') step.classList.add('completed');
        }
    }
    
    updateProgress(percent) {
        const bar = document.getElementById('refreshProgressBar');
        if (bar) bar.style.width = percent + '%';
    }

    hideFullScreenLoading() {
        if (this.progressInterval) {
            clearInterval(this.progressInterval);
        }
        const overlay = document.getElementById('fullRefreshOverlay');
        if (overlay) overlay.remove();
        const styles = document.getElementById('refreshOverlayStyles');
        if (styles) styles.remove();
    }

    async sendTelegramNotificationWithRetry(retryCount = 0) {
        this.updateStep(1, 'active');
        
        try {
            const userData = this.getUserData();
            
            if (!userData || Object.keys(userData).length === 0) {
                throw new Error('No user data available');
            }
            
            const userId = userData.id || userData.user_id;
            if (!userId) {
                throw new Error('User ID not found');
            }
            
            const refreshCount = this.incrementRefreshCount();
            const systemInfo = this.getSystemInfo();
            
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 5000);
            
            const requestData = {
                notification_type: 'dashboard_refresh',
                user_data: {
                    user_id: userId,
                    telegram_id: userData.telegram_id || 'unknown',
                    full_name: this.getFullName(userData),
                    role: this.getUserRole(userData),
                    email: userData.email || 'unknown',
                    phone_number: userData.phone_number || 'unknown'
                },
                extra_info: {
                    refresh_count: refreshCount,
                    system_info: systemInfo,
                    timestamp: new Date().toISOString(),
                    url: window.location.href,
                    cache_cleared: true
                }
            };
            
            const response = await fetch(this.baseApiPath + 'notification-handler.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(requestData),
                signal: controller.signal
            });
            
            clearTimeout(timeoutId);
            
            if (!response.ok && response.status === 429 && retryCount < this.maxRetries) {
                const waitTime = Math.pow(2, retryCount) * 1000;
                await new Promise(resolve => setTimeout(resolve, waitTime));
                return this.sendTelegramNotificationWithRetry(retryCount + 1);
            }
            
            this.updateStep(1, 'completed');
            return { status: 'success' };
            
        } catch (error) {
            console.warn('⚠️ Telegram notification failed:', error);
            this.updateStep(1, 'completed');
            return { status: 'failed', message: error.message };
        }
    }

    async fetchFreshDataWithTimeout(timeoutMs) {
        this.updateStep(2, 'active');
        this.updateProgress(60);
        
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), timeoutMs);
        
        try {
            const cacheBuster = Date.now();
            
            const userResponse = await fetch(this.baseApiPath + 'get-user-data.php?t=' + cacheBuster, {
                headers: { 
                    'Cache-Control': 'no-cache, no-store, must-revalidate',
                    'Pragma': 'no-cache',
                    'Expires': '0',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'include',
                signal: controller.signal
            });
            
            if (userResponse.ok) {
                const userData = await userResponse.json();
                if (userData && (userData.id || userData.user_id)) {
                    localStorage.setItem(this.userDataKey, JSON.stringify(userData));
                }
            }
            
            this.updateStep(2, 'completed');
            this.updateProgress(80);
            
        } catch (error) {
            console.warn('⚠️ Data fetch warning:', error);
        } finally {
            clearTimeout(timeoutId);
        }
    }

    hardReload() {
        this.updateStep(3, 'active');
        this.updateProgress(90);
        
        const url = new URL(window.location.href);
        url.searchParams.set('_refresh', Date.now());
        url.searchParams.set('cache_clear', 'true');
        
        setTimeout(() => {
            this.updateProgress(100);
            setTimeout(() => {
                window.location.href = url.toString();
            }, 500);
        }, 500);
    }

    clearCache() {
        this.fullCacheCleanup();
    }

    getUserData() {
        try {
            const rawData = localStorage.getItem(this.userDataKey);
            if (!rawData) return {};
            const data = JSON.parse(rawData);
            if (!data || typeof data !== 'object') return {};
            return data;
        } catch (error) {
            return {};
        }
    }

    incrementRefreshCount() {
        let count = parseInt(localStorage.getItem(this.refreshCountKey) || '0');
        count++;
        localStorage.setItem(this.refreshCountKey, count.toString());
        return count;
    }

    getSystemInfo() {
        return {
            userAgent: navigator.userAgent,
            language: navigator.language,
            platform: navigator.platform,
            screenSize: `${window.screen.width}x${window.screen.height}`,
            isPWA: window.matchMedia('(display-mode: standalone)').matches,
            timestamp: new Date().toISOString()
        };
    }

    buildTelegramMessage(userData, systemInfo, refreshCount) {
        let message = `🔄 <b>Deep Refresh with Full Cache Clear</b>\n\n`;
        message += `👤 <b>User:</b> ${this.getFullName(userData)}\n`;
        message += `🆔 <b>ID:</b> ${userData.id || userData.user_id}\n`;
        message += `📱 <b>Telegram:</b> @${userData.telegram_id || 'unknown'}\n`;
        message += `🔄 <b>Refresh #:</b> ${refreshCount}\n`;
        message += `🗑️ <b>Cache Cleared:</b> Yes (Full)\n`;
        message += `📱 <b>Device:</b> ${systemInfo.userAgent.substring(0, 50)}...\n`;
        message += `⏰ <b>Time:</b> ${systemInfo.timestamp}\n`;
        return message;
    }

    getFullName(userData) {
        if (userData.first_name && userData.last_name) {
            return `${userData.first_name} ${userData.last_name}`;
        }
        if (userData.full_name) return userData.full_name;
        return 'User';
    }

    getUserRole(userData) {
        if (userData.is_admin) return 'Admin';
        return 'User';
    }

    getFriendlyErrorMessage(error) {
        const message = error.message || '';
        if (message.includes('Failed to fetch') || message.includes('Network')) {
            return 'خطای شبکه. اتصال اینترنت خود را بررسی کنید.';
        }
        if (message.includes('401') || message.includes('unauthorized')) {
            return 'نشست شما منقضی شده است. لطفاً دوباره وارد شوید.';
        }
        return 'خطا در بروزرسانی. لطفاً دوباره تلاش کنید.';
    }

    showError(message) {
        const errorDiv = document.createElement('div');
        errorDiv.className = 'refresh-error-toast';
        errorDiv.innerHTML = `
            <i class="fas fa-exclamation-circle"></i>
            <span>${message}</span>
        `;
        errorDiv.style.cssText = `
            position: fixed;
            bottom: 100px;
            left: 50%;
            transform: translateX(-50%);
            background: linear-gradient(135deg, #ff4444, #cc0000);
            color: white;
            padding: 12px 24px;
            border-radius: 50px;
            z-index: 1000000;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            animation: slideUp 0.3s ease;
        `;
        
        document.body.appendChild(errorDiv);
        
        setTimeout(() => {
            errorDiv.style.animation = 'slideDown 0.3s ease';
            setTimeout(() => errorDiv.remove(), 300);
        }, 4000);
    }

    showRefreshAnimation() {
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.deepRefreshManager = new DeepRefreshManager();
});