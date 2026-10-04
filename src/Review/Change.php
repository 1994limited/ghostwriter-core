<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Review;

/**
 * What one revision did to one unit (or extra item): its text before and
 * after, for "Before / after" and "Put it back". `filled` lists the
 * `[[ask: …]]` markers it filled with a fact the editor gave in their
 * comment (decision 4), so the panel can label the value "from your
 * comment" rather than as Ghostwriter's.
 *
 * Each side is capped at MAX_TEXT bytes; a change cut there can't be put
 * back.
 */
final class Change
{
    public const MAX_TEXT = 4096;

    public readonly string $before;

    public readonly string $after;

    public readonly bool $cut;

    /**
     * @param  string  $unit  The unit ("u4") or extra item ("x1.2").
     * @param  int  $version  The review's version when it was made.
     * @param  list<array{ask: string, value: string, by: int|string|null}>  $filled  Asks filled from the comment: the marker's hint, what it became, whose comment.
     * @param  bool  $layout  Whether the comment's block was also laid out anew.
     */
    public function __construct(
        public readonly string $unit,
        string $before,
        string $after,
        public readonly int $version,
        public readonly array $filled = [],
        public readonly bool $layout = false,
        bool $cut = false,
    ) {
        $this->cut = $cut || strlen($before) > self::MAX_TEXT || strlen($after) > self::MAX_TEXT;
        $this->before = mb_strcut($before, 0, self::MAX_TEXT);
        $this->after = mb_strcut($after, 0, self::MAX_TEXT);
    }

    /** Whether "Put it back" can restore it: the whole text was kept. */
    public function canPutBack(): bool
    {
        return ! $this->cut;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['unit' => $this->unit, 'before' => $this->before, 'after' => $this->after, 'version' => $this->version]
            + ($this->filled !== [] ? ['filled' => $this->filled] : [])
            + ($this->layout ? ['layout' => true] : [])
            + ($this->cut ? ['cut' => true] : []);
    }

    /**
     * @param  array<mixed>  $array
     */
    public static function fromArray(array $array): self
    {
        $filled = [];

        foreach (is_array($array['filled'] ?? null) ? $array['filled'] : [] as $fill) {
            if (is_array($fill) && is_scalar($fill['ask'] ?? null) && is_scalar($fill['value'] ?? null)) {
                $by = $fill['by'] ?? null;
                $filled[] = ['ask' => (string) $fill['ask'], 'value' => (string) $fill['value'], 'by' => is_int($by) || is_string($by) ? $by : null];
            }
        }

        return new self(
            is_scalar($array['unit'] ?? null) ? (string) $array['unit'] : '',
            is_scalar($array['before'] ?? null) ? (string) $array['before'] : '',
            is_scalar($array['after'] ?? null) ? (string) $array['after'] : '',
            is_int($array['version'] ?? null) ? $array['version'] : 0,
            $filled,
            ($array['layout'] ?? false) === true,
            ($array['cut'] ?? false) === true,
        );
    }
}
