<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Conflict;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Viewer;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;

/**
 * One review of one entry, shared by everyone who can edit it (E7): the
 * suggestions it made, and every decision on them, kept as the page's
 * history. Its own record, not the writing Session.
 *
 * - **History.** decisions are only added (Decision); a suggestion's state
 *   is its last decision's. Undo adds an Open one.
 * - **Expiry.** Suggestions nobody acted on expire EXPIRES_DAYS (14)
 *   after the review finished: expire() marks them Expired and drops their
 *   text. Decided ones, and the decisions, are kept.
 * - **One run per entry at a time:** EditReviews::start() refuses while
 *   a run holds the entry (Domain\Busy, with whose run it is).
 * - `version` is for optimistic concurrency: the store refuses a save of
 *   an older version (Domain\Conflict).
 * - `checked`: the candidates the review call (or the verifier) looked at
 *   in context and dropped, as `{id, passage, reason, by}`
 *   (ValidatedReview::$checked). Not shown; EditReviews::quieted() keeps
 *   them quiet (Quiet::CHECKED).
 * - `verified`: the verifier's verdict on each suggestion shown, by id:
 *   'keep' or 'fix'. Empty when it didn't run or failed (`verifyError`).
 * - `error`: why a Failed review failed: UNREADABLE (a code: show
 *   errorMessage()'s words), or the provider's own message.
 */
final class EditReview
{
    public const EXPIRES_DAYS = 14;

    /** The `error` (or `verifyError`) when no reply could be read. */
    public const UNREADABLE = 'unreadable';

    /**
     * @param  list<array<string, mixed>>  $suggestions  Suggestion::toArray()
     * @param  list<Decision>  $decisions
     * @param  list<array<string, mixed>>  $findings  Finding::toArray(): what it was given
     * @param  array<string, int>  $dropped
     * @param  array{input: int, output: int}  $usage
     * @param  array<string, list<string>>  $versions  "Write another" versions, by suggestion id
     * @param  list<array{id: string, passage: ?string, reason: string, by: string}>  $checked
     * @param  array<string, string>  $verified
     */
    public function __construct(
        public readonly string $id,
        public readonly EntryRef $entry,
        public ReviewStatus $status = ReviewStatus::Queued,
        public int|string|null $startedBy = null,
        public string $contentHash = '',
        public array $suggestions = [],
        public array $decisions = [],
        public array $findings = [],
        public array $dropped = [],
        public array $usage = ['input' => 0, 'output' => 0],
        public int $calls = 0,
        public int $truncated = 0,
        public array $versions = [],
        public int $version = 0,
        public ?string $error = null,
        public ?string $createdAt = null,
        public ?string $finishedAt = null,
        public ?string $expiresAt = null,
        public array $checked = [],
        public array $verified = [],
        public ?string $verifyError = null,
    ) {}

    /**
     * A failed review's error in plain words for the editor, as a Message
     * (`suggest.review.error.*`), for a code core sets. Null when there is
     * no error, or it is already words (a provider's message): show it as
     * it is.
     */
    public static function errorMessage(?string $error): ?Message
    {
        return match ($error) {
            self::UNREADABLE => new Message('suggest.review.error.unreadable'),
            default => null,
        };
    }

    /**
     * @return list<Suggestion> With their current state.
     */
    public function all(): array
    {
        $states = $this->states();

        return array_values(array_map(function (array $array) use ($states) {
            $suggestion = Suggestion::fromArray($array);
            $suggestion->state = $states[$suggestion->id] ?? $suggestion->state;

            return $suggestion;
        }, $this->suggestions));
    }

    /**
     * Still to step through.
     *
     * @return list<Suggestion>
     */
    public function open(): array
    {
        return array_values(array_filter($this->all(), fn (Suggestion $suggestion) => $suggestion->state->isOpen()));
    }

    public function find(string $suggestionId): ?Suggestion
    {
        foreach ($this->all() as $suggestion) {
            if ($suggestion->id === $suggestionId) {
                return $suggestion;
            }
        }

        return null;
    }

