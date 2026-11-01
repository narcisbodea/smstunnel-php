<?php

declare(strict_types=1);

// Router for `php -S`: answers with what it received, so CurlTransport can be tested against a real socket.

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = is_string($uri) ? (string) parse_url($uri, \PHP_URL_PATH) : '/';

// A base URL of http://host/fail-429 makes every call under it answer 429.
if (preg_match('#^/fail-(\d{3})(/|$)#', $path, $match) === 1) {
    http_response_code((int) $match[1]);
    header('Content-Type: application/json');
    header('Retry-After: 7');
    echo json_encode(['message' => 'Status ' . $match[1], 'statusCode' => (int) $match[1]]);

    return true;
}

if ($path === '/redirect') {
    header('Location: https://smstunnel.io/', true, 301);

    return true;
}

$headers = [];
foreach ($_SERVER as $name => $value) {
    if (str_starts_with((string) $name, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr((string) $name, 5)))] = $value;
    }
}
if (isset($_SERVER['CONTENT_TYPE'])) {
    $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
}

header('Content-Type: application/json');
header('X-Echo: yes');
echo json_encode([
    'success' => true,
    'data' => [
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'uri' => $_SERVER['REQUEST_URI'] ?? '',
        'headers' => $headers,
        'body' => file_get_contents('php://input'),
    ],
]);

return true;
