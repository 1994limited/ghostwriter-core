<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Queue\WaitingStore;

/**
 * What every WaitingStore must do. See SessionStoreContract for how an
 * addon runs it.
 */
trait WaitingStoreContract
{
    abstract protected function waitingStore(): WaitingStore;

    public function test_a_mark_is_kept_until_unmarked(): void
    {
        $store = $this->waitingStore();

        $this->assertNull($store->markedAt('session:01M3WWM3KKW1FV098209K5HHYE'));

        $store->mark('session:01M3WWM3KKW1FV098209K5HHYE', 1790907895);
        $store->mark('image:8f6333b1434f62216723c86d40', 1790907000);

        $this->assertSame(1790907895, $store->markedAt('session:01M3WWM3KKW1FV098209K5HHYE'));

        $store->mark('session:01M3WWM3KKW1FV098209K5HHYE', 1790907900);
        $this->assertSame(1790907900, $store->markedAt('session:01M3WWM3KKW1FV098209K5HHYE'));

        $store->unmark('session:01M3WWM3KKW1FV098209K5HHYE');
        $store->unmark('session:01M3WWM3KKW1FV098209K5HHYE');

        $this->assertNull($store->markedAt('session:01M3WWM3KKW1FV098209K5HHYE'));
        $this->assertSame(1790907000, $store->markedAt('image:8f6333b1434f62216723c86d40'));
    }
}
