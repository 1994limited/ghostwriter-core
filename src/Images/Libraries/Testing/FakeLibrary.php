<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Testing;

use Closure;
use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\LicenceRefused;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\LicensingUncertain;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\NotConnected;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Account;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Capabilities;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\ConnectsAccount;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Cost;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Licence;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\LicensableLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\OAuth\Pkce;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\OAuth\TokenSet;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Offer;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Ports\LibraryTokens;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Preview;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Quote;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\SearchQuery;
use NineteenNinetyFour\Ghostwriter\Core\Images\Photo;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoFile;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoUnavailable;
use RuntimeException;
use Throwable;

/**
 * A paid library that charges nothing and calls nobody, for tests, the
 * addons' demo library ("Demo stock (no charge)") and screenshots. Its
 * photos, quotes and licence outcomes are scripted; its comps are drawn
 * with GD (stripes and a "PREVIEW" watermark at the photo's aspect), and
 * its licensed files are plain drawn JPEGs.
 *
 *     $library = (new FakeLibrary)->withPhotos($photo);
 *     $library->licenceOutcomes(FakeLibrary::UNCERTAIN_CHARGED);   // the call fails, but it was bought
 *     $library->licenceOutcomes(new InsufficientBalance('No downloads left.'));
 *
 * Every call is recorded (`calls`), so a test can say a photo was
 * licensed exactly once (licenceCalls()).
 *
 * It is also a ConnectsAccount, so an addon's "Connect account" routes can
 * be tested against it, and run in a browser with the demo library: its
 * authorizationUrl() sends the person straight back to the callback with
 * a code, as a provider would once they allowed access. The code is bound
 * to the state and the callback address through PKCE (OAuth\Pkce), so
 * connect() refuses one used with another state or address. Tokens are
 * kept in the LibraryTokens given (in memory without one) and expire
 * after an hour; refresh() renews them unless `refusesRefresh`. Built
 * with Capabilities whose `needsOAuth` is true, account(), quotes() and
 * license() throw NotConnected until it is connected.
 */
final class FakeLibrary implements ConnectsAccount, LicensableLibrary
{
    /** The licence is bought. */
    public const SUCCEED = 'succeed';

    /** The call fails after the licence was bought: findLicences() finds it. */
    public const UNCERTAIN_CHARGED = 'uncertain-charged';

    /** The call fails and nothing was bought: findLicences() finds nothing. */
    public const UNCERTAIN_NOT_CHARGED = 'uncertain-not-charged';

    /** @var array<int, array{0: string, 1: array<int, mixed>}> Every call: its method and arguments. */
    public array $calls = [];

    public bool $available = true;

    /** @var array<string, Photo> */
    private array $photos = [];

    /** @var array<string, array<int, Quote>> */
    private array $quotes = [];

    /** @var array<int, string|Throwable> */
    private array $outcomes = [];

    /** @var array<string, array<int, Licence>> Licences bought, by photo. */
    private array $bought = [];

    /** @var Closure(): DateTimeImmutable */
    private Closure $clock;

    private Capabilities $capabilities;

    private int $orders = 0;

    /** The fake's own "client secret", for PKCE. */
    private const SECRET = 'demo-client-secret';

    /** How long its access tokens last. */
    public const TOKEN_SECONDS = 3600;

    /** Whether refresh() refuses, as for an access revoked at the provider. */
    public bool $refusesRefresh = false;

    /** Whether the next connect() is refused, as for a code the provider won't take. */
    public bool $refusesConnect = false;

    private readonly LibraryTokens $tokens;

    private int $issued = 0;

    /**
     * @param  (Closure(): DateTimeImmutable)|null  $clock
     */
    public function __construct(
        private readonly string $id = 'demo',
        private readonly string $label = 'Demo stock (no charge)',
        ?Capabilities $capabilities = null,
        ?Closure $clock = null,
        private readonly ?Account $account = null,
        ?LibraryTokens $tokens = null,
    ) {
        $this->capabilities = $capabilities ?? Capabilities::paid(Capabilities::QUOTES_BALANCE, 30, termsCheckedAt: '2026-10-02', editorial: true);
        $this->clock = $clock ?? fn () => new DateTimeImmutable;
        $this->tokens = $tokens ?? new InMemoryLibraryTokens;
    }

    /**
     * A photo of this library's, with a paid offer ("1 download").
     */
    public function photoFor(string $id, string $title = 'Rocks at dusk', int $width = 1600, int $height = 1000): Photo
    {
        return new Photo(
            $this->id, $id, "https://stock.example.com/{$this->id}/{$id}/thumb.jpg", "Demo photographer/{$this->label}", null, 'Royalty-free',
            title: $title, width: $width, height: $height, offer: Offer::paid(Cost::units(1, Cost::DOWNLOAD)),
        );
    }

