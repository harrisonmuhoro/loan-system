<?php
/**
 * Phase 3 database/import-structure tests.
 * Requires an isolated database named legacy_test_*.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from CLI.\n");
    exit(2);
}

$dbName = getenv('LEGACY_TEST_DB') ?: '';
if (!preg_match('/^legacy_test_[a-z0-9_]+$/', $dbName)) {
    echo "SKIP: set LEGACY_TEST_DB to an isolated legacy_test_* database.\n";
    exit(0);
}

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    getenv('LEGACY_TEST_HOST') ?: 'localhost',
    getenv('LEGACY_TEST_PORT') ?: '3306',
    $dbName
);

try {
    $db = new PDO($dsn, getenv('LEGACY_TEST_USER') ?: 'root', getenv('LEGACY_TEST_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "BLOCKED: {$e->getMessage()}\n");
    exit(2);
}

function ok(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("FAIL: {$message}");
    }
    echo "PASS: {$message}\n";
}

function fails(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable $e) {
        echo "PASS: {$message}\n";
        return;
    }
    throw new RuntimeException("FAIL: {$message}");
}

$tables = $db->query(
    "SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE()
        AND table_name IN ('legacy_import_batches',
                           'legacy_import_records',
                           'legacy_reconciliation_runs',
                           'legacy_reconciliation_results')"
)->fetchColumn();
ok((int)$tables === 4, 'Phase 3 import and reconciliation tables exist');

$db->beginTransaction();

try {
    $db->exec("INSERT INTO legacy_financial_years(label,start_date,end_date)
               VALUES ('IMPORT/TEST','2025-01-01','2025-12-31')");
    $yearId = (int)$db->lastInsertId();

    $db->exec("INSERT INTO users(role) VALUES ('admin')");
    $userId = (int)$db->lastInsertId();

    $batch = $db->prepare(
        "INSERT INTO legacy_import_batches
         (batch_reference, financial_year_id, source_filename, source_file_path,
          source_file_hash, source_mime_type, source_type, uploaded_by)
         VALUES ('TEST-BATCH', ?, 'test.csv', 'secure/legacy-imports/test.csv',
                 REPEAT('a', 64), 'text/csv', 'CSV', ?)"
    );
    $batch->execute([$yearId, $userId]);
    $batchId = (int)$db->lastInsertId();

    fails(
        function () use ($db, $yearId, $userId): void {
            $stmt = $db->prepare(
                "INSERT INTO legacy_import_batches
                 (batch_reference, financial_year_id, source_filename, source_file_path,
                  source_file_hash, source_mime_type, source_type, uploaded_by)
                 VALUES ('TEST-BATCH-DUP', ?, 'same.csv', 'secure/legacy-imports/same.csv',
                         REPEAT('a', 64), 'text/csv', 'CSV', ?)"
            );
            $stmt->execute([$yearId, $userId]);
        },
        'repeated source hash/year is rejected'
    );

    $record = $db->prepare(
        "INSERT INTO legacy_import_records
         (import_batch_id, source_row_number, source_data, normalized_name,
          normalized_pf_number, normalized_national_id, source_reference,
          amount, validation_state)
         VALUES (?, 1, JSON_OBJECT('Name','Test'), 'TEST PERSON',
                 'PF-TEST', 'ID-TEST', 'SRC-TEST', 1000, 'VALID')"
    );
    $record->execute([$batchId]);

    $run = $db->prepare(
        "INSERT INTO legacy_reconciliation_runs
         (run_reference, source_type, source_batch_id, started_by)
         VALUES ('REC-TEST', 'IMPORT_BATCH', ?, ?)"
    );
    $run->execute([$batchId, $userId]);
    $runId = (int)$db->lastInsertId();

    $result = $db->prepare(
        "INSERT INTO legacy_reconciliation_results
         (reconciliation_run_id, source_reference, source_name,
          source_amount, result_state, details)
         VALUES (?, 'SRC-TEST', 'TEST PERSON', 1000, 'AMOUNT_MISMATCH', 'test mismatch')"
    );
    $result->execute([$runId]);

    $count = $db->prepare(
        'SELECT COUNT(*) FROM legacy_reconciliation_results
          WHERE reconciliation_run_id = ? AND result_state = "AMOUNT_MISMATCH"'
    );
    $count->execute([$runId]);
    ok((int)$count->fetchColumn() === 1, 'reconciliation mismatches are persisted');

    $db->rollBack();
    echo "PASS: import-structure test rolled back cleanly\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $e;
}

echo "PHASE 3 DATABASE TESTS PASSED\n";

