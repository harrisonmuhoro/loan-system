/* ===========================
🌐 CONFIGURATION & GLOBALS
=========================== */

const API_BASE_URL = getApiBaseUrl();

// DOM Elements Cache
const elements = {
    mainHeader: null,
    sidebar: null,
    main: null,
    loadingOverlay: null,
    toastContainer: null,
    statusMessage: null,
    themeToggle: null,
    staffForm: null,
    addFundForm: null,
    updateIndicator: null,
    profileBadge: null,
    profileDropdown: null,
    menuToggle: null
};

// State Management
let resizeTimer;
let currentPage = 1;
const itemsPerPage = 10;
let editingUserId = null;
let currentTheme = 'light'; // Will be loaded from database
document.documentElement.setAttribute('data-theme', currentTheme);

// Cache for performance
let usersCache = null;
let usersCacheTime = 0;
const CACHE_DURATION = 30000; // 30 seconds

/* ===========================
       🔐 SECURITY & VALIDATION
       =========================== */

function escapeHtml(text) {
    const str = String(text || '');
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return str.replace(/[&<>"']/g, m => map[m]);
}

/**
 * Robust currency parser for Debtor Import
 * Handles strings with commas, "KES", and spaces correctly.
 */
function parseCurrencyAmount(amount) {
    if (!amount) return 0;

    // 1. If already a number, return it
    if (typeof amount === 'number') {
        return amount;
    }

    // 2. If string, clean and parse
    let clean = String(amount).toUpperCase();

    // Remove currency prefix
    if (clean.startsWith('KES')) {
        clean = clean.substring(3).trim();
    }

    // Remove commas and spaces
    clean = clean.replace(/[\s,]/g, '');

    const parsed = parseFloat(clean);

    // Return 0 if invalid, otherwise the number
    return isNaN(parsed) ? 0 : parsed;
}

function validateStaffInput(data) {
    const errors = [];

    if (!data.name || data.name.trim().length < 2) {
        errors.push("Name must be at least 2 characters");
    }

    if (!/^\d{9,11}$/.test(data.pfNumber)) {
        errors.push("PF Number must be 9-11 digits");
    }

    if (!data.email || data.email.trim().length < 5) {
        errors.push("Email is required");
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(data.email)) {
        errors.push("Please enter a valid email address");
    }

    if (!data.idNumber || data.idNumber.trim().length < 5) {
        errors.push("ID Number is required");
    }

    // Only validate password if provided (for new users or password changes)
    if (data.password !== undefined) {
        if (!data.password || data.password.length < 8) {
            errors.push("Password must be at least 8 characters");
        }

        const hasLetter = /[a-zA-Z]/.test(data.password);
        const hasNumber = /[0-9]/.test(data.password);

        if (!hasLetter || !hasNumber) {
            errors.push("Password must contain both letters and numbers (alphanumeric)");
        }
    }

    return errors;
}

function checkPasswordStrength(password) {
    const lengthReq = document.getElementById('req-length');
    const alphanumericReq = document.getElementById('req-alphanumeric');
    const strengthBar = document.getElementById('strengthBar');
    const saveBtn = document.getElementById('saveStaffBtn');

    let requirementsMet = 0;

    // Check length
    if (password.length >= 8) {
        lengthReq.classList.remove('requirement-unmet');
        lengthReq.classList.add('requirement-met');
        requirementsMet++;
    } else {
        lengthReq.classList.remove('requirement-met');
        lengthReq.classList.add('requirement-unmet');
    }

    // Check alphanumeric
    const hasLetter = /[a-zA-Z]/.test(password);
    const hasNumber = /[0-9]/.test(password);

    if (hasLetter && hasNumber) {
        alphanumericReq.classList.remove('requirement-unmet');
        alphanumericReq.classList.add('requirement-met');
        requirementsMet++;
    } else {
        alphanumericReq.classList.remove('requirement-met');
        alphanumericReq.classList.add('requirement-unmet');
    }

    // Update strength bar
    strengthBar.className = 'password-strength-bar';

    if (password.length === 0) {
        strengthBar.style.width = '0';
        // Only disable if we're adding a new user (not editing)
        if (saveBtn && !editingUserId) saveBtn.disabled = true;
    } else if (requirementsMet === 1) {
        strengthBar.classList.add('strength-weak');
        if (saveBtn) saveBtn.disabled = true;
    } else if (requirementsMet === 2) {
        const hasSpecial = /[!@#$%^&*(),.?":{}|<>]/.test(password);
        const hasUpper = /[A-Z]/.test(password);
        const hasLower = /[a-z]/.test(password);

        if (hasSpecial && hasUpper && hasLower) {
            strengthBar.classList.add('strength-strong');
        } else {
            strengthBar.classList.add('strength-medium');
        }

        // Enable button when password is strong enough
        if (saveBtn) saveBtn.disabled = false;
    }
}

/* ===========================
       <i data-lucide="coins" class="icon-sm"></i> UTILITY FUNCTIONS
       =========================== */

function formatStatNumber(value, elementId) {
    const element = document.getElementById(elementId);
    if (!element) return;

    const numValue = Number(value) || 0;
    const valueStr = numValue.toString();
    const digitCount = valueStr.length;

    // Remove any existing classes
    element.className = 'stat-number';

    // Apply appropriate class based on digit count
    if (digitCount <= 6) {
        element.classList.add('short-number');
    } else if (digitCount <= 9) {
        element.classList.add('medium-number');
    } else if (digitCount <= 12) {
        element.classList.add('long-number');
    } else if (digitCount <= 15) {
        element.classList.add('very-long-number');
    } else {
        element.classList.add('extra-long-number');
    }

    // For currency values, add proper formatting
    if (elementId === 'totalFunds') {
        element.innerHTML = `
    <span class="currency">KES</span>
    <span class="digit-group">${new Intl.NumberFormat('en-KE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }).format(numValue)}</span>
`;
        element.setAttribute('data-full-value', `KES ${numValue.toFixed(2)}`);
    } else {
        element.textContent = new Intl.NumberFormat('en-KE').format(numValue);
    }
}

function fmtKES(n) {
    n = Number(n) || 0;
    return 'KES ' + new Intl.NumberFormat('en-KE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }).format(n);
}

function showLoading(show = true) {
    if (elements.loadingOverlay) {
        elements.loadingOverlay.classList.toggle('hidden', !show);
    }
}

function showToast(message, type = 'success', duration = 3000) {
    if (!elements.toastContainer) return;

    const toast = document.createElement('div');
    toast.className = `toast ${type}`;

    const icon = {
        success: '<i data-lucide="check-circle" class="icon-sm"></i>',
        error: '<i data-lucide="x-circle" class="icon-sm"></i>',
        warning: '<i data-lucide="alert-triangle" class="icon-sm"></i>',
        info: 'ℹ️'
    } [type] || 'ℹ️';

    toast.innerHTML = `
    <span>${icon}</span>
    <span>${escapeHtml(message)}</span>
`;

    elements.toastContainer.appendChild(toast);

    setTimeout(() => {
        toast.style.animation = 'slideInRight 0.3s reverse';
        setTimeout(() => toast.remove(), 300);
    }, duration);

    if (elements.statusMessage) {
        elements.statusMessage.textContent = `${type}: ${message}`;
        setTimeout(() => {
            elements.statusMessage.textContent = '';
        }, 1000);
    }
}

function debounce(func, wait) {
    let timeout;
    return function(...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), wait);
    };
}

function exportToCSV(data, filename) {
    if (!data || data.length === 0) {
        showToast('No data to export', 'warning');
        return;
    }

    const headers = Object.keys(data[0]);
    const csvContent = [
        headers.join(','),
        ...data.map(row => headers.map(header => {
            const value = row[header] || '';
            return `"${String(value).replace(/"/g, '""')}"`;
        }).join(','))
    ].join('\n');

    const blob = new Blob([csvContent], {
        type: 'text/csv'
    });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);

    showToast('Data exported successfully', 'success');
}

function createModal(title, content) {
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.innerHTML = `
    <div class="modal-content">
        <div class="modal-header">
            <h3>${title}</h3>
            <button class="close-modal">&times;</button>
        </div>
        <div class="modal-body">
            ${content}
        </div>
    </div>
`;

    // Close modal handlers
    modal.querySelector('.close-modal').addEventListener('click', () => {
        modal.remove();
    });

    modal.addEventListener('click', (e) => {
        if (e.target === modal) {
            modal.remove();
        }
    });

    return modal;
}

/* ===========================
       <i data-lucide="clipboard-list" class="icon-sm"></i> NAVIGATION & UI CONTROL
       =========================== */

function showSection(name) {
    document.querySelectorAll('main section').forEach(s => s.classList.add('hidden'));
    const section = document.getElementById(name + 'Section');
    if (section) {
        section.classList.remove('hidden');
    }

    // Update active nav item
    document.querySelectorAll('#sidebar button').forEach(btn => {
        btn.classList.remove('active');
    });

    const activeNav = document.getElementById('nav' + name.charAt(0).toUpperCase() + name.slice(1));
    if (activeNav) {
        activeNav.classList.add('active');
    }

    // Close mobile menu
    if (window.innerWidth <= 760 && elements.sidebar) {
        elements.sidebar.classList.remove('mobile-open');
    }
}

function updateThemeIcon() {
    if (elements.themeToggle) {
        elements.themeToggle.textContent = currentTheme === 'dark' ? '☀️' : '🌙';
    }
}

function initializeProfileBadge() {
    if (!elements.profileBadge || !elements.profileDropdown) return;
    if (elements.profileBadge.dataset.profileInitialized === 'true') return;

    elements.profileBadge.dataset.profileInitialized = 'true';

    elements.profileBadge.addEventListener('click', (e) => {
        e.stopPropagation();
        elements.profileDropdown.classList.toggle('active');
    });

    document.addEventListener('click', () => {
        elements.profileDropdown.classList.remove('active');
    });

    const profileView = document.getElementById('profileView');
    if (profileView) {
        profileView.addEventListener('click', (e) => {
            e.stopPropagation();
            showProfileModal();
            elements.profileDropdown.classList.remove('active');
        });
    }

    const profileSettings = document.getElementById('profileSettings');
    if (profileSettings) {
        profileSettings.addEventListener('click', (e) => {
            e.stopPropagation();
            showSection('settings');
            elements.profileDropdown.classList.remove('active');
        });
    }

    const profileLogout = document.getElementById('logoutBtn');
    if (profileLogout) {
        profileLogout.addEventListener('click', (e) => {
            e.stopPropagation();
            if (confirm('Are you sure you want to logout?')) {
                // Use existing logout handler
                if (typeof window.logoutHandler === 'object') {
                    window.logoutHandler.logout();
                } else {
                    // Fallback to direct redirect
                    window.location.href = 'index.php?logout=true';
                }
            }
        });
    }
}

function showProfileModal() {
    const modal = createModal('Administrator Profile', `
    <div class="account-card">
        <div style="display: flex; align-items: center; gap: 20px; margin-bottom: 20px;">
            <div class="profile-avatar" style="width: 80px; height: 80px; font-size: 32px;">
                <?php echo strtoupper(substr($user_name, 0, 2)); ?>
            </div>
            <div>
                <h3><?php echo htmlspecialchars($user_name); ?></h3>
                <p class="small">Administrator</p>
                <p class="small">Email: <?php echo htmlspecialchars($user_email); ?></p>
                <p class="small">PF Number: <?php echo htmlspecialchars($user_pf); ?></p>
                <p class="small">ID Number: <?php echo htmlspecialchars($user_id_number); ?></p>
            </div>
        </div>
        <div class="row">
            <button class="btn-primary" onclick="showSection('settings'); document.querySelector('.modal').remove();">
                ⚙ Account Settings
            </button>
            <button class="btn-secondary" onclick="document.querySelector('.modal').remove();">
                Close
            </button>
        </div>
    </div>
`);

    document.body.appendChild(modal);
    modal.classList.add('show');
    modal.style.display = 'flex';
}

/* ===========================
       <i data-lucide="bar-chart-3" class="icon-sm"></i> DATA LOADING FUNCTIONS
       =========================== */

function renderfunding_accounts() {
    const container = document.getElementById('funding_accountsContainer');
    if (!container) return;

    apiRequest('funds.php', 'GET')
        .then(response => {
            if (response.success && response.data && response.data.length > 0) {
                container.innerHTML = '';
                response.data.forEach(fund => {
                    const card = document.createElement('div');
                    card.className = 'account-card';

                    // Format dates properly
                    const createdDate = fund.createdAt ? new Date(fund.createdAt)
                        .toLocaleDateString(
                            'en-US', {
                                year: 'numeric',
                                month: 'short',
                                day: 'numeric'
                            }) : 'N/A';

                    const updatedDate = fund.updatedAt ? new Date(fund.updatedAt)
                        .toLocaleDateString(
                            'en-US', {
                                year: 'numeric',
                                month: 'short',
                                day: 'numeric',
                                hour: '2-digit',
                                minute: '2-digit'
                            }) : 'N/A';

                    // Create transaction badge
                    const transactionCount = fund.transaction_count || 0;
                    const transactionBadge = transactionCount > 0 ?
                        `<span class="transaction-badge">${transactionCount} Transaction${transactionCount !== 1 ? 's' : ''}</span>` :
                        '';

                    // Create statistics row
                    const stats = `
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin: 15px 0;">
                        <div style="background: #e8f5e9; padding: 10px; border-radius: 6px; text-align: center;">
                            <div style="font-size: 12px; color: #2e7d32; margin-bottom: 4px;">Total Deposits</div>
                            <div style="font-size: 16px; font-weight: 600; color: #2e7d32;">
                                ${fmtKES(fund.total_deposits || 0)}
                            </div>
                        </div>
                        <div style="background: #ffebee; padding: 10px; border-radius: 6px; text-align: center;">
                            <div style="font-size: 12px; color: #c62828; margin-bottom: 4px;">Total Withdrawals</div>
                            <div style="font-size: 16px; font-weight: 600; color: #c62828;">
                                ${fmtKES(fund.total_withdrawals || 0)}
                            </div>
                        </div>
                    </div>
                `;

                    // Create recent transactions display
                    let recentTransactionsHTML = '';
                    if (fund.recent_transactions && fund.recent_transactions.length > 0) {
                        recentTransactionsHTML = `
                        <div style="margin-top: 15px;">
                            <h4 style="font-size: 14px; margin-bottom: 10px; color: var(--text-primary);">
                                Recent Transactions (Last 5)
                            </h4>
                            <div style="max-height: 200px; overflow-y: auto;">
                    `;

                        fund.recent_transactions.forEach(trans => {
                            const transDate = new Date(trans.created_at)
                                .toLocaleDateString(
                                    'en-US', {
                                        month: 'short',
                                        day: 'numeric',
                                        hour: '2-digit',
                                        minute: '2-digit'
                                    });

                            recentTransactionsHTML += `
                            <div class="transaction-row">
                                <div>
                                    <span class="transaction-type ${trans.transaction_type}">${trans.transaction_type}</span>
                                    <div style="font-size: 11px; color: var(--text-secondary); margin-top: 2px;">
                                        ${transDate} • ${escapeHtml(trans.created_by_name || 'System')}
                                    </div>
                                </div>
                                <div style="font-weight: 600; color: ${trans.transaction_type === 'withdrawal' ? '#c62828' : '#2e7d32'};">
                                    ${trans.transaction_type === 'withdrawal' ? '-' : '+'}${fmtKES(trans.amount)}
                                </div>
                            </div>
                        `;
                        });

                        recentTransactionsHTML += `
                        </div>
                    </div>
                `;
                    }

                    card.innerHTML = `
                    ${transactionBadge}
                    <h3>${escapeHtml(fund.name)}</h3>
                    <p style="font-size: 24px; font-weight: 700; color: var(--green); margin: 10px 0;">
                        ${fmtKES(fund.balance)}
                    </p>
                    
                    ${stats}
                    
                    <div class="account-dates">
                        <span><strong>Created:</strong> ${createdDate}</span>
                        <span><strong>Last Updated:</strong> ${updatedDate}</span>
                    </div>
                    
                    ${recentTransactionsHTML}
                    
                    <div class="row mb-2" style="margin-top: 15px;">
                        <input type="number" id="topup_${fund.id}" placeholder="Top-up amount (KES)" min="0" required>
                        <button class="btn-success topup-btn" data-id="${fund.id}">➕ Top Up</button>
                        <button class="btn-danger remove-fund-btn" data-id="${fund.id}">🗑️ Remove</button>
                    </div>
                    
                    ${transactionCount > 0 ? `
                        <button class="btn-info view-transactions-btn" data-id="${fund.id}" data-name="${escapeHtml(fund.name)}">
                            <i data-lucide="bar-chart-3" class="icon-sm"></i> View All Transactions (${transactionCount})
                        </button>
                    ` : ''}
                `;
                    container.appendChild(card);
                });

                // Add event listeners for top-up buttons
                document.querySelectorAll('.topup-btn').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const id = btn.dataset.id;
                        if (!id) return;

                        const input = document.getElementById(`topup_${id}`);
                        const topupAmount = Number(input.value) || 0;

                        if (topupAmount <= 0) {
                            showToast('Please enter a valid top-up amount', 'error');
                            return;
                        }

                        const fund = response.data.find(f => f.id == id);
                        if (!fund) {
                            showToast('Account not found', 'error');
                            return;
                        }

                        const currentBalance = Number(fund.balance) || 0;
                        const newBalance = currentBalance + topupAmount;

                        showLoading(true);
                        apiRequest(`funds.php?id=${id}`, 'PUT', {
                                balance: newBalance
                            })
                            .then(response => {
                                if (response.success) {
                                    input.value = '';
                                    renderfunding_accounts();
                                    updateStats();
                                    showToast(
                                        `Top-up successful: ${fmtKES(topupAmount)}`,
                                        'success');
                                } else {
                                    showToast(response.message ||
                                        'Failed to top-up account', 'error');
                                }
                            })
                            .catch(error => {
                                showToast('Failed to top-up account', 'error');
                            })
                            .finally(() => {
                                showLoading(false);
                            });
                    });
                });

                // Add event listeners for remove fund buttons
                document.querySelectorAll('.remove-fund-btn').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const id = btn.dataset.id;
                        if (!id) return;

                        const account = response.data.find(a => a.id == id);
                        if (!account) return;

                        if (!confirm(
                                `Remove funding account "${account.name || 'Unnamed Account'}"?\n\nThis will also delete all ${account.transaction_count || 0} associated transactions.`
                            ))
                            return;

                        showLoading(true);
                        apiRequest(`funds.php?id=${id}`, 'DELETE')
                            .then(response => {
                                if (response.success) {
                                    renderfunding_accounts();
                                    updateStats();
                                    showToast('Funding account removed', 'success');
                                } else {
                                    showToast('Failed to remove account', 'error');
                                }
                            })
                            .catch(error => {
                                showToast('Failed to remove account', 'error');
                            })
                            .finally(() => {
                                showLoading(false);
                            });
                    });
                });

                // Add event listeners for view transactions buttons
                document.querySelectorAll('.view-transactions-btn').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const id = btn.dataset.id;
                        const name = btn.dataset.name;
                        showTransactionHistory(id, name);
                    });
                });
            } else {
                container.innerHTML = `<p class="small text-center">No funding accounts found.</p>`;
            }
        }).catch(error => {
            container.innerHTML = '<p class="small text-center">Error loading accounts</p>';
        });
}

/**
 * Restore from backup function
 */
