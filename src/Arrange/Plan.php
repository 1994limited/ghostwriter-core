<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Arrange;

/**
 * A layout: where the draft's units and extras go, field by field. It never
 * holds words of its own; its name and description are labels for the
 * panel and never reach the entry.
 *
 * `fields` maps each field it arranges to its blocks: a page builder's
 * sets, a rich-text field's constructs, or (for a top-level text field)
 * one block of type `value`. Fields it doesn't arrange (the title, SEO
 * fields) are copied from the draft as they are.
 *
 * The writer's draft is plan "w" (Plans::fromDraft()); the planner's are
 * "p1", "p2". `follows` names the site pattern it follows (SitePatterns),
 * `suggested` marks the one most like the site's own pages
 * (Candidates::rank()), and `stale` one that no longer fits the text after
 * an edit (Plans::repair()) until layouts are refreshed.
 */
final class Plan
{
    public const WRITER = 'w';

    /**
     * @param  array<string, list<PlanBlock>>  $fields
     */
    public function __construct(
        public readonly string $id,
        public readonly PlanOrigin $origin,
        public readonly string $name,
        public readonly string $description,
        public readonly array $fields,
        public readonly ?string $follows = null,
        public readonly bool $suggested = false,
        public readonly bool $stale = false,
    ) {}

    /**
     * Every ref the plan places, in reading order.
     *
     * @return list<string>
     */
    public function refs(): array
    {
        $refs = [];

        foreach ($this->fields as $blocks) {
            foreach ($blocks as $block) {
                array_push($refs, ...$block->refs());
            }
        }

        return $refs;
    }

    /**
     * The extra items it places.
     *
     * @return list<string>
     */
    public function extrasUsed(): array
    {
        $used = [];

        foreach ($this->refs() as $ref) {
            if (str_starts_with($ref, 'x')) {
                $used[] = Extras\Extras::itemId($ref);
            }
        }

        return array_values(array_unique($used));
    }

    /**
     * Its block types, field by field: what "Suggested" compares.
     *
     * @return array<string, list<string>>
     */
    public function sequences(): array
    {
        return array_map(fn (array $blocks) => array_map(fn (PlanBlock $block) => $block->type, $blocks), $this->fields);
    }

    /** How many blocks it has at the top level, for the card. */
    public function blockCount(): int
    {
        return array_sum(array_map('count', $this->fields));
    }

    /**
     * @param  array<string, list<PlanBlock>>|null  $fields
     */
    public function with(?array $fields = null, ?bool $suggested = null, ?bool $stale = null, ?string $id = null): self
    {
        return new self($id ?? $this->id, $this->origin, $this->name, $this->description, $fields ?? $this->fields, $this->follows, $suggested ?? $this->suggested, $stale ?? $this->stale);
    }

    /**
     * What Session::$plans stores, one per plan.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'origin' => $this->origin->value,
            'name' => $this->name,
            'description' => $this->description,
            'fields' => array_map(fn (array $blocks) => array_map(fn (PlanBlock $block) => $block->toArray(), $blocks), $this->fields),
        ]
            + ($this->follows !== null ? ['follows' => $this->follows] : [])
            + ($this->extrasUsed() !== [] ? ['extrasUsed' => $this->extrasUsed()] : [])
            + ($this->suggested ? ['suggested' => true] : [])
            + ($this->stale ? ['stale' => true] : []);
    }

    /**
     * @param  array<mixed>  $array
     */
    public static function fromArray(array $array): self
    {
        $fields = [];

        foreach (is_array($array['fields'] ?? null) ? $array['fields'] : [] as $handle => $blocks) {
            if (is_string($handle) && is_array($blocks)) {
                $fields[$handle] = array_values(array_map(fn (array $block) => PlanBlock::fromArray($block), array_filter($blocks, 'is_array')));
            }
        }

        return new self(
            is_scalar($array['id'] ?? null) ? (string) $array['id'] : '',
            PlanOrigin::tryFrom(is_string($array['origin'] ?? null) ? $array['origin'] : '') ?? PlanOrigin::Model,
            is_scalar($array['name'] ?? null) ? (string) $array['name'] : '',
            is_scalar($array['description'] ?? null) ? (string) $array['description'] : '',
            $fields,
            is_string($array['follows'] ?? null) ? $array['follows'] : null,
            ($array['suggested'] ?? false) === true,
            ($array['stale'] ?? false) === true,
        );
    }
}
