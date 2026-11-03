<?php
/**
 * Legacy financial ledger API.
 *
 * Phase 2 server-side engine. This endpoint owns ledger writes; clients must
 * never calculate or overwrite balances.
 */
declare(strict_types=1);

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../php_errors.log');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/legacy_authorization.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function ledgerResponse(bool $success, string $message, $data = null, int $status = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    $payload = ['success' => $success, 'message' => $message];
    if ($data !== null) {
        $payload['data'] = $data;
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function ledgerInput(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);

    if (!is_array($data)) {
        ledgerResponse(false, 'A JSON request body is required', null, 400);
    }

    return $data;
}

function ledgerUserId(): int
{
    $id = (int)($_SESSION['user_id'] ?? 0);
    if ($id <= 0) {
        ledgerResponse(false, 'Authentication required', null, 401);
    }

    return $id;
}

function ledgerRole(): string
{
    $role = strtolower(trim((string)($_SESSION['user_role'] ?? $_SESSION['role'] ?? '')));
    if ($role === '') {
        ledgerResponse(false, 'User role is missing', null, 403);
    }

    return $role;
}

function requireLedgerRoles(array $roles): string
{
    $role = ledgerRole();
    if (!in_array($role, $roles, true)) {
        ledgerResponse(false, 'You do not have permission for this legacy-ledger operation', null, 403);
    }

    return $role;
}

function requireLedgerPermission(PDO $db, string $permission): string
{
    try {
        $context = legacyRequirePermission($db, $permission);
        return $context['role'];
    } catch (Throwable $e) {
        ledgerResponse(false, $e->getMessage(), null, $e->getMessage() === 'Authentication required' ? 401 : 403);
    }
}

function requireLedgerAuthentication(PDO $db): array
{
    try {
        return legacyAuthContext($db, ledgerUserId());
    } catch (Throwable $e) {
        ledgerResponse(false, $e->getMessage(), null, $e->getMessage() === 'Authentication required' ? 401 : 403);
    }
}

function requireCsrf(array $data): void
{
    $expected = (string)($_SESSION['csrf_token'] ?? '');
    $provided = (string)($data['csrf_token'] ?? '');

    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        ledgerResponse(false, 'Invalid security token', null, 403);
    }
}

function ledgerDb(): PDO
{
    $database = new Database();
    $db = $database->getConnection();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    return $db;
}

function requiredInt(array $data, string $field): int
{
    $value = filter_var($data[$field] ?? null, FILTER_VALIDATE_INT);
    if ($value === false || $value <= 0) {
        ledgerResponse(false, "{$field} must be a positive integer", null, 422);
    }

    return (int)$value;
}

function requiredAmount(array $data, string $field = 'amount'): string
{
    $raw = $data[$field] ?? null;
    if (!is_numeric($raw) || (float)$raw <= 0) {
        ledgerResponse(false, "{$field} must be greater than zero", null, 422);
    }

    $amount = number_format((float)$raw, 4, '.', '');
    if ((float)$amount <= 0) {
        ledgerResponse(false, "{$field} must be greater than zero", null, 422);
    }

    return $amount;
}

function requiredText(array $data, string $field, int $max = 1000): string
{
    $value = trim((string)($data[$field] ?? ''));
    if ($value === '') {
        ledgerResponse(false, "{$field} is required", null, 422);
    }
    if (mb_strlen($value) > $max) {
        ledgerResponse(false, "{$field} is too long", null, 422);
    }

    return $value;
}

function optionalText(array $data, string $field, int $max = 500): ?string
{
    if (!array_key_exists($field, $data) || $data[$field] === null) {
        return null;
    }

    $value = trim((string)$data[$field]);
    if ($value === '') {
        return null;
    }
    if (mb_strlen($value) > $max) {
        ledgerResponse(false, "{$field} is too long", null, 422);
    }

    return $value;
}

function validateDateValue(string $date, string $field): string
{
    $parsed = DateTime::createFromFormat('Y-m-d', $date);
    $errors = DateTime::getLastErrors();

    if (!$parsed || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))
        || $parsed->format('Y-m-d') !== $date) {
        ledgerResponse(false, "{$field} must be a valid YYYY-MM-DD date", null, 422);
    }

    return $date;
}

