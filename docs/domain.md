# Domain and stores: wiring them into an addon

Core 0.5.0 holds Ghostwriter's domain: the pieces being written and their conversations, the content plan, the kinds of content, the voice and image style guides, image requests and the queue-waiting notice, with the rules that go with them. Each addon keeps its storage, its permissions, its jobs and its UI, and implements a few small ports.

What differed between the three, and what core does instead, is in [domain-unification.md](domain-unification.md).

## What's in it

All under `NineteenNinetyFour\Ghostwriter\Core\Domain`, apart from the placeholders (`Core\Images`).

| Type | What it is | Rules on it |
|---|---|---|
| `Format` | How the addon stores records today: `Statamic`, `Craft`, `Filament`. Makes IDs (`newId()`), stamps dates (`stamp()`), reads them (`Format::parse()`). | |
| `DomainOptions` | The settings (`shared`, `jobTimeout`) and the real per-addon differences, as `statamic()`, `craft()`, `filament()`. | `staleAfter()`: the job limit (timeout × 3 + 60) plus 120 seconds, so work is never marked stopped while its job may still run. |
| `Viewer` | The person asking: user ID, `manager` (the settings permission), `admin` (a Statamic super user). | |
| `Sessions\Session` | A piece: brief, conversation, draft, status, usage, examples, images, the record it is for, who started, touched and ran it. | `claim()`, `isStale()`, `recoverIfStale()`, `answer()`, `fail()`, `markApplied()`, `canRetry()`, `waitingOn()`, `title()`. |
| `Sessions\SessionAccess` | Who may see, resume and delete (E7, Q1). | `canSee()`, `canResume()`, `canDelete()`, `visible()`. |
| `Sessions\SessionGuard` | Every change to a session, under its lock, with the rules. | `find()`, `visible()`, `start()`, `send()`, `retry()`, `edit()`, `change()`, `applied()`, `delete()`. |
| `Sessions\Progress`, `Record` | Where a piece has got to, and whether it's finished (E6). | `Progress::of($session, $record, $options)`. |
| `Sessions\SessionImages` | The images chosen for a draft's fields. | `startMaking()`, `made()`, `failed()`, `offer()`, `choose()`, `copy()`, `mergeTurn()`. |
| `Planning\Idea`, `PlanState`, `Plan` | The content plan, its screen's state, and its rules (E3, E8). | `receive()`, `keep()`, `drop()`, `putBack()`, `dismiss()`, `start()`, `release()`, `clear()`, `openByGroup()`. |
| `Kinds\ContentType`, `KindSuggestions`, `Analysis` | Kinds of content, the kinds suggested per group, and whether a group is being studied. | `generic()`, `modelledOn()`, `forSession()`, `missing()`, `handleFor()`, `toStudio()`; `store()`, `remove()`, `due()`. |
| `Guides\Guide`, `GuideState` | The voice and image style guides, and their screens' state. | `normalise()`, `section()`. |
| `Images\ImageRequest`, `ImageRequests`, `StoredFile` | The image button's requests and the pictures beside them. | `succeed()`, `fail()`, `isOwnedBy()`, `isStale()`, `isExpired()`; `ImageRequests::start()`, `mine()`, `change()`. |
| `Queue\Waiting` | Work not yet picked up by a worker. | `queued()`, `started()`, `waited()`, `notice()`. |
| `Core\Images\Placeholders` | The striped placeholder and where it goes (D10). | `fill()`, `note()`, `png()`. |

Work states (`PlanState`, `GuideState`, `KindSuggestions`, `Analysis`) share `begin()`, `succeed()`, `fail()`, `forgetFailure()` and `recoverIfStale()`.

Refusals are exceptions carrying the status to answer with (`status()`): `NotFound` (404), `NotAllowed` (403), `Conflict` (409), `Busy` (409, with `waitingOn`), `LockTimeout` (409). All extend `Refused`.

## The ports

Each store takes and returns core types only. Read a stored record with `Type::fromArray($stored, $format)` and write `$object->toArray()`: unchanged fields come back exactly as they were read, so existing data needs no migration.

