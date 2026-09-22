<?php

declare(strict_types=1);

function otp_api_http_request(
    string $method,
    string $url,
    array $headers = [],
    ?string $body = null,
    int $timeoutSeconds = 12
): array {
    $headerText = implode("\r\n", $headers);

    $options = [
        'http' => [
            'method' => strtoupper($method),
            'header' => $headerText,
            'ignore_errors' => true,
            'timeout' => $timeoutSeconds,
        ],
    ];

    if ($body !== null) {
        $options['http']['content'] = $body;
    }

    $context = stream_context_create($options);
    $responseBody = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];

    $status = 0;

    if (isset($responseHeaders[0]) && preg_match(
        '/\s(\d{3})\s/',
        (string) $responseHeaders[0],
        $matches
    )) {
        $status = (int) $matches[1];
    }

    if ($responseBody === false && $status === 0) {
        throw new RuntimeException('Remote service is unavailable.');
    }

    return [
        'status' => $status,
        'body' => $responseBody === false ? '' : $responseBody,
        'headers' => $responseHeaders,
    ];
}
