<?php

/*
 * Fake Dify API for trying the UI without a Dify key. It streams the three
 * answer styles from the design: verified, AI-with-sources and hand-off.
 *
 *   php -S localhost:8081 tools/mock-dify.php
 *   .env → DIFY_API_BASE=http://localhost:8081/v1  DIFY_API_KEY=mock
 */

declare(strict_types=1);

function uuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
    $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function sse(array $event): void
{
    echo 'data: ', json_encode($event, JSON_UNESCAPED_UNICODE), "\n\n";
    flush();
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];

if (preg_match('#^/v1/(messages/[^/]+/feedbacks|chat-messages/[^/]+/stop)$#', $path)) {
    header('Content-Type: application/json');
    echo '{"result":"success"}';
    return;
}
if ($path !== '/v1/chat-messages') {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['code' => 'not_found', 'message' => 'Not found', 'status' => 404]);
    return;
}

$query = strtolower((string) ($body['query'] ?? ''));
$ids = [
    'conversation_id' => ($body['conversation_id'] ?? '') ?: uuid(),
    'message_id' => uuid(),
    'task_id' => uuid(),
];
$metadata = [];

if (preg_match('/document|dokumen/', $query)) {
    $answer = "You'll need:\n- A copy of the player's IC, MyKid or passport\n- A recent passport-size photo\n- [Any other document TMS requires]";
    $metadata['annotation_reply'] = ['id' => uuid(), 'account' => ['id' => uuid(), 'name' => 'TMS Admin']];
} elseif (preg_match('/paid|payment|unpaid|fee|bayar|yuran/', $query)) {
    $answer = "[[HANDOFF]] I can't check payment records from here\nPayment status is linked to your team's account, which I can't see. Please contact TMS so someone can check it for you.";
} else {
    $answer = 'No. A player can be registered with **one club per season**. To move to another club, the current club first approves a transfer request in TMS, then the new club completes the registration.';
    $metadata['retriever_resources'] = [
        ['position' => 1, 'document_name' => 'Player Registration Guide.pdf', 'segment_position' => 4, 'content' => 'Each player may be registered with one club per season…'],
        ['position' => 2, 'document_name' => 'Transfer Rules.pdf', 'page' => 12, 'content' => 'Transfers require approval from the current club…'],
    ];
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
while (ob_get_level() > 0) {
    ob_end_flush();
}

echo "event: ping\n\n";
flush();
usleep(400_000);

foreach (preg_split('/(?<=\s)/', $answer) as $chunk) {
    sse(['event' => 'message', 'answer' => $chunk, 'created_at' => time()] + $ids);
    usleep(35_000);
}
sse(['event' => 'message_end', 'metadata' => $metadata + ['usage' => ['total_tokens' => 0]]] + $ids);
