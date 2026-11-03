<?php
/**
 * payments.php - Enhanced Payment Management API with Funding Account Integration
 * Handles payment recording and retrieval for loan system
 * AUTOMATICALLY deposits ALL payments to the Collections Funding Account
 * Access: Cashier (record), Loanee (view own), Accountant (view all)
 * 
 * @version 2.0
 * @author Loan Management System
 */

// ==========================================
// ERROR HANDLING & SESSION CONFIGURATION
// ==========================================

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Start session FIRST - before any output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set response headers
header('Content-Type: application/json');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Load dependencies
require_once __DIR__ . '/../config/database.php';

// ==========================================
// CONFIGURATION CONSTANTS
// ==========================================

/**
 * Name of the collections funding account
 * All loan repayments will be deposited here
 */
define('COLLECTIONS_ACCOUNT_NAME', 'Collections Fund');

/**
 * Maximum payment amount allowed (in KES)
 */
define('MAX_PAYMENT_AMOUNT', 100000000); // 100 million

/**
 * Minimum payment amount allowed (in KES)
 */
define('MIN_PAYMENT_AMOUNT', 1);

// ==========================================
// RESPONSE HANDLER
// ==========================================

/**
 * Send standardized JSON response and exit
 * 
 * @param bool $success Success status
 * @param string $message Response message
 * @param mixed $data Response data
 * @param int $httpCode HTTP status code
 */
function sendResponse($success, $message, $data = null, $httpCode = 200) {
    // Clean any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    http_response_code($httpCode);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);
    exit();
}

// ==========================================
// AUTHENTICATION & AUTHORIZATION
// ==========================================

/**
 * Get user role from session (handles both 'role' and 'user_role')
 * 
 * @return string|null User role or null if not set
 */
function getUserRole() {
    return $_SESSION['user_role'] ?? $_SESSION['role'] ?? null;
}

/**
 * Get user ID from session
 * 
 * @return int|null User ID or null if not set
 */
function getUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Verify user is authenticated with comprehensive session checking
 */
function verifyAuthentication() {
    error_log("=== AUTHENTICATION CHECK ===");
    error_log("Session ID: " . session_id());
    error_log("User ID: " . ($_SESSION['user_id'] ?? 'NOT SET'));
    error_log("User Role: " . (getUserRole() ?? 'NOT SET'));
    error_log("PF Number: " . ($_SESSION['pf_number'] ?? 'NOT SET'));
    
    $hasUserId = isset($_SESSION['user_id']);
    $hasRole = isset($_SESSION['user_role']) || isset($_SESSION['role']);
    $hasPfNumber = isset($_SESSION['pf_number']);
    
    // For GET requests, be more lenient
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!$hasUserId && !$hasPfNumber) {
            error_log("Authentication FAILED: No user credentials");
            sendResponse(false, 'Unauthorized. Please login.', null, 401);
        }
        error_log("Authentication PASSED for GET request");
        return true;
    }
    
    // For POST requests, require user_id
    if (!$hasUserId) {
        error_log("Authentication FAILED: No user_id in session");
        sendResponse(false, 'Unauthorized. Please login.', null, 401);
    }
    
    error_log("Authentication PASSED for POST request");
    return true;
}

/**
 * Verify cashier role with comprehensive permission checking
 */
function verifyCashierRole() {
    $userRole = getUserRole();
    $userId = getUserId();
    $pfNumber = $_SESSION['pf_number'] ?? null;
    
    error_log("=== CASHIER AUTHORIZATION CHECK ===");
    error_log("User ID: {$userId}");
    error_log("Role: {$userRole}");
    error_log("PF Number: {$pfNumber}");
    
    if (!$userId) {
        error_log("FAILED: No user_id");
        sendResponse(false, 'Session expired. Please login again.', null, 401);
    }
    
    if (!$userRole) {
        error_log("FAILED: No role assigned");
        sendResponse(false, 'Access denied. User role not found.', null, 403);
    }
    
    if ($userRole !== 'cashier') {
        error_log("FAILED: Role is '{$userRole}', expected 'cashier'");
        sendResponse(
            false, 
            "Access denied. Only cashiers can record payments.",
            ['your_role' => $userRole],
            403
        );
    }
    
    // Check additional permissions if set
    $permissions = $_SESSION['cashier_permissions'] ?? [];
    if (!empty($permissions) && isset($permissions['canRecordPayments'])) {
        if (!$permissions['canRecordPayments']) {
            error_log("FAILED: Permission 'canRecordPayments' denied");
            sendResponse(
                false, 
                'You do not have permission to record payments.',
                null,
                403
            );
        }
    }
    
    error_log("SUCCESS: Cashier authorization passed");
    return true;
}

