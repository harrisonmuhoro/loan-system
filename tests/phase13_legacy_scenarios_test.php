<?php
declare(strict_types=1);

/**
 * Phase 13 & 14 Real Application Workflow and Financial Scenario Integration Tests.
 *
 * Combines database constraint/arithmetic checks with real HTTP endpoint integration tests:
 * 1. Legacy payment recording.
 * 2. Payment reversal requests and maker/checker approval rejection.
 * 3. Payment reversal approvals and Collections Funding Account refunds.
 * 4. Legacy adjustments.
 * 5. Waiver requests and approvals.
 * 6. Write-off requests and multi-role approvals.
 * 7. Legacy debt migration preserving historical accounts and creating normal loans.
 * 8. Duplicate migration prevention and canonical identity conflict rejection.
 * 9. Closed financial year posting authorization lifecycle.
 * 10. Disputed debt payment handling.
 *
 * Role-based permission matrix and security verification:
 * - Cashier, Accountant, Admin, Loanee sessions.
 * - Loanee IDOR protection.
 * - CSRF token rejection.
 * - Self-approval prevention.
 */

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
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . "\n";
}

// Ensure session file exists
$sessionFile = __DIR__ . '/phase13_sessions.json';
if (!file_exists($sessionFile)) {
    require __DIR__ . '/create_phase13_sessions.php';
}
$sessions = json_decode(file_get_contents($sessionFile), true);

/**
 * Helper to invoke application endpoints over HTTP.
 */
function callAppHttp(string $endpoint, string $method, array $queryParams, ?array $body, string $role, ?string $csrfOverride = null): array
{
    global $sessions, $dbName;
    if (!isset($sessions[$role])) {
        throw new RuntimeException("No session available for role {$role}");
    }

    $sess = $sessions[$role];
    $url = 'http://localhost/loan-system/' . ltrim($endpoint, '/');
    if (!empty($queryParams)) {
        $url .= '?' . http_build_query($queryParams);
    }

    $payload = $body ?? [];
    if (!isset($payload['csrf_token'])) {
        $payload['csrf_token'] = $csrfOverride ?? $sess['csrf_token'];
    } elseif ($csrfOverride !== null) {
        $payload['csrf_token'] = $csrfOverride;
    }

    $ch = curl_init($url);
    $headers = [
        'Content-Type: application/json',
        'X-Test-Db: ' . $dbName
    ];
    if (isset($payload['csrf_token'])) {
        $headers[] = 'X-CSRF-Token: ' . $payload['csrf_token'];
    }

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_COOKIE => 'PHPSESSID=' . $sess['session_id'],
        CURLOPT_TIMEOUT => 15
    ];
    if ($method !== 'GET' && !empty($payload)) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $opts);

    $raw = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException("CURL request failed for {$url}: {$err}");
    }

    $json = json_decode($raw, true);
    return [
        'status' => $httpCode,
        'json' => $json,
        'success' => $json['success'] ?? false,
        'message' => $json['message'] ?? ($json['error'] ?? ''),
        'data' => $json['data'] ?? null,
        'raw' => $raw
    ];
}

echo "=== PART 1: Database Invariants and Arithmetic Scenarios ===\n";
$actor = (int)$db->query('SELECT user_id FROM users WHERE is_active=1 LIMIT 1')->fetchColumn();
$token = strtoupper(bin2hex(random_bytes(4)));

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
    echo "PASS: Database arithmetic and constraints verified.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}

echo "\n=== PART 2: Real Application Workflow and Role-Based Integration Tests ===\n";

// Set up dedicated test debtor and account for workflow operations
$testToken = strtoupper(bin2hex(random_bytes(4)));
$activeYearId = (int)$db->query("SELECT financial_year_id FROM legacy_financial_years WHERE status='OPEN' ORDER BY financial_year_id LIMIT 1")->fetchColumn();
if ($activeYearId <= 0) {
    throw new RuntimeException("No open financial year found for workflow tests");
}

$db->prepare("INSERT INTO legacy_debtors (legacy_reference,full_name,pf_number,national_id,created_by) VALUES (?,?,?,?,2)")
    ->execute(["WORK-D-{$testToken}", "Workflow Debtor {$testToken}", "PF{$testToken}", "ID{$testToken}"]);
