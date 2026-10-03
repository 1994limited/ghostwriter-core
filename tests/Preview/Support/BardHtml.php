<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Preview\Support;

/**
 * Bard nodes printed as HTML, as Statamic's `{{ bard }}` does for the
 * nodes core's Bard dialect writes. Enough for the preview round trip.
 */
final class BardHtml
{
    /**
     * @param  array<mixed>  $nodes
     */
    public static function render(array $nodes): string
    {
        return implode('', array_map(fn ($node) => is_array($node) ? self::node($node) : '', $nodes));
    }

    /**
     * @param  array<mixed>  $node
     */
    private static function node(array $node): string
    {
        $inner = self::render(is_array($node['content'] ?? null) ? $node['content'] : []);

        return match ($node['type'] ?? null) {
            'text' => self::marks(htmlspecialchars(is_string($node['text'] ?? null) ? $node['text'] : '', ENT_QUOTES), is_array($node['marks'] ?? null) ? $node['marks'] : []),
            'paragraph' => "<p>{$inner}</p>",
            'heading' => '<h'.(int) ($node['attrs']['level'] ?? 2).">{$inner}</h".(int) ($node['attrs']['level'] ?? 2).'>',
            'bulletList' => "<ul>{$inner}</ul>",
            'orderedList' => "<ol>{$inner}</ol>",
            'listItem' => "<li>{$inner}</li>",
            'blockquote' => "<blockquote>{$inner}</blockquote>",
            'hardBreak' => '<br>',
            default => $inner,
        };
    }

    /**
     * @param  array<mixed>  $marks
     */
    private static function marks(string $html, array $marks): string
    {
        foreach ($marks as $mark) {
            $html = match (is_array($mark) ? ($mark['type'] ?? null) : null) {
                'bold' => "<strong>{$html}</strong>",
                'italic' => "<em>{$html}</em>",
                'link' => '<a href="'.htmlspecialchars((string) ($mark['attrs']['href'] ?? ''), ENT_QUOTES).'">'.$html.'</a>',
                default => $html,
            };
        }

        return $html;
    }
}
