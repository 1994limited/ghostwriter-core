<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Layout\Support;

use Closure;
use League\CommonMark\CommonMarkConverter;
use NineteenNinetyFour\Ghostwriter\Core\Layout\RichTextDialect;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;

/**
 * Statamic's rich text, as its adapter will provide it in stage 2: a `bard`
 * field stores a ProseMirror node list (or HTML with `save_html`), a
 * `markdown` field stores markdown. It is here, built from the Statamic
 * addon's own code, so the golden fixtures can check the Statamic outputs
 * whole and so the RichTextDialect interface is known to fit Bard.
 *
 * The house style learns, for each kind of text node (a heading of each
 * level, paragraph, blockquote, lists), its attributes and the marks on all
 * of its words.
 */
final class BardDialect implements RichTextDialect
{
    /** Bard nodes whose dressing is learned. */
    private const TEXT_NODES = ['heading', 'paragraph', 'blockquote', 'bulletList', 'orderedList'];

    private const MAJORITY = 0.5;

    /** @var Closure(): string */
    private readonly Closure $newId;

    /**
     * @param  (callable(): string)|null  $newId  The ID a pull-quote set is stored with.
     */
    public function __construct(
        private readonly MarkdownToBard $toBard = new MarkdownToBard,
        private readonly BardToMarkdown $toMarkdown = new BardToMarkdown,
        ?callable $newId = null,
    ) {
        $this->newId = Closure::fromCallable($newId ?? fn (): string => bin2hex(random_bytes(4)));
    }

    public function fromMarkdown(string $markdown, Field $field): mixed
    {
        if ($field->type !== 'bard') {
            return $markdown;
        }

        if ($field->meta['save_html'] ?? false) {
            return trim((string) (new CommonMarkConverter(['html_input' => 'escape', 'allow_unsafe_links' => false]))->convert($markdown));
        }

        return $this->withQuoteSets($this->toBard->convert($markdown), $field);
    }

    public function toMarkdown(mixed $value, Field $field): ?string
    {
        return is_array($value) ? $this->toMarkdown->convert($value) : trim(is_scalar($value) ? (string) $value : '');
    }

    public function isWritten(mixed $value): bool
    {
        return is_array($value) && $value !== [];
    }

    public function shapes(array $samples): array
    {
        $byType = [];

        foreach ($samples as $nodes) {
            $seenHere = [];

            foreach ((array) $nodes as $node) {
                $kind = is_array($node) ? $this->kindOf($node) : null;

                // One vote per sample per kind of node: the first such node.
                if ($kind === null || isset($seenHere[$kind]) || ! is_array($node)) {
                    continue;
                }

                $seenHere[$kind] = true;
                $shape = $this->shapeOf($node);
                $byType[$kind][(string) json_encode($shape)][] = $shape;
            }
        }

        $shapes = [];

        foreach ($byType as $kind => $variants) {
            uasort($variants, fn (array $a, array $b) => count($b) <=> count($a));
            $best = reset($variants);
            $total = array_sum(array_map('count', $variants));

            // A plain node is the default and needs nothing.
            if ($total >= 2 && count($best) / $total > self::MAJORITY && ($best[0]['attrs'] !== [] || $best[0]['marks'] !== [])) {
                $shapes[$kind] = $best[0];
            }
        }

        return $shapes;
    }

    public function dress(mixed $value, array $shapes): mixed
    {
        if ($shapes === [] || ! is_array($value)) {
            return $value;
        }

        foreach ($value as $i => $node) {
            $kind = is_array($node) ? $this->kindOf($node) : null;
            $shape = $kind !== null ? ($shapes[$kind] ?? null) : null;

            if ($shape === null || ! is_array($node)) {
                continue;
            }

            // Only plain nodes: anything the writer dressed is left as it is.
            $plain = $this->shapeOf($node);

            if ($plain['attrs'] !== [] || $plain['marks'] !== []) {
                continue;
            }

            $node['attrs'] = array_merge((array) ($node['attrs'] ?? []), (array) $shape['attrs']);

            if ($shape['marks'] !== []) {
                foreach ((array) ($node['content'] ?? []) as $j => $child) {
                    if (is_array($child) && ($child['type'] ?? null) === 'text') {
                        $node['content'][$j]['marks'] = $shape['marks'];
                    }
                }
            }

            $value[$i] = $node;
        }

        return $value;
    }

    /**
     * "heading2", "paragraph": a heading's level is part of what it is, not
     * how it is dressed.
     *
     * @param  array<mixed>  $node
     */
    private function kindOf(array $node): ?string
    {
        $type = (string) ($node['type'] ?? '');

        if (! in_array($type, self::TEXT_NODES, true)) {
            return null;
        }

        return $type === 'heading' ? 'heading'.(int) ($node['attrs']['level'] ?? 1) : $type;
    }

    /**
     * @param  array<mixed>  $node
     * @return array{attrs: array<mixed>, marks: array<int, mixed>}
     */
    private function shapeOf(array $node): array
    {
        $attrs = array_filter((array) ($node['attrs'] ?? []), fn ($value, $key) => $key !== 'level' && $value !== null && $value !== '', ARRAY_FILTER_USE_BOTH);
        ksort($attrs);

        // Marks every piece of text in the node carries.
        $marks = null;

        foreach ((array) ($node['content'] ?? []) as $child) {
            if (! is_array($child) || ($child['type'] ?? null) !== 'text') {
                continue;
            }

            $own = array_map(fn ($mark) => json_encode($mark), (array) ($child['marks'] ?? []));
            $marks = $marks === null ? $own : array_values(array_intersect($marks, $own));
        }

        return ['attrs' => $attrs, 'marks' => array_map(fn ($mark) => json_decode((string) $mark, true), $marks ?? [])];
    }

    /**
     * Where a Bard field offers a pull-quote style set, block quotes are
     * stored as that set, which is what editors on the site would have used.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @return array<int, array<string, mixed>>
     */
    private function withQuoteSets(array $nodes, Field $field): array
    {
        $quoteSet = null;
        $quoteField = null;

        foreach ($field->sets as $handle => $set) {
            if (! str_contains((string) $handle, 'quote')) {
                continue;
            }

            foreach ($set->fields as $setField) {
                if (in_array($setField->kind->value, ['text', 'longtext'], true)) {
                    [$quoteSet, $quoteField] = [$handle, $setField->handle];
                    break 2;
                }
            }
        }

        if ($quoteSet === null) {
            return $nodes;
        }

        return array_map(function (array $node) use ($quoteSet, $quoteField): array {
            if ($node['type'] !== 'blockquote') {
                return $node;
            }

            return ['type' => 'set', 'attrs' => ['id' => ($this->newId)(), 'values' => [
                'type' => $quoteSet,
                $quoteField => trim($this->plain($node)),
            ]]];
        }, $nodes);
    }

    /**
     * @param  array<mixed>  $node
     */
    private function plain(array $node): string
    {
        if (isset($node['text'])) {
            $italic = array_filter((array) ($node['marks'] ?? []), fn ($mark) => is_array($mark) && ($mark['type'] ?? null) === 'italic') !== [];

            return $italic ? '*'.$node['text'].'*' : (string) $node['text'];
        }

        return implode(($node['type'] ?? '') === 'blockquote' ? "\n\n" : '', array_map(fn ($child) => $this->plain((array) $child), (array) ($node['content'] ?? [])));
    }
}
