<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Arrange;

use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Usage;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\Extras;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;
use NineteenNinetyFour\Ghostwriter\Core\Layout\BuiltEntry;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Layouts;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Conversation;
use NineteenNinetyFour\Ghostwriter\Core\Studio\LayoutBrief;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Studio\WriterContext;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use NineteenNinetyFour\Ghostwriter\Core\Text\TaggedResponse;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Layouts and extras on a session: what the addons call. It keeps the
 * session's units, extras, layouts and chosen layout up to date, and gives
 * "Use this draft" the chosen layout's data. Everything is stored on the
 * session (`units`, `extras`, `plans`, `plan`), so it is shared by
 * everyone on the piece (E7); save the session as after any change.
 *
 *     $response = $studio->write($conversation, $context);
 *     $before = $session->draft;
 *     $session->answer($response->reply, $response->document, …);
 *     $layouts->afterWriter($session, $before, $response, $conversation, $context, $site);   // first draft: + the planner
 *
 *     $layouts->plans($session);                    // the cards
 *     $layouts->choose($session, 'p1');             // shared; no model
 *     $data = $layouts->draftData($session, $site); // then the existing build path
 *
 * Only layouts that look noticeably different from the writer's and from
 * each other are offered (LayoutGate); when none is, the piece simply has
 * the writer's.
 *
 * Model calls: the first draft's turn makes one to the layout planner,
 * after the writer's; refresh() makes one. Nothing else here calls a
 * model: switching, editing, deleting an extra and applying cost nothing.
 */
final class SessionLayouts
{
    /** Alternatives asked for besides the writer's layout. */
    public const ALTERNATIVES = 2;

    /** The fewest units worth laying out another way. */
    public const MIN_UNITS = 3;

    private readonly LoggerInterface $logger;

    /** The last planner call's plans, checked: kept, and dropped with why. */
    private ?Validated $planned = null;

    public function __construct(
        private readonly Studio $studio,
        private readonly Layouts $layouts = new Layouts,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger;
    }

    /**
     * After a writer's turn, with the draft it had before ($before; null on
     * the first draft). Carries the unit ids over, keeps the extras the
     * writer sent (or the ones the session had), re-derives the writer's
     * layout and brings the others up to date. On the first draft it then
     * asks the layout planner for alternatives. The planner's tokens are
     * added to the session's usage, and returned.
     */
    public function afterWriter(Session $session, ?string $before, TaggedResponse $response, Conversation $conversation, WriterContext $writer, LayoutContext $site): Usage
    {
        if ($response->document === null) {
            return new Usage;
        }

        $first = $before === null || trim($before) === '';

        if ($response->extras !== null || $first) {
            $session->extras = $this->studio->extras($response, $conversation, $writer, $site->exampleIds)->toArray();
        }

        if (! $this->afterEdit($session, $before, $site)) {
            return new Usage;
        }

        return $first ? $this->plan($session, $site) : new Usage;
    }

    /**
     * After the draft changed by any other route (Edit YAML, click-to-edit,
     * a revision): unit ids carried over, the writer's layout re-derived,
     * the others repaired, any that still fail marked stale, and the
     * comments re-anchored to the units (Detached where their text is
     * gone). No model.
     * False when the draft doesn't parse (layouts are left as they were).
     */
    public function afterEdit(Session $session, ?string $before, LayoutContext $site): bool
    {
        $draft = self::draft($session->draft);

        if ($draft === null) {
            return false;
        }

        $units = Units::fromDraft($draft, $site->schema, $this->layouts->richText);
        $previous = self::draft($before);

        if ($previous !== null) {
            $units = (new UnitMatcher)->carry(Units::fromDraft($previous, $site->schema, $this->layouts->richText)->restore($session->units), $units);
        } elseif ($session->units !== []) {
            $units = $units->restore($session->units);
        }

        $session->units = $units->sidecar();
        $this->rearrange($session, $draft, $units, $site);

        return true;
    }

