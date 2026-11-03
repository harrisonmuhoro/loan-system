<?php
// Include config file where constants are defined
require_once __DIR__ . '/config.php';

class Database {
    private $host = DB_HOST;
    private $db_name = DB_NAME;
    private $username = DB_USER;
    private $password = DB_PASS;
    private $conn = null;
    private $isConnected = false;

    public function getConnection() {
        // Return existing connection if available
        if ($this->conn !== null && $this->isConnected) {
            return $this->conn;
        }

        try {
            conditional_error_log("Attempting database connection to {$this->host}/{$this->db_name}");
            
            $dsn = "mysql:host={$this->host};dbname={$this->db_name};charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => true, // Connection pooling
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                PDO::ATTR_TIMEOUT => 30, // Connection timeout
            ];
            
            $this->conn = new PDO($dsn, $this->username, $this->password, $options);
            
            // Set SQL mode for strict behavior
            $this->conn->exec("SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
            
            // Set timezone
            $this->conn->exec("SET time_zone = '+03:00'"); // Nairobi timezone
            
            $this->isConnected = true;
            conditional_error_log("Database connection successful");
            
            return $this->conn;
            
        } catch (PDOException $e) {
            $this->isConnected = false;
            $this->conn = null;
            
            $errorMsg = "Database connection error: " . $e->getMessage();
            conditional_error_log($errorMsg);
            
            // In development, show detailed error
            if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
                throw new Exception($errorMsg);
            } else {
                // In production, show generic error
                throw new Exception("Database connection failed. Please try again later.");
            }
        }
    }
    
    public function closeConnection() {
        if ($this->conn !== null) {
            $this->conn = null;
            $this->isConnected = false;
            conditional_error_log("Database connection closed");
        }
    }
    
    public function isConnected() {
        return $this->isConnected && $this->conn !== null;
    }
    
    public function __destruct() {
        $this->closeConnection();
    }
    
    // Test connection method
    public function testConnection() {
        try {
            $conn = $this->getConnection();
            $stmt = $conn->query("SELECT 1 as test");
            $result = $stmt->fetch();
            return $result['test'] === 1;
        } catch (Exception $e) {
            conditional_error_log("Connection test failed: " . $e->getMessage());
            return false;
        }
    }
    
    // Get database info for debugging
    public function getDatabaseInfo() {
        try {
            $conn = $this->getConnection();
            $stmt = $conn->query("SELECT VERSION() as version, DATABASE() as database");
            return $stmt->fetch();
        } catch (Exception $e) {
            conditional_error_log("Failed to get database info: " . $e->getMessage());
            return false;
        }
    }
}
?>