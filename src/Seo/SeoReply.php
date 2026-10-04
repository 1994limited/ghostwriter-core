<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

/**
 * The `seo-editor` call's answer, read: what the model made of the page
 * (`notes`, for the log), the links it proposes, in its order, and the
 * page it suggests for each of the writer's links to choose (`markers`).
 * Nothing here is checked yet: LinkValidator and SeoLinks do that.
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

        return new self(is_scalar($data['notes'] ?? null) ? trim((string) $data['notes']) : '', $links, $markers);
    }
}
