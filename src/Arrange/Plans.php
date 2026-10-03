<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Arrange;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\BlockRef;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use Traversable;

/**
 * A session's layouts (Session::$plans), in display order: the writer's
 * plan "w" first, then the planner's.
 *
 * @implements IteratorAggregate<int, Plan>
 */
final class Plans implements Countable, IteratorAggregate
{
    /**
     * @param  list<Plan>  $plans
     */
    public function __construct(private readonly array $plans = []) {}

    /**
     * The writer's own layout, read from its draft with no model: each
     * page-builder block (nested ones as children) with its units placed
     * as they are, and each rich-text field as one `text` construct per
     * unit. Every block keeps its position in the draft (`origin`), so the
     * arranger brings its other values along, and arranging this plan
     * gives the draft back exactly.
     *
     * @param  Draft|array<string, mixed>  $draft
     */
    public static function fromDraft(Draft|array $draft, Units $units, Schema $schema): Plan
    {
        $data = $draft instanceof Draft ? $draft->data : $draft;
        $fields = [];

        foreach ($schema->fields as $field) {
            if (! array_key_exists($field->handle, $data)) {
                continue;
            }

            if ($field->isBuilder()) {
                $fields[$field->handle] = self::blocks($data[$field->handle], $field, FieldPath::of($field->handle), $units);
            } elseif (self::isMarkdown($field)) {
                $at = $units->at(FieldPath::of($field->handle));

                if ($at !== []) {
                    $fields[$field->handle] = array_map(fn (Unit $unit) => new PlanBlock('text', [new Placement(Placement::BODY, [$unit->id])]), $at);
                }
            }
        }

        return new Plan(Plan::WRITER, PlanOrigin::Writer, 'As written', 'The writer’s own layout', $fields);
    }

    /**
     * The blocks of one page builder, as the writer laid them out.
     *
     * @return list<PlanBlock>
     */
    public static function blocks(mixed $value, Field $field, FieldPath $path, Units $units): array
    {
        $blocks = [];
        $position = 0;

        foreach (is_array($value) ? $value : [] as $block) {
            if (! is_array($block)) {
                continue;
            }

            $i = $position++;
            $type = is_scalar($block['type'] ?? null) ? (string) $block['type'] : '';
            $set = $field->set($type);
            $at = $path->with(new BlockRef(null, $i, $type));
            $placements = [];
            $children = [];

            if ($set !== null) {
                $byField = [];

                foreach ($units->inBlock($at) as $unit) {
                    $relative = self::relative($unit->path, $at);

                    if ($relative === null || self::nestedBuilder($set->fields, $relative)) {
                        continue;
                    }

                    $byField[$relative][] = $unit->id;
                }

                foreach ($byField as $handle => $ids) {
                    $placements[] = new Placement((string) $handle, $ids);
                }

                foreach ($set->fields as $setField) {
                    if ($setField->isBuilder() && is_array($block[$setField->handle] ?? null)) {
                        $children[$setField->handle] = self::blocks($block[$setField->handle], $setField, $at->with($setField->handle), $units);
                    }
                }
            }

            $blocks[] = new PlanBlock($type, $placements, $children, [], $i);
        }

        return $blocks;
    }

    public function get(string $id): ?Plan
    {
        foreach ($this->plans as $plan) {
            if ($plan->id === $id) {
                return $plan;
            }
        }

        return null;
    }

    public function writer(): ?Plan
    {
        return $this->get(Plan::WRITER);
    }

    /**
     * @return list<Plan>
     */
    public function all(): array
    {
        return $this->plans;
    }

    /** The plan marked suggested, if any. */
    public function suggested(): ?Plan
    {
        foreach ($this->plans as $plan) {
            if ($plan->suggested) {
                return $plan;
            }
        }

        return null;
    }

    /** These plans with one put in place of the plan with its id, or added at the end. */
    public function with(Plan $plan): self
    {
        $plans = $this->plans;

        foreach ($plans as $i => $existing) {
            if ($existing->id === $plan->id) {
                $plans[$i] = $plan;

                return new self($plans);
            }
        }

        $plans[] = $plan;

        return new self($plans);
    }

    /**
     * The writer's plan followed by these alternatives, renumbered p1, p2…
     *
     * @param  list<Plan>  $alternatives
     */
    public static function of(Plan $writer, array $alternatives = []): self
    {
        $plans = [$writer];

        foreach (array_values($alternatives) as $i => $plan) {
            $plans[] = $plan->with(id: 'p'.($i + 1));
        }

        return new self($plans);
    }

    public function count(): int
    {
        return count($this->plans);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->plans);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(fn (Plan $plan) => $plan->toArray(), $this->plans);
    }

    /**
     * @param  array<mixed>  $array  From toArray().
     */
    public static function fromArray(array $array): self
    {
        return new self(array_values(array_map(fn (array $plan) => Plan::fromArray($plan), array_filter($array, 'is_array'))));
    }

    /** Rich text, or a long text field that holds markdown. */
    public static function isMarkdown(Field $field): bool
    {
        return $field->kind === Kind::RichText
            || ($field->kind === Kind::LongText && ($field->type === 'markdown' || ($field->meta['format'] ?? null) === 'markdown'));
    }

    /**
     * Where a unit is inside a block, as a placement's field: "heading",
     * "seo/description" through a group, "questions" for a row. Null for a
     * unit that isn't inside the block.
     */
    public static function relative(FieldPath $path, FieldPath $block): ?string
    {
        $prefix = count($block->segments);

        if (count($path->segments) <= $prefix || ! str_starts_with($path->dotted().'.', $block->dotted().'.')) {
            return null;
        }

        $handles = [];

        foreach (array_slice($path->segments, $prefix) as $segment) {
            if ($segment instanceof BlockRef) {
                break;
            }

            $handles[] = $segment;
        }

        return $handles === [] ? null : implode('/', $handles);
    }

    /**
     * Whether a relative path starts in a builder nested in the set: those
     * units belong to its child blocks.
     *
     * @param  array<int, Field>  $fields
     */
    private static function nestedBuilder(array $fields, string $relative): bool
    {
        $first = explode('/', $relative)[0];

        foreach ($fields as $field) {
            if ($field->handle === $first) {
                return $field->isBuilder();
            }
        }

        return false;
    }
}
