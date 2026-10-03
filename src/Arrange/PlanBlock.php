<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Arrange;

/**
 * One block of a plan: a set of a page builder, with what goes in each of
 * its fields; or, in a rich-text field, one construct: `text` (as
 * written), `p`, `h2`–`h6`, `list`, `quote` or `set:<handle>` (a quote
 * set, stored by the dialect from a block quote).
 *
 * - `children`: blocks nested in it, by the nested builder's handle (Neo
 *   children, or a builder inside a set).
 * - `settings`: values that aren't writing (a background, a layout
 *   choice), such as a pattern's house defaults.
 * - `origin`: in the writer's plan, the block's position in the draft, so
 *   its other values (images, settings) come with it.
 */
final class PlanBlock
{
    /**
     * @param  list<Placement>  $placements
     * @param  array<string, list<PlanBlock>>  $children
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public readonly string $type,
        public readonly array $placements = [],
        public readonly array $children = [],
        public readonly array $settings = [],
        public readonly ?int $origin = null,
    ) {}

    /**
     * Every ref placed in it and its children, in order.
     *
     * @return list<string>
     */
    public function refs(): array
    {
        $refs = [];

        foreach ($this->placements as $placement) {
            array_push($refs, ...$placement->refs());
        }

        foreach ($this->children as $blocks) {
            foreach ($blocks as $child) {
                array_push($refs, ...$child->refs());
            }
        }

        return $refs;
    }

    public function placement(string $field): ?Placement
    {
        foreach ($this->placements as $placement) {
            if ($placement->field === $field) {
                return $placement;
            }
        }

        return null;
    }

    /**
     * @param  list<Placement>|null  $placements
     * @param  array<string, list<PlanBlock>>|null  $children
     */
    public function with(?array $placements = null, ?array $children = null): self
    {
        return new self($this->type, $placements ?? $this->placements, $children ?? $this->children, $this->settings, $this->origin);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['type' => $this->type, 'placements' => array_map(fn (Placement $placement) => $placement->toArray(), $this->placements)]
            + ($this->children !== [] ? ['children' => array_map(fn (array $blocks) => array_map(fn (PlanBlock $block) => $block->toArray(), $blocks), $this->children)] : [])
            + ($this->settings !== [] ? ['settings' => $this->settings] : [])
            + ($this->origin !== null ? ['origin' => $this->origin] : []);
    }

    /**
     * @param  array<mixed>  $array
     */
    public static function fromArray(array $array): self
    {
        $children = [];

        foreach (is_array($array['children'] ?? null) ? $array['children'] : [] as $field => $blocks) {
            if (is_string($field) && is_array($blocks)) {
                $children[$field] = array_values(array_map(fn (array $block) => self::fromArray($block), array_filter($blocks, 'is_array')));
            }
        }

        $settings = [];

        foreach (is_array($array['settings'] ?? null) ? $array['settings'] : [] as $key => $value) {
            $settings[(string) $key] = $value;
        }

        return new self(
            is_scalar($array['type'] ?? null) ? (string) $array['type'] : '',
            array_values(array_map(fn (array $placement) => Placement::fromArray($placement), array_filter(is_array($array['placements'] ?? null) ? $array['placements'] : [], 'is_array'))),
            $children,
            $settings,
            is_int($array['origin'] ?? null) ? $array['origin'] : null,
        );
    }
}
