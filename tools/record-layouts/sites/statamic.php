<?php

/*
 * Reads the Northfold Statamic site (read only) and runs the addon's own
 * layout classes over every collection and blueprint, so the hooks
 * (make-hooks.php) record them. Each published entry, simplified, stands in
 * for a writer's draft.
 *
 *     CACHE_STORE=array GOLDEN_LOG=statamic-site.jsonl GOLDEN_ADDON=statamic \
 *         php -d auto_prepend_file=/tmp/hooks/statamic/prepend.php tools/record-layouts/sites/statamic.php
 */

$site = $argv[1] ?? getenv('HOME').'/Dev/gw-test-statamic';
chdir($site);

require $site.'/vendor/autoload.php';
$app = require $site.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use NineteenNinetyFour\Ghostwriter\Blueprints\HouseStyle;
use NineteenNinetyFour\Ghostwriter\Blueprints\KindFinder;
use NineteenNinetyFour\Ghostwriter\Blueprints\PatternFinder;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaDescriber;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaReader;
use NineteenNinetyFour\Ghostwriter\Core\Text\EntrySimplifier;
use NineteenNinetyFour\Ghostwriter\Drafts\EntryBuilder;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

foreach (Collection::all() as $collection) {
    foreach ($collection->entryBlueprints() as $blueprint) {
        $context = "site:{$collection->handle()}/{$blueprint->handle()}";
        GoldenRecorder::$context = $context;

        $schema = app(SchemaReader::class)->read($blueprint);
        $pattern = app(PatternFinder::class)->find($collection->handle(), $schema, $blueprint->handle());
        app(SchemaDescriber::class)->describe($schema, $pattern);
        app(SchemaDescriber::class)->describe($schema, []);
        app(KindFinder::class)->find($collection->handle(), $schema, $blueprint->handle());

        // Each published entry, simplified, as a draft of a new one, and the
        // pattern's own examples (defaults left out) as the writer gives them.
        $entries = Entry::query()->where('collection', $collection->handle())->where('published', true)->get()
            ->filter(fn ($entry) => $entry->blueprint()?->handle() === $blueprint->handle())->values();
        $drafts = $entries->map(fn ($entry) => app(EntrySimplifier::class)->simplify($entry->data()->all(), $schema))->all();
        $drafts = [...$pattern['examples'], ...$drafts];

        foreach ($drafts as $i => $draft) {
            GoldenRecorder::$context = "$context#draft$i";
            $built = app(EntryBuilder::class)->build($draft, $schema, $pattern);
            $toFill = [];
            $title = (string) ($draft['title'] ?? 'New page');
            $data = (new HouseStyle)->apply($built['data'], $schema, $pattern['house'], $toFill, ['id' => null, 'title' => $title]);
            (new HouseStyle)->linkToSelf($data, $schema, $pattern['house'], 'new-entry', $title);

            // Without what the collection usually does, as an edit is built.
            app(EntryBuilder::class)->build($draft, $schema);
        }
    }
}

echo "done\n";