function restoreFromBackup() {
    // Create file input if it doesn't exist
    let fileInput = document.getElementById('restoreFileInput');
    if (!fileInput) {
        fileInput = document.createElement('input');
        fileInput.type = 'file';
        fileInput.id = 'restoreFileInput';
        fileInput.accept = '.json';
        fileInput.style.display = 'none';
        document.body.appendChild(fileInput);
    }

    // Handle file selection
    fileInput.onchange = function(event) {
        const file = event.target.files[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = function(e) {
            try {
                const backupData = JSON.parse(e.target.result);

                // Validate backup data structure
                if (!backupData || typeof backupData !== 'object') {
                    showToast('Invalid backup file format', 'error');
                    return;
                }

                // Show confirmation dialog
                if (!confirm(
                        'Are you sure you want to restore from backup? This will replace all current data and cannot be undone.'
                    )) {
                    return;
                }

                // Send backup data to server
                showLoading(true);
                apiRequest('restore.php', 'POST', {
                        csrf_token: window.CSRF_TOKEN,
                        backup_data: backupData
                    })
                    .then(response => {
                        if (response.success) {
                            showToast('Backup restored successfully', 'success');

                            // Refresh page after a short delay to reload all data
                            setTimeout(() => {
                                window.location.reload();
                            }, 2000);
                        } else {
                            showToast('Restore failed: ' + (response.message || 'Unknown error'),
                                'error');
                        }
                    })
                    .catch(error => {
                        //console.error('Restore error:', error);
                        showToast('Restore failed: ' + error.message, 'error');
                    })
                    .finally(() => {
                        showLoading(false);
                    });
            } catch (error) {
                //console.error('Backup parsing error:', error);
                showToast('Failed to parse backup file', 'error');
            }
        };

        reader.readAsText(file);
    };

    // Trigger file selection
    fileInput.click();
}

// Function to show logs in a modal
function showLogsModal(logs, pagination) {
    const modal = document.createElement('div');
    modal.className = 'modal show';
    modal.style.display = 'flex';

    let logsHtml = '';
    if (logs.length === 0) {
        logsHtml = '<p class="text-center">No activity logs found</p>';
    } else {
        logsHtml = `
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Admin</th>
                        <th>Action</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    ${logs.map(log => `
                        <tr>
                            <td>${new Date(log.timestamp).toLocaleString()}</td>
                            <td>${escapeHtml(log.admin_name || 'Unknown')}</td>
                            <td>${escapeHtml(log.action)}</td>
                            <td>${escapeHtml(log.details || '')}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        </div>
        <div class="pagination">
            <span>Showing ${logs.length} of ${pagination.total} entries</span>
        </div>
    `;
    }

    modal.innerHTML = `
    <div class="modal-content" style="max-width: 1000px;">
        <div class="modal-header">
            <h2><i data-lucide="clipboard-list" class="icon-sm"></i> Activity Logs</h2>
            <button class="close-modal">&times;</button>
        </div>
        <div class="modal-body">
            ${logsHtml}
        </div>
        <div class="modal-footer">
            <button class="btn-secondary" onclick="this.closest('.modal').remove()">Close</button>
        </div>
    </div>
`;

    document.body.appendChild(modal);

    // Close modal handlers
    modal.querySelector('.close-modal').addEventListener('click', () => modal.remove());
    modal.addEventListener('click', (e) => {
        if (e.target === modal) modal.remove();
    });
}

// Function to show transaction history modal
async function showTransactionHistory(accountId, accountName) {
    showLoading(true);

    try {
        // Fetch ALL transactions without limit
        const response = await apiRequest(`funds.php?id=${accountId}&limit=999999`, 'GET');

        if (response.success && response.data) {
            const account = response.data;
            const transactions = account.transactions || [];

            // Update summary
            const summaryDiv = document.getElementById('transactionSummary');
            summaryDiv.innerHTML = `
            <h3 style="margin-top: 0;">${escapeHtml(accountName)}</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 15px;">
                <div style="text-align: center; padding: 15px; background: var(--bg-tertiary); border-radius: 6px;">
                    <div style="font-size: 14px; color: var(--text-secondary); margin-bottom: 5px;">Current Balance</div>
                    <div style="font-size: 24px; font-weight: 700; color: var(--green);">${fmtKES(account.balance)}</div>
                </div>
                <div style="text-align: center; padding: 15px; background: #e8f5e9; border-radius: 6px;">
                    <div style="font-size: 14px; color: #2e7d32; margin-bottom: 5px;">Total Deposits</div>
                    <div style="font-size: 20px; font-weight: 600; color: #2e7d32;">
                        ${fmtKES(transactions.filter(t => t.transaction_type === 'deposit').reduce((sum, t) => sum + parseFloat(t.amount), 0))}
                    </div>
                </div>
                <div style="text-align: center; padding: 15px; background: #ffebee; border-radius: 6px;">
                    <div style="font-size: 14px; color: #c62828; margin-bottom: 5px;">Total Withdrawals</div>
                    <div style="font-size: 20px; font-weight: 600; color: #c62828;">
                        ${fmtKES(transactions.filter(t => t.transaction_type === 'withdrawal').reduce((sum, t) => sum + parseFloat(t.amount), 0))}
                    </div>
                </div>
                <div style="text-align: center; padding: 15px; background: var(--bg-tertiary); border-radius: 6px;">
                    <div style="font-size: 14px; color: var(--text-secondary); margin-bottom: 5px;">Total Transactions</div>
                    <div style="font-size: 20px; font-weight: 600;">${transactions.length}</div>
                </div>
            </div>
        `;

            // Update transactions table
            const tbody = document.getElementById('transactionTableBody');
            tbody.innerHTML = '';

            if (transactions.length === 0) {
                tbody.innerHTML =
                    '<tr><td colspan="5" style="text-align: center; padding: 30px;">No transactions found</td></tr>';
            } else {
                // Sort by date (newest first)
                const sortedTransactions = [...transactions].sort((a, b) => {
                    return new Date(b.created_at) - new Date(a.created_at);
                });

                sortedTransactions.forEach(trans => {
                    const row = tbody.insertRow();
                    row.innerHTML = `
                    <td>${new Date(trans.created_at).toLocaleString('en-US', {
                        year: 'numeric',
                        month: 'short',
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    })}</td>
                    <td>
                        <span class="transaction-type ${trans.transaction_type}">${trans.transaction_type}</span>
                    </td>
                    <td style="font-weight: 600; color: ${trans.transaction_type === 'withdrawal' ? '#c62828' : '#2e7d32'};">
                        ${trans.transaction_type === 'withdrawal' ? '-' : '+'}${fmtKES(trans.amount)}
                    </td>
                    <td style="font-size: 12px;">${escapeHtml(trans.transaction_reference || trans.reference || 'N/A')}</td>
                    <td>${escapeHtml(trans.created_by_name || 'System')}<br>
                        <small style="color: var(--text-secondary);">PF: ${trans.created_by_pf || 'N/A'}</small>
                    </td>
                `;
                });
            }

            // Store current account for export
            window.currentTransactionAccount = {
                id: accountId,
                name: accountName,
                balance: account.balance,
                transactions: transactions // ALL transactions
            };

            // Show modal
            const modal = document.getElementById('transactionModal');
            if (modal) {
                modal.style.display = 'flex';
                modal.classList.add('show');
            } else {
                showToast('Modal element not found', 'error');
            }
        } else {
            showToast('Failed to load transaction history', 'error');
        }
    } catch (error) {
        showToast('Failed to load transaction history', 'error');
    } finally {
        showLoading(false);
    }
}

/**
 * Close transaction modal
 */
function closeTransactionModal() {
    const modal = document.getElementById('transactionModal');
    if (modal) {
        // Add fade-out animation
        modal.style.animation = 'fadeOut 0.3s';

        setTimeout(() => {
            modal.style.display = 'none';
            modal.classList.remove('show');
            modal.style.animation = '';
        }, 300);
    }

    // Clear the current transaction account data
    window.currentTransactionAccount = null;
}

// Function to export ALL transaction history as Excel with complete details
async function exportTransactionHistory() {
    if (!window.currentTransactionAccount) {
        showToast('No transaction data available', 'error');
        return;
    }

    const account = window.currentTransactionAccount;

    try {
        showLoading(true);

        // Load XLSX library if not already loaded
        if (typeof XLSX === 'undefined') {
            await loadScript('https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js');
        }

        // Create workbook
        const wb = XLSX.utils.book_new();

        // 1. Account Summary Worksheet
        const summaryData = [
            ['ACCOUNT SUMMARY'],
            ['Account Name', account.name],
            ['Account ID', account.id],
            ['Current Balance', account.balance],
            ['Total Transactions', account.transactions.length],
            [],
            ['TRANSACTION SUMMARY'],
            ['Total Deposits', account.transactions.filter(t => t.transaction_type === 'deposit').reduce((
                sum, t) => sum + parseFloat(t.amount), 0)],
            ['Total Withdrawals', account.transactions.filter(t => t.transaction_type === 'withdrawal')
                .reduce((sum, t) => sum + parseFloat(t.amount), 0)
            ],
            ['Net Change', account.transactions.reduce((sum, t) => sum + (t.transaction_type === 'deposit' ?
                parseFloat(t.amount) : -parseFloat(t.amount)), 0)],
            [],
            ['TRANSACTION COUNT BY TYPE'],
            ['Deposit Transactions', account.transactions.filter(t => t.transaction_type === 'deposit')
                .length
            ],
            ['Withdrawal Transactions', account.transactions.filter(t => t.transaction_type ===
                'withdrawal').length],
            [],
            ['DATE RANGE'],
            ['First Transaction', account.transactions.length > 0 ? new Date(Math.min(...account
                .transactions.map(t => new Date(t.created_at)))).toLocaleDateString() : 'N/A'],
            ['Last Transaction', account.transactions.length > 0 ? new Date(Math.max(...account.transactions
                .map(t => new Date(t.created_at)))).toLocaleDateString() : 'N/A']
        ];

        const summaryWs = XLSX.utils.aoa_to_sheet(summaryData);

        // Auto-size columns for summary
        const summaryColWidths = [{
                wch: 25
            }, // Column A
            {
                wch: 20
            }, // Column B
        ];
        summaryWs['!cols'] = summaryColWidths;

        XLSX.utils.book_append_sheet(wb, summaryWs, 'Summary');

        // 2. Detailed Transactions Worksheet - ALL TRANSACTIONS
        const transactionData = [
            ['TRANSACTION ID', 'DATE & TIME', 'TYPE', 'AMOUNT (KES)', 'PAYMENT METHOD', 'REFERENCE NUMBER',
                'RECORDED BY', 'PF NUMBER', 'DEPARTMENT', 'NOTES', 'CREATED AT', 'UPDATED AT'
            ]
        ];

        // Sort transactions by date (newest first)
        const sortedTransactions = [...account.transactions].sort((a, b) => {
            return new Date(b.created_at) - new Date(a.created_at);
        });

        // Add ALL transactions with complete details
        sortedTransactions.forEach((trans, index) => {
            transactionData.push([
                trans.transaction_id || `TXN${index + 1}`,
                new Date(trans.created_at).toLocaleString('en-US', {
                    month: 'short',
                    day: '2-digit',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit'
                }),
                trans.transaction_type ? trans.transaction_type.toUpperCase() : 'UNKNOWN',
                parseFloat(trans.amount) || 0,
                trans.payment_source || trans.payment_method || 'N/A',
                trans.transaction_reference || trans.reference || 'N/A',
                trans.created_by_name || trans.recorded_by || 'System',
                trans.created_by_pf || 'N/A',
                trans.department || 'N/A',
                trans.notes || 'N/A',
                trans.created_at || 'N/A',
                trans.updated_at || 'N/A'
            ]);
        });

        const transactionsWs = XLSX.utils.aoa_to_sheet(transactionData);

        // Auto-size columns for transactions
        const transColWidths = [{
                wch: 15
            }, // Transaction ID
            {
                wch: 22
            }, // Date & Time
            {
                wch: 12
            }, // Type
            {
                wch: 15
            }, // Amount
            {
                wch: 15
            }, // Payment Method
            {
                wch: 20
            }, // Reference Number
            {
                wch: 20
            }, // Recorded By
            {
                wch: 15
            }, // PF Number
            {
                wch: 15
            }, // Department
            {
                wch: 30
            }, // Notes
            {
                wch: 22
            }, // Created At
            {
                wch: 22
            } // Updated At
        ];
        transactionsWs['!cols'] = transColWidths;

        // Add styling to header row
        const headerRange = XLSX.utils.decode_range(transactionsWs['!ref']);
        for (let col = headerRange.s.c; col <= headerRange.e.c; col++) {
            const cellAddress = XLSX.utils.encode_cell({
                r: 0,
                c: col
            });
            if (!transactionsWs[cellAddress]) continue;

            transactionsWs[cellAddress].s = {
                font: {
                    bold: true,
                    color: {
                        rgb: "FFFFFF"
                    }
                },
                fill: {
                    fgColor: {
                        rgb: "4472C4"
                    }
                },
                alignment: {
                    horizontal: "center",
                    vertical: "center"
                }
            };
        }

        XLSX.utils.book_append_sheet(wb, transactionsWs, 'All Transactions');

        // 3. Monthly Summary Worksheet (Optional - shows trends)
        const monthlyData = [
            ['MONTH', 'DEPOSITS', 'WITHDRAWALS', 'NET CHANGE', 'TRANSACTION COUNT']
        ];
        const monthlyMap = new Map();

        account.transactions.forEach(trans => {
            const date = new Date(trans.created_at);
            const monthKey = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;

            if (!monthlyMap.has(monthKey)) {
                monthlyMap.set(monthKey, {
                    deposits: 0,
                    withdrawals: 0,
                    count: 0
                });
            }

            const monthData = monthlyMap.get(monthKey);
            if (trans.transaction_type === 'deposit') {
                monthData.deposits += parseFloat(trans.amount);
            } else {
                monthData.withdrawals += parseFloat(trans.amount);
            }
            monthData.count += 1;
        });

        // Sort months chronologically
        const sortedMonths = Array.from(monthlyMap.keys()).sort();
        sortedMonths.forEach(month => {
            const [year, monthNum] = month.split('-');
            const monthName = new Date(year, monthNum - 1).toLocaleDateString('en-US', {
                month: 'long',
                year: 'numeric'
            });
            const data = monthlyMap.get(month);

            monthlyData.push([
                monthName,
                data.deposits,
                data.withdrawals,
                data.deposits - data.withdrawals,
                data.count
            ]);
        });

        const monthlyWs = XLSX.utils.aoa_to_sheet(monthlyData);
        const monthlyColWidths = [{
                wch: 20
            }, // Month
            {
                wch: 15
            }, // Deposits
            {
                wch: 15
            }, // Withdrawals
            {
                wch: 15
            }, // Net Change
            {
                wch: 18
            } // Transaction Count
        ];
        monthlyWs['!cols'] = monthlyColWidths;

        XLSX.utils.book_append_sheet(wb, monthlyWs, 'Monthly Summary');

        // 4. Payment Methods Analysis Worksheet
        const paymentMethods = new Map();
        account.transactions.forEach(trans => {
            const method = trans.payment_source || trans.payment_method || 'Unknown';
            if (!paymentMethods.has(method)) {
                paymentMethods.set(method, {
                    count: 0,
                    total: 0
                });
            }
            const methodData = paymentMethods.get(method);
            methodData.count += 1;
            methodData.total += parseFloat(trans.amount);
        });

        const paymentData = [
            ['PAYMENT METHOD', 'TRANSACTION COUNT', 'TOTAL AMOUNT', 'AVERAGE AMOUNT']
        ];
        paymentMethods.forEach((data, method) => {
            paymentData.push([
                method,
                data.count,
                data.total,
                data.count > 0 ? data.total / data.count : 0
            ]);
        });

        const paymentWs = XLSX.utils.aoa_to_sheet(paymentData);
        const paymentColWidths = [{
                wch: 25
            }, // Payment Method
            {
                wch: 18
            }, // Transaction Count
            {
                wch: 15
            }, // Total Amount
            {
                wch: 15
            } // Average Amount
        ];
        paymentWs['!cols'] = paymentColWidths;

        XLSX.utils.book_append_sheet(wb, paymentWs, 'Payment Methods');

        // Generate Excel file with comprehensive name
        const fileName =
            `Complete_Transaction_History_${account.name.replace(/\s+/g, '_')}_${new Date().toISOString().split('T')[0]}.xlsx`;
        XLSX.writeFile(wb, fileName);

        showToast(`Exported ${account.transactions.length} transactions with complete details!`, 'success');
    } catch (error) {
        console.error('Export error:', error);
        showToast('Failed to export transaction history: ' + error.message, 'error');
    } finally {
        showLoading(false);
    }
}

/* ===========================
       <i data-lucide="bar-chart-3" class="icon-sm"></i> USER MANAGEMENT
       =========================== */

class UserManager {
    constructor() {
        this.users = [];
        this.filteredUsers = [];
        this.pageSize = 50;
        this.currentPage = 1;
        this.totalUsers = 0;
        this.cache = new Map();
        this.cacheTimeout = 30000; // 30 seconds
        this.isLoading = false;
        this.searchDebounceTimer = null;
    }

    /**
     * Load users with pagination and caching
     */
    async loadUsers(options = {}) {
        const {
            page = 1,
                pageSize = this.pageSize,
                filters = {},
                forceRefresh = false
        } = options;

        // Generate cache key
        const cacheKey = JSON.stringify({
            page,
            pageSize,
            filters
        });

        // Check cache
        if (!forceRefresh && this.cache.has(cacheKey)) {
            const cached = this.cache.get(cacheKey);
            if (Date.now() - cached.timestamp < this.cacheTimeout) {
                return cached.data;
            }
        }

        // Prevent concurrent requests
        if (this.isLoading) {
            return this.users;
        }

        this.isLoading = true;

        try {
            // Build query parameters
            const params = new URLSearchParams({
                page,
                pageSize,
                ...filters
            });

            const response = await apiRequest(`users.php?${params.toString()}`, 'GET');

            if (response.success && response.data) {
                // Cache the result
                this.cache.set(cacheKey, {
                    data: response.data,
                    timestamp: Date.now()
                });

                this.users = response.data;
                this.totalUsers = response.total || response.data.length;
                this.currentPage = page;

                return response.data;
            }

            return [];
        } catch (error) {
            //console.error('Error loading users:', error);
            if (window.showToast) {
                window.showToast('Failed to load users: ' + error.message, 'error');
            }
            return [];
        } finally {
            this.isLoading = false;
        }
    }

    /**
     * Render users with virtual scrolling
     */
    renderUsers(users = null) {
        const tbody = document.querySelector('#staffTable tbody');
        if (!tbody) return;

        const dataToRender = users || this.filteredUsers;

        if (dataToRender.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center">No users found</td></tr>';
            return;
        }

        // Use DocumentFragment for better performance
        const fragment = document.createDocumentFragment();

        dataToRender.forEach((user, index) => {
            const row = this.createUserRow(user, index);
            fragment.appendChild(row);
        });

        tbody.innerHTML = '';
        tbody.appendChild(fragment);

        // Attach event listeners after rendering
        this.attachEventListeners();
    }

    /**
     * Create single user row (optimized)
     */
    createUserRow(user, index) {
        const tr = document.createElement('tr');
        tr.className = 'no-select';
        tr.dataset.userId = user.user_id;

        // FIX: Ensure is_active is properly parsed as integer
        const isActive = parseInt(user.is_active) === 1;
        const statusValue = isActive ? 1 : 0;

        // FIX: Proper HTML escaping
        const escapedName = escapeHtml(user.full_name || '');
        const escapedPF = escapeHtml(user.pf_number || '');
        const escapedID = escapeHtml(user.id_number || '');
        const escapedEmail = escapeHtml(user.email || '');
        const escapedRole = escapeHtml(user.role || '');

        tr.innerHTML = `
    <td>${index + 1}</td>
    <td>${escapedName}</td>
    <td>${escapedPF}</td>
    <td>${escapedID}</td>
    <td>${escapedEmail}</td>
    <td><span class="role-badge ${escapedRole}">${escapedRole.toUpperCase()}</span></td>
    <td>
        ${isActive ? 
            '<span class="role-badge" style="background:var(--success)">Active</span>' : 
            '<span class="role-badge" style="background:var(--muted)">Inactive</span>'
        }
    </td>
    <td>${user.created_at ? new Date(user.created_at).toLocaleDateString() : '-'}</td>
    <td>
        <div style="display:flex;gap:5px;flex-wrap:wrap;">
            <button class="btn-primary edit-user-btn" 
                    data-user-id="${user.user_id}" 
                    style="font-size:12px;padding:6px 10px;">
                <i data-lucide="edit-2" class="icon-sm"></i> Edit
            </button>
            <button class="btn-${isActive ? 'warning' : 'success'} toggle-status-btn" 
                    data-user-id="${user.user_id}" 
                    data-current-status="${statusValue}" 
                    style="font-size:12px;padding:6px 10px;">
                ${isActive ? '<i data-lucide="lock" class="icon-sm"></i> Deactivate' : '🔓 Activate'}
            </button>
            <button class="btn-danger remove-user-btn" 
                    data-user-id="${user.user_id}" 
                    style="font-size:12px;padding:6px 10px;">
                🗑️ Remove
            </button>
        </div>
    </td>
`;

        return tr;
    }

    /**
     * Attach event listeners using delegation
     */
    attachEventListeners() {
        const tbody = document.querySelector('#staffTable tbody');
        if (!tbody) return;

        // Remove old listeners by cloning
        const newTbody = tbody.cloneNode(true);
        tbody.parentNode.replaceChild(newTbody, tbody);

        // Add new event listener with delegation
        newTbody.addEventListener('click', async (e) => {
            const target = e.target.closest('button');
            if (!target) return;

            e.preventDefault();
            e.stopPropagation();

            const userId = target.dataset.userId;
            if (!userId) return;

            if (target.classList.contains('edit-user-btn')) {
                await this.handleEditUser(userId);
            } else if (target.classList.contains('toggle-status-btn')) {
                const currentStatus = target.dataset.currentStatus;
                await toggleUserStatus(userId, currentStatus);
            } else if (target.classList.contains('remove-user-btn')) {
                await this.handleRemoveUser(userId);
            }
        });
    }

    /**
     * Search with debouncing
     */
    search(term, filters = {}) {
        clearTimeout(this.searchDebounceTimer);

        this.searchDebounceTimer = setTimeout(async () => {
            const searchFilters = {
                ...filters,
                search: term
            };

            const users = await this.loadUsers({
                page: 1,
                filters: searchFilters
            });

            this.filteredUsers = users;
            this.renderUsers();
        }, 300);
    }

    /**
     * Clear cache
     */
    clearCache() {
        this.cache.clear();
    }

    /**
     * Event handlers
     */
    async handleEditUser(userId) {
        const user = this.users.find(u => String(u.user_id) === String(userId));
        if (!user) {
            if (window.showToast) {
                window.showToast('User not found', 'error');
            }
            return;
        }

        // Use global function
        if (typeof window.populateUserEditForm === 'function') {
            window.populateUserEditForm(user);
        }
    }

    async handleRemoveUser(userId) {
        if (!confirm('Are you sure you want to remove this user? This action cannot be undone.')) {
            return;
        }

        try {
            const response = await apiRequest(`users.php?user_id=${userId}`, 'DELETE');

            if (response.success) {
                if (window.showToast) {
                    window.showToast('User removed successfully', 'success');
                }

                // Refresh data
                this.clearCache();
                await this.loadUsers({
                    forceRefresh: true
                });
                this.renderUsers();
            }
        } catch (error) {
            if (window.showToast) {
                window.showToast('Failed to remove user', 'error');
            }
        }
    }

    /**
     * Utility method
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// Create global instance
const userManager = new UserManager();

// Backward compatible function
async function renderUsers() {
    const filters = {
        role: document.getElementById('filterUserRole')?.value || '',
        status: document.getElementById('filterUserStatus')?.value || '',
        search: document.getElementById('userSearchInput')?.value || ''
    };

    const users = await userManager.loadUsers({
        filters
    });
    userManager.filteredUsers = users;
    userManager.renderUsers();
}

// Export
window.UserManager = UserManager;
window.userManager = userManager;
window.renderUsers = renderUsers;

/**
 * Toggle user status (activate/deactivate)
 */
async function toggleUserStatus(userId, currentIsActive) {
    //console.log('<i data-lucide="refresh-cw" class="icon-sm"></i> Toggling status for user:', userId, 'Current status:', currentIsActive);

    const isCurrentlyActive = parseInt(currentIsActive) === 1;
    const newStatus = isCurrentlyActive ? 0 : 1;

    // Self-deactivation protection
    if (!newStatus && userId == window.currentAdminUserId) {
        showToast('You cannot deactivate your own account', 'error');
        return;
    }

    if (!confirm(`Are you sure you want to ${newStatus ? 'activate' : 'deactivate'} this user?`)) {
        return;
    }

    try {
        showLoading(true);

        // Get CSRF token
        const csrfElement = document.querySelector('#csrf-token-data');
        const csrfToken = csrfElement ? csrfElement.dataset.csrfToken : '';

        if (!csrfToken) {
            throw new Error('CSRF token not found');
        }

        // Use PUT method to update user status
        const response = await apiRequest(`users.php?user_id=${userId}`, 'PUT', {
            is_active: newStatus,
            csrf_token: csrfToken
        });

        //console.log('<i data-lucide="download" class="icon-sm"></i> Toggle response:', response);

        if (response.success) {
            showToast('User ' + (newStatus ? 'activated' : 'deactivated') + ' successfully', 'success');

            // Clear cache and refresh
            usersCache = null;
            if (typeof userManager !== 'undefined' && userManager.clearCache) {
                userManager.clearCache();
            }
            await renderUsers();
        } else {
            showToast(response.message || 'Failed to update status', 'error');
        }
    } catch (error) {
        //console.error('<i data-lucide="x-circle" class="icon-sm"></i> Toggle status error:', error);
        showToast('Network error. Please try again.', 'error');
    } finally {
        showLoading(false);
    }
}


async function handleEditUser(userId) {
    //console.log('🔧 Editing user ID:', userId);

    if (!userId) {
        showToast('Invalid user ID', 'error');
        return;
    }

    try {
        showLoading(true);

        // Fetch user data from API
        const response = await apiRequest(`users.php?user_id=${userId}`, 'GET');

        //console.log('<i data-lucide="download" class="icon-sm"></i> API Response:', response);

        if (response.success && response.data) {
            const user = response.data;
            //console.log('<i data-lucide="user" class="icon-sm"></i> User data:', user);

            // Populate form with user data
            populateUserEditForm(user);
        } else {
            //console.error('<i data-lucide="x-circle" class="icon-sm"></i> API Error:', response.message);
            showToast(response.message || 'User not found', 'error');
        }
    } catch (error) {
        //console.error('<i data-lucide="x-circle" class="icon-sm"></i> Edit user error:', error);
        showToast('Failed to load user data: ' + error.message, 'error');
    } finally {
        showLoading(false);
    }
}

/**
 * Populate edit form with user data
 */
function populateUserEditForm(user) {
    //console.log('<i data-lucide="file-edit" class="icon-sm"></i> Populating form with user:', user);

    if (!user) {
        showToast('User data not found', 'error');
        return;
    }

    // Get form elements
    const nameField = document.getElementById('staffName');
    const pfField = document.getElementById('staffPf');
    const idField = document.getElementById('staffIdNumber');
    const emailField = document.getElementById('staffEmail');
    const roleField = document.getElementById('staffRole');
    const passwordField = document.getElementById('staffPwd');

    // Check if elements exist
    if (!nameField || !pfField || !idField || !emailField || !roleField) {
        //console.error('<i data-lucide="x-circle" class="icon-sm"></i> Form elements not found');
        showToast('Form elements not found', 'error');
        return;
    }

    // Populate form fields
    nameField.value = user.full_name || '';
    pfField.value = user.pf_number || '';
    idField.value = user.id_number || '';
    emailField.value = user.email || '';
    roleField.value = user.role || 'loanee';

    // Clear password field and make it optional
    if (passwordField) {
        passwordField.value = '';
        passwordField.removeAttribute('required');
        passwordField.placeholder = 'Leave blank to keep current password';
    }

    // Store editing user ID
    window.editingUserId = user.user_id;
    editingUserId = user.user_id;

    //console.log('<i data-lucide="check-circle" class="icon-sm"></i> Editing user ID set to:', editingUserId);

    // Update button text
    const saveBtn = document.getElementById('saveStaffBtn');
    if (saveBtn) {
        saveBtn.innerHTML = '💾 Update User';
        saveBtn.disabled = false; // Enable for editing
    }

    // Show cancel button
    const cancelBtn = document.getElementById('cancelEditBtn');
    if (cancelBtn) {
        cancelBtn.classList.remove('hidden');
    }

    // Scroll to form
    const staffForm = document.getElementById('staffForm');
    if (staffForm) {
        staffForm.scrollIntoView({
            behavior: 'smooth',
            block: 'center'
        });
    }

    showToast('Editing user: ' + user.full_name, 'info');
}


/**
 * Handle toggle user status
 */
async function handleToggleUserStatus(userId, currentStatus) {
    const newStatus = currentStatus === '1' || currentStatus === 1 ? 0 : 1;
    const actionText = newStatus === 1 ? 'activate' : 'deactivate';

    if (!confirm(`Are you sure you want to ${actionText} this user?`)) {
        return;
    }

    try {
        showLoading(true);

        const csrfToken = document.querySelector('#csrf-token-data')?.dataset.csrfToken || '';

        // Create form data for POST request to admin.php
        const formData = new FormData();
        formData.append('action', 'toggle_user_status');
        formData.append('user_id', userId);
        formData.append('is_active', newStatus);
        formData.append('csrf_token', csrfToken);

        const response = await fetch('admin.php', {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            showToast(`User ${actionText}d successfully`, 'success');

            // Clear cache and refresh user list
            usersCache = null;
            if (typeof userManager !== 'undefined' && userManager.clearCache) {
                userManager.clearCache();
            }
            await renderUsers();
        } else {
            showToast(result.message || 'Failed to update user status', 'error');
        }
    } catch (error) {
        //console.error('Toggle status error:', error);
        showToast('Failed to update user status: ' + error.message, 'error');
    } finally {
        showLoading(false);
    }
}

/**
 * Handle remove user
 */
async function handleRemoveUser(userId) {
    if (!confirm('Are you sure you want to remove this user? This action cannot be undone.')) {
        return;
    }

    try {
        showLoading(true);

        const csrfToken = document.querySelector('#csrf-token-data')?.dataset.csrfToken || '';

        const response = await apiRequest('users.php?user_id=' + userId, 'DELETE', {
            csrf_token: csrfToken
        });

        if (response.success) {
            showToast('User removed successfully', 'success');

            // Clear cache and refresh
            usersCache = null;
            if (typeof userManager !== 'undefined' && userManager.clearCache) {
                userManager.clearCache();
            }
            await renderUsers();
        } else {
            showToast(response.message || 'Failed to remove user', 'error');
        }
    } catch (error) {
        //console.error('Remove user error:', error);
        showToast('Failed to remove user: ' + error.message, 'error');
    } finally {
        showLoading(false);
    }
}

/**
 * Cancel edit mode
 */
function cancelEditMode() {
    // Reset form
    const staffForm = document.getElementById('staffForm');
    if (staffForm) {
        staffForm.reset();
    }

    // Clear editing user ID
    window.editingUserId = null;
    editingUserId = null;

    // Reset password field to required
    const passwordField = document.getElementById('staffPwd');
    if (passwordField) {
        passwordField.setAttribute('required', 'required');
        passwordField.placeholder = 'Password';
    }

    // Reset button text
    const saveBtn = document.getElementById('saveStaffBtn');
    if (saveBtn) {
        saveBtn.innerHTML = '💾 Save User';
        saveBtn.disabled = true; // Require valid password for new users
    }

    // Hide cancel button
    const cancelBtn = document.getElementById('cancelEditBtn');
    if (cancelBtn) {
        cancelBtn.classList.add('hidden');
    }

    // Reset password validation
    resetPasswordValidation();

    showToast('Edit cancelled', 'info');
}

async function updateUserRole(userId, newRole) {
    try {
        showLoading(true);
        const response = await apiRequest(`users.php?user_id=${encodeURIComponent(userId)}`, 'PUT', {
            role: newRole,
            csrf_token: getCsrfToken()
        });
        if (!response.success) throw new Error(response.message || 'Failed to update role');
        showToast(`User role updated to ${newRole}`, 'success');
    } catch (error) {
        //console.error('Update role error:', error);
        showToast('Failed to update user role: ' + error.message, 'error');
    } finally {
        showLoading(false);
    }
}

/**
 * Initialize user management event listeners
 * IMPORTANT: Call this AFTER DOM is loaded
 */
function initializeUserManagementEvents() {
    //console.log('🔧 Initializing user management event listeners...');

    const staffTableBody = document.querySelector('#staffTable tbody');

    if (!staffTableBody) {
        //console.error('<i data-lucide="x-circle" class="icon-sm"></i> Staff table body not found');
        return;
    }

    // Remove old listeners by cloning
    const newTbody = staffTableBody.cloneNode(true);
    staffTableBody.parentNode.replaceChild(newTbody, staffTableBody);

    // Single event listener using delegation
    newTbody.addEventListener('click', async function(e) {
        const button = e.target.closest('button');
        if (!button) return;

        e.preventDefault();
        e.stopPropagation();

        const userId = button.dataset.userId;

        //console.log('🖱️ Button clicked:', button.className, 'User ID:', userId);

        if (!userId) {
            //console.error('<i data-lucide="x-circle" class="icon-sm"></i> No userId found on button');
            showToast('User ID not found', 'error');
            return;
        }

        // Handle different button types
        if (button.classList.contains('edit-user-btn')) {
            //console.log('<i data-lucide="edit-2" class="icon-sm"></i> Edit button clicked for user:', userId);
            await handleEditUser(userId);
        } else if (button.classList.contains('toggle-status-btn')) {
            const currentStatus = button.dataset.currentStatus;
            //console.log('<i data-lucide="refresh-cw" class="icon-sm"></i> Toggle button clicked for user:', userId, 'Current status:', currentStatus);
            await toggleUserStatus(userId, currentStatus);
        } else if (button.classList.contains('remove-user-btn')) {
            //console.log('🗑️ Remove button clicked for user:', userId);
            await handleRemoveUser(userId);
        }
    });

    // Initialize other user management buttons
    const cancelEditBtn = document.getElementById('cancelEditBtn');
    if (cancelEditBtn) {
        cancelEditBtn.onclick = cancelEditMode;
    }

    const filterUserBtn = document.getElementById('filterUserBtn');
    if (filterUserBtn) {
        filterUserBtn.onclick = async () => {
            usersCache = null;
            await renderUsers();
            showToast('Filters applied', 'info');
        };
    }

    const clearUserFilters = document.getElementById('clearUserFilters');
    if (clearUserFilters) {
        clearUserFilters.onclick = async () => {
            document.getElementById('filterUserRole').value = '';
            document.getElementById('filterUserStatus').value = '';
            document.getElementById('userSearchInput').value = '';
            usersCache = null;
            await renderUsers();
            showToast('Filters cleared', 'info');
        };
    }

    // --- FIX: Add Refresh Button Listener for User Management ---
    const refreshUserBtn = document.getElementById('refreshUserBtn');
    if (refreshUserBtn) {
        refreshUserBtn.addEventListener('click', async () => {
            // Clear cache to force fresh data fetch
            usersCache = null;
            if (typeof userManager !== 'undefined' && userManager.clearCache) {
                userManager.clearCache();
            }
            await renderUsers();
            showToast('Data refreshed', 'success');
        });
    }

    // Search with debounce
    const userSearchInput = document.getElementById('userSearchInput');
    if (userSearchInput) {
        userSearchInput.addEventListener('input', debounce(async () => {
            usersCache = null;
            await renderUsers();
        }, 300));
    }

    //console.log('<i data-lucide="check-circle" class="icon-sm"></i> All user management events initialized');

    // Ensure global availability
    window.initializeUserManagementEvents = initializeUserManagementEvents;
}


/**
 * Initialize with retry mechanism
 */
function initUserManagementWithRetry(maxRetries = 5, delay = 200) {
    let attempts = 0;

    const tryInit = () => {
        attempts++;
        //console.log(`<i data-lucide="refresh-cw" class="icon-sm"></i> Attempt ${attempts} to initialize user management...`);

        const staffTable = document.getElementById('staffTable');

        if (staffTable && staffTable.querySelector('tbody')) {
            initializeUserManagementEvents();
            return true;
        }

        if (attempts < maxRetries) {
            //console.log(`<i data-lucide="hourglass" class="icon-sm"></i> Table not ready, retrying in ${delay}ms...`);
            setTimeout(tryInit, delay);
        } else {
            //console.error('<i data-lucide="x-circle" class="icon-sm"></i> Failed to initialize user management after', maxRetries, 'attempts');
        }

        return false;
    };

    tryInit();
}

// Auto-initialize
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        //console.log('<i data-lucide="file-text" class="icon-sm"></i> DOM loaded - initializing user management');
        initUserManagementWithRetry();
    });
} else {
    //console.log('<i data-lucide="file-text" class="icon-sm"></i> DOM already loaded - initializing user management immediately');
    initUserManagementWithRetry();
}

function renderPagination(totalPages, totalItems) {
    const paginationContainer = document.getElementById('loansPagination');
    if (!paginationContainer) return;

    paginationContainer.innerHTML = '';

    if (totalPages <= 1) return;

    // Previous button
    const prevBtn = document.createElement('button');
    prevBtn.textContent = 'Previous';
    prevBtn.disabled = currentPage === 1;
    prevBtn.addEventListener('click', () => {
        if (currentPage > 1) {
            currentPage--;
            renderLoans();
        }
    });
    paginationContainer.appendChild(prevBtn);

    // Page numbers
    for (let i = 1; i <= totalPages; i++) {
        const pageBtn = document.createElement('button');
        pageBtn.textContent = i;
        pageBtn.className = i === currentPage ? 'active' : '';
        pageBtn.addEventListener('click', () => {
            currentPage = i;
            renderLoans();
        });
        paginationContainer.appendChild(pageBtn);
    }

    // Next button
    const nextBtn = document.createElement('button');
    nextBtn.textContent = 'Next';
    nextBtn.disabled = currentPage === totalPages;
    nextBtn.addEventListener('click', () => {
        if (currentPage < totalPages) {
            currentPage++;
            renderLoans();
        }
    });
    paginationContainer.appendChild(nextBtn);
}

/* ===========================
    <i data-lucide="bar-chart-3" class="icon-sm"></i> DATA LOADING & RENDERING
=========================== */

async function renderLoans(filter = 'all', forceRefresh = false) {
    try {
        showLoadingIndicator('Loading loan applications...');

        const LOANS_ENDPOINT = 'loan_applications.php';
        let endpoint = LOANS_ENDPOINT;
        const params = new URLSearchParams();

        if (filter && filter !== 'all') params.append('filter', filter);
        if (forceRefresh) params.append('_cb', Date.now());

        if (params.toString()) endpoint += `?${params.toString()}`;

        const response = await apiRequest(endpoint, 'GET');

        if (!response?.success || !Array.isArray(response.data)) {
            showNotification('No loans found or invalid response', 'info');
            return;
        }

        const tableBody = document.querySelector('#loansTable tbody');
        if (!tableBody) {
            console.error('Table body not found');
            return;
        }

        tableBody.innerHTML = '';

        response.data.forEach(loan => {
            const isLegacy = loan.record_type === 'legacy';
            const legacyBadge = isLegacy ? '<span class="badge legacy-badge">Legacy</span>' : '';

            const statusClass = (loan.status || 'unknown').toLowerCase();
            const currentBalance = loan.current_balance ??
                (Number(loan.loan_amount || 0) - Number(loan.total_paid || 0));

            const lastUpdated = loan.updated_at || loan.application_date || loan.created_at || '—';

            const row = document.createElement('tr');
            row.className = `status-${statusClass}`;

            row.innerHTML = `
            <td>${escapeHtml(formatDate(loan.application_date || loan.created_at))}</td>
            <td>${escapeHtml(loan.full_name || 'N/A')} ${legacyBadge}</td>
            <td>${escapeHtml(loan.id_number || 'N/A')}</td>
            <td>${escapeHtml(loan.pf_number || 'N/A')}</td>
            <td>${escapeHtml(loan.email || 'N/A')}</td>
            <td>${escapeHtml(loan.loan_type || 'N/A')}</td>
            <td style="text-align:right">${formatCurrency(loan.loan_amount || 0)}</td>
            <td>
                <span class="status-badge status-${statusClass}">
                    ${escapeHtml(loan.status || 'Unknown')}
                </span>
            </td>
            <td>${escapeHtml(loan.feedback || '—')}</td>
            <td>${escapeHtml(formatDate(lastUpdated))}</td>
            <td class="actions-column">
            <button class="action-btn edit-btn" 
                data-loan="${encodeURIComponent(JSON.stringify(loan))}"
                 onclick="showEditLoanModalFromButton(this)">
                <i data-lucide="edit-2" class="icon-sm"></i> Edit
            </button>

                <button 
                    class="action-btn view-btn"
                    onclick="viewLoanDetails('${escapeJS(loan.application_id || '')}')"
                >
                    <i data-lucide="eye" class="icon-sm"></i> View
                </button>
            </td>
        `;

            tableBody.appendChild(row);
        });

        // Optional: update stats/cards if you have them
        if (typeof updateStats === 'function') {
            updateStats(response.stats || {});
        }

        document.getElementById('loansTable')?.style.setProperty('display', 'table');

    } catch (error) {
        console.error('Error rendering loans:', error);
        showNotification(`Failed to load loans: ${error.message}`, 'error');
    } finally {
        hideLoadingIndicator();
    }
}

/* ===========================
        <i data-lucide="edit-2" class="icon-sm"></i> LOAN EDITING
    =========================== */

function showEditLoanModal(loan) {
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.id = 'editLoanModal';

    modal.innerHTML = `
    <div class="modal-content" style="max-width: 900px; width: 90%;">
        <div class="modal-header">
            <h3><i data-lucide="edit-2" class="icon-sm"></i> Edit Loan Application</h3>
            <small>${escapeHtml(loan.application_id || '—')} – ${escapeHtml(loan.full_name || 'Unknown')}</small>
            <span class="close-btn" onclick="closeModal('editLoanModal')">×</span>
        </div>

        <div class="modal-body">
            <form id="editLoanForm">
                <input type="hidden" name="action" value="update_loan">
                <input type="hidden" name="csrf_token" value="${getCsrfToken() || ''}">
                <input type="hidden" name="id" value="${loan.id || ''}">

                <div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem;">

                    <!-- Personal Information -->
                    <div class="form-group">
                        <label>Full Name * <span class="required">*</span></label>
                        <input type="text" name="full_name" value="${escapeHtml(loan.full_name || '')}" required>
                    </div>

                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" value="${escapeHtml(loan.email || '')}">
                    </div>

                    <div class="form-group">
                        <label>PF Number</label>
                        <input type="text" name="pf_number" value="${escapeHtml(loan.pf_number || '')}">
                    </div>

                    <div class="form-group">
                        <label>ID Number</label>
                        <input type="text" name="id_number" value="${escapeHtml(loan.id_number || '')}">
                    </div>

                    <!-- Loan Details -->
                    <div class="form-group">
                        <label>Loan Type</label>
                        <select name="loan_type">
                            <option value="mortgage" ${loan.loan_type === 'mortgage' ? 'selected' : ''}>Mortgage</option>
                            <option value="car" ${loan.loan_type === 'car' ? 'selected' : ''}>Car</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Loan Amount (KES)</label>
                        <input type="number" step="1000" name="loan_amount" value="${loan.loan_amount || ''}">
                    </div>

                    <div class="form-group">
                        <label>Payment Duration (Years)</label>
                        <input type="number" name="payment_duration" value="${loan.payment_duration || 12}">
                    </div>

                    <!-- Employment & Contract -->
                    <div class="form-group">
                        <label>Basic Salary (KES)</label>
                        <input type="number" step="100" name="basic_salary" value="${loan.basic_salary || ''}">
                    </div>

                    <div class="form-group">
                        <label>Employment Type</label>
                        <select name="employment_type">
                            <option value="">— Select —</option>
                            <option value="permanent" ${loan.employment_type === 'permanent' ? 'selected' : ''}>Permanent</option>
                            <option value="contracted" ${loan.employment_type === 'contracted' ? 'selected' : ''}>Contracted</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Contract Period</label>
                        <input type="text" name="contract_period" value="${escapeHtml(loan.contract_period || '')}">
                    </div>

                    <div class="form-group">
                        <label>Contract Start Date</label>
                        <input type="date" name="contract_start_date" value="${loan.contract_start_date || ''}">
                    </div>

                    <div class="form-group">
                        <label>Contract End Date</label>
                        <input type="date" name="contract_end_date" value="${loan.contract_end_date || ''}">
                    </div>

                    <!-- Security / Collateral -->
                    <div class="form-group full-width">
                        <label>Property Location</label>
                        <input type="text" name="property_location" value="${escapeHtml(loan.property_location || '')}">
                    </div>

                    <div class="form-group">
                        <label>Property Value (KES)</label>
                        <input type="number" step="1000" name="property_value" value="${loan.property_value || ''}">
                    </div>

                    <div class="form-group">
                        <label>Car Type</label>
                        <input type="text" name="car_type" value="${escapeHtml(loan.car_type || '')}">
                    </div>

                    <div class="form-group">
                        <label>Car Value (KES)</label>
                        <input type="number" step="1000" name="car_value" value="${loan.car_value || ''}">
                    </div>

                    <!-- Banking -->
                    <div class="form-group">
                        <label>Bank Name</label>
                        <input type="text" name="bank_name" value="${escapeHtml(loan.bank_name || '')}">
                    </div>

                    <div class="form-group">
                        <label>Bank Branch</label>
                        <input type="text" name="bank_branch" value="${escapeHtml(loan.bank_branch || '')}">
                    </div>

                    <div class="form-group">
                        <label>Bank Account Number</label>
                        <input type="text" name="bank_account_number" value="${escapeHtml(loan.bank_account_number || '')}">
                    </div>

                    <!-- Feedback -->
                    <div class="form-group full-width">
                        <label>Feedback / Notes</label>
                        <textarea name="feedback" rows="4">${escapeHtml(loan.feedback || '')}</textarea>
                    </div>

                </div>
            </form>
        </div>

        <div class="modal-footer">
            <button class="action-btn save-btn" onclick="saveLoanEdits()">💾 Save Changes</button>
            <button class="action-btn cancel-btn" onclick="closeModal('editLoanModal')">Cancel</button>
        </div>
    </div>
`;

    document.body.appendChild(modal);
    modal.style.display = 'flex';
}

/**
 * Save edited loan data
 */
async function saveLoanEdits() {
    const form = document.getElementById('editLoanForm');
    if (!form) return;

    try {
        showLoadingIndicator('Saving changes...');

        const formData = new FormData(form);

        const response = await fetch('admin.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });

        if (!response.ok) {
            throw new Error(`Server responded with ${response.status}`);
        }

        const data = await response.json();

        if (data.success) {
            showNotification('Loan updated successfully', 'success');
            closeModal('editLoanModal');
            renderLoans('all', true); // force refresh
        } else {
            showNotification(data.message || 'Failed to update loan', 'error');
        }
    } catch (err) {
        console.error('Save error:', err);
        showNotification(`Error saving changes: ${err.message}`, 'error');
    } finally {
        hideLoadingIndicator();
    }
}

/* Helpers */

function closeModal(id) {
    const modal = document.getElementById(id);
    if (modal) modal.remove();
}

function escapeJS(str) {
    return String(str).replace(/'/g, "\\'").replace(/"/g, '\\"');
}

// ==========================================
// FINANCIAL REPORTS VIEW (Cashier/Accountant Style)
// ==========================================
async function showReportsView() {
    //console.log('Loading reports view...');

    try {
        showLoading(true);

        // Fetch all loans using the existing helper
        const loans = await getAllLoans();

        // Hide all other sections
        document.querySelectorAll('main section').forEach(s => s.classList.add('hidden'));

        // Show reports section
        const reportsSection = document.getElementById('reportsSection');
        if (reportsSection) {
            reportsSection.classList.remove('hidden');
        }

        // Update navigation
        document.querySelectorAll('#sidebar button, #sidebar li').forEach(btn => {
            btn.classList.remove('active');
        });
        const reportsNav = document.getElementById('navReports');
        if (reportsNav) {
            reportsNav.classList.add('active');
        }

        // Define initial filter state
        const reportFilters = {
            fromDate: null,
            toDate: null,
            loanType: 'all',
            pfNumber: null
        };

        // 1. RENDER FILTER SECTION HTML
        let filterHtml = `
            <div class="filter-section">
                <h3 style="margin-top:0; color:var(--green);"><i data-lucide="search" class="icon-sm"></i> Filter Reports</h3>
                <div class="filter-row">
                    <div class="filter-group">
                        <label for="reportFromDate">From Date:</label>
                        <input type="date" id="reportFromDate" value="">
                    </div>
                    <div class="filter-group">
                        <label for="reportToDate">To Date:</label>
                        <input type="date" id="reportToDate" value="">
                    </div>
                    <div class="filter-group">
                        <label for="reportLoanType">Loan Type:</label>
                        <select id="reportLoanType">
                            <option value="all">All Loans</option>
                            <option value="mortgage">Mortgage</option>
                            <option value="car">Car</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="reportPfNumber">PF Number:</label>
                        <input type="text" id="reportPfNumber" placeholder="Enter PF Number">
                    </div>
                </div>
                <div class="filter-buttons">
                    <button onclick="applyReportFilters()" class="btn-primary">Apply Filters</button>
                    <button onclick="resetReportFilters()" class="btn-secondary">Reset</button>
                </div>
            </div>
        `;

        // Define window functions for the buttons to work
        window.applyReportFilters = function() {
            reportFilters.fromDate = document.getElementById('reportFromDate').value;
            reportFilters.toDate = document.getElementById('reportToDate').value;
            reportFilters.loanType = document.getElementById('reportLoanType').value;
            reportFilters.pfNumber = document.getElementById('reportPfNumber').value.trim();

            // Re-render reports with filters
            showReportsViewWithFilters(loans, reportFilters);
        };

        window.resetReportFilters = function() {
            document.getElementById('reportFromDate').value = '';
            document.getElementById('reportToDate').value = '';
            document.getElementById('reportLoanType').value = 'all';
            document.getElementById('reportPfNumber').value = '';

            // Reset filter state
            reportFilters.fromDate = null;
            reportFilters.toDate = null;
            reportFilters.loanType = 'all';
            reportFilters.pfNumber = null;

            // Re-render reports without filters
            showReportsViewWithFilters(loans, reportFilters);
        };

        // Insert Filter + Content into DOM
        const reportsDiv = document.getElementById('reportsContent');
        if (reportsDiv) {
            reportsDiv.innerHTML = filterHtml;
        }

        // Show reports with initial data (unfiltered)
        showReportsViewWithFilters(loans, reportFilters);

    } catch (error) {
        //console.error('Error loading reports:', error);
        showToast('Failed to load reports: ' + error.message, 'error');
    } finally {
        showLoading(false);
    }
}

// Helper function to render content based on filters
async function showReportsViewWithFilters(loans, filters) {
    // 1. Apply Filters
    let filteredLoans = [...loans];

    if (filters.fromDate) {
        const fromDate = new Date(filters.fromDate);
        filteredLoans = filteredLoans.filter(loan => {
            const loanDate = new Date(loan.application_date);
            return loanDate >= fromDate;
        });
    }

    if (filters.toDate) {
        const toDate = new Date(filters.toDate);
        toDate.setHours(23, 59, 59, 999); // Include the entire day
        filteredLoans = filteredLoans.filter(loan => {
            const loanDate = new Date(loan.application_date);
            return loanDate <= toDate;
        });
    }

    if (filters.loanType !== 'all') {
        filteredLoans = filteredLoans.filter(loan => loan.loan_type === filters.loanType);
    }

    if (filters.pfNumber) {
        filteredLoans = filteredLoans.filter(loan =>
            loan.pf_number && loan.pf_number.toLowerCase().includes(filters.pfNumber.toLowerCase())
        );
    }

    // 2. Calculate Statistics
    const activeLoans = filteredLoans.filter(l => l.status === 'active' || l.status === 'dispatched');
    const closedLoans = filteredLoans.filter(l => l.status === 'closed');
    const mortgageLoans = filteredLoans.filter(l => l.loan_type === 'mortgage');
    const carLoans = filteredLoans.filter(l => l.loan_type === 'car');

    let totalLoanedAmount = 0;
    let totalPaidAmount = 0;
    let totalPrincipalPaid = 0;
    let totalInterestPaid = 0;
    let totalOutstandingBalance = 0;

    const mortgageStats = {
        loaned: 0,
        paid: 0,
        principal: 0,
        interest: 0,
        balance: 0,
        count: 0
    };
    const carStats = {
        loaned: 0,
        paid: 0,
        principal: 0,
        interest: 0,
        balance: 0,
        count: 0
    };
    const yearlyStats = {};

    // FIX: Process ALL filtered loans, not just active/closed
    const loansToProcess = [...filteredLoans];


    for (const loan of loansToProcess) {
        const balanceInfo = await recalculateLoanBalance(loan);
        const loanAmount = parseFloat(loan.loan_amount) || 0;

        totalLoanedAmount += loanAmount;
        totalPaidAmount += parseFloat(balanceInfo.totalPaid) || 0;
        totalPrincipalPaid += parseFloat(balanceInfo.principalPaid) || 0;
        totalInterestPaid += parseFloat(balanceInfo.interestPaid) || 0;

        if (loan.status !== 'closed') {
            totalOutstandingBalance += parseFloat(balanceInfo.totalBalanceDue) || 0;
        }

        // Type Stats
        const stats = loan.loan_type === 'mortgage' ? mortgageStats : carStats;
        stats.loaned += loanAmount;
        stats.paid += parseFloat(balanceInfo.totalPaid) || 0;
        stats.principal += parseFloat(balanceInfo.principalPaid) || 0;
        stats.interest += parseFloat(balanceInfo.interestPaid) || 0;
        if (loan.status !== 'closed') {
            stats.balance += parseFloat(balanceInfo.totalBalanceDue) || 0;
        }
        stats.count++;

        // Yearly Stats
        const dispatchDate = loan.dispatch_date || loan.application_date;
        if (dispatchDate) {
            const year = new Date(dispatchDate).getFullYear();
            if (!yearlyStats[year]) {
                yearlyStats[year] = {
                    loaned: 0,
                    paid: 0,
                    principal: 0,
                    interest: 0,
                    balance: 0,
                    count: 0,
                    mortgage: {
                        count: 0,
                        amount: 0
                    },
                    car: {
                        count: 0,
                        amount: 0
                    }
                };
            }
            yearlyStats[year].loaned += loanAmount;
            yearlyStats[year].paid += parseFloat(balanceInfo.totalPaid) || 0;
            yearlyStats[year].principal += parseFloat(balanceInfo.principalPaid) || 0;
            yearlyStats[year].interest += parseFloat(balanceInfo.interestPaid) || 0;
            if (loan.status !== 'closed') {
                yearlyStats[year].balance += parseFloat(balanceInfo.totalBalanceDue) || 0;
            }
            yearlyStats[year].count++;

            if (loan.loan_type === 'mortgage') {
                yearlyStats[year].mortgage.count++;
                yearlyStats[year].mortgage.amount += loanAmount;
            } else {
                yearlyStats[year].car.count++;
                yearlyStats[year].car.amount += loanAmount;
            }
        }
    }

    // 3. Generate HTML
    let html = `
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px;">
        
        <!-- Overall Summary Card -->
        <div class="report-card card-overall">
            <button onclick="exportOverallSummary()" class="card-export-btn" title="Export Overall Summary"><i data-lucide="bar-chart-3" class="icon-sm"></i> Export</button>
            <h3 style="margin-top:0; padding-right: 80px;"><i data-lucide="briefcase" class="icon-sm" style="margin-right: 8px;"></i>Overall Summary</h3>
            <p><strong>Total Loaned:</strong> KES ${formatCurrency(totalLoanedAmount)}</p>
            <p><strong>Total Loans:</strong> ${activeLoans.length + closedLoans.length}</p>
            <p><strong>Active Loans:</strong> ${activeLoans.length}</p>
            <p><strong>Closed Loans:</strong> ${closedLoans.length}</p>
        </div>

        <!-- Mortgage Summary Card -->
        <div class="report-card card-mortgage">
            <button onclick="exportMortgageLoans()" class="card-export-btn" title="Export Mortgage Loans"><i data-lucide="bar-chart-3" class="icon-sm"></i> Export</button>
            <h3 style="margin-top:0; padding-right: 80px;"><i data-lucide="home" class="icon-sm" style="margin-right: 8px;"></i>Mortgage Loans</h3>
            <p><strong>Total Count:</strong> ${mortgageStats.count}</p>
            <p><strong>Total Loaned:</strong> KES ${formatCurrency(mortgageStats.loaned)}</p>
            <p><strong>Total Paid:</strong> KES ${formatCurrency(mortgageStats.paid)}</p>
            <p><strong>Outstanding:</strong> KES ${formatCurrency(mortgageStats.balance)}</p>
        </div>

        <!-- Car Loan Summary Card -->
        <div class="report-card card-car">
            <button onclick="exportCarLoans()" class="card-export-btn" title="Export Car Loans"><i data-lucide="bar-chart-3" class="icon-sm"></i> Export</button>
            <h3 style="margin-top:0; padding-right: 80px;"><i data-lucide="car" class="icon-sm" style="margin-right: 8px;"></i>Car Loans</h3>
            <p><strong>Total Count:</strong> ${carStats.count}</p>
            <p><strong>Total Loaned:</strong> KES ${formatCurrency(carStats.loaned)}</p>
            <p><strong>Total Paid:</strong> KES ${formatCurrency(carStats.paid)}</p>
            <p><strong>Outstanding:</strong> KES ${formatCurrency(carStats.balance)}</p>
        </div>

        <!-- Payment Summary Card -->
        <div class="report-card card-payment">
            <button onclick="exportPaymentSummary()" class="card-export-btn" title="Export Payment Summary"><i data-lucide="bar-chart-3" class="icon-sm"></i> Export</button>
            <h3 style="margin-top:0; padding-right: 80px;"><i data-lucide="coins" class="icon-sm"></i> Payment Summary</h3>
            <p><strong>Total Paid:</strong> KES ${formatCurrency(totalPaidAmount)}</p>
            <p><strong>Principal Paid:</strong> KES ${formatCurrency(totalPrincipalPaid)}</p>
            <p><strong>Interest Paid:</strong> KES ${formatCurrency(totalInterestPaid)}</p>
            <p><strong>Outstanding:</strong> KES ${formatCurrency(totalOutstandingBalance)}</p>
        </div>
    </div>

    <!-- Yearly Breakdown -->
    <div class="account-card">
        <h3 style="margin-top:0; color:var(--green); display:flex; align-items:center; gap:8px;"><i data-lucide="calendar" class="icon-sm"></i> Yearly Breakdown</h3>
        <table class="reports-table">
            <thead>
                <tr style="background:var(--blue); color:white;">
                    <th>Year</th>
                    <th style="text-align:center;">Total Loans</th>
                    <th style="text-align:center;">Mortgage</th>
                    <th style="text-align:center;">Car</th>
                    <th style="text-align:right;">Amount Loaned</th>
                    <th style="text-align:right;">Principal Paid</th>
                    <th style="text-align:right;">Interest Paid</th>
                    <th style="text-align:right;">Outstanding</th>
                </tr>
            </thead>
            <tbody>
    `;

    const sortedYears = Object.keys(yearlyStats).sort((a, b) => b - a);
    sortedYears.forEach(year => {
        const stats = yearlyStats[year];
        html += `
            <tr style="border-bottom: 1px solid var(--border-color);">
                <td style="padding:12px; font-weight:bold;">${year}</td>
                <td style="padding:12px; text-align:center;">${stats.count}</td>
                <td style="padding:12px; text-align:center;">${stats.mortgage.count} (KES ${formatCurrency(stats.mortgage.amount)})</td>
                <td style="padding:12px; text-align:center;">${stats.car.count} (KES ${formatCurrency(stats.car.amount)})</td>
                <td style="padding:12px; text-align:right; font-weight:bold;">KES ${formatCurrency(stats.loaned)}</td>
                <td style="padding:12px; text-align:right; color:var(--green);">KES ${formatCurrency(stats.principal)}</td>
                <td style="padding:12px; text-align:right; color:var(--warning);">KES ${formatCurrency(stats.interest)}</td>
                <td style="padding:12px; text-align:right; color:var(--danger);">KES ${formatCurrency(stats.balance)}</td>
            </tr>
        `;
    });

    html += `
                <tr style="background:var(--bg-tertiary); font-weight:bold; border-top: 2px solid var(--blue);">
                    <td style="padding:12px;">TOTAL</td>
                    <td style="padding:12px; text-align:center;">${activeLoans.length + closedLoans.length}</td>
                    <td style="padding:12px; text-align:center;">${mortgageStats.count}</td>
                    <td style="padding:12px; text-align:center;">${carStats.count}</td>
                    <td style="padding:12px; text-align:right;">KES ${formatCurrency(totalLoanedAmount)}</td>
                    <td style="padding:12px; text-align:right; color:var(--green);">KES ${formatCurrency(totalPrincipalPaid)}</td>
                    <td style="padding:12px; text-align:right; color:var(--warning);">KES ${formatCurrency(totalInterestPaid)}</td>
                    <td style="padding:12px; text-align:right; color:var(--danger);">KES ${formatCurrency(totalOutstandingBalance)}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Active Loans Details -->
    <div class="account-card" style="margin-top:20px;">
        <h3 style="margin-top:0; color:var(--green);"><i data-lucide="clipboard-list" class="icon-sm"></i> Active Loans Details</h3>
        <table class="reports-table">
            <thead>
                <tr style="background:var(--blue); color:white;">
                    <th>Loanee</th>
                    <th>PF Number</th>
                    <th>Type</th>
                    <th>Start Date</th>
                    <th style="text-align:right;">Original Amount</th>
                    <th style="text-align:right;">Principal Paid</th>
                    <th style="text-align:right;">Interest Paid</th>
                    <th style="text-align:right;">Balance Due</th>
                </tr>
            </thead>
            <tbody>
    `;

    for (const loan of activeLoans) {
        const balanceInfo = await recalculateLoanBalance(loan);
        html += `
            <tr style="border-bottom: 1px solid var(--border-color);">
                <td style="padding:12px;">${escapeHtml(loan.full_name)}</td>
                <td style="padding:12px;">${escapeHtml(loan.pf_number)}</td>
                <td style="padding:12px;">${loan.loan_type === 'mortgage' ? '<i data-lucide="home" class="icon-sm" style="vertical-align: middle;"></i> Mortgage' : '<i data-lucide="car" class="icon-sm" style="vertical-align: middle;"></i> Car'}</td>
                <td style="padding:12px;">${formatDate(loan.dispatch_date || loan.application_date)}</td>
                <td style="padding:12px; text-align:right;">KES ${formatCurrency(loan.loan_amount)}</td>
                <td style="padding:12px; text-align:right; color:var(--green);">KES ${formatCurrency(balanceInfo.principalPaid)}</td>
                <td style="padding:12px; text-align:right; color:var(--warning);">KES ${formatCurrency(balanceInfo.interestPaid)}</td>
                <td style="padding:12px; text-align:right; color:var(--danger); font-weight:bold;">KES ${formatCurrency(balanceInfo.totalBalanceDue)}</td>
            </tr>
        `;
    }

    html += `
            </tbody>
        </table>
    </div>
    `;

    // Append to DOM (preserving filter section)
    const reportsDiv = document.getElementById('reportsContent');
    const filterSection = reportsDiv.querySelector('.filter-section');
    const filterHtml = filterSection ? filterSection.outerHTML : '';

    reportsDiv.innerHTML = filterHtml + html;
}

// Make globally available
window.showReportsView = showReportsView;
window.recalculateLoanBalance = recalculateLoanBalance;
window
    .calculateMonthlyInterest = calculateMonthlyInterest;


// UPDATE STATS FUNCTION
async function updateStats() {
    try {
        // Use sequential requests instead of Promise.all to avoid race conditions
        const loansResponse = await apiRequest('loan_applications.php', 'GET');

        if (loansResponse.success && loansResponse.data) {
            const loans = loansResponse.data;
            const updates = {
                totalLoans: loans.length,
                pendingLoans: loans.filter(l => l.status === 'pending').length,
                forwardedLoans: loans.filter(l => l.status === 'forwarded').length,
                acceptedLoans: loans.filter(l => l.status === 'accepted').length,
                dispatchedLoans: loans.filter(l => l.status === 'dispatched').length,
                activeLoans: loans.filter(l => l.status === 'active').length,
                returnedLoans: loans.filter(l => l.status === 'returned').length,
                rejectedLoans: loans.filter(l => l.status === 'rejected').length
            };

            // Update DOM elements with proper formatting
            Object.entries(updates).forEach(([key, value]) => {
                formatStatNumber(value, key);
            });
        }

        // Get funds data separately
        const fundsResponse = await apiRequest('funds.php', 'GET');
        if (fundsResponse.success && fundsResponse.data) {
            const funds = fundsResponse.data;
            const totalFunds = funds.reduce((sum, fund) => sum + (Number(fund.balance) || 0), 0);
            formatStatNumber(totalFunds, 'totalFunds');
        }
    } catch (error) {
        //console.error('Error updating stats:', error);
        showToast('Failed to update statistics', 'error');
    }
}

function loadAmortRate() {
    apiRequest('settings.php?key=amortization_rate', 'GET')
        .then(response => {
            if (response.success && response.data) {
                const rate = Number(response.data.value);
                const amortRateEl = document.getElementById('amortRate');
                const amortStatusEl = document.getElementById('amortStatus');

                if (amortRateEl) amortRateEl.value = rate;
                if (amortStatusEl) amortStatusEl.textContent = `Current: ${rate.toFixed(1)}%`;
            } else {
                const rate = Number(localStorage.getItem('amortization_rate') ||
                    3.0); // FIXED: Use correct key
                const amortRateEl = document.getElementById('amortRate');
                const amortStatusEl = document.getElementById('amortStatus');

                if (amortRateEl) amortRateEl.value = rate;
                if (amortStatusEl) amortStatusEl.textContent = `Current: ${rate.toFixed(1)}%`;
            }
        })
        .catch(error => {
            const rate = Number(localStorage.getItem('amortization_rate') ||
                3.0); // FIXED: Use correct key
            const amortRateEl = document.getElementById('amortRate');
            const amortStatusEl = document.getElementById('amortStatus');

            if (amortRateEl) amortRateEl.value = rate;
            if (amortStatusEl) amortStatusEl.textContent = `Current: ${rate.toFixed(1)}%`;
        });
}

function renderActivityFeed() {
    const container = document.getElementById('activityFeed');
    if (!container) return;

    apiRequest('activity.php', 'GET')
        .then(response => {
            if (response.success && response.data && response.data.length > 0) {
                container.innerHTML = response.data.map(activity => {
                    const icon = {
                        add_staff: '<i data-lucide="user" class="icon-sm"></i>',
                        update_staff: '<i data-lucide="edit-2" class="icon-sm"></i>',
                        delete_staff: '🗑️',
                        change_password: '🔐',
                        update_settings: '⚙️',
                        logout: '<i data-lucide="log-out" class="icon-sm"></i>',
                        accepted: '<i data-lucide="check-circle" class="icon-sm"></i>',
                        dispatched: '🚀',
                        rejected: '<i data-lucide="x-circle" class="icon-sm"></i>',
                        returned: '<i data-lucide="corner-up-left" class="icon-sm"></i>',
                        forwarded: '<i data-lucide="arrow-right" class="icon-sm"></i>'
                    } [activity.action] || '<i data-lucide="clipboard-list" class="icon-sm"></i>';

                    const title = {
                        add_staff: 'Staff Added',
                        update_staff: 'Staff Updated',
                        delete_staff: 'Staff Deleted',
                        change_password: 'Password Changed',
                        update_settings: 'Settings Updated',
                        logout: 'User Logged Out',
                        accepted: 'Loan Accepted',
                        dispatched: 'Funds Dispatched',
                        rejected: 'Loan Rejected',
                        returned: 'Loan Returned',
                        forwarded: 'Loan Forwarded'
                    } [activity.action] || 'Activity';

                    return `
                    <div class="activity-item">
                        <div class="activity-icon ${activity.action}">${icon}</div>
                        <div class="activity-content">
                            <div class="activity-title">${title}</div>
                            <div class="small">${escapeHtml(activity.details)}</div>
                            <div class="activity-time">${new Date(activity.timestamp).toLocaleString()}</div>
                        </div>
                    </div>
                `;
                }).join('');
            } else {
                container.innerHTML = '<p class="small text-center">No activity recorded</p>';
            }
        })
        .catch(error => {
            container.innerHTML = '<p class="small text-center">Error loading activity</p>';
        });
}

function updateRecentActivity() {
    const container = document.getElementById('recentActivity');
    if (!container) return;

    apiRequest('activity.php?limit=5', 'GET')
        .then(response => {
            if (response.success && response.data && response.data.length > 0) {
                container.innerHTML = response.data.map(activity => {
                    const icon = {
                        add_staff: '<i data-lucide="user" class="icon-sm"></i>',
                        update_staff: '<i data-lucide="edit-2" class="icon-sm"></i>',
                        delete_staff: '🗑️',
                        change_password: '🔐',
                        update_settings: '⚙️',
                        logout: '<i data-lucide="log-out" class="icon-sm"></i>',
                        accepted: '<i data-lucide="check-circle" class="icon-sm"></i>',
                        dispatched: '🚀',
                        rejected: '<i data-lucide="x-circle" class="icon-sm"></i>',
                        returned: '<i data-lucide="corner-up-left" class="icon-sm"></i>',
                        forwarded: '<i data-lucide="arrow-right" class="icon-sm"></i>'
                    } [activity.action] || '<i data-lucide="clipboard-list" class="icon-sm"></i>';

                    return `
                    <div class="activity-item">
                        <div class="activity-icon ${activity.action}">${icon}</div>
                        <div class="activity-content">
                            <div class="small">${escapeHtml(activity.details)}</div>
                            <div class="activity-time">${new Date(activity.timestamp).toLocaleString()}</div>
                        </div>
                    </div>
                `;
                }).join('');
            } else {
                container.innerHTML = '<p class="small text-center">No recent activity</p>';
            }
        })
        .catch(error => {
            container.innerHTML = '<p class="small text-center">Error loading recent activity</p>';
        });
}

/* ===========================
       🎯 EVENT HANDLERS
    =========================== */

function handleUserFormSubmit(e) {
    e.preventDefault();

    //console.log('<i data-lucide="file-edit" class="icon-sm"></i> Form submitted, editing user ID:', editingUserId);

    const passwordField = document.getElementById('staffPwd');
    const password = passwordField ? passwordField.value.trim() : '';

    // Validate password ONLY if adding new user OR changing password
    if (!editingUserId && !password) {
        showToast('Password is required for new users', 'error');
        return;
    }

    // If password provided, validate it
    if (password) {
        if (password.length < 8) {
            showToast('Password must be at least 8 characters', 'error');
            return;
        }

        const hasLetter = /[a-zA-Z]/.test(password);
        const hasNumber = /[0-9]/.test(password);

        if (!hasLetter || !hasNumber) {
            showToast('Password must contain both letters and numbers', 'error');
            return;
        }
    }

    const data = {
        name: document.getElementById('staffName').value.trim(),
        pfNumber: document.getElementById('staffPf').value.trim(),
        idNumber: document.getElementById('staffIdNumber').value.trim(),
        email: document.getElementById('staffEmail').value.trim(),
        role: document.getElementById('staffRole').value,
        csrf_token: document.querySelector('#csrf-token-data')?.dataset.csrfToken || ''
    };

    // Only include password if provided
    if (password) {
        data.password = password;
    }

    /*console.log('📤 Submitting data:', {
    ...data,
    password: password ? '***' : 'not provided'
});*/

    // Validate other fields
    const errors = validateStaffInput(data);
    if (errors.length > 0) {
        showToast(errors.join(', '), 'error');
        return;
    }

    showLoading(true);

    const apiEndpoint = editingUserId ?
        `users.php?user_id=${editingUserId}` :
        'users.php';
    const method = editingUserId ? 'PUT' : 'POST';

    //console.log(`📡 API call: ${method} ${apiEndpoint}`);

    apiRequest(apiEndpoint, method, data)
        .then(response => {
            //console.log('<i data-lucide="download" class="icon-sm"></i> Response:', response);

            if (response.success) {
                const wasEditing = !!editingUserId;
                editingUserId = null;
                window.editingUserId = null;

                // Reset form
                const cancelBtn = document.getElementById('cancelEditBtn');
                if (cancelBtn) cancelBtn.classList.add('hidden');

                if (passwordField) {
                    passwordField.value = '';
                    passwordField.setAttribute('required', 'required');
                    passwordField.placeholder = 'Password';
                }

                // Clear cache and refresh
                usersCache = null;
                if (typeof userManager !== 'undefined' && userManager.clearCache) {
                    userManager.clearCache();
                }
                renderUsers();

                const form = document.getElementById('staffForm');
                if (form) form.reset();

                resetPasswordValidation();
                showToast(wasEditing ? 'User updated successfully' : 'User added successfully',
                    'success');
            } else {
                showToast(response.message || 'Operation failed', 'error');
            }
        })
        .catch(error => {
            //console.error('<i data-lucide="x-circle" class="icon-sm"></i> Form submission error:', error);
            showToast('Error: ' + error.message, 'error');
        })
        .finally(() => {
            showLoading(false);
        });
}

// Make functions globally accessible
window.toggleUserStatus = toggleUserStatus;
window.handleEditUser = handleEditUser;
window
    .handleRemoveUser = handleRemoveUser;
window.populateUserEditForm = populateUserEditForm;
window
    .initializeUserManagementEvents = initializeUserManagementEvents;
window.handleUserFormSubmit =
    handleUserFormSubmit;

function handleAddFundFormSubmit(e) {
    e.preventDefault();

    const nameInput = document.getElementById('newFundName');
    const balanceInput = document.getElementById('newFundBalance');

    if (!nameInput || !balanceInput) {
        showToast('Form error: Required fields not found', 'error');
        return;
    }

    const name = nameInput.value ? nameInput.value.trim() : '';
    const balance = balanceInput.value ? Number(balanceInput.value) : 0;

    if (!name) {
        showToast('Please enter account name', 'error');
        nameInput.focus();
        return;
    }

    if (isNaN(balance) || balance < 0) {
        showToast('Please enter a valid balance amount', 'error');
        balanceInput.focus();
        return;
    }

    const accountData = {
        name: name,
        balance: balance,
        createdAt: new Date().toISOString()
    };

    showLoading(true);

    apiRequest('funds.php', 'POST', accountData)
        .then(response => {
            if (response.success) {
                e.target.reset();
                renderfunding_accounts();
                updateStats();
                showToast('Funding account added successfully', 'success');
            } else {
                showToast(response.message || 'Failed to save funding account', 'error');
            }
        })
        .catch(error => {
            showToast('Failed to save funding account', 'error');
        })
        .finally(() => {
            showLoading(false);
        });
}

function resetPasswordValidation() {
    const lengthReq = document.getElementById('req-length');
    const alphanumericReq = document.getElementById('req-alphanumeric');
    const strengthBar = document.getElementById('strengthBar');
    const saveBtn = document.getElementById('saveStaffBtn');

    if (lengthReq) {
        lengthReq.classList.remove('requirement-met');
        lengthReq.classList.add('requirement-unmet');
    }
    if (alphanumericReq) {
        alphanumericReq.classList.remove('requirement-met');
        alphanumericReq.classList.add('requirement-unmet');
    }
    if (strengthBar) {
        strengthBar.className = 'password-strength-bar';
        strengthBar.style.width = '0';
    }
    if (saveBtn) saveBtn.disabled = true;
}

/* ===========================
       🎨 THEME MANAGEMENT
       =========================== */

/**
 * Initialize theme selector with proper event handlers
 * FIX: Added initialization guard to prevent duplicate listeners/toasts
 */
function initializeThemeSelector() {
    // ==========================================
    // FIX: Prevent duplicate initialization
    // ==========================================
    if (window.themeInitialized) {
        //console.log('Theme selector already initialized, skipping...');
        return;
    }
    window.themeInitialized = true;
    // ==========================================

    const themeButtons = document.querySelectorAll('.theme-option');

    // Load current theme from database
    loadThemeFromDatabase();

    themeButtons.forEach(button => {
        button.addEventListener('click', function() {
            const theme = this.getAttribute('data-theme');

            // Remove active class from all buttons
            themeButtons.forEach(btn => btn.classList.remove('active'));

            // Add active class to clicked button
            this.classList.add('active');

            // Apply theme
            currentTheme = theme;
            document.documentElement.setAttribute('data-theme', theme);

            // Save to database
            saveThemeToDatabase(theme);

            // Show success toast
            // Note: This toast will now only fire ONCE per click because
            // the event listener is only attached ONCE thanks to the guard above.
            showToast(`Theme changed to ${theme}`, 'success');
        });
    });
}

/**
 * Load theme preference from database
 */
async function loadThemeFromDatabase() {
    try {
        const response = await apiRequest('settings.php?key=user_theme_preference', 'GET');

        // Check if setting exists and has data
        if (response && response.success && response.data && response.data.value) {
            const theme = response.data.value;
            currentTheme = theme;
            document.documentElement.setAttribute('data-theme', theme);

            // Update active button
            document.querySelectorAll('.theme-option').forEach(btn => {
                if (btn.getAttribute('data-theme') === theme) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
        } else {
            // Setting doesn't exist yet - use default and create it
            currentTheme = 'light';
            document.documentElement.setAttribute('data-theme', 'light');

            // Update active button for light theme
            document.querySelectorAll('.theme-option').forEach(btn => {
                if (btn.getAttribute('data-theme') === 'light') {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });

            // Silently create the setting with default value
            saveThemeToDatabase('light').catch(() => {
                // Ignore errors when creating initial setting
            });
        }
    } catch (error) {
        // If there's any error (including "Setting not found"), use default
        //console.log('Theme preference not found, using default');
        currentTheme = 'light';
        document.documentElement.setAttribute('data-theme', 'light');

        // Update active button for light theme
        document.querySelectorAll('.theme-option').forEach(btn => {
            if (btn.getAttribute('data-theme') === 'light') {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });

        // Try to create the setting
        saveThemeToDatabase('light').catch(() => {
            // Ignore errors
        });
    }
}

/**
 * Save theme preference to database
 * FIXED: Now sends data in correct format expected by settings.php
 */
async function saveThemeToDatabase(theme) {
    try {
        const csrfElement = document.getElementById('csrf-token-data');
        const csrfToken = csrfElement ? csrfElement.dataset.csrfToken : '';

        if (!csrfToken) {
            //console.error('CSRF token not found');
            return;
        }

        const response = await apiRequest('settings.php', 'POST', {
            key: 'user_theme_preference',
            value: theme,
            csrf_token: csrfToken
        });

        if (!response.success) {
            //console.error('Failed to save theme preference:', response.message);
        }
    } catch (error) {
        //console.error('Error saving theme:', error);
    }
}

/* ===========================
       🎯 INITIALIZATION
       =========================== */
function initializeEventListeners() {
    //console.log('Initializing event listeners...');
    //console.log('Menu toggle element:', elements.menuToggle);
    //console.log('Sidebar element:', elements.sidebar);

    // Navigation items
    document.querySelectorAll('#sidebar button[data-filter]').forEach(item => {
        item.addEventListener('click', () => {
            const sectionName = item.dataset.filter;

            // Update active state
            document.querySelectorAll('#sidebar button').forEach(btn => {
                btn.classList.remove('active');
            });
            item.classList.add('active');

            // Show appropriate section
            if (sectionName === 'reports') {
                showReportsView();
            } else if (sectionName === 'legacy-debtors') {
                showLegacyDebtorsView();
            } else {
                showSection(sectionName);
            }

            if (window.innerWidth <= 760 && elements.sidebar) {
                elements.sidebar.classList.remove('mobile-open');
            }
        });
    });

    // Logout button
    document.getElementById('logoutBtnSidebar').addEventListener('click', () => {
        if (confirm('Are you sure you want to logout?')) {
            // Use existing logout handler
            if (typeof window.logoutHandler === 'object') {
                window.logoutHandler.logout();
            } else {
                // Fallback to direct redirect
                window.location.href = 'index.php?logout=true';
            }
        }
    });

    // Staff form submission
    if (elements.staffForm) {
        elements.staffForm.addEventListener('submit', handleUserFormSubmit);
    }

    // Add fund form submission
    if (elements.addFundForm) {
        elements.addFundForm.addEventListener('submit', handleAddFundFormSubmit);
    }

    // Password strength checking
    const staffPwd = document.getElementById('staffPwd');
    if (staffPwd) {
        staffPwd.addEventListener('input', () => {
            checkPasswordStrength(staffPwd.value);
        });
    }

    // Cancel edit button
    const cancelEditBtn = document.getElementById('cancelEditBtn');
    if (cancelEditBtn) {
        cancelEditBtn.addEventListener('click', cancelEditMode);
    }

    // ==========================================
    // FIX: Prevent Double Notifications on User Filters
    // ==========================================

    // Filter button (Loans section)
    document.getElementById('filterBtn').addEventListener('click', () => {
        currentPage = 1;
        renderLoans();
        // Note: renderLoans handles its own notification via window.loansRenderedOnce
    });

    // Clear filters button (Loans section)
    document.getElementById('clearFilters').addEventListener('click', () => {
        document.getElementById('filterStatus').value = '';
        document.getElementById('filterLoanType').value = '';
        document.getElementById('searchInput').value = '';
        currentPage = 1;
        renderLoans();
        // Note: renderLoans handles its own notification
    });

    // Refresh button (Loans section)
    document.getElementById('refreshBtn').addEventListener('click', async () => {
        // Force reload of the page data to simulate a fresh refresh
        window.loansRenderedOnce = false;
        await renderLoans('all', true); // Force refresh from API
        await updateStats();
    });

    // Export button
    document.getElementById('exportBtn').addEventListener('click', () => {
        apiRequest('loan_applications.php', 'GET').then(response => {
            if (response.success && response.data) {
                const exportData = response.data.map(loan => ({
                    'Application Date': loan.application_date || '',
                    'Full Name': loan.full_name || '',
                    'ID Number': loan.id_number || '',
                    'PF Number': loan.pf_number || '',
                    'Email': loan.email || '',
                    'Loan Type': loan.loan_type || '',
                    'Loan Amount': loan.loan_amount || 0,
                    'Status': loan.status || '',
                    'Feedback': loan.feedback || '',
                    'Last Updated': loan.updated_at || ''
                }));
                exportToCSV(exportData,
                    `loans_export_${new Date().toISOString().split('T')[0]}.csv`
                );
            } else {
                showToast('No data to export', 'warning');
            }
        }).catch(error => {
            showToast('Error exporting loans', 'error');
        });
    });

    // Search input with debounce (Loans section)
    document.getElementById('searchInput').addEventListener('input', debounce(() => {
        currentPage = 1;
        renderLoans();
    }, 300));

    // Search input duplicate check (Loans section) - ensuring single listener via ID selection
    // (Note: The listener above is attached directly, which is fine)

    // Settings - Save amortization rate
    const saveAmortBtn = document.getElementById('saveAmort');
    if (saveAmortBtn) {
        saveAmortBtn.addEventListener('click', () => {
            const rateInput = document.getElementById('amortRate');
            const rate = parseFloat(rateInput.value);
            const statusEl = document.getElementById('amortStatus');

            if (isNaN(rate) || rate < 0 || rate > 100) {
                showToast('Please enter a valid rate between 0 and 100', 'error');
                return;
            }

            showLoading(true);
            apiRequest('settings.php', 'POST', {
                    key: 'amortization_rate', // FIXED: Use 'key' not 'setting_key'
                    value: rate // FIXED: Use 'value' not 'setting_value'
                })
                .then(response => {
                    if (response.success) {
                        if (statusEl) statusEl.textContent =
                            `Current: ${rate.toFixed(1)}%`;
                        showToast('Interest rate updated', 'success');
                    } else {
                        showToast('Failed to update interest rate', 'error');
                    }
                })
                .catch(error => {
                    showToast('Error updating interest rate', 'error');
                })
                .finally(() => {
                    showLoading(false);
                });
        });
    }

    // Activity feed refresh
    const refreshActivityBtn = document.getElementById('refreshActivityBtn');
    if (refreshActivityBtn) {
        refreshActivityBtn.addEventListener('click', () => {
            renderActivityFeed();
            showToast('Activity feed refreshed', 'success');
        });
    }

    // Clear Activity Button
    const clearActivityBtn = document.getElementById('clearActivityBtn');
    if (clearActivityBtn) {
        clearActivityBtn.addEventListener('click', async () => {
            if (!confirm('Are you sure you want to clear all activity logs? This cannot be undone.')) {
                return;
            }

            try {
                showLoading(true);
                const response = await apiRequest('clear-data.php', 'POST', {
                    csrf_token: window.CSRF_TOKEN,
                    data_type: 'logs'
                });

                if (response.success) {
                    showToast('Activity logs cleared successfully', 'success');
                    // Refresh activity feed if visible
                    if (document.getElementById('activitySection').classList.contains('hidden') ===
                        false) {
                        renderActivityFeed();
                    }
                    // Refresh recent activity on dashboard
                    updateRecentActivity();
                } else {
                    showToast('Failed to clear logs: ' + (response.message || 'Unknown error'),
                        'error');
                }
            } catch (error) {
                showToast('Error clearing logs: ' + error.message, 'error');
            } finally {
                showLoading(false);
            }
        });
    }

    // Data Management buttons
    const backupDataBtn = document.getElementById('backupDataBtn');
    if (backupDataBtn) {
        backupDataBtn.addEventListener('click', async () => {
            if (!confirm('Download a complete database backup?')) return;

            try {
                showLoading(true);
                showToast('Preparing backup...', 'info');

                // Make AJAX request with proper headers
                const response = await fetch('backup.php?t=' + Date.now(), {
                    method: 'GET',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                });

                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }

                // Get response as text to ensure we get raw response
                const backupData = await response.text();

                // Verify it's valid JSON
                try {
                    JSON.parse(backupData);
                } catch (e) {
                    throw new Error('Invalid JSON response from server');
                }

                // Create blob and download
                const blob = new Blob([backupData], {
                    type: 'application/json'
                });
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.download =
                    `system_backup_${new Date().toISOString().replace(/[:.]/g, '-').slice(0, -5)}`;
                document.body.appendChild(link);
                link.click();
                URL.revokeObjectURL(url);

                showToast('Backup downloaded successfully!', 'success');
            } catch (error) {
                //console.error('Backup error:', error);
                showToast('Failed to download backup: ' + error.message, 'error');
            } finally {
                showLoading(false);
            }
        });
    }

    // Restore data button
    const restoreDataBtn = document.getElementById('restoreDataBtn');
    if (restoreDataBtn) {
        restoreDataBtn.addEventListener('click', restoreFromBackup);
    }

    // Activity Monitoring buttons
    const viewLogsBtn = document.getElementById('viewLogsBtn');
    if (viewLogsBtn) {
        viewLogsBtn.addEventListener('click', async () => {
            try {
                showLoading(true);
                const response = await apiRequest('activity-logs.php?page=1&limit=50', 'GET');

                if (response.success) {
                    showLogsModal(response.data, response.pagination);
                } else {
                    showToast('Failed to load activity logs', 'error');
                }
            } catch (error) {
                showToast('Error loading activity logs: ' + error.message, 'error');
            } finally {
                showLoading(false);
            }
        });
    }

    const clearLogsBtn = document.getElementById('clearLogsBtn');
    if (clearLogsBtn) {
        clearLogsBtn.addEventListener('click', async () => {
            if (!confirm(
                    'Are you sure you want to clear all activity logs? This cannot be undone.')) {
                return;
            }

            try {
                showLoading(true);
                const response = await apiRequest('clear-data.php', 'POST', {
                    csrf_token: window.CSRF_TOKEN,
                    data_type: 'logs'
                });

                if (response.success) {
                    showToast('Activity logs cleared successfully', 'success');
                    // Refresh activity feed if visible
                    if (document.getElementById('activitySection').classList.contains('hidden') ===
                        false) {
                        renderActivityFeed();
                    }
                } else {
                    showToast('Failed to clear logs: ' + (response.message || 'Unknown error'),
                        'error');
                }
            } catch (error) {
                showToast('Error clearing logs: ' + error.message, 'error');
            } finally {
                showLoading(false);
            }
        });
    }

    // Danger Zone buttons
    const clearStaffBtn = document.getElementById('clearStaffBtn');
    if (clearStaffBtn) {
        clearStaffBtn.addEventListener('click', async () => {
            if (!confirm(
                    '<i data-lucide="alert-triangle" class="icon-sm"></i> DANGER: This will delete ALL staff members (admins, cashiers, accountants) and their user accounts. This action cannot be undone!\n\nType "DELETE ALL STAFF" to confirm:'
                )) {
                return;
            }

            const confirmation = prompt('Please type "DELETE ALL STAFF" to confirm:');
            if (confirmation !== 'DELETE ALL STAFF') {
                showToast('Action cancelled', 'info');
                return;
            }

            try {
                showLoading(true);
                const response = await apiRequest('clear-data.php', 'POST', {
                    csrf_token: window.CSRF_TOKEN,
                    data_type: 'staff'
                });

                if (response.success) {
                    showToast('All staff data cleared successfully', 'success');
                    // Refresh user list if visible
                    if (document.getElementById('staffSection').classList.contains('hidden') ===
                        false) {
                        usersCache = null;
                        await renderUsers();
                    }
                } else {
                    showToast('Failed to clear staff: ' + (response.message || 'Unknown error'),
                        'error');
                }
            } catch (error) {
                showToast('Error clearing staff: ' + error.message, 'error');
            } finally {
                showLoading(false);
            }
        });
    }

    const clearLoansBtn = document.getElementById('clearLoansBtn');
    if (clearLoansBtn) {
        clearLoansBtn.addEventListener('click', async () => {
            if (!confirm(
                    '<i data-lucide="alert-triangle" class="icon-sm"></i> DANGER: This will delete ALL loan applications, payments, and payment schedules. This action cannot be undone!\n\nType "DELETE ALL LOANS" to confirm:'
                )) {
                return;
            }

            const confirmation = prompt('Please type "DELETE ALL LOANS" to confirm:');
            if (confirmation !== 'DELETE ALL LOANS') {
                showToast('Action cancelled', 'info');
                return;
            }

            try {
                showLoading(true);
                const response = await apiRequest('clear-data.php', 'POST', {
                    csrf_token: window.CSRF_TOKEN,
                    data_type: 'loans'
                });

                if (response.success) {
                    showToast('All loan data cleared successfully', 'success');
                    // Refresh loan list if visible
                    if (document.getElementById('loansSection').classList.contains('hidden') ===
                        false) {
                        renderLoans();
                    }
                    // Update dashboard stats
                    updateStats();
                } else {
                    showToast('Failed to clear loans: ' + (response.message || 'Unknown error'),
                        'error');
                }
            } catch (error) {
                showToast('Error clearing loans: ' + error.message, 'error');
            } finally {
                showLoading(false);
            }
        });
    }

    const clearSystemBtn = document.getElementById('clearSystemBtn');
    if (clearSystemBtn) {
        clearSystemBtn.addEventListener('click', async () => {
            if (!confirm(
                    '☢️ CRITICAL DANGER: This will delete ALL system data except system settings! This includes all users, loans, payments, and activity logs. This action cannot be undone!\n\nType "NUKE SYSTEM" to confirm:'
                )) {
                return;
            }

            const confirmation = prompt('Please type "NUKE SYSTEM" to confirm:');
            if (confirmation !== 'NUKE SYSTEM') {
                showToast('Action cancelled', 'info');
                return;
            }

            try {
                showLoading(true);
                const response = await apiRequest('clear-data.php', 'POST', {
                    csrf_token: window.CSRF_TOKEN,
                    data_type: 'system'
                });

                if (response.success) {
                    showToast('<i data-lucide="alert-triangle" class="icon-sm"></i> All system data cleared successfully. You will be logged out.',
                        'warning');
                    // Force logout after clearing system
                    setTimeout(() => {
                        window.location.href =
                            'index.php?logout=true&message=system_cleared';
                    }, 2000);
                } else {
                    showToast('Failed to clear system: ' + (response.message || 'Unknown error'),
                        'error');
                }
            } catch (error) {
                showToast('Error clearing system: ' + error.message, 'error');
            } finally {
                showLoading(false);
            }
        });
    }

    // Make sure the restore function is globally available for inline onclick
    window.restoreFromBackup = restoreFromBackup;

    // Add event listeners for export buttons
    document.getElementById('exportOverallSummaryBtn').addEventListener('click', exportOverallSummary);
    document.getElementById('exportMortgageLoansBtn').addEventListener('click', exportMortgageLoans);
    document.getElementById('exportCarLoansBtn').addEventListener('click', exportCarLoans);
    document.getElementById('exportPaymentSummaryBtn').addEventListener('click', exportPaymentSummary);
    document.getElementById('exportCompleteReportBtn').addEventListener('click', exportFinancialReport);

    // Initialize password change form
    initializePasswordChangeForm();
}

// Add this to your initialization code to handle password change form
function initializePasswordChangeForm() {
    const passwordForm = document.getElementById('passwordForm');
    if (!passwordForm) return;

    const newPasswordInput = document.getElementById('new_password');
    const confirmPasswordInput = document.getElementById('confirm_password');

    // Real-time password validation
    if (newPasswordInput) {
        newPasswordInput.addEventListener('input', function() {
            const password = this.value;
            validatePasswordStrength(password);

            // Check if passwords match
            if (confirmPasswordInput && confirmPasswordInput.value) {
                checkPasswordMatch();
            }
        });
    }

    // Confirm password validation
    if (confirmPasswordInput) {
        confirmPasswordInput.addEventListener('input', checkPasswordMatch);
    }

    // Form submission
    passwordForm.addEventListener('submit', function(e) {
        e.preventDefault();

        const currentPassword = document.getElementById('current_password').value;
        const newPassword = newPasswordInput.value;
        const confirmPassword = confirmPasswordInput.value;

        // Validation
        if (!currentPassword) {
            showToast('Please enter your current password', 'error');
            return;
        }

        if (newPassword.length < 8) {
            showToast('New password must be at least 8 characters', 'error');
            return;
        }

        if (newPassword !== confirmPassword) {
            showToast('Passwords do not match', 'error');
            return;
        }

        if (currentPassword === newPassword) {
            showToast('New password must be different from current password', 'warning');
            return;
        }

        // Submit form normally since it's already set up with PHP
        passwordForm.submit();
    });

    function validatePasswordStrength(password) {
        const strengthBar = document.getElementById('passwordStrength');
        if (!strengthBar) return;

        let strength = 0;
        let feedback = [];

        // Check length
        if (password.length >= 8) {
            strength += 25;
        } else {
            feedback.push('At least 8 characters');
        }

        // Check for lowercase
        if (/[a-z]/.test(password)) {
            strength += 25;
        } else {
            feedback.push('One lowercase letter');
        }

        // Check for uppercase
        if (/[A-Z]/.test(password)) {
            strength += 25;
        }

        // Check for numbers
        if (/[0-9]/.test(password)) {
            strength += 25;
        } else {
            feedback.push('One number');
        }

        // Visual feedback
        let color = '#dc3545'; // Red
        let strengthText = 'Weak';

        if (strength >= 75) {
            color = '#28a745'; // Green
            strengthText = 'Strong';
        } else if (strength >= 50) {
            color = '#ffc107'; // Yellow
            strengthText = 'Medium';
        }

        strengthBar.innerHTML = `
        <div style="background: #e0e0e0; height: 5px; border-radius: 3px; margin-top: 5px;">
            <div style="background: ${color}; height: 100%; width: ${strength}%; border-radius: 3px; transition: all 0.3s;"></div>
        </div>
        <small style="color: ${color}; font-size: 12px;">${strengthText}${feedback.length > 0 ? ' - Needs: ' + feedback.join(', ') : ''}</small>
    `;
    }

    function checkPasswordMatch() {
        const newPassword = newPasswordInput.value;
        const confirmPassword = confirmPasswordInput.value;

        if (confirmPassword) {
            if (newPassword === confirmPassword) {
                confirmPasswordInput.style.borderColor = '#28a745';
            } else {
                confirmPasswordInput.style.borderColor = '#dc3545';
            }
        } else {
            confirmPasswordInput.style.borderColor = '';
        }
    }
}



// Apply for loan button
const applyLoanBtn = document.querySelector('.apply-loan-btn');
if (applyLoanBtn) {
    applyLoanBtn.addEventListener('click', function(e) {
        e.preventDefault();
        applyForLoan();
    });
}

function applyForLoan() {
    // Store original portal info
    const originalPortal = 'admin';

    // Log the switch
    //console.log("Admin applying for loan");

    // Show confirmation dialog
    if (!confirm("You're about to switch to the loan application portal. Continue?")) {
        return;
    }

    // Show loading state
    showToast('Switching to loanee portal...', 'info');

    // Create loading overlay
    const loadingOverlay = document.createElement('div');
    loadingOverlay.id = 'portal-switch-overlay';
    loadingOverlay.style.cssText =
        'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(255,255,255,0.9);z-index:9999;display:flex;justify-content:center;align-items:center;flex-direction:column;';

    const spinner = document.createElement('div');
    spinner.style.cssText =
        'border:5px solid #f3f3f3;border-top:5px solid #4a6b4a;border-radius:50%;width:50px;height:50px;animation:spin 1s linear infinite;';

    const loadingText = document.createElement('p');
    loadingText.textContent = 'Switching to loanee portal...';
    loadingText.style.cssText = 'margin-top:20px;font-weight:bold;color:#4a6b4a;';

    loadingOverlay.appendChild(spinner);
    loadingOverlay.appendChild(loadingText);
    document.body.appendChild(loadingOverlay);

    // Redirect to loanee portal
    setTimeout(() => {
        window.location.href = 'loanee.php?from=admin&action=switch_portal';
    }, 500);
}

/* ===========================
       <i data-lucide="bar-chart-3" class="icon-sm"></i> API REQUEST HANDLER
       =========================== */

/**
 * Make API request with proper error handling
 */
async function apiRequest(endpoint, method = 'GET', data = null, retries = 2) {
    const cleanEndpoint = endpoint.replace(/^\/+/, '');
    const url = `${API_BASE_URL}/${cleanEndpoint}`.replace(/([^:]\/)\/+/g, "$1");

    const headers = {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
    };

    // Get CSRF token
    const csrfElement = document.getElementById('csrf-token-data');
    const csrfToken = csrfElement ? csrfElement.dataset.csrfToken : window.CSRF_TOKEN;

    if (csrfToken && method !== 'GET' && data) {
        data.csrf_token = csrfToken;
    }

    const requestOptions = {
        method,
        headers,
        credentials: 'include'
    };

    if (data && method !== 'GET') {
        requestOptions.body = JSON.stringify(data);
    }

    try {
        const response = await fetch(url, requestOptions);

        // Handle authentication errors
        if (response.status === 401 || response.status === 403) {
            // Clone response to safely read it multiple times if needed
            const responseClone = response.clone();
            const responseText = await responseClone.text();

            // Check if session expired (redirected to login page)
            if (responseText.trim().startsWith('<!DOCTYPE')) {
                showToast('Session expired. Redirecting to login...', 'error');
                setTimeout(() => {
                    window.location.href = 'index.php?error=session_expired';
                }, 2000);
                throw new Error('Session expired');
            }
        }

        // FIX: Clone the response before reading to prevent "body stream already read" error
        const responseClone = response.clone();
        
        // Parse JSON response
        let result;
        try {
            result = await responseClone.json();
        } catch (parseError) {
            // If JSON parsing fails, try to get the raw text for debugging
            const rawText = await response.text();
            console.error('Raw response:', rawText);
            throw new Error(`Invalid JSON response: ${parseError.message}`);
        }

        // Handle HTTP errors
        if (!response.ok) {
            // Retry on server errors
            if (response.status >= 500 && retries > 0) {
                await new Promise(resolve => setTimeout(resolve, (3 - retries) * 1000));
                return apiRequest(endpoint, method, data, retries - 1);
            }
            throw new Error(result.message || `HTTP ${response.status}`);
        }

        return result;
    } catch (error) {
        // Handle network errors
        if (error.name === 'AbortError') {
            throw new Error('Request timeout');
        }

        // Retry on network errors
        if (retries > 0 && !error.message.includes('Session expired')) {
            await new Promise(resolve => setTimeout(resolve, (3 - retries) * 1000));
            return apiRequest(endpoint, method, data, retries - 1);
        }

        throw error;
    }
}


/**
 * Initialize hamburger menu with proper event handling
 */
function initializeHamburgerMenu() {
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');

    if (!menuToggle || !sidebar) {
        console.error('Hamburger menu elements not found');
        return;
    }

    // Remove any existing listeners by cloning the button
    const newMenuToggle = menuToggle.cloneNode(true);
    menuToggle.parentNode.replaceChild(newMenuToggle, menuToggle);

    // Add click event listener
    newMenuToggle.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();

        const isMobile = window.innerWidth <= 760;

        if (isMobile) {
            sidebar.classList.toggle('mobile-open');
            if (sidebar.classList.contains('mobile-open')) {
                addMobileOverlay();
            } else {
                removeMobileOverlay();
            }
        } else {
            sidebar.classList.toggle('collapsed');
        }
    });

    // Close mobile menu when clicking outside
    document.addEventListener('click', function(e) {
        const isMobile = window.innerWidth <= 760;

        if (isMobile &&
            sidebar.classList.contains('mobile-open') &&
            !sidebar.contains(e.target) &&
            !newMenuToggle.contains(e.target)) {

            sidebar.classList.remove('mobile-open');
            removeMobileOverlay();
        }
    });

    // Handle window resize
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            const isMobile = window.innerWidth <= 760;

            if (!isMobile) {
                sidebar.classList.remove('mobile-open');
                removeMobileOverlay();
            }
        }, 250);
    });
}

function addMobileOverlay() {
    // Check if overlay already exists
    let overlay = document.getElementById('mobile-menu-overlay');

    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'mobile-menu-overlay';
        overlay.style.cssText = `
        position: fixed;
        top: 70px;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 999;
        opacity: 0;
        transition: opacity 0.3s;
    `;
        document.body.appendChild(overlay);

        // Trigger reflow for transition
        overlay.offsetHeight;
        overlay.style.opacity = '1';

        // Close menu when clicking overlay
        overlay.addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            if (sidebar) {
                sidebar.classList.remove('mobile-open');
            }
            removeMobileOverlay();
        });
    }
}

function removeMobileOverlay() {
    const overlay = document.getElementById('mobile-menu-overlay');
    if (overlay) {
        overlay.style.opacity = '0';
        setTimeout(() => {
            overlay.remove();
        }, 300);
    }
}



/* ===========================
       📤 IMPORT/EXPORT HELPERS
       =========================== */

/**
 * Load external script dynamically
 */
function loadScript(src) {
    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = src;
        script.onload = resolve;
        script.onerror = reject;
        document.head.appendChild(script);
    });
}

/**
 * Download CSV template for user import
 */
function downloadImportTemplate() {
    const template = [
        ['Full Name', 'Email', 'ID Number', 'PF Number'],
        ['John Doe', 'john.doe@example.com', '12345678', '123456789'],
        ['Jane Smith', 'jane.smith@example.com', '87654321', '987654321']
    ];

    const csv = template.map(row => row.join(',')).join('\n');
    const blob = new Blob([csv], {
        type: 'text/csv;charset=utf-8;'
    });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);

    link.href = url;
    link.download = 'user_import_template.csv';
    link.click();

    URL.revokeObjectURL(url);
    showToast('Template downloaded successfully', 'success');
}

/**
 * Parse table data from text (for PDF parsing)
 */
function parseTableFromText(text) {
    const users = [];
    const lines = text.split('\n').filter(line => line.trim());

    // Simple pattern matching for user data
    const namePattern = /([A-Z][a-z]+ [A-Z][a-z]+)/g;
    const emailPattern = /([a-zA-Z0-9._-]+@[a-zA-Z0-9._-]+\.[a-zA-Z0-9_-]+)/g;
    const idPattern = /\b(\d{7,8})\b/g;
    const pfPattern = /\b(\d{9,11})\b/g;

    for (const line of lines) {
        const names = line.match(namePattern);
        const emails = line.match(emailPattern);
        const ids = line.match(idPattern);
        const pfs = line.match(pfPattern);

        if (names && ids && pfs) {
            users.push({
                full_name: names[0],
                email: emails ? emails[0] : '',
                id_number: ids[0],
                pf_number: pfs[0]
            });
        }
    }

    return users;
}

/**
 * Parse CSV file
 */
async function parseCSV(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();

        reader.onload = (e) => {
            try {
                const text = e.target.result;
                const lines = text.split('\n').filter(line => line.trim());

                if (lines.length < 2) {
                    reject(new Error('CSV file is empty or invalid'));
                    return;
                }

                // Parse header
                const headers = lines[0].split(',').map(h => h.trim().toLowerCase());

                // Find column indices
                const nameIndex = headers.findIndex(h => h.includes('name'));
                const emailIndex = headers.findIndex(h => h.includes('email'));
                const idIndex = headers.findIndex(h => h.includes('id') && !h.includes(
                    'pf'));
                const pfIndex = headers.findIndex(h => h.includes('pf'));

                if (nameIndex === -1 || idIndex === -1 || pfIndex === -1) {
                    reject(new Error(
                        'CSV must contain columns for: Name, ID Number, and PF Number'
                    ));
                    return;
                }

                // Parse data rows
                const users = [];
                for (let i = 1; i < lines.length; i++) {
                    const values = lines[i].split(',').map(v => v.trim());

                    if (values.length > nameIndex && values[nameIndex]) {
                        users.push({
                            full_name: values[nameIndex] || '',
                            email: emailIndex !== -1 ? values[emailIndex] : '',
                            id_number: values[idIndex] || '',
                            pf_number: values[pfIndex] || ''
                        });
                    }
                }

                resolve(users);
            } catch (error) {
                reject(error);
            }
        };

        reader.onerror = () => reject(new Error('Failed to read CSV file'));
        reader.readAsText(file);
    });
}

/**
 * Parse Excel file
 */
async function parseExcel(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();

        reader.onload = async (e) => {
            try {
                // Load XLSX library if not already loaded
                if (typeof XLSX === 'undefined') {
                    await loadScript(
                        'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js'
                    );
                }

                const data = new Uint8Array(e.target.result);
                const workbook = XLSX.read(data, {
                    type: 'array'
                });

                // Get first sheet
                const sheetName = workbook.SheetNames[0];
                const worksheet = workbook.Sheets[sheetName];

                // Convert to JSON
                const jsonData = XLSX.utils.sheet_to_json(worksheet, {
                    header: 1
                });

                if (jsonData.length < 2) {
                    reject(new Error('Excel file is empty or invalid'));
                    return;
                }

                // Parse header
                const headers = jsonData[0].map(h => String(h).trim()
                    .toLowerCase());

                // Find column indices
                const nameIndex = headers.findIndex(h => h.includes('name'));
                const emailIndex = headers.findIndex(h => h.includes('email'));
                const idIndex = headers.findIndex(h => h.includes('id') && !h
                    .includes(
                        'pf'));
                const pfIndex = headers.findIndex(h => h.includes('pf'));

                if (nameIndex === -1 || idIndex === -1 || pfIndex === -1) {
                    reject(new Error(
                        'Excel must contain columns for: Name, ID Number, and PF Number'
                    ));
                    return;
                }

                // Parse data rows
                const users = [];
                for (let i = 1; i < jsonData.length; i++) {
                    const row = jsonData[i];

                    if (row[nameIndex]) {
                        users.push({
                            full_name: String(row[nameIndex] || '').trim(),
                            email: emailIndex !== -1 ? String(row[
                                    emailIndex] || '')
                                .trim() : '',
                            id_number: String(row[idIndex] || '').trim(),
                            pf_number: String(row[pfIndex] || '').trim()
                        });
                    }
                }

                resolve(users);
            } catch (error) {
                reject(error);
            }
        };

        reader.onerror = () => reject(new Error('Failed to read Excel file'));
        reader.readAsArrayBuffer(file);
    });
}

/**
 * Parse PDF file
 */
async function parsePDF(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();

        reader.onload = async (e) => {
            try {
                // Load PDF.js library if not already loaded
                if (typeof pdfjsLib === 'undefined') {
                    await loadScript(
                        'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js'
                    );
                    pdfjsLib.GlobalWorkerOptions.workerSrc =
                        'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
                }

                const typedarray = new Uint8Array(e.target.result);
                const pdf = await pdfjsLib.getDocument(typedarray).promise;

                let fullText = '';

                // Extract text from all pages
                for (let i = 1; i <= pdf.numPages; i++) {
                    const page = await pdf.getPage(i);
                    const textContent = await page.getTextContent();
                    const pageText = textContent.items.map(item => item.str).join(
                        ' ');
                    fullText += pageText + '\n';
                }

                // Parse text content (simple table detection)
                const users = parseTableFromText(fullText);

                if (users.length === 0) {
                    reject(new Error(
                        'No valid user data found in PDF. Please ensure PDF contains a table with Name, Email, ID Number, and PF Number columns.'
                    ));
                    return;
                }

                resolve(users);
            } catch (error) {
                reject(error);
            }
        };

        reader.onerror = () => reject(new Error('Failed to read PDF file'));
        reader.readAsArrayBuffer(file);
    });
}

/**
 * Handle file import
 */
async function handleFileImport(event) {
    const file = event.target.files[0];
    if (!file) return;

    const fileExtension = file.name.split('.').pop().toLowerCase();

    showLoading(true);

    try {
        let users = [];

        if (fileExtension === 'csv') {
            users = await parseCSV(file);
        } else if (fileExtension === 'xlsx' || fileExtension === 'xls') {
            users = await parseExcel(file);
        } else if (fileExtension === 'pdf') {
            users = await parsePDF(file);
        } else {
            throw new Error('Unsupported file format. Please use CSV, Excel, or PDF.');
        }

        if (users.length === 0) {
            throw new Error('No valid user data found in file');
        }

        // Show preview modal
        showImportPreview(users);

    } catch (error) {
        showToast('Error reading file: ' + error.message, 'error');
    } finally {
        showLoading(false);
        // Reset file input
        event.target.value = '';
    }
}

/**
 * Show import preview modal
 */
function showImportPreview(users) {
    const validUsers = users.filter(u => u.full_name && u.id_number && u.pf_number);
    const invalidUsers = users.length - validUsers.length;

    const modal = document.createElement('div');
    modal.className = 'modal show';
    modal.style.display = 'flex';

    modal.innerHTML = `
    <div class="modal-content" style="max-width: 900px;">
        <div class="modal-header">
            <h2><i data-lucide="download" class="icon-sm"></i> Import Users Preview</h2>
            <button class="close-modal">&times;</button>
        </div>
        <div class="modal-body">
            <div class="import-stats">
                <div class="import-stat-card success">
                    <div class="import-stat-number">${validUsers.length}</div>
                    <div class="import-stat-label">Valid Users</div>
                </div>
                <div class="import-stat-card ${invalidUsers > 0 ? 'warning' : ''}">
                    <div class="import-stat-number">${invalidUsers}</div>
                    <div class="import-stat-label">Invalid Rows</div>
                </div>
                <div class="import-stat-card">
                    <div class="import-stat-number">${users.length}</div>
                    <div class="import-stat-label">Total Rows</div>
                </div>
            </div>

            <p class="small" style="color: var(--text-secondary); margin-bottom: 15px;">
                <strong>Note:</strong> Default password will be set to ID Number. Default role will be Loanee. 
                You can change roles after import.
            </p>

            <div class="import-preview-table">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>ID Number</th>
                            <th>PF Number</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${users.map((user, index) => {
                            const isValid = user.full_name && user.id_number && user.pf_number;
                            const validClass = isValid ? '' : 'style="background: #ffebee;"';
                            const statusIcon = isValid ? '<i data-lucide="check-circle" class="icon-sm"></i>' : '<i data-lucide="x-circle" class="icon-sm"></i>';
                            const statusText = isValid ? 'Valid' : 'Invalid';
                            
                            return `
                                <tr ${validClass}>
                                    <td>${index + 1}</td>
                                    <td>${escapeHtml(user.full_name || '-')}</td>
                                    <td>${escapeHtml(user.email || 'N/A')}</td>
                                    <td>${escapeHtml(user.id_number || '-')}</td>
                                    <td>${escapeHtml(user.pf_number || '-')}</td>
                                    <td>${statusIcon} ${statusText}</td>
                                </tr>
                            `;
                        }).join('')}
                    </tbody>
                </table>
            </div>

            ${invalidUsers > 0 ? `
                <div class="error-list">
                    <strong style="color: var(--danger);"><i data-lucide="alert-triangle" class="icon-sm"></i> ${invalidUsers} Invalid Rows</strong>
                    <p class="small">Rows with missing Name, ID Number, or PF Number will be skipped.</p>
                </div>
            ` : ''}

            <div id="importProgress" style="display: none;">
                <div class="progress-bar">
                    <div class="progress-fill" id="importProgressBar" style="width: 0%;">0%</div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-secondary cancel-import">Cancel</button>
            <button class="btn-primary confirm-import" ${validUsers.length === 0 ? 'disabled' : ''}>
                Import ${validUsers.length} User${validUsers.length !== 1 ? 's' : ''}
            </button>
        </div>
    </div>
`;

    document.body.appendChild(modal);

    // Event listeners
    modal.querySelector('.close-modal').addEventListener('click', () => modal.remove());
    modal.querySelector('.cancel-import').addEventListener('click', () => modal.remove());
    modal.querySelector('.confirm-import').addEventListener('click', () => {
        performImport(validUsers, modal);
    });

    // Close on outside click
    modal.addEventListener('click', (e) => {
        if (e.target === modal) {
            modal.remove();
        }
    });
}

/**
 * Perform user import
 */
async function performImport(users, modal) {
    const confirmBtn = modal.querySelector('.confirm-import');
    const cancelBtn = modal.querySelector('.cancel-import');
    const progressDiv = modal.querySelector('#importProgress');
    const progressBar = modal.querySelector('#importProgressBar');

    // Disable buttons
    confirmBtn.disabled = true;
    cancelBtn.disabled = true;

    // Show progress
    progressDiv.style.display = 'block';

    try {
        // Get CSRF token
        const csrfToken = document.querySelector('#csrf-token-data')?.dataset.csrfToken ||
            window.CSRF_TOKEN || '';

        if (!csrfToken) {
            throw new Error('CSRF token not found');
        }

        // Update progress
        progressBar.style.width = '30%';
        progressBar.textContent = '30%';

        // Send import request
        const response = await apiRequest('import-users.php', 'POST', {
            users: users,
            csrf_token: csrfToken
        });

        // Update progress
        progressBar.style.width = '100%';
        progressBar.textContent = '100%';

        // Handle response
        if (response.success) {
            showToast(
                `Successfully imported ${response.imported} user(s). ${response.skipped} skipped.`,
                'success'
            );

            // Show detailed results if there were errors
            if (response.errors && response.errors.length > 0) {
                const errorModal = document.createElement('div');
                errorModal.className = 'modal show';
                errorModal.style.display = 'flex';

                errorModal.innerHTML = `
                <div class="modal-content">
                    <div class="modal-header">
                        <h3>Import Results</h3>
                        <button class="close-modal">&times;</button>
                    </div>
                    <div class="modal-body">
                        <div class="import-stats">
                            <div class="import-stat-card success">
                                <div class="import-stat-number">${response.imported}</div>
                                <div class="import-stat-label">Imported</div>
                            </div>
                            <div class="import-stat-card warning">
                                <div class="import-stat-number">${response.skipped}</div>
                                <div class="import-stat-label">Skipped</div>
                            </div>
                        </div>
                        </div>
                        <div class="error-list">
                            <strong>Errors/Warnings:</strong>
                            ${response.errors.map(err => `
                                <div class="error-item">${escapeHtml(err)}</div>
                            `).join('')}
                        </div>
                    </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn-primary close-results">Close</button>
                    </div>
                </div>
            `;

                document.body.appendChild(errorModal);

                errorModal.querySelector('.close-modal').addEventListener('click', () => {
                    errorModal.remove();
                });

                errorModal.querySelector('.close-results').addEventListener('click', () => {
                    errorModal.remove();
                });
            }

            // Refresh user list
            usersCache = null;
            await renderUsers();

            // Close preview modal
            setTimeout(() => {
                modal.remove();
            }, 1500);

        } else {
            throw new Error(response.message || 'Import failed');
        }

    } catch (error) {
        showToast('Import failed: ' + error.message, 'error');
        confirmBtn.disabled = false;
        cancelBtn.disabled = false;
        progressDiv.style.display = 'none';
    }
}

/**
 * Initialize import functionality
 */
function initializeImportFunctionality() {
    const importBtn = document.getElementById('importUsersBtn');
    const downloadTemplateBtn = document.getElementById('downloadTemplateBtn');
    const fileInput = document.getElementById('importFileInput');

    if (importBtn) {
        importBtn.addEventListener('click', () => {
            fileInput.click();
        });
    }

    if (downloadTemplateBtn) {
        downloadTemplateBtn.addEventListener('click', downloadImportTemplate);
    }

    if (fileInput) {
        fileInput.addEventListener('change', handleFileImport);
    }
}

/* ===========================
       <i data-lucide="bar-chart-3" class="icon-sm"></i> FINANCIAL REPORTS
       =========================== */

async function getAllLoans() {
    // Primary: Use pre-loaded server-side data (fast and reliable)
    if (window.initialLoansData && window.initialLoansData.length > 0) {
        return window.initialLoansData;
    }

    // Fallback: Try API if no pre-loaded data (e.g., on refresh or error)
    try {
        const response = await apiRequest('loan_applications.php', 'GET');
        if (response.success && response.data) {
            return response.data;
        }
    } catch (error) {
        //console.error('Failed to load loans via API:', error);
        showNotification('Error loading loans from server', 'error');
    }

    // Final fallback: empty array
    return [];
}

// Function to get payments for a specific loan
async function getPaymentsForLoan(applicationId) {
    try {
        const response = await apiRequest(`payments.php?application_id=${applicationId}`, 'GET');
        return response.data || [];
    } catch (error) {
        //console.error('Error fetching payments:', error);
        return [];
    }
}

// ==========================================
// UNIFIED BALANCE CALCULATION
// Use this consistent version across all three portals
// ==========================================

async function recalculateLoanBalance(loan) {
    // Get system interest rate
    let annualRate = 3.0; // Default fallback

    try {
        const settingsResponse = await apiRequest('settings.php?key=amortization_rate', 'GET');
        if (settingsResponse.success && settingsResponse.data) {
            annualRate = parseFloat(settingsResponse.data.value) || 3.0;
        }
    } catch (error) {
        //console.warn('Using default interest rate:', annualRate);
    }

    // CRITICAL FIX: Ensure payments are loaded
    if (!loan.payments || loan.payments.length === 0) {
        try {
            const paymentsResponse = await apiRequest(
                `payments.php?application_id=${loan.application_id}`,
                'GET'
            );
            loan.payments = paymentsResponse.data || [];
        } catch (error) {
            //console.warn('No payments found for loan:', loan.application_id);
            loan.payments = [];
        }
    }

    const payments = Array.isArray(loan.payments) ? loan.payments : [];

    let totalPaid = 0;
    let principalPaid = 0;
    let interestPaid = 0;

    // Sum up all payments
    payments.forEach(payment => {
        const amount = parseFloat(payment.amount) || 0;
        const principal = parseFloat(payment.principal_amount || payment.principalAmount) ||
            0;
        const interest = parseFloat(payment.interest_amount || payment.interestAmount) || 0;

        totalPaid += amount;
        principalPaid += principal;
        interestPaid += interest;
    });

    // Calculate remaining principal
    const originalAmount = parseFloat(loan.loan_amount || loan.loanAmount) || 0;
    const currentPrincipal = Math.max(0, originalAmount - principalPaid);

    // Calculate last payment date
    let lastPaymentDate = null;
    if (payments.length > 0) {
        const sortedPayments = [...payments].sort((a, b) => {
            const dateA = new Date(a.payment_date || a.paymentDate);
            const dateB = new Date(b.payment_date || b.paymentDate);
            return dateB - dateA;
        });
        lastPaymentDate = new Date(sortedPayments[0].payment_date || sortedPayments[0].paymentDate);
    } else {
        const dispatchDate = loan.dispatch_date || loan.dispatchDate;
        lastPaymentDate = dispatchDate ? new Date(dispatchDate) : new Date();
    }

    // Calculate time since last payment
    const today = new Date();
    const timeDiff = today - lastPaymentDate;
    const daysSinceLastPayment = Math.floor(timeDiff / (1000 * 60 * 60 * 24));
    const monthsSinceLastPayment = Math.max(1, Math.round(daysSinceLastPayment / 30));

    // Calculate accrued interest
    const accruedInterest = currentPrincipal > 0 ?
        calculateMonthlyInterest(currentPrincipal, annualRate, monthsSinceLastPayment) : 0;

    const totalBalanceDue = currentPrincipal + accruedInterest;

    return {
        originalAmount,
        totalPaid,
        principalPaid,
        interestPaid,
        currentPrincipal,
        accruedInterest: parseFloat(accruedInterest.toFixed(2)),
        totalBalanceDue: parseFloat(totalBalanceDue.toFixed(2)),
        lastPaymentDate: lastPaymentDate.toISOString().split('T')[0],
        monthsSinceLastPayment,
        daysSinceLastPayment
    };
}

// ==========================================
// MISSING HELPER FUNCTIONS
// ==========================================

/**
 * Convert array data to CSV format with proper handling of numeric fields
 */
function convertToCSV(data, headers, textColumns = []) {
    if (!data || data.length === 0) return '';

    // Create CSV content starting with headers
    let csv = headers.join(',') + '\n';

    // Add data rows
    data.forEach(row => {
        const values = row.map((value, index) => {
            // Handle null/undefined
            if (value === null || value === undefined) return '';

            // Convert to string
            let stringValue = String(value);

            // For columns that should be treated as text in Excel
            if (textColumns.includes(index)) {
                // Prepend with a single quote to force Excel to treat as text
                // This prevents scientific notation for long numbers
                stringValue = `\'${stringValue}`;
            }

            // Escape quotes
            stringValue = stringValue.replace(/"/g, '""');

            // Wrap in quotes if contains comma, newline, or quote
            if (stringValue.includes(',') || stringValue.includes('\n') || stringValue
                .includes(
                    '"')) {
                return `"${stringValue}"`;
            }

            return stringValue;
        });

        csv += values.join(',') + '\n';
    });

    return csv;
}

