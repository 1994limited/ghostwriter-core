<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Ai;

use GuzzleHttp\Client;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Agents;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Effort;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Http\GuzzleHttpClients;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Ai\ImageRequest;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Message;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Models;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Shape;
use NineteenNinetyFour\Ghostwriter\Core\Ai\StopReason;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextResponse;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Usage;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\PromptLibrary;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;

/**
 * The model and agent tables, and the value objects.
 */
class CatalogueTest extends TestCase
{
    public function test_the_default_models(): void
    {
        $this->assertSame('claude-opus-5-5', Models::defaultText('anthropic'));
        $this->assertSame('gpt-6.1-sol', Models::defaultText('openai'));
        $this->assertSame('gemini-3.8-flash', Models::defaultText('gemini'));
        $this->assertSame('gpt-image-2.5-sunburst', Models::defaultImage('openai'));
        $this->assertSame('gemini-3.1-flash-image', Models::defaultImage('gemini'));

        $this->expectException(\InvalidArgumentException::class);
        Models::defaultImage('anthropic');
    }

    public function test_openrouter_defaults_by_tier_and_its_effort(): void
    {
        $this->assertSame('anthropic/claude-opus-5.5', Models::defaultText('openrouter'));
        $this->assertSame('openai/gpt-image-2.5-sunburst', Models::defaultImage('openrouter'));
        $this->assertSame(['openai', 'gemini', 'openrouter'], Models::IMAGE_ORDER);
        $this->assertSame('anthropic/claude-opus-5.5', Models::defaultTextFor('openrouter', 'writer'));
        $this->assertSame('anthropic/claude-sonnet-5.5', Models::defaultTextFor('openrouter', 'photo-picker'));
        $this->assertSame('claude-opus-5-5', Models::defaultTextFor('anthropic', 'photo-picker'), 'Other providers have one default.');
        $this->assertSame(['quick', 'writing', 'writing'], [Agents::tier('gap-filler'), Agents::tier('writer'), Agents::tier('unknown')]);

        $this->assertSame(['anthropic', 'claude-opus-5-5'], Models::nativeModel('anthropic/claude-opus-5.5'));
        $this->assertSame(['gemini', 'gemini-3.8-flash'], Models::nativeModel('google/gemini-3.8-flash:free'));
        $this->assertNull(Models::nativeModel('meta-llama/llama-4'));

        $this->assertTrue(Models::takesEffort('openrouter', 'anthropic/claude-sonnet-5.5'));
        $this->assertTrue(Models::takesEffort('openrouter', 'openai/gpt-6.1-sol'));
        $this->assertTrue(Models::takesEffort('openrouter', 'google/gemini-3.8-flash'));
        $this->assertFalse(Models::takesEffort('openrouter', 'anthropic/claude-haiku-4.5'));
        $this->assertFalse(Models::takesEffort('openrouter', 'meta-llama/llama-4'));

        foreach ([...Models::OPENROUTER_TIERS, ...array_keys(Models::OPENROUTER_TEXT_CHOICES), ...array_keys(Models::OPENROUTER_IMAGE_CHOICES)] as $id) {
            $this->assertMatchesRegularExpression('#^[a-z0-9-]+/[a-z0-9.-]+$#', $id);
        }
    }

    public function test_which_models_take_effort_and_fallbacks(): void
    {
        foreach (['claude-opus-5-5', 'claude-opus-4-6', 'claude-sonnet-5-5', 'claude-fable-5-1', 'claude-opus-5', 'claude-sonnet-4-6-20260101'] as $model) {
            $this->assertTrue(Models::takesEffort('anthropic', $model), $model);
        }

        foreach (['claude-haiku-4-5', 'claude-opus-4-5', 'claude-sonnet-4-20250514', 'claude-3-7-sonnet'] as $model) {
            $this->assertFalse(Models::takesEffort('anthropic', $model), $model);
        }

        $this->assertTrue(Models::takesEffort('openai', 'gpt-6.1-sol'));
        $this->assertTrue(Models::takesEffort('openai', 'gpt-6'));
        $this->assertFalse(Models::takesEffort('openai', 'gpt-4.1'));
        $this->assertTrue(Models::takesEffort('gemini', 'gemini-3.8-flash'));
        $this->assertTrue(Models::takesEffort('gemini', 'gemini-2.5-pro'));
        $this->assertFalse(Models::takesEffort('gemini', 'gemini-2.0-flash'));
        $this->assertFalse(Models::takesEffort('fake', 'fake'));

        foreach (['claude-fable-5-1', 'claude-fable-5', 'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5', 'claude-opus-5-5-20260301'] as $model) {
            $this->assertTrue(Models::takesFallbacks($model), $model);
        }

        foreach (['claude-sonnet-4-6', 'claude-haiku-4-5', 'claude-opus-4-6', 'claude-sonnet-5'] as $model) {
            $this->assertFalse(Models::takesFallbacks($model), $model);
        }
    }

