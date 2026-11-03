<?php
/**
 * loanee.php - Secure Entry Point with Portal Switching & Data Isolation
 * FIXED: Portal switching permission check
 */

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
        header("Location: index.php?error=unauthorized");
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
    header("Location: index.php?error=session_invalid");
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
    header("Location: index.php?error=session_invalid");
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
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    if (!$conn) {
        throw new Exception("Database connection failed");
    }
} catch (Exception $e) {
    error_log("Database connection error: " . $e->getMessage());
    http_response_code(503);
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
</head>

<body>
    <div class="error-container">
        <div class="error-icon">⚠️</div>
        <h1>Service Temporarily Unavailable</h1>
        <p>We're having trouble connecting to our database. This is usually temporary.</p>
        <button onclick="location.reload()">Try Again</button>
    </div>
</body>

</html>
<?php
    exit();
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
            require_once __DIR__ . '/models/User.php';
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
        <script src="js/logout-handler.js"></script>

    <style>
    /* ===== Global Styles & Reset ===== */
    * {
        box-sizing: border-box;
    }

    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background: linear-gradient(135deg, #f5f9f7 0%, #e8f5f0 100%);
        color: #2c3e50;
        margin: 0;
        padding: 0;
        line-height: 1.6;
    }

    /* ===== Header ===== */
    header {
        background: linear-gradient(135deg, #4a6b4a, #5a7b5a);
        color: white;
        padding: 1rem;
        text-align: center;
        position: fixed;
        width: 100%;
        top: 0;
        z-index: 10;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
    }

    .header-container {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 1rem;
        position: relative;
    }

    .logo {
        height: 60px;
        width: auto;
        object-fit: contain;
    }

    h1 {
        margin: 0;
        font-size: 1.8rem;
        font-weight: 600;
    }

    /* Hamburger Menu Styles */
    .hamburger {
        position: absolute;
        left: 20px;
        top: 50%;
        transform: translateY(-50%);
        display: flex;
        flex-direction: column;
        justify-content: space-around;
        width: 30px;
        height: 25px;
        background: transparent;
        border: none;
        cursor: pointer;
        padding: 0;
        z-index: 11;
    }

    .hamburger span {
        width: 30px;
        height: 3px;
        background: white;
        border-radius: 10px;
        transition: all 0.3s linear;
        position: relative;
        transform-origin: 1px;
    }

    /* Profile Badge Styles */
    .profile-badge {
        position: absolute;
        right: 20px;
        top: 50%;
        transform: translateY(-50%);
        display: flex;
        align-items: center;
        background-color: rgba(255, 255, 255, 0.1);
        border-radius: 30px;
        padding: 5px 15px;
    }

    .profile-info {
        display: flex;
        align-items: center;
        margin-right: 15px;
    }

    .profile-avatar {
        margin-right: 10px;
    }

    .avatar-img {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        border: 2px solid #fff;
    }

    .profile-details {
        color: white;
    }

    .profile-name {
        font-weight: bold;
        font-size: 0.9rem;
    }

    .profile-role {
        font-size: 0.8rem;
        opacity: 0.8;
    }

    /* Sidebar Styles */
    #sidebar {
        background: #6b4a3a;
        width: 240px;
        padding-top: 100px;
        box-sizing: border-box;
        color: white;
        display: flex;
        flex-direction: column;
        position: fixed;
        top: 0;
        bottom: 0;
        left: 0;
        overflow-y: auto;
        box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
        transform: translateX(0);
        transition: all 0.3s ease;
        z-index: 5;
    }

    #sidebar.open {
        transform: translateX(0);
    }

    #sidebar.collapsed {
        width: 70px;
    }

    #sidebar.collapsed .sidebar-logo {
        padding: 15px 10px;
        justify-content: center;
    }

    #sidebar.collapsed .sidebar-logo-text {
        display: none;
    }

    #sidebar.collapsed .sidebar-logo img {
        height: 40px;
    }

    #sidebar.collapsed button {
        padding: 1rem;
        justify-content: center;
        text-align: center;
    }

    #sidebar.collapsed button .button-text {
        display: none;
    }

    #sidebar.collapsed button .button-icon {
        font-size: 1.2rem;
        margin-right: 0;
    }

    #sidebar.collapsed button.active::before {
        display: none;
    }

    #sidebar.collapsed #logoutBtn {
        padding: 0.8rem;
        justify-content: center;
    }

    #sidebar.collapsed #logoutBtn .logout-text {
        display: none;
    }

    .sidebar-logo {
        display: flex;
        align-items: center;
        padding: 20px 15px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        margin-bottom: 10px;
    }

    .sidebar-logo img {
        height: 50px;
        width: auto;
        object-fit: contain;
        margin-right: 10px;
    }

    .sidebar-logo-text {
        font-size: 1.2rem;
        font-weight: bold;
        color: white;
    }

    #sidebar button {
        background: transparent;
        border: none;
        color: white;
        padding: 1rem 1.5rem;
        text-align: left;
        cursor: pointer;
        font-size: 0.95rem;
        border-left: 5px solid transparent;
        transition: all 0.3s ease;
        position: relative;
        display: flex;
        align-items: center;
    }

    #sidebar button:hover {
        background: #5a3a2a;
        padding-left: 2rem;
    }

    #sidebar button.active {
        background: #5a3a2a;
        border-left: 5px solid #3a6b9a;
        font-weight: 600;
    }

    #sidebar button.active::before {
        content: '▶';
        position: absolute;
        left: 10px;
    }

    #sidebar button#logoutBtn {
        margin-top: auto;
        background: #c0392b;
    }

    #sidebar button#logoutBtn:hover {
        background: #a0281b;
    }

    #sidebar.collapsed button#logoutBtn .button-icon {
        font-size: 1.2rem;
    }

    .button-icon {
        margin-right: 10px;
        font-size: 1rem;
        display: inline-block;
        width: 20px;
        text-align: center;
    }

    .button-text {
        flex-grow: 1;
    }

    /* Tooltip Styles */
    .tooltip {
        position: relative;
    }

    .tooltip .tooltiptext {
        visibility: hidden;
        width: 120px;
        background-color: #555;
        color: #fff;
        text-align: center;
        border-radius: 6px;
        padding: 5px 0;
        position: absolute;
        z-index: 1;
        bottom: 125%;
        left: 50%;
        margin-left: -60px;
        opacity: 0;
        transition: opacity 0.3s;
        font-size: 0.8rem;
    }

    .tooltip:hover .tooltiptext {
        visibility: visible;
        opacity: 1;
    }

    /* ===== Main Content ===== */
    .main-content {
        margin-left: 240px;
        margin-top: 80px;
        /* Account for fixed header */
        padding: 2rem;
        min-height: calc(100vh - 80px);
        transition: margin-left 0.3s ease;
    }

    .main-content.full-width {
        margin-left: 0;
    }

    .main-content.sidebar-collapsed {
        margin-left: 90px;
    }

    .main-content.sidebar-open {
        margin-left: 240px;
    }

    main {
        max-width: 1200px;
        margin: 0 auto;
        background: #fff;
        border-radius: 12px;
        padding: 30px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
        animation: fadeInUp 0.5s ease;
    }

    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* ===== Typography ===== */
    h2 {
        color: #4a6b4a;
        margin: 0 0 16px;
        font-size: 28px;
        font-weight: 600;
        position: relative;
        padding-bottom: 10px;
    }

    h2::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        width: 60px;
        height: 3px;
        background: linear-gradient(135deg, #4a6b4a 0%, #6b4a3a 100%);
        border-radius: 2px;
    }

    h3 {
        color: #2c3e50;
        margin: 20px 0 12px;
        font-size: 20px;
        font-weight: 600;
    }

    h4 {
        color: #4a6b4a;
        margin: 15px 0 8px;
        font-size: 16px;
        font-weight: 600;
    }

    /* ===== Form Elements ===== */
    label {
        display: block;
        margin-top: 16px;
        font-weight: 600;
        color: #2c3e50;
        font-size: 14px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    input,
    select,
    textarea {
        width: 100%;
        padding: 12px 16px;
        margin-top: 8px;
        border-radius: 8px;
        border: 2px solid #e1e8ed;
        font-size: 15px;
        transition: all 0.3s ease;
        background: #fff;
    }

    input:focus,
    select:focus,
    textarea:focus {
        outline: none;
        border-color: #3498db;
        box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        transform: translateY(-2px);
    }

    button {
        background: linear-gradient(135deg, #3a6b9a 0%, #2c5282 100%);
        color: #fff;
        border: none;
        cursor: pointer;
        padding: 12px 24px;
        border-radius: 8px;
        font-size: 15px;
        font-weight: 600;
        transition: all 0.3s ease;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        box-shadow: 0 4px 15px rgba(58, 107, 154, 0.3);
    }

    button:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(58, 107, 154, 0.4);
    }

    button:active {
        transform: translateY(0);
    }

    button:disabled {
        background: #95a5a6;
        cursor: not-allowed;
        transform: none;
        box-shadow: none;
    }

    button.secondary {
        background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
        box-shadow: 0 4px 15px rgba(108, 117, 125, 0.3);
    }

    button.secondary:hover {
        box-shadow: 0 6px 20px rgba(108, 117, 125, 0.4);
    }

    /* ===== Utility Classes ===== */
    .hidden {
        display: none !important;
    }

    .error {
        color: #e74c3c;
        font-size: 14px;
        margin-top: 8px;
        padding: 10px;
        background: #fdf2f2;
        border-radius: 6px;
        border-left: 4px solid #e74c3c;
    }

    .success {
        color: #27ae60;
        margin-top: 12px;
        font-weight: 600;
        padding: 12px;
        background: #f0fdf4;
        border-radius: 6px;
        border-left: 4px solid #27ae60;
    }

    .info-box {
        background: linear-gradient(135deg, #e6f0ff 0%, #f0f8ff 100%);
        border: 2px solid #3a6b9a;
        padding: 16px;
        border-radius: 10px;
        box-shadow: 0 4px 15px rgba(58, 107, 154, 0.1);
    }

    /* ===== Calculator Section ===== */
    .calculator-form {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-top: 20px;
    }

    .calculator-inputs,
    .calculator-summary {
        padding: 20px;
        border-radius: 12px;
        border: 2px solid #e1e8ed;
        background: linear-gradient(135deg, #fafbfc 0%, #f8f9fa 100%);
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    }

    .summary-item {
        display: flex;
        justify-content: space-between;
        padding: 12px 0;
        border-bottom: 1px dashed #e1e8ed;
        transition: background 0.3s ease;
    }

    .summary-item:hover {
        background: rgba(52, 152, 219, 0.05);
        padding-left: 8px;
        padding-right: 8px;
        margin: 0 -8px;
        border-radius: 6px;
    }

    .summary-item:last-child {
        border-bottom: none;
        font-weight: 700;
        font-size: 18px;
        color: #4a6b4a;
        background: rgba(74, 107, 74, 0.05);
    }

    /* ===== Table Styles ===== */
    .table-container {
        max-height: 400px;
        overflow: auto;
        border: 2px solid #e1e8ed;
        border-radius: 10px;
        margin-top: 16px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    }

    table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
        background: #fff;
    }

    th,
    td {
        padding: 12px;
        border: 1px solid #e1e8ed;
        text-align: right;
    }

    th {
        background: linear-gradient(135deg, #4a6b4a 0%, #5a8a5a 100%);
        color: #fff;
        position: sticky;
        top: 0;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-size: 13px;
    }

    tr:hover {
        background: #f8f9fa;
    }

    /* ===== Loan History ===== */
    .loan-history-item {
        background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
        padding: 20px;
        border-radius: 12px;
        border: 2px solid #e1e8ed;
        margin-bottom: 16px;
        transition: all 0.3s ease;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    }

    .loan-history-item:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.1);
    }

    .loan-details {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
        margin-top: 12px;
    }

    .loan-details>div {
        padding: 8px;
        background: #f8f9fa;
        border-radius: 6px;
        transition: background 0.3s ease;
    }

    .loan-details>div:hover {
        background: #e9ecef;
    }

    /* ===== Eligibility Check ===== */
    .eligibility-section {
        background: linear-gradient(135deg, #f0f8ff 0%, #e6f3ff 100%);
        border: 2px solid #3498db;
        border-radius: 12px;
        padding: 24px;
        margin-bottom: 24px;
        box-shadow: 0 6px 20px rgba(52, 152, 219, 0.15);
    }

    .eligibility-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-top: 20px;
    }

    .eligibility-result {
        margin-top: 24px;
        padding: 20px;
        border-radius: 10px;
        font-weight: 600;
        font-size: 16px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    }

    .eligible {
        background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
        color: #155724;
        border: 2px solid #28a745;
    }

    .not-eligible {
        background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
        color: #721c24;
        border: 2px solid #dc3545;
    }

    .calculation-breakdown {
        background: #fff;
        padding: 20px;
        border-radius: 10px;
        margin-top: 16px;
        border: 2px solid #dee2e6;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    }

    .breakdown-item {
        display: flex;
        justify-content: space-between;
        padding: 10px 0;
        border-bottom: 1px solid #e9ecef;
        transition: background 0.3s ease;
    }

    .breakdown-item:hover {
        background: #f8f9fa;
        padding-left: 8px;
        padding-right: 8px;
        margin: 0 -8px;
        border-radius: 6px;
    }

    .breakdown-item:last-child {
        border-bottom: none;
    }

    .breakdown-total {
        font-weight: 700;
        font-size: 18px;
        color: #2c3e50;
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        margin-top: 8px;
        padding: 12px !important;
        border-radius: 6px;
    }

    .warning-text {
        color: #856404;
        background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
        padding: 12px;
        border-radius: 8px;
        margin-top: 12px;
        border-left: 4px solid #ffc107;
    }

    /* ===== Password Section ===== */
    .password-section {
        background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
        border: 2px solid #dee2e6;
        border-radius: 12px;
        padding: 24px;
        margin-top: 24px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    }

    .password-requirements {
        font-size: 13px;
        color: #6c757d;
        margin-top: 8px;
        padding: 8px;
        background: #f8f9fa;
        border-radius: 6px;
    }

    .password-strength {
        height: 6px;
        margin-top: 8px;
        border-radius: 3px;
        background-color: #e9ecef;
        transition: all 0.3s ease;
    }

    .password-strength-weak {
        background: linear-gradient(90deg, #dc3545 0%, #c82333 100%);
        width: 33%;
    }

    .password-strength-medium {
        background: linear-gradient(90deg, #ffc107 0%, #e0a800 100%);
        width: 66%;
    }

    .password-strength-strong {
        background: linear-gradient(90deg, #28a745 0%, #218838 100%);
        width: 100%;
    }

    .password-match {
        margin-top: 5px;
        font-size: 13px;
        padding: 8px;
        border-radius: 4px;
    }

    .password-match.valid {
        color: #27ae60;
        background: #f0fdf4;
        border-left: 4px solid #27ae60;
    }

    .password-match.invalid {
        color: #e74c3c;
        background: #fdf2f2;
        border-left: 4px solid #e74c3c;
    }

    /* ===== Payment Schedule Section ===== */
    .payment-schedule-section {
        margin-top: 24px;
        border: 2px solid #e1e8ed;
        border-radius: 12px;
        padding: 20px;
        background: linear-gradient(135deg, #f9f9f9 0%, #ffffff 100%);
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.08);
    }

    .schedule-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }

    .schedule-info {
        font-size: 14px;
        color: #6c757d;
        font-weight: 500;
    }

    .schedule-notice {
        background: linear-gradient(135deg, #d1ecf1 0%, #bee5eb 100%);
        color: #0c5460;
        padding: 12px;
        border-radius: 8px;
        margin-bottom: 16px;
        border-left: 4px solid #0c5460;
        font-weight: 500;
    }

    /* ===== Payment History Section ===== */
    .payment-history-section {
        margin-top: 24px;
        border: 2px solid #e1e8ed;
        border-radius: 12px;
        padding: 20px;
        background: linear-gradient(135deg, #f9f9f9 0%, #ffffff 100%);
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.08);
    }

    .payment-summary {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 20px;
        margin-bottom: 20px;
    }

    .summary-card {
        background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
        border-radius: 10px;
        padding: 16px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        text-align: center;
    }

    .summary-card h4 {
        margin: 0 0 10px 0;
        color: #4a6b4a;
        font-size: 16px;
    }

    .summary-card .amount {
        font-size: 24px;
        font-weight: bold;
        color: #2c3e50;
    }

    .payment-filters {
        display: flex;
        gap: 15px;
        margin-bottom: 20px;
        flex-wrap: wrap;
    }

    .filter-group {
        display: flex;
        flex-direction: column;
        min-width: 200px;
    }

    .filter-group label {
        font-size: 14px;
        margin-bottom: 5px;
        text-transform: none;
        letter-spacing: normal;
    }

    .filter-group select {
        padding: 8px 12px;
        border-radius: 6px;
        border: 1px solid #e1e8ed;
    }

    .payment-table-container {
        overflow-x: auto;
        margin-top: 20px;
    }

    .payment-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
    }

    .payment-table th {
        background: linear-gradient(135deg, #4a6b4a 0%, #5a8a5a 100%);
        color: white;
        padding: 12px;
        text-align: left;
        position: sticky;
        top: 0;
    }

    .payment-table td {
        padding: 12px;
        border-bottom: 1px solid #e1e8ed;
    }

    .payment-table tr:hover {
        background-color: #f8f9fa;
    }

    .loan-badge {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: bold;
        margin-right: 5px;
    }

    .loan-badge.mortgage {
        background-color: #e6f0ff;
        color: #0066cc;
    }

    .loan-badge.car {
        background-color: #fff2e6;
        color: #ff8800;
    }

    .payment-source-badge {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: bold;
    }

    .payment-source-badge.salary {
        background-color: #e6f7e6;
        color: #009900;
    }

    .payment-source-badge.bank {
        background-color: #e6f0ff;
        color: #0066cc;
    }

    .payment-source-badge.cash {
        background-color: #fff9e6;
        color: #cc9900;
    }

    .no-payments {
        text-align: center;
        padding: 40px;
        color: #6c757d;
        font-style: italic;
    }

    /* ===== Toast Notification ===== */
    .toast {
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
        font-weight: 500;
        max-width: 350px;
    }

    .toast.show {
        transform: translateY(0);
        opacity: 1;
    }

    .toast.error {
        background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
    }

    .toast.success {
        background: linear-gradient(135deg, #2ecc71 0%, #27ae60 100%);
    }

    /* ===== Loading State ===== */
    .loading-content {
        background: white;
        padding: 40px;
        border-radius: 12px;
        text-align: center;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    }

    .loading-content::after {
        content: '';
        display: inline-block;
        width: 40px;
        height: 40px;
        border: 4px solid #f3f3f3;
        border-top: 4px solid #3498db;
        border-radius: 50%;
        animation: spin 1s linear infinite;
        margin-top: 16px;
    }

    @keyframes spin {
        0% {
            transform: rotate(0deg);
        }

        100% {
            transform: rotate(360deg);
        }
    }

    /* ===== Responsive Design ===== */
    @media (max-width: 1024px) {

        .calculator-form,
        .eligibility-grid,
        .payment-summary {
            grid-template-columns: 1fr;
        }

        .loan-details {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 760px) {
        header {
            position: relative;
            height: auto;
            padding: 1rem;
            z-index: 1001;
            justify-content: space-between;
        }

        .header-left {
            display: flex;
            align-items: center;
        }

        .hamburger {
            display: flex;
        }

        .header-container {
            padding-left: 0;
            /* Remove padding for mobile */
            flex-direction: column;
            gap: 0.75rem;
            padding: 1rem 0;
            flex: 1;
        }

        header h1 {
            font-size: 22px;
        }

        .logo {
            height: 45px;
            width: 45px;
        }

        .profile-badge {
            position: relative;
            right: auto;
            top: auto;
            transform: none;
            justify-content: center;
            margin-top: 10px;
            padding: 8px 15px;
        }

        .profile-info {
            margin-right: 10px;
        }

        .profile-name {
            font-size: 0.8rem;
        }

        .profile-role {
            font-size: 0.7rem;
        }

        .logout-btn {
            padding: 0.8rem;
            font-size: 0.8rem;
        }

        .main-content {
            margin-left: 0;
            margin-top: 0;
            /* Remove margin top for mobile */
            padding: 1rem;
        }

        .sidebar {
            transform: translateX(-100%);
            /* Hidden by default on mobile */
        }

        .sidebar.open {
            transform: translateX(0);
            /* Shown when hamburger is active */
        }

        .sidebar.collapsed {
            transform: translateX(-100%);
            /* Still collapsed when open to show only icons */
        }

        .main-content {
            margin-left: 0;
            /* No sidebar margin on mobile */
        }

        .main-content.sidebar-open {
            margin-left: 0;
            /* No sidebar margin when sidebar is open */
        }

        main {
            padding: 20px;
            border-radius: 0;
        }

        h2 {
            font-size: 24px;
        }

        .payment-schedule-section,
        .payment-history-section {
            padding: 16px;
        }

        .table-container,
        .payment-table-container {
            max-height: 300px;
        }

        th,
        td {
            padding: 8px;
            font-size: 12px;
        }

        .toast {
            left: 20px;
            right: 20px;
            max-width: none;
        }

        .payment-filters {
            flex-direction: column;
        }

        .filter-group {
            width: 100%;
        }
    }

    @media (max-width: 480px) {
        .header-container {
            gap: 0.5rem;
        }

        header h1 {
            font-size: 20px;
        }

        .logo {
            height: 40px;
            width: 40px;
        }

        main {
            padding: 16px;
        }

        h2 {
            font-size: 22px;
        }

        button {
            padding: 10px 20px;
            font-size: 14px;
        }

        input,
        select,
        textarea {
            padding: 10px 12px;
            font-size: 14px;
        }
    }

    /* ===== Print Styles ===== */
    @media print {

        header,
        .sidebar {
            display: none;
        }

        .main-content {
            margin: 0;
            padding: 0;
        }

        main {
            box-shadow: none;
            border: none;
        }

        button,
        .toast {
            display: none;
        }
    }

    .return-staff-btn {
        background: linear-gradient(135deg, #6b4a3a 0%, #5a3a2a 100%);
        color: white;
        border: none;
        cursor: pointer;
        padding: 12px 20px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 500;
        margin-top: 20px;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .return-staff-btn:hover {
        background: linear-gradient(135deg, #5a3a2a 0%, #4a2a2a 100%);
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
    }

    .return-staff-btn:active {
        transform: translateY(0);
    }

    /* Profile Dropdown Styles */
    .profile-dropdown {
        position: absolute;
        right: 20px;
        top: 50%;
        transform: translateY(-50%);
    }

    .profile-button {
        display: flex;
        align-items: center;
        background-color: rgba(255, 255, 255, 0.1);
        border: none;
        border-radius: 30px;
        padding: 5px 15px;
        cursor: pointer;
        transition: background-color 0.3s;
    }

    .profile-button:hover {
        background-color: rgba(255, 255, 255, 0.2);
    }

    .profile-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        border: 2px solid #fff;
        margin-right: 10px;
    }

    .profile-name {
        color: white;
        font-weight: bold;
        font-size: 0.9rem;
        margin-right: 10px;
    }

    .dropdown-arrow {
        color: white;
        font-size: 0.8rem;
        transition: transform 0.3s;
    }

    .profile-button.active .dropdown-arrow {
        transform: rotate(180deg);
    }

    .dropdown-menu {
        position: absolute;
        top: 100%;
        right: 0;
        background-color: white;
        border-radius: 8px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        min-width: 180px;
        z-index: 1000;
        opacity: 0;
        visibility: hidden;
        transform: translateY(10px);
        transition: all 0.3s ease;
        margin-top: 10px;
    }

    .dropdown-menu.show {
        opacity: 1;
        visibility: visible;
        transform: translateY(0);
    }

    .dropdown-item {
        display: flex;
        align-items: center;
        padding: 12px 15px;
        color: #2c3e50;
        text-decoration: none;
        transition: background-color 0.2s;
    }

    .dropdown-item:hover {
        background-color: #f8f9fa;
    }

    .dropdown-item:first-child {
        border-radius: 8px 8px 0 0;
    }

    .dropdown-item:last-child {
        border-radius: 0 0 8px 8px;
    }

    .dropdown-icon {
        margin-right: 10px;
        font-size: 1rem;
    }

    .logout-item {
        color: white;
        background: #c0392b;
        border-radius: 0 0 8px 8px;
    }

    .logout-item:hover {
        background: #a0281b;
    }

    /* Update responsive styles for profile dropdown */
    @media (max-width: 760px) {
        .profile-dropdown {
            position: relative;
            right: auto;
            top: auto;
            transform: none;
            margin-top: 10px;
        }

        .header-container {
            flex-direction: column;
            gap: 0.75rem;
            padding: 1rem 0;
        }
    }

    /* Schedule Filters Styles */
    .schedule-filters {
        display: flex;
        gap: 15px;
        margin-bottom: 20px;
        flex-wrap: wrap;
        padding: 15px;
        background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
        border-radius: 10px;
        border: 1px solid #e1e8ed;
    }

    .schedule-filters .filter-group {
        display: flex;
        flex-direction: column;
        min-width: 200px;
    }

    .schedule-filters .filter-group label {
        font-size: 14px;
        margin-bottom: 5px;
        text-transform: none;
        letter-spacing: normal;
    }

    .schedule-filters .filter-group select {
        padding: 8px 12px;
        border-radius: 6px;
        border: 1px solid #e1e8ed;
    }

    .schedule-filters button {
        margin-top: 0;
        padding: 8px 15px;
        font-size: 14px;
    }

    #scheduleTableContainer {
        margin-top: 20px;
    }
    </style>
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

            <img src="logo.jpg" alt="Company Logo" class="logo">
            <h1>Loanee's Portal</h1>

            <!-- Profile Dropdown Menu -->
            <div class="profile-dropdown">
                <button class="profile-button" id="profileButton">
                    <img src="https://picsum.photos/seed/loanee123/40/40.jpg" alt="User" class="profile-avatar">
                    <span class="profile-name">
                        <?php echo htmlspecialchars($user_name ?: 'Loanee'); ?>
                    </span>
                    <span class="dropdown-arrow">▼</span>
                </button>

                <div class="dropdown-menu" id="profileDropdown">
                    <a href="#" class="dropdown-item" id="profileMenuItem">
                        <span class="dropdown-icon">👤</span>
                        Profile
                    </a>
                    <a href="#" class="dropdown-item" id="securityMenuItem">
                        <span class="dropdown-icon">🔒</span>
                        Security
                    </a>
                    <a href="#" class="dropdown-item logout-item" id="profileLogoutBtn">
                        <span class="dropdown-icon">🚪</span>
                        Logout
                    </a>
                </div>
            </div>
        </div>
    </header>
    <aside class="sidebar" id="sidebar">
        <!-- Sidebar Logo -->
        <div class="sidebar-logo">
            <img src="logo.jpg" alt="Company Logo">
            <div class="sidebar-logo-text">Loanee Portal</div>
        </div>

        <!-- Sidebar Buttons -->
        <button class="active tooltip" data-section="dashboard">
            <span class="button-icon">📊</span>
            <span class="button-text">Dashboard</span>
            <span class="tooltiptext">Dashboard</span>
        </button>
        <button class="tooltip" data-section="calculator">
            <span class="button-icon">🧮</span>
            <span class="button-text">Calculator</span>
            <span class="tooltiptext">Loan Calculator</span>
        </button>
        <button class="tooltip" data-section="history">
            <span class="button-icon">📜</span>
            <span class="button-text">Loan History</span>
            <span class="tooltiptext">Application History</span>
        </button>

        <button class="tooltip" data-section="payments">
            <span class="button-icon">💳</span>
            <span class="button-text">Payment History</span>
            <span class="tooltiptext">Payment History</span>
        </button>

        <button class="tooltip" data-section="payment-schedule">
            <span class="button-icon">📅</span>
            <span class="button-text">Payment Schedule</span>
            <span class="tooltiptext">View Payment Schedule</span>
        </button>

        <button class="tooltip" data-section="newapp">
            <span class="button-icon">📝</span>
            <span class="button-text">New Application</span>
            <span class="tooltiptext">Apply for Loan</span>
        </button>
        <button class="tooltip" data-section="profile">
            <span class="button-icon">👤</span>
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
            <span class="button-icon">↩️</span>
            RETURN TO <?php echo strtoupper($_SESSION['staff_original_role'] ?? 'STAFF'); ?> PORTAL
        </a>
        <?php endif; ?>

        <button class="tooltip" id="logoutBtn" style="margin-top: auto; background: #c0392b;">
            <span class="button-icon">🚪</span>
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
                <div id="historyContainer"></div>
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
                    <h3>📋 Loan Eligibility Pre-Check</h3>
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
                                    dispatchment<br>
                                    • Mortgage loans must be fully repaid <strong>within 10 years</strong> after
                                    loan
                                    dispatchment
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

    // ==========================================
    // API Configuration
    // ==========================================
    function getApiBaseUrl() {
        const hostname = window.location.hostname;
        const pathname = window.location.pathname;
        const basePath = pathname.substring(0, pathname.lastIndexOf('/'));

        if (hostname === 'localhost' || hostname === '127.0.0.1') {
            // Try multiple possible localhost paths
            const possiblePaths = [
                'http://localhost/loan-system/api',
                'http://localhost/loan/api',
                'http://localhost/api',
                'http://localhost/loans/api'
            ];

            // Return the first one that matches the current path
            for (const path of possiblePaths) {
                if (pathname.includes(path.split('/api')[0])) {
                    //console.log('Using API path:', path);
                    return path;
                }
            }

            // Default fallback
            //console.log('Using default API path:', possiblePaths[0]);
            return possiblePaths[0];
        } else {
            const protocol = window.location.protocol;
            return `${protocol}//${hostname}/loan-system/api`;
        }
    }

    const API_BASE_URL = getApiBaseUrl();
    //console.log('API Base URL:', API_BASE_URL);

    // ==========================================
    // DOM Elements
    // ==========================================
    // Sections
    const dashboardSection = document.getElementById('dashboardSection');
    const calculatorSection = document.getElementById('calculatorSection');
    const historySection = document.getElementById('historySection');
    const paymentsSection = document.getElementById('paymentsSection');
    const paymentScheduleSection = document.getElementById('paymentScheduleSection');
    const newAppSection = document.getElementById('newAppSection');
    const profileSection = document.getElementById('profileSection');

    // Dashboard Elements
    const userNameElement = document.getElementById('userName');
    const userPfElement = document.getElementById('userPf');
    const latestLoan = document.getElementById('latestLoan');
    const noLoan = document.getElementById('noLoan');
    const fixedRateDisplay = document.getElementById('fixedRateDisplay');

    // Calculator Elements 
    const calcAmount = document.getElementById('calcAmount');
    const calcTerm = document.getElementById('calcTerm');
    const calcBtn = document.getElementById('calcBtn');
    const calcSummary = document.getElementById('calcSummary');
    const sumAmount = document.getElementById('sumAmount');
    const sumRate = document.getElementById('sumRate');
    const sumTerm = document.getElementById('sumTerm');
    const sumMonthly = document.getElementById('sumMonthly');
    const sumInterest = document.getElementById('sumInterest');
    const sumTotal = document.getElementById('sumTotal');
    const amortContainer = document.getElementById('amortContainer');
    const amortBody = document.getElementById('amortBody');

    // Eligibility Check Elements
    const eligibilityCheck = document.getElementById('eligibilityCheck');
    const checkLoanAmount = document.getElementById('checkLoanAmount');
    const checkLoanTerm = document.getElementById('checkLoanTerm');
    const checkBasicSalary = document.getElementById('checkBasicSalary');
    const checkCurrentNetSalary = document.getElementById('checkCurrentNetSalary');
    const checkEligibilityBtn = document.getElementById('checkEligibilityBtn');
    const eligibilityResult = document.getElementById('eligibilityResult');
    const breakdownBox = document.getElementById('breakdownBox');
    const breakdownSalary = document.getElementById('breakdownSalary');
    const breakdownMaxAllowed = document.getElementById('breakdownMaxAllowed');
    const breakdownCurrentNet = document.getElementById('breakdownCurrentNet');
    const breakdownNewLoan = document.getElementById('breakdownNewLoan');
    const breakdownNewNet = document.getElementById('breakdownNewNet');
    const breakdownRemaining = document.getElementById('breakdownRemaining');

    // Loan Application Elements
    const precheckLoanType = document.getElementById('precheckLoanType');
    const precheckPropertyValue = document.getElementById('precheckPropertyValue');
    const precheckCarValue = document.getElementById('precheckCarValue');
    const checkPropertyValue = document.getElementById('checkPropertyValue');
    const checkCarValue = document.getElementById('checkCarValue');
    const precheckRetirementDetails = document.getElementById('precheckRetirementDetails');
    const precheckRetirementDate = document.getElementById('precheckRetirementDate');
    const precheckEmploymentType = document.getElementById('precheckEmploymentType');
    const precheckContractDetails = document.getElementById('precheckContractDetails');
    const precheckContractEnd = document.getElementById('precheckContractEnd');

    // Loan Application Form Elements
    const historyContainer = document.getElementById('historyContainer');
    const loanType = document.getElementById('loanType');
    const mortgageFields = document.getElementById('mortgageFields');
    const carFields = document.getElementById('carFields');
    const expectedLoan = document.getElementById('expectedLoan');
    const paymentYears = document.getElementById('paymentYears');
    const basicSalary = document.getElementById('basicSalary');
    const employmentType = document.getElementById('employmentType');
    const contractDetails = document.getElementById('contractDetails');
    const bankName = document.getElementById('bankName');
    const bankBranch = document.getElementById('bankBranch');
    const bankAccount = document.getElementById('bankAccount');
    const loanForm = document.getElementById('loanForm');
    const loanSuccess = document.getElementById('loanSuccess');
    const cancelLoan = document.getElementById('cancelLoan');

    // Profile & Security Elements
    const passwordForm = document.getElementById('passwordForm');
    const newPassword = document.getElementById('new_password');
    const passwordStrength = document.getElementById('passwordStrength');

    // Payment History Elements
    const loanFilter = document.getElementById('loanFilter');
    const yearFilter = document.getElementById('yearFilter');
    const sourceFilter = document.getElementById('sourceFilter');
    const paymentsTableBody = document.getElementById('paymentsTableBody');
    const totalPayments = document.getElementById('totalPayments');
    const totalPrincipalPaid = document.getElementById('totalPrincipalPaid');
    const totalInterestPaid = document.getElementById('totalInterestPaid');

    // Payment Schedule Elements
    const paymentScheduleContainer = document.getElementById('paymentScheduleContainer');

    // Hamburger and sidebar elements
    const hamburger = document.getElementById('hamburgerMenu');
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');
    const profileBadge = document.querySelector('.profile-badge');
    const profileAvatar = document.querySelector('.profile-avatar');

    // ==========================================
    // Utility Functions
    // ==========================================
    const fmtCurrency = v => new Intl.NumberFormat('en-KE', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
    }).format(Math.ceil(v));

    const fmtDate = d => new Date(d).toLocaleDateString('en-GB', {
        year: 'numeric',
        month: 'short',
        day: '2-digit'
    });

    function isValidAccountNumber(ac) {
        return /^[0-9]{8,16}$/.test(ac);
    }

    function checkPasswordStrength(password) {
        if (!password) return '';

        let strength = 0;
        const checks = {
            length: password.length >= 8,
            longLength: password.length >= 12,
            lowercase: /[a-z]/.test(password),
            uppercase: /[A-Z]/.test(password),
            number: /[0-9]/.test(password),
            special: /[^a-zA-Z0-9]/.test(password),
            noSpaces: !/\s/.test(password)
        };

        // Calculate strength
        if (checks.length) strength += 1;
        if (checks.longLength) strength += 1;
        if (checks.lowercase) strength += 1;
        if (checks.uppercase) strength += 1;
        if (checks.number) strength += 1;
        if (checks.special) strength += 1;

        // Determine level
        if (strength < 3) return 'weak';
        if (strength < 5) return 'medium';
        return 'strong';
    }

    function updatePasswordStrengthIndicator(password) {
        const strength = checkPasswordStrength(password);
        passwordStrength.className = 'password-strength';
        if (strength === 'weak') {
            passwordStrength.classList.add('password-strength-weak');
        } else if (strength === 'medium') {
            passwordStrength.classList.add('password-strength-medium');
        } else if (strength === 'strong') {
            passwordStrength.classList.add('password-strength-strong');
        }
    }

    // ==========================================
    // API Helper Functions
    // ==========================================
    async function apiRequest(endpoint, method = 'GET', data = null) {
        // Remove any leading slashes from endpoint
        const cleanEndpoint = endpoint.replace(/^\/+/, '');

        // Construct full URL - ensure single slash between base and endpoint
        let url = `${API_BASE_URL}/${cleanEndpoint}`.replace(/([^:]\/)\/+/g, "$1");

        // Add viewing_as_loanee flag for GET requests
        if (method === 'GET' && currentUser && currentUser.viewing_as_loanee) {
            url += (url.includes('?') ? '&' : '?') + 'viewing_as_loanee=true';
        }

        //console.log('🌐 API Request:', method, url);

        const headers = {
            'Content-Type': 'application/json'
        };

        const options = {
            method,
            headers,
            credentials: 'include'
        };

        if (data && method !== 'GET') {
            // Make sure we're sending properly formatted JSON
            const requestData = {
                ...data,
                csrf_token: data.csrf_token || csrfToken
            };

            options.body = JSON.stringify(requestData);
        }

        try {
            const response = await fetch(url, options);

            //console.log('📡 Response Status:', response.status, response.statusText);

            // Get response text first
            const responseText = await response.text();
            //console.log('📄 Raw Response:', responseText.substring(0, 200));

            if (!response.ok) {
                //console.error('❌ API Error Response:', responseText);
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            // Check if response is JSON
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                //console.error('❌ Non-JSON response received');
                //console.error('Content-Type:', contentType);
                //console.error('Response text:', responseText);
                throw new Error('Server returned non-JSON response');
            }

            // Parse JSON
            let result;
            try {
                result = JSON.parse(responseText);
            } catch (parseError) {
                //console.error('❌ JSON Parse Error:', parseError);
                //console.error('Response text:', responseText);
                throw new Error('Failed to parse JSON response');
            }

            //console.log('✅ API Response:', result);

            // Check for success property and handle accordingly
            if (!result.success) {
                throw new Error(result.message || 'Request failed');
            }

            return result;
        } catch (error) {
            //console.error('❌ API Error:', error);
            throw error;
        }
    }

    // ==========================================
    // Authentication Functions
    // ==========================================
    async function logout() {
        try {
            await apiRequest('auth.php', 'POST', {
                action: 'logout',
                csrf_token: csrfToken
            });
            return true;
        } catch (error) {
            //console.error('Logout error:', error);
            // Still return true to proceed with redirect
            return true;
        }
    }

    // ==========================================
    // Enhanced Eligibility Check
    // ==========================================
    function checkLoanEligibility() {
        const loanAmount = parseFloat(checkLoanAmount.value) || 0;
        const loanTerm = parseFloat(checkLoanTerm.value) || 0;
        const salary = parseFloat(checkBasicSalary.value) || 0;
        const currentNetSalary = parseFloat(checkCurrentNetSalary.value) || 0;
        const empType = precheckEmploymentType.value;
        const selectedLoanType = precheckLoanType.value;

        // Validate basic required fields
        if (loanAmount <= 0 || loanTerm <= 0 || salary <= 0 || currentNetSalary <= 0 || !empType || !
            selectedLoanType) {
            eligibilityResult.innerHTML =
                '<span style="color: #856404;">⚠️ Please fill in all required fields with valid values.</span>';
            eligibilityResult.classList.remove('hidden', 'eligible', 'not-eligible');
            breakdownBox.classList.add('hidden');
            return;
        }

        // Validate loan term based on loan type
        if (selectedLoanType === 'car' && loanTerm > 5) {
            eligibilityResult.innerHTML = `
❌ <strong>NOT ELIGIBLE - Car Loan Term Exceeds Limit</strong><br><br>
Car loans must be paid within a period of 5 years or less.<br>
Your requested term is ${loanTerm} years.<br><br>
<strong>Suggestion:</strong> Please reduce your loan term to 5 years or less.
`;
            eligibilityResult.classList.remove('hidden', 'eligible');
            eligibilityResult.classList.add('not-eligible');
            breakdownBox.classList.add('hidden');
            return;
        }

        if (selectedLoanType === 'mortgage' && loanTerm > 10) {
            eligibilityResult.innerHTML = `
❌ <strong>NOT ELIGIBLE - Mortgage Loan Term Exceeds Limit</strong><br><br>
Mortgage loans must be paid within a period of 10 years or less.<br>
Your requested term is ${loanTerm} years.<br><br>
<strong>Suggestion:</strong> Please reduce your loan term to 10 years or less.
`;
            eligibilityResult.classList.remove('hidden', 'eligible');
            eligibilityResult.classList.add('not-eligible');
            breakdownBox.classList.add('hidden');
            return;
        }

        // Validate loan amount against property/car value
        if (selectedLoanType === 'mortgage') {
            const propertyValue = parseFloat(checkPropertyValue.value) || 0;
            if (propertyValue <= 0) {
                eligibilityResult.innerHTML =
                    '<span style="color: #856404;">⚠️ Please enter property value.</span>';
                eligibilityResult.classList.remove('hidden', 'eligible', 'not-eligible');
                breakdownBox.classList.add('hidden');
                return;
            }
            if (loanAmount > propertyValue) {
                eligibilityResult.innerHTML = `
❌ <strong>NOT ELIGIBLE - Loan Amount Exceeds Property Value</strong><br><br>
You are requesting a loan of <strong>KES ${fmtCurrency(loanAmount)}</strong>, 
but the property value is only <strong>KES ${fmtCurrency(propertyValue)}</strong>.<br><br>
<strong>Maximum Loan Amount:</strong> KES ${fmtCurrency(propertyValue)}<br><br>
Please reduce your loan amount to match or be less than the property value.
`;
                eligibilityResult.classList.remove('hidden', 'eligible');
                eligibilityResult.classList.add('not-eligible');
                breakdownBox.classList.add('hidden');
                return;
            }
        } else if (selectedLoanType === 'car') {
            const carValue = parseFloat(checkCarValue.value) || 0;
            if (carValue <= 0) {
                eligibilityResult.innerHTML =
                    '<span style="color: #856404;">⚠️ Please enter car value.</span>';
                eligibilityResult.classList.remove('hidden', 'eligible', 'not-eligible');
                breakdownBox.classList.add('hidden');
                return;
            }
            if (loanAmount > carValue) {
                eligibilityResult.innerHTML = `
❌ <strong>NOT ELIGIBLE - Loan Amount Exceeds Car Value</strong><br><br>
You are requesting a loan of <strong>KES ${fmtCurrency(loanAmount)}</strong>, 
but the car value is only <strong>KES ${fmtCurrency(carValue)}</strong>.<br><br>
<strong>Maximum Loan Amount:</strong> KES ${fmtCurrency(carValue)}<br><br>
Please reduce your loan amount to match or be less than the car value.
`;
                eligibilityResult.classList.remove('hidden', 'eligible');
                eligibilityResult.classList.add('not-eligible');
                breakdownBox.classList.add('hidden');
                return;
            }
        }

        // Validate for permanent employees (retirement date)
        if (empType === 'permanent') {
            const retirementDate = precheckRetirementDate.value;
            if (!retirementDate) {
                eligibilityResult.innerHTML =
                    '<span style="color: #856404;">⚠️ Please enter your expected retirement date.</span>';
                eligibilityResult.classList.remove('hidden', 'eligible', 'not-eligible');
                breakdownBox.classList.add('hidden');
                return;
            }

            const retirementValidation = validateRetirementForLoan(retirementDate, loanTerm, selectedLoanType);
            if (!retirementValidation.isValid) {
                eligibilityResult.innerHTML = retirementValidation.message;
                eligibilityResult.classList.remove('hidden', 'eligible');
                eligibilityResult.classList.add('not-eligible');
                breakdownBox.classList.add('hidden');
                return;
            }
        }

        // Validate for contracted employees (contract end date)
        if (empType === 'contracted') {
            const contractEnd = precheckContractEnd.value;
            if (!contractEnd) {
                eligibilityResult.innerHTML =
                    '<span style="color: #856404;">⚠️ Please enter your contract end date.</span>';
                eligibilityResult.classList.remove('hidden', 'eligible', 'not-eligible');
                breakdownBox.classList.add('hidden');
                return;
            }

            const contractValidation = validateContractForLoan(contractEnd, loanTerm);
            if (!contractValidation.isValid) {
                eligibilityResult.innerHTML = contractValidation.message;
                eligibilityResult.classList.remove('hidden', 'eligible');
                eligibilityResult.classList.add('not-eligible');
                breakdownBox.classList.add('hidden');
                return;
            }
        }

        // Calculate monthly payment
        const monthlyRate = (currentInterestRate / 100) / 12;
        const totalMonths = Math.round(loanTerm * 12);

        let monthlyPayment;
        if (monthlyRate === 0) {
            monthlyPayment = Math.ceil(loanAmount / totalMonths);
        } else {
            monthlyPayment = Math.ceil(loanAmount * (monthlyRate * Math.pow(1 + monthlyRate, totalMonths)) /
                (Math.pow(1 + monthlyRate, totalMonths) - 1));
        }

        // Calculate new net salary after loan payment
        const newNetSalary = currentNetSalary - monthlyPayment;
        const oneThirdSalary = Math.ceil(salary / 3);

        // Display breakdown
        breakdownSalary.textContent = fmtCurrency(salary);
        breakdownMaxAllowed.textContent = fmtCurrency(oneThirdSalary);
        breakdownCurrentNet.textContent = fmtCurrency(currentNetSalary);
        breakdownNewLoan.textContent = fmtCurrency(monthlyPayment);
        breakdownNewNet.textContent = fmtCurrency(newNetSalary);
        breakdownRemaining.textContent = fmtCurrency(newNetSalary);
        breakdownBox.classList.remove('hidden');

        eligibilityResult.classList.remove('hidden');

        // Check if eligible based on new net salary
        if (newNetSalary >= oneThirdSalary) {
            eligibilityResult.className = 'eligibility-result eligible';
            eligibilityResult.innerHTML = `
✅ <strong>You are ELIGIBLE for this loan!</strong><br>
Your new net salary of KES ${fmtCurrency(newNetSalary)} is greater than one-third of your basic salary.<br>
<button id="proceedToApplication" style="margin-top:10px">Proceed to Full Application</button>
`;

            // Add event listener for proceed button
            const proceedBtn = document.getElementById('proceedToApplication');
            if (proceedBtn) {
                proceedBtn.addEventListener('click', function() {
                    // Pre-fill and make fields read-only
                    expectedLoan.value = loanAmount;
                    expectedLoan.readOnly = true;
                    expectedLoan.style.backgroundColor = '#f0f0f0';
                    expectedLoan.style.cursor = 'not-allowed';

                    paymentYears.value = loanTerm;
                    paymentYears.readOnly = true;
                    paymentYears.style.backgroundColor = '#f0f0f0';
                    paymentYears.style.cursor = 'not-allowed';

                    basicSalary.value = salary;
                    basicSalary.readOnly = true;
                    basicSalary.style.backgroundColor = '#f0f0f0';
                    basicSalary.style.cursor = 'not-allowed';

                    // Set loan type
                    loanType.value = selectedLoanType;
                    loanType.dispatchEvent(new Event('change'));

                    // Set property/car values
                    if (selectedLoanType === 'mortgage') {
                        const propertyValueInput = document.getElementById('propertyValue');
                        if (propertyValueInput) {
                            propertyValueInput.value = checkPropertyValue.value;
                        }
                    } else if (selectedLoanType === 'car') {
                        const carValueInput = document.getElementById('carValue');
                        if (carValueInput) {
                            carValueInput.value = checkCarValue.value;
                        }
                    }

                    employmentType.value = empType;
                    employmentType.dispatchEvent(new Event('change'));

                    if (empType === 'contracted') {
                        contractDetails.classList.remove('hidden');
                        document.getElementById('contractEnd').value = precheckContractEnd.value;
                    }

                    eligibilityCheck.classList.add('hidden');
                    loanForm.classList.remove('hidden');
                });
            }
        } else {
            const shortfall = oneThirdSalary - newNetSalary;
            const maxLoanPayment = Math.max(0, currentNetSalary - oneThirdSalary);

            eligibilityResult.className = 'eligibility-result not-eligible';
            eligibilityResult.innerHTML = `
❌ <strong>NOT ELIGIBLE - New Net Salary Below Minimum</strong><br><br>
Your new net salary would be KES ${fmtCurrency(newNetSalary)}, which is less than the minimum required 
of KES ${fmtCurrency(oneThirdSalary)} by KES ${fmtCurrency(shortfall)}.<br>
<strong>Suggestions:</strong><br>
• Reduce your loan amount to lower monthly payments<br>
• Extend your loan term for lower monthly payments<br>
• Increase your net salary through additional income<br>
• Consider a smaller loan amount<br><br>
Based on your current net salary, the maximum monthly loan payment you can afford is approximately 
<strong>KES ${fmtCurrency(maxLoanPayment)}</strong>.
`;
        }
    }

    // ==========================================
    // Navigation Functions
    // ==========================================
    function showSection(sectionName) {
        //console.log('showSection called:', sectionName);

        // Hide all sections
        document.querySelectorAll('section').forEach(sec => {
            sec.classList.add('hidden');
        });

        // Map data-section values to actual section IDs
        const sectionMap = {
            'dashboard': 'dashboardSection',
            'calculator': 'calculatorSection',
            'history': 'historySection',
            'payments': 'paymentsSection',
            'payment-schedule': 'paymentScheduleSection',
            'newapp': 'newAppSection',
            'profile': 'profileSection'
        };

        const targetId = sectionMap[sectionName];
        const target = document.getElementById(targetId);

        if (target) {
            target.classList.remove('hidden');
            //console.log('Showing section:', targetId);
        } else {
            //console.error('Section not found:', targetId);
        }

        // Update active button
        document.querySelectorAll('#sidebar button').forEach(btn => {
            btn.classList.remove('active');
        });
        const activeBtn = document.querySelector(`#sidebar button[data-section="${sectionName}"]`);
        if (activeBtn) activeBtn.classList.add('active');

        // Load data for the section
        switch (sectionName) {
            case 'dashboard':
                if (typeof loadDashboard === 'function') loadDashboard();
                break;
            case 'history':
                renderLoanHistory();
                break;
            case 'payments':
                renderPaymentHistory();
                break;
            case 'payment-schedule':
                // Fetch all loans to get payment schedules
                fetch('api/loans.php')
                    .then(r => r.json())
                    .then(res => {
                        if (res.success && res.data && res.data.length > 0) {
                            // Load payment schedules for all loans
                            loadPaymentSchedule();
                        } else {
                            document.getElementById('scheduleTableContainer').innerHTML =
                                '<p class="no-data">No loans found. Apply for a loan to view schedules.</p>';
                        }
                    })
                    .catch(err => {
                        document.getElementById('scheduleTableContainer').innerHTML =
                            '<p class="error">Failed to load loans.</p>';
                        //console.error('Loans fetch error:', err);
                    });
                break;
            case 'newapp':
                // Reset eligibility check
                if (document.getElementById('eligibilityCheck')) {
                    document.getElementById('eligibilityCheck').classList.remove('hidden');
                }
                if (document.getElementById('loanForm')) {
                    document.getElementById('loanForm').classList.add('hidden');
                }
                break;
        }

        // Close mobile menu
        if (window.innerWidth <= 760) {
            document.getElementById('hamburgerMenu')?.classList.remove('active');
            document.getElementById('sidebar')?.classList.remove('open');
        }
    }

    // ==========================================
    // Amortization Calculator
    // ==========================================
    function generateAmortizationSchedule(principal, annualRatePercent, years, startDate) {
        const annualRate = Number(annualRatePercent) / 100;
        const monthlyRate = annualRate / 12;
        const totalMonths = Math.max(1, Math.round(Number(years) * 12));

        let monthlyPayment;
        if (monthlyRate === 0) {
            monthlyPayment = principal / totalMonths;
        } else {
            monthlyPayment = principal * (monthlyRate * Math.pow(1 + monthlyRate, totalMonths)) / (Math.pow(1 +
                monthlyRate, totalMonths) - 1);
        }

        let balance = principal;
        const schedule = [];
        const baseDate = startDate ? new Date(startDate) : new Date();

        for (let i = 1; i <= totalMonths; i++) {
            const interestPayment = balance * monthlyRate;
            let principalPayment = monthlyPayment - interestPayment;

            if (i === totalMonths) {
                principalPayment = balance;
                monthlyPayment = principalPayment + interestPayment;
                balance = 0;
            } else {
                balance = Math.max(0, balance - principalPayment);
            }

            const paymentDate = new Date(baseDate.getFullYear(), baseDate.getMonth() + i, baseDate.getDate());

            schedule.push({
                paymentNumber: i,
                paymentDate: fmtDate(paymentDate),
                monthlyPayment: Math.round(monthlyPayment),
                principal: Math.round(principalPayment),
                interest: Math.round(interestPayment),
                balance: Math.round(balance)
            });
        }

        const totalPaid = schedule.reduce((sum, p) => sum + p.monthlyPayment, 0);
        const totalInterest = schedule.reduce((sum, p) => sum + p.interest, 0);

        return {
            schedule,
            monthlyPayment: Math.round(monthlyPayment),
            totalInterest: Math.round(totalInterest),
            totalPaid: Math.round(totalPaid)
        };
    }

    // ==========================================
    // Dashboard Loading
    // ==========================================
    async function loadDashboard() {
        try {
            //console.log('=== 🚀 loadDashboard started ===');
            //console.log('Current user:', currentUser);

            // Safety check
            if (!currentUser || !currentUser.pf_number) {
                //console.error('currentUser is null or missing pf_number');
                showNotification('Session error. Please refresh page.', 'error');
                return;
            }

            // Update user information display
            if (userNameElement) userNameElement.textContent = currentUser.full_name || 'Loanee';
            if (userPfElement) userPfElement.textContent = currentUser.pf_number || 'N/A';
            if (fixedRateDisplay) fixedRateDisplay.textContent = parseFloat(currentInterestRate).toFixed(1);

            // Clear previous content
            if (latestLoan) latestLoan.innerHTML = '';
            if (noLoan) noLoan.classList.add('hidden');

            // Show loading state
            if (latestLoan) {
                latestLoan.innerHTML = '<div class="loading-content">Loading loan data...</div>';
                latestLoan.classList.remove('hidden');
            }

            // Fetch loan applications
            //console.log('📞 Calling API: loans.php');
            const result = await apiRequest('loans.php', 'GET');

            //console.log('✅ API Response:', result);

            if (!result || !result.data) {
                throw new Error('Invalid response from API - missing data property');
            }

            const loans = result.data;
            //console.log(`📊 Total loans received: ${loans.length}`);

            // CRITICAL FIX: Filter loans STRICTLY by current user's PF number
            // This ensures staff members can ONLY see their own loans when viewing as loanee
            const userLoans = loans.filter(loan => {
                const loanPf = String(loan.pf_number || '').trim();
                const currentPf = String(currentUser.pf_number || '').trim();

                // STRICT matching - only by PF number
                const matches = loanPf === currentPf;

                if (matches) {
                    //console.log(`✅ Loan ${loan.application_id} matches PF ${currentPf}`);
                }

                return matches;
            });

            /*console.log(
            `👤 User loans filtered: ${userLoans.length}
             (showing ONLY for PF: ${currentUser.pf_number})`);*/

            // Handle case with no loans
            if (userLoans.length === 0) {
                //console.log('ℹ️ No loans found for user');
                if (latestLoan) latestLoan.classList.add('hidden');
                if (noLoan) noLoan.classList.remove('hidden');
                return;
            }

            // Sort loans by date (newest first)
            userLoans.sort((a, b) => {
                const dateA = new Date(a.application_date || 0).getTime();
                const dateB = new Date(b.application_date || 0).getTime();
                return dateB - dateA;
            });

            // Get most recent loan
            const latest = userLoans[0];
            //console.log('📌 Latest loan:', latest.application_id);

            // Show loan details
            if (latestLoan && noLoan) {
                latestLoan.classList.remove('hidden');
                noLoan.classList.add('hidden');
                displayLoanDetails(latest);
            }
        } catch (error) {
            //console.error('❌ Error loading dashboard:', error);
            showNotification('Error loading dashboard: ' + error.message, 'error');

            if (latestLoan) {
                latestLoan.innerHTML = `
                <div class="error">
                    <strong>Failed to load loan data</strong><br>
                    Error: ${error.message}<br>
                    <button onclick="location.reload()" style="margin-top: 10px;">Reload Page</button>
                </div>
            `;
                latestLoan.classList.remove('hidden');
            }
            if (noLoan) noLoan.classList.add('hidden');
        }
    }

    // Helper function to display loan details
    function displayLoanDetails(latest) {
        try {
            // Determine status and badge color
            const status = String(latest.status || 'pending').toLowerCase();
            let badgeColor = '#fff3cd';
            if (status === 'closed' || status === 'dispatched') badgeColor = '#d4edda';
            else if (status === 'rejected') badgeColor = '#f8d7da';
            else if (status === 'returned') badgeColor = '#ffeaa7';

            // Create loan type specific details
            let loanTypeDetails = '';
            if (latest.loan_type === 'mortgage') {
                loanTypeDetails = `
                    <div><strong>Property Location:</strong> ${latest.property_location || 'N/A'}</div>
                    <div><strong>Property Value:</strong> KES ${fmtCurrency(latest.property_value || 0)}</div>
                `;
            } else if (latest.loan_type === 'car') {
                loanTypeDetails = `
                    <div><strong>Car Type:</strong> ${latest.car_type || 'N/A'}</div>
                    <div><strong>Car Value:</strong> KES ${fmtCurrency(latest.car_value || 0)}</div>
                `;
            }

            // Create employment details
            let employmentDetails = '';
            if (latest.employment_type === 'contracted') {
                employmentDetails = `
                    <div><strong>Contract Period:</strong> ${latest.contract_period || 'N/A'} months</div>
                    <div><strong>Contract Start:</strong> ${latest.contract_start_date ? fmtDate(latest.contract_start_date) : 'N/A'}</div>
                    <div><strong>Contract End:</strong> ${latest.contract_end_date ? fmtDate(latest.contract_end_date) : 'N/A'}</div>
                `;
            }

            // Create processing dates
            let processingDates = '';
            if (latest.cashier_processed_date) {
                processingDates +=
                    `<div><strong>Cashier Processed:</strong> ${fmtDate(latest.cashier_processed_date)}</div>`;
            }
            if (latest.accountant_processed_date) {
                processingDates +=
                    `<div><strong>Accountant Processed:</strong> ${fmtDate(latest.accountant_processed_date)}</div>`;
            }
            if (latest.dispatch_date) {
                processingDates += `<div><strong>Dispatch Date:</strong> ${fmtDate(latest.dispatch_date)}</div>`;
            }
            if (latest.closed_date) {
                processingDates += `<div><strong>Closed Date:</strong> ${fmtDate(latest.closed_date)}</div>`;
            }

            // Create a button to view payment schedule if loan is dispatched
            let paymentScheduleButton = '';
            if (status === 'dispatched' && latest.dispatch_date) {
                paymentScheduleButton = `
                    <div style="margin-top:15px; text-align:center;">
                        <button id="viewPaymentSchedule" class="primary-button" style="background: linear-gradient(135deg, #4a6b4a 0%, #5a8a5a 100%);">
                            View Payment Schedule
                        </button>
                    </div>
                `;
            }

            // Create a button to apply for another loan
            let applyAnotherButton = '';
            if (status === 'dispatched' || status === 'active' || status === 'closed') {
                applyAnotherButton = `
                    <div style="margin-top:15px; text-align:center;">
                        <button id="applyAnotherLoan" class="secondary-button" style="background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);">
                            Apply for Another Loan
                        </button>
                    </div>
                `;
            }

            // Build complete HTML for latest loan
            latestLoan.innerHTML = `
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px">
                    <strong>Latest Application</strong>
                    <span style="padding:6px 12px;border-radius:12px;background:${badgeColor};color:#333">
                        ${latest.status || 'pending'}
                    </span>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px">
                    <div>
                        <h4 style="margin-top:0;margin-bottom:8px;color:#4a6b4a">Application Details</h4>
                        <div><strong>Application Date:</strong> ${latest.application_date ? fmtDate(latest.application_date) : 'N/A'}</div>
                        <div><strong>Full Name:</strong> ${latest.full_name || 'N/A'}</div>
                        <div><strong>ID Number:</strong> ${latest.id_number || 'N/A'}</div>
                        <div><strong>PF Number:</strong> ${latest.pf_number || 'N/A'}</div>
                        <div><strong>Email:</strong> ${latest.email || 'N/A'}</div>
                    </div>
                    
                    <div>
                        <h4 style="margin-top:0;margin-bottom:8px;color:#4a6b4a">Loan Details</h4>
                        <div><strong>Application ID:</strong> ${latest.application_id || 'N/A'}</div>
                        <div><strong>Loan Type:</strong> ${latest.loan_type ? String(latest.loan_type).charAt(0).toUpperCase() + String(latest.loan_type).slice(1) : 'N/A'}</div>
                        <div><strong>Loan Amount:</strong> KES ${fmtCurrency(latest.loan_amount || 0)}</div>
                        <div><strong>Payment Duration:</strong> ${latest.payment_duration || 'N/A'} years</div>
                        <div><strong>Payment Frequency:</strong> ${latest.payment_frequency || 'monthly'}</div>
                        <div><strong>Basic Salary:</strong> KES ${fmtCurrency(latest.basic_salary || 0)}</div>
                        <div><strong>Employment Type:</strong> ${latest.employment_type || 'N/A'}</div>
                    </div>
                </div>

                ${loanTypeDetails ? `
                <div style="margin-top:15px">
                    <h4 style="margin-top:0;margin-bottom:8px;color:#4a6b4a">Loan Type Details</h4>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px">
                        ${loanTypeDetails}
                    </div>
                </div>
                ` : ''}

                ${employmentDetails ? `
                <div style="margin-top:15px">
                    <h4 style="margin-top:0;margin-bottom:8px;color:#4a6b4a">Employment Details</h4>
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:15px">
                        ${employmentDetails}
                    </div>
                </div>
                ` : ''}

                <div style="margin-top:15px">
                    <h4 style="margin-top:0;margin-bottom:8px;color:#4a6b4a">Banking Details</h4>
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:15px">
                        <div><strong>Bank Name:</strong> ${latest.bank_name || 'N/A'}</div>
                        <div><strong>Bank Branch:</strong> ${latest.bank_branch || 'N/A'}</div>
                        <div><strong>Account Number:</strong> ${latest.bank_account_number || 'N/A'}</div>
                    </div>
                </div>

                <div style="margin-top:15px">
                    <h4 style="margin-top:0;margin-bottom:8px;color:#4a6b4a">Processing Timeline</h4>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px">
                        ${processingDates}
                    </div>
                </div>

                ${latest.feedback ? `
                <div style="margin-top:15px">
                    <h4 style="margin-top:0;margin-bottom:8px;color:#4a6b4a">Feedback</h4>
                    <div style="background:#f8f9fa;padding:10px;border-radius:6px;border-left:4px solid #4a6b4a">
                        ${latest.feedback}
                    </div>
                </div>
                ` : ''}

                ${paymentScheduleButton}
                ${applyAnotherButton}
            `;

            // Add event listeners for buttons
            const viewScheduleBtn = document.getElementById('viewPaymentSchedule');
            if (viewScheduleBtn) {
                viewScheduleBtn.addEventListener('click', () => {
                    showSection('payment-schedule');
                });
            }

            const applyAnotherBtn = document.getElementById('applyAnotherLoan');
            if (applyAnotherBtn) {
                applyAnotherBtn.addEventListener('click', () => {
                    showSection('newapp');
                });
            }
        } catch (error) {
            //console.error('Error displaying loan details:', error);
            if (latestLoan) {
                latestLoan.innerHTML = `
                    <div class="error">
                        <strong>Error displaying loan details</strong><br>
                        ${error.message}
                    </div>
                `;
            }
        }
    }

    // ==========================================
    // Payment History Rendering
    // ==========================================
    async function renderPaymentHistory() {
        const container = document.getElementById('paymentsTableBody');
        if (!paymentsTableBody) {
            //console.error('paymentsTableBody not found!');
            return;
        }

        // Show loading state
        paymentsTableBody.innerHTML = `
        <tr>
            <td colspan="9" class="no-payments">
                <div style="padding: 20px;">
                    <div class="loading-indicator"></div>
                    Loading payment history...
                </div>
            </td>
        </tr>
    `;

        try {
            // Safety check for current user
            if (!currentUser || !currentUser.pf_number) {
                //console.error('Current user or PF number is missing');
                paymentsTableBody.innerHTML = `
                <tr>
                    <td colspan="9" class="no-payments">
                        <div style="padding: 20px; text-align: center;">
                            <p>Session error. Please refresh the page.</p>
                        </div>
                    </td>
                </tr>
            `;
                return;
            }

            // Get current user's loans first
            const loans = await getLoanApplications();

            // CRITICAL FIX: Filter loans STRICTLY by current user's PF number ONLY
            // This ensures staff members can ONLY see their own payments when viewing as loanee
            const userLoans = loans.filter(loan => {
                const loanPf = String(loan.pf_number || '').trim();
                const currentPf = String(currentUser.pf_number || '').trim();

                // STRICT PF number matching only
                const matches = loanPf === currentPf;

                if (matches) {
                    //console.log(`✅ Payment history: Loan ${loan.application_id} matches PF ${currentPf}`);
                }

                return matches;
            });

            //console.log(`Payment History: Found ${userLoans.length} loans for PF ${currentUser.pf_number}`);

            // Get payments ONLY for user's loans
            const allPayments = [];

            for (const loan of userLoans) {
                try {
                    const loanId = loan.application_id || loan.id;
                    //console.log(`Fetching payments for loan: ${loanId} (PF: ${loan.pf_number})`);

                    const result = await apiRequest(`payments.php?application_id=${loanId}`);

                    if (result.data && result.data.length > 0) {
                        // Add loan information to each payment (with fallback)
                        const paymentsWithLoanInfo = result.data.map(payment => ({
                            ...payment,
                            loan_id: loanId,
                            application_id: loanId,
                            // Use loan type from payment if available, otherwise from loan object
                            loan_type: payment.loan_type || loan.loan_type || 'unknown',
                            loan_amount: payment.loan_amount || loan.loan_amount,
                            // Store PF number for verification
                            pf_number: loan.pf_number
                        }));

                        allPayments.push(...paymentsWithLoanInfo);
                    }
                } catch (error) {
                    //console.error(`Error fetching payments for loan ${loan.application_id}:`, error);
                }
            }

            //console.log(`Total payments loaded: ${allPayments.length} (all for PF: ${currentUser.pf_number})`);

            // If no payments found, show a clear message
            if (allPayments.length === 0) {
                paymentsTableBody.innerHTML = `
                <tr>
                    <td colspan="9" class="no-payments">
                        <div style="padding: 20px; text-align: center;">
                            <p>No payment records found for your loans.</p>
                            <p>Payment history will appear here once payments are made.</p>
                        </div>
                    </td>
                </tr>
            `;

                // Still update summary with zeros
                if (totalPayments) totalPayments.textContent = 'KES 0';
                if (totalPrincipal) totalPrincipal.textContent = 'KES 0';
                if (totalInterest) totalInterest.textContent = 'KES 0';
                return;
            }

            // Populate loan ID filter dropdown
            populateLoanFilter(userLoans);

            // Populate year filter dropdown
            populateYearFilter(allPayments);

            // Calculate totals
            let totalAmount = 0;
            let totalPrincipalAmt = 0;
            let totalInterestAmt = 0;

            allPayments.forEach(payment => {
                totalAmount += parseFloat(payment.amount) || 0;
                totalPrincipalAmt += parseFloat(payment.principal_amount) || 0;
                totalInterestAmt += parseFloat(payment.interest_amount) || 0;
            });

            // Update summary cards
            if (totalPayments) totalPayments.textContent = `KES ${fmtCurrency(totalAmount)}`;
            if (totalPrincipal) totalPrincipal.textContent = `KES ${fmtCurrency(totalPrincipalAmt)}`;
            if (totalInterest) totalInterest.textContent = `KES ${fmtCurrency(totalInterestAmt)}`;

            // Store payments globally for filtering
            window.allUserPayments = allPayments;

            // Display payments
            displayFilteredPayments(allPayments);

        } catch (error) {
            //console.error('Error rendering payment history:', error);
            paymentsTableBody.innerHTML = `
            <tr>
                <td colspan="9" class="no-payments">Error loading payment history. Please try again later.</td>
            </tr>
        `;
        }
    }


    // ==========================================
    // Filter Population Functions
    // ==========================================
    function populateLoanFilter(payments) {
        const loanFilter = document.getElementById('loanFilter');
        if (!loanFilter) return;

        loanFilter.innerHTML = '<option value="">All Loan IDs</option>';

        // Extract unique loan IDs and their types
        const loanMap = new Map();
        payments.forEach(p => {
            if (p.application_id && !loanMap.has(p.application_id)) {
                loanMap.set(p.application_id, p.loan_type || 'Unknown');
            }
        });

        // Sort loan IDs descending (assuming string comparison works for date-like IDs)
        const sortedLoanIds = Array.from(loanMap.keys()).sort((a, b) => b.localeCompare(a));

        sortedLoanIds.forEach(id => {
            const type = loanMap.get(id).charAt(0).toUpperCase() + loanMap.get(id).slice(1);
            const option = document.createElement('option');
            option.value = id;
            option.textContent = `${id} - ${type}`;
            loanFilter.appendChild(option);
        });
    }

    function populateYearFilter(payments) {
        const yearFilter = document.getElementById('yearFilter');
        if (!yearFilter) return;

        yearFilter.innerHTML = '<option value="">All Years</option>';

        // Extract unique years
        const years = new Set();
        payments.forEach(p => {
            if (p.payment_date) {
                const year = new Date(p.payment_date).getFullYear();
                if (year) years.add(year);
            }
        });

        // Sort descending (most recent first)
        const sortedYears = Array.from(years).sort((a, b) => b - a);

        sortedYears.forEach(year => {
            const option = document.createElement('option');
            option.value = year;
            option.textContent = year;
            yearFilter.appendChild(option);
        });
    }

    // ==========================================
    // Filter Population Functions
    // ==========================================
    function populateLoanFilter(loans) {
        if (!loanFilter) return;

        loanFilter.innerHTML = '<option value="">All Loan IDs</option>';

        // Sort loans by application_id from latest to oldest (descending)
        loans.sort((a, b) => {
            const idA = a.application_id || '';
            const idB = b.application_id || '';
            return idB.localeCompare(idA);
        });

        loans.forEach(loan => {
            const option = document.createElement('option');
            option.value = loan.application_id;
            const loanType = loan.loan_type ? loan.loan_type.charAt(0).toUpperCase() + loan.loan_type.slice(
                    1) :
                '';
            option.textContent =
                `${loan.application_id} - ${loanType} (KES ${fmtCurrency(loan.loan_amount || 0)})`;
            loanFilter.appendChild(option);
        });
    }


    // ==========================================
    // Display Filtered Payments
    // ==========================================
    // Around line 1150-1200, update the displayFilteredPayments function:

    function displayFilteredPayments(payments) {
        const container = document.getElementById('paymentsTableBody');
        if (!paymentsTableBody) return;

        // Get filter values
        const loanId = document.getElementById('loanFilter')?.value || '';
        const loanType = document.getElementById('typeFilter')?.value.toLowerCase() || '';
        const year = document.getElementById('yearFilter')?.value || '';
        const source = document.getElementById('sourceFilter')?.value.toLowerCase() || '';

        let filtered = payments.filter(p => {
            const matchesLoanId = !loanId || p.application_id === loanId;
            const matchesType = !loanType || (p.loan_type || '').toLowerCase() === loanType;
            const paymentYear = p.payment_date ? new Date(p.payment_date).getFullYear() : null;
            const matchesYear = !year || paymentYear === parseInt(year);
            const matchesSource = !source || (p.payment_source || '').toLowerCase() === source;

            return matchesLoanId && matchesType && matchesYear && matchesSource;
        });

        container.innerHTML = '';
        if (filtered.length === 0) {
            container.innerHTML =
                '<tr><td colspan="9" class="no-payments">No payments match the filters.</td></tr>';
        } else {
            filtered.forEach(p => {
                const row = document.createElement('tr');

                // Handle loan type display - with fallback
                let displayType = 'N/A';
                if (p.loan_type) {
                    displayType = p.loan_type.charAt(0).toUpperCase() + p.loan_type.slice(1);
                }

                // Handle payment source display
                const displaySource = (p.payment_source || '').replace(/_/g, ' ').split(' ')
                    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
                    .join(' ') || 'N/A';

                row.innerHTML = `
                <td>${fmtDate(p.payment_date)}</td>
                <td>${p.application_id || 'N/A'}</td>
                <td><span class="loan-badge ${p.loan_type || ''}">${displayType}</span></td>
                <td><strong>KES ${fmtCurrency(p.amount)}</strong></td>
                <td>KES ${fmtCurrency(p.principal_amount)}</td>
                <td>KES ${fmtCurrency(p.interest_amount)}</td>
                <td>KES ${fmtCurrency(p.balance_after)}</td>
                <td><span class="payment-source-badge ${(p.payment_source || '').split('_')[0]}">${displaySource}</span></td>
                <td>${p.transaction_reference || '-'}</td>
            `;
                container.appendChild(row);
            });
        }

        // Update totals based on filtered data
        updatePaymentTotals(filtered);
    }


    // ==========================================
    // API Functions
    // ==========================================
    async function getLoanApplications() {
        try {
            const result = await apiRequest('loans.php', 'GET');
            return result.data || [];
        } catch (error) {
            //console.error('Error getting loan applications:', error);
            showNotification('Unable to load loan applications', 'error');
            return [];
        }
    }

    // ==========================================
    // Loan Application Functions
    // ==========================================
    async function createLoanApplication(loanData) {
        try {
            const result = await apiRequest('loans.php', 'POST', {
                ...loanData,
                pf_number: currentUser.pf_number,
                id_number: currentUser.id_number,
                email: currentUser.email
            });
            return result.success;
        } catch (error) {
            //console.error('Loan submission error:', error);
            showNotification(error.message || 'Submission failed', 'error');
            return false;
        }
    }

    // ==========================================
    // Generate Unique Loan ID Function
    // ==========================================
    function generateUniqueLoanId() {
        const timestamp = Date.now().toString();
        const random = Math.floor(1000 + Math.random() * 9000).toString();
        const micro = timestamp.slice(-4);
        const now = new Date();
        const year = now.getFullYear().toString();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        const hour = String(now.getHours()).padStart(2, '0');
        const minute = String(now.getMinutes()).padStart(2, '0');
        const second = String(now.getSeconds()).padStart(2, '0');
        return year + month + day + hour + minute + second + micro + random;
    }

    // ==========================================
    // Loan Application Form
    // ==========================================
    if (loanForm) {
        loanForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (loanSuccess) loanSuccess.textContent = '';

            if (!currentUser || !currentUser.pf_number) {
                showNotification("Session expired. Please log in again.", 'error');
                if (loanSuccess) {
                    loanSuccess.textContent = 'Session expired. Please log in again.';
                    loanSuccess.style.color = 'red';
                }
                return;
            }

            const loanId = generateUniqueLoanId();
            const loanData = {
                application_id: loanId,
                loan_type: loanType.value,
                loan_amount: Number(expectedLoan.value),
                payment_duration: Number(paymentYears.value),
                payment_frequency: 'monthly',
                basic_salary: Number(basicSalary.value),
                employment_type: employmentType.value,
                bank_name: bankName.value,
                bank_branch: bankBranch.value.trim(),
                bank_account_number: bankAccount.value.trim(),
                full_name: currentUser.full_name,
                id_number: currentUser.id_number,
                pf_number: currentUser.pf_number,
                email: currentUser.email
            };

            // Add loan type specific data
            if (employmentType.value === 'contracted') {
                const contractPeriodInput = document.getElementById('contractPeriod');
                const contractStartInput = document.getElementById('contractStart');
                const contractEndInput = document.getElementById('contractEnd');
                if (contractPeriodInput && contractPeriodInput.value) loanData.contract_period = Number(
                    contractPeriodInput.value);
                if (contractStartInput && contractStartInput.value) loanData.contract_start_date =
                    contractStartInput.value;
                if (contractEndInput && contractEndInput.value) loanData.contract_end_date =
                    contractEndInput.value;
            }

            if (loanType.value === 'mortgage') {
                const propertyLocationInput = document.getElementById('propertyLocation');
                const propertyValueInput = document.getElementById('propertyValue');
                if (propertyLocationInput && propertyLocationInput.value) loanData.property_location =
                    propertyLocationInput.value.trim();
                if (propertyValueInput && propertyValueInput.value) loanData.property_value = Number(
                    propertyValueInput.value);
            } else if (loanType.value === 'car') {
                const carTypeInput = document.getElementById('carType');
                const carValueInput = document.getElementById('carValue');
                if (carTypeInput && carTypeInput.value) loanData.car_type = carTypeInput.value.trim();
                if (carValueInput && carValueInput.value) loanData.car_value = Number(carValueInput
                    .value);
            }

            const submitBtn = document.getElementById('submitLoan');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Submitting...';
            }

            try {
                const success = await createLoanApplication(loanData);
                if (success) {
                    if (loanSuccess) {
                        loanSuccess.textContent = 'Loan application submitted successfully!';
                        loanSuccess.style.color = 'green';
                    }
                    showNotification('Loan application submitted successfully', 'success');
                    await loadDashboard();
                    setTimeout(() => {
                        if (loanSuccess) loanSuccess.textContent = '';
                        if (loanForm) loanForm.reset();
                        if (mortgageFields) mortgageFields.classList.add('hidden');
                        if (carFields) carFields.classList.add('hidden');
                        if (contractDetails) contractDetails.classList.add('hidden');
                        if (bankName) bankName.value = "Family Bank";
                        // Show eligibility check again for another application
                        if (eligibilityCheck) eligibilityCheck.classList.remove('hidden');
                        if (loanForm) loanForm.classList.add('hidden');
                        showSection('dashboard');
                    }, 1500);
                } else {
                    if (loanSuccess) {
                        loanSuccess.textContent =
                            'Failed to submit loan application. Please try again.';
                        loanSuccess.style.color = 'red';
                    }
                    showNotification('Submission failed. Please try again.', 'error');
                }
            } catch (error) {
                if (loanSuccess) {
                    loanSuccess.textContent = 'Error: ' + (error.message || 'Please try again.');
                    loanSuccess.style.color = 'red';
                }
                showNotification('Submission error: ' + error.message, 'error');
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Submit Loan Application';
                }
            }
        });
    }

    // ==========================================
    // Event Bindings
    // ==========================================
    document.querySelectorAll('#sidebar button[data-section]').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('#sidebar button').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            const section = btn.dataset.section;
            showSection(section);
            if (window.innerWidth <= 760 && hamburger) {
                hamburger.classList.remove('active');
                sidebar.classList.remove('open');
            }
        });
    });

    if (loanType) {
        loanType.addEventListener('change', () => {
            const val = loanType.value.toLowerCase();
            if (mortgageFields) {
                if (val === 'mortgage') {
                    mortgageFields.classList.remove('hidden');
                    document.getElementById('propertyLocation')?.setAttribute('required', 'required');
                    document.getElementById('propertyValue')?.setAttribute('required', 'required');
                } else {
                    mortgageFields.classList.add('hidden');
                    document.getElementById('propertyLocation')?.removeAttribute('required');
                    document.getElementById('propertyValue')?.removeAttribute('required');
                }
            }
            if (carFields) {
                if (val === 'car') {
                    carFields.classList.remove('hidden');
                    document.getElementById('carType')?.setAttribute('required', 'required');
                    document.getElementById('carValue')?.setAttribute('required', 'required');
                } else {
                    carFields.classList.add('hidden');
                    document.getElementById('carType')?.removeAttribute('required');
                    document.getElementById('carValue')?.removeAttribute('required');
                }
            }
        });
    }

    if (employmentType) {
        employmentType.addEventListener('change', () => {
            const val = employmentType.value.toLowerCase();
            if (contractDetails) {
                if (val === 'contracted') {
                    contractDetails.classList.remove('hidden');
                    document.getElementById('contractPeriod')?.setAttribute('required', 'required');
                    document.getElementById('contractStart')?.setAttribute('required', 'required');
                    document.getElementById('contractEnd')?.setAttribute('required', 'required');
                } else {
                    contractDetails.classList.add('hidden');
                    document.getElementById('contractPeriod')?.removeAttribute('required');
                    document.getElementById('contractStart')?.removeAttribute('required');
                    document.getElementById('contractEnd')?.removeAttribute('required');
                }
            }
        });
    }

    if (precheckEmploymentType) {
        precheckEmploymentType.addEventListener('change', () => {
            const val = precheckEmploymentType.value.toLowerCase();
            if (val === 'contracted' && precheckContractDetails) {
                precheckContractDetails.classList.remove('hidden');
            } else if (precheckContractDetails) {
                precheckContractDetails.classList.add('hidden');
            }
        });
    }

    if (precheckLoanType) {
        precheckLoanType.addEventListener('change', () => {
            const val = precheckLoanType.value.toLowerCase();
            if (val === 'mortgage' && precheckPropertyValue) {
                precheckPropertyValue.classList.remove('hidden');
                precheckCarValue.classList.add('hidden');
                checkPropertyValue?.setAttribute('required', 'required');
                checkCarValue?.removeAttribute('required');
            } else if (val === 'car' && precheckCarValue) {
                precheckCarValue.classList.remove('hidden');
                precheckPropertyValue.classList.add('hidden');
                checkCarValue?.setAttribute('required', 'required');
                checkPropertyValue?.removeAttribute('required');
            } else {
                if (precheckPropertyValue) precheckPropertyValue.classList.add('hidden');
                if (precheckCarValue) precheckCarValue.classList.add('hidden');
                checkPropertyValue?.removeAttribute('required');
                checkCarValue?.removeAttribute('required');
            }
        });
    }

    if (precheckEmploymentType) {
        precheckEmploymentType.addEventListener('change', () => {
            const val = precheckEmploymentType.value.toLowerCase();
            if (val === 'permanent') {
                if (precheckRetirementDetails) precheckRetirementDetails.classList.remove('hidden');
                if (precheckContractDetails) precheckContractDetails.classList.add('hidden');
                precheckRetirementDate?.setAttribute('required', 'required');
                precheckContractEnd?.removeAttribute('required');
            } else if (val === 'contracted') {
                if (precheckContractDetails) precheckContractDetails.classList.remove('hidden');
                if (precheckRetirementDetails) precheckRetirementDetails.classList.add('hidden');
                precheckContractEnd?.setAttribute('required', 'required');
                precheckRetirementDate?.removeAttribute('required');
            } else {
                if (precheckRetirementDetails) precheckRetirementDetails.classList.add('hidden');
                if (precheckContractDetails) precheckContractDetails.classList.add('hidden');
                precheckRetirementDate?.removeAttribute('required');
                precheckContractEnd?.removeAttribute('required');
            }
        });
    }

    if (checkEligibilityBtn) {
        checkEligibilityBtn.addEventListener('click', checkLoanEligibility);
    }

    if (cancelLoan) {
        cancelLoan.addEventListener('click', () => {
            if (loanForm) loanForm.reset();
            if (bankName) bankName.value = "Family Bank";
            if (loanForm) loanForm.classList.add('hidden');
            if (eligibilityCheck) eligibilityCheck.classList.remove('hidden');
            if (eligibilityResult) eligibilityResult.classList.add('hidden');
            if (breakdownBox) breakdownBox.classList.add('hidden');

            // Reset readonly fields
            if (expectedLoan) {
                expectedLoan.readOnly = false;
                expectedLoan.style.backgroundColor = '#fff';
                expectedLoan.style.cursor = 'text';
            }
            if (paymentYears) {
                paymentYears.readOnly = false;
                paymentYears.style.backgroundColor = '#fff';
                paymentYears.style.cursor = 'text';
            }
            if (basicSalary) {
                basicSalary.readOnly = false;
                basicSalary.style.backgroundColor = '#fff';
                basicSalary.style.cursor = 'text';
            }
        });
    }

    if (newPassword) {
        newPassword.addEventListener('input', () => {
            updatePasswordStrengthIndicator(newPassword.value);
        });
    }

    if (calcBtn) {
        calcBtn.addEventListener('click', () => {
            const amount = parseFloat(calcAmount.value) || 0;
            const term = parseFloat(calcTerm.value) || 0;
            if (amount <= 0 || term <= 0) {
                if (calcSummary) calcSummary.classList.add('hidden');
                if (amortContainer) amortContainer.classList.add('hidden');
                showNotification('Please enter valid loan amount and term', 'error');
                return;
            }
            const result = generateAmortizationSchedule(amount, currentInterestRate, term, new Date());
            if (sumAmount) sumAmount.textContent = fmtCurrency(amount);
            if (sumRate) sumRate.textContent = currentInterestRate;
            if (sumTerm) sumTerm.textContent = term;
            if (sumMonthly) sumMonthly.textContent = fmtCurrency(result.monthlyPayment);
            if (sumInterest) sumInterest.textContent = fmtCurrency(result.totalInterest);
            if (sumTotal) sumTotal.textContent = fmtCurrency(result.totalPaid);
            if (amortBody) {
                amortBody.innerHTML = '';
                result.schedule.forEach(row => {
                    amortBody.innerHTML += `
                        <tr>
                            <td style="text-align:left;">${row.paymentNumber}</td>
                            <td style="text-align:left;">${row.paymentDate}</td>
                            <td>${fmtCurrency(row.monthlyPayment)}</td>
                            <td>${fmtCurrency(row.principal)}</td>
                            <td>${fmtCurrency(row.interest)}</td>
                            <td>${fmtCurrency(row.balance)}</td>
                        </tr>
                    `;
                });
            }
            if (calcSummary) calcSummary.classList.remove('hidden');
            if (amortContainer) amortContainer.classList.remove('hidden');
        });
    }

    // Replace with a single event listener for the Apply Filter button
    const applyFilterBtn = document.getElementById('applyFilterBtn');
    if (applyFilterBtn) {
        applyFilterBtn.addEventListener('click', () => {
            if (window.allUserPayments) displayFilteredPayments(window.allUserPayments);
        });
    }

    // Also add a reset filter button functionality
    const resetFilterBtn = document.createElement('button');
    resetFilterBtn.textContent = 'Reset Filters';
    resetFilterBtn.style.marginTop = '8px';
    resetFilterBtn.style.marginLeft = '10px';
    resetFilterBtn.className = 'secondary';
    resetFilterBtn.addEventListener('click', () => {
        if (loanFilter) loanFilter.value = '';
        if (yearFilter) yearFilter.value = '';
        if (sourceFilter) sourceFilter.value = '';
        if (window.allUserPayments) displayFilteredPayments(window.allUserPayments);
    });

    // Add the reset button next to the apply button
    if (applyFilterBtn && applyFilterBtn.parentNode) {
        applyFilterBtn.parentNode.appendChild(resetFilterBtn);
    }

    if (hamburger) {
        hamburger.addEventListener('click', () => {
            hamburger.classList.toggle('active');
            sidebar.classList.toggle('open');
            if (mainContent) {
                mainContent.classList.toggle('sidebar-open');
            }
            if (window.innerWidth > 760) {
                sidebar.classList.toggle('collapsed');
                if (mainContent) {
                    mainContent.classList.toggle('sidebar-collapsed');
                }
            }
        });
    }

    if (profileBadge) {
        profileBadge.addEventListener('click', () => {
            showSection('profile');
            if (window.innerWidth <= 760 && hamburger) {
                hamburger.classList.remove('active');
                sidebar.classList.remove('open');
            }
        });
    }

    const logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', async (e) => {
            e.preventDefault();
            if (!confirm('Are you sure you want to logout?')) return;
            logoutBtn.disabled = true;
            const originalHTML = logoutBtn.innerHTML;
            logoutBtn.innerHTML =
                '<span class="button-icon">⏳</span><span class="button-text">Logging out...</span>';
            showNotification('Logging out...', 'success');
            try {
                await logout();
                logoutBtn.innerHTML =
                    '<span class="button-icon">✅</span><span class="button-text">Success!</span>';
                showNotification('Logout successful! Redirecting...', 'success');
                setTimeout(() => {
                    window.location.href = 'index.php';
                }, 1200);
            } catch (error) {
                showNotification('Logging out...', 'success');
                setTimeout(() => {
                    window.location.href = 'index.php';
                }, 800);
            }
        });
    }

    // ==========================================
    // Validation Functions
    // ==========================================
    function validateRetirementForLoan(retirementDateStr, loanTermYears, loanType) {
        if (!retirementDateStr || !loanTermYears || !loanType) {
            return {
                isValid: true,
                message: ''
            };
        }
        const retirementDate = new Date(retirementDateStr);
        const today = new Date();
        const finalPaymentDate = new Date(today);
        finalPaymentDate.setFullYear(finalPaymentDate.getFullYear() + Math.floor(loanTermYears));
        finalPaymentDate.setMonth(finalPaymentDate.getMonth() + Math.round((loanTermYears % 1) * 12));
        const paymentDeadline = new Date(retirementDate);
        paymentDeadline.setMonth(paymentDeadline.getMonth() - 3);
        if (finalPaymentDate > paymentDeadline) {
            return {
                isValid: false,
                message: `❌ <strong>NOT ELIGIBLE - Retirement Timeline Rule</strong><br><br>Your <strong>${loanType.charAt(0).toUpperCase() + loanType.slice(1)} Loan</strong> with a ${loanTermYears}-year term would be fully paid on approximately <strong>${fmtDate(finalPaymentDate)}</strong>.<br><br>However, your retirement date is <strong>${fmtDate(retirementDate)}</strong>, and loans must be <strong>fully repaid 3 months before retirement</strong>.<br><br><strong>Latest Payment Deadline:</strong> ${fmtDate(paymentDeadline)}<br><br><strong>Suggestions:</strong><br>• Reduce your loan term to ${Math.floor((paymentDeadline - today) / (365.25 * 24 * 60 * 60 * 1000))} years or less<br>• Consider a different loan type with different requirements<br>• Plan to retire later if possible`
            };
        }
        return {
            isValid: true,
            message: ''
        };
    }

    function validateContractForLoan(contractEndDateStr, loanTermYears) {
        if (!contractEndDateStr || !loanTermYears) {
            return {
                isValid: true,
                message: ''
            };
        }
        const contractEndDate = new Date(contractEndDateStr);
        const today = new Date();
        const finalPaymentDate = new Date(today);
        finalPaymentDate.setFullYear(finalPaymentDate.getFullYear() + Math.floor(loanTermYears));
        finalPaymentDate.setMonth(finalPaymentDate.getMonth() + Math.round((loanTermYears % 1) * 12));
        const threeMonthsBeforeEnd = new Date(contractEndDate);
        threeMonthsBeforeEnd.setMonth(threeMonthsBeforeEnd.getMonth() - 3);
        if (finalPaymentDate >= threeMonthsBeforeEnd) {
            return {
                isValid: false,
                message: `❌ <strong>NOT ELIGIBLE - Contract Duration Rule</strong><br><br>Your requested loan term of ${loanTermYears} years would end on approximately <strong>${fmtDate(finalPaymentDate)}</strong>.<br><strong>Suggestion:</strong> Please reduce your loan term so that the final payment is made on or before <strong>${fmtDate(threeMonthsBeforeEnd)}</strong>.`
            };
        }
        return {
            isValid: true,
            message: ''
        };
    }

    // ==========================================
    // Return to Staff Portal Function
    // ==========================================
    function returnToStaffPortal() {
        //console.log('returnToStaffPortal called');

        // Add safety check
        if (!currentUser) {
            //console.error('currentUser is null, cannot return to staff portal');
            showNotification('Session error. Please refresh page.', 'error');
            return;
        }

        if (confirm('Return to your staff portal? Any unsaved loan application data will be lost.')) {
            // Show loading notification
            showNotification('Returning to staff portal...', 'info');

            // Determine which staff portal to return to based on original portal
            let redirectUrl = 'index.php'; // Default fallback

            // Get the original portal from session data
            const originalPortal =
                "<?php echo isset($_SESSION['original_portal']) ? $_SESSION['original_portal'] : ''; ?>";

            // Fix the switch statement to properly handle all cases
            switch (originalPortal) {
                case 'admin':
                    redirectUrl = 'admin.php?returned_from_loanee=1';
                    break;
                case 'cashier':
                    redirectUrl = 'cashier.php?returned_from_loanee=1';
                    break;
                case 'accountant':
                    redirectUrl = 'accountant.php?returned_from_loanee=1';
                    break;
                default:
                    // If original portal is not set, use current role
                    if (currentUser.user_role) {
                        switch (currentUser.user_role) {
                            case 'admin':
                                redirectUrl = 'admin.php?returned_from_loanee=1';
                                break;
                            case 'cashier':
                                redirectUrl = 'cashier.php?returned_from_loanee=1';
                                break;
                            case 'accountant':
                                redirectUrl = 'accountant.php?returned_from_loanee=1';
                                break;
                            default:
                                redirectUrl = 'index.php';
                                break;
                        }
                    } else {
                        redirectUrl = 'index.php';
                    }
                    break;
            }

            //console.log('Redirecting to:', redirectUrl);

            // Add a small delay to ensure notification shows
            setTimeout(() => {
                window.location.href = redirectUrl;
            }, 500);
        }
    }

    // Make function globally available
    window.returnToStaffPortal = returnToStaffPortal;

    // ==========================================
    // Debug portal switching
    // ==========================================
    //console.log('=== Portal Info ===');
    //console.log('Page:', window.location.pathname);
    //console.log('User Role:', currentUser.user_role);
    //console.log('Viewing as Loanee:', currentUser.viewing_as_loanee);
    /*/
                /console.log('Original Portal:',
                <?php echo isset($_SESSION['original_portal']) ? '"' . $_SESSION['original_portal'] . '"' : 'null'; ?>
            );*/

    // ==========================================
    // Fallback loaders in case main functions are missing
    // ==========================================
    async function renderLoanHistory() {
        const container = document.getElementById('historyContainer');
        if (!container) {
            //console.error('historyContainer not found!');
            return;
        }

        container.innerHTML = '<div class="loading">Loading loan history...</div>';

        try {
            // Safety check for current user
            if (!currentUser || !currentUser.pf_number) {
                //console.error('Current user or PF number is missing');
                container.innerHTML = '<p class="error">Session error. Please refresh the page.</p>';
                return;
            }

            const response = await fetch('api/loans.php');
            const res = await response.json();

            if (!res.success || !res.data) {
                throw new Error('Failed to load loans');
            }

            // CRITICAL FIX: Filter loans STRICTLY by current user's PF number ONLY
            // This ensures staff members can ONLY see their own loan history when viewing as loanee
            const allLoans = res.data;
            const userLoans = allLoans.filter(loan => {
                const loanPf = String(loan.pf_number || '').trim();
                const currentPf = String(currentUser.pf_number || '').trim();

                // STRICT PF number matching only
                const matches = loanPf === currentPf;

                if (matches) {
                    //console.log(`✅ Loan history: Loan ${loan.application_id} matches PF ${currentPf}`);
                }

                return matches;
            });

            /*console.log(
            `Loan History: Found ${userLoans.length} loans for PF ${currentUser.pf_number} out of ${allLoans.length} total loans`
        );*/

            if (userLoans.length === 0) {
                container.innerHTML = '<p class="no-data">No loan applications found for your account.</p>';
                return;
            }

            // Sort by application date (newest first)
            userLoans.sort((a, b) => new Date(b.application_date) - new Date(a.application_date));

            let html = `
            <div class="table-container">
                <table class="loan-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Application ID</th>
                            <th>Type</th>
                            <th>Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
        `;

            userLoans.forEach(loan => {
                const statusClass = loan.status?.toLowerCase() || 'pending';
                const statusText = loan.status ? loan.status.charAt(0).toUpperCase() + loan.status
                    .slice(
                        1) : 'Pending';
                const loanType = loan.loan_type ? loan.loan_type.charAt(0).toUpperCase() + loan
                    .loan_type
                    .slice(1) : 'N/A';
                const loanAmount = parseFloat(loan.loan_amount || 0);

                html += `
                <tr>
                    <td>${new Date(loan.application_date).toLocaleDateString('en-GB')}</td>
                    <td>${loan.application_id || 'N/A'}</td>
                    <td>${loanType}</td>
                    <td>KES ${fmtCurrency(loanAmount)}</td>
                    <td><span class="status ${statusClass}">${statusText}</span></td>
                </tr>
            `;
            });

            html += `
                    </tbody>
                </table>
            </div>
        `;
            container.innerHTML = html;

        } catch (err) {
            //console.error('Loan history error:', err);
            container.innerHTML =
                '<p class="error">Failed to load loan history. Please try again later.</p>';
        }
    }

    // ==========================================
    // Payment History Rendering
    // ==========================================
    function renderPaymentHistory() {
        const container = document.getElementById('paymentsTableBody');
        if (!container) {
            //console.error('paymentsTableBody not found!');
            return;
        }

        container.innerHTML = '<tr><td colspan="9" class="no-payments">Loading payment history...</td></tr>';

        fetch('api/payments.php')
            .then(r => r.json())
            .then(res => {
                if (res.success && res.data && res.data.length > 0) {
                    window.allUserPayments = res.data;

                    // Populate filters
                    populateLoanFilter(res.data);
                    populateYearFilter(res.data);

                    // Display all payments initially
                    displayFilteredPayments(res.data);

                    // Add filter button listeners (only once)
                    const applyBtn = document.getElementById('applyFilterBtn');
                    const resetBtn = document.getElementById('resetFilterBtn');
                    if (applyBtn && !applyBtn.hasListener) {
                        applyBtn.addEventListener('click', () => displayFilteredPayments(window
                            .allUserPayments));
                        applyBtn.hasListener = true;
                    }
                    if (resetBtn && !resetBtn.hasListener) {
                        resetBtn.addEventListener('click', () => {
                            document.getElementById('loanFilter').value = '';
                            document.getElementById('typeFilter').value = '';
                            document.getElementById('yearFilter').value = '';
                            document.getElementById('sourceFilter').value = '';
                            displayFilteredPayments(window.allUserPayments);
                        });
                        resetBtn.hasListener = true;
                    }

                } else {
                    container.innerHTML =
                        '<tr><td colspan="9" class="no-payments">No payments recorded yet.</td></tr>';
                    updatePaymentTotals([]);
                }
            })
            .catch(err => {
                container.innerHTML =
                    '<tr><td colspan="9" class="no-payments error">Failed to load payments.</td></tr>';
                updatePaymentTotals([]);
                //console.error(err);
            });
    }

    // ==========================================
    // UPDATE TOTALS FUNCTION
    // ==========================================
    function updatePaymentTotals(payments) {
        if (!payments || payments.length === 0) {
            document.getElementById('totalPayments').textContent = 'KES 0';
            document.getElementById('totalPrincipal').textContent = 'KES 0';
            document.getElementById('totalInterest').textContent = 'KES 0';
            return;
        }

        const totalPaid = payments.reduce((sum, p) => sum + parseFloat(p.amount || 0), 0);
        const totalPrincipal = payments.reduce((sum, p) => sum + parseFloat(p.principal_amount || 0), 0);
        const totalInterest = payments.reduce((sum, p) => sum + parseFloat(p.interest_amount || 0), 0);

        // Format with commas and KES
        const fmt = (num) => 'KES ' + Math.round(num).toLocaleString();

        document.getElementById('totalPayments').textContent = fmt(totalPaid);
        document.getElementById('totalPrincipal').textContent = fmt(totalPrincipal);
        document.getElementById('totalInterest').textContent = fmt(totalInterest);
    }

    // Global variable to store all schedule data
    window.allScheduleData = [];

    async function loadPaymentSchedule(applicationId) {
        const container = document.getElementById('scheduleTableContainer');
        if (!container) return;

        container.innerHTML = '<div class="loading">Loading payment schedule...</div>';

        try {
            // Get all user loans first to populate filters
            const loans = await getLoanApplications();

            // Filter loans for current user
            const userLoans = loans.filter(loan => {
                const loanUserId = parseInt(loan.user_id) || 0;
                const currentUserId = parseInt(currentUser.id) || 0;
                const loanPf = String(loan.pf_number || '').trim();
                const currentPf = String(currentUser.pf_number || '').trim();
                const loanIdNum = String(loan.id_number || '').trim();
                const currentIdNum = String(currentUser.id_number || '').trim();

                if (loanUserId > 0 && currentUserId > 0 && loanUserId === currentUserId)
                    return true;
                if (loanPf && currentPf && loanPf === currentPf) return true;
                if (loanIdNum && currentIdNum && loanIdNum === currentIdNum) return true;
                return false;
            });

            // Populate filter dropdowns
            populateScheduleFilters(userLoans);

            // Get schedule data for all loans
            const allScheduleData = [];

            for (const loan of userLoans) {
                try {
                    const loanId = loan.application_id || loan.id;
                    const result = await apiRequest(`payment_schedule.php?application_id=${loanId}`);

                    if (result.success && result.data && result.data.length > 0) {
                        // Add loan information to each schedule item
                        const scheduleWithLoanInfo = result.data.map(item => ({
                            ...item,
                            loan_id: loanId,
                            loan_type: loan.loan_type,
                            loan_amount: loan.loan_amount
                        }));

                        allScheduleData.push(...scheduleWithLoanInfo);
                    }
                } catch (error) {
                    //console.error(`Error fetching schedule for loan ${loan.application_id}:`, error);
                }
            }

            // Store all data globally for filtering
            window.allScheduleData = allScheduleData;

            // If no schedule data found
            if (allScheduleData.length === 0) {
                container.innerHTML = '<p class="no-data">No payment schedules available.</p>';
                return;
            }

            // Display all schedules initially
            displayFilteredSchedule(allScheduleData);

            // Add event listeners for filter buttons
            const applyFilterBtn = document.getElementById('applyScheduleFilterBtn');
            const resetFilterBtn = document.getElementById('resetScheduleFilterBtn');

            if (applyFilterBtn && !applyFilterBtn.hasListener) {
                applyFilterBtn.addEventListener('click', () => {
                    displayFilteredSchedule(window.allScheduleData);
                });
                applyFilterBtn.hasListener = true;
            }

            if (resetFilterBtn && !resetFilterBtn.hasListener) {
                resetFilterBtn.addEventListener('click', () => {
                    document.getElementById('scheduleLoanFilter').value = '';
                    document.getElementById('scheduleTypeFilter').value = '';
                    document.getElementById('scheduleYearFilter').value = '';
                    displayFilteredSchedule(window.allScheduleData);
                });
                resetFilterBtn.hasListener = true;
            }

        } catch (error) {
            container.innerHTML = '<p class="error">Error loading payment schedule.</p>';
            //console.error('Schedule loading error:', error);
        }
    }

    function populateScheduleFilters(loans) {
        // Populate loan ID filter
        const loanFilter = document.getElementById('scheduleLoanFilter');
        if (loanFilter) {
            loanFilter.innerHTML = '<option value="">All Loan IDs</option>';

            // Sort loans by application_id from latest to oldest
            loans.sort((a, b) => {
                const idA = a.application_id || '';
                const idB = b.application_id || '';
                return idB.localeCompare(idA);
            });

            loans.forEach(loan => {
                const option = document.createElement('option');
                option.value = loan.application_id;
                const loanType = loan.loan_type ? loan.loan_type.charAt(0).toUpperCase() + loan
                    .loan_type.slice(
                        1) : '';
                option.textContent = `${loan.application_id} - ${loanType}`;
                loanFilter.appendChild(option);
            });
        }

        // Populate year filter
        const yearFilter = document.getElementById('scheduleYearFilter');
        if (yearFilter) {
            yearFilter.innerHTML = '<option value="">All Years</option>';

            // Extract unique years from all payment dates
            const years = new Set();
            window.allScheduleData.forEach(item => {
                if (item.due_date) {
                    const year = new Date(item.due_date).getFullYear();
                    if (year) years.add(year);
                }
            });

            // Sort years descending
            const sortedYears = Array.from(years).sort((a, b) => b - a);

            sortedYears.forEach(year => {
                const option = document.createElement('option');
                option.value = year;
                option.textContent = year;
                yearFilter.appendChild(option);
            });
        }
    }

    function displayFilteredSchedule(data) {
        const container = document.getElementById('scheduleTableContainer');
        if (!container) return;

        // Get filter values
        const loanId = document.getElementById('scheduleLoanFilter')?.value.trim() || '';
        const loanType = document.getElementById('scheduleTypeFilter')?.value.toLowerCase().trim() || '';
        const year = document.getElementById('scheduleYearFilter')?.value || '';

        // Filter the data
        let filtered = data.filter(item => {
            const matchesLoanId = !loanId || item.loan_id === loanId;
            const matchesType = !loanType || (item.loan_type || '').toLowerCase() === loanType;
            const itemYear = item.due_date ? new Date(item.due_date).getFullYear() : null;
            const matchesYear = !year || itemYear === parseInt(year);

            return matchesLoanId && matchesType && matchesYear;
        });

        // CRITICAL: Sort by loan_id first, then by payment_number (1,2,3... for each loan)
        filtered.sort((a, b) => {
            if (a.loan_id !== b.loan_id) {
                return a.loan_id.localeCompare(b.loan_id);
            }
            return a.payment_number - b.payment_number;
        });

        // Group by loan
        const groupedByLoan = {};
        filtered.forEach(item => {
            if (!groupedByLoan[item.loan_id]) {
                groupedByLoan[item.loan_id] = {
                    loan_type: item.loan_type || 'Unknown',
                    loan_amount: item.loan_amount || 0,
                    items: []
                };
            }
            groupedByLoan[item.loan_id].items.push(item);
        });

        let html = '';

        if (Object.keys(groupedByLoan).length === 0) {
            html = '<p class="no-data">No payment schedules match the selected filters.</p>';
        } else {
            Object.keys(groupedByLoan).sort().forEach(loanId => {
                const loan = groupedByLoan[loanId];
                const loanTypeDisplay = loan.loan_type ?
                    loan.loan_type.charAt(0).toUpperCase() + loan.loan_type.slice(1) :
                    'Loan';

                html += `
            <div class="schedule-loan-section" style="margin-bottom: 40px;">
                <h3 style="color:#4a6b4a; border-bottom:2px solid #4a6b4a; padding-bottom:8px;">
                    Payment Schedule — ${loanId} <small>(${loanTypeDisplay})</small>
                </h3>
                <p><strong>Loan Amount:</strong> KES ${parseFloat(loan.loan_amount).toLocaleString()}</p>
                <p><strong>Total Installments:</strong> ${loan.items.length} months</p>

                <div class="table-container">
                    <table class="schedule-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Due Date</th>
                                <th>Amount (KES)</th>
                                <th>Principal (KES)</th>
                                <th>Interest (KES)</th>
                                <th>Balance (KES)</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>`;

                loan.items.forEach(row => {
                    const dueDate = new Date(row.due_date);
                    const isOverdue = dueDate < new Date() && row.status !== 'paid';
                    const statusText = row.status === 'paid' ? 'Paid' :
                        isOverdue ? 'Overdue' : 'Pending';
                    const statusClass = row.status === 'paid' ? 'paid' :
                        isOverdue ? 'overdue' : 'pending';

                    html += `
                <tr>
                    <td><strong>${row.payment_number}</strong></td>
                    <td>${dueDate.toLocaleDateString('en-GB')}</td>
                    <td>${parseFloat(row.expected_amount).toLocaleString()}</td>
                    <td>${parseFloat(row.expected_principal).toLocaleString()}</td>
                    <td>${parseFloat(row.expected_interest).toLocaleString()}</td>
                    <td>${parseFloat(row.expected_balance).toLocaleString()}</td>
                    <td><span class="status ${statusClass}">${statusText}</span></td>
                </tr>`;
                });

                html += `
                        </tbody>
                    </table>
                </div>
            </div>`;
            });
        }

        container.innerHTML = html;
    }

    // ==========================================
    // Initialization
    // ==========================================
    function initializeApp() {
        //console.log('Initializing app...');
        //console.log('Current user:', currentUser);

        // Check if sidebar exists before adding event listeners
        const hamburger = document.getElementById('hamburgerMenu');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');

        if (hamburger && sidebar) {
            hamburger.addEventListener('click', () => {
                hamburger.classList.toggle('active');
                sidebar.classList.toggle('open');
                if (mainContent) {
                    mainContent.classList.toggle('sidebar-open');
                }
                if (window.innerWidth > 760) {
                    sidebar.classList.toggle('collapsed');
                    if (mainContent) {
                        mainContent.classList.toggle('sidebar-collapsed');
                    }
                }
            });
        }

        // Initialize other components
        if (typeof loadDashboard === 'function') {
            loadDashboard();
        }

        // Show dashboard section by default
        showSection('dashboard');
    }

    // Initialize the app when page loads
    document.addEventListener('DOMContentLoaded', function() {
        // Profile dropdown functionality
        const profileButton = document.getElementById('profileButton');
        const profileDropdown = document.getElementById('profileDropdown');
        const profileMenuItem = document.getElementById('profileMenuItem');
        const securityMenuItem = document.getElementById('securityMenuItem');
        const profileLogoutBtn = document.getElementById('profileLogoutBtn');

        // Toggle dropdown
        if (profileButton) {
            profileButton.addEventListener('click', function(e) {
                e.stopPropagation();
                profileDropdown.classList.toggle('show');
                profileButton.classList.toggle('active');
            });
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function() {
            if (profileDropdown) {
                profileDropdown.classList.remove('show');
            }
            if (profileButton) {
                profileButton.classList.remove('active');
            }
        });

        // Prevent dropdown from closing when clicking inside
        if (profileDropdown) {
            profileDropdown.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        }

        // Profile menu item click
        if (profileMenuItem) {
            profileMenuItem.addEventListener('click', function(e) {
                e.preventDefault();
                showSection('profile');
                profileDropdown.classList.remove('show');
                profileButton.classList.remove('active');
            });
        }

        // Security menu item click
        if (securityMenuItem) {
            securityMenuItem.addEventListener('click', function(e) {
                e.preventDefault();
                showSection('profile');
                profileDropdown.classList.remove('show');
                profileButton.classList.remove('active');

                // Scroll to password section
                setTimeout(() => {
                    const passwordSection = document.getElementById('passwordForm');
                    if (passwordSection) {
                        passwordSection.scrollIntoView({
                            behavior: 'smooth'
                        });
                    }
                }, 100);
            });
        }

        // Profile logout button click
        if (profileLogoutBtn) {
            profileLogoutBtn.addEventListener('click', async function(e) {
                e.preventDefault();

                if (!confirm('Are you sure you want to logout?')) return;

                profileLogoutBtn.innerHTML =
                    '<span class="dropdown-icon">⏳</span>Logging out...';

                try {
                    await logout();
                    showNotification('Logout successful! Redirecting...', 'success');
                    setTimeout(() => {
                        window.location.href = 'index.php';
                    }, 1200);
                } catch (error) {
                    showNotification('Logout successful! Redirecting...', 'success');
                    setTimeout(() => {
                        window.location.href = 'index.php';
                    }, 800);
                }
            });
        }
        //console.log('DOM loaded - initializing loanee portal');
        initializeApp();

        // Default to dashboard
        setTimeout(() => showSection('dashboard'), 100);
    });
    </script>

</body>

</html>