<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images\Libraries;

use NineteenNinetyFour\Ghostwriter\Core\Images\Placeholders;
use RuntimeException;

/**
 * The stand-in a paid photo's slot holds until it is licensed: the
 * placeholder's stripes at the photo's aspect ratio (LONG_SIDE pixels on
 * its long side), and a label such as "Getty Images 1234567 · preview,
 * not licensed". It holds nothing of the provider's, so it is safe at a
 * public address, while the comp itself stays private.
 *
 * It is a JPEG, so the licensed file (asked for as a JPEG) can later
 * replace it in place without the extension changing.
 */
final class StandIn
{
    public const LONG_SIDE = 1600;

    /** The smallest a short side is drawn. */
    private const MIN_SIDE = 200;

    /**
     * @param  int  $width  The photo's width, or any width in its aspect ratio.
     * @param  int  $height  Its height.
     *
     * @throws RuntimeException without GD
     */
    public static function jpeg(int $width, int $height, string $label): string
    {
        if (! Placeholders::available()) {
            throw new RuntimeException('Drawing the stand-in image needs the GD extension.');
        }

        [$w, $h] = self::size($width, $height);
        $image = imagecreatetruecolor(max(1, $w), max(1, $h));

        if ($image === false) {
            throw new RuntimeException('The stand-in image could not be drawn.');
        }

        $light = (int) imagecolorallocate($image, 0xEE, 0xF0, 0xF3);
        $dark = (int) imagecolorallocate($image, 0xDD, 0xE1, 0xE6);
        $ink = (int) imagecolorallocate($image, 0x4A, 0x52, 0x5E);
        $band = Placeholders::BAND;

        imagefill($image, 0, 0, $light);

        // Bands at 45 degrees, as the placeholder's.
        for ($x = -$h; $x < $w; $x += $band * 2) {
            imagefilledpolygon($image, [$x, $h, $x + $band, $h, $x + $band + $h, 0, $x + $h, 0], $dark);
        }

        self::label($image, $label, $w, $h, $light, $ink);

        ob_start();
        imagejpeg($image, null, 85);

        return (string) ob_get_clean();
    }

    /**
     * The stand-in's size: the photo's aspect ratio, LONG_SIDE on the long
     * side, and no side under MIN_SIDE.
     *
     * @return array{0: int, 1: int}
     */
    public static function size(int $width, int $height): array
    {
        $width = max(1, $width);
        $height = max(1, $height);
        $scale = self::LONG_SIDE / max($width, $height);

        return [max(self::MIN_SIDE, (int) round($width * $scale)), max(self::MIN_SIDE, (int) round($height * $scale))];
    }

    /**
     * The label, centred on a light panel, in GD's built-in font drawn
     * three times its size so it reads at a glance.
     */
    private static function label(\GdImage $image, string $label, int $w, int $h, int $panel, int $ink): void
    {
        // GD's built-in fonts have no "·" or other non-ASCII.
        $text = trim((string) preg_replace('/[^\x20-\x7E]+/', '-', $label));

        if ($text === '') {
            return;
        }

        $font = 5;
        $scale = 3;
        $charW = imagefontwidth($font);
        $charH = imagefontheight($font);
        $max = max(1, intdiv($w - 80, $charW * $scale));
        $lines = explode("\n", wordwrap($text, $max, "\n", true));
        $textW = max(array_map('strlen', $lines)) * $charW;
        $textH = count($lines) * $charH;

        $small = imagecreatetruecolor(max(1, $textW), max(1, $textH));

        if ($small === false) {
            return;
        }

        imagefill($small, 0, 0, (int) imagecolorallocate($small, 0xEE, 0xF0, 0xF3));
        $smallInk = (int) imagecolorallocate($small, 0x4A, 0x52, 0x5E);

        foreach ($lines as $i => $line) {
            imagestring($small, $font, intdiv($textW - strlen($line) * $charW, 2), $i * $charH, $line, $smallInk);
        }

        $outW = $textW * $scale;
        $outH = $textH * $scale;
        $x = intdiv($w - $outW, 2);
        $y = intdiv($h - $outH, 2);

        imagefilledrectangle($image, $x - 24, $y - 24, $x + $outW + 24, $y + $outH + 24, $panel);
        imagerectangle($image, $x - 24, $y - 24, $x + $outW + 24, $y + $outH + 24, $ink);
        imagecopyresized($image, $small, $x, $y, 0, 0, $outW, $outH, $textW, $textH);
    }
}
