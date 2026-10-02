<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Studio\Testing;

use JsonException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Message;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use RuntimeException;

/**
 * The text requests a test suite made, written down so two runs can be
 * compared: an addon's suite on its own Studio, then on core's. See
 * docs/studio.md, "Checking parity".
 *
 * A request is recorded with the values a provider would actually send:
 * the token limit and effort after the Agents defaults, and images as
 * their type, size and a hash rather than their bytes.
 *
 *     // in the addon's TestCase::tearDown()
 *     if ($path = getenv('GHOSTWRITER_RECORD_REQUESTS')) {
 *         RequestLog::append($path, static::class.'::'.$this->name(), $fake->requests());
 *     }
 *
 *     bin/compare-requests before.jsonl after.jsonl
 */
final class RequestLog
{
    /**
     * @return array{agent: string, instructions: string, prompt: string, history: array<int, array{role: string, content: string}>, images: array<int, array{mime: string, bytes: int, sha1: string}>, maxTokens: int, effort: ?string, model: ?string, timeout: ?int}
     */
    public static function record(TextRequest $request): array
    {
        return [
            'agent' => $request->agent,
            'instructions' => $request->instructions,
            'prompt' => $request->prompt,
            'history' => array_map(fn (Message $message) => ['role' => $message->role, 'content' => $message->content], array_values($request->history)),
            'images' => array_map(fn ($image) => ['mime' => $image->mime, 'bytes' => strlen($image->data), 'sha1' => sha1($image->data)], array_values($request->images)),
            'maxTokens' => $request->resolvedMaxTokens(),
            'effort' => $request->resolvedEffort()?->value,
            'model' => $request->model,
            'timeout' => $request->timeout,
        ];
    }

    /**
     * @param  array<int, TextRequest>  $requests
     * @return array<int, array<string, mixed>>
     */
    public static function records(array $requests): array
    {
        return array_values(array_map(self::record(...), $requests));
    }

    /**
     * Add one test's requests to a log, one JSON line per test. Tests that
     * made none are skipped.
     *
     * @param  array<int, TextRequest>  $requests
     *
     * @throws JsonException
     */
    public static function append(string $path, string $test, array $requests): void
    {
        if ($requests === []) {
            return;
        }

        $line = json_encode(['test' => $test, 'requests' => self::records($requests)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if (file_put_contents($path, $line."\n", FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException("Could not write to {$path}.");
        }
    }

    /**
     * A log read back: each test's requests, by test name.
     *
     * @return array<string, array<int, array<string, mixed>>>
     *
     * @throws RuntimeException when the file can't be read.
     */
    public static function read(string $path): array
    {
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            throw new RuntimeException("Could not read {$path}.");
        }

        $tests = [];

        foreach ($lines as $line) {
            $entry = json_decode($line, true);

            if (is_array($entry) && is_string($entry['test'] ?? null) && is_array($entry['requests'] ?? null)) {
                $tests[$entry['test']] = array_values(array_filter($entry['requests'], 'is_array'));
            }
        }

        return $tests;
    }

    /**
     * What differs between two logs, as lines for a person to read: tests
     * only in one, a different number of requests, and each field that
     * differs, with the first point where the texts part.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $before
     * @param  array<string, array<int, array<string, mixed>>>  $after
     * @param  array<int, string>  $ignore  Fields not to compare, e.g. ['maxTokens'].
     * @return array<int, string>
     */
    public static function compare(array $before, array $after, array $ignore = []): array
    {
        $out = [];

        foreach (array_diff(array_keys($before), array_keys($after)) as $test) {
            $out[] = "{$test}: only in the first log";
        }

        foreach (array_diff(array_keys($after), array_keys($before)) as $test) {
            $out[] = "{$test}: only in the second log";
        }

        foreach (array_intersect(array_keys($before), array_keys($after)) as $test) {
            if (count($before[$test]) !== count($after[$test])) {
                $out[] = sprintf('%s: %d request(s), then %d (%s, then %s)', $test, count($before[$test]), count($after[$test]), self::agents($before[$test]), self::agents($after[$test]));

                continue;
            }

            foreach ($before[$test] as $i => $was) {
                $now = $after[$test][$i];

                foreach (array_unique([...array_keys($was), ...array_keys($now)]) as $field) {
                    if (in_array($field, $ignore, true) || ($was[$field] ?? null) === ($now[$field] ?? null)) {
                        continue;
                    }

                    $out[] = sprintf('%s: request %d (%s), %s: %s', $test, $i + 1, is_string($was['agent'] ?? null) ? $was['agent'] : '?', $field, self::difference($was[$field] ?? null, $now[$field] ?? null));
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $requests
     */
    private static function agents(array $requests): string
    {
        return implode(', ', array_map(fn (array $request) => is_string($request['agent'] ?? null) ? $request['agent'] : '?', $requests)) ?: 'none';
    }

    private static function difference(mixed $was, mixed $now): string
    {
        $was = is_string($was) ? $was : (string) json_encode($was, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $now = is_string($now) ? $now : (string) json_encode($now, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $at = 0;
        $length = min(strlen($was), strlen($now));

        while ($at < $length && $was[$at] === $now[$at]) {
            $at++;
        }

        $line = substr_count(substr($was, 0, $at), "\n") + 1;
        $show = fn (string $text) => json_encode(substr($text, max(0, $at - 20), 80), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return "differs from line {$line}: {$show($was)} became {$show($now)}";
    }
}
