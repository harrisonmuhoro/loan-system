<?php
/**
 * Phase 3 controlled legacy import and reconciliation API.
 *
 * Actions:
 * - upload: multipart file staging and batch creation
 * - validate: parse staged source and create row-level validation records
 * - confirm: mark a validated batch ready for posting
 * - post: create identities, one account per debtor, components, and opening entries
 * - migrate_existing: copy outstanding_debtors into the normalized model without deletion
 * - reconcile_existing: persist source-to-normalized reconciliation results
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

const LEGACY_IMPORT_MAX_BYTES = 26214400;
const LEGACY_IMPORT_STORAGE = __DIR__ . '/../secure/legacy-imports';

function importResponse(bool $success, string $message, $data = null, int $status = 200): void
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

function importDb(): PDO
{
    $database = new Database();
    $db = $database->getConnection();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    return $db;
}

function importUserId(): int
{
    $id = (int)($_SESSION['user_id'] ?? 0);
    if ($id <= 0) {
        importResponse(false, 'Authentication required', null, 401);
    }
    return $id;
}

function importRole(array $allowed): string
{
    $role = strtolower(trim((string)($_SESSION['user_role'] ?? $_SESSION['role'] ?? '')));
    if (!in_array($role, $allowed, true)) {
        importResponse(false, 'You do not have permission for this import operation', null, 403);
    }
    return $role;
}

function requireImportPermission(PDO $db, string $permission): string
{
    try {
        return legacyRequirePermission($db, $permission)['role'];
    } catch (Throwable $e) {
        importResponse(false, $e->getMessage(), null, $e->getMessage() === 'Authentication required' ? 401 : 403);
    }
}

function importCsrf(?array $data = null): void
{
    $expected = (string)($_SESSION['csrf_token'] ?? '');
    $provided = (string)($data['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        importResponse(false, 'Invalid security token', null, 403);
    }
}

function importJson(): array
{
    $data = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($data)) {
        importResponse(false, 'A JSON request body is required', null, 400);
    }
    return $data;
}

function importInt(array $data, string $field): int
{
    $value = filter_var($data[$field] ?? null, FILTER_VALIDATE_INT);
    if ($value === false || $value <= 0) {
        importResponse(false, "{$field} must be a positive integer", null, 422);
    }
    return (int)$value;
}

function importText(array $data, string $field, int $max = 255): string
{
    $value = trim((string)($data[$field] ?? ''));
    if ($value === '' || mb_strlen($value) > $max) {
        importResponse(false, "{$field} is required and must be at most {$max} characters", null, 422);
    }
    return $value;
}

function importAudit(PDO $db, string $entity, int $entityId, string $action, int $actor, ?string $reason, ?array $after): void
{
    $stmt = $db->prepare(
        'INSERT INTO legacy_audit_events
         (event_reference, entity_type, entity_id, action, actor_user_id,
          reason_text, after_data, source_ip, user_agent)
         VALUES (:reference, :entity, :entity_id, :action, :actor,
                 :reason, :after_data, :source_ip, :user_agent)'
    );

    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $stmt->bindValue(':reference', 'AUD-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(5)));
    $stmt->bindValue(':entity', $entity);
    $stmt->bindValue(':entity_id', $entityId, PDO::PARAM_INT);
    $stmt->bindValue(':action', $action);
    $stmt->bindValue(':actor', $actor, PDO::PARAM_INT);
    $stmt->bindValue(':reason', $reason);
    $stmt->bindValue(':after_data', $after === null ? null : json_encode($after));
    $stmt->bindValue(':source_ip', $ip ? @inet_pton($ip) : null, $ip ? PDO::PARAM_LOB : PDO::PARAM_NULL);
    $stmt->bindValue(':user_agent', isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : null);
    $stmt->execute();
}

function beginValidationAttempt(PDO $db, array $batch, int $actor): int
{
    $next = $db->prepare(
        'SELECT COALESCE(MAX(attempt_number), 0) + 1
           FROM legacy_import_validation_attempts
          WHERE import_batch_id = ?'
    );
    $next->execute([(int)$batch['import_batch_id']]);
    $attemptNumber = (int)$next->fetchColumn();

    $insert = $db->prepare(
        'INSERT INTO legacy_import_validation_attempts
            (import_batch_id, attempt_number, validated_by, source_file_hash)
         VALUES (?, ?, ?, ?)'
    );
    $insert->execute([
        (int)$batch['import_batch_id'],
        $attemptNumber,
        $actor,
        $batch['source_file_hash'] ?? null
    ]);

    return (int)$db->lastInsertId();
}

function recordValidationRow(PDO $db, int $attemptId, array $row, array $normalized, ?string $sourceRef): void
{
    $stmt = $db->prepare(
        'INSERT INTO legacy_import_validation_rows
            (validation_attempt_id, source_row_number, source_data, normalized_name,
             normalized_pf_number, normalized_national_id, source_reference,
             amount, validation_state, error_message)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $attemptId,
        (int)$row['source_row_number'],
        json_encode($row['raw'], JSON_UNESCAPED_UNICODE),
        $normalized['name'],
        $normalized['pf_number'],
        $normalized['national_id'],
        $sourceRef,
        $normalized['amount'],
        $normalized['state'],
        empty($normalized['errors']) ? null : implode('; ', $normalized['errors'])
    ]);
}

function handleDuplicateResolution(PDO $db, array $data, int $actor): void
{
    requireImportPermission($db, 'legacy.import');
    $recordId = importInt($data, 'import_record_id');
    $candidateId = isset($data['candidate_record_id']) && $data['candidate_record_id'] !== null
        ? importInt($data, 'candidate_record_id') : null;
    $resolution = strtoupper(trim((string)($data['resolution'] ?? '')));
    if (!in_array($resolution, ['SAME_PERSON', 'DIFFERENT_PERSON', 'UNRESOLVED'], true)) {
        importResponse(false, 'resolution must be SAME_PERSON, DIFFERENT_PERSON, or UNRESOLVED', null, 422);
    }
    $reason = importText($data, 'reason', 1000);

    $record = $db->prepare('SELECT import_record_id, validation_state FROM legacy_import_records WHERE import_record_id = ?');
    $record->execute([$recordId]);
    if (!$record->fetch(PDO::FETCH_ASSOC)) {
        importResponse(false, 'Import record not found', null, 404);
    }

    $insert = $db->prepare(
        'INSERT INTO legacy_duplicate_resolutions
            (import_record_id, candidate_record_id, resolution, reason, resolved_by)
         VALUES (?, ?, ?, ?, ?)'
    );
    $insert->execute([$recordId, $candidateId, $resolution, $reason, $actor]);
    $resolutionId = (int)$db->lastInsertId();
    importAudit($db, 'legacy_import_record', $recordId, 'DUPLICATE_RESOLUTION', $actor, $reason, [
        'resolution_id' => $resolutionId,
        'resolution' => $resolution,
        'candidate_record_id' => $candidateId
    ]);
    importResponse(true, 'Duplicate decision recorded without merging source records', [
        'duplicate_resolution_id' => $resolutionId,
        'resolution' => $resolution
    ], 201);
}

function normalizeHeader(string $header): string
{
    $header = mb_strtolower(trim($header));
    $header = preg_replace('/[^a-z0-9]+/u', '_', $header) ?? '';
    return trim($header, '_');
}

function normalizeValue(?string $value): ?string
{
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function normalizeName(?string $value): ?string
{
    $value = normalizeValue($value);
    if ($value === null) {
        return null;
    }

    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return mb_strtoupper($value);
}

function parseAmount($value): ?string
{
    if ($value === null || trim((string)$value) === '') {
        return null;
    }

    $clean = str_replace(',', '', trim((string)$value));
    $clean = preg_replace('/[^0-9.\-]/', '', $clean) ?? '';

    if ($clean === '' || !is_numeric($clean) || (float)$clean <= 0) {
        return null;
    }

    return number_format((float)$clean, 4, '.', '');
}

function mapSourceRow(array $row, int $rowNumber): array
{
    $mapped = [];
    foreach ($row as $key => $value) {
        $mapped[normalizeHeader((string)$key)] = is_scalar($value) ? trim((string)$value) : null;
    }

    $pick = static function (array $keys) use ($mapped): ?string {
        foreach ($keys as $key) {
            if (isset($mapped[$key]) && trim((string)$mapped[$key]) !== '') {
                return trim((string)$mapped[$key]);
            }
        }
        return null;
    };

    return [
        'source_row_number' => $rowNumber,
        'name' => $pick(['name', 'full_name', 'full_names', 'debtor_name', 'staff_name', 'employee_name']),
        'pf_number' => $pick(['pf_number', 'pf_no', 'pf', 'payroll_number', 'staff_number']),
        'national_id' => $pick(['national_id', 'id_number', 'id_no', 'id', 'identification_number']),
        'phone' => $pick(['phone', 'phone_number', 'mobile', 'mobile_number']),
        'source_reference' => $pick(['source_reference', 'legacy_reference', 'loan_reference', 'account_reference', 'reference']),
        'amount' => parseAmount($pick(['amount', 'outstanding_amount', 'outstanding_balance', 'balance', 'loan_amount'])),
        'raw' => $row
    ];
}

function parseCsvRows(string $path): array
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Unable to open staged source file');
    }

    $rows = [];
    $headers = null;
    $line = 0;

    while (($cells = fgetcsv($handle, 0, ',')) !== false) {
        $line++;
        if (count(array_filter($cells, static fn($v) => trim((string)$v) !== '')) === 0) {
            continue;
        }

        $candidateHeaders = array_map(static fn($v) => normalizeHeader((string)$v), $cells);
        $hasName = (bool)array_intersect($candidateHeaders, ['name', 'full_name', 'debtor_name', 'staff_name']);
        $hasAmount = (bool)array_intersect($candidateHeaders, ['amount', 'outstanding_amount', 'outstanding_balance', 'balance', 'loan_amount']);

        if ($headers === null) {
            if (!$hasName || !$hasAmount) {
                continue;
            }
            $headers = $candidateHeaders;
            continue;
        }

        $row = [];
        foreach ($headers as $index => $header) {
            $row[$header] = $cells[$index] ?? '';
        }
        $rows[] = mapSourceRow($row, $line);
    }

    fclose($handle);

    if ($headers === null) {
        throw new RuntimeException('No header row containing name and amount was found');
    }

    return $rows;
}

function parseExcelRows(string $path): array
{
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('Excel imports require composer dependencies. Run composer install first.');
    }

    require_once $autoload;

    if (!class_exists('\\PhpOffice\\PhpSpreadsheet\\IOFactory')) {
        throw new RuntimeException('PhpSpreadsheet is not installed');
    }

    $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet();
    $rawRows = [];
    foreach ($sheet->toArray(null, true, true, true) as $row) {
        $rawRows[] = array_values($row);
    }

    $headers = null;
    $result = [];
    foreach ($rawRows as $index => $cells) {
        $candidate = array_map(static fn($v) => normalizeHeader((string)$v), $cells);
        $hasName = (bool)array_intersect($candidate, ['name', 'full_name', 'debtor_name', 'staff_name']);
        $hasAmount = (bool)array_intersect($candidate, ['amount', 'outstanding_amount', 'outstanding_balance', 'balance', 'loan_amount']);

        if ($headers === null) {
            if (!$hasName || !$hasAmount) {
                continue;
            }
            $headers = $candidate;
            continue;
        }

        $row = [];
        foreach ($headers as $column => $header) {
            $row[$header] = $cells[$column] ?? '';
        }
        $result[] = mapSourceRow($row, $index + 1);
    }

    if ($headers === null) {
        throw new RuntimeException('No header row containing name and amount was found');
    }

    return $result;
}

function parseImportFile(string $path, string $extension): array
{
    $extension = strtolower($extension);
    if ($extension === 'csv') {
        return parseCsvRows($path);
    }
    if (in_array($extension, ['xls', 'xlsx'], true)) {
        return parseExcelRows($path);
    }
    throw new RuntimeException('Only CSV, XLS, and XLSX files are supported');
}

function sourcePathForBatch(string $batchReference, string $hash, string $extension): string
{
    if (!is_dir(LEGACY_IMPORT_STORAGE) && !mkdir(LEGACY_IMPORT_STORAGE, 0750, true) && !is_dir(LEGACY_IMPORT_STORAGE)) {
        throw new RuntimeException('Unable to create private legacy-import storage');
    }

    $filename = $batchReference . '-' . $hash . '.' . strtolower($extension);
    $fullPath = LEGACY_IMPORT_STORAGE . DIRECTORY_SEPARATOR . $filename;

    if (file_exists($fullPath)) {
        throw new RuntimeException('Source file already exists');
    }

    return $fullPath;
}

function validateFinancialYear(PDO $db, int $yearId): array
{
    $stmt = $db->prepare('SELECT * FROM legacy_financial_years WHERE financial_year_id = ?');
    $stmt->execute([$yearId]);
    $year = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$year) {
        importResponse(false, 'Financial year not found', null, 422);
    }

    return $year;
}

function findIdentity(PDO $db, ?string $pf, ?string $nationalId): ?array
{
    if ($pf !== null && $nationalId !== null) {
        $stmt = $db->prepare(
            'SELECT * FROM legacy_debtors WHERE pf_number = ? AND national_id = ? LIMIT 1'
        );
        $stmt->execute([$pf, $nationalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    }

    if ($pf !== null) {
        $stmt = $db->prepare('SELECT * FROM legacy_debtors WHERE pf_number = ? LIMIT 1');
        $stmt->execute([$pf]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    }

    if ($nationalId !== null) {
        $stmt = $db->prepare('SELECT * FROM legacy_debtors WHERE national_id = ? LIMIT 1');
        $stmt->execute([$nationalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    }

    return null;
}

function accountForDebtor(PDO $db, int $debtorId): ?array
{
    $stmt = $db->prepare('SELECT * FROM legacy_debt_accounts WHERE legacy_debtor_id = ? LIMIT 1');
    $stmt->execute([$debtorId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function componentExists(PDO $db, ?string $sourceReference): ?array
{
    if ($sourceReference === null) {
        return null;
    }

    $stmt = $db->prepare('SELECT * FROM legacy_loan_components WHERE source_reference = ? LIMIT 1');
    $stmt->execute([$sourceReference]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function identityValidation(PDO $db, array $row): array
{
    $errors = [];
    $name = normalizeName($row['name'] ?? null);
    $pf = normalizeValue($row['pf_number'] ?? null);
    $nationalId = normalizeValue($row['national_id'] ?? null);
    $amount = $row['amount'] ?? null;

    if ($name === null || mb_strlen($name) < 3) {
        $errors[] = 'name is required';
    }
    if ($pf === null && $nationalId === null) {
        $errors[] = 'PF number or national ID is required';
    }
    if ($amount === null || (float)$amount <= 0) {
        $errors[] = 'amount must be greater than zero';
    }

    $existing = ($pf !== null || $nationalId !== null)
        ? findIdentity($db, $pf, $nationalId)
        : null;

    $state = empty($errors) ? 'VALID' : 'INVALID';
    $details = $errors;

    if ($existing && $state === 'VALID') {
        $exact = $pf !== null && $nationalId !== null
            && $existing['pf_number'] === $pf
            && $existing['national_id'] === $nationalId;
        $sourceReference = normalizeValue($row['source_reference'] ?? null);
        $component = componentExists($db, $sourceReference);

        if (!$exact) {
            $state = 'POSSIBLE_DUPLICATE';
            $details[] = 'Identity partially matches an existing debtor';
        } elseif ($component) {
            $state = 'DUPLICATE';
            $details[] = 'Source reference already exists';
        } elseif ($sourceReference === null) {
            $state = 'POSSIBLE_DUPLICATE';
            $details[] = 'Existing identity requires a source reference for a new loan component';
        }
    } elseif (($sourceReference = normalizeValue($row['source_reference'] ?? null)) !== null
        && componentExists($db, $sourceReference)) {
        $state = 'DUPLICATE';
        $details[] = 'Source reference already exists';
    }

    return [
        'state' => $state,
        'errors' => $details,
        'name' => $name,
        'pf_number' => $pf,
        'national_id' => $nationalId,
        'amount' => $amount,
        'existing' => $existing
    ];
}

function batchForUpdate(PDO $db, int $batchId): array
{
    $stmt = $db->prepare('SELECT * FROM legacy_import_batches WHERE import_batch_id = ? FOR UPDATE');
    $stmt->execute([$batchId]);
    $batch = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$batch) {
        importResponse(false, 'Import batch not found', null, 404);
    }

    return $batch;
}

function handleUpload(PDO $db, int $actor): void
{
    requireImportPermission($db, 'legacy.import');
    importCsrf($_POST);

    $yearId = (int)($_POST['financial_year_id'] ?? 0);
    if ($yearId <= 0) {
        importResponse(false, 'financial_year_id is required', null, 422);
    }

    $year = validateFinancialYear($db, $yearId);
    if ($year['status'] === 'CLOSED') {
        importResponse(false, 'Imports into closed financial years require a separate approved process', null, 422);
    }

    $file = $_FILES['source_file'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        importResponse(false, 'A source_file upload is required', null, 422);
    }
    if ((int)$file['size'] <= 0 || (int)$file['size'] > LEGACY_IMPORT_MAX_BYTES) {
        importResponse(false, 'Source file is empty or exceeds the 25 MB limit', null, 422);
    }

    $originalName = basename((string)$file['name']);
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, ['csv', 'xls', 'xlsx'], true)) {
        importResponse(false, 'Only CSV, XLS, and XLSX files are supported', null, 422);
    }

    $tmp = (string)$file['tmp_name'];
    $hash = hash_file('sha256', $tmp);
    if ($hash === false) {
        importResponse(false, 'Unable to hash source file', null, 422);
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detectedMime = $finfo ? finfo_file($finfo, $tmp) : false;
    if ($finfo) {
        finfo_close($finfo);
    }
    $allowedMimes = [
        'csv' => ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'],
        'xls' => ['application/vnd.ms-excel', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip']
    ];
    if ($detectedMime === false || !in_array($detectedMime, $allowedMimes[$extension], true)) {
        importResponse(false, 'Uploaded file content does not match its extension', null, 422);
    }

    $existing = $db->prepare(
        'SELECT import_batch_id, batch_reference, status
           FROM legacy_import_batches
          WHERE financial_year_id = ? AND source_file_hash = ?
          LIMIT 1'
    );
    $existing->execute([$yearId, $hash]);
    if ($existing->fetch()) {
        importResponse(false, 'This source file was already uploaded for the financial year', null, 409);
    }

    $batchReference = 'IMP-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(5));
    $path = sourcePathForBatch($batchReference, $hash, $extension);

    if (!is_uploaded_file($tmp) || !move_uploaded_file($tmp, $path)) {
        importResponse(false, 'Unable to securely retain the source file', null, 500);
    }

    try {
        $stmt = $db->prepare(
            'INSERT INTO legacy_import_batches
             (batch_reference, financial_year_id, source_filename, source_file_path,
              source_file_hash, source_mime_type, source_type, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $batchReference, $yearId, $originalName,
            'secure/legacy-imports/' . basename($path),
            $hash,
            (string)($file['type'] ?? 'application/octet-stream'),
            strtoupper($extension),
            $actor
        ]);
        $batchId = (int)$db->lastInsertId();
    } catch (Throwable $e) {
        @unlink($path);
        throw $e;
    }

    importAudit($db, 'legacy_import_batch', $batchId, 'IMPORT_UPLOADED', $actor, 'Source file staged', [
        'batch_reference' => $batchReference,
        'filename' => $originalName,
        'sha256' => $hash
    ]);

    importResponse(true, 'Source file staged for validation', [
        'import_batch_id' => $batchId,
        'batch_reference' => $batchReference,
        'status' => 'UPLOADED'
    ], 201);
}

function handleValidate(PDO $db, array $data, int $actor): void
{
    requireImportPermission($db, 'legacy.import');
    $batchId = importInt($data, 'import_batch_id');

    $db->beginTransaction();

    try {
        $batch = batchForUpdate($db, $batchId);
        if (!in_array($batch['status'], ['UPLOADED', 'VALIDATING', 'PREVIEWED'], true)) {
            throw new RuntimeException('This batch is not available for validation');
        }

        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $batch['source_file_path']);
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Retained source file is unavailable');
        }

        $rows = parseImportFile($path, pathinfo($batch['source_filename'], PATHINFO_EXTENSION));
        $attemptId = beginValidationAttempt($db, $batch, $actor);
        $existingRecords = $db->prepare(
            'SELECT COUNT(*) FROM legacy_import_records WHERE import_batch_id = ?'
        );
        $existingRecords->execute([$batchId]);
        $isFirstAttempt = (int)$existingRecords->fetchColumn() === 0;

        $seen = [];
        $valid = $invalid = $duplicates = 0;

        $insert = $db->prepare(
            'INSERT INTO legacy_import_records
             (import_batch_id, source_row_number, source_data, normalized_name,
              normalized_pf_number, normalized_national_id, source_reference,
              amount, validation_state, error_message)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($rows as $row) {
            $normalized = identityValidation($db, $row);
            $sourceRef = normalizeValue($row['source_reference'] ?? null);
            if ($sourceRef === null) {
                $sourceRef = $batch['batch_reference'] . ':ROW:' . $row['source_row_number'];
            }

            $identityKey = ($normalized['pf_number'] ?? '') . '|' . ($normalized['national_id'] ?? '') . '|' . $sourceRef;
            if (isset($seen[$identityKey]) && $normalized['state'] === 'VALID') {
                $normalized['state'] = 'DUPLICATE';
                $normalized['errors'][] = 'Duplicate row in this batch';
            }
            $seen[$identityKey] = true;

            if ($normalized['state'] === 'VALID') {
                $valid++;
            } elseif ($normalized['state'] === 'DUPLICATE' || $normalized['state'] === 'POSSIBLE_DUPLICATE') {
                $duplicates++;
            } else {
                $invalid++;
            }

            if ($isFirstAttempt) {
                $insert->execute([
                    $batchId, $row['source_row_number'], json_encode($row['raw'], JSON_UNESCAPED_UNICODE),
                    $normalized['name'], $normalized['pf_number'], $normalized['national_id'], $sourceRef,
                    $normalized['amount'], $normalized['state'],
                    empty($normalized['errors']) ? null : implode('; ', $normalized['errors'])
                ]);
            }
            recordValidationRow($db, $attemptId, $row, $normalized, $sourceRef);
        }

        $status = $valid > 0 ? 'PREVIEWED' : 'REJECTED';
        $db->prepare(
            "UPDATE legacy_import_batches
                SET status = ?, detected_count = ?, valid_count = ?,
                    imported_count = 0, rejected_count = ?, duplicate_count = ?,
                    total_imported_debt = COALESCE(
                        (SELECT SUM(amount) FROM legacy_import_records
                          WHERE import_batch_id = ? AND validation_state = 'VALID'), 0)
              WHERE import_batch_id = ?"
        )->execute([$status, count($rows), $valid, $invalid, $duplicates, $batchId, $batchId]);

        $db->prepare(
            'UPDATE legacy_import_validation_attempts
                SET validation_status = \'COMPLETED\', detected_count = ?, valid_count = ?,
                    invalid_count = ?, duplicate_count = ?, completed_at = CURRENT_TIMESTAMP
              WHERE validation_attempt_id = ?'
        )->execute([count($rows), $valid, $invalid, $duplicates, $attemptId]);

        importAudit($db, 'legacy_import_batch', $batchId, 'IMPORT_VALIDATED', $actor, 'Source validation completed', [
            'detected' => count($rows),
            'valid' => $valid,
            'invalid' => $invalid,
            'duplicates' => $duplicates,
            'validation_attempt_id' => $attemptId,
            'history_preserved' => !$isFirstAttempt
        ]);

        $db->commit();

        importResponse(true, 'Import validation completed', [
            'import_batch_id' => $batchId,
            'detected_count' => count($rows),
            'valid_count' => $valid,
            'invalid_count' => $invalid,
            'duplicate_count' => $duplicates,
            'status' => $status
        ]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        importResponse(false, $e->getMessage(), null, 422);
    }
}

function handleConfirm(PDO $db, array $data, int $actor): void
{
    requireImportPermission($db, 'legacy.import');
    $batchId = importInt($data, 'import_batch_id');

    $db->beginTransaction();
    try {
        $batch = batchForUpdate($db, $batchId);
        if ($batch['status'] !== 'PREVIEWED' || (int)$batch['valid_count'] <= 0) {
            throw new RuntimeException('Only a validated batch with valid records can be confirmed');
        }

        $db->prepare(
            "UPDATE legacy_import_batches
                SET status='CONFIRMED', confirmed_by=?, confirmed_at=CURRENT_TIMESTAMP
              WHERE import_batch_id=?"
        )->execute([$actor, $batchId]);

        importAudit($db, 'legacy_import_batch', $batchId, 'IMPORT_CONFIRMED', $actor, 'Import confirmed for posting', null);
        $db->commit();

        importResponse(true, 'Import confirmed for posting', ['import_batch_id' => $batchId, 'status' => 'CONFIRMED']);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        importResponse(false, $e->getMessage(), null, 422);
    }
}

function createOrFindDebtor(PDO $db, array $record, int $actor): array
{
    $existing = findIdentity($db, $record['normalized_pf_number'], $record['normalized_national_id']);
    if ($existing) {
        if ($record['normalized_pf_number'] !== null
            && $record['normalized_national_id'] !== null
            && $existing['pf_number'] === $record['normalized_pf_number']
            && $existing['national_id'] === $record['normalized_national_id']) {
            return $existing;
        }
        throw new RuntimeException('Identity conflict requires manual duplicate review');
    }

    $stmt = $db->prepare(
        'INSERT INTO legacy_debtors
         (full_name, pf_number, national_id, identity_status, created_by)
         VALUES (?, ?, ?, "VERIFIED", ?)'
    );
    $stmt->execute([
        $record['normalized_name'],
        $record['normalized_pf_number'],
        $record['normalized_national_id'],
        $actor
    ]);

    $id = (int)$db->lastInsertId();
    $stmt = $db->prepare('SELECT * FROM legacy_debtors WHERE legacy_debtor_id = ? FOR UPDATE');
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function createOrFindAccount(PDO $db, int $debtorId, int $actor, string $reference): array
{
    $existing = accountForDebtor($db, $debtorId);
    if ($existing) {
        return $existing;
    }

    $stmt = $db->prepare(
        'INSERT INTO legacy_debt_accounts
         (legacy_debtor_id, account_reference, created_by)
         VALUES (?, ?, ?)'
    );
    $stmt->execute([$debtorId, $reference, $actor]);

    $id = (int)$db->lastInsertId();
    $stmt = $db->prepare('SELECT * FROM legacy_debt_accounts WHERE legacy_account_id = ? FOR UPDATE');
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function postOpening(PDO $db, array $record, array $batch, int $actor, int $accountId, int $yearId): int
{
    $componentRef = $record['source_reference'];
    $existing = componentExists($db, $componentRef);
    if ($existing) {
        throw new RuntimeException('Source component already exists: ' . $componentRef);
    }

    $component = $db->prepare(
        'INSERT INTO legacy_loan_components
         (legacy_account_id, financial_year_id, component_reference,
          source_system, source_reference, loan_description, original_principal,
          component_date, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $component->execute([
        $accountId,
        $yearId,
        $componentRef,
        'LEGACY_IMPORT',
        $componentRef,
        'Imported legacy debt',
        $record['amount'],
        date('Y-m-d'),
        $actor
    ]);
    $componentId = (int)$db->lastInsertId();

    $transaction = $db->prepare(
        'INSERT INTO legacy_transactions
         (legacy_account_id, legacy_component_id, financial_year_id,
          transaction_reference, transaction_type, debit_amount, transaction_date,
          source_type, source_reference, description, reason_code, recorded_by)
         VALUES (?, ?, ?, ?, "OPENING_BALANCE", ?, ?, "IMPORT", ?, ?, "IMPORT", ?)'
    );
    $transaction->execute([
        $accountId,
        $componentId,
        $yearId,
        'OPEN-' . $batch['import_batch_id'] . '-' . $record['source_row_number'],
        $record['amount'],
        date('Y-m-d'),
        $batch['batch_reference'],
        'Imported opening balance',
        $actor
    ]);

    return $componentId;
}

function handlePost(PDO $db, array $data, int $actor): void
{
    requireImportPermission($db, 'legacy.migration.approve');
    $batchId = importInt($data, 'import_batch_id');

    $db->beginTransaction();

    try {
        $batch = batchForUpdate($db, $batchId);
        if ($batch['status'] !== 'CONFIRMED') {
            throw new RuntimeException('Only a confirmed batch can be posted');
        }

        $year = validateFinancialYear($db, (int)$batch['financial_year_id']);
        if ($year['status'] === 'CLOSED') {
            throw new RuntimeException('Closed financial-year imports require a separate approved process');
        }

        $rows = $db->prepare(
            "SELECT * FROM legacy_import_records
              WHERE import_batch_id = ? AND validation_state = 'VALID'
              ORDER BY source_row_number
              FOR UPDATE"
        );
        $rows->execute([$batchId]);

        $posted = 0;
        while ($record = $rows->fetch(PDO::FETCH_ASSOC)) {
            $debtor = createOrFindDebtor($db, $record, $actor);
            $account = createOrFindAccount(
                $db,
                (int)$debtor['legacy_debtor_id'],
                $actor,
                'LDA-' . $batch['batch_reference'] . '-' . $record['source_row_number']
            );

            postOpening($db, $record, $batch, $actor, (int)$account['legacy_account_id'], (int)$batch['financial_year_id']);

            $db->prepare(
                "UPDATE legacy_import_records
                    SET validation_state='IMPORTED', legacy_debtor_id=?, legacy_account_id=?
                  WHERE import_record_id=?"
            )->execute([
                $debtor['legacy_debtor_id'],
                $account['legacy_account_id'],
                $record['import_record_id']
            ]);
            $posted++;
        }

        $status = $posted === (int)$batch['valid_count'] ? 'POSTED' : 'PARTIAL';
        $db->prepare(
            'UPDATE legacy_import_batches
                SET status=?, imported_count=?, completed_at=CURRENT_TIMESTAMP
              WHERE import_batch_id=?'
        )->execute([$status, $posted, $batchId]);

        importAudit($db, 'legacy_import_batch', $batchId, 'IMPORT_POSTED', $actor, 'Validated records posted to ledger', [
            'posted' => $posted,
            'batch_status' => $status
        ]);

        $db->commit();
        importResponse(true, 'Legacy import posted', [
            'import_batch_id' => $batchId,
            'imported_count' => $posted,
            'status' => $status
        ], 201);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        importResponse(false, 'Import post rolled back: ' . $e->getMessage(), null, 422);
    }
}

function handleMigrateExisting(PDO $db, int $actor): void
{
    requireImportPermission($db, 'legacy.migration.approve');
    $db->beginTransaction();

    try {
        $rows = $db->query('SELECT * FROM outstanding_debtors ORDER BY financial_year, debtor_id')->fetchAll(PDO::FETCH_ASSOC);
        $migrated = $skipped = 0;
        $errors = [];

        foreach ($rows as $source) {
            $savepoint = 'legacy_row_' . (int)$source['debtor_id'];
            $db->exec('SAVEPOINT ' . $savepoint);
            $yearStmt = $db->prepare(
                'SELECT * FROM legacy_financial_years WHERE label = ? FOR UPDATE'
            );
            $yearStmt->execute([$source['financial_year']]);
            $year = $yearStmt->fetch(PDO::FETCH_ASSOC);

            if (!$year) {
                $skipped++;
                $errors[] = "Debtor {$source['debtor_id']}: financial year {$source['financial_year']} is not configured";
                continue;
            }

            $sourceRef = 'OUTSTANDING_DEBTOR:' . $source['debtor_id'];
            if (componentExists($db, $sourceRef)) {
                continue;
            }

            $record = [
                'normalized_name' => normalizeName($source['name']),
                'normalized_pf_number' => normalizeValue($source['pf_number']),
                'normalized_national_id' => normalizeValue($source['id_number']),
                'source_reference' => $sourceRef,
                'amount' => parseAmount($source['amount']),
                'source_row_number' => (int)$source['debtor_id']
            ];

            if ($record['amount'] === null
                || ($record['normalized_pf_number'] === null && $record['normalized_national_id'] === null)) {
                $skipped++;
                $errors[] = "Debtor {$source['debtor_id']}: missing valid amount or identity";
                continue;
            }

            try {
                $debtor = createOrFindDebtor($db, $record, $actor);
                $account = createOrFindAccount($db, (int)$debtor['legacy_debtor_id'], $actor, 'LDA-OUTSTANDING-' . $source['debtor_id']);

                $batch = [
                    'import_batch_id' => 0,
                    'batch_reference' => 'OUTSTANDING_MIGRATION'
                ];
                postOpening($db, $record, $batch, $actor, (int)$account['legacy_account_id'], (int)$year['financial_year_id']);

                if (!empty($source['loan_application_id'])) {
                    $loan = $db->prepare('SELECT id, application_id FROM loan_applications WHERE application_id = ?');
                    $loan->execute([$source['loan_application_id']]);
                    $loanRow = $loan->fetch(PDO::FETCH_ASSOC);

                    if ($loanRow && empty($account['linked_loan_application_id'])) {
                        $db->prepare(
                            "INSERT INTO legacy_migrations
                             (migration_reference, legacy_account_id, migration_status,
                              balance_at_migration, migration_date, loan_application_id,
                              loan_application_reference, requested_by, executed_by,
                              executed_at)
                             VALUES (?, ?, 'EXECUTED', ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)"
                        )->execute([
                            'HISTORICAL-MIGRATION:' . $source['debtor_id'],
                            $account['legacy_account_id'],
                            $record['amount'],
                            $source['updated_at'] ?: $source['created_at'],
                            $loanRow['id'],
                            $loanRow['application_id'],
                            $actor,
                            $actor
                        ]);
                        $db->prepare(
                            "UPDATE legacy_debt_accounts
                                SET migration_status='MIGRATED', migration_date=?,
                                    linked_loan_application_id=?, status='MIGRATED'
                              WHERE legacy_account_id=?"
                        )->execute([
                            $source['updated_at'] ?: $source['created_at'],
                            $loanRow['id'],
                            $account['legacy_account_id']
                        ]);
                    } elseif (!$loanRow) {
                        $errors[] = "Debtor {$source['debtor_id']}: linked loan {$source['loan_application_id']} was not found";
                    } else {
                        $errors[] = "Debtor {$source['debtor_id']}: multiple migration links require review";
                    }
                }

                $migrated++;
            } catch (Throwable $e) {
                $db->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $skipped++;
                $errors[] = "Debtor {$source['debtor_id']}: " . $e->getMessage();
            }
        }

        $db->commit();
        importResponse(true, 'Existing outstanding debt migration completed', [
            'migrated' => $migrated,
            'skipped' => $skipped,
            'errors' => $errors,
            'source_records_preserved' => true
        ]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        importResponse(false, 'Existing-data migration rolled back: ' . $e->getMessage(), null, 422);
    }
}

function handleReconcileExisting(PDO $db, int $actor): void
{
    requireImportPermission($db, 'legacy.payment.reconcile');

    $runReference = 'REC-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(5));
    $db->beginTransaction();

    try {
        $stmt = $db->prepare(
            "INSERT INTO legacy_reconciliation_runs
             (run_reference, source_type, started_by)
             VALUES (?, 'OUTSTANDING_DEBTORS', ?)"
        );
        $stmt->execute([$runReference, $actor]);
        $runId = (int)$db->lastInsertId();

        $sources = $db->query('SELECT * FROM outstanding_debtors ORDER BY debtor_id')->fetchAll(PDO::FETCH_ASSOC);
        $matched = $mismatches = $normalizedCount = 0;
        $insert = $db->prepare(
            'INSERT INTO legacy_reconciliation_results
             (reconciliation_run_id, source_reference, source_name,
              source_pf_number, source_national_id, source_amount,
              normalized_debtor_id, normalized_account_id, normalized_amount,
              result_state, difference_amount, details)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($sources as $source) {
            $sourceRef = 'OUTSTANDING_DEBTOR:' . $source['debtor_id'];
            $component = componentExists($db, $sourceRef);
            $normalizedAmount = null;
            $debtorId = $accountId = null;
            $state = 'MISSING_NORMALIZED';
            $details = 'No normalized component was found';

            if ($component) {
                $normalizedCount++;
                $accountId = (int)$component['legacy_account_id'];
                $debtorStmt = $db->prepare(
                    'SELECT legacy_debtor_id FROM legacy_debt_accounts WHERE legacy_account_id = ?'
                );
                $debtorStmt->execute([$accountId]);
                $debtorId = (int)$debtorStmt->fetchColumn();

                $amountStmt = $db->prepare(
                    "SELECT COALESCE(SUM(debit_amount)-SUM(credit_amount),0)
                       FROM legacy_transactions
                      WHERE legacy_component_id = ?"
                );
                $amountStmt->execute([(int)$component['legacy_component_id']]);
                $normalizedAmount = (float)$amountStmt->fetchColumn();
                $sourceAmount = (float)$source['amount'];
                $difference = $normalizedAmount - $sourceAmount;

                if (abs($difference) < 0.00005) {
                    $state = 'MATCHED';
                    $details = 'Opening ledger equals source amount';
                    $matched++;
                } else {
                    $state = 'AMOUNT_MISMATCH';
                    $details = 'Source and normalized opening amounts differ';
                    $mismatches++;
                }
            } else {
                $difference = null;
                $mismatches++;
            }

            $insert->execute([
                $runId,
                $sourceRef,
                $source['name'],
                $source['pf_number'],
                $source['id_number'],
                $source['amount'],
                $debtorId,
                $accountId,
                $normalizedAmount,
                $state,
                $difference,
                $details
            ]);
        }

        $db->prepare(
            "UPDATE legacy_reconciliation_runs
                SET status=?, source_count=?, normalized_count=?, matched_count=?,
                    mismatch_count=?, completed_at=CURRENT_TIMESTAMP
              WHERE reconciliation_run_id=?"
        )->execute([
            $mismatches === 0 ? 'COMPLETED' : 'COMPLETED_WITH_MISMATCHES',
            count($sources),
            $normalizedCount,
            $matched,
            $mismatches,
            $runId
        ]);

        importAudit($db, 'legacy_reconciliation_run', $runId, 'RECONCILIATION_COMPLETED', $actor, 'Existing data reconciliation completed', [
            'source_count' => count($sources),
            'matched_count' => $matched,
            'mismatch_count' => $mismatches
        ]);

        $db->commit();
        importResponse(true, 'Existing-data reconciliation completed', [
            'reconciliation_run_id' => $runId,
            'run_reference' => $runReference,
            'source_count' => count($sources),
            'matched_count' => $matched,
            'mismatch_count' => $mismatches
        ]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        importResponse(false, 'Reconciliation rolled back: ' . $e->getMessage(), null, 422);
    }
}

try {
    $db = importDb();
    $method = $_SERVER['REQUEST_METHOD'];
    $action = strtoupper(trim((string)($_GET['action'] ?? '')));

    if ($method !== 'POST') {
        importResponse(false, 'Method not allowed', null, 405);
    }

    $actor = importUserId();

    if ($action === 'UPLOAD') {
        handleUpload($db, $actor);
    }

    $data = importJson();
    importCsrf($data);

    switch ($action) {
        case 'VALIDATE':
            handleValidate($db, $data, $actor);
            break;
        case 'CONFIRM':
            handleConfirm($db, $data, $actor);
            break;
        case 'POST':
            handlePost($db, $data, $actor);
            break;
        case 'MIGRATE_EXISTING':
            handleMigrateExisting($db, $actor);
            break;
        case 'RECONCILE_EXISTING':
            handleReconcileExisting($db, $actor);
            break;
        case 'RESOLVE_DUPLICATE':
            handleDuplicateResolution($db, $data, $actor);
            break;
        default:
            importResponse(false, 'Unknown legacy import action', null, 422);
    }
} catch (Throwable $e) {
    error_log('[Legacy Import API] ' . $e->getMessage());
    importResponse(false, 'Legacy import operation failed', null, 500);
}

