<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Testing;

use Closure;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Agents;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;

/**
 * A FakeProvider scripted from a scenario file, for end-to-end tests that
 * drive a real control panel without spending tokens. The addons stand one
 * in only on a local site that has turned it on, and only for a request
 * that names a scenario (the X-Ghostwriter-Fake header or the
 * ghostwriter_fake cookie), or a queued job pushed by one.
 *
 *     $fake = FakeScenario::load($dir, 'statamic/write-article#run42', fn (string $key) => $cache->increment($key) - 1);
 *     $providers->fake($fake);
 *
 * The value is `<name>` or `<name>#<run>`. The name is a path under $dir
 * without `.json`; the run keeps one test's place in each agent's script
 * apart from another's. Each agent's replies are handed out in order across
 * requests and queued jobs, so $next must count in shared storage (a
 * cache): it is given a key and returns how many times it was given that
 * key before. The last reply keeps being given once the others are used.
 *
 * A scenario file is JSON:
 *
 *     {
 *       "description": "What the scenario stands for.",
 *       "fallback": "schema",
 *       "agents": {
 *         "brief-filler": [{"schema": true, "merge": {"title": "Winter care", "examples": "$all"}}],
 *         "writer": [{"textFile": "drafts/questions.txt"}, {"textFile": "drafts/article.txt", "delay": 1500}],
 *         "reviewer": [{"structured": {"suggestions": []}}],
 *         "verifier": [{"fail": "The model is overloaded."}]
 *       }
 *     }
 *
 * Each reply is one of:
 * - `text`: the reply as written (a list of strings is joined with new lines);
 * - `textFile`: the reply read from a file beside the scenario;
 * - `structured`: an object, sent back as its JSON;
 * - `schema`: made up from the request's schema (SchemaFaker), with
 *   `merge` laid over it; in `merge`, `"$all"` for a list of set values
 *   gives every value the schema allows (up to its maxItems), `"$first"`
 *   the first, and a `"*"` key stands for every property of that object
 *   it doesn't name (except those with set values);
 * - `fail`: the call throws a ProviderException with this message.
 *
 * Any reply may wait `delay` milliseconds first (at most 10000), so a test
 * can see a running state. When the folder has a `.requests` folder in it,
 * each request is written there as a line of JSON (agent, prompt,
 * instructions), in `<name with / as -->#<run>.jsonl`, for writing
 * scenarios and reading failed tests. An agent the file doesn't list is answered from
 * its request's schema (`"fallback": "schema"`, the default) or fails
 * (`"fallback": "fail"`).
 */
final class FakeScenario
{
    /** The request header that names a scenario. */
    public const HEADER = 'X-Ghostwriter-Fake';

    /** The cookie that names one, where a header can't be set. */
    public const COOKIE = 'ghostwriter_fake';

    /** A scenario name: lower-case path segments, never `..`. */
    private const NAME = '~^[a-z0-9][a-z0-9_-]*(?:/[a-z0-9][a-z0-9_-]*)*$~';

    private const RUN = '~^[A-Za-z0-9_-]{1,64}$~';

    private const MAX_DELAY = 10000;

    /**
     * The scenario a header or cookie names, or null when the value isn't
     * one (any other value is ignored, never read as a path).
     *
     * @return array{name: string, run: string}|null
     */
    public static function parse(?string $value): ?array
    {
        $value = trim((string) $value);

        if ($value === '' || strlen($value) > 200) {
            return null;
        }

        [$name, $run] = str_contains($value, '#') ? explode('#', $value, 2) : [$value, 'default'];

        if (preg_match(self::NAME, $name) !== 1 || preg_match(self::RUN, $run) !== 1) {
            return null;
        }

        return ['name' => $name, 'run' => $run];
    }

    /**
     * The scenario's file under $dir, or null when the value names none.
     */
    public static function path(string $dir, ?string $value): ?string
    {
        $parsed = self::parse($value);
        $root = realpath($dir);

        if ($parsed === null || $root === false) {
            return null;
        }

        $file = realpath($root.'/'.$parsed['name'].'.json');

        return $file !== false && str_starts_with($file, $root.DIRECTORY_SEPARATOR) ? $file : null;
    }

