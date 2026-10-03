# Photo search: wiring it into an addon

Core 0.2.0 holds the whole "Find a photo" flow that the three addons each had a copy of (`StockSearch`, `ImageStudio`/`ImagePicker`'s shortlist and judging). An addon now does three things only:

1. **Describes the place** the photo is for, as a `PhotoContext`.
2. **Hands over the images already in that place**, as bytes or `Ai\Image`s (none is fine).
3. **Stores the photo** that's chosen, with its credit, title and alt text.

Everything between (choosing searches, searching, judging, the second round, safe downloads) is core's.

## What changed for users (decisions D2 and D4)

- **Photos are judged even when the field has no reference images.** The model checks each candidate's subject against the block's and page's words and the library's own description of the photo, and clear misses are left out. With references it also matches their style, as before.
- **When nothing fits, a second round of searches runs** (Statamic had this; Filament and Craft didn't), with or without references. If that finds nothing either, the unranked results come back with `noneFit` set, so the UI can say so.
- **"Best match" only when a model judged.** `Photo::$picked` is never set on unranked results. Statamic and Craft stamped the badge on unranked photos; follow `picked` and that stops.
- **The library's own words are kept:** Unsplash's `alt_description` and caption, Pexels' `alt` and the title in its address, Pixabay's `tags`, Openverse's `title` and `tags`. `alt()`, `assetTitle()` and `filenameBase()` use them, falling back to the search term.

## Building the services

Build these once and share them, next to `Providers` (a container singleton, a plugin component):

```php
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoFinder;
use NineteenNinetyFour\Ghostwriter\Core\Images\StockSearch;

$stock = new StockSearch(
    $http,                                  // the same HttpClients as Providers
    $credentials,                           // keys: unsplash, pexels, pixabay (Credentials::ENV names the env variables)
    openverse: fn () => $settings->openverse,   // a bool, or a callable read on each search
    logger: $logger,                        // a library failing is logged at warning; optional
);

$finder = new PhotoFinder($stock, $providers, $prompts, $logger);
```

- `$providers` may be the `Providers` registry (its text model is used when it has a key), a `TextProvider`, or `null` to search without a model.
- `$prompts` is the addon's `PromptLibrary`, so a site's override of `photo-researcher` or `photo-picker` is used.

### HTTP clients

`StockSearch` fetches from addresses the libraries hand out, so it follows redirects itself (https only, at most three, never to a private or local address) and drops the API key when a redirect leaves the host. For that its clients must hand redirects back rather than follow them:

| Addon | What to pass |
|---|---|
| Statamic, Filament | `new GuzzleHttpClients()`: it implements `Ports\DownloadClients` already. |
| Craft | The wrapper around `Craft::createGuzzleClient()` should implement `Ports\DownloadClients` too: `downloadClient($timeout, $stream)` returns a client built with `['timeout' => $timeout, 'allow_redirects' => false, 'stream' => $stream]`. Without it, core still works, but Guzzle follows redirects (http ones included) out of core's sight. |

With a Guzzle client, thumbnails are fetched side by side; with any other PSR-18 client, one after another.

## Finding photos

```php
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoContext;

$context = PhotoContext::make(
    title: $entryTitle,
    label: 'Hero: Image',               // where the picture goes, as the person sees it
    blockText: $wordsInThatBlock,
    pageText: $restOfThePage,
    summary: $summary,                  // optional
    shape: 'landscape',                 // from the references, as before; or a Shape
    style: $imageryGuide->for($section), // the site's own description of its images; optional
);

$results = $finder->find($context, $referenceBytes, $typed ?: null);
```

- `$referenceBytes`: the images in that place on other entries (the addon's `ImageSampler`), up to three are shown. Core makes them small (Imagick, then GD); pass raw file contents.
- `$typed`: what the person typed, as a string with semicolons or a list, or `null` to have the `photo-researcher` agent choose three searches from the words. Without a model, the page title is searched.
- `find()` throws `ProviderException` only when the searches are being chosen and that call fails. Judging never throws: a failed call leaves the results unranked, logged at warning.

`PhotoResults` gives the photos in order and how they were chosen:

| | |
|---|---|
| `photos` / iterate | `Photo`s, best first. When judged, only those that fit. |
| `picked()` | The shortlist (up to three, from different searches). Empty unless judged. Badge these. |
| `judged` | A model compared them with the page. |
| `withReferences` | …and with the images already there, so style was matched too. When false and judged, say "Compared with the page; there are no other images here to match." |
| `noneFit` | Nothing fitted, even after a second round. The photos are the unranked results. Say so. |
| `retried` | A second round of searches ran. |
| `terms` | Every search run, for "Searched for: …". |

`toArray()` is ready for JSON. Each photo keeps the keys the addons' UIs already read (`source`, `id`, `thumb`, `credit`, `credit_url`, `licence`, `term`, `picked`) plus `title`, `description`, `tags`, `width`, `height`, `reason`, `alt` and `asset_title`. `Photo::fromArray()` reads it back, older arrays included, for a queue job or a session.

## Keeping the chosen photo

```php
$file = $stock->fetch($source, $id);   // PhotoUnavailable (an InvalidArgumentException) with a readable message if it can't be had

$file->content;     // bytes, checked: JPEG, PNG or WebP, at most StockSearch::MAX_BYTES (15 MB)
$file->extension;   // jpg, png or webp, from the bytes
$file->photo;       // looked up again from the library: credit, credit URL, licence, title, description

$name  = $file->photo->filenameBase().'-'.$random.'.'.$file->extension;   // "brown-rocks-at-golden-hour-x7k2qa.jpg"
$title = $file->photo->assetTitle();   // "Brown rocks at golden hour"
$alt   = $file->photo->alt();          // "Brown rocks during golden hour", at most 125 characters
```

`fetch()` never takes an address from the browser: it looks the photo up by source and ID, and reports Unsplash downloads as Unsplash asks. Pass `$fallback` to the helpers to fall back to something other than the search term (`filenameBase($entryTitle)`).

Where each CMS puts these stays in the addon:

| | Statamic | Filament | Craft |
|---|---|---|---|
| File name | `FieldImages::keep()` / `ImageStudio::store()` | `ImagePicker::keep()` | `ImagePicker::keep()` |
| Title | asset `title` | (a path only) | `$asset->title` |
| Alt text | the container's `alt` field, if the blueprint has one | (none) | `$asset->alt` |
| Credit | asset data `credit`, `credit_url`, `licence` | (not saved) | the credit-like text field |

## Photo libraries

Since 1.1.0 each library is a `Libraries\PhotoLibrary`, and `StockSearch` is a set of them. Its public API is unchanged: `sources()`, `available()`, `search()`, `fetch()` and `thumbnails()` work as before, and the four free libraries are always there (`Libraries\Free\Unsplash`, `Pexels`, `Pixabay`, `Openverse`).

```php
$stock = new StockSearch($http, $credentials, openverse: $setting, logger: $logger, libraries: [$getty]);

$stock->libraries();                    // every library by ID, available or not
$stock->library('getty')?->capabilities()->quotes;
$stock->label('getty');                 // "Getty Images"
$stock->search('pottery', 'landscape', sources: ['getty']);   // only these libraries
```

- A library passed in comes after the free ones; one with a free library's ID replaces it (a demo library, say).
- `Capabilities` says what a library is: `free`, `mayRank`, `noModelInput` (no asset from it may go to any model, for any feature: paid libraries), `needsOAuth`, `quotes` (`exact`, `balance`, `credits` or `none`), `previewKeepDays` and `previewStorage` (how long, and whether, a comp may be kept), `termsCheckedAt`, `editorial`, `creditRequired` and `sandbox`.
- A paid photo carries an `Offer` (`free`, a `Cost` hint such as "1 download", its licence type and products), and `editorial`, `restrictions` and `collection`. `Photo::toArray()` adds those keys only when they are set, so a free photo's array is unchanged. A photo with no offer is free (`Photo::isFree()`).
- **A model never sees a photo whose library doesn't allow it.** Paid libraries' licences forbid using their content or its metadata for AI, so `PhotoRanker` leaves their photos out of what it sends: they come after the judged ones, in their library's own order, unjudged and never picked. `StockSearch::mayRank($photo)` decides. The free libraries' photos are judged as before.
- `fetch()` hands over a free library's file only; a paid library refuses.

Each adapter's test uses the `tests/Images/Libraries/LibraryContract.php` trait over `ImagesTestCase`'s mocked network: search maps IDs, thumbnails and offers; `photo()` refuses IDs that aren't the library's without asking it; no error holds a key; the capabilities hang together.

## Licensing (paid libraries)

A paid library implements `Libraries\LicensableLibrary` (which extends `PreviewableLibrary`): `preview($id)` (the comp), `account()`, `quotes($id)`, `license($id, $quote, $key, $licensedBy)`, `download($licence)` and `findLicences($id)`. The customer always licenses from their own account with their own key; nothing goes through 1994.

The whole "License & replace" is `Domain\Stock\StockImages::license()`:

```php
$image = $stockImages->license($ledgerId, $library, $quote, $replacer, new Person($user->id, $user->name));
```

1. The ledger record is saved as `licensing` first, so a second press (or a second editor) is refused with a `Conflict`.
2. The library is asked **once**. `LicensingUncertain` (the call may have charged) leaves the record `licensing`; tell the editor not to buy it again, and let `reconcile()` settle it from `findLicences()`. A plain refusal (`InsufficientBalance`, `QuoteChanged` with the new quote, `LicenceRefused`, `NotConnected`) marks it `failed`, and it may be tried again.
3. The licence is recorded before anything else can fail.
4. The licensed file goes to the addon's `AssetReplacer`, byte for byte (never re-encode it: the licences require its embedded copyright and IDs). If that fails, the record stays licensed and not replaced; `replaceAgain()` finishes it without buying again.

All the licensing errors extend `PhotoUnavailable`, so existing catches still work.

`Downloader::post()` sends token and licence calls exactly once, never retried or redirected; with `purchase: true`, anything that leaves the outcome unknown is `LicensingUncertain`. `LibraryTokens` is the port for a site's cached and user tokens (`OAuth\TokenSet`, masked in dumps); the addon encrypts them. `StandIn::jpeg($width, $height, $label)` draws the public-safe stand-in at the photo's aspect ratio.

A library that needs the customer's own signed-in account (`Capabilities::$needsOAuth`) also implements `Libraries\ConnectsAccount`: `authorizationUrl($state, $redirectUri)`, `connect($code, $redirectUri, $state)`, `refresh($tokens)`, `connected()` and `disconnect()`, keeping its tokens through `LibraryTokens`. The addons' "Connect account" routes and their rules are in [connecting-accounts.md](connecting-accounts.md).

### Shutterstock (`Libraries\Paid\Shutterstock`)

```php
$shutterstock = new Shutterstock(
    $http,
    key: env('SHUTTERSTOCK_API_KEY', ''),         // the addon reads the environment; core never does
    secret: env('SHUTTERSTOCK_API_SECRET', ''),
    tokens: $libraryTokens,                        // the site's encrypted LibraryTokens
    sandbox: (bool) env('SHUTTERSTOCK_SANDBOX', false),
    editorial: $settings->includeEditorial,        // off by default
    token: env('SHUTTERSTOCK_API_TOKEN') ?: null,  // optional: a fixed token instead of Connect account
);
$stock = new StockSearch($http, $credentials, libraries: [$shutterstock]);
```

- The customer needs a Shutterstock **API** subscription (a shutterstock.com web plan can't license through the API) and their own app at shutterstock.com/account/developers/apps, whose consumer key and secret go in `.env`.
- **Search** needs only the key and secret (basic auth). With a token (the fixed one, or a connected account's), `search()`, `photo()` and `preview()` are made as the user instead, so results are what the account can license: a free API subscription can license only the Free collection, and Shutterstock narrows a search to it only for a user-token search. If the token is refused (401, 403), a search is made again with basic auth and logged at debug (pass a PSR-3 logger as the last argument); a look-up isn't. **Licensing** needs a connected account: it is a `ConnectsAccount` ([connecting-accounts.md](connecting-accounts.md)), or a fixed token. A licence Shutterstock refuses because the plan doesn't cover the image or the API terms aren't accepted (it says "Terms of Service must be accepted", in a 200 answer) is `LicenceRefused` with `Shutterstock::LICENCE_NOT_COVERED`.
- **A fixed token** (`token:`, 1.x addition) is an alternative to Connect account for a site that licenses through one company account. The account owner opens their app at shutterstock.com/account/developers/apps, clicks **Generate token** under *Token*, and chooses these scopes on *Select scopes*: `licenses.create`, `licenses.view`, `purchases.view` and `user.view` (checked against api-reference.shutterstock.com on 2026-10-03: `GET /v2/user/subscriptions` needs `purchases.view`, `POST /v2/images/licenses` `licenses.create` and `purchases.view`, the licence history and redownloads `licenses.view`, `GET /v2/user` `user.view`). Such a `v2/` token doesn't expire; Shutterstock says it lasts until the account changes its password or email address. While it is set, `connected()` is true, `usesToken()` is true, every account call (`account()`, `quotes()`, `license()`, `download()`, `findLicences()`) sends it, `refresh()` hands it back without asking anything, a token kept by Connect account is never used, and `authorizationUrl()`, `connect()` and `disconnect()` throw `NotConnected` ("This site uses a token from its settings; remove it to connect an account instead."). A 401 or 403 with it is `NotConnected` saying the token is invalid or lacks those scopes (`Shutterstock::TOKEN_REFUSED`). It is masked in `var_dump()` and `print_r()`, as a `TokenSet` is. Keep it in `.env` (`SHUTTERSTOCK_API_TOKEN`), never in the database.
- **No comp is ever stored.** `preview()` returns `Preview::linked()` with Shutterstock's own watermarked preview address (`previewStorage: none`); the CP comp route redirects to it, for signed-in users only. Shutterstock's licence has no comp licence for still images. Its archived API terms also asked for "Powered by Shutterstock" wherever previews are shown; show it beside Shutterstock results and previews.
- Costs are the subscription's allotment ("1 download"); `account()` and `quotes()` read `/v2/user/subscriptions`. Each quote is a subscription and a JPEG size (`huge`, `medium`, `small`), largest first.
- `license()` sends the ledger ID as `metadata.customer_id` (Shutterstock has no idempotency key) and is never retried; `findLicences()` reads the licence history, so `reconcile()` matches an uncertain call by that ID. The download address (8 hours) is kept only in memory; `download()` later asks for a redownload, which doesn't charge.
- Editorial images are off unless `editorial: true`. An editorial photo's quotes are marked editorial, and only such a quote sends `editorial_acknowledgement`.
- `sandbox: true` points every API call at `api-sandbox.shutterstock.com`: licensing charges nothing and returns a watermarked file, and editorial licensing isn't available there. **A token generated for production works there too**, by Shutterstock's docs: the sandbox "uses the same applications and it requires the same authentication and subscriptions as the main API", and its examples send the same `SHUTTERSTOCK_API_TOKEN` (a `v2/` token) to it.
- `mayRank` is false and `noModelInput` true: no model sees its photos, metadata or files until counsel clears it.
- `php tools/smoke/shutterstock.php [.env] [words]` runs one live search, look-up and preview against the sandbox on a developer's machine (default `.env`: `~/Dev/gw-test-filament/.env`). When `SHUTTERSTOCK_API_TOKEN` is set there too, it also makes one `account()` call with that token against the sandbox and prints only the subscription count and each one's downloads left; otherwise it says the token isn't set. It never prints the key, secret or token, saves nothing, and doesn't license. `tools/` isn't shipped; never commit what it shows.

`Libraries\Testing\FakeLibrary` is a scripted paid library: photos, quotes and licence outcomes (`SUCCEED`, `UNCERTAIN_CHARGED`, `UNCERTAIN_NOT_CHARGED`, or an exception), GD-drawn comps, and a record of every call (`licenceCalls($id)`). It is also the addons' demo library. `Domain\Testing\MemoryAssetReplacer` and `Libraries\Testing\InMemoryLibraryTokens` go with it.

## Testing

`StockSearch` takes `Testing\MockHttpClient` (it is a `DownloadClients` too, and records whether each download asked to stream), and `PhotoFinder` and `PhotoRanker` take a `FakeProvider` directly:

```php
$fake = new FakeProvider;
$fake->respond('photo-researcher', 'raised brick beds; garden bed; brick planter');
$fake->respond('photo-picker', "4: brick beds full of herbs\n1: raised beds", '...');   // or 'none: a; b; c'

$results = (new PhotoFinder($stock, $fake, $prompts))->find($context, []);
$fake->assertSent('photo-picker', fn (TextRequest $r) => str_contains($r->prompt, 'There are no reference images'));
```

The picker's answer format: one line per photo that fits, `number: why`, best first; or `none: search; search; search`. A plain list of numbers (`4, 1, 7`), as older site overrides of the prompt ask for, is still read.

## Not in 0.2.0

- `LogoCard`, `Placeholders` and `ImageRequests` (core plan section 6) are still in the addons.
- No text-only judging for a model that can't see images: all three providers' default models can.
- `ImageSampler` (finding a field's existing images) stays in each addon, as planned.
