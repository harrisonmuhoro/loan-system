<?php
// Include required files
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/ApiHelper.php';

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/api_loans.log');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ApiHelper::sendJson(204, []);
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check authentication
if (!isset($_SESSION['user_id'])) {
    ApiHelper::logError('Unauthorized access attempt');
    ApiHelper::sendJson(401, ['success' => false, 'message' => 'Unauthorized – please login.']);
}

// Verify CSRF token for POST/PUT/DELETE
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $token = $input['csrf_token'] ?? null;
    
    if (!validateCSRFToken($token)) {
        ApiHelper::logError('CSRF token validation failed');
        ApiHelper::sendJson(403, ['success' => false, 'message' => 'Invalid CSRF token']);
    }
}

// Initialize database connection
try {
    $database = new Database();
    
    // Test connection
    if (!$database->testConnection()) {
        throw new Exception('Database connection test failed');
    }
    
    $conn = $database->getConnection();
    
    // Log database info in development
    if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
        $dbInfo = $database->getDatabaseInfo();
        ApiHelper::logError('Database info: ' . json_encode($dbInfo));
    }
    
} catch (Exception $e) {
    ApiHelper::logError('Database connection error: ' . $e->getMessage());
    ApiHelper::sendJson(500, ['success' => false, 'message' => 'Database connection failed']);
}

// Resolve identity from the database after the connection is initialized.
$currentUserId = (int)$_SESSION['user_id'];
$identityStmt = $conn->prepare('SELECT role, pf_number, id_number, is_active FROM users WHERE user_id = ?');
$identityStmt->execute([$currentUserId]);
$identity = $identityStmt->fetch(PDO::FETCH_ASSOC);
if (!$identity || (int)$identity['is_active'] !== 1) {
    ApiHelper::sendJson(401, ['success' => false, 'message' => 'Unauthorized']);
}
$currentUserRole = strtolower((string)$identity['role']);
$currentPfNumber = (string)($identity['pf_number'] ?? '');
$currentIdNumber = (string)($identity['id_number'] ?? '');
$viewingAsLoanee = isset($_SESSION['viewing_as_loanee']) && $_SESSION['viewing_as_loanee'] === true;

// Clear output buffers
while (ob_get_level()) {
    ob_end_clean();
}

// Set headers
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

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
 *  GENERATE UNIQUE LOAN ID
 * ----------------------------------------------------------------- */
/**
 * Generates a unique Loan ID using the format:
 * YYYYMMDDHHMMSS + Micro(4) + Random(4)
 */
function generateUniqueLoanId() {
    $timestamp = time();
    $micro = microtime(true);
    
    // FIX: Ensure variable name is correct and parenthesis closed
    $parts = explode('.', (string)$micro); 
    
    // Extract last 4 digits of micro part
    $microPart = isset($parts[1]) ? substr($parts[1], 0, 4) : rand(1000, 9999);
    
    // FIX: Add missing 4th argument to str_pad (STR_PAD_RIGHT) and cast to string
    $randomPart = str_pad((string)rand(1000, 9999), 4, '0', STR_PAD_RIGHT);
    
    // Date formatting: YYYYMMDDHHMMSS
    return date('YmdHis', $timestamp) . $microPart . $randomPart;
}

/* -----------------------------------------------------------------
 *  FILE UPLOAD HANDLER
 * ----------------------------------------------------------------- */
function handleFileUpload($file, $applicationId, $fieldName): ?string
{
    if (!isset($file) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        logError("File upload error for {$fieldName}: " . $file['error']);
        return null;
    }

    // Validate file type (images only)
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes)) {
        logError("Invalid file type for {$fieldName}: {$mimeType}");
        return null;
    }

    // Validate file size (max 5MB)
    if ($file['size'] > 5 * 1024 * 1024) {
        logError("File too large for {$fieldName}: " . $file['size']);
        return null;
    }

    // Create uploads directory if it doesn't exist
    $uploadDir = __DIR__ . '/../uploads/loan_documents/' . $applicationId;
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = $fieldName . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $filepath = $uploadDir . '/' . $filename;

    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        // Return relative path for database storage
        return 'uploads/loan_documents/' . $applicationId . '/' . $filename;
    }

    logError("Failed to move uploaded file for {$fieldName}");
    return null;
}

/* -----------------------------------------------------------------
 *  LOAN APPLICATION MODEL - ENHANCED
 * ----------------------------------------------------------------- */
class LoanApplication
{
    private $conn;
    private $table = 'loan_applications';
    private $extendedTable = 'loan_extended_details';

