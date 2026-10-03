<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Images\Libraries;

use Closure;
use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\NetworkError;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\InsufficientBalance;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\LicenceRefused;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\LicensingUncertain;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\NotConnected;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\QuoteChanged;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Capabilities;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\ConnectsAccount;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Cost;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Licence;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\LicensableLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\OAuth\TokenSet;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Offer;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Paid\Shutterstock;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\PhotoLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Quote;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\SearchQuery;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Testing\InMemoryLibraryTokens;
use NineteenNinetyFour\Ghostwriter\Core\Images\Photo;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Images\ImagesTestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * The Shutterstock adapter over a mocked network. Every answer here is
 * synthetic, written in the shape of Shutterstock's API reference with
 * made-up IDs, captions and example.com addresses: nothing is recorded
 * from the real API.
 */
final class ShutterstockTest extends ImagesTestCase
{
    use LibraryContract;

    private const KEY = 'synthetic-consumer-key';

    private const SECRET = 'synthetic-consumer-secret';

    private const TOKEN = '1/synthetic-access-token';

    private const FIXED = 'v2/synthetic-fixed-token';

    private const API = 'https://api.shutterstock.com';

    private const CALLBACK = 'https://cms.example.com/cp/ghostwriter/libraries/shutterstock/callback';

    private InMemoryLibraryTokens $tokens;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokens = new InMemoryLibraryTokens;
        $this->now = new DateTimeImmutable('2026-10-02T12:00:00Z');
    }

    public function test_it_is_a_licensable_library_that_connects_an_account_and_stores_no_comp(): void
    {
        $library = $this->shutterstock();
        $capabilities = $library->capabilities();

        $this->assertInstanceOf(LicensableLibrary::class, $library);
        $this->assertInstanceOf(ConnectsAccount::class, $library);
        $this->assertSame(['shutterstock', 'Shutterstock'], [$library->id(), $library->label()]);
        $this->assertSame('Shutterstock (sandbox)', $this->shutterstock(sandbox: true)->label());
        $this->assertSame([
            'free' => false,
            'may_rank' => false,
            'needs_oauth' => true,
            'quotes' => Capabilities::QUOTES_BALANCE,
            'preview_keep_days' => 30,
            'preview_storage' => Capabilities::STORAGE_NONE,
            'terms_checked_at' => '2026-10-02',
            'editorial' => true,
            'credit_required' => false,
            'sandbox' => true,
            'no_model_input' => true,
        ], $capabilities->toArray());
    }

    public function test_its_keys_come_from_the_constructor_only_and_never_show(): void
    {
        putenv('SHUTTERSTOCK_API_KEY=from-env');
        putenv('SHUTTERSTOCK_API_SECRET=from-env');

        try {
            $library = new Shutterstock($this->http, '', '', $this->tokens);
            $this->assertFalse($library->available(), 'The environment is never read: the addon passes the keys in.');
        } finally {
            putenv('SHUTTERSTOCK_API_KEY');
            putenv('SHUTTERSTOCK_API_SECRET');
        }

        $this->assertTrue($this->shutterstock()->available());
        $dump = print_r($this->shutterstock(), true);
        $this->assertStringNotContainsString(self::KEY, $dump);
        $this->assertStringNotContainsString(self::SECRET, $dump);
    }

    public function test_without_a_token_search_uses_basic_auth_and_maps_paid_offers_in_downloads(): void
    {
        $this->routeSearch();

        $photos = $this->shutterstock()->search(new SearchQuery('mended pottery', 'portrait', 2, 5));

        $request = $this->http->requests[0];
        $url = (string) $request->getUri();
        $this->assertStringStartsWith(self::API.'/v2/images/search?license=commercial&query=mended%20pottery&page=2&per_page=5&image_type=photo', $url);
        $this->assertStringContainsString('orientation=vertical', $url);
        $this->assertStringNotContainsString('license=editorial', $url);
        $this->assertStringNotContainsString(self::SECRET, $url);
        $this->assertSame('Basic '.base64_encode(self::KEY.':'.self::SECRET), $request->getHeaderLine('Authorization'));

        $this->assertSame(['1000001', '1000002'], array_map(fn (Photo $photo) => $photo->id, $photos), 'The editorial result is left out while editorial is off.');
        $photo = $photos[0];
        $this->assertSame('https://thumbs.example.com/1000001-huge.jpg', $photo->thumb);
        $this->assertSame('Shutterstock', $photo->credit);
        $this->assertSame('Bowls mended with gold', $photo->title);
        $this->assertSame([1500, 1000], [$photo->width, $photo->height]);
        $this->assertSame('1 download', $photo->offer()->label());
        $this->assertFalse($photo->offer()->price?->isMoney(), 'A cost is the allotment, never money.');
        $this->assertSame(Offer::ROYALTY_FREE, $photo->offer()->licenceType);
        $this->assertNull($photo->url, 'No final file before licensing.');
        $this->assertSame('Shutterstock Essentials', $photo->collection);
    }

    public function test_editorial_results_come_only_when_switched_on_and_asked_for(): void
    {
        $this->routeSearch();

        $this->shutterstock(editorial: true)->search(new SearchQuery('pottery'));
        $photos = $this->shutterstock(editorial: true)->search(new SearchQuery('pottery', 'square', editorial: true));

        $this->assertStringNotContainsString('license=editorial', (string) $this->http->requests[0]->getUri());
        $url = (string) $this->http->requests[1]->getUri();
        $this->assertStringContainsString('?license=commercial&license=editorial&', $url);
        $this->assertStringContainsString('aspect_ratio_min=0.9', $url);
        $editorial = array_values(array_filter($photos, fn (Photo $photo) => $photo->editorial));
        $this->assertCount(1, $editorial);
        $this->assertSame(Offer::EDITORIAL, $editorial[0]->offer()->licenceType);
        $this->assertSame(Shutterstock::EDITORIAL_RESTRICTIONS, $editorial[0]->restrictions);
    }

    public function test_the_sandbox_switches_every_api_call_but_not_sign_in(): void
    {
        $this->route('https://api-sandbox.shutterstock.com/v2/images/search', ['data' => [$this->image('1000001')]]);
        $library = $this->shutterstock(sandbox: true);

        $library->search(new SearchQuery('pottery'));

        $this->assertStringStartsWith('https://api-sandbox.shutterstock.com/v2/images/search?', (string) $this->http->requests[0]->getUri());
        $this->assertStringStartsWith(self::API.'/v2/oauth/authorize?', $library->authorizationUrl('s', self::CALLBACK));
    }

    public function test_a_preview_is_shutterstocks_own_address_and_is_never_fetched(): void
    {
        $this->routePhoto('1000001');

        $preview = $this->shutterstock()->preview('1000001');

        $this->assertFalse($preview->isStored());
        $this->assertNull($preview->file);
        $this->assertSame('https://previews.example.com/1000001-1500.jpg', $preview->url);
        $this->assertTrue($preview->watermarked);
        $this->assertEquals($this->now->modify('+30 days'), $preview->keepUntil);
        $this->assertSame([self::API.'/v2/images/1000001?view=full'], $this->requested(), 'Only the look-up: the comp is never downloaded.');
    }

    public function test_a_photo_is_never_fetched_unlicensed(): void
    {
        $this->expectException(LicenceRefused::class);
        $this->shutterstock()->fetch('1000001');
    }

    public function test_sign_in_asks_for_the_licensing_scopes_and_keeps_expiring_tokens(): void
    {
        $library = $this->shutterstock();
        parse_str((string) parse_url($library->authorizationUrl('state-1', self::CALLBACK), PHP_URL_QUERY), $query);

        $this->assertSame([
            'client_id' => self::KEY,
            'redirect_uri' => self::CALLBACK,
            'response_type' => 'code',
            'scope' => 'user.view licenses.create licenses.view purchases.view',
            'state' => 'state-1',
            'realm' => 'customer',
        ], $query);
        $this->assertStringNotContainsString(self::SECRET, $library->authorizationUrl('state-1', self::CALLBACK));

        $this->route(self::API.'/v2/oauth/access_token', ['access_token' => self::TOKEN, 'expires_in' => 3600, 'token_type' => 'Bearer', 'refresh_token' => '3/synthetic-refresh']);

        $tokens = $library->connect('code-1', self::CALLBACK, 'state-1');

        $request = $this->http->requests[0];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        parse_str((string) $request->getBody(), $body);
        $this->assertSame(['client_id' => self::KEY, 'client_secret' => self::SECRET, 'grant_type' => 'authorization_code', 'code' => 'code-1', 'expires' => 'true', 'realm' => 'customer'], $body);
        $this->assertTrue($library->connected());
        $this->assertSame($tokens, $this->tokens->get('shutterstock'));
        $this->assertSame($this->now->getTimestamp() + 3600, $tokens->expiresAt?->getTimestamp());
    }

    public function test_a_refused_sign_in_keeps_what_was_there_and_says_nothing_secret(): void
    {
        $this->connected();
        $before = $this->tokens->get('shutterstock');
        $this->route(self::API.'/v2/oauth/access_token', fn () => $this->http->response(400, '{"message":"Invalid code code-1 for '.self::SECRET.'"}'));

        $exception = $this->failure(fn () => $this->shutterstock()->connect('code-1', self::CALLBACK));

        $this->assertInstanceOf(NotConnected::class, $exception);
        $this->assertStringNotContainsString('code-1', $exception->getMessage());
        $this->assertStringNotContainsString(self::SECRET, $exception->getMessage());
        $this->assertSame($before, $this->tokens->get('shutterstock'));
    }

    public function test_expired_tokens_are_refreshed_before_use_and_a_refused_refresh_disconnects(): void
    {
        $this->tokens->put('shutterstock', new TokenSet('1/old', $this->now->modify('-1 minute'), '3/synthetic-refresh'));
        $this->route(self::API.'/v2/oauth/access_token', ['access_token' => '1/new', 'expires_in' => 3600, 'token_type' => 'Bearer']);
        $this->routeAccount();

        $this->shutterstock()->account();

        parse_str((string) $this->http->requests[0]->getBody(), $body);
        $this->assertSame(['client_id' => self::KEY, 'client_secret' => self::SECRET, 'grant_type' => 'refresh_token', 'refresh_token' => '3/synthetic-refresh'], $body);
        $this->assertSame('Bearer 1/new', $this->http->requests[1]->getHeaderLine('Authorization'));
        $this->assertSame('3/synthetic-refresh', $this->tokens->get('shutterstock')?->refreshToken, 'The refresh token is kept when the answer leaves it out.');

        $this->setUp();
        $this->tokens->put('shutterstock', new TokenSet('1/old', $this->now->modify('-1 minute'), '3/revoked'));
        $this->route(self::API.'/v2/oauth/access_token', fn () => $this->http->response(401, 'revoked'));

        $this->assertInstanceOf(NotConnected::class, $this->failure(fn () => $this->shutterstock()->account()));
        $this->assertFalse($this->shutterstock()->connected());
    }

    public function test_tokens_that_never_expire_are_used_as_they_are_and_disconnect_forgets(): void
    {
        $tokens = new TokenSet('v2/non-expiring');
        $library = $this->shutterstock();

        $this->assertSame($tokens, $library->refresh($tokens));
        $this->assertSame([], $this->http->requests);

        $this->tokens->put('shutterstock', $tokens);
        $library->disconnect();
        $this->assertFalse($library->connected());
    }

    public function test_the_account_lists_its_subscriptions_with_downloads_left(): void
    {
        $this->connected();
        $this->routeAccount();

        $account = $this->shutterstock()->account();

        $this->assertSame('shutterstock', $account->library);
        $this->assertSame('synthetic_user', $account->name);
        $this->assertCount(2, $account->products, 'Image subscriptions only.');
        $product = $account->product('s1000');
        $this->assertSame('standard', $product['type'] ?? null);
        $this->assertSame('Synthetic API subscription', $product['name'] ?? null);
        $this->assertSame('742 downloads', $product['remaining']?->label());
        $this->assertSame('2026-11-01', $product['resetsAt']?->format('Y-m-d'));
        $this->assertSame('2027-01-31', $product['termEndsAt']?->format('Y-m-d'));
        $this->assertSame('Bearer '.self::TOKEN, $this->http->requests[0]->getHeaderLine('Authorization'));
    }

    public function test_without_a_connected_account_nothing_is_asked(): void
    {
        $library = $this->shutterstock();

        foreach ([fn () => $library->account(), fn () => $library->quotes('1000001'), fn () => $library->license('1000001', $this->quote(), 'record-1', 'Ann'), fn () => $library->findLicences('1000001')] as $call) {
            $this->assertInstanceOf(NotConnected::class, $this->failure($call));
        }

        $this->assertSame([], $this->http->requests);
    }

    public function test_a_refused_token_asks_to_connect_again(): void
    {
        $this->connected();
        $this->route(self::API.'/v2/user/subscriptions', fn () => $this->http->response(401, 'Bad token '.self::TOKEN));

        $exception = $this->failure(fn () => $this->shutterstock()->account());

        $this->assertInstanceOf(NotConnected::class, $exception);
        $this->assertStringNotContainsString(self::TOKEN, $exception->getMessage());
    }

    public function test_quotes_are_each_subscriptions_jpeg_sizes_largest_first(): void
    {
        $this->connected();
        $this->routeAccount();
        $this->routePhoto('1000001');

        $quotes = $this->shutterstock()->quotes('1000001');

        $this->assertSame(['s1000:huge', 's1000:medium', 's1000:small'], array_map(fn (Quote $quote) => $quote->option, $quotes), 'Vector is left out, and the empty subscription.');
        $this->assertSame('Synthetic API subscription · Huge (4000 px)', $quotes[0]->licenceName);
        $this->assertSame('1 download', $quotes[0]->costLabel());
        $this->assertSame(['huge', 'standard'], [$quotes[0]->size, $quotes[0]->productType]);
        $this->assertSame(Shutterstock::LICENCE_TERMS, $quotes[0]->terms);
    }

    public function test_licensing_sends_the_ledger_key_once_and_downloads_without_credentials(): void
    {
        $this->connected();
        $this->routeAccount();
        $this->routePhoto('1000001');
        $this->routeLicences(fn () => $this->json(['data' => [['image_id' => '1000001', 'download' => ['url' => 'https://download.example.com/gatekeeper/abc/shutterstock_1000001.jpg'], 'allotment_charge' => 1, 'license_id' => 'e1licence01']]]));
        $this->route('https://download.example.com/', $this->jpegResponse(60, 40));
        $library = $this->shutterstock();

        $licence = $library->license('1000001', $this->quote(), 'record-1', 'Ann');

        $posts = $this->posts('/v2/images/licenses');
        $this->assertCount(1, $posts);
        $this->assertSame('Bearer '.self::TOKEN, $posts[0]->getHeaderLine('Authorization'));
        $this->assertSame(['images' => [['image_id' => '1000001', 'subscription_id' => 's1000', 'size' => 'huge', 'price' => 0, 'metadata' => ['customer_id' => 'record-1']]]], json_decode((string) $posts[0]->getBody(), true));

        $this->assertSame(['shutterstock', '1000001', 'e1licence01', 'record-1', 'Ann'], [$licence->library, $licence->photoId, $licence->orderId, $licence->key, $licence->licensedBy]);
        $this->assertSame('1 download', $licence->cost?->label());
        $this->assertFalse($licence->estimated);
        $this->assertSame(['standard', 's1000:huge', 'Shutterstock.com', Offer::ROYALTY_FREE], [$licence->productType, $licence->option, $licence->creditLine, $licence->licenceType]);
        $this->assertSame('2027-01-31', $licence->termEndsAt?->format('Y-m-d'));
        $this->assertEquals($this->now->modify('+8 hours'), $licence->downloadUrlExpiresAt);
        $this->assertStringNotContainsString('gatekeeper', (string) json_encode($licence->toArray()), 'The signed download address is never kept.');

        $file = $library->download($licence);

        $this->assertSame('image/jpeg', $file->mime);
        $this->assertSame('1000001', $file->photo->id);
        $download = array_values(array_filter($this->http->requests, fn (RequestInterface $r) => $r->getUri()->getHost() === 'download.example.com'));
        $this->assertCount(1, $download);
        $this->assertSame('', $download[0]->getHeaderLine('Authorization'), 'No credentials to the download host.');
    }

    public function test_a_licence_without_its_id_is_found_in_the_history_by_its_key(): void
    {
        $this->connected();
        $this->routeAccount();
        $this->routePhoto('1000001');
        $this->routeLicences(
            fn () => $this->json(['data' => [['image_id' => '1000001', 'download' => ['url' => 'https://download.example.com/x.jpg'], 'allotment_charge' => 1]]]),
            ['data' => [$this->history('e1other', 'record-0'), $this->history('e1mine', 'record-1')]],
        );

        $licence = $this->shutterstock()->license('1000001', $this->quote(), 'record-1', 'Ann');

        $this->assertSame('e1mine', $licence->orderId);
    }

    public function test_an_unknown_outcome_is_uncertain_never_retried_and_reconciled_by_key(): void
    {
        foreach ([
            'server error' => fn () => $this->http->response(503, 'Busy '.self::TOKEN),
            'timeout' => fn (RequestInterface $request) => throw NetworkError::timedOut(90)->withRequest($request),
            'no download' => fn () => $this->json(['data' => [['image_id' => '1000001', 'allotment_charge' => 1]]]),
            'unreadable' => fn () => $this->http->response(200, '<html>OK</html>'),
        ] as $case => $answer) {
            $this->setUp();
            $this->connected();
            $this->routeAccount();
            $this->routePhoto('1000001');
            $this->routeLicences($answer, ['data' => [$this->history('e1found', 'record-1')]]);
            $library = $this->shutterstock();

            $exception = $this->failure(fn () => $library->license('1000001', $this->quote(), 'record-1', 'Ann'));

            $this->assertInstanceOf(LicensingUncertain::class, $exception, $case);
            $this->assertStringNotContainsString(self::TOKEN, $exception->getMessage(), $case);
            $this->assertCount(1, $this->posts('/v2/images/licenses'), "{$case}: sent once.");

            $found = $library->findLicences('1000001');
            $this->assertSame(['e1found'], array_map(fn ($licence) => $licence->orderId, $found), $case);
            $this->assertSame('record-1', $found[0]->key, "{$case}: StockImages::reconcile() matches it by key.");
            $this->assertSame('s1000:huge', $found[0]->option);
            $this->assertSame('2026-10-02', $found[0]->licensedAt->format('Y-m-d'));
        }
    }

    public function test_a_refusal_says_so_and_nothing_was_bought(): void
    {
        foreach ([
            'item error' => [fn () => $this->json(['data' => [['image_id' => '1000001', 'error' => 'Media unavailable: see https://example.com/x?token=abc']]]), LicenceRefused::class, 'Media unavailable'],
            'errors list' => [fn () => $this->json(['errors' => [['message' => 'Media is restricted in your region']]]), LicenceRefused::class, 'restricted in your region'],
            'bad request' => [fn () => $this->http->response(400, 'nope'), LicenceRefused::class, null],
            'token refused' => [fn () => $this->http->response(403, 'nope'), NotConnected::class, null],
        ] as $case => [$answer, $class, $words]) {
            $this->setUp();
            $this->connected();
            $this->routeAccount();
            $this->routePhoto('1000001');
            $this->routeLicences($answer);

            $exception = $this->failure(fn () => $this->shutterstock()->license('1000001', $this->quote(), 'record-1', 'Ann'));

            $this->assertInstanceOf($class, $exception, $case);
            $this->assertNotInstanceOf(LicensingUncertain::class, $exception, $case);
            $this->assertStringNotContainsString('token=abc', $exception->getMessage(), $case);
            $words === null ?: $this->assertStringContainsString($words, $exception->getMessage(), $case);
        }
    }

    public function test_a_licence_the_plan_doesnt_cover_or_unaccepted_terms_says_so_in_the_editors_words(): void
    {
        foreach ([
            'terms, per item' => ['data' => [['image_id' => '1000001', 'error' => 'Terms of Service must be accepted']]],
            'terms, per item object' => ['data' => [['image_id' => '1000001', 'error' => ['message' => 'Terms of service must be accepted.']]]],
            'terms, errors list' => ['errors' => [['message' => 'Terms of Service must be accepted', 'path' => 'images[0]']]],
            'not valid for the subscription' => ['data' => [['image_id' => '1000001', 'error' => 'Subscription is not valid for this media']]],
            'errors list beside an item without an error' => ['data' => [['image_id' => '1000001']], 'errors' => [['message' => 'Terms of Service must be accepted']]],
        ] as $case => $answer) {
            $this->setUp();
            $this->routeAccount();
            $this->routePhoto('1000001');
            $this->routeLicences(fn () => $this->json($answer));

            $exception = $this->failure(fn () => $this->shutterstock(token: self::FIXED)->license('1000001', $this->quote(), 'record-1', 'Ann'));

            $this->assertInstanceOf(LicenceRefused::class, $exception, $case);
            $this->assertNotInstanceOf(LicensingUncertain::class, $exception, $case);
            $this->assertSame(Shutterstock::LICENCE_NOT_COVERED, $exception->getMessage(), $case);
            $this->assertSame('Shutterstock refused this licence. Your plan may not cover this image (free API plans can only license the free collection), or your account must accept Shutterstock\'s API terms.', $exception->getMessage());
            $this->assertCount(1, $this->posts('/v2/images/licenses'), "{$case}: sent once, never again.");
        }
    }

    public function test_with_a_token_search_and_look_ups_are_made_as_the_user(): void
    {
        foreach (['fixed token' => [self::FIXED, false], 'connected account' => [null, true]] as $case => [$token, $connect]) {
            $this->setUp();
            $connect && $this->connected();
            $this->routeSearch();
            $this->routePhoto('1000001');
            $library = $this->shutterstock(token: $token);

            $library->search(new SearchQuery('pottery'));
            $library->photo('1000001');
            $library->preview('1000001');

            $this->assertCount(3, $this->http->requests, $case);

            foreach ($this->http->requests as $request) {
                $this->assertSame('Bearer '.($token ?? self::TOKEN), $request->getHeaderLine('Authorization'), "{$case}: ".$request->getUri());
            }

            $this->assertStringStartsWith(self::API.'/v2/images/search?license=commercial&query=pottery', (string) $this->http->requests[0]->getUri());
            $this->assertSame([], $this->logs, $case);
        }
    }

    public function test_an_expired_connected_token_is_renewed_before_a_search_and_basic_auth_is_used_if_it_cant_be(): void
    {
        $this->tokens->put('shutterstock', new TokenSet(self::TOKEN, $this->now->modify('-1 minute'), '3/synthetic-refresh'));
        $this->route(self::API.'/v2/oauth/access_token', ['access_token' => '1/synthetic-renewed', 'expires_in' => 3600, 'token_type' => 'Bearer']);
        $this->routeSearch();

        $this->shutterstock()->search(new SearchQuery('pottery'));

        $this->assertSame('Bearer 1/synthetic-renewed', $this->http->requests[1]->getHeaderLine('Authorization'));

        $this->setUp();
        $this->tokens->put('shutterstock', new TokenSet(self::TOKEN, $this->now->modify('-1 minute'), '3/synthetic-refresh'));
        $this->route(self::API.'/v2/oauth/access_token', fn () => $this->http->response(503, 'down'));
        $this->routeSearch();

        $this->assertCount(2, $this->shutterstock()->search(new SearchQuery('pottery')));
        $this->assertSame('Basic '.base64_encode(self::KEY.':'.self::SECRET), $this->http->requests[1]->getHeaderLine('Authorization'));
        $this->assertSame(['debug'], array_column($this->logs, 'level'));
    }

    public function test_a_search_the_users_token_is_refused_for_falls_back_to_basic_auth_and_logs_at_debug(): void
    {
        foreach ([401, 403] as $status) {
            $this->setUp();
            $this->route(self::API.'/v2/images/search', fn (RequestInterface $request) => str_starts_with($request->getHeaderLine('Authorization'), 'Bearer')
                ? $this->http->response($status, 'Bad token '.self::FIXED)
                : $this->json(['data' => [$this->image('1000001'), $this->image('1000002')]]));

            $photos = $this->shutterstock(token: self::FIXED)->search(new SearchQuery('pottery'));

            $this->assertSame(['1000001', '1000002'], array_map(fn (Photo $photo) => $photo->id, $photos), (string) $status);
            $this->assertSame(['Bearer '.self::FIXED, 'Basic '.base64_encode(self::KEY.':'.self::SECRET)], array_map(fn (RequestInterface $r) => $r->getHeaderLine('Authorization'), $this->http->requests));
            $this->assertSame(['debug'], array_column($this->logs, 'level'));
            $this->assertStringNotContainsString(self::FIXED, $this->logs[0]['message']);
            $this->assertStringNotContainsString(self::SECRET, $this->logs[0]['message']);
        }
    }

    public function test_a_look_up_the_users_token_is_refused_for_doesnt_fall_back(): void
    {
        $this->route(self::API.'/v2/images/1000001', fn () => $this->http->response(403, 'Bad token '.self::FIXED));
        $library = $this->shutterstock(token: self::FIXED);

        foreach ([fn () => $library->photo('1000001'), fn () => $library->preview('1000001')] as $call) {
            $exception = $this->failure($call);
            $this->assertInstanceOf(NotConnected::class, $exception);
            $this->assertSame(Shutterstock::TOKEN_REFUSED, $exception->getMessage());
        }

        $this->assertCount(2, $this->http->requests, 'Search only falls back to basic auth.');
    }

    public function test_an_empty_allotment_is_insufficient_balance(): void
    {
        foreach ([
            'item error' => fn () => $this->json(['data' => [['image_id' => '1000001', 'error' => 'Allotment exceeded for subscription s1000']]]),
            'payment required' => fn () => $this->http->response(402, ''),
        ] as $case => $answer) {
            $this->setUp();
            $this->connected();
            $this->routeAccount();
            $this->routePhoto('1000001');
            $this->routeLicences($answer);

            $this->assertInstanceOf(InsufficientBalance::class, $this->failure(fn () => $this->shutterstock()->license('1000001', $this->quote(), 'record-1', 'Ann')), $case);
        }

        $this->setUp();
        $this->connected();
        $this->routeAccount(empty: true);
        $this->routePhoto('1000001');

        $this->assertInstanceOf(InsufficientBalance::class, $this->failure(fn () => $this->shutterstock()->license('1000001', $this->quote(), 'record-1', 'Ann')));
        $this->assertSame([], $this->posts('/v2/images/licenses'), 'Nothing is bought with nothing left.');
    }

    public function test_a_changed_option_is_refused_before_buying_with_the_option_as_it_is_now(): void
    {
        $this->connected();
        $this->routeAccount();
        $this->routePhoto('1000001');
        $library = $this->shutterstock();

        foreach ([
            'subscription gone' => new Quote('1000001', 's9999:huge', 'Old plan', Cost::units(1, Cost::DOWNLOAD)),
            'cost changed' => new Quote('1000001', 's1000:huge', 'Plan', Cost::units(2, Cost::DOWNLOAD)),
            'another photo' => new Quote('1000002', 's1000:huge', 'Plan', Cost::units(1, Cost::DOWNLOAD)),
            'not an option' => new Quote('1000001', 'anything', 'Plan', Cost::units(1, Cost::DOWNLOAD)),
        ] as $case => $quote) {
            $exception = $this->failure(fn () => $library->license('1000001', $quote, 'record-1', 'Ann'));

            $this->assertInstanceOf(QuoteChanged::class, $exception, $case);
        }

        $exception = $this->failure(fn () => $library->license('1000001', new Quote('1000001', 's9999:huge', 'Old plan', Cost::units(1, Cost::DOWNLOAD)), 'record-1', 'Ann'));
        $this->assertInstanceOf(QuoteChanged::class, $exception);
        $this->assertSame('s1000:huge', $exception->quote?->option);
        $this->assertSame([], $this->posts('/v2/images/licenses'));
    }

    public function test_editorial_images_are_refused_while_off_and_licensed_with_the_acknowledgement_when_on(): void
    {
        $this->connected();
        $this->routeAccount();
        $this->routePhoto('1000003');
        $this->routeLicences(fn () => $this->json(['data' => [['image_id' => '1000003', 'download' => ['url' => 'https://download.example.com/e.jpg'], 'allotment_charge' => 1, 'license_id' => 'e1edit']]]));

        $this->assertInstanceOf(LicenceRefused::class, $this->failure(fn () => $this->shutterstock()->quotes('1000003')));

        $library = $this->shutterstock(editorial: true);
        $quotes = $library->quotes('1000003');
        $this->assertSame('s1000:huge:editorial', $quotes[0]->option);
        $this->assertStringEndsWith(' · editorial', $quotes[0]->licenceName);
        $this->assertSame(Shutterstock::EDITORIAL_RESTRICTIONS, $quotes[0]->terms);

        $plain = new Quote('1000003', 's1000:huge', 'Plan', Cost::units(1, Cost::DOWNLOAD));
        $this->assertInstanceOf(QuoteChanged::class, $this->failure(fn () => $library->license('1000003', $plain, 'record-1', 'Ann')), 'Only a quote the person saw as editorial acknowledges the agreement.');
        $this->assertSame([], $this->posts('/v2/images/licenses'));

        $licence = $library->license('1000003', $quotes[0], 'record-1', 'Ann');

        $body = json_decode((string) $this->posts('/v2/images/licenses')[0]->getBody(), true);
        $this->assertTrue($body['images'][0]['editorial_acknowledgement']);
        $this->assertSame([Offer::EDITORIAL, Shutterstock::EDITORIAL_RESTRICTIONS], [$licence->licenceType, $licence->restrictions]);
    }

    public function test_a_licence_bought_earlier_is_downloaded_again_without_buying(): void
    {
        $this->connected();
        $this->routePhoto('1000001');
        $this->route(self::API.'/v2/images/licenses/e1licence01/downloads', ['url' => 'https://download.example.com/again.jpg']);
        $this->route('https://download.example.com/', $this->jpegResponse());
        $licence = new Licence('shutterstock', '1000001', 'e1licence01', $this->now, option: 's1000:medium');

        $file = $this->shutterstock()->download($licence);

        $this->assertSame('jpg', $file->extension);
        $redownload = $this->posts('/v2/images/licenses/e1licence01/downloads');
        $this->assertCount(1, $redownload);
        $this->assertSame(['size' => 'medium'], json_decode((string) $redownload[0]->getBody(), true));
        $this->assertSame([], $this->posts('/v2/images/licenses'), 'Nothing bought again.');
    }

    public function test_a_fixed_token_is_used_for_every_account_call_and_counts_as_connected(): void
    {
        $this->routeAccount();
        $this->routePhoto('1000001');
        $this->routeLicences(fn () => $this->json(['data' => [['image_id' => '1000001', 'download' => ['url' => 'https://download.example.com/gatekeeper/abc/shutterstock_1000001.jpg'], 'allotment_charge' => 1, 'license_id' => 'e1licence01']]]), ['data' => [$this->history('e1licence01', 'record-1')]]);
        $this->route('https://download.example.com/', $this->jpegResponse(60, 40));
        $this->route(self::API.'/v2/images/licenses/e1licence01/downloads', ['url' => 'https://download.example.com/gatekeeper/def/shutterstock_1000001.jpg']);
        $library = $this->shutterstock(token: ' '.self::FIXED.' ');

        $this->assertTrue($library->connected(), 'No account is connected, but the token counts.');
        $this->assertTrue($library->usesToken());
        $this->assertFalse($this->shutterstock()->usesToken());
        $this->assertFalse($this->shutterstock(token: '  ')->connected(), 'A blank token is no token.');

        $this->assertSame('742 downloads', $library->account()->product('s1000')['remaining']?->label());
        $this->assertCount(3, $library->quotes('1000001'));
        $licence = $library->license('1000001', $this->quote(), 'record-1', 'Ann');
        $library->download($licence);
        $this->assertSame(['e1licence01'], array_map(fn (Licence $l) => $l->orderId, $library->findLicences('1000001')));
        $library->download(new Licence('shutterstock', '1000001', 'e1licence01', $this->now, 'Ann', 's1000:huge'));

        $bearer = array_values(array_filter($this->http->requests, fn (RequestInterface $r) => str_starts_with($r->getHeaderLine('Authorization'), 'Bearer')));
        $this->assertGreaterThanOrEqual(5, count($bearer));

        foreach ($bearer as $request) {
            $this->assertSame('Bearer '.self::FIXED, $request->getHeaderLine('Authorization'), (string) $request->getUri());
        }

        $this->assertSame([], $this->posts('/v2/oauth/access_token'), 'Nothing is refreshed.');
        $this->assertNull($this->tokens->get('shutterstock'), 'Nothing is kept.');
    }

    public function test_a_kept_connected_token_never_overrides_the_fixed_one(): void
    {
        $this->tokens->put('shutterstock', new TokenSet(self::TOKEN, $this->now->modify('-1 hour'), '3/synthetic-refresh'));
        $this->routeAccount();
        $library = $this->shutterstock(token: self::FIXED);

        $library->account();
        $refreshed = $library->refresh(new TokenSet(self::TOKEN, $this->now->modify('-1 hour'), '3/synthetic-refresh'));

        $this->assertSame('Bearer '.self::FIXED, $this->http->requests[0]->getHeaderLine('Authorization'));
        $this->assertSame(self::FIXED, $refreshed->accessToken, 'refresh() isn\'t needed: the fixed token comes back.');
        $this->assertSame([], $this->posts('/v2/oauth/access_token'));
        $this->assertSame(self::TOKEN, $this->tokens->get('shutterstock')?->accessToken, 'The kept token is left alone.');
    }

    public function test_connect_account_is_refused_while_a_fixed_token_is_set(): void
    {
        $this->tokens->put('shutterstock', new TokenSet(self::TOKEN));
        $library = $this->shutterstock(token: self::FIXED);

        foreach ([fn () => $library->authorizationUrl('state', self::CALLBACK), fn () => $library->connect('code', self::CALLBACK, 'state'), fn () => $library->disconnect()] as $call) {
            $exception = $this->failure($call);
            $this->assertInstanceOf(NotConnected::class, $exception);
            $this->assertSame('This site uses a token from its settings; remove it to connect an account instead.', $exception->getMessage());
        }

        $this->assertSame([], $this->http->requests);
        $this->assertNotNull($this->tokens->get('shutterstock'), 'Disconnect forgot nothing.');
    }

    public function test_a_refused_fixed_token_says_it_is_invalid_or_lacks_scopes(): void
    {
        $this->routePhoto('1000001');
        $this->route(self::API.'/v2/user/subscriptions', fn () => $this->http->response(403, 'Bad token '.self::FIXED));
        $this->routeLicences(fn () => $this->http->response(401, 'Bad token '.self::FIXED), ['data' => []]);
        $library = $this->shutterstock(token: self::FIXED);

        foreach ([fn () => $library->account(), fn () => $library->quotes('1000001'), fn () => $library->license('1000001', $this->quote(), 'record-1', 'Ann')] as $call) {
            $exception = $this->failure($call);
            $this->assertInstanceOf(NotConnected::class, $exception);
            $this->assertSame(Shutterstock::TOKEN_REFUSED, $exception->getMessage());
            $this->assertStringContainsString('lacks the scopes', $exception->getMessage());
            $this->assertStringNotContainsString(self::FIXED, $exception->getMessage());
        }

        $this->assertTrue($library->connected(), 'The token stays: only the settings can remove it.');
    }

    public function test_a_fixed_token_never_shows_in_dumps(): void
    {
        $library = $this->shutterstock(token: self::FIXED);

        $this->assertStringNotContainsString(self::FIXED, print_r($library, true));
        ob_start();
        var_dump($library);
        $this->assertStringNotContainsString(self::FIXED, (string) ob_get_clean());
        $this->assertStringContainsString("'token' => '***'", var_export($library->__debugInfo(), true));
    }

    protected function library(): PhotoLibrary
    {
        return $this->shutterstock();
    }

    protected function routeSearch(): void
    {
        $this->route(self::API.'/v2/images/search', ['data' => [$this->image('1000001'), $this->image('1000003', editorial: true), $this->image('1000002')], 'total_count' => 3, 'search_id' => 'synthetic-search']);
    }

    protected function routePhoto(string $id): void
    {
        $this->route(self::API."/v2/images/{$id}", $this->image($id, editorial: $id === '1000003'));
    }

    protected function contractId(): string
    {
        return '1000001';
    }

    protected function contractKey(): string
    {
        return self::SECRET;
    }

    private function shutterstock(bool $sandbox = false, bool $editorial = false, ?string $token = null): Shutterstock
    {
        return new Shutterstock($this->http, self::KEY, self::SECRET, $this->tokens, $sandbox, $editorial, fn () => $this->now, $token, $this->logger());
    }

    private function connected(): void
    {
        $this->tokens->put('shutterstock', new TokenSet(self::TOKEN, $this->now->modify('+1 hour'), '3/synthetic-refresh'));
    }

    private function quote(): Quote
    {
        return new Quote('1000001', 's1000:huge', 'Synthetic API subscription · Huge', Cost::units(1, Cost::DOWNLOAD), 'standard', 'huge');
    }

    private function routeAccount(bool $empty = false): void
    {
        $this->route(self::API.'/v2/user', ['id' => '9000001', 'username' => 'synthetic_user']);
        $this->route(self::API.'/v2/user/subscriptions', ['data' => [
            [
                'id' => 's1000',
                'license' => 'standard',
                'description' => 'Synthetic API subscription',
                'asset_type' => 'images',
                'expiration_time' => '2027-01-31T00:00:00Z',
                'allotment' => ['downloads_left' => $empty ? 0 : 742, 'downloads_limit' => 750, 'start_time' => '2026-10-01T00:00:00Z', 'end_time' => '2026-11-01T00:00:00Z'],
                'formats' => [
                    ['media_type' => 'image', 'description' => 'Small', 'format' => 'jpg', 'min_resolution' => 500, 'size' => 'small'],
                    ['media_type' => 'image', 'description' => 'Huge', 'format' => 'jpg', 'min_resolution' => 4000, 'size' => 'huge'],
                    ['media_type' => 'image', 'description' => 'Med', 'format' => 'jpg', 'min_resolution' => 1000, 'size' => 'medium'],
                    ['media_type' => 'image', 'description' => 'Vector', 'format' => 'eps', 'size' => 'vector'],
                ],
            ],
            ['id' => 's2000', 'license' => 'standard', 'description' => 'Used up', 'asset_type' => 'images', 'allotment' => ['downloads_left' => 0], 'formats' => [['media_type' => 'image', 'format' => 'jpg', 'size' => 'huge']]],
            ['id' => 's3000', 'license' => 'standard', 'description' => 'Footage', 'asset_type' => 'videos', 'allotment' => ['downloads_left' => 10]],
        ]]);
    }

    /**
     * POST answers from $post; GET (the licence history) from $history.
     *
     * @param  Closure(RequestInterface): ResponseInterface  $post
     * @param  array<mixed>  $history
     */
    private function routeLicences(Closure $post, array $history = ['data' => []]): void
    {
        $this->route(self::API.'/v2/images/licenses', fn (RequestInterface $request) => $request->getMethod() === 'POST' ? $post($request) : $this->json($history));
    }

    /**
     * @return array<int, RequestInterface>
     */
    private function posts(string $path): array
    {
        return array_values(array_filter($this->http->requests, fn (RequestInterface $request) => $request->getMethod() === 'POST' && $request->getUri()->getPath() === $path));
    }

    /**
     * @return array<string, mixed>
     */
    private function history(string $id, string $key): array
    {
        return [
            'id' => $id,
            'user' => ['username' => 'synthetic_user'],
            'license' => 'standard',
            'download_time' => '2026-10-02T12:00:30.000Z',
            'is_downloadable' => true,
            'image' => ['id' => '1000001', 'format' => ['size' => 'huge']],
            'subscription_id' => 's1000',
            'metadata' => ['customer_id' => $key],
        ];
    }

    /**
     * An image in the shape of Shutterstock's Image schema (view=full),
     * with made-up values.
     *
     * @return array<string, mixed>
     */
    private function image(string $id, bool $editorial = false): array
    {
        return [
            'id' => $id,
            'aspect' => 1.5,
            'description' => $editorial ? 'Potters at a synthetic fair, 2026' : 'Bowls mended with gold',
            'image_type' => 'photo',
            'media_type' => 'image',
            'is_editorial' => $editorial,
            'has_model_release' => ! $editorial,
            'asset_collection' => 'Essentials',
            'keywords' => ['pottery', 'kintsugi', 'gold'],
            'contributor' => ['id' => '7000001'],
            'assets' => [
                'preview' => ['url' => "https://previews.example.com/{$id}-450.jpg", 'width' => 450, 'height' => 300],
                'large_thumb' => ['url' => "https://thumbs.example.com/{$id}-large.jpg", 'width' => 150, 'height' => 100],
                'huge_thumb' => ['url' => "https://thumbs.example.com/{$id}-huge.jpg", 'width' => 390, 'height' => 260],
                'preview_1000' => ['url' => "https://previews.example.com/{$id}-1000.jpg", 'width' => 1000, 'height' => 667],
                'preview_1500' => ['url' => "https://previews.example.com/{$id}-1500.jpg", 'width' => 1500, 'height' => 1000],
            ],
        ];
    }

    private function failure(callable $call): Throwable
    {
        try {
            $call();
        } catch (Throwable $exception) {
            return $exception;
        }

        $this->fail('Expected a failure.');
    }
}
