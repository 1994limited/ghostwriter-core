<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

/**
 * What a suggested edit is about. The values are fixed English slugs, in
 * JSON and in the model's reply; the names people see are UI strings
 * (`suggest.category.<value>`).
 */
enum Category: string
{
    case OutOfDate = 'out-of-date';
    case Voice = 'voice';
    case Clarity = 'clarity';
    case FactToCheck = 'fact-to-check';
    case Link = 'link';
    case Accessibility = 'accessibility';
    case Seo = 'seo';
    case Duplicate = 'duplicate';

    /** Accept all wording fixes takes these: rewording only, nothing about facts, links, images or dates. */
    public function isWording(): bool
    {
        return in_array($this, [self::Voice, self::Clarity, self::Seo], true);
    }

    /**
     * Which wins when two suggestions overlap (lower wins), and the order
     * in which the cap keeps them: facts, dates, links, alt text, SEO,
     * duplicates, clarity, voice.
     */
    public function rank(): int
    {
        return match ($this) {
            self::FactToCheck => 1,
            self::OutOfDate => 2,
            self::Link => 3,
            self::Accessibility => 4,
            self::Seo => 5,
            self::Duplicate => 6,
            self::Clarity => 7,
            self::Voice => 8,
        };
    }

    /** The key of its name: "Out of date", "Fact to check". */
    public function label(): string
    {
        return 'suggest.category.'.$this->value;
    }

    /** The key of the mark's short speech label: "A bit old", "Still true?". */
    public function speech(): string
    {
        return 'suggest.speech.'.$this->value;
    }
}
