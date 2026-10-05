# Connections

Settings → Connections is one page in each addon where a site sets up every outside service Ghostwriter uses: open the service's page, make a key, paste it, **Check & save**. Core holds everything but the page and the encrypted storage.

## The services

`Connections\Services::all()`, grouped as the page shows them:

| Group | Service | Fields (environment variable) | Check & save calls | Signs in too |
| --- | --- | --- | --- | --- |
| Writing | Anthropic | key (`ANTHROPIC_API_KEY`) | `GET /v1/models?limit=1` | |
| Writing | OpenAI (also makes images) | key (`OPENAI_API_KEY`) | `GET /v1/models` | |
| Writing | Gemini (also makes images) | key (`GEMINI_API_KEY`) | `GET /v1beta/models?pageSize=1` | |
| Writing | OpenRouter (also makes images) | key (`OPENROUTER_API_KEY`) | `GET /api/v1/key` | Connect with OpenRouter |
| Images | Unsplash | access key (`UNSPLASH_ACCESS_KEY`) | one search, `per_page=1` | |
| Images | Pexels | key (`PEXELS_API_KEY`) | one search, `per_page=1` | |
| Images | Pixabay | key (`PIXABAY_API_KEY`) | one search, `per_page=3` (its least) | |
| Images | Openverse | none | | |
| Stock photos | Shutterstock | consumer key and secret (`SHUTTERSTOCK_API_KEY`, `SHUTTERSTOCK_API_SECRET`) | one search with basic auth (the sandbox where the site uses it) | Connect account (to license) |

Image making has no cards of its own: it uses the OpenAI, Gemini or OpenRouter key from Writing. Getty Images and iStock join Stock photos when core has their adapter.

Each service's words (what it is, two or three plain steps, field labels) are in `resources/lang/<language>/connections.php`, in English, German, French, Dutch and Spanish. `Connections\Strings::for($locale)` gives them with English for anything missing; the addons hand `all()` to their page and fill in `:name` parameters the same way.

## Precedence

`Connections\Connections` is the one resolver, and it is `Ai\Ports\Credentials`, so it goes wherever the environment's credentials went (`Providers`, `StockSearch`, the libraries):

1. **The environment (or config) wins.** When it sets a service's required field, every field of that service comes from there and nothing kept is read. The card says **Set in .env** (or **Set in config**, when the variable isn't in the environment but a config file sets the key) and hides Set up and Replace.
2. **Then what was set up on the page**, or got by signing in.
3. Otherwise none: **Not set up**.

Saving or disconnecting a service the environment sets is refused (`ConnectionRefused` `env-wins`). A handle Services doesn't know (`getty`) goes to the environment as it is.

## Storage

The `Connections\CredentialStore` port keeps values by name, and the addon **encrypts every value at rest**:

| Name | What |
| --- | --- |
| `<service>` | `{fields: {key, secret?}, saved_at, via: paste/connect/migrated}` |
| `health:<service>` | the note that a key stopped working: `{refused_at, fingerprint}` (a 16-character SHA-256 prefix of the key it was about) |
| `tokens:<library>` | a paid library's connected-account tokens (`StoredLibraryTokens`, the `LibraryTokens` port) |

`StoredProviderKeys` is the `ProviderKeys` port over it, so Connect with OpenRouter keeps its key on the same card (`via: connect`). `Connections::adopt()` moves keys kept somewhere older (the addons' migrations) without replacing anything already kept.

`Tests\Contracts\CredentialStoreContract` is what every store must do, including that no value is ever kept as plain text.

| Addon | Where |
| --- | --- |
| Statamic | the database table `ghostwriter_credentials` when the site has one and the migration ran; otherwise `storage/ghostwriter/credentials.json` (gitignored with `storage/`). Laravel's `Crypt` (APP_KEY). |
| Craft | the `ghostwriter_credentials` table, Craft's security component with the security key. Never project config. |
| Filament | the `ghostwriter_credentials` table (publishable migration), per workspace, Laravel's `Crypt`. |

## Status

`Connections::status($id)` gives a `Status`: `connected` ("Connected · key ending ••a1b2", `Mask::ending()`; never more than the last four characters), `not_set`, `env` (with `where`), `broken` ("Key stopped working") or `no_key` (Openverse).

**Key stopped working** is found on use: `KeyWatch` wraps the site's `HttpClients`. A 401 from a service's API host (`Services::HOSTS`), or a 400/403 that says the key is bad (Pixabay, Gemini), calls `markBroken()`; the next 2xx calls `markWorking()`. The note is about one key (its fingerprint), so a new key clears it. Downloads and other hosts are never watched, and watching never fails a call.

## Checking a key

`KeyCheck` (the `ChecksKeys` port) makes the one cheap call in the table above, straight to the service and tried once, with a provider's base URL where one is set. It answers a `CheckResult`: works, or a reason (`check.refused`, `check.unreachable`, `check.busy`, `check.missing`, `check.failed`) the page says in the editor's language. Nothing is logged, and the key never reaches a message.

`Testing\FakeKeyCheck` calls nobody: every key works except one with "wrong" in it (refused) or "offline" (unreachable). The addons use it only while an end-to-end fake scenario is playing on a local site (see each addon's `docs/testing.md`), when `Services::withTestServices()` also adds two test cards: **Test service** (never set in the environment) and **Test service (.env)** (`GHOSTWRITER_E2E_ENV_KEY`, a dummy value in the test sites' `.env`).

## Keys never reach Ghostwriter

A key goes only to the service it belongs to: in the check, and in every call afterwards. No key, masked or not, is sent anywhere else.
