<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Studio;

use NineteenNinetyFour\Ghostwriter\Core\Arrange\Content;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\Extras;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Piece;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Plan;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Plans;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\PlanValidator;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Unit;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\UnitKind;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Units;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Pattern;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Seo\HeadingPolicy;
use NineteenNinetyFour\Ghostwriter\Core\Seo\RenderProfile;

/**
 * What the layout planner is shown (Studio::planLayouts()): the draft's
 * units and extras as short summaries, the blocks and fields it may use,
 * the site's own patterns and the writer's layout. No voice guide and no
 * examples: it writes nothing.
 */
final class LayoutBrief
{
    /** How many words of a unit, and of a piece, the planner is shown. */
    public const UNIT_WORDS = 25;

    public const PIECE_WORDS = 12;

    /**
     * @param  list<array{id: string, field: string, sequence: list<string>, count: int, share: float, example: string, exampleId: int|string|null}>  $patterns  Arrange\SitePatterns::find().
     * @param  array<string, array{headings: float, lists: float, quotes: float, entries: int}>  $profile  Arrange\SitePatterns::profile().
     * @param  RenderProfile|null  $render  How the template prints headings, for each rich-text field's real levels; null for the default.
     */
    public function __construct(
        public readonly Units $units,
        public readonly Extras $extras,
        public readonly Schema $schema,
        public readonly array $patterns,
        public readonly array $profile,
        public readonly Plan $writer,
        public readonly int $count = 2,
        public readonly ?Pattern $pattern = null,
        public readonly ?RenderProfile $render = null,
    ) {}

    public function prompt(): string
    {
        return implode("\n\n", [
            "<units>\n".$this->unitLines()."\n</units>",
            "<extras>\n".($this->extraLines() ?: '(none)')."\n</extras>",
            "<fields>\n".$this->fieldLines()."\n</fields>",
            "<site_patterns>\n".($this->patternLines() ?: '(none yet)')."\n</site_patterns>",
            "<writer_layout>\n".$this->writerLines()."\n</writer_layout>",
        ]);
    }

    private function unitLines(): string
    {
        $lines = [];

        foreach ($this->units->all() as $unit) {
            $where = $this->where($unit);
            $words = count(preg_split('/\s+/u', trim($unit->markdown), -1, PREG_SPLIT_NO_EMPTY) ?: []);

            if ($unit->kind === UnitKind::Media) {
                $lines[] = "{$unit->id} [image] in {$where}";

                continue;
            }

            $lines[] = "{$unit->id} [{$unit->kind->value}, {$words} words] in {$where}: \"".self::words($unit->markdown, self::UNIT_WORDS).'"';

            if (count($unit->pieces) > 1) {
                foreach ($unit->pieces as $i => $piece) {
                    $lead = $piece->kind === Piece::PARAGRAPH ? Content::leadIn($piece) : null;
                    $kind = $piece->field ?? $piece->kind.($piece->kind === Piece::HEADING ? ' '.$piece->level : '');
                    $lines[] = '  '.$unit->id.'#'.($i + 1)." {$kind}".($lead !== null ? ' (lead-in "'.$lead[0].'")' : '').': "'.self::words(Content::plain($piece), self::PIECE_WORDS).'"';
                }
            } elseif (($unit->pieces[0] ?? null) !== null && ($lead = Content::leadIn($unit->pieces[0])) !== null) {
                $lines[] = "  {$unit->id}#1 paragraph (lead-in \"{$lead[0]}\")";
            }
        }

        return implode("\n", $lines);
    }

    private function extraLines(): string
    {
        $lines = [];

        foreach ($this->extras->all() as $extra) {
            foreach ($extra->items as $item) {
                $parts = array_map(fn (string $name, string $text) => "{$name}: \"".self::words($text, self::PIECE_WORDS).'"', array_keys($item->parts), $item->parts);
                $lines[] = "{$item->id} [{$extra->kind->value}] \"".self::words($item->text, self::UNIT_WORDS).'"'.($parts !== [] ? ' ('.implode(', ', $parts).')' : '').($item->needsAnswer() ? ' (holds a fact still to add)' : '');
            }
        }

        return implode("\n", $lines);
    }

