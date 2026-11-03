<?php
/**
 * System Settings API
 * Handles system settings with role-based access control
 * - Admin: Full read/write access
 * - Cashier & Accountant: Read-only access
 */

// Disable error display, enable logging
error_reporting(0);
ini_set('display_errors', 0);

// Start output buffering
ob_start();

// Set JSON content type
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

// Include required files
require_once __DIR__ . '/../config/database.php';

// Set session params to match admin portal to avoid cookie conflicts
session_set_cookie_params([
    'lifetime' => 36000,
    'path' => '/'
]);
// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Send JSON response and exit
 */
function sendResponse($success, $message, $data = null, $statusCode = 200)
{
    ob_end_clean();
    http_response_code($statusCode);

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

// Verify user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
    sendResponse(false, 'Unauthorized. Please login.', null, 401);
}

// Initialize database connection
try {
    $database = new Database();
    $db = $database->getConnection();
} catch (Exception $e) {
    error_log('Database connection error: ' . $e->getMessage());
    sendResponse(false, 'Database connection failed', null, 500);
}

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

try {
    // ==================== GET REQUEST - READ SETTINGS ====================
    if ($method === 'GET') {
        // Allow admin, cashier, and accountant to READ settings
        $allowedRoles = ['admin', 'cashier', 'accountant'];

        if (!in_array($_SESSION['user_role'], $allowedRoles)) {
            sendResponse(false, 'Access denied. You do not have permission to view settings. Your role: ' . $_SESSION['user_role'], null, 403);
        }

        $key = $_GET['key'] ?? '';

        if (empty($key)) {
            // Get all settings
            $query = "SELECT setting_key, value, updated_at FROM system_settings ORDER BY setting_key";
            $stmt = $db->prepare($query);
            $stmt->execute();
            $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            sendResponse(true, 'Settings retrieved successfully', $settings);
        } else {
            // Get specific setting
            $query = "SELECT setting_key, value, updated_at FROM system_settings WHERE setting_key = :key";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':key', $key, PDO::PARAM_STR);
            $stmt->execute();

            if ($stmt->rowCount() === 0) {
                // Return default value for amortization_rate if not found
                if ($key === 'amortization_rate') {
                    sendResponse(true, 'Default setting returned', [
                        'setting_key' => 'amortization_rate',
                        'value' => '3.0',
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                }

                sendResponse(false, 'Setting not found', null, 404);
            }

            $setting = $stmt->fetch(PDO::FETCH_ASSOC);
            sendResponse(true, 'Setting retrieved successfully', $setting);
        }
    }

    // ==================== POST REQUEST - UPDATE SETTINGS ====================
    elseif ($method === 'POST') {
        // ONLY ADMIN can modify settings
        if ($_SESSION['user_role'] !== 'admin') {
            sendResponse(false, 'Access denied. Only administrators can modify system settings.', null, 403);
        }

        // Get raw input
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            sendResponse(false, 'Invalid JSON data', null, 400);
        }

        // CSRF token validation for POST requests
        if (
            !isset($input['csrf_token']) ||
            !isset($_SESSION['csrf_token']) ||
            $input['csrf_token'] !== $_SESSION['csrf_token']
        ) {
            sendResponse(false, 'Invalid CSRF token. Please refresh the page and try again.', null, 403);
        }

        // Validate required fields
        if (empty($input['key']) || !isset($input['value'])) {
            sendResponse(false, 'Key and value are required', null, 400);
        }

        // Validate setting key (only allow specific keys)
$allowedKeys = [
    'amortization_rate',
    'system_name',
    'system_email',
    'notification_enabled',
    'backup_frequency',
    'user_theme_preference',           // ✅ ADD THIS LINE
    ];

    if (!in_array($input['key'], $allowedKeys)) {
        sendResponse(false, 'Invalid setting key: ' . $input['key'], null, 400);
    }
    // Validate value based on key
    switch ($input['key']) {
        case 'amortization_rate':
        case 'late_payment_penalty_rate':
            $value = floatval($input['value']);
            if ($value < 0 || $value > 100) {
                sendResponse(false, 'Rate must be between 0 and 100', null, 400);
            }
            $input['value'] = strval($value);
            break;
    
        case 'min_loan_amount':
        case 'max_loan_amount_mortgage':
        case 'max_loan_amount_car':
            $value = floatval($input['value']);
            if ($value < 0) {
                sendResponse(false, 'Amount must be positive', null, 400);
            }
            $input['value'] = strval($value);
            break;
    
        case 'min_payment_duration':
        case 'max_payment_duration':
        case 'grace_period_days':
            $value = intval($input['value']);
            if ($value < 0) {
                sendResponse(false, 'Duration must be positive', null, 400);
            }
            $input['value'] = strval($value);
            break;
    
        case 'maintenance_mode':
        case 'notification_enabled':
            $input['value'] = in_array(strtolower($input['value']), ['true', '1', 'yes', 'on']) ? '1' : '0';
            break;
    
        case 'system_email':
            if (!filter_var($input['value'], FILTER_VALIDATE_EMAIL)) {
                sendResponse(false, 'Invalid email address', null, 400);
            }
            break;
    
        // ✅ ADD THIS NEW CASE for theme validation
        case 'user_theme_preference':
            // Validate theme value
            $validThemes = ['light', 'dark'];
            if (!in_array($input['value'], $validThemes)) {
                sendResponse(false, 'Invalid theme. Must be either "light" or "dark"', null, 400);
            }
            break;
    }

        // Check if setting exists
        $query = "SELECT id FROM system_settings WHERE setting_key = :key";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':key', $input['key'], PDO::PARAM_STR);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            // Update existing setting
            $query = "UPDATE system_settings 
                      SET value = :value, 
                          updated_at = NOW(), 
                          updated_by = :updated_by 
                      WHERE setting_key = :key";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':key', $input['key'], PDO::PARAM_STR);
            $stmt->bindParam(':value', $input['value'], PDO::PARAM_STR);
            $stmt->bindParam(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);
        } else {
            // Create new setting
            $query = "INSERT INTO system_settings 
                      (setting_key, value, created_at, updated_at, created_by, updated_by) 
                      VALUES (:key, :value, NOW(), NOW(), :created_by, :updated_by)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':key', $input['key'], PDO::PARAM_STR);
            $stmt->bindParam(':value', $input['value'], PDO::PARAM_STR);
            $stmt->bindParam(':created_by', $_SESSION['user_id'], PDO::PARAM_INT);
            $stmt->bindParam(':updated_by', $_SESSION['user_id'], PDO::PARAM_INT);
        }

        if ($stmt->execute()) {
            // Log the action
            $logQuery = "INSERT INTO activity_log (admin_id, action, details, timestamp) 
                        VALUES (:admin_id, 'update_setting', :message, NOW())";
            $logStmt = $db->prepare($logQuery);
            $logMessage = "Updated setting: {$input['key']} to {$input['value']}";
            $logStmt->bindParam(':admin_id', $_SESSION['user_id'], PDO::PARAM_INT); // <-- Fixed
            $logStmt->bindParam(':message', $logMessage, PDO::PARAM_STR);
            $logStmt->execute();

            sendResponse(true, 'Setting saved successfully', [
                'setting_key' => $input['key'],
                'value' => $input['value'],
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        } else {
            sendResponse(false, 'Failed to save setting', null, 500);
        }
    }

    // ==================== METHOD NOT ALLOWED ====================
    else {
        sendResponse(false, 'Method not allowed', null, 405);
    }

} catch (PDOException $e) {
    error_log("Settings API database error: " . $e->getMessage());
    sendResponse(false, 'Database error occurred', null, 500);
} catch (Exception $e) {
    error_log("Settings API error: " . $e->getMessage());
    sendResponse(false, 'An error occurred: ' . $e->getMessage(), null, 500);
}

exit;
?>