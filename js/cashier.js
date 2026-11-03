// ==========================================
// PRODUCTION-SAFE LOGGING
// ==========================================
const DEBUG_MODE = false; // Set to false in production

const logger = {
    log: (...args) => DEBUG_MODE && console.log(...args),
    error: (...args) => DEBUG_MODE && console.error(...args),
    warn: (...args) => DEBUG_MODE && console.warn(...args),
    info: (...args) => DEBUG_MODE && console.info(...args)
};



// ==========================================
// GLOBAL VARIABLES AND CONFIGURATION
// ==========================================
// API Configuration
const API_CONFIG = {
    getBaseUrl() {
        const hostname = window.location.hostname;
        const pathname = window.location.pathname;
        const basePath = pathname.substring(0, pathname.lastIndexOf('/'));

        // Check if we're in the right directory structure
        if (hostname === 'localhost' || hostname === '127.0.0.1') {
            // For local development, use the full path
            return window.location.origin + '/loan-system/api';
        } else {
            // For production, use relative path
            return '/api';
        }
    }
};
const API_BASE_URL = API_CONFIG.getBaseUrl();
//console.log('API Base URL:', API_BASE_URL);



// DOM Element References
const DOM_ELEMENTS = {
    loansSection: document.getElementById('loansSection'),
    debtorsSection: document.getElementById('debtorsSection'),
    sidebar: document.getElementById('sidebar'),
    mainContent: document.getElementById('mainContent'),
    sectionTitle: document.getElementById('sectionTitle'),
    loadingIndicator: document.getElementById('loadingIndicator'),
    errorMessage: document.getElementById('errorMessage'),
    loansTable: document.getElementById('loansTable'),
    notification: document.getElementById('notification'),
    hamburgerMenu: document.getElementById('hamburgerMenu')
};



// ==========================================
// UTILITY FUNCTIONS
// ==========================================
function formatApplicationId(applicationId) {
    if (!applicationId) return 'N/A';
    return applicationId; // Return just the ID, let convertToCSV handle the formatting
}

function formatAccountNumber(accountNumber) {
    if (!accountNumber || accountNumber === 'N/A') return 'N/A';
    return accountNumber; // Return just the number, the CSV formatting will handle the Excel format
}

function formatCurrency(amount) {
    return new Intl.NumberFormat().format(Math.round(amount));
}

function formatDate(dateString) {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleDateString();
}

function formatDateTime(dateString) {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleString();
}

function debounce(func, wait) {
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

// ==========================================
// API FUNCTIONS - FIXED VERSION
// ==========================================
async function apiCall(endpoint, method = 'GET', data = null) {
    const options = {
        method: method,
        headers: {
            'Content-Type': 'application/json',
        },
        credentials: 'include'
    };

    if (APP_STATE.csrfToken) {
        options.headers['X-CSRF-Token'] = APP_STATE.csrfToken;
    }

    if (data) {
        options.body = JSON.stringify(data);
    }

    try {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 30000); // 30 second timeout

        options.signal = controller.signal;

        const response = await fetch(`${API_BASE_URL}/${endpoint}`, options);
        clearTimeout(timeoutId);

        if (!response.ok) {
            if (response.status === 401) {
                //console.warn('Unauthorized - Session expired');
                showNotification('Session expired. Please log in again.', 'error');
                setTimeout(() => window.location.href = 'index.php', 2000);
                throw new Error('Unauthorized');
            } else if (response.status === 403) {
                throw new Error('You do not have permission to perform this action');
            } else if (response.status === 404) {
                throw new Error('Resource not found');
            } else if (response.status >= 500) {
                throw new Error('Server error. Please try again later.');
            }

            let errorMessage = `HTTP error! status: ${response.status}`;
            try {
                const errorData = await response.json();
                errorMessage = errorData.message || errorMessage;
            } catch (e) {
                errorMessage = response.statusText || errorMessage;
            }
            throw new Error(errorMessage);
        }

        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            const text = await response.text();
            //console.error('Non-JSON response:', text.substring(0, 200));

            // Check if it's HTML error page
            if (text.includes('<!DOCTYPE') || text.includes('<html')) {
                throw new Error(
                    'Server returned an HTML page instead of JSON. Please check server configuration.');
            }

            throw new Error('Server returned invalid response format');
        }

        const result = await response.json();

        if (!result.success && result.success !== undefined) {
            throw new Error(result.message || 'Request failed');
        }

        return result;
    } catch (error) {
        if (error.name === 'AbortError') {
            //console.error('API request timeout:', endpoint);
            throw new Error('Request timeout. Please check your connection.');
        }

        // Only log detailed errors in development
        if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
            /*//console.error('API call error details:', {
                endpoint,
                method,
                error: error.message,
                stack: error.stack
            });*/
        } else {
            //console.error('API call failed:', endpoint, error.message);
        }

        throw error;
    }
}


// ==========================================
// NOTIFICATION SYSTEM
// ==========================================
function showNotification(message, type = 'success', duration = 3000) {
    const notification = DOM_ELEMENTS.notification;
    notification.textContent = message;
    notification.className = `notification show ${type}`;
    setTimeout(() => {
        notification.classList.remove('show');
    }, duration);
}

// ==========================================
// PERMISSION SYSTEM
// ==========================================
function hasPermission(permission) {
    return APP_STATE.cashierPermissions[permission] === true;
}

function applyPermissions() {
    if (!hasPermission('canExportData')) {
        const exportBtns = document.querySelectorAll('[onclick*="export"]');
        exportBtns.forEach(btn => btn.style.display = 'none');
    }
}



// ==========================================
// LOAN DATA MANAGEMENT
// ==========================================
async function getAllLoans() {
    try {
        const response = await apiCall('loans.php');
        const loans = response.data || [];

        // Debug logging for mortgage loans
        const mortgageLoans = loans.filter(l => l.loan_type === 'mortgage');
        //console.log('Mortgage loans found:', mortgageLoans.length);
        mortgageLoans.forEach(loan => {
            /*/
        ///console.log(`Mortgage loan ${loan.application_id}:`, {
        property_location: loan.property_location,
            property_value: loan.property_value
    });*/
        });

        // Debug logging for car loans
        const carLoans = loans.filter(l => l.loan_type === 'car');
        //console.log('Car loans found:', carLoans.length);
        carLoans.forEach(loan => {
            /*//console.log(`Car loan ${loan.application_id}:`, {
            car_type: loan.car_type,
            car_value: loan.car_value
            });*/
        });

        return loans;
    } catch (error) {
        //console.error('Error fetching loans:', error);
        return [];
    }
}
// ==========================================
// IMPROVED PAYMENT FETCH WITH CACHING
// ==========================================
const paymentCache = new Map();
const CACHE_DURATION = 5 * 60 * 1000; // 5 minutes

async function getPaymentsForLoan(applicationId, forceRefresh = false) {
    // Check cache first
    if (!forceRefresh && paymentCache.has(applicationId)) {
        const cached = paymentCache.get(applicationId);
        if (Date.now() - cached.timestamp < CACHE_DURATION) {
            return cached.data;
        }
    }

    try {
        const response = await apiCall(`payments.php?application_id=${applicationId}`);
        const payments = response.data || [];

        // Cache the result
        paymentCache.set(applicationId, {
            data: payments,
            timestamp: Date.now()
        });

        return payments;
    } catch (error) {
        //console.error('Error fetching payments:', error);

        // Return cached data if available, even if expired
        if (paymentCache.has(applicationId)) {
            //console.warn('Using expired cache for payments');
            return paymentCache.get(applicationId).data;
        }

        return [];
    }
}

function generateLoanId(loan) {
    return `${loan.id_number}_${loan.pf_number}_${loan.loan_type}_${loan.application_date}`;
}


// ==========================================
// FIXED RENDER LOANS FUNCTION (WITH ADVANCED FILTERS)
// ==========================================
let isRendering = false; // Prevent concurrent renders

/**
 * Render loans with optional filtering
 * @param {string} filter - Main view filter ('all', 'pending', 'active', etc.)
 * @param {string} [search=''] - Search term for name, ID, email, PF
 * @param {string} [statusFilter='All'] - Status dropdown value
 * @param {string} [loanTypeFilter='All'] - Loan type dropdown value
 */
async function renderLoans(filter = 'all', search = '', statusFilter = 'All', loanTypeFilter = 'All') {
    if (isRendering) {
        //console.warn('Render already in progress, skipping...');
        return;
    }

    isRendering = true;

    try {
        // Handle special views first (these ignore filters)
        if (filter === 'reconciliation') {
            hideAllSections();
            showReconciliationView(null);
            return;
        }

        if (filter === 'reports') {
            hideAllSections();
            showReportsView(null);
            return;
        }

        if (filter === 'debtors') {
            showDebtorsSection();
            return;
        }

        // Regular loan views
        showLoansSection(filter);

        // Reset UI elements
        if (DOM_ELEMENTS.loadingIndicator) DOM_ELEMENTS.loadingIndicator.style.display = 'none';
        if (DOM_ELEMENTS.errorMessage) DOM_ELEMENTS.errorMessage.style.display = 'none';

        // Hide special view content
        const reconciliationDiv = document.getElementById('reconciliationContent');
        if (reconciliationDiv) reconciliationDiv.style.display = 'none';

        const reportsDiv = document.getElementById('reportsContent');
        if (reportsDiv) reportsDiv.style.display = 'none';

        // Show main content
        DOM_ELEMENTS.mainContent.style.display = 'block';
        document.getElementById('loansSection').style.display = 'block';

        // Ensure loans table exists
        if (!DOM_ELEMENTS.loansTable) {
            DOM_ELEMENTS.mainContent.innerHTML = `
            <table id="loansTable">
                <thead>
                    <tr>
                        <th>Full Name</th>
                        <th>ID Number</th>
                        <th>PF Number</th>
                        <th>Loan Type</th>
                        <th>Details</th>
                        <th>Basic Salary</th>
                        <th>Loan Amount</th>
                        <th>Current Balance</th>
                        <th>Amortization</th>
                        <th>Status</th>
                        <th>Feedback</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        `;
            DOM_ELEMENTS.loansTable = document.getElementById('loansTable');
        }
        DOM_ELEMENTS.loansTable.style.display = 'table';

        // Fetch all loans (you can later optimize this to send filters to server)
        let loans = await getAllLoans();

        // Apply main view filter (pending, active, dispatched, etc.)
        switch (filter) {
            case 'pending':
                loans = loans.filter(l => l.status === 'pending');
                DOM_ELEMENTS.sectionTitle.textContent = 'Pending Loans';
                break;
            case 'forwarded':
                loans = loans.filter(l => l.status === 'forwarded');
                DOM_ELEMENTS.sectionTitle.textContent = 'Loans Forwarded to Accountant';
                break;
            case 'accepted':
                loans = loans.filter(l => l.status === 'accepted');
                DOM_ELEMENTS.sectionTitle.textContent = 'Accepted Loans (Awaiting Dispatch)';
                break;
            case 'dispatched':
                loans = loans.filter(l => l.status === 'dispatched' || l.status === 'active');
                DOM_ELEMENTS.sectionTitle.textContent = 'Dispatched/Active Loans';
                break;
            case 'active':
                loans = loans.filter(l => l.status === 'active');
                DOM_ELEMENTS.sectionTitle.textContent = 'Active Loans (In Repayment)';
                break;
            case 'rejected':
                loans = loans.filter(l => l.status === 'rejected');
                DOM_ELEMENTS.sectionTitle.textContent = 'Rejected Loans';
                break;
            case 'returned':
                loans = loans.filter(l => l.status === 'returned');
                DOM_ELEMENTS.sectionTitle.textContent = 'Loans Returned by Accountant';
                break;
            case 'closed':
                loans = loans.filter(l => l.status === 'closed');
                DOM_ELEMENTS.sectionTitle.textContent = 'Closed Loans';
                break;
            default:
                DOM_ELEMENTS.sectionTitle.textContent = 'All Loans';
        }

        // Apply additional filters from the new controls
        if (search.trim()) {
            const term = search.toLowerCase().trim();
            loans = loans.filter(loan => {
                return (
                    (loan.full_name?.toLowerCase().includes(term)) ||
                    (loan.id_number?.toLowerCase().includes(term)) ||
                    (loan.pf_number?.toLowerCase().includes(term)) ||
                    (loan.email?.toLowerCase().includes(term)) // if email exists in data
                );
            });
        }

        if (statusFilter !== 'All') {
            const targetStatus = statusFilter.toLowerCase();
            loans = loans.filter(loan => loan.status?.toLowerCase() === targetStatus);
        }

        if (loanTypeFilter !== 'All') {
            const targetType = loanTypeFilter.toLowerCase();
            loans = loans.filter(loan => loan.loan_type?.toLowerCase() === targetType);
        }

        // Render table
        const loansTableBody = DOM_ELEMENTS.loansTable.querySelector('tbody');
        loansTableBody.innerHTML = '';

        if (loans.length === 0) {
            loansTableBody.innerHTML = `
            <tr>
                <td colspan="12" style="text-align:center; padding: 30px;">
                    No loan applications match the current filters.
                </td>
            </tr>
        `;
            return;
        }

        // Sort by newest first
        loans.sort((a, b) => new Date(b.application_date) - new Date(a.application_date));

        // Fetch payments for active/dispatched loans
        const loansNeedingPayments = loans.filter(loan =>
            loan.status === 'active' || loan.status === 'dispatched'
        );

        const paymentPromises = loansNeedingPayments.map(async (loan) => {
            try {
                loan.payments = await getPaymentsForLoan(loan.application_id);
                loan.balanceInfo = await recalculateLoanBalance(loan);
            } catch (error) {
                //console.error(`Error fetching data for loan ${loan.application_id}:`, error);
                loan.payments = [];
                loan.balanceInfo = null;
            }
            return loan;
        });

        await Promise.race([
            Promise.all(paymentPromises),
            new Promise((_, reject) => setTimeout(() => reject(new Error('Timeout')), 8000))
        ]).catch(() => {
            //console.warn('Payment fetch timeout, continuing...');
        });

        // Build table rows
        for (const loan of loans) {
            const row = loansTableBody.insertRow();
            row.dataset.applicationId = loan.application_id;

            const isLegacy = (
                loan.financial_year !== null ||
                loan.record_type === 'legacy' ||
                (loan.application_id && loan.application_id.startsWith('MIGRATED-'))
            );

            if (isLegacy) row.classList.add('legacy-debtor');
            row.classList.add(`status-${loan.status}`);

            // Full Name
            row.insertCell().textContent = loan.full_name || 'N/A';

            // ID Number
            row.insertCell().textContent = loan.id_number || 'N/A';

            // PF Number
            row.insertCell().textContent = loan.pf_number || 'N/A';

            // Loan Type
            row.insertCell().textContent = loan.loan_type ?
                loan.loan_type.charAt(0).toUpperCase() + loan.loan_type.slice(1) :
                'N/A';

            // Details button
            const detailsCell = row.insertCell();
            const detailsBtn = document.createElement('button');
            detailsBtn.textContent = 'View';
            detailsBtn.className = 'action-btn';
            detailsBtn.onclick = () => showLoanDetails(loan);
            detailsCell.appendChild(detailsBtn);

            // Basic Salary
            row.insertCell().textContent = loan.basic_salary ?
                `KES ${formatCurrency(loan.basic_salary)}` :
                'N/A';

            // Loan Amount
            row.insertCell().textContent = loan.loan_amount ?
                `KES ${formatCurrency(loan.loan_amount)}` :
                'N/A';

            // Current Balance
            const balanceCell = row.insertCell();
            if (loan.status === 'active' || loan.status === 'dispatched') {
                if (loan.balanceInfo) {
                    balanceCell.innerHTML =
                        `<strong>KES ${formatCurrency(loan.balanceInfo.totalBalanceDue)}</strong>`;
                    if (loan.balanceInfo.accruedInterest > 0) {
                        balanceCell.innerHTML +=
                            `<br><small style="color:#d84315;">+KES ${formatCurrency(loan.balanceInfo.accruedInterest)} interest</small>`;
                    }
                } else {
                    balanceCell.innerHTML = '<span style="color:#999;">Calculating...</span>';
                    recalculateLoanBalance(loan).then(balanceInfo => {
                        balanceCell.innerHTML =
                            `<strong>KES ${formatCurrency(balanceInfo.totalBalanceDue)}</strong>`;
                        if (balanceInfo.accruedInterest > 0) {
                            balanceCell.innerHTML +=
                                `<br><small style="color:#d84315;">+KES ${formatCurrency(balanceInfo.accruedInterest)} interest</small>`;
                        }
                    }).catch(() => {
                        balanceCell.innerHTML = '<span style="color:#c62828;">Error</span>';
                    });
                }
            } else if (loan.status === 'closed') {
                balanceCell.innerHTML =
                    '<strong style="color:#2e7d32;">KES 0</strong><br><small>Paid Off</small>';
            } else {
                balanceCell.textContent = '-';
            }

            // Amortization button
            const amortCell = row.insertCell();
            const amortBtn = document.createElement('button');
            amortBtn.textContent = 'Schedule';
            amortBtn.className = 'action-btn';
            amortBtn.onclick = () => showAmortization(loan);
            amortCell.appendChild(amortBtn);

            // Status
            row.insertCell().textContent = loan.status ? loan.status.toUpperCase() : 'PENDING';

            // Feedback
            row.insertCell().textContent = loan.feedback || 'None';

            // Actions
            const actionsCell = row.insertCell();
            actionsCell.className = 'actions-cell';
            actionsCell.innerHTML = '';

            if (loan.status === 'pending' || loan.status === 'returned') {
                if (hasPermission('canForwardLoans')) {
                    actionsCell.appendChild(createActionButton('Forward', () => handleLoanAction(loan,
                        'forward')));
                }
                if (hasPermission('canRejectLoans')) {
                    actionsCell.appendChild(createActionButton('Reject', () => showFeedbackModal(loan,
                        'reject'), 'reject-btn'));
                }
            } else if (loan.status === 'forwarded') {
                actionsCell.innerHTML = '<span style="color:#666;">Awaiting Accountant...</span>';
            } else if (loan.status === 'accepted') {
                actionsCell.innerHTML =
                    '<span style="color:#16a085; font-weight:bold;">Awaiting Dispatch</span>';
            } else if (loan.status === 'dispatched' || loan.status === 'active') {
                if (hasPermission('canRecordPayments')) {
                    actionsCell.appendChild(createActionButton('Record', () => showRecordPaymentModal(loan), '',
                        '#2c7a2c'));
                }
                if (hasPermission('canCloseLoans')) {
                    actionsCell.appendChild(createActionButton('Close', () => handleLoanAction(loan, 'close')));
                }
            } else if (loan.status === 'rejected' || loan.status === 'closed') {
                if (hasPermission('canCloseLoans')) {
                    actionsCell.appendChild(createActionButton('Close', () => handleLoanAction(loan, 'close')));
                } else {
                    actionsCell.innerHTML = '<span style="color:#666;">Case Closed</span>';
                }
            }
        }

        // Export button
        if (hasPermission('canExportData')) {
            let exportBtn = document.getElementById('exportAllLoansBtn');
            if (!exportBtn) {
                exportBtn = document.createElement('button');
                exportBtn.id = 'exportAllLoansBtn';
                exportBtn.textContent = 'Export to CSV';
                exportBtn.className = 'action-btn';
                exportBtn.style.cssText = 'margin-left: 15px; background: #2196F3;';
                exportBtn.onclick = exportAllLoans;
                DOM_ELEMENTS.sectionTitle.appendChild(exportBtn);
            }
        }

    } catch (error) {
        //console.error('Error rendering loans:', error);
        showNotification('Error loading loans: ' + error.message, 'error');
    } finally {
        isRendering = false;
    }
}

document.getElementById('filterLoansBtn').addEventListener('click', () => {
    const search = document.getElementById('loanSearch').value.trim();
    const status = document.getElementById('statusFilter').value;
    const loanType = document.getElementById('loanTypeFilter').value;
    renderLoans('all', search, status, loanType); // 'all' view + filters
});

document.getElementById('clearLoansFilter').addEventListener('click', () => {
    document.getElementById('loanSearch').value = '';
    document.getElementById('statusFilter').value = 'All';
    document.getElementById('loanTypeFilter').value = 'All';
    renderLoans('all'); // Reset everything
});

document.getElementById('refreshLoans').addEventListener('click', () => {
    renderLoans('all'); // Refresh current view without changing filters
});

/**
 * Helper function to create a standardized action button.
 * @param {string} text - The button text.
 * @param {function} onClick - The onclick event handler.
 * @param {string} className - Additional CSS classes.
 * @param {string} background - Custom background color.
 * @returns {HTMLButtonElement} The created button element.
 */
function createActionButton(text, onClick, className = '', background = '') {
    const button = document.createElement('button');
    button.textContent = text;
    button.className = `action-btn ${className}`;
    if (background) button.style.background = background;
    button.onclick = onClick;
    return button;
}


// ==========================================
// REPORTS VIEW FUNCTION
// ==========================================

async function showReportsView(loans) {
    // Hide loading indicator
    if (DOM_ELEMENTS.loadingIndicator) {
        DOM_ELEMENTS.loadingIndicator.style.display = 'none';
    }

    if (DOM_ELEMENTS.errorMessage) {
        DOM_ELEMENTS.errorMessage.style.display = 'none';
    }

    // --- START OF FIX ---
    // Explicitly show the parent loansSection so the content inside is visible
    const loansSection = document.getElementById('loansSection');
    if (loansSection) {
        loansSection.classList.remove('hidden');
        loansSection.style.display = 'block';
    }
    // --- END OF FIX ---

    if (!loans) {
        loans = await getAllLoans();
    }

    DOM_ELEMENTS.sectionTitle.textContent = '<i data-lucide="bar-chart-3" class="icon-sm"></i> Financial Reports & Analytics';
    DOM_ELEMENTS.mainContent.style.display = 'block';
    DOM_ELEMENTS.loansTable.style.display = 'none';

    // Hide reconciliation content if visible
    const reconciliationDiv = document.getElementById('reconciliationContent');
    if (reconciliationDiv) {
        reconciliationDiv.style.display = 'none';
    }

    // Create reports container
    let reportsDiv = document.getElementById('reportsContent');
    if (!reportsDiv) {
        reportsDiv = document.createElement('div');
        reportsDiv.id = 'reportsContent';
        DOM_ELEMENTS.mainContent.appendChild(reportsDiv);
    } else {
        // Clear existing content before adding new content
        reportsDiv.innerHTML = '';
    }

    // Make sure the reports div is visible
    reportsDiv.style.display = 'block';

    // Add filter section
    let filterHtml = `
            <div class="filter-section">
                <h3 style="margin-top:0; color:#2e7d32;"><i data-lucide="search" class="icon-sm"></i> Filter Reports</h3>
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
                    <button onclick="applyReportFilters()">Apply Filters</button>
                    <button class="secondary" onclick="resetReportFilters()">Reset</button>
                </div>

                <div class="export-options">
                    <button id="exportCSVBtn" class="active" onclick="setReportExportFormat('csv')">CSV</button>
                    <button id="exportExcelBtn" onclick="setReportExportFormat('excel')">Excel</button>
                    <button onclick="exportFilteredReport()" class="action-btn"
                        style="background:#2196F3; margin-left: 10px;">
                        Export Filtered Report
                    </button>
                </div>
            </div>
            `;

    // Store filter state
    const reportFilters = {
        fromDate: null,
        toDate: null,
        loanType: 'all',
        pfNumber: null
    };

    // Function to apply filters
    window.applyReportFilters = function() {
        reportFilters.fromDate = document.getElementById('reportFromDate').value;
        reportFilters.toDate = document.getElementById('reportToDate').value;
        reportFilters.loanType = document.getElementById('reportLoanType').value;
        reportFilters.pfNumber = document.getElementById('reportPfNumber').value.trim();

        // Re-render reports with filters
        showReportsViewWithFilters(loans, reportFilters);
    };

    // Function to reset filters
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
        showReportsView(loans);
    };

    // Function to set export format
    window.setReportExportFormat = function(format) {
        if (format === 'excel' && typeof XLSX === 'undefined') {
            showNotification('Excel export library not loaded. Using CSV format instead.',
                'warning');
            document.getElementById('exportCSVBtn').classList.add('active');
            document.getElementById('exportExcelBtn').classList.remove('active');
            return;
        }

        if (format === 'excel') {
            document.getElementById('exportExcelBtn').classList.add('active');
            document.getElementById('exportCSVBtn').classList.remove('active');
        } else {
            document.getElementById('exportCSVBtn').classList.add('active');
            document.getElementById('exportExcelBtn').classList.remove('active');
        }
    };

    // Function to export filtered report
    window.exportFilteredReport = function() {
        const isExcelSelected = document.getElementById('exportExcelBtn').classList.contains(
            'active');
        exportFilteredReportData(loans, reportFilters, isExcelSelected);
    };

    // Add filter section to reports
    reportsDiv.innerHTML = filterHtml;

    // Show reports with initial filters
    showReportsViewWithFilters(loans, reportFilters);
}

