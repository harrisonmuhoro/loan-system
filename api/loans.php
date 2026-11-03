<?php
// api/loans.php - ENHANCED VERSION WITH EXTENDED DETAILS & IMAGE UPLOAD

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

logError("Request from user_id: {$currentUserId}, role: {$currentUserRole}, PF: {$currentPfNumber}");

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
                        ORDER BY la.application_date DESC";
                $stmt = $this->conn->prepare($sql);
                $stmt->execute();
            } else {
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
                        )
                        ORDER BY la.application_date DESC";
                
                $stmt = $this->conn->prepare($sql);
                $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
                $stmt->bindValue(':pf_number', $pfNumber ?? '', PDO::PARAM_STR);
                $stmt->bindValue(':id_number', $idNumber ?? '', PDO::PARAM_STR);
                $stmt->execute();
            }
            
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
            if ($canSeeAll) {
                $sql = "SELECT 
                            la.*,
                            led.*,
                            u.full_name as applicant_name,
                            u.email as applicant_email,
                            u.pf_number as applicant_pf
                        FROM {$this->table} la
                        LEFT JOIN users u ON la.user_id = u.user_id
                        LEFT JOIN {$this->extendedTable} led ON la.application_id = led.application_id
                        WHERE la.application_id = :id";
                $stmt = $this->conn->prepare($sql);
                $stmt->bindValue(':id', $applicationId, PDO::PARAM_STR);
            } else {
                $sql = "SELECT 
                            la.*,
                            led.*
                        FROM {$this->table} la
                        LEFT JOIN {$this->extendedTable} led ON la.application_id = led.application_id
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

            // Insert main loan application
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

            $stmt->execute();

            // Save extended details if provided
            if (!empty($data['extended_details'])) {
                $this->saveExtendedDetails($data['application_id'], $data['extended_details']);
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
        // ... existing saveLoanDetails code remains the same ...
        return true;
    }

    // ... rest of existing methods (getUserStats, getOverallStats, update, etc.) ...

    public function getUserStats($userId): array
    {
        try {
            $sql = "SELECT 
                        COUNT(*) as total_applications,
                        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                        SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted
                    FROM {$this->table}
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
            $sql = "SELECT 
                        COUNT(*) as total_applications,
                        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
                    FROM {$this->table}";
            $stmt = $this->conn->query($sql);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            return [];
        }
    }

    public function update($applicationId, $data, $userRole): bool
    {
        // ... existing update code ...
        return true;
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
            verifyCsrf($input);
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