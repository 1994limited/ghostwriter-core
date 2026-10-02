<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Images\Libraries;

use DateTimeImmutable;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\InsufficientBalance;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\LicenceRefused;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\LicensingUncertain;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\NotConnected;
use NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions\QuoteChanged;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\OAuth\TokenSet;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\StandIn;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Testing\InMemoryLibraryTokens;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoUnavailable;
use PHPUnit\Framework\TestCase;

final class LicensingTypesTest extends TestCase
{
    public function test_every_licensing_error_is_still_a_photo_unavailable(): void
    {
        foreach ([new NotConnected('x'), new QuoteChanged, new InsufficientBalance('x'), new LicenceRefused('x'), new LicensingUncertain] as $exception) {
            $this->assertInstanceOf(PhotoUnavailable::class, $exception);
            $this->assertInstanceOf(InvalidArgumentException::class, $exception, 'The addons\' controllers catch this.');
        }

        $this->assertStringContainsString("don't buy it again", (new LicensingUncertain)->getMessage());
    }

    public function test_a_stand_in_is_a_jpeg_at_the_photos_aspect_with_its_label(): void
    {
        $jpeg = StandIn::jpeg(4000, 3000, 'Getty Images 1234567 · preview, not licensed');
        $size = getimagesizefromstring($jpeg);

        $this->assertNotFalse($size);
        $this->assertSame('image/jpeg', $size['mime']);
        $this->assertSame([1600, 1200], [$size[0], $size[1]]);
        $this->assertSame([1067, 1600], StandIn::size(2000, 3000));
        $this->assertSame([1600, 200], StandIn::size(10000, 10), 'No side is drawn too thin to read.');
        $this->assertNotSame(StandIn::jpeg(4000, 3000, 'Getty Images 1'), StandIn::jpeg(4000, 3000, 'Getty Images 2'), 'The label is drawn.');
        $this->assertStringNotContainsString('Exif', substr($jpeg, 0, 64));
    }

    public function test_tokens_expire_a_little_early_and_never_show_in_a_dump(): void
    {
        $now = new DateTimeImmutable('2026-10-02T12:00:00+00:00');
        $tokens = TokenSet::fromResponse(['access_token' => 'secret-token', 'expires_in' => 1800, 'refresh_token' => 'secret-refresh', 'scope' => 'licenses.create purchases.view', 'token_type' => 'Bearer'], $now);

        $this->assertNotNull($tokens);
        $this->assertSame('2026-10-02T12:30:00+00:00', $tokens->expiresAt?->format(DATE_ATOM));
        $this->assertFalse($tokens->isExpired($now->modify('+28 minutes')));
        $this->assertTrue($tokens->isExpired($now->modify('+29 minutes 30 seconds')), 'A minute early, so a call never starts with a dying token.');
        $this->assertSame(['licenses.create', 'purchases.view'], $tokens->scopes);
        $this->assertSame('Bearer secret-token', $tokens->header());
        $this->assertTrue($tokens->canRefresh());
        $this->assertEquals($tokens, TokenSet::fromArray($tokens->toArray()));
        $this->assertNull(TokenSet::fromResponse(['error' => 'invalid_client']));

        $dump = print_r($tokens, true).var_export($tokens->__debugInfo(), true);
        $this->assertStringNotContainsString('secret-token', $dump);
        $this->assertStringNotContainsString('secret-refresh', $dump);

        $store = new InMemoryLibraryTokens;
        $store->put('getty', $tokens);
        $this->assertSame($tokens, $store->get('getty'));
        $store->forget('getty');
        $this->assertNull($store->get('getty'));
    }
}
