<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use Throwable;

/**
 * Makes images small enough to show a model many at once: at most 512
 * pixels on the longer side, as a JPEG. Uses Imagick when it is loaded,
 * then GD; with neither, an image under 1 MB is sent as it is and a larger
 * one is left out.
 */
class Shrinker
{
    public const SIDE = 512;

    /** Without Imagick or GD, the largest image sent as it is. */
    public const UNSHRUNK_BYTES = 1_000_000;

    /** The image made small, or null when the bytes aren't an image or can't be made small enough. */
    public function small(string $content): ?Image
    {
        $size = $content === '' ? false : @getimagesizefromstring($content);

        if ($size === false) {
            return null;
        }

        if ($size[0] <= self::SIDE && $size[1] <= self::SIDE && strlen($content) < self::UNSHRUNK_BYTES && in_array($size['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            return new Image($content, $size['mime']);
        }

        $small = $this->imagick($content) ?? $this->gd($content);

        if ($small !== null) {
            return $small;
        }

        return strlen($content) < self::UNSHRUNK_BYTES ? new Image($content, $size['mime']) : null;
    }

    private function imagick(string $content): ?Image
    {
        if (! extension_loaded('imagick')) {
            return null;
        }

        try {
            $image = new \Imagick;
            $image->readImageBlob($content);
            $image->thumbnailImage(self::SIDE, self::SIDE, true);
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(75);
            $blob = $image->getImageBlob();
            $image->clear();

            return $blob !== '' ? new Image($blob, 'image/jpeg') : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function gd(string $content): ?Image
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        try {
            $source = @imagecreatefromstring($content);

            if ($source === false) {
                return null;
            }

            $width = imagesx($source);
            $height = imagesy($source);
            $scale = min(1, self::SIDE / max($width, $height, 1));
            $small = imagescale($source, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));

            if ($small === false) {
                return null;
            }

            ob_start();
            imagejpeg($small, null, 75);
            $blob = (string) ob_get_clean();

            return $blob !== '' ? new Image($blob, 'image/jpeg') : null;
        } catch (Throwable) {
            return null;
        }
    }
}
