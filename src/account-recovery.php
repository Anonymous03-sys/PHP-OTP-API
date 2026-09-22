<?php

declare(strict_types=1);

require_once __DIR__ . '/firebase.php';

function otp_api_recovery_portals(): array
{
    return [
        'senior' => [
            'collection' => 'seniorcitizens',
            'identifier_field' => 'senior_id_number',
            'password_field' => 'senior_password',
            'email_field' => 'email_address',
            'normalizer' => 'uppercase',
            'eligibility' => 'senior',
        ],
        'lgu' => [
            'collection' => 'seniorlgu',
            'identifier_field' => 'employee_no',
            'password_field' => 'password',
            'email_field' => 'email_address',
            'normalizer' => 'trim',
            'eligibility' => 'lgu',
        ],
        'sysadmin' => [
            'collection' => 'seniorsysadusers',
            'identifier_field' => 'employee_no',
            'password_field' => 'password',
            'email_field' => 'email_address',
            'normalizer' => 'trim',
            'eligibility' => 'sysadmin',
        ],
    ];
}

function otp_api_get_recovery_portal(string $portal): ?array
{
    $key = strtolower(trim($portal));
    $portals = otp_api_recovery_portals();

    return $portals[$key] ?? null;
}

function otp_api_normalize_recovery_identifier(
    array $portalConfig,
    string $identifier
): string {
    $normalized = trim($identifier);

    if (($portalConfig['normalizer'] ?? '') === 'uppercase') {
        $normalized = strtoupper($normalized);
    }

    return $normalized;
}

function otp_api_is_senior_recovery_eligible(array $document): bool
{
    $accountStatus = strtoupper(trim((string) otp_api_firestore_field(
        $document,
        'status',
        'APPROVED'
    )));
    $vitalStatus = strtoupper(trim((string) otp_api_firestore_field(
        $document,
        'vital_status',
        'ALIVE'
    )));

    if (in_array(
        $accountStatus,
        ['PENDING', 'REJECTED', 'DISABLED'],
        true
    )) {
        return false;
    }

    return $vitalStatus !== 'DECEASED';
}

function otp_api_resolve_lgu_account_status(array $document): string
{
    $explicitStatus = strtoupper(trim((string) otp_api_firestore_field(
        $document,
        'account_status',
        ''
    )));

    if ($explicitStatus === 'INACTIVE') {
        return 'INACTIVE';
    }

    if ($explicitStatus === 'ACTIVE') {
        return 'ACTIVE';
    }

    $legacyEmployment = otp_api_firestore_field(
        $document,
        'is_employed',
        null
    );

    if ($legacyEmployment === false) {
        return 'INACTIVE';
    }

    if ($legacyEmployment === true) {
        return 'ACTIVE';
    }

    $legacyText = strtoupper(trim((string) ($legacyEmployment ?? '')));

    if (in_array(
        $legacyText,
        ['NO', 'FALSE', 'INACTIVE', 'UNEMPLOYED'],
        true
    )) {
        return 'INACTIVE';
    }

    return 'ACTIVE';
}

function otp_api_is_recovery_eligible(
    string $eligibilityRule,
    array $document
): bool {
    return match ($eligibilityRule) {
        'senior' => otp_api_is_senior_recovery_eligible($document),
        'lgu' => otp_api_resolve_lgu_account_status($document) === 'ACTIVE',
        'sysadmin' => true,
        default => false,
    };
}

function otp_api_mask_email(string $email): string
{
    $email = trim($email);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return '';
    }

    [$local, $domain] = explode('@', $email, 2);
    $localLength = strlen($local);

    if ($localLength <= 1) {
        $maskedLocal = '*';
    } elseif ($localLength === 2) {
        $maskedLocal = substr($local, 0, 1) . '*';
    } else {
        $visible = substr($local, 0, min(2, $localLength - 1));
        $maskedLocal = $visible . str_repeat(
            '*',
            max(3, $localLength - strlen($visible))
        );
    }

    return $maskedLocal . '@' . $domain;
}

function otp_api_resolve_recovery_account(
    string $portal,
    string $identifier
): array {
    $portalKey = strtolower(trim($portal));
    $portalConfig = otp_api_get_recovery_portal($portalKey);

    if ($portalConfig === null) {
        throw new InvalidArgumentException('Unknown recovery portal.');
    }

    $normalizedIdentifier = otp_api_normalize_recovery_identifier(
        $portalConfig,
        $identifier
    );

    if ($normalizedIdentifier === '') {
        throw new InvalidArgumentException('Recovery identifier is required.');
    }

    $document = otp_api_firestore_find_single_document(
        $portalConfig['collection'],
        $portalConfig['identifier_field'],
        $normalizedIdentifier
    );

    if ($document === null) {
        return [
            'resolved' => false,
            'eligible' => false,
            'reason' => 'ACCOUNT_NOT_RESOLVED',
        ];
    }

    $documentId = otp_api_firestore_document_id($document);

    if ($documentId === '') {
        return [
            'resolved' => false,
            'eligible' => false,
            'reason' => 'ACCOUNT_NOT_RESOLVED',
        ];
    }

    $eligible = otp_api_is_recovery_eligible(
        $portalConfig['eligibility'],
        $document
    );

    $email = trim((string) otp_api_firestore_field(
        $document,
        $portalConfig['email_field'],
        ''
    ));
    $validEmail = filter_var($email, FILTER_VALIDATE_EMAIL) !== false;

    return [
        'resolved' => true,
        'eligible' => $eligible && $validEmail,
        'reason' => !$eligible
            ? 'ACCOUNT_NOT_ELIGIBLE'
            : ($validEmail ? 'READY' : 'RECOVERY_EMAIL_UNAVAILABLE'),
        'portal' => $portalKey,
        'collection' => $portalConfig['collection'],
        'account_document_id' => $documentId,
        'identifier' => $normalizedIdentifier,
        'identifier_field' => $portalConfig['identifier_field'],
        'password_field' => $portalConfig['password_field'],
        'email' => $validEmail ? $email : '',
        'masked_email' => $validEmail ? otp_api_mask_email($email) : '',
    ];
}
