<?php
// js/loans.js.php
session_start();

// Check if user is authenticated
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    http_response_code(403);
    exit('Access denied');
}

// Set proper content type
header('Content-Type: application/javascript');

// Close PHP tag before outputting JavaScript
?>

class LoanAPI {
constructor(apiKey) {
this.apiKey = apiKey;
this.baseUrl = 'api/loans.php';
}

async fetchMyLoans() {
try {
const response = await fetch(this.baseUrl, {
method: 'GET',
headers: {
'X-API-Key': this.apiKey
},
credentials: 'include'
});

if (!response.ok) {
throw new Error(`HTTP ${response.status}`);
}

const data = await response.json();

if (data.success) {
console.log('My loans:', data.data);
console.log('Statistics:', data.stats); // For regular users
return data;
} else {
console.error('Error:', data.message);
return null;
}
} catch (error) {
console.error('Failed to fetch loans:', error);
return null;
}
}

async fetchSingleLoan(applicationId) {
try {
const response = await fetch(`${this.baseUrl}?id=${applicationId}`, {
method: 'GET',
headers: {
'X-API-Key': this.apiKey
},
credentials: 'include'
});

if (!response.ok) {
throw new Error(`HTTP ${response.status}`);
}

const data = await response.json();
return data;
} catch (error) {
console.error('Failed to fetch loan:', error);
return null;
}
}

async createLoan(loanData, csrfToken) {
try {
const response = await fetch(this.baseUrl, {
method: 'POST',
headers: {
'Content-Type': 'application/json',
'X-API-Key': this.apiKey
},
credentials: 'include',
body: JSON.stringify({
...loanData,
csrf_token: csrfToken
})
});

const data = await response.json();
return data;
} catch (error) {
console.error('Failed to create loan:', error);
return { success: false, message: error.message };
}
}

async updateLoanStatus(applicationId, status, feedback, csrfToken) {
try {
const response = await fetch(this.baseUrl, {
method: 'PUT',
headers: {
'Content-Type': 'application/json',
'X-API-Key': this.apiKey
},
credentials: 'include',
body: JSON.stringify({
application_id: applicationId,
status: status,
feedback: feedback,
csrf_token: csrfToken
})
});

const data = await response.json();
return data;
} catch (error) {
console.error('Failed to update loan:', error);
return { success: false, message: error.message };
}
}

async deleteLoan(applicationId, csrfToken) {
try {
const response = await fetch(this.baseUrl, {
method: 'DELETE',
headers: {
'Content-Type': 'application/json',
'X-API-Key': this.apiKey
},
credentials: 'include',
body: JSON.stringify({
application_id: applicationId,
csrf_token: csrfToken
})
});

const data = await response.json();
return data;
} catch (error) {
console.error('Failed to delete loan:', error);
return { success: false, message: error.message };
}
}
}

// Usage example — API key injected securely from PHP session
const loanAPI = new LoanAPI('<?= htmlspecialchars($_SESSION['api_key'] ?? '') ?>');

// Fetch current user's loans
async function displayMyLoans() {
const result = await loanAPI.fetchMyLoans();

if (result && result.success) {
const loans = result.data;
const stats = result.stats; // Available for regular users

console.log(`You have ${loans.length} loan applications`);

if (stats) {
console.log(`Pending: ${stats.pending}`);
console.log(`Approved: ${stats.approved}`);
console.log(`Total approved amount: ${stats.total_approved_amount}`);
}

// Display loans in your UI
displayLoansInTable(loans);
}
}

/**
 * Renders loan applications into the #loanHistoryGrid container,
 * and updates #latestLoan / #noLoan on the dashboard section.
 * Matches the card structure used in loanee.js.
 * @param {Array} loans - Array of loan objects from the API
 */
