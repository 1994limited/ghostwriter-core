# Comments on blocks

Editors comment on the draft where they see it: a block in the preview, a card, some words, a field or the whole page. Comments live on the session, shared by everyone on the piece (E7), and follow their words from layout to layout and from turn to turn. Nothing here is a setting. The design is `page-preview-layouts-design.md` (§9).

Everything is in `NineteenNinetyFour\Ghostwriter\Core\Review`. Addons call `Review\SessionReview`; the classes under it are documented further down. Nothing in this page calls a model.

## What the addon calls: `SessionReview`

```php
$comments = new SessionReview(SessionGuard $guard);
```

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

### Limits

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

## Storing it

`Session::$review` is stored like `units`: only once there is something in it, under `review` (Filament: a JSON column the addon adds). It round-trips through every format.
