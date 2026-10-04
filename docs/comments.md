# Comments on blocks

Editors comment on the draft where they see it: a block in the preview, a card, some words, a field or the whole page. Comments live on the session, shared by everyone on the piece (E7), and follow their words from layout to layout and from turn to turn. Nothing here is a setting. The design is `page-preview-layouts-design.md` (§9).

**Apply N comments** sends every comment not sent yet (up to 12) to the model in **one** call. Each comment may change only the units it is anchored to; core checks every change with no model and applies what passes, re-arranges the layouts and keeps a before and after on each thread.

Everything is in `NineteenNinetyFour\Ghostwriter\Core\Review`. Addons call `Review\SessionReview`; the classes under it are documented further down. Only Apply calls a model.

## What the addon calls: `SessionReview`

```php
$comments = new SessionReview(SessionGuard $guard, ?Studio $studio, ?SessionLayouts $sessionLayouts, Layouts $layouts = new Layouts, ?LoggerInterface $logger);
```

The Studio (or the addon's `SessionLayouts`) is needed only for Apply and Put it back; comments, replies, resolving and the list need neither.

Every change goes through `SessionGuard::annotate()`: under the session's lock, for anyone who can see the piece (`SessionAccess::canSee`), and allowed while Ghostwriter works on it (§9.5): a comment made during a run waits, *Not sent*, for the next Apply. Each method returns the `Thread` it changed.

```php
$thread = $comments->add($sessionId, $viewer, $scope, $body, ?int $version);
$comments->edit($sessionId, $viewer, $threadId, $body, ?int $version);     // its author, before it is sent
$comments->delete($sessionId, $viewer, $threadId, ?int $version);          // its author, or a manager
$comments->reply($sessionId, $viewer, $threadId, $body, ?int $version);    // the thread goes with the next Apply
$comments->resolve($sessionId, $viewer, $threadId, ?int $version);
$comments->reopen($sessionId, $viewer, $threadId, ?int $version);          // Resolved → Changed or Replied (Not sent if never answered; Detached if its text is gone)
$comments->repin($sessionId, $viewer, $threadId, $scope, ?int $version);   // "Pin to a block" on a Detached thread: Not sent again
```

**`$version`** is the review's version as the panel last saw it (`threads()` and `review()` carry it). A change made from an older copy is refused with `Conflict` ("The comments have changed since you last saw them…"), so the panel fetches them again. Pass null to skip the check. The version goes up with every change, including Ghostwriter's, so the 10-second poll (§9.5) compares it to know when to refetch.

**Refusals** are exceptions with their HTTP status (`Domain\Refused`): `NotFound` (404: no such piece or thread), `NotAllowed` (403: someone else's private piece, someone else's comment to edit or delete) and `Conflict` (409: a thread being revised now can't be replied to, resolved, re-pinned or deleted; an empty comment; a limit reached; a stale version; no draft yet).

### The scope: what a comment is about

```php
Scope::block(['u4'], 'Visits list', 'w', 'page_builder/2', ?int $sub);   // a block (or one card of several: $sub)
Scope::text('u5', new TextQuote($exact, $prefix, $suffix), 'Who it suits', 'w', 'page_builder/3');  // some words
Scope::field('title', ['u1'], 'Title');                                    // a top-level field
Scope::page();                                                             // the whole page
```

- The anchor is the draft's **units** (`Arrange\Units`), never the block's position. Take a block's units from the preview's `BlockMap` (`MappedBlock::$units`) or the Blocks view's node path (`Units::inBlock()`). A block a layout fills from extras is anchored to the extra items (`x1.2`).
- A comment on some words is anchored to the one unit they're in, plus an `Anchor\TextQuote` (the words, ≤ 300 characters, with ≤ 32 before and after). The front end takes it with the shared quote rules (`resources/anchor/quote-cases.json`).
- `label`, `planId` and `blockPath` record where the comment was made, for the thread's heading ("On Visits list"). They don't anchor it.

### The list: `threads()`

```php
$comments->threads(Session $session, ?string $planId = null): list<array>
```

Every thread by number: the stored thread (`Thread::toArray()`: `id`, `number`, `status`, `scope`, `startedBy`, `resolvedAt`, `resolvedBy`, `notes`), plus

| Key | |
|---|---|
| `state` | "Not sent", "Revising", "Changed", "Replied", "Resolved" or "Detached" (`ThreadStatus::label()`) |
| `blocks` | The block paths in that layout (the chosen one by default) holding its units, in the layout's order: `page_builder/2`, `page_builder/2/children/0` for a nested card, `body` for a rich-text or plain field. Pin it on the first; with more than one, the thread says "spans 2 blocks". |
| `inLayout` | False when the layout doesn't use any of its text (an extra this layout leaves out): "Not in this layout. It comes back when you switch to one that uses this text." Always true for the page. |
| `canPutBack` | Its last change can be put back. |

Each note has `id`, `kind` (`comment`, `reply`, `change` or `system`), `by` (the user's id; null for Ghostwriter), `body` (markdown: escape it when you show it, C6) and `at`. `$comments->where($session, ?$planId)` gives the `blocks` alone, by thread id; `$comments->review($session)` the `Review` itself (`version`, `counts()` for the toolbar's amber count of open threads).

### States

| Status | `state` | When |
|---|---|---|
| `open` | Not sent | New, replied to since Ghostwriter answered, sent back by the last run (refused, conflicted, failed), or pinned again |
| `sending` | Revising | In the run going now |
| `changed` | Changed | Ghostwriter changed its text: the last note is a `change` note with a before and after |
| `replied` | Replied | Ghostwriter answered without changing anything |
| `resolved` | Resolved | Anyone resolved it. Reopen goes back to Changed or Replied (Not sent before any answer, Detached if its text is gone) |
| `detached` | Detached | Its units are gone from the draft, or its quoted words from its unit. It shows its quote and offers "Pin to a block" or Resolve. It comes back by itself if the text does. |

### Following the text

- **Between layouts** nothing changes: `threads($session, $planId)` and `where()` place each thread in whichever layout by its units. A comment made on the writer's "Text" block is on three blocks in a layout that splits it.
- **Between turns**: `SessionLayouts::afterEdit()` (and so `afterWriter()`) re-anchors the comments after it carries the unit ids over (`Arrange\UnitMatcher`): a unit reworded a little keeps its id and its comments; a unit replaced outright takes them to *Detached*. A quote found only fuzzily is taken again from the text. Call nothing else.

## Apply

```php
// The controller (POST …/sessions/{id}/review/apply):
$session = $comments->apply($sessionId, $viewer, ?int $version);   // then start the job
// The job:
$outcome = $comments->revise($sessionId, $conversation, $writerContext, $site, $names = []);
```

- **`apply()`** claims the piece for a run, as Send does (`SessionGuard::begin()`): one run at a time, so a second Apply, a chat message or a hand edit while it runs is refused (`Busy`, with whose run it is: "Priya is waiting on Ghostwriter"). Under the same lock it puts up to 12 *Not sent* threads into the run (*Revising*), recording the review's version and each unit's hash, and adds the editor's line to the chat (`messages[]`: role `user`, `review.step` `apply`, the thread numbers and how many wait; show it as a system line, "Daniel applied 2 comments"). `Conflict` when nothing is *Not sent*. Comments made during the run wait for the next one.
- **`revise()`**, in the queued job, builds the `RevisionRequest`, makes **one call** to the `reviser` agent, then checks and applies the reply under the session's lock (`SessionGuard::change()`). Build `$conversation` and `$writerContext` as for a writer's turn (`Layout::fromSchema()`); `$site` is the `LayoutContext` layouts use; `$names` (user id → name) puts "By Priya" in the prompt.
- **The outcome** (`ApplyOutcome`), by thread number: `changed`, `replied`, `refused` (number → the validator's rules), `conflicted`, `laidOut`, `units` (what changed), `summary` (the chat's line), `failed` (why the run couldn't happen) and `usage`.
- **After it**, each thread in the run is *Changed* (a `change` note: the reply, with a `Change` per unit), *Replied* (a `reply` note), or back to *Not sent* with a `system` note saying why. The chat gets one assistant message (`review.step` `revised`): "Revised 2 blocks from your comments: Visits list, Who it suits. Nothing else changed." The tokens go on `Session::$usage`, and the piece is idle again. Re-render the preview: `draftVersion` isn't kept; compare the review's `version` and the draft.
- **If the call fails** (a provider error, or a reply cut off even with more room: `reviser` is in `StudioOptions::WHOLE`), every thread goes back to *Not sent* with a line saying so, the chat says so, and the piece is idle. Nothing in the draft changes.

### What a comment may change

The reviser gets the writer's instructions (voice, rules, gap markers, the fields, the examples; not the extras section) and then `resources/prompts/reviser.md`. The prompt is the current draft, the comments (each with its label, the units it may change, any quoted words, and an earlier answer when it was replied to since), the text of those units, the extra items in scope and the chosen layout. Each comment may:

- **change its units' text**, keeping house style, the gap markers and the extras rules, as the writer must: a whole unit (`units:`), or exact words in it (`replace:`). A comment on some words may change only the sentences they're in;
- **answer a fact** (decision 4): a fact the editor gives in the comment (or the brief has) may fill an `[[ask: …]]`. The `Change` records it in `filled` (`ask`, `value`, `by`: the comment's author), and the reply ends "Filled in from your comment: “£60”." Label it as the editor's in the before and after;
- **lay its block out anew**, only when it asks to (`layout:`, in the layout planner's form): the block or blocks holding its units are replaced in the chosen layout, if the result passes the layout rules (`PlanValidator`). The writer's own layout is the draft, so the draft is re-arranged; another layout is replaced in `Session::$plans`. Otherwise the text change still applies and the reply says "I kept the layout: …";
- **change or remove an extra item** in its scope (`extras:`); an edited item's source becomes the editor (`SourceKind::Editor`);
- **only reply**, changing nothing (*Replied*): a question, an image, something it would have to invent.

### The checks (`RevisionValidator`, no model)

Each comment's changes are checked on their own, against the draft as it is under the lock. A comment that breaks a rule keeps the draft as it was and goes back to *Not sent*, with a plain line in its thread; the others are applied.

| Rule | Refused when | The line in the thread |
|---|---|---|
| `scope` | A unit or extra item outside the comment's scope changes, or `exact` isn't in its unit exactly once (by the shared quote rules, with the comment's quote's context) | "I couldn’t make this change without touching other parts of the page. Try commenting on the whole section." |
| `text-range` | A comment on some words changes text outside the sentences they're in (`ScopedEditCheck`) | "…within the words you picked. Try commenting on the whole block." |
| `markers` | An `[[ask: …]]`, `[[check: …]]` or `#gw-link:` link is lost or added; an ask filled with something neither the comment nor the brief gave | "…without losing or making up a gap left for you to fill, or a link to choose." |
| `link` | A link to another site, an email address or a phone number appears | "That change added a link to another site…" |
| `facts` | A figure, quotation or name none of its sources has (`SourceCheck`): the unit before, the comment and its replies, the brief and answers, the draft, a shown entry | "That change needed something I don’t have (£75). Tell me in a reply and apply again." |
| `lost` | The draft's units aren't what they were: a unit emptied, a section's heading taken out (merging it into the one before), a unit split or turned into another kind | "…would have merged, split or removed part of the page…" |
| `shape` | The text doesn't fit where it goes: an image, or a row given a different number of paragraphs than it has fields | "I couldn’t fit that change into this block’s fields." |
| `missing` | The reply had nothing for the comment | "I didn’t get to this comment. Apply again to send it." |
| `size` *(a warning)* | Under 40% or over 160% of the words, and the comment didn't ask for length ("shorter", "expand"…) | Applied; the reply adds a line |
| `layout` *(a warning)* | A new arrangement the layout rules refuse | The text is applied; "I kept the layout: …" |

### Concurrency (§9.5)

- Only one Apply runs at a time, under the session's claim; comments, replies, resolving and reopening go on meanwhile (`annotate()`).
- Each thread records each unit's hash when it joins the run (`Thread::$hashes`, `Arrange\Unit::hash()`; extra items too). Under the lock, every unit or item a comment's change touches is compared with it; one someone changed during the run is **skipped and reported**: the thread goes back to *Not sent* with "Someone changed this block while I was working, so I changed nothing. Apply again to use the new version." (`ApplyOutcome::$conflicted`). So is a unit an earlier comment in the same run rewrote whole.
- Unit ids are kept by place through a revision (the checks make sure the units are the same), then `SessionLayouts::afterEdit()` re-arranges every layout and re-anchors the comments, with no call. A comment on some words moves its quote to the sentence that took their place.

## Before and after, Put it back, Show before

```php
$comments->changes($session, $threadId);                       // every change made for it, oldest first
$comments->putBack($sessionId, $viewer, $threadId, $site);      // the last change undone
$comments->beforeData($session, $threadId, $site);             // the draft data with it undone, for "Show before" (nothing saved)
```

- **`changes()`** gives, for each change: `unit`, `before`, `after`, `version`, `filled`, `layout` (a new arrangement, with no text), `canPutBack`, and `diff`: a word diff (`WordDiff`), runs of `['=', 'kept ']`, `['-', 'taken out ']`, `['+', 'put in ']`.
- **`putBack()`** writes each `before` back through `Text\DraftEditor` after checking the text is still the `after` (`Conflict` if it changed since). It adds "Put back." by the viewer and keeps the thread's state. Refused while Ghostwriter works, as hand edits are. A layout change, and a change cut at 4 KB, can't be put back.
- **`beforeData()`**: render it through the preview, read-only, with a "Before" badge.

## Cost

| Moment | Calls |
|---|---|
| Add, edit, delete, reply, resolve, reopen, pin again, the list, switching layout, before and after, Put it back, Show before | 0 |
| **Apply N comments** | **1** (`reviser`), whatever N is, up to 12; replies to every comment come from it. ~4–8k tokens in, ~1–3k out. A reply cut off is asked for once more with twice the room (16000), as for every agent |
| Checking, applying, re-arranging the layouts | 0 |

`reviser`: 8000 max tokens, effort `medium`, the writing tier, in `StudioOptions::WHOLE`.

## Limits

100 threads per piece, 30 notes per thread, 2000 characters per note (`Review::MAX_THREADS`, `MAX_NOTES`, `Note::MAX_BODY`), and 12 threads per Apply (`Review::PER_APPLY`; the rest wait for the next).

## The classes

| Class | What it is |
|---|---|
| `Review` | `Session::$review`: `threads`, `next` (the next pin number, never reused) and `version`. `all()`, `get()`, `find()`, `open()`, `sending()`, `counts()`; `add()`, `edit()`, `reply()`, `resolve()`, `reopen()`, `repin()`, `remove()`, `reanchor(Units, ?$extraIds)`; for a run, `send($hashes)`, `answer()`, `sendBack()`, `remark()`. `toArray()`, `fromArray()`. |
| `Thread` | One comment and its notes: `id`, `number`, `scope`, `status`, `notes`, `startedBy`, `resolvedAt`, `resolvedBy`, `sentAtVersion`, `hashes` (unit id → hash when it joined a run). `comment()`, `asks()` (the comment and the replies since Ghostwriter last answered), `changes()`, `lastAnswer()`. |
| `Note`, `NoteKind` | One message: a `comment`, Ghostwriter's `reply` or `change` (with its `Change`s), or a `system` line. |
| `Change` | What one revision did to one unit or extra item: `before`, `after`, `version`, `filled` (asks filled from the comment), `layout`. Each side is capped at 4 KB. |
| `Scope`, `ScopeKind` | What a comment is about (above). `editableUnits(Units, $extraIds)`: what a revision for it may change. |
| `ThreadStatus` | The states (above), with `label()`. |
| `ReviewRules` | Who may do what (§14): `mayComment()`, `mayEdit()`, `mayDelete()`, `mayApply()`. Add the CMS's own checks around it. |
| `RevisionRequest` | One Apply's threads, the units, extras, chosen layout, writer context and conversation; `prompt()`, `editable()`. `Studio::revise()` sends it. |
| `RevisionReply`, `RevisionItem` | The reviser's `<changes>` read into one item per comment: `reply`, `units`, `replace`, `layout`, `extras`. |
| `RevisionValidator` | The checks above: `check()` gives a `Verdict` (`rules`, `units`, `extras`, `filled`, `layout`, `warnings`, `unsourced`, `data`); `layout()` checks a new arrangement. |
| `RevisionApplier` | Applies a reply to the session under its lock: conflicts, checks, the draft and units, layouts, answers, the chat line. |
| `ApplyOutcome` | What one Apply did, by thread number. |
| `WordDiff` | The before and after as runs of words. |
| `Text\DraftEditor` | Writes a unit's new text into the draft's data (a rich-text section, a text, a list, a row) and dumps the YAML as the addons do. |
| `Studio::revise()`, `reviserInstructions()` | The call, and its instructions. |

## Checking parity

`bin/compare-requests` on core's own suite, before and after Apply was added (`GHOSTWRITER_RECORD_REQUESTS=… vendor/bin/phpunit`, `StudioTestCase` records), finds only the new tests' `reviser` requests: the writer's and the layout planner's requests are byte for byte the same.

## Storing it

`Session::$review` is stored like `units`: only once there is something in it, under `review` (Filament: a JSON column the addon adds). It round-trips through every format.
