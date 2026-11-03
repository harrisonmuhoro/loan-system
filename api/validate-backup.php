<?php
// api/validate-backup.php - FINAL PRODUCTION VERSION
// • Smart reserved keyword detection (no false positives on `timestamp`)
// • CSRF + Auth + JSON validation
// • Clear, actionable report
// • Zero noise, maximum safety

declare(strict_types=1);

ob_clean();
header('Content-Type: application/json; charset=utf-8');

// Start session & enforce admin access
session_start();

if (
    !isset($_SESSION['user_role'], $_SESSION['user_id'], $_SESSION['csrf_token']) ||
    $_SESSION['user_role'] !== 'admin'
) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access'], JSON_PRETTY_PRINT);
    exit;
}

// Read raw input
$rawInput = file_get_contents('php://input');
if ($rawInput === false || $rawInput === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Empty request body'], JSON_PRETTY_PRINT);
    exit;
}

$input = json_decode($rawInput, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON: ' . json_last_error_msg()
    ], JSON_PRETTY_PRINT);
    exit;
}

// CSRF check
if (empty($input['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $input['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid or missing CSRF token'], JSON_PRETTY_PRINT);
    exit;
}

// Extract backup
$backup = $input['backup_data'] ?? null;
if (!is_array($backup) || $backup === []) {
    echo json_encode([
        'success' => false,
        'message' => 'No backup data provided or invalid format'
    ], JSON_PRETTY_PRINT);
    exit;
}

// === Report Setup ===
$report = [
    'success' => true,
    'message' => '',
    'summary' => [
        'total_tables' => 0,
        'total_rows' => 0,
        'metadata_found' => false
    ],
    'tables' => [],
    'issues' => []
];

// === Reserved keyword categories ===
$dangerousKeywords = [  // These WILL break queries if not quoted
    'SELECT','INSERT','UPDATE','DELETE','FROM','WHERE','JOIN','ON',
    'GROUP','ORDER','HAVING','TABLE','DATABASE','CREATE','DROP','ALTER'
];

$safeReserved = [       // Common in apps, safe when quoted (we ignore warnings)
    'TIMESTAMP','DATE','TIME','DATETIME','YEAR',
    'USER','KEY','INDEX','STATUS','TYPE','LEVEL','COMMENT'
];

// === Validate each table ===
foreach ($backup as $tableName => $rows) {
    // Skip metadata
    if ($tableName === '_metadata') {
        $report['summary']['metadata_found'] = true;
        continue;
    }

    if (!is_string($tableName) || !is_array($rows)) {
        $report['issues'][] = "Invalid entry: '$tableName' is not a valid table";
        $report['success'] = false;
        continue;
    }

    $rowCount = count($rows);
    $report['summary']['total_rows'] += $rowCount;
    $report['summary']['total_tables']++;

    $tableInfo = [
        'name' => $tableName,
        'row_count' => $rowCount,
        'columns' => [],
        'sample_columns_count' => 0
    ];

    if ($rowCount === 0) {
        $tableInfo['note'] = 'Table is empty';
        $report['tables'][] = $tableInfo;
        continue;
    }

    $firstRow = $rows[0];
    if (!is_array($firstRow)) {
        $report['issues'][] = "Table `$tableName`: First row is not an associative array";
        $report['success'] = false;
        $report['tables'][] = $tableInfo;
        continue;
    }

    $columns = array_keys($firstRow);
    $tableInfo['columns'] = $columns;
    $tableInfo['sample_columns_count'] = count($columns);

    foreach ($columns as $col) {
        if (!is_string($col)) {
            $report['issues'][] = "Table `$tableName`: Non-string column name detected";
            $report['success'] = false;
            continue;
        }

        // Critical structural issues
        if (preg_match('/\s/', $col)) {
            $report['issues'][] = "Table `$tableName`: Column contains whitespace: `$col`";
            $report['success'] = false;
        }

        if (preg_match('/[^a-zA-Z0-9_]/', $col)) {
            $report['issues'][] = "Table `$tableName`: Column contains special characters: `$col`";
            $report['success'] = false;
        }

        if (preg_match('/^\d/', $col)) {
            $report['issues'][] = "Table `$tableName`: Column starts with number: `$col` (invalid identifier)";
            $report['success'] = false;
        }

        // Reserved keyword check
        $upper = strtoupper($col);
        if (in_array($upper, $dangerousKeywords, true)) {
            $report['issues'][] = "Table `$tableName`: DANGEROUS reserved keyword: `$col` → WILL cause SQL errors if not quoted";
            $report['success'] = false;
        }
        // Safe reserved keywords (like timestamp) → no warning
        // We assume your app already quotes them properly
    }

    $report['tables'][] = $tableInfo;
}

// === Final message ===
if ($report['success']) {
    $report['message'] = 'Backup is valid and safe to restore';
} else {
    $report['message'] = count($report['issues']) . ' critical issue(s) found — fix before restoring';
}

// === Output ===
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;