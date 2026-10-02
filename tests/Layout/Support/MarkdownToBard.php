<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Layout\Support;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use League\CommonMark\Extension\Table\Table;
use League\CommonMark\Extension\Table\TableCell;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\Table\TableRow;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;

/**
 * Converts a markdown body into the ProseMirror document a Bard field stores:
 * headings, paragraphs, lists (nested too), block quotes and tables, with
 * bold, italic and link marks. Anything else is reduced to its text: Bard
 * only knows the marks its field's buttons switch on, so inline code keeps
 * its words but not a code mark.
 */
class MarkdownToBard
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function convert(string $markdown): array
    {
        $environment = new Environment;
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new TableExtension);

        $document = (new MarkdownParser($environment))->parse($markdown);
        $nodes = [];

        foreach ($document->children() as $child) {
            if ($node = $this->block($child)) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function block(Node $node): ?array
    {
        return match (true) {
            $node instanceof Heading => [
                'type' => 'heading',
                'attrs' => ['level' => $node->getLevel()],
                'content' => $this->inlines($node),
            ],
            $node instanceof Paragraph => $this->paragraph($node),
            $node instanceof ListBlock => [
                'type' => $node->getListData()->type === ListBlock::TYPE_ORDERED ? 'orderedList' : 'bulletList',
                'content' => array_map(fn (ListItem $item) => [
                    'type' => 'listItem',
                    // A listItem must hold at least a paragraph, even an empty one.
                    'content' => array_values(array_filter(array_map(fn (Node $child) => $this->block($child), iterator_to_array($item->children(), false)))) ?: [['type' => 'paragraph']],
                ], iterator_to_array($node->children(), false)),
            ],
            $node instanceof BlockQuote => [
                'type' => 'blockquote',
                'content' => array_values(array_filter(array_map(fn (Node $child) => $this->block($child), iterator_to_array($node->children(), false)))),
            ],
            $node instanceof Table => ['type' => 'table', 'content' => $this->rows($node)],
            $node instanceof ThematicBreak => ['type' => 'horizontalRule'],
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function paragraph(Node $node): array
    {
        $content = $this->inlines($node);

        return $content ? ['type' => 'paragraph', 'content' => $content] : ['type' => 'paragraph'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(Table $table): array
    {
        $rows = [];

        foreach ($table->children() as $section) {
            foreach ($section->children() as $row) {
                if (! $row instanceof TableRow) {
                    continue;
                }

                $cells = [];

                foreach ($row->children() as $cell) {
                    if ($cell instanceof TableCell) {
                        $cells[] = [
                            'type' => $cell->getType() === TableCell::TYPE_HEADER ? 'tableHeader' : 'tableCell',
                            'attrs' => ['colspan' => 1, 'rowspan' => 1, 'colwidth' => null],
                            'content' => [$this->paragraph($cell)],
                        ];
                    }
                }

                $rows[] = ['type' => 'tableRow', 'content' => $cells];
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $marks
     * @return array<int, array<string, mixed>>
     */
    private function inlines(Node $parent, array $marks = []): array
    {
        $out = [];

        foreach ($parent->children() as $child) {
            if ($child instanceof Text) {
                if ($child->getLiteral() !== '') {
                    $out[] = $marks
                        ? ['type' => 'text', 'marks' => $marks, 'text' => $child->getLiteral()]
                        : ['type' => 'text', 'text' => $child->getLiteral()];
                }
            } elseif ($child instanceof Code) {
                if ($child->getLiteral() !== '') {
                    $out[] = $marks
                        ? ['type' => 'text', 'marks' => $marks, 'text' => $child->getLiteral()]
                        : ['type' => 'text', 'text' => $child->getLiteral()];
                }
            } elseif ($child instanceof Newline) {
                $out[] = $child->getType() === Newline::HARDBREAK ? ['type' => 'hardBreak'] : ['type' => 'text', 'text' => ' '];
            } elseif ($child instanceof Strong) {
                array_push($out, ...$this->inlines($child, [...$marks, ['type' => 'bold']]));
            } elseif ($child instanceof Emphasis) {
                array_push($out, ...$this->inlines($child, [...$marks, ['type' => 'italic']]));
            } elseif ($child instanceof Link) {
                $mark = ['type' => 'link', 'attrs' => ['href' => $child->getUrl(), 'rel' => null, 'target' => null, 'title' => $child->getTitle()]];
                array_push($out, ...$this->inlines($child, [...$marks, $mark]));
            } else {
                array_push($out, ...$this->inlines($child, $marks));
            }
        }

        return $out;
    }
}
