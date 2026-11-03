<?php
// api/loans.php - FIXED VERSION

session_start();

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/api_loans.log');

// Clear output buffers
while (ob_get_level()) {
    ob_end_clean();
}

// Set headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/* -----------------------------------------------------------------
 *  HELPER FUNCTIONS
 * ----------------------------------------------------------------- */
function sendJson(int $code, array $payload): void
{
    while (ob_get_level()) {
        ob_end_clean();
    }

    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function logError(string $message): void
{
    error_log(date('[Y-m-d H:i:s] ') . 'LOAN API: ' . $message);
}

/* -----------------------------------------------------------------
 *  DATABASE CONNECTION
 * ----------------------------------------------------------------- */
require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
} catch (Throwable $e) {
    logError('DB connection error: ' . $e->getMessage());
    sendJson(500, ['success' => false, 'message' => 'Database connection failed']);
}

/* -----------------------------------------------------------------
 *  SESSION AUTHENTICATION
 * ----------------------------------------------------------------- */
if (empty($_SESSION['user_id'])) {
    logError('No user_id in session - Request from IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    sendJson(401, ['success' => false, 'message' => 'Unauthorized – please login.']);
}

 $currentUserId = (int)($_SESSION['user_id'] ?? 0);
 $currentUserRole = $_SESSION['user_role'] ?? 'loanee';
 $currentPfNumber = $_SESSION['pf_number'] ?? '';
 $currentIdNumber = $_SESSION['id_number'] ?? '';
 $viewingAsLoanee = isset($_SESSION['viewing_as_loanee']) && $_SESSION['viewing_as_loanee'] === true;

logError("Request from user_id: {$currentUserId}, role: {$currentUserRole}, PF: {$currentPfNumber}, viewing_as_loanee: " . ($viewingAsLoanee ? 'true' : 'false'));

/* CSRF token verification */
function verifyCsrf(array $input): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        return;
    }

    $token = $input['csrf_token'] ?? null;
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        logError('CSRF mismatch for user: ' . ($_SESSION['user_id'] ?? 'unknown'));
        sendJson(403, ['success' => false, 'message' => 'Invalid CSRF token']);
    }
}

/* -----------------------------------------------------------------
 *  LOAN APPLICATION MODEL - INLINE
 * ----------------------------------------------------------------- */
class LoanApplication
{
    private $conn;
    private $table = 'loan_applications';

    public function __construct($db)
    {
        $this->conn = $db;
    }

