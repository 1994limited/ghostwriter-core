<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Studio;

/**
 * What Studio::fillBrief() fills a brief from: the kind, what the person
 * said (their reply to "What's it called, and what should it say?", or a
 * plan idea's title and notes), the titles of the group's existing
 * records, the records ticked to model it on, and the published records
 * the brief filler may choose from when none are ticked (`candidates`).
 *
 *     $request = BriefRequest::fromDetails($kind, $reply, $titles, $type->examples)->withCandidates($candidates);
 *     $request = BriefRequest::fromIdea($kind, $idea->title, trim($idea->why."\n\n".$idea->notes), $titles, $type->examples);
 *     $again = $request->tryAgain($brief, $answersFromTheCard);
 *
 * Domain\Sessions\BriefThread::request() builds it from a session.
 */
final class BriefRequest
{
    /**
     * @param  string  $details  What the person said, in their words: the quick details, or an idea's notes.
     * @param  string|null  $title  The working title, when it is known (an idea's); otherwise read from the details.
     * @param  array<int, string>  $titles  Titles of existing records in the kind's group, newest first.
     * @param  array<int, int|string>  $examples  Records to model it on, chosen as the brief screen chose them (the kind's own examples, a found kind's, or none).
     * @param  Brief|null  $previous  For "Try again": the brief as the card had it, with the person's changes.
     * @param  array<int, string>  $kept  For "Try again": the questions the person changed, whose answers are kept as they are.
     * @param  array<int, array{id: int|string, title: string}>  $candidates  Published records the brief filler may choose to model it on, newest first, by ID and title (the "Model it on" picker's). Only used when none are ticked.
     * @param  bool  $examplesKept  For "Try again": the person unticked every example, so none are chosen for them.
     */
    public function __construct(
        public readonly ContentKind $kind,
        public readonly string $details,
        public readonly ?string $title = null,
        public readonly array $titles = [],
        public readonly array $examples = [],
        public readonly ?Brief $previous = null,
        public readonly array $kept = [],
        public readonly array $candidates = [],
        public readonly bool $examplesKept = false,
    ) {}

    /**
     * The same request with the records the brief filler may choose from
     * when none are ticked: each `['id' => …, 'title' => …]`, or `id =>
     * title`. Blank titles and repeated IDs are left out.
     *
     * @param  array<int|string, mixed>  $candidates
     */
    public function withCandidates(array $candidates): self
    {
        return new self($this->kind, $this->details, $this->title, $this->titles, $this->examples, $this->previous, $this->kept, self::candidates($candidates), $this->examplesKept);
    }

    /**
     * Whether the brief filler chooses the records to model it on: none are
     * ticked, there are some to choose from, and the person didn't untick
     * them all before "Try again".
     */
    public function choosesExamples(): bool
    {
        return $this->examples === [] && $this->candidates !== [] && ! $this->examplesKept;
    }

    /**
     * The candidate with this ID, as the model wrote it (compared as text),
     * or null when it isn't one.
     */
    public function candidate(mixed $id): int|string|null
    {
        if (! is_scalar($id) || trim((string) $id) === '') {
            return null;
        }

        foreach ($this->candidates as $candidate) {
            if ((string) $candidate['id'] === trim((string) $id)) {
                return $candidate['id'];
            }
        }

        return null;
    }

    /**
     * @param  array<int|string, mixed>  $given
     * @return array<int, array{id: int|string, title: string}>
     */
    private static function candidates(array $given): array
    {
        $out = [];
        $seen = [];

        foreach ($given as $key => $value) {
            [$id, $title] = is_array($value) ? [$value['id'] ?? null, $value['title'] ?? null] : [$key, $value];

            if ((! is_int($id) && ! (is_string($id) && trim($id) !== '')) || ! is_scalar($title) || trim((string) $title) === '' || isset($seen[(string) $id])) {
                continue;
            }

            $seen[(string) $id] = true;
            $out[] = ['id' => $id, 'title' => trim((string) preg_replace('/\s+/u', ' ', (string) $title))];
        }

        return $out;
    }

    /**
     * From the person's reply to the quick-details question.
     *
     * @param  array<int, string>  $titles
     * @param  array<int, int|string>  $examples
     */
    public static function fromDetails(ContentKind $kind, string $reply, array $titles = [], array $examples = []): self
    {
        return new self($kind, trim($reply), null, $titles, $examples);
    }

    /**
     * From an idea on the content plan ("Draft this"): its title, and its
     * why and notes as the details.
     *
     * @param  array<int, string>  $titles
     * @param  array<int, int|string>  $examples
     */
    public static function fromIdea(ContentKind $kind, string $title, string $notes, array $titles = [], array $examples = []): self
    {
        return new self($kind, trim($notes), trim($title), $titles, $examples);
    }

    /**
     * The same request again ("Try again"), after a brief the person didn't
     * take. Answers they changed in the card are kept exactly; the rest of
     * the previous answers are what to do differently. The working title
     * and the ticked examples are the card's; when the person unticked
     * every one, none are chosen for them.
     *
     * @param  array<string, mixed>  $edited  The card's answers by handle, as the person left them.
     * @param  array<int, int|string>|null  $examples  The card's ticked examples; null keeps the brief's.
     */
    public function tryAgain(Brief $previous, array $edited = [], ?array $examples = null, ?string $title = null): self
    {
        $card = $previous->with($edited, $examples, $title);
        $kept = [];

        foreach ($card->answers as $handle => $answer) {
            if ($answer !== ($previous->answers[$handle] ?? '')) {
                $kept[] = $handle;
            }
        }

        return new self(
            $this->kind,
            $this->details,
            $card->title !== '' ? $card->title : $this->title,
            $this->titles,
            $card->examples,
            $card,
            array_values(array_unique([...$this->kept, ...$kept])),
            $this->candidates,
            $card->examples === [] && $previous->examples !== [],
        );
    }

    /**
     * Everything the person has said that a fact may come from: the title,
     * the details and the answers they wrote themselves.
     */
    public function source(): string
    {
        $kept = array_map(fn (string $handle) => $this->previous->answers[$handle] ?? '', $this->previous !== null ? $this->kept : []);

        return trim(implode("\n", [(string) $this->title, $this->details, ...$kept]));
    }
}