| Port | Statamic | Craft | Filament |
|---|---|---|---|
| `Sessions\SessionStore` | one JSON file each in `ghostwriter.sessions_path` | `ghostwriter_sessions`: `data` is `toArray()` as JSON; set `userId` and `elementId` from `startedBy` and `recordId` | `ghostwriter_sessions` through the `Session` model: `Session::fromArray($model->getAttributes(), Format::Filament)`, and `$model->setRawAttributes($session->toArray())`; the workspace scope stays on the model |
| `Planning\PlanStore` | `ideas.yaml` (the `ideas` list) and `plan.json` | `idea` documents and the `plan` state | `ghostwriter_ideas` rows and the `plan` state row |
| `Kinds\KindStore` | `types/<handle>.yaml` (`ContentType::fromArray($yaml, Format::Statamic, $handle)`), `kinds.json` and `types.json`, keyed by collection | `type` documents (YAML), the `kinds` and `types` state, keyed by section | `ghostwriter_kinds` rows, the `kinds:<resource>` state row (its `learning` key is the `Analysis`, or keep your own) |
| `Guides\GuideStore` | `voice.md`, `imagery.md`; `voice.json`, `imagery.json` | `guide` documents; `voice`, `imagery` state | `ghostwriter_guides`; `guide:<kind>` state rows |
| `Images\ImageRequestStore` | `images/<id>.json`, files in `images/files` | `image:<id>` state, `ghostwriter_files` | `image:<id>` state rows, the local disk |
| `Queue\WaitingStore` | `queued/<subject>.mark` files | — (its own queue: `runsItself`) | `queued:<subject>` state rows |
| `Lock` | `flock` on `<id>.lock` beside the session | `Craft::$app->getMutex()` (as `Store::locked()`) | `Cache::lock($key, 60)->block($wait, $work)` on a store every worker shares |
| `Core\Images\AssetSink` | the field's asset container, `ghostwriter/image-placeholder.png`, titled and with alt text | the field's volume, `ghostwriter-image-placeholder.png` | the field's disk and directory |

