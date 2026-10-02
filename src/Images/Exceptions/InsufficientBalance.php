<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions;

use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoUnavailable;

/**
 * The account has no downloads or credits left for this licence. Nothing
 * was bought.
 */
final class InsufficientBalance extends PhotoUnavailable {}
