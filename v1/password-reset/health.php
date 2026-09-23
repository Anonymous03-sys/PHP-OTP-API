<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/config.php';
require_once dirname(__DIR__, 2) . '/src/response.php';
require_once dirname(__DIR__, 2) . '/src/firebase.php';
require_once dirname(__DIR__, 2) . '/src/recovery-mailer.php';

$config = otp_api_config();

otp_api_apply_cors(
    $config['allowed_origins'],
    ['GET', 'OPTIONS']
);

otp_api_require_method('GET');

try {
    otp_api_recovery_pepper();
    otp_api_load_firebase_service_account();
    otp_api_recovery_sender();

    if (trim((string) (getenv('BREVO_API_KEY') ?: '')) === '') {
        throw new RuntimeException(
            'Brevo API key configuration is missing.'
        );
    }
} catch (Throwable $error) {
    error_log('[OTP Recovery Health] configuration unavailable: '
        . get_class($error)
        . ' - '
        . $error->getMessage());

    otp_api_json_response(503, [
        'success' => false,
        'code' => 'RECOVERY_CONFIGURATION_INCOMPLETE',
        'message' => 'Account recovery is online but its production configuration is incomplete.',
        'service' => 'php-otp-api',
        'recoveryApi' => 'unavailable',
    ]);
}

otp_api_json_response(200, [
    'success' => true,
    'code' => 'RECOVERY_SERVICE_READY',
    'message' => 'Account recovery service is ready.',
    'service' => 'php-otp-api',
    'recoveryApi' => 'available',
]);
