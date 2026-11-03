<?php
// Prevent direct access
if (!defined('INCLUDED')) {
    define('INCLUDED', true);
}

// Environment detection
$environment = getenv('APP_ENV') ?: 'development';
define('ENVIRONMENT', $environment);

// Database configuration
$dbHost = getenv('LOAN_DB_HOST');
$dbName = getenv('LOAN_DB_NAME');
$dbUser = getenv('LOAN_DB_USER');
$dbPass = getenv('LOAN_DB_PASS');
if (ENVIRONMENT === 'production'
    && ($dbHost === false || $dbName === false || $dbUser === false || $dbPass === false
        || trim((string)$dbHost) === '' || trim((string)$dbName) === ''
        || trim((string)$dbUser) === '' || (string)$dbUser === 'root'
        || (string)$dbPass === '')) {
    error_log('Production database configuration is incomplete or uses an unsafe credential default');
    throw new RuntimeException('Application configuration is unavailable');
}
define('DB_HOST', $dbHost !== false && $dbHost !== '' ? $dbHost : 'localhost');
define('DB_NAME', $dbName !== false && $dbName !== '' ? $dbName : 'loan_management');
define('DB_USER', $dbUser !== false && $dbUser !== '' ? $dbUser : 'root');
define('DB_PASS', $dbPass !== false ? $dbPass : '');

if (session_status() === PHP_SESSION_NONE) {
    $secureCookie = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Application settings
define('APP_NAME', 'loan-system');
define('INTEREST_RATE', 3.0); 

// Security settings
define('CSRF_TOKEN_EXPIRY', 3600);
// Expire after four hours without a request, not four hours after login.
// The portal APIs update last_activity on every authenticated request.
define('SESSION_TIMEOUT', 14400);

// Set default timezone
date_default_timezone_set('Africa/Nairobi');

// Conditional error reporting
if (ENVIRONMENT === 'production') {
    // Production settings
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
} else {
    // Development settings
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
}

// Enhanced logging function
function conditional_error_log($message) {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message";
    
    if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
        error_log($logMessage);
    }
    
    // Always log to file for debugging
    $logDir = dirname(__DIR__) . '/logs';
    if (!file_exists($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/debug.log';
    file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);
}

// CSRF Token Generation
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
    return $_SESSION['csrf_token'];
}

// Verify CSRF Token
function validateCSRFToken($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        conditional_error_log('CSRF token missing');
        return false;
    }
    
    // Check token expiry
    if (isset($_SESSION['csrf_token_time'])) {
        $elapsed = time() - $_SESSION['csrf_token_time'];
        if ($elapsed > CSRF_TOKEN_EXPIRY) {
            conditional_error_log('CSRF token expired');
            return false;
        }
    }
    
    return hash_equals($_SESSION['csrf_token'], $token);
}

// Session timeout check - FIXED
function checkSessionTimeout() {
    if (empty($_SESSION['user_id'])) {
        return true;
    }

    $now = time();
    // Support sessions created before this idle-timeout implementation.
    $lastActivity = (int)($_SESSION['last_activity'] ?? $_SESSION['login_time'] ?? $now);

    if (($now - $lastActivity) > SESSION_TIMEOUT) {
        conditional_error_log('Session expired for user: ' . ($_SESSION['user_id'] ?? 'unknown'));
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'],
                $params['secure'], $params['httponly']);
        }
        session_destroy();
        return false;
    }

    // Any authenticated page/API request counts as activity.
    $_SESSION['last_activity'] = $now;
    return true;
}

// Initialize CSRF token
if (session_status() === PHP_SESSION_ACTIVE) {
    generateCSRFToken();
}

// Check session timeout on every page load
if (session_status() === PHP_SESSION_ACTIVE) {
    checkSessionTimeout();
}
?>
