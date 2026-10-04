<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Arrange;

use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Text\LenientYaml;
use Throwable;

/**
 * Reads the layout planner's `<plans>` block into plans (unvalidated:
 * PlanValidator decides which are used). No model.
 *
 *     - name: Scannable
 *       description: Short hero, the visits as cards, answers below
 *       follows: p-2
 *       page_builder:                       # a page builder
 *         - type: hero
 *           place: { heading: u1, image: u2 }
 *         - type: cards
 *           rows: [{ heading: "u4#2:lead", body: "u4#2:rest" }]
 *         - type: faq
 *           place: { questions: [u6, x2.1] }
 *         - type: section
 *           place: { heading: "u4#1" }
 *           children: [{ type: text, place: { body: u5 } }]
 *       body:                               # rich text
 *         - { type: h2, from: "u3#1" }
 *         - { type: text, from: ["u3#2", "u3#3"], transform: heading-to-lead-in }
 *       excerpt: x3.1                       # a top-level text field
 *
 * `transform` is one transform for the block, or one per ref
 * (`{ "u4#1": lead-in-to-heading }`); `level` goes with heading-level and
 * lead-in-to-heading. `rows` is for the set's rows field (by its handle
 * when it has more than one). Plans are numbered p1, p2… in order.
 */
final class PlanReader
{
    public const NAME_LENGTH = 40;

    public const DESCRIPTION_LENGTH = 120;

    /** The problems that mean the reply couldn't be read at all (rather than read, with no usable plan). */
    public const UNREADABLE = ['there was no <plans> block', 'the YAML did not parse', 'it was not a list of plans', 'there were no plans'];

    /** Why the last read() found nothing, for the log; empty when it found plans. */
    public string $problem = '';

    /**
     * @return list<Plan>
     */
    public function read(?string $block, Schema $schema): array
    {
        $this->problem = '';

        if ($block === null || trim($block) === '') {
            $this->problem = 'there was no <plans> block';

            return [];
        }

        $block = (string) preg_replace('/\A```(?:yaml|yml)?\s*\n(.*?)\n?```\s*\z/su', '$1', trim($block));

        try {
            $data = LenientYaml::parse($block);
        } catch (Throwable) {
            $this->problem = 'the YAML did not parse';

            return [];
        }

        if (! is_array($data) || ! array_is_list($data)) {
            $this->problem = 'it was not a list of plans';

            return [];
        }

        return $this->readList($data, $schema);
    }

    /**
     * Plans already decoded: the YAML's list, or structured output's plans
     * turned back into its shape (PlanSchema::toRaw()).
     *
     * @param  array<mixed>  $data
     * @return list<Plan>
     */
    public function readList(array $data, Schema $schema): array
    {
        $this->problem = '';
        $plans = [];

        foreach ($data as $raw) {
            if (is_array($raw) && ($plan = $this->plan($raw, $schema, 'p'.(count($plans) + 1))) !== null) {
                $plans[] = $plan;
            }
        }

        if ($plans === []) {
            $this->problem = 'no plan arranged any field';
        }

        return $plans;
    }

    /**
     * @param  array<mixed>  $raw
     */
    public function plan(array $raw, Schema $schema, string $id): ?Plan
    {
        $fields = [];

        foreach ($raw as $key => $value) {
            $field = is_string($key) ? $schema->field($key) : null;

            if ($field === null || in_array($key, ['name', 'description', 'follows'], true)) {
                continue;
            }

            $fields[$field->handle] = match (true) {
                $field->isBuilder() => $this->blocks($value, $field),
                Plans::isMarkdown($field) => $this->constructs($value),
                default => self::refs($value) === [] ? [] : [new PlanBlock('value', [new Placement($field->handle, self::refs($value))])],
            };
        }

        if ($fields === []) {
            return null;
        }

        return new Plan(
            $id,
            PlanOrigin::Model,
            self::label($raw['name'] ?? '', self::NAME_LENGTH) ?: 'Layout '.substr($id, 1),
            self::label($raw['description'] ?? '', self::DESCRIPTION_LENGTH),
            $fields,
            is_scalar($raw['follows'] ?? null) && trim((string) $raw['follows']) !== '' ? trim((string) $raw['follows']) : null,
        );
    }

