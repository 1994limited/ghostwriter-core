<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Arrange\MarkdownSections;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Piece;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;

/**
 * Fits one rich-text value's headings to its HeadingPolicy, with no model:
 *
 * 1. **Bold line to heading.** A one-line paragraph that is all bold, at
 *    most BOLD_WORDS words, not ending in `.`, `!` or `?` (a colon is
 *    fine), followed by a block that isn't a heading, becomes a heading at
 *    the depth of the heading before it. Only where the field takes
 *    headings. A bold lead-in ("**November: Cut back.** Prune…") is never
 *    touched.
 * 2. **Empty headings** (no letter or digit once counts show their value)
 *    are removed. A heading holding an `[[ask: …]]` is never empty: the
 *    ask is the person's to answer.
 * 3. **Levels are re-ranked:** the distinct levels the value uses, in
 *    order, onto the policy's levels from `top`.
 * 4. **Local skips are closed:** each heading is at most one deeper than
 *    the one before it, the first at `top`.
 * 5. **Deeper than allowed:** a heading with no level left becomes a bold
 *    lead-in of the paragraph after it ("**Heading.** Paragraph"), or a
 *    bold line ending in a full stop when no paragraph follows.
 * 6. **No headings allowed:** every heading becomes a lead-in, and rule 1
 *    is off.
 *
 * Words never change: only heading marks, the bold around a lead-in, and
 * a lead-in's closing full stop (a bold line's colon becomes one, so rule
 * 1 leaves it alone the next time). Fixing twice is fixing once. Code
 * blocks are left alone. Text outside the headings it changes stays
 * exactly as written, blank lines included.
 *
 * @phpstan-type Block array{kind: string, level: int, text: string, start: int, end: int, out: list<string>|null, prefix: string}
 */
final class HeadingFixer
{
    /** The most words a bold line may have to be taken for a heading. */
    public const BOLD_WORDS = 12;

    public function fix(string $markdown, HeadingPolicy $policy): FixedHeadings
    {
        if (trim($markdown) === '' || (! str_contains($markdown, '#') && ! str_contains($markdown, '**') && ! str_contains($markdown, '__'))) {
            return new FixedHeadings($markdown);
        }

        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
        $found = MarkdownSections::blocks($lines);

        if ($found === []) {
            return new FixedHeadings($markdown);
        }

        $levels = $policy->levels();
        $changes = [];

        /** @var list<Block> $blocks */
        $blocks = [];

        foreach ($found as $block) {
            $out = array_slice($lines, $block['start'], $block['end'] - $block['start']);
            $blocks[] = [
                'kind' => $block['kind'] === Piece::HEADING ? 'heading' : ($block['kind'] === Piece::PARAGRAPH ? 'paragraph' : 'other'),
                'level' => $block['level'],
                'text' => $block['kind'] === Piece::HEADING ? self::headingText($lines[$block['start']]) : '',
                'start' => $block['start'],
                'end' => $block['end'],
                'out' => $out,
                'prefix' => '',
            ];
        }

        // 2. Empty headings.
        foreach ($blocks as $i => $block) {
            if ($block['kind'] === 'heading' && self::isEmpty($block['text'])) {
                $changes[] = new HeadingChange(HeadingChange::REMOVED, $block['level'], null, $block['text']);
                $blocks[$i]['out'] = null;
            }
        }

        // 1. Bold lines posing as headings.
        if ($levels !== []) {
            $used = array_map(fn (array $block) => $block['level'], array_filter($blocks, fn (array $block) => $block['kind'] === 'heading' && $block['out'] !== null));
            $depth = null;

            foreach ($blocks as $i => $block) {
                if ($block['out'] === null) {
                    continue;
                }

                if ($block['kind'] === 'heading') {
                    $depth = $block['level'];

                    continue;
                }

                $text = $block['kind'] === 'paragraph' ? self::boldLine($block['out']) : null;
                $next = self::next($blocks, $i);

                if ($text === null || $next === null || $blocks[$next]['kind'] === 'heading') {
                    continue;
                }

                $level = $depth ?? ($used === [] ? $policy->top : min($used));
                $blocks[$i]['kind'] = 'heading';
                $blocks[$i]['level'] = $level;
                $blocks[$i]['text'] = $text;
                $blocks[$i]['out'] = [str_repeat('#', $level).' '.$text];
                $changes[] = new HeadingChange(HeadingChange::FROM_BOLD, null, $level, $text);
                $depth = $level;
            }
        }

        // 3. Re-rank the levels the value uses, 4. close local skips, 5–6. make lead-ins of what doesn't fit.
        $distinct = array_values(array_unique(array_map(fn (array $block) => $block['level'], array_filter($blocks, fn (array $block) => $block['kind'] === 'heading' && $block['out'] !== null))));
        sort($distinct);
        $rankOf = array_flip($distinct);
        $previous = -1;

        foreach ($blocks as $i => $block) {
            if ($block['kind'] !== 'heading' || $block['out'] === null) {
                continue;
            }

            $rank = min($rankOf[$block['level']], $previous + 1);
            $previous = $rank;

            if (isset($levels[$rank])) {
                $level = $levels[$rank];

                if ($level !== $block['level']) {
                    $blocks[$i]['out'][0] = (string) preg_replace('/^(\s{0,3})#{1,6}/', '${1}'.str_repeat('#', $level), $block['out'][0], 1);
                    $changes[] = new HeadingChange(HeadingChange::LEVEL, $block['level'], $level, $block['text']);
                    $blocks[$i]['level'] = $level;
                }

                continue;
            }

            /** @var list<Block> $blocks */
            $next = self::next($blocks, $i);
            $lead = trim((string) preg_replace('/\*\*|__/', '', $block['text']));
            $changes[] = new HeadingChange(HeadingChange::TO_LEAD_IN, $block['level'], null, $block['text']);

            if ($next !== null && $blocks[$next]['kind'] === 'paragraph') {
                $stop = preg_match('/[.:!?…]$/u', $lead) === 1 ? '' : '.';
                $blocks[$next]['prefix'] = '**'.$lead.$stop.'** '.$blocks[$next]['prefix'];
                $blocks[$i]['out'] = null;

                continue;
            }

            $lead = rtrim($lead, ': ');
            $stop = preg_match('/[.!?…]$/u', $lead) === 1 ? '' : '.';
            $blocks[$i]['kind'] = 'paragraph';
            $blocks[$i]['out'] = ['**'.$lead.$stop.'**'];
        }

        if ($changes === []) {
            return new FixedHeadings($markdown);
        }

        /** @var list<Block> $blocks */
        return new FixedHeadings(self::assemble($lines, $blocks), $changes);
    }

