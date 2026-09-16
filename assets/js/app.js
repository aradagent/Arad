// NeoBank PWA Application JavaScript

document.addEventListener('DOMContentLoaded', function() {
    
    // Initialize modals
    initModals();
    
    // Initialize bank card eye toggle
    initEyeToggle();
    
    // Initialize quick actions
    initQuickActions();
    
    // Initialize bottom navigation
    initNavigation();
    
    // Check for PWA installation
    initPWA();
    
    // Load user data if on dashboard
    if (document.querySelector('.dashboard')) {
        loadUserData();
        loadRecentTransactions();
    }
    
    // Fix avatar sizes
    setTimeout(fixAvatars, 500);
});

// Fix avatar sizes function
function fixAvatars() {
    // Fix transaction avatars
    const transactionAvatars = document.querySelectorAll('.transaction-avatar');
    
    transactionAvatars.forEach(avatar => {
        // Force size constraints
        avatar.style.width = '45px';
        avatar.style.height = '45px';
        avatar.style.minWidth = '45px';
        avatar.style.minHeight = '45px';
        avatar.style.overflow = 'hidden';
        avatar.style.borderRadius = '50%';
        avatar.style.flexShrink = '0';
        avatar.style.marginRight = '15px';
        avatar.style.display = 'flex';
        avatar.style.alignItems = 'center';
        avatar.style.justifyContent = 'center';
        
        // Fix images inside avatars
        const img = avatar.querySelector('img');
        if (img) {
            img.style.width = '100%';
            img.style.height = '100%';
            img.style.objectFit = 'cover';
            img.style.borderRadius = '50%';
            
            // Handle image loading errors
            img.onerror = function() {
                this.style.display = 'none';
                const name = this.alt || 'User';
                const initials = getInitialsFromName(name);
                
                // Create or use existing initials div
                let initialsDiv = this.nextElementSibling;
                if (!initialsDiv || !initialsDiv.classList.contains('avatar-initials')) {
                    initialsDiv = document.createElement('div');
                    initialsDiv.className = 'avatar-initials';
                    this.parentNode.appendChild(initialsDiv);
                }
                
                initialsDiv.textContent = initials;
                initialsDiv.style.cssText = `
                    width: 100%;
                    height: 100%;
                    border-radius: 50%;
                    background: linear-gradient(45deg, #6C40C5, #FF4D8D);
                    color: white;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-weight: bold;
                    font-size: 0.9rem;
                `;
                initialsDiv.style.display = 'flex';
            };
        }
        
        // Fix initials divs
        const initialsDivs = avatar.querySelectorAll('.avatar-initials');
        initialsDivs.forEach(div => {
            div.style.width = '100%';
            div.style.height = '100%';
            div.style.borderRadius = '50%';
            div.style.background = 'linear-gradient(45deg, #6C40C5, #FF4D8D)';
            div.style.color = 'white';
            div.style.display = 'flex';
            div.style.alignItems = 'center';
            div.style.justifyContent = 'center';
            div.style.fontWeight = 'bold';
            div.style.fontSize = '0.9rem';
        });
    });
    
    // Fix user avatar in header
    const userAvatar = document.querySelector('.avatar');
    if (userAvatar && userAvatar.tagName === 'IMG') {
        userAvatar.style.width = '50px';
        userAvatar.style.height = '50px';
        userAvatar.style.minWidth = '50px';
        userAvatar.style.minHeight = '50px';
        userAvatar.style.borderRadius = '50%';
        userAvatar.style.objectFit = 'cover';
        userAvatar.style.border = '2px solid #6C40C5';
        
        userAvatar.onerror = function() {
            this.style.display = 'none';
            const name = this.alt || 'User';
            const initials = getInitialsFromName(name);
            
            const initialsDiv = document.createElement('div');
            initialsDiv.textContent = initials;
            initialsDiv.style.cssText = `
                width: 50px;
                height: 50px;
                min-width: 50px;
                min-height: 50px;
                border-radius: 50%;
                background: linear-gradient(45deg, #6C40C5, #FF4D8D);
                color: white;
                display: flex;
                align-items: center;
                justify-content: center;
                font-weight: bold;
                font-size: 1rem;
                border: 2px solid #6C40C5;
            `;
            this.parentNode.appendChild(initialsDiv);
        };
    }
}

