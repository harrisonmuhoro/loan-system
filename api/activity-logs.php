<?php
// api/activity-logs.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

session_start();

// Authentication check
if (
    !isset($_SESSION['user_role']) || 
    $_SESSION['user_role'] !== 'admin' || 
    !isset($_SESSION['user_id'])
) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Database connection
require_once __DIR__ . '/../config/database.php';

try {
    $db = (new Database())->getConnection();
    
    // Get pagination parameters
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(100, max(10, intval($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;
    
    // Get total count
    $countStmt = $db->prepare("SELECT COUNT(*) as total FROM activity_log");
    $countStmt->execute();
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get logs with pagination
    $stmt = $db->prepare("
        SELECT al.*, u.full_name as admin_name 
        FROM activity_log al
        LEFT JOIN users u ON al.admin_id = u.user_id
        ORDER BY al.timestamp DESC
        LIMIT :limit OFFSET :offset
    ");
    
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $logs,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => ceil($total / $limit)
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}