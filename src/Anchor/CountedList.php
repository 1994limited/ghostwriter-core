<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Anchor;

/**
 * A plain list found in some text, and how many items it has, as
 * ListCounter counted them: "Northumberland, Durham and the Tyne Valley"
 * is 3. The count is always core's, never a model's.
 */
final class CountedList
{
    public const AND = 'and';

    public const OR = 'or';

    public const BULLETS = 'bullets';

    /**
     * @param  list<string>  $items  The items, as written (markdown emphasis taken off).
     * @param  string  $text  The list exactly as it is in the text it was found in.
     * @param  string  $style  How it was written: `and`, `or` (inline, with that final word) or `bullets`.
     */
    public function __construct(
        public readonly array $items,
        public readonly string $text,
        public readonly string $style = self::AND,
    ) {}

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * The list on one line, as a marker's `from:` holds it: an inline list
     * exactly as written, a bulleted one with its items joined by "; ".
     */
    public function oneLine(): string
    {
        $line = $this->style === self::BULLETS ? implode('; ', $this->items) : $this->text;

        return trim((string) preg_replace('/\s+/u', ' ', str_replace(['[', ']', '|'], ['(', ')', '/'], $line)));
    }

    /** Whether another list has the same items, compared as words. */
    public function sameItems(self $other): bool
    {
        return $this->keys() === $other->keys();
    }

    /** How many items the two lists share, compared as words. */
    public function shared(self $other): int
    {
        return count(array_intersect(array_unique($this->keys()), array_unique($other->keys())));
    }

    /**
     * @return array{count: int, items: list<string>, text: string, style: string}
     */
    public function toArray(): array
    {
        return ['count' => $this->count(), 'items' => $this->items, 'text' => $this->text, 'style' => $this->style];
    }

    /**
     * @param  array<mixed>  $array
     */
    public static function fromArray(array $array): ?self
    {
        $items = array_values(array_map('strval', array_filter(is_array($array['items'] ?? null) ? $array['items'] : [], 'is_scalar')));

        if ($items === []) {
            return null;
        }

        $style = is_string($array['style'] ?? null) && in_array($array['style'], [self::AND, self::OR, self::BULLETS], true) ? $array['style'] : self::AND;

        return new self($items, is_scalar($array['text'] ?? null) ? (string) $array['text'] : implode(', ', $items), $style);
    }

    /**
     * Each item as words, without a leading article, so "the Tyne Valley"
     * and "Tyne Valley" are the same item.
     *
     * @return list<string>
     */
    private function keys(): array
    {
        return array_map(function (string $item) {
            $words = NormalisedText::words($item);

            if (count($words) > 1 && in_array($words[0], ['the', 'a', 'an'], true)) {
                array_shift($words);
            }

            return implode(' ', $words);
        }, $this->items);
    }
}