// Get initials from name
function getInitialsFromName(name) {
    const parts = name.split(' ');
    return ((parts[0]?.[0] || '') + (parts[1]?.[0] || '')).toUpperCase();
}

// Modal Management
function initModals() {
    
    const modalTriggers = document.querySelectorAll('[data-modal]');
    const modalCloses = document.querySelectorAll('.close-modal');
    const modalOverlays = document.querySelectorAll('.modal-overlay');
    
    modalTriggers.forEach(trigger => {
        trigger.addEventListener('click', () => {
            const modalId = trigger.getAttribute('data-modal');
            openModal(modalId);
        });
    });
    
    modalCloses.forEach(close => {
        close.addEventListener('click', function() {
            const modal = this.closest('.modal-overlay');
            if (modal) {
                closeModal(modal.id);
            }
        });
    });
    
    modalOverlays.forEach(overlay => {
        overlay.addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal(this.id);
            }
        });
    });
    
    // Close modals with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const openModal = document.querySelector('.modal-overlay[style*="display: flex"]');
            if (openModal) {
                closeModal(openModal.id);
            }
        }
    });
    
}

// Bank Card Eye Toggle
function initEyeToggle() {
    const eyeToggle = document.querySelector('.eye-toggle');
    const cardNumber = document.querySelector('.card-number');
    const balanceAmounts = document.querySelectorAll('.balance-amount');
    
    if (eyeToggle && cardNumber) {
        eyeToggle.addEventListener('click', function() {
            const icon = this.querySelector('i');
            const isBlurred = cardNumber.classList.toggle('blurred');
            
            // Toggle icon
            icon.className = isBlurred ? 'fas fa-eye-slash' : 'fas fa-eye';
            
            // Toggle balance visibility
            balanceAmounts.forEach(amount => {
                if (isBlurred) {
                    amount.dataset.original = amount.textContent;
                    amount.textContent = amount.textContent.replace(/[0-9]/g, '•');
                } else {
                    amount.textContent = amount.dataset.original || amount.textContent;
                }
            });
        });
    }
}

// Quick Actions Handlers
function initQuickActions() {
    
    // Use event delegation for action buttons
    document.addEventListener('click', function(e) {
        const actionBtn = e.target.closest('.action-btn');
        if (actionBtn) {
            e.preventDefault();
            const action = actionBtn.getAttribute('data-action');
            
            switch(action) {
                case 'send':
                    openModal('sendModal');
                    setTimeout(initRecipientSearch, 100);
                    break;
                case 'receive':
                    openModal('receiveModal');
                    break;
                case 'withdraw':
                    openModal('withdrawModal');
                    break;
                case 'deposit':
                    showToast('Deposit feature coming soon!', 'info');
                    break;
                case 'exchange':
                    showToast('Exchange feature coming soon!', 'info');
                    break;
            }
        }
    });
    
    // Send Money Form
    document.addEventListener('submit', function(e) {
        if (e.target.id === 'sendForm') {
            e.preventDefault();
            sendMoney();
        }
        
        if (e.target.id === 'withdrawForm') {
            e.preventDefault();
            submitWithdrawal();
        }
    });
    
}

// Initialize recipient search functionality
function initRecipientSearch() {
    
    const recipientInput = document.getElementById('recipient');
    const recipientInfo = document.getElementById('recipientInfo');
    
    if (!recipientInput) {
        return;
    }
    
    
    // Clear any previous value
    recipientInput.value = '';
    
    let searchTimeout;
    
    // Setup event listeners
    recipientInput.addEventListener('input', function(e) {
        clearTimeout(searchTimeout);
        
        const value = e.target.value.trim();
        
        if (value.length < 3) {
            hideRecipientInfo();
            return;
        }
        
        searchTimeout = setTimeout(async () => {
            await validateRecipient(value);
        }, 500);
    });
    
    // Also validate on blur
    recipientInput.addEventListener('blur', function() {
        setTimeout(async () => {
            const value = this.value.trim();
            if (value.length >= 3) {
                await validateRecipient(value);
            }
        }, 200);
    });
    
}

