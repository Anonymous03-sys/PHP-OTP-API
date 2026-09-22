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

    error_log('[OTP Recovery] ' . json_encode([
        'event' => 'account_resolution',
        'portal' => $portal,
        'resolved' => (bool) ($account['resolved'] ?? false),
        'eligible' => (bool) ($account['eligible'] ?? false),
        'reason' => (string) ($account['reason'] ?? 'UNKNOWN'),
        'recipient' => ($account['email'] ?? '') !== ''
            ? (string) $account['email']
            : null,
    ], JSON_UNESCAPED_SLASHES));

    $challenge = otp_api_issue_recovery_challenge(
        $account,
        $portal,
        $normalizedIdentifier
    );
} catch (Throwable $error) {
    error_log('[OTP Recovery] ' . json_encode([
        'event' => 'request_failure',
        'portal' => $portal,
        'error_type' => get_class($error),
    ], JSON_UNESCAPED_SLASHES));

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

error_log('[OTP Recovery] ' . json_encode([
    'event' => 'challenge_state',
    'portal' => $portal,
    'challenge' => $challengeId !== ''
        ? substr($challengeId, 0, 8)
        : null,
    'status' => (string) ($challenge['status'] ?? 'UNKNOWN'),
    'created' => $created,
    'should_deliver' => $shouldDeliver,
], JSON_UNESCAPED_SLASHES));

if (
    $created &&
    $shouldDeliver &&
    $challengeId !== '' &&
    $otp !== ''
) {
    $destinationEmail = (string) ($account['email'] ?? '');

    error_log('[OTP Recovery] ' . json_encode([
        'event' => 'delivery_attempt',
        'portal' => $portal,
        'challenge' => substr($challengeId, 0, 8),
        'recipient' => $destinationEmail,
    ], JSON_UNESCAPED_SLASHES));

    $delivery = otp_api_send_recovery_otp(
        $destinationEmail,
        $otp,
        (int) ($challenge['expires_in'] ?? 300)
    );

    error_log('[OTP Recovery] ' . json_encode([
        'event' => 'delivery_result',
        'portal' => $portal,
        'challenge' => substr($challengeId, 0, 8),
        'recipient' => $destinationEmail,
        'success' => (bool) ($delivery['success'] ?? false),
        'status_code' => (int) ($delivery['status_code'] ?? 0),
        'reason' => (string) ($delivery['reason'] ?? 'UNKNOWN'),
        'provider' => 'BREVO',
        'provider_message' => $delivery['provider_message'] ?? null,
        'provider_message_id' => $delivery['message_id'] ?? null,
    ], JSON_UNESCAPED_SLASHES));

    try {
        otp_api_update_challenge($challengeId, [
            'delivery_status' => $delivery['success']
                ? 'SENT'
                : 'FAILED',
            'delivery_provider' => 'BREVO',
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
    } catch (RuntimeException $error) {
        error_log('[OTP Recovery] ' . json_encode([
            'event' => 'delivery_state_persist_failure',
            'portal' => $portal,
            'challenge' => substr($challengeId, 0, 8),
            'error_type' => get_class($error),
        ], JSON_UNESCAPED_SLASHES));

        // The API response remains generic. Delivery-state persistence is
        // operational metadata and must never expose provider details.
    }
} else {
    error_log('[OTP Recovery] ' . json_encode([
        'event' => 'delivery_skipped',
        'portal' => $portal,
        'challenge' => $challengeId !== ''
            ? substr($challengeId, 0, 8)
            : null,
        'account_reason' => (string) ($account['reason'] ?? 'UNKNOWN'),
        'challenge_status' => (string) ($challenge['status'] ?? 'UNKNOWN'),
        'created' => $created,
        'should_deliver' => $shouldDeliver,
    ], JSON_UNESCAPED_SLASHES));
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