    /**
     * Each suggestion's state: its last decision's.
     *
     * @return array<string, SuggestionState>
     */
    public function states(): array
    {
        $states = [];

        foreach ($this->decisions as $decision) {
            $states[$decision->suggestion] = $decision->state;
        }

        return $states;
    }

    /** The decision a suggestion's state comes from, if any. */
    public function lastDecision(string $suggestionId): ?Decision
    {
        $last = null;

        foreach ($this->decisions as $decision) {
            if ($decision->suggestion === $suggestionId) {
                $last = $decision;
            }
        }

        return $last;
    }

    /**
     * A person's decision: Accepted, Dismissed or Confirmed.
     *
     * @throws Conflict for a suggestion that isn't in the review, "It's
     *                  still right" on anything but a Fact to check or an Out
     *                  of date suggestion, or a suggestion that's done, stale
     *                  or expired.
     */
    public function decide(string $suggestionId, SuggestionState $state, Viewer $by, DateTimeImmutable $now, ?string $answer = null, ?string $text = null): Decision
    {
        $suggestion = $this->find($suggestionId) ?? throw new Conflict('There is no such suggestion in this review.');

        if (! $state->isDecision()) {
            throw new Conflict('Only Accept, Dismiss and It\'s still right are decisions.');
        }

        if (in_array($suggestion->state, [SuggestionState::Done, SuggestionState::Stale, SuggestionState::Expired], true)) {
            throw new Conflict('This suggestion can\'t be changed any more.');
        }

        if ($state === SuggestionState::Confirmed && ! in_array($suggestion->category, [Category::FactToCheck, Category::OutOfDate], true)) {
            throw new Conflict('Only a fact to check or a dated claim can be confirmed.');
        }

        if ($suggestion->state === $state) {
            return $this->lastDecision($suggestionId) ?? Decision::now($suggestionId, $state, $by->id, $now, $answer, $text);
        }

        $decision = Decision::now($suggestionId, $state, $by->id, $now, $answer, $text);
        $this->decisions[] = $decision;

        return $decision;
    }

    /**
     * Undo: the suggestion is open again; its earlier decision stays in the
     * history.
     *
     * @throws Conflict when there is nothing to undo.
     */
    public function undo(string $suggestionId, Viewer $by, DateTimeImmutable $now): Decision
    {
        $suggestion = $this->find($suggestionId) ?? throw new Conflict('There is no such suggestion in this review.');

        if (! $suggestion->state->isDecision()) {
            throw new Conflict('There is nothing to undo.');
        }

        $decision = Decision::now($suggestionId, SuggestionState::Open, $by->id, $now);
        $this->decisions[] = $decision;

        return $decision;
    }

    /** What core decides (Done, Stale, Expired), with no person. */
    public function settle(string $suggestionId, SuggestionState $state, DateTimeImmutable $now): void
    {
        $this->decisions[] = Decision::now($suggestionId, $state, null, $now);
    }

    /**
     * Expires every suggestion still open: marked Expired, its words gone.
     * Decided suggestions and every decision are kept. Returns how many.
     */
    public function expire(DateTimeImmutable $now): int
    {
        $expired = 0;
        $states = $this->states();
        $kept = [];

        foreach ($this->suggestions as $array) {
            $id = is_string($array['id'] ?? null) ? $array['id'] : '';
            $state = $states[$id] ?? SuggestionState::tryFrom(is_string($array['state'] ?? null) ? $array['state'] : '') ?? SuggestionState::Open;

            if ($state === SuggestionState::Open) {
                $this->settle($id, SuggestionState::Expired, $now);
                $array = array_intersect_key($array, array_flip(['id', 'category', 'label', 'anchor', 'finding', 'free'])) + ['state' => SuggestionState::Expired->value];
                $expired++;
            }

            $kept[] = $array;
        }

        $this->suggestions = $kept;
        $this->versions = [];

        return $expired;
    }

