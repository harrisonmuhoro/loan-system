<?php
require_once '../config/database.php';
require_once '../helpers/response_helper.php';

session_start();

// Check if user is logged in
if (empty($_SESSION['user_id'])) {
    ResponseHelper::unauthorized();
}

// Only allow staff roles to switch
 $allowedRoles = ['admin', 'cashier', 'accountant'];
if (!in_array($_SESSION['user_role'], $allowedRoles)) {
    ResponseHelper::forbidden('Only staff members can switch portals');
}

 $database = new Database();
 $db = $database->getConnection();

 $method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'POST':
        // Get raw input and decode JSON
        $rawInput = file_get_contents("php://input");
        $data = json_decode($rawInput);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            ResponseHelper::error('Invalid JSON data: ' . json_last_error_msg());
        }
        
        // Validate CSRF token
        if (empty($data->csrf_token) || !isset($_SESSION['csrf_token']) || $data->csrf_token !== $_SESSION['csrf_token']) {
            ResponseHelper::forbidden('Invalid CSRF token');
        }
        
        // Store original portal info
        $_SESSION['original_portal'] = $_SESSION['user_role'];
        $_SESSION['original_role'] = $_SESSION['user_role'];
        
        // Set flags for loanee portal
        $_SESSION['viewing_as_loanee'] = true;
        $_SESSION['from_staff_portal'] = true;
        $_SESSION['switching_to_loanee'] = true;
        
        // Update staff portal access permission
        try {
            $stmt = $db->prepare("UPDATE users SET can_apply_for_loan = 1 WHERE user_id = :user_id");
            $stmt->bindParam(':user_id', $_SESSION['user_id']);
            $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error updating staff portal access: " . $e->getMessage());
        }
        
        // Log activity
        try {
            $stmt = $db->prepare("INSERT INTO activity_log (admin_id, action, details, timestamp) 
                                 VALUES (:admin_id, :action, :details, NOW())");
            $stmt->bindParam(':admin_id', $_SESSION['user_id']);
            $stmt->bindParam(':action', 'switch_to_loanee');
            $stmt->bindParam(':details', "Staff {$_SESSION['full_name']} switched to loanee portal");
            $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error logging activity: " . $e->getMessage());
        }
        
        ResponseHelper::success([
            'message' => 'Successfully prepared to switch to loanee portal',
            'redirect_url' => 'loanee.php?from=' . $_SESSION['user_role'] . '&action=switch_portal'
        ]);
        break;
        
    default:
        ResponseHelper::error('Method not allowed');
        break;
}
?>