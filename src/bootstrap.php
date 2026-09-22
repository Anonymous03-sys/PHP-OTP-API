<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/response.php';

$config = otp_api_config();

otp_api_apply_cors(
    $config['allowed_origins'],
    ['POST', 'OPTIONS']
);
