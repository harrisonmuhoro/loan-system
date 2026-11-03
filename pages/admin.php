<?php
// Start output buffering early to prevent headers already sent errors
ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

// === PREVENT HTML OUTPUT FOR API CALLS ===
$isApiRequest = (
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
    strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') === 0 ||
    str_contains($_SERVER['REQUEST_URI'] ?? '', 'restore.php') ||
    str_contains($_SERVER['REQUEST_URI'] ?? '', 'backup')
);

if ($isApiRequest) {
    if (ob_get_level()) {
        ob_clean();
        while (ob_get_level() > 0) ob_end_clean();
    }
} else {
    ob_start();
}

// Define environment
if (!defined('ENVIRONMENT')) {
    define('ENVIRONMENT', 'production');
}

// Configure error display
if (ENVIRONMENT === 'production') {
    error_reporting(0);
    ini_set('display_errors', 0);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

// ===== SESSION CONFIGURATION =====
ini_set('session.gc_maxlifetime', 36000);
ini_set('session.cookie_lifetime', 36000);
ini_set('session.gc_probability', 1);
ini_set('session.gc_divisor', 100);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');

// Determine the appropriate cookie domain for sessions
$sessionDomain = '';

// For production domains (not localhost or IP), use the current host
$host = $_SERVER['HTTP_HOST'] ?? '';
if (!in_array($host, ['localhost', '127.0.0.1', '[::1]'])) {
    if (($colonPos = strpos($host, ':')) !== false) {
        $host = substr($host, 0, $colonPos);
    }
    $sessionDomain = $host;  
}

session_set_cookie_params([
    'lifetime' => 36000,
    'path' => '/',
    'domain' => $sessionDomain,
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax'
]);

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Add after session_start()
error_log("Session ID: " . session_id());
error_log("Session data: " . print_r($_SESSION, true));
error_log("Cookie domain: " . $sessionDomain);

// Regenerate session ID periodically for security
if (!isset($_SESSION['last_regeneration'])) {
    $_SESSION['last_regeneration'] = time();
} elseif (time() - $_SESSION['last_regeneration'] > 1800) { // every 30 minutes
    session_regenerate_id(true);
    $_SESSION['last_regeneration'] = time();
}

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$loggedInAsAdmin = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
if ($loggedInAsAdmin) {
    $_SESSION['last_activity'] = time();
} else {
    error_log('ADMIN ACCESS DENIED - Session dump: ' . print_r($_SESSION, true));
    // Optional: Comment out destruction if you don't want forced logout
    // session_unset();
    // session_destroy();
    header("Location: /loan-system/index.php?error=access_denied");
    exit();
}
   
   /* Final check - if not recognized as admin, deny access
   if (!$loggedInAsAdmin) {
       error_log('ADMIN ACCESS DENIED - Session dump: ' . print_r($_SESSION, true));
       session_unset();
       session_destroy();
       header("Location: /loan-system/index.php?error=access_denied");
       exit();
   }*/


// Now safely extract user data
$user_id        = $_SESSION['user_id'] ?? null;
$user_pf        = $_SESSION['pf_number'] ?? $_SESSION['pf'] ?? '';
$user_name      = $_SESSION['full_name'] ?? $_SESSION['name'] ?? 'admin';
$user_email     = $_SESSION['email'] ?? '';
$user_id_number = $_SESSION['id_number'] ?? '';
$user_role      = 'admin'; // Force for consistency

// ===== HANDLE RETURN FROM LOANEE PORTAL =====
if (isset($_GET['returned_from_loanee']) && $_GET['returned_from_loanee'] == 1) {
    unset($_SESSION['switching_to_loanee']);
    unset($_SESSION['can_apply_for_loan']);
    unset($_SESSION['viewing_as_loanee']);
    unset($_SESSION['from_staff_portal']);
    unset($_SESSION['original_portal']);
    unset($_SESSION['original_role']);

    // Redirect to clean URL
    $currentFile = basename($_SERVER['PHP_SELF']);
    header("Location: $currentFile");
    exit();
}

// Include configuration and database AFTER authentication
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
} catch (Exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    http_response_code(503);
    die("Database connection failed. Please try again later.");
}

// Conditional error logging function
if (!function_exists('conditional_error_log')) {
    function conditional_error_log($message) {
        if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
            error_log($message);
        }
        if (defined('ENVIRONMENT') && ENVIRONMENT === 'production') {
            $logDir = dirname(__DIR__) . '/logs';
            if (!file_exists($logDir)) {
                mkdir($logDir, 0755, true);
            }
            $logFile = $logDir . '/production.log';
            file_put_contents(
                $logFile,
                date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL,
                FILE_APPEND
            );
        }
    }
}

// Log successful authentication
conditional_error_log('========== ADMIN.PHP SESSION STATE ==========');
conditional_error_log('Session ID: ' . session_id());
conditional_error_log('User ID: ' . ($user_id ?? 'not set'));
conditional_error_log('User Role: ' . ($user_role ?? 'not set'));
conditional_error_log('Authenticated: ' . (isset($_SESSION['authenticated']) && $_SESSION['authenticated'] ? 'true' : 'false'));
conditional_error_log('============================================');
conditional_error_log('Admin authenticated successfully');
conditional_error_log('User ID: ' . $user_id);
conditional_error_log('User Name: ' . $user_name);
conditional_error_log('User Role: ' . $user_role);

