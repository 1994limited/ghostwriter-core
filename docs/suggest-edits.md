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

## Content to revisit: `Revisit\*`

A list on the Overview that ranks published entries by the free checks, at no cost. No model is ever called, and no request is made unless the weekly external link check is on.

```php
use NineteenNinetyFour\Ghostwriter\Core\Revisit\{RevisitIndex, RevisitScanner, AgePolicy};

$scanner = new RevisitScanner(
    AgePolicy::fromGroups($groupsWithADateField, $switchedOff),   // the same policy the snapshots' CheckContexts use
    ownHosts: ['northfold.co.uk'],                                  // links to these aren't "other sites"
);
$index = new RevisitIndex($scanner, $revisitStore);

$index->refreshOne($entrySource, $ref, $now);                      // on save (queued, deduplicated)
$index->deleted($entrySource, $ref, $now, ['entry::abc']);         // on delete: forget it, rescan its linkers
$index->refresh($entrySource, $now, $lastRun, $site);              // daily
$index->refresh($entrySource, $now, $lastRun, $site, full: true);  // weekly, and the first run

$revisitStore->top($site, $group, limit: 25, offset: 0, kinds: ['past-year'], now: $now);  // the list
$revisitStore->count($site, $group, $kinds, $now);
$revisitStore->stats($site, $now);   // the tiles: entries per reason kind, and 'worth-a-look'
$revisitStore->put($row->snooze($now));   // Snooze for 90 days
```

- **Incremental.** `refreshOne()` scans one entry. `refresh()` scans the entries saved since `$lastRun`, and the rows whose `watch` date has come (the day after a closing date, 1 January for a "New for 2026", the day an entry turns a year old). It only re-scores every other row for its age. `full: true` scans everything and forgets rows whose entry has gone.
- **Scanning.** `RevisitScanner::scan(EntrySnapshot, $now, ?RevisitRow $previous)` runs `Findings::standard()->without('overlap')` at `$now` (`CheckContext::at()`). It keeps the previous row's snooze and external link results. `rescore(RevisitRow, $now)` updates age and external links without reading the entry. With nothing changed, it equals a scan.
- **Reasons** (`RevisitReason`, `ReasonKind`): `leftover`, `closing-date`, `broken-link`, `past-year`, `external-link`, `relative-time`, `empty-field`, `stated-count`, `missing-alt`, `seo-length`, `age`. Each has a `message()` (the chip, `revisit.reason.*`: "“New for 2024”", "1 broken link", "no alt text ×3", "2 years old") and a `severity()` (`high`, `medium`, `low`).
- **Score** (`Priority`): each kind's weight times its count, up to its cap, plus age (20 × `AgePolicy::share()`), capped at 100. In a dated group, age, past years and relative time weigh a quarter. `Priority::word($score)` gives `high` (≥ 60), `medium` (≥ 30, "worth a look") or `low`.
- **`RevisitRow`**: `entry`, `title`, `editUrl`, `updatedAt`, `reasons`, `score`, `priority()`, `checkedAt`, `contentHash`, `watch`, `linksTo` (what its internal links hold), `external` (URL ⇒ `LinkResult`), `snoozedUntil`, `snooze()`, `has()`, `toArray()`/`fromArray()`.
- **Reviewing** opens the entry with `?ghostwriter=suggest`. There is no overnight review: a review runs only when someone clicks.

### The weekly external link check (opt-in)

```php
$check = new ExternalLinkCheck(new HttpLinkProbe($httpClients), $scanner);
$check->run($revisitStore, new RevisitOptions(externalLinks: $siteSetting), $now, $site);   // weekly
```

- **Off by default.** `RevisitOptions::$externalLinks` is the site setting the addons expose, and only a manager can turn it on. With it off, `run()` makes no request.
- **Politeness.** Each address is checked at most once a week (`EVERY`), however many pages link to it, and at most `LIMIT` (500) addresses a run. Requests go one at a time, round-robin across hosts, at least `PER_HOST` (1 s) apart on one host, with a 10 s `TIMEOUT`. The probe sends a HEAD request, falls back to a one-byte GET when the site refuses HEAD, and gives a User-Agent that says what it is.
- **Results.** A 404, a 410 or a name that no longer resolves is `Broken`. 2xx and 3xx are `Ok`. Anything else (timeouts, 401, 403, 429, 5xx) is `Unknown` and never shown. A link counts as broken only after two Broken checks in a row (`LinkResult::FAILURES`). The results go into each linking row's `external`, so they show in the list at once (`external-link` reason). In the next review they also become Link findings: pass the row's `external` as `CheckContext::$external`.
- Links to the site's own entries are always checked, with no request (`LinkTargets`).

### Ports for the revisit list

