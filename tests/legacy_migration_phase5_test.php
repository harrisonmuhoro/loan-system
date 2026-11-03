<?php
/** Phase 5 migration relationship and routing assertions. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("Run from CLI.\n");
$dbName = getenv('LEGACY_TEST_DB') ?: '';
if (!preg_match('/^legacy_test_[a-z0-9_]+$/', $dbName)) {
    echo "SKIP: set LEGACY_TEST_DB to an isolated legacy_test_* database.\n";
    exit(0);
}

$db = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('LEGACY_TEST_HOST') ?: '127.0.0.1', getenv('LEGACY_TEST_PORT') ?: '3306', $dbName),
    getenv('LEGACY_TEST_USER') ?: 'root', getenv('LEGACY_TEST_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

function phase5Assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    echo "PASS: {$message}\n";
}

$tables = $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('legacy_migrations','loan_applications','payments')")->fetchColumn();
phase5Assert((int)$tables === 3, 'migration and normal-loan tables exist');

$trigger = $db->query("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name='legacy_migrations_before_update'")->fetchColumn();
phase5Assert((int)$trigger === 1, 'executed migration immutability trigger exists');

$rows = $db->query("SELECT m.legacy_account_id,m.migration_status,m.migration_date,m.balance_at_migration,m.loan_application_id,la.id AS loan_id,la.loan_amount FROM legacy_migrations m LEFT JOIN loan_applications la ON la.id=m.loan_application_id WHERE m.migration_status='EXECUTED'")->fetchAll();
foreach ($rows as $row) {
    phase5Assert((int)$row['loan_id'] > 0, 'executed migration has a permanent normal-loan link');
    phase5Assert(abs((float)$row['balance_at_migration'] - (float)$row['loan_amount']) < 0.00005, 'normal loan starts at approved migration balance');
    phase5Assert($row['migration_date'] !== null, 'executed migration has a permanent migration date');
}

$duplicateIndex = $db->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='legacy_migrations' AND index_name='uk_legacy_migration_account'")->fetchColumn();
phase5Assert((int)$duplicateIndex === 1, 'one migration record per legacy account is enforced');

echo "PHASE 5 MIGRATION TESTS PASSED\n";
