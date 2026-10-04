<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Arrange;

use NineteenNinetyFour\Ghostwriter\Core\Ai\OutputSchema;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;

/**
 * The layout planner's reply as structured output: the shape of the plans
 * for one site's fields, and the way back from it to the mapping
 * PlanReader reads. No model.
 *
 * The YAML the tagged prompt asks for keys each plan by field handle and
 * each block's places by the set's field handles. A schema with a
 * property per handle would grow with the site (Claude allows 24 optional
 * properties and 16 unions in a request, and no recursion), so the shape
 * is generic and every property is required, with "" and [] for nothing:
 *
 *     {"plans": [{"notes", "name", "description", "follows",
 *       "fields": [{"field": "page_builder",
 *         "blocks": [{"type": "hero", "place": [{"field": "heading", "refs": ["u1"]}],
 *                     "rows": [{"field": "", "cells": [{"column": "question", "ref": "x2.1.question"}]}],
 *                     "transform": "", "level": 0, "children": […]}],
 *         "constructs": [], "refs": []}]}]}
 *
 * Only what the site's fields need is in it: `rows` where a block has a
 * rows field, `constructs` where there is rich text, `refs` where there is
 * a plain field, and blocks nested only as deep as the site's builders go,
 * at most three (deeper would need recursion). Claude refuses a grammar
 * that compiles too large, and every level of nesting multiplies it. A
 * transform is for a whole block; the YAML's per-ref transforms aren't
 * offered (`transforms`, if a model sends them, is still read). Block types and field handles are enums of the site's own,
 * so a reply can't name a set that doesn't exist; refs stay strings, and
 * PlanValidator checks every one as before.
 */
final class PlanSchema
{
    /** Levels of blocks: a block, its children, and theirs. */
    public const DEPTH = 3;

    public static function for(Schema $schema, int $count): OutputSchema
    {
        $handles = [];
        $types = [];
        $constructs = ['text', 'p', 'h2', 'h3', 'h4', 'h5', 'h6', 'list', 'quote'];
        $depth = 0;
        $rich = false;
        $plain = false;

        foreach ($schema->fields as $field) {
            if (! $field->isWritable()) {
                continue;
            }

            if ($field->isBuilder()) {
                $handles[] = $field->handle;
                array_push($types, ...self::types($field, 1));
                $depth = max($depth, self::depth($field, 1));
            } elseif (Plans::isMarkdown($field)) {
                $handles[] = $field->handle;
                $rich = true;
                array_push($constructs, ...array_map(fn ($set) => 'set:'.$set, array_map('strval', array_keys($field->sets))));
            } elseif (in_array($field->kind, [Kind::Text, Kind::LongText, Kind::List], true)) {
                $handles[] = $field->handle;
                $plain = true;
            }
        }

        $types = array_values(array_unique($types));
        $transforms = ['', ...array_values(array_filter(array_map(fn (Transform $t) => $t->value, Transform::cases()), fn (string $t) => ! in_array($t, [Transform::AsIs->value, Transform::Split->value, Transform::Join->value], true)))];
        $refs = ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Refs: u4, "u4#2", "u4#2:lead", x2.1, x2.1.question.'];
        $transform = ['type' => 'string', 'enum' => $transforms, 'description' => 'For the whole block; "" for none.'];
        $block = null;

        $rows = self::hasRows($schema);

        for ($level = min($depth, self::DEPTH); $level >= 1; $level--) {
            $properties = [
                'type' => $types !== [] ? ['type' => 'string', 'enum' => $types] : ['type' => 'string'],
                'place' => ['type' => 'array', 'description' => 'The set\'s fields and what goes in each.', 'items' => [
                    'type' => 'object', 'required' => ['field', 'refs'], 'properties' => ['field' => ['type' => 'string'], 'refs' => $refs],
                ]],
                'rows' => ! $rows ? null : ['type' => 'array', 'description' => 'For a rows field, one item per row; `field` is the rows field, "" when the set has one.', 'items' => [
                    'type' => 'object', 'required' => ['field', 'cells'], 'properties' => [
                        'field' => ['type' => 'string'],
                        'cells' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['column', 'ref'], 'properties' => ['column' => ['type' => 'string'], 'ref' => ['type' => 'string']]]],
                    ],
                ]],
                'transform' => $transform,
                'level' => ['type' => 'integer', 'description' => 'With heading-level or lead-in-to-heading: the level; 0 otherwise.'],
            ];
            $properties = array_filter($properties, fn ($property) => $property !== null);

            if ($block !== null) {
                $properties['children'] = ['type' => 'array', 'description' => 'Blocks inside this one.', 'items' => $block];
            }

