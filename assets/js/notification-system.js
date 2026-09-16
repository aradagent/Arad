// assets/js/notification-system.js
// سیستم کامل نوتیفیکیشن - نسخه نهایی با مودال مرکزی در موبایل

class NotificationSoundManager {
    constructor() {
        this.audioContext = null;
        this.soundEnabled = true;
        this.lastPlayTime = 0;
        this.minPlayInterval = 1500;
        this.userInteracted = false;
        this.pendingQueue = [];
        this.init();
    }
    
    init() {
        this.loadSettings();
        this.initUserInteraction();
    }
    
    loadSettings() {
        try {
            const saved = localStorage.getItem('avapay_sound_enabled');
            if (saved !== null) {
                this.soundEnabled = saved === 'true';
            }
        } catch(e) {}
    }
    
    saveSettings() {
        try {
            localStorage.setItem('avapay_sound_enabled', this.soundEnabled);
        } catch(e) {}
    }
    
    initUserInteraction() {
        const enableAudio = () => {
            if (!this.userInteracted) {
                this.userInteracted = true;
                this.initAudioContext();
                this.playPendingSounds();
            }
        };
        document.addEventListener('click', enableAudio);
        document.addEventListener('touchstart', enableAudio);
    }
    
    initAudioContext() {
        if (this.audioContext) return;
        try {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            this.audioContext = new AudioCtx();
            this.audioContext.resume();
        } catch(e) {}
    }
    
