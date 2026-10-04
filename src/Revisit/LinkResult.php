<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Revisit;

use DateTimeImmutable;

/**
 * The last check of one link to another site: its status, the HTTP code
 * when there was one, and when it was checked. A link is shown as broken
 * only once it has been Broken twice in a row (`failures` ≥ 2), so a site
 * that's down for an afternoon isn't reported.
 */
final class LinkResult
{
    /** Checks in a row a link must fail before it's shown. */
    public const FAILURES = 2;

    public function __construct(
        public readonly string $url,
        public readonly LinkStatus $status,
        public readonly ?int $code = null,
        public readonly ?string $checkedAt = null,
        public readonly int $failures = 0,
        public readonly ?string $error = null,
    ) {}

    public function isBroken(): bool
    {
        return $this->status === LinkStatus::Broken && $this->failures >= self::FAILURES;
    }

    /** This check after the one before: Broken counts up, Ok resets, Unknown keeps the count. */
    public function after(?self $previous): self
    {
        $failures = match ($this->status) {
            LinkStatus::Broken => ($previous->failures ?? 0) + 1,
            LinkStatus::Ok => 0,
            LinkStatus::Unknown => $previous->failures ?? 0,
        };

        $status = $this->status === LinkStatus::Unknown && $previous !== null ? $previous->status : $this->status;

        return new self($this->url, $status, $this->code ?? $previous?->code, $this->checkedAt, $failures, $this->error);
    }

    /** Whether it's due another check: never checked, or checked over $days days ago. */
    public function due(DateTimeImmutable $now, int $days): bool
    {
        return $this->checkedAt === null || new DateTimeImmutable($this->checkedAt) <= $now->modify("-{$days} days");
    }

    /**
     * @return array{url: string, status: string, code: ?int, checkedAt: ?string, failures: int, error: ?string}
     */
    public function toArray(): array
    {
        return ['url' => $this->url, 'status' => $this->status->value, 'code' => $this->code, 'checkedAt' => $this->checkedAt, 'failures' => $this->failures, 'error' => $this->error];
    }

    /**
     * @param  array<mixed>  $array
     */
    public static function fromArray(array $array): self
    {
        return new self(
            is_string($array['url'] ?? null) ? $array['url'] : '',
            LinkStatus::tryFrom(is_string($array['status'] ?? null) ? $array['status'] : '') ?? LinkStatus::Unknown,
            is_int($array['code'] ?? null) ? $array['code'] : null,
            is_string($array['checkedAt'] ?? null) ? $array['checkedAt'] : null,
            is_int($array['failures'] ?? null) ? $array['failures'] : 0,
            is_string($array['error'] ?? null) ? $array['error'] : null,
        );
    }
}
