<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/account-recovery.php';

otp_api_require_method('POST');

$payload = otp_api_read_json_body();
$portal = is_string($payload['portal'] ?? null)
    ? trim($payload['portal'])
    : '';
$identifier = is_string($payload['identifier'] ?? null)
    ? trim($payload['identifier'])
    : '';

if (
    $portal === '' ||
    strlen($portal) > 32 ||
    $identifier === '' ||
    strlen($identifier) > 128 ||
    otp_api_get_recovery_portal($portal) === null
) {
    otp_api_json_response(400, [
        'success' => false,
        'code' => 'INVALID_RECOVERY_REQUEST',
        'message' => 'Enter a valid account identifier and recovery portal.',
    ]);
}

try {
    // OTP-2 deliberately resolves the account only. Later stages will use this
    // internal result to create and deliver an OTP challenge. The public
    // response stays generic so this endpoint does not become an account
    // directory.
    otp_api_resolve_recovery_account($portal, $identifier);
} catch (RuntimeException) {
    otp_api_json_response(503, [
        'success' => false,
        'code' => 'RECOVERY_SERVICE_UNAVAILABLE',
        'message' => 'Account recovery is temporarily unavailable.',
    ]);
}

otp_api_json_response(202, [
    'success' => true,
    'code' => 'RECOVERY_REQUEST_ACCEPTED',
    'message' => 'If an eligible account is registered and has a recovery email, a verification code will be sent.',
]);
