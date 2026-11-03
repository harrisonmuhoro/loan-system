<?php
/**
 * Import Debtors API Endpoint
 * Location: api/import-debtors.php
 * 
 * Handles importing outstanding debtors from previous financial years.
 * Stores them in loan_applications table as 'active' loans.
 */

// Start output buffering
ob_start();

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../php_errors.log');

// Start session
session_start();

// Include database configuration
require_once __DIR__ . '/../config/database.php';

// Set JSON header
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

/**
 * Send JSON response
 */
function sendResponse($success, $message = '', $data = null) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    $response = [
        'success' => $success,
        'message' => $message
    ];
    
    if ($data !== null) {
        $response['data'] = $data;
    }
    
    echo json_encode($response);
    exit;
}

/**
 * Log error
 */
function logError($message) {
    error_log('[Import Debtors API] ' . $message);
}

/**
 * Generate Unique Loan ID Function (PHP version of your JS logic)
 * Format: YYYYMMDDHHMMSS + Micro(4) + Random(4)
 */
function generateUniqueLoanId() {
    $timestamp = time();
    $micro = microtime(true);
    $parts = explode('.', $micro);
    
    // Extract last 4 digits of micro part
    $microPart = isset($parts[1]) ? substr($parts[1], 0, 4) : rand(1000, 9999);
    
    // Generate 4 digit random number
    $randomPart = str_pad(rand(1000, 9999), 4, '0');
    
    // Date formatting: YYYYMMDDHHMMSS
    return date('YmdHis', $timestamp) . $microPart . $randomPart;
}

// Check authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
    logError('Unauthorized access attempt');
    sendResponse(false, 'Unauthorized access');
}

// Allow admin and cashier to import
 $allowedRoles = ['admin', 'cashier', 'accountant'];
if (!in_array($_SESSION['user_role'], $allowedRoles)) {
    logError('Unauthorized user attempted to import debtors: ' . $_SESSION['user_id']);
    sendResponse(false, 'You do not have permission to import debtors');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Invalid request method');
}

// Get JSON input
 $input = file_get_contents('php://input');
 $data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    logError('Invalid JSON input: ' . json_last_error_msg());
    sendResponse(false, 'Invalid JSON data');
}

// Validate CSRF token
if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
    logError('CSRF token validation failed');
    sendResponse(false, 'Invalid security token');
}

