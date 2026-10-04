<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

/**
 * Reads the review call's reply: the JSON in `<suggestions>` (or the
 * reply itself), checked against resources/schemas/suggestions.schema.json
 * by a test, not at runtime. Lenient where models slip:
 *
 * - code fences are stripped, and a trailing comma before `}` or `]`;
 * - a single object is a list of one, and a bare list is the list;
 * - a reply cut off part-way keeps every suggestion object that closed;
 * - the older `decline` key reads as `drop` (a candidate dropped in
 *   context).
 *
 * Anything else is unreadable: read() gives no items and says why.
 *
 * The verifier's reply is read the same way, as `<verdicts>` with a
 * `verdicts` list (`new SuggestionReader('verdicts')`).
 */
final class SuggestionReader
{
    public function __construct(private readonly string $list = 'suggestions') {}

    /**
     * @return array{items: list<array<string, mixed>>, truncated: bool, problem: ?string}
     */
    public function read(string $reply): array
    {
        $tag = preg_quote($this->list, '/');
        $body = preg_match('/<'.$tag.'>(.*?)(?:<\/'.$tag.'>|$)/s', $reply, $m) === 1 ? $m[1] : $reply;
        $body = trim((string) preg_replace('/^\s*```(?:json)?\s*|\s*```\s*$/u', '', trim($body)));

        if ($body === '') {
            return ['items' => [], 'truncated' => false, 'problem' => 'the reply was empty'];
        }

        $data = json_decode($body, true);

        if (! is_array($data)) {
            $data = json_decode((string) preg_replace('/,\s*([}\]])/', '$1', $body), true);
        }

        if (is_array($data)) {
            $list = array_is_list($data) ? $data : (is_array($data[$this->list] ?? null) ? $data[$this->list] : (isset($data['category']) || isset($data['finding']) || isset($data['verdict']) ? [$data] : null));

            if (! is_array($list)) {
                return ['items' => [], 'truncated' => false, 'problem' => 'there was no "'.$this->list.'" list'];
            }

            return ['items' => self::objects($list), 'truncated' => false, 'problem' => null];
        }

        // Cut off part-way: keep every object in the list that closed.
        $start = preg_match('/"'.$tag.'"\s*:\s*\[/', $body, $found, PREG_OFFSET_CAPTURE) === 1 ? $found[0][1] + strlen($found[0][0]) : (str_starts_with($body, '[') ? 1 : null);

        if ($start === null) {
            return ['items' => [], 'truncated' => false, 'problem' => 'the JSON did not parse'];
        }

        $items = [];

        foreach (self::closedObjects(substr($body, $start)) as $json) {
            $item = json_decode((string) preg_replace('/,\s*([}\]])/', '$1', $json), true);

            if (is_array($item)) {
                $items[] = $item;
            }
        }

        return ['items' => self::objects($items), 'truncated' => true, 'problem' => $items === [] ? 'the JSON did not parse' : null];
    }

    /**
     * @param  array<mixed>  $list
     * @return list<array<string, mixed>>
     */
    private static function objects(array $list): array
    {
        $objects = [];

        foreach ($list as $item) {
            if (is_array($item) && ! array_is_list($item)) {
                $object = array_combine(array_map('strval', array_keys($item)), array_values($item));

                if (! isset($object['drop']) && is_string($object['decline'] ?? null)) {
                    $object['drop'] = $object['decline'];
                }

                unset($object['decline']);
                $objects[] = $object;
            }
        }

        return $objects;
    }

    /**
     * The top-level objects in the text of a list, as JSON, up to the
     * first that doesn't close.
     *
     * @return list<string>
     */
    private static function closedObjects(string $text): array
    {
        $objects = [];
        $depth = 0;
        $start = null;
        $inString = false;
        $escaped = false;
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $start ??= $i;
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0 && $start !== null) {
                    $objects[] = substr($text, $start, $i - $start + 1);
                    $start = null;
                }
            } elseif ($char === ']' && $depth === 0) {
                break;
            }
        }

        return $objects;
    }
}
