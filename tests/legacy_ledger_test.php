<?php
/**
 * Database-level Phase 2 ledger tests.
 *
 * Safety: this test refuses to run unless LEGACY_TEST_DB is explicitly set
 * to a database whose name starts with legacy_test_.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this test from the CLI.\n");
    exit(2);
}

$dbName = getenv('LEGACY_TEST_DB') ?: '';
if (!preg_match('/^legacy_test_[a-z0-9_]+$/', $dbName)) {
    fwrite(STDOUT, "SKIP: set LEGACY_TEST_DB to an isolated legacy_test_* database.\n");
    exit(0);
}

$host = getenv('LEGACY_TEST_HOST') ?: '127.0.0.1';
$port = getenv('LEGACY_TEST_PORT') ?: '3306';
$user = getenv('LEGACY_TEST_USER') ?: 'root';
$password = getenv('LEGACY_TEST_PASSWORD') ?: '';
$dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";

try {
    $db = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "BLOCKED: cannot connect to isolated test database: {$e->getMessage()}\n");
    exit(2);
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("FAIL: {$message}");
    }
    echo "PASS: {$message}\n";
}

function expectFailure(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (Throwable $e) {
        echo "PASS: {$message}\n";
        return;
    }

    throw new RuntimeException("FAIL: {$message}");
}

$tables = $db->query(
    "SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE()
        AND table_name IN (
          'legacy_financial_years','legacy_debtors','legacy_debt_accounts',
          'legacy_loan_components','legacy_transactions',
          'legacy_payment_details','legacy_action_requests',
          'legacy_migrations','legacy_audit_events'
        )"
)->fetchColumn();
assertTrue((int)$tables === 9, 'Phase 2 foundation tables are present');

$db->beginTransaction();

try {
    $db->exec("INSERT INTO legacy_financial_years
        (label, start_date, end_date)
        VALUES ('TEST/2024', '2024-01-01', '2024-12-31')");

    $yearId = (int)$db->lastInsertId();

    $stmt = $db->prepare(
        "INSERT INTO legacy_debtors (full_name, pf_number, national_id)
         VALUES ('Phase 2 Test Debtor', 'TEST-PF', 'TEST-ID')"
    );
    $stmt->execute();
    $debtorId = (int)$db->lastInsertId();

    $stmt = $db->prepare(
        "INSERT INTO legacy_debt_accounts (legacy_debtor_id, account_reference)
         VALUES (?, 'TEST-ACCOUNT')"
    );
    $stmt->execute([$debtorId]);
    $accountId = (int)$db->lastInsertId();

    expectFailure(
        function () use ($db, $debtorId): void {
            $stmt = $db->prepare(
                "INSERT INTO legacy_debt_accounts
                 (legacy_debtor_id, account_reference)
                 VALUES (?, 'TEST-DUPLICATE-ACCOUNT')"
            );
            $stmt->execute([$debtorId]);
        },
        'one legacy account per debtor is enforced'
    );

    $stmt = $db->prepare(
        "INSERT INTO legacy_transactions
         (legacy_account_id, financial_year_id, transaction_reference,
          transaction_type, debit_amount, transaction_date, recorded_by)
         VALUES (?, ?, 'TEST-OPENING', 'OPENING_BALANCE', 1000.0000, '2024-01-01', 1)"
    );
    $stmt->execute([$accountId, $yearId]);

    $stmt = $db->prepare(
        "INSERT INTO legacy_transactions
         (legacy_account_id, financial_year_id, transaction_reference,
          transaction_type, credit_amount, transaction_date, recorded_by)
         VALUES (?, ?, 'TEST-PAYMENT', 'PAYMENT', 1250.0000, '2024-02-01', 1)"
    );
    $stmt->execute([$accountId, $yearId]);
    $paymentId = (int)$db->lastInsertId();

    $balance = $db->prepare(
        "SELECT SUM(debit_amount) - SUM(credit_amount)
           FROM legacy_transactions
          WHERE legacy_account_id = ?"
    );
    $balance->execute([$accountId]);
    assertTrue((float)$balance->fetchColumn() === -250.0, 'overpayment remains as a credit balance');

    expectFailure(
        function () use ($db, $accountId, $yearId): void {
            $stmt = $db->prepare(
                "INSERT INTO legacy_transactions
                 (legacy_account_id, financial_year_id, transaction_reference,
                  transaction_type, debit_amount, credit_amount, transaction_date)
                 VALUES (?, ?, 'TEST-BOTH-SIDES', 'ADJUSTMENT_DEBIT', 1, 1, '2024-03-01')"
            );
            $stmt->execute([$accountId, $yearId]);
        },
        'a transaction cannot contain both debit and credit'
    );

    expectFailure(
        function () use ($db, $paymentId): void {
            $stmt = $db->prepare(
                "UPDATE legacy_transactions SET credit_amount = 999
                  WHERE legacy_transaction_id = ?"
            );
            $stmt->execute([$paymentId]);
        },
        'posted financial transactions are immutable'
    );

    $db->exec(
        "INSERT INTO legacy_financial_years
         (label, start_date, end_date, status, post_close_transactions_allowed)
         VALUES ('TEST/CLOSED', '2023-01-01', '2023-12-31', 'CLOSED', 0)"
    );
    $closedYearId = (int)$db->lastInsertId();

    expectFailure(
        function () use ($db, $accountId, $closedYearId): void {
            $stmt = $db->prepare(
                "INSERT INTO legacy_transactions
                 (legacy_account_id, financial_year_id, transaction_reference,
                  transaction_type, debit_amount, transaction_date)
                 VALUES (?, ?, 'TEST-CLOSED-BLOCK', 'INTEREST', 10, '2023-02-01')"
            );
            $stmt->execute([$accountId, $closedYearId]);
        },
        'closed-year posting requires controlled authorization'
    );

    $db->rollBack();
    echo "PASS: test transaction rolled back cleanly\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $e;
}

echo "PHASE 2 DATABASE TESTS PASSED\n";

