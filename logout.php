<?php
/**
 * Universal Logout Handler
 * Handles logout requests from all user roles: Admin, Cashier, Accountant, Loanee
 * Provides secure session cleanup and role-specific redirections
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Configure error logging
ini_set('log_errors', 1);
$logDir = dirname(__DIR__) . '/logs';
if (!file_exists($logDir)) {
    mkdir($logDir, 0755, true);
}
ini_set('error_log', $logDir . '/php_error.log');

/**
 * Determine the appropriate login page based on user role
 */
function getLoginPageForRole($role = null)
{
    // Default to main login page if no role is specified
    if (empty($role)) {
        return 'index.php';
    }

    // Role-specific login pages (if they exist)
    $rolePages = [
        'admin' => 'index.php',
        'cashier' => 'index.php',
        'accountant' => 'index.php',
        'loanee' => 'index.php'
    ];

    return $rolePages[$role] ?? 'index.php';
}

/**
 * Log the logout activity to database
 */
function logLogoutActivity()
{
    try {
        // Only log if user was logged in
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['full_name'])) {
            return;
        }

        require_once __DIR__ . '/config/database.php';

        $database = new Database();
        $db = $database->getConnection();

        // Prepare activity log entry
        $userId = $_SESSION['user_id'];
        $userName = $_SESSION['full_name'];
        $userRole = $_SESSION['user_role'] ?? 'unknown';
        $userEmail = $_SESSION['email'] ?? 'N/A';

        // Create detailed logout message
        $message = sprintf(
            "User '%s' (Role: %s, Email: %s) logged out from IP: %s",
            $userName,
            ucfirst($userRole),
            $userEmail,
            $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
        );

        // Insert into activity log
        $query = "INSERT INTO activity_log (admin_id, action, details, timestamp) 
                  VALUES (:user_id, 'logout', :message, NOW())";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindParam(':message', $message, PDO::PARAM_STR);
        $stmt->execute();

        // Also log to file for backup
        error_log(sprintf(
            "[LOGOUT] User ID: %d, Name: %s, Role: %s, Time: %s",
            $userId,
            $userName,
            $userRole,
            date('Y-m-d H:i:s')
        ));

        return true;
    } catch (Exception $e) {
        // Log error but don't stop logout process
        error_log("Logout activity logging failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Clear all user-related cookies
 */
function clearUserCookies()
{
    // Get cookie parameters
    $params = session_get_cookie_params();

    // Delete session cookie
    if (isset($_COOKIE[session_name()])) {
        setcookie(
            session_name(),
            '',
            time() - 3600,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    // Clear any application-specific cookies
    $cookiePrefixes = ['loan_system_', 'user_', 'remember_', 'auth_'];

    foreach ($_COOKIE as $cookieName => $cookieValue) {
        foreach ($cookiePrefixes as $prefix) {
            if (strpos($cookieName, $prefix) === 0) {
                setcookie(
                    $cookieName,
                    '',
                    time() - 3600,
                    $params['path'],
                    $params['domain'],
                    $params['secure'],
                    true
                );
                break;
            }
        }
    }
}

/**
 * Perform complete session cleanup
 */
function cleanupSession()
{
    // Unset all session variables
    $_SESSION = array();

    // Clear session cookies
    clearUserCookies();

    // Destroy the session
    session_destroy();

    // Regenerate session ID for security
    session_start();
    session_regenerate_id(true);
}

// =============================================================================
// MAIN LOGOUT LOGIC
// =============================================================================

try {
    // Store user info before destroying session (for logging and redirect)
    $userRole = $_SESSION['user_role'] ?? null;
    $userId = $_SESSION['user_id'] ?? null;
    $userName = $_SESSION['full_name'] ?? 'Unknown User';

    // Log the logout activity (before destroying session)
    logLogoutActivity();

    // Perform complete session cleanup
    cleanupSession();

    // Determine redirect page based on role
    $loginPage = getLoginPageForRole($userRole);

    // Add success message parameter
    $redirectUrl = $loginPage . '?logout=success';

    // Optional: Add role-specific message
    if ($userRole) {
        $redirectUrl .= '&role=' . urlencode($userRole);
    }

    // Security headers
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Redirect to login page
    header("Location: " . $redirectUrl);
    exit();

} catch (Exception $e) {
    // Log the error
    error_log("Critical logout error: " . $e->getMessage());

    // Even if there's an error, still try to destroy session
    try {
        session_destroy();
    } catch (Exception $destroyError) {
        error_log("Session destroy error: " . $destroyError->getMessage());
    }

    // Redirect to login page anyway
    header("Location: index.php?logout=error");
    exit();
}
?>