<?php

/* POST {message_id, rating: "like" | "dislike" | null} → records "Did this solve it?" in Dify. */

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

$request = read_json_request();
$messageId = (string) ($request['message_id'] ?? '');
$rating = $request['rating'] ?? null;

if (!preg_match(ID_PATTERN, $messageId)) {
    json_error(422, 'Invalid message id.');
}
if (!in_array($rating, ['like', 'dislike', null], true)) {
    json_error(422, 'Invalid rating.');
}
if (rate_limited('feedback', 30, 60)) {
    json_error(429, 'Too many requests.');
}

try {
    dify()->feedback($messageId, $rating, visitor_id());
} catch (DifyException $e) {
    error_log("Dify feedback failed ({$e->status}): {$e->getMessage()}");
    json_error(502, 'Could not save feedback.');
}

json_out(['ok' => true]);
