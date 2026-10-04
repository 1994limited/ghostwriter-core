<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Arrange;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\Extras;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Walk;
use NineteenNinetyFour\Ghostwriter\Core\Layout\EntryBuilder;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Pattern;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\HeadingLevels;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Whether a plan can be used, with no model. A plan that fails any rule is
 * dropped (or, after an edit, marked stale), and each Violation is logged
 * at debug level.
 *
 * 1. **Schema.** Every block type is a set of its field (children of the
 *    nested builder's); every placement targets a field of its set; what
 *    is placed suits the field (one heading or paragraph in a text field,
 *    no headings in plain long text or a list, media only in an image
 *    field, rows from row units or extra items); the field's `min` and
 *    `max` (Field::$meta) hold; required fields are filled, by a placement,
 *    a setting or the pattern's house default; no block meant for words is
 *    left with none.
 * 2. **Conservation.** Every unit of the fields it arranges, and every
 *    piece of one placed by piece, is placed exactly once (media units may
 *    be left out); no unit of a field it doesn't arrange is placed; the
 *    arranged words are the same multiset as the units' and the placed
 *    extras', punctuation aside.
 * 3. **Markers.** Every `[[ask: …]]` and `#gw-link:` link appears as often
 *    as in what was placed.
 * 4. **Extras.** Each placed item exists, is placed once, and is sourced
 *    or waiting on the editor's answer.
 * 5. **Boilerplate.** No words go into a set the site copies whole.
 * 6. **Distinctness.** It isn't the same layout as an earlier plan.
 * 7. **Round trip.** EntryBuilder, given the arranged draft, notes nothing
 *    new that is "not a field here", "not an option" or an unknown block.
 * 8. **No headings here.** No heading is made (lead-in-to-heading,
 *    heading-level, an `h1`–`h6` construct) in a field whose editor shows
 *    none (Schema\HeadingLevels::allowed() empty). A level the field
 *    can't show isn't a violation: the SEO pass maps it.
 *
 * validate() says which plans were dropped and by which rules (Validated);
 * valid() gives only the plans kept.
 */
final class PlanValidator
{
    private readonly LoggerInterface $logger;

    private readonly Arranger $arranger;

    public function __construct(
        private readonly EntryBuilder $builder = new EntryBuilder,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger;
        $this->arranger = new Arranger;
    }

    /**
     * The plans that pass, in order, each checked against those before it
     * (and the writer's), with the writer's plan kept whatever it is: it
     * is the draft.
     *
     * @param  list<Plan>  $plans
     * @param  Extras|array<int, mixed>  $extras
     * @param  Draft|array<string, mixed>  $draft
     * @return list<Plan>
     */
    public function valid(array $plans, Units $units, Extras|array $extras, Draft|array $draft, Schema $schema, ?Pattern $pattern = null): array
    {
        return $this->validate($plans, $units, $extras, $draft, $schema, $pattern)->kept;
    }

    /**
     * As valid(), with what was dropped and why: each dropped plan's
     * violations, by its id, also logged at debug level with their rules.
     *
     * @param  list<Plan>  $plans
     * @param  Extras|array<int, mixed>  $extras
     * @param  Draft|array<string, mixed>  $draft
     */
    public function validate(array $plans, Units $units, Extras|array $extras, Draft|array $draft, Schema $schema, ?Pattern $pattern = null): Validated
    {
        $kept = [];
        $dropped = [];

        foreach ($plans as $plan) {
            if ($plan->origin === PlanOrigin::Writer) {
                $kept[] = $plan;

                continue;
            }

            $violations = $this->check($plan, $units, $extras, $draft, $schema, $pattern, $kept);

            if ($violations === []) {
                $kept[] = $plan;
            } else {
                $dropped[$plan->id] = $violations;
                $rules = Validated::rulesOf($violations);
                $this->logger->debug("Ghostwriter: layout \"{$plan->name}\" was dropped (".implode(', ', $rules).').', ['plan' => $plan->id, 'rules' => $rules, 'violations' => array_map('strval', $violations)]);
            }
        }

        return new Validated($kept, $dropped);
    }

    /**
     * Whether a required field must be filled by the plan for it to be
     * used: only a field the writer writes words in. Images and other
     * files, links, entries (reference), settings (choice, choices,
     * toggle, number), groups and nested builders are never in a plan;
     * left empty, they get what the writer's draft gets on the build path
     * ("Use this draft"): the striped placeholder (Images\Placeholders), a
     * `#gw-link:` sentinel (HouseStyle), the house or the CMS's default,
     * or a gap in "Finish this page".
     */
    public static function requiredWords(Field $field): bool
    {
        return $field->required && ! $field->files && in_array($field->kind, [Kind::Text, Kind::LongText, Kind::RichText, Kind::List, Kind::Rows], true);
    }

    /**
     * Everything wrong with one plan; empty when it can be used.
     *
     * @param  Extras|array<int, mixed>  $extras
     * @param  Draft|array<string, mixed>  $draft
     * @param  list<Plan>  $earlier  Plans it must differ from.
     * @return list<Violation>
     */
    public function check(Plan $plan, Units $units, Extras|array $extras, Draft|array $draft, Schema $schema, ?Pattern $pattern = null, array $earlier = []): array
    {
        $data = $draft instanceof Draft ? $draft->data : $draft;
        $extras = $extras instanceof Extras ? $extras : Extras::fromArray($extras);
        $content = new Content($units, $extras);
        $violations = [];

        // 1. Schema.
        foreach ($plan->fields as $handle => $blocks) {
            $field = $schema->field($handle);

            if ($field === null || ! $field->isWritable()) {
                $violations[] = new Violation(Violation::UNKNOWN_FIELD, "\"{$handle}\" is not a field this plan can arrange.");

                continue;
            }

            if ($field->isBuilder()) {
                array_push($violations, ...$this->blocks($blocks, $field, $content, $units, $pattern, (array) ($pattern->blocks[$handle] ?? [])));
            } elseif (Plans::isMarkdown($field)) {
                foreach ($blocks as $block) {
                    if (! self::isConstruct($block->type, $field)) {
                        $violations[] = new Violation(Violation::UNKNOWN_BLOCK, "\"{$block->type}\" is not something {$handle} can hold.");
                    }

                    if (HeadingLevels::allowed($field) === [] && self::makesHeadings($block->type, $block->placements)) {
                        $violations[] = new Violation(Violation::NO_HEADINGS, "{$handle} shows no headings, so none can be made in it.");
                    }
                }
            } else {
                $placement = $blocks[0]->placements[0] ?? null;

                if (count($blocks) !== 1 || count($blocks[0]->placements) !== 1) {
                    $violations[] = new Violation(Violation::KIND, "{$handle} takes one value.");
                } elseif ($placement !== null) {
                    array_push($violations, ...$this->fits($placement, $field, $content, $units, $handle));
                }

                if (self::requiredWords($field) && $placement === null) {
                    $violations[] = new Violation(Violation::REQUIRED, "{$handle} is required.");
                }
            }
        }

        // 2. Conservation, and 4. extras.
        array_push($violations, ...$this->conservation($plan, $units, $extras, $content));

        // 2 and 3. The words and markers of what it arranges.
        $arranged = $this->arranger->arrange($plan, $units, $extras, $data, $schema);
        $expected = [];

        foreach ($units->all() as $unit) {
            if (isset($plan->fields[$unit->path->handle()]) && $unit->kind !== UnitKind::Media) {
                $expected[] = $unit->markdown;
            }
        }

        foreach ($plan->refs() as $ref) {
            if (str_starts_with($ref, 'x')) {
                $expected[] = Content::joinMarkdown($content->pieces($ref) ?? []);
            }
        }

        $actual = self::texts($arranged, $schema, array_keys($plan->fields));
        $expectedText = implode("\n\n", $expected);
        $actualText = implode("\n\n", $actual);

        if (self::bag($expectedText) !== self::bag($actualText)) {
            $violations[] = new Violation(Violation::WORDS, 'the arranged words are not the draft\'s.');
        }

        if (self::markers($expectedText) !== self::markers($actualText)) {
            $violations[] = new Violation(Violation::MARKERS, 'an [[ask: …]], a [[check: …]] or a #gw-link: link was lost or doubled.');
        }

        // 6. Distinctness.
        foreach ($earlier as $other) {
            if (self::signature($other) === self::signature($plan)) {
                $violations[] = new Violation(Violation::SAME, "it is the same layout as \"{$other->name}\".");
            }
        }

        // 7. Round trip.
        $before = $this->problems($this->builder->build($data, $schema, $pattern)->notes);

        foreach (array_diff($this->problems($this->builder->build($arranged, $schema, $pattern)->notes), $before) as $note) {
            $violations[] = new Violation(Violation::ROUND_TRIP, $note);
        }

        return $violations;
    }

    /**
     * @param  list<PlanBlock>  $blocks
     * @param  array<string, mixed>  $blockPattern  Pattern::$blocks for this builder.
     * @return list<Violation>
     */
    private function blocks(array $blocks, Field $field, Content $content, Units $units, ?Pattern $pattern, array $blockPattern): array
    {
        $violations = [];
        $min = $field->meta['min'] ?? null;
        $max = $field->meta['max'] ?? null;

        if ((is_int($min) && count($blocks) < $min) || (is_int($max) && $max > 0 && count($blocks) > $max)) {
            $violations[] = new Violation(Violation::LIMITS, "{$field->handle} takes ".(is_int($min) ? "at least {$min} " : '').(is_int($max) ? "at most {$max} " : '').'blocks.');
        }

        $boilerplate = (array) ($blockPattern['boilerplate'] ?? []);
        $fixed = (array) ($blockPattern['fixed'] ?? []);

        foreach ($blocks as $block) {
            $set = $field->set($block->type);

            if ($set === null) {
                $violations[] = new Violation(Violation::UNKNOWN_BLOCK, "\"{$block->type}\" is not a block {$field->handle} has.");

                continue;
            }

            $placed = [];

            foreach ($block->placements as $placement) {
                $target = Arranger::target($set->fields, $placement->field);

                if ($target === null || (! $target->isWritable() && ! $target->files)) {
                    $violations[] = new Violation(Violation::UNKNOWN_FIELD, "{$block->type} has no field \"{$placement->field}\" to write in.");

                    continue;
                }

                $placed[] = explode('/', $placement->field)[0];
                array_push($violations, ...$this->fits($placement, $target, $content, $units, "{$block->type}: {$placement->field}"));

                if (Plans::isMarkdown($target) && HeadingLevels::allowed($target) === [] && self::makesHeadings('', [$placement])) {
                    $violations[] = new Violation(Violation::NO_HEADINGS, "{$block->type}: {$placement->field} shows no headings, so none can be made in it.");
                }

                if (in_array($block->type, $boilerplate, true) && $placement->refs() !== []) {
                    $violations[] = new Violation(Violation::BOILERPLATE, "{$block->type} is copied whole on this site; words can't go in it.");
                }
            }

            foreach ($block->children as $handle => $children) {
                $nested = Arranger::target($set->fields, $handle);

                if ($nested === null || ! $nested->isBuilder()) {
                    $violations[] = new Violation(Violation::UNKNOWN_BLOCK, "{$block->type} can't hold blocks under \"{$handle}\".");

                    continue;
                }

                $placed[] = $handle;
                array_push($violations, ...$this->blocks($children, $nested, $content, $units, $pattern, []));
            }

            if ($block->origin !== null || in_array($block->type, $boilerplate, true)) {
                continue;
            }

            $writing = false;

            foreach ($set->fields as $setField) {
                $filled = in_array($setField->handle, $placed, true) || array_key_exists($setField->handle, $block->settings) || array_key_exists($setField->handle, (array) ($fixed[$block->type] ?? []));

                if (self::requiredWords($setField) && ! $filled) {
                    $violations[] = new Violation(Violation::REQUIRED, "{$block->type}: {$setField->handle} is required.");
                }

                $writing = $writing || (in_array($setField->kind, [Kind::Text, Kind::LongText, Kind::RichText, Kind::List, Kind::Rows], true) && ! $setField->files);
            }

            if ($writing && $block->placements === [] && $block->children === []) {
                $violations[] = new Violation(Violation::EMPTY_BLOCK, "a {$block->type} block with nothing in it.");
            }
        }

        return $violations;
    }

    /**
     * Whether what a placement holds suits its field.
     *
     * @return list<Violation>
     */
    private function fits(Placement $placement, Field $target, Content $content, Units $units, string $where): array
    {
        $violations = [];
        $media = array_filter($placement->from, fn (string $ref) => $units->get($ref)?->kind === UnitKind::Media);

        if ($target->files) {
            return count($media) === count($placement->from) && $placement->rows() === [] ? [] : [new Violation(Violation::KIND, "{$where} takes images only.")];
        }

        if ($media !== []) {
            return [new Violation(Violation::KIND, "{$where} can't take an image.")];
        }

        foreach ($placement->refs() as $ref) {
            if ($content->pieces($ref) === null) {
                $violations[] = new Violation(Violation::UNKNOWN_REF, "{$where}: \"{$ref}\" is nothing in this draft.");
            }
        }

        if ($violations !== []) {
            return $violations;
        }

        if ($target->kind === Kind::Rows) {
            foreach ($placement->rows() as $row) {
                foreach (array_keys($row) as $column) {
                    if (Arranger::target($target->fields, $column) === null) {
                        $violations[] = new Violation(Violation::UNKNOWN_FIELD, "{$where} has no column \"{$column}\".");
                    }
                }
            }

            return $violations;
        }

        if ($placement->rows() !== []) {
            return [new Violation(Violation::KIND, "{$where} has no rows.")];
        }

        $pieces = $content->resolve($placement->from, $placement->transform, $placement->options) ?? [];
        $headings = array_filter($pieces, fn (Piece $piece) => $piece->kind === Piece::HEADING);

        $fits = match (true) {
            Plans::isMarkdown($target) => true,
            $target->kind === Kind::Text => count($pieces) === 1 && $pieces[0]->kind !== Piece::QUOTE && $pieces[0]->kind !== Piece::TABLE && $pieces[0]->kind !== Piece::CODE,
            $target->kind === Kind::LongText, $target->kind === Kind::List => $headings === [],
            default => false,
        };

        return $fits ? [] : [new Violation(Violation::KIND, "{$where} can't hold what is placed in it.")];
    }

    /**
     * @return list<Violation>
     */
    private function conservation(Plan $plan, Units $units, Extras $extras, Content $content): array
    {
        $violations = [];
        $used = [];

        foreach ($plan->refs() as $ref) {
            $parsed = Content::parse($ref);

            if ($parsed === null) {
                continue;
            }

            if ($parsed['extra'] !== null) {
                $item = $extras->item($parsed['extra']);
                $key = $ref;

                if ($item !== null && $item->source === null && ! $item->needsAnswer()) {
                    $violations[] = new Violation(Violation::UNSOURCED_EXTRA, "{$ref} has no source.");
                }

                if (isset($used[$key]) || ($parsed['part'] === null && self::anyPart($used, $parsed['extra'])) || ($parsed['part'] !== null && isset($used[$parsed['extra']]))) {
                    $violations[] = new Violation(Violation::DUPLICATED, "{$ref} is placed twice.");
                }

                $used[$key] = true;

                continue;
            }

            $unit = $parsed['unit'] === null ? null : $units->get($parsed['unit']);

            if ($unit === null) {
                continue;
            }

            if (! isset($plan->fields[$unit->path->handle()])) {
                $violations[] = new Violation(Violation::OUTSIDE, "{$ref} is in {$unit->path->handle()}, which this plan leaves as it is.");
            }

            $count = max(1, count($unit->pieces));
            $pieces = $parsed['piece'] === null ? range(1, $count) : [$parsed['piece']];

            foreach ($pieces as $piece) {
                foreach ($parsed['side'] === null ? ['lead', 'rest'] : [$parsed['side']] as $side) {
                    $key = "{$unit->id}#{$piece}:{$side}";

                    if (isset($used[$key])) {
                        $violations[] = new Violation(Violation::DUPLICATED, "{$ref} is placed twice.");

                        break 2;
                    }

                    $used[$key] = true;
                }
            }
        }

        foreach ($units->all() as $unit) {
            if ($unit->kind === UnitKind::Media || ! isset($plan->fields[$unit->path->handle()])) {
                continue;
            }

            for ($piece = 1; $piece <= max(1, count($unit->pieces)); $piece++) {
                if (! isset($used["{$unit->id}#{$piece}:lead"], $used["{$unit->id}#{$piece}:rest"])) {
                    $violations[] = new Violation(Violation::MISSING, count($unit->pieces) > 1 ? "{$unit->id}#{$piece} is not placed." : "{$unit->id} is not placed.");
                }
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, true>  $used
     */
    private static function anyPart(array $used, string $item): bool
    {
        foreach (array_keys($used) as $key) {
            if (str_starts_with($key, $item.'.') || $key === $item) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a construct or its placements make headings.
     *
     * @param  list<Placement>  $placements
     */
    private static function makesHeadings(string $type, array $placements): bool
    {
        if (preg_match('/^h[1-6]$/', $type) === 1) {
            return true;
        }

        foreach ($placements as $placement) {
            if (in_array($placement->transform, [Transform::LeadInToHeading, Transform::HeadingLevel], true)) {
                return true;
            }
        }

        return false;
    }

    private static function isConstruct(string $type, Field $field): bool
    {
        if (in_array($type, ['text', 'p', 'list', 'quote'], true) || preg_match('/^h[1-6]$/', $type) === 1) {
            return true;
        }

        return str_starts_with($type, 'set:') && $field->set(substr($type, 4)) !== null;
    }

    /**
     * The text of the arranged fields, value by value.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $handles
     * @return list<string>
     */
    private static function texts(array $data, Schema $schema, array $handles): array
    {
        $fields = array_values(array_filter($schema->fields, fn (Field $field) => in_array($field->handle, $handles, true)));
        $texts = [];

        foreach (Walk::entry(new Schema($fields), new EntryData($data)) as $visit) {
            if ($visit->field->files) {
                continue;
            }

            $value = $visit->value;
            $text = match ($visit->field->kind) {
                Kind::Text, Kind::LongText, Kind::RichText => is_string($value) ? $value : null,
                Kind::List => is_array($value) ? implode("\n", array_map('strval', array_filter($value, 'is_scalar'))) : (is_string($value) ? $value : null),
                default => null,
            };

            if ($text !== null && trim($text) !== '') {
                $texts[] = $text;
            }
        }

        return $texts;
    }

    /**
     * The words of some text, counted, in order of the words.
     *
     * @return array<string, int>
     */
    private static function bag(string $text): array
    {
        $counts = array_count_values(NormalisedText::words(Markers::normalise($text)));
        ksort($counts);

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    private static function markers(string $text): array
    {
        $found = [];

        foreach (Markers::asks($text) as $ask) {
            $key = 'ask:'.Markers::normaliseHint($ask['hint']);
            $found[$key] = ($found[$key] ?? 0) + 1;
        }

        foreach (Markers::checks($text) as $check) {
            // By its list: a stat's value and label may be placed apart from its text.
            $key = 'check:'.Markers::normaliseHint($check['list']);
            $found[$key] = ($found[$key] ?? 0) + 1;
        }

        foreach (Markers::links($text) as $link) {
            $key = 'link:'.Markers::normaliseHint($link['hint']);
            $found[$key] = ($found[$key] ?? 0) + 1;
        }

        ksort($found);

        return $found;
    }

    /**
     * Notes from building that say a plan put something where it can't go.
     *
     * @param  array<int, string>  $notes
     * @return list<string>
     */
    private function problems(array $notes): array
    {
        return array_values(array_filter($notes, fn (string $note) => str_contains($note, 'is not a field here') || str_contains($note, 'is not an option') || str_starts_with($note, 'A block of type')));
    }

    /** A plan's layout, without its name: what two plans must not share. */
    private static function signature(Plan $plan): string
    {
        return (string) json_encode(array_map(fn (array $blocks) => array_map(fn (PlanBlock $block) => self::blockSignature($block), $blocks), $plan->fields));
    }

    /**
     * @return array<string, mixed>
     */
    private static function blockSignature(PlanBlock $block): array
    {
        return [
            'type' => $block->type,
            'placements' => array_map(fn (Placement $placement) => [$placement->field, $placement->refs(), $placement->transform->value, $placement->options], $block->placements),
            'children' => array_map(fn (array $blocks) => array_map(fn (PlanBlock $child) => self::blockSignature($child), $blocks), $block->children),
        ];
    }
}
