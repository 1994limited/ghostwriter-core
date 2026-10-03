# Layouts and extras

How drafting works: the writer writes the draft and, in the same call, prepares **extras** for the block types the site has; a second call proposes up to two other **layouts** of the same words. The writer's draft is layout 1. Switching layouts costs nothing, and **Use this draft** applies the chosen one through the same build path as before. This page is the API the addons call. The design is `page-preview-layouts-design.md` (§5, §6.1, §6.2).

Everything here is `NineteenNinetyFour\Ghostwriter\Core\Arrange` (no model) or `Studio` (the model calls).

## Extras

`Arrange\Extras`. An extra is a stat block, FAQs, a pull quote, an "at a glance" box, a caption, a second call to action, a testimonial or a short intro, prepared from what the writer was given. Extras sit beside the draft, never in it: a layout may place them, and an extra no layout places never reaches the entry.

### What the writer is offered

```php
$slots = ExtraSlots::for($schema);   // which kinds this site can show, and where
$slots->kinds(); $slots->has(ExtraKind::Faq); $slots->slots(ExtraKind::Faq); $slots->describe(); $slots->isEmpty();
```

| Kind | A page builder's set (handle or label), or shape | Rich text |
|---|---|---|
| `stats` | stats, numbers, figures, facts; or rows with a value and a label | — |
| `faq` | faq, questions, accordion | headings and paragraphs |
| `pull_quote` | quote, pull_quote, blockquote | a block quote (the dialect may store it as a quote set) |
| `at_a_glance` | summary, highlights, key_facts, at_a_glance, callout | a list |
| `caption` | a caption or alt field beside an image field | — |
| `cta` | cta, call_to_action, banner | — |
| `testimonial` | testimonial, review | a quote set with an attribution field |
| `intro` | intro, lede, standfirst, excerpt; or a top-level field so named | the first paragraph |

The writer's instructions get the `writer-extras` section whenever the layout has a schema (`Studio\Layout::fromSchema()`) with a slot for at least one kind. With no slot, the instructions are exactly as they were. Nothing is configured: an addon that gives the writer its schema gets extras.

### Reading them

```php
$response = $studio->write($conversation, $context);            // as before; $response->extras is the <extras> block
$extras = $studio->extras($response, $conversation, $context, $exampleIds);   // Extras, checked
$session->extras = $extras->toArray();
```

`$exampleIds` are the entry ids of the examples the writer was shown (`Layout::$examples`), in order, so an entry source can link to its entry. Leave it out when you don't have them.

Each item is kept only when:

1. **its quote is in its source**: `brief` or `answer` (the person's messages and the questionnaire answers), `draft`, or `entry` (an example the writer was shown, by number), compared after normalising case, whitespace, quotes, dashes and markdown; and
2. **every fact in it is in that quote**: figures (compared by value, so "4" is given by "four"), quotations and names, by `ScopedEditCheck` with the quote as the text before, so no fact and no link is added. An attribution's names must be in the source it quotes.

An item that fails, or has no source, is kept only when it holds an `[[ask: …]]` in place of the fact it needs and says nothing else unsourced: `needsAnswer()` is then true, and the panel labels it *needs your answer*. Everything else is dropped (logged at info, with why). An unreadable `<extras>` block is dropped with a warning; the turn goes on. Kinds the site has no slot for, and a second extra of a kind, are dropped.

### The types

```php
Extras:    all(); items();               // array<string id, ExtraItem>
           item('x2.1'); item('x2.1.question'); extraOf('x2.1');
           edit('x2.1', $text, ?array $parts); without('x2.1');   // the editor's changes: new Extras
           toArray(); Extras::fromArray($session->extras);
Extra:     id ("x2"), kind (ExtraKind), items (list<ExtraItem>)
ExtraItem: id ("x2.1"), text, parts (['question' => …, 'attribution' => …]), source (?Source), askHints, needsAnswer(), part($name)
Source:    kind (SourceKind: brief, answer, draft, entry, conversation, editor), quote, ref, entryId, entryTitle
```

An item the editor changes (`Extras::edit()`) takes their words as its source (`SourceKind::Editor`). Parts by kind: `stats` `value`, `label`; `faq` `question`; `pull_quote` and `testimonial` `attribution`; `cta` `button`; `caption` `for`.

## Plans: what a layout is

`Arrange\Plan`. A plan says where the draft's units and extras go; it never holds words of its own. Its name and description are labels for the card.

```php
Plan:       id ("w", "p1", "p2"), origin (PlanOrigin: writer, pattern, model), name, description,
            fields (array<string handle, list<PlanBlock>>), follows (a SitePatterns id), suggested, stale
            refs(); extrasUsed(); sequences(); blockCount(); with(...); toArray(); Plan::fromArray()
PlanBlock:  type, placements (list<Placement>), children (array<string nested builder, list<PlanBlock>>), settings, origin
Placement:  field, from (list<string> refs), transform (Transform), options (['level' => 3], ['rows' => [[column => ref]]])
Plans:      Plans::fromDraft($draft, Units, Schema): Plan   // the writer's plan "w"
            Plans::of(Plan $writer, list<Plan> $alternatives)  // numbered p1, p2…
            get($id); writer(); suggested(); all(); with(Plan); toArray(); Plans::fromArray($session->plans)
```

- **Fields a plan arranges** are the keys of `fields`: a page builder (blocks are its sets, nested builders under `children`), a rich-text or markdown field (blocks are constructs: `text` as written, `p`, `h2`–`h6`, `list`, `quote`, `set:<handle>` for a quote set, which the dialect stores from a block quote), or a top-level text, long text or list field (one block of type `value`, so an extra can fill an empty excerpt). Every other field is copied from the draft.
- **Refs:** `u7` a unit; `u7#2` its second piece; `u7#2:lead` and `u7#2:rest` a bold lead-in and the rest of that paragraph; `x1.2` an extra item; `x1.2.question` one of its parts.
- **Transforms** (`Transform`): `as-is`, `split`, `join` (as written), `lead-in-to-heading`, `heading-to-lead-in`, `paragraphs-to-list`, `list-to-paragraphs`, `heading-level` (with `level`), `as-quote`. None adds or rewords a word; a lead-in's full stop or colon is the only punctuation that comes or goes.
- **The writer's plan** (`Plans::fromDraft()`) places each block's units as they are and keeps each block's position in the draft (`origin`), so images and settings come with it. Arranging it gives the draft back exactly: `tests/Arrange/WriterPlanParityTest` checks every draft in the golden layout fixtures, and runs `bin/compare-layouts` on the built entries ("The layouts are the same.").

## The arranger

```php
$data  = (new Arranger)->arrange(Plan $plan, Units $units, Extras|array $extras, Draft|array $draft, Schema $schema);   // Text\Draft::$data shape
$built = (new Arranger)->build(EntryBuilder $builder, $plan, $units, $extras, $draft, $schema, ?Pattern $pattern, array $defaults);  // BuiltEntry
```

Deterministic, no model. The output has the draft's own shape, so the existing build path runs on it unchanged: `EntryBuilder`, then `HouseStyle::apply()`, placeholders and images, as "Use this draft" does today.

- A placement of exactly the units one value had, as they are, gets the draft's own value. Anything else is put together from pieces, as the target field takes it: one line for text, paragraphs for long text, markdown for rich text, items for a list, rows for a rows field (a row unit as it was; an extra item by its parts, so a stat's `value` and `label` and a question and its answer go in their own columns), the assets for an image field.
- A block from the writer's plan that a layout moves keeps its other values; text the layout put elsewhere is taken out of it.
- Extras a plan doesn't place never reach the data.

