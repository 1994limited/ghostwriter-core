<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Layout\Support;

/**
 * The reverse of MarkdownToBard: a stored Bard document as the markdown a
 * writer would have typed. Used to show the model real entries from the site
 * in the same form it is asked to write in.
 *
 * Sets placed inside a Bard field are kept only when they carry text worth
 * reading (a pull quote becomes a block quote); images, buttons and other
 * furniture are dropped.
 */
class BardToMarkdown
{
    /**
     * @param  array<int, mixed>  $nodes
     */
    public function convert(array $nodes): string
    {
        $blocks = [];

        foreach ($nodes as $node) {
            if (is_array($node) && ($block = $this->block($node)) !== '') {
                $blocks[] = $block;
            }
        }

        return implode("\n\n", $blocks);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function block(array $node): string
    {
        $content = (array) ($node['content'] ?? []);

        return match ($node['type'] ?? null) {
            'heading' => str_repeat('#', (int) ($node['attrs']['level'] ?? 2)).' '.$this->inline($content),
            'paragraph' => $this->inline($content),
            'bulletList' => $this->list($content, fn (int $i) => '- '),
            'orderedList' => $this->list($content, fn (int $i) => ((int) ($node['attrs']['start'] ?? $node['attrs']['order'] ?? 1) + $i).'. '),
            'blockquote' => implode("\n", array_map(fn (string $line) => '> '.$line, explode("\n", $this->convert($content)))),
            'table' => $this->table($content),
            'horizontalRule' => '---',
            'set' => $this->set((array) ($node['attrs']['values'] ?? [])),
            default => '',
        };
    }

    /**
     * A list, one item per line. An item's first paragraph sits on the marker
     * line; anything after it (more paragraphs, nested lists) follows on its
     * own lines, indented to the item's text so it stays inside the item.
     *
     * An item's text is not always inside a paragraph: some stored lists keep
     * text nodes straight in the listItem, so loose inline nodes are read as
     * a paragraph of their own rather than dropped.
     *
     * @param  array<int, mixed>  $items
     */
    private function list(array $items, callable $marker): string
    {
        $lines = [];

        foreach (array_values($items) as $i => $item) {
            $prefix = $marker($i);
            $pad = str_repeat(' ', strlen($prefix));
            $out = '';

            foreach ($this->itemBlocks((array) ($item['content'] ?? [])) as $child) {
                if (($part = $this->block($child)) === '') {
                    continue;
                }

                $part = $this->indent($part, $pad);

                $out .= match (true) {
                    $out === '' => $part,
                    in_array($child['type'] ?? null, ['bulletList', 'orderedList'], true) => "\n".$pad.$part,
                    default => "\n\n".$pad.$part,
                };
            }

            $lines[] = rtrim($prefix.$out);
        }

        return implode("\n", $lines);
    }

    /**
     * An item's children as blocks, with runs of loose inline nodes (text and
     * hard breaks straight inside the listItem) gathered into paragraphs.
     *
     * @param  array<int, mixed>  $children
     * @return array<int, array<string, mixed>>
     */
    private function itemBlocks(array $children): array
    {
        $blocks = [];
        $inline = [];

        foreach ($children as $child) {
            if (! is_array($child)) {
                continue;
            }

            if (in_array($child['type'] ?? null, ['text', 'hardBreak'], true)) {
                $inline[] = $child;

                continue;
            }

            if ($inline) {
                $blocks[] = ['type' => 'paragraph', 'content' => $inline];
                $inline = [];
            }

            $blocks[] = $child;
        }

        if ($inline) {
            $blocks[] = ['type' => 'paragraph', 'content' => $inline];
        }

        return $blocks;
    }

    /**
     * Indents every line of a block after the first, so a multi-line block
     * (a nested list, a paragraph with hard breaks) stays inside its item.
     */
    private function indent(string $block, string $pad): string
    {
        return preg_replace('/\n(?!\n)/', "\n".$pad, $block);
    }

    /**
     * @param  array<int, mixed>  $rows
     */
    private function table(array $rows): string
    {
        $lines = [];

        foreach (array_values($rows) as $i => $row) {
            $cells = array_map(
                fn ($cell) => str_replace('|', '\\|', $this->convert((array) ($cell['content'] ?? []))),
                (array) ($row['content'] ?? []),
            );

            $lines[] = '| '.implode(' | ', $cells).' |';

            if ($i === 0) {
                $lines[] = '|'.str_repeat(' --- |', count($cells));
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function set(array $values): string
    {
        if (! str_contains((string) ($values['type'] ?? ''), 'quote')) {
            return '';
        }

        $text = trim((string) ($values['text'] ?? $values['quote'] ?? ''));

        return $text === '' ? '' : '> '.str_replace("\n", "\n> ", $text);
    }

    /**
     * @param  array<int, mixed>  $nodes
     */
    private function inline(array $nodes): string
    {
        $out = '';

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            if (($node['type'] ?? null) === 'hardBreak') {
                $out .= "  \n";

                continue;
            }

            // Marks wrap the words only: "**bold **" is not bold in markdown,
            // so spaces at either end stay outside the markers.
            preg_match('/^(\s*)(.*?)(\s*)$/su', (string) ($node['text'] ?? ''), $parts);
            [, $before, $text, $after] = $parts + ['', '', '', ''];

            if ($text !== '') {
                foreach ((array) ($node['marks'] ?? []) as $mark) {
                    $text = match ($mark['type'] ?? null) {
                        'bold' => '**'.$text.'**',
                        'italic' => '*'.$text.'*',
                        'code' => '`'.$text.'`',
                        'link' => '['.$text.']('.($mark['attrs']['href'] ?? '').')',
                        default => $text,
                    };
                }
            }

            $out .= $before.$text.$after;
        }

        return trim($out);
    }
}
