<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images;

use InvalidArgumentException;

/**
 * A photograph or search that can't be used, with a message an editor can
 * read: an unknown photo, an address that isn't https, a file too large or
 * not an image, a library that failed. Never carries a key.
 *
 * It extends InvalidArgumentException because that is what the addons'
 * own photo code threw, and their controllers already catch it. The
 * licensing errors (Images\Exceptions) extend it, so those catches still
 * work.
 */
class PhotoUnavailable extends InvalidArgumentException {}
