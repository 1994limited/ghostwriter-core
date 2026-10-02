<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Guides;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\RoundTrips;
use NineteenNinetyFour\Ghostwriter\Core\Domain\WorkState;

/**
 * A guide screen's working state: whether a scan or refinement is running
 * (`task`), the last error, the refine conversation and the records last
 * scanned. Kept as Statamic's `voice.json` and `imagery.json`, Craft's
 * `voice` and `imagery` state, and Filament's `guide:<kind>` state rows.
 */
final class GuideState
{
    use RoundTrips;
    use WorkState;

    /**
     * @param  array<int, array<string, mixed>>  $messages  The refine conversation, `role` and `content`.
     * @param  array<int, array<string, mixed>>  $scanned  The records the last scan read: `title` and the group.
     * @param  array<int, mixed>  $pending  Kept as it is (Statamic and Craft share the shape with the plan's state).
     */
    public function __construct(
        public readonly Format $format,
        string $status = 'idle',
        ?string $error = null,
        ?string $task = null,
        public array $messages = [],
        public array $scanned = [],
        public array $pending = [],
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
            array_values(array_filter((array) ($data['messages'] ?? []), 'is_array')),
            array_values(array_filter((array) ($data['scanned'] ?? []), 'is_array')),
            array_values((array) ($data['pending'] ?? [])),
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

    public function addMessage(string $role, string $content): void
    {
        $this->messages[] = ['role' => $role, 'content' => $content];
    }

    /**
     * @return array<string, mixed>
     */
    private function encode(Format $format): array
    {
        $data = [
            'status' => $this->status,
            'error' => $this->error,
            'task' => $this->task,
            'messages' => $this->messages,
            'scanned' => $this->scanned,
        ];

        if ($format !== Format::Filament) {
            $data['pending'] = $this->pending;
        }

        return $data;
    }
}