// Log return from loanee portal activity (now that DB is available)
if (isset($_GET['returned_from_loanee']) && $_GET['returned_from_loanee'] == 1) {
    try {
        $stmt = $conn->prepare("INSERT INTO activity_log (admin_id, action, details, timestamp) 
                                VALUES (:admin_id, :action, :details, NOW())");
        $stmt->bindParam(':admin_id', $user_id);
        $action = 'return_from_loanee';
        $stmt->bindParam(':action', $action);
        $details = 'User returned from loanee portal to admin portal';
        $stmt->bindParam(':details', $details);
        $stmt->execute();
    } catch (PDOException $e) {
        error_log("Error logging return activity: " . $e->getMessage());
    }
}

// AJAX REQUEST HANDLER
if (
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
) {
    // Clean any existing output buffers
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // Start a new output buffer
    ob_start();
}

// Handle AJAX requests
if (isset($_GET['action']) && $_GET['action'] === 'get_user') {
    header('Content-Type: application/json');
    
    $user_id = $_GET['user_id'] ?? 0;
    
    if ($user_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
        exit;
    }
    
    try {
        $query = "SELECT * FROM users WHERE user_id = :user_id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            echo json_encode(['success' => true, 'user' => $user]);
        } else {
            echo json_encode(['success' => false, 'message' => 'User not found']);
        }
    } catch (PDOException $e) {
        conditional_error_log("Error fetching user: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
    exit;
}

// ===== HANDLE LOAN UPDATE (AJAX) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_loan') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit();
    }

    // Ensure user is admin
    if ($_SESSION['user_role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }

    // Get and validate loan ID
    $loanId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$loanId) {
        echo json_encode(['success' => false, 'message' => 'Invalid or missing loan ID']);
        exit();
    }

    // Define allowed fields + PDO types + input filters
    $allowedFields = [
        // Basic info
        'full_name'             => ['type' => PDO::PARAM_STR,   'filter' => FILTER_SANITIZE_FULL_SPECIAL_CHARS],
        'email'                 => ['type' => PDO::PARAM_STR,   'filter' => FILTER_VALIDATE_EMAIL],
        'pf_number'             => ['type' => PDO::PARAM_STR,   'filter' => FILTER_SANITIZE_STRING],
        'id_number'             => ['type' => PDO::PARAM_STR,   'filter' => FILTER_SANITIZE_STRING],

        // Loan details
        'loan_type'             => ['type' => PDO::PARAM_STR,   'filter' => FILTER_SANITIZE_STRING],
        'loan_amount'           => ['type' => PDO::PARAM_STR,   'filter' => FILTER_VALIDATE_FLOAT],
        'payment_duration'      => ['type' => PDO::PARAM_INT,   'filter' => FILTER_VALIDATE_INT],
        'basic_salary'          => ['type' => PDO::PARAM_STR,   'filter' => FILTER_VALIDATE_FLOAT],

        // Employment & contract
        'employment_type'       => ['type' => PDO::PARAM_STR,   'filter' => FILTER_SANITIZE_STRING],
        'contract_period'       => ['type' => PDO::PARAM_INT,   'filter' => FILTER_VALIDATE_INT],
        'contract_start_date'   => ['type' => PDO::PARAM_STR,   'filter' => FILTER_SANITIZE_STRING],
        'contract_end_date'     => ['type' => PDO::PARAM_STR,   'filter' => FILTER_SANITIZE_STRING],

        // Mortgage fields
        'property_location'     => ['type' => PDO::PARAM_STR,   'filter' => FILTER_SANITIZE_STRING],
        'property_value'        => ['type' => PDO::PARAM_STR,   'filter' => FILTER_VALIDATE_FLOAT],

        // Car loan fields
        'car_type'              => ['type' => PDO::PARAM_STR,   'filter' => FILTER_SANITIZE_STRING],
        'car_value'             => ['type' => PDO::PARAM_STR,   'filter' => FILTER_VALIDATE_FLOAT],

        // Banking
        'bank_name'             => ['type' => PDO::PARAM_STR,   'filter' => FILTER_SANITIZE_STRING],
        'bank_branch'           => ['type' => PDO::PARAM_STR,   'filter' => FILTER_SANITIZE_STRING],
        'bank_account_number'   => ['type' => PDO::PARAM_STR,   'filter' => FILTER_SANITIZE_STRING],

        // Notes / feedback
        'feedback'              => ['type' => PDO::PARAM_STR,   'filter' => FILTER_SANITIZE_FULL_SPECIAL_CHARS],
    ];

    // Build dynamic update parts
    $updates = [];
    $params = [];

    foreach ($allowedFields as $field => $config) {
        if (!isset($_POST[$field])) {
            continue;
        }

        $rawValue = $_POST[$field];
        $value = filter_var($rawValue, $config['filter']);

        // Skip if filtering failed or value is invalid/empty in a bad way
        if ($value === false || $value === null) {
            continue;
        }

        // ──────────────────────────────────────────────
        // Special handling for dates
        // ──────────────────────────────────────────────
        if (in_array($field, ['contract_start_date', 'contract_end_date'])) {
            // Accept empty or placeholder → treat as NULL
            if (empty($rawValue) || $rawValue === 'dd/mm/yyyy' || $rawValue === 'dd/mm/yyy') {
                $value = null;
            } else {
                // Try to normalize common formats to YYYY-MM-DD
                try {
                    $date = new DateTime($rawValue);
                    $value = $date->format('Y-m-d');
                } catch (Exception $e) {
                    // Invalid date → skip or set null
                    $value = null;
                }
            }
        }

        // ──────────────────────────────────────────────
        // Special handling for enums (optional strict check)
        // ──────────────────────────────────────────────
        if ($field === 'loan_type' && !in_array($value, ['mortgage', 'car'])) {
            continue; // or set default
        }
        if ($field === 'employment_type' && !in_array($value, ['permanent', 'contracted'])) {
            continue;
        }
        if ($field === 'bank_name' && $value !== 'Family Bank') {
            continue; // strict enum enforcement
        }

        // Add to update if we have a valid value (including NULL for dates)
        $updates[] = "$field = :$field";
        $params[":$field"] = $value;
    }

    // Nothing to update → early exit
    if (empty($updates)) {
        echo json_encode([
            'success' => false,
            'message' => 'No valid fields were provided for update'
        ]);
        exit();
    }

    try {
        // Build and prepare query
        $query = "UPDATE loan_applications 
                  SET " . implode(', ', $updates) . ", 
                      updated_at = NOW() 
                  WHERE id = :id";

        $stmt = $conn->prepare($query);

        // Bind all parameters with correct types
        foreach ($params as $key => $value) {
            $fieldName = ltrim($key, ':');
            $type = $allowedFields[$fieldName]['type'] ?? PDO::PARAM_STR;
            $stmt->bindValue($key, $value, $type);
        }

        // Always bind the loan ID
        $stmt->bindValue(':id', $loanId, PDO::PARAM_INT);

        $success = $stmt->execute();

        if ($success) {
            // Optional: log the update
            logActivity($conn, $_SESSION['user_id'], 'loan_update', 
                "Updated loan ID $loanId by admin");

            echo json_encode([
                'success' => true,
                'message' => 'Loan updated successfully'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to update loan (no rows affected)'
            ]);
        }
    } catch (PDOException $e) {
        error_log("Loan update failed: " . $e->getMessage() . " | Query: " . $query);
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    }

    exit();
}

// Loan management functions - UPDATED TO USE UNIFIED VIEW
function getAllLoans()
{
    global $conn;
    
    try {
        $query = "SELECT la.*, 
                    COALESCE((SELECT SUM(amount) FROM payments WHERE application_id = la.application_id), 0) as total_paid,
                    COALESCE((SELECT SUM(principal_amount) FROM payments WHERE application_id = la.application_id), 0) as total_principal_paid,
                    COALESCE((SELECT SUM(interest_amount) FROM payments WHERE application_id = la.application_id), 0) as total_interest_paid,
                    COALESCE((SELECT COUNT(*) FROM payments WHERE application_id = la.application_id), 0) as payment_count
                  FROM v_unified_loans la 
                  ORDER BY la.application_date DESC";

        $stmt = $conn->prepare($query);
        $stmt->execute();
        
        $loans = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Map fields consistently
            $loan = [
                'id' => $row['id'],
                'application_id' => $row['application_id'],
                'full_name' => $row['full_name'],
                'id_number' => $row['id_number'],
                'pf_number' => $row['pf_number'],
                'loan_type' => $row['loan_type'],
                'loan_amount' => floatval($row['loan_amount']),
                'basic_salary' => floatval($row['basic_salary']),
                'payment_duration' => $row['payment_duration'],
                'status' => $row['status'],
                'feedback' => $row['feedback'],
                'application_date' => $row['application_date'],
                'dispatch_date' => $row['dispatch_date'],
                'accountant_processed_date' => $row['accountant_processed_date'],
                'email' => $row['email'] ?? null,
                'employment_type' => $row['employment_type'] ?? null,
                'bank_name' => $row['bank_name'] ?? null,
                'bank_branch' => $row['bank_branch'] ?? null,
                'bank_account_number' => $row['bank_account_number'] ?? null,
                'property_location' => $row['property_location'] ?? null,
                'property_value' => $row['property_value'] ? floatval($row['property_value']) : null,
                'car_type' => $row['car_type'] ?? null,
                'car_value' => $row['car_value'] ? floatval($row['car_value']) : null,
                'contract_period' => $row['contract_period'] ?? null,
                'contract_start_date' => $row['contract_start_date'] ?? null,
                'contract_end_date' => $row['contract_end_date'] ?? null,
                'payment_frequency' => 'monthly',
                'total_paid' => floatval($row['total_paid']),
                'total_principal_paid' => floatval($row['total_principal_paid']),
                'total_interest_paid' => floatval($row['total_interest_paid']),
                'payment_count' => intval($row['payment_count'])
            ];

            // Fetch payments for this loan
            $paymentStmt = $conn->prepare("SELECT * FROM payments WHERE application_id = ? ORDER BY payment_date DESC");
            $paymentStmt->execute([$row['application_id']]);
            $payments = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);
            $loan['payments'] = array_map(function($p) {
                return [
                    'payment_id' => $p['payment_id'],
                    'payment_date' => $p['payment_date'],
                    'amount' => floatval($p['amount']),
                    'principal_amount' => floatval($p['principal_amount']),
                    'interest_amount' => floatval($p['interest_amount']),
                    'payment_source' => $p['payment_source'],
                    'recorded_by' => $p['recorded_by'],
                    'transaction_reference' => $p['transaction_reference'] ?? null,
                    'notes' => $p['notes'] ?? null
                ];
            }, $payments);

            $loans[] = $loan;
        }

        return $loans;
    } catch (PDOException $e) {
        error_log("Error in getAllLoans: " . $e->getMessage());
        return [];
    }
}

