<?php
/**
 * Authentication API Endpoint
 * Handles user login with PF Number, logout, and CSRF token generation
 * BACKWARD COMPATIBLE: Works with both email and PF number sessions
 */

// CRITICAL: This must be the VERY FIRST line - no output before this
ob_start();

// Configure error logging
ini_set('log_errors', 1);
$logDir = dirname(__DIR__) . '/logs';
if (!file_exists($logDir)) {
    mkdir($logDir, 0755, true);
}
ini_set('error_log', $logDir . '/php_error.log');

// Log script start
error_log('========== AUTH.PHP CALLED ==========');
error_log('Request Method: ' . $_SERVER['REQUEST_METHOD']);
error_log('Request URI: ' . $_SERVER['REQUEST_URI']);

// Ensure consistent session handling across the application
ini_set('session.use_cookies', 1);
ini_set('session.use_only_cookies', 1);
// All application pages use the root cookie path. Keeping the API on a
// different path can make it appear as though the user has been logged out.
ini_set('session.cookie_path', '/');

// Start session before any output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
    error_log('Session started. ID: ' . session_id());
} else {
    error_log('Session already active. ID: ' . session_id());
}

// Log session contents
error_log('Session contents: ' . print_r($_SESSION, true));

// Set JSON headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    ob_end_clean();
    exit;
}

/**
 * Send JSON response and exit
 */
function sendResponse($success, $message, $data = null, $statusCode = 200)
{
    error_log('Sending response: ' . ($success ? 'SUCCESS' : 'FAILURE') . ' - ' . $message);
    ob_end_clean();
    http_response_code($statusCode);
    $response = [
        'success' => $success,
        'message' => $message,
        'status_code' => $statusCode,
        'timestamp' => date('c')
    ];
    if ($data !== null) {
        $response['data'] = $data;
    }
    echo json_encode($response, JSON_PRETTY_PRINT);
    exit;
}

/**
 * Handle fatal errors and exceptions
 */
function handleError($errno, $errstr, $errfile, $errline)
{
    $errorMsg = "PHP Error [$errno]: $errstr in $errfile on line $errline";
    error_log($errorMsg);
    sendResponse(false, 'An internal error occurred. Check error logs for details.', null, 500);
}

set_error_handler('handleError');
set_exception_handler(function ($e) {
    $errorMsg = 'Uncaught Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
    error_log($errorMsg);
    error_log('Stack trace: ' . $e->getTraceAsString());
    sendResponse(false, 'An internal error occurred: ' . $e->getMessage(), null, 500);
});

// Define BASE_PATH with better error handling
if (!defined('BASE_PATH')) {
    $basePath = realpath(dirname(__DIR__));
    if ($basePath === false) {
        error_log('CRITICAL: Could not determine BASE_PATH');
        sendResponse(false, 'Server configuration error', null, 500);
    }
    define('BASE_PATH', $basePath . '/');
    error_log('BASE_PATH set to: ' . BASE_PATH);
}

// Include config FIRST (it has the CSRF functions)
$configPath = BASE_PATH . 'config/config.php';
if (!file_exists($configPath)) {
    error_log('CRITICAL: Config file not found at: ' . $configPath);
    sendResponse(false, 'Configuration file not found', null, 500);
}
require_once $configPath;
error_log('Config file included successfully');

// Check and include database configuration
$dbPath = BASE_PATH . 'config/database.php';
if (!file_exists($dbPath)) {
    error_log('CRITICAL: Database config file not found at: ' . $dbPath);
    sendResponse(false, 'Database configuration file not found', null, 500);
}

require_once $dbPath;
error_log('Database config file included successfully');

