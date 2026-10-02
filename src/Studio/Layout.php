<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Studio;

/**
 * What a kind of content is built from, as the type analyst and the writer
 * are shown it: the fields, already described as text (the addon's
 * SchemaDescriber), and the existing entries modelled on, as the addon's
 * PatternFinder gives them.
 */
final class Layout
{
    /**
     * @param  string  $fields  The fields, described.
     * @param  array<int, array<string, mixed>>  $examples  Existing entries, simplified, newest first; each is shown as YAML.
     * @param  int  $studied  How many entries were looked at.
     */
    public function __construct(
        public readonly string $fields,
        public readonly array $examples = [],
        public readonly int $studied = 0,
    ) {}

    /**
     * From a described schema and a PatternFinder pattern, which has the
     * examples under `examples` and the count under `entries`.
     *
     * @param  array<string, mixed>  $pattern
     */
    public static function fromPattern(string $fields, array $pattern): self
    {
        $examples = array_values(array_filter((array) ($pattern['examples'] ?? []), 'is_array'));
        $studied = $pattern['entries'] ?? 0;

        return new self($fields, $examples, is_numeric($studied) ? (int) $studied : 0);
    }
}
