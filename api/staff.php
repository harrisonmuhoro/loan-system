<?php
// Add this at the very top of all API files
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);

// Start output buffering to catch any accidental output
ob_start();

// Set content type header before any output
header('Content-Type: application/json');

// Include required files
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../helpers/response_helper.php';

// Start session
session_start();

// Verify session
if (!isset($_SESSION['user_id'])) {
    ob_clean();
    ResponseHelper::unauthorized('Unauthorized. Please login.');
    exit();
}

// Verify admin role
if ($_SESSION['user_role'] !== 'admin') {
    ob_clean();
    ResponseHelper::forbidden('Only admins can manage staff.');
    exit();
}

// Initialize database and user model
try {
    $database = new Database();
    $db = $database->getConnection();
    $user = new User($db);
} catch (Exception $e) {
    ob_clean();
    ResponseHelper::serverError('Database connection failed: ' . $e->getMessage());
    exit();
}

// Function to log staff updates
function logStaffUpdate($db, $staffId, $updateType, $updateData)
{
    try {
        $query = "INSERT INTO staff_updates (staff_id, updated_by, update_type, field_name, old_value, new_value, update_reason, update_date) 
                 VALUES (:staff_id, :updated_by, :update_type, :field_name, :old_value, :new_value, :update_reason, NOW())";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':staff_id', $staffId);
        $stmt->bindParam(':updated_by', $_SESSION['user_id']);
        $stmt->bindParam(':update_type', $updateType);
        $stmt->bindParam(':field_name', $updateData['field_name']);
        $stmt->bindParam(':old_value', $updateData['old_value']);
        $stmt->bindParam(':new_value', $updateData['new_value']);
        $stmt->bindParam(':update_reason', $updateData['update_reason']);
        $stmt->execute();
        return true;
    } catch (Exception $e) {
        error_log("Failed to log staff update: " . $e->getMessage());
        return false;
    }
}

