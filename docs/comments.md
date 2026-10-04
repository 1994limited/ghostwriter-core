# Comments on blocks

Editors comment on the draft where they see it: a block in the preview, a card, some words or the whole page. **Comments are conversation messages**: there is no store of their own. Nothing here is a setting. The design is `page-preview-layouts-design.md` (§9).

1. **Pins not sent yet are the editor's own**, in their panel (the addons keep them in the browser): click a block or select some words in Comment mode, write, edit or delete freely. Nothing reaches the conversation yet.
2. **Apply N comments** sends them as **one message** from the editor, of the `comments` kind, and claims the piece as Send does. The chat shows it as "3 comments", each with its block's label and words.
3. Ghostwriter's turn is the **scoped revision**: one `reviser` call for every comment (up to 12), each allowed to change only the units it is anchored to. Core checks every change with no model, applies what passes, re-arranges the layouts and answers with **one message**: a result per comment.
4. **Put back** and **Resolve** act on a comment's result in that answer.

Everything is in `NineteenNinetyFour\Ghostwriter\Core\Review`. Addons call `Review\Comments`. Only `revise()` calls a model.

## The messages

The editor's message (`role: user`), as `Session::addMessage()` stores it:

```php
['role' => 'user', 'content' => "2 comments on the draft:\n1. On “Visits list”: Shorter.\n2. …", 'by' => '1', 'at' => '…',
 'comments' => ['items' => [Comment::toArray(), …]]]
```

