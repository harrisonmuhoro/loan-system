<?php
/**
 * Controlled legacy-to-normal-loan migration API.
 *
 * Migration date boundary: payment_date >= DATE(migration_date) routes to the
 * normal loan. Earlier payment dates remain on the legacy ledger.
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
require_once __DIR__ . '/../helpers/legacy_identity_helper.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

function migrationResponse(bool $success, string $message, $data = null, int $status = 200): void
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

function migrationDb(): PDO
{
    $db = (new Database())->getConnection();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    return $db;
}

function migrationActor(): int
{
    try {
        return legacyAuthUserId();
    } catch (Throwable $e) {
        migrationResponse(false, $e->getMessage(), null, 401);
    }
}

function migrationPermission(PDO $db, string $permission): string
{
    try {
        return legacyRequirePermission($db, $permission)['role'];
    } catch (Throwable $e) {
        migrationResponse(false, $e->getMessage(), null, $e->getMessage() === 'Authentication required' ? 401 : 403);
    }
}

function migrationCsrf(array $data): void
{
    $expected = (string)($_SESSION['csrf_token'] ?? '');
    $provided = (string)($data['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        migrationResponse(false, 'Invalid security token', null, 403);
    }
}

function migrationJson(): array
{
    $data = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($data)) {
        migrationResponse(false, 'A JSON request body is required', null, 400);
    }
    return $data;
}

function migrationInt(array $data, string $field): int
{
    $value = filter_var($data[$field] ?? null, FILTER_VALIDATE_INT);
    if ($value === false || $value <= 0) {
        migrationResponse(false, "{$field} must be a positive integer", null, 422);
    }
    return (int)$value;
}

function migrationText(array $data, string $field, int $max = 1000): string
{
    $value = trim((string)($data[$field] ?? ''));
    if ($value === '' || mb_strlen($value) > $max) {
        migrationResponse(false, "{$field} is required and must be at most {$max} characters", null, 422);
    }
    return $value;
}

function migrationDate(array $data): string
{
    $value = trim((string)($data['migration_date'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        $value .= ' 00:00:00';
    }
    $parsed = DateTime::createFromFormat('Y-m-d H:i:s', $value);
    if (!$parsed || $parsed->format('Y-m-d H:i:s') !== $value) {
        migrationResponse(false, 'migration_date must be YYYY-MM-DD or YYYY-MM-DD HH:MM:SS', null, 422);
    }
    return $value;
}

function migrationAudit(PDO $db, string $entity, int $entityId, string $action, int $actor, string $reason, ?string $requestReference, ?array $before, ?array $after): void
{
    $stmt = $db->prepare(
        'INSERT INTO legacy_audit_events
         (event_reference, entity_type, entity_id, action, actor_user_id,
          request_reference, reason_text, before_data, after_data, source_ip, user_agent)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $stmt->execute([
        'AUD-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(5)),
        $entity, $entityId, $action, $actor, $requestReference, $reason,
        $before === null ? null : json_encode($before),
        $after === null ? null : json_encode($after),
        $ip ? @inet_pton($ip) : null,
        isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : null
    ]);
}

function migrationBalance(PDO $db, int $accountId): string
{
    $stmt = $db->prepare(
        'SELECT COALESCE(SUM(debit_amount) - SUM(credit_amount), 0)
           FROM legacy_transactions WHERE legacy_account_id = ?'
    );
    $stmt->execute([$accountId]);
    return number_format((float)$stmt->fetchColumn(), 4, '.', '');
}

function migrationMatchingLoans(PDO $db, array $account): array
{
    $assessment = legacyAssessMigrationIdentityMatches($db, $account);
    return $assessment['matches'];
}

function migrationRequest(PDO $db, array $data, int $actor): void
{
    migrationPermission($db, 'legacy.migration.request');
    $accountId = migrationInt($data, 'legacy_account_id');
    $date = migrationDate($data);
    $reasonCode = migrationText($data, 'reason_code', 80);
    $reason = migrationText($data, 'reason_text');
    $reference = trim((string)($data['request_reference'] ?? '')) ?: 'MIG-REQ-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(5));

    $db->beginTransaction();
    try {
        $accountStmt = $db->prepare(
            'SELECT a.*, d.full_name, d.pf_number, d.national_id, d.linked_user_id
               FROM legacy_debt_accounts a
               INNER JOIN legacy_debtors d ON d.legacy_debtor_id = a.legacy_debtor_id
              WHERE a.legacy_account_id = ? FOR UPDATE'
        );
        $accountStmt->execute([$accountId]);
        $account = $accountStmt->fetch(PDO::FETCH_ASSOC);
        if (!$account) {
            throw new RuntimeException('Legacy debt account not found');
        }

        $linkedLoan = null;
        if (!empty($account['linked_loan_application_id'])) {
            $linked = $db->prepare('SELECT id, loan_amount FROM loan_applications WHERE id=? FOR UPDATE');
            $linked->execute([(int)$account['linked_loan_application_id']]);
            $linkedLoan = $linked->fetch(PDO::FETCH_ASSOC);
            if (!$linkedLoan) throw new RuntimeException('Existing linked normal loan is missing; manual reconciliation is required');
        }

        $existing = $db->prepare(
            "SELECT * FROM legacy_migrations WHERE legacy_account_id = ?
             ORDER BY legacy_migration_id DESC LIMIT 1 FOR UPDATE"
        );
        $existing->execute([$accountId]);
        $prior = $existing->fetch(PDO::FETCH_ASSOC);
        if ($prior && in_array($prior['migration_status'], ['REQUESTED','VALIDATED','APPROVED','EXECUTED'], true)) {
            throw new RuntimeException('This legacy account already has an active or executed migration');
        }

        $balance = migrationBalance($db, $accountId);
        if ((float)$balance <= 0) {
            throw new RuntimeException('Only a positive legacy balance can be migrated');
        }
        $linkedId = (int)($account['linked_loan_application_id'] ?? 0);
        $assessment = legacyAssessMigrationIdentityMatches($db, $account);
        $matches = $assessment['matches'];

        if ($assessment['status'] === 'CONFLICT') {
            throw new RuntimeException('Conflicting PF number and national ID match found in normal loans; manual reconciliation is required before migration');
        }
        if ($assessment['status'] === 'AMBIGUOUS') {
            throw new RuntimeException('Ambiguous normal loan identity match detected; manual reconciliation is required before migration');
        }
        if ($assessment['status'] === 'ALREADY_LINKED') {
            throw new RuntimeException('Candidate normal loan is already linked to another legacy account');
        }
        if ($linkedId === 0 && !empty($matches)) {
            throw new RuntimeException('Existing normal loan identity match requires manual reconciliation before migration');
        }
        if ($linkedId > 0) {
            $linkedMatchExists = count(array_filter($matches, static fn(array $loan): bool => (int)$loan['id'] === $linkedId)) > 0;
            if (!$linkedMatchExists) {
                throw new RuntimeException('Existing loan link does not match debtor identity; manual reconciliation is required');
            }
            if ($linkedLoan && bccomp((string)$linkedLoan['loan_amount'], $balance, 4) !== 0) {
                throw new RuntimeException('Existing linked normal loan balance differs; manual reconciliation is required');
            }
        }

        $action = $db->prepare(
            "INSERT INTO legacy_action_requests
             (request_reference, legacy_account_id, action_type, requested_amount,
              reason_code, reason_text, status, requested_by)
             VALUES (?, ?, 'MIGRATION', ?, ?, ?, 'REQUESTED', ?)"
        );
        $action->execute([$reference, $accountId, $balance, $reasonCode, $reason, $actor]);
        $actionId = (int)$db->lastInsertId();

        $migration = $db->prepare(
            "INSERT INTO legacy_migrations
             (migration_reference, legacy_account_id, action_request_id,
              migration_status, balance_at_migration, migration_date, requested_by)
             VALUES (?, ?, ?, 'REQUESTED', ?, ?, ?)"
        );
        $migration->execute([$reference, $accountId, $actionId, $balance, $date, $actor]);
        $migrationId = (int)$db->lastInsertId();

        migrationAudit($db, 'legacy_migration', $migrationId, 'MIGRATION_REQUESTED', $actor, $reason, $reference, null, [
            'account_id' => $accountId,
            'balance' => (float)$balance,
            'migration_date' => $date,
            'identity_assessment' => $assessment['audit_payload']
        ]);
        $db->commit();
        migrationResponse(true, 'Migration request created', [
            'legacy_migration_id' => $migrationId, 'action_request_id' => $actionId,
            'migration_reference' => $reference, 'balance_at_migration' => (float)$balance,
            'migration_date' => $date
        ], 201);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        migrationResponse(false, $e->getMessage(), null, $e instanceof PDOException && $e->getCode() === '23000' ? 409 : 422);
    }
}

function migrationApprove(PDO $db, array $data, int $actor): void
{
    migrationPermission($db, 'legacy.migration.approve');
    $actionId = migrationInt($data, 'action_request_id');
    $reason = migrationText($data, 'reason');
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT * FROM legacy_action_requests WHERE action_request_id = ? FOR UPDATE');
        $stmt->execute([$actionId]);
        $action = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$action || $action['action_type'] !== 'MIGRATION') throw new RuntimeException('Migration request not found');
        if ((int)$action['requested_by'] === $actor) throw new RuntimeException('A requester cannot approve their own migration');
        if ($action['status'] !== 'REQUESTED') throw new RuntimeException('Migration request is not awaiting approval');

        $migrationStmt = $db->prepare('SELECT * FROM legacy_migrations WHERE action_request_id = ? FOR UPDATE');
        $migrationStmt->execute([$actionId]);
        $migration = $migrationStmt->fetch(PDO::FETCH_ASSOC);
        if (!$migration || $migration['migration_status'] !== 'REQUESTED') throw new RuntimeException('Migration is stale or unavailable');
        $current = migrationBalance($db, (int)$action['legacy_account_id']);
        if (bccomp($current, (string)$migration['balance_at_migration'], 4) !== 0) {
            $db->prepare("UPDATE legacy_migrations SET migration_status='FAILED', failure_reason=? WHERE legacy_migration_id=?")
                ->execute(['Balance changed after migration request', $migration['legacy_migration_id']]);
            $db->prepare("UPDATE legacy_action_requests SET status='REJECTED' WHERE action_request_id=?")
                ->execute([$actionId]);
            migrationAudit($db, 'legacy_migration', (int)$migration['legacy_migration_id'], 'MIGRATION_REJECTED_STALE', $actor, $reason, $action['request_reference'], ['balance' => (float)$migration['balance_at_migration']], ['balance' => (float)$current]);
            $db->commit();
            migrationResponse(false, 'Migration request is stale because the legacy balance changed', null, 409);
        }

        $db->prepare("UPDATE legacy_action_requests SET approved_by=?, approved_at=CURRENT_TIMESTAMP, status='APPROVED' WHERE action_request_id=?")
            ->execute([$actor, $actionId]);
        $db->prepare("UPDATE legacy_migrations SET approved_by=?, approved_at=CURRENT_TIMESTAMP, migration_status='APPROVED' WHERE legacy_migration_id=?")
            ->execute([$actor, $migration['legacy_migration_id']]);
        migrationAudit($db, 'legacy_migration', (int)$migration['legacy_migration_id'], 'MIGRATION_APPROVED', $actor, $reason, $action['request_reference'], null, ['approver_id' => $actor]);
        $db->commit();
        migrationResponse(true, 'Migration approved');
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        migrationResponse(false, $e->getMessage(), null, 422);
    }
}

function migrationExecute(PDO $db, array $data, int $actor): void
{
    migrationPermission($db, 'legacy.migration.process');
    $actionId = migrationInt($data, 'action_request_id');
    $reason = migrationText($data, 'reason');
    $db->beginTransaction();
    try {
        $actionStmt = $db->prepare('SELECT * FROM legacy_action_requests WHERE action_request_id=? FOR UPDATE');
        $actionStmt->execute([$actionId]);
        $action = $actionStmt->fetch(PDO::FETCH_ASSOC);
        if (!$action || $action['action_type'] !== 'MIGRATION' || $action['status'] !== 'APPROVED') throw new RuntimeException('Migration must be approved before execution');

        $migrationStmt = $db->prepare('SELECT * FROM legacy_migrations WHERE action_request_id=? FOR UPDATE');
        $migrationStmt->execute([$actionId]);
        $migration = $migrationStmt->fetch(PDO::FETCH_ASSOC);
        if (!$migration || $migration['migration_status'] !== 'APPROVED') throw new RuntimeException('Migration is stale or unavailable');
        $accountId = (int)$action['legacy_account_id'];
        $accountStmt = $db->prepare('SELECT a.*, d.full_name, d.pf_number, d.national_id, d.linked_user_id FROM legacy_debt_accounts a INNER JOIN legacy_debtors d ON d.legacy_debtor_id=a.legacy_debtor_id WHERE a.legacy_account_id=? FOR UPDATE');
        $accountStmt->execute([$accountId]);
        $account = $accountStmt->fetch(PDO::FETCH_ASSOC);
        if (!$account) throw new RuntimeException('Legacy debt account not found');
        $current = migrationBalance($db, $accountId);
        if (bccomp($current, (string)$migration['balance_at_migration'], 4) !== 0) throw new RuntimeException('Migration is stale because the legacy balance changed');

        $loan = null;
        if (!empty($account['linked_loan_application_id'])) {
            $loanStmt = $db->prepare('SELECT * FROM loan_applications WHERE id=? FOR UPDATE');
            $loanStmt->execute([(int)$account['linked_loan_application_id']]);
            $loan = $loanStmt->fetch(PDO::FETCH_ASSOC);
        }

        $linkedId = (int)($account['linked_loan_application_id'] ?? 0);
        $assessment = legacyAssessMigrationIdentityMatches($db, $account);
        $matches = $assessment['matches'];

        if ($assessment['status'] === 'CONFLICT') {
            throw new RuntimeException('Conflicting PF number and national ID match found in normal loans; manual reconciliation is required before migration');
        }
        if ($assessment['status'] === 'AMBIGUOUS') {
            throw new RuntimeException('Ambiguous normal loan identity match detected; manual reconciliation is required before migration');
        }
        if ($assessment['status'] === 'ALREADY_LINKED') {
            throw new RuntimeException('Candidate normal loan is already linked to another legacy account');
        }
        if ($linkedId === 0 && !empty($matches)) {
            throw new RuntimeException('Existing normal loan identity match requires manual reconciliation before migration');
        }
        $linkedMatchExists = count(array_filter($matches, static fn(array $candidate): bool => (int)$candidate['id'] === $linkedId)) > 0;
        if ($linkedId > 0 && !$linkedMatchExists) throw new RuntimeException('Existing loan link does not match debtor identity; manual reconciliation is required');

        if ($loan && bccomp((string)$loan['loan_amount'], (string)$migration['balance_at_migration'], 4) !== 0) {
            throw new RuntimeException('Existing linked normal loan balance differs from the approved migration balance');
        }

        if (!$loan) {
            $applicationId = 'LEGACY-' . $accountId . '-' . strtoupper(bin2hex(random_bytes(4)));
            $insertLoan = $db->prepare(
                "INSERT INTO loan_applications
                 (user_id, application_id, full_name, id_number, pf_number, loan_type,
                  loan_amount, payment_duration, payment_frequency, status, feedback)
                 VALUES (?, ?, ?, ?, ?, 'mortgage', ?, 12, 'monthly', 'active', ?)"
            );
            $insertLoan->execute([
                $account['linked_user_id'] ?: null, $applicationId, $account['full_name'],
                $account['national_id'], $account['pf_number'], $migration['balance_at_migration'],
                'Controlled migration of the collectible balance from the legacy ledger.'
            ]);
            $loanId = (int)$db->lastInsertId();
            $loan = ['id' => $loanId, 'application_id' => $applicationId, 'loan_amount' => $migration['balance_at_migration']];
        }

        $fy = $db->prepare(
            'SELECT financial_year_id, status FROM legacy_financial_years
              WHERE start_date <= DATE(?) AND end_date >= DATE(?) FOR UPDATE'
        );
        $fy->execute([$migration['migration_date'], $migration['migration_date']]);
        $financialYear = $fy->fetch(PDO::FETCH_ASSOC);
        if (!$financialYear) throw new RuntimeException('Migration date does not belong to a configured financial year');

        $ledger = $db->prepare(
            "INSERT INTO legacy_transactions
             (legacy_account_id, financial_year_id, transaction_reference, transaction_type,
              debit_amount, credit_amount, transaction_date, source_type, source_reference,
              description, reason_code, post_close_approval_reference, recorded_by)
             VALUES (?, ?, ?, 'MIGRATION', 0, ?, DATE(?), 'legacy_migration', ?,
                     'Collectible balance transferred to linked normal loan', 'MIGRATION', ?, ?)"
        );
        $ledger->execute([
            $accountId, (int)$financialYear['financial_year_id'],
            'MIG-' . $migration['migration_reference'], $migration['balance_at_migration'],
            $migration['migration_date'], (string)$loan['application_id'],
            $migration['migration_reference'], $actor
        ]);

        $db->prepare("UPDATE legacy_migrations SET migration_status='EXECUTED', loan_application_id=?, loan_application_reference=?, executed_by=?, executed_at=CURRENT_TIMESTAMP WHERE legacy_migration_id=?")
            ->execute([$loan['id'], $loan['application_id'], $actor, $migration['legacy_migration_id']]);
        $db->prepare("UPDATE legacy_debt_accounts SET migration_status='MIGRATED', migration_date=?, linked_loan_application_id=?, status='MIGRATED' WHERE legacy_account_id=?")
            ->execute([$migration['migration_date'], $loan['id'], $accountId]);
        $db->prepare("UPDATE legacy_action_requests SET status='EXECUTED', executed_by=?, executed_at=CURRENT_TIMESTAMP WHERE action_request_id=?")
            ->execute([$actor, $actionId]);

        migrationAudit($db, 'legacy_migration', (int)$migration['legacy_migration_id'], 'MIGRATION_EXECUTED', $actor, $reason, $action['request_reference'], null, [
            'legacy_account_id' => $accountId, 'loan_application_id' => (int)$loan['id'],
            'balance' => (float)$migration['balance_at_migration'], 'migration_date' => $migration['migration_date'],
            'identity_assessment' => $assessment['audit_payload']
        ]);
        $db->commit();
        migrationResponse(true, 'Legacy account migrated to normal loan', [
            'legacy_account_id' => $accountId, 'loan_application_id' => (int)$loan['id'],
            'migration_date' => $migration['migration_date'], 'balance' => (float)$migration['balance_at_migration']
        ]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        migrationResponse(false, $e->getMessage(), null, $e instanceof PDOException && $e->getCode() === '23000' ? 409 : 422);
    }
}

try {
    $db = migrationDb();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') migrationResponse(false, 'Method not allowed', null, 405);
    $data = migrationJson();
    migrationCsrf($data);
    $actor = migrationActor();
    $action = strtoupper(trim((string)($_GET['action'] ?? '')));
    if ($action === 'REQUEST') migrationRequest($db, $data, $actor);
    if ($action === 'APPROVE') migrationApprove($db, $data, $actor);
    if ($action === 'EXECUTE') migrationExecute($db, $data, $actor);
    migrationResponse(false, 'Unknown migration action', null, 422);
} catch (Throwable $e) {
    migrationResponse(false, 'Migration operation failed', null, 500);
}