            $block = ['type' => 'object', 'required' => array_keys($properties), 'properties' => $properties];
        }

        return new OutputSchema('plans', [
            'type' => 'object',
            'required' => ['plans'],
            'properties' => ['plans' => [
                'type' => 'array',
                'description' => "{$count} arrangements, each clearly different.",
                'items' => [
                    'type' => 'object',
                    'required' => ['notes', 'name', 'description', 'follows', 'fields'],
                    'properties' => [
                        'notes' => ['type' => 'string', 'description' => 'First, a few words for yourself: the shape you will follow and where each unit goes. Not shown.'],
                        'name' => ['type' => 'string', 'description' => 'Two or three words.'],
                        'description' => ['type' => 'string', 'description' => 'One line about the shape, not the words.'],
                        'follows' => ['type' => 'string', 'description' => 'The site pattern followed (p-2), or "".'],
                        'fields' => ['type' => 'array', 'items' => self::entry(array_filter([
                            'field' => $handles !== [] ? ['type' => 'string', 'enum' => $handles] : ['type' => 'string'],
                            'blocks' => $block === null ? null : ['type' => 'array', 'description' => 'For a page builder; [] otherwise.', 'items' => $block],
                            'constructs' => ! $rich ? null : ['type' => 'array', 'description' => 'For rich text; [] otherwise.', 'items' => [
                                'type' => 'object',
                                'required' => ['type', 'from', 'transform', 'level'],
                                'properties' => [
                                    'type' => ['type' => 'string', 'enum' => array_values(array_unique($constructs))],
                                    'from' => $refs,
                                    'transform' => $transform,
                                    'level' => ['type' => 'integer'],
                                ],
                            ]],
                            'refs' => ! $plain ? null : $refs + ['description' => 'For a plain field; [] otherwise.'],
                        ], fn ($property) => $property !== null))],
                    ],
                ],
            ]],
        ]);
    }

    /**
     * One structured plan as the mapping PlanReader::plan() reads: keyed by
     * field handle, blocks with `place`, `rows`, `transform`, `children`.
     *
     * @param  array<mixed>  $plan
     * @return array<string, mixed>
     */
    public static function toRaw(array $plan, Schema $schema): array
    {
        $raw = [
            'name' => $plan['name'] ?? '',
            'description' => $plan['description'] ?? '',
            'follows' => $plan['follows'] ?? '',
        ];

        foreach (is_array($plan['fields'] ?? null) ? $plan['fields'] : [] as $entry) {
            $handle = is_array($entry) && is_string($entry['field'] ?? null) ? $entry['field'] : '';
            $field = $handle !== '' ? $schema->field($handle) : null;

            if ($field === null || in_array($handle, ['name', 'description', 'follows'], true)) {
                continue;
            }

            $raw[$handle] = match (true) {
                $field->isBuilder() => array_map(self::block(...), self::list($entry['blocks'] ?? null)),
                Plans::isMarkdown($field) => array_map(fn (array $construct) => array_filter([
                    'type' => $construct['type'] ?? '',
                    'from' => $construct['from'] ?? [],
                    'transform' => ($construct['transform'] ?? '') !== '' ? $construct['transform'] : null,
                    'level' => is_int($construct['level'] ?? null) && $construct['level'] > 0 ? $construct['level'] : null,
                ], fn ($value) => $value !== null), self::list($entry['constructs'] ?? null)),
                default => is_array($entry['refs'] ?? null) ? $entry['refs'] : [],
            };
        }

        return $raw;
    }

    /**
     * @param  array<mixed>  $block
     * @return array<string, mixed>
     */
    private static function block(array $block): array
    {
        $place = [];

        foreach (self::list($block['place'] ?? null) as $placement) {
            if (is_string($placement['field'] ?? null) && $placement['field'] !== '') {
                $place[$placement['field']] = $placement['refs'] ?? [];
            }
        }

        $rows = [];

        foreach (self::list($block['rows'] ?? null) as $row) {
            $cells = [];

            foreach (self::list($row['cells'] ?? null) as $cell) {
                if (is_string($cell['column'] ?? null) && is_scalar($cell['ref'] ?? null)) {
                    $cells[$cell['column']] = (string) $cell['ref'];
                }
            }

            if ($cells !== []) {
                $rows[is_string($row['field'] ?? null) ? $row['field'] : ''][] = $cells;
            }
        }

        $transforms = [];

        foreach (self::list($block['transforms'] ?? null) as $one) {
            if (is_scalar($one['ref'] ?? null) && is_string($one['transform'] ?? null)) {
                $transforms[(string) $one['ref']] = $one['transform'];
            }
        }

        $whole = is_string($block['transform'] ?? null) && $block['transform'] !== '' ? $block['transform'] : null;

        return array_filter([
            'type' => $block['type'] ?? '',
            'place' => $place,
            // One rows field unnamed is the list PlanReader takes for the set's only one.
            'rows' => $rows === [] ? null : (array_keys($rows) === [''] ? $rows[''] : array_diff_key($rows, ['' => true])),
            'transform' => $whole ?? ($transforms !== [] ? $transforms : null),
            'level' => is_int($block['level'] ?? null) && $block['level'] > 0 ? $block['level'] : null,
            'children' => self::list($block['children'] ?? null) !== [] ? array_map(self::block(...), self::list($block['children'])) : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * @return list<array<mixed>>
     */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private static function entry(array $properties): array
    {
        return ['type' => 'object', 'required' => array_keys($properties), 'properties' => $properties];
    }

    /** How deep the field's blocks nest: 1 for blocks with no blocks inside. */
    private static function depth(Field $field, int $depth): int
    {
        $deepest = $depth;

        foreach ($field->sets as $set) {
            foreach ($set->fields as $setField) {
                if ($setField->isBuilder() && $depth < self::DEPTH) {
                    $deepest = max($deepest, self::depth($setField, $depth + 1));
                }
            }
        }

        return $deepest;
    }

    /** Whether any block the planner may use has a rows field. */
    private static function hasRows(Schema $schema): bool
    {
        $walk = function (array $fields) use (&$walk): bool {
            foreach ($fields as $field) {
                if ($field->kind === Kind::Rows || ($field->isBuilder() && array_filter($field->sets, fn ($set) => $walk($set->fields)) !== [])) {
                    return true;
                }
            }

            return false;
        };

        return $walk($schema->fields);
    }

    /**
     * @return list<string>
     */
    private static function types(Field $field, int $depth): array
    {
        $types = [];

        foreach ($field->sets as $handle => $set) {
            $types[] = (string) $handle;

            foreach ($set->fields as $setField) {
                if ($setField->isBuilder() && $depth < self::DEPTH) {
                    array_push($types, ...self::types($setField, $depth + 1));
                }
            }
        }

        return $types;
    }
}
