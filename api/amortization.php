<?php
// api/amortization.php

header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['authenticated']) || $_SESSION['user_role'] !== 'cashier') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$application_id = $_GET['application_id'] ?? null;
if (!$application_id || !preg_match('/^[A-Za-z0-9\-_]+$/', $application_id)) {
    echo json_encode(['success' => false, 'message' => 'Invalid application ID']);
    exit();
}
require_once __DIR__ . '/../config/config.php';
$database = new Database();
$db = $database->getConnection();

if (!$db) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$stmt = $db->prepare("
    SELECT la.loan_amount, la.payment_duration, la.application_date,
           COALESCE(ps.actual_amount, 0) AS total_paid,
           COALESCE(ps.actual_principal, 0) AS principal_paid
    FROM loan_applications la
    LEFT JOIN (
        SELECT application_id,
               SUM(amount) AS actual_amount,
               SUM(principal_amount) AS actual_principal
        FROM payments
        GROUP BY application_id
    ) ps ON la.application_id = ps.application_id
    WHERE la.application_id = ?
");
$stmt->execute([$application_id]);
$loan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$loan) {
    echo json_encode(['success' => false, 'message' => 'Loan not found']);
    exit();
}

$principal         = (float)$loan['loan_amount'];
$months            = (int)$loan['payment_duration'];
$start_date        = new DateTime($loan['application_date']);
$principal_paid    = (float)$loan['principal_paid'];
$remaining_principal = max(0, $principal - $principal_paid);

$interest_rate = 3.0; // default fallback
try {
    $rate_stmt = $db->query("SELECT value FROM system_settings WHERE setting_key = 'amortization_rate' LIMIT 1");
    if ($row = $rate_stmt->fetch(PDO::FETCH_ASSOC)) {
        $interest_rate = (float)$row['value'];
    }
} catch (Exception $e) {
    error_log("Failed to fetch amortization_rate: " . $e->getMessage());
}

// Monthly interest rate
$monthly_rate = $interest_rate / 100 / 12;

$schedule = [];
$balance  = $remaining_principal;

// Fixed monthly payment (EMI formula)
if ($monthly_rate > 0 && $months > 0) {
    $emi = $balance * $monthly_rate * pow(1 + $monthly_rate, $months) / (pow(1 + $monthly_rate, $months) - 1);
} else {
    $emi = $balance / $months; // fallback: straight-line
}

$emi = round($emi, 2);

$current_date = clone $start_date;
$current_date->modify('first day of next month'); // First payment due next month

for ($i = 1; $i <= $months && $balance > 0; $i++) {
    $interest = round($balance * $monthly_rate, 2);
    $principal_payment = min($emi - $interest, $balance);
    $principal_payment = round($principal_payment, 2);

    $payment = $principal_payment + $interest;
    $balance -= $principal_payment;
    $balance = max(0, round($balance, 2));

    $due_date = $current_date->format('Y-m-d');

    // Determine status
    $status = 'pending';
    $payment_stmt = $db->prepare("
        SELECT actual_date, amount
        FROM payment_schedules
        WHERE application_id = ? AND payment_number = ?
        LIMIT 1
    ");
    $payment_stmt->execute([$application_id, $i]);
    $paid = $payment_stmt->fetch(PDO::FETCH_ASSOC);

    if ($paid && $paid['actual_date'] && strtotime($paid['actual_date']) <= strtotime($due_date)) {
        $status = ($paid['amount'] >= $emi) ? 'paid' : 'partial';
    } elseif ($paid && $paid['actual_date'] && strtotime($paid['actual_date']) > strtotime($due_date)) {
        $status = 'overdue';
    } elseif (strtotime($due_date) < time()) {
        $status = 'overdue';
    }

    $schedule[] = [
        'payment_number' => $i,
        'due_date'       => $due_date,
        'payment'        => $payment,
        'principal'      => $principal_payment,
        'interest'       => $interest,
        'balance'        => $balance,
        'status'         => $status
    ];

    $current_date->modify('+1 month');
}

echo json_encode([
    'success' => true,
    'data'    => [
        'schedule'       => $schedule,
        'total_payments' => count($schedule),
        'emi'            => $emi,
        'interest_rate'  => $interest_rate . '%',
        'remaining_principal' => $remaining_principal
    ]
]);