async function showReportsViewWithFilters(loans, filters) {
    // Apply filters to loans
    let filteredLoans = [...loans];

    // Filter by date range
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

    // Filter by loan type
    if (filters.loanType !== 'all') {
        filteredLoans = filteredLoans.filter(loan => loan.loan_type === filters.loanType);
    }

    // Filter by PF number
    if (filters.pfNumber) {
        filteredLoans = filteredLoans.filter(loan =>
            loan.pf_number && loan.pf_number.toLowerCase().includes(filters.pfNumber
                .toLowerCase())
        );
    }

    // Fetch payment data for filtered loans
    const activeLoans = filteredLoans.filter(l => l.status === 'active' || l.status === 'dispatched');
    const closedLoans = filteredLoans.filter(l => l.status === 'closed');

    const paymentPromises = [...activeLoans, ...closedLoans].map(async (loan) => {
        try {
            const payments = await getPaymentsForLoan(loan.application_id);
            loan.payments = payments;
            return loan;
        } catch (error) {
            loan.payments = [];
            return loan;
        }
    });

    await Promise.all(paymentPromises);

    // Calculate statistics based on filtered data
    const mortgageLoans = filteredLoans.filter(l => l.loan_type === 'mortgage');
    const carLoans = filteredLoans.filter(l => l.loan_type === 'car');

    // FIX: Process ALL filtered loans, not just active/closed
    const loansToProcess = [...filteredLoans];

    // Overall statistics
    let totalLoanedAmount = 0;
    let totalPaidAmount = 0;
    let totalPrincipalPaid = 0;
    let totalInterestPaid = 0;
    let totalOutstandingBalance = 0;

    // By loan type
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

    // By year statistics
    const yearlyStats = {};

    for (const loan of [...activeLoans, ...closedLoans]) {
        const loanAmount = parseFloat(loan.loan_amount) || 0;
        const balanceInfo = await recalculateLoanBalance(loan);

        totalLoanedAmount += loanAmount;
        totalPaidAmount += parseFloat(balanceInfo.totalPaid) || 0;
        totalPrincipalPaid += parseFloat(balanceInfo.principalPaid) || 0;
        totalInterestPaid += parseFloat(balanceInfo.interestPaid) || 0;

        if (loan.status !== 'closed') {
            totalOutstandingBalance += parseFloat(balanceInfo.totalBalanceDue) || 0;
        }

        // By loan type
        const stats = loan.loan_type === 'mortgage' ? mortgageStats : carStats;
        stats.loaned += loanAmount;
        stats.paid += parseFloat(balanceInfo.totalPaid) || 0;
        stats.principal += parseFloat(balanceInfo.principalPaid) || 0;
        stats.interest += parseFloat(balanceInfo.interestPaid) || 0;
        if (loan.status !== 'closed') {
            stats.balance += parseFloat(balanceInfo.totalBalanceDue) || 0;
        }
        stats.count++;

        // By year
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

    // Generate HTML for reports
    let html = `
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px;">
        <!-- Overall Summary Card -->
        <div class="balance-highlight" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; position: relative;">
            <button onclick="exportOverallSummary()" style="position: absolute; top: 10px; right: 10px; background: rgba(255,255,255,0.3); border: none; color: white; padding: 5px 10px; border-radius: 5px; cursor: pointer; font-size: 0.8rem; transition: all 0.3s;" onmouseover="this.style.background='rgba(255,255,255,0.5)'" onmouseout="this.style.background='rgba(255,255,255,0.3)'">
                <i data-lucide="bar-chart-3" class="icon-sm"></i> Export </button>
            <h3 style="margin-top:0; color: white; padding-right: 80px;">💼 Overall Summary</h3>
            <p><strong>Total Loaned:</strong> KES ${formatCurrency(totalLoanedAmount)}</p>
            <p><strong>Total Loans:</strong> ${activeLoans.length + closedLoans.length}</p>
            <p><strong>Active Loans:</strong> ${activeLoans.length}</p>
            <p><strong>Closed Loans:</strong> ${closedLoans.length}</p>
        </div>

        <!-- Mortgage Summary Card -->
        <div class="balance-highlight" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; border: none; position: relative;">
            <button onclick="exportMortgageLoans()" style="position: absolute; top: 10px; right: 10px; background: rgba(255,255,255,0.3); border: none; color: white; padding: 5px 10px; border-radius: 5px; cursor: pointer; font-size: 0.8rem; transition: all 0.3s;" onmouseover="this.style.background='rgba(255,255,255,0.5)'" onmouseout="this.style.background='rgba(255,255,255,0.3)'">
                <i data-lucide="bar-chart-3" class="icon-sm"></i> Export
            </button>
            <h3 style="margin-top:0; color: white; padding-right: 80px;">🏠 Mortgage Loans</h3>
            <p><strong>Total Count:</strong> ${mortgageStats.count}</p>
            <p><strong>Total Loaned:</strong> KES ${formatCurrency(mortgageStats.loaned)}</p>
            <p><strong>Total Paid:</strong> KES ${formatCurrency(mortgageStats.paid)}</p>
            <p><strong>Outstanding:</strong> KES ${formatCurrency(mortgageStats.balance)}</p>
        </div>

        <!-- Car Loan Summary Card -->
        <div class="balance-highlight" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; border: none; position: relative;">
            <button onclick="exportCarLoans()" style="position: absolute; top: 10px; right: 10px; background: rgba(255,255,255,0.3); border: none; color: white; padding: 5px 10px; border-radius: 5px; cursor: pointer; font-size: 0.8rem; transition: all 0.3s;" onmouseover="this.style.background='rgba(255,255,255,0.5)'" onmouseout="this.style.background='rgba(255,255,255,0.3)'">
                <i data-lucide="bar-chart-3" class="icon-sm"></i> Export
            </button>
            <h3 style="margin-top:0; color: white; padding-right: 80px;">🚗 Car Loans</h3>
            <p><strong>Total Count:</strong> ${carStats.count}</p>
            <p><strong>Total Loaned:</strong> KES ${formatCurrency(carStats.loaned)}</p>
            <p><strong>Total Paid:</strong> KES ${formatCurrency(carStats.paid)}</p>
            <p><strong>Outstanding:</strong> KES ${formatCurrency(carStats.balance)}</p>
        </div>

        <!-- Payment Summary Card -->
        <div class="balance-highlight" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); color: white; border: none; position: relative;">
            <button onclick="exportPaymentSummary()" style="position: absolute; top: 10px; right: 10px; background: rgba(255,255,255,0.3); border: none; color: white; padding: 5px 10px; border-radius: 5px; cursor: pointer; font-size: 0.8rem; transition: all 0.3s;" onmouseover="this.style.background='rgba(255,255,255,0.5)'" onmouseout="this.style.background='rgba(255,255,255,0.3)'">
                <i data-lucide="bar-chart-3" class="icon-sm"></i> Export
            </button>
            <h3 style="margin-top:0; color: white; padding-right: 80px;"><i data-lucide="coins" class="icon-sm"></i> Payment Summary</h3>
            <p><strong>Total Paid:</strong> KES ${formatCurrency(totalPaidAmount)}</p>
            <p><strong>Principal Paid:</strong> KES ${formatCurrency(totalPrincipalPaid)}</p>
            <p><strong>Interest Paid:</strong> KES ${formatCurrency(totalInterestPaid)}</p>
            <p><strong>Outstanding:</strong> KES ${formatCurrency(totalOutstandingBalance)}</p>
        </div>
    </div>

    <!-- Yearly Breakdown -->
    <div class="balance-highlight">
        <h3 style="margin-top:0; color:#2e7d32;">📅 Yearly Breakdown</h3>
        <table style="width:100%; border-collapse:collapse; margin-top:15px;">
            <thead>
                <tr style="background:#3a6b9a; color:white;">
                    <th style="padding:12px; text-align:left;">Year</th>
                    <th style="padding:12px; text-align:center;">Total Loans</th>
                    <th style="padding:12px; text-align:center;">Mortgage</th>
                    <th style="padding:12px; text-align:center;">Car</th>
                    <th style="padding:12px; text-align:right;">Amount Loaned</th>
                    <th style="padding:12px; text-align:right;">Principal Paid</th>
                    <th style="padding:12px; text-align:right;">Interest Paid</th>
                    <th style="padding:12px; text-align:right;">Outstanding</th>
                </tr>
            </thead>
            <tbody>
`;

    const sortedYears = Object.keys(yearlyStats).sort((a, b) => b - a);
    sortedYears.forEach(year => {
        const stats = yearlyStats[year];
        html += `
        <tr style="border-bottom: 1px solid #e0e0e0;">
            <td style="padding:12px; font-weight:bold;">${year}</td>
            <td style="padding:12px; text-align:center;">${stats.count}</td>
            <td style="padding:12px; text-align:center;">${stats.mortgage.count} (KES ${formatCurrency(stats.mortgage.amount)})</td>
            <td style="padding:12px; text-align:center;">${stats.car.count} (KES ${formatCurrency(stats.car.amount)})</td>
            <td style="padding:12px; text-align:right; font-weight:bold;">KES ${formatCurrency(stats.loaned)}</td>
            <td style="padding:12px; text-align:right; color:#2e7d32;">KES ${formatCurrency(stats.principal)}</td>
            <td style="padding:12px; text-align:right; color:#d84315;">KES ${formatCurrency(stats.interest)}</td>
            <td style="padding:12px; text-align:right; color:#c62828;">KES ${formatCurrency(stats.balance)}</td>
        </tr>
    `;
    });

    html += `
            </tbody>
            <tfoot>
                <tr style="background:#f5f5f5; font-weight:bold; border-top: 2px solid #3a6b9a;">
                    <td style="padding:12px;">TOTAL</td>
                    <td style="padding:12px; text-align:center;">${activeLoans.length + closedLoans.length}</td>
                    <td style="padding:12px; text-align:center;">${mortgageStats.count}</td>
                    <td style="padding:12px; text-align:center;">${carStats.count}</td>
                    <td style="padding:12px; text-align:right;">KES ${formatCurrency(totalLoanedAmount)}</td>
                    <td style="padding:12px; text-align:right; color:#2e7d32;">KES ${formatCurrency(totalPrincipalPaid)}</td>
                    <td style="padding:12px; text-align:right; color:#d84315;">KES ${formatCurrency(totalInterestPaid)}</td>
                    <td style="padding:12px; text-align:right; color:#c62828;">KES ${formatCurrency(totalOutstandingBalance)}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- Detailed Active Loans -->
    <div class="balance-highlight" style="margin-top:20px;">
        <h3 style="margin-top:0; color:#2e7d32;"><i data-lucide="clipboard-list" class="icon-sm"></i> Active Loans Details</h3>
        <table style="width:100%; border-collapse:collapse; margin-top:15px;">
            <thead>
                <tr style="background:#3a6b9a; color:white;">
                    <th style="padding:12px;">Loanee</th>
                    <th style="padding:12px;">PF Number</th>
                    <th style="padding:12px;">Type</th>
                    <th style="padding:12px;">Start Date</th>
                    <th style="padding:12px; text-align:right;">Original Amount</th>
                    <th style="padding:12px; text-align:right;">Principal Paid</th>
                    <th style="padding:12px; text-align:right;">Interest Paid</th>
                    <th style="padding:12px; text-align:right;">Balance Due</th>
                </tr>
            </thead>
            <tbody>
`;

    for (const loan of activeLoans) {
        const balanceInfo = await recalculateLoanBalance(loan);
        html += `
        <tr style="border-bottom: 1px solid #e0e0e0;">
            <td style="padding:12px;">${loan.full_name}</td>
            <td style="padding:12px;">${loan.pf_number}</td>
            <td style="padding:12px;">${loan.loan_type === 'mortgage' ? '🏠 Mortgage' : '🚗 Car'}</td>
            <td style="padding:12px;">${formatDate(loan.dispatch_date || loan.application_date)}</td>
            <td style="padding:12px; text-align:right;">KES ${formatCurrency(loan.loan_amount)}</td>
            <td style="padding:12px; text-align:right; color:#2e7d32;">KES ${formatCurrency(balanceInfo.principalPaid)}</td>
            <td style="padding:12px; text-align:right; color:#d84315;">KES ${formatCurrency(balanceInfo.interestPaid)}</td>
            <td style="padding:12px; text-align:right; color:#c62828; font-weight:bold;">KES ${formatCurrency(balanceInfo.totalBalanceDue)}</td>
        </tr>
    `;
    }

    html += `
</tbody>
</table>
</div>
`;

    // Append to reports container
    const reportsDiv = document.getElementById('reportsContent');

    // Preserve the filter section
    const filterSection = reportsDiv.querySelector('.filter-section');
    const filterHtml = filterSection ? filterSection.outerHTML : '';

    // Replace content (not append) to avoid duplicates
    reportsDiv.innerHTML = filterHtml + html;
    reportsDiv.style.display = 'block';
}


// Function to export filtered report data
async function exportFilteredReportData(loans, filters, isExcel) {
    if (!hasPermission('canExportData')) {
        showNotification('You do not have permission to export data.', 'error');
        return;
    }

    try {
        // Apply same filters as in the view
        let filteredLoans = [...loans];

        // Filter by date range
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

        // Filter by loan type
        if (filters.loanType !== 'all') {
            filteredLoans = filteredLoans.filter(loan => loan.loan_type === filters.loanType);
        }

        // Filter by PF number
        if (filters.pfNumber) {
            filteredLoans = filteredLoans.filter(loan =>
                loan.pf_number && loan.pf_number.toLowerCase().includes(filters.pfNumber
                    .toLowerCase())
            );
        }

        // Fetch payment data for filtered loans
        const activeLoans = filteredLoans.filter(l => l.status === 'active' || l.status ===
            'dispatched');
        const closedLoans = filteredLoans.filter(l => l.status === 'closed');

        const paymentPromises = [...activeLoans, ...closedLoans].map(async (loan) => {
            try {
                const payments = await getPaymentsForLoan(loan.application_id);
                loan.payments = payments;
                return loan;
            } catch (error) {
                loan.payments = [];
                return loan;
            }
        });

        await Promise.all(paymentPromises);

        // Prepare data for export
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
                .slice(
                    1) :
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

        // Export based on selected format
        if (isExcel && typeof XLSX !== 'undefined') {
            // Export to Excel using SheetJS
            const wb = XLSX.utils.book_new();
            const ws = XLSX.utils.aoa_to_sheet([headers, ...data]);

            // Set column widths
            const wscols = [{
                    wch: 15
                }, {
                    wch: 20
                }, {
                    wch: 15
                }, {
                    wch: 15
                },
                {
                    wch: 15
                },
                {
                    wch: 18
                }, {
                    wch: 15
                }, {
                    wch: 15
                }, {
                    wch: 15
                },
                {
                    wch: 18
                },
                {
                    wch: 12
                },
                {
                    wch: 15
                },
                {
                    wch: 15
                },
                {
                    wch: 15
                },
                {
                    wch: 20
                }
            ];
            ws['!cols'] = wscols;

            XLSX.utils.book_append_sheet(wb, ws, "Filtered Report");

            // Generate filename with filter info
            let filename = `Filtered_Report_${new Date().toISOString().split('T')[0]}`;
            if (filters.fromDate) filename += `_From_${filters.fromDate}`;
            if (filters.toDate) filename += `_To_${filters.toDate}`;
            if (filters.loanType !== 'all') filename += `_${filters.loanType}`;
            if (filters.pfNumber) filename += `_PF_${filters.pfNumber}`;
            filename += '.xlsx';

            XLSX.writeFile(wb, filename);
            showNotification('Excel report exported successfully!', 'success');
        } else {
            // Export to CSV
            const csv = convertToCSV(data, headers);

            // Generate filename with filter info
            let filename = `Filtered_Report_${new Date().toISOString().split('T')[0]}`;
            if (filters.fromDate) filename += `_From_${filters.fromDate}`;
            if (filters.toDate) filename += `_To_${filters.toDate}`;
            if (filters.loanType !== 'all') filename += `_${filters.loanType}`;
            if (filters.pfNumber) filename += `_PF_${filters.pfNumber}`;
            filename += '.csv';

            downloadCSV(filename, csv);
        }
    } catch (error) {
        showNotification('Error exporting filtered report: ' + error.message, 'error');
    }
}



async function showReconciliationView(loans) {
    // Hide loading indicator
    if (DOM_ELEMENTS.loadingIndicator) {
        DOM_ELEMENTS.loadingIndicator.style.display = 'none';
    }

    if (DOM_ELEMENTS.errorMessage) {
        DOM_ELEMENTS.errorMessage.style.display = 'none';
    }

    // --- CRITICAL FIX ---
    // Explicitly show the parent loansSection so the content inside is visible
    const loansSection = document.getElementById('loansSection');
    if (loansSection) {
        loansSection.classList.remove('hidden');
        loansSection.style.display = 'block';
    }
    // --- END FIX ---

    if (!loans) {
        loans = (await getAllLoans()).filter(l => l.status === 'active' || l.status ===
            'dispatched');
    } else {
        loans = loans.filter(l => l.status === 'active' || l.status === 'dispatched');
    }

    DOM_ELEMENTS.sectionTitle.textContent = '<i data-lucide="coins" class="icon-sm"></i> Payment Reconciliation Dashboard';
    DOM_ELEMENTS.mainContent.style.display = 'block';
    DOM_ELEMENTS.loansTable.style.display = 'none';

    // Fetch payment data for all loans
    const paymentPromises = loans.map(async (loan) => {
        try {
            const payments = await getPaymentsForLoan(loan.application_id);
            loan.payments = payments;
            return loan;
        } catch (error) {
            loan.payments = [];
            return loan;
        }
    });

    await Promise.all(paymentPromises);

    // Calculate comprehensive statistics
    let totalExpectedPayments = 0;
    let totalActualPayments = 0;
    let totalVariance = 0;
    let totalOverdueAmount = 0;
    let loansWithOverdue = 0;
    let loansOnTrack = 0;
    let totalPrincipalVariance = 0;
    let totalInterestVariance = 0;

    const reconciliationData = [];

    for (const loan of loans) {
        const balanceInfo = await recalculateLoanBalance(loan);
        await updatepayment_scheduleWithActuals(loan);

        const schedule = loan.payment_schedule || [];
        const overduePayments = schedule.filter(p => p.status === 'overdue');
        const paidPayments = schedule.filter(p => p.status === 'paid' || p.status === 'partial');

        // Calculate expected vs actual
        let expectedToDate = 0;
        let actualToDate = balanceInfo.totalPaid;
        let principalVariance = 0;
        let interestVariance = 0;
        let overdueAmount = 0;

        paidPayments.forEach(p => {
            expectedToDate += p.expectedAmount;
            principalVariance += (p.actualAmount || 0) - p.expectedAmount;
            interestVariance += (p.actualInterest || 0) - p.expectedInterest;
        });

        overduePayments.forEach(p => {
            overdueAmount += p.expectedAmount;
            expectedToDate += p.expectedAmount;
        });

        const variance = actualToDate - expectedToDate;

        totalExpectedPayments += expectedToDate;
        totalActualPayments += actualToDate;
        totalVariance += variance;
        totalOverdueAmount += overdueAmount;
        totalPrincipalVariance += principalVariance;
        totalInterestVariance += interestVariance;

        if (overduePayments.length > 0) {
            loansWithOverdue++;
        } else {
            loansOnTrack++;
        }

        reconciliationData.push({
            loan,
            balanceInfo,
            overduePayments: overduePayments.length,
            overdueAmount,
            expectedToDate,
            actualToDate,
            variance,
            principalVariance,
            interestVariance,
            paymentPerformance: variance >= 0 ? 'On Track' : 'Behind',
            riskLevel: overduePayments.length > 3 ? 'High' : overduePayments.length > 1 ?
                'Medium' : 'Low'
        });
    }

    // Sort by risk level and overdue amount
    reconciliationData.sort((a, b) => {
        const riskOrder = {
            'High': 3,
            'Medium': 2,
            'Low': 1
        };
        if (riskOrder[a.riskLevel] !== riskOrder[b.riskLevel]) {
            return riskOrder[b.riskLevel] - riskOrder[a.riskLevel];
        }
        return b.overdueAmount - a.overdueAmount;
    });

    // Add date range selector
    let html = `
                    <div class="date-range-selector">
                        <label>From Date:</label>
                        <input type="date" id="reconFromDate" value="${APP_STATE.reconDateRange.from || ''}">
                        <label>To Date:</label>
                        <input type="date" id="reconToDate" value="${APP_STATE.reconDateRange.to || ''}">
                        <button onclick="applyDateRangeFilter()">Apply Filter</button>
                        <button onclick="resetDateRangeFilter()">Reset</button>
                    </div>

                    <!-- Summary Statistics Cards -->
                    <div
                        style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 25px;">
                        <div class="balance-highlight"
                            style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none;">
                            <h4 style="margin: 0; color: white; font-size: 0.9rem;">Total Active Loans</h4>
                            <p style="font-size: 2rem; font-weight: bold; margin: 10px 0;">${loans.length}</p>
                            <p style="margin: 0; font-size: 0.85rem;">On Track: ${loansOnTrack} | Issues:
                                ${loansWithOverdue}</p>
                        </div>

                        <div class="balance-highlight"
                            style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; border: none;">
                            <h4 style="margin: 0; color: white; font-size: 0.9rem;">Expected Payments</h4>
                            <p style="font-size: 2rem; font-weight: bold; margin: 10px 0;">KES
                                ${formatCurrency(totalExpectedPayments)}</p>
                            <p style="margin: 0; font-size: 0.85rem;">As of today</p>
                        </div>

                        <div class="balance-highlight"
                            style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; border: none;">
                            <h4 style="margin: 0; color: white; font-size: 0.9rem;">Actual Payments</h4>
                            <p style="font-size: 2rem; font-weight: bold; margin: 10px 0;">KES
                                ${formatCurrency(totalActualPayments)}</p>
                            <p style="margin: 0; font-size: 0.85rem;">Total received</p>
                        </div>

                        <div class="balance-highlight"
                            style="background: linear-gradient(135deg, ${totalVariance >= 0 ? '#43e97b 0%, #38f9d7' : '#fa709a 0%, #fee140'} 100%); color: white; border: none;">
                            <h4 style="margin: 0; color: white; font-size: 0.9rem;">Payment Variance</h4>
                            <p style="font-size: 2rem; font-weight: bold; margin: 10px 0;">${totalVariance >= 0
                                ? '+' : ''}KES ${formatCurrency(Math.abs(totalVariance))}</p>
                            <p style="margin: 0; font-size: 0.85rem;">${totalVariance >= 0 ? 'Surplus' :
                                'Shortfall'}</p>
                        </div>

                        <div class="balance-highlight"
                            style="background: linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%); color: white; border: none;">
                            <h4 style="margin: 0; color: white; font-size: 0.9rem;">Total Overdue</h4>
                            <p style="font-size: 2rem; font-weight: bold; margin: 10px 0;">KES
                                ${formatCurrency(totalOverdueAmount)}</p>
                            <p style="margin: 0; font-size: 0.85rem;">${loansWithOverdue} loans affected</p>
                        </div>

                        <div class="balance-highlight"
                            style="background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%); color: #333; border: none;">
                            <h4 style="margin: 0; font-size: 0.9rem;">Collection Rate</h4>
                            <p style="font-size: 2rem; font-weight: bold; margin: 10px 0;">
                                ${totalExpectedPayments > 0 ? ((totalActualPayments / totalExpectedPayments) *
                                100).toFixed(1) : 0}%</p>
                            <p style="margin: 0; font-size: 0.85rem;">Payment efficiency</p>
                        </div>
                    </div>

                    <!-- Variance Analysis -->
                    <div class="balance-highlight" style="margin-bottom: 20px;">
                        <h3 style="margin-top:0; color:#2e7d32;"><i data-lucide="bar-chart-3" class="icon-sm"></i> Variance Analysis</h3>
                        <div
                            style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
                            <div>
                                <strong>Principal Variance:</strong>
                                <p
                                    style="font-size: 1.3rem; color: ${totalPrincipalVariance >= 0 ? '#2e7d32' : '#c62828'}; margin: 5px 0;">
                                    ${totalPrincipalVariance >= 0 ? '+' : ''}KES
                                    ${formatCurrency(Math.abs(totalPrincipalVariance))}
                                </p>
                            </div>
                            <div>
                                <strong>Interest Variance:</strong>
                                <p
                                    style="font-size: 1.3rem; color: ${totalInterestVariance >= 0 ? '#2e7d32' : '#c62828'}; margin: 5px 0;">
                                    ${totalInterestVariance >= 0 ? '+' : ''}KES
                                    ${formatCurrency(Math.abs(totalInterestVariance))}
                                </p>
                            </div>
                            <div>
                                <strong>Total Variance:</strong>
                                <p
                                    style="font-size: 1.3rem; color: ${totalVariance >= 0 ? '#2e7d32' : '#c62828'}; margin: 5px 0;">
                                    ${totalVariance >= 0 ? '+' : ''}KES
                                    ${formatCurrency(Math.abs(totalVariance))}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Export Options -->
                    <div class="export-options">
                        <button id="exportCSVBtn" class="active" onclick="setExportFormat('csv')">CSV</button>
                        <button id="exportExcelBtn" onclick="setExportFormat('excel')">Excel</button>
                        <button onclick="exportReconciliationReport()" class="action-btn"
                            style="background:#2196F3; margin-left: 10px;">
                            Export Report
                        </button>
                    </div>

                    <!-- Detailed Reconciliation Table -->
                    <div style="overflow-x: auto;">
                        <table id="reconciliationTable"
                            style="width:100%; border-collapse:collapse; margin-top:20px; font-size: 0.9rem; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);">
                            <thead>
                                <tr style="background:#1a3a5a; color:white;">
                                    <th style="padding:10px;">Risk</th>
                                    <th style="padding:10px;">Loanee</th>
                                    <th style="padding:10px;">PF Number</th>
                                    <th style="padding:10px;">Loan Type</th>
                                    <th style="padding:10px;">Original Amount</th>
                                    <th style="padding:10px;">Expected Paid</th>
                                    <th style="padding:10px;">Actual Paid</th>
                                    <th style="padding:10px;">Variance</th>
                                    <th style="padding:10px;">Current Balance</th>
                                    <th style="padding:10px;">Overdue Payments</th>
                                    <th style="padding:10px;">Overdue Amount</th>
                                    <th style="padding:10px;">Last Payment</th>
                                    <th style="padding:10px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                `;


    const riskColors = {
        'High': '#c62828',
        'Medium': '#f57c00',
        'Low': '#2e7d32'
    };

    reconciliationData.forEach(data => {

        // Use consistent row styling with other tables
        let rowClass = 'status-default';
        if (data.riskLevel === 'High') {
            rowClass = 'status-active'; // Use existing status class for high risk
        } else if (data.riskLevel === 'Medium') {
            rowClass = 'status-dispatched'; // Use existing status class for medium risk
        } else {
            rowClass = 'status-closed'; // Use existing status class for low risk
        }

        html += `
                                <tr class="${rowClass}">
                                    <td style="padding:10px;">
                                        <span
                                            style="background: ${riskColors[data.riskLevel]}; color: white; padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 0.75rem;">
                                            ${data.riskLevel}
                                        </span>
                                    </td>
                                    <td style="padding:10px; font-weight: bold;">${data.loan.full_name}</td>
                                    <td style="padding:10px;">${data.loan.pf_number}</td>
                                    <td style="padding:10px;">${data.loan.loan_type.toUpperCase()}</td>
                                    <td style="padding:10px;">KES ${formatCurrency(data.loan.loan_amount)}</td>
                                    <td style="padding:10px;">KES ${formatCurrency(data.expectedToDate)}</td>
                                    <td style="padding:10px; font-weight: bold;">KES
                                        ${formatCurrency(data.actualToDate)}</td>
                                    <td
                                        style="padding:10px; color: ${data.variance >= 0 ? '#2e7d32' : '#c62828'}; font-weight: bold;">
                                        ${data.variance >= 0 ? '+' : ''}KES
                                        ${formatCurrency(Math.abs(data.variance))}
                                    </td>
                                    <td style="padding:10px; font-weight: bold;">KES
                                        ${formatCurrency(data.balanceInfo.totalBalanceDue)}</td>
                                    <td style="padding:10px; text-align: center;">
                                        ${data.overduePayments > 0 ? `<span
                                            style="background: #c62828; color: white; padding: 4px 8px; border-radius: 4px; font-weight: bold;">${data.overduePayments}</span>`
                                        : '-'}
                                    </td>
                                    <td
                                        style="padding:10px; color: ${data.overdueAmount > 0 ? '#c62828' : '#666'}; font-weight: bold;">
                                        ${data.overdueAmount > 0 ? 'KES ' + formatCurrency(data.overdueAmount) :
                                        '-'}
                                    </td>
                                    <td style="padding:10px; font-size: 0.85rem;">
                                        ${formatDate(data.balanceInfo.lastPaymentDate)}<br>
                                        <small style="color: #666;">(${data.balanceInfo.monthsSinceLastPayment}
                                            months ago)</small>
                                    </td>
                                    <td style="padding:10px;">
                                    <button class="action-btn"
                                    onclick="showDetailedReconciliation(${JSON.stringify(data.loan).replace(/"/g, '&quot;')})" 
                                    style="font-size: 0.85rem; padding: 6px 12px;">
                                    View Details
                                    </button>
                                    </td>
                                </tr>
                                `;
    });

    html += `
                            </tbody>
                            <tfoot>
                                <tr style="background: #f5f5f5; font-weight: bold; border-top: 2px solid #3a6b9a;">
                                    <td colspan="5" style="padding:12px; text-align: right;">TOTALS:</td>
                                    <td style="padding:12px;">KES ${formatCurrency(totalExpectedPayments)}</td>
                                    <td style="padding:12px;">KES ${formatCurrency(totalActualPayments)}</td>
                                    <td style="padding:12px; color: ${totalVariance >= 0 ? '#2e7d32' : '#c62828'};">
                                        ${totalVariance >= 0 ? '+' : ''}KES
                                        ${formatCurrency(Math.abs(totalVariance))}
                                    </td>
                                    <td colspan="2" style="padding:12px;"></td>
                                    <td style="padding:12px; color: #c62828;">KES
                                        ${formatCurrency(totalOverdueAmount)}</td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    `;

    let reconciliationDiv = document.getElementById('reconciliationContent');
    if (!reconciliationDiv) {
        reconciliationDiv = document.createElement('div');
        reconciliationDiv.id = 'reconciliationContent';
        DOM_ELEMENTS.mainContent.appendChild(reconciliationDiv);
    }
    reconciliationDiv.innerHTML = html;
    reconciliationDiv.style.display = 'block';
}

async function showDetailedReconciliation(loan) {
    if (!loan) {
        showNotification('Error: Invalid loan data', 'error');
        return;
    }

    // Fetch payment data if not already loaded
    if (!loan.payments) {
        try {
            const payments = await getPaymentsForLoan(loan.application_id);
            loan.payments = payments;
        } catch (error) {
            //console.error('Error fetching payments:', error);
            showNotification('Error fetching payment history', 'error');
            loan.payments = [];
        }
    }

    const balanceInfo = await recalculateLoanBalance(loan);
    const schedule = await updatepayment_scheduleWithActuals(loan);

    let html = `
                    <div class="balance-highlight">
                        <h3 style="margin-top:0; color:#2e7d32;">Loanee Information</h3>
                        <p><strong>Full Name:</strong> ${loan.full_name}</p>
                        <p><strong>ID Number:</strong> ${loan.id_number}</p>
                        <p><strong>PF Number:</strong> ${loan.pf_number}</p>
                        <p><strong>Loan Type:</strong> ${loan.loan_type ? loan.loan_type.charAt(0).toUpperCase()
                            + loan.loan_type.slice(1) : 'N/A'}</p>
                        <p><strong>Original Amount:</strong> KES ${formatCurrency(loan.loan_amount)}</p>
                        <p><strong>Current Balance:</strong> KES ${formatCurrency(balanceInfo.totalBalanceDue)}
                        </p>
                        <p><strong>Status:</strong> ${loan.status ? loan.status.toUpperCase() : 'PENDING'}</p>
                    </div>

                    <h3 style="color:#4a6b4a; margin-top:20px;">Payment Reconciliation Details</h3>
                    <div style="overflow-x: auto;">
                        <table id="detailedReconciliationTable"
                            style="width:100%; border-collapse:collapse; margin-top:15px;">
                            <thead>
                                <tr style="background:#1a3a5a; color:white;">
                                    <th style="padding:10px;">Payment #</th>
                                    <th style="padding:10px;">Due Date</th>
                                    <th style="padding:10px;">Expected Amount</th>
                                    <th style="padding:10px;">Actual Amount</th>
                                    <th style="padding:10px;">Expected Interest</th>
                                    <th style="padding:10px;">Actual Interest</th>
                                    <th style="padding:10px;">Expected Principal</th>
                                    <th style="padding:10px;">Balance</th>
                                    <th style="padding:10px;">Status</th>
                                    <th style="padding:10px;">Variance</th>
                                </tr>
                            </thead>
                            <tbody>
                                `;

    if (schedule.payment_schedule && schedule.payment_schedule.length > 0) {
        schedule.payment_schedule.forEach((payment) => {
            // Create status badges instead of using background colors
            let statusBadge = '';
            const statusColors = {
                'paid': '#2e7d32',
                'partial': '#f57c00',
                'pending': '#666666',
                'overdue': '#c62828'
            };

            const statusText = payment.status.toUpperCase();
            const statusColor = statusColors[payment.status] || '#666666';

            statusBadge =
                `<span
                                    style="background: ${statusColor}; color: white; padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 0.75rem;">${statusText}</span>`;

            html += `
                                <tr>
                                    <td style="padding:10px;">${payment.paymentNumber}</td>
                                    <td style="padding:10px;">${formatDate(payment.dueDate)}</td>
                                    <td style="padding:10px;">KES ${formatCurrency(payment.expectedAmount)}</td>
                                    <td style="padding:10px;">${payment.actualAmount > 0 ? 'KES ' +
                                        formatCurrency(payment.actualAmount) : '-'}</td>
                                    <td style="padding:10px;">KES ${formatCurrency(payment.expectedInterest)}
                                    </td>
                                    <td style="padding:10px;">${payment.actualInterest > 0 ? 'KES ' +
                                        formatCurrency(payment.actualInterest) : '-'}</td>
                                    <td style="padding:10px;">KES ${formatCurrency(payment.expectedPrincipal)}
                                    </td>
                                    <td style="padding:10px;">KES ${formatCurrency(payment.expectedBalance)}
                                    </td>
                                    <td style="padding:10px;">${statusBadge}</td>
                                    <td
                                        style="padding:10px; color:${payment.variance > 0 ? 'green' : payment.variance < 0 ? 'red' : 'black'};">
                                        ${payment.variance !== 0 && payment.actualAmount > 0 ?
                                        (payment.variance > 0 ? '+' : '') + 'KES ' +
                                        formatCurrency(Math.abs(payment.variance)) :
                                        '-'}
                                    </td>
                                </tr>
                                `;
        });
    } else {
        html += `
                                <tr>
                                    <td colspan="10" style="text-align:center; padding:30px;">No payment
                                        schedule available</td>
                                </tr>
                                `;
    }

    html += `
                            </tbody>
                        </table>
                    </div>

                    <div style="margin-top:20px;">
                        <button onclick="exportDetailedReconciliation(${JSON.stringify(loan).replace(/" /g, '&quot;'
                            )})" class="action-btn" style="background:#2196F3;">
                            Export Details
                        </button>
                    </div>
                    `;

    document.getElementById('detailedReconciliationContent').innerHTML = html;
    document.getElementById('detailedReconciliationModal').style.display = 'flex';
}

// Also add this export function that's referenced in the above function
function exportDetailedReconciliation(loan) {
    if (!hasPermission('canExportData')) {
        showNotification('You do not have permission to export data.', 'error');
        return;
    }

    // Create CSV content
    let csv = `Detailed Reconciliation Report for ${loan.full_name} (PF: ${loan.pf_number})\n`;
    csv += `Generated on: ${new Date().toLocaleString()}\n\n`;

    // Add loan information
    csv += `Loan Information\n`;
    csv += `Full Name,${loan.full_name}\n`;
    csv += `ID Number,${loan.id_number}\n`;
    csv += `PF Number,${loan.pf_number}\n`;
    csv +=
        `Loan Type,${loan.loan_type ? loan.loan_type.charAt(0).toUpperCase() +
                    loan.loan_type.slice(1) : 'N/A'}\n`;
    csv += `Original Amount,KES ${formatCurrency(loan.loan_amount)}\n`;
    csv += `Status,${loan.status ? loan.status.toUpperCase() : 'PENDING'}\n\n`;

    // Add payment schedule
    csv += `Payment Schedule\n`;
    csv +=
        `Payment #,Due Date,Expected Amount,Actual Amount,Expected Interest,Actual Interest,Expected
                    Principal,Balance,Status,Variance\n`;

    // Get the payment schedule data from the modal
    const rows = document.querySelectorAll('#detailedReconciliationModal tbody tr');
    rows.forEach(row => {
        const cells = row.querySelectorAll('td');
        const rowData = [];
        cells.forEach(cell => {
            let text = cell.innerText.replace(/"/g, '""');
            rowData.push(`"${text}"`);
        });
        csv += rowData.join(',') + '\n';
    });

    // Download CSV
    const blob = new Blob([csv], {
        type: 'text/csv'
    });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download =
        `detailed_reconciliation_${loan.full_name}_${loan.pf_number}_${new
                    Date().toISOString().split('T')[0]}.csv`;
    link.click();

    showNotification('Detailed reconciliation report exported successfully!', 'success');
}

// Add this function after the existing export functions
async function exportLoanDetails() {
    if (!hasPermission('canExportData')) {
        showNotification('You do not have permission to export data.', 'error');
        return;
    }

    try {
        // Get loan data from session storage
        const loanData = sessionStorage.getItem('currentLoanForExport');
        if (!loanData) {
            showNotification('No loan data available for export', 'error');
            return;
        }

        const loan = JSON.parse(loanData);

        // Fetch payment data if not already loaded
        if (!loan.payments) {
            try {
                const payments = await getPaymentsForLoan(loan.application_id);
                loan.payments = payments;
            } catch (error) {
                loan.payments = [];
            }
        }

        const balanceInfo = await recalculateLoanBalance(loan);

        // Create PDF content
        const {
            jsPDF
        } = window.jspdf;
        const doc = new jsPDF();

        // Add title
        doc.setFontSize(18);
        doc.text('Loan Details Report', 14, 22);

        // Add loanee information
        doc.setFontSize(12);
        doc.text('Loanee Information', 14, 35);
        doc.setFontSize(10);
        doc.text(`Name: ${loan.full_name}`, 20, 42);
        doc.text(`ID Number: ${loan.id_number}`, 20, 48);
        doc.text(`PF Number: ${loan.pf_number}`, 20, 54);
        doc.text(`Email: ${loan.email || 'N/A'}`, 20, 60);

        // Add loan information
        doc.setFontSize(12);
        doc.text('Loan Information', 14, 72);
        doc.setFontSize(10);
        doc.text(`Application ID: ${loan.application_id}`, 20, 79);
        doc.text(
            `Loan Type: ${loan.loan_type ? loan.loan_type.charAt(0).toUpperCase() +
                    loan.loan_type.slice(1) : 'N/A'}`,
            20, 85);
        doc.text(`Application Date: ${formatDate(loan.application_date)}`, 20, 91);
        doc.text(`Status: ${loan.status ? loan.status.toUpperCase() : 'PENDING'}`, 20, 97);
        doc.text(`Payment Duration: ${loan.payment_duration || 'N/A'} years`, 20, 103);

        // Add financial information
        doc.setFontSize(12);
        doc.text('Financial Information', 14, 115);
        doc.setFontSize(10);
        doc.text(`Original Loan Amount: KES ${formatCurrency(loan.loan_amount)}`, 20, 122);
        doc.text(`Total Paid: KES ${formatCurrency(balanceInfo.totalPaid)}`, 20, 128);
        doc.text(`Principal Paid: KES ${formatCurrency(balanceInfo.principalPaid)}`, 20, 134);
        doc.text(`Interest Paid: KES ${formatCurrency(balanceInfo.interestPaid)}`, 20, 140);
        doc.text(`Current Principal Balance: KES ${formatCurrency(balanceInfo.currentPrincipal)}`,
            20,
            146);
        doc.text(
            `Accrued Interest (${balanceInfo.monthsSinceLastPayment} months): KES
                    ${formatCurrency(balanceInfo.accruedInterest)}`,
            20, 152);
        doc.text(`Total Balance Due: KES ${formatCurrency(balanceInfo.totalBalanceDue)}`, 20, 158);

        // Add payment history if available
        if (loan.payments && loan.payments.length > 0) {
            doc.addPage();
            doc.setFontSize(12);
            doc.text('Payment History', 14, 22);

            // Create table headers
            const headers = ['#', 'Date', 'Amount', 'Interest', 'Principal', 'Balance', 'Source'];
            let yPosition = 35;

            doc.setFontSize(10);
            headers.forEach((header, index) => {
                doc.text(header, 20 + (index * 25), yPosition);
            });

            yPosition += 7;

            // Add payment data
            loan.payments.sort((a, b) => new Date(b.payment_date) - new Date(a.payment_date));
            loan.payments.forEach((payment, index) => {
                if (yPosition > 270) {
                    doc.addPage();
                    yPosition = 22;
                }

                doc.text(`${index + 1}`, 20, yPosition);
                doc.text(formatDate(payment.payment_date), 45, yPosition);
                doc.text(`KES ${formatCurrency(payment.amount)}`, 70, yPosition);
                doc.text(`KES ${formatCurrency(payment.interest_amount || 0)}`, 95,
                    yPosition);
                doc.text(`KES ${formatCurrency(payment.principal_amount || 0)}`, 120,
                    yPosition);
                doc.text(`KES ${formatCurrency(payment.balance_after || 0)}`, 145,
                    yPosition);
                doc.text(payment.payment_source, 170, yPosition);

                yPosition += 7;
            });
        }

        // Save the PDF
        doc.save(
            `loan_details_${loan.full_name}_${loan.pf_number}_${new
                    Date().toISOString().split('T')[0]}.pdf`
        );

        showNotification('Loan details exported successfully!', 'success');
    } catch (error) {
        showNotification('Error exporting loan details: ' + error.message, 'error');
    }

    //Call to re-initialize export buttons
    initializeExportButtons();
}

// ==========================================
// MODAL FUNCTIONS
// ==========================================
async function showLoanDetails(loan) {
    sessionStorage.setItem('currentLoanForExport', JSON.stringify(loan));

    // Fetch payment data if not already loaded
    if (!loan.payments) {
        try {
            const payments = await getPaymentsForLoan(loan.application_id);
            loan.payments = payments;
        } catch (error) {
            //console.error('Error fetching payments:', error);
            showNotification('Error fetching payment history', 'error');
            loan.payments = [];
        }
    }

    const balanceInfo = await recalculateLoanBalance(loan);

    let detailsHtml = `
                    <button onclick="exportLoanDetails()" class="action-btn"
                        style="float:right;background:#2196F3;margin-bottom:15px;">
                        Export as PDF
                    </button>

                    <div class="balance-highlight">
                        <h3 style="color:#2e7d32; margin-top:0;">Current Loan Financial Status</h3>
                        <table style="width:100%; font-size:0.95rem;">
                            <tr>
                                <td><strong>Original Loan Amount:</strong></td>
                                <td style="text-align:right;">KES ${formatCurrency(loan.loan_amount)}</td>
                            </tr>
                            <tr>
                                <td><strong>Total Paid:</strong></td>
                                <td style="text-align:right; color:#2e7d32;">KES
                                    ${formatCurrency(balanceInfo.totalPaid)}</td>
                            </tr>
                            <tr>
                                <td><strong>Principal Paid:</strong></td>
                                <td style="text-align:right;">KES ${formatCurrency(balanceInfo.principalPaid)}
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Interest Paid:</strong></td>
                                <td style="text-align:right; color:#d84315;">KES
                                    ${formatCurrency(balanceInfo.interestPaid)}</td>
                            </tr>
                            <tr style="border-top: 1px solid #ccc;">
                                <td><strong>Current Principal Balance:</strong></td>
                                <td style="text-align:right;">KES
                                    ${formatCurrency(balanceInfo.currentPrincipal)}</td>
                            </tr>
                            <tr>
                                <td><strong>Accrued Interest (${balanceInfo.monthsSinceLastPayment}
                                        months):</strong></td>
                                <td style="text-align:right; color:#d84315;">KES
                                    ${formatCurrency(balanceInfo.accruedInterest)}</td>
                            </tr>
                            <tr style="border-top:2px solid #2e7d32; font-weight:bold; font-size:1.1rem;">
                                <td><strong>Total Balance Due:</strong></td>
                                <td style="text-align:right; color:#c62828;">KES
                                    ${formatCurrency(balanceInfo.totalBalanceDue)}</td>
                            </tr>
                        </table>
                        <p style="margin:10px 0 0 0; font-size:0.85rem; color:#666;">
                            Last Payment: ${formatDate(balanceInfo.lastPaymentDate)}
                            (${balanceInfo.monthsSinceLastPayment} months ago)
                        </p>
                    </div>
                    <h3 style="color:#4a6b4a;">Loanee Information</h3>
                    <p><strong>Full Name:</strong> ${loan.full_name}</p>
                    <p><strong>ID Number:</strong> ${loan.id_number}</p>
                    <p><strong>PF Number:</strong> ${loan.pf_number}</p>
                    <p><strong>Email:</strong> ${loan.email || 'N/A'}</p>
                    <hr style="margin: 20px 0; border: none; border-top: 1px solid #e0e0e0;">
                    <h3 style="color:#4a6b4a;">Loan Details</h3>
                    <p><strong>Loan Type:</strong> ${loan.loan_type ? loan.loan_type.charAt(0).toUpperCase() +
                        loan.loan_type.slice(1) : 'N/A'}</p>
                    <p><strong>Application Date:</strong> ${loan.application_date ?
                        formatDateTime(loan.application_date) : 'N/A'}</p>
                    <p><strong>Status:</strong> <span style="font-weight:bold; color:#4a6b4a;">${loan.status ?
                            loan.status.toUpperCase() : 'PENDING'}</span></p>
                    <p><strong>Payment Duration:</strong> ${loan.payment_duration || 'N/A'} years</p>
                    <p><strong>Payment Frequency:</strong> Monthly</p>
                    <p><strong>Basic Salary:</strong> KES ${loan.basic_salary ?
                        formatCurrency(loan.basic_salary) : 'N/A'}</p>
                    <p><strong>Employment Type:</strong> ${loan.employment_type || 'N/A'}</p>
                    `;

    if (loan.employment_type === 'contracted') {
        detailsHtml += `
                    <p><strong>Contract Period:</strong> ${loan.contract_period || 'N/A'} months</p>
                    <p><strong>Contract Start:</strong> ${loan.contract_start_date || 'N/A'}</p>
                    <p><strong>Contract End:</strong> ${loan.contract_end_date || 'N/A'}</p>
                    `;
    }
    if (loan.loan_type === 'mortgage') {
        detailsHtml += `
                    <p><strong>Property Location:</strong> ${loan.property_location || 'N/A'}</p>
                    <p><strong>Property Value:</strong> KES ${loan.property_value ?
                        formatCurrency(loan.property_value) : 'N/A'}</p>
                    `;
    }
    if (loan.loan_type === 'car') {
        detailsHtml += `
                    <p><strong>Car Type:</strong> ${loan.car_type || 'N/A'}</p>
                    <p><strong>Car Model:</strong> ${loan.car_model || 'N/A'}</p>
                    <p><strong>Car Value:</strong> KES ${loan.car_value ? formatCurrency(loan.car_value) :
                        'N/A'}</p>
                    `;
    }
    detailsHtml += `
                    <p><strong>Bank:</strong> ${loan.bank_name || 'N/A'} (${loan.bank_branch || 'N/A'})</p>
                    <p><strong>Account Number:</strong> ${loan.bank_account_number || 'N/A'}</p>
                    `;

    if (loan.dispatch_date) {
        detailsHtml +=
            `<p><strong>Dispatch Date:</strong> ${formatDateTime(loan.dispatch_date)}</p>`;
        const dispatchDate = new Date(loan.dispatch_date);
        const gracePeriodEndDate = new Date(dispatchDate);
        gracePeriodEndDate.setMonth(gracePeriodEndDate.getMonth() + 1);
        detailsHtml += `
                    <div class="grace-period-notice">
                        <h4>Grace Period Information</h4>
                        <p><strong>Dispatch Date:</strong> ${formatDate(loan.dispatch_date)}</p>
                        <p><strong>Grace Period End:</strong> ${formatDate(gracePeriodEndDate.toISOString())}
                        </p>
                        <p><strong>First Payment Due:</strong> ${formatDate(new
                            Date(gracePeriodEndDate.getFullYear(), gracePeriodEndDate.getMonth() + 1,
                            gracePeriodEndDate.getDate()))}</p>
                        <p>The loanee has a one-month grace period after funds are dispatched before the first
                            payment is due.</p>
                    </div>
                    `;
    }
    if (loan.payments && loan.payments.length > 0) {
        detailsHtml += `
                    <hr style="margin: 20px 0; border: none; border-top: 1px solid #e0e0e0;">
                    <div
                        style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                        <h3 style="color:#4a6b4a;">Payment History (${loan.payments.length} payments)</h3>
                        <button onclick="forceRefreshPaymentData(${JSON.stringify(loan).replace(/" /g, '&quot;' )})"
                            class="action-btn" style="background:#2196F3;">
                            Refresh Payment Data
                        </button>
                    </div>
                    <div style="overflow-x: auto;">
                        <table style="width:100%; font-size:0.85rem; border-collapse:collapse; margin-top:10px;">
                            <thead>
                                <tr style="background:#3a6b9a; color:white;">
                                    <th style="padding:8px; border:1px solid #ccc;">#</th>
                                    <th style="padding:8px; border:1px solid #ccc;">Date</th>
                                    <th style="padding:8px; border:1px solid #ccc;">Amount</th>
                                    <th style="padding:8px; border:1px solid #ccc;">Interest</th>
                                    <th style="padding:8px; border:1px solid #ccc;">Principal</th>
                                    <th style="padding:8px; border:1px solid #ccc;">Balance</th>
                                    <th style="padding:8px; border:1px solid #ccc;">Source</th>
                                    <th style="padding:8px; border:1px solid #ccc;">Ref ID</th>
                                </tr>
                            </thead>
                            <tbody>
                                `;

        loan.payments.sort((a, b) => new Date(b.payment_date) - new Date(a.payment_date));
        loan.payments.forEach((p, idx) => {
            detailsHtml += `
                                <tr>
                                    <td style="padding:8px; border:1px solid #ccc;">${idx + 1}</td>
                                    <td style="padding:8px; border:1px solid #ccc;">
                                        ${formatDate(p.payment_date)}</td>
                                    <td style="padding:8px; border:1px solid #ccc; font-weight:bold;">KES
                                        ${formatCurrency(p.amount)}</td>
                                    <td style="padding:8px; border:1px solid #ccc; color:#d84315;">KES
                                        ${formatCurrency(p.interest_amount || 0)}</td>
                                    <td style="padding:8px; border:1px solid #ccc; color:#2e7d32;">KES
                                        ${formatCurrency(p.principal_amount || 0)}</td>
                                    <td style="padding:8px; border:1px solid #ccc;">KES
                                        ${formatCurrency(p.balance_after || 0)}</td>
                                    <td style="padding:8px; border:1px solid #ccc; font-size:0.8rem;">
                                        ${p.payment_source}</td>
                                    <td style="padding:8px; border:1px solid #ccc; font-size:0.75rem; color:#666;">
                                        ${p.transaction_reference || '-'}</td>
                                </tr>
                                `;
        });
        detailsHtml += `
                            </tbody>
                        </table>
                    </div>

                    <hr style="margin: 20px 0; border: none; border-top: 1px solid #e0e0e0;">
                    <h3 style="color:#4a6b4a;">Payment History</h3>
                    <p style="text-align:center; padding:20px; color:#666;">No payments recorded yet.</p>
                    `;
    }

    detailsHtml += `
                    <hr style="margin: 20px 0; border: none; border-top: 1px solid #e0e0e0;">
                    <p><strong>Accountant Process Date:</strong> ${loan.accountant_processed_date ?
                        formatDateTime(loan.accountant_processed_date) : 'N/A'}</p>
                    <p><strong>Feedback:</strong> ${loan.feedback || 'None'}</p>
                    `;

    document.getElementById('loanDetailsContent').innerHTML = detailsHtml;
    document.getElementById('detailsModal').style.display = 'flex';
}

function showFeedbackModal(loan, actionType) {
    APP_STATE.currentActionLoan = loan;
    APP_STATE.currentActionType = actionType;
    document.getElementById('feedbackTextarea').value = '';
    if (actionType === 'reject') {
        document.getElementById('feedbackTitle').textContent = 'Reject Loan: Provide Reason';
        document.getElementById('submitFeedback').textContent = 'Reject Loan';
    } else {
        document.getElementById('feedbackTitle').textContent = 'Return Loan: Provide Feedback';
        document.getElementById('submitFeedback').textContent = 'Return Loan';
    }
    document.getElementById('feedbackModal').style.display = 'flex';
}

async function showAmortization(loan) {
    // Fetch payment data if not already loaded
    if (!loan.payments) {
        try {
            const payments = await getPaymentsForLoan(loan.application_id);
            loan.payments = payments;
        } catch (error) {
            //console.error('Error fetching payments:', error);
            showNotification('Error fetching payment history', 'error');
            loan.payments = [];
        }
    }

    const amortTableBody = document.getElementById('amortTableBody');
    amortTableBody.innerHTML = '';
    const balanceInfo = await recalculateLoanBalance(loan);
    const schedule = await updatepayment_scheduleWithActuals(loan);
    const amortInfo = document.getElementById('amortInfo');

    let gracePeriodNotice = '';
    if (loan.dispatch_date) {
        const dispatchDate = new Date(loan.dispatch_date);
        const gracePeriodEndDate = new Date(dispatchDate);
        gracePeriodEndDate.setMonth(gracePeriodEndDate.getMonth() + 1);
        gracePeriodNotice = `
        <div class="grace-period-notice">
            <h4>Grace Period Information</h4>
            <p><strong>Dispatch Date:</strong> ${formatDate(loan.dispatch_date)}</p>
            <p><strong>Grace Period End:</strong> ${formatDate(gracePeriodEndDate.toISOString())}</p>
            <p><strong>First Payment Due:</strong> ${formatDate(new Date(gracePeriodEndDate.getFullYear(), gracePeriodEndDate.getMonth() + 1, gracePeriodEndDate.getDate()))}</p>
            <p>The loanee has a one-month grace period after funds are dispatched before the first payment is due.</p>
        </div>
    `;
    }

    // Update amortization information display
    amortInfo.innerHTML = `
    <div style="display:flex;justify-content:space-between;align-items:center;">
        <h3 style="margin:0; color:#2e7d32;">Payment Schedule Summary</h3>
        <button onclick="exportAmortization()" class="action-btn" style="background:#2196F3;">
            Export Schedule
        </button>
    </div>
    ${gracePeriodNotice}
    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:20px;margin-top:20px;">
        <div>
            <strong>Original Loan:</strong> KES ${formatCurrency(loan.loan_amount)}<br>
            <strong>Current Balance:</strong> <span style="color:#c62828;">KES ${formatCurrency(balanceInfo.totalBalanceDue)}</span>
        </div>
        <div>
            <strong>Total Paid:</strong> KES ${formatCurrency(balanceInfo.totalPaid)}<br>
            <strong>Interest Rate:</strong> <?php echo $interest_rate; ?>% per year
        </div>
        <div>
            <strong>Payment Frequency:</strong> Monthly<br>
            <strong>Months Since Last Payment:</strong> ${balanceInfo.monthsSinceLastPayment}
        </div>
    </div>
`;

    // Handle empty payment schedule
    if (!schedule.payment_schedule || schedule.payment_schedule.length === 0) {
        amortTableBody.innerHTML = `
        <tr>
            <td colspan="9" style="text-align:center; padding:30px;">
                No payment schedule generated yet. Loan must be dispatched first.
            </td>
        </tr>
    `;
        document.getElementById('amortModal').style.display = 'flex';
        return;
    }

    // Populate payment schedule table
    schedule.payment_schedule.forEach((payment) => {
        const statusColors = {
            'paid': '#d4edda',
            'partial': '#fff3cd',
            'pending': '#ffffff',
            'overdue': '#f8d7da'
        };

        const row = `
        <tr style="background:${statusColors[payment.status] || '#fff'};">
            <td>${payment.paymentNumber}</td>
            <td>${formatDate(payment.dueDate)}</td>
            <td>KES ${formatCurrency(payment.expectedAmount)}</td>
            <td>${payment.actualAmount > 0 ? 'KES ' + formatCurrency(payment.actualAmount) : '-'}</td>
            <td style="color:#d84315;">KES ${formatCurrency(payment.expectedInterest)}</td>
            <td style="color:#d84315; font-weight:bold;">
                ${payment.actualInterest > 0 ? 'KES ' + formatCurrency(payment.actualInterest) : '-'}
            </td>
            <td>KES ${formatCurrency(payment.expectedPrincipal)}</td>
            <td>KES ${formatCurrency(payment.expectedBalance)}</td>
            <td>
                <strong>${payment.status.toUpperCase()}</strong>
                ${payment.variance !== 0 && payment.actualAmount > 0 ? 
                    `<br><small style="color:${payment.variance > 0 ? 'green' : 'red'};">
                        ${payment.variance > 0 ? '+' : ''}KES ${Math.round(payment.variance)}
                    </small>` : ''}
            </td>
        </tr>
    `;
        amortTableBody.innerHTML += row;
    });

    document.getElementById('amortModal').style.display = 'flex';
}

// Show payment recording modal
function showRecordPaymentModal(loan) {
    if (!loan || !loan.application_id) {
        showNotification('Error: Invalid loan data', 'error');
        //console.error('Invalid loan object:', loan);
        return;
    }

    APP_STATE.currentPaymentLoan = loan;

    recalculateLoanBalance(loan).then(balanceInfo => {
        document.getElementById('paymentLoaneeInfo').innerHTML = `
        <p style="margin:5px 0;"><strong>Loanee:</strong> ${loan.full_name}</p>
        <p style="margin:5px 0;"><strong>PF Number:</strong> ${loan.pf_number}</p>
        <p style="margin:5px 0;"><strong>Original Loan Amount:</strong> KES ${formatCurrency(loan.loan_amount)}</p>
        <p style="margin:5px 0;"><strong>Current Principal:</strong> KES ${formatCurrency(balanceInfo.currentPrincipal)}</p>
        <p style="margin:5px 0;"><strong>Accrued Interest:</strong> 
            <span style="color:#d84315;">KES ${formatCurrency(balanceInfo.accruedInterest)}</span>
        </p>
        <p style="margin:5px 0;"><strong>Total Balance Due:</strong> 
            <span style="color:#c62828; font-weight:bold; font-size:1.1em;">
                KES ${formatCurrency(balanceInfo.totalBalanceDue)}
            </span>
        </p>
        <p style="margin:5px 0; font-size:0.9em; color:#666;">
            <em>Last payment: ${balanceInfo.monthsSinceLastPayment} months ago</em>
        </p>
    `;
    });

    // Reset form fields
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('paymentDate').value = today;
    document.getElementById('principalAmount').value = '';
    document.getElementById('interestAmount').value = '';
    document.getElementById('totalPaymentAmount').value = '';
    document.getElementById('paymentSource').value = '';
    document.getElementById('transactionReference').value = '';
    document.getElementById('paymentNotes').value = '';
    document.getElementById('paymentCalculationPreview').style.display = 'none';
    document.getElementById('recordPaymentModal').style.display = 'flex';
}


// Add event listeners for payment form inputs
const principalInput = document.getElementById('principalAmount');
const interestInput = document.getElementById('interestAmount');

const principalListener = () => updatePaymentPreview();
const interestListener = () => updatePaymentPreview();

principalInput.addEventListener('input', principalListener);
interestInput.addEventListener('input', interestListener);

paymentModalListeners = [{
        element: principalInput,
        type: 'input',
        listener: principalListener
    },
    {
        element: interestInput,
        type: 'input',
        listener: interestListener
    }
];

// ==========================================
// PAYMENT FUNCTIONS
// ==========================================

// Update payment preview as user enters amounts
function updatePaymentPreview() {
    const principal = parseFloat(document.getElementById('principalAmount').value) || 0;
    const interest = parseFloat(document.getElementById('interestAmount').value) || 0;
    const totalAmount = principal + interest;

    document.getElementById('totalPaymentAmount').value = totalAmount.toFixed(2);

    if (totalAmount > 0 && APP_STATE.currentPaymentLoan) {
        recalculateLoanBalance(APP_STATE.currentPaymentLoan).then(balanceInfo => {
            const newBalance = Math.max(0, balanceInfo.currentPrincipal - principal);

            document.getElementById('previewPrincipal').textContent = 'KES ' + formatCurrency(
                principal);
            document.getElementById('previewInterest').textContent = 'KES ' + formatCurrency(
                interest);
            document.getElementById('previewTotalPayment').textContent = 'KES ' +
                formatCurrency(
                    totalAmount);
            document.getElementById('previewNewBalance').textContent = 'KES ' + formatCurrency(
                newBalance);
            document.getElementById('paymentCalculationPreview').style.display = 'block';
        });
    } else {
        document.getElementById('paymentCalculationPreview').style.display = 'none';
    }
}


// Submit payment record
async function submitPayment() {
    const principal = parseFloat(document.getElementById('principalAmount').value);
    const interest = parseFloat(document.getElementById('interestAmount').value);
    const totalAmount = principal + interest;
    const pDate = document.getElementById('paymentDate').value;
    const source = document.getElementById('paymentSource').value;
    const transactionRef = document.getElementById('transactionReference').value.trim();
    const notes = document.getElementById('paymentNotes').value.trim();

    // Validate form inputs
    if (!principal || principal < 0) {
        showNotification('Please enter a valid principal amount (must be 0 or greater).', 'error');
        return;
    }

    if (interest === undefined || interest === null || interest < 0) {
        showNotification('Please enter a valid interest amount (can be 0).', 'error');
        return;
    }

    if (totalAmount <= 0) {
        showNotification('Total payment must be greater than zero.', 'error');
        return;
    }

    if (!pDate) {
        showNotification('Please select a payment date.', 'error');
        return;
    }

    if (!source) {
        showNotification('Please select a payment source.', 'error');
        return;
    }

    if (!transactionRef) {
        showNotification('Please enter a transaction/reference ID.', 'error');
        return;
    }

    const balanceInfo = await recalculateLoanBalance(APP_STATE.currentPaymentLoan);

    // Confirm if payment significantly exceeds balance
    if (totalAmount > balanceInfo.totalBalanceDue * 1.1) {
        const confirm = window.confirm(
            `Payment amount (KES ${formatCurrency(totalAmount)}) significantly exceeds balance due (KES ${formatCurrency(balanceInfo.totalBalanceDue)}). Do you want to continue?`
        );
        if (!confirm) return;
    }

    const paymentDateObj = new Date(pDate);
    paymentDateObj.setHours(12, 0, 0, 0);

    const payment = {
        application_id: APP_STATE.currentPaymentLoan.application_id,
        payment_date: paymentDateObj.toISOString().split('T')[0],
        amount: totalAmount,
        principal_amount: principal,
        interest_amount: interest,
        balance_after: Math.max(0, balanceInfo.currentPrincipal - principal),
        payment_source: source,
        transaction_reference: transactionRef,
        notes: notes,
        csrf_token: APP_STATE.csrfToken
    };

    try {
        const result = await apiCall('payments.php', 'POST', payment);

        if (result.success) {
            // <i data-lucide="check-circle" class="icon-sm"></i> CRITICAL FIX: Clear cache for this loan
            paymentCache.delete(APP_STATE.currentPaymentLoan.application_id);

            // <i data-lucide="check-circle" class="icon-sm"></i> Calculate NEW balance after payment
            const newBalance = {
                totalPaid: parseFloat(balanceInfo.totalPaid) + totalAmount,
                principalPaid: parseFloat(balanceInfo.principalPaid) + principal,
                interestPaid: parseFloat(balanceInfo.interestPaid) + interest,
                currentPrincipal: Math.max(0, parseFloat(balanceInfo.currentPrincipal) -
                    principal),
                totalBalanceDue: Math.max(0, parseFloat(balanceInfo.totalBalanceDue) -
                    totalAmount).toFixed(
                    2),
                accruedInterest: 0 // Reset after payment
            };

            // <i data-lucide="check-circle" class="icon-sm"></i> UPDATE DOM IMMEDIATELY
            await updateLoanRowBalance(APP_STATE.currentPaymentLoan.application_id, newBalance);

            // <i data-lucide="check-circle" class="icon-sm"></i> Handle Status Update Isolated from Payment Success
            try {
                // Check if loan should be closed
                if (parseFloat(newBalance.totalBalanceDue) <= 0) {
                    await updateLoanStatus(
                        APP_STATE.currentPaymentLoan.application_id,
                        'closed',
                        'Loan fully paid off.'
                    );
                    showNotification(`Payment recorded! Loan fully paid and closed.`, 'success');
                } else {
                    showNotification('Payment recorded successfully!', 'success');
                }
            } catch (statusError) {
                // If status update fails, log it but don't fail the whole payment
                console.error('Status update failed:', statusError);
                showNotification('Payment recorded, but status update failed. Please refresh the page.',
                    'warning');
            }

            // Close modal
            document.getElementById('recordPaymentModal').style.display = 'none';

            // <i data-lucide="check-circle" class="icon-sm"></i> Refresh table to ensure data consistency regardless of status update success
            await renderLoans(document.querySelector('#sidebar button.active')?.dataset.filter ||
                'all');

        } else {
            showNotification('Failed to record payment: ' + result.message, 'error');
        }
    } catch (error) {
        // This block now only catches Payment API errors, not Status Update errors
        showNotification('Failed to record payment: ' + error.message, 'error');
    }
}

/**
 * Update loan balance in the table row immediately (FIXED VERSION)
 * @param {string} applicationId - The loan's application ID
 * @param {object} newBalance - Object with balance information
 */
async function updateLoanRowBalance(applicationId, newBalance) {
    try {
        // <i data-lucide="check-circle" class="icon-sm"></i> FIXED: Find row by data-application-id attribute
        const row = document.querySelector(
            `#loansTable tbody tr[data-application-id="${applicationId}"]`);

        if (!row) {
            //console.warn(`Row not found for application ID: ${applicationId}`);
            return;
        }

        // <i data-lucide="check-circle" class="icon-sm"></i> Find the balance cell (Column 7 - "Current Balance")
        // Index 6 because counting starts from 0
        const balanceCell = row.cells[6];

        if (balanceCell) {
            const totalDue = parseFloat(newBalance.totalBalanceDue) || 0;
            const accruedInterest = parseFloat(newBalance.accruedInterest) || 0;

            // Update the cell content
            const principal = loan.current_principal_balance || (loan.loan_amount - (loan
                .total_principal_paid || 0));
            const interest = loan.accrued_interest || 0; // fallback
            balanceCell.innerHTML = `
${formatCurrency(principal)}
${interest > 0 ? `<span style="color: #dc3545;">+${formatCurrency(interest)} interest</span>` : ''}
`;

            if (accruedInterest > 0) {
                balanceCell.innerHTML +=
                    `<br><small style="color:#d84315;">+KES ${formatCurrency(accruedInterest)} interest</small>`;
            }

            // Add visual feedback (green flash)
            balanceCell.style.transition = 'background-color 0.5s ease';
            balanceCell.style.backgroundColor = '#c8e6c9'; // Light green

            setTimeout(() => {
                balanceCell.style.backgroundColor = '';
            }, 1000);

            //console.log(`<i data-lucide="check-circle" class="icon-sm"></i> Balance updated for ${applicationId}: KES ${formatCurrency(totalDue)}`);
        } else {
            //console.warn(`Balance cell not found for ${applicationId}`);
        }
    } catch (error) {
        //console.error('Error updating loan row balance:', error);
    }
}


// Refresh payment data for a loan
async function refreshPaymentData(loan) {
    try {
        const result = await apiCall(`payments.php?application_id=${loan.application_id}`);
        const payments = result.data || [];
        loan.payments = payments;
        const balanceInfo = await recalculateLoanBalance(loan);

        if (document.getElementById('detailsModal').style.display === 'block') {
            await updateLoanSummaryDisplay(loan);
        }

        if (DOM_ELEMENTS.loansTable.style.display !== 'none') {
            await renderLoans(document.querySelector('#sidebar button.active').dataset.filter);
        }

        return {
            payments,
            balanceInfo
        };
    } catch (error) {
        //console.error('Error refreshing payment data: ', error);
        showNotification('Error refreshing payment data', 'error');
        return null;
    }
}

// Force refresh payment data for a loan
async function forceRefreshPaymentData(loan) {
    try {
        if (loan.payments) {
            delete loan.payments;
        }

        const result = await apiCall(`payments.php?application_id=${loan.application_id}`);
        const payments = result.data || [];
        loan.payments = payments;

        // Update display if details modal is open
        if (document.getElementById('detailsModal').style.display === 'block') {
            await showLoanDetails(loan);
        }

        // Refresh loans table if visible
        if (DOM_ELEMENTS.loansTable.style.display !== 'none') {
            await renderLoans(document.querySelector('#sidebar button.active').dataset.filter);
        }

        return {
            payments,
            balanceInfo: await recalculateLoanBalance(loan)
        };
    } catch (error) {
        //console.error('Error refreshing payment data:', error);
        showNotification('Error refreshing payment data', 'error');
        return null;
    }
}


/**
 * Performs a surgical DOM update on a specific loan row in the table.
 * Bypasses heavy batch fetch timeout in 'All Loans' view.
 * @param {string} applicationId - The ID of the loan to update.
 * @param {object} newData - Object containing new balance info (totalPaid, principalPaid, etc.).
 */
async function updateLoanRowDOM(applicationId, newData) {
    try {
        // 1. Find the specific row using data-application-id attribute (FIXED)
        const targetRow = document.querySelector(
            `#loansTable tbody tr[data-application-id="${applicationId}"]`);

        // 2. If row is found, update the specific "Current Balance" cell (Column 8 / Index 7)
        if (targetRow) {
            const balanceCell = targetRow.cells[7]; // 8th column, index 7

            if (balanceCell) {
                // Calculate new balance for display
                const newBalance = parseFloat(newData.totalBalanceDue) || 0;
                const newInterest = parseFloat(newData.accruedInterest) || 0;

                // Update cell HTML
                const principal = loan.current_principal_balance || (loan.loan_amount - (loan
                    .total_principal_paid || 0));
                const interest = loan.accrued_interest || 0; // fallback
                balanceCell.innerHTML = `
${formatCurrency(principal)}
${interest > 0 ? `<span style="color: #dc3545;">+${formatCurrency(interest)} interest</span>` : ''}
`;
                // Add a visual "flash" to indicate it updated
                targetRow.classList.add('balance-updated');
                setTimeout(() => {
                    targetRow.classList.remove('balance-updated');
                }, 1000); // Remove flash class after 1 second
            }
        }
    } catch (error) {
        //console.error('Error updating loan row:', error);
        // Don't show notification here to avoid cluttering UI after a successful action
    }
}


// ==========================================
// LOAN ACTIONS
// ==========================================

// Update loan status in the database
async function updateLoanStatus(applicationId, status, feedback = '') {
    try {
        await apiCall('loans.php', 'PUT', {
            application_id: applicationId,
            status: status,
            feedback: feedback,
            csrf_token: APP_STATE.csrfToken
        });
    } catch (error) {
        //console.error('Error updating loan status:', error);
        throw error;
    }
}

// Handle loan actions (forward, reject, close)
async function handleLoanAction(loan, action) {
    try {
        if (action === 'forward') {
            if (!hasPermission('canForwardLoans')) {
                showNotification('You do not have permission to forward loans.', 'error');
                return;
            }

            await updateLoanStatus(loan.application_id, 'forwarded',
                'Forwarded to Accountant for approval.');
            showNotification(`Loan for ${loan.full_name} forwarded to Accountant.`, 'success');
        } else if (action === 'reject') {
            if (!hasPermission('canRejectLoans')) {
                showNotification('You do not have permission to reject loans.', 'error');
                return;
            }

            await updateLoanStatus(loan.application_id, 'rejected', 'Rejected by Cashier.');
            showNotification(`Loan for ${loan.full_name} rejected.`, 'success');
        } else if (action === 'close') {
            if (!hasPermission('canCloseLoans')) {
                showNotification('You do not have permission to close loans.', 'error');
                return;
            }

            const confirmation = confirm(
                `Are you sure you want to CLOSE the case for ${loan.full_name}? This action is final.`
            );

            if (confirmation) {
                await updateLoanStatus(
                    loan.application_id,
                    'closed',
                    loan.feedback || 'Case closed by Cashier.'
                );
                showNotification(`Loan case for ${loan.full_name} closed.`, 'success');
            } else {
                return;
            }
        }

        renderLoans(document.querySelector('#sidebar button.active').dataset.filter);
    } catch (error) {
        showNotification('Failed to update loan: ' + error.message, 'error');
    }
}

/**
 * Force refresh the "Current Balance" cell for a specific loan immediately.
 * Bypasses caching and batch loading to show live data.
 * @param {string} applicationId - The ID of the loan to refresh.
 */
async function refreshLoanRowBalance(applicationId) {
    try {
        // 1. Fetch FRESH payments for this specific loan (bypass cache)
        const response = await apiCall(`payments.php?application_id=${applicationId}`);
        const freshPayments = response.data || [];

        // 2. Fetch FRESH loan details to ensure we have the base object
        // Note: This assumes an endpoint like loans.php?loan_id=X exists or you have access to the current loan list.
        const loanResponse = await apiCall(`loans.php?loan_id=${applicationId}`);
        const loan = loanResponse.data;

        if (!loan) {
            //console.warn(`Loan with ID ${applicationId} not found.`);
            return;
        }

        // 3. Update the loan's payment data with fresh info
        loan.payments = freshPayments;

        // 4. Recalculate the new balance
        const balanceInfo = await recalculateLoanBalance(loan);

        // 5. Update the DOM
        await updateLoanRowDOM(applicationId, {
            totalPaid: parseFloat(balanceInfo.totalPaid),
            principalPaid: parseFloat(balanceInfo.principalPaid),
            interestPaid: parseFloat(balanceInfo.interestPaid),
            currentPrincipal: Math.max(0, balanceInfo.currentPrincipal),
            totalBalanceDue: balanceInfo.totalBalanceDue,
            accruedInterest: balanceInfo.accruedInterest
        });
    } catch (error) {
        //console.error('Error refreshing loan row balance:', error);
    }
}

// Submit feedback for loan action
async function submitFeedback() {
    const feedback = document.getElementById('feedbackTextarea').value.trim();

    if (!feedback) {
        showNotification('Feedback/Reason is required.', 'error');
        return;
    }

    try {
        if (APP_STATE.currentActionType === 'reject') {
            await updateLoanStatus(
                APP_STATE.currentActionLoan.application_id,
                'rejected',
                feedback
            );
            showNotification(
                `Loan for ${APP_STATE.currentActionLoan.full_name} permanently rejected.`,
                'success'
            );
        } else if (APP_STATE.currentActionType === 'return') {
            await updateLoanStatus(
                APP_STATE.currentActionLoan.application_id,
                'returned',
                feedback
            );
            showNotification(
                `Loan for ${APP_STATE.currentActionLoan.full_name} returned to loanee for correction.`,
                'success'
            );
        }

        document.getElementById('feedbackModal').style.display = 'none';
        renderLoans(document.querySelector('#sidebar button.active').dataset.filter);
    } catch (error) {
        showNotification('Failed to update loan: ' + error.message, 'error');
    }
}



// Update payment schedule with actual payment data
async function updatepayment_scheduleWithActuals(loan) {
    const scheduleData = await generatepayment_schedule(loan);
    loan.payment_schedule = scheduleData.schedule;
    loan.grace_period_end_date = scheduleData.gracePeriodEndDate;
    loan.payment_start_date = scheduleData.startDate;

    const payments = loan.payments || [];

    payments.forEach(payment => {
        const paymentDate = new Date(payment.payment_date || payment.date);
        const paymentMonth = paymentDate.getMonth();
        const paymentYear = paymentDate.getFullYear();

        const scheduleIndex = loan.payment_schedule.findIndex(scheduled => {
            const scheduledDate = new Date(scheduled.dueDate);
            return scheduledDate.getMonth() === paymentMonth &&
                scheduledDate.getFullYear() === paymentYear;
        });

        if (scheduleIndex >= 0) {
            const scheduled = loan.payment_schedule[scheduleIndex];
            scheduled.actualAmount = parseFloat(payment.amount) || 0;
            scheduled.actualInterest = parseFloat(payment.interest_amount) || 0;
            scheduled.actualDate = payment.payment_date || payment.date;
            scheduled.variance = scheduled.actualAmount - scheduled.expectedAmount;
            scheduled.status = scheduled.actualAmount >= scheduled.expectedAmount ? 'paid' :
                'partial';
        }
    });

    return loan;
}

// ==========================================
// EXPORT FUNCTIONS
// ==========================================

/**
 * Helper function to convert data to CSV format (Excel compatible)
 * @param {Array} data - Array of data rows
 * @param {Array} headers - Array of column headers
 * @returns {string} CSV formatted string
 */
function convertToCSV(data, headers) {
    let csv = headers.join(',') + '\n';

    data.forEach(row => {
        const values = row.map((value, index) => {
            const stringValue = String(value);

            // For Application ID and Account Number columns, add tab character to preserve formatting
            if (index === 0 || (headers[index] && (
                    headers[index].includes('Application ID') ||
                    headers[index].includes('Account Number')
                ))) {
                // Use tab character prefix to force Excel to treat as text
                return `"${stringValue}\t"`;
            }

            // Escape quotes and wrap in quotes if contains comma, quotes, or newlines
            if (stringValue.includes(',') ||
                stringValue.includes('"') ||
                stringValue.includes('\n')) {
                return `"${stringValue.replace(/"/g, '""')}"`;
            }

            return stringValue;
        });

        csv += values.join(',') + '\n';
    });

    return csv;
}

/**
 * Helper function to download CSV file
 * @param {string} filename - Name of the file to download
 * @param {string} csvContent - CSV content to download
 */
function downloadCSV(filename, csvContent) {
    const BOM = '\uFEFF'; // UTF-8 BOM for Excel compatibility
    const blob = new Blob([BOM + csvContent], {
        type: 'text/csv;charset=utf-8;'
    });

    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = filename;
    link.click();

    showNotification(
        `${filename} exported successfully! Note: In Excel, adjust column widths to view all data properly.`,
        'success'
    );
}

/**
 * Export overall loan summary to CSV
 */
async function exportOverallSummary() {
    if (!hasPermission('canExportData')) {
        showNotification('You do not have permission to export data.', 'error');
        return;
    }

    try {
        const loans = await getAllLoans();
        const activeLoans = loans.filter(l => l.status === 'active' || l.status === 'dispatched');
        const closedLoans = loans.filter(l => l.status === 'closed');

        // Fetch payment data for all loans
        const paymentPromises = [...activeLoans, ...closedLoans].map(async (loan) => {
            try {
                const payments = await getPaymentsForLoan(loan.application_id);
                loan.payments = payments;
                return loan;
            } catch (error) {
                loan.payments = [];
                return loan;
            }
        });

        await Promise.all(paymentPromises);

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
                .slice(
                    1) : 'N/A',
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

/**
 * Export mortgage loans to CSV
 */
async function exportMortgageLoans() {
    if (!hasPermission('canExportData')) {
        showNotification('You do not have permission to export data.', 'error');
        return;
    }

    try {
        const loans = await getAllLoans();
        const mortgageLoans = loans.filter(l => l.loan_type === 'mortgage');

        // Fetch payment data for mortgage loans
        const paymentPromises = mortgageLoans.map(async (loan) => {
            try {
                const payments = await getPaymentsForLoan(loan.application_id);
                loan.payments = payments;
                return loan;
            } catch (error) {
                loan.payments = [];
                return loan;
            }
        });

        await Promise.all(paymentPromises);

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

/**
 * Export car loans to CSV
 */
async function exportCarLoans() {
    if (!hasPermission('canExportData')) {
        showNotification('You do not have permission to export data.', 'error');
        return;
    }

    try {
        const loans = await getAllLoans();
        const carLoans = loans.filter(l => l.loan_type === 'car');

        // Fetch payment data for car loans
        const paymentPromises = carLoans.map(async (loan) => {
            try {
                const payments = await getPaymentsForLoan(loan.application_id);
                loan.payments = payments;
                return loan;
            } catch (error) {
                loan.payments = [];
                return loan;
            }
        });

        await Promise.all(paymentPromises);

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

            // Determine car info - prioritize whichever field has data
            let carInfo = 'Not Specified';
            if (loan.car_model && loan.car_model !== 'N/A') {
                carInfo = loan.car_model;
            } else if (loan.car_type && loan.car_type !== 'N/A') {
                carInfo = loan.car_type;
            }

            // Dispatch date handling
            let dispatchDate = 'Pending Dispatch';
            if (loan.dispatch_date && loan.dispatch_date !== null) {
                dispatchDate = formatDate(loan.dispatch_date);
            }

            data.push([
                formatApplicationId(loan.application_id),
                loan.full_name || 'N/A',
                loan.pf_number || 'N/A',
                loan.id_number || 'N/A',
                formatCurrency(loan.loan_amount || 0),
                carInfo,
                loan.car_value ? formatCurrency(loan.car_value) : 'Not Specified',
                formatCurrency(balanceInfo.totalPaid),
                formatCurrency(balanceInfo.principalPaid),
                formatCurrency(balanceInfo.interestPaid),
                loan.status !== 'closed' ? formatCurrency(balanceInfo.totalBalanceDue) :
                '0',
                loan.status ? loan.status.toUpperCase() : 'UNKNOWN',
                loan.payment_duration || 'N/A',
                formatDate(loan.application_date) || 'N/A',
                dispatchDate,
                loan.bank_name || 'Not Specified',
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

/**
 * Export payment summary to CSV
 */
async function exportPaymentSummary() {
    if (!hasPermission('canExportData')) {
        showNotification('You do not have permission to export data.', 'error');
        return;
    }

    try {
        const loans = await getAllLoans();
        const activeLoans = loans.filter(l => l.status === 'active' || l.status === 'dispatched');
        const closedLoans = loans.filter(l => l.status === 'closed');

        // Fetch payment data for all loans
        const paymentPromises = [...activeLoans, ...closedLoans].map(async (loan) => {
            try {
                const payments = await getPaymentsForLoan(loan.application_id);
                loan.payments = payments;
                return loan;
            } catch (error) {
                loan.payments = [];
                return loan;
            }
        });

        await Promise.all(paymentPromises);

        const headers = [
            'Application ID',
            'Loanee Name',
            'PF Number',
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

            const paymentStatus = loan.status === 'closed' ?
                'FULLY PAID' :
                balanceInfo.totalPaid > 0 ? 'IN PROGRESS' : 'NO PAYMENTS';

            data.push([
                loan.application_id,
                loan.full_name,
                loan.pf_number,
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

// Enhanced reconciliation export with Excel formatting
// ==========================================
// RECONCILIATION EXPORT FUNCTIONS
// ==========================================

/**
 * Enhanced reconciliation export with Excel formatting
 * Exports payment reconciliation data in either Excel or CSV format
 */
async function exportReconciliationReport() {
    if (!hasPermission('canExportData')) {
        showNotification('You do not have permission to export data.', 'error');
        return;
    }

    try {
        // Get all active and dispatched loans
        const loans = (await getAllLoans())
            .filter(l => l.status === 'active' || l.status === 'dispatched');

        // Fetch payment data for all loans
        const paymentPromises = loans.map(async (loan) => {
            try {
                const payments = await getPaymentsForLoan(loan.application_id);
                loan.payments = payments;
                return loan;
            } catch (error) {
                loan.payments = [];
                return loan;
            }
        });

        await Promise.all(paymentPromises);

        // Calculate reconciliation data
        const reconciliationData = [];
        let totalExpectedPayments = 0;
        let totalActualPayments = 0;
        let totalVariance = 0;
        let totalOverdueAmount = 0;
        let totalPrincipalVariance = 0;
        let totalInterestVariance = 0;

        for (const loan of loans) {
            const balanceInfo = await recalculateLoanBalance(loan);
            await updatepayment_scheduleWithActuals(loan);

            const schedule = loan.payment_schedule || [];
            const overduePayments = schedule.filter(p => p.status === 'overdue');
            const paidPayments = schedule.filter(p => p.status === 'paid' || p.status ===
                'partial');

            // Calculate expected vs actual
            let expectedToDate = 0;
            let actualToDate = balanceInfo.totalPaid;
            let principalVariance = 0;
            let interestVariance = 0;
            let overdueAmount = 0;

            paidPayments.forEach(p => {
                expectedToDate += p.expectedAmount;
                principalVariance += (p.actualAmount || 0) - p.expectedAmount;
                interestVariance += (p.actualInterest || 0) - p.expectedInterest;
            });

            overduePayments.forEach(p => {
                overdueAmount += p.expectedAmount;
                expectedToDate += p.expectedAmount;
            });

            const variance = actualToDate - expectedToDate;

            totalExpectedPayments += expectedToDate;
            totalActualPayments += actualToDate;
            totalVariance += variance;
            totalOverdueAmount += overdueAmount;
            totalPrincipalVariance += principalVariance;
            totalInterestVariance += interestVariance;

            const riskLevel = overduePayments.length > 3 ? 'High' :
                overduePayments.length > 1 ? 'Medium' : 'Low';

            reconciliationData.push({
                loanee: loan.full_name,
                pfNumber: loan.pf_number,
                loanType: loan.loan_type ?
                    loan.loan_type.charAt(0).toUpperCase() + loan.loan_type.slice(1) : 'N/A',
                originalAmount: loan.loan_amount,
                expectedPaid: expectedToDate,
                actualPaid: actualToDate,
                variance: variance,
                principalVariance: principalVariance,
                interestVariance: interestVariance,
                currentBalance: balanceInfo.totalBalanceDue,
                overduePayments: overduePayments.length,
                overdueAmount: overdueAmount,
                lastPaymentDate: balanceInfo.lastPaymentDate,
                monthsSinceLastPayment: balanceInfo.monthsSinceLastPayment,
                riskLevel: riskLevel
            });
        }

        // Check if XLSX library is available
        const xlsxAvailable = typeof XLSX !== 'undefined';

        // Check export format preference - default to CSV if XLSX not available
        const excelBtn = document.getElementById('exportExcelBtn');
        const isExcelSelected = xlsxAvailable && excelBtn && excelBtn.classList.contains('active');

        if (isExcelSelected && xlsxAvailable) {
            // Use SheetJS for Excel export
            const wb = XLSX.utils.book_new();

            // Summary sheet
            const summaryData = [
                ['Summary Statistics'],
                ['Total Active Loans', loans.length],
                ['Total Expected Payments', `KES ${formatCurrency(totalExpectedPayments)}`],
                ['Total Actual Payments', `KES ${formatCurrency(totalActualPayments)}`],
                ['Total Variance',
                    `${totalVariance >= 0 ? '+' : ''}KES ${formatCurrency(Math.abs(totalVariance))}`
                ],
                ['Total Principal Variance',
                    `${totalPrincipalVariance >= 0 ? '+' : ''}KES ${formatCurrency(Math.abs(totalPrincipalVariance))}`
                ],
                ['Total Interest Variance',
                    `${totalInterestVariance >= 0 ? '+' : ''}KES ${formatCurrency(Math.abs(totalInterestVariance))}`
                ],
                ['Total Overdue Amount', `KES ${formatCurrency(totalOverdueAmount)}`],
                ['Collection Rate',
                    `${totalExpectedPayments > 0 ? ((totalActualPayments / totalExpectedPayments) * 100).toFixed(1) : 0}%`
                ]
            ];

            const wsSummary = XLSX.utils.aoa_to_sheet(summaryData);
            XLSX.utils.book_append_sheet(wb, wsSummary, "Summary");

            // Detailed data sheet
            const headers = [
                'Loanee', 'PF Number', 'Loan Type', 'Original Amount (KES)',
                'Expected Paid (KES)', 'Actual Paid (KES)', 'Variance (KES)',
                'Principal Variance (KES)', 'Interest Variance (KES)',
                'Current Balance (KES)', 'Overdue Payments', 'Overdue Amount (KES)',
                'Last Payment Date', 'Months Since Last Payment', 'Risk Level'
            ];

            const detailData = [headers];
            reconciliationData.forEach(data => {
                detailData.push([
                    data.loanee,
                    data.pfNumber,
                    data.loanType,
                    formatCurrency(data.originalAmount),
                    formatCurrency(data.expectedPaid),
                    formatCurrency(data.actualPaid),
                    `${data.variance >= 0 ? '+' : ''}${formatCurrency(Math.abs(data.variance))}`,
                    `${data.principalVariance >= 0 ? '+' : ''}${formatCurrency(Math.abs(data.principalVariance))}`,
                    `${data.interestVariance >= 0 ? '+' : ''}${formatCurrency(Math.abs(data.interestVariance))}`,
                    formatCurrency(data.currentBalance),
                    data.overduePayments,
                    formatCurrency(data.overdueAmount),
                    data.lastPaymentDate,
                    data.monthsSinceLastPayment,
                    data.riskLevel
                ]);
            });

            const wsDetails = XLSX.utils.aoa_to_sheet(detailData);
            XLSX.utils.book_append_sheet(wb, wsDetails, "Details");

            // Set column widths
            const wscols = [{
                    wch: 20
                }, {
                    wch: 15
                }, {
                    wch: 15
                }, {
                    wch: 18
                },
                {
                    wch: 18
                }, {
                    wch: 18
                }, {
                    wch: 18
                }, {
                    wch: 18
                },
                {
                    wch: 18
                }, {
                    wch: 15
                }, {
                    wch: 18
                }, {
                    wch: 15
                },
                {
                    wch: 15
                }, {
                    wch: 12
                }
            ];
            wsDetails['!cols'] = wscols;

            // Save file
            XLSX.writeFile(wb,
                `reconciliation_report_${new Date().toISOString().split('T')[0]}.xlsx`);
            showNotification('Excel report exported successfully!', 'success');
        } else {
            // Original CSV export
            let csv = 'Payment Reconciliation Report\n';
            csv += `Generated on: ${new Date().toLocaleString()}\n\n`;

            // Summary section
            csv += 'Summary Statistics\n';
            csv += `Total Active Loans,${loans.length}\n`;
            csv += `Total Expected Payments,KES ${formatCurrency(totalExpectedPayments)}\n`;
            csv += `Total Actual Payments,KES ${formatCurrency(totalActualPayments)}\n`;
            csv +=
                `Total Variance,${totalVariance >= 0 ? '+' : ''}KES ${formatCurrency(Math.abs(totalVariance))}\n`;
            csv +=
                `Total Principal Variance,${totalPrincipalVariance >= 0 ? '+' : ''}KES ${formatCurrency(Math.abs(totalPrincipalVariance))}\n`;
            csv +=
                `Total Interest Variance,${totalInterestVariance >= 0 ? '+' : ''}KES ${formatCurrency(Math.abs(totalInterestVariance))}\n`;
            csv += `Total Overdue Amount,KES ${formatCurrency(totalOverdueAmount)}\n`;
            csv +=
                `Collection Rate,${totalExpectedPayments > 0 ? ((totalActualPayments / totalExpectedPayments) * 100).toFixed(1) : 0}%\n\n`;

            // Detailed data section
            csv += 'Detailed Reconciliation Data\n';
            csv +=
                'Loanee,PF Number,Loan Type,Original Amount (KES),Expected Paid (KES),Actual Paid (KES),Variance(KES),Principal Variance(KES),Interest Variance(KES),Current Balance(KES),Overdue Payments,Overdue Amount(KES),Last Payment Date,Months Since Last Payment,Risk Level\n';

            reconciliationData.forEach(data => {
                csv +=
                    `${data.loanee},${data.pfNumber},${data.loanType},${formatCurrency(data.originalAmount)},${formatCurrency(data.expectedPaid)},${formatCurrency(data.actualPaid)},${data.variance >= 0 ? '+' : ''}${formatCurrency(Math.abs(data.variance))},${data.principalVariance >= 0 ? '+' : ''}${formatCurrency(Math.abs(data.principalVariance))},${data.interestVariance >= 0 ? '+' : ''}${formatCurrency(Math.abs(data.interestVariance))},${formatCurrency(data.currentBalance)},${data.overduePayments},${formatCurrency(data.overdueAmount)},${data.lastPaymentDate},${data.monthsSinceLastPayment},${data.riskLevel}\n`;
            });

            // Download CSV
            const blob = new Blob([csv], {
                type: 'text/csv'
            });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = `reconciliation_report_${new Date().toISOString().split('T')[0]}.csv`;
            link.click();
            showNotification('CSV report exported successfully!', 'success');
        }
    } catch (error) {
        //console.error('Error exporting reconciliation report:', error);
        showNotification('Error exporting reconciliation report: ' + error.message, 'error');
    }
}

/**
 * Initialize export buttons
 * Sets CSV as the default active export format
 */
function initializeExportButtons() {
    // Set CSV as default active
    document.getElementById('exportCSVBtn').classList.add('active');
    document.getElementById('exportExcelBtn').classList.remove('active');
}

/**
 * Export format selector functions
 * Updates the active export format button
 * @param {string} format - The export format ('csv' or 'excel')
 */
function setExportFormat(format) {
    // Update button states
    if (format === 'excel') {
        // Check if XLSX library is available
        if (typeof XLSX === 'undefined') {
            showNotification('Excel export library not loaded. Using CSV format instead.', 'warning');
            document.getElementById('exportCSVBtn').classList.add('active');
            document.getElementById('exportExcelBtn').classList.remove('active');
            return;
        }

        document.getElementById('exportExcelBtn').classList.add('active');
        document.getElementById('exportCSVBtn').classList.remove('active');
    } else {
        document.getElementById('exportCSVBtn').classList.add('active');
        document.getElementById('exportExcelBtn').classList.remove('active');
    }
}

// ==========================================
// ADDITIONAL EXPORT FUNCTIONS
// ==========================================

/**
 * Export all loans with current filter applied
 * This function should be added after the existing export functions
 */
async function exportAllLoans() {
    if (!hasPermission('canExportData')) {
        showNotification('You do not have permission to export data.', 'error');
        return;
    }

    try {
        // Get current filter
        const currentFilter = document.querySelector('#sidebar button.active').dataset.filter;
        let loans = await getAllLoans();

        // Apply the same filter as the current view
        switch (currentFilter) {
            case 'pending':
                loans = loans.filter(l => l.status === 'pending');
                break;
            case 'forwarded':
                loans = loans.filter(l => l.status === 'forwarded');
                break;
            case 'accepted':
                loans = loans.filter(l => l.status === 'accepted');
                break;
            case 'dispatched':
                loans = loans.filter(l => l.status === 'dispatched' || l.status === 'active');
                break;
            case 'active':
                loans = loans.filter(l => l.status === 'active');
                break;
            case 'rejected':
                loans = loans.filter(l => l.status === 'rejected');
                break;
            case 'returned':
                loans = loans.filter(l => l.status === 'returned');
                break;
            case 'closed':
                loans = loans.filter(l => l.status === 'closed');
                break;
        }

        // Fetch payment data for all loans
        const paymentPromises = loans.map(async (loan) => {
            try {
                const payments = await getPaymentsForLoan(loan.application_id);
                loan.payments = payments;
                return loan;
            } catch (error) {
                loan.payments = [];
                return loan;
            }
        });

        await Promise.all(paymentPromises);

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

        for (const loan of loans) {
            const balanceInfo = await recalculateLoanBalance(loan);
            data.push([
                formatApplicationId(loan.application_id),
                loan.full_name,
                loan.pf_number,
                loan.id_number,
                loan.loan_type ? loan.loan_type.charAt(0).toUpperCase() + loan.loan_type
                .slice(
                    1) : 'N/A',
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
        const filename = `Loans_${currentFilter}_${new Date().toISOString().split('T')[0]}.csv`;
        downloadCSV(filename, csv);
    } catch (error) {
        showNotification('Error exporting loans: ' + error.message, 'error');
    }
}

// ==========================================
// DATE RANGE FILTER FUNCTIONS
// ==========================================

/**
 * Apply date range filter to reconciliation view
 * Stores date range in app state and refreshes the view
 */
function applyDateRangeFilter() {
    const fromDate = document.getElementById('reconFromDate').value;
    const toDate = document.getElementById('reconToDate').value;

    // Store date range in app state
    APP_STATE.reconDateRange.from = fromDate;
    APP_STATE.reconDateRange.to = toDate;

    // Refresh reconciliation view with date filter
    showReconciliationView(null);
    showNotification('Date range filter applied', 'success');
}

/**
 * Reset date range filter
 * Clears date inputs and app state, then refreshes the view
 */
function resetDateRangeFilter() {
    // Clear date inputs
    document.getElementById('reconFromDate').value = '';
    document.getElementById('reconToDate').value = '';

    // Clear date range in app state
    APP_STATE.reconDateRange.from = null;
    APP_STATE.reconDateRange.to = null;

    // Refresh reconciliation view without date filter
    showReconciliationView(null);
    showNotification('Date range filter reset', 'success');
}


// ==========================================
// AUTHENTICATION
// ==========================================

/**
 * Logs out the current cashier user
 * Clears session storage and app state, then redirects to login page
 */
function logoutCashier() {
    // Clear session storage
    sessionStorage.removeItem('loginTime');

    // Show notification
    showNotification('Logging out...', 'success', 2000);

    // Call logout API (but don't wait for response in case of network issues)
    apiCall('auth.php', 'POST', {
        action: 'logout',
        csrf_token: APP_STATE.csrfToken
    }).catch(error => {
        //console.error('Logout error:', error);
    }).finally(() => {
        // Clear app state
        APP_STATE.currentUser = null;
        APP_STATE.csrfToken = null;

        // Redirect after a brief delay
        setTimeout(() => {
            window.location.href = '/loan-system/index.php?logout=success';
        }, 500);
    });
}

// ==========================================
// SIDEBAR TOGGLE FUNCTION (UPDATED)
// ==========================================

/**
 * Toggles sidebar between open, closed, and collapsed states
 * Updated to match Accountant Portal logic
 */
function toggleSidebar() {
    const main = document.querySelector('main');
    const sidebar = document.getElementById('sidebar');

    if (!main || !sidebar) return;

    if (window.innerWidth <= 768) {
        // MOBILE BEHAVIOR: Toggle Visibility (Off-canvas)
        if (sidebar.classList.contains('open')) {
            sidebar.classList.remove('open');
            main.classList.remove('sidebar-open');
        } else {
            sidebar.classList.add('open');
            main.classList.add('sidebar-open');
        }
    } else {
        // DESKTOP BEHAVIOR: Toggle Collapse/Expand

        if (sidebar.classList.contains('collapsed')) {
            // It is currently collapsed, so expand it
            sidebar.classList.remove('collapsed');
            main.classList.remove('sidebar-collapsed');
            main.classList.add('sidebar-open');

            // Re-show text and adjust widths
            const logoText = sidebar.querySelector('.sidebar-logo-text');
            if (logoText) logoText.style.display = 'block';

            const buttonTexts = sidebar.querySelectorAll('.button-text');
            buttonTexts.forEach(el => el.style.display = 'block');
        } else {
            // It is currently expanded, so collapse it
            sidebar.classList.add('collapsed');
            main.classList.add('sidebar-collapsed');

            // Hide text and adjust widths
            const logoText = sidebar.querySelector('.sidebar-logo-text');
            if (logoText) logoText.style.display = 'none';

            const buttonTexts = sidebar.querySelectorAll('.button-text');
            buttonTexts.forEach(el => el.style.display = 'none');
        }
    }
}

// Updated Resize Event Listener to fix spacing issues
window.addEventListener('resize', () => {
    // FIXED: Use 'main' from DOM_ELEMENTS
    const main = DOM_ELEMENTS.main;
    const sidebar = document.getElementById('sidebar');

    if (!main || !sidebar) return;

    if (window.innerWidth > 768) {
        // Ensure sidebar is visible on desktop
        sidebar.classList.remove('open');
        sidebar.style.transform = 'none'; // Remove translateX from mobile logic

        if (sidebar.classList.contains('collapsed')) {
            main.classList.add('sidebar-collapsed');
            main.classList.remove('sidebar-open');
        } else {
            main.classList.add('sidebar-open');
            main.classList.remove('sidebar-collapsed');
        }
    } else {
        // MOBILE VIEW
        // Reset padding
        main.classList.remove('sidebar-open', 'sidebar-collapsed');

        // Ensure sidebar is off-canvas by default
        sidebar.classList.remove('open', 'collapsed');
        sidebar.style.transform = 'translateX(-100%)';
    }
});

// ==========================================
// INITIALIZATION
// ==========================================

/**
 * Initialize sidebar with proper event handlers
 */
function initializeSidebarButtons() {
    const sidebarButtons = document.querySelectorAll('#sidebar button:not(#logoutBtn)');

    sidebarButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            const filter = btn.dataset.filter;

            // Remove active class from all buttons
            sidebarButtons.forEach(b => b.classList.remove('active'));

            // Add active class to clicked button
            btn.classList.add('active');

            // Handle special sections
            if (filter === 'debtors') {
                showDebtorsSection();
            } else if (filter === 'reconciliation') {
                hideAllSections();
                showReconciliationView(null);
            } else if (filter === 'reports') {
                hideAllSections();
                showReportsView(null);
            } else {
                // Regular loan filters
                showLoansSection(filter);
            }

            // Close sidebar on mobile
            if (window.innerWidth <= 768) {
                toggleSidebar();
            }
        });
    });

    // Logout button
    const logoutButton = document.getElementById('logoutBtn');
    if (logoutButton) {
        logoutButton.addEventListener('click', logoutCashier);
    }
}

// Top-right profile menu, matching the Loanee portal interaction.
function initializeProfileMenu() {
    const profileBadge = document.getElementById('profileBadge');
    const profileDropdown = document.getElementById('profileDropdown');
    if (!profileBadge || !profileDropdown) return;

    profileBadge.addEventListener('click', (event) => {
        event.stopPropagation();
        profileDropdown.classList.toggle('active');
    });

    document.addEventListener('click', () => {
        profileDropdown.classList.remove('active');
    });

    document.getElementById('profileLogoutBtn')?.addEventListener('click', (event) => {
        event.stopPropagation();
        logoutCashier();
    });
}

// ==========================================
// IMPROVED INITIALIZATION
// ==========================================
async function initializeApp() {
    if (APP_STATE.loggedInFromMain) {
        APP_STATE.currentUser = APP_STATE.userData;

        // **Clear any overlays or loading states**
        document.querySelectorAll('[id*="overlay"]').forEach(el => el.remove());
        hideLoadingIndicator();

        try {
            const result = await apiCall('auth.php');
            if (result.success && result.data && result.data.csrf_token) {
                APP_STATE.csrfToken = result.data.csrf_token;
            }
        } catch (error) {
            const csrfInput = document.getElementById('csrf-token-data');
            if (csrfInput) {
                APP_STATE.csrfToken = csrfInput.dataset.csrfToken;
            }
        }

        // Start CSRF refresh (less frequent to reduce load)
        setInterval(refreshCSRFToken, 10 * 60 * 1000); // Every 10 minutes

        // Show sidebar and loans section
        DOM_ELEMENTS.loansSection.style.display = 'block';
        DOM_ELEMENTS.sidebar.style.display = 'flex';

        const mainElement = document.querySelector('main');

        if (window.innerWidth > 768) {
            APP_STATE.sidebarOpen = true;
            APP_STATE.sidebarCollapsed = false;
            DOM_ELEMENTS.sidebar.classList.add('open');

            if (mainElement) {
                mainElement.classList.add('sidebar-open');
            }
        } else {
            APP_STATE.sidebarOpen = false;
            APP_STATE.sidebarCollapsed = false;
            DOM_ELEMENTS.sidebar.classList.remove('open', 'collapsed');

            if (mainElement) {
                mainElement.classList.remove('sidebar-open', 'sidebar-collapsed');
            }
        }

        // Initialize theme
        initializeThemeSelector();

        // Initialize sidebar
        initializeSidebarButtons();

        // Show content
        hideAllSections();
        await renderLoans('all');

        // Show welcome notification
        showNotification('Welcome back, ' + APP_STATE.userData.fullName + '!', 'success');

        // Apply permissions
        applyPermissions();

        // Start notification polling (less frequent)
        initializeNotificationSystem();
        pollNotifications();
        setInterval(pollNotifications, 60000); // Every 60 seconds instead of 30

        // Session expiry is enforced by the server using idle activity time.
        // Do not use a login-time countdown here: it logged out active users
        // even while their API requests were keeping the server session alive.
        sessionStorage.setItem('loginTime', Date.now().toString());
    }
}

// ==========================================
// EVENT LISTENERS
// ==========================================

// Modal close event listeners
document.getElementById('closeDetailsModal').onclick = () => {
    document.getElementById('detailsModal').style.display = 'none';
};

document.getElementById('closeDetailedReconciliationModal').onclick = () => {
    document.getElementById('detailedReconciliationModal').style.display = 'none';
};

document.getElementById('closeAmortModal').onclick = () => {
    document.getElementById('amortModal').style.display = 'none';
};

document.getElementById('closePaymentModal').onclick = () => {
    document.getElementById('recordPaymentModal').style.display = 'none';
};

document.getElementById('cancelFeedback').onclick = () => {
    document.getElementById('feedbackModal').style.display = 'none';
};

document.getElementById('cancelFeedbackButton').onclick = () => {
    document.getElementById('feedbackModal').style.display = 'none';
};

document.getElementById('cancelPayment').onclick = () => {
    document.getElementById('recordPaymentModal').style.display = 'none';
};

// Payment form event listeners
document.getElementById('principalAmount').addEventListener('input', updatePaymentPreview);
document.getElementById('interestAmount').addEventListener('input', updatePaymentPreview);
document.getElementById('submitPayment').onclick = submitPayment;
document.getElementById('submitFeedback').onclick = submitFeedback;

// Sidebar and navigation event listeners
DOM_ELEMENTS.hamburgerMenu.addEventListener('click', toggleSidebar);

document.addEventListener('click', (event) => {
    if (window.innerWidth <= 768 &&
        APP_STATE.sidebarOpen &&
        !DOM_ELEMENTS.sidebar.contains(event.target) &&
        !DOM_ELEMENTS.hamburgerMenu.contains(event.target)) {
        toggleSidebar();
    }
});

window.addEventListener('resize', () => {
    // Define main locally since it's not in DOM_ELEMENTS
    const main = document.querySelector('main');

    if (window.innerWidth > 768) {
        if (!APP_STATE.sidebarOpen) {
            APP_STATE.sidebarOpen = true;
            APP_STATE.sidebarCollapsed = false;
            DOM_ELEMENTS.sidebar.classList.add('open');

            if (main) {
                main.classList.add('sidebar-open');
            }

            DOM_ELEMENTS.sidebar.classList.remove('collapsed');

            if (main) {
                // FIXED: Use 'main' instead of DOM_ELEMENTS.portalSection
                main.classList.remove('sidebar-collapsed');
            }
        }
    } else {
        if (APP_STATE.sidebarOpen) {
            APP_STATE.sidebarOpen = false;
            APP_STATE.sidebarCollapsed = false;
            DOM_ELEMENTS.sidebar.classList.remove('open', 'collapsed');

            if (main) {
                // FIXED: Use 'main' instead of DOM_ELEMENTS.portalSection
                main.classList.remove('sidebar-open', 'sidebar-collapsed');
            }
        }
    }
});

// Modal click-outside-to-close functionality
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
};

// ==========================================
// NOTIFICATION SYSTEM
// ==========================================

const NOTIFICATION_CONFIG = {
    pollInterval: 30000, // Check every 30 seconds
    soundEnabled: true,
    maxDisplayed: 10
};

let notificationPollTimer = null;
let notificationDropdownOpen = false;

/**
 * Initialize notification system
 * Sets up event listeners and configuration
 */
function initializeNotificationSystem() {
    const NOTIFICATION_CONFIG = {
        pollInterval: 30000, // Check every 30 seconds
        soundEnabled: true
    };

    // Close dropdown when clicking outside
    document.addEventListener('click', function(event) {
        const dropdown = document.getElementById('notificationDropdown');
        const bell = document.getElementById('notificationBell');

        if (!dropdown.contains(event.target) && !bell.contains(event.target)) {
            dropdown.style.display = 'none';
            notificationDropdownOpen = false;
        }
    });
}

/**
 * Poll for new notifications
 * Updates badge and plays sound if new notifications are available
 */
async function pollNotifications() {
    try {
        // First check if session is valid
        if (!await checkSessionValidity()) {
            // Stop polling if session is invalid
            clearInterval(notificationPollTimer);
            return;
        }

        const response = await apiCall('cashier_notifications.php?unread_only=true');

        if (response.success) {
            updateNotificationBadge(response.unread_count);

            // Show notification sound/alert if there are new ones
            if (response.unread_count > 0 && NOTIFICATION_CONFIG.soundEnabled) {
                playNotificationSound();
            }
        }
    } catch (error) {
        //console.error('Error polling notifications:', error);

        // If unauthorized, stop polling and redirect to login
        if (error.message.includes('Unauthorized')) {
            clearInterval(notificationPollTimer);
            showNotification('Your session has expired. Please log in again.', 'warning', 5000);
            setTimeout(() => {
                logoutCashier();
            }, 2000);
        }
    }
}

/**
 * Update notification badge with count
 * @param {number} count - Number of unread notifications
 */
function updateNotificationBadge(count) {
    const badge = document.getElementById('notificationBadge');

    if (count > 0) {
        badge.textContent = count > 99 ? '99+' : count;
        badge.style.display = 'block';
    } else {
        badge.style.display = 'none';
    }
}

/**
 * Toggle notification dropdown visibility
 * Loads notifications when opening
 */
async function toggleNotificationDropdown() {
    const dropdown = document.getElementById('notificationDropdown');
    notificationDropdownOpen = !notificationDropdownOpen;

    if (notificationDropdownOpen) {
        dropdown.style.display = 'block';
        await loadNotifications();
    } else {
        dropdown.style.display = 'none';
    }
}

/**
 * Load and display notifications in dropdown
 * Fetches from server and renders HTML
 */
async function loadNotifications() {
    const notificationList = document.getElementById('notificationList');
    notificationList.innerHTML = '<div style="text-align: center; padding: 20px;">Loading...</div>';

    try {
        // Remove limit parameter to fetch all notifications
        const response = await apiCall('cashier_notifications.php');

        if (response.success) {
            if (response.data.length === 0) {
                notificationList.innerHTML =
                    '<div class="notification-empty">No notifications</div>';
                return;
            }

            let html = '';
            response.data.forEach(notification => {
                const isUnread = notification.is_read === 0 || notification.is_read === '0';
                const timeAgo = getTimeAgo(notification.created_at);

                html += `
                <div class="notification-item ${isUnread ? 'unread' : ''}"
                    onclick="handleNotificationClick(${notification.id}, ${notification.loan_application_id})">
                    <div class="notification-message">${escapeHtml(notification.message)}</div>
                    <div class="notification-time">${timeAgo}</div>
                    <button class="notification-delete"
                        onclick="deleteNotification(${notification.id}, event)">
                        ×
                    </button>
                </div>
            `;
            });

            notificationList.innerHTML = html;
        }
    } catch (error) {
        //console.error('Error loading notifications:', error);
        notificationList.innerHTML =
            '<div class="notification-empty">Error loading notifications</div>';
    }
}

// ==========================================
// NOTIFICATION HANDLERS
// ==========================================

/**
 * Handle notification click event
 * @param {number} notificationId - ID of the notification
 * @param {number} loanApplicationId - ID of the related loan application
 */
async function handleNotificationClick(notificationId, loanApplicationId) {
    try {
        // Mark as read
        await apiCall('cashier_notifications.php', 'PUT', {
            notification_id: notificationId,
            csrf_token: APP_STATE.csrfToken
        });

        // Reload notifications
        await loadNotifications();
        await pollNotifications();

        // If there's a loan application ID, load its details
        if (loanApplicationId) {
            const loans = await getAllLoans();
            const loan = loans.find(l => l.id === loanApplicationId);
            if (loan) {
                showLoanDetails(loan);
            }
        }

        // Close dropdown
        document.getElementById('notificationDropdown').style.display = 'none';
        notificationDropdownOpen = false;
    } catch (error) {
        //console.error('Error handling notification click:', error);
        showNotification('Error processing notification', 'error');
    }
}

/**
 * Mark all notifications as read
 */
async function markAllAsRead() {
    try {
        const response = await apiCall('cashier_notifications.php', 'PUT', {
            mark_all_read: true,
            csrf_token: APP_STATE.csrfToken
        });

        if (response.success) {
            await loadNotifications();
            await pollNotifications();
            showNotification('All notifications marked as read', 'success');
        }
    } catch (error) {
        //console.error('Error marking all as read:', error);
        showNotification('Error marking notifications as read', 'error');
    }
}

/**
 * Delete a specific notification
 * @param {number} notificationId - ID of the notification to delete
 * @param {Event} event - Click event to stop propagation
 */
async function deleteNotification(notificationId, event) {
    event.stopPropagation();

    if (!confirm('Delete this notification?')) {
        return;
    }

    try {
        const response = await apiCall('cashier_notifications.php', 'DELETE', {
            notification_id: notificationId,
            csrf_token: APP_STATE.csrfToken
        });

        if (response.success) {
            await loadNotifications();
            await pollNotifications();
            showNotification('Notification deleted', 'success');
        }
    } catch (error) {
        //console.error('Error deleting notification:', error);
        showNotification('Error deleting notification', 'error');
    }
}

/**
 * View all notifications in a modal (placeholder function)
 */
function viewAllNotifications() {
    // You can implement a full notifications modal here
    showNotification('Feature coming soon', 'info');
}

// ==========================================
// UTILITY FUNCTIONS
// ==========================================

/**
 * Get a human-readable "time ago" string from a date
 * @param {string} dateString - ISO date string
 * @returns {string} Human readable time difference
 */
function getTimeAgo(dateString) {
    const date = new Date(dateString);
    const now = new Date();
    const seconds = Math.floor((now - date) / 1000);

    const intervals = {
        year: 31536000,
        month: 2592000,
        week: 604800,
        day: 86400,
        hour: 3600,
        minute: 60
    };

    for (const [unit, secondsInUnit] of Object.entries(intervals)) {
        const interval = Math.floor(seconds / secondsInUnit);
        if (interval >= 1) {
            return `${interval} ${unit}${interval > 1 ? 's' : ''} ago`;
        }
    }

    return 'Just now';
}

/**
 * Escape HTML characters to prevent XSS
 * @param {string} text - Text to escape
 * @returns {string} Escaped HTML
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Play notification sound (placeholder implementation)
 */
function playNotificationSound() {
    // You can use Web Audio API or a simple audio element
    // For now, we'll use the browser's built-in notification sound
    if ('Notification' in window && Notification.permission === 'granted') {
        // Browser notification (optional)
        // new Notification('New notification', { body: 'You have new notifications' });
    }
}

// ==========================================
// PORTAL SWITCHING FUNCTIONS
// ==========================================

/**
 * Apply for loan function with proper error handling
 * Switches from staff portal to loanee portal
 */
function applyForLoan() {
    const confirmSwitch = confirm("You're about to switch to the loan application portal. Continue?");

    if (!confirmSwitch) {
        return;
    }

    // Show loading state
    showNotification('Switching to loanee portal...', 'info');

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

    // Get current portal name
    const currentPortal = window.location.pathname.includes('admin.php') ? 'admin' :
        window.location.pathname.includes('cashier.php') ? 'cashier' :
        window.location.pathname.includes('accountant.php') ? 'accountant' : 'index';

    // Redirect with proper parameters
    setTimeout(() => {
        window.location.href = `loanee.php?from=${currentPortal}&action=switch_portal`;
    }, 500);
}

/**
 * Return to staff portal function
 * Switches from loanee portal back to staff portal
 */
function returnToStaffPortal() {
    if (!confirm('Return to your staff portal? Any unsaved loan application data will be lost.')) {
        return;
    }

    // Show loading notification
    showNotification('Returning to staff portal...', 'info');

    // Create loading overlay
    const loadingOverlay = document.createElement('div');
    loadingOverlay.id = 'portal-return-overlay';
    loadingOverlay.style.cssText =
        'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(255,255,255,0.9);z-index:9999;display:flex;justify-content:center;align-items:center;flex-direction:column;';

    const spinner = document.createElement('div');
    spinner.style.cssText =
        'border:5px solid #f3f3f3;border-top:5px solid #4a6b4a;border-radius:50%;width:50px;height:50px;animation:spin 1s linear infinite;';

    const loadingText = document.createElement('p');
    loadingText.textContent = 'Returning to staff portal...';
    loadingText.style.cssText = 'margin-top:20px;font-weight:bold;color:#4a6b4a;';

    loadingOverlay.appendChild(spinner);
    loadingOverlay.appendChild(loadingText);
    document.body.appendChild(loadingOverlay);

    // Redirect after brief delay
    setTimeout(() => {
        window.location.href = 'loanee.php?action=return_to_staff';
    }, 500);
}

// Add CSS animation for spinner
const style = document.createElement('style');
style.textContent = `
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
`;
document.head.appendChild(style);

// Make functions globally available
window.applyForLoan = applyForLoan;
window.returnToStaffPortal = returnToStaffPortal;

// ==========================================
// SESSION MANAGEMENT
// ==========================================

/**
 * Check if current user session is valid
 * @returns {boolean} True if session is valid, false otherwise
 */
async function checkSessionValidity() {
    try {
        const response = await apiCall('auth.php');
        if (!response.success) {
            // Session is invalid, redirect to login
            showNotification('Your session has expired. Please log in again.', 'warning', 5000);
            setTimeout(() => {
                logoutCashier();
            }, 2000);
            return false;
        }
        return true;
    } catch (error) {
        //console.error('Session check failed:', error);
        showNotification('Session validation failed. Please save your work.', 'warning');
        return false;
    }
}

// ==========================================
// INITIALIZATION
// ==========================================

/**
 * Initialize export buttons after DOM is updated
 * Sets CSV as default active export format
 */
setTimeout(() => {
    // Set CSV as default active
    const csvBtn = document.getElementById('exportCSVBtn');
    const excelBtn = document.getElementById('exportExcelBtn');

    if (csvBtn && excelBtn) {
        csvBtn.classList.add('active');
        excelBtn.classList.remove('active');
    }
}, 100);

// ===========================
// 🎨 THEME MANAGEMENT
// ===========================

/**
 * Initialize theme selector with proper event handlers
 */
function initializeThemeSelector() {
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

            showNotification(`Theme changed to ${theme}`, 'success');
        });
    });
}

/**
 * Load theme preference from database
 */
async function loadThemeFromDatabase() {
    try {
        const response = await apiRequest(
            'preferences.php?action=get&key=user_theme_preference',
            'GET'
        );

        if (response && response.success && response.value) {
            const theme = response.value;
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
 * @param {string} theme - Theme name to save
 */
async function saveThemeToDatabase(theme) {
    try {
        const response = await apiRequest('preferences.php', 'POST', {
            action: 'set',
            key: 'user_theme_preference',
            value: theme
        });

        if (!response.success) {
            //console.error('Failed to save theme preference:', response.message);
        }
    } catch (error) {
        //console.error('Error saving theme:', error);
    }
}

// ==========================================
// START APPLICATION
// ==========================================
document.addEventListener('DOMContentLoaded', function() {
    //console.log('DOM loaded - initializing...');

    // Ensure table structure
    ensureTableStructure();

    // Initialize sidebar
    initializeSidebarButtons();
    initializeProfileMenu();

    // Hide all sections initially
    hideAllSections();

    // Initialize migration button
    initializeMigrationButton();

    // ==========================================
    // FIX: Enforce Sidebar Open on Desktop
    // ==========================================
    const sidebar = document.getElementById('sidebar');
    const main = document.querySelector('main');

    if (sidebar && main) {
        if (window.innerWidth > 768) {
            // Force sidebar to be open on desktop
            if (!sidebar.classList.contains('open')) {
                sidebar.classList.add('open');
            }
            // Ensure main content has correct padding
            main.classList.add('sidebar-open');
            main.classList.remove('sidebar-collapsed');
        } else {
            // On mobile, ensure correct initial state (hidden)
            sidebar.classList.remove('open');
            main.classList.remove('sidebar-open', 'sidebar-collapsed');
        }
    }

    // Initialize debtor import
    if (typeof initializeDebtorImport === 'function') {
        initializeDebtorImport();
    }

    // Initialize app
    initializeApp();

    adjustMainContentSpacing();
});

// Make functions globally available
window.showDebtorsSection = showDebtorsSection;
window.showLoansSection = showLoansSection;
window.hideAllSections = hideAllSections;
window.updateSidebarActive = updateSidebarActive;

/**
 * Show loading indicator
 */
function showLoadingIndicator() {
    const loadingIndicator = document.getElementById('loadingIndicator');
    if (loadingIndicator) {
        loadingIndicator.style.display = 'block';
    }
}

/**
 * Hide loading indicator
 */
function hideLoadingIndicator() {
    const loadingIndicator = document.getElementById('loadingIndicator');
    if (loadingIndicator) {
        loadingIndicator.style.display = 'none';
    }
}

// Debug portal switching
//console.log('=== Portal Info ===');
//console.log('Page:', window.location.pathname);
//console.log('User Role:', APP_STATE.currentUser ? APP_STATE.currentuser_role : 'Not defined');
/*//console.log('Viewing as Loanee:', <?php echo isset($_SESSION['viewing_as_loanee']) ? 'true' : 'false'; ?>);*/
/*//console.log('Original Portal:', <?php echo isset($_SESSION['original_portal']) ? '"' . $_SESSION['original_portal'] . '"' : 'null'; ?>);*/

// ==========================================
// DEBTOR IMPORT FUNCTIONALITY
// ==========================================

/**
 * Initialize debtor import functionality for cashier portal
 * Prevents duplicate event listeners.
 */
let isDebtorImportInitialized = false;

function initializeDebtorImport() {
    if (isDebtorImportInitialized) return;

    // Populate financial year dropdown dynamically
    populateFinancialYears();

    const importBtn = document.getElementById('importDebtorsBtn');
    const downloadTemplateBtn = document.getElementById('downloadDebtorTemplateBtn');
    const viewDebtorsBtn = document.getElementById('viewImportedDebtorsBtn');
    const fileInput = document.getElementById('importDebtorFileInput');

    if (importBtn) {
        importBtn.addEventListener('click', () => {
            const fySelect = document.getElementById('debtorFinancialYear');
            if (!fySelect.value) {
                showNotification('Please select a financial year first', 'error');
                fySelect.focus();
                return;
            }
            fileInput.click();
        });
    }

    if (downloadTemplateBtn) {
        downloadTemplateBtn.addEventListener('click', downloadDebtorTemplate);
    }

    if (viewDebtorsBtn) {
        viewDebtorsBtn.addEventListener('click', viewImportedDebtors);
    }

    if (fileInput) {
        fileInput.addEventListener('change', handleDebtorFileImport);
    }

    isDebtorImportInitialized = true;
}

/**
 * Populate financial years dynamically (2024/2025 onwards for 20 years)
 */
function populateFinancialYears() {
    const select = document.getElementById('debtorFinancialYear');
    if (!select) return;

    const startYear = 2024;
    const numberOfYears = 60;

    // Clear existing options except the first "Select Year" option
    while (select.options.length > 1) {
        select.remove(1);
    }

    // Generate financial years from 2024/2025 onwards
    for (let i = 0; i < numberOfYears; i++) {
        const year1 = startYear + i;
        const year2 = year1 + 1;
        const financialYear = `${year1}/${year2}`;
        const option = document.createElement('option');
        option.value = financialYear;
        option.textContent = `FY ${financialYear}`;
        select.appendChild(option);
    }
}

/**
 * Download debtor import template
 */
function downloadDebtorTemplate() {
    const template = [
        ['Nyeri County Executive Car Loan and Mortgage (Staff) Scheme Fund'],
        ['Long Term Loan Repayments Due FY 2024 / 2025'],
        ['', 'Name', 'Amount'],
        ['1', 'John Doe', '1,500,000'],
        ['2', 'Jane Smith', '2,300,000'],
        ['3', 'Robert Johnson', '750,000']
    ];

    const csv = template.map(row => row.join(',')).join('\n');
    const blob = new Blob([csv], {
        type: 'text/csv;charset=utf-8;'
    });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);

    link.href = url;
    link.download = 'debtor_import_template.csv';
    link.click();

    URL.revokeObjectURL(url);
    showNotification('Template downloaded successfully', 'success');
}

/**
 * Handle debtor file import (Improved)
 * @param {Event} event - File input change event
 */
async function handleDebtorFileImport(event) {
    const file = event.target.files[0];
    if (!file) return;

    const fySelect = document.getElementById('debtorFinancialYear');
    const financialYear = fySelect.value;

    if (!financialYear) {
        showNotification('Please select a financial year first', 'error');
        fySelect.focus();
        return;
    }

    const fileExtension = file.name.split('.').pop().toLowerCase();

    showLoadingIndicator();

    try {
        let debtors = [];

        if (fileExtension === 'csv') {
            debtors = await parseDebtorCSV(file);
        } else if (fileExtension === 'xlsx' || fileExtension === 'xls') {
            debtors = await parseDebtorExcel(file);
        } else {
            throw new Error('Unsupported file format. Please use CSV or Excel (XLS / XLSX).');
        }

        if (debtors.length === 0) {
            throw new Error('No valid debtor data found in file');
        }

        // Show preview modal
        showDebtorImportPreview(debtors, financialYear);

    } catch (error) {
        showNotification('Error reading file: ' + error.message, 'error');
    } finally {
        hideLoadingIndicator();
        // IMPORTANT: Reset input so 'change' event triggers again if same file selected
        event.target.value = '';
    }
}

/**
 * Parse CSV file for debtors (Robust Version)
 * Handles with Template format (ID, Name, Amount) and prevents code injection.
 */
function parseDebtorCSV(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();

        reader.onload = (e) => {
            try {
                const text = e.target.result;
                const lines = text.split('\n').filter(line => line.trim());

                if (lines.length < 2) {
                    reject(new Error('CSV file appears to be empty or invalid'));
                    return;
                }

                const debtors = [];
                const seenRecords = new Set();
                let headerIndex = -1;

                // ==========================================
                // 1. Identify Header Row and Map Columns
                // ==========================================
                let headerMap = {
                    name: -1,
                    pfNumber: -1,
                    idNumber: -1,
                    amount: -1,
                    refNumber: -1
                };

                // We loop through the first 10 lines to find the header
                for (let i = 0; i < Math.min(lines.length, 10); i++) {
                    const line = lines[i].trim();
                    if (!line) continue;

                    const parts = line.split(',');
                    const lineLower = parts.join(' ').toLowerCase();

                    // Detect headers based on keywords
                    if (lineLower.includes('name') && lineLower.includes('amount')) {
                        headerIndex = i;

                        // Map columns by checking content of each cell in this row
                        parts.forEach((col, idx) => {
                            const colLower = col.toLowerCase().trim();

                            // Name Aliases
                            if (colLower.includes('name') && !colLower.includes(
                                    'bank')) {
                                headerMap.name = idx;
                            }
                            // PF Number Aliases
                            if (colLower.includes('pf') || colLower.includes(
                                    'staff id') ||
                                colLower.includes('employee id')) {
                                headerMap.pfNumber = idx;
                            }
                            // ID Number Aliases
                            if (colLower.includes('id no') || colLower.includes(
                                    'national id')) {
                                headerMap.idNumber = idx;
                            }
                            // Amount Aliases
                            if (colLower.includes('amount') || colLower.includes(
                                    'balance') ||
                                colLower.includes('arrears')) {
                                headerMap.amount = idx;
                            }
                            // Ref/Txn ID
                            if (colLower.includes('txn') || colLower.includes(
                                    'ref') || colLower
                                .includes('transaction')) {
                                headerMap.refNumber = idx;
                            }
                        });
                        break; // Found headers, stop looking
                    }
                }

                // Fallback: If headers not found by keywords, try standard order
                if (headerIndex === -1 && lines.length > 0) {
                    headerIndex = 0;
                    const firstLineParts = lines[0].split(',');

                    // Check if first column is numeric (ID column)
                    if (!isNaN(firstLineParts[0].trim())) {
                        // Assume Format: ID, Name, Amount
                        headerMap.name = 1;
                        headerMap.amount = 2;
                    } else {
                        // Assume Format: Name, Amount
                        headerMap.name = 0;
                        headerMap.amount = 1;
                    }
                }

                if (headerMap.name === -1 || headerMap.amount === -1) {
                    reject(new Error(
                        'Could not identify headers (Name, Amount). Please check CSV format.'
                    ));
                    return;
                }

                // ==========================================
                // 2. Parse Data Rows
                // ==========================================
                for (let i = headerIndex + 1; i < lines.length; i++) {
                    const line = lines[i].trim();
                    if (!line) continue;

                    // Regex to split by comma, ignoring commas inside quotes
                    // e.g., "Doe, John", 1000 -> ['Doe, John', '1000']
                    const parts = line.split(/,(?=(?:(?:[^"]*"){2})*[^"]*$)/);

                    if (parts.length <= 1) continue; // Skip empty rows

                    // Helper to get clean value from index
                    const getValue = (idx) => {
                        let val = parts[idx] ? parts[idx].trim().replace(/^"|"$/g, '') :
                            '';
                        return val;
                    };

                    const name = getValue(headerMap.name);
                    const rawPF = getValue(headerMap.pfNumber);
                    const rawID = getValue(headerMap.idNumber);
                    const rawAmount = getValue(headerMap.amount);

                    // --- SECURITY CHECK: Prevent Code Injection ---
                    // Check if name looks like code (starts with function, const, <, //, etc.)
                    const codeKeywords =
                        /^(function|const|let|var|\/\/|\s*\(|<\?php|import|require)/i;
                    if (codeKeywords.test(name) || name.length < 3) {
                        // Skip this row silently to avoid errors
                        continue;
                    }

                    // --- AMOUNT PARSING ---
                    let amount = 0;
                    if (rawAmount) {
                        // Remove all non-digit characters except decimal point and negative sign
                        // Handles "KES 1,500,000" -> 1500000
                        const cleanAmount = rawAmount.replace(/[^0-9.\-]/g, '').replace(
                            /,/g, '');
                        amount = parseFloat(cleanAmount);
                    }

                    // Create unique key: PF_Number + Amount (rounded to avoid decimal differences)
                    const roundedAmount = Math.round(amount);
                    const duplicateKey = `${rawPF}_${roundedAmount}`;

                    // Check if this record already exists
                    if (seenRecords.has(duplicateKey)) {
                        console.log(
                            `Duplicate detected and skipped: ${name} (PF: ${rawPF}, Amount: ${roundedAmount})`
                        );
                        continue; // Skip this duplicate
                    }

                    // Mark this record as seen
                    seenRecords.add(duplicateKey);

                    // --- VALIDATION ---
                    // Only push if valid data exists
                    if (name && !isNaN(amount) && amount > 0) {
                        debtors.push({
                            name: name,
                            amount: Math.abs(amount), // Ensure positive
                            pf_number: rawPF || '',
                            id_number: rawID || ''
                        });
                    }
                }

                if (debtors.length === 0) {
                    reject(new Error('No valid debtor data found in file'));
                    return;
                }

                const duplicatesCount = seenRecords.size - debtors.length;
                debtors.duplicatesCount = duplicatesCount; // ADD THIS
                resolve(debtors);
            } catch (error) {
                reject(error);
            }
        };

        reader.onerror = () => reject(new Error('Failed to read CSV file'));
        reader.readAsText(file);
    });
}

/**
 * Perform debtor import (FIXED VERSION)
 * Fixes "undefined" display issues and calculates counts accurately.
 * @param {Array} debtors - Array of valid debtor objects
 * @param {string} financialYear - Financial year string
 * @param {HTMLElement} modal - Modal element
 */
async function performDebtorImport(debtors, financialYear, modal) {
    const confirmBtn = document.getElementById('confirmDebtorImport');
    const cancelBtn = document.getElementById('cancelDebtorImport');
    const progressDiv = document.getElementById('debtorImportProgress');
    const progressBar = document.getElementById('debtorProgressBar');

    // Disable buttons
    confirmBtn.disabled = true;
    cancelBtn.disabled = true;

    // Show progress
    progressDiv.style.display = 'block';

    try {
        // Use APP_STATE.csrfToken if available, otherwise fallback to DOM
        const csrfToken = APP_STATE.csrfToken ||
            (document.getElementById('csrf-token-data') ? document.getElementById('csrf-token-data')
                .dataset
                .csrfToken : null);

        if (!csrfToken) {
            throw new Error('CSRF token not found');
        }

        // Calculate total amount from the debtors array (Safely)
        let totalAmount = 0;
        debtors.forEach(d => {
            let amount = 0;
            // Handle numeric amounts safely
            if (typeof d.amount === 'number') {
                amount = d.amount;
            } else if (typeof d.amount === 'string' && d.amount !== '') {
                // Remove non-numeric characters (currency symbols, commas) but keep decimal
                const cleanAmount = d.amount.replace(/[^0-9.\-]/g, '').replace(/,/g, '');
                amount = parseFloat(cleanAmount);
            }
            // Only add if valid number
            if (!isNaN(amount) && amount > 0) {
                totalAmount += Math.abs(amount);
            }
        });

        // Update progress
        progressBar.style.width = '100%';
        progressBar.textContent = '100%';

        // Send import request
        // FIXED: Changed from 'import-debtors.php' to 'debtors.php'
        const response = await apiCall('debtors.php', 'POST', {
            debtors: debtors,
            financial_year: financialYear,
            csrf_token: csrfToken
        });

        // Calculate imported count explicitly
        const importedCount = debtors.length;

        // Update progress
        progressBar.style.width = '100%';
        progressBar.textContent = '100%';

        if (response.success) {
            // FIX: Use safe defaults and actual count
            const displayTotal = totalAmount > 0 ? `KES ${formatCurrency(totalAmount)}` : 'KES 0';

            showNotification(
                `Successfully imported ${importedCount} legacy loan(s) worth ${displayTotal}. These loans are now ACTIVE.`,
                'success'
            );

            // Close import preview modal
            setTimeout(() => {
                modal.remove();
            }, 1500);

            // FIXED: Pass importedCount explicitly to the view/export function
            viewImportedDebtors(null, importedCount);
        } else {
            throw new Error(response.message || 'Import failed');
        }

    } catch (error) {
        console.error('Import error:', error);
        showNotification('Import failed: ' + error.message, 'error');
        confirmBtn.disabled = false;
        cancelBtn.disabled = false;
        progressDiv.style.display = 'none';
    }
}

/**
 * Parse Excel file for debtors (Robust Version)
 */
async function parseDebtorExcel(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();

        reader.onload = async (e) => {
            try {
                // Load XLSX library if not present
                if (typeof XLSX === 'undefined') {
                    await loadScript(
                        'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js'
                    );
                }

                const data = new Uint8Array(e.target.result);
                const workbook = XLSX.read(data, {
                    type: 'array',
                    cellDates: false,
                    cellNF: false,
                    cellText: false,
                    raw: true
                });

                const sheetName = workbook.SheetNames[0];
                const worksheet = workbook.Sheets[sheetName];

                // Convert to JSON with first row as header
                const jsonData = XLSX.utils.sheet_to_json(worksheet, {
                    header: 1,
                    defval: '',
                    blankrows: false,
                    raw: false
                });

                if (jsonData.length < 2) {
                    reject(new Error('Excel file appears to be empty or invalid'));
                    return;
                }

                const debtors = [];
                const seenRecords = new Set();
                let nameColIndex = -1;
                let amountColIndex = -1;
                let pfColIndex = -1;

                // Loop through rows to find header indices (skipping potential title rows)
                for (let i = 0; i < jsonData.length; i++) {
                    const row = jsonData[i];
                    const keys = Object.keys(row);

                    // Skip title rows (rows containing 'Nyeri', 'Long Term', etc.)
                    const isTitleRow = keys.some(key =>
                        String(row[key]).includes('Nyeri') ||
                        String(row[key]).includes('Long Term Loan') ||
                        String(row[key]).includes('Executive'));
                    if (isTitleRow) {
                        continue;
                    }

                    // Find header row
                    if (nameColIndex === -1) {
                        for (let j = 0; j < keys.length; j++) {
                            const cellValue = String(row[keys[j]]).toLowerCase()
                                .trim();

                            if (cellValue.includes('name') && !cellValue.includes(
                                    'bank')) {
                                nameColIndex = keys[j];
                            }
                            if (cellValue.includes('amount')) {
                                amountColIndex = keys[j];
                            }

                            // Detect PF Number column
                            if (cellValue.includes('pf') || cellValue.includes('staff id')) {
                                pfColIndex = keys[j];
                            }
                        }

                        if (nameColIndex >= 0 && amountColIndex >= 0) {
                            break; // Found headers
                        }
                    }

                    if (nameColIndex === -1) {
                        // Fallback mapping if headers not found
                        nameColIndex = keys[1] || keys[0]; // Guess
                        amountColIndex = keys[2] || keys[1]; // Guess
                    }

                    // Process data rows
                    if (nameColIndex >= 0) {
                        const name = String(row[nameColIndex] || '').trim();
                        const amountValue = row[amountColIndex];
                        const pfNumber = pfColIndex >= 0 ? String(row[pfColIndex] || '')
                            .trim() : ''; // ADD THIS

                        // Security check
                        const codeKeywords =
                            /^(function|const|let|var|\/\/|\s*\(|<\?php|import|require)/i;
                        if (codeKeywords.test(name) || name.length < 3) {
                            continue;
                        }

                        // --- AMOUNT PARSING ---
                        let amount = 0;
                        let originalAmount = String(amountValue);

                        if (typeof amountValue === 'number') {
                            amount = Math.round(amountValue);
                        } else {
                            // Remove currency symbols, text, keep numbers
                            const cleanAmount = originalAmount.replace(/[^0-9.\-]/g,
                                    '')
                                .replace(/\..*$/, '');
                            amount = parseFloat(cleanAmount);
                        }

                        // === ADD DUPLICATE DETECTION HERE ===
                        if (!isNaN(amount) && amount > 0) {
                            const roundedAmount = Math.round(amount);
                            const duplicateKey = `${pfNumber}_${roundedAmount}`;

                            // Check if this record already exists
                            if (seenRecords.has(duplicateKey)) {
                                console.log(
                                    `Duplicate detected and skipped: ${name} (PF: ${pfNumber}, Amount: ${roundedAmount})`
                                );
                                continue; // Skip this duplicate
                            }

                            // Mark this record as seen
                            seenRecords.add(duplicateKey);
                            // === END DUPLICATE DETECTION ===

                            debtors.push({
                                name: name,
                                amount: Math.abs(amount),
                                pf_number: pfNumber,
                                original_amount: originalAmount
                            });
                        }
                    }
                }

                if (debtors.length === 0) {
                    reject(new Error('No valid debtor data found in file'));
                }

                const duplicatesCount = seenRecords.size - debtors.length;
                debtors.duplicatesCount = duplicatesCount; // ADD THIS
                resolve(debtors);
            } catch (error) {
                reject(error);
            }
        };

        reader.onerror = () => reject(new Error('Failed to read Excel file'));
        reader.readAsArrayBuffer(file);
    });
}

/**
 * Load external script dynamically
 * @param {string} src - URL of script to load
 * @returns {Promise} Promise resolving when script is loaded
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

// ==========================================
// DEBTOR IMPORT MODAL AND FUNCTIONS
// ==========================================

/**
 * Show debtor import preview modal
 * @param {Array} debtors - Array of debtor objects
 * @param {string} financialYear - Financial year string
 */
function showDebtorImportPreview(debtors, financialYear) {
    // Validate debtors
    const validDebtors = [];
    const warnings = [];

    debtors.forEach((debtor, index) => {
        const issues = [];

        // Name validation
        if (!debtor.name || debtor.name.trim().length < 3) {
            issues.push('Name too short (min 3 characters)');
        }

        // Amount validation
        if (!debtor.amount || isNaN(debtor.amount)) {
            issues.push('Amount is missing or invalid');
        } else if (debtor.amount <= 0) {
            issues.push('Amount must be greater than zero');
        } else if (debtor.amount > 100000000) {
            issues.push('Amount exceeds limit (KES 100M)');
        }

        // Warnings for high amounts
        if (debtor.amount > 50000000 && debtor.amount <= 100000000) {
            warnings.push(
                `Row ${index + 1} - ${debtor.name}: Very high amount (KES ${formatCurrency(debtor.amount)})`
            );
        }

        debtor.issues = issues;
        debtor.isValid = issues.length === 0;

        if (debtor.isValid) {
            validDebtors.push(debtor);
        }
    });

    const invalidCount = debtors.length - validDebtors.length;

    // Calculate total with proper number handling
    const totalAmount = validDebtors.reduce((sum, d) => {
        const amount = parseFloat(d.amount);
        return sum + (isNaN(amount) ? 0 : amount);
    }, 0);

    // ADD THIS: Track duplicates removed during parsing
    const duplicatesRemoved = (debtors.duplicatesCount || 0);

    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.id = 'debtorImportModal';

    modal.innerHTML = `
    <div class="modal-content" style="max-width: 1000px;">
        <div class="modal-header">
            <h2><i data-lucide="bar-chart-3" class="icon-sm"></i> Import Debtors Preview - FY ${escapeHtml(financialYear)}</h2>
            <span class="close-btn" id="closeDebtorModal">&times;</span>
        </div>
        <div class="modal-body">
            <div class="debtor-stats">
                <div class="debtor-stat-card success">
                    <div class="debtor-stat-number">${validDebtors.length}</div>
                    <div class="debtor-stat-label">Valid Records</div>
                </div>
                
                <div class="debtor-stat-card ${invalidCount > 0 ? 'warning' : ''}">
                    <div class="debtor-stat-number">${invalidCount}</div>
                    <div class="debtor-stat-label">Invalid Records</div>
                </div>
                
                <!-- ADD THIS: Show duplicates removed -->
                ${duplicatesRemoved > 0 ? `
                <div class="debtor-stat-card warning">
                    <div class="debtor-stat-number">${duplicatesRemoved}</div>
                    <div class="debtor-stat-label">Duplicates Removed</div>
                </div>
                ` : ''}
                
                <div class="debtor-stat-card info">
                    <div class="debtor-stat-number">KES ${formatCurrency(totalAmount)}</div>
                    <div class="debtor-stat-label">Total Amount</div>
                </div>
            </div>

            <!-- ADD THIS: Duplicates notice -->
            ${duplicatesRemoved > 0 ? `
            <p class="small" style="color: #f57c00; margin: 15px 0; background: #fff3e0; padding: 10px; border-radius: 5px;">
                <strong>ℹ️ Note:</strong> ${duplicatesRemoved} duplicate record(s) were automatically removed based on matching PF Number + Amount.
            </p>
            ` : ''}

            <p class="small" style="color: var(--text-secondary); margin: 15px 0;">
                <strong>Note:</strong> These debtors will be recorded as outstanding from the previous financial year.
            </p>

            ${warnings.length > 0 ? `
            <div class="error-list" style="background: #fff3e0; border-left-color: #f57c00; margin-bottom: 15px;">
                <strong style="color: #f57c00;"><i data-lucide="alert-triangle" class="icon-sm"></i> Warnings (${warnings.length})</strong>
                ${warnings.slice(0, 5).map(w => `
                <div class="error-item" style="border-left-color: #f57c00;">
                    ${escapeHtml(w)}</div>
                `).join('')}
                ${warnings.length > 5 ? `<div class="error-item" style="border-left-color: #f57c00;">
                    ... and ${warnings.length - 5} more</div>` : ''}
            </div>
            ` : ''}

            <div class="debtor-preview-table">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Debtor Name</th>
                            <th style="text-align: right;">Outstanding Amount (KES)</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${debtors.map((debtor, index) => {
                            const rowClass = debtor.isValid ? '' : 'validation-error';
                            const statusIcon = debtor.isValid ? '<i data-lucide="check-circle" class="icon-sm"></i>' : '<i data-lucide="x-circle" class="icon-sm"></i>';
                            const statusText = debtor.isValid ? 'Valid' : debtor.issues.join(', ');
                            const displayAmount = isNaN(debtor.amount) ? 'Invalid' : formatCurrency(debtor.amount);

                            return `
                            <tr class="${rowClass}">
                                <td>${index + 1}</td>
                                <td>${escapeHtml(debtor.name)}</td>
                                <td style="text-align: right; font-weight: 600; font-family: monospace;">
                                    ${isNaN(debtor.amount) ? 
                                    '<span style="color: red;">Invalid</span>' : 
                                    'KES ' + Math.round(debtor.amount).toLocaleString('en-KE')}
                                </td>
                                <td>${statusIcon} ${statusText}</td>
                            </tr>
                            `;
                        }).join('')}
                        <tr style="background: #f5f5f5; font-weight: bold; border-top: 2px solid #333;">
                            <td colspan="2" style="text-align: right; padding: 12px;">TOTAL:</td>
                            <td style="text-align: right; font-weight: bold; font-family: monospace; font-size: 1.1em; padding: 12px;">
                                KES ${formatCurrency(totalAmount)}
                            </td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            ${invalidCount > 0 ? `
            <div class="error-list" style="margin-top: 15px;">
                <strong style="color: var(--danger);"><i data-lucide="alert-triangle" class="icon-sm"></i> ${invalidCount} Invalid Records</strong>
                <p class="small">Records with validation errors will be skipped during import.</p>
            </div>
            ` : ''}

            <div id="debtorImportProgress" style="display: none; margin-top: 20px;">
                <div class="progress-bar">
                    <div class="progress-fill" id="debtorProgressBar" style="width: 0%;">0%</div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button id="cancelDebtorImport" class="action-btn reject-btn">Cancel</button>
            <button id="confirmDebtorImport" class="action-btn" ${validDebtors.length===0 ? 'disabled' : '' }>
                Import ${validDebtors.length} Debtor${validDebtors.length !== 1 ? 's' : ''} (KES ${formatCurrency(totalAmount)})
            </button>
        </div>
    </div>
`;

    document.body.appendChild(modal);
    modal.style.display = 'flex';

    // Event listeners
    document.getElementById('closeDebtorModal').addEventListener('click', () => {
        modal.remove();
    });

    document.getElementById('cancelDebtorImport').addEventListener('click', () => {
        modal.remove();
    });

    document.getElementById('confirmDebtorImport').addEventListener('click', () => {
        performDebtorImport(validDebtors, financialYear, modal);
    });

    // Close on outside click
    modal.addEventListener('click', (e) => {
        if (e.target === modal) {
            modal.remove();
        }
    });
}



/**
 * View imported debtors
 * @param {Array|null} debtors - Array of debtor objects (can be null to fetch)
 * @param {number} importedCount - Explicit count of imported records (to avoid undefined)
 */
async function viewImportedDebtors(debtors, importedCount) {
    const fySelect = document.getElementById('debtorFinancialYear');
    const financialYear = fySelect.value;

    if (!financialYear) {
        showNotification('Please select a financial year first', 'error');
        fySelect.focus();
        return;
    }

    try {
        showLoadingIndicator();

        // FIX: Try to fetch debtors if not passed
        if (!debtors) {
            const response = await apiCall(
                `debtors.php?financial_year=${encodeURIComponent(financialYear)}`);
            if (response.success && response.data) {
                debtors = response.data;
                // FIX: Update count to actual data length
                importedCount = debtors.length;
            }
        }

        if (debtors && debtors.length > 0) {
            // Calculate total amount safely
            let totalAmount = 0;
            debtors.forEach(d => {
                let amount = 0;
                if (typeof d.amount === 'number') {
                    amount = d.amount;
                } else if (typeof d.amount === 'string') {
                    const cleanAmount = d.amount.replace(/[^0-9.\-]/g, '').replace(/,/g, '');
                    amount = parseFloat(cleanAmount);
                }
                if (!isNaN(amount)) totalAmount += Math.abs(amount);
            });

            const modal = document.createElement('div');
            modal.className = 'modal';
            modal.id = 'viewDebtorsModal';

            modal.innerHTML = `
            <div class="modal-content" style="max-width: 900px;">
                <div class="modal-header">
                    <h3><i data-lucide="bar-chart-3" class="icon-sm"></i> Imported Debtors - FY ${escapeHtml(financialYear)}</h3>
                    <span class="close-btn" id="closeViewDebtorsModal">&times;</span>
                </div>
                <div class="modal-body">
                    <div class="debtor-stats">
                        <div class="debtor-stat-card info">
                            <div class="debtor-stat-number">${debtors.length}</div>
                            <div class="debtor-stat-label">Total Debtors</div>
                        </div>
                        <div class="debtor-stat-card danger">
                            <div class="debtor-stat-number">KES ${formatCurrency(totalAmount)}</div>
                            <div class="debtor-stat-label">Total Outstanding</div>
                        </div>
                    </div>

                    <div class="debtor-preview-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Debtor Name</th>
                                    <th style="text-align: right;">Outstanding Amount (KES)</th>
                                    <th>Import Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${debtors.map((debtor, index) => `
                                <tr>
                                    <td>${index + 1}</td>
                                    <td>${escapeHtml(debtor.name)}</td>
                                    <td style="text-align: right; font-weight: 600;">KES ${formatCurrency(debtor.amount)}</td>
                                    <td>${formatDate(debtor.created_at)}</td>
                                </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button id="exportDebtorsBtn" class="action-btn"><i data-lucide="download" class="icon-sm"></i> Export to CSV</button>
                    <button id="closeViewDebtorsBtn" class="action-btn reject-btn">Close</button>
                </div>
            </div>
        `;

            document.body.appendChild(modal);
            modal.style.display = 'flex';

            // Event Listeners
            document.getElementById('closeViewDebtorsModal').addEventListener('click', () => {
                modal.remove();
            });

            document.getElementById('closeViewDebtorsBtn').addEventListener('click', () => {
                modal.remove();
            });

            // FIX: Use actual debtors length
            document.getElementById('exportDebtorsBtn').addEventListener('click', () => {
                exportDebtorsToCSV(debtors, financialYear, debtors.length);
            });

        } else {
            showNotification('No debtors found for this financial year', 'info');
        }

    } catch (error) {
        console.error('Error loading debtors:', error);
        showNotification('Error loading debtors: ' + error.message, 'error');
    } finally {
        hideLoadingIndicator();
    }
}
let csrfTokenRefreshTimer = null;

/**
 * Refresh CSRF token from server
 */
async function refreshCSRFToken() {
    try {
        const result = await fetch(`${API_BASE_URL}/auth.php`, {
            method: 'GET',
            credentials: 'include'
        });

        const data = await result.json();

        if (data.success && data.data && data.data.csrf_token) {
            APP_STATE.csrfToken = data.data.csrf_token;

            // Update DOM token if exists
            const csrfInput = document.getElementById('csrf-token-data');
            if (csrfInput) {
                csrfInput.dataset.csrfToken = data.data.csrf_token;
            }

            //console.log('CSRF token refreshed');
        }
    } catch (error) {
        //console.error('Failed to refresh CSRF token:', error);
    }
}

/**
 * Start CSRF token refresh timer
 */
function startCSRFTokenRefresh() {
    if (csrfTokenRefreshTimer) {
        clearInterval(csrfTokenRefreshTimer);
    }

    csrfTokenRefreshTimer = setInterval(refreshCSRFToken, 30 * 60 * 1000);
}

/**
 * Export debtors to CSV
 * @param {Array} debtors - Array of debtor objects
 * @param {string} financialYear - Financial year string
 * @param {number} importedCount - Explicit count of imported records (to avoid undefined)
 */
function exportDebtorsToCSV(debtors, financialYear, importedCount) {
    if (!hasPermission('canExportData')) {
        showNotification('You do not have permission to export data.', 'error');
        return;
    }

    try {
        const headers = [
            '#',
            'Name',
            'Outstanding Amount(KES)'
        ];

        const data = debtors.map((debtor, index) => {
            const amount = typeof debtor.amount === 'number' ? debtor.amount : parseFloat(debtor
                .amount
                .replace(/,/g, ''));
            return [
                index + 1,
                debtor.name,
                formatCurrency(amount) // formatCurrency handles undefined/0 gracefully
            ];
        });

        const csv = convertToCSV(data, headers);
        // FIX: Use importedCount in filename
        const filename =
            `Debtors_FY_${financialYear.replace('/', '-')}_${new Date().toISOString().split('T')[0]}_(${importedCount} Records).csv`;
        downloadCSV(filename, csv);
    } catch (error) {
        showNotification('Error exporting debtors: ' + error.message, 'error');
    }
}


// ==========================================
// SECTION VISIBILITY MANAGEMENT
// ==========================================

/**
 * Hide all sections to ensure clean switching
 */
function hideAllSections() {
    // Hide all main sections
    const sections = [
        'loansSection',
        'debtorsSection',
        'reconciliationContent',
        'reportsContent'
    ];

    sections.forEach(sectionId => {
        const section = document.getElementById(sectionId);
        if (section) {
            section.style.display = 'none';
            section.classList.add('hidden');
        }
    });

    // Hide main content and loans table
    const mainContent = document.getElementById('mainContent');
    if (mainContent) {
        mainContent.style.display = 'none';
    }

    const loansTable = document.getElementById('loansTable');
    if (loansTable) {
        loansTable.style.display = 'none';
    }
}

/**
 * Show debtors section with proper visibility management
 */
function showDebtorsSection() {
    //console.log('Showing debtors section...');

    // Hide all other sections first
    hideAllSections();

    // Show debtors section
    const debtorsSection = document.getElementById('debtorsSection');
    if (debtorsSection) {
        debtorsSection.style.display = 'block';
        debtorsSection.classList.remove('hidden');
        //console.log('Debtors section displayed');
    } else {
        //console.error('Debtors section not found!');
    }

    // Update page title
    const sectionTitle = document.getElementById('sectionTitle');
    if (sectionTitle) {
        sectionTitle.textContent = '<i data-lucide="bar-chart-3" class="icon-sm"></i> Import Existing Debtors';
    }

    // Update active sidebar button
    updateSidebarActive('debtors');

    // Initialize debtor import if not already done
    if (typeof initializeDebtorImport === 'function') {
        initializeDebtorImport();
    }
}

// ==========================================
// MIGRATE OUTSTANDING DEBTORS
// ==========================================

/**
 * Initialize migration button listener
 */
function initializeMigrationButton() {
    const migrateBtn = document.getElementById('migrateDebtorsBtn').addEventListener('click',
        async function() {
            if (!confirm(
                    'Are you sure you want to move all outstanding debtors to the main Loan Applications table? This action cannot be undone.'
                )) {
                return;
            }

            showLoadingIndicator();

            try {
                const response = await apiCall('migrate_outstanding_to_loans.php', 'POST', {
                    csrf_token: APP_STATE.csrfToken
                });

                if (response.success) {
                    // Safely format the amount with fallback
                    const totalAmount = response.data && response.data.total_amount ?
                        formatCurrency(response.data.total_amount) :
                        '0';

                    const imported = response.data && response.data.imported ?
                        response.data.imported :
                        0;

                    showNotification(
                        `Success! Migrated ${imported} record(s) worth KES ${totalAmount}.`,
                        'success', 5000
                    );

                    // Refresh the main Loans table
                    updateSidebarActive('all');
                    await renderLoans('all');

                } else {
                    throw new Error(response.message || 'Migration failed');
                }

            } catch (error) {
                //console.error('Migration error:', error);
                showNotification('Migration failed: ' + error.message, 'error');
            } finally {
                hideLoadingIndicator();
            }
        });
}


/**
 * Show loans section with proper table wrapping (Updated)
 * @param {string} filter - Filter to apply (all, active, etc.)
 */
function showLoansSection(filter) {
    //console.log('Showing loans section with filter:', filter);

    // Hide all other sections first
    hideAllSections();

    // Show loans section
    const loansSection = document.getElementById('loansSection');
    if (loansSection) {
        loansSection.style.display = 'block';
        loansSection.classList.remove('hidden');
    }

    const mainContent = document.getElementById('mainContent');
    if (mainContent) {
        mainContent.style.display = 'block';

        // Ensure table is wrapped in responsive container
        const loansTable = document.getElementById('loansTable');
        if (loansTable && !loansTable.parentElement.classList.contains('table-responsive')) {
            const wrapper = document.createElement('div');
            wrapper.className = 'table-responsive';
            loansTable.parentNode.insertBefore(wrapper, loansTable);
            wrapper.appendChild(loansTable);
        }

        // Show table
        if (loansTable) {
            loansTable.style.display = 'table';
        }
    }

    // Update sidebar active state
    updateSidebarActive(filter || 'all');

    // Render loans with specified filter
    // If 'all' is passed, renderLoans fetches everything including new imports
    if (typeof renderLoans === 'function') {
        renderLoans(filter || 'all');
    }
}

/**
 * Update sidebar active button
 */
function updateSidebarActive(filter) {
    // Remove active class from all sidebar buttons
    const sidebarButtons = document.querySelectorAll('#sidebar button:not(#logoutBtn)');
    sidebarButtons.forEach(btn => {
        btn.classList.remove('active');
    });

    // Add active class to the matching button
    const activeButton = document.querySelector(`#sidebar button[data-filter="${filter}"]`);
    if (activeButton) {
        activeButton.classList.add('active');
    }
}


/**
 * Ensure proper table structure on page load
 */
function ensureTableStructure() {
    const loansTable = document.getElementById('loansTable');

    if (loansTable) {
        // Check if table is already wrapped
        if (!loansTable.parentElement.classList.contains('table-responsive')) {
            const wrapper = document.createElement('div');
            wrapper.className = 'table-responsive';
            loansTable.parentNode.insertBefore(wrapper, loansTable);
            wrapper.appendChild(loansTable);
            //console.log('Table wrapped in responsive container');
        }
    }
}

function adjustMainContentSpacing() {
    const main = document.querySelector('main');
    const sidebar = document.getElementById('sidebar');

    if (!main || !sidebar) return;

    const updateSpacing = () => {
        if (window.innerWidth > 768) {
            if (sidebar.classList.contains('collapsed')) {
                main.classList.add('sidebar-collapsed');
                main.classList.remove('sidebar-open');
            } else if (sidebar.classList.contains('open')) {
                main.classList.add('sidebar-open');
                main.classList.remove('sidebar-collapsed');
            }
        } else {
            main.classList.remove('sidebar-open', 'sidebar-collapsed');
        }
    };

    // Run on load
    updateSpacing();

    // Run on sidebar toggle
    const observer = new MutationObserver(updateSpacing);
    observer.observe(sidebar, {
        attributes: true,
        attributeFilter: ['class']
    });

    // FIXED: Use 'main' instead of the undefined DOM_ELEMENTS.portalSection
    main.classList.remove('sidebar-collapsed');
}

// ==========================================
// FIX: Force close all modals on page load
// ==========================================
document.addEventListener('DOMContentLoaded', function() {
    // Select all modals
    const modals = document.querySelectorAll('.modal');

    // Explicitly hide them
    modals.forEach(modal => {
        modal.style.display = 'none';
    });

    // Log check for debugging
    // //console.log('Safety check: Closed ' + modals.length + ' modals.');
});

// ==========================================
// FIX: Remove any dark overlays on page load
// ==========================================
document.addEventListener('DOMContentLoaded', function() {
    // Remove any lingering overlays
    const overlays = document.querySelectorAll('[id*="overlay"]');
    overlays.forEach(overlay => overlay.remove());

    // Ensure all modals are closed
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        modal.style.display = 'none';
    });

    // Remove loading states
    const loadingElements = document.querySelectorAll('[id*="loading"], [id*="Loading"]');
    loadingElements.forEach(el => {
        el.style.display = 'none';
    });
});
