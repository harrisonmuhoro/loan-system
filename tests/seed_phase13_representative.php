<?php
declare(strict_types=1);

/** Seeds only legacy_test_phase13_prod; never point this at production. */
$dbName = getenv('LEGACY_TEST_DB') ?: '';
if ($dbName !== 'legacy_test_phase13_prod') {
    throw new RuntimeException('This seed script is restricted to legacy_test_phase13_prod');
}
$db = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('LEGACY_TEST_HOST') ?: '127.0.0.1', getenv('LEGACY_TEST_PORT') ?: '3306', $dbName),
    getenv('LEGACY_TEST_USER') ?: 'root', getenv('LEGACY_TEST_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$users = $db->query('SELECT user_id FROM users WHERE is_active=1 ORDER BY user_id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
if (count($users) < 2) throw new RuntimeException('The clone needs two active users');
[$requester, $approver] = array_map('intval', $users);
$token = strtoupper(bin2hex(random_bytes(5)));

$db->beginTransaction();
try {
    $db->prepare("INSERT INTO legacy_financial_years (label,start_date,end_date,status,post_close_transactions_allowed,created_by) VALUES ('P13-REP-2095','2095-01-01','2095-12-31','OPEN',0,?) ON DUPLICATE KEY UPDATE financial_year_id=LAST_INSERT_ID(financial_year_id)")->execute([$requester]);
    $year = (int)$db->lastInsertId();
    if ($year <= 0) $year = (int)$db->query("SELECT financial_year_id FROM legacy_financial_years WHERE label='P13-REP-2095'")->fetchColumn();

    $make = function (string $kind, float $opening, ?string $dispute = null) use ($db, $year, $requester, $approver, $token): int {
        static $n = 0;
        $n++;
        $suffix = $token . '-' . $n;
        $db->prepare('INSERT INTO legacy_debtors (legacy_reference,full_name,pf_number,national_id,created_by) VALUES (?,?,?,?,?)')
            ->execute(['P13-REP-D-' . $suffix, 'P13 Representative ' . $kind, 'P13' . $suffix, 'ID13' . $suffix, $requester]);
        $debtor = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO legacy_debt_accounts (legacy_debtor_id,account_reference,created_by) VALUES (?,?,?)')
            ->execute([$debtor, 'P13-REP-A-' . $suffix, $requester]);
        $account = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO legacy_loan_components (legacy_account_id,financial_year_id,component_reference,original_principal,component_date,created_by) VALUES (?,?,?,?,?,?)')
            ->execute([$account, $year, 'P13-REP-C-' . $suffix, $opening, '2095-01-01', $requester]);
        $component = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO legacy_transactions (legacy_account_id,legacy_component_id,financial_year_id,transaction_reference,transaction_type,debit_amount,credit_amount,transaction_date,description,recorded_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$account,$component,$year,'P13-REP-T-'.$suffix,'OPENING_BALANCE',$opening,0,'2095-01-01','representative opening balance',$requester]);
        if ($dispute !== null) {
            $db->prepare('UPDATE legacy_debt_accounts SET dispute_status=\'OPEN\' WHERE legacy_account_id=?')->execute([$account]);
            $db->prepare('INSERT INTO legacy_dispute_events (legacy_account_id,old_status,new_status,reason,actor_user_id) VALUES (?,?,?,?,?)')->execute([$account,'NONE','OPEN',$dispute,$requester]);
        }
        return $account;
    };

    $normal = $make('Normal', 1000);
    $overpaid = $make('Overpaid', 100);
    $disputed = $make('Disputed', 500, 'Representative dispute');
    $waived = $make('Waived', 300);
    $written = $make('WrittenOff', 400);
    $migrated = $make('Migrated', 1200);

    $tx = $db->prepare('INSERT INTO legacy_transactions (legacy_account_id,financial_year_id,transaction_reference,transaction_type,debit_amount,credit_amount,transaction_date,description,recorded_by) VALUES (?,?,?,?,?,?,?,?,?)');
    $tx->execute([$normal,$year,'P13-REP-PAY-'.$token,'PAYMENT',0,200,'2095-02-01','representative payment',$requester]);
    $normalPayId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO legacy_payment_details (legacy_transaction_id,payment_method,payment_date,transaction_number,reconciliation_state) VALUES (?, 'CASH','2095-02-01',?,'RECONCILED')")->execute([$normalPayId,'P13-REP-PAY-REC-'.$token]);
    $tx->execute([$overpaid,$year,'P13-REP-OVER-'.$token,'PAYMENT',0,150,'2095-02-01','representative overpayment',$requester]);
    $payId = (int)$db->lastInsertId();
    $fund = $db->prepare("INSERT INTO funding_accounts(name,balance) VALUES(?,?)");
    $fund->execute(['P13 Representative Collections ' . $token, 150]);
    $fundId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO legacy_payment_details (legacy_transaction_id,payment_method,payment_date,transaction_number,funding_account_id,reconciliation_state) VALUES (?, 'CASH','2095-02-01',?,?, 'RECONCILED')")->execute([$payId,'P13-REP-REC-'.$token,$fundId]);
    $db->prepare("INSERT INTO funding_transactions (funding_account_id,amount,transaction_type,reference,created_by) VALUES (?, 150, 'deposit', ?, ?)")->execute([$fundId, 'P13-REP-REC-'.$token, $requester]);
    $tx->execute([$overpaid,$year,'P13-REP-REV-'.$token,'PAYMENT_REVERSAL',150,0,'2095-03-01','representative reversal',$requester]);
    $db->prepare("INSERT INTO funding_transactions (funding_account_id,amount,transaction_type,reference,created_by) VALUES (?, 150, 'refund', ?, ?)")->execute([$fundId, 'REV-P13-REP-REC-'.$token, $requester]);
    $tx->execute([$disputed,$year,'P13-REP-DIS-PAY-'.$token,'PAYMENT',0,100,'2095-02-01','payment while disputed',$requester]);
    $disPayId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO legacy_payment_details (legacy_transaction_id,payment_method,payment_date,transaction_number,reconciliation_state) VALUES (?, 'CASH','2095-02-01',?,'RECONCILED')")->execute([$disPayId,'P13-REP-DIS-REC-'.$token]);
    $tx->execute([$waived,$year,'P13-REP-WAIVE-'.$token,'WAIVER',0,300,'2095-02-01','representative waiver',$requester]);
    $tx->execute([$written,$year,'P13-REP-WRITE-'.$token,'WRITE_OFF',0,400,'2095-02-01','representative write-off',$requester]);

    $db->prepare("INSERT INTO legacy_action_requests (request_reference,legacy_account_id,financial_year_id,action_type,posting_type,requested_amount,reason_code,reason_text,status,requested_by,approved_by,approved_at) VALUES (?,?,?,?,?,?,?,'representative approval','APPROVED',?,?,CURRENT_TIMESTAMP)")
        ->execute(['P13-REP-MIG-REQ-'.$token,$migrated,$year,'MIGRATION',null,1200,'P13',$requester,$approver]);
    $action = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO loan_applications (user_id,application_id,full_name,id_number,pf_number,loan_type,loan_amount,payment_duration,payment_frequency,status,feedback) SELECT ?,?,?,?,?, 'mortgage',1200,12,'monthly','active','P13 representative migrated loan' FROM legacy_debtors WHERE legacy_debtor_id=(SELECT legacy_debtor_id FROM legacy_debt_accounts WHERE legacy_account_id=?)")
        ->execute([$requester,'P13-REP-LOAN-'.$token,'P13 Representative Migrated','ID13REP'.$token,'P13REP'.$token,$migrated]);
    $loan = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO legacy_migrations (migration_reference,legacy_account_id,action_request_id,migration_status,balance_at_migration,migration_date,loan_application_id,loan_application_reference,requested_by,approved_by,approved_at,executed_by,executed_at) VALUES (?,?,?,?,?,'2095-06-01',?,?,?, ?,CURRENT_TIMESTAMP,?,CURRENT_TIMESTAMP)")
        ->execute(['P13-REP-MIG-'.$token,$migrated,$action,'APPROVED',1200,$loan,'P13-REP-LOAN-'.$token,$requester,$approver,$approver]);
    $migrationId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO legacy_transactions (legacy_account_id,financial_year_id,transaction_reference,transaction_type,debit_amount,credit_amount,transaction_date,description,reason_code,post_close_approval_reference,recorded_by) VALUES (?,?,?,'MIGRATION',0,?,'2095-06-01','representative migration','MIGRATION',?,?)")
        ->execute([$migrated,$year,'P13-REP-MIG-TX-'.$token,1200,'P13-REP-MIG-'.$token,$approver]);
    $db->prepare("UPDATE legacy_migrations SET migration_status='EXECUTED' WHERE legacy_migration_id=?")->execute([$migrationId]);
    $db->prepare('UPDATE legacy_debt_accounts SET migration_status=\'MIGRATED\',migration_date=\'2095-06-01\',linked_loan_application_id=?,status=\'MIGRATED\' WHERE legacy_account_id=?')->execute([$loan,$migrated]);

    $db->commit();
    echo "seeded_accounts=6\nseeded_migrated_loan={$loan}\nseed=committed\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}
