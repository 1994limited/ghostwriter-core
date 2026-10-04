<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

/**
 * Another entry of the site, as the review call is shown it: its title,
 * address and a short summary, and what a link to it stores (`entry::abc`,
 * `{entry:12@1:url}`), so a suggested link can be written. A link
 * candidate from LinkTargets has no EntryRef. Its type is the group's
 * label ("Pages", "Journal"), so the model sees "Contact us (Pages)"; empty
 * where the index doesn't know it.
 */
final class DigestEntry
{
    public const SUMMARY = 160;

    public function __construct(
        public readonly ?EntryRef $entry,
        public readonly string $title,
        public readonly ?string $url = null,
        public readonly string $summary = '',
        public readonly mixed $link = null,
        public readonly string $type = '',
    ) {}

    /**
     * @return array{entry: array{group: string, id: int|string, site: int|string|null}|null, title: string, url: ?string, summary: string, link: mixed, type: string}
     */
    public function toArray(): array
    {
        return ['entry' => $this->entry?->toArray(), 'title' => $this->title, 'url' => $this->url, 'summary' => $this->summary, 'link' => $this->link, 'type' => $this->type];
    }

    /**
     * @param  array<mixed>  $array
     */
    public static function fromArray(array $array): self
    {
        return new self(
            is_array($array['entry'] ?? null) ? EntryRef::fromArray($array['entry']) : null,
            is_string($array['title'] ?? null) ? $array['title'] : '',
            is_string($array['url'] ?? null) ? $array['url'] : null,
            is_string($array['summary'] ?? null) ? $array['summary'] : '',
            $array['link'] ?? null,
            is_string($array['type'] ?? null) ? $array['type'] : '',
        );
    }
}