    /**
     * What search() finds, and photo() knows. Made with photoFor() when
     * given IDs.
     */
    public function withPhotos(Photo|string ...$photos): self
    {
        foreach ($photos as $photo) {
            $photo = is_string($photo) ? $this->photoFor($photo) : $photo;
            $this->photos[$photo->id] = $photo;
        }

        return $this;
    }

    /**
     * The options quotes() gives for a photo. Without, one: "1 download".
     */
    public function withQuotes(string $id, Quote ...$quotes): self
    {
        $this->quotes[$id] = array_values($quotes);

        return $this;
    }

    /**
     * What the next license() calls do, in turn: SUCCEED,
     * UNCERTAIN_CHARGED, UNCERTAIN_NOT_CHARGED, or an exception to throw
     * (InsufficientBalance, QuoteChanged, LicenceRefused, NotConnected, or
     * something unexpected). Once they run out, licences succeed.
     */
    public function licenceOutcomes(string|Throwable ...$outcomes): self
    {
        array_push($this->outcomes, ...$outcomes);

        return $this;
    }

    /**
     * How many times license() was called for the photo.
     */
    public function licenceCalls(string $id): int
    {
        return count(array_filter($this->calls, fn (array $call) => $call[0] === 'license' && $call[1][0] === $id));
    }

    /**
     * @return array<int, Licence> Every licence bought for the photo.
     */
    public function bought(string $id): array
    {
        return $this->bought[$id] ?? [];
    }

    public function id(): string
    {
        return $this->id;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function capabilities(): Capabilities
    {
        return $this->capabilities;
    }

    public function available(): bool
    {
        return $this->available;
    }

    public function search(SearchQuery $query): array
    {
        $this->calls[] = ['search', [$query]];
        $photos = array_slice(array_values($this->photos), ($query->page - 1) * $query->perPage, $query->perPage);

        return array_map(fn (Photo $photo) => $photo->withTerm($query->term), $photos);
    }

    public function photo(string $id): Photo
    {
        $this->calls[] = ['photo', [$id]];

        return $this->known($id);
    }

    public function fetch(string $id): PhotoFile
    {
        $this->calls[] = ['fetch', [$id]];

        throw new LicenceRefused('That photograph must be licensed before it can be used.');
    }

    public function preview(string $id): Preview
    {
        $this->calls[] = ['preview', [$id]];
        $photo = $this->known($id);

        return Preview::stored(new PhotoFile(self::draw($photo, 'PREVIEW'), 'image/png', 'png', $photo), (int) $this->capabilities->previewKeepDays, true, ($this->clock)());
    }

    public function account(): Account
    {
        $this->calls[] = ['account', []];
        $this->needConnection();

        return $this->account ?? new Account($this->id, 'Demo account', [[
            'id' => 'demo-pack', 'type' => 'demo', 'name' => 'Demo pack', 'remaining' => Cost::units(100, Cost::DOWNLOAD), 'resetsAt' => null, 'termEndsAt' => null,
        ]]);
    }

    public function quotes(string $id): array
    {
        $this->calls[] = ['quotes', [$id]];
        $this->needConnection();
        $this->known($id);

        return $this->quotes[$id] ?? [new Quote($id, 'demo-pack', 'Demo licence', Cost::units(1, Cost::DOWNLOAD), 'demo', '2400')];
    }

    public function license(string $id, Quote $quote, string $key, string $licensedBy): Licence
    {
        $this->calls[] = ['license', [$id, $quote, $key, $licensedBy]];
        $this->needConnection();
        $this->known($id);
        $outcome = array_shift($this->outcomes) ?? self::SUCCEED;

        if ($outcome instanceof Throwable) {
            throw $outcome;
        }

        if ($outcome === self::UNCERTAIN_NOT_CHARGED) {
            throw new LicensingUncertain;
        }

        $licence = new Licence(
            $this->id, $id, 'demo-order-'.(++$this->orders), ($this->clock)(), $licensedBy, $quote->option, $quote->cost,
            creditLine: "Demo photographer/{$this->label}", productType: $quote->productType, key: $key, raw: ['order' => $this->orders],
        );
        $this->bought[$id][] = $licence;

        if ($outcome === self::UNCERTAIN_CHARGED) {
            throw new LicensingUncertain;
        }

        return $licence;
    }

    public function download(Licence $licence): PhotoFile
    {
        $this->calls[] = ['download', [$licence]];

        return new PhotoFile(self::draw($this->known($licence->photoId), null, 'jpg'), 'image/jpeg', 'jpg', $this->known($licence->photoId));
    }

    public function findLicences(string $id): array
    {
        $this->calls[] = ['findLicences', [$id]];

        return $this->bought[$id] ?? [];
    }

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        $this->calls[] = ['authorizationUrl', [$state, $redirectUri]];

        return $redirectUri.(str_contains($redirectUri, '?') ? '&' : '?').http_build_query(['code' => $this->code($state, $redirectUri), 'state' => $state], '', '&', PHP_QUERY_RFC3986);
    }

