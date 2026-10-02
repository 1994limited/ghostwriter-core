<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain;

/**
 * Keeps a stored record's own shape. A value object read with
 * `fromArray()` remembers the array it came from; `toArray()` then gives
 * back the stored value of every key it has not changed (its exact type,
 * JSON spelling and date format), the new value of any it has, and any
 * keys it doesn't know about where they were. So reading a record and
 * saving it untouched writes back exactly what was there.
 *
 * @internal
 */
trait RoundTrips
{
    /** @var array<string, mixed> The record as it was read. */
    private array $stored = [];

    /** @var array<string, mixed> What toArray() would have written just after reading it. */
    private array $asRead = [];

    private ?Format $readAs = null;

    /**
     * @param  array<string, mixed>  $stored
     */
    private function remember(array $stored, Format $format): static
    {
        $this->stored = $stored;
        $this->readAs = $format;
        $this->asRead = $this->encode($format);

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    abstract private function encode(Format $format): array;

    /**
     * @return array<string, mixed>
     */
    private function emit(Format $format): array
    {
        $now = $this->encode($format);

        if ($this->readAs !== $format) {
            return $now;
        }

        $out = [];

        foreach ($this->stored as $key => $value) {
            if (! array_key_exists($key, $now)) {
                // Not ours: kept as it was.
                $out[$key] = $value;
            } elseif (array_key_exists($key, $this->asRead) && $now[$key] === $this->asRead[$key]) {
                $out[$key] = $value;
            } else {
                $out[$key] = $now[$key];
            }
        }

        foreach ($now as $key => $value) {
            // A key the record didn't have is written only once it means something.
            if (! array_key_exists($key, $this->stored) && (! array_key_exists($key, $this->asRead) || $this->asRead[$key] !== $value)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