    playBeep() {
        if (!this.soundEnabled) return false;
        const now = Date.now();
        if (now - this.lastPlayTime < this.minPlayInterval) return false;
        this.lastPlayTime = now;
        
        if (this.audioContext && this.userInteracted) {
            try {
                const ctx = this.audioContext;
                const oscillator = ctx.createOscillator();
                const gain = ctx.createGain();
                oscillator.connect(gain);
                gain.connect(ctx.destination);
                oscillator.frequency.value = 880;
                oscillator.type = 'sine';
                gain.gain.setValueAtTime(0.2, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.35);
                oscillator.start();
                oscillator.stop(ctx.currentTime + 0.35);
                setTimeout(() => {
                    if (!ctx) return;
                    const osc2 = ctx.createOscillator();
                    const gain2 = ctx.createGain();
                    osc2.connect(gain2);
                    gain2.connect(ctx.destination);
                    osc2.frequency.value = 1100;
                    osc2.type = 'sine';
                    gain2.gain.setValueAtTime(0.15, ctx.currentTime);
                    gain2.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.25);
                    osc2.start();
                    osc2.stop(ctx.currentTime + 0.25);
                }, 120);
                return true;
            } catch(e) {}
        }
        return this.fallbackBeep();
    }
    
    playBell() {
        if (!this.soundEnabled) return false;
        const now = Date.now();
        if (now - this.lastPlayTime < this.minPlayInterval) return false;
        this.lastPlayTime = now;
        
        if (this.audioContext && this.userInteracted) {
            try {
                const ctx = this.audioContext;
                const osc1 = ctx.createOscillator();
                const gain1 = ctx.createGain();
                osc1.connect(gain1);
                gain1.connect(ctx.destination);
                osc1.frequency.value = 523.25;
                osc1.type = 'sine';
                gain1.gain.setValueAtTime(0.2, ctx.currentTime);
                gain1.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.3);
                osc1.start();
                osc1.stop(ctx.currentTime + 0.3);
                setTimeout(() => {
                    if (!ctx) return;
                    const osc2 = ctx.createOscillator();
                    const gain2 = ctx.createGain();
                    osc2.connect(gain2);
                    gain2.connect(ctx.destination);
                    osc2.frequency.value = 659.25;
                    osc2.type = 'sine';
                    gain2.gain.setValueAtTime(0.2, ctx.currentTime + 0.15);
                    gain2.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.45);
                    osc2.start();
                    osc2.stop(ctx.currentTime + 0.45);
                }, 150);
                return true;
            } catch(e) {}
        }
        return this.fallbackBell();
    }
    
    playImportant() {
        if (!this.soundEnabled) return false;
        const now = Date.now();
        if (now - this.lastPlayTime < this.minPlayInterval) return false;
        this.lastPlayTime = now;
        
        if (this.audioContext && this.userInteracted) {
            try {
                const ctx = this.audioContext;
                const notes = [523.25, 659.25, 783.99];
                notes.forEach((freq, index) => {
                    setTimeout(() => {
                        if (!ctx) return;
                        const osc = ctx.createOscillator();
                        const gain = ctx.createGain();
                        osc.connect(gain);
                        gain.connect(ctx.destination);
                        osc.frequency.value = freq;
                        osc.type = 'sine';
                        gain.gain.setValueAtTime(0.2, ctx.currentTime);
                        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.25);
                        osc.start();
                        osc.stop(ctx.currentTime + 0.25);
                    }, index * 120);
                });
                return true;
            } catch(e) {}
        }
        return this.fallbackImportant();
    }
    
    fallbackBeep() {
        if (navigator.vibrate) navigator.vibrate(80);
        this.createQuickOscillator(880, 0.2);
        return true;
    }
    
    fallbackBell() {
        if (navigator.vibrate) navigator.vibrate([80, 40, 80]);
        this.createQuickOscillator(523.25, 0.15);
        setTimeout(() => this.createQuickOscillator(659.25, 0.15), 150);
        return true;
    }
    
    fallbackImportant() {
        if (navigator.vibrate) navigator.vibrate([100, 50, 100, 50, 100]);
        this.createQuickOscillator(523.25, 0.12);
        setTimeout(() => this.createQuickOscillator(659.25, 0.12), 130);
        setTimeout(() => this.createQuickOscillator(783.99, 0.12), 260);
        return true;
    }
    
    createQuickOscillator(frequency, duration) {
        try {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            const ctx = new AudioCtx();
            const oscillator = ctx.createOscillator();
            const gain = ctx.createGain();
            oscillator.connect(gain);
            gain.connect(ctx.destination);
            oscillator.frequency.value = frequency;
            oscillator.type = 'sine';
            gain.gain.setValueAtTime(0.12, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + duration);
            oscillator.start();
            oscillator.stop(ctx.currentTime + duration);
            ctx.resume();
            setTimeout(() => ctx.close(), (duration + 0.1) * 1000);
        } catch(e) {}
    }
    
    playForNotification(notificationType) {
        if (!this.soundEnabled) return;
        if (!this.userInteracted) {
            this.pendingQueue.push(notificationType);
            return;
        }
        switch(notificationType) {
            case 'offer_received': this.playBell(); break;
            case 'offer_accepted': this.playImportant(); break;
            case 'deal_completed': this.playImportant(); break;
            case 'offer_rejected': this.playBeep(); break;
            default: this.playBeep();
        }
    }
    
    playPendingSounds() {
        this.pendingQueue.forEach((type, index) => {
            setTimeout(() => this.playForNotification(type), index * 200);
        });
        this.pendingQueue = [];
    }
    
    toggleSound() {
        this.soundEnabled = !this.soundEnabled;
        this.saveSettings();
        if (this.soundEnabled && this.userInteracted) this.playBeep();
        return this.soundEnabled;
    }
    
    isSoundEnabled() { return this.soundEnabled; }
}

class NotificationManager {
    constructor() {
        this.pollingInterval = null;
        this.lastUnreadCount = 0;
        this.isDropdownOpen = false;
        this.allNotifications = [];
        this.currentPage = 1;
        this.hasMore = true;
        this.totalCount = 0;
        this.soundManager = new NotificationSoundManager();
        this.pendingActions = new Set();
        this.init();
    }
    
    init() {
        this.loadNotifications();
        this.startPolling();
        this.setupEventListeners();
        this.updateUnreadCount();
    }
    
