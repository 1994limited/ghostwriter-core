# Connecting a library account ("Connect account")

Some paid libraries license only for a person's own signed-in account, through an OAuth user token (Shutterstock today; Adobe Stock's user auth later). Their `Capabilities::$needsOAuth` is true, and they implement `Images\Libraries\ConnectsAccount`. Each addon builds three control panel routes on it; core does everything the provider sees.

Connecting a model provider (OpenRouter) works the same way, through its own port and store: see [below](#connecting-a-model-provider-connect-with-openrouter).

Both now sit inside a card on Settings → Connections ([connections.md](connections.md)), and keep what they get in the same encrypted store: `Connections\StoredProviderKeys` and `Connections\StoredLibraryTokens`.

## The interface

```php
namespace NineteenNinetyFour\Ghostwriter\Core\Images\Libraries;

interface ConnectsAccount extends PhotoLibrary
{
    public function authorizationUrl(string $state, string $redirectUri): string;

    /** @throws NotConnected */
    public function connect(string $code, string $redirectUri, string $state = ''): TokenSet;

    /** @throws NotConnected */
    public function refresh(TokenSet $tokens): TokenSet;

    public function connected(): bool;

    public function disconnect(): void;
}
```

- The library is built with the site's `LibraryTokens` (per site; per tenant in Filament). `connect()` and `refresh()` put the tokens there under the library's `id()`, and `disconnect()` forgets them. The addon never reads or writes them itself; it only encrypts them at rest, inside its `LibraryTokens`.
- The library refreshes expired tokens itself before any call that needs the account. `refresh()` is public for a "Check connection" button.
- Every failure is a `NotConnected` (a `PhotoUnavailable`) in plain words. No message holds a code, a token or a secret.

## A fixed token instead (Shutterstock)

A site that licenses through one company account can skip Connect account: the account owner clicks **Generate token** on their app's page at shutterstock.com/account/developers/apps, chooses the scopes `licenses.create`, `licenses.view`, `purchases.view` and `user.view`, and the token goes in `.env` (`SHUTTERSTOCK_API_TOKEN`), which the addon passes in as `new Shutterstock(..., token: ...)`. A `v2/` token doesn't expire (until the account's password or email address changes).

- `connected()` and `usesToken()` are true; every account call uses the token; `refresh()` hands it back as it is; a token kept through `LibraryTokens` is never used instead.
- `authorizationUrl()`, `connect()` and `disconnect()` throw `NotConnected`: "This site uses a token from its settings; remove it to connect an account instead." The settings row should show "Connected with a token from the settings" with no Connect or Disconnect button while `usesToken()`.
- A 401 or 403 is `NotConnected` saying the token is invalid or lacks those scopes (`Shutterstock::TOKEN_REFUSED`): show it on the settings row and in the License dialog, without "Connect again".
- By Shutterstock's docs, a production token also works against the sandbox (same applications, same authentication).

## The three routes

All three are control panel routes for people who may manage Ghostwriter's settings (Statamic `manage ghostwriter settings`, Craft admin or `ghostwriter:settings`, Filament `canManage`).

```php
// GET …/ghostwriter/libraries/{id}/connect
$state = bin2hex(random_bytes(16));
$session->put("ghostwriter.oauth.{$id}", $state);
return redirect($library->authorizationUrl($state, $callbackUrl));

// GET …/ghostwriter/libraries/{id}/callback?code=…&state=…
$expected = $session->pull("ghostwriter.oauth.{$id}");        // single use
if (! is_string($expected) || ! hash_equals($expected, (string) $request->query('state'))) {
    abort(403);                                                // or back to settings with an error
}
if ($request->query('error')) { /* the person said no: back to settings, "Not connected" */ }
try {
    $library->connect((string) $request->query('code'), $callbackUrl, $expected);
} catch (NotConnected $e) {
    // back to settings with $e->getMessage()
}

// POST …/ghostwriter/libraries/{id}/disconnect   (CSRF-protected)
$library->disconnect();
```

Rules:

1. **`state` is random, single use, kept in the CP session and checked with `hash_equals()`** before `connect()` is called. It is the CSRF guard for the callback. Pass the same value to `connect()`: where the provider supports PKCE, the library derives the verifier from it (`OAuth\Pkce`), so nothing else needs keeping between the two requests.
2. **`$callbackUrl` is absolute and identical** in both calls (scheme, host, path). Build it from the CP's own URL helper, never from the request's `Host` header.
3. The callback route must accept a GET from the provider while the person is signed in to the CP: it is a top-level navigation, so a `SameSite=Lax` session cookie is sent.
4. Show **Connect account** when `needsOAuth` and not `connected()`, and **Disconnect** when connected. The library's `available()` says only that its key and secret are set; searching doesn't need a connection.
5. `NotConnected` from `account()`, `quotes()` or `license()` later means the connection was lost (revoked, password changed): show "Connect again" in the License dialog and on the settings row.

## Each provider's callback rules

| Library | What the customer registers | PKCE | Tokens |
|---|---|---|---|
| Shutterstock | In their app at shutterstock.com/account/developers/apps, the **Callback URL** field is a comma-separated list of **host names and paths, not full URLs**, such as `cms.example.com/cp/ghostwriter/libraries/shutterstock/callback`. The `redirect_uri` sent "must use a host name that you set up in your application". `localhost` is the default, for testing. The settings row should show the host-and-path to paste. | Not documented, so not used. | Asked for with `expires=true`: an hour, then renewed with the refresh token and the secret, so a leaked token store alone gives an hour at most. Shutterstock has no revoke endpoint: Disconnect forgets the tokens, and the customer can delete the app to revoke them. Scopes: `user.view licenses.create licenses.view purchases.view`. A fixed token (`token:`) replaces all of this; see above. Sign-in always goes to `api.shutterstock.com`, even in sandbox mode. |
| Demo (`FakeLibrary`) | Nothing. `authorizationUrl()` returns the callback itself with `code` and `state`, as if the person had allowed access, so the whole flow runs in a browser. | Yes: the code is bound to the state and the callback address, so a different state or address is refused. | An hour, refreshed; `refusesRefresh` and `refusesConnect` script a revoked or refused connection. |

## Testing it in an addon

```php
$tokens = new InMemoryLibraryTokens;          // or the addon's own encrypted store
$library = new FakeLibrary(
    capabilities: Capabilities::paid(Capabilities::QUOTES_BALANCE, 30, needsOAuth: true, termsCheckedAt: '2026-10-02'),
    tokens: $tokens,
);

$this->get('/cp/ghostwriter/libraries/demo/connect')->assertRedirect();   // to …/callback?code=…&state=…
$this->get($redirectLocation)->assertRedirect('/cp/ghostwriter/settings');
$this->assertTrue($library->connected());
```

Feature tests should cover: a callback with a wrong or reused `state` is refused and nothing is kept; a refused code shows the message; Disconnect forgets the tokens; someone without the settings permission gets 403 on all three routes.

# Connecting a model provider ("Connect with OpenRouter")

People who don't want to create an API key can sign in to OpenRouter instead (see [providers.md](providers.md#openrouter)). It is OAuth with PKCE (S256) and ends in an ordinary OpenRouter API key for their account, which the addon keeps encrypted. Provider credentials are separate from library tokens: their own port, store and routes.

## The interfaces

```php
namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Credentials;

interface ConnectsProvider
{
    public function provider(): string;                                                     // 'openrouter'

    /** @throws ConnectFailed */
    public function authorizationUrl(string $state, string $redirectUri, string $codeChallenge): string;

    /** @throws ConnectFailed|ProviderException */
    public function connect(#[SensitiveParameter] string $code, #[SensitiveParameter] string $verifier): ConnectedKey;

    public function connected(): bool;                                                      // a connected key is kept
    public function usesEnvKey(): bool;                                                     // OPENROUTER_API_KEY is set, and wins

    /** @throws ConnectFailed */
    public function disconnect(): void;

    /** @throws NotConfigured|ProviderException */
    public function account(): ProviderAccount;                                             // "Check connection"
}

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Ports;

interface ProviderKeys                                                                      // the addon implements it, encrypted at rest
{
    public function get(string $provider): ?string;
    public function put(string $provider, #[SensitiveParameter] string $key): void;
    public function forget(string $provider): void;
}
```

Core's implementation is `Credentials\OpenRouterConnection`:

```php
$connection = new OpenRouterConnection(
    $envCredentials,                       // the addon's Credentials (the environment's keys alone)
    $providerKeys,                         // the addon's encrypted ProviderKeys
    $http,                                 // HttpClients
    keyLabel: 'Ghostwriter ('.$siteHost.')',
    baseUrl: $settings->baseUrl('openrouter'),
    logger: $logger,
);
```

Build `Providers` with `new ConnectedCredentials($envCredentials, $providerKeys)` so the connected key is used to write (see providers.md).

- `connect()` keeps the key through `ProviderKeys` under `openrouter` and returns a `ConnectedKey`. Its `masked()` (`sk-or-v1-a…789`) is for the settings row. The addon never reads or writes the key itself, apart from encrypting it at rest. `ConnectedKey` is masked in `var_dump()` and `print_r()` and refuses to be serialized. Every parameter that holds a code, a verifier or a key is `#[SensitiveParameter]`, so stack traces mask it.
- **`.env` always wins.** While `OPENROUTER_API_KEY` is set, `authorizationUrl()`, `connect()` and `disconnect()` throw `ConnectFailed` (`OpenRouterConnection::ENV_KEY_SET`), and `account()` describes the env key. The settings row should then say "Using OPENROUTER_API_KEY from .env" with no Connect or Disconnect button.
- Every failure is a `ProviderException` with plain words. No message holds a code, a verifier or a key.

## The three routes, plus Check connection

These are control panel routes for people who may manage Ghostwriter's settings, the same as for libraries.

```php
use NineteenNinetyFour\Ghostwriter\Core\Ai\Credentials\Pkce;

// GET …/ghostwriter/providers/openrouter/connect
$state = bin2hex(random_bytes(16));
$verifier = Pkce::verifier();
$session->put('ghostwriter.connect.openrouter', ['state' => $state, 'verifier' => $verifier]);
return redirect($connection->authorizationUrl($state, $callbackUrl, Pkce::challenge($verifier)));

// GET …/ghostwriter/providers/openrouter/callback?code=…&state=…
$kept = $session->pull('ghostwriter.connect.openrouter');           // single use
if (! is_array($kept) || ! hash_equals($kept['state'], (string) $request->query('state'))) {
    abort(403);                                                      // or back to settings with an error
}
if (! $request->query('code')) { /* the person went back without allowing: "Not connected" */ }
try {
    $key = $connection->connect((string) $request->query('code'), $kept['verifier']);
    // back to settings: "Connected to OpenRouter ({$key->masked()})."
} catch (ProviderException $e) {
    // back to settings with $e->getMessage()
}

// POST …/ghostwriter/providers/openrouter/disconnect   (CSRF-protected)
$connection->disconnect();

// POST …/ghostwriter/providers/openrouter/check        (CSRF-protected), "Check connection"
try {
    $line = $connection->account()->summary();   // "Connected. $74.50 of $100.00 left (resets monthly)."
} catch (ProviderException $e) {
    $line = $e->getMessage();                    // e.g. a refused key: "Connect with OpenRouter again"
}
```

Rules:

1. **`state` and the verifier are random, single use and kept together in the CP session.** Check `state` with `hash_equals()` before calling `connect()`. OpenRouter hands `state` back on the callback. A code lasts 10 minutes and works once, so `connect()` never retries the exchange.
2. **`$callbackUrl` must be `https://`, or `http://localhost` on any port**, which is OpenRouter's rule. `authorizationUrl()` throws `ConnectFailed` for anything else. Build it from the CP's own URL helper, never from the request's `Host` header. Nothing needs registering at OpenRouter.
3. The callback is a top-level GET from openrouter.ai, so a `SameSite=Lax` session cookie is sent.
4. **Disconnect only forgets the key.** OpenRouter has no way to revoke a key with the key itself. The settings row should say "To revoke it, delete the key at openrouter.ai/settings/keys."

## The settings row

| State | Shows | Buttons |
|---|---|---|
| `usesEnvKey()` | "Using OPENROUTER_API_KEY from .env" | Check connection |
| `connected()` | "Connected to OpenRouter (`maskedKey()`)" | Check connection, Disconnect |
| neither | "Not connected" and one line: "Sign in to OpenRouter to use Claude, GPT or Gemini with one account, paid for with OpenRouter credit." | **Connect with OpenRouter** |

Under it, when OpenRouter is the text or image provider, add the privacy note: "Requests, including images, pass through OpenRouter on their way to the model's company." There is also an optional model choice per tier (`Models::OPENROUTER_TEXT_CHOICES`, `Ports\ModelTiers`).

`ProviderAccount` gives `source` (`env` or `connected`), `label`, `limit`, `remaining`, `usage`, `limitReset`, `freeTier`, `summary()`, `exhausted()` and `toArray()`. OpenRouter counts in credits, which are US dollars. A key with no limit shows its usage only. Running out of account credit shows up on the next call as `OutOfCredit`: "Your OpenRouter credit has run out."

## Testing it in an addon

```php
$keys = new InMemoryProviderKeys;                         // or the addon's own encrypted store
$connection = new FakeOpenRouter($keys, $envCredentials);  // bind it where OpenRouterConnection would go

$this->get('/cp/ghostwriter/providers/openrouter/connect')->assertRedirect();   // to …/callback?code=…&state=…
$this->get($redirectLocation)->assertRedirect('/cp/ghostwriter/settings');
$this->assertTrue($connection->connected());
```

`FakeOpenRouter` sends the person straight back to the callback with a `code` and the `state`. Like OpenRouter, the code works once and only with the verifier its challenge came from. `refuseCode` scripts a refused sign-in, and `refuseKey` scripts a key that OpenRouter no longer accepts in `account()`.

Feature tests should cover:
- a callback with a wrong or reused `state` is refused, and nothing is kept;
- a refused code shows the message;
- Disconnect forgets the key;
- with `OPENROUTER_API_KEY` set, Connect and Disconnect are refused and the env key is used;
- the stored key is encrypted;
- someone without the settings permission gets 403 on every route.
