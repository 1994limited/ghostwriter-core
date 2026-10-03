<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Preview;

use Closure;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\MarkdownSections;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Unit;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Units;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\BlockRef;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Text\Utf8;

/**
 * The preview's invisible markers: how a rendered page is mapped back to
 * the blocks, fields and sections it shows.
 *
 * **Encoding.** A marker is `U+E0067 U+E0077` (tag "g", tag "w"), a
 * payload in Unicode tag characters (ASCII 0x20–0x7E at U+E0020–U+E007E),
 * and `U+E007F` (cancel tag). Tag characters are default-ignorable: not
 * drawn, no width, no effect on shaping. They only mean something after
 * the black flag (U+1F3F4, subdivision flags), so a marker is never read
 * there.
 *
 * **Payloads.** `b7` a block, `f2` a top-level field, `s3` a section of
 * rich text, each with an optional field index: `b7.2` is the third field
 * of block 7's set. The map (BlockMap) says what each key is.
 *
 * **Where they go:** on every writable text value of every block (and of
 * each top-level field), so a template that doesn't print one field still
 * prints another. Plain text is prefixed; markdown gets it after a line's
 * leading `#`, `>`, bullet or number (and any emphasis); HTML in its first
 * text; Bard in its first text node; a list on its first item. A rich text
 * value with more than one unit also gets a section marker at the start of
 * each unit (MarkdownSections' split). Values that look like addresses
 * (`https://…`, `/…`, `#…`, `mailto:`) are left alone.
 *
 * **Never saved.** Only the preview's copy of the data is marked: apply
 * builds its data separately. strip() removes markers from anything, and
 * Text\Draft::parse() strips them from every draft.
 */
final class PreviewMarkers
{
    public const START = "\u{E0067}\u{E0077}";

    public const END = "\u{E007F}";

    /** A marker, anywhere. Group 1 is the payload, in tag characters. */
    public const PATTERN = '/(?<!\x{1F3F4})\x{E0067}\x{E0077}([\x{E0020}-\x{E007E}]{1,16})\x{E007F}/u';

    /** A payload Ghostwriter writes: groups key, index (field). */
    public const PAYLOAD = '/^([bfs]\d+)(?:\.(\d+))?$/';

    /** Values that are addresses, not words. */
    private const ADDRESS = '/^\s*(?:[a-z][a-z0-9+.-]*:|\/|#|www\.)\S*\s*$/i';

    /** How many words of each value go in a block's anchors. */
    public const ANCHOR_WORDS = 8;

    /** @var Closure(mixed): ?string */
    private readonly Closure $assetName;

    /** @var list<MappedBlock> */
    private array $blocks = [];

    /** @var array<string, int> */
    private array $counters = [];

    private ?Units $units = null;

    /**
     * @param  (callable(mixed): ?string)|null  $assetName  The file's basename for a stored asset reference: by default the part after the last `/` or `::` of a string. Craft passes one that loads the asset by ID.
     */
    public function __construct(?callable $assetName = null)
    {
        $this->assetName = Closure::fromCallable($assetName ?? fn (mixed $value): ?string => self::basename($value));
    }

    /** A marker for a payload ("b7.2"). */
    public static function encode(string $payload): string
    {
        $tags = '';

        foreach (str_split($payload) as $char) {
            $code = ord($char);

            if ($code < 0x20 || $code > 0x7E) {
                throw new \InvalidArgumentException('A marker payload is printable ASCII.');
            }

            $tags .= mb_chr(0xE0000 + $code, 'UTF-8');
        }

        if ($tags === '' || strlen($payload) > 16) {
            throw new \InvalidArgumentException('A marker payload is 1 to 16 characters.');
        }

        return self::START.$tags.self::END;
    }

