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
| The second look | `Studio::verifySeoLinks(LinkCheck)` → `LinkVerdicts`, `LinkValidator::judged()` | One `seo-verifier` call: each link, and each suggestion for a marker (`MarkerSuggestion`, by its marker's id), in its paragraph. It judges the page first: `drop` only when the page is wrong or misleading there; `keep-with-anchor` when the page is right but the words are weak, with better words (`anchor`) from the same sentence. `judged()` checks those words by the rules above (and the same sentence) and moves the link onto them; words that fail leave the link on its first words. A failure keeps what passed the checks |
| Writing | `PlacedLink::linked()`, `DraftEditor` | `[words](href)`; the words never change |
| What is kept | `SeoState` on `Session::$seo` | `links` (unit, words, href, title, type, url, why), `removed`, `notice`, `checked`, `suggested` (hint, words, id, title, type, url, href, why; `SeoState::suggestion($hint, $words)`) |

`InlineLinks::inlineHref(DigestEntry)` is a separate interface from `LinkDialect`, as `LinkPlaceholders` is: `StatamicLinks` gives `statamic://entry::id` (a term: its address), `CraftLinks` `{entry:12@1:url||/address}`, `FilamentLinks` (new) the public address `->publicUrlUsing()` gives, `NoLinks` none. `HtmlDialect::fromMarkdown()` keeps a Craft reference tag in an href as CKEditor stores it (CommonMark would percent-encode its braces), and `LinkCandidates::linkKey()` reads CKEditor's in-editor form (`https://…/x#entry:12@1:url`) too.

**Later turns, edits and removals.** No call: `LinkGuard` runs after every writer turn (where the addon gives a `LinkContext`) and turns any address the writer wasn't given (not in the previous draft, not added by the pass, not a `#gw-link:` marker, not an outside address in the brief or the conversation, not a real page of the site) into a `#gw-link:` marker. **A link to a real page is kept** (decision 22) where the index is a `Suggest\LinkLookup`: `linkRow($href, $site)` (each addon hands its rows to `LinkCandidates::rowFor()`, which matches by `linkKey()`, and an absolute address only on the row's own host) finds a row of the draft's site that `Linkable` allows and that isn't the page itself, and the link is written as `InlineLinks::inlineHref()` gives it (`entry::abc` becomes `statamic://entry::abc`). `guard()` returns the kept ones as its third value. The writer's own markers are never resolved by the pass: Finish this page offers the page the pass suggested first, then the title matches. `SessionLayouts::removeLink($session, $href, $site)` is the Text tab's **Remove link**: the words stay, the link leaves `SeoState::$links` for `$removed`, and LinkGuard takes it out again if the writer puts it back.

**Finish this page.** `SessionGaps::fromSession()` carries `SeoState::$links`, and the `AddedLinks` detector (in `GapFinder::standard()`) gives one `links-added` suggestion a link still in the form ("Check 3 links Ghostwriter added. “…” goes to …"), with **Keep it** (`dismiss`, label `gaps.fix.keep-link`) and **Remove the link** (`remove-link`). Its meta has `href` (as stored), `formHref` (as the form holds it), `words`, `title`, `type`, `url`, `why` and `count`. A suggestion: never counted, never blocking. An addon that finds the session for an entry by its gap list should also take one whose `SessionGaps` isn't empty for its links.

**Suggested pages for the writer's markers** (decision 24). `SessionGaps::fromSession()` also carries `SeoState::$suggested`, and `LinkMarkers` puts a marker's suggestion (`SessionGaps::suggestion($hint, $words)`) first: a primary `link` fix, "Link to Contact us", whose value is the href the pass would have written (`statamic://entry::abc`, `{entry:12@1:url||…}`, the public address), then the title matches without the same page, then Choose an entry (or, with no `LinkTargets`, the address to type) and Remove the link. The gap's `meta.suggested` is `{id, title, href, url, type, why}`, and `meta.candidates` starts with it. Only inline markers get one; never resolved without the editor.

**Strings.** `resources/lang/en/seo.php`: the notice (`notice.links`, `notice.links-one`, `notice.no-links`), the status (`status.checking`) and the Text tab's (`link.added`, `link.added-long`, `link.remove`, `link.open`, `link.removed`); `gaps.links-added`, `gaps.links-added-one`, `gaps.speech.links-added`, `gaps.fix.keep-link`.

