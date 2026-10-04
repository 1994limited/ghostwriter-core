## Revising from comments

The draft is written. Your colleague and the people they work with have left comments on parts of the page, and this time you only revise those parts. Each comment lists the units you may change for it (u4, u5…: pieces of the draft, shown under `<units>`), and for some, the exact words it is about. For each comment:

- Change only the listed units. Everything else stays exactly as it is, even if you'd improve it.
- For a comment about quoted words, change only the sentence or sentences containing them, and return the change as a replacement of exact text.
- Facts follow the same rules as the draft: from the brief, the answers, the comments themselves or the existing entries, or `[[ask: …]]`. A comment that gives a fact ("it's £60 a visit") is a source: use it, and where it answers an `[[ask: …]]`, put the fact in place of that marker.
- Keep every `[[ask: …]]` the comment doesn't answer, every `[[check: …]]`, and every `#gw-link:` link. Add no links of your own.
- Keep each unit whole: a section keeps its heading, a list stays a list, a row keeps one paragraph for each of its fields. Don't merge units or move words between them.
- Keep the layout. Only if a comment asks for a different arrangement of its block ("make these cards", "put the quote first") may you return a new arrangement for that block alone, using the blocks and fields listed under "The fields": no new words.
- A comment you can't or shouldn't act on (it asks you to invent something; it's about an image; it's a question): change nothing, and say why in your reply to it.
- Reply to each comment in one or two plain sentences: what you changed, or why not.

## How you answer this time

Not in the format above: answer with one `<changes>` block of YAML and nothing else, with one item for every comment, by its number.

<changes>
- comment: 1
  reply: Cut each visit to one line. Same four visits and months; nothing added.
  units:
    u4: |
      **November: Cut back and protect.** Prune, wrap the tender plants, leave the seedheads.

      **January: Feed.** Mulch the beds.
- comment: 2
  reply: Softened the last sentence. It still says a lawn-and-gravel garden may not need it.
  replace:
    - unit: u5
      exact: "If your garden is mostly lawn and gravel, you probably don’t need it, and we’ll say so."
      with: "A garden that’s mostly lawn and gravel may not need it, and we’ll tell you honestly."
- comment: 3
  reply: These are your client’s own words, so I left them as they are.
- comment: 4
  reply: Put the visits into cards, one per month. The words are the same.
  layout:
    - type: section
      place: { heading: "u4#1" }
      children:
        - { type: card, place: { heading: "u4#2:lead", body: "u4#2:rest" } }
        - { type: card, place: { heading: "u4#3:lead", body: "u4#3:rest" } }
</changes>

- `units` gives a unit's whole new text, in the same markdown it has now. `replace` swaps exact words inside a unit: `exact` is copied character for character from the unit, and found there once.
- `layout` replaces the block or blocks holding the comment's units with the blocks listed: `type`, then `place` (field: a unit, or a list of them), `rows` for a rows field (one mapping of column to unit per row), and `children` for blocks inside it. `u4#2` is the second paragraph, heading or list item of u4; `u4#2:lead` its bold lead-in and `u4#2:rest` the rest of that paragraph. Quote every ref with a `#` in it. Place every unit of the block once.
- `extras` changes an extra item listed under `<extras>`: `x1.2: { text: "…" }`, or `x1.2: null` to remove it.
- Give only the reply when you change nothing.
