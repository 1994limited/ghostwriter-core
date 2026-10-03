<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Layout\Testing;

use JsonException;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Plan;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Plans;
use NineteenNinetyFour\Ghostwriter\Core\Layout\BuiltEntry;
use NineteenNinetyFour\Ghostwriter\Core\Layout\FoundKind;
use NineteenNinetyFour\Ghostwriter\Core\Layout\HouseResult;
use NineteenNinetyFour\Ghostwriter\Core\Layout\HouseRules;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Pattern;
use RuntimeException;

/**
 * What the layout algorithms gave during a test suite, written down so two
 * runs can be compared: an addon's suite on its own layout classes, then on
 * core's. See docs/layout.md, "Checking parity".
 *
 *     // in the addon's TestCase::setUp(), with the log's path in an env var
 *     LayoutLog::start(getenv('GHOSTWRITER_RECORD_LAYOUTS') ?: null, static::class.'::'.$this->name());
 *
 *     // wherever the addon calls a layout algorithm
 *     LayoutLog::record('pattern', $pattern);
 *
 *     bin/compare-layouts before.jsonl after.jsonl
 *
 * Outputs are recorded in the addons' array shapes (Pattern::toArray() and
 * the like), so a log from before core and one from after can be compared.
 * Layouts are recorded too: `LayoutLog::record('plans', $plans)` and
 * `LayoutLog::record('arrange', $arrangedDraft)`, so each addon's arranged
 * output for the same plans can be compared with the others'.
 * The IDs Statamic makes at random for new blocks and rows (eight hex
 * characters under an `id` key) are compared as "<id>", and UUIDs (a test
 * suite's entry IDs, new on every run) as "<uuid>".
 */
final class LayoutLog
{
    private static ?string $path = null;

    private static string $test = '';

    /**
     * Record from here on into the log at $path, under this test's name.
     * A null path records nothing.
     */
    public static function start(?string $path, string $test): void
    {
        self::$path = $path === '' ? null : $path;
        self::$test = $test;
    }

    public static function stop(): void
    {
        self::$path = null;
        self::$test = '';
    }

    /**
     * Add one output to the log, when recording.
     *
     * @throws JsonException
     */
    public static function record(string $algorithm, mixed $output): void
    {
        if (self::$path === null) {
            return;
        }

        $line = json_encode(['test' => self::$test, 'algorithm' => $algorithm, 'output' => self::export($output)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);

        if (file_put_contents(self::$path, $line."\n", FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Could not write to '.self::$path.'.');
        }
    }

    /**
     * Core's results in the shapes the addons' arrays had.
     */
    public static function export(mixed $output): mixed
    {
        return match (true) {
            $output instanceof Pattern, $output instanceof HouseRules, $output instanceof FoundKind, $output instanceof BuiltEntry, $output instanceof Plan, $output instanceof Plans => $output->toArray(),
            $output instanceof HouseResult => ['data' => $output->data, 'toFill' => $output->toFill],
            is_array($output) => array_map(self::export(...), $output),
            default => $output,
        };
    }

    /**
     * A log read back: each test's outputs, in order, by test name.
     *
     * @return array<string, array<int, array{algorithm: string, output: mixed}>>
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

            if (is_array($entry) && is_string($entry['test'] ?? null) && is_string($entry['algorithm'] ?? null)) {
                $tests[$entry['test']][] = ['algorithm' => $entry['algorithm'], 'output' => $entry['output'] ?? null];
            }
        }

        return $tests;
    }

    /**
     * What differs between two logs, as lines for a person to read: tests
     * only in one, a different run of algorithms, and each output that
     * differs, with the first point where they part.
     *
     * @param  array<string, array<int, array{algorithm: string, output: mixed}>>  $before
     * @param  array<string, array<int, array{algorithm: string, output: mixed}>>  $after
     * @param  array<int, string>  $ignore  Algorithms not to compare, e.g. ['kinds'].
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
            $was = array_values(array_filter($before[$test], fn (array $entry) => ! in_array($entry['algorithm'], $ignore, true)));
            $now = array_values(array_filter($after[$test], fn (array $entry) => ! in_array($entry['algorithm'], $ignore, true)));

            if (array_column($was, 'algorithm') !== array_column($now, 'algorithm')) {
                $out[] = sprintf('%s: %s, then %s', $test, implode(', ', array_column($was, 'algorithm')) ?: 'nothing', implode(', ', array_column($now, 'algorithm')) ?: 'nothing');

                continue;
            }

            foreach ($was as $i => $entry) {
                $a = self::encode(self::normalise($entry['output']));
                $b = self::encode(self::normalise($now[$i]['output']));

                if ($a !== $b) {
                    $out[] = sprintf('%s: %s %d %s', $test, $entry['algorithm'], $i + 1, self::difference($a, $b));
                }
            }
        }

        return $out;
    }

    /**
     * Random IDs made comparable: an `id` of eight hex characters is "<id>",
     * and a UUID anywhere in a string is "<uuid>".
     */
    public static function normalise(mixed $value): mixed
    {
        if (is_string($value)) {
            return preg_replace('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', '<uuid>', $value) ?? $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $key === 'id' && is_string($item) && preg_match('/^[0-9a-f]{8}$/', $item) ? '<id>' : self::normalise($item);
        }

        return $value;
    }

    private static function encode(mixed $value): string
    {
        return is_string($value) ? $value : (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function difference(string $was, string $now): string
    {
        $at = 0;
        $length = min(strlen($was), strlen($now));

        while ($at < $length && $was[$at] === $now[$at]) {
            $at++;
        }

        $line = substr_count(substr($was, 0, $at), "\n") + 1;
        $show = fn (string $text) => json_encode(substr($text, max(0, $at - 30), 90), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return "differs from line {$line}: {$show($was)} became {$show($now)}";
    }
}
