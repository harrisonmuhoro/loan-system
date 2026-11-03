<?php
// api/clear-data.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

session_start();

// Authentication check
if (
    !isset($_SESSION['user_role']) || 
    $_SESSION['user_role'] !== 'admin' || 
    !isset($_SESSION['user_id'], $_SESSION['csrf_token'])
) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get and validate input
 $input = json_decode(file_get_contents('php://input'), true);

if (
    empty($input['csrf_token']) || 
    !hash_equals($_SESSION['csrf_token'], $input['csrf_token'])
) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

 $dataType = $input['data_type'] ?? '';
if (!in_array($dataType, ['logs', 'staff', 'loans', 'system'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid data type']);
    exit;
}

// Database connection
require_once __DIR__ . '/../config/database.php';

try {
    $db = (new Database())->getConnection();
    $db->beginTransaction();
    
    $clearedTables = [];
    $errors = [];
    
    switch ($dataType) {
        case 'logs':
            // Clear activity logs
            $stmt = $db->prepare("DELETE FROM activity_log");
            if ($stmt->execute()) {
                $clearedTables[] = 'activity_log';
            } else {
                $errors[] = 'Failed to clear activity_log';
            }
            break;
            
        case 'staff':
            // Clear staff-related tables in order
            $staffTables = [
                'staff',
                'admins', 
                'cashiers',
                'accountants',
                'users' // Clear users with role != 'loanee'
            ];
            
            foreach ($staffTables as $table) {
                try {
                    if ($table === 'users') {
                        $stmt = $db->prepare("DELETE FROM users WHERE role != 'loanee'");
                    } else {
                        $stmt = $db->prepare("DELETE FROM $table");
                    }
                    
                    if ($stmt->execute()) {
                        $clearedTables[] = $table;
                    } else {
                        $errors[] = "Failed to clear $table";
                    }
                } catch (Exception $e) {
                    $errors[] = "Error clearing $table: " . $e->getMessage();
                }
            }
            break;
            
        case 'loans':
            // Clear loan-related tables in order
            $loanTables = [
                'payments',
                'payment_schedules',
                'loan_applications'
            ];
            
            foreach ($loanTables as $table) {
                try {
                    $stmt = $db->prepare("DELETE FROM $table");
                    if ($stmt->execute()) {
                        $clearedTables[] = $table;
                    } else {
                        $errors[] = "Failed to clear $table";
                    }
                } catch (Exception $e) {
                    $errors[] = "Error clearing $table: " . $e->getMessage();
                }
            }
            break;
            
        case 'system':
            // Clear all data except system_settings
            $allTables = [
                'activity_log',
                'payments',
                'payment_schedules',
                'loan_applications',
                'funding_transactions',
                'funding_accounts',
                'staff',
                'admins',
                'cashiers',
                'accountants',
                'users'
            ];
            
            foreach ($allTables as $table) {
                try {
                    $stmt = $db->prepare("DELETE FROM $table");
                    if ($stmt->execute()) {
                        $clearedTables[] = $table;
                    } else {
                        $errors[] = "Failed to clear $table";
                    }
                } catch (Exception $e) {
                    $errors[] = "Error clearing $table: " . $e->getMessage();
                }
            }
            break;
    }
    
    if (empty($errors)) {
        $db->commit();
        
        // Log the action
        try {
            $logStmt = $db->prepare(
                "INSERT INTO activity_log (admin_id, action, details, timestamp) 
                 VALUES (?, 'clear_data', ?, NOW())"
            );
            $logStmt->execute([
                $_SESSION['user_id'],
                "Cleared data type: $dataType"
            ]);
        } catch (Exception $e) {
            // Ignore logging errors
        }
        
        echo json_encode([
            'success' => true,
            'message' => "Successfully cleared $dataType data",
            'cleared_tables' => $clearedTables
        ]);
    } else {
        $db->rollBack();
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Some errors occurred',
            'errors' => $errors
        ]);
    }
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}