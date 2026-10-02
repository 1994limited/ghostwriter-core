<?php

/*
 * Writes what an addon's layout classes were given and gave (see
 * make-hooks.php): every call to $GOLDEN_LOG, and the outputs alone, as
 * LayoutLog lines, to $GHOSTWRITER_RECORD_LAYOUTS.
 */
final class GoldenRecorder
{
    public static ?string $context = null;

    public static function record(string $algorithm, array $payload): void
    {
        self::layoutLog($algorithm, $payload);
        $file = getenv('GOLDEN_LOG');

        if (! $file) {
            return;
        }

        $head = ['algorithm' => $algorithm, 'addon' => getenv('GOLDEN_ADDON') ?: '', 'context' => self::context()];
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

        try {
            $line = json_encode($head + $payload, $flags);
        } catch (JsonException $e) {
            $line = json_encode($head + ['error' => $e->getMessage()], $flags);
        }

        file_put_contents($file, $line."\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * The output as core's LayoutLog records it at the adapter's call sites.
     * `learn` runs inside the pattern finder, so it is not an adapter's call.
     */
    private static function layoutLog(string $algorithm, array $payload): void
    {
        $file = getenv('GHOSTWRITER_RECORD_LAYOUTS');

        if (! $file || $algorithm === 'learn') {
            return;
        }

        $output = $algorithm === 'apply'
            ? ['data' => $payload['output'], 'toFill' => array_values(array_slice($payload['toFill'], count($payload['toFillBefore'])))]
            : $payload['output'];

        $line = json_encode(['test' => self::context(), 'algorithm' => $algorithm, 'output' => $output], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);

        file_put_contents($file, $line."\n", FILE_APPEND | LOCK_EX);
    }

    private static function context(): string
    {
        if (self::$context !== null) {
            return self::$context;
        }

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (isset($frame['class']) && str_contains(strtolower($frame['class']), 'tests\\') && str_starts_with($frame['function'], 'test')) {
                return $frame['class'].'::'.$frame['function'];
            }
        }

        return '';
    }
}
