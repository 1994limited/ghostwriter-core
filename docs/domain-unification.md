# Domain unification

How core's domain types, rules, stores and image placeholders (0.5.0) were unified from the three addons, as of Statamic `0d8fa34`, Filament `ce06b1d` and Craft `9a2a569`. Each row gives the rule, what differed, what core does, and the per-addon option where the difference is real.

"Decided" rows follow a decision recorded in the UX parity audit (E3, E6, E7, E8, Q1, Q3, CRA-2, D10). "Accidental" means one copy had moved on and the others hadn't; core takes the newer behaviour for all three. "CMS" means the stored shape or the CMS really differs; core keeps both, as a `Format` or a `DomainOptions` setting.

Stored data needs no migration in any addon: every record read with `fromArray($stored, $format)` is written back by `toArray()` exactly as it was, and changed fields are written in the addon's own shape. `tests/Fixtures/domain` holds 54 stored records (37 recorded from the three test sites, 17 synthetic for shapes they don't hold yet), and `RoundTripTest` checks each both ways.

## Sessions

| Rule | What differed | Kind | What core does | Statamic | Craft | Filament |
|---|---|---|---|---|---|---|
| Who may see and carry on a piece (E7) | Statamic: everyone when shared; unshared, the starter, anyone for a piece with no starter on record, and super users. Craft: everyone, or the starter. Filament: everyone in the tenant, or the starter (a query scope). | CMS | `SessionAccess::canSee()` / `canResume()` / `visible()`. | `unownedIsAnyones`, `adminSeesAll` | — | — (the workspace scope stays in the store) |
| Who may delete a piece (Q1) | Shared: the starter or a manager in all three (Statamic also the unowned and super user cases). Unshared: whoever may see it. | Decided | `SessionAccess::canDelete()`; `Viewer::$manager` is the addon's settings permission. | as above | — | — |
| One run at a time (E7) | Statamic and Craft read the session under a lock and refused while working; Filament claimed with one conditional `UPDATE`. | Accidental | `Session::claim()` inside `SessionGuard`, under the `Lock` port (`session:<id>`). | file lock | Craft mutex | Laravel cache lock (replaces `claimRun()`) |
| Whose request is running | Statamic and Filament: "Pat is waiting on Ghostwriter. Try again when it has answered." Craft: "Pat is waiting on Ghostwriter." | CMS (wording) | `Busy::$waitingOn`, `Busy::messageFor($nameOf)`. | default | `waitingOnOther` | default (its translation can stay) |
| Stale "working" (CRA-2) | Craft showed a session, a guide or plan state, a study and an image request still working after (timeout + 120) × 2 seconds as failed (core uses the job limit, timeout × 3 + 60, plus 120 seconds since 0.5.2, so a run that is still retrying is never taken as stopped) ("This stopped before it finished…"), timed from the last save. Statamic and Filament had nothing: a killed job left a piece working for ever. | Decided | All three, through `isStale()` / `recoverIfStale()` on `Session`, the work states and `ImageRequest`. A session's run is timed from `started_working_at`, a new optional key written by `claim()`, or the last save where it's missing. A stale run can be claimed again. | adopts it | as before | adopts it; no column, so timed from `updated_at`, which a claim sets |
| Hand edits while a run works (F3) | Statamic and Craft refused them under the lock; Filament refused them without a lock. | Accidental | `SessionGuard::edit()`: refused while working, under the lock. | — | — | — |
| Image choices while a run works (F2) | Statamic let them through and the turn merged its own image changes around them; Craft laid the turn's reply over the session as it stood. | Accidental | `SessionGuard::change()` for the choice; the turn's job saves through `change()` and `SessionImages::mergeTurn()`. | — | — | — |
| Retry | Statamic and Craft: only after a failure whose last message is the person's; Filament: only after a failure. | Accidental | `SessionGuard::retry()`: Statamic's and Craft's rule. "There is nothing to try again." otherwise. | — | — | — |
| A turn's answer | The same in all three: the draft kept even when it doesn't parse (with the problem added to the reply), `asks` for a reply ending on a question, what changed in `draft`. Statamic and Craft also keep the previous word count (`was`). | CMS (shape) | `Session::answer()`; `was` by format. | `was` | `was` | no `was` |
| A piece's title | Statamic: the draft's title, else the first answer whole. Craft and Filament: else the first answer's first line, cut at 80. | CMS | `Session::title()`, by format. | — | — | — |
| Finished (E6) | All three: a new piece is finished once its record is saved (Craft: not just an unpublished draft). Editing an existing record: Statamic once the record is saved after the changes were put in; Craft and Filament once they are put in. Filament also counted a saved record as finished while a new run worked on it. | CMS | `Progress::of($session, Record, $options)`, with `Record` saying what the addon knows of the entry. | default | `editFinishedOnApply` | `editFinishedOnApply`, `recordFirst` |
| Stages | Statamic and Craft: `failed`, `working`, `editing`, `changed`, `published`, `saved`, `in_form`, `draft`, `interview`. Filament's plan: `writing`, `ready`, `asking` (also when the last reply asks), and the rest the same. | CMS (names) | `Progress::$stage`. | — | — | `stageNames`, `questionsMeanAsking` |
| IDs | Upper-case ULIDs (Statamic), 26 hex digits (Craft), lower-case ULIDs beside a numeric row key (Filament). | CMS | `Format::newId()`, `isSessionId()`; Filament's row key is `Session::$key`. | — | — | — |

