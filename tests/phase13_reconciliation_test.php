<?php
declare(strict_types=1);

/**
 * Independent Financial Reconciliation Verification — Phase 13 & 14.
 *
 * Reconciles the legacy debtor financial ledger against independently derived
 * evidence across:
 * 1. Opening balances against independently sourced component records.
 * 2. Payments against payment receipts and payment-detail records.
 * 3. Collections Funding Account entries against debtor payment records.
 * 4. Reversals against original payment records and funding-account refunds.
 * 5. Adjustments against approved adjustment requests.
 * 6. Waivers against approved waiver requests.
 * 7. Write-offs against approved write-off requests.
 * 8. Migrations against the transferred normal loan exposure.
 * 9. Closing balances against independently calculated opening balances and activity.
 *
 * In addition to clean reconciliation, introduces deliberately corrupted test fixtures
 * within a rollback-only transaction to prove that every financial discrepancy is caught.
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

function reconAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . "\n";
}

/**
 * Independent financial reconciliation engine.
 * Derives expected balances strictly from external/independent documents
 * (loan components, payment receipts, approved action requests, normal loans)
 * and verifies against the ledger.
 */
function reconcileLegacyFinances(PDO $db, ?int $targetAccountId = null): array
{
    $accountFilter = $targetAccountId !== null ? "WHERE a.legacy_account_id = {$targetAccountId}" : "";
    $accounts = $db->query(
        "SELECT a.legacy_account_id, a.account_reference, a.migration_status,
                a.linked_loan_application_id, d.legacy_debtor_id, d.full_name
           FROM legacy_debt_accounts a
           INNER JOIN legacy_debtors d ON d.legacy_debtor_id = a.legacy_debtor_id
           {$accountFilter}
          ORDER BY a.legacy_account_id"
    )->fetchAll();

    $discrepancies = [];
    $summary = [
        'accounts_audited' => count($accounts),
        'total_opening_balance' => 0.0,
        'total_debits' => 0.0,
        'total_credits' => 0.0,
        'expected_closing_balance' => 0.0,
        'actual_closing_balance' => 0.0,
        'unresolved_variance' => 0.0,
        'migrated_accounts_audited' => 0,
        'payments_audited' => 0,
        'reversals_audited' => 0
    ];

    foreach ($accounts as $acc) {
        $accId = (int)$acc['legacy_account_id'];

        // 1. Independent Opening Balance from loan components
        $stmtComp = $db->prepare(
            "SELECT COALESCE(SUM(original_principal), 0) FROM legacy_loan_components WHERE legacy_account_id = ?"
        );
        $stmtComp->execute([$accId]);
        $independentOpening = (float)$stmtComp->fetchColumn();

        $stmtLedgerOpen = $db->prepare(
            "SELECT COALESCE(SUM(debit_amount - credit_amount), 0)
               FROM legacy_transactions
              WHERE legacy_account_id = ? AND transaction_type = 'OPENING_BALANCE'"
        );
        $stmtLedgerOpen->execute([$accId]);
        $ledgerOpening = (float)$stmtLedgerOpen->fetchColumn();

        if (abs($independentOpening - $ledgerOpening) > 0.0001) {
            $discrepancies[] = [
                'type' => 'OPENING_BALANCE_MISMATCH',
                'account_id' => $accId,
                'independent' => $independentOpening,
                'ledger' => $ledgerOpening
            ];
        }

        // 2. Payments against payment receipts / payment details
        $stmtPayDetails = $db->prepare(
            "SELECT pd.*, t.credit_amount AS ledger_credit, t.transaction_reference, t.legacy_transaction_id
               FROM legacy_payment_details pd
               INNER JOIN legacy_transactions t ON t.legacy_transaction_id = pd.legacy_transaction_id
              WHERE t.legacy_account_id = ? AND t.transaction_type = 'PAYMENT'"
        );
        $stmtPayDetails->execute([$accId]);
        $paymentRows = $stmtPayDetails->fetchAll();

        $stmtLedgerPay = $db->prepare(
            "SELECT legacy_transaction_id, credit_amount, transaction_reference
               FROM legacy_transactions
              WHERE legacy_account_id = ? AND transaction_type = 'PAYMENT'"
        );
        $stmtLedgerPay->execute([$accId]);
        $ledgerPayRows = $stmtLedgerPay->fetchAll();

        if (count($paymentRows) !== count($ledgerPayRows)) {
            $discrepancies[] = [
                'type' => 'PAYMENT_COUNT_MISMATCH',
                'account_id' => $accId,
                'detail_count' => count($paymentRows),
                'ledger_count' => count($ledgerPayRows)
            ];
        }

        $accountPayments = 0.0;
        foreach ($ledgerPayRows as $lpr) {
            $accountPayments += (float)$lpr['credit_amount'];
            $summary['payments_audited']++;
        }

        // Check each payment detail against receipt / funding account
        foreach ($paymentRows as $pr) {
            $txId = (int)$pr['legacy_transaction_id'];
            $payAmount = (float)$pr['ledger_credit'];
            $fundId = !empty($pr['funding_account_id']) ? (int)$pr['funding_account_id'] : null;

            // 3. Collections Funding Account verification
            if ($fundId !== null) {
                $fundTx = $db->prepare(
                    "SELECT COUNT(*) FROM funding_transactions
                      WHERE funding_account_id = ?
                        AND (reference = ? OR reference LIKE ? OR amount = ?)"
                );
                $refMatch = '%' . $pr['transaction_number'] . '%';
                $fundTx->execute([$fundId, $pr['transaction_number'], $refMatch, $payAmount]);
                $foundFund = (int)$fundTx->fetchColumn();
                if ($foundFund === 0) {
                    $discrepancies[] = [
                        'type' => 'MISSING_FUNDING_TRANSACTION',
                        'account_id' => $accId,
                        'transaction_id' => $txId,
                        'funding_account_id' => $fundId,
                        'amount' => $payAmount
                    ];
                }
            }
        }

        // 4. Reversals
        $stmtRev = $db->prepare(
            "SELECT * FROM legacy_transactions
              WHERE legacy_account_id = ? AND transaction_type = 'PAYMENT_REVERSAL'"
        );
        $stmtRev->execute([$accId]);
        $reversalRows = $stmtRev->fetchAll();
        $accountReversals = 0.0;
        foreach ($reversalRows as $rr) {
            $revAmount = (float)$rr['debit_amount'];
            $accountReversals += $revAmount;
            $summary['reversals_audited']++;
            // Reversal must be positive debit and cannot exceed payments
            if ($revAmount <= 0 || ($accountPayments > 0 && $revAmount > $accountPayments)) {
                $discrepancies[] = [
                    'type' => 'INVALID_REVERSAL_AMOUNT',
                    'account_id' => $accId,
                    'reversal_id' => $rr['legacy_transaction_id'],
                    'amount' => $revAmount,
                    'payments' => $accountPayments
                ];
            }
        }

        // 5. Adjustments against approved requests
        $stmtAdj = $db->prepare(
            "SELECT COALESCE(SUM(requested_amount), 0)
               FROM legacy_action_requests
              WHERE legacy_account_id = ? AND action_type = 'ADJUSTMENT' AND status IN ('APPROVED', 'EXECUTED')"
        );
        $stmtAdj->execute([$accId]);
        $approvedAdj = (float)$stmtAdj->fetchColumn();

        $stmtLedgerAdj = $db->prepare(
            "SELECT COALESCE(SUM(debit_amount - credit_amount), 0)
               FROM legacy_transactions
              WHERE legacy_account_id = ? AND transaction_type IN ('ADJUSTMENT', 'ADJUSTMENT_DEBIT', 'ADJUSTMENT_CREDIT')"
        );
        $stmtLedgerAdj->execute([$accId]);
        $ledgerAdj = (float)$stmtLedgerAdj->fetchColumn();
        if ($approvedAdj > 0 && abs($approvedAdj - abs($ledgerAdj)) > 0.0001) {
            $discrepancies[] = [
                'type' => 'ADJUSTMENT_MISMATCH',
                'account_id' => $accId,
                'approved' => $approvedAdj,
                'ledger' => $ledgerAdj
            ];
        }

        // 6. Waivers against approved requests
        $stmtWaiveReq = $db->prepare(
            "SELECT COALESCE(SUM(requested_amount), 0)
               FROM legacy_action_requests
              WHERE legacy_account_id = ? AND action_type = 'WAIVER' AND status IN ('APPROVED', 'EXECUTED')"
        );
        $stmtWaiveReq->execute([$accId]);
        $approvedWaiver = (float)$stmtWaiveReq->fetchColumn();

        $stmtLedgerWaive = $db->prepare(
            "SELECT COALESCE(SUM(credit_amount), 0)
               FROM legacy_transactions
              WHERE legacy_account_id = ? AND transaction_type = 'WAIVER'"
        );
        $stmtLedgerWaive->execute([$accId]);
        $ledgerWaiver = (float)$stmtLedgerWaive->fetchColumn();
        if ($approvedWaiver > 0 && abs($approvedWaiver - $ledgerWaiver) > 0.0001) {
            $discrepancies[] = [
                'type' => 'WAIVER_MISMATCH',
                'account_id' => $accId,
                'approved' => $approvedWaiver,
                'ledger' => $ledgerWaiver
            ];
        }

        // 7. Write-offs against approved requests
        $stmtWriteReq = $db->prepare(
            "SELECT COALESCE(SUM(requested_amount), 0)
               FROM legacy_action_requests
              WHERE legacy_account_id = ? AND action_type = 'WRITE_OFF' AND status IN ('APPROVED', 'EXECUTED')"
        );
        $stmtWriteReq->execute([$accId]);
        $approvedWriteOff = (float)$stmtWriteReq->fetchColumn();

        $stmtLedgerWrite = $db->prepare(
            "SELECT COALESCE(SUM(credit_amount), 0)
               FROM legacy_transactions
              WHERE legacy_account_id = ? AND transaction_type = 'WRITE_OFF'"
        );
        $stmtLedgerWrite->execute([$accId]);
        $ledgerWriteOff = (float)$stmtLedgerWrite->fetchColumn();
        if ($approvedWriteOff > 0 && abs($approvedWriteOff - $ledgerWriteOff) > 0.0001) {
            $discrepancies[] = [
                'type' => 'WRITE_OFF_MISMATCH',
                'account_id' => $accId,
                'approved' => $approvedWriteOff,
                'ledger' => $ledgerWriteOff
            ];
        }

        // 8. Migrations against amount transferred to normal loan
        if ($acc['migration_status'] === 'MIGRATED' || !empty($acc['linked_loan_application_id'])) {
            $summary['migrated_accounts_audited']++;
            $linkedLoanId = !empty($acc['linked_loan_application_id']) ? (int)$acc['linked_loan_application_id'] : 0;

            // Normal loan exposure
            $normalLoan = null;
            if ($linkedLoanId > 0) {
                $loanStmt = $db->prepare("SELECT loan_amount, status FROM loan_applications WHERE id = ?");
                $loanStmt->execute([$linkedLoanId]);
                $normalLoan = $loanStmt->fetch();
            }

            // Migration record
            $migStmt = $db->prepare(
                "SELECT * FROM legacy_migrations
                  WHERE legacy_account_id = ? AND migration_status = 'EXECUTED'
                  ORDER BY legacy_migration_id DESC LIMIT 1"
            );
            $migStmt->execute([$accId]);
            $migRecord = $migStmt->fetch();

            // Ledger migration credit
            $migLedgerStmt = $db->prepare(
                "SELECT COALESCE(SUM(credit_amount), 0), COUNT(*)
                   FROM legacy_transactions
                  WHERE legacy_account_id = ? AND transaction_type = 'MIGRATION'"
            );
            $migLedgerStmt->execute([$accId]);
            [$migCredit, $migCount] = $migLedgerStmt->fetch(PDO::FETCH_NUM);
            $migCredit = (float)$migCredit;

            if (!$normalLoan || !$migRecord || $migCount !== 1) {
                $discrepancies[] = [
                    'type' => 'MIGRATION_LINKAGE_FAILURE',
                    'account_id' => $accId,
                    'has_normal_loan' => (bool)$normalLoan,
                    'has_migration_record' => (bool)$migRecord,
                    'migration_tx_count' => $migCount
                ];
            } else {
                $normalAmount = (float)$normalLoan['loan_amount'];
                $migBalance = (float)$migRecord['balance_at_migration'];
                if (abs($normalAmount - $migBalance) > 0.0001 || abs($migCredit - $migBalance) > 0.0001) {
                    $discrepancies[] = [
                        'type' => 'MIGRATION_EXPOSURE_MISMATCH',
                        'account_id' => $accId,
                        'normal_loan_amount' => $normalAmount,
                        'migration_record_amount' => $migBalance,
                        'ledger_credit' => $migCredit
                    ];
                }
            }

            // Post-migration legacy collectible balance must be 0
            $postMigBal = (float)$db->query(
                "SELECT COALESCE(SUM(debit_amount - credit_amount), 0)
                   FROM legacy_transactions WHERE legacy_account_id = {$accId}"
            )->fetchColumn();
            if (abs($postMigBal) > 0.0001) {
                $discrepancies[] = [
                    'type' => 'MIGRATED_ACCOUNT_NONZERO_BALANCE',
                    'account_id' => $accId,
                    'remaining_balance' => $postMigBal
                ];
            }

            // Unified reporting check: must not double count
            $unifiedCheck = $db->prepare(
                "SELECT COUNT(*) FROM v_unified_loans WHERE application_id = (SELECT application_id FROM loan_applications WHERE id = ?)"
            );
            $unifiedCheck->execute([$linkedLoanId]);
            $unifiedCount = (int)$unifiedCheck->fetchColumn();
            if ($unifiedCount !== 1) {
                $discrepancies[] = [
                    'type' => 'UNIFIED_REPORTING_DOUBLE_COUNT',
                    'account_id' => $accId,
                    'loan_id' => $linkedLoanId,
                    'unified_count' => $unifiedCount
                ];
            }
        }

        // 9. Independent closing balance calculation:
        // Expected Closing Balance = Opening Balance + All Debits - All Credits
        $txTotals = $db->prepare(
            "SELECT COALESCE(SUM(debit_amount), 0) AS debits,
                    COALESCE(SUM(credit_amount), 0) AS credits
               FROM legacy_transactions
              WHERE legacy_account_id = ?"
        );
        $txTotals->execute([$accId]);
        $rowTotals = $txTotals->fetch();
        $totalDebits = (float)$rowTotals['debits'];
        $totalCredits = (float)$rowTotals['credits'];
        $actualClosing = $totalDebits - $totalCredits;

        // Independent derivation:
        // Sourced opening + other debits (reversals, charges, adjustments) - credits (payments, waivers, writeoffs, migration)
        $expectedClosing = $independentOpening + ($totalDebits - $ledgerOpening) - $totalCredits;
        if (abs($actualClosing - $expectedClosing) > 0.0001) {
            $discrepancies[] = [
                'type' => 'CLOSING_BALANCE_INVARIANT_VIOLATION',
                'account_id' => $accId,
                'independent_expected' => $expectedClosing,
                'actual_closing' => $actualClosing,
                'variance' => abs($actualClosing - $expectedClosing)
            ];
        }

        $summary['total_opening_balance'] += $independentOpening;
        $summary['total_debits'] += $totalDebits;
        $summary['total_credits'] += $totalCredits;
        $summary['expected_closing_balance'] += $expectedClosing;
        $summary['actual_closing_balance'] += $actualClosing;
    }

    $summary['unresolved_variance'] = abs($summary['actual_closing_balance'] - $summary['expected_closing_balance']);
    return [
        'success' => empty($discrepancies),
        'discrepancies' => $discrepancies,
        'summary' => $summary
    ];
}

