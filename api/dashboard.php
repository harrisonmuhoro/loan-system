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
require_once '../models/LoanApplication.php';

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
        
        $loan = new LoanApplication($db);
        $stats = $loan->getStats();
        
        // Get funding accounts balance
        $query = "SELECT SUM(balance) as total_balance FROM funding_accounts";
        $stmt = $db->prepare($query);
        if ($stmt->execute()) {
            $funds = $stmt->fetch();
        }
        
        $dashboard_data = [
            'loan_stats' => $stats,
            'total_funds' => $funds['total_balance'] ?? 0,
            'user_role' => $_SESSION['role']
        ];
        
        ResponseHelper::success($dashboard_data);
        break;
        
    default:
        ResponseHelper::error('Method not allowed', 405);
}
?>