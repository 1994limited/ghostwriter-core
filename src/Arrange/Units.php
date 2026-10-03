<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Arrange;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\BlockRef;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Visit;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Walk;
use NineteenNinetyFour\Ghostwriter\Core\Layout\RichTextDialect;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use Traversable;

/**
 * Every piece of text in a draft or an entry, as units in reading order.
 *
 * - **Granularity.** A text, long text or list value is one unit. A rich
 *   text value (or a markdown field) is split by its top headings into
 *   sections, with any lead before the first as prose (MarkdownSections).
 *   A row of a rows field is one unit. An image field with something in it
 *   is a media unit.
 * - **Ids** are "u1", "u2"… in reading order, and `next` is the number the
 *   next new unit gets. Ids are never reused: UnitMatcher carries them to
 *   the next draft, and restore() puts stored ones back.
 * - **Draft or entry.** fromDraft() reads a draft, where rich text is
 *   already markdown and blocks have no IDs (paths by position);
 *   fromEntry() reads an entry's stored values through the dialect, with
 *   block IDs in the paths (Suggest edits: ids for one call only).
 *
 * @implements IteratorAggregate<int, Unit>
 */
final class Units implements Countable, IteratorAggregate
{
    /** @var array<string, Unit> */
    private readonly array $byId;

    /**
     * @param  list<Unit>  $units
     */
    private function __construct(
        private readonly array $units,
        public readonly int $next,
    ) {
        $byId = [];

        foreach ($units as $unit) {
            $byId[$unit->id] = $unit;
        }

        $this->byId = $byId;
    }

    /**
     * The units of a draft (Text\Draft, or its data). Rich text in a draft
     * is markdown already; a dialect is only used for a value that isn't a
     * string.
     *
     * @param  Draft|array<string, mixed>  $draft
     */
    public static function fromDraft(Draft|array $draft, Schema $schema, ?RichTextDialect $richText = null): self
    {
        return self::read(new EntryData($draft instanceof Draft ? $draft->data : $draft), $schema, $richText, true);
    }

    /** The units of an entry's current values, read as markdown with its dialect. */
    public static function fromEntry(EntryData $entry, Schema $schema, RichTextDialect $richText): self
    {
        return self::read($entry, $schema, $richText, false);
    }

    /**
     * @param  list<Unit>  $units  With their ids.
     */
    public static function of(array $units, ?int $next = null): self
    {
        $highest = 0;

        foreach ($units as $unit) {
            $highest = max($highest, self::number($unit->id));
        }

        return new self(array_values($units), max($next ?? 0, $highest + 1));
    }

    public function get(string $id): ?Unit
    {
        return $this->byId[$id] ?? null;
    }

