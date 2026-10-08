<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

/**
 * The `seo-verifier` call's verdicts (SEO layer §8.3), by id (l1…, m1…):
 * the links and suggestions to drop, with why, and the links whose page is
 * right but whose words aren't, with the better words it chose from the
 * same sentence (`keep-with-anchor`). Anything else is kept as it is.
 * LinkValidator::judged() applies them, checking each new anchor as it
 * checked the first.
 */
final class LinkVerdicts
{
    public const KEEP = 'keep';

    public const KEEP_WITH_ANCHOR = 'keep-with-anchor';

    public const DROP = 'drop';

    /**
     * @param  array<string, string>  $drop  Id => why.
     * @param  array<string, array{anchor: string, why: string}>  $anchors  Link id => the better words, and why.
     */
    public function __construct(
        public readonly array $drop = [],
        public readonly array $anchors = [],
    ) {}

    /**
     * From the reply's `verdicts` list. An item that can't be read is
     * skipped (kept). A `keep-with-anchor` with no words, or the same
     * words, is a keep; a `keep` with words is a keep too.
     *
     * @param  array<mixed>  $verdicts
     */
    public static function fromArray(array $verdicts): self
    {
        $drop = [];
        $anchors = [];

        foreach ($verdicts as $verdict) {
            if (! is_array($verdict) || ! is_string($verdict['id'] ?? null) || trim($verdict['id']) === '') {
                continue;
            }

            $id = trim($verdict['id']);
            $why = is_scalar($verdict['reason'] ?? null) ? trim((string) $verdict['reason']) : '';
            $anchor = is_scalar($verdict['anchor'] ?? null) ? trim((string) $verdict['anchor']) : '';

            match ($verdict['verdict'] ?? null) {
                self::DROP => $drop[$id] = $why,
                self::KEEP_WITH_ANCHOR => $anchor !== '' ? $anchors[$id] = ['anchor' => $anchor, 'why' => $why] : null,
                default => null,
            };
        }

        return new self($drop, $anchors);
    }

    public function drops(string $id): bool
    {
        return isset($this->drop[$id]);
    }
}