// ===== SESSION REFRESH FUNCTION =====
function refreshSession() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    
    // Update timestamps
    $_SESSION['last_activity'] = time();
    $_SESSION['session_expiry'] = time() + 36000;
    
    // Regenerate CSRF token
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    
    return $_SESSION['csrf_token'];
}

/**
 * Debug session state
 */
function debugSession()
{
    if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
        error_log("=== SESSION DEBUG ===");
        error_log("Session ID: " . session_id());
        error_log("Session Status: " . session_status());
        error_log("Session Save Path: " . session_save_path());
        error_log("Cookie Params: " . print_r(session_get_cookie_params(), true));
        error_log("Session Data: " . print_r($_SESSION, true));
        error_log("==================");
    }
}

// Call debug after session_start
debugSession();

/**
 * Helper function to log activities
 */
function logActivity($conn, $user_id, $action, $message)
{
    try {
        $query = "INSERT INTO activity_log (admin_id, action, details, timestamp) 
                 VALUES (:admin_id, :action, :details, NOW())";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':admin_id', $user_id);
        $stmt->bindParam(':action', $action);
        $stmt->bindParam(':details', $message);
        $stmt->execute();
    } catch (PDOException $e) {
        conditional_error_log("Failed to log activity: " . $e->getMessage());
    }
}

/**
 * Function to generate JSON backup of the database
 */
function backupDatabase($conn, $tables = '*')
{
    try {
        $backupData = [];

        // Get all tables if wildcard
        if ($tables === '*') {
            $tables = [];
            $result = $conn->query("SHOW TABLES");
            while ($row = $result->fetch(PDO::FETCH_NUM)) {
                $tables[] = $row[0];
            }
        } else {
            $tables = is_array($tables) ? $tables : explode(',', $tables);
        }

        // Define table order for restore
        $tableOrder = [
            'system_settings',
            'users',
            'admins',
            'cashiers',
            'accountants',
            'staff',
            'funding_accounts',
            'funding_transactions',
            'loan_applications',
            'payments',
            'payment_schedules',
            'activity_log'
        ];

        // Sort tables according to order
        usort($tables, function ($a, $b) use ($tableOrder) {
            $posA = array_search($a, $tableOrder);
            $posB = array_search($b, $tableOrder);

            if ($posA === false && $posB === false) return 0;
            if ($posA === false) return 1;
            if ($posB === false) return -1;

            return $posA - $posB;
        });

        // Loop through tables and collect data
        foreach ($tables as $table) {
            try {
                $result = $conn->query("SELECT * FROM `$table`");
                $rows = [];

                while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
                    // Convert any binary data to base64
                    foreach ($row as $key => $value) {
                        if (is_resource($value)) {
                            $row[$key] = base64_encode(stream_get_contents($value));
                        }
                    }
                    $rows[] = $row;
                }

                $backupData[$table] = $rows;
            } catch (PDOException $e) {
                error_log("Error backing up table $table: " . $e->getMessage());
            }
        }

        // Add metadata
        $backupData['_metadata'] = [
            'timestamp' => date('Y-m-d\TH:i:s\Z'),
            'version' => '1.0',
            'table_count' => count($tables),
            'total_rows' => array_sum(array_map('count', $backupData))
        ];

        // Encode to JSON
        $jsonContent = json_encode(
            $backupData,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        // Verify JSON encoding succeeded
        if ($jsonContent === false || json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('JSON encoding failed: ' . json_last_error_msg());
        }

        return $jsonContent;
    } catch (Exception $e) {
        error_log("Backup generation error: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Logout function
 */
function logout()
{
    // Unset all session variables
    $_SESSION = array();
    
    // Delete the session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    // Destroy the session
    session_destroy();
    
    // Redirect to login page
    header("Location: /loan-system/index.php?logout=1");
    exit();
}

/**
 * Apply for loan function
 */
function applyForLoan()
{
    // Store original portal info
    $_SESSION['original_portal'] = 'admin';
    $_SESSION['viewing_as_loanee'] = true;
    $_SESSION['from_staff_portal'] = true;
    $_SESSION['staff_original_role'] = $_SESSION['user_role'];
    $_SESSION['switching_to_loanee'] = true;

    // Log the switch
    error_log("Admin applying for loan: " . print_r([
        'user_id' => $_SESSION['user_id'],
        'pf_number' => $_SESSION['pf_number'],
        'role' => $_SESSION['user_role']
    ], true));

    // Redirect to loanee portal
    header("Location: loanee.php?from=admin&action=switch_portal");
    exit();
}

// Handle the apply for loan action
if (isset($_GET['action']) && $_GET['action'] === 'apply_for_loan') {
    applyForLoan();
}

// Get system settings from database
$interest_rate = 3.0;
try {
    $query = "SELECT value FROM system_settings WHERE setting_key = 'amortization_rate'";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        $interest_rate = floatval($result['value']);
    }
} catch (PDOException $e) {
    conditional_error_log("Error getting interest rate: " . $e->getMessage());
}

// Handle password change request
$password_error = '';
$password_success = '';
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
        } else {
            try {
                // Verify current password
                $query = "SELECT password FROM users WHERE user_id = :user_id";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':user_id', $user_id);
                $stmt->execute();
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user && password_verify($current_password, $user['password'])) {
                    // Update password
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $query = "UPDATE users SET password = :password, updated_at = NOW() WHERE user_id = :user_id";
                    $stmt = $conn->prepare($query);
                    $stmt->bindParam(':password', $hashed_password);
                    $stmt->bindParam(':user_id', $user_id);
                    $stmt->execute();

                    $password_success = "Password changed successfully.";
                    $log_message = "Admin {$_SESSION['full_name']} changed their password";
                    logActivity($conn, $_SESSION['user_id'], 'change_password', $log_message);
                } else {
                    $password_error = "Current password is incorrect.";
                }
            } catch (PDOException $e) {
                conditional_error_log("Error changing password: " . $e->getMessage());
                $password_error = "Failed to change password. Please try again.";
            }
        }
    }
}

