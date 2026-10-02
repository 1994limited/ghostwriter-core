# Layout unification

How core's schema model and layout algorithms were unified from the three addons' copies, as of Statamic `8c7573e`, Filament `8c14ac0` and Craft `ff77f61`. Comments, code style and CMS names in docblocks were ignored when comparing. Filament's copies were ported from Craft's and differed from them only where noted.

"Accidental" means the difference came from one copy being fixed or extended and the others not; core takes the fix for all three. "CMS" means the CMSs really differ; core keeps both, as a dialect or an option. Every row was checked against the golden fixtures (see [layout.md](layout.md), "Checking parity"): 225 Statamic, 95 Filament and 175 Craft cases, all of which core reproduces except the one marked deliberate.

## Schema model (`Core\Schema`)

| What differed | What core does | What each addon passes |
|---|---|---|
| Each reader returned arrays in nearly the same shape. Statamic had `list` and `group` kinds; Craft added `engine` (`matrix`, `neo`, `neo-children`) and a table's `columns`; Filament added `path`, `format`, `relationship`. | `Field`, `Set`, `Schema` and the `Kind` enum (the union, twelve kinds). Structural keys are properties; anything else is `meta`. `Schema::fromSpecs()` reads the old arrays, `toSpecs()` writes them back. A field with an `images` key is `files`. | Nothing: `Schema::fromSpecs($reader->read(...))`. |
| Craft's `layouts/EntryData` read an element into one plain shape; Statamic used `$entry->data()->all()`; Filament its `RecordReader`. | `EntryData`: the values in Craft's shape (blocks as `type`/`enabled`/fields, Neo children as a tree), with the entry's ID, title and parent. The readers stay in the adapters. | Its reader's values, the ID it used, the title (Filament: the title field), and the parent (Statamic: not the home page). |
| "A builder" was `isset($spec['engine'])` (Craft, Filament) or `kind === 'blocks'` (Statamic). | `Field::isBuilder()`: `blocks`, or a field with sets that isn't rich text. Gives the same answer for every field the three readers make (a Matrix with nothing to write is still walked; Bard is not). | Nothing. |

## Algorithms (`Core\Layout`)

