<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Text;

use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\MarkerResolver;
use NineteenNinetyFour\Ghostwriter\Core\Preview\PreviewMarkers;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * A working draft: the entry's fields written out as YAML, with rich text as
 * markdown and page-builder blocks as a plain list. It is text so that a
 * person can read and edit it, the model can rewrite it, and nothing about
 * it depends on how any one site stores its content.
 */
class Draft
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly array $data,
        public readonly string $raw,
    ) {}

    /**
     * @throws InvalidArgumentException when the text is not a draft, in words a person can act on.
     */
    public static function parse(string $raw): self
    {
        // A preview's markers never reach a draft, so they can't reach an entry.
        $raw = trim(PreviewMarkers::stripText($raw));

        // Models sometimes wrap the whole draft in a code fence, with or
        // without a newline before the closing one.
        $raw = Pcre::replace('/\A```(?:yaml|yml)?\s*\n(.*?)\n?```\s*\z/s', '$1', $raw);

        try {
            $data = LenientYaml::parse($raw);
        } catch (ParseException $exception) {
            throw new InvalidArgumentException('The draft is not valid YAML: '.$exception->getMessage(), 0, $exception);
        }

        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('The draft should be a list of fields, starting with the title.');
        }

        if (! is_scalar($data['title'] ?? null) || trim((string) $data['title']) === '') {
            throw new InvalidArgumentException('The draft needs a title.');
        }

        /** @var array<string, mixed> $data */
        return new self($data, $raw);
    }

    /**
     * The words of writing in a draft's text, counted as the draft pane
     * counts them (wordCount()), so the conversation's "Draft written · N
     * words" agrees with it. A draft that doesn't parse is counted as text.
     */
    public static function wordsIn(string $raw): int
    {
        try {
            return self::parse($raw)->wordCount();
        } catch (InvalidArgumentException) {
            return str_word_count($raw);
        }
    }

    public function title(): string
    {
        $title = $this->data['title'] ?? '';

        return trim(is_scalar($title) ? (string) $title : '');
    }

    /**
     * Words of actual writing, not counting field names and block types,
     * nor the links chosen for fields the draft doesn't hold (`gw_links`),
     * nor where a link goes: `[our services](statamic://entry::7)` is two
     * words, so linking words (the SEO pass does, after the draft is
     * written) doesn't change the count.
     */
    public function wordCount(): int
    {
        $words = 0;
        $data = $this->data;
        unset($data[MarkerResolver::CHOSEN_LINKS]);

        array_walk_recursive($data, function ($value, $key) use (&$words): void {
            if (is_string($value) && $key !== 'type') {
                $words += str_word_count(Pcre::replace('/\]\((?:[^()\s]|\([^()\s]*\))*(?:\s+"[^"]*")?\)/', ']', $value));
            }
        });

        return $words;
    }
}
