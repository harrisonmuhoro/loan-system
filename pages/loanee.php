<?php
// ==========================================
// 1. ERROR & SESSION CONFIGURATION
// ==========================================
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

 $logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
ini_set('error_log', $logDir . '/php_errors.log');

// Secure session settings
ini_set('session.gc_maxlifetime', 86400);
ini_set('session.cookie_lifetime', 86400);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
ini_set('session.use_strict_mode', 1);
ini_set('session.use_only_cookies', 1);

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ==========================================
// 2. PORTAL SWITCHING: STAFF → LOANEE
// ==========================================
if (isset($_GET['action']) && $_GET['action'] === 'switch_portal') {
    error_log("Portal switch attempt detected");
    error_log("Session data: " . print_r($_SESSION, true));
    
    // Check if user has staff role
    $userRole = $_SESSION['user_role'] ?? '';
    
    if (!in_array($userRole, ['admin', 'cashier', 'accountant'])) {
        error_log("Unauthorized portal switch attempt by role: $userRole");
        header("Location: /loan-system/index.php?error=unauthorized");
        exit();
    }

    // Store original staff identity
    $_SESSION['staff_backup'] = [
        'user_id'     => $_SESSION['user_id'],
        'pf_number'   => $_SESSION['pf_number'],
        'user_role'   => $_SESSION['user_role'],
        'full_name'   => $_SESSION['full_name'],
        'email'       => $_SESSION['email'],
        'id_number'   => $_SESSION['id_number']
    ];

    // Mark as viewing loanee portal (but keep original role for return)
    $_SESSION['viewing_as_loanee'] = true;
    $_SESSION['original_portal']    = $_GET['from'] ?? 'admin';
    $_SESSION['staff_original_role'] = $userRole; // Store original role

    error_log("Staff switched to loanee portal: PF={$_SESSION['pf_number']}, From={$_GET['from']}");
    
    // Redirect to clean URL
    header("Location: loanee.php");
    exit();
}

// ==========================================
// 3. PORTAL SWITCHING: LOANEE → STAFF (RETURN)
// ==========================================
if (isset($_GET['action']) && $_GET['action'] === 'return_to_staff') {
    if (!isset($_SESSION['staff_backup'])) {
        error_log("Return to staff portal blocked: no backup data");
        header("Location: loanee.php");
        exit();
    }

    // Restore original staff session
    foreach ($_SESSION['staff_backup'] as $key => $value) {
        $_SESSION[$key] = $value;
    }

    // Get the portal BEFORE unsetting (this is the fix)
    $portal = $_SESSION['original_portal'] ?? 'index';

    // Clear switching flags
    unset(
        $_SESSION['viewing_as_loanee'],
        $_SESSION['original_portal'],
        $_SESSION['staff_backup'],
        $_SESSION['staff_original_role']
    );

    $redirectMap = [
        'admin' => 'admin.php?returned_from_loanee=1',
        'cashier' => 'cashier.php?returned_from_loanee=1',
        'accountant' => 'accountant.php?returned_from_loanee=1',
        'index' => 'index.php'
    ];

    $url = $redirectMap[$portal] ?? 'index.php';
    error_log("Staff returned from loanee portal → $url");
    header("Location: $url");
    exit();
}


// ==========================================
// 4. SESSION VALIDATION & NORMALIZATION
// ==========================================
if (!isset($_SESSION['user_id']) || !isset($_SESSION['pf_number'])) {
    error_log("CRITICAL: Invalid session - missing user_id or pf_number");
    session_unset();
    session_destroy();
    header("Location: /loan-system/index.php?error=session_invalid");
    exit();
}

// Normalize and validate core identity
 $user_id       = (int)$_SESSION['user_id'];
 $user_pf       = trim((string)$_SESSION['pf_number']);
 $user_name     = trim((string)($_SESSION['full_name'] ?? ''));
 $user_email    = trim((string)($_SESSION['email'] ?? ''));
 $id_number     = trim((string)($_SESSION['id_number'] ?? ''));
 $user_role     = trim((string)($_SESSION['user_role'] ?? 'loanee'));

// Final validation
if ($user_id <= 0 || empty($user_pf)) {
    error_log("CRITICAL: Invalid session data - user_id=$user_id, pf=$user_pf");
    session_unset();
    session_destroy();
    header("Location: /loan-system/index.php?error=session_invalid");
    exit();
}

