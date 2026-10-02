<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions;

use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoUnavailable;

/**
 * The library needs a connected account, or refused the one it has: no
 * key or secret set, a token revoked or expired beyond refreshing.
 * Nothing was bought.
 */
final class NotConnected extends PhotoUnavailable {}
