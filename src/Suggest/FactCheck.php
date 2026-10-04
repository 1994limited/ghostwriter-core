<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

use InvalidArgumentException;

/**
 * A Fact to check: what to ask the editor, the quote as a template with
 * `{answer}` where the fact goes, and the version without the fact
 * ("Remove the number"; null when the sentence doesn't work without it).
 * Ghostwriter never supplies the answer.
 */
final class FactCheck
{
    public const ANSWER = '{answer}';

    public function __construct(
        public readonly string $ask,
        public readonly string $template,
        public readonly ?string $without = null,
        public readonly AnswerKind $answer = AnswerKind::Text,
    ) {}

    /**
     * The template filled with the editor's answer.
     *
     * @throws InvalidArgumentException when the answer isn't one of its kind ("8 or 9?" for a number).
     */
    public function fill(string $answer): string
    {
        $answer = trim($answer);
        $ok = match ($this->answer) {
            AnswerKind::Number => preg_match('/^\d{1,3}(?:[,.\x{202F} ]\d{3})*(?:[.,]\d+)?$|^\d+(?:[.,]\d+)?$/u', $answer) === 1,
            AnswerKind::Money => preg_match('/^[£$€]?\s?\d[\d,. ]*(?:k|m)?\s?€?$/iu', $answer) === 1,
            AnswerKind::Date => Dates::find($answer, Phrases::for('en')) !== [] || preg_match('/\d/', $answer) === 1,
            AnswerKind::Text => $answer !== '',
        };

        if (! $ok || $answer === '') {
            throw new InvalidArgumentException($this->answer === AnswerKind::Date ? 'suggest.fact.date-only' : 'suggest.fact.number-only');
        }

        if ($this->answer === AnswerKind::Money && preg_match('/[£$€]/u', $this->template) === 1) {
            $answer = trim((string) preg_replace('/[£$€]\s?/u', '', $answer));
        }

        return str_replace(self::ANSWER, $answer, $this->template);
    }

    /**
     * @return array{ask: string, template: string, without: ?string, answer: string}
     */
    public function toArray(): array
    {
        return ['ask' => $this->ask, 'template' => $this->template, 'without' => $this->without, 'answer' => $this->answer->value];
    }

    /**
     * @param  array<mixed>  $array
     */
    public static function fromArray(array $array): self
    {
        return new self(
            is_string($array['ask'] ?? null) ? $array['ask'] : '',
            is_string($array['template'] ?? null) ? $array['template'] : self::ANSWER,
            is_string($array['without'] ?? null) ? $array['without'] : null,
            AnswerKind::tryFrom(is_string($array['answer'] ?? null) ? $array['answer'] : '') ?? AnswerKind::Text,
        );
    }
}
