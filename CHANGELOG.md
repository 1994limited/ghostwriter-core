# Changelog

All notable changes to `1994/ghostwriter-core` are documented here. While the version is `0.x`, a minor release may contain breaking changes.

## 0.1.0 - Unreleased

The first version: the parts the Statamic, Filament and Craft addons had copied by hand, in one framework-free package.

### Added

- **Text:** `Draft`, `LenientYaml`, `TaggedResponse`, `HtmlToMarkdown`, `DraftPreview`, `EntryMerger`, `EntrySimplifier` and `Utf8`. They are unified from the three addons' copies. Where the copies differed, the difference is a constructor option ([docs/text-unification.md](docs/text-unification.md)).
- **Prompts:** all eleven prompts, plus `Vocabulary` (with `statamic()`, `craft()` and `filament()`) and `PromptLibrary`, which supports per-site overrides. Golden tests check that each addon gets its current prompts byte for byte.
- **AI layer,** ported from the Craft addon:
  - Value objects, the `TextProvider` and `ImageProvider` contracts, and the Anthropic, OpenAI and Gemini providers over PSR-18.
  - The `Providers` registry, and the `Credentials`, `HttpClients` and `ProviderSettings` ports.
  - `Models`, one table of default models: `claude-opus-5-5`, `gpt-6.1-sol`, `gemini-3.8-flash`, `gpt-image-2.5-sunburst` and `gemini-3.1-flash-image`.
  - `Agents`, which sets max tokens and effort per agent.
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
  - `Testing\FakeProvider`, with Craft's API plus `assertSent`, `assertNotSent`, `assertNothingSent` and `failWith`.
  - `Testing\MockHttpClient`, `NetworkError` and `RecordingSleeper`.
- **Boundaries:** `bin/check-boundaries`, which fails if `src/` names a framework or CMS.
- **Dependencies:** `symfony/yaml` `^6.4|^7.0|^8.0`, `psr/http-factory` `^1.1`, and Guzzle `^7.8|^8.0` for `GuzzleHttpClients`. CI also runs `--prefer-lowest`, Guzzle 7, Guzzle 8, and the newest versions without the PHP 8.2 platform pin.

### Deprecated

- `TextResponse::$inputTokens`, `$outputTokens` and `$truncated`. Use `$usage->input`, `$usage->output` and `truncated()`. These properties will be removed in 1.0.
