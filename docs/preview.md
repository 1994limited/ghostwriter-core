# Page preview and Suggest edits: the shared anchoring

Page preview (comments on a rendered draft) and Suggest edits (Ghostwriter's suggestions on an existing entry) both point at **a field and a quoted range of its text**, and both are **scoped edits** that must change only that range, keep Ghostwriter's markers and add no unsourced fact. This page is the API the addons call for that shared layer. The designs are `page-preview-layouts-design.md` (§5.1, §8) and `suggest-edits-design.md` (§4.1).

Nothing here calls a model, renders a page or touches a CMS.

## Anchor: quotes, sources and scoped edits

`NineteenNinetyFour\Ghostwriter\Core\Anchor`. Offsets and lengths are always in **characters** (code points, as `mb_substr()` counts them).

### `TextQuote`

```php
new TextQuote(string $exact, string $prefix = '', string $suffix = '');  // exact ≤ 300 characters; prefix keeps its last 32, suffix its first 32
TextQuote::around(string $text, int $offset, int $length): TextQuote    // a range and its context
$quote->toArray(): array;  TextQuote::fromArray(array $a): TextQuote    // {exact, prefix?, suffix?}
```

### `QuoteFinder`

```php
(new QuoteFinder)->find(TextQuote $quote, string $text, ?int $occurrence = null, bool $markdown = false): ?QuoteMatch
```

1. **Exact**, after normalising (`NormalisedText`): whitespace runs (NBSP, narrow and figure spaces, line breaks) are one space; curly and straight quotes are the same, and so are hyphens, dashes and the minus sign; `…` is `...`; soft hyphens, zero-width characters and Unicode tag characters (the preview's markers) are dropped. With `$markdown`, inline syntax is skipped too: `*`, `_`, backticks, a link's brackets and `(target)`, and a line's leading `#`, `>`, bullet or number. Case is kept.
2. **Repeats** are told apart by how much of the prefix and suffix agree with what surrounds each occurrence. When the context can't tell, `$occurrence` (0 for the first) picks one; with no `$occurrence` either, the result is **null**.
3. **One fuzzy match** when there is no exact one, for quotes of at least 16 characters: a span of whole words whose character trigrams agree with the quote's at least 0.9 (Dice). Two separate places that match are ambiguous: **null**. Store `$match->requote($text)` after a fuzzy match, so the quote holds the real words.

`QuoteMatch` has `offset`, `length` (in the text as written, so a range in markdown includes any syntax inside it), `occurrence`, `fuzzy`, `text($text)`, `requote($text)` and `toArray()`.

**Front-end ports** (Suggest edits' `quote.js` in each addon) run the same cases: `resources/anchor/quote-cases.json`. Each case has `text`, `quote` (`exact`, `prefix`, `suffix`), optional `occurrence` and `markdown`, and `expect` (`offset`, `length`, `text`, `occurrence`, `fuzzy`, or null). JavaScript strings count UTF-16 units: convert with `Array.from(text)` before comparing offsets.

### `Sentences`

```php
Sentences::split(string $text): array                                // list<[offset, length]>
Sentences::covering(string $text, int $offset, int $length): array   // [offset, length] of the sentence(s) a range is in
Sentences::inOneBlock(string $markdown, int $offset, int $length): bool  // no line break; no table cell's |
```

### `SourceCheck`

```php
(new SourceCheck)->unsourced(string $new, array $sources): array   // list<string>: the facts in $new no source has
```

A fact is a figure in digits (compared by what it says, as `Studio\Figures` does: "4" is given by "four", "£1,200" by "£1.2k"), a quotation in quotation marks, or a name: a capitalised word or run of them that doesn't start a sentence, line or heading, compared without case. Lines in title case are skipped. `[[ask: …]]` markers, link targets and HTML tags are not facts.

### `ScopedEditCheck`

```php
(new ScopedEditCheck)->check(
    string $before, string $after,
    array $sources = [],          // what facts may come from besides $before
    float $minRatio = 0.3, float $maxRatio = 1.5,   // $after's words as a share of $before's
    ?TextQuote $quote = null,     // with a quote, $before is the whole text and only the quote's sentence(s) may change
    bool $mayFillAsks = false,    // an [[ask: …]] may be replaced by its (sourced) answer
    bool $mayAddMarkers = false,  // new [[ask: …]] or #gw-link: may appear
): array                           // list of ScopedEditCheck::SCOPE, MARKERS, LINK, FACTS, SIZE; empty when it passes
```

- **Suggest edits:** `check($quote->exact, $replacement, [$unitText, $page, $citedEntry])`, with its own ratios per category.
- **Page preview's revision:** `check($unitBefore, $unitAfter, [$comment, $brief, …], 0.4, 1.6, $textQuote, mayFillAsks: true, mayAddMarkers: true)` for a text comment; without `$textQuote` for a block comment.

Size isn't checked when `$before` has fewer than three words. `#gw-link:` links can never be dropped.

## Units: stable ids for draft text

`NineteenNinetyFour\Ghostwriter\Core\Arrange`. A unit is what an editor means by "this bit": a field value inside a block, a top-level field, or one section of a rich-text value. Comments point at unit ids, so they follow their words into another layout and across the writer's turns.

```php
Units::fromDraft(Draft|array $draft, Schema $schema, ?RichTextDialect $richText = null): Units   // rich text is markdown already
Units::fromEntry(EntryData $entry, Schema $schema, RichTextDialect $richText): Units            // stored values, read as markdown; block IDs in the paths
$units->get('u7'); $units->all(); $units->ids(); $units->inBlock(FieldPath $block); $units->at(FieldPath $value); $units->next;
```

| A value of kind | Units |
|---|---|
| `text` | one `text` unit |
| `longtext` | one `prose` unit (a markdown field: as rich text) |
| `richtext` | split by its **top** headings (the highest level it uses): any lead before the first is `prose`, then one `section` per heading, up to the next heading of that level. With no headings: one unit, `list` or `quote` when that's all it is, else `prose`. `part` is its index in the value (0, 1…). |
| `list` | one `list` unit, an `item` piece per item |
| `rows` | one `row` unit per row with text, a `field` piece per text field |
| an image field (`files`) | one `media` unit with its stored references in `assets`, when it holds something |

Each `Unit` has `id` ("u7"), `kind` (`UnitKind`), `path` (`Gaps\FieldPath`: by position in a draft, `page_builder/2/text`; with block IDs in an entry, `page_builder/#a1b2/text`), `part`, `markdown` (as written), `pieces` (`Piece`: `paragraph`, `heading` with its level, `item`, `quote`, `table`, `code`, `field`), `blockType` and `assets`. `hash()` is its normalised text hashed; `where()` is its path and part (`body~1`).

`inBlock()` and `at()` compare paths **by position** (`FieldPath::dotted()`), so a draft's units are found by an entry's path and the other way round.

### Carrying ids between turns

Ids are never in the draft's YAML, so no prompt or hand edit can corrupt them. They're stored beside it in `Session::$units`:

```php
// After any change to the draft (a writer's turn, click-to-edit, Edit YAML, a revision):
$before = Units::fromDraft($oldDraft, $schema)->restore($session->units);
$after = (new UnitMatcher)->carry($before, Units::fromDraft($newDraft, $schema));
$session->units = $after->sidecar();   // {next, units: {u7: {path, part?, kind, hash}}}
```

`carry()` keeps an id when the new unit is at the same place, of the same kind, and ≥ 60% similar; else for the best match anywhere ≥ 50% (Jaccard of word pairs; single words when either text has fewer than four). Media units match only by identical assets. Every other unit gets a new id from `next`: **ids are never reused**, so a comment whose unit is gone stays detached rather than landing on other words.

`Session::$units` is written only once it has something in it, like `$gaps`. Filament's table needs a `units` JSON column before the addon sets it.

Suggest edits uses `fromEntry()` ids (`u1`…) for one call only and stores anchors as a `FieldPath` plus a `TextQuote`, not ids.

## The preview marker

`NineteenNinetyFour\Ghostwriter\Core\Preview`. In **preview renders only**, every text value carries an invisible code naming its block and field, so the locator (below) can map the rendered page back to the draft.

### Encoding

```
U+E0067 U+E0077   tag "g", tag "w": the start
U+E00xx …         the payload, ASCII 0x20–0x7E as U+E0020–U+E007E (1–16 characters)
U+E007F           cancel tag: the end
```

`PreviewMarkers::PATTERN` (`/(?<!\x{1F3F4})\x{E0067}\x{E0077}([\x{E0020}-\x{E007E}]{1,16})\x{E007F}/u`) finds one; after the black flag U+1F3F4 the same characters are a subdivision flag, never a marker. Tag characters are default-ignorable: no glyph, no width, no effect on shaping.

**Payloads:** `b7` a block, `f2` a top-level field, `s3` a section of rich text, with an optional field index: `b7.2` is field 2 of block 7's set (its index in `Set::$fields`, as `MappedBlock::$fields` lists). Keys are numbered from 1 in reading order on each `mark()`.

### Where markers go

| Value | Where |
|---|---|
| text, long text | the start (after leading whitespace). Values that look like addresses (`https://…`, `/…`, `#…`, `mailto:`, `tel:`) are left alone. |
| markdown (a markdown field, or rich text stored as markdown) | after the line's leading `#`, `>`, bullet, number or table `|`, and any emphasis delimiters, so `**bold**` and `_em_` still parse. A code block's line is left alone. |
| HTML | the first text that isn't whitespace (not in `<script>`, `<style>` or `<template>`), inserted into the string: nothing else about the HTML changes. |
| Bard JSON | the first text node (sets are skipped). |
| list | its first item |
| rows, group | each text value in each row, with the rows or group field's index |
| rich text with more than one unit | also a section marker (`s3`) at the start of each unit: the lead, then each top heading, split exactly as `Units` splits it |

Every writable text value of every block is marked, not only the first: a template that doesn't print one field still prints another. Top-level text fields are `f` keys. Disabled blocks are skipped (they don't render).

### API

```php
$preview = (new PreviewMarkers(?callable $assetName = null))->mark(array $data, Schema $schema, ?Units $units = null): PreviewData;
$preview->data;     // the marked copy, for the preview render ONLY
$preview->map;      // BlockMap: send $preview->map->toArray() to the locator as JSON
$preview->hash;     // sha1 of the data without markers: the render cache key
```

- `$data` is apply's data in storage form (`DraftValues` in each addon): rich text as HTML, Bard nodes or markdown, references as stored. Call apply's builder **twice**, and mark only the preview's copy.
- `$units`, from the draft the data was built from (or `Units::fromEntry()` on the data), fills each `MappedBlock::$units`, matched by position.
- `$assetName` turns a stored asset reference into the file's basename, for the asset fallback. The default takes what follows the last `/` or `::` of a string (Statamic's `assets::photos/garden.jpg`); Craft passes one that loads the asset by ID.

`MappedBlock` (`toArray()` is what the locator gets):

| Key | |
|---|---|
| `key` | `b7`, `f2`, `s3` |
| `kind` | `block`, `field` or `section` |
| `path` | a `Gaps\FieldPath` string: `page_builder/#a1b2` |
| `label` | the set's or field's name, or a section's heading |
| `parent` | the enclosing block's key (Neo children, nested builders, a rich-text field's sections) |
| `units` | unit ids it shows itself (a field with sections lists none: its sections do) |
| `fields` | field index → handle |
| `assets` | image basenames, for the asset fallback |
| `anchors` | the first eight normalised words of each value's first line, for the text fallback |
| `type` | the set handle, or the field handle |

