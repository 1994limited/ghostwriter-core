<?php

use NineteenNinetyFour\Ghostwriter\Drafts\MarkdownToBard;

/*
 * Runs each addon's own HouseStyle and EntryBuilder (pure classes) on
 * inputs made to tell the addons' differences apart, so the hooks record
 * them as golden cases too.
 *
 *     GOLDEN_LOG=craft-synthetic.jsonl GOLDEN_ADDON=craft php -d auto_prepend_file=/tmp/hooks/craft/prepend.php tools/record-layouts/synthetic.php
 */

$addon = getenv('GOLDEN_ADDON');
$repo = (getenv('GHOSTWRITER_ADDONS') ?: getenv('HOME').'/Dev').'/ghostwriter-'.$addon;
require $repo.'/vendor/autoload.php';

$ns = [
    'statamic' => ['NineteenNinetyFour\\Ghostwriter\\Blueprints\\', 'NineteenNinetyFour\\Ghostwriter\\Drafts\\'],
    'filament' => ['NineteenNinetyFour\\Ghostwriter\\Filament\\Layouts\\', 'NineteenNinetyFour\\Ghostwriter\\Filament\\Drafts\\'],
    'craft' => ['nineteenninetyfour\\ghostwriter\\layouts\\', 'nineteenninetyfour\\ghostwriter\\drafts\\'],
][$addon];

$houseClass = $ns[0].'HouseStyle';
$builderClass = $ns[1].'EntryBuilder';

function f(string $handle, string $kind, string $type, array $extra = []): array
{
    return ['handle' => $handle, 'type' => $type, 'kind' => $kind, 'display' => ucfirst(str_replace('_', ' ', $handle)), 'instructions' => '', 'required' => false] + $extra;
}

// Field types as each CMS names them.
$t = [
    'statamic' => ['text' => 'text', 'rich' => 'bard', 'image' => 'assets', 'user' => 'users', 'link' => 'link', 'entries' => 'entries', 'builder' => 'replicator', 'number' => 'integer', 'date' => 'date'],
    'craft' => ['text' => 'craft\\fields\\PlainText', 'rich' => 'craft\\ckeditor\\Field', 'image' => 'craft\\fields\\Assets', 'user' => 'craft\\fields\\Users', 'link' => 'craft\\fields\\Link', 'entries' => 'craft\\fields\\Entries', 'builder' => 'craft\\fields\\Matrix', 'number' => 'craft\\fields\\Number', 'date' => 'craft\\fields\\Date'],
    'filament' => ['text' => 'Filament\\Forms\\Components\\TextInput', 'rich' => 'Filament\\Forms\\Components\\RichEditor', 'image' => 'Filament\\Forms\\Components\\FileUpload', 'user' => 'Filament\\Forms\\Components\\Select', 'link' => 'Filament\\Forms\\Components\\TextInput', 'entries' => 'Filament\\Forms\\Components\\Select', 'builder' => 'Filament\\Forms\\Components\\Builder', 'number' => 'Filament\\Forms\\Components\\TextInput', 'date' => 'Filament\\Forms\\Components\\DatePicker'],
][$addon];

$engine = ['statamic' => [], 'craft' => ['engine' => 'matrix'], 'filament' => ['engine' => 'builder']][$addon];
$image = ['images' => true, 'max_files' => 1];
$rich = fn (string $text) => $addon === 'statamic'
    ? [['type' => 'paragraph', 'attrs' => ['textAlign' => 'center'], 'content' => [['type' => 'text', 'text' => $text, 'marks' => [['type' => 'italic']]]]]]
    : '<p class="lead"><em>'.htmlspecialchars($text).'</em></p>';
$link = fn (int $to) => match ($addon) {
    'statamic' => "entry::page-{$to}",
    'craft' => ['type' => 'entry', 'value' => [$to], 'label' => "Page {$to}"],
    'filament' => "/pages/{$to}",
};
$entries = fn (int $to) => $addon === 'statamic' ? ["page-{$to}"] : [$to];

$schema = [
    f('title', 'text', $t['text'], ['required' => true]),
    f('page_builder', 'blocks', $t['builder'], $engine + ['sets' => [
        'feature' => ['display' => 'Feature', 'instructions' => '', 'fields' => [
            f('heading', 'text', $t['text']),
            f('label', 'text', $t['text']),
            f('intro', 'richtext', $t['rich']),
            f('image', 'reference', $t['image'], $image),
            f('author', 'reference', $t['user']),
            f('published_on', 'reference', $t['date']),
            f('related', 'reference', $t['entries']),
            f('button_link', 'reference', $t['link']),
            f('button_text', 'text', $t['text']),
            f('cards', 'blocks', $t['builder'], $engine + ['sets' => [
                'card' => ['display' => 'Card', 'instructions' => '', 'fields' => [
                    f('words', 'text', $t['text']),
                    f('photo', 'reference', $t['image'], $image),
                    f('owner', 'reference', $t['user']),
                ]],
            ]]),
        ]],
        'spacer' => ['display' => 'Spacer', 'instructions' => '', 'fields' => [f('height', 'number', $t['number'])]],
    ]]),
];

