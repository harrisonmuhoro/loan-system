<?php
/*  Loan Application Model - Updated for your exact schema  */

class LoanApplication
{
    private $conn;
    private $table = 'loan_applications'; // Fixed table name

    public function __construct($db)
    {
        $this->conn = $db;
    }

    public function read($userId, $role, $pfNumber = null, $idNumber = null): array
    {
        $canSeeAll = in_array($role, ['admin', 'accountant', 'cashier'], true);
        
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
                // For loanees, fetch by user_id OR pf_number OR id_number to catch all
                $sql = "SELECT 
                            la.*,
                            COALESCE(ld.property_location, la.property_location) as property_location,
                            COALESCE(ld.property_value, la.property_value) as property_value,
                            COALESCE(ld.car_model, la.car_type) as car_type,
                            COALESCE(ld.car_value, la.car_value) as car_value
                        FROM {$this->table} la
                        LEFT JOIN loandetails ld ON la.application_id = ld.application_id
                        WHERE la.user_id = :user_id 
                            OR la.pf_number = :pf_number 
                            OR la.id_number = :id_number
                        ORDER BY la.application_date DESC";
                $stmt = $this->conn->prepare($sql);
                $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
                $stmt->bindValue(':pf_number', $pfNumber ?? '', PDO::PARAM_STR);
                $stmt->bindValue(':id_number', $idNumber ?? '', PDO::PARAM_STR);
                $stmt->execute();
                
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Update any mismatched user_ids to the current one
                foreach ($results as $loan) {
                    if ($loan['user_id'] != $userId) {
                        $updateSql = "UPDATE {$this->table} SET user_id = :user_id WHERE application_id = :application_id";
                        $updateStmt = $this->conn->prepare($updateSql);
                        $updateStmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
                        $updateStmt->bindValue(':application_id', $loan['application_id'], PDO::PARAM_STR);
                        $updateStmt->execute();
                    }
                }
            }          
            
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
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
            
