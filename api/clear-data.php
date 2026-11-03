<?php
// api/clear-data.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

/**
 * Bulk data deletion has been permanently retired and disabled.
 * Financial records, user accounts, and loan records are immutable and protected
 * by database constraints and audit triggers.
 *
 * This endpoint returns HTTP 410 Gone for all request methods and payloads.
 */
http_response_code(410);
echo json_encode([
    'success' => false,
    'message' => 'Bulk data deletion is disabled'
], JSON_UNESCAPED_UNICODE);
exit;
