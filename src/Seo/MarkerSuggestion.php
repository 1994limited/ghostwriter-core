<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Suggest\DigestEntry;

/**
 * A page suggested for one of the writer's links to choose (decision 24),
 * once checked in code: the marker, the page and the href a link to it
 * carries (InlineLinks::inlineHref()). The `seo-verifier` call sees it as
 * its marker's id (m1…) and may drop it. What is kept goes on the session
 * (SeoState::$suggested) and becomes the first fix of the marker's step in
 * Finish this page, "Link to Contact us". The draft is never changed.
 */
final class MarkerSuggestion
{
    public function __construct(
        public readonly WriterMarker $marker,
        public readonly DigestEntry $target,
        public readonly string $href,
        public readonly string $why = '',
    ) {}

    public function id(): string
    {
        return $this->marker->id;
    }

    /**
     * As the session keeps it.
     *
     * @return array{hint: string, words: string, id: string, title: string, type: string, url: ?string, href: string, why: string}
     */
    public function toArray(): array
    {
        return [
            'hint' => $this->marker->hint,
            'words' => $this->marker->words,
            'id' => (string) ($this->target->entry->id ?? ''),
            'title' => $this->target->title,
            'type' => $this->target->type,
            'url' => $this->target->url,
            'href' => $this->href,
            'why' => $this->why,
        ];
    }
}
