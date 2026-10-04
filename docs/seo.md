# The SEO layer

The design is `seo-layer-design.md` in the website repo. This page is the API the addons call. Phase 1 so far covers **headings** (§19 rows 1–2); links, the search title and description, slugs and filenames come later, through the same `SeoPass`.

Nothing here calls a model.

## Heading levels

`NineteenNinetyFour\Ghostwriter\Core\Seo` and `Schema\HeadingLevels`.

### What the editors allow: `Schema\HeadingLevels`

Each addon's `SchemaReader` records the levels a rich-text field's editor offers in its field spec under `headings` (kept in `Field::$meta`):

| CMS | Field | `headings` |
|---|---|---|
| Statamic | Bard | the `h1`…`h6` in its `buttons` (Statamic's default buttons when the config has none); none → `[]` |
| Craft | CKEditor | the field's `headingLevels` when `heading` is in its toolbar; else `[]` |
| Filament | RichEditor | the `h1`–`h3` buttons in its toolbar; none → `[]`; `->ghostwriterHeadings(from: 1)` overrides `top` |
| any | Markdown, plain HTML | not set: every level |

```php
HeadingLevels::allowed(Field $field): array   // list<int>; [1..6] when the field doesn't say; [] for none, or a field that holds no headings
```

### Where the body starts: `RenderProfile`

How a group's template prints headings, read from a preview's outline:

```php
RenderProfile::default($key);                                     // the title is the H1; bodies start at ##
RenderProfile::fromOutline($key, Outline $outline, $seenAt, $label);
RenderProfile::fromEntries($key, Schema $schema, array $entries, RichTextDialect $richText);   // 80% of entries open a field with their own # → it owns the H1
RenderProfile::resolve($key, ?RenderProfile $stored, $schema, $entries, $richText);           // stored (rendered) > entries > default
$profile->top(Field $field, ?string $blockType = null): int;      // 2 under a template H1; 1 for a body that owns it; one below a block's own heading field
$profile->observe(RenderProfile $seen): RenderProfile;            // two renders must agree to change a stored profile
$profile->problem(): ?string;  $profile->note(): ?string;         // 'no-h1' | 'static-h1' | 'several-h1', and the developer note (English)
```

`h1` is an `H1Source`: `Title`, `Field` (`h1Field`: `hero.heading`), `Static` (a logo), `None`, `Several`. `fieldLevels` holds the block fields the template prints as headings (`section.heading => 2`); `bodyOwnsH1` the rich-text fields whose own `#` is the page's only H1.

**Ports.** `RenderProfiles` (`get`, `put`, `all`): Statamic a JSON file, Craft its state store. `Seo\Testing\InMemoryRenderProfiles` for tests. Filament needs none (the default and the entries).

**The outline.** `locator.js` gains `outline(doc, map, located)`: each `h1`–`h6` in document order with `{level, text, field, unit, inContent}`. The addon posts it after a render and calls:

```php
[$profile, $changed] = $seo->observe(RenderProfiles $profiles, string $key, Outline::fromArray($posted), string $label);
// $changed: what bodies are fitted to changed; build the plan again and render once more
```

### The rules: `HeadingPolicy` and `HeadingFixer`

```php
$policy = HeadingPolicy::for(Field $field, ?RenderProfile $profile, ?string $blockType);   // top + allowed
$policy->levels();  $policy->markdown();  $policy->constructs();
(new HeadingFixer)->fix(string $markdown, HeadingPolicy $policy): FixedHeadings          // ->markdown, ->changes (HeadingChange)
```

Bold lines become headings, empty headings go (one holding an `[[ask: …]]` is kept), levels are re-ranked from `top`, local skips are closed, and a heading deeper than the editor allows becomes a bold lead-in. Words never change; fixing twice is fixing once.

### In the pipeline: `SeoPass`

| | Where | What |
|---|---|---|
| ① `afterWriter($session, $site)` | `SessionLayouts::afterWriter()` and `afterEdit()`, before units are cut | Fits every rich-text value of the session's draft; rewrites `Session::$draft` only when something changed |
| ② `arranged($data, $site)` | `SessionLayouts::draftData()` | Fits a plan's arranged data every time it is built (layouts make headings); nothing stored |

`LayoutContext` gains `profile` (null: the default). Pass the resolved profile wherever the addon builds one, and to `Layout::fromSchema(…, $profile)` so the writer is told the same levels. `LayoutGate` and `LayoutDiff` compare rich text as ② fits it, so a layout that only moves heading levels the pass would move back is not "noticeably different". `PlanValidator` drops a plan that makes headings in a field whose editor shows none (`no-headings-here`).

### Contracts

`Tests\Contracts\HeadingLevelsContract` (the reader's levels; four levels into a two-level field through the real apply path) and `RenderProfileContract` (a seeded preview's outline says the title is the H1; two renders must agree).

## Internal links (row 4)

On a first draft, where the addon gives `LayoutContext` a `Seo\LinkContext`, the SEO pass links the writer's text to the site's other pages, before units and layouts are made from it, so every layout carries the links (decision 5).

```php
new LayoutContext($schema, …, links: new LinkContext(
    index: $linkIndex,            // Suggest\LinkIndex: related() over every routable page (decision 9); also a Suggest\LinkLookup (linkRow()) so the writer's links to real pages are kept
    links: new StatamicLinks,     // an InlineLinks dialect: how the field's rich text stores a link
    group: 'journal', site: 'default', except: null,
    kind: $contentKind, voice: $voiceGuide, locale: 'en_GB',
));

$layouts->afterWriter($session, $before, $response, $conversation, $writer, $site, function (string $stage) {
    // SeoPass::CHECKING ("Draft ready. Checking headings and links…"), then SessionLayouts::PLANNING ("Finding other layouts…")
});
```

| Step | Who | What |
|---|---|---|
| How many | `SeoLinks::target()` | About one per 250 words of prose, 2 to 5, less the links already there (the writer's links to real pages count; its `#gw-link:` markers don't, decision 23); none under 150 words |
| Where to | `LinkIndex::related()` | Up to 25 candidates, each one the dialect can link to (`InlineLinks::inlineHref()`); none: no call, notice `seo.notice.no-links` |
| The pick | `Studio::seoEdit(SeoRequest)` | One `seo-editor` call (writing tier, high effort, cached instructions): unit, words (`exact`, `prefix`), target (`e1`…, an enum), `why`. A target of `''` with a `hint` is a `#gw-link:` marker (one at most). Also `markers`: for each of the writer's markers (`WriterMarker`, m1…, an enum), the candidate it most likely means, or `''` (decision 24). With markers and no room for links, the call still runs, for them alone (`linkTarget` 0) |
| The checks | `LinkValidator` | The target is real and new; the words are in the unit once; 2–8 words, not vague, not the page's title, not a long target title pasted whole; not in a heading, bold, a quotation, a link, a marker, code or an address; one sentence; not the page's first sentence; one a unit; within the room |
| The second look | `Studio::verifySeoLinks(LinkCheck)` | One `seo-verifier` call: each link, and each suggestion for a marker (`MarkerSuggestion`, by its marker's id), in its paragraph, keep or drop. A failure keeps what passed the checks |
| Writing | `PlacedLink::linked()`, `DraftEditor` | `[words](href)`; the words never change |
| What is kept | `SeoState` on `Session::$seo` | `links` (unit, words, href, title, type, url, why), `removed`, `notice`, `checked`, `suggested` (hint, words, id, title, type, url, href, why; `SeoState::suggestion($hint, $words)`) |

`InlineLinks::inlineHref(DigestEntry)` is a separate interface from `LinkDialect`, as `LinkPlaceholders` is: `StatamicLinks` gives `statamic://entry::id` (a term: its address), `CraftLinks` `{entry:12@1:url||/address}`, `FilamentLinks` (new) the public address `->publicUrlUsing()` gives, `NoLinks` none. `HtmlDialect::fromMarkdown()` keeps a Craft reference tag in an href as CKEditor stores it (CommonMark would percent-encode its braces), and `LinkCandidates::linkKey()` reads CKEditor's in-editor form (`https://…/x#entry:12@1:url`) too.

**Later turns, edits and removals.** No call: `LinkGuard` runs after every writer turn (where the addon gives a `LinkContext`) and turns any address the writer wasn't given (not in the previous draft, not added by the pass, not a `#gw-link:` marker, not an outside address in the brief or the conversation, not a real page of the site) into a `#gw-link:` marker. **A link to a real page is kept** (decision 22) where the index is a `Suggest\LinkLookup`: `linkRow($href, $site)` (each addon hands its rows to `LinkCandidates::rowFor()`, which matches by `linkKey()`, and an absolute address only on the row's own host) finds a row of the draft's site that `Linkable` allows and that isn't the page itself, and the link is written as `InlineLinks::inlineHref()` gives it (`entry::abc` becomes `statamic://entry::abc`). `guard()` returns the kept ones as its third value. The writer's own markers are never resolved by the pass: Finish this page offers the page the pass suggested first, then the title matches. `SessionLayouts::removeLink($session, $href, $site)` is the Text tab's **Remove link**: the words stay, the link leaves `SeoState::$links` for `$removed`, and LinkGuard takes it out again if the writer puts it back.

**Finish this page.** `SessionGaps::fromSession()` carries `SeoState::$links`, and the `AddedLinks` detector (in `GapFinder::standard()`) gives one `links-added` suggestion a link still in the form ("Check 3 links Ghostwriter added. “…” goes to …"), with **Keep it** (`dismiss`, label `gaps.fix.keep-link`) and **Remove the link** (`remove-link`). Its meta has `href` (as stored), `formHref` (as the form holds it), `words`, `title`, `type`, `url`, `why` and `count`. A suggestion: never counted, never blocking. An addon that finds the session for an entry by its gap list should also take one whose `SessionGaps` isn't empty for its links.

**Suggested pages for the writer's markers** (decision 24). `SessionGaps::fromSession()` also carries `SeoState::$suggested`, and `LinkMarkers` puts a marker's suggestion (`SessionGaps::suggestion($hint, $words)`) first: a primary `link` fix, "Link to Contact us", whose value is the href the pass would have written (`statamic://entry::abc`, `{entry:12@1:url||…}`, the public address), then the title matches without the same page, then Choose an entry (or, with no `LinkTargets`, the address to type) and Remove the link. The gap's `meta.suggested` is `{id, title, href, url, type, why}`, and `meta.candidates` starts with it. Only inline markers get one; never resolved without the editor.

**Strings.** `resources/lang/en/seo.php`: the notice (`notice.links`, `notice.links-one`, `notice.no-links`), the status (`status.checking`) and the Text tab's (`link.added`, `link.added-long`, `link.remove`, `link.open`, `link.removed`); `gaps.links-added`, `gaps.links-added-one`, `gaps.speech.links-added`, `gaps.fix.keep-link`.

`Tests\Contracts\LinkInsertContract`: a link `inlineHref()` gives for a real page goes through the addon's real apply path into a rich-text field, reads back as a link to the same page with its words and the markers beside it, and renders as the page's address.
