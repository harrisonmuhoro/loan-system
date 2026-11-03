<?php
require_once __DIR__ . '/legacy_deprecated_guard.php';
rejectDeprecatedLegacyEndpoint('legacy-debt.php and api/legacy_migration.php');
/**
 * Migration API - Convert pending outstanding_debtors to loan_applications
 * Location: /api/migrate_outstanding_to_loans.php
 *
 * Migrates legacy debtors from a specific financial year into the modern
 * loan_applications table as active mortgage loans.
 */

ob_start(); // Start output buffering early

session_start();

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Clean any accidental output
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Authorization: only admin and cashier allowed
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) ||
    !in_array($_SESSION['user_role'], ['admin', 'cashier'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Read and parse JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
    exit;
}

// Validate CSRF token
if (!isset($data['csrf_token']) || $data['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
    exit;
}

// Validate financial year parameter
if (empty($data['financial_year'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Financial year is required']);
    exit;
}

$financialYear = trim($data['financial_year']);

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Ensure consistent character set/collation
    $conn->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

    // Fetch only unmigrated debtors for the given financial year
    $stmt = $conn->prepare("
        SELECT * FROM outstanding_debtors 
        WHERE financial_year = :fy 
          AND migrated_to_loan = 0
        ORDER BY name ASC
    ");
    $stmt->execute([':fy' => $financialYear]);
    $debtors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($debtors)) {
        echo json_encode([
            'success' => true,
            'message' => 'No pending debtors found for migration in FY ' . $financialYear,
            'data' => ['imported' => 0, 'skipped' => 0, 'total_amount' => 0, 'errors' => []]
        ]);
        exit;
    }

    // Start transaction
    $conn->beginTransaction();

    $imported     = 0;
    $skipped      = 0;
    $totalAmount  = 0.0;
    $errors       = [];

    // Prepared statements
    $insertLoan = $conn->prepare("
        INSERT INTO loan_applications (
            application_id,
            full_name,
            pf_number,
            id_number,
            loan_amount,
            loan_type,
            status,
            feedback,
            application_date,
            dispatch_date,
            created_at,
            updated_at
        ) VALUES (
            ?, ?, ?, ?, ?, 'mortgage', ?, ?, NOW(), NULL, NOW(), NOW()
        )
    ");

    $updateDebtor = $conn->prepare("
        UPDATE outstanding_debtors 
        SET migrated_to_loan = 1, 
            loan_application_id = :app_id,
            updated_at = NOW()
        WHERE debtor_id = :debtor_id
    ");

    $checkAppId = $conn->prepare("
        SELECT 1 FROM loan_applications 
        WHERE application_id = ? LIMIT 1
    ");

    foreach ($debtors as $debtor) {
        $debtorId   = (int)$debtor['debtor_id'];
        $name       = $debtor['name'] ?? 'Unknown';
        $pfNumber   = trim($debtor['pf_number'] ?? '');
        $idNumber   = trim($debtor['id_number'] ?? '');
        $amount     = floatval($debtor['amount'] ?? 0);

        // Validate PF number (strict numeric check - adjust regex if alphanumeric PFs are allowed)
        if (!empty($pfNumber) && !preg_match('/^\d+$/', $pfNumber)) {
            error_log("Migration skipped - Invalid PF number for debtor #$debtorId ($name): '$pfNumber'");
            $skipped++;
            $errors[] = "Invalid PF number for '$name' (ID: $debtorId): '$pfNumber'";
            continue;
        }

        // Skip zero or negative amounts
        if ($amount <= 0) {
            error_log("Migration skipped - Invalid amount for debtor #$debtorId ($name): $amount");
            $skipped++;
            $errors[] = "Invalid loan amount for '$name' (ID: $debtorId): $amount";
            continue;
        }

        // Generate unique application ID
        $baseYear = str_replace('/', '-', $financialYear);
        $baseSeq  = str_pad($debtorId, 6, '0', STR_PAD_LEFT);
        $appId    = "MIGRATED-{$baseYear}-{$baseSeq}";

        // Handle collision by appending incrementing suffix
        $suffix = 2;
        $maxAttempts = 100;
        while ($suffix <= $maxAttempts) {
            $checkAppId->execute([$appId]);
            if ($checkAppId->fetchColumn() === false) {
                break;
            }
            $appId = "MIGRATED-{$baseYear}-{$baseSeq}-{$suffix}";
            $suffix++;
        }

        if ($suffix > $maxAttempts) {
            $errors[] = "Failed to generate unique application ID for debtor #$debtorId ($name)";
            $skipped++;
            continue;
        }

        // Determine status
        $status = in_array($debtor['status'] ?? 'active', ['active', 'dispatched', 'closed'])
            ? $debtor['status']
            : 'active';

        $feedback = "Legacy loan migrated from FY {$financialYear}. " .
                    "Original debtor ID: {$debtorId}. " .
                    "Imported on " . date('Y-m-d H:i:s');

        // Insert new loan application
        $insertLoan->execute([
            $appId,
            $name,
            $pfNumber ?: null,
            $idNumber ?: null,
            $amount,
            $status,
            $feedback
        ]);

        // Update original debtor record
        $updateDebtor->execute([
            ':app_id'    => $appId,
            ':debtor_id' => $debtorId
        ]);

        // After updating the debtor, delete it 
        $deleteDebtor = $conn->prepare("DELETE FROM outstanding_debtors WHERE debtor_id = :debtor_id");
        $deleteDebtor->execute([':debtor_id' => $debtorId]);
        
        $imported++;
        $totalAmount += $amount;
    }

    $conn->commit();

    // Log the migration activity
    if (isset($_SESSION['user_id'])) {
        $logStmt = $conn->prepare("
            INSERT INTO activity_log (admin_id, action, details, timestamp)
            VALUES (:uid, 'migrate_legacy_debtors', :details, NOW())
        ");
        $details = "Migrated $imported debtors → loans for FY $financialYear " .
                   "(Total: KES " . number_format($totalAmount, 2) . "). " .
                   "Skipped: $skipped";
        if (!empty($errors)) {
            $details .= " Errors: " . implode('; ', $errors);
        }
        $logStmt->execute([
            ':uid'     => $_SESSION['user_id'],
            ':details' => $details
        ]);
    }

    // Prepare success response
    $message = "Successfully migrated $imported debtor(s)";
    if ($skipped > 0) $message .= " ($skipped skipped)";
    if (!empty($errors)) $message .= ". Check logs for details.";

    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => [
            'imported'      => $imported,
            'skipped'       => $skipped,
            'total_amount'  => $totalAmount,
            'errors'        => $errors
        ]
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }

    error_log("Migration failed for FY $financialYear: " . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Migration failed: ' . $e->getMessage(),
        'data' => [
            'imported'      => 0,
            'skipped'       => 0,
            'total_amount'  => 0,
            'errors'        => [$e->getMessage()]
        ]
    ]);
}