    /* 
    * FIXED: Enhanced read() method for LoanApplication class
    * This ensures proper loan filtering for all user types, including staff viewing as loanees
    */
    public function read($userId, $role, $pfNumber = null, $idNumber = null, $viewingAsLoanee = false): array
    {
        // Staff can see all applications ONLY if they're not viewing as a loanee
        $canSeeAll = in_array($role, ['admin', 'accountant', 'cashier'], true) && !$viewingAsLoanee;
        
        try {
            if ($canSeeAll) {
                // Staff can see all applications
                $sql = "SELECT 
                            la.*,
                            u.full_name as applicant_name,
                            u.email as applicant_email,
                            u.pf_number as applicant_pf,
                            COALESCE(ld.property_location, la.property_location) as property_location,
                            COALESCE(ld.property_value, la.property_value) as property_value,
                            COALESCE(ld.car_model, la.car_type) as car_type,
                            COALESCE(ld.car_value, la.car_value) as car_value
                        FROM {$this->table} la
                        LEFT JOIN users u ON la.user_id = u.user_id
                        LEFT JOIN loandetails ld ON la.application_id = ld.application_id
                        ORDER BY la.application_date DESC";
                $stmt = $this->conn->prepare($sql);
                $stmt->execute();
            } else {
                // FIXED: Enhanced filtering for loanees (including staff viewing as loanees)
                $sql = "SELECT 
                            la.*,
                            COALESCE(ld.property_location, la.property_location) as property_location,
                            COALESCE(ld.property_value, la.property_value) as property_value,
                            COALESCE(ld.car_model, la.car_type) as car_type,
                            COALESCE(ld.car_value, la.car_value) as car_value
                        FROM {$this->table} la
                        LEFT JOIN loandetails ld ON la.application_id = ld.application_id
                        WHERE (
                            la.user_id = :user_id 
                            OR la.pf_number = :pf_number 
                            OR la.id_number = :id_number
                            OR (la.pf_number = :pf_number_str AND la.pf_number != '')
                            OR (la.id_number = :id_number_str AND la.id_number != '')
                        )
                        ORDER BY la.application_date DESC";
                
                $stmt = $this->conn->prepare($sql);
                
                // Bind with multiple formats to handle type inconsistencies
                $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
                $stmt->bindValue(':pf_number', $pfNumber ?? '', PDO::PARAM_STR);
                $stmt->bindValue(':id_number', $idNumber ?? '', PDO::PARAM_STR);
                $stmt->bindValue(':pf_number_str', (string)$pfNumber, PDO::PARAM_STR);
                $stmt->bindValue(':id_number_str', (string)$idNumber, PDO::PARAM_STR);
                
                $stmt->execute();
                
                // Log for debugging
                error_log("Loanee read query - user_id: $userId, pf: $pfNumber, id: $idNumber, viewing_as_loanee: " . ($viewingAsLoanee ? 'true' : 'false'));
            }
            
            // Fetch all results
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            error_log("Fetched " . count($results) . " loans from database");
            
            // Update user_id if needed (only for loanee role or staff viewing as loanees)
            if (!$canSeeAll) {
                foreach ($results as $loan) {
                    if ((int)$loan['user_id'] !== $userId) {
                        error_log("Updating user_id for loan " . $loan['application_id'] . " from " . $loan['user_id'] . " to $userId");
                        
                        $updateSql = "UPDATE {$this->table} 
                                      SET user_id = :user_id 
                                      WHERE application_id = :application_id";
                        $updateStmt = $this->conn->prepare($updateSql);
                        $updateStmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
                        $updateStmt->bindValue(':application_id', $loan['application_id'], PDO::PARAM_STR);
                        $updateStmt->execute();
                    }
                }
            }
            
            // Ensure property and car details are properly set
            foreach ($results as &$loan) {
                // For mortgage loans
                if ($loan['loan_type'] === 'mortgage') {
                    $loan['property_location'] = !empty($loan['property_location']) ? $loan['property_location'] : null;
                    $loan['property_value'] = !empty($loan['property_value']) ? $loan['property_value'] : null;
                }
                
                // For car loans
                if ($loan['loan_type'] === 'car') {
                    $loan['car_type'] = !empty($loan['car_type']) ? $loan['car_type'] : null;
                    $loan['car_value'] = !empty($loan['car_value']) ? $loan['car_value'] : null;
                }
            }
            
            error_log("Returning " . count($results) . " loans after processing");
            return $results;
            
        } catch (PDOException $e) {
            error_log('Database error in read(): ' . $e->getMessage());
            error_log('SQL State: ' . $e->getCode());
            error_log('Stack trace: ' . $e->getTraceAsString());
            return [];
        }
    }

