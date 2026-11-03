<?php
// ===== SESSION CONFIGURATION (Aligned with index.php) =====
ini_set('session.gc_maxlifetime', 36000);
ini_set('session.cookie_lifetime', 36000);
ini_set('session.gc_probability', 1);
ini_set('session.gc_divisor', 100);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');

// Use consistent domain handling as per index.php
$sessionDomain = '';
if (!empty($_SERVER['HTTP_HOST'])) {
    $sessionDomain = $_SERVER['HTTP_HOST'];
}

session_set_cookie_params([
    'lifetime' => 36000,
    'path' => '/',
    'domain' => $sessionDomain,
    'secure' => false, 
    'httponly' => true,
    'samesite' => 'Lax'
]);

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Start output buffering after session configuration
ob_start();

// Update last activity and regeneration logic
if (!isset($_SESSION['last_regeneration'])) {
    $_SESSION['last_regeneration'] = time();
} elseif (time() - $_SESSION['last_regeneration'] > 43200) { // Optional: Regenerate every hour
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

// Include config and database 
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

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
        header("Location: /loan-system/index.php?error=login_required");
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

function createPaymentScheduleForDispatchedLoan($loanId, $loanAmount, $paymentDuration, $dispatchDate)
{
    global $pdo;

    $existingStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM payment_schedules WHERE loan_application_id = ?"
    );
    $existingStmt->execute([$loanId]);
    if ((int) $existingStmt->fetchColumn() > 0) {
        return;
    }

    $rateStmt = $pdo->prepare(
        "SELECT value FROM system_settings WHERE setting_key = 'amortization_rate' LIMIT 1"
    );
    $rateStmt->execute();
    $annualRate = (float) ($rateStmt->fetchColumn() ?: 3.0);

    $principal = (float) $loanAmount;
    $years = max(1, (int) $paymentDuration);
    $totalPayments = $years * 12;
    $monthlyRate = $annualRate / 100 / 12;

    if ($monthlyRate > 0) {
        $monthlyPayment = $principal *
            ($monthlyRate * pow(1 + $monthlyRate, $totalPayments)) /
            (pow(1 + $monthlyRate, $totalPayments) - 1);
    } else {
        $monthlyPayment = $principal / $totalPayments;
    }

    $startDate = new DateTime($dispatchDate);
    $startDate->add(new DateInterval('P1M'));
    $balance = $principal;

    $insertStmt = $pdo->prepare(
        "INSERT INTO payment_schedules
            (loan_application_id, payment_number, due_date, expected_amount,
             expected_principal, expected_interest, expected_balance, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')"
    );

    for ($i = 1; $i <= $totalPayments; $i++) {
        $dueDate = clone $startDate;
        $dueDate->add(new DateInterval('P' . ($i - 1) . 'M'));

        $interestPayment = $balance * $monthlyRate;
        $principalPayment = min($balance, $monthlyPayment - $interestPayment);
        $balance = max(0, $balance - $principalPayment);

        $insertStmt->execute([
            $loanId,
            $i,
            $dueDate->format('Y-m-d'),
            round($monthlyPayment, 2),
            round($principalPayment, 2),
            round($interestPayment, 2),
            round($balance, 2)
        ]);
    }
}

function dispatchLoan($loanId, $fundingAccountId = null)
{
    global $pdo;

    try {
        $pdo->beginTransaction();

        // Get loan amount
        $loanStmt = $pdo->prepare(
            "SELECT loan_amount, payment_duration FROM loan_applications WHERE id = ?"
        );
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

        // The Loanee portal reads schedules from payment_schedules. Generate
        // them in the same transaction when the loan is dispatched.
        createPaymentScheduleForDispatchedLoan(
            $loanId,
            $loan['loan_amount'],
            $loan['payment_duration'],
            $dispatchDate
        );

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

// Debtors management functions
function getDebtorsSummary()
{
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                financial_year,
                COUNT(*) as total_debtors,
                SUM(amount) as total_amount,
                SUM(CASE WHEN loan_application_id IS NULL THEN 1 ELSE 0 END) as pending_conversion
            FROM debtors
            GROUP BY financial_year
            ORDER BY financial_year DESC
        ");
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error in getDebtorsSummary: " . $e->getMessage());
        return [];
    }
}

function getDebtorsByYear($financialYear)
{
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                d.*,
                la.status as loan_status,
                la.application_date
            FROM debtors d
            LEFT JOIN loan_applications la ON d.loan_application_id = la.application_id
            WHERE d.financial_year = ?
            ORDER BY d.created_at DESC
        ");
        $stmt->execute([$financialYear]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error in getDebtorsByYear: " . $e->getMessage());
        return [];
    }
}

// ==================================================================
// ENHANCED EXPORT FUNCTIONS WITH SESSION VALIDATION
// ==================================================================

// Export functions
function exportLoansToCSV($filter = 'all')
{
    global $pdo;

    // CRITICAL: Verify session is still valid before exporting
    if (empty($_SESSION['user_id']) || empty($_SESSION['user_role'])) {
        header('HTTP/1.1 401 Unauthorized');
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Session expired. Please refresh the page and log in again.',
            'code' => 'SESSION_EXPIRED'
        ]);
        exit();
    }

    if ($_SESSION['user_role'] !== 'accountant') {
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Unauthorized access',
            'code' => 'UNAUTHORIZED'
        ]);
        exit();
    }

    // Update last activity
    $_SESSION['last_activity'] = time();

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

        // Generate filename with timestamp
        $timestamp = date('Y-m-d_His');
        $filename = "loans_export_{$filter}_{$timestamp}.csv";
        
        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        // Add UTF-8 BOM for Excel compatibility
        fprintf($output, "\xEF\xBB\xBF");

        // CSV Headers
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

        // CSV Data
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Force text format for Application ID
            $appId = $row['application_id'] . "\t";

            fputcsv($output, [
                $row['id'],
                $appId,
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
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Database error occurred during export',
            'code' => 'DB_ERROR'
        ]);
        exit();
    }
}

