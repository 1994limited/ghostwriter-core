<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps;

/**
 * An entry a link could point at: what a link field stores for it
 * (`entry::abc`, an element ID), its title and its address, for the
 * "Link to /contact" fix.
 */
final class LinkTarget
{
    public function __construct(
        public readonly mixed $value,
        public readonly string $title,
        public readonly ?string $url = null,
    ) {}

    /**
     * @return array{value: mixed, title: string, url: string|null}
     */
    public function toArray(): array
    {
        return ['value' => $this->value, 'title' => $this->title, 'url' => $this->url];
    }
}
