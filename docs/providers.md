# AI providers

Core calls models through `Ai\Providers`, which picks the provider from the settings and the keys. Four providers write, and three make images:

| Handle | Writes | Makes images | Key from |
|---|---|---|---|
| `anthropic` | Claude (Messages API) | – | `ANTHROPIC_API_KEY` |
| `openai` | ChatGPT (Chat Completions) | `gpt-image-2.5-sunburst` (Images API) | `OPENAI_API_KEY` |
| `gemini` | Gemini (`generateContent`) | `gemini-3.1-flash-image` | `GEMINI_API_KEY` |
| `openrouter` | Claude, GPT, Gemini and others through one key (OpenAI-compatible Chat Completions) | `openai/gpt-image-2.5-sunburst` by default (OpenRouter's Image API) | `OPENROUTER_API_KEY`, or **Connect with OpenRouter** |

Keys in `.env` work exactly as before. The env names are in `Ports\Credentials::ENV`. `Providers::keyStatus()` now also lists `OPENROUTER_API_KEY`, as the last entry.

## OpenRouter

[OpenRouter](https://openrouter.ai) sells access to many companies' models through one account and one key, paid for with prepaid credit. It suits people who don't want to create an API key at Anthropic, OpenAI or Google. With **Connect with OpenRouter**, they sign in to OpenRouter (or sign up), choose a spending limit, and come back to the control panel with a key already saved. Nothing needs copying. See [connecting-accounts.md](connecting-accounts.md#connecting-a-model-provider-connect-with-openrouter) for the flow and the routes.

### Where the key comes from

`Credentials\ConnectedCredentials` wraps the addon's own `Credentials`:

```php
$keys = new EncryptedProviderKeys(...);                      // the addon's Ports\ProviderKeys, encrypted at rest
$credentials = new ConnectedCredentials($envCredentials, $keys);
$providers = new Providers($credentials, $http, $settings, $logger);
```

- **A key in `.env` always wins.** `OPENROUTER_API_KEY` is used whenever it's set, and the connected key is ignored. `ConnectedCredentials::source('openrouter')` returns `'env'`, `'connected'` or `null`.
- Only `openrouter` takes a connected key (`ConnectedCredentials::CONNECTABLE`). Anthropic, OpenAI and Gemini keys still come only from the environment.
- With no key, `Providers::text()` throws `NotConfigured`: "OpenRouter isn't connected. Connect with OpenRouter in Ghostwriter's settings, or add OPENROUTER_API_KEY to your .env file."

### Models

OpenRouter names models `company/model`, e.g. `anthropic/claude-opus-5.5`. Every agent belongs to a tier (`Agents::tier()`), and each tier has its own default model (`Models::OPENROUTER_TIERS`):

| Tier | Agents | Default |
|---|---|---|
| `writing` (`Agents::WRITING`) | every agent not listed below: voice, type analysis, kinds, planning, briefs, the writer, the imagery guide | `anthropic/claude-opus-5.5` |
| `quick` (`Agents::QUICK`) | `photo-researcher`, `photo-picker`, `photo-query`, `photo-scout`, `gap-filler` | `anthropic/claude-sonnet-5.5` |

Which model is used, first match wins:
1. The request's own `model`.
2. The model chosen for the tier, when the `ProviderSettings` also implements `Ports\ModelTiers` (`tierModel($provider, $tier)`). `StaticProviderSettings` takes `tierModels: ['openrouter' => ['quick' => '…']]`.
3. `ProviderSettings::textModel()`, for every agent.
4. The tier's default.

`Models::OPENROUTER_TEXT_CHOICES` and `Models::OPENROUTER_IMAGE_CHOICES` list ids with labels for a settings dropdown. Any other id on openrouter.ai/models works too. The ids were checked against OpenRouter's public model list on 2026-10-03.

**Effort.** `reasoning: {"effort": "low"}` is sent for agents with an effort (the quick tier), but only to models whose own provider takes one: `Models::takesEffort('openrouter', $id)` maps the id back to the native model (`Models::nativeModel()`), e.g. `anthropic/claude-sonnet-5.5` to `claude-sonnet-5-5`. OpenRouter counts reasoning against `max_tokens`, as the other providers do.

### Requests

| | |
|---|---|
| Text URL | `{base_url ?? https://openrouter.ai/api/v1}/chat/completions` |
| Headers | `Authorization: Bearer …`, plus app attribution: `HTTP-Referer: https://1994.co.uk`, `X-OpenRouter-Title: Ghostwriter` and the older `X-Title: Ghostwriter`. |
| Body | `model`, `max_tokens`, `messages` = system + history + the user turn (images as `image_url` data URIs after the text), `reasoning.effort` when it applies. |
| Stop reason | OpenRouter normalises them: `stop` → End; `length` → MaxTokens; `content_filter` → Safety; `tool_calls` and others → Other; `error` → thrown (below). A `message.refusal` with no content → `Refused`. |
| Usage | `prompt_tokens`, `completion_tokens`. |
| Model | The response's `model`, which is the model that actually answered. |
| Images | `POST {base}/images` with `model`, `prompt`, `aspect_ratio` (`3:2`, `2:3`, `1:1` by shape), `n: 1`, and the references as `input_references: [{type: image_url, image_url: {url: data URI}}]`. Reads `data[0].b64_json`. |

Calls are retried as for the other providers: 408, 409, 429, 500, 502, 503 and 504, following `Retry-After`.

### Errors

| OpenRouter answers | Core throws | Message |
|---|---|---|
| 401 | `AuthenticationFailed` | With an env key: "OpenRouter didn't accept the API key (401). Check OPENROUTER_API_KEY." With a connected key: "OpenRouter no longer accepts the key Ghostwriter was connected with (401). Connect with OpenRouter again in the settings." |
| 402, no credit | `OutOfCredit` (new) | "Your OpenRouter credit has run out. Add credit at https://openrouter.ai/settings/credits, then try again." |
| 402, `limit_source: openrouter_key_limit` | `OutOfCredit` | "This OpenRouter key has reached its spending limit. Raise the limit at https://openrouter.ai/settings/keys, then try again." |
| 402, `limit_source: openrouter_in_flight_budget` | `RateLimited` (retryable) | "OpenRouter is holding new requests until earlier ones are paid for. Try again in a moment." |
| 403 with moderation `reasons`, or `error_type: content_policy_violation` | `Refused` | "OpenRouter's moderation flagged this request. Try rewording it." |
| 403, `error_type: refusal` | `Refused` | "The model declined this request. Try rewording it." |
| Any other 403 (guardrails, permissions) | `BadResponse` | "OpenRouter refused this request (403): …". On OpenRouter a 403 is never a bad key. |
| 200 with an `error` in place of choices, or `finish_reason: error` | Mapped as above by its `code`; otherwise `BadResponse`, retryable for 5xx | "OpenRouter could not finish the answer: …" |
| Anything else | As for the other providers | |

### Image making

OpenRouter's Image API serves both models core uses by default: `openai/gpt-image-2.5-sunburst` (the default) and `google/gemini-3.1-flash-image`, with reference images. `Models::IMAGE_ORDER` is now `openai`, `gemini`, `openrouter`. So when no image provider is chosen, a site that already has an OpenAI or Gemini key keeps making images with it, and a site that only has OpenRouter makes them through OpenRouter. Set the image provider to `openrouter` to make images through it even when another key exists.

An image model chosen in the settings must use OpenRouter's spelling (`openai/…`, `google/…`) when OpenRouter makes the images.

### Privacy

With OpenRouter, every request goes through OpenRouter on its way to the model's company: the instructions, the prompt and any images (photo ranking, alt text, references for image making). OpenRouter's own [privacy policy](https://openrouter.ai/privacy) and data settings apply, as well as those of the company whose model answers. The addons' privacy notes and settings screens should say so when OpenRouter is chosen. Core never logs prompts, replies, images or keys.

### Testing

- `Testing\MockHttpClient` covers the provider and the connection, as for the other providers. Core's tests never call OpenRouter.
- `Testing\FakeOpenRouter` stands in for `ConnectsProvider` in an addon's route tests, and `Testing\InMemoryProviderKeys` stands in for the encrypted store.
- `Providers::fake()` covers OpenRouter like any other provider.
