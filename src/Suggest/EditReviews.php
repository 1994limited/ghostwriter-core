<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Busy;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Conflict;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Lock;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotFound;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Ulid;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Viewer;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Studio\UnreadableReply;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * What the addons call for Suggest edits. It holds no CMS code: the addon
 * builds the CheckContext and ReviewInput from its entry, checks
 * permissions (EditReviewAccess), and queues run().
 *
 *     $reviews->preview($context, $ref);                 // the free half, at once: no model
 *     $review = $reviews->start($ref, $viewer, $now);     // claim; queue run()
 *     $reviews->run($review->id, $input, $now);           // the queued job: the call(s), validation, store
 *     $reviews->decide($id, $suggestionId, SuggestionState::Dismissed, $viewer, $now);
 *     $reviews->undo($id, $suggestionId, $viewer, $now);
 *     $reviews->another($id, $suggestionId, $input, $now); // "Write another": one reworder call
 *     $reviews->saved($ref, $savedContext, $now);         // after a save: Done and Stale (Reconciler)
 *     $reviews->expire($now);                             // daily: unactioned suggestions after 14 days
 *     $reviews->quieted($ref, $now);                      // for CheckContext: dismissals that stick
 *
 * Every change is made under the entry's lock, with the store's version
 * check, so two people deciding at once never lose a decision.
 */
final class EditReviews
{
    /** A run that has held the entry this long (seconds) is taken to have died. */
    public const STALE_RUN = 900;

    private readonly SuggestionValidator $validator;

