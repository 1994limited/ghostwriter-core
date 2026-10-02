<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images;

use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use RuntimeException;

/**
 * Marks where an image belongs but none has been chosen: soft grey
 * diagonal stripes, the usual sign of a picture still to come, so the page
 * shows its layout as it will be and nobody can mistake the placeholder
 * for a real image (D10: one rule and one setting everywhere).
 *
 * The rule: an image field a new record's draft left empty gets the
 * placeholder when the field is required, or when at least half of the
 * existing records (for a block, half the blocks of that type) have an
 * image there (`$rates`, the pattern's `filled`). An optional picture,
 * such as a background few pages use, is left empty. A field that takes
 * only other files (PDFs, videos) never gets one, and neither does one a
 * person filled. Blocks in the draft have their own image fields filled
 * the same way; an empty builder that holds nothing but images gets one
 * block with the placeholder, where the CMS takes it (AssetSink::block()).
 *
 * The setting is `placeholder_images` ("Mark images still to choose"), on
 * unless turned off. Editing an existing record never adds placeholders.
 *
 *     $placeholders = new Placeholders($sink, $pattern->filled);
 *     $data = $placeholders->fill($data, $schema);
 *     if ($note = $placeholders->note()) { $notes[] = $note; }
 */
final class Placeholders
{
    /** The setting's key in all three. */
    public const SETTING = 'placeholder_images';

    public const LABEL = 'Mark images still to choose';

    public const HELP = 'A striped placeholder in image fields a draft leaves empty, where records usually have an image. Replace them before publishing.';

    /** The file's name, where the CMS names it (Craft, Filament); Statamic keeps it at `ghostwriter/image-placeholder.png`. */
    public const FILENAME = 'ghostwriter-image-placeholder.png';

    /** The asset's title, where the CMS gives assets one. */
    public const TITLE = 'Image to choose (placeholder from Ghostwriter)';

    /** The asset's alt text, where the CMS keeps it. */
    public const ALT = 'Image to choose';

    /** Share of existing records or blocks with an image that makes one expected. */
    public const EXPECTED = 0.5;

    public const WIDTH = 1600;

    public const HEIGHT = 1000;

    /** Stripe width, wide enough to read at any size the image is shown. */
    public const BAND = 40;

    /** @var array<int, string> Where placeholders went, for the note. */
    private array $filled = [];

    private ?string $png = null;

    /**
     * @param  array<string, float>  $rates  How often each field is filled ("handle", or "set.handle" inside blocks).
     */
    public function __construct(private readonly AssetSink $sink, private readonly array $rates = []) {}

    /**
     * @param  array<string, mixed>  $data  The new record's data, in core's EntryData shape.
     * @param  Schema|array<int, Field>  $fields
     * @return array<string, mixed>
     */
    public function fill(array $data, Schema|array $fields, ?string $block = null, ?string $set = null): array
    {
        foreach ($fields instanceof Schema ? $fields->fields : $fields as $field) {
            $handle = $field->handle;
            $label = ($block !== null ? "{$block}: " : '').($field->label !== '' ? $field->label : $handle);
            $value = $data[$handle] ?? null;
            $expected = $this->expected($field, ($set !== null ? "{$set}." : '').$handle);

            if ($field->files) {
                if (empty($value) && $expected && self::takesImages($field) && ($reference = $this->placeholderFor($field)) !== null) {
                    $data[$handle] = $this->sink->value($field, $reference);
                    $this->filled[] = $label;
                }

                continue;
            }

            if (! $field->isBuilder()) {
                continue;
            }

            if (is_array($value) && $value !== []) {
                // Blocks in the draft: fill in each one's own image fields.
                foreach ($value as $i => $item) {
                    $type = is_array($item) && is_string($item['type'] ?? null) ? $item['type'] : '';
                    $found = $field->set($type);

                    if (is_array($item) && $found !== null) {
                        $data[$handle][$i] = $this->fill($item, $found->fields, $block ?? $found->label, $type);
                    }
                }
            } elseif ($expected && self::imagesOnly($field) && ($item = $this->imageBlock($field)) !== null) {
                $data[$handle] = [$item];
                $this->filled[] = $label;
            }
        }

        return $data;
    }

    /**
     * The places given a placeholder, by label.
     *
     * @return array<int, string>
     */
    public function filled(): array
    {
        return array_values(array_unique($this->filled));
    }

    /**
     * The note shown after a draft is used, when any placeholder went in.
     */
    public function note(): ?string
    {
        $filled = $this->filled();

        return $filled === [] ? null : 'A striped placeholder marks each image still to pick: '.implode('; ', $filled).'. Replace them before publishing.';
    }

    /**
     * Whether placeholders can be drawn: they need the GD extension.
     */
    public static function available(): bool
    {
        return extension_loaded('gd');
    }

    /**
     * Soft grey diagonal stripes edge to edge, 1600 × 1000, as a PNG.
     *
     * @throws RuntimeException without GD
     */
    public static function png(): string
    {
        if (! self::available()) {
            throw new RuntimeException('Drawing the image placeholder needs the GD extension.');
        }

        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);

        if ($image === false) {
            throw new RuntimeException('The image placeholder could not be drawn.');
        }

        $light = (int) imagecolorallocate($image, 0xEE, 0xF0, 0xF3);
        $dark = (int) imagecolorallocate($image, 0xDD, 0xE1, 0xE6);

        imagefill($image, 0, 0, $light);

        // Bands at 45 degrees.
        for ($x = -self::HEIGHT; $x < self::WIDTH; $x += self::BAND * 2) {
            imagefilledpolygon($image, [$x, self::HEIGHT, $x + self::BAND, self::HEIGHT, $x + self::BAND + self::HEIGHT, 0, $x + self::HEIGHT, 0], $dark);
        }

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * A files field that may hold images (not one kept to PDFs or videos).
     */
    public static function takesImages(Field $field): bool
    {
        return $field->files && (bool) ($field->meta['images'] ?? false);
    }

    /**
     * A builder whose every block type holds only file fields.
     */
    public static function imagesOnly(Field $field): bool
    {
        if ($field->sets === []) {
            return false;
        }

        foreach ($field->sets as $set) {
            foreach ($set->fields as $inner) {
                if (! $inner->files) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Required, or filled on most of the records (or blocks) like this one.
     * With no history to go by, only a required field.
     */
    private function expected(Field $field, string $key): bool
    {
        return $field->required || ($this->rates[$key] ?? 0) >= self::EXPECTED;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function imageBlock(Field $builder): ?array
    {
        foreach ($builder->sets as $handle => $set) {
            foreach ($set->fields as $field) {
                if (self::takesImages($field) && ($reference = $this->placeholderFor($field)) !== null) {
                    return $this->sink->block($builder, (string) $handle, $field, $this->sink->value($field, $reference));
                }
            }
        }

        return null;
    }

    private function placeholderFor(Field $field): string|int|null
    {
        return $this->sink->placeholder($field, fn () => $this->png ??= self::png());
    }
}