    /**
     * Asks the planner again ("Refresh layouts", a click): one call. The
     * writer's layout stays first; the others are replaced.
     */
    public function refresh(Session $session, LayoutContext $site): Usage
    {
        return self::draft($session->draft) === null ? new Usage : $this->plan($session, $site);
    }

    /** Whether the planner has anything to arrange: a page builder or a rich text with sections, and enough units. */
    public function needsPlanner(Units $units, LayoutContext $site, Draft $draft): bool
    {
        if (count(array_filter($units->all(), fn (Unit $unit) => $unit->kind !== UnitKind::Media)) < self::MIN_UNITS) {
            return false;
        }

        foreach ($site->schema->fields as $field) {
            if (! array_key_exists($field->handle, $draft->data)) {
                continue;
            }

            if ($field->isBuilder() || (Plans::isMarkdown($field) && count($units->at(FieldPath::of($field->handle))) > 1)) {
                return true;
            }
        }

        return false;
    }

    /** The layouts, in display order: the writer's first. */
    public function plans(Session $session): Plans
    {
        return Plans::fromArray($session->plans);
    }

    /** The chosen layout: the session's choice when it is there and not stale, else the writer's. */
    public function chosen(Session $session): ?Plan
    {
        $plans = $this->plans($session);
        $chosen = $session->plan === null ? null : $plans->get($session->plan);

        return $chosen !== null && ! $chosen->stale ? $chosen : $plans->writer();
    }

    /**
     * Chooses a layout for everyone on the piece. No model; nothing is
     * saved to the entry.
     *
     * @throws InvalidArgumentException for a layout the session doesn't have, or one that needs refreshing.
     */
    public function choose(Session $session, string $planId): void
    {
        $plan = $this->plans($session)->get($planId);

        if ($plan === null) {
            throw new InvalidArgumentException("There is no layout \"{$planId}\" on this piece.");
        }

        if ($plan->stale) {
            throw new InvalidArgumentException("The layout \"{$plan->name}\" needs refreshing before it can be used.");
        }

        $session->plan = $plan->origin === PlanOrigin::Writer ? null : $plan->id;
    }

    /**
     * The draft data of a layout (the chosen one by default), for "Use
     * this draft" and the preview: run the existing build path on it
     * (EntryBuilder, HouseStyle, placeholders, images). The session's
     * draft stays the writer's text.
     *
     * @return array<string, mixed>
     */
    public function draftData(Session $session, LayoutContext $site, ?string $planId = null): array
    {
        $draft = self::draft($session->draft) ?? throw new InvalidArgumentException('This piece has no draft to use.');
        $plan = $planId === null ? $this->chosen($session) : $this->plans($session)->get($planId);

        if ($plan === null) {
            return $draft->data;
        }

        return (new Arranger)->arrange($plan, $this->units($session, $draft, $site), $session->extras, $draft, $site->schema);
    }

    /** The chosen layout built with the addon's EntryBuilder, as "Use this draft" builds a draft. */
    public function build(Session $session, LayoutContext $site, ?string $planId = null): BuiltEntry
    {
        return $this->layouts->builder()->build($this->draftData($session, $site, $planId), $site->schema, $site->pattern, $site->defaults);
    }

    /**
     * What the validator made of the planner's plans on the last call this
     * object made to it (afterWriter() on a first draft, or refresh()): the
     * plans kept, and each dropped one's violations by its id
     * (`$planned->rules()` gives the rules alone). The writer's plan is
     * always kept. Null before any planner call, or when the planner wasn't
     * needed. Not stored on the session: it is for diagnosing a planner
     * reply that produced no layouts.
     */
    public function planned(): ?Validated
    {
        return $this->planned;
    }

