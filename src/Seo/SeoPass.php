<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Usage;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\LayoutContext;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\HeadingLevels;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use NineteenNinetyFour\Ghostwriter\Core\Text\DraftEditor;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * The SEO layer's pass over a draft: what the writing pipeline calls.
 *
 * - **afterWriter()** (①): on the writer's draft, before units and
 *   layouts are made from it, so every layout starts from fixed, linked
 *   text. Headings are fitted to the template and each field's editor
 *   (HeadingFixer). After a writer's turn, LinkGuard keeps the writer's
 *   links to real pages of the site and turns any other address it made
 *   up into a `#gw-link:` marker. On the first draft, where
 *   the addon gives a LinkContext and a Studio, the draft is linked to the
 *   site's other pages (SeoLinks: one `seo-editor` and one `seo-verifier`
 *   call). The session's draft is rewritten only when something changed.
 *   SessionLayouts runs it after every writer turn and edit.
 * - **arranged()** (②): on a plan's arranged data, in
 *   SessionLayouts::draftData(), because layouts make headings
 *   (lead-in-to-heading, heading-level). Nothing is stored: a plan is
 *   fixed whenever it is built, for the preview, the cards and "Use this
 *   draft" alike.
 * - **removeLink()**: an editor's "Remove link" on a link the pass added:
 *   the words stay, the link goes, and LinkGuard won't let it back.
 * - **The search title, description and address** (§9, §10), where the
 *   addon gives LayoutContext a MetaContext: written by the first draft's
 *   `seo-editor` call (with the links; SeoMeta says which are wanted), and
 *   again after a writer's turn that changed the title or a quarter of the
 *   words, unless an editor wrote them. The address follows the title
 *   (SlugRules) until someone types one. retryMeta() is the Search
 *   section's Try again; editMeta() and useMeta() its edits.
 *
 * Everything but the first draft's links is deterministic and idempotent.
 */
final class SeoPass
{
    /** What the panel says while ① runs on a first draft: "Checking headings and links…". */
    public const CHECKING = 'checking';

    /** The share of a draft's words a writer's turn must change for the title and description to be written again (§5.2). */
    public const CHANGED = 0.25;

    private readonly LoggerInterface $logger;

    /** The tokens the last afterWriter() spent. */
    private Usage $spent;

    public function __construct(
        private readonly HeadingFixer $fixer = new HeadingFixer,
        ?LoggerInterface $logger = null,
        private readonly ?Studio $studio = null,
        private readonly LinkGuard $guard = new LinkGuard,
        private readonly SeoMeta $meta = new SeoMeta,
    ) {
        $this->logger = $logger ?? new NullLogger;
        $this->spent = new Usage;
    }

