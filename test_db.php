<?php
require_once 'config/database.php';

echo "Testing Database Connection...<br>";

try {
    $database = new Database();
    $db = $database->getConnection();
    echo "SUCCESS: Database connection established<br>";
    
    // Test a simple query
    $stmt = $db->query("SELECT COUNT(*) as user_count FROM users");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "User count in database: " . $result['user_count'] . "<br>";
    
    // Test User model
    require_once 'models/User.php';
    $user = new User($db);
    
    // Test authentication with known user
    if ($user->getByEmail('shawnkaboi3@gmail.com')) {
        echo "SUCCESS: User model can retrieve by email<br>";
        echo "Retrieved User ID: " . $user->user_id . "<br>";
        echo "Retrieved Role: " . $user->role . "<br>";
    } else {
        echo "ERROR: User model failed to retrieve by email<br>";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "<br>";
}

echo "<br>Database connection test completed.<br>";
?>