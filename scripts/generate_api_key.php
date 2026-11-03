<?php
/* scripts/generate_api_key.php */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/api_keys.php';

try {
    $db = (new Database())->getConnection();
    $keyManager = new ApiKeyManager($db);
    
    // Create API key for loan system frontend
    $result = $keyManager->createKey(
        'Loan System Frontend',
        ['read', 'write'], // Permissions
        'Main API key for the loan application system',
        null, // Not linked to a specific user
        null  // No expiration
    );
    
    if ($result['success']) {
        // Generate .env file content
        $envFile = __DIR__ . '/../.env';
        $envContent = file_exists($envFile) ? file_get_contents($envFile) : '';
        
        // Check if API_KEY already exists in .env
        if (strpos($envContent, 'API_KEY=') === false) {
            // Append new API key to .env
            $envContent .= "\n# Loan System API Key\n";
            $envContent .= "API_KEY={$result['api_key']}\n";
            $envContent .= "API_SECRET={$result['api_secret']}\n";
            
            file_put_contents($envFile, $envContent);
            chmod($envFile, 0600); // Secure permissions
            
            echo "✅ API Key Generated Successfully!\n";
            echo "Key has been added to .env file.\n";
        } else {
            echo "⚠️ API Key already exists in .env file.\n";
            echo "Update manually if needed.\n";
        }
        
    } else {
        echo "❌ Failed to generate API key.\n";
        echo "Error: " . ($result['message'] ?? 'Unknown error') . "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}