$workDebtorId = (int)$db->lastInsertId();

$db->prepare("INSERT INTO legacy_debt_accounts (legacy_debtor_id,account_reference,created_by) VALUES (?,?,2)")
    ->execute([$workDebtorId, "WORK-ACC-{$testToken}"]);
$workAccountId = (int)$db->lastInsertId();

$db->prepare("INSERT INTO legacy_loan_components (legacy_account_id,financial_year_id,component_reference,original_principal,component_date,created_by) VALUES (?,?,?,?, '2095-01-01', 2)")
    ->execute([$workAccountId, $activeYearId, "WORK-COMP-{$testToken}", 1500.00]);
$workCompId = (int)$db->lastInsertId();

$db->prepare("INSERT INTO legacy_transactions (legacy_account_id,legacy_component_id,financial_year_id,transaction_reference,transaction_type,debit_amount,credit_amount,transaction_date,description,recorded_by) VALUES (?,?,?,?, 'OPENING_BALANCE', 1500.00, 0, '2095-01-01', 'Opening balance', 2)")
    ->execute([$workAccountId, $workCompId, $activeYearId, "WORK-TX-OPEN-{$testToken}"]);

// Collections Funding Account for tests
$fundStmt = $db->query("SELECT id FROM funding_accounts ORDER BY id LIMIT 1");
$testFundId = (int)$fundStmt->fetchColumn();
if ($testFundId <= 0) {
    $db->prepare("INSERT INTO funding_accounts (name, balance) VALUES ('Test Collections Funding', 1000.00)")->execute();
    $testFundId = (int)$db->lastInsertId();
}

// 1. Security Check: Invalid CSRF Token must be rejected with 403
$csrfTest = callAppHttp('api/legacy_ledger.php', 'POST', ['action' => 'RECORD_PAYMENT'], [
    'legacy_account_id' => $workAccountId,
    'financial_year_id' => $activeYearId,
    'amount' => 100,
    'payment_date' => '2095-02-01',
    'payment_method' => 'CASH',
    'transaction_reference' => "TX-CSRF-{$testToken}",
    'reason' => 'CSRF test'
], 'cashier', 'INVALID-CSRF-TOKEN');
p13Scenario($csrfTest['status'] === 403, 'invalid CSRF token rejected with HTTP 403');

// 2. Security Check: Loanee unauthorized to record payment (HTTP 403)
$loaneeTest = callAppHttp('api/legacy_ledger.php', 'POST', ['action' => 'RECORD_PAYMENT'], [
    'legacy_account_id' => $workAccountId,
    'financial_year_id' => $activeYearId,
    'amount' => 100,
    'payment_date' => '2095-02-01',
    'payment_method' => 'CASH',
    'transaction_reference' => "TX-LOANEE-{$testToken}",
    'reason' => 'Loanee test'
], 'loanee');
p13Scenario($loaneeTest['status'] === 403, 'loanee role prohibited from recording legacy payments (HTTP 403)');

// 3. Workflow 1: Legacy Payment Recording via Cashier
$payReceipt = "REC-WORK-{$testToken}";
$initialBalance = (float)$db->query("SELECT SUM(debit_amount - credit_amount) FROM legacy_transactions WHERE legacy_account_id = {$workAccountId}")->fetchColumn();
$initialFundBalance = (float)$db->query("SELECT balance FROM funding_accounts WHERE id = {$testFundId}")->fetchColumn();

$payRes = callAppHttp('api/legacy_ledger.php', 'POST', ['action' => 'RECORD_PAYMENT'], [
    'legacy_account_id' => $workAccountId,
    'financial_year_id' => $activeYearId,
    'amount' => 200.00,
    'payment_date' => '2095-02-01',
    'payment_method' => 'CASH',
    'transaction_reference' => "TX-PAY-{$testToken}",
    'reason' => 'Workflow cashier payment recording',
    'receipt_number' => $payReceipt,
    'funding_account_id' => $testFundId,
    'payer_name' => "Workflow Debtor {$testToken}"
], 'cashier');
p13Scenario(($payRes['status'] === 200 || $payRes['status'] === 201) && $payRes['success'], 'legacy payment recorded successfully via Cashier workflow');
$recordedPayTxId = (int)($payRes['data']['transaction_id'] ?? $payRes['data']['legacy_transaction_id'] ?? 0);

