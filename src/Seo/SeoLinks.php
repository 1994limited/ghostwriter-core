<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Usage;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\LayoutContext;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Unit;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\UnitKind;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Units;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Walk;
use NineteenNinetyFour\Ghostwriter\Core\Layout\LinkPlaceholders;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\DigestEntry;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use NineteenNinetyFour\Ghostwriter\Core\Text\DraftEditor;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * The links part of the SEO pass's ① (SEO layer §5.1, §7), on a first
 * draft, before units and layouts are made from it, so every layout
 * carries the links:
 *
 * 1. How many: about one per 250 words of the draft's prose, 2 to 5, less
 *    the links it has already (markers too); none under 150 words, and
 *    none when it has enough (decision 8).
 * 2. Where to: the link index's best candidates for the draft (LinkIndex::
 *    related(): every routable page of the site, decision 9), up to 25,
 *    each one the dialect can link to. None: no call, and the notice says
 *    so.
 * 3. One `seo-editor` call picks the words and the pages; LinkValidator
 *    checks every pick in code; one `seo-verifier` call keeps or drops
 *    each link in its paragraph (decision 10). A failed verifier keeps
 *    what passed the checks.
 * 4. The kept links are written into the draft (the words never change)
 *    and recorded on the session (SeoState), with the notice.
 *
 * The writer's own `#gw-link:` markers are never resolved here, even when
 * a page matches: Finish this page offers that match (§7.5).
 */
final class SeoLinks
{
    /** Below this many words of prose, no links are looked for. */
    public const MIN_WORDS = 150;

    public const WORDS_PER_LINK = 250;

    public const FEWEST = 2;

