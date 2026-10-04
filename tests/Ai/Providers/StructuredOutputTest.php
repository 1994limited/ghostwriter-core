<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Ai\Providers;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\BadResponse;
use NineteenNinetyFour\Ghostwriter\Core\Ai\OutputSchema;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers\Anthropic;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers\Gemini;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers\OpenAi;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers\OpenRouter;
use NineteenNinetyFour\Ghostwriter\Core\Ai\StopReason;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Structured\Schemas;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextResponse;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Ai\ProviderTestCase;

/**
 * A request with a schema, as each provider sends it, and the reply read
 * back: natively where the model has structured output, through a tool for
 * older Claude models, and as plain text when neither is possible or the
 * provider refuses the format.
 */
final class StructuredOutputTest extends ProviderTestCase
{
    private const REPLY = '{"verdicts":[{"notes":"Reads well.","id":"s1","verdict":"keep","reason":"Fine."}]}';

    private static function schema(): OutputSchema
    {
        return new OutputSchema('verdicts', [
            'type' => 'object',
            'required' => ['verdicts'],
            'properties' => ['verdicts' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'required' => ['notes', 'id', 'verdict'],
                'properties' => [
                    'notes' => ['type' => 'string', 'maxLength' => 200],
                    'id' => ['type' => 'string'],
                    'verdict' => ['type' => 'string', 'enum' => ['keep', 'fix', 'drop']],
                    'replacement' => ['type' => 'string'],
                ],
            ]]],
        ], 'One verdict for every suggestion.');
    }

    private function structured(string $agent = 'verifier', ?string $model = null, string $effort = 'high'): TextRequest
    {
        return (new TextRequest($agent, 'Be brief.', 'Check these.', model: $model, effort: $effort))->withSchema(self::schema());
    }

    public function test_claude_gets_the_schema_as_output_config_beside_the_effort_and_the_cache_still_holds(): void
    {
        $this->http->queueJson([
            'model' => 'claude-opus-5-5',
            'content' => [['type' => 'thinking', 'thinking' => ''], ['type' => 'text', 'text' => self::REPLY]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'cache_read_input_tokens' => 900, 'output_tokens' => 40],
        ]);

        $response = (new Anthropic('secret', $this->transport('anthropic')))->text($this->structured());
        $body = $this->http->body(0);

        $this->assertSame(['effort' => 'high', 'format' => ['type' => 'json_schema', 'schema' => Schemas::anthropic(self::schema())]], $body['output_config']);
        $this->assertFalse($body['output_config']['format']['schema']['additionalProperties']);
        $this->assertSame([['type' => 'text', 'text' => 'Be brief.', 'cache_control' => ['type' => 'ephemeral']]], $body['system'], 'The instructions are still the cached prefix.');
        $this->assertArrayNotHasKey('tools', $body);
        $this->assertArrayNotHasKey('tool_choice', $body);
        $this->assertSame(self::REPLY, $response->text);
        $this->assertSame('keep', $response->structured['verdicts'][0]['verdict'] ?? null);
        $this->assertSame(TextResponse::JSON_SCHEMA, $response->structuredBy);
        $this->assertEquals(['structured' => 'json_schema', 'parsed' => true, 'cache_read_tokens' => 900], array_intersect_key($this->logged('info')[0]['context'], ['structured' => 1, 'parsed' => 1, 'cache_read_tokens' => 1]));
    }

    public function test_an_older_claude_answers_through_a_tool_it_is_made_to_call(): void
    {
        $this->http->queueJson([
            'content' => [['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'reply', 'input' => json_decode(self::REPLY, true)]],
            'stop_reason' => 'tool_use',
        ]);

        $response = (new Anthropic('secret', $this->transport('anthropic')))->text($this->structured(model: 'claude-opus-4-1'));
        $body = $this->http->body(0);

        $this->assertSame([['name' => 'reply', 'description' => 'Send your reply. One verdict for every suggestion.', 'input_schema' => Schemas::anthropic(self::schema())]], $body['tools']);
        $this->assertSame(['type' => 'tool', 'name' => 'reply'], $body['tool_choice']);
        $this->assertArrayNotHasKey('output_config', $body, 'Opus 4.1 takes no effort either.');
        $this->assertSame(self::REPLY, $response->text, 'The tool input is the reply.');
        $this->assertSame(StopReason::End, $response->stopReason);
        $this->assertSame(TextResponse::TOOL, $response->structuredBy);
        $this->assertSame('s1', $response->structured['verdicts'][0]['id'] ?? null);
    }

    public function test_a_refused_format_sends_the_request_again_as_plain_text(): void
    {
        $this->http->queueJson(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'output_config.format: Schema is too complex for compilation.']], 400);
        $this->http->queueJson(['content' => [['type' => 'text', 'text' => '<verdicts>'.self::REPLY.'</verdicts>']], 'stop_reason' => 'end_turn']);

        $response = (new Anthropic('secret', $this->transport('anthropic')))->text($this->structured());

        $this->assertArrayHasKey('format', $this->http->body(0)['output_config']);
        $this->assertSame(['effort' => 'high'], $this->http->body(1)['output_config'], 'The effort stays.');
        $this->assertNull($response->structuredBy);
        $this->assertNull($response->structured);
        $this->assertStringStartsWith('<verdicts>', $response->text);
        $this->assertSame('The provider refused the reply format; asking again without it.', $this->logged('warning')[0]['message']);
    }

    public function test_any_other_400_still_fails(): void
    {
        $this->http->queueJson(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'max_tokens: too large']], 400);

        $this->expectException(BadResponse::class);

        (new Anthropic('secret', $this->transport('anthropic')))->text($this->structured());
    }

    public function test_a_refusal_retried_on_the_recommended_model_keeps_the_schema(): void
    {
        $this->http->queueJson(['content' => [], 'stop_reason' => 'refusal', 'stop_details' => ['category' => 'cyber', 'recommended_model' => 'claude-opus-4-8']]);
        $this->http->queueJson(['model' => 'claude-opus-4-8', 'content' => [['type' => 'text', 'text' => self::REPLY]], 'stop_reason' => 'end_turn']);

        $response = (new Anthropic('secret', $this->transport('anthropic')))->text($this->structured());

        $this->assertSame('claude-opus-4-8', $this->http->body(1)['model']);
        $this->assertSame('json_schema', $this->http->body(1)['output_config']['format']['type']);
        $this->assertSame(TextResponse::JSON_SCHEMA, $response->structuredBy);
        $this->assertSame('keep', $response->structured['verdicts'][0]['verdict'] ?? null);
    }

    public function test_chatgpt_gets_a_strict_response_format(): void
    {
        $this->http->queueJson(['model' => 'gpt-6.1-sol', 'choices' => [['message' => ['content' => self::REPLY], 'finish_reason' => 'stop']]]);

        $response = (new OpenAi('secret', $this->transport('openai')))->text($this->structured());
        $format = $this->http->body(0)['response_format'];

        $this->assertSame('json_schema', $format['type']);
        $this->assertSame(['name', 'description', 'schema', 'strict'], array_keys($format['json_schema']));
        $this->assertTrue($format['json_schema']['strict']);
        $this->assertSame('verdicts', $format['json_schema']['name']);
        $this->assertSame(['notes', 'id', 'verdict', 'replacement'], $format['json_schema']['schema']['properties']['verdicts']['items']['required']);
        $this->assertSame(['string', 'null'], $format['json_schema']['schema']['properties']['verdicts']['items']['properties']['replacement']['type']);
        $this->assertSame('keep', $response->structured['verdicts'][0]['verdict'] ?? null);
        $this->assertSame(TextResponse::JSON_SCHEMA, $response->structuredBy);
    }

    public function test_a_model_without_structured_output_is_asked_in_plain_text(): void
    {
        $this->http->queueJson(['choices' => [['message' => ['content' => '<verdicts>'.self::REPLY.'</verdicts>'], 'finish_reason' => 'stop']]]);

        $openai = new OpenAi('secret', $this->transport('openai'), 'gpt-3.5-turbo');
        $response = $openai->text($this->structured());

        $this->assertArrayNotHasKey('response_format', $this->http->body(0));
        $this->assertFalse($openai->takesSchema($this->structured()));
        $this->assertNull($response->structuredBy);
        $this->assertNull($response->structured);
    }

    public function test_a_gateway_that_refuses_response_format_is_asked_again_without_it(): void
    {
        $this->http->queueJson(['error' => ['message' => 'Unsupported parameter: response_format']], 400);
        $this->http->queueJson(['choices' => [['message' => ['content' => self::REPLY], 'finish_reason' => 'stop']]]);

        $response = (new OpenAi('secret', $this->transport('openai'), null, null, 'https://gateway.example.com/v1'))->text($this->structured());

        $this->assertArrayHasKey('response_format', $this->http->body(0));
        $this->assertArrayNotHasKey('response_format', $this->http->body(1));
        $this->assertNull($response->structuredBy);
    }

    public function test_openrouter_sends_claude_its_own_schema_and_others_the_strict_one(): void
    {
        $this->http->queueJson(['choices' => [['message' => ['content' => self::REPLY], 'finish_reason' => 'stop']]]);
        $this->http->queueJson(['choices' => [['message' => ['content' => self::REPLY], 'finish_reason' => 'stop']]]);
        $this->http->queueJson(['choices' => [['message' => ['content' => '<verdicts>'.self::REPLY.'</verdicts>'], 'finish_reason' => 'stop']]]);

        $router = new OpenRouter('sk-or-secret', $this->transport('openrouter'));
        $claude = $router->text($this->structured());
        $router->text($this->structured(model: 'openai/gpt-6.1-sol'));
        $llama = $router->text($this->structured(model: 'meta-llama/llama-4'));

        $this->assertSame(Schemas::anthropic(self::schema()), $this->http->body(0)['response_format']['json_schema']['schema'], 'Optional properties stay optional for Claude.');
        $this->assertTrue($this->http->body(0)['response_format']['json_schema']['strict']);
        $this->assertSame(Schemas::strict(self::schema()), $this->http->body(1)['response_format']['json_schema']['schema']);
        $this->assertArrayNotHasKey('response_format', $this->http->body(2));
        $this->assertSame(TextResponse::JSON_SCHEMA, $claude->structuredBy);
        $this->assertNull($llama->structuredBy);
        $this->assertSame([['type' => 'text', 'text' => 'Be brief.', 'cache_control' => ['type' => 'ephemeral']]], $this->http->body(0)['messages'][0]['content'], 'Claude\'s instructions are still cached.');
    }

    public function test_gemini_is_asked_for_json_of_the_shape(): void
    {
        $this->http->queueJson(['candidates' => [['content' => ['parts' => [['text' => 'Thinking…', 'thought' => true], ['text' => self::REPLY]]], 'finishReason' => 'STOP']]]);

        $response = (new Gemini('secret', $this->transport('gemini')))->text($this->structured());
        $config = $this->http->body(0)['generationConfig'];

        $this->assertSame('application/json', $config['responseMimeType']);
        $this->assertSame(Schemas::gemini(self::schema()), $config['responseJsonSchema']);
        $this->assertSame(['thinkingLevel' => 'high'], $config['thinkingConfig']);
        $this->assertSame('keep', $response->structured['verdicts'][0]['verdict'] ?? null);
    }

    public function test_gemini_refusing_the_schema_is_asked_again_without_it(): void
    {
        $this->http->queueJson(['error' => ['code' => 400, 'message' => 'Invalid JSON payload: responseJsonSchema has an unsupported keyword.']], 400);
        $this->http->queueJson(['candidates' => [['content' => ['parts' => [['text' => 'plain']]], 'finishReason' => 'STOP']]]);

        $response = (new Gemini('secret', $this->transport('gemini')))->text($this->structured());

        $this->assertArrayNotHasKey('responseJsonSchema', $this->http->body(1)['generationConfig']);
        $this->assertSame('plain', $response->text);
        $this->assertNull($response->structuredBy);
    }

    public function test_a_request_without_a_schema_is_sent_as_before(): void
    {
        $this->http->queueJson(['content' => [['type' => 'text', 'text' => 'OK']]]);

        $response = (new Anthropic('secret', $this->transport('anthropic')))->text(new TextRequest('verifier', 'Be brief.', 'Hi.', effort: 'high'));

        $this->assertSame(['effort' => 'high'], $this->http->body(0)['output_config']);
        $this->assertNull($response->structured);
        $this->assertNull($response->structuredBy);
        $this->assertArrayNotHasKey('structured', $this->logged('info')[0]['context']);
    }
}
