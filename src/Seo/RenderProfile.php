<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Layout\RichTextDialect;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\HeadingLevels;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;

/**
 * How a group's template prints headings, read from a preview's outline:
 * what prints the page's `h1`, which block fields render as headings and
 * at what level, and which rich-text fields' own `#` is the page's `h1`.
 * It sets where each body's headings start (top()).
 *
 * Kept per group, site and blueprint (Statamic) or entry type (Craft)
 * through the addon's RenderProfiles, updated on every preview. A stored
 * profile only changes when two renders agree (observe()), so one odd
 * draft doesn't flip it.
 *
 * Before any preview, and on Filament, the default: the title is the
 * `h1`, and bodies start at `##`. The site's own entries can correct that
 * first (fromEntries()).
 */
final class RenderProfile
{
    /** How many renders must agree before a stored profile changes. */
    public const AGREE = 2;

    /** The share of a group's entries that must open a field with its own `#`, and use no other, for it to own the `h1`. */
    public const OWNS_H1_SHARE = 0.8;

    /** The fewest entries that count as evidence. */
    public const OWNS_H1_ENTRIES = 3;

    /**
     * @param  string  $key  The addon's key: group, site and blueprint or entry type.
     * @param  string|null  $h1Field  The field that prints the `h1`, when one does (`title`, `hero.heading`).
     * @param  array<string, int>  $fieldLevels  Fields the template prints as headings, other than the `h1`'s: `section.heading` => 2.
     * @param  list<string>  $bodyOwnsH1  Rich-text fields whose own `#` is the page's only `h1`.
     * @param  string  $seenAt  When a render last agreed (ISO 8601); '' for one never rendered.
     * @param  int  $renders  How many renders agreed; 0 for the default or the entries' evidence.
     * @param  array{profile: array<string, mixed>, renders: int}|null  $pending  A different outline seen since, waiting for another render to agree.
     * @param  string  $label  The group's name, for the developer note.
     */
    public function __construct(
        public readonly string $key,
        public readonly H1Source $h1 = H1Source::Title,
        public readonly ?string $h1Field = 'title',
        public readonly array $fieldLevels = [],
        public readonly array $bodyOwnsH1 = [],
        public readonly string $seenAt = '',
        public readonly int $renders = 0,
        public readonly ?array $pending = null,
        public readonly string $label = '',
    ) {}

    /** The title is the `h1`; bodies start at `##`. */
    public static function default(string $key = '', string $label = ''): self
    {
        return new self($key, label: $label);
    }

    /** Whether a render has been seen, rather than assumed. */
    public function rendered(): bool
    {
        return $this->renders > 0;
    }

    /**
     * Where a rich-text field's headings start: one below a heading field
     * the template prints in the same block, when it prints one; else 1
     * for a field whose own `#` is the page's `h1`, when the template
     * prints none; else 2.
     */
    public function top(Field $field, ?string $blockType = null): int
    {
        if ($blockType !== null) {
            $levels = [];

            foreach ($this->fieldLevels as $path => $level) {
                if (str_starts_with($path, $blockType.'.') && $path !== $blockType.'.'.$field->handle) {
                    $levels[] = $level;
                }
            }

            if ($levels !== []) {
                return min(6, max($levels) + 1);
            }

            return 2;
        }

        if (! $this->h1->printsH1() && in_array($field->handle, $this->bodyOwnsH1, true) && in_array(1, HeadingLevels::allowed($field), true)) {
            return 1;
        }

        return 2;
    }

