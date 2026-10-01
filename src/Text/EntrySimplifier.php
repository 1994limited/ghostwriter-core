<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Text;

use Closure;

/**
 * Reduces a stored entry to the form drafts are written in: only the fields
 * the writer fills, rich text as markdown, and none of the IDs or references
 * an editing screen adds. It is how existing entries are shown to the model
 * as examples, in exactly the shape it is asked to produce.
 *
 * Reference fields are never written, so they are left out. Blocks switched
 * off (`enabled: false`) are left out too.
 *
 * Options:
 * - `$richText`: how a stored rich text value becomes markdown, as
 *   `fn (mixed $value, array $spec): ?string`. Null (the default) reads it
 *   as HTML through HtmlToMarkdown, passing it through unchanged when the
 *   spec says `format: markdown` (a markdown editor already holds markdown),
 *   and drops anything that isn't a string. An addon that stores rich text
 *   in another form (a node tree) passes its own converter.
 */
class EntrySimplifier
{
    private readonly HtmlToMarkdown $markdown;

    /** @var (Closure(mixed, array<string, mixed>): ?string)|null */
    private readonly ?Closure $richText;

    /**
     * @param  (callable(mixed, array<string, mixed>): ?string)|null  $richText
     */
    public function __construct(?HtmlToMarkdown $markdown = null, ?callable $richText = null)
    {
        $this->markdown = $markdown ?? new HtmlToMarkdown;
        $this->richText = $richText === null ? null : Closure::fromCallable($richText);
    }

    /**
     * @param  array<string, mixed>  $data  The entry's data, in the shared entry-data shape.
     * @param  array<int, array<string, mixed>>  $schema
     * @return array<string, mixed>
     */
    public function simplify(array $data, array $schema): array
    {
        $out = [];

        foreach ($schema as $spec) {
            if (($spec['kind'] ?? null) === 'reference' || ! array_key_exists($spec['handle'], $data)) {
                continue;
            }

            $value = $this->value($data[$spec['handle']], $spec);

            if ($value !== null && $value !== '' && $value !== []) {
                $out[$spec['handle']] = $value;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function value(mixed $value, array $spec): mixed
    {
        return match ($spec['kind']) {
            'richtext' => $this->richText($value, $spec),
            'blocks' => $this->blocks((array) $value, $spec),
            'rows' => array_values(array_filter(array_map(
                fn ($row) => is_array($row) ? $this->simplify($row, $spec['fields'] ?? []) : null,
                (array) $value,
            ))),
            'group' => is_array($value) ? $this->simplify($value, $spec['fields'] ?? []) : null,
            'text', 'longtext' => is_string($value) ? trim($value) : null,
            default => is_scalar($value) || is_array($value) ? $value : null,
        };
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function richText(mixed $value, array $spec): ?string
    {
        if ($this->richText !== null) {
            return ($this->richText)($value, $spec);
        }

        if (! is_string($value)) {
            return null;
        }

        return trim(($spec['format'] ?? null) === 'markdown' ? $value : $this->markdown->convert($value));
    }

    /**
     * @param  array<int|string, mixed>  $sets
     * @param  array<string, mixed>  $spec
     * @return array<int, array<string, mixed>>
     */
    private function blocks(array $sets, array $spec): array
    {
        $out = [];

        foreach ($sets as $set) {
            // A block switched off by an editor is not part of the page.
            if (! is_array($set) || ($set['enabled'] ?? true) === false || ! isset($set['type'])) {
                continue;
            }

            $fields = $spec['sets'][$set['type']]['fields'] ?? null;

            if ($fields === null) {
                continue;
            }

            $out[] = ['type' => $set['type']] + $this->simplify($set, $fields);
        }

        return $out;
    }
}
