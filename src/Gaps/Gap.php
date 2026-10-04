<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps;

/**
 * One thing an editor must finish, where it is, and how it can be
 * finished.
 *
 * Its ID is stable while the gap is there: kind, path (blocks by ID),
 * normalised hint and occurrence, so the front end can match a gap across
 * checks and a session's gap list can be matched to what is found. The
 * occurrence is which marker with this hint in this field it is (0 for the
 * first), as the front end finds a marker by its text.
 */
final class Gap
{
    /**
     * @param  list<Fix>  $fixes  The primary first.
     * @param  array<string, mixed>  $meta  Anything the front end needs: the link's words, candidates, the asset, the stock record's state, the reason from the draft.
     */
    public function __construct(
        public readonly string $id,
        public readonly GapKind $kind,
        public readonly Severity $severity,
        public readonly FieldPath $path,
        public readonly string $label,
        public readonly ?string $hint = null,
        public readonly ?string $excerpt = null,
        public readonly int $occurrence = 0,
        public readonly array $fixes = [],
        public readonly array $meta = [],
    ) {}

    /**
     * A gap with its ID made from what it is, and its kind's usual
     * severity unless given.
     *
     * @param  list<Fix>  $fixes
     * @param  array<string, mixed>  $meta
     */
    public static function make(GapKind $kind, FieldPath $path, string $label, ?string $hint = null, ?string $excerpt = null, int $occurrence = 0, array $fixes = [], array $meta = [], ?Severity $severity = null): self
    {
        return new self(self::idFor($kind, $path, $hint, $occurrence), $kind, $severity ?? $kind->severity(), $path, $label, $hint, $excerpt, $occurrence, $fixes, $meta);
    }

    /** "ask|page_builder/#a1b2/intro|adult ticket price|0" */
    public static function idFor(GapKind $kind, FieldPath|string $path, ?string $hint = null, int $occurrence = 0): string
    {
        return $kind->value.'|'.($path instanceof FieldPath ? $path->toString() : $path).'|'.Markers::normaliseHint($hint ?? '').'|'.$occurrence;
    }

    public function blocks(): bool
    {
        return $this->severity === Severity::Blocks;
    }

    /** Counted in the pill: it blocks, it prompts, or the CMS requires it. */
    public function counts(): bool
    {
        return $this->severity !== Severity::Suggestion;
    }

    /**
     * Brings the guide out on its own (a check on load, a draft applied):
     * it blocks, or it prompts (an image the page looks like it needs).
     */
    public function prompts(): bool
    {
        return $this->severity === Severity::Blocks || $this->severity === Severity::Prompt;
    }

    /**
     * What the guide says about it: `gaps.<kind>` (or a variant), with the
     * field's label, the hint and anything else it needs.
     */
    public function message(): Message
    {
        $params = ['label' => $this->label];

        if ($this->hint !== null) {
            $params['hint'] = $this->hint;
        }

        if ($this->excerpt !== null) {
            $params['excerpt'] = $this->excerpt;
        }

        foreach (['words', 'library', 'list', 'newCount', 'newValue', 'group'] as $key) {
            if (is_scalar($this->meta[$key] ?? null)) {
                $params[$key] = $this->meta[$key];
            }
        }

        $key = is_string($this->meta['message'] ?? null) ? $this->meta['message'] : 'gaps.'.$this->kind->value;

        return new Message($key, $params);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function withMeta(array $meta): self
    {
        return new self($this->id, $this->kind, $this->severity, $this->path, $this->label, $this->hint, $this->excerpt, $this->occurrence, $this->fixes, $meta + $this->meta);
    }

    /**
     * For the front end, as JSON.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'severity' => $this->severity->value,
            'path' => $this->path->toString(),
            'dotted' => $this->path->dotted(),
            'field' => $this->path->handle(),
            'label' => $this->label,
            'hint' => $this->hint,
            'excerpt' => $this->excerpt,
            'occurrence' => $this->occurrence,
            'message' => $this->message()->toArray(),
            'speech' => $this->kind->speech(),
            'fixes' => array_map(fn (Fix $fix) => $fix->toArray(), $this->fixes),
            'meta' => $this->meta,
        ];
    }
}