    /**
     * From one render's outline.
     *
     * - The template's own `h1`s (not inside a rich-text value): one from
     *   the title is Title; from another field, Field; from no field (a
     *   logo), Static; more than one, Several; none, None.
     * - With no `h1` of the template's, a rich-text field whose own `#` is
     *   the only `h1` on the page owns it.
     * - Every other field the template prints as a heading keeps its level.
     */
    public static function fromOutline(string $key, Outline $outline, string $seenAt = '', string $label = ''): self
    {
        $h1s = $outline->template(1);

        [$source, $field] = match (true) {
            count($h1s) > 1 => [H1Source::Several, null],
            count($h1s) === 1 && $h1s[0]->field === 'title' => [H1Source::Title, 'title'],
            count($h1s) === 1 && $h1s[0]->field !== null => [H1Source::Field, $h1s[0]->field],
            count($h1s) === 1 => [H1Source::Static, null],
            default => [H1Source::None, null],
        };

        $owns = [];
        $inContent = $outline->content(1);

        if ($source === H1Source::None && count($inContent) === 1 && $inContent[0]->field !== null) {
            $owns[] = $inContent[0]->field;
        }

        $levels = [];

        foreach ($outline->headings as $heading) {
            if ($heading->inContent || $heading->field === null || $heading->field === $field || $heading->field === 'title') {
                continue;
            }

            $levels[$heading->field] = min($levels[$heading->field] ?? 6, $heading->level);
        }

        ksort($levels);

        return new self($key, $source, $field, $levels, $owns, $seenAt, 1, null, $label);
    }

    /**
     * What the group's published entries show, before any render: a
     * top-level rich-text field that at least OWNS_H1_SHARE of them open
     * with their own `#` heading, using no other `#`, owns the `h1` (the
     * template then prints none). Null when no field does.
     *
     * @param  array<int, EntryData>  $entries
     */
    public static function fromEntries(string $key, Schema $schema, array $entries, RichTextDialect $richText, string $label = ''): ?self
    {
        $owns = [];

        foreach ($schema->fields as $field) {
            if (! HeadingLevels::holdsHeadings($field) || ! in_array(1, HeadingLevels::allowed($field), true)) {
                continue;
            }

            $written = 0;
            $opening = 0;

            foreach ($entries as $entry) {
                $value = $entry->values[$field->handle] ?? null;
                $markdown = $field->kind === Kind::RichText ? ($value === null || $value === '' || $value === [] ? null : $richText->toMarkdown($value, $field)) : (is_string($value) ? $value : null);

                if ($markdown === null || trim($markdown) === '') {
                    continue;
                }

                $written++;
                $h1s = preg_match_all('/^ {0,3}#(?!#)\s+\S/m', $markdown);
                $first = ltrim($markdown);

                if ($h1s === 1 && preg_match('/^#(?!#)\s+\S/', $first) === 1) {
                    $opening++;
                }
            }

            if ($written >= self::OWNS_H1_ENTRIES && $opening / $written >= self::OWNS_H1_SHARE) {
                $owns[] = $field->handle;
            }
        }

        return $owns === [] ? null : new self($key, H1Source::None, null, [], $owns, '', 0, null, $label);
    }

    /**
     * The profile to use: a stored one that has seen a render; else what
     * the entries show; else the default.
     *
     * @param  array<int, EntryData>  $entries
     */
    public static function resolve(string $key, ?self $stored, Schema $schema, array $entries, RichTextDialect $richText, string $label = ''): self
    {
        if ($stored !== null && $stored->rendered()) {
            return $stored;
        }

        return self::fromEntries($key, $schema, $entries, $richText, $label) ?? self::default($key, $label);
    }

    /**
     * This stored profile after another render: the same outline counts
     * one more agreeing render; a different one is kept pending, and
     * replaces it once AGREE renders in a row show it. A profile that had
     * seen no render takes the first one straight away.
     */
    public function observe(self $seen): self
    {
        if (! $this->rendered()) {
            return $seen->with(renders: 1, pending: null, label: $seen->label !== '' ? $seen->label : $this->label);
        }

        if ($seen->signature() === $this->signature()) {
            return $this->with(seenAt: $seen->seenAt, renders: $this->renders + 1, pending: null, label: $seen->label !== '' ? $seen->label : $this->label);
        }

        $pending = $this->pending !== null && self::fromArray($this->pending['profile'])->signature() === $seen->signature()
            ? $this->pending['renders'] + 1
            : 1;

        if ($pending >= self::AGREE) {
            return $seen->with(renders: $pending, pending: null, label: $seen->label !== '' ? $seen->label : $this->label);
        }

        return $this->with(pending: ['profile' => $seen->toArray(), 'renders' => $pending], keepPending: true);
    }