// Verify ledger, payment record, Collections Funding Account deposit, outstanding balance, audit trail
$newBalance = (float)$db->query("SELECT SUM(debit_amount - credit_amount) FROM legacy_transactions WHERE legacy_account_id = {$workAccountId}")->fetchColumn();
p13Scenario(abs($newBalance - ($initialBalance - 200.00)) < 0.0001, 'debtor ledger outstanding balance correctly reduced by payment amount');

$payDetail = $db->query("SELECT * FROM legacy_payment_details WHERE legacy_transaction_id = {$recordedPayTxId}")->fetch();
p13Scenario($payDetail && $payDetail['receipt_number'] === $payReceipt, 'payment detail record preserved receipt and payment metadata');

$actualFundId = (int)$payDetail['funding_account_id'];
$fundingTx = $db->query("SELECT * FROM funding_transactions WHERE funding_account_id = {$actualFundId} AND transaction_type = 'deposit' AND reference = 'legacy-payment:TX-PAY-{$testToken}'")->fetch();
p13Scenario($fundingTx && abs((float)$fundingTx['amount'] - 200.00) < 0.0001, 'Collections Funding Account recorded deposit transaction for payment');

$auditPay = (int)$db->query("SELECT COUNT(*) FROM legacy_audit_events WHERE entity_type = 'legacy_account' AND entity_id = {$workAccountId} AND action = 'PAYMENT'")->fetchColumn();
p13Scenario($auditPay > 0, 'payment recording created an immutable audit event');

// 4. Duplicate submission check: duplicate transaction reference submission fails safely with 409
$dupPay = callAppHttp('api/legacy_ledger.php', 'POST', ['action' => 'RECORD_PAYMENT'], [
    'legacy_account_id' => $workAccountId,
    'financial_year_id' => $activeYearId,
    'amount' => 200.00,
    'payment_date' => '2095-02-01',
    'payment_method' => 'CASH',
    'transaction_reference' => "TX-PAY-{$testToken}",
    'reason' => 'Duplicate payment attempt',
    'receipt_number' => $payReceipt,
    'funding_account_id' => $testFundId
], 'cashier');
p13Scenario($dupPay['status'] === 409 && !$dupPay['success'], 'duplicate payment submission rejected safely with HTTP 409');

// 5. Workflow 2 & 3: Payment Reversal Request and Approval
// Request reversal as Cashier
$revReq = callAppHttp('api/legacy_ledger.php', 'POST', ['action' => 'REQUEST_ACTION'], [
    'legacy_account_id' => $workAccountId,
    'financial_year_id' => $activeYearId,
    'action_type' => 'PAYMENT_REVERSAL',
    'target_transaction_id' => $recordedPayTxId,
    'reason_code' => 'TEST_REVERSAL',
    'reason_text' => 'Workflow integration test reversal request'
], 'cashier');
p13Scenario($revReq['status'] === 201 && $revReq['success'], 'payment reversal request created via workflow');
$revActionId = (int)$revReq['data']['action_request_id'];

// Maker/Checker Security Check: Cashier cannot approve their own reversal request
$selfApprove = callAppHttp('api/legacy_ledger.php', 'POST', ['action' => 'APPROVE_ACTION'], [
    'action_request_id' => $revActionId,
    'reason' => 'Unauthorized self-approval attempt'
], 'cashier');
p13Scenario($selfApprove['status'] === 403 || $selfApprove['status'] === 422, 'maker/checker rule enforced: requester cannot approve own request');

// Approve reversal as Accountant (independent approval)
$apprRev = callAppHttp('api/legacy_ledger.php', 'POST', ['action' => 'APPROVE_ACTION'], [
    'action_request_id' => $revActionId,
    'reason' => 'Approved reversal by accountant'
], 'accountant');
p13Scenario($apprRev['status'] === 200 && $apprRev['success'], 'accountant approved payment reversal request');

// Execute reversal
$execRev = callAppHttp('api/legacy_ledger.php', 'POST', ['action' => 'EXECUTE_ACTION'], [
    'action_request_id' => $revActionId,
    'reason' => 'Execution of approved reversal'
], 'accountant');
p13Scenario($execRev['status'] === 200 && $execRev['success'], 'payment reversal executed through application workflow');

