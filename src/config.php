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
    ];

    return $config;
}
