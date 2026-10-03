<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras;

use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Set;

/**
 * Which extras a site can show, and where: the writer is offered only
 * those, so it never prepares something the site has no place for.
 *
 * Found by handle and label, with the field's shape as a check:
 *
 * | Kind          | A page builder's set                                      | Rich text                      |
 * |---------------|-----------------------------------------------------------|--------------------------------|
 * | `stats`       | stats, numbers, figures, facts; or rows of value + label  | —                              |
 * | `faq`         | faq, questions, accordion                                 | headings and paragraphs        |
 * | `pull_quote`  | quote, pull_quote, blockquote                             | a block quote (or quote set)   |
 * | `at_a_glance` | summary, highlights, key_facts, at_a_glance, callout      | a list                         |
 * | `caption`     | a caption or alt field beside an image field              | —                              |
 * | `cta`         | cta, call_to_action, banner                               | —                              |
 * | `testimonial` | testimonial, review                                       | a quote set with an attribution |
 * | `intro`       | intro, lede, standfirst, excerpt; or such a top-level field | the first paragraph          |
 *
 * Only top-level fields and the sets of top-level page builders are looked
 * at. A set that matches two kinds counts for the first in this order.
 */
final class ExtraSlots
{
    /** Set names for each kind, as words of a handle or label. */
    private const NAMES = [
        'at_a_glance' => ['at_a_glance', 'summary', 'highlights', 'highlight', 'key_facts', 'key_points', 'callout'],
        'stats' => ['stats', 'stat', 'statistics', 'numbers', 'figures', 'facts'],
        'faq' => ['faq', 'faqs', 'questions', 'accordion'],
        'testimonial' => ['testimonial', 'testimonials', 'review', 'reviews'],
        'pull_quote' => ['pull_quote', 'pullquote', 'quote', 'blockquote'],
        'cta' => ['cta', 'call_to_action', 'banner'],
        'intro' => ['intro', 'introduction', 'lede', 'standfirst', 'excerpt'],
    ];

    /**
     * @param  array<string, list<array{field: string, set: string|null, label: string}>>  $slots  By ExtraKind value.
     */
    private function __construct(private readonly array $slots) {}

    public static function for(?Schema $schema): self
    {
        $slots = [];

        foreach ($schema === null ? [] : $schema->fields as $field) {
            if ($field->isBuilder()) {
                foreach ($field->sets as $handle => $set) {
                    foreach (self::setKinds((string) $handle, $set) as $kind) {
                        $slots[$kind->value][] = ['field' => $field->handle, 'set' => (string) $handle, 'label' => $set->label !== '' ? $set->label : (string) $handle];
                    }
                }

                continue;
            }

            if (self::isRichText($field)) {
                $label = $field->label !== '' ? $field->label : $field->handle;

                foreach ([ExtraKind::Faq, ExtraKind::PullQuote, ExtraKind::AtAGlance, ExtraKind::Intro] as $kind) {
                    $slots[$kind->value][] = ['field' => $field->handle, 'set' => null, 'label' => $label];
                }

                foreach ($field->sets as $handle => $set) {
                    if (self::named((string) $handle, $set->label, 'testimonial') || (self::named((string) $handle, $set->label, 'pull_quote') && self::hasField($set, ['attribution', 'cite', 'author', 'name']))) {
                        $slots[ExtraKind::Testimonial->value][] = ['field' => $field->handle, 'set' => (string) $handle, 'label' => $set->label !== '' ? $set->label : (string) $handle];
                    }
                }

                continue;
            }

            if (in_array($field->kind, [Kind::Text, Kind::LongText], true) && self::named($field->handle, $field->label, 'intro')) {
                $slots[ExtraKind::Intro->value][] = ['field' => $field->handle, 'set' => null, 'label' => $field->label !== '' ? $field->label : $field->handle];
            }
        }

        $ordered = [];

        foreach (ExtraKind::cases() as $kind) {
            if (isset($slots[$kind->value])) {
                $ordered[$kind->value] = $slots[$kind->value];
            }
        }

        return new self($ordered);
    }

