// assets/js/friends-manager-final.js
class FriendsManager {
    constructor() {
        this.apiUrl = '/ledor/api/friends-api.php';
        this.containerId = 'friendsAvatarContainer';
        this.currentUserId = window.currentUserId || null;
        this.init();
    }

    async init() {
        await this.loadFriendsAvatars();
        this.addEventListeners();
    }

    addEventListeners() {
        // Listen for friend avatar clicks
        document.addEventListener('click', (e) => {
            const avatarCircle = e.target.closest('.friend-avatar-circle');
            if (avatarCircle && !avatarCircle.classList.contains('view-all-btn')) {
                const friendId = avatarCircle.getAttribute('data-friend-id');
                const friendName = avatarCircle.querySelector('.friend-avatar-name')?.textContent || 'Friend';
                this.showFriendDetails(friendId, friendName);
            }
        });
    }

    async loadFriendsAvatars() {
        try {
            const container = document.getElementById(this.containerId);
            if (!container) {
                console.error('Friends container not found');
                return;
            }

            // Show loading state
            container.innerHTML = `
                <div class="loading-friends">
                    <i class="fas fa-spinner fa-spin"></i> Loading friends...
                </div>
            `;

            const response = await fetch(`${this.apiUrl}?action=get_recent_friends&limit=10`);
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const data = await response.json();

            if (data.status === 'success' && data.data && data.data.length > 0) {
                this.renderAvatarCircles(data.data);
            } else {
                this.showNoFriends(data.message || 'No friends found');
            }
        } catch (error) {
            console.error('Error loading friends:', error);
            this.showError('Failed to load friends: ' + error.message);
        }
    }

    renderAvatarCircles(friends) {
        const container = document.getElementById(this.containerId);
        if (!container) return;

        container.innerHTML = '';
        
        friends.forEach(friend => {
            const avatarElement = this.createAvatarElement(friend);
            container.appendChild(avatarElement);
        });

        // Add view all button
        if (friends.length > 0) {
            const viewAllBtn = this.createViewAllButton();
            container.appendChild(viewAllBtn);
        }
        
    }

    createAvatarElement(friend) {
        const div = document.createElement('div');
        div.className = 'friend-avatar-circle';
        div.setAttribute('data-friend-id', friend.id);
        div.setAttribute('title', `Click to view transactions with ${friend.full_name}`);
        
        // Calculate total amount
        const totalSent = parseFloat(friend.total_sent) || 0;
        const totalReceived = parseFloat(friend.total_received) || 0;
        const netAmount = totalReceived - totalSent;
        const amountClass = netAmount >= 0 ? 'positive' : 'negative';
        const amountSign = netAmount >= 0 ? '+' : '';
        
        // Create avatar
        let avatarContent = '';
        const avatarUrl = friend.avatar || '';
        const friendName = friend.full_name || 'Unknown';
        
        if (avatarUrl && !avatarUrl.includes('default-avatar')) {
            avatarContent = `
                <img src="${avatarUrl}" 
                     alt="${friendName}" 
                     class="friend-avatar-img"
                     onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='flex';">
                <div class="friend-avatar-initials" style="display: none;">
                    ${this.getInitials(friendName)}
                </div>
            `;
        } else {
            avatarContent = `
                <div class="friend-avatar-initials">
                    ${this.getInitials(friendName)}
                </div>
            `;
        }
        
        // Format amount for badge
        let amountBadge = '';
        if (friend.transaction_count > 0) {
            amountBadge = `
                <div class="friend-transaction-info ${amountClass}" 
                     title="Net balance with ${friendName}">
                    ${amountSign}$${Math.abs(netAmount).toFixed(2)}
                </div>
            `;
        }
        
        div.innerHTML = `
            ${avatarContent}
            ${amountBadge}
            <div class="friend-avatar-name">${this.getShortName(friendName)}</div>
            ${friend.transaction_count > 0 ? 
                `<div class="friend-transaction-count">${friend.transaction_count} TX</div>` : 
                ''
            }
        `;

        return div;
    }

    createViewAllButton() {
        const div = document.createElement('div');
        div.className = 'friend-avatar-circle view-all-btn';
        div.setAttribute('title', 'View all friends and transactions');
        
        div.innerHTML = `
            <div class="view-all-circle">
                <i class="fas fa-ellipsis-h"></i>
            </div>
            <div class="friend-avatar-name">View All</div>
        `;

        div.addEventListener('click', (e) => {
            e.stopPropagation();
            window.location.href = 'all_friends.php';
        });

        return div;
    }

