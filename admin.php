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
    header("Location: index.php?error=access_denied");
    exit();
}
   
   /* Final check - if not recognized as admin, deny access
   if (!$loggedInAsAdmin) {
       error_log('ADMIN ACCESS DENIED - Session dump: ' . print_r($_SESSION, true));
       session_unset();
       session_destroy();
       header("Location: index.php?error=access_denied");
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
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

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
    header("Location: index.php?logout=1");
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
            $user_id_to_toggle = $_POST['user_id'] ?? 0;
            $is_active = isset($_POST['is_active']) ? intval($_POST['is_active']) : null;
            
            if ($user_id_to_toggle <= 0 || !in_array($is_active, [0, 1])) {
                echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
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
                $log_message = "Admin {$user_name} $status_text user: {$user_to_toggle['full_name']} (ID: $user_id_to_toggle)";
                logActivity($conn, $user_id, 'toggle_user_status', $log_message);
                
                echo json_encode([
                    'success' => true, 
                    'message' => "User $status_text successfully"
                ]);
            } catch (Exception $e) {
                $conn->rollBack();
                conditional_error_log("Error toggling user status: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Database error']);
            }
            exit;
        } else if ($_POST['action'] === 'delete_staff') {
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
    <script src="js/logout-handler.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <style>
    /* ===== CSS Variables ===== */
    :root {
        --green: #4a6b4a;
        --blue: #3a6b9a;
        --muted: #6c757d;
        --danger: #b33a3a;
        --warning: #ff9800;
        --success: #4caf50;
        --info: #17a2b8;
        --sidebar-bg: #2c3e50;
        --bg: #f5f9f7;

        /* Light theme */
        --bg-primary: #f5f9f7;
        --bg-secondary: #fff;
        --bg-tertiary: #f8f9fa;
        --text-primary: #333;
        --text-secondary: #6c757d;
        --text-inverse: #fff;
        --border-color: #e6e6e6;
        --shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        --shadow-hover: 0 4px 20px rgba(0, 0, 0, 0.15);
        --header-bg: var(--green);
    }

    [data-theme="dark"] {
        --bg-primary: #1a1a1a;
        --bg-secondary: #2d2d2d;
        --bg-tertiary: #3a3a3a;
        --text-primary: #f0f0f0;
        --text-secondary: #a0a0a0;
        --text-inverse: #1a1a1a;
        --border-color: #444;
        --shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
        --shadow-hover: 0 4px 20px rgba(0, 0, 0, 0.5);
        --header-bg: #1a2a1a;
    }

    /* ===== Base Styles ===== */
    * {
        box-sizing: border-box;
    }

    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        margin: 0;
        background: var(--bg-primary);
        color: var(--text-primary);
        line-height: 1.6;
        transition: background-color 0.3s, color 0.3s;
        display: flex;
        flex-direction: column;
        min-height: 100vh;
    }

    /* ===== Header ===== */
    header {
        background: var(--header-bg);
        color: var(--text-inverse);
        padding: 0.5rem 1rem;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        z-index: 2000;
        display: flex;
        align-items: center;
        justify-content: space-between;
        height: 70px;
        box-shadow: var(--shadow);
        transition: background-color 0.3s;
    }

    .hamburger {
        background: transparent;
        border: 0;
        color: var(--text-inverse);
        font-size: 1.5rem;
        cursor: pointer;
        padding: 0.5rem;
        border-radius: 4px;
        transition: background-color 0.2s, transform 0.1s;
        position: relative;
        z-index: 3000;
        min-width: 44px;
        min-height: 44px;
        display: flex !important;
        /* Force display */
        align-items: center;
        justify-content: center;
    }

    .hamburger:hover {
        background-color: rgba(255, 255, 255, 0.1);
    }

    .hamburger:active {
        transform: scale(0.95);
    }

    .hamburger:focus {
        outline: 2px solid rgba(255, 255, 255, 0.5);
        outline-offset: 2px;
    }

    .logo-box {
        display: flex;
        align-items: center;
        gap: 15px;
        justify-content: center;
    }

    .logo-box img {
        height: 50px;
        width: auto;
        max-width: 50px;
        object-fit: contain;
        border-radius: 4px;
    }

    .logo-box h1 {
        margin: 0;
        font-size: 1.5rem;
        font-weight: 600;
        white-space: nowrap;
    }

    /* ===== Theme Selector ===== */
    .theme-selector {
        display: flex;
        align-items: center;
        gap: 8px;
        background: rgba(255, 255, 255, 0.1);
        padding: 5px 12px;
        border-radius: 20px;
        justify-content: space-between;
        width: 160px;
        /* Reduced width for only two options */
    }

    .theme-option {
        display: flex;
        flex-direction: column;
        align-items: center;
        background: transparent;
        border: none;
        color: var(--text-inverse);
        cursor: pointer;
        padding: 5px;
        border-radius: 4px;
        transition: all 0.2s;
        flex: 1;
    }

    .theme-option:hover {
        background-color: rgba(255, 255, 255, 0.1);
    }

    .theme-option.active {
        background-color: rgba(255, 255, 255, 0.2);
    }

    .theme-preview {
        width: 20px;
        height: 20px;
        border-radius: 50%;
        margin-bottom: 3px;
        border: 2px solid rgba(255, 255, 255, 0.5);
    }

    .light-theme {
        background: linear-gradient(135deg, #f5f9f7, #ffffff);
    }

    .dark-theme {
        background: linear-gradient(135deg, #1a1a1a, #2d2d2d);
    }

    /* ===== Profile Badge ===== */
    .profile-badge {
        display: flex;
        align-items: center;
        gap: 10px;
        background: rgba(255, 255, 255, 0.1);
        padding: 5px 12px;
        border-radius: 20px;
        cursor: pointer;
        transition: background-color 0.2s;
    }

    .profile-badge:hover {
        background: rgba(255, 255, 255, 0.2);
    }

    .profile-avatar {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: var(--blue);
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        color: white;
        font-size: 14px;
    }

    .profile-info {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
    }

    .profile-name {
        font-size: 14px;
        font-weight: 600;
        margin: 0;
        line-height: 1.2;
    }

    .profile-role {
        font-size: 11px;
        opacity: 0.8;
        margin: 0;
        line-height: 1;
    }

    .profile-dropdown {
        position: absolute;
        top: 100%;
        right: 0;
        margin-top: 5px;
        background: var(--bg-secondary);
        border-radius: 8px;
        box-shadow: var(--shadow-hover);
        min-width: 200px;
        z-index: 1000;
        display: none;
    }

    .profile-dropdown.active {
        display: block;
    }

    .profile-dropdown-item {
        padding: 10px 15px;
        display: flex;
        align-items: center;
        gap: 10px;
        cursor: pointer;
        transition: background-color 0.2s;
        border-bottom: 1px solid var(--border-color);
    }

    .profile-dropdown-item:last-child {
        border-bottom: none;
    }

    .profile-dropdown-item:hover {
        background: var(--bg-tertiary);
    }

    .profile-dropdown-item.danger {
        color: var(--danger);
    }

    /* ===== Main Layout - VERTICAL STACKING ===== */
    .horizontal-container {
        display: flex;
        flex-direction: column;
        flex: 1;
        margin-top: 70px;
    }

    /* ===== SIDEBAR FIX ===== */
    nav {
        position: fixed;
        top: 70px;
        bottom: 0;
        left: 0;
        width: 230px;
        background: var(--sidebar-bg);
        color: var(--text-inverse);
        padding: 12px;
        box-shadow: 2px 0 6px rgba(0, 0, 0, 0.08);
        overflow-y: auto;
        overflow-x: hidden;
        box-sizing: border-box;
        transition: transform 0.3s ease, width 0.3s ease;
        z-index: 1500;
        /* Increased z-index */
    }

    /* Collapsed state (desktop) */
    nav.collapsed {
        width: 68px;
    }

    nav.collapsed .label {
        display: none;
    }

    nav.collapsed .sidebar-logo .small {
        display: none;
    }

    nav.collapsed li {
        justify-content: center;
        padding: 12px 6px;
    }

    /* Reduce sidebar logo size */
    .sidebar-logo {
        display: flex;
        align-items: center;
        padding: 15px 10px;
        /* Reduced padding */
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        margin-bottom: 10px;
    }

    .sidebar-logo img {
        height: 40px;
        /* Reduced from 50px */
        width: auto;
        object-fit: contain;
        margin-right: 10px;
    }

    .sidebar-logo-text {
        font-size: 1rem;
        /* Reduced from 1.2rem */
        font-weight: bold;
        color: white;
    }

    /* Update the sidebar button styles to match the cashier portal */
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
        width: 100%;
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
        font-size: 0.7rem;
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

    /* Tooltip styles */
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


    /* Collapsed sidebar adjustments */
    #sidebar.collapsed {
        width: 70px;
    }

    #sidebar.collapsed .sidebar-logo {
        padding: 12px 8px;
        justify-content: center;
    }

    #sidebar.collapsed .sidebar-logo img {
        height: 40px;
    }

    #sidebar.collapsed .sidebar-logo-text {
        display: none;
    }

    #sidebar.collapsed button {
        padding: 0.8rem 0.5rem;
        justify-content: center;
    }

    #sidebar.collapsed button .button-text {
        display: none;
    }

    #sidebar.collapsed button .button-icon {
        font-size: 1rem;
        margin-right: 0;
    }

    #sidebar.collapsed button.active::before {
        display: none;
    }

    #sidebar.collapsed button#logoutBtnSidebar {
        padding: 0.8rem 0.5rem;
        justify-content: center;
    }

    #sidebar.collapsed button#logoutBtnSidebar .button-text {
        display: none;
    }

    /* Logout button compact style */
    #sidebar button#logoutBtn {
        margin-top: auto;
        background: #c0392b;
        padding: 0.7rem 1rem;
        /* Reduced padding */
    }

    #sidebar.collapsed button#logoutBtn {
        padding: 0.7rem 0.5rem;
    }

    /* Main content adjustment */
    main.sidebar-collapsed {
        padding-left: 80px;
        /* Reduced from 90px */
    }

    /* Mobile responsiveness */
    @media (max-width: 768px) {
        #sidebar {
            width: 220px;
            /* Reduced for mobile */
        }

        .sidebar-logo {
            padding: 12px 10px;
        }

        .sidebar-logo img {
            height: 35px;
        }

        .sidebar-logo-text {
            font-size: 0.9rem;
        }

        #sidebar button {
            padding: 0.7rem 0.8rem;
            font-size: 0.8rem;
        }
    }

    /* Mobile menu overlay */
    #mobile-menu-overlay {
        display: none;
    }

    /* ===== RESPONSIVE: MOBILE ===== */
    @media (max-width: 760px) {

        /* Hide sidebar by default on mobile */
        nav {
            transform: translateX(-100%);
            width: 250px;
            /* Slightly wider on mobile */
            z-index: 2500;
            /* Above everything on mobile */
        }

        /* Show sidebar when mobile-open class is added */
        nav.mobile-open {
            transform: translateX(0);
            box-shadow: 4px 0 20px rgba(0, 0, 0, 0.3);
        }

        /* Main content full width on mobile */
        .main {
            margin-left: 0 !important;
            padding: 80px 10px 30px;
        }

        /* Show overlay */
        #mobile-menu-overlay {
            display: block;
        }

        /* Ensure hamburger is visible */
        .hamburger {
            display: block;
            z-index: 3000;
        }
    }

    /* ===== HAMBURGER BUTTON STYLES ===== */
    .hamburger {
        background: transparent;
        border: 0;
        color: var(--text-inverse);
        font-size: 1.5rem;
        cursor: pointer;
        padding: 0.5rem;
        border-radius: 4px;
        transition: background-color 0.2s, transform 0.1s;
        position: relative;
        z-index: 3000;
        min-width: 44px;
        /* Better touch target */
        min-height: 44px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .hamburger:hover {
        background-color: rgba(255, 255, 255, 0.1);
    }

    .hamburger:active {
        transform: scale(0.95);
    }

    /* Visual feedback for hamburger state */
    .hamburger.active {
        background-color: rgba(255, 255, 255, 0.2);
    }

    /* ===== MAIN CONTENT ADJUSTMENT ===== */
    .main {
        margin-left: 250px;
        padding: 80px 15px 40px;
        transition: margin-left 0.3s ease;
    }

    /* When sidebar is collapsed on desktop */
    nav.collapsed~.horizontal-container .main {
        margin-left: 88px;
        /* 68px sidebar + 20px padding */
    }

    /* ===== SMOOTH TRANSITIONS ===== */
    * {
        /* Ensure smooth transitions for all interactive elements */
        transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
    }

    /* ===== ACCESSIBILITY IMPROVEMENTS ===== */
    .hamburger:focus {
        outline: 2px solid rgba(255, 255, 255, 0.5);
        outline-offset: 2px;
    }

    nav li:focus {
        outline: 2px solid rgba(255, 255, 255, 0.3);
        outline-offset: -2px;
    }

    /* ===== SCROLLBAR STYLING ===== */
    nav::-webkit-scrollbar {
        width: 6px;
    }

    nav::-webkit-scrollbar-track {
        background: rgba(255, 255, 255, 0.05);
    }

    nav::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.2);
        border-radius: 3px;
    }

    nav::-webkit-scrollbar-thumb:hover {
        background: rgba(255, 255, 255, 0.3);
    }

    /* ===== Main Content ===== */
    .main {
        margin-left: 250px;
        padding: 80px 15px 40px;
        transition: margin-left 0.3s;
    }

    nav.collapsed~.horizontal-container .main {
        margin-left: 80px;
    }

    nav.collapsed .label {
        display: none;
    }

    nav.collapsed li {
        justify-content: center;
    }

    nav.collapsed #logoutBtn {
        text-align: center;
        font-size: 1.2rem;
    }

    nav li {
        white-space: nowrap;
    }

    /* ===== Sections ===== */
    .hidden {
        display: none !important;
    }

    section {
        margin-bottom: 1.5rem;
        flex: 1;
        display: flex;
        flex-direction: column;
        width: 100%;
    }

    h2 {
        color: var(--text-primary);
        margin: 0 0 1.5rem;
        font-size: 1.8rem;
        border-bottom: 2px solid var(--border-color);
        padding-bottom: 0.5rem;
    }

    h3 {
        color: var(--text-primary);
        margin: 1.5rem 0 1rem;
        font-size: 1.3rem;
    }

    /* ===== Stats Section ===== */
    .stats {
        display: flex;
        flex-direction: row;
        /* Changed from column to row */
        flex-wrap: wrap;
        gap: 1rem;
        margin-bottom: 1.5rem;
        justify-content: flex-start;
        align-items: stretch;
    }

    .stat-card {
        background: var(--bg-secondary);
        padding: 1rem;
        border-radius: 8px;
        box-shadow: var(--shadow);
        text-align: center;
        border-left: 6px solid var(--green);
        transition: transform 0.3s, box-shadow 0.3s;
        min-height: 140px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        overflow: hidden;
        flex: 0 0 calc(20% - 0.8rem);
        /* 5 cards per row */
        min-width: 180px;
        max-width: 220px;
    }


    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-hover);
    }

    .stat-number {
        font-size: 1.8rem;
        font-weight: 700;
        color: var(--green);
        margin-bottom: 0.5rem;
        word-wrap: break-word;
        overflow-wrap: break-word;
        hyphens: auto;
        line-height: 1.2;
        display: flex;
        flex-direction: column;
        align-items: center;
    }

    .stat-label {
        color: var(--text-secondary);
        font-size: 0.9rem;
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-top: auto;
    }

    .stat-card.total-funds {
        border-left-color: var(--success);
    }

    .stat-card.total-funds .stat-number {
        color: var(--success);
    }

    /* =====STAT CARD RESPONSIVE TEXT=====*/
    .stat-number.short-number {
        font-size: 2rem;
    }

    .stat-number.medium-number {
        font-size: 1.6rem;
    }

    .stat-number.long-number {
        font-size: 1.4rem;
    }

    .stat-number.very-long-number {
        font-size: 1.2rem;
    }

    .stat-number.extra-long-number {
        font-size: 1rem;
    }

    /* Currency formatting */
    .stat-number .currency {
        font-size: 0.6em;
        opacity: 0.8;
    }

    .stat-number .digit-group {
        display: block;
    }

    /* For larger screens, wrap horizontally */
    /* For larger screens */
    @media (min-width: 1400px) {
        .stat-card {
            flex: 0 0 calc(20% - 0.8rem);
            /* 5 cards per row on large screens */
        }
    }

    /* For medium-large screens */
    @media (min-width: 1024px) and (max-width: 1399px) {
        .stat-card {
            flex: 0 0 calc(25% - 0.75rem);
            /* 4 cards per row */
        }
    }

    /* For medium screens */
    @media (min-width: 768px) and (max-width: 1023px) {
        .stat-card {
            flex: 0 0 calc(33.333% - 0.75rem);
            /* 3 cards per row */
        }
    }

    /* For small-medium screens */
    @media (min-width: 480px) and (max-width: 767px) {
        .stat-card {
            flex: 0 0 calc(50% - 0.5rem);
            /* 2 cards per row */
        }
    }

    /* For very small screens */
    @media (max-width: 479px) {
        .stats {
            flex-direction: column;
        }

        .stat-card {
            width: 100%;
            min-height: 100px;
            padding: 0.75rem;
        }
    }

    /* ===== Tables ===== */
    .table-container {
        background: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 8px;
        overflow: auto;
        box-shadow: var(--shadow);
        margin-bottom: 1rem;
        max-width: 100%;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
        min-width: 900px;
    }

    #staffTable {
        min-width: 1100px;
    }

    #staffTable td:last-child {
        min-width: 250px;
    }

    th,
    td {
        padding: 8px;
        border-bottom: 1px solid var(--border-color);
        text-align: left;
        white-space: nowrap;
    }

    td:nth-child(2),
    td:nth-child(5) {
        white-space: normal;
        max-width: 200px;
        word-wrap: break-word;
    }

    td:last-child {
        white-space: normal;
    }

    tr:hover {
        background: var(--bg-tertiary);
    }

    /* Status row colors */
    tr.pending {
        background: #fff9e6;
    }

    tr.forwarded {
        background: #cce7ff;
    }

    tr.accepted {
        background: #d4edda;
    }

    tr.dispatched {
        background: #a7e9a7;
    }

    tr.active {
        background: #d1ecf1;
    }

    tr.returned {
        background: #ffeaa7;
    }

    tr.rejected {
        background: #f8d7da;
    }

    /* Role badges */
    .role-badge {
        display: inline-block;
        padding: 3px 8px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
    }

    .role-badge.admin {
        background: var(--danger);
        color: white;
    }

    .role-badge.cashier {
        background: var(--info);
        color: white;
    }

    .role-badge.accountant {
        background: var(--success);
        color: white;
    }

    .role-badge.loanee {
        background: var(--warning);
        color: white;
    }

    /* ===== Cards & Forms ===== */
    .account-card {
        background: var(--bg-secondary);
        border: 1px solid var(--border-color);
        padding: 1rem;
        border-radius: 8px;
        margin-bottom: 1rem;
        box-shadow: var(--shadow);
        transition: transform 0.2s, box-shadow 0.2s;
        position: relative;
        width: 100%;
    }

    .account-card:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-hover);
    }

    input,
    select,
    textarea {
        padding: 10px;
        border-radius: 6px;
        border: 1px solid var(--border-color);
        font-size: 14px;
        background: var(--bg-secondary);
        color: var(--text-primary);
        transition: border-color 0.2s, box-shadow 0.2s;
        width: 100%;
    }

    input:focus,
    select:focus,
    textarea:focus {
        border-color: var(--blue);
        box-shadow: 0 0 0 3px rgba(58, 107, 154, 0.1);
    }

    button {
        padding: 10px 16px;
        border-radius: 6px;
        border: 0;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    button:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    }

    button:active {
        transform: translateY(0);
    }

    button:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none;
    }

    .btn-primary {
        background: var(--blue);
        color: var(--text-inverse);
    }

    .btn-success {
        background: var(--success);
        color: var(--text-inverse);
    }

    .btn-danger {
        background: var(--danger);
        color: var(--text-inverse);
    }

    .btn-secondary {
        background: var(--muted);
        color: var(--text-inverse);
    }

    .btn-warning {
        background: var(--warning);
        color: var(--text-inverse);
    }

    .btn-info {
        background: var(--info);
        color: var(--text-inverse);
    }

    .btn-outline {
        background: transparent;
        border: 1px solid var(--border-color);
        color: var(--text-primary);
    }

    .btn-outline:hover {
        background: var(--bg-tertiary);
    }

    .small {
        font-size: 13px;
        color: var(--text-secondary);
    }

    .row {
        display: flex;
        gap: 12px;
        align-items: center;
        flex-wrap: wrap;
        width: 100%;
    }

    .row>* {
        flex: 1;
        min-width: 150px;
    }

    label {
        font-weight: 600;
        margin-right: 6px;
        color: var(--text-primary);
    }

    .center {
        display: flex;
        align-items: center;
        justify-content: center;
    }

    @keyframes fadeOut {
        from {
            opacity: 1;
        }

        to {
            opacity: 0;
        }
    }

    /* ===== Password Requirements ===== */
    .password-requirements {
        font-size: 12px;
        color: var(--text-secondary);
        margin-top: 4px;
        margin-bottom: 8px;
    }

    .password-requirements ul {
        margin: 4px 0 0 20px;
        padding: 0;
    }

    .password-requirements li {
        margin-bottom: 2px;
    }

    .requirement-met {
        color: var(--success);
    }

    .requirement-unmet {
        color: var(--danger);
    }

    .password-strength {
        height: 5px;
        border-radius: 3px;
        margin-top: 5px;
        background-color: #e0e0e0;
    }

    .password-strength-bar {
        height: 100%;
        border-radius: 3px;
        width: 0;
        transition: width 0.3s, background-color 0.3s;
    }

    .strength-weak {
        background-color: var(--danger);
        width: 33%;
    }

    .strength-medium {
        background-color: var(--warning);
        width: 66%;
    }

    .strength-strong {
        background-color: var(--success);
        width: 100%;
    }

    /* ===== Loading Spinner ===== */
    .loading-spinner {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 3px solid rgba(255, 255, 255, .3);
        border-radius: 50%;
        border-top-color: #fff;
        animation: spin 1s ease-in-out infinite;
    }

    @keyframes spin {
        to {
            transform: rotate(360deg);
        }
    }

    .loading-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 9999;
    }

    .loading-content {
        background: var(--bg-secondary);
        padding: 2rem;
        border-radius: 8px;
        text-align: center;
        box-shadow: var(--shadow-hover);
    }

    /* ===== Modal ===== */
    .modal {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 3000;
        animation: fadeIn 0.3s;
    }

    .modal.show {
        display: flex;
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
        background: var(--bg-secondary);
        border-radius: 8px;
        width: 90%;
        max-width: 800px;
        max-height: 80vh;
        overflow: auto;
        box-shadow: var(--shadow-hover);
        animation: slideUp 0.3s;
    }

    @keyframes slideUp {
        from {
            transform: translateY(50px);
            opacity: 0;
        }

        to {
            transform: translateY(0);
            opacity: 1;
        }
    }

    .modal-header {
        padding: 1.5rem;
        border-bottom: 1px solid var(--border-color);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .modal-body {
        padding: 1.5rem;
    }

    .modal-footer {
        padding: 1rem 1.5rem;
        border-top: 1px solid var(--border-color);
        display: flex;
        justify-content: flex-end;
        gap: 1rem;
    }

    .close-modal {
        background: transparent;
        border: 0;
        font-size: 1.5rem;
        cursor: pointer;
        color: var(--text-secondary);
        padding: 0.5rem;
        border-radius: 4px;
        transition: background-color 0.2s;
    }

    .close-modal:hover {
        background: var(--bg-tertiary);
    }

    /* ===== Pagination ===== */
    .pagination {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 1rem;
        margin: 1.5rem 0;
    }

    .pagination button {
        padding: 8px 12px;
        min-width: 40px;
    }

    .pagination-info {
        color: var(--text-secondary);
        font-size: 0.9rem;
    }

    /* ===== Search ===== */
    .search-container {
        position: relative;
        flex: 1;
        max-width: 400px;
    }

    .search-input {
        width: 100%;
        padding-left: 40px;
    }

    .search-icon {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--text-secondary);
    }

    /* ===== Toast Notifications ===== */
    .toast-container {
        position: fixed;
        top: 80px;
        right: 20px;
        z-index: 4000;
    }

    .toast {
        background: var(--bg-secondary);
        border-left: 4px solid var(--success);
        padding: 1rem 1.5rem;
        border-radius: 4px;
        box-shadow: var(--shadow-hover);
        margin-bottom: 1rem;
        min-width: 300px;
        animation: slideInRight 0.3s;
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    @keyframes slideInRight {
        from {
            transform: translateX(100%);
            opacity: 0;
        }

        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    .toast.error {
        border-left-color: var(--danger);
    }

    .toast.warning {
        border-left-color: var(--warning);
    }

    .toast.info {
        border-left-color: var(--info);
    }

    /* ===== Real-time Update Indicator ===== */
    .update-indicator {
        position: fixed;
        top: 80px;
        left: 50%;
        transform: translateX(-50%);
        background: var(--info);
        color: var(--text-inverse);
        padding: 8px 16px;
        border-radius: 20px;
        font-size: 0.85rem;
        z-index: 1500;
        animation: slideDown 0.3s;
        display: none;
    }

    @keyframes slideDown {
        from {
            transform: translate(-50%, -100%);
            opacity: 0;
        }

        to {
            transform: translate(-50%, 0);
            opacity: 1;
        }
    }

    /* ===== Activity Feed ===== */
    .activity-feed {
        max-height: 300px;
        overflow-y: auto;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 1rem;
        background: var(--bg-tertiary);
    }

    .activity-item {
        padding: 0.75rem;
        border-bottom: 1px solid var(--border-color);
        display: flex;
        align-items: start;
        gap: 0.75rem;
    }

    .activity-item:last-child {
        border-bottom: none;
    }

    .activity-icon {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    .activity-icon.accepted {
        background: var(--success);
        color: white;
    }

    .activity-icon.dispatched {
        background: var(--info);
        color: white;
    }

    .activity-icon.rejected {
        background: var(--danger);
        color: white;
    }

    .activity-icon.returned {
        background: var(--warning);
        color: white;
    }

    .activity-icon.forwarded {
        background: var(--muted);
        color: white;
    }

    .activity-content {
        flex: 1;
    }

    .activity-title {
        font-weight: 600;
        margin-bottom: 0.25rem;
    }

    .activity-time {
        font-size: 0.75rem;
        color: var(--text-secondary);
    }

    /* ===== Error States ===== */
    .error-message {
        color: var(--danger);
        font-size: 0.85rem;
        margin-top: 0.25rem;
    }

    .input-error {
        border-color: var(--danger) !important;
    }

    /* ===== Success States ===== */
    .success-message {
        color: var(--success);
        font-size: 0.85rem;
        margin-top: 0.25rem;
    }

    /* ===== Utility Classes ===== */
    .text-center {
        text-align: center;
    }

    .text-right {
        text-align: right;
    }

    .mt-1 {
        margin-top: 0.5rem;
    }

    .mt-2 {
        margin-top: 1rem;
    }

    .mt-3 {
        margin-top: 1.5rem;
    }

    .mb-1 {
        margin-bottom: 0.5rem;
    }

    .mb-2 {
        margin-bottom: 1rem;
    }

    .mb-3 {
        margin-bottom: 1.5rem;
    }

    .p-1 {
        padding: 0.5rem;
    }

    .p-2 {
        padding: 1rem;
    }

    .p-3 {
        padding: 1.5rem;
    }

    /* Add this to your CSS */
    .no-select {
        user-select: none;
        -webkit-user-select: none;
        -moz-user-select: none;
        -ms-user-select: none;
    }

    /* Optimize table rendering */
    #staffTable {
        min-width: 1100px;
    }

    #staffTable td:last-child {
        min-width: 250px;
    }

    /* ===== Responsive Design ===== */
    @media (max-width: 760px) {
        nav {
            transform: translateX(-100%);
        }

        nav.mobile-open {
            transform: translateX(0);
        }

        .main {
            margin-left: 0;
            padding: 70px 10px 30px;
        }
    }

    @media (max-width: 480px) {
        .main {
            padding: 60px 8px 20px;
        }

        .stats {
            gap: 0.75rem;
        }

        .stat-card {
            padding: 0.75rem;
            min-height: 100px;
        }
    }

    .logo-box img {
        height: 40px;
        max-width: 40px;
    }

    .logo-box h1 {
        font-size: 1.2rem;
    }

    .theme-selector {
        width: 160px;
        /* Reduced width for only two options */
    }


    /* Screen reader announcements */
    .sr-only {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
        border: 0;
    }

    /* Import Users Modal Styles */
    .import-preview-table {
        max-height: 400px;
        overflow-y: auto;
        margin: 15px 0;
        border: 1px solid var(--border-color);
        border-radius: 6px;
    }

    .import-preview-table table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
    }

    .import-preview-table th {
        position: sticky;
        top: 0;
        background: var(--bg-tertiary);
        padding: 10px;
        border-bottom: 2px solid var(--border-color);
        font-weight: 600;
        text-align: left;
        z-index: 1;
    }

    .import-preview-table td {
        padding: 8px 10px;
        border-bottom: 1px solid var(--border-color);
    }

    .import-preview-table tr:hover {
        background: var(--bg-tertiary);
    }

    .import-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 15px;
        margin: 20px 0;
    }

    .import-stat-card {
        background: var(--bg-tertiary);
        padding: 15px;
        border-radius: 6px;
        text-align: center;
        border-left: 4px solid var(--info);
    }

    .import-stat-card.success {
        border-left-color: var(--success);
    }

    .import-stat-card.warning {
        border-left-color: var(--warning);
    }

    .import-stat-card.danger {
        border-left-color: var(--danger);
    }

    .import-stat-number {
        font-size: 2rem;
        font-weight: 700;
        margin-bottom: 5px;
    }

    .import-stat-label {
        font-size: 0.85rem;
        color: var(--text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .error-list {
        max-height: 200px;
        overflow-y: auto;
        background: var(--bg-tertiary);
        border-radius: 6px;
        padding: 15px;
        margin-top: 15px;
    }

    .error-item {
        padding: 8px;
        margin-bottom: 8px;
        background: var(--bg-secondary);
        border-left: 3px solid var(--danger);
        border-radius: 4px;
        font-size: 0.9rem;
    }

    .column-mapping {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
        margin: 20px 0;
    }

    .column-mapping label {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }

    .progress-bar {
        width: 100%;
        height: 30px;
        background: var(--bg-tertiary);
        border-radius: 15px;
        overflow: hidden;
        margin: 20px 0;
    }

    .progress-fill {
        height: 100%;
        background: linear-gradient(90deg, var(--success), var(--info));
        transition: width 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 600;
        font-size: 0.9rem;
    }
    </style>
</head>

<body>

    <!-- Screen reader announcements -->
    <div id="statusMessage" class="sr-only" aria-live="polite"></div>

    <!-- Toast container -->
    <div id="toastContainer" class="toast-container"></div>

    <!-- Real-time update indicator -->
    <div id="updateIndicator" class="update-indicator">
        🔄 Data updated from other portal
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
            <img src="logo.jpg" alt="Logo">
            <h1>Administrator Portal</h1>
        </div>

        <!-- MODIFIED: Theme selector with only light and dark options -->
        <div class="theme-selector" id="themeSelector">
            <button class="theme-option active" data-theme="light" aria-label="Light theme">
                <span class="theme-preview light-theme"></span>
                <span>Light</span>
            </button>
            <button class="theme-option" data-theme="dark" aria-label="Dark theme">
                <span class="theme-preview dark-theme"></span>
                <span>Dark</span>
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
                <div class="profile-dropdown-item danger" id="profileView">
                    👤 View Profile
                </div>
                <div class="profile-dropdown-item danger" id="profileSettings">
                    ⚙️ Account Settings
                </div>
                <div class="profile-dropdown-item danger" id="logoutBtn">
                    🚪 Logout
                </div>
            </div>
        </div>
    </header>

    <!-- HORIZONTAL CONTAINER -->
    <div class="horizontal-container">
        <!-- SIDEBAR NAVIGATION -->
        <nav id="sidebar" class="collapsed" role="navigation" aria-label="Admin navigation">
            <div class="sidebar-logo">
                <img src="logo.jpg" alt="logo">
                <div class="sidebar-logo-text">Admin Portal</div>
            </div>

            <button class="active tooltip" data-filter="dashboard" id="navDashboard">
                <span class="button-icon">📊</span>
                <span class="button-text">Dashboard</span>
                <span class="tooltiptext">Dashboard</span>
            </button>
            <button class="tooltip" data-filter="loans" id="navLoans">
                <span class="button-icon">💰</span>
                <span class="button-text">Loans Overview</span>
                <span class="tooltiptext">Loans Overview</span>

                <button class="apply-loan-btn" onclick="applyForLoan()">
                    <span class="button-icon">📝</span>
                    <span class="button-text">Apply for Loan</span>
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
                    <span class="button-icon">📋</span>
                    <span class="button-text">Activity Feed</span>
                    <span class="tooltiptext">Activity Feed</span>
                </button>
                <button class="tooltip" data-filter="settings" id="navSettings">
                    <span class="button-icon">⚙️</span>
                    <span class="button-text">Settings</span>
                    <span class="tooltiptext">Settings</span>
                </button>
                <button class="tooltip" data-filter="reports" id="navReports">
                    <span class="button-icon">📊</span>
                    <span class="button-text">Financial Reports</span>
                    <span class="tooltiptext">Financial Reports</span>
                </button>

                <button class="tooltip" id="logoutBtnSidebar" style="margin-top: auto; background: #c0392b;">
                    <span class="button-icon">🚪</span>
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
                                                $icon = '👤';
                                                break;
                                            case 'update_staff':
                                                $icon = '✏️';
                                                break;
                                            case 'delete_staff':
                                                $icon = '🗑️';
                                                break;
                                            case 'change_password':
                                                $icon = '🔐';
                                                break;
                                            case 'update_settings':
                                                $icon = '⚙️';
                                                break;
                                            case 'logout':
                                                $icon = '🚪';
                                                break;
                                            default:
                                                $icon = '📋';
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
                            <span class="search-icon">🔍</span>
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
                            ❌ Clear
                        </button>
                        <button id="refreshBtn" class="btn-secondary">
                            🔄 Refresh
                        </button>
                        <button id="exportBtn" class="btn-success">
                            📥 Export
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
                        <tbody></tbody>
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
                        <h2>📊 Transaction History</h2>
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
                        <button class="btn-primary" onclick="exportTransactionHistory()">📥 Export PDF</button>
                        <button class="btn-secondary" onclick="closeTransactionModal()">Close</button>
                    </div>
                </div>
            </div>

            <!-- User Management Section -->
            <section id="staffSection" class="hidden">
                <h2>User Management</h2>

                <!-- Import Users Section -->
                <div class="account-card" style="margin-bottom: 20px; border-left: 4px solid var(--info);">
                    <h3 style="margin-top: 0;">📥 Import Users</h3>
                    <p class="small" style="color: var(--text-secondary); margin-bottom: 15px;">
                        Import multiple users from PDF, Excel, or CSV files. Default password will be their ID number,
                        default role will be Loanee.
                    </p>
                    <div class="row mb-2">
                        <button id="importUsersBtn" class="btn-info">
                            📥 Import Users
                        </button>
                        <button id="downloadTemplateBtn" class="btn-outline">
                            📄 Download Template
                        </button>
                    </div>
                    <input type="file" id="importFileInput" accept=".pdf,.xlsx,.xls,.csv" style="display: none;">
                </div>

                <!-- Search and Filter Controls -->
                <div class="row mb-2" style="justify-content: space-between;">
                    <div class="row" style="flex: 1;">
                        <div class="search-container">
                            <span class="search-icon">🔍</span>
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
                            ❌ Clear
                        </button>
                        <button id="refreshUserBtn" class="btn-secondary">
                            🔄 Refresh
                        </button>
                        <button id="exportUserBtn" class="btn-success">
                            📥 Export
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
                        ❌ Cancel Edit
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
                            🔄 Refresh
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
                            📥 Download Backup
                        </button>
                        <button onclick="restoreFromBackup()" class="btn-warning">
                            📤 Restore Full Backup
                        </button>

                        <input type="file" id="restoreFileInput" accept=".json" style="display: none;">
                    </div>
                </div>

                <div class="account-card">
                    <h3>Activity Monitoring</h3>
                    <div class="row mt-2">
                        <button id="viewLogsBtn" class="btn-primary">
                            📋 View Activity Logs
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
                <h2>📊 Financial Reports & Analytics</h2>

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

        </main>
    </div>

    <script>
    /* ===========================
   🌐 CONFIGURATION & GLOBALS
   =========================== */

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
    let lastLoanUpdate = Date.now();
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
           💰 UTILITY FUNCTIONS
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
            success: '✅',
            error: '❌',
            warning: '⚠️',
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

    /**
     * Improved API Request Handler for Admin Portal
     * 
     * Features:
     * - Automatic retry with exponential backoff
     * - Request deduplication
     * - Better error handling
     * - Request queuing for rate limiting
     * - CSRF token management
     */

    class APIRequestHandler {
        constructor(baseUrl) {
            this.baseUrl = baseUrl || this.getApiBaseUrl();
            this.pendingRequests = new Map();
            this.requestQueue = [];
            this.isProcessingQueue = false;
            this.csrfToken = this.getCsrfToken();

            // Refresh token periodically to handle session changes
            setInterval(() => {
                this.csrfToken = this.getCsrfToken();
            }, 300000); // Every 5 minutes
        }

        getApiBaseUrl() {
            const hostname = window.location.hostname;
            const pathname = window.location.pathname;
            const basePath = pathname.substring(0, pathname.lastIndexOf('/'));

            if (hostname === 'localhost' || hostname === '127.0.0.1') {
                return `http://localhost/loan-system/api`;
            }
            return `${window.location.protocol}//${hostname}${basePath}/api`;
        }

        getCsrfToken() {
            const element = document.getElementById('csrf-token-data');
            return element ? element.dataset.csrfToken : '';
        }

        updateCsrfToken(newToken) {
            this.csrfToken = newToken;
            const element = document.getElementById('csrf-token-data');
            if (element) {
                element.dataset.csrfToken = newToken;
            }
        }

        /**
         * Make API request with automatic retry and deduplication
         */
        async request(endpoint, options = {}) {
            const {
                method = 'GET',
                    data = null,
                    retries = 2,
                    timeout = 30000,
                    deduplicate = true
            } = options;

            // Generate request key for deduplication
            const requestKey = `${method}:${endpoint}:${JSON.stringify(data)}`;

            // Check if identical request is already pending
            if (deduplicate && this.pendingRequests.has(requestKey)) {
                return this.pendingRequests.get(requestKey);
            }

            // Create request promise
            const requestPromise = this._makeRequest(endpoint, method, data, retries, timeout);

            // Store pending request
            if (deduplicate) {
                this.pendingRequests.set(requestKey, requestPromise);

                // Remove from pending after completion
                requestPromise.finally(() => {
                    this.pendingRequests.delete(requestKey);
                });
            }

            return requestPromise;
        }

        async _makeRequest(endpoint, method, data, retriesLeft, timeout) {
            const cleanEndpoint = endpoint.replace(/^\/+/, '');
            const url = `${this.baseUrl}/${cleanEndpoint}`.replace(/([^:]\/)\/+/g, "$1");

            const headers = {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            };

            const requestOptions = {
                method,
                headers,
                credentials: 'include',
                mode: 'cors',
                signal: AbortSignal.timeout(timeout)
            };

            if (data && method !== 'GET') {
                // Add CSRF token to request data
                if (!data.csrf_token && this.csrfToken) {
                    data.csrf_token = this.csrfToken;
                }
                requestOptions.body = JSON.stringify(data);
            }

            try {
                const response = await fetch(url, requestOptions);

                // Read response body ONCE
                const responseText = await response.text();

                // Handle authentication errors
                if (response.status === 401 || response.status === 403) {
                    // Check if session expired (redirected to login page)
                    if (responseText.trim().startsWith('<!DOCTYPE')) {
                        this.handleSessionExpired();
                        throw new Error('Session expired');
                    }
                }

                // Parse JSON from the already read text
                let result;

                try {
                    result = JSON.parse(responseText);
                } catch (parseError) {
                    // Retry if we have retries left
                    if (retriesLeft > 0) {
                        await this.delay((3 - retriesLeft) * 1000);
                        return this._makeRequest(endpoint, method, data, retriesLeft - 1, timeout);
                    }
                    throw new Error(`Invalid JSON response: ${responseText.substring(0, 100)}`);
                }

                // Handle HTTP errors
                if (!response.ok) {
                    // Retry on server errors
                    if (response.status >= 500 && retriesLeft > 0) {
                        await this.delay((3 - retriesLeft) * 1000);
                        return this._makeRequest(endpoint, method, data, retriesLeft - 1, timeout);
                    }
                    throw new Error(result.message || `HTTP ${response.status}`);
                }

                // Update CSRF token if provided
                if (result.csrf_token) {
                    this.updateCsrfToken(result.csrf_token);
                }

                return result;

            } catch (error) {
                // Handle network errors
                if (error.name === 'AbortError') {
                    throw new Error('Request timeout');
                }

                // Retry on network errors
                if (retriesLeft > 0 && !error.message.includes('Session expired')) {
                    await this.delay((3 - retriesLeft) * 1000);
                    return this._makeRequest(endpoint, method, data, retriesLeft - 1, timeout);
                }

                throw error;
            }

            if (!this.csrfToken) {
                // Try to get the token again
                this.csrfToken = this.getCsrfToken();

                if (!this.csrfToken) {
                    throw new Error('CSRF token not available');
                }
            }

        } catch (error) {
            if (error.message.includes('CSRF')) {
                // Refresh the page to get a new token
                window.location.reload();
            }

            // Add CSRF token to request
            if (data && method !== 'GET') {
                if (!data.csrf_token && this.csrfToken) {
                    data.csrf_token = this.csrfToken;
                }
                requestOptions.body = JSON.stringify(data);
            }
        }

        handleSessionExpired() {
            // Show notification
            if (window.showToast) {
                window.showToast('Session expired. Redirecting to login...', 'error');
            }

            // Redirect after delay
            setTimeout(() => {
                window.location.href = 'index.php?error=session_expired';
            }, 2000);
        }

        delay(ms) {
            return new Promise(resolve => setTimeout(resolve, ms));
        }

        /**
         * Convenience methods
         */
        get(endpoint, options = {}) {
            return this.request(endpoint, {
                ...options,
                method: 'GET'
            });
        }

        post(endpoint, data, options = {}) {
            return this.request(endpoint, {
                ...options,
                method: 'POST',
                data
            });
        }

        put(endpoint, data, options = {}) {
            return this.request(endpoint, {
                ...options,
                method: 'PUT',
                data
            });
        }

        delete(endpoint, options = {}) {
            return this.request(endpoint, {
                ...options,
                method: 'DELETE'
            });
        }
    }

    // Create global instance
    const apiHandler = new APIRequestHandler();

    // Backward compatible function
    async function apiRequestWithErrorHandling(endpoint, method = 'GET', data = null, retries = 2) {
        try {
            // Validate required fields for POST requests
            if (method === 'POST' && data) {
                // Check for required fields based on action
                if (data.action === 'toggle_status') {
                    if (!data.user_id) {
                        throw new Error('User ID is required for status toggle');
                    }
                    if (data.is_active === undefined || data.is_active === null) {
                        throw new Error('Status value is required');
                    }
                    if (!data.csrf_token) {
                        throw new Error('CSRF token is required');
                    }
                }
            }

            // Make the actual request
            const response = await apiRequest(endpoint, method, data, retries);

            return response;

        } catch (error) {
            console.error(`API Request Error [${method} ${endpoint}]:`, error);

            // Provide user-friendly error messages
            let userMessage = error.message;

            if (error.message.includes('CSRF')) {
                userMessage = 'Security token expired. Please refresh the page and try again.';
            } else if (error.message.includes('Network')) {
                userMessage = 'Network error. Please check your connection and try again.';
            } else if (error.message.includes('timeout')) {
                userMessage = 'Request timeout. The server is taking too long to respond.';
            } else if (error.message.includes('401') || error.message.includes('403')) {
                userMessage = 'Session expired. Please log in again.';
            }

            throw new Error(userMessage);
        }
    }


    // Export for use
    window.APIRequestHandler = APIRequestHandler;
    window.apiHandler = apiHandler;
    window.apiRequestSafe = apiRequestWithErrorHandling;

    /* ===========================
           📋 NAVIGATION & UI CONTROL
           =========================== */

    function showSection(name) {
        document.querySelectorAll('main section').forEach(s => s.classList.add('hidden'));
        const section = document.getElementById(name + 'Section');
        if (section) {
            section.classList.remove('hidden');
        }

        // Update active nav item
        document.querySelectorAll('#sidebar li').forEach(li => {
            li.classList.remove('active');
            li.removeAttribute('aria-current');
        });

        const activeNav = document.getElementById('nav' + name.charAt(0).toUpperCase() + name.slice(1));
        if (activeNav) {
            activeNav.classList.add('active');
            activeNav.setAttribute('aria-current', 'page');
        }

        // Close mobile menu
        if (window.innerWidth <= 760 && elements.sidebar) {
            elements.sidebar.classList.remove('mobile-open');
        }
    }

    //
    const applyLoanBtn = document.querySelector('.apply-loan-btn');
    if (applyLoanBtn) {
        applyLoanBtn.addEventListener('click', function(e) {
            e.preventDefault();
            applyForLoan();
        });
    }

    function updateThemeIcon() {
        if (elements.themeToggle) {
            elements.themeToggle.textContent = currentTheme === 'dark' ? '☀️' : '🌙';
        }
    }

    function initializeProfileBadge() {
        if (!elements.profileBadge || !elements.profileDropdown) return;

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
                // FIXED: Only show one confirmation dialog
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
                ⚙Account Settings
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
           📊 DATA LOADING FUNCTIONS
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
                            📊 View All Transactions (${transactionCount})
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

                            // FIXED: Only show one confirmation dialog
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

    // Function to show transaction history modal
    async function showTransactionHistory(accountId, accountName) {
        showLoading(true);

        try {
            const response = await apiRequest(`funds.php?id=${accountId}`, 'GET');

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
                    transactions.forEach(trans => {
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
                    <td style="font-size: 12px;">${escapeHtml(trans.reference || 'N/A')}</td>
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
                    transactions: transactions
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

    // Function to export transaction history as PDF
    function exportTransactionHistory() {
        if (!window.currentTransactionAccount) {
            showToast('No transaction data available', 'error');
            return;
        }

        const account = window.currentTransactionAccount;
        const {
            jsPDF
        } = window.jspdf;
        const doc = new jsPDF('l', 'mm', 'a4');

        const pageWidth = doc.internal.pageSize.getWidth();
        const pageHeight = doc.internal.pageSize.getHeight();
        let yPosition = 20;

        // Header
        doc.setFontSize(18);
        doc.setFont('helvetica', 'bold');
        doc.text('Transaction History Report', pageWidth / 2, yPosition, {
            align: 'center'
        });

        yPosition += 10;
        doc.setFontSize(14);
        doc.text(account.name, pageWidth / 2, yPosition, {
            align: 'center'
        });

        yPosition += 15;

        // Summary section
        doc.setFontSize(12);
        doc.setFont('helvetica', 'normal');
        doc.text(`Current Balance: KES ${formatCurrency(account.balance)}`, 20, yPosition);
        doc.text(`Total Transactions: ${account.transactions.length}`, pageWidth - 80, yPosition);

        yPosition += 15;

        // Table headers
        const headers = ['Date & Time', 'Type', 'Amount', 'Reference', 'Recorded By'];
        const columnWidths = [45, 30, 35, 80, 45];

        doc.setFont('helvetica', 'bold');
        doc.setFontSize(10);

        let xPos = 15;
        headers.forEach((header, index) => {
            doc.text(header, xPos, yPosition);
            xPos += columnWidths[index];
        });

        yPosition += 7;
        doc.setLineWidth(0.5);
        doc.line(15, yPosition, pageWidth - 15, yPosition);
        yPosition += 5;

        // Table rows
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(9);

        account.transactions.forEach(trans => {
            if (yPosition > pageHeight - 20) {
                doc.addPage();
                yPosition = 20;

                // Repeat headers
                xPos = 15;
                doc.setFont('helvetica', 'bold');
                doc.setFontSize(10);
                headers.forEach((header, index) => {
                    doc.text(header, xPos, yPosition);
                    xPos += columnWidths[index];
                });
                yPosition += 7;
                doc.line(15, yPosition, pageWidth - 15, yPosition);
                yPosition += 5;
                doc.setFont('helvetica', 'normal');
                doc.setFontSize(9);
            }

            xPos = 15;
            const rowData = [
                new Date(trans.created_at).toLocaleString('en-US', {
                    month: 'short',
                    day: 'numeric',
                    year: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit'
                }),
                trans.transaction_type.toUpperCase(),
                `${trans.transaction_type === 'withdrawal' ? '-' : '+'}${formatCurrency(trans.amount)}`,
                (trans.reference || 'N/A').substring(0, 40),
                trans.created_by_name || 'System'
            ];

            rowData.forEach((data, index) => {
                doc.text(data, xPos, yPosition);
                xPos += columnWidths[index];
            });

            yPosition += 6;
        });

        // Footer
        const pageCount = doc.internal.getNumberOfPages();
        for (let i = 1; i <= pageCount; i++) {
            doc.setPage(i);
            doc.setFontSize(10);
            doc.setFont('helvetica', 'normal');
            doc.text(`Page ${i} of ${pageCount}`, pageWidth / 2, pageHeight - 10, {
                align: 'center'
            });
            doc.text(`Generated on ${new Date().toLocaleString()}`, pageWidth / 2, pageHeight - 5, {
                align: 'center'
            });
        }

        doc.save(
            `Transaction_History_${account.name.replace(/\s+/g, '_')}_${new Date().toISOString().split('T')[0]}.pdf`
        );
        showToast('Transaction history exported successfully!', 'success');
    }

    /**
     * Improved User Management with Lazy Loading
     * 
     * Features:
     * - Virtual scrolling for large datasets
     * - Progressive loading
     * - Smart caching
     * - Search optimization
     */

    /**
     * FIXED: Remove duplicate createUserRow definition
     * Replace the UserManager class section (around line 3500-3700) with this:
     */

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

                const response = await apiRequest(`users.php?${params}`, 'GET');

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
                console.error('Error loading users:', error);
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
         * FIXED: Single definition only
         */
        createUserRow(user, index) {
            const tr = document.createElement('tr');
            tr.className = 'no-select';
            tr.dataset.userId = user.user_id;

            // Ensure is_active is properly parsed
            const isActive = parseInt(user.is_active) === 1;
            const statusValue = isActive ? '1' : '0';

            // Escape HTML to prevent XSS
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
                            style="font-size:12px;padding:6px 10px;"
                            title="Edit this user">
                        ✏️ Edit
                    </button>
                    <button class="btn-${isActive ? 'warning' : 'success'} toggle-status-btn" 
                            data-user-id="${user.user_id}" 
                            data-current-status="${statusValue}" 
                            style="font-size:12px;padding:6px 10px;"
                            title="${isActive ? 'Deactivate' : 'Activate'} this user">
                        ${isActive ? '🔒 Deactivate' : '🔓 Activate'}
                    </button>
                    <button class="btn-danger remove-user-btn" 
                            data-user-id="${user.user_id}" 
                            style="font-size:12px;padding:6px 10px;"
                            title="Remove this user">
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

            // Use the global function
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
    async function toggleUserStatus(userId, currentStatus) {
        console.log('Toggle status called:', {
            userId,
            currentStatus
        });

        // Validate inputs
        if (!userId) {
            showToast('Invalid user ID', 'error');
            return;
        }

        // Convert status to integer for comparison
        const statusInt = parseInt(currentStatus);
        const actionText = statusInt === 1 ? 'deactivate' : 'activate';

        if (!confirm(`Are you sure you want to ${actionText} this user?`)) {
            return;
        }

        try {
            showLoading(true);

            const newStatus = statusInt === 1 ? 0 : 1;

            // Get CSRF token with multiple fallback methods
            const csrfToken =
                document.querySelector('#csrf-token-data')?.dataset.csrfToken ||
                document.querySelector('input[name="csrf_token"]')?.value ||
                document.querySelector('meta[name="csrf-token"]')?.content ||
                '';

            if (!csrfToken) {
                throw new Error('CSRF token not found. Please refresh the page.');
            }

            console.log('Sending toggle request:', {
                userId,
                newStatus
            });

            // Use POST instead of PUT for better compatibility
            const response = await apiRequest('users.php', 'POST', {
                action: 'toggle_status',
                user_id: parseInt(userId),
                is_active: newStatus,
                csrf_token: csrfToken
            });

            console.log('Toggle response:', response);

            if (response.success) {
                showToast(`User ${actionText}d successfully`, 'success');

                // Clear cache and refresh
                usersCache = null;
                usersCacheTime = 0;

                if (typeof userManager !== 'undefined' && userManager.clearCache) {
                    userManager.clearCache();
                }

                await renderUsers();
            } else {
                throw new Error(response.message || 'Failed to update user status');
            }
        } catch (error) {
            console.error('Toggle status error:', error);
            showToast('Failed to update user status: ' + error.message, 'error');
        } finally {
            showLoading(false);
        }
    }

    // Make sure it's globally accessible
    window.toggleUserStatus = toggleUserStatus;

    /**
     * Populate edit form with user data
     */
    function populateUserEditForm(user) {
        if (!user) {
            showToast('User data not found', 'error');
            return;
        }

        // Populate form fields
        document.getElementById('staffName').value = user.full_name || '';
        document.getElementById('staffPf').value = user.pf_number || '';
        document.getElementById('staffIdNumber').value = user.id_number || '';
        document.getElementById('staffEmail').value = user.email || '';
        document.getElementById('staffRole').value = user.role || 'loanee';

        // Clear password field and make it optional for editing
        const passwordField = document.getElementById('staffPwd');
        if (passwordField) {
            passwordField.value = '';
            passwordField.removeAttribute('required');
            passwordField.placeholder = 'Leave blank to keep current password';
        }

        // Store editing user ID globally
        window.editingUserId = user.user_id;
        editingUserId = user.user_id;

        // Update form button text
        const saveBtn = document.getElementById('saveStaffBtn');
        if (saveBtn) {
            saveBtn.innerHTML = '💾 Update User';
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
     * Handle edit user click
     */
    async function handleEditUser(userId) {
        try {
            showLoading(true);

            // Get user data from API
            const response = await apiRequest(`users.php?user_id=${userId}`, 'GET');

            if (response.success && response.data) {
                populateUserEditForm(response.data);
            } else {
                showToast(response.message || 'Failed to load user data', 'error');
            }
        } catch (error) {
            console.error('Edit user error:', error);
            showToast('Failed to load user data: ' + error.message, 'error');
        } finally {
            showLoading(false);
        }
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

            const response = await apiRequest(`users.php?user_id=${userId}`, 'PUT', {
                is_active: newStatus,
                csrf_token: csrfToken
            });

            if (response.success) {
                showToast(`User ${actionText}d successfully`, 'success');

                // Clear cache and refresh user list
                usersCache = null;
                if (typeof userManager !== 'undefined' && userManager.clearCache) {
                    userManager.clearCache();
                }
                await renderUsers();
            } else {
                showToast(response.message || 'Failed to update user status', 'error');
            }
        } catch (error) {
            console.error('Toggle status error:', error);
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

            const response = await apiRequest(`users.php?user_id=${userId}`, 'DELETE', {
                csrf_token: csrfToken
            });

            if (response.success) {
                showToast('User removed successfully', 'success');

                // Clear cache and refresh user list
                usersCache = null;
                if (typeof userManager !== 'undefined' && userManager.clearCache) {
                    userManager.clearCache();
                }
                await renderUsers();
            } else {
                showToast(response.message || 'Failed to remove user', 'error');
            }
        } catch (error) {
            console.error('Remove user error:', error);
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
        if (typeof resetPasswordValidation === 'function') {
            resetPasswordValidation();
        }

        showToast('Edit cancelled', 'info');
    }

    /**
     * Initialize user management event listeners
     * IMPORTANT: Call this AFTER DOM is loaded
     */
    function initializeUserManagementEvents() {
        console.log('🔧 Initializing user management event listeners...');

        // Event delegation for dynamically added buttons
        const staffTableBody = document.querySelector('#staffTable tbody');

        if (staffTableBody) {
            // Remove old listeners by cloning (prevents duplicate listeners)
            const newTbody = staffTableBody.cloneNode(true);
            staffTableBody.parentNode.replaceChild(newTbody, staffTableBody);

            // Single event listener using delegation
            newTbody.addEventListener('click', async function(e) {
                // Find the button that was clicked
                const button = e.target.closest('button');
                if (!button) return;

                e.preventDefault();
                e.stopPropagation();

                const userId = button.dataset.userId;
                if (!userId) {
                    console.error('No userId found on button');
                    return;
                }

                console.log('Button clicked:', button.className, 'User ID:', userId);

                // Handle different button types
                if (button.classList.contains('edit-user-btn')) {
                    await handleEditUser(userId);
                } else if (button.classList.contains('toggle-status-btn')) {
                    const currentStatus = button.dataset.currentStatus;
                    await toggleUserStatus(userId, currentStatus);
                } else if (button.classList.contains('remove-user-btn')) {
                    await handleRemoveUser(userId);
                }
            });

            console.log('✅ User management events attached successfully');
        } else {
            console.error('❌ Staff table body not found');
        }

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

        const refreshUserBtn = document.getElementById('refreshUserBtn');
        if (refreshUserBtn) {
            refreshUserBtn.onclick = async () => {
                usersCache = null;
                await renderUsers();
                showToast('User list refreshed', 'success');
            };
        }

        // Search with debounce
        const userSearchInput = document.getElementById('userSearchInput');
        if (userSearchInput) {
            userSearchInput.addEventListener('input', debounce(async () => {
                usersCache = null;
                await renderUsers();
            }, 300));
        }

        console.log('✅ All user management events initialized');
    }

    // Ensure global availability
    window.initializeUserManagementEvents = initializeUserManagementEvents;

    /**
     * Initialize with retry mechanism
     */
    function initUserManagementWithRetry(maxRetries = 5, delay = 200) {
        let attempts = 0;

        const tryInit = () => {
            attempts++;
            console.log(`🔄 Attempt ${attempts} to initialize user management...`);

            const staffTable = document.getElementById('staffTable');

            if (staffTable && staffTable.querySelector('tbody')) {
                initializeUserManagementEvents();
                return true;
            }

            if (attempts < maxRetries) {
                console.log(`⏳ Table not ready, retrying in ${delay}ms...`);
                setTimeout(tryInit, delay);
            } else {
                console.error('❌ Failed to initialize user management after', maxRetries, 'attempts');
            }

            return false;
        };

        tryInit();
    }

    // Auto-initialize
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            console.log('📄 DOM loaded - initializing user management');
            initUserManagementWithRetry();
        });
    } else {
        console.log('📄 DOM already loaded - initializing user management immediately');
        initUserManagementWithRetry();
    }


    const refreshUserBtn = document.getElementById('refreshUserBtn');
    if (refreshUserBtn) {
        refreshUserBtn.addEventListener('click', async () => {
            usersCache = null;
            await renderUsers();
            showToast('User list refreshed', 'success');
        });
    }

    // Export button
    const exportUserBtn = document.getElementById('exportUserBtn');
    if (exportUserBtn) {
        exportUserBtn.addEventListener('click', async () => {
            const roleFilter = document.getElementById('filterUserRole')?.value || '';
            const statusFilter = document.getElementById('filterUserStatus')?.value || '';
            const searchTerm = document.getElementById('userSearchInput')?.value.trim() || '';

            let queryParams = '';
            if (roleFilter) queryParams += `&role=${encodeURIComponent(roleFilter)}`;
            if (statusFilter) queryParams += `&status=${encodeURIComponent(statusFilter)}`;
            if (searchTerm) queryParams += `&search=${encodeURIComponent(searchTerm)}`;

            try {
                const response = await apiRequest(`users.php?${queryParams.substring(1)}`, 'GET');
                if (response.success && response.data) {
                    const exportData = response.data.map(user => ({
                        Name: user.full_name || '',
                        'PF Number': user.pf_number || '',
                        'ID Number': user.id_number || '',
                        Email: user.email || '',
                        Role: user.role || '',
                        Status: user.is_active ? 'Active' : 'Inactive',
                        'Created Date': user.created_at || ''
                    }));
                    exportToCSV(exportData,
                        `users_export_${new Date().toISOString().split('T')[0]}.csv`);
                } else {
                    showToast('No data to export', 'warning');
                }
            } catch (error) {
                showToast('Error exporting users', 'error');
            }
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


    // Make functions globally accessible
    window.populateUserEditForm = populateUserEditForm;
    window.handleEditUser = handleEditUser;
    window.handleToggleUserStatus = handleToggleUserStatus;
    window.handleRemoveUser = handleRemoveUser;
    window.cancelEditMode = cancelEditMode;
    window.initializeUserManagementEvents = initializeUserManagementEvents;

    /* ===========================
           📊 DATA LOADING FUNCTIONS
           =========================== */

    function renderLoans() {
        const tbody = document.querySelector('#loansTable tbody');
        if (!tbody) return;

        apiRequest('loan_applications.php', 'GET')
            .then(response => {
                if (response.success && response.data && response.data.length > 0) {
                    let loans = response.data;

                    // Apply filters
                    const statusFilter = document.getElementById('filterStatus')?.value || '';
                    const typeFilter = document.getElementById('filterLoanType')?.value.toLowerCase() || '';
                    const searchTerm = document.getElementById('searchInput')?.value.toLowerCase() || '';

                    let filtered = loans.filter(loan => {
                        if (!loan || typeof loan !== 'object') return false;

                        const statusMatch = !statusFilter || (loan.status && loan.status ===
                            statusFilter);
                        const typeMatch = !typeFilter || (loan.loan_type && loan.loan_type
                            .toLowerCase() ===
                            typeFilter);
                        const searchMatch = !searchTerm ||
                            (loan.full_name && loan.full_name.toLowerCase().includes(searchTerm)) ||
                            (loan.id_number && loan.id_number.toLowerCase().includes(searchTerm)) ||
                            (loan.email && loan.email.toLowerCase().includes(searchTerm)) ||
                            (loan.pf_number && loan.pf_number.toString().includes(searchTerm));

                        return statusMatch && typeMatch && searchMatch;
                    });

                    // Sort by last updated
                    filtered.sort((a, b) => {
                        const dateA = a.updated_at ? new Date(a.updated_at) : new Date(0);
                        const dateB = b.updated_at ? new Date(b.updated_at) : new Date(0);
                        return dateB - dateA;
                    });

                    // Pagination
                    const totalPages = Math.ceil(filtered.length / itemsPerPage);
                    const startIndex = (currentPage - 1) * itemsPerPage;
                    const endIndex = startIndex + itemsPerPage;
                    const paginatedList = filtered.slice(startIndex, endIndex);

                    tbody.innerHTML = '';

                    paginatedList.forEach(loan => {
                        const tr = document.createElement('tr');
                        tr.className = loan.status || '';
                        tr.innerHTML = `
                    <td>${loan.application_date || '-'}</td>
                    <td>${escapeHtml(loan.full_name || '')}</td>
                    <td>${escapeHtml(loan.id_number || '')}</td>
                    <td>${escapeHtml(loan.pf_number || '')}</td>
                    <td>${escapeHtml(loan.email || '')}</td>
                    <td>${escapeHtml(loan.loan_type || '')}</td>
                    <td>${fmtKES(loan.loan_amount || 0)}</td>
                    <td>${escapeHtml(loan.status || 'Pending')}</td>
                    <td>${escapeHtml(loan.feedback || '')}</td>
                    <td>${loan.updated_at ? new Date(loan.updated_at).toLocaleString() : '-'}</td>
                `;
                        tbody.appendChild(tr);
                    });

                    renderPagination(totalPages, filtered.length);
                } else {
                    tbody.innerHTML =
                        '<tr><td colspan="10" class="small text-center">No loan applications found.</td></tr>';
                    const paginationContainer = document.getElementById('loansPagination');
                    if (paginationContainer) {
                        paginationContainer.innerHTML = '';
                    }
                }
            })
            .catch(error => {
                tbody.innerHTML =
                    '<tr><td colspan="10" class="small text-center">Error loading loans</td></tr>';
                const paginationContainer = document.getElementById('loansPagination');
                if (paginationContainer) {
                    paginationContainer.innerHTML = '';
                }
            });
    }

    function renderPagination(totalPages, totalItems) {
        const container = document.getElementById('loansPagination');
        if (!container) return;

        if (totalPages <= 1) {
            container.innerHTML = '';
            return;
        }

        let html = '';

        // Previous button
        html += `<button ${currentPage === 1 ? 'disabled' : ''} class="btn-outline" onclick="changePage(${currentPage - 1})">
    ⬅️ Previous
</button>`;

        // Page numbers
        const maxVisible = 5;
        let startPage = Math.max(1, currentPage - Math.floor(maxVisible / 2));
        let endPage = Math.min(totalPages, startPage + maxVisible - 1);

        if (endPage - startPage < maxVisible - 1) {
            startPage = Math.max(1, totalPages - maxVisible + 1);
            endPage = totalPages;
        }

        if (startPage > 1) {
            html += `<button class="btn-outline" onclick="changePage(1)">1</button>`;
            if (startPage > 2) {
                html += `<span>...</span>`;
            }
        }

        for (let i = startPage; i <= endPage; i++) {
            html +=
                `<button class="${i === currentPage ? 'btn-primary' : 'btn-outline'}" onclick="changePage(${i})">${i}</button>`;
        }

        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                html += `<span>...</span>`;
            }
            html += `<button class="btn-outline" onclick="changePage(${totalPages})">${totalPages}</button>`;
        }

        // Next button
        html += `<button ${currentPage === totalPages ? 'disabled' : ''} class="btn-outline" onclick="changePage(${currentPage + 1})">
    Next ➡️
</button>`;

        // Info
        const startItem = (currentPage - 1) * itemsPerPage + 1;
        const endItem = Math.min(currentPage * itemsPerPage, totalItems);
        html += `<span class="pagination-info">Showing ${startItem}-${endItem} of ${totalItems}</span>`;

        container.innerHTML = html;
    }

    window.changePage = function(page) {
        currentPage = page;
        renderLoans();
    };

    // UPDATE STATS FUNCTION
    async function updateStats() {
        try {
            const [loansResponse, fundsResponse] = await Promise.all([
                apiRequest('loan_applications.php', 'GET'),
                apiRequest('funds.php', 'GET')
            ]);

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

            if (fundsResponse.success && fundsResponse.data) {
                const funds = fundsResponse.data;
                const totalFunds = funds.reduce((sum, fund) => sum + (Number(fund.balance) || 0), 0);
                formatStatNumber(totalFunds, 'totalFunds');
            }
        } catch (error) {
            console.error('Error updating stats:', error);
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
                    const rate = Number(localStorage.getItem('amortization_rate') || 3.0); // FIXED: Use correct key
                    const amortRateEl = document.getElementById('amortRate');
                    const amortStatusEl = document.getElementById('amortStatus');

                    if (amortRateEl) amortRateEl.value = rate;
                    if (amortStatusEl) amortStatusEl.textContent = `Current: ${rate.toFixed(1)}%`;
                }
            })
            .catch(error => {
                const rate = Number(localStorage.getItem('amortization_rate') || 3.0); // FIXED: Use correct key
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
                            add_staff: '👤',
                            update_staff: '✏️',
                            delete_staff: '🗑️',
                            change_password: '🔐',
                            update_settings: '⚙️',
                            logout: '🚪',
                            accepted: '✅',
                            dispatched: '🚀',
                            rejected: '❌',
                            returned: '↩️',
                            forwarded: '➡️'
                        } [activity.action] || '📋';

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
                            add_staff: '👤',
                            update_staff: '✏️',
                            delete_staff: '🗑️',
                            change_password: '🔐',
                            update_settings: '⚙️',
                            logout: '🚪',
                            accepted: '✅',
                            dispatched: '🚀',
                            rejected: '❌',
                            returned: '↩️',
                            forwarded: '➡️'
                        } [activity.action] || '📋';

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
        console.log('Form submitted, editingUserId:', editingUserId);

        const passwordField = document.getElementById('staffPwd');
        const password = passwordField ? passwordField.value.trim() : '';

        // Validate password ONLY if:
        // 1. Adding new user (editingUserId is null), OR
        // 2. Password field is not empty (user wants to change password)
        if (!editingUserId && !password) {
            showToast('Password is required for new users', 'error');
            return;
        }

        // If password is provided (for new user or password change), validate it
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
            createdAt: new Date().toISOString()
        };

        // Only include password if provided
        if (password) {
            data.password = password;
        }

        console.log('Form data:', data);

        // Validate other fields
        const errors = validateStaffInput(data);

        if (errors.length > 0) {
            showToast(errors.join(', '), 'error');
            return;
        }

        showLoading(true);

        if (editingUserId) {
            console.log('Updating user:', editingUserId);
            // Update existing user
            apiRequest(`users.php?user_id=${editingUserId}`, 'PUT', data)
                .then(response => {
                    console.log('Update response:', response);
                    if (response.success) {
                        editingUserId = null;
                        const cancelBtn = document.getElementById('cancelEditBtn');
                        if (cancelBtn) cancelBtn.classList.add('hidden');

                        // Reset password field
                        if (passwordField) {
                            passwordField.value = '';
                            passwordField.setAttribute('required', 'required');
                            passwordField.placeholder = 'Password';
                        }

                        // Clear cache to force refresh
                        usersCache = null;
                        renderUsers();

                        if (elements.staffForm) elements.staffForm.reset();
                        resetPasswordValidation();
                        showToast('User updated successfully', 'success');
                    } else {
                        showToast(response.message || 'Failed to update user', 'error');
                    }
                })
                .catch(error => {
                    console.error('Update error:', error);
                    showToast('Error updating user: ' + error.message, 'error');
                })
                .finally(() => {
                    showLoading(false);
                });
        } else {
            console.log('Adding new user');
            // Add new user
            apiRequest('users.php', 'POST', data)
                .then(response => {
                    console.log('Add response:', response);
                    if (response.success) {
                        // Clear cache to force refresh
                        usersCache = null;
                        renderUsers();

                        if (elements.staffForm) elements.staffForm.reset();
                        resetPasswordValidation();
                        showToast('User added successfully', 'success');
                    } else {
                        showToast(response.message || 'Failed to add user', 'error');
                    }
                })
                .catch(error => {
                    console.error('Add error:', error);
                    showToast('Error adding user: ' + error.message, 'error');
                })
                .finally(() => {
                    showLoading(false);
                });
        }
    }

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
            console.log('Theme preference not found, using default');
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
     * FIXED: Now sends data in the correct format expected by settings.php
     */
    async function saveThemeToDatabase(theme) {
        try {
            // Get CSRF token
            const csrfElement = document.getElementById('csrf-token-data');
            const csrfToken = csrfElement ? csrfElement.dataset.csrfToken : '';

            if (!csrfToken) {
                console.error('CSRF token not found');
                return;
            }

            // CRITICAL FIX: Send data with correct field names
            // According to settings.php API, it expects 'key' and 'value', NOT 'setting_key' and 'setting_value'
            const response = await apiRequest('settings.php', 'POST', {
                key: 'user_theme_preference', // ✅ Correct - API expects 'key'
                value: theme, // ✅ Correct - API expects 'value'
                csrf_token: csrfToken // ✅ Required for POST requests
            });

            if (!response || !response.success) {
                console.error('Failed to save theme preference:', response?.message || 'Unknown error');
            }
        } catch (error) {
            console.error('Error saving theme:', error);
        }
    }

    // ==========================================
    // ADDITIONAL BEST PRACTICES
    // ==========================================

    /**
     * Initialize theme on page load - call this in your DOMContentLoaded
     */
    function initializeTheme() {
        // Set default theme immediately to prevent flash
        document.documentElement.setAttribute('data-theme', 'light');

        // Then load saved preference
        loadThemeFromDatabase();
    }

    /**
     * Get current theme
     */
    function getCurrentTheme() {
        return currentTheme || 'light';
    }

    /**
     * Toggle between light and dark themes
     */
    function toggleTheme() {
        const newTheme = currentTheme === 'light' ? 'dark' : 'light';

        // Update UI
        document.querySelectorAll('.theme-option').forEach(btn => {
            if (btn.getAttribute('data-theme') === newTheme) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });

        // Apply and save
        currentTheme = newTheme;
        document.documentElement.setAttribute('data-theme', newTheme);
        saveThemeToDatabase(newTheme);

        showToast(`Switched to ${newTheme} theme`, 'success');
    }



    // Initialize everything when DOM is ready
    document.addEventListener('DOMContentLoaded', function() {
        console.log('DOM Content Loaded - Initializing...');

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

        console.log('Elements cached:', elements);

        // Initialize password change form functionality
        initializePasswordChangeForm();

        // Initialize theme icon
        updateThemeIcon();

        // Initialize transaction modal handlers
        const modal = document.getElementById('transactionModal');
        if (modal) {
            console.log('Transaction modal found, setting up handlers');

            // Close on overlay click
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    closeTransactionModal();
                }
            });

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

        // Mount portal
        console.log('Mounting portal...');
        improvedMountPortal();
    });

    // ==========================================
    // HAMBURGER MENU FIX
    // Place this script AFTER the elements object is initialized
    // ==========================================

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

        // Remove any existing listeners
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


    /**
     * Add overlay for mobile menu
     */
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

    /**
     * Remove mobile overlay
     */
    function removeMobileOverlay() {
        const overlay = document.getElementById('mobile-menu-overlay');
        if (overlay) {
            overlay.style.opacity = '0';
            setTimeout(() => {
                overlay.remove();
            }, 300);
        }
    }

    /**
     * Initialize with retry mechanism
     */
    function initHamburgerWithRetry(maxRetries = 5, delay = 200) {
        let attempts = 0;

        const tryInit = () => {
            attempts++;
            console.log(`🔄 Attempt ${attempts} to initialize hamburger menu...`);

            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.getElementById('sidebar');

            if (menuToggle && sidebar) {
                initializeHamburgerMenu();
                return true;
            }

            if (attempts < maxRetries) {
                console.log(`⏳ Elements not ready, retrying in ${delay}ms...`);
                setTimeout(tryInit, delay);
            } else {
                console.error('❌ Failed to initialize hamburger menu after', maxRetries, 'attempts');
            }

            return false;
        };

        tryInit();
    }

    // ==========================================
    // AUTO-INITIALIZE
    // ==========================================

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            console.log('📄 DOM loaded - initializing hamburger menu');
            initHamburgerWithRetry();
        });
    } else {
        // DOM already loaded
        console.log('📄 DOM already loaded - initializing hamburger menu immediately');
        initHamburgerWithRetry();
    }

    // Also expose globally for manual initialization
    window.initializeHamburgerMenu = initializeHamburgerMenu;
    window.initHamburgerWithRetry = initHamburgerWithRetry;

    function improvedMountPortal() {
        const isLoggedIn = <?php echo isset($_SESSION['user_id']) ? 'true' : 'false'; ?>;
        if (isLoggedIn) {
            document.body.classList.add('logged-in');
            if (elements.mainHeader) elements.mainHeader.classList.remove('hidden');
            if (elements.sidebar) elements.sidebar.classList.remove('hidden');
            if (elements.main) elements.main.classList.remove('hidden');

            showSection('dashboard');

            // Initialize all components in sequence
            initializeEventListeners();
            initializeProfileBadge();
            initializeThemeSelector();

            // Add import functionality
            initializeImportFunctionality();

            // Initialize user management events (MUST be after renderUsers)
            initializeUserManagementEvents();

            setTimeout(() => {
                initUserManagementWithRetry();
            }, 1000);
        };


        // Load data with proper timing
        setTimeout(() => {
            renderfunding_accounts();
        }, 200);

        setTimeout(() => {
            renderLoans();
        }, 400);

        setTimeout(() => {
            renderUsers();
        }, 600);

        setTimeout(() => {
            updateStats();
            loadAmortRate();
            updateRecentActivity();
        }, 800);

        setTimeout(() => {
            window.initLogout(['logoutBtn', 'logoutBtnSidebar'], {
                useAjax: true,
                confirmMessage: 'Are you sure you want to logout?'
            });
        }, 1000);
        else {
            document.body.classList.remove('logged-in');
            window.location.href = 'index.php';
        }
    }



    function initializeEventListeners() {
        // Store menuToggle in elements object for later use
        elements.menuToggle = document.getElementById('menuToggle');

        console.log('Initializing event listeners...');
        console.log('Menu toggle element:', elements.menuToggle);
        console.log('Sidebar element:', elements.sidebar);

        // Menu toggle for mobile and desktop
        if (elements.menuToggle && elements.sidebar) {
            console.log('Setting up menu toggle listener');

            elements.menuToggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();

                console.log('Menu toggle clicked, window width:', window.innerWidth);
                console.log('Current sidebar classes:', elements.sidebar.className);

                if (window.innerWidth <= 760) {
                    console.log('Mobile view - toggling mobile-open');
                    elements.sidebar.classList.toggle('mobile-open');
                } else {
                    console.log('Desktop view - toggling collapsed');
                    elements.sidebar.classList.toggle('collapsed');
                }

                console.log('New sidebar classes:', elements.sidebar.className);
            });

            // Close mobile menu when clicking outside
            document.addEventListener('click', (e) => {
                if (window.innerWidth <= 760 &&
                    elements.sidebar.classList.contains('mobile-open') &&
                    !elements.sidebar.contains(e.target) &&
                    !elements.menuToggle.contains(e.target)) {
                    console.log('Clicking outside - closing mobile menu');
                    elements.sidebar.classList.remove('mobile-open');
                }
            });
        } else {
            console.error('Menu toggle or sidebar not found!');
            console.error('menuToggle:', elements.menuToggle);
            console.error('sidebar:', elements.sidebar);
        }

        // Navigation items
        document.querySelectorAll('#sidebar button[data-filter]').forEach(item => {
            item.addEventListener('click', () => {
                const sectionName = item.dataset.filter;

                // Update active state
                document.querySelectorAll('#sidebar button').forEach(btn => {
                    btn.classList.remove('active');
                });
                item.classList.add('active');

                // Show the appropriate section
                if (sectionName === 'reports') {
                    console.log('Loading reports view...');
                    showReportsView();
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
            // Your existing logout logic here
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

        // Loan filters
        const filterBtn = document.getElementById('filterBtn');
        if (filterBtn) {
            filterBtn.addEventListener('click', () => {
                currentPage = 1;
                renderLoans();
                showToast('Filters applied', 'info');
            });
        }

        const clearFilters = document.getElementById('clearFilters');
        if (clearFilters) {
            clearFilters.addEventListener('click', () => {
                document.getElementById('filterStatus').value = '';
                document.getElementById('filterLoanType').value = '';
                document.getElementById('searchInput').value = '';
                currentPage = 1;
                renderLoans();
                showToast('Filters cleared', 'info');
            });
        }

        const refreshBtn = document.getElementById('refreshBtn');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => {
                renderLoans();
                showToast('Data refreshed', 'success');
            });
        }

        const exportBtn = document.getElementById('exportBtn');
        if (exportBtn) {
            exportBtn.addEventListener('click', () => {
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
        }

        // Search with debounce
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.addEventListener('input', debounce(() => {
                currentPage = 1;
                renderLoans();
            }, 300));
        }

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

        // Initialize user management event listeners
        initializeUserManagementEvents();

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
                    console.error('Backup error:', error);
                    showToast('Failed to download backup: ' + error.message, 'error');
                } finally {
                    showLoading(false);
                }
            });
        }

        const restoreDataBtn = document.getElementById('restoreDataBtn');
        if (restoreDataBtn) {
            restoreDataBtn.addEventListener('click', () => {
                const fileInput = document.getElementById('restoreFileInput');
                if (fileInput) fileInput.click();
            });
        }

        // Activity Monitoring buttons
        const viewLogsBtn = document.getElementById('viewLogsBtn');
        if (viewLogsBtn) {
            viewLogsBtn.addEventListener('click', () => {
                showToast('View logs functionality would be implemented here', 'info');
            });
        }

        const clearLogsBtn = document.getElementById('clearLogsBtn');
        if (clearLogsBtn) {
            clearLogsBtn.addEventListener('click', () => {
                // FIXED: Only show one confirmation dialog
                if (confirm('Are you sure you want to clear all activity logs?')) {
                    showToast('Clear logs functionality would be implemented here', 'info');
                }
            });
        }

        // Danger Zone buttons
        const clearStaffBtn = document.getElementById('clearStaffBtn');
        if (clearStaffBtn) {
            clearStaffBtn.addEventListener('click', () => {
                // FIXED: Only show one confirmation dialog
                if (confirm('Are you sure you want to clear all staff data? This cannot be undone.')) {
                    showToast('Clear staff functionality would be implemented here', 'warning');
                }
            });
        }

        const clearLoansBtn = document.getElementById('clearLoansBtn');
        if (clearLoansBtn) {
            clearLoansBtn.addEventListener('click', () => {
                // FIXED: Only show one confirmation dialog
                if (confirm('Are you sure you want to clear all loan data? This cannot be undone.')) {
                    showToast('Clear loans functionality would be implemented here', 'warning');
                }
            });
        }

        const clearSystemBtn = document.getElementById('clearSystemBtn');
        if (clearSystemBtn) {
            clearSystemBtn.addEventListener('click', () => {
                // FIXED: Only show one confirmation dialog
                if (confirm('Are you sure you want to clear ALL system data? This cannot be undone.')) {
                    showToast('Clear system functionality would be implemented here', 'warning');
                }
            });
        }

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

    // Initialize everything when DOM is ready
    document.addEventListener('DOMContentLoaded', function() {
        console.log('🚀 Admin Portal Initializing...');
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

        // Initialize password change form functionality
        initializePasswordChangeForm();

        // Initialize theme icon
        updateThemeIcon();

        // Initialize transaction modal handlers
        const modal = document.getElementById('transactionModal');
        if (modal) {
            // Close on overlay click
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    closeTransactionModal();
                }
            });

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

        // Initialize hamburger menu
        initializeHamburgerMenu();

        // Mount portal
        improvedMountPortal();
    });

    // Financial Reports View Function
    async function showReportsView() {
        // First, show the section
        showSection('reports');
        showLoading(true);

        try {
            // Update section title
            const sectionTitle = document.querySelector('#reportsSection h2');
            if (sectionTitle) {
                sectionTitle.textContent = '📊 Financial Reports & Analytics';
            }

            // Show reports content div
            let reportsContent = document.getElementById('reportsContent');
            if (!reportsContent) {
                // Create to div if it doesn't exist
                reportsContent = document.createElement('div');
                reportsContent.id = 'reportsContent';
                const reportsSection = document.getElementById('reportsSection');
                if (reportsSection) {
                    reportsSection.appendChild(reportsContent);
                }
            }
            reportsContent.style.display = 'block';

            // Hide the loans table if it exists in this section
            const loansTable = document.getElementById('loansTable');
            if (loansTable && loansTable.closest('#reportsSection')) {
                loansTable.style.display = 'none';
            }

            // Fetch payment data for all active/dispatched loans
            const loans = await getAllLoans();
            const activeLoans = loans.filter(l => l.status === 'active' || l.status === 'dispatched');
            const closedLoans = loans.filter(l => l.status === 'closed');

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

            // Create an async function to process loans
            const processLoans = async () => {
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
            };

            // Wait for loans to be processed
            await processLoans();

            let html = `
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px;">
<!-- Overall Summary Card -->
<div class="account-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; position: relative;">
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
<div class="account-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; border: none; position: relative;">
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
<div class="account-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; border: none; position: relative;">
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
<div class="account-card" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); color: white; border: none; position: relative;">
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
<div class="account-card">
<h3 style="margin-top:0; color:var(--green);">📅 Yearly Breakdown</h3>
<div class="table-container">
    <table style="width:100%; border-collapse:collapse; margin-top:15px;">
        <thead>
            <tr>
                <th>Year</th>
                <th>Total Loans</th>
                <th>Mortgage</th>
                <th>Car</th>
                <th>Amount Loaned</th>
                <th>Principal Paid</th>
                <th>Interest Paid</th>
                <th>Outstanding</th>
            </tr>
        </thead>
        <tbody>
`;

            const sortedYears = Object.keys(yearlyStats).sort((a, b) => b - a);
            sortedYears.forEach(year => {
                const stats = yearlyStats[year];
                html += `
<tr>
    <td style="font-weight:bold;">${year}</td>
    <td style="text-align:center;">${stats.count}</td>
    <td style="text-align:center;">${stats.mortgage.count} (KES ${formatCurrency(stats.mortgage.amount)})</td>
    <td style="text-align:center;">${stats.car.count} (KES ${formatCurrency(stats.car.amount)})</td>
    <td style="text-align:right; font-weight:bold;">KES ${formatCurrency(stats.loaned)}</td>
    <td style="text-align:right; color:var(--success);">KES ${formatCurrency(stats.principal)}</td>
    <td style="text-align:right; color:var(--warning);">KES ${formatCurrency(stats.interest)}</td>
    <td style="text-align:right; color:var(--danger);">KES ${formatCurrency(stats.balance)}</td>
</tr>
`;
            });

            html += `
        </tbody>
        <tfoot>
            <tr style="background:#f5f5f5; font-weight:bold; border-top: 2px solid var(--green);">
                <td>TOTAL</td>
                <td style="text-align:center;">${activeLoans.length + closedLoans.length}</td>
                <td style="text-align:center;">${mortgageStats.count}</td>
                <td style="text-align:center;">${carStats.count}</td>
                <td style="text-align:right;">KES ${formatCurrency(totalLoanedAmount)}</td>
                <td style="text-align:right; color:var(--success);">KES ${formatCurrency(totalPrincipalPaid)}</td>
                <td style="text-align:right; color:var(--warning);">KES ${formatCurrency(totalInterestPaid)}</td>
                <td style="text-align:right; color:var(--danger);">KES ${formatCurrency(totalOutstandingBalance)}</td>
            </tr>
        </tfoot>
    </table>
</div>
</div>

<!-- Detailed Active Loans -->
<div class="account-card" style="margin-top:20px;">
<h3 style="margin-top:0; color:var(--green);">📋 Active Loans Details</h3>
<div class="table-container">
    <table style="width:100%; border-collapse:collapse; margin-top:15px;">
        <thead>
            <tr>
                <th>Loanee</th>
                <th>PF Number</th>
                <th>Type</th>
                <th>Start Date</th>
                <th>Original Amount</th>
                <th>Principal Paid</th>
                <th>Interest Paid</th>
                <th>Balance Due</th>
            </tr>
        </thead>
        <tbody>
`;

            // Create another async function to process active loans for detailed table
            const processActiveLoans = async () => {
                for (const loan of activeLoans) {
                    const balanceInfo = await recalculateLoanBalance(loan);
                    html += `
    <tr>
        <td>${loan.full_name}</td>
        <td>${loan.pf_number}</td>
        <td>${loan.loan_type === 'mortgage' ? '🏠 Mortgage' : '🚗 Car'}</td>
        <td>${formatDate(loan.dispatch_date || loan.application_date)}</td>
        <td style="text-align:right;">KES ${formatCurrency(loan.loan_amount)}</td>
        <td style="text-align:right; color:var(--success);">KES ${formatCurrency(balanceInfo.principalPaid)}</td>
        <td style="text-align:right; color:var(--warning);">KES ${formatCurrency(balanceInfo.interestPaid)}</td>
        <td style="text-align:right; color:var(--danger); font-weight:bold;">KES ${formatCurrency(balanceInfo.totalBalanceDue)}</td>
    </tr>
`;
                }
            };

            // Wait for active loans to be processed
            await processActiveLoans();

            html += `
        </tbody>
    </table>
</div>
</div>

<!-- Export Button -->
<div style="text-align:right; margin-top:20px;">
<button onclick="exportFinancialReport()" class="btn-primary" style="padding:12px 25px; font-size:1rem;">
    📊 Export Complete Report
</button>
</div>
`;

            // With this:
            let reportsDiv = document.getElementById('reportsContent');
            if (!reportsDiv) {
                reportsDiv = document.createElement('div');
                reportsDiv.id = 'reportsContent';
                const reportsSection = document.getElementById('reportsSection');
                if (reportsSection) {
                    reportsSection.appendChild(reportsDiv);
                }
            }
            reportsDiv.innerHTML = html;
            reportsDiv.style.display = 'block';

            // Hide other sections
            const loansTableElement = document.getElementById('loansTable');
            if (loansTableElement) loansTableElement.style.display = 'none';
        } catch (error) {
            console.error('Error loading financial reports:', error);
            showToast('Failed to load financial reports', 'error');
        } finally {
            showLoading(false);
        }
    }

    // Add this function to JavaScript section of admin portal
    async function getAllLoans() {
        try {
            const response = await apiRequest('loan_applications.php', 'GET');
            return response.data || [];
        } catch (error) {
            console.error('Error fetching loans:', error);
            return [];
        }
    }

    // Add this function to get payments for a specific loan
    async function getPaymentsForLoan(applicationId) {
        try {
            const response = await apiRequest(`payments.php?application_id=${applicationId}`, 'GET');
            return response.data || [];
        } catch (error) {
            console.error('Error fetching payments:', error);
            return [];
        }
    }

    // ==========================================
    // UNIFIED BALANCE CALCULATION
    // Use this consistent version across all three portals
    // ==========================================

    async function recalculateLoanBalance(loan) {
        // CRITICAL: Use consistent interest rate source
        // Get from system settings via API to ensure consistency
        let annualRate = 3.0; // Default fallback

        try {
            // Try to get current system rate
            const settingsResponse = await apiRequest('settings.php?key=amortization_rate', 'GET');
            if (settingsResponse.success && settingsResponse.data) {
                annualRate = parseFloat(settingsResponse.data.value) || 3.0;
            }
        } catch (error) {
            console.warn('Using default interest rate:', annualRate);
        }

        // Normalize payment data structure (handle both camelCase and snake_case)
        const payments = Array.isArray(loan.payments) ? loan.payments : [];

        let totalPaid = 0;
        let principalPaid = 0;
        let interestPaid = 0;

        payments.forEach(payment => {
            // Handle both naming conventions
            const amount = parseFloat(payment.amount) || 0;
            const principal = parseFloat(payment.principal_amount || payment.principalAmount) || 0;
            const interest = parseFloat(payment.interest_amount || payment.interestAmount) || 0;

            totalPaid += amount;
            principalPaid += principal;
            interestPaid += interest;
        });

        // Normalize loan amount field
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
            // Normalize dispatch date field
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
    // MISSING HELPER FUNCTIONS - Add these to your admin.php
    // Place these BEFORE to exportOverallSummary function
    // ==========================================

    /**
     * Convert array data to CSV format with proper handling of numeric fields
     * @param {Array} data - 2D array of data rows
     * @param {Array} headers - Array of header strings
     * @param {Array} textColumns - Array of column indices that should be treated as text
     * @returns {string} - Formatted CSV string
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
                    stringValue = `'${stringValue}`;
                }

                // Escape quotes
                stringValue = stringValue.replace(/"/g, '""');

                // Wrap in quotes if contains comma, newline, or quote
                if (stringValue.includes(',') || stringValue.includes('\n') || stringValue.includes(
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
     * @param {string} filename - Name of file to download
     * @param {string} csvContent - CSV formatted string
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
     * @param {string} message - Notification message
     * @param {string} type - Type: success, error, warning, info
     */
    function showNotification(message, type = 'info') {
        // Use your existing showToast function
        if (typeof showToast === 'function') {
            showToast(message, type);
        } else {
            // Fallback notification
            console.log(`${type}: ${message}`);
            alert(message);
        }
    }

    /**
     * Unified API call function - wrapper for apiRequest for backward compatibility
     * @param {string} endpoint - API endpoint
     * @param {string} method - HTTP method
     * @param {object} data - Request data
     * @returns {Promise} - API response
     */
    async function apiCall(endpoint, method = 'GET', data = null) {
        return await apiRequest(endpoint, method, data);
    }

    /**
     * Format currency for display (no currency symbol)
     * @param {number} amount - Amount to format
     * @returns {string} - Formatted amount
     */
    function formatCurrency(amount) {
        const num = parseFloat(amount) || 0;
        return new Intl.NumberFormat('en-KE', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 2
        }).format(num);
    }

    /**
     * Format date for display
     * @param {string} dateString - ISO date string
     * @returns {string} - Formatted date
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
     * Calculate monthly interest on a principal amount
     * @param {number} principal - Principal amount
     * @param {number} annualRate - Annual interest rate (percentage)
     * @param {number} months - Number of months
     * @returns {number} - Calculated interest
     */
    function calculateMonthlyInterest(principal, annualRate, months = 1) {
        if (principal <= 0 || annualRate <= 0) return 0;
        const monthlyRate = annualRate / 100 / 12;
        return principal * monthlyRate * Math.max(1, months);
    }

    // ==========================================
    // ADDITIONAL UTILITY FUNCTIONS
    // ==========================================

    /**
     * Safely parse float values
     * @param {*} value - Value to parse
     * @param {number} defaultValue - Default value if parsing fails
     * @returns {number} - Parsed float or default
     */
    function safeParseFloat(value, defaultValue = 0) {
        const parsed = parseFloat(value);
        return isNaN(parsed) ? defaultValue : parsed;
    }

    /**
     * Get nested property from object safely
     * @param {object} obj - Object to get property from
     * @param {string} path - Dot notation path (e.g., 'user.profile.name')
     * @param {*} defaultValue - Default value if property doesn't exist
     * @returns {*} - Property value or default
     */
    function getNestedProperty(obj, path, defaultValue = null) {
        return path.split('.').reduce((current, prop) =>
            current?. [prop] ?? defaultValue, obj);
    }

    /**
     * Debounce function calls
     * @param {Function} func - Function to debounce
     * @param {number} wait - Wait time in milliseconds
     * @returns {Function} - Debounced function
     */
    function debounce(func, wait = 300) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func.apply(this, args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    /**
     * Deep clone an object
     * @param {*} obj - Object to clone
     * @returns {*} - Cloned object
     */
    function deepClone(obj) {
        if (obj === null || typeof obj !== 'object') return obj;

        if (obj instanceof Date) return new Date(obj.getTime());
        if (obj instanceof Array) return obj.map(item => deepClone(item));
        if (obj instanceof Object) {
            const clonedObj = {};
            for (const key in obj) {
                if (obj.hasOwnProperty(key)) {
                    clonedObj[key] = deepClone(obj[key]);
                }
            }
            return clonedObj;
        }
    }

    // ==========================================
    // EXPORT/IMPORT HELPERS
    // ==========================================

    /**
     * Export data to JSON file
     * @param {object} data - Data to export
     * @param {string} filename - Filename for export
     */
    function exportToJSON(data, filename) {
        const jsonStr = JSON.stringify(data, null, 2);
        const blob = new Blob([jsonStr], {
            type: 'application/json'
        });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        link.click();
        URL.revokeObjectURL(url);
        showToast('JSON exported successfully', 'success');
    }

    /**
     * Import JSON file
     * @param {File} file - File object from input
     * @returns {Promise} - Resolves with parsed JSON data
     */
    function importFromJSON(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = (e) => {
                try {
                    const data = JSON.parse(e.target.result);
                    resolve(data);
                } catch (error) {
                    reject(new Error('Invalid JSON file'));
                }
            };
            reader.onerror = () => reject(new Error('Failed to read file'));
            reader.readAsText(file);
        });
    }

    // ==========================================
    // VALIDATION HELPERS
    // ==========================================

    /**
     * Validate email address
     * @param {string} email - Email to validate
     * @returns {boolean} - True if valid
     */
    function isValidEmail(email) {
        const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return regex.test(email);
    }

    /**
     * Validate phone number (Kenyan format)
     * @param {string} phone - Phone number to validate
     * @returns {boolean} - True if valid
     */
    function isValidPhone(phone) {
        const regex = /^(\+254|0)[17]\d{8}$/;
        return regex.test(phone.replace(/\s/g, ''));
    }

    /**
     * Validate PF number (9-11 digits)
     * @param {string} pfNumber - PF number to validate
     * @returns {boolean} - True if valid
     */
    function isValidPFNumber(pfNumber) {
        const regex = /^\d{9,11}$/;
        return regex.test(pfNumber);
    }

    /**
     * Validate ID number (7-8 digits)
     * @param {string} idNumber - ID number to validate
     * @returns {boolean} - True if valid
     */
    function isValidIDNumber(idNumber) {
        const regex = /^\d{7,8}$/;
        return regex.test(idNumber);
    }

    // ==========================================
    // DATE HELPERS
    // ==========================================

    /**
     * Get date difference in days
     * @param {Date|string} date1 - First date
     * @param {Date|string} date2 - Second date
     * @returns {number} - Difference in days
     */
    function getDaysDifference(date1, date2) {
        const d1 = new Date(date1);
        const d2 = new Date(date2);
        const diffTime = Math.abs(d2 - d1);
        return Math.ceil(diffTime / (1000 * 60 * 60 * 24));
    }

    /**
     * Add months to a date
     * @param {Date|string} date - Starting date
     * @param {number} months - Number of months to add
     * @returns {Date} - New date
     */
    function addMonths(date, months) {
        const d = new Date(date);
        d.setMonth(d.getMonth() + months);
        return d;
    }

    /**
     * Format date to ISO string (YYYY-MM-DD)
     * @param {Date|string} date - Date to format
     * @returns {string} - ISO formatted date
     */
    function toISODate(date) {
        const d = new Date(date);
        return d.toISOString().split('T')[0];
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
            const filename = `Overall_Summary_${new Date().toISOString().split('T')[0]}.csv`;
            downloadCSV(filename, csv);
        } catch (error) {
            showNotification('Error exporting overall summary: ' + error.message, 'error');
        }
    }

    async function exportFinancialReport() {
        try {
            showLoading(true);

            // Get all data
            const loans = await getAllLoans();

            // CRITICAL FIX: Fetch payment data for all loans before calculations
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

            // CRITICAL: Specify which columns should be treated as text (indices 0, 2, 3, 15 = Application ID, PF Number, ID Number, Account Number)
            const textColumns = [0, 2, 3, 15];
            const csv = convertToCSV(data, headers, textColumns);
            const filename = `Complete_Financial_Report_${new Date().toISOString().split('T')[0]}.csv`;
            downloadCSV(filename, csv);

            showToast('Complete financial report exported successfully!', 'success');
        } catch (error) {
            console.error('Export error:', error);
            showToast('Failed to export complete report: ' + error.message, 'error');
        } finally {
            showLoading(false);
        }
    }

    // Make these functions globally accessible
    window.exportOverallSummary = exportOverallSummary;
    window.exportFinancialReport = exportFinancialReport;

    // ==========================================
    // MORTGAGE LOANS EXPORT
    // ==========================================
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
                    loan.status !== 'closed' ? formatCurrency(balanceInfo.totalBalanceDue) : '0',
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


    // ==========================================
    // CAR LOANS EXPORT
    // ==========================================
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
                    loan.status !== 'closed' ? formatCurrency(balanceInfo.totalBalanceDue) : '0',
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

    // ==========================================
    // PAYMENT SUMMARY EXPORT
    // ==========================================
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

            // Rest of the function remains the same...
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
                    loan.status !== 'closed' ? formatCurrency(balanceInfo.totalBalanceDue) : '0',
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

    // Make these functions globally accessible
    window.exportOverallSummary = exportOverallSummary;
    window.exportMortgageLoans = exportMortgageLoans;
    window.exportCarLoans = exportCarLoans;
    window.exportPaymentSummary = exportPaymentSummary;
    window.exportFinancialReport = exportFinancialReport;

    // Add this function to the admin portal JavaScript section
    function hasPermission(permission) {
        // Since this is the admin portal, we'll assume the admin has all permissions
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

    async function restoreFromBackup() {
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = '.json';

        input.onchange = async e => {
            const file = e.target.files[0];
            if (!file) return;

            try {
                const fileContent = await readFileAsText(file);
                const backupData = JSON.parse(fileContent);

                // ✅ STEP 1: Validate backup structure
                showLoading(true);
                const validateResponse = await apiRequest('validate-backup.php', 'POST', {
                    backup_data: backupData
                });

                const validation = validateResponse; // Already JSON from apiRequest

                if (!validation.success) {
                    showToast('Backup validation failed!', 'error');

                    // Safe issues handling: fallback to message if no issues array
                    const issues = Array.isArray(validation.issues) && validation.issues.length > 0 ?
                        validation.issues : [validation.message ||
                            'An unknown error occurred. Check console for details.'
                        ];

                    console.error('Validation issues:', issues);

                    // Show detailed error (update your modal code similarly)
                    const modal = document.createElement('div');
                    modal.className = 'modal show';
                    modal.innerHTML = `
                <div class="modal-content">
                    <h3>Validation Errors</h3>
                    <ul>${issues.map(issue => `<li>${issue}</li>`).join('')}</ul>
                    <button onclick="this.parentElement.parentElement.remove()">Close</button>
                </div>
            `;
                    document.body.appendChild(modal);

                    showLoading(false);
                    return;
                }

                // If validation passes, proceed to restore (assuming your code continues here)
                if (confirm(
                        'Backup validated successfully. Restore now? This will overwrite current data.'
                    )) {
                    const restoreResponse = await apiRequest('restore.php', 'POST', {
                        backup_data: backupData
                    });
                    // Handle restore response...
                }

                showLoading(false);

            } catch (error) {
                showToast('Error during restore process: ' + error.message, 'error');
                console.error(error);
                showLoading(false);
            }
        };

        input.click();
    }
    // Helper function to read file as text
    function readFileAsText(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result);
            reader.onerror = () => reject(new Error('Failed to read file'));
            reader.readAsText(file);
        });
    }


    /**
     * Format application ID for display in exports
     * @param {string|number} id - Application ID to format
     * @returns {string} - Formatted application ID
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
     * @param {string} accountNumber - Account number to format
     * @returns {string} - Formatted account number
     */
    function formatAccountNumber(accountNumber) {
        if (!accountNumber) return 'N/A';

        // Add a prefix to prevent Excel from treating it as a number
        return `'${String(accountNumber)}`;
    }

    // ==========================================
    // HAMBURGER MENU INITIALIZATION
    // Add this script RIGHT AFTER your closing 
    // ==========================================

    (function() {
        'use strict';

        console.log('🍔 Hamburger script loaded');

        function initHamburger() {
            console.log('🔧 Initializing hamburger menu...');

            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.getElementById('sidebar');

            if (!menuToggle || !sidebar) {
                console.error('❌ Required elements not found');
                console.error('menuToggle:', menuToggle);
                console.error('sidebar:', sidebar);
                return false;
            }

            console.log('✅ Elements found');

            // Remove any existing listeners
            const newMenuToggle = menuToggle.cloneNode(true);
            menuToggle.parentNode.replaceChild(newMenuToggle, menuToggle);

            // Add click handler
            newMenuToggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();

                console.log('🍔 Hamburger clicked!');
                console.log('Window width:', window.innerWidth);

                const isMobile = window.innerWidth <= 760;
                if (isMobile) {
                    console.log('📱 Mobile: toggling mobile-open');
                    sidebar.classList.toggle('mobile-open');
                    if (sidebar.classList.contains('mobile-open')) {
                        addOverlay();
                    } else {
                        removeOverlay();
                    }
                } else {
                    console.log('🖥️ Desktop: toggling collapsed');
                    sidebar.classList.toggle('collapsed');
                }
                console.log('Sidebar classes:', sidebar.className);
            });
            console.log('✅ Hamburger initialized successfully');
            return true;
        }

        function addOverlay() {
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

                // Trigger transition
                setTimeout(() => {
                    overlay.style.opacity = '1';
                }, 10);

                overlay.addEventListener('click', function() {
                    const sidebar = document.getElementById('sidebar');
                    if (sidebar) {
                        sidebar.classList.remove('mobile-open');
                    }
                    removeOverlay();
                });
            }
        }

        function removeOverlay() {
            const overlay = document.getElementById('mobile-menu-overlay');
            if (overlay) {
                overlay.style.opacity = '0';
                setTimeout(() => {
                    overlay.remove();
                }, 300);
            }
        }

        // Try to initialize with retry
        let attempts = 0;
        const maxAttempts = 10;
        const retryDelay = 200;

        function tryInit() {
            attempts++;
            console.log(`🔄 Attempt ${attempts} to initialize hamburger...`);

            if (initHamburger()) {
                console.log('🎉 Hamburger initialized successfully!');
            } else if (attempts < maxAttempts) {
                console.log(`⏳ Retrying in ${retryDelay}ms...`);
                setTimeout(tryInit, retryDelay);
            } else {
                console.error('❌ Failed to initialize hamburger after', maxAttempts, 'attempts');
            }
        }

        // Start initialization
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', tryInit);
        } else {
            tryInit();
        }
    })();


    //Then use it properly in your resize event handler
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            // Your resize logic here
        }, 250);
    });

    // Add this JavaScript function after your other function definitions
    function applyForLoan() {
        // Store original portal info
        const originalPortal = 'admin';

        // Log the switch
        console.log("Admin applying for loan");

        // Show confirmation dialog
        if (!confirm("You're about to switch to the loan application portal. Continue?")) {
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

        // Redirect to loanee portal
        setTimeout(() => {
            window.location.href = 'loanee.php?from=admin&action=switch_portal';
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
                    const idIndex = headers.findIndex(h => h.includes('id') && !h.includes('pf'));
                    const pfIndex = headers.findIndex(h => h.includes('pf'));

                    if (nameIndex === -1 || idIndex === -1 || pfIndex === -1) {
                        reject(new Error(
                            'CSV must contain columns for: Name, ID Number, and PF Number'));
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
                    const headers = jsonData[0].map(h => String(h).trim().toLowerCase());

                    // Find column indices
                    const nameIndex = headers.findIndex(h => h.includes('name'));
                    const emailIndex = headers.findIndex(h => h.includes('email'));
                    const idIndex = headers.findIndex(h => h.includes('id') && !h.includes(
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
                                email: emailIndex !== -1 ? String(row[emailIndex] || '')
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
                        const pageText = textContent.items.map(item => item.str).join(' ');
                        fullText += pageText + '\n';
                    }

                    // Parse text content (simple table detection)
                    const users = parseTableFromText(fullText);

                    if (users.length === 0) {
                        reject(new Error(
                            'No valid user data found in PDF. Please ensure the PDF contains a table with Name, Email, ID Number, and PF Number columns.'
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
                <h2>📥 Import Users Preview</h2>
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
                                const statusIcon = isValid ? '✅' : '❌';
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
                        <strong style="color: var(--danger);">⚠️ ${invalidUsers} Invalid Rows</strong>
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
                document.querySelector('input[name="csrf_token"]')?.value ||
                '<?php echo $_SESSION["csrf_token"] ?? ""; ?>';

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
                            <div class="error-list">
                                <strong>Errors/Warnings:</strong>
                                ${response.errors.map(err => `
                                    <div class="error-item">${escapeHtml(err)}</div>
                                `).join('')}
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

    function downloadBackup() {
        if (!confirm('Download full database backup now?')) return;

        // This triggers direct download - no JSON parsing needed
        window.location.href = 'backup.php';
    }

    // Quick fix for backup/restore
    async function quickRestoreFix() {
        // Get actual column names from first backup row
        const input = document.getElementById('restoreFileInput');
        input.onchange = async (e) => {
            const file = e.target.files[0];
            const text = await file.text();
            const backup = JSON.parse(text);

            // Validate structure
            /* console.log('Backup tables:', Object.keys(backup));
            console.log('First user columns:', backup.users?. [0] ? Object.keys(backup.users[0]) :
            'No users');*/

            // Proceed with restore if valid
            if (confirm('Restore database? This will delete all current data.')) {
                // Use the fixed restore.php endpoint
                const response = await apiRequest('restore.php', 'POST', {
                    backup_data: backup
                });

                if (response.success) {
                    alert('Restore successful!');
                    location.reload();
                } else {
                    alert('Restore failed: ' + response.message);
                }
            }
        };
    }
    </script>

</body>

</html>