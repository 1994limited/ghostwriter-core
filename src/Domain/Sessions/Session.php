<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Domain\DomainOptions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\RoundTrips;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Viewer;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Asks;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;

/**
 * One piece being written or edited: the brief it started from, the
 * conversation, the draft as it stands, the images chosen for it and the
 * record it is for. The same thing as Statamic's and Craft's `Session` and
 * Filament's `Session` model.
 *
 * Who did what is kept by user ID as the CMS stores it: `startedBy` (the
 * starter, `user_id`), `touchedBy` (who last did something to it) and
 * `runBy` (whose request Ghostwriter is answering now, or last answered).
 *
 * The record a piece is for:
 * - `source`: the existing record being edited (Statamic's and Craft's
 *   `source`, Filament's `record_key` when `editing`).
 * - `recordId`: the record a new piece became (Statamic's `entry_id`,
 *   Craft's `element_id`, which is set from the start as Craft writes into
 *   an unpublished draft, and Filament's `record_key` once saved).
 *
 * Read one with fromArray($stored, $format); toArray() gives the stored
 * shape back, unchanged keys exactly as they were.
 */
final class Session
{
    use RoundTrips;

    public const IDLE = 'idle';

    public const WORKING = 'working';

    public const FAILED = 'failed';

    /**
     * The key on a message that is a note to the writer, not something a
     * person said: sent to the model with the rest, never shown in the
     * conversation (BriefThread::visible()). See addNote().
     */
    public const NOTE = 'note';

    /**
     * @param  array<string, mixed>  $answers
     * @param  array<int, array<string, mixed>>  $messages  Each with `role`, `content` and `at`; a person's with `by`; the writer's may say whether it `asks` (and what, under `asked`: Studio\Asks), and what it did to the `draft`; a person's answers to them are under `answers`.
     * @param  array<string, int>  $usage  Tokens, `input` and `output`.
     * @param  array<int, int|string>  $examples  Records the piece is modelled on, chosen with the brief.
     * @param  array<string, array<string, mixed>>  $images  Images chosen or made for the draft, by field (see SessionImages).
     * @param  int|string|null  $key  The host's own row key beside the ID (Filament's numeric `id`).
     * @param  string|null  $group  The collection, section or resource, where the record keeps it (Filament).
     * @param  string|null  $variant  The blueprint or entry type of the record being edited (Statamic, Craft).
     * @param  int|null  $siteId  The site the record is in (Craft).
     * @param  array<int, array<string, string>>  $gaps  What the draft left for a person when it was applied (Gaps\SessionGaps::toArray()). Stored only once there is something in it, under `gaps` (Filament: a JSON column the addon adds).
     * @param  array<string, mixed>  $units  The draft's unit ids (Arrange\Units::sidecar()), carried from turn to turn. Stored like `gaps`: only once there is something in it, under `units` (Filament: a JSON column the addon adds).
     * @param  list<array<string, mixed>>  $extras  The extras the writer prepared with the draft (Arrange\Extras\Extras::toArray()), kept until it sends new ones. Stored like `units`, under `extras`.
     * @param  list<array<string, mixed>>  $plans  The layouts (Arrange\Plans::toArray()): the writer's first, then the planner's. Stored like `units`, under `plans`.
     * @param  string|null  $plan  The chosen layout's id, shared by everyone on the piece; null for the writer's. Stored under `plan` once chosen.
     * @param  array<string, mixed>  $seo  What the SEO pass did to the draft (Seo\SeoState): the links it added and those removed since, and its notice. Stored like `units`, under `seo` (Filament: a JSON column the addon adds).
     */
    public function __construct(
        public readonly Format $format,
        public readonly string $id,
        public string $kind,
        public array $answers = [],
        public array $messages = [],
        public ?string $draft = null,
        public string $status = self::IDLE,
        public ?string $error = null,
        public int|string|null $recordId = null,
        public int|string|null $source = null,
        public int|string|null $startedBy = null,
        public int|string|null $touchedBy = null,
        public int|string|null $runBy = null,
        public array $usage = ['input' => 0, 'output' => 0],
        public array $examples = [],
        public array $images = [],
        public ?string $appliedAt = null,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
        public ?string $startedWorkingAt = null,
        public int|string|null $key = null,
        public ?string $group = null,
        public ?string $variant = null,
        public ?int $siteId = null,
        public bool $editing = false,
        public array $gaps = [],
        public array $units = [],
        public array $extras = [],
        public array $plans = [],
        public ?string $plan = null,
        public array $seo = [],
    ) {
        $this->editing = $editing || $source !== null;
    }

