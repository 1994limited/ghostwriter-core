<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Review;

/**
 * One comment sent to Ghostwriter: an item of an editor's comments message
 * in the conversation (Comments::apply()). Its scope says what it is about
 * (a block's units, some words in one, the whole page); its number is the
 * pin's, unique on the piece.
 *
 * `hashes` are the hashes of the units and extra items it may change when
 * it was sent (Arrange\Unit::hash()), so one someone changes while
 * Ghostwriter works is skipped rather than overwritten.
 */
final class Comment
{
    public const MAX_BODY = 2000;

    /**
     * @param  array<string, string>  $hashes  Unit or extra item id => hash when sent.
     */
    public function __construct(
        public readonly string $id,
        public readonly int $number,
        public readonly Scope $scope,
        public readonly string $body,
        public readonly int|string|null $by = null,
        public readonly array $hashes = [],
    ) {}

    /**
     * A new comment, its words trimmed and cut to MAX_BODY.
     *
     * @param  array<string, string>  $hashes
     */
    public static function make(int $number, Scope $scope, string $body, int|string|null $by, array $hashes = []): self
    {
        return new self(bin2hex(random_bytes(8)), $number, $scope, mb_substr(trim($body), 0, self::MAX_BODY), $by, $hashes);
    }

    /**
     * What the editor asked: the comment's words.
     *
     * @return list<string>
     */
    public function asks(): array
    {
        return [$this->body];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'number' => $this->number, 'scope' => $this->scope->toArray(), 'body' => $this->body]
            + ($this->by !== null ? ['by' => $this->by] : [])
            + ($this->hashes !== [] ? ['hashes' => $this->hashes] : []);
    }

    /**
     * @param  array<mixed>  $array
     */
    public static function fromArray(array $array): ?self
    {
        if (! is_numeric($array['number'] ?? null) || ! is_array($array['scope'] ?? null)) {
            return null;
        }

        $hashes = [];

        foreach (is_array($array['hashes'] ?? null) ? $array['hashes'] : [] as $id => $hash) {
            if (is_scalar($hash)) {
                $hashes[(string) $id] = (string) $hash;
            }
        }

        $by = $array['by'] ?? null;

        return new self(
            is_scalar($array['id'] ?? null) ? (string) $array['id'] : (string) $array['number'],
            (int) $array['number'],
            Scope::fromArray($array['scope']),
            is_scalar($array['body'] ?? null) ? (string) $array['body'] : '',
            is_int($by) || is_string($by) ? $by : null,
            $hashes,
        );
    }
}
