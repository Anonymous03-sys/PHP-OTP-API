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
    $configuredOrigins = array_values(array_filter(array_map(
        static fn (string $origin): string => trim($origin),
        explode(',', $allowedOriginsValue)
    )));

    // Explicit development origins are safe to keep predictable and avoid
    // requiring a Render environment edit for local Ionic testing.
    $localDevelopmentOrigins = [
        'http://localhost',
        'https://localhost',
        'http://localhost:8100',
        'http://localhost:8101',
        'http://localhost:8102',
        'https://localhost:8100',
        'https://localhost:8101',
        'https://localhost:8102',
        'http://127.0.0.1:8100',
        'http://127.0.0.1:8101',
        'http://127.0.0.1:8102',
        'https://127.0.0.1:8100',
        'https://127.0.0.1:8101',
        'https://127.0.0.1:8102',
        'capacitor://localhost',
    ];

    $allowedOrigins = array_values(array_unique(array_merge(
        $localDevelopmentOrigins,
        $configuredOrigins
    )));

    $config = [
        'service' => 'php-otp-api',
        'api_version' => 'v1',
        'allowed_origins' => $allowedOrigins,
        'firebase_project_id' => trim((string) (
            getenv('FIREBASE_PROJECT_ID') ?: 'ojt-app-3cebb-3ac5a'
        )),
        'challenge_collection' => 'password_reset_challenges',
        'challenge_lock_collection' => 'password_reset_locks',
        'challenge_retention_seconds' => 604800,
        'otp_ttl_seconds' => 300,
        'resend_cooldown_seconds' => 60,
        'max_otp_attempts' => 5,
        'reset_token_ttl_seconds' => 600,
        'account_rate_window_seconds' => 900,
        'account_rate_max_requests' => 5,
        'source_rate_window_seconds' => 900,
        'source_rate_max_requests' => 20,
        'challenge_history_limit' => 100,
        'trusted_proxy_headers' => filter_var(
            getenv('OTP_RECOVERY_TRUST_PROXY_HEADERS') ?: 'false',
            FILTER_VALIDATE_BOOLEAN
        ),
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


function otp_api_firebase_project_id(): string
{
    $projectId = trim((string) (
        otp_api_config()['firebase_project_id'] ?? ''
    ));

    if ($projectId === '') {
        throw new RuntimeException(
            'Firebase target project is not configured.'
        );
    }

    return $projectId;
}
