<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Paid;

use Closure;
use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\HttpClients;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Shape;
use NineteenNinetyFour\Ghostwriter\Core\Images\Downloader;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\InsufficientBalance;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\LicenceRefused;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\LicensingUncertain;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\NotConnected;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\QuoteChanged;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Account;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Capabilities;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\ConnectsAccount;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Cost;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Licence;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\LicensableLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\OAuth\TokenSet;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Offer;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Ports\LibraryTokens;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Preview;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Quote;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\SearchQuery;
use NineteenNinetyFour\Ghostwriter\Core\Images\Photo;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoFile;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoUnavailable;
use NineteenNinetyFour\Ghostwriter\Core\Text\Slug;
use SensitiveParameter;

/**
 * Shutterstock, licensed from the customer's own API subscription (a
 * shutterstock.com web plan can't license through the API) with their own
 * app's consumer key and secret, which the addon passes in from
 * SHUTTERSTOCK_API_KEY and SHUTTERSTOCK_API_SECRET. Nothing here reads the
 * environment.
 *
 * - **Search and look-ups** use basic auth with the key and secret: no
 *   connected account is needed to search.
 * - **Licensing** needs an OAuth user token with the licenses.create,
 *   licenses.view, purchases.view and user.view scopes, got through
 *   ConnectsAccount ("Connect account"). Tokens are asked for with
 *   `expires=true`: an hour, renewed with the refresh token and the
 *   secret. Shutterstock documents no PKCE and no revoke endpoint.
 * - **Comps are never stored**: Shutterstock's licence has no comp licence
 *   for still images, so preview() hands back only its own watermarked
 *   preview address (Capabilities::STORAGE_NONE), for the control panel.
 * - **Cost** is the subscription's allotment: "1 download", never money.
 *   account() and quotes() come from /v2/user/subscriptions.
 * - **license()** is sent once (Downloader::post(), purchase: true) with
 *   the ledger record's ID as `metadata.customer_id`. Shutterstock has no
 *   idempotency key, but it returns that metadata in the licence history,
 *   so findLicences() matches an uncertain call to its record by it.
 * - **Editorial** images are off unless `$editorial`; licensing one needs
 *   a quote marked editorial, which sends `editorial_acknowledgement`.
 * - `$sandbox` points every API call at api-sandbox.shutterstock.com,
 *   where licensing charges nothing, returns a watermarked file and
 *   doesn't do editorial. Sign-in always goes to api.shutterstock.com.
 */
final class Shutterstock implements ConnectsAccount, LicensableLibrary
{
    public const API = 'https://api.shutterstock.com';

    public const SANDBOX_API = 'https://api-sandbox.shutterstock.com';

    /** The scopes asked for at sign-in. */
    public const SCOPES = ['user.view', 'licenses.create', 'licenses.view', 'purchases.view'];

    /** When the terms this adapter follows were last read. */
    public const TERMS_CHECKED_AT = '2026-10-02';

    /**
     * How long a preview stays in the slot before it reads "Preview
     * expired", as for the other libraries. Nothing is stored: this only
     * bounds how long Shutterstock's own preview address is shown.
     */
    public const PREVIEW_DAYS = 30;

    /** How long a licence's download address lasts. */
    public const DOWNLOAD_HOURS = 8;

    public const LICENCE_TERMS = 'https://www.shutterstock.com/license';

    public const EDITORIAL_RESTRICTIONS = 'Editorial use only: not for commercial, promotional, advertorial or endorsement use. Credit: Artist/Shutterstock.com.';

    /** The image sizes offered, largest first: the first is the default. */
    private const SIZES = ['huge', 'medium', 'small'];

    private readonly Downloader $downloader;

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $clock;

    /** @var array<string, string> Download addresses from licences bought in this request, by licence ID. Never kept. */
    private array $downloads = [];

