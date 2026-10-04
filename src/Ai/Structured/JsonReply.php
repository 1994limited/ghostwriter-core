<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Structured;

/**
 * A structured reply's text decoded. Models held to a schema send bare
 * JSON; a code fence around it is tolerated all the same.
 */
final class JsonReply
{
    /**
     * @return array<string, mixed>|null The object, or null when the text isn't one.
     */
    public static function decode(string $text): ?array
    {
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        $data = json_decode($text, true);

        if (! is_array($data) && preg_match('/```(?:json)?\s*(.*?)\s*```/s', $text, $m) === 1) {
            $data = json_decode($m[1], true);
        }

        return is_array($data) && ! array_is_list($data) ? $data : null;
    }
}