// Validate required fields
if (!isset($data['debtors']) || !is_array($data['debtors']) || empty($data['debtors'])) {
    sendResponse(false, 'No debtors data provided');
}

 $debtors = $data['debtors'];
 $financialYear = isset($data['financial_year']) ? trim($data['financial_year']) : 'N/A';
 $userId = $_SESSION['user_id'];

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    if (!$conn) {
        throw new Exception('Database connection failed');
    }
    
    $conn->beginTransaction();
    
    $imported = 0;
    $skipped = 0;
    $totalAmount = 0;
    $errors = [];
    
    // SQL Insert Statement for loan_applications
    $insertSql = "INSERT INTO loan_applications (
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
        bank_name, 
        bank_branch, 
        bank_account_number, 
        status, 
        application_date, 
        dispatch_date, 
        feedback, 
        created_at
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
        :bank_name, 
        :bank_branch, 
        :bank_account_number, 
        :status, 
        :application_date, 
        :dispatch_date, 
        :feedback, 
        NOW()
    )";
    
    $stmt = $conn->prepare($insertSql);
    
    foreach ($debtors as $index => $debtor) {
        try {
            // 1. Validate Basic Fields
            $name = trim($debtor['name'] ?? '');
            $amount = floatval($debtor['amount'] ?? 0);
            
            if (empty($name)) {
                $skipped++;
                $errors[] = "Row " . ($index + 1) . ": Name is required";
                continue;
            }
            
            if ($amount <= 0) {
                $skipped++;
                $errors[] = "Row " . ($index + 1) . ": Invalid amount ({$amount})";
                continue;
            }
            
            // Check for duplicates in loan_applications to prevent double import
            $checkSql = "SELECT COUNT(*) FROM loan_applications 
                         WHERE full_name = :name 
                         AND loan_amount = :amount 
                         AND status = 'active'";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bindValue(':name', $name);
            $checkStmt->bindValue(':amount', $amount);
            $checkStmt->execute();
            
            if ($checkStmt->fetchColumn() > 0) {
                $skipped++;
                $errors[] = "Row " . ($index + 1) . ": Duplicate active loan found for {$name}";
                continue;
            }
            
            // 2. Generate Unique Application ID
            $applicationId = generateUniqueLoanId();
            
            // 3. Auto-Update Dates & Status
            $now = date('Y-m-d H:i:s');
            $applicationDate = $now; 
            $dispatchDate = $now;
            
            // Status: 'active'
            $status = 'active';
            
            // Feedback for traceability
            $feedback = "Legacy loan imported from financial year {$financialYear}.";

            // 4. Default Values for Missing Fields
            // ==========================================
            // CRITICAL FIX APPLIED HERE
            // ==========================================
            
            // ID Number
            $idNumber = isset($debtor['id_number']) ? trim($debtor['id_number']) : '';
            
            // PF Number: Source is string, DB is BigInt.
            // We must convert to integer or null. 
            $pfNumberRaw = isset($debtor['pf_number']) ? trim($debtor['pf_number']) : '';
            $pfNumber = is_numeric($pfNumberRaw) ? intval($pfNumberRaw) : null;
            
            // Email
            $email = isset($debtor['email']) ? trim($debtor['email']) : null;
            $loanType = 'mortgage'; 
            $paymentDuration = 12;
            $paymentFrequency = 'monthly';
            $basicSalary = 0;
            $employmentType = 'permanent';
            
            // Bank Name: DB is ENUM('Family Bank'). 
            // We cannot use 'N/A'. We use the ENUM value.
            $bankName = 'Family Bank';
            
            $bankBranch = '';
            $bankAccountNumber = '';

            // 5. Bind and Execute
            $stmt->bindValue(':user_id', null); // No specific user ID for legacy
            
            $stmt->bindValue(':application_id', $applicationId);
            $stmt->bindValue(':full_name', $name);
            $stmt->bindValue(':id_number', $idNumber);
            
            // Bind PF Number as integer or null
            $stmt->bindValue(':pf_number', $pfNumber, PDO::PARAM_NULL);
            
            $stmt->bindValue(':email', $email, PDO::PARAM_NULL);
            $stmt->bindValue(':loan_type', $loanType);
            $stmt->bindValue(':loan_amount', $amount);
            $stmt->bindValue(':payment_duration', $paymentDuration);
            $stmt->bindValue(':payment_frequency', $paymentFrequency);
            $stmt->bindValue(':basic_salary', $basicSalary);
            $stmt->bindValue(':employment_type', $employmentType);
            
            // Bind Bank Name as 'Family Bank'
            $stmt->bindValue(':bank_name', $bankName);
            
            $stmt->bindValue(':bank_branch', $bankBranch);
            $stmt->bindValue(':bank_account_number', $bankAccountNumber);
            
            $stmt->bindValue(':status', $status);
            $stmt->bindValue(':application_date', $applicationDate);
            $stmt->bindValue(':dispatch_date', $dispatchDate);
            $stmt->bindValue(':feedback', $feedback);
            
            if ($stmt->execute()) {
                $imported++;
                $totalAmount += $amount;
            } else {
                $skipped++;
                $errors[] = "Row " . ($index + 1) . ": Database insert failed. " . print_r($stmt->errorInfo(), true);
            }
            
        } catch (Exception $e) {
            $skipped++;
            $errors[] = "Row " . ($index + 1) . ": " . $e->getMessage();
            logError("Error importing debtor: " . $e->getMessage());
        }
    }
    
    $conn->commit();
    
    // Prepare response
    $responseData = [
        'imported' => $imported,
        'skipped' => $skipped,
        'total_amount' => $totalAmount
    ];
    
    if (!empty($errors)) {
        $responseData['errors'] = array_slice($errors, 0, 10);
    }
    
    $message = "Successfully imported {$imported} debtor(s)";
    if ($skipped > 0) {
        $message .= " ({$skipped} skipped due to errors)";
    }
    
    sendResponse(true, $message, $responseData);
    
} catch (PDOException $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    logError("Database error: " . $e->getMessage());
    sendResponse(false, 'Database error occurred while importing debtors');
    
} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    logError("General error: " . $e->getMessage());
    sendResponse(false, 'An error occurred: ' . $e->getMessage());
}
?>