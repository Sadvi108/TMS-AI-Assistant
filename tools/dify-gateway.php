<?php

/*
 * Router for PHP's built-in server that forwards only Dify's app API (/v1/...)
 * to the local Dify, streaming the response. tunnel.sh puts it between the
 * Cloudflare tunnel and Dify, so the tunnel never exposes the Dify console.
 *
 *   php -S 127.0.0.1:8787 tools/dify-gateway.php
 */

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
if (!str_starts_with($path, '/v1/')) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo "Not found\n";
    return;
}

$upstream = rtrim(getenv('DIFY_UPSTREAM') ?: 'http://localhost', '/') . $_SERVER['REQUEST_URI'];
$method = $_SERVER['REQUEST_METHOD'];

$headers = [];
foreach (getallheaders() as $name => $value) {
    if (!in_array(strtolower($name), ['host', 'content-length', 'connection', 'accept-encoding'], true)) {
        $headers[] = "$name: $value";
    }
}

while (ob_get_level() > 0) {
    ob_end_flush();
}

$ch = curl_init($upstream);
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 300,
    CURLOPT_HEADERFUNCTION => function ($ch, string $line): int {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            http_response_code((int) $m[1]);
        } elseif (preg_match('/^(content-type|cache-control):/i', $line)) {
            header(trim($line));
        }
        return strlen($line);
    },
    CURLOPT_WRITEFUNCTION => function ($ch, string $chunk): int {
        echo $chunk;
        flush();
        return connection_aborted() ? 0 : strlen($chunk);
    },
]);
if ($method !== 'GET' && $method !== 'HEAD') {
    curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents('php://input'));
}

if (curl_exec($ch) === false && !connection_aborted()) {
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode(['code' => 'bad_gateway', 'message' => 'Local Dify is not reachable: ' . curl_error($ch)]);
}
