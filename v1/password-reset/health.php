<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/config.php';
require_once dirname(__DIR__, 2) . '/src/response.php';

$config = otp_api_config();

otp_api_apply_cors(
    $config['allowed_origins'],
    ['GET', 'OPTIONS']
);

otp_api_require_method('GET');

otp_api_json_response(200, [
    'success' => true,
    'code' => 'RECOVERY_SERVICE_READY',
    'message' => 'Account recovery service is ready.',
    'service' => 'php-otp-api',
    'recoveryApi' => 'available',
]);
