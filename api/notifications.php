<?php
// Add this at the very top of all API files
error_reporting(0);
ini_set('display_errors', 0);

// Start output buffering to catch any accidental output
ob_start();

// Set content type header before any output
header('Content-Type: application/json');

// Include required files
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response_helper.php';

// Start session
session_start();

// Verify session
if (!isset($_SESSION['user_id'])) {
    ob_clean();
    ResponseHelper::unauthorized('Unauthorized. Please login.');
    exit();
}

// Verify accountant role
if ($_SESSION['role'] !== 'accountant') {
    ob_clean();
    ResponseHelper::forbidden('Only accountants can view notifications.');
    exit();
}

// Initialize database
try {
    $database = new Database();
    $db = $database->getConnection();
} catch (Exception $e) {
    ob_clean();
    ResponseHelper::serverError('Database connection failed');
    exit();
}

// Handle request method
 $method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        // Get unread notifications for the accountant
        $query = "SELECT * FROM accountant_notifications 
                 WHERE accountant_id = :accountant_id AND is_read = 0
                 ORDER BY created_at DESC";

        $stmt = $db->prepare($query);
        $stmt->bindParam(':accountant_id', $_SESSION['user_id']);
        $stmt->execute();

        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ob_clean();
        ResponseHelper::success($notifications, 'Notifications retrieved successfully');

    } elseif ($method === 'PUT') {
        // Mark notifications as read
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            ob_clean();
            ResponseHelper::error('Invalid JSON data');
            exit();
        }

        $notificationId = $input['notification_id'] ?? null;

        if ($notificationId) {
            // Mark specific notification as read
            $query = "UPDATE accountant_notifications 
                     SET is_read = 1 
                     WHERE id = :notification_id AND accountant_id = :accountant_id";

            $stmt = $db->prepare($query);
            $stmt->bindParam(':notification_id', $notificationId);
            $stmt->bindParam(':accountant_id', $_SESSION['user_id']);
            $stmt->execute();
        } else {
            // Mark all notifications as read
            $query = "UPDATE accountant_notifications 
                     SET is_read = 1 
                     WHERE accountant_id = :accountant_id";

            $stmt = $db->prepare($query);
            $stmt->bindParam(':accountant_id', $_SESSION['user_id']);
            $stmt->execute();
        }

        ob_clean();
        ResponseHelper::success(null, 'Notifications marked as read');

    } elseif ($method === 'DELETE') {
        // Clear all notifications
        $query = "DELETE FROM accountant_notifications 
                 WHERE accountant_id = :accountant_id";

        $stmt = $db->prepare($query);
        $stmt->bindParam(':accountant_id', $_SESSION['user_id']);
        $stmt->execute();

        ob_clean();
        ResponseHelper::success(null, 'Notifications cleared successfully');

    } else {
        ob_clean();
        ResponseHelper::error('Method not allowed', 405);
    }

} catch (Exception $e) {
    ob_clean();
    ResponseHelper::serverError($e->getMessage());
}

exit();
?>