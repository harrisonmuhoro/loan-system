<?php
/** Phase 4 database authorization and approval-control tests. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run from CLI.\n");
}

$dbName = getenv('LEGACY_TEST_DB') ?: '';
if (!preg_match('/^legacy_test_[a-z0-9_]+$/', $dbName)) {
    echo "SKIP: set LEGACY_TEST_DB to an isolated legacy_test_* database.\n";
    exit(0);
}

$db = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('LEGACY_TEST_HOST') ?: '127.0.0.1', getenv('LEGACY_TEST_PORT') ?: '3306', $dbName),
    getenv('LEGACY_TEST_USER') ?: 'root',
    getenv('LEGACY_TEST_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

function checkPhase4(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo "PASS: {$message}\n";
}

$required = ['legacy_permissions', 'legacy_role_permissions', 'legacy_user_permissions'];
$placeholders = implode(',', array_fill(0, count($required), '?'));
$stmt = $db->prepare(
    "SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name IN ($placeholders)"
);
$stmt->execute($required);
checkPhase4((int)$stmt->fetchColumn() === 3, 'granular permission tables exist');

$stmt = $db->query("SELECT COUNT(*) FROM legacy_permissions WHERE permission_key IN ('legacy.view','legacy.writeoff.approve','legacy.payment.create')");
checkPhase4((int)$stmt->fetchColumn() === 3, 'permission catalogue is seeded');

$stmt = $db->query("SELECT COUNT(*) FROM legacy_role_permissions rp INNER JOIN legacy_permissions p ON p.permission_id=rp.permission_id WHERE rp.role='loanee' AND rp.allowed=1 AND p.permission_key LIKE 'legacy.%'");
checkPhase4((int)$stmt->fetchColumn() === 0, 'loanee has no legacy permissions by default');

$stmt = $db->query("SELECT COUNT(*) FROM legacy_role_permissions rp INNER JOIN legacy_permissions p ON p.permission_id=rp.permission_id WHERE rp.role='cashier' AND p.permission_key='legacy.writeoff.approve' AND rp.allowed=1");
checkPhase4((int)$stmt->fetchColumn() === 0, 'cashier cannot approve write-offs');

$stmt = $db->query("SELECT COUNT(*) FROM legacy_role_permissions rp INNER JOIN legacy_permissions p ON p.permission_id=rp.permission_id WHERE rp.role='accountant' AND p.permission_key='legacy.writeoff.approve' AND rp.allowed=1");
checkPhase4((int)$stmt->fetchColumn() === 1, 'accountant can participate in write-off approval');

$stmt = $db->query("SELECT COUNT(*) FROM legacy_role_permissions rp INNER JOIN legacy_permissions p ON p.permission_id=rp.permission_id WHERE rp.role='admin' AND p.permission_key='legacy.writeoff.approve' AND rp.allowed=1");
checkPhase4((int)$stmt->fetchColumn() === 1, 'admin can participate in write-off approval');

echo "PHASE 4 DATABASE RBAC TESTS PASSED\n";
