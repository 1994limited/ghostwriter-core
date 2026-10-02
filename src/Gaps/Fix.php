<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps;

/**
 * One way to finish a gap, as the guide offers it: the primary first, then
 * the alternatives. The label is a message key, translated by the addon.
 */
final class Fix
{
    public function __construct(
        public readonly FixAction $action,
        public readonly Message $label,
        public readonly mixed $value = null,
        public readonly FixCost $cost = FixCost::Free,
        public readonly bool $primary = false,
    ) {}

    /**
     * A fix with its usual label (`gaps.fix.<action>`).
     *
     * @param  array<string, scalar|null>  $params
     */
    public static function of(FixAction $action, bool $primary = false, mixed $value = null, FixCost $cost = FixCost::Free, array $params = []): self
    {
        return new self($action, new Message('gaps.fix.'.$action->value, $params), $value, $cost, $primary);
    }

    /**
     * @return array{action: string, label: array{key: string, params: array<string, scalar|null>}, value: mixed, cost: string, primary: bool}
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action->value,
            'label' => $this->label->toArray(),
            'value' => $this->value,
            'cost' => $this->cost->value,
            'primary' => $this->primary,
        ];
    }
}
