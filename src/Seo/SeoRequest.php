<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Ai\OutputSchema;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Unit;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\UnitKind;
use NineteenNinetyFour\Ghostwriter\Core\Studio\ContentKind;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\DigestEntry;

/**
 * What the `seo-editor` call is shown (SEO layer §8.1), links part: the
 * draft by unit, which units may take a link, the site's pages it may link
 * to (numbered e1…, the link index's best candidates), and how many links
 * to add at most. The instructions (rules and the voice guide) are the same
 * for every call on a site, so they are cached; everything that changes is
 * here, in the prompt.
 *
 * The writer's own links to choose (`#gw-link:` markers) are listed too,
 * m1…, for the model to suggest the page each most likely means (decision
 * 24); they aren't links the page has (decision 23).
 *
 * Its schema is built per call (schema()): `unit` is an enum of the units
 * that may take a link, `target` one of the candidates' ids (or '' for a
 * marker) and `marker` one of the writer's markers, so the model can't name
 * anything else.
 */
final class SeoRequest
{
    /** Characters of a candidate's summary shown. */
    public const SUMMARY = 160;

    /**
     * @param  list<Unit>  $units  The draft's text units, in reading order.
     * @param  list<string>  $linkable  The ids of the units that may take a link.
     * @param  list<DigestEntry>  $candidates  Numbered e1… in this order.
     * @param  int  $linkTarget  How many links to add, at most (§7.2); 0: none wanted.
     * @param  int  $existing  Links the draft has already, the writer's markers not counted.
     * @param  list<WriterMarker>  $markers  The writer's links to choose, m1…
     * @param  MetaRequest|null  $meta  The search title and description wanted (§9); null: none.
     */
    public function __construct(
        public readonly string $title,
        public readonly array $units,
        public readonly array $linkable,
        public readonly array $candidates,
        public readonly int $linkTarget,
        public readonly int $existing = 0,
        public readonly ?ContentKind $kind = null,
        public readonly string $voice = '',
        public readonly string $language = 'en',
        public readonly int $words = 0,
        public readonly array $markers = [],
        public readonly ?MetaRequest $meta = null,
    ) {}

    /** Whether the call asks for links or suggestions at all, rather than only the title and description. */
    public function wantsLinks(): bool
    {
        return ($this->linkTarget > 0 && $this->candidates !== []) || ($this->markers !== [] && $this->candidates !== []);
    }

    /**
     * The candidates by id: e1, e2…
     *
     * @return array<string, DigestEntry>
     */
    public function byId(): array
    {
        $out = [];

        foreach (array_values($this->candidates) as $i => $candidate) {
            $out['e'.($i + 1)] = $candidate;
        }

        return $out;
    }

    /** The prompt: the page by unit, then the pages it may link to. */
    public function prompt(): string
    {
        $kind = $this->kind !== null ? " ({$this->kind->title})" : '';
        $lines = [
            "The page: \"{$this->title}\"{$kind}, about {$this->words} words.",
            match (true) {
                $this->linkTarget > 0 && $this->candidates !== [] => "Links it has already: {$this->existing}. Add at most {$this->linkTarget}, and fewer, or none, when nothing fits.",
                $this->candidates === [] && $this->meta !== null => 'No links are wanted this time: give `"links": []` and `"markers": []`.',
                default => "Links it has already: {$this->existing}. It has enough: add none, and give `\"links\": []`.",
            },
            '',
            '## The page, by unit',
        ];

        if ($this->candidates !== []) {
            array_push($lines, '', 'Only units marked "links allowed" can take a link.');
        }

        foreach ($this->units as $unit) {
            if ($unit->kind === UnitKind::Media || trim($unit->markdown) === '') {
                continue;
            }

            $allowed = $this->candidates === [] ? '' : (in_array($unit->id, $this->linkable, true) ? ', links allowed' : ', no links here');
            array_push($lines, '', "[{$unit->id}] ({$unit->kind->value}{$allowed})", trim($unit->markdown));
        }

        if ($this->markers !== []) {
            array_push($lines, '', '## Links the writer left for the editor to choose', '', 'For each, the site\'s page the editor most likely means, or none.');

            foreach ($this->markers as $marker) {
                $hint = $marker->hintWords() !== '' ? " (the writer's note: \"{$marker->hintWords()}\")" : '';
                $lines[] = "{$marker->id}. “{$marker->words}” in {$marker->unit->id}{$hint}";
            }
        }

        if ($this->candidates !== []) {
            array_push($lines, '', '## The site\'s pages you may link to', '');
        }

        foreach ($this->byId() as $id => $candidate) {
            $type = $candidate->type !== '' ? " ({$candidate->type})" : '';
            $url = $candidate->url !== null && $candidate->url !== '' ? " · {$candidate->url}" : '';
            $lines[] = "{$id}. {$candidate->title}{$type}{$url}";

            $summary = trim((string) preg_replace('/\s+/u', ' ', mb_substr($candidate->summary, 0, self::SUMMARY)));

            if ($summary !== '') {
                $lines[] = "    {$summary}";
            }
        }

        if ($this->meta !== null) {
            array_push($lines, '', $this->meta->prompt());
        }

        return implode("\n", $lines);
    }

    /** The reply's shape, with this call's units and candidates as enums. */
    public function schema(OutputSchema $base): OutputSchema
    {
        $schema = $base->schema;
        $item = &$schema['properties']['links']['items']['properties'];
        $item['unit']['enum'] = $this->linkable !== [] ? array_values($this->linkable) : [''];
        $item['target']['enum'] = [...array_keys($this->byId()), ''];
        $schema['properties']['links']['maxItems'] = max(0, $this->linkTarget);
        unset($item);
        $marker = &$schema['properties']['markers'];
        $marker['items']['properties']['marker']['enum'] = $this->markers !== [] ? array_values(array_map(fn (WriterMarker $m) => $m->id, $this->markers)) : [''];
        $marker['items']['properties']['target']['enum'] = [...array_keys($this->byId()), ''];
        $marker['maxItems'] = count($this->markers);
        unset($marker);

        return new OutputSchema($base->name, $schema, $base->description);
    }
}
