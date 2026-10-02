<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Studio;

/**
 * One piece of published writing the voice analyst reads.
 */
final class VoiceSample
{
    /**
     * @param  string  $group  Where it was published: the collection, section or resource, as the addon names it.
     * @param  string  $text  Its prose.
     */
    public function __construct(
        public readonly string $title,
        public readonly string $group,
        public readonly string $text,
    ) {}
}
