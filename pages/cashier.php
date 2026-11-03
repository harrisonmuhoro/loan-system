<?php
// Start session to check if user is already logged in
session_start();

// Handle return from loanee portal
if (isset($_GET['returned_from_loanee']) && $_GET['returned_from_loanee'] == 1) {
    // Clear loanee flags
    unset($_SESSION['switching_to_loanee']);
    unset($_SESSION['can_apply_for_loan']);
    unset($_SESSION['viewing_as_loanee']);
    unset($_SESSION['from_staff_portal']);
    unset($_SESSION['original_portal']);
    unset($_SESSION['staff_original_role']);
    // Ensure role is cashier
    $_SESSION['user_role'] = 'cashier';
    // Redirect to clean URL
    header("Location: cashier.php");
    exit();
}

// Check if user is already logged in from the main system
$loggedInFromMain = false;
$userData = [];

// Check for both possible session variable names to handle different login systems
if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'cashier') {
    $loggedInFromMain = true;

    // Normalize session variables to ensure consistency
    if (isset($_SESSION['user_role']) && !isset($_SESSION['role'])) {
        $_SESSION['role'] = $_SESSION['user_role'];
    }

    // Set the authentication flag if it doesn't exist
    if (!isset($_SESSION['authenticated'])) {
        $_SESSION['authenticated'] = true;
    }

    $userData = [
        'pfNumber' => $_SESSION['pf_number'] ?? '',
        'fullName' => $_SESSION['full_name'] ?? '',
        'email'    => $_SESSION['email'] ?? '',
        'role'     => $_SESSION['user_role'] ?? '',
    ];

    // Define cashier permissions based on role
    $cashierPermissions = [
        'canViewLoans'        => true,
        'canRecordPayments'   => true,
        'canViewReports'      => true,
        'canExportData'       => true,
        'canForwardLoans'     => true,
        'canRejectLoans'      => true,
        'canCloseLoans'       => true,
        'canApplyForLoan'     => true,
        'cannotDeleteData'    => true,
        'cannotManageStaff'   => true,
        'cannotManageSystem'  => true,
    ];

    // Store permissions in session for JavaScript access
    $_SESSION['cashier_permissions'] = $cashierPermissions;
}

// If not logged in from main system, redirect to login page
if (!$loggedInFromMain) {
    header("Location: ../index.php");
    exit();
}

// Get system settings (interest rate)
$interest_rate = 3.0;
try {
    if (!defined('BASE_PATH')) {
        define('BASE_PATH', __DIR__ . '/');
    }
    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../config/database.php';
    $database = new Database();
    $conn     = $database->getConnection();
    $query    = "SELECT value FROM system_settings WHERE setting_key = 'amortization_rate'";
    $stmt     = $conn->prepare($query);
    $stmt->execute();
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $interest_rate = floatval($row['value']);
    }
} catch (Exception $e) {
    error_log("Error getting interest rate: " . $e->getMessage());
}

// Apply for loan function
function applyForLoan() {
    $_SESSION['original_portal']      = 'cashier';
    $_SESSION['viewing_as_loanee']    = true;
    $_SESSION['from_staff_portal']    = true;
    $_SESSION['staff_original_role']  = $_SESSION['user_role'];
    $_SESSION['switching_to_loanee']  = true;
    error_log("Cashier applying for loan: " . print_r([
        'user_id'   => $_SESSION['user_id'],
        'pf_number' => $_SESSION['pf_number'],
        'role'      => $_SESSION['user_role']
    ], true));
    header("Location: loanee.php?from=cashier&action=switch_portal");
    exit();
}

