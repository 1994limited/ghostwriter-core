<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

/**
 * The `seo-editor` call's answer, read: what the model made of the page
 * (`notes`, for the log) and the links it proposes, in its order. Nothing
 * here is checked yet: LinkValidator does that.
 */
final class SeoReply
{
    /**
     * @param  list<LinkPick>  $links
     */
    public function __construct(
        public readonly string $notes = '',
        public readonly array $links = [],
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

        return new self(is_scalar($data['notes'] ?? null) ? trim((string) $data['notes']) : '', $links);
    }
}
