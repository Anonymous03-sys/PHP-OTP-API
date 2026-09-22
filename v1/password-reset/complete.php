<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/account-recovery.php';
require_once dirname(__DIR__, 2) . '/src/challenge-engine.php';
require_once dirname(__DIR__, 2) . '/src/recovery-mailer.php';

otp_api_require_method('POST');
otp_api_require_json_content_type();

$payload = otp_api_read_json_body();
$resetToken = is_string($payload['resetToken'] ?? null)
    ? trim($payload['resetToken'])
    : '';
$newPassword = is_string($payload['newPassword'] ?? null)
    ? $payload['newPassword']
    : '';

if (
    !preg_match('/^[A-Za-z0-9_-]{30,100}$/', $resetToken) ||
    $newPassword === '' ||
    strlen($newPassword) > 256
) {
    otp_api_json_response(400, [
        'success' => false,
        'code' => 'INVALID_RESET_REQUEST',
        'message' => 'Enter a valid new password and recovery authorization.',
    ]);
}

try {
    $authorization = otp_api_validate_reset_authorization(
        $resetToken
    );

    if (!($authorization['valid'] ?? false)) {
        otp_api_json_response(400, [
            'success' => false,
            'code' => 'INVALID_OR_EXPIRED_RESET_TOKEN',
            'message' => 'This password reset authorization is invalid, expired, or already used.',
        ]);
    }

    $challenge = $authorization['challenge'];
    $challengeId = otp_api_firestore_document_id($challenge);
    $portal = strtolower(trim((string) otp_api_challenge_field(
        $challenge,
        'portal',
        ''
    )));
    $accountDocumentId = trim((string) otp_api_challenge_field(
        $challenge,
        'account_document_id',
        ''
    ));
    $accountKey = trim((string) otp_api_challenge_field(
        $challenge,
        'account_key',
        ''
    ));

    $portalConfig = otp_api_get_recovery_portal($portal);

    if (
        $challengeId === '' ||
        $portalConfig === null ||
        $accountDocumentId === ''
    ) {
        otp_api_json_response(400, [
            'success' => false,
            'code' => 'INVALID_OR_EXPIRED_RESET_TOKEN',
            'message' => 'This password reset authorization is invalid, expired, or already used.',
        ]);
    }

    $passwordValidation = otp_api_validate_recovery_password(
        $portal,
        $newPassword
    );

    if (!($passwordValidation['valid'] ?? false)) {
        otp_api_json_response(400, [
            'success' => false,
            'code' => 'PASSWORD_REQUIREMENTS_NOT_MET',
            'message' => (string) (
                $passwordValidation['message']
                ?? 'The new password does not meet the required policy.'
            ),
        ]);
    }

    $account = otp_api_get_current_recovery_account(
        $portal,
        $accountDocumentId
    );

    if (
        $account === null ||
        !otp_api_is_current_recovery_account_eligible(
            $portal,
            $account
        )
    ) {
        otp_api_json_response(400, [
            'success' => false,
            'code' => 'ACCOUNT_NOT_ELIGIBLE',
            'message' => 'This account is not eligible for self-service password recovery.',
        ]);
    }

    $completedAt = time();
    $sessionInvalidationToken =
        'recovery-reset-' . otp_api_random_opaque_token(18);

    $commitUpdates = [
        [
            'document' => $account,
            'fields' => [
                $portalConfig['password_field'] => $newPassword,
                'currentSessionId' => $sessionInvalidationToken,
            ],
        ],
        [
            'document' => $challenge,
            'fields' => [
                'state' => 'COMPLETED',
                'otp_hash' => null,
                'reset_token_hash' => null,
                'reset_token_used' => true,
                'completed_at_epoch' => $completedAt,
            ],
        ],
    ];

    $commitUpdates = array_merge(
        $commitUpdates,
        otp_api_build_sibling_invalidation_updates(
            $accountKey,
            $challengeId,
            $completedAt
        )
    );

    // Password replacement, session invalidation, reset-token consumption, and
    // sibling recovery invalidation are one Firestore commit.
    otp_api_firestore_commit_document_updates($commitUpdates);

    $email = trim((string) otp_api_firestore_field(
        $account,
        $portalConfig['email_field'],
        ''
    ));

    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $notice = otp_api_send_password_changed_notice($email);

        try {
            otp_api_update_challenge($challengeId, [
                'password_change_notice_status' => $notice['success']
                    ? 'SENT'
                    : 'FAILED',
                'password_change_notice_status_code' => (int) (
                    $notice['status_code'] ?? 0
                ),
                'password_change_notice_updated_at_epoch' => time(),
            ]);
        } catch (RuntimeException) {
            // Notification metadata must not change reset success.
        }
    }
} catch (OtpApiFirestoreConflictException) {
    try {
        $recheck = otp_api_validate_reset_authorization($resetToken);

        if (!($recheck['valid'] ?? false)) {
            otp_api_json_response(400, [
                'success' => false,
                'code' => 'INVALID_OR_EXPIRED_RESET_TOKEN',
                'message' => 'This password reset authorization is invalid, expired, or already used.',
            ]);
        }
    } catch (Throwable) {
        // Fall through to the retry response below.
    }

    otp_api_json_response(409, [
        'success' => false,
        'code' => 'RECOVERY_RETRY_REQUIRED',
        'message' => 'The recovery state changed while your password was being updated. Please try again.',
    ]);
} catch (Throwable) {
    otp_api_json_response(503, [
        'success' => false,
        'code' => 'RECOVERY_SERVICE_UNAVAILABLE',
        'message' => 'Account recovery is temporarily unavailable.',
    ]);
}

otp_api_json_response(200, [
    'success' => true,
    'code' => 'PASSWORD_RESET_COMPLETED',
    'message' => 'Your password has been updated. Sign in using your new password.',
]);
