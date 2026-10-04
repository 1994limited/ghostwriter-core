<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Layout;

use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Set;
use NineteenNinetyFour\Ghostwriter\Core\Seo\H1Source;
use NineteenNinetyFour\Ghostwriter\Core\Seo\HeadingPolicy;
use NineteenNinetyFour\Ghostwriter\Core\Seo\RenderProfile;

/**
 * Writes a schema out as the brief the model works to: which keys a draft
 * may contain, what each one is for, and how this group's entries are
 * usually assembled. It is the `fields` text of a Studio Layout.
 *
 * Each rich-text field lists the heading levels it takes: from where the
 * template's own headings leave off (Seo\RenderProfile) to what its
 * editor can show (Schema\HeadingLevels).
 */
final class SchemaDescriber
{
    private RenderProfile $profile;

    public function __construct(private readonly LayoutOptions $options = new LayoutOptions)
    {
        $this->profile = RenderProfile::default();
    }

    /**
     * @param  Pattern|array<string, mixed>|null  $pattern  What the pattern finder found; none before anything is published.
     * @param  RenderProfile|null  $profile  How the group's template prints headings; null for the default (the title is the H1).
     */
    public function describe(Schema $schema, Pattern|array|null $pattern = null, ?RenderProfile $profile = null): string
    {
        $this->profile = $profile ?? RenderProfile::default();
        $pattern = $pattern instanceof Pattern ? $pattern->toArray() : ($pattern ?? []);
        $lines = $this->fields($schema->fields, 0, $pattern);

        foreach ($schema->fields as $field) {
            $blocks = $pattern['blocks'][$field->handle] ?? null;

            if ($field->kind !== Kind::Blocks || ! $blocks) {
                continue;
            }

            if ($blocks['sequence']) {
                $lines[] = '';
                $lines[] = "Entries in this {$this->options->group} usually build `{$field->handle}` from these blocks, in this order: ".implode(', ', $blocks['sequence']).'. Follow that order unless the brief gives a reason not to.';
            }

            $boilerplate = $blocks['boilerplate'] ?? [];

            if ($boilerplate) {
                $lines[] = 'These blocks are the same on every entry. Include each with its `type` alone, in its usual place, and its content is copied in for you. Never ask for their text: '.implode(', ', $boilerplate).'.';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, Field>  $fields
     * @param  array<string, mixed>  $pattern
     * @param  array<string, mixed>  $hints  House defaults for these fields, shown as "usually ...".
     * @param  string|null  $blockType  The block these fields are in, if any.
     * @return array<int, string>
     */
    private function fields(array $fields, int $depth, array $pattern = [], array $hints = [], ?string $blockType = null): array
    {
        $lines = [];
        $pad = str_repeat('  ', $depth);

        foreach ($fields as $field) {
            if (! $field->isWritable()) {
                continue;
            }

            $label = $this->kindLabel($field->kind);

            if (in_array($field->kind, [Kind::Choice, Kind::Choices], true)) {
                $label .= ' '.implode(', ', array_keys($field->options));
            }

            $line = "{$pad}- `{$field->handle}` ({$label}".($field->required ? ', required' : '').')';

            if ($field->label !== '' && strcasecmp(str_replace('_', ' ', $field->handle), $field->label) !== 0) {
                $line .= ': '.$field->label;
            }

            if ($field->instructions !== '') {
                $line .= '. '.rtrim($field->instructions, '.');
            }

            if (isset($hints[$field->handle]) && is_string($hints[$field->handle])) {
                $line .= '. Usually "'.$hints[$field->handle].'"';
            }

            if ($field->kind === Kind::RichText) {
                $line .= $this->headings($field, $blockType);
            }

            $lines[] = $line;

            if ($field->kind === Kind::Blocks) {
                array_push($lines, ...$this->blocks($field, $depth + 1, $pattern['blocks'][$field->handle] ?? null));
            } elseif (in_array($field->kind, [Kind::Rows, Kind::Group], true)) {
                array_push($lines, ...$this->fields($field->fields, $depth + 1, blockType: $blockType));
            }
        }

        return $lines;
    }

    /**
     * Blocks the group actually uses are described in full; the rest of a
     * large page builder is only named, to keep the brief readable.
     *
     * @param  array<string, mixed>|null  $pattern
     * @return array<int, string>
     */
    private function blocks(Field $field, int $depth, ?array $pattern): array
    {
        $lines = [];
        $pad = str_repeat('  ', $depth);
        $used = array_keys($pattern['usage'] ?? []);
        $sets = $field->sets;

        // With nothing published yet there is no pattern, so describe them all.
        $full = $used ? array_intersect_key($sets, array_flip($used)) : $sets;
        $rest = array_diff_key($sets, $full);

        $lines[] = "{$pad}Each block is written as `type: <block>` followed by that block's fields"
            .($this->nests($sets) ? '; a block that holds other blocks lists them under `children`' : '').'. Blocks:';

        foreach ($full as $handle => $set) {
            $share = isset($pattern['usage'][$handle]) ? ', on '.round($pattern['usage'][$handle] * 100).'% of entries' : '';

            $lines[] = "{$pad}- `{$handle}`: {$set->label}".($set->instructions !== '' ? '. '.rtrim($set->instructions, '.') : '').$share;

            if (in_array($handle, $pattern['boilerplate'] ?? [], true)) {
                $lines[] = "{$pad}    (the same on every entry; write `type: {$handle}` and nothing else)";

                continue;
            }

            array_push($lines, ...$this->fields(
                $this->worthDescribing($set->fields, $pattern['fixed'][$handle] ?? [], $pattern['used'][$handle] ?? null),
                $depth + 2,
                hints: $pattern['fixed'][$handle] ?? [],
                blockType: (string) $handle,
            ));
        }

        if ($rest) {
            $lines[] = "{$pad}Also available but not normally used here: ".implode(', ', array_map(
                fn (string|int $handle, Set $set) => "`{$handle}` ({$set->label})",
                array_keys($rest),
                $rest,
            )).'.';
        }

        return $lines;
    }

    /**
     * The heading levels a rich-text field takes, for the end of its line:
     * ". Section headings: `##` and `###` (the page's main heading is the
     * title)", or ". No headings: use a bold lead-in instead".
     */
    private function headings(Field $field, ?string $blockType): string
    {
        $policy = HeadingPolicy::for($field, $this->profile, $blockType);

        if (! $policy->allowsHeadings()) {
            return '. No headings: use a bold lead-in instead';
        }

        $main = match (true) {
            $blockType !== null => '',
            $policy->top === 1 => ' (`#` is the page\'s main heading)',
            $this->profile->h1 === H1Source::Title => ' (the page\'s main heading is the title)',
            $this->profile->h1 === H1Source::Field && $this->profile->h1Field !== null => ' (the page\'s main heading is `'.$this->profile->h1Field.'`)',
            default => '',
        };

        return '. Section headings: '.$policy->markdown().$main;
    }

    private function kindLabel(Kind $kind): string
    {
        return match ($kind) {
            Kind::Text => 'short text',
            Kind::LongText => 'plain text',
            Kind::RichText => 'markdown',
            Kind::Choice => 'one of',
            Kind::Choices => 'any of',
            Kind::Toggle => 'true or false',
            Kind::Number => 'number',
            Kind::List => 'list of short strings',
            Kind::Blocks => 'list of blocks',
            Kind::Rows => 'list of rows',
            Kind::Group => 'group',
            Kind::Reference => 'reference',
        };
    }

    /**
     * Whether any of these blocks can hold blocks of its own (Neo).
     *
     * @param  array<string, Set>  $sets
     */
    private function nests(array $sets): bool
    {
        foreach ($sets as $set) {
            foreach ($set->fields as $field) {
                if ($field->engine === Field::CHILDREN && $field->isWritable()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Within a block the group already uses, leave out the fields nobody
     * fills in and the settings that are always the same. They are applied
     * when the entry is built, so the writer need not think about them.
     *
     * @param  array<int, Field>  $fields
     * @param  array<string, mixed>  $fixed
     * @param  array<int, string>|null  $used  Null when the block has never been used.
     * @return array<int, Field>
     */
    private function worthDescribing(array $fields, array $fixed, ?array $used): array
    {
        if ($used === null) {
            return $fields;
        }

        return array_values(array_filter($fields, function (Field $field) use ($fixed, $used) {
            if (! in_array($field->handle, $used, true)) {
                return false;
            }

            return ! (array_key_exists($field->handle, $fixed) && ($field->kind->isSetting() || $field->kind->isCopied()));
        }));
    }
}
