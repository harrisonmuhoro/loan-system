<?php
// Strict error handling
error_reporting(0);
ini_set('display_errors', 0);
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
    ResponseHelper::forbidden('Only admins can view activity logs.');
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
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        
        // Get activity logs using actual column names
        // FIXED: Removed 'loan_id' column that doesn't exist in activity_log table
        $query = "SELECT id, action, details, admin_id, timestamp 
                  FROM activity_log 
                  ORDER BY timestamp DESC 
                  LIMIT :limit";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        ob_clean();
        ResponseHelper::success($activities, 'Activity logs retrieved successfully');
        
    } elseif ($method === 'POST') {
        // Get raw input
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            ob_clean();
            ResponseHelper::error('Invalid JSON data');
        }
        
        // Validate required fields
        if (empty($input['action']) || empty($input['details'])) {
            ob_clean();
            ResponseHelper::error('Action and details are required');
        }
        
        // Insert activity entry
        // FIXED: Removed 'loan_id' column that doesn't exist in activity_log table
        $query = "INSERT INTO activity_log (action, details, admin_id, timestamp) 
                  VALUES (:action, :details, :admin_id, NOW())";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':action', $input['action']);
        // FIXED: Removed binding for loan_id that doesn't exist
        $stmt->bindParam(':details', $input['details']);
        $stmt->bindParam(':admin_id', $_SESSION['user_id']);
        
        if ($stmt->execute()) {
            ob_clean();
            ResponseHelper::success(
                ['id' => $db->lastInsertId()], 
                'Activity logged successfully'
            );
        } else {
            ob_clean();
            ResponseHelper::error('Failed to log activity');
        }
        
    } elseif ($method === 'DELETE') {
        // Clear activity log
        $query = "DELETE FROM activity_log";
        $stmt = $db->prepare($query);
        
        if ($stmt->execute()) {
            ob_clean();
            ResponseHelper::success(null, 'Activity log cleared successfully');
        } else {
            ob_clean();
            ResponseHelper::error('Failed to clear activity log');
        }
        
    } else {
        ob_clean();
        ResponseHelper::error('Method not allowed', 405);
    }
    
} catch (PDOException $e) {
    error_log("Activity API PDO error: " . $e->getMessage());
    ob_clean();
    ResponseHelper::serverError('Database error: ' . $e->getMessage());
} catch (Exception $e) {
    error_log("Activity API error: " . $e->getMessage());
    ob_clean();
    ResponseHelper::serverError($e->getMessage());
}

exit();
?>