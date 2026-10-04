<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Arrange\Unit;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\DigestEntry;

/**
 * A link LinkValidator kept: where its words are in its unit's text (in
 * characters), the page it goes to (null for a `#gw-link:` marker) and
 * the href it is written with.
 */
final class PlacedLink
{
    public function __construct(
        public readonly LinkPick $pick,
        public readonly Unit $unit,
        public readonly int $offset,
        public readonly int $length,
        public readonly string $href,
        public readonly ?DigestEntry $target = null,
        public readonly string $id = '',
    ) {}

    /** The words as the unit has them. */
    public function words(): string
    {
        return mb_substr($this->unit->markdown, $this->offset, $this->length);
    }

    /** The unit's text with these words linked. */
    public function linked(): string
    {
        $text = $this->unit->markdown;

        return mb_substr($text, 0, $this->offset).'['.$this->words().']('.$this->href.')'.mb_substr($text, $this->offset + $this->length);
    }

    /** The paragraph (or list item, or line) the words are in, with them marked ⟦ ⟧ for the verifier. */
    public function paragraph(): string
    {
        $text = $this->unit->markdown;
        $start = mb_strrpos(mb_substr($text, 0, $this->offset), "\n\n");
        $start = $start === false ? 0 : $start + 2;
        $end = mb_strpos($text, "\n\n", $this->offset + $this->length);
        $end = $end === false ? mb_strlen($text) : $end;

        return trim(mb_substr($text, $start, $this->offset - $start).'⟦'.$this->words().'⟧'.mb_substr($text, $this->offset + $this->length, $end - $this->offset - $this->length));
    }

    /** The heading the words sit under in their unit, if it starts with one. */
    public function heading(): ?string
    {
        return preg_match('/^#{1,6}\s+(.+)$/m', mb_substr($this->unit->markdown, 0, $this->offset), $m) === 1 ? trim($m[1]) : null;
    }
}