| Port | What | Contract |
|---|---|---|
| `Revisit\RevisitStore` | `put`, `get`, `forget`, `top`, `count`, `stats`, `linkingTo`, `all`. Statamic: JSON shards per site and collection; Craft and Filament: a table, plus a links table indexed on the target. | `Tests\Contracts\RevisitStoreContract` |
| `Revisit\EntrySource` | `all($site, $chunk)` (published, chunked), `updatedSince($since, $site)`, `find($ref)` (unpublished comes back with `published` false). Each `EntrySnapshot` carries the `CheckContext` the free checks read. | `Tests\Contracts\EntrySourceContract` |
| `Revisit\LinkProbe` | `probe($url, $timeout): LinkResult`. Core's `HttpLinkProbe` works over any `Ai\Ports\HttpClients`; an addon may use its framework's client instead. | `Tests\Contracts\LinkProbeContract` |

In-memory versions for tests: `Revisit\Testing\InMemoryRevisitStore`, `Revisit\Testing\MemoryEntrySource`.

## The review call: `Studio::suggestEdits()`

One `reviewer` call per review, or one per part of a long page. It runs only when someone clicks **Suggest edits** (a menu item under **Edit with Ghostwriter**) or **Review** in the revisit list.

```php
$context  = new CheckContext(/* as above: the form's current values */);
$findings = Findings::standard()->find($context);
$input    = new ReviewInput(
    context: $context,
    writer: $writerContext,                       // the voice guide and the kind, as the writer gets them
    findings: $findings,
    digest: SiteDigest::build($context, $findings),   // link candidates, then EntryIndex::nearest(); at most 30
    images: [$finding->id => $thumbnail],          // Ai\Image, 512 px, for missing-alt findings; at most 4 a call
    replyLanguage: $cpLocale,                      // reasons in the person's language; replacements follow the page
);

$input->calls();                                   // 1, or more for a long page: say it in the confirm first
$reply  = $studio->suggestEdits($input);           // Result<SuggestionReply>: ->value, ->usage (tokens, every call)
$review = (new SuggestionValidator)->validate($reply->value, $input);   // ValidatedReview
$review->suggestions;                              // list<Suggestion>, in form order
$review->dropped;                                  // ['facts' => 1, 'anchor' => 2]: counts only, no text
```

- **Agent.** `reviewer`: prompt `resources/prompts/reviewer.md`, with the shared `scoped-edit.md`. It allows 8000 tokens at effort `medium`. It is not in `StudioOptions::WHOLE`: a cut-off reply is asked for again with twice the room, and if it's still cut off, every suggestion that closed is kept (`SuggestionReply::$truncated`).
- **Long pages are split** (`ReviewInput::WORDS_PER_CALL`, 6,000 words a call). Units are never split, and a field's units stay together where they fit. Each call sees its units, the findings in them and the whole digest. The instructions are the same for every call, so a provider can cache them. The replies are merged into one review. The own-suggestion cap is per call.
- **The prompt** (`Suggest\ReviewPrompt::render()`, pinned by `tests/Fixtures/suggest/reviewer-services.json`): `<page>` with `<unit id="u1" field="Hero: Eyebrow" type="text">` (`Arrange\Units::fromEntry()`, markdown), `<image id="i1" … attached="1">`, `<findings>` numbered `f1…` (`write` or `ask`), `<site>` (`e1…`) and `<dismissed>`. Links are never shown as stored: a digest entry reads `entry:e12`, any other link on the site reads `link:3`, and core puts the real targets back.
- **Images** go through `ModelInputGuard` in the Studio. A refused image (Getty, iStock) isn't attached, and its finding stays "Describe it yourself".
- **The reply** is JSON in `<suggestions>`, matching `resources/schemas/suggestions.schema.json`. `SuggestionReader` reads it leniently: code fences, a trailing comma, a single object, or a cut-off list (keeping the objects that closed). An unreadable reply gives no suggestions and logs a warning with no reply text, unless `logReplies` is on.

### Never inventing facts: `SuggestionValidator`

Each suggestion the model writes is checked alone. A failing one is **dropped, never repaired**, and counted by reason (`SuggestionValidator::DROPS`):

| Reason | Rule |
|---|---|
| `anchor` | The unit isn't in the call, the quote isn't in it (`QuoteFinder`: exact, then by context, then one fuzzy match ≥ 0.9, which is re-quoted to the real text), the quote is ambiguous, or the range crosses a paragraph. A whole value may be replaced only in a short field (≤ 120 characters) or an SEO field. |
| `finding` / `declined` | A suggestion naming a finding takes the finding's anchor and category. A finding named twice keeps the first. Only Duplicate and Clarity findings may be declined. |
| `facts` | `SourceCheck` through `ScopedEditCheck`: a figure, quotation or name that isn't on the page or in the cited site entry. A Fact to check never keeps a replacement. Its template must be the quote with exactly one span replaced by `{answer}`, and its `without` must add nothing. |
| `scope`, `size`, `markers`, `link` | `ScopedEditCheck`: it stays in its sentences, keeps `[[ask: …]]`, `[[check: …]]` and `#gw-link:`, adds no outside link, and keeps a sensible size (Clarity may shrink to 20%, Duplicate to nothing). An SEO value must fit its limit. A link may point only at a digest entry. |
| `claims` | A claim the model flagged on its own, with the site's claim checks off. |
| `voice` | A Voice suggestion when no voice guide has been written. |
| `dismissed` | What a decision keeps quiet (`Quieted`). |
| `overlap` | A finding's fix beats the model's own suggestion, then the lower `Category::rank()` wins, then the shorter range. |
| `cap`, `page-share` | At most `cap` of the model's own suggestions a call (12 by default), 3 in a field, and at most 30% of the page's words changed by them. Lowest-ranked go first. |