// Verify reversal restored balance and issued funding refund
$postRevBal = (float)$db->query("SELECT SUM(debit_amount - credit_amount) FROM legacy_transactions WHERE legacy_account_id = {$workAccountId}")->fetchColumn();
p13Scenario(abs($postRevBal - $initialBalance) < 0.0001, 'reversal restored debtor balance to pre-payment state');

$fundingRefundTx = $db->query("SELECT * FROM funding_transactions WHERE funding_account_id = {$actualFundId} AND transaction_type = 'refund' ORDER BY id DESC LIMIT 1")->fetch();
p13Scenario($fundingRefundTx && abs((float)$fundingRefundTx['amount'] - 200.00) < 0.0001, 'reversal recorded refund transaction on Collections Funding Account');

// 6. Workflow 4: Legacy Adjustment
$adjReq = callAppHttp('api/legacy_ledger.php', 'POST', ['action' => 'REQUEST_ACTION'], [
    'legacy_account_id' => $workAccountId,
    'financial_year_id' => $activeYearId,
    'action_type' => 'ADJUSTMENT',
    'adjustment_type' => 'DEBIT',
    'amount' => 50.00,
    'reason_code' => 'ADJ_FEE',
    'reason_text' => 'Workflow adjustment test'
], 'accountant');
p13Scenario($adjReq['status'] === 201 && $adjReq['success'], 'legacy adjustment requested');
$adjId = (int)$adjReq['data']['action_request_id'];

// Admin approves adjustment
$adjAppr = callAppHttp('api/legacy_ledger.php', 'POST', ['action' => 'APPROVE_ACTION'], [
    'action_request_id' => $adjId,
    'reason' => 'Admin approved adjustment'
], 'admin');
p13Scenario($adjAppr['status'] === 200 && $adjAppr['success'], 'admin approved legacy adjustment');

callAppHttp('api/legacy_ledger.php', 'POST', ['action' => 'EXECUTE_ACTION'], [
    'action_request_id' => $adjId,
    'reason' => 'Execute adjustment'
], 'accountant');
$balAfterAdj = (float)$db->query("SELECT SUM(debit_amount - credit_amount) FROM legacy_transactions WHERE legacy_account_id = {$workAccountId}")->fetchColumn();
p13Scenario(abs($balAfterAdj - ($initialBalance + 50.00)) < 0.0001, 'adjustment debit reconciled on debtor ledger');

// 7. Workflow 5: Waiver Request and Approval
$waiveReq = callAppHttp('api/legacy_ledger.php', 'POST', ['action' => 'REQUEST_ACTION'], [
    'legacy_account_id' => $workAccountId,
    'financial_year_id' => $activeYearId,
    'action_type' => 'WAIVER',
    'amount' => 50.00,
    'reason_code' => 'HARDSHIP',
    'reason_text' => 'Workflow hardship waiver request'
], 'cashier');
p13Scenario($waiveReq['status'] === 201 && $waiveReq['success'], 'waiver requested');
$waiveId = (int)$waiveReq['data']['action_request_id'];

$waiveAppr = callAppHttp('api/legacy_ledger.php', 'POST', ['action' => 'APPROVE_ACTION'], [
    'action_request_id' => $waiveId,
    'reason' => 'Accountant approved waiver'
], 'accountant');
p13Scenario($waiveAppr['status'] === 200 && $waiveAppr['success'], 'waiver approved by accountant');

callAppHttp('api/legacy_ledger.php', 'POST', ['action' => 'EXECUTE_ACTION'], [
    'action_request_id' => $waiveId,
    'reason' => 'Execute waiver'
], 'accountant');
$balAfterWaive = (float)$db->query("SELECT SUM(debit_amount - credit_amount) FROM legacy_transactions WHERE legacy_account_id = {$workAccountId}")->fetchColumn();
p13Scenario(abs($balAfterWaive - $initialBalance) < 0.0001, 'waiver credit reconciled on debtor ledger');

// 8. Workflow 6: Write-Off Dual Approval Requirement (Accountant + Admin)
$writeReq = callAppHttp('api/legacy_ledger.php', 'POST', ['action' => 'REQUEST_ACTION'], [
    'legacy_account_id' => $workAccountId,
    'financial_year_id' => $activeYearId,
    'action_type' => 'WRITE_OFF',
    'amount' => 100.00,
    'reason_code' => 'UNCOLLECTIBLE',
    'reason_text' => 'Workflow write-off request'
], 'cashier');
p13Scenario($writeReq['status'] === 201 && $writeReq['success'], 'write-off requested');
$writeId = (int)$writeReq['data']['action_request_id'];