$page = function (int $n, string $title) use ($rich, $link, $entries, $addon) {
    return ['title' => $title, 'page_builder' => [
        ['id' => "f{$n}", 'type' => 'feature', 'enabled' => true,
            'heading' => "About {$title}",
            // The label repeats the page's title, as a breadcrumb would.
            'label' => $title,
            // The same intro, dressed the same way, on every page.
            'intro' => $rich('Gardens for the north'),
            'image' => $addon === 'craft' ? [100 + $n] : "images/{$n}.jpg",
            'author' => $addon === 'statamic' ? ["user-{$n}"] : [$n],
            'published_on' => "2026-0{$n}-01",
            'related' => $entries(10 + $n),
            'button_link' => $link(20 + $n),
            'button_text' => 'Read more',
            'cards' => [
                ['id' => "c{$n}a", 'type' => 'card', 'enabled' => true, 'words' => 'One', 'photo' => $addon === 'craft' ? [200 + $n] : "cards/{$n}.jpg", 'owner' => [$n]],
                ['id' => "c{$n}b", 'type' => 'card', 'enabled' => true, 'words' => 'Two', 'owner' => [$n + 1]],
            ],
        ],
        ['id' => "s{$n}", 'type' => 'spacer', 'enabled' => true, 'height' => $n === 3 ? 20 : 40],
    ]];
};

$pages = [$page(1, 'Orchards'), $page(2, 'Meadows'), $page(3, 'Walled gardens')];
$ids = $addon === 'statamic' ? ['p1', 'p2', 'p3'] : [1, 2, 3];
$self = ['id' => $addon === 'statamic' ? null : 50, 'title' => 'Rain gardens'];

GoldenRecorder::$context = 'synthetic:places-the-pages-disagree-on';
$house = new $houseClass;
$style = $house->learn($pages, $schema, $ids);

// The writer's draft leaves the intro, the label and the references empty.
$toFill = [];
$house->apply(['title' => 'Rain gardens', 'page_builder' => [
    ['type' => 'feature', 'enabled' => true, 'heading' => 'About rain gardens', 'button_text' => 'Read more', 'cards' => [['type' => 'card', 'enabled' => true, 'words' => 'Three']]],
    ['type' => 'spacer', 'enabled' => true],
]], $schema, $style, $toFill, $self);

// The same, without IDs to tell links to the page itself.
GoldenRecorder::$context = 'synthetic:places-without-ids';
$style = $house->learn($pages, $schema);
$toFill = [];
$house->apply(['title' => 'Rain gardens', 'page_builder' => [
    ['type' => 'feature', 'enabled' => true, 'heading' => 'About rain gardens'],
    ['type' => 'spacer', 'enabled' => true],
    ['type' => 'spacer', 'enabled' => true],
]], $schema, $style, $toFill, $self);

// A draft built against a hand-made pattern: house values, boilerplate, references to name.
GoldenRecorder::$context = 'synthetic:building-against-a-pattern';
$builder = $addon === 'statamic' ? new $builderClass(new MarkdownToBard) : new $builderClass;
$pattern = [
    'blocks' => ['page_builder' => [
        'sequence' => ['feature', 'spacer'],
        'usage' => ['feature' => 1.0, 'spacer' => 1.0],
        'fixed' => ['spacer' => ['height' => 40], 'feature' => ['button_text' => 'Read more', 'cards' => [['id' => 'c1a', 'type' => 'card', 'enabled' => true, 'words' => 'One']]]],
        'used' => ['feature' => ['heading', 'image', 'author', 'related', 'button_link', 'button_text', 'cards'], 'spacer' => ['height']],
        'boilerplate' => ['spacer'],
    ]],
    'fixed' => ['seo' => ['id' => 'x1', 'title' => 'Northfold'], 'published_on' => '2026-01-01'],
];
$builder->build(['title' => "Rain  gardens\n", 'page_builder' => [
    ['type' => 'feature', 'heading' => 'About', 'intro' => "Rain gardens **soak** it up.\n\n> Plant for the wet.", 'button_text' => 'More'],
    ['type' => 'spacer', 'height' => 10],
    ['type' => 'carousel'],
    'not a block',
], 'colour' => 'green'], $schema, $pattern, ['seo' => ['title' => 'Kind default']]);

echo "done\n";
