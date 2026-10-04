<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Arrange;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\Extras;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;

/**
 * How two layouts of the same words differ on the page, with no model:
 * what LayoutGate reads to decide whether a layout is worth offering.
 *
 * Each plan is laid out as what a reader sees, top to bottom: a page
 * builder's blocks (each one's set, then what is in its fields), and a
 * rich-text value's constructs after the plan's transforms (a heading, a
 * paragraph, a list, a quote, an inline set). Constructs are compared by
 * kind and words, not by how the plan spells its refs, so "the writer's
 * section as written" and "the same section, construct by construct" are
 * the same. A field the plan doesn't arrange is the writer's.
 *
 * The two sequences are aligned (longest common subsequence). What is
 * left over on either side is the change:
 *
 * - **weight**: how much of the page changed, in words, a heading's words
 *   counting double (it is set larger), with a fixed weight for a block's
 *   own frame and for an image. `share()` is that over the page's weight.
 * - **regions**: separate places on the page that changed: one run of
 *   changes between unchanged constructs is one region; a section moved
 *   is two (where it was, where it is).
 * - **blockEdits**: page builders only: how many blocks were added,
 *   dropped or changed set, as an edit distance over set types.
 */
final class LayoutDiff
{
    /** A block's frame (its background, its padding) in words: a set added or dropped shows. */
    public const BLOCK_WEIGHT = 20;

    /** An image, in words. */
    public const MEDIA_WEIGHT = 40;

    /** A heading's words count double (it is set larger), up to this many extra, and it weighs at least HEADING_MIN. */
    public const HEADING_EXTRA = 10;

    public const HEADING_MIN = 6;

    /**
     * @param  list<array{key: string, kind: string, weight: float, units: list<string>, field: string}>  $removed  From the first plan.
     * @param  list<array{key: string, kind: string, weight: float, units: list<string>, field: string}>  $added  In the second.
     */
    private function __construct(
        public readonly float $weight,
        public readonly float $total,
        public readonly int $regions,
        public readonly int $blockEdits,
        public readonly array $removed,
        public readonly array $added,
    ) {}

    /**
     * How $b differs from $a. $writer gives the fields a plan leaves as
     * written (the writer's plan itself, usually one of the two).
     *
     * @param  Extras|array<int, mixed>  $extras
     */
    public static function between(Plan $a, Plan $b, Plan $writer, Units $units, Extras|array $extras, Schema $schema): self
    {
        $content = new Content($units, $extras instanceof Extras ? $extras : Extras::fromArray($extras));
        $left = self::items($a, $writer, $units, $content, $schema);
        $right = self::items($b, $writer, $units, $content, $schema);
        [$keptLeft, $keptRight, $regions] = self::align(array_column($left, 'key'), array_column($right, 'key'));

        $removed = array_values(array_diff_key($left, $keptLeft));
        $added = array_values(array_diff_key($right, $keptRight));
        $weight = (self::sum($removed) + self::sum($added)) / 2;
        $total = max(self::sum($left), self::sum($right));

        $edits = 0;
        $sequencesA = self::builderSequences($a, $writer, $schema);
        $sequencesB = self::builderSequences($b, $writer, $schema);

        foreach ($sequencesA + $sequencesB as $handle => $_) {
            $x = $sequencesA[$handle] ?? [];
            $y = $sequencesB[$handle] ?? [];
            $edits += (int) round(Candidates::levenshtein($x, $y) * max(count($x), count($y)));
        }

        return new self($weight, $total, $regions, $edits, $removed, $added);
    }

    /** The share of the page that changed, 0–1. */
    public function share(): float
    {
        return $this->total <= 0 ? 0.0 : min(1.0, $this->weight / $this->total);
    }

    /** Nothing a reader would see changed. */
    public function none(): bool
    {
        return $this->removed === [] && $this->added === [];
    }

    /**
     * The units whose words are somewhere else, or shaped differently,
     * in the second plan: what the preview can point at.
     *
     * @return list<string>
     */
    public function changedUnits(): array
    {
        $ids = [];

        foreach ($this->added as $item) {
            array_push($ids, ...$item['units']);
        }

        return array_values(array_unique($ids));
    }