    /**
     * The value back together: each block's lines (changed or not), and
     * before each the lines that were just before it (blank lines, or none
     * between a tight list's items). A removed block's own lines go.
     *
     * @param  list<string>  $lines
     * @param  list<Block>  $blocks
     */
    private static function assemble(array $lines, array $blocks): string
    {
        $out = array_slice($lines, 0, $blocks[0]['start']);
        $first = true;

        foreach ($blocks as $i => $block) {
            if ($block['out'] === null) {
                continue;
            }

            if (! $first) {
                array_push($out, ...array_slice($lines, $blocks[$i - 1]['end'], $block['start'] - $blocks[$i - 1]['end']));
            }

            $first = false;
            $body = $block['out'];

            if ($block['prefix'] !== '' && $body !== []) {
                $indent = strlen($body[0]) - strlen(ltrim($body[0]));
                $body[0] = substr($body[0], 0, $indent).$block['prefix'].substr($body[0], $indent);
            }

            array_push($out, ...$body);
        }

        array_push($out, ...array_slice($lines, $blocks[count($blocks) - 1]['end']));

        return implode("\n", $out);
    }

    /**
     * @param  list<Block>  $blocks
     */
    private static function next(array $blocks, int $i): ?int
    {
        for ($j = $i + 1; $j < count($blocks); $j++) {
            if ($blocks[$j]['out'] !== null) {
                return $j;
            }
        }

        return null;
    }

    /** A heading line's words: without its marks or closing `#`s. */
    private static function headingText(string $line): string
    {
        $text = (string) preg_replace('/^\s{0,3}#{1,6}(?:\s+|$)/u', '', $line);

        return trim((string) preg_replace('/(?:^|\s+)#+\s*$/u', '', $text));
    }

    /** No letter or digit once counts show their value; an ask always counts. */
    private static function isEmpty(string $text): bool
    {
        if (preg_match(Markers::ASK_PATTERN, $text) === 1) {
            return false;
        }

        return preg_match('/[\p{L}\p{N}]/u', Markers::withoutChecks($text)) !== 1;
    }

    /**
     * A one-line paragraph that is all bold and reads as a heading: its
     * words (without a closing colon); null for anything else.
     *
     * @param  list<string>  $lines
     */
    private static function boldLine(array $lines): ?string
    {
        if (count($lines) !== 1 || preg_match('/^\s*(\*\*|__)(?!\s)(.+?)(?<!\s)\1\s*$/u', $lines[0], $m) !== 1) {
            return null;
        }

        $text = $m[2];

        if (str_contains($text, $m[1]) || preg_match('/[.!?…]$/u', $text) === 1 || preg_match('/[\p{L}\p{N}]/u', $text) !== 1) {
            return null;
        }

        $words = preg_split('/\s+/u', trim(Markers::withoutChecks($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return count($words) > self::BOLD_WORDS ? null : rtrim($text, ': ');
    }
}
