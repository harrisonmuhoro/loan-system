<?php
/**
 * Diagnostic Script for Loan Application Loading Issues
 * Place this file in your root directory and access it directly
 * Example: http://localhost/loan-system/debug_loans.php
 */

session_start();

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include database connection
require_once __DIR__ . '/config/database.php';

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan System Diagnostics</title>
    <style>
    body {
        font-family: monospace;
        padding: 20px;
        background: #f5f5f5;
    }

    .section {
        background: white;
        padding: 20px;
        margin-bottom: 20px;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    h2 {
        color: #2c3e50;
        border-bottom: 2px solid #3498db;
        padding-bottom: 10px;
    }

    .success {
        color: #27ae60;
        font-weight: bold;
    }

    .error {
        color: #e74c3c;
        font-weight: bold;
    }

    .warning {
        color: #f39c12;
        font-weight: bold;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
    }

    th,
    td {
        border: 1px solid #ddd;
        padding: 8px;
        text-align: left;
    }

    th {
        background-color: #3498db;
        color: white;
    }

    tr:nth-child(even) {
        background-color: #f2f2f2;
    }

    pre {
        background: #2c3e50;
        color: #ecf0f1;
        padding: 15px;
        border-radius: 4px;
        overflow-x: auto;
    }
    </style>
</head>

<body>
    <h1>🔍 Loan System Diagnostics</h1>

    <?php
    // Section 1: Session Information
    echo '<div class="section">';
    echo '<h2>1. Session Information</h2>';
    
    if (isset($_SESSION['user_id'])) {
        echo '<p class="success">✓ User is logged in</p>';
        echo '<table>';
        echo '<tr><th>Key</th><th>Value</th></tr>';
        foreach ($_SESSION as $key => $value) {
            if ($key !== 'csrf_token') {
                echo '<tr><td>' . htmlspecialchars($key) . '</td><td>' . htmlspecialchars($value) . '</td></tr>';
            }
        }
        echo '</table>';
    } else {
        echo '<p class="error">✗ No user logged in</p>';
        echo '<p>Please log in first, then refresh this page.</p>';
    }
    echo '</div>';

    // Section 2: Database Connection
    echo '<div class="section">';
    echo '<h2>2. Database Connection</h2>';
    
    try {
        $database = new Database();
        $conn = $database->getConnection();
        
        if ($conn) {
            echo '<p class="success">✓ Database connection successful</p>';
            
            // Test query
            $stmt = $conn->query("SELECT VERSION() as version");
            $version = $stmt->fetch(PDO::FETCH_ASSOC);
            echo '<p>MySQL Version: ' . $version['version'] . '</p>';
        } else {
            echo '<p class="error">✗ Database connection failed</p>';
        }
    } catch (Exception $e) {
        echo '<p class="error">✗ Database error: ' . htmlspecialchars($e->getMessage()) . '</p>';
    }
    echo '</div>';

    // Section 3: Loan Applications Count
    if (isset($conn) && $conn) {
        echo '<div class="section">';
        echo '<h2>3. Loan Applications in Database</h2>';
        
        try {
            // Total count
            $stmt = $conn->query("SELECT COUNT(*) as total FROM loan_applications");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            echo '<p><strong>Total Loan Applications:</strong> ' . $result['total'] . '</p>';
            
            // Count by status
            $stmt = $conn->query("SELECT status, COUNT(*) as count FROM loan_applications GROUP BY status");
            $statusCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($statusCounts) > 0) {
                echo '<table>';
                echo '<tr><th>Status</th><th>Count</th></tr>';
                foreach ($statusCounts as $row) {
                    echo '<tr><td>' . htmlspecialchars($row['status']) . '</td><td>' . $row['count'] . '</td></tr>';
                }
                echo '</table>';
            }
            
            // Recent applications
            $stmt = $conn->query("SELECT application_id, user_id, pf_number, id_number, full_name, loan_type, loan_amount, status, application_date FROM loan_applications ORDER BY application_date DESC LIMIT 5");
            $recentApps = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($recentApps) > 0) {
                echo '<h3>Recent Applications:</h3>';
                echo '<table>';
                echo '<tr><th>App ID</th><th>User ID</th><th>PF Number</th><th>Full Name</th><th>Type</th><th>Amount</th><th>Status</th><th>Date</th></tr>';
                foreach ($recentApps as $app) {
                    echo '<tr>';
                    echo '<td>' . htmlspecialchars($app['application_id']) . '</td>';
                    echo '<td>' . htmlspecialchars($app['user_id']) . '</td>';
                    echo '<td>' . htmlspecialchars($app['pf_number']) . '</td>';
                    echo '<td>' . htmlspecialchars($app['full_name']) . '</td>';
                    echo '<td>' . htmlspecialchars($app['loan_type']) . '</td>';
                    echo '<td>KES ' . number_format($app['loan_amount']) . '</td>';
                    echo '<td>' . htmlspecialchars($app['status']) . '</td>';
                    echo '<td>' . htmlspecialchars($app['application_date']) . '</td>';
                    echo '</tr>';
                }
                echo '</table>';
            }
        } catch (PDOException $e) {
            echo '<p class="error">✗ Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
        echo '</div>';
    }

    // Section 4: User-Specific Loan Check
    if (isset($_SESSION['user_id']) && isset($conn) && $conn) {
        echo '<div class="section">';
        echo '<h2>4. Your Loan Applications</h2>';
        
        $userId = $_SESSION['user_id'];
        $pfNumber = $_SESSION['pf_number'] ?? null;
        $idNumber = $_SESSION['id_number'] ?? null;
        
        try {
            // Check by user_id
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM loan_applications WHERE user_id = :user_id");
            $stmt->execute([':user_id' => $userId]);
            $userIdCount = $stmt->fetch(PDO::FETCH_ASSOC);
            echo '<p>Loans matching User ID (' . $userId . '): <strong>' . $userIdCount['count'] . '</strong></p>';
            
            // Check by pf_number
            if ($pfNumber) {
                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM loan_applications WHERE pf_number = :pf_number");
                $stmt->execute([':pf_number' => $pfNumber]);
                $pfCount = $stmt->fetch(PDO::FETCH_ASSOC);
                echo '<p>Loans matching PF Number (' . $pfNumber . '): <strong>' . $pfCount['count'] . '</strong></p>';
            }
            
            // Check by id_number
            if ($idNumber) {
                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM loan_applications WHERE id_number = :id_number");
                $stmt->execute([':id_number' => $idNumber]);
                $idCount = $stmt->fetch(PDO::FETCH_ASSOC);
                echo '<p>Loans matching ID Number (' . $idNumber . '): <strong>' . $idCount['count'] . '</strong></p>';
            }
            
            // Get actual loans
            $sql = "SELECT * FROM loan_applications 
                    WHERE user_id = :user_id 
                       OR pf_number = :pf_number 
                       OR id_number = :id_number 
                    ORDER BY application_date DESC";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':user_id' => $userId,
                ':pf_number' => $pfNumber ?? '',
                ':id_number' => $idNumber ?? ''
            ]);
            
            $userLoans = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($userLoans) > 0) {
                echo '<h3 class="success">✓ Found ' . count($userLoans) . ' loan(s) for current user</h3>';
                echo '<table>';
                echo '<tr><th>App ID</th><th>User ID</th><th>Type</th><th>Amount</th><th>Status</th><th>Date</th></tr>';
                foreach ($userLoans as $loan) {
                    echo '<tr>';
                    echo '<td>' . htmlspecialchars($loan['application_id']) . '</td>';
                    echo '<td>' . htmlspecialchars($loan['user_id']) . '</td>';
                    echo '<td>' . htmlspecialchars($loan['loan_type']) . '</td>';
                    echo '<td>KES ' . number_format($loan['loan_amount']) . '</td>';
                    echo '<td>' . htmlspecialchars($loan['status']) . '</td>';
                    echo '<td>' . htmlspecialchars($loan['application_date']) . '</td>';
                    echo '</tr>';
                }
                echo '</table>';
            } else {
                echo '<p class="warning">⚠ No loans found for current user</p>';
                echo '<p>This might indicate a data mismatch issue.</p>';
            }
        } catch (PDOException $e) {
            echo '<p class="error">✗ Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
        echo '</div>';
    }

    // Section 5: API Test
    echo '<div class="section">';
    echo '<h2>5. API Endpoint Test</h2>';
    echo '<p>Testing API endpoint: <code>/api/loans.php</code></p>';
    
    if (isset($_SESSION['user_id'])) {
        echo '<button onclick="testAPI()">Test API Call</button>';
        echo '<div id="apiResult" style="margin-top: 15px;"></div>';
        
        echo '<script>
        async function testAPI() {
            const resultDiv = document.getElementById("apiResult");
            resultDiv.innerHTML = "<p>Testing API...</p>";
            
            try {
                const response = await fetch("/loan-system/api/loans.php", {
                    method: "GET",
                    credentials: "include",
                    headers: {
                        "Content-Type": "application/json"
                    }
                });
                
                const data = await response.json();
                
                resultDiv.innerHTML = "<h4>API Response:</h4><pre>" + JSON.stringify(data, null, 2) + "</pre>";
            } catch (error) {
                resultDiv.innerHTML = "<p class=\"error\">Error: " + error.message + "</p>";
            }
        }
        </script>';
    } else {
        echo '<p class="warning">Please log in to test API</p>';
    }
    echo '</div>';

    // Section 6: Recommendations
    echo '<div class="section">';
    echo '<h2>6. Recommendations</h2>';
    echo '<ul>';
    
    if (!isset($_SESSION['user_id'])) {
        echo '<li class="error">Log in to the system first</li>';
    }
    
    if (isset($conn) && $conn) {
        try {
            $stmt = $conn->query("SELECT COUNT(*) as total FROM loan_applications");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['total'] == 0) {
                echo '<li class="warning">No loan applications in database - create a test application</li>';
            } else if (isset($_SESSION['user_id']) && count($userLoans ?? []) == 0) {
                echo '<li class="error">User ID mismatch detected - run the following SQL to fix:</li>';
                echo '<pre>UPDATE loan_applications 
SET user_id = ' . $_SESSION['user_id'] . ' 
WHERE pf_number = \'' . ($_SESSION['pf_number'] ?? '') . '\' 
   OR id_number = \'' . ($_SESSION['id_number'] ?? '') . '\';</pre>';
            } else {
                echo '<li class="success">Database looks good!</li>';
            }
        } catch (Exception $e) {
            echo '<li class="error">Could not check database: ' . htmlspecialchars($e->getMessage()) . '</li>';
        }
    }
    
    echo '<li>Check browser console for JavaScript errors</li>';
    echo '<li>Check /logs/api_loans.log for API errors</li>';
    echo '<li>Ensure CSRF token is properly set in session</li>';
    echo '</ul>';
    echo '</div>';
    ?>

    <div class="section">
        <h2>7. Quick Fixes</h2>
        <p><strong>If loans are not showing for logged-in user:</strong></p>
        <ol>
            <li>Replace <code>/api/loans.php</code> with the fixed version from Artifact #1</li>
            <li>Clear your browser cache and cookies</li>
            <li>Log out and log back in</li>
            <li>Check the API test above</li>
        </ol>
    </div>
</body>

</html>