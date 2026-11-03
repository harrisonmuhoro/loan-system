<?php
// Include required dependencies at the top
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helper/response_helper.php';

class APIHelper {
    /**
     * Validate required fields in request data
     * 
     * @param object|array $data Request data
     * @param array $required_fields Array of required field names
     * @throws Exception If required fields are missing
     */
    public static function validateRequired($data, $required_fields) {
        // Ensure session is started if needed
        self::ensureSessionStarted();
        
        $missing = [];
        
        // Convert to array if object
        $dataArray = is_object($data) ? (array)$data : $data;
        
        foreach ($required_fields as $field) {
            if (!isset($dataArray[$field]) || $dataArray[$field] === '' || $dataArray[$field] === null) {
                $missing[] = $field;
            }
        }
        
        if (!empty($missing)) {
            ResponseHelper::error('Missing required fields: ' . implode(', ', $missing), 400);
        }
    }
    
    /**
     * Sanitize input data to prevent XSS and injection attacks
     * 
     * @param object|array $data Data to sanitize
     * @param array $options Sanitization options
     * @return object|array Sanitized data
     */
    public static function sanitizeInput($data, $options = []) {
        // Ensure session is started if needed
        self::ensureSessionStarted();
        
        $defaultOptions = [
            'int' => FILTER_SANITIZE_NUMBER_INT,
            'float' => FILTER_SANITIZE_NUMBER_FLOAT,
            'email' => FILTER_SANITIZE_EMAIL,
            'url' => FILTER_SANITIZE_URL,
            'encoded' => FILTER_SANITIZE_ENCODED,
            'special_chars' => FILTER_SANITIZE_SPECIAL_CHARS
        ];
        
        $options = array_merge($defaultOptions, $options);
        
        if (is_object($data)) {
            foreach ($data as $key => $value) {
                if (is_string($value)) {
                    // First strip tags, then apply htmlspecialchars for additional protection
                    $data->$key = htmlspecialchars(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
        } elseif (is_array($data)) {
            foreach ($data as $key => $value) {
                if (is_string($value)) {
                    // First strip tags, then apply htmlspecialchars for additional protection
                    $data[$key] = htmlspecialchars(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
        }
        
        return $data;
    }
    
    /**
     * Validate and sanitize specific input fields with their expected types
     * 
     * @param array $data Input data
     * @param array $fieldSpecs Field specifications with validation rules
     * @return array Validated and sanitized data
     * @throws Exception If validation fails
     */
    public static function validateAndSanitizeFields($data, $fieldSpecs) {
        // Ensure session is started if needed
        self::ensureSessionStarted();
        
        $result = [];
        
        foreach ($fieldSpecs as $field => $spec) {
            if (!isset($data[$field])) {
                if ($spec['required'] ?? false) {
                    ResponseHelper::error("Missing required field: $field", 400);
                }
                continue;
            }
            
            $value = $data[$field];
            
            // Type validation
            switch ($spec['type'] ?? 'string') {
                case 'email':
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        ResponseHelper::error("Invalid email format for field: $field", 400);
                    }
                    $result[$field] = filter_var($value, FILTER_SANITIZE_EMAIL);
                    break;
                    
                case 'int':
                    if (!filter_var($value, FILTER_VALIDATE_INT)) {
                        ResponseHelper::error("Invalid integer format for field: $field", 400);
                    }
                    $result[$field] = filter_var($value, FILTER_SANITIZE_NUMBER_INT);
                    break;
                    
                case 'float':
                    if (!filter_var($value, FILTER_VALIDATE_FLOAT)) {
                        ResponseHelper::error("Invalid number format for field: $field", 400);
                    }
                    $result[$field] = filter_var($value, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
                    break;
                    
                case 'url':
                    if (!filter_var($value, FILTER_VALIDATE_URL)) {
                        ResponseHelper::error("Invalid URL format for field: $field", 400);
                    }
                    $result[$field] = filter_var($value, FILTER_SANITIZE_URL);
                    break;
                    
                case 'date':
                    $format = $spec['format'] ?? 'Y-m-d';
                    $date = DateTime::createFromFormat($format, $value);
                    if (!$date) {
                        ResponseHelper::error("Invalid date format for field: $field", 400);
                    }
                    $result[$field] = $date->format($format);
                    break;
                    
                case 'string':
                default:
                    // Check min/max length if specified
                    $length = strlen($value);
                    if (isset($spec['minLength']) && $length < $spec['minLength']) {
                        ResponseHelper::error("Field $field is too short (minimum {$spec['minLength']} characters)", 400);
                    }
                    if (isset($spec['maxLength']) && $length > $spec['maxLength']) {
                        ResponseHelper::error("Field $field is too long (maximum {$spec['maxLength']} characters)", 400);
                    }
                    
                    // Sanitize string
                    $result[$field] = htmlspecialchars(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    break;
            }
            
            // Custom validation if specified
            if (isset($spec['validate']) && is_callable($spec['validate'])) {
                if (!$spec['validate']($result[$field])) {
                    ResponseHelper::error("Custom validation failed for field: $field", 400);
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Generate a unique application ID with additional entropy
     * 
     * @return string Application ID
     */
    public static function generateApplicationId() {
        return 'APP_' . date('Y') . '_' . strtoupper(bin2hex(random_bytes(8)));
    }
    
    /**
     * Calculate loan amortization schedule with enhanced precision
     * 
     * @param float $principal Loan principal amount
     * @param float $annual_rate Annual interest rate (percentage)
     * @param int $years Loan term in years
     * @param string $start_date Payment start date (Y-m-d format)
     * @return array Amortization schedule
     */
    public static function calculateLoanAmortization($principal, $annual_rate, $years, $start_date) {
        // Ensure session is started if needed
        self::ensureSessionStarted();
        
        // Validate inputs
        if ($principal <= 0) {
            ResponseHelper::error('Principal amount must be greater than zero', 400);
        }
        
        if ($annual_rate < 0) {
            ResponseHelper::error('Annual rate cannot be negative', 400);
        }
        
        if ($years <= 0) {
            ResponseHelper::error('Loan term must be greater than zero', 400);
        }
        
        $monthly_rate = $annual_rate / 100 / 12;
        $total_months = $years * 12;
        
        if ($monthly_rate == 0) {
            $monthly_payment = $principal / $total_months;
        } else {
            $monthly_payment = $principal * ($monthly_rate * pow(1 + $monthly_rate, $total_months)) / 
                              (pow(1 + $monthly_rate, $total_months) - 1);
        }
        
        $schedule = [];
        $balance = $principal;
        $total_interest = 0;
        
        for ($i = 1; $i <= $total_months; $i++) {
            $interest_payment = $balance * $monthly_rate;
            $principal_payment = $monthly_payment - $interest_payment;
            $balance -= $principal_payment;
            $total_interest += $interest_payment;
            
            $due_date = date('Y-m-d', strtotime($start_date . " +$i months"));
            
            $schedule[] = [
                'payment_number' => $i,
                'due_date' => $due_date,
                'monthly_payment' => round($monthly_payment, 2),
                'principal' => round($principal_payment, 2),
                'interest' => round($interest_payment, 2),
                'balance' => round(max(0, $balance), 2)
            ];
        }
        
        // Add summary to the schedule
        $schedule['summary'] = [
            'total_payments' => $total_months,
            'monthly_payment' => round($monthly_payment, 2),
            'total_principal' => round($principal, 2),
            'total_interest' => round($total_interest, 2),
            'total_amount' => round($principal + $total_interest, 2)
        ];
        
        return $schedule;
    }
    
    /**
     * Validate password strength according to security requirements
     * 
     * @param string $password Password to validate
     * @param array $options Validation options
     * @return array Validation result with 'valid' boolean and 'message' string
     */
    public static function validatePasswordStrength($password, $options = []) {
        // Ensure session is started if needed
        self::ensureSessionStarted();
        
        $defaultOptions = [
            'min_length' => 8,
            'require_uppercase' => true,
            'require_lowercase' => true,
            'require_number' => true,
            'require_special' => true,
            'forbidden_patterns' => ['password', '123456', 'qwerty'],
            'check_common_passwords' => true
        ];
        
        $options = array_merge($defaultOptions, $options);
        
        if (empty($password)) {
            return ['valid' => false, 'message' => 'Password cannot be empty'];
        }
        
        if (strlen($password) < $options['min_length']) {
            return ['valid' => false, 'message' => "Password must be at least {$options['min_length']} characters long"];
        }
        
        if ($options['require_uppercase'] && !preg_match('/[A-Z]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least one uppercase letter'];
        }
        
        if ($options['require_lowercase'] && !preg_match('/[a-z]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least one lowercase letter'];
        }
        
        if ($options['require_number'] && !preg_match('/[0-9]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least one number'];
        }
        
        if ($options['require_special'] && !preg_match('/[^A-Za-z0-9]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least one special character'];
        }
        
        // Check for forbidden patterns
        $passwordLower = strtolower($password);
        foreach ($options['forbidden_patterns'] as $pattern) {
            if (strpos($passwordLower, $pattern) !== false) {
                return ['valid' => false, 'message' => 'Password contains a common pattern that is not allowed'];
            }
        }
        
        // Check against common passwords if enabled
        if ($options['check_common_passwords'] && self::isCommonPassword($password)) {
            return ['valid' => false, 'message' => 'Password is too common and easily guessable'];
        }
        
        return ['valid' => true, 'message' => 'Password meets security requirements'];
    }
    
    /**
     * Check if a password is in a list of common passwords
     * 
     * @param string $password Password to check
     * @return bool True if it's a common password
     */
    private static function isCommonPassword($password) {
        // In a real implementation, this would check against a comprehensive list
        // For demonstration, we'll check against a small sample
        $commonPasswords = [
            'password', '123456', '123456789', 'qwerty', 'abc123',
            'password123', 'admin', 'letmein', 'welcome', 'monkey'
        ];
        
        return in_array(strtolower($password), $commonPasswords);
    }
    
    /**
     * Hash a password using the best available algorithm
     * 
     * @param string $password Plain text password
     * @param array $options Hashing options
     * @return string Password hash
     */
    public static function hashPassword($password, $options = []) {
        // Ensure session is started if needed
        self::ensureSessionStarted();
        
        $defaultOptions = [
            'cost' => 12,
            'algorithm' => PASSWORD_BCRYPT
        ];
        
        $options = array_merge($defaultOptions, $options);
        
        return password_hash($password, $options['algorithm'], ['cost' => $options['cost']]);
    }
    
    /**
     * Verify a password against its hash
     * 
     * @param string $password Plain text password
     * @param string $hash Password hash
     * @return bool True if password matches, false otherwise
     */
    public static function verifyPassword($password, $hash) {
        // Ensure session is started if needed
        self::ensureSessionStarted();
        
        return password_verify($password, $hash);
    }
    
    /**
     * Check if a password needs rehashing
     * 
     * @param string $hash Existing password hash
     * @param array $options Hashing options to check against
     * @return bool True if rehashing is needed
     */
    public static function passwordNeedsRehash($hash, $options = []) {
        // Ensure session is started if needed
        self::ensureSessionStarted();
        
        $defaultOptions = [
            'cost' => 12,
            'algorithm' => PASSWORD_BCRYPT
        ];
        
        $options = array_merge($defaultOptions, $options);
        
        return password_needs_rehash($hash, $options['algorithm'], ['cost' => $options['cost']]);
    }
    
    /**
     * Generate a secure random token for password resets or verification
     * 
     * @param int $length Token length in bytes
     * @return string Hex-encoded random token
     */
    public static function generateSecureToken($length = 32) {
        // Ensure session is started if needed
        self::ensureSessionStarted();
        
        return bin2hex(random_bytes($length));
    }
    
    /**
     * Generate a CSRF token for form protection with expiration
     * 
     * @param int $expire_seconds Token expiration time in seconds (default: 1 hour)
     * @return string CSRF token
     */
    public static function generateCSRFToken($expire_seconds = 3600) {
        // Ensure session is started
        self::ensureSessionStarted();
        
        // Generate a new token
        $token = self::generateSecureToken(32);
        
        // Store token with expiration
        $_SESSION['csrf_token'] = $token;
        $_SESSION['csrf_token_expire'] = time() + $expire_seconds;
        
        return $token;
    }
    
    /**
     * Validate a CSRF token against the session token
     * 
     * @param string $token Token to validate
     * @param bool $regenerate Whether to regenerate the token after validation
     * @return bool True if valid, false otherwise
     */
    public static function validateCSRFToken($token, $regenerate = true) {
        // Ensure session is started
        self::ensureSessionStarted();
        
        // Check if token exists in session
        if (empty($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        
        // Check if token has expired
        if (isset($_SESSION['csrf_token_expire']) && time() > $_SESSION['csrf_token_expire']) {
            unset($_SESSION['csrf_token'], $_SESSION['csrf_token_expire']);
            return false;
        }
        
        // Use hash_equals to prevent timing attacks
        $isValid = hash_equals($_SESSION['csrf_token'], $token);
        
        // Regenerate token if requested and valid
        if ($isValid && $regenerate) {
            self::generateCSRFToken();
        }
        
        return $isValid;
    }
    
    /**
     * Rate limiting helper to prevent brute force attacks
     * 
     * @param string $identifier Unique identifier (email, IP, etc.)
     * @param string $action Action type (login, password_reset, etc.)
     * @param int $max_attempts Maximum allowed attempts
     * @param int $time_window Time window in seconds
     * @param string $storage Storage mechanism (session, file, database)
     * @param bool $progressive_delay Whether to apply progressive delays
     * @return array Result with 'allowed' boolean and 'remaining' attempts
     */
    public static function rateLimitCheck($identifier, $action = 'default', $max_attempts = 5, $time_window = 900, $storage = 'session', $progressive_delay = true) {
        // Ensure session is started if needed
        self::ensureSessionStarted();
        
        $key = 'rate_limit_' . md5($identifier . '_' . $action);
        
        // Use different storage mechanisms based on the $storage parameter
        switch ($storage) {
            case 'file':
                $data = self::rateLimitFileStorage($key, $max_attempts, $time_window, $progressive_delay);
                break;
                
            case 'database':
                $data = self::rateLimitDatabaseStorage($key, $max_attempts, $time_window, $progressive_delay);
                break;
                
            case 'session':
            default:
                $data = self::rateLimitSessionStorage($key, $max_attempts, $time_window, $progressive_delay);
                break;
        }
        
        return $data;
    }
    
    /**
     * Session-based rate limiting storage
     */
    private static function rateLimitSessionStorage($key, $max_attempts, $time_window, $progressive_delay) {
        // Ensure session is started
        self::ensureSessionStarted();
        
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = ['attempts' => 0, 'first_attempt' => time()];
        }
        
        $data = $_SESSION[$key];
        
        // Reset if time window has passed
        if (time() - $data['first_attempt'] > $time_window) {
            $_SESSION[$key] = ['attempts' => 1, 'first_attempt' => time()];
            return ['allowed' => true, 'remaining' => $max_attempts - 1, 'retry_after' => 0];
        }
        
        // Check if max attempts reached
        if ($data['attempts'] >= $max_attempts) {
            $retry_after = $time_window - (time() - $data['first_attempt']);
            return ['allowed' => false, 'remaining' => 0, 'retry_after' => $retry_after];
        }
        
        // Increment attempts
        $_SESSION[$key]['attempts']++;
        
        // Apply progressive delay if enabled
        $delay = 0;
        if ($progressive_delay && $_SESSION[$key]['attempts'] > 1) {
            // Exponential backoff: 2^attempts seconds, capped at 30 seconds
            $delay = min(pow(2, $_SESSION[$key]['attempts'] - 1), 30);
            sleep($delay);
        }
        
        return [
            'allowed' => true, 
            'remaining' => $max_attempts - $_SESSION[$key]['attempts'], 
            'delay' => $delay,
            'retry_after' => 0
        ];
    }
    
    /**
     * File-based rate limiting storage
     */
    private static function rateLimitFileStorage($key, $max_attempts, $time_window, $progressive_delay) {
        // Ensure session is started if needed
        self::ensureSessionStarted();
        
        $file = sys_get_temp_dir() . '/' . $key . '.json';
        
        // Use file locking to prevent race conditions
        $handle = fopen($file, 'c+');
        if (!$handle) {
            // Fallback to session storage if file cannot be opened
            return self::rateLimitSessionStorage($key, $max_attempts, $time_window, $progressive_delay);
        }
        
        if (flock($handle, LOCK_EX)) {
            $data = [];
            
            // Read existing data if file exists and is not empty
            if (filesize($file) > 0) {
                $content = fread($handle, filesize($file));
                $data = json_decode($content, true) ?: [];
            }
            
            // Reset if time window has passed
            if (isset($data['first_attempt']) && time() - $data['first_attempt'] > $time_window) {
                $data = ['attempts' => 1, 'first_attempt' => time()];
            } else {
                $data['attempts'] = isset($data['attempts']) ? $data['attempts'] + 1 : 1;
                if (!isset($data['first_attempt'])) {
                    $data['first_attempt'] = time();
                }
            }
            
            // Write updated data
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($data));
            
            // Release lock
            flock($handle, LOCK_UN);
        } else {
            // Fallback to session storage if lock cannot be acquired
            fclose($handle);
            return self::rateLimitSessionStorage($key, $max_attempts, $time_window, $progressive_delay);
        }
        
        fclose($handle);
        
        // Check if max attempts reached
        if ($data['attempts'] >= $max_attempts) {
            $retry_after = $time_window - (time() - $data['first_attempt']);
            return ['allowed' => false, 'remaining' => 0, 'retry_after' => $retry_after];
        }
        
        // Apply progressive delay if enabled
        $delay = 0;
        if ($progressive_delay && $data['attempts'] > 1) {
            // Exponential backoff: 2^attempts seconds, capped at 30 seconds
            $delay = min(pow(2, $data['attempts'] - 1), 30);
            sleep($delay);
        }
        
        return [
            'allowed' => true, 
            'remaining' => $max_attempts - $data['attempts'], 
            'delay' => $delay,
            'retry_after' => 0
        ];
    }
    
    /**
     * Database-based rate limiting storage (example implementation)
     */
    private static function rateLimitDatabaseStorage($key, $max_attempts, $time_window, $progressive_delay) {
        // Ensure session is started if needed
        self::ensureSessionStarted();
        
        try {
            $db = self::getDatabaseInstance();
            
            // Check if record exists
            $stmt = $db->prepare("SELECT * FROM rate_limits WHERE `key` = ? AND created_at > ?");
            $stmt->execute([$key, date('Y-m-d H:i:s', time() - $time_window)]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($record) {
                // Update existing record
                $attempts = $record['attempts'] + 1;
                $stmt = $db->prepare("UPDATE rate_limits SET attempts = ? WHERE id = ?");
                $stmt->execute([$attempts, $record['id']]);
            } else {
                // Create new record
                $attempts = 1;
                $stmt = $db->prepare("INSERT INTO rate_limits (`key`, attempts, created_at) VALUES (?, ?, ?)");
                $stmt->execute([$key, $attempts, date('Y-m-d H:i:s')]);
            }
            
            // Check if max attempts reached
            if ($attempts >= $max_attempts) {
                $retry_after = $time_window - (time() - strtotime($record['created_at']));
                return ['allowed' => false, 'remaining' => 0, 'retry_after' => $retry_after];
            }
            
            // Apply progressive delay if enabled
            $delay = 0;
            if ($progressive_delay && $attempts > 1) {
                // Exponential backoff: 2^attempts seconds, capped at 30 seconds
                $delay = min(pow(2, $attempts - 1), 30);
                sleep($delay);
            }
            
            return [
                'allowed' => true, 
                'remaining' => $max_attempts - $attempts, 
                'delay' => $delay,
                'retry_after' => 0
            ];
            
        } catch (Exception $e) {
            // Log error and fallback to session storage
            error_log("Rate limiting database error: " . $e->getMessage());
            return self::rateLimitSessionStorage($key, $max_attempts, $time_window, $progressive_delay);
        }
    }
    
    /**
     * Log security events for monitoring
     * 
     * @param string $event Event description
     * @param string $level Log level (info, warning, error, critical)
     * @param array $context Additional context data
     * @param string $storage Storage mechanism (file, database, syslog)
     */
    public static function logSecurityEvent($event, $level = 'info', $context = [], $storage = 'file') {
        // Ensure session is started if needed
        self::ensureSessionStarted();
        
        $log_entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => $level,
            'event' => $event,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'user_id' => $_SESSION['user_id'] ?? null,
            'context' => $context
        ];
        
        // Use different storage mechanisms based on the $storage parameter
        switch ($storage) {
            case 'database':
                self::logToDatabase($log_entry);
                break;
                
            case 'syslog':
                self::logToSyslog($log_entry);
                break;
                
            case 'file':
            default:
                self::logToFile($log_entry);
                break;
        }
    }
    
    /**
     * Log to file
     */
    private static function logToFile($log_entry) {
        // Ensure session is started if needed
        self::ensureSessionStarted();
        
        $log_file = __DIR__ . '/../logs/security_' . date('Y-m-d') . '.log';
        
        // Ensure log directory exists with secure permissions
        $log_dir = dirname($log_file);
        if (!is_dir($log_dir)) {
            mkdir($log_dir, 0700, true); // Changed to 0700 for better security
        }
        
        // Format log entry
        $log_line = json_encode($log_entry) . PHP_EOL;
        
        // Write to log file with exclusive lock to prevent race conditions
        file_put_contents($log_file, $log_line, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Log to database
     */
    private static function logToDatabase($log_entry) {
        // Ensure session is started if needed
        self::ensureSessionStarted();
        
        try {
            $db = self::getDatabaseInstance();
            
            $stmt = $db->prepare("INSERT INTO security_logs 
                                  (timestamp, level, event, ip, user_agent, user_id, context) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?)");
            
            $stmt->execute([
                $log_entry['timestamp'],
                $log_entry['level'],
                $log_entry['event'],
                $log_entry['ip'],
                $log_entry['user_agent'],
                $log_entry['user_id'],
                json_encode($log_entry['context'])
            ]);
        } catch (Exception $e) {
            // Fallback to file logging if database fails
            error_log("Database logging failed: " . $e->getMessage());
            self::logToFile($log_entry);
        }
    }
    
    /**
     * Log to syslog
     */
    private static function logToSyslog($log_entry) {
        // Ensure session is started if needed
        self::ensureSessionStarted();
        
        $priority = LOG_INFO;
        
        switch ($log_entry['level']) {
            case 'debug':
                $priority = LOG_DEBUG;
                break;
            case 'info':
                $priority = LOG_INFO;
                break;
            case 'warning':
                $priority = LOG_WARNING;
                break;
            case 'error':
                $priority = LOG_ERR;
                break;
            case 'critical':
                $priority = LOG_CRIT;
                break;
        }
        
        openlog('security', LOG_PID | LOG_ODELAY, LOG_AUTH);
        syslog($priority, $log_entry['event'] . ' - ' . json_encode($log_entry['context']));
        closelog();
    }
    
    /**
     * Generate a secure API key for API authentication
     * 
     * @param string $prefix Optional prefix for the API key
     * @return string Generated API key
     */
    public static function generateAPIKey($prefix = 'API') {
        // Ensure session is started if needed
        self::ensureSessionStarted();
        
        // Use a more unpredictable timestamp component
        $timestamp = dechex(time());
        $random = bin2hex(random_bytes(16));
        return $prefix . '_' . $timestamp . '_' . strtoupper($random);
    }
    
    /**
     * Validate an API key against stored keys
     * 
     * @param string $apiKey API key to validate
     * @param string $userId User ID to check against (optional)
     * @return array Validation result with 'valid' boolean and 'user_id' if valid
     */
    public static function validateAPIKey($apiKey, $userId = null) {
        // Ensure session is started if needed
        self::ensureSessionStarted();
        
        if (empty($apiKey)) {
            return ['valid' => false, 'message' => 'API key is required'];
        }
        
        try {
            // This would typically check against a database
            // Example implementation:
            
            $db = self::getDatabaseInstance();
            
            $stmt = $db->prepare("SELECT * FROM api_keys WHERE api_key = ? AND status = 'active'");
            $stmt->execute([$apiKey]);
            $keyRecord = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$keyRecord) {
                return ['valid' => false, 'message' => 'Invalid API key'];
            }
            
            // Check if key has expired
            if (!empty($keyRecord['expires_at']) && strtotime($keyRecord['expires_at']) < time()) {
                return ['valid' => false, 'message' => 'API key has expired'];
            }
            
            // Check if user ID matches (if provided)
            if ($userId && $keyRecord['user_id'] != $userId) {
                return ['valid' => false, 'message' => 'API key does not belong to this user'];
            }
            
            // Update last used timestamp
            $stmt = $db->prepare("UPDATE api_keys SET last_used = ? WHERE id = ?");
            $stmt->execute([date('Y-m-d H:i:s'), $keyRecord['id']]);
            
            return [
                'valid' => true, 
                'user_id' => $keyRecord['user_id'],
                'permissions' => json_decode($keyRecord['permissions'], true)
            ];
            
        } catch (Exception $e) {
            error_log("API key validation error: " . $e->getMessage());
            return ['valid' => false, 'message' => 'API key validation failed'];
        }
    }
    
    /**
     * Secure session initialization
     * 
     * @param string $name Session name (optional)
     * @param array $options Session options
     */
    public static function secureSessionStart($name = null, $options = []) {
        // Set session name if provided
        if ($name) {
            session_name($name);
        }
        
        // Default secure session options
        $defaultOptions = [
            'lifetime' => 7200, // 2 hours
            'path' => '/',
            'domain' => $_SERVER['HTTP_HOST'] ?? '',
            'secure' => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Strict'
        ];
        
        $options = array_merge($defaultOptions, $options);
        
        // Set session cookie parameters
        session_set_cookie_params($options);
        
        // Start session
        session_start();
        
        // Regenerate session ID to prevent session fixation
        if (!isset($_SESSION['initiated'])) {
            session_regenerate_id(true);
            $_SESSION['initiated'] = true;
        }
        
        // Check for session hijacking with more flexible IP checking
        $currentIP = $_SERVER['REMOTE_ADDR'] ?? '';
        if (isset($_SESSION['ip_address'])) {
            // Check if IP has changed significantly (for users with dynamic IPs)
            if (!self::isIPSimilar($_SESSION['ip_address'], $currentIP)) {
                self::logSecurityEvent('Possible session hijacking detected', 'warning', [
                    'session_ip' => $_SESSION['ip_address'],
                    'current_ip' => $currentIP
                ]);
                
                // Destroy session and start a new one
                session_destroy();
                session_start();
                session_regenerate_id(true);
                $_SESSION['initiated'] = true;
            }
        }
        
        // Store current IP address
        $_SESSION['ip_address'] = $currentIP;
        
        // Set last activity time
        $_SESSION['last_activity'] = time();
    }
    
    /**
     * Check if two IP addresses are similar (same /24 subnet)
     * 
     * @param string $ip1 First IP address
     * @param string $ip2 Second IP address
     * @return bool True if IPs are in the same /24 subnet
     */
    private static function isIPSimilar($ip1, $ip2) {
        // Exact match
        if ($ip1 === $ip2) {
            return true;
        }
        
        // Check if both IPs are IPv4
        if (filter_var($ip1, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && 
            filter_var($ip2, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // Extract the first 3 octets (/24 subnet)
            $subnet1 = substr($ip1, 0, strrpos($ip1, '.'));
            $subnet2 = substr($ip2, 0, strrpos($ip2, '.'));
            
            return $subnet1 === $subnet2;
        }
        
        // For IPv6, just check exact match for now
        return false;
    }
    
    /**
     * Check if session has expired
     * 
     * @param int $max_lifetime Maximum session lifetime in seconds
     * @return bool True if session is expired, false otherwise
     */
    public static function isSessionExpired($max_lifetime = 7200) {
        // Ensure session is started
        self::ensureSessionStarted();
        
        if (!isset($_SESSION['last_activity'])) {
            return true;
        }
        
        return (time() - $_SESSION['last_activity']) > $max_lifetime;
    }
    
    /**
     * Destroy session securely
     */
    public static function secureSessionDestroy() {
        // Unset all session variables
        $_SESSION = [];
        
        // Delete session cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(), 
                '', 
                time() - 42000,
                $params["path"], 
                $params["domain"], 
                $params["secure"], 
                $params["httponly"]
            );
        }
        
        // Destroy session
        session_destroy();
    }
    
    /**
     * Generate a secure file name for uploads
     * 
     * @param string $originalName Original file name
     * @param string $prefix Optional prefix for the new file name
     * @return string Secure file name
     */
    public static function generateSecureFileName($originalName, $prefix = '') {
        // Ensure session is started if needed
        self::ensureSessionStarted();
        
        // Get file extension
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        
        // Generate random name
        $randomName = bin2hex(random_bytes(16));
        
        // Combine prefix, random name, and extension
        return $prefix . $randomName . '.' . $extension;
    }
    
    /**
     * Validate uploaded file
     * 
     * @param array $file File from $_FILES array
     * @param array $allowedTypes Allowed MIME types
     * @param int $maxSize Maximum file size in bytes
     * @return array Validation result
     */
    public static function validateUploadedFile($file, $allowedTypes = [], $maxSize = 5242880) {
        // Ensure session is started if needed
        self::ensureSessionStarted();
        
        // Check if file was uploaded
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return [
                'valid' => false,
                'message' => 'File upload error: ' . $file['error']
            ];
        }
        
        // Check file size
        if ($file['size'] > $maxSize) {
            return [
                'valid' => false,
                'message' => 'File size exceeds maximum allowed size'
            ];
        }
        
        // Check file type
        if (!empty($allowedTypes) && !in_array($file['type'], $allowedTypes)) {
            return [
                'valid' => false,
                'message' => 'File type not allowed'
            ];
        }
        
        // Additional check using finfo for more accurate MIME type detection
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedType = $finfo->file($file['tmp_name']);
        
        if (!empty($allowedTypes) && !in_array($detectedType, $allowedTypes)) {
            return [
                'valid' => false,
                'message' => 'File type not allowed (detected: ' . $detectedType . ')'
            ];
        }
        
        // Check file content to prevent disguised malicious files
        if (!self::validateFileContent($file['tmp_name'], $detectedType)) {
            return [
                'valid' => false,
                'message' => 'File content does not match its type'
            ];
        }
        
        return ['valid' => true];
    }
    
    /**
     * Validate file content to ensure it matches the declared type
     * 
     * @param string $filePath Path to the file
     * @param string $mimeType Expected MIME type
     * @return bool True if content is valid
     */
    private static function validateFileContent($filePath, $mimeType) {
        // Ensure session is started if needed
        self::ensureSessionStarted();
        
        // For images, check if it's a valid image
        if (strpos($mimeType, 'image/') === 0) {
            $imageInfo = @getimagesize($filePath);
            return $imageInfo !== false;
        }
        
        // For PDF files, check for PDF signature
        if ($mimeType === 'application/pdf') {
            $handle = fopen($filePath, 'r');
            $header = fread($handle, 4);
            fclose($handle);
            return $header === '%PDF';
        }
        
        // For text files, check for binary content
        if (strpos($mimeType, 'text/') === 0) {
            $handle = fopen($filePath, 'r');
            $chunk = fread($handle, 1024);
            fclose($handle);
            
            // Check for null bytes (common in binary files)
            return strpos($chunk, "\0") === false;
        }
        
        // For other types, we'll rely on the MIME type detection
        return true;
    }
    
    /**
     * Ensure session is started
     */
    private static function ensureSessionStarted() {
        if (session_status() === PHP_SESSION_NONE) {
            // Use default secure session settings
            self::secureSessionStart();
        }
    }
    
    /**
     * Get database instance
     */
    private static function getDatabaseInstance() {
        static $db = null;
        
        if ($db === null) {
            try {
                $database = new Database();
                $db = $database->getConnection();
                
                if (!$db || !($db instanceof PDO)) {
                    throw new Exception('Database connection is not a valid PDO instance');
                }
            } catch (Exception $e) {
                error_log('Database connection error: ' . $e->getMessage());
                throw $e;
            }
        }
        
        return $db;
    }
}
?>