<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

/**
 * A rendered page's headings, in document order, as the preview's locator
 * reported them (locator.js `outline()`), posted by the addon after a
 * render. Read into a RenderProfile.
 */
final class Outline
{
    /** The most headings kept from one page. */
    public const MAX = 200;

    /**
     * @param  list<OutlineHeading>  $headings
     */
    public function __construct(public readonly array $headings = []) {}

    /**
     * From what the locator posted; anything that isn't a heading is left out.
     *
     * @param  array<mixed>  $headings
     */
    public static function fromArray(array $headings): self
    {
        $out = [];

        foreach (array_slice($headings, 0, self::MAX) as $heading) {
            if (is_array($heading) && ($read = OutlineHeading::fromArray($heading)) !== null) {
                $out[] = $read;
            }
        }

        return new self($out);
    }

    /**
     * @return list<array{level: int, text: string, field: string|null, unit: string|null, inContent: bool}>
     */
    public function toArray(): array
    {
        return array_map(fn (OutlineHeading $heading) => $heading->toArray(), $this->headings);
    }

    /**
     * The template's own headings at a level: not part of a rich-text value.
     *
     * @return list<OutlineHeading>
     */
    public function template(int $level): array
    {
        return array_values(array_filter($this->headings, fn (OutlineHeading $heading) => $heading->level === $level && ! $heading->inContent));
    }

    /**
     * Headings inside rich-text values at a level.
     *
     * @return list<OutlineHeading>
     */
    public function content(int $level): array
    {
        return array_values(array_filter($this->headings, fn (OutlineHeading $heading) => $heading->level === $level && $heading->inContent));
    }
}