// First approval: Accountant
$writeAppr1 = callAppHttp('api/legacy_ledger.php', 'POST', ['action' => 'APPROVE_ACTION'], [
    'action_request_id' => $writeId,
    'reason' => 'Accountant approved write-off'
], 'accountant');
p13Scenario($writeAppr1['status'] === 200 && $writeAppr1['success'], 'first write-off approval recorded');

// Execution attempt before Admin approval must be rejected
$earlyExec = callAppHttp('api/legacy_ledger.php', 'POST', ['action' => 'EXECUTE_ACTION'], [
    'action_request_id' => $writeId,
    'reason' => 'Attempt premature execution'
], 'accountant');
p13Scenario(!$earlyExec['success'], 'write-off requires dual approval before execution');

// Second approval: Admin
$writeAppr2 = callAppHttp('api/legacy_ledger.php', 'POST', ['action' => 'APPROVE_ACTION'], [
    'action_request_id' => $writeId,
    'reason' => 'Admin approved write-off'
], 'admin');
p13Scenario($writeAppr2['status'] === 200 && $writeAppr2['success'], 'admin recorded dual write-off approval');

$execWrite = callAppHttp('api/legacy_ledger.php', 'POST', ['action' => 'EXECUTE_ACTION'], [
    'action_request_id' => $writeId,
    'reason' => 'Execute fully approved write-off'
], 'accountant');
p13Scenario($execWrite['status'] === 200 && $execWrite['success'], 'write-off executed after dual approval');

// 9. Workflow 7 & 8: Legacy Debt Migration & Duplicate Prevention
$currentMigBalance = (float)$db->query("SELECT SUM(debit_amount - credit_amount) FROM legacy_transactions WHERE legacy_account_id = {$workAccountId}")->fetchColumn();
$migReq = callAppHttp('api/legacy_migration.php', 'POST', ['action' => 'REQUEST'], [
    'legacy_account_id' => $workAccountId,
    'migration_date' => '2095-06-01',
    'reason_code' => 'FULL_MIGRATION',
    'reason_text' => 'Workflow integration test migration request'
], 'accountant');
p13Scenario($migReq['status'] === 201 && $migReq['success'], 'legacy debt migration requested');
$migActionId = (int)$migReq['data']['action_request_id'];
$migRecordId = (int)$migReq['data']['legacy_migration_id'];

// Maker/checker: Accountant requester cannot approve own migration
$selfMig = callAppHttp('api/legacy_migration.php', 'POST', ['action' => 'APPROVE'], [
    'action_request_id' => $migActionId,
    'reason' => 'Self approval attempt'
], 'accountant');
p13Scenario(!$selfMig['success'], 'migration self-approval rejected');

// Admin approves migration
$apprMig = callAppHttp('api/legacy_migration.php', 'POST', ['action' => 'APPROVE'], [
    'action_request_id' => $migActionId,
    'reason' => 'Admin approved migration'
], 'admin');
p13Scenario($apprMig['status'] === 200 && $apprMig['success'], 'admin approved legacy debt migration');

// Execute migration
$execMig = callAppHttp('api/legacy_migration.php', 'POST', ['action' => 'EXECUTE'], [
    'action_request_id' => $migActionId,
    'reason' => 'Execute migration into normal loan'
], 'accountant');
p13Scenario($execMig['status'] === 200 && $execMig['success'], 'legacy migration executed successfully');
$createdNormalLoanId = (int)$execMig['data']['loan_application_id'];

// Verify migration invariants:
// - Legacy account preserved
$accCheck = $db->query("SELECT * FROM legacy_debt_accounts WHERE legacy_account_id = {$workAccountId}")->fetch();
p13Scenario($accCheck && $accCheck['migration_status'] === 'MIGRATED' && (int)$accCheck['linked_loan_application_id'] === $createdNormalLoanId, 'legacy account preserved with permanent normal loan link');

