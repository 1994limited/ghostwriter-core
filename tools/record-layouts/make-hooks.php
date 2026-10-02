<?php

/*
 * Builds instrumented copies of each addon's own layout classes (as they
 * were before core 0.4), which record what they are given and what they
 * give. Loaded with PHP's auto_prepend_file, so the addon repos are never
 * touched:
 *
 *     php tools/record-layouts/make-hooks.php /tmp/hooks ~/Dev
 *     cd ~/Dev/ghostwriter-statamic
 *     GOLDEN_LOG=/tmp/statamic-tests.jsonl GOLDEN_ADDON=statamic \
 *         php -d auto_prepend_file=/tmp/hooks/statamic/prepend.php vendor/bin/phpunit
 *
 * GOLDEN_LOG gets every call with its inputs, for convert.php to turn into
 * golden fixtures. GHOSTWRITER_RECORD_LAYOUTS gets the outputs alone, as
 * Layout\Testing\LayoutLog lines, for bin/compare-layouts (docs/layout.md).
 *
 * Arguments: where to write the hooks, and the directory holding the three
 * addon checkouts (ghostwriter-statamic, -filament, -craft).
 */

$out = $argv[1] ?? sys_get_temp_dir().'/ghostwriter-layout-hooks';
$dev = $argv[2] ?? (getenv('GHOSTWRITER_ADDONS') ?: getenv('HOME').'/Dev');

$addons = [
    'statamic' => [
        'root' => "$dev/ghostwriter-statamic/src",
        'files' => [
            'Blueprints/SchemaDescriber.php', 'Blueprints/PatternFinder.php', 'Blueprints/KindFinder.php',
            'Blueprints/HouseStyle.php', 'Drafts/EntryBuilder.php',
        ],
    ],
    'filament' => [
        'root' => "$dev/ghostwriter-filament/src",
        'files' => [
            'Layouts/SchemaDescriber.php', 'Layouts/PatternFinder.php', 'Layouts/KindFinder.php',
            'Layouts/HouseStyle.php', 'Drafts/EntryBuilder.php',
        ],
    ],
    'craft' => [
        'root' => "$dev/ghostwriter-craft/src",
        'files' => [
            'layouts/SchemaDescriber.php', 'layouts/PatternFinder.php', 'layouts/KindFinder.php',
            'layouts/HouseStyle.php', 'drafts/EntryBuilder.php',
        ],
    ],
];

