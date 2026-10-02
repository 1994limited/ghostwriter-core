# Changelog

All notable changes to `1994/ghostwriter-core` are documented here. While the version is `0.x`, a minor release may contain breaking changes.

## 0.2.0 - Unreleased

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
