<?php

declare(strict_types=1);

require_once __DIR__ . '/http.php';

function otp_api_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function otp_api_load_firebase_service_account(): array
{
    static $serviceAccount = null;

    if ($serviceAccount !== null) {
        return $serviceAccount;
    }

    $rawJson = trim((string) (getenv('FIREBASE_SERVICE_ACCOUNT_JSON') ?: ''));

    if ($rawJson === '') {
        $base64 = trim((string) (
            getenv('FIREBASE_SERVICE_ACCOUNT_JSON_BASE64') ?: ''
        ));

        if ($base64 !== '') {
            $decoded = base64_decode($base64, true);

            if ($decoded === false) {
                throw new RuntimeException(
                    'Firebase service account configuration is invalid.'
                );
            }

            $rawJson = $decoded;
        }
    }

    if ($rawJson === '') {
        throw new RuntimeException(
            'Firebase service account configuration is missing.'
        );
    }

    try {
        $decoded = json_decode(
            $rawJson,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException) {
        throw new RuntimeException(
            'Firebase service account configuration is invalid.'
        );
    }

    if (!is_array($decoded)) {
        throw new RuntimeException(
            'Firebase service account configuration is invalid.'
        );
    }

    foreach (['project_id', 'client_email', 'private_key'] as $requiredField) {
        if (trim((string) ($decoded[$requiredField] ?? '')) === '') {
            throw new RuntimeException(
                'Firebase service account configuration is incomplete.'
            );
        }
    }

    $serviceAccount = $decoded;

    return $serviceAccount;
}

function otp_api_firebase_access_token(): string
{
    static $cachedToken = null;
    static $cachedUntil = 0;

    if (
        is_string($cachedToken) &&
        $cachedToken !== '' &&
        $cachedUntil > time() + 60
    ) {
        return $cachedToken;
    }

    $serviceAccount = otp_api_load_firebase_service_account();
    $issuedAt = time();

    $header = [
        'alg' => 'RS256',
        'typ' => 'JWT',
    ];

    $claims = [
        'iss' => (string) $serviceAccount['client_email'],
        'scope' => 'https://www.googleapis.com/auth/datastore',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $issuedAt,
        'exp' => $issuedAt + 3600,
    ];

    $encodedHeader = otp_api_base64url_encode(
        json_encode($header, JSON_UNESCAPED_SLASHES)
    );
    $encodedClaims = otp_api_base64url_encode(
        json_encode($claims, JSON_UNESCAPED_SLASHES)
    );
    $unsignedToken = $encodedHeader . '.' . $encodedClaims;

    $signature = '';

    if (!openssl_sign(
        $unsignedToken,
        $signature,
        (string) $serviceAccount['private_key'],
        OPENSSL_ALGO_SHA256
    )) {
        throw new RuntimeException(
            'Unable to authorize Firebase service access.'
        );
    }

    $assertion = $unsignedToken . '.' . otp_api_base64url_encode($signature);

    $response = otp_api_http_request(
        'POST',
        'https://oauth2.googleapis.com/token',
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ], '', '&', PHP_QUERY_RFC3986)
    );

    if ($response['status'] < 200 || $response['status'] >= 300) {
        throw new RuntimeException(
            'Unable to authorize Firebase service access.'
        );
    }

    try {
        $payload = json_decode(
            $response['body'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException) {
        throw new RuntimeException(
            'Firebase authorization returned an invalid response.'
        );
    }

    $accessToken = trim((string) ($payload['access_token'] ?? ''));
    $expiresIn = (int) ($payload['expires_in'] ?? 3600);

    if ($accessToken === '') {
        throw new RuntimeException(
            'Firebase authorization did not return an access token.'
        );
    }

    $cachedToken = $accessToken;
    $cachedUntil = time() + max(60, $expiresIn);

    return $cachedToken;
}

function otp_api_firestore_run_query(array $structuredQuery): array
{
    $serviceAccount = otp_api_load_firebase_service_account();
    $projectId = rawurlencode((string) $serviceAccount['project_id']);

    $url = sprintf(
        'https://firestore.googleapis.com/v1/projects/%s/databases/(default)/documents:runQuery',
        $projectId
    );

    $body = json_encode(
        ['structuredQuery' => $structuredQuery],
        JSON_UNESCAPED_SLASHES
    );

    if ($body === false) {
        throw new RuntimeException('Unable to encode Firestore query.');
    }

    $response = otp_api_http_request(
        'POST',
        $url,
        [
            'Authorization: Bearer ' . otp_api_firebase_access_token(),
            'Content-Type: application/json',
        ],
        $body
    );

    if ($response['status'] < 200 || $response['status'] >= 300) {
        throw new RuntimeException('Firestore account lookup failed.');
    }

    try {
        $payload = json_decode(
            $response['body'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException) {
        throw new RuntimeException(
            'Firestore account lookup returned an invalid response.'
        );
    }

    if (!is_array($payload)) {
        throw new RuntimeException(
            'Firestore account lookup returned an invalid response.'
        );
    }

    return $payload;
}

function otp_api_firestore_find_single_document(
    string $collection,
    string $identifierField,
    string $identifierValue
): ?array {
    $results = otp_api_firestore_run_query([
        'from' => [
            ['collectionId' => $collection],
        ],
        'where' => [
            'fieldFilter' => [
                'field' => ['fieldPath' => $identifierField],
                'op' => 'EQUAL',
                'value' => ['stringValue' => $identifierValue],
            ],
        ],
        'limit' => 2,
    ]);

    $documents = [];

    foreach ($results as $result) {
        if (
            is_array($result) &&
            isset($result['document']) &&
            is_array($result['document'])
        ) {
            $documents[] = $result['document'];
        }
    }

    if (count($documents) !== 1) {
        return null;
    }

    return $documents[0];
}

function otp_api_firestore_document_id(array $document): string
{
    $name = trim((string) ($document['name'] ?? ''));

    if ($name === '') {
        return '';
    }

    $segments = explode('/', $name);
    $last = end($segments);

    return $last === false ? '' : rawurldecode((string) $last);
}

function otp_api_firestore_decode_value(mixed $value): mixed
{
    if (!is_array($value)) {
        return null;
    }

    if (array_key_exists('nullValue', $value)) {
        return null;
    }

    if (array_key_exists('stringValue', $value)) {
        return (string) $value['stringValue'];
    }

    if (array_key_exists('booleanValue', $value)) {
        return (bool) $value['booleanValue'];
    }

    if (array_key_exists('integerValue', $value)) {
        return (int) $value['integerValue'];
    }

    if (array_key_exists('doubleValue', $value)) {
        return (float) $value['doubleValue'];
    }

    if (array_key_exists('timestampValue', $value)) {
        return (string) $value['timestampValue'];
    }

    return null;
}

function otp_api_firestore_field(
    array $document,
    string $fieldName,
    mixed $default = null
): mixed {
    $fields = $document['fields'] ?? null;

    if (
        !is_array($fields) ||
        !array_key_exists($fieldName, $fields)
    ) {
        return $default;
    }

    $decoded = otp_api_firestore_decode_value($fields[$fieldName]);

    return $decoded ?? $default;
}
