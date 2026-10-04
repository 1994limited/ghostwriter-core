<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

/**
 * One link the `seo-editor` call proposes (SEO layer §7.3): words copied
 * from a unit of the draft (`exact`, with `prefix` where they appear
 * twice), and the candidate they should link to (`e3`), or, with no
 * target, a hint for a `#gw-link:` marker where the page clearly needs a
 * link to something the site doesn't have yet. LinkValidator decides
 * whether it is made.
 */
final class LinkPick
{
    public function __construct(
        public readonly string $unit,
        public readonly string $exact,
        public readonly string $target = '',
        public readonly string $prefix = '',
        public readonly string $hint = '',
        public readonly string $why = '',
    ) {}

    public function isMarker(): bool
    {
        return $this->target === '';
    }

    /**
     * @param  array<mixed>  $item  As the reply has it.
     */
    public static function fromArray(array $item): ?self
    {
        $text = fn (string $key) => is_scalar($item[$key] ?? null) ? trim((string) $item[$key]) : '';

        if ($text('unit') === '' || $text('exact') === '') {
            return null;
        }

        return new self($text('unit'), $text('exact'), $text('target'), is_scalar($item['prefix'] ?? null) ? (string) $item['prefix'] : '', $text('hint'), $text('why'));
    }

    /**
     * @return array{unit: string, exact: string, prefix: string, target: string, hint: string, why: string}
     */
    public function toArray(): array
    {
        return ['unit' => $this->unit, 'exact' => $this->exact, 'prefix' => $this->prefix, 'target' => $this->target, 'hint' => $this->hint, 'why' => $this->why];
    }
}