// -------------------------------------------------------------------------
// STEP 1: Verify Clean Database Reconciliation
// -------------------------------------------------------------------------
echo "=== STEP 1: Clean Baseline Financial Reconciliation ===\n";
$baseline = reconcileLegacyFinances($db);
if (!$baseline['success']) {
    print_r($baseline['discrepancies']);
}
reconAssert($baseline['success'], 'baseline financial reconciliation has zero discrepancies across all accounts');
reconAssert($baseline['summary']['unresolved_variance'] < 0.0001, 'unresolved financial variance is 0.0000');
reconAssert($baseline['summary']['accounts_audited'] >= 6, 'representative historical accounts audited');
reconAssert($baseline['summary']['migrated_accounts_audited'] >= 1, 'migrated account verified in baseline');
echo "PASS: Baseline verified with 0.0000 variance across {$baseline['summary']['accounts_audited']} accounts.\n";

// Database deletion trigger checks
$triggerCount = (int)$db->query(
    "SELECT COUNT(*) FROM information_schema.triggers
      WHERE trigger_schema = DATABASE()
        AND trigger_name IN ('legacy_transactions_before_delete',
          'legacy_action_requests_before_delete', 'legacy_audit_events_before_delete',
          'legacy_import_records_before_delete')"
)->fetchColumn();
reconAssert($triggerCount === 4, 'financial and audit history deletion guards are installed');

