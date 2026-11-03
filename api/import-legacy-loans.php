<?php
require_once __DIR__ . '/legacy_deprecated_guard.php';
rejectDeprecatedLegacyEndpoint('legacy-debt.php and api/legacy_migration.php');
/**
 * Import Legacy Loans API Endpoint
 * Location: api/import-legacy-loans.php
 * 
 * This API handles the "destructuring" of simple legacy data (Name, Amount)
 * into the complex loan_applications table.
 * 
 * Features:
 * - Generates Application IDs using the specific YYYYMMDDHHMMSS + Micro(4) + Random(4) format.
 * - Auto-updates dispatch_date, accountant_processed_date, and feedback.
 * - Sets missing fields (ID Number, PF Number, Email, etc.) to NULL or defaults.
 * - Supports GET (View), POST (Import), PUT (Update), DELETE (Remove).
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
    // Clean any output buffers
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
    error_log('[Import Legacy Loans] ' . $message);
}

/**
 * Generate Unique Loan ID Function
 * Replicates JS logic: YYYYMMDDHHMMSS + Micro(4) + Random(4)
 */
function generateUniqueLoanId() {
    // Get current timestamp
    $timestamp = time();
    
    // Get microseconds (matches JS Date.now precision)
    $micro = microtime(true);
    $parts = explode('.', $micro);
    
    // Extract last 4 digits of micro part
    // Equivalent to JS: timestamp.slice(-4)
    $microPart = isset($parts[1]) ? substr($parts[1], 0, 4) : rand(1000, 9999);
    
    // Generate 4 digit random number
    // Equivalent to JS: Math.floor(1000 + Math.random() * 9000)
    $randomPart = str_pad(rand(1000, 9999), 4, '0');
    
    // Date formatting
    // Equivalent to JS: year + month + day + hour + minute + second
    $year = date('Y', $timestamp);
    $month = str_pad(date('m', $timestamp), 2, '0');
    $day = str_pad(date('d', $timestamp), 2, '0');
    $hour = str_pad(date('H', $timestamp), 2, '0');
    $minute = str_pad(date('i', $timestamp), 2, '0');
    $second = str_pad(date('s', $timestamp), 2, '0');
    
    // Combine: YYYYMMDDHHMMSS + micro(4) + random(4)
    return $year . $month . $day . $hour . $minute . $second . $microPart . $randomPart;
}

// Check authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
    logError('Unauthorized access attempt');
    sendResponse(false, 'Unauthorized access');
}

// Allow both admin and cashier access
 $allowedRoles = ['admin', 'cashier', 'accountant'];
if (!in_array($_SESSION['user_role'], $allowedRoles)) {
    logError('Unauthorized user attempted to import legacy loans: ' . $_SESSION['user_id']);
    sendResponse(false, 'You do not have permission to import loans');
}

 $method = $_SERVER['REQUEST_METHOD'];
 $userId = $_SESSION['user_id'];

try {
    // Get database connection
    $database = new Database();
    $conn = $database->getConnection();
    
    if (!$conn) {
        throw new Exception('Database connection failed');
    }
    
    // Handle different request methods
    switch ($method) {
        case 'GET':
            handleGetRequest($conn);
            break;
            
        case 'POST':
            handlePostRequest($conn, $userId);
            break;
            
        case 'PUT':
            handlePutRequest($conn, $userId);
            break;
            
        case 'DELETE':
            handleDeleteRequest($conn, $userId);
            break;
            
        default:
            sendResponse(false, 'Invalid request method');
    }
    
} catch (PDOException $e) {
    logError("Database error: " . $e->getMessage());
    sendResponse(false, 'Database error occurred');
    
} catch (Exception $e) {
    logError("General error: " . $e->getMessage());
    sendResponse(false, 'An error occurred: ' . $e->getMessage());
}

/**
 * Handle GET request - Retrieve Imported Legacy Loans
 */
