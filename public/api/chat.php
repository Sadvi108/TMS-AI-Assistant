<?php

/*
 * POST {query, conversation_id?, lang} → streams the Dify answer back as
 * newline-delimited JSON events:
 *   start   {query, redacted}                  question as sent to Dify
 *   meta    {conversation_id, message_id, task_id}
 *   delta   {text}                             next piece of the answer
 *   replace {text}                             Dify moderation replaced the answer
 *   end     {verified, sources[], handoff}     how to present the finished answer
 *   error   {message, code?}
 */

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

const MAX_QUESTION_CHARS = 2000;

$request = read_json_request();
$config = app_config();
$user = visitor_id();

$lang = ($request['lang'] ?? '') === 'ms' ? 'ms' : 'en';
$text = [
    'en' => [
        'empty' => 'Please type a question.',
        'long' => 'Please keep your question under 2,000 characters.',
        'busy' => "You're sending questions very quickly. Please wait a minute and try again.",
        'setup' => "The assistant isn't set up yet. Please contact TMS.",
        'generic' => "Sorry, the assistant couldn't answer just now. Please try again.",
    ],
    'ms' => [
        'empty' => 'Sila taip soalan anda.',
        'long' => 'Sila pastikan soalan anda kurang daripada 2,000 aksara.',
        'busy' => 'Anda menghantar soalan terlalu cepat. Sila tunggu seminit dan cuba lagi.',
        'setup' => 'Pembantu belum disediakan. Sila hubungi TMS.',
        'generic' => 'Maaf, pembantu tidak dapat menjawab sekarang. Sila cuba lagi.',
    ],
][$lang];

$query = trim((string) ($request['query'] ?? ''));
$conversationId = (string) ($request['conversation_id'] ?? '');

if ($query === '' || !preg_match('//u', $query)) {
    json_error(422, $text['empty']);
}
if (preg_match_all('/./su', $query) > MAX_QUESTION_CHARS) {
    json_error(422, $text['long']);
}
if (!preg_match(ID_PATTERN, $conversationId)) {
    $conversationId = '';
}
if (rate_limited('chat', 20, 60)) {
    json_error(429, $text['busy']);
}

[$query, $redacted] = Redactor::redact($query);

start_stream();
emit(['type' => 'start', 'query' => $query, 'redacted' => $redacted]);

$client = dify();
if (!$client->isConfigured()) {
    emit(['type' => 'error', 'message' => $text['setup'] . debug_detail('DIFY_API_KEY is not set in .env')]);
    exit;
}

$inputs = $config['language_input'] !== ''
    ? [$config['language_input'] => $lang === 'ms' ? 'Bahasa Malaysia' : 'English']
    : (object) [];

$answer = '';
$meta = ['conversation_id' => $conversationId, 'message_id' => ''];
$endMetadata = null;
$workflowStarted = false;
$endedBeforeWorkflow = false;
$faqNodeRan = false;

