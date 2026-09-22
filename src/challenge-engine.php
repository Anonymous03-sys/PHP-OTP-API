<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/challenge-store.php';

function otp_api_random_opaque_token(int $bytes = 24): string
{
    return rtrim(
        strtr(base64_encode(random_bytes($bytes)), '+/', '-_'),
        '='
    );
}

function otp_api_recovery_hmac(string $purpose, string $value): string
{
    return hash_hmac(
        'sha256',
        $purpose . '|' . $value,
        otp_api_recovery_pepper()
    );
}

function otp_api_generate_otp(): string
{
    return str_pad(
        (string) random_int(0, 999999),
        6,
        '0',
        STR_PAD_LEFT
    );
}

function otp_api_hash_otp(string $challengeId, string $otp): string
{
    return otp_api_recovery_hmac(
        'otp',
        $challengeId . '|' . $otp
    );
}

function otp_api_account_key(string $portal, string $identifier): string
{
    return otp_api_recovery_hmac(
        'account',
        strtolower(trim($portal)) . '|' . trim($identifier)
    );
}

function otp_api_request_source_identity(): string
{
    $remoteAddress = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

    if (filter_var($remoteAddress, FILTER_VALIDATE_IP)) {
        return $remoteAddress;
    }

    $forwarded = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));

    if ($forwarded !== '') {
        $firstForwarded = trim(explode(',', $forwarded)[0] ?? '');

        if (filter_var($firstForwarded, FILTER_VALIDATE_IP)) {
            return $firstForwarded;
        }
    }

    return 'unknown-source';
}

function otp_api_source_key(): string
{
    return otp_api_recovery_hmac(
        'source',
        otp_api_request_source_identity()
    );
}

function otp_api_challenge_field(
    array $document,
    string $field,
    mixed $default = null
): mixed {
    return otp_api_firestore_field($document, $field, $default);
}

function otp_api_challenge_state(array $document): string
{
    return strtoupper(trim((string) otp_api_challenge_field(
        $document,
        'state',
        ''
    )));
}

function otp_api_recent_request_count(
    array $documents,
    int $windowStartEpoch
): int {
    $count = 0;

    foreach ($documents as $document) {
        $createdAt = (int) otp_api_challenge_field(
            $document,
            'created_at_epoch',
            0
        );

        if ($createdAt >= $windowStartEpoch) {
            $count++;
        }
    }

    return $count;
}

function otp_api_latest_pending_challenge(
    array $documents,
    int $now
): ?array {
    $latest = null;
    $latestCreatedAt = -1;

    foreach ($documents as $document) {
        if (otp_api_challenge_state($document) !== 'PENDING') {
            continue;
        }

        $expiresAt = (int) otp_api_challenge_field(
            $document,
            'expires_at_epoch',
            0
        );

        if ($expiresAt <= $now) {
            continue;
        }

        $createdAt = (int) otp_api_challenge_field(
            $document,
            'created_at_epoch',
            0
        );

        if ($createdAt > $latestCreatedAt) {
            $latest = $document;
            $latestCreatedAt = $createdAt;
        }
    }

    return $latest;
}

function otp_api_expire_stale_challenges(
    array $documents,
    int $now
): void {
    foreach ($documents as $document) {
        if (otp_api_challenge_state($document) !== 'PENDING') {
            continue;
        }

        $expiresAt = (int) otp_api_challenge_field(
            $document,
            'expires_at_epoch',
            0
        );

        if ($expiresAt > 0 && $expiresAt <= $now) {
            $documentId = otp_api_firestore_document_id($document);

            if ($documentId !== '') {
                otp_api_update_challenge($documentId, [
                    'state' => 'EXPIRED',
                    'expired_at_epoch' => $now,
                ]);
            }
        }
    }
}

function otp_api_supersede_pending_challenges(
    array $documents,
    int $now
): void {
    foreach ($documents as $document) {
        if (otp_api_challenge_state($document) !== 'PENDING') {
            continue;
        }

        $expiresAt = (int) otp_api_challenge_field(
            $document,
            'expires_at_epoch',
            0
        );

        if ($expiresAt <= $now) {
            continue;
        }

        $documentId = otp_api_firestore_document_id($document);

        if ($documentId !== '') {
            otp_api_update_challenge($documentId, [
                'state' => 'SUPERSEDED',
                'superseded_at_epoch' => $now,
            ]);
        }
    }
}

