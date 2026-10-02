<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Studio;

/**
 * What the writer's instructions are filled from.
 */
final class WriterContext
{
    /**
     * @param  string  $voice  The voice guide; empty when none is written.
     * @param  string  $images  What the writer is told about images: each addon has its own (see docs/studio.md).
     */
    public function __construct(
        public readonly ContentKind $kind,
        public readonly string $voice,
        public readonly Layout $layout,
        public readonly string $images,
    ) {}
}
