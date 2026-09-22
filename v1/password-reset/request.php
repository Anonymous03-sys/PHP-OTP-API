<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/account-recovery.php';
require_once dirname(__DIR__, 2) . '/src/challenge-engine.php';

otp_api_require_method('POST');

$payload = otp_api_read_json_body();
$portal = is_string($payload['portal'] ?? null)
    ? trim($payload['portal'])
    : '';
$identifier = is_string($payload['identifier'] ?? null)
    ? trim($payload['identifier'])
    : '';

$portalConfig = otp_api_get_recovery_portal($portal);

if (
    $portal === '' ||
    strlen($portal) > 32 ||
    $identifier === '' ||
    strlen($identifier) > 128 ||
    $portalConfig === null
) {
    otp_api_json_response(400, [
        'success' => false,
        'code' => 'INVALID_RECOVERY_REQUEST',
        'message' => 'Enter a valid account identifier and recovery portal.',
    ]);
}

$normalizedIdentifier = otp_api_normalize_recovery_identifier(
    $portalConfig,
    $identifier
);

try {
    $account = otp_api_resolve_recovery_account(
        $portal,
        $normalizedIdentifier
    );

    $challenge = otp_api_issue_recovery_challenge(
        $account,
        $portal,
        $normalizedIdentifier
    );
} catch (RuntimeException) {
    otp_api_json_response(503, [
        'success' => false,
        'code' => 'RECOVERY_SERVICE_UNAVAILABLE',
        'message' => 'Account recovery is temporarily unavailable.',
    ]);
}

if (($challenge['status'] ?? '') === 'RATE_LIMITED') {
    otp_api_json_response(429, [
        'success' => false,
        'code' => 'RECOVERY_RATE_LIMITED',
        'message' => 'Too many recovery requests were made. Please wait before trying again.',
        'retryAfter' => (int) ($challenge['retry_after'] ?? 900),
    ]);
}

otp_api_json_response(202, [
    'success' => true,
    'code' => 'RECOVERY_REQUEST_ACCEPTED',
    'message' => 'If an eligible account is registered and has a recovery email, a verification code will be sent.',
    'challengeId' => (string) ($challenge['challenge_id'] ?? ''),
    'expiresIn' => (int) ($challenge['expires_in'] ?? 300),
    'resendAfter' => (int) ($challenge['resend_after'] ?? 60),
]);
