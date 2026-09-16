// assets/js/notif.js
// Ava Pay Notification System

// ============================================
// 1. CONFIGURATION
// ============================================
const NOTIF_CONFIG = {
    pollInterval: 5000, // Check every 5 seconds
    soundEnabled: true,
    desktopNotif: true,
    maxDropdownItems: 10
};

// ============================================
// 2. GLOBAL VARIABLES
// ============================================
let notifInterval = null;
let unreadCount = 0;
let isDropdownOpen = false;

// ============================================
// 3. DOM ELEMENTS
// ============================================
function getElements() {
    return {
        bell: document.getElementById('notificationBell'),
        badge: document.getElementById('notificationBadge'),
        dropdown: document.getElementById('notificationDropdown'),
        list: document.getElementById('notificationList')
    };
}

// ============================================
// 4. INITIALIZATION
// ============================================
function initNotificationSystem() {
    
    // Load initial count
    loadNotifCount();
    
    // Start polling
    startPolling();
    
    // Setup event listeners
    setupEventListeners();
    
    // Request notification permission
    requestNotifPermission();
    
}

// ============================================
// 5. POLLING SYSTEM
// ============================================
function startPolling() {
    // Clear existing interval
    if (notifInterval) {
        clearInterval(notifInterval);
    }
    
    // Start new interval
    notifInterval = setInterval(() => {
        checkNewNotifications();
    }, NOTIF_CONFIG.pollInterval);
}



// ============================================
// 6. LOAD NOTIFICATION COUNT
// ============================================
async function loadNotifCount() {
    try {
        const response = await fetch('api/notification_api.php?action=unread_count');
        const data = await response.json();
        
        if (data.success) {
            const c = (typeof data.unread_count !== 'undefined') ? data.unread_count : (data.count || 0);
            updateBadge(c);
            unreadCount = c;
        }
    } catch (error) {
        console.error('Error loading notification count:', error);
    }
}

// ============================================
// 7. CHECK FOR NEW NOTIFICATIONS
// ============================================
async function checkNewNotifications() {
    try {
        const response = await fetch('api/notification_api.php?action=unread_count');
        const data = await response.json();
        
        if (data.success) {
            const oldCount = unreadCount;
            const newCount = (typeof data.unread_count !== 'undefined') ? data.unread_count : (data.count || 0);
            
            // Update badge
            updateBadge(newCount);
            
            // If new notifications arrived
            if (newCount > oldCount) {
                handleNewNotifs(newCount - oldCount);
            }
            
            unreadCount = newCount;
        }
    } catch (error) {
        console.error('Error checking notifications:', error);
    }
}

// ============================================
// 8. HANDLE NEW NOTIFICATIONS
// ============================================
function handleNewNotifs(count) {
    
    // Play sound
    playNotifSound();
    
    // Show desktop notification
    if (NOTIF_CONFIG.desktopNotif) {
        showDesktopNotif(count);
    }
    
    // Show toast
    showNotifToast(`${count} new notification${count > 1 ? 's' : ''}`);
    
    // Refresh dropdown if open
    if (isDropdownOpen) {
        loadNotifList();
    }
}

// ============================================
// 9. LOAD NOTIFICATION LIST
// ============================================
async function loadNotifList() {
    const elements = getElements();
    if (!elements.list) return;
    
    // Show loading
    elements.list.innerHTML = `
        <div class="notification-loading">
            <i class="fas fa-spinner fa-spin"></i> Loading...
        </div>
    `;
    
    try {
        // Mark as read when opening dropdown
        const response = await fetch('api/notification_api.php?action=get');
        const data = await response.json();
        
        if (data.success) {
            renderNotifList(data.notifications, data.total_count);
            
            // Update badge since we marked as read
            updateBadge(0);
            unreadCount = 0;
        } else {
            showNotifError('Failed to load notifications');
        }
    } catch (error) {
        console.error('Error loading notification list:', error);
        showNotifError('Error loading notifications');
    }
}

