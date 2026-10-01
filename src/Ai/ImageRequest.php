<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai;

/**
 * One image to be made. The references are pictures the site already uses
 * in the same place, handed over as the style to match; an editor's own
 * image (a logo, a product) comes last among them when there is one.
 *
 * Left null, `model` comes from the settings and then Models, and `timeout`
 * from the settings.
 */
final class ImageRequest
{
    public readonly Shape $shape;

    /**
     * @param  array<int, Image>  $references
     * @param  Shape|string  $shape  A Shape, or its value ("portrait"...) as older callers pass it. Unknown values are landscape.
     */
    public function __construct(
        public readonly string $prompt,
        public readonly array $references = [],
        Shape|string $shape = Shape::Landscape,
        public readonly ?string $model = null,
        public readonly ?int $timeout = null,
    ) {
        $this->shape = is_string($shape) ? (Shape::tryFrom($shape) ?? Shape::Landscape) : $shape;
    }
}
