# Gaps: Finish this page

What an editor must finish before a page goes live, how core finds it, and how the publish guard uses it. Everything is in `NineteenNinetyFour\Ghostwriter\Core\Gaps`, and nothing in it calls a model unless a button that says so passes `$withModel`. The design is `finish-this-page-design.md`; this page is the API as built.

## Markers

`Markers` writes and finds the marks Ghostwriter leaves in content. They are visible on purpose: an editor who never opens the guide still sees them. Each is written strictly and found leniently, and `Markers::patterns()` gives the same patterns to the front end (`resources/gaps/patterns.json`, kept up to date by a test).

| Marker | Written by | Means |
|---|---|---|
| `[[ask: adult ticket price]]` | `Markers::ask($hint)` | a fact only the editor knows |
| `[[check: 3 areas \| from: Northumberland, Durham and the Tyne Valley]]` | `Markers::check($value, $list)` | a count core made from a list the editor gave, to confirm |
| `[Talk to us](#gw-link:contact-page)` | `Markers::link($hint)`, `linkUrl()` | a link still to choose |

The keywords `ask`, `check`, `from` and `gw-link` are never translated.

**Showing them.** Printed as they are, the markers read as code. `resources/js/preview/markers.js` displays them as chips: an amber chip for an ask, a dotted underline for a count to check and a dashed underline for a link to choose, each with a tooltip. It works in the preview's frame (after the locator) and, without a DOM, in the CP's lists and under plain text inputs. It is display only, and the stored marker is never changed. See docs/preview.md, "Gap markers on the page".

```php
Markers::asks($text);          // list<{hint, match, offset, occurrence}>
Markers::checks($text);        // list<{hint, value, list, match, offset, occurrence}>; hint is the value
Markers::links($markdown);     // list<{hint, words, match, offset, occurrence}>
Markers::has($text);           // any of the three
Markers::normalise($text);     // lenient forms (and a model's near misses) written strictly
Markers::resolveCheck($text, $match, $value, $occurrence = 0);   // the marker replaced by $value ('' removes it)
Markers::resolveAsk($text, $match, $answer, $occurrence = 0);    // the marker replaced by the answer, exactly as typed ('' removes it)
Markers::resolveLink($markdown, $match, $href, $occurrence = 0); // [words](#gw-link:…) pointed at $href, its words kept ('' unlinks it)
Markers::withoutChecks($text); // each count as the plain value it marks
Markers::withoutAsks($text);   // asks taken out, counts as their values: for slugs and fact checks
```

**The round trip.** Every addon's apply path must keep all three markers through its own storage: rich text (HTML, Bard), markdown fields and plain text. `Tests\Contracts\MarkerRoundTripContract` checks it, counts to check included (with `&` in the list, in a heading, a list item and bold text); core runs it for HTML and Bard.

## Counts to check

A derived count is a stat such as "3 areas" counted from a list the editor gave ("Northumberland, Durham and the Tyne Valley"). Core counts the list (`Anchor\ListCounter`, see docs/layouts.md for what it counts and skips), never the model, and puts the count in with a `[[check: …]]` marker so the editor confirms it before the page goes live.

