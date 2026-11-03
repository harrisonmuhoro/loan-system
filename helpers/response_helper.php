<?php
/**
 * Response Helper
 * Provides consistent JSON API responses across the system.
 */
class ResponseHelper
{
    /**
     * Send a success response (HTTP 200 by default).
     */
    public static function success($data = null, string $message = 'Success', int $status_code = 200): void
    {
        self::send(true, $message, $data, $status_code);
    }

    /**
     * Send an error response (HTTP 400 by default).
     */
    public static function error(string $message = 'Error', int $status_code = 400, mixed $data = null): void
    {
        self::send(false, $message, $data, $status_code);
    }

    /**
     * Standardized unauthorized response (HTTP 401).
     */
    public static function unauthorized(string $message = 'Unauthorized. Please login.'): void
    {
        self::error($message, 401);
    }

    /**
     * Standardized forbidden response (HTTP 403).
     */
    public static function forbidden(string $message = 'Access denied.'): void
    {
        self::error($message, 403);
    }

    /**
     * Standardized not found response (HTTP 404).
     */
    public static function notFound(string $message = 'Not Found'): void
    {
        self::error($message, 404);
    }

    /**
     * Standardized server error response (HTTP 500).
     */
    public static function serverError(string $message = 'Internal Server Error'): void
    {
        self::error($message, 500);
    }

    /**
     * Internal method to structure and output responses.
     */
    private static function send(bool $success, string $message, mixed $data = null, int $status_code = 200): void
{
    http_response_code($status_code);

    // Always output valid JSON
    header('Content-Type: application/json; charset=utf-8');
    ob_clean();  // ← Add this line to clear any buffered output
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'status_code' => $status_code,
        'timestamp' => date('c') // ISO8601 for better logging
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit();
}
}