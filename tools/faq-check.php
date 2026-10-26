<?php

/*
 * Sends every question in a CSV (question,answer) through the running app's
 * api/chat.php — one fresh conversation each — and reports how it was answered.
 *
 *   php tools/faq-check.php [--base=http://localhost:8000] [--lang=en] [--only=3,17] [--gap=3.2] tools/faq.csv
 *
 * --suggestions asks the site's suggested questions (src/topics.php, English and
 * Bahasa Malaysia) instead, after checking each English one is worded as in the CSV.
 *
 * Full results go to storage/faq-report.json. Exit code 1 if any question errored.
 */

declare(strict_types=1);

$opts = getopt('', ['base::', 'lang::', 'only::', 'gap::', 'suggestions'], $rest);
$file = $argv[$rest] ?? __DIR__ . '/faq.csv';
$base = rtrim($opts['base'] ?? 'http://localhost:8000', '/');
$lang = $opts['lang'] ?? 'en';
$only = isset($opts['only']) ? array_map('intval', explode(',', $opts['only'])) : null;
// Seconds between questions: api/chat.php allows 20 a minute per IP; Dify Cloud's free plan
// allows about 10 knowledge lookups a minute, so use --gap=6.5 against it.
$gap = (float) ($opts['gap'] ?? 3.2);

$rows = [];
$handle = fopen($file, 'r');
fgetcsv($handle, null, ',', '"', ''); // header
while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
    if (($row[0] ?? '') !== '') {
        $rows[] = [$row[0], $row[1] ?? ''];
    }
}

if (isset($opts['suggestions'])) {
    $faq = array_column($rows, 1, 0);
    $rows = [];
    foreach ((require __DIR__ . '/../src/topics.php')['topics'] as $topic) {
        foreach ($topic['questions'] as $q) {
            if (!isset($faq[$q['en']])) {
                echo "Not worded as in the FAQ, so it won't be Verified: {$q['en']}\n";
            }
            $rows[] = [$q['en'], $faq[$q['en']] ?? ''];
            $rows[] = [$q['ms'], $faq[$q['en']] ?? ''];
        }
    }
}
$results = [];
$lastStart = 0.0;

foreach ($rows as $i => [$question, $expected]) {
    $n = $i + 1;
    if ($only !== null && !in_array($n, $only, true)) {
        continue;
    }
    usleep((int) max(0, ($lastStart + $gap - microtime(true)) * 1e6));
    $lastStart = microtime(true);

    $r = ask($base, $question, $lang);
    $r['n'] = $n;
    $r['question'] = $question;
    $r['expected'] = $expected;
    $r['score'] = overlap($expected, preg_replace('/<think>.*?<\/think>/s', '', $r['answer']));
    $r['kind'] = kind($r);
    $results[] = $r;

    printf("%2d  %-8s %4s  %5.1fs  %s\n    → %s\n", $n, $r['kind'], $r['kind'] === 'ERROR' ? '' : round($r['score'] * 100) . '%',
        $r['seconds'], $question, preview($r['error'] ?: $r['answer']));
}

$counts = array_count_values(array_column($results, 'kind'));
$low = array_filter($results, fn($r) => in_array($r['kind'], ['DOCS', 'PLAIN'], true) && $r['score'] < 0.4);
$times = array_column($results, 'seconds');
sort($times);

echo "\n", str_repeat('-', 60), "\n";
foreach (['VERIFIED', 'DOCS', 'PLAIN', 'HANDOFF', 'EMPTY', 'ERROR'] as $k) {
    printf("%-9s %d\n", $k, $counts[$k] ?? 0);
}
printf("Low overlap with the expected answer (<40%%): %s\n", $low ? implode(', ', array_column($low, 'n')) : 'none');
if ($times) {
    printf("Time per answer: median %.1fs, slowest %.1fs\n", $times[intdiv(count($times), 2)], end($times));
}

@file_put_contents(__DIR__ . '/../storage/faq-report.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
exit(($counts['ERROR'] ?? 0) > 0 ? 1 : 0);

/** Streams one question through api/chat.php and collects the events. */
function ask(string $base, string $question, string $lang): array
{
    $r = ['answer' => '', 'verified' => false, 'sources' => [], 'handoff' => false, 'error' => '', 'first_token' => null, 'seconds' => 0.0];
    $start = microtime(true);
    $buffer = '';
    $ch = curl_init("$base/api/chat.php");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['query' => $question, 'lang' => $lang, 'conversation_id' => '']),
        CURLOPT_TIMEOUT => 180,
        CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$buffer, &$r, $start): int {
            $buffer .= $chunk;
            while (($nl = strpos($buffer, "\n")) !== false) {
                $event = json_decode(substr($buffer, 0, $nl), true);
                $buffer = substr($buffer, $nl + 1);
                switch ($event['type'] ?? null) {
                    case 'delta':
                        $r['answer'] .= $event['text'];
                        $r['first_token'] ??= round(microtime(true) - $start, 2);
                        break;
                    case 'replace':
                        $r['answer'] = $event['text'];
                        break;
                    case 'end':
                        $r['verified'] = $event['verified'];
                        $r['sources'] = $event['sources'];
                        $r['handoff'] = $event['handoff'];
                        break;
                    case 'error':
                        $r['error'] = $event['message'];
                        break;
                }
            }
            return strlen($chunk);
        },
    ]);
    curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($status !== 200) {
        $r['error'] = "HTTP $status " . (json_decode($buffer, true)['error'] ?? trim($buffer) ?: curl_error($ch));
    }
    $r['seconds'] = round(microtime(true) - $start, 2);
    return $r;
}

function kind(array $r): string
{
    return match (true) {
        $r['error'] !== '' => 'ERROR',
        $r['handoff'] => 'HANDOFF',
        trim(preg_replace('/<think>.*?<\/think>/s', '', $r['answer'])) === '' => 'EMPTY',
        $r['verified'] => 'VERIFIED',
        $r['sources'] !== [] => 'DOCS',
        default => 'PLAIN',
    };
}

/** Share of the expected answer's meaningful words that appear in the actual answer. */
function overlap(string $expected, string $actual): float
{
    static $stop = ['the', 'a', 'an', 'and', 'or', 'of', 'to', 'in', 'for', 'is', 'are', 'be', 'it', 'its', 'if', 'on', 'at', 'by', 'with', 'your', 'you', 'that', 'this', 'may', 'can', 'any', 'as', 'from', 'such', 'which', 'when', 'where', 'there', 'their'];
    $words = fn(string $s) => array_diff(array_unique(preg_split('/[^a-z0-9]+/', strtolower($s), -1, PREG_SPLIT_NO_EMPTY)), $stop);
    $want = $words($expected);
    return $want ? count(array_intersect($want, $words($actual))) / count($want) : 0.0;
}

function preview(string $text): string
{
    $text = trim(preg_replace('/\s+/', ' ', preg_replace('/<think>.*?<\/think>/s', '', $text)));
    return strlen($text) > 160 ? substr($text, 0, 157) . '...' : $text;
}
