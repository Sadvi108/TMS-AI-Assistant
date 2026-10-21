<?php

declare(strict_types=1);

final class DifyException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, public readonly string $difyCode = '')
    {
        parent::__construct($message);
    }
}

/**
 * Minimal client for the Dify chat app API (Chatbot, Agent and Chatflow apps).
 * Docs: https://docs.dify.ai/guides/application-publishing/developing-with-apis
 */
final class DifyClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $timeout = 180,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * POST /chat-messages in streaming mode. $onEvent receives each decoded
     * SSE event; return false from it to cancel the request.
     */
    public function streamChat(array $payload, callable $onEvent): void
    {
        $payload['response_mode'] = 'streaming';
        $status = 0;
        $buffer = '';
        $errorBody = '';
        $cancelled = false;

        $ch = curl_init($this->baseUrl . '/chat-messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $this->headers(['Accept: text/event-stream']),
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HEADERFUNCTION => function ($ch, string $header) use (&$status): int {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                    $status = (int) $m[1];
                }
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$status, &$buffer, &$errorBody, &$cancelled, $onEvent): int {
                if ($status !== 200) {
                    $errorBody .= $chunk;
                    return strlen($chunk);
                }
                $buffer .= str_replace("\r\n", "\n", $chunk);
                while (($end = strpos($buffer, "\n\n")) !== false) {
                    $event = self::parseSseBlock(substr($buffer, 0, $end));
                    $buffer = substr($buffer, $end + 2);
                    if ($event !== null && $onEvent($event) === false) {
                        $cancelled = true;
                        return 0; // makes curl abort the transfer
                    }
                }
                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($ch);
        $curlError = curl_error($ch);

        if ($cancelled) {
            return;
        }
        if ($status !== 200 && $status !== 0) {
            throw self::errorFromBody($errorBody, $status);
        }
        if ($ok === false) {
            throw new DifyException('Could not reach Dify: ' . $curlError);
        }
        $event = self::parseSseBlock($buffer);
        if ($event !== null) {
            $onEvent($event);
        }
    }

    /** POST /messages/{id}/feedbacks — $rating is "like", "dislike" or null to clear. */
    public function feedback(string $messageId, ?string $rating, string $user): void
    {
        $this->postJson('/messages/' . rawurlencode($messageId) . '/feedbacks', [
            'rating' => $rating,
            'user' => $user,
        ]);
    }

    /** POST /chat-messages/{task_id}/stop */
    public function stop(string $taskId, string $user): void
    {
        $this->postJson('/chat-messages/' . rawurlencode($taskId) . '/stop', ['user' => $user]);
    }

    private function postJson(string $path, array $body): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $this->headers(),
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);

        if ($response === false) {
            throw new DifyException('Could not reach Dify: ' . $curlError);
        }
        if ($status < 200 || $status >= 300) {
            throw self::errorFromBody($response, $status);
        }
        $data = json_decode($response, true);
        return is_array($data) ? $data : [];
    }

    private function headers(array $extra = []): array
    {
        return array_merge([
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
        ], $extra);
    }

    /** Dify sends "data: {json}" blocks; pings and comments carry no data. */
    private static function parseSseBlock(string $block): ?array
    {
        $data = '';
        foreach (explode("\n", $block) as $line) {
            if (str_starts_with($line, 'data:')) {
                $data .= ltrim(substr($line, 5));
            }
        }
        if ($data === '') {
            return null;
        }
        $event = json_decode($data, true);
        return is_array($event) ? $event : null;
    }

    private static function errorFromBody(string $body, int $status): DifyException
    {
        $data = json_decode($body, true);
        $message = is_array($data) ? (string) ($data['message'] ?? 'Unknown error') : trim(strip_tags($body));
        $code = is_array($data) ? (string) ($data['code'] ?? '') : '';
        return new DifyException($message !== '' ? $message : "HTTP $status", $status, $code);
    }
}
