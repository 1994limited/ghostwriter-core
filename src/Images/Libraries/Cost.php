<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images\Libraries;

use InvalidArgumentException;

/**
 * What a licence costs: money (an amount in minor units and a currency),
 * or units of the account's allowance (downloads, credits). Never a float.
 * `exact` is false for a hint or an estimate, true for what the provider
 * says it will charge or did.
 *
 *     Cost::money(900, 'GBP');            // £9.00
 *     Cost::units(1, Cost::DOWNLOAD);     // "1 download"
 *     Cost::units(3, Cost::CREDIT);       // "3 credits"
 */
final class Cost
{
    public const DOWNLOAD = 'download';

    public const CREDIT = 'credit';

    public const LICENCE = 'licence';

    private function __construct(
        public readonly ?int $amount,
        public readonly ?string $currency,
        public readonly ?int $units,
        public readonly ?string $unit,
        public readonly bool $exact,
    ) {}

    public static function money(int $amount, string $currency, bool $exact = true): self
    {
        $currency = strtoupper(trim($currency));

        if ($amount < 0 || ! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('A cost is a non-negative amount in minor units and a three-letter currency.');
        }

        return new self($amount, $currency, null, null, $exact);
    }

    public static function units(int $units, string $unit, bool $exact = true): self
    {
        if ($units < 0 || ! in_array($unit, [self::DOWNLOAD, self::CREDIT, self::LICENCE], true)) {
            throw new InvalidArgumentException('A cost in units is a non-negative count of downloads, credits or licences.');
        }

        return new self(null, null, $units, $unit, $exact);
    }

    public function isMoney(): bool
    {
        return $this->amount !== null;
    }

    public function estimated(): self
    {
        return new self($this->amount, $this->currency, $this->units, $this->unit, false);
    }

    /**
     * In words for a card or a confirm step: "GBP 9.00", "1 download",
     * "3 credits"; "about" before an estimate.
     */
    public function label(): string
    {
        $text = $this->isMoney()
            ? $this->currency.' '.number_format((int) $this->amount / 100, 2, '.', ',')
            : $this->units.' '.$this->unit.($this->units === 1 ? '' : 's');

        return ($this->exact ? '' : 'about ').$text;
    }

    /**
     * @return array{amount?: int, currency?: string, units?: int, unit?: string, exact: bool}
     */
    public function toArray(): array
    {
        return ($this->isMoney()
            ? ['amount' => (int) $this->amount, 'currency' => (string) $this->currency]
            : ['units' => (int) $this->units, 'unit' => (string) $this->unit]) + ['exact' => $this->exact];
    }

    /**
     * Back from toArray(). Null when it doesn't hold a cost.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $exact = (bool) ($data['exact'] ?? true);

        try {
            if (is_numeric($data['amount'] ?? null) && is_string($data['currency'] ?? null)) {
                return self::money((int) $data['amount'], $data['currency'], $exact);
            }

            if (is_numeric($data['units'] ?? null) && is_string($data['unit'] ?? null)) {
                return self::units((int) $data['units'], $data['unit'], $exact);
            }
        } catch (InvalidArgumentException) {
            return null;
        }

        return null;
    }
}
