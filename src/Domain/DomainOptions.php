<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain;

use InvalidArgumentException;

/**
 * The settings the domain rules take, and the few things the three addons
 * genuinely do differently, as presets: statamic(), craft() and filament()
 * keep each addon's behaviour as it was before core, apart from what the
 * decisions unified (docs/domain-unification.md lists both).
 */
final class DomainOptions
{
    /** Said while someone else's request runs (E7). */
    public const WAITING_ON_OTHER = '{name} is waiting on Ghostwriter. Try again when it has answered.';

    /** Said of work that stopped without finishing (CRA-2). */
    public const STOPPED = 'This stopped before it finished, probably cut off by a time limit on the server. Try again.';

    /** Said when work has waited for a queue worker for longer than Waiting::AFTER. */
    public const QUEUE_NOTICE = 'Still waiting for a queue worker to pick this up. Is “{command}” running?';

    /**
     * @param  Format  $format  How the addon stores its records.
     * @param  bool  $shared  Conversations are shared with everyone who may use Ghostwriter (E7, `shared_conversations`, on by default); off, each is its starter's alone.
     * @param  int  $jobTimeout  The time limit on one model call, in seconds (the addon's timeout setting). Work marked as running for longer than the job limit (timeout × 3 + 60) plus 120 seconds has stopped (CRA-2).
     * @param  bool  $unownedIsAnyones  A session with no starter on record (from before starters were kept) may be opened by anyone (Statamic).
     * @param  bool  $adminSeesAll  An admin (a Statamic super user) may open and delete private conversations too.
     * @param  bool  $editFinishedOnApply  A piece editing an existing record is finished once its changes are put into the form (Craft, Filament); otherwise once the record is saved after that (Statamic).
     * @param  bool  $recordFirst  A new piece whose record is saved is finished, and shown as saved, even while Ghostwriter works on it again (Filament).
     * @param  bool  $questionsMeanAsking  A piece whose last reply asked something is at the "asking" stage even with a draft (Filament).
     * @param  array<string, string>  $stageNames  The addon's names for core's stages (Session\Progress::STAGES), where they differ.
     * @param  string  $waitingOnOther  Busy::messageFor()'s text, with {name}.
     * @param  string  $queueNotice  Waiting::notice()'s text, with {command}.
     */
    public function __construct(
        public readonly Format $format,
        public readonly bool $shared = true,
        public readonly int $jobTimeout = 300,
        public readonly bool $unownedIsAnyones = false,
        public readonly bool $adminSeesAll = false,
        public readonly bool $editFinishedOnApply = false,
        public readonly bool $recordFirst = false,
        public readonly bool $questionsMeanAsking = false,
        public readonly array $stageNames = [],
        public readonly string $waitingOnOther = self::WAITING_ON_OTHER,
        public readonly string $queueNotice = self::QUEUE_NOTICE,
    ) {
        if ($jobTimeout < 1) {
            throw new InvalidArgumentException('The job time limit must be at least a second.');
        }
    }

    public static function statamic(bool $shared = true, int $jobTimeout = 300): self
    {
        return new self(
            Format::Statamic,
            shared: $shared,
            jobTimeout: $jobTimeout,
            unownedIsAnyones: true,
            adminSeesAll: true,
        );
    }

    public static function craft(bool $shared = true, int $jobTimeout = 300): self
    {
        return new self(
            Format::Craft,
            shared: $shared,
            jobTimeout: $jobTimeout,
            editFinishedOnApply: true,
            waitingOnOther: '{name} is waiting on Ghostwriter.',
        );
    }

    public static function filament(bool $shared = true, int $jobTimeout = 300): self
    {
        return new self(
            Format::Filament,
            shared: $shared,
            jobTimeout: $jobTimeout,
            editFinishedOnApply: true,
            recordFirst: true,
            questionsMeanAsking: true,
            stageNames: ['working' => 'writing', 'draft' => 'ready', 'interview' => 'asking'],
            queueNotice: 'Still waiting for a queue worker to pick this up. Is `{command}` running?',
        );
    }

    /**
     * Seconds after which work still marked as running is taken to have
     * stopped. A queued job is given three tries of the time limit plus a
     * minute (timeout × 3 + 60, the addons' job limit), so work is only
     * stale once that has run out, with two minutes' margin on top: a
     * turn still retrying is never marked stopped while its job may run,
     * which would let a second run start on the same conversation.
     */
    public function staleAfter(): int
    {
        return $this->jobTimeout * 3 + 60 + 120;
    }

    public function with(?bool $shared = null, ?int $jobTimeout = null): self
    {
        return new self(
            $this->format,
            $shared ?? $this->shared,
            $jobTimeout ?? $this->jobTimeout,
            $this->unownedIsAnyones,
            $this->adminSeesAll,
            $this->editFinishedOnApply,
            $this->recordFirst,
            $this->questionsMeanAsking,
            $this->stageNames,
            $this->waitingOnOther,
            $this->queueNotice,
        );
    }
}
