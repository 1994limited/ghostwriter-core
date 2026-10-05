<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;

/**
 * Which SEO text Ghostwriter wrote itself (SEO layer §9.4): a hash of each
 * title and description it put into an entry, by role. A value whose hash
 * is here is Ghostwriter's own and nobody has changed it since, so it may
 * be written again; anything else is a person's, and is never replaced
 * without asking (MetaPolicy).
 *
 * Kept on the session (SeoState::$written) when a draft is applied; for an
 * existing entry, the addon joins those of the sessions that were applied
 * to it (merge()).
 */
final class SeoProvenance
{
    /**
     * @param  array<string, list<string>>  $hashes  By role (SeoField::TITLE, ::DESCRIPTION).
     */
    public function __construct(public readonly array $hashes = []) {}

    /** The hash of some SEO text: the same text with any spacing gives the same hash. */
    public static function hash(string $text): string
    {
        return sha1(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }

    /**
     * @param  array<mixed>  $array  toArray()'s shape: `{title: [hash…], description: [hash…]}` (a single hash string is read too).
     */
    public static function fromArray(array $array): self
    {
        $hashes = [];

        foreach ([SeoField::TITLE, SeoField::DESCRIPTION] as $role) {
            $list = $array[$role] ?? [];
            $list = is_string($list) ? [$list] : (is_array($list) ? $list : []);
            $list = array_values(array_unique(array_filter($list, fn ($hash) => is_string($hash) && preg_match('/^[0-9a-f]{40}$/', $hash) === 1)));

            if ($list !== []) {
                $hashes[$role] = $list;
            }
        }

        return new self($hashes);
    }

    /**
     * @return array<string, list<string>>
     */
    public function toArray(): array
    {
        return $this->hashes;
    }

    /** Whether Ghostwriter wrote exactly this text for this role. Empty text is nobody's. */
    public function owns(string $role, ?string $text): bool
    {
        if ($text === null || trim($text) === '') {
            return false;
        }

        return in_array(self::hash($text), $this->hashes[$role] ?? [], true);
    }

    /** With this text recorded as written by Ghostwriter. */
    public function with(string $role, string $text): self
    {
        if (trim($text) === '') {
            return $this;
        }

        $hashes = $this->hashes;
        $hashes[$role] = array_values(array_unique([...($hashes[$role] ?? []), self::hash($text)]));

        return new self($hashes);
    }

    /** Both lists together: an entry's sessions, or a session and the entry. */
    public function merge(self $other): self
    {
        $hashes = $this->hashes;

        foreach ($other->hashes as $role => $list) {
            $hashes[$role] = array_values(array_unique([...($hashes[$role] ?? []), ...$list]));
        }

        return new self($hashes);
    }

    public function isEmpty(): bool
    {
        return $this->hashes === [];
    }
}
