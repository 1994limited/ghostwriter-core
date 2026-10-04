# Suggest edits and Content to revisit

Ghostwriter reviews an existing entry and suggests small, anchored changes, which the editor steps through in the Finish this page guide. Content to revisit ranks the site's published entries by the same free checks. This page is the API the addons call, as built. The design is `addon-reviews/suggest-edits-design.md`; where this page and the design differ, this page is right.

Everything here is framework-free. Only the review call (`Studio::suggestEdits()`) and "Write another" (`Studio::reword()`) use a model, and only when someone clicks a button that says so.

## The free checks: `Suggest\Findings`

The free half costs nothing. It never calls a model and never makes a request.

```php
use NineteenNinetyFour\Ghostwriter\Core\Suggest\{CheckContext, Findings, SuggestOptions, Quieted, EntryRef};
use NineteenNinetyFour\Ghostwriter\Core\Revisit\AgePolicy;

$report = Findings::standard()->report(new CheckContext(
    gaps: new GapContext(
        schema: $schema, entry: $entry, richText: $dialect, links: $links,
        placeholders: $assets, assets: $assets, targets: $linkTargets,
        alt: $assetAlt,            // new port: AssetAlt
        seo: $seoFields,           // new port: SeoFields
    ),
    now: new DateTimeImmutable,
    updatedAt: $entryLastSaved,     // null when unknown: no age-based checks
    language: $siteLocale,          // "en_GB", "de", "fr-FR"…
    index: $entryIndex,             // new port: EntryIndex (Overlaps); null to skip
    entry: new EntryRef('pages', $id, $site),
    age: AgePolicy::fromGroups($groupsWithADateField, $groupsSwitchedOff),
    options: new SuggestOptions(claims: $claimChecksOn),
    quieted: $editReviews->quieted($entryRef),   // decisions that keep findings quiet
));

$report->findings;       // list<Finding>, in form order
$report->gaps;           // Finish this page's GapReport (for the revisit list)
$report->leftovers();    // unfinished markers, placeholders and stray tokens
$report->emptyFields();  // required or expected fields left empty
```

`Findings::standard()` runs these checks, and the Gaps detectors whose finds are suggestions:

