# Connecting a library account ("Connect account")

Some paid libraries license only for a person's own signed-in account, through an OAuth user token (Shutterstock today; Adobe Stock's user auth later). Their `Capabilities::$needsOAuth` is true, and they implement `Images\Libraries\ConnectsAccount`. Each addon builds three control panel routes on it; core does everything the provider sees.

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
| Shutterstock | In their app at shutterstock.com/account/developers/apps, the **Callback URL** field is a comma-separated list of **host names and paths, not full URLs**, such as `cms.example.com/cp/ghostwriter/libraries/shutterstock/callback`. The `redirect_uri` sent "must use a host name that you set up in your application". `localhost` is the default, for testing. The settings row should show the host-and-path to paste. | Not documented, so not used. | Asked for with `expires=true`: an hour, then renewed with the refresh token and the secret, so a leaked token store alone gives an hour at most. Shutterstock has no revoke endpoint: Disconnect forgets the tokens, and the customer can delete the app to revoke them. Scopes: `user.view licenses.create licenses.view purchases.view`. Sign-in always goes to `api.shutterstock.com`, even in sandbox mode. |
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
