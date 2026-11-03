<?php
// Add this at the very top of all API files
error_reporting(0); // Disable error reporting for production
ini_set('display_errors', 0); // Don't display errors

// Start output buffering to catch any accidental output
ob_start();

// Set content type header before any output
header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../config/config.php';
require_once '../helpers/response_helper.php';

session_start();

 $database = new Database();
 $db = $database->getConnection();

 $method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Check if user is logged in
        if (empty($_SESSION['user_id'])) {
            ResponseHelper::unauthorized();
        }
        
        if (!in_array($_SESSION['role'], ['admin', 'accountant'])) {
            ResponseHelper::forbidden('Only admins and accountants can view funding accounts');
        }
        
        $query = "SELECT * FROM funding_accounts ORDER BY created_at DESC";
        $stmt = $db->prepare($query);
        $stmt->execute();
        
        $result = $stmt->get_result();
        $accounts = $result->fetch_all(MYSQLI_ASSOC);
        ResponseHelper::success($accounts);
        break;
        
    case 'POST':
        // Check if user is logged in
        if (empty($_SESSION['user_id'])) {
            ResponseHelper::unauthorized();
        }
        
        // Validate CSRF token
        $data = json_decode(file_get_contents("php://input"));
        if (empty($data->csrf_token) || !validateCSRFToken($data->csrf_token)) {
            ResponseHelper::forbidden('Invalid CSRF token');
        }
        
        if ($_SESSION['role'] !== 'admin') {
            ResponseHelper::forbidden('Only admins can create funding accounts');
        }
        
        if (!isset($data->account_name) || !isset($data->balance)) {
            ResponseHelper::error('Account name and balance are required');
        }
        
        $query = "INSERT INTO funding_accounts (account_name, balance) 
                 VALUES (:account_name, :balance)";
        
        $stmt = $db->prepare($query);
        $stmt->bind_param("sd", $data->account_name, $data->balance);
        
        if ($stmt->execute()) {
            ResponseHelper::success(['account_id' => $db->$insert_id], 'Funding account created successfully');
        } else {
            ResponseHelper::error('Failed to create funding account');
        }
        break;
        
    case 'PUT':
        // Check if user is logged in
        if (empty($_SESSION['user_id'])) {
            ResponseHelper::unauthorized();
        }
        
        // Validate CSRF token
        $data = json_decode(file_get_contents("php://input"));
        if (empty($data->csrf_token) || !validateCSRFToken($data->csrf_token)) {
            ResponseHelper::forbidden('Invalid CSRF token');
        }
        
        if (!in_array($_SESSION['role'], ['admin', 'accountant'])) {
            ResponseHelper::forbidden('Only admins and accountants can update funding accounts');
        }
        
        if (!isset($data->account_id)) {
            ResponseHelper::error('Account ID is required');
        }
        
        if (isset($data->topup_amount)) {
            // Top up account
            $query = "UPDATE funding_accounts 
                     SET balance = balance + :topup_amount, updated_at = NOW()
                     WHERE account_id = :account_id";
            
            $stmt = $db->prepare($query);
            $stmt = $db->prepare($query);
            $stmt->bind_param("di", $data->topup_amount, $data->account_id);
            if ($stmt->execute()) {
                ResponseHelper::success(null, 'Account topped up successfully');
            } else {
                ResponseHelper::error('Failed to top up account');
            }
        } else {
            ResponseHelper::error('Invalid operation');
        }
        break;
        
    case 'DELETE':
        // Check if user is logged in
        if (empty($_SESSION['user_id'])) {
            ResponseHelper::unauthorized();
        }
        
        // Validate CSRF token
        if (empty($_GET['csrf_token']) || !validateCSRFToken($_GET['csrf_token'])) {
            ResponseHelper::forbidden('Invalid CSRF token');
        }
        
        if ($_SESSION['role'] !== 'admin') {
            ResponseHelper::forbidden('Only admins can delete funding accounts');
        }
        
        if (!isset($_GET['account_id'])) {
            ResponseHelper::error('Account ID is required');
        }
        
        $query = "DELETE FROM funding_accounts WHERE account_id = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param("i", $_GET['account_id']);
        if ($stmt->execute()) {
            ResponseHelper::success(null, 'Funding account deleted successfully');
        } else {
            ResponseHelper::error('Failed to delete funding account');
        }
        break;
        
    default:
        ResponseHelper::error('Method not allowed', 405);
}
?>