    /**
     * @param  (Closure(): DateTimeImmutable)|null  $clock
     */
    public function __construct(
        HttpClients $http,
        private readonly string $key,
        #[SensitiveParameter] private readonly string $secret,
        private readonly LibraryTokens $tokens,
        private readonly bool $sandbox = false,
        private readonly bool $editorial = false,
        ?Closure $clock = null,
    ) {
        $this->downloader = new Downloader($http);
        $this->clock = $clock ?? fn () => new DateTimeImmutable;
    }

    public function id(): string
    {
        return 'shutterstock';
    }

    public function label(): string
    {
        return $this->sandbox ? 'Shutterstock (sandbox)' : 'Shutterstock';
    }

    public function capabilities(): Capabilities
    {
        return Capabilities::paid(
            Capabilities::QUOTES_BALANCE,
            self::PREVIEW_DAYS,
            Capabilities::STORAGE_NONE,
            needsOAuth: true,
            // Its AI clause is narrower than Getty's ("create, train, test,
            // or otherwise improve" models), but until counsel clears it no
            // model sees its photos, metadata or files.
            mayRank: false,
            termsCheckedAt: self::TERMS_CHECKED_AT,
            editorial: true,
            creditRequired: false,
            sandbox: true,
            noModelInput: true,
        );
    }

    public function available(): bool
    {
        return trim($this->key) !== '' && trim($this->secret) !== '';
    }

    public function search(SearchQuery $query): array
    {
        if (! $this->available()) {
            throw new PhotoUnavailable('Shutterstock has no API key and secret set.');
        }

        $editorial = $this->editorial && $query->editorial;
        $params = [
            'query' => $query->term,
            'page' => $query->page,
            'per_page' => $query->perPage,
            'image_type' => 'photo',
            'sort' => 'relevance',
            'view' => 'full',
        ] + match ($query->shape) {
            Shape::Landscape => ['orientation' => 'horizontal'],
            Shape::Portrait => ['orientation' => 'vertical'],
            Shape::Square => ['aspect_ratio_min' => 0.9, 'aspect_ratio_max' => 1.1],
        };

        // `license` is a repeated parameter: commercial only, unless editorial is allowed and asked for.
        $url = $this->api('/v2/images/search').'?license=commercial'.($editorial ? '&license=editorial' : '');
        $data = $this->downloader->json($url, $params, $this->basic(), label: 'Shutterstock');
        $photos = [];

        foreach (is_array($data['data'] ?? null) ? $data['data'] : [] as $item) {
            $photo = is_array($item) ? $this->result($item) : null;

            if ($photo !== null && ($editorial || ! $photo->editorial)) {
                $photos[] = $photo->withTerm($query->term);
            }
        }

        return $photos;
    }

    public function photo(string $id): Photo
    {
        return $this->lookup($id)[0];
    }

    public function fetch(string $id): PhotoFile
    {
        throw new LicenceRefused('A Shutterstock photograph must be licensed before it can be used.');
    }

    /**
     * Shutterstock's own watermarked preview address, never its bytes:
     * its licence has no comp licence for still images.
     */
    public function preview(string $id): Preview
    {
        [, $data] = $this->lookup($id);
        $url = $this->string($data, 'assets', 'preview_1500', 'url')
            ?? $this->string($data, 'assets', 'preview_1000', 'url')
            ?? $this->string($data, 'assets', 'preview', 'url');

        if ($url === null || ! $this->downloader->secure($url)) {
            throw new PhotoUnavailable('Shutterstock has no preview of that photograph.');
        }

        return Preview::linked($url, self::PREVIEW_DAYS, true, ($this->clock)());
    }

    public function account(): Account
    {
        $token = $this->token();
        $products = [];

        foreach ($this->subscriptions($token) as $subscription) {
            $left = $subscription['allotment']['downloads_left'] ?? null;
            $products[] = [
                'id' => (string) $subscription['id'],
                'type' => $this->string($subscription, 'license'),
                'name' => $this->string($subscription, 'description') ?? $this->string($subscription, 'product_description') ?? (string) $subscription['id'],
                'remaining' => is_numeric($left) ? Cost::units(max(0, (int) $left), Cost::DOWNLOAD) : null,
                'resetsAt' => $this->date($subscription['allotment']['end_time'] ?? null),
                'termEndsAt' => $this->date($subscription['expiration_time'] ?? null),
            ];
        }

        try {
            $user = $this->downloader->json($this->api('/v2/user'), headers: $this->bearer($token), label: 'Shutterstock', account: true);
            $name = $this->string($user, 'username');
        } catch (PhotoUnavailable) {
            $name = null;
        }

        return new Account($this->id(), $name, $products);
    }