    /** What the profile says about the page, without when or how often: two profiles that agree have the same one. */
    public function signature(): string
    {
        return (string) json_encode([$this->h1->value, $this->h1Field, $this->fieldLevels, $this->bodyOwnsH1]);
    }

    /**
     * A template problem for the developer, or null: `no-h1` (the page
     * has no main heading, and no body owns one), `static-h1` (the
     * template prints its own text, such as a logo, as the `h1`) or
     * `several-h1`. Only for a profile a render has shown.
     */
    public function problem(): ?string
    {
        if (! $this->rendered()) {
            return null;
        }

        return match ($this->h1) {
            H1Source::None => $this->bodyOwnsH1 === [] ? 'no-h1' : null,
            H1Source::Static => 'static-h1',
            H1Source::Several => 'several-h1',
            default => null,
        };
    }

    /** The developer note for problem(), in English; null when there is none. */
    public function note(): ?string
    {
        $group = $this->label !== '' ? $this->label : $this->key;

        return match ($this->problem()) {
            'no-h1' => "Pages in {$group} print no main heading (H1). Ghostwriter starts their text at H2 as usual; the template should print the title as an H1.",
            'static-h1' => "Pages in {$group} print fixed text, such as a logo, as their main heading (H1). Ghostwriter starts their text at H2; the template should print the title as the H1 instead.",
            'several-h1' => "Pages in {$group} print more than one main heading (H1). Ghostwriter starts their text at H2; the template should print only the title as an H1.",
            default => null,
        };
    }

    /**
     * @param  array{profile: array<string, mixed>, renders: int}|null  $pending
     */
    public function with(?string $seenAt = null, ?int $renders = null, ?array $pending = null, ?string $label = null, bool $keepPending = false): self
    {
        return new self($this->key, $this->h1, $this->h1Field, $this->fieldLevels, $this->bodyOwnsH1, $seenAt ?? $this->seenAt, $renders ?? $this->renders, $keepPending || $pending !== null ? $pending : null, $label ?? $this->label);
    }

    /**
     * @return array{key: string, h1: string, h1Field: string|null, fieldLevels: array<string, int>, bodyOwnsH1: list<string>, seenAt: string, renders: int, pending: array{profile: array<string, mixed>, renders: int}|null, label: string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'h1' => $this->h1->value,
            'h1Field' => $this->h1Field,
            'fieldLevels' => $this->fieldLevels,
            'bodyOwnsH1' => $this->bodyOwnsH1,
            'seenAt' => $this->seenAt,
            'renders' => $this->renders,
            'pending' => $this->pending,
            'label' => $this->label,
        ];
    }

    /**
     * @param  array<mixed>  $array
     */
    public static function fromArray(array $array): self
    {
        $levels = [];

        foreach (is_array($array['fieldLevels'] ?? null) ? $array['fieldLevels'] : [] as $path => $level) {
            if (is_int($level) && $level >= 1 && $level <= 6) {
                $levels[(string) $path] = $level;
            }
        }

        $pending = $array['pending'] ?? null;
        $pending = is_array($pending) && is_array($pending['profile'] ?? null) && is_int($pending['renders'] ?? null)
            ? ['profile' => self::stringKeys($pending['profile']), 'renders' => $pending['renders']]
            : null;

        return new self(
            is_scalar($array['key'] ?? null) ? (string) $array['key'] : '',
            H1Source::tryFrom(is_string($array['h1'] ?? null) ? $array['h1'] : '') ?? H1Source::Title,
            array_key_exists('h1Field', $array) ? (is_string($array['h1Field']) ? $array['h1Field'] : null) : 'title',
            $levels,
            array_values(array_map('strval', array_filter(is_array($array['bodyOwnsH1'] ?? null) ? $array['bodyOwnsH1'] : [], 'is_string'))),
            is_string($array['seenAt'] ?? null) ? $array['seenAt'] : '',
            is_int($array['renders'] ?? null) ? max(0, $array['renders']) : 0,
            $pending,
            is_string($array['label'] ?? null) ? $array['label'] : '',
        );
    }

    /**
     * @param  array<mixed>  $array
     * @return array<string, mixed>
     */
    private static function stringKeys(array $array): array
    {
        $out = [];

        foreach ($array as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }
}
