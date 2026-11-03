<?php
/**
 * PaymentSchedule Class
 * Generates and manages payment schedules
 */
class PaymentSchedule {
    private $conn;
    private $table = 'payment_schedules';
    
    public function __construct($db) {
        $this->conn = $db->getConnection();
    }
    
    /**
     * Generate payment schedule for a loan
     */
    public function generateSchedule($loanId) {
        // Get loan details
        $loanQuery = "SELECT * FROM loan_applications WHERE application_id = :loan_id";
        $loanStmt = $this->conn->prepare($loanQuery);
        $loanStmt->bindParam(':loan_id', $loanId);
        $loanStmt->execute();
        $loan = $loanStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$loan) {
            return false;
        }
        
        // Get interest rate from settings
        $settingsQuery = "SELECT value FROM settings WHERE setting_key = 'interest_rate'";
        $settingsStmt = $this->conn->prepare($settingsQuery);
        $settingsStmt->execute();
        $settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);
        $interestRate = $settings ? floatval($settings['value']) : 3.0;
        
        $principal = $loan['loan_amount'];
        $years = $loan['payment_duration_years'];
        $monthlyRate = ($interestRate / 100) / 12;
        $totalMonths = round($years * 12);
        
        // Calculate monthly payment
        if ($monthlyRate == 0) {
            $monthlyPayment = ceil($principal / $totalMonths);
        } else {
            $monthlyPayment = ceil($principal * ($monthlyRate * pow(1 + $monthlyRate, $totalMonths)) / 
                                   (pow(1 + $monthlyRate, $totalMonths) - 1));
        }
        
        // Delete existing schedule if any
        $this->deleteScheduleByLoanId($loanId);
        
        // Generate schedule
        $balance = $principal;
        $startDate = $loan['payment_start_date'] ? new DateTime($loan['payment_start_date']) : new DateTime();
        
        for ($i = 1; $i <= $totalMonths; $i++) {
            $interestPayment = round($balance * $monthlyRate);
            $principalPayment = $monthlyPayment - $interestPayment;
            
            // Last payment adjustment
            if ($i === $totalMonths) {
                $principalPayment = $balance;
                $monthlyPayment = $principalPayment + $interestPayment;
                $balance = 0;
            } else {
                $balance = max(0, $balance - $principalPayment);
            }
            
            // Calculate due date
            $dueDate = clone $startDate;
            $dueDate->modify("+{$i} months");
            
            // Insert schedule entry
            $insertQuery = "INSERT INTO " . $this->table . " 
                           (loan_id, payment_number, due_date, expected_amount, 
                            expected_principal, expected_interest, expected_balance)
                           VALUES 
                           (:loan_id, :payment_number, :due_date, :expected_amount,
                            :expected_principal, :expected_interest, :expected_balance)";
            
            $insertStmt = $this->conn->prepare($insertQuery);
            $insertStmt->bindParam(':loan_id', $loanId);
            $insertStmt->bindParam(':payment_number', $i);
            $insertStmt->bindValue(':due_date', $dueDate->format('Y-m-d'));
            $insertStmt->bindParam(':expected_amount', $monthlyPayment);
            $insertStmt->bindParam(':expected_principal', $principalPayment);
            $insertStmt->bindParam(':expected_interest', $interestPayment);
            $insertStmt->bindParam(':expected_balance', $balance);
            $insertStmt->execute();
        }
        
        return true;
    }
    
    /**
     * Get schedule by loan ID
     */
    public function getScheduleByLoanId($loanId) {
        $query = "SELECT * FROM " . $this->table . " 
                  WHERE loan_id = :loan_id 
                  ORDER BY payment_number ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':loan_id', $loanId);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Delete schedule by loan ID
     */
    public function deleteScheduleByLoanId($loanId) {
        $query = "DELETE FROM " . $this->table . " WHERE loan_id = :loan_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':loan_id', $loanId);
        return $stmt->execute();
    }
    
    /**
     * Get next due payment for a loan
     */
    public function getNextDuePayment($loanId) {
        $query = "SELECT * FROM " . $this->table . " 
                  WHERE loan_id = :loan_id 
                  AND due_date >= CURDATE()
                  ORDER BY payment_number ASC
                  LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':loan_id', $loanId);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get overdue payments for a loan
     */
    public function getOverduePayments($loanId) {
        $query = "SELECT ps.* 
                  FROM " . $this->table . " ps
                  LEFT JOIN payments p ON ps.loan_id = p.loan_id AND ps.payment_number = p.payment_number
                  WHERE ps.loan_id = :loan_id 
                  AND ps.due_date < CURDATE()
                  AND p.payment_id IS NULL
                  ORDER BY ps.payment_number ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':loan_id', $loanId);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}