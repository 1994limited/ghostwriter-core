<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Anchor;

use InvalidArgumentException;

/**
 * A quoted range of text, as the W3C Web Annotation TextQuoteSelector
 * names one: the exact words, and a little of what comes before and after
 * them to tell repeats apart. A comment on some words (page preview) and a
 * suggestion about some words (Suggest edits) both point at text this way,
 * so it still finds its words after the text around them moves.
 *
 * The exact words are at most 300 characters; the prefix keeps its last
 * 32 characters and the suffix its first 32.
 */
final class TextQuote
{
    public const MAX_EXACT = 300;

    public const MAX_CONTEXT = 32;

    public readonly string $prefix;

    public readonly string $suffix;

    public function __construct(
        public readonly string $exact,
        string $prefix = '',
        string $suffix = '',
    ) {
        if (trim($exact) === '') {
            throw new InvalidArgumentException('A quote needs some words.');
        }

        if (mb_strlen($exact) > self::MAX_EXACT) {
            throw new InvalidArgumentException('A quote is at most '.self::MAX_EXACT.' characters.');
        }

        $this->prefix = mb_substr($prefix, -self::MAX_CONTEXT);
        $this->suffix = mb_substr($suffix, 0, self::MAX_CONTEXT);
    }

    /**
     * The quote of a range of some text, with its context. Offsets are in
     * characters. A range longer than MAX_EXACT is cut to it.
     */
    public static function around(string $text, int $offset, int $length): self
    {
        $length = min($length, self::MAX_EXACT);

        return new self(
            mb_substr($text, $offset, $length),
            mb_substr($text, max(0, $offset - self::MAX_CONTEXT), min($offset, self::MAX_CONTEXT)),
            mb_substr($text, $offset + $length, self::MAX_CONTEXT),
        );
    }

    /**
     * @return array{exact: string, prefix?: string, suffix?: string}
     */
    public function toArray(): array
    {
        return array_filter(['exact' => $this->exact, 'prefix' => $this->prefix, 'suffix' => $this->suffix], fn (string $value, string $key) => $key === 'exact' || $value !== '', ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @param  array<mixed>  $array  From toArray().
     */
    public static function fromArray(array $array): self
    {
        $text = fn (string $key) => is_scalar($array[$key] ?? null) ? (string) $array[$key] : '';

        return new self($text('exact'), $text('prefix'), $text('suffix'));
    }
}