function otp_api_issue_recovery_challenge(
    array $accountResolution,
    string $portal,
    string $normalizedIdentifier
): array {
    $config = otp_api_config();
    $now = time();

    $accountKey = otp_api_account_key($portal, $normalizedIdentifier);
    $sourceKey = otp_api_source_key();

    $accountHistory = otp_api_find_account_challenges($accountKey);
    $sourceHistory = otp_api_find_source_challenges($sourceKey);

    otp_api_expire_stale_challenges($accountHistory, $now);

    $accountWindowStart = $now
        - (int) $config['account_rate_window_seconds'];
    $sourceWindowStart = $now
        - (int) $config['source_rate_window_seconds'];

    if (
        otp_api_recent_request_count(
            $accountHistory,
            $accountWindowStart
        ) >= (int) $config['account_rate_max_requests']
    ) {
        return [
            'status' => 'RATE_LIMITED',
            'retry_after' => (int) $config['account_rate_window_seconds'],
        ];
    }

    if (
        otp_api_recent_request_count(
            $sourceHistory,
            $sourceWindowStart
        ) >= (int) $config['source_rate_max_requests']
    ) {
        return [
            'status' => 'RATE_LIMITED',
            'retry_after' => (int) $config['source_rate_window_seconds'],
        ];
    }

    $activeChallenge = otp_api_latest_pending_challenge(
        $accountHistory,
        $now
    );

    if ($activeChallenge !== null) {
        $resendAfterEpoch = (int) otp_api_challenge_field(
            $activeChallenge,
            'resend_after_epoch',
            0
        );

        if ($resendAfterEpoch > $now) {
            $challengeId = otp_api_firestore_document_id($activeChallenge);
            $expiresAt = (int) otp_api_challenge_field(
                $activeChallenge,
                'expires_at_epoch',
                $now
            );

            return [
                'status' => 'COOLDOWN',
                'challenge_id' => $challengeId,
                'otp' => null,
                'created' => false,
                'should_deliver' => false,
                'masked_email' => (string) otp_api_challenge_field(
                    $activeChallenge,
                    'masked_email',
                    ''
                ),
                'expires_in' => max(0, $expiresAt - $now),
                'resend_after' => max(1, $resendAfterEpoch - $now),
            ];
        }
    }

    otp_api_supersede_pending_challenges($accountHistory, $now);

    $challengeId = otp_api_random_opaque_token(24);
    $otp = otp_api_generate_otp();
    $expiresAt = $now + (int) $config['otp_ttl_seconds'];
    $resendAfter = $now + (int) $config['resend_cooldown_seconds'];

    $isEligible = (bool) ($accountResolution['eligible'] ?? false);
    $documentId = $isEligible
        ? (string) ($accountResolution['account_document_id'] ?? '')
        : '';
    $maskedEmail = $isEligible
        ? (string) ($accountResolution['masked_email'] ?? '')
        : '';

    otp_api_create_challenge_document($challengeId, [
        'portal' => strtolower(trim($portal)),
        'account_key' => $accountKey,
        'source_key' => $sourceKey,
        'account_document_id' => $documentId,
        'eligible' => $isEligible,
        'delivery_ready' => $isEligible,
        'delivery_status' => $isEligible ? 'PENDING' : 'NOT_APPLICABLE',
        'delivery_provider' => null,
        'delivery_status_code' => 0,
        'delivery_updated_at_epoch' => null,
        'delivered_at_epoch' => null,
        'masked_email' => $maskedEmail,
        'otp_hash' => otp_api_hash_otp($challengeId, $otp),
        'created_at_epoch' => $now,
        'expires_at_epoch' => $expiresAt,
        'resend_after_epoch' => $resendAfter,
        'attempts_used' => 0,
        'max_attempts' => (int) $config['max_otp_attempts'],
        'state' => 'PENDING',
        'verified_at_epoch' => null,
        'completed_at_epoch' => null,
    ]);

    return [
        'status' => 'CREATED',
        'challenge_id' => $challengeId,
        'otp' => $otp,
        'created' => true,
        'should_deliver' => $isEligible,
        'masked_email' => $maskedEmail,
        'expires_in' => (int) $config['otp_ttl_seconds'],
        'resend_after' => (int) $config['resend_cooldown_seconds'],
    ];
}

