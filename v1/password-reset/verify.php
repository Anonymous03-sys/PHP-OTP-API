<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/challenge-engine.php';

otp_api_require_method('POST');

$payload = otp_api_read_json_body();
$challengeId = is_string($payload['challengeId'] ?? null)
    ? trim($payload['challengeId'])
    : '';
$otp = is_string($payload['otp'] ?? null)
    ? trim($payload['otp'])
    : '';

if (
    !preg_match('/^[A-Za-z0-9_-]{20,80}$/', $challengeId) ||
    !preg_match('/^\d{6}$/', $otp)
) {
    otp_api_json_response(400, [
        'success' => false,
        'code' => 'INVALID_VERIFICATION_REQUEST',
        'message' => 'Enter the six-digit verification code.',
    ]);
}

try {
    $verification = otp_api_verify_challenge_otp(
        $challengeId,
        $otp
    );
} catch (RuntimeException) {
    otp_api_json_response(503, [
        'success' => false,
        'code' => 'RECOVERY_SERVICE_UNAVAILABLE',
        'message' => 'Account recovery is temporarily unavailable.',
    ]);
}

if (!($verification['success'] ?? false)) {
    $response = [
        'success' => false,
        'code' => 'INVALID_OR_EXPIRED_CODE',
        'message' => 'The verification code is invalid, expired, or no longer available.',
    ];

    if (array_key_exists('attempts_remaining', $verification)) {
        $response['attemptsRemaining'] = (int) $verification['attempts_remaining'];
    }

    otp_api_json_response(400, $response);
}

otp_api_json_response(200, [
    'success' => true,
    'code' => 'OTP_VERIFIED',
    'message' => 'Verification successful. You can now create a new password.',
    'resetToken' => (string) ($verification['reset_token'] ?? ''),
    'expiresIn' => (int) (
        $verification['reset_token_expires_in'] ?? 600
    ),
]);
