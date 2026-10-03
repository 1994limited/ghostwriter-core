<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Arrange;

/**
 * The fixed ways a plan may reshape the words it places. None adds, drops
 * or rewords a word; a lead-in's closing full stop or colon is the only
 * punctuation that comes or goes.
 */
enum Transform: string
{
    /** As written. */
    case AsIs = 'as-is';

    /** A unit's pieces into several places (list items into cards): placed by piece, so as written. */
    case Split = 'split';

    /** Several units into one field, in order: as written. */
    case Join = 'join';

    /** "**November: Cut back.** Prune…" becomes a heading "November: Cut back" and the paragraph "Prune…". */
    case LeadInToHeading = 'lead-in-to-heading';

    /** A heading and the paragraph after it become "**Heading.** Paragraph". */
    case HeadingToLeadIn = 'heading-to-lead-in';

    /** Each paragraph becomes a list item, words unchanged. */
    case ParagraphsToList = 'paragraphs-to-list';

    /** Each list item becomes a paragraph. */
    case ListToParagraphs = 'list-to-paragraphs';

    /** Headings move to the level in the options (`level`), deeper ones keeping their distance. */
    case HeadingLevel = 'heading-level';

    /** The words as a block quote, or into a quote block's field. */
    case AsQuote = 'as-quote';
}