// Handle request method
 $method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        // Get all staff from staff table
        $query = "SELECT s.*, u.role FROM staff s JOIN users u ON s.user_id = u.user_id ORDER BY s.created_at DESC";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $staff = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        ob_clean();
        ResponseHelper::success($staff);
        
    } elseif ($method === 'POST') {
        // Get raw input
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            ob_clean();
            ResponseHelper::error('Invalid JSON data: ' . json_last_error_msg());
            exit();
        }
        
        // Verify CSRF token
        if (!isset($input['csrf_token']) || !isset($_SESSION['csrf_token']) || 
            $input['csrf_token'] !== $_SESSION['csrf_token']) {
            ob_clean();
            ResponseHelper::forbidden('Invalid CSRF token');
            exit();
        }
        
        // Validate required fields
        $required = ['name', 'pfNumber', 'idNumber', 'email', 'password', 'role'];
        
        $missing = [];
        foreach ($required as $field) {
            if (!isset($input[$field]) || (is_string($input[$field]) && trim($input[$field]) === '')) {
                $missing[] = $field;
            }
        }
        
        if (!empty($missing)) {
            ob_clean();
            ResponseHelper::error("Missing required fields: " . implode(', ', $missing));
            exit();
        }
        
        // Validate role
        $validRoles = ['cashier', 'accountant', 'admin'];
        if (!in_array($input['role'], $validRoles)) {
            ob_clean();
            ResponseHelper::error('Invalid role. Must be one of: ' . implode(', ', $validRoles));
            exit();
        }
        
        // Start transaction
        $db->beginTransaction();
        
        try {
            // Check if email already exists
            $query = "SELECT user_id FROM users WHERE email = :email";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':email', $input['email']);
            $stmt->execute();

            if ($stmt->fetch(PDO::FETCH_ASSOC)) {
                $db->rollBack();
                ob_clean();
                ResponseHelper::error('Email already exists');
                exit();
            }
            
            // Add new staff to users table
            $hashed_password = password_hash($input['password'], PASSWORD_DEFAULT);
            $query = "INSERT INTO users (full_name, email, id_number, pf_number, password, role, created_at) 
                     VALUES (:full_name, :email, :id_number, :pf_number, :password, :role, NOW())";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':full_name', $input['name']);
            $stmt->bindParam(':email', $input['email']);
            $stmt->bindParam(':id_number', $input['idNumber']);
            $stmt->bindParam(':pf_number', $input['pfNumber']);
            $stmt->bindParam(':password', $hashed_password);
            $stmt->bindParam(':role', $input['role']);

            if ($stmt->execute()) {
                $user_id = $db->lastInsertId();
                
                // Add to staff table
                $query = "INSERT INTO staff (user_id, pf_number, full_name, email, id_number, password, role, created_by, created_at) 
                         VALUES (:user_id, :pf_number, :full_name, :email, :id_number, :password, :role, :created_by, NOW())";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':user_id', $user_id);
                $stmt->bindParam(':pf_number', $input['pfNumber']);
                $stmt->bindParam(':full_name', $input['name']);
                $stmt->bindParam(':email', $input['email']);
                $stmt->bindParam(':id_number', $input['idNumber']);
                $stmt->bindParam(':password', $hashed_password);
                $stmt->bindParam(':role', $input['role']);
                $stmt->bindParam(':created_by', $_SESSION['user_id']);
                
                if ($stmt->execute()) {
                    $staff_id = $db->lastInsertId();
                    
                    // Add to role-specific table
                    $role_table = $input['role'] . 's'; // cashier -> cashiers, etc.
                    $query = "INSERT INTO {$role_table} (pf_number, full_name, email, password_hash, role, is_active, created_at) 
                             VALUES (:pf_number, :full_name, :email, :password_hash, :role, 1, NOW())";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':pf_number', $input['pfNumber']);
                    $stmt->bindParam(':full_name', $input['name']);
                    $stmt->bindParam(':email', $input['email']);
                    $stmt->bindParam(':password_hash', $hashed_password);
                    $stmt->bindParam(':role', $input['role']);
                    
                    if ($stmt->execute()) {
                        // Log staff creation
                        $updateData = [
                            'field_name' => 'staff_creation',
                            'old_value' => null,
                            'new_value' => json_encode([
                                'name' => $input['name'],
                                'pf_number' => $input['pfNumber'],
                                'id_number' => $input['idNumber'],
                                'email' => $input['email'],
                                'role' => $input['role']
                            ]),
                            'update_reason' => 'New staff member created'
                        ];
                        logStaffUpdate($db, $staff_id, 'create', $updateData);
                        
                        $db->commit();
                        ob_clean();
                        ResponseHelper::success(null, 'Staff added successfully');
                    } else {
                        $db->rollBack();
                        ob_clean();
                        ResponseHelper::error('Failed to add staff to role table');
                    }
                } else {
                    $db->rollBack();
                    ob_clean();
                    ResponseHelper::error('Failed to add staff to staff table');
                }
            } else {
                $db->rollBack();
                ob_clean();
                ResponseHelper::error('Failed to add staff to users table');
            }
        } catch (Exception $e) {
            $db->rollBack();
            ob_clean();
            ResponseHelper::error('Failed to add staff: ' . $e->getMessage());
        }
        
    } elseif ($method === 'PUT') {
        // Get raw input
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            ob_clean();
            ResponseHelper::error('Invalid JSON data: ' . json_last_error_msg());
            exit();
        }
        
        // Verify CSRF token
        if (!isset($input['csrf_token']) || !isset($_SESSION['csrf_token']) || 
            $input['csrf_token'] !== $_SESSION['csrf_token']) {
            ob_clean();
            ResponseHelper::forbidden('Invalid CSRF token');
            exit();
        }
        
        // Use user_id for identification
        $user_id = $_GET['user_id'] ?? '';
        
        if (empty($user_id)) {
            ob_clean();
            ResponseHelper::error('User ID is required');
            exit();
        }
        
        // Get current staff data
        $query = "SELECT s.*, u.role, u.password FROM staff s JOIN users u ON s.user_id = u.user_id WHERE s.user_id = :user_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $current_staff = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$current_staff) {
            ob_clean();
            ResponseHelper::notFound('Staff member not found');
            exit();
        }
        
        // Start transaction
        $db->beginTransaction();
        
        try {
            // Update users table
            $query = "UPDATE users SET full_name = :full_name, email = :email, id_number = :id_number, 
                     pf_number = :pf_number, role = :role, updated_at = NOW()";

            // Only update password if provided
            if (!empty($input['password'])) {
                $hashed_password = password_hash($input['password'], PASSWORD_DEFAULT);
                $query .= ", password = :password";
            }

            $query .= " WHERE user_id = :user_id";

            $stmt = $db->prepare($query);
            $stmt->bindParam(':full_name', $input['name']);
            $stmt->bindParam(':email', $input['email']);
            $stmt->bindParam(':id_number', $input['idNumber']);
            $stmt->bindParam(':pf_number', $input['pfNumber']);
            $stmt->bindParam(':role', $input['role']);
            $stmt->bindParam(':user_id', $user_id);

            if (!empty($input['password'])) {
                $stmt->bindParam(':password', $hashed_password);
            }

            if ($stmt->execute()) {
                // Update staff table
                $query = "UPDATE staff SET full_name = :full_name, email = :email, id_number = :id_number, 
                         pf_number = :pf_number, role = :role, updated_by = :updated_by, updated_at = NOW()";

                // Only update password if provided
                if (!empty($input['password'])) {
                    $query .= ", password = :password";
                }

                $query .= " WHERE user_id = :user_id";

                $stmt = $db->prepare($query);
                $stmt->bindParam(':full_name', $input['name']);
                $stmt->bindParam(':email', $input['email']);
                $stmt->bindParam(':id_number', $input['idNumber']);
                $stmt->bindParam(':pf_number', $input['pfNumber']);
                $stmt->bindParam(':role', $input['role']);
                $stmt->bindParam(':updated_by', $_SESSION['user_id']);
                $stmt->bindParam(':user_id', $user_id);

                if (!empty($input['password'])) {
                    $stmt->bindParam(':password', $hashed_password);
                }

                if ($stmt->execute()) {
                    // Handle role change
                    if ($current_staff['role'] !== $input['role']) {
                        // Remove from old role table
                        $old_role_table = $current_staff['role'] . 's';
                        $query = "DELETE FROM {$old_role_table} WHERE pf_number = :pf_number";
                        $stmt = $db->prepare($query);
                        $stmt->bindParam(':pf_number', $current_staff['pf_number']);
                        $stmt->execute();

                        // Add to new role table
                        $new_role_table = $input['role'] . 's';
                        $query = "INSERT INTO {$new_role_table} (pf_number, full_name, email, password_hash, role, is_active, created_at) 
                                 VALUES (:pf_number, :full_name, :email, :password_hash, :role, 1, NOW())";
                        $stmt = $db->prepare($query);
                        $stmt->bindParam(':pf_number', $input['pfNumber']);
                        $stmt->bindParam(':full_name', $input['name']);
                        $stmt->bindParam(':email', $input['email']);
                        
                        if (!empty($input['password'])) {
                            $stmt->bindParam(':password_hash', $hashed_password);
                        } else {
                            // Use existing password hash
                            $stmt->bindParam(':password_hash', $current_staff['password']);
                        }
                        
                        $stmt->bindParam(':role', $input['role']);
                        $stmt->execute();
                        
                        // Log role change
                        $updateData = [
                            'field_name' => 'role_change',
                            'old_value' => $current_staff['role'],
                            'new_value' => $input['role'],
                            'update_reason' => 'Staff member role changed'
                        ];
                        logStaffUpdate($db, $current_staff['staff_id'], 'update', $updateData);
                    } else {
                        // Update existing role table
                        $role_table = $input['role'] . 's';
                        $query = "UPDATE {$role_table} SET pf_number = :pf_number, full_name = :full_name, email = :email";

                        // Only update password if provided
                        if (!empty($input['password'])) {
                            $query .= ", password_hash = :password_hash";
                        }

                        $query .= " WHERE pf_number = :old_pf_number";

                        $stmt = $db->prepare($query);
                        $stmt->bindParam(':pf_number', $input['pfNumber']);
                        $stmt->bindParam(':full_name', $input['name']);
                        $stmt->bindParam(':email', $input['email']);
                        $stmt->bindParam(':old_pf_number', $current_staff['pf_number']);

                        if (!empty($input['password'])) {
                            $stmt->bindParam(':password_hash', $hashed_password);
                        }

                        $stmt->execute();
                    }

                    // Log staff update
                    $updateData = [
                        'field_name' => 'staff_update',
                        'old_value' => json_encode([
                            'name' => $current_staff['full_name'],
                            'pf_number' => $current_staff['pf_number'],
                            'id_number' => $current_staff['id_number'],
                            'email' => $current_staff['email'],
                            'role' => $current_staff['role']
                        ]),
                        'new_value' => json_encode([
                            'name' => $input['name'],
                            'pf_number' => $input['pfNumber'],
                            'id_number' => $input['idNumber'],
                            'email' => $input['email'],
                            'role' => $input['role']
                        ]),
                        'update_reason' => 'Staff member information updated'
                    ];
                    logStaffUpdate($db, $current_staff['staff_id'], 'update', $updateData);
                    
                    $db->commit();
                    ob_clean();
                    ResponseHelper::success(null, 'Staff updated successfully');
                } else {
                    $db->rollBack();
                    ob_clean();
                    ResponseHelper::error('Failed to update staff in staff table');
                }
            } else {
                $db->rollBack();
                ob_clean();
                ResponseHelper::error('Failed to update staff in users table');
            }
        } catch (Exception $e) {
            $db->rollBack();
            ob_clean();
            ResponseHelper::error('Failed to update staff: ' . $e->getMessage());
        }
        
    } elseif ($method === 'DELETE') {
        // Use user_id for identification
        $user_id = $_GET['user_id'] ?? '';
        
        if (empty($user_id)) {
            ob_clean();
            ResponseHelper::error('User ID is required');
            exit();
        }
        
        // Get staff details for logging and role table deletion
        $query = "SELECT s.*, u.role FROM staff s JOIN users u ON s.user_id = u.user_id WHERE s.user_id = :user_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $staff_details = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$staff_details) {
            ob_clean();
            ResponseHelper::notFound('Staff member not found');
            exit();
        }
        
        // Start transaction
        $db->beginTransaction();
        
        try {
            // Delete from users table
            $query = "DELETE FROM users WHERE user_id = :user_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();

            // Delete from staff table
            $query = "DELETE FROM staff WHERE user_id = :user_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();

            // Delete from role-specific table
            $role_table = $staff_details['role'] . 's';
            $query = "DELETE FROM {$role_table} WHERE pf_number = :pf_number";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':pf_number', $staff_details['pf_number']);
            $stmt->execute();

            // Log staff deletion
            $updateData = [
                'field_name' => 'staff_deletion',
                'old_value' => json_encode([
                    'name' => $staff_details['full_name'],
                    'pf_number' => $staff_details['pf_number'],
                    'id_number' => $staff_details['id_number'],
                    'email' => $staff_details['email'],
                    'role' => $staff_details['role']
                ]),
                'new_value' => null,
                'update_reason' => 'Staff member deleted'
            ];
            logStaffUpdate($db, $staff_details['staff_id'], 'delete', $updateData);
            
            $db->commit();
            ob_clean();
            ResponseHelper::success(null, 'Staff deleted successfully');
        } catch (Exception $e) {
            $db->rollBack();
            ob_clean();
            ResponseHelper::error('Failed to delete staff: ' . $e->getMessage());
        }
        
    } else {
        ob_clean();
        ResponseHelper::error('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    ob_clean();
    ResponseHelper::serverError('Server error: ' . $e->getMessage());
}

exit();
?>