    async showFriendDetails(friendId, friendName) {
        
        try {
            // Show loading modal first
            this.showLoadingModal(friendName);
            
            const url = `${this.apiUrl}?action=get_friend_details&friend_id=${friendId}`;
            
            const response = await fetch(url);
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const data = await response.json();
            
            if (data.status === 'success') {
                this.renderFriendDetailsModal(data);
            } else {
                throw new Error(data.message || 'Failed to load friend details');
            }
        } catch (error) {
            console.error('Error loading friend details:', error);
            this.showErrorModal(friendName, error.message);
        }
    }

    showLoadingModal(friendName) {
        // Remove existing modal
        this.removeExistingModal();
        
        const modalHTML = `
            <div class="friend-info-modal" id="friendDetailsModal">
                <div class="friend-info-content">
                    <div class="friend-modal-header">
                        <div class="friend-modal-initials">
                            ${this.getInitials(friendName)}
                        </div>
                        <div class="friend-modal-details">
                            <h3>${friendName}</h3>
                            <p>Loading transaction history...</p>
                        </div>
                    </div>
                    <div style="text-align: center; padding: 40px;">
                        <i class="fas fa-spinner fa-spin fa-2x" style="color: var(--accent-purple);"></i>
                        <p style="margin-top: 15px; color: var(--text-gray);">
                            Loading transaction details...
                        </p>
                    </div>
                </div>
            </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', modalHTML);
    }

    renderFriendDetailsModal(data) {
        this.removeExistingModal();
        
        const { friend, stats, transactions = [], user_stats = {} } = data;
        const friendName = friend.full_name || 'Unknown';
        
        // Calculate percentages
        const totalUserTransactions = user_stats.total_transactions || 1;
        const transactionPercentage = stats.transaction_count > 0 ? 
            ((stats.transaction_count / totalUserTransactions) * 100).toFixed(1) : 0;
        
        // Create modal HTML
        const modalHTML = `
            <div class="friend-info-modal" id="friendDetailsModal">
                <div class="friend-info-content">
                    <button class="close-modal-btn" onclick="window.friendsManager.closeModal()">
                        &times;
                    </button>
                    
                    <!-- Header -->
                    <div class="friend-modal-header">
                        ${friend.avatar && !friend.avatar.includes('default-avatar') 
                            ? `<img src="${friend.avatar}" alt="${friendName}" class="friend-modal-avatar">`
                            : `<div class="friend-modal-initials">
                                ${this.getInitials(friendName)}
                              </div>`
                        }
                        <div class="friend-modal-details">
                            <h3>${friendName}</h3>
                            <p><i class="fas fa-credit-card"></i> ${friend.account_number || 'N/A'}</p>
                            ${friend.telegram_id ? `<p><i class="fab fa-telegram"></i> ${friend.telegram_id}</p>` : ''}
                            ${friend.phone_number ? `<p><i class="fas fa-phone"></i> ${friend.phone_number}</p>` : ''}
                            <p><i class="fas fa-calendar"></i> Member since: ${new Date(friend.created_at).toLocaleDateString()}</p>
                        </div>
                    </div>
                    
                    <!-- Quick Stats -->
                    <div class="quick-stats" style="
                        display: grid;
                        grid-template-columns: repeat(2, 1fr);
                        gap: 10px;
                        margin: 20px 0;
                    ">
                        <div class="stat-box" style="
                            background: rgba(76, 217, 100, 0.1);
                            border: 1px solid rgba(76, 217, 100, 0.3);
                            border-radius: 10px;
                            padding: 15px;
                            text-align: center;
                        ">
                            <div style="font-size: 0.9rem; color: var(--text-gray);">Received</div>
                            <div style="font-size: 1.3rem; font-weight: bold; color: #4CD964;">
                                +$${stats.total_received ? parseFloat(stats.total_received).toFixed(2) : '0.00'}
                            </div>
                        </div>
                        <div class="stat-box" style="
                            background: rgba(255, 59, 48, 0.1);
                            border: 1px solid rgba(255, 59, 48, 0.3);
                            border-radius: 10px;
                            padding: 15px;
                            text-align: center;
                        ">
                            <div style="font-size: 0.9rem; color: var(--text-gray);">Sent</div>
                            <div style="font-size: 1.3rem; font-weight: bold; color: #FF3B30;">
                                -$${stats.total_sent ? parseFloat(stats.total_sent).toFixed(2) : '0.00'}
                            </div>
                        </div>
                    </div>
                    
                    <!-- Net Balance -->
                    <div class="net-balance" style="
                        background: var(--glass-bg);
                        border-radius: 15px;
                        padding: 20px;
                        margin: 20px 0;
                        text-align: center;
                        border: 1px solid var(--glass-border);
                    ">
                        <div style="font-size: 0.9rem; color: var(--text-gray); margin-bottom: 5px;">
                            Net Balance with ${friend.first_name}
                        </div>
                        <div style="font-size: 2rem; font-weight: bold; 
                            color: ${stats.net_balance >= 0 ? '#4CD964' : '#FF3B30'};">
                            ${stats.net_balance >= 0 ? '+' : ''}$${Math.abs(stats.net_balance || 0).toFixed(2)}
                        </div>
                        <div style="font-size: 0.8rem; color: var(--text-gray); margin-top: 5px;">
                            ${stats.transaction_count || 0} transactions • 
                            ${transactionPercentage}% of your total transactions
                        </div>
                    </div>
                    
                    <!-- Transaction History -->
                    <div class="transaction-history">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                            <h4><i class="fas fa-exchange-alt"></i> Transaction History</h4>
                            <span style="font-size: 0.9rem; color: var(--text-gray);">
                                ${transactions.length} transactions
                            </span>
                        </div>
                        
                        ${transactions.length > 0 
                            ? `<div class="transactions-list" style="max-height: 300px; overflow-y: auto;">
                                ${transactions.map(tx => this.createTransactionItem(tx)).join('')}
                               </div>`
                            : `<div class="no-transactions" style="
                                    text-align: center;
                                    padding: 40px 20px;
                                    color: var(--text-gray);
                                ">
                                    <i class="fas fa-exchange-alt" style="font-size: 2rem; margin-bottom: 10px;"></i>
                                    <p>No transactions found with ${friend.first_name}</p>
                                </div>`
                        }
                        
                        ${stats.first_transaction 
                            ? `<div style="
                                    font-size: 0.8rem;
                                    color: var(--text-gray);
                                    margin-top: 15px;
                                    text-align: center;
                                ">
                                <i class="fas fa-history"></i> 
                                First transaction: ${new Date(stats.first_transaction).toLocaleDateString()}
                            </div>`
                            : ''
                        }
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="friend-actions" style="
                        display: flex;
                        gap: 10px;
                        margin-top: 25px;
                    ">
                        <button class="btn" style="flex: 1;" 
                                onclick="window.location.href='send_money.php?to=${friend.id}'">
                            <i class="fas fa-paper-plane"></i> Send Money
                        </button>
                        <button class="btn-secondary" style="flex: 1;"
                                onclick="window.location.href='transactions.php?filter=friend&id=${friend.id}'">
                            <i class="fas fa-list"></i> All Transactions
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', modalHTML);
        this.addModalEventListeners();
    }

