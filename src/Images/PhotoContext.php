<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Shape;

/**
 * Where a photograph is wanted, in words: the page's title and summary, the
 * field's label, the words in the block the field sits in, the rest of the
 * page, the shape the field's images have, and the site's own description
 * of the images in this section (its imagery guide), if it has one.
 *
 * Each addon builds one from its own slot (a field on a form, an image in a
 * draft). Only the title is needed; the more there is, the better the
 * searches and the judging.
 */
final class PhotoContext
{
    public function __construct(
        public readonly string $title,
        public readonly string $label = '',
        public readonly string $blockText = '',
        public readonly string $pageText = '',
        public readonly string $summary = '',
        public readonly Shape $shape = Shape::Landscape,
        public readonly string $style = '',
    ) {}

    /**
     * @param  Shape|string  $shape  A Shape, or landscape, portrait or square.
     */
    public static function make(string $title, string $label = '', string $blockText = '', string $pageText = '', string $summary = '', Shape|string $shape = Shape::Landscape, string $style = ''): self
    {
        return new self(trim($title), trim($label), trim($blockText), trim($pageText), trim($summary), self::shape($shape), trim($style));
    }

    public static function shape(Shape|string $shape): Shape
    {
        return $shape instanceof Shape ? $shape : (Shape::tryFrom(strtolower(trim($shape))) ?? Shape::Landscape);
    }
}
