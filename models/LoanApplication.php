<?php
class LoanApplication {
    private $conn;
    private $table_name = "loan_applications";
    
    public $application_id;
    public $user_id;
    public $loan_type;
    public $loan_amount;
    public $payment_duration_years;
    public $basic_salary;
    public $payment_start_date;
    public $first_deduction_date;
    public $employment_type;
    public $contract_period_months;
    public $contract_start_date;
    public $contract_end_date;
    public $bank_name;
    public $bank_branch;
    public $bank_account_number;
    public $status;
    public $feedback;
    public $application_date;
    public $accountant_processed_date;
    public $dispatch_date;
    public $last_updated;
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    public function create() {
        $query = "INSERT INTO " . $this->table_name . "
                SET user_id=:user_id, loan_type=:loan_type, loan_amount=:loan_amount,
                    payment_duration_years=:payment_duration_years, basic_salary=:basic_salary,
                    payment_start_date=:payment_start_date, first_deduction_date=:first_deduction_date,
                    employment_type=:employment_type, contract_period_months=:contract_period_months,
                    contract_start_date=:contract_start_date, contract_end_date=:contract_end_date,
                    bank_name=:bank_name, bank_branch=:bank_branch, bank_account_number=:bank_account_number,
                    status=:status, application_id=:application_id";
        
        $stmt = $this->conn->prepare($query);
        
        $this->application_id = uniqid('app_', true);
        $this->status = 'pending';
        
        $stmt->bindParam(":user_id", $this->user_id);
        $stmt->bindParam(":loan_type", $this->loan_type);
        $stmt->bindParam(":loan_amount", $this->loan_amount);
        $stmt->bindParam(":payment_duration_years", $this->payment_duration_years);
        $stmt->bindParam(":basic_salary", $this->basic_salary);
        $stmt->bindParam(":payment_start_date", $this->payment_start_date);
        $stmt->bindParam(":first_deduction_date", $this->first_deduction_date);
        $stmt->bindParam(":employment_type", $this->employment_type);
        $stmt->bindParam(":contract_period_months", $this->contract_period_months);
        $stmt->bindParam(":contract_start_date", $this->contract_start_date);
        $stmt->bindParam(":contract_end_date", $this->contract_end_date);
        $stmt->bindParam(":bank_name", $this->bank_name);
        $stmt->bindParam(":bank_branch", $this->bank_branch);
        $stmt->bindParam(":bank_account_number", $this->bank_account_number);
        $stmt->bindParam(":status", $this->status);
        $stmt->bindParam(":application_id", $this->application_id);
        
        return $stmt->execute();
    }
    
    public function getAll($status = null, $limit = null, $offset = 0) {
        $query = "SELECT la.*, u.full_name, u.id_number, u.pf_number
                 FROM " . $this->table_name . " la
                 JOIN Users u ON la.user_id = u.user_id";
        
        if ($status) {
            $query .= " WHERE la.status = :status";
        }
        
        $query .= " ORDER BY la.application_date DESC";
        
        if ($limit) {
            $query .= " LIMIT :limit OFFSET :offset";
        }
        
        $stmt = $this->conn->prepare($query);
        
        if ($status) {
            $stmt->bindParam(":status", $status);
        }
        
        if ($limit) {
            $stmt->bindParam(":limit", $limit, PDO::PARAM_INT);
            $stmt->bindParam(":offset", $offset, PDO::PARAM_INT);
        }
        
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getById($application_id) {
        $query = "SELECT la.*, u.full_name, u.id_number, u.pf_number, u.email
                 FROM " . $this->table_name . " la
                 JOIN Users u ON la.user_id = u.user_id
                 WHERE la.application_id = :application_id LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":application_id", $application_id);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function getByUserId($user_id) {
        $query = "SELECT * FROM " . $this->table_name . " 
                 WHERE user_id = :user_id 
                 ORDER BY application_date DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":user_id", $user_id);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function updateStatus($status, $feedback = null) {
        $query = "UPDATE " . $this->table_name . "
                SET status=:status, feedback=:feedback, last_updated=NOW()";
        
        if ($status === 'accepted') {
            $query .= ", accountant_processed_date=NOW()";
        } elseif ($status === 'dispatched') {
            $query .= ", dispatch_date=NOW()";
        }
        
        $query .= " WHERE application_id=:application_id";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(":status", $status);
        $stmt->bindParam(":feedback", $feedback);
        $stmt->bindParam(":application_id", $this->application_id);
        
        return $stmt->execute();
    }
    
    public function getStats() {
        $query = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'forwarded' THEN 1 ELSE 0 END) as forwarded,
                    SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted,
                    SUM(CASE WHEN status = 'dispatched' THEN 1 ELSE 0 END) as dispatched,
                    SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END) as returned,
                    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
                 FROM " . $this->table_name;
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
?>