    public function test_every_agent_has_its_limits(): void
    {
        $this->assertSame(16000, Agents::maxTokens('writer'));
        $this->assertSame(8000, Agents::maxTokens('kind-finder'));
        $this->assertSame(6000, Agents::maxTokens('brief-writer'));
        $this->assertSame(2000, Agents::maxTokens('photo-scout'));
        $this->assertSame(16000, Agents::maxTokens('something-new'));
        $this->assertSame(Effort::Low, Agents::effort('photo-picker'));
        $this->assertSame([2000, Effort::Low], [Agents::maxTokens('photo-query'), Agents::effort('photo-query')]);
        $this->assertNull(Agents::effort('writer'));
        $this->assertSame([16000, Effort::High], [Agents::maxTokens('reviewer'), Agents::effort('reviewer')]);
        $this->assertSame([12000, Effort::High], [Agents::maxTokens('verifier'), Agents::effort('verifier')]);
        $this->assertSame([4000, Effort::Medium], [Agents::maxTokens('reworder'), Agents::effort('reworder')]);
        $this->assertSame([Agents::WRITING, Agents::WRITING, Agents::WRITING], [Agents::tier('reviewer'), Agents::tier('verifier'), Agents::tier('reworder')], 'Suggest edits writes on the top tier.');
        $this->assertTrue(Agents::cachesInstructions('reviewer'));
        $this->assertFalse(Agents::cachesInstructions('writer'));

        foreach (PromptLibrary::NAMES as $name) {
            if (! in_array($name, ['image', 'writer-extras', 'scoped-edit'], true)) {
                $this->assertArrayHasKey($name, Agents::MAX_TOKENS, "{$name} has no token limit.");
            }
        }
    }

    public function test_requests_take_the_older_string_forms(): void
    {
        $request = new TextRequest('photo-picker', 'I', 'P', effort: 'high');
        $this->assertSame(Effort::High, $request->effort);
        $this->assertSame(Effort::High, $request->resolvedEffort());
        $this->assertSame(2000, $request->resolvedMaxTokens());
        $this->assertNull((new TextRequest('writer', 'I', 'P', effort: 'extreme'))->effort);

        $this->assertSame(Shape::Portrait, (new ImageRequest('x', shape: 'portrait'))->shape);
        $this->assertSame(Shape::Landscape, (new ImageRequest('x', shape: 'round'))->shape);
        $this->assertSame(Shape::Square, (new ImageRequest('x', shape: Shape::Square))->shape);
    }

    public function test_a_response_keeps_the_deprecated_fields_readable(): void
    {
        $response = new TextResponse('Hi', StopReason::MaxTokens, new Usage(10, 20), 'anthropic', 'claude-opus-5-5');

        $this->assertSame([10, 20, true], [$response->inputTokens, $response->outputTokens, $response->truncated]);
        $this->assertTrue(isset($response->inputTokens));
        $this->assertSame([0, 0, StopReason::End], [(new TextResponse('Hi'))->usage->input, (new TextResponse('Hi'))->usage->output, (new TextResponse('Hi'))->stopReason]);

        $this->expectException(\LogicException::class);
        $response->__get('nope');
    }

    public function test_images_and_messages(): void
    {
        $png = Image::fromString((string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

        $this->assertSame(['image/png', 'png', 70], [$png->mime, $png->extension(), $png->bytes()]);
        $this->assertSame('jpg', (new Image('x', 'image/jpeg'))->extension());
        $this->assertEquals([new Message('user', 'Hi'), new Message('assistant', 'Hello')], Message::list([['role' => 'user', 'content' => 'Hi'], ['role' => 'assistant', 'content' => 'Hello']]));

        $this->expectException(\InvalidArgumentException::class);
        Image::fromString('not an image');
    }

    public function test_guzzle_clients_carry_the_timeouts(): void
    {
        $clients = new GuzzleHttpClients(['headers' => ['x-test' => '1'], 'timeout' => 5]);
        $client = $clients->client(120);

        $this->assertInstanceOf(ClientInterface::class, $client);
        $this->assertInstanceOf(Client::class, $client);
        $this->assertSame([120, 15, '1'], [$client->getConfig('timeout'), $client->getConfig('connect_timeout'), $client->getConfig('headers')['x-test']]);
        $this->assertSame('POST', $clients->requestFactory()->createRequest('POST', 'https://example.com')->getMethod());
    }

    public function test_a_text_request_can_be_copied_with_a_new_limit_or_model(): void
    {
        $images = [new Image('x', 'image/png')];
        $request = new TextRequest('writer', 'Be brief.', 'Go.', [new Message('user', 'Hi')], $images, model: 'claude-opus-5-5', timeout: 60, effort: 'high');

        $more = $request->withMaxTokens(32000);
        $this->assertNotSame($request, $more);
        $this->assertSame([32000, 16000], [$more->resolvedMaxTokens(), $request->resolvedMaxTokens()]);
        $this->assertSame(['writer', 'Be brief.', 'Go.', $images, 'claude-opus-5-5', 60, Effort::High], [$more->agent, $more->instructions, $more->prompt, $more->images, $more->model, $more->timeout, $more->effort]);
        $this->assertEquals($request->history, $more->history);

        $other = $more->withModel('claude-sonnet-5');
        $this->assertSame(['claude-sonnet-5', 32000, 'claude-opus-5-5'], [$other->model, $other->maxTokens, $more->model]);
        $this->assertNull($other->withModel(null)->model);
        $this->assertSame(32000, $other->withModel(null)->maxTokens);
    }
}