    createTransactionItem(transaction) {
        const isSent = transaction.sender_id == this.currentUserId;
        const amountClass = isSent ? 'negative' : 'positive';
        const amountSign = isSent ? '-' : '+';
        const icon = isSent ? 'arrow-up' : 'arrow-down';
        const direction = isSent ? 'to' : 'from';
        
        return `
            <div class="transaction-item-detailed" style="
                display: flex;
                align-items: center;
                padding: 12px;
                background: rgba(255, 255, 255, 0.05);
                border-radius: 10px;
                margin-bottom: 10px;
                border-left: 4px solid ${isSent ? '#FF3B30' : '#4CD964'};
            ">
                <div style="
                    width: 40px;
                    height: 40px;
                    border-radius: 50%;
                    background: ${isSent ? 'rgba(255, 59, 48, 0.1)' : 'rgba(76, 217, 100, 0.1)'};
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    margin-right: 12px;
                    color: ${isSent ? '#FF3B30' : '#4CD964'};
                ">
                    <i class="fas fa-${icon}"></i>
                </div>
                <div style="flex: 1;">
                    <div style="font-weight: 500; font-size: 0.95rem;">
                        ${transaction.description || `${direction} ${transaction.direction || 'Transaction'}`}
                    </div>
                    <div style="font-size: 0.8rem; color: var(--text-gray); display: flex; gap: 15px; margin-top: 3px;">
                        <span><i class="far fa-calendar"></i> ${transaction.formatted_date}</span>
                        <span><i class="far fa-clock"></i> ${transaction.formatted_time}</span>
                        <span style="
                            background: ${transaction.status === 'completed' ? 'rgba(76, 217, 100, 0.1)' : 
                                      transaction.status === 'pending' ? 'rgba(255, 193, 7, 0.1)' : 
                                      'rgba(255, 59, 48, 0.1)'};
                            color: ${transaction.status === 'completed' ? '#4CD964' : 
                                   transaction.status === 'pending' ? '#FFC107' : 
                                   '#FF3B30'};
                            padding: 2px 8px;
                            border-radius: 10px;
                            font-size: 0.75rem;
                        ">
                            ${transaction.status || 'completed'}
                        </span>
                    </div>
                </div>
                <div style="
                    font-weight: bold;
                    font-size: 1.1rem;
                    color: ${isSent ? '#FF3B30' : '#4CD964'};
                ">
                    ${amountSign}$${Math.abs(parseFloat(transaction.amount)).toFixed(2)} 
                    <span style="font-size: 0.8rem; color: var(--text-gray);">${transaction.currency || 'USD'}</span>
                </div>
            </div>
        `;
    }

