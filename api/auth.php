<?php
/**
 * Authentication API Endpoint
 * Handles user login, logout, and CSRF token generation
 */

// CRITICAL: This must be the VERY FIRST line - no output before this
ob_start();

// TEMPORARY DEBUGGING - Remove after fixing
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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
ini_set('session.cookie_path', '/loan-system/'); // Adjust if needed

// Start session before any output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
    error_log('Session started. ID: ' . session_id());
} else {
    error_log('Session already active. ID: ' . session_id());
}

// Log session contents (be careful in production - might contain sensitive data)
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
function sendResponse($success, $message, $data = null)
{
    error_log('Sending response: ' . ($success ? 'SUCCESS' : 'FAILURE') . ' - ' . $message);
    ob_end_clean();
    $response = [
        'success' => $success,
        'message' => $message
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
    sendResponse(false, 'An internal error occurred. Check error logs for details.');
}

set_error_handler('handleError');
set_exception_handler(function ($e) {
    $errorMsg = 'Uncaught Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
    error_log($errorMsg);
    error_log('Stack trace: ' . $e->getTraceAsString());
    sendResponse(false, 'An internal error occurred: ' . $e->getMessage());
});

// Define BASE_PATH with better error handling
if (!defined('BASE_PATH')) {
    $basePath = realpath(dirname(__DIR__));
    if ($basePath === false) {
        error_log('CRITICAL: Could not determine BASE_PATH');
        sendResponse(false, 'Server configuration error');
    }
    define('BASE_PATH', $basePath . '/');
    error_log('BASE_PATH set to: ' . BASE_PATH);
}

// Include config FIRST (it has the CSRF functions)
$configPath = BASE_PATH . 'config/config.php';
if (!file_exists($configPath)) {
    error_log('CRITICAL: Config file not found at: ' . $configPath);
    sendResponse(false, 'Configuration file not found');
}
require_once $configPath;
error_log('Config file included successfully');

// Check and include database configuration
$dbPath = BASE_PATH . 'config/database.php';
error_log('Looking for database config at: ' . $dbPath);
error_log('Database config exists: ' . (file_exists($dbPath) ? 'YES' : 'NO'));

if (!file_exists($dbPath)) {
    error_log('CRITICAL: Database config file not found at: ' . $dbPath);
    error_log('Current directory: ' . getcwd());
    error_log('BASE_PATH: ' . BASE_PATH);
    sendResponse(false, 'Database configuration file not found');
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
    sendResponse(false, 'Database connection failed: ' . $e->getMessage());
}

// Get request method
$method = $_SERVER['REQUEST_METHOD'];
error_log('Processing ' . $method . ' request');

// Handle GET requests - Return CSRF token
if ($method === 'GET') {
    error_log('Handling GET request for CSRF token');
    $token = generateCSRFToken(); // Function is already defined in config.php

    if (!empty($_SESSION['logged_in']) && !empty($_SESSION['user_id'])) {
        error_log('User is logged in, returning user data with token');
        // User is logged in - return user data with token
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
        error_log('User not logged in, returning token only');
        // Not logged in - just return token
        sendResponse(true, 'CSRF token generated', [
            'csrf_token' => $token
        ]);
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
        sendResponse(false, 'Invalid JSON: ' . json_last_error_msg());
    }

    if (empty($data->action)) {
        error_log('No action parameter provided');
        sendResponse(false, 'Action parameter is required');
    }

    error_log('Action requested: ' . $data->action);

    // Handle login action
    if ($data->action === 'login') {
        if (empty($data->email) || empty($data->password)) {
            error_log('Login attempt with missing credentials');
            sendResponse(false, 'Email and password are required');
        }

        try {
            $query = "SELECT user_id, full_name, email, id_number, pf_number, password, role
                      FROM users
                      WHERE email = :email AND is_active = 1
                      LIMIT 1";

            $stmt = $db->prepare($query);
            $stmt->bindParam(':email', $data->email, PDO::PARAM_STR);
            $stmt->execute();

            if ($stmt->rowCount() === 0) {
                error_log('Login failed: User not found or inactive - ' . $data->email);
                sendResponse(false, 'Invalid email or password');
            }

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Verify password
            if (!password_verify($data->password, $user['password'])) {
                error_log('Login failed: Invalid password for ' . $data->email);
                sendResponse(false, 'Invalid email or password');
            }

            error_log('Login successful for: ' . $user['email']);

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
            sendResponse(false, 'Login failed. Please try again.');
        }
    }

    // Handle logout action
    if ($data->action === 'logout') {
        // Optionally validate CSRF token
        if (!empty($data->csrf_token) && !validateCSRFToken($data->csrf_token)) {
            error_log('Logout failed: Invalid CSRF token');
            sendResponse(false, 'Invalid CSRF token');
        }

        error_log('Logout successful');

        // Destroy session
        session_unset();
        session_destroy();

        sendResponse(true, 'Logout successful');
    }

    // Handle password change action
    if ($data->action === 'change_password') {
        error_log('Password change request received');
        
        // Check if user is logged in FIRST
        if (empty($_SESSION['user_id'])) {
            error_log('Password change failed: User not logged in');
            error_log('Session contents: ' . print_r($_SESSION, true));
            sendResponse(false, 'You must be logged in to change your password');
        }
        
        // Validate input
        if (empty($data->current_password) || empty($data->new_password)) {
            error_log('Password change failed: Missing required fields');
            sendResponse(false, 'Current password and new password are required');
        }
        
        // Validate new password strength
        if (strlen($data->new_password) < 8) {
            error_log('Password change failed: Password too short');
            sendResponse(false, 'New password must be at least 8 characters long');
        }
        
        try {
            // Verify current password
            $query = "SELECT password FROM users WHERE user_id = :user_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
            $stmt->execute();
            
            if ($stmt->rowCount() === 0) {
                error_log('Password change failed: User not found in database');
                sendResponse(false, 'User account not found');
            }
            
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!password_verify($data->current_password, $user['password'])) {
                error_log('Password change failed: Current password is incorrect for user ID: ' . $_SESSION['user_id']);
                sendResponse(false, 'Current password is incorrect');
            }
            
            // Update password
            $hashed_password = password_hash($data->new_password, PASSWORD_DEFAULT);
            $query = "UPDATE users SET password = :password, updated_at = NOW() WHERE user_id = :user_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':password', $hashed_password, PDO::PARAM_STR);
            $stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
            
            if ($stmt->execute()) {
                error_log('Password changed successfully for user ID: ' . $_SESSION['user_id']);
                
                // Log activity
                try {
                    $activityQuery = "INSERT INTO activity_log (admin_id, action, details, timestamp) 
                                     VALUES (:admin_id, 'change_password', :details, NOW())";
                    $activityStmt = $db->prepare($activityQuery);
                    $activityStmt->bindParam(':admin_id', $_SESSION['user_id']);
                    $details = "User {$_SESSION['full_name']} changed their password";
                    $activityStmt->bindParam(':details', $details);
                    $activityStmt->execute();
                } catch (PDOException $e) {
                    error_log("Failed to log activity: " . $e->getMessage());
                }
                
                sendResponse(true, 'Password changed successfully');
            } else {
                error_log('Password change failed: Database update failed');
                sendResponse(false, 'Failed to update password in database');
            }
            
        } catch (PDOException $e) {
            error_log('Password change error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            sendResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    // Unknown action
    error_log('Unknown action requested: ' . $data->action);
    sendResponse(false, 'Invalid action');
}

// Method not allowed
error_log('Method not allowed: ' . $method);
ob_end_clean();
http_response_code(405);
echo json_encode([
    'success' => false,
    'message' => 'Method not allowed'
]);
exit;