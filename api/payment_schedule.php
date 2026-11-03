<?php
require_once '../config/database.php';
require_once '../config/config.php';
require_once '../helpers/response_helper.php';

session_start();

$database = new Database();
$db = $database->getConnection();

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Check if user is logged in
        if (empty($_SESSION['user_id'])) {
            ResponseHelper::unauthorized();
        }

        $application_id = $_GET['application_id'] ?? null;

        if (!$application_id) {
            ResponseHelper::error('Application ID is required');
        }

        // First get the loan application ID from the application_id string
        $app_query = "SELECT id, user_id FROM loan_applications WHERE application_id = :application_id";
        $app_stmt = $db->prepare($app_query);
        $app_stmt->bindParam(":application_id", $application_id);
        $app_stmt->execute();
        $app_result = $app_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$app_result) {
            ResponseHelper::error('Application not found');
        }

        $identity = $db->prepare('SELECT role FROM users WHERE user_id = ? AND is_active = 1');
        $identity->execute([(int)$_SESSION['user_id']]);
        $role = strtolower((string)$identity->fetchColumn());
        if (!in_array($role, ['admin', 'accountant', 'cashier'], true)
            && (int)$app_result['user_id'] !== (int)$_SESSION['user_id']) {
            ResponseHelper::forbidden('You can only view your own payment schedule');
        }

        $loan_application_id = $app_result['id'];

        $query = "SELECT * FROM payment_schedules WHERE loan_application_id = :loan_application_id ORDER BY payment_number";
        $stmt = $db->prepare($query);
        $stmt->bindParam(":loan_application_id", $loan_application_id);
        $stmt->execute();

        $schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Backfill schedules for loans that were dispatched before schedule
        // generation was added to the Accountant dispatch flow.
        if (empty($schedule)) {
            $loan_query = "SELECT loan_amount, payment_duration, dispatch_date, status
                           FROM loan_applications WHERE id = :loan_id LIMIT 1";
            $loan_stmt = $db->prepare($loan_query);
            $loan_stmt->bindParam(':loan_id', $loan_application_id, PDO::PARAM_INT);
            $loan_stmt->execute();
            $loan = $loan_stmt->fetch(PDO::FETCH_ASSOC);

            if ($loan && $loan['status'] === 'dispatched') {
                $rate_stmt = $db->prepare(
                    "SELECT value FROM system_settings
                     WHERE setting_key = 'amortization_rate' LIMIT 1"
                );
                $rate_stmt->execute();
                $annual_rate = (float) ($rate_stmt->fetchColumn() ?: 3.0);

                $principal = (float) $loan['loan_amount'];
                $total_payments = max(1, (int) $loan['payment_duration']) * 12;
                $monthly_rate = $annual_rate / 100 / 12;
                $monthly_payment = $monthly_rate > 0
                    ? $principal * ($monthly_rate * pow(1 + $monthly_rate, $total_payments)) /
                      (pow(1 + $monthly_rate, $total_payments) - 1)
                    : $principal / $total_payments;

                $start_date = new DateTime($loan['dispatch_date'] ?: 'now');
                $start_date->add(new DateInterval('P1M'));
                $balance = $principal;

                $insert_stmt = $db->prepare(
                    "INSERT INTO payment_schedules
                        (loan_application_id, payment_number, due_date, expected_amount,
                         expected_principal, expected_interest, expected_balance, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')"
                );

                for ($i = 1; $i <= $total_payments; $i++) {
                    $due_date = clone $start_date;
                    $due_date->add(new DateInterval('P' . ($i - 1) . 'M'));
                    $interest_payment = $balance * $monthly_rate;
                    $principal_payment = min($balance, $monthly_payment - $interest_payment);
                    $balance = max(0, $balance - $principal_payment);

                    $insert_stmt->execute([
                        $loan_application_id,
                        $i,
                        $due_date->format('Y-m-d'),
                        round($monthly_payment, 2),
                        round($principal_payment, 2),
                        round($interest_payment, 2),
                        round($balance, 2)
                    ]);
                }

                $stmt->execute();
                $schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        ResponseHelper::success($schedule);
        break;

    case 'POST':
        // Check if user is logged in
        if (empty($_SESSION['user_id'])) {
            ResponseHelper::unauthorized();
        }

        // Validate CSRF token
        $data = json_decode(file_get_contents("php://input"));
        if (empty($data->csrf_token) || !validateCSRFToken($data->csrf_token)) {
            ResponseHelper::forbidden('Invalid CSRF token');
        }

        $identity = $db->prepare('SELECT role FROM users WHERE user_id = ? AND is_active = 1');
        $identity->execute([(int)$_SESSION['user_id']]);
        $role = strtolower((string)$identity->fetchColumn());
        if (!in_array($role, ['accountant', 'admin'], true)) {
            ResponseHelper::forbidden('Only accountants and admins can generate payment schedules');
        }

        if (!isset($data->application_id)) {
            ResponseHelper::error('Application ID is required');
        }

        // First get the loan application ID from the application_id string
        $app_query = "SELECT id, user_id FROM loan_applications WHERE application_id = :application_id";
        $app_stmt = $db->prepare($app_query);
        $app_stmt->bindParam(":application_id", $data->application_id);
        $app_stmt->execute();
        $app_result = $app_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$app_result) {
            ResponseHelper::error('Application not found');
        }

        $loan_application_id = $app_result['id'];

        // Generate payment schedule logic here
        $principal = $data->loan_amount;
        $annual_rate = $data->interest_rate;
        $years = $data->payment_duration_years;
        $start_date = $data->start_date;

        $monthly_rate = $annual_rate / 100 / 12;
        $total_months = $years * 12;

        $monthly_payment = $principal * ($monthly_rate * pow(1 + $monthly_rate, $total_months)) /
            (pow(1 + $monthly_rate, $total_months) - 1);

        $balance = $principal;
        $schedules = [];

        for ($i = 1; $i <= $total_months; $i++) {
            $interest_payment = $balance * $monthly_rate;
            $principal_payment = $monthly_payment - $interest_payment;
            $balance -= $principal_payment;

            $due_date = date('Y-m-d', strtotime($start_date . " +$i months"));

            $schedules[] = [
                'loan_application_id' => $loan_application_id,
                'payment_number' => $i,
                'due_date' => $due_date,
                'expected_amount' => round($monthly_payment, 2),
                'expected_principal' => round($principal_payment, 2),
                'expected_interest' => round($interest_payment, 2),
                'expected_balance' => round(max(0, $balance), 2),
                'status' => 'pending'
            ];
        }

        // Clear existing schedule
        $delete_query = "DELETE FROM payment_schedules WHERE loan_application_id = :loan_application_id";
        $delete_stmt = $db->prepare($delete_query);
        $delete_stmt->bindParam(":loan_application_id", $loan_application_id);
        $delete_stmt->execute();

        // Insert new schedule
        $insert_query = "INSERT INTO payment_schedules 
                        (loan_application_id, payment_number, due_date, expected_amount, expected_principal, expected_interest, expected_balance, status)
                        VALUES 
                        (:loan_application_id, :payment_number, :due_date, :expected_amount, :expected_principal, :expected_interest, :expected_balance, :status)";

        $insert_stmt = $db->prepare($insert_query);

        foreach ($schedules as $schedule) {
            $insert_stmt->execute($schedule);
        }

        ResponseHelper::success($schedules, 'Payment schedule generated successfully');
        break;

    default:
        ResponseHelper::error('Method not allowed', 405);
}