    /**
     * ① on the session's draft. Returns what changed in the headings, by
     * value (a dotted path: `body`, `page_builder.2.text`); the draft is
     * left alone when nothing did, or when it doesn't parse.
     *
     * - $writer: the draft is a writer's turn, so its links are guarded
     *   against $before (the draft it had before; null on the first).
     * - $first: the first draft, so it is linked to the site's other pages
     *   where $site has a LinkContext (two calls; their tokens are added to
     *   the session's usage and given by spent()). A failed call never
     *   fails the turn: the draft goes on without links.
     *
     * @return array<string, list<HeadingChange>>
     */
    public function afterWriter(Session $session, LayoutContext $site, bool $first = false, ?string $before = null, bool $writer = false): array
    {
        $this->spent = new Usage;

        if ($session->draft === null || trim($session->draft) === '') {
            return [];
        }

        try {
            $draft = Draft::parse($session->draft);
        } catch (Throwable) {
            return [];
        }

        [$data, $changes] = $this->headings($draft->data, $site->schema, $site->profile);
        $changed = $changes !== [];

        if ($changed) {
            $this->log($changes, 'draft');
        }

        if ($writer && $site->links !== null) {
            [$data, $guarded, $real] = $this->guard->guard($data, self::data($before), self::sources($session), SeoState::of($session), $site->links);

            if ($real !== []) {
                $changed = $changed || array_filter($real, fn (array $link) => $link['to'] !== '['.$link['words'].']('.$link['href'].')') !== [];
                $this->logger->info('Ghostwriter: the writer linked to '.count($real).' of the site\'s pages; '.(count($real) === 1 ? 'it is' : 'they are').' kept.', ['links' => array_map(fn (array $link) => "{$link['words']} → {$link['title']}", $real)]);
            }

            if ($guarded !== []) {
                $changed = true;
                $this->logger->warning('Ghostwriter: the writer used '.count($guarded).' '.(count($guarded) === 1 ? 'address' : 'addresses').' that it wasn\'t given; '.(count($guarded) === 1 ? 'it is' : 'they are').' now a link for the editor to choose.', ['links' => array_map(fn (array $change) => $change['href'], $guarded)]);
            }
        }

        if ($changed) {
            $session->draft = (new DraftEditor)->dump($data);
        }

        if ($first && ($site->links !== null || $site->meta !== null) && $this->studio !== null && SeoState::of($session)->checked === null) {
            $meta = $site->meta !== null ? $this->meta->request($session, $site->meta, self::sources($session)) : null;

            try {
                $this->spent = (new SeoLinks($this->studio, $this->logger))->add($session, $site, null, $meta);
            } catch (ProviderException $exception) {
                $this->logger->warning("Ghostwriter: the draft wasn't linked to the site's other pages, and has no search title or description, as the call failed: {$exception->getMessage()}", ['agent' => 'seo-editor']);
                SeoState::of($session)->withLinks(SeoState::of($session)->links, null, gmdate('Y-m-d\TH:i:s\Z'))->saveTo($session);
            }
        } elseif ($writer && ! $first && $site->meta !== null && $this->studio !== null && SeoState::of($session)->meta->checked !== null && self::changed($before, $session->draft)) {
            // A writer's turn that changed the title, or a quarter of the words: the title and description follow, unless an editor wrote them (§5.2).
            $meta = $this->meta->request($session, $site->meta, self::sources($session));

            if ($meta !== null) {
                $this->spent = $this->writeMeta($session, $site, $meta);
            }
        }

        if ($this->spent->input > 0 || $this->spent->output > 0) {
            $session->usage = ['input' => (int) ($session->usage['input'] ?? 0) + $this->spent->input, 'output' => (int) ($session->usage['output'] ?? 0) + $this->spent->output] + $session->usage;
        }

        if ($site->meta !== null) {
            $this->fitSlug($session, $site->meta);
        }

        return $changes;
    }

    /**
     * "Try again" in the Search section: one `seo-editor` call, for the
     * title and description only, written differently from the ones there
     * now. The editor's own are asked for again too: they asked. Returns
     * the call's tokens (added to the session's usage); none when the page
     * has no SEO field Ghostwriter writes or suggests.
     *
     * @throws ProviderException
     */
    public function retryMeta(Session $session, LayoutContext $site): Usage
    {
        if ($site->meta === null || $this->studio === null) {
            return new Usage;
        }

        $request = $this->meta->request($session, $site->meta, self::sources($session), force: true);

        if ($request === null) {
            return new Usage;
        }

        $state = SeoState::of($session);
        $meta = $state->meta;

        foreach ([SeoField::TITLE, SeoField::DESCRIPTION] as $role) {
            if ($request->wanted($role)) {
                $meta = $meta->with($role, $meta->text($role));
            }
        }

        $state->withMeta($meta)->saveTo($session);
        $usage = $this->writeMeta($session, $site, $request, rethrow: true);
        $session->usage = ['input' => (int) ($session->usage['input'] ?? 0) + $usage->input, 'output' => (int) ($session->usage['output'] ?? 0) + $usage->output] + $session->usage;

        return $usage;
    }

