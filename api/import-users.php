<?php
// api/import-users.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config/database.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verify admin authentication
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    // Get the JSON data from POST
    $jsonData = file_get_contents('php://input');
    $data = json_decode($jsonData, true);
    
    // Verify CSRF token
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }
    
    if (!$data || !isset($data['users']) || !is_array($data['users'])) {
        throw new Exception('Invalid request data');
    }
    
    $users = $data['users'];
    $results = [
        'success' => true,
        'total' => count($users),
        'imported' => 0,
        'skipped' => 0,
        'errors' => []
    ];
    
    $conn->beginTransaction();
    
    foreach ($users as $index => $user) {
        try {
            // Validate required fields
            if (empty($user['full_name']) || empty($user['id_number']) || empty($user['pf_number'])) {
                $results['skipped']++;
                $results['errors'][] = "Row " . ($index + 1) . ": Missing required fields (Name, ID Number, or PF Number)";
                continue;
            }
            
            // Clean and validate data
            $fullName = trim($user['full_name']);
            $email = !empty($user['email']) ? trim($user['email']) : null;
            $idNumber = trim($user['id_number']);
            $pfNumber = preg_replace('/\D/', '', trim($user['pf_number'])); // Remove non-digits
            
            // Validate PF number (9-11 digits)
            if (!preg_match('/^\d{9,11}$/', $pfNumber)) {
                $results['skipped']++;
                $results['errors'][] = "Row " . ($index + 1) . ": Invalid PF number format (must be 9-11 digits) - got: " . $user['pf_number'];
                continue;
            }
            
            // Validate ID number (6-9 digits)
            if (!preg_match('/^\d{6,9}$/', $idNumber)) {
                $results['skipped']++;
                $results['errors'][] = "Row " . ($index + 1) . ": Invalid ID number format (must be 6-9 digits) - got: " . $idNumber;
                continue;
            }
            
            // Validate email if provided
            if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $results['skipped']++;
                $results['errors'][] = "Row " . ($index + 1) . ": Invalid email format - " . $email;
                continue;
            }
            
            // Check if user already exists
            $checkQuery = "SELECT user_id, full_name FROM users WHERE pf_number = :pf_number OR id_number = :id_number";
            if ($email) {
                $checkQuery .= " OR email = :email";
            }
            
            $stmt = $conn->prepare($checkQuery);
            $stmt->bindParam(':pf_number', $pfNumber);
            $stmt->bindParam(':id_number', $idNumber);
            if ($email) {
                $stmt->bindParam(':email', $email);
            }
            $stmt->execute();
            
            if ($existingUser = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results['skipped']++;
                $results['errors'][] = "Row " . ($index + 1) . ": User already exists - " . $existingUser['full_name'] . " (duplicate PF number, ID number, or email)";
                continue;
            }
            
            // Default password is ID number
            $password = password_hash($idNumber, PASSWORD_DEFAULT);
            
            // Default role is loanee
            $role = 'loanee';
            
            // Insert user into users table
            $insertQuery = "INSERT INTO users (full_name, email, id_number, pf_number, password, role, created_at, is_active, can_apply_for_loan) 
                           VALUES (:full_name, :email, :id_number, :pf_number, :password, :role, NOW(), 1, 1)";
            
            $stmt = $conn->prepare($insertQuery);
            $stmt->bindParam(':full_name', $fullName);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':id_number', $idNumber);
            $stmt->bindParam(':pf_number', $pfNumber);
            $stmt->bindParam(':password', $password);
            $stmt->bindParam(':role', $role);
            $stmt->execute();
            
            $results['imported']++;
            
        } catch (PDOException $e) {
            $results['skipped']++;
            $errorMsg = $e->getMessage();
            
            // Check for duplicate entry errors
            if (strpos($errorMsg, 'Duplicate entry') !== false) {
                if (strpos($errorMsg, 'pf_number') !== false) {
                    $results['errors'][] = "Row " . ($index + 1) . ": Duplicate PF number - " . $pfNumber;
                } elseif (strpos($errorMsg, 'email') !== false) {
                    $results['errors'][] = "Row " . ($index + 1) . ": Duplicate email - " . $email;
                } elseif (strpos($errorMsg, 'id_number') !== false) {
                    $results['errors'][] = "Row " . ($index + 1) . ": Duplicate ID number - " . $idNumber;
                } else {
                    $results['errors'][] = "Row " . ($index + 1) . ": Duplicate entry";
                }
            } else {
                $results['errors'][] = "Row " . ($index + 1) . ": Database error - " . $errorMsg;
            }
        }
    }
    
    $conn->commit();
    
    // Log the import activity
    try {
        $logQuery = "INSERT INTO activity_log (admin_id, action, details, timestamp) 
                     VALUES (:admin_id, :action, :details, NOW())";
        $stmt = $conn->prepare($logQuery);
        $adminId = $_SESSION['user_id'];
        $action = 'import_users';
        $details = "Imported {$results['imported']} users, skipped {$results['skipped']} users";
        $stmt->bindParam(':admin_id', $adminId);
        $stmt->bindParam(':action', $action);
        $stmt->bindParam(':details', $details);
        $stmt->execute();
    } catch (PDOException $e) {
        // Log error but don't fail the import
        error_log("Failed to log import activity: " . $e->getMessage());
    }
    
    echo json_encode($results);
    
} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Import failed: ' . $e->getMessage()
    ]);
}