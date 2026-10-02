<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions;

use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Quote;
use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoUnavailable;

/**
 * The price or option has changed since the person confirmed it. Nothing
 * was bought; ask again with `$quote`, the option as it is now, when the
 * library said.
 */
final class QuoteChanged extends PhotoUnavailable
{
    public function __construct(string $message = 'The price has changed since you confirmed it. Check it and try again.', public readonly ?Quote $quote = null)
    {
        parent::__construct($message);
    }
}
