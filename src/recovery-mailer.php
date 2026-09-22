<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

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

    if (!preg_match('/^\\d{6}$/', $otp)) {
        return [
            'success' => false,
            'status_code' => 0,
            'reason' => 'INVALID_OTP',
        ];
    }

    $apiKey = trim((string) (getenv('SENDGRID_API_KEY') ?: ''));

    if ($apiKey === '') {
        return [
            'success' => false,
            'status_code' => 0,
            'reason' => 'MAIL_CONFIGURATION_UNAVAILABLE',
        ];
    }

    try {
        $sender = otp_api_recovery_sender();
        $sendgrid = new \SendGrid($apiKey);
        $message = new \SendGrid\Mail\Mail();

        $message->setFrom($sender['email'], $sender['name']);
        $message->setSubject(
            'Senior Citizen Information System - Password Recovery Code'
        );
        $message->addTo($destinationEmail);

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

        $message->addContent('text/plain', $plainText);
        $message->addContent('text/html', $html);

        $response = $sendgrid->send($message);
        $statusCode = (int) $response->statusCode();

        if ($statusCode >= 200 && $statusCode < 300) {
            return [
                'success' => true,
                'status_code' => $statusCode,
                'reason' => 'SENT',
            ];
        }

        return [
            'success' => false,
            'status_code' => $statusCode,
            'reason' => 'PROVIDER_REJECTED',
        ];
    } catch (Throwable) {
        return [
            'success' => false,
            'status_code' => 0,
            'reason' => 'DELIVERY_EXCEPTION',
        ];
    }
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

    $apiKey = trim((string) (getenv('SENDGRID_API_KEY') ?: ''));

    if ($apiKey === '') {
        return [
            'success' => false,
            'status_code' => 0,
            'reason' => 'MAIL_CONFIGURATION_UNAVAILABLE',
        ];
    }

    try {
        $sender = otp_api_recovery_sender();
        $sendgrid = new \SendGrid($apiKey);
        $message = new \SendGrid\Mail\Mail();

        $message->setFrom($sender['email'], $sender['name']);
        $message->setSubject(
            'Senior Citizen Information System - Password Changed'
        );
        $message->addTo($destinationEmail);

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

        $message->addContent('text/plain', $plainText);
        $message->addContent('text/html', $html);

        $response = $sendgrid->send($message);
        $statusCode = (int) $response->statusCode();

        return [
            'success' => $statusCode >= 200 && $statusCode < 300,
            'status_code' => $statusCode,
            'reason' => $statusCode >= 200 && $statusCode < 300
                ? 'SENT'
                : 'PROVIDER_REJECTED',
        ];
    } catch (Throwable) {
        return [
            'success' => false,
            'status_code' => 0,
            'reason' => 'DELIVERY_EXCEPTION',
        ];
    }
}
