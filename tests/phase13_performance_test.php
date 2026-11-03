<?php
declare(strict_types=1);

/** Rollback-only synthetic performance probe; does not modify persistent data. */
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
$actor = (int)$db->query('SELECT user_id FROM users ORDER BY user_id LIMIT 1')->fetchColumn();
$year = (int)$db->query("SELECT financial_year_id FROM legacy_financial_years ORDER BY financial_year_id LIMIT 1")->fetchColumn();
if ($actor <= 0 || $year <= 0) throw new RuntimeException('Required isolated performance fixtures are missing');

$count = 1000;
$token = strtoupper(bin2hex(random_bytes(5)));
$db->beginTransaction();
try {
    $debtor = $db->prepare('INSERT INTO legacy_debtors (legacy_reference,full_name,pf_number,national_id,created_by) VALUES (?,?,?,?,?)');
    $account = $db->prepare('INSERT INTO legacy_debt_accounts (legacy_debtor_id,account_reference,created_by) VALUES (?,?,?)');
    $component = $db->prepare('INSERT INTO legacy_loan_components (legacy_account_id,financial_year_id,component_reference,original_principal,component_date,created_by) VALUES (?,?,?,?,?,?)');
    $transaction = $db->prepare('INSERT INTO legacy_transactions (legacy_account_id,legacy_component_id,financial_year_id,transaction_reference,transaction_type,debit_amount,credit_amount,transaction_date,description,recorded_by) VALUES (?,?,?,?,?,?,?,?,?,?)');

    for ($i = 1; $i <= $count; $i++) {
        $debtor->execute(['P13-PERF-D-' . $token . '-' . $i, 'Performance Debtor ' . $i, 'P13' . $token . $i, 'PERF' . $token . $i, $actor]);
        $debtorId = (int)$db->lastInsertId();
        $account->execute([$debtorId, 'P13-PERF-A-' . $token . '-' . $i, $actor]);
        $accountId = (int)$db->lastInsertId();
        $component->execute([$accountId, $year, 'P13-PERF-C-' . $token . '-' . $i, 1000, '2096-01-01', $actor]);
        $componentId = (int)$db->lastInsertId();
        $transaction->execute([$accountId, $componentId, $year, 'P13-PERF-T-' . $token . '-' . $i, 'OPENING_BALANCE', 1000, 0, '2096-01-01', 'performance fixture', $actor]);
    }

    $dashboard = "SELECT COUNT(*) AS total_accounts,
        COALESCE(SUM(CASE WHEN a.migration_status <> 'MIGRATED' THEN
          GREATEST((SELECT COALESCE(SUM(t.debit_amount)-SUM(t.credit_amount),0) FROM legacy_transactions t WHERE t.legacy_account_id=a.legacy_account_id),0)
          ELSE 0 END),0) AS outstanding_balance
      FROM legacy_debt_accounts a";
    $balances = 'SELECT a.legacy_account_id, COALESCE(SUM(t.debit_amount-t.credit_amount),0) AS balance
                   FROM legacy_debt_accounts a
                   LEFT JOIN legacy_transactions t ON t.legacy_account_id=a.legacy_account_id
                  GROUP BY a.legacy_account_id';
    $start = microtime(true);
    $db->query($dashboard)->fetch();
    $dashboardMs = (microtime(true) - $start) * 1000;
    $start = microtime(true);
    $rows = $db->query($balances)->rowCount();
    $balanceMs = (microtime(true) - $start) * 1000;
    $db->rollBack();

    echo 'synthetic_accounts=' . $count . "\n";
    echo 'dashboard_ms=' . number_format($dashboardMs, 2, '.', '') . "\n";
    echo 'balance_query_ms=' . number_format($balanceMs, 2, '.', '') . "\n";
    echo 'rollback=passed\nPHASE 13 SYNTHETIC PERFORMANCE TEST PASSED\n';
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}
