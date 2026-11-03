<?php
// Simple API test file
 $base_url = 'http://localhost/loan_system/api';

// Test login
function testLogin() {
    $data = [
        'action' => 'login',
        'pf_number' => '10101010101',
        'password' => 'admin123'
    ];
    
    $ch = curl_init(base_url . "/auth.php");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    echo "Login Test: " . $response . "\n";
}

// Run tests
testLogin();
?>