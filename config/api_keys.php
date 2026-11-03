<?php
/*  config/api_keys.php  –  API Key Management with Logging  */

class ApiKeyManager
{
    private PDO $conn;
    private string $keysTable = 'api_keys';
    private string $logsTable = 'api_key_logs';
    private ?int $currentKeyId = null;
    
    public function __construct(PDO $db)
    {
        $this->conn = $db;
    }
    
    /**
     * Generate a new API key pair
     * @return array ['key' => string, 'secret' => string]
     */
    public static function generateKeyPair(): array
    {
        return [
            'key' => 'lsk_' . bin2hex(random_bytes(20)),    // 40 chars + prefix
            'secret' => 'lss_' . bin2hex(random_bytes(32))  // 64 chars + prefix
        ];
    }
    
    /**
     * Hash the API secret for storage
     */
    private static function hashSecret(string $secret): string
    {
        return password_hash($secret, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 3
        ]);
    }
    
    /**
     * Validate an API key and secret
     * @return array|false Returns key data or false
     */
    public function validateKey(string $apiKey, ?string $apiSecret = null): array|false
    {
        $sql = "SELECT id, api_key, api_secret, name, permissions, 
                       is_active, expires_at, user_id
                FROM {$this->keysTable} 
                WHERE api_key = :key AND is_active = 1";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':key', $apiKey, PDO::PARAM_STR);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            error_log("API Key not found or inactive: " . substr($apiKey, 0, 10) . "...");
            return false;
        }
        
        // Check if key has expired
        if ($result['expires_at'] && strtotime($result['expires_at']) < time()) {
            error_log("API Key expired: " . $result['name']);
            return false;
        }
        
        // Verify secret if provided (for sensitive operations)
        if ($apiSecret !== null) {
            if (!password_verify($apiSecret, $result['api_secret'])) {
                error_log("API Secret verification failed for: " . $result['name']);
                return false;
            }
        }
        
        // Update usage statistics
        $this->currentKeyId = (int)$result['id'];
        $this->updateUsageStats($result['id']);
        
        // Decode permissions
        $result['permissions'] = json_decode($result['permissions'] ?? '[]', true);
        
        return $result;
    }
    
    /**
     * Create a new API key
     */
    public function createKey(
        string $name,
        array $permissions = ['read', 'write'],
        ?string $description = null,
        ?int $userId = null,
        ?string $expiresAt = null
    ): array {
        $keyPair = self::generateKeyPair();
        $hashedSecret = self::hashSecret($keyPair['secret']);
        
        $sql = "INSERT INTO {$this->keysTable} 
                (api_key, api_secret, name, description, user_id, 
                 permissions, expires_at, created_at) 
                VALUES (:key, :secret, :name, :description, :user_id, 
                        :permissions, :expires_at, NOW())";
        
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(':key', $keyPair['key'], PDO::PARAM_STR);
            $stmt->bindValue(':secret', $hashedSecret, PDO::PARAM_STR);
            $stmt->bindValue(':name', $name, PDO::PARAM_STR);
            $stmt->bindValue(':description', $description, PDO::PARAM_STR);
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':permissions', json_encode($permissions), PDO::PARAM_STR);
            $stmt->bindValue(':expires_at', $expiresAt, PDO::PARAM_STR);
            
            if ($stmt->execute()) {
                return [
                    'success' => true,
                    'api_key' => $keyPair['key'],
                    'api_secret' => $keyPair['secret'],  // Only shown once!
                    'name' => $name,
                    'permissions' => $permissions,
                    'expires_at' => $expiresAt
                ];
            }
        } catch (PDOException $e) {
            error_log("Failed to create API key: " . $e->getMessage());
        }
        
        return ['success' => false, 'message' => 'Failed to create API key'];
    }
    
    /**
     * List all API keys (without secrets)
     */
    public function listKeys(?int $userId = null): array
    {
        $sql = "SELECT id, api_key, name, description, permissions, 
                       is_active, usage_count, last_used, expires_at, created_at
                FROM {$this->keysTable}";
        
        if ($userId !== null) {
            $sql .= " WHERE user_id = :user_id";
        }
        
        $sql .= " ORDER BY created_at DESC";
        
        $stmt = $this->conn->prepare($sql);
        
        if ($userId !== null) {
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        }
        
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Decode permissions JSON
        foreach ($results as &$row) {
            $row['permissions'] = json_decode($row['permissions'] ?? '[]', true);
            // Mask the API key for security
            $row['api_key_masked'] = substr($row['api_key'], 0, 12) . '...' . 
                                     substr($row['api_key'], -4);
        }
        
        return $results;
    }
    
    /**
     * Revoke an API key
     */
    public function revokeKey(string $apiKey, ?int $revokedBy = null): bool
    {
        $sql = "UPDATE {$this->keysTable} 
                SET is_active = 0, 
                    revoked_at = NOW(),
                    revoked_by = :revoked_by
                WHERE api_key = :key";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':key', $apiKey, PDO::PARAM_STR);
        $stmt->bindValue(':revoked_by', $revokedBy, PDO::PARAM_INT);
        
        return $stmt->execute();
    }
    
    /**
     * Update usage statistics
     */
    private function updateUsageStats(int $keyId): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        $sql = "UPDATE {$this->keysTable} 
                SET last_used = NOW(), 
                    usage_count = usage_count + 1,
                    last_ip = :ip
                WHERE id = :id";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':id', $keyId, PDO::PARAM_INT);
        $stmt->bindValue(':ip', $ip, PDO::PARAM_STR);
        $stmt->execute();
    }
    
    /**
     * Log API request
     */
    public function logRequest(
        string $endpoint,
        string $method,
        int $statusCode,
        int $responseTimeMs
    ): void {
        if ($this->currentKeyId === null) {
            return;
        }
        
        $sql = "INSERT INTO {$this->logsTable} 
                (api_key_id, endpoint, method, ip_address, user_agent, 
                 status_code, request_time, response_time_ms)
                VALUES (:key_id, :endpoint, :method, :ip, :user_agent, 
                        :status, NOW(), :response_time)";
        
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(':key_id', $this->currentKeyId, PDO::PARAM_INT);
            $stmt->bindValue(':endpoint', $endpoint, PDO::PARAM_STR);
            $stmt->bindValue(':method', $method, PDO::PARAM_STR);
            $stmt->bindValue(':ip', $_SERVER['REMOTE_ADDR'] ?? 'unknown', PDO::PARAM_STR);
            $stmt->bindValue(':user_agent', $_SERVER['HTTP_USER_AGENT'] ?? '', PDO::PARAM_STR);
            $stmt->bindValue(':status', $statusCode, PDO::PARAM_INT);
            $stmt->bindValue(':response_time', $responseTimeMs, PDO::PARAM_INT);
            $stmt->execute();
        } catch (PDOException $e) {
            error_log("Failed to log API request: " . $e->getMessage());
        }
    }
    
    /**
     * Check if key has permission
     */
    public function hasPermission(array $keyData, string $permission): bool
    {
        return in_array($permission, $keyData['permissions'] ?? [], true);
    }
    
    /**
     * Get usage statistics
     */
    public function getUsageStats(int $keyId, int $days = 7): array
    {
        $sql = "SELECT 
                    DATE(request_time) as date,
                    COUNT(*) as requests,
                    AVG(response_time_ms) as avg_response_time,
                    COUNT(DISTINCT ip_address) as unique_ips
                FROM {$this->logsTable}
                WHERE api_key_id = :key_id
                AND request_time >= DATE_SUB(NOW(), INTERVAL :days DAY)
                GROUP BY DATE(request_time)
                ORDER BY date DESC";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(':key_id', $keyId, PDO::PARAM_INT);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}