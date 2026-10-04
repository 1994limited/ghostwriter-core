# Changelog

All notable changes to `1994/ghostwriter-core` are documented here. From 1.0.0 it follows semantic versioning: a minor or patch release doesn't break the public API.

## Unreleased

### Changed (breaking for comments, which no addon has shipped)

- **Comments are conversation messages.** The separate review store is gone. Pins not sent yet are the editor's own, in their panel; **Apply** sends them as one message from the editor (`comments.items`: each comment's number, scope, words, author and the hashes of what it may change), claiming the piece as Send does, and the job's one `reviser` call ends in one message from Ghostwriter (`comments.answers` naming the editor's message, `comments.results`: Changed with its before and after, Replied, Refused or Skipped, each with a reply in plain words). Put back and Resolve are noted on that answer. Shared conversations (E7) need nothing more: one run at a time through the session's claim, with no version to compare. See docs/comments.md.
  - `Review\Comments` replaces `SessionReview`: `apply()`, `revise()`, `fail()`, `resolve()` (and reopen), `putBack()`, `pins()` (every sent comment with its status worked out from the conversation, the blocks holding it in a layout, and whether its words have gone), `where()`, `unanswered()`, `nextNumber()`, `itemsOf()`, `resultsOf()`, `blocksOf()`.
  - `Comment`, `CommentResult`, `CommentOutcome`, `CommentStatus` (Revising, Changed, Replied, Not applied, Skipped, Resolved, Detached).
  - `RevisionRequest`, `RevisionValidator::check()`, `Verdict` and `RevisionApplier::apply()` take `Comment`s; `Change::$version` is the index of the editor's message.
  - `ReviewRules` keeps `mayComment()` and `mayApply()`.

### Removed

- `Session::$review` (and its `review` key), `Review`, `Thread`, `Note`, `NoteKind`, `ThreadStatus`, `SessionReview` and its add, edit, delete, reply, repin and version checks, `beforeData()`, and the re-anchoring in `SessionLayouts::afterEdit()` (a pin follows its unit ids, which `UnitMatcher` carries; one whose words are gone shows Detached).

### Changed (Suggest edits)

- **Suggest edits: no suggestion reaches the editor without the model judging it in context.** See docs/suggest-edits.md.
  - **Every free finding is a candidate.** `ReviewInput::numbered()` now numbers every finding (the long-sentence hint and a link field to a deleted page included). The prompt's `<findings>` is now `<candidates>`: each line has its unit, the heading it sits under, its quote, the check's message, the dated words for an Out of date one, and what to write if it is kept.
  - **Keep or drop.** The reviewer answers every candidate: a kept suggestion (`"finding": "f3"` and the fix) or `{"finding": "f3", "drop": "<reason>"}`. Any category may be dropped. `SuggestionReader` reads the older `decline` key as `drop`. The schema has `drop` in place of `decline`, and `category` is no longer required (a drop has none).
  - **No free fallback in a review.** A candidate the model didn't answer is not shown (`unanswered`); one it dropped is not shown and is stored as checked. When the review call fails, the review is Failed with no suggestions. `Finding::toSuggestion()` stays for `EditReviews::preview()`, which is unchanged. Free checks only find and explain; they never write replacement text.
  - **Out of date findings are anchored on their sentence** (`PastYears`, `RelativeTime`): the quote is the whole sentence (a heading line is its own), one finding a sentence, with the dated words in `meta['phrase']` (as written) and `meta['phraseOffset']` (where they start in the quote). The message's `:quote` is the phrase, and so is the revisit chip. Finding ids for these change accordingly. Closing dates and stated counts stay on the phrase.
  - Out of date fixes rewrite the whole sentence, with alternatives that are meaningfully different, one of them the sentence without the dated words ("Winter care visits", never "Every year: winter care visits").
  - `EditReview::decide(Confirmed)` ("It's still right") is allowed for Out of date as well as Fact to check.
  - **The `reviewer` runs on the writing tier at effort `high`** (16000 tokens). `reworder` moves from the quick tier to the writing tier, at effort `medium` (4000 tokens).

### Added (Suggest edits)

- **The verifier: a second pass.** After validation, `Studio::verifyEdits(ReviewInput, list<Suggestion>): Result<SuggestionReply>` makes one `verifier` call per part that kept anything (new prompt `resources/prompts/verifier.md`, writing tier, effort `high`, 12000 tokens; `Suggest\VerifyPrompt`). Each kept suggestion is shown with its whole paragraph, its heading, why it was made and the site entry it cites; the reply is `<verdicts>` with `{"id": "s1", "verdict": "keep" | "fix" | "drop", "reason", "replacement"?, "alternatives"?}`. `SuggestionValidator::verify()` applies it: a fix passes every check again; a drop goes into `checked` (`by: 'verifier'`). If the verifier fails, the validated suggestions stand and a warning is logged. `Studio::verifierInstructions()`. `EditReviews::run()` does reviewer, validation, then verifier.
- `ReviewInput::modelCalls()`: the most model calls a review makes (a reviewer and a verifier call per part). `calls()` is still the number of parts, for the confirm. `EditReview::$calls` and `$usage` count both agents.
- **Checked candidates.** `ValidatedReview::$checked` and `EditReview::$checked`: `list<{id, passage, reason, by}>`, what the reviewer (`by: 'reviewer'`) or the verifier (`by: 'verifier'`) dropped in context, logged at debug level with the reason only. `EditReviews::quieted()` turns each into a `Quiet` of the new state `Quiet::CHECKED`, for 12 months or until its passage changes, so the same words aren't a candidate next time; the prompt's `<dismissed>` lists them as "checked fine". `ValidatedReview::$verified` and `EditReview::$verified` (suggestion id ⇒ `keep` or `fix`), and `EditReview::$verifyError`.
- **`Anchor\SentenceFit`**: a deterministic check that a replacement leaves whole sentences (a capital where a sentence starts, the same end punctuation, no word doubled at a join, nothing empty mid-sentence except a Duplicate). The validator runs it on every replacement and alternative, after the model, and in `acceptsVersion()`.
- `SuggestionValidator::DROPS` gains `unanswered`, `fit` and `verifier`.
- `CheckText::sentenceRange()`, `sentenceAnchor()` and `headingBefore()`. `ReviewInput::batchFor(Anchor)`. `SuggestionReader` takes the list's name (`new SuggestionReader('verdicts')`).
- **Prompt caching** for `reviewer`, `verifier` and `reworder` (`Agents::CACHED`, `Agents::cachesInstructions()`): Anthropic gets their instructions as a system block with `cache_control` (ephemeral), and so does Claude through OpenRouter.
- A kept candidate with nothing to write stays as a suggestion with no replacement only for a link field, a broken link (the link is the fix) and an image whose picture wasn't attached ("Describe the image yourself").


### Fixed
- Suggest edits' `Reconciler` no longer marks a suggestion Done when its new words were in the old ones already ("Winter care visits" in "New for 2024: winter care visits", or a fact's version without, "team", in "team of 6"): such words count only once the old words have gone, unless they hold them.
- Suggest edits' `Reconciler` marks an Out of date rewrite Done when it is the old sentence less its dated words ("Two open days a year at the studio garden…" for "New for 2023: two open days a year…"): the new sentence was found as the old quote by a fuzzy match, so the old words never seemed gone. A fuzzy match holding the new words no longer counts as the old words.

## 1.9.0 - 2026-10-04

### Added

- **Comments on blocks: the comments model on the session (`Review\*`).** An editor comments on a block, a card, some words, a field or the whole page; the comments are shared by everyone on the piece (E7) and follow their words from layout to layout and from turn to turn. No model is called. See docs/comments.md.
  - `SessionReview`, what the addons call: `add()`, `edit()`, `delete()`, `reply()`, `resolve()`, `reopen()`, `repin()`, each under the session's lock and allowed while Ghostwriter works, each taking the review's `version` (a change from an older copy is refused with `Conflict`); `threads()` (every thread with its state, the blocks holding it in a layout and whether it is in that layout), `where()` and `review()`.
  - `Review` (threads, the next pin number, a version; `reanchor()`, and `send()`, `answer()`, `sendBack()` for a run), `Thread`, `Note`, `NoteKind`, `Change`, `Scope`, `ScopeKind`, `ThreadStatus` (Not sent, Revising, Changed, Replied, Resolved, Detached) and `ReviewRules`. Limits: 100 threads per piece, 30 notes per thread, 2000 characters per note, 12 threads per Apply.
