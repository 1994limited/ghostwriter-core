<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps;

/**
 * How an SEO addon composes the page's `<title>` from its SEO title: the
 * site name, the separator between them and which side the name goes
 * (SEO Pro's `site_name`, `site_name_separator`, `site_name_position`;
 * SEOmatic's `siteName`, `separatorChar`, `siteNamePosition`). Both join
 * the three with single spaces.
 */
final class TitleFormat
{
    public const BEFORE = 'before';

    public const AFTER = 'after';

    public const NONE = 'none';

    /**
     * @param  string  $position  self::BEFORE, self::AFTER or self::NONE.
     */
    public function __construct(
        public readonly string $siteName,
        public readonly string $separator,
        public readonly string $position,
    ) {}

    /**
     * From an addon's settings, with its defaults for what isn't set.
     */
    public static function of(?string $siteName, ?string $separator, ?string $position): self
    {
        $position = strtolower(trim((string) $position));

        return new self(
            trim((string) $siteName),
            trim((string) $separator),
            in_array($position, [self::BEFORE, self::AFTER, self::NONE], true) ? $position : self::AFTER,
        );
    }

    /** Whether the site name is added to the title at all. */
    public function addsName(): bool
    {
        return $this->siteName !== '' && $this->position !== self::NONE;
    }

    /** The page's `<title>` for an SEO title. */
    public function compose(string $title): string
    {
        $title = trim($title);

        if ($title === '') {
            return $this->siteName;
        }

        if (! $this->addsName()) {
            return $title;
        }

        $parts = array_values(array_filter([$title, $this->separator, $this->siteName], fn (string $part) => $part !== ''));

        return implode(' ', $this->position === self::BEFORE ? array_reverse($parts) : $parts);
    }

    /** How many characters the site name and separator add to a title (§9.2's budget). */
    public function added(): int
    {
        return $this->addsName() ? mb_strlen($this->compose('x')) - 1 : 0;
    }

    /**
     * @return array{siteName: string, separator: string, position: string}
     */
    public function toArray(): array
    {
        return ['siteName' => $this->siteName, 'separator' => $this->separator, 'position' => $this->position];
    }
}
