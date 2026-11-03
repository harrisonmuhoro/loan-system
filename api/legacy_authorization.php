<?php
/**
 * Server-side authorization for legacy debt operations.
 *
 * The authenticated user id comes from the session, but the role and active
 * state are always read from users. A client cannot elevate itself by
 * changing a session role value or a request payload.
 */
declare(strict_types=1);

function legacyAuthUserId(): int
{
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        throw new RuntimeException('Authentication required');
    }

    return $userId;
}

function legacyAuthContext(PDO $db, int $userId): array
{
    $stmt = $db->prepare(
        'SELECT u.user_id, u.role, u.is_active,
                COALESCE(p.permission_key, \'\') AS permission_key,
                COALESCE(up.allowed, rp.allowed, 0) AS permission_allowed
           FROM users u
           LEFT JOIN legacy_role_permissions rp
             ON rp.role = CONVERT(u.role USING utf8mb4) COLLATE utf8mb4_general_ci
           LEFT JOIN legacy_permissions p ON p.permission_id = rp.permission_id
           LEFT JOIN legacy_user_permissions up
             ON up.user_id = u.user_id AND up.permission_id = p.permission_id
          WHERE u.user_id = :user_id'
    );
    $stmt->execute([':user_id' => $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows || (int)$rows[0]['is_active'] !== 1) {
        throw new RuntimeException('Authenticated user is inactive or unavailable');
    }

    $permissions = [];
    foreach ($rows as $row) {
        if ($row['permission_key'] !== '') {
            $permissions[$row['permission_key']] = (int)$row['permission_allowed'] === 1;
        }
    }

    return [
        'user_id' => (int)$rows[0]['user_id'],
        'role' => strtolower((string)$rows[0]['role']),
        'permissions' => $permissions,
    ];
}

function legacyRequirePermission(PDO $db, string $permission): array
{
    $context = legacyAuthContext($db, legacyAuthUserId());
    if (($context['permissions'][$permission] ?? false) !== true) {
        throw new RuntimeException('Permission denied: ' . $permission);
    }

    return $context;
}

function legacyRequireAnyPermission(PDO $db, array $permissions): array
{
    $context = legacyAuthContext($db, legacyAuthUserId());
    foreach ($permissions as $permission) {
        if (($context['permissions'][$permission] ?? false) === true) {
            return $context;
        }
    }

    throw new RuntimeException('Permission denied');
}

function legacyPermissionForAction(string $actionType, string $stage): string
{
    $actionType = strtoupper($actionType);
    $stage = strtolower($stage);

    $map = [
        'PAYMENT_REVERSAL' => [
            'request' => 'legacy.payment.reverse',
            'approve' => 'legacy.payment.reverse.approve',
            'execute' => 'legacy.payment.reverse.process',
        ],
        'ADJUSTMENT' => [
            'request' => 'legacy.adjustment.request',
            'approve' => 'legacy.adjustment.approve',
            'execute' => 'legacy.adjustment.process',
        ],
        'WAIVER' => [
            'request' => 'legacy.waiver.request',
            'approve' => 'legacy.waiver.approve',
            'execute' => 'legacy.waiver.process',
        ],
        'WRITE_OFF' => [
            'request' => 'legacy.writeoff.request',
            'approve' => 'legacy.writeoff.approve',
            'execute' => 'legacy.writeoff.process',
        ],
    ];

    if (!isset($map[$actionType][$stage])) {
        throw new RuntimeException('Unsupported legacy action permission');
    }

    return $map[$actionType][$stage];
}
