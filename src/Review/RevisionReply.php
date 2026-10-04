<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Review;

use NineteenNinetyFour\Ghostwriter\Core\Text\LenientYaml;
use Throwable;

/**
 * The reviser's `<changes>` block, read into one RevisionItem per comment
 * (unvalidated: RevisionValidator decides what is applied). A comment the
 * reply has no item for gets none.
 */
final class RevisionReply
{
    /**
     * @param  array<int, RevisionItem>  $items  By comment number.
     * @param  string  $problem  Why nothing could be read; empty when something could.
     */
    public function __construct(
        public readonly array $items = [],
        public readonly string $problem = '',
    ) {}

    public function item(int $comment): ?RevisionItem
    {
        return $this->items[$comment] ?? null;
    }

    public static function read(string $text): self
    {
        if (preg_match('/<changes>(.*?)(?:<\/changes>|$)/s', $text, $m) !== 1) {
            return new self([], 'there was no <changes> block');
        }

        $block = (string) preg_replace('/\A```(?:yaml|yml)?\s*\n(.*?)\n?```\s*\z/su', '$1', trim($m[1]));

        try {
            $data = LenientYaml::parse($block);
        } catch (Throwable) {
            return new self([], 'the YAML did not parse');
        }

        if (! is_array($data) || ! array_is_list($data)) {
            return new self([], 'it was not a list of changes');
        }

        $items = [];

        foreach ($data as $raw) {
            if (! is_array($raw) || ! is_numeric($raw['comment'] ?? null)) {
                continue;
            }

            $number = (int) $raw['comment'];
            $items[$number] = new RevisionItem(
                $number,
                is_scalar($raw['reply'] ?? null) ? trim((string) $raw['reply']) : '',
                self::texts($raw['units'] ?? null),
                self::replacements($raw['replace'] ?? null),
                is_array($raw['layout'] ?? null) && $raw['layout'] !== [] ? array_values($raw['layout']) : null,
                self::extras($raw['extras'] ?? null),
            );
        }

        return new self($items, $items === [] ? 'no item named a comment' : '');
    }

    /**
     * @return array<string, string>
     */
    private static function texts(mixed $units): array
    {
        $out = [];

        foreach (is_array($units) ? $units : [] as $id => $text) {
            if (is_scalar($text)) {
                $out[trim((string) $id)] = (string) $text;
            }
        }

        return $out;
    }

    /**
     * @return list<array{unit: string, exact: string, with: string}>
     */
    private static function replacements(mixed $replace): array
    {
        $out = [];

        foreach (is_array($replace) ? $replace : [] as $one) {
            if (is_array($one) && is_scalar($one['unit'] ?? null) && is_scalar($one['exact'] ?? null) && is_scalar($one['with'] ?? null) && (string) $one['exact'] !== '') {
                $out[] = ['unit' => trim((string) $one['unit']), 'exact' => (string) $one['exact'], 'with' => (string) $one['with']];
            }
        }

        return $out;
    }

    /**
     * @return array<string, array{text: string, parts: array<string, string>}|null>
     */
    private static function extras(mixed $extras): array
    {
        $out = [];

        foreach (is_array($extras) ? $extras : [] as $id => $item) {
            $id = trim((string) $id);

            if ($item === null) {
                $out[$id] = null;
            } elseif (is_scalar($item)) {
                $out[$id] = ['text' => trim((string) $item), 'parts' => []];
            } elseif (is_array($item) && is_scalar($item['text'] ?? null)) {
                $parts = [];

                foreach ($item as $name => $value) {
                    if (is_string($name) && $name !== 'text' && $name !== 'source' && is_scalar($value)) {
                        $parts[$name] = trim((string) $value);
                    }
                }

                $out[$id] = ['text' => trim((string) $item['text']), 'parts' => $parts];
            }
        }

        return $out;
    }
}
