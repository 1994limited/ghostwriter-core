<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Layout;

use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\NoLinks;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Layout;

/**
 * The layout algorithms, built once with an addon's options and dialects:
 * a container singleton or a plugin component.
 *
 *     $layouts = new Layouts(LayoutOptions::craft(), new HtmlDialect, new CraftLinks(hyper: [...], link: [...]));
 *
 *     $pattern = $layouts->patterns()->find($schema, PatternFinder::choose($entries, $type->where));
 *     $built = $layouts->builder()->build($draft->data, $schema, $pattern, $type->defaults);
 *     $house = $layouts->houseStyle()->apply($built->data, $schema, $pattern->house, $id, $title);
 */
final class Layouts
{
    public function __construct(
        public readonly LayoutOptions $options = new LayoutOptions,
        public readonly RichTextDialect $richText = new HtmlDialect,
        public readonly LinkDialect $links = new NoLinks,
    ) {}

    public function describer(): SchemaDescriber
    {
        return new SchemaDescriber($this->options);
    }

    public function patterns(): PatternFinder
    {
        return new PatternFinder($this->options, $this->richText, $this->links);
    }

    public function kinds(): KindFinder
    {
        return new KindFinder($this->options);
    }

    public function houseStyle(): HouseStyle
    {
        return new HouseStyle($this->options, $this->richText, $this->links);
    }

    public function builder(): EntryBuilder
    {
        return new EntryBuilder($this->options, $this->richText);
    }

    /**
     * What the Studio's type analyst and writer are shown: the schema,
     * described, and the entries the pattern was found from.
     *
     * @param  array<int, EntryData>  $entries  The entries to learn from, newest first.
     */
    public function layout(Schema $schema, array $entries): Layout
    {
        return Layout::fromSchema($schema, $this->patterns()->find($schema, $entries), $this->describer());
    }
}