/**
 * Verify CSRF token for state-changing operations
 * 
 * @param array $input Request input data
 */
function verifyCsrfToken($input) {
    $sessionToken = $_SESSION['csrf_token'] ?? null;
    $inputToken = $input['csrf_token'] ?? null;
    
    error_log("=== CSRF TOKEN VERIFICATION ===");
    
    if (!$sessionToken) {
        error_log("FAILED: No CSRF token in session");
        sendResponse(false, 'Session expired. Please refresh the page.', null, 403);
    }
    
    if (!$inputToken) {
        error_log("FAILED: No CSRF token in request");
        sendResponse(false, 'Invalid request. Security token missing.', null, 403);
    }
    
    if ($inputToken !== $sessionToken) {
        error_log("FAILED: CSRF tokens do not match");
        sendResponse(false, 'Invalid security token. Please refresh and try again.', null, 403);
    }
    
    error_log("SUCCESS: CSRF token verified");
    return true;
}

// ==========================================
// DATABASE CONNECTION
// ==========================================

/**
 * Get database connection with error handling
 * 
 * @return ?PDO Database connection (nullable on failure)
 */
function getDbConnection(): ?PDO {
    try {
        $database = new Database();
        $conn = $database->getConnection();
        
        if (!$conn) {
            throw new Exception("Failed to establish database connection");
        }
        
        return $conn;
    } catch (Exception $e) {
        error_log('Database connection error: ' . $e->getMessage());
        sendResponse(false, 'Database connection failed.', null, 500);
        
        // Explicit null return (unreachable due to exit() but satisfies type hint)
        return null;
    }
}


// ==========================================
// VALIDATION FUNCTIONS
// ==========================================

/**
 * Validate required fields exist and are not empty
 * 
 * @param array $input Input data
 * @param array $requiredFields List of required field names
 */
function validateRequiredFields($input, $requiredFields) {
    foreach ($requiredFields as $field) {
        if (!isset($input[$field]) || 
            (is_string($input[$field]) && trim($input[$field]) === '')) {
            $fieldName = ucfirst(str_replace('_', ' ', $field));
            sendResponse(false, "{$fieldName} is required", null, 400);
        }
    }
}

/**
 * Validate payment amount
 * 
 * @param mixed $amount Payment amount
 * @return bool True if valid
 */
function validatePaymentAmount($amount) {
    if (!is_numeric($amount)) {
        sendResponse(false, 'Payment amount must be a number', null, 400);
    }
    
    $amount = floatval($amount);
    
    if ($amount < MIN_PAYMENT_AMOUNT) {
        sendResponse(
            false, 
            'Payment amount must be at least KES ' . number_format(MIN_PAYMENT_AMOUNT), 
            null, 
            400
        );
    }
    
    if ($amount > MAX_PAYMENT_AMOUNT) {
        sendResponse(
            false, 
            'Payment amount cannot exceed KES ' . number_format(MAX_PAYMENT_AMOUNT), 
            null, 
            400
        );
    }
    
    return true;
}

/**
 * Validate and map payment source
 * 
 * @param string $inputSource Payment source from input
 * @return string Mapped payment source
 */
function mapPaymentSource($inputSource) {
    // Mapping of display names to database values
    $sourceMap = [
        'Salary Deduction' => 'salary_deduction',
        'salary deduction' => 'salary_deduction',
        'Bank Transfer' => 'bank_transfer',
        'bank transfer' => 'bank_transfer',
        'Cash Deposit' => 'cash_deposit',
        'cash deposit' => 'cash_deposit',
        'cash' => 'cash_deposit'
    ];
    
    // Check exact match first
    if (isset($sourceMap[$inputSource])) {
        return $sourceMap[$inputSource];
    }
    
    // Fallback: normalize input
    $mappedSource = strtolower(str_replace(' ', '_', trim($inputSource)));
    
    // Validate against allowed values
    $validSources = ['cash_deposit', 'bank_transfer', 'salary_deduction'];
    
    if (!in_array($mappedSource, $validSources)) {
        sendResponse(
            false, 
            'Invalid payment source. Allowed: Cash Deposit, Bank Transfer, Salary Deduction',
            ['provided' => $inputSource],
            400
        );
    }
    
    return $mappedSource;
}

