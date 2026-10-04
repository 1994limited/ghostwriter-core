<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Layout;

use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\NoLinks;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Set;
use NineteenNinetyFour\Ghostwriter\Core\Text\EntrySimplifier;

/**
 * Studies the entries a group already has to find out how its pages are
 * really put together, which the schema alone cannot say: a page builder
 * allows thirty blocks, but the articles only ever use six, in one order.
 *
 * It finds, for each page builder, the usual sequence of blocks and how
 * often each is used; the values that are the same on nearly every entry,
 * which are house defaults rather than writing; the newest entries as
 * examples; how often each field is filled; and the house style.
 *
 * The adapter finds the entries: the group's published ones, newest first
 * (choose() narrows them as the addons did), or the ones a person picked.
 */
final class PatternFinder
{
    /** Entries studied at most. */
    public const SAMPLE = 30;

    /** A value shared by this share of entries is a house default. */
    private const FIXED_SHARE = 0.8;

    /** Keys that are bookkeeping, never content. */
    private const BOOKKEEPING = ['id', 'type', 'enabled'];

    private readonly HouseStyle $house;

    private readonly EntrySimplifier $simplifier;

    public function __construct(
        private readonly LayoutOptions $options = new LayoutOptions,
        RichTextDialect $richText = new HtmlDialect,
        LinkDialect $links = new NoLinks,
    ) {
        $this->house = new HouseStyle($options, $richText, $links);
        $this->simplifier = self::simplifier($richText);
    }

    /**
     * The simplifier that shows entries to the model, reading rich text
     * through the dialect.
     */
    public static function simplifier(RichTextDialect $richText): EntrySimplifier
    {
        return new EntrySimplifier(richText: fn (mixed $value, array $spec) => $richText->toMarkdown($value, Field::fromSpec($spec)));
    }

    /**
     * The entries to learn from, as the addons chose them: those matching
     * `where` (a field => value they must have, or contain for a list),
     * or every one while none match, at most SAMPLE.
     *
     * @param  array<int, EntryData>  $entries  The group's published entries, newest first.
     * @param  array<string, mixed>  $where
     * @return array<int, EntryData>
     */
    public static function choose(array $entries, array $where = []): array
    {
        $entries = array_values($entries);
        $matching = $where === [] ? $entries : array_values(array_filter($entries, fn (EntryData $entry) => self::matches($entry, $where)));

        return array_slice($matching === [] ? $entries : $matching, 0, self::SAMPLE);
    }

    /**
     * @param  array<int, EntryData>  $entries  The entries to learn from, newest first.
     */
    public function find(Schema $schema, array $entries): Pattern
    {
        $entries = array_values($entries);
        $specs = $schema->toSpecs();
        $data = array_map(fn (EntryData $entry) => $entry->values, $entries);
        $simplified = array_map(fn (array $entry) => $this->simplifier->simplify($entry, $specs), $data);

        $blocks = [];

        foreach ($schema->fields as $field) {
            if ($field->kind === Kind::Blocks) {
                $blocks[$field->handle] = $this->blockPattern(array_values(array_filter(array_column($data, $field->handle))), $field->sets);
            }
        }

        $words = array_map(fn (array $entry) => str_word_count(json_encode($entry) ?: ''), $simplified);
        sort($words);

        $writable = array_map(fn (Field $field) => $field->handle, $schema->writable());

        return new Pattern(
            entries: count($entries),
            words: $words === [] ? 0 : (int) $words[intdiv(count($words), 2)],
            blocks: $blocks,
            fixed: $this->fixedValues($data, array_merge($this->options->bookkeeping, array_keys($blocks), $writable)),
            examples: array_values(array_map(fn (array $example) => $this->withoutDefaults($example, $schema, $blocks), array_slice($simplified, 0, 2))),
            filled: $this->fillRates($data, $schema->fields),
            house: $this->house->learn($entries, $schema),
        );
    }