    public function connect(string $code, string $redirectUri, string $state = ''): TokenSet
    {
        $this->calls[] = ['connect', [$redirectUri, $state]];

        if ($this->refusesConnect || trim($state) === '' || ! hash_equals($this->code($state, $redirectUri), $code)) {
            $this->refusesConnect = false;

            throw new NotConnected("{$this->label} didn't accept the sign-in. Try connecting again.");
        }

        return $this->issue();
    }

    public function refresh(TokenSet $tokens): TokenSet
    {
        $this->calls[] = ['refresh', []];

        if (! $tokens->canRefresh() || $this->refusesRefresh) {
            $this->tokens->forget($this->id);

            throw new NotConnected("The {$this->label} account needs connecting again.");
        }

        return $this->issue();
    }

    public function connected(): bool
    {
        return $this->tokens->get($this->id) !== null;
    }

    public function disconnect(): void
    {
        $this->calls[] = ['disconnect', []];
        $this->tokens->forget($this->id);
    }

    /**
     * The code a provider would hand back for this state and address: bound
     * to them by the PKCE challenge, as a real provider binds its code.
     */
    private function code(string $state, string $redirectUri): string
    {
        return 'demo-'.substr(hash('sha256', Pkce::challenge(Pkce::verifier($state === '' ? '-' : $state, self::SECRET)).'|'.$redirectUri), 0, 24);
    }

    private function issue(): TokenSet
    {
        $n = ++$this->issued;
        $tokens = new TokenSet("demo-access-{$n}", ($this->clock)()->modify('+'.self::TOKEN_SECONDS.' seconds'), "demo-refresh-{$n}", ['licenses.create']);
        $this->tokens->put($this->id, $tokens);

        return $tokens;
    }

    /**
     * @throws NotConnected
     */
    private function needConnection(): void
    {
        if (! $this->capabilities->needsOAuth) {
            return;
        }

        $tokens = $this->tokens->get($this->id) ?? throw new NotConnected("Connect the {$this->label} account in the settings first.");

        if ($tokens->isExpired(($this->clock)())) {
            $this->refresh($tokens);
        }
    }

    /**
     * @throws PhotoUnavailable
     */
    private function known(string $id): Photo
    {
        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) || ! isset($this->photos[$id])) {
            throw new PhotoUnavailable('That photograph could not be found.');
        }

        return $this->photos[$id];
    }

    /**
     * A picture at the photo's aspect ratio (800 pixels on the long side):
     * warm bands, and a watermark word across it for a comp.
     */
    private static function draw(Photo $photo, ?string $watermark, string $type = 'png'): string
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('Drawing the demo library\'s pictures needs the GD extension.');
        }

        $scale = 800 / max(1, $photo->width ?? 1600, $photo->height ?? 1000);
        $w = max(40, (int) round(($photo->width ?? 1600) * $scale));
        $h = max(40, (int) round(($photo->height ?? 1000) * $scale));
        $image = imagecreatetruecolor($w, $h);

        if ($image === false) {
            throw new RuntimeException('The demo library\'s picture could not be drawn.');
        }

        // A colour of its own for each photo, so a page of them can be told apart.
        $seed = crc32($photo->id);

        for ($y = 0; $y < $h; $y += 20) {
            imagefilledrectangle($image, 0, $y, $w, $y + 19, (int) imagecolorallocate($image, 150 + ($seed + $y) % 90, 100 + ($seed >> 3) % 80, 60 + ($y * 2) % 90));
        }

        if ($watermark !== null) {
            $white = (int) imagecolorallocatealpha($image, 255, 255, 255, 40);

            for ($y = 20; $y < $h; $y += 60) {
                for ($x = intdiv($y, 60) % 2 * 60; $x < $w; $x += 160) {
                    imagestring($image, 5, $x, $y, $watermark, $white);
                }
            }
        }

        ob_start();
        $type === 'jpg' ? imagejpeg($image, null, 85) : imagepng($image);

        return (string) ob_get_clean();
    }
}
