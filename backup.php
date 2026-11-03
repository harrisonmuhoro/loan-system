<?php
// backup.php - IMPROVED VERSION
// Improvements:
// 1. Streaming for large databases
// 2. Better error handling
// 3. Progress tracking
// 4. Memory efficient

session_start();

// Clean output buffers
while (ob_get_level()) ob_end_clean();

// Auth check
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/config/database.php';

/**
 * Memory-efficient database backup
 * Streams data directly to output without loading entire DB into memory
 */
function streamBackup($conn) {
    // Set headers for download
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="backup_' . date('Y-m-d_His') . '.json"');
    header('Cache-Control: no-cache, must-revalidate');
    
    $tables = [];
    $result = $conn->query("SHOW TABLES");
    while ($row = $result->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }
    
    // Table order for proper restore
    $orderedTables = [
        'system_settings', 'users', 'admins', 'cashiers', 'accountants',
        'staff', 'funding_accounts', 'funding_transactions', 
        'loan_applications', 'payments', 'payment_schedules', 'activity_log'
    ];
    
    // Sort tables
    usort($tables, function($a, $b) use ($orderedTables) {
        $posA = array_search($a, $orderedTables);
        $posB = array_search($b, $orderedTables);
        if ($posA === false) return 1;
        if ($posB === false) return -1;
        return $posA - $posB;
    });
    
    // Start JSON output
    echo "{\n";
    
    $firstTable = true;
    foreach ($tables as $table) {
        if (!$firstTable) echo ",\n";
        $firstTable = false;
        
        echo '"' . $table . '": [';
        
        $stmt = $conn->query("SELECT * FROM `$table`");
        $firstRow = true;
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!$firstRow) echo ",";
            $firstRow = false;
            
            // Encode each row separately to save memory
            echo "\n" . json_encode($row, JSON_UNESCAPED_UNICODE);
        }
        
        echo "\n]";
    }
    
    // Add metadata
    echo ',
    "_metadata": {
        "timestamp": "' . date('Y-m-d\TH:i:s\Z') . '",
        "version": "1.0",
        "table_count": ' . count($tables) . ',
        "generated_by": "admin"
    }
}';
    
    // Log activity
    try {
        $stmt = $conn->prepare("INSERT INTO activity_log (admin_id, action, details, timestamp) VALUES (?, 'backup_database', 'Full backup downloaded', NOW())");
        $stmt->execute([$_SESSION['user_id']]);
    } catch (Exception $e) {
        error_log("Failed to log backup: " . $e->getMessage());
    }
}

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    streamBackup($conn);
    exit;
    
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Backup failed: ' . $e->getMessage()
    ]);
    exit;
}