// Handle staff management operations
$staff_error = '';
$staff_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $staff_error = "Invalid request. Please try again.";
    } else {
        if ($_POST['action'] === 'add_staff' || $_POST['action'] === 'update_staff') {
            $staff_name = $_POST['staff_name'] ?? '';
            $staff_pf = $_POST['staff_pf'] ?? '';
            $staff_id_number = $_POST['staff_id_number'] ?? '';
            $staff_email = $_POST['staff_email'] ?? '';
            $staff_password = $_POST['staff_password'] ?? '';
            $staff_role = $_POST['staff_role'] ?? '';

            if (empty($staff_name) || empty($staff_pf) || empty($staff_id_number) || empty($staff_email) || empty($staff_password) || empty($staff_role)) {
                $staff_error = "All fields are required.";
            } elseif (!filter_var($staff_email, FILTER_VALIDATE_EMAIL)) {
                $staff_error = "Valid email is required.";
            } elseif (strlen($staff_password) < 8) {
                $staff_error = "Password must be at least 8 characters long.";
            } else {
                try {
                    $hashed_password = password_hash($staff_password, PASSWORD_DEFAULT);

                    // Start transaction
                    $conn->beginTransaction();

                    if ($_POST['action'] === 'add_staff') {
                        // Check if staff with this email already exists
                        $query = "SELECT user_id FROM users WHERE email = :email";
                        $stmt = $conn->prepare($query);
                        $stmt->bindParam(':email', $staff_email);
                        $stmt->execute();

                        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
                            $staff_error = "Staff with this email already exists.";
                            $conn->rollBack();
                        } else {
                            // Add new staff to users table
                            $query = "INSERT INTO users (full_name, email, id_number, pf_number, password, role, created_at) 
                                     VALUES (:full_name, :email, :id_number, :pf_number, :password, :role, NOW())";
                            $stmt = $conn->prepare($query);
                            $stmt->bindParam(':full_name', $staff_name);
                            $stmt->bindParam(':email', $staff_email);
                            $stmt->bindParam(':id_number', $staff_id_number);
                            $stmt->bindParam(':pf_number', $staff_pf);
                            $stmt->bindParam(':password', $hashed_password);
                            $stmt->bindParam(':role', $staff_role);

                            if ($stmt->execute()) {
                                $new_user_id = $conn->lastInsertId();

                                // Add to staff table
                                $query = "INSERT INTO staff (user_id, pf_number, full_name, email, id_number, password, role, created_by, created_at) 
                                         VALUES (:user_id, :pf_number, :full_name, :email, :id_number, :password, :role, :created_by, NOW())";
                                $stmt = $conn->prepare($query);
                                $stmt->bindParam(':user_id', $new_user_id);
                                $stmt->bindParam(':pf_number', $staff_pf);
                                $stmt->bindParam(':full_name', $staff_name);
                                $stmt->bindParam(':email', $staff_email);
                                $stmt->bindParam(':id_number', $staff_id_number);
                                $stmt->bindParam(':password', $hashed_password);
                                $stmt->bindParam(':role', $staff_role);
                                $stmt->bindParam(':created_by', $user_id);

                                if ($stmt->execute()) {
                                    // Add to role-specific table
                                    $role_table = $staff_role . 's';
                                    $query = "INSERT INTO {$role_table} (pf_number, full_name, email, password_hash, role, is_active, created_at) 
                                             VALUES (:pf_number, :full_name, :email, :password_hash, :role, 1, NOW())";
                                    $stmt = $conn->prepare($query);
                                    $stmt->bindParam(':pf_number', $staff_pf);
                                    $stmt->bindParam(':full_name', $staff_name);
                                    $stmt->bindParam(':email', $staff_email);
                                    $stmt->bindParam(':password_hash', $hashed_password);
                                    $stmt->bindParam(':role', $staff_role);

                                    if ($stmt->execute()) {
                                        $conn->commit();
                                        $staff_success = "Staff member added successfully.";
                                        $log_message = "Admin {$user_name} added new staff member: {$staff_name} ({$staff_email})";
                                        logActivity($conn, $user_id, 'add_staff', $log_message);
                                    } else {
                                        $conn->rollBack();
                                        $staff_error = "Failed to add staff member to role table.";
                                    }
                                } else {
                                    $conn->rollBack();
                                    $staff_error = "Failed to add staff member to staff table.";
                                }
                            } else {
                                $conn->rollBack();
                                $staff_error = "Failed to add staff member.";
                            }
                        }
                    } else if ($_POST['action'] === 'update_staff') {
                        // Update existing staff
                        $staff_id = $_POST['staff_id'] ?? '';

                        // Get current staff data
                        $query = "SELECT * FROM users WHERE user_id = :user_id";
                        $stmt = $conn->prepare($query);
                        $stmt->bindParam(':user_id', $staff_id);
                        $stmt->execute();
                        $current_staff = $stmt->fetch(PDO::FETCH_ASSOC);

                        if ($current_staff) {
                            // Update users table
                            $query = "UPDATE users SET full_name = :full_name, email = :email, id_number = :id_number, 
                                     pf_number = :pf_number, role = :role, updated_at = NOW()";

                            if (!empty($staff_password)) {
                                $query .= ", password = :password";
                            }

                            $query .= " WHERE user_id = :user_id";

                            $stmt = $conn->prepare($query);
                            $stmt->bindParam(':full_name', $staff_name);
                            $stmt->bindParam(':email', $staff_email);
                            $stmt->bindParam(':id_number', $staff_id_number);
                            $stmt->bindParam(':pf_number', $staff_pf);
                            $stmt->bindParam(':role', $staff_role);
                            $stmt->bindParam(':user_id', $staff_id);

                            if (!empty($staff_password)) {
                                $stmt->bindParam(':password', $hashed_password);
                            }

                            if ($stmt->execute()) {
                                // Update staff table
                                $query = "UPDATE staff SET full_name = :full_name, email = :email, id_number = :id_number, 
                                         pf_number = :pf_number, role = :role, updated_by = :updated_by, updated_at = NOW()";

                                if (!empty($staff_password)) {
                                    $query .= ", password = :password";
                                }

                                $query .= " WHERE user_id = :user_id";

                                $stmt = $conn->prepare($query);
                                $stmt->bindParam(':full_name', $staff_name);
                                $stmt->bindParam(':email', $staff_email);
                                $stmt->bindParam(':id_number', $staff_id_number);
                                $stmt->bindParam(':pf_number', $staff_pf);
                                $stmt->bindParam(':role', $staff_role);
                                $stmt->bindParam(':updated_by', $user_id);
                                $stmt->bindParam(':user_id', $staff_id);

                                if (!empty($staff_password)) {
                                    $stmt->bindParam(':password', $hashed_password);
                                }

                                if ($stmt->execute()) {
                                    // Handle role change
                                    if ($current_staff['role'] !== $staff_role) {
                                        // Remove from old role table
                                        $old_role_table = $current_staff['role'] . 's';
                                        $query = "DELETE FROM {$old_role_table} WHERE pf_number = :pf_number";
                                        $stmt = $conn->prepare($query);
                                        $stmt->bindParam(':pf_number', $current_staff['pf_number']);
                                        $stmt->execute();

                                        // Add to new role table
                                        $new_role_table = $staff_role . 's';
                                        $query = "INSERT INTO {$new_role_table} (pf_number, full_name, email, password_hash, role, is_active, created_at) 
                                                 VALUES (:pf_number, :full_name, :email, :password_hash, :role, 1, NOW())";
                                        $stmt = $conn->prepare($query);
                                        $stmt->bindParam(':pf_number', $staff_pf);
                                        $stmt->bindParam(':full_name', $staff_name);
                                        $stmt->bindParam(':email', $staff_email);

                                        if (!empty($staff_password)) {
                                            $stmt->bindParam(':password_hash', $hashed_password);
                                        } else {
                                            $stmt->bindParam(':password_hash', $current_staff['password']);
                                        }

                                        $stmt->bindParam(':role', $staff_role);
                                        $stmt->execute();
                                    } else {
                                        // Update existing role table
                                        $role_table = $staff_role . 's';
                                        $query = "UPDATE {$role_table} SET pf_number = :pf_number, full_name = :full_name, email = :email";

                                        if (!empty($staff_password)) {
                                            $query .= ", password_hash = :password_hash";
                                        }

                                        $query .= " WHERE pf_number = :old_pf_number";

                                        $stmt = $conn->prepare($query);
                                        $stmt->bindParam(':pf_number', $staff_pf);
                                        $stmt->bindParam(':full_name', $staff_name);
                                        $stmt->bindParam(':email', $staff_email);
                                        $stmt->bindParam(':old_pf_number', $current_staff['pf_number']);

                                        if (!empty($staff_password)) {
                                            $stmt->bindParam(':password_hash', $hashed_password);
                                        }

                                        $stmt->execute();
                                    }

                                    $conn->commit();
                                    $staff_success = "Staff member updated successfully.";
                                    $log_message = "Admin {$user_name} updated staff member: {$staff_name} ({$staff_email})";
                                    logActivity($conn, $user_id, 'update_staff', $log_message);
                                } else {
                                    $conn->rollBack();
                                    $staff_error = "Failed to update staff member in staff table.";
                                }
                            } else {
                                $conn->rollBack();
                                $staff_error = "Failed to update staff member.";
                            }
                        } else {
                            $conn->rollBack();
                            $staff_error = "Staff member not found";
                        }
                    }
                } catch (PDOException $e) {
                    $conn->rollBack();
                    conditional_error_log("Error managing staff: " . $e->getMessage());
                    $staff_error = "Failed to save staff member.";
                }
            }
        } else if ($_POST['action'] === 'toggle_user_status' || $_POST['action'] === 'toggle_status') {
            // Validate required fields
            if (!isset($_POST['user_id']) || !isset($_POST['is_active'])) {
                echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
                exit;
            }
            
            $user_id_to_toggle = $_POST['user_id'] ?? 0;
            $is_active = $_POST['is_active'] ?? null;
            
            // Validate user_id
            if ($user_id_to_toggle <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
                exit;
            }
            
            // Validate is_active value
            if (!in_array($is_active, [0, 1])) {
                echo json_encode(['success' => false, 'message' => 'Invalid status value']);
                exit;
            }
            
            // Prevent self-deactivation
            if ($user_id_to_toggle == $user_id && $is_active == 0) {
                echo json_encode(['success' => false, 'message' => 'Cannot deactivate yourself']);
                exit;
            }
            
            try {
                $conn->beginTransaction();
                
                // Get user's role and PF number
                $query = "SELECT role, pf_number, full_name FROM users WHERE user_id = :user_id";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':user_id', $user_id_to_toggle);
                $stmt->execute();
                $user_to_toggle = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$user_to_toggle) {
                    throw new Exception('User not found');
                }
                
                // Update users table
                $query = "UPDATE users SET is_active = :is_active, updated_at = NOW() WHERE user_id = :user_id";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':is_active', $is_active);
                $stmt->bindParam(':user_id', $user_id_to_toggle);
                $stmt->execute();
                
                // Update role-specific table
                $role_table = $user_to_toggle['role'] . 's';
                $query = "UPDATE {$role_table} SET is_active = :is_active WHERE pf_number = :pf_number";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':is_active', $is_active);
                $stmt->bindParam(':pf_number', $user_to_toggle['pf_number']);
                $stmt->execute();
                
                $conn->commit();
                
                $status_text = $is_active == 1 ? 'activated' : 'deactivated';
                $log_message = "Admin {$user_name} {$status_text} user: {$user_to_toggle['full_name']} (ID: $user_id_to_toggle)";
                logActivity($conn, $user_id, 'toggle_user_status', $log_message);
                
                echo json_encode([
                    'success' => true, 
                    'message' => "User {$status_text} successfully"
                ]);
            } catch (Exception $e) {
                $conn->rollBack();
                conditional_error_log("Error toggling user status: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Database error']);
            }
        }
         
         else if ($_POST['action'] === 'delete_staff') {
            $staff_id = $_POST['staff_id'] ?? '';
            
            if (empty($staff_id)) {
                $staff_error = "Staff ID is required.";
            } else {
                try {
                    // Get staff details
                    $query = "SELECT u.*, s.role FROM users u JOIN staff s ON u.user_id = s.user_id WHERE u.user_id = :user_id";
                    $stmt = $conn->prepare($query);
                    $stmt->bindParam(':user_id', $staff_id);
                    $stmt->execute();
                    $staff_details = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($staff_details) {
                        $conn->beginTransaction();
                        
                        // Delete from users table
                        $query = "DELETE FROM users WHERE user_id = :user_id";
                        $stmt = $conn->prepare($query);
                        $stmt->bindParam(':user_id', $staff_id);
                        $stmt->execute();
                        
                        // Delete from staff table
                        $query = "DELETE FROM staff WHERE user_id = :user_id";
                        $stmt = $conn->prepare($query);
                        $stmt->bindParam(':user_id', $staff_id);
                        $stmt->execute();
                        
                        // Delete from role-specific table
                        $role_table = $staff_details['role'] . 's';
                        $query = "DELETE FROM {$role_table} WHERE pf_number = :pf_number";
                        $stmt = $conn->prepare($query);
                        $stmt->bindParam(':pf_number', $staff_details['pf_number']);
                        $stmt->execute();
                        
                        $conn->commit();
                        $staff_success = "Staff member deleted successfully.";
                        $log_message = "Admin {$user_name} deleted staff member: {$staff_details['full_name']} ({$staff_details['email']})";
                        logActivity($conn, $user_id, 'delete_staff', $log_message);
                    } else {
                        $conn->rollBack();
                        $staff_error = "Staff member not found.";
                    }
                } catch (PDOException $e) {
                    $conn->rollBack();
                    conditional_error_log("Error deleting staff: " . $e->getMessage());
                    $staff_error = "Failed to delete staff member.";
                }
            }
        }
    }
}