$wrappers = [
    'SchemaDescriber' => [
        'rename' => ['public function describe(' => 'public function goldenDescribe('],
        'code' => <<<'PHP'
    public function describe(array $schema, array $pattern): string
    {
        $out = $this->goldenDescribe($schema, $pattern);
        \GoldenRecorder::record('describe', ['schema' => $schema, 'pattern' => $pattern, 'output' => $out]);

        return $out;
    }
PHP,
    ],
    'EntryBuilder' => [
        'rename' => ['public function build(' => 'public function goldenBuild('],
        'code' => <<<'PHP'
    public function build(array $draft, array $schema, array $pattern = [], array $defaults = []): array
    {
        $out = $this->goldenBuild($draft, $schema, $pattern, $defaults);
        \GoldenRecorder::record('build', ['draft' => $draft, 'schema' => $schema, 'pattern' => $pattern, 'defaults' => $defaults, 'output' => $out]);

        return $out;
    }
PHP,
    ],
    'HouseStyle' => [
        'rename' => [
            'public function learn(' => 'public function goldenLearn(',
            'public function apply(' => 'public function goldenApply(',
            'public function linkToSelf(' => 'public function goldenLinkToSelf(',
        ],
        'code' => <<<'PHP'
    public function learn(array $entries, array $schema, array $ids = []): array
    {
        $out = $this->goldenLearn($entries, $schema, $ids);
        \GoldenRecorder::record('learn', ['entries' => $entries, 'schema' => $schema, 'ids' => $ids, 'output' => $out]);

        return $out;
    }

    public function apply(array $data, array $schema, array $style, array &$toFill = [], array $self = ['id' => null, 'title' => ''], string $path = '', string $shape = '', string $label = ''): array
    {
        if ($path !== '' || $shape !== '' || $label !== '') {
            return $this->goldenApply($data, $schema, $style, $toFill, $self, $path, $shape, $label);
        }

        $before = $toFill;
        $out = $this->goldenApply($data, $schema, $style, $toFill, $self);
        \GoldenRecorder::record('apply', ['data' => $data, 'schema' => $schema, 'style' => $style, 'self' => $self, 'toFillBefore' => $before, 'toFill' => $toFill, 'output' => $out]);

        return $out;
    }
PHP,
        'statamic' => <<<'PHP'

    public function linkToSelf(array $data, array $schema, array $style, string $id, string $title, string $path = ''): array
    {
        if ($path !== '') {
            return $this->goldenLinkToSelf($data, $schema, $style, $id, $title, $path);
        }

        $out = $this->goldenLinkToSelf($data, $schema, $style, $id, $title);
        \GoldenRecorder::record('linkToSelf', ['data' => $data, 'schema' => $schema, 'style' => $style, 'id' => $id, 'title' => $title, 'output' => $out]);

        return $out;
    }
PHP,
    ],
    'PatternFinder' => [
        'rename' => ['private function patternFrom(' => 'private function goldenPatternFrom('],
        'statamic' => <<<'PHP'
    private function patternFrom(\Illuminate\Support\Collection $entries, array $schema): array
    {
        $out = $this->goldenPatternFrom($entries, $schema);
        \GoldenRecorder::record('pattern', [
            'schema' => $schema,
            'data' => $entries->map(fn ($entry) => $entry->data()->all())->values()->all(),
            'ids' => $entries->map(fn ($entry) => (string) $entry->id())->values()->all(),
            'output' => $out,
        ]);

        return $out;
    }
PHP,
        'filament' => <<<'PHP'
    private function patternFrom(array $entries, array $schema): array
    {
        $out = $this->goldenPatternFrom($entries, $schema);
        \GoldenRecorder::record('pattern', [
            'schema' => $schema,
            'data' => array_values(array_map(fn ($record) => $this->data->read($record, $schema), $entries)),
            'ids' => array_values(array_map(fn ($record) => $record->getKey(), $entries)),
            'output' => $out,
        ]);

        return $out;
    }
PHP,
        'craft' => <<<'PHP'
    private function patternFrom(array $entries, array $schema): array
    {
        $out = $this->goldenPatternFrom($entries, $schema);
        \GoldenRecorder::record('pattern', [
            'schema' => $schema,
            'data' => array_values(array_map(fn ($entry) => $this->data->read($entry, $schema), $entries)),
            'ids' => array_values(array_map(fn ($entry) => (int) $entry->getCanonicalId(), $entries)),
            'output' => $out,
        ]);

        return $out;
    }
PHP,
    ],
    'KindFinder' => [
        'rename' => ['public function find(' => 'public function goldenFind('],
        'statamic' => <<<'PHP'
    public function find(string $collection, array $schema, ?string $blueprint = null): array
    {
        $out = $this->goldenFind($collection, $schema, $blueprint);
        $field = collect($schema)->firstWhere('kind', 'blocks')['handle'] ?? null;
        $entries = $field === null ? collect() : \Statamic\Facades\Entry::query()
            ->where('collection', $collection)
            ->where('published', true)
            ->get()
            ->when($blueprint, fn ($all) => $all->filter(fn ($entry) => $entry->blueprint()?->handle() === $blueprint))
            ->sortByDesc(fn ($entry) => $entry->date()?->timestamp ?? $entry->lastModified()?->timestamp ?? 0)
            ->values();

        \GoldenRecorder::record('kinds', [
            'schema' => $schema,
            'entries' => $entries->map(function ($entry) use ($field) {
                $parent = $entry->parent();

                return [
                    'id' => (string) $entry->id(),
                    'title' => (string) $entry->get('title'),
                    'parent' => $parent ? ['id' => $parent->id(), 'title' => (string) $parent->title(), 'root' => (bool) $parent->isRoot()] : null,
                    'data' => [$field => $entry->get($field)],
                ];
            })->all(),
            'output' => $out,
        ]);

        return $out;
    }
PHP,
        'filament' => <<<'PHP'
    public function find(\NineteenNinetyFour\Ghostwriter\Filament\Support\WritableResource $resource): array
    {
        $out = $this->goldenFind($resource);

        try {
            $schema = $this->maps->fields($resource);
            $spec = collect($schema)->first(fn (array $field) => ($field['engine'] ?? null) === 'builder');
            $titleField = $this->maps->titleField($resource);
            $records = $spec === null ? [] : PatternFinder::published($resource, 120);
        } catch (\Throwable) {
            return $out;
        }

        \GoldenRecorder::record('kinds', [
            'schema' => $schema,
            'entries' => array_map(fn ($record) => [
                'id' => (string) $record->getKey(),
                'title' => $titleField ? (string) data_get($record, $titleField) : '#'.$record->getKey(),
                'parent' => null,
                'data' => $this->reader->read($record, [$spec]),
            ], $records),
            'output' => $out,
        ]);

        return $out;
    }
PHP,
        'craft' => <<<'PHP'
    public function find(string $section, array $schema, ?string $entryType = null): array
    {
        $out = $this->goldenFind($section, $schema, $entryType);
        $spec = null;

        foreach ($schema as $candidate) {
            if ($candidate['kind'] === 'blocks') {
                $spec = $candidate;
                break;
            }
        }

        $entries = $spec === null ? [] : PatternFinder::published($section, $entryType, 120);

        \GoldenRecorder::record('kinds', [
            'schema' => $schema,
            'entries' => array_map(function ($entry) use ($spec) {
                $parent = $entry->getParent();

                return [
                    'id' => (int) $entry->id,
                    'title' => (string) $entry->title,
                    'parent' => $parent ? ['id' => (int) $parent->id, 'title' => (string) $parent->title, 'root' => false] : null,
                    'data' => $this->data->read($entry, [$spec]),
                ];
            }, $entries),
            'output' => $out,
        ]);

        return $out;
    }
PHP,
    ],
];

foreach ($addons as $addon => $config) {
    @mkdir("$out/$addon", 0777, true);
    $requires = [];

    foreach ($config['files'] as $file) {
        $class = basename($file, '.php');
        $source = file_get_contents("{$config['root']}/$file");
        $spec = $wrappers[$class];

        foreach ($spec['rename'] as $from => $to) {
            if (substr_count($source, $from) !== 1) {
                if ($from === 'public function linkToSelf(' && $addon !== 'statamic') {
                    continue;
                }

                fwrite(STDERR, "$addon $class: '$from' found ".substr_count($source, $from)." times\n");
                exit(1);
            }

            $source = str_replace($from, $to, $source);
        }

        $code = ($spec['code'] ?? '').($spec[$addon] ?? '');
        $end = strrpos($source, '}');
        $source = substr($source, 0, $end)."\n".$code."\n}\n";

        file_put_contents("$out/$addon/$class.php", $source);
        $requires[] = "require __DIR__.'/$class.php';";
    }

    file_put_contents("$out/$addon/prepend.php", "<?php\n\nrequire ".var_export(__DIR__.'/recorder.php', true).";\n".implode("\n", $requires)."\n");
}

echo "Hooks written to $out\n";