error_log("Session validated: ID=$user_id, PF=$user_pf, Role=$user_role");



// ==========================================
// 5. ACCESS CONTROL: WHO CAN VIEW THIS PAGE?
// ==========================================
 $allowed = false;

// Regular loanees - always allowed
if ($user_role === 'loanee') {
    $allowed = true;
    error_log("Access granted: Regular loanee user");
}

// Staff viewing as loanee - allowed if flag is set
if (isset($_SESSION['viewing_as_loanee']) && $_SESSION['viewing_as_loanee'] === true) {
    if (in_array($user_role, ['admin', 'cashier', 'accountant'])) {
        $allowed = true;
        error_log("Access granted: Staff ($user_role) viewing as loanee - PF=$user_pf");
    }
}

if (!$allowed) {
    error_log("Access denied: role=$user_role, viewing_as_loanee=" . 
              (isset($_SESSION['viewing_as_loanee']) ? 'true' : 'false'));
    
    // Redirect to appropriate portal
    $url = match ($user_role) {
        'admin'      => 'admin.php',
        'cashier'    => 'cashier.php',
        'accountant' => 'accountant.php',
        'loanee'     => 'loanee.php',
        default      => 'index.php'
    };
    header("Location: $url");
    exit();
}

// ==========================================
// 6. CSRF TOKEN
// ==========================================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ==========================================
// 7. DATABASE CONNECTION
// ==========================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    if (!$conn) {
        throw new Exception("Database connection failed");
    }
} catch (Exception $e) {
    error_log("Database connection error: " . $e->getMessage());
    http_response_code(503);

    // Get user's theme preference
 $userTheme = getUserPreference($conn, $user_id, 'theme', 'light');
    
    // Display error page
    ?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Unavailable</title>
    <style>
    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }

    .error-container {
        background: white;
        padding: 40px;
        border-radius: 12px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        max-width: 500px;
        text-align: center;
    }

    .error-icon {
        font-size: 64px;
        margin-bottom: 20px;
    }

    h1 {
        color: #2c3e50;
        font-size: 24px;
        margin-bottom: 15px;
    }

    p {
        color: #7f8c8d;
        line-height: 1.6;
        margin-bottom: 25px;
    }

    button {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border: none;
        padding: 12px 30px;
        border-radius: 6px;
        font-size: 16px;
        cursor: pointer;
        transition: transform 0.2s;
    }

    button:hover {
        transform: translateY(-2px);
    }
    </style>
    <script src="../js/lucide.min.js"></script>
</head>

<body>
    <div class="error-container">
        <div class="error-icon"><i data-lucide="alert-triangle" class="icon-sm"></i></div>
        <h1>Service Temporarily Unavailable</h1>
        <p>We're having trouble connecting to our database. This is usually temporary.</p>
        <button onclick="location.reload()">Try Again</button>
    </div>
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
<?php
    exit();
}

// Utility functions for user preferences
function getUserPreference($conn, $userId, $key, $default = null) {
    $stmt = $conn->prepare("SELECT preference_value FROM user_preferences WHERE user_id = ? AND preference_key = ?");
    $stmt->execute([$userId, $key]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['preference_value'] : $default;
}

function setUserPreference($conn, $userId, $key, $value) {
    $stmt = $conn->prepare("INSERT INTO user_preferences (user_id, preference_key, preference_value) 
                           VALUES (?, ?, ?) 
                           ON DUPLICATE KEY UPDATE preference_value = ?, updated_at = CURRENT_TIMESTAMP");
    $stmt->execute([$userId, $key, $value, $value]);
    return true;
}

// Get system settings (interest rate)
 $interest_rate = 3.0;
try {
    $query = "SELECT value FROM system_settings WHERE setting_key = 'amortization_rate'";
    $stmt = $conn->prepare($query);
    $stmt->execute();

    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $interest_rate = floatval($row['value']);
    }
} catch (Exception $e) {
    error_log("Error getting interest rate: " . $e->getMessage());
}

// ==========================================
// 8. HANDLE PASSWORD CHANGE REQUEST
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $password_error = "Invalid request. Please try again.";
    } else {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $password_error = "All password fields are required.";
        } elseif ($new_password !== $confirm_password) {
            $password_error = "New passwords do not match.";
        } elseif (strlen($new_password) < 8) {
            $password_error = "Password must be at least 8 characters long.";
        } elseif (!preg_match('/[A-Z]/', $new_password)) {
            $password_error = "Password must contain at least one uppercase letter.";
        } elseif (!preg_match('/[a-z]/', $new_password)) {
            $password_error = "Password must contain at least one lowercase letter.";
        } elseif (!preg_match('/[0-9]/', $new_password)) {
            $password_error = "Password must contain at least one number.";
        } elseif (preg_match('/\s/', $new_password)) {
            $password_error = "Password cannot contain spaces.";
        } else {
            require_once __DIR__ . '/../models/User.php';
            $user = new User($conn);

            if ($user->authenticate($user_email, $current_password)) {
                $user->user_id = $user_id;
                if ($user->updatePassword($new_password)) {
                    $password_success = "Password changed successfully.";
                } else {
                    $password_error = "Failed to update password. Please try again.";
                }
            } else {
                $password_error = "Current password is incorrect.";
            }
        }
    }
}