    /**
     * Both plans as a reader sees them, in order.
     *
     * @return list<array{key: string, kind: string, weight: float, units: list<string>, field: string}>
     */
    private static function items(Plan $plan, Plan $writer, Units $units, Content $content, Schema $schema): array
    {
        $items = [];

        foreach ($schema->fields as $field) {
            $blocks = $plan->fields[$field->handle] ?? $writer->fields[$field->handle] ?? null;

            if ($blocks === null) {
                continue;
            }

            if ($field->isBuilder()) {
                self::blockItems($blocks, $field, $field->handle, $units, $content, $items);
            } elseif (Plans::isMarkdown($field)) {
                foreach ($blocks as $block) {
                    self::constructItems($block, $field->handle, $units, $content, $items);
                }
            } else {
                foreach (isset($blocks[0]) ? $blocks[0]->placements : [] as $placement) {
                    self::placementItem($placement, $field, $field->handle, $units, $content, $items);
                }
            }
        }

        return $items;
    }

    /**
     * @param  list<PlanBlock>  $blocks
     * @param  list<array{key: string, kind: string, weight: float, units: list<string>, field: string}>  $items
     */
    private static function blockItems(array $blocks, Field $builder, string $where, Units $units, Content $content, array &$items): void
    {
        foreach ($blocks as $block) {
            $set = $builder->set($block->type);
            $items[] = ['key' => "{$where}|set:{$block->type}", 'kind' => 'set:'.$block->type, 'weight' => (float) self::BLOCK_WEIGHT, 'units' => self::unitsOf($block->refs()), 'field' => $where];

            foreach ($block->placements as $placement) {
                $target = $set === null ? null : Arranger::target($set->fields, $placement->field);

                if ($target === null) {
                    continue;
                }

                if (Plans::isMarkdown($target)) {
                    $pieces = $content->resolve($placement->from, $placement->transform, $placement->options) ?? [];
                    self::pushPieces($pieces, "{$where}|{$block->type}.{$placement->field}", self::unitsOf($placement->refs()), $items);
                } else {
                    self::placementItem($placement, $target, "{$where}|{$block->type}.{$placement->field}", $units, $content, $items);
                }
            }

            foreach ($block->children as $handle => $children) {
                $child = $set === null ? null : Arranger::target($set->fields, $handle);

                if ($child !== null && $child->isBuilder()) {
                    self::blockItems($children, $child, "{$where}/{$handle}", $units, $content, $items);
                }
            }
        }
    }

    /**
     * One construct of a rich-text field, as what it shows.
     *
     * @param  list<array{key: string, kind: string, weight: float, units: list<string>, field: string}>  $items
     */
    private static function constructItems(PlanBlock $block, string $where, Units $units, Content $content, array &$items): void
    {
        $pieces = [];
        $refs = [];

        foreach ($block->placements as $placement) {
            array_push($pieces, ...($content->resolve($placement->from, $placement->transform, $placement->options) ?? []));
            array_push($refs, ...$placement->refs());
        }

        $ids = self::unitsOf($refs);

        if (str_starts_with($block->type, 'set:')) {
            self::push($block->type, implode("\n", array_map(fn (Piece $piece) => Content::plain($piece), $pieces)), $where, $ids, $items);

            return;
        }

        // As the arranger writes it, read back: what the page shows.
        $shown = [];

        foreach (MarkdownSections::split(Arranger::construct($block->type, $pieces)) as $section) {
            array_push($shown, ...$section['pieces']);
        }

        self::pushPieces($shown, $where, $ids, $items);
    }

    /**
     * Pieces as the markdown they make: list items run on into one list.
     *
     * @param  list<Piece>  $pieces
     * @param  list<string>  $ids
     * @param  list<array{key: string, kind: string, weight: float, units: list<string>, field: string}>  $items
     */
    private static function pushPieces(array $pieces, string $where, array $ids, array &$items): void
    {
        $list = [];

        foreach ($pieces as $piece) {
            if ($piece->kind === Piece::ITEM) {
                $list[] = Content::plain($piece);

                continue;
            }

            if ($list !== []) {
                self::push('list', implode("\n", $list), $where, $ids, $items);
                $list = [];
            }

            $kind = match ($piece->kind) {
                Piece::HEADING => 'h'.max(1, min(6, $piece->level ?: 2)),
                Piece::PARAGRAPH, Piece::FIELD => 'p',
                default => $piece->kind,
            };

            self::push($kind, Content::plain($piece), $where, $ids, $items);
        }

        if ($list !== []) {
            self::push('list', implode("\n", $list), $where, $ids, $items);
        }
    }

