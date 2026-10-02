<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Guides;

use DateTimeInterface;

/**
 * The site's voice guide or image style guide, as markdown: read into
 * every writing prompt (voice), or whenever photographs are searched for,
 * ranked or made (imagery). Kept as a markdown file in the project
 * (Statamic), a `guide` document (Craft) or a `ghostwriter_guides` row
 * (Filament).
 */
final class Guide
{
    public const VOICE = 'voice';

    public const IMAGERY = 'imagery';

    public function __construct(
        public readonly string $kind,
        public readonly string $body = '',
        public readonly ?DateTimeInterface $updatedAt = null,
    ) {}

    public function exists(): bool
    {
        return trim($this->body) !== '';
    }

    /**
     * The guide as all three save it: trailing space trimmed, one newline.
     */
    public static function normalise(string $markdown): string
    {
        return rtrim($markdown)."\n";
    }

    public function withBody(string $markdown): self
    {
        return new self($this->kind, self::normalise($markdown), $this->updatedAt);
    }

    /**
     * What an image style guide says about one group (its `##` heading),
     * or the whole guide where it isn't divided that way. Empty when there
     * is nothing for the group.
     */
    public function section(string $groupTitle): string
    {
        if ($groupTitle !== '' && preg_match('/^##\s+'.preg_quote($groupTitle, '/').'\s*$(.*?)(?=^##\s|\z)/imsu', $this->body, $m)) {
            return trim($m[1]);
        }

        return str_contains($this->body, "\n## ") ? '' : trim($this->body);
    }
}