?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Loanee Portal</title>
    <script src="../js/logout-handler.js"></script>
    <link rel="stylesheet" href="../css/loanee.css">

    <script src="../js/lucide.min.js"></script>
</head>

<body>

    <!-- Header -->
    <header>
        <div class="header-container">
            <!-- Hamburger Menu Button -->
            <button class="hamburger" id="hamburgerMenu">
                <span></span>
                <span></span>
                <span></span>
            </button>

            <img src="../logo.jpg" alt="Company Logo" class="logo">
            <h1>Loanee's Portal</h1>

            <!--Theme Selector -->
            <div class="theme-selector" id="themeSelector">
                <button class="theme-option active" data-theme="light" aria-label="Light theme" title="Light theme">
                    <i data-lucide="sun" class="icon-sm"></i>
                </button>
                <button class="theme-option" data-theme="dark" aria-label="Dark theme" title="Dark theme">
                    <i data-lucide="moon" class="icon-sm"></i>
                </button>
            </div>

            <!-- Profile Dropdown Menu -->
            <div class="profile-dropdown">
                <button class="profile-button" id="profileButton">
                    <img src="https://picsum.photos/seed/loanee123/40/40.jpg" alt="User" class="profile-avatar">
                    <span class="profile-name">
                        <?php echo htmlspecialchars($user_name ?: 'Loanee'); ?>
                    </span>
                    <span class="dropdown-arrow"><i data-lucide="chevron-down" class="icon-sm"></i></span>
                </button>

                <div class="dropdown-menu" id="profileDropdown">
                    <a href="#" class="dropdown-item" id="profileMenuItem">
                        <span class="dropdown-icon"><i data-lucide="user" class="icon-sm"></i></span>
                        Profile
                    </a>
                    <a href="#" class="dropdown-item" id="securityMenuItem">
                        <span class="dropdown-icon"><i data-lucide="lock" class="icon-sm"></i></span>
                        Security
                    </a>
                    <a href="#" class="dropdown-item logout-item" id="profileLogoutBtn">
                        <span class="dropdown-icon"><i data-lucide="log-out" class="icon-sm"></i></span>
                        Logout
                    </a>
                </div>
            </div>
        </div>
    </header>
    <aside class="sidebar" id="sidebar">
        <!-- Sidebar Logo -->
        <div class="sidebar-logo">
            <img src="../logo.jpg" alt="Company Logo">
            <div class="sidebar-logo-text">Loanee Portal</div>
        </div>

        <!-- Sidebar Buttons -->
        <button class="active tooltip" data-section="dashboard">
            <span class="button-icon"><i data-lucide="play"></i></span>
            <span class="button-text">Dashboard</span>
            <span class="tooltiptext">Dashboard</span>
        </button>
        <button class="tooltip" data-section="calculator">
            <span class="button-icon"><i data-lucide="calculator"></i></span>
            <span class="button-text">Calculator</span>
            <span class="tooltiptext">Loan Calculator</span>
        </button>
        <button class="tooltip" data-section="history">
            <span class="button-icon"><i data-lucide="credit-card"></i></span>
            <span class="button-text">Payment History</span>
            <span class="tooltiptext">Payment History</span>
        </button>

        <button class="tooltip" data-section="payment-schedule">
            <span class="button-icon"><i data-lucide="calendar"></i></span>
            <span class="button-text">Payment Schedule</span>
            <span class="tooltiptext">View Payment Schedule</span>
        </button>

        <button class="tooltip" data-section="newapp">
            <span class="button-icon"><i data-lucide="file-text"></i></span>
            <span class="button-text">New Application</span>
            <span class="tooltiptext">Apply for Loan</span>
        </button>
        <button class="tooltip" data-section="profile">
            <span class="button-icon"><i data-lucide="user"></i></span>
            <span class="button-text">Profile & Security</span>
            <span class="tooltiptext">Profile Settings</span>
        </button>

        <?php
        // Only show return button if user is staff AND is viewing as loanee
        $isStaffUser = isset($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['admin', 'cashier', 'accountant']);
        $isViewingAsLoanee = isset($_SESSION['viewing_as_loanee']) && $_SESSION['viewing_as_loanee'] === true;

        if ($isStaffUser && $isViewingAsLoanee):
        ?>
        <a href="?action=return_to_staff" class="btn btn-danger">
            <span class="button-icon"><i data-lucide="corner-up-left" class="icon-sm"></i></span>
            RETURN TO <?php echo strtoupper($_SESSION['staff_original_role'] ?? 'STAFF'); ?> PORTAL
        </a>
        <?php endif; ?>

        <button class="tooltip" id="logoutBtn" style="margin-top: auto; background: #c0392b;">
            <span class="button-icon"><i data-lucide="file-text"></i></span>
            <span class="button-text">Logout</span>
            <span class="tooltiptext">Logout</span>
        </button>
    </aside>

    <div class="main-content" id="mainContent">
        <main>
            <section id="dashboardSection">
                <div style="display:flex;justify-content:space-between;align-items:center">
                    <div>
                        <h2>Welcome, <span id="userName"><?php echo htmlspecialchars($user_name); ?></span>!</h2>
                        <div style="color:#6c757d">PF Number: <span
                                id="userPf"><?php echo htmlspecialchars($user_pf); ?></span></div>
                    </div>
                    <div style="text-align:right">
                        <div class="info-box">
                            <strong>Fixed Interest Rate:</strong> <span
                                id="fixedRateDisplay"><?php echo $interest_rate; ?></span>% per annum
                        </div>
                    </div>
                </div>

                <h3 style="margin-top:18px">Latest Loan Application</h3>
                <div id="latestLoan" class="info-box hidden"></div>
                <div id="noLoan" class="hidden">
                    <h3>No Loan Applications Yet</h3>
                    <p>You haven't submitted any loan applications. Use "New Application" to get started.</p>
                </div>
            </section>

            <section id="calculatorSection" class="hidden">
                <h2>Loan Amortization Calculator</h2>
                <div class="calculator-form">
                    <div class="calculator-inputs">
                        <label for="calcAmount">Loan Amount (KES)</label>
                        <input id="calcAmount" type="number" min="0" step="1000" placeholder="e.g., 1,000,000" />
                        <label for="calcTerm">Loan Term (years)</label>
                        <input id="calcTerm" type="number" min="0.1" step="0.1" placeholder="e.g., 15" />
                        <button id="calcBtn" style="margin-top:12px">Calculate Amortization</button>
                    </div>

                    <div class="calculator-summary hidden" id="calcSummary">
                        <h3>Payment Summary</h3>
                        <div class="summary-item"><span>Loan Amount</span><span>KES <span id="sumAmount">0</span></span>
                        </div>
                        <div class="summary-item"><span>Interest Rate</span><span><span id="sumRate">0</span>%
                                p.a.</span></div>
                        <div class="summary-item"><span>Term</span><span><span id="sumTerm">0</span> years</span>
                        </div>
                        <div class="summary-item"><span>Monthly Payment</span><span>KES <span
                                    id="sumMonthly">0</span></span></div>
                        <div class="summary-item"><span>Total Interest</span><span>KES <span
                                    id="sumInterest">0</span></span></div>
                        <div class="summary-item"><span>Total Paid</span><span>KES <span id="sumTotal">0</span></span>
                        </div>
                    </div>
                </div>

                <div id="amortContainer" class="hidden">
                    <h3>Amortization Schedule</h3>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th style="text-align:left">Payment #</th>
                                    <th style="text-align:left">Payment Date</th>
                                    <th>Monthly Payment</th>
                                    <th>Principal</th>
                                    <th>Interest</th>
                                    <th>Balance</th>
                                </tr>
                            </thead>
                            <tbody id="amortBody"></tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section id="historySection" class="hidden">
                <h2>Loan Application History</h2>
                <div id="loanHistoryGrid"></div>
            </section>

            <section id="paymentsSection" class="hidden">
                <h2>Payment History</h2>
                <div class="payment-history-section">
                    <div class="payment-summary" id="paymentSummary">
                        <div class="summary-card">
                            <div class="summary-item">
                                <span>Total Payments</span>
                                <strong id="totalPayments">KES 0</strong>
                            </div>
                            <div class="summary-item">
                                <span>Total Principal Paid</span>
                                <strong id="totalPrincipal">KES 0</strong>
                            </div>
                            <div class="summary-item">
                                <span>Total Interest Paid</span>
                                <strong id="totalInterest">KES 0</strong>
                            </div>
                        </div>
                    </div>
                    <div class="payment-filters">
                        <div class="filter-group">
                            <label for="loanFilter">Filter by Loan ID</label>
                            <select id="loanFilter">
                                <option value="">All Loan IDs</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="typeFilter">Filter by Loan Type</label>
                            <select id="typeFilter">
                                <option value="">All Types</option>
                                <option value="mortgage">Mortgage</option>
                                <option value="car">Car Loan</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="yearFilter">Filter by Year</label>
                            <select id="yearFilter">
                                <option value="">All Years</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="sourceFilter">Filter by Payment Source</label>
                            <select id="sourceFilter">
                                <option value="">All Sources</option>
                                <option value="salary_deduction">Salary Deduction</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cash_deposit">Cash Deposit</option>
                            </select>
                        </div>
                        <div class="filter-group" style="display: flex; align-items: flex-end; gap: 10px;">
                            <button id="applyFilterBtn" style="margin-top: 8px;">Apply Filter</button>
                            <button id="resetFilterBtn" style="margin-top: 8px;">Reset Filters</button>
                        </div>
                    </div>

                    <div class="payment-table-container">
                        <table class="payment-table">
                            <thead>
                                <tr>
                                    <th>Payment Date</th>
                                    <th>Loan ID</th>
                                    <th>Loan Type</th>
                                    <th>Amount</th>
                                    <th>Principal</th>
                                    <th>Interest</th>
                                    <th>Balance After</th>
                                    <th>Payment Source</th>
                                    <th>Reference ID</th>
                                </tr>
                            </thead>
                            <tbody id="paymentsTableBody">
                                <tr>
                                    <td colspan="9" class="no-payments">Loading payment history...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section id="paymentScheduleSection" class="hidden">
                <h2>Payment Schedule</h2>
                <div class="payment-schedule-section">
                    <div class="schedule-filters">
                        <div class="filter-group">
                            <label for="scheduleLoanFilter">Filter by Loan ID</label>
                            <select id="scheduleLoanFilter">
                                <option value="">All Loan IDs</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="scheduleTypeFilter">Filter by Loan Type</label>
                            <select id="scheduleTypeFilter">
                                <option value="">All Types</option>
                                <option value="mortgage">Mortgage</option>
                                <option value="car">Car Loan</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="scheduleYearFilter">Filter by Year</label>
                            <select id="scheduleYearFilter">
                                <option value="">All Years</option>
                            </select>
                        </div>
                        <div class="filter-group" style="display: flex; align-items: flex-end; gap: 10px;">
                            <button id="applyScheduleFilterBtn" style="margin-top: 8px;">Apply Filter</button>
                            <button id="resetScheduleFilterBtn" style="margin-top: 8px;">Reset Filters</button>
                        </div>
                    </div>
                    <div id="scheduleTableContainer">
                        <!-- Payment schedule will be loaded here -->
                    </div>
                </div>
            </section>

            <section id="profileSection" class="hidden">
                <h2>Profile & Security</h2>

                <div class="info-box" style="margin-bottom:20px">
                    <h3>Account Information</h3>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-top:10px">
                        <div><strong>Full Name:</strong> <?php echo htmlspecialchars($user_name); ?></div>
                        <div><strong>PF Number:</strong> <?php echo htmlspecialchars($user_pf); ?></div>
                        <div><strong>Email:</strong> <?php echo htmlspecialchars($user_email); ?></div>
                        <div><strong>ID Number:</strong> <?php echo htmlspecialchars($id_number); ?></div>
                    </div>
                </div>

                <div class="password-section">
                    <h3>Change Password</h3>

                    <?php if (isset($password_error)): ?>
                    <div class="error"><?php echo $password_error; ?></div>
                    <?php endif; ?>

                    <?php if (isset($password_success)): ?>
                    <div class="success"><?php echo $password_success; ?></div>/8+

                    <?php endif; ?>
                    <form method="post" action="" id="passwordForm">
                        <input type="hidden" name="action" value="change_password">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                        <!-- Add this new hidden username field here -->
                        <input type="text" name="username" value="<?php echo htmlspecialchars($_SESSION['email']); ?>"
                            autocomplete="username" style="display: none;" tabindex="-1" readonly>

                        <label for="current_password">Current Password</label>
                        <input type="password" id="current_password" name="current_password"
                            autocomplete="current-password" required>

                        <label for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password" autocomplete="new-password"
                            required>
                        <div class="password-requirements">
                            Password must be at least 8 characters long.
                        </div>
                        <div id="passwordStrength" class="password-strength"></div>

                        <label for="confirm_password">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password"
                            required>

                        <button type="submit" style="margin-top:15px">Change Password</button>
                    </form>
                </div>
            </section>

            <section id="newAppSection" class="hidden">
                <h2>Apply for Loan</h2>

                <div class="eligibility-section" id="eligibilityCheck">
                    <h3><i data-lucide="clipboard-list" class="icon-sm"></i> Loan Eligibility Pre-Check</h3>
                    <p>Before proceeding with your application, let's check if you meet our lending criteria.</p>

                    <!-- Find the eligibility section in your HTML and update it -->
                    <div class="eligibility-grid">
                        <div>
                            <label for="checkLoanAmount">Desired Loan Amount (KES)</label>
                            <input id="checkLoanAmount" type="number" min="0" placeholder="e.g., 500,000" />

                            <label for="checkLoanTerm">Loan Term (years)</label>
                            <input id="checkLoanTerm" type="number" min="0.1" step="0.1" placeholder="e.g., 10" />

                            <label for="checkBasicSalary">Your Basic Monthly Salary (KES)</label>
                            <input id="checkBasicSalary" type="number" min="0" placeholder="e.g., 100,000" />

                            <label for="checkCurrentNetSalary">Your Current Net Salary (KES)</label>
                            <input id="checkCurrentNetSalary" type="number" min="0" placeholder="e.g., 80,000"
                                value="0" />

                            <h3 style="margin-top:20px; border-top: 1px solid #ccc; padding-top: 15px;">Loan Type
                            </h3>
                            <label for="precheckLoanType">Select Loan Type</label>
                            <select id="precheckLoanType" required>
                                <option value="">-- Select --</option>
                                <option value="mortgage">Mortgage Loan</option>
                                <option value="car">Car Loan</option>
                            </select>

                            <!-- Property/Car Value Fields -->
                            <div id="precheckPropertyValue" class="hidden">
                                <label for="checkPropertyValue">Property Value (KES)</label>
                                <input id="checkPropertyValue" type="number" min="0" placeholder="e.g., 5,000,000" />
                                <small style="color:#6c757d">Loan amount cannot exceed property value</small>
                            </div>

                            <div id="precheckCarValue" class="hidden">
                                <label for="checkCarValue">Car Value (KES)</label>
                                <input id="checkCarValue" type="number" min="0" placeholder="e.g., 2,000,000" />
                                <small style="color:#6c757d">Loan amount cannot exceed car value</small>
                            </div>

                            <h3 style="margin-top:20px; border-top: 1px solid #ccc; padding-top: 15px;">Employment
                                Details</h3>
                            <label for="precheckEmploymentType">Employment Type</label>
                            <select id="precheckEmploymentType" required>
                                <option value="">-- Select --</option>
                                <option value="permanent">Full-time Government Employee</option>
                                <option value="contracted">Contracted</option>
                            </select>

                            <!-- Retirement Date for Permanent Employees -->
                            <div id="precheckRetirementDetails" class="hidden">
                                <label for="precheckRetirementDate">Expected Retirement Date</label>
                                <input id="precheckRetirementDate" type="date" required />
                                <small style="color:#6c757d">
                                    <strong>Note:</strong><br>
                                    • Car loans must be fully repaid <strong>within 5 years</strong> after loan
                                    disbursment<br>
                                    • Mortgage loans must be fully repaid <strong>within 10 years</strong> after
                                    loan
                                    disbursment
                                </small>
                            </div>

                            <!-- Contract End Date for Contracted Employees -->
                            <div id="precheckContractDetails" class="hidden">
                                <label for="precheckContractEnd">Contract End Date</label>
                                <input id="precheckContractEnd" type="date" required />
                                <small style="color:#6c757d">Your contract must be valid for entire loan duration,
                                    excluding final 3 months.</small>
                            </div>
                        </div>
                    </div>

                    <button id="checkEligibilityBtn" style="margin-top:15px">Check Eligibility</button>

                    <div id="eligibilityResult" class="eligibility-result hidden"></div>
                    <div id="breakdownBox" class="calculation-breakdown hidden">
                        <h4>Monthly Deduction Breakdown</h4>
                        <div class="breakdown-item">
                            <span>Basic Salary</span>
                            <span id="breakdownSalary">KES 0</span>
                        </div>
                        <div class="breakdown-item">
                            <span>Maximum Allowed (1/3 of Salary)</span>
                            <span id="breakdownMaxAllowed">KES 0</span>
                        </div>
                        <div class="breakdown-item">
                            <span>Current Net Salary</span>
                            <span id="breakdownCurrentNet">KES 0</span>
                        </div>
                        <div class="breakdown-item">
                            <span>New Loan Payment</span>
                            <span id="breakdownNewLoan">KES 0</span>
                        </div>
                        <div class="breakdown-item">
                            <span>New Net Salary (After Loan Payment)</span>
                            <span id="breakdownNewNet">KES 0</span>
                        </div>
                        <div class="breakdown-item breakdown-total">
                            <span>Remaining After Loan Payment</span>
                            <span id="breakdownRemaining">KES 0</span>
                        </div>
                    </div>
                </div>

                <form id="loanForm" class="hidden">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                        <div>
                            <label for="loanType">Loan Type</label>
                            <select id="loanType" required>
                                <option value="">-- Select --</option>
                                <option value="mortgage">Mortgage Loan</option>
                                <option value="car">Car Loan</option>
                            </select>
                            <div id="mortgageFields" class="hidden">
                                <label for="propertyLocation">Property Location</label>
                                <input id="propertyLocation" required />
                                <label for="propertyValue">Property Value (KES)</label>
                                <input id="propertyValue" type="number" min="0" step="1" required />
                            </div>

                            <div id="carFields" class="hidden">
                                <label for="carType">Car Type</label>
                                <input id="carType" required />
                                <label for="carValue">Car Value (KES)</label>
                                <input id="carValue" type="number" min="0" step="1" required />
                            </div>
                        </div>

                        <div>
                            <label for="expectedLoan">Expected Loan Amount (KES)</label>
                            <input id="expectedLoan" type="number" min="0" required />
                            <label for="paymentYears">Payment Duration (years)</label>
                            <input id="paymentYears" type="number" min="0.1" step="0.1" max="30" required />
                            <label for="basicSalary">Basic Salary (KES)</label>
                            <input id="basicSalary" type="number" min="0" required />
                        </div>
                    </div>

                    <h3 style="margin-top:14px">Employment</h3>
                    <label for="employmentType">Employment Type</label>
                    <select id="employmentType" required>
                        <option value="">-- Select --</option>
                        <option value="permanent">Full-time Government Employee</option>
                        <option value="contracted">Contracted</option>
                    </select>
                    <div id="contractDetails" class="hidden">
                        <label for="contractPeriod">Contract Period (months)</label>
                        <input id="contractPeriod" type="number" min="1" required />
                        <label for="contractStart">Contract Start Date</label>
                        <input id="contractStart" type="date" required />
                        <label for="contractEnd">Contract End Date</label>
                        <input id="contractEnd" type="date" required />
                    </div>

                    <h3 style="margin-top:14px">Banking</h3>
                    <label for="bankName">Bank Name</label>
                    <input id="bankName" value="Family Bank" readonly style="background:#f0f0f0;cursor:not-allowed" />
                    <label for="bankBranch">Bank Branch</label>
                    <input id="bankBranch" placeholder="Enter branch" required />
                    <label for="bankAccount">Bank Account Number</label>
                    <input id="bankAccount" required />

                    <div style="display:flex;gap:8px;margin-top:12px">
                        <button id="submitLoan" type="submit">Submit Loan Application</button>
                        <button id="cancelLoan" type="button" class="secondary">Cancel</button>
                    </div>
                    <div id="loanSuccess" class="success"></div>
                </form>
            </section>
        </main>
    </div>

    <!-- Toast Notification -->
    <div id="toast" class="toast"></div>
    <script>
    // ==========================================
    // CRITICAL: Initialize currentUser IMMEDIATELY
    // ==========================================
    let currentUser = {};
    let csrfToken = "<?php echo $_SESSION['csrf_token']; ?>";
    let currentInterestRate = <?php echo $interest_rate; ?>;

    // ==========================================
    // FIXED: Initialize Current User Data
    // ==========================================
    document.addEventListener('DOMContentLoaded', function() {
        try {
            // Get user data from PHP session
            const userId = <?php echo $user_id ?? 'null'; ?>;
            const userPf = "<?php echo $user_pf ?? ''; ?>";
            const userIdNumber = "<?php echo $id_number ?? ''; ?>";
            const userEmail = "<?php echo $user_email ?? ''; ?>";
            const userName = "<?php echo $user_name ?? ''; ?>";
            const userRole =
                "<?php echo isset($_SESSION['user_role']) ? $_SESSION['user_role'] : 'loanee'; ?>";
            const viewingAsLoanee =
                <?php echo isset($_SESSION['viewing_as_loanee']) && $_SESSION['viewing_as_loanee'] ? 'true' : 'false'; ?>;

            // Log what we received from PHP
            //console.log('=== PHP Session Data ===');
            //console.log('User ID:', userId);
            //console.log('PF Number:', userPf);
            //console.log('ID Number:', userIdNumber);
            //console.log('Email:', userEmail);
            //console.log('Full Name:', userName);
            //console.log('Role:', userRole);
            //console.log('Viewing as Loanee:', viewingAsLoanee);

            // Validate critical fields
            if (!userId || !userPf) {
                //console.error('✗ Critical: Missing user_id or pf_number!');
                showNotification('Session error: Missing user credentials. Please log in again.', 'error');
                setTimeout(() => {
                    window.location.href = 'index.php';
                }, 2000);
                return;
            }

            // Determine effective role based on viewing context
            const effectiveRole = (viewingAsLoanee &&
                    (userRole === 'admin' || userRole === 'cashier' || userRole === 'accountant')) ?
                'loanee' : userRole;

            // Initialize currentUser object
            currentUser = {
                id: userId,
                pf_number: userPf,
                id_number: userIdNumber,
                email: userEmail,
                full_name: userName,
                user_role: effectiveRole,
                viewing_as_loanee: viewingAsLoanee
            };

            //console.log('✓ Current user initialized:', currentUser);

            // Initialize rest of app
            initializeApp();

        } catch (error) {
            //console.error('Error initializing user data:', error);
            showNotification('Error initializing session. Please log in again.', 'error');
        }
    });

    // Define showNotification function immediately to ensure it's available globally
    function showNotification(message, type = 'success', duration = 3000) {
        // Wait for DOM if needed
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => {
                setTimeout(() => showNotification(message, type, duration), 100);
            });
            return;
        }
        // Find or create toast element
        let toast = document.getElementById('toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'toast';
            toast.className = 'toast';
            toast.style.cssText = `
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            color: white;
            padding: 16px 24px;
            border-radius: 8px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3);
            z-index: 10000;
            transform: translateY(100px);
            opacity: 0;
            transition: all 0.3s ease;
            font-size: 14px;
            max-width: 350px;
        `;
            document.body.appendChild(toast);
        }

        toast.textContent = message;
        toast.className = `toast ${type} show`;

        // Auto-hide after duration
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 300);
        }, duration);
    }

    // Make function globally available immediately
    window.showNotification = showNotification;
    </script>
    <script src="../js/loanee.js?v=20260913-2"></script>
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


