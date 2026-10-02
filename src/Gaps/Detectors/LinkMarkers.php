<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detector;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Gap;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Walk;
use NineteenNinetyFour\Ghostwriter\Core\Layout\LinkDialect;

/**
 * Links still to choose:
 *
 * - inline, in rich text and markdown: a link to `#gw-link:<hint>`;
 * - in a link field: a value pointing at the sentinel, or, through 1.x,
 *   the house style's legacy `https://example.com` together with "Link to
 *   choose" (in the value or a field beside it), in a field the link
 *   dialect says holds links.
 *
 * Where the addon has LinkTargets, the hint is matched against entry
 * titles and slugs for a "Link to …" fix.
 */
final class LinkMarkers implements Detector
{
    use Deterministic;

    public function kinds(): array
    {
        return [GapKind::LinkToChoose];
    }

    public function detect(GapContext $context): iterable
    {
        foreach (Walk::entry($context->schema, $context->entry) as $visit) {
            if ($context->links->holdsLinks($visit->field)) {
                $strings = self::strings($visit->value);
                $hints = array_values(array_filter(array_map(fn (string $string) => Markers::linkHint($string), $strings), fn (?string $hint) => $hint !== null));
                $legacy = in_array(LinkDialect::PLACEHOLDER_URL, $strings, true)
                    && (in_array(LinkDialect::PLACEHOLDER_TEXT, $strings, true) || in_array(LinkDialect::PLACEHOLDER_TEXT, self::strings($visit->siblings), true));

                if ($hints === [] && $legacy) {
                    $hints = [''];
                }

                foreach ($hints as $occurrence => $hint) {
                    [$fixes, $candidates] = self::linkFixes($context, $hint !== '' ? $hint : null, false);

                    yield Gap::make(GapKind::LinkToChoose, $visit->path, $visit->label, $hint !== '' ? $hint : null, null, $occurrence, $fixes, [
                        'inline' => false,
                        'legacy' => $hint === '',
                        'candidates' => $candidates,
                        'message' => 'gaps.link-field',
                    ]);
                }

                continue;
            }

            $text = Walk::text($visit, $context->richText);

            if ($text === null || ! str_contains($text, Markers::LINK_PREFIX)) {
                continue;
            }

            foreach (Markers::links($text) as $link) {
                [$fixes, $candidates] = self::linkFixes($context, $link['hint'], true);

                yield Gap::make(GapKind::LinkToChoose, $visit->path, $visit->label, $link['hint'], Markers::excerpt($text, $link['offset'], strlen($link['match'])), $link['occurrence'], $fixes, [
                    'inline' => true,
                    'words' => $link['words'],
                    'candidates' => $candidates,
                ]);
            }
        }
    }

    /**
     * Every string in a value, at any depth.
     *
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            array_push($strings, ...self::strings($item));
        }

        return $strings;
    }
}
