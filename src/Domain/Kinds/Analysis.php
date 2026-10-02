<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds;

use NineteenNinetyFour\Ghostwriter\Core\Domain\WorkState;

/**
 * Whether one group is being studied to learn a kind of content right
 * now, and the last error (Statamic's `types.json` and Craft's `types`
 * state, an entry per group: `status`, `error`).
 */
final class Analysis
{
    use WorkState;

    public function __construct(string $status = 'idle', ?string $error = null)
    {
        $this->status = $status;
        $this->error = $error;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(is_string($data['status'] ?? null) ? $data['status'] : 'idle', is_scalar($data['error'] ?? null) ? (string) $data['error'] : null);
    }

    /**
     * @return array{status: string, error: ?string}
     */
    public function toArray(): array
    {
        return ['status' => $this->status, 'error' => $this->error];
    }
}
