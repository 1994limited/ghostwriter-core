<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Layout;

use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;

/**
 * How one CMS stores rich text, for the layout algorithms: the writer
 * always works in markdown, and the house style learns and copies how the
 * site dresses its text (a hero heading that is always centred and bold).
 *
 * Core has HtmlDialect (Craft's CKEditor and Redactor, Filament's
 * RichEditor and MarkdownEditor). Statamic's adapter provides a Bard
 * dialect, whose values are ProseMirror node lists.
 *
 * A "shape" is whatever the dialect learns about how one kind of text
 * element is dressed (HTML attributes and inline wrappers; Bard attrs and
 * marks). Core only counts and compares shapes, so they must be arrays
 * that json_encode() the same way when they are the same.
 */
interface RichTextDialect
{
    /**
     * A draft's markdown as the field stores it. The markdown came from a
     * model: raw HTML in it must be escaped, never passed through.
     */
    public function fromMarkdown(string $markdown, Field $field): mixed;

    /**
     * A stored value as markdown, for showing an entry to the model as an
     * example. Null when there is nothing to show.
     */
    public function toMarkdown(mixed $value, Field $field): ?string;

    /**
     * Whether a stored value holds something written, so the house style
     * learns from it and dresses it.
     */
    public function isWritten(mixed $value): bool;

    /**
     * How the text elements in these samples (stored values that are
     * written, from the same place in several entries) are dressed, where
     * more than half agree, keyed by the kind of element. A plain element
     * is the default and is left out.
     *
     * @param  array<int, mixed>  $samples
     * @return array<string, array<string, mixed>>
     */
    public function shapes(array $samples): array;

    /**
     * The writer's plain elements, dressed in the house shapes. Elements the
     * writer dressed are left as they are.
     *
     * @param  array<string, array<string, mixed>>  $shapes  From shapes().
     */
    public function dress(mixed $value, array $shapes): mixed;
}
