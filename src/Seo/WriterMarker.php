<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Arrange\Unit;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;

/**
 * A link the writer left for the editor to choose, `[book a winter
 * visit](#gw-link:booking-page)`, as the `seo-editor` call is shown it
 * (m1, m2…), so it can suggest the page it most likely means (decision
 * 24). The marker stays a marker: the suggestion is only offered first in
 * Finish this page.
 */
final class WriterMarker
{
    /** Markers shown to the model, at most. */
    public const MOST = 8;

    /**
     * @param  int  $offset  Where the marker starts in its unit's text, in characters.
     * @param  int  $length  The marker's length as written, in characters.
     */
    public function __construct(
        public readonly string $id,
        public readonly Unit $unit,
        public readonly string $words,
        public readonly string $hint,
        public readonly int $offset = 0,
        public readonly int $length = 0,
    ) {}

    /**
     * Every link still to choose in some units, in reading order, numbered
     * m1…, at most MOST.
     *
     * @param  iterable<Unit>  $units
     * @return list<self>
     */
    public static function in(iterable $units): array
    {
        $found = [];

        foreach ($units as $unit) {
            if (! str_contains($unit->markdown, Markers::LINK_PREFIX)) {
                continue;
            }

            foreach (Markers::links($unit->markdown) as $link) {
                if (trim($link['words']) === '' || count($found) >= self::MOST) {
                    continue;
                }

                $offset = mb_strlen(substr($unit->markdown, 0, $link['offset']));
                $found[] = new self('m'.(count($found) + 1), $unit, trim($link['words']), $link['hint'], $offset, mb_strlen($link['match']));
            }
        }

        return $found;
    }

    /**
     * The paragraph (or list item, or line) the marker is in, with its
     * words marked ⟦ ⟧ in place of the marker, for the verifier.
     */
    public function paragraph(): string
    {
        $text = $this->unit->markdown;
        $start = mb_strrpos(mb_substr($text, 0, $this->offset), "\n\n");
        $start = $start === false ? 0 : $start + 2;
        $end = mb_strpos($text, "\n\n", $this->offset + $this->length);
        $end = $end === false ? mb_strlen($text) : $end;

        return trim(mb_substr($text, $start, $this->offset - $start).'⟦'.$this->words.'⟧'.mb_substr($text, $this->offset + $this->length, $end - $this->offset - $this->length));
    }

    /** The marker's hint as words: "booking page". */
    public function hintWords(): string
    {
        return trim((string) preg_replace('/[-_]+/', ' ', $this->hint));
    }
}
