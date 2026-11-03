<?php
/** Phase 11 security smoke checks that do not require production data. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit("Run from CLI.\n");

function securityAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    echo "PASS: {$message}\n";
}

$root = dirname(__DIR__);
$loanApplications = file_get_contents($root . '/api/loan_applications.php');
$loans = file_get_contents($root . '/api/loans.php');
$payments = file_get_contents($root . '/api/payments.php');
$schedule = file_get_contents($root . '/api/payment_schedule.php');
$clearData = file_get_contents($root . '/api/clear-data.php');
$config = file_get_contents($root . '/config/config.php');

securityAssert(str_contains($loanApplications, 'validateCSRFToken'), 'normal-loan application mutations validate CSRF');
securityAssert(str_contains($loanApplications, 'WHERE user_id = :current_user_id'), 'normal-loan application list is owner-scoped');
securityAssert(str_contains($loanApplications, ':user_id'), 'new normal loans are linked to the authenticated user');
securityAssert(str_contains($loans, 'WHERE user_id = :user_id'), 'unified-loan loanee queries are owner-scoped');
securityAssert(!str_contains($loans, 'OR pf_number = :pf_number'), 'unified-loan access does not fall back to caller identifiers');
securityAssert(!str_contains($payments, 'OR pf_number = :pf_number'), 'payment access does not fall back to caller identifiers');
securityAssert(str_contains($schedule, 'You can only view your own payment schedule'), 'payment schedules enforce ownership');
securityAssert(str_contains($clearData, "'Bulk data deletion is disabled'"), 'bulk deletion endpoint is disabled');
securityAssert(str_contains($config, "(string)\$dbUser === 'root'"), 'production rejects root database credentials');
securityAssert(str_contains($config, "(string)\$dbPass === ''"), 'production rejects blank database passwords');
securityAssert(is_file($root . '/secure/.htaccess'), 'private import storage has a deny rule');

echo "PHASE 11 STATIC SECURITY TESTS PASSED\n";