    /**
     * @return list<Unit> In reading order.
     */
    public function all(): array
    {
        return $this->units;
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->byId);
    }

    /**
     * The units of one block (or row, or field), children included. Paths
     * are compared by position, so a draft's units are found by an entry's
     * path and the other way round.
     *
     * @return list<Unit>
     */
    public function inBlock(FieldPath $block): array
    {
        $prefix = $block->dotted();

        return array_values(array_filter($this->units, fn (Unit $unit) => $unit->path->dotted() === $prefix || str_starts_with($unit->path->dotted(), $prefix.'.')));
    }

    /**
     * The units of exactly one value: a rich text value's sections, in order.
     *
     * @return list<Unit>
     */
    public function at(FieldPath $path): array
    {
        $dotted = $path->dotted();

        return array_values(array_filter($this->units, fn (Unit $unit) => $unit->path->dotted() === $dotted));
    }

    public function count(): int
    {
        return count($this->units);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->units);
    }

    /**
     * What to store beside the draft (Session::$units): the next id, and
     * for each unit where it is, its kind and its hash. Not the text: the
     * draft has it.
     *
     * @return array{next: int, units: array<string, array{path: string, part?: int, kind: string, hash: string}>}
     */
    public function sidecar(): array
    {
        $units = [];

        foreach ($this->units as $unit) {
            $units[$unit->id] = ['path' => $unit->path->toString()]
                + ($unit->part !== null ? ['part' => $unit->part] : [])
                + ['kind' => $unit->kind->value, 'hash' => $unit->hash()];
        }

        return ['next' => $this->next, 'units' => $units];
    }

    /**
     * These units with the ids a sidecar gave the same draft: a unit gets
     * the stored id at its place (path, part and kind); any other unit gets
     * a new one, after the sidecar's `next`. An empty sidecar changes
     * nothing.
     *
     * @param  array<mixed>  $sidecar  From sidecar().
     */
    public function restore(array $sidecar): self
    {
        $stored = is_array($sidecar['units'] ?? null) ? $sidecar['units'] : [];

        if ($stored === []) {
            return $this;
        }

        $byPlace = [];
        $next = is_int($sidecar['next'] ?? null) ? $sidecar['next'] : 1;

        foreach ($stored as $id => $entry) {
            if (! is_array($entry) || ! is_string($entry['path'] ?? null)) {
                continue;
            }

            $part = is_int($entry['part'] ?? null) ? '~'.$entry['part'] : '';
            $byPlace[$entry['path'].$part.'|'.(is_string($entry['kind'] ?? null) ? $entry['kind'] : '')] = (string) $id;
            $next = max($next, self::number((string) $id) + 1);
        }

        $units = [];
        $taken = [];

        foreach ($this->units as $unit) {
            $id = $byPlace[$unit->where().'|'.$unit->kind->value] ?? null;

            if ($id === null || isset($taken[$id])) {
                $id = 'u'.$next++;
            }

            $taken[$id] = true;
            $units[] = $unit->withId($id);
        }

        return new self($units, $next);
    }

    /**
     * @return array{next: int, units: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return ['next' => $this->next, 'units' => array_map(fn (Unit $unit) => $unit->toArray(), $this->units)];
    }

    /**
     * @param  array<mixed>  $array  From toArray().
     */
    public static function fromArray(array $array): self
    {
        $units = array_values(array_map(fn (array $unit) => Unit::fromArray($unit), array_filter(is_array($array['units'] ?? null) ? $array['units'] : [], 'is_array')));

        return self::of($units, is_int($array['next'] ?? null) ? $array['next'] : null);
    }

    /** The number in an id: 7 for "u7"; 0 for anything else. */
    public static function number(string $id): int
    {
        return preg_match('/^u(\d+)$/', $id, $m) === 1 ? (int) $m[1] : 0;
    }

    private static function read(EntryData $entry, Schema $schema, ?RichTextDialect $richText, bool $draft): self
    {
        $found = [];
        $rows = [];

        foreach (Walk::entry($schema, $entry) as $visit) {
            $here = $visit->path->toString();

            foreach ($rows as $prefix) {
                if (str_starts_with($here, $prefix.'/')) {
                    continue 2;
                }
            }

            if ($visit->field->kind === Kind::Rows) {
                $rows[] = $here;
                array_push($found, ...self::rows($visit, $richText, $draft));

                continue;
            }

            array_push($found, ...self::value($visit, $richText, $draft));
        }

        $units = [];

        foreach ($found as $i => $unit) {
            $units[] = $unit->withId('u'.($i + 1));
        }

        return new self($units, count($units) + 1);
    }

    /**
     * @return list<Unit> Without ids yet.
     */
    private static function value(Visit $visit, ?RichTextDialect $richText, bool $draft): array
    {
        $field = $visit->field;
        $value = $visit->value;
        $blockType = Unit::blockTypeOf($visit->path);

        if ($field->files) {
            $assets = array_values(array_map('strval', array_filter(is_array($value) ? $value : [$value], fn ($item) => is_scalar($item) && $item !== '')));

            return $assets === [] ? [] : [new Unit('', UnitKind::Media, $visit->path, '', [], $blockType, $assets)];
        }

        if (self::isMarkdown($field)) {
            $markdown = self::markdown($field, $value, $richText, $draft);
            $units = [];

            foreach (MarkdownSections::split($markdown ?? '') as $part => $section) {
                $units[] = new Unit('', $section['kind'], $visit->path, $section['markdown'], $section['pieces'], $blockType, [], $part);
            }

            return $units;
        }

        $text = match ($field->kind) {
            Kind::Text, Kind::LongText => is_scalar($value) ? trim((string) $value) : '',
            Kind::List => implode("\n", array_map(fn ($item) => trim((string) $item), array_filter(is_array($value) ? $value : [$value], 'is_scalar'))),
            default => '',
        };

        if ($text === '') {
            return [];
        }

        if ($field->kind === Kind::List) {
            $items = array_values(array_filter(explode("\n", $text), fn (string $item) => $item !== ''));

            return [new Unit('', UnitKind::List, $visit->path, $text, array_map(fn (string $item) => new Piece(Piece::ITEM, $item), $items), $blockType)];
        }

        $kind = $field->kind === Kind::LongText ? UnitKind::Prose : UnitKind::Text;

        return [new Unit('', $kind, $visit->path, $text, [new Piece(Piece::PARAGRAPH, $text)], $blockType)];
    }

    /**
     * One unit per row with text in it: its text fields, as pieces.
     *
     * @return list<Unit>
     */
    private static function rows(Visit $visit, ?RichTextDialect $richText, bool $draft): array
    {
        if (! is_array($visit->value)) {
            return [];
        }

        $units = [];

        foreach (array_values(array_filter($visit->value, 'is_array')) as $i => $row) {
            $pieces = [];

            foreach ($visit->field->fields as $field) {
                $value = $row[$field->handle] ?? null;
                $text = match (true) {
                    self::isMarkdown($field) => self::markdown($field, $value, $richText, $draft),
                    in_array($field->kind, [Kind::Text, Kind::LongText], true) => is_scalar($value) ? trim((string) $value) : null,
                    $field->kind === Kind::List => is_array($value) ? implode("\n", array_map('strval', array_filter($value, 'is_scalar'))) : null,
                    default => null,
                };

                if ($text !== null && $text !== '') {
                    $pieces[] = new Piece(Piece::FIELD, $text, 0, $field->handle);
                }
            }

            if ($pieces === []) {
                continue;
            }

            $id = $row['id'] ?? null;
            $path = $visit->path->with(new BlockRef(is_int($id) || is_string($id) ? $id : null, $i));
            $markdown = implode("\n\n", array_map(fn (Piece $piece) => $piece->markdown, $pieces));
            $units[] = new Unit('', UnitKind::Row, $path, $markdown, $pieces, Unit::blockTypeOf($visit->path));
        }

        return $units;
    }

    /** Rich text, or a long text field that holds markdown. */
    private static function isMarkdown(Field $field): bool
    {
        return $field->kind === Kind::RichText
            || ($field->kind === Kind::LongText && ($field->type === 'markdown' || ($field->meta['format'] ?? null) === 'markdown'));
    }

    private static function markdown(Field $field, mixed $value, ?RichTextDialect $richText, bool $draft): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (is_string($value) && ($draft || $field->kind === Kind::LongText || $richText === null)) {
            return trim($value);
        }

        return $richText?->toMarkdown($value, $field);
    }
}