function auditLedger(
    PDO $db,
    string $entityType,
    int $entityId,
    string $action,
    int $actor,
    ?string $reason,
    ?string $requestReference,
    ?array $before,
    ?array $after
): void {
    $reference = 'AUD-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(5));

    $stmt = $db->prepare(
        'INSERT INTO legacy_audit_events
         (event_reference, entity_type, entity_id, action, actor_user_id,
          request_reference, reason_text, before_data, after_data, source_ip, user_agent)
         VALUES
         (:event_reference, :entity_type, :entity_id, :action, :actor_user_id,
          :request_reference, :reason_text, :before_data, :after_data, :source_ip, :user_agent)'
    );

    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $packedIp = $ip ? @inet_pton($ip) : null;

    $stmt->bindValue(':event_reference', $reference);
    $stmt->bindValue(':entity_type', $entityType);
    $stmt->bindValue(':entity_id', $entityId, PDO::PARAM_INT);
    $stmt->bindValue(':action', $action);
    $stmt->bindValue(':actor_user_id', $actor, PDO::PARAM_INT);
    $stmt->bindValue(':request_reference', $requestReference);
    $stmt->bindValue(':reason_text', $reason);
    $stmt->bindValue(':before_data', $before === null ? null : json_encode($before));
    $stmt->bindValue(':after_data', $after === null ? null : json_encode($after));
    $stmt->bindValue(':source_ip', $packedIp, $packedIp === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
    $stmt->bindValue(':user_agent', optionalText($_SERVER, 'HTTP_USER_AGENT', 500));
    $stmt->execute();
}

function getAccountForUpdate(PDO $db, int $accountId): array
{
    $stmt = $db->prepare(
        'SELECT a.*, d.full_name, d.pf_number, d.national_id
           FROM legacy_debt_accounts a
           INNER JOIN legacy_debtors d ON d.legacy_debtor_id = a.legacy_debtor_id
          WHERE a.legacy_account_id = :account_id
          FOR UPDATE'
    );
    $stmt->execute([':account_id' => $accountId]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        ledgerResponse(false, 'Legacy debt account not found', null, 404);
    }

    return $account;
}

function getYearForUpdate(PDO $db, int $yearId): array
{
    $stmt = $db->prepare(
        'SELECT * FROM legacy_financial_years
          WHERE financial_year_id = :year_id
          FOR UPDATE'
    );
    $stmt->execute([':year_id' => $yearId]);
    $year = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$year) {
        ledgerResponse(false, 'Financial year not found', null, 422);
    }

    return $year;
}

function currentBalance(PDO $db, int $accountId): string
{
    $stmt = $db->prepare(
        'SELECT COALESCE(SUM(debit_amount) - SUM(credit_amount), 0)
           FROM legacy_transactions
          WHERE legacy_account_id = :account_id'
    );
    $stmt->execute([':account_id' => $accountId]);
    return number_format((float)$stmt->fetchColumn(), 4, '.', '');
}

function hasPostedPayments(PDO $db, int $accountId): bool
{
    $stmt = $db->prepare(
        "SELECT COUNT(*)
           FROM legacy_transactions
          WHERE legacy_account_id = :account_id
            AND transaction_type = 'PAYMENT'
            AND status = 'POSTED'"
    );
    $stmt->execute([':account_id' => $accountId]);
    return (int)$stmt->fetchColumn() > 0;
}

function refreshAccountStatus(PDO $db, int $accountId): void
{
    $account = getAccountForUpdate($db, $accountId);
    $balance = (float)currentBalance($db, $accountId);

    if ($account['dispute_status'] === 'OPEN') {
        $status = 'DISPUTED';
    } elseif ($balance < 0) {
        $status = 'OVERPAID';
    } elseif (abs($balance) < 0.00005) {
        $status = hasPostedPayments($db, $accountId) ? 'FULLY_PAID' : 'CLOSED';
    } elseif (hasPostedPayments($db, $accountId)) {
        $status = 'PARTIALLY_PAID';
    } else {
        $status = 'OPEN';
    }

    $stmt = $db->prepare(
        'UPDATE legacy_debt_accounts
            SET status = :status, updated_at = CURRENT_TIMESTAMP
          WHERE legacy_account_id = :account_id'
    );
    $stmt->execute([':status' => $status, ':account_id' => $accountId]);
}

