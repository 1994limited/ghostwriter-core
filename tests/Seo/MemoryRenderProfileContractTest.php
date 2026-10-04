<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Seo\Outline;
use NineteenNinetyFour\Ghostwriter\Core\Seo\RenderProfile;
use NineteenNinetyFour\Ghostwriter\Core\Seo\RenderProfiles;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoPass;
use NineteenNinetyFour\Ghostwriter\Core\Seo\Testing\InMemoryRenderProfiles;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\RenderProfileContractTest;

/**
 * The render profile contract on core's in-memory store, through
 * SeoPass::observe(), as the addons' outline endpoints call it.
 */
final class MemoryRenderProfileContractTest extends RenderProfileContractTest
{
    private ?RenderProfiles $store = null;

    protected function profiles(): RenderProfiles
    {
        return $this->store ??= new InMemoryRenderProfiles;
    }

    protected function record(string $key, array $outline): RenderProfile
    {
        return (new SeoPass)->observe($this->profiles(), $key, Outline::fromArray($outline), 'Pages')[0];
    }

    protected function seededOutline(): array
    {
        $fixture = json_decode((string) file_get_contents(dirname(__DIR__).'/Fixtures/seo/outlines.json'), true);

        return $fixture['cases'][0]['outline'];
    }

    public function test_observing_says_when_bodies_must_be_fitted_again(): void
    {
        $title = Outline::fromArray([['level' => 1, 'text' => 'Winter care', 'field' => 'title']]);
        $hero = Outline::fromArray([['level' => 1, 'text' => 'Gardens', 'field' => 'hero.heading']]);
        $seo = new SeoPass;

        $this->assertFalse($seo->observe($this->profiles(), 'k', $title)[1], 'The default already said the title is the H1.');
        $this->assertFalse($seo->observe($this->profiles(), 'k', $hero)[1], 'One render is pending.');
        $this->assertTrue($seo->observe($this->profiles(), 'k', $hero)[1]);
        $this->assertTrue($seo->observe($this->profiles(), 'j', $hero)[1], 'A first render that differs from the default changes it at once.');
        $this->assertSame(2, $this->profiles()->get('k')?->top(new Field('body', Kind::RichText)));
    }
}
