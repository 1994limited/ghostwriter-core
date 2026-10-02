<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps\Testing;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\AssetRefs;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\PlaceholderAssets;
use NineteenNinetyFour\Ghostwriter\Core\Images\Placeholders;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;

/**
 * The asset ports over plain values, for tests and the demo:
 *
 * - a files field holds `volume::path` strings (or bare paths, as
 *   Domain\Testing\MemoryAssetSink makes them), alone or in a list;
 * - rich text holds images as `asset::volume::path` anywhere in its value
 *   (`![Alt](asset::photos::rocks.jpg)` in markdown, a Bard image's `src`);
 * - the placeholder is the file named Placeholders::FILENAME, whatever its
 *   title.
 */
final class MemoryAssets implements AssetRefs, PlaceholderAssets
{
    public function in(mixed $value, Field $field): array
    {
        if ($field->kind === Kind::RichText) {
            $refs = [];

            foreach (self::strings($value) as $string) {
                if (preg_match_all('/asset::([A-Za-z0-9_-]+)::([^\s)"\'\]]+)/', $string, $matches, PREG_SET_ORDER) > 0) {
                    foreach ($matches as $match) {
                        $refs[] = new AssetRef($match[1], $match[2]);
                    }
                }
            }

            return $refs;
        }

        if (! $field->files) {
            return [];
        }

        $refs = [];

        foreach (self::strings($value) as $reference) {
            if (trim($reference) === '') {
                continue;
            }

            $parts = explode('::', $reference, 2);
            $refs[] = count($parts) === 2 ? new AssetRef($parts[0], $parts[1]) : new AssetRef('', $reference);
        }

        return $refs;
    }

    public function isPlaceholder(AssetRef $asset, Field $field): bool
    {
        return $asset->filename() === Placeholders::FILENAME;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        $strings = [];

        foreach (is_array($value) ? $value : [] as $item) {
            array_push($strings, ...self::strings($item));
        }

        return $strings;
    }
}
