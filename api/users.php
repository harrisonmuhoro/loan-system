<?php
ob_start();
header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../config/config.php';
require_once '../helpers/response_helper.php';
require_once '../models/User.php';

session_start();

$database = new Database();
$db = $database->getConnection();
$user = new User($db);
$method = $_SERVER['REQUEST_METHOD'];

/**
 * Enhanced password hashing with explicit cost factor
 * PHP's password_hash already includes automatic salt generation
 * Cost factor of 12 provides strong security (higher = more secure but slower)
 */
function hashPasswordSecure($password) {
    $options = [
        'cost' => 12, // Computational cost (4-31, default is 10)
    ];
    return password_hash($password, PASSWORD_BCRYPT, $options);
}

/**
 * Verify password against hash
 */
function verifyPasswordSecure($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * Check if password needs rehashing (security upgrade)
 */
function needsRehash($hash) {
    $options = ['cost' => 12];
    return password_needs_rehash($hash, PASSWORD_BCRYPT, $options);
}

switch ($method) {
    case 'GET':
        // Check if user is logged in
        if (empty($_SESSION['user_id'])) {
            ResponseHelper::unauthorized();
        }
        
        // Only admins can view all users
        if ($_SESSION['user_role'] !== 'admin') {
            ResponseHelper::forbidden('Only admins can view all users');
        }
        
        // Handle single user fetch for editing
        if (isset($_GET['user_id'])) {
            $user_id = $_GET['user_id'];
            
            if ($user_id <= 0 || !is_numeric($user_id)) {
                ResponseHelper::error('Invalid user ID');
            }
            
            $query = "SELECT user_id, full_name, email, id_number, pf_number, role, is_active, can_apply_for_loan, created_at, updated_at 
                      FROM users WHERE user_id = :user_id LIMIT 1";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                // Format response
                $formattedUser = [
                    'user_id' => (int)$user['user_id'],
                    'full_name' => $user['full_name'],
                    'email' => $user['email'],
                    'id_number' => $user['id_number'],
                    'pf_number' => $user['pf_number'],
                    'role' => $user['role'],
                    'is_active' => (int)$user['is_active'],
                    'can_apply_for_loan' => (bool)$user['can_apply_for_loan'],
                    'created_at' => $user['created_at'],
                    'updated_at' => $user['updated_at']
                ];
                ResponseHelper::success($formattedUser);
            } else {
                ResponseHelper::notFound('User not found');
            }
            exit;
        }
        
        // Get parameters for listing all users
        $role = isset($_GET['role']) ? $_GET['role'] : null;
        $status = isset($_GET['status']) ? $_GET['status'] : null;
        $search = isset($_GET['search']) ? trim($_GET['search']) : null;
        
        // Build base query
        $query = "SELECT user_id, full_name, email, id_number, pf_number, role, created_at, updated_at, is_active, can_apply_for_loan 
                  FROM users WHERE 1=1";
        
        $conditions = [];
        $params = [];
        
        // Add role filter
        if ($role && $role !== '') {
            $conditions[] = "role = :role";
            $params[':role'] = $role;
        }
        
        // Add status filter
        if ($status !== null && $status !== '') {
            $conditions[] = "is_active = :status";
            $params[':status'] = $status;
        }
        
        // Add search condition
        if ($search && $search !== '') {
            $conditions[] = "(full_name LIKE :search OR email LIKE :search OR pf_number LIKE :search OR id_number LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }
        
        // Add conditions to query
        if (!empty($conditions)) {
            $query .= " AND " . implode(' AND ', $conditions);
        }
        
        $query .= " ORDER BY created_at DESC";
        
        $stmt = $db->prepare($query);
        
        // Bind parameters
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format response data
        $formattedUsers = [];
        foreach ($users as $userRow) {
            $formattedUsers[] = [
                'user_id' => (int)$userRow['user_id'],
                'full_name' => $userRow['full_name'],
                'email' => $userRow['email'],
                'id_number' => $userRow['id_number'],
                'pf_number' => $userRow['pf_number'],
                'role' => $userRow['role'],
                'is_active' => (int)$userRow['is_active'],
                'can_apply_for_loan' => (bool)$userRow['can_apply_for_loan'],
                'created_at' => $userRow['created_at'],
                'updated_at' => $userRow['updated_at']
            ];
        }
        
        ResponseHelper::success($formattedUsers);
        break;

        
    case 'POST':
        // Check if user is logged in
        if (empty($_SESSION['user_id'])) {
            ResponseHelper::unauthorized();
        }
        
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
        
        // Handle switching to loanee portal
        if (isset($data->action) && $data->action === 'switch_to_loanee_portal') {
            $allowedRoles = ['admin', 'cashier', 'accountant'];
            if (!in_array($_SESSION['user_role'], $allowedRoles)) {
                ResponseHelper::forbidden('You do not have permission to access the loanee portal');
            }
            
            // Store original portal info
            if (!isset($_SESSION['original_portal'])) {
                $_SESSION['original_portal'] = strtolower($_SESSION['user_role']);
                $_SESSION['original_role'] = $_SESSION['user_role'];
            }
            
            // Set flags for loanee view
            $_SESSION['viewing_as_loanee'] = true;
            $_SESSION['from_staff_portal'] = true;
            
            // Log activity
            try {
                $stmt = $db->prepare("INSERT INTO activity_log (admin_id, action, details, timestamp) 
                                     VALUES (:admin_id, :action, :details, NOW())");
                $stmt->bindParam(':admin_id', $_SESSION['user_id']);
                $action = 'switch_to_loanee_portal';
                $stmt->bindParam(':action', $action);
                $details = "Staff {$_SESSION['full_name']} switched to loanee portal";
                $stmt->bindParam(':details', $details);
                $stmt->execute();
            } catch (PDOException $e) {
                error_log("Failed to log activity: " . $e->getMessage());
            }
            
            ResponseHelper::success([
                'message' => 'Switching to loanee portal',
                'redirect_url' => 'loanee.php?from=' . strtolower($_SESSION['user_role']) . '&action=switch_portal'
            ]);
        }
        // Handle returning to staff portal
        else if (isset($data->action) && $data->action === 'return_to_staff_portal') {
            if (!isset($_SESSION['from_staff_portal'])) {
                ResponseHelper::forbidden('Not authorized to return to staff portal');
            }
            
            $originalPortal = $_SESSION['original_portal'] ?? 'index';
            
            // Clear loanee flags
            unset($_SESSION['from_staff_portal']);
            unset($_SESSION['viewing_as_loanee']);
            unset($_SESSION['switching_to_loanee']);
            
            // Log activity
            try {
                $stmt = $db->prepare("INSERT INTO activity_log (admin_id, action, details, timestamp) 
                                     VALUES (:admin_id, :action, :details, NOW())");
                $stmt->bindParam(':admin_id', $_SESSION['user_id']);
                $action = 'return_to_staff_portal';
                $stmt->bindParam(':action', $action);
                $details = "Staff {$_SESSION['full_name']} returned to staff portal";
                $stmt->bindParam(':details', $details);
                $stmt->execute();
            } catch (PDOException $e) {
                error_log("Failed to log activity: " . $e->getMessage());
            }
            
            $redirectUrl = match($originalPortal) {
                'admin' => 'admin.php?returned_from_loanee=1',
                'cashier' => 'cashier.php?returned_from_loanee=1',
                'accountant' => 'accountant.php?returned_from_loanee=1',
                default => 'index.php'
            };
            
            ResponseHelper::success([
                'message' => 'Returning to staff portal',
                'redirect_url' => $redirectUrl
            ]);
        }
        
        // Handle user creation (admin only)
        else {
            // Only admins can create users
            if ($_SESSION['user_role'] !== 'admin') {
                ResponseHelper::forbidden('Only admins can create users');
            }
            
            // Required fields
            $required_fields = ['name', 'pfNumber', 'idNumber', 'email', 'password', 'role'];
            
            foreach ($required_fields as $field) {
                if (!isset($data->$field) || (is_string($data->$field) && trim($data->$field) === '')) {
                    ResponseHelper::error("$field is required");
                }
            }
            
            // Validate role
            $allowed_roles = ['admin', 'cashier', 'accountant', 'loanee'];
            if (!in_array($data->role, $allowed_roles)) {
                ResponseHelper::error('Invalid role');
            }
            
            // Validate PF number format
            if (!preg_match('/^\d{9,11}$/', $data->pfNumber)) {
                ResponseHelper::error('PF number must be 9-11 digits');
            }
            
            // Enhanced password validation
            if (strlen($data->password) < 8) {
                ResponseHelper::error('Password must be at least 8 characters long');
            }
            
            // Additional password strength checks
            if (!preg_match('/[A-Z]/', $data->password)) {
                ResponseHelper::error('Password must contain at least one uppercase letter');
            }
            if (!preg_match('/[a-z]/', $data->password)) {
                ResponseHelper::error('Password must contain at least one lowercase letter');
            }
            if (!preg_match('/[0-9]/', $data->password)) {
                ResponseHelper::error('Password must contain at least one number');
            }
            
            // Check for duplicates
            if ($user->getByEmail($data->email)) {
                ResponseHelper::error('Email already exists');
            }
            
            if ($user->getByPfNumber($data->pfNumber)) {
                ResponseHelper::error('PF number already exists');
            }
            
            if ($user->getByIdNumber($data->idNumber)) {
                ResponseHelper::error('ID number already exists');
            }
            
            // Create user with enhanced password hashing
            $user->full_name = trim($data->name);
            $user->pf_number = trim($data->pfNumber);
            $user->id_number = trim($data->idNumber);
            $user->password = hashPasswordSecure($data->password); // Using enhanced hashing
            $user->email = trim($data->email);
            $user->role = $data->role;
            
            if ($user->create()) {
                // Log activity
                try {
                    $stmt = $db->prepare("INSERT INTO activity_log (admin_id, action, details, timestamp) 
                                         VALUES (:admin_id, :action, :details, NOW())");
                    $stmt->bindParam(':admin_id', $_SESSION['user_id']);
                    $action = 'add_user';
                    $stmt->bindParam(':action', $action);
                    $details = "Admin {$_SESSION['full_name']} added new user: {$user->full_name} ({$user->email})";
                    $stmt->bindParam(':details', $details);
                    $stmt->execute();
                } catch (PDOException $e) {
                    error_log("Failed to log activity: " . $e->getMessage());
                }
                
                ResponseHelper::success([
                    'user_id' => $user->user_id,
                    'full_name' => $user->full_name,
                    'email' => $user->email,
                    'role' => $user->role
                ], 'User created successfully');
            } else {
                ResponseHelper::error('Failed to create user');
            }
        }
        break;
        
    case 'PUT':
        // Check if user is logged in
        if (empty($_SESSION['user_id'])) {
            ResponseHelper::unauthorized();
        }
        
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
        
        // Only admins can update users
        if ($_SESSION['user_role'] !== 'admin') {
            ResponseHelper::forbidden('Only admins can update users');
        }
    
        $user_id = $_GET['user_id'] ?? '';
    
        if (empty($user_id)) {
            ResponseHelper::error('User ID is required');
        }
    
        // Get existing user
        $query = "SELECT * FROM users WHERE user_id = :user_id LIMIT 1";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
        if (!$existingUser) {
            ResponseHelper::notFound('User not found');
        }
        
        // Build update query
        $updateFields = [];
        $params = [':user_id' => $user_id];
    
        if (isset($data->name)) {
            $updateFields[] = "full_name = :full_name";
            $params[':full_name'] = trim($data->name);
        }
    
        if (isset($data->pfNumber)) {
            if (!preg_match('/^\d{9,11}$/', $data->pfNumber)) {
                ResponseHelper::error('PF number must be 9-11 digits');
            }
            $updateFields[] = "pf_number = :pf_number";
            $params[':pf_number'] = trim($data->pfNumber);
        }
    
        if (isset($data->idNumber)) {
            $updateFields[] = "id_number = :id_number";
            $params[':id_number'] = trim($data->idNumber);
        }
    
        if (isset($data->email)) {
            $updateFields[] = "email = :email";
            $params[':email'] = trim($data->email);
        }
    
        if (isset($data->role)) {
            $allowed_roles = ['admin', 'cashier', 'accountant', 'loanee'];
            if (!in_array($data->role, $allowed_roles)) {
                ResponseHelper::error('Invalid role');
            }
            $updateFields[] = "role = :role";
            $params[':role'] = $data->role;
        }
    
        if (isset($data->can_apply_for_loan)) {
            $updateFields[] = "can_apply_for_loan = :can_apply_for_loan";
            $params[':can_apply_for_loan'] = $data->can_apply_for_loan ? 1 : 0;
        }

        if (isset($data->is_active) && !$data->is_active && $user_id == $_SESSION['user_id']) {
            ResponseHelper::forbidden('You cannot deactivate your own account');
        }
        
        // Handle is_active toggle
        if (isset($data->is_active)) {
            $updateFields[] = "is_active = :is_active";
            $params[':is_active'] = $data->is_active ? 1 : 0;
        }
        
        // Handle password update with enhanced security
        if (isset($data->password) && !empty($data->password)) {
            // Enhanced password validation
            if (strlen($data->password) < 8) {
                ResponseHelper::error('Password must be at least 8 characters long');
            }
            if (!preg_match('/[A-Z]/', $data->password)) {
                ResponseHelper::error('Password must contain at least one uppercase letter');
            }
            if (!preg_match('/[a-z]/', $data->password)) {
                ResponseHelper::error('Password must contain at least one lowercase letter');
            }
            if (!preg_match('/[0-9]/', $data->password)) {
                ResponseHelper::error('Password must contain at least one number');
            }
            
            $updateFields[] = "password = :password";
            $params[':password'] = hashPasswordSecure($data->password); // Using enhanced hashing
        }

        if (empty($updateFields)) {
            ResponseHelper::error('No fields to update');
        }

        $updateFields[] = "updated_at = NOW()";

        $updateQuery = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE user_id = :user_id";
        $stmt = $db->prepare($updateQuery);

        if ($stmt->execute($params)) {
            // Log activity
            try {
                $logStmt = $db->prepare("INSERT INTO activity_log (admin_id, action, details, timestamp) 
                                        VALUES (:admin_id, :action, :details, NOW())");
                $logStmt->bindParam(':admin_id', $_SESSION['user_id']);
                $action = 'update_user';
                $logStmt->bindParam(':action', $action);
                $details = "Admin {$_SESSION['full_name']} updated user ID: {$user_id}";
                $logStmt->bindParam(':details', $details);
                $logStmt->execute();
            } catch (PDOException $e) {
                error_log("Failed to log activity: " . $e->getMessage());
            }

            ResponseHelper::success(null, 'User updated successfully');
        } else {
            ResponseHelper::error('Failed to update user');
        }
        break;
        
    case 'DELETE':
        // Check if user is logged in
        if (empty($_SESSION['user_id'])) {
            ResponseHelper::unauthorized();
        }
        
        // Only admins can delete users
        if ($_SESSION['user_role'] !== 'admin') {
            ResponseHelper::forbidden('Only admins can delete users');
        }
        
        $user_id = $_GET['user_id'] ?? '';
        
        if (empty($user_id)) {
            ResponseHelper::error('User ID is required');
        }
        
        // Get user
        $query = "SELECT * FROM users WHERE user_id = :user_id LIMIT 1";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$existingUser) {
            ResponseHelper::notFound('User not found');
        }
        
        // Prevent self-deletion
        if ($user_id == $_SESSION['user_id']) {
            ResponseHelper::forbidden('You cannot delete your own account');
        }
        
        // Check for active loans
        $loanCheckQuery = "SELECT COUNT(*) as loan_count FROM loan_applications 
                          WHERE user_id = :user_id AND status IN ('active', 'dispatched')";
        $loanStmt = $db->prepare($loanCheckQuery);
        $loanStmt->bindParam(':user_id', $user_id);
        $loanStmt->execute();
        $loanResult = $loanStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($loanResult['loan_count'] > 0) {
            ResponseHelper::error('Cannot delete user with active loans');
        }
        
        // Delete user
        $deleteQuery = "DELETE FROM users WHERE user_id = :user_id";
        $stmt = $db->prepare($deleteQuery);
        $stmt->bindParam(':user_id', $user_id);
        
        if ($stmt->execute()) {
            // Log activity
            try {
                $logStmt = $db->prepare("INSERT INTO activity_log (admin_id, action, details, timestamp) 
                                        VALUES (:admin_id, :action, :details, NOW())");
                $logStmt->bindParam(':admin_id', $_SESSION['user_id']);
                $action = 'delete_user';
                $logStmt->bindParam(':action', $action);
                $details = "Admin {$_SESSION['full_name']} deleted user: {$existingUser['full_name']}";
                $logStmt->bindParam(':details', $details);
                $logStmt->execute();
            } catch (PDOException $e) {
                error_log("Failed to log activity: " . $e->getMessage());
            }
            
            ResponseHelper::success(null, 'User deleted successfully');
        } else {
            ResponseHelper::error('Failed to delete user');
        }
        break;
        
    default:
        ResponseHelper::error('Method not allowed', 405);
}
?>