function otp_api_verify_challenge_otp(
    string $challengeId,
    string $enteredOtp
): array {
    $challengeId = trim($challengeId);
    $enteredOtp = trim($enteredOtp);

    if (
        $challengeId === '' ||
        !preg_match('/^[A-Za-z0-9_-]{20,80}$/', $challengeId) ||
        !preg_match('/^\d{6}$/', $enteredOtp)
    ) {
        return [
            'success' => false,
            'code' => 'INVALID_OR_EXPIRED_CODE',
        ];
    }

    $challenge = otp_api_get_challenge($challengeId);

    if ($challenge === null) {
        return [
            'success' => false,
            'code' => 'INVALID_OR_EXPIRED_CODE',
        ];
    }

    $now = time();
    $state = otp_api_challenge_state($challenge);

    if ($state !== 'PENDING') {
        return [
            'success' => false,
            'code' => 'INVALID_OR_EXPIRED_CODE',
        ];
    }

    $expiresAt = (int) otp_api_challenge_field(
        $challenge,
        'expires_at_epoch',
        0
    );

    if ($expiresAt <= $now) {
        otp_api_update_challenge($challengeId, [
            'state' => 'EXPIRED',
            'expired_at_epoch' => $now,
        ]);

        return [
            'success' => false,
            'code' => 'INVALID_OR_EXPIRED_CODE',
        ];
    }

    $attemptsUsed = (int) otp_api_challenge_field(
        $challenge,
        'attempts_used',
        0
    );
    $maxAttempts = (int) otp_api_challenge_field(
        $challenge,
        'max_attempts',
        (int) otp_api_config()['max_otp_attempts']
    );

    if ($attemptsUsed >= $maxAttempts) {
        otp_api_update_challenge($challengeId, [
            'state' => 'LOCKED',
            'locked_at_epoch' => $now,
        ]);

        return [
            'success' => false,
            'code' => 'INVALID_OR_EXPIRED_CODE',
        ];
    }

    $expectedHash = (string) otp_api_challenge_field(
        $challenge,
        'otp_hash',
        ''
    );
    $actualHash = otp_api_hash_otp($challengeId, $enteredOtp);

    if ($expectedHash === '' || !hash_equals($expectedHash, $actualHash)) {
        $attemptsUsed++;
        $updates = [
            'attempts_used' => $attemptsUsed,
            'last_attempt_at_epoch' => $now,
        ];

        if ($attemptsUsed >= $maxAttempts) {
            $updates['state'] = 'LOCKED';
            $updates['locked_at_epoch'] = $now;
        }

        otp_api_update_challenge($challengeId, $updates);

        return [
            'success' => false,
            'code' => 'INVALID_OR_EXPIRED_CODE',
            'attempts_remaining' => max(0, $maxAttempts - $attemptsUsed),
        ];
    }

    if (!(bool) otp_api_challenge_field($challenge, 'eligible', false)) {
        otp_api_update_challenge($challengeId, [
            'state' => 'CONSUMED',
            'verified_at_epoch' => $now,
        ]);

        return [
            'success' => false,
            'code' => 'INVALID_OR_EXPIRED_CODE',
        ];
    }

    otp_api_update_challenge($challengeId, [
        'state' => 'VERIFIED',
        'verified_at_epoch' => $now,
        'attempts_used' => $attemptsUsed + 1,
    ]);

    return [
        'success' => true,
        'code' => 'OTP_VERIFIED',
        'challenge_id' => $challengeId,
        'account_document_id' => (string) otp_api_challenge_field(
            $challenge,
            'account_document_id',
            ''
        ),
        'portal' => (string) otp_api_challenge_field(
            $challenge,
            'portal',
            ''
        ),
    ];
}