/**
 * Validate date format
 * 
 * @param string $date Date string
 * @param string $format Expected format
 * @return bool True if valid
 */
function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    if (!$d || $d->format($format) !== $date) {
        sendResponse(false, 'Invalid date format. Use YYYY-MM-DD', null, 400);
    }
    return true;
}

// ==========================================
// FUNDING ACCOUNT INTEGRATION
// ==========================================

/**
 * Find or create the Collections Funding Account
 * 
 * @param PDO $db Database connection
 * @return array|null Account information or null on failure
 */
function getCollectionsFundingAccount($db) {
    try {
        // Try to find existing account
        $query = "SELECT id as funding_account_id, name, balance 
                  FROM funding_accounts 
                  WHERE name = :account_name 
                  LIMIT 1";
        
        $stmt = $db->prepare($query);
        $accountName = COLLECTIONS_ACCOUNT_NAME;
        $stmt->bindParam(':account_name', $accountName);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            error_log("Collections account found (ID: {$result['funding_account_id']})");
            return $result;
        }
        
        // Create new account if not found
        error_log("Creating new Collections account...");
        
        $createQuery = "INSERT INTO funding_accounts (name, balance, created_at, updated_at) 
                        VALUES (:name, 0, NOW(), NOW())";
        
        $createStmt = $db->prepare($createQuery);
        $createStmt->bindParam(':name', $accountName);
        
        if ($createStmt->execute()) {
            $newAccountId = $db->lastInsertId();
            error_log("Collections account created (ID: {$newAccountId})");
            
            return [
                'funding_account_id' => $newAccountId,
                'name' => COLLECTIONS_ACCOUNT_NAME,
                'balance' => 0
            ];
        }
        
        error_log("Failed to create Collections account");
        return null;
        
    } catch (Exception $e) {
        error_log("Error with Collections account: " . $e->getMessage());
        return null;
    }
}

/**
 * Deposit payment to Collections Funding Account
 * 
 * @param PDO $db Database connection
 * @param string $applicationId Loan application ID
 * @param float $amount Payment amount
 * @param int $paymentId Payment record ID
 * @param int $userId User making the deposit
 * @return array|false Deposit result or false on failure
 */
function depositToCollectionsAccount($db, $applicationId, $amount, $paymentId, $userId) {
    try {
        $collectionsAccount = getCollectionsFundingAccount($db);
        
        if (!$collectionsAccount) {
            error_log("ERROR: Could not access Collections account");
            return false;
        }
        
        $accountId = $collectionsAccount['funding_account_id'];
        $accountName = $collectionsAccount['name'];
        
        error_log("Depositing KES {$amount} to Collections account (ID: {$accountId})");
        
        // Update account balance
        $updateQuery = "UPDATE funding_accounts 
                        SET balance = balance + :amount, 
                            updated_at = NOW() 
                        WHERE id = :account_id";
        
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->bindParam(':amount', $amount);
        $updateStmt->bindParam(':account_id', $accountId);
        
        if (!$updateStmt->execute()) {
            error_log("Failed to update Collections account balance");
            return false;
        }
        
        // Log the transaction
        $transactionQuery = "INSERT INTO funding_transactions 
                             (funding_account_id, amount, transaction_type, reference, created_by, created_at) 
                             VALUES (:account_id, :amount, 'deposit', :reference, :user_id, NOW())";
        
        $transactionStmt = $db->prepare($transactionQuery);
        $transactionStmt->bindParam(':account_id', $accountId);
        $transactionStmt->bindParam(':amount', $amount);
        
        $reference = "Loan repayment collection - Application: {$applicationId}, Payment ID: {$paymentId}";
        $transactionStmt->bindParam(':reference', $reference);
        $transactionStmt->bindParam(':user_id', $userId);
        
        if ($transactionStmt->execute()) {
            error_log("✓ Deposit successful: KES {$amount} to '{$accountName}'");
            
            // Get updated balance
            $balanceQuery = "SELECT balance FROM funding_accounts WHERE id = :account_id";
            $balanceStmt = $db->prepare($balanceQuery);
            $balanceStmt->bindParam(':account_id', $accountId);
            $balanceStmt->execute();
            $balanceResult = $balanceStmt->fetch(PDO::FETCH_ASSOC);
            
            return [
                'account_id' => $accountId,
                'account_name' => $accountName,
                'amount_deposited' => $amount,
                'new_balance' => $balanceResult['balance'] ?? 0
            ];
        }
        
        return false;
        
    } catch (Exception $e) {
        error_log("Error depositing to Collections account: " . $e->getMessage());
        return false;
    }
}