    setupEventListeners() {
        const bell = document.getElementById('notificationBell');
        const overlay = document.getElementById('notificationOverlay');
        
        if (bell) {
            bell.addEventListener('click', (e) => {
                e.stopPropagation();
                this.toggleDropdown();
            });
        }
        
        if (overlay) {
            overlay.addEventListener('click', () => this.closeDropdown());
        }
        
        document.addEventListener('click', (e) => {
            const dropdown = document.getElementById('notificationDropdown');
            const bellElement = document.getElementById('notificationBell');
            if (!this.isDropdownOpen || !dropdown || !bellElement) return;
            if (dropdown.contains(e.target) || bellElement.contains(e.target)) return;
            // (رفع باگ) مودال‌هایی مثل «رد پیشنهاد» (و مودال‌های dashboard.php/
            // arad.php/money_transfer.php) با insertAdjacentHTML مستقیم به body
            // اضافه می‌شوند، یعنی از نظر DOM داخل دراپ‌داون نیستند. بدون این
            // بررسی، کلیک روی هر نقطه‌ای از آن مودال‌ها (even دکمه‌های خودشان)
            // به این شنونده می‌رسید و دراپ‌داون را که پشتشان باز مانده بود
            // می‌بست — از دید کاربر انگار «پشت مودال یک پنجره می‌افتاد».
            if (e.target.closest('.custom-modal-overlay, .ava-fs-modal, .ava-sheet, .modal-overlay, .arf-modal-overlay')) return;
            this.closeDropdown();
        });
    }
    
    setupEventDelegation() {
        const container = document.getElementById('notificationList');
        if (!container) return;
        
        if (this.containerClickHandler) {
            container.removeEventListener('click', this.containerClickHandler);
        }
        
        this.containerClickHandler = this.handleContainerClick.bind(this);
        container.addEventListener('click', this.containerClickHandler);
    }
    
    async loadNotifications(reset = true) {
        try {
            if (reset) {
                this.currentPage = 1;
                this.hasMore = true;
            }
            if (!this.hasMore) return;
            
            const response = await fetch(`api/notification_api.php?action=get&page=${this.currentPage}&limit=10`);
            const result = await response.json();
            
            if (result.success) {
                if (reset) {
                    this.allNotifications = result.notifications || [];
                } else {
                    this.allNotifications = [...this.allNotifications, ...(result.notifications || [])];
                }
                this.totalCount = result.total_count || this.allNotifications.length;
                this.hasMore = result.has_more || false;
                this.updateBadge(result.unread_count);
                if (this.isDropdownOpen) this.renderNotifications();
            }
        } catch(e) { console.error('Error loading notifications:', e); }
    }
    
