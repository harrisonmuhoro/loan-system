<?php
// Add this at the very top of all API files
error_reporting(0);
ini_set('display_errors', 0);

// Start output buffering to catch any accidental output
ob_start();

// Set content type header before any output
header('Content-Type: application/json');

// Include required files
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response_helper.php';

// Start session
session_start();

// Verify session
if (!isset($_SESSION['user_id'])) {
    ob_clean();
    ResponseHelper::unauthorized('Unauthorized. Please login.');
    exit();
}

// Verify cashier role
if ($_SESSION['role'] !== 'cashier') {
    ob_clean();
    ResponseHelper::forbidden('Only cashiers can make loan decisions.');
    exit();
}

// Initialize database
try {
    $database = new Database();
    $db = $database->getConnection();
} catch (Exception $e) {
    ob_clean();
    ResponseHelper::serverError('Database connection failed');
    exit();
}

// Handle request method
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        // Get decisions made by the current cashier
        $query = "SELECT d.*, la.full_name, la.pf_number, la.loan_type, la.loan_amount 
                 FROM decisions d
                 JOIN loan_applications la ON d.application_id = la.application_id
                 WHERE d.decision_maker_id = :user_id
                 ORDER BY d.decision_date DESC";

        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $_SESSION['user_id']);
        $stmt->execute();

        $decisions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ob_clean();
        ResponseHelper::success($decisions);

    } elseif ($method === 'POST') {
        // Get raw input
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            ob_clean();
            ResponseHelper::error('Invalid JSON data');
            exit();
        }

        // Validate CSRF token
        if (
            !isset($input['csrf_token']) || !isset($_SESSION['csrf_token']) ||
            $input['csrf_token'] !== $_SESSION['csrf_token']
        ) {
            ob_clean();
            ResponseHelper::forbidden('Invalid CSRF token');
            exit();
        }

        // Validate required fields
        $required = ['application_id', 'decision_type'];

        $missing = [];
        foreach ($required as $field) {
            if (!isset($input[$field]) || (is_string($input[$field]) && trim($input[$field]) === '')) {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            ob_clean();
            ResponseHelper::error("Missing required fields: " . implode(', ', $missing));
            exit();
        }

        // Validate decision type
        $validDecisions = ['forward', 'reject', 'close'];
        if (!in_array($input['decision_type'], $validDecisions)) {
            ob_clean();
            ResponseHelper::error('Invalid decision type');
            exit();
        }

        // Check if loan exists
        $query = "SELECT * FROM loan_applications WHERE application_id = :application_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':application_id', $input['application_id']);
        $stmt->execute();

        if ($stmt->rowCount() === 0) {
            ob_clean();
            ResponseHelper::notFound('Loan application not found');
            exit();
        }

        $loan = $stmt->fetch(PDO::FETCH_ASSOC);

        // Check if loan is in a valid state for this decision
        $currentStatus = $loan['status'];
        $decisionType = $input['decision_type'];

        if ($decisionType === 'forward' && !in_array($currentStatus, ['pending', 'returned'])) {
            ob_clean();
            ResponseHelper::error('Loan cannot be forwarded in its current status');
            exit();
        }

        if ($decisionType === 'reject' && !in_array($currentStatus, ['pending', 'returned'])) {
            ob_clean();
            ResponseHelper::error('Loan cannot be rejected in its current status');
            exit();
        }

        if ($decisionType === 'close' && !in_array($currentStatus, ['rejected', 'active', 'dispatched'])) {
            ob_clean();
            ResponseHelper::error('Loan cannot be closed in its current status');
            exit();
        }

        // Begin transaction
        $db->beginTransaction();

        try {
            // Record the decision
            $query = "INSERT INTO decisions 
                     (application_id, decision_type, decision_maker_id, decision_date, feedback, previous_status)
                     VALUES 
                     (:application_id, :decision_type, :decision_maker_id, NOW(), :feedback, :previous_status)";

            $stmt = $db->prepare($query);
            $stmt->bindParam(':application_id', $input['application_id']);
            $stmt->bindParam(':decision_type', $decisionType);
            $stmt->bindParam(':decision_maker_id', $_SESSION['user_id']);
            $stmt->bindParam(':feedback', $input['feedback']);
            $stmt->bindParam(':previous_status', $currentStatus);
            $stmt->execute();

            // Update loan status based on decision
            $newStatus = '';
            $feedback = $input['feedback'] ?? '';

            switch ($decisionType) {
                case 'forward':
                    $newStatus = 'forwarded';
                    if (empty($feedback)) {
                        $feedback = 'Forwarded to Accountant for approval.';
                    }
                    break;
                case 'reject':
                    $newStatus = 'rejected';
                    if (empty($feedback)) {
                        $feedback = 'Rejected by Cashier.';
                    }
                    break;
                case 'close':
                    $newStatus = 'closed';
                    if (empty($feedback)) {
                        $feedback = 'Case closed by Cashier.';
                    }
                    break;
            }

            // Update loan application
            $query = "UPDATE loan_applications 
                     SET status = :new_status, 
                         feedback = :feedback,
                         cashier_processed_date = NOW()
                     WHERE application_id = :application_id";

            $stmt = $db->prepare($query);
            $stmt->bindParam(':new_status', $newStatus);
            $stmt->bindParam(':feedback', $feedback);
            $stmt->bindParam(':application_id', $input['application_id']);
            $stmt->execute();

            // If forwarding to accountant, create a notification for accountants
            if ($decisionType === 'forward') {
                $query = "INSERT INTO notifications 
                         (user_id, notification_type, related_id, message, created_at)
                         SELECT u.user_id, 'loan_forwarded', :application_id, 
                                CONCAT('Loan for ', la.full_name, ' (', la.pf_number, ') has been forwarded for your review'), NOW()
                         FROM users u
                         CROSS JOIN loan_applications la
                         WHERE u.role = 'accountant' AND la.application_id = :application_id";

                $stmt = $db->prepare($query);
                $stmt->bindParam(':application_id', $input['application_id']);
                $stmt->execute();
            }

            // If rejecting, create a notification for the loanee
            if ($decisionType === 'reject') {
                $query = "INSERT INTO notifications 
                         (user_id, notification_type, related_id, message, created_at)
                         VALUES (:user_id, 'loan_rejected', :application_id, 
                                CONCAT('Your loan application has been rejected. Reason: ', :feedback), NOW())";

                $stmt = $db->prepare($query);
                $stmt->bindParam(':user_id', $loan['user_id']);
                $stmt->bindParam(':application_id', $input['application_id']);
                $stmt->bindParam(':feedback', $feedback);
                $stmt->execute();
            }

            // Commit transaction
            $db->commit();

            ob_clean();
            ResponseHelper::success([
                'decision_id' => $db->lastInsertId(),
                'new_status' => $newStatus
            ], 'Decision recorded successfully');

        } catch (Exception $e) {
            // Rollback transaction
            $db->rollback();
            throw $e;
        }

    } elseif ($method === 'PUT') {
        // Update an existing decision (only if not yet processed by accountant)
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            ob_clean();
            ResponseHelper::error('Invalid JSON data');
            exit();
        }

        // Validate CSRF token
        if (
            !isset($input['csrf_token']) || !isset($_SESSION['csrf_token']) ||
            $input['csrf_token'] !== $_SESSION['csrf_token']
        ) {
            ob_clean();
            ResponseHelper::forbidden('Invalid CSRF token');
            exit();
        }

        // Validate required fields
        if (!isset($input['decision_id'])) {
            ob_clean();
            ResponseHelper::error('Decision ID is required');
            exit();
        }

        // Check if decision exists and belongs to this cashier
        $query = "SELECT d.*, la.status as loan_status 
                 FROM decisions d
                 JOIN loan_applications la ON d.application_id = la.application_id
                 WHERE d.decision_id = :decision_id AND d.decision_maker_id = :user_id";

        $stmt = $db->prepare($query);
        $stmt->bindParam(':decision_id', $input['decision_id']);
        $stmt->bindParam(':user_id', $_SESSION['user_id']);
        $stmt->execute();

        if ($stmt->rowCount() === 0) {
            ob_clean();
            ResponseHelper::notFound('Decision not found or access denied');
            exit();
        }

        $decision = $stmt->fetch(PDO::FETCH_ASSOC);

        // Check if decision can still be modified
        if ($decision['loan_status'] !== $decision['new_status']) {
            ob_clean();
            ResponseHelper::error('This decision has already been processed and cannot be modified');
            exit();
        }

        // Update feedback if provided
        if (isset($input['feedback'])) {
            $query = "UPDATE decisions 
                     SET feedback = :feedback
                     WHERE decision_id = :decision_id";

            $stmt = $db->prepare($query);
            $stmt->bindParam(':feedback', $input['feedback']);
            $stmt->bindParam(':decision_id', $input['decision_id']);
            $stmt->execute();

            // Also update loan application feedback
            $query = "UPDATE loan_applications 
                     SET feedback = :feedback
                     WHERE application_id = :application_id";

            $stmt = $db->prepare($query);
            $stmt->bindParam(':feedback', $input['feedback']);
            $stmt->bindParam(':application_id', $decision['application_id']);
            $stmt->execute();
        }

        ob_clean();
        ResponseHelper::success(null, 'Decision updated successfully');

    } else {
        ob_clean();
        ResponseHelper::error('Method not allowed', 405);
    }

} catch (Exception $e) {
    ob_clean();
    ResponseHelper::serverError($e->getMessage());
}

exit();
?>