<?php

declare(strict_types=1);

function otp_api_recovery_sender(): array
{
    $email = trim((string) (
        getenv('SENIOR_RECOVERY_FROM_EMAIL')
        ?: 'codetology.adm1n@gmail.com'
    ));
    $name = trim((string) (
        getenv('SENIOR_RECOVERY_FROM_NAME')
        ?: 'Senior Citizen Information System'
    ));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException(
            'Recovery sender email configuration is invalid.'
        );
    }

    if ($name === '') {
        $name = 'Senior Citizen Information System';
    }

    return [
        'email' => $email,
        'name' => $name,
    ];
}

function otp_api_brevo_status_code(array $headers): int
{
    foreach ($headers as $header) {
        if (
            is_string($header) &&
            preg_match('/^HTTP\/\S+\s+(\d{3})(?:\s|$)/i', $header, $matches)
        ) {
            return (int) $matches[1];
        }
    }

    return 0;
}

function otp_api_brevo_failure_reason(int $statusCode): string
{
    return match ($statusCode) {
        400 => 'PROVIDER_REQUEST_REJECTED',
        401, 403 => 'PROVIDER_AUTHORIZATION_FAILED',
        429 => 'PROVIDER_RATE_LIMITED',
        default => 'PROVIDER_REJECTED',
    };
}

function otp_api_brevo_send_email(
    string $destinationEmail,
    string $subject,
    string $plainText,
    string $html
): array {
    $destinationEmail = trim($destinationEmail);

    if (!filter_var($destinationEmail, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'status_code' => 0,
            'reason' => 'INVALID_DESTINATION',
        ];
    }

    $apiKey = trim((string) (getenv('BREVO_API_KEY') ?: ''));

    if ($apiKey === '') {
        return [
            'success' => false,
            'status_code' => 0,
            'reason' => 'MAIL_CONFIGURATION_UNAVAILABLE',
        ];
    }

    try {
        $sender = otp_api_recovery_sender();
        $payload = json_encode([
            'sender' => [
                'name' => $sender['name'],
                'email' => $sender['email'],
            ],
            'to' => [
                [
                    'email' => $destinationEmail,
                ],
            ],
            'subject' => $subject,
            'textContent' => $plainText,
            'htmlContent' => $html,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Accept: application/json',
                    'Content-Type: application/json',
                    'api-key: ' . $apiKey,
                    'User-Agent: Senior-E-Services-Recovery/1.0',
                ]),
                'content' => $payload,
                'timeout' => 15,
                'ignore_errors' => true,
                'protocol_version' => 1.1,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $responseBody = @file_get_contents(
            'https://api.brevo.com/v3/smtp/email',
            false,
            $context
        );
        $responseHeaders = $http_response_header ?? [];
        $statusCode = otp_api_brevo_status_code($responseHeaders);

        if ($responseBody === false && $statusCode === 0) {
            return [
                'success' => false,
                'status_code' => 0,
                'reason' => 'DELIVERY_EXCEPTION',
                'provider_message' => 'BREVO_TRANSPORT_FAILURE',
            ];
        }

        $decoded = [];

        if (is_string($responseBody) && trim($responseBody) !== '') {
            $candidate = json_decode($responseBody, true);

            if (is_array($candidate)) {
                $decoded = $candidate;
            }
        }

        $messageId = is_string($decoded['messageId'] ?? null)
            ? trim($decoded['messageId'])
            : '';
        $providerMessage = is_string($decoded['message'] ?? null)
            ? trim($decoded['message'])
            : '';

        if ($statusCode >= 200 && $statusCode < 300) {
            return [
                'success' => true,
                'status_code' => $statusCode,
                'reason' => 'SENT',
                'message_id' => $messageId !== '' ? $messageId : null,
            ];
        }

        return [
            'success' => false,
            'status_code' => $statusCode,
            'reason' => otp_api_brevo_failure_reason($statusCode),
            'provider_message' => $providerMessage !== ''
                ? $providerMessage
                : null,
        ];
    } catch (Throwable $error) {
        return [
            'success' => false,
            'status_code' => 0,
            'reason' => 'DELIVERY_EXCEPTION',
            'provider_message' => get_class($error),
        ];
    }
}