`BlockMap` has `get($key)`, `keys()`, `children(?string $parent)`, `forUnit($unitId)` (the innermost), `depth()`, `toArray()` and `fromArray()`.

The lower-level helpers are public for the addons' own tests: `encode($payload)`, `decode($text)` (payload, key, field and byte offset of each marker), `markText()`, `markMarkdown($markdown, [line => marker])`, `markHtml($html, [topLevelElement|-1 => marker])` and `markBard($nodes, [nodeIndex|-1 => marker])`.

### Never saved

- Only the preview's copy is marked. `PreviewMarkers::strip($anything)` and `stripText($string)` take every marker out, and `contains($anything)` says whether one is there.
- `Text\Draft::parse()` strips markers, so none can reach a draft (and through it an entry), even pasted.
- The locator removes them from the frame's DOM as soon as it finds them, so nothing can be copied with one.
- **`Tests\Contracts\PreviewMarkerContract`**: each addon extends `PreviewMarkerContractTest` with its own `storedValue($markdown, $shape)` (the real apply path) and `renderValue($stored, $shape)` (as the site's templates print it: Statamic's Bard and markdown augmentation, Craft's CKEditor HTML). It proves every shape keeps its markers through rendering, strips back to exactly the unmarked page, carries one marker per section, and that apply's data never holds one. Core runs it for HTML and Bard.
