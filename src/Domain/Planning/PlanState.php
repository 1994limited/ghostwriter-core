<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Planning;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\RoundTrips;
use NineteenNinetyFour\Ghostwriter\Core\Domain\WorkState;

/**
 * The content plan screen's working state: whether ideas are being looked
 * for, the last error, and the suggestions waiting to be reviewed
 * (`pending`, each an array in the addon's suggestion keys).
 *
 * Suggestions stay pending until someone keeps or drops them (E3): closing
 * the review keeps the batch, and a card offers it again.
 *
 * Kept as Statamic's `plan.json`, Craft's `plan` state, Filament's `plan`
 * state row value.
 */
final class PlanState
{
    use RoundTrips;
    use WorkState;

    /**
     * @param  array<int, array<string, mixed>>  $pending
     * @param  array<int, mixed>  $messages  Kept as it is (Statamic and Craft share the guide screens' shape).
     * @param  array<int, mixed>  $scanned  Kept as it is.
     */
    public function __construct(
        public readonly Format $format,
        string $status = 'idle',
        ?string $error = null,
        ?string $task = null,
        public array $pending = [],
        public array $messages = [],
        public array $scanned = [],
    ) {
        $this->status = $status;
        $this->error = $error;
        $this->task = $task;
    }

    public static function empty(Format $format): self
    {
        return new self($format);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, Format $format): self
    {
        $state = new self(
            $format,
            is_string($data['status'] ?? null) ? $data['status'] : 'idle',
            is_scalar($data['error'] ?? null) ? (string) $data['error'] : null,
            is_scalar($data['task'] ?? null) ? (string) $data['task'] : null,
            array_values(array_filter((array) ($data['pending'] ?? []), 'is_array')),
            array_values((array) ($data['messages'] ?? [])),
            array_values((array) ($data['scanned'] ?? [])),
        );

        return $state->remember($data, $format);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(?Format $format = null): array
    {
        return $this->emit($format ?? $this->format);
    }

    public function hasPending(): bool
    {
        return $this->pending !== [];
    }

    /**
     * @return array<string, mixed>
     */
    private function encode(Format $format): array
    {
        if ($format === Format::Filament) {
            return ['status' => $this->status, 'error' => $this->error, 'pending' => $this->pending];
        }

        return [
            'status' => $this->status,
            'error' => $this->error,
            'task' => $this->task,
            'messages' => $this->messages,
            'scanned' => $this->scanned,
            'pending' => $this->pending,
        ];
    }
}