function otp_api_send_recovery_otp(
    string $destinationEmail,
    string $otp,
    int $expiresInSeconds = 300
): array {
    $destinationEmail = trim($destinationEmail);

    if (!filter_var($destinationEmail, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'status_code' => 0,
            'reason' => 'INVALID_DESTINATION',
        ];
    }

    if (!preg_match('/^\d{6}$/', $otp)) {
        return [
            'success' => false,
            'status_code' => 0,
            'reason' => 'INVALID_OTP',
        ];
    }

    $minutes = max(1, (int) ceil($expiresInSeconds / 60));

    $plainText = implode("\n", [
        'Senior Citizen Information System',
        'Password Recovery Verification',
        '',
        'A password reset was requested for your account.',
        '',
        'Your verification code is: ' . $otp,
        '',
        'This code expires in ' . $minutes . ' minutes.',
        'Do not share this code with anyone.',
        '',
        'If you did not request a password reset, ignore this email.',
        'Your current password has not been changed.',
        '',
        'This is an automated message. Please do not reply.',
    ]);

    $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');

    $html = "
    <div style='font-family: Arial, sans-serif; color: #1f2937; line-height: 1.6; max-width: 620px; margin: 0 auto;'>
        <div style='background: #0f766e; color: #ffffff; padding: 20px 24px; border-radius: 12px 12px 0 0;'>
            <div style='font-size: 13px; letter-spacing: 0.08em; text-transform: uppercase; opacity: 0.9;'>LGU E-Services</div>
            <h2 style='margin: 6px 0 0;'>Senior Citizen Information System</h2>
        </div>
        <div style='border: 1px solid #d1d5db; border-top: 0; padding: 24px; border-radius: 0 0 12px 12px; background: #ffffff;'>
            <h3 style='margin-top: 0; color: #111827;'>Password Recovery Verification</h3>
            <p>A password reset was requested for your Senior Citizen Information System account.</p>
            <p>Your six-digit verification code is:</p>
            <div style='font-size: 32px; font-weight: 700; letter-spacing: 8px; text-align: center; background: #f3f4f6; color: #111827; padding: 16px; border-radius: 10px; margin: 20px 0;'>
                {$safeOtp}
            </div>
            <p>This code expires in <strong>{$minutes} minutes</strong>. Do not share this code with anyone.</p>
            <p>If you did not request a password reset, ignore this email. Your current password has not been changed.</p>
            <hr style='border: 0; border-top: 1px solid #e5e7eb; margin: 24px 0;'>
            <p style='font-size: 12px; color: #6b7280; margin-bottom: 0;'>
                Senior Citizen Information System<br>
                LGU E-Services<br>
                This is an automated message. Please do not reply.
            </p>
        </div>
    </div>
    ";

    return otp_api_brevo_send_email(
        $destinationEmail,
        'Senior Citizen Information System - Password Recovery Code',
        $plainText,
        $html
    );
}

function otp_api_send_password_changed_notice(
    string $destinationEmail
): array {
    $destinationEmail = trim($destinationEmail);

    if (!filter_var($destinationEmail, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'status_code' => 0,
            'reason' => 'INVALID_DESTINATION',
        ];
    }

    $plainText = implode("\n", [
        'Senior Citizen Information System',
        'Password Changed',
        '',
        'The password for your account was successfully changed.',
        '',
        'If you made this change, no further action is required.',
        'If you did not perform this action, contact your authorized LGU or system administrator immediately.',
        '',
        'For your protection, existing signed-in sessions were invalidated.',
        '',
        'This is an automated message. Please do not reply.',
    ]);

    $html = "
    <div style='font-family: Arial, sans-serif; color: #1f2937; line-height: 1.6; max-width: 620px; margin: 0 auto;'>
        <div style='background: #0f766e; color: #ffffff; padding: 20px 24px; border-radius: 12px 12px 0 0;'>
            <div style='font-size: 13px; letter-spacing: 0.08em; text-transform: uppercase; opacity: 0.9;'>LGU E-Services</div>
            <h2 style='margin: 6px 0 0;'>Senior Citizen Information System</h2>
        </div>
        <div style='border: 1px solid #d1d5db; border-top: 0; padding: 24px; border-radius: 0 0 12px 12px; background: #ffffff;'>
            <h3 style='margin-top: 0; color: #111827;'>Password Changed</h3>
            <p>The password for your account was successfully changed.</p>
            <p>If you made this change, no further action is required.</p>
            <p>If you did not perform this action, contact your authorized LGU or system administrator immediately.</p>
            <p>For your protection, existing signed-in sessions were invalidated.</p>
            <hr style='border: 0; border-top: 1px solid #e5e7eb; margin: 24px 0;'>
            <p style='font-size: 12px; color: #6b7280; margin-bottom: 0;'>
                Senior Citizen Information System<br>
                LGU E-Services<br>
                This is an automated message. Please do not reply.
            </p>
        </div>
    </div>
    ";

    return otp_api_brevo_send_email(
        $destinationEmail,
        'Senior Citizen Information System - Password Changed',
        $plainText,
        $html
    );
}