    public function __construct($db)
    {
        $this->conn = $db;
    }

    public function read($userId, $role, $pfNumber = null, $idNumber = null, $viewingAsLoanee = false): array
    {
        $canSeeAll = in_array($role, ['admin', 'accountant', 'cashier'], true) && !$viewingAsLoanee;
        
        // Get filter from GET request
        $filter = $_GET['filter'] ?? 'all';
        
        try {
            $sql = "SELECT * FROM v_unified_loans";
            $whereClauses = [];
            $params = [];

            // Add Filter Logic
            if ($filter !== 'all') {
                $whereClauses[] = "status = :status";
                $params[':status'] = $filter;
            }

            // Add Role Logic
            if (!$canSeeAll) {
                $whereClauses[] = "user_id = :user_id";
                $params[':user_id'] = $userId;
            }

            // Combine WHERE clauses
            if (!empty($whereClauses)) {
                $sql .= " WHERE " . implode(' AND ', $whereClauses);
            }

            $sql .= " ORDER BY application_date DESC";
            
            $stmt = $this->conn->prepare($sql);
            
            // Bind parameters
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $results;
            
        } catch (PDOException $e) {
            logError('Database error in read(): ' . $e->getMessage());
            return [];
        }
    }

    public function readOne($applicationId, $userId, $role, $pfNumber = null, $idNumber = null, $viewingAsLoanee = false)
    {
        $canSeeAll = in_array($role, ['admin', 'accountant', 'cashier'], true) && !$viewingAsLoanee;
        
        try {
            // FIX: Query v_unified_loans for single record
            if ($canSeeAll) {
                $sql = "SELECT * FROM v_unified_loans WHERE application_id = :id";
                $stmt = $this->conn->prepare($sql);
                $stmt->bindValue(':id', $applicationId, PDO::PARAM_STR);
            } else {
                $sql = "SELECT * FROM v_unified_loans 
                         WHERE application_id = :id AND user_id = :user_id";
                $stmt = $this->conn->prepare($sql);
                $stmt->bindValue(':id', $applicationId, PDO::PARAM_STR);
                $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            }
            
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            logError('Database error in readOne(): ' . $e->getMessage());
            return false;
        }
    }

    public function create($data, $userId): bool
    {
        try {
            $this->conn->beginTransaction();

            // FIX: Use the generateUniqueLoanId helper function
            $applicationId = generateUniqueLoanId();

            // FIX: Robust Bank Name Handling
            // Default to 'Family Bank' if the input doesn't match the enum or is empty
            $bankName = isset($data['bank_name']) ? trim($data['bank_name']) : '';
            if (empty($bankName)) {
                $bankName = 'Family Bank';
            }

            // FIX: Robust PF Number Handling
            // DB is BigInt. Input is string. Must convert numeric only, otherwise null.
            $rawPf = isset($data['pf_number']) ? trim($data['pf_number']) : '';
            $pfNumber = is_numeric($rawPf) ? intval($rawPf) : null;

            // Insert main loan application
            $sql = "INSERT INTO {$this->table} (
                application_id, 
                user_id, 
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
                bank_account_number
            ) VALUES (
                :application_id, :user_id, :full_name, :id_number, :pf_number, :email, 
                :loan_type, :loan_amount, :payment_duration, :payment_frequency, :basic_salary, 
                :employment_type, :contract_period, :contract_start_date, :contract_end_date, 
                :property_location, :property_value, :car_type, :car_value, 
                :bank_name, :bank_branch, :bank_account_number
            )";  
                

            $stmt = $this->conn->prepare($sql);
            
            // Bind Parameters
            $stmt->bindValue(':application_id', $applicationId, PDO::PARAM_STR);
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':full_name', $data['full_name'] ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':id_number', $data['id_number'] ?? '', PDO::PARAM_STR);
            // Bind real values with their correct PDO types. Binding these
            // fields as PARAM_NULL caused valid PF numbers and emails to be
            // saved as NULL in loan_applications.
            if ($pfNumber === null) {
                $stmt->bindValue(':pf_number', null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':pf_number', $pfNumber, PDO::PARAM_INT);
            }

