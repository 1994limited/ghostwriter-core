<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps;

/**
 * One SEO value of an entry, wherever an SEO addon keeps it: where it sits
 * in the form, whether it's the title or the description, its limit, its
 * effective text and whether Ghostwriter can write it.
 */
final class SeoField
{
    public const TITLE = 'title';

    public const DESCRIPTION = 'description';

    /** The usual limits, in characters, where the field has none of its own. */
    public const LIMITS = [self::TITLE => 60, self::DESCRIPTION => 160];

    /**
     * @param  string  $role  self::TITLE or self::DESCRIPTION.
     * @param  ?string  $text  The effective text: the custom value, or the one it inherits from another field; null when it comes from a template core can't evaluate.
     * @param  bool  $writable  False when the value is inherited from a template.
     * @param  ?string  $inheritsFrom  The label of the field it inherits from ("Summary").
     */
    public function __construct(
        public readonly FieldPath $path,
        public readonly string $role,
        public readonly string $label,
        public readonly int $limit,
        public readonly ?string $text,
        public readonly bool $writable = true,
        public readonly ?string $inheritsFrom = null,
    ) {}

    public function length(): int
    {
        return $this->text === null ? 0 : mb_strlen(trim($this->text));
    }

    public function tooLong(): bool
    {
        return $this->text !== null && $this->length() > $this->limit;
    }
}