/**
 * Download CSV file to user's computer
 */
function downloadCSV(filename, csvContent) {
    // Process CSV to prevent scientific notation for long numbers
    const processedContent = csvContent.replace(/(\d{10,})/g, '\t$1');

    const blob = new Blob([processedContent], {
        type: 'text/csv;charset=utf-8;'
    });
    const link = document.createElement('a');

    if (navigator.msSaveBlob) {
        // IE 10+
        navigator.msSaveBlob(blob, filename);
    } else {
        const url = URL.createObjectURL(blob);
        link.href = url;
        link.download = filename;
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }

    showToast(`File ${filename} downloaded successfully`, 'success');
}

/**
 * Show notification - alias for showToast for consistency
 */
function showNotification(message, type = 'info') {
    // Use your existing showToast function
    if (typeof showToast === 'function') {
        showToast(message, type);
    } else {
        // Fallback notification
        //console.log(`${type}: ${message}`);
        alert(message);
    }
}

/**
 * Unified API call function - wrapper for apiRequest for backward compatibility
 */
async function apiCall(endpoint, method = 'GET', data = null) {
    return await apiRequest(endpoint, method, data);
}

/**
 * Format currency for display (no currency symbol)
 */
function formatCurrency(amount) {
    const num = parseFloat(amount) || 0;
    return new Intl.NumberFormat('en-KE', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
    }).format(Math.round(num));
}

