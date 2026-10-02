<?php

/*
 * Records what the three test sites have stored, read only, as the domain
 * round-trip fixtures in tests/Fixtures/domain/<addon>/. Each fixture is
 * {"source": where it came from, "format": the addon, "type": the domain
 * type, "handle": where the record doesn't hold it, "record": the stored
 * shape}. Filament rows are read with PDO, so they keep the types the
 * database gives; Craft's JSON columns and Statamic's files are decoded.
 *
 *     php tools/record-domain/record.php [core repo] [~/Dev]
 *
 * Statamic: storage/ghostwriter/{sessions,images}/*.json, kinds.json,
 * types.json, plan.json, voice.json, imagery.json and
 * resources/ghostwriter/ideas.yaml (gw-test-statamic). Filament: the
 * ghostwriter_* tables in database/database.sqlite (gw-test-filament).
 * Craft: the ghostwriter_* tables in the gw_test_craft MySQL database.
 */

use Symfony\Component\Yaml\Yaml;

$core = $argv[1] ?? dirname(__DIR__, 2);
$dev = $argv[2] ?? dirname($core);
require $core.'/vendor/autoload.php';

$out = $core.'/tests/Fixtures/domain';
$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;
$count = 0;

$write = function (string $addon, string $type, string $name, string $source, array $record, ?string $handle = null) use ($out, $flags, &$count) {
    $dir = "{$out}/{$addon}/{$type}";
    @mkdir($dir, 0777, true);
    $name = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $name);
    $fixture = ['source' => $source, 'format' => $addon, 'type' => $type] + ($handle !== null ? ['handle' => $handle] : []) + ['record' => $record];
    file_put_contents("{$dir}/{$name}.json", json_encode($fixture, $flags)."\n");
    $count++;
};

$json = fn (string $path) => json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

// Statamic -------------------------------------------------------------

$site = "{$dev}/gw-test-statamic";
$storage = "{$site}/storage/ghostwriter";

foreach (glob("{$storage}/sessions/*.json") ?: [] as $path) {
    $write('statamic', 'session', basename($path, '.json'), 'gw-test-statamic storage/ghostwriter/sessions/'.basename($path), $json($path));
}

foreach (glob("{$storage}/images/*.json") ?: [] as $path) {
    $write('statamic', 'image-request', basename($path, '.json'), 'gw-test-statamic storage/ghostwriter/images/'.basename($path), $json($path));
}

foreach (is_file("{$storage}/kinds.json") ? $json("{$storage}/kinds.json") : [] as $group => $state) {
    $write('statamic', 'kind-suggestions', $group, "gw-test-statamic storage/ghostwriter/kinds.json, \"{$group}\"", $state, $group);
}

foreach (is_file("{$storage}/types.json") ? $json("{$storage}/types.json") : [] as $group => $state) {
    $write('statamic', 'analysis', $group, "gw-test-statamic storage/ghostwriter/types.json, \"{$group}\"", $state, $group);
}

foreach (['plan' => 'plan-state', 'voice' => 'guide-state', 'imagery' => 'guide-state'] as $file => $type) {
    if (is_file("{$storage}/{$file}.json")) {
        $write('statamic', $type, $file, "gw-test-statamic storage/ghostwriter/{$file}.json", $json("{$storage}/{$file}.json"), $file);
    }
}

$ideas = is_file("{$site}/resources/ghostwriter/ideas.yaml") ? (array) (Yaml::parse((string) file_get_contents("{$site}/resources/ghostwriter/ideas.yaml"))['ideas'] ?? []) : [];

foreach ($ideas as $i => $idea) {
    $write('statamic', 'idea', (string) ($idea['id'] ?? $i), 'gw-test-statamic resources/ghostwriter/ideas.yaml, item '.($i + 1), $idea);
}

foreach (['voice', 'imagery'] as $kind) {
    if (is_file("{$site}/resources/ghostwriter/{$kind}.md")) {
        $write('statamic', 'guide', $kind, "gw-test-statamic resources/ghostwriter/{$kind}.md", ['body' => (string) file_get_contents("{$site}/resources/ghostwriter/{$kind}.md")], $kind);
    }
}

