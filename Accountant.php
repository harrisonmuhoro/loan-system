<?php
// Start output buffering early to prevent headers already sent errors
ob_start();

// Configure session settings BEFORE starting session
ini_set('session.gc_maxlifetime', 86400); // 24 hours
ini_set('session.cookie_lifetime', 86400);
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);

// Start session
session_start();

// Regenerate session ID periodically for security
if (!isset($_SESSION['last_regeneration'])) {
    $_SESSION['last_regeneration'] = time();
} elseif (time() - $_SESSION['last_regeneration'] > 1800) { // 30 minutes
    session_regenerate_id(true);
    $_SESSION['last_regeneration'] = time();
}

// Error reporting (suppress display, log errors)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Define BASE_PATH
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__ . '/');
}

// Include config and database EARLY — critical fix!
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

// Initialize database connection BEFORE any DB usage
$database = new Database();
$pdo = $database->getConnection();

if (!$pdo) {
    http_response_code(503);
    die('Database connection failed. Please try again later.');
}

// ==================================================================
// HANDLE RETURN FROM LOANEE PORTAL (ONE PLACE ONLY, NO DUPLICATES)
// ==================================================================

if (isset($_GET['returned_from_loanee']) && $_GET['returned_from_loanee'] == 1) {

    // Prevent redirect loop: only process once per session
    if (!isset($_SESSION['return_processed'])) {
        $_SESSION['return_processed'] = true;

        // Clear all loanee-view flags
        unset(
            $_SESSION['viewing_as_loanee'],
            $_SESSION['from_staff_portal'],
            $_SESSION['original_portal'],
            $_SESSION['original_role'],
            $_SESSION['switching_to_loanee'],
            $_SESSION['can_apply_for_loan']
        );

        // Restore correct role
        $_SESSION['user_role'] = 'accountant';

        // Optional: Log return activity
        try {
            $stmt = $pdo->prepare("INSERT INTO activity_log (admin_id, action, details, timestamp) 
                                   VALUES (?, 'return_from_loanee', 'Returned from loanee portal to accountant portal', NOW())");
            $stmt->execute([$_SESSION['user_id'] ?? null]);
        } catch (Exception $e) {
            error_log("Failed to log return from loanee portal: " . $e->getMessage());
        }

        // Redirect to clean URL
        $currentFile = basename($_SERVER['PHP_SELF']);
        header("Location: $currentFile?returned=1");
        exit();
    }
}

// Mark return as processed (after clean redirect)
if (isset($_GET['returned']) && $_GET['returned'] == 1) {
    $_SESSION['return_processed'] = true;
}

// ==================================================================
// AUTHENTICATION CHECK (after session & DB are ready)
// ==================================================================

function requireAccountantLogin()
{
    if (empty($_SESSION['user_id']) || empty($_SESSION['user_role'])) {
        header("Location: index.php?error=login_required");
        exit();
    }

    if ($_SESSION['user_role'] !== 'accountant') {
        // Redirect based on actual role
        $redirect = match ($_SESSION['user_role']) {
            'admin' => 'admin.php',
            'cashier' => 'cashier.php',
            'loanee' => 'loanee.php',
            default => 'index.php'
        };
        header("Location: $redirect");
        exit();
    }

    // Optional: ensure authenticated flag
    $_SESSION['authenticated'] = true;
}

requireAccountantLogin();

// Update last activity
$_SESSION['last_activity'] = time();

// ==================================================================
// UTILITY FUNCTIONS (safe to define now)
// ==================================================================

function hashPassword($password)
{
    return password_hash($password, PASSWORD_DEFAULT);
}
// Staff management functions
function getAllStaff()
{
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT user_id, pf_number, full_name, email, role, is_active, updated_at FROM users WHERE role IN ('cashier', 'accountant', 'admin') ORDER BY full_name");
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error in getAllStaff: " . $e->getMessage());
        return [];
    }
}

function updateStaffPassword($userId, $newPassword)
{
    global $pdo;
    
    try {
        $hashedPassword = hashPassword($newPassword);
        
        $stmt = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE user_id = ?");
        $stmt->execute([$hashedPassword, $userId]);
        
        return true;
    } catch (PDOException $e) {
        error_log("Error in updateStaffPassword: " . $e->getMessage());
        return false;
    }
}

// Loan management functions
function getAllLoans()
{
    global $pdo;
    
    try {
        $query = "SELECT la.*, 
                    COALESCE((SELECT SUM(amount) FROM payments WHERE application_id = la.application_id), 0) as total_paid,
                    COALESCE((SELECT SUM(principal_amount) FROM payments WHERE application_id = la.application_id), 0) as total_principal_paid,
                    COALESCE((SELECT SUM(interest_amount) FROM payments WHERE application_id = la.application_id), 0) as total_interest_paid,
                    COALESCE((SELECT COUNT(*) FROM payments WHERE application_id = la.application_id), 0) as payment_count
                    FROM loan_applications la 
                    ORDER BY la.application_date DESC";

        $stmt = $pdo->prepare($query);
        $stmt->execute();
        
        $loans = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Map database fields using snake_case for consistency with cashier portal
            $loan = [
                'id' => $row['id'],
                'application_id' => $row['application_id'],
                'full_name' => $row['full_name'],
                'id_number' => $row['id_number'],
                'pf_number' => $row['pf_number'],
                'loan_type' => $row['loan_type'],
                'loan_amount' => floatval($row['loan_amount']),
                'basic_salary' => floatval($row['basic_salary']),
                'payment_duration' => $row['payment_duration'],
                'status' => $row['status'],
                'feedback' => $row['feedback'],
                'application_date' => $row['application_date'],
                'dispatch_date' => $row['dispatch_date'],
                'accountant_processed_date' => $row['accountant_processed_date'],
                'email' => $row['email'] ?? null,
                'employment_type' => $row['employment_type'] ?? null,
                'bank_name' => $row['bank_name'] ?? null,
                'bank_branch' => $row['bank_branch'] ?? null,
                'bank_account_number' => $row['bank_account_number'] ?? null,
                'property_location' => $row['property_location'] ?? null,
                'property_value' => $row['property_value'] ? floatval($row['property_value']) : null,
                'car_type' => $row['car_type'] ?? null,
                'car_value' => $row['car_value'] ? floatval($row['car_value']) : null,
                'contract_period' => $row['contract_period'] ?? null,
                'contract_start_date' => $row['contract_start_date'] ?? null,
                'contract_end_date' => $row['contract_end_date'] ?? null,
                'payment_frequency' => 'monthly'
            ];

            // Get payment history for this loan
            $paymentStmt = $pdo->prepare("SELECT * FROM payments WHERE application_id = ? ORDER BY payment_date DESC");
            $paymentStmt->execute([$row['application_id']]);

            $payments = [];
            while ($payment = $paymentStmt->fetch(PDO::FETCH_ASSOC)) {
                $payments[] = [
                    'payment_id' => $payment['payment_id'],
                    'payment_date' => $payment['payment_date'],
                    'amount' => floatval($payment['amount']),
                    'principal_amount' => floatval($payment['principal_amount']),
                    'interest_amount' => floatval($payment['interest_amount']),
                    'payment_source' => $payment['payment_source'],
                    'recorded_by' => $payment['recorded_by'],
                    'transaction_reference' => $payment['transaction_reference'] ?? null,
                    'notes' => $payment['notes'] ?? null,
                    'recorded_at' => $payment['recorded_at'] ?? null
                ];
            }
            $loan['payments'] = $payments;

            // Get payment schedule
            $scheduleStmt = $pdo->prepare("SELECT * FROM payment_schedules WHERE loan_application_id = ? ORDER BY payment_number");
            $scheduleStmt->execute([$row['id']]);

            $schedule = [];
            while ($sched = $scheduleStmt->fetch(PDO::FETCH_ASSOC)) {
                $schedule[] = [
                    'payment_number' => $sched['payment_number'],
                    'due_date' => $sched['due_date'],
                    'expected_amount' => floatval($sched['expected_amount']),
                    'expected_principal' => floatval($sched['expected_principal']),
                    'expected_interest' => floatval($sched['expected_interest']),
                    'expected_balance' => floatval($sched['expected_balance']),
                    'actual_amount' => floatval($sched['actual_amount'] ?? 0),
                    'actual_interest' => floatval($sched['actual_interest'] ?? 0),
                    'actual_date' => $sched['actual_date'] ?? null,
                    'status' => $sched['status'] ?? 'pending',
                    'variance' => floatval($sched['variance'] ?? 0)
                ];
            }
            $loan['payment_schedule'] = $schedule;

            $loans[] = $loan;
        }

        return $loans;
    } catch (PDOException $e) {
        error_log("Error in getAllLoans: " . $e->getMessage());
        return [];
    }
}

function getLoanById($loanId)
{
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM loan_applications WHERE id = ?");
        $stmt->execute([$loanId]);
        
        $loan = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($loan) {
            // Get payments
            $paymentStmt = $pdo->prepare("SELECT * FROM payments WHERE application_id = ? ORDER BY payment_date DESC");
            $paymentStmt->execute([$loan['application_id']]);
            
            $payments = [];
            while ($payment = $paymentStmt->fetch(PDO::FETCH_ASSOC)) {
                $payments[] = $payment;
            }
            $loan['payments'] = $payments;
        }

        return $loan;
    } catch (PDOException $e) {
        error_log("Error in getLoanById: " . $e->getMessage());
        return null;
    }
}

function updateLoanStatus($loanId, $status, $feedback = null)
{
    global $pdo;
    
    try {
        $accountantId = $_SESSION['user_id'] ?? null;

        $stmt = $pdo->prepare("UPDATE loan_applications SET status = ?, feedback = ?, accountant_processed_date = NOW(), updated_at = NOW() WHERE id = ?");
        $stmt->execute([$status, $feedback, $loanId]);
        
        return true;
    } catch (PDOException $e) {
        error_log("Error in updateLoanStatus: " . $e->getMessage());
        return false;
    }
}

function dispatchLoan($loanId, $fundingAccountId = null)
{
    global $pdo;

    try {
        $pdo->beginTransaction();

        // Get loan amount
        $loanStmt = $pdo->prepare("SELECT loan_amount FROM loan_applications WHERE id = ?");
        $loanStmt->execute([$loanId]);
        $loan = $loanStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$loan) {
            throw new Exception("Loan not found");
        }
        
        $loanAmount = $loan['loan_amount'];

        // Update funding account if provided
        if ($fundingAccountId) {
            // Check if account has sufficient balance
            $fundCheckStmt = $pdo->prepare("SELECT balance, name FROM funding_accounts WHERE id = ?");
            $fundCheckStmt->execute([$fundingAccountId]);
            $fundAccount = $fundCheckStmt->fetch(PDO::FETCH_ASSOC);

            if (!$fundAccount || $fundAccount['balance'] < $loanAmount) {
                throw new Exception("Insufficient funds in selected account");
            }

            // Deduct from funding account
            $fundStmt = $pdo->prepare("UPDATE funding_accounts SET balance = balance - ?, updated_at = NOW() WHERE id = ?");
            $fundStmt->execute([$loanAmount, $fundingAccountId]);

            // Log the transaction
            $logStmt = $pdo->prepare("INSERT INTO funding_transactions (funding_account_id, amount, transaction_type, reference, created_by, created_at) VALUES (?, ?, 'withdrawal', ?, ?, NOW())");
            $reference = "Loan dispatch for application ID: " . $loanId;
            $logStmt->execute([$fundingAccountId, $loanAmount, $reference, $_SESSION['user_id']]);
        }

        // Update loan status
        $dispatchDate = date('Y-m-d H:i:s');
        $updateStmt = $pdo->prepare("UPDATE loan_applications SET status = 'dispatched', dispatch_date = ?, accountant_processed_date = NOW(), updated_at = NOW() WHERE id = ?");
        $updateStmt->execute([$dispatchDate, $loanId]);

        $pdo->commit();

        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error in dispatchLoan: " . $e->getMessage());
        return $e->getMessage();
    }
}

// Payment management functions
function getAllPayments()
{
    global $pdo;
    
    try {
        $query = "SELECT lp.*, la.full_name as loanee_name, la.pf_number, la.loan_amount, la.loan_type 
                    FROM payments lp 
                    JOIN loan_applications la ON lp.application_id = la.application_id 
                    ORDER BY lp.payment_date DESC";

        $stmt = $pdo->prepare($query);
        $stmt->execute();

        $payments = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $payments[] = [
                'payment_id' => $row['payment_id'],
                'loanee_name' => $row['loanee_name'],
                'pf_number' => $row['pf_number'],
                'loan_type' => $row['loan_type'],
                'loan_amount' => floatval($row['loan_amount']),
                'payment_date' => $row['payment_date'],
                'amount' => floatval($row['amount']),
                'principal_amount' => floatval($row['principal_amount']),
                'interest_amount' => floatval($row['interest_amount']),
                'payment_source' => $row['payment_source'],
                'recorded_by' => $row['recorded_by'],
                'transaction_reference' => $row['transaction_reference'] ?? null,
                'notes' => $row['notes'] ?? null,
                'recorded_at' => $row['recorded_at'] ?? null
            ];
        }

        return $payments;
    } catch (PDOException $e) {
        error_log("Error in getAllPayments: " . $e->getMessage());
        return [];
    }
}

function getPaymentsByLoanId($loanId)
{
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM payments WHERE application_id = ? ORDER BY payment_date DESC");
        $stmt->execute([$loanId]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error in getPaymentsByLoanId: " . $e->getMessage());
        return [];
    }
}

// Funding Accounts
function getFundingAccounts()
{
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM funding_accounts ORDER BY name");
        $stmt->execute();

        $accounts = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $accounts[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'account_name' => $row['name'],
                'balance' => floatval($row['balance']),
                'created_at' => $row['created_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null
            ];
        }

        return $accounts;
    } catch (PDOException $e) {
        error_log("Error in getFundingAccounts: " . $e->getMessage());
        return [];
    }
}

// Settings functions
function getLoanSettings()
{
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT setting_key, value FROM system_settings WHERE setting_key IN ('amortization_rate', 'payment_frequency')");
        $stmt->execute();

        $settings = [
            'interest_rate' => 3.0,
            'payment_frequency' => 'monthly'
        ];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($row['setting_key'] == 'amortization_rate') {
                $settings['interest_rate'] = floatval($row['value']);
            } else if ($row['setting_key'] == 'payment_frequency') {
                $settings['payment_frequency'] = $row['value'];
            }
        }

        return $settings;
    } catch (PDOException $e) {
        error_log("Error in getLoanSettings: " . $e->getMessage());
        return [
            'interest_rate' => 3.0,
            'payment_frequency' => 'monthly'
        ];
    }
}

function saveLoanSettings($interestRate, $paymentFrequency)
{
    global $pdo;
    
    try {
        $updatedBy = $_SESSION['user_id'] ?? null;

        // Update or insert interest rate
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, value, updated_by, updated_at) VALUES ('amortization_rate', ?, ?, NOW()) ON DUPLICATE KEY UPDATE value = ?, updated_by = ?, updated_at = NOW()");
        $rateStr = strval($interestRate);
        $stmt->execute([$rateStr, $updatedBy, $rateStr, $updatedBy]);

        // Update or insert payment frequency
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, value, updated_by, updated_at) VALUES ('payment_frequency', ?, ?, NOW()) ON DUPLICATE KEY UPDATE value = ?, updated_by = ?, updated_at = NOW()");
        $stmt->execute([$paymentFrequency, $updatedBy, $paymentFrequency, $updatedBy]);

        return true;
    } catch (PDOException $e) {
        error_log("Error in saveLoanSettings: " . $e->getMessage());
        return false;
    }
}

// Export functions
function exportLoansToCSV($filter = 'all')
{
    global $pdo;

    try {
        $query = "SELECT * FROM loan_applications";
        $params = [];

        if ($filter !== 'all') {
            $query .= " WHERE status = ?";
            $params[] = $filter;
        }

        $query .= " ORDER BY application_date DESC";

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        $filename = "loans_export_" . date('Y-m-d') . ".csv";
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');

        fputcsv($output, [
            'ID',
            'Application ID',
            'Full Name',
            'ID Number',
            'PF Number',
            'Loan Type',
            'Application Date',
            'Basic Salary',
            'Loan Amount',
            'Payment Duration',
            'Status',
            'Feedback',
            'Accountant Processed Date',
            'Dispatch Date'
        ]);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['id'],
                $row['application_id'],
                $row['full_name'],
                $row['id_number'],
                $row['pf_number'],
                $row['loan_type'],
                $row['application_date'],
                $row['basic_salary'],
                $row['loan_amount'],
                $row['payment_duration'],
                $row['status'],
                $row['feedback'],
                $row['accountant_processed_date'],
                $row['dispatch_date']
            ]);
        }

        fclose($output);
        exit();
    } catch (PDOException $e) {
        error_log("Error in exportLoansToCSV: " . $e->getMessage());
        header('HTTP/1.1 500 Internal Server Error');
        echo "Error exporting loans";
        exit();
    }
}

// Initialize session variables for JavaScript
function initAccountantSession()
{
    // Session data is now accessed directly from PHP session
    // No need to store in localStorage
}