    renderNotifications() {
        const container = document.getElementById('notificationList');
        if (!container) return;
        
        if (!this.allNotifications || this.allNotifications.length === 0) {
            container.innerHTML = `<div class="empty-state"><i class="fas fa-bell-slash"></i><p>هیچ اعلانی وجود ندارد</p></div>`;
            return;
        }
        
        let html = this.allNotifications.map(notif => {
            let iconHtml = '';
            let iconClass = '';
            
            switch(notif.type) {
                case 'offer_received': 
                    iconHtml = '<i class="fas fa-hand-holding-usd"></i>'; 
                    iconClass = 'offer_received';
                    break;
                case 'offer_accepted': 
                    iconHtml = '<i class="fas fa-check-circle"></i>'; 
                    iconClass = 'offer_accepted';
                    break;
                case 'offer_rejected': 
                    iconHtml = '<i class="fas fa-times-circle"></i>'; 
                    iconClass = 'offer_rejected';
                    break;
                case 'deal_completed': 
                    iconHtml = '<i class="fas fa-trophy"></i>'; 
                    iconClass = 'deal_completed';
                    break;
                default: 
                    iconHtml = '<i class="fas fa-bell"></i>';
                    iconClass = 'default';
            }
            
            let offerId = notif.related_id || 0;
            let notifId = notif.id;
            let actionsHtml = '';
            
            if (notif.type === 'offer_received' && notif.is_read == 0 && offerId > 0) {
                actionsHtml = `
                    <div class="notification-actions">
                        <button type="button" class="action-btn accept" data-action="accept" data-offer-id="${offerId}" data-notif-id="${notifId}">
                            <i class="fas fa-check"></i>
                            <span>پذیرش</span>
                        </button>
                        <button type="button" class="action-btn reject" data-action="reject" data-offer-id="${offerId}" data-notif-id="${notifId}">
                            <i class="fas fa-times"></i>
                            <span>رد</span>
                        </button>
                    </div>
                `;
            }
            
            return `
                <div class="notification-item ${notif.is_read == 0 ? 'unread' : ''}" data-id="${notif.id}" data-offer-id="${offerId}" data-type="${this.escapeHtml(notif.type || '')}" data-related-id="${notif.related_id || 0}">
                    <div class="notification-icon ${iconClass}">
                        ${iconHtml}
                    </div>
                    <div class="notification-content">
                        <div class="notification-title">${this.escapeHtml(notif.title)}</div>
                        <div class="notification-message">${this.escapeHtml(notif.message)}</div>
                        <div class="notification-time">${notif.time_ago || 'لحظاتی پیش'}</div>
                        ${actionsHtml}
                    </div>
                    <button type="button" class="notification-delete" data-action="delete" data-notif-id="${notif.id}">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
        }).join('');
        
        if (this.totalCount > this.allNotifications.length) {
            html += `<div class="view-all-btn" data-action="view_all"><span>مشاهده همه اعلان‌ها (${this.totalCount})</span><i class="fas fa-chevron-left"></i></div>`;
        }
        
        container.innerHTML = html;
        this.setupEventDelegation();
    }
    
    handleContainerClick(e) {
        e.stopPropagation();
        
        const target = e.target.closest('button');
        if (!target) {
            // کلیک روی خودِ بدنه‌ی آیتم (نه دکمه) — برای نوتیفیکیشن‌های «فیش جدید»، فوراً عکس را نشان بده
            const item = e.target.closest('.notification-item');
            if (!item) return;
            const notifType = item.getAttribute('data-type');
            const relatedId = parseInt(item.getAttribute('data-related-id'));
            const notifId = parseInt(item.getAttribute('data-id'));
            if ((notifType === 'topup_receipt' || notifType === 'invoice_paid') && relatedId > 0) {
                if (typeof window.avaOpenReceiptFromNotif === 'function') {
                    window.avaOpenReceiptFromNotif(notifType, relatedId);
                }
                if (item.classList.contains('unread') && notifId > 0) {
                    item.classList.remove('unread');
                    this.markAsReadSilently(notifId);
                }
            }
            return;
        }
        
        const action = target.getAttribute('data-action');
        if (!action) return;
        
        switch(action) {
            case 'accept':
                const offerId = parseInt(target.getAttribute('data-offer-id'));
                const notifId = parseInt(target.getAttribute('data-notif-id'));
                if (offerId && offerId > 0 && notifId && notifId > 0) {
                    this.acceptOffer(notifId, offerId, target);
                } else {
                    this.showToast('خطا: اطلاعات پیشنهاد یافت نشد', 'error');
                }
                break;
                
            case 'reject':
                const offerId2 = parseInt(target.getAttribute('data-offer-id'));
                const notifId2 = parseInt(target.getAttribute('data-notif-id'));
                if (offerId2 && offerId2 > 0 && notifId2 && notifId2 > 0) {
                    this.showRejectModal(notifId2, offerId2, target);
                } else {
                    this.showToast('خطا: شناسه پیشنهاد یافت نشد', 'error');
                }
                break;
                
            case 'delete':
                const notifId3 = parseInt(target.getAttribute('data-notif-id'));
                if (notifId3 && notifId3 > 0) {
                    this.deleteNotification(notifId3);
                }
                break;
                
            case 'view_all':
                window.location.href = 'notifications.php';
                break;
        }
    }
    
    async acceptOffer(notificationId, offerId, buttonElement) {
        const actionKey = `accept_${notificationId}`;
        if (this.pendingActions.has(actionKey)) return;
        this.pendingActions.add(actionKey);
        
        const originalText = buttonElement.innerHTML;
        buttonElement.disabled = true;
        buttonElement.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        
        try {
            const response = await fetch('api/offer_api.php?action=accept', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ offer_id: offerId })
            });
            const result = await response.json();
            
            if (result.success) {
                this.showToast('✅ پیشنهاد با موفقیت پذیرفته شد', 'success');
                await this.deleteNotificationFromDB(notificationId);
                this.removeNotificationFromDOM(notificationId);
                this.allNotifications = this.allNotifications.filter(n => n.id !== notificationId);
                this.totalCount = Math.max(0, this.totalCount - 1);
                this.lastUnreadCount = Math.max(0, this.lastUnreadCount - 1);
                this.updateBadge(this.lastUnreadCount);
                await this.refreshMainPageData();
                
                if (this.allNotifications.length === 0 && this.isDropdownOpen) {
                    this.renderNotifications();
                }
            } else {
                this.showToast(result.message || 'خطا در پذیرش پیشنهاد', 'error');
                buttonElement.disabled = false;
                buttonElement.innerHTML = originalText;
            }
        } catch(e) {
            console.error('Error:', e);
            this.showToast('خطا در ارتباط با سرور', 'error');
            buttonElement.disabled = false;
            buttonElement.innerHTML = originalText;
        }
        
        this.pendingActions.delete(actionKey);
    }
    
    showRejectModal(notificationId, offerId, buttonElement) {
        const actionKey = `reject_modal_${notificationId}`;
        if (this.pendingActions.has(actionKey)) return;
        this.pendingActions.add(actionKey);
        
        const modalHtml = `
            <div class="custom-modal-overlay" id="rejectModal_${notificationId}" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);display:flex;align-items:center;justify-content:center;z-index:20000;">
                <div class="custom-modal" style="background:#1a1a2e;border-radius:24px;width:90%;max-width:360px;overflow:hidden;">
                    <div class="custom-modal-header" style="padding:16px 20px;border-bottom:1px solid rgba(255,255,255,0.1);display:flex;justify-content:space-between;align-items:center;">
                        <h4 style="margin:0;color:#FF3B30;font-size:1rem;">رد پیشنهاد</h4>
                        <button type="button" class="close-modal-btn" data-close-modal style="background:none;border:none;color:#888;font-size:20px;cursor:pointer;">×</button>
                    </div>
                    <div class="custom-modal-body" style="padding:20px;">
                        <p style="margin-bottom:12px;color:#ccc;font-size:0.85rem;">لطفاً دلیل رد پیشنهاد را وارد کنید:</p>
                        <textarea class="reject-textarea" id="rejectReason_${notificationId}" rows="3" placeholder="مثال: قیمت مناسب نیست ..." style="width:100%;padding:12px;border-radius:16px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);color:white;resize:none;font-size:0.8rem;"></textarea>
                    </div>
                    <div class="custom-modal-footer" style="padding:16px 20px;display:flex;gap:12px;border-top:1px solid rgba(255,255,255,0.05);">
                        <button type="button" class="btn-cancel" data-cancel-modal style="flex:1;padding:10px;border-radius:30px;background:rgba(255,255,255,0.08);border:none;color:white;cursor:pointer;">انصراف</button>
                        <button type="button" class="btn-confirm reject" data-confirm-reject style="flex:1;padding:10px;border-radius:30px;background:#FF3B30;border:none;color:white;cursor:pointer;">تایید و رد</button>
                    </div>
                </div>
            </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        
        const modal = document.getElementById(`rejectModal_${notificationId}`);
        const closeBtn = modal.querySelector('[data-close-modal]');
        const cancelBtn = modal.querySelector('[data-cancel-modal]');
        const confirmBtn = modal.querySelector('[data-confirm-reject]');
        
        const closeModal = () => {
            modal.remove();
            this.pendingActions.delete(actionKey);
        };
        
        closeBtn.addEventListener('click', closeModal);
        cancelBtn.addEventListener('click', closeModal);

        // (اصلاح) قبلاً کلیک روی پس‌زمینه و کلید Escape این مودال را نمی‌بست
        // و تنها راه خروج دو دکمه‌ی داخل آن بودند.
        modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
        const escHandler = (e) => { if (e.key === 'Escape') closeModal(); };
        document.addEventListener('keydown', escHandler);
        modal._escHandler = escHandler;
        // اطمینان از حذف شنونده‌ی Escape هنگام بسته‌شدن مودال (جلوگیری از نشت حافظه)
        modal.remove = (function(origRemove){
            return function(){ document.removeEventListener('keydown', escHandler); return origRemove.apply(this, arguments); };
        })(modal.remove);

        confirmBtn.addEventListener('click', async () => {
            const reason = document.getElementById(`rejectReason_${notificationId}`)?.value || '';
            if (!reason) {
                this.showToast('لطفاً دلیل رد را وارد کنید', 'error');
                return;
            }
            await this.rejectOffer(notificationId, offerId, reason, closeModal);
        });
        
        this.pendingActions.delete(actionKey);
    }
    
    async rejectOffer(notificationId, offerId, reason, closeModalCallback) {
        const actionKey = `reject_${notificationId}`;
        if (this.pendingActions.has(actionKey)) return;
        this.pendingActions.add(actionKey);
        
        try {
            const response = await fetch('api/offer_api.php?action=reject', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ offer_id: offerId, reason: reason })
            });
            const result = await response.json();
            