    /**
     * What each layout changes against the writer's, for the panel: a few
     * plain phrases to show beside its card ("Closing line as a quote"),
     * and where on the page it changed, to point at when the person
     * switches to it (LayoutDiff::places(), by the preview's blocks and
     * sections), with the units whose words moved or changed shape. By
     * plan id; none for the writer's or a stale one. No model; works on
     * layouts stored before it existed.
     *
     * @return array<string, array{summary: list<string>, places: list<array{field: string, block: int|null, section: int|null}>, units: list<string>}>
     */
    public function changes(Session $session, Schema $schema): array
    {
        $draft = self::draft($session->draft);
        $plans = $this->plans($session);
        $writer = $plans->writer();

        if ($draft === null || $writer === null || count($plans) < 2) {
            return [];
        }

        try {
            $units = Units::fromDraft($draft, $schema, $this->layouts->richText)->restore($session->units);
            $changes = [];

            foreach ($plans->all() as $plan) {
                if ($plan->origin === PlanOrigin::Writer || $plan->stale) {
                    continue;
                }

                $diff = LayoutDiff::between($writer, $plan, $writer, $units, $session->extras, $schema);
                $changes[$plan->id] = ['summary' => $diff->summary(), 'places' => $diff->places(), 'units' => $diff->changedUnits()];
            }

            return $changes;
        } catch (Throwable $exception) {
            $this->logger->warning("Ghostwriter: what the layouts change could not be worked out: {$exception->getMessage()}");

            return [];
        }
    }

    /** The session's extras, for the Text tab. */
    public function extras(Session $session): Extras
    {
        return Extras::fromArray($session->extras);
    }

    /**
     * An extra item changed by the editor: their words become its source.
     *
     * @param  array<string, string>|null  $parts
     */
    public function editExtra(Session $session, string $itemId, string $text, ?array $parts = null, ?LayoutContext $site = null): void
    {
        $session->extras = $this->extras($session)->edit($itemId, $text, $parts)->toArray();

        if ($site !== null) {
            $this->afterEdit($session, $session->draft, $site);
        }
    }

    /** An extra item deleted by the editor; layouts that used it are re-arranged without it. */
    public function deleteExtra(Session $session, string $itemId, LayoutContext $site): void
    {
        $session->extras = $this->extras($session)->without($itemId)->toArray();
        $this->afterEdit($session, $session->draft, $site);
    }

    /**
     * The writer's layout re-derived, the others repaired and checked.
     */
    private function rearrange(Session $session, Draft $draft, Units $units, LayoutContext $site): void
    {
        $extras = $this->extras($session);
        $writer = Plans::fromDraft($draft, $units, $site->schema);
        $validator = new PlanValidator($this->layouts->builder(), $this->logger);
        $repair = new PlanRepair;
        $plans = [$writer];

        foreach ($this->plans($session)->all() as $plan) {
            if ($plan->origin === PlanOrigin::Writer) {
                continue;
            }

            $repaired = $repair->repair($plan, $units, $extras, $writer);
            $violations = $validator->check($repaired, $units, $extras, $draft, $site->schema, $site->pattern, $plans);

            if ($violations !== []) {
                $this->logger->debug("Ghostwriter: layout \"{$repaired->name}\" needs refreshing (".implode(', ', Validated::rulesOf($violations)).').', ['plan' => $repaired->id, 'rules' => Validated::rulesOf($violations), 'violations' => array_map('strval', $violations)]);
            }

            $plans[] = $repaired->with(stale: $violations !== [], suggested: false);
        }

        $this->store($session, new Plans($plans), $units, $extras, $draft, $site);
    }

