<?php

declare(strict_types=1);

require_once __DIR__ . '/src/response.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    otp_api_json_response(405, [
        'success' => false,
        'code' => 'METHOD_NOT_ALLOWED',
        'message' => 'This endpoint only supports GET.',
    ]);
}

otp_api_json_response(200, [
    'success' => true,
    'service' => 'php-otp-api',
    'legacyOtp' => 'available',
    'recoveryApi' => 'scaffold',
]);