Alternatives are checked one by one, and a failing one is dropped on its own. A source the model claims that can't be shown (a voice guide heading that isn't one, an entry it wasn't shown) becomes `general`, and the reason is kept. Alt text is clipped to 125 characters and loses "Image of". Findings the model didn't fix stay as their free form (`Finding::toSuggestion()`): a link candidate, an answer box, or "Rewrite it yourself".

### Suggestions

```php
final class Suggestion {
    public readonly string $id;               // Anchor::key(): stable across reviews
    public readonly Category $category;
    public readonly Anchor $anchor;
    public readonly Reason $reason;           // text (or a finding's message), source (ReasonSource), detail, entry; sourceLabel()
    public readonly ?string $replacement;     // inline markdown; null for a Fact to check or "Rewrite it yourself"
    public readonly array $alternatives;      // up to 2: "Another version", free
    public readonly ?FactCheck $fact;         // ask, template with {answer}, without, answer (AnswerKind); fill($answer)
    public readonly ?LinkChange $link;        // target as stored, title, url, free
    public readonly ?string $finding;         // the finding it fixes
    public readonly bool $free;               // found and fixed with no model
    public SuggestionState $state;            // Open, Accepted, Dismissed, Confirmed, Done, Stale, Expired
    public function toArray(): array;         // for the guide
}
```

`FactCheck::fill('8')` gives "team of 8". It throws `InvalidArgumentException` with the message key `suggest.fact.number-only` for "8 or 9?".

### "Write another": `Studio::reword()`

Up to two alternatives come with the first call, so "Another version" is free. After that, the button reads **Write another (uses Ghostwriter)**:

```php
$request  = RewordRequest::for($suggestion, $context, $voiceGuide, $versionsShownSoFar);
$versions = $studio->reword($request);                       // Result<list<string>>, at most 2, none already shown
$kept     = array_filter($versions->value, fn ($v) => $validator->acceptsVersion($suggestion, $v, $input));
```

`reworder` allows 1500 tokens at effort `low`, in the quick tier. It sees the sentence and one sentence either side, the reason, every version shown so far and the voice guide, and never the rest of the page. Each new version passes the same checks as the first. When none passes, say `suggest.review.another-none`.

### Cost

Shown in tokens only, never money. Every `Result` carries `usage` (input and output), counting every call, including re-asks and every part of a split page. Nothing model-backed runs on load, typing, saving, polling, the free checks, the revisit index or the external link check.

| Action | Model calls |
|---|---|
| Suggest edits (or Review in the list) | `calls()` × `reviewer`, usually 1 |
| Another version (stored alternatives), Accept, Edit, Dismiss, Undo, It's still right | 0 |
| Write another | 1 small `reworder` |

## Ports the free checks read

| Port | What | Contract |
|---|---|---|
| `Gaps\AssetAlt` | `altFor(AssetRef): ?string`: '' when empty, null when the container or volume has no alt field. Statamic: the container blueprint's `alt`; Craft: `Asset::$alt`. Filament has none (alt text is a form field). | `Tests\Contracts\AssetAltContract` |
| `Gaps\SeoFields` | `in(Schema, EntryData): list<SeoField>`: where the SEO title and description are, their limit, effective text and whether they're writable. `Gaps\PlainSeoFields` reads plain `seo_title` / `meta_description` fields and is the Filament implementation. | `Tests\Contracts\SeoFieldsContract` |
| `Suggest\EntryIndex` | `sharing(list<int> $shingles, EntryRef $except, int $limit = 5): list<IndexedParagraph>` and `nearest(EntryRef, string $text, int $limit = 20): list<DigestEntry>`, for one site. Paragraph shingles are `Suggest\Shingles::of($paragraph)`, computed on save. | `Tests\Contracts\EntryIndexContract` |
| `Gaps\LinkTargets` | Existing (Finish): broken links to the site's own entries, and candidates. | — |

In-memory versions for tests: `Gaps\Testing\MemoryAssetAlt`, `Suggest\Testing\MemoryEntryIndex`.

## Strings

`resources/lang/en/revisit.php`: `revisit.reason.*`, `revisit.priority.*`, the tiles, the note, the empty state and the external link setting's label and help. `resources/lang/en/suggest.php`: `suggest.category.*`, `suggest.speech.*`, `suggest.finding.*`, `suggest.source.*`, `suggest.fact.*`, `suggest.review.*`. `Gaps\Message::english()` now reads any namespace that has a file in `resources/lang/en/` (`suggest`, `revisit`); keys without one are `gaps` keys, as before.