    /**
     * One option per image subscription with downloads left and per JPEG
     * size it offers, largest first. An editorial photo's options are
     * marked so (licensing one acknowledges the editorial agreement).
     */
    public function quotes(string $id): array
    {
        $this->checkId($id);

        return $this->options($id, $this->token())[0];
    }

    /**
     * The quotes, and the subscriptions they come from.
     *
     * @return array{0: array<int, Quote>, 1: array<int, array<string, mixed>>}
     *
     * @throws PhotoUnavailable
     */
    private function options(string $id, TokenSet $token): array
    {
        [$photo] = $this->lookup($id);

        if ($photo->editorial && ! $this->editorial) {
            throw new LicenceRefused('That photograph is for editorial use only, and editorial images from Shutterstock are switched off.');
        }

        $subscriptions = $this->subscriptions($token);
        $quotes = [];
        $empty = false;

        foreach ($subscriptions as $subscription) {
            $left = $subscription['allotment']['downloads_left'] ?? null;

            if (is_numeric($left) && (int) $left <= 0) {
                $empty = true;

                continue;
            }

            $formats = [];

            foreach (is_array($subscription['formats'] ?? null) ? $subscription['formats'] : [] as $format) {
                $size = is_array($format) ? $this->string($format, 'size') : null;

                if ($size !== null && in_array($size, self::SIZES, true)
                    && in_array($this->string($format, 'media_type') ?? 'image', ['image'], true)
                    && in_array($this->string($format, 'format') ?? 'jpg', ['jpg', 'jpeg'], true)) {
                    $formats[$size] = $format;
                }
            }

            uksort($formats, fn (string $a, string $b) => array_search($a, self::SIZES, true) <=> array_search($b, self::SIZES, true));
            $name = $this->string($subscription, 'description') ?? $this->string($subscription, 'product_description') ?? 'Shutterstock subscription';
            $licence = $this->string($subscription, 'license');

            foreach ($formats as $size => $format) {
                $pixels = is_numeric($format['min_resolution'] ?? null) ? ' ('.(int) $format['min_resolution'].' px)' : '';
                $quotes[] = new Quote(
                    $id,
                    $subscription['id'].':'.$size.($photo->editorial ? ':editorial' : ''),
                    $name.' · '.($this->string($format, 'description') ?? ucfirst($size)).$pixels.($photo->editorial ? ' · editorial' : ''),
                    Cost::units(1, Cost::DOWNLOAD),
                    $licence,
                    $size,
                    $licence !== null && str_contains(strtolower($licence), 'enhanced'),
                    terms: $photo->editorial ? self::EDITORIAL_RESTRICTIONS : self::LICENCE_TERMS,
                );
            }
        }

        if ($quotes === [] && $empty) {
            throw new InsufficientBalance('Your Shutterstock subscription has no downloads left.');
        }

        return [$quotes, $subscriptions];
    }