    public function isEmpty(): bool
    {
        return $this->slots === [];
    }

    public function has(ExtraKind $kind): bool
    {
        return isset($this->slots[$kind->value]);
    }

    /**
     * @return list<ExtraKind>
     */
    public function kinds(): array
    {
        return array_values(array_filter(ExtraKind::cases(), fn (ExtraKind $kind) => $this->has($kind)));
    }

    /**
     * Where a kind can go: the top-level field, and the set in it (null for rich text or a plain field).
     *
     * @return list<array{field: string, set: string|null, label: string}>
     */
    public function slots(ExtraKind $kind): array
    {
        return $this->slots[$kind->value] ?? [];
    }

    /** The writer's list: one line per kind, with where it would go. */
    public function describe(): string
    {
        return implode("\n", array_map(function (ExtraKind $kind): string {
            $where = array_values(array_unique(array_map(fn (array $slot) => $slot['set'] === null ? "the {$slot['label']} field" : "the “{$slot['label']}” block", $this->slots($kind))));

            return "- `{$kind->value}`: {$kind->describe()}. It would go in ".implode(' or ', $where).'.';
        }, $this->kinds()));
    }

    /**
     * @return array<string, list<array{field: string, set: string|null, label: string}>>
     */
    public function toArray(): array
    {
        return $this->slots;
    }

    /**
     * @return list<ExtraKind>
     */
    private static function setKinds(string $handle, Set $set): array
    {
        $kinds = [];

        foreach (self::NAMES as $kind => $names) {
            if (self::named($handle, $set->label, $kind)) {
                $kinds[] = ExtraKind::from($kind);

                break;
            }
        }

        if ($kinds === [] && self::statsRows($set)) {
            $kinds[] = ExtraKind::Stats;
        }

        // A caption can go wherever an image has a caption or alt field beside it.
        $image = array_filter($set->fields, fn (Field $field) => $field->files);
        $caption = self::hasField($set, ['caption', 'alt', 'alt_text', 'credit']);

        if ($image !== [] && $caption) {
            $kinds[] = ExtraKind::Caption;
        }

        return $kinds;
    }

    /** Rows whose fields are a value or number and a label. */
    private static function statsRows(Set $set): bool
    {
        foreach ($set->fields as $field) {
            if ($field->kind !== Kind::Rows) {
                continue;
            }

            $handles = array_map(fn (Field $column) => self::words($column->handle.' '.$column->label), $field->fields);
            $value = array_filter($handles, fn (array $words) => array_intersect($words, ['value', 'number', 'figure', 'stat', 'amount']) !== []);
            $label = array_filter($handles, fn (array $words) => array_intersect($words, ['label', 'caption', 'text', 'description']) !== []);

            if ($value !== [] && $label !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $names
     */
    private static function hasField(Set $set, array $names): bool
    {
        foreach ($set->fields as $field) {
            if ($field->isWritable() && array_intersect(self::words($field->handle), $names) !== []) {
                return true;
            }

            if ($field->isWritable() && in_array(self::slug($field->handle), $names, true)) {
                return true;
            }
        }

        return false;
    }

    private static function named(string $handle, string $label, string $kind): bool
    {
        foreach ([$handle, $label] as $name) {
            $slug = self::slug($name);

            foreach (self::NAMES[$kind] ?? [] as $candidate) {
                if ($slug === $candidate || str_starts_with($slug, $candidate.'_') || str_ends_with($slug, '_'.$candidate) || str_contains($slug, '_'.$candidate.'_')) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function isRichText(Field $field): bool
    {
        return $field->kind === Kind::RichText
            || ($field->kind === Kind::LongText && ($field->type === 'markdown' || ($field->meta['format'] ?? null) === 'markdown'));
    }

    /** "Pull Quote", "pullQuote" and "pull-quote" are all "pull_quote". */
    private static function slug(string $name): string
    {
        $name = (string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', trim($name));

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_');
    }

    /**
     * @return list<string>
     */
    private static function words(string $name): array
    {
        return array_values(array_filter(explode('_', self::slug($name))));
    }
}
