<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\TextQuote;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;

/**
 * One link Finish's **Suggest links** found for an existing page (SEO
 * layer §12, `few-links`; PageLinks): the words to link, where they are,
 * and the page they would go to. Nothing is linked until the editor
 * presses **Link it** on its step (Gaps\Detectors\ProposedLinks), and
 * nothing is saved until they save.
 *
 * - `path`, `label`: the field (a FieldPath, blocks by ID, as the gap
 *   finder walks the entry) and what the guide calls it.
 * - `quote`: the words as a TextQuote of the field's text (Markdown, as
 *   the addon's dialect reads it), with up to 32 characters either side.
 * - `words`: the words exactly as the editor shows them: no Markdown in
 *   them (PageLinks leaves out any that has some).
 * - `href`: the link as the field's rich text stores it
 *   (InlineLinks::inlineHref(): `statamic://entry::abc`,
 *   `{entry:12@1:url||…}`, a public address).
 * - `title`, `type`, `url`: the page it goes to, as the step names it.
 * - `why`: the `seo-editor`'s one line, in the page's language.
 */
final class LinkProposal
{
    public function __construct(
        public readonly string $id,
        public readonly FieldPath $path,
        public readonly string $label,
        public readonly TextQuote $quote,
        public readonly string $words,
        public readonly string $href,
        public readonly string $title,
        public readonly string $type = '',
        public readonly ?string $url = null,
        public readonly string $why = '',
    ) {}

    /**
     * @return array{id: string, path: string, label: string, quote: array{exact: string, prefix?: string, suffix?: string}, words: string, href: string, title: string, type: string, url: ?string, why: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'path' => $this->path->toString(),
            'label' => $this->label,
            'quote' => $this->quote->toArray(),
            'words' => $this->words,
            'href' => $this->href,
            'title' => $this->title,
            'type' => $this->type,
            'url' => $this->url,
            'why' => $this->why,
        ];
    }

    /**
     * Null for anything that isn't one (a stored value from another
     * version, a hand-edited cache).
     *
     * @param  array<mixed>  $array  From toArray().
     */
    public static function fromArray(array $array): ?self
    {
        $text = fn (string $key) => is_scalar($array[$key] ?? null) ? (string) $array[$key] : '';

        if ($text('path') === '' || $text('words') === '' || $text('href') === '' || ! is_array($array['quote'] ?? null)) {
            return null;
        }

        try {
            $quote = TextQuote::fromArray($array['quote']);
        } catch (InvalidArgumentException) {
            return null;
        }

        return new self(
            $text('id'),
            FieldPath::parse($text('path')),
            $text('label'),
            $quote,
            $text('words'),
            $text('href'),
            $text('title'),
            $text('type'),
            is_string($array['url'] ?? null) && $array['url'] !== '' ? $array['url'] : null,
            $text('why'),
        );
    }
}
