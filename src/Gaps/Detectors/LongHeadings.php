<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detector;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Fix;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FixAction;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FixCost;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Gap;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Visit;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Walk;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;

/**
 * "Shorten a heading" (SEO layer §12, `heading-long`): a heading in rich
 * text longer than LIMIT characters, which is hard to scan and gets cut
 * off in search results. One step a heading: **Write it for me** (one
 * `gap-filler` call, task `shorten-heading`: the same heading, shorter,
 * adding nothing) · **I'll write it**.
 *
 * A suggestion: never counted, never blocking (SEO never blocks
 * publishing). A heading field of its own (a block's heading) isn't
 * looked at: its length is the template's business.
 */
final class LongHeadings implements Detector
{
    use Deterministic;

    /** The longest heading that isn't a step, in characters (every language: §17). */
    public const LIMIT = 70;

    /** What "Write it for me" aims under. */
    public const TARGET = 60;

    private const HEADING = '/^(#{1,6})[ \t]+(.+?)[ \t]*#*[ \t]*$/mu';

    public function kinds(): array
    {
        return [GapKind::HeadingLong];
    }

    public function detect(GapContext $context): iterable
    {
        foreach (Walk::entry($context->schema, $context->entry) as $visit) {
            foreach (self::in($visit, $context) as $gap) {
                yield $gap;
            }
        }
    }

    /**
     * The headings over the limit in one field's text, as their words read
     * (no markdown).
     *
     * @return list<array{text: string, level: int, occurrence: int}>
     */
    public static function long(string $markdown): array
    {
        if (! str_contains($markdown, '#') || preg_match_all(self::HEADING, $markdown, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $found = [];
        $seen = [];

        foreach ($matches as $match) {
            $text = self::plain($match[2]);
            $key = mb_strtolower($text);
            $occurrence = $seen[$key] = isset($seen[$key]) ? $seen[$key] + 1 : 0;

            if (mb_strlen($text) > self::LIMIT) {
                $found[] = ['text' => $text, 'level' => strlen($match[1]), 'occurrence' => $occurrence];
            }
        }

        return $found;
    }

    /** A heading's words without inline markdown: links, emphasis, code. */
    public static function plain(string $heading): string
    {
        $text = (string) preg_replace('/!?\[([^\]]*)\]\([^)]*\)/u', '$1', $heading);
        $text = (string) preg_replace('/(\*\*|__|\*|_|`)(.+?)\1/u', '$2', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @return iterable<Gap>
     */
    private static function in(Visit $visit, GapContext $context): iterable
    {
        if (! in_array($visit->field->kind, [Kind::RichText, Kind::LongText], true)) {
            return;
        }

        $text = Walk::text($visit, $context->richText);

        if ($text === null) {
            return;
        }

        foreach (self::long($text) as $heading) {
            yield Gap::make(GapKind::HeadingLong, $visit->path, $visit->label, $heading['text'], $heading['text'], $heading['occurrence'], [
                Fix::of(FixAction::WriteForMe, true, cost: FixCost::Model),
                Fix::of(FixAction::Focus),
            ], [
                'length' => mb_strlen($heading['text']),
                'limit' => self::LIMIT,
                'target' => self::TARGET,
                'level' => $heading['level'],
                'words' => $heading['text'],
                'match' => $heading['text'],
                'inline' => true,
                'task' => 'shorten-heading',
                'step' => 'gaps.step.heading-long',
            ]);
        }
    }
}
