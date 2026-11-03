<?php
declare(strict_types=1);

/**
 * Creates authenticated test sessions and Netscape cookie files for:
 * - Cashier
 * - Accountant
 * - Accountant2 (secondary accountant for dual-maker/checker workflows)
 * - Admin
 * - Loanee
 *
 * Uses isolated test databases only.
 */

$dbName = getenv('LEGACY_TEST_DB') ?: '';
if (!preg_match('/^legacy_test_[a-z0-9_]+$/', $dbName)) {
    throw new RuntimeException('LEGACY_TEST_DB must name an isolated legacy_test_* database');
}

$db = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('LEGACY_TEST_HOST') ?: '127.0.0.1', getenv('LEGACY_TEST_PORT') ?: '3306', $dbName),
    getenv('LEGACY_TEST_USER') ?: 'root', getenv('LEGACY_TEST_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// Ensure secondary accountant exists for dual-maker/checker write-off tests
$secAcctId = $db->query("SELECT user_id FROM users WHERE role = 'accountant' AND email = 'accountant2@test.com' LIMIT 1")->fetchColumn();
if (!$secAcctId) {
    $db->prepare("INSERT INTO users (full_name, email, id_number, pf_number, password, role, is_active) VALUES ('Secondary Accountant', 'accountant2@test.com', '99887766', 99887766, 'TestHash123!', 'accountant', 1) ON DUPLICATE KEY UPDATE user_id=LAST_INSERT_ID(user_id)")->execute();
    $secAcctId = (int)$db->lastInsertId();
    if ($secAcctId > 0) {
        $db->prepare("INSERT IGNORE INTO accountants (user_id, pf_number, full_name, email) VALUES (?, 99887766, 'Secondary Accountant', 'accountant2@test.com')")->execute([$secAcctId]);
    }
}

$rolesMap = [
    'cashier' => "SELECT user_id, role FROM users WHERE role = 'cashier' AND is_active = 1 LIMIT 1",
    'accountant' => "SELECT user_id, role FROM users WHERE role = 'accountant' AND email != 'accountant2@test.com' AND is_active = 1 LIMIT 1",
    'accountant2' => "SELECT user_id, role FROM users WHERE role = 'accountant' AND email = 'accountant2@test.com' AND is_active = 1 LIMIT 1",
    'admin' => "SELECT user_id, role FROM users WHERE role = 'admin' AND is_active = 1 LIMIT 1",
    'loanee' => "SELECT user_id, role FROM users WHERE role = 'loanee' AND is_active = 1 LIMIT 1"
];

$sessions = [];

foreach ($rolesMap as $roleKey => $sql) {
    $user = $db->query($sql)->fetch();
    if (!$user) {
        throw new RuntimeException("No active user found for role query: {$roleKey}");
    }

    $sessId = 'phase13-' . $roleKey;
    $csrfToken = 'csrf-' . $roleKey . '-' . bin2hex(random_bytes(8));

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    session_id($sessId);
    session_start();
    $_SESSION['user_id'] = (int)$user['user_id'];
    $_SESSION['user_role'] = (string)$user['role'];
    $_SESSION['role'] = (string)$user['role'];
    $_SESSION['csrf_token'] = $csrfToken;
    $_SESSION['last_activity'] = time();
    session_write_close();

    $cookieFile = __DIR__ . '/accept-' . $roleKey . '.cookies.txt';
    $cookieContent = "# Netscape HTTP Cookie File\n# Generated for Phase 13/14 integration tests\n127.0.0.1\tFALSE\t/\tFALSE\t0\tPHPSESSID\t{$sessId}\n";
    file_put_contents($cookieFile, $cookieContent);

    $sessions[$roleKey] = [
        'user_id' => (int)$user['user_id'],
        'role' => (string)$user['role'],
        'session_id' => $sessId,
        'csrf_token' => $csrfToken,
        'cookie_file' => $cookieFile
    ];
}

file_put_contents(__DIR__ . '/phase13_sessions.json', json_encode($sessions, JSON_PRETTY_PRINT));
foreach ($sessions as $key => $s) {
    echo "Session created for {$key}: user_id={$s['user_id']}, sess_id={$s['session_id']}, csrf={$s['csrf_token']}\n";
}
echo "All role sessions successfully established.\n";
