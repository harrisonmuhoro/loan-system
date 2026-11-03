<?php
// Disable error display, enable logging
error_reporting(0);
ini_set('display_errors', 0);

// Start output buffering
ob_start();

// Set JSON content type
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    ob_end_clean();
    exit;
}

// Start session first
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include required files - USE ABSOLUTE PATHS
require_once __DIR__ . '/../config/database.php';

/**
 * Send JSON response
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

// Verify authentication
if (!isset($_SESSION['user_id'])) {
    sendResponse(false, 'Unauthorized', null, 401);
}

// Initialize database
try {
    $database = new Database();
    $db = $database->getConnection();
} catch (Exception $e) {
    error_log('Database error: ' . $e->getMessage());
    sendResponse(false, 'Database connection failed', null, 500);
}

$currentUserId = (int)$_SESSION['user_id'];
$userStmt = $db->prepare('SELECT role, is_active FROM users WHERE user_id = ?');
$userStmt->execute([$currentUserId]);
$currentUser = $userStmt->fetch(PDO::FETCH_ASSOC);
if (!$currentUser || (int)$currentUser['is_active'] !== 1) {
    sendResponse(false, 'Unauthorized', null, 401);
}
$currentUserRole = strtolower((string)$currentUser['role']);
$isStaff = in_array($currentUserRole, ['admin', 'accountant', 'cashier'], true);

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') {
    $rawInput = file_get_contents('php://input');
    $csrfInput = json_decode($rawInput, true);
    if (!is_array($csrfInput)) {
        $csrfInput = $_POST;
    }
    if (!validateCSRFToken($csrfInput['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
        sendResponse(false, 'Invalid CSRF token', null, 403);
    }
    if ($method !== 'POST' || empty($csrfInput)) {
        // Rewind is not available for php://input; JSON mutation branches read
        // the already parsed payload below through this request-local variable.
        $_REQUEST['_security_input'] = $csrfInput;
    }
}

try {
    // GET - Retrieve loan applications
    if ($method === 'GET') {
        $query = "SELECT 
                    id,
                    application_id,
                    full_name,
                    id_number,
                    pf_number,
                    email,
                    loan_type,
                    loan_amount,
                    payment_duration,
                    payment_frequency,
                    basic_salary,
                    employment_type,
                    property_location,
                    property_value,
                    car_type,
                    car_value,
                    bank_name,
                    bank_branch,
                    bank_account_number,
                    status,
                    feedback,
                    application_date,
                    dispatch_date,
                    closed_date,
                    cashier_processed_date,
                    accountant_processed_date,
                    created_at,
                    updated_at
                  FROM loan_applications " . ($isStaff ? '' : 'WHERE user_id = :current_user_id ') .
                  "ORDER BY application_date DESC";
        
        $stmt = $db->prepare($query);
        if (!$isStaff) {
            $stmt->bindValue(':current_user_id', $currentUserId, PDO::PARAM_INT);
        }
        $stmt->execute();
        
        $loans = $stmt->fetchAll(PDO::FETCH_ASSOC);
        sendResponse(true, 'Loan applications retrieved successfully', $loans);
    }
    
    // POST - Create new loan application
    elseif ($method === 'POST') {
        if (!in_array($currentUserRole, ['loanee', 'admin', 'cashier'], true)) {
            sendResponse(false, 'Permission denied', null, 403);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            sendResponse(false, 'Invalid JSON data', null, 400);
        }
        
        // Validate required fields
        $required = ['full_name', 'id_number', 'pf_number', 'email', 'loan_type', 'loan_amount', 'payment_duration'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                sendResponse(false, "Missing required field: $field", null, 400);
            }
        }
        
        // Generate application ID
        $application_id = date('YmdHis') . rand(10000000, 99999999);
        
        // Insert loan application
        $query = "INSERT INTO loan_applications (
                    user_id,
                    application_id,
                    full_name,
                    id_number,
                    pf_number,
                    email,
                    loan_type,
                    loan_amount,
                    payment_duration,
                    payment_frequency,
                    basic_salary,
                    employment_type,
                    contract_period,
                    contract_start_date,
                    contract_end_date,
                    property_location,
                    property_value,
                    car_type,
                    car_value,
                    bank_name,
                    bank_branch,
                    bank_account_number,
                    status,
                    application_date
                  ) VALUES (
                    :user_id,
                    :application_id,
                    :full_name,
                    :id_number,
                    :pf_number,
                    :email,
                    :loan_type,
                    :loan_amount,
                    :payment_duration,
                    :payment_frequency,
                    :basic_salary,
                    :employment_type,
                    :contract_period,
                    :contract_start_date,
                    :contract_end_date,
                    :property_location,
                    :property_value,
                    :car_type,
                    :car_value,
                    :bank_name,
                    :bank_branch,
                    :bank_account_number,
                    'pending',
                    NOW()
                  )";
        
        $stmt = $db->prepare($query);
        // bindParam() requires variables by reference; using inline array
        // expressions here caused normal-loan creation to fatal under PHP 8.
        $stmt->execute([
            ':user_id' => $currentUserId,
            ':application_id' => $application_id,
            ':full_name' => $input['full_name'],
            ':id_number' => $input['id_number'],
            ':pf_number' => $input['pf_number'],
            ':email' => $input['email'],
            ':loan_type' => $input['loan_type'],
            ':loan_amount' => $input['loan_amount'],
            ':payment_duration' => $input['payment_duration'],
            ':payment_frequency' => $input['payment_frequency'] ?? 'monthly',
            ':basic_salary' => $input['basic_salary'] ?? null,
            ':employment_type' => $input['employment_type'] ?? null,
            ':contract_period' => $input['contract_period'] ?? null,
            ':contract_start_date' => $input['contract_start_date'] ?? null,
            ':contract_end_date' => $input['contract_end_date'] ?? null,
            ':property_location' => $input['property_location'] ?? null,
            ':property_value' => $input['property_value'] ?? null,
            ':car_type' => $input['car_type'] ?? null,
            ':car_value' => $input['car_value'] ?? null,
            ':bank_name' => $input['bank_name'] ?? null,
            ':bank_branch' => $input['bank_branch'] ?? null,
            ':bank_account_number' => $input['bank_account_number'] ?? null,
        ]);
        
        if ($stmt->rowCount() > 0) {
            sendResponse(true, 'Loan application created successfully', [
                'application_id' => $application_id,
                'id' => $db->lastInsertId()
            ]);
        } else {
            sendResponse(false, 'Failed to create loan application', null, 500);
        }
    }
    
    // PUT - Update loan application
    elseif ($method === 'PUT') {
        if (!$isStaff) {
            sendResponse(false, 'Permission denied', null, 403);
        }
        
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        
        if (!$id) {
            sendResponse(false, 'Loan ID is required', null, 400);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            sendResponse(false, 'Invalid JSON data', null, 400);
        }
        
        $status = $input['status'] ?? null;
        $feedback = $input['feedback'] ?? '';
        
        if (!$status) {
            sendResponse(false, 'Status is required', null, 400);
        }
        
        // Build update query
        $query = "UPDATE loan_applications 
                  SET status = :status, 
                      feedback = :feedback,
                      updated_at = NOW()";
        
        // Add date fields based on status
        if ($status === 'dispatched') {
            $query .= ", dispatch_date = NOW()";
        } elseif ($status === 'closed') {
            $query .= ", closed_date = NOW()";
        }
        
        // Add processed date based on user role
        if ($currentUserRole === 'cashier') {
            $query .= ", cashier_processed_date = NOW()";
        } elseif ($currentUserRole === 'accountant') {
            $query .= ", accountant_processed_date = NOW()";
        }
        
        $query .= " WHERE id = :id" . ($isStaff ? '' : ' AND user_id = :current_user_id');
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':feedback', $feedback);
        $stmt->bindParam(':id', $id);
        if (!$isStaff) {
            $stmt->bindValue(':current_user_id', $currentUserId, PDO::PARAM_INT);
        }
        
        if ($stmt->execute()) {
            sendResponse(true, 'Loan application updated successfully');
        } else {
            sendResponse(false, 'Failed to update loan application', null, 500);
        }
    }
    
    // DELETE - Delete loan applications
    elseif ($method === 'DELETE') {
        if ($currentUserRole !== 'admin') {
            sendResponse(false, 'Only admins can delete loan applications', null, 403);
        }
        
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        
        if ($id) {
            // Delete specific loan
            $query = "DELETE FROM loan_applications WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $id);
        } else {
            // Delete all loans
            $query = "DELETE FROM loan_applications";
            $stmt = $db->prepare($query);
        }
        
        if ($stmt->execute()) {
            $message = $id ? 'Loan application deleted successfully' : 'All loan applications deleted successfully';
            sendResponse(true, $message);
        } else {
            sendResponse(false, 'Failed to delete loan application(s)', null, 500);
        }
    }
    
    else {
        sendResponse(false, 'Method not allowed', null, 405);
    }
    
} catch (PDOException $e) {
    error_log('Loan applications API PDO error: ' . $e->getMessage());
    sendResponse(false, 'A database error occurred', null, 500);
} catch (Exception $e) {
    error_log('Loan applications API error: ' . $e->getMessage());
    sendResponse(false, 'An internal server error occurred', null, 500);
}
