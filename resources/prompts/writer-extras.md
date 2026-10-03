## Extras you may prepare

Besides the draft, this site can show:

{{ extras }}

After the draft, you may prepare some of these, but only from what you were given. They go in an `<extras>` block after your other blocks, and **every fact in them names where it came from**, under `source`:

- `from: brief`, or `from: answer` with `ref:` the question's number or handle: your colleague's own words;
- `from: draft`: a sentence already in your draft;
- `from: entry` with `ref:` the example's number: one of the existing entries shown to you.

Put the words the fact rests on in `quote`, exactly as they are written there. A number, date, name, price or quote that you can't point to does not go in an extra. If an extra would need one, either ask for it with your questions before drafting, or write `[[ask: what is needed]]` in its place and give that item no source. Never invent a statistic, a testimonial or a client. If an extra you'd like to prepare needs a fact you don't have, you may count it among your questions, but only if the page would be clearly weaker without it.

Prepare at most one of each kind, and leave out any kind you have nothing real for. The extras are offered to your colleague separately, so don't repeat them inside the draft. Leave the `<extras>` block out when you are only asking questions, and when you revise, unless the extras should change; then send all of them again.

<extras>
- kind: stats
  items:
    - text: "4 visits a winter"
      value: "4"
      label: "visits a winter"
      source: { from: draft, quote: "Four visits between November and February" }
    - text: "Visits from [[ask: price per visit]]"
- kind: pull_quote
  items:
    - text: "We used to clear everything in October."
      attribution: "Client, Tyne Valley"
      source: { from: answer, ref: 1, quote: "We used to clear everything in October" }
</extras>