    /**
     * The markers in some text, in order: each payload, its key and field
     * index (null when it has none, or isn't Ghostwriter's shape), and its
     * offset in bytes.
     *
     * @return list<array{payload: string, key: string|null, field: int|null, offset: int}>
     */
    public static function decode(string $text): array
    {
        if (preg_match_all(self::PATTERN, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        $found = [];

        foreach ($matches as $match) {
            $payload = implode('', array_map(fn (string $char) => chr(mb_ord($char, 'UTF-8') - 0xE0000), mb_str_split($match[1][0])));
            $parts = preg_match(self::PAYLOAD, $payload, $m) === 1 ? $m : null;
            $found[] = [
                'payload' => $payload,
                'key' => $parts[1] ?? null,
                'field' => isset($parts[2]) ? (int) $parts[2] : null,
                'offset' => $match[0][1],
            ];
        }

        return $found;
    }

    /**
     * Every marker taken out: of a string, or of every string in an array.
     * Anything else comes back as it was.
     */
    public static function strip(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::stripText($value);
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::strip($item);
            }
        }

        return $value;
    }

    /** Every marker taken out of a string. */
    public static function stripText(string $text): string
    {
        return str_contains($text, self::START) ? (string) preg_replace(self::PATTERN, '', $text) : $text;
    }