Where a store knows when a work state or image request last changed (Craft's `dateUpdated`, a file's modification time, Filament's `updated_at`), set `$state->changedAt` when reading it, so `recoverIfStale()` can tell a stopped job.

A store's `find()` must return null, never throw, for an ID that isn't one: check it with `Format::isSessionId()` before touching a path or a query.

### Building them

```php
use NineteenNinetyFour\Ghostwriter\Core\Domain\DomainOptions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Images\ImageRequests;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\Plan;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Queue\Waiting;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\SessionGuard;

$options = DomainOptions::statamic(
    shared: (bool) config('ghostwriter.shared_conversations', true),
    jobTimeout: $settings->timeout(),
);

$sessions = new SessionGuard($sessionStore, $lock, $options);
$plan = new Plan($planStore, $lock, $options->format);
$images = new ImageRequests($imageRequestStore, $lock, $options);
$waiting = new Waiting($waitingStore, $options, runsItself: config('queue.default') === 'sync');
```

Make them container singletons (Laravel) or plugin components (Craft). The viewer comes from the signed-in user:

```php
$viewer = new Viewer(User::current()?->id(), manager: $user->can('edit ghostwriter settings'), admin: $user->isSuper());   // Statamic
$viewer = new Viewer(auth()->id(), manager: Gate::allows(GhostwriterServiceProvider::MANAGE_ABILITY));                      // Filament
$viewer = new Viewer((int) $user->id, manager: Plugin::canManage($user));                                                     // Craft
```

## In the controllers and jobs

```php
// Starting a piece, then its job
$session = Session::start($options->format, $type->handle, $answers, $viewer->id, $examples);
$session = $sessions->start($session, $studio->brief($kind, $answers), $viewer);
RunSessionTurn::start($session->id);

// A message, or a retry; Busy says whose request is running
try {
    $session = $sessions->send($id, $message, $viewer);
} catch (Busy $busy) {
    abort(409, $busy->messageFor(fn ($id) => Presenter::name($id)));
} catch (Refused $refused) {
    abort($refused->status(), $refused->getMessage());
}

// A hand edit (refused while a turn runs)
$sessions->edit($id, $viewer, fn (Session $s) => $s->draft = $yaml);

// In the turn's job: the answer laid over the session as it is now
$sessions->change($id, function (Session $latest) use ($response, $imagesBefore, $turnImages) {
    SessionImages::mergeTurn($latest, $turnImages, $imagesBefore);
    $latest->answer($response->reply, $response->document, $response->inputTokens, $response->outputTokens);
});

// Where a piece has got to
$progress = Progress::of($session, Record::saved($entry->published(), $entry->lastModified()), $options);
```

The content plan:

```php
$plan->begin();                                  // Conflict while it is already looking
SuggestIdeas::start(...);
// in the job
$plan->receive($result->value);                  // SuggestedIdea[]: joins any batch waiting (E3)
// the review
$plan->keep($chosen);                            // chosen open, the rest dismissed
$plan->drop();                                   // "Drop them all"
$plan->putBack($id);                             // only from dismissed (E8)
$ideas = $plan->ideas(fn ($session) => $sessionStore->find((string) $session) !== null);
```

Placeholders, when a new record's draft is applied and the setting is on:

```php
$placeholders = new Placeholders($sink, $pattern->filled);
$data = $placeholders->fill($data, $schema);
if ($note = $placeholders->note()) {
    $notes[] = $note;
}
```

## Contract tests

Core ships what every store must do, in `tests/Contracts`: `SessionStoreContract`, `PlanStoreContract`, `KindStoreContract`, `GuideStoreContract`, `ImageRequestStoreContract`, `WaitingStoreContract` and `LockContract`, each a trait with an abstract PHPUnit case beside it (`SessionStoreContractTest`, and so on). Core runs them against its in-memory stores (`Domain\Testing\InMemory*`) for all three formats.

Packagist installs include `tests/Contracts` (the rest of `tests/` is left out). Map the namespace in the addon's `composer.json`:

```json
"autoload-dev": {
    "psr-4": {
        "NineteenNinetyFour\\Ghostwriter\\Core\\Tests\\Contracts\\": "vendor/1994/ghostwriter-core/tests/Contracts/"
    }
}
```

Then, in Statamic or Filament (PHPUnit, with the app booted by the addon's own base case if the store needs it):

```php
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\SessionStoreContract;

final class SessionStoreTest extends TestCase   // the addon's own TestCase
{
    use SessionStoreContract;

    protected function sessionStore(): SessionStore { return app(FileSessionStore::class); }
    protected function storeFormat(): Format { return Format::Statamic; }
}
```

In Craft, use the trait in a Codeception unit test, and give it real users for the sessions table's foreign key:

```php
final class SessionStoreTest extends Unit
{
    use SessionStoreContract;

    protected function sessionStore(): SessionStore { return new DbSessionStore(); }
    protected function storeFormat(): Format { return Format::Craft; }
    protected function contractUser(int $n): int { return $this->users[$n]; }
}
```

The in-memory stores, `InMemoryLock` (which can be told a key is held elsewhere) and `MemoryAssetSink` are there for an addon's unit tests too.

## Checking before and after

1. **Stored data reads back unchanged.** Before switching, copy the test site's state (Statamic `storage/ghostwriter` and `resources/ghostwriter`; Filament's `ghostwriter_*` rows; Craft's `ghostwriter_*` tables). After switching, read every record with the new stores and write it to a scratch copy: the copy must equal the original byte for byte (JSON compared decoded). `tools/record-domain/record.php` reads the three test sites the same way, read only, and core's `RoundTripTest` covers what it recorded.
2. **The addon's own suite passes unchanged**, apart from tests that asserted a behaviour the decisions changed (listed under "Per addon" below).
3. **The contract tests pass** against the addon's stores and lock.
4. **On the test site**, write a piece end to end, send while someone else's run works (the "is waiting" message), edit by hand while it works (refused), keep and drop a batch of plan suggestions, put a dismissed idea back, and kill a worker mid-turn and wait past the stale time (the piece shows as failed and can be tried again).

## Per addon, in stage 2

**All three**

- Require `~0.5.0`.
- Implement the stores, the lock and the asset sink above, and run the contract tests.
- Replace the session, plan, kind, guide and image request classes' rules with core's: ownership checks with `SessionAccess`, "is it working" checks and claims with `SessionGuard`, `finished`/`stage` with `Progress`, the plan's accept/dismiss/reopen with `Plan`, `KindSuggestions` and the generic kind with core's, `Placeholders` with core's.
- Show a stale run as failed (new for Statamic and Filament), a new batch of suggestions joining the one waiting, and "Put back" only on dismissed ideas.

**Statamic**

- `DomainOptions::statamic()`. Keep the file layout; sessions gain `started_working_at` when claimed.
- The `Waiting` files become a `WaitingStore`. The `exclusively()` file lock becomes the `Lock`.
- The placeholder sink keeps the container, path, title and alt text; its blocks get an `id`.

**Craft**

- `DomainOptions::craft()`. `Store::isStale()` and `Store::STOPPED` give way to core's (the same numbers and words); set `changedAt` from `dateUpdated`.
- `Waiting` with `runsItself: true`, so no notice shows. The `Store::locked()` mutex is the `Lock`.
- Placeholders: an empty builder now gets a block only when it holds nothing but images.

**Filament**

- `DomainOptions::filament()`. `Session::claimRun()` gives way to `SessionGuard` and a cache `Lock`; use a cache store every worker shares (database or Redis), not `array` or `file` on several servers.
- Stores map rows with `getAttributes()` / `setRawAttributes()`, inside the workspace scope.
- The asset sink's `block()` returns null, keeping placeholders out of empty builders as before.
- Its translations for "is waiting" and the queue notice can stay; `DomainOptions::filament()` has the same English.