function handleGetRequest($conn) {
    // Get query parameters
    $financialYear = $_GET['financial_year'] ?? null;
    $debtorId = $_GET['debtor_id'] ?? null;
    
    try {
        if ($debtorId) {
            // Get specific legacy loan (treated as "debtor" for frontend compatibility)
            $stmt = $conn->prepare("
                SELECT * FROM loan_applications 
                WHERE id = :id 
                AND feedback LIKE '%Legacy loan imported%'
            ");
            $stmt->bindParam(':id', $debtorId);
            $stmt->execute();
            
            $debtor = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($debtor) {
                sendResponse(true, 'Legacy loan retrieved successfully', $debtor);
            } else {
                sendResponse(false, 'Legacy loan not found');
            }
            
        } elseif ($financialYear) {
            // Parse Financial Year string (e.g., "2024/2025" -> "2024")
            // This allows filtering legacy loans by their creation year without a specific column
            $targetYear = $financialYear;
            
            if (strpos($financialYear, '/') !== false) {
                $parts = explode('/', $financialYear);
                $targetYear = isset($parts[0]) ? trim($parts[0]) : $financialYear;
            }

            // Get loans for specific year where feedback indicates legacy import
            $stmt = $conn->prepare("
                SELECT * FROM loan_applications 
                WHERE feedback LIKE '%Legacy loan imported%'
                AND YEAR(created_at) = :year
                ORDER BY loan_amount DESC, full_name ASC
            ");
            $stmt->bindParam(':year', $targetYear);
            $stmt->execute();
            
            $debtors = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if ($debtors) {
                // Calculate statistics
                $totalAmount = array_sum(array_column($debtors, 'loan_amount'));
                $totalCount = count($debtors);
                
                sendResponse(true, "Retrieved {$totalCount} legacy loan(s)", [
                    'debtors' => $debtors,
                    'statistics' => [
                        'total_count' => $totalCount,
                        'total_amount' => $totalAmount,
                        'financial_year' => $financialYear
                    ]
                ]);
            } else {
                sendResponse(true, 'No legacy loans found for this financial year', []);
            }
            
        } else {
            // Get all legacy loans grouped by financial year (YEAR of creation)
            $stmt = $conn->query("
                SELECT 
                    YEAR(created_at) as year_group,
                    COUNT(*) as debtor_count,
                    SUM(loan_amount) as total_amount,
                    MIN(created_at) as first_import,
                    MAX(created_at) as last_import
                FROM loan_applications
                WHERE feedback LIKE '%Legacy loan imported%'
                GROUP BY YEAR(created_at)
                ORDER BY year_group DESC
            ");
            
            $summary = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if ($summary) {
                // Format year back to "YYYY/YYY+1" string for frontend consistency if desired
                // For now, keeping as "YYYY" to avoid complexity in generation
                sendResponse(true, 'Retrieved legacy loan summary', $summary);
            } else {
                sendResponse(true, 'No legacy loans found', []);
            }
        }
        
    } catch (PDOException $e) {
        logError("Error in GET request: " . $e->getMessage());
        sendResponse(false, 'Failed to retrieve legacy loans');
    }
}

/**
 * Handle POST request - Import New Legacy Loans
 * Destructures simple Name/Amount data into full loan_applications
 */
function handlePostRequest($conn, $userId) {
    // Get JSON input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendResponse(false, 'Invalid JSON data');
    }
    
    // Validate CSRF token
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        sendResponse(false, 'Invalid security token');
    }
    
    // Validate required fields
    if (!isset($data['financial_year']) || empty($data['financial_year'])) {
        sendResponse(false, 'Financial year is required');
    }
    
    if (!isset($data['debtors']) || !is_array($data['debtors'])) {
        sendResponse(false, 'Debtors data is required');
    }
    
    $debtors = $data['debtors'];
    $financialYear = trim($data['financial_year']);
    
    try {
        // Insert batch into loan_applications
        $conn->beginTransaction();
        
        $importedCount = 0;
        $skippedCount = 0;
        $totalAmount = 0;
        $errors = [];
        
        foreach ($debtors as $debtor) {
            try {
                $name = trim($debtor['name'] ?? '');
                $amount = floatval($debtor['amount'] ?? 0);
                
                // Basic validation
                if (empty($name)) {
                    throw new Exception('Debtor name is required');
                }
                
                if ($amount <= 0) {
                    throw new Exception('Amount must be greater than zero');
                }

                // ==========================================
                // 1. Generate Unique Application ID (Auto-Update)
                // ==========================================
                $applicationId = generateUniqueLoanId();

                // ==========================================
                // 2. Auto-Update Dates & Feedback
                // ==========================================
                $now = date('Y-m-d H:i:s'); // Current timestamp
                $dispatchDate = $now; 
                $accountantProcessedDate = $now;
                
                // Status: 'dispatched' makes it appear in Active/Dispatched loan lists
                $status = 'dispatched';
                
                // Feedback: Auto-set to indicate this is a legacy import
                $feedback = "Legacy loan imported from previous financial year ({$financialYear}).";

                // ==========================================
                // 3. Accumulate Fields (Set Missing Data to NULL)
                // ==========================================
                // Since we only have Name/Amount, we fill others with defaults or NULL
                
                $userIdValue = NULL; // Or set to $userId if you want to assign ownership
                $idNumber = NULL; 
                $pfNumber = NULL;
                $email = NULL;
                
                // Defaults for fields not in CSV (can be adjusted)
                $loanType = 'mortgage'; // Default assumption
                $paymentDuration = 12; 
                $paymentFrequency = 'monthly';
                $employmentType = 'permanent';
                $basicSalary = 0; 
                
                $propertyLocation = NULL;
                $propertyValue = NULL;
                $carType = NULL;
                $carValue = NULL;
                
                $bankName = NULL;
                $bankBranch = NULL;
                $bankAccountNumber = NULL;

                // ==========================================
                // 4. Insert into loan_applications
                // ==========================================
                $sql = "INSERT INTO loan_applications (
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
                    dispatch_date, 
                    closed_date, 
                    feedback, 
                    accountant_processed_date, 
                    cashier_processed_date, 
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
                    :status, 
                    :dispatch_date, 
                    :closed_date, 
                    :feedback, 
                    :accountant_processed_date, 
                    :cashier_processed_date, 
                    NOW()
                )";
                
                $stmt = $conn->prepare($sql);
                
                $stmt->bindValue(':user_id', $userIdValue);
                $stmt->bindValue(':application_id', $applicationId);
                $stmt->bindValue(':full_name', $name);
                $stmt->bindValue(':id_number', $idNumber);
                $stmt->bindValue(':pf_number', $pfNumber);
                $stmt->bindValue(':email', $email);
                $stmt->bindValue(':loan_type', $loanType);
                $stmt->bindValue(':loan_amount', $amount);
                $stmt->bindValue(':payment_duration', $paymentDuration);
                $stmt->bindValue(':payment_frequency', $paymentFrequency);
                $stmt->bindValue(':basic_salary', $basicSalary);
                $stmt->bindValue(':employment_type', $employmentType);
                $stmt->bindValue(':contract_period', 0);
                $stmt->bindValue(':contract_start_date', null);
                $stmt->bindValue(':contract_end_date', null);
                $stmt->bindValue(':property_location', $propertyLocation);
                $stmt->bindValue(':property_value', $propertyValue);
                $stmt->bindValue(':car_type', $carType);
                $stmt->bindValue(':car_value', $carValue);
                $stmt->bindValue(':bank_name', $bankName);
                $stmt->bindValue(':bank_branch', $bankBranch);
                $stmt->bindValue(':bank_account_number', $bankAccountNumber);
                $stmt->bindValue(':status', $status);
                $stmt->bindValue(':dispatch_date', $dispatchDate);
                $stmt->bindValue(':closed_date', null);
                $stmt->bindValue(':feedback', $feedback);
                $stmt->bindValue(':accountant_processed_date', $accountantProcessedDate);
                $stmt->bindValue(':cashier_processed_date', null);
                
                if ($stmt->execute()) {
                    $importedCount++;
                    $totalAmount += $amount;
                } else {
                    $skippedCount++;
                    $errors[] = "Failed to import: " . $name;
                }

            } catch (Exception $e) {
                // Log error but continue with next debtor
                logError("Error importing debtor " . ($debtor['name'] ?? 'Unknown') . ": " . $e->getMessage());
                $skippedCount++;
                $errors[] = "Error: " . ($debtor['name'] ?? 'Unknown');
            }
        }
        
        $conn->commit();
        
        sendResponse(true, 'Import completed successfully', [
            'imported' => $importedCount,
            'skipped' => $skippedCount,
            'total_amount' => $totalAmount,
            'financial_year' => $financialYear,
            'errors' => array_slice($errors, 0, 10) // Limit errors in response
        ]);
        
    } catch (PDOException $e) {
        if (isset($conn) && $conn->inTransaction()) {
            $conn->rollBack();
        }
        logError("Import Database error: " . $e->getMessage());
        sendResponse(false, 'Database error occurred');
        
    } catch (Exception $e) {
        if (isset($conn) && $conn->inTransaction()) {
            $conn->rollBack();
        }
        logError("Import General error: " . $e->getMessage());
        sendResponse(false, 'An error occurred: ' . $e->getMessage());
    }
}

