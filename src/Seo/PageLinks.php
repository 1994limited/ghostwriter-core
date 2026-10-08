<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Usage;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\QuoteFinder;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\TextQuote;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Unit;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\UnitKind;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Units;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Visit;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Walk;
use NineteenNinetyFour\Ghostwriter\Core\Layout\LinkPlaceholders;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\Links;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\DigestEntry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Finish's **Suggest links** (SEO layer §12, `few-links`): links for an
 * existing page, from its current text, by the same rules and the same two
 * calls as a first draft's (SeoLinks), but proposed rather than made:
 *
 * 1. How many: about one per 250 words of its prose, 2 to 5 (decision 8),
 *    less the links it has to the site's own pages already; none under
 *    150 words.
 * 2. Where to: the link index's best candidates for the page
 *    (LinkIndex::related(), every routable page of the site, key pages
 *    included), never the page itself (LinkContext::$except) or a page it
 *    links to already, each one the field's rich text can link to.
 * 3. One `seo-editor` call (links only: no title or description) picks the
 *    words and the pages; LinkValidator checks each pick (descriptive
 *    words, not in a heading, bold or a quotation, within one sentence,
 *    one a unit, no page twice); one `seo-verifier` call keeps or drops
 *    each in its paragraph. A failed verifier keeps what passed the checks.
 * 4. Each kept link becomes a LinkProposal: the field, the words as a
 *    quote of the field's text, the page and its href. Nothing is written:
 *    the editor links each one from its step (Link it), or skips it.
 *
 * Words with Markdown in them (an italic, an escape) are left out: the
 * editor finds a proposal by the words it shows.
 */
final class PageLinks
{
    /** Characters that would make the words differ from what the editor shows. */
    private const MARKUP = '/[*_`\\\\\[\]<>]/u';

    private readonly LoggerInterface $logger;

    private Usage $spent;

    public function __construct(
        private readonly Studio $studio,
        ?LoggerInterface $logger = null,
        private readonly LinkValidator $validator = new LinkValidator,
    ) {
        $this->logger = $logger ?? new NullLogger;
        $this->spent = new Usage;
    }

    /** The tokens the last find() spent. */
    public function spent(): Usage
    {
        return $this->spent;
    }

