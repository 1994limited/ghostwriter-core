<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Studio;

/**
 * One question the writer asks before drafting (Asks): short, one thing
 * only, answered in its own box. A `choice` question offers set answers.
 */
final class AskedQuestion
{
    public const TEXT = 'text';

    public const CHOICE = 'choice';

    /**
     * @param  list<string>  $options  The set answers of a `choice` question.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $question,
        public readonly string $hint = '',
        public readonly string $kind = self::TEXT,
        public readonly array $options = [],
        public readonly bool $optional = false,
    ) {}

    /**
     * @return array{id: string, question: string, hint: string, kind: string, options: list<string>, optional: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'question' => $this->question,
            'hint' => $this->hint,
            'kind' => $this->kind,
            'options' => $this->options,
            'optional' => $this->optional,
        ];
    }
}
