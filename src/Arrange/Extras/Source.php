<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras;

/**
 * Where the facts of one extra item came from, with the words they rest
 * on. An entry source keeps the entry's id and title, when the adapter knows
 * them, for the "from Winter round 2025" link.
 */
final class Source
{
    /**
     * @param  string|null  $ref  The question's number or handle for an answer; the example's number for an entry.
     * @param  string  $quote  The words quoted from the source, as the writer gave them.
     */
    public function __construct(
        public readonly SourceKind $kind,
        public readonly string $quote = '',
        public readonly ?string $ref = null,
        public readonly int|string|null $entryId = null,
        public readonly ?string $entryTitle = null,
    ) {}

    /**
     * @return array{kind: string, quote: string, ref?: string, entryId?: int|string, entryTitle?: string}
     */
    public function toArray(): array
    {
        return ['kind' => $this->kind->value, 'quote' => $this->quote]
            + ($this->ref !== null ? ['ref' => $this->ref] : [])
            + ($this->entryId !== null ? ['entryId' => $this->entryId] : [])
            + ($this->entryTitle !== null ? ['entryTitle' => $this->entryTitle] : []);
    }

    /**
     * @param  array<mixed>  $array
     */
    public static function fromArray(array $array): ?self
    {
        $kind = SourceKind::tryFrom(is_string($array['kind'] ?? null) ? $array['kind'] : '');

        if ($kind === null) {
            return null;
        }

        $entryId = $array['entryId'] ?? null;

        return new self(
            $kind,
            is_scalar($array['quote'] ?? null) ? (string) $array['quote'] : '',
            is_scalar($array['ref'] ?? null) ? (string) $array['ref'] : null,
            is_int($entryId) || is_string($entryId) ? $entryId : null,
            is_string($array['entryTitle'] ?? null) ? $array['entryTitle'] : null,
        );
    }
}