    /** Whether a string, or any string in an array, holds a marker. */
    public static function contains(mixed $value): bool
    {
        if (is_string($value)) {
            return str_contains($value, self::START) && preg_match(self::PATTERN, $value) === 1;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::contains($item)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The preview's copy of an entry's data, marked, with its map.
     *
     * $data is in storage form, as apply would set it: rich text as HTML,
     * Bard nodes or markdown. With $units (from the same data, or the draft
     * it was built from), each mapped block lists the unit ids it shows,
     * matched by position.
     *
     * @param  array<string, mixed>  $data
     */
    public function mark(array $data, Schema $schema, ?Units $units = null): PreviewData
    {
        $this->blocks = [];
        $this->counters = [];
        $this->units = $units;

        $hash = sha1((string) json_encode(Utf8::scrub(self::strip($data))));
        $marked = $data;

        foreach ($schema->fields as $field) {
            if (! array_key_exists($field->handle, $data)) {
                continue;
            }

            $path = FieldPath::of($field->handle);

            if ($field->isBuilder()) {
                $marked[$field->handle] = $this->builder($data[$field->handle], $field, $path, null);

                continue;
            }

            if (! self::holdsText($field)) {
                continue;
            }

            $key = $this->key('f');
            $at = count($this->blocks);
            $info = ['units' => [], 'assets' => [], 'anchors' => []];
            $marked[$field->handle] = $this->value($data[$field->handle], $field, $path, $key, null, $key, $info);
            array_splice($this->blocks, $at, 0, [new MappedBlock($key, MappedBlock::FIELD, $path->toString(), $field->label !== '' ? $field->label : $field->handle, null, $info['units'], [], $info['assets'], $info['anchors'], $field->handle)]);
        }

        return new PreviewData($marked, new BlockMap($this->blocks), $hash);
    }

    /**
     * A plain value with a marker at its start (after leading whitespace).
     */
    public static function markText(string $text, string $marker): string
    {
        if (trim($text) === '' || preg_match(self::ADDRESS, $text) === 1) {
            return $text;
        }

        $lead = strlen($text) - strlen(ltrim($text));

        return substr($text, 0, $lead).$marker.substr($text, $lead);
    }

    /**
     * Markdown with markers at the start of lines' text: [line => marker],
     * lines counted from 0. A line that starts a code block, a table's
     * divider or raw HTML is left alone.
     *
     * @param  array<int, string>  $markers
     */
    public static function markMarkdown(string $markdown, array $markers): string
    {
        $lines = preg_split('/(\r\n|\r|\n)/', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

        foreach ($markers as $line => $marker) {
            $index = $line * 2;
            $text = $lines[$index] ?? null;

            if ($text === null || trim($text) === '' || preg_match('/^\s*(?:`{3,}|~{3,}|<|\|?\s*:?-{3,})/', $text) === 1 || preg_match('/^( {4,}|\t)/', $text) === 1) {
                continue;
            }

            preg_match('/^\s*(?:(?:#{1,6}|>|[-*+]|\d+[.)]|\|)\s*)*[*_]*/', $text, $syntax);
            $at = strlen($syntax[0] ?? '');
            $lines[$index] = substr($text, 0, $at).$marker.substr($text, $at);
        }

        return implode('', $lines);
    }

    /**
     * HTML with markers in the first text of elements at the top level:
     * [element index => marker], where -1 is the first text of all. Markers
     * are inserted into the string; nothing else about the HTML changes.
     *
     * @param  array<int, string>  $markers
     */
    public static function markHtml(string $html, array $markers): string
    {
        $inserts = [];

        foreach (self::htmlTexts($html) as [$top, $offset]) {
            foreach ($markers as $element => $marker) {
                if (! isset($inserts[$element]) && ($element === -1 || $element === $top)) {
                    $inserts[$element] = [$offset, $marker];
                }
            }
        }

        // From the end, so earlier offsets stay right; two at one place keep their order.
        uasort($inserts, fn (array $a, array $b) => $b[0] <=> $a[0]);
        $byOffset = [];

        foreach ($markers as $element => $marker) {
            if (isset($inserts[$element])) {
                $byOffset[$inserts[$element][0]] = ($byOffset[$inserts[$element][0]] ?? '').$marker;
            }
        }

        krsort($byOffset);

        foreach ($byOffset as $offset => $marker) {
            $html = substr($html, 0, $offset).$marker.substr($html, $offset);
        }

        return $html;
    }

    /**
     * Bard (ProseMirror) nodes with markers in the first text node of
     * top-level nodes: [node index => marker], where -1 is the first text
     * of all.
     *
     * @param  array<mixed>  $nodes
     * @param  array<int, string>  $markers
     * @return array<mixed>
     */
    public static function markBard(array $nodes, array $markers): array
    {
        $byNode = [];

        foreach ($markers as $index => $marker) {
            $node = $index === -1 ? self::firstTextNode($nodes) : $index;

            if ($node !== null) {
                $byNode[$node] = ($byNode[$node] ?? '').$marker;
            }
        }

        foreach ($byNode as $index => $marker) {
            if (is_array($nodes[$index] ?? null)) {
                $nodes[$index] = self::prefixFirstText($nodes[$index], $marker);
            }
        }

        return $nodes;
    }

    private function builder(mixed $value, Field $field, FieldPath $path, ?string $parent): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $index = 0;

        foreach ($value as $i => $block) {
            if (! is_array($block)) {
                continue;
            }

            $position = $index++;
            $type = is_scalar($block['type'] ?? null) ? (string) $block['type'] : '';
            $set = $field->set($type);

            if ($set === null || ($block['enabled'] ?? true) === false) {
                continue;
            }

            $id = $block['id'] ?? null;
            $here = $path->with(new BlockRef(is_int($id) || is_string($id) ? $id : null, $position, $type));
            $key = $this->key('b');
            $at = count($this->blocks);
            $info = ['units' => [], 'assets' => [], 'anchors' => []];
            $fields = [];

            foreach ($set->fields as $f => $child) {
                if (! array_key_exists($child->handle, $block)) {
                    continue;
                }

                if ($child->isBuilder()) {
                    $block[$child->handle] = $this->builder($block[$child->handle], $child, $here->with($child->handle), $key);

                    continue;
                }

                if (self::holdsText($child) || $child->files) {
                    $fields[$f] = $child->handle;
                    $block[$child->handle] = $this->value($block[$child->handle], $child, $here->with($child->handle), $key.'.'.$f, $parent, $key, $info);
                }
            }

            $value[$i] = $block;
            array_splice($this->blocks, $at, 0, [new MappedBlock($key, MappedBlock::BLOCK, $here->toString(), $set->label !== '' ? $set->label : $type, $parent, $info['units'], $fields, $info['assets'], $info['anchors'], $type)]);
        }

        return $value;
    }

    /**
     * One value marked, its units, assets and anchors added to $info.
     *
     * @param  array{units: list<string>, assets: list<string>, anchors: list<string>}  $info
     */
    private function value(mixed $value, Field $field, FieldPath $path, string $payload, ?string $parent, string $owner, array &$info): mixed
    {
        if ($field->files) {
            $this->addUnits($info, $this->units?->at($path) ?? []);

            foreach (is_array($value) ? $value : [$value] as $item) {
                $name = ($this->assetName)($item);

                if ($name !== null && $name !== '' && ! in_array($name, $info['assets'], true)) {
                    $info['assets'][] = $name;
                }
            }

            return $value;
        }

        if ($field->kind === Kind::Rows || $field->kind === Kind::Group) {
            if (! is_array($value)) {
                return $value;
            }

            $rows = $field->kind === Kind::Group ? [$value] : $value;

            foreach ($rows as $r => $row) {
                if (! is_array($row)) {
                    continue;
                }

                foreach ($field->fields as $child) {
                    if (array_key_exists($child->handle, $row) && (self::holdsText($child) || $child->files)) {
                        $rows[$r][$child->handle] = $this->value($row[$child->handle], $child, $path->with($child->handle), $payload, $parent, $owner, $info);
                    }
                }
            }

            $this->addUnits($info, $this->units?->inBlock($path) ?? []);

            return $field->kind === Kind::Group ? $rows[0] : $rows;
        }

        $marker = self::encode($payload);
        $units = $this->units?->at($path) ?? [];
        $this->addUnits($info, $field->kind === Kind::RichText || self::isMarkdownField($field) ? [] : $units);
        $anchor = self::anchor(self::firstLine($value));

        if ($anchor !== null) {
            $info['anchors'][] = $anchor;
        }

        if ($field->kind === Kind::List) {
            if (is_array($value)) {
                foreach ($value as $k => $item) {
                    if (is_string($item) && trim($item) !== '') {
                        $value[$k] = self::markText($item, $marker);

                        break;
                    }
                }

                return $value;
            }

            return is_string($value) ? self::markText($value, $marker) : $value;
        }

        if ($field->kind === Kind::RichText || self::isMarkdownField($field)) {
            return $this->richText($value, $field, $path, $marker, $owner, $units, $info);
        }

        return is_string($value) ? self::markText($value, $marker) : $value;
    }

    /**
     * @param  list<Unit>  $units
     * @param  array{units: list<string>, assets: list<string>, anchors: list<string>}  $info
     */
    private function richText(mixed $value, Field $field, FieldPath $path, string $marker, string $owner, array $units, array &$info): mixed
    {
        $starts = match (true) {
            is_array($value) => self::bardSections($value),
            is_string($value) && self::isHtml($value) && ! self::isMarkdownField($field) => self::htmlSections($value),
            is_string($value) => array_map(fn (array $section) => $section['line'], MarkdownSections::split($value)),
            default => [],
        };

        $markers = [];

        if (count($starts) > 1) {
            foreach ($starts as $n => $start) {
                $key = $this->key('s');
                $unit = $units[$n] ?? null;
                $markers[] = [$start, self::encode($key)];
                $this->blocks[] = new MappedBlock(
                    $key,
                    MappedBlock::SECTION,
                    $path->toString(),
                    $unit !== null ? self::label($unit->markdown) : '',
                    $owner,
                    $unit !== null ? [$unit->id] : [],
                    [],
                    [],
                    $unit !== null ? array_values(array_filter([self::anchor(self::firstLine(isset($unit->pieces[0]) ? $unit->pieces[0]->markdown : $unit->markdown))])) : [],
                );
            }
        } else {
            $this->addUnits($info, $units);
        }

        // The field's own marker first, at the very start; then each section's.
        $html = is_string($value) && self::isHtml($value) && ! self::isMarkdownField($field);
        $all = [is_string($value) && ! $html ? ($starts[0] ?? 0) : -1 => $marker];

        foreach ($markers as [$start, $sectionMarker]) {
            $all[$start] = ($all[$start] ?? '').$sectionMarker;
        }

        return match (true) {
            is_array($value) => self::markBard($value, $all),
            $html => self::markHtml($value, $all),
            is_string($value) => self::markMarkdown($value, $all),
            default => $value,
        };
    }

    /**
     * @param  array{units: list<string>, assets: list<string>, anchors: list<string>}  $info
     * @param  list<Unit>  $units
     */
    private function addUnits(array &$info, array $units): void
    {
        foreach ($units as $unit) {
            if (! in_array($unit->id, $info['units'], true)) {
                $info['units'][] = $unit->id;
            }
        }
    }

    private function key(string $prefix): string
    {
        $this->counters[$prefix] = ($this->counters[$prefix] ?? 0) + 1;

        return $prefix.$this->counters[$prefix];
    }

    private static function holdsText(Field $field): bool
    {
        return ! $field->files && in_array($field->kind, [Kind::Text, Kind::LongText, Kind::RichText, Kind::List, Kind::Rows, Kind::Group], true);
    }

    private static function isMarkdownField(Field $field): bool
    {
        return $field->kind === Kind::LongText && ($field->type === 'markdown' || ($field->meta['format'] ?? null) === 'markdown')
            || $field->kind === Kind::RichText && ($field->meta['format'] ?? null) === 'markdown';
    }

    private static function isHtml(string $value): bool
    {
        return preg_match('/^\s*<[a-z!]/i', $value) === 1;
    }

    /**
     * Where an HTML value's units start: -1 for a lead before the first
     * top heading (or the only unit), then each top heading's index among
     * the top-level elements.
     *
     * @return list<int>
     */
    private static function htmlSections(string $html): array
    {
        $headings = [];
        $leadText = false;

        foreach (self::htmlElements($html) as $index => $tag) {
            if (preg_match('/^h([1-6])$/', $tag, $m) === 1) {
                $headings[$index] = (int) $m[1];
            }
        }

        if ($headings === []) {
            return [-1];
        }

        $top = min($headings);
        $first = null;
        $starts = [];

        foreach ($headings as $index => $level) {
            if ($level === $top) {
                $first ??= $index;
                $starts[] = $index;
            }
        }

        foreach (self::htmlTexts($html) as [$element]) {
            $leadText = $element !== null && $element < $first;

            break;
        }

        return $leadText ? [-1, ...$starts] : $starts;
    }

    /**
     * The tag names of the top-level elements, by index.
     *
     * @return array<int, string>
     */
    private static function htmlElements(string $html): array
    {
        $elements = [];

        foreach (self::htmlTokens($html) as $token) {
            if ($token['depth'] === 0 && $token['open'] !== null) {
                $elements[$token['element']] = $token['open'];
            }
        }

        return $elements;
    }

    /**
     * Each piece of text that isn't only whitespace: the index of the
     * top-level element it's in (null for text at the top level), and the
     * byte offset of its first character.
     *
     * @return list<array{0: int|null, 1: int}>
     */
    private static function htmlTexts(string $html): array
    {
        $texts = [];

        foreach (self::htmlTokens($html) as $token) {
            if ($token['text'] && ! $token['raw'] && preg_match('/\S/', $token['value'], $m, PREG_OFFSET_CAPTURE) === 1) {
                $texts[] = [$token['depth'] === 0 ? null : $token['element'], $token['offset'] + $m[0][1]];
            }
        }

        return $texts;
    }

    /**
     * @return list<array{value: string, offset: int, text: bool, open: string|null, depth: int, element: int, raw: bool}>
     */
    private static function htmlTokens(string $html): array
    {
        $void = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];
        $tokens = [];
        $depth = 0;
        $element = -1;
        $raw = null;

        preg_match_all('/<!--.*?-->|<[^>]*>|[^<]+/s', $html, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$value, $offset]) {
            $open = null;
            $isText = $value[0] !== '<';

            if (! $isText && preg_match('/^<\s*\/\s*([a-z0-9-]+)/i', $value, $close) === 1) {
                $depth = max(0, $depth - 1);
                $raw = $raw === strtolower($close[1]) ? null : $raw;
            } elseif (! $isText && preg_match('/^<\s*([a-z][a-z0-9-]*)/i', $value, $tag) === 1) {
                $open = strtolower($tag[1]);

                if ($depth === 0) {
                    $element++;
                }

                $tokens[] = ['value' => $value, 'offset' => $offset, 'text' => false, 'open' => $open, 'depth' => $depth, 'element' => $element, 'raw' => $raw !== null];

                if (! in_array($open, $void, true) && ! str_ends_with($value, '/>')) {
                    $depth++;
                    $raw ??= in_array($open, ['script', 'style', 'template'], true) ? $open : null;
                }

                continue;
            }

            $tokens[] = ['value' => $value, 'offset' => $offset, 'text' => $isText, 'open' => null, 'depth' => $depth, 'element' => $element, 'raw' => $raw !== null];
        }

        return $tokens;
    }