    private function fieldLines(): string
    {
        $lines = [];

        foreach ($this->schema->fields as $field) {
            if (! $field->isWritable()) {
                continue;
            }

            if ($field->isBuilder()) {
                $limits = self::limits($field);
                $lines[] = "{$field->handle}: page builder{$limits}. Blocks:";
                array_push($lines, ...$this->sets($field, 1));
            } elseif (Plans::isMarkdown($field)) {
                $sets = array_keys(array_filter($field->sets, fn ($set) => true));
                $headings = HeadingPolicy::for($field, $this->render)->constructs();
                $lines[] = "{$field->handle}: rich text. Constructs: text, p, ".($headings !== '' ? $headings.', ' : '').'list, quote'.($sets !== [] ? ', '.implode(', ', array_map(fn ($set) => "set:{$set}", $sets)) : '').'.'.($headings === '' ? ' No headings.' : '').(isset($this->profile[$field->handle]) ? ' The site\'s own: '.$this->profile[$field->handle]['headings'].' headings per 100 words, '.round($this->profile[$field->handle]['lists'] * 100).'% list items, '.round($this->profile[$field->handle]['quotes'] * 100).'% quotes.' : '');
            } elseif (in_array($field->kind, [Kind::Text, Kind::LongText, Kind::List], true)) {
                $lines[] = "{$field->handle}: ".self::kind($field).($field->required ? ', required' : '');
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function sets(Field $field, int $depth): array
    {
        $lines = [];
        $pad = str_repeat('  ', $depth);
        $boilerplate = (array) ($this->pattern->blocks[$field->handle]['boilerplate'] ?? []);

        foreach ($field->sets as $handle => $set) {
            $fields = [];
            $nested = [];

            foreach ($set->fields as $setField) {
                if ($setField->isBuilder()) {
                    $nested[] = $setField;
                    $fields[] = "{$setField->handle} (blocks: ".implode(', ', array_keys($setField->sets)).')';
                } elseif ($setField->isWritable() || $setField->files) {
                    // Only what the planner must place words in is "required": an image, a link or a setting it never fills.
                    $kind = self::kind($setField);

                    if (Plans::isMarkdown($setField)) {
                        $headings = HeadingPolicy::for($setField, $this->render, (string) $handle)->constructs();
                        $kind .= $headings !== '' ? ", headings {$headings}" : ', no headings';
                    }

                    $fields[] = "{$setField->handle} ({$kind}".(PlanValidator::requiredWords($setField) ? ', required' : '').')';
                }
            }

            $copied = in_array((string) $handle, $boilerplate, true) ? '; copied whole on every page, place nothing in it' : '';
            $lines[] = "{$pad}- {$handle}: ".($fields === [] ? 'no fields' : implode(', ', $fields)).$copied;

            foreach ($nested as $inner) {
                array_push($lines, ...$this->sets($inner, $depth + 1));
            }
        }

        return $lines;
    }

    private function patternLines(): string
    {
        return implode("\n", array_map(
            fn (array $pattern) => "{$pattern['id']}: {$pattern['field']}: ".implode(', ', $pattern['sequence'])." (used by {$pattern['count']} ".($pattern['count'] === 1 ? 'entry' : 'entries').($pattern['example'] !== '' ? ", e.g. {$pattern['example']}" : '').')',
            $this->patterns,
        ));
    }

    private function writerLines(): string
    {
        $lines = [];

        foreach ($this->writer->fields as $handle => $blocks) {
            $lines[] = "{$handle}: ".implode(', ', array_map(fn ($block) => $block->type.' ['.implode(' ', $block->refs()).']', $blocks));
        }

        return $lines === [] ? '(nothing arranged)' : implode("\n", $lines);
    }

    /** Where a unit is, by block type and field: "hero.heading", "body". */
    private function where(Unit $unit): string
    {
        return ($unit->blockType !== null ? $unit->blockType.'.' : '').$unit->path->field().' ('.$unit->path->toString().')';
    }

    private static function kind(Field $field): string
    {
        return match (true) {
            $field->files => 'image',
            Plans::isMarkdown($field) => 'rich text',
            default => match ($field->kind) {
                Kind::Text => 'text, one line',
                Kind::LongText => 'long text, no headings',
                Kind::List => 'list of short items',
                Kind::Rows => 'rows of '.implode(', ', array_map(fn (Field $column) => $column->handle, $field->fields)),
                Kind::Group => 'group of '.implode(', ', array_map(fn (Field $inner) => $inner->handle, $field->fields)),
                default => $field->kind->value,
            },
        };
    }

    private static function limits(Field $field): string
    {
        $min = $field->meta['min'] ?? null;
        $max = $field->meta['max'] ?? null;

        return match (true) {
            is_int($min) && is_int($max) => ", {$min} to {$max} blocks",
            is_int($max) => ", at most {$max} blocks",
            is_int($min) => ", at least {$min} blocks",
            default => '',
        };
    }

    private static function words(string $text, int $limit): string
    {
        $words = preg_split('/\s+/u', trim(str_replace('"', '\'', $text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_slice($words, 0, $limit)).(count($words) > $limit ? ' …' : '');
    }
}
