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
