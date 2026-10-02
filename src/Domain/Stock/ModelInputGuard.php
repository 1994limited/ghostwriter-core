<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Stock;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Keeps images a model may not see out of every model call. Getty's and
 * iStock's licences forbid any AI use of their content or its metadata,
 * licensed images included, so before any of the site's own images goes
 * into a prompt (photo-picker's reference images, the imagery analyst's
 * samples, any later vision feature) it is checked here. Refused:
 *
 * - an asset with a ledger record from a library whose assets may go to
 *   no model (Capabilities::$noModelInput, kept on the record), or from
 *   Getty or iStock (LIBRARIES) whatever the record says;
 * - a file named as Getty's downloads are: `GettyImages-*`, `iStock-*`;
 * - an image whose embedded IPTC or XMP credit, source or copyright names
 *   Getty Images or iStock, however it got onto the site.
 *
 * A refused image is left out quietly and logged at debug.
 *
 *     $guard = new ModelInputGuard($ledger, $logger);
 *     $guard->allowsImage($bytes, $assetRef, 'rocks.jpg');
 */
final class ModelInputGuard
{
    /** Libraries whose assets never go to a model, whatever a record says. */
    public const LIBRARIES = ['getty', 'istock'];

    /** How far into a file its embedded metadata is looked for. */
    private const SCAN_BYTES = 1024 * 1024;

    private const NAMES = '/^(gettyimages|istock)-/i';

    private const OWNERS = '/getty\s*images|istock/i';

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ?StockImageStore $ledger = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger;
    }

    /**
     * An asset (by its ledger record and file name), or an image's bytes.
     */
    public function allows(AssetRef|Image|string $input): bool
    {
        return $input instanceof AssetRef ? $this->allowsAsset($input) : $this->allowsImage($input);
    }

    /**
     * An image, checked by everything known about it: its bytes, and the
     * asset and file name it came from where the caller knows them.
     */
    public function allowsImage(Image|string $image, ?AssetRef $asset = null, ?string $filename = null): bool
    {
        return ($asset === null || $this->allowsAsset($asset))
            && ($filename === null || $this->allowsFilename($filename))
            && $this->allowsBytes($image instanceof Image ? $image->data : $image);
    }

    public function allowsAsset(AssetRef $asset): bool
    {
        if (! $this->allowsFilename($asset->filename())) {
            return false;
        }

        $record = $this->ledger?->forAsset($asset);

        if ($record !== null && ($record->noModelInput || in_array($record->library, self::LIBRARIES, true))) {
            return $this->refuse("its ledger record is from {$record->library}", $asset->key());
        }

        return true;
    }

    public function allowsFilename(string $filename): bool
    {
        return preg_match(self::NAMES, basename($filename)) ? $this->refuse('its file name is a Getty or iStock download\'s', basename($filename)) : true;
    }

    /**
     * Whether an image's embedded credit, source or copyright (IPTC, or
     * XMP) leaves it free of Getty Images and iStock.
     */
    public function allowsBytes(string $bytes): bool
    {
        foreach ($this->owners($bytes) as $owner) {
            if (preg_match(self::OWNERS, $owner)) {
                return $this->refuse('its embedded credit or copyright names Getty Images or iStock');
            }
        }

        return true;
    }

    /**
     * The credit, source and copyright lines embedded in an image.
     *
     * @return array<int, string>
     */
    private function owners(string $bytes): array
    {
        $owners = [];
        $info = [];

        if ($bytes !== '' && @getimagesizefromstring($bytes, $info) !== false && isset($info['APP13']) && is_array($iptc = @iptcparse($info['APP13']))) {
            // Credit, source, copyright notice and by-line.
            foreach (['2#110', '2#115', '2#116', '2#080'] as $tag) {
                foreach ((array) ($iptc[$tag] ?? []) as $value) {
                    $owners[] = (string) $value;
                }
            }
        }

        $head = substr($bytes, 0, self::SCAN_BYTES);
        $start = stripos($head, '<x:xmpmeta');

        if ($start !== false) {
            $end = stripos($head, '</x:xmpmeta>', $start);
            $xmp = substr($head, $start, $end === false ? null : $end - $start);
            $fields = 'photoshop:Credit|photoshop:Source|dc:rights|dc:creator|xmpRights:WebStatement|plus:CopyrightOwnerName|plus:LicensorName';

            preg_match_all('#<('.$fields.')\b[^>]*>(.*?)</\1>#is', $xmp, $elements);
            preg_match_all('#\b('.$fields.')\s*=\s*"([^"]*)"#i', $xmp, $attributes);

            array_push($owners, ...$elements[2], ...$attributes[2]);
        }

        return $owners;
    }

    private function refuse(string $why, string $what = 'an image'): bool
    {
        $this->logger->debug("Ghostwriter: {$what} was left out of a model call: {$why}.");

        return false;
    }
}
