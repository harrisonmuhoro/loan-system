<?php
// fix_loan_user_ids.php
require_once 'config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    // Get all users
    $usersQuery = "SELECT user_id, pf_number, id_number, email FROM users";
    $usersStmt = $conn->prepare($usersQuery);
    $usersStmt->execute();
    $users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Create mapping of user identifiers to user_id
    $userMap = [];
    foreach ($users as $user) {
        $userMap[$user['pf_number']] = $user['user_id'];
        $userMap[$user['id_number']] = $user['user_id'];
        $userMap[$user['email']] = $user['user_id'];
    }
    
    // Get all loan applications with incorrect user_id
    $loansQuery = "SELECT id, application_id, pf_number, id_number, email, user_id FROM loan_applications WHERE user_id = 0 OR user_id NOT IN (SELECT user_id FROM users)";
    $loansStmt = $conn->prepare($loansQuery);
    $loansStmt->execute();
    $loans = $loansStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Update each loan application with the correct user_id
    foreach ($loans as $loan) {
        $correctUserId = null;
        
        // Try to match by pf_number first
        if (isset($userMap[$loan['pf_number']])) {
            $correctUserId = $userMap[$loan['pf_number']];
        }
        // Try to match by id_number
        else if (isset($userMap[$loan['id_number']])) {
            $correctUserId = $userMap[$loan['id_number']];
        }
        // Try to match by email
        else if (isset($userMap[$loan['email']])) {
            $correctUserId = $userMap[$loan['email']];
        }
        
        if ($correctUserId) {
            $updateQuery = "UPDATE loan_applications SET user_id = :user_id WHERE id = :id";
            $updateStmt = $conn->prepare($updateQuery);
            $updateStmt->bindValue(':user_id', $correctUserId, PDO::PARAM_INT);
            $updateStmt->bindValue(':id', $loan['id'], PDO::PARAM_INT);
            $updateStmt->execute();
            
            echo "Updated loan application {$loan['application_id']} with user_id {$correctUserId}<br>";
        } else {
            echo "Could not find matching user for loan application {$loan['application_id']}<br>";
        }
    }
    
    echo "Fix completed!";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>