`Tests\Contracts\LinkInsertContract`: a link `inlineHref()` gives for a real page goes through the addon's real apply path into a rich-text field, reads back as a link to the same page with its words and the markers beside it, and renders as the page's address.

## Search title, description, address and file names (row 5)

Where the addon gives `LayoutContext` a `Seo\MetaContext`, the first draft's `seo-editor` call also writes the page's search title and description, and the pass makes its address. Nothing goes into the entry until "Use this draft", and nothing is saved until the editor saves.

```php
new LayoutContext($schema, …, links: $linkContext, meta: new MetaContext(
    fields: $seoFields,            // Gaps\SeoFields: SEO Pro, SEOmatic, plain fields…
    schema: $fullSchema,           // the entry's blueprint, its SEO field included
    entry: $entryData,             // the values the SEO fields are read from (the entry being edited, or a new one's defaults), with group and site
    newEntry: true,                // a new entry, or one never published
    provenance: $provenance,       // SeoProvenance: what Ghostwriter wrote into this entry before (its earlier sessions' SeoState::$written)
    slug: new SlugContext(settable: true, dated: true, taken: ['…'], current: null, base: 'northfold.garden/journal/'),
    kind: $contentKind, voice: $voiceGuide, locale: 'en_GB',   // used where there is no LinkContext
));
```

| Step | Who | What |
|---|---|---|
| What is wanted | `SeoMeta::request()` | The SEO fields as they would read with the draft in (an inherited description reads the draft's excerpt). The description where `MetaPolicy` would write or suggest one; the title only when the page title is too long for the `<title>` once the site name is added (decision 12), or the page has a title of its own; never one an editor wrote in the Search section |
| How long | `MetaRange` | Title 30 to `limit − 8`, or `limit −` the site name and separator (`TitleFormat::added()`); description 120 to `limit − 5` (`limit − 30` to `limit − 5` under 150) |
| The call | `Studio::seoEdit(SeoRequest)` | The links call with `SeoRequest::$meta` (a `MetaRequest`): the reply's `title` and `description` (`""` when not wanted). With no links to look for, the call is for them alone. One more ask when `SeoMetaCheck` finds a problem, with it quoted |
| The checks | `SeoMetaCheck` | In range; nothing the page doesn't say (`SourceCheck` against the draft, the brief and the answers); no marker, and no figure only inside an unresolved `[[check:]]` or `[[ask:]]`; one plain line (no `!`, emoji or capitals); a title isn't the page title word for word. `settle()`: cut at a word when only too long, dropped otherwise (`SearchMeta::$dropped`) |
| What is kept | `SeoState::$meta` (`SearchMeta`) | `title`, `description`, `slug`, `edited`, `use`, `dropped`, `checked` |
| Later turns | `SeoPass::afterWriter()` | Written again (one meta-only call) when a writer's turn changed the title or a quarter of the words (`SeoPass::changed()`), for the roles nobody edited |
| The address | `SlugRules` | From the title, while nobody has typed one: the whole phrase ("what-to-do-in-the-garden-in-march"), with stop words out only for a title over 60 characters or an address over 75 (then six words and 60 characters; a leading negation stays), filler off both ends, a year only in a dated group or to avoid a clash, unique among `taken` |

**Never overwriting a person** (`MetaPolicy::decide()`, §9.4): empty, or Ghostwriter's own unchanged text (`SeoProvenance`, hashes by role) is **Write**; a person's text, or an inherited text that's out of range, is **Suggest**; a description inherited from a field that is empty or missing (SEO Pro's `@seo:excerpt` on a page with no excerpt) is **Write**, as the page prints nothing and there's no text anyone chose to keep (the Search section says so: `seo.search.description-source-empty`); inherited text that fits, a template the entry can't override and anything switched off is **Leave**. On a new entry, an inherited title that's too long is written (decision 12). Text the editor wrote in the Search section ("Give it its own") is written wherever the field takes a custom value (`MetaPolicy::ownable()`), even where Ghostwriter would leave it inheriting.

