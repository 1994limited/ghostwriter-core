<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\HeadingLevels;

/**
 * The headings one rich-text value may have: where they start (`top`,
 * one below what the template prints above it) and the levels its editor
 * can show (`allowed`). The levels it uses are the allowed ones from
 * `top` down (levels()).
 */
final class HeadingPolicy
{
    /**
     * @param  int  $top  1–6: the first level a heading of this value may take.
     * @param  list<int>  $allowed  The levels the field's editor can show (HeadingLevels::allowed()).
     */
    public function __construct(
        public readonly int $top = 2,
        public readonly array $allowed = HeadingLevels::ALL,
    ) {}

    /** For a field, where its template prints it: inside a block of $blockType, or at the top level. */
    public static function for(Field $field, ?RenderProfile $profile = null, ?string $blockType = null): self
    {
        return new self(($profile ?? RenderProfile::default())->top($field, $blockType), HeadingLevels::allowed($field));
    }

    /**
     * The levels a heading may take, shallowest first: the allowed ones
     * from `top` down. Empty: no headings at all.
     *
     * @return list<int>
     */
    public function levels(): array
    {
        return array_values(array_filter($this->allowed, fn (int $level) => $level >= $this->top));
    }

    public function allowsHeadings(): bool
    {
        return $this->levels() !== [];
    }

    /**
     * The levels as the writer is told them, in markdown: "`##` and
     * `###`", "`##` to `######`", "`##` only"; '' for none.
     */
    public function markdown(): string
    {
        $levels = $this->levels();
        $names = array_map(fn (int $level) => '`'.str_repeat('#', $level).'`', $levels);

        return match (true) {
            $names === [] => '',
            count($names) === 1 => $names[0].' only',
            count($names) > 2 && $levels === range($levels[0], $levels[count($levels) - 1]) => $names[0].' to '.$names[count($names) - 1],
            default => implode(', ', array_slice($names, 0, -1)).' and '.$names[count($names) - 1],
        };
    }

    /** The levels as the layout planner's constructs: "h2, h3", "h2–h6"; '' for none. */
    public function constructs(): string
    {
        $levels = $this->levels();

        if (count($levels) > 2 && $levels === range($levels[0], $levels[count($levels) - 1])) {
            return 'h'.$levels[0].'–h'.$levels[count($levels) - 1];
        }

        return implode(', ', array_map(fn (int $level) => 'h'.$level, $levels));
    }
}
