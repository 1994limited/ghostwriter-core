<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Arrange;

use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\ExtraItem;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\ExtraKind;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\Extras;

/**
 * What a plan's refs stand for, as pieces, and the fixed transforms on
 * them. Shared by the arranger and the validator.
 *
 * @internal
 */
final class Content
{
    public function __construct(
        private readonly Units $units,
        private readonly Extras $extras,
    ) {}

    /**
     * A ref taken apart: a unit, with a piece (1-based) and a side of a
     * lead-in; or an extra item, with a part. The other keys are null.
     * Null for anything else.
     *
     * @return array{unit: string|null, piece: int|null, side: string|null, extra: string|null, part: string|null}|null
     */
    public static function parse(string $ref): ?array
    {
        if (preg_match('/^(u\d+)(?:#(\d+)(?::(lead|rest))?)?$/', $ref, $m) === 1) {
            return ['unit' => $m[1], 'piece' => isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null, 'side' => $m[3] ?? null, 'extra' => null, 'part' => null];
        }

        if (preg_match('/^(x\d+\.\d+)(?:\.([a-z_]+))?$/', $ref, $m) === 1) {
            return ['unit' => null, 'piece' => null, 'side' => null, 'extra' => $m[1], 'part' => $m[2] ?? null];
        }

        return null;
    }

    /**
     * The pieces a ref stands for; null when it stands for nothing here.
     *
     * @return list<Piece>|null
     */
    public function pieces(string $ref): ?array
    {
        $parsed = self::parse($ref);

        if ($parsed === null) {
            return null;
        }

        if ($parsed['extra'] !== null) {
            $item = $this->extras->item($parsed['extra']);

            return $item === null ? null : $this->extraPieces($item, $parsed['part'], $this->extras->extraOf($parsed['extra'])?->kind);
        }

        $unit = $parsed['unit'] === null ? null : $this->units->get($parsed['unit']);

        if ($unit === null) {
            return null;
        }

        if ($parsed['piece'] === null) {
            return $unit->pieces;
        }

        $piece = $unit->pieces[$parsed['piece'] - 1] ?? null;

        if ($piece === null) {
            return null;
        }

        if ($parsed['side'] === null) {
            return [$piece];
        }

        $split = self::leadIn($piece);

        if ($split === null) {
            return null;
        }

        return [$parsed['side'] === 'lead' ? new Piece(Piece::PARAGRAPH, $split[0]) : new Piece(Piece::PARAGRAPH, $split[1])];
    }

    /**
     * The pieces a ref stands for as a row: an extra item by its named
     * parts (a stat's value and label, a question and its answer), so each
     * goes in its own column; anything else as pieces() gives it.
     *
     * @return list<Piece>|null
     */
    public function rowPieces(string $ref): ?array
    {
        $parsed = self::parse($ref);
        $item = $parsed !== null && $parsed['extra'] !== null && $parsed['part'] === null ? $this->extras->item($parsed['extra']) : null;

        if ($item === null) {
            return $this->pieces($ref);
        }

        $parts = array_diff_key($item->parts, array_flip(ExtraKind::addresses()));

        if (isset($parts['value'], $parts['label'])) {
            return [new Piece(Piece::PARAGRAPH, $parts['value'], 0, 'value'), new Piece(Piece::PARAGRAPH, $parts['label'], 0, 'label')];
        }

        unset($parts['value'], $parts['label']);
        $pieces = [];

        foreach ($parts as $name => $text) {
            $pieces[] = new Piece(Piece::PARAGRAPH, $text, 0, $name);
        }

        $pieces[] = new Piece(Piece::PARAGRAPH, $item->text, 0, 'text');

        return $pieces;
    }

    /**
     * The pieces of several refs, transformed; null when any ref stands for nothing.
     *
     * @param  list<string>  $refs
     * @param  array<string, mixed>  $options
     * @return list<Piece>|null
     */
    public function resolve(array $refs, Transform $transform = Transform::AsIs, array $options = []): ?array
    {
        $pieces = [];

        foreach ($refs as $ref) {
            $found = $this->pieces($ref);

            if ($found === null) {
                return null;
            }

            array_push($pieces, ...$found);
        }

        return self::transform($pieces, $transform, $options);
    }