// Export payments to CSV
function exportPaymentsToCSV()
{
    global $pdo;

    // CRITICAL: Verify session is still valid
    if (empty($_SESSION['user_id']) || empty($_SESSION['user_role'])) {
        header('HTTP/1.1 401 Unauthorized');
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Session expired. Please refresh the page and log in again.',
            'code' => 'SESSION_EXPIRED'
        ]);
        exit();
    }

    if ($_SESSION['user_role'] !== 'accountant') {
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Unauthorized access',
            'code' => 'UNAUTHORIZED'
        ]);
        exit();
    }

    // Update last activity
    $_SESSION['last_activity'] = time();

    try {
        $query = "SELECT lp.*, la.full_name as loanee_name, la.pf_number, la.loan_amount, la.loan_type 
                    FROM payments lp 
                    JOIN loan_applications la ON lp.application_id = la.application_id 
                    ORDER BY lp.payment_date DESC";

        $stmt = $pdo->prepare($query);
        $stmt->execute();

        $timestamp = date('Y-m-d_His');
        $filename = "payments_export_{$timestamp}.csv";
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        // Add UTF-8 BOM
        fprintf($output, "\xEF\xBB\xBF");

        // CSV Headers
        fputcsv($output, [
            'Loanee Name',
            'PF Number',
            'Loan Type',
            'Loan Amount',
            'Payment Date',
            'Principal Amount',
            'Interest Amount',
            'Total Amount',
            'Payment Source',
            'Recorded By',
            'Transaction Reference',
            'Notes',
            'Recorded At'
        ]);

        // CSV Data
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Force text format for large numbers
            $loanAmount = $row['loan_amount'] . "\t";
            $principalAmount = $row['principal_amount'] . "\t";
            $interestAmount = $row['interest_amount'] . "\t";
            $totalAmount = $row['amount'] . "\t";

            fputcsv($output, [
                $row['loanee_name'],
                $row['pf_number'],
                $row['loan_type'],
                $loanAmount,
                $row['payment_date'],
                $principalAmount,
                $interestAmount,
                $totalAmount,
                $row['payment_source'],
                $row['recorded_by'],
                $row['transaction_reference'] ?? '',
                $row['notes'] ?? '',
                $row['recorded_at'] ?? ''
            ]);
        }

        fclose($output);
        exit();
        
    } catch (PDOException $e) {
        error_log("Error in exportPaymentsToCSV: " . $e->getMessage());
        header('HTTP/1.1 500 Internal Server Error');
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Database error occurred during export',
            'code' => 'DB_ERROR'
        ]);
        exit();
    }
}