    /**
     * A placement into a field that isn't rich text: a heading, a summary,
     * rows, an image.
     *
     * @param  list<array{key: string, kind: string, weight: float, units: list<string>, field: string}>  $items
     */
    private static function placementItem(Placement $placement, Field $target, string $where, Units $units, Content $content, array &$items): void
    {
        $ids = self::unitsOf($placement->refs());

        if ($target->files) {
            $assets = [];

            foreach ($placement->from as $ref) {
                array_push($assets, ...($units->get($ref) !== null ? $units->get($ref)->assets : [$ref]));
            }

            $items[] = ['key' => "{$where}|media|".implode(',', $assets), 'kind' => 'media', 'weight' => (float) self::MEDIA_WEIGHT, 'units' => $ids, 'field' => $where];

            return;
        }

        $texts = [];

        foreach ($placement->refs() as $ref) {
            foreach ($content->pieces($ref) ?? [] as $piece) {
                $texts[] = Content::plain($piece);
            }
        }

        self::push('field', implode("\n", $texts), $where, $ids, $items);
    }

    /**
     * @param  list<string>  $ids
     * @param  list<array{key: string, kind: string, weight: float, units: list<string>, field: string}>  $items
     */
    private static function push(string $kind, string $text, string $where, array $ids, array &$items): void
    {
        $normal = NormalisedText::string($text);

        if ($normal === '') {
            return;
        }

        $words = count(NormalisedText::words($text));
        $weight = preg_match('/^h[1-6]$/', $kind) === 1 ? max(self::HEADING_MIN, $words + min($words, self::HEADING_EXTRA)) : $words;
        $field = explode('|', $where)[0];
        $items[] = ['key' => "{$field}|{$kind}|{$normal}", 'kind' => $kind, 'weight' => (float) $weight, 'units' => $ids, 'field' => $where];
    }

    /**
     * The units some refs come from: "u7#2:lead" is u7; extras are none.
     *
     * @param  list<string>  $refs
     * @return list<string>
     */
    private static function unitsOf(array $refs): array
    {
        $ids = [];

        foreach ($refs as $ref) {
            $unit = Content::parse($ref)['unit'] ?? null;

            if ($unit !== null) {
                $ids[] = $unit;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<string, list<string>>
     */
    private static function builderSequences(Plan $plan, Plan $writer, Schema $schema): array
    {
        $sequences = [];

        foreach ($schema->fields as $field) {
            $blocks = $plan->fields[$field->handle] ?? $writer->fields[$field->handle] ?? null;

            if ($blocks !== null && $field->isBuilder()) {
                $sequences[$field->handle] = array_map(fn (PlanBlock $block) => $block->type, $blocks);
            }
        }

        return $sequences;
    }

    /**
     * The longest common subsequence of two key lists: the indexes kept on
     * each side, and how many separate runs of change there are.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return array{0: array<int, true>, 1: array<int, true>, 2: int}
     */
    private static function align(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        $table = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $table[$i][$j] = $a[$i] === $b[$j] ? $table[$i + 1][$j + 1] + 1 : max($table[$i + 1][$j], $table[$i][$j + 1]);
            }
        }

        $keptA = [];
        $keptB = [];
        $regions = 0;
        $changing = false;
        [$i, $j] = [0, 0];

        while ($i < $n || $j < $m) {
            if ($i < $n && $j < $m && $a[$i] === $b[$j]) {
                $keptA[$i++] = true;
                $keptB[$j++] = true;
                $changing = false;

                continue;
            }

            if (! $changing) {
                $regions++;
                $changing = true;
            }

            if ($j >= $m || ($i < $n && $table[$i + 1][$j] >= $table[$i][$j + 1])) {
                $i++;
            } else {
                $j++;
            }
        }

        return [$keptA, $keptB, $regions];
    }

    /**
     * @param  list<array{weight: float}>|array<int, array{weight: float}>  $items
     */
    private static function sum(array $items): float
    {
        return array_sum(array_map(fn (array $item) => $item['weight'], $items));
    }
}
