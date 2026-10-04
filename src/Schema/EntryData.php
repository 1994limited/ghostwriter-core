<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Schema;

/**
 * An existing entry's content as plain data, in one shape whatever the CMS:
 * the shape the pattern finder, the kind finder and the house style read,
 * so none of them need to know whether a site uses Matrix or Neo, Bard or
 * CKEditor, Eloquent or flat files.
 *
 * `values` holds each field by handle (the title under `title` where the
 * entry has one):
 *
 * - a page builder is a list of blocks, each `['type' => ..., 'enabled' =>
 *   bool, ...its own fields]`, with the block's `id` where it has one. A Neo
 *   block's child blocks sit under `children`, as a tree, not Neo's flat
 *   list with levels.
 * - rows (a table, a grid, a repeater) are a list of arrays keyed by the
 *   row's field handles; a group is an array keyed by its field handles.
 * - rich text is as the CMS stores it (HTML, Bard nodes, markdown): the
 *   RichTextDialect reads it.
 * - choices are the stored values; references are what the CMS stores
 *   (element IDs, `entry::id` strings, asset paths).
 *
 * The other properties say where the entry sits: its ID (to find links to
 * itself, and to name it as an example), its title as a person would know
 * it, and the page it sits under, if any. An adapter leaves the parent out
 * when it is the site's home page, which every top-level page sits under.
 * Its group (a collection, section or resource handle) and site handle,
 * where the adapter gives them, say whose defaults apply to it (an SEO
 * addon's section and site defaults: SeoFields).
 */
final class EntryData
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(
        public readonly array $values,
        public readonly int|string|null $id = null,
        private readonly ?string $title = null,
        public readonly int|string|null $parentId = null,
        public readonly ?string $parentTitle = null,
        public readonly ?string $group = null,
        public readonly ?string $site = null,
    ) {}

    /**
     * The same entry, in the same place, with other values.
     *
     * @param  array<string, mixed>  $values
     */
    public function withValues(array $values): self
    {
        return new self($values, $this->id, $this->title, $this->parentId, $this->parentTitle, $this->group, $this->site);
    }

    /**
     * The entry's title: as given, or the `title` value.
     */
    public function title(): string
    {
        if ($this->title !== null) {
            return $this->title;
        }

        $title = $this->values['title'] ?? '';

        return is_scalar($title) ? (string) $title : '';
    }

    public function get(string $handle): mixed
    {
        return $this->values[$handle] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = ['id' => $this->id];

        if ($this->title !== null) {
            $array['title'] = $this->title;
        }

        if ($this->parentId !== null) {
            $array['parent'] = ['id' => $this->parentId, 'title' => $this->parentTitle];
        }

        $array['values'] = $this->values;

        return $array;
    }

    /**
     * @param  array<mixed>  $array  From toArray().
     */
    public static function fromArray(array $array): self
    {
        $values = [];

        foreach (is_array($array['values'] ?? null) ? $array['values'] : [] as $key => $value) {
            $values[(string) $key] = $value;
        }

        $parent = is_array($array['parent'] ?? null) ? $array['parent'] : [];

        return new self(
            $values,
            self::key($array['id'] ?? null),
            is_scalar($array['title'] ?? null) ? (string) $array['title'] : null,
            self::key($parent['id'] ?? null),
            is_scalar($parent['title'] ?? null) ? (string) $parent['title'] : null,
        );
    }

    private static function key(mixed $value): int|string|null
    {
        return is_int($value) || is_string($value) ? $value : null;
    }
}
