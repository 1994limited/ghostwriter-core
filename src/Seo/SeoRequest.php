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
 * Its schema is built per call (schema()): `unit` is an enum of the units
 * that may take a link and `target` one of the candidates' ids (or '' for a
 * marker), so the model can't name anything else.
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
     * @param  int  $existing  Links the draft has already, markers included.
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
    ) {}

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
            "Links it has already: {$this->existing}. Add at most {$this->linkTarget}, and fewer, or none, when nothing fits.",
            '',
            '## The page, by unit',
            '',
            'Only units marked "links allowed" can take a link.',
        ];

        foreach ($this->units as $unit) {
            if ($unit->kind === UnitKind::Media || trim($unit->markdown) === '') {
                continue;
            }

            $allowed = in_array($unit->id, $this->linkable, true) ? 'links allowed' : 'no links here';
            array_push($lines, '', "[{$unit->id}] ({$unit->kind->value}, {$allowed})", trim($unit->markdown));
        }

        array_push($lines, '', '## The site\'s pages you may link to', '');

        foreach ($this->byId() as $id => $candidate) {
            $type = $candidate->type !== '' ? " ({$candidate->type})" : '';
            $url = $candidate->url !== null && $candidate->url !== '' ? " · {$candidate->url}" : '';
            $lines[] = "{$id}. {$candidate->title}{$type}{$url}";

            $summary = trim((string) preg_replace('/\s+/u', ' ', mb_substr($candidate->summary, 0, self::SUMMARY)));

            if ($summary !== '') {
                $lines[] = "    {$summary}";
            }
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

        return new OutputSchema($base->name, $schema, $base->description);
    }
}
