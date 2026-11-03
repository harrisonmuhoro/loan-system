<?php
// api/upload-users-file.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../lib/FileParser.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verify admin authentication
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

try {
    // Check if file was uploaded
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No file uploaded or upload error occurred');
    }
    
    $file = $_FILES['file'];
    $fileName = $file['name'];
    $fileTmpPath = $file['tmp_name'];
    $fileSize = $file['size'];
    
    // Validate file size (max 10MB)
    if ($fileSize > 10 * 1024 * 1024) {
        throw new Exception('File size exceeds 10MB limit');
    }
    
    // Get file extension
    $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    
    // Validate file type
    $allowedExtensions = ['pdf', 'xlsx', 'xls', 'csv'];
    if (!in_array($fileExtension, $allowedExtensions)) {
        throw new Exception('Invalid file type. Allowed types: PDF, Excel (XLSX/XLS), CSV');
    }
    
    // Validate MIME type for additional security
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $fileTmpPath);
    finfo_close($finfo);
    
    $allowedMimeTypes = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
        'text/csv',
        'text/plain'
    ];
    
    if (!in_array($mimeType, $allowedMimeTypes)) {
        throw new Exception('Invalid file MIME type: ' . $mimeType);
    }
    
    // Create uploads directory if it doesn't exist
    $uploadDir = __DIR__ . '/../uploads/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Generate unique filename
    $newFileName = uniqid('import_') . '_' . time() . '.' . $fileExtension;
    $destinationPath = $uploadDir . $newFileName;
    
    // Move uploaded file
    if (!move_uploaded_file($fileTmpPath, $destinationPath)) {
        throw new Exception('Failed to move uploaded file');
    }
    
    // Parse the file
    try {
        $users = FileParser::parseFile($destinationPath, $fileExtension);
        
        // Delete the uploaded file after parsing
        unlink($destinationPath);
        
        if (empty($users)) {
            throw new Exception('No valid user data found in file');
        }
        
        // Return parsed data for preview
        echo json_encode([
            'success' => true,
            'users' => $users,
            'total' => count($users),
            'message' => 'File parsed successfully. Review the data below before importing.'
        ]);
        
    } catch (Exception $e) {
        // Delete the uploaded file on error
        if (file_exists($destinationPath)) {
            unlink($destinationPath);
        }
        throw new Exception('File parsing failed: ' . $e->getMessage());
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}