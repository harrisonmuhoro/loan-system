<?php
/**
 * API Front Controller
 * Routes requests to corresponding endpoint files within /api/
 */

header('Content-Type: application/json');

// Define base project path
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__) . '/');
}

// Load configuration and response helper
require_once BASE_PATH . '/loanee_config/config.php';
require_once BASE_PATH . '/loanee_helpers/response_helper.php';

try {
    // Parse request URI
    $request_uri = strtok($_SERVER['REQUEST_URI'], '?'); // strip query params
    $request_parts = explode('/', trim($request_uri, '/'));
    $api_index = array_search('api', $request_parts);

    // Validate URI
    if ($api_index === false) {
        ResponseHelper::error('Invalid API request path', 400);
    }

    // Extract endpoint (e.g., "settings" from /api/settings)
    $endpoint = $request_parts[$api_index + 1] ?? null;
    if (!$endpoint) {
        ResponseHelper::error('No endpoint specified', 400);
    }

    // Build full path to the endpoint file
    $endpoint_file = __DIR__ . '/' . basename($endpoint) . '.php';

    // Load endpoint or respond with 404
    if (file_exists($endpoint_file)) {
        require_once $endpoint_file;
    } else {
        ResponseHelper::notFound("Endpoint '{$endpoint}' not found");
    }
} catch (Throwable $e) {
    // Catch unexpected errors gracefully
    ResponseHelper::serverError('Internal error: ' . $e->getMessage());
}