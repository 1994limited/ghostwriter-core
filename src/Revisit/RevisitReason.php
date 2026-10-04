<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Revisit;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;

/**
 * One reason an entry is on the list, as a chip: its kind, how many there
 * are, the quote it's about (a past year, a count), and anything else the
 * chip says (the months, the year).
 */
final class RevisitReason
{
    /**
     * @param  array<string, scalar|null>  $meta
     */
    public function __construct(
        public readonly ReasonKind $kind,
        public readonly int $count = 1,
        public readonly ?string $quote = null,
        public readonly array $meta = [],
    ) {}

    /** The chip's words: '“New for 2024”', '1 broken link', 'no alt text ×3', '2 years old'. */
    public function message(): Message
    {
        $count = $this->count;

        return match ($this->kind) {
            ReasonKind::Age => ($months = (int) ($this->meta['months'] ?? 0)) >= 24
                ? new Message('revisit.reason.age-years', ['years' => intdiv($months, 12)])
                : new Message('revisit.reason.age-months', ['months' => $months]),
            ReasonKind::PastYear, ReasonKind::StatedCount => new Message('revisit.reason.'.$this->kind->value, ['quote' => $this->quote]),
            ReasonKind::RelativeTime => new Message('revisit.reason.relative-time', ['quote' => $this->quote, 'year' => $this->meta['year'] ?? null]),
            ReasonKind::BrokenLink => new Message($count === 1 ? 'revisit.reason.broken-link-one' : 'revisit.reason.broken-link-many', ['count' => $count]),
            ReasonKind::ExternalLink => new Message($count === 1 ? 'revisit.reason.external-link-one' : 'revisit.reason.external-link-many', ['count' => $count]),
            ReasonKind::MissingAlt => new Message($count === 1 ? 'revisit.reason.missing-alt' : 'revisit.reason.missing-alt-many', ['count' => $count]),
            ReasonKind::EmptyField => new Message($count === 1 ? 'revisit.reason.empty-field-one' : 'revisit.reason.empty-field', ['count' => $count]),
            default => new Message('revisit.reason.'.$this->kind->value, ['count' => $count]),
        };
    }

    /** The chip's colour and word: 'high', 'medium' or 'low'. */
    public function severity(): string
    {
        return match ($this->kind) {
            ReasonKind::Leftover, ReasonKind::ClosingDate, ReasonKind::BrokenLink, ReasonKind::ExternalLink, ReasonKind::PastYear => 'high',
            ReasonKind::RelativeTime, ReasonKind::StatedCount, ReasonKind::EmptyField => 'medium',
            ReasonKind::Age => (int) ($this->meta['months'] ?? 0) >= 24 ? 'medium' : 'low',
            default => 'low',
        };
    }

    /**
     * @return array{kind: string, count: int, quote: ?string, meta: array<string, scalar|null>, severity: string, message: array{key: string, params: array<string, scalar|null>}}
     */
    public function toArray(): array
    {
        return ['kind' => $this->kind->value, 'count' => $this->count, 'quote' => $this->quote, 'meta' => $this->meta, 'severity' => $this->severity(), 'message' => $this->message()->toArray()];
    }

    /**
     * @param  array<mixed>  $array
     */
    public static function fromArray(array $array): ?self
    {
        $kind = ReasonKind::tryFrom(is_string($array['kind'] ?? null) ? $array['kind'] : '');

        if ($kind === null) {
            return null;
        }

        $meta = [];

        foreach (is_array($array['meta'] ?? null) ? $array['meta'] : [] as $key => $value) {
            if (is_string($key) && (is_scalar($value) || $value === null)) {
                $meta[$key] = $value;
            }
        }

        return new self($kind, is_int($array['count'] ?? null) ? $array['count'] : 1, is_string($array['quote'] ?? null) ? $array['quote'] : null, $meta);
    }
}
