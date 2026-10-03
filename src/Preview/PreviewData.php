<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Preview;

/**
 * What a preview render needs: the entry's data as apply would set it,
 * with markers added (PreviewMarkers::mark()), and the map the locator
 * reads the rendered page with.
 *
 * Never save `data`: it is for the preview render only.
 */
final class PreviewData
{
    /**
     * @param  array<string, mixed>  $data  Marked.
     * @param  string  $hash  Of the data without markers: the render's cache key.
     * @param  string|null  $planId  The layout it shows, once there are layouts.
     */
    public function __construct(
        public readonly array $data,
        public readonly BlockMap $map,
        public readonly string $hash,
        public readonly ?string $planId = null,
        public readonly int $draftVersion = 0,
    ) {}

    public function withPlan(?string $planId, int $draftVersion): self
    {
        return new self($this->data, $this->map, $this->hash, $planId, $draftVersion);
    }
}
