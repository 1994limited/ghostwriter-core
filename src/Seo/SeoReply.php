<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;

/**
 * The `seo-editor` call's answer, read: what the model made of the page
 * (`notes`, for the log), the links it proposes, in its order, the page it
 * suggests for each of the writer's links to choose (`markers`), and the
 * search title and description it wrote ('' where none was wanted).
 * Nothing here is checked yet: LinkValidator, SeoLinks and SeoMetaCheck do
 * that.
 */
final class SeoReply
{
    /**
     * @param  list<LinkPick>  $links
     * @param  list<MarkerPick>  $markers
     */
    public function __construct(
        public readonly string $notes = '',
        public readonly array $links = [],
        public readonly array $markers = [],
        public readonly string $title = '',
        public readonly string $description = '',
    ) {}

    /**
     * @param  array<mixed>  $data  The reply's JSON.
     */
    public static function fromArray(array $data): self
    {
        $links = [];

        foreach (is_array($data['links'] ?? null) ? $data['links'] : [] as $item) {
            if (is_array($item) && ($pick = LinkPick::fromArray($item)) !== null) {
                $links[] = $pick;
            }
        }

        $markers = [];

        foreach (is_array($data['markers'] ?? null) ? $data['markers'] : [] as $item) {
            if (is_array($item) && ($pick = MarkerPick::fromArray($item)) !== null) {
                $markers[] = $pick;
            }
        }

        $text = fn (string $key) => is_scalar($data[$key] ?? null) ? trim((string) $data[$key]) : '';

        return new self($text('notes'), $links, $markers, $text('title'), $text('description'));
    }

    /** The title or the description. */
    public function text(string $role): string
    {
        return $role === SeoField::TITLE ? $this->title : $this->description;
    }
}
