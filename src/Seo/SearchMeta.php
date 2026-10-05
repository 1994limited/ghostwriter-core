<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;

/**
 * The draft's search title, description and address (SEO layer §9, §10),
 * kept on the session (SeoState::$meta), not in the draft: the writer
 * never sees or rewrites them. They show in the Text tab's Search section
 * and go into the site's SEO fields and slug on "Use this draft".
 *
 * - `title`: the page's own SEO title; '' while it uses the page title,
 *   which is the default (decision 12).
 * - `description`: the search description; '' when none was written.
 * - `edited`: the roles an editor changed in the Search section (`title`,
 *   `description`, `slug`). Theirs now: a later turn never rewrites them.
 * - `use`: the roles the editor chose to put in although the entry's own
 *   value stays otherwise ("Use this" beside "Your SEO description stays").
 * - `slug`: the address made from the title (SlugRules); null where none
 *   is set (a published entry, no slug field).
 * - `dropped`: what was wrong with a text the check refused (SeoMetaCheck
 *   codes, by role), so the Search section can say why it is empty.
 * - `checked`: when the title and description were last written.
 */
final class SearchMeta
{
    /**
     * @param  list<string>  $edited
     * @param  list<string>  $use
     * @param  array<string, list<string>>  $dropped
     */
    public function __construct(
        public readonly string $title = '',
        public readonly string $description = '',
        public readonly array $edited = [],
        public readonly array $use = [],
        public readonly ?string $slug = null,
        public readonly array $dropped = [],
        public readonly ?string $checked = null,
    ) {}

    /**
     * @param  array<mixed>  $array
     */
    public static function fromArray(array $array): self
    {
        $list = fn (string $key) => array_values(array_unique(array_filter(is_array($array[$key] ?? null) ? $array[$key] : [], fn ($role) => is_string($role) && in_array($role, [SeoField::TITLE, SeoField::DESCRIPTION, 'slug'], true))));
        $dropped = [];

        foreach (is_array($array['dropped'] ?? null) ? $array['dropped'] : [] as $role => $codes) {
            if (is_string($role) && is_array($codes)) {
                $dropped[$role] = array_values(array_filter($codes, 'is_string'));
            }
        }

        return new self(
            is_string($array['title'] ?? null) ? trim($array['title']) : '',
            is_string($array['description'] ?? null) ? trim($array['description']) : '',
            $list('edited'),
            $list('use'),
            is_string($array['slug'] ?? null) && $array['slug'] !== '' ? $array['slug'] : null,
            $dropped,
            is_string($array['checked'] ?? null) ? $array['checked'] : null,
        );
    }

    /**
     * Only what is set.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'title' => $this->title,
            'description' => $this->description,
            'edited' => $this->edited,
            'use' => $this->use,
            'slug' => $this->slug,
            'dropped' => $this->dropped,
            'checked' => $this->checked,
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    public function isEmpty(): bool
    {
        return $this->toArray() === [];
    }

    /** The text for a role: the title or the description. */
    public function text(string $role): string
    {
        return $role === SeoField::TITLE ? $this->title : $this->description;
    }

    public function edited(string $role): bool
    {
        return in_array($role, $this->edited, true);
    }

    public function uses(string $role): bool
    {
        return in_array($role, $this->use, true);
    }

    /**
     * With new text for a role. $edited: an editor wrote it (theirs from
     * now on); otherwise Ghostwriter did, and what was refused is $dropped.
     *
     * @param  list<string>  $dropped
     */
    public function with(string $role, string $text, bool $edited = false, array $dropped = [], ?string $checked = null): self
    {
        $text = trim($text);
        $all = $this->dropped;
        unset($all[$role]);

        if ($dropped !== []) {
            $all[$role] = $dropped;
        }

        return new self(
            $role === SeoField::TITLE ? $text : $this->title,
            $role === SeoField::DESCRIPTION ? $text : $this->description,
            $edited ? array_values(array_unique([...$this->edited, $role])) : array_values(array_diff($this->edited, [$role])),
            $this->use,
            $this->slug,
            $all,
            $checked ?? $this->checked,
        );
    }

    /** With an address; $edited when an editor typed it. */
    public function withSlug(?string $slug, bool $edited = false): self
    {
        return new self($this->title, $this->description, $edited ? array_values(array_unique([...$this->edited, 'slug'])) : $this->edited, $this->use, $slug !== null && $slug !== '' ? $slug : null, $this->dropped, $this->checked);
    }

    /** With the editor's choice to put this role's text in over the entry's own (true), or not. */
    public function using(string $role, bool $use = true): self
    {
        $list = $use ? array_values(array_unique([...$this->use, $role])) : array_values(array_diff($this->use, [$role]));

        return new self($this->title, $this->description, $this->edited, $list, $this->slug, $this->dropped, $this->checked);
    }
}