    /**
     * The links to propose for the page in $page (its current values: the
     * form's, or the saved entry's), linking to the site's pages through
     * $links.
     *
     * @param  string|null  $title  The page's title; null takes the entry's `title` value.
     *
     * @throws ProviderException from the `seo-editor` call; a failed verifier is logged.
     */
    public function find(GapContext $page, LinkContext $links, ?string $title = null, ?string $now = null): LinkProposals
    {
        $this->spent = new Usage;
        $now ??= gmdate('Y-m-d\TH:i:s\Z');
        $title ??= is_scalar($page->entry->values['title'] ?? null) ? trim((string) $page->entry->values['title']) : '';
        $units = Units::fromEntry($page->entry, $page->schema, $page->richText);
        $visits = [];

        foreach (Walk::entry($page->schema, $page->entry) as $visit) {
            $visits[$visit->path->toString()] = $visit;
        }

        $prose = array_values(array_filter($units->all(), fn (Unit $unit) => in_array($unit->kind, [UnitKind::Prose, UnitKind::Section, UnitKind::List], true) && trim($unit->markdown) !== ''));
        $linkable = [];

        foreach ($prose as $unit) {
            $visit = $visits[$unit->path->toString()] ?? null;

            if ($visit !== null && self::holdsLinks($visit->field, $links)) {
                $linkable[$unit->id] = $unit;
            }
        }

        $words = array_sum(array_map(fn (Unit $unit) => count(NormalisedText::words($unit->markdown)), $prose));
        $hrefs = array_values(array_filter(SeoLinks::links(array_map(fn (Unit $unit) => $unit->markdown, $units->all()))));
        $internal = Links::of($page, $page->hosts)['internal'];
        $room = $words < SeoLinks::MIN_WORDS ? 0 : max(0, SeoLinks::target($words) - count($internal));

        if ($linkable === []) {
            $this->logger->info('Ghostwriter: Suggest links found no field on this page that can take a link.');

            return LinkProposals::none(LinkProposals::NO_PLACE, $now);
        }

        if ($room <= 0) {
            $this->logger->info("Ghostwriter: Suggest links looked for none ({$words} words of prose, ".count($internal).' links to the site already).');

            return LinkProposals::none(LinkProposals::NO_ROOM, $now);
        }

        $text = $title."\n\n".implode("\n\n", array_map(fn (Unit $unit) => $unit->markdown, $prose));
        $candidates = array_values(array_filter(
            $links->index->related($text, $links->group, $links->site, $links->except, LinkCandidates::LIMIT, array_values(array_unique([...$hrefs, ...$internal]))),
            fn (DigestEntry $entry) => $links->links->inlineHref($entry) !== null,
        ));

        if ($candidates === []) {
            $this->logger->info('Ghostwriter: Suggest links found no page of the site close enough to this one to link to.');

            return LinkProposals::none(LinkProposals::NO_CANDIDATES, $now);
        }

        $request = new SeoRequest($title, $units->all(), array_keys($linkable), $candidates, $room, count($internal), $links->kind, $links->voice, $links->locale, $words);
        $result = $this->studio->seoEdit($request);
        $this->spent = $result->usage;
        $validated = $this->validator->validate($result->value->links, $request, $linkable, $links->links, $prose[0]->id ?? null, $links->locale);
        $checked = array_values(array_filter($validated->kept, fn (PlacedLink $link) => $link->target !== null));

        if ($checked !== []) {
            try {
                $verdicts = $this->studio->verifySeoLinks(new LinkCheck($title, $checked, $links->kind, $links->locale));
                $this->spent = $this->spent->plus($verdicts->usage);
                $validated = $this->validator->judged($validated, $verdicts->value, $request, $prose[0]->id ?? null, $links->locale);
            } catch (ProviderException $exception) {
                $this->logger->warning("Ghostwriter: the link verifier failed, so Suggest links offers the links that passed the checks unverified: {$exception->getMessage()}", ['agent' => 'seo-verifier']);
            }
        }

        if ($validated->dropped !== []) {
            $this->logger->info('Ghostwriter: Suggest links dropped '.count($validated->dropped).' of '.count($result->value->links).' proposed links.', ['agent' => 'seo-editor', 'dropped' => $validated->rules(), 'notes' => $result->value->notes]);
        }

        if ($validated->anchored !== []) {
            $this->logger->info('Ghostwriter: the link verifier gave '.count($validated->anchored).' '.(count($validated->anchored) === 1 ? 'link' : 'links').' better words.', ['agent' => 'seo-verifier', 'anchors' => $validated->anchors()]);
        }

        $proposals = [];

        foreach ($validated->kept as $link) {
            if ($link->target === null) {
                continue;
            }

            $proposal = $this->proposal($link, $visits[$link->unit->path->toString()] ?? null, $page, 'l'.(count($proposals) + 1));

            if ($proposal !== null) {
                $proposals[] = $proposal;
            }
        }

        $this->logger->info('Ghostwriter: Suggest links proposed '.count($proposals).' '.(count($proposals) === 1 ? 'link' : 'links').'.', [
            'links' => array_map(fn (LinkProposal $proposal) => "{$proposal->words} → {$proposal->title}", $proposals),
            // What the model made of the page and the candidates, and how many it picked: why none, when none.
            'picked' => count($result->value->links),
            'notes' => $result->value->notes,
            // The pages it was offered, best first (LinkIndex::related()).
            'candidates' => array_map(fn (DigestEntry $entry) => $entry->title, $candidates),
        ]);

        return $proposals === [] ? LinkProposals::none(LinkProposals::DROPPED, $now) : new LinkProposals($proposals, '', $now);
    }

    /**
     * A kept link as a proposal: its words found again in the field's text
     * (a unit is a section of it), quoted there. Null when they can't be,
     * or have Markdown in them.
     */
    private function proposal(PlacedLink $link, ?Visit $visit, GapContext $page, string $id): ?LinkProposal
    {
        $words = $link->words();
        $text = $visit !== null ? Walk::text($visit, $page->richText) : null;

        if ($visit === null || $text === null || $link->target === null) {
            return null;
        }

        if (preg_match(self::MARKUP, $words) === 1) {
            $this->logger->info('Ghostwriter: Suggest links left out a link whose words have formatting in them.', ['words' => $words]);

            return null;
        }

        try {
            $found = (new QuoteFinder)->find(TextQuote::around($link->unit->markdown, $link->offset, $link->length), $text);
        } catch (InvalidArgumentException) {
            return null;
        }

        if ($found === null || $found->fuzzy) {
            return null;
        }

        return new LinkProposal($id, $visit->path, $visit->label, TextQuote::around($text, $found->offset, $found->length), $words, $link->href, $link->target->title, $link->target->type, $link->target->url, $link->pick->why);
    }

    /** Markdown whose editor has a link button. */
    private static function holdsLinks(Field $field, LinkContext $context): bool
    {
        $markdown = $field->kind === Kind::RichText
            || ($field->kind === Kind::LongText && ($field->type === 'markdown' || ($field->meta['format'] ?? null) === 'markdown'));

        return $markdown && (! $context->links instanceof LinkPlaceholders || $context->links->supportsLinks($field));
    }
}