    public function license(string $id, Quote $quote, string $key, string $licensedBy): Licence
    {
        $this->checkId($id);
        $token = $this->token();

        if ($quote->photoId !== $id) {
            throw new QuoteChanged('That licence option is for another photograph. Check it and try again.');
        }

        // Asked again, so the option, the balance and the editorial flag are as they are now.
        [$current, $subscriptions] = $this->options($id, $token);
        $same = null;

        foreach ($current as $option) {
            if ($option->option === $quote->option) {
                $same = $option;
            }
        }

        if ($same === null || $same->cost?->toArray() !== $quote->cost?->toArray()) {
            throw new QuoteChanged('That licence option has changed since you confirmed it. Check it and try again.', $same ?? $current[0] ?? null);
        }

        [$subscriptionId, $size, $editorial] = $this->option($same->option);
        $image = [
            'image_id' => $id,
            'subscription_id' => $subscriptionId,
            'size' => $size,
            // An API subscription must send a price and a customer ID; we
            // don't resell, so the price is 0, and the customer ID is the
            // ledger record's ID, which the licence history gives back.
            'price' => 0,
            'metadata' => ['customer_id' => $key],
        ] + ($editorial ? ['editorial_acknowledgement' => true] : []);

        $answer = $this->downloader->post($this->api('/v2/images/licenses'), ['images' => [$image]], $this->bearer($token), timeout: 90, label: 'Shutterstock', purchase: true);
        $item = is_array($answer['data'][0] ?? null) ? $answer['data'][0] : null;
        $error = $item !== null ? $this->string($item, 'error') : $this->firstError($answer);

        if ($error !== null) {
            throw preg_match('/allotment|downloads? (left|limit|remaining)|insufficient|no (more )?downloads|quota|credits?|exceeded/i', $error)
                ? new InsufficientBalance('Your Shutterstock subscription has nothing left to license this with.')
                : new LicenceRefused('Shutterstock wouldn\'t license that photograph: '.$this->plain($error));
        }

        $url = $item !== null ? $this->string($item, 'download', 'url') : null;

        if ($item === null || $url === null) {
            // A 200 that doesn't say what was bought: it may have charged.
            throw new LicensingUncertain;
        }

        $now = ($this->clock)();
        $orderId = $this->string($item, 'license_id') ?? $this->licenceIdFor($id, $key) ?? "image-{$id}-{$now->getTimestamp()}";
        $this->downloads[$orderId] = $url;
        $charge = $item['allotment_charge'] ?? null;
        $endsAt = null;

        foreach ($subscriptions as $subscription) {
            if ($subscription['id'] === $subscriptionId) {
                $endsAt = $this->date($subscription['expiration_time'] ?? null);
            }
        }

        unset($item['download']);

        return new Licence(
            $this->id(),
            $id,
            $orderId,
            $now,
            $licensedBy,
            $same->option,
            is_numeric($charge) ? Cost::units((int) $charge, Cost::DOWNLOAD) : $same->cost,
            ! is_numeric($charge),
            'Shutterstock.com',
            $editorial ? Offer::EDITORIAL : Offer::ROYALTY_FREE,
            $editorial ? self::EDITORIAL_RESTRICTIONS : null,
            $same->productType,
            $endsAt,
            $now->modify('+'.self::DOWNLOAD_HOURS.' hours'),
            self::LICENCE_TERMS,
            $key,
            Licence::trim($item + ['subscription_id' => $subscriptionId, 'size' => $size, 'sandbox' => $this->sandbox]),
        );
    }

    /**
     * The licensed file: from the address the licence came with, or asked
     * for again (a redownload doesn't charge, where the licence allows
     * one). Fetched without credentials, byte for byte.
     */
    public function download(Licence $licence): PhotoFile
    {
        $this->checkId($licence->photoId);
        $url = $this->downloads[$licence->orderId] ?? null;

        if ($url === null) {
            if (! preg_match('/^[A-Za-z0-9]{6,64}$/', $licence->orderId)) {
                throw new PhotoUnavailable('Shutterstock didn\'t say which licence this is, so it can\'t be downloaded again here. Download it from your Shutterstock account.');
            }

            $size = $licence->option !== null ? $this->option($licence->option)[1] : 'huge';
            $answer = $this->downloader->post($this->api("/v2/images/licenses/{$licence->orderId}/downloads"), ['size' => $size], $this->bearer($this->token()), label: 'Shutterstock');
            $url = $this->string($answer, 'url') ?? throw new PhotoUnavailable('Shutterstock gave no address to download that licence again.');
        }

        $image = $this->downloader->image($url, LicensableLibrary::MAX_BYTES, 180);

        try {
            $photo = $this->photo($licence->photoId);
        } catch (PhotoUnavailable) {
            $photo = new Photo($this->id(), $licence->photoId, $url, 'Shutterstock', $this->page($licence->photoId), 'Shutterstock licence', offer: Offer::paid());
        }

        return new PhotoFile($image['content'], $image['mime'], Downloader::IMAGE_TYPES[$image['mime']], $photo);
    }

