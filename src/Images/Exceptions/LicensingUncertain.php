<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions;

use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoUnavailable;

/**
 * The licence call failed after it may have charged: the connection
 * dropped, it timed out, or the provider answered with an error or
 * something unreadable once the purchase was sent. Never retry it: leave
 * the ledger record `licensing` and reconcile it from the library's own
 * licences (StockImages::reconcile()).
 */
final class LicensingUncertain extends PhotoUnavailable
{
    public const MESSAGE = 'We couldn\'t confirm the purchase. Ghostwriter will check with the library in a few minutes; don\'t buy it again.';

    public function __construct(string $message = self::MESSAGE)
    {
        parent::__construct($message);
    }
}