// Get system statistics
$total_loans = 0;
$pending_loans = 0;
$forwarded_loans = 0;
$accepted_loans = 0;
$dispatched_loans = 0;
$active_loans = 0;
$returned_loans = 0;
$rejected_loans = 0;
$total_funds = 0;
$recent_activities = [];

try {
    // Get loan statistics
    $query = "SELECT status, COUNT(*) as count FROM loan_applications GROUP BY status";
    $stmt = $conn->prepare($query);
    $stmt->execute();

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $status = $row['status'];
        $count = $row['count'];
        
        switch ($status) {
            case 'pending': $pending_loans = $count; break;
            case 'forwarded': $forwarded_loans = $count; break;
            case 'accepted': $accepted_loans = $count; break;
            case 'dispatched': $dispatched_loans = $count; break;
            case 'active': $active_loans = $count; break;
            case 'returned': $returned_loans = $count; break;
            case 'rejected': $rejected_loans = $count; break;
        }
        
        $total_loans += $count;
    }
} catch (PDOException $e) {
    conditional_error_log("Error getting loan statistics: " . $e->getMessage());
}

// Get funds and activity data
try {
    $query = "SELECT SUM(balance) as total FROM funding_accounts";
    $stmt = $conn->prepare($query);
    $stmt->execute();

    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $total_funds = $row['total'] ?? 0;
    }

    $query = "SELECT * FROM activity_log ORDER BY timestamp DESC LIMIT 5";
    $stmt = $conn->prepare($query);
    $stmt->execute();

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $recent_activities[] = $row;
    }
} catch (PDOException $e) {
    conditional_error_log("Error getting admin statistics: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>

    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Administrator Portal</title>
    <span id="csrf-token-data" data-csrf-token="<?php echo $_SESSION['csrf_token']; ?>" style="display:none;"></span>
    <script src="../js/logout-handler.js"></script>
    <link rel="stylesheet" href="../css/admin.css" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <style>

    </style>
    <script src="../js/lucide.min.js"></script>
</head>

<body>

    <!-- Screen reader announcements -->
    <div id="statusMessage" class="sr-only" aria-live="polite"></div>

    <!-- Toast container -->
    <div id="toastContainer" class="toast-container"></div>

    <!-- Real-time update indicator -->
    <div id="updateIndicator" class="update-indicator">
        <i data-lucide="refresh-cw" class="icon-sm"></i> Data updated from other portal
    </div>

    <!-- Loading overlay -->
    <div id="loadingOverlay" class="loading-overlay hidden">
        <div class="loading-content">
            <div class="loading-spinner"></div>
            <p class="mt-2">Loading...</p>
        </div>
    </div>

    <!-- MAIN HEADER -->
    <header id="mainHeader">
        <button id="menuToggle" class="hamburger" aria-label="Toggle navigation menu">
            ☰
        </button>
        <div class="logo-box">
            <img src="../logo.jpg" alt="Logo">
            <h1>Administrator Portal</h1>
        </div>

        <!-- MODIFIED: Theme selector with only light and dark options -->
        <div class="theme-selector" id="themeSelector">
                <button class="theme-option active" data-theme="light" aria-label="Light theme" title="Light theme">
                    <i data-lucide="sun" class="icon-sm"></i>
                </button>
                <button class="theme-option" data-theme="dark" aria-label="Dark theme" title="Dark theme">
                    <i data-lucide="moon" class="icon-sm"></i>
                </button>
            </div>

        <!-- Profile Badge -->
        <div class="profile-badge" id="profileBadge">
            <div class="profile-avatar" id="profileAvatar">
                <?php echo strtoupper(substr($user_name, 0, 2)); ?>
            </div>
            <div class="profile-info">
                <p class="profile-name"><?php echo htmlspecialchars($user_name); ?></p>
                <p class="profile-role">Administrator</p>
            </div>
            <div class="profile-dropdown" id="profileDropdown">
                <div class="profile-dropdown-item" id="profileView">
                    <i data-lucide="user" class="icon-sm"></i> Profile
                </div>
                <div class="profile-dropdown-item" id="profileSettings">
                    <i data-lucide="lock" class="icon-sm"></i> Security
                </div>
                <div class="profile-dropdown-item danger" id="logoutBtn">
                    <i data-lucide="log-out" class="icon-sm"></i> Logout
                </div>
            </div>
        </div>
    </header>

    <!-- HORIZONTAL CONTAINER -->
    <div class="horizontal-container">
        <!-- SIDEBAR NAVIGATION -->
        <nav id="sidebar" class="collapsed" role="navigation" aria-label="Admin navigation">
            <div class="sidebar-logo">
                <img src="../logo.jpg" alt="logo">
                <div class="sidebar-logo-text">Admin Portal</div>
            </div>

            <button class="active tooltip" data-filter="dashboard" id="navDashboard">
                <span class="button-icon"><i data-lucide="bar-chart-3" class="icon-sm"></i></span>
                <span class="button-text">Dashboard</span>
                <span class="tooltiptext">Dashboard</span>
            </button>
            <button class="tooltip" data-filter="loans" id="navLoans">
                <span class="button-icon"><i data-lucide="coins" class="icon-sm"></i></span>
                <span class="button-text">Loans Overview</span>
                <span class="tooltiptext">Loans Overview</span>
            </button>

            <button class="tooltip apply-loan-btn">
                <span class="button-icon"><i data-lucide="file-edit" class="icon-sm"></i></span>
                <span class="button-text">Apply for Loan</span>
                <span class="tooltiptext">Apply for Loan</span>
            </button>

            <button class="tooltip" data-filter="accounts" id="navAccounts">
                <span class="button-icon">🏦</span>
                <span class="button-text">Accounts</span>
                <span class="tooltiptext">Accounts</span>
            </button>
            <button class="tooltip" data-filter="staff" id="navStaff">
                <span class="button-icon">👥</span>
                <span class="button-text">User Management</span>
                <span class="tooltiptext">User Management</span>
            </button>
            <button class="tooltip" data-filter="activity" id="navActivity">
                <span class="button-icon"><i data-lucide="clipboard-list" class="icon-sm"></i></span>
                <span class="button-text">Activity Feed</span>
                <span class="tooltiptext">Activity Feed</span>
            </button>

            <button class="tooltip" data-filter="legacy-debtors">
                <span class="button-icon">📒</span>
                <span class="button-text">Legacy Debtors</span>
                <span class="tooltiptext">View Imported Debtors</span>
            </button>
            <button class="tooltip" onclick="window.location.href='../legacy-debt.php'">
                <span class="button-icon">🧾</span>
                <span class="button-text">Legacy Debt</span>
                <span class="tooltiptext">Legacy debt ledger and approvals</span>
            </button>

            <button class="tooltip" data-filter="settings" id="navSettings">
                <span class="button-icon"><i data-lucide="settings" class="icon-sm"></i></span>
                <span class="button-text">Settings</span>
                <span class="tooltiptext">Settings</span>
            </button>

            <button class="tooltip" data-filter="reports" id="navReports">
                <span class="button-icon"><i data-lucide="bar-chart-3" class="icon-sm"></i></span>
                <span class="button-text">Financial Reports</span>
                <span class="tooltiptext">Financial Reports</span>
            </button>

            <button class="tooltip" id="logoutBtnSidebar" style="margin-top: auto; background: #c0392b;">
                <span class="button-icon"><i data-lucide="log-out" class="icon-sm"></i></span>
                <span class="button-text">Logout</span>
                <span class="tooltiptext">Logout</span>
            </button>
        </nav>

        <!-- MAIN CONTENT -->
        <main class="main" id="main">
            <!-- Dashboard Section -->
            <section id="dashboardSection">
                <h2>System Overview</h2>
                <div class="stats" id="dashboardStats">
                    <div class="stat-card">
                        <div class="stat-number" id="totalLoans"><?php echo $total_loans; ?></div>
                        <div class="stat-label">Total Loans</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number" id="pendingLoans"><?php echo $pending_loans; ?></div>
                        <div class="stat-label">Pending (Cash)</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number" id="forwardedLoans"><?php echo $forwarded_loans; ?></div>
                        <div class="stat-label">Forwarded (Acc)</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number" id="acceptedLoans"><?php echo $accepted_loans; ?></div>
                        <div class="stat-label">Accepted (Acc.)</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number" id="dispatchedLoans"><?php echo $dispatched_loans; ?></div>
                        <div class="stat-label">Dispatched (Acc.)</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number" id="activeLoans"><?php echo $active_loans; ?></div>
                        <div class="stat-label">Active Loans</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number" id="returnedLoans"><?php echo $returned_loans; ?></div>
                        <div class="stat-label">Returned (Cash/Acc.)</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number" id="rejectedLoans"><?php echo $rejected_loans; ?></div>
                        <div class="stat-label">Rejected</div>
                    </div>
                    <div class="stat-card total-funds">
                        <div class="stat-number" id="totalFunds" data-value="<?php echo $total_funds; ?>">
                            KES <?php echo number_format($total_funds, 2); ?>
                        </div>
                        <div class="stat-label">Total Fund Balance</div>
                    </div>
                </div>

                <!-- Recent Activity Summary -->
                <div class="account-card">
                    <h3>Recent Activity</h3>
                    <div id="recentActivity" class="activity-feed">
                        <?php if (empty($recent_activities)): ?>
                        <p class="small text-center">No recent activity</p>
                        <?php else: ?>
                        <?php foreach ($recent_activities as $activity): ?>
                        <div class="activity-item">
                            <div class="activity-icon <?php echo $activity['action']; ?>">
                                <?php
                                        $icon = '';
                                        switch ($activity['action']) {
                                            case 'add_staff':
                                                $icon = '<i data-lucide="user" class="icon-sm"></i>';
                                                break;
                                            case 'update_staff':
                                                $icon = '<i data-lucide="edit-2" class="icon-sm"></i>';
                                                break;
                                            case 'delete_staff':
                                                $icon = '🗑️';
                                                break;
                                            case 'change_password':
                                                $icon = '🔐';
                                                break;
                                            case 'update_settings':
                                                $icon = '<i data-lucide="settings" class="icon-sm"></i>';
                                                break;
                                            case 'logout':
                                                $icon = '<i data-lucide="log-out" class="icon-sm"></i>';
                                                break;
                                            default:
                                                $icon = '<i data-lucide="clipboard-list" class="icon-sm"></i>';
                                                break;
                                        }
                                        echo $icon;
                                        ?>
                            </div>
                            <div class="activity-content">
                                <div class="small"><?php echo htmlspecialchars($activity['details']); ?></div>
                                <div class="activity-time">
                                    <?php echo date('Y-m-d H:i:s', strtotime($activity['timestamp'])); ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <!-- Loans Section -->
            <section id="loansSection" class="hidden">
                <h2>All Loan Applications</h2>
                <div class="row mb-2" style="justify-content: space-between;">
                    <div class="row" style="flex: 1;">
                        <div class="search-container">
                            <span class="search-icon"><i data-lucide="search" class="icon-sm"></i></span>
                            <input id="searchInput" class="search-input"
                                placeholder="Search by name, ID, email, or PF number">
                        </div>
                        <label>Status:
                            <select id="filterStatus">
                                <option value="">All</option>
                                <option value="pending">Pending</option>
                                <option value="forwarded">Forwarded</option>
                                <option value="accepted">Accepted</option>
                                <option value="dispatched">Dispatched</option>
                                <option value="active">Active</option>
                                <option value="returned">Returned</option>
                                <option value="rejected">Rejected</option>
                            </select>
                        </label>
                        <label>Loan Type:
                            <select id="filterLoanType">
                                <option value="">All</option>
                                <option value="mortgage">Mortgage</option>
                                <option value="car">Car</option>
                            </select>
                        </label>
                    </div>
                    <div class="row">
                        <button id="filterBtn" class="btn-secondary">
                            🔧 Filter
                        </button>
                        <button id="clearFilters" class="btn-outline">
                            <i data-lucide="x-circle" class="icon-sm"></i> Clear
                        </button>
                        <button id="refreshBtn" class="btn-secondary">
                            <i data-lucide="refresh-cw" class="icon-sm"></i> Refresh
                        </button>
                        <button id="exportBtn" class="btn-success">
                            <i data-lucide="download" class="icon-sm"></i> Export
                        </button>
                    </div>
                </div>

                <div class="table-container">
                    <table id="loansTable">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Full Name</th>
                                <th>ID Number</th>
                                <th>PF Number</th>
                                <th>Email</th>
                                <th>Loan Type</th>
                                <th>Loan Amount</th>
                                <th>Status</th>
                                <th>Feedback</th>
                                <th>Last Updated</th>
                            </tr>
                        </thead>
                        <tbody id="loansTableBody"></tbody>
                    </table>
                </div>
                <div id="loansPagination" class="pagination"></div>
            </section>

            <!-- Accounts Section -->
            <section id="accountsSection" class="hidden">
                <h2>Funding Accounts & Top-up</h2>
                <div id="funding_accountsContainer"></div>

                <h3>Add Funding Account</h3>
                <form id="addFundForm" class="row mb-2">
                    <input id="newFundName" placeholder="Account name (e.g., Housing Fund)" required>
                    <input id="newFundBalance" type="number" placeholder="Initial balance (KES)" required>
                    <button type="submit" class="btn-primary">
                        ➕ Add Account
                    </button>
                </form>
            </section>

            <!-- Transaction History Modal -->
            <div id="transactionModal" class="modal" style="display: none;">
                <div class="modal-content" style="max-width: 1000px;">
                    <div class="modal-header">
                        <h2><i data-lucide="bar-chart-3" class="icon-sm"></i> Transaction History</h2>
                        <button class="close-modal" onclick="closeTransactionModal()">&times;</button>
                    </div>
                    <div class="modal-body">
                        <div id="transactionSummary" class="account-card" style="margin-bottom: 20px;">
                            <!-- Summary will be inserted here -->
                        </div>

                        <h3>Transaction Details</h3>
                        <div class="table-container">
                            <table id="transactionTable">
                                <thead>
                                    <tr>
                                        <th>Date & Time</th>
                                        <th>Type</th>
                                        <th>Amount</th>
                                        <th>Reference</th>
                                        <th>Recorded By</th>
                                    </tr>
                                </thead>
                                <tbody id="transactionTableBody">
                                    <!-- Transactions will be inserted here -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn-primary" onclick="exportTransactionHistory()"><i data-lucide="bar-chart-3" class="icon-sm"></i> Export Excel (All
                            Details)</button>
                        <button class="btn-secondary" onclick="closeTransactionModal()">Close</button>
                    </div>
                </div>
            </div>

            <!-- User Management Section -->
            <section id="staffSection" class="hidden">
                <h2>User Management</h2>

                <!-- Import Users Section -->
                <div class="account-card" style="margin-bottom: 20px; border-left: 4px solid var(--info);">
                    <h3 style="margin-top: 0;"><i data-lucide="download" class="icon-sm"></i> Import Users</h3>
                    <p class="small" style="color: var(--text-secondary); margin-bottom: 15px;">
                        Import multiple users from PDF, Excel, or CSV files. Default password will be their ID number,
                        default role will be Loanee.
                    </p>
                    <div class="row mb-2">
                        <button id="importUsersBtn" class="btn-info">
                            <i data-lucide="download" class="icon-sm"></i> Import Users
                        </button>
                        <button id="downloadTemplateBtn" class="btn-outline">
                            <i data-lucide="file-text" class="icon-sm"></i> Download Template
                        </button>
                    </div>
                    <input type="file" id="importFileInput" accept=".pdf,.xlsx,.xls,.csv" style="display: none;">
                </div>

                <!-- Search and Filter Controls -->
                <div class="row mb-2" style="justify-content: space-between;">
                    <div class="row" style="flex: 1;">
                        <div class="search-container">
                            <span class="search-icon"><i data-lucide="search" class="icon-sm"></i></span>
                            <input id="userSearchInput" class="search-input"
                                placeholder="Search by name, email, PF number, or ID number">
                        </div>
                        <label>Role:
                            <select id="filterUserRole">
                                <option value="">All</option>
                                <option value="admin">Admin</option>
                                <option value="cashier">Cashier</option>
                                <option value="accountant">Accountant</option>
                                <option value="loanee">Loanee</option>
                            </select>
                        </label>
                        <label>Status:
                            <select id="filterUserStatus">
                                <option value="">All</option>
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>
                        </label>
                    </div>
                    <div class="row">
                        <button id="filterUserBtn" class="btn-secondary">
                            🔧 Filter
                        </button>
                        <button id="clearUserFilters" class="btn-outline">
                            <i data-lucide="x-circle" class="icon-sm"></i> Clear
                        </button>
                        <button id="refreshUserBtn" class="btn-secondary">
                            <i data-lucide="refresh-cw" class="icon-sm"></i> Refresh
                        </button>
                        <button id="exportUserBtn" class="btn-success">
                            <i data-lucide="download" class="icon-sm"></i> Export
                        </button>
                    </div>
                </div>

                <div class="table-container">
                    <table id="staffTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Full Name</th>
                                <th>PF Number</th>
                                <th>ID Number</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>

                <h3>Add / Update User</h3>
                <form id="staffForm" class="row mb-2" method="post">
                    <input type="hidden" name="action" value="add_staff">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input id="staffName" name="staff_name" placeholder="Full Name" autocomplete="name" required>
                    <input id="staffPf" name="staff_pf" placeholder="PF Number (9-11 digits)" autocomplete="off"
                        required>
                    <input id="staffIdNumber" name="staff_id_number" placeholder="ID Number" autocomplete="off"
                        required>
                    <input id="staffEmail" name="staff_email" type="email" placeholder="Email Address"
                        autocomplete="email" required>
                    <div style="position: relative; flex: 1;">
                        <input id="staffPwd" name="staff_password" type="password" placeholder="Password"
                            autocomplete="new-password" required>
                        <div class="password-requirements">
                            Password must contain:
                            <ul>
                                <li id="req-length" class="requirement-unmet">At least 8 characters</li>
                                <li id="req-alphanumeric" class="requirement-unmet">Both letters and numbers
                                    (alphanumeric)</li>
                            </ul>
                        </div>
                        <div class="password-strength">
                            <div id="strengthBar" class="password-strength-bar"></div>
                        </div>
                    </div>
                    <select id="staffRole" name="staff_role" autocomplete="off">
                        <option value="loanee">Loanee</option>
                        <option value="cashier">Cashier</option>
                        <option value="accountant">Accountant</option>
                        <option value="admin">Administrator</option>
                    </select>
                    <button type="submit" class="btn-primary" id="saveStaffBtn">
                        💾 Save User
                    </button>
                    <button type="button" id="cancelEditBtn" class="btn-secondary hidden">
                        <i data-lucide="x-circle" class="icon-sm"></i> Cancel Edit
                    </button>
                </form>
                <?php if (isset($staff_error)): ?>
                <div class="error-message"><?php echo $staff_error; ?></div>
                <?php endif; ?>
                <?php if (isset($staff_success)): ?>
                <div class="success-message"><?php echo $staff_success; ?></div>
                <?php endif; ?>
            </section>

            <!-- Activity Feed Section -->
            <section id="activitySection" class="hidden">
                <h2>Real-time Activity Feed</h2>
                <div class="account-card">
                    <div class="row mb-2">
                        <button id="clearActivityBtn" class="btn-secondary">
                            🗑️ Clear Activity
                        </button>
                        <button id="refreshActivityBtn" class="btn-secondary">
                            <i data-lucide="refresh-cw" class="icon-sm"></i> Refresh
                        </button>
                    </div>
                    <div id="activityFeed" class="activity-feed">
                        <p class="small text-center">No activity recorded</p>
                    </div>
                </div>
            </section>



            <!-- Settings Section -->
            <section id="settingsSection" class="hidden">
                <h2>System Settings</h2>

                <div class="account-card">
                    <h3>Interest Rate Settings</h3>
                    <label for="amortRate">Annual Interest Rate (%) for Amortization</label>
                    <div class="row mt-2">
                        <input id="amortRate" name="setting_value" type="number" min="0" max="100" step="0.1"
                            value="<?php echo $interest_rate; ?>" style="width: 120px;">
                        <button type="button" id="saveAmort" class="btn-primary">
                            💾 Save Rate
                        </button>
                        <div id="amortStatus" class="small ml-2"></div>
                    </div>
                    <?php if (isset($settings_error)): ?>
                    <div class="error-message"><?php echo $settings_error; ?></div>
                    <?php endif; ?>
                    <?php if (isset($settings_success)): ?>
                    <div class="success-message"><?php echo $settings_success; ?></div>
                    <?php endif; ?>
                </div>

                <div class="account-card">
                    <h3>Profile & Security</h3>
                    <p style="color:#6c757d;font-size:14px;margin-top:5px">
                        For your security, we use bcrypt to securely hash your passwords.
                    </p>

                    <?php if (isset($password_error)): ?>
                    <div class="error-message"><?php echo $password_error; ?></div>
                    <?php endif; ?>

                    <?php if (isset($password_success)): ?>
                    <div class="success-message"><?php echo $password_success; ?></div>
                    <?php endif; ?>

                    <form method="post" action id="passwordForm">
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

                <div class="account-card">
                    <h3>Data Management</h3>
                    <div class="row mt-2">
                        <button id="backupDataBtn" class="btn btn-success">
                            <i data-lucide="download" class="icon-sm"></i> Download Backup
                        </button>
                        <button id="restoreDataBtn" class="btn-warning">
                            📤 Restore Full Backup
                        </button>

                        <input type="file" id="restoreFileInput" accept=".json" style="display: none;">
                    </div>
                </div>

                <div class="account-card">
                    <h3>Activity Monitoring</h3>
                    <div class="row mt-2">
                        <button id="viewLogsBtn" class="btn-primary">
                            <i data-lucide="clipboard-list" class="icon-sm"></i> View Activity Logs
                        </button>
                        <button id="clearLogsBtn" class="btn-secondary">
                            🗑️ Clear Logs
                        </button>
                    </div>
                </div>

                <div class="account-card">
                    <h3>Danger Zone</h3>
                    <p class="small text-danger">These actions are irreversible and will permanently delete data.</p>
                    <div class="row mt-2">
                        <button id="clearStaffBtn" class="btn-danger">
                            🗑️ Clear All Staff
                        </button>
                        <button id="clearLoansBtn" class="btn-danger">
                            🗑️ Clear ALL Loans
                        </button>
                        <button id="clearSystemBtn" class="btn-danger">
                            💣 Clear ALL System Data
                        </button>
                    </div>
                </div>
            </section>

            <!-- Financial Reports Section -->
            <section id="reportsSection" class="hidden">
                <h2><i data-lucide="bar-chart-3" class="icon-sm"></i> Financial Reports & Analytics</h2>

                <!-- Export Buttons -->
                <div style="margin-bottom: 20px;">
                    <button id="exportOverallSummaryBtn" class="btn-primary">⬇ Export Overall Summary</button>
                    <button id="exportMortgageLoansBtn" class="btn-primary">⬇ Export Mortgage Loans</button>
                    <button id="exportCarLoansBtn" class="btn-primary">⬇ Export Car Loans</button>
                    <button id="exportPaymentSummaryBtn" class="btn-primary">⬇ Export Payment Summary</button>
                    <button id="exportCompleteReportBtn" class="btn-danger">⬇ Export Complete Report</button>
                </div>

                <!-- Reports Content -->
                <div id="reportsContent"></div>
            </section>

            <!-- Legacy Debtors Section -->
            <section id="legacyDebtorsSection" class="hidden">
                <!-- Content will be dynamically loaded here -->
            </section>

        </main>
    </div>

    <script>
    window.CSRF_TOKEN = '<?php echo $_SESSION['csrf_token']; ?>';

    // CSRF Token Management
    const APP_STATE = {
        csrfToken: window.CSRF_TOKEN || document.querySelector('#csrf-token-data')?.dataset.csrfToken || ''
    };

    window.currentAdminUserId = <?php echo json_encode($user_id ?? 0); ?>;

    // Dynamic API base URL configuration
    function getApiBaseUrl() {
        const hostname = window.location.hostname;
        const pathname = window.location.pathname;
        const basePath = pathname.substring(0, pathname.lastIndexOf('/'));

        if (hostname === 'localhost' || hostname === '127.0.0.1') {
            return `http://localhost/loan-system/api`;
        } else {
            return `${window.location.protocol}//${hostname}${basePath}/api`;
        }
    }

    /* ===========================
       🎯 INITIALIZATION
       =========================== */

    function improvedMountPortal() {
        const isLoggedIn = <?php echo isset($_SESSION['user_id']) ? 'true' : 'false'; ?>;
        if (isLoggedIn) {
            document.body.classList.add('logged-in');
            if (elements.mainHeader) elements.mainHeader.classList.remove('hidden');
            if (elements.sidebar) elements.sidebar.classList.remove('hidden');
            if (elements.main) elements.main.classList.remove('hidden');

            showSection('dashboard');

            // Load data with proper timing
            setTimeout(() => {
                renderfunding_accounts();
            }, 200);

            setTimeout(() => {
                updateStats();
                loadAmortRate();
                updateRecentActivity();
            }, 800);

            setTimeout(() => {
                if (typeof window.initLogout === 'function') {
                    window.initLogout(['logoutBtn', 'logoutBtnSidebar'], {
                        useAjax: true,
                        confirmMessage: 'Are you sure you want to logout?'
                    });
                }
            }, 1000);
        } else {
            document.body.classList.remove('logged-in');
            window.location.href = 'index.php';
        }
    }

    /**
     * Pass loans data from PHP to JavaScript safely
     **/

    window.initialLoansData = <?php 
$allLoans = getAllLoans();
echo json_encode($allLoans, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>;
    </script>
    <script src="../js/admin.js"></script>

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