// ============================================
// 10. RENDER NOTIFICATION LIST
// ============================================
function renderNotifList(notifications, totalCount) {
    const elements = getElements();
    if (!elements.list) return;
    
    if (!notifications || notifications.length === 0) {
        elements.list.innerHTML = `
            <div class="notification-empty">
                <i class="far fa-bell"></i>
                <div>No notifications yet</div>
                <small>You're all caught up!</small>
            </div>
        `;
        return;
    }
    
    let html = '';
    
    notifications.forEach(notif => {
        const icon = getNotifIcon(notif.type);
        const time = formatNotifTime(notif.created_at);
        // API فیلد data ساخت‌یافته ندارد؛ از type/related_id بازسازی می‌کنیم.
        const notifData = notif.data || { type: notif.type, related_id: notif.related_id || null };
        
        html += `
            <div class="notification-item ${notif.is_read ? '' : 'unread'}" 
                 onclick="handleNotifClick(${notif.id}, '${escapeHtml(JSON.stringify(notifData))}')">
                <div class="notification-title">
                    <span><i class="fas ${icon}"></i> ${escapeHtml(notif.title)}</span>
                    ${!notif.is_read ? '<span class="notification-unread-dot"></span>' : ''}
                </div>
                <div class="notification-message">${escapeHtml(notif.message)}</div>
                <div class="notification-time">
                    <i class="far fa-clock"></i> ${time}
                </div>
            </div>
        `;
    });
    
    // Add footer
    html += `
        <div class="notification-footer">
            <a href="notifications.php">
                <i class="fas fa-list"></i> View All ${totalCount} Notifications
            </a>
        </div>
    `;
    
    elements.list.innerHTML = html;
}

// ============================================
// 11. HANDLE NOTIFICATION CLICK
// ============================================
async function handleNotifClick(notifId, dataString) {
    // Mark as read
    await markNotifAsRead(notifId);
    
    // Parse data
    try {
        const data = JSON.parse(dataString);
        
        // Handle based on type
        if (data.type === 'transaction') {
            if (data.transaction_id) {
                window.location.href = `transaction_details.php?id=${data.transaction_id}`;
            }
        }
    } catch (error) {
        console.error('Error handling notification click:', error);
    }
    
    // Close dropdown
    hideNotifDropdown();
}

// ============================================
// 12. MARK NOTIFICATION AS READ
// ============================================
async function markNotifAsRead(notifId) {
    try {
        await fetch('api/notification_api.php?action=mark_read', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ id: notifId })
        });
        
        // Update local count
        if (unreadCount > 0) {
            unreadCount--;
            updateBadge(unreadCount);
        }
    } catch (error) {
        console.error('Error marking notification as read:', error);
    }
}

// ============================================
// 13. MARK ALL AS READ
// ============================================
async function markAllAsRead() {
    if (!confirm('Mark all notifications as read?')) return;
    
    try {
        const response = await fetch('api/notification_api.php?action=mark_all_read', {
            method: 'POST'
        });
        
        const data = await response.json();
        
        if (data.success) {
            unreadCount = 0;
            updateBadge(0);
            showNotifToast('All notifications marked as read', 'success');
            
            // Reload list if dropdown is open
            if (isDropdownOpen) {
                loadNotifList();
            }
        }
    } catch (error) {
        console.error('Error marking all as read:', error);
        showNotifToast('Error marking all as read', 'error');
    }
}

// ============================================
// 14. CLEAR ALL NOTIFICATIONS
// ============================================
async function clearAllNotifications() {
    if (!confirm('Are you sure you want to clear all notifications? This cannot be undone.')) return;
    
    try {
        const response = await fetch('api/notification_api.php?action=clear_all', {
            method: 'POST'
        });
        
        const data = await response.json();
        
        if (data.success) {
            unreadCount = 0;
            updateBadge(0);
            showNotifToast('All notifications cleared', 'success');
            
            // Clear the list
            const elements = getElements();
            if (elements.list) {
                elements.list.innerHTML = `
                    <div class="notification-empty">
                        <i class="far fa-bell"></i>
                        <div>No notifications yet</div>
                        <small>You're all caught up!</small>
                    </div>
                `;
            }
        }
    } catch (error) {
        console.error('Error clearing all notifications:', error);
        showNotifToast('Error clearing all notifications', 'error');
    }
}

// ============================================
// 15. UPDATE BADGE
// ============================================
function updateBadge(count) {
    const elements = getElements();
    if (!elements.badge) return;
    
    if (count > 0) {
        const displayCount = count > 99 ? '99+' : count.toString();
        
        // Update if changed
        if (elements.badge.textContent !== displayCount) {
            elements.badge.textContent = displayCount;
            elements.badge.style.display = 'flex';
            
            // Animate for new notifications
            elements.badge.style.animation = 'pulse 0.5s ease 3';
            setTimeout(() => {
                elements.badge.style.animation = 'pulse 2s infinite';
            }, 1500);
        }
    } else {
        elements.badge.textContent = '0';
        elements.badge.style.display = 'none';
        elements.badge.style.animation = 'none';
    }
}