    /**
     * A new piece, from a brief: idle until claim() starts Ghostwriter on it.
     *
     * @param  array<string, mixed>  $answers
     * @param  array<int, int|string>  $examples
     */
    public static function start(Format $format, string $kind, array $answers, int|string|null $startedBy = null, array $examples = [], ?DateTimeInterface $now = null, ?string $group = null): self
    {
        $at = $format->stamp($now ?? new DateTimeImmutable);

        return new self(
            $format,
            $format->newId(),
            $kind,
            answers: $answers,
            startedBy: self::user($format, $startedBy),
            examples: $examples,
            createdAt: $at,
            updatedAt: $at,
            group: $group,
        );
    }

    /**
     * @param  array<string, mixed>  $data  The stored record, in the format's shape.
     */
    public static function fromArray(array $data, Format $format): self
    {
        $session = match ($format) {
            Format::Statamic => self::fromStatamic($data),
            Format::Craft => self::fromCraft($data),
            Format::Filament => self::fromFilament($data),
        };

        return $session->remember($data, $format);
    }

    /**
     * The stored shape: what fromArray() read, with this session's changes.
     *
     * @return array<string, mixed>
     */
    public function toArray(?Format $format = null): array
    {
        return $this->emit($format ?? $this->format);
    }

    public function isWorking(): bool
    {
        return $this->status === self::WORKING;
    }

    public function hasFailed(): bool
    {
        return $this->status === self::FAILED;
    }

    /**
     * Whether it edits a record that existed before, rather than writing a new one.
     */
    public function isEditing(): bool
    {
        return $this->editing;
    }

    /**
     * @param  array<string, mixed>  $extra  More about the message: `asks`, `draft`, `editing`.
     */
    public function addMessage(string $role, string $content, int|string|null $by = null, array $extra = [], ?DateTimeInterface $now = null): void
    {
        $message = ['role' => $role, 'content' => $content, 'at' => ($now ?? new DateTimeImmutable)->format(DATE_ATOM)];

        if ($by !== null && $by !== '') {
            $message['by'] = self::user($this->format, $by);
        }

        $this->messages[] = $message + $extra;
    }

    /**
     * A note to the writer in the person's turn, such as "This entry already
     * exists on the site…" when an existing record becomes the draft. The
     * writer reads it as part of the conversation; the conversation the
     * person sees leaves it out, and it is no one's (no `by`).
     *
     * @param  array<string, mixed>  $extra
     */
    public function addNote(string $content, array $extra = [], ?DateTimeInterface $now = null): void
    {
        $this->addMessage('user', $content, null, [self::NOTE => true] + $extra, $now);
    }

    /**
     * Whether a message is a note to the writer (addNote()). Notes written
     * before NOTE existed are known too: the person's turn that says an
     * existing record became the draft, followed by Ghostwriter's reply
     * flagged `editing` ("I have the entry as it stands…"), which only
     * ever follows that note.
     *
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>|null  $next  The message after it, if any.
     */
    public static function isNote(array $message, ?array $next = null): bool
    {
        if (! empty($message[self::NOTE])) {
            return true;
        }

        if (($message['role'] ?? null) !== 'user' || BriefThread::step($message) !== null) {
            return false;
        }

        return ($next['role'] ?? null) === 'assistant' && ! empty($next['editing']);
    }

    /**
     * Someone did something to the piece: asked for something, edited the
     * draft, chose an image or put it into a form.
     */
    public function touch(int|string|null $userId): void
    {
        if ($userId !== null && $userId !== '') {
            $this->touchedBy = self::user($this->format, $userId);
        }
    }

    /**
     * Start Ghostwriter on someone's request. It runs one request at a time:
     * this succeeds only when the piece is not already working, or its
     * work has stopped without saying so (isStale()). With `$from`, only
     * when it is in that state (a retry claims from failed).
     *
     * Call it with the session read afresh under its lock (SessionGuard
     * does), so two requests can't both claim it.
     */
    public function claim(int|string|null $by, DomainOptions $options, ?DateTimeInterface $now = null, ?string $from = null): bool
    {
        $now ??= new DateTimeImmutable;

        if ($from !== null ? $this->status !== $from : ($this->isWorking() && ! $this->isStale($options, $now))) {
            return false;
        }

        $this->status = self::WORKING;
        $this->error = null;
        $this->runBy = $by === null || $by === '' ? null : self::user($this->format, $by);
        $this->startedWorkingAt = $this->format->stamp($now);
        $this->touch($by);

        return true;
    }

