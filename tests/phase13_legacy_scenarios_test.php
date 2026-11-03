<?php
declare(strict_types=1);

/** Rollback-only representative legacy financial scenarios for Phase 13. */
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
function p13Scenario(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
    echo 'PASS: ' . $message . "\n";
}

$actor = (int)$db->query('SELECT user_id FROM users ORDER BY user_id LIMIT 1')->fetchColumn();
if ($actor <= 0) throw new RuntimeException('No test user exists');
$token = strtoupper(bin2hex(random_bytes(5)));
$db->beginTransaction();
try {
    $db->prepare("INSERT INTO legacy_financial_years (label,start_date,end_date,status,post_close_transactions_allowed,created_by) VALUES (?, '2097-01-01','2097-12-31','OPEN',0,?)")
        ->execute(['P13-' . $token, $actor]);
    $year = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO legacy_debtors (legacy_reference,full_name,pf_number,national_id,created_by) VALUES (?,?,?,?,?)')
        ->execute(['P13-' . $token, 'Phase 13 Scenario ' . $token, 'P13' . $token, 'ID13' . $token, $actor]);
    $debtor = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO legacy_debt_accounts (legacy_debtor_id,account_reference,created_by) VALUES (?,?,?)')
        ->execute([$debtor, 'P13-ACC-' . $token, $actor]);
    $account = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO legacy_loan_components (legacy_account_id,financial_year_id,component_reference,original_principal,component_date,created_by) VALUES (?,?,?,?,?,?)')
        ->execute([$account, $year, 'P13-COMP-' . $token, 100, '2097-01-01', $actor]);
    $component = (int)$db->lastInsertId();

    $tx = $db->prepare('INSERT INTO legacy_transactions (legacy_account_id,legacy_component_id,financial_year_id,transaction_reference,transaction_type,debit_amount,credit_amount,transaction_date,description,recorded_by) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $tx->execute([$account,$component,$year,'P13-OPEN-'.$token,'OPENING_BALANCE',100,0,'2097-01-01','opening', $actor]);
    $tx->execute([$account,$component,$year,'P13-PAY-'.$token,'PAYMENT',0,125,'2097-02-01','overpayment', $actor]);
    $balance = (float)$db->query("SELECT SUM(debit_amount-credit_amount) FROM legacy_transactions WHERE legacy_account_id={$account}")->fetchColumn();
    p13Scenario(abs($balance + 25) < 0.00005, 'overpayment is preserved as a negative credit balance');

    $db->prepare('INSERT INTO legacy_payment_details (legacy_transaction_id,payment_method,payment_date,transaction_number,reconciliation_state) VALUES ((SELECT legacy_transaction_id FROM legacy_transactions WHERE transaction_reference=?),?,?,?,?)')
        ->execute(['P13-PAY-'.$token,'CASH','2097-02-01','P13-REC-'.$token,'UNRECONCILED']);
    $tx->execute([$account,$component,$year,'P13-REV-'.$token,'PAYMENT_REVERSAL',125,0,'2097-03-01','reversal', $actor]);
    $balance = (float)$db->query("SELECT SUM(debit_amount-credit_amount) FROM legacy_transactions WHERE legacy_account_id={$account}")->fetchColumn();
    p13Scenario(abs($balance - 100) < 0.00005, 'payment reversal restores the debtor balance');

    $tx->execute([$account,$component,$year,'P13-INT-'.$token,'INTEREST',10,0,'2097-04-01','interest', $actor]);
    $tx->execute([$account,$component,$year,'P13-PEN-'.$token,'PENALTY',5,0,'2097-04-02','penalty', $actor]);
    $tx->execute([$account,$component,$year,'P13-ADJ-D-'.$token,'ADJUSTMENT_DEBIT',7,0,'2097-04-03','adjustment', $actor]);
    $tx->execute([$account,$component,$year,'P13-ADJ-C-'.$token,'ADJUSTMENT_CREDIT',0,2,'2097-04-04','adjustment', $actor]);
    $tx->execute([$account,$component,$year,'P13-WAIVE-'.$token,'WAIVER',0,20,'2097-04-05','waiver', $actor]);
    $tx->execute([$account,$component,$year,'P13-WRITE-'.$token,'WRITE_OFF',0,10,'2097-04-06','write-off', $actor]);
    $balance = (float)$db->query("SELECT SUM(debit_amount-credit_amount) FROM legacy_transactions WHERE legacy_account_id={$account}")->fetchColumn();
    p13Scenario(abs($balance - 90) < 0.00005, 'interest, penalty, adjustments, waiver, and write-off reconcile in the ledger');

    $db->prepare('UPDATE legacy_debt_accounts SET dispute_status=? WHERE legacy_account_id=?')->execute(['OPEN',$account]);
    $db->prepare('INSERT INTO legacy_dispute_events (legacy_account_id,old_status,new_status,reason,actor_user_id) VALUES (?,?,?,?,?)')
        ->execute([$account,'NONE','OPEN','Phase 13 dispute scenario',$actor]);
    p13Scenario((int)$db->query("SELECT COUNT(*) FROM legacy_dispute_events WHERE legacy_account_id={$account}")->fetchColumn() === 1, 'dispute state has an auditable event');

    $db->rollBack();
    echo "PHASE 13 LEGACY SCENARIO TESTS PASSED (fixtures rolled back)\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}