    /**
     * Where a Bard value's units start: -1 for a lead before the first top
     * heading (or the only unit), then each top heading's node index.
     *
     * @param  array<mixed>  $nodes
     * @return list<int>
     */
    private static function bardSections(array $nodes): array
    {
        $headings = [];

        foreach ($nodes as $index => $node) {
            if (is_array($node) && ($node['type'] ?? null) === 'heading' && is_int($index)) {
                $level = $node['attrs']['level'] ?? 1;
                $headings[$index] = is_numeric($level) ? (int) $level : 1;
            }
        }

        if ($headings === []) {
            return [-1];
        }

        $top = min($headings);
        $starts = array_keys(array_filter($headings, fn (int $level) => $level === $top));
        $firstText = self::firstTextNode($nodes);

        return $firstText !== null && $firstText < $starts[0] ? [-1, ...$starts] : $starts;
    }

    /**
     * The index of the first top-level node with text in it.
     *
     * @param  array<mixed>  $nodes
     */
    private static function firstTextNode(array $nodes): ?int
    {
        foreach ($nodes as $index => $node) {
            if (is_int($index) && is_array($node) && self::bardText($node) !== '') {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>  $node
     * @return array<mixed>
     */
    private static function prefixFirstText(array $node, string $marker, bool &$done = false): array
    {
        if (($node['type'] ?? null) === 'text' && is_string($node['text'] ?? null) && trim($node['text']) !== '') {
            $node['text'] = $marker.$node['text'];
            $done = true;

            return $node;
        }

        if (($node['type'] ?? null) === 'set' || ! is_array($node['content'] ?? null)) {
            return $node;
        }

        foreach ($node['content'] as $i => $child) {
            if (! $done && is_array($child)) {
                $node['content'][$i] = self::prefixFirstText($child, $marker, $done);
            }
        }

        return $node;
    }

    /**
     * @param  array<mixed>  $node
     */
    private static function bardText(array $node): string
    {
        if (($node['type'] ?? null) === 'text') {
            return is_string($node['text'] ?? null) ? trim($node['text']) : '';
        }

        if (($node['type'] ?? null) === 'set') {
            return '';
        }

        $text = '';

        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            $text .= is_array($child) ? self::bardText($child) : '';
        }

        return $text;
    }

    /**
     * The first line of words in a stored value, whatever its form: what
     * one element of the page will hold whole.
     */
    private static function firstLine(mixed $value): string
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $line = is_array($item) && array_key_exists('type', $item) ? self::bardText($item) : self::firstLine($item);

                if (trim($line) !== '') {
                    return $line;
                }
            }

            return '';
        }

        if (! is_scalar($value)) {
            return '';
        }

        $text = html_entity_decode((string) preg_replace('/<[^>]*>/', "\n", (string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (NormalisedText::words($line) !== []) {
                return $line;
            }
        }

        return '';
    }

    /** The first words of a line, normalised; null when there are fewer than two. */
    private static function anchor(string $line): ?string
    {
        $words = array_slice(NormalisedText::words(self::stripText($line)), 0, self::ANCHOR_WORDS);

        return count($words) >= 2 ? implode(' ', $words) : null;
    }

    /** A section's label: its heading's words, or its first few. */
    private static function label(string $markdown): string
    {
        $line = trim((string) strtok(ltrim($markdown), "\n"));
        $words = preg_split('/\s+/u', trim((string) preg_replace('/^(?:#{1,6}|>|[-*+]|\d+[.)])\s*|[*_`]/u', '', $line))) ?: [];

        return implode(' ', array_slice($words, 0, 8));
    }

    private static function basename(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = (string) preg_replace('/[?#].*$/', '', $value);
        $parts = preg_split('#::|/#', $value) ?: [];
        $name = end($parts);

        return is_string($name) && $name !== '' ? $name : null;
    }
}