- `value` is what the page will say ("3 areas", or "3" in a stat's value column). `from` is the list on one line: an inline list exactly as written, a bulleted list's items joined by "; " (`CountedList::oneLine()`).
- Brackets and bars are taken out of both halves; the list is at most 300 characters (a longer list is never offered as a count).

## Finding gaps: `GapFinder`

```php
$report = GapFinder::standard()->find(new GapContext(
    schema: $schema,
    entry: $entry,                 // EntryData: the form's values, or the saved entry's
    richText: $dialect,
    links: $links,
    // placeholders, assets, targets, stock, pattern, session: as before
    sources: ExtraSources::fromSession($session)->all(),   // new: what counts were counted from
));
$report->count();      // the pill
$report->toArray();    // for the guide
```

`GapContext::$sources` is optional: the texts a count to check may have been counted from (the person's messages and answers, and the draft). With them, a count whose list has since changed says so; without them, a count is only checked against its own list.

### The `check` gap

`GapKind::Check` (`check`), found by `Detectors\CheckMarkers`, one gap per marker. It **blocks** (`Severity::Blocks`), like an ask. Its id is `check|<path>|<value>|<occurrence>`, so it keeps its place in the guide while it goes stale and back.

- `hint`: the value ("3 areas"). `excerpt`: the sentence it sits in.
- `meta`: `match` (the marker as written, for `resolveCheck()`), `value`, `count` (the number in the value), `list`, `items` (the list's items), `stale`, and when stale `newCount`, `newValue`, `newList`.
- **Message** (`gaps.check`): "I counted 3 areas from “Northumberland, Durham and the Tyne Valley”. Is that right?"
- **Fixes**, the primary first:

| Fix | `FixAction` | `Fix::$value` | Does |
|---|---|---|---|
| Looks right | `confirm` | the value | replace the marker with the value |
| Change it | `change` | the value | an editable value, prefilled; replace the marker with what the editor types |
| Remove it | `remove` | — | take the marker out (`resolveCheck($text, $match, '')`) |

**When the list has changed** (`meta.stale`):

| `stale` | When | Message | Fixes |
|---|---|---|---|
| `changed` | the list isn't in the sources any more, but a list sharing items with it is | `gaps.check-changed`: "…but that list has changed since. It now has 4. Use “4 areas” instead?" | **Use “4 areas”** (`confirm`, label `gaps.fix.use-count`, value the new value), Change it, Remove it |
| `gone` | no list like it is in the sources | `gaps.check-gone`: "…but that list isn't in what you gave me any more. Is 3 areas still right?" | Change it (primary), Remove it |
| `count` | the marker's value isn't its own list's count (edited by hand) | `gaps.check-count`: "This says 5 areas, but “…” has 3. Use “3 areas” instead?" | Use “3 areas”, Change it, Remove it |

Lists are compared by their items as words, without a leading article, so "the Tyne Valley" and "Tyne Valley" are the same item, and an inline list and the same items as bullets are the same list.

**The addon's step.** The guide shows the message and the fixes as for any gap. Each fix replaces the marker in the field's text, found by `meta.match` and the gap's `occurrence`: the front end with the `check` pattern from `patterns.json`, or PHP with `Markers::resolveCheck()`. Nothing is saved until the editor saves.

## Resolving a gap from a chip: `MarkerResolver`

The page preview and the Text tab show markers as chips (docs/preview.md). Clicking one lets the editor resolve the gap in the **draft** itself, before Use this draft: the addon opens a small dialog at the chip ("Only you know this: adult ticket price", "Counted from '…'. 3 areas, is that right?", "Link to choose"), and writes the answer into the stored draft. A chip only knows its kind, hint, list and which of its kind and hint it is (`occurrence`), so `MarkerResolver` finds the marker in what the addon stores:

```php
$texts = MarkerResolver::leaves($draft->data);    // every string in the draft's values, with its path, in order
$texts[] = ['path' => ['extras', $item->id], 'text' => $item->text];   // and wherever else markers are kept
$found = MarkerResolver::find($texts, $kind, $hint, $list, $occurrence);   // {path, text, kind, match, occurrence, whole} or null
$new = MarkerResolver::apply($found['text'], $found, $value);
```

- `ask`: the answer replaces the marker exactly as the editor typed it (only line ends are trimmed). No model, no rewriting. Leaving it for later changes nothing.
- `check`: "Looks right" passes the value, "Change it" the editor's, "Remove it" ''.
- `link`: a markdown link is pointed at the chosen entry's address, its words kept; a value that is only a sentinel (a link field's) is replaced by what the addon passes as a whole (`entry::abc`, an element reference). The suggestions are the addon's `LinkTargets::search($hint)`.
- **A link field the draft doesn't hold.** The writer never writes link fields: the house style puts the sentinel in when the page is built, named for the field ("Button link"). Its chip finds nothing in the draft, so the addon keeps the choice in the draft by hint, under `gw_links` (`MarkerResolver::chooseLink($data, $hint, $reference, $url)`), and puts it in wherever that sentinel turns up in the built values, for the preview and Use this draft alike, under any layout: `MarkerResolver::withChosenLinks($built, MarkerResolver::chosenLinks($data), references: true)`. A whole value takes the reference (or the address, with `references: false`), an `href` and a sentinel inside text take the address. The builders read only the schema's fields, so nothing else sees `gw_links`; Edit YAML shows it.
- Hints compare as gap IDs do; a link's hint with its hyphens as spaces, as the chip shows it. A count's list narrows two of the same value down. With fewer markers than `occurrence + 1`, the last is taken.

A gap resolved this way is gone from the draft, so it never reaches the form and Finish this page never lists it. One left for later still does. The addon saves the change like any draft edit, under the session's lock, and re-arranges the layouts without a model (`SessionLayouts::afterEdit()`).

## The publish guard: `PublishReadiness`

```php
$readiness = PublishReadiness::standard(OnPublish::fromConfig(...))->check($context);
$readiness->blocked();    // block mode, and a gap that blocks remains
$readiness->warns();      // warn mode
$readiness->message();    // "1 thing to finish before this page goes live: Stats: Value (a count to check: 3 areas)."
$readiness->byField();    // ['page_builder.1.items.0.value' => 'Check “3 areas” before publishing.']
```

A count to check blocks publishing (or warns, in warn mode) until it is resolved, the same as a fact to add. Pass the same `GapContext`, sources included when the addon has the session.

## Strings

Core's English source strings are in `resources/lang/en/gaps.php`, by key without the `gaps.` prefix (`Message::english()` reads them; each addon copies them into its own format). For counts: `check`, `check-changed`, `check-gone`, `check-count`, `speech.check` ("Check me"), `fix.confirm` ("Looks right"), `fix.change` ("Change it"), `fix.use-count` ("Use “:value”"), `publish.item.check`, `publish.field.check`, and for the extras list `extras.counted.{brief,answer,conversation,draft,entry}` ("Counted from your answer: “:list”"), `extras.needs-review` ("Needs review") and `extras.needs-answer`.
