<?php
declare(strict_types=1);
ob_start();

/** Creates isolated test-only sessions; never use with production credentials. */
$dbName = getenv('LEGACY_TEST_DB') ?: '';
if (!preg_match('/^legacy_test_[a-z0-9_]+$/', $dbName)) {
    throw new RuntimeException('LEGACY_TEST_DB must name an isolated legacy_test_* database');
}
$db = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('LEGACY_TEST_HOST') ?: '127.0.0.1', getenv('LEGACY_TEST_PORT') ?: '3306', $dbName),
    getenv('LEGACY_TEST_USER') ?: 'root', getenv('LEGACY_TEST_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$users = $db->query("SELECT user_id, role FROM users WHERE is_active=1 ORDER BY user_id LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
if (count($users) < 2) throw new RuntimeException('Two active test users are required');

foreach ($users as $index => $user) {
    $id = 'phase13-' . ($index + 1);
    session_id($id);
    session_start();
    $_SESSION['user_id'] = (int)$user['user_id'];
    $_SESSION['role'] = (string)$user['role'];
    $_SESSION['user_role'] = (string)$user['role'];
    $_SESSION['csrf_token'] = 'phase13-csrf-' . ($index + 1);
    session_write_close();
    echo $id . ' user_id=' . $user['user_id'] . ' role=' . $user['role'] . "\n";
}
$loanee = $db->query("SELECT user_id, role FROM users WHERE is_active=1 AND role='loanee' ORDER BY user_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($loanee) {
    session_id('phase13-loanee');
    session_start();
    $_SESSION['user_id'] = (int)$loanee['user_id'];
    $_SESSION['role'] = 'loanee';
    $_SESSION['user_role'] = 'loanee';
    $_SESSION['csrf_token'] = 'phase13-csrf-loanee';
    session_write_close();
    echo 'phase13-loanee user_id=' . $loanee['user_id'] . " role=loanee\n";
}
$accountant2 = $db->query("SELECT user_id FROM users WHERE user_id=7 AND is_active=1 AND role='accountant'")->fetchColumn();
if ($accountant2) {
    session_id('phase13-accountant2');
    session_start();
    $_SESSION['user_id'] = (int)$accountant2;
    $_SESSION['role'] = 'accountant';
    $_SESSION['user_role'] = 'accountant';
    $_SESSION['csrf_token'] = 'phase13-csrf-accountant2';
    session_write_close();
    echo 'phase13-accountant2 user_id=' . $accountant2 . " role=accountant\n";
}