- **`Session::$review`**: the piece's comments (`Review::toArray()`), stored like `units`, under `review` (Filament: a JSON column the addon adds).
- `SessionGuard::annotate()`: a change anyone who can see the piece may make whatever Ghostwriter is doing, under the session's lock.
- **Comments on blocks: Apply (the scoped revision).** "Apply N comments" sends every comment not sent yet (up to 12) in **one** model call; each may change only the units it is anchored to. See docs/comments.md.
  - `SessionReview::apply()` (claims the piece for a run, as Send does, and puts the comments into it with each unit's hash), `revise()` (in the job: the call, the checks and the changes applied under the session's lock), `putBack()`, `changes()` (each change's before, after and word diff) and `beforeData()` (the draft with a change undone, for "Show before").
  - `Studio::revise(RevisionRequest): Result<RevisionReply>` and `Studio::reviserInstructions()`: the new agent `reviser` (prompt `resources/prompts/reviser.md`, after the writer's instructions; `Agents`: 8000 tokens, effort `medium`; in `StudioOptions::WHOLE`, with its own cut-off message). A comment may change its units' text, fill an `[[ask: …]]` with a fact the editor gave (recorded in the `Change`'s `filled`, decision 4), lay its block out anew when it asks to, change an extra item in its scope, or only reply.
  - `RevisionValidator` (no model): a comment is refused, and goes back to Not sent with a plain line, for a change outside its scope, outside its quoted sentences, losing or making up a marker, adding a link or an unsourced fact, losing a unit, or not fitting its field; `size` and `layout` are warnings. `Verdict`.
  - `RevisionApplier`: skips and reports a unit someone changed during the run (by its hash), writes what passes into the draft and units, re-arranges every layout with `SessionLayouts::afterEdit()` (no call), and records each thread's before and after. `ApplyOutcome`, `RevisionRequest`, `RevisionReply`, `RevisionItem`, `WordDiff`.
  - `Text\DraftEditor`: one unit's new text written into a draft's data (a rich-text section, a text, a list, a row), and the data dumped as the addons dump it.
  - `SessionGuard::begin()`: claims the piece for a run that isn't a chat message, and prepares it under the same lock.
- **Suggest edits, the free checks (`Suggest\*`).** Deterministic checks over an entry's current values, with no model and no request. See docs/suggest-edits.md.
  - `Findings::standard()->report(CheckContext)`: `FindingReport` (`findings` in form order, Finish's `gaps`, `leftovers()`, `emptyFields()`). `find()`, `without()`, `with()`, `checks()`.
  - Checks (`Suggest\Checks\*`): `PastYears` (a past year written as current; history words and a year on its own left alone), `RelativeTime`, `ClosingDates` (in text, and date fields whose handle reads as an end), `StatedCounts` (counts and prices to recheck; a template with `{answer}`), `LongSentences` (a hint only), `EmptyLinkText`, `Overlaps` (5-word shingles against other entries).
  - Phrase lists in English, German, French, Dutch and Spanish (`resources/suggest/phrases/*.php`, `Phrases::for()`), with dates read in each (`Dates`).
  - `Finding`, `Category` (`isWording()`, `rank()`, `label()`, `speech()`), `Needs`, `Anchor` (a `FieldPath` and an `Anchor\TextQuote`, an asset, or a whole value; `key()`, `hash()`), `AnchorScope`, `CheckContext`, `CheckText`, `Check`, `EntryRef`, `Shingles`, `IndexedParagraph`, `DigestEntry`.
  - Decisions that stick: `Quiet` and `Quieted`. "It's still right" and Dismiss last 12 months, or until the passage is edited.
  - `SuggestOptions` (`claims`: the per-site claim-check switch) and `Revisit\AgePolicy` (a quarter weight in groups with a date field, with a switch per group).
  - Ports: `Suggest\EntryIndex`, with `Testing\MemoryEntryIndex` and `Tests\Contracts\EntryIndexContract`.
- **Finish this page: `MissingAlt` and `SeoLength` detectors**, in `GapFinder::standard()` as suggestions (never counted, never blocking). Ports `Gaps\AssetAlt` and `Gaps\SeoFields`, with `SeoField`, `PlainSeoFields`, `Testing\MemoryAssetAlt`, `Tests\Contracts\AssetAltContract` and `SeoFieldsContract`. `GapContext` gains `alt` and `seo` (named, optional): without them, neither detector finds anything, so Finish is unchanged until an addon passes them.
- `resources/lang/en/suggest.php`. `Gaps\Message::english()` and `strings()` read any namespace with a file in `resources/lang/en/`; keys without one are `gaps` keys, as before.
- **Content to revisit, with no model (`Revisit\*`).** See docs/suggest-edits.md.
  - `RevisitScanner` (`scan()`, `rescore()`), `RevisitIndex` (`refreshOne()` on save, `deleted()` rescans the entries that linked to it, `refresh()` daily with `watch` dates, `full: true` weekly), `RevisitRow` (`snooze()`, `priority()`), `RevisitReason`, `ReasonKind`, `Priority`, `EntrySnapshot`, `Links`.
  - Ports `RevisitStore` and `EntrySource`, with `Testing\InMemoryRevisitStore`, `Testing\MemoryEntrySource`, `Tests\Contracts\RevisitStoreContract` and `EntrySourceContract`.
  - **The opt-in weekly external link check:** `ExternalLinkCheck` (once a week per address, one request per host per second, 10 s timeout, at most 500 a run), `RevisitOptions::$externalLinks` (off by default), port `LinkProbe` with core's `HttpLinkProbe` (HEAD, then a one-byte GET) and `Tests\Contracts\LinkProbeContract`, `LinkResult` (broken only after two failed checks in a row), `LinkStatus`. Results feed the new `external-link` Link findings (`Suggest\Checks\ExternalLinks`, through `CheckContext::$external`).
  - `Suggest\Watches` (`PastYears`, `ClosingDates`): when time alone changes a check's answer. `CheckContext::at()`.
  - `resources/lang/en/revisit.php`.

- **Suggest edits: the review call (`Studio::suggestEdits()`).** See docs/suggest-edits.md.
  - `ReviewInput` (the CheckContext, the writer's voice guide and kind, the findings numbered `f1…`, the `SiteDigest`, images for missing alt text), with `calls()`: **a long page is split into several calls** (`WORDS_PER_CALL`, never splitting a unit), and the replies are merged. `ReviewBatch`, `ReviewPrompt` (link targets shown as `entry:e12` and `link:3`, never as stored).
  - New agents `reviewer` (8000 tokens, effort medium; a cut-off reply keeps the suggestions that closed) and `reworder` (1500, low, quick tier); new prompts `reviewer`, `reworder` and `scoped-edit` (the rules every scoped edit shares). `PromptLibrary::NAMES` lists them.
  - `SuggestionReader` (JSON in `<suggestions>`, lenient) and `resources/schemas/suggestions.schema.json`; `SuggestionReply`.
  - `SuggestionValidator`: never invents facts. Each suggestion is anchored with `QuoteFinder` or dropped; checked with `ScopedEditCheck` and `SourceCheck`; Fact to check templates checked against the quote; claims dropped with the claim switch off; dismissed, overlapping and over-cap suggestions dropped; free findings the model didn't fix kept in their free form. `ValidatedReview` (`suggestions`, `dropped` counts).
  - `Suggestion` (a replacement plus up to two alternatives), `Reason`, `ReasonSource`, `FactCheck` (`fill()`), `AnswerKind`, `LinkChange`, `SuggestionState`, `Finding::toSuggestion()`.
  - "Write another": `Studio::reword(RewordRequest)`, and `SuggestionValidator::acceptsVersion()`.

### Changed

- `PromptLibrary::NAMES` lists `reviser`; `Agents` has `reviser`; `StudioOptions::WHOLE` and `CUT_OFF_MESSAGES` have `reviser` (an addon's own cut-off messages may now name it). The writer's request is unchanged (`bin/compare-requests`).
- `SessionLayouts::afterEdit()` (and so `afterWriter()`) re-anchors the session's comments to the units it carried over: a comment whose text is gone becomes Detached, and comes back when the text does.

## 1.8.3 - 2026-10-04

### Added

- **Resolve a gap from its chip (`Gaps\MarkerResolver`, `Markers::resolveAsk()`, `Markers::resolveLink()`).** A chip in the page preview or the Text tab says only its kind, hint, list and order; `MarkerResolver::find()` finds that marker in a draft's values (`leaves()`) and anything else the addon passes, and `apply()` replaces it: an answer exactly as typed, a count confirmed, changed or removed, a link pointed at an entry (or a link field's whole value replaced). No model. A link field the draft doesn't hold (the house style's sentinel) is chosen by hint and kept in the draft under `gw_links` (`chooseLink()`, `chosenLinks()`), and `withChosenLinks()` puts it into the built values. See docs/gaps.md, "Resolving a gap from a chip".
- **`markers.js`: chips know their marker.** Each chip carries `data-gw-gap-match` (the marker as written), and `markGaps()` gives each chip's `match` and `occurrence` (which of the chips with that kind and hint it is). Chips with `onActivate` have `aria-haspopup="dialog"`. `toHtml()`'s chips carry the same data attributes. New `unmarkGaps(root)` puts the markers back as written, for text about to be edited, so chip markup is never saved.

## 1.8.2 - 2026-10-04

### Added

- **Gap markers shown as chips, not raw text (`resources/js/preview/markers.js`).** A dependency-free ES module the addons copy as they copy the locator, with a checksum test. Run after the locator in the preview's frame, `markGaps()` turns `[[ask: …]]` into an amber chip reading the hint ("Only you know this: add it before publishing") and `[[check: 3 areas | from: …]]` into the value with a dotted amber underline ("Counted from '…'. Check it before publishing"). It gives `#gw-link:` links a dashed underline ("Link to choose"). Each has a visually hidden label for screen readers, and the styles go into the frame, never the site's CSS. `countByRegion()` counts the chips in each located block. For places without a DOM to rewrite (the Extras list, a row under a plain text input), there are `segments()`, `toPlainText()`, `toHtml()` (escaped), `gapsIn()` and `chipRow()`. It is display only and never changes a stored marker. Its patterns are core's, and a Node test keeps them equal to `resources/gaps/patterns.json`. See docs/preview.md.

## 1.8.1 - 2026-10-04

### Fixed

- **Layouts: a required image no longer drops every alternative.** On a site whose hero has a required image (Northfold's Pages), `PlanValidator` dropped every plan that made its own hero, as a plan never holds images, so the planner's layouts were never offered. "Required" now applies only to fields the writer writes in (text, long text, rich text, a list, rows: `PlanValidator::requiredWords()`). A required image, link, entries field, setting (choice, toggle, number), group or nested builder left empty in a plan's block gets what the writer's draft gets on the build path: the striped placeholder, the `#gw-link:` sentinel, the house or CMS default. The planner's brief marks only those fields as required, too. See docs/layouts.md.
- **Why a plan was dropped is now recorded.** `PlanValidator::validate()` returns `Validated` (`kept`, `dropped`: violations by plan id, `rules()`); `valid()` is unchanged. `SessionLayouts::planned()` gives the last planner call's `Validated`. Dropped plans are logged at debug with their rules, as are plans marked stale after an edit, and the planner call logs one line naming each dropped plan and its rules.

## 1.8.0 - 2026-10-03

### Added

- **Extras, prepared by the writer in the same call as the draft (`Arrange\Extras\*`).** Stats, FAQs, a pull quote, an "at a glance" box, captions, a second call to action, a testimonial and a short intro, only for the block types and fields the site has. Every fact in an extra carries its source; an unsourced fact is never kept. See docs/layouts.md.
  - `ExtraSlots::for(?Schema)`: which kinds this site can show, and where (a page builder's set by handle or label, with the field shape as a check; rich text; a top-level intro field). `kinds()`, `has()`, `slots()`, `describe()`, `isEmpty()`.
  - The writer's instructions gain the new prompt `writer-extras` ("Extras you may prepare") whenever the layout has a schema with somewhere to put an extra. `Studio::extrasSection(WriterContext)` gives that section. With no schema (a `Layout` built from fields an addon described), the writer's request is exactly as before.
  - `TaggedResponse::$extras`: the writer's `<extras>` block, kept out of the reply.
  - `ExtrasReader::read(?string $block, ExtraSlots, ExtraSources): Extras` and `Studio::extras(TaggedResponse, Conversation, WriterContext, array $exampleIds = [])`. An item is kept when its quote is in its source (the brief or answers, the draft, or an example the writer was shown) and every figure, quotation and name in it is in that quote (`ScopedEditCheck`: no fact and no link beyond the quote); an attribution's names must be in its source. An item that fails, or has none, is kept only with an `[[ask: …]]` in place of the missing fact and nothing else unsourced: it "needs your answer". Everything else is dropped and logged, never invented.
  - `Extras` (`all()`, `items()`, `item()`, `extraOf()`, `edit()`, `without()`, `toArray()`, `fromArray()`), `Extra`, `ExtraItem` (ids `x1.2`, parts such as `question` and `attribution`, `needsAnswer()`), `Source`, `SourceKind`, `ExtraKind`, `ExtraSources::fromWriter()`.
- **`Session::$extras`**: the session's extras (`Extras::toArray()`). Stored like `$units`: only once there is something in it, under `extras` (Filament: a JSON column the addon adds).

- **Layouts: plans, the arranger and the validator (`Arrange\*`), with no model.** A plan arranges the draft's units and extras into a page builder's blocks, a rich-text field's structure or a plain field; it never holds words of its own. See docs/layouts.md.
  - `Plan`, `PlanBlock`, `Placement`, `PlanOrigin`, `Transform` (`as-is`, `split`, `join`, `lead-in-to-heading`, `heading-to-lead-in`, `paragraphs-to-list`, `list-to-paragraphs`, `heading-level`, `as-quote`), `Plans` (`fromDraft()`, `of()`, `get()`, `writer()`, `suggested()`, `with()`, `toArray()`, `fromArray()`).
  - `Plans::fromDraft($draft, Units, Schema)`: the writer's plan "w". Arranging it gives the draft back exactly, for every draft in the golden layout fixtures, and `bin/compare-layouts` finds no difference in the entries built from them.
  - `Arranger::arrange(Plan, Units, Extras, $draft, Schema)`: the draft data a plan gives, for the existing build path; `Arranger::build()` runs `EntryBuilder` on it.
  - `PlanValidator::check()` and `valid()`, with a `Violation` per rule: unknown blocks, fields and refs, kinds, limits, required fields, empty blocks, units missing, duplicated or outside, the words and markers conserved, unsourced extras, boilerplate, sameness, and the build round trip.
  - `PlanRepair::repair()`: after an edit, removed units come out and new ones go in after the unit before them; what still fails is marked `stale`.
  - `PlanReader`: the layout planner's `<plans>` YAML into plans.
  - `SitePatterns::find()` (every distinct block order, commonest first) and `profile()` (rich-text structure); `Candidates::rank()` marks the plan most like the site's pages `suggested`.
- **The layout planner: drafting now proposes up to two other layouts.** See docs/layouts.md.
  - `Studio::planLayouts(LayoutBrief): Result` (`list<Plan>`): one call to the new agent `layout-planner` (prompt `resources/prompts/layout-planner.md`; `Agents`: 4000 tokens, effort `low`; not in `WHOLE`, so a cut-off reply keeps its whole plans). `LayoutBrief` summarises the units, extras, blocks and fields, the site's patterns and the writer's layout.
  - `SessionLayouts`, what the addons call: `afterWriter()` (extras read, unit ids carried, the writer's layout re-derived, the others repaired; on the first draft, the planner called, its plans validated, ranked and stored), `afterEdit()`, `refresh()`, `plans()`, `choose()`, `chosen()`, `draftData()`, `build()`, `extras()`, `editExtra()`, `deleteExtra()`, `needsPlanner()`. With `LayoutContext` (schema, pattern, entries, defaults, example ids).
  - A first draft is two calls (the writer with extras, then the planner); later turns are the writer only; choosing and applying a layout, and editing extras, call nothing.
- **`Session::$plans`** (`Plans::toArray()`) **and `Session::$plan`** (the chosen layout's id), stored like `$units`, under `plans` and `plan`.
- **Derived counts: counted in code, marked for review.** An extra may now say "3 areas" for "Northumberland, Durham and the Tyne Valley". See docs/layouts.md and docs/gaps.md.
  - `Anchor\ListCounter` (`find()`, `count()`, `fromMarker()`, `numbers()`, `numberIn()`) and `Anchor\CountedList` (`items`, `text`, `style`, `count()`, `oneLine()`, `sameItems()`, `shared()`): plain lists (commas with a final "and" or "or", Oxford comma or not; bullets, outer level only) counted with no model. Open lists ("etc.", "such as", "including"…), ranges, prose and ambiguous lists are skipped.
  - `ExtrasReader`: an item whose only fact beyond its quote is one whole number, where the quote is a plain list, is a derived count. The number is core's count, whatever the model wrote ("5 areas" becomes "3 areas"; `ExtrasReader::$counted`, logged at info), and goes in as a `[[check: 3 areas | from: …]]` marker. `ExtraItem::$count` keeps the list; `needsReview()`, `countLabel()` ("Counted from your answer: …") and `state()` ("Needs review") are for the extras list.
  - The review marker: `Markers::CHECK`, `CHECK_PATTERN`, `check()`, `checks()`, `resolveCheck()`, `withoutChecks()`; `has()` and `normalise()` know it, and `patterns()` (`resources/gaps/patterns.json`) has `check`.
  - `GapKind::Check` (blocks), found by `Detectors\CheckMarkers` in `GapFinder::standard()`: "I counted 3 areas from “…”. Is that right?", with the fixes `FixAction::Confirm` ("Looks right"), `FixAction::Change` ("Change it") and `Remove`. When the list has changed since (`GapContext::$sources`), `meta.stale` is `changed` (with `newCount` and `newValue`, and "Use “4 areas”"), `gone` or `count`. `PublishReadiness` blocks or warns until it is resolved.
  - `GapContext::$sources` (named, optional) and `ExtraSources::fromSession()`; `Fix::withLabel()`; the strings in `resources/lang/en/gaps.php` (`check*`, `fix.confirm`, `fix.change`, `fix.use-count`, `extras.*`).
  - `Tests\Contracts\MarkerRoundTripContract` checks a count to check in every shape: each addon's apply path must keep it.
- `PatternFinder::sequences()` and `PatternFinder::sequenceOf()`: the block-order counting `find()` uses, public for `SitePatterns`. `find()` is unchanged.
- `LayoutLog::export()` records `Plan` and `Plans` as arrays.

### Changed

- `TaggedResponse`'s constructor takes `$extras` as a new optional last argument; a reply with no `<reply>` block has its `<extras>` block taken out of the text shown, as `<draft>` and `<images>` are.
- `PromptLibrary::NAMES` lists `writer-extras` and `layout-planner`; `Agents` has `layout-planner`.
- `writer-extras` says, in one sentence, that a stat may count a list by quoting it, and that Ghostwriter counts it.
- `Markers::withoutAsks()` also writes each count to check as its value; the `bracketed` placeholder-text pattern no longer matches inside `[[…]]`. `PlanValidator`'s `MARKERS` rule and `ScopedEditCheck` keep counts to check like links: one lost or doubled fails.

## 1.7.0 - 2026-10-03

### Added

- **Shared anchoring (`Anchor\*`), for page preview's comments and Suggest edits.** Both point at a field and a quoted range of its text, and both are scoped edits, so they share one layer. No model is involved. See docs/preview.md.
  - `TextQuote` (`exact` ≤ 300 characters, `prefix` and `suffix` ≤ 32, `around()`, `toArray()`, `fromArray()`), as the W3C `TextQuoteSelector`.
  - `QuoteFinder::find(TextQuote, $text, ?int $occurrence = null, bool $markdown = false): ?QuoteMatch`: exact after normalising whitespace, NBSP, curly quotes, dashes and invisible characters (with `$markdown`, inline syntax too); repeats picked by the prefix and suffix, then by `$occurrence`; otherwise one fuzzy match (trigram Dice ≥ 0.9, quotes of 16 characters or more), and null when two places match. `QuoteMatch` (`offset`, `length`, `occurrence`, `fuzzy`, `text()`, `requote()`) counts in characters.
  - `NormalisedText` (`of()`, `string()`, `words()`, `original()`): the normalising, with a map back to the original.
  - `Sentences` (`split()`, `covering()`, `inOneBlock()`).
  - `SourceCheck::unsourced($new, $sources)`: figures (compared as `Studio\Figures` compares them), quotations and names in new text that no source has.
  - `ScopedEditCheck::check($before, $after, $sources, $minRatio, $maxRatio, ?TextQuote $quote, $mayFillAsks, $mayAddMarkers)`: `scope`, `markers`, `link`, `facts` and `size`.
  - `resources/anchor/quote-cases.json`: QuoteFinder's cases, for every front-end port of it to run.
- **Units: stable ids for every piece of draft text (`Arrange\*`).** A comment points at units, not block positions, so it follows its words into another layout and across turns. See docs/preview.md.
  - `Units::fromDraft(Draft|array, Schema, ?RichTextDialect)` and `Units::fromEntry(EntryData, Schema, RichTextDialect)` (block IDs in the paths, for Suggest edits): a unit per text, long text or list value, per section of rich text or a markdown field (split by its top headings, with any lead as prose), per row of a rows field, and per image field with something in it. Ids `u1`… in reading order. Also `get()`, `all()`, `ids()`, `inBlock()`, `at()`, `count()`, `toArray()`, `fromArray()`, `of()`.
  - `Unit` (`id`, `kind`, `path`, `markdown`, `pieces`, `blockType`, `assets`, `part`, `hash()`, `where()`), `UnitKind`, `Piece`.
  - `UnitMatcher::carry(Units $before, Units $after): Units`: an id is kept at the same place when the text is ≥ 60% similar (word-pair Jaccard), else by the best match anywhere ≥ 50%; ids are never reused.
  - `Units::sidecar()` and `restore()`: the ids stored beside the draft, never in its YAML.
- **`Session::$units`**, the draft's `Units::sidecar()`. Stored like `$gaps`: only once there is something in it, under `units`, so a store with no place for it yet (Filament's table) is never sent the key.
- **The preview's invisible markers (`Preview\PreviewMarkers`).** A marker is `U+E0067 U+E0077`, a payload in Unicode tag characters (`b7.2`: block 7, field 2; `f2`: a top-level field; `s3`: a section of rich text) and `U+E007F`. `mark(array $data, Schema, ?Units): PreviewData` marks the preview's copy of apply's data (every text value of every block, plain text, markdown, HTML and Bard JSON, and each section of rich text) and builds its `BlockMap` of `MappedBlock`s (`key`, `kind`, `path`, `label`, `parent`, `units`, `fields`, `assets`, `anchors`, `type`) for the locator. Also `encode()`, `decode()`, `strip()`, `stripText()`, `contains()`, `markText()`, `markMarkdown()`, `markHtml()` and `markBard()`. `PreviewData` (`data`, `map`, `hash`, `planId`, `draftVersion`). See docs/preview.md.
- **`Tests\Contracts\PreviewMarkerContract`** (with `PreviewMarkerContractTest`): each addon proves that its stored rich text, markdown and plain values keep their markers through the site's own rendering, strip back to exactly the unmarked page, and that apply's data never holds one. Core runs it for HTML and Bard.
- **The preview locator (`resources/js/preview/locator.js`)**, framework-free, for each addon to copy: `findMarkers()` (text and `alt`, `title`, `aria-label`, `content`; removed at once), `locate(doc, map)` (a region per block, level by level: the block's own wrapper, or a run of a shared container's children, with the bare unmarked elements before it back to the block before, then after it up to the next block, never the header, footer or nav; then by asset file name, by text anchor (unique matches only), and by the gap between located siblings; `missing`, `partial` and the `content` area), `measure()`, `canRead()`, `watch()`, `contentArea()`, `stripText()`, `words()`, `decodePayload()` and `parsePayload()`. Its Node tests (`tests/js`, no install) include a page marked by core's PHP (`tests/Fixtures/preview/page.json`), and CI runs them.

### Changed

- `Text\Draft::parse()` takes out any preview marker, so one can never reach a draft or an entry.
- **Preview markers go at the end of each value and section, not the start.** The phase 0 spike found that a leading marker is the first character to a template filter, so Antlers `title` and Twig `capitalize` lowercased the real first word. Plain text gets its marker before trailing whitespace; markdown at the end of its last line of text (before a heading's closing `#`s, a table row's last `|` or a hard break); HTML after its last text, outside closing inline elements (`</a>`, `</strong>`) but inside the paragraph; Bard at the end of its last text node, or in a text node of its own after one with marks (which `strip()` removes whole); a list on its last item; section markers (`s<n>`) at the end of each section. Never straight after a black flag (U+1F3F4). `markMarkdown()`, `markHtml()` and `markBard()` now key each marker by the last line, element or node it covers (`PreviewMarkers::LAST` for the whole value). The locator's bare roots and runs reach back over the unmarked elements before their marks, then forward over what is left. `PreviewMarkerContract` adds two cases: filters that capitalise the first letter keep the text and its marker, and a value ending in punctuation, an emoji or a link keeps its marker (outside the link).

## 1.6.1 - 2026-10-03

### Fixed

- **`BriefCheck` no longer takes out what the person or the context gave (1.6.1).** Quoted post titles became `[Add: the quote]`, and a length the person stated became `[Add: the figure]`. Now:
  - figures are compared by what they say, not how they're written: "800-word", "eight hundred" and "about 800"; "£12k", "£12,000" and "twelve thousand pounds"; "40%" and "forty per cent"; "2026-05-14", "14/05/2026" and "14 May 2026"; ranges ("£5–10k", "1,500 to 2,000"), and millions. A price or a percentage must be given as one or as a plain number, so "40 pounds" doesn't allow "40%". "800-word" counts as the piece's own length;
  - only speech and testimonials are bracketed. Quoted titles of the group's entries (the `titles` the model was given, which the examples come from), the working title, terms the person used, and proposed titles or headings ("Sections: …", "called …") stay, and a figure inside one of them is part of the title;
  - invented prices, client quotes, years and team sizes are still taken out.

  `BriefCheck::check()` takes the titles as a new optional last argument (`$titles`); `Studio::fillBrief()` passes the request's titles and the working title.
- **`Studio::fillGap()` compares figures the same way**, so a summary or shortened text that writes "£1,200,000" as "£1.2m", or "eight weeks" as "8 weeks", is no longer refused. Both use the new internal `Studio\Figures`.

## 1.6.0 - 2026-10-03

### Added

- **OpenRouter, a fourth AI provider (`openrouter`).** `Ai\Providers\OpenRouter` writes through OpenRouter's OpenAI-compatible Chat Completions, with images as input (photo ranking, alt text), and makes images through its Image API, with references. It sends app attribution (`HTTP-Referer`, `X-OpenRouter-Title`, `X-Title`) and is retried like the other providers. The key is `OPENROUTER_API_KEY` (`Credentials::ENV`, so `keyStatus()` lists it last) or a connected one. See docs/providers.md.
  - **Models by tier.** New `Agents::tier()` (`Agents::WRITING`, `Agents::QUICK` for the photo agents and `gap-filler`) and `Models::OPENROUTER_TIERS`: `anthropic/claude-opus-5.5` for writing, `anthropic/claude-sonnet-5.5` for quick jobs. Also `Models::defaultTextFor()`, `Models::nativeModel()`, `Models::OPENROUTER_TEXT_CHOICES` and `Models::OPENROUTER_IMAGE_CHOICES`. The new optional `Ports\ModelTiers` (implemented by `StaticProviderSettings`, which takes `tierModels`) chooses a model per tier. `reasoning.effort` goes only to models whose own provider takes effort.
  - **Errors:** a 402 is the new `Exceptions\OutOfCredit`: "Your OpenRouter credit has run out. Add credit at https://openrouter.ai/settings/credits, then try again." A key's own limit gets its own message, and the in-flight budget is `RateLimited`. A 403 is moderation or a refusal (`Refused`), never a bad key. An error inside a 200 is not taken for an answer.
  - **Images:** `Models::IMAGE_ORDER` is now `openai`, `gemini`, `openrouter`, so sites with an OpenAI or Gemini key keep using it. The default image model is `openai/gpt-image-2.5-sunburst`.
- **Connect with OpenRouter.** `Ai\Credentials\ConnectsProvider` (`authorizationUrl($state, $redirectUri, $codeChallenge)`, `connect($code, $verifier): ConnectedKey`, `connected()`, `usesEnvKey()`, `disconnect()`, `account(): ProviderAccount`) is implemented by `OpenRouterConnection`, which uses OAuth PKCE (S256) and checks connections with `GET /api/v1/key`. Also new:
  - `Credentials\Pkce`;
  - `Credentials\ConnectedCredentials`, which reads keys from `.env` first and connected keys after;
  - `Credentials\ConnectedKey`, masked in dumps;
  - `Credentials\ProviderAccount`;
  - the storage port `Ai\Ports\ProviderKeys`, which each addon implements and encrypts;
  - `Exceptions\ConnectFailed`;
  - for tests, `Testing\FakeOpenRouter` and `Testing\InMemoryProviderKeys`.

  docs/connecting-accounts.md describes the routes and the settings row.
- `Http\Transport::get()`, and an optional error reader (`$errors`, the new last constructor argument, and `withErrors()`) that a provider uses to read its own error responses first.
- **The brief in the conversation (`Domain\Sessions\BriefThread`, `BriefStage`).** After the kind is chosen, Ghostwriter asks for the quick details in one message ("What’s it called, and what should it say? A line or two is plenty."), fills in the kind's whole brief from the reply and shows it as a card to check, with "Looks right, start writing" and "Try again". Agreeing stores the brief on the piece and starts the writing, which goes on as before; the agreed card collapses to "Show the brief" and stays editable. "Draft this" from the plan skips the question and fills the card from the idea. All of it is kept in the session's messages (each step under a `brief` key), so no store or table changes, and pieces from before carry on as they were. `BriefThread::stage()` (`Details`, `Filling`, `Proposed`, `Writing`, `Questions`, `Drafting`), `fills()`, `card()`, `agreed()`, `text()`, `visible()` (what to render, by message index), `request()`, `step()` and `forWriter()`. See docs/studio.md.
- **`SessionGuard::open()`, `openFromIdea()`, `details()`, `propose()`, `tryAgain()`, `agree()` and `editBrief()`**, under the session's lock with the usual refusals (`Busy` while a run works, `Conflict` for a step out of turn). A failed fill is tried again with `retry()`.
- **`Studio::fillBrief(BriefRequest): Result<Brief>`** and the `brief-filler` prompt (`Agents`: 6,000 tokens): one call gives a working title, an answer for every question and the examples to model on (the request's, chosen as the brief screen chose them, at most six). `BriefRequest::fromDetails()`, `fromIdea()` and `tryAgain()` (the previous brief, with the answers the person changed kept exactly). `Studio::briefFillerPrompt()`.
- **`Studio\BriefCheck`: facts about the organisation are never invented.** A figure or quotation in a filled brief that the person didn't give, and the kind's text doesn't have, becomes `[Add: the figure]` or `[Add: the quote]` (lengths and counts of the piece itself stay); a set-answer question gets one of its values or nothing; a required question left blank gets `[Add: <question>]`. Logged, without the reply.
- `Studio\Brief` (`title`, `answers`, `examples`, `attempt`, `open()`, `with()`, `toArray()`, `fromArray()`, `MAX_EXAMPLES`).
- `Studio::brief()` takes an optional working title (`?string $title = null`), written first.
- `resources/lang/en/brief.php`: the English for the ask, the card, its buttons and the announcement, for the addons to copy.

### Changed

- A provider's key parameter is `#[SensitiveParameter]`.
- The "not a provider Ghostwriter can write with" message now lists openrouter.
- Requests to Anthropic, OpenAI and Gemini are unchanged. `compare-requests` shows no difference, and neither do their HTTP requests.
- `Studio\Conversation` leaves out the brief's own messages before it was agreed, so the writer starts from the agreed brief as it did from the brief screen's.
- `Session::title()` uses the brief card's working title until there is a draft.
- **No "guess" in what people read.** `gaps.ask` is now "I left a gap in :label: :hint. Only you know this. What should it say?", `gaps.ask-value` ":label is empty: :hint. This one needs you." and `gaps.guide.reason.draft` "Only you know this." The prompts' rule says "invent" instead of "guess": the writer's "Never invent the fact instead", the gap filler's "never invent what one stands for" and the brief writer's "are never invented". Every addon's writer and brief-writer requests change by that word (`tests/Fixtures/studio/*`).

### Deprecated

- `Studio::draftBrief()` (the brief screen's "Fill in the brief") and the `brief-writer` prompt: use `fillBrief()`. `SessionGuard::start()` with a brief from the screen still works, for pieces started that way.


## 1.5.0 - 2026-10-03

### Added

- `Libraries\Paid\Shutterstock` takes an optional PSR-3 logger (`?LoggerInterface $logger = null`, the new last argument), used to note at debug when a user-token search falls back to basic auth.

### Changed

- **Shutterstock searches as the user when there is a token.** With a fixed token or a connected account's token (`LibraryTokens`, renewed first if it has expired), `search()`, `photo()` and `preview()` send it (Bearer) instead of the app key and secret, so results are what the account can license (a free API subscription can license only the Free collection, and Shutterstock limits a search to it only when the user makes it). Without a token they use basic auth, as before. If the user's token is refused for a search (401, 403), or can't be renewed, the search uses basic auth and says so at debug; a look-up refused with the token isn't retried (`NotConnected`, `TOKEN_REFUSED` for a fixed token). `Shutterstock` takes an optional PSR-3 logger as its new last argument.

### Fixed

- **A Shutterstock licence refused for the plan or the API terms says so.** Shutterstock answers such a licence with a 200 whose item (or `errors` list) says "Terms of Service must be accepted" or that the subscription isn't valid for the media; that is now `LicenceRefused` with `Shutterstock::LICENCE_NOT_COVERED`: "Shutterstock refused this licence. Your plan may not cover this image (free API plans can only license the free collection), or your account must accept Shutterstock's API terms." An item's `error` given as an object, and an `errors` list beside an item without an error, are read too. Nothing was bought.

## 1.4.0 - 2026-10-03

### Added

- **A fixed Shutterstock access token, instead of Connect account.** `Libraries\Paid\Shutterstock` takes an optional `token` (`#[SensitiveParameter] ?string $token = null`, the last argument): the token the account owner gets with "Generate token" on their app's page, with the scopes `licenses.create`, `licenses.view`, `purchases.view` and `user.view`. While it is set, `connected()` is true, every account call (`account()`, `quotes()`, `license()`, `download()`, `findLicences()`) uses it, `refresh()` hands it back without asking, a token kept by Connect account (`LibraryTokens`) never overrides it, and `authorizationUrl()`, `connect()` and `disconnect()` throw `NotConnected` (`Shutterstock::TOKEN_IN_SETTINGS`). A 401 or 403 with it is `NotConnected` saying the token is invalid or lacks those scopes (`Shutterstock::TOKEN_REFUSED`). It is masked in dumps. New `usesToken()`. See docs/images.md and docs/connecting-accounts.md.
- `tools/smoke/shutterstock.php` also makes one `account()` call against the sandbox when `SHUTTERSTOCK_API_TOKEN` is set, printing only the subscription count and downloads left.

## 1.3.0 - 2026-10-03

"Finish this page", the core phases (finish-this-page design). Nothing in the 1.x API changes; the addons' calls work as they are.

### Added

- **Markers for what an editor must finish (`Gaps\Markers`), phase 1.** A fact to add is `[[ask: adult ticket price]]`, written strictly by `Markers::ask()` and found leniently (`[[ ASK:x ]]` counts); a link to choose is a real link to `#gw-link:<hint>` (`Markers::link()`), or `https://example.com/#gw-link:<hint>` for link fields that validate an address (`Markers::linkUrl()`). Also `asks()`, `links()`, `leftovers()` (a vocabulary placeholder such as `[[item]]` left in text), `placeholderText()` (`TBC`, `[insert date]`, `lorem ipsum`…), `isLinkSentinel()`, `linkHint()`, `normalise()` (near misses such as `[ask: x]` and `[[Ask - x]]` put right), `excerpt()` and `patterns()`, kept for the front end in `resources/gaps/patterns.json` (a test fails when it is out of date, and checks the patterns match the same in JavaScript).
- **`Gaps\FieldPath` and `Gaps\BlockRef`:** where a value sits in an entry, with blocks named by ID where they have one (`page_builder/#a1b2/intro`), so a reorder doesn't move a gap; `dotted()` gives the form's `page_builder.1.intro`.
- **`Layout\LinkPlaceholders`**, implemented by `StatamicLinks`, `CraftLinks` and `NoLinks`: `placeholderFor(Field, $siblings, $hint)` marks a link field as still to choose with the sentinel (Statamic `#gw-link:<hint>`; Craft's Link field and Hyper `https://example.com/#gw-link:<hint>`; Filament none), and `supportsLinks(Field)` says whether a text field can hold a link mark. A separate interface, so a `LinkDialect` written outside core keeps working.
- **`LayoutOptions::$linkSentinels`** (and `withLinkSentinels()`): the house style marks a link it can't settle with the sentinel, with the field's label as the hint, and names it "(link still to choose)". **Off by default in 1.x**: example.com stays, as the addons' tests and the golden layouts expect, and the gap detectors find both.
- **`BuiltEntry::$asks` and `BuiltEntry::$toFill`**: a fact the writer marked in a field that can't hold text (a number, a choice, a toggle, a date) is listed with its path, label and hint, and a note says so ("Still to add by hand: Price (adult ticket price)."); the references still to choose are listed with their paths too. The "Still to choose by hand" note is unchanged.

- **`tests/Contracts/MarkerRoundTripContract`** (with `MarkerRoundTripContractTest`), phase 2: markdown holding a fact to add and a link to choose, through an addon's real apply path and back with its dialect, keeps both markers, in rich text (headings, lists and emphasis too), in a markdown field, and in plain text (where the link is an ask). Core runs it through `EntryBuilder` and the house style with the HTML dialect and with the Bard dialect.

- **The `Gaps` module, phase 3: what is unfinished in an entry, found for nothing.** `GapFinder::standard()->find(GapContext)` gives a `GapReport` (`all()`, `blocking()`, `counted()`, `count()` for the pill, `suggestions()`, `ofKind()`, `find($id)`, `toArray()` for the front end) of `Gap`s in form order, each with its `GapKind`, `Severity` (`Blocks`, `Required`, `Suggestion`), `FieldPath`, label ("Hero: Intro"), hint, excerpt, occurrence, `Fix`es (`FixAction`, `FixCost`: free, model, licence; primary first) and a stable `id` (kind, path with block IDs, hint, occurrence). A field that is only empty isn't reported again where a more specific gap names it.
  - **Detectors** (`Gaps\Detectors`), all deterministic: `AskMarkers`, `AskValues` (a fact the draft asked for in a number or date field, while it is empty), `LinkMarkers` (inline `#gw-link:`, link fields on the sentinel, and through 1.x the legacy `https://example.com` + "Link to choose"), `EmptyLinks`, `BrokenLinks`, `PlaceholderImages`, `EmptyImages`, `UnlicensedStock` (one `StockImages::unlicensedAmong()` call for every asset in the entry), `RequiredFields`, `ExpectedFields` (suggestions, from the pattern's fill rates), `LeftoverTokens` and `PlaceholderText`. A detector that would ask a model (`Detector::usesModel()`) runs only with `find(..., withModel: true)`; none ships yet.
  - **`GapContext`**: the schema, the entry's current values (`EntryData`), the dialects, and the optional ports, ledger, pattern and session list.
  - **Ports each addon implements:** `PlaceholderAssets::isPlaceholder(AssetRef, Field)`, `AssetRefs::in($value, Field)` (field assets and images inline in rich text) and `LinkTargets` (`exists($target, Field)`, `search($hint, $limit)` returning `LinkTarget`s, for "Link to Contact"; no model). In-memory versions for tests and demos: `Gaps\Testing\MemoryAssets` and `MemoryLinkTargets`. Contracts: **`tests/Contracts/PlaceholderAssetsContract`** (the sink's own placeholder is recognised; an ordinary image titled like it isn't) and **`AssetRefsContract`** (an image in a field and one inline in rich text are both found).
  - **`SessionGaps`**, the session's gap list: `fromDraft(BuiltEntry, $housePlaces, $placeholders)` when a draft is applied, `fromSession()`, `enrich(Gap)` (adds `meta.reason` and `meta.fromDraft`), `expects()` and `askValues()`. Only a help to the messages: the content is the source of truth.
  - **Messages are keys, never sentences:** `Gaps\Message` (`key`, `params`, `english()`), with core's English source strings in `resources/lang/en/gaps.php` (guide messages, speech labels, fix labels, the guide's own words) for each addon to copy into its own format.
- **One publish guard, phase 4: `Gaps\PublishReadiness`** (`standard(OnPublish)`, `check(GapContext): Readiness`). One finder run covers every gap that blocks, unlicensed stock previews included (through `UnlicensedStock`), so stock phase 7 uses it rather than a guard of its own. `Readiness`: `problems()`, `ready()`, `blocked()`, `warns()`, `report()`, `message(?translate)` (one message for the page: "3 things to finish before this page goes live: …"), `byField(?translate, $topLevel)` (field errors by the form's dotted path, or by top-level handle for Craft's `addError()`), `messages()` and `toArray()`. Required fields are left to the CMS's own validation.
- **`Gaps\OnPublish`** (`Block`, the default, or `Warn`) and `OnPublish::fromConfig($value, $legacy)`: the setting is `ghostwriter.publish.on_unfinished` (Craft `onUnfinishedPublish`), with the stock design's `ghostwriter.stock.on_publish` read when it isn't set; anything but `warn` blocks.
- **`tests/Contracts/PublishGuardContract`** (with `PublishGuardContractTest` and `GuardOutcome`), for each addon's real save hooks: publishing with a fact to add or a link to choose is refused with the message on the field; drafts save; finished content publishes; warn mode publishes with one warning; a stock preview and a marker give one refusal (and one warning) naming both; and every other way of going live the addon names (`guardOtherWaysLive()`) is refused. Core runs it against a pretend CMS using `PublishReadiness` alone.
- **Fixes that write, phase 9 (core): the `gap-filler` agent and `Studio::fillGap(GapRequest): Result<string>`.** One small request per click (`Agents`: 1,500 tokens, low effort), never during detection: `GapRequest::summary()` (a blurb from the page's own text), `shorten()` (within a limit), `writeAround(Gap)` (the sentence holding `[[ask: …]]` without the fact, adding nothing) and `alt()` (vision, refused with `Gaps\GapRefused` when the model-input guard won't let a model see the image). **It never fills a fact:** a request can't be made for an `AskValue` gap, or for an `Ask` gap except to write around it (`GapRefused`, before any model is asked); the prompt forbids adding any fact or a vaguer stand-in; and an answer that still holds a marker, or (apart from alt text) has a figure the given text doesn't, isn't used (`UnreadableReply`). Answers are kept within the limit.
- **`Session::$gaps`**, stored under `gaps` only once there is something in it (Filament: a JSON column the addon adds before it fills it), so today's stores are never sent the key.

### Changed

- **The writer marks what it doesn't know instead of writing around it** (`resources/prompts/writer.md`). A new section, "Marking what only your colleague knows": a missing fact becomes `[[ask: what is needed]]` (at most five; more means asking first), a link with no known target points at `#gw-link:<where>` (in markdown fields only; elsewhere it is an ask), a fact meant for a number or date field is marked in that field, the reply lists what was marked, and a revision keeps every ask the colleague hasn't answered. "Never invent" now names prices and durations too, and marks never go in the title. Every addon's writer request changes accordingly (`tests/Fixtures/studio/*/write-*.json`).
- `EntryBuilder` keeps markers in text as they are, puts near misses right (`Markers::normalise()`), and no longer turns a marker in a toggle into `false` or reports one in a choice as "not an option": it lists it in `$asks`.
- `Text\Slug::make()` leaves out `[[ask: …]]`.

## 1.2.0 - 2026-10-02

### Added

- **`Images\Libraries\ConnectsAccount`**, the "Connect account" port for libraries that license through the customer's own signed-in account (`needsOAuth`): `authorizationUrl($state, $redirectUri)`, `connect($code, $redirectUri, $state = '')`, `refresh(TokenSet)`, `connected()` and `disconnect()`. Tokens are kept through the library's `LibraryTokens`. The addons' three control panel routes and their rules are in [docs/connecting-accounts.md](docs/connecting-accounts.md).
- **`Images\Libraries\OAuth\Pkce`**: RFC 7636 S256, with the verifier derived from the addon's single-use `state` and the library's secret, so nothing is kept between the connect and callback requests.
- **`FakeLibrary` is a `ConnectsAccount`**, so the addons can test "Connect account" against it and run it in a browser with the demo library: its authorization address goes straight back to the callback with a code bound to the state and address. It takes an optional `tokens:` (`LibraryTokens`); its tokens last an hour and refresh; `refusesConnect` and `refusesRefresh` script failures. Built with `needsOAuth`, it refuses `account()`, `quotes()` and `license()` with `NotConnected` until connected. Without `needsOAuth` (the default) it behaves as before.
- **`Images\Libraries\Paid\Shutterstock`**, the first paid library: a `LicensableLibrary` and `ConnectsAccount`. Search with the customer's app key and secret (basic auth); licensing with their connected account's OAuth token (expiring tokens, refreshed). Comps are never stored: `preview()` gives Shutterstock's own watermarked address (`previewStorage: none`). Costs are the subscription's allotment ("1 download"), from `/v2/user/subscriptions`. `license()` is sent once with the ledger ID as `metadata.customer_id`, and `findLicences()` reads the licence history so `reconcile()` can match it. Editorial images are off unless switched on, and need an editorial quote (`editorial_acknowledgement`). A `sandbox` option uses `api-sandbox.shutterstock.com`. Keys come from the constructor only. See [docs/images.md](docs/images.md).
- `Downloader::json()` takes `account: true` for calls made with a connected account's token: a refusal is then `NotConnected`.

## 1.1.0 - 2026-10-02

Multi-source stock photos, from the stock images design: phase 1, the library abstraction; phase 2, the ledger domain and the model-input guard; and phase 4, the licensing framework and its fakes. Nothing in the 1.0 API changes; the addons' calls work as they are.

### Added

- **Photo libraries (`Images\Libraries`), phase 1:** `PhotoLibrary`, the interface every library implements (`id()`, `label()`, `capabilities()`, `available()`, `search(SearchQuery)`, `photo($id)`, `fetch($id)`), with `Capabilities` (`free`, `mayRank`, `noModelInput`, `needsOAuth`, `quotes`, `previewKeepDays`, `previewStorage`, `termsCheckedAt`, `editorial`, `creditRequired`, `sandbox`), `SearchQuery`, `Offer`, `Cost` (minor units or units of an allowance, never a float) and `Preview` (a paid library's comp: bytes kept privately until `keepUntil`, or only the provider's address).
- **The four free libraries as classes:** `Libraries\Free\Unsplash`, `Pexels`, `Pixabay` and `Openverse`, moved out of `StockSearch` unchanged.
- **`StockSearch` is a set of libraries.** A new optional `libraries:` argument adds more after the free ones (one with a free library's ID replaces it). New: `libraries()`, `library($id)`, `label($id)`, `mayRank(Photo)`, and `search(..., sources:)` to keep a search to some libraries.
- **`Photo` gains `offer`, `editorial`, `restrictions` and `collection`** (appended to the constructor with defaults), `offer()` and `isFree()`. `toArray()` adds the new keys only when set, so a free photo's array is as before; `fromArray()` reads them.
- **`tests/Images/Libraries/LibraryContract.php`:** what every library adapter must do, run against the four free ones.
- **The stock image ledger (`Domain\Stock`), phase 2:** `StockImage`, one record per stock image put into the site, free or paid: its asset (`AssetRef`), where it is used (`Usage`), its state (`preview`, `licensing`, `licensed`, `failed`, `removed`), the comp kept for it and when each comp was downloaded (one "Refresh preview" allowed), credit, licence type, restrictions, product and term end (for the seat and storage terms), the quote and the licence, who did what (`Person`) and an append-only `history` of `HistoryEvent`s. `StockImages` holds the rules, each change under the lock `stock:<id>`: a licence is begun once, an unknown outcome is settled by `reconcile()` from the library's own licences (never by buying again; failed only after 10 minutes), usages follow the records that hold the image (`syncUsages()`), and the publish guard asks `unlicensedIn()` or `unlicensedAmong()`.
- **`StockImageStore`**, with `StockImageQuery` and `StockImagePage`. No delete. `save()` goes through `StockImage::over()`, which refuses a shorter history or a state moving back, and merges two saves' histories. `tests/Contracts/StockImageStoreContract.php` and `Domain\Testing\InMemoryStockImageStore`.
- **`Images\Libraries\Quote` and `Licence`:** a priced licence option, and a licence bought (order ID, cost as charged or estimated, credit line, product type, term end, the idempotency key, and the provider's answer with signed addresses and tokens taken out).
- **`Domain\Stock\ModelInputGuard`:** no Getty or iStock image the site holds goes to a model. It refuses an asset whose ledger record allows no model input (or is Getty's or iStock's), a `GettyImages-*` or `iStock-*` file, and an image whose embedded IPTC or XMP credit, source or copyright names Getty Images or iStock. `PhotoRanker`, `PhotoFinder` and `Studio` take one (an optional last argument); without one, they still check each image's bytes. `Images\ReferenceImage` carries a reference's asset and file name; `Studio\ImagerySample` gains optional `asset` and `filename`.

- **Licensing, phase 4:** `Libraries\PreviewableLibrary` (`preview()`) and `LicensableLibrary` (`account()`, `quotes()`, `license()`, `download()`, `findLicences()`), with `Account`. The errors, in `Images\Exceptions`: `NotConnected`, `QuoteChanged` (with the new quote), `InsufficientBalance`, `LicenceRefused` and `LicensingUncertain` (it may have charged: never retry, reconcile).
- **`StockImages::license()`**, the whole "License & replace": the ledger record goes to `licensing` first, the library is asked once, a plain refusal fails the record, an unknown outcome leaves it for `reconcile()`, the licence is recorded before the file is fetched, and the addon's new `Domain\Stock\AssetReplacer` port (with `ReplaceMeta`: credit, licence type, restrictions, the ledger ID; never title or alt) puts the file in place byte for byte. `replaceAgain()` finishes a licence whose file couldn't be put in place, without buying again.
- **`Downloader::post()`**, for token and licence calls: sent exactly once, never retried or redirected, with `purchase: true` mapping anything that leaves the outcome unknown to `LicensingUncertain`, and messages that name the host at most.
- **`Libraries\Ports\LibraryTokens`** and **`Libraries\OAuth\TokenSet`** (masked in dumps), **`Libraries\StandIn::jpeg()`** (the public-safe stand-in at the photo's aspect ratio), and the fakes: **`Libraries\Testing\FakeLibrary`** (scripted photos, quotes and licence outcomes, GD-drawn comps, every call recorded; also the addons' demo library), `InMemoryLibraryTokens` and `Domain\Testing\MemoryAssetReplacer`.

### Changed

- **A model never sees a photo whose library doesn't allow it** (`Capabilities::$mayRank`). `PhotoRanker` sends only those photos' thumbnails and descriptions to the `photo-picker` agent; the rest follow the judged ones in their library's order, unjudged and never picked. Every free library allows it, so nothing changes for them yet. Unsplash stays judged by decision; see the stock images design.
- **Reference images and imagery samples go through the model-input guard.** One whose embedded credit or copyright names Getty Images or iStock is left out, logged at debug. Others are sent as before.
- `PhotoUnavailable` is no longer `final`, so the licensing errors can extend it.
- The branch alias is `1.x-dev` (it still said `0.5.x-dev`).

## 1.0.0 - 2026-10-02

The first stable release. The extraction from the Statamic, Filament and Craft addons is complete: text handling and prompts, the provider layer, photo search, `Studio`, the schema model and layout algorithms, and the domain model with its stores and rules all live here. All three addons run on it. From here the public API is stable within 1.x, and the addons require `^1.0`.

No code changes since 0.5.2.

## 0.5.2 - 2026-10-02

### Fixed
- Work was taken as stopped after (timeout + 120) × 2 seconds (840 with the default 300), before the addons' job limit of timeout × 3 + 60 (960). A turn still retrying could be shown as failed and a second run started on the same conversation. `DomainOptions::staleAfter()` is now the job limit plus 120 seconds (1,080 with the default).

## 0.5.1 - 2026-10-02

### Fixed
- `Plan::putBack()` refused started pieces, which removed **Back to ideas** (E5) from started-but-unfinished pieces. It now also takes a started piece when the host says it isn't finished (`putBack($id, fn (Idea $idea) => …)`), and still refuses finished pieces and open ideas. Without the callable a started piece is refused, as before.

## 0.5.0 - 2026-10-02

The domain model, its rules and the store contracts, unified from the three addons: Phase 4, stage 1 of the core extraction. The addons switch over in stage 2. See [docs/domain.md](docs/domain.md) for how an addon wires it, and [docs/domain-unification.md](docs/domain-unification.md) for what differed and what core does.

### Added

- **Sessions (`Domain\Sessions`):** `Session` (the brief, conversation, draft, status, usage, examples, images, the record it is for, who started, touched and ran it, `startedWorkingAt`), with `claim()`, `isStale()`, `recoverIfStale()`, `answer()`, `markApplied()`, `canRetry()`, `waitingOn()` and `title()`. `SessionAccess`: shared or private conversations (E7), deleted by the starter or a manager (Q1). `SessionGuard`: every change under a per-session `Lock`, one run at a time with `Busy` saying whose request runs, retries, hand edits refused while a run works (F3), and changes after slow work. `Progress` and `Record`: where a piece has got to, and finished only once its record is saved (E6). `SessionImages`: the image fields' state machine, and a turn's image changes merged around choices made meanwhile (F2).
- **Content plan (`Domain\Planning`):** `Idea`, `PlanState` and `Plan`: suggestions wait for review and a new batch joins them (E3), no title twice, keep or drop, put back only dismissed ideas (E8), an idea whose piece was deleted is open again, only open or dismissed ideas cleared.
- **Kinds (`Domain\Kinds`):** `ContentType` (the "Something new" brief word for word per addon, `modelledOn()`, `forSession()`, `missing()`, `handleFor()`, `toStudio()`), `KindSuggestions` (when a group is due a look, Q3) and `Analysis`.
- **Guides (`Domain\Guides`):** `Guide` and `GuideState`. **Image requests (`Domain\Images`):** `ImageRequest`, `ImageRequests` and `StoredFile`: owner only, cleared after a day. **Queue (`Domain\Queue`):** `Waiting`, the notice for work no worker has picked up.
- **Stale work (CRA-2):** a session, screen state, study or image request still marked working (timeout + 120) × 2 seconds on has stopped, and shows as failed.
- **`Images\Placeholders`** and the **`Images\AssetSink`** port (D10): one rule for where the striped placeholder goes, the same image byte for byte, and the `placeholder_images` setting's label and note.
- **Store interfaces and ports:** `SessionStore`, `PlanStore`, `KindStore`, `GuideStore`, `ImageRequestStore`, `WaitingStore` and `Lock`. Contract tests for each in `tests/Contracts` (traits, with abstract PHPUnit cases), now included in the package; in-memory implementations in `Domain\Testing`.
- **`Format` and `DomainOptions`**, with `statamic()`, `craft()` and `filament()` presets. Every type reads and writes the addon's current stored shape (`fromArray($stored, $format)`, `toArray()`), unchanged fields exactly, so no data migration is needed. `tests/Fixtures/domain` holds 54 stored records, 37 of them recorded from the three test sites with `tools/record-domain/record.php`.
- **Refusals** as exceptions with a status: `NotFound`, `NotAllowed`, `Conflict`, `Busy`, `LockTimeout`.

### Changed for the addons, once they switch

- All three: a run that stopped without finishing shows as failed and can be tried again (new for Statamic and Filament); a new batch of plan suggestions joins the one waiting instead of replacing it; only a dismissed idea can be put back; clearing takes open or dismissed ideas only.
- Statamic and Craft: sessions gain a `started_working_at` key when a run is claimed. Filament's sessions are timed from `updated_at`, as it has no column.
- Craft: an empty page builder gets a placeholder block only when it holds nothing but images, as in Statamic.
- Filament: a run is claimed under a cache lock rather than a conditional update; a retry needs the last message to be a person's.
- The branch alias is `0.5.x-dev`; addons should require `~0.5.0`.

## 0.4.0 - 2026-10-02

The schema model and the layout algorithms, unified from the three addons' copies: Phase 3, stage 1 of the core extraction. The addons switch over in stage 2. See [docs/layout.md](docs/layout.md) for how an addon wires it, and [docs/layout-unification.md](docs/layout-unification.md) for what differed and what core does.

### Added

- **`Schema\Field`, `Set`, `Schema` and `Kind`:** what a kind of entry is made of, with the union of the three addons' kinds (`text`, `longtext`, `richtext`, `choice`, `choices`, `toggle`, `number`, `list`, `blocks`, `rows`, `group`, `reference`), page builders' sets, an adapter's `engine` tag and its own `meta`. `Schema::fromSpecs()` reads the arrays the addons' `SchemaReader`s return today, and `toSpecs()` gives them back.
- **`Schema\EntryData`:** an existing entry's content in one shape whatever the CMS (Craft's `EntryData` shape), with its ID, title and parent.
- **`Layout\SchemaDescriber`, `PatternFinder`, `KindFinder`, `HouseStyle` and `EntryBuilder`**, working on a `Schema` and `EntryData`, with results `Pattern`, `HouseRules`, `HouseResult` (with the "Still to set by hand" note), `FoundKind` and `BuiltEntry`. Each `toArray()` is the array the addons' classes returned. `PatternFinder::choose()` keeps the shared rule for which entries to learn from. `Layouts` builds all five with one set of options and dialects.
- **Dialects:** `RichTextDialect`, with `HtmlDialect` for HTML (Craft's CKEditor and Redactor, Filament's RichEditor and MarkdownEditor); Statamic's Bard dialect lives in its adapter, and a reference copy is in core's tests. `LinkDialect`, with `StatamicLinks`, `CraftLinks` (the adapter passes its Hyper and Link field classes) and `NoLinks` (Filament).
- **`LayoutOptions`**, with `statamic()`, `craft()` and `filament()` presets for what the three did differently: "collection" or "section", "page" or "record", Statamic's bookkeeping keys, whether a block's rich text is copied by position, which unsettled references are named, block and row IDs in the data, the unknown-block wording, and how the kind finder picks its builder and names its kinds.
- **Studio:** `Layout::fromSchema($schema, $pattern, $describer)` describes a structured schema with core's `SchemaDescriber`. `Layout::fromPattern()` with an addon's own text still works, and sends the same requests.
- **Parity tooling:** `Layout\Testing\LayoutLog` and `bin/compare-layouts` (installed as `vendor/bin/compare-layouts`) compare what the layout algorithms gave in two runs of an addon's suite. `tests/Fixtures/layout` holds 495 golden cases (Statamic 225, Filament 95, Craft 175) recorded from each addon's own code, from its test suite, its Northfold test site and made-up inputs, with `tools/record-layouts`; core reproduces every one.

### Changed for the addons, once they switch

- Filament: a field named `label` or `value` is no longer read as part of a Craft link (FIL-9), so a label repeating each record's title isn't copied into a new record as its title.
- Statamic: an empty value shared by most entries is no longer taken for a house default, as in Craft and Filament. A field held back for a link to the new entry itself is skipped in Craft and Filament too, as in Statamic. A copied entry-level default gets fresh set and row IDs.
- The branch alias is `0.4.x-dev`; addons should require `~0.4.0`.

## 0.3.0 - 2026-10-02

The Studio: every model call the addons make for writing, planning and learning a site, unified from the three addons' `Studio` classes. See [docs/studio.md](docs/studio.md) for how an addon wires it, and [docs/studio-unification.md](docs/studio-unification.md) for what differed and what core does.

### Added

- **`Studio\Studio`** with the union of the three addons' jobs: `analyseVoice`, `refineVoice`, `analyseType` (with the one re-ask when the reply can't be read), `suggestKinds`, `suggestIdeas`, `analyseImagery`, `draftBrief`, `photoQuery`, `write`, `brief` and `writerInstructions`, plus `ask()` for one-off calls. Prompts come from the addon's `PromptLibrary`, limits and effort from `Agents`, calls go through `Providers` or any `TextProvider`.
- **Neutral inputs**, no CMS objects: `VoiceSample`, `ContentKind` and `Question`, `Layout` (the fields already described, and the example entries), `TypeSurvey`, `KindSurvey` and `KindSample`, `PlanContext`, `PlanGroup`, `PlanItem` and `PlannedIdea`, `ImagerySample`, `Conversation` and `WriterContext`.
- **Results with usage:** `Result` (`value` and `usage`, counting every call a job made, retries and re-asks included), `SuggestedKind` and `SuggestedIdea` (with `toArray()` in each addon's keys), and `TaggedResponse` for the voice and writing jobs. A reply that can't be read throws `UnreadableReply`, an `InvalidArgumentException` carrying the message the addons threw and the `problem`.
- **`StudioOptions`**, with `statamic()`, `craft()` and `filament()` presets for what the three did differently: example YAML depth, the planner's "draft" or "not published", "blueprint" or "entry type", whether a kind finder reply with no `<kinds>` block means "nothing to add", and the cut-off messages.
- **The cut-off policy (core-ai-design §6.6), once:** a reply that ran out of room is asked for again with twice the room, up to 32000 tokens. Still cut off, a draft or guide (`writer`, `type-analyst`, `voice-analyst`, `voice-editor`) throws `Truncated`; everything else is kept, with a warning.
- **F8:** a reply that can't be read is logged as the problem only ("there was no `<type>` block", "the YAML did not parse at line 3"). The whole reply goes in the log context only with `StudioOptions::$logReplies` on. Prompts, instructions and keys are never logged.
- **Parity tooling:** `Studio\Testing\RequestLog` records a suite's requests as they would be sent, and `bin/compare-requests` (installed as `vendor/bin/compare-requests`) compares two runs. `tests/Fixtures/studio` holds each addon's representative inputs and the exact requests core sends for them.

### Changed for the addons, once they switch

- Craft: a cut-off plan, kind list, brief or imagery guide is kept rather than failing, as in Statamic and Filament.
- Craft and Filament: an empty `<kinds></kinds>` reply means "nothing to add" rather than an error.
- All three: a title repeated within one kind finder or planner reply is kept once; the planner accepts `collection`, `section` or `resource` as the group key; token usage includes the retry with more room and the type analyst's re-ask.
- The branch alias is `0.3.x-dev`; addons should require `~0.3.0`.

## 0.2.0 - 2026-10-02

Photo search, unified from the three addons' copies, with model ranking and the photo libraries' own descriptions. See [docs/images.md](docs/images.md) for how an addon wires it.

### Added

- **`Images\StockSearch`:** Unsplash, Pexels, Pixabay and Openverse over PSR-18, with keys from `Credentials` (`unsplash`, `pexels`, `pixabay`) and Openverse switched by a constructor argument (a bool or a callable). It is the union of the three addons' versions: orientation per shape, nine results per library, a two-word retry for long searches, one library failing without hiding the others, and Openverse results kept only when Openverse's own thumbnail loads (originals are never fetched to check them). `fetch($source, $id)` looks the photo up again by ID, reports Unsplash downloads, and returns a `PhotoFile` (bytes, MIME type, extension and the `Photo`).
- **Hardened downloads:** https only on every hop, with redirects followed by core (at most three, never to localhost, a private or reserved IP address, or a `.local`/`.internal` name) and API keys dropped when a redirect leaves the host. Bodies are read no further than their cap (15 MB for a photo, 2 MB for a thumbnail), and a declared length over the cap is refused before reading. Files must say they are images and be a JPEG, PNG or WebP that PHP can read; the extension comes from the bytes. Failures are `Images\PhotoUnavailable` (an `InvalidArgumentException`, as the addons threw) with a message that never carries a key.
- **`Images\Photo`:** source, ID, thumbnail, credit, credit URL, licence, the library's own `title`, `description` and `tags`, width and height, the full-size address, the search that found it, and `picked` and `reason` when a model judged it. `alt()`, `assetTitle()` and `filenameBase()` turn the library's words into alt text (at most 125 characters), an asset title and a file name, falling back to the search term (decision D4). `toArray()` keeps the keys the addons' UIs already read; `fromArray()` reads them back, older arrays included.
- **`Images\PhotoRanker`:** judges candidates through the `photo-picker` agent. With reference images it matches style and subject, as before; without, it now judges the subject alone against the block's and page's words and each photo's library description, instead of skipping (decision D2). Clear misses are left out. `picked` is set only when the model judged, so "Best match" can no longer land on unranked photos. Requests stay within `Ai\Limits`, and images are made small first (Imagick, then GD).
- **`Images\PhotoFinder`:** the whole flow. Searches are chosen by the `photo-researcher` agent (or typed), run, and judged. When nothing fits, the model's own searches are run and judged once more, with or without references (Statamic's retry, now in all three). Returns `Images\PhotoResults` with `judged`, `withReferences`, `noneFit`, `retried` and the `terms` searched.
- **`Images\PhotoContext`:** the words around a field (title, summary, label, block text, page text, shape, imagery guide).
- **`Text\Slug`:** `make()` for ASCII file names (accents taken off, other scripts transliterated with intl, cut between words) and `clip()` for one-line text cut at a word.
- **`Ai\Ports\DownloadClients`:** an optional extra on `HttpClients`, for clients that hand redirects back instead of following them, and can stream. `GuzzleHttpClients` and `MockHttpClient` implement it; an addon's own `HttpClients` should too.

### Changed

- **The `photo-picker` prompt** handles both modes (with and without references), asks for one line per photo that fits with a reason, and lets the model answer `none: search; search; search`. A plain list of numbers, as a site's older override asks for, is still read. Its golden fixtures are now core's text for all three addons.
- The branch alias is `0.2.x-dev`; addons should require `~0.2.0`.

## 0.1.1 - 2026-10-02

### Changed
- The writer prompt no longer lets the model promise or require anything on the organisation's behalf (conditions, requirements, prices, offers, guarantees, deadlines, policies) that the brief or conversation didn't give it. It leaves them out and says what is missing. Found in testing, where a job advert gained "you'll need to be local".
- The writer may also take facts about the organisation from the existing entries it is shown, which it already did in practice.

## 0.1.0 - 2026-10-01

The first version: the parts the Statamic, Filament and Craft addons had copied by hand, in one framework-free package.

### Added

- **Text:** `Draft`, `LenientYaml`, `TaggedResponse`, `HtmlToMarkdown`, `DraftPreview`, `EntryMerger`, `EntrySimplifier` and `Utf8`. They are unified from the three addons' copies. Where the copies differed, the difference is a constructor option ([docs/text-unification.md](docs/text-unification.md)).
- **Prompts:** all twelve prompts (including `photo-query`, the single-search photo prompt behind Statamic's `Studio::photoQuery`), plus `Vocabulary` (with `statamic()`, `craft()` and `filament()`) and `PromptLibrary`, which supports per-site overrides. Golden tests check that each addon gets its current prompts byte for byte.
- **AI layer,** ported from the Craft addon:
  - Value objects, the `TextProvider` and `ImageProvider` contracts, and the Anthropic, OpenAI and Gemini providers over PSR-18.
  - The `Providers` registry, and the `Credentials`, `HttpClients` and `ProviderSettings` ports. `Providers::withSleeper()` and `withRetryPolicy()` return a configured copy.
  - `Models`, one table of default models: `claude-opus-5-5`, `gpt-6.1-sol`, `gemini-3.8-flash`, `gpt-image-2.5-sunburst` and `gemini-3.1-flash-image`.
  - `Agents`, which sets max tokens and effort per agent.
  - `TextRequest::withMaxTokens(int)` and `withModel(?string)`, which return a copy (to retry a cut-off reply with more room, or on another model).
- **Retries:** 408, 409, 429, 5xx and 529 responses, and connection failures, are retried with backoff and jitter. `retry-after` and `retry-after-ms` are followed, with waits capped at 30 s. A response timeout is not retried.
- **Stop reasons:** `TextResponse::$stopReason` (`end`, `max_tokens`, `refusal`, `safety`, `other`) and `truncated()`. Providers report a cut-off reply and never throw for it.
- **Usage:** `Usage` counts Anthropic's cache-creation tokens and Gemini's thinking tokens.
- **Anthropic fallbacks:**
  - Server-side fallbacks are sent only to models with refusal classifiers, only on Anthropic's own API, and can be turned off.
  - If the API refuses the beta, it is dropped for the rest of the process.
  - A refusal that names a `recommended_model` is retried once on that model.
- **Effort:** sent to OpenAI (`reasoning_effort`) and Gemini (`thinkingLevel`) as well as Anthropic, for the models that take it.
- **Base URLs:** an optional `base_url` per provider, for gateways that speak the same API. It must be `https://`, except for localhost.
- **Request size:** a request with more than 24 images, or more than 20 MB of image data, is refused before it is sent. The limits are public on `Ai\Limits` (`MAX_IMAGES`, `MAX_IMAGE_BYTES`, `fits()`), so addons can size their requests. 24 leaves room for the photo picker's 3 references and 18 thumbnails; 20 MB of raw bytes is about 27 MB once base64-encoded, under Anthropic's 32 MB request limit.
- **Exceptions:** a hierarchy under `ProviderException`, with `retryable()`, `status()` and `provider()`. Keys never appear in messages or logs.
- **Testing:**
  - `Testing\FakeProvider`, with Craft's API plus `assertSent`, `assertNotSent`, `assertImageSent`, `assertNoImageSent`, `assertNothingSent`, `failWith` and `reset(?string $agent)`. Under PHPUnit (or Pest) the asserts go through `PHPUnit\Framework\Assert`, so they count and tests aren't marked risky; without it they throw `AssertionError`.
  - `FakeProvider::withoutKeys()` and `->unconfigured(bool $text = true, bool $image = true)`, which make the faked `Providers` report missing keys: `configured()` false, `image()` and `imageHandle()` null, `keyStatus()` all false, and `text()` throws `NotConfigured`.
  - `Testing\MockHttpClient`, `NetworkError` and `RecordingSleeper`.
- **Boundaries:** `bin/check-boundaries`, which fails if `src/` names a framework or CMS.
- **Dependencies:** `symfony/yaml` `^6.4|^7.0|^8.0`, `psr/http-factory` `^1.1`, and Guzzle `^7.8|^8.0` for `GuzzleHttpClients`. CI also runs `--prefer-lowest`, Guzzle 7, Guzzle 8, and the newest versions without the PHP 8.2 platform pin.

### Deprecated

- `TextResponse::$inputTokens`, `$outputTokens` and `$truncated`. Use `$usage->input`, `$usage->output` and `truncated()`. These properties will be removed in 1.0.