/**
 * Handle PUT request - Update Legacy Loan
 */
function handlePutRequest($conn, $userId) {
    // Get JSON input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendResponse(false, 'Invalid JSON data');
    }
    
    // Validate CSRF token
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        sendResponse(false, 'Invalid security token');
    }
    
    // Validate debtor ID (ID in loan_applications)
    if (!isset($data['debtor_id']) || !is_numeric($data['debtor_id'])) {
        sendResponse(false, 'Valid debtor ID is required');
    }
    
    $debtorId = intval($data['debtor_id']);
    
    // Build update query dynamically based on provided fields
    $updates = [];
    $params = [':debtor_id' => $debtorId];
    
    if (isset($data['name']) && !empty(trim($data['name']))) {
        $updates[] = "full_name = :name";
        $params[':name'] = trim($data['name']);
    }
    
    // Note: We restrict updating 'amount' for legacy imports to avoid breaking balance calculations,
    // unless explicitly handled by other logic. But for now, allow it.
    if (isset($data['amount']) && is_numeric($data['amount']) && $data['amount'] > 0) {
        $updates[] = "loan_amount = :amount";
        $params[':amount'] = floatval($data['amount']);
    }
    
    if (empty($updates)) {
        sendResponse(false, 'No fields to update');
    }
    
    try {
        // Get current debtor data for logging
        $stmt = $conn->prepare("SELECT * FROM loan_applications WHERE id = :id");
        $stmt->bindParam(':id', $debtorId);
        $stmt->execute();
        $oldData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$oldData) {
            sendResponse(false, 'Legacy loan not found');
        }
        
        // Ensure it's actually a legacy loan
        if (strpos($oldData['feedback'], 'Legacy loan imported') === false) {
            sendResponse(false, 'This record is not a legacy imported loan and cannot be updated via this API.');
        }
        
        // Update legacy loan
        $sql = "UPDATE loan_applications SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmt = $conn->prepare($sql);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        if ($stmt->execute()) {
            // Log activity
            try {
                $logStmt = $conn->prepare("
                    INSERT INTO activity_log 
                    (admin_id, action, details, timestamp) 
                    VALUES 
                    (:admin_id, :action, :details, NOW())
                ");
                
                $action = 'update_legacy_loan';
                $details = "Updated legacy loan: {$oldData['full_name']} (ID: {$debtorId})";
                
                $logStmt->bindParam(':admin_id', $userId);
                $logStmt->bindParam(':action', $action);
                $logStmt->bindParam(':details', $details);
                $logStmt->execute();
            } catch (Exception $e) {
                logError("Failed to log activity: " . $e->getMessage());
            }
            
            sendResponse(true, 'Legacy loan updated successfully');
        } else {
            sendResponse(false, 'Failed to update legacy loan');
        }
        
    } catch (PDOException $e) {
        logError("Error in PUT request: " . $e->getMessage());
        sendResponse(false, 'Failed to update legacy loan');
    }
}

