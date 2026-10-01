# Text and prompt unification

How core's text classes and prompts were unified from the three addons' copies, as of Statamic `05b64ce`, Filament `00aee47` and Craft `76dffaa`. Comments, code style and CMS names in docblocks were ignored when comparing.

"Default" means what an addon gets by constructing the class with no arguments.

## Text classes (`Core\Text`)

| Class | What differed between the three | What core does | What each adapter passes to keep its behaviour |
|---|---|---|---|
| `Draft` | Statamic's code-fence pattern also accepted a fence with no newline before the closing ```` ``` ````, and ran in UTF-8 mode (`/u`). | Statamic's pattern, run in UTF-8 mode with a byte-mode fallback (`Pcre`). With valid UTF-8 every addon's inputs give the same result; a draft with a bad byte is reported as "not valid YAML" either way, since Symfony YAML rejects it. | Nothing. |
| `LenientYaml` | Statamic ran two of its patterns with `/u`; Craft and Filament didn't. | `/u` with a byte-mode fallback. The only change for Craft and Filament is that `\s` also matches Unicode spaces (a no-break space after a list dash). | Nothing. |
| `TaggedResponse` | Statamic and Filament used `/u`; Craft didn't. | `/u` with a byte-mode fallback, so a reply with a bad byte is still parsed (with plain `/u` it came back empty). | Nothing. |
| `HtmlToMarkdown` | Identical. All three drop Craft's `<craft-entry>` element. | Same behaviour. The custom elements to drop are a constructor option, `$embeds`, defaulting to `['craft-entry']`. | Nothing. |
| `DraftPreview` | Statamic gave a `group`'s fields the group's path (`['meta', 'seo_title']`); Craft and Filament gave them a root path (`['seo_title']`). | Statamic's. Craft's and Filament's schema readers never produce a `group` kind, so their output can't change. | Nothing. |
| `EntryMerger` | (1) Craft and Filament copied fields the draft didn't hold from the entry (all but `title`); Statamic didn't. (2) Statamic merged `rows` by position, keeping row IDs and extra fields; Craft and Filament replaced them. (3) Statamic merged `group`s field by field. (4) Statamic used the built block's `id` when the original had none, and didn't skip non-array blocks. | Options `keepMissing` (default `true`) and `mergeRows` (default `false`). Groups are always merged (Craft and Filament have no groups). A block keeps `$was['id'] ?? $block['id'] ?? null`, and non-array blocks are skipped. | Statamic: `new EntryMerger(keepMissing: false, mergeRows: true)`. Craft and Filament: nothing. |
| `EntrySimplifier` | (1) Rich text: Craft converted HTML; Filament also passed `format: markdown` fields through; Statamic converted Bard node arrays with `BardToMarkdown` and `trim`med strings. (2) Statamic simplified `group`s. (3) Each called its own `SchemaReader::writable()`, which was the same everywhere (`kind !== 'reference'`). | Optional `richText` callable `fn (mixed $value, array $spec): ?string`. The default reads HTML and passes `format: markdown` through (only Filament's reader sets `format`). Groups are simplified (only Statamic has them). The writable rule is inlined. | Statamic: `new EntrySimplifier(richText: fn ($value) => is_array($value) ? $bard->convert($value) : trim((string) $value))`. Craft and Filament: nothing. |
| `Utf8` (new) | Statamic had `Ai\Text::scrub`; Craft scrubbed inside its HTTP provider. | `Utf8::scrub()`. The transport uses it too. | Statamic can swap `Text::scrub` for it. |

## Prompts (`Core\Prompts`)

Nine prompts were byte-identical in all three addons and are copied as they are. Statamic had no `photo-picker.md`: its text was inline in `Ai/Agents/PhotoPicker.php`. That text is identical to the Craft and Filament file, so the file is used for all three.

| Prompt | What differed | Placeholders | Statamic | Craft | Filament |
|---|---|---|---|---|---|
| `planner.md` | Filament says app, resource, record and "the organisation" where the others say website, section, entry and "the site". It answers with a `resource:` key, and fills `{{ resources }}` rather than `{{ sections }}`. Statamic and Craft were identical, and both use the `collection:` key. | `[[place]]`, `[[site]]`, `[[group]]`, `[[groups]]`, `[[group_key]]`, `[[item]]`, `[[items]]`, `[[readers]]`, `[[offerer]]`, `[[on_place]]` | website / site / section / sections / collection / entry / entries; default phrases | same as Statamic | app / app / resource / resources / resource / record / records; phrases `readers` = "its readers", `offerer` = "the organisation", `on_place` = "in the app" |
| `kind-finder.md` | Craft asks for example IDs "as a list of numbers" (`[12, 15]`); Statamic and Filament ask for "a list of strings, exactly as given" (`["id-one", "id-two"]`). Statamic also tells the model to reply with an empty `<kinds></kinds>` when everything is already taught or turned down. The CMS words are the same in all three. | `[[kind_ids]]`, `[[kind_ids_example]]`, `[[kinds_when_none]]` | strings; `kinds_when_none` set | `numericIds: true` | strings |

Each addon passes `Vocabulary::statamic()`, `::craft()` or `::filament()` to `PromptLibrary`. The golden tests in `tests/Prompts` check that each one reproduces that addon's files byte for byte, after trimming.

`[[name]]` placeholders can't collide with the `{{ name }}` ones the addons fill with `strtr()`, and no prompt contained `[[` before.

### Prompt overrides

Statamic and Filament read an override from `resource_path("ghostwriter/prompts/{name}.md")`; Craft reads one through its `paths` component. Each passes that lookup as `PromptLibrary`'s `$override` callable, returning the file's text or `null`. Overrides get the vocabulary filled in too, so a site that copied `PromptLibrary::original()` keeps working.