function displayLoansInTable(loans) {
    const container = document.getElementById('loanHistoryGrid');
    const latestLoan = document.getElementById('latestLoan');
    const noLoan = document.getElementById('noLoan');

    // ── Helpers ──────────────────────────────────────────────
    const fmtCurrency = (n) =>
        Number(n || 0).toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    const fmtDate = (d) => {
        if (!d) return 'N/A';
        const dt = new Date(d);
        return isNaN(dt) ? d : dt.toLocaleDateString('en-KE', { year: 'numeric', month: 'short', day: 'numeric' });
    };

    const statusStyle = (status) => {
        switch ((status || '').toLowerCase()) {
            case 'closed':
            case 'dispatched':
            case 'active':
                return 'background:#d4edda; color:#155724';
            case 'rejected':
                return 'background:#f8d7da; color:#721c24';
            case 'returned':
                return 'background:#ffeaa7; color:#856404';
            default:
                return 'background:#fff3cd; color:#856404';
        }
    };

    const capitalize = (s) => s ? s.charAt(0).toUpperCase() + s.slice(1) : 'N/A';

    // ── Build card HTML for one loan ─────────────────────────
    const buildCard = (loan) => {
        const status    = String(loan.status || 'pending').toLowerCase();
        const loanType  = capitalize(loan.loan_type);
        const loanAmt   = parseFloat(loan.loan_amount || 0);

        // Type-specific details
        let typeDetails = '';
        if (loan.loan_type === 'mortgage') {
            typeDetails = `
            <div class="detail-row">
                <span class="detail-label">Property Location:</span>
                <span class="detail-value">${loan.property_location || 'N/A'}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Property Value:</span>
                <span class="detail-value">KES ${fmtCurrency(loan.property_value || 0)}</span>
            </div>`;
        } else if (loan.loan_type === 'car') {
            typeDetails = `
            <div class="detail-row">
                <span class="detail-label">Car Type:</span>
                <span class="detail-value">${loan.car_type || 'N/A'}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Car Value:</span>
                <span class="detail-value">KES ${fmtCurrency(loan.car_value || 0)}</span>
            </div>`;
        }

        // Employment details (contracted only)
        let empDetails = '';
        if (loan.employment_type === 'contracted') {
            empDetails = `
            <div class="detail-section">
                <h4 class="section-title">Employment Details</h4>
                <div class="detail-row">
                    <span class="detail-label">Contract Period:</span>
                    <span class="detail-value">${loan.contract_period || 'N/A'} months</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Contract Start:</span>
                    <span class="detail-value">${fmtDate(loan.contract_start_date)}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Contract End:</span>
                    <span class="detail-value">${fmtDate(loan.contract_end_date)}</span>
                </div>
            </div>`;
        }

        // Feedback block
        const feedbackBlock = loan.feedback ? `
            <div class="detail-section">
                <h4 class="section-title">Feedback</h4>
                <p style="color:#555; margin:0;">${loan.feedback}</p>
            </div>` : '';

        return `
        <div class="loan-history-card">
            <div class="loan-card-header">
                <div class="loan-header-left">
                    <h3 class="loan-title">${loanType} Loan</h3>
                    <p class="loan-id">ID: ${loan.application_id || 'N/A'}</p>
                </div>
                <div class="loan-header-right">
                    <span class="status-badge" style="${statusStyle(status)}">
                        ${capitalize(loan.status)}
                    </span>
                </div>
            </div>
            <div class="loan-card-body">
                <div class="detail-section">
                    <h4 class="section-title">Loan Information</h4>
                    <div class="detail-row">
                        <span class="detail-label">Application Date:</span>
                        <span class="detail-value">${fmtDate(loan.application_date)}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Loan Amount:</span>
                        <span class="detail-value amount-highlight">KES ${fmtCurrency(loanAmt)}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Payment Duration:</span>
                        <span class="detail-value">${loan.payment_duration || 'N/A'} years</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Payment Frequency:</span>
                        <span class="detail-value">${capitalize(loan.payment_frequency || 'monthly')}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Basic Salary:</span>
                        <span class="detail-value">KES ${fmtCurrency(loan.basic_salary || 0)}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Employment Type:</span>
                        <span class="detail-value">${capitalize(loan.employment_type)}</span>
                    </div>
                    ${typeDetails}
                </div>

                ${empDetails}

                <div class="detail-section">
                    <h4 class="section-title">Banking Details</h4>
                    <div class="detail-row">
                        <span class="detail-label">Bank Name:</span>
                        <span class="detail-value">${loan.bank_name || 'N/A'}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Bank Branch:</span>
                        <span class="detail-value">${loan.bank_branch || 'N/A'}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Account Number:</span>
                        <span class="detail-value">${loan.bank_account_number || 'N/A'}</span>
                    </div>
                </div>

                ${feedbackBlock}
            </div>
        </div>`;
    };

    // ── Render history grid ───────────────────────────────────
    if (container) {
        if (!loans || loans.length === 0) {
            container.innerHTML = `
            <div class="empty-state" style="text-align:center; padding:40px; background:#fff; border-radius:12px; box-shadow:0 2px 10px rgba(0,0,0,0.05);">
                <h3 style="color:#6c757d; margin-bottom:10px;">No Loan Applications Yet</h3>
                <p style="color:#999;">Your loan application history will appear here once you apply.</p>
            </div>`;
        } else {
            const sorted = [...loans].sort((a, b) => new Date(b.application_date) - new Date(a.application_date));
            container.innerHTML = `<div class="loan-history-grid">${sorted.map(buildCard).join('')}</div>`;
        }
    }

    // ── Update dashboard latest-loan widget ───────────────────
    if (latestLoan && noLoan) {
        if (!loans || loans.length === 0) {
            latestLoan.classList.add('hidden');
            noLoan.classList.remove('hidden');
        } else {
            const latest = [...loans].sort((a, b) => new Date(b.application_date) - new Date(a.application_date))[0];
            latestLoan.innerHTML = buildCard(latest);
            latestLoan.classList.remove('hidden');
            noLoan.classList.add('hidden');
        }
    }
}