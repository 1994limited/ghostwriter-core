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
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;
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
 *    the links it has already; none under 150 words, and none when it has
 *    enough (decision 8). The writer's own `#gw-link:` markers aren't
 *    links the page has, so they don't count (decision 23); its links to
 *    real pages, which LinkGuard kept, do.
 * 2. Where to: the link index's best candidates for the draft (LinkIndex::
 *    related(): every routable page of the site, decision 9), up to 25,
 *    each one the dialect can link to. None: no call, and the notice says
 *    so.
 * 3. One `seo-editor` call picks the words and the pages, and suggests a
 *    page for each of the writer's markers (decision 24); LinkValidator
 *    checks every pick in code, and each suggestion is checked to be a
 *    candidate the dialect can link to; one `seo-verifier` call keeps or
 *    drops each link and suggestion in its paragraph (decision 10). A
 *    failed verifier keeps what passed the checks.
 * 4. The kept links are written into the draft (the words never change)
 *    and recorded on the session (SeoState), with the notice. The kept
 *    suggestions are recorded too (SeoState::$suggested).
 *
 * The writer's own markers are never resolved here: a suggestion is only
 * offered first in Finish this page, "Link to Contact us", for the editor
 * to take or leave (§7.5).
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
     * says how (LayoutContext::$links), and writes its search title and
     * description where $meta asks for them, in the same `seo-editor`
     * call (§8, §9). The session's draft and SEO state are changed in
     * place; the calls' tokens are returned (and not yet added to the
     * session's usage).
     *
     * With no links to look for and nothing in $meta, no call is made.
     *
     * @throws ProviderException only from the `seo-editor` call; a failed verifier is logged.
     */
    public function add(Session $session, LayoutContext $site, ?string $now = null, ?MetaRequest $meta = null): Usage
    {
        $context = $site->links;
        $now ??= gmdate('Y-m-d\TH:i:s\Z');
        $meta = $meta !== null && $meta->wants() ? $meta : null;

        if (($context === null && $meta === null) || $session->draft === null || trim($session->draft) === '') {
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

        foreach ($context === null ? [] : $prose as $unit) {
            $field = $fields[$unit->path->toString()] ?? null;

            if ($field !== null && $context !== null && self::holdsLinks($field, $context)) {
                $linkable[$unit->id] = $unit;
            }
        }

        $words = array_sum(array_map(fn (Unit $unit) => count(NormalisedText::words($unit->markdown)), $prose));
        $hrefs = self::links($draft->data);
        $markers = $context === null ? [] : WriterMarker::in($units->all());
        $room = $linkable === [] || $words < self::MIN_WORDS ? 0 : max(0, self::target($words) - count($hrefs));
        $notice = $state->notice;
        $candidates = [];

        if ($context !== null && $room <= 0 && $markers === []) {
            $this->logger->info("Ghostwriter: no internal links looked for ({$words} words of prose, ".count($hrefs).' links already, '.count($linkable).' units that can take one).');
        } elseif ($context !== null) {
            if ($room <= 0) {
                $this->logger->info("Ghostwriter: no internal links looked for ({$words} words of prose, ".count($hrefs).' links already, '.count($linkable).' units that can take one); pages are suggested for the writer\'s '.count($markers).' '.(count($markers) === 1 ? 'link' : 'links').' to choose.');
            }

            $text = $draft->title()."\n\n".implode("\n\n", array_map(fn (Unit $unit) => $unit->markdown, $prose));
            $candidates = array_values(array_filter(
                $context->index->related($text, $context->group, $context->site, $context->except, LinkCandidates::LIMIT, array_values(array_unique($hrefs))),
                fn (DigestEntry $entry) => $context->links->inlineHref($entry) !== null,
            ));

            if ($candidates === []) {
                $this->logger->info('Ghostwriter: no page of the site is close enough to this draft to link to.');
                $notice = $room > 0 ? ['key' => 'seo.notice.no-links', 'params' => []] : $notice;
            }
        }

        if ($candidates === []) {
            $room = 0;
            $markers = [];
        }

        if ($candidates === [] && $meta === null) {
            $state->withLinks($state->links, $notice, $context !== null ? $now : ($state->checked ?? $now))->saveTo($session);

            return new Usage;
        }

        $title = $draft->title();
        $kind = $context !== null ? $context->kind : $site->meta?->kind;
        $voice = $context !== null ? $context->voice : (string) $site->meta?->voice;
        $locale = $context !== null ? $context->locale : ($site->meta->locale ?? 'en');
        $request = new SeoRequest($title, $units->all(), array_keys($linkable), $candidates, $room, count($hrefs), $kind, $voice, $locale, $words, $markers, $meta);
        $result = $this->studio->seoEdit($request);
        $usage = $result->usage;
        $added = [];
        $suggestions = [];

        if ($context !== null && $candidates !== []) {
            [$data, $added, $suggestions, $usage] = $this->place($draft, $result->value, $request, $linkable, $context, $prose, $usage);

            if ($data !== null) {
                $session->draft = (new DraftEditor)->dump($data);
            }

            $notice = $added === []
                ? ($room > 0 ? ['key' => 'seo.notice.no-links', 'params' => []] : $notice)
                : ['key' => count($added) === 1 ? 'seo.notice.links-one' : 'seo.notice.links', 'params' => ['count' => count($added), 'titles' => implode(', ', array_map(fn (array $link) => $link['title'], $added))]];

            $this->logger->info('Ghostwriter: linked the draft to '.count($added).' of the site\'s pages.', ['links' => array_map(fn (array $link) => "{$link['words']} → {$link['title']}", $added)]);

            if ($markers !== []) {
                $this->logger->info('Ghostwriter: suggested pages for '.count($suggestions).' of the writer\'s '.count($markers).' '.(count($markers) === 1 ? 'link' : 'links').' to choose.', ['suggested' => array_map(fn (MarkerSuggestion $suggestion) => "{$suggestion->marker->words} → {$suggestion->target->title}", $suggestions)]);
            }
        }

        $state = $state->withLinks([...$state->links, ...$added], $notice, $context !== null ? $now : ($state->checked ?? $now), $candidates !== [] ? array_map(fn (MarkerSuggestion $suggestion) => $suggestion->toArray(), $suggestions) : null);

        if ($meta !== null) {
            $settled = (new SeoMeta)->settle($state->meta, $result->value, $meta, $now);
            $state = $state->withMeta($settled);
            $this->logger->info('Ghostwriter: wrote the search '.implode(' and ', array_keys(array_filter(['title' => $meta->wantsTitle, 'description' => $meta->wantsDescription]))).'.', [
                'title' => $meta->wantsTitle ? $settled->title : null,
                'description' => $meta->wantsDescription ? $settled->description : null,
                'dropped' => $settled->dropped,
            ]);
        }

        $state->saveTo($session);

        return $usage;
    }

    /**
     * The links the call proposed, checked and verified, written into the
     * draft's data; the suggestions for the writer's markers kept.
     *
     * @param  array<string, Unit>  $linkable
     * @param  list<Unit>  $prose
     * @return array{0: array<string, mixed>|null, 1: list<array{unit: string, words: string, href: string, title: string, type: string, url: ?string, why: string}>, 2: list<MarkerSuggestion>, 3: Usage}
     */
    private function place(Draft $draft, SeoReply $reply, SeoRequest $request, array $linkable, LinkContext $context, array $prose, Usage $usage): array
    {
        $title = $request->title;
        $first = $prose[0]->id ?? null;
        $validated = $this->validator->validate($reply->links, $request, $linkable, $context->links, $first, $context->locale);
        $checked = array_values(array_filter($validated->kept, fn (PlacedLink $link) => $link->target !== null));
        $suggestions = $this->suggestions($reply->markers, $request, $context);

        if ($checked !== [] || $suggestions !== []) {
            try {
                $verdicts = $this->studio->verifySeoLinks(new LinkCheck($title, $checked, $context->kind, $context->locale, $suggestions));
                $usage = $usage->plus($verdicts->usage);
                $validated = $this->validator->judged($validated, $verdicts->value, $request, $first, $context->locale);
                $dropped = array_values(array_filter($suggestions, fn (MarkerSuggestion $suggestion) => $verdicts->value->drops($suggestion->id())));
                $suggestions = array_values(array_filter($suggestions, fn (MarkerSuggestion $suggestion) => ! $verdicts->value->drops($suggestion->id())));

                if ($dropped !== []) {
                    $this->logger->info('Ghostwriter: the link verifier dropped '.count($dropped).' of the pages suggested for the writer\'s links to choose.', ['dropped' => array_map(fn (MarkerSuggestion $suggestion) => "{$suggestion->marker->words} → {$suggestion->target->title}: ".$verdicts->value->drop[$suggestion->id()], $dropped)]);
                }
            } catch (ProviderException $exception) {
                $this->logger->warning("Ghostwriter: the link verifier failed, so the links that passed the checks are kept unverified: {$exception->getMessage()}", ['agent' => 'seo-verifier']);
            }
        }

        if ($validated->dropped !== []) {
            $this->logger->info('Ghostwriter: '.count($validated->dropped).' of '.count($reply->links).' proposed links were dropped.', ['agent' => 'seo-editor', 'dropped' => $validated->rules(), 'notes' => $reply->notes]);
        }

        if ($validated->anchored !== []) {
            $this->logger->info('Ghostwriter: the link verifier gave '.count($validated->anchored).' '.(count($validated->anchored) === 1 ? 'link' : 'links').' better words.', ['agent' => 'seo-verifier', 'anchors' => $validated->anchors()]);
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

        return [$validated->kept !== [] ? $data : null, $added, $suggestions, $usage];
    }

    /**
     * The `seo-editor` call's suggestions for the writer's markers, checked:
     * a marker shown, once; a candidate shown that the dialect can link to.
     * An empty target (nothing fits) is no suggestion.
     *
     * @param  list<MarkerPick>  $picks
     * @return list<MarkerSuggestion>
     */
    private function suggestions(array $picks, SeoRequest $request, LinkContext $context): array
    {
        $markers = [];

        foreach ($request->markers as $marker) {
            $markers[$marker->id] = $marker;
        }

        $candidates = $request->byId();
        $kept = [];

        foreach ($picks as $pick) {
            $marker = $markers[$pick->marker] ?? null;
            $target = $candidates[$pick->target] ?? null;

            if ($marker === null || $target === null || isset($kept[$pick->marker])) {
                continue;
            }

            $href = $context->links->inlineHref($target);

            if ($href !== null && trim($href) !== '') {
                $kept[$pick->marker] = new MarkerSuggestion($marker, $target, trim($href), $pick->why);
            }
        }

        return array_values($kept);
    }

    /**
     * Every link in some draft data, as written (repeats kept): what counts
     * towards the five. The writer's `#gw-link:` markers don't: they are
     * links the page needs, not links it has (decision 23).
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

        return array_values(array_filter($hrefs, fn (string $href) => $href !== '' && ! Markers::isLinkSentinel($href)));
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
