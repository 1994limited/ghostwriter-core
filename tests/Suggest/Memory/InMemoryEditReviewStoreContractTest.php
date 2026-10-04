<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Suggest\Memory;

use NineteenNinetyFour\Ghostwriter\Core\Suggest\EditReviewStore;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Testing\InMemoryEditReviewStore;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\EditReviewStoreContractTest;

final class InMemoryEditReviewStoreContractTest extends EditReviewStoreContractTest
{
    private ?InMemoryEditReviewStore $store = null;

    protected function editReviewStore(): EditReviewStore
    {
        return $this->store ??= new InMemoryEditReviewStore;
    }
}
