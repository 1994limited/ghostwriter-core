<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Layout;

use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;

/**
 * A LinkDialect that marks a link still to choose with the `#gw-link:`
 * sentinel (Gaps\Markers) rather than pointing it at example.com. Core's
 * three dialects implement it; it is a separate interface so a LinkDialect
 * written outside core keeps working in 1.x.
 *
 * HouseStyle uses it when LayoutOptions::$linkSentinels is on.
 */
interface LinkPlaceholders
{
    /**
     * The values that make this field a link still to choose, keyed by
     * field handle as LinkDialect::placeholder() gives them: the field's
     * own value pointing at the sentinel with this hint, and any words for
     * it. Null when the field can't hold one.
     *
     * - Statamic's `link` fieldtype stores any string: `#gw-link:<hint>`.
     * - Craft's Link field and Hyper's URL type validate the address, so
     *   they get `https://example.com/#gw-link:<hint>`.
     *
     * @param  array<int, Field>  $siblings  The other fields of its block.
     * @return array<string, mixed>|null
     */
    public function placeholderFor(Field $field, array $siblings, string $hint): ?array;

    /**
     * Whether a text field can hold a link mark (rich text, markdown).
     * Where it can't, the writer is told to ask for the link instead:
     * `[[ask: link to the contact page]]`.
     */
    public function supportsLinks(Field $field): bool;
}