    showErrorModal(friendName, errorMessage) {
        this.removeExistingModal();
        
        const modalHTML = `
            <div class="friend-info-modal" id="friendDetailsModal">
                <div class="friend-info-content">
                    <button class="close-modal-btn" onclick="window.friendsManager.closeModal()">
                        &times;
                    </button>
                    
                    <div class="friend-modal-header">
                        <div class="friend-modal-initials">
                            ${this.getInitials(friendName)}
                        </div>
                        <div class="friend-modal-details">
                            <h3>${friendName}</h3>
                            <p style="color: #FF3B30;"><i class="fas fa-exclamation-triangle"></i> Error Loading Details</p>
                        </div>
                    </div>
                    
                    <div style="text-align: center; padding: 40px 20px; color: var(--text-gray);">
                        <i class="fas fa-exclamation-circle" style="
                            font-size: 3rem;
                            margin-bottom: 15px;
                            color: #FF3B30;
                        "></i>
                        <h4 style="color: var(--text-light); margin-bottom: 10px;">Unable to Load Transactions</h4>
                        <p style="margin-bottom: 20px;">${errorMessage}</p>
                        <div style="display: flex; gap: 10px; justify-content: center;">
                            <button class="btn-secondary" onclick="window.friendsManager.closeModal()">
                                <i class="fas fa-times"></i> Close
                            </button>
                            <button class="btn" onclick="window.location.reload()">
                                <i class="fas fa-redo"></i> Retry
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', modalHTML);
        this.addModalEventListeners();
    }

    addModalEventListeners() {
        const modal = document.getElementById('friendDetailsModal');
        if (!modal) return;
        
        // Close on escape
        const escapeHandler = (e) => {
            if (e.key === 'Escape') {
                this.closeModal();
            }
        };
        
        // Close on outside click
        const clickHandler = (e) => {
            if (e.target === modal) {
                this.closeModal();
            }
        };
        
        modal.addEventListener('click', clickHandler);
        document.addEventListener('keydown', escapeHandler);
        
        // Store handlers for cleanup
        modal._escapeHandler = escapeHandler;
        modal._clickHandler = clickHandler;
    }

    closeModal() {
        const modal = document.getElementById('friendDetailsModal');
        if (modal) {
            // Remove event listeners
            if (modal._escapeHandler) {
                document.removeEventListener('keydown', modal._escapeHandler);
            }
            if (modal._clickHandler) {
                modal.removeEventListener('click', modal._clickHandler);
            }
            
            // Remove modal
            modal.remove();
        }
    }

    removeExistingModal() {
        this.closeModal();
    }

    showNoFriends(message) {
        const container = document.getElementById(this.containerId);
        if (!container) return;

        container.innerHTML = `
            <div class="no-friends">
                <i class="fas fa-user-friends"></i>
                <h3>No Friends Yet</h3>
                <p>${message || 'Start sending money to see friends here'}</p>
                <button class="btn" onclick="openModal('sendModal')">
                    <i class="fas fa-paper-plane"></i> Send Your First Payment
                </button>
            </div>
        `;
    }

    showError(message) {
        const container = document.getElementById(this.containerId);
        if (!container) return;

        container.innerHTML = `
            <div class="friends-error">
                <i class="fas fa-exclamation-triangle"></i>
                <h3>Error Loading Friends</h3>
                <p>${message}</p>
                <button class="btn-secondary" onclick="window.friendsManager.loadFriendsAvatars()">
                    <i class="fas fa-redo"></i> Try Again
                </button>
            </div>
        `;
    }

    getInitials(name) {
        if (!name) return '??';
        const parts = name.split(' ');
        return ((parts[0]?.[0] || '') + (parts[1]?.[0] || '')).toUpperCase();
    }

    getShortName(fullName) {
        if (!fullName) return 'User';
        const parts = fullName.split(' ');
        if (parts.length === 1) return parts[0];
        return parts[0] + ' ' + parts[1][0] + '.';
    }
}

// Initialize on DOM ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        if (document.getElementById('friendsAvatarContainer')) {
            window.friendsManager = new FriendsManager();
        }
    });
} else {
    if (document.getElementById('friendsAvatarContainer')) {
        window.friendsManager = new FriendsManager();
    }
}

// Global helper functions
window.sendMoneyToFriend = function(friendId) {
    window.location.href = `send_money.php?to=${friendId}`;
};

window.viewFriendTransactions = function(friendId) {
    window.location.href = `transactions.php?friend=${friendId}`;
};


