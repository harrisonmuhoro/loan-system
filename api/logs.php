<?php
// Strict error handling
error_reporting(0);
ini_set('display_errors', 0);

// Start output buffering
ob_start();

// Set JSON header immediately
header('Content-Type: application/json; charset=utf-8');

// Include required files
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response_helper.php';

// Start session
session_start();

// Verify session
if (!isset($_SESSION['user_id'])) {
    ob_clean();
    ResponseHelper::unauthorized('Unauthorized. Please login.');
}

// Verify admin role
if ($_SESSION['user_role'] !== 'admin') {
    ob_clean();
    ResponseHelper::forbidden('Only admins can view admin logs.');
}

// Initialize database
try {
    $database = new Database();
    $db = $database->getConnection();
} catch (Exception $e) {
    ob_clean();
    ResponseHelper::serverError('Database connection failed: ' . $e->getMessage());
}

// Handle request method
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
        
        // Get admin logs using correct column names: id, admin_id, action, details, timestamp
        $query = "SELECT id, admin_id as adminId, action, details, timestamp 
                  FROM admin_logs 
                  ORDER BY timestamp DESC 
                  LIMIT :limit";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        ob_clean();
        ResponseHelper::success($logs, 'Admin logs retrieved successfully');
        
    } elseif ($method === 'POST') {
        // Log an admin action
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            ob_clean();
            ResponseHelper::error('Invalid JSON data');
        }
        
        // Validate required fields
        if (empty($input['action'])) {
            ob_clean();
            ResponseHelper::error('Action is required');
        }
        
        // Insert log entry
        $query = "INSERT INTO admin_logs (admin_id, action, details, timestamp) 
                  VALUES (:admin_id, :action, :details, NOW())";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':admin_id', $_SESSION['user_id']);
        $stmt->bindParam(':action', $input['action']);
        $stmt->bindParam(':details', $input['details']);
        
        if ($stmt->execute()) {
            ob_clean();
            ResponseHelper::success(
                ['id' => $db->lastInsertId()], 
                'Admin action logged successfully'
            );
        } else {
            ob_clean();
            ResponseHelper::error('Failed to log admin action');
        }
        
    } elseif ($method === 'DELETE') {
        // Clear all admin logs
        $query = "DELETE FROM admin_logs";
        $stmt = $db->prepare($query);
        
        if ($stmt->execute()) {
            // Log this action before clearing
            $logQuery = "INSERT INTO admin_logs (admin_id, action, details, timestamp) 
                         VALUES (:admin_id, 'CLEAR_LOGS', 'All admin logs cleared', NOW())";
            $logStmt = $db->prepare($logQuery);
            $logStmt->bindParam(':admin_id', $_SESSION['user_id']);
            $logStmt->execute();
            
            ob_clean();
            ResponseHelper::success(null, 'Admin logs cleared successfully');
        } else {
            ob_clean();
            ResponseHelper::error('Failed to clear admin logs');
        }
        
    } else {
        ob_clean();
        ResponseHelper::error('Method not allowed', 405);
    }
    
} catch (PDOException $e) {
    error_log("Logs API PDO error: " . $e->getMessage());
    ob_clean();
    ResponseHelper::serverError('Database error: ' . $e->getMessage());
} catch (Exception $e) {
    error_log("Logs API error: " . $e->getMessage());
    ob_clean();
    ResponseHelper::serverError($e->getMessage());
}

exit();
?>