// Initialize database connection
$db = null;
try {
    error_log('Attempting to create Database instance...');
    $database = new Database();
    error_log('Database instance created successfully');

    error_log('Attempting to get database connection...');
    $db = $database->getConnection();
    error_log('Database connection obtained');

    if (!$db || !($db instanceof PDO)) {
        throw new Exception('Database connection is not a valid PDO instance');
    }

    error_log('Database connection validated successfully');
} catch (Exception $e) {
    error_log('Database connection error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    sendResponse(false, 'Database connection failed: ' . $e->getMessage(), null, 500);
}

// Get request method
$method = $_SERVER['REQUEST_METHOD'];
error_log('Processing ' . $method . ' request');

// Handle GET requests - Return CSRF token for authenticated users
if ($method === 'GET') {
    error_log('Handling GET request for CSRF token');
    
    // BACKWARD COMPATIBILITY: Check multiple session variables
    $isAuthenticated = (
        (!empty($_SESSION['logged_in']) || !empty($_SESSION['authenticated'])) &&
        !empty($_SESSION['user_id']) &&
        !empty($_SESSION['user_role'])
    );
    
    if ($isAuthenticated) {
        error_log('Authenticated user found: ' . $_SESSION['user_role'] . ' - User ID: ' . $_SESSION['user_id']);
        
        // BACKWARD COMPATIBILITY: Ensure PF number exists in session
        if (empty($_SESSION['pf_number'])) {
            error_log('WARNING: PF number missing from session, fetching from database');
            
            try {
                $query = "SELECT pf_number FROM users WHERE user_id = :user_id LIMIT 1";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
                $stmt->execute();
                
                if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $_SESSION['pf_number'] = $row['pf_number'];
                    error_log('PF number retrieved and added to session: ' . $row['pf_number']);
                } else {
                    error_log('ERROR: Could not fetch PF number for user_id: ' . $_SESSION['user_id']);
                }
            } catch (PDOException $e) {
                error_log('Error fetching PF number: ' . $e->getMessage());
            }
        }
        
        // Generate or retrieve CSRF token
        $token = generateCSRFToken();
        
        // Return user data with token
        sendResponse(true, 'User authenticated', [
            'user' => [
                'user_id' => $_SESSION['user_id'] ?? null,
                'full_name' => $_SESSION['full_name'] ?? '',
                'email' => $_SESSION['email'] ?? '',
                'id_number' => $_SESSION['id_number'] ?? '',
                'pf_number' => $_SESSION['pf_number'] ?? '',
                'role' => $_SESSION['user_role'] ?? ''
            ],
            'csrf_token' => $token
        ]);
    } else {
        error_log('User not authenticated. Session analysis:');
        error_log('- logged_in: ' . (isset($_SESSION['logged_in']) ? $_SESSION['logged_in'] : 'not set'));
        error_log('- authenticated: ' . (isset($_SESSION['authenticated']) ? $_SESSION['authenticated'] : 'not set'));
        error_log('- user_id: ' . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'not set'));
        error_log('- user_role: ' . (isset($_SESSION['user_role']) ? $_SESSION['user_role'] : 'not set'));
        
        // Not authenticated - return unauthorized response
        sendResponse(false, 'Unauthorized. Please login.', null, 401);
    }
}