function postLedgerTransaction(
    PDO $db,
    int $accountId,
    int $yearId,
    string $transactionReference,
    string $transactionType,
    ?string $debit,
    ?string $credit,
    string $transactionDate,
    int $actor,
    string $reason,
    ?string $postCloseReference,
    ?int $componentId = null,
    ?string $sourceType = null,
    ?string $sourceReference = null,
    ?int $parentTransactionId = null
): int {
    $stmt = $db->prepare(
        'INSERT INTO legacy_transactions
         (legacy_account_id, legacy_component_id, financial_year_id,
          transaction_reference, transaction_type, debit_amount, credit_amount,
          transaction_date, source_type, source_reference, description,
          reason_code, post_close_approval_reference, parent_transaction_id,
          status, recorded_by)
         VALUES
         (:account_id, :component_id, :year_id, :reference, :type,
          :debit, :credit, :transaction_date, :source_type, :source_reference,
          :description, :reason_code, :post_close_reference, :parent_id,
          "POSTED", :recorded_by)'
    );

    $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
    $stmt->bindValue(':component_id', $componentId, $componentId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue(':year_id', $yearId, PDO::PARAM_INT);
    $stmt->bindValue(':reference', $transactionReference);
    $stmt->bindValue(':type', $transactionType);
    $stmt->bindValue(':debit', $debit ?? '0.0000');
    $stmt->bindValue(':credit', $credit ?? '0.0000');
    $stmt->bindValue(':transaction_date', $transactionDate);
    $stmt->bindValue(':source_type', $sourceType);
    $stmt->bindValue(':source_reference', $sourceReference);
    $stmt->bindValue(':description', $reason);
    $stmt->bindValue(':reason_code', strtoupper($transactionType));
    $stmt->bindValue(':post_close_reference', $postCloseReference);
    $stmt->bindValue(':parent_id', $parentTransactionId, $parentTransactionId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue(':recorded_by', $actor, PDO::PARAM_INT);
    $stmt->execute();

    return (int)$db->lastInsertId();
}

function depositLegacyPayment(PDO $db, string $amount, string $transactionReference, int $actor): int
{
    $accountName = 'Collections Fund';

    $stmt = $db->prepare(
        'SELECT id, balance FROM funding_accounts
          WHERE name = :name
          LIMIT 1
          FOR UPDATE'
    );
    $stmt->execute([':name' => $accountName]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        $insert = $db->prepare(
            'INSERT INTO funding_accounts (name, balance)
             VALUES (:name, 0)'
        );
        $insert->execute([':name' => $accountName]);
        $accountId = (int)$db->lastInsertId();
        $oldBalance = 0.0;
    } else {
        $accountId = (int)$account['id'];
        $oldBalance = (float)$account['balance'];
    }

    $newBalance = $oldBalance + (float)$amount;
    $update = $db->prepare(
        'UPDATE funding_accounts
            SET balance = :balance, updated_at = CURRENT_TIMESTAMP
          WHERE id = :account_id'
    );
    $update->execute([':balance' => number_format($newBalance, 2, '.', ''), ':account_id' => $accountId]);

    $reference = 'legacy-payment:' . $transactionReference;
    $insertTransaction = $db->prepare(
        "INSERT INTO funding_transactions
         (funding_account_id, amount, transaction_type, reference, created_by)
         VALUES (:account_id, :amount, 'deposit', :reference, :created_by)"
    );
    $insertTransaction->execute([
        ':account_id' => $accountId,
        ':amount' => $amount,
        ':reference' => $reference,
        ':created_by' => $actor
    ]);

    return $accountId;
}

function routePostMigrationPayment(PDO $db, array $account, string $paymentDate, string $amount, string $paymentMethod, string $transactionReference, int $actor, string $reason, array $migration): void
{
    $loanStmt = $db->prepare('SELECT * FROM loan_applications WHERE id = :loan_id FOR UPDATE');
    $loanStmt->execute([':loan_id' => $migration['loan_application_id']]);
    $loan = $loanStmt->fetch(PDO::FETCH_ASSOC);
    if (!$loan) {
        throw new RuntimeException('The normal loan linked to this migration was not found');
    }

    $duplicate = $db->prepare(
        'SELECT payment_id FROM payments
          WHERE application_id = :application_id AND transaction_reference = :reference
          LIMIT 1 FOR UPDATE'
    );
    $duplicate->execute([
        ':application_id' => $loan['application_id'],
        ':reference' => $transactionReference
    ]);
    if ($duplicate->fetchColumn()) {
        throw new RuntimeException('This payment reference has already been posted to the normal loan');
    }

    $balanceStmt = $db->prepare(
        'SELECT COALESCE(loan_amount, 0) - COALESCE(SUM(principal_amount), 0)
           FROM loan_applications la
           LEFT JOIN payments p ON p.application_id = la.application_id
          WHERE la.id = ?
          GROUP BY la.id, la.loan_amount'
    );
    $balanceStmt->execute([(int)$loan['id']]);
    $balanceBefore = (float)$balanceStmt->fetchColumn();
    $balanceAfter = $balanceBefore - (float)$amount;
    $source = [
        'CASH' => 'cash_deposit',
        'BANK_TRANSFER' => 'bank_transfer',
        'SALARY_DEDUCTION' => 'salary_deduction',
        'OTHER' => 'cash_deposit'
    ][$paymentMethod] ?? 'cash_deposit';

    $insert = $db->prepare(
        'INSERT INTO payments
         (application_id, payment_date, amount, principal_amount, interest_amount,
          balance_after, payment_source, transaction_reference, recorded_by, notes)
         VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?)'
    );
    $insert->execute([
        $loan['application_id'], $paymentDate, $amount, $amount,
        number_format($balanceAfter, 2, '.', ''), $source,
        substr($transactionReference, 0, 50), (string)$actor,
        'Post-migration payment routed by migration date boundary.'
    ]);
    $paymentId = (int)$db->lastInsertId();
    depositLegacyPayment($db, $amount, $transactionReference, $actor);
    auditLedger(
        $db, 'loan_application', (int)$loan['id'], 'POST_MIGRATION_PAYMENT', $actor,
        $reason, $transactionReference,
        ['legacy_account_id' => (int)$account['legacy_account_id'], 'balance_before' => $balanceBefore],
        ['payment_id' => $paymentId, 'balance_after' => $balanceAfter, 'migration_date' => $migration['migration_date']]
    );

    $db->commit();
    ledgerResponse(true, 'Payment routed to normal loan', [
        'route' => 'NORMAL_LOAN', 'payment_id' => $paymentId,
        'loan_application_id' => (int)$loan['id'], 'balance' => $balanceAfter,
        'migration_date_boundary' => $migration['migration_date']
    ], 201);
}

function newReference(string $prefix): string
{
    return $prefix . '-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(5));
}

function roleForUser(PDO $db, int $userId): ?string
{
    $stmt = $db->prepare('SELECT role FROM users WHERE user_id = :user_id');
    $stmt->execute([':user_id' => $userId]);
    $role = $stmt->fetchColumn();
    return $role === false ? null : strtolower((string)$role);
}

function actionApprovals(PDO $db, array $request): array
{
    $roles = [];
    foreach (['approved_by', 'secondary_approved_by'] as $field) {
        if (!empty($request[$field])) {
            $role = roleForUser($db, (int)$request[$field]);
            if ($role !== null) {
                $roles[] = $role;
            }
        }
    }
    return $roles;
}

function getActionForUpdate(PDO $db, int $actionId): array
{
    $stmt = $db->prepare(
        'SELECT * FROM legacy_action_requests
          WHERE action_request_id = :action_id
          FOR UPDATE'
    );
    $stmt->execute([':action_id' => $actionId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        ledgerResponse(false, 'Legacy action request not found', null, 404);
    }

    return $request;
}

function handleGet(PDO $db): void
{
    $accountId = filter_input(INPUT_GET, 'legacy_account_id', FILTER_VALIDATE_INT);
    if (!$accountId || $accountId <= 0) {
        ledgerResponse(false, 'legacy_account_id is required', null, 422);
    }

    requireLedgerPermission($db, 'legacy.view');

    $stmt = $db->prepare(
        'SELECT a.*, d.full_name, d.pf_number, d.national_id,
                COALESCE(SUM(t.debit_amount) - SUM(t.credit_amount), 0) AS current_balance
           FROM legacy_debt_accounts a
           INNER JOIN legacy_debtors d ON d.legacy_debtor_id = a.legacy_debtor_id
           LEFT JOIN legacy_transactions t ON t.legacy_account_id = a.legacy_account_id
          WHERE a.legacy_account_id = :account_id
          GROUP BY a.legacy_account_id, d.full_name, d.pf_number, d.national_id'
    );
    $stmt->execute([':account_id' => $accountId]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        ledgerResponse(false, 'Legacy debt account not found', null, 404);
    }

    $tx = $db->prepare(
        'SELECT t.*, p.payment_method, p.payment_date, p.transaction_number,
                p.receipt_number, p.reconciliation_state
           FROM legacy_transactions t
           LEFT JOIN legacy_payment_details p
             ON p.legacy_transaction_id = t.legacy_transaction_id
          WHERE t.legacy_account_id = :account_id
          ORDER BY t.transaction_date ASC, t.legacy_transaction_id ASC'
    );
    $tx->execute([':account_id' => $accountId]);

    ledgerResponse(true, 'Legacy account retrieved', [
        'account' => $account,
        'transactions' => $tx->fetchAll(PDO::FETCH_ASSOC)
    ]);
}

function handlePayment(PDO $db, array $data, int $actor): void
{
    requireLedgerPermission($db, 'legacy.payment.create');

    $accountId = requiredInt($data, 'legacy_account_id');
    $yearId = requiredInt($data, 'financial_year_id');
    $amount = requiredAmount($data);
    $paymentDate = validateDateValue(requiredText($data, 'payment_date', 10), 'payment_date');
    $paymentMethod = strtoupper(requiredText($data, 'payment_method', 30));
    $allowedMethods = ['CASH', 'BANK_TRANSFER', 'SALARY_DEDUCTION', 'OTHER'];

    if (!in_array($paymentMethod, $allowedMethods, true)) {
        ledgerResponse(false, 'Invalid payment_method', null, 422);
    }

    $transactionReference = requiredText($data, 'transaction_reference', 150);
    $transactionNumber = optionalText($data, 'transaction_number', 150);
    $receiptNumber = optionalText($data, 'receipt_number', 100);
    $reason = requiredText($data, 'reason', 1000);
    $postCloseReference = optionalText($data, 'post_close_approval_reference', 100);

    $db->beginTransaction();

    try {
        $account = getAccountForUpdate($db, $accountId);
        $year = getYearForUpdate($db, $yearId);

        if ($year['status'] === 'CLOSED') {
            if ((int)$year['post_close_transactions_allowed'] !== 1
                || $postCloseReference === null) {
                throw new RuntimeException('Closed financial year requires controlled post-close authorization');
            }
        }

        $migrationStmt = $db->prepare(
            "SELECT * FROM legacy_migrations
              WHERE legacy_account_id = ? AND migration_status = 'EXECUTED'
              ORDER BY legacy_migration_id DESC LIMIT 1 FOR UPDATE"
        );
        $migrationStmt->execute([$accountId]);
        $migration = $migrationStmt->fetch(PDO::FETCH_ASSOC);
        if ($migration !== false && $paymentDate >= substr((string)$migration['migration_date'], 0, 10)) {
            routePostMigrationPayment(
                $db, $account, $paymentDate, $amount, $paymentMethod,
                $transactionReference, $actor, $reason, $migration
            );
        }

        $beforeBalance = currentBalance($db, $accountId);
        $transactionId = postLedgerTransaction(
            $db,
            $accountId,
            $yearId,
            $transactionReference,
            'PAYMENT',
            null,
            $amount,
            $paymentDate,
            $actor,
            $reason,
            $postCloseReference
        );

        $details = $db->prepare(
            'INSERT INTO legacy_payment_details
             (legacy_transaction_id, payment_method, payment_date,
              transaction_number, receipt_number, payer_name, payer_reference,
              funding_account_id, reconciliation_state, posting_notes)
             VALUES
             (:transaction_id, :payment_method, :payment_date, :transaction_number,
              :receipt_number, :payer_name, :payer_reference, :funding_account_id,
              "UNRECONCILED", :posting_notes)'
        );

        $fundingAccountId = depositLegacyPayment($db, $amount, $transactionReference, $actor);
        $details->execute([
            ':transaction_id' => $transactionId,
            ':payment_method' => $paymentMethod,
            ':payment_date' => $paymentDate,
            ':transaction_number' => $transactionNumber,
            ':receipt_number' => $receiptNumber,
            ':payer_name' => optionalText($data, 'payer_name', 255),
            ':payer_reference' => optionalText($data, 'payer_reference', 150),
            ':funding_account_id' => $fundingAccountId,
            ':posting_notes' => $reason
        ]);

        refreshAccountStatus($db, $accountId);
        $afterBalance = currentBalance($db, $accountId);

        auditLedger(
            $db, 'legacy_account', $accountId, 'PAYMENT', $actor, $reason,
            $transactionReference,
            ['balance_before' => (float)$beforeBalance],
            ['balance_after' => (float)$afterBalance, 'transaction_id' => $transactionId]
        );

        $db->commit();

        ledgerResponse(true, 'Legacy payment posted', [
            'transaction_id' => $transactionId,
            'transaction_reference' => $transactionReference,
            'balance' => (float)$afterBalance,
            'overpayment_credit' => max(0, -(float)$afterBalance)
        ], 201);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        $status = $e instanceof PDOException && $e->getCode() === '23000' ? 409 : 422;
        ledgerResponse(false, $e->getMessage(), null, $status);
    }
}

function handleRequestAction(PDO $db, array $data, int $actor): void
{
    $actionType = strtoupper(requiredText($data, 'action_type', 30));
    $allowed = ['PAYMENT_REVERSAL', 'ADJUSTMENT', 'WAIVER', 'WRITE_OFF'];

    if (!in_array($actionType, $allowed, true)) {
        ledgerResponse(false, 'Unsupported action_type', null, 422);
    }

    try {
        $permission = legacyPermissionForAction($actionType, 'request');
        $role = legacyRequirePermission($db, $permission)['role'];
    } catch (Throwable $e) {
        ledgerResponse(false, $e->getMessage(), null, $e->getMessage() === 'Authentication required' ? 401 : 403);
    }

    $accountId = requiredInt($data, 'legacy_account_id');
    $yearId = requiredInt($data, 'financial_year_id');
    $reasonCode = requiredText($data, 'reason_code', 80);
    $reasonText = requiredText($data, 'reason_text', 1000);
    $amount = isset($data['amount']) ? requiredAmount($data) : null;
    $requestReference = optionalText($data, 'request_reference', 100);

    if ($requestReference === null) {
        $requestReference = newReference('REQ');
    }

    $postingType = null;
    if ($actionType === 'ADJUSTMENT') {
        $direction = strtoupper(requiredText($data, 'adjustment_type', 30));
        $postingType = $direction === 'DEBIT' ? 'ADJUSTMENT_DEBIT' :
            ($direction === 'CREDIT' ? 'ADJUSTMENT_CREDIT' : null);
        if ($postingType === null) {
            ledgerResponse(false, 'adjustment_type must be DEBIT or CREDIT', null, 422);
        }
    } elseif ($actionType === 'WAIVER') {
        $postingType = 'WAIVER';
    } elseif ($actionType === 'WRITE_OFF') {
        $postingType = 'WRITE_OFF';
    } elseif ($actionType === 'PAYMENT_REVERSAL') {
        $postingType = 'PAYMENT_REVERSAL';
    }

    $targetTransactionId = isset($data['target_transaction_id'])
        ? requiredInt($data, 'target_transaction_id') : null;

    if ($actionType === 'PAYMENT_REVERSAL' && $targetTransactionId === null) {
        ledgerResponse(false, 'target_transaction_id is required for a reversal', null, 422);
    }

    if ($actionType !== 'PAYMENT_REVERSAL' && $amount === null) {
        ledgerResponse(false, 'amount is required for this action', null, 422);
    }

    $db->beginTransaction();

    try {
        getAccountForUpdate($db, $accountId);
        getYearForUpdate($db, $yearId);

        $stmt = $db->prepare(
            'INSERT INTO legacy_action_requests
             (request_reference, legacy_account_id, financial_year_id,
              action_type, posting_type, requested_amount, target_transaction_id,
              reason_code, reason_text, supporting_reference, requested_by)
             VALUES
             (:request_reference, :account_id, :year_id, :action_type, :posting_type,
              :amount, :target_id, :reason_code, :reason_text, :supporting_reference,
              :requested_by)'
        );
        $stmt->execute([
            ':request_reference' => $requestReference,
            ':account_id' => $accountId,
            ':year_id' => $yearId,
            ':action_type' => $actionType,
            ':posting_type' => $postingType,
            ':amount' => $amount,
            ':target_id' => $targetTransactionId,
            ':reason_code' => $reasonCode,
            ':reason_text' => $reasonText,
            ':supporting_reference' => optionalText($data, 'supporting_reference', 150),
            ':requested_by' => $actor
        ]);

        $actionId = (int)$db->lastInsertId();
        auditLedger(
            $db, 'legacy_action_request', $actionId, 'ACTION_REQUESTED',
            $actor, $reasonText, $requestReference, null,
            ['action_type' => $actionType, 'amount' => $amount, 'requester_role' => $role]
        );
        $db->commit();

        ledgerResponse(true, 'Legacy action request created', [
            'action_request_id' => $actionId,
            'request_reference' => $requestReference
        ], 201);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $status = $e instanceof PDOException && $e->getCode() === '23000' ? 409 : 422;
        ledgerResponse(false, $e->getMessage(), null, $status);
    }
}

function handleApproveAction(PDO $db, array $data, int $actor): void
{
    $actionId = requiredInt($data, 'action_request_id');
    $context = requireLedgerAuthentication($db);
    $role = $context['role'];
    $reason = requiredText($data, 'reason', 1000);

    $db->beginTransaction();

    try {
        $request = getActionForUpdate($db, $actionId);

        if ((int)$request['requested_by'] === $actor) {
            throw new RuntimeException('A requester cannot approve their own action');
        }
        if (!in_array($request['status'], ['REQUESTED', 'UNDER_REVIEW'], true)) {
            throw new RuntimeException('This action is not awaiting approval');
        }

        $actionType = $request['action_type'];
        $requiredPermission = legacyPermissionForAction($actionType, 'approve');
        if (($context['permissions'][$requiredPermission] ?? false) !== true) {
            throw new RuntimeException('This role cannot approve the requested action');
        }

        if ($actionType === 'WRITE_OFF') {
            $existingRoles = actionApprovals($db, $request);
            if (in_array($role, $existingRoles, true)) {
                throw new RuntimeException('This approval role is already recorded');
            }

            if ($request['approved_by'] === null) {
                $field = 'approved_by';
                $timeField = 'approved_at';
            } else {
                $field = 'secondary_approved_by';
                $timeField = 'secondary_approved_at';
            }

            $sql = "UPDATE legacy_action_requests
                       SET {$field} = :actor, {$timeField} = CURRENT_TIMESTAMP,
                           status = 'UNDER_REVIEW'
                     WHERE action_request_id = :action_id";
            $stmt = $db->prepare($sql);
            $stmt->execute([':actor' => $actor, ':action_id' => $actionId]);

            $request = getActionForUpdate($db, $actionId);
            $roles = actionApprovals($db, $request);
            if (in_array('accountant', $roles, true) && in_array('admin', $roles, true)) {
                $db->prepare(
                    "UPDATE legacy_action_requests SET status='APPROVED'
                      WHERE action_request_id=:action_id"
                )->execute([':action_id' => $actionId]);
            }
        } else {
            $stmt = $db->prepare(
                "UPDATE legacy_action_requests
                    SET approved_by = :actor, approved_at = CURRENT_TIMESTAMP,
                        status = 'APPROVED'
                  WHERE action_request_id = :action_id"
            );
            $stmt->execute([':actor' => $actor, ':action_id' => $actionId]);
        }

        auditLedger(
            $db, 'legacy_action_request', $actionId, 'ACTION_APPROVED',
            $actor, $reason, $request['request_reference'], null,
            ['approver_role' => $role, 'action_type' => $actionType]
        );
        $db->commit();

        ledgerResponse(true, 'Legacy action approval recorded');
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        ledgerResponse(false, $e->getMessage(), null, 422);
    }
}

function handleExecuteAction(PDO $db, array $data, int $actor): void
{
    $actionId = requiredInt($data, 'action_request_id');
    $context = requireLedgerAuthentication($db);
    $role = $context['role'];
    $postingReference = optionalText($data, 'transaction_reference', 100);

    if ($postingReference === null) {
        $postingReference = newReference('TX');
    }

    $db->beginTransaction();

    try {
        $request = getActionForUpdate($db, $actionId);
        if ($request['status'] !== 'APPROVED') {
            throw new RuntimeException('Action must be fully approved before execution');
        }
        if ($request['executed_by'] !== null) {
            throw new RuntimeException('Action has already been executed');
        }

        $executionPermission = legacyPermissionForAction((string)$request['action_type'], 'execute');
        if (($context['permissions'][$executionPermission] ?? false) !== true) {
            throw new RuntimeException('This role cannot execute the requested action');
        }

        if ($request['action_type'] === 'WRITE_OFF') {
            $roles = actionApprovals($db, $request);
            if (!in_array('accountant', $roles, true) || !in_array('admin', $roles, true)) {
                throw new RuntimeException('Write-off requires Accountant and Admin approval');
            }
        }

        $account = getAccountForUpdate($db, (int)$request['legacy_account_id']);
        $year = getYearForUpdate($db, (int)$request['financial_year_id']);
        $reason = $request['reason_text'];
        $postCloseReference = $year['status'] === 'CLOSED' ? $request['request_reference'] : null;

        $beforeBalance = currentBalance($db, (int)$request['legacy_account_id']);
        $amount = $request['requested_amount'] !== null
            ? number_format((float)$request['requested_amount'], 4, '.', '')
            : null;
        $transactionId = null;

        if ($request['action_type'] === 'PAYMENT_REVERSAL') {
            $target = $db->prepare(
                "SELECT t.*
                   FROM legacy_transactions t
                  WHERE t.legacy_transaction_id = :transaction_id
                    AND t.legacy_account_id = :account_id
                    AND t.transaction_type = 'PAYMENT'
                    AND t.status = 'POSTED'
                  FOR UPDATE"
            );
            $target->execute([
                ':transaction_id' => $request['target_transaction_id'],
                ':account_id' => $request['legacy_account_id']
            ]);
            $original = $target->fetch(PDO::FETCH_ASSOC);
            if (!$original) {
                throw new RuntimeException('Original posted payment was not found');
            }

            $exists = $db->prepare(
                "SELECT COUNT(*) FROM legacy_transactions
                  WHERE parent_transaction_id = :parent_id
                    AND transaction_type = 'PAYMENT_REVERSAL'"
            );
            $exists->execute([':parent_id' => $original['legacy_transaction_id']]);
            if ((int)$exists->fetchColumn() > 0) {
                throw new RuntimeException('This payment has already been reversed');
            }

            $amount = number_format((float)$original['credit_amount'], 4, '.', '');
            $transactionId = postLedgerTransaction(
                $db, (int)$request['legacy_account_id'], (int)$request['financial_year_id'],
                $postingReference, 'PAYMENT_REVERSAL', $amount, null,
                date('Y-m-d'), $actor, $reason, $postCloseReference,
                $original['legacy_component_id'], 'legacy_payment_reversal',
                (string)$original['transaction_reference'], (int)$original['legacy_transaction_id']
            );

            $db->prepare(
                "UPDATE legacy_transactions SET status='REVERSED'
                  WHERE legacy_transaction_id=:transaction_id"
            )->execute([':transaction_id' => $original['legacy_transaction_id']]);

            $db->prepare(
                "UPDATE legacy_payment_details SET reconciliation_state='REVERSED'
                  WHERE legacy_transaction_id=:transaction_id"
            )->execute([':transaction_id' => $original['legacy_transaction_id']]);
        } else {
            if ($amount === null) {
                throw new RuntimeException('An approved action amount is required');
            }

            if (in_array($request['action_type'], ['WAIVER', 'WRITE_OFF'], true)
                && (float)$amount > (float)$beforeBalance) {
                throw new RuntimeException('Waiver or write-off cannot exceed the current balance');
            }

            $type = $request['posting_type'];
            $debit = in_array($type, ['ADJUSTMENT_DEBIT'], true) ? $amount : null;
            $credit = in_array($type, ['ADJUSTMENT_CREDIT', 'WAIVER', 'WRITE_OFF'], true)
                ? $amount : null;

            if ($debit === null && $credit === null) {
                throw new RuntimeException('Unsupported posting type');
            }

            $transactionId = postLedgerTransaction(
                $db, (int)$request['legacy_account_id'], (int)$request['financial_year_id'],
                $postingReference, $type, $debit, $credit,
                date('Y-m-d'), $actor, $reason, $postCloseReference
            );
        }

        $db->prepare(
            "UPDATE legacy_action_requests
                SET status='EXECUTED', executed_by=:actor, executed_at=CURRENT_TIMESTAMP
              WHERE action_request_id=:action_id"
        )->execute([':actor' => $actor, ':action_id' => $actionId]);

        refreshAccountStatus($db, (int)$request['legacy_account_id']);
        $afterBalance = currentBalance($db, (int)$request['legacy_account_id']);

        auditLedger(
            $db, 'legacy_account', (int)$request['legacy_account_id'], $request['action_type'],
            $actor, $reason, $request['request_reference'],
            ['balance_before' => (float)$beforeBalance],
            ['balance_after' => (float)$afterBalance, 'transaction_id' => $transactionId]
        );

        $db->commit();

        ledgerResponse(true, 'Legacy action executed', [
            'action_request_id' => $actionId,
            'transaction_id' => $transactionId,
            'balance' => (float)$afterBalance,
            'executor_role' => $role
        ]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $status = $e instanceof PDOException && $e->getCode() === '23000' ? 409 : 422;
        ledgerResponse(false, $e->getMessage(), null, $status);
    }
}

function handleCharge(PDO $db, array $data, int $actor): void
{
    $chargeType = strtoupper(requiredText($data, 'charge_type', 20));
    if (!in_array($chargeType, ['INTEREST', 'PENALTY'], true)) {
        ledgerResponse(false, 'charge_type must be INTEREST or PENALTY', null, 422);
    }

    $role = requireLedgerPermission($db, 'legacy.' . strtolower($chargeType) . '.apply');

    $accountId = requiredInt($data, 'legacy_account_id');
    $yearId = requiredInt($data, 'financial_year_id');
    $amount = requiredAmount($data);
    $transactionDate = validateDateValue(requiredText($data, 'transaction_date', 10), 'transaction_date');
    $reason = requiredText($data, 'reason', 1000);
    $postCloseReference = optionalText($data, 'post_close_approval_reference', 100);

    $db->beginTransaction();

    try {
        getAccountForUpdate($db, $accountId);
        $year = getYearForUpdate($db, $yearId);
        if ($year['status'] === 'CLOSED'
            && ((int)$year['post_close_transactions_allowed'] !== 1 || $postCloseReference === null)) {
            throw new RuntimeException('Closed financial year requires controlled post-close authorization');
        }

        $reference = requiredText($data, 'transaction_reference', 100);
        $before = currentBalance($db, $accountId);
        $id = postLedgerTransaction(
            $db, $accountId, $yearId, $reference, $chargeType, $amount, null,
            $transactionDate, $actor, $reason, $postCloseReference
        );
        refreshAccountStatus($db, $accountId);
        $after = currentBalance($db, $accountId);
        auditLedger(
            $db, 'legacy_account', $accountId, $chargeType,
            $actor, $reason, $reference,
            ['balance_before' => (float)$before],
            ['balance_after' => (float)$after, 'transaction_id' => $id, 'role' => $role]
        );
        $db->commit();

        ledgerResponse(true, 'Legacy charge posted', [
            'transaction_id' => $id,
            'balance' => (float)$after
        ], 201);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $status = $e instanceof PDOException && $e->getCode() === '23000' ? 409 : 422;
        ledgerResponse(false, $e->getMessage(), null, $status);
    }
}

try {
    $db = ledgerDb();
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $method === 'GET'
        ? 'get_account'
        : strtoupper(trim((string)($_GET['action'] ?? '')));

    if ($method === 'GET') {
        handleGet($db);
    }

    if ($method !== 'POST') {
        ledgerResponse(false, 'Method not allowed', null, 405);
    }

    $data = ledgerInput();
    requireCsrf($data);
    $actor = ledgerUserId();

    switch ($action) {
        case 'RECORD_PAYMENT':
            handlePayment($db, $data, $actor);
            break;
        case 'REQUEST_ACTION':
            handleRequestAction($db, $data, $actor);
            break;
        case 'APPROVE_ACTION':
            handleApproveAction($db, $data, $actor);
            break;
        case 'EXECUTE_ACTION':
            handleExecuteAction($db, $data, $actor);
            break;
        case 'POST_CHARGE':
            handleCharge($db, $data, $actor);
            break;
        default:
            ledgerResponse(false, 'Unknown legacy-ledger action', null, 422);
    }
} catch (Throwable $e) {
    error_log('[Legacy Ledger API] ' . $e->getMessage());
    ledgerResponse(false, 'Legacy ledger operation failed', null, 500);
}