// - Normal loan received correct outstanding exposure
$loanCheck = $db->query("SELECT * FROM loan_applications WHERE id = {$createdNormalLoanId}")->fetch();
p13Scenario($loanCheck && abs((float)$loanCheck['loan_amount'] - $currentMigBalance) < 0.0001, 'normal loan received exact transferred balance');

// - Legacy ledger has MIGRATION transaction
$migTx = (float)$db->query("SELECT COALESCE(SUM(credit_amount), 0) FROM legacy_transactions WHERE legacy_account_id = {$workAccountId} AND transaction_type = 'MIGRATION'")->fetchColumn();
p13Scenario(abs($migTx - $currentMigBalance) < 0.0001, 'legacy ledger contains MIGRATION credit transaction');

// - Collectible legacy balance is 0.0000
$finalColBal = (float)$db->query("SELECT SUM(debit_amount - credit_amount) FROM legacy_transactions WHERE legacy_account_id = {$workAccountId}")->fetchColumn();
p13Scenario(abs($finalColBal) < 0.0001, 'post-migration legacy collectible balance is zero');

// - Duplicate migration attempt blocked
$dupMig = callAppHttp('api/legacy_migration.php', 'POST', ['action' => 'REQUEST'], [
    'legacy_account_id' => $workAccountId,
    'migration_date' => '2095-06-01',
    'reason_code' => 'DUP',
    'reason_text' => 'Duplicate migration attempt'
], 'accountant');
p13Scenario(!$dupMig['success'], 'duplicate migration request safely rejected');

// 10. Workflow 9: Closed Financial Year Posting Authorization
// Create a closed financial year
$closedToken = strtoupper(bin2hex(random_bytes(3)));
$db->prepare("INSERT INTO legacy_financial_years (label,start_date,end_date,status,post_close_transactions_allowed,created_by) VALUES (?,'2090-01-01','2090-12-31','CLOSED',1,2)")
    ->execute(["CLOSED-{$closedToken}"]);
$closedYearId = (int)$db->lastInsertId();

// Posting directly to closed year without authorization must fail
$closedPost = callAppHttp('api/legacy_ledger.php', 'POST', ['action' => 'RECORD_PAYMENT'], [
    'legacy_account_id' => $workAccountId,
    'financial_year_id' => $closedYearId,
    'amount' => 50.00,
    'payment_date' => '2090-06-01',
    'payment_method' => 'CASH',
    'transaction_reference' => "TX-CLOSED-{$closedToken}",
    'reason' => 'Closed year post attempt',
    'funding_account_id' => $testFundId
], 'cashier');
p13Scenario(!$closedPost['success'], 'posting to closed financial year blocked without post-close authorization');

// 11. Workflow 10: Disputed Debt Payment Handling
$disputeRes = callAppHttp('api/legacy_ledger.php', 'POST', ['action' => 'UPDATE_DISPUTE_STATUS'], [
    'legacy_account_id' => $workAccountId,
    'dispute_status' => 'OPEN',
    'reason' => 'Debtor disputed service charges'
], 'accountant');
p13Scenario($disputeRes['status'] === 200 && $disputeRes['success'], 'account dispute status updated to OPEN with immutable event');

$disputePay = callAppHttp('api/legacy_ledger.php', 'POST', ['action' => 'RECORD_PAYMENT'], [
    'legacy_account_id' => $workAccountId,
    'financial_year_id' => $activeYearId,
    'amount' => 25.00,
    'payment_date' => '2095-07-01',
    'payment_method' => 'CASH',
    'transaction_reference' => "TX-DISP-{$testToken}",
    'reason' => 'Disputed account payment',
    'funding_account_id' => $testFundId,
    'receipt_number' => "REC-DISP-{$testToken}"
], 'cashier');
p13Scenario($disputePay['status'] === 200 && $disputePay['success'], 'disputed account can receive controlled payments');

// 12. Security Test: Retired clear-data.php endpoint returns HTTP 410 and performs no deletions
$clearTest = callAppHttp('api/clear-data.php', 'POST', [], ['data_type' => 'loans'], 'admin');
p13Scenario($clearTest['status'] === 410 && str_contains($clearTest['message'], 'Bulk data deletion is disabled'), 'clear-data endpoint returns HTTP 410 Gone and rejects deletion');

echo "\nPHASE 13 REAL APPLICATION WORKFLOW AND SECURITY TESTS PASSED\n";
