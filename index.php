<?php
// Start session
session_start();

// Check for registration success message
$success_message = '';
if (isset($_SESSION['registration_success']) && $_SESSION['registration_success']) {
    $success_message = $_SESSION['success_message'];

    // Clear session variables so message doesn't show again
    unset($_SESSION['registration_success']);
    unset($_SESSION['success_message']);
}

// Include required files with correct paths
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

// Initialize database connection
try {
    $database = new Database();
    $conn = $database->getConnection();
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Handle login form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $pf_number = trim($_POST['pf_number']);
    $password = $_POST['password'];

    $errors = [];

    if (empty($pf_number)) {
        $errors[] = "PF Number is required";
    }

    if (empty($password)) {
        $errors[] = "Password is required";
    }

    if (empty($errors)) {
        try {
            // Query to get user data using PF number only for login
            $stmt = $conn->prepare("SELECT user_id, pf_number, id_number, email, password, role, full_name 
                                    FROM users 
                                    WHERE pf_number = :pf_number 
                                    AND is_active = 1");
            $stmt->bindParam(':pf_number', $pf_number, PDO::PARAM_STR);
            $stmt->execute();

            if ($stmt->rowCount() === 1) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                // Verify password using bcrypt
                if (password_verify($password, $user['password'])) {
                    // Regenerate session ID to prevent session fixation
                    session_regenerate_id(true);

                    // Set session variables
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['pf_number'] = $user['pf_number'];
                    $_SESSION['id_number'] = $user['id_number'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['login_time'] = time();
                    $_SESSION['last_activity'] = time();
                    $_SESSION['last_regeneration'] = time();
                    $_SESSION['authenticated'] = true;

                    // Generate CSRF token for the session
                    if (!isset($_SESSION['csrf_token'])) {
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    }

                    // Redirect based on role
                    $role_pages = [
                        'admin' => 'admin.php',
                        'accountant' => 'accountant.php',
                        'cashier' => 'cashier.php',
                        'loanee' => 'loanee.php'
                    ];

                    if (isset($role_pages[$user['role']])) {
                        // Use absolute URL for redirect to avoid any path issues
                        $redirect_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" .
                            dirname($_SERVER['PHP_SELF']) . "/" . $role_pages[$user['role']];
                        header("Location: " . $redirect_url);
                        exit();
                    } else {
                        $error_msg = "Invalid role assigned. Please contact administrator.";
                    }
                } else {
                    $error_msg = "Invalid password. Please try again.";
                }
            } else {
                $error_msg = "Invalid PF Number. Please verify your credentials or contact administrator.";
            }
        } catch (PDOException $e) {
            $error_msg = "Login failed. Please try again later.";
            error_log("Login error: " . $e->getMessage());
        }
    } else {
        $error_msg = implode("<br>", $errors);
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nyeri County Loan Management System - Login</title>
    <style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background-color: #e0f2f1;
        min-height: 100vh;
        display: flex;
        justify-content: center;
        align-items: center;
        padding: 20px;
    }

    .login-container {
        background: white;
        padding: 40px;
        border-radius: 15px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        width: 400px;
        max-width: 90%;
        animation: fadeIn 0.5s ease-in-out;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(-20px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .logo {
        text-align: center;
        margin-bottom: 30px;
    }

    .emblem {
        width: 80px;
        height: 80px;
        margin: 0 auto 15px;
        background-color: #f5f5f5;
        border-radius: 50%;
        display: flex;
        justify-content: center;
        align-items: center;
        overflow: hidden;
        border: 2px solid #e0e0e0;
        transition: all 0.3s ease;
        cursor: pointer;
    }

    .emblem:hover {
        transform: scale(1.1);
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        border-color: #2e7d32;
    }

    .emblem img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: all 0.3s ease;
    }

    .emblem:hover img {
        filter: brightness(1.1);
    }

    .logo h1 {
        color: #2e7d32;
        font-size: 24px;
        margin-bottom: 5px;
        font-weight: 600;
    }

    .logo p {
        color: #666;
        font-size: 18px;
        font-weight: 500;
    }

    .form-group {
        margin-bottom: 20px;
    }

    .form-group label {
        display: block;
        margin-bottom: 8px;
        color: #333;
        font-weight: 500;
    }

    .form-group input {
        width: 100%;
        padding: 12px;
        border: 1px solid #ddd;
        border-radius: 5px;
        font-size: 14px;
        transition: all 0.3s;
    }

    .form-group input:focus {
        outline: none;
        border-color: #2e7d32;
        box-shadow: 0 0 0 2px rgba(46, 125, 50, 0.2);
    }

    .remember-me {
        display: flex;
        align-items: center;
        margin-bottom: 20px;
    }

    .remember-me input {
        margin-right: 8px;
    }

    .remember-me label {
        font-size: 14px;
        color: #555;
    }

    .btn-login,
    .btn-create {
        width: 100%;
        padding: 12px;
        background-color: #2e7d32;
        color: white;
        border: none;
        border-radius: 5px;
        font-size: 16px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s;
        margin-bottom: 10px;
    }

    .btn-login:hover,
    .btn-create:hover {
        background-color: #1b5e20;
    }

    .btn-login:active,
    .btn-create:active {
        transform: translateY(1px);
    }

    .btn-login:disabled,
    .btn-create:disabled {
        opacity: 0.7;
        cursor: not-allowed;
        transform: none;
    }

    .notification {
        padding: 12px;
        margin-bottom: 20px;
        border-radius: 5px;
        display: block;
        animation: slideDown 0.3s ease-out;
    }

    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .error {
        background-color: #ffebee;
        color: #c62828;
        border: 1px solid #ef9a9a;
    }

    .success {
        background-color: #e8f5e9;
        color: #2e7d32;
        border: 1px solid #a5d6a7;
    }

    .password-toggle {
        position: relative;
    }

    .toggle-password {
        position: absolute;
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        cursor: pointer;
        color: #666;
        user-select: none;
        font-size: 12px;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .loading {
        display: none;
        text-align: center;
        margin-top: 10px;
    }

    .spinner {
        border: 3px solid #f3f3f3;
        border-top: 3px solid #2e7d32;
        border-radius: 50%;
        width: 20px;
        height: 20px;
        animation: spin 1s linear infinite;
        display: inline-block;
    }

    @keyframes spin {
        0% {
            transform: rotate(0deg);
        }

        100% {
            transform: rotate(360deg);
        }
    }

    .password-input-wrapper {
        position: relative;
    }

    .password-input-wrapper input {
        padding-right: 60px;
    }

    .copyright {
        text-align: center;
        margin-top: 20px;
        font-size: 12px;
        color: #777;
    }

    .info-text {
        font-size: 12px;
        color: #666;
        margin-top: 5px;
        font-style: italic;
    }
    </style>
</head>

<body>
    <div class="login-container">
        <div class="logo">
            <div class="emblem">
                <img src="logo.jpg" alt="Nyeri County Government Emblem"
                    onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                <div
                    style="display:none; width:100%; height:100%; justify-content:center; align-items:center; font-size:40px; color:#2e7d32;">
                    🏛️</div>
            </div>
            <h1>Nyeri County Loan Management System</h1>
            <p>Welcome Back</p>
        </div>

        <?php if (!empty($success_message)): ?>
        <div class="notification success">
            <?php echo htmlspecialchars($success_message); ?>
        </div>
        <?php endif; ?>

        <?php if (isset($error_msg)): ?>
        <div class="notification error">
            <?php echo htmlspecialchars($error_msg); ?>
        </div>
        <?php endif; ?>

        <?php if (isset($_GET['logout']) && $_GET['logout'] == 'success'): ?>
        <div class="notification success">You have been logged out successfully.</div>
        <?php endif; ?>

        <form id="loginForm" method="post" action="index.php">
            <div class="form-group">
                <label for="pf_number">PF Number</label>
                <input type="text" id="pf_number" name="pf_number" placeholder="Enter your PF Number" required
                    value="<?php echo isset($_POST['pf_number']) ? htmlspecialchars($_POST['pf_number']) : ''; ?>"
                    autocomplete="off">
                <div class="info-text">Your Personal File Number</div>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div class="password-input-wrapper">
                    <input type="password" id="password" name="password" placeholder="Enter your password" required
                        autocomplete="current-password">
                    <span class="toggle-password" onclick="togglePassword()">SHOW</span>
                </div>
            </div>

            <div class="remember-me">
                <input type="checkbox" id="remember" name="remember">
                <label for="remember">Remember me</label>
            </div>

            <button type="submit" class="btn-login" id="loginBtn">Sign In</button>
        </form>

        <button type="button" class="btn-create" onclick="window.location.href='register.php'">Create Account</button>

        <div class="loading" id="loading">
            <div class="spinner"></div> Authenticating...
        </div>

        <div class="copyright">
            © 2025 Nyeri County Government. All Rights Reserved.
        </div>
    </div>

    <script>
    function togglePassword() {
        const passwordInput = document.getElementById('password');
        const toggleIcon = document.querySelector('.toggle-password');

        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            toggleIcon.textContent = 'HIDE';
        } else {
            passwordInput.type = 'password';
            toggleIcon.textContent = 'SHOW';
        }
    }

    document.getElementById('loginForm').addEventListener('submit', function(e) {
        const loginBtn = document.getElementById('loginBtn');
        const loading = document.getElementById('loading');

        loginBtn.disabled = true;
        loginBtn.textContent = 'Signing in...';
        loading.style.display = 'block';
    });

    // Auto-focus on PF number field
    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('pf_number').focus();
    });

    // Clear error messages after 5 seconds
    setTimeout(function() {
        const notifications = document.querySelectorAll('.notification.error');
        notifications.forEach(function(notification) {
            notification.style.transition = 'opacity 0.5s';
            notification.style.opacity = '0';
            setTimeout(function() {
                notification.remove();
            }, 500);
        });
    }, 5000);

    // Format PF number input (optional - converts to uppercase)
    document.getElementById('pf_number').addEventListener('input', function(e) {
        this.value = this.value.toUpperCase();
    });
    </script>
</body>

</html>