// Handle the apply for loan action
if (isset($_GET['action']) && $_GET['action'] === 'apply_for_loan') {
    applyForLoan();
}

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Check authentication
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'cashier') {
    error_log('Unauthorized access attempt to cashier.php. Session: ' . print_r($_SESSION, true));
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en"><head>    
<meta charset="UTF-8" />    
<meta name="viewport" content="width=device-width, initial-scale=1" />    
<title>Cashier Portal</title>    
<link rel="stylesheet" href="../css/cashier.css?v=20260930-1" />    
<script src="../js/logout-handler.js"></script>
    
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    
<script src="../js/lucide.min.js"></script>

</head>

<body>    
<!-- CSRF Token -->    <input type="hidden" id="csrf-token-data"        data-csrf-token="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>
">    
<!-- Header -->    
<header>        
<div class="header-container">            
<!-- Left side with hamburger menu -->            <button class="hamburger" id="hamburgerMenu">                <span></span>                <span></span>                <span></span>            </button>        </div>
        
<!-- Centered Logo and Title Container -->        
<div class="logo-title-container">            <img src="../logo.jpg" alt="Company Logo" class="logo">            <h1>Cashier Portal</h1>        </div>
        
<!-- Right side with controls -->        
<div class="header-controls">            
<!-- Theme Selector -->            
<div class="theme-selector" id="themeSelector">
                <button class="theme-option active" data-theme="light" aria-label="Light theme" title="Light theme">
                    <i data-lucide="sun" class="icon-sm"></i>
                </button>
                <button class="theme-option" data-theme="dark" aria-label="Dark theme" title="Dark theme">
                    <i data-lucide="moon" class="icon-sm"></i>
                </button>
            </div>
            
<!-- Add cashier profile badge -->            
<div class="profile-badge" id="profileBadge">                
<div class="profile-info">                    
<div class="profile-avatar">                        <img src="https://picsum.photos/seed/cashier123/40/40.jpg" alt="Profile" class="avatar-img">                    </div>
                    
<div class="profile-details">                        
<div class="profile-name"><?php echo htmlspecialchars($userData['fullName']); ?>
</div>
                        
<div class="profile-role"><?php echo ucfirst(htmlspecialchars($userData['role'])); ?>
</div>
                    </div>
                </div>
                <div class="profile-dropdown" id="profileDropdown">
                    <div class="profile-dropdown-item danger" id="profileLogoutBtn">
                        <i data-lucide="log-out" class="icon-sm"></i> Logout
                    </div>
                </div>
            </div>
            
<!-- Notification Bell -->            
<div class="notification-bell-container">                <button id="notificationBell" class="notification-bell" onclick="toggleNotificationDropdown()">                    <i data-lucide="bell" class="icon-sm"></i>                    <span id="notificationBadge" class="notification-badge" style="display: none;">0</span>                </button>                
<!-- Notification Dropdown -->                
<div id="notificationDropdown" class="notification-dropdown" style="display: none;">                    
<div class="notification-header">                        <h3>Notifications</h3>                        <button onclick="markAllAsRead()" class="mark-all-read-btn">Mark all as read</button>                    </div>
                    
<div id="notificationList" class="notification-list">                        
<div class="notification-empty">No new notifications</div>
                    </div>
                    
<div class="notification-footer">                        <a href="#" onclick="viewAllNotifications(); return false;">View All</a>                    </div>
                </div>
            </div>
        </div>
    </header>
    
<!-- Sidebar -->    
<aside id="sidebar">        
<!-- Sidebar Logo -->        
<div class="sidebar-logo">            <img src="../logo.jpg" alt="Company Logo">            
<div class="sidebar-logo-text">Cashier Portal</div>
        </div>
        
<!-- sidebar buttons -->        <button class="active tooltip" data-filter="all">            <span class="button-icon"><i data-lucide="clipboard-list" class="icon-sm"></i></span>            <span class="button-text">All Loans</span>        </button>        <button class="tooltip" data-filter="pending">            <span class="button-icon"><i data-lucide="hourglass" class="icon-sm"></i></span>            <span class="button-text">Pending Loans</span>        </button>        <button class="tooltip" data-filter="forwarded">            <span class="button-icon"><i data-lucide="arrow-right" class="icon-sm"></i></span>            <span class="button-text">Forwarded to Accountant</span>        </button>        <button class="tooltip" data-filter="accepted">            <span class="button-icon"><i data-lucide="check-circle" class="icon-sm"></i></span>            <span class="button-text">Accepted Loans</span>        </button>        <button class="tooltip" data-filter="dispatched">            <span class="button-icon"><i data-lucide="package" class="icon-sm"></i></span>            <span class="button-text">Dispatched Loans</span>        </button>        <button class="tooltip" data-filter="active">            <span class="button-icon"><i data-lucide="coins" class="icon-sm"></i></span>            <span class="button-text">Active Loans</span>        </button>        <button class="tooltip" data-filter="rejected">            <span class="button-icon"><i data-lucide="x-circle" class="icon-sm"></i></span>            <span class="button-text">Rejected Loans</span>        </button>        <button class="tooltip" data-filter="returned">            <span class="button-icon"><i data-lucide="corner-up-left" class="icon-sm"></i></span>            <span class="button-text">Returned by Accountant</span>        </button>        <button class="tooltip" data-filter="closed">            <span class="button-icon"><i data-lucide="lock" class="icon-sm"></i></span>            <span class="button-text">Closed Loans</span>        </button>        <button class="tooltip" data-filter="reconciliation">            <span class="button-icon"><i data-lucide="coins" class="icon-sm"></i></span>            <span class="button-text">Reconciliation</span>        </button>        
<!-- NEW: Import Debtors section -->        <button class="tooltip" data-filter="debtors">            <span class="button-icon"><i data-lucide="folder" class="icon-sm"></i></span>            <span class="button-text">Import Debtors</span>            <span class="tooltiptext">Import Existing Debtors</span>        </button>        <button class="tooltip" data-filter="reports">            <span class="button-icon"><i data-lucide="bar-chart-3" class="icon-sm"></i></span>            <span class="button-text">Financial Reports</span>        </button>        <button class="tooltip" onclick="applyForLoan()">            <span class="button-icon"><i data-lucide="file-edit" class="icon-sm"></i></span>            <span class="button-text">Apply for Loan</span>        </button>        <button class="tooltip" id="logoutBtn" style="margin-top: auto; background: #c0392b;">            <span class="button-icon"><i data-lucide="log-out" class="icon-sm"></i></span>            <span class="button-text">Logout</span>            <span class="tooltiptext">Logout</span>        </button>    </aside>
    
<!-- Main Content -->    
<main>        
<section id="loansSection">            <h2 id="sectionTitle">All Loans</h2>            
<div class="filter-bar">                
<div class="filter-item search-wrapper">                    <span class="search-icon"><i data-lucide="search" class="icon-sm"></i></span>                    <input type="search" id="loanSearch" placeholder="Search by name, ID, email, or PF"                        class="filter-input">                </div>
                
<div class="filter-item">                    <label for="statusFilter" class="filter-label">Status:</label>                    <select id="statusFilter" class="filter-select">                        <option value="All">All</option>                        <option value="active">Active</option>                        <option value="dispatched">Dispatched</option>                        <option value="closed">Closed</option>                        <option value="pending">Pending</option>                        <option value="forwarded">Forwarded</option>                        <option value="accepted">Accepted</option>                        <option value="rejected">Rejected</option>                        <option value="returned">Returned</option>                    </select>                </div>
                
<div class="filter-item">                    <label for="loanTypeFilter" class="filter-label">Loan Type:</label>                    <select id="loanTypeFilter" class="filter-select">                        <option value="All">All</option>                        <option value="Car">Car</option>                        <option value="mortgage">Mortgage</option>                    </select>                </div>
                <button id="filterLoansBtn" class="filter-btn"><i data-lucide="edit-2" class="icon-sm"></i> Filter</button>                <button id="clearLoansFilter" class="clear-btn"><i data-lucide="x-circle" class="icon-sm"></i> Clear</button>                <button id="refreshLoans" class="refresh-btn"><i data-lucide="refresh-cw" class="icon-sm"></i> Refresh</button>            </div>
            
<div id="mainContent">
<div class="table-responsive">
<table id="loansTable">
<thead>                        
<tr>                            <th>Full Name</th>                            <th>ID Number</th>                            <th>PF Number</th>                            <th>Loan Type</th>                            <th>Details</th>                            <th>Basic Salary</th>                            <th>Loan Amount</th>                            <th>Current Balance</th>                            <th>Amortization</th>                            <th>Status</th>                            <th>Feedback</th>                            <th>Actions</th>                        </tr>
                    </thead>
                    
<tbody></tbody>
                </table>
            </div>
            </div>
        </section>
        
<!-- Import Debtors Section - Place this after loansSection and before notifications -->        
<section id="debtorsSection" class="hidden">            
<div style="padding: 20px;">                
    <h2 style="color: #4a6b4a; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">                    <i data-lucide="bar-chart-3" class="icon-sm"></i> Import Existing Debtors                </h2>                
<div class="account-card">                    
    <h3 style="margin-top: 0; color: #2e7d32;"><i data-lucide="bar-chart-3" class="icon-sm"></i> Import Existing Debtors</h3>                    <p class="small" style="color: var(--text-secondary); margin-bottom: 15px;">                        Import outstanding debtors from previous financial year. Supports CSV, Excel (XLS/XLSX) files.                        The file should contain: Name and Outstanding Amount columns.                    </p>                    
<div class="row mb-2">                        
    <label style="font-weight: 600; color: var(--text-primary);">                            Financial Year:                            <select id="debtorFinancialYear" required style="margin-top: 5px;">                                <option value="">Select Year</option>                            </select>                        </label>                    </div>
                    
<div class="row mb-2">                    
        <button id="importDebtorsBtn" class="btn-warning">                            
            <i data-lucide="download" class="icon-sm"></i> Import Debtors                       
         </button>                        <button id="downloadDebtorTemplateBtn" class="btn-outline">                            <i data-lucide="file-text" class="icon-sm"></i> Download Template                        </button>                        <button id="migrateDebtorsBtn" class="btn-info">                            <i data-lucide="refresh-cw" class="icon-sm"></i> Migrate Outstanding to Loans                        </button>                        <button id="viewImportedDebtorsBtn" class="btn-info">                            <i data-lucide="eye" class="icon-sm"></i> View Imported Debtors                        </button>                    </div>
                    <input type="file" id="importDebtorFileInput" accept=".csv,.xlsx,.xls" style="display: none;">                </div>
                
<!-- Debtor import content will be displayed here -->                
<div id="debtorsContent"></div>
            </div>
        </section>
    </main>

    <!-- Notification Container -->
    <div id="notification" class="notification"></div>

    <!-- Loan Details Modal -->
    <div id="detailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i data-lucide="file-text" class="icon-sm"></i> Loan Details</h2>
                <span class="close-btn" id="closeDetailsModal">&times;</span>
            </div>
            <div class="modal-body">
                <div id="loanDetailsContent"></div>
            </div>
        </div>
    </div>

    <!-- Feedback Modal -->
    <div id="feedbackModal" class="modal">
        <div class="modal-content" style="max-width:500px;">
            <div class="modal-header">
                <h2 id="feedbackTitle">Provide Feedback</h2>
                <span class="close-btn" id="cancelFeedback">&times;</span>
            </div>
            <div class="modal-body">
                <textarea id="feedbackTextarea"
                    style="width:100%; min-height:120px; padding:12px; border:2px solid #e0e0e0; border-radius:6px; resize:vertical;"
                    placeholder="Enter your feedback or reason here..."></textarea>
                <div style="text-align:right; margin-top:20px;">
                    <button id="submitFeedback" class="action-btn" style="padding: 10px 20px;">
                        <i data-lucide="check-circle" class="icon-sm"></i> Submit
                    </button>
                    <button id="cancelFeedbackButton" class="action-btn reject-btn" style="padding: 10px 20px;">
                        <i data-lucide="x-circle" class="icon-sm"></i> Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Loan Type Details Modal (matches accountant style) -->
    <div id="loanTypeModal" class="modal">
        <div class="modal-content" style="max-width:600px;">
            <div class="modal-header">
                <h2><i data-lucide="file-text" class="icon-sm"></i> Loan Type Details</h2>
                <span class="close-btn" id="closeLoanTypeModal">&times;</span>
            </div>
            <div class="modal-body">
                <div id="loanTypeContent"></div>
            </div>
        </div>
    </div>

    <div id="amortModal" class="modal">        
<div class="modal-content" style="max-width:1200px;">            
<div class="modal-header">                <h2><i data-lucide="bar-chart-3" class="icon-sm"></i> Payment Schedule &amp; Amortization</h2>                <span class="close-btn" id="closeAmortModal">&times;</span>            </div>
            
<div class="modal-body">                
<div id="amortInfo" class="balance-highlight"></div>
                
<div style="overflow-x: auto;">                    
<table style="width:100%; border-collapse:collapse; font-size:0.85rem; min-width:800px;">                        
<thead>                            
<tr>                                <th>#</th>                                <th>Due Date</th>                                <th>Expected Payment</th>                                <th>Actual Payment</th>                                <th>Expected Interest</th>                                <th>Actual Interest</th>                                <th>Principal</th>                                <th>Balance</th>                                <th>Status</th>                            </tr>
                        </thead>
                        <tbody id="amortTableBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
<!-- Detailed Reconciliation Modal -->    
<div id="detailedReconciliationModal" class="modal">        
<div class="modal-content" style="max-width:1200px;">            
<div class="modal-header">                <h2><i data-lucide="bar-chart-3" class="icon-sm"></i> Detailed Payment Reconciliation</h2>                <span class="close-btn" id="closeDetailedReconciliationModal">&times;</span>            </div>
            
<div class="modal-body">                
<div id="detailedReconciliationContent"></div>
            </div>
        </div>
    </div>
    
<!-- Record Payment Modal -->    
<div id="recordPaymentModal" class="modal">        
<div class="modal-content" style="max-width:700px;">            
<div class="modal-header">                <h2><i data-lucide="credit-card" class="icon-sm"></i> Record Payment</h2>                <span class="close-btn" id="closePaymentModal">&times;</span>            </div>
            
<div class="modal-body">                
<div id="paymentLoaneeInfo" class="balance-highlight"></div>
                
<div class="payment-input-group">                    
    <h4 style="margin-top:0; color:#2c5f2d;">
    <i data-lucide="coins" class="icon-sm"></i> 
    Payment Breakdown
</h4>                    
<div class="payment-input-row">                        
<div>                            <label>Principal Amount (KES) <span style="color:red;">*</span></label>                            <input type="number" id="principalAmount" placeholder="Enter principal payment" min="0" step="0.01">                            
<div class="input-helper">Amount going towards loan principal</div>
                        </div>
                        
<div>                            <label>Interest Amount (KES) <span style="color:red;">*</span></label>                            <input type="number" id="interestAmount" placeholder="Enter interest amount" min="0" step="0.01">                            
<div class="input-helper">Interest portion of this payment</div>
                        </div>
                    </div>
                    
<div style="margin-top: 20px;">                        <label style="color:#2e7d32; font-weight:bold;">Total Payment Amount (KES)</label>                        <input type="number" id="totalPaymentAmount" placeholder="Auto-calculated" readonly style="background:#e8f5e9; font-weight:bold; font-size:1.1rem;">                        
<div class="input-helper" style="color:#2e7d32;">Automatically calculated: Principal + Interest</div>
                    </div>
                    
<div class="calculation-preview" id="paymentCalculationPreview" style="display:none;">                        <h4><i data-lucide="info" class="icon-sm"></i> Payment Summary</h4>                        
<div class="calc-row"><span>Principal Paid:</span><span id="previewPrincipal" style="color:#2e7d32;">KES 0</span></div>
                        
<div class="calc-row"><span>Interest Charged:</span><span id="previewInterest" style="color:#d84315;">KES 0</span></div>
                        
<div class="calc-row"><span>Total Payment:</span><span id="previewTotalPayment" style="font-weight:bold;">KES 0</span></div>
                        
<div class="calc-row"><span>New Balance:</span><span id="previewNewBalance" style="color:#c62828;">KES 0</span></div>
                    </div>
                </div>
                <label>Payment Date <span style="color:red;">*</span></label>                <input type="date" id="paymentDate" style="width:100%; padding:10px; margin-bottom:15px; border:2px solid #e0e0e0; border-radius:6px;">                <label>Payment Source <span style="color:red;">*</span></label>                <select id="paymentSource" style="width:100%; padding:10px; margin-bottom:15px; border:2px solid #e0e0e0; border-radius:6px;">                    <option value="">Select payment source</option>                    <option value="Salary Deduction">💼 Salary Deduction</option>                    <option value="Bank Transfer">🏦 Bank Transfer</option>                    <option value="Cash Deposit">💵 Cash Deposit</option>                </select>                <label>Transaction/Reference ID <span style="color:red;">*</span></label>                <input type="text" id="transactionReference" placeholder="e.g., TXN123456, REF-2024-001" maxlength="100" style="width:100%; padding:10px; margin-bottom:15px; border:2px solid #e0e0e0; border-radius:6px;">                
<div class="input-helper">Bank transaction ID, receipt number, or any reference for this payment</div>
                <label>Payment Notes (Optional):</label>                <textarea id="paymentNotes" placeholder="Add any notes about this payment..." style="width:100%; min-height:80px; padding:12px; margin-bottom:20px; border:2px solid #e0e0e0; border-radius:6px; resize:vertical;"></textarea>                
<div style="text-align:right;">                    <button id="submitPayment" class="action-btn" style="padding: 12px 25px; font-size: 1rem;"><i data-lucide="save" class="icon-sm"></i> Record Payment</button>                    <button id="cancelPayment" class="action-btn reject-btn" style="padding: 12px 25px; font-size: 1rem;"><i data-lucide="x-circle" class="icon-sm"></i> Cancel</button>                </div>
            </div>
        </div>
    </div>
    
<script>
    const APP_STATE = {
        loggedInFromMain: <?php echo $loggedInFromMain ? 'true' : 'false'; ?>,
        userData: <?php echo json_encode($userData); ?>,
        cashierPermissions: <?php echo json_encode($cashierPermissions ?? []); ?>,
        currentUser: null, csrfToken: null, currentPaymentLoan: null,
        currentActionLoan: null, currentActionType: null,
        sidebarOpen: true, sidebarCollapsed: false,
        reconDateRange: { from: null, to: null }
    };
</script>
<script>
    function calculateMonthlyInterest(principal, annualRate, months = 1) {
        if (principal <= 0 || annualRate <= 0) return 0;
        const monthlyRate = annualRate / 100 / 12;
        return principal * monthlyRate * Math.max(1, months);
    }
    async function recalculateLoanBalance(loan) {
        const annualRate = parseFloat(<?php echo $interest_rate ?? 3; ?>);
        const payments = Array.isArray(loan.payments) ? loan.payments : [];
        let totalPaid = 0, principalPaid = 0, interestPaid = 0;
        payments.forEach(p => {
            totalPaid += parseFloat(p.amount) || 0;
            principalPaid += parseFloat(p.principal_amount) || 0;
            interestPaid += parseFloat(p.interest_amount) || 0;
        });
        const originalAmount = parseFloat(loan.loan_amount) || 0;
        const currentPrincipal = Math.max(0, originalAmount - principalPaid);
        let lastPaymentDate = payments.length > 0
            ? new Date([...payments].sort((a,b) => new Date(b.payment_date)-new Date(a.payment_date))[0].payment_date)
            : (loan.dispatch_date ? new Date(loan.dispatch_date) : new Date());
        const daysSinceLastPayment = Math.floor((new Date() - lastPaymentDate) / (1000*60*60*24));
        const monthsSinceLastPayment = Math.max(1, Math.round(daysSinceLastPayment / 30));
        const accruedInterest = currentPrincipal > 0 ? calculateMonthlyInterest(currentPrincipal, annualRate, monthsSinceLastPayment) : 0;
        return {
            originalAmount, totalPaid, principalPaid, interestPaid, currentPrincipal,
            accruedInterest: accruedInterest.toFixed(2),
            totalBalanceDue: (currentPrincipal + accruedInterest).toFixed(2),
            lastPaymentDate: lastPaymentDate.toISOString().split('T')[0],
            monthsSinceLastPayment, daysSinceLastPayment
        };
    }
    async function generatepayment_schedule(loan) {
        const rate = <?php echo $interest_rate; ?>;
        loan.payment_frequency = 'monthly';
        const dispatchDate = new Date(loan.dispatch_date || loan.application_date);
        const gracePeriodEndDate = new Date(dispatchDate);
        gracePeriodEndDate.setMonth(gracePeriodEndDate.getMonth() + 1);
        const startDate = new Date(gracePeriodEndDate);
        const principal = parseFloat(loan.loan_amount);
        const years = loan.payment_duration || 1;
        let schedule = [];
        const paymentsPerYear = 12;
        const totalPayments = years * paymentsPerYear;
        const periodicRate = (rate / 100) / paymentsPerYear;
        const periodicPayment = principal * (periodicRate * Math.pow(1 + periodicRate, totalPayments)) / (Math.pow(1 + periodicRate, totalPayments) - 1);
        let balance = principal;
        for (let i = 0; i < totalPayments; i++) {
            const dueDate = new Date(startDate);
            dueDate.setMonth(startDate.getMonth() + i);
            const interestPayment = balance * periodicRate;
            const principalPayment = periodicPayment - interestPayment;
            balance -= principalPayment;
            schedule.push({
                paymentNumber: i + 1, dueDate: dueDate.toISOString(),
                expectedAmount: periodicPayment, expectedPrincipal: principalPayment,
                expectedInterest: interestPayment, expectedBalance: Math.max(0, balance),
                status: 'pending', actualAmount: 0, actualInterest: 0, actualDate: null, variance: 0
            });
        }
        return { schedule, gracePeriodEndDate: gracePeriodEndDate.toISOString(), startDate: startDate.toISOString() };
    }
</script>
<script src="../js/cashier.js?v=20260913-6"></script>
<script>
    const renderIcons = () => {
        lucide.createIcons();
        document.querySelectorAll('svg[data-lucide]').forEach(el => el.removeAttribute('data-lucide'));
    };
    renderIcons();
    const observer = new MutationObserver((mutations) => {
        let hasNewNodes = false;
        mutations.forEach((mutation) => {
            mutation.addedNodes.forEach(node => {
                if (node.nodeType === 1) hasNewNodes = true;
            });
        });
        if (hasNewNodes) {
            observer.disconnect();
            renderIcons();
            observer.observe(document.body, { childList: true, subtree: true });
        }
    });
    observer.observe(document.body, { childList: true, subtree: true });
</script>
</body>
</html>