    /**
     * Whether it is marked as working but has been for longer than any job
     * is allowed: its process was stopped (a time limit, a restart) before
     * it could say so, and nothing else ever will (CRA-2). Timed from when
     * the run was claimed, or the last save where that isn't recorded.
     */
    public function isStale(DomainOptions $options, ?DateTimeInterface $now = null): bool
    {
        if (! $this->isWorking()) {
            return false;
        }

        $since = Format::parse($this->startedWorkingAt ?? $this->updatedAt);

        return $since !== null && $since->getTimestamp() < ($now ?? new DateTimeImmutable)->getTimestamp() - $options->staleAfter();
    }

    /**
     * A run that stopped without finishing is shown as failed, so it can be
     * tried again. Whether anything changed.
     */
    public function recoverIfStale(DomainOptions $options, ?DateTimeInterface $now = null): bool
    {
        if (! $this->isStale($options, $now)) {
            return false;
        }

        $this->status = self::FAILED;
        $this->error = DomainOptions::STOPPED;

        return true;
    }

    /**
     * Who is waiting on Ghostwriter for this piece, when it's someone other
     * than the viewer.
     */
    public function waitingOn(Viewer $viewer): int|string|null
    {
        return $this->isWorking() && $this->runBy !== null && ! $viewer->is($this->runBy) ? $this->runBy : null;
    }