// ==========================================
// POST REQUEST HANDLER - Record Payment
// ==========================================

/**
 * Handle POST request to record a new payment
 * 
 * @param PDO $db Database connection
 */
function handlePostRequest($db) {
    // Step 1: Verify authentication
    verifyAuthentication();
    
    // Step 2: Verify cashier role
    verifyCashierRole();
    
    // Step 3: Get and parse input
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    
    error_log("=== PAYMENT RECORDING REQUEST ===");
    error_log("Application ID: " . ($input['application_id'] ?? 'NOT SET'));
    error_log("Amount: " . ($input['amount'] ?? 'NOT SET'));
    error_log("Recorded by: " . ($_SESSION['full_name'] ?? 'UNKNOWN'));
    
    // Validate JSON parsing
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON parsing failed: " . json_last_error_msg());
        sendResponse(false, 'Invalid JSON data: ' . json_last_error_msg(), null, 400);
    }
    
    // Step 4: Verify CSRF token
    verifyCsrfToken($input);
    
    // Step 5: Validate required fields
    $requiredFields = [
        'application_id',
        'payment_date',
        'amount',
        'principal_amount',
        'interest_amount',
        'payment_source'
    ];
    
    validateRequiredFields($input, $requiredFields);
    
    // Step 6: Validate amounts
    validatePaymentAmount($input['amount']);
    validatePaymentAmount($input['principal_amount']);
    validatePaymentAmount($input['interest_amount']);
    
    // Validate payment date
    validateDate($input['payment_date']);
    
    // Validate payment breakdown
    $totalAmount = floatval($input['principal_amount']) + floatval($input['interest_amount']);
    if (abs($totalAmount - floatval($input['amount'])) > 0.01) {
        sendResponse(
            false, 
            'Payment breakdown mismatch: Principal + Interest must equal Total Amount',
            [
                'principal' => $input['principal_amount'],
                'interest' => $input['interest_amount'],
                'total' => $input['amount'],
                'calculated_total' => $totalAmount
            ],
            400
        );
    }
    
    // Step 7: Map and validate payment source
    $paymentSource = mapPaymentSource($input['payment_source']);
    
    // Step 8: Prepare data
    $recordedBy = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Unknown';
    $userId = getUserId();
    $transactionRef = $input['transaction_reference'] ?? null;
    $notes = $input['notes'] ?? null;
    $balanceAfter = $input['balance_after'] ?? 0;
    
    // Step 9: Execute database transaction
    try {
        $db->beginTransaction();
        
        error_log("Starting payment transaction for loan {$input['application_id']}");
        
        // Insert payment record
        $query = "INSERT INTO payments (
                    application_id,
                    payment_date,
                    amount,
                    principal_amount,
                    interest_amount,
                    balance_after,
                    payment_source,
                    transaction_reference,
                    recorded_by,
                    notes
                  ) VALUES (
                    :application_id,
                    :payment_date,
                    :amount,
                    :principal_amount,
                    :interest_amount,
                    :balance_after,
                    :payment_source,
                    :transaction_reference,
                    :recorded_by,
                    :notes
                  )";
        
        $stmt = $db->prepare($query);
        
        $stmt->bindParam(":application_id", $input['application_id']);
        $stmt->bindParam(":payment_date", $input['payment_date']);
        $stmt->bindParam(":amount", $input['amount']);
        $stmt->bindParam(":principal_amount", $input['principal_amount']);
        $stmt->bindParam(":interest_amount", $input['interest_amount']);
        $stmt->bindParam(":balance_after", $balanceAfter);
        $stmt->bindParam(":payment_source", $paymentSource);
        $stmt->bindParam(":transaction_reference", $transactionRef);
        $stmt->bindParam(":recorded_by", $recordedBy);
        $stmt->bindParam(":notes", $notes);
        
        if (!$stmt->execute()) {
            throw new Exception('Failed to insert payment record');
        }
        
        $paymentId = $db->lastInsertId();
        
        error_log("✓ Payment record created (ID: {$paymentId})");
        
        // Deposit to Collections account
        error_log("Initiating Collections account deposit...");
        $collectionResult = depositToCollectionsAccount(
            $db, 
            $input['application_id'], 
            $input['amount'], 
            $paymentId, 
            $userId
        );
        
        if (!$collectionResult) {
            error_log("⚠ Warning: Collections deposit failed for payment {$paymentId}");
            // Note: We don't fail the transaction - payment is still recorded
        } else {
            error_log("✓ Collections deposit completed");
            error_log("  Account: {$collectionResult['account_name']}");
            error_log("  New Balance: KES {$collectionResult['new_balance']}");
        }
        
        // Commit transaction
        $db->commit();
        error_log("✓ Payment transaction committed successfully");
        
        // Prepare response
        $responseData = [
            'payment_id' => $paymentId,
            'application_id' => $input['application_id'],
            'amount' => $input['amount'],
            'principal_amount' => $input['principal_amount'],
            'interest_amount' => $input['interest_amount'],
            'payment_source' => $paymentSource,
            'payment_date' => $input['payment_date'],
            'recorded_by' => $recordedBy,
            'recorded_at' => date('Y-m-d H:i:s')
        ];
        
        if ($collectionResult) {
            $responseData['collections_account'] = $collectionResult;
        }
        
        sendResponse(
            true,
            'Payment recorded successfully and deposited to Collections Fund',
            $responseData
        );
        
    } catch (Exception $e) {
        // Rollback on error
        if ($db->inTransaction()) {
            $db->rollBack();
            error_log("✗ Transaction rolled back due to error");
        }
        
        error_log('✗ Payment recording failed: ' . $e->getMessage());
        error_log('Stack trace: ' . $e->getTraceAsString());
        sendResponse(false, 'Failed to record payment: ' . $e->getMessage(), null, 500);
    }
}

