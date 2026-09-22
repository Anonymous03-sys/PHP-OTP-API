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

function otp_api_hash_reset_token(string $resetToken): string
{
    return otp_api_recovery_hmac(
        'reset-token',
        $resetToken
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
    $remoteValid = filter_var(
        $remoteAddress,
        FILTER_VALIDATE_IP
    ) !== false;

    $trustProxyHeaders = (bool) (
        otp_api_config()['trusted_proxy_headers'] ?? false
    );

    if ($trustProxyHeaders) {
        $forwarded = trim((string) (
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''
        ));

        if ($forwarded !== '') {
            $firstForwarded = trim(
                explode(',', $forwarded)[0] ?? ''
            );

            if (filter_var($firstForwarded, FILTER_VALIDATE_IP)) {
                return ($remoteValid ? $remoteAddress : 'proxy')
                    . '|'
                    . $firstForwarded;
            }
        }
    }

    if ($remoteValid) {
        return $remoteAddress;
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

function otp_api_cleanup_stale_challenges(
    array $documents,
    int $now
): void {
    foreach ($documents as $document) {
        $state = otp_api_challenge_state($document);
        $updates = [];

        if ($state === 'PENDING') {
            $expiresAt = (int) otp_api_challenge_field(
                $document,
                'expires_at_epoch',
                0
            );

            if ($expiresAt > 0 && $expiresAt <= $now) {
                $updates = [
                    'state' => 'EXPIRED',
                    'otp_hash' => null,
                    'reset_token_hash' => null,
                    'reset_token_used' => true,
                    'delivery_ready' => false,
                    'expired_at_epoch' => $now,
                ];
            }
        } elseif ($state === 'VERIFIED') {
            $tokenExpiresAt = (int) otp_api_challenge_field(
                $document,
                'reset_token_expires_at_epoch',
                0
            );

            if ($tokenExpiresAt > 0 && $tokenExpiresAt <= $now) {
                $updates = [
                    'state' => 'EXPIRED',
                    'otp_hash' => null,
                    'reset_token_hash' => null,
                    'reset_token_used' => true,
                    'expired_at_epoch' => $now,
                ];
            }
        } elseif (in_array(
            $state,
            ['EXPIRED', 'LOCKED', 'SUPERSEDED', 'CONSUMED', 'COMPLETED'],
            true
        )) {
            $hasOtpHash = (string) otp_api_challenge_field(
                $document,
                'otp_hash',
                ''
            ) !== '';
            $hasResetHash = (string) otp_api_challenge_field(
                $document,
                'reset_token_hash',
                ''
            ) !== '';

            if ($hasOtpHash || $hasResetHash) {
                $updates = [
                    'otp_hash' => null,
                    'reset_token_hash' => null,
                    'reset_token_used' => true,
                ];
            }
        }

        if ($updates === []) {
            continue;
        }

        try {
            otp_api_firestore_commit_document_updates([
                [
                    'document' => $document,
                    'fields' => $updates,
                ],
            ]);
        } catch (OtpApiFirestoreConflictException) {
            // A newer request already moved this challenge forward.
        }
    }
}

function otp_api_retry_after_for_limit(
    array $documents,
    int $windowSeconds,
    int $maxRequests,
    int $now
): int {
    $windowStart = $now - $windowSeconds;
    $timestamps = [];

    foreach ($documents as $document) {
        $createdAt = (int) otp_api_challenge_field(
            $document,
            'created_at_epoch',
            0
        );

        if ($createdAt >= $windowStart) {
            $timestamps[] = $createdAt;
        }
    }

    if (count($timestamps) < $maxRequests) {
        return 0;
    }

    sort($timestamps, SORT_NUMERIC);
    $index = max(0, count($timestamps) - $maxRequests);
    $releaseAt = $timestamps[$index] + $windowSeconds;

    return max(1, $releaseAt - $now);
}

function otp_api_cooldown_from_lock(
    ?array $lock,
    int $now
): ?array {
    if ($lock === null) {
        return null;
    }

    $challengeId = trim((string) otp_api_firestore_field(
        $lock,
        'active_challenge_id',
        ''
    ));

    if ($challengeId === '') {
        return null;
    }

    $challenge = otp_api_get_challenge($challengeId);

    if (
        $challenge === null ||
        otp_api_challenge_state($challenge) !== 'PENDING'
    ) {
        return null;
    }

    $expiresAt = (int) otp_api_challenge_field(
        $challenge,
        'expires_at_epoch',
        0
    );
    $resendAfterEpoch = (int) otp_api_challenge_field(
        $challenge,
        'resend_after_epoch',
        0
    );

    if ($expiresAt <= $now || $resendAfterEpoch <= $now) {
        return null;
    }

    return [
        'status' => 'COOLDOWN',
        'challenge_id' => $challengeId,
        'otp' => null,
        'created' => false,
        'should_deliver' => false,
        'masked_email' => (string) otp_api_challenge_field(
            $challenge,
            'masked_email',
            ''
        ),
        'expires_in' => max(0, $expiresAt - $now),
        'resend_after' => max(1, $resendAfterEpoch - $now),
    ];
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

    otp_api_cleanup_stale_challenges($accountHistory, $now);

    $accountRetryAfter = otp_api_retry_after_for_limit(
        $accountHistory,
        (int) $config['account_rate_window_seconds'],
        (int) $config['account_rate_max_requests'],
        $now
    );

    if ($accountRetryAfter > 0) {
        return [
            'status' => 'RATE_LIMITED',
            'retry_after' => $accountRetryAfter,
        ];
    }

    $sourceRetryAfter = otp_api_retry_after_for_limit(
        $sourceHistory,
        (int) $config['source_rate_window_seconds'],
        (int) $config['source_rate_max_requests'],
        $now
    );

    if ($sourceRetryAfter > 0) {
        return [
            'status' => 'RATE_LIMITED',
            'retry_after' => $sourceRetryAfter,
        ];
    }

    for ($attempt = 0; $attempt < 3; $attempt++) {
        $lock = otp_api_get_challenge_lock($accountKey);
        $cooldown = otp_api_cooldown_from_lock($lock, $now);

        if ($cooldown !== null) {
            return $cooldown;
        }

        $currentHistory = otp_api_find_account_challenges($accountKey);
        otp_api_cleanup_stale_challenges($currentHistory, $now);

        $challengeId = otp_api_random_opaque_token(24);
        $otp = otp_api_generate_otp();
        $expiresAt = $now + (int) $config['otp_ttl_seconds'];
        $resendAfter = $now
            + (int) $config['resend_cooldown_seconds'];

        $isEligible = (bool) ($accountResolution['eligible'] ?? false);
        $documentId = $isEligible
            ? (string) (
                $accountResolution['account_document_id'] ?? ''
            )
            : '';
        $maskedEmail = $isEligible
            ? (string) ($accountResolution['masked_email'] ?? '')
            : '';

        $challengeFields = [
            'portal' => strtolower(trim($portal)),
            'account_key' => $accountKey,
            'source_key' => $sourceKey,
            'account_document_id' => $documentId,
            'eligible' => $isEligible,
            'delivery_ready' => $isEligible,
            'delivery_status' => $isEligible
                ? 'PENDING'
                : 'NOT_APPLICABLE',
            'delivery_provider' => null,
            'delivery_status_code' => 0,
            'delivery_reason' => null,
            'delivery_updated_at_epoch' => null,
            'delivered_at_epoch' => null,
            'masked_email' => $maskedEmail,
            'otp_hash' => otp_api_hash_otp($challengeId, $otp),
            'created_at_epoch' => $now,
            'expires_at_epoch' => $expiresAt,
            'resend_after_epoch' => $resendAfter,
            'cleanup_after_epoch' => $now
                + (int) $config['challenge_retention_seconds'],
            'attempts_used' => 0,
            'max_attempts' => (int) $config['max_otp_attempts'],
            'state' => 'PENDING',
            'verified_at_epoch' => null,
            'reset_token_hash' => null,
            'reset_token_issued_at_epoch' => null,
            'reset_token_expires_at_epoch' => null,
            'reset_token_used' => false,
            'completed_at_epoch' => null,
        ];

        $writes = [
            otp_api_firestore_build_conditional_set_write(
                otp_api_challenge_collection(),
                $challengeId,
                $challengeFields,
                null
            ),
            otp_api_firestore_build_conditional_set_write(
                otp_api_challenge_lock_collection(),
                $accountKey,
                [
                    'active_challenge_id' => $challengeId,
                    'resend_after_epoch' => $resendAfter,
                    'expires_at_epoch' => $expiresAt,
                    'updated_at_epoch' => $now,
                ],
                $lock
            ),
        ];

        foreach ($currentHistory as $existingChallenge) {
            if (otp_api_challenge_state($existingChallenge) !== 'PENDING') {
                continue;
            }

            $existingId = otp_api_firestore_document_id(
                $existingChallenge
            );

            if ($existingId === '') {
                continue;
            }

            $existingExpiresAt = (int) otp_api_challenge_field(
                $existingChallenge,
                'expires_at_epoch',
                0
            );

            if ($existingExpiresAt <= $now) {
                continue;
            }

            $writes[] = otp_api_firestore_build_update_write(
                $existingChallenge,
                [
                    'state' => 'SUPERSEDED',
                    'otp_hash' => null,
                    'reset_token_hash' => null,
                    'reset_token_used' => true,
                    'delivery_ready' => false,
                    'superseded_at_epoch' => $now,
                ]
            );
        }

        try {
            otp_api_firestore_commit_writes($writes);

            return [
                'status' => 'CREATED',
                'challenge_id' => $challengeId,
                'otp' => $otp,
                'created' => true,
                'should_deliver' => $isEligible,
                'masked_email' => $maskedEmail,
                'expires_in' => (int) $config['otp_ttl_seconds'],
                'resend_after' => (int) (
                    $config['resend_cooldown_seconds']
                ),
            ];
        } catch (OtpApiFirestoreConflictException) {
            $now = time();
        }
    }

    $cooldown = otp_api_cooldown_from_lock(
        otp_api_get_challenge_lock($accountKey),
        time()
    );

    if ($cooldown !== null) {
        return $cooldown;
    }

    throw new RuntimeException(
        'Recovery challenge issuance could not be serialized.'
    );
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

    for ($retry = 0; $retry < 3; $retry++) {
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
            try {
                otp_api_firestore_commit_document_updates([
                    [
                        'document' => $challenge,
                        'fields' => [
                            'state' => 'EXPIRED',
                            'otp_hash' => null,
                            'reset_token_hash' => null,
                            'reset_token_used' => true,
                            'delivery_ready' => false,
                            'expired_at_epoch' => $now,
                        ],
                    ],
                ]);
            } catch (OtpApiFirestoreConflictException) {
                continue;
            }

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
            try {
                otp_api_firestore_commit_document_updates([
                    [
                        'document' => $challenge,
                        'fields' => [
                            'state' => 'LOCKED',
                            'otp_hash' => null,
                            'reset_token_hash' => null,
                            'reset_token_used' => true,
                            'locked_at_epoch' => $now,
                        ],
                    ],
                ]);
            } catch (OtpApiFirestoreConflictException) {
                continue;
            }

            return [
                'success' => false,
                'code' => 'INVALID_OR_EXPIRED_CODE',
                'attempts_remaining' => 0,
            ];
        }

        $expectedHash = (string) otp_api_challenge_field(
            $challenge,
            'otp_hash',
            ''
        );
        $actualHash = otp_api_hash_otp($challengeId, $enteredOtp);

        if (
            $expectedHash === '' ||
            !hash_equals($expectedHash, $actualHash)
        ) {
            $nextAttempts = $attemptsUsed + 1;
            $updates = [
                'attempts_used' => $nextAttempts,
                'last_attempt_at_epoch' => $now,
            ];

            if ($nextAttempts >= $maxAttempts) {
                $updates['state'] = 'LOCKED';
                $updates['otp_hash'] = null;
                $updates['reset_token_hash'] = null;
                $updates['reset_token_used'] = true;
                $updates['locked_at_epoch'] = $now;
            }

            try {
                otp_api_firestore_commit_document_updates([
                    [
                        'document' => $challenge,
                        'fields' => $updates,
                    ],
                ]);
            } catch (OtpApiFirestoreConflictException) {
                continue;
            }

            return [
                'success' => false,
                'code' => 'INVALID_OR_EXPIRED_CODE',
                'attempts_remaining' => max(
                    0,
                    $maxAttempts - $nextAttempts
                ),
            ];
        }

        if (!(bool) otp_api_challenge_field(
            $challenge,
            'eligible',
            false
        )) {
            try {
                otp_api_firestore_commit_document_updates([
                    [
                        'document' => $challenge,
                        'fields' => [
                            'state' => 'CONSUMED',
                            'verified_at_epoch' => $now,
                            'otp_hash' => null,
                            'reset_token_hash' => null,
                            'reset_token_used' => true,
                        ],
                    ],
                ]);
            } catch (OtpApiFirestoreConflictException) {
                continue;
            }

            return [
                'success' => false,
                'code' => 'INVALID_OR_EXPIRED_CODE',
            ];
        }

        $resetToken = otp_api_random_opaque_token(32);
        $resetTokenTtl = (int) (
            otp_api_config()['reset_token_ttl_seconds']
        );
        $resetTokenExpiresAt = $now + $resetTokenTtl;

        try {
            otp_api_firestore_commit_document_updates([
                [
                    'document' => $challenge,
                    'fields' => [
                        'state' => 'VERIFIED',
                        'verified_at_epoch' => $now,
                        'attempts_used' => $attemptsUsed + 1,
                        'otp_hash' => null,
                        'reset_token_hash' => otp_api_hash_reset_token(
                            $resetToken
                        ),
                        'reset_token_issued_at_epoch' => $now,
                        'reset_token_expires_at_epoch' => (
                            $resetTokenExpiresAt
                        ),
                        'reset_token_used' => false,
                    ],
                ],
            ]);
        } catch (OtpApiFirestoreConflictException) {
            continue;
        }

        return [
            'success' => true,
            'code' => 'OTP_VERIFIED',
            'challenge_id' => $challengeId,
            'reset_token' => $resetToken,
            'reset_token_expires_in' => $resetTokenTtl,
        ];
    }

    throw new RuntimeException(
        'Recovery verification state changed repeatedly.'
    );
}

function otp_api_find_verified_challenge_by_reset_token(
    string $resetToken
): ?array {
    $resetToken = trim($resetToken);

    if (!preg_match('/^[A-Za-z0-9_-]{30,100}$/', $resetToken)) {
        return null;
    }

    $resetTokenHash = otp_api_hash_reset_token($resetToken);
    $matches = otp_api_find_challenges_by_key(
        'reset_token_hash',
        $resetTokenHash
    );

    if (count($matches) !== 1) {
        return null;
    }

    $challenge = $matches[0];
    $storedHash = (string) otp_api_challenge_field(
        $challenge,
        'reset_token_hash',
        ''
    );

    if (
        $storedHash === '' ||
        !hash_equals($storedHash, $resetTokenHash)
    ) {
        return null;
    }

    return $challenge;
}

function otp_api_validate_reset_authorization(
    string $resetToken
): array {
    $challenge = otp_api_find_verified_challenge_by_reset_token(
        $resetToken
    );

    if ($challenge === null) {
        return [
            'valid' => false,
            'code' => 'INVALID_OR_EXPIRED_RESET_TOKEN',
        ];
    }

    if (otp_api_challenge_state($challenge) !== 'VERIFIED') {
        return [
            'valid' => false,
            'code' => 'INVALID_OR_EXPIRED_RESET_TOKEN',
        ];
    }

    if (!(bool) otp_api_challenge_field($challenge, 'eligible', false)) {
        return [
            'valid' => false,
            'code' => 'INVALID_OR_EXPIRED_RESET_TOKEN',
        ];
    }

    if ((bool) otp_api_challenge_field(
        $challenge,
        'reset_token_used',
        false
    )) {
        return [
            'valid' => false,
            'code' => 'INVALID_OR_EXPIRED_RESET_TOKEN',
        ];
    }

    $expiresAt = (int) otp_api_challenge_field(
        $challenge,
        'reset_token_expires_at_epoch',
        0
    );

    if ($expiresAt <= time()) {
        $challengeId = otp_api_firestore_document_id($challenge);

        if ($challengeId !== '') {
            try {
                otp_api_firestore_commit_document_updates([
                    [
                        'document' => $challenge,
                        'fields' => [
                            'state' => 'EXPIRED',
                            'otp_hash' => null,
                            'reset_token_hash' => null,
                            'reset_token_used' => true,
                            'expired_at_epoch' => time(),
                        ],
                    ],
                ]);
            } catch (OtpApiFirestoreConflictException) {
                // A concurrent completion or cleanup already changed state.
            }
        }

        return [
            'valid' => false,
            'code' => 'INVALID_OR_EXPIRED_RESET_TOKEN',
        ];
    }

    return [
        'valid' => true,
        'challenge' => $challenge,
    ];
}

function otp_api_build_sibling_invalidation_updates(
    string $accountKey,
    string $completedChallengeId,
    int $completedAt
): array {
    if ($accountKey === '') {
        return [];
    }

    $updates = [];
    $siblings = otp_api_find_account_challenges($accountKey);

    foreach ($siblings as $sibling) {
        $siblingId = otp_api_firestore_document_id($sibling);

        if (
            $siblingId === '' ||
            $siblingId === $completedChallengeId
        ) {
            continue;
        }

        $state = otp_api_challenge_state($sibling);

        if (!in_array($state, ['PENDING', 'VERIFIED'], true)) {
            continue;
        }

        $updates[] = [
            'document' => $sibling,
            'fields' => [
                'state' => 'SUPERSEDED',
                'otp_hash' => null,
                'reset_token_hash' => null,
                'reset_token_used' => true,
                'superseded_at_epoch' => $completedAt,
            ],
        ];
    }

    return $updates;
}
