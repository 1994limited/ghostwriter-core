# Ghostwriter Core

The framework-free core shared by the Ghostwriter addons for [Statamic](https://github.com/1994limited/ghostwriter-statamic), [Filament](https://github.com/1994limited/ghostwriter-filament) and [Craft CMS](https://github.com/1994limited/ghostwriter-craft). It holds the parts that used to be copied by hand between the three: draft text handling, the prompts, the connection to the AI providers, the schema model and the layout algorithms, the domain model and its rules, and photo search with ranking.

Each addon stays a thin adapter. It reads the CMS's schema and entries, stores state, runs queue jobs, checks permissions and draws the UI, and calls core for everything else.

Core depends on no framework or CMS. CI fails if `src/` names `Illuminate`, `Laravel`, `Craft`, `Yii`, `Statamic`, `Filament` or `Livewire` (`bin/check-boundaries`).

## Install

```bash
composer require 1994/ghostwriter-core
```

PHP 8.2 or later, with `dom` and `mbstring`. Runtime dependencies are `symfony/yaml` (6.4, 7 or 8), `league/commonmark` 2 and the PSR HTTP and log interfaces (`psr/log` 1 to 3). `guzzlehttp/guzzle` (7.8+ or 8) is suggested, not required: it's needed for `Http\GuzzleHttpClients`, the ready-made HTTP client, and for `Testing\MockHttpClient`'s default factories. CI runs the lowest and highest versions allowed, and Guzzle 7 and 8 each.

Core follows semantic versioning from 1.0.0. The addons require `^1.0`.

## What's in it

### Text: `NineteenNinetyFour\Ghostwriter\Core\Text`

| Class | What it does |
|---|---|
| `Draft` | Parses a YAML draft (rich text as markdown, blocks as a list). Unwraps code fences and explains what's wrong with a bad one. |
| `LenientYaml` | Reads YAML written by a model, re-quoting prose that strict YAML would choke on. |
| `TaggedResponse` | Splits a `<reply>…</reply><draft>…</draft>` answer, and the writer's optional `<images>` block. |
| `HtmlToMarkdown` | Turns rich text HTML into the markdown a writer would type. |
| `DraftPreview` | Lays a draft out as a tree for the panel, with safe HTML and a path for each editable piece. |
| `EntryMerger` | Lays a rewritten draft over the entry it came from, keeping block IDs and everything that isn't writing. |
| `EntrySimplifier` | Reduces a stored entry to draft form, for showing real entries to the model as examples. |
| `Utf8` | `Utf8::scrub()` makes every string in a value valid UTF-8 before it's encoded as JSON. |
| `Slug` | `Slug::make()` for file names (ASCII, accents taken off, cut between words) and `Slug::clip()` for one-line titles and alt text. |

Where the three addons' copies differed, the difference is a constructor option. See [docs/text-unification.md](docs/text-unification.md) for what each addon passes.

### Prompts: `NineteenNinetyFour\Ghostwriter\Core\Prompts`

The twelve prompts live in `resources/prompts`. The CMS's own words (website or app, section or resource, entry or record) are `[[name]]` placeholders, filled from a `Vocabulary`:

```php
use NineteenNinetyFour\Ghostwriter\Core\Prompts\PromptLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\Vocabulary;

$prompts = new PromptLibrary(
    Vocabulary::statamic(),                      // or ::craft(), ::filament(), or your own
    fn (string $name): ?string => is_file($path = resource_path("ghostwriter/prompts/{$name}.md"))
        ? file_get_contents($path)
        : null,                                  // a site's override, or null for core's
);

$instructions = strtr($prompts->get('planner'), [
    '{{ count }}' => '8',
    '{{ voice }}' => $voice,
    // ...the addon fills its own {{ name }} placeholders, as before
]);
```

### AI: `NineteenNinetyFour\Ghostwriter\Core\Ai`

One way to call a model, used by all three addons, over any PSR-18 client. It covers Anthropic, OpenAI and Gemini for text, and OpenAI and Gemini for images.

- **Requests and responses:** `TextRequest` (agent, instructions, prompt, history, images; max tokens, effort, model and timeout default sensibly; `withMaxTokens()` and `withModel()` return a copy) and `TextResponse` (text, `StopReason`, `Usage`, provider, model, `truncated()`). Images use `ImageRequest` and `Image`.
- **Defaults:** `Models` is the one table of default models and their capabilities. `Agents` gives each agent (prompt name) its max tokens and effort.
- **Reliability:** busy and rate-limited calls are retried with backoff, following `retry-after`, up to 3 attempts. A response timeout is not retried.
- **Errors:** every failure is a `ProviderException` with a message that can be shown to an editor. Its subclasses (`NotConfigured`, `AuthenticationFailed`, `RateLimited`, `Overloaded`, `Unreachable`, `Refused`, `BadResponse`, plus `Truncated` for callers) say what happened, and `retryable()` says whether trying again could help.
- **Cut-off replies:** providers report `StopReason::MaxTokens` and never throw for it. The caller decides whether to retry or throw `Truncated`.
- **Gateways:** a `base_url` per provider, for gateways that speak the same API. It must be `https://`, except for localhost.

### Studio: `NineteenNinetyFour\Ghostwriter\Core\Studio`

Every model call the addons make for writing, planning and learning a site: the voice guide, the type analysis (with its re-ask), kind suggestions, plan ideas, the imagery guide, brief drafts and the writer's turns. Inputs are small value objects (`VoiceSample`, `TypeSurvey`, `KindSurvey`, `PlanContext`, `ImagerySample`, `Conversation`, `WriterContext`), never CMS objects; results are core types with their token usage. The cut-off policy and the logging of unreadable replies live here, once. See [docs/studio.md](docs/studio.md) for the wiring and the parity check, and [docs/studio-unification.md](docs/studio-unification.md) for what each addon passes.

```php
$studio = new Studio($providers, $prompts, $logger, StudioOptions::statamic());
$ideas = $studio->suggestIdeas(new PlanContext($groups, $plan, $voice, $steer));
$ideas->value;          // SuggestedIdea[]
$ideas->usage->output;  // tokens, retries included
```

### Schema and layouts: `NineteenNinetyFour\Ghostwriter\Core\Schema` and `…\Core\Layout`

What a kind of entry is made of (`Schema`, `Field`, `Kind`, `Set`) and an entry's content in one shape whatever the CMS (`EntryData`), and the algorithms that work on them: `SchemaDescriber` (the fields as the model's brief), `PatternFinder` (how a group's entries are really built: block order and usage, house defaults, examples, fill rates), `KindFinder` (kinds of entry, from how entries are built, with no model call), `HouseStyle` (settings, links, nested items and rich text dressing agreed place by place) and `EntryBuilder` (a draft into entry data). How a CMS stores rich text and links is a dialect: `HtmlDialect` for HTML, a Bard dialect in the Statamic adapter, and `StatamicLinks`, `CraftLinks` and `NoLinks`. See [docs/layout.md](docs/layout.md) for the wiring and the parity check, and [docs/layout-unification.md](docs/layout-unification.md) for what each addon passes.

```php
$layouts = new Layouts(LayoutOptions::craft(), new HtmlDialect, new CraftLinks(hyper: [$hyper], link: [$link]));

$schema = Schema::fromSpecs($reader->read($entryType));
$pattern = $layouts->patterns()->find($schema, PatternFinder::choose($entries, $type->where));
$built = $layouts->builder()->build($draft->data, $schema, $pattern, $type->defaults);
$house = $layouts->houseStyle()->apply($built->data, $schema, $pattern->house, $entryId, $title);

$studioLayout = Layout::fromSchema($schema, $pattern, $layouts->describer());   // for the Studio
```

### Images: `NineteenNinetyFour\Ghostwriter\Core\Images`

Photo search for an image field: free photo libraries searched, the results judged by a model against the page, and the chosen file downloaded safely. Each addon supplies the words around the field, the images already in that place (if any) and storage for the photo that's chosen. See [docs/images.md](docs/images.md) for the wiring.

| Class | What it does |
|---|---|
| `StockSearch` | Searches Unsplash, Pexels and Pixabay (with a key from `Credentials`: `unsplash`, `pexels`, `pixabay`) and Openverse (no key; CC0 and public domain only; can be switched off). `search($term, $shape)` gives `Photo`s; `fetch($source, $id)` looks the photo up again and downloads it as a `PhotoFile`. It holds a set of `Libraries\PhotoLibrary` objects (the four free ones in `Libraries\Free`, plus any passed as `libraries`); `search(..., sources:)` keeps a search to some of them. |
| `Libraries\PhotoLibrary` | One photo library: `search(SearchQuery)`, `photo($id)`, `fetch($id)`, and its `Capabilities` (free or paid, how costs are quoted, how long a comp may be kept, and `mayRank`: whether a model may see its photos). `Offer` and `Cost` say how a paid photo can be had; `Preview` is a paid library's comp. |
| `PhotoFinder` | The whole job: `find(PhotoContext, $references, ?$terms)` chooses searches (the `photo-researcher` agent, or the person's own), runs them, has the results judged, and runs a second round when nothing fits. Returns `PhotoResults`. |
| `PhotoRanker` | The judging, through the `photo-picker` agent. With reference images it matches style and subject; without, subject alone. Clear misses are left out. Without a model, results come back unranked and nothing is picked. Photos from a library without `mayRank` are never shown to the model: they follow the judged ones, unjudged. |
| `Photo` | One result: source, id, thumbnail, credit, licence, the library's own `title`, `description` and `tags`, size, the search that found it, and `picked`/`reason` when a model judged it. `alt()`, `assetTitle()` and `filenameBase()` turn the library's words into alt text, an asset title and a file name, falling back to the search term. |
| `PhotoContext` | The words around the field: page title and summary, field label, block text, page text, shape, and the site's imagery guide. |
| `PhotoResults` | The photos in order, with `judged`, `withReferences`, `noneFit`, `retried` and the `terms` searched. `picked()` is the shortlist; it is empty unless a model judged, so a "Best match" badge can follow `picked` alone. |

```php
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoContext;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoFinder;
use NineteenNinetyFour\Ghostwriter\Core\Images\StockSearch;

$stock = new StockSearch($http, $credentials, openverse: fn () => $settings->openverse, logger: $logger);
$finder = new PhotoFinder($stock, $providers, $prompts, $logger);

$results = $finder->find(
    PhotoContext::make($title, $label, $blockText, $pageText, $summary, shape: 'landscape', style: $imageryGuide),
    $referenceBytes,              // the images already in that place; [] is fine
    $typedSearches ?: null,       // null: the model chooses the searches
);

$json = $results->toArray();      // photos (with alt, asset_title, picked, reason), terms, judged, none_fit...

// When the person chooses one:
$file = $stock->fetch($source, $id);                    // looked up again by ID; https only; at most 15 MB
$name = $file->photo->filenameBase().'.'.$file->extension;
$alt = $file->photo->alt();
```

Downloads are https only, redirects included (core follows them itself, at most three, and never to a private address), read no further than their cap (15 MB for a photo, 2 MB for a thumbnail) and checked to be a JPEG, PNG or WebP. Openverse results are kept only when Openverse's own thumbnail loads; originals are never fetched to check them.

### Domain: `NineteenNinetyFour\Ghostwriter\Core\Domain`

The pieces being written (`Sessions\Session`), the content plan (`Planning\Idea`, `PlanState`), kinds of content (`Kinds\ContentType`, `KindSuggestions`), the voice and image style guides (`Guides\Guide`, `GuideState`), image requests (`Images\ImageRequest`) and the queue-waiting notice (`Queue\Waiting`), with their rules: who may see, resume and delete a piece (`SessionAccess`), one run at a time under a per-session lock with stale runs recovered (`SessionGuard`), when a piece is finished (`Progress`), and the plan's review, dismissal and put-back rules (`Plan`). Each addon implements the store interfaces (`SessionStore`, `PlanStore`, `KindStore`, `GuideStore`, `ImageRequestStore`, `WaitingStore`) and the `Lock` port, and proves them with the contract tests in `tests/Contracts`. Every type reads and writes the shape the addon stores today (`fromArray($stored, Format::Craft)`), so no data migration is needed. In-memory stores for tests are in `Domain\Testing`. See [docs/domain.md](docs/domain.md) for the wiring, and [docs/domain-unification.md](docs/domain-unification.md) for what differed.

```php
$sessions = new SessionGuard($store, $lock, DomainOptions::filament(shared: config('ghostwriter.shared_conversations')));

$session = $sessions->send($id, $message, new Viewer(auth()->id()));    // Busy (409) while someone's request runs
$sessions->change($id, fn (Session $s) => $s->answer($reply, $draft, $in, $out));   // in the job
$finished = Progress::of($session, Record::saved($published), $options)->finished;  // E6
```

`Images\Placeholders` draws the striped placeholder and decides where it goes (D10); the addon saves the file through an `AssetSink`.

## Wiring it into an addon

The adapter implements three ports and builds one `Providers`, shared for the whole request or process:

| Port | What it answers | Laravel addons | Craft |
|---|---|---|---|
| `Ports\Credentials` | `key(provider)`: the trimmed key, or null. Env names are in `Credentials::ENV`. | `config("ghostwriter.keys.{$provider}")` | `App::env(Credentials::ENV[$provider])` |
| `Ports\HttpClients` | A PSR-18 client for a timeout, plus PSR-17 factories | `new GuzzleHttpClients()` | `Craft::createGuzzleClient([...])` wrapped in a small class, or `new GuzzleHttpClients(['handler' => $stack])` |
| `Ports\ProviderSettings` | Text and image provider and model, timeout, base URLs, Anthropic fallbacks on or off | from `config('ghostwriter.*')` or the settings model | the plugin's `Settings` |

Photo search uses the same `HttpClients`. An `HttpClients` of your own (Craft's wrapper) should also implement `Ports\DownloadClients`, a client that hands redirects back instead of following them, so core can check each hop is https. `GuzzleHttpClients` and `MockHttpClient` already do.

```php
use NineteenNinetyFour\Ghostwriter\Core\Ai\Http\GuzzleHttpClients;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;

$providers = new Providers($credentials, new GuzzleHttpClients(), $settings, $logger);

$response = $providers->text()->text(new TextRequest(
    agent: 'writer',
    instructions: $prompts->get('writer'),
    prompt: $prompt,
    history: Message::list($session->history),
));

$image = $providers->image()?->image(new ImageRequest('A lighthouse at dusk', $references, Shape::Landscape));
```

`ArrayCredentials` and `StaticProviderSettings` are plain implementations, for tests or for hosts that read their config once.

Logging is optional: pass any PSR-3 logger. A finished call is logged at `info`, each retry at `warning` and a failed call at `error`. Prompts, replies, images and keys are never logged.

Retries can make a call take up to `timeout × 3` plus the waits, so queue jobs should allow `timeout × 3 + 60` seconds.

How the registry waits between retries, and how often it retries, are constructor arguments (`sleeper:`, `retry:`). To change them on a registry that's already built, `withSleeper(Sleeper)` and `withRetryPolicy(RetryPolicy)` return a configured copy; the registry is otherwise immutable, so rebind the copy wherever the original was shared. A fake standing in carries over to the copy.

```php
$providers = $providers->withSleeper(new RecordingSleeper)->withRetryPolicy(new RetryPolicy(attempts: 1));
```

### Request limits

`Ai\Limits::MAX_IMAGES` (24) and `Ai\Limits::MAX_IMAGE_BYTES` (20 MB of raw image data) cap one request, and every provider refuses a request over them before sending it. `Limits::fits($images)` checks a list in advance. 24 leaves room for the photo picker's 3 references and 18 thumbnails. 20 MB of raw bytes is about 27 MB once base64-encoded, which is under Anthropic's 32 MB request limit.

## Testing with `FakeProvider`

```php
$fake = $providers->fake();   // every text and image call goes to the fake from now on

$fake->respond('writer', '<reply>Here it is.</reply><draft>title: A</draft>');
$fake->respond('photo-picker', '3, 1', '2');                     // handed out in order; the last repeats
$fake->respond('planner', fn (TextRequest $r) => '<ideas>…</ideas>');
$fake->failWith('brief-writer', new RateLimited('Busy.', 'anthropic', 429));
$fake->respondWithImage(Image::fromPath(__DIR__.'/fixtures/photo.jpg'));

// ...run the code under test...

$fake->assertSent('photo-picker', fn (TextRequest $r) => count($r->images) === 9);
$fake->assertNotSent('writer');
$fake->assertImageSent(fn (ImageRequest $r) => $r->shape === Shape::Landscape);
$fake->assertNoImageSent();
$fake->assertNothingSent();
$fake->prompted('writer')[0]->prompt;
$fake->imageRequests;

$fake->reset('writer');   // forget the writer's queued answers and requests
$fake->reset();           // forget every answer and request, text and image
```

When PHPUnit is loaded (Pest included), the asserts go through `PHPUnit\Framework\Assert`, so each one counts as an assertion and a test that only asserts on the fake isn't marked risky. Without PHPUnit they throw `AssertionError`, so core needs no test framework at runtime.

### Testing without keys

A fake marked unconfigured makes the registry behave as if the keys were missing, so the no-key paths can be tested while nothing leaves the machine:

```php
$providers->fake(FakeProvider::withoutKeys());   // no text key, no image key

$providers->configured();     // false
$providers->text();           // throws NotConfigured, worded as for a real missing key
$providers->image();          // null
$providers->imageHandle();    // null
$providers->keyStatus();      // every key false

$providers->fake()->unconfigured(text: false);   // a text key, but no image key
$providers->fake()->unconfigured(false, false);  // both keys back
```

`reset()` keeps whether the fake is unconfigured.

To test at the HTTP level, use `Testing\MockHttpClient`. It is both a PSR-18 client and an `HttpClients`, it records every request, and it can throw `Testing\NetworkError::connectFailed()` or `::timedOut()`. Pass `Testing\RecordingSleeper` to `Providers` (or use `withSleeper()`) so retries record their waits instead of sleeping.

## Development

```bash
composer install
vendor/bin/phpunit          # tests
vendor/bin/pint --test      # code style (Laravel preset)
vendor/bin/phpstan analyse  # level 8
bin/check-boundaries        # no framework or CMS in src/
```

The addons' documentation screenshots come from one re-runnable tool in `tools/screenshots/`. It drives each addon's local test site, sets up each scene without model calls, and restores the site afterwards. See its [README](tools/screenshots/README.md). The `tools/` folder isn't included in the Composer package.

To work on core and an addon together, point the addon at a sibling checkout with a Composer `path` repository in a gitignored `composer.local.json`, so it never reaches a release.

## Licence

Proprietary. See [LICENSE](LICENSE).
