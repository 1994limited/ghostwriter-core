<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Ai\Structured;

use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Models;
use NineteenNinetyFour\Ghostwriter\Core\Ai\OutputSchema;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Structured\JsonReply;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Structured\Schemas;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\SchemaFaker;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextResponse;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Studio;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each provider's rewrite of an OutputSchema, and the catalogue of which
 * models take one.
 */
final class SchemasTest extends TestCase
{
    private static function schema(): OutputSchema
    {
        return new OutputSchema('answer', [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'An answer',
            'type' => 'object',
            'required' => ['notes', 'items'],
            'properties' => [
                'notes' => ['type' => 'string', 'maxLength' => 200, 'description' => 'First, a few words'],
                'items' => [
                    'type' => 'array',
                    'minItems' => 2,
                    'maxItems' => 5,
                    'items' => [
                        'type' => 'object',
                        'required' => ['id'],
                        'properties' => [
                            'id' => ['type' => 'string', 'pattern' => '^f[0-9]+$'],
                            'kind' => ['enum' => ['a', 'b']],
                            'count' => ['type' => 'integer', 'minimum' => 0],
                            'when' => ['type' => 'string', 'format' => 'date'],
                            'fact' => ['type' => 'object', 'required' => ['ask'], 'properties' => ['ask' => ['type' => 'string'], 'without' => ['type' => ['string', 'null']]]],
                            'pick' => ['oneOf' => [['type' => 'string'], ['type' => 'integer']]],
                            'fixed' => ['const' => 'x'],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function test_claude_gets_closed_objects_with_optional_properties_and_rejected_keywords_folded(): void
    {
        $schema = Schemas::anthropic(self::schema());
        $item = $schema['properties']['items']['items'];

        $this->assertArrayNotHasKey('$schema', $schema);
        $this->assertArrayNotHasKey('title', $schema);
        $this->assertFalse($schema['additionalProperties']);
        $this->assertFalse($item['additionalProperties']);
        $this->assertFalse($item['properties']['fact']['additionalProperties']);
        $this->assertSame(['id'], $item['required'], 'Optional properties stay optional.');
        $this->assertSame('First, a few words. At most 200 characters.', $schema['properties']['notes']['description']);
        $this->assertArrayNotHasKey('maxLength', $schema['properties']['notes']);
        $this->assertSame(1, $schema['properties']['items']['minItems'], 'Claude takes minItems 0 or 1 only.');
        $this->assertSame('At least 2 items. At most 5 items.', $schema['properties']['items']['description']);
        $this->assertSame(['type' => 'string', 'description' => 'Matches ^f[0-9]+$.'], $item['properties']['id']);
        $this->assertSame(['type' => 'integer', 'description' => 'At least 0.'], $item['properties']['count']);
        $this->assertSame('date', $item['properties']['when']['format']);
        $this->assertSame([['type' => 'string'], ['type' => 'integer']], $item['properties']['pick']['anyOf'], 'oneOf becomes anyOf.');
        $this->assertSame(['const' => 'x'], $item['properties']['fixed']);
    }

    public function test_strict_mode_requires_every_property_and_makes_optional_ones_nullable(): void
    {
        $schema = Schemas::strict(self::schema());
        $item = $schema['properties']['items']['items'];

        $this->assertSame(['notes', 'items'], $schema['required']);
        $this->assertSame(['id', 'kind', 'count', 'when', 'fact', 'pick', 'fixed'], $item['required']);
        $this->assertSame('string', $item['properties']['id']['type'], 'A required property stays as it is.');
        $this->assertSame(['a', 'b', null], $item['properties']['kind']['enum']);
        $this->assertSame(['integer', 'null'], $item['properties']['count']['type']);
        $this->assertSame(['object', 'null'], $item['properties']['fact']['type']);
        $this->assertSame(['ask', 'without'], $item['properties']['fact']['required']);
        $this->assertSame(['string', 'null'], $item['properties']['fact']['properties']['without']['type'], 'Already nullable: unchanged.');
        $this->assertContains(['type' => 'null'], $item['properties']['pick']['anyOf']);
        $this->assertArrayNotHasKey('minItems', $schema['properties']['items']);
        $this->assertArrayNotHasKey('maxItems', $schema['properties']['items']);
        $this->assertFalse($item['additionalProperties']);
    }

    public function test_gemini_keeps_counts_and_bounds_and_spells_const_as_enum(): void
    {
        $schema = Schemas::gemini(self::schema());
        $item = $schema['properties']['items']['items'];

        $this->assertSame([2, 5], [$schema['properties']['items']['minItems'], $schema['properties']['items']['maxItems']]);
        $this->assertSame(0, $item['properties']['count']['minimum']);
        $this->assertSame(['enum' => ['x']], $item['properties']['fixed']);
        $this->assertSame(['id'], $item['required']);
    }

    public function test_the_same_schema_always_gives_the_same_bytes(): void
    {
        $this->assertSame(json_encode(Schemas::anthropic(Studio::reviewerSchema())), json_encode(Schemas::anthropic(Studio::reviewerSchema())));
        $this->assertSame(json_encode(Schemas::strict(Studio::verifierSchema())), json_encode(Schemas::strict(Studio::verifierSchema())));
    }

    /**
     * Claude compiles a schema only within limits: 24 optional properties
     * and 16 unions across a request.
     */
    public function test_the_review_schemas_fit_claudes_limits_and_put_notes_first(): void
    {
        foreach ([Studio::reviewerSchema(), Studio::verifierSchema()] as $output) {
            $schema = Schemas::anthropic($output);
            [$optional, $unions] = self::tally($schema);

            $this->assertLessThanOrEqual(24, $optional, $output->name);
            $this->assertLessThanOrEqual(16, $unions, $output->name);

            $list = array_values($schema['properties'])[0];
            $this->assertSame('notes', array_key_first($list['items']['properties']), 'Notes first, so the model looks before it answers.');
            $this->assertContains('notes', $list['items']['required']);
        }
    }

    public function test_the_contract_schema_knows_every_key_the_structured_one_asks_for(): void
    {
        $contract = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/resources/schemas/suggestions.schema.json'), true);
        $structured = Studio::reviewerSchema()->schema;

        $this->assertSame([], array_diff(array_keys($structured['properties']['suggestions']['items']['properties']), array_keys($contract['properties']['suggestions']['items']['properties'])));
    }

    public function test_an_output_schema_has_an_object_at_its_root_and_a_plain_name(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new OutputSchema('two words', ['type' => 'object', 'properties' => []]);
    }

    public function test_a_list_at_the_root_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new OutputSchema('list', ['type' => 'array', 'items' => ['type' => 'string']]);
    }

    public function test_a_reply_is_decoded_bare_or_fenced_and_only_as_an_object(): void
    {
        $this->assertSame(['a' => 1], JsonReply::decode(' {"a": 1} '));
        $this->assertSame(['a' => 1], JsonReply::decode("```json\n{\"a\": 1}\n```"));
        $this->assertNull(JsonReply::decode('[1, 2]'));
        $this->assertSame(['a' => 1], JsonReply::decode('<suggestions>{"a": 1}</suggestions>'), 'JSON in the tag a tagged prompt asks for.');
        $this->assertNull(JsonReply::decode('<a>{"a": 1}</b>'));
        $this->assertNull(JsonReply::decode(''));
    }

    public function test_made_up_data_has_the_schemas_shape(): void
    {
        $data = SchemaFaker::fake(self::schema());

        $this->assertSame('text', $data['notes']);
        $this->assertCount(2, $data['items'], 'minItems.');
        $this->assertSame(['id' => 'text', 'kind' => 'a', 'count' => 0, 'when' => 'text', 'fact' => ['ask' => 'text', 'without' => 'text'], 'pick' => 'text', 'fixed' => 'x'], $data['items'][0]);
    }

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function models(): iterable
    {
        yield 'Opus 5.5' => ['anthropic', 'claude-opus-5-5', TextResponse::JSON_SCHEMA];
        yield 'Sonnet 4.5, dated' => ['anthropic', 'claude-sonnet-4-5-20250929', TextResponse::JSON_SCHEMA];
        yield 'Opus 4.6' => ['anthropic', 'claude-opus-4-6', TextResponse::JSON_SCHEMA];
        yield 'Haiku 4.5' => ['anthropic', 'claude-haiku-4-5-20251001', TextResponse::JSON_SCHEMA];
        yield 'Fable 5.1' => ['anthropic', 'claude-fable-5-1', TextResponse::JSON_SCHEMA];
        yield 'Mythos preview' => ['anthropic', 'claude-mythos-preview', TextResponse::JSON_SCHEMA];
        yield 'Opus 4.1' => ['anthropic', 'claude-opus-4-1', TextResponse::TOOL];
        yield 'Sonnet 3.7' => ['anthropic', 'claude-3-7-sonnet-latest', TextResponse::TOOL];
        yield 'not Claude' => ['anthropic', 'my-gateway-model', null];
        yield 'GPT-6.1' => ['openai', 'gpt-6.1-sol', TextResponse::JSON_SCHEMA];
        yield 'GPT-4o' => ['openai', 'gpt-4o-mini', TextResponse::JSON_SCHEMA];
        yield 'o3' => ['openai', 'o3', TextResponse::JSON_SCHEMA];
        yield 'GPT-3.5' => ['openai', 'gpt-3.5-turbo', null];
        yield 'Gemini 3.8' => ['gemini', 'gemini-3.8-flash', TextResponse::JSON_SCHEMA];
        yield 'Gemini 2.5' => ['gemini', 'gemini-2.5-pro', TextResponse::JSON_SCHEMA];
        yield 'Gemini 1.5' => ['gemini', 'gemini-1.5-pro', null];
        yield 'Claude via OpenRouter' => ['openrouter', 'anthropic/claude-opus-5.5', TextResponse::JSON_SCHEMA];
        yield 'GPT via OpenRouter' => ['openrouter', 'openai/gpt-6.1-sol', TextResponse::JSON_SCHEMA];
        yield 'Gemini via OpenRouter' => ['openrouter', 'google/gemini-3.8-flash', TextResponse::JSON_SCHEMA];
        yield 'old Claude via OpenRouter' => ['openrouter', 'anthropic/claude-3.5-haiku', null];
        yield 'someone else via OpenRouter' => ['openrouter', 'meta-llama/llama-4', null];
    }

    #[DataProvider('models')]
    public function test_which_models_take_a_schema(string $provider, string $model, ?string $mode): void
    {
        $this->assertSame($mode, Models::structuredOutput($provider, $model));
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{int, int} Optional properties and unions.
     */
    private static function tally(array $node): array
    {
        $optional = 0;
        $unions = 0;

        foreach (is_array($node['properties'] ?? null) ? $node['properties'] : [] as $name => $child) {
            $optional += in_array($name, $node['required'] ?? [], true) ? 0 : 1;
            $unions += (is_array($child['type'] ?? null) || isset($child['anyOf'])) ? 1 : 0;
            [$o, $u] = self::tally($child);
            $optional += $o;
            $unions += $u;
        }

        if (is_array($node['items'] ?? null)) {
            [$o, $u] = self::tally($node['items']);
            $optional += $o;
            $unions += $u;
        }

        return [$optional, $unions];
    }
}
