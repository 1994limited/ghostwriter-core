# Suggest edits and Content to revisit

Ghostwriter reviews an existing entry and suggests small, anchored changes, which the editor steps through in the Finish this page guide. Content to revisit ranks the site's published entries by the same free checks. This page is the API the addons call, as built. The design is `addon-reviews/suggest-edits-design.md`; where this page and the design differ, this page is right.

Everything here is framework-free. Only the review (`Studio::suggestEdits()`, then `Studio::verifyEdits()`) and "Write another" (`Studio::reword()`) use a model, and only when someone clicks a button that says so.

**No suggestion reaches the editor without the model having judged it in context.** The free checks only find and explain: they never write replacement text. In a review, every free finding goes to the review call as a candidate, and the model keeps it (with a fix that fits its sentence and paragraph) or drops it. What it keeps passes the deterministic checks, then a second model pass (the verifier), before it is shown.

## How an addon wires it

1. **Edit with Ghostwriter → Suggest edits** (a menu item; hint: "Reads the page against your voice guide. Uses Ghostwriter once."). On open, call `EditReviews::preview()` and show the free findings at once, marked "Found without AI" (where and why; no new words).
2. Build a `ReviewInput`. Its `calls()` (the number of parts) goes in the confirm: "Uses Ghostwriter once", or, for a long page, `suggest.review.split` with the number of parts. Each part is a `reviewer` call and then a `verifier` call: `modelCalls()` (2 × `calls()`) is the most a review makes, for anywhere the addon shows a count of model calls. Call `start()`, then queue `run()`.
3. The guide steps through the suggestions (`EditReview::open()`). Accept, Edit, Another version, Dismiss, Undo, It's still right, Use it and Link to it each call `decide()` or `undo()`. Write another calls `another()`. Changes go into the form; nothing is saved except alt text, after its confirm.
4. On save, call `EditReviews::saved()` and `RevisitIndex::refreshOne()`. On delete, call `RevisitIndex::deleted()`.
5. **Daily:** `RevisitIndex::refresh()` and `EditReviews::expire()`. **Weekly:** `RevisitIndex::refresh(full: true)`, and `ExternalLinkCheck::run()` when the site has turned it on.

### The only settings

| Setting | Where | Default |
|---|---|---|
| Check links to other sites once a week | `Revisit\RevisitOptions::$externalLinks`, per site, managers only | off |
| A quarter weight for age in a group with a date field | `Revisit\AgePolicy::fromGroups($withDateField, $switchedOff)`, per group | on for groups with a date field |
| Claim checks | `Suggest\SuggestOptions::$claims`, per site | on |

There is no overnight review: a review runs only when someone clicks.

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
    quieted: $editReviews->quieted($entryRef, $now),   // decisions that keep findings quiet
));

