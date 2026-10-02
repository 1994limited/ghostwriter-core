<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Images\Libraries;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\NotConnected;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Capabilities;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\ConnectsAccount;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\OAuth\Pkce;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\OAuth\TokenSet;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Testing\FakeLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Testing\InMemoryLibraryTokens;
use PHPUnit\Framework\TestCase;

/**
 * The "Connect account" port, against the fake the addons test with.
 */
final class ConnectsAccountTest extends TestCase
{
    private const CALLBACK = 'https://cms.example.com/cp/ghostwriter/libraries/demo/callback';

    private DateTimeImmutable $now;

    private InMemoryLibraryTokens $tokens;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-02 12:00:00');
        $this->tokens = new InMemoryLibraryTokens;
    }

    public function test_pkce_follows_rfc_7636_and_needs_nothing_kept(): void
    {
        // RFC 7636, appendix B.
        $this->assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', Pkce::challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'));

        $verifier = Pkce::verifier('state-1', 'secret');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $verifier);
        $this->assertSame($verifier, Pkce::verifier('state-1', 'secret'), 'The callback derives the same verifier.');
        $this->assertNotSame($verifier, Pkce::verifier('state-2', 'secret'));
        $this->assertNotSame($verifier, Pkce::verifier('state-1', 'another secret'));

        $this->expectException(\InvalidArgumentException::class);
        Pkce::verifier('', 'secret');
    }

    public function test_the_fake_connects_through_the_callback_and_keeps_the_tokens(): void
    {
        $library = $this->library();
        $this->assertInstanceOf(ConnectsAccount::class, $library);
        $this->assertFalse($library->connected());

        $url = $library->authorizationUrl('state-1', self::CALLBACK);
        $this->assertStringStartsWith(self::CALLBACK.'?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('state-1', $query['state']);

        $tokens = $library->connect((string) $query['code'], self::CALLBACK, 'state-1');

        $this->assertTrue($library->connected());
        $this->assertSame($tokens, $this->tokens->get('demo'));
        $this->assertSame($this->now->modify('+1 hour')->getTimestamp(), $tokens->expiresAt?->getTimestamp());
        $this->assertTrue($tokens->canRefresh());
        $this->assertSame('demo-pack', $library->account()->products[0]['id']);
    }

    public function test_a_code_is_refused_with_another_state_or_address_and_nothing_is_kept(): void
    {
        $library = $this->library();
        parse_str((string) parse_url($library->authorizationUrl('state-1', self::CALLBACK), PHP_URL_QUERY), $query);
        $code = (string) $query['code'];

        foreach ([[$code, self::CALLBACK, 'state-2'], [$code, 'https://evil.example.com/callback', 'state-1'], [$code, self::CALLBACK, ''], ['demo-guess', self::CALLBACK, 'state-1']] as [$c, $uri, $state]) {
            try {
                $library->connect($c, $uri, $state);
                $this->fail('Expected the code to be refused.');
            } catch (NotConnected $exception) {
                $this->assertStringNotContainsString($code, $exception->getMessage());
            }
        }

        $this->assertFalse($library->connected());

        $library->refusesConnect = true;

        try {
            $library->connect($code, self::CALLBACK, 'state-1');
            $this->fail('Expected a scripted refusal.');
        } catch (NotConnected) {
            $this->assertFalse($library->connected());
        }

        $library->connect($code, self::CALLBACK, 'state-1');
        $this->assertTrue($library->connected(), 'A refusal is scripted once.');
    }

    public function test_an_unconnected_account_is_refused_and_expired_tokens_are_refreshed(): void
    {
        $library = $this->library()->withPhotos('a');

        foreach ([fn () => $library->account(), fn () => $library->quotes('a')] as $call) {
            try {
                $call();
                $this->fail('Expected NotConnected.');
            } catch (NotConnected) {
                $this->addToAssertionCount(1);
            }
        }

        $this->connect($library);
        $first = $this->tokens->get('demo');
        $this->now = $this->now->modify('+2 hours');

        $library->quotes('a');

        $second = $this->tokens->get('demo');
        $this->assertNotNull($second);
        $this->assertNotSame($first?->accessToken, $second->accessToken);
        $this->assertFalse($second->isExpired($this->now));

        $third = $library->refresh($second);
        $this->assertSame($third, $this->tokens->get('demo'), 'refresh() keeps what it gets.');
    }

    public function test_a_refused_refresh_and_a_disconnect_forget_the_tokens(): void
    {
        $library = $this->library();
        $tokens = $this->connect($library);

        $library->refusesRefresh = true;

        try {
            $library->refresh($tokens);
            $this->fail('Expected NotConnected.');
        } catch (NotConnected) {
            $this->assertFalse($library->connected(), 'Revoked tokens are no use: connect again.');
        }

        $this->connect($library);
        $library->disconnect();
        $this->assertFalse($library->connected());
        $this->assertNull($this->tokens->get('demo'));
    }

    public function test_without_needs_oauth_the_fake_licenses_as_before(): void
    {
        $library = (new FakeLibrary)->withPhotos('a');

        $this->assertFalse($library->capabilities()->needsOAuth);
        $this->assertFalse($library->connected());
        $this->assertCount(1, $library->quotes('a'));
    }

    private function library(): FakeLibrary
    {
        return new FakeLibrary(
            capabilities: Capabilities::paid(Capabilities::QUOTES_BALANCE, 30, needsOAuth: true, termsCheckedAt: '2026-10-02'),
            clock: fn () => $this->now,
            tokens: $this->tokens,
        );
    }

    private function connect(FakeLibrary $library): TokenSet
    {
        parse_str((string) parse_url($library->authorizationUrl('s', self::CALLBACK), PHP_URL_QUERY), $query);

        return $library->connect((string) $query['code'], self::CALLBACK, 's');
    }
}
