<?php

declare(strict_types=1);

function otp_api_json_response(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');

    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function otp_api_apply_cors(array $allowedOrigins, array $allowedMethods): void
{
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    $originAllowed = $origin === ''
        || in_array($origin, $allowedOrigins, true);

    header('Vary: Origin');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: ' . implode(', ', $allowedMethods));

    if ($origin !== '') {
        // Approved origins receive the normal CORS response. A disallowed
        // origin receives only a readable rejection response; no recovery
        // operation is permitted to continue.
        header('Access-Control-Allow-Origin: ' . $origin);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    if (!$originAllowed) {
        error_log('[OTP Recovery] blocked browser origin: ' . $origin);

        otp_api_json_response(403, [
            'success' => false,
            'code' => 'RECOVERY_ORIGIN_NOT_ALLOWED',
            'message' => 'This hosted portal is not authorized to use account recovery.',
        ]);
    }
}

function otp_api_require_method(string $expectedMethod): void
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method !== strtoupper($expectedMethod)) {
        otp_api_json_response(405, [
            'success' => false,
            'code' => 'METHOD_NOT_ALLOWED',
            'message' => 'This endpoint does not support the requested method.',
        ]);
    }
}

function otp_api_require_json_content_type(): void
{
    $contentType = strtolower(trim((string) (
        $_SERVER['CONTENT_TYPE'] ?? ''
    )));

    if (
        $contentType === '' ||
        !str_starts_with($contentType, 'application/json')
    ) {
        otp_api_json_response(415, [
            'success' => false,
            'code' => 'UNSUPPORTED_MEDIA_TYPE',
            'message' => 'Requests to this endpoint must use JSON.',
        ]);
    }
}

function otp_api_read_json_body(int $maxBytes = 8192): array
{
    $declaredLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

    if ($declaredLength > $maxBytes) {
        otp_api_json_response(413, [
            'success' => false,
            'code' => 'REQUEST_TOO_LARGE',
            'message' => 'The request body is too large.',
        ]);
    }

    $rawBody = file_get_contents('php://input');

    if ($rawBody === false || trim($rawBody) === '') {
        return [];
    }

    if (strlen($rawBody) > $maxBytes) {
        otp_api_json_response(413, [
            'success' => false,
            'code' => 'REQUEST_TOO_LARGE',
            'message' => 'The request body is too large.',
        ]);
    }

    try {
        $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        otp_api_json_response(400, [
            'success' => false,
            'code' => 'INVALID_JSON',
            'message' => 'The request body must contain valid JSON.',
        ]);
    }

    if (!is_array($payload)) {
        otp_api_json_response(400, [
            'success' => false,
            'code' => 'INVALID_JSON',
            'message' => 'The request body must contain a JSON object.',
        ]);
    }

    return $payload;
}


function otp_api_pad_response_time(
    float $startedAt,
    int $minimumMilliseconds = 700,
    int $jitterMilliseconds = 250
): void {
    $jitter = $jitterMilliseconds > 0
        ? random_int(0, $jitterMilliseconds)
        : 0;
    $targetMicroseconds = ($minimumMilliseconds + $jitter) * 1000;
    $elapsedMicroseconds = (int) round(
        (microtime(true) - $startedAt) * 1000000
    );
    $remaining = $targetMicroseconds - $elapsedMicroseconds;

    if ($remaining > 0) {
        usleep($remaining);
    }
}
