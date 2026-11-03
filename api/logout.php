<?php
// Disable ALL output before session operations
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Start output buffering IMMEDIATELY - BEFORE anything else
ob_start();

// Configure error logging
$logDir = dirname(__DIR__) . '/logs';
if (!file_exists($logDir)) {
    @mkdir($logDir, 0755, true);
}
ini_set('error_log', $logDir . '/php_error.log');

/**
 * Send JSON response and exit
 */
function sendResponse($success, $message, $data = null, $statusCode = 200)
{
    // Clean ALL output buffers
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Start fresh output buffer
    ob_start();
    
    // Set headers
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $response = [
        'success' => $success,
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ];

    if ($data !== null) {
        $response['data'] = $data;
    }

    echo json_encode($response, JSON_PRETTY_PRINT);
    
    ob_end_flush();
    exit;
}

try {
    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        // Configure session BEFORE starting
        $sessionPath = sys_get_temp_dir();
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $sessionPath = 'C:/wamp64/tmp';
            if (!is_dir($sessionPath)) {
                @mkdir($sessionPath, 0777, true);
            }
        }
        
        @ini_set('session.save_path', $sessionPath);
        @ini_set('session.gc_probability', 1);
        @ini_set('session.gc_divisor', 100);
        
        @session_start();
    }

    // Handle preflight OPTIONS request
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    // Only accept POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendResponse(false, 'Method not allowed. Use POST request.', null, 405);
    }

    // Get input
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        sendResponse(false, 'Invalid JSON data', null, 400);
    }

    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        sendResponse(false, 'No active session found', [
            'already_logged_out' => true
        ], 401);
    }

    // Store user info
    $userId = $_SESSION['user_id'];
    $userName = $_SESSION['full_name'] ?? 'Unknown User';
    $userRole = $_SESSION['user_role'] ?? 'unknown';
    $userEmail = $_SESSION['email'] ?? 'N/A';

    // Log the logout activity
    try {
        require_once __DIR__ . '/../config/database.php';
        $database = new Database();
        $db = $database->getConnection();

        $message = sprintf(
            "API Logout: User '%s' (Role: %s, Email: %s) from IP: %s",
            $userName,
            ucfirst($userRole),
            $userEmail,
            $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
        );

        $query = "INSERT INTO activity_log (admin_id, action, details, timestamp) 
                  VALUES (:user_id, 'api_logout', :message, NOW())";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':message', $message, PDO::PARAM_STR);
        $stmt->execute();

        error_log("[API LOGOUT] User ID: $userId, Name: $userName, Role: $userRole");
    } catch (Exception $e) {
        error_log("Logout activity logging failed: " . $e->getMessage());
    }

    // Get cookie parameters
    $params = session_get_cookie_params();

    // Clear session cookie
    if (isset($_COOKIE[session_name()])) {
        setcookie(
            session_name(),
            '',
            time() - 3600,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    // Unset all session variables
    $_SESSION = array();

    // Destroy the session
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_destroy();
    }

    // Determine redirect page
    $rolePages = [
        'admin' => 'index.php',
        'cashier' => 'index.php',
        'accountant' => 'index.php',
        'loanee' => 'index.php'
    ];

    $redirectPage = $rolePages[$userRole] ?? 'index.php';

    // Send success response
    sendResponse(true, 'Logout successful', [
        'user' => $userName,
        'role' => $userRole,
        'redirect_url' => $redirectPage . '?logout=success&role=' . urlencode($userRole),
        'logged_out_at' => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    error_log("Critical logout API error: " . $e->getMessage());
    
    // Try to destroy session anyway
    try {
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_destroy();
        }
    } catch (Exception $destroyError) {
        error_log("Session destroy error: " . $destroyError->getMessage());
    }

    sendResponse(false, 'Logout failed: ' . $e->getMessage(), null, 500);
}