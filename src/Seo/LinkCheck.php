<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Ai\OutputSchema;
use NineteenNinetyFour\Ghostwriter\Core\Studio\ContentKind;

/**
 * What the `seo-verifier` call is shown (SEO layer §8.3): each link that
 * passed LinkValidator, numbered l1…, in its paragraph with its words
 * marked ⟦ ⟧, the heading it sits under, and the page it would go to
 * (title, type, address, summary). The verifier keeps or drops each one;
 * it never rewrites.
 */
final class LinkCheck
{
    /**
     * @param  list<PlacedLink>  $links  With targets (markers aren't checked).
     */
    public function __construct(
        public readonly string $title,
        public readonly array $links,
        public readonly ?ContentKind $kind = null,
        public readonly string $language = 'en',
    ) {}

    public function prompt(): string
    {
        $kind = $this->kind !== null ? " ({$this->kind->title})" : '';
        $lines = ["The page: \"{$this->title}\"{$kind}.", '', 'The links to check:'];

        foreach ($this->links as $link) {
            $target = $link->target;

            if ($target === null) {
                continue;
            }

            $type = $target->type !== '' ? " ({$target->type})" : '';
            $url = $target->url !== null && $target->url !== '' ? ", {$target->url}" : '';
            $summary = trim((string) preg_replace('/\s+/u', ' ', mb_substr($target->summary, 0, SeoRequest::SUMMARY)));

            array_push($lines, '', "{$link->id}. The words “{$link->words()}”, in:", '    '.str_replace("\n", "\n    ", $link->paragraph()));

            if (($heading = $link->heading()) !== null) {
                $lines[] = "    Under the heading: {$heading}";
            }

            $lines[] = "    They would link to: {$target->title}{$type}{$url}";

            if ($summary !== '') {
                $lines[] = "    That page: {$summary}";
            }

            if ($link->pick->why !== '') {
                $lines[] = "    Why it was chosen: {$link->pick->why}";
            }
        }

        return implode("\n", $lines);
    }

    /** The reply's shape, with these links' ids as the enum. */
    public function schema(OutputSchema $base): OutputSchema
    {
        $schema = $base->schema;
        $schema['properties']['verdicts']['items']['properties']['id']['enum'] = array_values(array_map(fn (PlacedLink $link) => $link->id, $this->links));

        return new OutputSchema($base->name, $schema, $base->description);
    }
}
