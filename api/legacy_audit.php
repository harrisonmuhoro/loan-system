<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/legacy_authorization.php';
header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new RuntimeException('Method not allowed');
    $db = (new Database())->getConnection();
    legacyRequirePermission($db, 'legacy.audit.view');
    $accountId = filter_input(INPUT_GET, 'legacy_account_id', FILTER_VALIDATE_INT);
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
    $sql = 'SELECT e.*,u.full_name AS actor_name,u.role AS actor_role
              FROM legacy_audit_events e LEFT JOIN users u ON u.user_id=e.actor_user_id';
    $params = [];
    if ($accountId) {
        $sql .= " WHERE (e.entity_type='legacy_account' AND e.entity_id=?
                   OR e.entity_type='legacy_migration' AND e.entity_id IN
                      (SELECT legacy_migration_id FROM legacy_migrations WHERE legacy_account_id=?)
                   OR e.entity_type='legacy_action_request' AND e.entity_id IN
                      (SELECT action_request_id FROM legacy_action_requests WHERE legacy_account_id=?))";
        $params[] = $accountId;
        $params[] = $accountId;
        $params[] = $accountId;
    }
    $sql .= ' ORDER BY e.created_at DESC LIMIT ' . $limit;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['success' => true, 'data' => ['events' => $stmt->fetchAll(PDO::FETCH_ASSOC)]], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code($e->getMessage() === 'Authentication required' ? 401 : 403);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
