<?php
/** Phase 10 database invariants. Requires the Phase 10 SQL migration. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("Run from CLI.\n");
$dbName = getenv('LEGACY_TEST_DB') ?: '';
if (!preg_match('/^legacy_test_[a-z0-9_]+$/', $dbName)) {
    fwrite(STDOUT, "SKIP: set LEGACY_TEST_DB to an isolated legacy_test_* database.\n");
    exit(0);
}
$db = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('LEGACY_TEST_HOST') ?: '127.0.0.1', getenv('LEGACY_TEST_PORT') ?: '3306', $dbName),
    getenv('LEGACY_TEST_USER') ?: 'root', getenv('LEGACY_TEST_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

function p10Assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    echo "PASS: {$message}\n";
}

function p10Reject(callable $operation, string $message): void
{
    try { $operation(); } catch (Throwable $e) { echo "PASS: {$message}\n"; return; }
    throw new RuntimeException('FAIL: expected rejection: ' . $message);
}

$users = $db->query('SELECT user_id FROM users ORDER BY user_id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
if (count($users) < 2) throw new RuntimeException('Need two existing users to test independent approval');
[$requester, $approver] = array_map('intval', $users);
$token = strtoupper(bin2hex(random_bytes(5)));
$db->beginTransaction();

try {
    $fy = $db->prepare("INSERT INTO legacy_financial_years (label,start_date,end_date,status,post_close_transactions_allowed) VALUES (?, '2098-01-01','2098-12-31','CLOSED',1)");
    $fy->execute(['P10/' . $token]);
    $yearId = (int)$db->lastInsertId();
    $openFy = $db->prepare("INSERT INTO legacy_financial_years (label,start_date,end_date,status,post_close_transactions_allowed) VALUES (?, '2099-01-01','2099-12-31','OPEN',0)");
    $openFy->execute(['P10-OPEN/' . $token]);
    $openYearId = (int)$db->lastInsertId();
    $debtor = $db->prepare('INSERT INTO legacy_debtors (legacy_reference,full_name,pf_number,national_id,linked_user_id) VALUES (?,?,?,?,?)');
    $pf = (string)random_int(10000000, 99999999);
    $debtor->execute(['P10-' . $token, 'Phase 10 Test ' . $token, $pf, 'ID' . $token, $requester]);
    $debtorId = (int)$db->lastInsertId();
    $account = $db->prepare('INSERT INTO legacy_debt_accounts (legacy_debtor_id,account_reference) VALUES (?,?)');
    $account->execute([$debtorId, 'P10-ACC-' . $token]);
    $accountId = (int)$db->lastInsertId();

    $insertTx = $db->prepare(
        'INSERT INTO legacy_transactions
         (legacy_account_id,financial_year_id,transaction_reference,transaction_type,debit_amount,credit_amount,transaction_date,description,post_close_approval_reference,recorded_by)
         VALUES (?,?,?, ?,?,?,?, ?,?,?)'
    );
    p10Reject(fn() => $insertTx->execute([$accountId,$yearId,'P10-FAKE-' . $token,'PAYMENT','0','25.0000','0','fake authorization','FAKE-' . $token,$requester]), 'fake closed-year authorization reference is rejected');

    $authRef = 'P10-AUTH-' . $token;
    $auth = $db->prepare("INSERT INTO legacy_post_close_authorizations (authorization_reference,legacy_account_id,financial_year_id,operation_type,amount,reason_text,requested_by) VALUES (?,?,?,'PAYMENT',25,'Phase 10 test',?)");
    $auth->execute([$authRef,$accountId,$yearId,$requester]);
    p10Reject(fn() => $insertTx->execute([$accountId,$yearId,'P10-PENDING-' . $token,'PAYMENT','0','25.0000','0','pending auth',$authRef,$requester]), 'unapproved authorization is rejected');

    p10Reject(function () use ($db, $authRef, $requester): void {
        $db->prepare("UPDATE legacy_post_close_authorizations SET status='APPROVED',approved_by=?,approved_at=CURRENT_TIMESTAMP WHERE authorization_reference=?")
            ->execute([$requester,$authRef]);
    }, 'self-approved authorization is rejected by the database');
    $db->prepare("UPDATE legacy_post_close_authorizations SET status='APPROVED',approved_by=?,approved_at=CURRENT_TIMESTAMP WHERE authorization_reference=?")
        ->execute([$approver,$authRef]);
    p10Reject(fn() => $insertTx->execute([$accountId,$yearId,'P10-WRONG-AMOUNT-' . $token,'PAYMENT','0','26.0000','0','mismatch',$authRef,$requester]), 'authorization cannot be reused for a different amount');

    $paymentRef = 'P10-PAY-' . $token;
    $insertTx->execute([$accountId,$yearId,$paymentRef,'PAYMENT','0','25.0000','0','approved test payment',$authRef,$requester]);
    $paymentId = (int)$db->lastInsertId();
    $state = $db->prepare('SELECT status,consumed_transaction_id FROM legacy_post_close_authorizations WHERE authorization_reference=?');
    $state->execute([$authRef]);
    $consumed = $state->fetch();
    p10Assert($consumed['status'] === 'CONSUMED' && (int)$consumed['consumed_transaction_id'] === $paymentId, 'valid approval is consumed by exactly one payment');
    p10Reject(fn() => $insertTx->execute([$accountId,$yearId,'P10-REPLAY-' . $token,'PAYMENT','0','25.0000','0','replay',$authRef,$requester]), 'consumed authorization replay is rejected');

    $fund = $db->prepare('INSERT INTO funding_accounts (name,balance) VALUES (?,0)');
    $fund->execute(['P10 Collections ' . $token]);
    $fundId = (int)$db->lastInsertId();
    $openPaymentRef = 'P10-OPEN-PAY-' . $token;
    $insertTx->execute([$accountId,$openYearId,$openPaymentRef,'PAYMENT','0','25.0000','0','test payment for reversal',null,$requester]);
    $openPaymentId = (int)$db->lastInsertId();
    $db->prepare("UPDATE funding_accounts SET balance=25 WHERE id=?")->execute([$fundId]);
    $db->prepare("INSERT INTO funding_transactions (funding_account_id,amount,transaction_type,reference,created_by) VALUES (?,25,'deposit',?,?)")
        ->execute([$fundId,'legacy-payment:' . $openPaymentRef,$requester]);
    $db->prepare("INSERT INTO legacy_payment_details (legacy_transaction_id,payment_method,payment_date,funding_account_id,reconciliation_state) VALUES (?,'CASH','2098-05-01',?,'UNRECONCILED')")
        ->execute([$openPaymentId,$fundId]);

    $insertReversal = $db->prepare(
        "INSERT INTO legacy_transactions
         (legacy_account_id,financial_year_id,transaction_reference,transaction_type,debit_amount,credit_amount,transaction_date,description,parent_transaction_id,recorded_by)
         VALUES (?,?,?,?,?,?,? ,?,?,?)"
    );
    $insertReversal->execute([$accountId,$openYearId,'P10-REV-' . $token,'PAYMENT_REVERSAL','25.0000','0','2098-05-01','reversal',$openPaymentId,$approver]);
    $reversalId = (int)$db->lastInsertId();
    $db->prepare("UPDATE legacy_transactions SET status='REVERSED' WHERE legacy_transaction_id=?")->execute([$openPaymentId]);
    $db->prepare("UPDATE legacy_payment_details SET reconciliation_state='REVERSED' WHERE legacy_transaction_id=?")->execute([$openPaymentId]);
    $db->prepare("INSERT INTO funding_transactions (funding_account_id,amount,transaction_type,reference,created_by) VALUES (?,25,'refund',?,?)")
        ->execute([$fundId,'legacy-payment-reversal:' . $paymentRef,$approver]);
    $db->prepare('UPDATE funding_accounts SET balance=balance-25 WHERE id=?')->execute([$fundId]);
    $balances = $db->prepare('SELECT (SELECT SUM(debit_amount)-SUM(credit_amount) FROM legacy_transactions WHERE legacy_account_id=?) AS debtor_balance, (SELECT balance FROM funding_accounts WHERE id=?) AS fund_balance, (SELECT SUM(CASE WHEN transaction_type=\'deposit\' THEN amount WHEN transaction_type=\'refund\' THEN -amount ELSE 0 END) FROM funding_transactions WHERE funding_account_id=?) AS fund_net');
    $balances->execute([$accountId,$fundId,$fundId]);
    $reconciled = $balances->fetch();
    p10Assert(abs((float)$reconciled['debtor_balance'] + 25.0) < 0.00005 && abs((float)$reconciled['fund_balance']) < 0.00005 && abs((float)$reconciled['fund_net']) < 0.00005, 'payment reversal restores debtor ledger and funding balance to pre-payment state');

    p10Reject(fn() => $insertReversal->execute([$accountId,$openYearId,'P10-REV-DUP-' . $token,'PAYMENT_REVERSAL','25.0000','0','2098-05-01','duplicate reversal',$openPaymentId,$approver]), 'database uniqueness blocks a second reversal for the same payment');

    $migrationDebtor = $db->prepare('INSERT INTO legacy_debtors (legacy_reference,full_name,pf_number,national_id,linked_user_id) VALUES (?,?,?,?,?)');
    $migrationPf = (string)random_int(10000000, 99999999);
    $migrationDebtor->execute(['P10-MIG-' . $token, 'Phase 10 Migration ' . $token, $migrationPf, 'MID' . $token, $requester]);
    $migrationDebtorId = (int)$db->lastInsertId();
    $migrationAccount = $db->prepare('INSERT INTO legacy_debt_accounts (legacy_debtor_id,account_reference) VALUES (?,?)');
    $migrationAccount->execute([$migrationDebtorId,'P10-MIG-ACC-' . $token]);
    $migrationAccountId = (int)$db->lastInsertId();
    $insertTx->execute([$migrationAccountId,$openYearId,'P10-OPEN-' . $token,'OPENING_BALANCE','100.0000','0','0','opening balance',null,$requester]);

    $loan = $db->prepare("INSERT INTO loan_applications (user_id,application_id,full_name,id_number,pf_number,loan_type,loan_amount,status) VALUES (?,?,?,?,?,'mortgage',100,'active')");
    $loan->execute([$requester,'P10-LOAN-' . $token,'Phase 10 Migration ' . $token,'MID' . $token,(int)$migrationPf]);
    $loanId = (int)$db->lastInsertId();
    $migrationRef = 'P10-MIGRATION-' . $token;
    $action = $db->prepare("INSERT INTO legacy_action_requests (request_reference,legacy_account_id,financial_year_id,action_type,requested_amount,reason_code,reason_text,status,requested_by,approved_by,approved_at) VALUES (?, ?, ?, 'MIGRATION',100,'PHASE10','approved test migration','APPROVED',?,?,CURRENT_TIMESTAMP)");
    $action->execute([$migrationRef,$migrationAccountId,$openYearId,$requester,$approver]);
    $actionId = (int)$db->lastInsertId();
    $migration = $db->prepare("INSERT INTO legacy_migrations (migration_reference,legacy_account_id,action_request_id,migration_status,balance_at_migration,migration_date,loan_application_id,requested_by,approved_by) VALUES (?, ?, ?, 'APPROVED',100,'2098-06-01',?,?,?)");
    $migration->execute([$migrationRef,$migrationAccountId,$actionId,$loanId,$requester,$approver]);
    $insertTx->execute([$migrationAccountId,$openYearId,'MIG-' . $migrationRef,'MIGRATION','0','100.0000','0','migration transfer',$migrationRef,$approver]);
    $db->prepare("UPDATE legacy_migrations SET migration_status='EXECUTED',loan_application_reference=?,executed_by=?,executed_at=CURRENT_TIMESTAMP WHERE action_request_id=?")
        ->execute(['P10-LOAN-' . $token,$approver,$actionId]);
    $db->prepare("UPDATE legacy_debt_accounts SET migration_status='MIGRATED',migration_date='2098-06-01',linked_loan_application_id=?,status='MIGRATED' WHERE legacy_account_id=?")
        ->execute([$loanId,$migrationAccountId]);
    $migrationBalances = $db->prepare('SELECT (SELECT SUM(debit_amount)-SUM(credit_amount) FROM legacy_transactions WHERE legacy_account_id=?) AS legacy_balance, (SELECT loan_amount FROM loan_applications WHERE id=?) AS normal_balance, (SELECT COUNT(*) FROM legacy_migrations WHERE legacy_account_id=? AND migration_status=\'EXECUTED\') AS migrations');
    $migrationBalances->execute([$migrationAccountId,$loanId,$migrationAccountId]);
    $moved = $migrationBalances->fetch();
    p10Assert(abs((float)$moved['legacy_balance']) < 0.00005 && abs((float)$moved['normal_balance']-100.0) < 0.00005, 'migration credits legacy ledger and preserves collectible balance in one normal loan');
    p10Assert((int)$moved['migrations'] === 1, 'one permanent migration relationship exists');
    $view = $db->query("SELECT COUNT(*) FROM information_schema.views WHERE table_schema=DATABASE() AND table_name='v_unified_loans'")->fetchColumn();
    if ((int)$view === 1) {
        $visible = $db->prepare("SELECT COUNT(*) FROM v_unified_loans WHERE application_id IN (?,?)");
        $visible->execute(['P10-LOAN-' . $token,'LEGACY-P10-MIG-ACC-' . $token]);
        p10Assert((int)$visible->fetchColumn() === 1, 'unified loan view shows migrated normal exposure once and omits migrated legacy collectible row');
    }
} finally {
    if ($db->inTransaction()) $db->rollBack();
}

echo "PHASE 10 DATABASE INVARIANTS PASSED (all fixtures rolled back)\n";