// ==========================================
// GET REQUEST HANDLER - Retrieve Payments
// ==========================================

/**
 * Handle GET request to retrieve payment records
 * FIXED: Now includes loan type in response
 * 
 * @param PDO $db Database connection
 */
function handleGetRequest($db) {
    verifyAuthentication();
    
    $applicationId = $_GET['application_id'] ?? null;
    $userRole = getUserRole();
    // FORCE LOANEE PERMISSIONS when staff is viewing as loanee
if (isset($_SESSION['viewing_as_loanee']) && $_SESSION['viewing_as_loanee'] === true) {
    $userRole = 'loanee';  // This triggers "own data only" filtering
    error_log("Staff in loanee mode → restricted to own payments");
}
    $userId = getUserId();
    $pfNumber = $_SESSION['pf_number'] ?? null;
    $idNumber = $_SESSION['id_number'] ?? null;
    
    error_log("=== GET PAYMENTS REQUEST ===");
    error_log("Application ID: " . ($applicationId ?? 'ALL'));
    error_log("User Role: {$userRole}");
    error_log("User ID: {$userId}");
    
    // Build query with loan information JOIN - THIS IS THE FIX
    $baseQuery = "SELECT 
                    p.payment_id,
                    p.application_id,
                    p.payment_date,
                    p.amount,
                    p.principal_amount,
                    p.interest_amount,
                    p.balance_after,
                    p.payment_source,
                    p.transaction_reference,
                    p.recorded_by,
                    p.notes,
                    p.recorded_at,
                    la.full_name,
                    la.pf_number,
                    la.loan_type,
                    la.loan_amount
                  FROM payments p
                  LEFT JOIN loan_applications la ON p.application_id = la.application_id";
    
    // If specific application_id requested
    if ($applicationId) {
        // Verify loan ownership for loanees
        if ($userRole === 'loanee') {
            $loanQuery = "SELECT user_id, pf_number, id_number 
                         FROM loan_applications 
                         WHERE application_id = :application_id";
            $loanStmt = $db->prepare($loanQuery);
            $loanStmt->bindParam(":application_id", $applicationId);
            $loanStmt->execute();
            $loan = $loanStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$loan) {
                error_log("Loan not found: {$applicationId}");
                sendResponse(false, 'Loan application not found', null, 404);
            }
            
            // Verify ownership
            $isOwner = (
                (int)$loan['user_id'] === (int)$userId ||
                (string)$loan['pf_number'] === (string)$pfNumber ||
                (string)$loan['id_number'] === (string)$idNumber
            );
            
            if (!$isOwner) {
                error_log("Access denied - user does not own this loan");
                sendResponse(false, 'Access denied. You can only view your own loan payments.', null, 403);
            }
        }
        
        // Get payments for specific loan
        $query = $baseQuery . " WHERE p.application_id = :application_id 
                               ORDER BY p.payment_date DESC, p.payment_id DESC";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(":application_id", $applicationId);
        $stmt->execute();
        
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("Found " . count($payments) . " payments for loan {$applicationId}");
        
        sendResponse(true, 'Payments retrieved successfully', $payments);
    }
    // Get all payments user is allowed to see
    else {
        // Staff can see all payments
        if (in_array($userRole, ['admin', 'accountant', 'cashier'])) {
            $query = $baseQuery . " ORDER BY p.payment_date DESC, p.payment_id DESC";
            
            $stmt = $db->prepare($query);
            $stmt->execute();
            
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            error_log("Found " . count($payments) . " total payments for staff user");
            
            sendResponse(true, 'Payments retrieved successfully', $payments);
        }
        // Loanee can only see their own payments
        else {
            // Get user's loan IDs first
            $loansQuery = "SELECT application_id 
                          FROM loan_applications 
                          WHERE user_id = :user_id 
                             OR pf_number = :pf_number 
                             OR id_number = :id_number";
            
            $loansStmt = $db->prepare($loansQuery);
            $loansStmt->bindParam(":user_id", $userId);
            $loansStmt->bindParam(":pf_number", $pfNumber);
            $loansStmt->bindParam(":id_number", $idNumber);
            $loansStmt->execute();
            $loans = $loansStmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($loans)) {
                error_log("No loans found for user {$userId}");
                sendResponse(true, 'No loans found', []);
            }
            
            $loanIds = array_column($loans, 'application_id');
            $placeholders = implode(',', array_fill(0, count($loanIds), '?'));
            
            // Get payments for user's loans
            $query = $baseQuery . " WHERE p.application_id IN ($placeholders) 
                                   ORDER BY p.payment_date DESC, p.payment_id DESC";
            
            $stmt = $db->prepare($query);
            
            foreach ($loanIds as $i => $loanId) {
                $stmt->bindValue(($i + 1), $loanId);
            }
            
            $stmt->execute();
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            error_log("Found " . count($payments) . " payments for user {$userId}");
            
            sendResponse(true, 'Payments retrieved successfully', $payments);
        }
    }
}

// ==========================================
// MAIN REQUEST ROUTER
// ==========================================

try {
    // Get database connection
    $db = getDbConnection();
    
    // Get request method
    $method = $_SERVER['REQUEST_METHOD'];
    
    error_log("=== PAYMENTS API REQUEST ===");
    error_log("Method: {$method}");
    error_log("User: " . ($_SESSION['full_name'] ?? 'NOT LOGGED IN'));
    error_log("Role: " . (getUserRole() ?? 'NO ROLE'));
    error_log("Timestamp: " . date('Y-m-d H:i:s'));
    
    // Route to appropriate handler
    switch ($method) {
        case 'GET':
            handleGetRequest($db);
            break;
            
        case 'POST':
            handlePostRequest($db);
            break;
            
        default:
            sendResponse(false, 'Method not allowed', ['allowed_methods' => ['GET', 'POST']], 405);
    }
    
} catch (Exception $e) {
    error_log('CRITICAL ERROR in Payments API: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    sendResponse(false, 'An unexpected server error occurred', null, 500);
}