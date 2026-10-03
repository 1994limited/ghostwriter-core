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