// ============================================
// 16. NOTIFICATION SOUND
// ============================================
function playNotifSound() {
    if (!NOTIF_CONFIG.soundEnabled) return;
    
    try {
        // Try to play using HTML5 Audio
        const audio = new Audio();
        audio.src = 'assets/sounds/notification.mp3';
        audio.volume = 0.3;
        audio.play().catch(() => {
            playFallbackSound();
        });
    } catch (error) {
        playFallbackSound();
    }
}

function playFallbackSound() {
    try {
        const audioContext = new (window.AudioContext || window.webkitAudioContext)();
        const oscillator = audioContext.createOscillator();
        const gainNode = audioContext.createGain();
        
        oscillator.connect(gainNode);
        gainNode.connect(audioContext.destination);
        
        oscillator.frequency.value = 800;
        oscillator.type = 'sine';
        
        gainNode.gain.setValueAtTime(0.3, audioContext.currentTime);
        gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.5);
        
        oscillator.start(audioContext.currentTime);
        oscillator.stop(audioContext.currentTime + 0.5);
    } catch (error) {
        // Sound not available
    }
}

// ============================================
// 17. DESKTOP NOTIFICATIONS
// ============================================
async function showDesktopNotif(count) {
    // iOS Safari اصلاً Notification را تعریف نمی‌کند؛ دسترسی مستقیم ReferenceError می‌دهد
    if (typeof Notification === 'undefined') return;
    if (Notification.permission !== 'granted') return;
    
    try {
        const response = await fetch('api/notification_api.php?action=get');
        const data = await response.json();
        
        if (data.success && data.notifications.length > 0) {
            const newest = data.notifications[0];
            
            new Notification(newest.title, {
                body: newest.message,
                icon: '/ledor/default-avatar.png',
                tag: `notif-${Date.now()}`
            });
        }
    } catch (error) {
        console.error('Error showing desktop notification:', error);
    }
}

