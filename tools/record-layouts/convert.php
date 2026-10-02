<?php

/*
 * Turns the recordings into core's golden fixtures: each case's inputs in
 * core's neutral shape (a Schema in Field::toArray() form and EntryData
 * arrays), with the output the addon's own code gave.
 *
 *     php tools/record-layouts/convert.php <core repo> <recordings directory>
 *
 * The recordings directory holds <addon>-tests.jsonl (the addon's suite),
 * <addon>-site.jsonl (sites/<addon>.php) and <addon>-synthetic.jsonl
 * (synthetic.php), for statamic, filament and craft.
 */

use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Layout\Addons;

$core = $argv[1] ?? dirname(__DIR__, 2);
$recordings = $argv[2] ?? getcwd();
require $core.'/vendor/autoload.php';

$out = $core.'/tests/Fixtures/layout';
$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

function schemaOf(array $specs): array
{
    return Schema::fromSpecs($specs)->toArray();
}

function entry(array $values, mixed $id = null, ?string $title = null, ?array $parent = null): array
{
    $entry = ['id' => $id];

    if ($title !== null) {
        $entry['title'] = $title;
    }

    if ($parent !== null) {
        $entry['parent'] = $parent;
    }

    $entry['values'] = $values;

    return $entry;
}

function slug(string $context): string
{
    if (str_starts_with($context, 'synthetic:')) {
        return 'synthetic-'.substr($context, 10);
    }

    if (str_starts_with($context, 'site:')) {
        $context = preg_replace('/#draft\d+$/', '', $context);

        return 'site-'.trim(preg_replace('/[^a-z0-9]+/i', '-', substr($context, 5)), '-');
    }

    $parts = explode('::', $context);
    $class = basename(str_replace('\\', '/', $parts[0]));
    $method = $parts[1] ?? 'unknown';
    $method = preg_replace('/^test_?/', '', $method);
    $method = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $method));

    return strtolower($class).'-'.substr(trim(preg_replace('/[^a-z0-9]+/', '-', $method), '-'), 0, 60);
}

$totals = [];

foreach (['statamic', 'filament', 'craft'] as $addon) {
    $files = [];
    $seen = [];

    foreach (['tests', 'site', 'synthetic'] as $source) {
        $path = "{$recordings}/{$addon}-{$source}.jsonl";

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

            if (isset($record['error'])) {
                fwrite(STDERR, "skipped an unencodable {$record['algorithm']} in {$record['context']}\n");

                continue;
            }

            $schema = schemaOf($record['schema']);

            $case = match ($record['algorithm']) {
                'describe' => ['input' => ['pattern' => $record['pattern']], 'expected' => $record['output']],
                'pattern' => [
                    'input' => ['entries' => array_map(fn ($values, $i) => entry($values, $record['ids'][$i] ?? null), $record['data'], array_keys($record['data']))],
                    'expected' => $record['output'],
                ],
                'learn' => [
                    'input' => ['entries' => array_map(fn ($values, $i) => entry($values, $record['ids'][$i] ?? null), $record['entries'], array_keys($record['entries']))],
                    'expected' => $record['output'],
                ],
                'kinds' => [
                    'input' => ['entries' => array_map(function ($entry) {
                        $parent = $entry['parent'] ?? null;

                        // The home page every top-level page sits under is no parent to name.
                        $parent = $parent && ! ($parent['root'] ?? false) ? ['id' => $parent['id'], 'title' => $parent['title']] : null;

                        return entry($entry['data'] ?? [], $entry['id'], $entry['title'], $parent);
                    }, $record['entries'])],
                    'expected' => $record['output'],
                ],
                'apply' => [
                    'input' => ['data' => $record['data'], 'style' => $record['style'], 'self' => $record['self']],
                    'expected' => ['data' => $record['output'], 'toFill' => array_values(array_slice($record['toFill'], count($record['toFillBefore'])))],
                ],
                'linkToSelf' => [
                    'input' => ['data' => $record['data'], 'style' => $record['style'], 'id' => $record['id'], 'title' => $record['title']],
                    'expected' => $record['output'],
                ],
                'build' => [
                    'input' => ['draft' => $record['draft'], 'pattern' => $record['pattern'], 'defaults' => $record['defaults']],
                    'expected' => $record['output'],
                ],
            };

            $hash = sha1(json_encode([$record['algorithm'], $schema, $case['input']]));

            if (isset($seen[$hash])) {
                if (json_encode($seen[$hash]) !== json_encode($case['expected'])) {
                    // Random IDs (Statamic) make the same input give different
                    // outputs; the test normalises them, so either will do.
                }

                continue;
            }

            $seen[$hash] = $case['expected'];
            $file = slug($record['context']);
            $schemaKey = substr(sha1(json_encode($schema)), 0, 12);

            $files[$file]['source'] ??= match ($source) {
                'site' => 'Northfold test site: '.preg_replace('/#draft\d+$/', '', substr($record['context'], 5)),
                'synthetic' => 'Inputs made to tell the addons apart, run through the addon\'s own code: '.substr($record['context'], 10),
                default => 'Test: '.$record['context'],
            };
            $files[$file]['schemas'][$schemaKey] = $schema;
            $files[$file]['cases'][] = ['algorithm' => $record['algorithm'], 'schema' => $schemaKey] + $case;
            $totals[$addon][$record['algorithm']] = ($totals[$addon][$record['algorithm']] ?? 0) + 1;
        }
    }

    @mkdir("$out/$addon", 0777, true);
    array_map('unlink', glob("$out/$addon/*.json") ?: []);

    foreach ($files as $name => $file) {
        file_put_contents("$out/$addon/$name.json", json_encode(['addon' => $addon] + $file, $flags)."\n");
    }
}

foreach ($totals as $addon => $counts) {
    ksort($counts);
    echo $addon.': '.array_sum($counts).' cases ('.implode(', ', array_map(fn ($k, $v) => "$k $v", array_keys($counts), $counts)).")\n";
}

/*
 * Where core differs on purpose: core's output is stored beside the
 * addon's, with the reason (docs/layout-unification.md).
 */
$deliberate = [
    ['filament', 'synthetic-places-the-pages-disagree-on', 'learn', 'FIL-9: Filament no longer reads `label` and `value` fields as Craft link fields, so a label repeating each record\'s title is not taken for a link to the record itself.'],
];

foreach ($deliberate as [$addon, $name, $algorithm, $reason]) {
    $path = "$out/$addon/$name.json";
    $fixture = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    foreach ($fixture['cases'] as $i => $case) {
        if ($case['algorithm'] !== $algorithm) {
            continue;
        }

        $schema = Schema::fromArray($fixture['schemas'][$case['schema']]);
        $layouts = Addons::layouts($addon);
        $entries = array_map(fn ($entry) => EntryData::fromArray($entry), $case['input']['entries']);

        $fixture['cases'][$i]['deliberate'] = ['reason' => $reason, 'expected' => match ($algorithm) {
            'learn' => $layouts->houseStyle()->learn($entries, $schema)->toArray(),
        }];
    }

    file_put_contents($path, json_encode($fixture, $flags)."\n");
}
