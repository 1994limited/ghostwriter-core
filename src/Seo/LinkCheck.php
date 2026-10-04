<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Ai\OutputSchema;
use NineteenNinetyFour\Ghostwriter\Core\Studio\ContentKind;

/**
 * What the `seo-verifier` call is shown (SEO layer §8.3): each link that
 * passed LinkValidator, numbered l1…, in its paragraph with its words
 * marked ⟦ ⟧, the heading it sits under, and the page it would go to
 * (title, type, address, summary). Then each page suggested for one of
 * the writer's links to choose (MarkerSuggestion), numbered by its marker
 * (m1…), the same way. The verifier keeps or drops each one; it never
 * rewrites.
 */
final class LinkCheck
{
    /**
     * @param  list<PlacedLink>  $links  With targets (markers aren't checked).
     * @param  list<MarkerSuggestion>  $suggestions
     */
    public function __construct(
        public readonly string $title,
        public readonly array $links,
        public readonly ?ContentKind $kind = null,
        public readonly string $language = 'en',
        public readonly array $suggestions = [],
    ) {}

    /** Whether there is nothing to check. */
    public function isEmpty(): bool
    {
        return $this->links === [] && $this->suggestions === [];
    }

    public function prompt(): string
    {
        $kind = $this->kind !== null ? " ({$this->kind->title})" : '';
        $lines = ["The page: \"{$this->title}\"{$kind}."];

        if ($this->links !== []) {
            array_push($lines, '', 'The links to check:');
        }

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

        if ($this->suggestions !== []) {
            array_push($lines, '', 'The suggestions to check: words the writer left for the editor to link, and the page the editor would be offered first.');

            foreach ($this->suggestions as $suggestion) {
                $target = $suggestion->target;
                $type = $target->type !== '' ? " ({$target->type})" : '';
                $url = $target->url !== null && $target->url !== '' ? ", {$target->url}" : '';
                $summary = trim((string) preg_replace('/\s+/u', ' ', mb_substr($target->summary, 0, SeoRequest::SUMMARY)));

                array_push($lines, '', "{$suggestion->id()}. The words “{$suggestion->marker->words}”, in:", '    '.str_replace("\n", "\n    ", $suggestion->marker->paragraph()));
                $lines[] = "    The page suggested: {$target->title}{$type}{$url}";

                if ($summary !== '') {
                    $lines[] = "    That page: {$summary}";
                }

                if ($suggestion->why !== '') {
                    $lines[] = "    Why it was chosen: {$suggestion->why}";
                }
            }
        }

        return implode("\n", $lines);
    }

    /** The reply's shape, with these links' ids as the enum. */
    public function schema(OutputSchema $base): OutputSchema
    {
        $schema = $base->schema;
        $schema['properties']['verdicts']['items']['properties']['id']['enum'] = [
            ...array_values(array_map(fn (PlacedLink $link) => $link->id, $this->links)),
            ...array_values(array_map(fn (MarkerSuggestion $suggestion) => $suggestion->id(), $this->suggestions)),
        ];

        return new OutputSchema($base->name, $schema, $base->description);
    }
}