/**
 * Format date for display
 */
function formatDate(dateString) {
    if (!dateString) return 'N/A';
    try {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    } catch (error) {
        return 'Invalid Date';
    }
}

/**
 * Calculate monthly interest - UNIFIED VERSION
 */
function calculateMonthlyInterest(principal, annualRate, months = 1) {
    if (principal <= 0 || annualRate <= 0) return 0;
    const monthlyRate = annualRate / 100 / 12;
    return principal * monthlyRate * Math.max(1, months);
}

/**
 * Format application ID for display in exports
 */
function formatApplicationId(id) {
    // Convert to string if it's a number
    const idStr = String(id || '');

    // If it's a simple numeric ID, return as is
    if (/^\d+$/.test(idStr)) {
        return idStr;
    }

    // If it contains special characters or is too long, format it
    // Add a prefix to prevent Excel from treating it as a number
    return `'${idStr}`;
}

/**
 * Format account number for display in exports
 */
function formatAccountNumber(accountNumber) {
    if (!accountNumber) return 'N/A';

    // Add a prefix to prevent Excel from treating it as a number
    return `'${String(accountNumber)}`;
}

// ==========================================
// UNIFIED FINANCIAL REPORT EXPORT
// Use this consistent version across all three portals
// ==========================================

// 1. Fix exportOverallSummary function
async function exportOverallSummary() {
    if (!hasPermission('canExportData')) {
        showNotification('You do not have permission to export data.', 'error');
        return;
    }

    try {
        const loans = await getAllLoans();
        const activeLoans = loans.filter(l => l.status === 'active' || l.status === 'dispatched');
        const closedLoans = loans.filter(l => l.status === 'closed');

        // CRITICAL FIX: Fetch payment data for all loans before calculations
        const paymentPromises = loans.map(async (loan) => {
            try {
                const paymentsResponse = await apiRequest(
                    `payments.php?application_id=${loan.application_id}`,
                    'GET'
                );
                loan.payments = paymentsResponse.data || [];
                return loan;
            } catch (error) {
                loan.payments = [];
                return loan;
            }
        });

        await Promise.all(paymentPromises);

        // Rest of the function remains the same...
        const headers = [
            'Application ID',
            'Loanee Name',
            'PF Number',
            'ID Number',
            'Loan Type',
            'Original Amount (KES)',
            'Total Paid (KES)',
            'Principal Paid (KES)',
            'Interest Paid (KES)',
            'Outstanding Balance (KES)',
            'Status',
            'Application Date',
            'Dispatch Date',
            'Bank Name',
            'Account Number'
        ];

        const data = [];

        for (const loan of [...activeLoans, ...closedLoans]) {
            const balanceInfo = await recalculateLoanBalance(loan);
            data.push([
                formatApplicationId(loan.application_id),
                loan.full_name,
                loan.pf_number,
                loan.id_number,
                loan.loan_type ? loan.loan_type.charAt(0).toUpperCase() + loan.loan_type
                .slice(1) :
                'N/A',
                formatCurrency(loan.loan_amount),
                formatCurrency(balanceInfo.totalPaid),
                formatCurrency(balanceInfo.principalPaid),
                formatCurrency(balanceInfo.interestPaid),
                loan.status !== 'closed' ? formatCurrency(balanceInfo.totalBalanceDue) :
                '0',
                loan.status.toUpperCase(),
                formatDate(loan.application_date),
                loan.dispatch_date ? formatDate(loan.dispatch_date) : 'N/A',
                loan.bank_name || 'N/A',
                formatAccountNumber(loan.bank_account_number)
            ]);
        }

        const csv = convertToCSV(data, headers);
        const filename = `Overall_Summary_${new Date().toISOString().split('T')[0]}.csv`;
        downloadCSV(filename, csv);
    } catch (error) {
        showNotification('Error exporting overall summary: ' + error.message, 'error');
    }
}

