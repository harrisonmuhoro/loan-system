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
        'email' => $_SESSION['email'] ?? '',
        'role' => $_SESSION['user_role'] ?? '',
    ];

    // Define cashier permissions based on role
    $cashierPermissions = [
        'canViewLoans' => true,
        'canRecordPayments' => true,
        'canViewReports' => true,
        'canExportData' => true,
        'canForwardLoans' => true,
        'canRejectLoans' => true,
        'canCloseLoans' => true,
        'canApplyForLoan' => true,  // Add this permission
        'cannotDeleteData' => true,
        'cannotManageStaff' => true,
        'cannotManageSystem' => true
    ];

    // Store permissions in session for JavaScript access
    $_SESSION['cashier_permissions'] = $cashierPermissions;
}

// If not logged in from main system, redirect to login page
if (!$loggedInFromMain) {
    header("Location: index.php");
    exit();
}

// Get system settings (interest rate)
$interest_rate = 3.0;
try {
    // Define BASE_PATH if not already defined
    if (!defined('BASE_PATH')) {
        define('BASE_PATH', __DIR__ . '/');
    }

    // Include database configuration
    require_once __DIR__ . '/config/config.php';
    require_once __DIR__ . '/config/database.php';

    $database = new Database();
    $conn = $database->getConnection();

    $query = "SELECT value FROM system_settings WHERE setting_key = 'amortization_rate'";
    $stmt = $conn->prepare($query);
    $stmt->execute();

    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $interest_rate = floatval($row['value']);
    }
} catch (Exception $e) {
    // If database connection fails, use default value
    error_log("Error getting interest rate: " . $e->getMessage());
}

// Add this function to all three staff portals

// FIXED: Apply for loan function
function applyForLoan()
{
    // Store original portal info BEFORE any checks
    $_SESSION['original_portal'] = 'cashier';
    $_SESSION['viewing_as_loanee'] = true;
    $_SESSION['from_staff_portal'] = true;
    $_SESSION['staff_original_role'] = $_SESSION['user_role'];

    // Set a flag that the user is switching to loanee portal
    $_SESSION['switching_to_loanee'] = true;

    // Log the switch
    error_log("Cashier applying for loan: " . print_r([
        'user_id' => $_SESSION['user_id'],
        'pf_number' => $_SESSION['pf_number'],
        'role' => $_SESSION['user_role']
    ], true));

    // Redirect to loanee portal with proper parameters
    header("Location: loanee.php?from=cashier&action=switch_portal");
    exit();
}