    /**
     * A failed turn can be run again when the message it was answering is
     * the last one.
     */
    public function canRetry(): bool
    {
        return $this->hasFailed() && ($this->lastMessage()['role'] ?? null) === 'user';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lastMessage(): ?array
    {
        $last = end($this->messages);

        return is_array($last) ? $last : null;
    }

    /**
     * Ghostwriter's answer to the turn: the reply, the draft if it wrote or
     * changed one (kept even when it doesn't parse, with the problem added
     * to the reply), and the tokens used. The piece is idle again.
     *
     * `$questions` is the writer's `<questions>` block (TaggedResponse):
     * asking before a draft, its questions are kept under `asked`
     * (Studio\Asks) with the reply as their intro. A block that can't be
     * read is added to the reply as it came, so nothing asked is lost.
     */
    public function answer(string $reply, ?string $document, int $inputTokens = 0, int $outputTokens = 0, ?DateTimeInterface $now = null, ?string $questions = null): void
    {
        $before = $this->draft;

        if ($document !== null) {
            // A draft that does not parse is still kept, so nothing the
            // model wrote is lost; the problem is reported alongside it.
            try {
                Draft::parse($document);
            } catch (InvalidArgumentException $exception) {
                $reply = trim($reply."\n\n(".$exception->getMessage().' Ask me to fix it.)');
            }

            $this->draft = $document;
        }

        $asks = $document === null ? Asks::read($questions, $reply) : null;

        if ($asks !== null) {
            $reply = $asks->text();
        } elseif ($document === null && $questions !== null) {
            $reply = trim($reply."\n\n".$questions);
        }

        // A turn that hands nothing back and ends on a question is the
        // writer waiting on its colleague, which the panel makes plain.
        $extra = ['asks' => $document === null && ($asks !== null || str_contains($reply, '?'))];

        if ($asks !== null) {
            $extra[Asks::KEY] = $asks->toArray();
        }

        // What this turn did to the draft, for the conversation's log.
        if ($document !== null && $document !== $before) {
            $extra['draft'] = ['change' => $before === null ? 'written' : 'updated', 'words' => str_word_count($document)];

            if ($this->format !== Format::Filament) {
                $extra['draft']['was'] = $before === null ? null : str_word_count($before);
            }
        }

        $this->addMessage('assistant', $reply !== '' ? $reply : 'I have updated the draft.', null, $extra, $now);

        $this->usage = [
            'input' => (int) ($this->usage['input'] ?? 0) + $inputTokens,
            'output' => (int) ($this->usage['output'] ?? 0) + $outputTokens,
        ] + $this->usage;
        $this->status = self::IDLE;
        $this->error = null;
    }

    public function fail(string $message): void
    {
        $this->status = self::FAILED;
        $this->error = $message;
    }

    /**
     * The draft was put into a form (or the record's draft, in Craft).
     */
    public function markApplied(int|string|null $by = null, ?DateTimeInterface $now = null): void
    {
        $this->appliedAt = $this->format->stamp($now ?? new DateTimeImmutable);
        $this->touch($by);
    }

    /**
     * The draft's title, for lists, before any record exists.
     */
    public function title(): string
    {
        if ($this->draft && preg_match('/^title:\s*(.+)$/mu', $this->draft, $m)) {
            return trim($m[1], " \t\"'");
        }

        // The brief card's working title, in the conversation (1.6).
        $card = BriefThread::card($this);

        if ($card !== null && trim($card->title) !== '') {
            return mb_strimwidth(trim($card->title), 0, 80, '…');
        }

        foreach ($this->answers as $answer) {
            if ($this->format === Format::Statamic) {
                if ($answer) {
                    return (string) (is_scalar($answer) ? $answer : 'Untitled');
                }

                continue;
            }

            if (is_string($answer) && trim($answer) !== '') {
                return mb_strimwidth(trim((string) strtok($answer, "\n")), 0, 80, '…');
            }
        }

        return 'Untitled';
    }

    /**
     * A user reference as the format keeps it: text for Statamic, a number
     * for Craft and Filament.
     */
    public static function user(Format $format, int|string|null $id): int|string|null
    {
        if ($id === null || $id === '') {
            return null;
        }

        if ($format === Format::Statamic) {
            return (string) $id;
        }

        return is_numeric($id) ? (int) $id : $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function encode(Format $format): array
    {
        $data = match ($format) {
            Format::Statamic => [
                'id' => $this->id,
                'type' => $this->kind,
                'answers' => $this->answers,
                'messages' => $this->messages,
                'draft' => $this->draft,
                'status' => $this->status,
                'error' => $this->error,
                'entry_id' => $this->recordId === null ? null : (string) $this->recordId,
                'user_id' => self::user($format, $this->startedBy),
                'usage' => $this->usage,
                'examples' => $this->examples,
                'images' => $this->images,
                'source' => $this->source === null ? null : (string) $this->source,
                'blueprint' => $this->variant,
                'applied_at' => $this->appliedAt,
                'touched_by' => self::user($format, $this->touchedBy),
                'run_by' => self::user($format, $this->runBy),
                'created_at' => $this->createdAt,
                'updated_at' => $this->updatedAt,
            ],
            Format::Craft => [
                'id' => $this->id,
                'type' => $this->kind,
                'answers' => $this->answers,
                'messages' => $this->messages,
                'draft' => $this->draft,
                'status' => $this->status,
                'error' => $this->error,
                'element_id' => self::int($this->recordId),
                'site_id' => $this->siteId,
                'user_id' => self::int($this->startedBy),
                'touched_by' => self::int($this->touchedBy),
                'run_by' => self::int($this->runBy),
                'usage' => $this->usage,
                'examples' => array_values(array_map('intval', $this->examples)),
                'images' => $this->images,
                'source' => self::int($this->source),
                'entry_type' => $this->variant,
                'applied_at' => $this->appliedAt,
                'created_at' => $this->createdAt,
                'updated_at' => $this->updatedAt,
            ],
            Format::Filament => array_filter(['id' => $this->key], fn ($value) => $value !== null) + [
                'ulid' => $this->id,
                'user_id' => self::user($format, $this->startedBy),
                'touched_by' => self::user($format, $this->touchedBy),
                'run_by' => self::user($format, $this->runBy),
                'resource' => (string) $this->group,
                'record_key' => ($this->editing ? $this->source : $this->recordId) === null ? null : (string) ($this->editing ? $this->source : $this->recordId),
                'kind' => $this->kind,
                // A column Filament never filled is null, not an empty list.
                'answers' => $this->answers === [] ? null : $format->json($this->answers),
                'messages' => $this->messages === [] ? null : $format->json($this->messages),
                'draft' => $this->draft,
                'status' => $this->status,
                'error' => $this->error,
                'editing' => $this->editing ? 1 : 0,
                'examples' => $this->examples === [] ? null : $format->json($this->examples),
                'images' => $this->images === [] ? null : $format->json($this->images),
                'usage' => ($this->usage['input'] ?? 0) === 0 && ($this->usage['output'] ?? 0) === 0 && count($this->usage) === 2 ? null : $format->json($this->usage),
                'applied_at' => $this->appliedAt,
                'created_at' => $this->createdAt,
                'updated_at' => $this->updatedAt,
            ],
        };

        // Written only once there is something in it (or the record has it,
        // so it can be emptied), so a store with no place for it yet
        // (Filament's table) is never sent the key.
        if ($this->gaps !== [] || array_key_exists('gaps', $this->stored)) {
            $data['gaps'] = $format !== Format::Filament ? $this->gaps : ($this->gaps === [] ? null : $format->json($this->gaps));
        }

        if ($this->units !== [] || array_key_exists('units', $this->stored)) {
            $data['units'] = $format !== Format::Filament ? $this->units : ($this->units === [] ? null : $format->json($this->units));
        }

        if ($this->extras !== [] || array_key_exists('extras', $this->stored)) {
            $data['extras'] = $format !== Format::Filament ? $this->extras : ($this->extras === [] ? null : $format->json($this->extras));
        }

        if ($this->plans !== [] || array_key_exists('plans', $this->stored)) {
            $data['plans'] = $format !== Format::Filament ? $this->plans : ($this->plans === [] ? null : $format->json($this->plans));
        }

        if ($this->plan !== null || array_key_exists('plan', $this->stored)) {
            $data['plan'] = $this->plan;
        }

        if ($this->seo !== [] || array_key_exists('seo', $this->stored)) {
            $data['seo'] = $format !== Format::Filament ? $this->seo : ($this->seo === [] ? null : $format->json($this->seo));
        }

        // Kept where the record has room for it; Filament's table has no
        // column, and falls back on updated_at, which a claim also sets.
        if ($this->startedWorkingAt !== null && $format !== Format::Filament) {
            $data['started_working_at'] = $this->startedWorkingAt;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function fromStatamic(array $data): self
    {
        return new self(
            Format::Statamic,
            self::text($data['id'] ?? ''),
            self::text($data['type'] ?? ''),
            answers: (array) ($data['answers'] ?? []),
            messages: array_values(array_filter((array) ($data['messages'] ?? []), 'is_array')),
            draft: self::nullableText($data['draft'] ?? null),
            status: self::text($data['status'] ?? self::IDLE),
            error: self::nullableText($data['error'] ?? null),
            recordId: self::ref($data['entry_id'] ?? null),
            source: self::ref($data['source'] ?? null),
            startedBy: self::ref($data['user_id'] ?? null),
            touchedBy: self::ref($data['touched_by'] ?? null),
            runBy: self::ref($data['run_by'] ?? null),
            usage: self::usage($data['usage'] ?? null),
            examples: self::list($data['examples'] ?? []),
            images: self::images($data['images'] ?? []),
            appliedAt: self::nullableText($data['applied_at'] ?? null),
            createdAt: self::nullableText($data['created_at'] ?? null),
            updatedAt: self::nullableText($data['updated_at'] ?? null),
            startedWorkingAt: self::nullableText($data['started_working_at'] ?? null),
            variant: self::nullableText($data['blueprint'] ?? null),
            gaps: self::gaps($data['gaps'] ?? []),
            units: self::units($data['units'] ?? []),
            extras: self::records($data['extras'] ?? []),
            plans: self::records($data['plans'] ?? []),
            plan: self::nullableText($data['plan'] ?? null),
            seo: self::map($data['seo'] ?? []),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function fromCraft(array $data): self
    {
        return new self(
            Format::Craft,
            self::text($data['id'] ?? ''),
            self::text($data['type'] ?? ''),
            answers: (array) ($data['answers'] ?? []),
            messages: array_values(array_filter((array) ($data['messages'] ?? []), 'is_array')),
            draft: self::nullableText($data['draft'] ?? null),
            status: self::text($data['status'] ?? self::IDLE),
            error: self::nullableText($data['error'] ?? null),
            recordId: self::int($data['element_id'] ?? null),
            source: self::int($data['source'] ?? null),
            startedBy: self::int($data['user_id'] ?? null),
            touchedBy: self::int($data['touched_by'] ?? null),
            runBy: self::int($data['run_by'] ?? null),
            usage: self::usage($data['usage'] ?? null),
            examples: array_values(array_map('intval', array_filter((array) ($data['examples'] ?? []), 'is_numeric'))),
            images: self::images($data['images'] ?? []),
            appliedAt: self::nullableText($data['applied_at'] ?? null),
            createdAt: self::nullableText($data['created_at'] ?? null),
            updatedAt: self::nullableText($data['updated_at'] ?? null),
            startedWorkingAt: self::nullableText($data['started_working_at'] ?? null),
            variant: self::nullableText($data['entry_type'] ?? null),
            siteId: self::int($data['site_id'] ?? null),
            gaps: self::gaps($data['gaps'] ?? []),
            units: self::units($data['units'] ?? []),
            extras: self::records($data['extras'] ?? []),
            plans: self::records($data['plans'] ?? []),
            plan: self::nullableText($data['plan'] ?? null),
            seo: self::map($data['seo'] ?? []),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function fromFilament(array $row): self
    {
        $editing = (bool) ($row['editing'] ?? false);
        $record = self::ref($row['record_key'] ?? null);

        return new self(
            Format::Filament,
            self::text($row['ulid'] ?? ''),
            self::text($row['kind'] ?? ''),
            answers: self::decoded($row['answers'] ?? null),
            messages: array_values(array_filter(self::decoded($row['messages'] ?? null), 'is_array')),
            draft: self::nullableText($row['draft'] ?? null),
            status: self::text($row['status'] ?? self::IDLE),
            error: self::nullableText($row['error'] ?? null),
            recordId: $editing ? null : $record,
            source: $editing ? $record : null,
            startedBy: self::int($row['user_id'] ?? null),
            touchedBy: self::int($row['touched_by'] ?? null),
            runBy: self::int($row['run_by'] ?? null),
            usage: self::usage(self::decoded($row['usage'] ?? null)),
            examples: self::list(self::decoded($row['examples'] ?? null)),
            images: self::images(self::decoded($row['images'] ?? null)),
            appliedAt: self::nullableText($row['applied_at'] ?? null),
            createdAt: self::nullableText($row['created_at'] ?? null),
            updatedAt: self::nullableText($row['updated_at'] ?? null),
            key: self::ref($row['id'] ?? null),
            group: self::nullableText($row['resource'] ?? null),
            editing: $editing,
            gaps: self::gaps(self::decoded($row['gaps'] ?? null)),
            units: self::units(self::decoded($row['units'] ?? null)),
            extras: self::records(self::decoded($row['extras'] ?? null)),
            plans: self::records(self::decoded($row['plans'] ?? null)),
            plan: self::nullableText($row['plan'] ?? null),
            seo: self::map(self::decoded($row['seo'] ?? null)),
        );
    }

    /**
     * A stored object (string keys), or nothing.
     *
     * @return array<string, mixed>
     */
    private static function map(mixed $value): array
    {
        return is_array($value) && ! array_is_list($value) ? $value : [];
    }

    /**
     * @return array<mixed>
     */
    private static function decoded(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, int>
     */
    private static function usage(mixed $usage): array
    {
        /** @var array<string, int> */
        return array_merge(['input' => 0, 'output' => 0], is_array($usage) ? $usage : []);
    }

    /**
     * @return array<int, int|string>
     */
    private static function list(mixed $values): array
    {
        return array_values(array_filter(is_array($values) ? $values : [], fn ($value) => is_int($value) || (is_string($value) && $value !== '')));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function images(mixed $images): array
    {
        /** @var array<string, array<string, mixed>> */
        return array_filter(is_array($images) ? $images : [], 'is_array');
    }

    /**
     * @return array<string, mixed>
     */
    private static function units(mixed $units): array
    {
        $out = [];

        foreach (is_array($units) ? $units : [] as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * A list of keyed records, as stored.
     *
     * @return list<array<string, mixed>>
     */
    private static function records(mixed $records): array
    {
        $out = [];

        foreach (is_array($records) ? $records : [] as $record) {
            if (is_array($record)) {
                $entry = [];

                foreach ($record as $key => $value) {
                    $entry[(string) $key] = $value;
                }

                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private static function gaps(mixed $gaps): array
    {
        $out = [];

        foreach (is_array($gaps) ? $gaps : [] as $gap) {
            if (! is_array($gap)) {
                continue;
            }

            $entry = [];

            foreach ($gap as $key => $value) {
                if (is_string($key) && is_scalar($value)) {
                    $entry[$key] = (string) $value;
                }
            }

            $out[] = $entry;
        }

        return $out;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function nullableText(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    private static function ref(mixed $value): int|string|null
    {
        return is_int($value) || (is_string($value) && $value !== '') ? $value : null;
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
