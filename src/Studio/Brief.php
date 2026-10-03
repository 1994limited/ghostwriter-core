<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Studio;

/**
 * A brief filled in for a person to check (the brief card in the
 * conversation): a working title, an answer for every one of the kind's
 * questions, and the records to model the piece on.
 *
 * Anything only the person knows is left in square brackets, such as
 * `[Add: one project where we did this]`; `open()` lists those questions.
 *
 * Kept on the card's message as toArray(); read back with fromArray().
 */
final class Brief
{
    /** The most records a piece is modelled on, as each addon's picker allows. */
    public const MAX_EXAMPLES = 6;

    /** @var array<int, int|string> */
    public readonly array $examples;

    /**
     * @param  array<string, string>  $answers  By question handle, in the kind's order.
     * @param  array<int, int|string>  $examples  Records to model it on ("Model it on", ticked).
     * @param  int  $attempt  1 for the first fill, 2 after one "Try again", and so on.
     */
    public function __construct(
        public readonly string $title,
        public readonly array $answers,
        array $examples = [],
        public readonly int $attempt = 1,
    ) {
        $this->examples = array_slice(array_values(array_unique($examples, SORT_REGULAR)), 0, self::MAX_EXAMPLES);
    }

    /**
     * The questions whose answers still hold something in square brackets
     * for the person to fill in.
     *
     * @return list<string>
     */
    public function open(): array
    {
        return array_keys(array_filter($this->answers, fn (string $answer) => BriefCheck::hasBrackets($answer)));
    }

    /**
     * The same brief with the person's changes from the card: answers by
     * handle (only the kind's questions are kept), the examples ticked and
     * the working title. Anything not given stays as it was.
     *
     * @param  array<string, mixed>|null  $answers
     * @param  array<int, int|string>|null  $examples
     */
    public function with(?array $answers = null, ?array $examples = null, ?string $title = null): self
    {
        $merged = $this->answers;

        foreach ($answers ?? [] as $handle => $answer) {
            if (array_key_exists((string) $handle, $merged)) {
                $merged[(string) $handle] = trim(is_scalar($answer) ? (string) $answer : '');
            }
        }

        return new self($title !== null ? trim($title) : $this->title, $merged, $examples ?? $this->examples, $this->attempt);
    }

    /**
     * @return array{title: string, answers: array<string, string>, examples: array<int, int|string>, attempt: int}
     */
    public function toArray(): array
    {
        return ['title' => $this->title, 'answers' => $this->answers, 'examples' => $this->examples, 'attempt' => $this->attempt];
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $answers = [];

        foreach ((array) ($data['answers'] ?? []) as $handle => $answer) {
            $answers[(string) $handle] = is_scalar($answer) ? (string) $answer : '';
        }

        $examples = array_values(array_filter((array) ($data['examples'] ?? []), fn ($id) => is_int($id) || (is_string($id) && $id !== '')));
        $attempt = $data['attempt'] ?? 1;

        return new self(is_scalar($data['title'] ?? null) ? (string) $data['title'] : '', $answers, $examples, is_numeric($attempt) ? max(1, (int) $attempt) : 1);
    }
}
