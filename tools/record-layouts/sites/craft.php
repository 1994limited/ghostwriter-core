<?php

/*
 * Reads the Northfold Craft site (read only, inside a transaction that is
 * rolled back) and runs the plugin's own layout classes over every section
 * and entry type, so the hooks (make-hooks.php) record them. Each published
 * entry, simplified, stands in for a writer's draft.
 *
 *     GOLDEN_LOG=craft-site.jsonl GOLDEN_ADDON=craft \
 *         php -d auto_prepend_file=/tmp/hooks/craft/prepend.php tools/record-layouts/sites/craft.php
 */

$site = $argv[1] ?? getenv('HOME').'/Dev/gw-test-craft';
chdir($site);

require $site.'/bootstrap.php';
$app = require CRAFT_VENDOR_PATH.'/craftcms/cms/bootstrap/console.php';

use craft\elements\Entry;
use NineteenNinetyFour\Ghostwriter\Core\Text\EntrySimplifier;
use nineteenninetyfour\ghostwriter\drafts\EntryBuilder;
use nineteenninetyfour\ghostwriter\layouts\EntryData;
use nineteenninetyfour\ghostwriter\layouts\HouseStyle;
use nineteenninetyfour\ghostwriter\layouts\KindFinder;
use nineteenninetyfour\ghostwriter\layouts\PatternFinder;
use nineteenninetyfour\ghostwriter\layouts\SchemaDescriber;
use nineteenninetyfour\ghostwriter\layouts\SchemaReader;

$transaction = Craft::$app->getDb()->beginTransaction();

foreach (Craft::$app->getEntries()->getAllSections() as $section) {
    foreach ($section->getEntryTypes() as $type) {
        $context = "site:{$section->handle}/{$type->handle}";
        GoldenRecorder::$context = $context;

        $schema = (new SchemaReader)->read($type);
        $pattern = (new PatternFinder)->find($section->handle, $schema, $type->handle);
        (new SchemaDescriber)->describe($schema, $pattern);
        (new SchemaDescriber)->describe($schema, []);
        (new KindFinder)->find($section->handle, $schema, $type->handle);

        $entries = PatternFinder::published($section->handle, $type->handle);
        $drafts = array_map(fn (Entry $entry) => (new EntrySimplifier)->simplify((new EntryData)->read($entry, $schema), $schema), $entries);
        $drafts = [...$pattern['examples'], ...$drafts];

        foreach ($drafts as $i => $draft) {
            GoldenRecorder::$context = "$context#draft$i";
            $built = (new EntryBuilder)->build($draft, $schema, $pattern);
            $toFill = [];
            (new HouseStyle)->apply($built['data'], $schema, $pattern['house'], $toFill, ['id' => 990000 + $i, 'title' => (string) ($draft['title'] ?? 'New page')]);
            (new EntryBuilder)->build($draft, $schema);
        }
    }
}

$transaction->rollBack();

echo "done\n";
