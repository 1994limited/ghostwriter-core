<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Review;

/**
 * What a comment is about: the whole page, one block, some words in a
 * block, or a top-level field.
 */
enum ScopeKind: string
{
    /** The whole page: every unit may change. */
    case Page = 'page';

    /** One block (or one card of several): its units. */
    case Block = 'block';

    /** Some words in a block: the unit they are in, and only their sentences. */
    case Text = 'text';

    /** A top-level field (the title, a summary): its units. */
    case Field = 'field';
}
