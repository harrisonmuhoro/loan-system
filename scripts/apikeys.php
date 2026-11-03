<?php
/*  scripts/generate_api_key.php  */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/api_keys.php';

try {
    $db = (new Database())->getConnection();
    $keyManager = new ApiKeyManager($db);
    
    // Create API key for the loan system frontend
    $result = $keyManager->createKey(
        'Loan System Frontend',
        ['read', 'write'], // Permissions
        'Main API key for the loan application system',
        null, // Not linked to a specific user
        null  // No expiration
    );
    
    if ($result['success']) {
        echo "✅ API Key Generated Successfully!\n\n";
        echo "====================================\n";
        echo "Name: {$result['name']}\n";
        echo "API Key: {$result['api_key']}\n";
        echo "API Secret: {$result['api_secret']}\n";
        echo "Permissions: " . implode(', ', $result['permissions']) . "\n";
        echo "====================================\n\n";
        echo "⚠️  CRITICAL: Save both the key and secret securely!\n";
        echo "The secret will NOT be shown again.\n\n";
        echo "Add the API key to your JavaScript config:\n";
        echo "const API_KEY = '{$result['api_key']}';\n";
    } else {
        echo "❌ Failed to generate API key.\n";
        echo "Error: " . ($result['message'] ?? 'Unknown error') . "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}