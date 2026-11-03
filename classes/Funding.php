<?php
/**
 * Funding Class
 * Handles loan funding/disbursement operations
 */
class Funding {
    private $conn;
    private $table = 'funding';
    
    // Properties
    public $funding_id;
    public $loan_id;
    public $amount_funded;
    public $funding_date;
    public $funding_source;
    public $transaction_reference;
    public $disbursed_to_bank;
    public $disbursed_to_branch;
    public $disbursed_to_account;
    public $disbursement_status;
    public $processed_by;
    public $notes;
    public $created_at;
    
    public function __construct($db) {
        $this->conn = $db->getConnection();
    }
    
    /**
     * Create new funding record
     */
    public function create() {
        // First check if loan is approved
        $loanQuery = "SELECT status, loan_amount FROM loan_applications WHERE application_id = :loan_id";
        $loanStmt = $this->conn->prepare($loanQuery);
        $loanStmt->bindParam(':loan_id', $this->loan_id);
        $loanStmt->execute();
        $loan = $loanStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$loan || $loan['status'] !== 'approved') {
            return false; // Loan must be approved before funding
        }
        
        // Check if already funded
        if ($this->isLoanFunded($this->loan_id)) {
            return false; // Already funded
        }
        
        $query = "INSERT INTO " . $this->table . " 
                  SET loan_id = :loan_id,
                      amount_funded = :amount_funded,
                      funding_date = :funding_date,
                      funding_source = :funding_source,
                      transaction_reference = :transaction_reference,
                      disbursed_to_bank = :disbursed_to_bank,
                      disbursed_to_branch = :disbursed_to_branch,
                      disbursed_to_account = :disbursed_to_account,
                      disbursement_status = :disbursement_status,
                      processed_by = :processed_by,
                      notes = :notes,
                      created_at = NOW()";
        
        $stmt = $this->conn->prepare($query);
        
        // Bind values
        $stmt->bindParam(':loan_id', $this->loan_id);
        $stmt->bindParam(':amount_funded', $this->amount_funded);
        $stmt->bindParam(':funding_date', $this->funding_date);
        $stmt->bindParam(':funding_source', $this->funding_source);
        $stmt->bindParam(':transaction_reference', $this->transaction_reference);
        $stmt->bindParam(':disbursed_to_bank', $this->disbursed_to_bank);
        $stmt->bindParam(':disbursed_to_branch', $this->disbursed_to_branch);
        $stmt->bindParam(':disbursed_to_account', $this->disbursed_to_account);
        $stmt->bindParam(':disbursement_status', $this->disbursement_status);
        $stmt->bindParam(':processed_by', $this->processed_by);
        $stmt->bindParam(':notes', $this->notes);
        
        if ($stmt->execute()) {
            $this->funding_id = $this->conn->lastInsertId();
            
            // Update loan status to 'dispatched'
            $this->updateLoanStatusToDispatched($this->loan_id);
            
            // Generate payment schedule if not exists
            $this->generatePaymentScheduleIfNeeded($this->loan_id);
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if loan is already funded
     */
    public function isLoanFunded($loanId) {
        $query = "SELECT COUNT(*) as count FROM " . $this->table . " WHERE loan_id = :loan_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':loan_id', $loanId);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['count'] > 0;
    }
    
    /**
     * Update loan status to dispatched after funding
     */
    private function updateLoanStatusToDispatched($loanId) {
        $query = "UPDATE loan_applications 
                  SET status = 'dispatched',
                      feedback = 'Funds have been disbursed to your bank account.'
                  WHERE application_id = :loan_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':loan_id', $loanId);
        return $stmt->execute();
    }
    
