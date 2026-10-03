<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Anchor;

/**
 * Where QuoteFinder found a quote: an offset and a length in the text as
 * written (in characters), which of the quote's occurrences it is (0 for
 * the first), and whether it was found only by a fuzzy match, in which
 * case the words there aren't quite the quote's: requote() gives them.
 */
final class QuoteMatch
{
    public function __construct(
        public readonly int $offset,
        public readonly int $length,
        public readonly int $occurrence = 0,
        public readonly bool $fuzzy = false,
    ) {}

    /** The words matched, as the text has them. */
    public function text(string $text): string
    {
        return mb_substr($text, $this->offset, $this->length);
    }

    /** The quote re-taken from the text: what to store after a fuzzy match. */
    public function requote(string $text): TextQuote
    {
        return TextQuote::around($text, $this->offset, $this->length);
    }

    /**
     * @return array{offset: int, length: int, occurrence: int, fuzzy: bool}
     */
    public function toArray(): array
    {
        return ['offset' => $this->offset, 'length' => $this->length, 'occurrence' => $this->occurrence, 'fuzzy' => $this->fuzzy];
    }
}
