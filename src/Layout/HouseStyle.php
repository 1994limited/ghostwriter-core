<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Layout;

use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\NoLinks;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;

/**
 * What the model entries agree on, place by place, that the pattern
 * finder's "same on every block of this type" cannot see:
 *
 *   positions  settings and links by where a block sits. The first spacer on
 *              a page is 45/65, the last 60/100; the first two breadcrumbs
 *              are always Home and Studio. Agreed values are copied into the
 *              same place in a new entry.
 *   sequences  how many items, of which types, a builder nested in a block
 *              usually holds, so three breadcrumbs are made where pages
 *              have three.
 *   markup     how rich text is dressed in each place. A hero heading that is
 *              always centred, white and uppercase gets the same dressing;
 *              the writer only writes words.
 *
 * A link from a page to itself, such as a breadcrumb's last step, is
 * recognised as one: it is stored as "this page" and becomes a link to the
 * new entry, with its title. Where two or more model pages link to
 * themselves in the same place and none links anywhere else, the new page
 * does too, however many leave it empty.
 *
 * A link the pages usually have, or that is required, but that nothing
 * settles, points at https://example.com so the page works and the gap is
 * plain to see; it is listed with the places still to fill. With
 * LayoutOptions::$linkSentinels on it is marked with the `#gw-link:`
 * sentinel instead (LinkPlaceholders), with the field's label as the hint.
 *
 * How rich text and links are stored is the dialects' to say.
 */
final class HouseStyle
{
    /**
     * Share of the model entries that must agree for a link or other
     * structured value to be copied: a wrong link is worse than none.
     */
    private const AGREED = 0.8;

    /**
     * For a setting (a number, a choice, a word) the commonest is taken
     * once more than half agree: a spacer needs some height, and the usual
     * one is the best guess.
     */
    private const MAJORITY = 0.5;

    private const BOOKKEEPING = ['id', 'type', 'enabled'];

    public function __construct(
        private readonly LayoutOptions $options = new LayoutOptions,
        private readonly RichTextDialect $richText = new HtmlDialect,
        private readonly LinkDialect $links = new NoLinks,
    ) {}

    /**
     * @param  array<int, EntryData>  $entries  The model entries, newest first. Each one's ID finds its links to itself.
     */
    public function learn(array $entries, Schema $schema): HouseRules
    {
        $items = [];
        $lists = [];
        $rich = [];
        $links = [];

        foreach (array_values($entries) as $entry) {
            $own = [];
            $this->collect($entry->values, $schema->fields, '', '', $own, $lists, $rich, $links);
            $title = $entry->values['title'] ?? '';

            // A link to the entry itself reads the same on every entry once
            // it is written as "this page".
            foreach ($own as $path => $found) {
                foreach ($found as $item) {
                    $items[$path][] = $entry->id !== null ? $this->links->generalise($item, $entry->id, is_scalar($title) ? (string) $title : '') : $item;
                }
            }
        }

        $positions = [];

        foreach ($items as $path => $found) {
            if (count($found) >= 2 && ($agreed = $this->agreed($found, count($entries))) !== []) {
                $positions[$path] = $agreed;
            }
        }

        $sequences = [];

        foreach ($lists as $path => $found) {
            $counts = [];

            foreach ($found as $sequence) {
                $counts[implode('>', $sequence)] = ($counts[implode('>', $sequence)] ?? 0) + 1;
            }

            arsort($counts);
            $best = (string) array_key_first($counts);

            if (count($found) >= 2 && $counts[$best] / count($found) >= self::AGREED && $best !== '') {
                $sequences[$path] = explode('>', $best);
            }
        }

        $markup = [];

        foreach ($rich as $path => $samples) {
            if (count($samples) >= 2 && ($shapes = $this->richText->shapes($samples)) !== []) {
                $markup[$path] = $shapes;
            }
        }

        // How often each kind of block has its link set, wherever it sits.
        $linked = array_map(fn (array $found) => array_sum($found) / count($found), $links);

        return new HouseRules($positions, $sequences, $markup, $linked);
    }