// Handle POST requests
if ($method === 'POST') {
    error_log('Handling POST request');

    // Parse JSON input
    $input = file_get_contents('php://input');
    error_log('POST data received: ' . $input);

    $data = json_decode($input);

    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('JSON decode error: ' . json_last_error_msg());
        sendResponse(false, 'Invalid JSON: ' . json_last_error_msg(), null, 400);
    }

    if (empty($data->action)) {
        error_log('No action parameter provided');
        sendResponse(false, 'Action parameter is required', null, 400);
    }

    error_log('Action requested: ' . $data->action);

    // Handle login action with PF Number
    if ($data->action === 'login') {
        if (empty($data->pf_number) || empty($data->password)) {
            error_log('Login attempt with missing credentials');
            sendResponse(false, 'PF Number and password are required', null, 400);
        }

        try {
            // Query using PF number
            $query = "SELECT user_id, full_name, email, id_number, pf_number, password, role
                      FROM users
                      WHERE pf_number = :pf_number AND is_active = 1
                      LIMIT 1";

            $stmt = $db->prepare($query);
            $stmt->bindParam(':pf_number', $data->pf_number, PDO::PARAM_STR);
            $stmt->execute();

            if ($stmt->rowCount() === 0) {
                error_log('Login failed: User not found or inactive - PF: ' . $data->pf_number);
                sendResponse(false, 'Invalid PF Number or password', null, 401);
            }

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Verify password
            if (!password_verify($data->password, $user['password'])) {
                error_log('Login failed: Invalid password for PF: ' . $data->pf_number);
                sendResponse(false, 'Invalid PF Number or password', null, 401);
            }

            error_log('Login successful for PF: ' . $user['pf_number'] . ' (' . $user['email'] . ')');

            // Set session variables
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['id_number'] = $user['id_number'];
            $_SESSION['pf_number'] = $user['pf_number'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['logged_in'] = true;
            $_SESSION['authenticated'] = true;

            // Generate CSRF token
            $csrfToken = generateCSRFToken();

            sendResponse(true, 'Login successful', [
                'user' => [
                    'user_id' => $user['user_id'],
                    'full_name' => $user['full_name'],
                    'email' => $user['email'],
                    'id_number' => $user['id_number'],
                    'pf_number' => $user['pf_number'],
                    'user_role' => $user['role']
                ],
                'csrf_token' => $csrfToken
            ]);

        } catch (PDOException $e) {
            error_log('Login query error: ' . $e->getMessage());
            sendResponse(false, 'Login failed. Please try again.', null, 500);
        }
    }

    // Handle logout action
    if ($data->action === 'logout') {
        error_log('Logout successful for PF: ' . ($_SESSION['pf_number'] ?? 'unknown'));

        // Destroy session
        session_unset();
        session_destroy();

        sendResponse(true, 'Logout successful');
    }

    // Handle password change action
    if ($data->action === 'change_password') {
        error_log('Password change request received');
        
        // Check if user is logged in
        if (empty($_SESSION['user_id'])) {
            error_log('Password change failed: User not logged in');
            sendResponse(false, 'You must be logged in to change your password', null, 401);
        }
        
        // Validate input
        if (empty($data->current_password) || empty($data->new_password)) {
            error_log('Password change failed: Missing required fields');
            sendResponse(false, 'Current password and new password are required', null, 400);
        }
        
        // Validate new password strength
        if (strlen($data->new_password) < 8) {
            error_log('Password change failed: Password too short');
            sendResponse(false, 'New password must be at least 8 characters long', null, 400);
        }
        
        try {
            // Verify current password
            $query = "SELECT password FROM users WHERE user_id = :user_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
            $stmt->execute();
            
            if ($stmt->rowCount() === 0) {
                error_log('Password change failed: User not found in database');
                sendResponse(false, 'User account not found', null, 404);
            }
            
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!password_verify($data->current_password, $user['password'])) {
                error_log('Password change failed: Current password is incorrect');
                sendResponse(false, 'Current password is incorrect', null, 401);
            }
            
            // Update password
            $hashed_password = password_hash($data->new_password, PASSWORD_DEFAULT);
            $query = "UPDATE users SET password = :password, updated_at = NOW() WHERE user_id = :user_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':password', $hashed_password, PDO::PARAM_STR);
            $stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
            
            if ($stmt->execute()) {
                error_log('Password changed successfully for user ID: ' . $_SESSION['user_id']);
                sendResponse(true, 'Password changed successfully');
            } else {
                error_log('Password change failed: Database update failed');
                sendResponse(false, 'Failed to update password in database', null, 500);
            }
            
        } catch (PDOException $e) {
            error_log('Password change error: ' . $e->getMessage());
            sendResponse(false, 'Database error: ' . $e->getMessage(), null, 500);
        }
    }

    // Unknown action
    error_log('Unknown action requested: ' . $data->action);
    sendResponse(false, 'Invalid action', null, 400);
}

// Method not allowed
error_log('Method not allowed: ' . $method);
ob_end_clean();
http_response_code(405);
echo json_encode([
    'success' => false,
    'message' => 'Method not allowed',
    'status_code' => 405,
    'timestamp' => date('c')
]);
exit;