**On "Use this draft"** each addon calls `SearchFields::apply($values, $schema, $entryData, $state, $newEntry, $provenance)` with its `SeoFields` and its **`SeoWriter`** (a new port beside `SeoFields`: `write(array $values, SeoField $field, string $text): array`, in the field's own shape; `PlainSeoWriter` for plain fields). It returns `SearchApplied`: the values, what was written (`SeoState::withWritten()`), the action per role and the texts only suggested. The editor's "Use this" in the Search section (`SeoPass::useMeta()`) writes a suggested one after all. The slug is the addon's (`SearchMeta::$slug`), on never-published entries only.

**The Search section** (§9.5, decision 21): `SearchSection::of($session, $metaContext)` gives the rows each addon draws: `title` (`own`, `pageTitle`, `composed`, `length`, `limit`, `min`, `max`, `action`, `current`, `note`), `description` (the same, with `inheritsFrom`, `dropped`), `address` (`slug`, `base`, `editable`, `note`). Notes are `seo.search.*` messages. Edits: `SeoPass::editMeta($session, 'title'|'description'|'slug', $text)` (the editor's from then on), `useMeta()`, and **Try again**, `SeoPass::retryMeta($session, $site)`: one meta-only call, written differently from the texts there now (it throws on a provider failure, for the panel to say so).

**Finish this page:** `GapKind::SeoMissing` and the `SeoMissing` detector (in `GapFinder::standard()`): an SEO description that's empty, or under its range, on a page someone has worked on, with **Use this** (`FixAction::UseText`, the draft's description from `SessionGaps::$meta`) and **I'll write it** (`focus`). Its meta has `step` (`gaps.step.seo-missing`: "Add a description for search"), `role`, `limit`, `length`, `min`, `max`, `text`, `source`. Inherited text that fits, templates and switched-off values are left. A suggestion: never counted, never blocking. An addon passes `seo:` (its `SeoFields`) to `GapContext` for it to run.

**File names** (§11): `Images\Photo::filenameBase($fallback, $max, $alt, $language)` names a photo from the alt text it is given, then the library's description, title and tags, the fallback and the search term, through **`FilenameRules::descriptive()`**: library noise out (`filename_noise` in each language's phrase list: "stock photo", "royalty free", "image of"…), stop words out, at most six words and 50 characters, two words with letters at least; else the next source.

`Tests\Contracts\SeoWriterContract`: written text reads back through the addon's `SeoFields` as custom; an empty description is written on apply; a person's is never written (only suggested); Ghostwriter's own unchanged text is written again; inherited, templated and switched-off descriptions are left.

## Finish this page, Suggest edits and Content to revisit (row 6)

Everything here is free (no model until a button says so) and never blocks publishing.

**Finish this page** (`GapFinder::standard()`, all `Severity::Suggestion`):

| Gap kind | Detector | Step | Fixes |
|---|---|---|---|
| `heading-long` | `Detectors\LongHeadings` | Shorten a heading: a heading in rich or long text over 70 characters (`LIMIT`) | **Write it for me** (`write-for-me`, model: `GapRequest::shortenHeading($gap, $around)`, `gap-filler` task `shorten-heading`, under 60 characters, nothing added) · **I'll write it** (`focus`). Meta: `words`, `match`, `length`, `limit`, `target`, `level`, `task` |
| `few-links` | `Detectors\FewLinks` | Link to your other pages: 300 words or more (`MIN_WORDS`) of rich and long text and no link to the site: a CMS reference, a path, or a full address on one of `GapContext::$hosts` | **Suggest links** (`suggest-links`, model; `meta.running` is `gaps.fix.suggesting-links`, "Finding pages to link to…") · **Add a link** (`focus`, `gaps.fix.add-links`) · **Skip** (`dismiss`, `gaps.fix.skip`). Once Suggest links has run, its links are the steps and this one isn't shown; with none found it reads `gaps.few-links.none` ("No pages close enough to link to…") with Add a link · Skip |
| `link-proposed` | `Detectors\ProposedLinks` | One step per link Suggest links found (`GapContext::$proposals`) while its words are still in their field, unlinked, and the page doesn't link to that page yet: "Link “:words” to :title? :why" | **Link it** (`link`, `gaps.fix.link-it`, value: the href as the field stores links; `meta.inline`) · **Skip** (`dismiss`). Meta: `words`, `href`, `title`, `type`, `url`, `why`, `prefix`, `suffix`, `proposal`; `occurrence` counts the words' unlinked repeats in the field, as the editor finds them |
| `seo-missing` | `Detectors\SeoMissing` (row 5) | Add a description for search | Unchanged; now a Suggest edits candidate too |