    /**
     * @param  list<Piece>  $pieces
     * @param  array<string, mixed>  $options
     * @return list<Piece>
     */
    public static function transform(array $pieces, Transform $transform, array $options = []): array
    {
        $level = is_int($options['level'] ?? null) ? max(1, min(6, $options['level'])) : null;

        switch ($transform) {
            case Transform::LeadInToHeading:
                $out = [];

                foreach ($pieces as $piece) {
                    $split = $piece->kind === Piece::PARAGRAPH ? self::leadIn($piece) : null;

                    if ($split === null) {
                        $out[] = $piece;

                        continue;
                    }

                    $out[] = new Piece(Piece::HEADING, $split[0], $level ?? 3);

                    if ($split[1] !== '') {
                        $out[] = new Piece(Piece::PARAGRAPH, $split[1]);
                    }
                }

                return $out;

            case Transform::HeadingToLeadIn:
                $out = [];

                for ($i = 0; $i < count($pieces); $i++) {
                    $piece = $pieces[$i];
                    $next = $pieces[$i + 1] ?? null;

                    if ($piece->kind === Piece::HEADING && $next !== null && $next->kind === Piece::PARAGRAPH) {
                        $heading = self::plain($piece);
                        $stop = preg_match('/[.:!?…]$/u', $heading) === 1 ? '' : '.';
                        $out[] = new Piece(Piece::PARAGRAPH, '**'.$heading.$stop.'** '.$next->markdown);
                        $i++;

                        continue;
                    }

                    $out[] = $piece;
                }

                return $out;

            case Transform::ParagraphsToList:
                return array_map(fn (Piece $piece) => $piece->kind === Piece::PARAGRAPH || $piece->kind === Piece::FIELD ? new Piece(Piece::ITEM, self::plain($piece)) : $piece, $pieces);

            case Transform::ListToParagraphs:
                return array_map(fn (Piece $piece) => $piece->kind === Piece::ITEM ? new Piece(Piece::PARAGRAPH, self::plain($piece)) : $piece, $pieces);

            case Transform::HeadingLevel:
                $levels = array_map(fn (Piece $piece) => $piece->level, array_filter($pieces, fn (Piece $piece) => $piece->kind === Piece::HEADING));

                if ($levels === [] || $level === null) {
                    return $pieces;
                }

                $shift = $level - min($levels);

                return array_map(fn (Piece $piece) => $piece->kind === Piece::HEADING ? new Piece(Piece::HEADING, self::plain($piece), max(1, min(6, $piece->level + $shift))) : $piece, $pieces);

            case Transform::AsQuote:
                return $pieces === [] ? [] : [new Piece(Piece::QUOTE, implode("\n\n", array_map(fn (Piece $piece) => self::plain($piece), $pieces)))];

            default:
                return $pieces;
        }
    }

    /**
     * A bold lead-in and the rest: "**November: Cut back.** Prune…" gives
     * ["November: Cut back", "Prune…"]. Null for a paragraph with none.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function leadIn(Piece $piece): ?array
    {
        if (preg_match('/^\s*(\*\*|__)(.+?)\1\s*(.*)$/su', $piece->markdown, $m) !== 1) {
            return null;
        }

        $lead = rtrim(trim($m[2]), '.:');

        return $lead === '' ? null : [$lead, trim($m[3])];
    }

    /** A piece's words without its markdown: no heading marks, bullet or quote mark. */
    public static function plain(Piece $piece): string
    {
        $lines = preg_split('/\n/u', $piece->markdown) ?: [];
        $lines = array_map(fn (string $line) => (string) preg_replace('/^\s{0,3}(?:#{1,6}\s+|>\s?|(?:[-*+]|\d+[.)])\s+)/u', '', $line), $lines);

        if ($piece->kind === Piece::HEADING) {
            $lines = array_map(fn (string $line) => rtrim((string) preg_replace('/\s+#+\s*$/u', '', $line)), $lines);
        }

        return trim(implode("\n", array_map('trim', $lines)));
    }

    /** A piece as markdown of its own kind. */
    public static function markdown(Piece $piece): string
    {
        return match ($piece->kind) {
            Piece::HEADING => str_repeat('#', max(1, min(6, $piece->level ?: 2))).' '.self::plain($piece),
            Piece::ITEM => preg_match('/^\s*(?:[-*+]|\d+[.)])\s+/u', $piece->markdown) === 1 ? $piece->markdown : '- '.trim($piece->markdown),
            Piece::QUOTE => str_starts_with(ltrim($piece->markdown), '>') ? $piece->markdown : implode("\n", array_map(fn (string $line) => $line === '' ? '>' : '> '.$line, explode("\n", trim($piece->markdown)))),
            Piece::FIELD => trim($piece->markdown),
            default => $piece->markdown,
        };
    }

    /**
     * Pieces as markdown: list items run on, everything else is a block of its own.
     *
     * @param  list<Piece>  $pieces
     */
    public static function joinMarkdown(array $pieces): string
    {
        $out = '';
        $previous = null;

        foreach ($pieces as $piece) {
            $text = self::markdown($piece);

            if ($text === '') {
                continue;
            }

            $out .= $previous === null ? '' : ($previous === Piece::ITEM && $piece->kind === Piece::ITEM ? "\n" : "\n\n");
            $out .= $text;
            $previous = $piece->kind;
        }

        return $out;
    }

    /**
     * @return list<Piece>|null
     */
    private function extraPieces(ExtraItem $item, ?string $part, ?ExtraKind $kind): ?array
    {
        if ($part !== null) {
            $text = $item->part($part);

            return $text === null || $text === '' ? null : [new Piece(Piece::PARAGRAPH, $text, 0, $part)];
        }

        return match ($kind) {
            ExtraKind::Faq => [new Piece(Piece::HEADING, $item->parts['question'] ?? '', 3), new Piece(Piece::PARAGRAPH, $item->text)],
            ExtraKind::PullQuote, ExtraKind::Testimonial => [new Piece(Piece::QUOTE, $item->text.(isset($item->parts['attribution']) ? "\n\n— ".$item->parts['attribution'] : ''))],
            ExtraKind::AtAGlance => [new Piece(Piece::ITEM, $item->text)],
            default => [new Piece(Piece::PARAGRAPH, $item->text)],
        };
    }
}