## Checking a plan: `PlanValidator`

```php
$validator = new PlanValidator(EntryBuilder $builder, ?LoggerInterface $logger);   // the addon's builder, for the round trip
$violations = $validator->check($plan, $units, $extras, $draft, $schema, ?Pattern $pattern, list<Plan> $earlier);   // list<Violation>
$usable     = $validator->valid(list<Plan> $plans, $units, $extras, $draft, $schema, ?Pattern $pattern);       // the ones that pass; the writer's always
```

| Rule (`Violation::…`) | Fails when |
|---|---|
| `UNKNOWN_BLOCK` | a block type isn't a set of its field (or of the nested builder), or a rich-text construct isn't one the field can hold |
| `UNKNOWN_FIELD` | a field the plan arranges or places into isn't there, or can't be written |
| `UNKNOWN_REF` | a ref stands for no unit, piece, lead-in or extra item |
| `KIND` | what's placed doesn't suit the field: more than one piece, or a quote, in a text field; a heading in plain long text or a list; an image outside an image field or text in one |
| `LIMITS` | more or fewer blocks than the builder's `Field::$meta['min']` / `['max']` |
| `REQUIRED` | a required field of a new block (or a top-level field the plan arranges) is left empty, with no setting or house default for it |
| `EMPTY_BLOCK` | a block meant for words has none |
| `DUPLICATED` | a unit, piece, lead-in or extra item is placed twice |
| `MISSING` | a unit (or piece) of an arranged field isn't placed; media may be left out |
| `OUTSIDE` | a unit of a field the plan doesn't arrange is placed: it would be there twice |
| `WORDS` | the arranged fields' words aren't the units' and the placed extras' words (as a multiset, punctuation aside) |
| `MARKERS` | an `[[ask: …]]` or `#gw-link:` link is lost or doubled |
| `UNSOURCED_EXTRA` | a placed extra item has no source and isn't waiting on an answer |
| `BOILERPLATE` | words go into a set the pattern marks as copied whole |
| `SAME` | it's the same layout as an earlier plan (the writer's included) |
| `ROUND_TRIP` | building it notes something new that is not a field here, not an option, or an unknown block |

Violations are logged at debug level. A plan that fails is dropped.

## After the text changes

```php
$repaired = (new PlanRepair)->repair(Plan $plan, Units $units, Extras $extras, Plan $writer);   // $writer: Plans::fromDraft() of the new draft
```

Unit ids carry across edits (`UnitMatcher::carry()`), so a plan mostly still points at the right words. A removed unit, piece or lead-in, or an extra the editor deleted, is taken out (an empty placement or block goes too). A new unit goes after the unit before it: into the same placement when that holds the unit before it whole and both are prose or sections of one value, otherwise in a new block of the writer's type right after. Then the plan is validated again; one that still fails is kept with `stale` set ("Needs refreshing"). Nothing re-plans on its own.

## Site patterns and "Suggested"

```php
$patterns = (new SitePatterns($richText))->find(Schema $schema, array $entries, int $limit = 3);
// list<array{id: "p-1", field, sequence: list<string>, count, share, example: title, exampleId}>, commonest first
$profile  = (new SitePatterns($richText))->profile(Schema $schema, array $entries);
// per rich-text field: headings per 100 words, list share, quote share, entries
$ranked   = (new Candidates)->rank(Plans $plans, $patterns, $profile, $units, $extras, $draft, $schema);   // one plan with suggested = true
```

`SitePatterns` uses `PatternFinder::sequences()` and `sequenceOf()`, the code `PatternFinder::find()` takes its commonest sequence from. "Suggested" goes to the plan whose page builders are closest to the site's patterns (one minus the normalised Levenshtein distance over block types, weighted by each pattern's share) plus, for rich text, whose structure is closest to the profile. Ties go to the earlier plan, so the writer's. It means *like your pages*, not *best*.
