<?php
// Disable error display, enable logging
error_reporting(0);
ini_set('display_errors', 0);

// Start output buffering
ob_start();

// Set JSON content type
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
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
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
    sendResponse(false, 'Unauthorized', null, 401);
}

// Initialize database
try {
    $database = new Database();
    $db = $database->getConnection();
} catch (Exception $e) {
    error_log('Database error: ' . $e->getMessage());
    sendResponse(false, 'Database connection failed: ' . $e->getMessage(), null, 500);
}

$method = $_SERVER['REQUEST_METHOD'];

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
                  FROM loan_applications 
                  ORDER BY application_date DESC";
        
        $stmt = $db->prepare($query);
        $stmt->execute();
        
        $loans = $stmt->fetchAll(PDO::FETCH_ASSOC);
        sendResponse(true, 'Loan applications retrieved successfully', $loans);
    }
    
    // POST - Create new loan application
    elseif ($method === 'POST') {
        if (!in_array($_SESSION['user_role'], ['loanee', 'admin', 'cashier'])) {
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
        $stmt->bindParam(':application_id', $application_id);
        $stmt->bindParam(':full_name', $input['full_name']);
        $stmt->bindParam(':id_number', $input['id_number']);
        $stmt->bindParam(':pf_number', $input['pf_number']);
        $stmt->bindParam(':email', $input['email']);
        $stmt->bindParam(':loan_type', $input['loan_type']);
        $stmt->bindParam(':loan_amount', $input['loan_amount']);
        $stmt->bindParam(':payment_duration', $input['payment_duration']);
        $stmt->bindParam(':payment_frequency', $input['payment_frequency'] ?? 'monthly');
        $stmt->bindParam(':basic_salary', $input['basic_salary'] ?? null);
        $stmt->bindParam(':employment_type', $input['employment_type'] ?? null);
        $stmt->bindParam(':contract_period', $input['contract_period'] ?? null);
        $stmt->bindParam(':contract_start_date', $input['contract_start_date'] ?? null);
        $stmt->bindParam(':contract_end_date', $input['contract_end_date'] ?? null);
        $stmt->bindParam(':property_location', $input['property_location'] ?? null);
        $stmt->bindParam(':property_value', $input['property_value'] ?? null);
        $stmt->bindParam(':car_type', $input['car_type'] ?? null);
        $stmt->bindParam(':car_value', $input['car_value'] ?? null);
        $stmt->bindParam(':bank_name', $input['bank_name'] ?? null);
        $stmt->bindParam(':bank_branch', $input['bank_branch'] ?? null);
        $stmt->bindParam(':bank_account_number', $input['bank_account_number'] ?? null);
        
        if ($stmt->execute()) {
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
        if (!in_array($_SESSION['user_role'], ['admin', 'cashier', 'accountant'])) {
            sendResponse(false, 'Permission denied', null, 403);
        }
        
        $id = $_GET['id'] ?? null;
        
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
        if ($_SESSION['user_role'] === 'cashier') {
            $query .= ", cashier_processed_date = NOW()";
        } elseif ($_SESSION['user_role'] === 'accountant') {
            $query .= ", accountant_processed_date = NOW()";
        }
        
        $query .= " WHERE id = :id";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':feedback', $feedback);
        $stmt->bindParam(':id', $id);
        
        if ($stmt->execute()) {
            sendResponse(true, 'Loan application updated successfully');
        } else {
            sendResponse(false, 'Failed to update loan application', null, 500);
        }
    }
    
    // DELETE - Delete loan applications
    elseif ($method === 'DELETE') {
        if ($_SESSION['user_role'] !== 'admin') {
            sendResponse(false, 'Only admins can delete loan applications', null, 403);
        }
        
        $id = $_GET['id'] ?? null;
        
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
    sendResponse(false, 'Database error: ' . $e->getMessage(), null, 500);
} catch (Exception $e) {
    error_log('Loan applications API error: ' . $e->getMessage());
    sendResponse(false, 'An error occurred: ' . $e->getMessage(), null, 500);
}