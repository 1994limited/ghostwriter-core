<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Text;

/**
 * The agents answer in tagged blocks: what they say to the person, and the
 * document or draft they are handing back. Tags are used instead of JSON
 * because a long markdown document survives them untouched, on any provider.
 *
 * The writer may also add an `<images>` block of image requests and an
 * `<extras>` block (Arrange\Extras\ExtrasReader), which are kept apart from
 * the reply.
 */
class TaggedResponse
{
    public function __construct(
        public readonly string $reply,
        public readonly ?string $document,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly ?string $images = null,
        public readonly ?string $extras = null,
    ) {}

    /**
     * @param  string  $documentTag  The tag the document comes in: draft, guide and so on.
     */
    public static function parse(string $text, string $documentTag, int $inputTokens = 0, int $outputTokens = 0): self
    {
        $reply = self::block($text, 'reply');
        $document = self::block($text, $documentTag);

        // A model that ignores the format still said something; show it
        // rather than lose it.
        if ($reply === null) {
            $reply = trim(Pcre::replace('/<('.$documentTag.'|images|extras)>.*?(<\/\1>|\z)/s', '', $text));
        }

        return new self($reply, $document, $inputTokens, $outputTokens, self::block($text, 'images'), self::block($text, 'extras'));
    }

    private static function block(string $text, string $tag): ?string
    {
        // The closing tag is optional so a response cut off at the token
        // limit still yields what was written.
        if (! Pcre::match('/<'.$tag.'>(.*?)(?:<\/'.$tag.'>|\z)/s', $text, $m)) {
            return null;
        }

        $value = trim($m[1]);

        return $value === '' ? null : $value;
    }
}