// Handle the apply for loan action
if (isset($_GET['action']) && $_GET['action'] === 'apply_for_loan') {
    applyForLoan();
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
    // Log the issue for debugging
    error_log('Unauthorized access attempt to cashier_notifications.php. Session data: ' . print_r($_SESSION, true));

    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access'
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Cashier Portal</title>
    <script src="js/logout-handler.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <style>
    /* Base Styles */
    body {
        margin: 0;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background: #f5f9f7;
        color: #3a3a3a;
        display: flex;
        flex-direction: column;
        height: 100vh;
    }

    /* Header Styles */
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

    .logout-btn {
        background: rgba(255, 255, 255, 0.2);
        border: none;
        color: white;
        padding: 8px 12px;
        border-radius: 20px;
        cursor: pointer;
        display: flex;
        align-items: center;
        font-size: 0.9rem;
        transition: background 0.3s;
    }

    .logout-btn:hover {
        background: rgba(255, 255, 255, 0.3);
    }

    .logout-btn svg {
        margin-right: 5px;
    }

    /* Responsive adjustments */
    @media (max-width: 768px) {
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
            padding: 6px 10px;
            font-size: 0.8rem;
        }
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

    /* Main Content Styles */
    main {
        flex-grow: 1;
        padding: 100px 20px 20px 260px;
        overflow-y: auto;
        display: none;
        transition: all 0.3s ease;
    }

    main.sidebar-open {
        padding-left: 260px;
    }

    main.sidebar-collapsed {
        padding-left: 90px;
    }

    @media (max-width: 768px) {
        main {
            padding: 100px 15px 15px 15px;
        }

        #sidebar {
            width: 200px;
            transform: translateX(-100%);
        }

        #sidebar.open {
            transform: translateX(0);
        }
    }

    h2 {
        color: #4a6b4a;
        margin-bottom: 1.5rem;
        font-size: 1.5rem;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    /* Table Styles */
    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 1rem;
        font-size: 0.9rem;
        background: white;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    }

    th,
    td {
        border: 1px solid #e0e0e0;
        padding: 0.8rem;
        text-align: left;
        vertical-align: middle;
    }

    th {
        background: linear-gradient(135deg, #3a6b9a, #4a7bba);
        color: white;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.85rem;
        letter-spacing: 0.5px;
    }

    tr:hover {
        background: #f8f9fa;
    }

    /* Button Styles */
    button.action-btn {
        background: #4a6b4a;
        color: white;
        border: none;
        padding: 0.4rem 0.8rem;
        margin-right: 0.3rem;
        border-radius: 5px;
        cursor: pointer;
        font-size: 0.85rem;
        margin-bottom: 5px;
        transition: all 0.3s ease;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
    }

    button.action-btn:hover {
        background: #3a5a3a;
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
    }

    button.action-btn.reject-btn {
        background: #c0392b;
    }

    button.action-btn.reject-btn:hover {
        background: #a0291b;
    }

    /* Status Colors */
    .status-pending {
        background-color: #fff3cd;
    }

    .status-forwarded {
        background-color: #cce7ff;
    }

    .status-accepted {
        background-color: #d4edda;
    }

    .status-dispatched {
        background-color: #d1f2eb;
    }

    .status-active {
        background-color: #d1f2eb;
    }

    .status-rejected {
        background-color: #f8d7da;
    }

    .status-returned {
        background-color: #ffeaa7;
    }

    .status-closed {
        background-color: #e2e3e5;
    }

    /* Modal Styles */
    .modal {
        display: none;
        position: fixed;
        z-index: 2000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        overflow: auto;
        background-color: rgba(0, 0, 0, 0.5);
        animation: fadeIn 0.3s ease;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
        }

        to {
            opacity: 1;
        }
    }

    .modal-content {
        background-color: #fff;
        margin: 3% auto;
        padding: 25px;
        border-radius: 12px;
        width: 90%;
        max-width: 800px;
        position: relative;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        animation: slideIn 0.3s ease;
        max-height: 85vh;
        overflow-y: auto;
    }

    @keyframes slideIn {
        from {
            transform: translateY(-50px);
            opacity: 0;
        }

        to {
            transform: translateY(0);
            opacity: 1;
        }
    }

    .close-btn {
        color: #aaa;
        float: right;
        font-size: 28px;
        cursor: pointer;
        transition: color 0.3s ease;
    }

    .close-btn:hover {
        color: #000;
    }

    /* Form Styles */
    .balance-highlight {
        background: linear-gradient(135deg, #e8f5e9, #f1f8e9);
        padding: 20px;
        border-radius: 10px;
        margin-bottom: 20px;
        border-left: 5px solid #2e7d32;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    }

    /* Payment Form Styles */
    .payment-input-group {
        background: #f8f9fa;
        padding: 20px;
        border-radius: 10px;
        margin-bottom: 20px;
        border: 1px solid #dee2e6;
    }

    .payment-input-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-bottom: 15px;
    }

    .input-helper {
        font-size: 0.85rem;
        color: #666;
        margin-top: 5px;
        font-style: italic;
    }

    .calculation-preview {
        background: linear-gradient(135deg, #fff3cd, #fef5e7);
        padding: 15px;
        border-radius: 8px;
        margin-top: 20px;
        border-left: 4px solid #ffc107;
    }

    .calculation-preview h4 {
        margin: 0 0 10px 0;
        color: #856404;
    }

    .calc-row {
        display: flex;
        justify-content: space-between;
        padding: 8px 0;
        border-bottom: 1px dashed #dee2e6;
    }

    .calc-row:last-child {
        border-bottom: none;
        font-weight: bold;
        margin-top: 10px;
        padding-top: 10px;
        border-top: 2px solid #856404;
    }

    /* Notification Bell Styles */
    .notification-bell-container {
        position: relative;
    }

    .notification-bell {
        background: rgba(255, 255, 255, 0.2);
        border: none;
        color: white;
        font-size: 1.5rem;
        padding: 8px 12px;
        border-radius: 50%;
        cursor: pointer;
        position: relative;
        transition: all 0.3s;
    }

    .notification-bell:hover {
        background: rgba(255, 255, 255, 0.3);
        transform: scale(1.1);
    }

    .notification-badge {
        position: absolute;
        top: -5px;
        right: -5px;
        background: #e74c3c;
        color: white;
        border-radius: 50%;
        padding: 2px 6px;
        font-size: 0.7rem;
        font-weight: bold;
        min-width: 18px;
        text-align: center;
    }

    .notification-dropdown {
        position: absolute;
        top: 50px;
        right: 0;
        width: 350px;
        max-height: 500px;
        background: white;
        border-radius: 8px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
        z-index: 1000;
        overflow: hidden;
    }

    .notification-header {
        padding: 15px;
        border-bottom: 1px solid #e0e0e0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: #f8f9fa;
    }

    .notification-header h3 {
        margin: 0;
        font-size: 1.1rem;
        color: #333;
    }

    .mark-all-read-btn {
        background: transparent;
        border: none;
        color: #2196F3;
        cursor: pointer;
        font-size: 0.85rem;
        padding: 5px 10px;
        border-radius: 4px;
        transition: background 0.3s;
    }

    .mark-all-read-btn:hover {
        background: rgba(33, 150, 243, 0.1);
    }

    .notification-list {
        max-height: 400px;
        overflow-y: auto;
    }

    .notification-item {
        padding: 12px 15px;
        border-bottom: 1px solid #e0e0e0;
        cursor: pointer;
        transition: background 0.3s;
        position: relative;
    }

    .notification-item:hover {
        background: #f8f9fa;
    }

    .notification-item.unread {
        background: #e3f2fd;
        border-left: 3px solid #2196F3;
    }

    .notification-item.unread:hover {
        background: #d1e7ff;
    }

    .notification-message {
        font-size: 0.9rem;
        color: #333;
        margin-bottom: 5px;
        line-height: 1.4;
    }

    .notification-time {
        font-size: 0.75rem;
        color: #999;
    }

    .notification-delete {
        position: absolute;
        top: 10px;
        right: 10px;
        background: #e74c3c;
        color: white;
        border: none;
        padding: 3px 8px;
        border-radius: 4px;
        font-size: 0.7rem;
        cursor: pointer;
        opacity: 0;
        transition: opacity 0.3s;
    }

    .notification-item:hover .notification-delete {
        opacity: 1;
    }

    .notification-empty {
        padding: 40px 20px;
        text-align: center;
        color: #999;
    }

    .notification-footer {
        padding: 10px;
        text-align: center;
        border-top: 1px solid #e0e0e0;
        background: #f8f9fa;
    }

    .notification-footer a {
        color: #2196F3;
        text-decoration: none;
        font-size: 0.9rem;
    }

    .notification-footer a:hover {
        text-decoration: underline;
    }

    /* Responsive adjustments */
    @media (max-width: 768px) {
        .notification-bell-container {
            position: static;
            margin-top: 10px;
        }

        .notification-dropdown {
            width: 300px;
            right: -100px;
        }
    }

    /* Loading Indicator */
    .loading-indicator {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 3px solid rgba(255, 255, 255, .3);
        border-radius: 50%;
        border-top-color: #fff;
        animation: spin 1s ease-in-out infinite;
        margin-right: 10px;
    }

    @keyframes spin {
        to {
            transform: rotate(360deg);
        }
    }

    .error-message {
        color: #c0392b;
        background: #f8d7da;
        padding: 15px;
        border-radius: 6px;
        margin: 10px 0;
        border-left: 4px solid #c0392b;
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

    /* Grace Period Notice Styles */
    .grace-period-notice {
        background: #e3f2fd;
        border: 1px solid #bbdefb;
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 20px;
        color: #0d47a1;
    }

    .grace-period-notice h4 {
        margin-top: 0;
        color: #0d47a1;
    }

    /* Date Range Selector Styles */
    .date-range-selector {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-bottom: 20px;
        padding: 15px;
        background: #f8f9fa;
        border-radius: 8px;
        border: 1px solid #dee2e6;
    }

    .date-range-selector label {
        font-weight: 600;
        color: #495057;
    }

    .date-range-selector input[type="date"] {
        padding: 8px 12px;
        border: 1px solid #ced4da;
        border-radius: 4px;
        font-size: 0.9rem;
    }

    .date-range-selector button {
        padding: 8px 15px;
        background: #007bff;
        color: white;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 0.9rem;
        transition: background 0.3s;
    }

    .date-range-selector button:hover {
        background: #0069d9;
    }

    /* Export Options Styles */
    .export-options {
        display: flex;
        gap: 10px;
        margin-bottom: 15px;
    }

    .export-options button {
        padding: 8px 15px;
        background: #007bff;
        color: white;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 0.9rem;
        transition: background 0.3s;
    }

    .export-options button:hover {
        background: #0069d9;
    }

    .export-options button.active {
        background: #28a745;
    }

    /* Responsive Design */
    @media (max-width: 768px) {
        .payment-input-row {
            grid-template-columns: 1fr;
        }

        .modal-content {
            width: 95%;
            margin: 5% auto;
        }

        .date-range-selector {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }
    }

    @keyframes spin {
        0% {
            transform: rotate(0deg);
        }

        100% {
            transform: rotate(360deg);
        }
    }
    </style>
</head>

<body>
    <!-- CSRF Token -->
    <input type="hidden" id="csrf-token-data"
        data-csrf-token="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

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
            <h1>Cashier Portal</h1>
        </div>

        <!-- Add cashier profile badge -->
        <div class="profile-badge">
            <div class="profile-info">
                <div class="profile-avatar">
                    <img src="https://picsum.photos/seed/cashier123/40/40.jpg" alt="Profile" class="avatar-img">
                </div>
                <div class="profile-details">
                    <div class="profile-name"><?php echo htmlspecialchars($userData['fullName']); ?></div>
                    <div class="profile-role"><?php echo ucfirst(htmlspecialchars($userData['role'])); ?></div>
                </div>
            </div>
        </div>

        <!-- Add this after the profile-badge div, before closing header-container -->
        <div class="notification-bell-container"
            style="position: absolute; right: 250px; top: 50%; transform: translateY(-50%);">
            <button id="notificationBell" class="notification-bell" onclick="toggleNotificationDropdown()">
                🔔
                <span id="notificationBadge" class="notification-badge" style="display: none;">0</span>
            </button>

            <!-- Notification Dropdown -->
            <div id="notificationDropdown" class="notification-dropdown" style="display: none;">
                <div class="notification-header">
                    <h3>Notifications</h3>
                    <button onclick="markAllAsRead()" class="mark-all-read-btn">Mark all as read</button>
                </div>
                <div id="notificationList" class="notification-list">
                    <div class="notification-empty">No new notifications</div>
                </div>
                <div class="notification-footer">
                    <a href="#" onclick="viewAllNotifications(); return false;">View All</a>
                </div>
            </div>
        </div>
    </header>

    <!-- Sidebar -->
    <aside id="sidebar">
        <!-- Sidebar Logo -->
        <div class="sidebar-logo">
            <img src="logo.jpg" alt="Company Logo">
            <div class="sidebar-logo-text">Cashier Portal</div>
        </div>

        <!-- Sidebar Buttons -->
        <button class="active tooltip" data-filter="all">
            <span class="button-icon">📋</span>
            <span class="button-text">All Loans</span>
            <span class="tooltiptext">All Loans</span>
        </button>
        <button class="tooltip" data-filter="pending">
            <span class="button-icon">⏳</span>
            <span class="button-text">Pending Loans</span>
            <span class="tooltiptext">Pending Loans</span>
        </button>
        <button class="tooltip" data-filter="forwarded">
            <span class="button-icon">➡️</span>
            <span class="button-text">Forwarded to Accountant</span>
            <span class="tooltiptext">Forwarded to Accountant</span>
        </button>
        <button class="tooltip" data-filter="accepted">
            <span class="button-icon">✅</span>
            <span class="button-text">Accepted Loans</span>
            <span class="tooltiptext">Accepted Loans</span>
        </button>
        <button class="tooltip" data-filter="dispatched">
            <span class="button-icon">📦</span>
            <span class="button-text">Dispatched Loans</span>
            <span class="tooltiptext">Dispatched Loans</span>
        </button>
        <button class="tooltip" data-filter="active">
            <span class="button-icon">💰</span>
            <span class="button-text">Active Loans</span>
            <span class="tooltiptext">Active Loans</span>
        </button>
        <button class="tooltip" data-filter="rejected">
            <span class="button-icon">❌</span>
            <span class="button-text">Rejected Loans</span>
            <span class="tooltiptext">Rejected Loans</span>
        </button>
        <button class="tooltip" data-filter="returned">
            <span class="button-icon">↩️</span>
            <span class="button-text">Returned by Accountant</span>
            <span class="tooltiptext">Returned by Accountant</span>
        </button>
        <button class="tooltip" data-filter="closed">
            <span class="button-icon">🔒</span>
            <span class="button-text">Closed Loans</span>
            <span class="tooltiptext">Closed Loans</span>
        </button>
        <button class="tooltip" data-filter="reconciliation">
            <span class="button-icon">💰</span>
            <span class="button-text">Reconciliation</span>
            <span class="tooltiptext">Reconciliation</span>
        </button>

        <button class="tooltip" data-filter="reports">
            <span class="button-icon">📊</span>
            <span class="button-text">Financial Reports</span>
            <span class="tooltiptext">Financial Reports</span>
        </button>
        <button class="tooltip" onclick="applyForLoan()">
            <span class="button-icon">📝</span>
            <span class="button-text">Apply for Loan</span>
            <span class="tooltiptext">Apply for a Loan</span>
        </button>

        <button class="tooltip" id="logoutBtn" style="margin-top: auto; background: #c0392b;">
            <span class="button-icon">🚪</span>
            <span class="button-text">Logout</span>
            <span class="tooltiptext">Logout</span>
        </button>
    </aside>

    <!-- Main Content -->
    <main id="portalSection">
        <h2 id="sectionTitle">All Loans</h2>

        <!-- Main Content Area -->
        <div id="mainContent">
            <div id="loadingIndicator" style="text-align: center; padding: 40px; display: none;">
                <div class="loading-indicator"></div>
                Loading loan data...
            </div>

            <div id="errorMessage" class="error-message" style="display: none;">
                Failed to load loan data. Please try refreshing page.
            </div>

            <table id="loansTable">
                <thead>
                    <tr>
                        <th>Full Name</th>
                        <th>ID Number</th>
                        <th>PF Number</th>
                        <th>Loan Type</th>
                        <th>Details</th>
                        <th>Loan Amount</th>
                        <th>Current Balance</th>
                        <th>Amortization</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </main>

    <!-- Loan Details Modal -->
    <div id="detailsModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" id="closeDetailsModal">&times;</span>
            <h2>📄 Loan Details</h2>
            <div id="loanDetailsContent"></div>
        </div>
    </div>

    <!-- Feedback Modal -->
    <div id="feedbackModal" class="modal">
        <div class="modal-content" style="max-width:500px;">
            <span class="close-btn" id="cancelFeedback">&times;</span>
            <h2 id="feedbackTitle">Provide Feedback</h2>
            <textarea id="feedbackTextarea"
                style="width:100%; min-height:120px; margin-top:15px; padding:12px; border:2px solid #e0e0e0; border-radius:6px; resize:vertical;"
                placeholder="Enter your feedback or reason here..."></textarea>
            <div style="text-align:right; margin-top:20px;">
                <button id="submitFeedback" class="action-btn" style="padding: 10px 20px;">
                    ✅ Submit
                </button>
                <button id="cancelFeedbackButton" class="action-btn reject-btn" style="padding: 10px 20px;">
                    ❌ Cancel
                </button>
            </div>
        </div>
    </div>

    <!-- Amortization Modal -->
    <div id="amortModal" class="modal">
        <div class="modal-content" style="max-width:1200px;">
            <span class="close-btn" id="closeAmortModal">&times;</span>
            <h2>📊 Payment Schedule & Amortization</h2>
            <div id="amortInfo" class="balance-highlight"></div>
            <div style="overflow-x: auto;">
                <table style="width:100%; border-collapse:collapse; font-size:0.85rem; min-width:800px;">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Due Date</th>
                            <th>Expected Payment</th>
                            <th>Actual Payment</th>
                            <th>Expected Interest</th>
                            <th>Actual Interest</th>
                            <th>Principal</th>
                            <th>Balance</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="amortTableBody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Detailed Reconciliation Modal -->
    <div id="detailedReconciliationModal" class="modal">
        <div class="modal-content" style="max-width:1200px;">
            <span class="close-btn" id="closeDetailedReconciliationModal">&times;</span>
            <h2>📊 Detailed Payment Reconciliation</h2>
            <div id="detailedReconciliationContent"></div>
        </div>
    </div>

    <!-- Record Payment Modal -->
    <div id="recordPaymentModal" class="modal">
        <div class="modal-content" style="max-width:700px;">
            <span class="close-btn" id="closePaymentModal">&times;</span>
            <h2>💳 Record Payment</h2>
            <div id="paymentLoaneeInfo" class="balance-highlight"></div>

            <div class="payment-input-group">
                <h4 style="margin-top:0; color:#2c5f2d;">💰 Payment Breakdown</h4>

                <div class="payment-input-row">
                    <div>
                        <label>Principal Amount (KES) <span style="color:red;">*</span></label>
                        <input type="number" id="principalAmount" placeholder="Enter principal payment" min="0"
                            step="0.01">
                        <div class="input-helper">Amount going towards loan principal</div>
                    </div>

                    <div>
                        <label>Interest Amount (KES) <span style="color:red;">*</span></label>
                        <input type="number" id="interestAmount" placeholder="Enter interest amount" min="0"
                            step="0.01">
                        <div class="input-helper">Interest portion of this payment</div>
                    </div>
                </div>

                <div style="margin-top: 20px;">
                    <label style="color:#2e7d32; font-weight:bold;">Total Payment Amount (KES)</label>
                    <input type="number" id="totalPaymentAmount" placeholder="Auto-calculated" readonly
                        style="background:#e8f5e9; font-weight:bold; font-size:1.1rem;">
                    <div class="input-helper" style="color:#2e7d32;">Automatically calculated: Principal + Interest
                    </div>
                </div>

                <div class="calculation-preview" id="paymentCalculationPreview" style="display:none;">
                    <h4>💡 Payment Summary</h4>
                    <div class="calc-row">
                        <span>Principal Paid:</span>
                        <span id="previewPrincipal" style="color:#2e7d32;">KES 0</span>
                    </div>
                    <div class="calc-row">
                        <span>Interest Charged:</span>
                        <span id="previewInterest" style="color:#d84315;">KES 0</span>
                    </div>
                    <div class="calc-row">
                        <span>Total Payment:</span>
                        <span id="previewTotalPayment" style="font-weight:bold;">KES 0</span>
                    </div>
                    <div class="calc-row">
                        <span>New Balance:</span>
                        <span id="previewNewBalance" style="color:#c62828;">KES 0</span>
                    </div>
                </div>
            </div>

            <label>Payment Date <span style="color:red;">*</span></label>
            <input type="date" id="paymentDate"
                style="width:100%; padding:10px; margin-bottom:15px; border:2px solid #e0e0e0; border-radius:6px;">

            <label>Payment Source <span style="color:red;">*</span></label>
            <select id="paymentSource"
                style="width:100%; padding:10px; margin-bottom:15px; border:2px solid #e0e0e0; border-radius:6px;">
                <option value="">Select payment source</option>
                <option value="Salary Deduction">💼 Salary Deduction</option>
                <option value="Bank Transfer">🏦 Bank Transfer</option>
                <option value="Cash Deposit">💵 Cash Deposit</option>
            </select>

            <label>Transaction/Reference ID <span style="color:red;">*</span></label>
            <input type="text" id="transactionReference" placeholder="e.g., TXN123456, REF-2024-001" maxlength="100"
                style="width:100%; padding:10px; margin-bottom:15px; border:2px solid #e0e0e0; border-radius:6px;">
            <div class="input-helper">Bank transaction ID, receipt number, or any reference for this payment</div>

            <label>Payment Notes (Optional):</label>
            <textarea id="paymentNotes" placeholder="Add any notes about this payment..."
                style="width:100%; min-height:80px; padding:12px; margin-bottom:20px; border:2px solid #e0e0e0; border-radius:6px; resize:vertical;"></textarea>

            <div style="text-align:right;">
                <button id="submitPayment" class="action-btn" style="padding: 12px 25px; font-size: 1rem;">
                    💾 Record Payment
                </button>
                <button id="cancelPayment" class="action-btn reject-btn" style="padding: 12px 25px; font-size: 1rem;">
                    ❌ Cancel
                </button>
            </div>
        </div>
    </div>

    <!-- Notification -->
    <div id="notification" class="notification"></div>

    <script>
    // ==========================================
    // GLOBAL VARIABLES AND CONFIGURATION
    // ==========================================
    // API Configuration
    const API_CONFIG = {
        getBaseUrl() {
            const hostname = window.location.hostname;
            const pathname = window.location.pathname;
            const basePath = pathname.substring(0, pathname.lastIndexOf('/'));
            if (hostname === 'localhost' || hostname === '127.0.0.1') {
                return 'http://localhost/loan-system/api';
            } else {
                return '/loan-system/api';
            }
        }
    };
    const API_BASE_URL = API_CONFIG.getBaseUrl();
    ////console.log('API Base URL:', API_BASE_URL);

    // Authentication and User Data
    const APP_STATE = {
        loggedInFromMain: <?php echo $loggedInFromMain ? 'true' : 'false'; ?>,
        userData: <?php echo json_encode($userData); ?>,
        cashierPermissions: <?php echo json_encode($cashierPermissions ?? []); ?>,
        currentUser: null,
        csrfToken: null,
        currentPaymentLoan: null,
        currentActionLoan: null,
        currentActionType: null,
        sidebarOpen: true,
        sidebarCollapsed: false,
        reconDateRange: {
            from: null,
            to: null
        }
    };

    // DOM Element References
    const DOM_ELEMENTS = {
        portalSection: document.getElementById('portalSection'),
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
    // API FUNCTIONS
    // ==========================================
    async function apiCall(endpoint, method = 'GET', data = null) {
        const options = {
            method: method,
            headers: {
                'Content-Type': 'application/json',
            },
            credentials: 'include' // Add this line to include session cookies
        };
        if (APP_STATE.csrfToken) {
            options.headers['X-CSRF-Token'] = APP_STATE.csrfToken;
        }
        if (data) {
            options.body = JSON.stringify(data);
        }
        try {
            const response = await fetch(`${API_BASE_URL}/${endpoint}`, options);
            if (!response.ok) {
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
                ////console.error('Non-JSON response received:', text.substring(0, 500));
                throw new Error('Server returned an invalid response. Please contact support.');
            }
            const result = await response.json();
            if (!result.success) {
                throw new Error(result.message || 'Request failed');
            }
            return result;
        } catch (error) {
            /*//console.error('API call error:', {
                endpoint,
                method,
                error: error.message
            });*/
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
    // LOAN CALCULATIONS
    // ==========================================
    function calculateMonthlyInterest(principal, annualRate, months = 1) {
        if (principal <= 0 || annualRate <= 0) return 0;
        const monthlyRate = annualRate / 100 / 12;
        return principal * monthlyRate * Math.max(1, months);
    }

    async function recalculateLoanBalance(loan) {
        const annualRate = parseFloat(<?php echo $interest_rate ?? 3; ?>);
        const payments = Array.isArray(loan.payments) ? loan.payments : [];

        let totalPaid = 0;
        let principalPaid = 0;
        let interestPaid = 0;

        payments.forEach(payment => {
            const amount = parseFloat(payment.amount) || 0;
            const principal = parseFloat(payment.principal_amount) || 0;
            const interest = parseFloat(payment.interest_amount) || 0;

            totalPaid += amount;
            principalPaid += principal;
            interestPaid += interest;
        });

        const originalAmount = parseFloat(loan.loan_amount) || 0;
        const currentPrincipal = Math.max(0, originalAmount - principalPaid);

        let lastPaymentDate = null;
        if (payments.length > 0) {
            const sortedPayments = [...payments].sort((a, b) =>
                new Date(b.payment_date) - new Date(a.payment_date)
            );
            lastPaymentDate = new Date(sortedPayments[0].payment_date);
        } else {
            lastPaymentDate = loan.dispatch_date ? new Date(loan.dispatch_date) : new Date();
        }

        const today = new Date();
        const timeDiff = today - lastPaymentDate;
        const daysSinceLastPayment = Math.floor(timeDiff / (1000 * 60 * 60 * 24));
        const monthsSinceLastPayment = Math.max(1, Math.round(daysSinceLastPayment / 30));

        const accruedInterest = currentPrincipal > 0 ?
            calculateMonthlyInterest(currentPrincipal, annualRate, monthsSinceLastPayment) :
            0;

        const totalBalanceDue = currentPrincipal + accruedInterest;

        return {
            originalAmount,
            totalPaid,
            principalPaid,
            interestPaid,
            currentPrincipal,
            accruedInterest: accruedInterest.toFixed(2),
            totalBalanceDue: totalBalanceDue.toFixed(2),
            lastPaymentDate: lastPaymentDate.toISOString().split('T')[0],
            monthsSinceLastPayment,
            daysSinceLastPayment
        };
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
            ////console.log('Mortgage loans found:', mortgageLoans.length);
            mortgageLoans.forEach(loan => {
                /*//console.log(`Mortgage loan ${loan.application_id}:`, {
              property_location: loan.property_location,
              property_value: loan.property_value
          });*/
            });

            // Debug logging for car loans
            const carLoans = loans.filter(l => l.loan_type === 'car');
            ////console.log('Car loans found:', carLoans.length);
            carLoans.forEach(loan => {
                /*//console.log(`Car loan ${loan.application_id}:`, {
                    car_type: loan.car_type,
                    car_value: loan.car_value
                });*/
            });

            return loans;
        } catch (error) {
            ////console.error('Error fetching loans:', error);
            return [];
        }
    }

    async function getPaymentsForLoan(applicationId) {
        try {
            const response = await apiCall(`payments.php?application_id=${applicationId}`);
            return response.data || [];
        } catch (error) {
            ////console.error('Error fetching payments:', error);
            return [];
        }
    }

    function generateLoanId(loan) {
        return `${loan.id_number}_${loan.pf_number}_${loan.loan_type}_${loan.application_date}`;
    }

    // ==========================================
    // RENDER FUNCTIONS
    // ==========================================
    async function renderLoans(filter) {
        // Hide loading indicator at the beginning
        DOM_ELEMENTS.loadingIndicator.style.display = 'none';
        DOM_ELEMENTS.errorMessage.style.display = 'none';

        if (filter === 'reconciliation') {
            // Hide reports content if visible
            const reportsDiv = document.getElementById('reportsContent');
            if (reportsDiv) {
                reportsDiv.style.display = 'none';
            }
            // Pass null instead of undefined loans variable
            showReconciliationView(null);
            return;
        } else if (filter === 'reports') {
            // Hide reconciliation content if visible
            const reconciliationDiv = document.getElementById('reconciliationContent');
            if (reconciliationDiv) {
                reconciliationDiv.style.display = 'none';
            }
            // Pass null instead of undefined loans variable
            showReportsView(null);
            return;
        } else {
            // Hide both dashboard contents when showing regular loan views
            DOM_ELEMENTS.mainContent.style.display = 'block';
            DOM_ELEMENTS.loansTable.style.display = 'table';

            const reconciliationDiv = document.getElementById('reconciliationContent');
            if (reconciliationDiv) {
                reconciliationDiv.style.display = 'none';
            }

            const reportsDiv = document.getElementById('reportsContent');
            if (reportsDiv) {
                reportsDiv.style.display = 'none';
            }
        }

        // Get loans data for other filters
        let loans = await getAllLoans();

        if (!DOM_ELEMENTS.loansTable) {
            DOM_ELEMENTS.mainContent.innerHTML = `
    <div id="loadingIndicator" style="text-align: center; padding: 40px; display: none;">
        <div class="loading-indicator"></div>
        Loading loan data...
    </div>
    <div id="errorMessage" class="error-message" style="display: none;">
        Failed to load loan data. Please try refreshing page.
    </div>
    <table id="loansTable">
        <thead>
            <tr>
                <th>Full Name</th>
                <th>ID Number</th>
                <th>PF Number</th>
                <th>Loan Type</th>
                <th>Details</th>
                <th>Loan Amount</th>
                <th>Current Balance</th>
                <th>Amortization</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
`;
        }

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
            case 'all':
            default:
                DOM_ELEMENTS.sectionTitle.textContent = 'All Loans';
        }

        const existingExportBtn = document.getElementById('exportAllLoansBtn');
        if (existingExportBtn) {
            existingExportBtn.remove();
        }
        if (filter !== 'reconciliation' && hasPermission('canExportData')) {
            const exportBtn = document.createElement('button');
            exportBtn.id = 'exportAllLoansBtn';
            exportBtn.textContent = 'Export to CSV';
            exportBtn.className = 'action-btn';
            exportBtn.style.marginLeft = '15px';
            exportBtn.style.background = '#2196F3';
            exportBtn.onclick = exportAllLoans;
            DOM_ELEMENTS.sectionTitle.appendChild(exportBtn);
        }

        const loansTableBody = DOM_ELEMENTS.loansTable.querySelector('tbody');
        if (!loansTableBody) {
            ////console.error('Critical Error: The loans table body was not found in the DOM.');
            DOM_ELEMENTS.errorMessage.style.display = 'block';
            DOM_ELEMENTS.errorMessage.textContent =
                'The loan table could not be initialized. Please refresh the page.';
            DOM_ELEMENTS.loadingIndicator.style.display = 'none';
            return;
        }

        loansTableBody.innerHTML = '';
        if (loans.length === 0) {
            loansTableBody.innerHTML =
                '<tr><td colspan="10" style="text-align:center; padding: 30px;">No loan applications found for this filter.</td></tr>';
            DOM_ELEMENTS.loadingIndicator.style.display = 'none';
            return;
        }

        loans.sort((a, b) => new Date(b.application_date) - new Date(a.application_date));

        // Fetch payment data for all loans that need balance calculation
        const loansNeedingPayments = loans.filter(loan =>
            loan.status === 'active' || loan.status === 'dispatched'
        );

        // Create an array of promises to fetch payments for each loan
        const paymentPromises = loansNeedingPayments.map(async (loan) => {
            try {
                const payments = await getPaymentsForLoan(loan.application_id);
                loan.payments = payments;
                return loan;
            } catch (error) {
                ////console.error(`Error fetching payments for loan ${loan.application_id}:`, error);
                loan.payments = [];
                return loan;
            }
        });

        // Wait for all payment data to be fetched
        await Promise.all(paymentPromises);

        // Now render the table with the updated loan data
        loans.forEach(loan => {
            const row = loansTableBody.insertRow();
            row.className = `status-${loan.status}`;
            row.insertCell().textContent = loan.full_name || 'N/A';
            row.insertCell().textContent = loan.id_number || 'N/A';
            row.insertCell().textContent = loan.pf_number || 'N/A';
            row.insertCell().textContent = loan.loan_type ? loan.loan_type.charAt(0)
                .toUpperCase() + loan
                .loan_type.slice(1) : 'N/A';

            const detailsCell = row.insertCell();
            const detailsBtn = document.createElement('button');
            detailsBtn.textContent = 'View';
            detailsBtn.className = 'action-btn';
            detailsBtn.onclick = () => showLoanDetails(loan);
            detailsCell.appendChild(detailsBtn);

            row.insertCell().textContent = loan.loan_amount ?
                `KES ${formatCurrency(loan.loan_amount)}` :
                'N/A';

            const balanceCell = row.insertCell();
            if (loan.status === 'active' || loan.status === 'dispatched') {
                // Calculate balance with payment data
                recalculateLoanBalance(loan).then(balanceInfo => {
                    balanceCell.innerHTML =
                        `<strong>KES ${formatCurrency(balanceInfo.totalBalanceDue)}</strong>`;
                    if (balanceInfo.accruedInterest > 0) {
                        balanceCell.innerHTML +=
                            `<br><small style="color:#d84315;">+KES ${formatCurrency(balanceInfo.accruedInterest)} interest</small>`;
                    }
                });
            } else {
                balanceCell.textContent = '-';
            }

            const amortCell = row.insertCell();
            const amortBtn = document.createElement('button');
            amortBtn.textContent = 'Schedule';
            amortBtn.className = 'action-btn';
            amortBtn.onclick = () => showAmortization(loan);
            amortCell.appendChild(amortBtn);

            row.insertCell().textContent = loan.status ? loan.status.toUpperCase() : 'PENDING';

            const actionsCell = row.insertCell();
            actionsCell.className = 'actions-cell';
            actionsCell.innerHTML = '';

            if (loan.status === 'pending' || loan.status === 'returned') {
                if (hasPermission('canForwardLoans')) {
                    const forwardBtn = document.createElement('button');
                    forwardBtn.textContent = 'Forward';
                    forwardBtn.className = 'action-btn';
                    forwardBtn.onclick = () => handleLoanAction(loan, 'forward');
                    actionsCell.appendChild(forwardBtn);
                }
                if (hasPermission('canRejectLoans')) {
                    const rejectBtn = document.createElement('button');
                    rejectBtn.textContent = 'Reject';
                    rejectBtn.className = 'action-btn reject-btn';
                    rejectBtn.onclick = () => showFeedbackModal(loan, 'reject');
                    actionsCell.appendChild(rejectBtn);
                }
            } else if (loan.status === 'forwarded') {
                actionsCell.innerHTML =
                    '<span style="color:#666;">Awaiting Accountant...</span>';
            } else if (loan.status === 'accepted') {
                actionsCell.innerHTML =
                    '<span style="color:#16a085; font-weight:bold;">Awaiting Dispatch</span>';
            } else if (loan.status === 'dispatched' || loan.status === 'active') {
                if (hasPermission('canRecordPayments')) {
                    const paymentBtn = document.createElement('button');
                    paymentBtn.textContent = 'Record';
                    paymentBtn.className = 'action-btn';
                    paymentBtn.style.background = '#2c7a2c';
                    paymentBtn.style.fontWeight = 'bold';
                    paymentBtn.onclick = () => {
                        if (!loan.application_id) {
                            showNotification('Error: Loan application ID is missing',
                                'error');
                            return;
                        }
                        showRecordPaymentModal(loan);
                    };
                    actionsCell.appendChild(paymentBtn);
                }
                if (hasPermission('canCloseLoans')) {
                    const closeBtn = document.createElement('button');
                    closeBtn.textContent = 'Close';
                    closeBtn.className = 'action-btn';
                    closeBtn.onclick = () => handleLoanAction(loan, 'close');
                    actionsCell.appendChild(closeBtn);
                }
            } else if (loan.status === 'rejected') {
                if (hasPermission('canCloseLoans')) {
                    const closeBtn = document.createElement('button');
                    closeBtn.textContent = 'Close';
                    closeBtn.className = 'action-btn';
                    closeBtn.onclick = () => handleLoanAction(loan, 'close');
                    actionsCell.appendChild(closeBtn);
                }
            } else if (loan.status === 'closed') {
                actionsCell.innerHTML = '<span style="color:#666;">Case Closed</span>';
            }
        });

        DOM_ELEMENTS.loadingIndicator.style.display = 'none';
    }

    // ==========================================
    // REPORTS VIEW FUNCTION
    // ==========================================

    async function showReportsView(loans) {
        // Hide loading indicator
        DOM_ELEMENTS.loadingIndicator.style.display = 'none';
        DOM_ELEMENTS.errorMessage.style.display = 'none';

        if (!loans) {
            loans = await getAllLoans();
        }

        DOM_ELEMENTS.sectionTitle.textContent = '📊 Financial Reports & Analytics';
        DOM_ELEMENTS.mainContent.style.display = 'block';
        DOM_ELEMENTS.loansTable.style.display = 'none';

        // Fetch payment data for all active/dispatched loans
        const activeLoans = loans.filter(l => l.status === 'active' || l.status === 'dispatched');
        const closedLoans = loans.filter(l => l.status === 'closed');

        const paymentPromises = [...activeLoans, ...closedLoans].map(async (loan) => {
            try {
                const payments = await getPaymentsForLoan(loan.application_id);
                loan.payments = payments;
                return loan;
            } catch (error) {
                ////console.error(`Error fetching payments for loan ${loan.application_id}:`, error);
                loan.payments = [];
                return loan;
            }
        });

        await Promise.all(paymentPromises);

        // Calculate statistics by loan type
        const mortgageLoans = loans.filter(l => l.loan_type === 'mortgage');
        const carLoans = loans.filter(l => l.loan_type === 'car');

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

        let html = `
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px;">
        <!-- Overall Summary Card -->
        <div class="balance-highlight" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; position: relative;">
            <button onclick="exportOverallSummary()" 
                    style="position: absolute; top: 10px; right: 10px; background: rgba(255,255,255,0.3); border: none; color: white; padding: 5px 10px; border-radius: 5px; cursor: pointer; font-size: 0.8rem; transition: all 0.3s;"
                    onmouseover="this.style.background='rgba(255,255,255,0.5)'"
                    onmouseout="this.style.background='rgba(255,255,255,0.3)'">
                📊 Export
            </button>
            <h3 style="margin-top:0; color: white; padding-right: 80px;">💼 Overall Summary</h3>
            <p><strong>Total Loaned:</strong> KES ${formatCurrency(totalLoanedAmount)}</p>
            <p><strong>Total Loans:</strong> ${activeLoans.length + closedLoans.length}</p>
            <p><strong>Active Loans:</strong> ${activeLoans.length}</p>
            <p><strong>Closed Loans:</strong> ${closedLoans.length}</p>
        </div>

        <!-- Mortgage Summary Card -->
        <div class="balance-highlight" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; border: none; position: relative;">
            <button onclick="exportMortgageLoans()" 
                    style="position: absolute; top: 10px; right: 10px; background: rgba(255,255,255,0.3); border: none; color: white; padding: 5px 10px; border-radius: 5px; cursor: pointer; font-size: 0.8rem; transition: all 0.3s;"
                    onmouseover="this.style.background='rgba(255,255,255,0.5)'"
                    onmouseout="this.style.background='rgba(255,255,255,0.3)'">
                📊 Export
            </button>
            <h3 style="margin-top:0; color: white; padding-right: 80px;">🏠 Mortgage Loans</h3>
            <p><strong>Total Count:</strong> ${mortgageStats.count}</p>
            <p><strong>Total Loaned:</strong> KES ${formatCurrency(mortgageStats.loaned)}</p>
            <p><strong>Total Paid:</strong> KES ${formatCurrency(mortgageStats.paid)}</p>
            <p><strong>Outstanding:</strong> KES ${formatCurrency(mortgageStats.balance)}</p>
        </div>

        <!-- Car Loan Summary Card -->
        <div class="balance-highlight" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; border: none; position: relative;">
            <button onclick="exportCarLoans()" 
                    style="position: absolute; top: 10px; right: 10px; background: rgba(255,255,255,0.3); border: none; color: white; padding: 5px 10px; border-radius: 5px; cursor: pointer; font-size: 0.8rem; transition: all 0.3s;"
                    onmouseover="this.style.background='rgba(255,255,255,0.5)'"
                    onmouseout="this.style.background='rgba(255,255,255,0.3)'">
                📊 Export
            </button>
            <h3 style="margin-top:0; color: white; padding-right: 80px;">🚗 Car Loans</h3>
            <p><strong>Total Count:</strong> ${carStats.count}</p>
            <p><strong>Total Loaned:</strong> KES ${formatCurrency(carStats.loaned)}</p>
            <p><strong>Total Paid:</strong> KES ${formatCurrency(carStats.paid)}</p>
            <p><strong>Outstanding:</strong> KES ${formatCurrency(carStats.balance)}</p>
        </div>

        <!-- Payment Summary Card -->
        <div class="balance-highlight" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); color: white; border: none; position: relative;">
            <button onclick="exportPaymentSummary()" 
                    style="position: absolute; top: 10px; right: 10px; background: rgba(255,255,255,0.3); border: none; color: white; padding: 5px 10px; border-radius: 5px; cursor: pointer; font-size: 0.8rem; transition: all 0.3s;"
                    onmouseover="this.style.background='rgba(255,255,255,0.5)'"
                    onmouseout="this.style.background='rgba(255,255,255,0.3)'">
                📊 Export
            </button>
            <h3 style="margin-top:0; color: white; padding-right: 80px;">💰 Payment Summary</h3>
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
            <h3 style="margin-top:0; color:#2e7d32;">📋 Active Loans Details</h3>
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

        <!-- Export Button -->
        <button onclick="exportOverallSummary()" class="action-btn" style="background:#2196F3; padding:12px 25px; font-size:1rem;">
            📊 Export Complete Report
        </button>
        </div>
    `;

        let reportsDiv = document.getElementById('reportsContent');
        if (!reportsDiv) {
            reportsDiv = document.createElement('div');
            reportsDiv.id = 'reportsContent';
            DOM_ELEMENTS.mainContent.appendChild(reportsDiv);
        }
        reportsDiv.innerHTML = html;
        reportsDiv.style.display = 'block';

        // Hide reconciliation div if visible
        const reconciliationDiv = document.getElementById('reconciliationContent');
        if (reconciliationDiv) {
            reconciliationDiv.style.display = 'none';
        }
    }

    // ==========================================
    // RECONCILIATION VIEW - ENHANCED
    // ==========================================
    async function showReconciliationView(loans) {
        // Hide loading indicator
        DOM_ELEMENTS.loadingIndicator.style.display = 'none';
        DOM_ELEMENTS.errorMessage.style.display = 'none';

        if (!loans) {
            loans = (await getAllLoans()).filter(l => l.status === 'active' || l.status === 'dispatched');
        } else {
            loans = loans.filter(l => l.status === 'active' || l.status === 'dispatched');
        }

        DOM_ELEMENTS.sectionTitle.textContent = '💰 Payment Reconciliation Dashboard';
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
                riskLevel: overduePayments.length > 3 ? 'High' : overduePayments.length > 1 ? 'Medium' :
                    'Low'
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
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 25px;">
<div class="balance-highlight" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none;">
<h4 style="margin: 0; color: white; font-size: 0.9rem;">Total Active Loans</h4>
<p style="font-size: 2rem; font-weight: bold; margin: 10px 0;">${loans.length}</p>
<p style="margin: 0; font-size: 0.85rem;">On Track: ${loansOnTrack} | Issues: ${loansWithOverdue}</p>
</div>

<div class="balance-highlight" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; border: none;">
<h4 style="margin: 0; color: white; font-size: 0.9rem;">Expected Payments</h4>
<p style="font-size: 2rem; font-weight: bold; margin: 10px 0;">KES ${formatCurrency(totalExpectedPayments)}</p>
<p style="margin: 0; font-size: 0.85rem;">As of today</p>
</div>

<div class="balance-highlight" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; border: none;">
<h4 style="margin: 0; color: white; font-size: 0.9rem;">Actual Payments</h4>
<p style="font-size: 2rem; font-weight: bold; margin: 10px 0;">KES ${formatCurrency(totalActualPayments)}</p>
<p style="margin: 0; font-size: 0.85rem;">Total received</p>
</div>

<div class="balance-highlight" style="background: linear-gradient(135deg, ${totalVariance >= 0 ? '#43e97b 0%, #38f9d7' : '#fa709a 0%, #fee140'} 100%); color: white; border: none;">
<h4 style="margin: 0; color: white; font-size: 0.9rem;">Payment Variance</h4>
<p style="font-size: 2rem; font-weight: bold; margin: 10px 0;">${totalVariance >= 0 ? '+' : ''}KES ${formatCurrency(Math.abs(totalVariance))}</p>
<p style="margin: 0; font-size: 0.85rem;">${totalVariance >= 0 ? 'Surplus' : 'Shortfall'}</p>
</div>

<div class="balance-highlight" style="background: linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%); color: white; border: none;">
<h4 style="margin: 0; color: white; font-size: 0.9rem;">Total Overdue</h4>
<p style="font-size: 2rem; font-weight: bold; margin: 10px 0;">KES ${formatCurrency(totalOverdueAmount)}</p>
<p style="margin: 0; font-size: 0.85rem;">${loansWithOverdue} loans affected</p>
</div>

<div class="balance-highlight" style="background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%); color: #333; border: none;">
<h4 style="margin: 0; font-size: 0.9rem;">Collection Rate</h4>
<p style="font-size: 2rem; font-weight: bold; margin: 10px 0;">${totalExpectedPayments > 0 ? ((totalActualPayments / totalExpectedPayments) * 100).toFixed(1) : 0}%</p>
<p style="margin: 0; font-size: 0.85rem;">Payment efficiency</p>
</div>
</div>

<!-- Variance Analysis -->
<div class="balance-highlight" style="margin-bottom: 20px;">
<h3 style="margin-top:0; color:#2e7d32;">📊 Variance Analysis</h3>
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
<div>
<strong>Principal Variance:</strong>
<p style="font-size: 1.3rem; color: ${totalPrincipalVariance >= 0 ? '#2e7d32' : '#c62828'}; margin: 5px 0;">
${totalPrincipalVariance >= 0 ? '+' : ''}KES ${formatCurrency(Math.abs(totalPrincipalVariance))}
</p>
</div>
<div>
<strong>Interest Variance:</strong>
<p style="font-size: 1.3rem; color: ${totalInterestVariance >= 0 ? '#2e7d32' : '#c62828'}; margin: 5px 0;">
${totalInterestVariance >= 0 ? '+' : ''}KES ${formatCurrency(Math.abs(totalInterestVariance))}
</p>
</div>
<div>
<strong>Total Variance:</strong>
<p style="font-size: 1.3rem; color: ${totalVariance >= 0 ? '#2e7d32' : '#c62828'}; margin: 5px 0;">
${totalVariance >= 0 ? '+' : ''}KES ${formatCurrency(Math.abs(totalVariance))}
</p>
</div>
</div>
</div>

<!-- Export Options -->
<div class="export-options">
    <button id="exportCSVBtn" class="active" onclick="setExportFormat('csv')">CSV</button>
    <button id="exportExcelBtn" onclick="setExportFormat('excel')">Excel</button>
    <button onclick="exportReconciliationReport()" class="action-btn" style="background:#2196F3; margin-left: 10px;">
        Export Report
    </button>
</div>

<!-- Detailed Reconciliation Table -->
<div style="overflow-x: auto;">
<table style="width:100%; border-collapse:collapse; margin-top:20px; font-size: 0.9rem;">
<thead>
<tr style="background:#3a6b9a; color:white;">
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

        reconciliationData.forEach(data => {
            const riskColors = {
                'High': '#c62828',
                'Medium': '#f57c00',
                'Low': '#2e7d32'
            };
            const riskBgColors = {
                'High': '#ffebee',
                'Medium': '#fff3e0',
                'Low': '#e8f5e9'
            };

            html += `
<tr style="background: ${riskBgColors[data.riskLevel]};">
<td style="padding:10px;">
<span style="background: ${riskColors[data.riskLevel]}; color: white; padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 0.75rem;">
${data.riskLevel}
</span>
</td>
<td style="padding:10px; font-weight: bold;">${data.loan.full_name}</td>
<td style="padding:10px;">${data.loan.pf_number}</td>
<td style="padding:10px;">${data.loan.loan_type.toUpperCase()}</td>
<td style="padding:10px;">KES ${formatCurrency(data.loan.loan_amount)}</td>
<td style="padding:10px;">KES ${formatCurrency(data.expectedToDate)}</td>
<td style="padding:10px; font-weight: bold;">KES ${formatCurrency(data.actualToDate)}</td>
<td style="padding:10px; color: ${data.variance >= 0 ? '#2e7d32' : '#c62828'}; font-weight: bold;">
${data.variance >= 0 ? '+' : ''}KES ${formatCurrency(Math.abs(data.variance))}
</td>
<td style="padding:10px; font-weight: bold;">KES ${formatCurrency(data.balanceInfo.totalBalanceDue)}</td>
<td style="padding:10px; text-align: center;">
${data.overduePayments > 0 ? `<span style="background: #c62828; color: white; padding: 4px 8px; border-radius: 4px; font-weight: bold;">${data.overduePayments}</span>` : '-'}
</td>
<td style="padding:10px; color: ${data.overdueAmount > 0 ? '#c62828' : '#666'}; font-weight: bold;">
${data.overdueAmount > 0 ? 'KES ' + formatCurrency(data.overdueAmount) : '-'}
</td>
<td style="padding:10px; font-size: 0.85rem;">
${formatDate(data.balanceInfo.lastPaymentDate)}<br>
<small style="color: #666;">(${data.balanceInfo.monthsSinceLastPayment} months ago)</small>
</td>
<td style="padding:10px;">
<button class="action-btn" onclick="showDetailedReconciliation(${JSON.stringify(data.loan).replace(/"/g, '&quot;')})" style="font-size: 0.85rem; padding: 6px 12px;">
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
${totalVariance >= 0 ? '+' : ''}KES ${formatCurrency(Math.abs(totalVariance))}
</td>
<td colspan="2" style="padding:12px;"></td>
<td style="padding:12px; color: #c62828;">KES ${formatCurrency(totalOverdueAmount)}</td>
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
<p><strong>Loan Type:</strong> ${loan.loan_type ? loan.loan_type.charAt(0).toUpperCase() + loan.loan_type.slice(1) : 'N/A'}</p>
<p><strong>Original Amount:</strong> KES ${formatCurrency(loan.loan_amount)}</p>
<p><strong>Current Balance:</strong> KES ${formatCurrency(balanceInfo.totalBalanceDue)}</p>
<p><strong>Status:</strong> ${loan.status ? loan.status.toUpperCase() : 'PENDING'}</p>
</div>

<h3 style="color:#4a6b4a; margin-top:20px;">Payment Reconciliation Details</h3>
<div style="overflow-x: auto;">
<table style="width:100%; border-collapse:collapse; margin-top:15px;">
<thead>
<tr style="background:#3a6b9a; color:white;">
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
                const statusColors = {
                    'paid': '#d4edda',
                    'partial': '#fff3cd',
                    'pending': '#ffffff',
                    'overdue': '#f8d7da'
                };

                html += `
<tr style="background:${statusColors[payment.status] || '#fff'};">
<td style="padding:10px;">${payment.paymentNumber}</td>
<td style="padding:10px;">${formatDate(payment.dueDate)}</td>
<td style="padding:10px;">KES ${formatCurrency(payment.expectedAmount)}</td>
<td style="padding:10px;">${payment.actualAmount > 0 ? 'KES ' + formatCurrency(payment.actualAmount) : '-'}</td>
<td style="padding:10px;">KES ${formatCurrency(payment.expectedInterest)}</td>
<td style="padding:10px;">${payment.actualInterest > 0 ? 'KES ' + formatCurrency(payment.actualInterest) : '-'}</td>
<td style="padding:10px;">KES ${formatCurrency(payment.expectedPrincipal)}</td>
<td style="padding:10px;">KES ${formatCurrency(payment.expectedBalance)}</td>
<td style="padding:10px;"><strong>${payment.status.toUpperCase()}</strong></td>
<td style="padding:10px; color:${payment.variance > 0 ? 'green' : payment.variance < 0 ? 'red' : 'black'};">
${payment.variance !== 0 && payment.actualAmount > 0 ?
(payment.variance > 0 ? '+' : '') + 'KES ' + formatCurrency(Math.abs(payment.variance)) :
'-'}
</td>
</tr>
`;
            });
        } else {
            html += `
<tr>
<td colspan="10" style="text-align:center; padding:30px;">No payment schedule available</td>
</tr>
`;
        }

        html += `
</tbody>
</table>
</div>

<div style="margin-top:20px;">
<button onclick="exportDetailedReconciliation(${JSON.stringify(loan).replace(/"/g, '&quot;')})" class="action-btn" style="background:#2196F3;">
Export Details
</button>
</div>
`;

        document.getElementById('detailedReconciliationContent').innerHTML = html;
        document.getElementById('detailedReconciliationModal').style.display = 'block';
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
            `Loan Type,${loan.loan_type ? loan.loan_type.charAt(0).toUpperCase() + loan.loan_type.slice(1) : 'N/A'}\n`;
        csv += `Original Amount,KES ${formatCurrency(loan.loan_amount)}\n`;
        csv += `Status,${loan.status ? loan.status.toUpperCase() : 'PENDING'}\n\n`;

        // Add payment schedule
        csv += `Payment Schedule\n`;
        csv +=
            `Payment #,Due Date,Expected Amount,Actual Amount,Expected Interest,Actual Interest,Expected Principal,Balance,Status,Variance\n`;

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
            `detailed_reconciliation_${loan.full_name}_${loan.pf_number}_${new Date().toISOString().split('T')[0]}.csv`;
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
                `Loan Type: ${loan.loan_type ? loan.loan_type.charAt(0).toUpperCase() + loan.loan_type.slice(1) : 'N/A'}`,
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
            doc.text(`Current Principal Balance: KES ${formatCurrency(balanceInfo.currentPrincipal)}`, 20, 146);
            doc.text(
                `Accrued Interest (${balanceInfo.monthsSinceLastPayment} months): KES ${formatCurrency(balanceInfo.accruedInterest)}`,
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
                    doc.text(`KES ${formatCurrency(payment.interest_amount || 0)}`, 95, yPosition);
                    doc.text(`KES ${formatCurrency(payment.principal_amount || 0)}`, 120, yPosition);
                    doc.text(`KES ${formatCurrency(payment.balance_after || 0)}`, 145, yPosition);
                    doc.text(payment.payment_source, 170, yPosition);

                    yPosition += 7;
                });
            }

            // Save the PDF
            doc.save(
                `loan_details_${loan.full_name}_${loan.pf_number}_${new Date().toISOString().split('T')[0]}.pdf`
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
                ////console.error('Error fetching payments:', error);
                showNotification('Error fetching payment history', 'error');
                loan.payments = [];
            }
        }

        const balanceInfo = await recalculateLoanBalance(loan);

        let detailsHtml = `
<button onclick="exportLoanDetails()" class="action-btn" style="float:right;background:#2196F3;margin-bottom:15px;">
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
<td style="text-align:right; color:#2e7d32;">KES ${formatCurrency(balanceInfo.totalPaid)}</td>
</tr>
<tr>
<td><strong>Principal Paid:</strong></td>
<td style="text-align:right;">KES ${formatCurrency(balanceInfo.principalPaid)}</td>
</tr>
<tr>
<td><strong>Interest Paid:</strong></td>
<td style="text-align:right; color:#d84315;">KES ${formatCurrency(balanceInfo.interestPaid)}</td>
</tr>
<tr style="border-top: 1px solid #ccc;">
<td><strong>Current Principal Balance:</strong></td>
<td style="text-align:right;">KES ${formatCurrency(balanceInfo.currentPrincipal)}</td>
</tr>
<tr>
<td><strong>Accrued Interest (${balanceInfo.monthsSinceLastPayment} months):</strong></td>
<td style="text-align:right; color:#d84315;">KES ${formatCurrency(balanceInfo.accruedInterest)}</td>
</tr>
<tr style="border-top:2px solid #2e7d32; font-weight:bold; font-size:1.1rem;">
<td><strong>Total Balance Due:</strong></td>
<td style="text-align:right; color:#c62828;">KES ${formatCurrency(balanceInfo.totalBalanceDue)}</td>
</tr>
</table>
<p style="margin:10px 0 0 0; font-size:0.85rem; color:#666;">
Last Payment: ${formatDate(balanceInfo.lastPaymentDate)} (${balanceInfo.monthsSinceLastPayment} months ago)
</p>
</div>
<h3 style="color:#4a6b4a;">Loanee Information</h3>
<p><strong>Full Name:</strong> ${loan.full_name}</p>
<p><strong>ID Number:</strong> ${loan.id_number}</p>
<p><strong>PF Number:</strong> ${loan.pf_number}</p>
<p><strong>Email:</strong> ${loan.email || 'N/A'}</p>
<hr style="margin: 20px 0; border: none; border-top: 1px solid #e0e0e0;">
<h3 style="color:#4a6b4a;">Loan Details</h3>
<p><strong>Loan Type:</strong> ${loan.loan_type ? loan.loan_type.charAt(0).toUpperCase() + loan.loan_type.slice(1) : 'N/A'}</p>
<p><strong>Application Date:</strong> ${loan.application_date ? formatDateTime(loan.application_date) : 'N/A'}</p>
<p><strong>Status:</strong> <span style="font-weight:bold; color:#4a6b4a;">${loan.status ? loan.status.toUpperCase() : 'PENDING'}</span></p>
<p><strong>Payment Duration:</strong> ${loan.payment_duration || 'N/A'} years</p>
<p><strong>Payment Frequency:</strong> Monthly</p>
<p><strong>Basic Salary:</strong> KES ${loan.basic_salary ? formatCurrency(loan.basic_salary) : 'N/A'}</p>
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
<p><strong>Property Value:</strong> KES ${loan.property_value ? formatCurrency(loan.property_value) : 'N/A'}</p>
`;
        }
        if (loan.loan_type === 'car') {
            detailsHtml += `
<p><strong>Car Type:</strong> ${loan.car_type || 'N/A'}</p>
<p><strong>Car Model:</strong> ${loan.car_model || 'N/A'}</p>
<p><strong>Car Value:</strong> KES ${loan.car_value ? formatCurrency(loan.car_value) : 'N/A'}</p>
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
<p><strong>Grace Period End:</strong> ${formatDate(gracePeriodEndDate.toISOString())}</p>
<p><strong>First Payment Due:</strong> ${formatDate(new Date(gracePeriodEndDate.getFullYear(), gracePeriodEndDate.getMonth() + 1, gracePeriodEndDate.getDate()))}</p>
<p>The loanee has a one-month grace period after funds are dispatched before the first payment is due.</p>
</div>
`;
        }
        if (loan.payments && loan.payments.length > 0) {
            detailsHtml += `
<hr style="margin: 20px 0; border: none; border-top: 1px solid #e0e0e0;">
<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
<h3 style="color:#4a6b4a;">Payment History (${loan.payments.length} payments)</h3>
<button onclick="forceRefreshPaymentData(${JSON.stringify(loan).replace(/"/g, '&quot;')})" class="action-btn" style="background:#2196F3;">
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
<td style="padding:8px; border:1px solid #ccc;">${formatDate(p.payment_date)}</td>
<td style="padding:8px; border:1px solid #ccc; font-weight:bold;">KES ${formatCurrency(p.amount)}</td>
<td style="padding:8px; border:1px solid #ccc; color:#d84315;">KES ${formatCurrency(p.interest_amount || 0)}</td>
<td style="padding:8px; border:1px solid #ccc; color:#2e7d32;">KES ${formatCurrency(p.principal_amount || 0)}</td>
<td style="padding:8px; border:1px solid #ccc;">KES ${formatCurrency(p.balance_after || 0)}</td>
<td style="padding:8px; border:1px solid #ccc; font-size:0.8rem;">${p.payment_source}</td>
<td style="padding:8px; border:1px solid #ccc; font-size:0.75rem; color:#666;">${p.transaction_reference || '-'}</td>
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
<p><strong>Accountant Process Date:</strong> ${loan.accountant_processed_date ? formatDateTime(loan.accountant_processed_date) : 'N/A'}</p>
<p><strong>Feedback:</strong> ${loan.feedback || 'None'}</p>
`;

        document.getElementById('loanDetailsContent').innerHTML = detailsHtml;
        document.getElementById('detailsModal').style.display = 'block';
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
        document.getElementById('feedbackModal').style.display = 'block';
    }

    async function showAmortization(loan) {
        // Fetch payment data if not already loaded
        if (!loan.payments) {
            try {
                const payments = await getPaymentsForLoan(loan.application_id);
                loan.payments = payments;
            } catch (error) {
                ////console.error('Error fetching payments:', error);
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

        amortInfo.innerHTML = `
<div style="display:flex;justify-content:space-between;align-items:center;">
<h3 style="margin:0; color:#2e7d32;">Payment Schedule Summary</h3>
<button onclick="exportAmortization()" class="action-btn" style="background:#2196F3;">Export Schedule</button>
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

        if (!schedule.payment_schedule || schedule.payment_schedule.length === 0) {
            amortTableBody.innerHTML =
                '<tr><td colspan="9" style="text-align:center; padding:30px;">No payment schedule generated yet. Loan must be dispatched first.</td></tr>';
            document.getElementById('amortModal').style.display = 'block';
            return;
        }

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
<td style="color:#d84315; font-weight:bold;">${payment.actualInterest > 0 ? 'KES ' + formatCurrency(payment.actualInterest) : '-'}</td>
<td>KES ${formatCurrency(payment.expectedPrincipal)}</td>
<td>KES ${formatCurrency(payment.expectedBalance)}</td>
<td><strong>${payment.status.toUpperCase()}</strong>${payment.variance !== 0 && payment.actualAmount > 0 ?
`<br><small style="color:${payment.variance > 0 ? 'green' : 'red'};">${payment.variance > 0 ? '+' : ''}KES ${Math.round(payment.variance)}</small>` : ''}</td>
</tr>
`;
            amortTableBody.innerHTML += row;
        });
        document.getElementById('amortModal').style.display = 'block';
    }

    function showRecordPaymentModal(loan) {
        if (!loan || !loan.application_id) {
            showNotification('Error: Invalid loan data', 'error');
            ////console.error('Invalid loan object:', loan);
            return;
        }
        APP_STATE.currentPaymentLoan = loan;
        recalculateLoanBalance(loan).then(balanceInfo => {
            document.getElementById('paymentLoaneeInfo').innerHTML = `
<p style="margin:5px 0;"><strong>Loanee:</strong> ${loan.full_name}</p>
<p style="margin:5px 0;"><strong>PF Number:</strong> ${loan.pf_number}</p>
<p style="margin:5px 0;"><strong>Original Loan Amount:</strong> KES ${formatCurrency(loan.loan_amount)}</p>
<p style="margin:5px 0;"><strong>Current Principal:</strong> KES ${formatCurrency(balanceInfo.currentPrincipal)}</p>
<p style="margin:5px 0;"><strong>Accrued Interest:</strong> <span style="color:#d84315;">KES ${formatCurrency(balanceInfo.accruedInterest)}</span></p>
<p style="margin:5px 0;"><strong>Total Balance Due:</strong> <span style="color:#c62828; font-weight:bold; font-size:1.1em;">KES ${formatCurrency(balanceInfo.totalBalanceDue)}</span></p>
<p style="margin:5px 0; font-size:0.9em; color:#666;"><em>Last payment: ${balanceInfo.monthsSinceLastPayment} months ago</em></p>
`;
        });
        const today = new Date().toISOString().split('T')[0];
        document.getElementById('paymentDate').value = today;
        document.getElementById('principalAmount').value = '';
        document.getElementById('interestAmount').value = '';
        document.getElementById('totalPaymentAmount').value = '';
        document.getElementById('paymentSource').value = '';
        document.getElementById('transactionReference').value = '';
        document.getElementById('paymentNotes').value = '';
        document.getElementById('paymentCalculationPreview').style.display = 'none';
        document.getElementById('recordPaymentModal').style.display = 'block';
    }

    // ==========================================
    // PAYMENT FUNCTIONS
    // ==========================================
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

    async function submitPayment() {
        const principal = parseFloat(document.getElementById('principalAmount').value);
        const interest = parseFloat(document.getElementById('interestAmount').value);
        const totalAmount = principal + interest;
        const pDate = document.getElementById('paymentDate').value;
        const source = document.getElementById('paymentSource').value;
        const transactionRef = document.getElementById('transactionReference').value.trim();
        const notes = document.getElementById('paymentNotes').value.trim();

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
                await forceRefreshPaymentData(APP_STATE.currentPaymentLoan);
                if (balanceInfo.totalBalanceDue <= totalAmount) {
                    await updateLoanStatus(APP_STATE.currentPaymentLoan.application_id, 'closed',
                        'Loan fully paid off.');
                    showNotification(
                        `Payment recorded! Loan for ${APP_STATE.currentPaymentLoan.full_name} is now FULLY PAID and closed.`,
                        'success');
                } else if (APP_STATE.currentPaymentLoan.status === 'dispatched') {
                    await updateLoanStatus(APP_STATE.currentPaymentLoan.application_id, 'active',
                        'Payment received.');
                    showNotification('Payment recorded successfully!', 'success');
                } else {
                    showNotification('Payment recorded successfully!', 'success');
                }
                document.getElementById('recordPaymentModal').style.display = 'none';
                const currentFilter = document.querySelector('#sidebar button.active').dataset.filter;
                await renderLoans(currentFilter);
            } else {
                showNotification('Failed to record payment: ' + result.message, 'error');
            }
        } catch (error) {
            showNotification('Failed to record payment: ' + error.message, 'error');
        }
    }

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
            ////console.error('Error refreshing payment data:', error);
            showNotification('Error refreshing payment data', 'error');
            return null;
        }
    }

    async function forceRefreshPaymentData(loan) {
        try {
            if (loan.payments) {
                delete loan.payments;
            }
            const result = await apiCall(`payments.php?application_id=${loan.application_id}`);
            const payments = result.data || [];
            loan.payments = payments;

            // Update the display if details modal is open
            if (document.getElementById('detailsModal').style.display === 'block') {
                await showLoanDetails(loan);
            }

            // Refresh the loans table
            if (DOM_ELEMENTS.loansTable.style.display !== 'none') {
                await renderLoans(document.querySelector('#sidebar button.active').dataset.filter);
            }

            return {
                payments,
                balanceInfo: await recalculateLoanBalance(loan)
            };
        } catch (error) {
            ////console.error('Error refreshing payment data:', error);
            showNotification('Error refreshing payment data', 'error');
            return null;
        }
    }

    // ==========================================
    // LOAN ACTIONS
    // ==========================================
    async function updateLoanStatus(applicationId, status, feedback = '') {
        try {
            await apiCall('loans.php', 'PUT', {
                application_id: applicationId,
                status: status,
                feedback: feedback,
                csrf_token: APP_STATE.csrfToken
            });
        } catch (error) {
            ////console.error('Error updating loan status:', error);
            throw error;
        }
    }

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
                    await updateLoanStatus(loan.application_id, 'closed', loan.feedback ||
                        'Case closed by Cashier.');
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

    async function submitFeedback() {
        const feedback = document.getElementById('feedbackTextarea').value.trim();
        if (!feedback) {
            showNotification('Feedback/Reason is required.', 'error');
            return;
        }
        try {
            if (APP_STATE.currentActionType === 'reject') {
                await updateLoanStatus(APP_STATE.currentActionLoan.application_id, 'rejected',
                    feedback);
                showNotification(
                    `Loan for ${APP_STATE.currentActionLoan.full_name} permanently rejected.`,
                    'success');
            } else if (APP_STATE.currentActionType === 'return') {
                await updateLoanStatus(APP_STATE.currentActionLoan.application_id, 'returned',
                    feedback);
                showNotification(
                    `Loan for ${APP_STATE.currentActionLoan.full_name} returned to loanee for correction.`,
                    'success');
            }
            document.getElementById('feedbackModal').style.display = 'none';
            renderLoans(document.querySelector('#sidebar button.active').dataset.filter);
        } catch (error) {
            showNotification('Failed to update loan: ' + error.message, 'error');
        }
    }

    // ==========================================
    // PAYMENT SCHEDULE
    // ==========================================
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
        let paymentsPerYear = 12;
        const totalPayments = years * paymentsPerYear;
        const periodicRate = (rate / 100) / paymentsPerYear;
        const periodicPayment = principal * (periodicRate * Math.pow(1 + periodicRate, totalPayments)) /
            (Math.pow(1 + periodicRate, totalPayments) - 1);
        let balance = principal;

        for (let i = 0; i < totalPayments; i++) {
            const dueDate = new Date(startDate);
            dueDate.setMonth(startDate.getMonth() + i);
            const interestPayment = balance * periodicRate;
            const principalPayment = periodicPayment - interestPayment;
            balance -= principalPayment;
            schedule.push({
                paymentNumber: i + 1,
                dueDate: dueDate.toISOString(),
                expectedAmount: periodicPayment,
                expectedPrincipal: principalPayment,
                expectedInterest: interestPayment,
                expectedBalance: Math.max(0, balance),
                status: 'pending',
                actualAmount: 0,
                actualInterest: 0,
                actualDate: null,
                variance: 0
            });
        }

        return {
            schedule,
            gracePeriodEndDate: gracePeriodEndDate.toISOString(),
            startDate: startDate.toISOString()
        };
    }

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
    // Helper function to convert data to CSV format (Excel compatible)
    function convertToCSV(data, headers) {
        let csv = headers.join(',') + '\n';
        data.forEach(row => {
            const values = row.map((value, index) => {
                const stringValue = String(value);

                // For Application ID and Account Number columns, add tab character to preserve formatting
                if (index === 0 || (headers[index] && (headers[index].includes(
                            'Application ID') ||
                        headers[index].includes('Account Number')))) {
                    // Use tab character prefix to force Excel to treat as text
                    return `"${stringValue}\t"`;
                }

                // Escape quotes and wrap in quotes if contains comma, quotes, or newlines
                if (stringValue.includes(',') || stringValue.includes('"') || stringValue
                    .includes(
                        '\n')) {
                    return `"${stringValue.replace(/"/g, '""')}"`;
                }

                return stringValue;
            });
            csv += values.join(',') + '\n';
        });
        return csv;
    }

    // Helper function to download CSV file
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
            'success');
    }

    // 1. EXPORT OVERALL SUMMARY
    async function exportOverallSummary() {
        if (!hasPermission('canExportData')) {
            showNotification('You do not have permission to export data.', 'error');
            return;
        }

        try {
            const loans = await getAllLoans();
            const activeLoans = loans.filter(l => l.status === 'active' || l.status === 'dispatched');
            const closedLoans = loans.filter(l => l.status === 'closed');

            // Fetch payment data
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

    // 2. EXPORT MORTGAGE LOANS
    async function exportMortgageLoans() {
        if (!hasPermission('canExportData')) {
            showNotification('You do not have permission to export data.', 'error');
            return;
        }

        try {
            const loans = await getAllLoans();
            const mortgageLoans = loans.filter(l => l.loan_type === 'mortgage');

            // Fetch payment data
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

    // 3. EXPORT CAR LOANS
    async function exportCarLoans() {
        if (!hasPermission('canExportData')) {
            showNotification('You do not have permission to export data.', 'error');
            return;
        }

        try {
            const loans = await getAllLoans();
            const carLoans = loans.filter(l => l.loan_type === 'car');

            // Fetch payment data
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

    // 4. EXPORT PAYMENT SUMMARY
    async function exportPaymentSummary() {
        if (!hasPermission('canExportData')) {
            showNotification('You do not have permission to export data.', 'error');
            return;
        }

        try {
            const loans = await getAllLoans();
            const activeLoans = loans.filter(l => l.status === 'active' || l.status === 'dispatched');
            const closedLoans = loans.filter(l => l.status === 'closed');

            // Fetch payment data
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
                const paymentStatus = loan.status === 'closed' ? 'FULLY PAID' :
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
    async function exportReconciliationReport() {
        if (!hasPermission('canExportData')) {
            showNotification('You do not have permission to export data.', 'error');
            return;
        }

        try {
            // Get all active and dispatched loans
            const loans = (await getAllLoans()).filter(l => l.status === 'active' || l.status === 'dispatched');

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

                const riskLevel = overduePayments.length > 3 ? 'High' : overduePayments.length > 1 ? 'Medium' :
                    'Low';

                reconciliationData.push({
                    loanee: loan.full_name,
                    pfNumber: loan.pf_number,
                    loanType: loan.loan_type ? loan.loan_type.charAt(0).toUpperCase() + loan.loan_type
                        .slice(1) : 'N/A',
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

                // Save the file
                XLSX.writeFile(wb, `reconciliation_report_${new Date().toISOString().split('T')[0]}.xlsx`);
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
                    'Loanee,PF Number,Loan Type,Original Amount (KES),Expected Paid (KES),Actual Paid (KES),Variance (KES),Principal Variance (KES),Interest Variance (KES),Current Balance (KES),Overdue Payments,Overdue Amount (KES),Last Payment Date,Months Since Last Payment,Risk Level\n';

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

    // Initialize export buttons
    function initializeExportButtons() {
        // Set CSV as default active
        document.getElementById('exportCSVBtn').classList.add('active');
        document.getElementById('exportExcelBtn').classList.remove('active');
    }

    // Export format selector functions
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

    // Add this function after the existing export functions
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
                    loan.loan_type ? loan.loan_type.charAt(0).toUpperCase() + loan.loan_type.slice(1) :
                    'N/A',
                    formatCurrency(loan.loan_amount),
                    formatCurrency(balanceInfo.totalPaid),
                    formatCurrency(balanceInfo.principalPaid),
                    formatCurrency(balanceInfo.interestPaid),
                    loan.status !== 'closed' ? formatCurrency(balanceInfo.totalBalanceDue) : '0',
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

    // Date range filter functions
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
    function logoutCashier() {
        // Clear the session storage
        sessionStorage.removeItem('loginTime');

        // Show notification
        showNotification('Logging out...', 'success', 2000);

        // Call logout API (but don't wait for response in case of network issues)
        apiCall('auth.php', 'POST', {
            action: 'logout',
            csrf_token: APP_STATE.csrfToken
        }).catch(error => {
            ////console.error('Logout error:', error);
        }).finally(() => {
            // Clear app state
            APP_STATE.currentUser = null;
            APP_STATE.csrfToken = null;

            // Redirect after a brief delay
            setTimeout(() => {
                window.location.href = 'index.php?logout=success';
            }, 500);
        });
    }

    // ==========================================
    // SIDEBAR TOGGLE FUNCTION
    // ==========================================
    function toggleSidebar() {
        if (APP_STATE.sidebarOpen && !APP_STATE.sidebarCollapsed) {
            APP_STATE.sidebarCollapsed = true;
            DOM_ELEMENTS.sidebar.classList.add('collapsed');
            DOM_ELEMENTS.portalSection.classList.add('sidebar-collapsed');
            DOM_ELEMENTS.portalSection.classList.remove('sidebar-open');
        } else if (APP_STATE.sidebarOpen && APP_STATE.sidebarCollapsed) {
            APP_STATE.sidebarCollapsed = false;
            APP_STATE.sidebarOpen = false;
            DOM_ELEMENTS.sidebar.classList.remove('open', 'collapsed');
            DOM_ELEMENTS.portalSection.classList.remove('sidebar-open', 'sidebar-collapsed');
        } else {
            APP_STATE.sidebarOpen = true;
            APP_STATE.sidebarCollapsed = false;
            DOM_ELEMENTS.sidebar.classList.add('open');
            DOM_ELEMENTS.portalSection.classList.add('sidebar-open');
            DOM_ELEMENTS.sidebar.classList.remove('collapsed');
            DOM_ELEMENTS.portalSection.classList.remove('sidebar-collapsed');
        }
    }

    // ==========================================
    // INITIALIZATION
    // ==========================================
    function initializeSidebarButtons() {
        const sidebarButtons = DOM_ELEMENTS.sidebar.querySelectorAll('button:not(#logoutBtn)');
        sidebarButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                sidebarButtons.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                renderLoans(btn.dataset.filter);
                if (window.innerWidth <= 768) {
                    toggleSidebar();
                }
            });
        });

        // Add logout button event listener
        const logoutButton = document.getElementById('logoutBtn');
        if (logoutButton) {
            logoutButton.addEventListener('click', logoutCashier);
        }
    }

    async function initializeApp() {
        if (APP_STATE.loggedInFromMain) {
            APP_STATE.currentUser = APP_STATE.userData;
            try {
                const result = await apiCall('auth.php');
                if (result.success && result.data && result.data.csrf_token) {
                    APP_STATE.csrfToken = result.data.csrf_token;
                    ////console.log('CSRF token obtained successfully');
                } else {
                    ////console.error('Failed to get CSRF token from response:', result);
                }
            } catch (error) {
                ////console.error('Error getting CSRF token:', error);
                const csrfInput = document.getElementById('csrf-token-data');
                if (csrfInput) {
                    APP_STATE.csrfToken = csrfInput.dataset.csrfToken;
                    ////console.log('Using fallback CSRF token from DOM');
                }
            }

            DOM_ELEMENTS.portalSection.style.display = 'block';
            DOM_ELEMENTS.sidebar.style.display = 'flex';

            if (window.innerWidth > 768) {
                APP_STATE.sidebarOpen = true;
                APP_STATE.sidebarCollapsed = false;
                DOM_ELEMENTS.sidebar.classList.add('open');
                DOM_ELEMENTS.portalSection.classList.add('sidebar-open');
            } else {
                APP_STATE.sidebarOpen = false;
                APP_STATE.sidebarCollapsed = false;
                DOM_ELEMENTS.sidebar.classList.remove('open', 'collapsed');
                DOM_ELEMENTS.portalSection.classList.remove('sidebar-open', 'sidebar-collapsed');
            }

            document.addEventListener('visibilitychange', function() {
                if (document.visibilityState === 'visible') {
                    ////console.log('Page visible - checking session validity');
                    if (APP_STATE.loggedInFromMain) {
                        apiCall('auth.php').then(result => {
                            if (result.success && result.data && result.data
                                .csrf_token) {
                                APP_STATE.csrfToken = result.data.csrf_token;
                                ////console.log('CSRF token refreshed');
                            }
                        }).catch(error => {
                            ////console.error('Session check failed:', error);
                            showNotification(
                                'Session may have expired. Please save your work.',
                                'warning');
                        });
                    }
                }
            });

            initializeSidebarButtons();
            renderLoans('all');
            showNotification('Welcome back, ' + APP_STATE.userData.fullName + '! You are logged in.',
                'success');
            applyPermissions();

            // SESSION TIMEOUT MANAGEMENT - FIXED VERSION
            // SESSION TIMEOUT MANAGEMENT
            const sessionDuration = 4 * 60 * 60 * 1000; // 4 hours in milliseconds
            let loginTime = sessionStorage.getItem('loginTime');

            if (!loginTime) {
                // First login, store the current time
                loginTime = Date.now().toString();
                sessionStorage.setItem('loginTime', loginTime);
            }

            // Check if session has expired
            const currentTime = Date.now();
            const timeElapsed = currentTime - parseInt(loginTime);

            if (timeElapsed >= sessionDuration) {
                // Session has expired
                showNotification("Your session has expired. Please log in again.", 'warning', 5000);
                sessionStorage.removeItem('loginTime');
                setTimeout(() => {
                    logoutCashier();
                }, 2000);
            } else {
                // Session is still valid
                const remainingTime = sessionDuration - timeElapsed;
                const remainingMinutes = Math.floor(remainingTime / 60000);

                // Set timeout for remaining time
                setTimeout(() => {
                    showNotification("Your session has expired. Please log in again.", 'warning', 5000);
                    sessionStorage.removeItem('loginTime');
                    setTimeout(() => {
                        logoutCashier();
                    }, 2000);
                }, remainingTime);

                // Optional: Warn user 5 minutes before expiry
                const warningTime = remainingTime - (5 * 60 * 1000);
                if (warningTime > 0) {
                    setTimeout(() => {
                        showNotification("Your session will expire in 5 minutes. Please save your work.",
                            'warning', 8000);
                    }, warningTime);
                }
            }
        }
    }

    // ==========================================
    // EVENT LISTENERS
    // ==========================================
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

    document.getElementById('principalAmount').addEventListener('input', updatePaymentPreview);
    document.getElementById('interestAmount').addEventListener('input', updatePaymentPreview);
    document.getElementById('submitPayment').onclick = submitPayment;
    document.getElementById('submitFeedback').onclick = submitFeedback;

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
        if (window.innerWidth > 768) {
            if (!APP_STATE.sidebarOpen) {
                APP_STATE.sidebarOpen = true;
                APP_STATE.sidebarCollapsed = false;
                DOM_ELEMENTS.sidebar.classList.add('open');
                DOM_ELEMENTS.portalSection.classList.add('sidebar-open');
                DOM_ELEMENTS.sidebar.classList.remove('collapsed');
                DOM_ELEMENTS.portalSection.classList.remove('sidebar-collapsed');
            }
        } else {
            if (APP_STATE.sidebarOpen) {
                APP_STATE.sidebarOpen = false;
                APP_STATE.sidebarCollapsed = false;
                DOM_ELEMENTS.sidebar.classList.remove('open', 'collapsed');
                DOM_ELEMENTS.portalSection.classList.remove('sidebar-open', 'sidebar-collapsed');
            }
        }
    });

    window.onclick = function(event) {
        if (event.target.classList.contains('modal')) {
            event.target.style.display = 'none';
        }
    }

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

    // Initialize notification system
    function initializeNotificationSystem() {
        // Start polling for notifications
        pollNotifications();
        notificationPollTimer = setInterval(pollNotifications, NOTIFICATION_CONFIG.pollInterval);

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

    // Poll for new notifications
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

    // Update notification badge
    function updateNotificationBadge(count) {
        const badge = document.getElementById('notificationBadge');
        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : count;
            badge.style.display = 'block';
        } else {
            badge.style.display = 'none';
        }
    }

    // Toggle notification dropdown
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

    // Load and display notifications
    async function loadNotifications() {
        const notificationList = document.getElementById('notificationList');
        notificationList.innerHTML = '<div style="text-align: center; padding: 20px;">Loading...</div>';

        try {
            const response = await apiCall(
                `cashier_notifications.php?limit=${NOTIFICATION_CONFIG.maxDisplayed}`);

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

    // Handle notification click
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

    // Mark all notifications as read
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

    // Delete notification
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

    // View all notifications (opens modal)
    function viewAllNotifications() {
        // You can implement a full notifications modal here
        showNotification('Feature coming soon', 'info');
    }

    // Utility function: Get time ago string
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

    // Utility function: Escape HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Optional: Play notification sound
    function playNotificationSound() {
        // You can use Web Audio API or a simple audio element
        // For now, we'll use the browser's built-in notification sound
        if ('Notification' in window && Notification.permission === 'granted') {
            // Browser notification (optional)
            // new Notification('New notification', { body: 'You have new notifications' });
        }
    }

    // Place this in your admin.php, cashier.php, and accountant.php JavaScript

    // FIXED: Apply for loan function with proper error handling
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

    // FIXED: Return to staff portal function
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


    //session validity checker
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

    // Initialize export buttons after the DOM is updated
    setTimeout(() => {
        // Set CSV as default active
        const csvBtn = document.getElementById('exportCSVBtn');
        const excelBtn = document.getElementById('exportExcelBtn');

        if (csvBtn && excelBtn) {
            csvBtn.classList.add('active');
            excelBtn.classList.remove('active');
        }
    }, 100);

    // ==========================================
    // START THE APPLICATION
    // ==========================================
    document.addEventListener('DOMContentLoaded', initializeApp);
    window.addEventListener('load', () => {
        ////console.log('Page fully loaded - Cashier Portal initialized');
    });

    function showLoadingIndicator() {
        const loadingIndicator = document.getElementById('loadingIndicator');
        if (loadingIndicator) {
            loadingIndicator.style.display = 'block';
        }
    }

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
    /*console.log('Viewing as Loanee:',
    <?php echo isset($_SESSION['viewing_as_loanee']) ? 'true' : 'false'; ?>);*/
    /*console.log('Original Portal:',
    <?php echo isset($_SESSION['original_portal']) ? '"' . $_SESSION['original_portal'] . '"' : 'null'; ?>
    );*/
    </script>
</body>

</html>