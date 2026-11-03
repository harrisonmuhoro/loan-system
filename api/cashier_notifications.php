<?php
// api/cashier_notifications.php
header('Content-Type: application/json');

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check authentication
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'cashier') {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access'
    ]);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    $method = $_SERVER['REQUEST_METHOD'];
    $cashier_id = $_SESSION['user_id'] ?? null;
    
    if (!$cashier_id) {
        throw new Exception('Cashier ID not found in session');
    }
    
    switch ($method) {
        case 'GET':
            // Get notifications for this cashier
            $unread_only = isset($_GET['unread_only']) && $_GET['unread_only'] === 'true';
            $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
            
            $query = "SELECT 
                        cn.id,
                        cn.loan_application_id,
                        cn.message,
                        cn.is_read,
                        cn.created_at,
                        la.full_name as loanee_name,
                        la.application_id,
                        la.status as loan_status
                      FROM cashier_notifications cn
                      LEFT JOIN loan_applications la ON cn.loan_application_id = la.id
                      WHERE cn.cashier_id = :cashier_id";
            
            if ($unread_only) {
                $query .= " AND cn.is_read = 0";
            }
            
            $query .= " ORDER BY cn.created_at DESC LIMIT :limit";
            
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':cashier_id', $cashier_id, PDO::PARAM_INT);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get unread count
            $count_query = "SELECT COUNT(*) as unread_count 
                           FROM cashier_notifications 
                           WHERE cashier_id = :cashier_id AND is_read = 0";
            $count_stmt = $conn->prepare($count_query);
            $count_stmt->bindParam(':cashier_id', $cashier_id, PDO::PARAM_INT);
            $count_stmt->execute();
            $count_result = $count_stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => $notifications,
                'unread_count' => intval($count_result['unread_count'])
            ]);
            break;
            
        case 'PUT':
            // Mark notification(s) as read
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (isset($input['notification_id'])) {
                // Mark single notification as read
                $notification_id = intval($input['notification_id']);
                $query = "UPDATE cashier_notifications 
                         SET is_read = 1 
                         WHERE id = :notification_id AND cashier_id = :cashier_id";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':notification_id', $notification_id, PDO::PARAM_INT);
                $stmt->bindParam(':cashier_id', $cashier_id, PDO::PARAM_INT);
                $stmt->execute();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Notification marked as read'
                ]);
            } elseif (isset($input['mark_all_read']) && $input['mark_all_read'] === true) {
                // Mark all notifications as read
                $query = "UPDATE cashier_notifications 
                         SET is_read = 1 
                         WHERE cashier_id = :cashier_id AND is_read = 0";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':cashier_id', $cashier_id, PDO::PARAM_INT);
                $stmt->execute();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'All notifications marked as read'
                ]);
            } else {
                throw new Exception('Invalid request parameters');
            }
            break;
            
        case 'DELETE':
            // Delete notification
            $input = json_decode(file_get_contents('php://input'), true);
            $notification_id = intval($input['notification_id'] ?? 0);
            
            if ($notification_id > 0) {
                $query = "DELETE FROM cashier_notifications 
                         WHERE id = :notification_id AND cashier_id = :cashier_id";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':notification_id', $notification_id, PDO::PARAM_INT);
                $stmt->bindParam(':cashier_id', $cashier_id, PDO::PARAM_INT);
                $stmt->execute();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Notification deleted'
                ]);
            } else {
                throw new Exception('Invalid notification ID');
            }
            break;
            
        default:
            http_response_code(405);
            echo json_encode([
                'success' => false,
                'message' => 'Method not allowed'
            ]);
    }
    
} catch (Exception $e) {
    error_log('Cashier Notifications API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>