/**
 * Handle DELETE request - Delete Legacy Loan
 */
function handleDeleteRequest($conn, $userId) {
    // Get parameters
    $debtorId = $_GET['debtor_id'] ?? null;
    $financialYear = $_GET['financial_year'] ?? null;
    
    // Get JSON input for CSRF token
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    // Validate CSRF token
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        sendResponse(false, 'Invalid security token');
    }
    
    try {
        if ($debtorId) {
            // Delete specific legacy loan
            $stmt = $conn->prepare("SELECT * FROM loan_applications WHERE id = :id");
            $stmt->bindParam(':id', $debtorId);
            $stmt->execute();
            $debtor = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$debtor) {
                sendResponse(false, 'Legacy loan not found');
            }
            
            // Ensure it's a legacy loan
            if (strpos($debtor['feedback'], 'Legacy loan imported') === false) {
                sendResponse(false, 'Cannot delete a regular loan via this API. Use loan management interface.');
            }
            
            $stmt = $conn->prepare("DELETE FROM loan_applications WHERE id = :id");
            $stmt->bindParam(':id', $debtorId);
            
            if ($stmt->execute()) {
                // Log activity
                try {
                    $logStmt = $conn->prepare("
                        INSERT INTO activity_log 
                        (admin_id, action, details, timestamp) 
                        VALUES 
                        (:admin_id, :action, :details, NOW())
                    ");
                    
                    $action = 'delete_legacy_loan';
                    $details = "Deleted legacy loan: {$debtor['full_name']} (KES " . number_format($debtor['loan_amount'], 2) . ")";
                    
                    $logStmt->bindParam(':admin_id', $userId);
                    $logStmt->bindParam(':action', $action);
                    $logStmt->bindParam(':details', $details);
                    $logStmt->execute();
                } catch (Exception $e) {
                    logError("Failed to log activity: " . $e->getMessage());
                }
                
                sendResponse(true, 'Legacy loan deleted successfully');
            } else {
                sendResponse(false, 'Failed to delete legacy loan');
            }
            
        } elseif ($financialYear) {
            // Delete all legacy loans for a financial year
            // Extract year string for query matching
            $targetYear = $financialYear;
            if (strpos($financialYear, '/') !== false) {
                $parts = explode('/', $financialYear);
                $targetYear = isset($parts[0]) ? trim($parts[0]) : $financialYear;
            }

            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM loan_applications WHERE feedback LIKE '%Legacy loan imported%' AND YEAR(created_at) = :year");
            $stmt->bindParam(':year', $targetYear);
            $stmt->execute();
            $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            if ($count == 0) {
                sendResponse(false, 'No legacy loans found for this financial year');
            }
            
            $stmt = $conn->prepare("DELETE FROM loan_applications WHERE feedback LIKE '%Legacy loan imported%' AND YEAR(created_at) = :year");
            $stmt->bindParam(':year', $targetYear);
            
            if ($stmt->execute()) {
                // Log activity
                try {
                    $logStmt = $conn->prepare("
                        INSERT INTO activity_log 
                        (admin_id, action, details, timestamp) 
                        VALUES 
                        (:admin_id, :action, :details, NOW())
                    ");
                    
                    $action = 'delete_legacy_loans_fy';
                    $details = "Deleted {$count} legacy loan(s) for FY {$financialYear}";
                    
                    $logStmt->bindParam(':admin_id', $userId);
                    $logStmt->bindParam(':action', $action);
                    $logStmt->bindParam(':details', $details);
                    $logStmt->execute();
                } catch (Exception $e) {
                    logError("Failed to log activity: " . $e->getMessage());
                }
                
                sendResponse(true, "Deleted {$count} legacy loan(s) successfully");
            } else {
                sendResponse(false, 'Failed to delete legacy loans');
            }
            
        } else {
            sendResponse(false, 'Debtor ID or financial year is required');
        }
        
    } catch (PDOException $e) {
        logError("Error in DELETE request: " . $e->getMessage());
        sendResponse(false, 'Failed to delete legacy loan(s)');
    }
}
?>
