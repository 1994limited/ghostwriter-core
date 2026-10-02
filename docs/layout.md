# Layouts: wiring the schema model and layout algorithms into an addon

Core 0.4.0 holds the schema model and the algorithms that work on it: describing a schema for the model, finding how a group's entries are put together, finding the kinds of entry a group holds, the house style, and building a draft into entry data. Before 0.4 each addon had its own copy (`SchemaDescriber`, `PatternFinder`, `KindFinder`, `HouseStyle`, `EntryBuilder`). After the switch an addon keeps only what touches its CMS: reading the schema and the entries, and writing the result back.

What differed between the three, and what core does instead, is in [layout-unification.md](layout-unification.md).

## Two namespaces

- **`Core\Schema`**: the model. `Schema` (a list of `Field`s), `Field`, `Kind`, `Set` (one block type of a page builder) and `EntryData` (an existing entry's content in one shape whatever the CMS). It says nothing about writing, so the domain types coming in Phase 4 can use it too.
- **`Core\Layout`**: the algorithms, their options (`LayoutOptions`), the two dialects (`RichTextDialect`, `LinkDialect`) and their results (`Pattern`, `HouseRules`, `HouseResult`, `BuiltEntry`, `FoundKind`). `Layouts` builds them all with one set of options and dialects.

## What the addon provides

### A `Schema`, from its `SchemaReader`

Each addon's `SchemaReader` stays where it is: reading a blueprint, a field layout or a Filament form needs the CMS. It must give core a `Schema`. The quickest way is to keep the reader's arrays and convert them:

```php
$schema = Schema::fromSpecs($reader->read($blueprint));      // Statamic, Craft
$schema = Schema::fromSpecs($maps->fields($resource));         // Filament (the corrected form map)
```

`Schema::fromSpecs()` reads the keys the three readers already write (`handle`, `type`, `kind`, `display`, `instructions`, `required`, `options`, `sets`, `fields`, `engine`, `path`). A field with an `images` key is marked `files` (all three readers mark assets and uploads that way). Every other key (`container`, `columns`, `save_html`, `format`, `relationship`…) is kept in `meta`, which core never reads except through the dialects. `$schema->toSpecs()` gives the arrays back, for code that still works on them (the adapter's own writers, `Text\EntrySimplifier`, `Text\EntryMerger`).

What the fields must say:

| | |
|---|---|
| `kind` | One of the twelve `Kind`s. `reference` for anything the writer leaves for a person. A page builder with nothing in it to write is a `reference` that still has its `sets`. |
| `sets` | For a page builder: its block types, keyed by handle. Bard keeps its sets here too, though it is `richtext`. |
| `fields` | For `rows` and `group`: the fields each row or the group has. |
| `type` | The CMS's own field type (`bard`, `link`, a Craft field class). The dialects look at it. |
| `engine` | How a builder stores blocks, for the adapter (`matrix`, `neo`, `builder`). Core knows one tag: `neo-children` (`Field::CHILDREN`), the field a Neo block's child blocks sit under. |
| `label`, `instructions`, `required`, `options` | As an editor sees them. `options` is keyed by the stored value. |

A field is a page builder (`Field::isBuilder()`) when it is `blocks`, or has sets and isn't rich text. That is what Craft's `isset($spec['engine'])` and Statamic's `kind === 'blocks'` both meant.

### `EntryData`, from its entries

The algorithms read entries as `EntryData`: the values in one shape, with the entry's ID, title and parent.

```php
new EntryData(
    values: $values,          // each field by handle, the title under `title`
    id: $entry->id,           // finds links to the entry itself; names it as an example
    title: null,              // only when the title isn't values['title'] (Filament: the title field, or "#key")
    parentId: $parent?->id,   // the page it sits under; leave out the site's home page
    parentTitle: $parent?->title,
);
```

The values are what each addon already reads:

| | Values | ID | Parent |
|---|---|---|---|
| Statamic | `$entry->data()->all()` | `(string) $entry->id()` | `$entry->parent()`, unless it `isRoot()` |
| Craft | `(new EntryData)->read($entry, $schema)` (the adapter's reader, as now) | `(int) $entry->getCanonicalId()` (kind examples: `$entry->id`) | `$entry->getParent()` |
| Filament | `(new RecordReader)->read($record, $schema)` | `$record->getKey()` (kind examples: `(string)`) | none |

Page builders are lists of blocks, each `['type' => …, 'enabled' => bool, …fields]` with its `id` where it has one; a Neo block's children sit under `children` as a tree. Rich text is as stored (HTML, Bard nodes, markdown). References are what the CMS stores.

The adapter also chooses the entries, since that is a query: the group's published entries, newest first (of one blueprint or entry type where it has one), or the ones a person picked. `PatternFinder::choose($entries, $where)` then narrows them as the addons did: those matching `where`, or all of them while none match, at most `PatternFinder::SAMPLE` (30). For the kind finder, query at most `KindFinder::SAMPLE` (120); Statamic looked at every entry, and may go on doing so.

### The dialects and options

```php
use NineteenNinetyFour\Ghostwriter\Core\Layout\HtmlDialect;
use NineteenNinetyFour\Ghostwriter\Core\Layout\LayoutOptions;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Layouts;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\CraftLinks;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\NoLinks;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\StatamicLinks;
use NineteenNinetyFour\Ghostwriter\Core\Text\HtmlToMarkdown;

// Statamic: its own BardDialect (below)
$layouts = new Layouts(LayoutOptions::statamic(), new BardDialect($markdownToBard, $bardToMarkdown), new StatamicLinks);

// Craft
$layouts = new Layouts(LayoutOptions::craft(), new HtmlDialect, new CraftLinks(hyper: ['verbb\hyper\fields\HyperField'], link: [\craft\fields\Link::class]));

// Filament, with the panel's translation of "Like :titles"
$layouts = new Layouts(
    LayoutOptions::filament(fn (array $titles, int $more) => $more > 0
        ? __('ghostwriter::panel.like_more', ['titles' => implode(', ', $titles), 'count' => $more])
        : __('ghostwriter::panel.like', ['titles' => implode(' and ', $titles)])),
    new HtmlDialect(new HtmlToMarkdown(embeds: [])),
    new NoLinks,
);
```

Build it once (a container singleton, a plugin component). `LayoutOptions::statamic()` makes new block and row IDs with `bin2hex(random_bytes(4))`, as Statamic's sets get; pass your own callable to change that.

**`RichTextDialect`** is how a CMS stores rich text. `HtmlDialect` (core) is for HTML: Craft's CKEditor and Redactor, Filament's RichEditor, and a field whose meta says `format: markdown` (Filament's MarkdownEditor) is shown to the model as it is stored. Statamic provides a `BardDialect` in its adapter, built from its `MarkdownToBard` and `BardToMarkdown`:

- `fromMarkdown()`: a `markdown` field keeps the markdown; a Bard field with `save_html` gets HTML (CommonMark, raw HTML escaped); any other Bard field gets nodes, with block quotes stored as the field's pull-quote set where it has one.
- `toMarkdown()`: nodes through `BardToMarkdown`, strings trimmed.
- `isWritten()`: a non-empty node list.
- `shapes()` and `dress()`: the house style's Bard code (`heading1`, `paragraph`… with their `attrs` and the marks on all their text).

A working one is in core's tests, `tests/Layout/Support/BardDialect.php`, which the Statamic golden fixtures run through; copy it into the adapter, with the ID callable from the adapter.

**`LinkDialect`** is how a CMS stores links: which fields hold them, what a link to the entry itself looks like, and what stands in for a link nothing settles. All three are plain arrays and strings, so core has one for each CMS: `StatamicLinks` (`link` and `entries` fields, `entry::id`, placeholder text in a `link_text`-style field beside it), `CraftLinks` (Hyper and Craft's Link field, `[id]`; the field classes are passed in, so core names no Craft class) and `NoLinks` (Filament, whose URLs are text inputs the writer fills). A site with another link plugin can pass its own.

## Calling it

| Before (the addon's class) | After |
|---|---|
| `SchemaDescriber::describe($schema, $pattern)` | `$layouts->describer()->describe($schema, $pattern)` |
| `PatternFinder::find(…)` | the adapter's query, then `$layouts->patterns()->find($schema, PatternFinder::choose($entries, $where))`; picked examples go straight to `find()` |
| `KindFinder::find(…)` | the adapter's query, then `$layouts->kinds()->find($schema, $entries)`; each `FoundKind::toArray()` is the old array |
| `HouseStyle::learn(…)` | inside `find()`: `$pattern->house` |
| `HouseStyle::apply($data, $schema, $pattern['house'], $toFill, $self)` | `$result = $layouts->houseStyle()->apply($data, $schema, $pattern->house, $id, $title)`; `$result->data`, `$result->toFill`, and `$result->note()` is the "Still to set by hand…" note |
| `HouseStyle::linkToSelf(…)` (Statamic) | `$layouts->houseStyle()->linkToSelf($data, $schema, $pattern->house, $id, $title)` |
| `EntryBuilder::build($draft, $schema, $pattern, $defaults)` | `$layouts->builder()->build($draft, $schema, $pattern, $defaults)`; `->toArray()` is the old `['data', 'notes']`. Pass no pattern when revising an existing entry. |

`Pattern::toArray()` is the array the addons' `PatternFinder`s returned (`entries`, `words`, `blocks`, `fixed`, `examples`, `filled`, `house`), so the addon's placeholders (`filled`) and anything that stored a pattern keep working; `Pattern::fromArray()` reads one back.

The examples are simplified through the rich text dialect. For an addon's own use of `EntrySimplifier` (its voice scanner, say), `PatternFinder::simplifier($dialect)` gives one that reads rich text the same way.

### The Studio

`Studio` takes a `Layout`. It can now be built from the structured schema, which core describes:

```php
$layout = Layout::fromSchema($schema, $pattern, $layouts->describer());
// or, finding the pattern too
$layout = $layouts->layout($schema, PatternFinder::choose($entries, $type->where));
```

The old path, `Layout::fromPattern($described, $pattern)` with the addon's own describer, still works, so an addon can switch its describer and its Studio inputs separately. Both send the same requests (`tests/Studio/StructuredLayoutTest.php`).

## Checking parity

### Golden fixtures in core

`tests/Fixtures/layout/<addon>/*.json` hold what each addon's own `SchemaDescriber`, `PatternFinder`, `KindFinder`, `HouseStyle` (`learn`, `apply` with the places to fill, Statamic's `linkToSelf`) and `EntryBuilder` gave, recorded before core took them over:

- from the addon's own test suite (every call its tests made),
- from its Northfold test site (every collection, section or resource; each published entry, simplified, standing in for a draft, with and without the pattern),
- from inputs made to tell the addons apart (`synthetic-*`), run through each addon's own code.

Each case holds the inputs in core's neutral shape (a `Schema` in `Field::toArray()` form, `EntryData` arrays) and the addon's output. `tests/Layout/GoldenTest.php` gives core the same inputs, wired as in `tests/Layout/Addons.php`, and checks the output is the same, with Statamic's random IDs compared as `<id>`. A case where core differs on purpose carries `deliberate`: core's output and why. The recordings are never rewritten from core's output.

They were made with `tools/record-layouts`, which instruments the addons' classes without touching their repos:

```bash
php tools/record-layouts/make-hooks.php /tmp/hooks ~/Dev     # instrumented copies of the five classes per addon
cd ~/Dev/ghostwriter-statamic
GOLDEN_LOG=/tmp/rec/statamic-tests.jsonl GOLDEN_ADDON=statamic \
    php -d auto_prepend_file=/tmp/hooks/statamic/prepend.php vendor/bin/phpunit
# the sites and the made-up inputs: see the headers of tools/record-layouts/sites/*.php and synthetic.php
php tools/record-layouts/convert.php . /tmp/rec               # writes tests/Fixtures/layout
```

### Before and after in the addon

Core's fixtures pin the algorithms. The adapter's own part (reading entries into `EntryData`, choosing them, calling core in the right places) is checked by running the addon's suite before and after and comparing what the algorithms gave, as with `compare-requests` for the Studio.

1. **Before**, on the addon's main branch, record the old classes' outputs with the hooks, which also write `LayoutLog` lines:

   ```bash
   GHOSTWRITER_RECORD_LAYOUTS=/tmp/before.jsonl GOLDEN_ADDON=craft \
       php -d auto_prepend_file=/tmp/hooks/craft/prepend.php vendor/bin/codecept run unit
   ```

2. **After**, on the switched branch, record at the adapter's call sites with `Layout\Testing\LayoutLog`:

   ```php
   // TestCase::setUp()
   LayoutLog::start(getenv('GHOSTWRITER_RECORD_LAYOUTS') ?: null, static::class.'::'.$this->name());

   // wherever the adapter calls core
   LayoutLog::record('pattern', $pattern);              // also 'describe', 'kinds', 'apply', 'linkToSelf', 'build'
   ```

   ```bash
   GHOSTWRITER_RECORD_LAYOUTS=/tmp/after.jsonl vendor/bin/codecept run unit
   ```

3. **Compare**:

   ```bash
   vendor/bin/compare-layouts /tmp/before.jsonl /tmp/after.jsonl [--ignore=kinds]
   ```

   Each test's outputs are matched in order. Statamic's random set IDs and UUIDs are normalised. Compare two "before" runs first to see what is noise: Statamic's suite saves some entries within the same second, so their order (and so a pattern's examples and the order of `usage`) can change from run to run.

## Stage 2, per addon

1. Require `^1.0`.
2. Build one `Layouts` with the addon's options and dialects (above). Statamic: move `tests/Layout/Support/BardDialect.php` into the adapter.
3. Convert the reader's arrays with `Schema::fromSpecs()` (or have the reader build `Field`s), and read entries into `EntryData`.
4. Replace the five classes' bodies with calls to core (the table above); keep the queries, `FieldValues` (Craft), `FormState` (Filament), `SchemaEntryWriter`/`HouseFinish` (Statamic) and the placeholders. Delete the old copies, and in Filament the Hyper and Link constants (FIL-9).
5. Switch `StudioInputs` to `Layout::fromSchema()`.
6. Run the suite unchanged (only fixture loading may change), then the before/after comparison.
