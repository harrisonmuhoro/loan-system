<?php
/**
 * Debtors API Endpoint - Cross-Portal Version (FIXED COLLATION & MISSING COLUMN)
 * Location: api/debtors.php
 */

// Start output buffering
ob_start();

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../php_errors.log');

// Set session params to match admin portal to avoid cookie conflicts
session_set_cookie_params([
    'lifetime' => 36000,
    'path' => '/'
]);
// Start session
session_start();

// Include database configuration
require_once __DIR__ . '/../config/database.php';

// Set JSON header
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

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
    error_log('[Debtors API] ' . $message);
}

// ==========================================
// AUTHENTICATION & AUTHORIZATION
// ==========================================

// Check authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
    logError('Unauthorized access attempt - No session');
    http_response_code(401);
    sendResponse(false, 'Unauthorized access');
}

// Allow admin, cashier, and accountant access
 $allowedRoles = ['admin', 'cashier', 'accountant'];
if (!in_array($_SESSION['user_role'], $allowedRoles)) {
    logError('Unauthorized role attempted access: ' . $_SESSION['user_role']);
    http_response_code(403);
    sendResponse(false, 'You do not have permission to access debtor data');
}

 $method = $_SERVER['REQUEST_METHOD'];
 $userId = $_SESSION['user_id'];
 $userRole = $_SESSION['user_role'];

// ==========================================
// HANDLE CORS PREFLIGHT
// ==========================================

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ==========================================
// MAIN REQUEST HANDLER
// ==========================================

try {
    // Get database connection
    $database = new Database();
    $conn = $database->getConnection();
    
    if (!$conn) {
        throw new Exception('Database connection failed');
    }
    
    // **FIX: Set connection collation to prevent conflicts**
    $conn->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    
    // Route to appropriate handler
    switch ($method) {
        case 'GET':
            handleGetRequest($conn);
            break;
            
        case 'POST':
            // Only admin and cashier can import
            if (!in_array($userRole, ['admin', 'cashier'])) {
                sendResponse(false, 'Only admin and cashier can import debtors');
            }
            handlePostRequest($conn, $userId);
            break;
            
        case 'PUT':
            // All authorized roles can update
            handlePutRequest($conn, $userId);
            break;
            
        case 'DELETE':
            // Only admin can delete
            if ($userRole !== 'admin') {
                sendResponse(false, 'Only admin can delete debtors');
            }
            handleDeleteRequest($conn, $userId);
            break;
            
        default:
            http_response_code(405);
            sendResponse(false, 'Invalid request method');
    }
    
} catch (PDOException $e) {
    logError("Database error: " . $e->getMessage());
    http_response_code(500);
    sendResponse(false, 'Database error occurred');
} catch (Exception $e) {
    logError("General error: " . $e->getMessage());
    http_response_code(500);
    sendResponse(false, 'An error occurred: ' . $e->getMessage());
}

// ==========================================
// GET REQUEST HANDLER (FIXED)
// ==========================================

