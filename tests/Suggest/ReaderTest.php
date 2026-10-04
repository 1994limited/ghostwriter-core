<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Suggest;

use NineteenNinetyFour\Ghostwriter\Core\Suggest\SuggestionReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The reply as models really write it, and the published schema it is
 * checked against (resources/schemas/suggestions.schema.json). The reader
 * is hand-written and the schema is the contract; this test keeps the two
 * from drifting, with a small validator for the keywords the schema uses.
 */
final class ReaderTest extends TestCase
{
    private const ONE = '{"category": "voice", "unit": "u3", "quote": "bespoke", "reason": "Jargon.", "source": {"kind": "general"}, "replacement": "made to measure"}';

    /**
     * @return iterable<string, array{string, int, bool}>
     */
    public static function replies(): iterable
    {
        yield 'in the block' => ['<suggestions>{"suggestions": ['.self::ONE.']}</suggestions>', 1, false];
        yield 'with a code fence' => ["<suggestions>\n```json\n{\"suggestions\": [".self::ONE."]}\n```\n</suggestions>", 1, false];
        yield 'with no block' => ['{"suggestions": ['.self::ONE.', '.self::ONE.']}', 2, false];
        yield 'a trailing comma' => ['<suggestions>{"suggestions": ['.self::ONE.',]}</suggestions>', 1, false];
        yield 'a single object' => ['<suggestions>'.self::ONE.'</suggestions>', 1, false];
        yield 'a bare list' => ['<suggestions>['.self::ONE.']</suggestions>', 1, false];
        yield 'cut off: the closed ones kept' => ['<suggestions>{"suggestions": ['.self::ONE.', '.self::ONE.', {"category": "clarity", "unit": "u4", "quo', 2, true];
        yield 'none' => ['<suggestions>{"suggestions": []}</suggestions>', 0, false];
    }

    #[DataProvider('replies')]
    public function test_replies_as_models_write_them(string $reply, int $count, bool $truncated): void
    {
        $read = (new SuggestionReader)->read($reply);

        $this->assertCount($count, $read['items']);
        $this->assertSame($truncated, $read['truncated']);
        $this->assertNull($read['problem']);
    }

    public function test_an_unreadable_reply_says_why(): void
    {
        $this->assertSame('the JSON did not parse', (new SuggestionReader)->read('<suggestions>Here are my thoughts.</suggestions>')['problem']);
        $this->assertSame('the reply was empty', (new SuggestionReader)->read('<suggestions></suggestions>')['problem']);
        $this->assertSame('there was no "suggestions" list', (new SuggestionReader)->read('{"edits": []}')['problem']);
    }

    public function test_every_fixture_reply_matches_the_schema_and_reads_whole(): void
    {
        $schema = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/resources/schemas/suggestions.schema.json'), true);
        $this->assertIsArray($schema);

        foreach ([ReviewCase::reply(), '<suggestions>{"suggestions": ['.self::ONE.']}</suggestions>'] as $reply) {
            preg_match('/<suggestions>(.*)<\/suggestions>/s', $reply, $m);
            $data = json_decode($m[1], true);

            $this->assertSame([], self::errors($data, $schema), 'Valid against the schema.');
            $this->assertCount(count($data['suggestions']), (new SuggestionReader)->read($reply)['items']);
        }
    }

    public function test_the_schema_refuses_what_the_prompt_forbids(): void
    {
        $schema = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/resources/schemas/suggestions.schema.json'), true);

        $this->assertNotSame([], self::errors(['suggestions' => [['category' => 'tone']]], $schema));
        $this->assertNotSame([], self::errors(['suggestions' => [['category' => 'voice', 'alternatives' => ['a', 'b', 'c']]]], $schema));
        $this->assertNotSame([], self::errors(['suggestions' => [['category' => 'fact-to-check', 'fact' => ['ask' => 'x', 'template' => 'no answer here']]]], $schema));
        $this->assertNotSame([], self::errors(['suggestions' => [['category' => 'link', 'link' => ['entry' => 'entry::abc']]]], $schema));
    }

    /**
     * The JSON Schema keywords suggestions.schema.json uses: type, enum,
     * required, properties, items, maxItems, maxLength, minimum, pattern.
     *
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private static function errors(mixed $value, array $schema, string $at = '$'): array
    {
        $errors = [];
        $types = isset($schema['type']) ? (array) $schema['type'] : [];

        if ($types !== []) {
            $type = match (true) {
                is_array($value) && array_is_list($value) && $value !== [] => 'array',
                is_array($value) && $value === [] => in_array('array', $types, true) ? 'array' : 'object',
                is_array($value) => 'object',
                is_string($value) => 'string',
                is_int($value) => 'integer',
                is_null($value) => 'null',
                default => gettype($value),
            };

            if (! in_array($type, $types, true)) {
                return ["{$at}: not ".implode('|', $types)];
            }
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = "{$at}: not one of the enum";
        }

        if (is_string($value)) {
            if (isset($schema['maxLength']) && mb_strlen($value) > $schema['maxLength']) {
                $errors[] = "{$at}: too long";
            }

            if (isset($schema['pattern']) && preg_match('/'.str_replace('/', '\/', $schema['pattern']).'/u', $value) !== 1) {
                $errors[] = "{$at}: pattern";
            }
        }

        if (is_int($value) && isset($schema['minimum']) && $value < $schema['minimum']) {
            $errors[] = "{$at}: below minimum";
        }

        if (is_array($value) && array_is_list($value) && isset($schema['items'])) {
            if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
                $errors[] = "{$at}: too many items";
            }

            foreach ($value as $i => $item) {
                array_push($errors, ...self::errors($item, $schema['items'], "{$at}[{$i}]"));
            }
        }

        if (is_array($value) && ! array_is_list($value) || (is_array($value) && $value === [] && isset($schema['properties']))) {
            foreach ($schema['required'] ?? [] as $key) {
                if (! array_key_exists($key, $value)) {
                    $errors[] = "{$at}: {$key} is required";
                }
            }

            foreach ($schema['properties'] ?? [] as $key => $property) {
                if (array_key_exists($key, $value)) {
                    array_push($errors, ...self::errors($value[$key], $property, "{$at}.{$key}"));
                }
            }
        }

        return $errors;
    }
}