    public const MOST = 5;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly Studio $studio,
        ?LoggerInterface $logger = null,
        private readonly LinkValidator $validator = new LinkValidator,
    ) {
        $this->logger = $logger ?? new NullLogger;
    }

    /** How many links a draft of this many words is given: about one per 250, 2 to 5. */
    public static function target(int $words): int
    {
        return max(self::FEWEST, min(self::MOST, (int) round($words / self::WORDS_PER_LINK)));
    }

    /**
     * Links the session's draft to the site's other pages, where $site
     * says how (LayoutContext::$links). The session's draft and SEO state
     * are changed in place; the calls' tokens are returned (and not yet
     * added to the session's usage).
     *
     * @throws ProviderException only from the `seo-editor` call; a failed verifier is logged.
     */
    public function add(Session $session, LayoutContext $site, ?string $now = null): Usage
    {
        $context = $site->links;
        $now ??= gmdate('Y-m-d\TH:i:s\Z');

        if ($context === null || $session->draft === null || trim($session->draft) === '') {
            return new Usage;
        }

        try {
            $draft = Draft::parse($session->draft);
        } catch (Throwable) {
            return new Usage;
        }

        $state = SeoState::of($session);
        $units = Units::fromDraft($draft, $site->schema)->restore($session->units);
        $fields = self::fields($draft->data, $site);
        $prose = array_values(array_filter($units->all(), fn (Unit $unit) => in_array($unit->kind, [UnitKind::Prose, UnitKind::Section, UnitKind::List], true) && trim($unit->markdown) !== ''));
        $linkable = [];

        foreach ($prose as $unit) {
            $field = $fields[$unit->path->toString()] ?? null;

            if ($field !== null && self::holdsLinks($field, $context)) {
                $linkable[$unit->id] = $unit;
            }
        }

        $words = array_sum(array_map(fn (Unit $unit) => count(NormalisedText::words($unit->markdown)), $prose));
        $hrefs = self::links($draft->data);
        $room = self::target($words) - count($hrefs);

        if ($linkable === [] || $words < self::MIN_WORDS || $room <= 0) {
            $this->logger->info("Ghostwriter: no internal links looked for ({$words} words of prose, ".count($hrefs).' links already, '.count($linkable).' units that can take one).');
            (new SeoState($state->links, $state->removed, $state->notice, $now))->saveTo($session);

            return new Usage;
        }

        $title = $draft->title();
        $text = $title."\n\n".implode("\n\n", array_map(fn (Unit $unit) => $unit->markdown, $prose));
        $candidates = array_values(array_filter(
            $context->index->related($text, $context->group, $context->site, $context->except, LinkCandidates::LIMIT, array_values(array_unique($hrefs))),
            fn (DigestEntry $entry) => $context->links->inlineHref($entry) !== null,
        ));

        if ($candidates === []) {
            $this->logger->info('Ghostwriter: no page of the site is close enough to this draft to link to.');
            $state->withLinks($state->links, ['key' => 'seo.notice.no-links', 'params' => []], $now)->saveTo($session);

            return new Usage;
        }

        $request = new SeoRequest($title, $units->all(), array_keys($linkable), $candidates, $room, count($hrefs), $context->kind, $context->voice, $context->locale, $words);
        $result = $this->studio->seoEdit($request);
        $usage = $result->usage;
        $first = $prose[0]->id ?? null;
        $validated = $this->validator->validate($result->value->links, $request, $linkable, $context->links, $first, $context->locale);
        $checked = array_values(array_filter($validated->kept, fn (PlacedLink $link) => $link->target !== null));

        if ($checked !== []) {
            try {
                $verdicts = $this->studio->verifySeoLinks(new LinkCheck($title, $checked, $context->kind, $context->locale));
                $usage = $usage->plus($verdicts->usage);
                $validated = $validated->without($verdicts->value);
            } catch (ProviderException $exception) {
                $this->logger->warning("Ghostwriter: the link verifier failed, so the links that passed the checks are kept unverified: {$exception->getMessage()}", ['agent' => 'seo-verifier']);
            }
        }

        if ($validated->dropped !== []) {
            $this->logger->info('Ghostwriter: '.count($validated->dropped).' of '.count($result->value->links).' proposed links were dropped.', ['agent' => 'seo-editor', 'dropped' => $validated->rules(), 'notes' => $result->value->notes]);
        }

        $data = $draft->data;
        $added = [];
        $editor = new DraftEditor;

        foreach ($validated->kept as $link) {
            try {
                $data = $editor->set($data, $link->unit, $link->linked());
            } catch (InvalidArgumentException $exception) {
                $this->logger->warning("Ghostwriter: a link couldn't be written into the draft: {$exception->getMessage()}");

                continue;
            }

            if ($link->target !== null) {
                $added[] = [
                    'unit' => $link->unit->id,
                    'words' => $link->words(),
                    'href' => $link->href,
                    'title' => $link->target->title,
                    'type' => $link->target->type,
                    'url' => $link->target->url,
                    'why' => $link->pick->why,
                ];
            }
        }

        if ($validated->kept !== []) {
            $session->draft = $editor->dump($data);
        }

        $notice = $added === []
            ? ['key' => 'seo.notice.no-links', 'params' => []]
            : ['key' => count($added) === 1 ? 'seo.notice.links-one' : 'seo.notice.links', 'params' => ['count' => count($added), 'titles' => implode(', ', array_map(fn (array $link) => $link['title'], $added))]];
        $state->withLinks([...$state->links, ...$added], $notice, $now)->saveTo($session);

        $this->logger->info('Ghostwriter: linked the draft to '.count($added).' of the site\'s pages.', ['links' => array_map(fn (array $link) => "{$link['words']} → {$link['title']}", $added)]);

        return $usage;
    }

    /**
     * Every link in some draft data, markers too, as written (repeats
     * kept): what counts towards the five.
     *
     * @param  array<mixed>  $data
     * @return list<string>
     */
    public static function links(array $data): array
    {
        $hrefs = [];

        array_walk_recursive($data, function (mixed $value) use (&$hrefs) {
            if (is_string($value) && str_contains($value, '](') && preg_match_all('/(?<!!)\[[^\[\]\n]*\]\(\s*<?([^()\s>]*)/u', $value, $matches) > 0) {
                array_push($hrefs, ...$matches[1]);
            }
        });

        return array_values(array_filter($hrefs, fn (string $href) => $href !== ''));
    }

    /**
     * The draft's fields by where their values are ("body",
     * "page_builder/2/text").
     *
     * @param  array<string, mixed>  $data
     * @return array<string, Field>
     */
    private static function fields(array $data, LayoutContext $site): array
    {
        $fields = [];

        foreach (Walk::entry($site->schema, new EntryData($data)) as $visit) {
            $fields[$visit->path->toString()] = $visit->field;
        }

        return $fields;
    }

    /** Markdown whose editor has a link button. */
    private static function holdsLinks(Field $field, LinkContext $context): bool
    {
        $markdown = $field->kind === Kind::RichText
            || ($field->kind === Kind::LongText && ($field->type === 'markdown' || ($field->meta['format'] ?? null) === 'markdown'));

        return $markdown && (! $context->links instanceof LinkPlaceholders || $context->links->supportsLinks($field));
    }
}