function handleGetRequest($conn) {
    $financialYear = $_GET['financial_year'] ?? null;
    $debtorId = $_GET['debtor_id'] ?? null;
    $includeMigrated = isset($_GET['include_migrated']) ? 
        filter_var($_GET['include_migrated'], FILTER_VALIDATE_BOOLEAN) : true;
    
    try {
        // ==========================================
        // GET SPECIFIC DEBTOR
        // ==========================================
        if ($debtorId) {
            // FIXED: Calculate current_principal_balance dynamically
            // Logic: Loan Amount - Sum(Payments)
            $stmt = $conn->prepare("
                SELECT 
                    od.*,
                    la.id as loan_id,
                    la.application_id,
                    la.status as loan_status,
                    la.dispatch_date,
                    la.application_date,
                    (la.loan_amount - COALESCE((SELECT SUM(amount) FROM payments WHERE application_id = la.application_id), 0)) as current_principal_balance
                FROM outstanding_debtors od
                LEFT JOIN loan_applications la 
                    ON BINARY od.loan_application_id = BINARY la.application_id
                WHERE od.debtor_id = :debtor_id
            ");
            $stmt->bindParam(':debtor_id', $debtorId, PDO::PARAM_INT);
            $stmt->execute();
            
            $debtor = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($debtor) {
                sendResponse(true, 'Debtor retrieved successfully', $debtor);
            } else {
                http_response_code(404);
                sendResponse(false, 'Debtor not found');
            }
        }
        
        // ==========================================
        // GET DEBTORS BY FINANCIAL YEAR (FIXED)
        // ==========================================
        elseif ($financialYear) {
            // FIXED: Calculate current_principal_balance dynamically
            $sql = "
                SELECT 
                    od.debtor_id,
                    od.financial_year,
                    od.name,
                    od.pf_number,
                    od.id_number,
                    od.amount,
                    od.status,
                    od.migrated_to_loan,
                    od.loan_application_id,
                    od.created_at,
                    od.updated_at,
                    la.id as loan_id,
                    la.application_id,
                    la.status as loan_status,
                    la.dispatch_date,
                    la.application_date,
                    (la.loan_amount - COALESCE((SELECT SUM(amount) FROM payments WHERE application_id = la.application_id), 0)) as current_principal_balance
                FROM outstanding_debtors od
                LEFT JOIN loan_applications la 
                    ON BINARY od.loan_application_id = BINARY la.application_id
                WHERE od.financial_year = :financial_year
            ";
            
            if (!$includeMigrated) {
                $sql .= " AND od.migrated_to_loan = 0";
            }
            
            $sql .= " ORDER BY od.migrated_to_loan ASC, od.amount DESC, od.name ASC";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':financial_year', $financialYear);
            $stmt->execute();
            
            $debtors = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate statistics
            $totalCount = count($debtors);
            $totalAmount = 0;
            $migratedCount = 0;
            $pendingCount = 0;
            
            foreach ($debtors as $d) {
                $amount = floatval($d['amount']);
                if (!is_nan($amount)) {
                    $totalAmount += $amount;
                }
                
                if ($d['migrated_to_loan'] == 1) {
                    $migratedCount++;
                } else {
                    $pendingCount++;
                }
            }
            
            sendResponse(true, "Retrieved {$totalCount} debtor(s)", [
                'debtors' => $debtors,
                'statistics' => [
                    'total_count' => $totalCount,
                    'total_amount' => $totalAmount,
                    'migrated_count' => $migratedCount,
                    'pending_count' => $pendingCount,
                    'financial_year' => $financialYear
                ]
            ]);
        }
        
        // ==========================================
        // GET SUMMARY (ALL YEARS) - FIXED
        // ==========================================
        else {
            // **FIX: Simplified query without JOIN to avoid collation issues**
            $sql = "
                SELECT 
                    od.financial_year,
                    COUNT(od.debtor_id) as debtor_count,
                    SUM(od.amount) as total_amount,
                    SUM(CASE WHEN od.migrated_to_loan = 1 THEN 1 ELSE 0 END) as migrated_count,
                    SUM(CASE WHEN od.migrated_to_loan = 0 THEN 1 ELSE 0 END) as pending_count,
                    MIN(od.created_at) as first_import,
                    MAX(od.created_at) as last_import
                FROM outstanding_debtors od
            ";
            
            if (!$includeMigrated) {
                $sql .= " WHERE od.migrated_to_loan = 0";
            }
            
            $sql .= " GROUP BY od.financial_year
                     ORDER BY od.financial_year DESC";
            
            $stmt = $conn->query($sql);
            $summary = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // **FIX: Count active loans separately to avoid collation issues**
            if ($summary) {
                foreach ($summary as &$row) {
                    $countStmt = $conn->prepare("
                        SELECT COUNT(DISTINCT la.id) as active_count
                        FROM loan_applications la
                        INNER JOIN outstanding_debtors od 
                            ON BINARY la.application_id = BINARY od.loan_application_id
                        WHERE od.financial_year = :fy
                        AND la.status IN ('active', 'dispatched')
                    ");
                    $countStmt->execute([':fy' => $row['financial_year']]);
                    $countResult = $countStmt->fetch(PDO::FETCH_ASSOC);
                    $row['active_loans'] = $countResult['active_count'] ?? 0;
                }
                unset($row); // Break reference
            }
            
            if ($summary) {
                sendResponse(true, 'Retrieved debtor summary', $summary);
            } else {
                sendResponse(true, 'No debtors found', []);
            }
        }
        
    } catch (PDOException $e) {
        logError("Error in GET request: " . $e->getMessage());
        http_response_code(500);
        sendResponse(false, 'Failed to retrieve debtors: ' . $e->getMessage());
    }
}

// ==========================================
// POST REQUEST HANDLER (No changes needed)
// ==========================================

function handlePostRequest($conn, $userId) {
    // Get JSON input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        sendResponse(false, 'Invalid JSON data');
    }
    
    // Validate CSRF token
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        http_response_code(403);
        sendResponse(false, 'Invalid security token');
    }
    
    // Validate required fields
    if (!isset($data['financial_year']) || empty($data['financial_year'])) {
        http_response_code(400);
        sendResponse(false, 'Financial year is required');
    }
    
    if (!isset($data['debtors']) || !is_array($data['debtors']) || empty($data['debtors'])) {
        http_response_code(400);
        sendResponse(false, 'Debtors data is required and must be a non-empty array');
    }
    
    $debtors = $data['debtors'];
    $financialYear = trim($data['financial_year']);
    $defaultStatus = $data['default_status'] ?? 'active';
    
    try {
        $conn->beginTransaction();
        
        $importedCount = 0;
        $skippedCount = 0;
        $updatedCount = 0;
        $totalAmount = 0.0;
        $errors = [];
        $importedDebtors = [];
        
        // Prepare INSERT/UPDATE statement
        $sql = "INSERT INTO outstanding_debtors 
            (financial_year, name, pf_number, id_number, amount, status, created_by, created_at, updated_at) 
            VALUES 
            (:financial_year, :name, :pf_number, :id_number, :amount, :status, :created_by, NOW(), NOW())
            ON DUPLICATE KEY UPDATE 
                amount = VALUES(amount),
                status = VALUES(status),
                pf_number = VALUES(pf_number),
                id_number = VALUES(id_number),
                updated_at = NOW()";
        
        $stmt = $conn->prepare($sql);
        
        foreach ($debtors as $index => $debtor) {
            try {
                // Extract and clean data
                $name = trim($debtor['name'] ?? '');
                $rawAmount = $debtor['amount'] ?? '';
                $pfNumber = trim($debtor['pf_number'] ?? '');
                $idNumber = trim($debtor['id_number'] ?? '');
                $status = $debtor['status'] ?? $defaultStatus;
                
                // Validate name
                if (empty($name) || strlen($name) < 3) {
                    $errors[] = "Row " . ($index + 1) . ": Name is required (min 3 characters)";
                    $skippedCount++;
                    continue;
                }
                
                // Validate and clean amount
                $cleanAmount = preg_replace('/[^0-9.\-]/', '', $rawAmount);
                $cleanAmount = str_replace(',', '', $cleanAmount);
                $amount = floatval($cleanAmount);
                
                if (empty($rawAmount) || is_nan($amount) || $amount <= 0) {
                    $errors[] = "Row " . ($index + 1) . ": Invalid amount for {$name}";
                    $skippedCount++;
                    continue;
                }
                
                // Validate status
                $validStatuses = ['pending', 'forwarded', 'accepted', 'rejected', 'returned', 
                                 'dispatched', 'active', 'closed'];
                if (!in_array($status, $validStatuses)) {
                    $status = 'active'; // Default fallback
                }
                
                // Execute insert/update
                $stmt->execute([
                    ':financial_year' => $financialYear,
                    ':name' => $name,
                    ':pf_number' => $pfNumber ?: null,
                    ':id_number' => $idNumber ?: null,
                    ':amount' => $amount,
                    ':status' => $status,
                    ':created_by' => $userId
                ]);
                
                // Check if it was an insert or update
                if ($stmt->rowCount() > 0) {
                    $debtorId = $conn->lastInsertId();
                    
                    if ($debtorId == 0) {
                        // Was an update
                        $updatedCount++;
                        
                        // Fetch the debtor_id (since lastInsertId is 0 on update)
                        $fetchStmt = $conn->prepare("
                            SELECT debtor_id FROM outstanding_debtors 
                            WHERE financial_year = :fy AND name = :name 
                            LIMIT 1
                        ");
                        $fetchStmt->execute([
                            ':fy' => $financialYear,
                            ':name' => $name
                        ]);
                        $debtorId = $fetchStmt->fetchColumn();
                    } else {
                        // Was an insert
                        $importedCount++;
                    }
                    
                    $totalAmount += $amount;
                    
                    $importedDebtors[] = [
                        'debtor_id' => $debtorId,
                        'name' => $name,
                        'amount' => $amount,
                        'status' => $status,
                        'pf_number' => $pfNumber,
                        'id_number' => $idNumber
                    ];
                }
                
            } catch (Exception $e) {
                logError("Error importing debtor " . ($debtor['name'] ?? 'Unknown') . ": " . $e->getMessage());
                $errors[] = "Row " . ($index + 1) . ": " . $e->getMessage();
                $skippedCount++;
            }
        }
        
        $conn->commit();
        
        // Log import activity
        try {
            $logStmt = $conn->prepare("
                INSERT INTO activity_log 
                    (admin_id, action, details, timestamp) 
                    VALUES 
                    (:admin_id, :action, :details, NOW())
            ");
            
            $action = 'import_legacy_debtors';
            $safeTotal = number_format($totalAmount, 2);
            $details = "Imported {$importedCount} new debtors, updated {$updatedCount} existing debtors for FY {$financialYear}. Total: KES {$safeTotal}";
            
            $logStmt->execute([
                ':admin_id' => $userId,
                ':action' => $action,
                ':details' => $details
            ]);
        } catch (Exception $e) {
            logError("Failed to log import activity: " . $e->getMessage());
        }
        
        // Prepare response
        $message = "Import completed: {$importedCount} new, {$updatedCount} updated";
        if ($skippedCount > 0) {
            $message .= ", {$skippedCount} skipped";
        }
        
        sendResponse(true, $message, [
            'imported' => $importedCount,
            'updated' => $updatedCount,
            'skipped' => $skippedCount,
            'total_amount' => $totalAmount,
            'financial_year' => $financialYear,
            'imported_debtors' => $importedDebtors,
            'errors' => $errors
        ]);
        
    } catch (PDOException $e) {
        if (isset($conn) && $conn->inTransaction()) {
            $conn->rollBack();
        }
        logError("Error in POST request: " . $e->getMessage());
        http_response_code(500);
        sendResponse(false, 'Import failed: ' . $e->getMessage(), [
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'total_amount' => 0,
            'errors' => [$e->getMessage()]
        ]);
    } catch (Exception $e) {
        if (isset($conn) && $conn->inTransaction()) {
            $conn->rollBack();
        }
        logError("Error in POST request: " . $e->getMessage());
        http_response_code(500);
        sendResponse(false, 'An error occurred: ' . $e->getMessage(), [
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'total_amount' => 0,
            'errors' => [$e->getMessage()]
        ]);
    }
}

// ==========================================
// PUT REQUEST HANDLER
// ==========================================

/**
 * Handle PUT request - Update debtor
 * 
 * Expected JSON payload:
 * {
 *   "csrf_token": "...",
 *   "debtor_id": 123,
 *   "name": "Updated Name",
 *   "amount": 2000000,
 *   "status": "active"
 * }
 */
function handlePutRequest($conn, $userId) {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        sendResponse(false, 'Invalid JSON data');
    }
    
    // Validate CSRF token
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        http_response_code(403);
        sendResponse(false, 'Invalid security token');
    }
    
    // Validate debtor ID
    if (!isset($data['debtor_id']) || !is_numeric($data['debtor_id'])) {
        http_response_code(400);
        sendResponse(false, 'Valid debtor ID is required');
    }
    
    $debtorId = intval($data['debtor_id']);
    
    try {
        // Get current debtor data
        $stmt = $conn->prepare("SELECT * FROM outstanding_debtors WHERE debtor_id = :id");
        $stmt->execute([':id' => $debtorId]);
        $oldData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$oldData) {
            http_response_code(404);
            sendResponse(false, 'Debtor not found');
        }
        
        // Build update query dynamically
        $updates = [];
        $params = [':debtor_id' => $debtorId];
        
        if (isset($data['name']) && !empty(trim($data['name']))) {
            $updates[] = "name = :name";
            $params[':name'] = trim($data['name']);
        }
        
        if (isset($data['amount']) && is_numeric($data['amount']) && $data['amount'] > 0) {
            $updates[] = "amount = :amount";
            $params[':amount'] = floatval($data['amount']);
        }
        
        if (isset($data['pf_number'])) {
            $updates[] = "pf_number = :pf_number";
            $params[':pf_number'] = trim($data['pf_number']) ?: null;
        }
        
        if (isset($data['id_number'])) {
            $updates[] = "id_number = :id_number";
            $params[':id_number'] = trim($data['id_number']) ?: null;
        }
        
        if (isset($data['status'])) {
            $validStatuses = ['pending', 'forwarded', 'accepted', 'rejected', 'returned', 
                             'dispatched', 'active', 'closed'];
            if (in_array($data['status'], $validStatuses)) {
                $updates[] = "status = :status";
                $params[':status'] = $data['status'];
            }
        }
        
        if (empty($updates)) {
            http_response_code(400);
            sendResponse(false, 'No valid fields to update');
        }
        
        // Add updated_at timestamp
        $updates[] = "updated_at = NOW()";
        
        // Execute update
        $sql = "UPDATE outstanding_debtors SET " . implode(', ', $updates) . " WHERE debtor_id = :debtor_id";
        $stmt = $conn->prepare($sql);
        
        if ($stmt->execute($params)) {
            // Log activity
            try {
                $logStmt = $conn->prepare("
                    INSERT INTO activity_log 
                        (admin_id, action, details, timestamp) 
                        VALUES 
                        (:admin_id, :action, :details, NOW())
                ");
                
                $action = 'update_debtor';
                $details = "Updated debtor: {$oldData['name']} (ID: {$debtorId})";
                
                $logStmt->execute([
                    ':admin_id' => $userId,
                    ':action' => $action,
                    ':details' => $details
                ]);
            } catch (Exception $e) {
                logError("Failed to log activity: " . $e->getMessage());
            }
            
            sendResponse(true, 'Debtor updated successfully');
        } else {
            http_response_code(500);
            sendResponse(false, 'Failed to update debtor');
        }
        
    } catch (PDOException $e) {
        logError("Error in PUT request: " . $e->getMessage());
        http_response_code(500);
        sendResponse(false, 'Failed to update debtor: ' . $e->getMessage());
    }
}

