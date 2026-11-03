<?php
declare(strict_types=1);

function rejectDeprecatedLegacyEndpoint(string $replacement): void
{
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json');
    http_response_code(410);
    echo json_encode([
        'success' => false,
        'message' => 'This legacy endpoint has been retired. Use the controlled legacy debt workflow.',
        'replacement' => $replacement
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
