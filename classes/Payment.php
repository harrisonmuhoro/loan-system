<?php
/**
 * Payment Class
 * Handles payment recording and tracking
 */
class Payment {
    private $conn;
    private $table = 'payments';
    
    // Properties
    public $payment_id;
    public $loan_id;
    public $amount;
    public $payment_date;
    public $payment_source;
    public $transaction_reference;
    public $principal_amount;
    public $interest_amount;
    public $balance_after;
    public $recorded_by;
    public $notes;
    public $created_at;
    
    public function __construct($db) {
        $this->conn = $db->getConnection();
    }
    
    /**
     * Record a new payment
     */
    public function create() {
        $query = "INSERT INTO " . $this->table . " 
                  SET loan_id = :loan_id,
                      amount = :amount,
                      payment_date = :payment_date,
                      payment_source = :payment_source,
                      transaction_reference = :transaction_reference,
                      principal_amount = :principal_amount,
                      interest_amount = :interest_amount,
                      balance_after = :balance_after,
                      recorded_by = :recorded_by,
                      notes = :notes,
                      created_at = NOW()";
        
        $stmt = $this->conn->prepare($query);
        
        // Bind values
        $stmt->bindParam(':loan_id', $this->loan_id);
        $stmt->bindParam(':amount', $this->amount);
        $stmt->bindParam(':payment_date', $this->payment_date);
        $stmt->bindParam(':payment_source', $this->payment_source);
        $stmt->bindParam(':transaction_reference', $this->transaction_reference);
        $stmt->bindParam(':principal_amount', $this->principal_amount);
        $stmt->bindParam(':interest_amount', $this->interest_amount);
        $stmt->bindParam(':balance_after', $this->balance_after);
        $stmt->bindParam(':recorded_by', $this->recorded_by);
        $stmt->bindParam(':notes', $this->notes);
        
        if ($stmt->execute()) {
            $this->payment_id = $this->conn->lastInsertId();
            return true;
        }
        
        return false;
    }
    
    /**
     * Get all payments for a specific loan
     */
    public function getPaymentsByLoanId($loan_id) {
        $query = "SELECT * FROM " . $this->table . " 
                  WHERE loan_id = :loan_id 
                  ORDER BY payment_date ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':loan_id', $loan_id);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get payment by ID
     */
    public function getById($id) {
        $query = "SELECT * FROM " . $this->table . " WHERE payment_id = :id LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Calculate payment breakdown (principal vs interest)
     */
    public function calculatePaymentBreakdown($loanId, $paymentAmount) {
        // Get loan details
        $loanQuery = "SELECT loan_amount, payment_duration_years FROM loan_applications WHERE application_id = :loan_id";
        $loanStmt = $this->conn->prepare($loanQuery);
        $loanStmt->bindParam(':loan_id', $loanId);
        $loanStmt->execute();
        $loan = $loanStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$loan) {
            return null;
        }
        
        // Get current balance (original amount minus previous principal payments)
        $paymentsQuery = "SELECT COALESCE(SUM(principal_amount), 0) as total_principal 
                         FROM " . $this->table . " 
                         WHERE loan_id = :loan_id";
        $paymentsStmt = $this->conn->prepare($paymentsQuery);
        $paymentsStmt->bindParam(':loan_id', $loanId);
        $paymentsStmt->execute();
        $payments = $paymentsStmt->fetch(PDO::FETCH_ASSOC);
        
        $currentBalance = $loan['loan_amount'] - $payments['total_principal'];
        
        // Get interest rate from settings
        $settingsQuery = "SELECT value FROM settings WHERE setting_key = 'interest_rate'";
        $settingsStmt = $this->conn->prepare($settingsQuery);
        $settingsStmt->execute();
        $settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);
        $interestRate = $settings ? floatval($settings['value']) : 3.0;
        
        // Calculate interest portion (simple monthly interest on remaining balance)
        $monthlyRate = ($interestRate / 100) / 12;
        $interestAmount = ceil($currentBalance * $monthlyRate);
        
        // Remaining goes to principal
        $principalAmount = $paymentAmount - $interestAmount;
        
        // Ensure principal doesn't exceed remaining balance
        if ($principalAmount > $currentBalance) {
            $principalAmount = $currentBalance;
            $interestAmount = $paymentAmount - $principalAmount;
        }
        
        $balanceAfter = max(0, $currentBalance - $principalAmount);
        
        return [
            'principal_amount' => round($principalAmount),
            'interest_amount' => round($interestAmount),
            'balance_after' => round($balanceAfter),
            'current_balance' => round($currentBalance)
        ];
    }
    
    /**
     * Get total amount paid for a loan
     */
    public function getTotalPaidByLoan($loan_id) {
        $query = "SELECT COALESCE(SUM(amount), 0) as total 
                  FROM " . $this->table . " 
                  WHERE loan_id = :loan_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':loan_id', $loan_id);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $row['total'];
    }
    
    /**
     * Get remaining balance for a loan
     */
    public function getRemainingBalance($loan_id) {
        // Get original loan amount
        $loanQuery = "SELECT loan_amount FROM loan_applications WHERE application_id = :loan_id";
        $loanStmt = $this->conn->prepare($loanQuery);
        $loanStmt->bindParam(':loan_id', $loan_id);
        $loanStmt->execute();
        $loan = $loanStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$loan) {
            return 0;
        }
        
        // Get total principal paid
        $paymentsQuery = "SELECT COALESCE(SUM(principal_amount), 0) as total_principal 
                         FROM " . $this->table . " 
                         WHERE loan_id = :loan_id";
        $paymentsStmt = $this->conn->prepare($paymentsQuery);
        $paymentsStmt->bindParam(':loan_id', $loan_id);
        $paymentsStmt->execute();
        $payments = $paymentsStmt->fetch(PDO::FETCH_ASSOC);
        
        return max(0, $loan['loan_amount'] - $payments['total_principal']);
    }
    
    /**
     * Update payment
     */
    public function update() {
        $query = "UPDATE " . $this->table . " 
                  SET amount = :amount,
                      payment_date = :payment_date,
                      payment_source = :payment_source,
                      transaction_reference = :transaction_reference,
                      principal_amount = :principal_amount,
                      interest_amount = :interest_amount,
                      balance_after = :balance_after,
                      notes = :notes
                  WHERE payment_id = :payment_id";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':amount', $this->amount);
        $stmt->bindParam(':payment_date', $this->payment_date);
        $stmt->bindParam(':payment_source', $this->payment_source);
        $stmt->bindParam(':transaction_reference', $this->transaction_reference);
        $stmt->bindParam(':principal_amount', $this->principal_amount);
        $stmt->bindParam(':interest_amount', $this->interest_amount);
        $stmt->bindParam(':balance_after', $this->balance_after);
        $stmt->bindParam(':notes', $this->notes);
        $stmt->bindParam(':payment_id', $this->payment_id);
        
        return $stmt->execute();
    }
    
    /**
     * Delete payment
     */
    public function delete($id) {
        $query = "DELETE FROM " . $this->table . " WHERE payment_id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        return $stmt->execute();
    }
    
    /**
     * Get all payments (for admin)
     */
    public function getAllPayments() {
        $query = "SELECT p.*, la.pf_number, la.loan_type, la.loan_amount
                  FROM " . $this->table . " p
                  LEFT JOIN loan_applications la ON p.loan_id = la.application_id
                  ORDER BY p.payment_date DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}