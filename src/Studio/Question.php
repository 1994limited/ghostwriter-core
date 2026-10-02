<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Studio;

/**
 * One question a kind of content asks before writing: what the brief writer
 * fills in and the brief lists.
 */
final class Question
{
    /**
     * @param  array<string, string>  $options  Value => label, for a question with set answers; only the values are shown.
     */
    public function __construct(
        public readonly string $handle,
        public readonly string $label,
        public readonly string $instructions = '',
        public readonly bool $required = false,
        public readonly array $options = [],
    ) {}

    /**
     * From the array the addons store a question as: `handle`, `label`,
     * and optionally `instructions`, `required` and `options`.
     *
     * @param  array<string, mixed>  $question
     */
    public static function fromArray(array $question): self
    {
        $options = [];

        foreach ((array) ($question['options'] ?? []) as $value => $label) {
            $options[(string) $value] = is_scalar($label) ? (string) $label : (string) $value;
        }

        return new self(
            self::text($question['handle'] ?? ''),
            self::text($question['label'] ?? ''),
            self::text($question['instructions'] ?? ''),
            (bool) ($question['required'] ?? false),
            $options,
        );
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