            if (result.success) {
                this.showToast('❌ پیشنهاد رد شد', 'info');
                await this.deleteNotificationFromDB(notificationId);
                this.removeNotificationFromDOM(notificationId);
                this.allNotifications = this.allNotifications.filter(n => n.id !== notificationId);
                this.totalCount = Math.max(0, this.totalCount - 1);
                this.lastUnreadCount = Math.max(0, this.lastUnreadCount - 1);
                this.updateBadge(this.lastUnreadCount);
                if (closeModalCallback) closeModalCallback();
                await this.refreshMainPageData();
                
                if (this.allNotifications.length === 0 && this.isDropdownOpen) {
                    this.renderNotifications();
                }
            } else {
                this.showToast(result.message || 'خطا در رد پیشنهاد', 'error');
            }
        } catch(e) {
            console.error('Error:', e);
            this.showToast('خطا در ارتباط با سرور', 'error');
        }
        
        this.pendingActions.delete(actionKey);
    }
    
    removeNotificationFromDOM(notificationId) {
        const notificationItem = document.querySelector(`.notification-item[data-id="${notificationId}"]`);
        if (notificationItem) {
            notificationItem.classList.add('removing');
            setTimeout(() => {
                if (notificationItem && notificationItem.remove) {
                    notificationItem.remove();
                }
            }, 150);
        }
    }
    
    async deleteNotificationFromDB(notificationId) {
        try {
            const response = await fetch('api/notification_api.php?action=delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ notification_id: notificationId })
            });
            const result = await response.json();
            if (result.success) {
            }
        } catch(e) {
            console.error('Error deleting from DB:', e);
        }
    }
    
    async markAsReadSilently(notificationId) {
        try {
            await fetch('api/notification_api.php?action=mark_read', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ notification_id: notificationId })
            });
            this.allNotifications = this.allNotifications.map(n => n.id === notificationId ? { ...n, is_read: 1 } : n);
            this.lastUnreadCount = Math.max(0, this.lastUnreadCount - 1);
            this.updateBadge(this.lastUnreadCount);
        } catch(e) {
            console.error('Error marking as read:', e);
        }
    }
    
    async deleteNotification(notificationId) {
        if (!confirm('آیا از حذف این اعلان اطمینان دارید؟')) return;
        
        const actionKey = `delete_${notificationId}`;
        if (this.pendingActions.has(actionKey)) return;
        this.pendingActions.add(actionKey);
        
        try {
            await this.deleteNotificationFromDB(notificationId);
            this.showToast('اعلان حذف شد', 'success');
            
            const notification = this.allNotifications.find(n => n.id === notificationId);
            if (notification && notification.is_read == 0) {
                this.lastUnreadCount = Math.max(0, this.lastUnreadCount - 1);
                this.updateBadge(this.lastUnreadCount);
            }
            
            this.removeNotificationFromDOM(notificationId);
            this.allNotifications = this.allNotifications.filter(n => n.id !== notificationId);
            this.totalCount = Math.max(0, this.totalCount - 1);
            
            if (this.allNotifications.length === 0 && this.isDropdownOpen) {
                this.renderNotifications();
            }
        } catch(e) {
            console.error(e);
            this.showToast('خطا در حذف اعلان', 'error');
        }
        
        this.pendingActions.delete(actionKey);
    }
    
    async markAllAsRead() {
        try {
            // پاک‌کردن همه‌ی اعلان‌ها از سرور با یک درخواست
            await fetch('api/notification_api.php?action=clear_all', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({})
            });

            // پاک‌کردن همه از DOM و state
            this.allNotifications = [];
            this.totalCount = 0;
            this.lastUnreadCount = 0;
            this.hasMore = false;
            this.updateBadge(0);

            const container = document.getElementById('notificationList');
            if (container) {
                container.innerHTML = `
                    <div class="empty-state" style="text-align:center;padding:36px 16px;color:rgba(255,255,255,0.35);">
                        <i class="fas fa-bell-slash" style="font-size:2rem;display:block;margin-bottom:10px;opacity:.5;"></i>
                        <p style="margin:0;font-size:.85rem;">اعلانی وجود ندارد</p>
                    </div>`;
            }

            this.showToast('✅ همه اعلان‌ها پاک شدند', 'success');
        } catch(e) { 
            console.error(e);
            this.showToast('خطا در حذف اعلان‌ها', 'error');
        }
    }
    
    async refreshMainPageData() {
        if (typeof window.loadAllOffers === 'function') {
            await window.loadAllOffers();
        }
        if (typeof window.loadActiveDeals === 'function') {
            await window.loadActiveDeals();
        }
        if (typeof window.loadCompletedDeals === 'function') {
            await window.loadCompletedDeals();
        }
        if (typeof window.loadAds === 'function') {
            await window.loadAds();
        }
    }
    
    async updateUnreadCount() {
        try {
            const response = await fetch('api/notification_api.php?action=unread_count');
            const result = await response.json();
            if (result.success) {
                const oldCount = this.lastUnreadCount;
                this.updateBadge(result.unread_count);
                
                if (result.last_notification && result.unread_count > oldCount) {
                    const lastNotif = result.last_notification;
                    const lastSeen = localStorage.getItem('avapay_last_notification_time');
                    const notifTime = new Date(lastNotif.created_at).getTime();
                    
                    if (!lastSeen || notifTime > parseInt(lastSeen)) {
                        this.soundManager.playForNotification(lastNotif.type);
                        this.showToast(`🔔 ${lastNotif.title}`, 'info');
                        localStorage.setItem('avapay_last_notification_time', notifTime);
                        await this.loadNotifications();
                        
                        if (this.isDropdownOpen) {
                            this.renderNotifications();
                        }
                    }
                }
                
                this.lastUnreadCount = result.unread_count;
            }
        } catch(e) { console.error(e); }
    }
    
    updateBadge(count) {
        const badge = document.getElementById('notificationBadge');
        const dot = document.getElementById('notificationDot');
        if (count > 0) {
            if (badge) { 
                badge.style.display = 'flex'; 
                badge.textContent = count > 99 ? '99+' : count; 
            }
            if (dot) dot.style.display = 'block';
        } else {
            if (badge) badge.style.display = 'none';
            if (dot) dot.style.display = 'none';
        }
    }
    
    toggleDropdown() {
        const dropdown = document.getElementById('notificationDropdown');
        const overlay = document.getElementById('notificationOverlay');
        if (!dropdown) return;
        
        if (this.isDropdownOpen) {
            this.closeDropdown();
            return;
        }
        
        this.isDropdownOpen = true;
        if (overlay) { overlay.classList.add('active'); overlay.classList.add('show'); }
        dropdown.classList.add('show');
        
        this.positionDropdown();
        this.loadNotifications();
        
        const closeBtn = document.getElementById('closeDropdownBtn');
        if (closeBtn) {
            closeBtn.onclick = () => this.closeDropdown();
        }
        // اتصال دکمه «خواندن همه» — قبلاً هرگز وصل نشده بود
        const markAllBtn = document.getElementById('markAllReadBtn');
        if (markAllBtn) {
            markAllBtn.onclick = (e) => { e.stopPropagation(); this.markAllAsRead(); };
        }
        
        window.addEventListener('resize', () => this.positionDropdown());
        window.addEventListener('scroll', () => this.positionDropdown());
    }
    
    closeDropdown() {
        const dropdown = document.getElementById('notificationDropdown');
        const overlay = document.getElementById('notificationOverlay');
        
        if (dropdown) dropdown.classList.remove('show');
        if (overlay) { overlay.classList.remove('active'); overlay.classList.remove('show'); }
        this.isDropdownOpen = false;
        
        window.removeEventListener('resize', () => this.positionDropdown());
        window.removeEventListener('scroll', () => this.positionDropdown());
    }
    
   // متد positionDropdown در کلاس NotificationManager
