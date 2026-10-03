<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Arrange;

/**
 * What a unit of draft text is. A unit is what an editor means by "this
 * bit": a field value inside a block, a top-level field, or one section of
 * a rich-text value.
 */
enum UnitKind: string
{
    /** A text value: a heading, a button label. */
    case Text = 'text';

    /** A long text value, or rich text with no headings. */
    case Prose = 'prose';

    /** In rich text: a heading and what follows it, up to the next heading of that level or higher. */
    case Section = 'section';

    /** A list value, or rich text that is only a list. */
    case List = 'list';

    /** Rich text that is only a block quote. */
    case Quote = 'quote';

    /** An inline set in rich text (a Bard set, a CKEditor nested entry). Reserved: inline sets ride in their section's pieces for now. */
    case Set = 'set';

    /** An image field: no text; found on the page by its assets. */
    case Media = 'media';

    /** One row of a rows field (a table, a grid, a repeater). */
    case Row = 'row';
}