    /**
     * Fill a new entry's data from the house style: agreed values where the
     * draft left a place empty, nested items where the draft has none, and
     * the house dressing on the writer's rich text.
     *
     * @param  array<string, mixed>  $data  The entry's data, as EntryBuilder built it.
     * @param  int|string|null  $id  The new entry's ID, for links to itself. Without one they wait for linkToSelf().
     */
    public function apply(array $data, Schema $schema, HouseRules $style, int|string|null $id = null, string $title = ''): HouseResult
    {
        $toFill = [];
        $data = $this->fill($data, $schema->fields, $style, $toFill, $id, $title, '', '', '');

        return new HouseResult($data, $toFill, $this->options->item);
    }

    /**
     * Once the entry exists: the links to "this page" that apply() held
     * back, now pointing at it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function linkToSelf(array $data, Schema $schema, HouseRules $style, int|string $id, string $title): array
    {
        return $this->linkFields($data, $schema->fields, $style, $id, $title, '');
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, Field>  $fields
     * @param  array<int, string>  $toFill
     * @return array<string, mixed>
     */
    private function fill(array $data, array $fields, HouseRules $style, array &$toFill, int|string|null $id, string $title, string $path, string $shape, string $label): array
    {
        foreach ($fields as $spec) {
            $handle = $spec->handle;
            $value = $data[$handle] ?? null;

            if ($spec->kind === Kind::RichText && $this->richText->isWritten($value)) {
                $data[$handle] = $this->richText->dress($value, $style->markup["{$shape}.{$handle}"] ?? []);

                continue;
            }

            if (! $spec->isBuilder()) {
                continue;
            }

            $base = $path === '' ? $handle : "{$path}/{$handle}";
            $shapeBase = $shape === '' ? $handle : "{$shape}/{$handle}";

            // A builder the draft left empty, such as breadcrumbs, made as
            // the model entries have it.
            if ((! is_array($value) || $value === []) && isset($style->sequences[$base])) {
                $value = array_map(fn (string $type) => $this->newBlock() + ['type' => $type, 'enabled' => true], $style->sequences[$base]);
            }

            if (! is_array($value)) {
                continue;
            }

            $seen = [];

            foreach ($value as $i => $block) {
                $type = is_array($block) && is_string($block['type'] ?? null) ? $block['type'] : null;
                $set = $type !== null ? $spec->set($type) : null;

                if ($set === null || ! is_array($block)) {
                    continue;
                }

                $n = $seen[$type] = ($seen[$type] ?? -1) + 1;
                $here = "{$base}/{$type}#{$n}";
                $name = ($label === '' ? '' : "{$label}: ").$set->label.(count(array_filter($value, fn ($other) => is_array($other) && ($other['type'] ?? null) === $type)) > 1 ? ' '.($n + 1) : '');

                foreach ($style->positions[$here] ?? [] as $key => $agreed) {
                    if (! isset($block[$key]) || $block[$key] === '' || $block[$key] === []) {
                        $filled = $this->specific($this->withoutIds($agreed), $id, $title);

                        // A link to "this page" waits until there is a page to link to.
                        if (! $this->mentionsSelf($filled)) {
                            $block[$key] = $filled;
                        }
                    }
                }

                foreach ($set->fields as $field) {
                    $handleHere = $field->handle;

                    if ($field->isWritable() || ! empty($block[$handleHere]) || $field->isBuilder()) {
                        continue;
                    }

                    // A link to "this page" is coming once the page exists.
                    if ($this->mentionsSelf($style->positions[$here][$handleHere] ?? null)) {
                        continue;
                    }

                    // A link the block should have that nothing settles goes
                    // to example.com for now, so the page works and the gap shows.
                    $expected = $field->required || ($style->links["{$shapeBase}/{$type}.{$handleHere}"] ?? 0) >= self::MAJORITY;
                    $placeholder = $expected ? $this->placeholder($field, $set->fields) : null;

                    if ($placeholder !== null) {
                        foreach ($placeholder as $key => $stand) {
                            if ($key === $handleHere) {
                                $block[$key] = $stand;
                            } else {
                                $block[$key] ??= $stand;
                            }
                        }

                        $toFill[] = $this->sentinels() ? "{$name} (link still to choose)" : "{$name} (links to example.com for now)";
                    } elseif ($this->options->unsettled === LayoutOptions::NAME_LINKS) {
                        // Entries to pick cannot be stood in for; name the gap.
                        if ($expected && $this->links->holdsLinks($field)) {
                            $toFill[] = "{$name}: ".($field->label ?: $field->handle);
                        }
                    } elseif ($path !== '' && ! $field->files) {
                        // A link the model entries do not agree on is a
                        // person's to choose; name it so it is not missed.
                        // Images are marked by the placeholders instead.
                        $toFill[] = $name;
                    }
                }

                $value[$i] = $this->fill($block, $set->fields, $style, $toFill, $id, $title, $here, "{$shapeBase}/{$type}", $name);
            }

            $data[$handle] = $value;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, Field>  $fields
     * @return array<string, mixed>
     */
    private function linkFields(array $data, array $fields, HouseRules $style, int|string $id, string $title, string $path): array
    {
        foreach ($fields as $spec) {
            if (! $spec->isBuilder() || ! is_array($data[$spec->handle] ?? null)) {
                continue;
            }

            $base = $path === '' ? $spec->handle : "{$path}/{$spec->handle}";
            $seen = [];

            foreach ($data[$spec->handle] as $i => $block) {
                $type = is_array($block) && is_string($block['type'] ?? null) ? $block['type'] : null;
                $set = $type !== null ? $spec->set($type) : null;

                if ($set === null || ! is_array($block)) {
                    continue;
                }

                $n = $seen[$type] = ($seen[$type] ?? -1) + 1;
                $here = "{$base}/{$type}#{$n}";

                foreach ($style->positions[$here] ?? [] as $key => $agreed) {
                    if ((! isset($block[$key]) || $block[$key] === '' || $block[$key] === []) && $this->mentionsSelf($agreed)) {
                        $block[$key] = $this->specific($this->withoutIds($agreed), $id, $title);
                    }
                }

                $data[$spec->handle][$i] = $this->linkFields($block, $set->fields, $style, $id, $title, $here);
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, Field>  $fields
     * @param  array<string, array<int, array<string, mixed>>>  $items
     * @param  array<string, array<int, array<int, string>>>  $lists
     * @param  array<string, array<int, mixed>>  $rich
     * @param  array<string, array<int, int>>  $links  Per kind of block and link field, 1 where set and 0 where not.
     */
    private function collect(array $data, array $fields, string $path, string $shape, array &$items, array &$lists, array &$rich, array &$links): void
    {
        foreach ($fields as $spec) {
            $handle = $spec->handle;
            $value = $data[$handle] ?? null;

            if ($spec->kind === Kind::RichText && $this->richText->isWritten($value)) {
                $rich["{$shape}.{$handle}"][] = $value;

                continue;
            }

            if (! $spec->isBuilder() || ! is_array($value)) {
                continue;
            }

            $base = $path === '' ? $handle : "{$path}/{$handle}";
            $shapeBase = $shape === '' ? $handle : "{$shape}/{$handle}";
            $seen = [];
            $sequence = [];

            foreach ($value as $block) {
                if (! is_array($block) || ! is_string($block['type'] ?? null) || ($block['enabled'] ?? true) === false) {
                    continue;
                }

                $type = $block['type'];
                $set = $spec->set($type);

                if ($set === null) {
                    continue;
                }

                $n = $seen[$type] = ($seen[$type] ?? -1) + 1;
                $here = "{$base}/{$type}#{$n}";
                $sequence[] = $type;

                // Only the block's own plain values; builders inside it are
                // taken place by place, below.
                $own = [];

                foreach ($set->fields as $field) {
                    $plain = ! $field->isBuilder() && ($this->options->richTextInPositions || $field->kind !== Kind::RichText);

                    if ($plain && array_key_exists($field->handle, $block)) {
                        $own[$field->handle] = $block[$field->handle];
                    }

                    if ($this->links->holdsLinks($field)) {
                        $links["{$shapeBase}/{$type}.{$field->handle}"][] = $this->links->hasLink($block[$field->handle] ?? null) ? 1 : 0;
                    }
                }

                $items[$here][] = $own;

                $this->collect($block, $set->fields, $here, "{$shapeBase}/{$type}", $items, $lists, $rich, $links);
            }

            // Only builders inside blocks: the page builder's own order is
            // the pattern finder's to say.
            if ($path !== '') {
                $lists[$base][] = $sequence;
            }
        }
    }

    /**
     * Values that most entries agree on in one place. Empty is not a value.
     *
     * @param  array<int, array<string, mixed>>  $found
     * @return array<string, mixed>
     */
    private function agreed(array $found, int $entries): array
    {
        $seen = [];
        $samples = [];

        foreach ($found as $item) {
            foreach ($item as $key => $value) {
                if (in_array($key, self::BOOKKEEPING, true) || $value === null || $value === '' || $value === []) {
                    continue;
                }

                $encoded = (string) json_encode($this->withoutIds($value));
                $seen[$key][$encoded] = ($seen[$key][$encoded] ?? 0) + 1;
                $samples[$key][$encoded] ??= $value;
            }
        }

        $agreed = [];

        foreach ($seen as $key => $values) {
            arsort($values);
            $encoded = (string) array_key_first($values);
            $sample = $samples[$key][$encoded];

            // Counted against every model entry, so a value only some pages
            // have is not taken for the house's.
            $share = $values[$encoded] / max($entries, count($found));
            $structured = is_array($sample) || $this->links->looksLikeLink($sample);
            $needed = $structured ? self::AGREED : self::MAJORITY;

            // A link is copied when nearly every entry has it, or when more
            // than half do and none has anything different: a page that
            // leaves it empty is not a page that disagrees.
            $unopposed = count($values) === 1 && $share > self::MAJORITY;

            // A link to the page itself is copied when two or more pages
            // have one there and every link there is to the page itself,
            // whatever each calls it.
            $selfLinks = array_filter(array_keys($values), fn (string|int $value) => $this->mentionsSelf(json_decode((string) $value, true)));
            $toSelf = count($selfLinks) === count($values) && array_sum(array_intersect_key($values, array_flip($selfLinks))) >= 2;

            if ($share > $needed || $toSelf || ($structured && ($share >= $needed || $unopposed))) {
                $agreed[$key] = $sample;
            }
        }

        return $agreed;
    }

    /**
     * What a link still to choose holds: the sentinel where the options ask
     * for it and the dialect can, else example.com.
     *
     * @param  array<int, Field>  $siblings
     * @return array<string, mixed>|null
     */
    private function placeholder(Field $field, array $siblings): ?array
    {
        if ($this->sentinels() && $this->links instanceof LinkPlaceholders) {
            return $this->links->placeholderFor($field, $siblings, $field->label !== '' ? $field->label : $field->handle);
        }

        return $this->links->placeholder($field, $siblings);
    }

    private function sentinels(): bool
    {
        return $this->options->linkSentinels && $this->links instanceof LinkPlaceholders;
    }

    /**
     * "This page" made into the new entry and its title.
     */
    private function specific(mixed $value, int|string|null $id, string $title): mixed
    {
        if ($value === LinkDialect::SELF) {
            return $id !== null ? $this->links->toSelf($id) : LinkDialect::SELF;
        }

        if ($value === LinkDialect::TITLE) {
            return $title;
        }

        return is_array($value) ? array_map(fn ($item) => $this->specific($item, $id, $title), $value) : $value;
    }

    private function mentionsSelf(mixed $value): bool
    {
        if ($value === LinkDialect::SELF) {
            return true;
        }

        return is_array($value) && array_filter($value, fn ($item) => $this->mentionsSelf($item)) !== [];
    }

    /**
     * @return array<string, string>
     */
    private function newBlock(): array
    {
        return $this->options->newId !== null ? ['id' => ($this->options->newId)()] : [];
    }

    private function withoutIds(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        unset($value['id']);

        return array_map(fn ($item) => $this->withoutIds($item), $value);
    }
}