    public function isDue(DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && new DateTimeImmutable($this->expiresAt) <= $now && $this->open() !== [];
    }

    /**
     * For the guide: the review, its suggestions with their states, and
     * the history.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'entry' => $this->entry->toArray(),
            'status' => $this->status->value,
            'startedBy' => $this->startedBy,
            'contentHash' => $this->contentHash,
            'suggestions' => array_map(fn (Suggestion $suggestion) => $suggestion->toArray(), $this->all()),
            'decisions' => array_map(fn (Decision $decision) => $decision->toArray(), $this->decisions),
            'findings' => $this->findings,
            'dropped' => $this->dropped,
            'usage' => $this->usage,
            'calls' => $this->calls,
            'truncated' => $this->truncated,
            'versions' => $this->versions,
            'version' => $this->version,
            'error' => $this->error,
            'createdAt' => $this->createdAt,
            'finishedAt' => $this->finishedAt,
            'expiresAt' => $this->expiresAt,
            'checked' => $this->checked,
            'verified' => $this->verified,
            'verifyError' => $this->verifyError,
        ];
    }

    /**
     * @param  array<mixed>  $a  From toArray().
     */
    public static function fromArray(array $a): self
    {
        $by = $a['startedBy'] ?? null;
        $string = fn (string $key) => is_string($a[$key] ?? null) ? $a[$key] : null;
        $int = fn (string $key) => is_int($a[$key] ?? null) ? $a[$key] : 0;
        $lists = fn (string $key) => array_values(array_filter(is_array($a[$key] ?? null) ? $a[$key] : [], 'is_array'));
        $usage = is_array($a['usage'] ?? null) ? $a['usage'] : [];
        $versions = [];

        foreach (is_array($a['versions'] ?? null) ? $a['versions'] : [] as $id => $list) {
            if (is_string($id) && is_array($list)) {
                $versions[$id] = array_values(array_filter($list, 'is_string'));
            }
        }

        $dropped = [];

        foreach (is_array($a['dropped'] ?? null) ? $a['dropped'] : [] as $why => $count) {
            if (is_string($why) && is_int($count)) {
                $dropped[$why] = $count;
            }
        }

        $checked = [];

        foreach ($lists('checked') as $item) {
            if (is_string($item['id'] ?? null)) {
                $checked[] = [
                    'id' => $item['id'],
                    'passage' => is_string($item['passage'] ?? null) ? $item['passage'] : null,
                    'reason' => is_string($item['reason'] ?? null) ? $item['reason'] : '',
                    'by' => is_string($item['by'] ?? null) ? $item['by'] : 'reviewer',
                ];
            }
        }

        $verified = [];

        foreach (is_array($a['verified'] ?? null) ? $a['verified'] : [] as $id => $verdict) {
            if (is_string($id) && is_string($verdict)) {
                $verified[$id] = $verdict;
            }
        }

        return new self(
            $string('id') ?? '',
            EntryRef::fromArray(is_array($a['entry'] ?? null) ? $a['entry'] : []),
            ReviewStatus::tryFrom($string('status') ?? '') ?? ReviewStatus::Failed,
            is_int($by) || is_string($by) ? $by : null,
            $string('contentHash') ?? '',
            array_map(fn (array $s) => array_combine(array_map('strval', array_keys($s)), array_values($s)), $lists('suggestions')),
            array_map(fn (array $d) => Decision::fromArray($d), $lists('decisions')),
            array_map(fn (array $f) => array_combine(array_map('strval', array_keys($f)), array_values($f)), $lists('findings')),
            $dropped,
            ['input' => is_int($usage['input'] ?? null) ? $usage['input'] : 0, 'output' => is_int($usage['output'] ?? null) ? $usage['output'] : 0],
            $int('calls'),
            $int('truncated'),
            $versions,
            $int('version'),
            $string('error'),
            $string('createdAt'),
            $string('finishedAt'),
            $string('expiresAt'),
            $checked,
            $verified,
            $string('verifyError'),
        );
    }
}
