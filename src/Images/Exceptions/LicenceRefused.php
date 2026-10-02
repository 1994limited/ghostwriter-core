<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images\Exceptions;

use NineteenNinetyFour\Ghostwriter\Core\Images\PhotoUnavailable;

/**
 * The library won't license this photo to this account: the account lacks
 * the product, the photo is editorial-only, or not available in the
 * region. Also what a paid library's fetch() throws: its files are had by
 * licensing, not fetched. Nothing was bought.
 */
final class LicenceRefused extends PhotoUnavailable {}
