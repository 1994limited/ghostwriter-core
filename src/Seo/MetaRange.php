<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\TitleFormat;

/**
 * How long an SEO title or description should be (SEO layer §9.2), in
 * characters (mb_strlen()), inside the field's own limit (SeoField::$limit:
 * `character_limit`, `charLimit`, `max_length`; else 60 and 160):
 *
 * - **title:** 30 to `limit − 8` (52 for 60). Where the SEO addon adds the
 *   site name ("… | Northfold Gardens": TitleFormat), the name and its
 *   separator come off the budget instead, so the page's whole `<title>`
 *   fits the limit.
 * - **description:** 120 to `limit − 5` (155 for 160); a limit under 150
 *   makes it `limit − 30` to `limit − 5`.
 */
final class MetaRange
{
    public function __construct(
        public readonly string $role,
        public readonly int $limit,
        public readonly int $min,
        public readonly int $max,
        public readonly ?TitleFormat $format = null,
    ) {}

    public static function for(string $role, ?int $limit = null, ?TitleFormat $format = null): self
    {
        $limit = $limit !== null && $limit > 0 ? $limit : SeoField::LIMITS[$role] ?? 160;

        if ($role === SeoField::TITLE) {
            $added = $format?->added() ?? 0;
            $max = $added > 0 ? max(15, $limit - $added) : max(15, $limit - 8);

            return new self($role, $limit, max(10, min(30, $max - 10)), $max, $format !== null && $format->addsName() ? $format : null);
        }

        $max = max(20, $limit - 5);

        return new self($role, $limit, $limit >= 150 ? 120 : max(10, $limit - 30), $max);
    }

    public static function of(SeoField $field, ?TitleFormat $format = null): self
    {
        return self::for($field->role, $field->limit, $field->role === SeoField::TITLE ? $format : null);
    }

    public function length(?string $text): int
    {
        return $text === null ? 0 : mb_strlen(trim($text));
    }

    /** Within the range. */
    public function fits(?string $text): bool
    {
        $length = $this->length($text);

        return $length >= $this->min && $length <= $this->max;
    }

    public function tooLong(?string $text): bool
    {
        return $this->length($text) > $this->max;
    }

    public function tooShort(?string $text): bool
    {
        return $this->length($text) < $this->min;
    }

    /**
     * Whether a page title, used as the SEO title as every SEO addon does
     * by default, makes a `<title>` over the field's limit once the site
     * name is added: only then does the page get an SEO title of its own
     * (decision 12).
     */
    public function pageTitleTooLong(string $title): bool
    {
        $title = trim($title);

        return mb_strlen($this->format?->compose($title) ?? $title) > $this->limit;
    }

    /**
     * @return array{role: string, limit: int, min: int, max: int}
     */
    public function toArray(): array
    {
        return ['role' => $this->role, 'limit' => $this->limit, 'min' => $this->min, 'max' => $this->max];
    }
}