Each item (`Comment`): `id`, `number` (the pin's: one past every comment sent before on the piece, `Comments::nextNumber()`), `scope` (`Scope::toArray()`), `body` (≤ 2000 characters), `by`, and `hashes`: the hash of each unit and extra item it may change when it was sent (`Arrange\Unit::hash()`). `content` is the same list in words, so the writer reads it in later turns like any message.

Ghostwriter's answer (`role: assistant`):

```php
['role' => 'assistant', 'content' => 'Revised 2 blocks from your comments: Visits list, Who it suits. Nothing else changed.', 'at' => '…',
 'comments' => ['answers' => 4, 'results' => [CommentResult::toArray(), …]]]
```

`answers` is the index of the editor's message in `Session::$messages` (messages are only ever added, so indexes don't move). Each result (`CommentResult`): `number`, `id`, `outcome` (`CommentOutcome`: `changed`, `replied`, `refused`, `skipped`, `failed`), `reply` (plain words: what changed, why not, or the answer to a question; escape it when you show it, C6), `changes` (a `Change` per unit: `unit`, `before`, `after`, `version` (the editor's message's index), `filled`, `layout`, `cut`), `rules` (a refusal's), `quote` (a comment on some words moved to the words that took their place), and once someone acts on it, `resolved` and `putBack` (`{by, at}`).

## What the addon calls: `Comments`

```php
$comments = new Comments(SessionGuard $guard, ?Studio $studio, ?SessionLayouts $sessionLayouts, Layouts $layouts = new Layouts, ?LoggerInterface $logger);

// The controller (POST …/sessions/{id}/comments/apply), then start the job:
$session = $comments->apply($sessionId, $viewer, [['scope' => Scope::block(['u4'], 'Visits list', 'w', 'page_builder/2'), 'body' => 'Shorter.'], …]);
// The job:
$outcome = $comments->revise($sessionId, $conversation, $writerContext, $site, $names = []);
$comments->fail($sessionId, 'it took too long');        // a job that stopped: every comment answered Failed, the piece freed

$comments->resolve($sessionId, $viewer, $answerIndex, $number);          // and reopen: resolve(…, false)
$comments->putBack($sessionId, $viewer, $answerIndex, $number, $site);   // the change undone, if its text is still Ghostwriter's
$comments->pins($session, ?$planId);                                     // every sent comment, with its state and blocks
```

- **`apply()`** claims the piece (`SessionGuard::begin()`): one run at a time, so a second Apply, a chat message or a hand edit meanwhile is refused (`Busy`, "Priya is waiting on Ghostwriter"). `Conflict` for no comments, more than 12 (`Comments::PER_APPLY`), or no draft; `NotAllowed` for someone who may not resume the piece.
- **`revise()`**, in the queued job, reads the editor's message still waiting for its answer (`Comments::unanswered()`), builds the `RevisionRequest`, makes **one call** to the `reviser` agent, then checks and applies the reply under the session's lock (`RevisionApplier`). Build `$conversation` and `$writerContext` as for a writer's turn; `$site` is the `LayoutContext` layouts use; `$names` (user id → name) puts "By Priya" in the prompt. A provider error, or a reply cut off even with more room, answers every comment `failed` with the reason, changes nothing, and frees the piece. The tokens go on `Session::$usage`.
- **`resolve()`** is allowed while Ghostwriter works on something else (`SessionGuard::annotate()`); it needs an answer (`NotFound` otherwise).
- **`putBack()`** writes each `before` back through `Text\DraftEditor` after checking the text is still the `after` (`Conflict` if it changed since, or there's nothing to put back), then the layouts follow. Refused while Ghostwriter works, as hand edits are. A layout change, and a change cut at 4 KB, can't be put back.
- Refusals are `Domain\Refused` exceptions with their HTTP status: `NotFound` (404), `NotAllowed` (403), `Conflict` (409), `Busy` (409).

### Scopes: what a comment is about

```php
Scope::block(['u4'], 'Visits list', 'w', 'page_builder/2', ?int $sub);   // a block (or one card of several: $sub)
Scope::text('u5', new TextQuote($exact, $prefix, $suffix), 'Who it suits', 'w', 'page_builder/3');  // some words
Scope::field('title', ['u1'], 'Title');                                    // a top-level field
Scope::page();                                                             // the whole page
```

- The anchor is the draft's **units** (`Arrange\Units`), never the block's position. Take a block's units from the preview's `BlockMap` (`MappedBlock::$units`) or the Blocks view's node path (`Units::inBlock()`). A block a layout fills from extras is anchored to the extra items (`x1.2`).
- A comment on some words is anchored to the one unit they're in, plus an `Anchor\TextQuote` (≤ 300 characters, with ≤ 32 before and after), taken with the shared quote rules (`resources/anchor/quote-cases.json`).
- `label`, `planId` and `blockPath` record where it was made, for the chat ("On Visits list"). They don't anchor it.

### Pins: `pins()`

Every comment sent on the piece, by number, each with `number`, `id`, `message` (the editor's message's index), `answer` (Ghostwriter's, or null), `scope` (with a moved quote), `body`, `by`, `status` and `state`, `outcome`, `reply`, `rules`, `changes` (each with a word `diff`: runs of `['=', 'kept ']`, `['-', 'taken out ']`, `['+', 'put in ']`, `WordDiff`), `resolved`, `putBack`, `canPutBack`, `blocks`, `inLayout` and `detached`.

| `status` (`CommentStatus`) | `state` | When |
|---|---|---|
| `sending` | Revising | Sent; the run is going |
| `changed` | Changed | Its text changed (or its block was laid out anew) |
| `replied` | Replied | Answered without changing anything |
| `refused` | Not applied | A check refused its change; `reply` says why |
| `skipped` | Skipped | Someone changed its text during the run |
| `failed` | Not applied | The run couldn't happen (or stopped) |
| `resolved` | Resolved | Someone resolved it |
| `detached` | Detached | Its units are gone from the draft |

Pins follow their words with no work: `blocks` are the block paths holding the comment's units in a layout (`page_builder/2`, `page_builder/2/cards/0`, `body` for a rich-text or plain field; the chosen layout by default, `where()`), so a comment made on the writer's "Text" block is on three blocks in a layout that splits it, and `inLayout` is false in one that leaves its words out. Between turns, `SessionLayouts::afterEdit()` carries unit ids over (`Arrange\UnitMatcher`): a unit reworded a little keeps its id and its pins; one replaced outright leaves them Detached. A piece with no layouts yet has no `where()`: every pin is `inLayout`.

### What a comment may change

The reviser gets the writer's instructions (voice, rules, gap markers, the fields, the examples; not the extras section) and then `resources/prompts/reviser.md`. The prompt is the current draft, the comments (each with its label, the units it may change, and any quoted words), the text of those units, the extra items in scope and the chosen layout. Each comment may:

- **change its units' text**, keeping house style, the gap markers and the extras rules, as the writer must: a whole unit (`units:`), or exact words in it (`replace:`). A comment on some words may change only the sentences they're in;
- **answer a fact** (decision 4): a fact the editor gives in the comment (or the brief has) may fill an `[[ask: …]]`. The `Change` records it in `filled` (`ask`, `value`, `by`: the comment's author), and the reply ends "Filled in from your comment: “£60”." Label it as the editor's in the before and after;
- **lay its block out anew**, only when it asks to (`layout:`, in the layout planner's form): the block or blocks holding its units are replaced in the chosen layout, if the result passes the layout rules (`PlanValidator`). The writer's own layout is the draft, so the draft is re-arranged; another layout is replaced in `Session::$plans`. Otherwise the text change still applies and the reply says "I kept the layout: …";
- **change or remove an extra item** in its scope (`extras:`); an edited item's source becomes the editor (`SourceKind::Editor`);
- **only reply**, changing nothing (*Replied*): a question, an image, something it would have to invent.

### The checks (`RevisionValidator`, no model)

Each comment's changes are checked on their own, against the draft as it is under the lock. A comment that breaks a rule keeps the draft as it was and is *Refused*, with a plain reason in its result; the others are applied.

| Rule | Refused when | The reason |
|---|---|---|
| `scope` | A unit or extra item outside the comment's scope changes, or `exact` isn't in its unit exactly once (by the shared quote rules, with the comment's quote's context) | "I couldn’t make this change without touching other parts of the page. Try commenting on the whole section." |
| `text-range` | A comment on some words changes text outside the sentences they're in (`ScopedEditCheck`) | "…within the words you picked. Try commenting on the whole block." |
| `markers` | An `[[ask: …]]`, `[[check: …]]` or `#gw-link:` link is lost or added; an ask filled with something neither the comment nor the brief gave | "…without losing or making up a gap left for you to fill, or a link to choose." |
| `link` | A link to another site, an email address or a phone number appears | "That change added a link to another site…" |
| `facts` | A figure, quotation or name none of its sources has (`SourceCheck`): the unit before, the comment, the brief and answers, the draft, a shown entry | "That change needed something I don’t have (£75). Say it in a new comment and apply again." |
| `lost` | The draft's units aren't what they were: a unit emptied, a section's heading taken out (merging it into the one before), a unit split or turned into another kind | "…would have merged, split or removed part of the page…" |
| `shape` | The text doesn't fit where it goes: an image, or a row given a different number of paragraphs than it has fields | "I couldn’t fit that change into this block’s fields." |
| `missing` | The reply had nothing for the comment | "I didn’t get to this comment. Apply again to send it." |
| `size` *(a warning)* | Under 40% or over 160% of the words, and the comment didn't ask for length ("shorter", "expand"…) | Applied; the reply adds a line |
| `layout` *(a warning)* | A new arrangement the layout rules refuse | The text is applied; "I kept the layout: …" |

### Concurrency (§9.5)

- Only one run at a time, under the session's claim; a chat message waits for it, and it waits for one. Resolving goes on meanwhile.
- Each comment records each unit's hash when it is sent. Under the lock, every unit or item a comment's change touches is compared with it; one someone changed since is **skipped and reported** (`skipped`: "Someone changed this block while I was working, so I changed nothing. Apply again to use the new version."). So is a unit an earlier comment in the same run rewrote whole.
- Unit ids are kept by place through a revision (the checks make sure the units are the same), then `SessionLayouts::afterEdit()` re-arranges every layout, with no call.

## Cost

| Moment | Calls |
|---|---|
| Pinning, editing and deleting a pin not sent, resolving, reopening, the pins, switching layout, before and after, Put back | 0 |
| **Apply N comments** | **1** (`reviser`), whatever N is, up to 12. ~4–8k tokens in, ~1–3k out. A reply cut off is asked for once more with twice the room (16000) |
| Checking, applying, re-arranging the layouts | 0 |

`reviser`: 8000 max tokens, effort `medium`, the writing tier, in `StudioOptions::WHOLE`.

## The classes

| Class | What it is |
|---|---|
| `Comments` | What the addons call (above). |
| `Comment` | One comment in the editor's message. `make()`, `asks()`, `toArray()`, `fromArray()`. |
| `CommentResult`, `CommentOutcome` | Ghostwriter's answer to one comment. `canPutBack()`, `withResolved()`, `withPutBack()`. |
| `CommentStatus` | A pin's state, worked out from the conversation, with `label()`. |
| `Change` | What one revision did to one unit or extra item. Each side is capped at 4 KB. |
| `Scope`, `ScopeKind` | What a comment is about. `editableUnits(Units, $extraIds)`. |
| `ReviewRules` | Who may do what (§14): `mayComment()` (resolve, put back), `mayApply()` (as Send). Add the CMS's own checks around it. |
| `RevisionRequest` | One Apply's comments, the units, extras, chosen layout, writer context and conversation; `prompt()`, `editable()`. `Studio::revise()` sends it. |
| `RevisionReply`, `RevisionItem` | The reviser's `<changes>` read into one item per comment: `reply`, `units`, `replace`, `layout`, `extras`. |
| `RevisionValidator`, `Verdict` | The checks above, per comment. |
| `RevisionApplier` | Applies a reply under the lock: conflicts, checks, the draft and units, layouts, the answer message. |
| `ApplyOutcome` | What one Apply did, by number. |
| `WordDiff` | The before and after as runs of words. |
| `Text\DraftEditor` | Writes a unit's new text into the draft's data and dumps the YAML as the addons do. |
