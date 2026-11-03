<?php
/**
 * Enhanced Funding Accounts API
 * Handles CRUD operations for funding accounts WITH transaction history
 */

// Disable error display, enable logging
error_reporting(0);
ini_set('display_errors', 0);

// Start output buffering
ob_start();

// Set JSON content type
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Include required files
require_once __DIR__ . '/../config/database.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
    sendResponse(false, 'Database connection failed', null, 500);
}

$method = $_SERVER['REQUEST_METHOD'];

// Add this check for direct browser access
if ($method === 'GET' && (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest')) {
    sendResponse(false, 'Direct access not allowed. Please use the application interface.', null, 403);
}

try {
    // GET - Retrieve funding accounts with transaction history
    if ($method === 'GET') {
        // Check if user is logged in
        if (empty($_SESSION['user_id'])) {
            sendResponse(false, 'Unauthorized', null, 401);
        }
        
        // Check if user has permission
        if (!in_array($_SESSION['user_role'], ['admin', 'accountant'])) {
            sendResponse(false, 'Only admins and accountants can view funding accounts', null, 403);
        }
        
        // Check if requesting single account with transactions
        $accountId = $_GET['id'] ?? null;
        
        if ($accountId) {
            // Get single account with full transaction history
            $query = "SELECT id, name, balance, created_at AS createdAt, updated_at AS updatedAt 
                      FROM funding_accounts 
                      WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $accountId);
            $stmt->execute();
            
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$account) {
                sendResponse(false, 'Account not found', null, 404);
            }
            
            // Get transaction history for this account
            $transQuery = "SELECT 
                            ft.id,
                            ft.amount,
                            ft.transaction_type,
                            ft.reference,
                            ft.created_at,
                            u.full_name AS created_by_name,
                            u.pf_number AS created_by_pf
                          FROM funding_transactions ft
                          LEFT JOIN users u ON ft.created_by = u.user_id
                          WHERE ft.funding_account_id = :account_id
                          ORDER BY ft.created_at DESC";
            
            $transStmt = $db->prepare($transQuery);
            $transStmt->bindParam(':account_id', $accountId);
            $transStmt->execute();
            
            $transactions = $transStmt->fetchAll(PDO::FETCH_ASSOC);
            $account['transactions'] = $transactions;
            
            sendResponse(true, 'Account details retrieved successfully', $account);
        } else {
            // Get all accounts with transaction count
            $query = "SELECT 
                        fa.id, 
                        fa.name, 
                        fa.balance, 
                        fa.created_at AS createdAt, 
                        fa.updated_at AS updatedAt,
                        COUNT(ft.id) AS transaction_count,
                        COALESCE(SUM(CASE WHEN ft.transaction_type = 'withdrawal' THEN ft.amount ELSE 0 END), 0) AS total_withdrawals,
                        COALESCE(SUM(CASE WHEN ft.transaction_type = 'deposit' THEN ft.amount ELSE 0 END), 0) AS total_deposits,
                        COALESCE(SUM(CASE WHEN ft.transaction_type = 'refund' THEN ft.amount ELSE 0 END), 0) AS total_refunds,
                        COALESCE(SUM(CASE WHEN ft.transaction_type = 'deposit' THEN ft.amount WHEN ft.transaction_type IN ('withdrawal','refund') THEN -ft.amount ELSE 0 END), 0) AS net_transaction_total
                      FROM funding_accounts fa
                      LEFT JOIN funding_transactions ft ON fa.id = ft.funding_account_id
                      GROUP BY fa.id, fa.name, fa.balance, fa.created_at, fa.updated_at
                      ORDER BY fa.created_at DESC";
            
            $stmt = $db->prepare($query);
            $stmt->execute();
            
            $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // For each account, get recent transactions (last 5)
            foreach ($accounts as &$account) {
                $transQuery = "SELECT 
                                ft.id,
                                ft.amount,
                                ft.transaction_type,
                                ft.reference,
                                ft.created_at,
                                u.full_name AS created_by_name
                              FROM funding_transactions ft
                              LEFT JOIN users u ON ft.created_by = u.user_id
                              WHERE ft.funding_account_id = :account_id
                              ORDER BY ft.created_at DESC
                              LIMIT 5";
                
                $transStmt = $db->prepare($transQuery);
                $transStmt->bindParam(':account_id', $account['id']);
                $transStmt->execute();
                
                $account['recent_transactions'] = $transStmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            sendResponse(true, 'Funding accounts retrieved successfully', $accounts);
        }
    }
    
    // POST - Create new funding account
    elseif ($method === 'POST') {
        // Check if user is logged in
        if (empty($_SESSION['user_id'])) {
            sendResponse(false, 'Unauthorized', null, 401);
        }
        
        // Check if user has permission
        if ($_SESSION['user_role'] !== 'admin') {
            sendResponse(false, 'Only admins can create funding accounts', null, 403);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (empty($input['name'])) {
            sendResponse(false, 'Account name is required', null, 400);
        }
        
        $balance = isset($input['balance']) ? floatval($input['balance']) : 0;
        
        $query = "INSERT INTO funding_accounts (name, balance, created_at, updated_at) 
                  VALUES (:name, :balance, NOW(), NOW())";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':name', $input['name']);
        $stmt->bindParam(':balance', $balance);
        
        if ($stmt->execute()) {
            $newId = $db->lastInsertId();
            
            // Log initial deposit if balance > 0
            if ($balance > 0) {
                $logQuery = "INSERT INTO funding_transactions 
                            (funding_account_id, amount, transaction_type, reference, created_by, created_at) 
                            VALUES (:account_id, :amount, 'deposit', :reference, :user_id, NOW())";
                $logStmt = $db->prepare($logQuery);
                $logStmt->bindParam(':account_id', $newId);
                $logStmt->bindParam(':amount', $balance);
                $reference = "Initial deposit for new account: " . $input['name'];
                $logStmt->bindParam(':reference', $reference);
                $logStmt->bindParam(':user_id', $_SESSION['user_id']);
                $logStmt->execute();
            }
            
            sendResponse(true, 'Funding account created successfully', [
                'id' => $newId,
                'name' => $input['name'],
                'balance' => $balance,
                'createdAt' => date('Y-m-d H:i:s')
            ]);
        } else {
            sendResponse(false, 'Failed to create account', null, 500);
        }
    }
    
    // PUT - Top up account balance
    elseif ($method === 'PUT') {
        // Check if user is logged in
        if (empty($_SESSION['user_id'])) {
            sendResponse(false, 'Unauthorized', null, 401);
        }
        
        // Check if user has permission
        if (!in_array($_SESSION['user_role'], ['admin', 'accountant'])) {
            sendResponse(false, 'Only admins and accountants can update funding accounts', null, 403);
        }
        
        $id = $_GET['id'] ?? null;
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Validate inputs
        if (!$id) {
            sendResponse(false, 'Account ID is required', null, 400);
        }
        
        if (!isset($input['balance'])) {
            sendResponse(false, 'Balance amount is required', null, 400);
        }
        
        $newBalance = floatval($input['balance']);
        
        if ($newBalance < 0) {
            sendResponse(false, 'Balance cannot be negative', null, 400);
        }
        
        // Check if account exists
        $query = "SELECT id, name, balance FROM funding_accounts WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$account) {
            sendResponse(false, 'Account not found', null, 404);
        }
        
        $previousBalance = floatval($account['balance']);
        $topupAmount = $newBalance - $previousBalance;
        
        // Update the balance
        $query = "UPDATE funding_accounts 
                  SET balance = :balance, updated_at = NOW() 
                  WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':balance', $newBalance);
        $stmt->bindParam(':id', $id);
        
        if ($stmt->execute()) {
            // Log the transaction
            $transactionType = $topupAmount >= 0 ? 'deposit' : 'withdrawal';
            $absAmount = abs($topupAmount);
            
            $logQuery = "INSERT INTO funding_transactions 
                        (funding_account_id, amount, transaction_type, reference, created_by, created_at) 
                        VALUES (:account_id, :amount, :type, :reference, :user_id, NOW())";
            $logStmt = $db->prepare($logQuery);
            $logStmt->bindParam(':account_id', $id);
            $logStmt->bindParam(':amount', $absAmount);
            $logStmt->bindParam(':type', $transactionType);
            
            $reference = $transactionType === 'deposit' 
                ? "Manual top-up by " . $_SESSION['full_name']
                : "Manual adjustment by " . $_SESSION['full_name'];
            
            $logStmt->bindParam(':reference', $reference);
            $logStmt->bindParam(':user_id', $_SESSION['user_id']);
            $logStmt->execute();
            
            sendResponse(true, 'Account balance updated successfully', [
                'id' => $id,
                'name' => $account['name'],
                'previous_balance' => $previousBalance,
                'topup_amount' => $topupAmount,
                'new_balance' => $newBalance,
                'transaction_type' => $transactionType
            ]);
        } else {
            sendResponse(false, 'Failed to update account', null, 500);
        }
    }
    
    // DELETE - Remove funding account
    elseif ($method === 'DELETE') {
        // Check if user is logged in
        if (empty($_SESSION['user_id'])) {
            sendResponse(false, 'Unauthorized', null, 401);
        }
        
        // Check if user has permission
        if ($_SESSION['user_role'] !== 'admin') {
            sendResponse(false, 'Only admins can delete funding accounts', null, 403);
        }
        
        $id = $_GET['id'] ?? null;
        
        if (!$id) {
            sendResponse(false, 'Account ID is required', null, 400);
        }
        
        // Delete transactions first (cascade)
        $transQuery = "DELETE FROM funding_transactions WHERE funding_account_id = :id";
        $transStmt = $db->prepare($transQuery);
        $transStmt->bindParam(':id', $id);
        $transStmt->execute();
        
        // Delete account
        $query = "DELETE FROM funding_accounts WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $id);
        
        if ($stmt->execute() && $stmt->rowCount() > 0) {
            sendResponse(true, 'Account and all associated transactions deleted successfully');
        } else {
            sendResponse(false, 'Account not found', null, 404);
        }
    }
    
    else {
        sendResponse(false, 'Method not allowed', null, 405);
    }
    
} catch (Exception $e) {
    error_log('Funds API error: ' . $e->getMessage());
    sendResponse(false, 'An error occurred: ' . $e->getMessage(), null, 500);
}
?>
