# Ghostwriter Core

The framework-free core shared by the Ghostwriter addons for [Statamic](https://github.com/1994limited/ghostwriter-statamic), [Filament](https://github.com/1994limited/ghostwriter-filament) and [Craft CMS](https://github.com/1994limited/ghostwriter-craft). It holds the parts that used to be copied by hand between the three: draft text handling, the prompts, and the connection to the AI providers.

Each addon stays a thin adapter. It reads the CMS's schema and entries, stores state, runs queue jobs, checks permissions and draws the UI, and calls core for everything else.

Core depends on no framework or CMS. CI fails if `src/` names `Illuminate`, `Laravel`, `Craft`, `Yii`, `Statamic`, `Filament` or `Livewire` (`bin/check-boundaries`).

## Install

```bash
composer require 1994/ghostwriter-core
```

PHP 8.2 or later, with `dom` and `mbstring`. Runtime dependencies are `symfony/yaml` (6.4, 7 or 8), `league/commonmark` 2 and the PSR HTTP and log interfaces (`psr/log` 1 to 3). `guzzlehttp/guzzle` (7.8+ or 8) is suggested, not required: it's needed for `Http\GuzzleHttpClients`, the ready-made HTTP client, and for `Testing\MockHttpClient`'s default factories. CI runs the lowest and highest versions allowed, and Guzzle 7 and 8 each.

During the extraction core is `0.x`, and the addons should require an exact minor (`~0.1.0`).

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

- **Requests and responses:** `TextRequest` (agent, instructions, prompt, history, images; max tokens, effort, model and timeout default sensibly) and `TextResponse` (text, `StopReason`, `Usage`, provider, model, `truncated()`). Images use `ImageRequest` and `Image`.
- **Defaults:** `Models` is the one table of default models and their capabilities. `Agents` gives each agent (prompt name) its max tokens and effort.
- **Reliability:** busy and rate-limited calls are retried with backoff, following `retry-after`, up to 3 attempts. A response timeout is not retried.
- **Errors:** every failure is a `ProviderException` with a message that can be shown to an editor. Its subclasses (`NotConfigured`, `AuthenticationFailed`, `RateLimited`, `Overloaded`, `Unreachable`, `Refused`, `BadResponse`, plus `Truncated` for callers) say what happened, and `retryable()` says whether trying again could help.
- **Cut-off replies:** providers report `StopReason::MaxTokens` and never throw for it. The caller decides whether to retry or throw `Truncated`.
- **Gateways:** a `base_url` per provider, for gateways that speak the same API. It must be `https://`, except for localhost.

## Wiring it into an addon

The adapter implements three ports and builds one `Providers`, shared for the whole request or process:

| Port | What it answers | Laravel addons | Craft |
|---|---|---|---|
| `Ports\Credentials` | `key(provider)`: the trimmed key, or null. Env names are in `Credentials::ENV`. | `config("ghostwriter.keys.{$provider}")` | `App::env(Credentials::ENV[$provider])` |
| `Ports\HttpClients` | A PSR-18 client for a timeout, plus PSR-17 factories | `new GuzzleHttpClients()` | `Craft::createGuzzleClient([...])` wrapped in a small class, or `new GuzzleHttpClients(['handler' => $stack])` |
| `Ports\ProviderSettings` | Text and image provider and model, timeout, base URLs, Anthropic fallbacks on or off | from `config('ghostwriter.*')` or the settings model | the plugin's `Settings` |

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

To test at the HTTP level, use `Testing\MockHttpClient`. It is both a PSR-18 client and an `HttpClients`, it records every request, and it can throw `Testing\NetworkError::connectFailed()` or `::timedOut()`. Pass `Testing\RecordingSleeper` to `Providers` so retries record their waits instead of sleeping.

## Development

```bash
composer install
vendor/bin/phpunit          # tests
vendor/bin/pint --test      # code style (Laravel preset)
vendor/bin/phpstan analyse  # level 8
bin/check-boundaries        # no framework or CMS in src/
```

To work on core and an addon together, point the addon at a sibling checkout with a Composer `path` repository in a gitignored `composer.local.json`, so it never reaches a release.

## Licence

Proprietary. See [LICENSE](LICENSE).