    /**
     * An editor's text in the Search section: the title (empty to use the
     * page title again: "Use the page title"), the description, or the
     * address (empty: made from the title again). Theirs from now on: no
     * later turn rewrites it. Nothing is checked: it is theirs.
     */
    public function editMeta(Session $session, string $role, string $text, ?LayoutContext $site = null): void
    {
        $state = SeoState::of($session);

        if ($role === 'slug') {
            $slug = SlugRules::clean($text);
            $state->withMeta($state->meta->withSlug($slug !== '' ? $slug : null, $slug !== ''))->saveTo($session);

            if ($slug === '' && $site?->meta !== null) {
                $this->fitSlug($session, $site->meta);
            }

            return;
        }

        if (! in_array($role, [SeoField::TITLE, SeoField::DESCRIPTION], true)) {
            return;
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $state->withMeta($state->meta->with($role, $text, edited: true))->saveTo($session);
    }

    /**
     * "Use this" beside an SEO value of the entry's own that stays: the
     * draft's text goes in on "Use this draft" after all ($use), or not.
     */
    public function useMeta(Session $session, string $role, bool $use = true): void
    {
        $state = SeoState::of($session);
        $state->withMeta($state->meta->using($role, $use))->saveTo($session);
    }

    /**
     * The `seo-editor` call for the title and description alone (no
     * links), checked into the session's meta.
     *
     * @throws ProviderException when $rethrow; otherwise a failure is logged and nothing changes.
     */
    private function writeMeta(Session $session, LayoutContext $site, MetaRequest $request, bool $rethrow = false): Usage
    {
        $alone = new LayoutContext($site->schema, $site->pattern, $site->entries, $site->defaults, $site->exampleIds, $site->profile, null, $site->meta);

        try {
            return (new SeoLinks($this->studio ?? throw new ProviderException('No studio.', 'seo'), $this->logger))->add($session, $alone, null, $request);
        } catch (ProviderException $exception) {
            if ($rethrow) {
                throw $exception;
            }

            $this->logger->warning("Ghostwriter: the search title and description weren't written again, as the call failed: {$exception->getMessage()}", ['agent' => 'seo-editor']);

            return new Usage;
        }
    }

    /** The address follows the title while nobody has typed one. */
    private function fitSlug(Session $session, MetaContext $context): void
    {
        $state = SeoState::of($session);
        $slug = $this->meta->slug($session, $context);

        if ($slug !== $state->meta->slug) {
            $state->withMeta($state->meta->withSlug($slug, $state->meta->edited('slug')))->saveTo($session);
        }
    }

    /**
     * Whether a writer's turn changed the page enough for its search title
     * and description to be written again: the title changed, or a quarter
     * of the words did (§5.2).
     */
    public static function changed(?string $before, ?string $after): bool
    {
        $a = self::data($before);
        $b = self::data($after);

        if ($a === null || $b === null) {
            return false;
        }

        $title = fn (array $data) => trim(is_scalar($data['title'] ?? null) ? (string) $data['title'] : '');

        if ($title($a) !== $title($b)) {
            return true;
        }

        $words = fn (array $data) => array_count_values(NormalisedText::words(mb_strtolower(SeoMeta::text($data))));
        $x = $words($a);
        $y = $words($b);
        $total = array_sum($x) + array_sum($y);

        if ($total === 0) {
            return false;
        }

        $differ = 0;

        foreach ($x + $y as $word => $_) {
            $differ += abs(($x[$word] ?? 0) - ($y[$word] ?? 0));
        }

        return $differ / $total >= self::CHANGED;
    }

    /** The tokens the last afterWriter() spent on links (none on most turns). */
    public function spent(): Usage
    {
        return $this->spent;
    }

    /**
     * "Remove link" on a link the pass added: every link to that page in
     * the draft loses its link and keeps its words, the link leaves the
     * session's SEO state and joins its removed ones (LinkGuard won't let
     * the writer put it back). False when the draft has no such link.
     */
    public function removeLink(Session $session, string $href): bool
    {
        if ($session->draft === null || trim($session->draft) === '') {
            return false;
        }

        try {
            $draft = Draft::parse($session->draft);
        } catch (Throwable) {
            return false;
        }

        $key = LinkCandidates::linkKey($href) ?? $href;
        $found = false;
        $unlink = function (mixed $value) use (&$unlink, $key, &$found): mixed {
            if (is_array($value)) {
                return array_map($unlink, $value);
            }

            if (! is_string($value) || ! str_contains($value, '](')) {
                return $value;
            }

            return (string) preg_replace_callback('/(?<!!)\[([^\[\]\n]*)\]\(\s*<?([^()\s>]*)>?(?:\s+"[^"\n]*")?\s*\)/u', function (array $match) use ($key, &$found) {
                if ((LinkCandidates::linkKey($match[2]) ?? $match[2]) !== $key) {
                    return $match[0];
                }

                $found = true;

                return $match[1];
            }, $value);
        };

        $data = $unlink($draft->data);
        $state = SeoState::of($session);

        if (! $found && $state->link($href) === null) {
            return false;
        }

        if ($found) {
            $session->draft = (new DraftEditor)->dump($data);
        }

        $state->without($href)->saveTo($session);
        $this->logger->info('Ghostwriter: an editor removed a link Ghostwriter added.', ['href' => $href]);

        return true;
    }

    /**
     * ② on a plan's arranged data: the same fix, nothing stored.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function arranged(array $data, LayoutContext $site): array
    {
        return $this->headings($data, $site->schema, $site->profile)[0];
    }

    /**
     * A preview's outline, posted by the addon after a render, recorded on
     * the group's stored profile (RenderProfile::observe(): two renders
     * must agree to change it). Returns the stored profile and whether
     * what bodies are fitted to changed, in which case the addon builds
     * the plan again (②) and renders once more.
     *
     * @return array{0: RenderProfile, 1: bool}
     */
    public function observe(RenderProfiles $profiles, string $key, Outline $outline, string $label = '', ?string $now = null): array
    {
        $stored = $profiles->get($key);
        $seen = RenderProfile::fromOutline($key, $outline, $now ?? gmdate('Y-m-d\TH:i:s\Z'), $label);
        $profile = ($stored ?? RenderProfile::default($key, $label))->observe($seen);
        $profiles->put($profile);
        $changed = $stored === null ? $profile->signature() !== RenderProfile::default()->signature() : $profile->signature() !== $stored->signature();

        if ($changed) {
            $this->logger->info("Ghostwriter: the page template of {$key} prints its main heading from ".$profile->h1->value.'.', ['profile' => $profile->toArray()]);
        }

        return [$profile, $changed];
    }

    /**
     * Every rich-text and markdown value of some draft data fitted to its
     * HeadingPolicy: at the top level by the profile's page `top`; inside
     * a block, one below a heading field the template prints in that
     * block.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: array<string, list<HeadingChange>>}
     */
    public function headings(array $data, Schema $schema, ?RenderProfile $profile = null): array
    {
        $changes = [];
        $data = $this->values($data, $schema->fields, null, $profile ?? RenderProfile::default(), '', $changes);

        return [$data, $changes];
    }

    /**
     * @param  array<mixed>  $values
     * @param  array<int, Field>  $fields
     * @param  array<string, list<HeadingChange>>  $changes
     * @return array<mixed>
     */
    private function values(array $values, array $fields, ?string $blockType, RenderProfile $profile, string $at, array &$changes): array
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field->handle, $values)) {
                continue;
            }

            $value = $values[$field->handle];
            $path = ($at === '' ? '' : $at.'.').$field->handle;

            if ($field->isBuilder() && is_array($value)) {
                $i = 0;

                foreach ($value as $key => $block) {
                    if (! is_array($block)) {
                        continue;
                    }

                    $type = is_string($block['type'] ?? null) ? $block['type'] : null;
                    $set = $type === null ? null : $field->set($type);

                    if ($set !== null) {
                        $value[$key] = $this->values($block, $set->fields, $type, $profile, $path.'.'.$i, $changes);
                    }

                    $i++;
                }

                $values[$field->handle] = $value;
            } elseif ($field->kind === Kind::Rows && is_array($value)) {
                foreach ($value as $key => $row) {
                    if (is_array($row)) {
                        $value[$key] = $this->values($row, $field->fields, $blockType, $profile, $path.'.'.$key, $changes);
                    }
                }

                $values[$field->handle] = $value;
            } elseif ($field->kind === Kind::Group && is_array($value)) {
                $values[$field->handle] = $this->values($value, $field->fields, $blockType, $profile, $path, $changes);
            } elseif (HeadingLevels::holdsHeadings($field) && is_string($value)) {
                $fixed = $this->fixer->fix($value, HeadingPolicy::for($field, $profile, $blockType));

                if ($fixed->changed()) {
                    $values[$field->handle] = $fixed->markdown;
                    $changes[$path] = $fixed->changes;
                }
            }
        }

        return $values;
    }

    /**
     * A draft's data from its YAML; null when there is none or it doesn't
     * parse.
     *
     * @return array<string, mixed>|null
     */
    private static function data(?string $yaml): ?array
    {
        if ($yaml === null || trim($yaml) === '') {
            return null;
        }

        try {
            return Draft::parse($yaml)->data;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * What the editor gave: the brief's answers and the conversation, as
     * text, where an outside address the writer may use would appear.
     *
     * @return list<string>
     */
    private static function sources(Session $session): array
    {
        $texts = [];

        array_walk_recursive($session->answers, function (mixed $value) use (&$texts) {
            if (is_scalar($value)) {
                $texts[] = (string) $value;
            }
        });

        foreach ($session->messages as $message) {
            if (($message['role'] ?? null) === 'user' && is_string($message['content'] ?? null)) {
                $texts[] = $message['content'];
            }
        }

        return $texts;
    }

    /**
     * @param  array<string, list<HeadingChange>>  $changes
     */
    private function log(array $changes, string $what): void
    {
        $count = array_sum(array_map('count', $changes));
        $this->logger->info("Ghostwriter: fitted {$count} ".($count === 1 ? 'heading' : 'headings')." in the {$what} to the page template and its editors.", ['changes' => array_map(fn (array $list) => array_map(fn (HeadingChange $change) => $change->toArray(), $list), $changes)]);
    }
}