// 2. Fix exportMortgageLoans function
async function exportMortgageLoans() {
    if (!hasPermission('canExportData')) {
        showNotification('You do not have permission to export data.', 'error');
        return;
    }

    try {
        const loans = await getAllLoans();
        const mortgageLoans = loans.filter(l => l.loan_type === 'mortgage');

        // CRITICAL FIX: Fetch payment data for all loans before calculations
        const paymentPromises = loans.map(async (loan) => {
            try {
                const paymentsResponse = await apiRequest(
                    `payments.php?application_id=${loan.application_id}`,
                    'GET'
                );
                loan.payments = paymentsResponse.data || [];
                return loan;
            } catch (error) {
                loan.payments = [];
                return loan;
            }
        });

        await Promise.all(paymentPromises);

        // Rest of the function remains the same...
        const headers = [
            'Application ID',
            'Loanee Name',
            'PF Number',
            'ID Number',
            'Original Amount (KES)',
            'Property Location',
            'Property Value (KES)',
            'Total Paid (KES)',
            'Principal Paid (KES)',
            'Interest Paid (KES)',
            'Outstanding Balance (KES)',
            'Status',
            'Payment Duration (Years)',
            'Application Date',
            'Dispatch Date',
            'Bank Name',
            'Account Number'
        ];

        const data = [];

        for (const loan of mortgageLoans) {
            const balanceInfo = await recalculateLoanBalance(loan);
            data.push([
                formatApplicationId(loan.application_id),
                loan.full_name,
                loan.pf_number,
                loan.id_number,
                formatCurrency(loan.loan_amount),
                loan.property_location || 'N/A',
                loan.property_value ? formatCurrency(loan.property_value) : 'N/A',
                formatCurrency(balanceInfo.totalPaid),
                formatCurrency(balanceInfo.principalPaid),
                formatCurrency(balanceInfo.interestPaid),
                loan.status !== 'closed' ? formatCurrency(balanceInfo.totalBalanceDue) :
                '0',
                loan.status.toUpperCase(),
                loan.payment_duration || 'N/A',
                formatDate(loan.application_date),
                loan.dispatch_date ? formatDate(loan.dispatch_date) : 'N/A',
                loan.bank_name || 'N/A',
                formatAccountNumber(loan.bank_account_number)
            ]);
        }

        const csv = convertToCSV(data, headers);
        const filename = `Mortgage_Loans_${new Date().toISOString().split('T')[0]}.csv`;
        downloadCSV(filename, csv);
    } catch (error) {
        showNotification('Error exporting mortgage loans: ' + error.message, 'error');
    }
}

