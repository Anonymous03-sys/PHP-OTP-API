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

function otp_api_firestore_documents_base_url(): string
{
    $serviceAccount = otp_api_load_firebase_service_account();
    $projectId = rawurlencode((string) $serviceAccount['project_id']);

    return sprintf(
        'https://firestore.googleapis.com/v1/projects/%s/databases/(default)/documents',
        $projectId
    );
}

function otp_api_firestore_authorized_headers(): array
{
    return [
        'Authorization: Bearer ' . otp_api_firebase_access_token(),
        'Content-Type: application/json',
    ];
}

function otp_api_firestore_decode_json_response(
    array $response,
    string $failureMessage
): array {
    if ($response['status'] < 200 || $response['status'] >= 300) {
        throw new RuntimeException($failureMessage);
    }

    try {
        $payload = json_decode(
            $response['body'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException) {
        throw new RuntimeException($failureMessage);
    }

    if (!is_array($payload)) {
        throw new RuntimeException($failureMessage);
    }

    return $payload;
}

function otp_api_firestore_run_query(array $structuredQuery): array
{
    $url = otp_api_firestore_documents_base_url() . ':runQuery';

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
        otp_api_firestore_authorized_headers(),
        $body
    );

    return otp_api_firestore_decode_json_response(
        $response,
        'Firestore query failed.'
    );
}

function otp_api_firestore_find_single_document(
    string $collection,
    string $identifierField,
    string $identifierValue
): ?array {
    $results = otp_api_firestore_query_equal(
        $collection,
        $identifierField,
        $identifierValue,
        2
    );

    if (count($results) !== 1) {
        return null;
    }

    return $results[0];
}

function otp_api_firestore_query_equal(
    string $collection,
    string $field,
    string $value,
    int $limit = 50
): array {
    $results = otp_api_firestore_run_query([
        'from' => [
            ['collectionId' => $collection],
        ],
        'where' => [
            'fieldFilter' => [
                'field' => ['fieldPath' => $field],
                'op' => 'EQUAL',
                'value' => ['stringValue' => $value],
            ],
        ],
        'limit' => max(1, min(100, $limit)),
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

    return $documents;
}

function otp_api_firestore_get_document(
    string $collection,
    string $documentId
): ?array {
    $url = otp_api_firestore_documents_base_url()
        . '/'
        . rawurlencode($collection)
        . '/'
        . rawurlencode($documentId);

    $response = otp_api_http_request(
        'GET',
        $url,
        otp_api_firestore_authorized_headers()
    );

    if ($response['status'] === 404) {
        return null;
    }

    return otp_api_firestore_decode_json_response(
        $response,
        'Firestore document read failed.'
    );
}

function otp_api_firestore_create_document(
    string $collection,
    string $documentId,
    array $fields
): array {
    $url = otp_api_firestore_documents_base_url()
        . '/'
        . rawurlencode($collection)
        . '?documentId='
        . rawurlencode($documentId);

    $body = json_encode(
        ['fields' => otp_api_firestore_encode_fields($fields)],
        JSON_UNESCAPED_SLASHES
    );

    if ($body === false) {
        throw new RuntimeException('Unable to encode Firestore document.');
    }

    $response = otp_api_http_request(
        'POST',
        $url,
        otp_api_firestore_authorized_headers(),
        $body
    );

    return otp_api_firestore_decode_json_response(
        $response,
        'Firestore document creation failed.'
    );
}

function otp_api_firestore_patch_document(
    string $collection,
    string $documentId,
    array $fields
): array {
    if ($fields === []) {
        throw new InvalidArgumentException(
            'Firestore update requires at least one field.'
        );
    }

    $query = [];

    foreach (array_keys($fields) as $fieldName) {
        $query[] = 'updateMask.fieldPaths=' . rawurlencode((string) $fieldName);
    }

    $url = otp_api_firestore_documents_base_url()
        . '/'
        . rawurlencode($collection)
        . '/'
        . rawurlencode($documentId)
        . '?'
        . implode('&', $query);

    $body = json_encode(
        ['fields' => otp_api_firestore_encode_fields($fields)],
        JSON_UNESCAPED_SLASHES
    );

    if ($body === false) {
        throw new RuntimeException('Unable to encode Firestore document.');
    }

    $response = otp_api_http_request(
        'PATCH',
        $url,
        otp_api_firestore_authorized_headers(),
        $body
    );

    return otp_api_firestore_decode_json_response(
        $response,
        'Firestore document update failed.'
    );
}

function otp_api_firestore_encode_fields(array $fields): array
{
    $encoded = [];

    foreach ($fields as $name => $value) {
        $encoded[(string) $name] = otp_api_firestore_encode_value($value);
    }

    return $encoded;
}

function otp_api_firestore_encode_value(mixed $value): array
{
    if ($value === null) {
        return ['nullValue' => null];
    }

    if (is_bool($value)) {
        return ['booleanValue' => $value];
    }

    if (is_int($value)) {
        return ['integerValue' => (string) $value];
    }

    if (is_float($value)) {
        return ['doubleValue' => $value];
    }

    if (is_string($value)) {
        return ['stringValue' => $value];
    }

    throw new InvalidArgumentException(
        'Unsupported Firestore field value type.'
    );
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