            $email = trim((string)($data['email'] ?? ''));
            $stmt->bindValue(
                ':email',
                $email === '' ? null : $email,
                $email === '' ? PDO::PARAM_NULL : PDO::PARAM_STR
            );
            $stmt->bindValue(':loan_type', $data['loan_type'] ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':loan_amount', $data['loan_amount'], PDO::PARAM_STR);
            $stmt->bindValue(':payment_duration', $data['payment_duration'] ?? 12, PDO::PARAM_INT);
            $stmt->bindValue(':payment_frequency', $data['payment_frequency'] ?? 'monthly', PDO::PARAM_STR);
            $stmt->bindValue(':basic_salary', $data['basic_salary'], PDO::PARAM_STR);
            $stmt->bindValue(':employment_type', $data['employment_type'] ?? 'permanent', PDO::PARAM_STR);
            $stmt->bindValue(':contract_period', $data['contract_period'] ?? null, PDO::PARAM_INT);
            $stmt->bindValue(':contract_start_date', $data['contract_start_date'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':contract_end_date', $data['contract_end_date'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':property_location', $data['property_location'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':property_value', $data['property_value'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':car_type', $data['car_type'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':car_value', $data['car_value'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':bank_name', $bankName, PDO::PARAM_STR);
            $stmt->bindValue(':bank_branch', $data['bank_branch'] ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':bank_account_number', $data['bank_account_number'] ?? '', PDO::PARAM_STR);

            $stmt->execute();

            // Save extended details if provided
            if (!empty($data['extended_details'])) {
                $this->saveExtendedDetails($applicationId, $data['extended_details']);
            }

            // Save to loandetails for backward compatibility
            $this->saveLoanDetails($data);

            $this->conn->commit();
            return true;

        } catch (PDOException $e) {
            $this->conn->rollBack();
            logError('Database error in create(): ' . $e->getMessage());
            return false;
        }
    }

    private function saveExtendedDetails($applicationId, $details): bool
    {
        try {
            $sql = "INSERT INTO {$this->extendedTable} SET
                application_id = :application_id,
                surname = :surname,
                other_names = :other_names,
                po_box = :po_box,
                town_home_telephone = :town_home_telephone,
                mobile_number = :mobile_number,
                email_address = :email_address,
                marital_status = :marital_status,
                current_residence = :current_residence,
                permanent_residence = :permanent_residence,
                province_county = :province_county,
                court_road_residence = :court_road_residence,
                district_road = :district_road,
                date_of_birth = :date_of_birth,
                employer_name = :employer_name,
                work_station = :work_station,
                office_address = :office_address,
                office_telephone = :office_telephone,
                designation = :designation,
                date_employed = :date_employed,
                gross_salary = :gross_salary,
                net_salary = :net_salary,
                id_front_image = :id_front_image,
                id_back_image = :id_back_image,
                payslip_image = :payslip_image,
                kra_pin_image = :kra_pin_image";

            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(':application_id', $applicationId, PDO::PARAM_STR);
            $stmt->bindValue(':surname', $details['surname'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':other_names', $details['other_names'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':po_box', $details['po_box'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':town_home_telephone', $details['town_home_telephone'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':mobile_number', $details['mobile_number'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':email_address', $details['email_address'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':marital_status', $details['marital_status'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':current_residence', $details['current_residence'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':permanent_residence', $details['permanent_residence'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':province_county', $details['province_county'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':court_road_residence', $details['court_road_residence'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':district_road', $details['district_road'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':date_of_birth', $details['date_of_birth'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':employer_name', $details['employer_name'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':work_station', $details['work_station'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':office_address', $details['office_address'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':office_telephone', $details['office_telephone'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':designation', $details['designation'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':date_employed', $details['date_employed'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':gross_salary', $details['gross_salary'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':net_salary', $details['net_salary'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':id_front_image', $details['id_front_image'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':id_back_image', $details['id_back_image'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':payslip_image', $details['payslip_image'] ?? null, PDO::PARAM_STR);
            $stmt->bindValue(':kra_pin_image', $details['kra_pin_image'] ?? null, PDO::PARAM_STR);

            return $stmt->execute();

        } catch (PDOException $e) {
            logError('Error saving extended details: ' . $e->getMessage());
            return false;
        }
    }

    private function saveLoanDetails($data): bool
    {
        // Placeholder for backward compatibility if needed
        // Implementing logic to save to loandetails table if required by the system.
        // As this is often a separate table with different structure or handled by DB triggers,
        // we return true to indicate success without blocking the process.
        return true;
    }

    public function getUserStats($userId): array
    {
        try {
            // FIX: Query Unified View to get stats correctly
            $sql = "SELECT 
                        COUNT(*) as total_applications,
                        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                        SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted
                    FROM v_unified_loans
                    WHERE user_id = :user_id";

            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            return [];
        }
    }

    public function getOverallStats(): array
    {
        try {
            // FIX: Query Unified View to get overall stats correctly
            $sql = "SELECT 
                        COUNT(*) as total_applications,
                        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
                    FROM v_unified_loans";
            
            $stmt = $this->conn->query($sql);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            return [];
        }
    }

    public function update($applicationId, $data, $userRole): bool
{
    try {
        // Only certain roles can update loan status
        if (!in_array($userRole, ['admin', 'accountant', 'cashier'])) {
            logError("Unauthorized update attempt by role: {$userRole}");
            return false;
        }
        
        // Check if the loan exists in the View
        // We check v_unified_loans because the ID could be a legacy one
        $checkSql = "SELECT application_id FROM v_unified_loans WHERE application_id = :application_id";
        $checkStmt = $this->conn->prepare($checkSql);
        $checkStmt->bindValue(':application_id', $applicationId, PDO::PARAM_STR);
        $checkStmt->execute();
        
        if ($checkStmt->rowCount() === 0) {
            logError("Loan not found in unified view: {$applicationId}");
            return false;
        }
        
        // Prepare the update query
        $sql = "UPDATE {$this->table} SET 
                status = :status,
                feedback = :feedback,
                updated_at = NOW()";
        
        // Add accountant_processed_date if status is being set to accepted or forwarded
        if (isset($data['status']) && in_array($data['status'], ['accepted', 'forwarded'])) {
            $sql .= ", accountant_processed_date = NOW()";
        }
        
        // Add dispatch_date if status is being set to dispatched
        if (isset($data['status']) && $data['status'] === 'dispatched') {
            $sql .= ", dispatch_date = NOW()";
        }
        
        $sql .= " WHERE application_id = :application_id";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':application_id', $applicationId, PDO::PARAM_STR);
        $stmt->bindValue(':status', $data['status'], PDO::PARAM_STR);
        $stmt->bindValue(':feedback', $data['feedback'] ?? '', PDO::PARAM_STR);
        
        $result = $stmt->execute();
        
        if ($result) {
            logError("Loan {$applicationId} updated to status: {$data['status']} by {$userRole}");
            
            // Create a notification for the accountant if forwarded
            if (isset($data['status']) && $data['status'] === 'forwarded') {
                $this->createNotification($applicationId, 'forwarded', $data['feedback'] ?? '');
            }
            
            return true;
        }
        
        return false;
        
    } catch (PDOException $e) {
        logError('Database error in update(): ' . $e->getMessage());
        return false;
    }
}

// Add this method to create notifications (Integrated into class scope via anonymous function workaround if needed, but defined here for readability)
// Note: Since createNotification is private, we can't call it directly here. We will call the global helper below.

/* -----------------------------------------------------------------
 *  GLOBAL NOTIFICATION HELPER (Workaround for private method)
 * ----------------------------------------------------------------- */
function createNotification($applicationId, $action, $message): bool
{
    try {
        global $conn; // Use global connection defined at top
        
        // Get loan details from Unified View (handles legacy imports too)
        $sql = "SELECT la.*, u.full_name FROM v_unified_loans la 
                LEFT JOIN users u ON la.user_id = u.user_id 
                WHERE la.application_id = :application_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':application_id', $applicationId, PDO::PARAM_STR);
        $stmt->execute();
        $loan = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$loan) {
            return false;
        }
        
        // Create notification message
        $notificationMessage = '';
        if ($action === 'forwarded') {
            $notificationMessage = "New loan application from {$loan['full_name']} ({$loan['pf_number']}) has been forwarded for your review";
        }
        
        // Insert notification for accountants
        $sql = "INSERT INTO notifications (user_id, message, loan_application_id, created_at) 
                SELECT u.user_id, :message, :application_id, NOW() 
                FROM users u WHERE u.user_role = 'accountant'";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':message', $notificationMessage, PDO::PARAM_STR);
        $stmt->bindValue(':application_id', $applicationId, PDO::PARAM_STR);
        
        return $stmt->execute();
        
    } catch (PDOException $e) {
        logError('Error creating notification: ' . $e->getMessage());
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

    /* ---------- HANDLE FILE UPLOADS FOR MULTIPART ---------- */
    if ($method === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_with_files') {
        // Verify CSRF
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            sendJson(403, ['success' => false, 'message' => 'Invalid CSRF token']);
        }

        $applicationId = $_POST['application_id'] ?? 'APP' . time() . rand(1000, 9999);
        
        // Handle image uploads
        $imageFields = ['id_front_image', 'id_back_image', 'payslip_image', 'kra_pin_image'];
        $uploadedFiles = [];

        foreach ($imageFields as $field) {
            if (isset($_FILES[$field])) {
                $path = handleFileUpload($_FILES[$field], $applicationId, $field);
                if ($path) {
                    $uploadedFiles[$field] = $path;
                }
            }
        }

        // Prepare extended details
        $extendedDetails = array_merge($_POST, $uploadedFiles);

        // Prepare main application data
        $data = [
            'application_id' => $applicationId,
            'full_name' => $_POST['full_name'] ?? '',
            'id_number' => $_POST['id_number'] ?? '',
            'pf_number' => $_POST['pf_number'] ?? '',
            'email' => $_POST['email'] ?? '',
            'loan_type' => $_POST['loan_type'] ?? '',
            'loan_amount' => $_POST['loan_amount'] ?? '',
            'payment_duration' => $_POST['payment_duration'] ?? '',
            'basic_salary' => $_POST['basic_salary'] ?? '',
            'employment_type' => $_POST['employment_type'] ?? '',
            'bank_name' => $_POST['bank_name'] ?? '',
            'bank_branch' => $_POST['bank_branch'] ?? '',
            'bank_account_number' => $_POST['bank_account_number'] ?? '',
            'extended_details' => $extendedDetails
        ];

        $success = $loan->create($data, $currentUserId);

        if ($success) {
            sendJson(201, [
                'success' => true,
                'message' => 'Application created successfully with documents',
                'application_id' => $applicationId
            ]);
        } else {
            sendJson(500, ['success' => false, 'message' => 'Failed to create application']);
        }
    }

    /* ---------- REGULAR JSON REQUESTS ---------- */
    $input = [];
    if (in_array($method, ['POST', 'PUT', 'DELETE'], true) && empty($_FILES)) {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?? [];
        if (!empty($input)) {
            $token = $input['csrf_token'] ?? null;
            if (!validateCSRFToken($token)) {
                sendJson(403, ['success' => false, 'message' => 'Invalid CSRF token']);
            }
        }
    }

    /* ---------- GET ---------- */
    if ($method === 'GET') {
        $applicationId = $_GET['id'] ?? $_GET['application_id'] ?? null;

        if ($applicationId) {
            $loanData = $loan->readOne($applicationId, $currentUserId, $currentUserRole, $currentPfNumber, $currentIdNumber, $viewingAsLoanee);
            if (!$loanData) {
                sendJson(404, ['success' => false, 'message' => 'Loan not found']);
            }
            sendJson(200, ['success' => true, 'data' => $loanData]);
        }

        $list = $loan->read($currentUserId, $currentUserRole, $currentPfNumber, $currentIdNumber, $viewingAsLoanee);
        $stats = ($currentUserRole === 'loanee' || $viewingAsLoanee) 
            ? $loan->getUserStats($currentUserId) 
            : $loan->getOverallStats();

        sendJson(200, ['success' => true, 'data' => $list, 'stats' => $stats]);
    }

    /* ---------- POST ---------- */
    if ($method === 'POST' && empty($_FILES)) {
        $required = ['loan_type', 'loan_amount', 'payment_duration', 'basic_salary'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                sendJson(400, ['success' => false, 'message' => "Missing: {$field}"]);
            }
        }

        // If frontend sends application_id, use it. If not, generate one.
        if (!isset($input['application_id']) || empty($input['application_id'])) {
            $input['application_id'] = generateUniqueLoanId();
        }

        $success = $loan->create($input, $currentUserId);
        sendJson($success ? 201 : 500, [
            'success' => $success,
            'message' => $success ? 'Created successfully' : 'Failed to create'
        ]);
    }

    /* ---------- PUT ---------- */
    if ($method === 'PUT') {
        if (empty($input['application_id']) || empty($input['status'])) {
            sendJson(400, ['success' => false, 'message' => 'Missing required fields']);
        }

        $success = $loan->update($input['application_id'], $input, $currentUserRole);
        sendJson($success ? 200 : 500, [
            'success' => $success,
            'message' => $success ? 'Updated successfully' : 'Failed to update'
        ]);
    }

    sendJson(405, ['success' => false, 'message' => 'Method not allowed']);

} catch (Throwable $e) {
    logError('Error: ' . $e->getMessage());
    sendJson(500, ['success' => false, 'message' => 'Internal server error']);
}
?>