// ============================================
// 18. TOAST NOTIFICATIONS
// ============================================
function showNotifToast(message, type = 'info') {
    // Remove existing toasts
    const existingToasts = document.querySelectorAll('.notif-toast');
    existingToasts.forEach(toast => toast.remove());
    
    // Create toast
    const toast = document.createElement('div');
    toast.className = `notif-toast notif-toast-${type}`;
    
    // Set icon
    let icon = 'fa-info-circle';
    if (type === 'success') icon = 'fa-check-circle';
    if (type === 'error') icon = 'fa-exclamation-circle';
    
    toast.innerHTML = `
        <div class="notif-toast-content">
            <i class="fas ${icon}"></i>
            <span>${message}</span>
        </div>
    `;
    
    // Add styles if not exists
    if (!document.querySelector('#notif-toast-styles')) {
        const style = document.createElement('style');
        style.id = 'notif-toast-styles';
        style.textContent = `
            .notif-toast {
                position: fixed;
                bottom: 100px;
                left: 50%;
                transform: translateX(-50%) translateY(100px);
                background: rgba(0, 0, 0, 0.8);
                backdrop-filter: blur(10px);
                border: 1px solid #333;
                border-radius: 10px;
                padding: 12px 20px;
                color: white;
                font-weight: 500;
                z-index: 9999;
                opacity: 0;
                transition: transform 0.3s, opacity 0.3s;
                max-width: 90%;
                text-align: center;
                box-shadow: 0 5px 20px rgba(0, 0, 0, 0.3);
            }
            
            .notif-toast.show {
                transform: translateX(-50%) translateY(0);
                opacity: 1;
            }
            
            .notif-toast-content {
                display: flex;
                align-items: center;
                gap: 10px;
            }
            
            .notif-toast-content i {
                font-size: 1.1rem;
            }
            
            .notif-toast-success {
                border-left: 4px solid #4CD964;
            }
            
            .notif-toast-error {
                border-left: 4px solid #FF3B30;
            }
            
            .notif-toast-info {
                border-left: 4px solid #007AFF;
            }
        `;
        document.head.appendChild(style);
    }
    
    // Add to page
    document.body.appendChild(toast);
    
    // Show
    setTimeout(() => toast.classList.add('show'), 10);
    
    // Remove
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// ============================================
// 19. DROPDOWN FUNCTIONS
// ============================================
function toggleNotifDropdown() {
    const elements = getElements();
    
    if (isDropdownOpen) {
        hideNotifDropdown();
    } else {
        showNotifDropdown();
    }
}

function showNotifDropdown() {
    const elements = getElements();
    if (!elements.dropdown || !elements.bell) return;
    
    elements.dropdown.classList.add('show');
    elements.bell.classList.add('active');
    isDropdownOpen = true;
    
    // Load notifications
    loadNotifList();
}

function hideNotifDropdown() {
    const elements = getElements();
    if (!elements.dropdown || !elements.bell) return;
    
    elements.dropdown.classList.remove('show');
    elements.bell.classList.remove('active');
    isDropdownOpen = false;
}

// ============================================
// 20. EVENT LISTENERS
// ============================================
function setupEventListeners() {
    const elements = getElements();
    
    // Bell click
    if (elements.bell) {
        elements.bell.addEventListener('click', (e) => {
            e.stopPropagation();
            toggleNotifDropdown();
        });
    }
    
    // Close dropdown when clicking outside
    document.addEventListener('click', (e) => {
        const elements = getElements();
        
        if (isDropdownOpen && 
            !elements.dropdown.contains(e.target) && 
            !elements.bell.contains(e.target)) {
            hideNotifDropdown();
        }
    });
    
    // Refresh when window gets focus
    window.addEventListener('focus', () => {
        loadNotifCount();
    });
}

// ============================================
// 21. NOTIFICATION PERMISSION
// ============================================
function requestNotifPermission() {
    if ('Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission();
    }
}

// ============================================
// 22. HELPER FUNCTIONS
// ============================================
function getNotifIcon(type) {
    switch(type) {
        case 'transaction': return 'fa-exchange-alt';
        case 'admin': return 'fa-user-shield';
        default: return 'fa-info-circle';
    }
}

function formatNotifTime(datetime) {
    const now = new Date();
    const date = new Date(datetime);
    const diffMs = now - date;
    const diffMins = Math.floor(diffMs / (1000 * 60));
    const diffHours = Math.floor(diffMs / (1000 * 60 * 60));
    const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));
    
    if (diffDays > 7) {
        return date.toLocaleDateString('en-US', { 
            month: 'short', 
            day: 'numeric' 
        });
    } else if (diffDays > 1) {
        return `${diffDays} days ago`;
    } else if (diffDays === 1) {
        return 'Yesterday';
    } else if (diffHours > 0) {
        return `${diffHours} hour${diffHours > 1 ? 's' : ''} ago`;
    } else if (diffMins > 0) {
        return `${diffMins} minute${diffMins > 1 ? 's' : ''} ago`;
    } else {
        return 'Just now';
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function showNotifError(message) {
    const elements = getElements();
    if (elements.list) {
        elements.list.innerHTML = `
            <div class="notification-empty">
                <i class="fas fa-exclamation-triangle"></i>
                <div>${message}</div>
                <small>Please try again later</small>
            </div>
        `;
    }
}

// ============================================
// 23. GLOBAL EXPORTS
// ============================================
// Make functions available globally
window.toggleNotifications = toggleNotifDropdown;
window.clearAllNotifications = clearAllNotifications;
window.markAllAsRead = markAllAsRead;
window.handleNotifClick = handleNotifClick;

// ============================================
// 24. AUTO INITIALIZE
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    // Check if notification elements exist
    const bell = document.getElementById('notificationBell');
    const badge = document.getElementById('notificationBadge');
    
    if (bell && badge) {
        // Initialize system
        initNotificationSystem();
        
        // Add CSS for badge if not exists
        if (!document.querySelector('#notif-badge-styles')) {
            const style = document.createElement('style');
            style.id = 'notif-badge-styles';
            style.textContent = `
                .notification-badge {
                    position: absolute;
                    top: 0;
                    right: 0;
                    background: #FF3B30;
                    color: white;
                    font-size: 0.7rem;
                    font-weight: bold;
                    min-width: 18px;
                    height: 18px;
                    border-radius: 50%;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    border: 2px solid #1a1a2e;
                    display: none;
                }
                
                @keyframes pulse {
                    0% { transform: scale(1); }
                    50% { transform: scale(1.1); }
                    100% { transform: scale(1); }
                }
            `;
            document.head.appendChild(style);
        }
    }
});

// ============================================
// 25. EXPORT FOR TESTING
// ============================================
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        initNotificationSystem,
        loadNotifCount,
        loadNotifList,
        markAllAsRead,
        clearAllNotifications,
        updateBadge,
        showNotifToast
    };
}