try {
    $client->streamChat([
        'inputs' => $inputs,
        'query' => $query,
        'user' => $user,
        'conversation_id' => $conversationId,
    ], function (array $event) use (&$answer, &$meta, &$endMetadata, &$workflowStarted, &$endedBeforeWorkflow, &$faqNodeRan, $config, $text): bool {
        if (connection_aborted()) {
            return false;
        }

        if ($meta['message_id'] === '' && !empty($event['message_id'])) {
            $meta = [
                'conversation_id' => (string) ($event['conversation_id'] ?? ''),
                'message_id' => (string) $event['message_id'],
            ];
            emit(['type' => 'meta'] + $meta + ['task_id' => (string) ($event['task_id'] ?? '')]);
        }

        switch ($event['event'] ?? '') {
            case 'message':
            case 'agent_message':
                $chunk = (string) ($event['answer'] ?? '');
                if ($chunk !== '') {
                    $answer .= $chunk;
                    emit(['type' => 'delta', 'text' => $chunk]);
                }
                break;

            case 'message_replace':
                $answer = (string) ($event['answer'] ?? '');
                emit(['type' => 'replace', 'text' => $answer]);
                break;

            case 'message_end':
                // Held until the stream closes: Chatflow apps only reveal an annotation hit afterwards.
                $endMetadata = is_array($event['metadata'] ?? null) ? $event['metadata'] : [];
                $endedBeforeWorkflow = !$workflowStarted;
                break;

            case 'workflow_started':
                $workflowStarted = true;
                break;

            case 'node_started':
                // The Chatflow's "exact FAQ answer" node returns a knowledge-base answer word for word.
                if (($event['data']['node_id'] ?? '') === $config['faq_answer_node']) {
                    $faqNodeRan = true;
                }
                break;

            case 'workflow_finished':
                if (($event['data']['status'] ?? '') === 'failed' && $answer === '') {
                    error_log('Dify workflow failed: ' . ($event['data']['error'] ?? ''));
                    emit(['type' => 'error', 'message' => $text['generic'] . debug_detail((string) ($event['data']['error'] ?? ''))]);
                }
                break;

            case 'error':
                error_log('Dify stream error: ' . json_encode($event));
                emit(['type' => 'error', 'message' => $text['generic'] . debug_detail((string) ($event['message'] ?? ''))]);
                break;
        }
        return true;
    });

    if ($endMetadata !== null) {
        $handoff = str_contains($answer, $config['handoff_marker']) || says_cannot_answer($answer, $config['handoff_phrases']);
        emit([
            'type' => 'end',
            // Verified = TMS's own wording: a Dify annotation reply (Chatbot apps flag it in the
            // metadata; Chatflow apps answer before the workflow starts) or the exact-FAQ node.
            'verified' => !empty($endMetadata['annotation_reply']) || ($endedBeforeWorkflow && $workflowStarted) || $faqNodeRan,
            'sources' => sources_from($endMetadata['retriever_resources'] ?? []),
            'handoff' => $handoff,
        ]);
        if ($handoff) {
            app_log('unanswered.jsonl', [
                'question' => $query,
                'answer' => trim(str_replace($config['handoff_marker'], '', preg_replace('/<think>.*?<\/think>/s', '', $answer) ?? $answer)),
                'lang' => $lang,
            ] + $meta);
        }
    }
} catch (DifyException $e) {
    if ($e->status === 404 && stripos($e->getMessage(), 'conversation') !== false) {
        // The browser held an old conversation id; it starts a fresh one and retries.
        emit(['type' => 'error', 'code' => 'conversation_expired', 'message' => $text['generic']]);
    } else {
        error_log("Dify request failed ({$e->status} {$e->difyCode}): {$e->getMessage()}");
        $userMessage = $e->status === 401 ? $text['setup'] : $text['generic'];
        emit(['type' => 'error', 'message' => $userMessage . debug_detail("Dify {$e->status}: {$e->getMessage()}")]);
    }
}

/** True when the visible answer (reasoning stripped) is the prompt's "I can't answer that" fallback. */
function says_cannot_answer(string $answer, array $phrases): bool
{
    $visible = str_replace('’', "'", preg_replace('/<think>.*?<\/think>/s', '', $answer) ?? $answer);
    foreach ($phrases as $phrase) {
        if (stripos($visible, $phrase) !== false) {
            return true;
        }
    }
    return false;
}

/** Turns Dify retriever_resources into at most three de-duplicated citation chips. */
function sources_from(mixed $resources): array
{
    $sources = [];
    foreach (is_array($resources) ? $resources : [] as $resource) {
        $title = preg_replace('/\.(pdf|docx?|txt|md|markdown|csv|xlsx?|html?)$/i', '', (string) ($resource['document_name'] ?? ''));
        $title = trim(str_replace('_', ' ', $title));
        if ($title === '') {
            continue;
        }
        $page = isset($resource['page']) && is_numeric($resource['page']) ? (int) $resource['page'] : null;
        $section = isset($resource['segment_position']) && is_numeric($resource['segment_position']) ? (int) $resource['segment_position'] : null;
        $key = "$title|$page|$section";
        if (isset($sources[$key])) {
            continue;
        }
        $snippet = trim(preg_replace('/\s+/u', ' ', (string) ($resource['content'] ?? '')) ?? '');
        if (preg_match_all('/./su', $snippet) > 240) {
            preg_match('/^.{0,237}/su', $snippet, $m);
            $snippet = rtrim($m[0]) . '…';
        }
        $sources[$key] = ['title' => $title, 'page' => $page, 'section' => $section, 'snippet' => $snippet];
        if (count($sources) === 3) {
            break;
        }
    }
    return array_values($sources);
}

function debug_detail(string $detail): string
{
    return app_config()['debug'] && $detail !== '' ? " [debug: $detail]" : '';
}