    /* 
    * FIXED: Enhanced readOne() method with same filtering logic
    */
    public function readOne($applicationId, $userId, $role, $pfNumber = null, $idNumber = null, $viewingAsLoanee = false)
    {
        // Staff can see all applications ONLY if they're not viewing as a loanee
        $canSeeAll = in_array($role, ['admin', 'accountant', 'cashier'], true) && !$viewingAsLoanee;
        
        try {
            if ($canSeeAll) {
                $sql = "SELECT 
                            la.*,
                            u.full_name as applicant_name,
                            u.email as applicant_email,
                            u.pf_number as applicant_pf,
                            COALESCE(ld.property_location, la.property_location) as property_location,
                            COALESCE(ld.property_value, la.property_value) as property_value,
                            COALESCE(ld.car_model, la.car_type) as car_type,
                            COALESCE(ld.car_value, la.car_value) as car_value
                        FROM {$this->table} la
                        LEFT JOIN users u ON la.user_id = u.user_id
                        LEFT JOIN loandetails ld ON la.application_id = ld.application_id
                        WHERE la.application_id = :id";
                $stmt = $this->conn->prepare($sql);
                $stmt->bindValue(':id', $applicationId, PDO::PARAM_STR);
            } else {
                // FIXED: Enhanced single loan filtering
                $sql = "SELECT 
                            la.*,
                            COALESCE(ld.property_location, la.property_location) as property_location,
                            COALESCE(ld.property_value, la.property_value) as property_value,
                            COALESCE(ld.car_model, la.car_type) as car_type,
                            COALESCE(ld.car_value, la.car_value) as car_value
                        FROM {$this->table} la
                        LEFT JOIN loandetails ld ON la.application_id = ld.application_id
                        WHERE la.application_id = :id 
                        AND (
                            la.user_id = :user_id 
                            OR la.pf_number = :pf_number 
                            OR la.id_number = :id_number
                        )";
                $stmt = $this->conn->prepare($sql);
                $stmt->bindValue(':id', $applicationId, PDO::PARAM_STR);
                $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
                $stmt->bindValue(':pf_number', $pfNumber ?? '', PDO::PARAM_STR);
                $stmt->bindValue(':id_number', $idNumber ?? '', PDO::PARAM_STR);
            }
            
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                // For mortgage loans
                if ($result['loan_type'] === 'mortgage') {
                    $result['property_location'] = !empty($result['property_location']) ? $result['property_location'] : null;
                    $result['property_value'] = !empty($result['property_value']) ? $result['property_value'] : null;
                }
                
                // For car loans
                if ($result['loan_type'] === 'car') {
                    $result['car_type'] = !empty($result['car_type']) ? $result['car_type'] : null;
                    $result['car_value'] = !empty($result['car_value']) ? $result['car_value'] : null;
                }
            }
            
            return $result;
            
        } catch (PDOException $e) {
            error_log('Database error in readOne(): ' . $e->getMessage());
            return false;
        }
    }

    // Rest of the methods remain the same...
    public function create($data, $userId): bool
    {
        // First insert into loan_applications table
        $sql = "INSERT INTO loan_applications SET 
            application_id = :application_id,
            user_id = :user_id,
            full_name = :full_name,
            id_number = :id_number,
            pf_number = :pf_number,
            email = :email,
            loan_type = :loan_type,
            loan_amount = :loan_amount,
            payment_duration = :payment_duration,
            payment_frequency = :payment_frequency,
            basic_salary = :basic_salary,
            employment_type = :employment_type,
            contract_period = :contract_period,
            contract_start_date = :contract_start_date,
            contract_end_date = :contract_end_date,
            property_location = :property_location,
            property_value = :property_value,
            car_type = :car_type,
            car_value = :car_value,
            bank_name = :bank_name,
            bank_branch = :bank_branch,
            bank_account_number = :bank_account_number";

        $stmt = $this->conn->prepare($sql);

        // Bind all parameters
        $stmt->bindValue(':application_id', $data['application_id'], PDO::PARAM_STR);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':full_name', $data['full_name'], PDO::PARAM_STR);
        $stmt->bindValue(':id_number', $data['id_number'], PDO::PARAM_STR);
        $stmt->bindValue(':pf_number', $data['pf_number'], PDO::PARAM_STR);
        $stmt->bindValue(':email', $data['email'], PDO::PARAM_STR);
        $stmt->bindValue(':loan_type', $data['loan_type'], PDO::PARAM_STR);
        $stmt->bindValue(':loan_amount', $data['loan_amount'], PDO::PARAM_STR);
        $stmt->bindValue(':payment_duration', $data['payment_duration'], PDO::PARAM_INT);
        $stmt->bindValue(':payment_frequency', $data['payment_frequency'] ?? 'monthly', PDO::PARAM_STR);
        $stmt->bindValue(':basic_salary', $data['basic_salary'], PDO::PARAM_STR);
        $stmt->bindValue(':employment_type', $data['employment_type'], PDO::PARAM_STR);
        $stmt->bindValue(':contract_period', $data['contract_period'] ?? null, PDO::PARAM_INT);
        $stmt->bindValue(':contract_start_date', $data['contract_start_date'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':contract_end_date', $data['contract_end_date'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':property_location', $data['property_location'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':property_value', $data['property_value'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':car_type', $data['car_type'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':car_value', $data['car_value'] ?? null, PDO::PARAM_STR);
        $stmt->bindValue(':bank_name', $data['bank_name'], PDO::PARAM_STR);
        $stmt->bindValue(':bank_branch', $data['bank_branch'], PDO::PARAM_STR);
        $stmt->bindValue(':bank_account_number', $data['bank_account_number'], PDO::PARAM_STR);

        $result = $stmt->execute();

        // Also save to loandetails table for backward compatibility
        if ($result && !empty($data['application_id'])) {
            $this->saveLoanDetails($data);
        }

        return $result;
    }

    private function saveLoanDetails($data): bool
    {
        $applicationId = $data['application_id'];

        // Check if we already have details for this application
        $checkSql = "SELECT detail_id FROM loandetails WHERE application_id = :application_id";
        $checkStmt = $this->conn->prepare($checkSql);
        $checkStmt->bindValue(':application_id', $applicationId, PDO::PARAM_STR);
        $checkStmt->execute();

        $exists = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($exists) {
            // Update existing record
            $sql = "UPDATE loandetails SET ";
            $setParts = [];
            $params = [':application_id' => $applicationId];

            if (isset($data['property_location'])) {
                $setParts[] = "property_location = :property_location";
                $params[':property_location'] = $data['property_location'];
            }

            if (isset($data['property_value'])) {
                $setParts[] = "property_value = :property_value";
                $params[':property_value'] = $data['property_value'];
            }

            if (isset($data['car_type'])) {
                $setParts[] = "car_model = :car_type"; // Map car_type to car_model
                $params[':car_type'] = $data['car_type'];
            }

            if (isset($data['car_value'])) {
                $setParts[] = "car_value = :car_value";
                $params[':car_value'] = $data['car_value'];
            }

            if (!empty($setParts)) {
                $sql .= implode(', ', $setParts) . " WHERE application_id = :application_id";
                $stmt = $this->conn->prepare($sql);

                foreach ($params as $ph => $val) {
                    $stmt->bindValue($ph, $val, PDO::PARAM_STR);
                }

                return $stmt->execute();
            }
        } else {
            // Insert new record
            $sql = "INSERT INTO loandetails (application_id, property_location, property_value, car_model, car_value) 
                    VALUES (:application_id, :property_location, :property_value, :car_type, :car_value)";
            $stmt = $this->conn->prepare($sql);

            $stmt->bindValue(':application_id', $applicationId, PDO::PARAM_STR);
            $stmt->bindValue(':property_location', $data['property_location'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':property_value', $data['property_value'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':car_type', $data['car_type'] ?? null, PDO::PARAM_STR); // Map car_type to car_model
            $stmt->bindValue(':car_value', $data['car_value'] ?? null, PDO::PARAM_STR);

            return $stmt->execute();
        }

        return true;
    }

    public function getUserStats($userId): array
    {
        try {
            $sql = "SELECT 
                        COUNT(*) as total_applications,
                        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                        SUM(CASE WHEN status = 'forwarded' THEN 1 ELSE 0 END) as forwarded,
                        SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted,
                        SUM(CASE WHEN status = 'dispatched' THEN 1 ELSE 0 END) as dispatched,
                        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                        SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END) as returned,
                        SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed,
                        SUM(CASE WHEN status IN ('accepted', 'dispatched', 'active') 
                            THEN loan_amount ELSE 0 END) as total_approved_amount,
                        SUM(CASE WHEN status = 'active' 
                            THEN loan_amount ELSE 0 END) as total_active_amount
                    FROM {$this->table}
                    WHERE user_id = :user_id";

            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            logError('Database error in getUserStats(): ' . $e->getMessage());
            return [];
        }
    }

    public function getOverallStats(): array
    {
        try {
            $sql = "SELECT 
                        COUNT(*) as total_applications,
                        COUNT(DISTINCT user_id) as total_applicants,
                        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                        SUM(loan_amount) as total_loan_amount,
                        SUM(CASE WHEN status = 'active' 
                            THEN loan_amount ELSE 0 END) as active_loan_amount
                    FROM {$this->table}";

            $stmt = $this->conn->query($sql);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            logError('Database error in getOverallStats(): ' . $e->getMessage());
            return [];
        }
    }

    // NEW UPDATE METHOD
    public function update($applicationId, $data, $userRole): bool
    {
        try {
            // Get current loan data to validate status transitions
            $currentLoan = $this->readOne($applicationId, 0, 'admin'); // Use admin role to bypass permission check
            if (!$currentLoan) {
                logError("Loan not found for update: {$applicationId}");
                return false;
            }

            $currentStatus = $currentLoan['status'];
            $newStatus = $data['status'] ?? null;

            // Validate status transitions based on user role
            if (!$this->validateStatusTransition($currentStatus, $newStatus, $userRole)) {
                logError("Invalid status transition from {$currentStatus} to {$newStatus} by {$userRole}");
                return false;
            }

            // Build update query
            $sql = "UPDATE {$this->table} SET status = :status";
            $params = [
                ':status' => $newStatus,
                ':application_id' => $applicationId
            ];

            // Add feedback if provided
            if (isset($data['feedback'])) {
                $sql .= ", feedback = :feedback";
                $params[':feedback'] = $data['feedback'];
            }

            // Add timestamps based on status
            if ($newStatus === 'accepted') {
                $sql .= ", accountant_processed_date = NOW()";
            } elseif ($newStatus === 'dispatched') {
                $sql .= ", dispatch_date = NOW()";
            }

            $sql .= " WHERE application_id = :application_id";

            $stmt = $this->conn->prepare($sql);

            foreach ($params as $param => $value) {
                $stmt->bindValue($param, $value, PDO::PARAM_STR);
            }

            $result = $stmt->execute();

            if ($result) {
                logError("Loan status updated successfully: {$applicationId} from {$currentStatus} to {$newStatus} by {$userRole}");

                // If loan is dispatched, create payment schedule
                if ($newStatus === 'dispatched') {
                    $this->createPaymentSchedule($applicationId, $currentLoan);
                }
            }

            return $result;
        } catch (PDOException $e) {
            logError('Database error in update(): ' . $e->getMessage());
            return false;
        }
    }

    // Helper method to validate status transitions
    private function validateStatusTransition($currentStatus, $newStatus, $userRole): bool
    {
        if (!$newStatus) return false;

        // Define valid transitions for each role
        $validTransitions = [
            'cashier' => [
                'pending' => ['forwarded', 'rejected'],
                'returned' => ['forwarded', 'rejected'],
                'accepted' => ['dispatched'],
                'dispatched' => ['active'],
                'active' => ['closed'],
                'rejected' => ['closed']
            ],
            'accountant' => [
                'forwarded' => ['accepted', 'returned', 'rejected'],
                'accepted' => ['rejected']
            ],
            'admin' => [
                // Admin can do any transition
                'pending' => ['forwarded', 'rejected', 'accepted'],
                'forwarded' => ['accepted', 'returned', 'rejected', 'pending'],
                'returned' => ['forwarded', 'rejected', 'pending'],
                'accepted' => ['dispatched', 'rejected', 'pending'],
                'dispatched' => ['active', 'rejected', 'pending'],
                'active' => ['closed', 'rejected', 'pending'],
                'rejected' => ['closed', 'pending']
            ]
        ];

        // Check if transition is valid for user's role
        if (isset($validTransitions[$userRole][$currentStatus])) {
            return in_array($newStatus, $validTransitions[$userRole][$currentStatus]);
        }

        return false;
    }

    // Helper method to create payment schedule when loan is dispatched
    private function createPaymentSchedule($applicationId, $loan): bool
    {
        try {
            // Check if schedule already exists
            $checkSql = "SELECT COUNT(*) FROM payment_schedules WHERE loan_application_id = :application_id";
            $checkStmt = $this->conn->prepare($checkSql);
            $checkStmt->bindValue(':application_id', $applicationId, PDO::PARAM_STR);
            $checkStmt->execute();

            if ($checkStmt->fetchColumn() > 0) {
                return true; // Schedule already exists
            }

            // Get interest rate from system settings
            $rateSql = "SELECT value FROM system_settings WHERE setting_key = 'amortization_rate'";
            $rateStmt = $this->conn->prepare($rateSql);
            $rateStmt->execute();
            $rateRow = $rateStmt->fetch(PDO::FETCH_ASSOC);
            $annualRate = floatval($rateRow['value'] ?? 3.0);

            // Calculate payment schedule
            $principal = floatval($loan['loan_amount']);
            $years = intval($loan['payment_duration']);
            $paymentsPerYear = 12;
            $totalPayments = $years * $paymentsPerYear;
            $periodicRate = $annualRate / 100 / $paymentsPerYear;

            // Calculate monthly payment using amortization formula
            $monthlyPayment = $principal * ($periodicRate * pow(1 + $periodicRate, $totalPayments)) /
                (pow(1 + $periodicRate, $totalPayments) - 1);

            // Get dispatch date for grace period
            $dispatchDateStr = $loan['dispatch_date'] ?? date('Y-m-d');
            $dispatchDate = new DateTime($dispatchDateStr);
            $gracePeriodEndDate = new DateTime($dispatchDateStr);
            $gracePeriodEndDate->add(new DateInterval('P1M')); // Add 1 month grace period

            $startDate = new DateTime($gracePeriodEndDate->format('Y-m-d'));
            $balance = $principal;

            for ($i = 0; $i < $totalPayments; $i++) {
                $dueDate = new DateTime($startDate->format('Y-m-d'));
                $dueDate->add(new DateInterval('P' . $i . 'M'));

                $interestPayment = $balance * $periodicRate;
                $principalPayment = $monthlyPayment - $interestPayment;
                $balance -= $principalPayment;

                $insertSql = "INSERT INTO payment_schedules (
                    loan_application_id, payment_number, due_date, expected_amount, 
                    expected_principal, expected_interest, expected_balance, status
                ) VALUES (
                    :application_id, :payment_number, :due_date, :expected_amount,
                    :expected_principal, :expected_interest, :expected_balance, 'pending'
                )";

                $insertStmt = $this->conn->prepare($insertSql);
                $insertStmt->bindValue(':application_id', $applicationId, PDO::PARAM_STR);
                $insertStmt->bindValue(':payment_number', $i + 1, PDO::PARAM_INT);
                $insertStmt->bindValue(':due_date', $dueDate->format('Y-m-d'), PDO::PARAM_STR);
                $insertStmt->bindValue(':expected_amount', $monthlyPayment, PDO::PARAM_STR);
                $insertStmt->bindValue(':expected_principal', $principalPayment, PDO::PARAM_STR);
                $insertStmt->bindValue(':expected_interest', $interestPayment, PDO::PARAM_STR);
                $insertStmt->bindValue(':expected_balance', max(0, $balance), PDO::PARAM_STR);

                $insertStmt->execute();
            }

            return true;
        } catch (PDOException $e) {
            logError('Error creating payment schedule: ' . $e->getMessage());
            return false;
        }
    }
}

/* -----------------------------------------------------------------
 *  ROUTING
 * ----------------------------------------------------------------- */
try {
    $loan = new LoanApplication($conn);

    $method = $_SERVER['REQUEST_METHOD'];
    $input  = [];

    if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            logError('Invalid JSON received: ' . json_last_error_msg());
            sendJson(400, ['success' => false, 'message' => 'Invalid JSON']);
        }

        verifyCsrf($input);
    }

    /* ---------- GET ---------- */
    if ($method === 'GET') {
        $applicationId = $_GET['id'] ?? $_GET['application_id'] ?? null;

        // DEBUG LOGGING
        logError("=== GET REQUEST DEBUG ===");
        logError("Session user_id: " . ($currentUserId ?? 'NULL'));
        logError("Session user_role: " . ($currentUserRole ?? 'NULL'));
        logError("Session pf_number: " . ($currentPfNumber ?? 'NULL'));
        logError("Session id_number: " . ($currentIdNumber ?? 'NULL'));
        logError("Viewing as loanee: " . ($viewingAsLoanee ? 'true' : 'false'));

        if ($applicationId) {
            logError("Fetching single loan: {$applicationId}");
            $loanData = $loan->readOne($applicationId, $currentUserId, $currentUserRole, $currentPfNumber, $currentIdNumber, $viewingAsLoanee);

            if (!$loanData) {
                logError("Loan not found or access denied: {$applicationId}");
                sendJson(404, [
                    'success' => false,
                    'message' => 'Loan application not found or access denied'
                ]);
            }

            logError("Loan found: {$applicationId}");
            sendJson(200, ['success' => true, 'data' => $loanData]);
        }

        // Get all loans
        logError("Fetching all loans for user: {$currentUserId}, role: {$currentUserRole}, viewing_as_loanee: " . ($viewingAsLoanee ? 'true' : 'false'));
        $list = $loan->read($currentUserId, $currentUserRole, $currentPfNumber, $currentIdNumber, $viewingAsLoanee);

        // DEBUG: Log first loan to see structure
        if (!empty($list)) {
            logError("First loan structure: " . json_encode($list[0]));
        }

        logError("Found " . count($list) . " loans");

        if ($currentUserRole === 'loanee' || $viewingAsLoanee) {
            $stats = $loan->getUserStats($currentUserId);

            // DEBUG: Log response structure
            logError("Sending response with " . count($list) . " loans for loanee");

            sendJson(200, [
                'success' => true,
                'data' => $list,
                'stats' => $stats,
                'user_role' => $currentUserRole,
                'user_id' => $currentUserId,
                'pf_number' => $currentPfNumber,  // Add for debugging
                'id_number' => $currentIdNumber,   // Add for debugging
                'viewing_as_loanee' => $viewingAsLoanee  // Add for debugging
            ]);
        } else {
            $stats = $loan->getOverallStats();

            sendJson(200, [
                'success' => true,
                'data' => $list,
                'stats' => $stats,
                'user_role' => $currentUserRole,
                'user_id' => $currentUserId,
                'viewing_as_loanee' => $viewingAsLoanee  // Add for debugging
            ]);
        }
    }

    /* ---------- POST (Create) ---------- */
    if ($method === 'POST') {
        logError("POST request received - creating loan");
        logError("Input data: " . json_encode($input));

        // Validate required fields
        $required = ['loan_type', 'loan_amount', 'payment_duration', 'basic_salary'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                sendJson(400, ['success' => false, 'message' => "Missing required field: {$field}"]);
            }
        }

        // Create the loan application
        $success = $loan->create($input, $currentUserId);

        if ($success) {
            logError("Loan created successfully for user: {$currentUserId}");
            sendJson(201, [
                'success' => true,
                'message' => 'Loan application created successfully',
                'application_id' => $input['application_id'] ?? null
            ]);
        } else {
            logError("Failed to create loan for user: {$currentUserId}");
            sendJson(500, ['success' => false, 'message' => 'Failed to create loan application']);
        }
    }

    /* ---------- PUT (Update) - NEW IMPLEMENTATION ---------- */
    if ($method === 'PUT') {
        logError("PUT request received - updating loan");

        // Validate required fields
        if (empty($input['application_id'])) {
            sendJson(400, ['success' => false, 'message' => 'Application ID is required']);
        }

        if (empty($input['status'])) {
            sendJson(400, ['success' => false, 'message' => 'Status is required']);
        }

        // Verify the loan exists and user has permission to update it
        $loanData = $loan->readOne($input['application_id'], $currentUserId, $currentUserRole, $currentPfNumber, $currentIdNumber, $viewingAsLoanee);
        if (!$loanData) {
            sendJson(404, ['success' => false, 'message' => 'Loan application not found']);
        }

        // Update the loan
        $success = $loan->update($input['application_id'], [
            'status' => $input['status'],
            'feedback' => $input['feedback'] ?? ''
        ], $currentUserRole);

        if ($success) {
            logError("Loan updated successfully: {$input['application_id']}");
            sendJson(200, [
                'success' => true,
                'message' => 'Loan status updated successfully'
            ]);
        } else {
            logError("Failed to update loan: {$input['application_id']}");
            sendJson(500, ['success' => false, 'message' => 'Failed to update loan status']);
        }
    }

    /* ---------- DELETE ---------- */
    if ($method === 'DELETE') {
        logError("DELETE request received");
        sendJson(501, ['success' => false, 'message' => 'Delete functionality not yet implemented']);
    }

    /* ---------- METHOD NOT ALLOWED ---------- */
    logError("Method not allowed: {$method}");
    sendJson(405, ['success' => false, 'message' => 'Method not allowed']);
} catch (Throwable $e) {
    logError('Unexpected error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    logError('Stack trace: ' . $e->getTraceAsString());
    sendJson(500, ['success' => false, 'message' => 'Internal server error', 'debug' => $e->getMessage()]);
}