<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Studio;

use NineteenNinetyFour\Ghostwriter\Core\Layout\Pattern;
use NineteenNinetyFour\Ghostwriter\Core\Layout\SchemaDescriber;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Seo\RenderProfile;

/**
 * What a kind of content is built from, as the type analyst and the writer
 * are shown it: the fields, described as text, and the existing entries
 * modelled on.
 *
 * Built from a structured schema with fromSchema(), which describes it with
 * core's SchemaDescriber; or, as before core 0.4, from fields an addon
 * already described, with fromPattern() or the constructor.
 */
final class Layout
{
    /**
     * @param  string  $fields  The fields, described.
     * @param  array<int, array<string, mixed>>  $examples  Existing entries, simplified, newest first; each is shown as YAML.
     * @param  int  $studied  How many entries were looked at.
     * @param  Schema|null  $schema  The schema the fields were described from, when there was one.
     */
    public function __construct(
        public readonly string $fields,
        public readonly array $examples = [],
        public readonly int $studied = 0,
        public readonly ?Schema $schema = null,
    ) {}

    /**
     * From a schema and what the pattern finder found in the group's
     * entries: the schema described by core's SchemaDescriber (pass one
     * built with the addon's LayoutOptions), the examples and the count
     * from the pattern. With the group's render profile, rich-text fields
     * list the heading levels the template and their editors leave them.
     *
     * @param  Pattern|array<string, mixed>  $pattern
     */
    public static function fromSchema(Schema $schema, Pattern|array $pattern = [], SchemaDescriber $describer = new SchemaDescriber, ?RenderProfile $profile = null): self
    {
        $pattern = $pattern instanceof Pattern ? $pattern : Pattern::fromArray($pattern);

        return new self($describer->describe($schema, $pattern, $profile), $pattern->examples, $pattern->entries, $schema);
    }

    /**
     * From a described schema and a PatternFinder pattern, which has the
     * examples under `examples` and the count under `entries`.
     *
     * @param  array<string, mixed>|Pattern  $pattern
     */
    public static function fromPattern(string $fields, array|Pattern $pattern): self
    {
        if ($pattern instanceof Pattern) {
            return new self($fields, $pattern->examples, $pattern->entries);
        }

        $examples = array_values(array_filter((array) ($pattern['examples'] ?? []), 'is_array'));
        $studied = $pattern['entries'] ?? 0;

        return new self($fields, $examples, is_numeric($studied) ? (int) $studied : 0);
    }
}
