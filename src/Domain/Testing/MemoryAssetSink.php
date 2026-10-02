<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Testing;

use NineteenNinetyFour\Ghostwriter\Core\Images\AssetSink;
use NineteenNinetyFour\Ghostwriter\Core\Images\Placeholders;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;

/**
 * An AssetSink that keeps the placeholder in memory, one per place (the
 * field's `container` meta, or "default"), as `<place>/ghostwriter-image-placeholder.png`.
 * A field holds a list unless its `max_files` meta is 1; blocks are made
 * as `{type, enabled, <field>}` unless `blocks` is off.
 */
final class MemoryAssetSink implements AssetSink
{
    /** @var array<string, string> The PNG saved, by place. */
    public array $saved = [];

    public int $drawn = 0;

    public function __construct(private readonly bool $blocks = true, private readonly bool $fails = false) {}

    public function placeholder(Field $field, callable $png): ?string
    {
        if ($this->fails) {
            return null;
        }

        $place = is_string($field->meta['container'] ?? null) ? $field->meta['container'] : 'default';

        if (! isset($this->saved[$place])) {
            $this->saved[$place] = $png();
            $this->drawn++;
        }

        return $place.'/'.Placeholders::FILENAME;
    }

    public function value(Field $field, string|int $reference): mixed
    {
        return ($field->meta['max_files'] ?? null) === 1 ? $reference : [$reference];
    }

    public function block(Field $builder, string $set, Field $image, mixed $value): ?array
    {
        return $this->blocks ? ['type' => $set, 'enabled' => true, $image->handle => $value] : null;
    }
}