// 3. Fix exportCarLoans function
async function exportCarLoans() {
    if (!hasPermission('canExportData')) {
        showNotification('You do not have permission to export data.', 'error');
        return;
    }

    try {
        const loans = await getAllLoans();
        const carLoans = loans.filter(l => l.loan_type === 'car');

        // CRITICAL FIX: Fetch payment data for all loans before calculations
        const paymentPromises = loans.map(async (loan) => {
            try {
                const paymentsResponse = await apiRequest(
                    `payments.php?application_id=${loan.application_id}`,
                    'GET'
                );
                loan.payments = paymentsResponse.data || [];
                return loan;
            } catch (error) {
                loan.payments = [];
                return loan;
            }
        });

        await Promise.all(paymentPromises);

        // Rest of the function remains the same...
        const headers = [
            'Application ID',
            'Loanee Name',
            'PF Number',
            'ID Number',
            'Original Amount (KES)',
            'Car Type',
            'Car Value (KES)',
            'Total Paid (KES)',
            'Principal Paid (KES)',
            'Interest Paid (KES)',
            'Outstanding Balance (KES)',
            'Status',
            'Payment Duration (Years)',
            'Application Date',
            'Dispatch Date',
            'Bank Name',
            'Account Number'
        ];

        const data = [];

        for (const loan of carLoans) {
            const balanceInfo = await recalculateLoanBalance(loan);
            data.push([
                formatApplicationId(loan.application_id),
                loan.full_name,
                loan.pf_number,
                loan.id_number,
                formatCurrency(loan.loan_amount),
                loan.car_type || 'N/A',
                loan.car_value ? formatCurrency(loan.car_value) : 'N/A',
                formatCurrency(balanceInfo.totalPaid),
                formatCurrency(balanceInfo.principalPaid),
                formatCurrency(balanceInfo.interestPaid),
                loan.status !== 'closed' ? formatCurrency(balanceInfo.totalBalanceDue) :
                '0',
                loan.status.toUpperCase(),
                loan.payment_duration || 'N/A',
                formatDate(loan.application_date),
                loan.dispatch_date ? formatDate(loan.dispatch_date) : 'N/A',
                loan.bank_name || 'N/A',
                formatAccountNumber(loan.bank_account_number)
            ]);
        }

        const csv = convertToCSV(data, headers);
        const filename = `Car_Loans_${new Date().toISOString().split('T')[0]}.csv`;
        downloadCSV(filename, csv);
    } catch (error) {
        showNotification('Error exporting car loans: ' + error.message, 'error');
    }
}