// Validate recipient and show info
async function validateRecipient(recipient) {
    
    try {
        showLoading();
        
        const response = await fetch(`api/transaction.php?action=validateRecipient&recipient=${encodeURIComponent(recipient)}`);
        const result = await response.json();
        
        
        if (result.success) {
            window.currentRecipient = result.user;
            showRecipientInfo(result.user);
        } else {
            window.currentRecipient = null;
            hideRecipientInfo();
            showToast(result.message, 'error');
        }
    } catch (error) {
        console.error('Recipient validation error:', error);
        window.currentRecipient = null;
        hideRecipientInfo();
        showToast('Network error. Please try again.', 'error');
    } finally {
        hideLoading();
    }
}

// Show recipient info
function showRecipientInfo(user) {
    
    const infoContainer = document.getElementById('recipientInfo');
    if (!infoContainer) {
        console.error('Recipient info container not found!');
        return;
    }
    
    const hasAvatar = user.avatar && !user.avatar.includes('default-avatar.png');
    const initials = getInitials(user.first_name, user.last_name);
    
    infoContainer.innerHTML = `
        <div style="display: flex; align-items: center; gap: 12px; padding: 12px; background: rgba(108, 64, 197, 0.1); border-radius: 12px; border: 1px solid rgba(108, 64, 197, 0.3); margin-top: 10px; animation: fadeInUp 0.3s ease-out;">
            <div style="width: 50px; height: 50px; min-width: 50px; border-radius: 50%; overflow: hidden; flex-shrink: 0;">
                ${hasAvatar 
                    ? `<img src="${user.avatar}" alt="${user.full_name}" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">`
                    : `<div style="width: 100%; height: 100%; border-radius: 50%; background: linear-gradient(45deg, #6C40C5, #FF4D8D); color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 1rem;">${initials}</div>`
                }
            </div>
            <div style="flex: 1;">
                <div style="font-weight: 600; font-size: 1rem; color: white;">${user.full_name}</div>
                <div style="font-size: 0.85rem; color: #B8B8D1; margin-top: 4px; display: flex; gap: 8px; flex-wrap: wrap;">
                    <span style="background: rgba(255, 255, 255, 0.1); padding: 4px 10px; border-radius: 6px;">
                        <i class="fas fa-credit-card"></i> ${user.account_number}
                    </span>
                    ${user.telegram_id 
                        ? `<span style="background: rgba(255, 255, 255, 0.1); padding: 4px 10px; border-radius: 6px;">
                            <i class="fab fa-telegram"></i> ${user.telegram_id}
                          </span>`
                        : ''
                    }
                </div>
            </div>
            <div style="color: #4CD964;">
                <i class="fas fa-check-circle" style="font-size: 1.2rem;"></i>
            </div>
        </div>
    `;
    infoContainer.style.display = 'block';
}

// Hide recipient info
function hideRecipientInfo() {
    const infoContainer = document.getElementById('recipientInfo');
    if (infoContainer) {
        infoContainer.innerHTML = '';
        infoContainer.style.display = 'none';
    }
}

// Get initials from name
function getInitials(firstName, lastName) {
    return ((firstName?.[0] || '') + (lastName?.[0] || '')).toUpperCase();
}

// Send Money Function
async function sendMoney() {
    
    const form = document.getElementById('sendForm');
    if (!form) {
        console.error('Send form not found');
        return;
    }
    
    const formData = new FormData(form);
    const amount = parseFloat(formData.get('amount'));
    const recipient = formData.get('recipient');
    const description = formData.get('description') || 'Transaction';
    const currency = formData.get('currency');

    // Validation
    if (!amount || amount <= 0) {
        showToast('Please enter a valid amount', 'error');
        return;
    }

    if (!recipient) {
        showToast('Please enter recipient account number or Telegram ID', 'error');
        return;
    }

    try {
        showLoading();
        
        const response = await fetch('api/transaction.php?action=send', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                amount: amount,
                recipient: recipient,
                description: description,
                currency: currency
            })
        });
        
        const result = await response.json();
        
        
        if (result.success) {
            showToast('Money sent successfully!', 'success');
            closeModal('sendModal');
            
            // Refresh dashboard data
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            showToast(result.message || 'Failed to send money', 'error');
        }
    } catch (error) {
        console.error('Send money error:', error);
        showToast('Network error. Please try again.', 'error');
    } finally {
        hideLoading();
    }
}

// Submit Withdrawal Function
async function submitWithdrawal() {
    
    const form = document.getElementById('withdrawForm');
    if (!form) {
        console.error('Withdraw form not found');
        return;
    }
    
    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());
    
    // Validation
    if (!data.amount || !data.currency || !data.iban_number || !data.bank_name || !data.recipient_name) {
        showToast('Please fill all required fields', 'error');
        return;
    }
    
    if (parseFloat(data.amount) <= 0) {
        showToast('Amount must be greater than 0', 'error');
        return;
    }
    
    try {
        showLoading();
        
        const response = await fetch('api/withdraw.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (result.success) {
            showToast('Withdrawal request submitted!', 'success');
            closeModal('withdrawModal');
            
            // Refresh balance
            if (typeof loadUserData === 'function') {
                loadUserData();
            }
        } else {
            showToast(result.message || 'Failed to submit withdrawal', 'error');
        }
    } catch (error) {
        showToast('Network error. Please try again.', 'error');
        console.error('Withdrawal error:', error);
    } finally {
        hideLoading();
    }
}

// Navigation
function initNavigation() {
    
    const navItems = document.querySelectorAll('.nav-item');
    
    navItems.forEach(item => {
        item.addEventListener('click', function(e) {
            e.preventDefault();
            
            
            // Remove active class from all items
            navItems.forEach(nav => nav.classList.remove('active'));
            
            // Add active class to clicked item
            this.classList.add('active');
            
            // Get target page
            const target = this.getAttribute('data-page');
            if (target) {
                navigateTo(target);
            }
        });
    });
    
}

function navigateTo(page) {
    
    switch(page) {
        case 'dashboard':
            window.location.href = 'dashboard.php';
            break;
        case 'transactions':
            window.location.href = 'transactions.php';
            break;
        case 'profile':
            window.location.href = 'profile.php';
            break;
        case 'cards':
            showToast('Cards feature coming soon!', 'info');
            break;
        case 'more':
            showToast('More features coming soon!', 'info');
            break;
        default:
            if (page && page.includes('.php')) {
                window.location.href = page;
            }
            break;
    }
}

// Load User Data
async function loadUserData() {
    
    try {
        const response = await fetch('api/user.php?action=getData');
        const data = await response.json();
        
        if (data.success) {
            updateDashboard(data.user);
        }
    } catch (error) {
        console.error('Failed to load user data:', error);
    }
}

// Load Recent Transactions
async function loadRecentTransactions() {
    
    try {
        const response = await fetch('api/transaction.php?action=recent');
        const data = await response.json();
        
        if (data.success) {
            updateTransactions(data.transactions);
        }
    } catch (error) {
        console.error('Failed to load transactions:', error);
    }
}

// Update Dashboard UI
function updateDashboard(user) {
    
    // Update greeting
    const greeting = document.querySelector('.greeting h1');
    if (greeting) {
        greeting.textContent = `Welcome, ${user.first_name}`;
    }
    
    // Update card number
    const cardNumber = document.querySelector('.card-number');
    if (cardNumber) {
        cardNumber.textContent = formatCardNumber(user.account_number);
    }
    
    // Update cardholder name
    const cardholder = document.querySelector('.cardholder-name');
    if (cardholder) {
        cardholder.textContent = `${user.first_name} ${user.last_name}`;
    }
    
    // Update balances
    const balances = {
        'USD': user.balance_usd,
        'EUR': user.balance_eur,
        'USDT': user.balance_usdt,
        'IRR': user.balance_irr
    };
    
    Object.keys(balances).forEach(currency => {
        const element = document.querySelector(`[data-currency="${currency}"] .balance-amount`);
        if (element) {
            element.textContent = formatCurrency(balances[currency], currency);
            element.dataset.original = formatCurrency(balances[currency], currency);
        }
    });
    
    // Update avatar
    const avatar = document.querySelector('.avatar');
    if (avatar && user.avatar) {
        avatar.src = user.avatar;
        avatar.onerror = function() {
            this.style.display = 'none';
            const initialsDiv = document.createElement('div');
            initialsDiv.textContent = getInitials(user.first_name, user.last_name);
            initialsDiv.style.cssText = `
                width: 50px;
                height: 50px;
                min-width: 50px;
                min-height: 50px;
                border-radius: 50%;
                background: linear-gradient(45deg, #6C40C5, #FF4D8D);
                color: white;
                display: flex;
                align-items: center;
                justify-content: center;
                font-weight: bold;
                font-size: 1rem;
                border: 2px solid #6C40C5;
            `;
            this.parentNode.appendChild(initialsDiv);
        };
    }
}

// Update Transactions UI
function updateTransactions(transactions) {
    
    const container = document.querySelector('.transactions-list');
    if (!container) return;
    
    container.innerHTML = '';
    
    if (transactions.length === 0) {
        container.innerHTML = `
            <div class="transaction-item" style="text-align: center; padding: 30px; color: #B8B8D1;">
                <i class="fas fa-exchange-alt" style="font-size: 2rem; margin-bottom: 10px;"></i>
                <div>No transactions yet</div>
            </div>
        `;
        return;
    }
    
    transactions.forEach(transaction => {
        const transactionElement = createTransactionElement(transaction);
        container.appendChild(transactionElement);
    });
    
    // Fix avatar sizes after loading
    setTimeout(fixAvatars, 100);
}

// Create Transaction Element
function createTransactionElement(transaction) {
    const div = document.createElement('div');
    div.className = 'transaction-item';
    
    const isSent = transaction.transaction_type === 'sent' || transaction.type === 'send';
    const amountClass = isSent ? 'negative' : 'positive';
    const prefix = isSent ? '-' : '+';
    const contactName = transaction.contact_name || 'Unknown';
    const avatar = transaction.contact_avatar || transaction.avatar;
    const initials = getInitialsFromName(contactName);
    const hasAvatar = avatar && !avatar.includes('default-avatar.png');
    
    div.innerHTML = `
        <div class="transaction-avatar">
            ${hasAvatar 
                ? `<img src="${avatar}" alt="${contactName}" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                   <div class="avatar-initials" style="display: none;">${initials}</div>`
                : `<div class="avatar-initials">${initials}</div>`
            }
        </div>
        <div class="transaction-details">
            <div class="transaction-name">${contactName}</div>
            <div class="transaction-description">${transaction.description || 'Transaction'}</div>
            <div class="transaction-date">${formatDate(transaction.created_at)}</div>
        </div>
        <div class="transaction-amount ${amountClass}">
            ${prefix}${formatCurrency(transaction.amount, transaction.currency)}
        </div>
    `;
    
    return div;
}

// Utility Functions
function openModal(modalId) {
    
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(modalId) {
    
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
        
        // Reset form
        const form = modal.querySelector('form');
        if (form) form.reset();
        
        // Clear recipient info if it's send modal
        if (modalId === 'sendModal') {
            hideRecipientInfo();
            window.currentRecipient = null;
        }
    }
}

function formatCardNumber(number) {
    if (!number) return 'AV55 23 **** ****';
    const str = number.toString();
    if (str.length >= 12) {
        return str.replace(/(\d{4})(\d{4})(\d{4})/, '$1 $2 $3');
    }
    return str;
}

function formatCurrency(amount, currency) {
    if (!amount) amount = 0;
    
    const formatter = new Intl.NumberFormat('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: currency === 'USDT' ? 6 : 2
    });
    
    const formatted = formatter.format(amount);
    
    switch(currency) {
        case 'USD':
            return '$' + formatted;
        case 'EUR':
            return '€' + formatted;
        case 'USDT':
            return formatted + ' USDT';
        case 'IRR':
            return '﷼ ' + formatted;
        default:
            return formatted + ' ' + currency;
    }
}

function formatDate(dateString) {
    if (!dateString) return '';
    
    const date = new Date(dateString);
    const now = new Date();
    const diffMs = now - date;
    const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));
    
    if (diffDays === 0) {
        return 'Today';
    } else if (diffDays === 1) {
        return 'Yesterday';
    } else if (diffDays < 7) {
        return `${diffDays} days ago`;
    } else {
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    }
}

function showToast(message, type = 'info') {
    
    // Remove existing toasts
    const existingToasts = document.querySelectorAll('.toast');
    existingToasts.forEach(toast => toast.remove());
    
    // Create new toast
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.textContent = message;
    toast.style.cssText = `
        position: fixed;
        bottom: 100px;
        left: 50%;
        transform: translateX(-50%) translateY(100px);
        background: rgba(255, 255, 255, 0.05);
        backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 10px;
        padding: 15px 20px;
        color: white;
        font-weight: 500;
        z-index: 1000;
        opacity: 0;
        transition: transform 0.3s, opacity 0.3s;
        max-width: 90%;
        text-align: center;
    `;
    
    if (type === 'success') {
        toast.style.borderLeft = '4px solid #4CD964';
    } else if (type === 'error') {
        toast.style.borderLeft = '4px solid #FF3B30';
    } else {
        toast.style.borderLeft = '4px solid #6C40C5';
    }
    
    document.body.appendChild(toast);
    
    // Show toast
    setTimeout(() => {
        toast.style.transform = 'translateX(-50%) translateY(0)';
        toast.style.opacity = '1';
    }, 10);
    
    // Remove after 3 seconds
    setTimeout(() => {
        toast.style.transform = 'translateX(-50%) translateY(100px)';
        toast.style.opacity = '0';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

function showLoading() {
    
    let spinner = document.getElementById('global-spinner');
    if (!spinner) {
        spinner = document.createElement('div');
        spinner.id = 'global-spinner';
        spinner.style.cssText = `
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 40px;
            height: 40px;
            border: 3px solid rgba(255, 255, 255, 0.1);
            border-top: 3px solid #6C40C5;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            z-index: 9999;
        `;
        document.body.appendChild(spinner);
        
        // Add spin animation if not exists
        if (!document.querySelector('#spin-animation')) {
            const style = document.createElement('style');
            style.id = 'spin-animation';
            style.textContent = `
                @keyframes spin {
                    0% { transform: translate(-50%, -50%) rotate(0deg); }
                    100% { transform: translate(-50%, -50%) rotate(360deg); }
                }
            `;
            document.head.appendChild(style);
        }
    }
    spinner.style.display = 'block';
}

function hideLoading() {
    
    const spinner = document.getElementById('global-spinner');
    if (spinner) {
        spinner.style.display = 'none';
    }
}

// PWA Support
function initPWA() {
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/ledor/sw.js').then(
                registration => {
                },
                error => {
                }
            );
        });
    }
}

// Global variables initialization
if (typeof window.currentUserId === 'undefined') {
    window.currentUserId = null;
}

if (typeof window.currentRecipient === 'undefined') {
    window.currentRecipient = null;
}


// Add fadeInUp animation if not exists
if (!document.querySelector('#fadeInUp-animation')) {
    const style = document.createElement('style');
    style.id = 'fadeInUp-animation';
    style.textContent = `
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    `;
    document.head.appendChild(style);
}