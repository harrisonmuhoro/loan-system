<?php
/**
 * Password Reset API Endpoint
 * Handles password reset requests and confirmations
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

session_start();

// Define base path and include dependencies
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__) . '/');
}

 $paths = [
    BASE_PATH . '/config/config.php',
    BASE_PATH . '/config/database.php',
    BASE_PATH . '/helpers/response_helper.php',
    BASE_PATH . '/models/User.php',
];

foreach ($paths as $path) {
    if (!file_exists($path)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => "Missing dependency: " . basename($path)]);
        exit;
    }
    require_once $path;
}

// Init DB connection
try {
    $database = new Database();
    $db = $database->getConnection();
    $user = new User($db);
} catch (Exception $e) {
    ResponseHelper::serverError('Database connection failed: ' . $e->getMessage());
}

// CSRF Token Functions
function validateCSRFToken($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

// Route by HTTP method
 $method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    // POST: request reset / confirm reset
    case 'POST':
        $data = json_decode(file_get_contents("php://input"));
        if (empty($data->action)) ResponseHelper::error('Action is required');

        switch ($data->action) {
            case 'request':
                // Validate CSRF token
                if (empty($data->csrf_token) || !validateCSRFToken($data->csrf_token)) {
                    ResponseHelper::forbidden('Invalid CSRF token');
                }
                
                if (empty($data->email)) {
                    ResponseHelper::error('Email is required');
                }
                
                // Generate reset token
                $reset_token = bin2hex(random_bytes(32));
                $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
                
                // Store reset token in database
                $query = "UPDATE users SET reset_token = ?, reset_token_expiry = ? 
                         WHERE email = ?";
                $stmt = $db->prepare($query);
                $stmt->bind_param("sss", $reset_token, $expiry, $data->email);
                
                if ($stmt->execute()) {
                    // In a real implementation, you would send an email with the reset link
                    // For security, always return success even if email doesn't exist
                    ResponseHelper::success(null, 'If your email exists in our system, a password reset link has been sent');
                } else {
                    ResponseHelper::error('Failed to process password reset');
                }
                break;

            case 'confirm':
                // Validate CSRF token
                if (empty($data->csrf_token) || !validateCSRFToken($data->csrf_token)) {
                    ResponseHelper::forbidden('Invalid CSRF token');
                }
                
                if (empty($data->reset_token) || empty($data->new_password)) {
                    ResponseHelper::error('Reset token and new password are required');
                }
                
                // Validate new password strength
                if (strlen($data->new_password) < 8) {
                    ResponseHelper::error('New password must be at least 8 characters long');
                }
                
                // Verify reset token
                $query = "SELECT user_id FROM users 
                         WHERE reset_token = ? AND reset_token_expiry > NOW()";
                $stmt = $db->prepare($query);
                $stmt->bind_param("s", $data->reset_token);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $user->user_id = $row['user_id'];
                    
                    if ($user->updatePassword($data->new_password)) {
                        $query = "UPDATE users SET reset_token = NULL, reset_token_expiry = NULL 
                                 WHERE user_id = ?";
                        $stmt = $db->prepare($query);
                        $stmt->bind_param("i", $row['user_id']);
                        $stmt->execute();
                        $stmt->execute();
                        
                        ResponseHelper::success(null, 'Password reset successful');
                    } else {
                        ResponseHelper::error('Failed to reset password');
                    }
                } else {
                    ResponseHelper::error('Invalid or expired reset token');
                }
                break;

            default:
                ResponseHelper::error('Invalid action');
        }
        break;

    default:
        ResponseHelper::error('Method not allowed', 405);
}
?>