// 4. Fix exportPaymentSummary function
async function exportPaymentSummary() {
    if (!hasPermission('canExportData')) {
        showNotification('You do not have permission to export data.', 'error');
        return;
    }

    try {
        const loans = await getAllLoans();
        const activeLoans = loans.filter(l => l.status === 'active' || l.status === 'dispatched');
        const closedLoans = loans.filter(l => l.status === 'closed');

        // CRITICAL FIX: Fetch payment data for all loans before calculations
        const paymentPromises = loans.map(async (loan) => {
            try {
                const paymentsResponse = await apiRequest(
                    `payments.php?application_id=${loan.application_id}`,
                    'GET'
                );
                loan.payments = paymentsResponse.data || [];
                return loan;
            } catch (error) {
                loan.payments = [];
                return loan;
            }
        });

        await Promise.all(paymentPromises);

        // Rest of the function remains the same...
        const headers = [
            'Application ID',
            'Loanee Name',
            'Loan Type',
            'Original Amount (KES)',
            'Total Amount Paid (KES)',
            'Principal Paid (KES)',
            'Interest Paid (KES)',
            'Outstanding Balance (KES)',
            'Number of Payments',
            'Last Payment Date',
            'Months Since Last Payment',
            'Payment Status'
        ];

        const data = [];

        for (const loan of [...activeLoans, ...closedLoans]) {
            const balanceInfo = await recalculateLoanBalance(loan);
            const paymentStatus = loan.status === 'closed' ? 'FULLY PAID' :
                balanceInfo.totalPaid > 0 ? 'IN PROGRESS' : 'NO PAYMENTS';

            data.push([
                loan.application_id,
                loan.full_name,
                loan.loan_type.charAt(0).toUpperCase() + loan.loan_type.slice(1),
                formatCurrency(loan.loan_amount),
                formatCurrency(balanceInfo.totalPaid),
                formatCurrency(balanceInfo.principalPaid),
                formatCurrency(balanceInfo.interestPaid),
                loan.status !== 'closed' ? formatCurrency(balanceInfo.totalBalanceDue) :
                '0',
                loan.payments ? loan.payments.length : 0,
                balanceInfo.lastPaymentDate,
                balanceInfo.monthsSinceLastPayment,
                paymentStatus
            ]);
        }

        const csv = convertToCSV(data, headers);
        const filename = `Payment_Summary_${new Date().toISOString().split('T')[0]}.csv`;
        downloadCSV(filename, csv);
    } catch (error) {
        showNotification('Error exporting payment summary: ' + error.message, 'error');
    }
}

