<?php

declare(strict_types=1);

/**
 * Configuration helpers for the versioned Senior E-Services recovery API.
 *
 * Legacy index.php and verify.php intentionally do not load this file so their
 * existing behavior remains unchanged.
 */
function otp_api_config(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $allowedOriginsValue = getenv('OTP_RECOVERY_ALLOWED_ORIGINS') ?: '';
    $allowedOrigins = array_values(array_filter(array_map(
        static fn (string $origin): string => trim($origin),
        explode(',', $allowedOriginsValue)
    )));

    $config = [
        'service' => 'php-otp-api',
        'api_version' => 'v1',
        'allowed_origins' => $allowedOrigins,
        'challenge_collection' => 'password_reset_challenges',
        'otp_ttl_seconds' => 300,
        'resend_cooldown_seconds' => 60,
        'max_otp_attempts' => 5,
        'account_rate_window_seconds' => 900,
        'account_rate_max_requests' => 5,
        'source_rate_window_seconds' => 900,
        'source_rate_max_requests' => 20,
        'challenge_history_limit' => 50,
    ];

    return $config;
}

function otp_api_recovery_pepper(): string
{
    $pepper = (string) (getenv('OTP_RECOVERY_PEPPER') ?: '');

    if (strlen($pepper) < 32) {
        throw new RuntimeException(
            'OTP recovery pepper is missing or too short.'
        );
    }

    return $pepper;
}