    /**
     * A FakeProvider that plays the scenario.
     *
     * @param  Closure(string): int  $next  Given a counter key, how many times it was given it before (0 the first time).
     *
     * @throws InvalidArgumentException when the value names no scenario file, or the file can't be read.
     */
    public static function load(string $dir, string $value, Closure $next): FakeProvider
    {
        $file = self::path($dir, $value);
        $parsed = self::parse($value);

        if ($file === null || $parsed === null) {
            throw new InvalidArgumentException("No Ghostwriter fake scenario \"{$value}\" in {$dir}.");
        }

        $data = json_decode((string) file_get_contents($file), true);

        if (! is_array($data) || ! is_array($data['agents'] ?? [])) {
            throw new InvalidArgumentException("The fake scenario {$file} isn't a JSON object with \"agents\".");
        }

        $fake = new FakeProvider;
        $base = dirname($file);
        $prefix = 'ghostwriter-fake:'.$parsed['name'].'#'.$parsed['run'].':';
        $agents = (array) ($data['agents'] ?? []);
        // With a .requests folder beside the scenarios, every request is written down there.
        $root = (string) realpath($dir);
        $log = is_dir($root.'/.requests') ? $root.'/.requests/'.str_replace('/', '--', $parsed['name']).'#'.$parsed['run'].'.jsonl' : null;

        foreach ($agents as $agent => $replies) {
            $replies = array_values(array_filter(is_array($replies) && array_is_list($replies) ? $replies : [$replies], 'is_array'));

            if ($replies === []) {
                continue;
            }

            $fake->respond((string) $agent, function (TextRequest $request) use ($replies, $next, $prefix, $base, $log): string {
                $index = min(max(0, $next($prefix.$request->agent)), count($replies) - 1);
                self::record($log, $request, $index);

                return self::answer($replies[$index], $request, $base);
            });
        }

        $fallback = ($data['fallback'] ?? 'schema') === 'fail' ? ['fail' => 'The fake scenario has no reply for this agent.'] : ['schema' => true];

        foreach (array_keys(Agents::MAX_TOKENS) as $agent) {
            if (! array_key_exists($agent, $agents)) {
                $fake->respond($agent, function (TextRequest $request) use ($fallback, $base, $log): string {
                    self::record($log, $request, null);

                    return self::answer($fallback, $request, $base);
                });
            }
        }

        return $fake;
    }

    /**
     * One line of JSON per request, for writing scenarios and reading a failed test.
     */
    private static function record(?string $log, TextRequest $request, ?int $reply): void
    {
        if ($log === null) {
            return;
        }

        $line = json_encode([
            'agent' => $request->agent,
            'reply' => $reply,
            'schema' => $request->schema?->name,
            'prompt' => $request->prompt,
            'history' => count($request->history),
            'instructions' => $request->instructions,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        @file_put_contents($log, $line."\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * @param  array<string, mixed>  $reply
     *
     * @throws ProviderException
     */
    private static function answer(array $reply, TextRequest $request, string $base): string
    {
        $delay = is_numeric($reply['delay'] ?? null) ? (int) $reply['delay'] : 0;

        if ($delay > 0) {
            usleep(min($delay, self::MAX_DELAY) * 1000);
        }

        if (isset($reply['fail'])) {
            throw new ProviderException(is_string($reply['fail']) ? $reply['fail'] : 'The fake scenario failed this call.', 'fake');
        }

        if (array_key_exists('text', $reply)) {
            return is_array($reply['text']) ? implode("\n", array_map('strval', $reply['text'])) : (string) $reply['text'];
        }

        if (is_string($reply['textFile'] ?? null)) {
            $root = realpath($base);
            $file = realpath($base.'/'.$reply['textFile']);

            if ($root === false || $file === false || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR)) {
                throw new ProviderException("The fake scenario's file \"{$reply['textFile']}\" isn't there.", 'fake');
            }

            return (string) file_get_contents($file);
        }

        if (array_key_exists('structured', $reply)) {
            return (string) json_encode($reply['structured'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if ($request->schema === null) {
            return '{}';
        }

        $data = SchemaFaker::fake($request->schema);

        if (is_array($reply['merge'] ?? null)) {
            $data = self::merge($data, $reply['merge'], $request->schema->schema, $request->schema->schema);
        }

        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * $over laid on $data: objects key by key, anything else replaced.
     *
     * @param  array<string, mixed>  $node  The schema of $data.
     * @param  array<string, mixed>  $root
     */
    private static function merge(mixed $data, mixed $over, array $node, array $root): mixed
    {
        $node = self::resolve($node, $root);

        if ($over === '$all' || $over === '$first') {
            $items = is_array($node['items'] ?? null) ? self::resolve($node['items'], $root) : [];
            $values = is_array($items['enum'] ?? null) ? array_values(array_filter($items['enum'], fn ($v) => $v !== '')) : [];

            if (is_int($node['maxItems'] ?? null)) {
                $values = array_slice($values, 0, $node['maxItems']);
            }

            return $over === '$first' ? array_slice($values, 0, 1) : $values;
        }

        if (! is_array($over) || array_is_list($over) || ! is_array($data)) {
            return $over;
        }

        $properties = is_array($node['properties'] ?? null) ? $node['properties'] : [];

        // "*" stands for every property not named, apart from those with set values.
        if (array_key_exists('*', $over)) {
            foreach ($properties as $key => $property) {
                $property = is_array($property) ? self::resolve($property, $root) : [];

                if (! array_key_exists((string) $key, $over) && ! isset($property['enum']) && ! isset($property['const'])) {
                    $over[(string) $key] = $over['*'];
                }
            }

            unset($over['*']);
        }

        foreach ($over as $key => $value) {
            $data[$key] = self::merge($data[$key] ?? null, $value, is_array($properties[$key] ?? null) ? $properties[$key] : [], $root);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $root
     * @return array<string, mixed>
     */
    private static function resolve(array $node, array $root): array
    {
        for ($depth = 0; $depth < 8 && is_string($node['$ref'] ?? null) && str_starts_with($node['$ref'], '#/'); $depth++) {
            $target = $root;

            foreach (explode('/', substr($node['$ref'], 2)) as $segment) {
                $target = is_array($target) ? ($target[$segment] ?? null) : null;
            }

            $node = is_array($target) ? $target : [];
        }

        return $node;
    }
}