| Algorithm | What differed | Kind | What core does | Statamic | Craft | Filament |
|---|---|---|---|---|---|---|
| `SchemaDescriber` | "Entries in this collection" (Statamic) or "section" (Craft and Filament). | CMS | `LayoutOptions::$group`. | `collection` | `section` | `section` |
| | Craft and Filament said "a block that holds other blocks lists them under `children`" for Neo; Statamic had no check. | Accidental | Always checked; only Neo makes a `neo-children` field, so Statamic's output can't change. | — | — | — |
| `PatternFinder` | Choosing entries (queries) differed per CMS. | CMS | Stays in the adapter. `PatternFinder::choose()` keeps the shared rule: those matching `where`, else all, at most 30. | its query | its query | its query |
| | Keys never taken for a house default: Statamic also skipped `date`, `blueprint`, `published`, `updated_at`, `updated_by`. | CMS | `LayoutOptions::$bookkeeping`. | `statamic()` | `['title', 'slug', 'id']` | same |
| | Statamic kept an empty value (`''`, `null`, `[]`) shared by 80% of entries as a house default; Craft and Filament had learned not to ("a field nobody uses"). | Accidental | Empty is never a default. No recorded Statamic output changes. | — | — | — |
| | Fill rates walked builders by `engine` (Craft, Filament) or `kind` (Statamic). | Accidental | `Field::isBuilder()`. | — | — | — |
| | Examples were simplified with each addon's `EntrySimplifier` (Bard, HTML with or without `<craft-entry>`). | CMS | Through `RichTextDialect::toMarkdown()`. | `BardDialect` | `HtmlDialect` | `HtmlDialect(new HtmlToMarkdown(embeds: []))` |
| `KindFinder` | The builder grouped by: the first `blocks` field (Statamic, Craft), or the first Builder whatever its kind (Filament, so a Builder with nothing to write still counts). | CMS | `LayoutOptions::$kindsFromAnyBuilder`. | `false` | `false` | `true` |
| | Entries looked at: all (Statamic) or 120 (Craft, Filament). | CMS | The adapter's query; `KindFinder::SAMPLE` is 120. | all | 120 | 120 |
| | Named after a shared parent page; Statamic left out the home page; Filament has no parents. | CMS | `EntryData::$parentId`; the adapter leaves out a parent that shouldn't name a kind. | not the root | any parent | none |
| | Filament's label went through its translations. | CMS | `LayoutOptions::$kindLabel` (null: English, the same words). | — | — | `__()` |
| | Example IDs: ints (Craft), strings (Statamic, Filament). | CMS | The `EntryData` IDs, as given. | strings | ints | strings |
| `HouseStyle` | Rich text: HTML elements with attributes and inline wrappers (Craft, Filament) or Bard nodes with attrs and marks (Statamic). | CMS | `RichTextDialect`: `isWritten()`, `shapes()`, `dress()`. | `BardDialect` | `HtmlDialect` | `HtmlDialect` |
| | A block's rich text counted among the values copied by position (Craft, Filament) or not (Statamic, whose values are whole documents). | CMS | `LayoutOptions::$richTextInPositions`. | `false` | `true` | `true` |
| | Link fields: Hyper and Craft's Link (Craft; the same constants were copied into Filament, FIL-9), `link` and `entries` (Statamic). How a link to the page itself is stored (`[id]`, `entry::id`), how it is recognised, what makes a plain value a link, and what stands in for one nothing settles. | CMS | `LinkDialect`: `CraftLinks`, `StatamicLinks`, `NoLinks`. | `StatamicLinks` | `CraftLinks(hyper: [...], link: [...])` | `NoLinks` |
| | Places still to fill: Craft and Filament named every unsettled reference inside a nested block (not files); Statamic named only the links a block usually has that can't be stood in for ("Hero: Related"). | CMS | `LayoutOptions::$unsettled` (`NAME_NESTED`, `NAME_LINKS`). | `NAME_LINKS` | `NAME_NESTED` | `NAME_NESTED` |
| | A field held back for a link to the page itself was skipped (Statamic) or could be named or stood in for (Craft, Filament). Craft always knew the new entry's ID, so it never came up. | Accidental | Skipped. | — | — | — |
| | Files were told by class (Craft) or by an `images` key (Filament). | Accidental | `Field::$files`. | — | — | — |
| | New nested blocks made from a sequence got an ID (Statamic). | CMS | `LayoutOptions::$newId`. | random hex | none | none |
| | `linkToSelf()` once the entry exists (Statamic only; Craft writes into a draft that already has an ID, Filament has no links). | CMS | `HouseStyle::linkToSelf()`, for any addon. | uses it | — | — |
| | The note: "as it differs from page to page" or "record to record". | CMS | `HouseResult::note()`, `LayoutOptions::$item`. | `page` | `page` | `record` |
| | A link to the page itself was recognised by Craft as the ID, `[ID]` or `"ID"` under `linkValue`/`value`; Filament compared loosely, for string keys. | Accidental | `CraftLinks`: the ID as an int or string, or a list of just that ID. | — | — | — |
| | **Deliberate:** Filament ran Craft's link rules too, so a field named `label` that repeated each record's title was taken for a link's words, and copied into a new record as its title (FIL-9). | Deliberate | `NoLinks` reads nothing as a link. The one recorded case that shows it (`filament/synthetic-places-the-pages-disagree-on`, `learn`) holds core's output under `deliberate`. | — | — | changes |
| `EntryBuilder` | Rich text: HTML (Craft, Filament, including Filament's markdown editor, as before); Statamic kept markdown for a `markdown` field, wrote HTML for Bard with `save_html`, and Bard nodes otherwise, with block quotes as a pull-quote set. | CMS | `RichTextDialect::fromMarkdown()`. | `BardDialect` | `HtmlDialect` | `HtmlDialect` |
| | Statamic built `list` and `group` fields; the others' readers never make them. | Accidental | Built for all. | — | — | — |
| | Statamic gave new blocks and rows an `id`, and copied house content with fresh IDs; Craft and Filament dropped the IDs of copied content. Statamic copied the entry's own house defaults (not a block's) without touching IDs. | CMS, and accidental for the last | `LayoutOptions::$newId`: with it, new IDs everywhere, copies included; without, copies lose theirs. A copied entry-level value in Statamic now gets fresh IDs rather than sharing the source entry's. | random hex | none | none |
| | "does not exist in" (Statamic) or "cannot go in" (Craft, Filament) in the note about a block type the field doesn't allow. | CMS | `LayoutOptions::$unknownBlock`. | `does not exist in` | `cannot go in` | `cannot go in` |

## Not moved

- **The readers** (`SchemaReader`, Craft's `EntryData`, Filament's `RecordReader` and `FormMaps`) and the queries that choose entries: they need the CMS.
- **Writing back:** Craft's `FieldValues` and `Applier`, Filament's `FormState` and `DraftApplier`, Statamic's `SchemaEntryWriter` and `HouseFinish`.
- **Placeholders** (striped images where one belongs): Phase 4, with the rest of the images.
- **Statamic's Bard conversion** (`MarkdownToBard`, `BardToMarkdown`) stays in its adapter as its `BardDialect`. Its parity is checked here, through the copy in `tests/Layout/Support`, and again in the adapter in stage 2.
