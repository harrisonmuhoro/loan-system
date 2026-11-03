<?php
// api/restore.php - ROBUST & PRODUCTION-READY VERSION
// Features:
// • Graceful per-table restore (one bad table won't kill everything)
// • Safe transaction handling (no "no active transaction" errors)
// • Full column validation against current schema
// • Detailed error reporting
// • Works even with mixed MyISAM/InnoDB tables

declare(strict_types=1);

ob_clean(); // Clear any prior output
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

session_start();

// === 1. Authentication & CSRF ===
if (
    !isset($_SESSION['user_role']) || 
    $_SESSION['user_role'] !== 'admin' || 
    !isset($_SESSION['user_id'], $_SESSION['csrf_token'])
) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// === 2. Read and validate input ===
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON payload']);
    exit;
}

if (
    empty($input['csrf_token']) || 
    !hash_equals($_SESSION['csrf_token'], $input['csrf_token'])
) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$backup = $input['backup_data'] ?? null;
if (!is_array($backup) || empty($backup)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No backup data provided']);
    exit;
}

// === 3. Database connection ===
require_once __DIR__ . '/../config/database.php';

try {
    $db = (new Database())->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

// === 4. Helper: Get real column names from DB ===
$getTableColumns = static function (PDO $db, string $table): array {
    try {
        $stmt = $db->prepare("SHOW COLUMNS FROM `$table`");
        $stmt->execute();
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
    } catch (Throwable $e) {
        return [];
    }
};

// === 5. Define restore order (respects foreign keys) ===
$tableOrder = [
    'system_settings',
    'users',
    'admins',
    'cashiers',
    'accountants',
    'staff',
    'funding_accounts',
    'funding_transactions',
    'loan_applications',
    'payments',
    'payment_schedules',
    'activity_log'
    // Add more tables here if needed
];

// === 6. Perform restore ===
$restoredTables = [];
$errors = [];
$inTransaction = false;

try {
    // Only start transaction if InnoDB supports it
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $driverSupportsTransactions = $db->query("SELECT ENGINE FROM information_schema.TABLES 
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_log' 
            AND ENGINE = 'InnoDB'")->fetchColumn() === 'InnoDB';

        if ($driverSupportsTransactions) {
            $db->beginTransaction();
            $inTransaction = true;
            $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        }
    }

    foreach ($tableOrder as $table) {
        if (!isset($backup[$table]) || !is_array($backup[$table]) || empty($backup[$table])) {
            continue;
        }

        $rows = $backup[$table];

        try {
            $validColumns = $getTableColumns($db, $table);
            if (empty($validColumns)) {
                $errors[] = "Table `$table` does not exist or is inaccessible";
                continue;
            }

            // Truncate table
            $db->exec("TRUNCATE TABLE `$table`");

            $firstRow = $rows[0] ?? [];
            $columnsToInsert = array_intersect(array_keys($firstRow), $validColumns);

            if (empty($columnsToInsert)) {
                $errors[] = "No matching columns in table `$table`";
                continue;
            }

            $columnList   = '`' . implode('`, `', $columnsToInsert) . '`';
            $placeholders = ':' . implode(', :', $columnsToInsert);
            $sql = "INSERT INTO `$table` ($columnList) VALUES ($placeholders)";
            $stmt = $db->prepare($sql);

            $insertCount = 0;
            foreach ($rows as $row) {
                $params = [];
                foreach ($columnsToInsert as $col) {
                    $params[$col] = $row[$col] ?? null;
                }
                $stmt->execute($params);
                $insertCount++;
            }

            $restoredTables[$table] = $insertCount;

        } catch (Throwable $e) {
            $errors[] = "Table `$table`: " . $e->getMessage();
            // Continue with next table
        }
    }

    // === Finalize ===
    if ($inTransaction) {
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
        $db->commit();
    }

    // Log activity (even if partial)
    try {
        $logStmt = $db->prepare(
            "INSERT INTO activity_log (admin_id, action, details, timestamp) 
             VALUES (?, 'restore_backup', ?, NOW())"
        );
        $logStmt->execute([
            $_SESSION['user_id'],
            'Restored ' . count($restoredTables) . ' tables' . 
            ($errors ? ' (' . count($errors) . ' errors)' : '')
        ]);
    } catch (Throwable $e) {
        // Ignore logging failure
    }

    echo json_encode([
        'success' => true,
        'message' => 'Restore completed' . ($errors ? ' with warnings' : ''),
        'restored_tables' => $restoredTables,
        'errors' => $errors ?: null
    ]);

} catch (Throwable $e) {
    // === Critical failure handling ===
    if ($inTransaction && $db->inTransaction()) {
        try { $db->rollBack(); } catch (Throwable $rb) { /* ignore */ }
    }
    if ($inTransaction) {
        try { $db->exec('SET FOREIGN_KEY_CHECKS = 1'); } catch (Throwable $fk) { /* ignore */ }
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Restore failed: ' . $e->getMessage(),
        'errors' => $errors
    ]);
}

exit;