$report->findings;       // list<Finding>, in form order
$report->gaps;           // Finish this page's GapReport (for the revisit list)
$report->leftovers();    // unfinished markers, placeholders and stray tokens
$report->emptyFields();  // required or expected fields left empty
```

`Findings::standard()` runs these checks, and the Gaps detectors whose finds are suggestions:

| Kind | Category | Needs | What it finds |
|---|---|---|---|
| `past-year` | Out of date | words | A year before this one in a phrase that reads as current: "New for 2024", "our 2025 prices", "as of 2023". History ("since 2015", "founded in 2009", "in 2019 we won") and a year on its own ("our 2023 show garden") are left alone. In a dated group (`AgePolicy`), a year the entry was written in or after is history too. Anchored on the sentence (below). |
| `relative-time` | Out of date | words | "this year", "next spring", "currently", "coming soon"… in an entry last saved 12 months ago or more. Anchored on the sentence (below). |
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

**Out of date findings are anchored on their sentence.** `past-year` and `relative-time` quote the whole sentence the dated words are in (`CheckText::sentenceAnchor()`: `Sentences::covering()` inside the block, cut at words to `TextQuote::MAX_EXACT` around the words when the sentence is longer). A heading line ("## New for 2023") is a sentence of its own, and a short value ("New for 2023: winter care visits") is quoted whole. There is one finding a sentence: a past year and a time word in the same sentence are one Out of date finding. The dated words are in `meta['phrase']`, exactly as written, and `meta['phraseOffset']` is where they start in the quote's `exact`, in characters, for the guide to highlight them. The message's `:quote` is the phrase. Closing dates and stated counts (Fact to check) stay on the phrase itself.

For the sentence around any other range, `CheckText::passage($offset, $length)` gives its text and `CheckText::sentenceRange($offset, $length)` its [offset, length] in the plain text; `CheckText::headingBefore($offset)` gives the heading it sits under.

### Findings and anchors

```php
final class Finding {
    public readonly string $id;          // Anchor::key($category): "out-of-date|page_builder/#h1/eyebrow|new for 2024|0"
    public readonly Category $category;  // OutOfDate, Voice, Clarity, FactToCheck, Link, Accessibility, Seo, Duplicate
    public readonly string $kind;        // the table above
    public readonly Anchor $anchor;
    public readonly Needs $needs;        // Nothing, Words, Editor
    public readonly Message $message;    // suggest.finding.<kind>, with :quote, :year, :date, :label…
    public readonly array $meta;         // Out of date: phrase, phraseOffset; and year, date, candidates, template…
    public readonly bool $alone;         // false: not shown in the free half (a long sentence)
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

"It's still right" (a confirmed fact or dated claim), Dismiss, and a review's own judgement that a candidate is fine in context (`Quiet::CHECKED`) keep a finding quiet for 12 months, or until the passage it was about is edited, whichever comes first. The passage is the sentence or sentences around the quote (the whole value for a field, the asset and its alt text for an image), so an edit elsewhere in the field changes nothing.

```php
$quiet = Quiet::of($finding->id, $finding->anchor, $now, Quiet::CONFIRMED, $userId);   // or Quiet::DISMISSED, Quiet::CHECKED
$quieted = (new Quieted)->with($quiet);
$quieted->covers($id, $passageHash, $now);
```

`EditReviews::quieted($entry, $now)` builds it from an entry's review history (below). Pass it to `CheckContext` so the guide, the revisit list and the review call leave those findings out.

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
- **Reasons** (`RevisitReason`, `ReasonKind`): `leftover`, `closing-date`, `broken-link`, `past-year`, `external-link`, `relative-time`, `empty-field`, `stated-count`, `missing-alt`, `seo-length`, `age`. Each has a `message()` (the chip, `revisit.reason.*`: "“New for 2024”": an Out of date chip quotes `meta['phrase']`, never the whole sentence, "1 broken link", "no alt text ×3", "2 years old") and a `severity()` (`high`, `medium`, `low`).
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

## The review call: `Studio::suggestEdits()`, then `Studio::verifyEdits()`

One `reviewer` call per part of the page (usually one), then one `verifier` call per part that kept anything. They run only when someone clicks **Suggest edits** (a menu item under **Edit with Ghostwriter**) or **Review** in the revisit list. `EditReviews::run()` does all of it; an addon that runs the steps itself does the same:

```php
$context  = new CheckContext(/* as above: the form's current values */);
$findings = Findings::standard()->find($context);
$input    = new ReviewInput(
    context: $context,
    writer: $writerContext,                       // the voice guide and the kind, as the writer gets them
    findings: $findings,                          // every one is a candidate: f1, f2…
    digest: SiteDigest::build($context, $findings),   // link candidates, then EntryIndex::nearest(); at most 30
    images: [$finding->id => $thumbnail],          // Ai\Image, 512 px, for missing-alt findings; at most 4 a call
    replyLanguage: $cpLocale,                      // reasons in the person's language; replacements follow the page
);

$input->calls();                                   // parts: 1, or more for a long page. Say it in the confirm first
$input->modelCalls();                              // the most model calls: a reviewer and a verifier call per part
$reply    = $studio->suggestEdits($input);         // Result<SuggestionReply>: ->value, ->usage (tokens, every call)
$review   = $validator->validate($reply->value, $input);              // ValidatedReview
$verdicts = $studio->verifyEdits($input, $review->suggestions);     // Result<SuggestionReply>, verdicts as items
$review   = $validator->verify($review, $verdicts->value, $input);   // ValidatedReview, after the second pass
$review->suggestions;                              // list<Suggestion>, in form order
$review->dropped;                                  // ['declined' => 2, 'fit' => 1]: counts only, no text
$review->checked;                                  // candidates dropped in context: [['id', 'passage', 'reason', 'by'], …]
$review->verified;                                 // [suggestion id => 'keep' | 'fix']
```

- **Agents.** `reviewer` (prompt `resources/prompts/reviewer.md`) and `verifier` (`resources/prompts/verifier.md`), each with the shared `scoped-edit.md`, both on the writing tier (the writer's), at effort `high`, with 16000 and 12000 tokens. Neither is in `StudioOptions::WHOLE`: a cut-off reply is asked for again with twice the room, and if it's still cut off, everything that closed is kept (`SuggestionReply::$truncated`).
- **Prompt caching.** The instructions (the rules and the voice guide) are the same for every call of a review. For `reviewer`, `verifier` and `reworder` (`Agents::CACHED`), Anthropic gets them as a system block marked `cache_control` (ephemeral), and so does Claude through OpenRouter; other providers cache on their own. Below a provider's minimum size, nothing is cached.
- **Long pages are split** (`ReviewInput::WORDS_PER_CALL`, 6,000 words a part). Units are never split, and a field's units stay together where they fit. Each call sees its units, the candidates in them and the whole digest. The replies are merged into one review. The own-suggestion cap is per part.
- **The prompt** (`Suggest\ReviewPrompt::render()`, pinned by `tests/Fixtures/suggest/reviewer-services.json`): `<page>` (title, kind, last updated, today) with `<unit id="u1" field="Hero: Eyebrow" type="text">` (`Arrange\Units::fromEntry()`, markdown: the whole paragraph is there), `<image id="i1" … attached="1">`, `<candidates>`, `<site>` (`e1…`) and `<dismissed>`. Links are never shown as stored: a digest entry reads `entry:e12`, any other link on the site reads `link:3`, and core puts the real targets back.
- **Candidates.** Every free finding, of every kind (the long-sentence hint and a link field to a deleted page included), one line each: number, category, unit, the heading it sits under (the nearest heading line before it in its field), its quote, the check's message, the dated words for an Out of date one, and what to do if it is kept:

  ```
  f1 out-of-date u2 "New for 2024: winter care visits": Says “New for 2024” in 2026. Dated words: "New for 2024". If kept: rewrite the whole sentence.
  f3 clarity u4 under "Garden design" "In terms of …": This sentence has 36 words. Keep only if it reads badly here; then write.
  ```

- **Keep or drop.** The model answers every candidate once: a kept suggestion (`"finding": "f3"` and the fix) or `{"finding": "f3", "drop": "<a few words why>"}`. Any category may be dropped ("New for 2023" in a 2023 journal post is history). `SuggestionReader` also reads the older `decline` key as `drop`. For an Out of date candidate the replacement is the whole sentence rewritten so it no longer states a past date as current, with up to two meaningfully different alternatives, one of them the sentence with the dated words taken out and tidied ("New for 2023: winter care visits" → "Winter care visits", "Our winter care visits"). The model's own suggestions (voice, clarity, facts…) follow the same rule: only a real problem with the paragraph in view. It never supplies a fact.
- **Images** go through `ModelInputGuard` in the Studio. A refused image (Getty, iStock) isn't attached; if the model keeps its candidate, it has no replacement ("Describe the image yourself").
- **The reply** is held to `resources/schemas/reviewer-reply.json` by the provider where the model has structured output (`Studio::reviewerSchema()`, see [providers.md](providers.md#structured-output)): bare JSON, every item starting with a short `notes` field (what the model checked in context, never shown), so it looks before it answers. The instructions then say "Your reply is JSON in the shape you are given"; for a model without structured output they ask for the same JSON in `<suggestions>`, as before (`{{ answer_intro }}`, `{{ answer_open }}`, `{{ answer_close }}` in the prompt). Either way `SuggestionReader` reads it, leniently: code fences, a trailing comma, a single object, a cut-off list (keeping the objects that closed), and a null key (as strict modes send what's left out) read as left out. Every reply also matches the contract, `resources/schemas/suggestions.schema.json`, checked by a test. A schema guarantees the shape, not the truth: `SuggestionValidator` checks every item as before.
- **Unreadable, asked once more.** A reply that can't be read, and wasn't cut off, is asked for again with the same instructions and images and the problem quoted after the prompt ("Your last answer to this couldn't be read: the JSON did not parse. …"). The second reply stands. Each unreadable reply logs a warning with no reply text, unless `logReplies` is on; one still unreadable gives no suggestions.

### The second pass: the verifier

After validation, `Studio::verifyEdits($input, $review->suggestions)` makes one `verifier` call per part that kept anything. It is shown each kept suggestion, numbered `s1, s2…` across the review in form order (`Suggest\VerifyPrompt`, pinned by `tests/Fixtures/suggest/verifier-services.json`): its whole paragraph, the heading it sits under, its field, the quote, the replacement and alternatives (or the fact's template), why it was made and the site entry it cites or links to. The entry's title, kind, last-updated date and today are on `<page>`; the voice guide and the kind are in the instructions. For each it checks that it is a real problem in context, that the replacement reads naturally in its sentence and paragraph, that it adds no fact, and that it is in the site's voice, and answers (held to `resources/schemas/verifier-reply.json`, `Studio::verifierSchema()`, where the model has structured output; in `<verdicts>` tags where it doesn't; asked once more when unreadable, as the reviewer is):

```
{"verdicts": [
  {"notes": "…", "id": "s1", "verdict": "keep", "reason": "…"},
  {"notes": "…", "id": "s2", "verdict": "fix", "replacement": "…", "alternatives": ["…"], "reason": "…"},
  {"notes": "…", "id": "s3", "verdict": "drop", "reason": "…"}
]}
```

`SuggestionValidator::verify()` applies it:

- `keep`: shown as it was; `verified[id] = 'keep'`.
- `fix`: the new replacement, and the new alternatives if it gives any (else the old ones), pass every check again, `fit` included. A fix that fails drops the suggestion (counted by the reason, not checked, so it can come back). An alternative that fails is dropped alone. `verified[id] = 'fix'`. A fix of a Fact to check, or of a suggestion with no words, is a keep.
- `drop`: not shown; it goes in `checked` with `by: 'verifier'` and its reason, and counts as `verifier` in `dropped`.
- A suggestion it didn't answer is kept as it was. When the verifier call fails or can't be read, the validated suggestions stand, a warning is logged and `EditReview::$verifyError` says why.

### Never inventing facts: `SuggestionValidator`

Each suggestion the model writes is checked alone. A failing one is **dropped, never repaired**, and counted by reason (`SuggestionValidator::DROPS`):

| Reason | Rule |
|---|---|
| `anchor` | The unit isn't in the call, the quote isn't in it (`QuoteFinder`: exact, then by context, then one fuzzy match ≥ 0.9, which is re-quoted to the real text), the quote is ambiguous, or the range crosses a paragraph. A whole value may be replaced only in a short field (≤ 120 characters) or an SEO field. |
| `finding` | A suggestion naming a candidate takes the finding's anchor and category. A candidate answered twice keeps the first; a candidate that doesn't exist, or a drop that names none, is dropped. |
| `declined` | A candidate the model dropped in context. Not shown; stored in `checked` with its reason. |
| `unanswered` | A candidate the model neither kept nor dropped. Not shown, and not checked: it is a candidate again next time. |
| `facts` | `SourceCheck` through `ScopedEditCheck`: a figure, quotation or name that isn't on the page or in the cited site entry. A Fact to check never keeps a replacement. Its template must be the quote with exactly one span replaced by `{answer}`, and its `without` must add nothing. |
| `scope`, `size`, `markers`, `link` | `ScopedEditCheck`: it stays in its sentences, keeps `[[ask: …]]`, `[[check: …]]` and `#gw-link:`, adds no outside link, and keeps a sensible size (Clarity may shrink to 20%, Duplicate to nothing). An SEO value must fit its limit. A link may point only at a digest entry. A kept candidate with no fix is `scope`, except a link field, a broken link (the link is the fix) and an image whose picture wasn't attached, which are kept with no replacement. |
| `fit` | `Anchor\SentenceFit`, after the checks above, on every replacement and alternative (and every "Write another" version): put in place of its quote, the field still reads as whole sentences. A quote that started a sentence with a capital needs a replacement that starts with a capital, a digit or a quote mark. A quote that ended with `.`, `!`, `?` or `…` needs a replacement that does too; one that didn't, with the sentence going on, needs one that doesn't end it early. No word is doubled where it joins the text ("first of all all visit"). Nothing empty in the middle of a sentence, except a Duplicate. |
| `claims` | A claim the model flagged on its own, with the site's claim checks off. |
| `voice` | A Voice suggestion when no voice guide has been written. |
| `dismissed` | What a decision keeps quiet (`Quieted`), checked fine included. |
| `overlap` | A candidate's fix beats the model's own suggestion, then the lower `Category::rank()` wins, then the shorter range. |
| `cap`, `page-share` | At most `cap` of the model's own suggestions a part (12 by default), 3 in a field, and at most 30% of the page's words changed by them. Lowest-ranked go first. |
| `verifier` | Dropped by the verifier (above). |

Alternatives are checked one by one, and a failing one is dropped on its own. A source the model claims that can't be shown (a voice guide heading that isn't one, an entry it wasn't shown) becomes `general`, and the reason is kept. Alt text is clipped to 125 characters and loses "Image of". There is no free fallback: a candidate the model didn't keep is never shown in a review (`Finding::toSuggestion()` is only for the free half, `preview()`).

Each dropped candidate is logged at debug level with its reason only, no page text.

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

`reworder` allows 4000 tokens at effort `medium`, on the writing tier. It sees the sentence and one sentence either side, the reason, every version shown so far and the voice guide, and never the rest of the page. Each new version passes the same checks as the first, `fit` included. When none passes, say `suggest.review.another-none`.

### Cost

Shown in tokens only, never money. Every `Result` carries `usage` (input and output), counting every call, including re-asks (a cut-off reply, an unreadable one) and every part of a split page. The reply's schema is part of each request but the same for every call of an agent, so the cached instructions still hit (`cache_read_tokens` in the "A model call finished." log line). `EditReview::$usage` adds the reviewer's and the verifier's, and `EditReview::$calls` counts both. The verifier roughly doubles a review's tokens; the top tier and effort `high` cost more again, for better suggestions. Nothing model-backed runs on load, typing, saving, polling, the free checks, the revisit index or the external link check.

| Action | Model calls |
|---|---|
| Suggest edits (or Review in the list) | `calls()` × `reviewer` and up to `calls()` × `verifier` (`modelCalls()`), usually 2 |
| Another version (stored alternatives), Accept, Edit, Dismiss, Undo, It's still right | 0 |
| Write another | 1 small `reworder` |

## The record: `EditReview` and `EditReviews`

A review is a record of its own, per entry and site. It is not the writing `Session`. It is shared under E7: everyone who can edit the entry sees it, as `shared_conversations` says. `EditReviews` is the service the addons call. It holds no CMS code.

```php
$reviews = new EditReviews($editReviewStore, $lock, $studio);

$reviews->preview($checkContext, $ref);                      // ['findings' => free suggestions, 'review' => latest, re-checked]; no model
$review = $reviews->start($ref, $viewer, $now);               // claims the entry; queue run(). Domain\Busy while another run holds it
$review = $reviews->run($review->id, $reviewInput, $now);     // the queued job: reviewer, validation, verifier, stored Ready (or Failed)
$reviews->decide($id, $suggestionId, SuggestionState::Accepted, $viewer, $now, text: $wordsThatWentIn);
$reviews->decide($id, $suggestionId, SuggestionState::Dismissed, $viewer, $now);
$reviews->decide($id, $suggestionId, SuggestionState::Confirmed, $viewer, $now);    // It's still right (Fact to check or Out of date)
$reviews->decide($id, $suggestionId, SuggestionState::Accepted, $viewer, $now, answer: '8');   // Use it
$reviews->undo($id, $suggestionId, $viewer, $now);
$reviews->another($id, $suggestionId, $reviewInput, $now);    // Write another: list<string>, validated
$reviews->saved($ref, $savedCheckContext, $now);              // after a save: Done and Stale (Reconciler)
$reviews->expire($now);                                       // daily: unactioned suggestions after 14 days
$reviews->quieted($ref, $now);                                // Quieted, for every CheckContext of this entry
```

- **History.** Decisions (`Decision`: suggestion, state, by, at, answer, text) are only ever added. A suggestion's state is its last decision's. Undo adds an Open decision. Core adds Done, Stale and Expired with `by` null. `EditReviewStore::history($entry)` lists an entry's reviews, newest first. A decision on the same suggestion (same id) in an earlier review carries over to a new one.
- **Expiry.** Suggestions nobody acted on expire `EditReview::EXPIRES_DAYS` (14) after the review finished: `expire()` marks them Expired and drops their words. Reviews and decisions are kept. `EditReviewStore::delete()` is only for a deleted entry.
- **Dismissals stick.** `quieted()` gives each suggestion's last Dismissed or Confirmed decision as a `Quiet`. It lasts 12 months, or until its passage changes. Pass it to `CheckContext` so the free checks, the revisit list and the next review all leave it out.
- **Checked candidates stick too.** `EditReview::$checked` (list of `['id' => finding or suggestion id, 'passage' => passage hash, 'reason' => string, 'by' => 'reviewer' | 'verifier']`, in `toArray()`/`fromArray()`, default `[]`) holds what the reviewer or the verifier dropped in context. `quieted()` adds each as a `Quiet` of state `Quiet::CHECKED`, for 12 months from when the review finished or until its passage changes, so the same words aren't a candidate next time; the next prompt's `<dismissed>` lists them as "checked fine". `EditReview::$verified` (suggestion id ⇒ `keep` or `fix`) and `EditReview::$verifyError` record the second pass.
- **It's still right** works on a Fact to check and on an Out of date suggestion (a dated claim that is still true): `decide(…, Confirmed)` keeps it quiet like any confirmation.
- **One run per entry at a time.** `start()` works under the `Lock` (`edit-review:<entry key>`). A run that has held the entry for `STALE_RUN` (15 minutes) is taken to have died. `Busy::messageFor()` gives "Priya is reviewing this page. It opens here when it is ready."
- **Concurrency.** Every change is made under the entry's lock with the store's version check, and retried once on a `Conflict`.
- **Access** (`EditReviewAccess::from($domainOptions)`): shared, anyone who can edit the entry; not shared, its starter and admins. `canDecide()` also needs the run to have finished.
- **Accepted** is recorded for the history. The change itself is in one person's form until they save, and `saved()` then marks it Done.
- **Alt text** is saved to the asset by the addon, after its confirm. Then `decide(…, Accepted)`. The Reconciler marks it Done from `AssetAlt`.
- **Failed.** When the review call fails or its reply can't be read, the review is Failed with a short `error` and **no** suggestions: nothing is shown that the model didn't judge. The free findings are still in the guide from `preview()`. When only the verifier fails, the review is Ready with the validated suggestions, and `verifyError` says why.

### `Reconciler`

`reconcile(EditReview, CheckContext $saved, $now)`: for each suggestion still open or accepted, **Done** when the saved field has its new words. Those can be the replacement, an alternative, a later version, the editor's own words, the filled template, the version without, the new link target, or the asset's alt text. **Stale** when its quote is gone and none of those is there, or a whole-value field changed to something else. Otherwise it stays as it was. `preview()` runs it on a copy against the form's current values, so a stored review opens with its stale suggestions marked and nothing stored.

### The port

| Port | What | Contract |
|---|---|---|
| `Suggest\EditReviewStore` | `find`, `latestFor`, `history`, `save` (version check, `Domain\Conflict`), `dueToExpire`, `delete`. Statamic: a JSON file per review, with a pointer per entry. Craft: `{{%ghostwriter_edit_reviews}}`. Filament: an Eloquent table with `workspace`. | `Tests\Contracts\EditReviewStoreContract` |

In-memory version for tests: `Suggest\Testing\InMemoryEditReviewStore`.

## Ports the free checks read

Every port the addons implement, in one place: `Gaps\AssetAlt`, `Gaps\SeoFields`, `Suggest\EntryIndex` (below), `Revisit\RevisitStore`, `Revisit\EntrySource`, `Revisit\LinkProbe` (core's `HttpLinkProbe` will do) and `Suggest\EditReviewStore` (above), plus the existing `Gaps\LinkTargets`, `AssetRefs`, `PlaceholderAssets` and `Domain\Lock`. Each has a contract trait in `tests/Contracts` the addon's own test extends.

| Port | What | Contract |
|---|---|---|
| `Gaps\AssetAlt` | `altFor(AssetRef): ?string`: '' when empty, null when the container or volume has no alt field. Statamic: the container blueprint's `alt`; Craft: `Asset::$alt`. Filament has none (alt text is a form field). | `Tests\Contracts\AssetAltContract` |
| `Gaps\SeoFields` | `in(Schema, EntryData): list<SeoField>`: where the SEO title and description are, their limit, the text the page prints (resolved as the SEO addon does: the entry, then the section's defaults, then the site's, by `EntryData::$group` and `$site`), where it comes from (`SeoSource`: custom, another field, a default, a template, switched off) and whether they're writable. `noindex()`: the robots setting. `titleFormat()`: the site name, separator and position the `<title>` adds. `Gaps\PlainSeoFields` reads plain `seo_title` / `meta_description` fields and a `noindex` toggle. An inherited description that fits is never a suggestion (decision 11 of the SEO layer). | `Tests\Contracts\SeoFieldsContract` |
| `Suggest\EntryIndex` | `sharing(list<int> $shingles, EntryRef $except, int $limit = 5): list<IndexedParagraph>` and `nearest(EntryRef, string $text, int $limit = 20): list<DigestEntry>`, for one site. Paragraph shingles are `Suggest\Shingles::of($paragraph)`, computed on save. | `Tests\Contracts\EntryIndexContract` |
| `Gaps\LinkTargets` | Existing (Finish): broken links to the site's own entries, and candidates. | — |

In-memory versions for tests: `Gaps\Testing\MemoryAssetAlt`, `Suggest\Testing\MemoryEntryIndex`.

## Strings

`resources/lang/en/revisit.php`: `revisit.reason.*`, `revisit.priority.*`, the tiles, the note, the empty state and the external link setting's label and help. `resources/lang/en/suggest.php`: `suggest.category.*`, `suggest.speech.*`, `suggest.finding.*`, `suggest.source.*`, `suggest.fact.*`, `suggest.review.*`. `Gaps\Message::english()` now reads any namespace that has a file in `resources/lang/en/` (`suggest`, `revisit`); keys without one are `gaps` keys, as before.
