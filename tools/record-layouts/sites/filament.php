<?php

/*
 * Reads the Northfold Filament app and runs the plugin's own layout classes
 * over every resource, so the hooks (make-hooks.php) record them. The
 * plugin may save a form map as it reads, so it runs on a copy of the
 * app's database:
 *
 *     cp ~/Dev/gw-test-filament/database/database.sqlite /tmp/northfold.sqlite
 *     DB_DATABASE=/tmp/northfold.sqlite CACHE_STORE=array GOLDEN_LOG=filament-site.jsonl GOLDEN_ADDON=filament \
 *         php -d auto_prepend_file=/tmp/hooks/filament/prepend.php tools/record-layouts/sites/filament.php
 */

$site = $argv[1] ?? getenv('HOME').'/Dev/gw-test-filament';
chdir($site);

require $site.'/vendor/autoload.php';
$app = require $site.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use Filament\Facades\Filament;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;
use NineteenNinetyFour\Ghostwriter\Core\Text\EntrySimplifier;
use NineteenNinetyFour\Ghostwriter\Core\Text\HtmlToMarkdown;
use NineteenNinetyFour\Ghostwriter\Filament\Drafts\EntryBuilder;
use NineteenNinetyFour\Ghostwriter\Filament\Forms\FormMaps;
use NineteenNinetyFour\Ghostwriter\Filament\GhostwriterPlugin;
use NineteenNinetyFour\Ghostwriter\Filament\Layouts\HouseStyle;
use NineteenNinetyFour\Ghostwriter\Filament\Layouts\KindFinder;
use NineteenNinetyFour\Ghostwriter\Filament\Layouts\PatternFinder;
use NineteenNinetyFour\Ghostwriter\Filament\Layouts\SchemaDescriber;
use NineteenNinetyFour\Ghostwriter\Filament\Records\RecordReader;

if (realpath((string) config('database.connections.sqlite.database')) === realpath($site.'/database/database.sqlite')) {
    fwrite(STDERR, "Refusing to run against the site's own database.\n");
    exit(1);
}

Filament::setCurrentPanel(Filament::getPanel('admin'));

foreach (GhostwriterPlugin::get()->getResources() as $resource) {
    $context = "site:{$resource->handle()}";
    GoldenRecorder::$context = $context;

    $schema = app(FormMaps::class)->fields($resource);
    $pattern = (new PatternFinder)->find($resource, $schema);
    (new SchemaDescriber)->describe($schema, $pattern);
    (new SchemaDescriber)->describe($schema, []);
    app(KindFinder::class)->find($resource);

    $records = PatternFinder::published($resource, 90);
    $simplifier = new EntrySimplifier(new HtmlToMarkdown(embeds: []));
    $drafts = array_map(fn (Model $record) => $simplifier->simplify((new RecordReader)->read($record, $schema), $schema), $records);
    $drafts = [...$pattern['examples'], ...$drafts];
    $titleField = app(FormMaps::class)->titleField($resource);

    foreach ($drafts as $i => $draft) {
        GoldenRecorder::$context = "$context#draft$i";
        $built = (new EntryBuilder)->build($draft, $schema, $pattern);
        $toFill = [];
        (new HouseStyle)->apply($built['data'], $schema, $pattern['house'], $toFill, ['id' => null, 'title' => (string) ($titleField ? ($built['data'][$titleField] ?? '') : '')]);
        (new EntryBuilder)->build($draft, $schema);
    }
}

echo "done\n";