    /**
     * The account's licences for this photo, newest first, each with the
     * ledger ID it was bought under (`key`, from `metadata.customer_id`).
     */
    public function findLicences(string $id): array
    {
        $this->checkId($id);
        $data = $this->downloader->json($this->api('/v2/images/licenses'), ['image_id' => $id, 'sort' => 'newest', 'per_page' => 50], $this->bearer($this->token()), label: 'Shutterstock', account: true);
        $licences = [];

        foreach (is_array($data['data'] ?? null) ? $data['data'] : [] as $entry) {
            if (! is_array($entry) || $this->string($entry, 'image', 'id') !== $id || ($orderId = $this->string($entry, 'id')) === null) {
                continue;
            }

            $type = $this->string($entry, 'license');
            $editorial = $type !== null && str_contains(strtolower($type), 'editorial');
            $subscription = $this->string($entry, 'subscription_id');
            $size = $this->string($entry, 'image', 'format', 'size');

            $licences[] = new Licence(
                $this->id(),
                $id,
                $orderId,
                $this->date($entry['download_time'] ?? null) ?? ($this->clock)(),
                $this->string($entry, 'user', 'username') ?? '',
                $subscription !== null && $size !== null ? "{$subscription}:{$size}".($editorial ? ':editorial' : '') : null,
                null,
                true,
                'Shutterstock.com',
                $editorial ? Offer::EDITORIAL : Offer::ROYALTY_FREE,
                $editorial ? self::EDITORIAL_RESTRICTIONS : null,
                $type,
                terms: self::LICENCE_TERMS,
                key: $this->string($entry, 'metadata', 'customer_id'),
                raw: Licence::trim($entry),
            );
        }

        return $licences;
    }

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        return self::API.'/v2/oauth/authorize?'.http_build_query([
            'client_id' => $this->key,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'state' => $state,
            'realm' => 'customer',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function connect(string $code, string $redirectUri, string $state = ''): TokenSet
    {
        if (trim($code) === '' || ! $this->available()) {
            throw new NotConnected('Shutterstock didn\'t accept the sign-in. Try connecting again.');
        }

        return $this->tokenCall([
            'client_id' => $this->key,
            'client_secret' => $this->secret,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'expires' => 'true',
            'realm' => 'customer',
        ], null, 'Shutterstock didn\'t accept the sign-in. Try connecting again.');
    }

    public function refresh(TokenSet $tokens): TokenSet
    {
        if ($tokens->expiresAt === null) {
            return $tokens;
        }

        if (! $tokens->canRefresh()) {
            $this->tokens->forget($this->id());

            throw new NotConnected('The Shutterstock account needs connecting again.');
        }

        return $this->tokenCall([
            'client_id' => $this->key,
            'client_secret' => $this->secret,
            'grant_type' => 'refresh_token',
            'refresh_token' => (string) $tokens->refreshToken,
        ], $tokens, 'The Shutterstock account needs connecting again.');
    }

    public function connected(): bool
    {
        return $this->tokens->get($this->id()) !== null;
    }

    /**
     * Shutterstock has no endpoint to revoke a token: it is forgotten here,
     * and the customer can delete the app to revoke it.
     */
    public function disconnect(): void
    {
        $this->tokens->forget($this->id());
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['key' => '***', 'secret' => '***', 'sandbox' => $this->sandbox, 'editorial' => $this->editorial];
    }

    /**
     * @param  array<string, string>  $body
     *
     * @throws NotConnected
     */
    private function tokenCall(array $body, ?TokenSet $old, string $refused): TokenSet
    {
        try {
            $answer = $this->downloader->post(self::API.'/v2/oauth/access_token', $body, form: true, timeout: 30, label: 'Shutterstock');
        } catch (PhotoUnavailable $exception) {
            // A refusal (401, 403 or another 4xx) is final; failing to reach Shutterstock isn't.
            if ($exception instanceof NotConnected || preg_match('/\(4\d\d\)/', $exception->getMessage())) {
                if ($old !== null) {
                    // Refresh refused: the access was revoked. A failed reconnect keeps what was there.
                    $this->tokens->forget($this->id());
                }

                throw new NotConnected($refused);
            }

            throw $exception;
        }

        $tokens = TokenSet::fromResponse($answer, ($this->clock)()) ?? throw new NotConnected($refused);

        if ($tokens->refreshToken === null && $old?->refreshToken !== null) {
            $tokens = new TokenSet($tokens->accessToken, $tokens->expiresAt, $old->refreshToken, $tokens->scopes ?: $old->scopes, $tokens->type);
        }

        $this->tokens->put($this->id(), $tokens);

        return $tokens;
    }

    /**
     * The connected account's token, renewed first if it has expired.
     *
     * @throws NotConnected
     */
    private function token(): TokenSet
    {
        if (! $this->available()) {
            throw new NotConnected('Shutterstock has no API key and secret set.');
        }

        $tokens = $this->tokens->get($this->id()) ?? throw new NotConnected('Connect your Shutterstock account in the settings first.');

        return $tokens->isExpired(($this->clock)()) ? $this->refresh($tokens) : $tokens;
    }

    /**
     * The account's image subscriptions.
     *
     * @return array<int, array<string, mixed>> Each with a string `id`.
     */
    private function subscriptions(TokenSet $token): array
    {
        $data = $this->downloader->json($this->api('/v2/user/subscriptions'), headers: $this->bearer($token), label: 'Shutterstock', account: true);
        $subscriptions = [];

        foreach (is_array($data['data'] ?? null) ? $data['data'] : [] as $subscription) {
            if (is_array($subscription) && $this->string($subscription, 'id') !== null
                && in_array($this->string($subscription, 'asset_type') ?? 'images', ['images', 'image'], true)) {
                $subscription['id'] = (string) $this->string($subscription, 'id');
                $subscriptions[] = $subscription;
            }
        }

        return $subscriptions;
    }

    /**
     * The ID of the licence just bought under this ledger key, when the
     * licence answer didn't give one.
     */
    private function licenceIdFor(string $id, string $key): ?string
    {
        try {
            foreach ($this->findLicences($id) as $licence) {
                if ($licence->key === $key) {
                    return $licence->orderId;
                }
            }
        } catch (PhotoUnavailable) {
            // The licence is bought; only its ID is missing.
        }

        return null;
    }

    /**
     * A quote's option: subscription ID, size, and whether it is editorial.
     *
     * @return array{0: string, 1: string, 2: bool}
     *
     * @throws QuoteChanged
     */
    private function option(string $option): array
    {
        $parts = explode(':', $option);

        if (count($parts) < 2 || $parts[0] === '' || ! in_array($parts[1], self::SIZES, true) || (isset($parts[2]) && $parts[2] !== 'editorial')) {
            throw new QuoteChanged('That isn\'t a Shutterstock licence option. Check it and try again.');
        }

        return [$parts[0], $parts[1], ($parts[2] ?? '') === 'editorial'];
    }

    /**
     * The photo and Shutterstock's answer about it.
     *
     * @return array{0: Photo, 1: array<mixed>}
     *
     * @throws PhotoUnavailable
     */
    private function lookup(string $id): array
    {
        $this->checkId($id);
        $data = $this->downloader->json($this->api("/v2/images/{$id}"), ['view' => 'full'], $this->basic(), label: 'Shutterstock');

        return [$this->result($data) ?? throw new PhotoUnavailable('That photograph could not be found.'), $data];
    }

    /**
     * @param  array<mixed>  $item
     */
    private function result(array $item): ?Photo
    {
        $id = $this->string($item, 'id');
        $thumb = $this->string($item, 'assets', 'huge_thumb', 'url') ?? $this->string($item, 'assets', 'large_thumb', 'url') ?? $this->string($item, 'assets', 'preview', 'url');

        if ($id === null || ! preg_match('/^[1-9]\d{0,14}$/', $id) || $thumb === null) {
            return null;
        }

        $editorial = ($item['is_editorial'] ?? false) === true;
        $description = $this->string($item, 'description');
        [$width, $height] = $this->size($item);
        $collection = $this->string($item, 'asset_collection');
        $keywords = is_array($item['keywords'] ?? null) ? array_values(array_filter(array_map(fn ($k) => is_string($k) ? Slug::clip(trim($k), 40) : '', $item['keywords']))) : [];

        return new Photo(
            source: $this->id(),
            id: $id,
            thumb: $thumb,
            credit: 'Shutterstock',
            creditUrl: $this->page($id),
            licence: $editorial ? 'Shutterstock editorial licence' : 'Shutterstock licence',
            title: $description,
            description: $description,
            tags: array_slice($keywords, 0, 12),
            width: $width,
            height: $height,
            offer: Offer::paid(Cost::units(1, Cost::DOWNLOAD), $editorial ? Offer::EDITORIAL : Offer::ROYALTY_FREE),
            editorial: $editorial,
            restrictions: $editorial ? self::EDITORIAL_RESTRICTIONS : null,
            collection: $collection !== null ? "Shutterstock {$collection}" : null,
        );
    }

    /**
     * The photo's size, or its largest preview's (which has its aspect
     * ratio, all a stand-in needs).
     *
     * @param  array<mixed>  $item
     * @return array{0: ?int, 1: ?int}
     */
    private function size(array $item): array
    {
        foreach (['huge_jpg', 'preview_1500', 'preview_1000', 'preview'] as $asset) {
            $w = $item['assets'][$asset]['width'] ?? null;
            $h = $item['assets'][$asset]['height'] ?? null;

            if (is_numeric($w) && is_numeric($h) && (int) $w > 0 && (int) $h > 0) {
                return [(int) $w, (int) $h];
            }
        }

        $aspect = $item['aspect'] ?? null;

        return is_numeric($aspect) && (float) $aspect > 0 ? [1500, (int) round(1500 / (float) $aspect)] : [null, null];
    }

    /**
     * @throws PhotoUnavailable
     */
    private function checkId(string $id): void
    {
        if (! $this->available() || ! preg_match('/^[1-9]\d{0,14}$/', $id)) {
            throw new PhotoUnavailable('That photograph could not be found.');
        }
    }

    private function api(string $path): string
    {
        return ($this->sandbox ? self::SANDBOX_API : self::API).$path;
    }

    private function page(string $id): string
    {
        return "https://www.shutterstock.com/image-photo/{$id}";
    }

    /**
     * @return array<string, string>
     */
    private function basic(): array
    {
        return ['Authorization' => 'Basic '.base64_encode($this->key.':'.$this->secret)];
    }

    /**
     * @return array<string, string>
     */
    private function bearer(TokenSet $token): array
    {
        return ['Authorization' => $token->header()];
    }

    /**
     * @param  array<mixed>  $answer
     */
    private function firstError(array $answer): ?string
    {
        $error = $answer['errors'][0] ?? null;

        return is_array($error) ? ($this->string($error, 'message') ?? 'it refused') : null;
    }

    /** Shutterstock's own words, without addresses, cut short. */
    private function plain(string $text): string
    {
        $text = trim((string) preg_replace(['#[a-z][a-z0-9+.-]*://\S+#i', '/\s+/'], ['', ' '], $text));

        return $text === '' ? 'it refused.' : rtrim(Slug::clip($text, 160), '.').'.';
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        return Quote::date($value);
    }

    /**
     * A non-empty string at a path in decoded JSON, trimmed.
     *
     * @param  array<mixed>  $data
     */
    private function string(array $data, string ...$path): ?string
    {
        $value = $data;

        foreach ($path as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }

        if (! is_scalar($value) || is_bool($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