    private readonly Reconciler $reconciler;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly EditReviewStore $store,
        private readonly Lock $lock,
        private readonly Studio $studio,
        ?SuggestionValidator $validator = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->validator = $validator ?? new SuggestionValidator;
        $this->reconciler = new Reconciler;
        $this->logger = $logger ?? new NullLogger;
    }

    /**
     * The free half, at once and at no cost: the findings as suggestions
     * (Finding::toSuggestion()), and the latest stored review, re-checked
     * against the form's current text.
     *
     * @return array{findings: list<array<string, mixed>>, review: array<string, mixed>|null}
     */
    public function preview(CheckContext $context, EntryRef $entry, ?Findings $findings = null): array
    {
        $free = [];

        foreach (($findings ?? Findings::standard())->find($context) as $finding) {
            $suggestion = $finding->toSuggestion();

            if ($suggestion !== null) {
                $free[] = $suggestion->toArray();
            }
        }

        $latest = $this->store->latestFor($entry);

        if ($latest !== null && ! $latest->status->isRunning()) {
            $latest = $this->reconciler->reconcile(clone $latest, $context, $context->now);
        }

        return ['findings' => $free, 'review' => $latest?->toArray()];
    }

    /**
     * Claims the entry and queues a review: returns at once.
     *
     * @throws Busy while another run holds the entry, naming whose it is.
     */
    public function start(EntryRef $entry, Viewer $viewer, DateTimeImmutable $now): EditReview
    {
        return $this->lock->run($this->key($entry), function () use ($entry, $viewer, $now) {
            $latest = $this->store->latestFor($entry);

            if ($latest !== null && $latest->status->isRunning() && $latest->createdAt !== null && new DateTimeImmutable($latest->createdAt) > $now->modify('-'.self::STALE_RUN.' seconds')) {
                throw new Busy('Ghostwriter is already reviewing this page.', $viewer->is($latest->startedBy) ? null : $latest->startedBy, '{name} is reviewing this page. It opens here when it is ready.');
            }

            return $this->store->save(new EditReview(Ulid::generate(), $entry, ReviewStatus::Queued, $viewer->id, createdAt: $now->format(DATE_ATOM)));
        });
    }

    /**
     * The queued job's work: the call (or calls, for a long page), the
     * validation, and the review stored Ready. Decisions on the same
     * suggestions in earlier reviews carry over, by id. When the call fails
     * or can't be read, the review is Failed and keeps the free findings.
     */
    public function run(string $reviewId, ReviewInput $input, DateTimeImmutable $now): EditReview
    {
        $review = $this->load($reviewId);
        $review->status = ReviewStatus::Running;
        $review->contentHash = self::contentHash($input->context);
        $review = $this->store->save($review);

        $reply = new SuggestionReply(calls: $input->calls());
        $error = null;

        try {
            $result = $this->studio->suggestEdits($input);
            $reply = $result->value;
            $review->usage = ['input' => $result->usage->input, 'output' => $result->usage->output];

            if ($reply->unreadable()) {
                $error = 'unreadable';
            }
        } catch (ProviderException|UnreadableReply $exception) {
            $error = $exception->getMessage();
            $this->logger->warning("Ghostwriter: a review failed: {$error}", ['entry' => $input->context->entry?->key()]);
        }

        $validated = $this->validator->validate($reply, $input);

        return $this->lock->run($this->key($review->entry), function () use ($reviewId, $validated, $reply, $input, $now, $error, $review) {
            $fresh = $this->load($reviewId);
            $fresh->usage = $review->usage;
            $fresh->status = $error === null ? ReviewStatus::Ready : ReviewStatus::Failed;
            $fresh->error = $error;
            $fresh->calls = $reply->calls;
            $fresh->truncated = $reply->truncated;
            $fresh->dropped = $validated->dropped;
            $fresh->findings = array_values(array_map(fn (Finding $finding) => $finding->toArray(), $input->findings));
            $fresh->suggestions = array_map(fn (Suggestion $suggestion) => $suggestion->toArray(), $validated->suggestions);
            $fresh->finishedAt = $now->format(DATE_ATOM);
            $fresh->expiresAt = $now->modify('+'.EditReview::EXPIRES_DAYS.' days')->format(DATE_ATOM);
            $this->carryOver($fresh);

            if ($validated->dropped !== []) {
                $this->logger->info('Ghostwriter: review suggestions dropped: '.json_encode($validated->dropped), ['review' => $reviewId]);
            }

            return $this->store->save($fresh);
        });
    }

    /**
     * Accept, Dismiss, or It's still right, for everyone who sees the
     * review. `$answer` is a Fact to check's answer, `$text` the words the
     * editor put in when they weren't the suggestion's own.
     */
    public function decide(string $reviewId, string $suggestionId, SuggestionState $state, Viewer $by, DateTimeImmutable $now, ?string $answer = null, ?string $text = null): EditReview
    {
        return $this->change($reviewId, fn (EditReview $review) => $review->decide($suggestionId, $state, $by, $now, $answer, $text));
    }

    public function undo(string $reviewId, string $suggestionId, Viewer $by, DateTimeImmutable $now): EditReview
    {
        return $this->change($reviewId, fn (EditReview $review) => $review->undo($suggestionId, $by, $now));
    }

    /**
     * "Write another (uses Ghostwriter)": one reworder call for one
     * suggestion, once its stored alternatives have all been shown. Only
     * versions that pass the first call's checks are kept; none means
     * "I couldn't find another way to say it that keeps to the facts".
     *
     * @return list<string> The new versions, at most two.
     */
    public function another(string $reviewId, string $suggestionId, ReviewInput $input, DateTimeImmutable $now): array
    {
        $review = $this->load($reviewId);
        $suggestion = $review->find($suggestionId) ?? throw new NotFound('There is no such suggestion in this review.');

        if (! $suggestion->state->isOpen()) {
            throw new Conflict('This suggestion has been decided.');
        }

        $result = $this->studio->reword(RewordRequest::for($suggestion, $input->context, $input->writer->voice, $review->versions[$suggestionId] ?? []));
        $versions = array_values(array_filter($result->value, fn (string $version) => $this->validator->acceptsVersion($suggestion, $version, $input)));

        $this->change($reviewId, function (EditReview $review) use ($suggestionId, $versions, $result) {
            $review->versions[$suggestionId] = [...($review->versions[$suggestionId] ?? []), ...$versions];
            $review->usage = ['input' => $review->usage['input'] + $result->usage->input, 'output' => $review->usage['output'] + $result->usage->output];
        });

        return $versions;
    }

    /**
     * After the entry is saved: suggestions whose change is in the saved
     * text are Done, those that no longer fit are Stale (Reconciler).
     */
    public function saved(EntryRef $entry, CheckContext $saved, DateTimeImmutable $now): ?EditReview
    {
        $latest = $this->store->latestFor($entry);

        if ($latest === null || $latest->status->isRunning()) {
            return null;
        }

        return $this->change($latest->id, fn (EditReview $review) => $this->reconciler->reconcile($review, $saved, $now));
    }

    /**
     * Expires suggestions nobody acted on, EditReview::EXPIRES_DAYS after
     * their review finished. The reviews and their decisions are kept.
     * Returns how many suggestions expired.
     */
    public function expire(DateTimeImmutable $now): int
    {
        $expired = 0;

        foreach ($this->store->dueToExpire($now) as $id) {
            $this->change($id, function (EditReview $review) use ($now, &$expired) {
                $expired += $review->expire($now);
            });
        }

        return $expired;
    }

    /**
     * The decisions that keep findings quiet on an entry: each suggestion's
     * last Dismissed or It's still right, across the entry's history, for
     * 12 months or until its passage changes. Pass it to CheckContext.
     */
    public function quieted(EntryRef $entry, DateTimeImmutable $now): Quieted
    {
        $quiets = [];

        foreach (array_reverse($this->store->history($entry)) as $review) {
            foreach ($review->all() as $suggestion) {
                $decision = $review->lastDecision($suggestion->id);

                if ($decision === null) {
                    continue;
                }

                if (in_array($decision->state, [SuggestionState::Dismissed, SuggestionState::Confirmed], true)) {
                    $at = new DateTimeImmutable($decision->at);
                    $quiets[$suggestion->id] = new Quiet($suggestion->id, $suggestion->anchor->passage, Quieted::until($at)->format(DATE_ATOM), $decision->state === SuggestionState::Confirmed ? Quiet::CONFIRMED : Quiet::DISMISSED, $decision->by, $decision->at);
                } elseif ($decision->state === SuggestionState::Open) {
                    unset($quiets[$suggestion->id]);
                }
            }
        }

        return new Quieted(array_values(array_filter($quiets, fn (Quiet $quiet) => new DateTimeImmutable($quiet->until) > $now)));
    }

    /** The content a review was of, to tell whether a stored review still fits the page. */
    public static function contentHash(CheckContext $context): string
    {
        return substr(sha1(implode("\n", array_map(fn (CheckText $text) => $text->visit->path->toString().'='.$text->plain, $context->texts()))), 0, 16);
    }

    /**
     * One change to a review under the entry's lock, retried once when
     * someone else saved first.
     *
     * @param  callable(EditReview): mixed  $change
     */
    private function change(string $reviewId, callable $change): EditReview
    {
        $review = $this->load($reviewId);

        return $this->lock->run($this->key($review->entry), function () use ($reviewId, $change) {
            for ($attempt = 0; ; $attempt++) {
                $review = $this->load($reviewId);
                $change($review);

                try {
                    return $this->store->save($review);
                } catch (Conflict $conflict) {
                    if ($attempt >= 1) {
                        throw $conflict;
                    }
                }
            }
        });
    }

    /** Decisions from earlier reviews of the entry, on the same suggestions, carry over. */
    private function carryOver(EditReview $review): void
    {
        $ids = array_map(fn (Suggestion $suggestion) => $suggestion->id, $review->all());

        foreach ($this->store->history($review->entry) as $earlier) {
            if ($earlier->id === $review->id) {
                continue;
            }

            foreach ($ids as $id) {
                $decision = $earlier->lastDecision($id);

                if ($decision !== null && $decision->state->isDecision() && $decision->state !== SuggestionState::Accepted && $review->lastDecision($id) === null) {
                    $review->decisions[] = $decision;
                }
            }
        }
    }

    private function load(string $reviewId): EditReview
    {
        return $this->store->find($reviewId) ?? throw new NotFound('There is no such review.');
    }

    private function key(EntryRef $entry): string
    {
        return 'edit-review:'.$entry->key();
    }
}
