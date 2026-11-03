<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/legacy_authorization.php';

header('Content-Type: application/json');

function legacyDashboardResponse(bool $success, string $message, $data = null, int $status = 200): void
{
    http_response_code($status);
    $payload = ['success' => $success, 'message' => $message];
    if ($data !== null) $payload['data'] = $data;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') legacyDashboardResponse(false, 'Method not allowed', null, 405);
    $db = (new Database())->getConnection();
    legacyRequirePermission($db, 'legacy.view');

    $summary = $db->query(
        "SELECT
            COUNT(*) AS total_accounts,
            COALESCE(SUM((SELECT COALESCE(SUM(t.debit_amount)-SUM(t.credit_amount),0)
                          FROM legacy_transactions t WHERE t.legacy_account_id=a.legacy_account_id)),0) AS outstanding_balance,
            SUM(CASE WHEN a.dispute_status='OPEN' OR a.status='DISPUTED' THEN 1 ELSE 0 END) AS disputed_accounts,
            SUM(CASE WHEN a.status='OVERDUE' THEN 1 ELSE 0 END) AS overdue_accounts,
            SUM(CASE WHEN a.migration_status='MIGRATED' THEN 1 ELSE 0 END) AS migrated_accounts
         FROM legacy_debt_accounts a"
    )->fetch(PDO::FETCH_ASSOC);

    $payment = $db->query(
        "SELECT COUNT(*) AS payment_count, COALESCE(SUM(t.credit_amount),0) AS payment_amount
           FROM legacy_transactions t WHERE t.transaction_type='PAYMENT' AND t.status='POSTED'"
    )->fetch(PDO::FETCH_ASSOC);
    $pending = $db->query(
        "SELECT COUNT(*) FROM legacy_action_requests WHERE status IN ('REQUESTED','UNDER_REVIEW')"
    )->fetchColumn();
    $reconciliation = $db->query(
        "SELECT reconciliation_run_id,status,source_count,matched_count,mismatch_count,completed_at
           FROM legacy_reconciliation_runs ORDER BY reconciliation_run_id DESC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC) ?: null;

    $accounts = $db->query(
        "SELECT a.legacy_account_id,a.account_reference,a.status,a.dispute_status,a.migration_status,
                d.full_name,d.pf_number,d.national_id,
                COALESCE((SELECT SUM(t.debit_amount)-SUM(t.credit_amount)
                            FROM legacy_transactions t WHERE t.legacy_account_id=a.legacy_account_id),0) AS balance
           FROM legacy_debt_accounts a INNER JOIN legacy_debtors d ON d.legacy_debtor_id=a.legacy_debtor_id
          ORDER BY a.updated_at DESC, a.legacy_account_id DESC LIMIT 250"
    )->fetchAll(PDO::FETCH_ASSOC);

    $actions = $db->query(
        "SELECT r.action_request_id,r.request_reference,r.action_type,r.requested_amount,r.reason_text,
                r.status,r.requested_by,r.created_at,a.account_reference,d.full_name
           FROM legacy_action_requests r
           INNER JOIN legacy_debt_accounts a ON a.legacy_account_id=r.legacy_account_id
           INNER JOIN legacy_debtors d ON d.legacy_debtor_id=a.legacy_debtor_id
          WHERE r.status IN ('REQUESTED','UNDER_REVIEW')
          ORDER BY r.created_at DESC LIMIT 100"
    )->fetchAll(PDO::FETCH_ASSOC);

    $years = $db->query("SELECT financial_year_id,label,status FROM legacy_financial_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_ASSOC);
    $batches = $db->query(
        "SELECT import_batch_id,batch_reference,source_filename,status,detected_count,valid_count,
                imported_count,rejected_count,duplicate_count,uploaded_at,completed_at
           FROM legacy_import_batches ORDER BY import_batch_id DESC LIMIT 50"
    )->fetchAll(PDO::FETCH_ASSOC);

    legacyDashboardResponse(true, 'Legacy dashboard loaded', [
        'summary' => [
            'total_accounts' => (int)($summary['total_accounts'] ?? 0),
            'outstanding_balance' => (float)($summary['outstanding_balance'] ?? 0),
            'payment_count' => (int)($payment['payment_count'] ?? 0),
            'payment_amount' => (float)($payment['payment_amount'] ?? 0),
            'disputed_accounts' => (int)($summary['disputed_accounts'] ?? 0),
            'overdue_accounts' => (int)($summary['overdue_accounts'] ?? 0),
            'migrated_accounts' => (int)($summary['migrated_accounts'] ?? 0),
            'pending_actions' => (int)$pending,
        ],
        'reconciliation' => $reconciliation,
        'accounts' => $accounts,
        'actions' => $actions,
        'financial_years' => $years,
        'batches' => $batches,
    ]);
} catch (Throwable $e) {
    legacyDashboardResponse(false, $e->getMessage(), null, $e->getMessage() === 'Authentication required' ? 401 : 403);
}
