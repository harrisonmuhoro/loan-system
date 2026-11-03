<?php
/**
 * System Settings API
 * Handles system settings with role-based access control
 * - Admin: Full read/write access
 * - Cashier & Accountant: Read-only access
 */

// Strict error handling
error_reporting(0);
ini_set('display_errors', 0);

// Start output buffering
ob_start();

// Set JSON content type
header('Content-Type: application/json; charset=utf-8');
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
    sendResponse(false, 'Database connection failed: ' . $e->getMessage(), null, 500);
}

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

try {
    // ==================== GET REQUEST - READ SETTINGS ====================
    if ($method === 'GET') {
        // Allow admin, cashier, and accountant to READ settings
        $allowedRoles = ['admin', 'cashier', 'accountant'];

        if (!in_array($_SESSION['user_role'], $allowedRoles)) {
            sendResponse(false, 'Access denied. You do not have permission to view settings.', null, 403);
        }

        $key = $_GET['key'] ?? '';

        if (empty($key)) {
            // Get all settings
            $query = "SELECT id, setting_key, value, created_by, updated_by, created_at, updated_at 
                      FROM system_settings 
                      ORDER BY setting_key";
            $stmt = $db->prepare($query);
            $stmt->execute();
            $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);

            sendResponse(true, 'Settings retrieved successfully', $settings);
        } else {
            // Get specific setting
            $query = "SELECT id, setting_key, value, created_by, updated_by, created_at, updated_at 
                      FROM system_settings 
                      WHERE setting_key = :key";
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
        error_log("Settings API - Raw Input: " . $rawInput);

        $input = json_decode($rawInput, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Settings API - JSON Error: " . json_last_error_msg());
            sendResponse(false, 'Invalid JSON data: ' . json_last_error_msg(), null, 400);
        }

        error_log("Settings API - Parsed Input: " . print_r($input, true));

        // CSRF token validation for POST requests
        if (
            !isset($input['csrf_token']) ||
            !isset($_SESSION['csrf_token']) ||
            $input['csrf_token'] !== $_SESSION['csrf_token']
        ) {
            error_log("Settings API - CSRF Token Mismatch");
            sendResponse(false, 'Invalid CSRF token. Please refresh the page and try again.', null, 403);
        }

        // Validate required fields
        if (empty($input['key']) || !isset($input['value'])) {
            error_log("Settings API - Missing key or value");
            sendResponse(false, 'Key and value are required', null, 400);
        }

        // Validate setting key (only allow specific keys)
        $allowedKeys = [
            'amortization_rate',
            'system_name',
            'system_email',
            'notification_enabled',
            'backup_frequency',
            'late_payment_penalty_rate',
            'min_loan_amount',
            'max_loan_amount_mortgage',
            'max_loan_amount_car',
            'min_payment_duration',
            'max_payment_duration',
            'grace_period_days',
            'maintenance_mode'
        ];

        if (!in_array($input['key'], $allowedKeys)) {
            error_log("Settings API - Invalid key: " . $input['key']);
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
        }

        error_log("Settings API - Validated value: " . $input['value']);

        // Check if setting exists
        $query = "SELECT id FROM system_settings WHERE setting_key = :key";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':key', $input['key'], PDO::PARAM_STR);
        $stmt->execute();

        $user_id = $_SESSION['user_id'];

        if ($stmt->rowCount() > 0) {
            // Update existing setting
            $query = "UPDATE system_settings 
                      SET value = :value, 
                          updated_by = :updated_by, 
                          updated_at = NOW() 
                      WHERE setting_key = :key";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':key', $input['key'], PDO::PARAM_STR);
            $stmt->bindParam(':value', $input['value'], PDO::PARAM_STR);
            $stmt->bindParam(':updated_by', $user_id, PDO::PARAM_INT);

            error_log("Settings API - Updating existing setting");
        } else {
            // Create new setting
            $query = "INSERT INTO system_settings 
                      (setting_key, value, created_by, updated_by, created_at, updated_at) 
                      VALUES (:key, :value, :created_by, :updated_by, NOW(), NOW())";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':key', $input['key'], PDO::PARAM_STR);
            $stmt->bindParam(':value', $input['value'], PDO::PARAM_STR);
            $stmt->bindParam(':created_by', $user_id, PDO::PARAM_INT);
            $stmt->bindParam(':updated_by', $user_id, PDO::PARAM_INT);

            error_log("Settings API - Creating new setting");
        }

        if ($stmt->execute()) {
            error_log("Settings API - Successfully saved setting");

            // Log the action in activity_log
            try {
                $logQuery = "INSERT INTO activity_log (admin_id, action, details, timestamp) 
                             VALUES (:admin_id, :action, :details, NOW())";
                $logStmt = $db->prepare($logQuery);
                $action = 'update_setting';
                $details = "Updated setting: {$input['key']} to {$input['value']}";
                $logStmt->bindParam(':admin_id', $user_id, PDO::PARAM_INT);
                $logStmt->bindParam(':action', $action, PDO::PARAM_STR);
                $logStmt->bindParam(':details', $details, PDO::PARAM_STR);
                $logStmt->execute();
                error_log("Settings API - Activity logged successfully");
            } catch (PDOException $e) {
                error_log("Settings API - Failed to log activity: " . $e->getMessage());
            }

            sendResponse(true, 'Setting saved successfully', [
                'setting_key' => $input['key'],
                'value' => $input['value'],
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        } else {
            error_log("Settings API - Failed to execute statement: " . print_r($stmt->errorInfo(), true));
            sendResponse(false, 'Failed to save setting', null, 500);
        }
    }

    // ==================== METHOD NOT ALLOWED ====================
    else {
        sendResponse(false, 'Method not allowed', null, 405);
    }

} catch (PDOException $e) {
    error_log("Settings API database error: " . $e->getMessage());
    error_log("Settings API error info: " . print_r($e->errorInfo, true));
    sendResponse(false, 'Database error occurred: ' . $e->getMessage(), null, 500);
} catch (Exception $e) {
    error_log("Settings API error: " . $e->getMessage());
    sendResponse(false, 'An error occurred: ' . $e->getMessage(), null, 500);
}

exit;
