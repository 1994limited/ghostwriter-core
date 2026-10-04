<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps;

/**
 * One SEO value of an entry, wherever an SEO addon keeps it: where it sits
 * in the form, whether it's the title or the description, its limit, the
 * text the page prints with it, where that text comes from, and whether
 * Ghostwriter can give the page a value of its own there.
 */
final class SeoField
{
    public const TITLE = 'title';

    public const DESCRIPTION = 'description';

    /** The usual limits, in characters, where the field has none of its own. */
    public const LIMITS = [self::TITLE => 60, self::DESCRIPTION => 160];

    /** Where the text comes from. */
    public readonly SeoSource $source;

    /**
     * @param  string  $role  self::TITLE or self::DESCRIPTION.
     * @param  ?string  $text  The effective text the page prints: the custom value, or what it inherits from another field or a default; null when it comes from a template core can't evaluate, or is switched off.
     * @param  bool  $writable  Whether Ghostwriter can put a custom value here through the form. False for a template, a switched-off value, and an inherited one the addon's form can't override in place.
     * @param  ?string  $inheritsFrom  The label of the field it inherits from ("Excerpt").
     * @param  ?SeoSource  $source  Where the text comes from; by default, Field with $inheritsFrom, Template without text, else Custom.
     */
    public function __construct(
        public readonly FieldPath $path,
        public readonly string $role,
        public readonly string $label,
        public readonly int $limit,
        public readonly ?string $text,
        public readonly bool $writable = true,
        public readonly ?string $inheritsFrom = null,
        ?SeoSource $source = null,
    ) {
        $this->source = $source ?? match (true) {
            $inheritsFrom !== null => SeoSource::Field,
            $text === null => SeoSource::Template,
            default => SeoSource::Custom,
        };
    }

    public function length(): int
    {
        return $this->text === null ? 0 : mb_strlen(trim($this->text));
    }

    public function tooLong(): bool
    {
        return $this->text !== null && $this->length() > $this->limit;
    }

    /** Whether the page prints no text here although it could: a custom value left empty, or an inherited one whose source is empty. */
    public function isEmpty(): bool
    {
        return $this->text !== null && $this->length() === 0;
    }

    /** Whether the text comes from another field or a section's or site's default. */
    public function inherited(): bool
    {
        return $this->source->inherited();
    }

    /** Whether there is text core can check: not a template, not switched off. */
    public function checkable(): bool
    {
        return $this->text !== null && $this->source !== SeoSource::Disabled;
    }
}
