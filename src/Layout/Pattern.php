<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Layout;

/**
 * How a group's entries are really put together, which the schema alone
 * cannot say, as PatternFinder::find() found it.
 *
 * - `entries`: how many entries were studied; `words`: the median length of
 *   their writing, in words.
 * - `blocks`: for each page builder, by handle: `sequence` (the commonest
 *   order of block types), `usage` (the share of entries using each type),
 *   `fixed` (each type's values that are the same on nearly every block),
 *   `used` (each type's fields that anyone fills in) and `boilerplate` (the
 *   types copied whole rather than written).
 * - `fixed`: the entry's own values that are house defaults.
 * - `examples`: the two newest entries, simplified, without those defaults.
 * - `filled`: how often each field holds something ("hero.image" => 0.8).
 * - `house`: what the entries agree on place by place (HouseRules).
 *
 * toArray() gives the array the addons' PatternFinders returned, which is
 * what Studio's Layout::fromPattern() and the addons' placeholders read.
 */
final class Pattern
{
    /**
     * @param  array<string, array{sequence: array<int, string>, usage: array<string, float|int>, fixed: array<string, array<string, mixed>>, used: array<string, array<int, string>>, boilerplate: array<int, string>}>  $blocks
     * @param  array<string, mixed>  $fixed
     * @param  array<int, array<string, mixed>>  $examples
     * @param  array<string, float|int>  $filled
     */
    public function __construct(
        public readonly int $entries = 0,
        public readonly int $words = 0,
        public readonly array $blocks = [],
        public readonly array $fixed = [],
        public readonly array $examples = [],
        public readonly array $filled = [],
        public readonly HouseRules $house = new HouseRules,
    ) {}

    /**
     * @return array{entries: int, words: int, blocks: array<string, array<string, mixed>>, fixed: array<string, mixed>, examples: array<int, array<string, mixed>>, filled: array<string, float|int>, house: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'entries' => $this->entries,
            'words' => $this->words,
            'blocks' => $this->blocks,
            'fixed' => $this->fixed,
            'examples' => $this->examples,
            'filled' => $this->filled,
            'house' => $this->house->toArray(),
        ];
    }

    /**
     * From toArray(), or a pattern an addon stored before core.
     *
     * @param  array<mixed>  $array
     */
    public static function fromArray(array $array): self
    {
        $blocks = [];

        foreach (is_array($array['blocks'] ?? null) ? $array['blocks'] : [] as $handle => $block) {
            if (! is_array($block)) {
                continue;
            }

            $blocks[(string) $handle] = [
                'sequence' => self::strings($block['sequence'] ?? []),
                'usage' => self::numbers($block['usage'] ?? []),
                'fixed' => self::nested($block['fixed'] ?? []),
                'used' => array_map(fn ($used) => self::strings($used), self::keyed($block['used'] ?? [])),
                'boilerplate' => self::strings($block['boilerplate'] ?? []),
            ];
        }

        $examples = array_values(array_filter(is_array($array['examples'] ?? null) ? $array['examples'] : [], 'is_array'));

        return new self(
            is_numeric($array['entries'] ?? null) ? (int) $array['entries'] : 0,
            is_numeric($array['words'] ?? null) ? (int) $array['words'] : 0,
            $blocks,
            self::keyed($array['fixed'] ?? []),
            array_map(fn (array $example) => self::keyed($example), $examples),
            self::numbers($array['filled'] ?? []),
            HouseRules::fromArray(is_array($array['house'] ?? null) ? $array['house'] : []),
        );
    }

    /**
     * @return array<int, string>
     */
    private static function strings(mixed $value): array
    {
        return array_values(array_map('strval', array_filter(is_array($value) ? $value : [], 'is_scalar')));
    }

    /**
     * @return array<string, float|int>
     */
    private static function numbers(mixed $value): array
    {
        $out = [];

        foreach (is_array($value) ? $value : [] as $key => $number) {
            if (is_int($number) || is_float($number)) {
                $out[(string) $key] = $number;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function keyed(mixed $value): array
    {
        $out = [];

        foreach (is_array($value) ? $value : [] as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function nested(mixed $value): array
    {
        return array_map(fn ($item) => self::keyed($item), self::keyed($value));
    }
}