    /**
     * Generate payment schedule if it doesn't exist
     */
    private function generatePaymentScheduleIfNeeded($loanId) {
        require_once __DIR__ . '/classes/PaymentSchedule.php';
        $scheduleObj = new PaymentSchedule(new stdClass());
        $scheduleObj->$conn = $this->conn;
        
        // Check if schedule exists
        $checkQuery = "SELECT COUNT(*) as count FROM payment_schedule WHERE loan_id = :loan_id";
        $checkStmt = $this->conn->prepare($checkQuery);
        $checkStmt->bindParam(':loan_id', $loanId);
        $checkStmt->execute();
        $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['count'] == 0) {
            $scheduleObj->generateSchedule($loanId);
        }
    }
    
    /**
     * Get funding record by loan ID
     */
    public function getByLoanId($loanId) {
        $query = "SELECT * FROM " . $this->table . " WHERE loan_id = :loan_id LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':loan_id', $loanId);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get funding record by ID
     */
    public function getById($id) {
        $query = "SELECT * FROM " . $this->table . " WHERE funding_id = :id LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get all funding records
     */
    public function getAllFunding() {
        $query = "SELECT f.*, 
                         la.pf_number, 
                         la.loan_type, 
                         la.loan_amount,
                         la.bank_name,
                         la.bank_branch,
                         la.bank_account_number
                  FROM " . $this->table . " f
                  LEFT JOIN loan_applications la ON f.loan_id = la.application_id
                  ORDER BY f.funding_date DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get approved loans awaiting funding
     */
    public function getApprovedLoansAwaitingFunding() {
        $query = "SELECT la.*
                  FROM loan_applications la
                  LEFT JOIN " . $this->table . " f ON la.application_id = f.loan_id
                  WHERE la.status = 'approved'
                  AND f.funding_id IS NULL
                  ORDER BY la.application_date ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Update funding record
     */
    public function update() {
        $query = "UPDATE " . $this->table . " 
                  SET amount_funded = :amount_funded,
                      funding_date = :funding_date,
                      funding_source = :funding_source,
                      transaction_reference = :transaction_reference,
                      disbursed_to_bank = :disbursed_to_bank,
                      disbursed_to_branch = :disbursed_to_branch,
                      disbursed_to_account = :disbursed_to_account,
                      disbursement_status = :disbursement_status,
                      notes = :notes
                  WHERE funding_id = :funding_id";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':amount_funded', $this->amount_funded);
        $stmt->bindParam(':funding_date', $this->funding_date);
        $stmt->bindParam(':funding_source', $this->funding_source);
        $stmt->bindParam(':transaction_reference', $this->transaction_reference);
        $stmt->bindParam(':disbursed_to_bank', $this->disbursed_to_bank);
        $stmt->bindParam(':disbursed_to_branch', $this->disbursed_to_branch);
        $stmt->bindParam(':disbursed_to_account', $this->disbursed_to_account);
        $stmt->bindParam(':disbursement_status', $this->disbursement_status);
        $stmt->bindParam(':notes', $this->notes);
        $stmt->bindParam(':funding_id', $this->funding_id);
        
        return $stmt->execute();
    }
    
    /**
     * Delete funding record (and revert loan status)
     */
    public function delete($id) {
        // Get loan ID before deleting
        $funding = $this->getById($id);
        if (!$funding) {
            return false;
        }
        
        // Delete funding record
        $query = "DELETE FROM " . $this->table . " WHERE funding_id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        
        if ($stmt->execute()) {
            // Revert loan status back to approved
            $updateQuery = "UPDATE loan_applications 
                           SET status = 'approved',
                               feedback = 'Awaiting funding dispatch.'
                           WHERE application_id = :loan_id";
            $updateStmt = $this->conn->prepare($updateQuery);
            $updateStmt->bindParam(':loan_id', $funding['loan_id']);
            $updateStmt->execute();
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Get total amount funded
     */
    public function getTotalAmountFunded() {
        $query = "SELECT COALESCE(SUM(amount_funded), 0) as total FROM " . $this->table;
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['total'];
    }
    
    /**
     * Get funding statistics
     */
    public function getFundingStats() {
        $query = "SELECT 
                    COUNT(*) as total_fundings,
                    SUM(amount_funded) as total_amount,
                    AVG(amount_funded) as average_amount,
                    COUNT(CASE WHEN disbursement_status = 'completed' THEN 1 END) as completed_count,
                    COUNT(CASE WHEN disbursement_status = 'pending' THEN 1 END) as pending_count
                  FROM " . $this->table;
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get funding records by date range
     */
    public function getByDateRange($startDate, $endDate) {
        $query = "SELECT f.*, 
                         la.pf_number, 
                         la.loan_type, 
                         la.loan_amount
                  FROM " . $this->table . " f
                  LEFT JOIN loan_applications la ON f.loan_id = la.application_id
                  WHERE f.funding_date BETWEEN :start_date AND :end_date
                  ORDER BY f.funding_date DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':start_date', $startDate);
        $stmt->bindParam(':end_date', $endDate);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Validate funding data before processing
     */
    public function validate() {
        $errors = [];
        
        if (empty($this->loan_id)) {
            $errors[] = "Loan ID is required";
        }
        
        if (empty($this->amount_funded) || $this->amount_funded <= 0) {
            $errors[] = "Valid funding amount is required";
        }
        
        if (empty($this->funding_date)) {
            $errors[] = "Funding date is required";
        }
        
        if (empty($this->disbursed_to_bank)) {
            $errors[] = "Disbursement bank is required";
        }
        
        if (empty($this->disbursed_to_account)) {
            $errors[] = "Disbursement account number is required";
        }
        
        // Validate that amount matches loan amount
        if (!empty($this->loan_id)) {
            $loanQuery = "SELECT loan_amount FROM loan_applications WHERE application_id = :loan_id";
            $loanStmt = $this->conn->prepare($loanQuery);
            $loanStmt->bindParam(':loan_id', $this->loan_id);
            $loanStmt->execute();
            $loan = $loanStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($loan && $this->amount_funded != $loan['loan_amount']) {
                $errors[] = "Funded amount must match approved loan amount";
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
}