    /**
     * @return list<PlanBlock>
     */
    private function blocks(mixed $value, Field $field): array
    {
        $blocks = [];

        foreach (is_array($value) ? $value : [] as $raw) {
            if (! is_array($raw) || ! is_scalar($raw['type'] ?? null)) {
                continue;
            }

            $type = trim((string) $raw['type']);
            $set = $field->set($type);
            $transforms = self::transforms($raw['transform'] ?? null);
            $options = is_int($raw['level'] ?? null) ? ['level' => $raw['level']] : [];
            $placements = [];

            foreach (is_array($raw['place'] ?? null) ? $raw['place'] : [] as $handle => $refs) {
                $refs = self::refs($refs);

                if (is_string($handle) && $refs !== []) {
                    $placements[] = new Placement($handle, $refs, self::transformFor($refs, $transforms), $options);
                }
            }

            if (is_array($raw['rows'] ?? null)) {
                $rows = $raw['rows'];
                $columns = $set === null ? [] : array_values(array_filter($set->fields, fn (Field $setField) => $setField->kind === Kind::Rows));
                $byField = array_is_list($rows) ? (count($columns) >= 1 ? [$columns[0]->handle => $rows] : []) : $rows;

                foreach ($byField as $handle => $list) {
                    $mapped = array_values(array_filter(array_map(fn ($row) => is_array($row) ? array_filter(array_map(fn ($ref) => is_scalar($ref) ? trim((string) $ref) : '', $row)) : null, is_array($list) ? $list : [])));

                    if (is_string($handle) && $mapped !== []) {
                        $refs = array_merge(...array_map('array_values', $mapped));
                        $placements[] = new Placement($handle, [], self::transformFor($refs, $transforms), ['rows' => $mapped] + $options);
                    }
                }
            }

            $children = [];
            $nested = $set === null ? null : (array_values(array_filter($set->fields, fn (Field $setField) => $setField->engine === Field::CHILDREN))[0] ?? array_values(array_filter($set->fields, fn (Field $setField) => $setField->isBuilder()))[0] ?? null);

            if (is_array($raw['children'] ?? null) && $nested !== null) {
                $children = array_is_list($raw['children']) ? [$nested->handle => $this->blocks($raw['children'], $nested)] : [];

                foreach (array_is_list($raw['children']) ? [] : $raw['children'] as $handle => $list) {
                    $inner = is_string($handle) && $set !== null ? Arranger::target($set->fields, $handle) : null;

                    if ($inner !== null && $inner->isBuilder()) {
                        $children[$inner->handle] = $this->blocks($list, $inner);
                    }
                }
            }

            $blocks[] = new PlanBlock($type, $placements, array_filter($children));
        }

        return $blocks;
    }

    /**
     * @return list<PlanBlock>
     */
    private function constructs(mixed $value): array
    {
        $blocks = [];

        foreach (is_array($value) ? $value : [] as $raw) {
            if (! is_array($raw) || ! is_scalar($raw['type'] ?? null)) {
                continue;
            }

            $refs = self::refs($raw['from'] ?? ($raw['place'] ?? null));
            $options = is_int($raw['level'] ?? null) ? ['level' => $raw['level']] : [];

            if ($refs !== []) {
                $blocks[] = new PlanBlock(trim((string) $raw['type']), [new Placement(Placement::BODY, $refs, self::transformFor($refs, self::transforms($raw['transform'] ?? null)), $options)]);
            }
        }

        return $blocks;
    }

    /**
     * @return list<string>
     */
    private static function refs(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];

        return array_values(array_filter(array_map(fn ($ref) => is_scalar($ref) ? trim((string) $ref) : '', $values), fn (string $ref) => $ref !== ''));
    }

    /**
     * @return array<string, Transform>|Transform
     */
    private static function transforms(mixed $value): array|Transform
    {
        if (is_string($value)) {
            return Transform::tryFrom(trim($value)) ?? Transform::AsIs;
        }

        $out = [];

        foreach (is_array($value) ? $value : [] as $ref => $name) {
            $transform = is_string($name) ? Transform::tryFrom(trim($name)) : null;

            if ($transform !== null) {
                $out[(string) $ref] = $transform;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $refs
     * @param  array<string, Transform>|Transform  $transforms
     */
    private static function transformFor(array $refs, array|Transform $transforms): Transform
    {
        if ($transforms instanceof Transform) {
            return $transforms;
        }

        foreach ($refs as $ref) {
            if (isset($transforms[$ref])) {
                return $transforms[$ref];
            }
        }

        return Transform::AsIs;
    }

    private static function label(mixed $value, int $length): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', is_scalar($value) ? (string) $value : ''));

        return mb_strlen($text) > $length ? rtrim(mb_substr($text, 0, $length - 1)).'…' : $text;
    }
}
