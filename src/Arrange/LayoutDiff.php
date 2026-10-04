<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Arrange;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\ExtraKind;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\Extras;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;

/**
 * How two layouts of the same words differ on the page, with no model:
 * what LayoutGate reads to decide whether a layout is worth offering, and
 * what the panel says and highlights about one.
 *
 * Each plan is laid out as what a reader sees, top to bottom: a page
 * builder's blocks (each one's set, then what is in its fields), and a
 * rich-text value as the arranger writes it, construct by construct (a
 * heading, a paragraph, a list, a quote). Constructs are compared by kind
 * and words, not by how the plan spells its refs, so "the writer's section
 * as written" and "the same section, construct by construct" are the same.
 * A field the plan doesn't arrange is the writer's.
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
 * - **summary()**: one to three plain phrases ("Closing line as a quote",
 *   "Lists as paragraphs", "Quote moved up").
 * - **places()**: where the second plan changed, as the preview maps a
 *   page: a top-level block of a page builder (by position), or a
 *   top-level field, and the section of rich text in it.
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

    /** The most phrases summary() gives. */
    public const SUMMARY = 3;

    /**
     * @param  list<array<string, mixed>>  $removed  From the first plan: {key, kind, weight, units, refs, norm, label, at, index}.
     * @param  list<array<string, mixed>>  $added  In the second.
     * @param  list<array<string, mixed>>  $before  All of the first plan's.
     * @param  list<array<string, mixed>>  $after  All of the second's.
     */
    private function __construct(
        public readonly float $weight,
        public readonly float $total,
        public readonly int $regions,
        public readonly int $blockEdits,
        public readonly array $removed,
        public readonly array $added,
        private readonly array $before,
        private readonly array $after,
        private readonly Extras $extras,
    ) {}

    /**
     * How $b differs from $a. $writer gives the fields a plan leaves as
     * written (the writer's plan itself, usually one of the two).
     *
     * @param  Extras|array<int, mixed>  $extras
     */
    public static function between(Plan $a, Plan $b, Plan $writer, Units $units, Extras|array $extras, Schema $schema): self
    {
        $extras = $extras instanceof Extras ? $extras : Extras::fromArray($extras);
        $content = new Content($units, $extras);
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

        return new self($weight, $total, $regions, $edits, $removed, $added, $left, $right, $extras);
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
     * in the second plan.
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
     * Where the second plan changed, top to bottom, as the preview maps a
     * page (Preview\BlockMap): `field` the top-level field; `block` the
     * position of a top-level block of that page builder (null for a field
     * that isn't one); `section` the section of rich text in it, counted
     * as MarkdownSections splits the value (null for the whole block or
     * field, or a value with one section).
     *
     * @return list<array{field: string, block: int|null, section: int|null}>
     */
    public function places(): array
    {
        $places = [];

        foreach ($this->added as $item) {
            $at = ['field' => $item['at']['field'], 'block' => $item['at']['block'], 'section' => $item['at']['section']];
            $places[json_encode($at)] = $at;
        }

        return array_values($places);
    }

    /**
     * What changed, in one to three plain phrases, top of the page first:
     * "Closing line as a quote", "Lists as paragraphs", "Lead-ins as
     * subheadings", "Quote moved up", "Call to action added". Empty when
     * nothing did.
     *
     * @return list<string>
     */
    public function summary(): array
    {
        if ($this->none()) {
            return [];
        }

        /** @var array<string, int> $phrases phrase => where it starts on the page */
        $phrases = [];
        $say = function (string $phrase, int $at) use (&$phrases): void {
            $phrases[$phrase] = min($phrases[$phrase] ?? PHP_INT_MAX, $at);
        };
        $explained = [];
        // Blocks already explained whole (added, moved, of another set): what is in them goes without saying.
        $blocks = [];
        $block = fn (array $item) => $item['at']['field'].'#'.($item['at']['block'] ?? '-');

        // Blocks of a page builder.
        $setsBefore = self::setCounts($this->before);
        $setsAfter = self::setCounts($this->after);
        $removedSets = array_values(array_filter($this->removed, fn (array $item) => str_starts_with($item['kind'], 'set:')));
        $handled = [];

        foreach ($this->added as $i => $item) {
            if (! str_starts_with($item['kind'], 'set:')) {
                continue;
            }

            $type = $item['kind'];

            if ($item['units'] === [] && $item['refs'] !== []) {
                $say(self::sentence($item['label']).' added', $item['index']);
                $explained[$i] = true;
                $handled[$type] = true;
                $blocks[$block($item)] = true;

                continue;
            }

            foreach ($removedSets as $old) {
                if ($item['units'] !== [] && $old['units'] === $item['units']) {
                    if ($old['kind'] === $type) {
                        $say(self::sentence($item['label']).' moved '.self::direction($old, $item, $this->before, $this->after), $item['index']);
                    } else {
                        $say(self::sentence($old['label']).' as '.self::lower($item['label']), $item['index']);
                    }

                    $explained[$i] = true;
                    $handled[$type] = $handled[$old['kind']] = true;
                    $blocks[$block($item)] = true;

                    continue 2;
                }
            }
        }

        foreach ($setsAfter + $setsBefore as $type => $_) {
            [$label, $first] = self::setInfo($type, $this->after) ?? self::setInfo($type, $this->before) ?? [substr($type, 4), 0];
            $was = $setsBefore[$type] ?? 0;
            $now = $setsAfter[$type] ?? 0;

            if ($was === $now || isset($handled[$type])) {
                continue;
            }

            $say(match (true) {
                $was === 0 => self::sentence($label).' added',
                $now === 0 => self::sentence($label).' removed',
                $now > $was => self::sentence($label).' split into '.$now.' blocks',
                default => self::sentence($label).' blocks joined',
            }, $first);
        }

        // What is in them: constructs, by where their words went.
        $moves = [];

        foreach ($this->added as $i => $item) {
            if (str_starts_with($item['kind'], 'set:') || isset($explained[$i]) || isset($blocks[$block($item)])) {
                continue;
            }

            if ($item['units'] === [] && $item['refs'] !== []) {
                $kind = $this->extras->extraOf($item['refs'][0])?->kind;
                $say(($kind !== null ? self::extraName($kind) : self::sentence(self::kindName($item['kind']))).' added', $item['index']);

                continue;
            }

            foreach ($this->removed as $old) {
                if (str_starts_with($old['kind'], 'set:') || $old['norm'] === '' || $item['norm'] === '' || ! self::overlaps($old['norm'], $item['norm'])) {
                    continue;
                }

                $from = $old['kind'];
                $to = $item['kind'];

                if ($from === $to && $old['norm'] === $item['norm']) {
                    $moves[] = [$old, $item];

                    continue 2;
                }

                $heading = fn (string $kind) => preg_match('/^h([1-6])$/', $kind, $m) === 1 ? (int) $m[1] : null;
                $phrase = match (true) {
                    $from === 'p' && $to === 'quote' => $this->lineAsQuote($old),
                    $from === 'list' && $to === 'p' => 'list-to-p',
                    $from === 'p' && $to === 'list' => 'p-to-list',
                    $from === 'p' && $heading($to) !== null => 'Lead-ins as subheadings',
                    $heading($from) !== null && $to === 'p' => 'Subheadings as lead-ins',
                    $heading($from) !== null && $heading($to) !== null => $heading($to) > $heading($from) ? 'Smaller headings' : 'Bigger headings',
                    $from === 'quote' && $to === 'p' => 'Quote as a paragraph',
                    $from === 'p' && $to === 'p' => 'Paragraphs joined',
                    default => null,
                };

                if ($phrase !== null) {
                    $say($phrase, $item['index']);

                    continue 2;
                }
            }
        }

        $lists = array_values(array_filter(array_keys($phrases), fn (string $phrase) => in_array($phrase, ['list-to-p', 'p-to-list'], true)));

        foreach ($lists as $marker) {
            $at = $phrases[$marker];
            unset($phrases[$marker]);
            $count = $marker === 'list-to-p'
                ? count(array_filter($this->removed, fn (array $item) => $item['kind'] === 'list' && $this->wentTo($item, 'p')))
                : count(array_filter($this->added, fn (array $item) => $item['kind'] === 'list' && $this->cameFrom($item, 'p')));
            $say($marker === 'list-to-p' ? ($count > 1 ? 'Lists as paragraphs' : 'A list as paragraphs') : ($count > 1 ? 'Paragraphs as lists' : 'Paragraphs as a list'), $at);
        }

        $movedSections = [];

        foreach ($moves as [$old, $item]) {
            if (preg_match('/^h[1-6]$/', $item['kind']) === 1) {
                $movedSections[json_encode($item['at'])] = true;
            }
        }

        foreach ($moves as [$old, $item]) {
            if (preg_match('/^h[1-6]$/', $item['kind']) !== 1 && isset($movedSections[json_encode($item['at'])])) {
                continue;
            }

            $direction = self::direction($old, $item, $this->before, $this->after);
            $say(match (true) {
                preg_match('/^h[1-6]$/', $item['kind']) === 1 => 'A section moved '.$direction,
                default => self::sentence(self::kindName($item['kind'])).' moved '.$direction,
            }, $item['index']);
        }

        $sections = array_filter(array_keys($phrases), fn (string $phrase) => str_starts_with($phrase, 'A section moved'));

        if (count($sections) > 1) {
            $at = min(array_map(fn (string $phrase) => $phrases[$phrase], $sections));

            foreach ($sections as $phrase) {
                unset($phrases[$phrase]);
            }

            $say('Sections reordered', $at);
        }

        if ($phrases === []) {
            return ['Laid out differently'];
        }

        asort($phrases);

        return array_slice(array_keys($phrases), 0, self::SUMMARY);
    }

    /**
     * Both plans as a reader sees them, in order.
     *
     * @return list<array<string, mixed>>
     */
    private static function items(Plan $plan, Plan $writer, Units $units, Content $content, Schema $schema): array
    {
        $items = [];

        foreach ($schema->fields as $field) {
            $blocks = $plan->fields[$field->handle] ?? $writer->fields[$field->handle] ?? null;

            if ($blocks === null) {
                continue;
            }

            $at = ['field' => $field->handle, 'block' => null, 'section' => null];

            if ($field->isBuilder()) {
                foreach ($blocks as $i => $block) {
                    self::blockItems($block, $field, $field->handle, ['block' => $i] + $at, $units, $content, $items);
                }
            } elseif (Plans::isMarkdown($field)) {
                $chunks = [];
                $refs = [];

                foreach ($blocks as $block) {
                    $pieces = [];

                    foreach ($block->placements as $placement) {
                        array_push($pieces, ...($content->resolve($placement->from, $placement->transform, $placement->options) ?? []));
                        array_push($refs, ...$placement->refs());
                    }

                    $chunk = Arranger::construct($block->type, $pieces);

                    if ($chunk !== '') {
                        $chunks[] = $chunk;
                    }
                }

                self::sectionItems(implode("\n\n", $chunks), $field->handle, $at, $refs, $content, $items);
            } else {
                foreach (isset($blocks[0]) ? $blocks[0]->placements : [] as $placement) {
                    self::placementItem($placement, $field, $field->handle, $at, $units, $content, $items);
                }
            }
        }

        $indexed = [];

        foreach (array_values($items) as $i => $item) {
            $item['index'] = $i;
            $indexed[] = $item;
        }

        return $indexed;
    }

    /**
     * One block of a page builder, its fields and the blocks inside it.
     *
     * @param  array{field: string, block: int|null, section: int|null}  $at
     * @param  list<array<string, mixed>>  $items
     */
    private static function blockItems(PlanBlock $block, Field $builder, string $root, array $at, Units $units, Content $content, array &$items): void
    {
        $set = $builder->set($block->type);
        $refs = $block->refs();
        $items[] = ['key' => "{$root}|set:{$block->type}", 'kind' => 'set:'.$block->type, 'weight' => (float) self::BLOCK_WEIGHT, 'units' => self::unitsOf($refs), 'refs' => array_values(array_filter($refs, fn (string $ref) => str_starts_with($ref, 'x'))), 'norm' => '', 'label' => $set !== null && $set->label !== '' ? $set->label : $block->type, 'at' => $at];

        foreach ($block->placements as $placement) {
            $target = $set === null ? null : Arranger::target($set->fields, $placement->field);

            if ($target === null) {
                continue;
            }

            if (Plans::isMarkdown($target)) {
                self::sectionItems(Content::joinMarkdown($content->resolve($placement->from, $placement->transform, $placement->options) ?? []), $root, $at, $placement->refs(), $content, $items);
            } else {
                self::placementItem($placement, $target, $root, $at, $units, $content, $items);
            }
        }

        foreach ($block->children as $handle => $children) {
            $child = $set === null ? null : Arranger::target($set->fields, $handle);

            if ($child !== null && $child->isBuilder()) {
                foreach ($children as $nested) {
                    self::blockItems($nested, $child, $root, $at, $units, $content, $items);
                }
            }
        }
    }

    /**
     * A rich-text value as the reader sees it, section by section.
     *
     * @param  array{field: string, block: int|null, section: int|null}  $at
     * @param  list<string>  $refs  What was placed in it: the units and extras its words came from.
     * @param  list<array<string, mixed>>  $items
     */
    private static function sectionItems(string $markdown, string $root, array $at, array $refs, Content $content, array &$items): void
    {
        $sections = MarkdownSections::split($markdown);
        $sources = self::sources($refs, $content);

        foreach ($sections as $n => $section) {
            self::pushPieces($section['pieces'], $root, ['section' => count($sections) > 1 ? $n : null] + $at, $sources, $items);
        }
    }

    /**
     * Pieces as the markdown they make: list items run on into one list.
     *
     * @param  list<Piece>  $pieces
     * @param  array{field: string, block: int|null, section: int|null}  $at
     * @param  list<array{ref: string, unit: string|null, norm: string}>  $sources
     * @param  list<array<string, mixed>>  $items
     */
    private static function pushPieces(array $pieces, string $root, array $at, array $sources, array &$items): void
    {
        $list = [];

        foreach ($pieces as $piece) {
            if ($piece->kind === Piece::ITEM) {
                $list[] = Content::plain($piece);

                continue;
            }

            if ($list !== []) {
                self::push('list', implode("\n", $list), $root, $at, $sources, $items);
                $list = [];
            }

            $kind = match ($piece->kind) {
                Piece::HEADING => 'h'.max(1, min(6, $piece->level ?: 2)),
                Piece::PARAGRAPH, Piece::FIELD => 'p',
                default => $piece->kind,
            };

            self::push($kind, Content::plain($piece), $root, $at, $sources, $items);
        }

        if ($list !== []) {
            self::push('list', implode("\n", $list), $root, $at, $sources, $items);
        }
    }

    /**
     * A placement into a field that isn't rich text: a heading, a summary,
     * rows, an image.
     *
     * @param  array{field: string, block: int|null, section: int|null}  $at
     * @param  list<array<string, mixed>>  $items
     */
    private static function placementItem(Placement $placement, Field $target, string $root, array $at, Units $units, Content $content, array &$items): void
    {
        $refs = $placement->refs();

        if ($target->files) {
            $assets = [];

            foreach ($placement->from as $ref) {
                $unit = $units->get($ref);
                array_push($assets, ...($unit !== null ? $unit->assets : [$ref]));
            }

            $items[] = ['key' => "{$root}|media|".implode(',', $assets), 'kind' => 'media', 'weight' => (float) self::MEDIA_WEIGHT, 'units' => self::unitsOf($refs), 'refs' => [], 'norm' => '', 'label' => '', 'at' => $at];

            return;
        }

        $texts = [];

        foreach ($refs as $ref) {
            foreach ($content->pieces($ref) ?? [] as $piece) {
                $texts[] = Content::plain($piece);
            }
        }

        self::push('field', implode("\n", $texts), $root, $at, self::sources($refs, $content), $items);
    }

    /**
     * @param  array{field: string, block: int|null, section: int|null}  $at
     * @param  list<array{ref: string, unit: string|null, norm: string}>  $sources
     * @param  list<array<string, mixed>>  $items
     */
    private static function push(string $kind, string $text, string $root, array $at, array $sources, array &$items): void
    {
        $norm = self::norm($text);

        if ($norm === '') {
            return;
        }

        $words = count(NormalisedText::words($text));
        $weight = preg_match('/^h[1-6]$/', $kind) === 1 ? max(self::HEADING_MIN, $words + min($words, self::HEADING_EXTRA)) : $words;
        $units = [];
        $extras = [];

        foreach ($sources as $source) {
            if ($source['norm'] !== '' && self::overlaps($source['norm'], $norm)) {
                if ($source['unit'] !== null) {
                    $units[] = $source['unit'];
                } else {
                    $extras[] = $source['ref'];
                }
            }
        }

        $items[] = ['key' => "{$root}|{$kind}|{$norm}", 'kind' => $kind, 'weight' => (float) $weight, 'units' => array_values(array_unique($units)), 'refs' => array_values(array_unique($extras)), 'norm' => $norm, 'label' => '', 'at' => $at];
    }

    /**
     * The words each placed ref stands for, piece by piece, to tell which
     * units (or extras) a construct's words came from.
     *
     * @param  list<string>  $refs
     * @return list<array{ref: string, unit: string|null, norm: string}>
     */
    private static function sources(array $refs, Content $content): array
    {
        $sources = [];

        foreach ($refs as $ref) {
            $unit = Content::parse($ref)['unit'] ?? null;

            foreach ($content->pieces($ref) ?? [] as $piece) {
                $sources[] = ['ref' => $ref, 'unit' => $unit, 'norm' => self::norm(Content::plain($piece))];
            }
        }

        return $sources;
    }

    /** Its words, lower-cased, markdown and punctuation aside: what constructs are compared by. */
    private static function norm(string $text): string
    {
        return implode(' ', NormalisedText::words($text));
    }

    /** One text holds the other: a lead-in's heading in its paragraph, a list item in its list. */
    private static function overlaps(string $a, string $b): bool
    {
        return str_contains($a, $b) || str_contains($b, $a);
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

    // -- The summary's words --------------------------------------------------

    /**
     * "Closing line as a quote": a paragraph set as a quote, named by where
     * it was: in the value's last section (or the last few lines of one
     * with no sections), or its first.
     *
     * @param  array<string, mixed>  $old
     */
    private function lineAsQuote(array $old): string
    {
        $same = array_values(array_filter($this->before, fn (array $item) => $item['at']['field'] === $old['at']['field'] && $item['at']['block'] === $old['at']['block'] && $item['norm'] !== ''));
        $count = count(array_filter($this->added, fn (array $item) => $item['kind'] === 'quote' && $this->cameFrom($item, 'p')));
        $sections = array_values(array_unique(array_map(fn (array $item) => $item['at']['section'] ?? -1, $same)));
        $position = array_search($old['index'], array_column($same, 'index'), true);
        $section = $old['at']['section'] ?? -1;

        return match (true) {
            $count > 1 => 'Lines as quotes',
            count($sections) > 1 && $section === max($sections), count($sections) <= 1 && $position !== false && $position >= count($same) - 2 => 'Closing line as a quote',
            count($sections) > 1 && $section === min($sections), count($sections) <= 1 && $position === 0 => 'Opening line as a quote',
            default => 'A line as a quote',
        };
    }

    /**
     * Whether a removed construct's words went into one of this kind.
     *
     * @param  array<string, mixed>  $old
     */
    private function wentTo(array $old, string $kind): bool
    {
        foreach ($this->added as $item) {
            if ($item['kind'] === $kind && $item['norm'] !== '' && self::overlaps($old['norm'], $item['norm'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an added construct's words came from one of this kind.
     *
     * @param  array<string, mixed>  $item
     */
    private function cameFrom(array $item, string $kind): bool
    {
        foreach ($this->removed as $old) {
            if ($old['kind'] === $kind && $old['norm'] !== '' && self::overlaps($old['norm'], $item['norm'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * "up" or "down": where it is on the page now, against where it was,
     * each as a share of its page.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     */
    private static function direction(array $old, array $new, array $before, array $after): string
    {
        return $new['index'] / max(1, count($after)) < $old['index'] / max(1, count($before)) ? 'up' : 'down';
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, int>
     */
    private static function setCounts(array $items): array
    {
        $counts = [];

        foreach ($items as $item) {
            if (str_starts_with($item['kind'], 'set:')) {
                $counts[$item['kind']] = ($counts[$item['kind']] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * A set's label and where it first is.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{0: string, 1: int}|null
     */
    private static function setInfo(string $type, array $items): ?array
    {
        foreach ($items as $item) {
            if ($item['kind'] === $type) {
                return [$item['label'], $item['index']];
            }
        }

        return null;
    }

    private static function kindName(string $kind): string
    {
        return match (true) {
            $kind === 'media' => 'image',
            $kind === 'quote' => 'quote',
            $kind === 'list' => 'list',
            $kind === 'p' => 'a paragraph',
            $kind === 'field' => 'text',
            preg_match('/^h[1-6]$/', $kind) === 1 => 'a heading',
            default => $kind,
        };
    }

    private static function extraName(ExtraKind $kind): string
    {
        return match ($kind) {
            ExtraKind::Stats => 'Stats',
            ExtraKind::Faq => 'FAQs',
            ExtraKind::PullQuote => 'Pull quote',
            ExtraKind::AtAGlance => 'At-a-glance list',
            ExtraKind::Caption => 'Caption',
            ExtraKind::Cta => 'Call to action',
            ExtraKind::Testimonial => 'Testimonial',
            ExtraKind::Intro => 'Intro',
        };
    }

    private static function sentence(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    private static function lower(string $label): string
    {
        // "Call to action" → "call to action"; keep an acronym ("FAQ").
        return preg_match('/^\p{Lu}{2}/u', $label) === 1 ? $label : mb_strtolower(mb_substr($label, 0, 1)).mb_substr($label, 1);
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
     * @param  array<int, array<string, mixed>>  $items
     */
    private static function sum(array $items): float
    {
        return array_sum(array_map(fn (array $item) => $item['weight'], $items));
    }
}
