<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images;

use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;

/**
 * Where an addon keeps the striped placeholder, and how its fields hold
 * it. Core decides where a placeholder goes (Placeholders); the sink saves
 * the file into the CMS's asset storage and says what the field stores.
 *
 * - Statamic: the field's asset container, at `ghostwriter/image-placeholder.png`,
 *   as an asset titled Placeholders::TITLE with alt text Placeholders::ALT;
 *   the field holds the path (a list unless `max_files` is 1).
 * - Craft: the field's volume (its default upload location, or the first
 *   it may choose from), as an asset named Placeholders::FILENAME; the
 *   field holds a list of asset IDs.
 * - Filament: the upload field's disk and directory, as
 *   Placeholders::FILENAME; the field holds the path (a list if `multiple`).
 *
 * The file is made once per place and reused, so the asset library does
 * not fill up with copies: the sink keeps what it has saved.
 */
interface AssetSink
{
    /**
     * The reference a field stores for the placeholder, saving the file
     * first if it isn't there yet. Null when it can't be saved (no
     * container, a failed write), which leaves the field empty.
     *
     * @param  callable(): string  $png  The placeholder's bytes (Placeholders::png()), made only when needed.
     */
    public function placeholder(Field $field, callable $png): string|int|null;

    /**
     * The field's value holding the placeholder: the reference alone, or a
     * list of it, as the field takes.
     */
    public function value(Field $field, string|int $reference): mixed;

    /**
     * One block of a page builder holding the placeholder in one of its
     * image fields, for a builder that holds nothing but images (a
     * gallery inside a block). Null where the CMS doesn't take a block
     * made this way, which leaves the builder empty.
     *
     * @return array<string, mixed>|null
     */
    public function block(Field $builder, string $set, Field $image, mixed $value): ?array;
}
