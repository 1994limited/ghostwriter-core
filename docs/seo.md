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
