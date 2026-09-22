<?php

declare(strict_types=1);

function otp_api_json_response(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function otp_api_apply_cors(array $allowedOrigins, array $allowedMethods): void
{
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));

    header('Vary: Origin');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: ' . implode(', ', $allowedMethods));

    if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
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

function otp_api_read_json_body(): array
{
    $rawBody = file_get_contents('php://input');

    if ($rawBody === false || trim($rawBody) === '') {
        return [];
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
