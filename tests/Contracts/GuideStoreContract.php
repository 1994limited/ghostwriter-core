<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideStore;

/**
 * What every GuideStore must do. See SessionStoreContract for how an addon
 * runs it.
 */
trait GuideStoreContract
{
    abstract protected function guideStore(): GuideStore;

    abstract protected function storeFormat(): Format;

    public function test_a_guide_not_written_is_empty(): void
    {
        $guide = $this->guideStore()->guide(Guide::VOICE);

        $this->assertSame(Guide::VOICE, $guide->kind);
        $this->assertFalse($guide->exists());
        $this->assertNull($guide->updatedAt);
    }

    public function test_a_saved_guide_is_normalised_and_dated(): void
    {
        $store = $this->guideStore();
        $store->saveGuide(new Guide(Guide::VOICE, "# Voice\n\nPlain words. 🌷  \n\n\n"));

        $guide = $store->guide(Guide::VOICE);

        $this->assertSame("# Voice\n\nPlain words. 🌷\n", $guide->body);
        $this->assertTrue($guide->exists());
        $this->assertNotNull($guide->updatedAt);
        $this->assertFalse($store->guide(Guide::IMAGERY)->exists(), 'The two guides are kept apart.');
    }

    public function test_a_guide_is_saved_over(): void
    {
        $store = $this->guideStore();
        $store->saveGuide(new Guide(Guide::IMAGERY, 'One'));
        $store->saveGuide(new Guide(Guide::IMAGERY, 'Two'));

        $this->assertSame("Two\n", $store->guide(Guide::IMAGERY)->body);
    }

    public function test_each_guides_state_is_idle_until_saved_and_then_kept(): void
    {
        $store = $this->guideStore();

        $this->assertSame('idle', $store->state(Guide::VOICE)->status);

        $state = $store->state(Guide::VOICE);
        $state->begin('refine');
        $state->addMessage('user', 'Shorter sentences.');
        $state->scanned = [['title' => 'About', $this->storeFormat()->groupKey() => 'pages']];
        $store->saveState(Guide::VOICE, $state);

        $again = $store->state(Guide::VOICE);
        $this->assertSame('working', $again->status);
        $this->assertSame('refine', $again->task);
        $this->assertSame([['role' => 'user', 'content' => 'Shorter sentences.']], $again->messages);
        $this->assertSame($state->scanned, $again->scanned);
        $this->assertSame('idle', $store->state(Guide::IMAGERY)->status);
    }
}
