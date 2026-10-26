<?php

/*
 * GET → which settings the server can see, as true/false only (never values).
 * For checking a deployment: e.g. that DIFY_API_KEY reached the container.
 */

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

$config = app_config();
json_out([
    'ok' => true,
    'dify_configured' => $config['dify_key'] !== '',
    'dify_api_base_set' => getenv('DIFY_API_BASE') !== false && getenv('DIFY_API_BASE') !== '',
    'dify_api_key_set' => getenv('DIFY_API_KEY') !== false && getenv('DIFY_API_KEY') !== '',
    'image_env_set' => getenv('TRUST_PROXY') !== false, // set by the Dockerfile
    'env_file_present' => is_readable(APP_ROOT . '/.env'),
]);
