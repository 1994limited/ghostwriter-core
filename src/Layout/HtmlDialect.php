<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Layout;

use DOMDocument;
use DOMElement;
use DOMNode;
use League\CommonMark\CommonMarkConverter;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Text\HtmlToMarkdown;

/**
 * Rich text stored as HTML: Craft's CKEditor and Redactor, Filament's
 * RichEditor. A field whose meta says `format: markdown` (Filament's
 * MarkdownEditor) is shown to the model as it is stored.
 *
 * The house style learns, for each kind of text element (h1–h6, p,
 * blockquote, ul, ol), the attributes it carries and the inline elements
 * (span, strong, em…) that wrap all of its words, from the first such
 * element in each sample.
 */
final class HtmlDialect implements RichTextDialect
{
    private const TEXT_TAGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'blockquote', 'ul', 'ol'];

    private const WRAPPERS = ['span', 'strong', 'em', 'b', 'i', 'mark', 'small'];

    /** For a piece of markup, the commonest is taken once more than half agree. */
    private const MAJORITY = 0.5;

    private readonly HtmlToMarkdown $markdown;

    /**
     * @param  HtmlToMarkdown|null  $markdown  How stored HTML is read as markdown. Craft: the default (drops `<craft-entry>`); Filament: `new HtmlToMarkdown(embeds: [])`.
     */
    public function __construct(?HtmlToMarkdown $markdown = null)
    {
        $this->markdown = $markdown ?? new HtmlToMarkdown;
    }

    public function fromMarkdown(string $markdown, Field $field): string
    {
        if ($markdown === '') {
            return '';
        }

        return trim((string) (new CommonMarkConverter(['html_input' => 'escape', 'allow_unsafe_links' => false]))->convert($markdown));
    }

    public function toMarkdown(mixed $value, Field $field): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return trim(($field->meta['format'] ?? null) === 'markdown' ? $value : $this->markdown->convert($value));
    }

    public function isWritten(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    public function shapes(array $samples): array
    {
        $byTag = [];

        foreach ($samples as $html) {
            if (! is_string($html)) {
                continue;
            }

            $seenHere = [];

            foreach ($this->elements($html) as $element) {
                $tag = strtolower($element->nodeName);

                // One vote per sample per tag: the first such element.
                if (! in_array($tag, self::TEXT_TAGS, true) || isset($seenHere[$tag])) {
                    continue;
                }

                $seenHere[$tag] = true;
                $shape = $this->shapeOf($element);
                $byTag[$tag][(string) json_encode($shape)][] = $shape;
            }
        }

        $shapes = [];

        foreach ($byTag as $tag => $variants) {
            uasort($variants, fn (array $a, array $b) => count($b) <=> count($a));
            $best = reset($variants);
            $total = array_sum(array_map('count', $variants));

            // A plain element is the default and needs nothing.
            if ($total >= 2 && count($best) / $total > self::MAJORITY && ($best[0]['attributes'] !== [] || $best[0]['wrappers'] !== [])) {
                $shapes[$tag] = $best[0];
            }
        }

        return $shapes;
    }

    public function dress(mixed $value, array $shapes): mixed
    {
        if ($shapes === [] || ! is_string($value)) {
            return $value;
        }

        $document = $this->document($value);
        $body = $document->getElementsByTagName('body')->item(0);
        $changed = false;

        foreach (iterator_to_array($body->childNodes ?? []) as $element) {
            $shape = $element instanceof DOMElement ? ($shapes[strtolower($element->nodeName)] ?? null) : null;

            // Only plain elements: anything the writer dressed is left as it is.
            if (! $element instanceof DOMElement || ! is_array($shape) || $element->attributes->length > 0) {
                continue;
            }

            foreach ((array) ($shape['attributes'] ?? []) as $name => $attribute) {
                $element->setAttribute((string) $name, (string) $attribute);
            }

            $inner = $element;

            foreach ((array) ($shape['wrappers'] ?? []) as $wrapping) {
                [$tag, $attributes] = $wrapping;
                $wrapper = $document->createElement($tag);

                foreach ($attributes as $name => $attribute) {
                    $wrapper->setAttribute((string) $name, (string) $attribute);
                }

                while ($inner->firstChild) {
                    $wrapper->appendChild($inner->firstChild);
                }

                $inner->appendChild($wrapper);
                $inner = $wrapper;
            }

            $changed = true;
        }

        if (! $changed || ! $body) {
            return $value;
        }

        $out = '';

        foreach ($body->childNodes as $child) {
            $out .= $document->saveHTML($child);
        }

        return $out;
    }

    /**
     * @return array{attributes: array<string, string>, wrappers: array<int, array{0: string, 1: array<string, string>}>}
     */
    private function shapeOf(DOMElement $element): array
    {
        $wrappers = [];
        $node = $element;

        // Inline elements wrapping all of the content, one inside another.
        while (($only = $this->onlyChild($node)) && in_array(strtolower($only->nodeName), self::WRAPPERS, true)) {
            $wrappers[] = [strtolower($only->nodeName), $this->attributes($only)];
            $node = $only;
        }

        return ['attributes' => $this->attributes($element), 'wrappers' => $wrappers];
    }

    /**
     * @return array<int, DOMElement>
     */
    private function elements(string $html): array
    {
        $body = $this->document($html)->getElementsByTagName('body')->item(0);

        return $body ? array_values(array_filter(iterator_to_array($body->childNodes), fn ($node) => $node instanceof DOMElement)) : [];
    }

    private function document(string $html): DOMDocument
    {
        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="utf-8"?><body>'.$html.'</body>', LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);

        return $document;
    }

    private function onlyChild(DOMNode $node): ?DOMElement
    {
        $elements = [];

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $elements[] = $child;
            } elseif (trim($child->textContent) !== '') {
                return null;
            }
        }

        return count($elements) === 1 ? $elements[0] : null;
    }

    /**
     * @return array<string, string>
     */
    private function attributes(DOMElement $element): array
    {
        $attributes = [];

        foreach ($element->attributes ?? [] as $attribute) {
            $attributes[$attribute->name] = (string) $attribute->value;
        }

        ksort($attributes);

        return $attributes;
    }
}