## Content plan

| Rule | What differed | Kind | What core does | Per addon |
|---|---|---|---|---|
| Suggestions wait for review (E3) | After wave 2, all three kept a batch until it was kept or dropped, but a new run replaced a batch still waiting. | Decided | `Plan::receive()`: a new batch joins the one waiting. `keep()` and `drop()` are the only ways to end it. | — |
| No title twice | The Studio (0.3.0) drops titles already on the plan from the planner's reply. Nothing checked the batch waiting. | Accidental | `receive()` also drops titles already waiting, and checks the plan as it is when the job finishes (trimmed, any case). | — |
| Keeping suggestions | The same in all three: the chosen open, the rest dismissed, all `source: suggested`. | — | `Plan::keep()`. | — |
| Put back (E8) | Statamic's and Craft's update endpoint and Filament's `reopen()` took any idea back to open. | Decided | `Plan::putBack($id, $finished)` from dismissed (E8), or from a started piece the host says isn't finished (E5's **Back to ideas**). A finished piece, an open idea, or a started one without the host's answer is a `Conflict`. `Plan::edit()` changes words, never the state. (Corrected in 0.5.1: 0.5.0 refused started pieces too.) | Host passes `$finished` (its E6 check) |
| A piece deleted | Statamic and Craft showed a drafted idea with no session as open, without saving it; Filament saved it open. | Accidental | `Plan::ideas()` releases and saves (Filament's). | — |
| Clearing | Statamic and Craft refused anything but open or dismissed; Filament's method took any state (its screen only offered those two). | Accidental | `Plan::clear()`: open or dismissed only. | — |
| Shapes | `collection` / `section` / `resource`; `type` / `kind`; `session` / `session_id`; `created_at` / `createdAt`; empty text `''` or `null` (Filament). | CMS | `Idea::fromArray()` / `toArray()` by format. | — |

## Kinds of content

| Rule | What differed | Kind | What core does | Per addon |
|---|---|---|---|---|
| "Something new" | The same brief, worded for the CMS: entries or records, collection, section or resource; Statamic and Craft keep a blueprint or entry type, `where` and `defaults`, Filament doesn't. | CMS | `ContentType::generic($format, $group, $label)`, word for word. | — |
| A new handle | Statamic and Filament slugged as Laravel does (apostrophes dropped), Filament falling back to the resource then `kind`; Craft turned other characters into hyphens. | CMS | `ContentType::handleFor()`, by format. | — |
| Kind suggestions due a look (Q3) | Statamic: only when idle; Craft and Filament: not while working or failed. The same thing. Ten more published records before another look. | — | `KindSuggestions::due()`. Only Get started looks without being asked (the addon's call). | — |
| Shapes | `checked_at` / `checkedAt`; `entries` / `records`; Filament's `learning` queue. | CMS | `KindSuggestions` by format. | — |

## Guides and screens

| Rule | What differed | Kind | What core does |
|---|---|---|---|
| Saving a guide | The same: trailing space trimmed, one newline. | — | `Guide::normalise()`. |
| An imagery guide's section | The same `##` lookup in all three. | — | `Guide::section()`. |
| A failure shown once | Statamic forgot a plan failure on the next visit; Craft had `forgetFailure()` for its guide and plan screens; Filament forgot them on mount. | Accidental | `forgetFailure()` on every work state, for the addon to call when the screen opens. |
| One scan or refinement at a time | Each controller refused while working, with "Ghostwriter is still working on the last request." | — | `begin()` throws a `Conflict`. |
| Shapes | Filament's guide state has no `pending`; its plan state has only `status`, `error` and `pending`. | CMS | `GuideState`, `PlanState` by format. |

## Images

| Rule | What differed | Kind | What core does | Per addon |
|---|---|---|---|---|
| Image requests | The same machine: working, then done or failed. Statamic says `done`, Craft and Filament `ready`; the owner is `user`, `userId` or `user_id`; created as ISO 8601 (Statamic) or a Unix time. | CMS | `ImageRequest` by format; `details` keeps everything else in the addon's keys. | — |
| A request is its owner's | All three; Filament answered "not found". | — | `ImageRequests::mine()` throws `NotAllowed`; an addon may answer 404. | — |
| Cleared after a day | All three, from a file's or row's last change, with their files. | — | `ImageRequestStore::clearOlderThan()`, called by `ImageRequests::start()`. | — |
| Placeholders: where (D10) | The same rule in all three: an empty image field gets one if required, or if at least half the records (or blocks of that type) have an image there; never a field that only takes other files, never over a person's choice; never on an edit. | Decided | `Placeholders::fill()` on a core `Schema`. | — |
| Placeholders: builders | Builders were found by `kind === 'blocks'` (Statamic), `engine === 'builder'` (Filament) or any `engine` (Craft). An empty builder got one block: Statamic when it held nothing but images, Craft for any reference-kind builder with an image field in a set, Filament never. | Decided | `Field::isBuilder()`; an empty builder that holds nothing but images gets one block, built by the addon's `AssetSink::block()`, which may decline. | block with an `id` | block, now only for images-only builders | declines (as before) |
| Placeholders: the image | The same 1600 × 1000 stripes, byte for byte. | — | `Placeholders::png()`. | — |
| Placeholders: the setting | `placeholder_images` (Statamic config `images.placeholders`, Filament setting), `placeholderImages` (Craft), all labelled "Mark images still to choose". | CMS (key) | `Placeholders::SETTING`, `LABEL`, `HELP`, and the note after a draft is used. | keeps its config key | keeps `placeholderImages` | — |
| Saving the file | The asset container (Statamic, `ghostwriter/image-placeholder.png`), the volume (Craft), the upload disk and directory (Filament). | CMS | The `AssetSink` port. | its container | its volume | its disk |

## Queue waiting

| Rule | What differed | Kind | What core does | Per addon |
|---|---|---|---|---|
| Work not picked up | Statamic (files) and Filament (state rows) marked queued work and said so after 30 seconds, naming the worker command; Craft runs its own queue. | — | `Waiting` over a `WaitingStore`. | `runsItself: true` for Craft, and for the sync queue |
| The notice | “command” (Statamic) or `command` (Filament). | CMS (wording) | `DomainOptions::$queueNotice`. | default / `filament()` |
