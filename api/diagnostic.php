<?php
/**
 * Final API Diagnostic Tool
 * Aligned with your exact database schema
 */

// Start session at the very beginning, before any output
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>

<head>
    <title>API Diagnostic Tool - Final</title>
    <style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        padding: 20px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        min-height: 100vh;
    }

    .container {
        max-width: 1200px;
        margin: 0 auto;
    }

    .header {
        background: white;
        padding: 30px;
        border-radius: 10px 10px 0 0;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
    }

    h1 {
        color: #333;
        font-size: 2em;
        margin-bottom: 10px;
    }

    .subtitle {
        color: #666;
        font-size: 1.1em;
    }

    .content {
        background: white;
        padding: 30px;
        border-radius: 0 0 10px 10px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
    }

    .test-group {
        margin: 30px 0;
    }

    .test-group h2 {
        color: #4a6b4a;
        border-bottom: 3px solid #4a6b4a;
        padding-bottom: 10px;
        margin-bottom: 20px;
    }

    .test {
        margin: 15px 0;
        padding: 15px 20px;
        border-radius: 8px;
        border-left: 5px solid #ccc;
        background: #f9f9f9;
        display: flex;
        align-items: center;
        transition: transform 0.2s;
    }

    .test:hover {
        transform: translateX(5px);
    }

    .test.success {
        border-left-color: #4caf50;
        background: #e8f5e9;
    }

    .test.error {
        border-left-color: #f44336;
        background: #ffebee;
    }

    .test.warning {
        border-left-color: #ff9800;
        background: #fff3e0;
    }

    .test .icon {
        font-size: 24px;
        margin-right: 15px;
    }

    .test .content-text {
        flex: 1;
    }

    .test .label {
        font-weight: bold;
        color: #333;
    }

    .test .detail {
        color: #666;
        font-size: 0.9em;
        margin-top: 5px;
    }

    pre {
        background: #2d2d2d;
        color: #f8f8f2;
        padding: 15px;
        border-radius: 5px;
        overflow-x: auto;
        margin: 10px 0;
        font-size: 0.9em;
    }

    .stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin: 30px 0;
    }

    .stat-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 20px;
        border-radius: 10px;
        text-align: center;
    }

    .stat-number {
        font-size: 2.5em;
        font-weight: bold;
        margin: 10px 0;
    }

    .stat-label {
        font-size: 0.9em;
        opacity: 0.9;
    }

    .btn {
        display: inline-block;
        padding: 12px 24px;
        background: #4a6b4a;
        color: white;
        text-decoration: none;
        border-radius: 5px;
        margin: 10px 5px 0 0;
        font-weight: bold;
        transition: background 0.3s;
    }

    .btn:hover {
        background: #3a5a3a;
    }

    .summary {
        background: #f0f7ff;
        border: 2px solid #2196F3;
        padding: 20px;
        border-radius: 10px;
        margin: 30px 0;
    }

    .summary h3 {
        color: #1976D2;
        margin-bottom: 15px;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin: 20px 0;
    }

    th,
    td {
        padding: 12px;
        text-align: left;
        border-bottom: 1px solid #ddd;
    }

    th {
        background: #4a6b4a;
        color: white;
    }

    tr:hover {
        background: #f5f5f5;
    }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1>🔍 API Diagnostic Tool</h1>
            <p class="subtitle">Complete system health check for your loan management system</p>
        </div>

        <div class="content">
            <?php
            // Initialize statistics array
            $stats = [
                'total_tests' => 0,
                'passed' => 0,
                'failed' => 0,
                'warnings' => 0
            ];
            
            function test($label, $condition, $successMsg, $errorMsg, $detail = '') {
                global $stats;
                $stats['total_tests']++;
                
                if ($condition) {
                    $stats['passed']++;
                    echo "<div class='test success'>";
                    echo "<div class='icon'>✓</div>";
                    echo "<div class='content-text'>";
                    echo "<div class='label'>" . htmlspecialchars($label) . "</div>";
                    echo "<div class='detail'>" . htmlspecialchars($successMsg) . "</div>";
                    if ($detail) echo "<div class='detail' style='margin-top: 5px;'>" . htmlspecialchars($detail) . "</div>";
                    echo "</div></div>";
                } else {
                    $stats['failed']++;
                    echo "<div class='test error'>";
                    echo "<div class='icon'>✗</div>";
                    echo "<div class='content-text'>";
                    echo "<div class='label'>" . htmlspecialchars($label) . "</div>";
                    echo "<div class='detail'>" . htmlspecialchars($errorMsg) . "</div>";
                    if ($detail) echo "<div class='detail' style='margin-top: 5px; color: #d32f2f;'>" . htmlspecialchars($detail) . "</div>";
                    echo "</div></div>";
                }
            }
            
            function warning($label, $message, $detail = '') {
                global $stats;
                $stats['warnings']++;
                echo "<div class='test warning'>";
                echo "<div class='icon'>⚠</div>";
                echo "<div class='content-text'>";
                echo "<div class='label'>" . htmlspecialchars($label) . "</div>";
                echo "<div class='detail'>" . htmlspecialchars($message) . "</div>";
                if ($detail) echo "<div class='detail' style='margin-top: 5px;'>" . htmlspecialchars($detail) . "</div>";
                echo "</div></div>";
            }
            
            // Test 1: PHP Environment
            echo "<div class='test-group'><h2>1. PHP Environment</h2>";
            
            $phpVersion = phpversion();
            test(
                'PHP Version',
                version_compare($phpVersion, '7.4.0', '>='),
                "PHP $phpVersion is installed ✓",
                "PHP $phpVersion is too old. Minimum required: 7.4.0",
                "Current: $phpVersion"
            );
            
            $requiredExtensions = ['pdo', 'pdo_mysql', 'json', 'session', 'mbstring'];
            foreach ($requiredExtensions as $ext) {
                test(
                    "Extension: $ext",
                    extension_loaded($ext),
                    "Loaded and available",
                    "Not loaded - required for system operation"
                );
            }
            
            echo "</div>";
            
            // Test 2: File Structure
            echo "<div class='test-group'><h2>2. File Structure</h2>";
            
            $requiredFiles = [
                '../config/database.php' => 'Database configuration',
                '../config/config.php' => 'System configuration',
                '../helpers/response_helper.php' => 'API response handler',
                'funds.php' => 'Funding accounts API',
                'loans.php' => 'Loan applications API',
                'staff.php' => 'Staff management API',
                'settings.php' => 'System settings API',
                'activity.php' => 'Activity log API',
                'logs.php' => 'Admin logs API'
            ];
            
            foreach ($requiredFiles as $file => $desc) {
                $path = __DIR__ . '/' . $file;
                $exists = file_exists($path);
                $readable = $exists && is_readable($path);
                
                test(
                    $desc,
                    $exists && $readable,
                    "File exists and is readable",
                    $exists ? "File exists but cannot be read" : "File is missing",
                    $file
                );
            }
            
            echo "</div>";
            
            // Test 3: Database Connection & Schema
            echo "<div class='test-group'><h2>3. Database Connection & Schema</h2>";
            
            try {
                require_once __DIR__ . '/../config/database.php';
                $database = new Database();
                $db = $database->getConnection();
                
                test(
                    'Database Connection',
                    $db instanceof PDO,
                    'Connected to database successfully',
                    'Failed to establish database connection'
                );
                
                // Test tables
                $requiredTables = [
                    'users' => 'User accounts',
                    'loan_applications' => 'Loan applications',
                    'funding_accounts' => 'Funding accounts',
                    'system_settings' => 'System settings',
                    'activity_log' => 'Activity log',
                    'admin_logs' => 'Admin logs'
                ];
                
                echo "<h3 style='margin-top: 30px; color: #666;'>Database Tables</h3>";
                
                foreach ($requiredTables as $table => $desc) {
                    $stmt = $db->query("SHOW TABLES LIKE '$table'");
                    $exists = $stmt && $stmt->rowCount() > 0;
                    
                    if ($exists) {
                        $countStmt = $db->query("SELECT COUNT(*) as count FROM `$table`");
                        $count = $countStmt->fetch(PDO::FETCH_ASSOC)['count'];
                        test(
                            $desc,
                            true,
                            "Table exists with $count rows",
                            '',
                            "Table: $table"
                        );
                    } else {
                        test(
                            $desc,
                            false,
                            '',
                            "Table does not exist in database",
                            "Table: $table - Run setup.sql to create"
                        );
                    }
                }
                
                // Verify column structure for critical tables
                echo "<h3 style='margin-top: 30px; color: #666;'>Column Structure Verification</h3>";
                
                // Check funding_accounts
                $stmt = $db->query("SHOW COLUMNS FROM funding_accounts");
                $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                test(
                    'funding_accounts structure',
                    in_array('id', $columns) && in_array('name', $columns) && in_array('balance', $columns),
                    'Correct column names: id, name, balance',
                    'Missing required columns',
                    'Columns: ' . implode(', ', $columns)
                );
                
                // Check system_settings
                $stmt = $db->query("SHOW COLUMNS FROM system_settings");
                $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                test(
                    'system_settings structure',
                    in_array('setting_key', $columns) && in_array('value', $columns),
                    'Correct structure with setting_key and value columns',
                    'Missing required columns',
                    'Columns: ' . implode(', ', $columns)
                );
                
                // Check for amortization rate
                $stmt = $db->query("SELECT * FROM system_settings WHERE setting_key = 'amortization_rate'");
                $setting = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($setting !== false) {
                    test(
                        'Default amortization rate',
                        true,
                        "Configured: {$setting['value']}%",
                        ''
                    );
                } else {
                    test(
                        'Default amortization rate',
                        false,
                        '',
                        'Not configured - needs to be added',
                        "Run: INSERT INTO system_settings (setting_key, value) VALUES ('amortization_rate', '3.0')"
                    );
                }
                
                // Check activity_log structure
                $stmt = $db->query("SHOW COLUMNS FROM activity_log");
                $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $hasCorrectStructure = in_array('id', $columns) && in_array('type', $columns) && in_array('loan_id', $columns);
                
                if (!$hasCorrectStructure) {
                    warning(
                        'activity_log structure',
                        'Table structure may need updating',
                        'Expected columns: id, type, loan_id, admin_id, details, timestamp. Found: ' . implode(', ', $columns)
                    );
                } else {
                    test(
                        'activity_log structure',
                        true,
                        'Correct column structure',
                        '',
                        'Columns: ' . implode(', ', $columns)
                    );
                }
                
            } catch (Exception $e) {
                test(
                    'Database Connection',
                    false,
                    '',
                    'Failed to connect to database',
                    $e->getMessage()
                );
            }
            
            echo "</div>";
            
            // Test 4: Session Management
            echo "<div class='test-group'><h2>4. Session Management</h2>";
            
            test(
                'Session Status',
                session_status() === PHP_SESSION_ACTIVE,
                'PHP sessions are working',
                'Cannot start sessions'
            );
            
            $_SESSION['diagnostic_test'] = 'test_value';
            test(
                'Session Write',
                isset($_SESSION['diagnostic_test']) && $_SESSION['diagnostic_test'] === 'test_value',
                'Session read/write is working',
                'Cannot write to session'
            );
            unset($_SESSION['diagnostic_test']);
            
            echo "</div>";
            
            // Statistics Summary
            $successRate = $stats['total_tests'] > 0 ? round(($stats['passed'] / $stats['total_tests']) * 100) : 0;
            ?>

            <div class="summary">
                <h3>📊 Diagnostic Summary</h3>
                <div class="stats">
                    <div class="stat-card">
                        <div class="stat-label">Total Tests</div>
                        <div class="stat-number"><?= $stats['total_tests'] ?></div>
                    </div>
                    <div class="stat-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <div class="stat-label">Passed</div>
                        <div class="stat-number"><?= $stats['passed'] ?></div>
                    </div>
                    <div class="stat-card" style="background: linear-gradient(135deg, #eb3349 0%, #f45c43 100%);">
                        <div class="stat-label">Failed</div>
                        <div class="stat-number"><?= $stats['failed'] ?></div>
                    </div>
                    <div class="stat-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <div class="stat-label">Warnings</div>
                        <div class="stat-number"><?= $stats['warnings'] ?></div>
                    </div>
                </div>

                <div style="text-align: center; margin-top: 20px;">
                    <div
                        style="font-size: 1.5em; color: <?= $successRate >= 80 ? '#4caf50' : ($successRate >= 60 ? '#ff9800' : '#f44336') ?>;">
                        Success Rate: <strong><?= $successRate ?>%</strong>
                    </div>
                </div>
            </div>

            <?php if ($stats['failed'] > 0 || $stats['warnings'] > 0): ?>
            <div
                style="background: #fff3cd; border: 2px solid #ffc107; padding: 20px; border-radius: 10px; margin: 20px 0;">
                <h3 style="color: #856404; margin-bottom: 15px;">⚠️ Action Required</h3>
                <p><strong>Some issues were detected.</strong> Please review the failed tests above and take corrective
                    action.</p>
                <ul style="margin: 15px 0 15px 20px; color: #856404;">
                    <?php if ($stats['failed'] > 0): ?>
                    <li>Fix <?= $stats['failed'] ?> failed test(s) before proceeding</li>
                    <?php endif; ?>
                    <?php if ($stats['warnings'] > 0): ?>
                    <li>Review <?= $stats['warnings'] ?> warning(s) - these may impact functionality</li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php else: ?>
            <div
                style="background: #d4edda; border: 2px solid #28a745; padding: 20px; border-radius: 10px; margin: 20px 0;">
                <h3 style="color: #155724; margin-bottom: 15px;">✅ All Systems Operational</h3>
                <p><strong>Congratulations!</strong> All diagnostic tests passed. Your system is properly configured and
                    ready to use.</p>
            </div>
            <?php endif; ?>

            <div style="margin-top: 30px; text-align: center;">
                <a href="../admin.php" class="btn">← Back to Admin Portal</a>
                <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" class="btn" style="background: #2196F3;">🔄
                    Re-run Diagnostic</a>
            </div>
        </div>
    </div>
</body>

</html>