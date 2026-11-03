<?php
// Prevent direct access
if (!defined('INCLUDED')) {
    define('INCLUDED', true);
}

// Environment detection
 $environment = getenv('APP_ENV') ?: 'development';
define('ENVIRONMENT', $environment);

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'loan_management');
define('DB_USER', 'Harry');
define('DB_PASS', 'ZIHA@6002@ZIHA');

// Application settings
define('APP_NAME', 'loan-system');
define('INTEREST_RATE', 3.0); // 3% per annum

// Security settings
define('CSRF_TOKEN_EXPIRY', 3600); // 1 hour
define('SESSION_TIMEOUT', 1800); // 30 minutes

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

// Conditional logging function
function conditional_error_log($message) {
    if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
        error_log($message);
    }
    
    // In production, log to a secure file instead
    if (defined('ENVIRONMENT') && ENVIRONMENT === 'production') {
        $logDir = dirname(__DIR__) . '/logs';
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $logFile = $logDir . '/production.log';
        file_put_contents($logFile, date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, FILE_APPEND);
    }
}

// CSRF Token Generation
function generateCSRFToken()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Verify CSRF Token
function validateCSRFToken($token)
{
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

// Session timeout check
function checkSessionTimeout()
{
    if (isset($_SESSION['login_time'])) {
        $elapsed = time() - $_SESSION['login_time'];
        if ($elapsed > SESSION_TIMEOUT) {
            session_unset();
            session_destroy();
            return false;
        }
        $_SESSION['login_time'] = time(); // Reset timer
    }
    return true;
}