    /**
     * One call to the planner, its plans checked and ranked.
     */
    private function plan(Session $session, LayoutContext $site): Usage
    {
        $this->planned = null;
        $draft = self::draft($session->draft);

        if ($draft === null) {
            return new Usage;
        }

        $units = $this->units($session, $draft, $site);
        $extras = $this->extras($session);
        $writer = Plans::fromDraft($draft, $units, $site->schema);

        if (! $this->needsPlanner($units, $site, $draft)) {
            $this->store($session, new Plans([$writer]), $units, $extras, $draft, $site);

            return new Usage;
        }

        $sitePatterns = new SitePatterns($this->layouts->richText);
        $patterns = $sitePatterns->find($site->schema, $site->entries);
        $profile = $sitePatterns->profile($site->schema, $site->entries);
        $usage = new Usage;
        $proposed = [];

        try {
            $result = $this->studio->planLayouts(new LayoutBrief($units, $extras, $site->schema, $patterns, $profile, $writer, self::ALTERNATIVES, $site->pattern));
            $usage = $result->usage;
            $proposed = $result->value;
        } catch (ProviderException $exception) {
            $this->logger->warning("Ghostwriter: the layout planner failed: {$exception->getMessage()}", ['agent' => 'layout-planner']);
        }

        $this->planned = (new PlanValidator($this->layouts->builder(), $this->logger))->validate([$writer, ...$proposed], $units, $extras, $draft, $site->schema, $site->pattern);

        if ($this->planned->dropped !== []) {
            $why = implode('; ', array_map(fn (string $id, array $rules) => "{$id}: ".implode(', ', $rules), array_keys($this->planned->rules()), $this->planned->rules()));
            $this->logger->debug('Ghostwriter: the layout planner proposed '.count($proposed).' layouts and '.count($this->planned->dropped)." were dropped ({$why}).", ['agent' => 'layout-planner', 'dropped' => $this->planned->rules()]);
        }

        // Only layouts that look noticeably different are offered; of two
        // near-copies, the one most like the site's pages stays.
        $suggested = (new Candidates)->rank(new Plans($this->planned->kept), $patterns, $profile, $units, $extras, $draft, $site->schema)->suggested();
        $gate = (new LayoutGate)->filter($this->planned->kept, $units, $extras, $site->schema, $suggested?->id);

        if ($gate['dropped'] !== []) {
            $this->logger->info('Ghostwriter: '.count($gate['dropped']).' of '.(count($this->planned->kept) - 1).' layouts were not offered, as they look too like another ('.implode('; ', array_map(fn (string $id, string $why) => "{$id}: {$why}", array_keys($gate['dropped']), $gate['dropped'])).').', ['agent' => 'layout-planner', 'notOffered' => $gate['dropped']]);
        }

        $plans = Plans::of($writer, array_slice($gate['kept'], 1));
        $this->store($session, $plans, $units, $extras, $draft, $site, $patterns, $profile);

        if ($session->plan !== null && $this->plans($session)->get($session->plan) === null) {
            $session->plan = null;
        }

        $session->usage = ['input' => (int) ($session->usage['input'] ?? 0) + $usage->input, 'output' => (int) ($session->usage['output'] ?? 0) + $usage->output] + $session->usage;

        return $usage;
    }

    /**
     * @param  list<array{id: string, field: string, sequence: list<string>, count: int, share: float, example: string, exampleId: int|string|null}>|null  $patterns
     * @param  array<string, array{headings: float, lists: float, quotes: float, entries: int}>|null  $profile
     */
    private function store(Session $session, Plans $plans, Units $units, Extras $extras, Draft $draft, LayoutContext $site, ?array $patterns = null, ?array $profile = null): void
    {
        if ($patterns === null || $profile === null) {
            $sitePatterns = new SitePatterns($this->layouts->richText);
            $patterns = $sitePatterns->find($site->schema, $site->entries);
            $profile = $sitePatterns->profile($site->schema, $site->entries);
        }

        $fresh = new Plans(array_values(array_filter($plans->all(), fn (Plan $plan) => ! $plan->stale)));
        $ranked = (new Candidates)->rank($fresh, $patterns, $profile, $units, $extras, $draft, $site->schema);
        $session->plans = array_map(fn (Plan $plan) => ($ranked->get($plan->id) ?? $plan)->toArray(), $plans->all());
    }

    private function units(Session $session, Draft $draft, LayoutContext $site): Units
    {
        return Units::fromDraft($draft, $site->schema, $this->layouts->richText)->restore($session->units);
    }

    private static function draft(?string $yaml): ?Draft
    {
        if ($yaml === null || trim($yaml) === '') {
            return null;
        }

        try {
            return Draft::parse($yaml);
        } catch (Throwable) {
            return null;
        }
    }
}