// Initialize session variables for JavaScript
function initAccountantSession() {
    // Initialize session variables here if needed
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
                    $type = $_GET['type'] ?? 'loans';
                    if ($type === 'payments') {
                        exportPaymentsToCSV();
                    } else {
                        // Default to loans export
                        $filter = $_GET['filter'] ?? 'all';
                        exportLoansToCSV($filter);
                    }
                } else {
                    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
                }
                break;

            case 'debtors':
                if ($method === 'GET') {
                    $financialYear = $_GET['financial_year'] ?? null;
                    
                    // FIX: Removed require_once because functions are defined in this file.
                    // require_once __DIR__ . '/debtors.php'; 
                    
                    if ($financialYear) {
                        $debtors = getDebtorsByYear($financialYear);
                        echo json_encode(['success' => true, 'data' => ['debtors' => $debtors]]);
                    } else {
                        $summary = getDebtorsSummary();
                        echo json_encode(['success' => true, 'data' => $summary]);
                    }
                } else {
                    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
                }
                break;
            
            // FIX: Added session_check endpoint for the Session Manager JS
            case 'session_check':
                if ($method === 'GET') {
                    $_SESSION['last_activity'] = time();
                    echo json_encode(['success' => true, 'message' => 'Session valid']);
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
    header("Location: /loan-system/index.php?logout=success");
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
    <link rel="stylesheet" href="../css/accountant.css?v=20260930-1" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="../js/logout-handler.js"></script>
    <script src="../js/lucide.min.js"></script>
</head>

<body>
    <!-- Main Content (Hidden Initially) -->
    <header id="mainHeader">
        <button id="menuToggle" class="hamburger" aria-label="Toggle navigation menu">
            ☰
        </button>
        <div class="logo-box">
            <img src="../logo.jpg" alt="Logo">
            <h1>Accountant Portal</h1>
        </div>

        <!-- Header Controls Wrapper -->
        <div class="header-controls">
            <!-- Theme Selector -->
            <div class="theme-selector" id="themeSelector">
                <button class="theme-option active" data-theme="light" aria-label="Light theme" title="Light theme">
                    <i data-lucide="sun" class="icon-sm"></i>
                </button>
                <button class="theme-option" data-theme="dark" aria-label="Dark theme" title="Dark theme">
                    <i data-lucide="moon" class="icon-sm"></i>
                </button>
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
                        <i data-lucide="log-out" class="icon-sm"></i> Logout
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- SIDEBAR NAVIGATION -->
    <nav id="sidebar" role="navigation" aria-label="Accountant navigation">
        <div class="sidebar-logo">
            <img src="../logo.jpg" alt="logo">
            <div class="small">Accountant Portal</div>
        </div>
        <ul>
            <li id="navLoans" tabindex="0" role="button" data-filter="all">
                <i data-lucide="coins" class="icon-sm"></i> <span class="label">All Loans</span>
            </li>
            <li id="navForwarded" tabindex="0" role="button" data-filter="forwarded">
                <i data-lucide="clipboard-list" class="icon-sm"></i> <span class="label">Forwarded (Pending)</span>
            </li>
            <li id="navAccepted" tabindex="0" role="button" data-filter="accepted">
                <i data-lucide="check-circle" class="icon-sm"></i> <span class="label">Accepted (Awaiting Dispatch)</span>
            </li>
            <li id="navDispatched" tabindex="0" role="button" data-filter="dispatched">
                🚚 <span class="label">Dispatched</span>
            </li>
            <li id="navActive" tabindex="0" role="button" data-filter="active">
                <i data-lucide="refresh-cw" class="icon-sm"></i> <span class="label">Active Loans</span>
            </li>
            <li id="navRejected" tabindex="0" role="button" data-filter="rejected">
                <i data-lucide="x-circle" class="icon-sm"></i> <span class="label">Rejected</span>
            </li>
            <li id="navClosed" tabindex="0" role="button" data-filter="closed">
                <i data-lucide="check-circle" class="icon-sm"></i> <span class="label">Closed</span>
            </li>
            <li id="navPayments" tabindex="0" role="button" data-filter="payments">
                💳 <span class="label">Payment Records</span>
            </li>
            <li id="navAccounts" tabindex="0" role="button" data-filter="accounts">
                🏦 <span class="label">Funding Accounts</span>
            </li>
            <li id="navReports" tabindex="0" role="button" data-filter="reports">
                <i data-lucide="bar-chart-3" class="icon-sm"></i> <span class="label">Financial Reports</span>
            </li>

            <!-- FIXED: Changed from <button> to <li> for proper alignment -->
            <li data-filter="outstanding-debtors" tabindex="0" role="button">
                🧾 <span class="label">Outstanding Debtors</span>
            </li>

            <li data-filter="converted-debtors" tabindex="0" role="button">
                <i data-lucide="check-circle" class="icon-sm"></i> <span class="label">Converted Debtors</span>
            </li>
            <li tabindex="0" role="button" onclick="window.location.href='../legacy-debt.php'">
                🧾 <span class="label">Legacy Debt</span>
            </li>

            <button class="apply-loan-btn" onclick="applyForLoan()">
                <span class="button-icon"><i data-lucide="file-edit" class="icon-sm"></i></span>
                <span class="button-text">Apply for Loan</span>
            </button>
        </ul>

        <div style="margin-top: 16px;">
            <button id="logoutBtnSidebar" class="btn-danger" style="width: calc(100% - 16px); margin: 0 8px;">
                <i data-lucide="log-out" class="icon-sm"></i> <span class="label">Logout</span>
            </button>
        </div>
    </nav>

    <!-- MAIN CONTENT -->
    <main class="main" id="main">
        <div id="mainContent" style="display: none;"></div>
        <!-- Loans Section -->
        <section id="loansSection" class="hidden">
            <h2 id="sectionTitle" style="display: none;">All Loans</h2>

            <div
                style="margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <span style="font-weight: 600; color: #4a6b4a; font-size: 1.3rem;">All Loans</span>
                    <button id="exportLoansBtn" class="action-btn"
                        style="background: #2196F3; padding: 8px 16px; font-size: 0.95rem;">
                        <i data-lucide="file-text" class="icon-sm"></i> Export to CSV
                    </button>
                </div>
            </div>

            <div class="filter-bar">
                <div class="filter-item search-wrapper">
                    <span class="search-icon"><i data-lucide="search" class="icon-sm"></i></span>
                    <input type="search" id="loanSearch" placeholder="Search by name, ID, PF..." class="filter-input">
                </div>
                <div class="filter-item">
                    <label for="statusFilter" class="filter-label">Status:</label>
                    <select id="statusFilter" class="filter-select">
                        <option value="All">All</option>
                        <option value="pending">Pending</option>
                        <option value="forwarded">Forwarded</option>
                        <option value="accepted">Accepted</option>
                        <option value="active">Active</option>
                        <option value="dispatched">Dispatched</option>
                        <option value="rejected">Rejected</option>
                        <option value="closed">Closed</option>
                        <option value="returned">Returned</option>
                    </select>
                </div>
                <div class="filter-item">
                    <label for="loanTypeFilter" class="filter-label">Loan Type:</label>
                    <select id="loanTypeFilter" class="filter-select">
                        <option value="All">All</option>
                        <option value="mortgage">Mortgage</option>
                        <option value="car">Car</option>
                    </select>
                </div>
                <button id="applyFilters" class="filter-btn"><i data-lucide="edit-2" class="icon-sm"></i> Filter</button>
                <button id="clearFilters" class="clear-btn"><i data-lucide="x-circle" class="icon-sm"></i> Clear</button>
                <button id="refreshLoans" class="refresh-btn"><i data-lucide="refresh-cw" class="icon-sm"></i> Refresh</button>
            </div>

            <div class="table-responsive">

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
                                <th>Amortization</th>
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
            <h2><i data-lucide="bar-chart-3" class="icon-sm"></i> Financial Reports & Analytics</h2>

            <!-- Top Export Buttons (As per description) -->
            <div style="margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap;">
                <button id="exportReportLoansBtn" class="export-btn">⬇ Export Loans</button>
                <button id="exportOverallSummaryBtn" class="export-btn">⬇ Export Overall Summary</button>
                <button id="exportMortgageLoansBtn" class="export-btn">⬇ Export Mortgage Loans</button>
                <button id="exportCarLoansBtn" class="export-btn">⬇ Export Car Loans</button>
                <button id="exportPaymentSummaryBtn" class="export-btn">⬇ Export Payment Summary</button>
                <button id="exportCompleteReportBtn" class="export-btn" style="background: var(--danger);">⬇ Export
                    Complete Report</button>
            </div>

            <!-- Dynamic Reports Content (Filters, Cards, Tables) -->
            <div id="reportsContent"></div>
        </section>


        </section>
    </main>

    <!-- Loan Details Modal -->
    <div id="detailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i data-lucide="file-text" class="icon-sm"></i> Loan Details</h2>
                <span class="close-btn" id="closeDetailsModal">&times;</span>
            </div>
            <div class="modal-body">
                <div id="loanDetailsContent"></div>
            </div>
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
            <h2><i data-lucide="bar-chart-3" class="icon-sm"></i> Payment Schedule & Amortization</h2>
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
    </script>
    <script>
    // ==========================================
    // EVENT LISTENERS
    // ==========================================
    document.addEventListener('DOMContentLoaded', function() {
        
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

        //Initialize theme selector
        initializeThemeSelector();

        // Start session timeout tracking
        addSessionIndicator();

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
        const sidebarItems = document.querySelectorAll(
            '#sidebar [data-filter]'); // Changed to select any element with data-filter

        sidebarItems.forEach(item => {
            item.addEventListener('click', function(event) {
                event.preventDefault(); // Prevent any default behavior

                // 1. Remove active class from all items (li and button)
                sidebarItems.forEach(i => i.classList.remove('active'));

                // 2. Add active class to clicked item
                this.classList.add('active');

                // Reset client-side filters when changing loan sections.
                loanFilters.search = '';
                loanFilters.type = 'all';
                const searchInput = document.getElementById('loanSearch');
                const typeSelect = document.getElementById('loanTypeFilter');
                if (searchInput) searchInput.value = '';
                if (typeSelect) typeSelect.value = 'All';

                // 3. Get filter (e.g. 'all', 'pending', 'outstanding-debtors', etc.)
                const filter = this.dataset.filter;

                // 4. Hide ALL sections first (including mainContent for debtors)
                hideAllSections();
                document.getElementById('mainContent').style.display = 'none';

                // 5. Show the correct content based on filter
                if (filter === 'outstanding-debtors') {
                    showOutstandingDebtors();
                } else if (filter === 'converted-debtors') {
                    viewConvertedDebtors();
                } else if (filter === 'payments') {
                    loadPayments();
                } else if (filter === 'accounts') {
                    loadFundingAccounts();
                } else if (filter === 'reports') {
                    showReportsView();
                } else {
                    // Default to loans (e.g., 'all', 'forwarded', etc.)
                    document.getElementById('loansSection')?.classList.remove('hidden');
                    renderLoans(filter);
                }
            });
        });


        // Add event listeners for the export buttons
        document.getElementById('exportPaymentsBtn').addEventListener('click', exportPaymentsData);
        document.getElementById('exportOverallSummaryBtn').addEventListener('click', exportOverallSummary);
        document.getElementById('exportMortgageLoansBtn').addEventListener('click', exportMortgageLoans);
        document.getElementById('exportCarLoansBtn').addEventListener('click', exportCarLoans);
        document.getElementById('exportPaymentSummaryBtn').addEventListener('click', exportPaymentSummary);
        document.getElementById('exportCompleteReportBtn').addEventListener('click', exportFinancialReport);

        // FIX: Add specific listener for the Reports Section "Export Loans" button
        document.getElementById('exportReportLoansBtn').addEventListener('click', function() {
            // In the reports view, "Export Loans" typically implies exporting the entire dataset
            exportLoans('all');
        });

        // Existing listener for the Loans section button (This remains for filtering specific views)
        document.getElementById('exportLoansBtn').addEventListener('click', function() {
            const activeFilter = document.querySelector('#sidebar li.active').dataset.filter || 'all';
            exportLoans(activeFilter);
        });


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

        // Session expiry is enforced by the server using idle activity time.
        // The old login-time countdown logged out active users after two hours.
        sessionStorage.setItem('loginTime', Date.now().toString());
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
    </script>
    <script src="../js/accountant.js?v=20260930-1"></script>
        <script>
    const renderIcons = () => {
        lucide.createIcons();
        document.querySelectorAll('svg[data-lucide]').forEach(el => el.removeAttribute('data-lucide'));
    };
    renderIcons();
    const observer = new MutationObserver((mutations) => {
        let hasNewNodes = false;
        mutations.forEach((mutation) => {
            mutation.addedNodes.forEach(node => {
                if (node.nodeType === 1) hasNewNodes = true;
            });
        });
        if (hasNewNodes) {
            observer.disconnect();
            renderIcons();
            observer.observe(document.body, { childList: true, subtree: true });
        }
    });
    observer.observe(document.body, { childList: true, subtree: true });
</script>
</body>

</html>
