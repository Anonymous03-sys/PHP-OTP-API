<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/account-recovery.php';
require_once dirname(__DIR__, 2) . '/src/challenge-engine.php';
require_once dirname(__DIR__, 2) . '/src/recovery-mailer.php';

$requestStartedAt = microtime(true);

otp_api_require_method('POST');
otp_api_require_json_content_type();

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
} catch (Throwable) {
    otp_api_json_response(503, [
        'success' => false,
        'code' => 'RECOVERY_SERVICE_UNAVAILABLE',
        'message' => 'Account recovery is temporarily unavailable.',
    ]);
}

if (($challenge['status'] ?? '') === 'RATE_LIMITED') {
    $retryAfter = (int) ($challenge['retry_after'] ?? 900);
    header('Retry-After: ' . max(1, $retryAfter));

    otp_api_json_response(429, [
        'success' => false,
        'code' => 'RECOVERY_RATE_LIMITED',
        'message' => 'Too many recovery requests were made. Please wait before trying again.',
        'retryAfter' => $retryAfter,
    ]);
}

$challengeId = (string) ($challenge['challenge_id'] ?? '');
$shouldDeliver = (bool) ($challenge['should_deliver'] ?? false);
$created = (bool) ($challenge['created'] ?? false);
$otp = is_string($challenge['otp'] ?? null)
    ? $challenge['otp']
    : '';

if (
    $created &&
    $shouldDeliver &&
    $challengeId !== '' &&
    $otp !== ''
) {
    $delivery = otp_api_send_recovery_otp(
        (string) ($account['email'] ?? ''),
        $otp,
        (int) ($challenge['expires_in'] ?? 300)
    );

    try {
        otp_api_update_challenge($challengeId, [
            'delivery_status' => $delivery['success']
                ? 'SENT'
                : 'FAILED',
            'delivery_provider' => 'SENDGRID',
            'delivery_status_code' => (int) (
                $delivery['status_code'] ?? 0
            ),
            'delivery_reason' => (string) (
                $delivery['reason'] ?? 'UNKNOWN'
            ),
            'delivery_ready' => (bool) ($delivery['success'] ?? false),
            'delivery_updated_at_epoch' => time(),
            'delivered_at_epoch' => $delivery['success']
                ? time()
                : null,
        ]);
    } catch (RuntimeException) {
        // The API response remains generic. Delivery-state persistence is
        // operational metadata and must never expose provider details.
    }
}

otp_api_pad_response_time($requestStartedAt);

otp_api_json_response(202, [
    'success' => true,
    'code' => 'RECOVERY_REQUEST_ACCEPTED',
    'message' => 'If an eligible account is registered and has a recovery email, a verification code will be sent.',
    'challengeId' => $challengeId,
    'expiresIn' => (int) ($challenge['expires_in'] ?? 300),
    'resendAfter' => (int) ($challenge['resend_after'] ?? 60),
]);