// API Handlers
function handleApiRequest()
{
    header('Content-Type: application/json');

    $endpoint = $_GET['endpoint'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'];

    try {
        switch ($endpoint) {
            case 'loans':
                if ($method === 'GET') {
                    $loans = getAllLoans();
                    echo json_encode(['success' => true, 'data' => $loans]);
                } else if ($method === 'POST') {
                    $input = json_decode(file_get_contents('php://input'), true);

                    if (isset($input['action'])) {
                        if ($input['action'] === 'update_status') {
                            $loanId = $input['loan_id'];
                            $status = $input['status'];
                            $feedback = $input['feedback'] ?? null;

                            $result = updateLoanStatus($loanId, $status, $feedback);
                            echo json_encode(['success' => $result]);
                        } else if ($input['action'] === 'dispatch') {
                            $loanId = $input['loan_id'];
                            $fundingAccountId = $input['funding_account_id'] ?? null;

                            $result = dispatchLoan($loanId, $fundingAccountId);
                            if ($result === true) {
                                echo json_encode(['success' => true]);
                            } else {
                                echo json_encode(['success' => false, 'message' => $result]);
                            }
                        } else {
                            echo json_encode(['success' => false, 'message' => 'Invalid action']);
                        }
                    } else {
                        echo json_encode(['success' => false, 'message' => 'No action specified']);
                    }
                } else {
                    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
                }
                break;

            case 'payments':
                if ($method === 'GET') {
                    $payments = getAllPayments();
                    echo json_encode(['success' => true, 'data' => $payments]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
                }
                break;

            case 'funding':
                if ($method === 'GET') {
                    $accounts = getFundingAccounts();
                    echo json_encode(['success' => true, 'data' => $accounts]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
                }
                break;

            case 'settings':
                if ($method === 'GET') {
                    $settings = getLoanSettings();
                    echo json_encode(['success' => true, 'data' => $settings]);
                } else if ($method === 'POST') {
                    $input = json_decode(file_get_contents('php://input'), true);

                    $interestRate = $input['interest_rate'] ?? 3.0;
                    $paymentFrequency = $input['payment_frequency'] ?? 'monthly';

                    $result = saveLoanSettings($interestRate, $paymentFrequency);
                    echo json_encode(['success' => $result]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
                }
                break;

            case 'export':
                if ($method === 'GET') {
                    $filter = $_GET['filter'] ?? 'all';
                    exportLoansToCSV($filter);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
                }
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid endpoint']);
                break;
        }
    } catch (Exception $e) {
        error_log("API Error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }

    exit;
}

// Check if this is an API request
if (isset($_GET['api'])) {
    handleApiRequest();
    exit;
}

// Check if this is a logout request
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header("Location: index.php?logout=success");
    exit();
}

// Add this function to all three staff portals
// FIXED: Apply for loan function
function applyForLoan() {
    // Store original portal info BEFORE any checks
    $_SESSION['original_portal'] = 'cashier';
    $_SESSION['viewing_as_loanee'] = true;
    $_SESSION['from_staff_portal'] = true;
    $_SESSION['staff_original_role'] = $_SESSION['user_role'];
    
    // Set a flag that the user is switching to loanee portal
    $_SESSION['switching_to_loanee'] = true;
    
    // Log the switch
    error_log("Cashier applying for loan: " . print_r([
        'user_id' => $_SESSION['user_id'],
        'pf_number' => $_SESSION['pf_number'],
        'role' => $_SESSION['user_role']
    ], true));
    
    // Redirect to loanee portal with proper parameters
    header("Location: loanee.php?from=cashier&action=switch_portal");
    exit();
}

// Handle the apply for loan action
if (isset($_GET['action']) && $_GET['action'] === 'apply_for_loan') {
    applyForLoan();
}


// Get system interest rate for JavaScript
 $interest_rate = 3.0;
try {
    $stmt = $pdo->prepare("SELECT value FROM system_settings WHERE setting_key = 'amortization_rate'");
    $stmt->execute();
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $interest_rate = floatval($row['value']);
    }
} catch (PDOException $e) {
    error_log("Error getting interest rate: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Accountant Portal</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <link rel="stylesheet" href="accountant.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="js/logout-handler.js"></script>
    <style>
    /* ===== CSS Variables ===== */
    :root {
        --green: #4a6b4a;
        --blue: #3a6b9a;
        --muted: #6c757d;
        --danger: #b33a3a;
        --warning: #ff9800;
        --success: #4caf50;
        --info: #17a2b8;
        --sidebar-bg: #2c3e50;
        --bg: #f5f9f7;

        /* Light theme */
        --bg-primary: #f5f9f7;
        --bg-secondary: #fff;
        --bg-tertiary: #f8f9fa;
        --text-primary: #333;
        --text-secondary: #6c757d;
        --text-inverse: #fff;
        --border-color: #e6e6e6;
        --shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        --shadow-hover: 0 4px 20px rgba(0, 0, 0, 0.15);
    }

    [data-theme="dark"] {
        --bg-primary: #1a1a1a;
        --bg-secondary: #2d2d2d;
        --bg-tertiary: #3a3a3a;
        --text-primary: #f0f0f0;
        --text-secondary: #a0a0a0;
        --text-inverse: #1a1a1a;
        --border-color: #444;
        --shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
        --shadow-hover: 0 4px 20px rgba(0, 0, 0, 0.5);
    }

    /* ===== Base Styles ===== */
    * {
        box-sizing: border-box;
    }

    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        margin: 0;
        background: var(--bg-primary);
        color: var(--text-primary);
        line-height: 1.6;
        transition: background-color 0.3s, color 0.3s;
    }

    /* ===== Header ===== */
    header {
        background: var(--green);
        color: var(--text-inverse);
        padding: 0.5rem 1rem;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        z-index: 2000;
        display: flex;
        align-items: center;
        justify-content: center;
        height: 70px;
        box-shadow: var(--shadow);
        transition: background-color 0.3s;
    }

    .hamburger {
        position: absolute;
        left: 1rem;
        background: transparent;
        border: 0;
        color: var(--text-inverse);
        font-size: 1.5rem;
        cursor: pointer;
        padding: 0.5rem;
        border-radius: 4px;
        transition: background-color 0.2s;
    }

    .hamburger:hover {
        background-color: rgba(255, 255, 255, 0.1);
    }

    .logo-box {
        display: flex;
        align-items: center;
        gap: 15px;
        justify-content: center;
    }

    .logo-box img {
        height: 50px;
        width: auto;
        max-width: 50px;
        object-fit: contain;
        border-radius: 4px;
    }

    .logo-box h1 {
        margin: 0;
        font-size: 1.5rem;
        font-weight: 600;
        white-space: nowrap;
    }

    /* ===== Profile Badge ===== */
    .profile-badge {
        position: absolute;
        right: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
        background: rgba(255, 255, 255, 0.1);
        padding: 5px 12px;
        border-radius: 20px;
        cursor: pointer;
        transition: background-color 0.2s;
    }

    .profile-badge:hover {
        background: rgba(255, 255, 255, 0.2);
    }

    .profile-avatar {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: var(--blue);
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        color: white;
        font-size: 14px;
    }

    .profile-info {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
    }

    .profile-name {
        font-size: 14px;
        font-weight: 600;
        margin: 0;
        line-height: 1.2;
    }

    .profile-role {
        font-size: 11px;
        opacity: 0.8;
        margin: 0;
        line-height: 1;
    }

    .profile-dropdown {
        position: absolute;
        top: 100%;
        right: 0;
        margin-top: 5px;
        background: var(--bg-secondary);
        border-radius: 8px;
        box-shadow: var(--shadow-hover);
        min-width: 200px;
        z-index: 1000;
        display: none;
    }

    .profile-dropdown.active {
        display: block;
    }

    .profile-dropdown-item {
        padding: 10px 15px;
        display: flex;
        align-items: center;
        gap: 10px;
        cursor: pointer;
        transition: background-color 0.2s;
        border-bottom: 1px solid var(--border-color);
    }

    .profile-dropdown-item:last-child {
        border-bottom: none;
    }

    .profile-dropdown-item:hover {
        background: var(--bg-tertiary);
    }

    .profile-dropdown-item.danger {
        color: var(--danger);
    }

    /* ===== Sidebar ===== */
    nav {
        position: fixed;
        top: 70px;
        bottom: 0;
        left: 0;
        width: 240px;
        background: var(--sidebar-bg);
        color: var(--text-inverse);
        padding: 12px 8px;
        box-shadow: 2px 0 6px rgba(0, 0, 0, 0.08);
        overflow-y: auto;
        transition: all 0.3s ease;
        z-index: 1000;
    }

    nav.collapsed {
        width: 70px !important;
        padding: 12px 0;
    }

    @media (max-width: 768px) {
        nav {
            transform: translateX(-100%);
            width: 200px;
        }

        nav.open {
            transform: translateX(0);
            box-shadow: 4px 0 10px rgba(0, 0, 0, 0.3);
        }
    }

    nav .sidebar-logo {
        display: flex;
        align-items: center;
        padding: 15px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        margin-bottom: 10px;
    }

    nav .sidebar-logo img {
        height: 50px;
        width: auto;
        object-fit: contain;
        margin-right: 10px;
    }

    nav .sidebar-logo .small {
        font-size: 1.2rem;
        font-weight: bold;
        color: white;
    }

    nav.collapsed .sidebar-logo {
        justify-content: center;
        padding: 15px 10px;
    }

    nav.collapsed .sidebar-logo img {
        height: 40px;
        margin-right: 0;
    }

    nav.collapsed .sidebar-logo .small {
        display: none;
    }

    nav ul {
        list-style: none;
        margin: 0;
        padding: 0;
    }

    nav li {
        display: flex;
        align-items: center;
        justify-content: flex-start;
        gap: 12px;
        padding: 12px;
        border-radius: 8px;
        margin-bottom: 6px;
        cursor: pointer;
        transition: all 0.2s;
        position: relative;
    }

    nav.collapsed li {
        justify-content: center;
        gap: 0;
        padding: 12px 0;
    }

    nav li:hover {
        background: rgba(255, 255, 255, 0.1);
        transform: translateX(5px);
    }

    nav.collapsed li:hover::after {
        content: attr(data-tooltip);
        position: absolute;
        left: 80px;
        top: 50%;
        transform: translateY(-50%);
        background: #333;
        color: white;
        padding: 6px 10px;
        border-radius: 4px;
        font-size: 13px;
        white-space: nowrap;
        z-index: 1000;
        opacity: 1;
        pointer-events: none;
    }

    nav li.active {
        background: var(--blue);
        color: var(--text-inverse);
    }

    nav li.active::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 4px;
        background: var(--green);
    }

    nav.collapsed li .label {
        display: none;
    }

    /* ===== Main Content ===== */
    .main {
        flex-grow: 1;
        padding: 100px 40px 20px 0px;
        overflow-y: auto;
        transition: all 0.3s ease;
        padding-left: 240px !important;
    }

    .main.sidebar-open {
        padding-left: 240px;
    }

    .main.sidebar-collapsed {
        padding-left: 90px;
    }

    @media (max-width: 768px) {
        .main {
            padding: 100px 15px 15px 15px;
        }
    }

    nav li {
        white-space: nowrap;
    }

    /* ===== Sections ===== */
    .hidden {
        display: none !important;
    }

    section {
        margin-bottom: 2rem;
    }

    h2 {
        color: var(--text-primary);
        margin: 0 0 1rem;
        font-size: 1.8rem;
        border-bottom: 2px solid var(--border-color);
        padding-bottom: 0.5rem;
    }

    h3 {
        color: var(--text-primary);
        margin: 1.5rem 0 1rem;
        font-size: 1.3rem;
    }

    /* ===== Tables ===== */
    .table-container {
        background: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 8px;
        overflow: auto;
        box-shadow: var(--shadow);
        margin-bottom: 1.5rem;
        width: 100%;
        max-width: 100%;
        margin-left: 0;
        margin-right: 0;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
    }

    th,
    td {
        padding: 12px;
        border-bottom: 1px solid var(--border-color);
        text-align: left;
    }

    th {
        background: var(--blue);
        color: var(--text-inverse);
        position: sticky;
        top: 0;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.85rem;
        letter-spacing: 0.5px;
    }

    tr:hover {
        background: var(--bg-tertiary);
    }

    /* Status row colors */
    tr.pending {
        background: #fff9e6;
    }

    tr.forwarded {
        background: #cce7ff;
    }

    tr.accepted {
        background: #d4edda;
    }

    tr.dispatched {
        background: #a7e9a7;
    }

    tr.returned {
        background: #ffeaa7;
    }

    tr.rejected {
        background: #f8d7da;
    }

    /* ===== Cards & Forms ===== */
    .account-card {
        background: var(--bg-secondary);
        border: 1px solid var(--border-color);
        padding: 1.5rem;
        border-radius: 8px;
        margin-bottom: 1rem;
        box-shadow: var(--shadow);
        transition: transform 0.2s, box-shadow 0.2s;
    }

    .account-card:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-hover);
    }

    input,
    select,
    textarea {
        padding: 10px;
        border-radius: 6px;
        border: 1px solid var(--border-color);
        font-size: 14px;
        background: var(--bg-secondary);
        color: var(--text-primary);
        transition: border-color 0.2s, box-shadow 0.2s;
    }

    input:focus,
    select:focus,
    textarea:focus {
        border-color: var(--blue);
        box-shadow: 0 0 0 3px rgba(58, 107, 154, 0.1);
    }

    button {
        padding: 10px 16px;
        border-radius: 6px;
        border: 0;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    button:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    }

    button:active {
        transform: translateY(0);
    }

    button:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none;
    }

    .btn-primary {
        background: var(--blue);
        color: var(--text-inverse);
    }

    .btn-success {
        background: var(--success);
        color: var(--text-inverse);
    }

    .btn-danger {
        background: var(--danger);
        color: var(--text-inverse);
    }

    .btn-secondary {
        background: var(--muted);
        color: var(--text-inverse);
    }

    .btn-warning {
        background: var(--warning);
        color: var(--text-inverse);
    }

    .btn-info {
        background: var(--info);
        color: var(--text-inverse);
    }

    .btn-outline {
        background: transparent;
        border: 1px solid var(--border-color);
        color: var(--text-primary);
    }

    .btn-outline:hover {
        background: var(--bg-tertiary);
    }

    .small {
        font-size: 13px;
        color: var(--text-secondary);
    }

    .row {
        display: flex;
        gap: 12px;
        align-items: center;
        flex-wrap: wrap;
    }

    .row>* {
        flex: 1;
        min-width: 150px;
    }

    label {
        font-weight: 600;
        margin-right: 6px;
        color: var(--text-primary);
    }

    .center {
        display: flex;
        align-items: center;
        justify-content: center;
    }

    /* ===== Modal Improvements ===== */
    .modal {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 2000;
        animation: fadeIn 0.3s;
        padding: 20px;
        overflow-y: auto;
    }

    .modal-content {
        background: var(--bg-secondary);
        border-radius: 8px;
        width: 95%;
        max-width: 1000px;
        max-height: 90vh;
        box-shadow: var(--shadow-hover);
        animation: slideUp 0.3s;
        display: flex;
        flex-direction: column;
        margin: auto;
        overflow: hidden;
    }

    .modal-header {
        padding: 1.5rem;
        border-bottom: 1px solid var(--border-color);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-shrink: 0;
        position: sticky;
        top: 0;
        background: var(--bg-secondary);
        z-index: 10;
    }

    .modal-header h2 {
        margin: 0;
        border: none;
        padding: 0;
        font-size: 1.5rem;
    }

    .modal-body {
        padding: 1.5rem;
        overflow-y: auto;
        flex: 1;
        scrollbar-width: thin;
        scrollbar-color: var(--border-color) transparent;
    }

    .modal-body::-webkit-scrollbar {
        width: 8px;
    }

    .modal-body::-webkit-scrollbar-track {
        background: transparent;
    }

    .modal-body::-webkit-scrollbar-thumb {
        background: var(--border-color);
        border-radius: 4px;
    }

    .modal-body::-webkit-scrollbar-thumb:hover {
        background: var(--text-secondary);
    }

    .table-responsive {
        overflow-x: auto;
        margin-bottom: 1rem;
        border-radius: 4px;
        border: 1px solid var(--border-color);
    }

    .modal-body table {
        width: 100%;
        font-size: 0.85rem;
        border-collapse: collapse;
        margin-top: 10px;
        min-width: 600px;
    }

    .modal-body table thead tr {
        background: var(--blue);
        color: white;
    }

    .modal-body table th,
    .modal-body table td {
        padding: 10px;
        border: 1px solid var(--border-color);
        text-align: left;
        word-wrap: break-word;
    }

    .modal-body table tbody tr:hover {
        background: var(--bg-tertiary);
    }

    /* ===== Responsive Design ===== */
    @media (max-width: 768px) {
        main {
            padding: 100px 15px 15px 15px;
        }

        #sidebar {
            width: 200px;
            transform: translateX(-100%);
        }

        #sidebar.open {
            transform: translateX(0);
        }

        .hamburger {
            display: flex;
        }

        .logo-box h1 {
            font-size: 1.3rem;
        }

        .profile-badge {
            right: 10px;
            padding: 3px 8px;
        }

        .profile-name {
            font-size: 0.75rem;
        }

        .profile-role {
            font-size: 0.65rem;
        }

        .modal-content {
            width: 95%;
            max-width: 95%;
            max-height: 95vh;
        }

        .modal-header,
        .modal-body {
            padding: 1rem;
        }

        .modal-header h2 {
            font-size: 1.2rem;
        }

        .modal-body table {
            font-size: 0.75rem;
            min-width: 500px;
        }

        .modal-body table th,
        .modal-body table td {
            padding: 6px;
        }

        .balance-highlight {
            padding: 15px;
        }

        .balance-highlight h3 {
            font-size: 1.1rem;
        }

        .stats {
            grid-template-columns: 1fr;
        }

        nav.open::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: -1;
        }
    }

    /* ===== Animations ===== */
    @keyframes fadeIn {
        from {
            opacity: 0;
        }

        to {
            opacity: 1;
        }
    }

    @keyframes slideUp {
        from {
            transform: translateY(50px);
            opacity: 0;
        }

        to {
            transform: translateY(0);
            opacity: 1;
        }
    }

    .close-btn {
        color: #aaa;
        float: right;
        font-size: 28px;
        cursor: pointer;
        transition: color 0.3s ease;
    }

    .close-btn:hover {
        color: #000;
    }

    /* ===== Export Button ===== */
    .export-btn {
        background: var(--info);
        color: var(--text-inverse);
        margin-bottom: 1rem;
        margin-right: 1rem;
    }

    /* ===== Balance Highlight Section ===== */
    .balance-highlight {
        background: linear-gradient(135deg, #e8f5e9 0%, #f1f8f4 100%);
        border-left: 4px solid var(--green);
        padding: 20px;
        margin-bottom: 24px;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    }

    .balance-highlight h3 {
        color: var(--green);
        margin: 0 0 15px 0;
        font-size: 1.2rem;
        border-bottom: 2px solid var(--green);
        padding-bottom: 8px;
    }

    .balance-highlight table {
        width: 100%;
        font-size: 0.95rem;
        border-collapse: collapse;
    }

    .balance-highlight table tr {
        border-bottom: 1px solid rgba(0, 0, 0, 0.05);
    }

    .balance-highlight table tr:last-child {
        border-bottom: none;
    }

    .balance-highlight table td {
        padding: 10px 8px;
    }

    .balance-highlight table td:first-child {
        font-weight: 600;
        color: #333;
    }

    .balance-highlight table td:last-child {
        text-align: right;
        font-weight: 600;
    }

    .balance-highlight p {
        margin: 10px 0 0 0;
        font-size: 0.85rem;
        color: #666;
    }

    /* ===== Grace Period Notice ===== */
    .grace-period-notice {
        background: #e3f2fd;
        border: 1px solid #90caf9;
        border-left: 4px solid var(--blue);
        border-radius: 6px;
        padding: 15px;
        margin: 20px 0;
    }

    .grace-period-notice h4 {
        margin: 0 0 10px 0;
        color: var(--blue);
        font-size: 1.1rem;
    }

    .grace-period-notice p {
        margin: 8px 0;
        font-size: 0.9rem;
    }

    /* ===== Stats Cards ===== */
    .stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .stat-card {
        background: var(--bg-secondary);
        border-radius: 8px;
        padding: 20px;
        box-shadow: var(--shadow);
        text-align: center;
        transition: transform 0.2s, box-shadow 0.2s;
    }

    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-hover);
    }

    .stat-number {
        font-size: 2rem;
        font-weight: bold;
        color: var(--blue);
        margin-bottom: 10px;
    }

    .stat-label {
        font-size: 0.9rem;
        color: var(--text-secondary);
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    /* ===== Funding Account Selection ===== */
    .funding-account-option {
        cursor: pointer;
        border: 2px solid var(--border-color);
        transition: all 0.3s;
        margin-bottom: 10px;
    }

    .funding-account-option:hover {
        border-color: var(--blue);
        transform: translateY(-2px);
    }

    .funding-account-option.selected {
        border-color: var(--green);
        background: rgba(74, 107, 74, 0.1);
    }

    .funding-account-option .radio-btn {
        width: 20px;
        height: 20px;
        margin-right: 10px;
    }

    .insufficient-funds {
        color: var(--danger);
        font-weight: bold;
    }

    /* ===== Dark Theme Adjustments ===== */
    [data-theme="dark"] .balance-highlight {
        background: linear-gradient(135deg, #1e3a2e 0%, #243830 100%);
        border-left-color: #4a6b4a;
    }

    [data-theme="dark"] .balance-highlight h3 {
        color: #66bb6a;
        border-bottom-color: #4a6b4a;
    }

    [data-theme="dark"] .grace-period-notice {
        background: #1a2b3a;
        border-color: #3a6b9a;
    }

    [data-theme="dark"] .grace-period-notice h4 {
        color: #64b5f6;
    }

    [data-theme="dark"] .close-btn:hover {
        color: #fff;
        background: rgba(255, 255, 255, 0.1);
    }

    /* ===== Responsive Design ===== */
    @media (max-width: 480px) {
        .logo-box img {
            height: 40px;
            max-width: 40px;
        }

        .logo-box h1 {
            font-size: 1.1rem;
        }

        .profile-badge {
            flex-direction: column;
            padding: 5px;
        }

        .profile-info {
            margin-bottom: 5px;
        }

        header {
            height: auto;
            min-height: 70px;
            padding: 0.5rem;
        }

        main {
            padding-top: 80px;
        }

        table {
            font-size: 0.75rem;
        }

        th,
        td {
            padding: 0.5rem 0.3rem;
        }

        button.action-btn {
            padding: 0.3rem 0.6rem;
            font-size: 0.75rem;
            margin-bottom: 3px;
        }

        .modal {
            padding: 10px;
        }

        .modal-content {
            max-height: 98vh;
        }

        .balance-highlight table {
            font-size: 0.85rem;
        }

        .grace-period-notice {
            padding: 12px;
        }

        .grace-period-notice h4 {
            font-size: 1rem;
        }
    }

    /* Responsive logo adjustments */
    @media (max-width: 480px) {

        .logo-box img,
        .logo {
            height: 40px;
            max-width: 40px;
        }

        .logo-box h1 {
            font-size: 1.2rem;
        }

        .stats {
            grid-template-columns: 1fr;
        }
    }

    @keyframes spin {
        0% {
            transform: rotate(0deg);
        }

        100% {
            transform: rotate(360deg);
        }
    }

    .apply-loan-btn {
        background: linear-gradient(135deg, #4a6b4a 0%, #5a7a2a 100%);
        color: white;
        border: none;
        cursor: pointer;
        padding: 12px 20px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 500;
        margin-top: 20px;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .apply-loan-btn:hover {
        background: linear-gradient(135deg, #5a7a2a 0%, #4a7a2a 100%);
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
    }

    .apply-loan-btn:active {
        background: linear-gradient(135deg, #5a7a2a 0%, #4a7a2a 100%);
        transform: translateY(0);
    }
    </style>
</head>

<body>
    <!-- Main Content (Hidden Initially) -->
    <div id="main-content">
        <!-- MAIN HEADER -->
        <header id="mainHeader">
            <button id="menuToggle" class="hamburger" aria-label="Toggle navigation menu">
                ☰
            </button>
            <div class="logo-box">
                <img src="logo.jpg" alt="Logo">
                <h1>Accountant Portal</h1>
            </div>

            <!-- Profile Badge -->
            <div class="profile-badge" id="profileBadge">
                <div class="profile-avatar" id="profileAvatar">
                    <?php echo strtoupper(substr($_SESSION['full_name'], 0, 2)); ?>
                </div>
                <div class="profile-info">
                    <p class="profile-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></p>
                    <p class="profile-role">Accountant</p>
                </div>
                <div class="profile-dropdown" id="profileDropdown">
                    <div class="profile-dropdown-item danger" id="logoutBtn">
                        🚪 Logout
                    </div>
                </div>
            </div>
        </header>

        <!-- SIDEBAR NAVIGATION -->
        <nav id="sidebar" role="navigation" aria-label="Accountant navigation">
            <div class="sidebar-logo">
                <img src="logo.jpg" alt="logo">
                <div class="small">Accountant Portal</div>
            </div>
            <ul>
                <li id="navLoans" tabindex="0" role="button" data-filter="all">
                    💰 <span class="label">All Loans</span>
                </li>
                <li id="navForwarded" tabindex="0" role="button" data-filter="forwarded">
                    📋 <span class="label">Forwarded (Pending)</span>
                </li>
                <li id="navAccepted" tabindex="0" role="button" data-filter="accepted">
                    ✅ <span class="label">Accepted (Awaiting Dispatch)</span>
                </li>
                <li id="navDispatched" tabindex="0" role="button" data-filter="dispatched">
                    🚚 <span class="label">Dispatched</span>
                </li>
                <li id="navActive" tabindex="0" role="button" data-filter="active">
                    🔄 <span class="label">Active Loans</span>
                </li>
                <li id="navRejected" tabindex="0" role="button" data-filter="rejected">
                    ❌ <span class="label">Rejected</span>
                </li>
                <li id="navClosed" tabindex="0" role="button" data-filter="closed">
                    ✅ <span class="label">Closed</span>
                </li>
                <li id="navPayments" tabindex="0" role="button" data-filter="payments">
                    💳 <span class="label">Payment Records</span>
                </li>
                <li id="navAccounts" tabindex="0" role="button" data-filter="accounts">
                    🏦 <span class="label">Funding Accounts</span>
                </li>
                <li id="navReports" tabindex="0" role="button" data-filter="reports">
                    📊 <span class="label">Financial Reports</span>
                </li>
                <button class="tooltip" onclick="applyForLoan()">
                    <span class="button-icon">📝</span>
                    <span class="button-text">Apply for Loan</span>
                </button>



            </ul>
            <div style="margin-top: 16px;">
                <button id="logoutBtnSidebar" class="btn-danger" style="width: calc(100% - 16px); margin: 0 8px;">
                    🚪 <span class="label">Logout</span>
                </button>
            </div>
        </nav>

        <!-- MAIN CONTENT -->
        <main class="main" id="main">
            <!-- Loans Section -->
            <section id="loansSection" class="hidden">
                <h2 id="sectionTitle">All Loans</h2>
                <button id="exportLoansBtn" class="export-btn">⬇ Export Loans</button>
                <button id="exportAmortizationBtn" class="export-btn">⬇ Export Amortization</button>
                <div class="table-container">
                    <table id="loansTable">
                        <thead>
                            <tr>
                                <th>Full Name</th>
                                <th>ID Number</th>
                                <th>PF Number</th>
                                <th>Loan Type</th>
                                <th>Details</th>
                                <th>Basic Salary</th>
                                <th>Loan Amount</th>
                                <th>Current Balance</th>
                                <th>Status</th>
                                <th>Feedback</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </section>

            <!-- Payments Section -->
            <section id="paymentsSection" class="hidden">
                <h2>Payment Records</h2>
                <button id="exportPaymentsBtn" class="export-btn">⬇ Export Payments</button>
                <div class="table-container">
                    <table id="paymentsTable">
                        <thead>
                            <tr>
                                <th>Loanee Name</th>
                                <th>PF Number</th>
                                <th>Payment Date</th>
                                <th>Principal Amount</th>
                                <th>Interest Amount</th>
                                <th>Total Amount</th>
                                <th>Payment Source</th>
                                <th>Recorded By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </section>

            <!-- Funding Section -->
            <section id="fundingSection" class="hidden">
                <h2>Funding Accounts</h2>
                <div id="fundingAccountsContainer"></div>
            </section>

            <!-- Financial Reports Section -->
            <section id="reportsSection" class="hidden">
                <h2>📊 Financial Reports & Analytics</h2>

                <!-- Export Buttons -->
                <div style="margin-bottom: 20px;">
                    <button id="exportOverallSummaryBtn" class="export-btn">⬇ Export Overall Summary</button>
                    <button id="exportMortgageLoansBtn" class="export-btn">⬇ Export Mortgage Loans</button>
                    <button id="exportCarLoansBtn" class="export-btn">⬇ Export Car Loans</button>
                    <button id="exportPaymentSummaryBtn" class="export-btn">⬇ Export Payment Summary</button>
                    <button id="exportCompleteReportBtn" class="export-btn" style="background: var(--danger);">⬇ Export
                        Complete Report</button>
                </div>

                <!-- Reports Content -->
                <div id="reportsContent"></div>
            </section>
        </main>

        <!-- Loan Details Modal -->
        <div id="detailsModal" class="modal">
            <div class="modal-content">
                <span class="close-btn" id="closeDetailsModal">&times;</span>
                <h2>Loan Details</h2>
                <div id="loanDetailsContent" class="modal-body"></div>
            </div>
        </div>

        <!-- Payment Details Modal -->
        <div id="paymentDetailsModal" class="modal">
            <div class="modal-content">
                <span class="close-btn" id="closePaymentDetailsModal">&times;</span>
                <h2>Payment Details</h2>
                <div id="paymentDetailsContent" class="modal-body"></div>
            </div>
        </div>

        <!-- Amortization Modal -->
        <div id="amortizationModal" class="modal">
            <div class="modal-content" style="max-width:1200px;">
                <span class="close-btn" id="closeAmort">&times;</span>
                <h2>📊 Payment Schedule & Amortization</h2>
                <div id="amortInfo" class="balance-highlight"></div>
                <div class="modal-body" style="overflow-x: auto;">
                    <table style="width:100%; border-collapse:collapse; font-size:0.85rem; min-width:800px;">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Due Date</th>
                                <th>Expected Payment</th>
                                <th>Actual Payment</th>
                                <th>Expected Interest</th>
                                <th>Actual Interest</th>
                                <th>Principal</th>
                                <th>Balance</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="amortTableBody"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Feedback Modal -->
        <div id="feedbackModal" class="modal">
            <div class="modal-content" style="max-width:500px;">
                <span class="close-btn" id="closeFeedbackModal">&times;</span>
                <h2 id="feedbackTitle">Provide Feedback</h2>
                <div class="modal-body">
                    <textarea id="feedbackTextarea" rows="5" style="width: 100%; margin: 10px 0;"></textarea>
                </div>
                <div class="modal-footer">
                    <button id="cancelFeedback" class="btn-secondary">Cancel</button>
                    <button id="submitFeedback" class="btn-primary">Submit</button>
                </div>
            </div>
        </div>

        <!-- Funding Account Selection Modal -->
        <div id="fundingAccountModal" class="modal">
            <div class="modal-content">
                <span class="close-btn" id="closeFundingModal">&times;</span>
                <h2>Select Funding Account</h2>
                <div class="account-card">
                    <p><strong>Loanee:</strong> <span id="modalLoaneeName"></span></p>
                    <p><strong>Loan Amount:</strong> KES <span id="modalLoanAmount"></span></p>
                    <p><strong>Loan Type:</strong> <span id="modalLoanType"></span></p>
                </div>
                <div class="account-card">
                    <h3>Available Funding Accounts</h3>
                    <div id="fundingAccountsList"></div>
                    <div id="noAccountsMessage" class="hidden" style="text-align: center; padding: 20px; color: #666;">
                        No funding accounts available. Please contact the administrator.
                    </div>
                </div>
                <div class="modal-footer">
                    <button id="cancelDispatch" class="btn-secondary">Cancel</button>
                    <button id="confirmDispatch" class="btn-primary" disabled>Dispatch Funds</button>
                </div>
            </div>
        </div>

        <!-- Notification -->
        <div id="notification"
            style="display:none;position:fixed;top:80px;right:20px;padding:15px;border-radius:5px;box-shadow:0 4px 8px rgba(0,0,0,0.2);z-index:1000;max-width:300px;">
        </div>
    </div>

    <script>
    // ==========================================
    // GLOBAL VARIABLES AND CONFIGURATION
    // ==========================================
    const API_BASE_URL = window.location.pathname;
    const SYSTEM_INTEREST_RATE = <?php echo $interest_rate; ?>;

    // Authentication and User Data
    const APP_STATE = {
        currentUser: null,
        currentPaymentLoan: null,
        currentActionLoan: null,
        currentActionType: null,
        csrfToken: null,
        selectedFundingAccountId: null,
        currentDispatchLoan: null
    };

    // DOM Element References
    const DOM_ELEMENTS = {
        sidebar: document.getElementById('sidebar'),
        sectionTitle: document.getElementById('sectionTitle'),
        loansTable: document.getElementById('loansTable'),
        notification: document.getElementById('notification'),
        profileBadge: document.getElementById('profileBadge'),
        profileDropdown: document.getElementById('profileDropdown'),
        mainContent: document.getElementById('main-content'),
        fundingAccountModal: document.getElementById('fundingAccountModal'),
        modalLoaneeName: document.getElementById('modalLoaneeName'),
        modalLoanAmount: document.getElementById('modalLoanAmount'),
        modalLoanType: document.getElementById('modalLoanType'),
        fundingAccountsList: document.getElementById('fundingAccountsList'),
        noAccountsMessage: document.getElementById('noAccountsMessage'),
        confirmDispatch: document.getElementById('confirmDispatch')
    };

    // ==========================================
    // UTILITY FUNCTIONS
    // ==========================================
    function formatCurrency(amount) {
        return new Intl.NumberFormat().format(Math.round(amount));
    }

    function formatDate(dateString) {
        if (!dateString) return 'N/A';
        const date = new Date(dateString);
        return date.toLocaleDateString();
    }

    function formatDateTime(dateString) {
        if (!dateString) return 'N/A';
        const date = new Date(dateString);
        return date.toLocaleString();
    }

    function formatApplicationId(applicationId) {
        if (!applicationId) return 'N/A';
        return applicationId;
    }

    function formatAccountNumber(accountNumber) {
        if (!accountNumber || accountNumber === 'N/A') return 'N/A';
        return accountNumber;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // ==========================================
    // API FUNCTIONS
    // ==========================================
    async function apiCall(endpoint, method = 'GET', data = null) {
        const options = {
            method: method,
            headers: {
                'Content-Type': 'application/json',
            }
        };
        if (data) {
            options.body = JSON.stringify(data);
        }
        try {
            const response = await fetch(`${API_BASE_URL}?api&endpoint=${endpoint}`, options);
            if (!response.ok) {
                let errorMessage = `HTTP error! status: ${response.status}`;
                try {
                    const errorData = await response.json();
                    errorMessage = errorData.message || errorMessage;
                } catch (e) {
                    errorMessage = response.statusText || errorMessage;
                }
                throw new Error(errorMessage);
            }
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                throw new Error('Server returned an invalid response. Please contact support.');
            }
            const result = await response.json();
            if (!result.success) {
                throw new Error(result.message || 'Request failed');
            }
            return result;
        } catch (error) {
            throw error;
        }
    }

    // ==========================================
    // NOTIFICATION SYSTEM
    // ==========================================
    function showNotification(message, type = 'success', duration = 3000) {
        const notification = document.getElementById('notification');
        if (!notification) {
            return;
        }

        notification.textContent = message;
        notification.className = `notification show ${type}`;
        notification.style.display = 'block';

        setTimeout(() => {
            notification.classList.remove('show');
        }, duration);
    }
    // ==========================================
    // FUNDING ACCOUNT SELECTION MODAL
    // ==========================================
    async function showFundingAccountModal(loan) {
        APP_STATE.currentDispatchLoan = loan;
        APP_STATE.selectedFundingAccountId = null;

        // Populate loan details in modal
        DOM_ELEMENTS.modalLoaneeName.textContent = loan.full_name || 'N/A';
        DOM_ELEMENTS.modalLoanAmount.textContent = formatCurrency(loan.loan_amount || 0);
        DOM_ELEMENTS.modalLoanType.textContent = loan.loan_type ? loan.loan_type.charAt(0).toUpperCase() + loan
            .loan_type.slice(1) : 'N/A';

        // Fetch funding accounts
        try {
            const response = await apiCall('funding');
            const accounts = response.data || [];

            if (accounts.length === 0) {
                DOM_ELEMENTS.fundingAccountsList.innerHTML = '';
                DOM_ELEMENTS.noAccountsMessage.classList.remove('hidden');
                DOM_ELEMENTS.confirmDispatch.disabled = true;
            } else {
                DOM_ELEMENTS.noAccountsMessage.classList.add('hidden');

                const loanAmount = loan.loan_amount || 0;
                DOM_ELEMENTS.fundingAccountsList.innerHTML = accounts.map(account => {
                    const hasSufficientFunds = account.balance >= loanAmount;
                    const balanceClass = hasSufficientFunds ? '' : 'insufficient-funds';
                    const disabledAttr = hasSufficientFunds ? '' : 'disabled';

                    return `
                        <div class="funding-account-option ${!hasSufficientFunds ? 'disabled' : ''}" data-id="${account.id}">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <input type="radio" name="fundingAccount" value="${account.id}" class="radio-btn" ${disabledAttr}>
                                    <strong>${escapeHtml(account.name || 'Unnamed Account')}</strong>
                                </div>
                                <div class="${balanceClass}">
                                    Balance: KES ${formatCurrency(account.balance || 0)}
                                    ${!hasSufficientFunds ? '<br><small>(Insufficient funds)</small>' : ''}
                                </div>
                            </div>
                        </div>
                    `;
                }).join('');

                // Add click handlers to account options
                document.querySelectorAll('.funding-account-option:not(.disabled)').forEach(option => {
                    option.addEventListener('click', function() {
                        const radio = this.querySelector('input[type="radio"]');
                        if (radio && !radio.disabled) {
                            // Uncheck all radios
                            document.querySelectorAll('.funding-account-option input[type="radio"]')
                                .forEach(r => r.checked = false);
                            // Check this radio
                            radio.checked = true;
                            // Update selected state
                            document.querySelectorAll('.funding-account-option').forEach(opt => opt
                                .classList.remove('selected'));
                            this.classList.add('selected');
                            // Store selected ID
                            APP_STATE.selectedFundingAccountId = this.dataset.id;
                            // Enable confirm button
                            DOM_ELEMENTS.confirmDispatch.disabled = false;
                        }
                    });
                });

                DOM_ELEMENTS.confirmDispatch.disabled = true;
            }

            // Show modal
            DOM_ELEMENTS.fundingAccountModal.style.display = 'block';

        } catch (error) {
            showNotification('Failed to load funding accounts: ' + error.message, 'error');
        }
    }

    // ==========================================
    // LOAN CALCULATIONS
    // ==========================================
    function calculateMonthlyInterest(principal, annualRate, months = 1) {
        if (principal <= 0 || annualRate <= 0) return 0;
        const monthlyRate = annualRate / 100 / 12;
        return principal * monthlyRate * Math.max(1, months);
    }

    async function recalculateLoanBalance(loan) {
        const annualRate = 3.0; // Default interest rate
        const payments = Array.isArray(loan.payments) ? loan.payments : [];

        let totalPaid = 0;
        let principalPaid = 0;
        let interestPaid = 0;

        payments.forEach(payment => {
            const amount = parseFloat(payment.amount) || 0;
            const principal = parseFloat(payment.principal_amount) || 0; // Use snake_case
            const interest = parseFloat(payment.interest_amount) || 0; // Use snake_case

            totalPaid += amount;
            principalPaid += principal;
            interestPaid += interest;
        });

        const originalAmount = parseFloat(loan.loan_amount) || 0; // Use snake_case
        const currentPrincipal = Math.max(0, originalAmount - principalPaid);

        let lastPaymentDate = null;
        if (payments.length > 0) {
            const sortedPayments = [...payments].sort((a, b) =>
                new Date(b.payment_date) - new Date(a.payment_date) // Use snake_case
            );
            lastPaymentDate = new Date(sortedPayments[0].payment_date); // Use snake_case
        } else {
            lastPaymentDate = loan.dispatch_date ? new Date(loan.dispatch_date) : new Date(); // Use snake_case
        }

        const today = new Date();
        const timeDiff = today - lastPaymentDate;
        const daysSinceLastPayment = Math.floor(timeDiff / (1000 * 60 * 60 * 24));
        const monthsSinceLastPayment = Math.max(1, Math.round(daysSinceLastPayment / 30));

        const accruedInterest = currentPrincipal > 0 ?
            calculateMonthlyInterest(currentPrincipal, annualRate, monthsSinceLastPayment) :
            0;

        const totalBalanceDue = currentPrincipal + accruedInterest;

        return {
            originalAmount,
            totalPaid,
            principalPaid,
            interestPaid,
            currentPrincipal,
            accruedInterest: accruedInterest.toFixed(2),
            totalBalanceDue: totalBalanceDue.toFixed(2),
            lastPaymentDate: lastPaymentDate.toISOString().split('T')[0],
            monthsSinceLastPayment,
            daysSinceLastPayment
        };
    }

    // ==========================================
    // LOAN DATA MANAGEMENT
    // ==========================================
    async function getAllLoans() {
        try {
            const response = await apiCall('loans');
            return response.data || [];
        } catch (error) {
            return [];
        }
    }

    // ==========================================
    // RENDER FUNCTIONS
    // ==========================================
    async function renderLoans(filter) {
        let loans = [];

        try {
            loans = await getAllLoans();
        } catch (error) {
            showNotification('Failed to load loan data: ' + error.message, 'error');
            return;
        }

        // Show appropriate section
        const loanSection = document.getElementById('loansSection');
        const paymentsSection = document.getElementById('paymentsSection');
        const fundingSection = document.getElementById('fundingSection');

        loanSection.classList.remove('hidden');
        paymentsSection.classList.add('hidden');
        fundingSection.classList.add('hidden');
        document.getElementById('dashboardSection')?.classList.add('hidden');

        if (!DOM_ELEMENTS.loansTable) {
            showNotification('The loan table could not be initialized. Please refresh the page.', 'error');
            return;
        }

        switch (filter) {
            case 'forwarded':
                loans = loans.filter(l => l.status === 'forwarded');
                DOM_ELEMENTS.sectionTitle.textContent = 'Loans Forwarded to Accountant';
                break;
            case 'accepted':
                loans = loans.filter(l => l.status === 'accepted');
                DOM_ELEMENTS.sectionTitle.textContent = 'Accepted Loans (Awaiting Dispatch)';
                break;
            case 'dispatched':
                loans = loans.filter(l => l.status === 'dispatched' || l.status === 'active');
                DOM_ELEMENTS.sectionTitle.textContent = 'Dispatched/Active Loans';
                break;
            case 'active':
                loans = loans.filter(l => l.status === 'active');
                DOM_ELEMENTS.sectionTitle.textContent = 'Active Loans (In Repayment)';
                break;
            case 'rejected':
                loans = loans.filter(l => l.status === 'rejected');
                DOM_ELEMENTS.sectionTitle.textContent = 'Rejected Loans';
                break;
            case 'closed':
                loans = loans.filter(l => l.status === 'closed');
                DOM_ELEMENTS.sectionTitle.textContent = 'Closed Loans';
                break;
            case 'all':
            default:
                DOM_ELEMENTS.sectionTitle.textContent = 'All Loans';
        }

        const loansTableBody = DOM_ELEMENTS.loansTable.querySelector('tbody');
        if (!loansTableBody) {
            return;
        }

        loansTableBody.innerHTML = '';
        if (loans.length === 0) {
            loansTableBody.innerHTML =
                '<tr><td colspan="11" style="text-align:center; padding: 30px;">No loan applications found for this filter.</td></tr>';
            return;
        }

        loans.sort((a, b) => new Date(b.application_date) - new Date(a.application_date)); // Use snake_case
        loans.forEach(loan => {
            const row = loansTableBody.insertRow();
            row.className = `status-${loan.status}`;
            row.insertCell().textContent = loan.full_name || 'N/A';
            row.insertCell().textContent = loan.id_number || 'N/A';
            row.insertCell().textContent = loan.pf_number || 'N/A';
            row.insertCell().textContent = loan.loan_type ? loan.loan_type.charAt(0).toUpperCase() + loan
                .loan_type.slice(1) : 'N/A';

            const detailsCell = row.insertCell();
            const detailsBtn = document.createElement('button');
            detailsBtn.textContent = 'View';
            detailsBtn.className = 'action-btn';
            detailsBtn.onclick = () => showLoanDetails(loan);
            detailsCell.appendChild(detailsBtn);

            row.insertCell().textContent = loan.basic_salary ? `KES ${formatCurrency(loan.basic_salary)}` :
                'N/A'; // Use snake_case
            row.insertCell().textContent = loan.loan_amount ? `KES ${formatCurrency(loan.loan_amount)}` :
                'N/A'; // Use snake_case

            const balanceCell = row.insertCell();
            if (loan.status === 'active' || loan.status === 'dispatched') {
                recalculateLoanBalance(loan).then(balanceInfo => {
                    balanceCell.innerHTML =
                        `<strong>KES ${formatCurrency(balanceInfo.totalBalanceDue)}</strong>`;
                    if (balanceInfo.accruedInterest > 0) {
                        balanceCell.innerHTML +=
                            `<br><small style="color:#d84315;">+KES ${formatCurrency(balanceInfo.accruedInterest)} interest</small>`;
                    }
                });
            } else {
                balanceCell.textContent = '-';
            }

            row.insertCell().textContent = loan.status ? loan.status.toUpperCase() : 'PENDING';
            row.insertCell().textContent = loan.feedback || 'None';

            const actionsCell = row.insertCell();
            actionsCell.className = 'actions-cell';
            actionsCell.innerHTML = '';

            if (loan.status === 'forwarded') {
                const acceptBtn = document.createElement('button');
                acceptBtn.textContent = 'Accept';
                acceptBtn.className = 'action-btn';
                acceptBtn.onclick = () => handleLoanAction(loan, 'accept');
                actionsCell.appendChild(acceptBtn);

                const rejectBtn = document.createElement('button');
                rejectBtn.textContent = 'Reject';
                rejectBtn.className = 'action-btn reject-btn';
                rejectBtn.onclick = () => showFeedbackModal(loan, 'reject');
                actionsCell.appendChild(rejectBtn);
            } else if (loan.status === 'accepted') {
                const dispatchBtn = document.createElement('button');
                dispatchBtn.textContent = 'Dispatch';
                dispatchBtn.className = 'action-btn';
                dispatchBtn.onclick = () => showFundingAccountModal(loan);
                actionsCell.appendChild(dispatchBtn);
            } else if (loan.status === 'dispatched' || loan.status === 'active') {
                const amortBtn = document.createElement('button');
                amortBtn.textContent = 'Schedule';
                amortBtn.className = 'action-btn';
                amortBtn.onclick = () => showAmortization(loan);
                actionsCell.appendChild(amortBtn);
            }
        });
    }

    // ==========================================
    // MODAL FUNCTIONS
    // ==========================================
    async function showLoanDetails(loan) {
        let detailsHtml = `
        <button onclick="exportLoanDetails()" class="action-btn" style="float:right;background:#2196F3;margin-bottom:15px;">
            Export as PDF
        </button>
       
        <div class="balance-highlight">
            <h3 style="color:#2e7d32; margin-top:0;">Current Loan Financial Status</h3>
    `;

        const balanceInfo = await recalculateLoanBalance(loan);

        detailsHtml += `
            <table style="width:100%; font-size:0.95rem;">
                <tr>
                    <td><strong>Original Loan Amount:</strong></td>
                    <td style="text-align:right;">KES ${formatCurrency(loan.loan_amount)}</td>
                </tr>
                <tr>
                    <td><strong>Total Paid:</strong></td>
                    <td style="text-align:right; color:#2e7d32;">KES ${formatCurrency(balanceInfo.totalPaid)}</td>
                </tr>
                <tr>
                    <td><strong>Principal Paid:</strong></td>
                    <td style="text-align:right;">KES ${formatCurrency(balanceInfo.principalPaid)}</td>
                </tr>
                <tr>
                    <td><strong>Interest Paid:</strong></td>
                    <td style="text-align:right; color:#d84315;">KES ${formatCurrency(balanceInfo.interestPaid)}</td>
                </tr>
                <tr style="border-top: 1px solid #ccc;">
                    <td><strong>Current Principal Balance:</strong></td>
                    <td style="text-align:right;">KES ${formatCurrency(balanceInfo.currentPrincipal)}</td>
                </tr>
                <tr>
                    <td><strong>Accrued Interest (${balanceInfo.monthsSinceLastPayment} months):</strong></td>
                    <td style="text-align:right; color:#d84315;">KES ${formatCurrency(balanceInfo.accruedInterest)}</td>
                </tr>
                <tr style="border-top:2px solid #2e7d32; font-weight:bold; font-size:1.1rem;">
                    <td><strong>Total Balance Due:</strong></td>
                    <td style="text-align:right; color:#c62828;">KES ${formatCurrency(balanceInfo.totalBalanceDue)}</td>
                </tr>
            </table>
            <p style="margin:10px 0 0 0; font-size:0.85rem; color:#666;">
                Last Payment: ${formatDate(balanceInfo.lastPaymentDate)} (${balanceInfo.monthsSinceLastPayment} months ago)
            </p>
        </div>
        
        <h3 style="color:#4a6b4a;">Loanee Information</h3>
        <p><strong>Full Name:</strong> ${loan.full_name}</p>
        <p><strong>ID Number:</strong> ${loan.id_number}</p>
        <p><strong>PF Number:</strong> ${loan.pf_number}</p>
        <p><strong>Email:</strong> ${loan.email || 'N/A'}</p>
        <hr style="margin: 20px 0; border: none; border-top: 1px solid #e0e0e0;">
        <h3 style="color:#4a6b4a;">Loan Details</h3>
        <p><strong>Loan Type:</strong> ${loan.loan_type ? loan.loan_type.charAt(0).toUpperCase() + loan.loan_type.slice(1) : 'N/A'}</p>
        <p><strong>Application Date:</strong> ${loan.application_date ? formatDateTime(loan.application_date) : 'N/A'}</p>
        <p><strong>Status:</strong> <span style="font-weight:bold; color:#4a6b4a;">${loan.status ? loan.status.toUpperCase() : 'PENDING'}</span></p>
        <p><strong>Payment Duration:</strong> ${loan.payment_duration || 'N/A'} years</p>
        <p><strong>Payment Frequency:</strong> Monthly</p>
        <p><strong>Basic Salary:</strong> KES ${loan.basic_salary ? formatCurrency(loan.basic_salary) : 'N/A'}</p>
        <p><strong>Employment Type:</strong> ${loan.employment_type || 'N/A'}</p>
    `;

        if (loan.employment_type === 'contracted') {
            detailsHtml += `
            <p><strong>Contract Period:</strong> ${loan.contract_period || 'N/A'} months</p>
            <p><strong>Contract Start:</strong> ${loan.contract_start_date || 'N/A'}</p>
            <p><strong>Contract End:</strong> ${loan.contract_end_date || 'N/A'}</p>
        `;
        }
        if (loan.loan_type === 'mortgage') {
            detailsHtml += `
            <p><strong>Property Location:</strong> ${loan.property_location || 'N/A'}</p>
            <p><strong>Property Value:</strong> KES ${loan.property_value ? formatCurrency(loan.property_value) : 'N/A'}</p>
        `;
        }
        if (loan.loan_type === 'car') {
            detailsHtml += `
            <p><strong>Car Model:</strong> ${loan.car_type || 'N/A'}</p>
            <p><strong>Car Value:</strong> KES ${loan.car_value ? formatCurrency(loan.car_value) : 'N/A'}</p>
        `;
        }
        detailsHtml += `
        <p><strong>Bank:</strong> ${loan.bank_name || 'N/A'} (${loan.bank_branch || 'N/A'})</p>
        <p><strong>Account Number:</strong> ${loan.bank_account_number || 'N/A'}</p>
    `;

        if (loan.dispatch_date) {
            detailsHtml += `<p><strong>Dispatch Date:</strong> ${formatDateTime(loan.dispatch_date)}</p>`;
            const dispatchDate = new Date(loan.dispatch_date);
            const gracePeriodEndDate = new Date(dispatchDate);
            gracePeriodEndDate.setMonth(gracePeriodEndDate.getMonth() + 1);
            detailsHtml += `
            <div class="grace-period-notice">
                <h4>Grace Period Information</h4>
                <p><strong>Dispatch Date:</strong> ${formatDate(loan.dispatch_date)}</p>
                <p><strong>Grace Period End:</strong> ${formatDate(gracePeriodEndDate.toISOString())}</p>
                <p><strong>First Payment Due:</strong> ${formatDate(new Date(gracePeriodEndDate.getFullYear(), gracePeriodEndDate.getMonth() + 1, gracePeriodEndDate.getDate()))}</p>
                <p>The loanee has a one-month grace period after funds are dispatched before the first payment is due.</p>
            </div>
        `;
        }

        if (loan.payments && loan.payments.length > 0) {
            detailsHtml += `
            <hr style="margin: 20px 0; border: none; border-top: 1px solid #e0e0e0;">
            <h3 style="color:#4a6b4a;">Payment History (${loan.payments.length} payments)</h3>
            <div class="table-responsive">
                <table style="width:100%; font-size:0.85rem; border-collapse:collapse; margin-top:10px;">
                    <thead>
                        <tr style="background:#3a6b9a; color:white;">
                            <th style="padding:8px; border:1px solid #ccc;">#</th>
                            <th style="padding:8px; border:1px solid #ccc;">Date</th>
                            <th style="padding:8px; border:1px solid #ccc;">Amount</th>
                            <th style="padding:8px; border:1px solid #ccc;">Interest</th>
                            <th style="padding:8px; border:1px solid #ccc;">Principal</th>
                            <th style="padding:8px; border:1px solid #ccc;">Source</th>
                            <th style="padding:8px; border:1px solid #ccc;">Ref ID</th>
                            <th style="padding:8px; border:1px solid #ccc;">Recorded By</th>
                        </tr>
                    </thead>
                    <tbody>
        `;
            loan.payments.sort((a, b) => new Date(b.payment_date) - new Date(a.payment_date));
            loan.payments.forEach((p, idx) => {
                detailsHtml += `
                <tr>
                    <td style="padding:8px; border:1px solid #ccc;">${idx + 1}</td>
                    <td style="padding:8px; border:1px solid #ccc;">${formatDate(p.payment_date)}</td>
                    <td style="padding:8px; border:1px solid #ccc; font-weight:bold;">KES ${formatCurrency(p.amount)}</td>
                    <td style="padding:8px; border:1px solid #ccc; color:#d84315;">KES ${formatCurrency(p.interest_amount || 0)}</td>
                    <td style="padding:8px; border:1px solid #ccc; color:#2e7d32;">KES ${formatCurrency(p.principal_amount || 0)}</td>
                    <td style="padding:8px; border:1px solid #ccc; font-size:0.8rem;">${p.payment_source}</td>
                    <td style="padding:8px; border:1px solid #ccc; font-size:0.75rem; color:#666;">${p.transaction_reference || '-'}</td>
                    <td style="padding:8px; border:1px solid #ccc; font-size:0.8rem;">${p.recorded_by || 'Cashier'}</td>
                </tr>
            `;
            });
            detailsHtml += `
                    </tbody>
                </table>
            </div>
        `;
        } else {
            detailsHtml += `
            <hr style="margin: 20px 0; border: none; border-top: 1px solid #e0e0e0;">
            <h3 style="color:#4a6b4a;">Payment History</h3>
            <p style="text-align:center; padding:20px; color:#666;">No payments recorded yet.</p>
        `;
        }

        detailsHtml += `
        <hr style="margin: 20px 0; border: none; border-top: 1px solid #e0e0e0;">
        <p><strong>Accountant Process Date:</strong> ${loan.accountant_processed_date ? formatDateTime(loan.accountant_processed_date) : 'N/A'}</p>
        <p><strong>Feedback:</strong> ${loan.feedback || 'None'}</p>
    `;

        document.getElementById('loanDetailsContent').innerHTML = detailsHtml;
        document.getElementById('detailsModal').style.display = 'block';
    }

    function showFeedbackModal(loan, actionType) {
        if (!loan || !actionType) return;
        APP_STATE.currentActionLoan = loan;
        APP_STATE.currentActionType = actionType;

        const modal = document.getElementById('feedbackModal');
        const title = document.getElementById('feedbackTitle');
        const textarea = document.getElementById('feedbackTextarea');

        title.textContent = actionType === 'reject' ? 'Reject Loan: Provide Reason' : 'Provide Feedback';
        textarea.value = '';
        modal.style.display = 'block';
    }

    // ==========================================
    // AMORTIZATION FUNCTIONS
    // ==========================================
    async function generatePaymentSchedule(loan) {
        const rate = 3.0; // Default interest rate
        loan.payment_frequency = 'monthly';

        const dispatchDate = new Date(loan.dispatch_date || loan.application_date);
        const gracePeriodEndDate = new Date(dispatchDate);
        gracePeriodEndDate.setMonth(gracePeriodEndDate.getMonth() + 1);

        const startDate = new Date(gracePeriodEndDate);
        const principal = parseFloat(loan.loan_amount);
        const years = loan.payment_duration || 1;
        let schedule = [];
        let paymentsPerYear = 12;
        const totalPayments = years * paymentsPerYear;
        const periodicRate = (rate / 100) / paymentsPerYear;
        const periodicPayment = principal * (periodicRate * Math.pow(1 + periodicRate, totalPayments)) /
            (Math.pow(1 + periodicRate, totalPayments) - 1);
        let balance = principal;

        for (let i = 0; i < totalPayments; i++) {
            const dueDate = new Date(startDate);
            dueDate.setMonth(startDate.getMonth() + i);
            const interestPayment = balance * periodicRate;
            const principalPayment = periodicPayment - interestPayment;
            balance -= principalPayment;
            schedule.push({
                payment_number: i + 1,
                due_date: dueDate.toISOString(),
                expected_amount: periodicPayment,
                expected_principal: principalPayment,
                expected_interest: interestPayment,
                expected_balance: Math.max(0, balance),
                status: 'pending',
                actual_amount: 0,
                actual_interest: 0,
                actual_date: null,
                variance: 0
            });
        }

        return {
            schedule,
            grace_period_end_date: gracePeriodEndDate.toISOString(),
            start_date: startDate.toISOString()
        };
    }

    async function updatePaymentScheduleWithActuals(loan) {
        const scheduleData = await generatePaymentSchedule(loan);
        loan.payment_schedule = scheduleData.schedule;
        loan.grace_period_end_date = scheduleData.grace_period_end_date;
        loan.payment_start_date = scheduleData.start_date;

        const payments = loan.payments || [];
        payments.forEach(payment => {
            const paymentDate = new Date(payment.payment_date);
            const paymentMonth = paymentDate.getMonth();
            const paymentYear = paymentDate.getFullYear();

            const scheduleIndex = loan.payment_schedule.findIndex(scheduled => {
                const scheduledDate = new Date(scheduled.due_date);
                return scheduledDate.getMonth() === paymentMonth &&
                    scheduledDate.getFullYear() === paymentYear;
            });

            if (scheduleIndex >= 0) {
                const scheduled = loan.payment_schedule[scheduleIndex];
                scheduled.actual_amount = parseFloat(payment.amount) || 0;
                scheduled.actual_interest = parseFloat(payment.interest_amount) || 0;
                scheduled.actual_date = payment.payment_date;
                scheduled.variance = scheduled.actual_amount - scheduled.expected_amount;
                scheduled.status = scheduled.actual_amount >= scheduled.expected_amount ? 'paid' :
                    'partial';
            }
        });

        return loan;
    }

    async function showAmortization(loan) {
        if (!loan) return;
        const amortTableBody = document.getElementById('amortTableBody');
        amortTableBody.innerHTML = '';
        const balanceInfo = await recalculateLoanBalance(loan);
        const schedule = await updatePaymentScheduleWithActuals(loan);
        const amortInfo = document.getElementById('amortInfo');

        let gracePeriodNotice = '';
        if (loan.dispatch_date) {
            const dispatchDate = new Date(loan.dispatch_date);
            const gracePeriodEndDate = new Date(dispatchDate);
            gracePeriodEndDate.setMonth(gracePeriodEndDate.getMonth() + 1);
            gracePeriodNotice = `
                <div class="grace-period-notice">
                    <h4>Grace Period Information</h4>
                    <p><strong>Dispatch Date:</strong> ${formatDate(loan.dispatch_date)}</p>
                    <p><strong>Grace Period End:</strong> ${formatDate(gracePeriodEndDate.toISOString())}</p>
                    <p><strong>First Payment Due:</strong> ${formatDate(new Date(gracePeriodEndDate.getFullYear(), gracePeriodEndDate.getMonth() + 1, gracePeriodEndDate.getDate()))}</p>
                    <p>The loanee has a one-month grace period after funds are dispatched before the first payment is due.</p>
                </div>
            `;
        }

        amortInfo.innerHTML = `
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <h3 style="margin:0; color:#2e7d32;">Payment Schedule Summary</h3>
                <button onclick="exportAmortization()" class="action-btn" style="background:#2196F3;">Export Schedule</button>
            </div>
            ${gracePeriodNotice}
            <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:20px;margin-top:20px;">
                <div>
                    <strong>Original Loan:                    KES ${formatCurrency(loan.loan_amount)}</strong><br>
                    <strong>Current Balance:</strong> <span style="color:#c62828;">KES ${formatCurrency(balanceInfo.totalBalanceDue)}</span>
                </div>
                <div>
                    <strong>Total Paid:</strong> KES ${formatCurrency(balanceInfo.totalPaid)}<br>
                    <strong>Interest Rate:</strong> 3% per year
                </div>
                <div>
                    <strong>Payment Frequency:</strong> Monthly<br>
                    <strong>Months Since Last Payment:</strong> ${balanceInfo.monthsSinceLastPayment}
                </div>
            </div>
        `;

        if (!schedule.payment_schedule || schedule.payment_schedule.length === 0) {
            amortTableBody.innerHTML =
                '<tr><td colspan="9" style="text-align:center; padding:30px;">No payment schedule generated yet. Loan must be dispatched first.</td></tr>';
            document.getElementById('amortizationModal').style.display = 'block';
            return;
        }

        schedule.payment_schedule.forEach((payment) => {
            const statusColors = {
                'paid': '#d4edda',
                'partial': '#fff3cd',
                'pending': '#ffffff',
                'overdue': '#f8d7da'
            };
            const row = `
                <tr style="background:${statusColors[payment.status] || '#fff'};">
                    <td>${payment.payment_number}</td>
                    <td>${formatDate(payment.due_date)}</td>
                    <td>KES ${formatCurrency(payment.expected_amount)}</td>
                    <td>${payment.actual_amount > 0 ? 'KES ' + formatCurrency(payment.actual_amount) : '-'}</td>
                    <td style="color:#d84315;">KES ${formatCurrency(payment.expected_interest)}</td>
                    <td style="color:#d84315; font-weight:bold;">${payment.actual_interest > 0 ? 'KES ' + formatCurrency(payment.actual_interest) : '-'}</td>
                    <td>KES ${formatCurrency(payment.expected_principal)}</td>
                    <td>KES ${formatCurrency(payment.expected_balance)}</td>
                    <td><strong>${payment.status.toUpperCase()}</strong>${payment.variance !== 0 && payment.actual_amount > 0 ?
                        `<br><small style="color:${payment.variance > 0 ? 'green' : 'red'};">${payment.variance > 0 ? '+' : ''}KES ${Math.round(payment.variance)}</small>` : ''}</td>
                </tr>
            `;
            amortTableBody.innerHTML += row;
        });
        document.getElementById('amortizationModal').style.display = 'block';
    }

    // ==========================================
    // LOAN ACTIONS
    // ==========================================
    async function handleLoanAction(loan, action) {
        try {
            if (action === 'accept') {
                const result = await apiCall('loans', 'POST', {
                    action: 'update_status',
                    loan_id: loan.id,
                    status: 'accepted',
                    feedback: 'Accepted by Accountant'
                });
                if (result.success) {
                    showNotification(`Loan for ${loan.full_name} accepted.`, 'success');
                }
            } else if (action === 'dispatch') {
                showFundingAccountModal(loan);
                return;
            }
            renderLoans(document.querySelector('#sidebar li.active').dataset.filter);
        } catch (error) {
            showNotification('Failed to update loan: ' + error.message, 'error');
        }
    }

    async function confirmDispatch() {
        if (!APP_STATE.selectedFundingAccountId) {
            showNotification('Please select a funding account', 'error');
            return;
        }

        if (!APP_STATE.currentDispatchLoan) {
            showNotification('No loan selected for dispatch', 'error');
            return;
        }

        try {
            const result = await apiCall('loans', 'POST', {
                action: 'dispatch',
                loan_id: APP_STATE.currentDispatchLoan.id,
                funding_account_id: APP_STATE.selectedFundingAccountId
            });

            if (result.success) {
                showNotification(`Loan for ${APP_STATE.currentDispatchLoan.full_name} dispatched successfully.`,
                    'success');
                document.getElementById('fundingAccountModal').style.display = 'none';
                renderLoans(document.querySelector('#sidebar li.active').dataset.filter);
            } else {
                showNotification(result.message || 'Failed to dispatch loan', 'error');
            }
        } catch (error) {
            showNotification('Failed to dispatch loan: ' + error.message, 'error');
        }
    }

    async function submitFeedback() {
        const feedback = document.getElementById('feedbackTextarea').value.trim();
        if (!feedback) {
            showNotification('Feedback/Reason is required.', 'error');
            return;
        }
        try {
            const result = await apiCall('loans', 'POST', {
                action: 'update_status',
                loan_id: APP_STATE.currentActionLoan.id,
                status: APP_STATE.currentActionType,
                feedback: feedback
            });

            if (result.success) {
                showNotification(
                    `Loan ${APP_STATE.currentActionType === 'reject' ? 'rejected' : 'updated'} successfully!`,
                    'success');
                document.getElementById('feedbackModal').style.display = 'none';
                renderLoans(document.querySelector('#sidebar li.active').dataset.filter);
            }
        } catch (error) {
            showNotification('Failed to update loan: ' + error.message, 'error');
        }
    }

    // ==========================================
    // LOAD PAYMENTS AND ACCOUNTS
    // ==========================================
    async function loadPayments() {
        try {
            const response = await apiCall('payments');
            const payments = response.data || [];

            document.getElementById('loansSection')?.classList.add('hidden');
            document.getElementById('fundingSection')?.classList.add('hidden');
            document.getElementById('dashboardSection')?.classList.add('hidden');
            document.getElementById('paymentsSection')?.classList.remove('hidden');

            const tbody = document.querySelector('#paymentsTable tbody');
            if (!tbody) {
                showNotification('Payments table not found.', 'error');
                return;
            }

            tbody.innerHTML = '';

            if (payments.length === 0) {
                tbody.innerHTML = `
                <tr>
                    <td colspan="9" style="text-align:center; padding: 30px; color: #666;">
                        No payment records found.
                    </td>
                </tr>
            `;
                return;
            }

            payments.forEach(payment => {
                const row = tbody.insertRow();

                row.insertCell().textContent = escapeHtml(payment.loanee_name || '—');
                row.insertCell().textContent = payment.pf_number || '—';
                row.insertCell().textContent = formatDate(payment.payment_date);
                row.insertCell().textContent = `KES ${formatCurrency(payment.principal_amount)}`;
                row.insertCell().textContent = `KES ${formatCurrency(payment.interest_amount)}`;
                row.insertCell().textContent = `KES ${formatCurrency(payment.amount)}`;
                row.insertCell().textContent = payment.payment_source || '—';
                row.insertCell().textContent = payment.recorded_by || '—';

                const actionsCell = row.insertCell();
                const viewBtn = document.createElement('button');
                viewBtn.textContent = 'View';
                viewBtn.className = 'btn btn-sm btn-info';
                viewBtn.style.margin = '2px';
                viewBtn.onclick = () => showPaymentDetails(payment);
                actionsCell.appendChild(viewBtn);
            });

        } catch (error) {
            showNotification('Failed to load payments: ' + (error.message || 'Network error'), 'error');
        }
    }

    async function loadFundingAccounts() {
        try {
            const response = await apiCall('funding');
            const accounts = response.data || [];

            document.getElementById('loansSection')?.classList.add('hidden');
            document.getElementById('paymentsSection')?.classList.add('hidden');
            document.getElementById('dashboardSection')?.classList.add('hidden');
            document.getElementById('fundingSection')?.classList.remove('hidden');

            const container = document.getElementById('fundingAccountsContainer');
            if (!container) {
                showNotification('Funding container not found.', 'error');
                return;
            }

            container.innerHTML = '';

            if (accounts.length === 0) {
                container.innerHTML =
                    '<p style="text-align:center; padding: 30px; color: #666;">No funding accounts found.</p>';
                return;
            }

            let html = `
            <div class="table-container">
                <table style="width:100%; border-collapse:collapse; margin-top: 10px;">
                    <thead>
                        <tr style="background:#3a6b9a; color:white; text-align:left;">
                            <th style="padding:12px;">Account Name</th>
                            <th style="padding:12px;">Balance</th>
                            <th style="padding:12px;">Created At</th>
                        </tr>
                    </thead>
                    <tbody>
        `;

            accounts.forEach(account => {
                html += `
                <tr style="border-bottom: 1px solid #eee;">
                    <td style="padding:12px;">${escapeHtml(account.name || '—')}</td>
                    <td style="padding:12px; font-weight:600;">KES ${formatCurrency(account.balance)}</td>
                    <td style="padding:12px; color:#555;">${formatDate(account.created_at)}</td>
                </tr>
            `;
            });

            html += `
                    </tbody>
                </table>
            </div>
        `;

            container.innerHTML = html;

        } catch (error) {
            showNotification('Failed to load funding accounts: ' + (error.message || 'Network error'), 'error');
        }
    }

    function showPaymentDetails(payment) {
        const modal = document.getElementById('paymentDetailsModal');
        const content = document.getElementById('paymentDetailsContent');

        content.innerHTML = `
            <p><strong>Loanee Name:</strong> ${payment.loanee_name}</p>
            <p><strong>PF Number:</strong> ${payment.pf_number}</p>
            <p><strong>Payment Date:</strong> ${formatDate(payment.payment_date)}</p>
            <p><strong>Principal Amount:</strong> KES ${formatCurrency(payment.principal_amount)}</p>
            <p><strong>Interest Amount:</strong> KES ${formatCurrency(payment.interest_amount)}</p>
            <p><strong>Total Amount:</strong> KES ${formatCurrency(payment.amount)}</p>
            <p><strong>Payment Source:</strong> ${payment.payment_source}</p>
            <p><strong>Transaction Reference:</strong> ${payment.transaction_reference || 'N/A'}</p>
            <p><strong>Recorded By:</strong> ${payment.recorded_by}</p>
            <p><strong>Notes:</strong> ${payment.notes || 'None'}</p>
        `;

        modal.style.display = 'block';
    }

    // ==========================================
    // FINANCIAL REPORTS VIEW FUNCTION
    // ==========================================
    async function showReportsView(loans) {
        if (!loans) {
            loans = await getAllLoans();
        }

        document.getElementById('sectionTitle').textContent = '📊 Financial Reports & Analytics';

        document.getElementById('loansSection')?.classList.add('hidden');
        document.getElementById('paymentsSection')?.classList.add('hidden');
        document.getElementById('fundingSection')?.classList.add('hidden');
        document.getElementById('dashboardSection')?.classList.add('hidden');
        document.getElementById('reportsSection')?.classList.remove('hidden');

        // Fetch payment data for all active/dispatched loans
        const activeLoans = loans.filter(l => l.status === 'active' || l.status === 'dispatched');
        const closedLoans = loans.filter(l => l.status === 'closed');

        // Calculate statistics by loan type
        const mortgageLoans = loans.filter(l => l.loan_type === 'mortgage');
        const carLoans = loans.filter(l => l.loan_type === 'car');

        // Overall statistics
        let totalLoanedAmount = 0;
        let totalPaidAmount = 0;
        let totalPrincipalPaid = 0;
        let totalInterestPaid = 0;
        let totalOutstandingBalance = 0;

        // By loan type
        const mortgageStats = {
            loaned: 0,
            paid: 0,
            principal: 0,
            interest: 0,
            balance: 0,
            count: 0
        };
        const carStats = {
            loaned: 0,
            paid: 0,
            principal: 0,
            interest: 0,
            balance: 0,
            count: 0
        };

        // By year statistics
        const yearlyStats = {};

        for (const loan of [...activeLoans, ...closedLoans]) {
            const loanAmount = parseFloat(loan.loan_amount) || 0;
            const balanceInfo = await recalculateLoanBalance(loan);

            totalLoanedAmount += loanAmount;
            totalPaidAmount += parseFloat(balanceInfo.totalPaid) || 0;
            totalPrincipalPaid += parseFloat(balanceInfo.principalPaid) || 0;
            totalInterestPaid += parseFloat(balanceInfo.interestPaid) || 0;

            if (loan.status !== 'closed') {
                totalOutstandingBalance += parseFloat(balanceInfo.totalBalanceDue) || 0;
            }

            // By loan type
            const stats = loan.loan_type === 'mortgage' ? mortgageStats : carStats;
            stats.loaned += loanAmount;
            stats.paid += parseFloat(balanceInfo.totalPaid) || 0;
            stats.principal += parseFloat(balanceInfo.principalPaid) || 0;
            stats.interest += parseFloat(balanceInfo.interestPaid) || 0;
            if (loan.status !== 'closed') {
                stats.balance += parseFloat(balanceInfo.totalBalanceDue) || 0;
            }
            stats.count++;

            // By year
            const dispatchDate = loan.dispatch_date || loan.application_date;
            if (dispatchDate) {
                const year = new Date(dispatchDate).getFullYear();
                if (!yearlyStats[year]) {
                    yearlyStats[year] = {
                        loaned: 0,
                        paid: 0,
                        principal: 0,
                        interest: 0,
                        balance: 0,
                        count: 0,
                        mortgage: {
                            count: 0,
                            amount: 0
                        },
                        car: {
                            count: 0,
                            amount: 0
                        }
                    };
                }
                yearlyStats[year].loaned += loanAmount;
                yearlyStats[year].paid += parseFloat(balanceInfo.totalPaid) || 0;
                yearlyStats[year].principal += parseFloat(balanceInfo.principalPaid) || 0;
                yearlyStats[year].interest += parseFloat(balanceInfo.interestPaid) || 0;
                if (loan.status !== 'closed') {
                    yearlyStats[year].balance += parseFloat(balanceInfo.totalBalanceDue) || 0;
                }
                yearlyStats[year].count++;

                if (loan.loan_type === 'mortgage') {
                    yearlyStats[year].mortgage.count++;
                    yearlyStats[year].mortgage.amount += loanAmount;
                } else {
                    yearlyStats[year].car.count++;
                    yearlyStats[year].car.amount += loanAmount;
                }
            }
        }

        let html = `
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px;">
        <!-- Overall Summary Card -->
        <div class="balance-highlight" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; position: relative;">
            <button onclick="exportOverallSummary()" 
                    style="position: absolute; top: 10px; right: 10px; background: rgba(255,255,255,0.3); border: none; color: white; padding: 5px 10px; border-radius: 5px; cursor: pointer; font-size: 0.8rem; transition: all 0.3s;"
                    onmouseover="this.style.background='rgba(255,255,255,0.5)'"
                    onmouseout="this.style.background='rgba(255,255,255,0.3)'">
                📊 Export
            </button>
            <h3 style="margin-top:0; color: white; padding-right: 80px;">💼 Overall Summary</h3>
            <p><strong>Total Loaned:</strong> KES ${formatCurrency(totalLoanedAmount)}</p>
            <p><strong>Total Loans:</strong> ${activeLoans.length + closedLoans.length}</p>
            <p><strong>Active Loans:</strong> ${activeLoans.length}</p>
            <p><strong>Closed Loans:</strong> ${closedLoans.length}</p>
        </div>

        <!-- Mortgage Summary Card -->
        <div class="balance-highlight" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; border: none; position: relative;">
            <button onclick="exportMortgageLoans()" 
                    style="position: absolute; top: 10px; right: 10px; background: rgba(255,255,255,0.3); border: none; color: white; padding: 5px 10px; border-radius: 5px; cursor: pointer; font-size: 0.8rem; transition: all 0.3s;"
                    onmouseover="this.style.background='rgba(255,255,255,0.5)'"
                    onmouseout="this.style.background='rgba(255,255,255,0.3)'">
                📊 Export
            </button>
            <h3 style="margin-top:0; color: white; padding-right: 80px;">🏠 Mortgage Loans</h3>
            <p><strong>Total Count:</strong> ${mortgageStats.count}</p>
            <p><strong>Total Loaned:</strong> KES ${formatCurrency(mortgageStats.loaned)}</p>
            <p><strong>Total Paid:</strong> KES ${formatCurrency(mortgageStats.paid)}</p>
            <p><strong>Outstanding:</strong> KES ${formatCurrency(mortgageStats.balance)}</p>
        </div>

        <!-- Car Loan Summary Card -->
        <div class="balance-highlight" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; border: none; position: relative;">
            <button onclick="exportCarLoans()" 
                    style="position: absolute; top: 10px; right: 10px; background: rgba(255,255,255,0.3); border: none; color: white; padding: 5px 10px; border-radius: 5px; cursor: pointer; font-size: 0.8rem; transition: all 0.3s;"
                    onmouseover="this.style.background='rgba(255,255,255,0.5)'"
                    onmouseout="this.style.background='rgba(255,255,255,0.3)'">
                📊 Export
            </button>
            <h3 style="margin-top:0; color: white; padding-right: 80px;">🚗 Car Loans</h3>
            <p><strong>Total Count:</strong> ${carStats.count}</p>
            <p><strong>Total Loaned:</strong> KES ${formatCurrency(carStats.loaned)}</p>
            <p><strong>Total Paid:</strong> KES ${formatCurrency(carStats.paid)}</p>
            <p><strong>Outstanding:</strong> KES ${formatCurrency(carStats.balance)}</p>
        </div>

        <!-- Payment Summary Card -->
        <div class="balance-highlight" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); color: white; border: none; position: relative;">
            <button onclick="exportPaymentSummary()" 
                    style="position: absolute; top: 10px; right: 10px; background: rgba(255,255,255,0.3); border: none; color: white; padding: 5px 10px; border-radius: 5px; cursor: pointer; font-size: 0.8rem; transition: all 0.3s;"
                    onmouseover="this.style.background='rgba(255,255,255,0.5)'"
                    onmouseout="this.style.background='rgba(255,255,255,0.3)'">
                📊 Export
            </button>
            <h3 style="margin-top:0; color: white; padding-right: 80px;">💰 Payment Summary</h3>
            <p><strong>Total Paid:</strong> KES ${formatCurrency(totalPaidAmount)}</p>
            <p><strong>Principal Paid:</strong> KES ${formatCurrency(totalPrincipalPaid)}</p>
            <p><strong>Interest Paid:</strong> KES ${formatCurrency(totalInterestPaid)}</p>
            <p><strong>Outstanding:</strong> KES ${formatCurrency(totalOutstandingBalance)}</p>
        </div>
    </div>

        <!-- Yearly Breakdown -->
        <div class="balance-highlight">
            <h3 style="margin-top:0; color:#2e7d32;">📅 Yearly Breakdown</h3>
            <div class="table-container">
                <table style="width:100%; border-collapse:collapse; margin-top:15px;">
                    <thead>
                        <tr style="background:#3a6b9a; color:white;">
                            <th style="padding:12px; text-align:left;">Year</th>
                            <th style="padding:12px; text-align:center;">Total Loans</th>
                            <th style="padding:12px; text-align:center;">Mortgage</th>
                            <th style="padding:12px; text-align:center;">Car</th>
                            <th style="padding:12px; text-align:right;">Amount Loaned</th>
                            <th style="padding:12px; text-align:right;">Principal Paid</th>
                            <th style="padding:12px; text-align:right;">Interest Paid</th>
                            <th style="padding:12px; text-align:right;">Outstanding</th>
                        </tr>
                    </thead>
                    <tbody>
    `;

        const sortedYears = Object.keys(yearlyStats).sort((a, b) => b - a);
        sortedYears.forEach(year => {
            const stats = yearlyStats[year];
            html += `
            <tr style="border-bottom: 1px solid #e0e0e0;">
                <td style="padding:12px; font-weight:bold;">${year}</td>
                <td style="padding:12px; text-align:center;">${stats.count}</td>
                <td style="padding:12px; text-align:center;">${stats.mortgage.count} (KES ${formatCurrency(stats.mortgage.amount)})</td>
                <td style="padding:12px; text-align:center;">${stats.car.count} (KES ${formatCurrency(stats.car.amount)})</td>
                <td style="padding:12px; text-align:right; font-weight:bold;">KES ${formatCurrency(stats.loaned)}</td>
                <td style="padding:12px; text-align:right; color:#2e7d32;">KES ${formatCurrency(stats.principal)}</td>
                <td style="padding:12px; text-align:right; color:#d84315;">KES ${formatCurrency(stats.interest)}</td>
                <td style="padding:12px; text-align:right; color:#c62828;">KES ${formatCurrency(stats.balance)}</td>
            </tr>
        `;
        });

        html += `
                    </tbody>
                    <tfoot>
                        <tr style="background:#f5f5f5; font-weight:bold; border-top: 2px solid #3a6b9a;">
                            <td style="padding:12px;">TOTAL</td>
                            <td style="padding:12px; text-align:center;">${activeLoans.length + closedLoans.length}</td>
                            <td style="padding:12px; text-align:center;">${mortgageStats.count}</td>
                            <td style="padding:12px; text-align:center;">${carStats.count}</td>
                            <td style="padding:12px; text-align:right;">KES ${formatCurrency(totalLoanedAmount)}</td>
                            <td style="padding:12px; text-align:right; color:#2e7d32;">KES ${formatCurrency(totalPrincipalPaid)}</td>
                            <td style="padding:12px; text-align:right; color:#d84315;">KES ${formatCurrency(totalInterestPaid)}</td>
                            <td style="padding:12px; text-align:right; color:#c62828;">KES ${formatCurrency(totalOutstandingBalance)}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Detailed Active Loans -->
        <div class="balance-highlight" style="margin-top:20px;">
            <h3 style="margin-top:0; color:#2e7d32;">📋 Active Loans Details</h3>
            <div class="table-container">
                <table style="width:100%; border-collapse:collapse; margin-top:15px;">
                    <thead>
                        <tr style="background:#3a6b9a; color:white;">
                            <th style="padding:12px;">Loanee</th>
                            <th style="padding:12px;">PF Number</th>
                            <th style="padding:12px;">Type</th>
                            <th style="padding:12px;">Start Date</th>
                            <th style="padding:12px; text-align:right;">Original Amount</th>
                            <th style="padding:12px; text-align:right;">Principal Paid</th>
                            <th style="padding:12px; text-align:right;">Interest Paid</th>
                            <th style="padding:12px; text-align:right;">Balance Due</th>
                        </tr>
                    </thead>
                    <tbody>
    `;

        for (const loan of activeLoans) {
            const balanceInfo = await recalculateLoanBalance(loan);
            html += `
            <tr style="border-bottom: 1px solid #e0e0e0;">
                <td style="padding:12px;">${loan.full_name}</td>
                <td style="padding:12px;">${loan.pf_number}</td>
                <td style="padding:12px;">${loan.loan_type === 'mortgage' ? '🏠 Mortgage' : '🚗 Car'}</td>
                <td style="padding:12px;">${formatDate(loan.dispatch_date || loan.application_date)}</td>
                <td style="padding:12px; text-align:right;">KES ${formatCurrency(loan.loan_amount)}</td>
                <td style="padding:12px; text-align:right; color:#2e7d32;">KES ${formatCurrency(balanceInfo.principalPaid)}</td>
                <td style="padding:12px; text-align:right; color:#d84315;">KES ${formatCurrency(balanceInfo.interestPaid)}</td>
                <td style="padding:12px; text-align:right; color:#c62828; font-weight:bold;">KES ${formatCurrency(balanceInfo.totalBalanceDue)}</td>
            </tr>
        `;
        }

        html += `
                    </tbody>
                </table>
            </div>
        </div>
    `;

        let reportsDiv = document.getElementById('reportsContent');
        if (!reportsDiv) {
            reportsDiv = document.createElement('div');
            reportsDiv.id = 'reportsContent';
            document.getElementById('reportsSection').appendChild(reportsDiv);
        }
        reportsDiv.innerHTML = html;
        reportsDiv.style.display = 'block';
    }

    // ==========================================
    // EXPORT FUNCTIONS
    // ==========================================
    // Helper function to convert data to CSV format (Excel compatible)
    function convertToCSV(data, headers) {
        let csv = headers.join(',') + '\n';
        data.forEach(row => {
            const values = row.map((value, index) => {
                const stringValue = String(value);

                // For Application ID and Account Number columns, add tab character to preserve formatting
                if (index === 0 || (headers[index] && (headers[index].includes(
                            'Application ID') ||
                        headers[index].includes('Account Number')))) {
                    // Use tab character prefix to force Excel to treat as text
                    return `"${stringValue}\t"`;
                }

                // Escape quotes and wrap in quotes if contains comma, quotes, or newlines
                if (stringValue.includes(',') || stringValue.includes('"') || stringValue
                    .includes(
                        '\n')) {
                    return `"${stringValue.replace(/"/g, '""')}"`;
                }

                return stringValue;
            });
            csv += values.join(',') + '\n';
        });
        return csv;
    }

    // Helper function to download CSV file
    function downloadCSV(filename, csvContent) {
        const BOM = '\uFEFF'; // UTF-8 BOM for Excel compatibility
        const blob = new Blob([BOM + csvContent], {
            type: 'text/csv;charset=utf-8;'
        });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = filename;
        link.click();

        showNotification(
            `${filename} exported successfully! Note: In Excel, adjust column widths to view all data properly.`,
            'success');
    }

    // 1. EXPORT OVERALL SUMMARY
    async function exportOverallSummary() {
        try {
            const loans = await getAllLoans();
            const activeLoans = loans.filter(l => l.status === 'active' || l.status === 'dispatched');
            const closedLoans = loans.filter(l => l.status === 'closed');

            const headers = [
                'Application ID',
                'Loanee Name',
                'PF Number',
                'ID Number',
                'Loan Type',
                'Original Amount (KES)',
                'Total Paid (KES)',
                'Principal Paid (KES)',
                'Interest Paid (KES)',
                'Outstanding Balance (KES)',
                'Status',
                'Application Date',
                'Dispatch Date',
                'Bank Name',
                'Account Number'
            ];

            const data = [];

            for (const loan of [...activeLoans, ...closedLoans]) {
                const balanceInfo = await recalculateLoanBalance(loan);
                data.push([
                    loan.application_id || 'N/A',
                    loan.full_name || 'N/A',
                    loan.pf_number || 'N/A',
                    loan.id_number || 'N/A',
                    loan.loan_type ? loan.loan_type.charAt(0).toUpperCase() + loan.loan_type
                    .slice(1) :
                    'N/A',
                    formatCurrency(loan.loan_amount || 0),
                    formatCurrency(balanceInfo.totalPaid || 0),
                    formatCurrency(balanceInfo.principalPaid || 0),
                    formatCurrency(balanceInfo.interestPaid || 0),
                    loan.status !== 'closed' ? formatCurrency(balanceInfo.totalBalanceDue || 0) :
                    '0',
                    loan.status ? loan.status.toUpperCase() : 'UNKNOWN',
                    formatDate(loan.application_date) || 'N/A',
                    formatDate(loan.dispatch_date) || 'N/A',
                    loan.bank_name || 'N/A',
                    loan.bank_account_number || 'N/A'
                ]);
            }

            const csv = convertToCSV(data, headers);
            const filename = `Overall_Summary_${new Date().toISOString().split('T')[0]}.csv`;
            downloadCSV(filename, csv);
        } catch (error) {
            showNotification('Error exporting overall summary: ' + error.message, 'error');
        }
    }

    // 2. EXPORT MORTGAGE LOANS
    async function exportMortgageLoans() {
        try {
            const loans = await getAllLoans();
            const mortgageLoans = loans.filter(l => l.loan_type === 'mortgage');

            const headers = [
                'Application ID',
                'Loanee Name',
                'PF Number',
                'ID Number',
                'Original Amount (KES)',
                'Property Location',
                'Property Value (KES)',
                'Total Paid (KES)',
                'Principal Paid (KES)',
                'Interest Paid (KES)',
                'Outstanding Balance (KES)',
                'Status',
                'Payment Duration (Years)',
                'Application Date',
                'Dispatch Date',
                'Bank Name',
                'Account Number'
            ];

            const data = [];

            for (const loan of mortgageLoans) {
                const balanceInfo = await recalculateLoanBalance(loan);
                data.push([
                    loan.application_id || 'N/A',
                    loan.full_name || 'N/A',
                    loan.pf_number || 'N/A',
                    loan.id_number || 'N/A',
                    formatCurrency(loan.loan_amount || 0),
                    loan.property_location || 'N/A',
                    loan.property_value ? formatCurrency(loan.property_value) : 'N/A',
                    formatCurrency(balanceInfo.totalPaid || 0),
                    formatCurrency(balanceInfo.principalPaid || 0),
                    formatCurrency(balanceInfo.interestPaid || 0),
                    loan.status !== 'closed' ? formatCurrency(balanceInfo.totalBalanceDue || 0) :
                    '0',
                    loan.status ? loan.status.toUpperCase() : 'UNKNOWN',
                    loan.payment_duration || 'N/A',
                    formatDate(loan.application_date) || 'N/A',
                    formatDate(loan.dispatch_date) || 'N/A',
                    loan.bank_name || 'N/A',
                    loan.bank_account_number || 'N/A'
                ]);
            }

            const csv = convertToCSV(data, headers);
            const filename = `Mortgage_Loans_${new Date().toISOString().split('T')[0]}.csv`;
            downloadCSV(filename, csv);
        } catch (error) {
            showNotification('Error exporting mortgage loans: ' + error.message, 'error');
        }
    }

    // 3. EXPORT CAR LOANS
    async function exportCarLoans() {
        try {
            const loans = await getAllLoans();
            const carLoans = loans.filter(l => l.loan_type === 'car');

            const headers = [
                'Application ID',
                'Loanee Name',
                'PF Number',
                'ID Number',
                'Original Amount (KES)',
                'Car Type',
                'Car Value (KES)',
                'Total Paid (KES)',
                'Principal Paid (KES)',
                'Interest Paid (KES)',
                'Outstanding Balance (KES)',
                'Status',
                'Payment Duration (Years)',
                'Application Date',
                'Dispatch Date',
                'Bank Name',
                'Account Number'
            ];

            const data = [];

            for (const loan of carLoans) {
                const balanceInfo = await recalculateLoanBalance(loan);

                // Determine car info - prioritize whichever field has data
                let carInfo = 'Not Specified';
                if (loan.car_model && loan.car_model !== 'N/A') {
                    carInfo = loan.car_model;
                } else if (loan.car_type && loan.car_type !== 'N/A') {
                    carInfo = loan.car_type;
                }

                // Dispatch date handling
                let dispatchDate = 'Pending Dispatch';
                if (loan.dispatch_date && loan.dispatch_date !== null) {
                    dispatchDate = formatDate(loan.dispatch_date);
                }

                data.push([
                    formatApplicationId(loan.application_id),
                    loan.full_name || 'N/A',
                    loan.pf_number || 'N/A',
                    loan.id_number || 'N/A',
                    formatCurrency(loan.loan_amount || 0),
                    carInfo,
                    loan.car_value ? formatCurrency(loan.car_value) : 'Not Specified',
                    formatCurrency(balanceInfo.totalPaid),
                    formatCurrency(balanceInfo.principalPaid),
                    formatCurrency(balanceInfo.interestPaid),
                    loan.status !== 'closed' ? formatCurrency(balanceInfo.totalBalanceDue) :
                    '0',
                    loan.status ? loan.status.toUpperCase() : 'UNKNOWN',
                    loan.payment_duration || 'N/A',
                    formatDate(loan.application_date) || 'N/A',
                    dispatchDate,
                    loan.bank_name || 'Not Specified',
                    formatAccountNumber(loan.bank_account_number)
                ]);
            }

            const csv = convertToCSV(data, headers);
            const filename = `Car_Loans_${new Date().toISOString().split('T')[0]}.csv`;
            downloadCSV(filename, csv);
        } catch (error) {
            showNotification('Error exporting car loans: ' + error.message, 'error');
        }
    }

    // 4. EXPORT PAYMENT SUMMARY
    async function exportPaymentSummary() {
        try {
            const loans = await getAllLoans();
            const activeLoans = loans.filter(l => l.status === 'active' || l.status === 'dispatched');
            const closedLoans = loans.filter(l => l.status === 'closed');

            const headers = [
                'Application ID',
                'Loanee Name',
                'PF Number',
                'Loan Type',
                'Original Amount (KES)',
                'Total Amount Paid (KES)',
                'Principal Paid (KES)',
                'Interest Paid (KES)',
                'Outstanding Balance (KES)',
                'Number of Payments',
                'Last Payment Date',
                'Months Since Last Payment',
                'Payment Status'
            ];

            const data = [];

            for (const loan of [...activeLoans, ...closedLoans]) {
                const balanceInfo = await recalculateLoanBalance(loan);
                const paymentStatus = loan.status === 'closed' ? 'FULLY PAID' :
                    balanceInfo.totalPaid > 0 ? 'IN PROGRESS' : 'NO PAYMENTS';

                data.push([
                    loan.application_id || 'N/A',
                    loan.full_name || 'N/A',
                    loan.pf_number || 'N/A',
                    loan.loan_type ? loan.loan_type.charAt(0).toUpperCase() + loan.loan_type.slice(1) :
                    'N/A',
                    formatCurrency(loan.loan_amount || 0),
                    formatCurrency(balanceInfo.totalPaid || 0),
                    formatCurrency(balanceInfo.principalPaid || 0),
                    formatCurrency(balanceInfo.interestPaid || 0),
                    loan.status !== 'closed' ? formatCurrency(balanceInfo.totalBalanceDue || 0) :
                    '0',
                    loan.payments ? loan.payments.length : 0,
                    balanceInfo.lastPaymentDate || 'N/A',
                    balanceInfo.monthsSinceLastPayment || 0,
                    paymentStatus
                ]);
            }

            const csv = convertToCSV(data, headers);
            const filename = `Payment_Summary_${new Date().toISOString().split('T')[0]}.csv`;
            downloadCSV(filename, csv);
        } catch (error) {
            showNotification('Error exporting payment summary: ' + error.message, 'error');
        }
    }

    // 5. EXPORT COMPLETE FINANCIAL REPORT
    function exportFinancialReport() {
        // Create CSV content
        let csv = 'Financial Report - Generated on: ' + new Date().toLocaleString() + '\n\n';

        // Extract data from the reports view
        const reportsDiv = document.getElementById('reportsContent');
        if (!reportsDiv) {
            showNotification('No report data available to export', 'error');
            return;
        }

        // Get all tables
        const tables = reportsDiv.querySelectorAll('table');

        tables.forEach((table, index) => {
            if (index === 0) {
                csv += '\nYearly Breakdown\n';
            } else if (index === 1) {
                csv += '\n\nActive Loans Details\n';
            }

            const rows = table.querySelectorAll('tr');
            rows.forEach(row => {
                const cols = row.querySelectorAll('th, td');
                const rowData = [];
                cols.forEach(col => {
                    let text = col.innerText.replace(/"/g, '""');
                    // Remove extra whitespace and newlines
                    text = text.replace(/\s+/g, ' ').trim();
                    rowData.push(`"${text}"`);
                });
                csv += rowData.join(',') + '\n';
            });
        });

        // Download CSV
        const blob = new Blob([csv], {
            type: 'text/csv'
        });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = `financial_report_${new Date().toISOString().split('T')[0]}.csv`;
        link.click();

        showNotification('Financial report exported successfully!', 'success');
    }

    // PDF EXPORT FUNCTIONS
    function exportLoanDetails() {
        const {
            jsPDF
        } = window.jspdf;
        const doc = new jsPDF('p', 'mm', 'a4');

        // Get the current loan data from the modal
        const loanDetailsContent = document.getElementById('loanDetailsContent');
        const loaneeName = loanDetailsContent.querySelector('p:nth-child(2)')?.textContent.replace('Full Name: ', '') ||
            'Unknown';

        // Set up PDF dimensions
        const pageWidth = doc.internal.pageSize.getWidth();
        const pageHeight = doc.internal.pageSize.getHeight();
        let yPosition = 20;

        // Helper function to add text with word wrap
        function addText(text, fontSize = 12, isBold = false) {
            doc.setFontSize(fontSize);
            doc.setFont(isBold ? 'helvetica' : 'helvetica', isBold ? 'bold' : 'normal');

            const lines = doc.splitTextToSize(text, pageWidth - 40);
            lines.forEach(line => {
                if (yPosition > pageHeight - 20) {
                    doc.addPage();
                    yPosition = 20;
                }
                doc.text(line, 20, yPosition);
                yPosition += 7;
            });
            return yPosition;
        }

        // Header
        doc.setFontSize(20);
        doc.setFont('helvetica', 'bold');
        doc.text('Loan Details Report', pageWidth / 2, 15, {
            align: 'center'
        });

        yPosition = 30;

        // Extract loan information
        const loanInfo = extractLoanInfo(loanDetailsContent);

        // Add loanee information section
        addText('Loanee Information', 14, true);
        yPosition += 3;

        // Create a simple table for loanee information
        const loaneeData = [
            ['Full Name:', loanInfo.fullName],
            ['ID Number:', loanInfo.idNumber],
            ['PF Number:', loanInfo.pfNumber],
            ['Email:', loanInfo.email],
            ['Loan Type:', loanInfo.loanType],
            ['Application Date:', loanInfo.applicationDate],
            ['Status:', loanInfo.status],
            ['Payment Duration:', loanInfo.paymentDuration],
            ['Basic Salary:', loanInfo.basicSalary]
        ];

        yPosition = createSimpleTable(doc, loaneeData, yPosition, pageWidth);

        // Add loan financial status section
        yPosition += 10;
        addText('Current Loan Financial Status', 14, true);
        yPosition += 3;

        // Create a table for financial status
        const financialData = [
            ['Original Loan Amount:', loanInfo.originalAmount],
            ['Total Paid:', loanInfo.totalPaid],
            ['Principal Paid:', loanInfo.principalPaid],
            ['Interest Paid:', loanInfo.interestPaid],
            ['Current Principal Balance:', loanInfo.currentPrincipal],
            ['Accrued Interest:', loanInfo.accruedInterest],
            ['Total Balance Due:', loanInfo.totalBalanceDue]
        ];

        yPosition = createSimpleTable(doc, financialData, yPosition, pageWidth);

        // Add payment history if available
        if (loanInfo.payments && loanInfo.payments.length > 0) {
            yPosition += 10;
            addText('Payment History', 14, true);
            yPosition += 5;

            // Create a table for payment history
            yPosition = createPaymentTable(doc, loanInfo.payments, yPosition, pageWidth);
        }

        // Add footer
        const pageCount = doc.internal.getNumberOfPages();
        for (let i = 1; i <= pageCount; i++) {
            doc.setPage(i);
            doc.setFontSize(10);
            doc.setFont('helvetica', 'normal');
            doc.text(`Page ${i} of ${pageCount}`, pageWidth / 2, pageHeight - 10, {
                align: 'center'
            });
            doc.text(`Generated on ${new Date().toLocaleString()}`, pageWidth / 2, pageHeight - 5, {
                align: 'center'
            });
        }

        // Save the PDF
        doc.save(`Loan_Details_${loaneeName.replace(/\s+/g, '_')}_${new Date().toISOString().split('T')[0]}.pdf`);

        showNotification('Loan details exported successfully!', 'success');
    }

    // Helper function to extract loan information from the modal
    function extractLoanInfo(contentElement) {
        const info = {};

        // Extract loanee information
        info.fullName = contentElement.querySelector('p:nth-child(2)')?.textContent.replace('Full Name: ', '') || 'N/A';
        info.idNumber = contentElement.querySelector('p:nth-child(3)')?.textContent.replace('ID Number: ', '') || 'N/A';
        info.pfNumber = contentElement.querySelector('p:nth-child(4)')?.textContent.replace('PF Number: ', '') || 'N/A';
        info.email = contentElement.querySelector('p:nth-child(5)')?.textContent.replace('Email: ', '') || 'N/A';

        // Extract loan details
        const loanDetailsSection = Array.from(contentElement.querySelectorAll('h3')).find(h => h.textContent.includes(
            'Loan Details'));
        if (loanDetailsSection) {
            let nextElement = loanDetailsSection.nextElementSibling;
            while (nextElement && nextElement.tagName !== 'H3') {
                if (nextElement.tagName === 'P') {
                    const text = nextElement.textContent;
                    if (text.includes('Loan Type:')) info.loanType = text.replace('Loan Type: ', '');
                    else if (text.includes('Application Date:')) info.applicationDate = text.replace(
                        'Application Date: ', '');
                    else if (text.includes('Status:')) info.status = text.replace('Status: ', '');
                    else if (text.includes('Payment Duration:')) info.paymentDuration = text.replace(
                        'Payment Duration: ', '');
                    else if (text.includes('Basic Salary:')) info.basicSalary = text.replace('Basic Salary: ', '');
                }
                nextElement = nextElement.nextElementSibling;
            }
        }

        // Extract financial status
        const financialSection = Array.from(contentElement.querySelectorAll('h3')).find(h => h.textContent.includes(
            'Current Loan Financial Status'));
        if (financialSection) {
            const table = financialSection.nextElementSibling;
            if (table && table.tagName === 'TABLE') {
                const rows = table.querySelectorAll('tr');
                rows.forEach(row => {
                    const cells = row.querySelectorAll('td');
                    if (cells.length === 2) {
                        const label = cells[0].textContent.replace(':', '');
                        const value = cells[1].textContent;

                        if (label.includes('Original Loan Amount')) info.originalAmount = value;
                        else if (label.includes('Total Paid')) info.totalPaid = value;
                        else if (label.includes('Principal Paid')) info.principalPaid = value;
                        else if (label.includes('Interest Paid')) info.interestPaid = value;
                        else if (label.includes('Current Principal Balance')) info.currentPrincipal = value;
                        else if (label.includes('Accrued Interest')) info.accruedInterest = value;
                        else if (label.includes('Total Balance Due')) info.totalBalanceDue = value;
                    }
                });
            }
        }

        // Extract payment history
        const paymentSection = Array.from(contentElement.querySelectorAll('h3')).find(h => h.textContent.includes(
            'Payment History'));
        if (paymentSection) {
            const tableContainer = paymentSection.nextElementSibling;
            if (tableContainer && tableContainer.querySelector('table')) {
                const table = tableContainer.querySelector('table');
                const rows = table.querySelectorAll('tbody tr');

                info.payments = [];
                rows.forEach(row => {
                    const cells = row.querySelectorAll('td');
                    if (cells.length >= 8) {
                        info.payments.push({
                            number: cells[0].textContent,
                            date: cells[1].textContent,
                            amount: cells[2].textContent,
                            interest: cells[3].textContent,
                            principal: cells[4].textContent,
                            source: cells[5].textContent,
                            refId: cells[6].textContent,
                            recordedBy: cells[7].textContent
                        });
                    }
                });
            }
        }

        return info;
    }

    // Helper function to create a simple table
    function createSimpleTable(doc, data, startY, pageWidth) {
        let yPosition = startY;
        data.forEach(row => {
            // Check if we need a new page
            if (yPosition > pageHeight - 20) {
                doc.addPage();
                yPosition = 20;
            }

            // Draw row data
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(11);
            doc.text(row[0], 20, yPosition);

            doc.setFont('helvetica', 'normal');
            const valueText = doc.splitTextToSize(row[1], pageWidth * 0.6);
            valueText.forEach(line => {
                doc.text(line, pageWidth * 0.4, yPosition);
                yPosition += 7;
            });

            yPosition += 8;
        });
        return yPosition;
    }

    // Helper function to create a payment history table
    function createPaymentTable(doc, payments, startY, pageWidth) {
        let yPosition = startY;

        // Table headers
        const headers = ['#', 'Date', 'Amount', 'Interest', 'Principal', 'Source', 'Ref ID', 'Recorded By'];
        const columnWidths = [0.08, 0.12, 0.12, 0.12, 0.12, 0.12, 0.12, 0.2];

        // Draw headers
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(10);

        let xPos = 15;
        headers.forEach((header, index) => {
            doc.text(header, xPos, yPosition);
            xPos += pageWidth * columnWidths[index];
        });

        yPosition += 10;

        // Draw a line under headers
        doc.setLineWidth(0.5);
        doc.line(15, yPosition, pageWidth - 15, yPosition);
        yPosition += 3;

        // Draw table rows
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(9);

        payments.forEach(payment => {
            // Check if we need a new page
            if (yPosition > pageHeight - 20) {
                doc.addPage();
                yPosition = 20;

                // Repeat headers on new page
                xPos = 15;
                doc.setFont('helvetica', 'bold');
                headers.forEach((header, index) => {
                    doc.text(header, xPos, yPosition);
                    xPos += pageWidth * columnWidths[index];
                });

                yPosition += 10;
                doc.line(15, yPosition, pageWidth - 15, yPosition);
                yPosition += 3;

                doc.setFont('helvetica', 'normal');
            }

            // Draw row data
            xPos = 15;
            const rowData = [
                payment.number,
                payment.date,
                payment.amount,
                payment.interest,
                payment.principal,
                payment.source,
                payment.refId,
                payment.recordedBy
            ];

            rowData.forEach((data, index) => {
                // Clean up the data
                let cleanData = data.replace(/KES/g, '').replace(/,/g, '');

                // Truncate if too long
                if (cleanData.length > 15) {
                    cleanData = cleanData.substring(0, 12) + '...';
                }

                doc.text(cleanData, xPos, yPosition);
                xPos += pageWidth * columnWidths[index];
            });

            yPosition += 6;
        });

        // Draw a line at the bottom of the table
        doc.setLineWidth(0.5);
        doc.line(15, yPosition, pageWidth - 15, yPosition);

        return yPosition + 5;
    }

    function exportAmortization() {
        const {
            jsPDF
        } = window.jspdf;
        const doc = new jsPDF('l', 'mm', 'a4'); // Landscape orientation for better table fit

        // Get amortization data
        const amortInfo = document.getElementById('amortInfo');
        const amortTableBody = document.getElementById('amortTableBody');
        const loaneeName = amortInfo.querySelector('strong')?.textContent.split(':')[1]?.trim() || 'Unknown';

        // Set up PDF dimensions
        const pageWidth = doc.internal.pageSize.getWidth();
        const pageHeight = doc.internal.pageSize.getHeight();
        let yPosition = 20;

        // Header
        doc.setFontSize(16);
        doc.setFont('helvetica', 'bold');
        doc.text('Payment Schedule & Amortization Report', pageWidth / 2, 15, {
            align: 'center'
        });

        yPosition = 30;

        // Add summary information
        const summaryText = amortInfo.innerText;
        const summaryLines = doc.splitTextToSize(summaryText, pageWidth - 40);
        summaryLines.forEach(line => {
            if (yPosition > pageHeight - 20) {
                doc.addPage();
                yPosition = 20;
            }
            doc.text(line, 20, yPosition);
            yPosition += 7;
        });

        yPosition += 10;

        // Create a proper table for the amortization schedule
        const tableHeaders = ['#', 'Due Date', 'Expected Payment', 'Actual Payment', 'Expected Interest',
            'Actual Interest', 'Principal', 'Balance', 'Status'
        ];
        const columnWidths = [15, 30, 35, 35, 35, 30, 35, 25];

        // Draw table headers
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(10);

        let xPos = 15;
        tableHeaders.forEach((header, index) => {
            doc.text(header, xPos, yPosition);
            xPos += columnWidths[index];
        });

        yPosition += 8;

        // Draw a line under headers
        doc.setLineWidth(0.5);
        doc.line(15, yPosition, pageWidth - 15, yPosition);
        yPosition += 3;

        // Draw table rows
        const rows = amortTableBody.querySelectorAll('tr');
        rows.forEach((row, rowIndex) => {
            // Check if we need a new page
            if (yPosition > pageHeight - 20) {
                doc.addPage();
                yPosition = 20;

                // Repeat headers on new page
                xPos = 15;
                doc.setFont('helvetica', 'bold');
                tableHeaders.forEach((header, index) => {
                    doc.text(header, xPos, yPosition);
                    xPos += columnWidths[index];
                });

                yPosition += 8;
                doc.line(15, yPosition, pageWidth - 15, yPosition);
                yPosition += 3;
            }

            const cells = row.querySelectorAll('td');
            xPos = 15;
            doc.setFont('helvetica', 'normal');
            doc.setFontSize(8);

            cells.forEach((cell, cellIndex) => {
                if (cellIndex < columnWidths.length) {
                    let text = cell.textContent.trim();
                    // Clean up currency formatting and status text
                    text = text.replace(/KES/g, '').replace(/\s+/g, ' ');

                    // Truncate if too long
                    if (text.length > 20) {
                        text = text.substring(0, 17) + '...';
                    }

                    doc.text(text, xPos, yPosition);
                    xPos += columnWidths[cellIndex];
                }
            });

            yPosition += 7;
        });

        // Draw a line at the bottom of the table
        doc.setLineWidth(0.5);
        doc.line(15, yPosition, pageWidth - 15, yPosition);

        // Footer
        const pageCount = doc.internal.getNumberOfPages();
        for (let i = 1; i <= pageCount; i++) {
            doc.setPage(i);
            doc.setFontSize(10);
            doc.setFont('helvetica', 'normal');
            doc.text(`Page ${i} of ${pageCount}`, pageWidth / 2, pageHeight - 10, {
                align: 'center'
            });
            doc.text(`Generated on ${new Date().toLocaleString()}`, pageWidth / 2, pageHeight - 5, {
                align: 'center'
            });
        }

        // Save the PDF
        doc.save(
            `Amortization_Schedule_${loaneeName.replace(/\s+/g, '_')}_${new Date().toISOString().split('T')[0]}.pdf`
        );

        showNotification('Amortization schedule exported successfully!', 'success');
    }

    // ==========================================
    // AUTHENTICATION
    // ==========================================
    function logoutAccountant() {
        // Clear the session storage
        sessionStorage.removeItem('loginTime');
        sessionStorage.removeItem('lastActivityTime');

        // Session data will be cleared on the server side
        // No need to clear localStorage

        // Show notification
        showNotification('Logging out...', 'success', 2000);

        // Call logout (but don't wait for response in case of network issues)
        fetch('<?php echo $_SERVER["PHP_SELF"]; ?>?action=logout', {
            method: 'GET'
        }).catch(error => {
            //console.error('Logout error:', error);
        }).finally(() => {
            // Clear app state
            APP_STATE.currentUser = null;
            APP_STATE.csrfToken = null;

            // Redirect after a brief delay
            setTimeout(() => {
                window.location.href = 'index.php?logout=success';
            }, 500);
        });
    }

    // ==========================================
    // PROFILE BADGE FUNCTIONS
    // ==========================================
    function initializeProfileBadge() {
        // Profile badge click handler
        // Profile badge click handler
        DOM_ELEMENTS.profileBadge.addEventListener('click', (e) => {
            e.stopPropagation();
            DOM_ELEMENTS.profileDropdown.classList.toggle('active');
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', () => {
            DOM_ELEMENTS.profileDropdown.classList.remove('active');
        });

        // Logout button in profile dropdown
        document.getElementById('logoutBtn').addEventListener('click', () => {
            if (confirm('Are you sure you want to logout?')) {
                logoutAccountant();
            }
        });

        // Logout button in sidebar
        document.getElementById('logoutBtnSidebar').addEventListener('click', () => {
            if (confirm('Are you sure you want to logout?')) {
                logoutAccountant();
            }
        });
    }



    // Add event listener for the Apply for Loan button
    document.addEventListener('DOMContentLoaded', function() {
        const applyLoanBtn = document.querySelector('button[onclick="applyForLoan()"]');
        if (applyLoanBtn) {
            // Replace the inline onclick with our function
            applyLoanBtn.removeAttribute('onclick');
            applyLoanBtn.addEventListener('click', applyForLoan);
        }
    });

    // ==========================================
    // SIDEBAR TOGGLE FUNCTION
    // ==========================================
    function initializeSidebar() {
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const main = document.querySelector('.main');

        // Add state management
        let sidebarOpen = true;
        let sidebarCollapsed = false;

        // Set initial state based on screen size
        function updateSidebar() {
            if (window.innerWidth <= 768) {
                sidebar.classList.remove('open', 'collapsed');
                main.style.paddingLeft = '15px';
                sidebarOpen = false;
                sidebarCollapsed = false;
            } else {
                sidebar.classList.remove('collapsed');
                sidebar.classList.add('open');
                main.style.paddingLeft = '260px';
                sidebarOpen = true;
                sidebarCollapsed = false;
            }
        }

        // Toggle on hamburger click
        menuToggle.addEventListener('click', () => {
            if (window.innerWidth <= 768) {
                // Mobile behavior - toggle sidebar visibility
                sidebarOpen = !sidebarOpen;
                if (sidebarOpen) {
                    sidebar.classList.add('open');
                } else {
                    sidebar.classList.remove('open');
                }
            } else {
                // Desktop behavior - toggle collapse
                if (!sidebarCollapsed) {
                    sidebar.classList.add('collapsed');
                    main.style.paddingLeft = '90px';
                    sidebarCollapsed = true;
                } else {
                    sidebar.classList.remove('collapsed');
                    main.style.paddingLeft = '260px';
                    sidebarCollapsed = false;
                }
            }
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', (event) => {
            if (window.innerWidth <= 768 &&
                sidebarOpen &&
                !sidebar.contains(event.target) &&
                !menuToggle.contains(event.target)) {
                sidebar.classList.remove('open');
                sidebarOpen = false;
            }
        });

        // Update on resize
        window.addEventListener('resize', updateSidebar);

        // Initial check
        updateSidebar();
    }

    // ==========================================
    // EVENT LISTENERS
    // ==========================================
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize user data from localStorage
        APP_STATE.currentUser = {
            pfNumber: '<?php echo $_SESSION['pf_number']; ?>',
            fullName: '<?php echo addslashes($_SESSION['full_name']); ?>',
            role: '<?php echo $_SESSION['user_role']; ?>',
            id: '<?php echo $_SESSION['user_id']; ?>'
        };

        // Initialize sidebar
        initializeSidebar();

        // Initialize profile badge
        initializeProfileBadge();

        // === DEFAULT VIEW ON PAGE LOAD ===
        document.getElementById('dashboardSection')?.classList.add('hidden');
        document.getElementById('loansSection')?.classList.remove('hidden');
        document.getElementById('paymentsSection')?.classList.add('hidden');
        document.getElementById('fundingSection')?.classList.add('hidden');

        // Activate "All Loans"
        const allLoansTab = document.querySelector('#sidebar li[data-filter="all"]');
        if (allLoansTab) {
            allLoansTab.classList.add('active');
            renderLoans('all');
        }

        // ==========================================
        // SIDEBAR NAVIGATION – CLEAN & SAFE
        // ==========================================
        const sidebarItems = document.querySelectorAll('#sidebar li[data-filter]');

        sidebarItems.forEach(item => {
            item.addEventListener('click', function() {
                // 1. Remove active class from all items
                sidebarItems.forEach(i => i.classList.remove('active'));

                // 2. Add active class to clicked item
                this.classList.add('active');

                // 3. Get filter (e.g. 'all', 'pending', 'payments', 'accounts', 'reports')
                const filter = this.dataset.filter;

                // 4. Hide ALL sections first (safe even if element is missing)
                document.getElementById('dashboardSection')?.classList.add('hidden');
                document.getElementById('loansSection')?.classList.add('hidden');
                document.getElementById('paymentsSection')?.classList.add('hidden');
                document.getElementById('fundingSection')?.classList.add('hidden');
                document.getElementById('reportsSection')?.classList.add('hidden');

                // 5. Show the correct section
                if (filter === 'payments') {
                    loadPayments(); // <-- shows #paymentsSection inside the function
                } else if (filter === 'accounts') {
                    loadFundingAccounts(); // <-- shows #fundingSection inside the function
                } else if (filter === 'reports') {
                    showReportsView(); // <-- shows #reportsSection
                } else {
                    // Any loan filter (all, pending, accepted, etc.)
                    document.getElementById('loansSection')?.classList.remove('hidden');
                    renderLoans(filter);
                }
            });
        });

        // Add event listeners for the export buttons
        document.getElementById('exportOverallSummaryBtn').addEventListener('click', exportOverallSummary);
        document.getElementById('exportMortgageLoansBtn').addEventListener('click', exportMortgageLoans);
        document.getElementById('exportCarLoansBtn').addEventListener('click', exportCarLoans);
        document.getElementById('exportPaymentSummaryBtn').addEventListener('click', exportPaymentSummary);
        document.getElementById('exportCompleteReportBtn').addEventListener('click', exportFinancialReport);

        // Modal close button event listeners
        document.getElementById('closeDetailsModal').addEventListener('click', () => {
            document.getElementById('detailsModal').style.display = 'none';
        });

        document.getElementById('closePaymentDetailsModal').addEventListener('click', () => {
            document.getElementById('paymentDetailsModal').style.display = 'none';
        });

        document.getElementById('closeAmort').addEventListener('click', () => {
            document.getElementById('amortizationModal').style.display = 'none';
        });

        document.getElementById('closeFeedbackModal').addEventListener('click', () => {
            document.getElementById('feedbackModal').style.display = 'none';
        });

        document.getElementById('closeFundingModal').addEventListener('click', () => {
            document.getElementById('fundingAccountModal').style.display = 'none';
        });

        // Also add these event listeners for the cancel buttons
        document.getElementById('cancelFeedback').addEventListener('click', () => {
            document.getElementById('feedbackModal').style.display = 'none';
        });

        document.getElementById('cancelDispatch').addEventListener('click', () => {
            document.getElementById('fundingAccountModal').style.display = 'none';
        });

        // Add event listener for the submit feedback button
        document.getElementById('submitFeedback').addEventListener('click', submitFeedback);

        // Add event listener for the confirm dispatch button
        document.getElementById('confirmDispatch').addEventListener('click', confirmDispatch);

        // Session management - FIXED VERSION
        const sessionDuration = 2 * 60 * 60 * 1000; // 2 hours in milliseconds
        let loginTime = sessionStorage.getItem('loginTime');

        if (!loginTime) {
            // First login, store the current time
            loginTime = Date.now().toString();
            sessionStorage.setItem('loginTime', loginTime);
        }

        // Update last activity time on page load (this allows refreshes)
        sessionStorage.setItem('lastActivityTime', Date.now().toString());

        // Function to check session validity
        function checkSessionValidity() {
            const storedLoginTime = sessionStorage.getItem('loginTime');
            const lastActivity = sessionStorage.getItem('lastActivityTime');

            if (!storedLoginTime) {
                return; // No session to check
            }

            const currentTime = Date.now();
            const timeElapsed = currentTime - parseInt(storedLoginTime);
            const remainingMinutes = Math.floor((sessionDuration - timeElapsed) / 60000);

            if (timeElapsed >= sessionDuration) {
                // Session has expired
                showNotification("Your session has expired. Please log in again.", 'warning', 5000);
                sessionStorage.removeItem('loginTime');
                sessionStorage.removeItem('lastActivityTime');
                setTimeout(() => {
                    logoutAccountant();
                }, 2000);
            }
        }

        // Initial session check on page load
        checkSessionValidity();

        // Set timeout for session expiration
        const currentTime = Date.now();
        const timeElapsed = currentTime - parseInt(loginTime);
        const remainingTime = sessionDuration - timeElapsed;

        if (remainingTime > 0) {
            // Set timeout for session expiration
            setTimeout(() => {
                showNotification("Your session has expired. Please log in again.", 'warning', 5000);
                sessionStorage.removeItem('loginTime');
                sessionStorage.removeItem('lastActivityTime');
                setTimeout(() => {
                    logoutAccountant();
                }, 2000);
            }, remainingTime);

            // Optional: Warn user 5 minutes before expiry
            const warningTime = remainingTime - (5 * 60 * 1000); // 5 minutes before expiry
            if (warningTime > 0) {
                setTimeout(() => {
                    showNotification("Your session will expire in 5 minutes. Please save your work.",
                        'warning', 8000);
                }, warningTime);
            }
        }

        // Check session validity periodically (every minute)
        setInterval(checkSessionValidity, 60000);

        // Update last activity on user interaction
        ['click', 'keypress', 'scroll', 'mousemove'].forEach(eventType => {
            document.addEventListener(eventType, () => {
                sessionStorage.setItem('lastActivityTime', Date.now().toString());
            }, {
                passive: true
            });
        });
    });

    // Handle window resize for sidebar
    window.addEventListener('resize', () => {
        const sidebar = document.getElementById('sidebar');
        if (window.innerWidth > 760) {
            sidebar.classList.remove('collapsed');
        } else {
            sidebar.classList.add('collapsed');
        }
    });

    // Place this in your admin.php, cashier.php, and accountant.php JavaScript

    // FIXED: Apply for loan function with proper error handling
    function applyForLoan() {
        const confirmSwitch = confirm("You're about to switch to the loan application portal. Continue?");

        if (!confirmSwitch) {
            return;
        }

        // Show loading state
        showNotification('Switching to loanee portal...', 'info');

        // Create loading overlay
        const loadingOverlay = document.createElement('div');
        loadingOverlay.id = 'portal-switch-overlay';
        loadingOverlay.style.cssText =
            'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(255,255,255,0.9);z-index:9999;display:flex;justify-content:center;align-items:center;flex-direction:column;';

        const spinner = document.createElement('div');
        spinner.style.cssText =
            'border:5px solid #f3f3f3;border-top:5px solid #4a6b4a;border-radius:50%;width:50px;height:50px;animation:spin 1s linear infinite;';

        const loadingText = document.createElement('p');
        loadingText.textContent = 'Switching to loanee portal...';
        loadingText.style.cssText = 'margin-top:20px;font-weight:bold;color:#4a6b4a;';

        loadingOverlay.appendChild(spinner);
        loadingOverlay.appendChild(loadingText);
        document.body.appendChild(loadingOverlay);

        // Get current portal name
        const currentPortal = window.location.pathname.includes('admin.php') ? 'admin' :
            window.location.pathname.includes('cashier.php') ? 'cashier' :
            window.location.pathname.includes('accountant.php') ? 'accountant' : 'index';

        // Redirect with proper parameters
        setTimeout(() => {
            window.location.href = `loanee.php?from=${currentPortal}&action=switch_portal`;
        }, 500);
    }

    // FIXED: Return to staff portal function
    function returnToStaffPortal() {
        if (!confirm('Return to your staff portal? Any unsaved loan application data will be lost.')) {
            return;
        }

        // Show loading notification
        showNotification('Returning to staff portal...', 'info');

        // Create loading overlay
        const loadingOverlay = document.createElement('div');
        loadingOverlay.id = 'portal-return-overlay';
        loadingOverlay.style.cssText =
            'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(255,255,255,0.9);z-index:9999;display:flex;justify-content:center;align-items:center;flex-direction:column;';

        const spinner = document.createElement('div');
        spinner.style.cssText =
            'border:5px solid #f3f3f3;border-top:5px solid #4a6b4a;border-radius:50%;width:50px;height:50px;animation:spin 1s linear infinite;';

        const loadingText = document.createElement('p');
        loadingText.textContent = 'Returning to staff portal...';
        loadingText.style.cssText = 'margin-top:20px;font-weight:bold;color:#4a6b4a;';

        loadingOverlay.appendChild(spinner);
        loadingOverlay.appendChild(loadingText);
        document.body.appendChild(loadingOverlay);

        // Redirect after brief delay
        setTimeout(() => {
            window.location.href = 'loanee.php?action=return_to_staff';
        }, 500);
    }

    // Add CSS animation for spinner
    const style = document.createElement('style');
    style.textContent = `
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
`;
    document.head.appendChild(style);

    // Make functions globally available
    window.applyForLoan = applyForLoan;
    window.returnToStaffPortal = returnToStaffPortal;

    // Add the missing showLoading function
    function showLoading() {
        // Create loading overlay if it doesn't exist
        let loadingOverlay = document.getElementById('loading-overlay');

        if (!loadingOverlay) {
            loadingOverlay = document.createElement('div');
            loadingOverlay.id = 'loading-overlay';
            loadingOverlay.style.position = 'fixed';
            loadingOverlay.style.top = '0';
            loadingOverlay.style.left = '0';
            loadingOverlay.style.width = '100%';
            loadingOverlay.style.height = '100%';
            loadingOverlay.style.backgroundColor = 'rgba(255, 255, 255, 0.8)';
            loadingOverlay.style.zIndex = '9999';
            loadingOverlay.style.display = 'flex';
            loadingOverlay.style.justifyContent = 'center';
            loadingOverlay.style.alignItems = 'center';
            loadingOverlay.style.flexDirection = 'column';

            const spinner = document.createElement('div');
            spinner.style.border = '5px solid #f3f3f3';
            spinner.style.borderTop = '5px solid var(--blue)';
            spinner.style.borderRadius = '50%';
            spinner.style.width = '50px';
            spinner.style.height = '50px';
            spinner.style.animation = 'spin 1s linear infinite';

            const loadingText = document.createElement('p');
            loadingText.textContent = 'Redirecting to loan application portal...';
            loadingText.style.marginTop = '20px';
            loadingText.style.fontWeight = 'bold';
            loadingText.style.color = 'var(--text-primary)';

            loadingOverlay.appendChild(spinner);
            loadingOverlay.appendChild(loadingText);
            document.body.appendChild(loadingOverlay);

            // Add spinner animation if not already in CSS
            if (!document.getElementById('spinner-style')) {
                const style = document.createElement('style');
                style.id = 'spinner-style';
                style.textContent = `
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
            `;
                document.head.appendChild(style);
            }
        } else {
            loadingOverlay.style.display = 'flex';
        }
    }
    </script>
</body>

</html>