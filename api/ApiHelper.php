<?php
require_once __DIR__ . '/../config/config.php';

class ApiHelper {
    /**
     * Send JSON response with proper headers
     */
    public static function sendJson($code, $payload) {
        // Clear any output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Set headers
        http_response_code($code);
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
        header('Access-Control-Allow-Credentials: true');

        // Send response
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    
    /**
     * Log API errors
     */
    public static function logError($message) {
        $timestamp = date('Y-m-d H:i:s');
        $userId = $_SESSION['user_id'] ?? 'unknown';
        $role = $_SESSION['user_role'] ?? 'unknown';
        $logMessage = "[$timestamp] API ERROR [User:$userId, Role:$role]: $message";
        
        conditional_error_log($logMessage);
    }
    
    /**
     * Validate and sanitize input
     */
    public static function validateInput($data, $required = []) {
        $errors = [];
        
        // Check required fields
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                $errors[] = "Missing required field: $field";
            }
        }
        
        // Sanitize string inputs
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = trim($value);
            }
        }
        
        return ['valid' => empty($errors), 'errors' => $errors, 'data' => $data];
    }
    
    /**
     * Handle file uploads
     */
    public static function handleFileUpload($file, $uploadDir, $allowedTypes = []) {
        if (!isset($file) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return ['success' => false, 'message' => 'No file uploaded'];
        }
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'Upload error: ' . $file['error']];
        }
        
        // Check file type if allowed types specified
        if (!empty($allowedTypes)) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            if (!in_array($mimeType, $allowedTypes)) {
                return ['success' => false, 'message' => 'Invalid file type: ' . $mimeType];
            }
        }
        
        // Create upload directory if it doesn't exist
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // Generate unique filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid() . '_' . time() . '.' . $extension;
        $filepath = $uploadDir . '/' . $filename;
        
        // Move uploaded file
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            return ['success' => true, 'filename' => $filename, 'filepath' => $filepath];
        }
        
        return ['success' => false, 'message' => 'Failed to move uploaded file'];
    }
}
?>