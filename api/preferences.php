<?php
/**
 * api/preferences.php - API Endpoint for User Preferences
 * FIXED: Corrected paths for 'api' subfolder & Session Order
 */

// 1. Start Session IMMEDIATELY (Before headers)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. Set JSON Header
header('Content-Type: application/json');

// 3. Include required files (Using ../ to access parent config folder)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Get request method
 $method = $_SERVER['REQUEST_METHOD'];

// Validate request method
if ($method !== 'GET' && $method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    // Get database connection
    $database = new Database();
    $conn = $database->getConnection();
    
    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    $userId = $_SESSION['user_id'];

    // Handle GET request - Retrieve preference
    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';
        
        if ($action !== 'get') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action for GET request']);
            exit();
        }
        
        $key = $_GET['key'] ?? '';
        
        // Validate key parameter
        if (empty($key)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Preference key is required']);
            exit();
        }

        // Sanitize key
        $key = trim($key);
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid preference key format']);
            exit();
        }

        // Query database for preference
        $stmt = $conn->prepare("SELECT preference_value FROM user_preferences WHERE user_id = ? AND preference_key = ?");
        $stmt->execute([$userId, $key]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'value' => $result ? $result['preference_value'] : null,
            'key' => $key
        ]);
        exit();
    }
    
    // Handle POST request - Set preference
    if ($method === 'POST') {
        // Get JSON input
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Validate JSON input
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
            exit();
        }

        $action = $input['action'] ?? '';
        
        if ($action !== 'set') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action for POST request']);
            exit();
        }

        $key = $input['key'] ?? '';
        $value = $input['value'] ?? '';
        
        // Validate parameters
        if (empty($key)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Preference key is required']);
            exit();
        }

        // Sanitize key
        $key = trim($key);
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid preference key format']);
            exit();
        }

        // Sanitize value
        $value = trim($value);
        if (strlen($value) > 255) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Preference value too long (max 255 characters)']);
            exit();
        }

        // Update or insert preference
        $stmt = $conn->prepare("INSERT INTO user_preferences (user_id, preference_key, preference_value, created_at, updated_at) 
                               VALUES (?, ?, ?, NOW(), NOW()) 
                               ON DUPLICATE KEY UPDATE preference_value = ?, updated_at = NOW()");
        
        $result = $stmt->execute([$userId, $key, $value, $value]);
        
        if ($result) {
            echo json_encode([
                'success' => true, 
                'message' => 'Preference saved successfully',
                'key' => $key,
                'value' => $value
            ]);
        } else {
            throw new Exception('Failed to save preference');
        }
        exit();
    }

} catch (PDOException $e) {
    // Database error
    error_log("Database error in preferences.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Database error occurred'
    ]);
} catch (Exception $e) {
    // General error
    error_log("Error in preferences.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'An error occurred while processing your request'
    ]);
}
?>