// ==========================================
// DELETE REQUEST HANDLER
// ==========================================

/**
 * Handle DELETE request - Delete debtor(s)
 * 
 * Query Parameters:
 * - debtor_id: Delete specific debtor
 * - financial_year: Delete all debtors for a year
 * 
 * Body (JSON):
 * {
 *   "csrf_token": "..."
 * }
 */
function handleDeleteRequest($conn, $userId) {
    $debtorId = $_GET['debtor_id'] ?? null;
    $financialYear = $_GET['financial_year'] ?? null;
    
    // Get JSON input for CSRF token
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    // Validate CSRF token
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        http_response_code(403);
        sendResponse(false, 'Invalid security token');
    }
    
    try {
        // ==========================================
        // DELETE SPECIFIC DEBTOR
        // ==========================================
        if ($debtorId) {
            // Get debtor data first
            $stmt = $conn->prepare("SELECT * FROM outstanding_debtors WHERE debtor_id = :id");
            $stmt->execute([':id' => $debtorId]);
            $debtor = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$debtor) {
                http_response_code(404);
                sendResponse(false, 'Debtor not found');
            }
            
            // Check if already migrated
            if ($debtor['migrated_to_loan'] == 1) {
                http_response_code(400);
                sendResponse(false, 'Cannot delete a migrated debtor. Delete the associated loan instead.');
            }
            
            // Delete debtor
            $stmt = $conn->prepare("DELETE FROM outstanding_debtors WHERE debtor_id = :id");
            
            if ($stmt->execute([':id' => $debtorId])) {
                // Log activity
                try {
                    $logStmt = $conn->prepare("
                        INSERT INTO activity_log 
                            (admin_id, action, details, timestamp) 
                            VALUES 
                            (:admin_id, :action, :details, NOW())
                    ");
                    
                    $action = 'delete_debtor';
                    $details = "Deleted debtor: {$debtor['name']} (KES " . number_format($debtor['amount'], 2) . ")";
                    
                    $logStmt->execute([
                        ':admin_id' => $userId,
                        ':action' => $action,
                        ':details' => $details
                    ]);
                } catch (Exception $e) {
                    logError("Failed to log activity: " . $e->getMessage());
                }
                
                sendResponse(true, 'Debtor deleted successfully');
            } else {
                http_response_code(500);
                sendResponse(false, 'Failed to delete debtor');
            }
        }
        
        // ==========================================
        // DELETE ALL DEBTORS FOR FINANCIAL YEAR
        // ==========================================
        elseif ($financialYear) {
            // Get count of debtors
            $stmt = $conn->prepare("
                SELECT COUNT(*) as count, SUM(amount) as total_amount 
                FROM outstanding_debtors 
                WHERE financial_year = :year AND migrated_to_loan = 0
            ");
            $stmt->execute([':year' => $financialYear]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $count = $result['count'];
            $totalAmount = $result['total_amount'];
            
            if ($count == 0) {
                http_response_code(404);
                sendResponse(false, 'No unmigrated debtors found for this financial year');
            }
            
            // Delete debtors
            $stmt = $conn->prepare("
                DELETE FROM outstanding_debtors 
                WHERE financial_year = :year AND migrated_to_loan = 0
            ");
            
            if ($stmt->execute([':year' => $financialYear])) {
                // Log activity
                try {
                    $logStmt = $conn->prepare("
                        INSERT INTO activity_log 
                            (admin_id, action, details, timestamp) 
                            VALUES 
                            (:admin_id, :action, :details, NOW())
                    ");
                    
                    $action = 'delete_debtors_fy';
                    $details = "Deleted {$count} debtor(s) for FY {$financialYear}. Total: KES " . number_format($totalAmount, 2);
                    
                    $logStmt->execute([
                        ':admin_id' => $userId,
                        ':action' => $action,
                        ':details' => $details
                    ]);
                } catch (Exception $e) {
                    logError("Failed to log activity: " . $e->getMessage());
                }
                
                sendResponse(true, "Deleted {$count} debtor(s) successfully", [
                    'deleted_count' => $count,
                    'total_amount' => $totalAmount
                ]);
            } else {
                http_response_code(500);
                sendResponse(false, 'Failed to delete debtors');
            }
        }
        
        // ==========================================
        // MISSING PARAMETERS
        // ==========================================
        else {
            http_response_code(400);
            sendResponse(false, 'Debtor ID or financial year is required');
        }
        
    } catch (PDOException $e) {
        logError("Error in DELETE request: " . $e->getMessage());
        http_response_code(500);
        sendResponse(false, 'Failed to delete debtor(s): ' . $e->getMessage());
    }
}
?>