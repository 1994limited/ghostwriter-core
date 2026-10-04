<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

/**
 * The `seo-editor` call's answer for one of the writer's links to choose
 * (WriterMarker, m1…): the candidate it most likely means (`e3`), or none
 * when nothing on the list fits, and why. Checked by SeoLinks, then by the
 * `seo-verifier` call; only ever a suggestion (decision 24).
 */
final class MarkerPick
{
    public function __construct(
        public readonly string $marker,
        public readonly string $target = '',
        public readonly string $why = '',
    ) {}

    /**
     * @param  array<mixed>  $item  As the reply has it.
     */
    public static function fromArray(array $item): ?self
    {
        $text = fn (string $key) => is_scalar($item[$key] ?? null) ? trim((string) $item[$key]) : '';

        return $text('marker') === '' ? null : new self($text('marker'), $text('target'), $text('why'));
    }
}
