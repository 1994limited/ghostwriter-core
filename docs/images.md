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
- `Capabilities` says what a library is: `free`, `mayRank`, `needsOAuth`, `quotes` (`exact`, `balance`, `credits` or `none`), `previewKeepDays` and `previewStorage` (how long, and whether, a comp may be kept), `termsCheckedAt`, `editorial`, `creditRequired` and `sandbox`.
- A paid photo carries an `Offer` (`free`, a `Cost` hint such as "1 download", its licence type and products), and `editorial`, `restrictions` and `collection`. `Photo::toArray()` adds those keys only when they are set, so a free photo's array is unchanged. A photo with no offer is free (`Photo::isFree()`).
- **A model never sees a photo whose library doesn't allow it.** Paid libraries' licences forbid using their content or its metadata for AI, so `PhotoRanker` leaves their photos out of what it sends: they come after the judged ones, in their library's own order, unjudged and never picked. `StockSearch::mayRank($photo)` decides.
- `fetch()` hands over a free library's file only; a paid library refuses.

Each adapter's test uses the `tests/Images/Libraries/LibraryContract.php` trait over `ImagesTestCase`'s mocked network: search maps IDs, thumbnails and offers; `photo()` refuses IDs that aren't the library's without asking it; no error holds a key; the capabilities hang together.

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