Addons pass `hosts:` (the site's own hosts) to `GapContext` so a full address on the site counts as a link to it.

### Suggest links

**`SeoPass::suggestLinksFor(GapContext $page, LinkContext $links, ?string $title = null): LinkProposals`** is the click behind Finish's **Suggest links** on a `few-links` page. It runs the first draft's two calls on the page's current text (`Seo\PageLinks`): one `seo-editor` call, links only (no title or description), and one `seo-verifier` call, both in the writing tier at high effort with cached instructions. The rules are a draft's: about one link per 250 words of prose, 2 to 5, less the links the page has to the site already (none under 150 words); candidates from `LinkIndex::related()` (key pages included; never the page itself, `LinkContext::$except`, or a page it links to); `LinkValidator` on every pick (descriptive words, not in a heading, bold or a quotation, one sentence, one a unit, no page twice); the verifier keeps each in its paragraph, moves it onto better words from the same sentence, or drops it when the page is wrong there (a failed verifier keeps what passed the checks; a failed `seo-editor` call throws `ProviderException`). `spent()` gives the tokens.

Nothing is written. Each kept link is a **`Seo\LinkProposal`**: the field (`path`, `label`), the words as a `TextQuote` of the field's text, `words` as the editor shows them (words with Markdown in them are left out), `href` (`InlineLinks::inlineHref()`), the page's `title`, `type` and `url`, and the model's `why`. **`Seo\LinkProposals`** holds them, or says why there are none (`none`: `no-candidates`, `no-room`, `no-place`, `dropped`); `toArray()`/`fromArray()` for the addon's cache.

The addon keeps the result for the page view (by a token the guide sends with each check) and passes it to the gap finder as `GapContext::$proposals`: `ProposedLinks` makes a `link-proposed` step of each one still to make, and `FewLinks` steps aside, or says nothing was found. Link it links the words in the form's editor (the addon's front end, with undo); nothing is saved until the editor saves.

`Tests\Contracts\ProposedLinksContract` checks an addon's Finish context: a proposal is a Link it step with the stored href, linked words lose it, and nothing found reads `gaps.few-links.none`.

**Suggest edits** (`Suggest\Findings`): `seo-missing` (Category SEO, needs words, `meta.empty`; it replaces the older `seo-empty` on the same field), `heading-long` (SEO, anchored on the heading's words; the reviewer writes it shorter) and `few-links` (Link, not shown alone: the reviewer is shown up to `SiteDigest::RELATED` pages from `LinkIndex::related()`, link-only pages included, and proposes up to three links of its own; the kept candidate itself adds nothing to accept). The reviewer's prompt has two short sections, "Search: headings" and "Search: links". The validator now lets a new description replace an inherited one that is too short, as well as an empty or too long one (decision 11).

**Content to revisit** (`RevisitScanner`, `Priority`): new `ReasonKind`s `seo-missing` (weight 6, cap 6: no description the page prints), `few-links` (5, cap 5), `heading-levels` (3, cap 3: `Seo\HeadingLevelCheck`, a top-level field whose headings the fixer would move to another level), and, for phase 2, `competing` (10, cap 20) and `readability` (2, cap 4). All SEO reasons (`ReasonKind::isSeo()`, `seo-length` included) count for at most `Priority::SEO_CAP` (25) together, so no page is "High" on SEO alone; a missing description is no longer also an empty field. The list stays on Ghostwriter's own groups: link rows are only the other side of a link.

**Translations** (decision 18): `resources/lang/{de,fr,nl,es}/{seo,gaps,suggest,revisit}.php` hold the SEO strings in German, French, Dutch and Spanish (formal address); `Message::translations($namespace, $language)` reads them, and every other key falls back to English. Each addon's sync copies them into its own format.