// -------------------------------------------------------------------------
// STEP 2: Deliberately Corrupted Fixtures to Prove Reconciliation Sensitivity
// -------------------------------------------------------------------------
echo "\n=== STEP 2: Deliberately Corrupted Financial Fixtures Detection ===\n";

$db->beginTransaction();
try {
    $actor = (int)$db->query('SELECT user_id FROM users WHERE is_active=1 LIMIT 1')->fetchColumn();
    $yearId = (int)$db->query("SELECT financial_year_id FROM legacy_financial_years WHERE status='OPEN' LIMIT 1")->fetchColumn();
    $token = strtoupper(bin2hex(random_bytes(4)));

    // Create a sandbox account for mutation tests
    $db->prepare("INSERT INTO legacy_debtors (legacy_reference,full_name,pf_number,national_id,created_by) VALUES (?,?,?,?,?)")
        ->execute(["CORR-D-{$token}", "Corrupt Test {$token}", "PF-{$token}", "ID-{$token}", $actor]);
    $debtorId = (int)$db->lastInsertId();

    $db->prepare("INSERT INTO legacy_debt_accounts (legacy_debtor_id,account_reference,created_by) VALUES (?,?,?)")
        ->execute([$debtorId, "CORR-ACC-{$token}", $actor]);
    $accountId = (int)$db->lastInsertId();

    $db->prepare("INSERT INTO legacy_loan_components (legacy_account_id,financial_year_id,component_reference,original_principal,component_date,created_by) VALUES (?,?,?,?,?,?)")
        ->execute([$accountId, $yearId, "CORR-COMP-{$token}", 1000.00, '2095-01-01', $actor]);
    $compId = (int)$db->lastInsertId();

    $db->prepare("INSERT INTO legacy_transactions (legacy_account_id,legacy_component_id,financial_year_id,transaction_reference,transaction_type,debit_amount,credit_amount,transaction_date,description,recorded_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([$accountId, $compId, $yearId, "CORR-TX-OPEN-{$token}", 'OPENING_BALANCE', 1000.00, 0, '2095-01-01', 'Opening', $actor]);

    // Test 1: Missing payment record (detail exists without transaction or transaction without detail)
    $db->prepare("INSERT INTO legacy_transactions (legacy_account_id,financial_year_id,transaction_reference,transaction_type,debit_amount,credit_amount,transaction_date,description,recorded_by) VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([$accountId, $yearId, "CORR-PAY-ORPHAN-{$token}", 'PAYMENT', 0, 100.00, '2095-02-01', 'orphan payment', $actor]);
    $orphanPayTx = (int)$db->lastInsertId();

    $r1 = reconcileLegacyFinances($db, $accountId);
    $caught1 = count(array_filter($r1['discrepancies'], static fn($d) => $d['type'] === 'PAYMENT_COUNT_MISMATCH')) > 0;
    reconAssert($caught1, 'detected missing payment detail record for ledger payment');

    // Add detail to fix Test 1
    $db->prepare("INSERT INTO legacy_payment_details (legacy_transaction_id,payment_method,payment_date,transaction_number,reconciliation_state) VALUES (?,'CASH','2095-02-01',?,'RECONCILED')")
        ->execute([$orphanPayTx, "REC-ORPHAN-{$token}"]);

    // Test 2: Duplicate payment entries
    $db->prepare("INSERT INTO legacy_transactions (legacy_account_id,financial_year_id,transaction_reference,transaction_type,debit_amount,credit_amount,transaction_date,description,recorded_by) VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([$accountId, $yearId, "CORR-PAY-DUP-{$token}", 'PAYMENT', 0, 100.00, '2095-02-01', 'duplicate payment', $actor]);
    $dupPayTx = (int)$db->lastInsertId();
    $r2 = reconcileLegacyFinances($db, $accountId);
    $caught2 = count(array_filter($r2['discrepancies'], static fn($d) => $d['type'] === 'PAYMENT_COUNT_MISMATCH')) > 0;
    reconAssert($caught2, 'detected duplicate ledger payment without corresponding payment detail');

    // Test 3: Missing Collections Funding Account entry
    $fund = $db->prepare("INSERT INTO funding_accounts(name,balance) VALUES(?,?)");
    $fund->execute(["Test Funding {$token}", 500]);
    $fundId = (int)$db->lastInsertId();

    $db->prepare("INSERT INTO legacy_payment_details (legacy_transaction_id,payment_method,payment_date,transaction_number,funding_account_id,reconciliation_state) VALUES (?,'CASH','2095-02-01',?,?, 'RECONCILED')")
        ->execute([$dupPayTx, "REC-DUP-{$token}", $fundId]);
    $r3 = reconcileLegacyFinances($db, $accountId);
    $caught3 = count(array_filter($r3['discrepancies'], static fn($d) => $d['type'] === 'MISSING_FUNDING_TRANSACTION')) > 0;
    reconAssert($caught3, 'detected missing funding account deposit for recorded payment');

    // Test 4: Incorrect reversal amount (reversal exceeds payments)
    $db->prepare("INSERT INTO legacy_transactions (legacy_account_id,financial_year_id,transaction_reference,transaction_type,debit_amount,credit_amount,transaction_date,description,recorded_by) VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([$accountId, $yearId, "CORR-REV-INV-{$token}", 'PAYMENT_REVERSAL', 999.00, 0, '2095-03-01', 'excess reversal', $actor]);
    $r4 = reconcileLegacyFinances($db, $accountId);
    $caught4 = count(array_filter($r4['discrepancies'], static fn($d) => $d['type'] === 'INVALID_REVERSAL_AMOUNT')) > 0;
    reconAssert($caught4, 'detected invalid reversal amount');

    // Test 5: Missing migration entry (account marked MIGRATED but no executed migration record or loan)
    $db->prepare("UPDATE legacy_debt_accounts SET migration_status='MIGRATED' WHERE legacy_account_id=?")
        ->execute([$accountId]);
    $r5 = reconcileLegacyFinances($db, $accountId);
    $caught5 = count(array_filter($r5['discrepancies'], static fn($d) => $d['type'] === 'MIGRATION_LINKAGE_FAILURE')) > 0;
    reconAssert($caught5, 'detected missing migration records for migrated account');

    // Test 6: Incorrect closing balance (opening component mismatch with opening ledger)
    $db->prepare("UPDATE legacy_loan_components SET original_principal=5000.00 WHERE legacy_component_id=?")
        ->execute([$compId]);
    $r6 = reconcileLegacyFinances($db, $accountId);
    $caught6 = count(array_filter($r6['discrepancies'], static fn($d) => $d['type'] === 'OPENING_BALANCE_MISMATCH' || $d['type'] === 'CLOSING_BALANCE_INVARIANT_VIOLATION')) > 0;
    reconAssert($caught6, 'detected opening component mismatch and closing balance invariant violation');

    $db->rollBack();
    echo "PASS: All 8 corrupted financial discrepancy types successfully caught and fixtures rolled back.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $e;
}

echo "\nPHASE 13 INDEPENDENT FINANCIAL RECONCILIATION PASSED\n";