    /**
     * How often each field holds something (FillRates).
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, Field>  $fields
     * @return array<string, float>
     */
    private function fillRates(array $items, array $fields): array
    {
        return FillRates::of($items, $fields);
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private static function matches(EntryData $entry, array $where): bool
    {
        foreach ($where as $field => $expected) {
            if (! in_array($expected, (array) ($entry->values[$field] ?? null), false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, mixed>  $fields  One page builder's value from each entry.
     * @param  array<string, Set>  $available  The builder's sets.
     * @return array{sequence: array<int, string>, usage: array<string, float>, fixed: array<string, array<string, mixed>>, used: array<string, array<int, string>>, boilerplate: array<int, string>}
     */
    private function blockPattern(array $fields, array $available = []): array
    {
        $sequences = [];
        $byType = [];
        $entriesUsing = [];

        foreach ($fields as $sets) {
            $sequence = [];

            foreach ((array) $sets as $set) {
                if (! is_array($set) || ! isset($set['type']) || ! is_scalar($set['type']) || ($set['enabled'] ?? true) === false) {
                    continue;
                }

                $sequence[] = (string) $set['type'];
                $byType[(string) $set['type']][] = $set;
            }

            $sequences[] = $sequence;

            foreach (array_unique($sequence) as $type) {
                $entriesUsing[$type] = ($entriesUsing[$type] ?? 0) + 1;
            }
        }

        $total = max(count($fields), 1);

        arsort($entriesUsing);

        $fixed = array_filter(array_map(fn (array $items) => $this->fixedValues($items, self::BOOKKEEPING, reused: true), $byType));
        $used = array_map(fn (array $items) => $this->usedKeys($items), $byType);

        return [
            'sequence' => $this->commonest($sequences),
            'usage' => array_map(fn (int $count) => round($count / $total, 2), $entriesUsing),
            'fixed' => $fixed,
            'used' => $used,
            'boilerplate' => array_values(array_filter(
                array_map('strval', array_keys($byType)),
                fn (string $type) => count($byType[$type]) >= 2 && $this->isBoilerplate(isset($available[$type]) ? $available[$type]->fields : [], $fixed[$type] ?? [], $used[$type]),
            )),
        ];
    }

    /**
     * A block is boilerplate when nothing in it is written afresh each time:
     * every field of content that gets used holds a house default. Process
     * steps and testimonials are typical; so is a spacer. Such a block is
     * copied, not written. A setting that varies, such as a background,
     * does not make a block's content any less fixed.
     *
     * @param  array<int, Field>  $fields
     * @param  array<string, mixed>  $fixed
     * @param  array<int, string>  $used
     */
    private function isBoilerplate(array $fields, array $fixed, array $used): bool
    {
        foreach ($fields as $field) {
            // Settings and references (images, links) are not writing.
            $isContent = $field->isWritable() && ! $field->kind->isSetting();

            if ($isContent && in_array($field->handle, $used, true) && ! array_key_exists($field->handle, $fixed)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The order of blocks used most often. Entries are newest first, so a tie
     * goes to the most recent way of doing it.
     *
     * @param  array<int, array<int, string>>  $sequences
     * @return array<int, string>
     */
    private function commonest(array $sequences): array
    {
        $counts = self::sequences($sequences);

        if ($counts === []) {
            return [];
        }

        $best = array_search(max($counts), $counts, true);

        return $best === '' ? [] : explode('>', (string) $best);
    }

    /**
     * How many entries use each distinct order of blocks, keyed by the
     * types joined with ">", in the order first seen (newest first). The
     * commonest is the pattern's `sequence`; Arrange\SitePatterns keeps
     * them all.
     *
     * @param  array<int, array<int, string>>  $sequences
     * @return array<string, int>
     */
    public static function sequences(array $sequences): array
    {
        $counts = [];

        foreach ($sequences as $sequence) {
            $key = implode('>', $sequence);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * The order of a page builder's blocks in one entry's value: the types
     * of its blocks, leaving out any switched off.
     *
     * @return array<int, string>
     */
    public static function sequenceOf(mixed $blocks): array
    {
        $sequence = [];

        foreach ((array) $blocks as $set) {
            if (! is_array($set) || ! isset($set['type']) || ! is_scalar($set['type']) || ($set['enabled'] ?? true) === false) {
                continue;
            }

            $sequence[] = (string) $set['type'];
        }

        return $sequence;
    }

    /**
     * Keys whose value is identical on nearly every item.
     *
     * With `reused`, structured content (rows, blocks) also counts when no
     * item has a version of its own: a page builder's process steps might
     * come in a "website" and an "app" wording, each pasted onto several
     * pages. Nobody writes those afresh, so the commonest version is used.
     *
     * @param  array<int, mixed>  $items
     * @param  array<int, string>  $ignore
     * @return array<string, mixed>
     */
    private function fixedValues(array $items, array $ignore, bool $reused = false): array
    {
        // A single item proves nothing about a house default.
        if (count($items) < 2) {
            return [];
        }

        $seen = [];
        $samples = [];

        foreach ($items as $item) {
            foreach ((array) $item as $key => $value) {
                if (in_array($key, $ignore, true)) {
                    continue;
                }

                // Blocks and rows carry IDs, so two copies of the same
                // content only compare equal once those are set aside.
                $encoded = json_encode($this->withoutIds($value));

                $seen[$key][$encoded] = ($seen[$key][$encoded] ?? 0) + 1;
                $samples[$key][$encoded] ??= $value;
            }
        }

        $fixed = [];

        foreach ($seen as $key => $values) {
            arsort($values);
            $encoded = array_key_first($values);

            $sample = $samples[$key][$encoded];
            $neverUnique = $reused && is_array($sample) && $sample !== [] && min($values) >= 2 && array_sum($values) === count($items);

            // Empty is not a house default; it is a field nobody uses.
            if ($sample === null || $sample === '' || $sample === []) {
                continue;
            }

            if ($values[$encoded] / count($items) >= self::FIXED_SHARE || $neverUnique) {
                $fixed[(string) $key] = $sample;
            }
        }

        return $fixed;
    }

    /**
     * The keys that hold something on at least one item. A field nobody has
     * ever filled in is not part of how this group is written.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, string>
     */
    private function usedKeys(array $items): array
    {
        $used = [];

        foreach ($items as $item) {
            foreach ($item as $key => $value) {
                if ($value !== null && $value !== '' && $value !== [] && ! in_array($key, self::BOOKKEEPING, true)) {
                    $used[$key] = true;
                }
            }
        }

        return array_map('strval', array_keys($used));
    }

    /**
     * Examples are shown without the settings that are the same everywhere,
     * so what is left is the writing.
     *
     * @param  array<string, mixed>  $example
     * @param  array<string, array<string, mixed>>  $blocks
     * @return array<string, mixed>
     */
    private function withoutDefaults(array $example, Schema $schema, array $blocks): array
    {
        foreach ($schema->fields as $field) {
            if ($field->kind !== Kind::Blocks || ! isset($example[$field->handle]) || ! is_array($example[$field->handle])) {
                continue;
            }

            $pattern = $blocks[$field->handle];

            $example[$field->handle] = array_map(function ($block) use ($field, $pattern) {
                if (! is_array($block)) {
                    return $block;
                }

                // A boilerplate block is copied when the entry is built, so
                // the writer only needs to see where it goes.
                if (in_array($block['type'], $pattern['boilerplate'], true)) {
                    return ['type' => $block['type']];
                }

                $set = $field->set((string) $block['type']);
                $kinds = [];

                foreach ($set === null ? [] : $set->fields as $setField) {
                    $kinds[$setField->handle] = $setField->kind;
                }

                foreach ($pattern['fixed'][$block['type']] ?? [] as $key => $value) {
                    if (! array_key_exists($key, $block)) {
                        continue;
                    }

                    $kind = $kinds[$key] ?? null;
                    $isSetting = $kind?->isSetting() && $block[$key] === $value;

                    if ($isSetting || $kind?->isCopied()) {
                        unset($block[$key]);
                    }
                }

                return $block;
            }, $example[$field->handle]);
        }

        return $example;
    }

    /**
     * @return mixed The value with every `id` key removed, at any depth.
     */
    private function withoutIds(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        unset($value['id']);

        return array_map(fn ($item) => $this->withoutIds($item), $value);
    }
}