| Kind | Category | Needs | What it finds |
|---|---|---|---|
| `past-year` | Out of date | words | A year before this one in a phrase that reads as current: "New for 2024", "our 2025 prices", "as of 2023". History ("since 2015", "founded in 2009", "in 2019 we won") and a year on its own ("our 2023 show garden") are left alone. In a dated group (`AgePolicy`), a year the entry was written in or after is history too. |
| `relative-time` | Out of date | words | "this year", "next spring", "currently", "coming soon"… in an entry last saved 12 months ago or more. |
| `closing-date` | Fact to check | editor | A date with a year after a closing word ("applications close 31 January 2025", "bis zum 31.01.2025"), now past; and a date field whose handle reads as an end (`closing_date`, `deadline`, `ends_at`, `expires`, `valid_until`), now past. `meta.template` is the quote with `{answer}` for the date. |
| `stated-count` | Fact to check | editor | Counts and prices about the organisation ("team of 6", "over 20 years", "six designers", "from £450") in an entry last saved 12 months ago or more. `meta.template` puts `{answer}` where the number is; `meta.answer` is `number` or `money`. **Off with the claim-check switch.** |
| `long-sentence` | Clarity | words | Sentences over the language's limit (en 30 words, de 25, fr 35, nl 28, es 35). A hint for the review call only: `alone` is false, so it isn't shown without a rewrite. SEO values are left to SeoLength. |
| `empty-link-text` | Accessibility | words | Links whose words say nothing alone: "click here", "read more", "hier", "en savoir plus", "klik hier", "leer más". |
| `overlap` | Duplicate | words | A paragraph sharing over half its 5-word shingles with a paragraph of another entry on the same site (`EntryIndex`). Only with an index and an entry reference. |
| `link-broken` | Link | words (inline) or nothing (link field) | A link to a deleted entry or asset (`Gaps\Detectors\BrokenLinks`, through the addon's `LinkTargets`). `meta.candidates` are `LinkTargets::search()` of the link's words: the free "Link to …" fix. |
| `missing-alt` | Accessibility | words | An image with empty alt text, in an image field or inline in rich text (`Gaps\Detectors\MissingAlt`). Anchored to the asset. |
| `seo-length` | SEO | words | An SEO title or description over its limit (`Gaps\Detectors\SeoLength`). Anchored to the whole value. |
| `seo-empty` | SEO | words | An empty, writable SEO description that most pages like this fill (`ExpectedFields`). |

`Findings::standard()->without('overlap')` is what the revisit scan runs. `with(Check ...)` adds an addon's own checks.

### Findings and anchors

```php
final class Finding {
    public readonly string $id;          // Anchor::key($category): "out-of-date|page_builder/#h1/eyebrow|new for 2024|0"
    public readonly Category $category;  // OutOfDate, Voice, Clarity, FactToCheck, Link, Accessibility, Seo, Duplicate
    public readonly string $kind;        // the table above
    public readonly Anchor $anchor;
    public readonly Needs $needs;        // Nothing, Words, Editor
    public readonly Message $message;    // suggest.finding.<kind>, with :quote, :year, :date, :label…
    public readonly array $meta;
    public readonly bool $alone;         // false: a hint for the review call only
    public function toArray(): array;  public static function fromArray(array $a): self;
}

final class Anchor {
    public readonly AnchorScope $scope;  // Range (a quote in a text value), Field (the whole value), Asset (alt text)
    public readonly FieldPath $path;     // blocks by ID
    public readonly string $label;       // "Hero: Eyebrow"
    public readonly ?TextQuote $quote;   // Anchor\TextQuote: exact, prefix, suffix
    public readonly int $occurrence;     // which repeat of the quote in the field
    public readonly ?AssetRef $asset;    // Asset scope
    public readonly ?string $fieldHash;  // the field's text when read
    public readonly ?string $passage;    // the sentence(s) the quote is in: decisions last until it changes
    public function key(Category $c): string;
    public static function hash(string $text): string;
}
```

Ranges are matched in the field's text as `Anchor\QuoteFinder` normalises it with markdown syntax skipped, so `(new QuoteFinder)->find($anchor->quote, $markdown, $anchor->occurrence, markdown: true)` finds every range again in the field as stored, and the front end's port of `QuoteFinder` finds it in the editor. A range never crosses a paragraph, heading or list item.

`Category` gives `isWording()` (Voice, Clarity, SEO: what Accept all wording fixes takes), `rank()` (which wins an overlap: facts, dates, links, alt text, SEO, duplicates, clarity, voice), `label()` and `speech()` (message keys).

### Languages

The phrase lists are in `resources/suggest/phrases/{en,de,fr,nl,es}.php` (`Suggest\Phrases::for($locale)`). A site in another language gets only the checks that need no words: links, alt text, SEO length, overlaps, date fields. Dates are read with a year only: ISO, day-first numeric ("31/01/2025", "31.01.2025") and with the language's month names ("31 January 2025", "January 31, 2025", "31. Januar 2025", "1er février 2025", "31 de enero de 2025").

### Decisions that stick: `Quieted`

"It's still right" (a confirmed fact) and Dismiss keep a finding quiet for 12 months, or until the passage it was about is edited, whichever comes first. The passage is the sentence or sentences around the quote (the whole value for a field, the asset and its alt text for an image), so an edit elsewhere in the field changes nothing.

```php
$quiet = Quiet::of($finding->id, $finding->anchor, $now, Quiet::CONFIRMED, $userId);   // or Quiet::DISMISSED
$quieted = (new Quieted)->with($quiet);
$quieted->covers($id, $passageHash, $now);
```

`EditReviews::quieted($entry)` builds it from an entry's review history (below). Pass it to `CheckContext` so the guide, the revisit list and the review call leave those findings out.

### The settings these checks read

Only two, both named by the design's decisions:

- **The claim-check switch**, per site: `new SuggestOptions(claims: false)` turns off `stated-count` and the review call's claim flags. Closing dates are always checked.
- **The age switch, per group:** `AgePolicy::fromGroups($groupsWithADateField, $switchedOff)`. Groups with a date field weigh age and past years at a quarter (`AgePolicy::DATED_WEIGHT`); a switched-off group weighs like any other.

## Ports the addons implement

| Port | What | Contract |
|---|---|---|
| `Gaps\AssetAlt` | `altFor(AssetRef): ?string`: '' when empty, null when the container or volume has no alt field. Statamic: the container blueprint's `alt`; Craft: `Asset::$alt`. Filament has none (alt text is a form field). | `Tests\Contracts\AssetAltContract` |
| `Gaps\SeoFields` | `in(Schema, EntryData): list<SeoField>`: where the SEO title and description are, their limit, effective text and whether they're writable. `Gaps\PlainSeoFields` reads plain `seo_title` / `meta_description` fields and is the Filament implementation. | `Tests\Contracts\SeoFieldsContract` |
| `Suggest\EntryIndex` | `sharing(list<int> $shingles, EntryRef $except, int $limit = 5): list<IndexedParagraph>` and `nearest(EntryRef, string $text, int $limit = 20): list<DigestEntry>`, for one site. Paragraph shingles are `Suggest\Shingles::of($paragraph)`, computed on save. | `Tests\Contracts\EntryIndexContract` |
| `Gaps\LinkTargets` | Existing (Finish): broken links to the site's own entries, and candidates. | — |

In-memory versions for tests: `Gaps\Testing\MemoryAssetAlt`, `Suggest\Testing\MemoryEntryIndex`.

## Strings

`resources/lang/en/suggest.php`: `suggest.category.*`, `suggest.speech.*`, `suggest.finding.*`, `suggest.source.*`, `suggest.fact.*`, `suggest.review.*`. `Gaps\Message::english()` now reads any namespace that has a file in `resources/lang/en/` (`suggest`, `revisit`); keys without one are `gaps` keys, as before.
