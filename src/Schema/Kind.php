<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Schema;

/**
 * What a field holds, as the writer sees it. Every CMS field type comes
 * down to one of these, which is what lets one writer serve every site:
 *
 *   text, longtext   plain strings
 *   richtext         written as markdown, stored however the CMS stores it
 *                    (HTML, Bard nodes, markdown): see RichTextDialect
 *   choice, choices  one or several of a fixed set of options
 *   toggle, number   a boolean, a number
 *   list             a list of short strings (Statamic's list and taggable)
 *   blocks           a page builder: a list of blocks, each one of the
 *                    field's sets (Matrix, Neo, a replicator, a Builder)
 *   rows             a list of rows sharing the same fields (a table, a
 *                    grid, a repeater)
 *   group            a nested set of fields (Statamic's group)
 *   reference        assets, entries, users, links, dates and anything else
 *                    the writer leaves for a person
 */
enum Kind: string
{
    case Text = 'text';
    case LongText = 'longtext';
    case RichText = 'richtext';
    case Choice = 'choice';
    case Choices = 'choices';
    case Toggle = 'toggle';
    case Number = 'number';
    case List = 'list';
    case Blocks = 'blocks';
    case Rows = 'rows';
    case Group = 'group';
    case Reference = 'reference';

    /** Kinds that configure how something looks rather than say anything. */
    public const SETTINGS = [self::Choice, self::Choices, self::Toggle, self::Number];

    /** Structured kinds that, when the same everywhere, are copied whole and never rewritten. */
    public const COPIED = [self::Rows, self::Blocks, self::Group, self::List, self::Reference];

    /**
     * Whether the writer fills a field of this kind in, as opposed to
     * leaving it for a person or for the site's usual values.
     */
    public function isWritable(): bool
    {
        return $this !== self::Reference;
    }

    public function isSetting(): bool
    {
        return in_array($this, self::SETTINGS, true);
    }

    public function isCopied(): bool
    {
        return in_array($this, self::COPIED, true);
    }
}