positionDropdown() {
    const dropdown = document.getElementById('notificationDropdown');
    if (!dropdown) return;

    if (window.innerWidth <= 768) {
        // موبایل: bottom-sheet — همه چیز را CSS کنترل می‌کند
        dropdown.style.position = '';
        dropdown.style.top = '';
        dropdown.style.left = '';
        dropdown.style.right = '';
        dropdown.style.bottom = '';
        dropdown.style.transform = '';
        dropdown.style.margin = '';
        dropdown.style.maxHeight = '';
        dropdown.style.width = '';
        dropdown.style.maxWidth = '';
    } else {
        // دسکتاپ: زیر زنگوله
        const bell = document.getElementById('notificationBell');
        if (bell) {
            const bellRect = bell.getBoundingClientRect();
            dropdown.style.position = 'fixed';
            dropdown.style.top = (bellRect.bottom + 10) + 'px';
            dropdown.style.right = (window.innerWidth - bellRect.right) + 'px';
            dropdown.style.left = 'auto';
            dropdown.style.bottom = 'auto';
            dropdown.style.transform = 'none';
        }
    }
}
    
    startPolling() {
        if (this.pollingInterval) clearInterval(this.pollingInterval);
        this.updateUnreadCount();
        this.pollingInterval = setInterval(() => this.updateUnreadCount(), 5000);
    }
    
    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    showToast(message, type = 'info') {
        const existingToast = document.querySelector('.custom-toast');
        if (existingToast) existingToast.remove();
        
        const toast = document.createElement('div');
        toast.className = `custom-toast ${type}`;
        let icon = '🔔';
        if (type === 'success') icon = '✅';
        if (type === 'error') icon = '❌';
        if (type === 'info') icon = 'ℹ️';
        toast.innerHTML = `<span class="toast-icon">${icon}</span><span>${message}</span>`;
        
        if (!document.querySelector('style[data-toast-style]')) {
            const style = document.createElement('style');
            style.setAttribute('data-toast-style', 'true');
            style.textContent = `
                .custom-toast {
                    position: fixed; bottom: 100px; left: 50%;
                    transform: translateX(-50%) translateY(100px);
                    background: rgba(0,0,0,0.95);
                    padding: 12px 24px; border-radius: 50px; color: white;
                    z-index: 11000; transition: all 0.3s ease; opacity: 0;
                    font-size: 0.85rem; display: flex; align-items: center; gap: 10px;
                    border: 1px solid rgba(255,215,0,0.3); font-weight: 500;
                    white-space: nowrap;
                }
                .custom-toast.show { transform: translateX(-50%) translateY(0); opacity: 1; }
                .custom-toast.success { border-color: #4CD964; }
                .custom-toast.error { border-color: #FF3B30; }
                .custom-toast.info { border-color: #FFD700; }
                @media (max-width: 600px) {
                    .custom-toast { white-space: normal; max-width: 85%; text-align: center; font-size: 0.75rem; padding: 10px 18px; bottom: 80px; }
                }
                .notification-item.removing {
                    animation: fadeOutRight 0.15s ease forwards;
                }
                @keyframes fadeOutRight {
                    to { opacity: 0; transform: translateX(20px); }
                }
                .action-btn.accept:disabled, .action-btn.reject:disabled {
                    opacity: 0.5;
                    cursor: not-allowed;
                }
            `;
            document.head.appendChild(style);
        }
        
        document.body.appendChild(toast);
        setTimeout(() => toast.classList.add('show'), 10);
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3500);
    }
}

let notificationManager = null;

document.addEventListener('DOMContentLoaded', function() {
    notificationManager = new NotificationManager();
    window.notificationManager = notificationManager;
    
    const soundBtn = document.getElementById('soundToggleBtn');
    if (soundBtn) {
        soundBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            notificationManager.toggleSound();
        });
    }
    
    localStorage.setItem('avapay_last_notification_time', Date.now());
});

function markAllNotificationsRead() {
    if (window.notificationManager) {
        window.notificationManager.markAllAsRead();
    }
}