            return $results;
            
        } catch (PDOException $e) {
            error_log('Database error in read(): ' . $e->getMessage());
            return [];
        }
    }
    
    public function readOne($applicationId, $userId, $role, $pfNumber = null, $idNumber = null)
    {
        $canSeeAll = in_array($role, ['admin', 'accountant', 'cashier'], true);
        
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
                $sql = "SELECT 
                            la.*,
                            COALESCE(ld.property_location, la.property_location) as property_location,
                            COALESCE(ld.property_value, la.property_value) as property_value,
                            COALESCE(ld.car_model, la.car_type) as car_type,
                            COALESCE(ld.car_value, la.car_value) as car_value
                        FROM {$this->table} la
                        LEFT JOIN loandetails ld ON la.application_id = ld.application_id
                        WHERE la.application_id = :id AND (la.user_id = :user_id OR la.pf_number = :pf_number OR la.id_number = :id_number)";
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
    
    /* ---------- CREATE (with user_id auto-assigned) ---------- */
    public function create(array $data, int $userId): bool
    {
        // Match your exact column names and types
        $allowed = [
            'application_id'        => PDO::PARAM_STR,
            'user_id'               => PDO::PARAM_INT,
            'full_name'             => PDO::PARAM_STR,
            'id_number'             => PDO::PARAM_STR,
            'pf_number'             => PDO::PARAM_STR,  // Fixed: Changed from INT to STR
            'email'                 => PDO::PARAM_STR,
            'loan_type'             => PDO::PARAM_STR,  // enum('mortgage','car')
            'loan_amount'           => PDO::PARAM_STR,  // decimal
            'payment_duration'      => PDO::PARAM_INT,
            'payment_frequency'     => PDO::PARAM_STR,  // enum('monthly')
            'basic_salary'          => PDO::PARAM_STR,  // decimal
            'employment_type'       => PDO::PARAM_STR,  // enum('permanent','contracted')
            'contract_period'       => PDO::PARAM_INT,
            'contract_start_date'   => PDO::PARAM_STR,
            'contract_end_date'     => PDO::PARAM_STR,
            'property_location'     => PDO::PARAM_STR,
            'property_value'        => PDO::PARAM_STR,  // decimal
            'car_type'              => PDO::PARAM_STR,
            'car_value'             => PDO::PARAM_STR,  // decimal
            'bank_name'             => PDO::PARAM_STR,  // enum('Family Bank')
            'bank_branch'           => PDO::PARAM_STR,
            'bank_account_number'   => PDO::PARAM_STR,
        ];

        // Force the user_id to be the logged-in user
        $data['user_id'] = $userId;

        $set = [];
        $params = [];
        $types  = [];

        foreach ($allowed as $col => $type) {
            if (array_key_exists($col, $data) && $data[$col] !== null && $data[$col] !== '') {
                $set[]               = "$col = :$col";
                $params[":$col"]     = $data[$col];
                $types[":$col"]      = $type;
            }
        }

        if (!$set) {
            return false;
        }

        // application_date is auto-set by CURRENT_TIMESTAMP
        $sql = "INSERT INTO {$this->table} SET " . implode(', ', $set);
        $stmt = $this->conn->prepare($sql);

        foreach ($params as $ph => $val) {
            $stmt->bindValue($ph, $val, $types[$ph]);
        }

        return $stmt->execute();
    }
    
    /* ---------- UPDATE STATUS (role-based permissions) ---------- */
    public function updateStatus(
        string $applicationId, 
        string $status, 
        ?string $feedback = '', 
        ?int $userId = null,
        ?string $role = null
    ): bool {
        // Only staff can update status
        $canUpdate = in_array($role, ['admin', 'accountant', 'cashier'], true);
        
        if (!$canUpdate) {
            error_log("User ID {$userId} (role: {$role}) attempted to update status - denied");
            return false;
        }
        
        // Determine which processed date to update based on role
        $processedDateField = null;
        switch($role) {
            case 'cashier':
                $processedDateField = 'cashier_processed_date';
                break;
            case 'accountant':
                $processedDateField = 'accountant_processed_date';
                break;
            case 'admin':
                $processedDateField = 'cashier_processed_date'; // or both
                break;
        }
        
        $sql = "UPDATE {$this->table}
                SET status = :status,
                    feedback = :feedback";
        
        if ($processedDateField) {
            $sql .= ", {$processedDateField} = NOW()";
        }
        
        // Update dispatch_date when status changes to 'dispatched'
        if ($status === 'dispatched') {
            $sql .= ", dispatch_date = NOW()";
        }
        
        // Update closed_date when status changes to 'closed'
        if ($status === 'closed') {
            $sql .= ", closed_date = NOW()";
        }
        
        $sql .= " WHERE application_id = :id";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':id', $applicationId, PDO::PARAM_STR);
        $stmt->bindValue(':status', $status, PDO::PARAM_STR);
        $stmt->bindValue(':feedback', $feedback ?? '', PDO::PARAM_STR);

        return $stmt->execute();
    }
    
    /* ---------- DELETE (restricted permissions) ---------- */
    public function delete(string $applicationId, int $userId, ?string $role = null): bool
    {
        // Only admins can delete
        if ($role !== 'admin') {
            error_log("User ID {$userId} (role: {$role}) attempted to delete loan - denied");
            return false;
        }
        
        $sql = "DELETE FROM {$this->table} WHERE application_id = :id";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':id', $applicationId, PDO::PARAM_STR);
        
        return $stmt->execute();
    }
    
    /* ---------- USER CAN CANCEL (pending applications only) ---------- */
    public function cancelApplication(string $applicationId, int $userId): bool
    {
        // Users can only cancel their own pending applications
        $sql = "UPDATE {$this->table} 
                SET status = 'returned',
                    feedback = 'Cancelled by applicant'
                WHERE application_id = :id 
                AND user_id = :user_id 
                AND status = 'pending'";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':id', $applicationId, PDO::PARAM_STR);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        
        return $stmt->execute() && $stmt->rowCount() > 0;
    }
    
    /* ---------- GET USER STATISTICS ---------- */
    public function getUserStats(int $userId): array
    {
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
    }
    
    /* ---------- GET OVERALL STATISTICS (for staff) ---------- */
    public function getOverallStats(): array
    {
        $sql = "SELECT 
                    COUNT(*) as total_applications,
                    COUNT(DISTINCT user_id) as total_applicants,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'forwarded' THEN 1 ELSE 0 END) as forwarded,
                    SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted,
                    SUM(CASE WHEN status = 'dispatched' THEN 1 ELSE 0 END) as dispatched,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                    SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END) as returned,
                    SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed,
                    SUM(loan_amount) as total_loan_amount,
                    SUM(CASE WHEN status = 'active' 
                        THEN loan_amount ELSE 0 END) as active_loan_amount,
                    SUM(CASE WHEN loan_type = 'mortgage' THEN 1 ELSE 0 END) as mortgage_count,
                    SUM(CASE WHEN loan_type = 'car' THEN 1 ELSE 0 END) as car_count
                FROM {$this->table}";
        
        $stmt = $this->conn->query($sql);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    
    /* ---------- CHECK IF USER EXISTS AND MATCHES ---------- */
    public function verifyUserOwnership(string $applicationId, int $userId): bool
    {
        $sql = "SELECT id FROM {$this->table} 
                WHERE application_id = :id AND user_id = :user_id";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':id', $applicationId, PDO::PARAM_STR);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        
        return (bool)$stmt->fetch();
    }
}