foreach (glob("{$site}/resources/ghostwriter/types/*.yaml") ?: [] as $path) {
    $write('statamic', 'kind', basename($path, '.yaml'), 'gw-test-statamic resources/ghostwriter/types/'.basename($path), (array) Yaml::parse((string) file_get_contents($path)), basename($path, '.yaml'));
}

// Filament -------------------------------------------------------------

$pdo = new PDO('sqlite:'."{$dev}/gw-test-filament/database/database.sqlite", null, null, [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
$pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
$rows = fn (string $table) => $pdo->query("select * from {$table} order by id")->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows('ghostwriter_sessions') as $row) {
    $write('filament', 'session', $row['ulid'], 'gw-test-filament ghostwriter_sessions row '.$row['id'], $row);
}

foreach ($rows('ghostwriter_ideas') as $row) {
    $write('filament', 'idea', (string) $row['id'], 'gw-test-filament ghostwriter_ideas row '.$row['id'], $row);
}

foreach ($rows('ghostwriter_kinds') as $row) {
    $write('filament', 'kind', $row['handle'], 'gw-test-filament ghostwriter_kinds row '.$row['id'], $row);
}

foreach ($rows('ghostwriter_states') as $row) {
    $value = json_decode((string) $row['value'], true, 512, JSON_THROW_ON_ERROR);
    $tenant = $row['tenant_id'] === '' ? '' : '-tenant-'.$row['tenant_id'];
    [$prefix, $rest] = array_pad(explode(':', $row['key'], 2), 2, '');

    $type = match ($prefix) {
        'kinds' => 'kind-suggestions',
        'image' => 'image-request',
        'plan' => 'plan-state',
        'guide' => 'guide-state',
        'queued' => 'waiting',
        default => null,
    };

    if ($type !== null) {
        $write('filament', $type, ($rest !== '' ? $rest : $prefix).$tenant, "gw-test-filament ghostwriter_states row {$row['id']} ({$row['key']}), the value", $value, $rest !== '' ? $rest : $prefix);
    }
}

// Craft ----------------------------------------------------------------

$mysql = new PDO('mysql:host=127.0.0.1;port=3306;dbname=gw_test_craft', 'root', '');

foreach ($mysql->query('select id, data from ghostwriter_sessions order by dateCreated')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $write('craft', 'session', $row['id'], 'gw-test-craft ghostwriter_sessions row '.$row['id'].', the data column', json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR));
}

foreach ($mysql->query('select kind, handle, body from ghostwriter_documents order by id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $source = "gw-test-craft ghostwriter_documents {$row['kind']}/{$row['handle']}";

    match ($row['kind']) {
        'idea' => $write('craft', 'idea', $row['handle'], $source.', the body', json_decode($row['body'], true, 512, JSON_THROW_ON_ERROR), $row['handle']),
        'type' => $write('craft', 'kind', $row['handle'], $source.', the body as YAML', (array) Yaml::parse($row['body']), $row['handle']),
        'guide' => $write('craft', 'guide', $row['handle'], $source.', the body', ['body' => $row['body']], $row['handle']),
        default => null,
    };
}

foreach ($mysql->query('select name, value from ghostwriter_state order by id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $value = json_decode((string) $row['value'], true, 512, JSON_THROW_ON_ERROR);
    $source = "gw-test-craft ghostwriter_state {$row['name']}";

    if ($row['name'] === 'kinds' || $row['name'] === 'types') {
        foreach ($value as $group => $state) {
            $write('craft', $row['name'] === 'kinds' ? 'kind-suggestions' : 'analysis', $group, "{$source}, \"{$group}\"", $state, $group);
        }
    } elseif (str_starts_with($row['name'], 'image:')) {
        $write('craft', 'image-request', substr($row['name'], 6), $source, $value);
    } elseif ($row['name'] === 'plan') {
        $write('craft', 'plan-state', 'plan', $source, $value, 'plan');
    } elseif (in_array($row['name'], ['voice', 'imagery'], true)) {
        $write('craft', 'guide-state', $row['name'], $source, $value, $row['name']);
    }
}

echo "Recorded {$count} fixtures in {$out}.\n";
