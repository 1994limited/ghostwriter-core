<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\Sentences;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\TextQuote;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Visit;

/**
 * One field's text as the free checks read it: the markdown (rich text
 * through the addon's dialect), and its plain form, normalised as
 * QuoteFinder normalises it with markdown syntax skipped. Checks match in
 * the plain form, so a quote taken from it is found again in the field by
 * QuoteFinder (with `markdown: true`), and by the front end's port of it.
 *
 * Blocks are the plain form's paragraphs, headings and list items, as
 * [offset, length] in characters: a quoted range stays inside one.
 */
final class CheckText
{
    public readonly string $plain;

    /** @var list<array{0: int, 1: int}> */
    public readonly array $blocks;

    /** @var list<string|null> Each block's heading text, when the block is a heading line. */
    private array $headings = [];

    private readonly NormalisedText $normalised;

    public function __construct(
        public readonly Visit $visit,
        public readonly string $markdown,
    ) {
        $this->normalised = NormalisedText::of($markdown, true);
        $this->plain = $this->normalised->text;
        $this->blocks = $this->findBlocks();
    }

    /** The text of one block. */
    public function block(int $index): string
    {
        [$offset, $length] = $this->blocks[$index];

        return mb_substr($this->plain, $offset, $length);
    }

    /**
     * Every match of a pattern in the plain text, with offsets in
     * characters, and named groups' offsets too.
     *
     * @return list<array{text: string, offset: int, groups: array<string, array{0: string, 1: int}>}>
     */
    public function matches(string $pattern): array
    {
        if (preg_match_all($pattern, $this->plain, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        $found = [];

        foreach ($matches as $match) {
            $groups = [];

            foreach ($match as $name => [$text, $byte]) {
                if (is_string($name) && $byte >= 0) {
                    $groups[$name] = [$text, $this->chars($byte)];
                }
            }

            $found[] = ['text' => $match[0][0], 'offset' => $this->chars($match[0][1]), 'groups' => $groups];
        }

        return $found;
    }

    /** A byte offset in the plain text, in characters. */
    public function chars(int $byte): int
    {
        return mb_strlen(substr($this->plain, 0, $byte));
    }

    /**
     * Where in the plain text a character of the markdown went: the first
     * plain character at or after it; null past the end.
     */
    public function plainOffset(int $markdownChar): ?int
    {
        for ($i = 0; $i < $this->normalised->size(); $i++) {
            if ($this->normalised->original($i, 1)[0] >= $markdownChar) {
                return $i;
            }
        }

        return null;
    }

    /**
     * An anchor on a range of the plain text (in characters): its quote
     * with context, which repeat of the words it is, and the passage it's
     * in. A range over TextQuote::MAX_EXACT is cut at a word.
     */
    public function anchor(int $offset, int $length, string $label = ''): Anchor
    {
        if ($length > TextQuote::MAX_EXACT) {
            $cut = mb_strrpos(mb_substr($this->plain, $offset, TextQuote::MAX_EXACT), ' ');
            $length = $cut !== false && $cut > 0 ? $cut : TextQuote::MAX_EXACT;
        }

        $quote = TextQuote::around($this->plain, $offset, $length);
        $occurrence = 0;
        $from = 0;

        while (($at = mb_strpos($this->plain, $quote->exact, $from)) !== false && $at < $offset) {
            $occurrence++;
            $from = $at + 1;
        }

        return new Anchor(
            AnchorScope::Range,
            $this->visit->path,
            $label !== '' ? $label : $this->visit->label,
            $quote,
            $occurrence,
            fieldHash: Anchor::hash($this->plain),
            passage: Anchor::hash($this->passage($offset, $length)),
        );
    }

    /**
     * The sentence or sentences covering a range, inside its block, as
     * [offset, length] in the plain text: at most TextQuote::MAX_EXACT,
     * cut at words around the range when the sentence is longer. A range
     * in no block comes back as it is.
     *
     * @return array{0: int, 1: int}
     */
    public function sentenceRange(int $offset, int $length): array
    {
        $block = $this->blockAt($offset);

        if ($block === null) {
            return [$offset, $length];
        }

        [$start] = $this->blocks[$block];
        [$at, $size] = Sentences::covering($this->block($block), $offset - $start, $length);
        $from = $start + $at;
        $end = $from + $size;
        $rangeEnd = $offset + $length;

        if ($size <= TextQuote::MAX_EXACT) {
            return [$from, $size];
        }

        // Too long: start at a word late enough for the range to fit, then cut the end at a word.
        if ($rangeEnd - $from > TextQuote::MAX_EXACT) {
            $space = mb_strpos($this->plain, ' ', $rangeEnd - TextQuote::MAX_EXACT);
            $from = $space !== false && $space < $offset ? $space + 1 : $offset;
        }

        $cutAt = min($end, $from + TextQuote::MAX_EXACT);

        if ($cutAt < $end) {
            $space = mb_strrpos(mb_substr($this->plain, $from, $cutAt - $from), ' ');
            $cutAt = $space !== false && $from + $space >= $rangeEnd ? $from + $space : max($rangeEnd, $cutAt);
        }

        return [$from, min(TextQuote::MAX_EXACT, $cutAt - $from)];
    }

    /**
     * An anchor on the sentence or sentences a range is in (sentenceRange()):
     * Out of date findings are anchored this way, so a fix rewrites the
     * whole sentence. A heading line is a sentence of its own.
     */
    public function sentenceAnchor(int $offset, int $length, string $label = ''): Anchor
    {
        [$from, $size] = $this->sentenceRange($offset, $length);

        return $this->anchor($from, $size, $label);
    }

    /**
     * The heading a character sits under: the nearest heading line before
     * its block, as plain text; null when there is none.
     */
    public function headingBefore(int $offset): ?string
    {
        $block = $this->blockAt($offset);

        for ($i = ($block ?? count($this->blocks)) - 1; $i >= 0; $i--) {
            if (($this->headings[$i] ?? null) !== null) {
                return $this->headings[$i];
            }
        }

        return null;
    }

    /** An anchor on the whole value. */
    public function fieldAnchor(): Anchor
    {
        $quote = $this->plain !== '' && mb_strlen($this->plain) <= TextQuote::MAX_EXACT ? new TextQuote($this->plain) : null;

        return new Anchor(AnchorScope::Field, $this->visit->path, $this->visit->label, $quote, 0, fieldHash: Anchor::hash($this->plain), passage: Anchor::hash($this->plain));
    }

    /** The block a character is in; null when it's in none. */
    public function blockAt(int $offset): ?int
    {
        foreach ($this->blocks as $i => [$at, $length]) {
            if ($offset >= $at && $offset < $at + $length) {
                return $i;
            }
        }

        return null;
    }

    /** The sentence or sentences a range is in, inside its block. */
    public function passage(int $offset, int $length): string
    {
        $block = $this->blockAt($offset);

        if ($block === null) {
            return mb_substr($this->plain, $offset, $length);
        }

        [$start] = $this->blocks[$block];
        $text = $this->block($block);
        [$at, $size] = Sentences::covering($text, $offset - $start, $length);

        return mb_substr($text, $at, $size);
    }

    /**
     * The markdown's lines, each found in the plain text in turn.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function findBlocks(): array
    {
        $blocks = [];
        $cursor = 0;

        foreach (preg_split('/\n/u', $this->markdown) ?: [] as $line) {
            $text = NormalisedText::string($line, true);

            if ($text === '') {
                continue;
            }

            $at = mb_strpos($this->plain, $text, $cursor);

            if ($at === false) {
                continue;
            }

            $blocks[] = [$at, mb_strlen($text)];
            $this->headings[] = preg_match('/^\s{0,3}#{1,6}\s/u', $line) === 1 ? $text : null;
            $cursor = $at + mb_strlen($text);
        }

        return $blocks;
    }
}
