<?php

/* POST {task_id} → asks Dify to stop generating an answer the visitor cancelled. */

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

$request = read_json_request();
$taskId = (string) ($request['task_id'] ?? '');

if (!preg_match(ID_PATTERN, $taskId)) {
    json_error(422, 'Invalid task id.');
}

try {
    dify()->stop($taskId, visitor_id());
} catch (DifyException $e) {
    error_log("Dify stop failed ({$e->status}): {$e->getMessage()}");
    json_error(502, 'Could not stop the answer.');
}

json_out(['ok' => true]);
