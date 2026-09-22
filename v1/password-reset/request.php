<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

otp_api_require_method('POST');
otp_api_read_json_body();

otp_api_json_response(501, [
    'success' => false,
    'code' => 'RECOVERY_NOT_IMPLEMENTED',
    'message' => 'Password recovery is not active yet.',
]);
