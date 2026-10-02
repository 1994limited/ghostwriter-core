<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Studio;

/**
 * A kind of content Ghostwriter has learned (Statamic's and Craft's content
 * type, Filament's kind): what it is, how to write it and what to ask.
 *
 * Only `handle`, `title` and `description` are needed where a kind is just
 * listed (kinds already taught, kinds in the plan); the writer and the
 * brief writer use the rest.
 */
final class ContentKind
{
    /**
     * @param  array<int, string>  $checklist
     * @param  array<int, Question>  $questions
     */
    public function __construct(
        public readonly string $handle,
        public readonly string $title,
        public readonly string $description = '',
        public readonly string $guidance = '',
        public readonly array $checklist = [],
        public readonly array $questions = [],
    ) {}

    /**
     * From a stored kind's fields: `title`, `description`, `guidance`,
     * `checklist` and `questions` (each an array, see Question::fromArray).
     *
     * @param  array<string, mixed>  $kind
     */
    public static function fromArray(string $handle, array $kind): self
    {
        $text = fn (string $key): string => is_scalar($kind[$key] ?? null) ? (string) $kind[$key] : '';

        return new self(
            $handle,
            $text('title'),
            $text('description'),
            $text('guidance'),
            array_values(array_map(fn (mixed $item): string => is_scalar($item) ? (string) $item : '', (array) ($kind['checklist'] ?? []))),
            array_values(array_map(
                fn (mixed $question): Question => $question instanceof Question ? $question : Question::fromArray(is_array($question) ? $question : []),
                (array) ($kind['questions'] ?? []),
            )),
        );
    }
}
