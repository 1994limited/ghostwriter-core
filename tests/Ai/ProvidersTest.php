<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Ai;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Agents;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\NotConfigured;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\Overloaded;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Http\RetryPolicy;
use NineteenNinetyFour\Ghostwriter\Core\Ai\ImageRequest;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\ArrayCredentials;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\ModelTiers;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Ports\StaticProviderSettings;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers\Anthropic;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers\Gemini;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers\OpenAi;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers\OpenRouter;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\FakeProvider;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\RecordingSleeper;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;

/**
 * The registry: which provider is used, from the settings and the keys.
 */
class ProvidersTest extends ProviderTestCase
{
    private ArrayCredentials $keys;

    private StaticProviderSettings $settings;

    private Providers $providers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->keys = new ArrayCredentials(['anthropic' => '  a-key  ']);
        $this->settings = new StaticProviderSettings;
        $this->providers = new Providers($this->keys, $this->http, $this->settings, sleeper: $this->sleeper);
    }

    public function test_the_provider_is_chosen_from_the_settings_and_the_keys(): void
    {
        $this->assertInstanceOf(Anthropic::class, $this->providers->text());
        $this->assertTrue($this->providers->configured());
        $this->assertNull($this->providers->image(), 'Claude does not make images.');
        $this->assertNull($this->providers->imageHandle());

        $this->settings->textProvider = 'openai';
        $this->assertFalse($this->providers->configured());

        try {
            $this->providers->text();
            $this->fail('Expected NotConfigured.');
        } catch (NotConfigured $exception) {
            $this->assertSame('No API key is set for OpenAI. Add OPENAI_API_KEY to your .env file.', $exception->getMessage());
        }

        // Images go to whichever image provider has a key, unless one is chosen.
        $this->keys->set('gemini', 'g');
        $this->assertInstanceOf(Gemini::class, $this->providers->image());

        $this->keys->set('openai', 'o');
        $this->assertInstanceOf(OpenAi::class, $this->providers->image());
        $this->assertInstanceOf(OpenAi::class, $this->providers->text());

        $this->settings->imageProvider = 'gemini';
        $this->assertInstanceOf(Gemini::class, $this->providers->image());

        $this->keys->set('gemini', ' ');
        $this->assertNull($this->providers->image(), 'The chosen provider has no key.');

        $this->settings->imageProvider = 'anthropic';
        $this->assertNull($this->providers->imageHandle(), 'Claude does not make images.');
    }

    public function test_openrouter_writes_and_makes_images_only_after_the_others(): void
    {
        $this->keys->set('openrouter', 'or');
        $this->settings->textProvider = 'openrouter';

        $this->assertInstanceOf(OpenRouter::class, $this->providers->text());
        $this->assertSame('openrouter', $this->providers->imageHandle(), 'With no OpenAI or Gemini key, OpenRouter makes the images.');
        $this->assertInstanceOf(OpenRouter::class, $this->providers->image());

        $this->keys->set('gemini', 'g');
        $this->assertSame('gemini', $this->providers->imageHandle(), 'An existing image key keeps making images.');

        $this->settings->imageProvider = 'openrouter';
        $this->assertSame('openrouter', $this->providers->imageHandle());
    }

    public function test_settings_can_choose_a_model_per_tier(): void
    {
        $settings = new StaticProviderSettings('openrouter', 'openai/gpt-6.1-sol', tierModels: ['openrouter' => [Agents::QUICK => 'google/gemini-3.8-flash']]);
        $this->assertInstanceOf(ModelTiers::class, $settings);
        $this->keys->set('openrouter', 'or');
        $this->http->queueJson(['choices' => [['message' => ['content' => 'OK'], 'finish_reason' => 'stop']]]);
        $this->http->queueJson(['choices' => [['message' => ['content' => 'OK'], 'finish_reason' => 'stop']]]);

        $providers = new Providers($this->keys, $this->http, $settings, sleeper: $this->sleeper);
        $providers->text()->text(new TextRequest('photo-picker', 'I', 'P'));
        $providers->text()->text(new TextRequest('writer', 'I', 'P'));

        $this->assertSame(['google/gemini-3.8-flash', 'openai/gpt-6.1-sol'], [$this->http->body(0)['model'], $this->http->body(1)['model']]);
    }

    public function test_an_unknown_provider_is_not_configured(): void
    {
        $this->settings->textProvider = 'xai';

        $this->assertFalse($this->providers->configured());
        $this->expectException(NotConfigured::class);
        $this->expectExceptionMessage('"xai" is not a provider Ghostwriter can write with.');

        $this->providers->text();
    }

    public function test_the_settings_reach_the_provider(): void
    {
        $this->settings->textModel = 'claude-sonnet-5-5';
        $this->settings->timeout = 180;
        $this->settings->anthropicFallbacks = false;
        $this->http->queueJson(['content' => [['type' => 'text', 'text' => 'OK']]]);

        $this->providers->text()->text(new TextRequest('writer', 'Be brief.', 'Hi.'));

        $this->assertSame('a-key', $this->http->requests[0]->getHeaderLine('x-api-key'), 'Trimmed.');
        $this->assertSame('claude-sonnet-5-5', $this->http->body(0)['model']);
        $this->assertArrayNotHasKey('fallbacks', $this->http->body(0));
        $this->assertSame([180], $this->http->timeouts);
    }

    public function test_the_image_model_and_base_url_reach_the_image_provider(): void
    {
        $this->keys->set('openai', 'o');
        $this->settings->imageModel = 'gpt-image-2.5-flare';
        $this->settings->baseUrls = ['openai' => 'https://gateway.example.com/v1'];
        $this->http->queueJson(['data' => [['b64_json' => self::PNG]]]);

        $this->providers->image()?->image(new ImageRequest('A lighthouse'));

        $this->assertSame('https://gateway.example.com/v1/images/generations', (string) $this->http->requests[0]->getUri());
        $this->assertSame('gpt-image-2.5-flare', $this->http->body(0)['model']);
    }

    public function test_a_bad_base_url_is_refused_when_the_provider_is_built(): void
    {
        $this->settings->baseUrls = ['anthropic' => 'http://gateway.example.com'];

        $this->expectException(NotConfigured::class);

        $this->providers->text();
    }

    public function test_the_settings_screen_is_told_which_keys_exist_never_what_they_are(): void
    {
        $this->keys->set('gemini', 'g')->set('pexels', '');

        $status = $this->providers->keyStatus();

        $this->assertSame([
            'ANTHROPIC_API_KEY' => true,
            'OPENAI_API_KEY' => false,
            'GEMINI_API_KEY' => true,
            'UNSPLASH_ACCESS_KEY' => false,
            'PIXABAY_API_KEY' => false,
            'PEXELS_API_KEY' => false,
            'OPENROUTER_API_KEY' => false,
        ], $status);
    }

    public function test_a_fake_stands_in_for_every_model(): void
    {
        $this->keys->set('anthropic', null);
        $fake = $this->providers->fake();

        $this->assertTrue($this->providers->configured());
        $this->assertSame($fake, $this->providers->text());
        $this->assertSame($fake, $this->providers->image());
        $this->assertSame(['fake', 'fake'], [$this->providers->textHandle(), $this->providers->imageHandle()]);

        $mine = new FakeProvider;
        $this->assertSame($mine, $this->providers->fake($mine));

        $this->providers->unfake();
        $this->assertFalse($this->providers->faked());
        $this->assertFalse($this->providers->configured());
    }

    public function test_an_unconfigured_fake_stands_for_missing_keys(): void
    {
        $this->keys->set('openai', 'o');
        $fake = $this->providers->fake(FakeProvider::withoutKeys());

        $this->assertTrue($this->providers->faked());
        $this->assertFalse($this->providers->configured());
        $this->assertNull($this->providers->imageHandle());
        $this->assertNull($this->providers->image());
        $this->assertNotContains(true, $this->providers->keyStatus());
        $this->assertSame('fake', $this->providers->textHandle());

        try {
            $this->providers->text();
            $this->fail('Expected NotConfigured.');
        } catch (NotConfigured $exception) {
            $this->assertSame('No API key is set for Anthropic. Add ANTHROPIC_API_KEY to your .env file.', $exception->getMessage());
        }

        // A text key but no image key.
        $fake->unconfigured(text: false);
        $this->assertTrue($this->providers->configured());
        $this->assertSame($fake, $this->providers->text());
        $this->assertNull($this->providers->image());
        $this->assertNotContains(true, $this->providers->keyStatus());

        // An image key but no text key.
        $fake->unconfigured(image: false);
        $this->assertFalse($this->providers->configured());
        $this->assertSame([$fake, 'fake'], [$this->providers->image(), $this->providers->imageHandle()]);

        // Both keys back: the real key status shows again.
        $fake->unconfigured(false, false);
        $this->assertTrue($this->providers->configured());
        $this->assertSame([true, true], [$this->providers->keyStatus()['ANTHROPIC_API_KEY'], $this->providers->keyStatus()['OPENAI_API_KEY']]);
        $fake->assertNothingSent();
    }

    public function test_an_unconfigured_fake_names_an_unknown_provider_plainly(): void
    {
        $this->settings->textProvider = 'mistral';
        $this->providers->fake(FakeProvider::withoutKeys());

        $this->expectException(NotConfigured::class);
        $this->expectExceptionMessage('No API key is set for "mistral".');

        $this->providers->text();
    }

    public function test_a_copy_can_have_its_own_sleeper(): void
    {
        $sleeper = new RecordingSleeper;
        $copy = $this->providers->withSleeper($sleeper);

        $this->assertNotSame($this->providers, $copy);

        $this->http->queueJson(['error' => ['message' => 'Rate limited']], 429, ['retry-after' => '7']);
        $this->http->queueJson(['content' => [['type' => 'text', 'text' => 'OK']]]);

        $this->assertSame('OK', $copy->text()->text(new TextRequest('writer', 'I', 'P'))->text);
        $this->assertSame([7.0], $sleeper->waits);
        $this->assertSame([], $this->sleeper->waits);
    }

    public function test_a_copy_can_have_its_own_retry_policy(): void
    {
        $copy = $this->providers->withRetryPolicy(new RetryPolicy(attempts: 1));

        $this->http->queueJson(['error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529);

        try {
            $copy->text()->text(new TextRequest('writer', 'I', 'P'));
            $this->fail('Expected the single attempt to fail.');
        } catch (Overloaded) {
            $this->assertCount(1, $this->http->requests);
            $this->assertSame([], $this->sleeper->waits);
        }
    }

    public function test_a_fake_carries_over_to_a_copy(): void
    {
        $fake = $this->providers->fake();

        $this->assertSame($fake, $this->providers->withSleeper(new RecordingSleeper)->text());
        $this->assertSame($fake, $this->providers->withRetryPolicy(new RetryPolicy)->image());
    }
}
