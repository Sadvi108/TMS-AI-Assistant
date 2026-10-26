<?php

declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';

// Dify conversation, message and task ids are UUIDs.
const ID_PATTERN = '/^[A-Za-z0-9-]{8,64}$/';

require __DIR__ . '/DifyClient.php';
require __DIR__ . '/Redactor.php';

load_env(APP_ROOT . '/.env');

/** Reads KEY=value lines into the environment without overriding real env vars. */
function load_env(string $path): void
{
    if (!is_readable($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            $value = substr($value, 1, -1);
        }
        if (getenv($key) === false) {
            putenv("$key=$value");
        }
    }
}

function env(string $key, string $default = ''): string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

function app_config(): array
{
    static $config;
    return $config ??= [
        'dify_base' => rtrim(env('DIFY_API_BASE', 'https://api.dify.ai/v1'), '/'),
        'dify_key' => env('DIFY_API_KEY'),
        'language_input' => env('DIFY_LANGUAGE_INPUT'),
        'handoff_marker' => env('HANDOFF_MARKER', '[[HANDOFF]]'),
        'handoff_phrases' => array_values(array_filter(array_map('trim', explode('|', env(
            'HANDOFF_PHRASES',
            "can't answer that from TMS information|cannot answer that from TMS information|can only assist with TMS|can only help with TMS|tidak dapat menjawab|hanya dapat membantu"
        ))))),
        'website_url' => env('TMS_WEBSITE_URL', 'https://maclubsystem.com'),
        'support' => [
            'email' => env('TMS_SUPPORT_EMAIL', '[Official TMS email]'),
            'phone' => env('TMS_SUPPORT_PHONE', '[Official TMS phone]'),
            'hours' => env('TMS_OFFICE_HOURS', '[Office hours]'),
        ],
        'debug' => env('APP_DEBUG') === 'true',
    ];
}

function dify(): DifyClient
{
    $config = app_config();
    return new DifyClient($config['dify_base'], $config['dify_key']);
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

/** Anonymous, long-lived id so Dify can keep each visitor's conversations apart. */
function visitor_id(): string
{
    $id = $_COOKIE['tms_uid'] ?? '';
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
        $id = bin2hex(random_bytes(16));
        setcookie('tms_uid', $id, [
            'expires' => time() + 60 * 60 * 24 * 365,
            'path' => '/',
            'secure' => is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE['tms_uid'] = $id;
    }
    return 'tms-' . $id;
}

function current_lang(): string
{
    $lang = $_COOKIE['tms_lang'] ?? '';
    if ($lang === 'en' || $lang === 'ms') {
        return $lang;
    }
    return str_starts_with(strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), 'ms') ? 'ms' : 'en';
}

function json_out(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(int $status, string $message): never
{
    json_out(['error' => $message], $status);
}

/**
 * Accepts only same-origin style JSON POSTs. Browsers can't send
 * application/json cross-site without a CORS preflight, so this doubles
 * as CSRF protection for the API endpoints.
 */
function read_json_request(): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_error(405, 'Method not allowed.');
    }
    if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        json_error(415, 'Expected a JSON request.');
    }
    $data = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($data)) {
        json_error(400, 'Invalid JSON.');
    }
    return $data;
}

/** Switches the response to a newline-delimited JSON stream that flushes as it goes. */
function start_stream(): void
{
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    ignore_user_abort(true); // we notice the disconnect ourselves and cancel Dify
    set_time_limit(0);
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('Cache-Control: no-cache, no-store');
    header('X-Accel-Buffering: no'); // nginx: don't buffer the stream
}

function emit(array $event): void
{
    echo json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
    flush();
}

/** Sliding-window limit per client IP, stored in small files under storage/. */
function rate_limited(string $bucket, int $max, int $seconds): bool
{
    $dir = APP_ROOT . '/storage/ratelimit';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return false;
    }
    $handle = @fopen($dir . '/' . hash('sha256', $bucket . '|' . ($_SERVER['REMOTE_ADDR'] ?? '')), 'c+');
    if ($handle === false) {
        return false;
    }
    flock($handle, LOCK_EX);
    $now = time();
    $hits = json_decode(stream_get_contents($handle) ?: '[]', true);
    $hits = array_values(array_filter(is_array($hits) ? $hits : [], fn($t) => is_int($t) && $t > $now - $seconds));
    $limited = count($hits) >= $max;
    if (!$limited) {
        $hits[] = $now;
    }
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($hits));
    flock($handle, LOCK_UN);
    fclose($handle);
    return $limited;
}

/** Appends one JSON line to storage/<file>. Logging must never break a request. */
function app_log(string $file, array $row): void
{
    $row = ['time' => date('c')] + $row;
    $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    if (@file_put_contents(APP_ROOT . '/storage/' . $file, $line, FILE_APPEND | LOCK_EX) === false) {
        error_log("TMS assistant: could not write storage/$file");
    }
}
