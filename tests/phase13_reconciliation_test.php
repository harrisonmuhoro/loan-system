<?php
declare(strict_types=1);

/** Read-only Phase 13 reconciliation checks. Requires LEGACY_TEST_DB=legacy_test_*. */
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

function p13Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . "\n";
}

$variance = $db->query(
    'SELECT COUNT(*) FROM (
        SELECT a.legacy_account_id,
               COALESCE(SUM(t.debit_amount - t.credit_amount), 0) AS ledger_balance,
               COALESCE((SELECT SUM(t2.debit_amount - t2.credit_amount)
                           FROM legacy_transactions t2
                          WHERE t2.legacy_account_id = a.legacy_account_id), 0) AS independent_balance
          FROM legacy_debt_accounts a
          LEFT JOIN legacy_transactions t ON t.legacy_account_id = a.legacy_account_id
         GROUP BY a.legacy_account_id
    ) balances WHERE ABS(ledger_balance - independent_balance) > 0.00005'
)->fetchColumn();
p13Assert((int)$variance === 0, 'legacy ledger balances reconcile to independent debit-minus-credit calculation');

$duplicateLinks = $db->query(
    'SELECT COUNT(*) FROM (
        SELECT linked_loan_application_id
          FROM legacy_debt_accounts
         WHERE linked_loan_application_id IS NOT NULL
         GROUP BY linked_loan_application_id
        HAVING COUNT(*) > 1
    ) duplicate_links'
)->fetchColumn();
p13Assert((int)$duplicateLinks === 0, 'no normal loan is linked to multiple legacy accounts');

$triggerCount = $db->query(
    "SELECT COUNT(*) FROM information_schema.triggers
      WHERE trigger_schema = DATABASE()
        AND trigger_name IN ('legacy_transactions_before_delete',
          'legacy_action_requests_before_delete', 'legacy_audit_events_before_delete',
          'legacy_import_records_before_delete')"
)->fetchColumn();
p13Assert((int)$triggerCount === 4, 'financial and audit history deletion guards are installed');

echo "PHASE 13 READ-ONLY RECONCILIATION CHECKS PASSED\n";
