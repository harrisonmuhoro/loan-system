<?php
/**
 * Helper function to create cashier notifications
 * 
 * @param PDO $conn Database connection
 * @param int $cashier_id Cashier ID to notify
 * @param int|null $loan_application_id Related loan application ID
 * @param string $message Notification message
 * @return bool Success status
 */
function createCashierNotification($conn, $cashier_id, $loan_application_id, $message) {
    try {
        $query = "INSERT INTO cashier_notifications 
                  (cashier_id, loan_application_id, message, is_read, created_at) 
                  VALUES (:cashier_id, :loan_application_id, :message, 0, NOW())";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':cashier_id', $cashier_id, PDO::PARAM_INT);
        $stmt->bindParam(':loan_application_id', $loan_application_id, PDO::PARAM_INT);
        $stmt->bindParam(':message', $message, PDO::PARAM_STR);
        
        return $stmt->execute();
    } catch (Exception $e) {
        error_log("Error creating cashier notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Notify all cashiers about a loan event
 */
function notifyAllCashiers($conn, $loan_application_id, $message) {
    try {
        $query = "SELECT id FROM cashiers WHERE is_active = 1";
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $cashiers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($cashiers as $cashier) {
            createCashierNotification($conn, $cashier['id'], $loan_application_id, $message);
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Error notifying cashiers: " . $e->getMessage());
        return false;
    }
}
?>