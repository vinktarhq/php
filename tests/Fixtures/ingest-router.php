<?php

declare(strict_types=1);

/*
 * A stand-in for ingest under `php -S`, for CurlTransportTest. The first path segment picks how it
 * answers; every request is appended to the file named by VINKTAR_TEST_LOG.
 */

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$uri = parse_url(is_string($requestUri) ? $requestUri : '/', \PHP_URL_PATH);
$uri = is_string($uri) ? $uri : '/';
$log = getenv('VINKTAR_TEST_LOG');
$raw = (string) file_get_contents('php://input');
$encoding = is_string($_SERVER['HTTP_CONTENT_ENCODING'] ?? null) ? $_SERVER['HTTP_CONTENT_ENCODING'] : '';
$body = $encoding === 'gzip' ? gzdecode($raw) : $raw;

if (is_string($log) && $log !== '') {
    file_put_contents($log, json_encode([
        'uri' => $uri,
        'key' => $_SERVER['HTTP_X_VINKTAR_KEY'] ?? null,
        'encoding' => $encoding,
        'agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        'expect' => $_SERVER['HTTP_EXPECT'] ?? null,
        'body' => json_decode(is_string($body) ? $body : '', true),
    ])."\n", \FILE_APPEND | \LOCK_EX);
}

switch (explode('/', trim($uri, '/'))[0]) {
    case 'redirect':
        http_response_code(307);
        header('Location: /target/v1/batch');
        break;

    case 'ratelimit':
        http_response_code(429);
        header('Content-Type: application/json');
        header('Retry-After: 12');
        header('X-RateLimit-Categories: 12:event;identify');
        echo '{"error":"rate_limited"}';
        break;

    case 'stall':
        // The status and the first bytes arrive, and the rest does not.
        http_response_code(202);
        header('Content-Type: application/json');
        header('Content-Length: 64');
        echo '{"rece';
        flush();
        sleep(3);
        echo 'ived":1}';
        break;

    case 'slow':
        sleep(3);
        http_response_code(202);
        echo '{}';
        break;

    default:
        http_response_code(202);
        header('Content-Type: application/json');
        echo '{"received":1,"rejected":0,"errors":[]}';
}