// 5. Fix exportFinancialReport function
async function exportFinancialReport() {
    try {
        showLoading(true);

        // Get all data
        const loans = await getAllLoans();

        // CRITICAL FIX: Fetch payment data for all loans before calculations
        const paymentPromises = loans.map(async (loan) => {
            try {
                const paymentsResponse = await apiRequest(
                    `payments.php?application_id=${loan.application_id}`,
                    'GET'
                );
                loan.payments = paymentsResponse.data || [];
                return loan;
            } catch (error) {
                loan.payments = [];
                return loan;
            }
        });

        await Promise.all(paymentPromises);

        // Headers for the complete report
        const headers = [
            'Application ID',
            'Loanee Name',
            'PF Number',
            'ID Number',
            'Email',
            'Loan Type',
            'Loan Amount (KES)',
            'Total Paid (KES)',
            'Principal Paid (KES)',
            'Interest Paid (KES)',
            'Outstanding Balance (KES)',
            'Status',
            'Application Date',
            'Dispatch Date',
            'Bank Name',
            'Account Number'
        ];

        const data = [];

        for (const loan of loans) {
            const balanceInfo = await recalculateLoanBalance(loan);
            data.push([
                formatApplicationId(loan.application_id),
                loan.full_name,
                loan.pf_number,
                loan.id_number,
                loan.email,
                loan.loan_type ? loan.loan_type.charAt(0).toUpperCase() + loan.loan_type
                .slice(1) :
                'N/A',
                formatCurrency(loan.loan_amount),
                formatCurrency(balanceInfo.totalPaid),
                formatCurrency(balanceInfo.principalPaid),
                formatCurrency(balanceInfo.interestPaid),
                loan.status !== 'closed' ? formatCurrency(balanceInfo.totalBalanceDue) :
                '0',
                loan.status.toUpperCase(),
                formatDate(loan.application_date),
                loan.dispatch_date ? formatDate(loan.dispatch_date) : 'N/A',
                loan.bank_name || 'N/A',
                formatAccountNumber(loan.bank_account_number)
            ]);
        }

        // CRITICAL: Specify which columns should be treated as text (indices 0, 2, 3, 15 = Application ID, PF Number, ID Number, Account Number)
        const textColumns = [0, 2, 3, 15];
        const csv = convertToCSV(data, headers, textColumns);
        const filename = `Complete_Financial_Report_${new Date().toISOString().split('T')[0]}.csv`;
        downloadCSV(filename, csv);

        showToast('Complete financial report exported successfully!', 'success');
    } catch (error) {
        //console.error('Export error:', error);
        showToast('Failed to export complete report: ' + error.message, 'error');
    } finally {
        showLoading(false);
    }
}

// Make these functions globally accessible
window.exportOverallSummary = exportOverallSummary;
window.exportMortgageLoans =
    exportMortgageLoans;
window.exportCarLoans = exportCarLoans;
window.exportPaymentSummary =
    exportPaymentSummary;
window.exportFinancialReport = exportFinancialReport;

/**
 * Check permission
 */
function hasPermission(permission) {
    // Since this is admin portal, we'll assume admin has all permissions
    // In a real implementation, you would check against user roles
    const adminPermissions = {
        'canExportData': true,
        'canViewReports': true,
        'canManageStaff': true,
        'canViewLoans': true,
        'canManageSystem': true
    };

    return adminPermissions[permission] || false;
}

/* ===========================
       🎨 INITIALIZATION
       =========================== */

document.addEventListener('DOMContentLoaded', function() {
    // Cache DOM elements
    elements.mainHeader = document.getElementById('mainHeader');
    elements.sidebar = document.getElementById('sidebar');
    elements.main = document.getElementById('main');
    elements.loadingOverlay = document.getElementById('loadingOverlay');
    elements.toastContainer = document.getElementById('toastContainer');
    elements.statusMessage = document.getElementById('statusMessage');
    elements.themeToggle = document.getElementById('themeToggle');
    elements.staffForm = document.getElementById('staffForm');
    elements.addFundForm = document.getElementById('addFundForm');
    elements.updateIndicator = document.getElementById('updateIndicator');
    elements.profileBadge = document.getElementById('profileBadge');
    elements.profileDropdown = document.getElementById('profileDropdown');
    elements.menuToggle = document.getElementById('menuToggle');

    // Initialize the profile menu before other components so a failure in
    // another initializer cannot prevent the profile dropdown from binding.
    initializeProfileBadge();

    // Initialize hamburger menu and other components
    initializeHamburgerMenu();
    initializeEventListeners();
    initializeThemeSelector();

    // Initialize password change form functionality
    initializePasswordChangeForm();

    // Initialize theme icon
    updateThemeIcon();

    // Initialize migration button for manual use
    if (typeof initializeMigrationButton === 'function') {
        initializeMigrationButton();
    }

    // Initialize transaction modal handlers
    const modal = document.getElementById('transactionModal');
    if (modal) {
        // Close on overlay click
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeTransactionModal();
            }
        });

        // Load loans when modal is opened
        if (document.getElementById('loansSection')) {
            renderLoans('all');
        }

        // Close on X button click
        const closeButtons = modal.querySelectorAll('.close-modal');
        closeButtons.forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                closeTransactionModal();
            });
        });

        // Close on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal.classList.contains('show')) {
                closeTransactionModal();
            }
        });
    }

    // Auto-load all loans on page load using pre-loaded data
    getAllLoans().then(loans => {
        if (loans.length === 0) {
            document.getElementById('loansTableBody').innerHTML =
                '<tr><td colspan="10" style="text-align:center; color:orange;">No loan applications found</td></tr>';
        } else {
            renderLoans('all'); // renderLoans will use the returned loans
        }
        // Optional: updateDashboardMetrics(loans);
    });

    // Mount portal
    improvedMountPortal();
});



/**
 * Hide all sections
 */
function hideAllSections() {
    const sections = [
        'dashboardSection',
        'loansSection',
        'accountsSection',
        'staffSection',
        'activitySection',
        'settingsSection',
        'reportsSection'
    ];

    sections.forEach(sectionId => {
        const section = document.getElementById(sectionId);
        if (section) {
            section.classList.add('hidden');
            section.style.display = 'none';
        }
    });
}

/**
 * Update sidebar active state
 */
function updateSidebarActive(activeFilter) {
    // Remove active class from all sidebar buttons
    document.querySelectorAll('#sidebar button').forEach(btn => {
        btn.classList.remove('active');
    });

    // Add active class to the clicked button
    const activeBtn = document.querySelector(`#sidebar button[data-filter="${activeFilter}"]`);
    if (activeBtn) {
        activeBtn.classList.add('active');
    }
}

/**
 * Show loans section with proper visibility management
 * @param {string} filter - Filter to apply ('all', 'active', 'pending', etc.)
 */
function showLoansSection(filter = 'all') {
    console.log('Showing loans section with filter:', filter);

    // Hide all other sections first
    hideAllSections();

    // Show loans section
    const loansSection = document.getElementById('loansSection');
    if (loansSection) {
        loansSection.style.display = 'block';
        loansSection.classList.remove('hidden');
    } else {
        console.error('Element #loansSection not found');
    }

    // Show main content area
    const mainContent = document.getElementById('mainContent');
    if (mainContent) {
        mainContent.style.display = 'block';
    }

    // Make sure the loans table is visible
    const loansTable = document.getElementById('loansTable');
    if (loansTable) {
        loansTable.style.display = 'table';
    }

    // Update sidebar active state
    updateSidebarActive('loans'); // Changed from filter to 'loans'

    // Force refresh to get latest data
    if (typeof renderLoans === 'function') {
        renderLoans('all', true); // Force refresh
    }

    // Update stats
    if (typeof updateStats === 'function') {
        updateStats();
    }
}

// Add this early in your <script> tag (e.g., before renderLoans definition)
function showLoadingIndicator(message = 'Loading...') {
    let loading = document.getElementById('loadingIndicator');
    if (!loading) {
        loading = document.createElement('div');
        loading.id = 'loadingIndicator';
        loading.style.position = 'fixed';
        loading.style.top = '0';
        loading.style.left = '0';
        loading.style.width = '100%';
        loading.style.height = '100%';
        loading.style.background = 'rgba(255, 255, 255, 0.8)';
        loading.style.display = 'flex';
        loading.style.alignItems = 'center';
        loading.style.justifyContent = 'center';
        loading.style.zIndex = '9999';
        loading.innerHTML =
            '<div style="padding: 20px; background: #fff; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);"><span class="loading-message"></span></div>';
        document.body.appendChild(loading);
    }
    const messageElement = loading.querySelector('.loading-message');
    if (messageElement) messageElement.textContent = message;
    loading.style.display = 'flex';
}

/**
 * Hide loading indicator
 */
function hideLoadingIndicator() {
    const loading = document.getElementById('loadingIndicator');
    if (loading) {
        loading.style.display = 'none';
    }
}

// Make functions globally available
window.hideAllSections = hideAllSections;
window.updateSidebarActive = updateSidebarActive;
window.showLoadingIndicator = showLoadingIndicator;
window.hideLoadingIndicator = hideLoadingIndicator;



/**
 * Get CSRF token from various possible sources
 */
function getCsrfToken() {
    return window.CSRF_TOKEN ||
        document.querySelector('#csrf-token-data')?.dataset.csrfToken ||
        document.querySelector('input[name="csrf_token"]')?.value ||
        document.querySelector('meta[name="csrf-token"]')?.content ||
        '';
}

// Make globally available
window.getCsrfToken = getCsrfToken;


/**
 * Show legacy debtors view in admin portal
 * Displays all imported debtors grouped by financial year
 */
async function showLegacyDebtorsView() {
    hideAllSections();

    try {
        showLoadingIndicator();

        // Fetch all debtors with summary
        const response = await apiCall('debtors.php');

        if (!response.success) {
            throw new Error(response.message);
        }

        const summary = response.data || [];

        // Calculate totals
        let totalDebtors = 0;
        let totalAmount = 0;
        let totalActive = 0;

        summary.forEach(year => {
            totalDebtors += parseInt(year.debtor_count) || 0;
            totalAmount += parseFloat(year.total_amount) || 0;
            totalActive += parseInt(year.active_loans) || 0;
        });

        let html = `
        <h2><i data-lucide="bar-chart-3" class="icon-sm"></i> Legacy Debtors Management</h2>
        
        <!-- Overall Statistics -->
        <div class="debtor-stats">
            <div class="debtor-stat-card info">
                <div class="debtor-stat-number">${totalDebtors}</div>
                <div class="debtor-stat-label">Total Debtors</div>
            </div>
            <div class="debtor-stat-card danger">
                <div class="debtor-stat-number">KES ${formatCurrency(totalAmount)}</div>
                <div class="debtor-stat-label">Total Outstanding</div>
            </div>
            <div class="debtor-stat-card success">
                <div class="debtor-stat-number">${totalActive}</div>
                <div class="debtor-stat-label">Active Loans</div>
            </div>
        </div>
        
        <div class="balance-highlight" style="margin-top: 20px;">
            <h3>Debtors by Financial Year</h3>
    `;

        if (summary.length === 0) {
            html += '<p>No legacy debtors have been imported yet.</p>';
        } else {
            summary.forEach(year => {
                const yearTotal = parseFloat(year.total_amount) || 0;
                const debtorCount = parseInt(year.debtor_count) || 0;
                const activeLoans = parseInt(year.active_loans) || 0;
                const pending = debtorCount - activeLoans;

                html += `
                <div style="border: 1px solid #e0e0e0; padding: 15px; margin: 10px 0; border-radius: 8px;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <h4 style="margin: 0 0 10px 0;">FY ${year.financial_year}</h4>
                            <p style="margin: 5px 0;"><strong>Total Records:</strong> ${debtorCount}</p>
                            <p style="margin: 5px 0;"><strong>Total Amount:</strong> KES ${formatCurrency(yearTotal)}</p>
                            <p style="margin: 5px 0;"><strong>Converted to Loans:</strong> ${activeLoans}</p>
                            <p style="margin: 5px 0;"><strong>Pending Migration:</strong> ${pending}</p>
                            <p style="margin: 5px 0; font-size: 0.85em; color: #666;">
                                First Import: ${formatDate(year.first_import)} | 
                                Last Import: ${formatDate(year.last_import)}
                            </p>
                        </div>
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <button onclick="viewDebtorsByYear('${year.financial_year}')" 
                                    class="action-btn" style="background: #2196F3;">
                                <i data-lucide="clipboard-list" class="icon-sm"></i> View Details
                            </button>
                            <button onclick="exportDebtorsByYear('${year.financial_year}')" 
                                    class="action-btn" style="background: #4CAF50;">
                                <i data-lucide="download" class="icon-sm"></i> Export CSV
                            </button>
                            ${pending > 0 ? `
                            <button onclick="migrateDebtorsByYear('${year.financial_year}')" 
                                    class="action-btn" style="background: #FF9800;">
                                <i data-lucide="refresh-cw" class="icon-sm"></i> Migrate (${pending})
                            </button>
                            ` : ''}
                        </div>
                    </div>
                </div>
            `;
            });
        }

        html += `</div>`;

        // FIX: Check if mainContent exists before setting innerHTML
        const mainContent = document.getElementById('mainContent');
        if (!mainContent) {
            // If mainContent doesn't exist, look for alternative containers
            const main = document.getElementById('main');
            const legacySection = document.getElementById('legacyDebtorsSection');

            if (legacySection) {
                legacySection.innerHTML = html;
                legacySection.classList.remove('hidden');
                legacySection.style.display = 'block';
            } else if (main) {
                // Create a section if it doesn't exist
                const section = document.createElement('section');
                section.id = 'legacyDebtorsSection';
                section.innerHTML = html;

                // Hide other sections
                document.querySelectorAll('main section').forEach(s => s.classList.add('hidden'));

                // Append and show new section
                main.appendChild(section);
            } else {
                throw new Error('Could not find a container to display legacy debtors view');
            }
        } else {
            mainContent.innerHTML = html;
            mainContent.style.display = 'block';
        }

    } catch (error) {
        console.error('Error loading legacy debtors:', error);
        showNotification('Error loading debtors: ' + error.message, 'error');
    } finally {
        hideLoadingIndicator();
    }
}

/**
 * View debtors for specific financial year
 */
async function viewDebtorsByYear(financialYear) {
    try {
        showLoadingIndicator();

        const response = await apiCall(`debtors.php?financial_year=${encodeURIComponent(financialYear)}`);

        if (!response.success) {
            throw new Error(response.message);
        }

        const debtors = response.data.debtors || [];
        const stats = response.data.statistics || {};

        // Create modal
        const modal = document.createElement('div');
        modal.className = 'modal';
        modal.id = 'viewDebtorsByYearModal';

        modal.innerHTML = `
        <div class="modal-content" style="max-width: 1000px;">
            <div class="modal-header">
                <h3><i data-lucide="bar-chart-3" class="icon-sm"></i> Debtors - FY ${escapeHtml(financialYear)}</h3>
                <span class="close-btn" onclick="closeDebtorModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="debtor-stats">
                    <div class="debtor-stat-card info">
                        <div class="debtor-stat-number">${stats.total_count || 0}</div>
                        <div class="debtor-stat-label">Total Records</div>
                    </div>
                    <div class="debtor-stat-card danger">
                        <div class="debtor-stat-number">KES ${formatCurrency(stats.total_amount || 0)}</div>
                        <div class="debtor-stat-label">Total Amount</div>
                    </div>
                </div>
                
                <div class="debtor-preview-table">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Name</th>
                                <th>PF Number</th>
                                <th>ID Number</th>
                                <th style="text-align: right;">Amount (KES)</th>
                                <th>Status</th>
                                <th>Loan Status</th>
                                <th>Import Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${debtors.map((debtor, index) => {
                                const loanStatus = debtor.loan_application_id ? 
                                    `<span style="color: #4CAF50;"><i data-lucide="check-circle" class="icon-sm"></i> ${debtor.loan_status || 'Active'}</span>` : 
                                    '<span style="color: #999;">Not Migrated</span>';
                                
                                return `
                                <tr>
                                    <td>${index + 1}</td>
                                    <td>${escapeHtml(debtor.name)}</td>
                                    <td>${debtor.pf_number || 'N/A'}</td>
                                    <td>${debtor.id_number || 'N/A'}</td>
                                    <td style="text-align: right; font-weight: 600;">
                                        KES ${formatCurrency(debtor.amount)}
                                    </td>
                                    <td>${debtor.status || 'pending'}</td>
                                    <td>${loanStatus}</td>
                                    <td>${formatDate(debtor.created_at)}</td>
                                </tr>
                                `;
                            }).join('')}
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button onclick="exportDebtorsByYear('${financialYear}')" class="action-btn">
                    <i data-lucide="download" class="icon-sm"></i> Export CSV
                </button>
                <button onclick="closeDebtorModal()" class="action-btn reject-btn">
                    Close
                </button>
            </div>
        </div>
    `;

        document.body.appendChild(modal);
        modal.style.display = 'flex';

    } catch (error) {
        console.error('Error viewing debtors:', error);
        showNotification('Error loading debtors: ' + error.message, 'error');
    } finally {
        hideLoadingIndicator();
    }
}

/**
 * Export debtors for specific year to CSV
 */
async function exportDebtorsByYear(financialYear) {
    if (!hasPermission('canExportData')) {
        showNotification('You do not have permission to export data.', 'error');
        return;
    }

    try {
        const response = await apiCall(`debtors.php?financial_year=${encodeURIComponent(financialYear)}`);

        if (!response.success) {
            throw new Error(response.message);
        }

        const debtors = response.data.debtors || [];

        const headers = ['#', 'Name', 'PF Number', 'ID Number', 'Amount (KES)', 'Status', 'Migrated',
            'Import Date'
        ];

        const data = debtors.map((debtor, index) => [
            index + 1,
            debtor.name,
            debtor.pf_number || 'N/A',
            debtor.id_number || 'N/A',
            formatCurrency(debtor.amount),
            debtor.status || 'pending',
            debtor.loan_application_id ? 'Yes' : 'No',
            formatDate(debtor.created_at)
        ]);

        const csv = convertToCSV(data, headers);
        const filename =
            `Debtors_FY_${financialYear.replace('/', '-')}_${new Date().toISOString().split('T')[0]}.csv`;
        downloadCSV(filename, csv);

    } catch (error) {
        showNotification('Error exporting debtors: ' + error.message, 'error');
    }
}

/**
 * Migrate debtors for specific year to loans
 */
async function migrateDebtorsByYear(financialYear) {
    if (!confirm(
            `Migrate all pending debtors from FY ${financialYear} to loan applications?\n\nThis will create active loan records for each debtor.`
        )) {
        return;
    }

    try {
        showLoadingIndicator();

        const response = await apiCall('migrate_outstanding_to_loans.php', 'POST', {
            csrf_token: APP_STATE.csrfToken,
            financial_year: financialYear
        });

        if (!response.success) {
            throw new Error(response.message);
        }

        const imported = response.data.imported || 0;
        const totalAmount = response.data.total_amount || 0;

        showNotification(
            `Successfully migrated ${imported} debtor(s) worth KES ${formatCurrency(totalAmount)} to loan applications!`,
            'success',
            5000
        );

        // Refresh the view
        showLegacyDebtorsView();

    } catch (error) {
        console.error('Migration error:', error);
        showNotification('Migration failed: ' + error.message, 'error');
    } finally {
        hideLoadingIndicator();
    }
}

/**
 * Close debtor modal
 */
function closeDebtorModal() {
    const modal = document.getElementById('viewDebtorsByYearModal');
    if (modal) {
        modal.remove();
    }
}

function showEditLoanModalFromButton(btn) {
    try {
        const loan = JSON.parse(decodeURIComponent(btn.dataset.loan));
        showEditLoanModal(loan);
    } catch (e) {
        console.error("Failed to parse loan data", e);
        showNotification("Cannot edit – data format error", "error");
    }
}

async function viewLoanDetails(applicationId) {
    if (!applicationId) {
        return showNotification("No loan ID provided", "error");
    }

    showLoadingIndicator('Loading loan details...');
    try {
        const response = await apiRequest(
            `loans.php?application_id=${encodeURIComponent(applicationId)}`, 'GET'
        );
        if (!response.success) throw new Error(response.message || 'Failed to load loan');

        const loan = response.data;
        const modal = document.createElement('div');
        modal.className = 'modal show';
        modal.style.display = 'flex';
        modal.innerHTML = `
        <div class="modal-content" style="max-width: 820px;">
            <div class="modal-header">
                <h3>Loan Details — ${escapeHtml(applicationId)}</h3>
                <button class="close-modal" type="button">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-grid loan-details-grid">
                    <div><strong>Full Name:</strong> ${escapeHtml(loan.full_name ?? '—')}</div>
                    <div><strong>ID Number:</strong> ${escapeHtml(loan.id_number ?? '—')}</div>
                    <div><strong>PF Number:</strong> ${escapeHtml(loan.pf_number ?? '—')}</div>
                    <div><strong>Email:</strong> ${escapeHtml(loan.email ?? '—')}</div>
                    <div><strong>Loan Type:</strong> ${escapeHtml(loan.loan_type ?? '—')}</div>
                    <div><strong>Loan Amount:</strong> ${fmtKES(loan.loan_amount)}</div>
                    <div><strong>Status:</strong> ${escapeHtml(loan.status ?? '—')}</div>
                    <div><strong>Basic Salary:</strong> ${fmtKES(loan.basic_salary)}</div>
                    <div><strong>Employment:</strong> ${escapeHtml(loan.employment_type ?? '—')}</div>
                    <div><strong>Bank:</strong> ${escapeHtml(loan.bank_name ?? '—')}</div>
                    <div><strong>Account No:</strong> ${escapeHtml(loan.bank_account_number ?? '—')}</div>
                    <div><strong>Application Date:</strong> ${formatDate(loan.application_date)}</div>
                    <div><strong>Dispatch Date:</strong> ${formatDate(loan.dispatch_date)}</div>
                    <div class="full-width"><strong>Feedback:</strong> ${escapeHtml(loan.feedback ?? '—')}</div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary close-modal" type="button">Close</button>
            </div>
        </div>`;

        modal.querySelectorAll('.close-modal').forEach(btn =>
            btn.addEventListener('click', () => modal.remove())
        );
        modal.addEventListener('click', event => {
            if (event.target === modal) modal.remove();
        });
        document.body.appendChild(modal);
    } catch (err) {
        showNotification(`Error: ${err.message}`, 'error');
    } finally {
        hideLoadingIndicator();
    }
}
