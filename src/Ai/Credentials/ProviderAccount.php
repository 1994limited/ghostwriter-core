<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Credentials;

/**
 * What a provider says about the key in use, for "Check connection".
 * OpenRouter counts in credits, which are US dollars.
 */
final class ProviderAccount
{
    /**
     * @param  string  $source  Where the key came from: 'env' or 'connected'.
     * @param  string  $label  The provider's name for the key (masked by the provider).
     * @param  float|null  $limit  The spending limit set on the key, or null for none.
     * @param  float|null  $remaining  What is left of that limit, or null for no limit.
     * @param  float  $usage  Credit used with the key so far.
     * @param  string|null  $limitReset  How often the limit starts again (daily, weekly, monthly), or null.
     * @param  bool  $freeTier  Whether the account has never bought credit.
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $source,
        public readonly string $label = '',
        public readonly ?float $limit = null,
        public readonly ?float $remaining = null,
        public readonly float $usage = 0.0,
        public readonly ?string $limitReset = null,
        public readonly bool $freeTier = false,
    ) {}

    /**
     * One line for the settings row: "Connected. $74.50 of $100.00 left
     * (resets monthly)." or "Connected. $25.50 used; this key has no
     * spending limit."
     */
    public function summary(): string
    {
        $from = $this->source === 'env' ? 'Using the key from the .env file.' : 'Connected.';

        if ($this->limit === null || $this->remaining === null) {
            $line = sprintf('%s $%s used; this key has no spending limit.', $from, number_format($this->usage, 2));
        } else {
            $line = sprintf('%s $%s of $%s left%s.', $from, number_format(max(0.0, $this->remaining), 2), number_format($this->limit, 2), $this->limitReset ? " (resets {$this->limitReset})" : '');
        }

        return $this->freeTier ? $line.' The account has no credit yet, so only free models will answer.' : $line;
    }

    /** Whether the key's own limit is used up. Account credit is not shown here; a call says so (OutOfCredit). */
    public function exhausted(): bool
    {
        return $this->remaining !== null && $this->remaining <= 0.0;
    }

    /**
     * @return array{provider: string, source: string, label: string, limit: ?float, remaining: ?float, usage: float, limitReset: ?string, freeTier: bool, summary: string}
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'source' => $this->source,
            'label' => $this->label,
            'limit' => $this->limit,
            'remaining' => $this->remaining,
            'usage' => $this->usage,
            'limitReset' => $this->limitReset,
            'freeTier' => $this->freeTier,
            'summary' => $this->summary(),
        ];
    }
}
