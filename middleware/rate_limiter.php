<?php
class RateLimiter {
    private static $requests = [];
    private static $limits = [
        'login' => ['requests' => 5, 'window' => 300], // 5 requests per 5 minutes
        'api' => ['requests' => 100, 'window' => 60] // 100 requests per minute
    ];
    
    public static function check($type, $identifier) {
        $now = time();
        $window = self::$limits[$type]['window'];
        $max_requests = self::$limits[$type]['requests'];
        
        if (!isset(self::$requests[$type][$identifier])) {
            self::$requests[$type][$identifier] = [];
        }
        
        // Clean old requests
        self::$requests[$type][$identifier] = array_filter(
            self::$requests[$type][$identifier],
            function($timestamp) use ($now, $window) {
                return $timestamp > ($now - $window);
            }
        );
        
        // Check if limit exceeded
        if (count(self::$requests[$type][$identifier]) >= $max_requests) {
            ResponseHelper::error('Rate limit exceeded. Please try again later.', 429);
        }
        
        // Add current request
        self::$requests[$type][$identifier][] = $now;
    }
}
?>