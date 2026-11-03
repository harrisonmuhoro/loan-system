<?php
session_start();
ob_start();

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 0);  // Don't show errors in output
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// === CONFIG & DB CHECK ===
if (!file_exists(__DIR__ . '/config/config.php')) {
    die('Config file missing');
}
if (!file_exists(__DIR__ . '/config/database.php')) {
    die('Database config missing');
}

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

 $database = new Database();
 $conn = $database->getConnection();

if (!$conn) {
    $msg = 'Database connection failed';
    error_log($msg);
    if (is_ajax()) {
        json_response(false, $msg);
    }
    die($msg);
}

 $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// === HELPER FUNCTIONS ===
function is_ajax() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function json_response($success, $message, $errors = []) {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'errors' => $errors
    ]);
    exit();
}

function sanitize_input($data) {
    if (is_array($data)) {
        return array_map('sanitize_input', $data);
    }
    $data = trim($data);
    $data = stripslashes($data);
    $data = strip_tags($data);
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

// === AJAX REGISTRATION HANDLER ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax()) {
    $errors = [];

    // Get and sanitize inputs
    $id_number = sanitize_input($_POST['id_number'] ?? '');
    $pf_number = sanitize_input($_POST['pf_number'] ?? '');
    $full_name = sanitize_input($_POST['full_name'] ?? '');
    $email     = sanitize_input($_POST['email'] ?? '');
    $password  = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // === VALIDATION ===
    if (empty($id_number)) {
        $errors[] = "ID Number is required";
    } elseif (!preg_match('/^[0-9]{6,10}$/', $id_number)) {
        $errors[] = "ID Number must be 6-10 digits";
    }

    if (empty($pf_number)) {
        $errors[] = "PF Number is required";
    } elseif (!preg_match('/^[0-9]{10,14}$/', $pf_number)) {
        $errors[] = "PF Number must be 10-14 digits";
    } elseif (!is_numeric($pf_number)) {
        $errors[] = "PF Number must be numeric";
    } else {
        $pf_number_int = (int)$pf_number;
        if ($pf_number_int <= 0) {
            $errors[] = "PF Number must be positive";
        }
    }

    if (empty($full_name)) {
        $errors[] = "Full Name is required";
    } elseif (!preg_match('/^[a-zA-Z\s]{2,100}$/', $full_name)) {
        $errors[] = "Full Name: letters and spaces only (2-100 chars)";
    }

    if (empty($email)) {
        $errors[] = "Email is required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    } elseif (strlen($email) > 100) {
        $errors[] = "Email too long";
    }

    if (empty($password)) {
        $errors[] = "Password is required";
    } elseif (strlen($password) < 8) {
        $errors[] = "Password must be 8+ characters";
    } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/', $password)) {
        $errors[] = "Password needs: uppercase, lowercase, number, special char";
    }

    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match";
    }

    // === DUPLICATE CHECKS (if no validation errors) ===
    if (empty($errors)) {
        try {
            // Check email
            $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            if ($stmt->rowCount() > 0) {
                $errors[] = "Email already registered";
            }

            // Check PF number
            if (empty($errors)) {
                $stmt = $conn->prepare("SELECT user_id FROM users WHERE pf_number = ? LIMIT 1");
                $stmt->execute([$pf_number_int]);
                if ($stmt->rowCount() > 0) {
                    $errors[] = "PF Number already in use";
                }
            }

        } catch (PDOException $e) {
            error_log("Duplicate check failed: " . $e->getMessage());
            $errors[] = "System error. Please try again.";
        }
    }

    // === FINAL INSERT ===
    if (empty($errors)) {
        try {
            $hashed_password = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

            $stmt = $conn->prepare("
                INSERT INTO users 
                (id_number, pf_number, full_name, email, password, role, is_active) 
                VALUES 
                (?, ?, ?, ?, ?, 'loanee', 1)
            ");

            $success = $stmt->execute([
                $id_number,
                $pf_number_int,
                $full_name,
                $email,
                $hashed_password
            ]);

            if ($success) {
                $_SESSION['registration_success'] = true;
                $_SESSION['success_message'] = "Account created successfully! Please login.";

                json_response(true, "Registration successful! Redirecting to login...");
            } else {
                $errorInfo = $stmt->errorInfo();
                $msg = "Insert failed: " . $errorInfo[2];
                error_log($msg);
                json_response(false, "Registration failed", [$msg]);
            }

        } catch (PDOException $e) {
            error_log("INSERT Error: " . $e->getMessage());
            json_response(false, "Database error", ["Please try again later."]);
        }
    } else {
        json_response(false, "Please fix the errors below", $errors);
    }
}

