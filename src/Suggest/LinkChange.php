<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

/**
 * A link to point at another entry: what the link stores (`entry::abc`,
 * `{entry:12@1:url}`), the entry's title and address, and whether it came
 * free (LinkTargets::search()) or from the model (an entry it was shown).
 */
final class LinkChange
{
    public function __construct(
        public readonly mixed $target,
        public readonly string $title,
        public readonly ?string $url = null,
        public readonly bool $free = true,
    ) {}

    /**
     * @return array{target: mixed, title: string, url: ?string, free: bool}
     */
    public function toArray(): array
    {
        return ['target' => $this->target, 'title' => $this->title, 'url' => $this->url, 'free' => $this->free];
    }

    /**
     * @param  array<mixed>  $array
     */
    public static function fromArray(array $array): self
    {
        return new self($array['target'] ?? null, is_string($array['title'] ?? null) ? $array['title'] : '', is_string($array['url'] ?? null) ? $array['url'] : null, ($array['free'] ?? true) !== false);
    }
}