// === HTML FORM (Non-AJAX) ===
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Loan Management System</title>
    <style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background-color: #e0f2f1;
        /* Changed to match index.php */
        min-height: 100vh;
        display: flex;
        justify-content: center;
        align-items: center;
        padding: 20px;
    }

    .container {
        background: white;
        padding: 40px;
        border-radius: 15px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        width: 450px;
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
        /* Changed to match index.php */
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
        position: relative;
    }

    label {
        display: block;
        margin-bottom: 8px;
        color: #333;
        font-weight: 500;
    }

    input {
        width: 100%;
        padding: 12px;
        padding-right: 60px;
        /* Make room for the toggle button */
        border: 1px solid #ddd;
        border-radius: 5px;
        font-size: 14px;
        transition: all 0.3s;
        height: 44px;
        /* Fixed height for consistent positioning */
    }

    input:focus {
        outline: none;
        border-color: #2e7d32;
        /* Changed to match index.php */
        box-shadow: 0 0 0 2px rgba(46, 125, 50, 0.2);
        /* Changed to match index.php */
    }

    input.error {
        border-color: #c62828;
    }

    .btn {
        width: 100%;
        padding: 12px;
        background-color: #2e7d32;
        /* Changed to match index.php */
        color: white;
        border: none;
        border-radius: 5px;
        font-size: 16px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s;
        margin-bottom: 10px;
    }

    .btn:hover {
        background-color: #1b5e20;
        /* Changed to match index.php */
    }

    .btn:active {
        transform: translateY(1px);
    }

    .btn:disabled {
        opacity: 0.7;
        cursor: not-allowed;
        transform: none;
    }

    .notification {
        padding: 12px;
        margin-bottom: 20px;
        border-radius: 5px;
        display: none;
        font-size: 14px;
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

    .notification.error {
        background: #ffebee;
        color: #c62828;
        border: 1px solid #ef9a9a;
    }

    .notification.success {
        background: #e8f5e9;
        color: #2e7d32;
        border: 1px solid #a5d6a7;
    }

    .login-link {
        text-align: center;
        margin-top: 20px;
        color: #666;
    }

    .login-link a {
        color: #2e7d32;
        /* Changed to match index.php */
        text-decoration: none;
        font-weight: 500;
    }

    .login-link a:hover {
        text-decoration: underline;
    }

    .loading {
        text-align: center;
        margin-top: 10px;
        display: none;
    }

    .spinner {
        border: 3px solid #f3f3f3;
        border-top: 3px solid #2e7d32;
        /* Changed to match index.php */
        border-radius: 50%;
        width: 20px;
        height: 20px;
        animation: spin 1s linear infinite;
        display: inline-block;
        margin-right: 8px;
    }

    @keyframes spin {
        to {
            transform: rotate(360deg);
        }
    }

    .password-toggle {
        position: relative;
    }

    .toggle-password {
        position: absolute;
        right: 12px;
        top: 0;
        bottom: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        color: #666;
        font-size: 14px;
        user-select: none;
        width: 40px;
        text-align: center;
        background: transparent;
        border: none;
        z-index: 10;
    }

    .password-requirements {
        font-size: 12px;
        color: #666;
        margin-top: 5px;
    }

    .password-strength {
        height: 5px;
        margin-top: 5px;
        background: #e9ecef;
        border-radius: 3px;
        overflow: hidden;
    }

    .password-strength-bar {
        height: 100%;
        width: 0;
        transition: 0.3s;
    }

    .weak .password-strength-bar {
        background: #dc3545;
        width: 33%;
    }

    .medium .password-strength-bar {
        background: #ffc107;
        width: 66%;
    }

    .strong .password-strength-bar {
        background: #28a745;
        width: 100%;
    }
    </style>
    <script src="js/lucide.min.js"></script>
</head>

<body>
    <div class="container">
        <div class="logo">
            <div class="emblem">
                <img src="logo.jpg" alt="Nyeri County Government Emblem"
                    onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                <div
                    style="display:none; width:100%; height:100%; justify-content:center; align-items:center; font-size:40px; color:#2e7d32;">
                    <i data-lucide="landmark" class="icon-sm"></i></div>
            </div>
            <h1>Nyeri County Loan Management System</h1>
            <p>Loanee Registration</p>
        </div>

        <div id="notification" class="notification"></div>

        <form id="registerForm" novalidate>
            <div class="form-group">
                <label>ID Number *</label>
                <input type="text" name="id_number" required pattern="[0-9]{6,10}" maxlength="10"
                    placeholder="e.g. 12345678">
            </div>

            <div class="form-group">
                <label>PF Number *</label>
                <input type="text" name="pf_number" required pattern="[0-9]{10,14}" maxlength="14"
                    placeholder="e.g. 1234567890">
            </div>

            <div class="form-group">
                <label>Full Name *</label>
                <input type="text" name="full_name" required pattern="[a-zA-Z\s]{2,100}" maxlength="100"
                    placeholder="John Doe">
            </div>

            <div class="form-group">
                <label>Email *</label>
                <input type="email" name="email" required maxlength="100" placeholder="you@example.com">
            </div>

            <div class="form-group password-toggle">
                <label>Password *</label>
                <input type="password" name="password" id="password" required minlength="8">
                <button type="button" class="toggle-password" onclick="toggle('password')">Show</button>
                <div class="password-requirements">
                    8+ chars, uppercase, lowercase, number, special (@$!%*?&)
                </div>
                <div id="strength" class="password-strength">
                    <div class="password-strength-bar"></div>
                </div>
            </div>

            <div class="form-group password-toggle">
                <label>Confirm Password *</label>
                <input type="password" name="confirm_password" id="confirm_password" required>
                <button type="button" class="toggle-password" onclick="toggle('confirm_password')">Show</button>
            </div>

            <button type="submit" class="btn" id="btn">Register</button>
            <div class="loading" id="loading">
                <div class="spinner"></div> Creating account...
            </div>
        </form>

        <div class="login-link">
            Already have an account? <a href="index.php">Login here</a>
        </div>
    </div>

    <script>
    function toggle(id) {
        const input = document.getElementById(id);
        const icon = input.nextElementSibling;
        if (input.type === 'password') {
            input.type = 'text';
            icon.textContent = 'Hide';
        } else {
            input.type = 'password';
            icon.textContent = 'Show';
        }
    }

    function checkStrength(pwd) {
        let s = 0;
        if (pwd.length >= 8) s++;
        if (pwd.length >= 12) s++;
        if (/[a-z]/.test(pwd)) s++;
        if (/[A-Z]/.test(pwd)) s++;
        if (/\d/.test(pwd)) s++;
        if (/[@$!%*?&]/.test(pwd)) s++;
        return s;
    }

    document.getElementById('password').addEventListener('input', function() {
        const pwd = this.value;
        const bar = document.querySelector('#strength .password-strength-bar');
        const strength = checkStrength(pwd);
        const el = document.getElementById('strength');

        el.className = 'password-strength';
        if (strength < 3) el.classList.add('weak');
        else if (strength < 5) el.classList.add('medium');
        else el.classList.add('strong');
        bar.style.width = strength < 3 ? '33%' : strength < 5 ? '66%' : '100%';
    });

    document.getElementById('registerForm').addEventListener('submit', function(e) {
        e.preventDefault();

        const btn = document.getElementById('btn');
        const loading = document.getElementById('loading');
        const notif = document.getElementById('notification');

        if (!this.checkValidity()) {
            show('Please fill all fields correctly', 'error');
            return;
        }

        btn.disabled = true;
        btn.textContent = 'Please wait...';
        loading.style.display = 'block';

        const formData = new FormData(this);

        fetch('register.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    show(data.message, 'success');
                    setTimeout(() => window.location.href = 'index.php', 1500);
                } else {
                    const msg = data.errors ? data.errors.join('<br>') : data.message;
                    show(msg, 'error');
                }
            })
            .catch(err => {
                console.error(err);
                show('Network error. Please try again.', 'error');
            })
            .finally(() => {
                btn.disabled = false;
                btn.textContent = 'Register';
                loading.style.display = 'none';
            });
    });

    function show(msg, type) {
        const n = document.getElementById('notification');
        n.innerHTML = msg;
        n.className = 'notification ' + type;
        n.style.display = 'block';
        setTimeout(() => n.style.display = 'none', 6000);
    }

    // Auto-focus
    document.querySelector('[name="id_number"]').focus();
    </script>
        <script>
    const renderIcons = () => {
        lucide.createIcons();
        document.querySelectorAll('svg[data-lucide]').forEach(el => el.removeAttribute('data-lucide'));
    };
    renderIcons();
    const observer = new MutationObserver((mutations) => {
        let hasNewNodes = false;
        mutations.forEach((mutation) => {
            mutation.addedNodes.forEach(node => {
                if (node.nodeType === 1) hasNewNodes = true;
            });
        });
        if (hasNewNodes) {
            observer.disconnect();
            renderIcons();
            observer.observe(document.body, { childList: true, subtree: true });
        }
    });
    observer.observe(document.body, { childList: true, subtree: true });
</script>
</body>

</html>
