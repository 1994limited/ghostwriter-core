<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Ai\Testing;

use AssertionError;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\RateLimited;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Ai\ImageRequest;
use NineteenNinetyFour\Ghostwriter\Core\Ai\StopReason;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\FakeProvider;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextResponse;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Usage;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

class FakeProviderTest extends TestCase
{
    private function ask(FakeProvider $fake, string $agent, string $prompt = 'Go.', array $images = []): TextResponse
    {
        return $fake->text(new TextRequest($agent, 'Instructions.', $prompt, images: $images));
    }

    public function test_answers_are_given_in_order_and_the_last_one_repeats(): void
    {
        $fake = (new FakeProvider)->respond('writer', 'One', fn (TextRequest $request) => "Two for {$request->prompt}")->respond('writer', 'Three');

        $this->assertSame(['One', 'Two for Go.', 'Three', 'Three'], array_map(fn () => $this->ask($fake, 'writer')->text, range(1, 4)));

        $response = $this->ask($fake, 'writer');
        $this->assertSame([StopReason::End, 100, 50, 'fake'], [$response->stopReason, $response->usage->input, $response->usage->output, $response->provider]);
        $this->assertCount(5, $fake->prompted('writer'));
        $this->assertSame('fake', $fake->handle());
    }

    public function test_a_queued_response_is_handed_back_as_it_is(): void
    {
        $cut = new TextResponse('title: Half', StopReason::MaxTokens, new Usage(100, 16000));
        $fake = (new FakeProvider)->respond('writer', $cut);

        $this->assertSame($cut, $this->ask($fake, 'writer'));
        $this->assertTrue($this->ask($fake, 'writer')->truncated());
    }

    public function test_an_agent_with_nothing_queued_fails(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('The fake has no answer queued for "planner".');

        $this->ask(new FakeProvider, 'planner');
    }

    public function test_fail_with_makes_the_next_call_throw(): void
    {
        $fake = (new FakeProvider)->failWith('writer', new RateLimited('Busy.', 'anthropic', 429))->respond('writer', 'Fine now.');

        try {
            $this->ask($fake, 'writer');
            $this->fail('Expected the queued failure.');
        } catch (RateLimited $exception) {
            $this->assertSame('Busy.', $exception->getMessage());
        }

        $this->assertSame('Fine now.', $this->ask($fake, 'writer')->text);
    }

    public function test_assert_sent_and_not_sent(): void
    {
        $fake = (new FakeProvider)->respond('photo-picker', '1, 2');
        $fake->assertNothingSent();

        $this->ask($fake, 'photo-picker', 'Pick.', array_fill(0, 9, new Image('x', 'image/png')));

        $fake->assertSent('photo-picker');
        $fake->assertSent('photo-picker', fn (TextRequest $request) => count($request->images) === 9);
        $fake->assertNotSent('writer');
        $fake->assertNotSent('photo-picker', fn (TextRequest $request) => count($request->images) === 3);

        $failures = [
            fn () => $fake->assertSent('writer'),
            fn () => $fake->assertSent('photo-picker', fn (TextRequest $request) => $request->images === []),
            fn () => $fake->assertNotSent('photo-picker'),
            fn () => $fake->assertNothingSent(),
        ];

        foreach ($failures as $i => $failure) {
            try {
                $failure();
                $this->fail("Assertion {$i} should have failed.");
            } catch (AssertionFailedError $error) {
                $this->assertNotSame('', $error->getMessage());
            }
        }

        try {
            $fake->assertSent('writer');
            $this->fail('Expected the assertion to fail.');
        } catch (AssertionFailedError $error) {
            $this->assertStringContainsString('Sent: photo-picker ×1.', $error->getMessage());
        }
    }

    public function test_images_are_made_and_recorded(): void
    {
        $fake = new FakeProvider;

        $this->assertSame('image/png', $fake->image(new ImageRequest('A lighthouse'))->mime);

        $mine = new Image('bytes', 'image/webp');
        $fake->respondWithImage(fn (ImageRequest $request) => $request->prompt === 'Mine' ? $mine : Image::fromString((string) base64_decode(FakeProvider::PNG)));

        $this->assertSame($mine, $fake->image(new ImageRequest('Mine', shape: 'portrait')));
        $this->assertSame(['A lighthouse', 'Mine'], array_map(fn (ImageRequest $request) => $request->prompt, $fake->imageRequests));

        $this->expectException(AssertionFailedError::class);
        $fake->assertNothingSent();
    }

    public function test_image_asserts(): void
    {
        $fake = new FakeProvider;
        $fake->assertNoImageSent();

        $fake->image(new ImageRequest('A lighthouse', shape: 'landscape'));

        $fake->assertImageSent();
        $fake->assertImageSent(fn (ImageRequest $request) => $request->prompt === 'A lighthouse');

        foreach ([fn () => $fake->assertNoImageSent(), fn () => $fake->assertImageSent(fn (ImageRequest $request) => $request->prompt === 'A harbour'), fn () => (new FakeProvider)->assertImageSent()] as $i => $failure) {
            try {
                $failure();
                $this->fail("Image assertion {$i} should have failed.");
            } catch (AssertionFailedError $error) {
                $this->assertMatchesRegularExpression('/image request/', $error->getMessage());
            }
        }
    }

    public function test_the_asserts_count_with_phpunit(): void
    {
        $fake = (new FakeProvider)->respond('writer', 'Hi.');
        $before = Assert::getCount();

        $this->ask($fake, 'writer');
        $fake->assertSent('writer');
        $fake->assertNotSent('planner');
        $fake->assertNoImageSent();

        $this->assertSame($before + 3, Assert::getCount());
    }

    public function test_a_test_that_only_asserts_on_the_fake_is_not_risky(): void
    {
        // failOnRisky is on: this would fail if the assert weren't counted.
        (new FakeProvider)->assertNothingSent();
    }

    public function test_without_phpunit_the_asserts_throw_assertion_error(): void
    {
        $fake = new class extends FakeProvider
        {
            protected function usesPhpUnit(): bool
            {
                return false;
            }
        };

        $fake->assertNothingSent();
        $fake->image(new ImageRequest('A lighthouse'));

        foreach ([fn () => $fake->assertSent('writer'), fn () => $fake->assertNothingSent(), fn () => $fake->assertNoImageSent()] as $i => $failure) {
            try {
                $failure();
                $this->fail("Assertion {$i} should have failed.");
            } catch (AssertionError $error) {
                $this->assertNotSame('', $error->getMessage());
            }
        }
    }

    public function test_reset_forgets_one_agent_or_everything(): void
    {
        $fake = (new FakeProvider)->respond('writer', 'Draft.')->respond('planner', 'Plan.')->respondWithImage(new Image('bytes', 'image/webp'));
        $this->ask($fake, 'writer');
        $this->ask($fake, 'planner');
        $fake->image(new ImageRequest('A lighthouse'));

        $this->assertSame($fake, $fake->reset('writer'));
        $fake->assertNotSent('writer');
        $fake->assertSent('planner');
        $this->assertCount(1, $fake->imageRequests);
        $this->assertSame('Plan.', $this->ask($fake, 'planner')->text);

        try {
            $this->ask($fake, 'writer');
            $this->fail('Expected the writer\'s answers to be gone.');
        } catch (ProviderException $exception) {
            $this->assertStringContainsString('no answer queued for "writer"', $exception->getMessage());
        }

        $fake->unconfigured(image: false);
        $fake->reset();
        $fake->assertNothingSent();
        $this->assertSame('image/png', $fake->image(new ImageRequest('Again'))->mime);
        $this->assertFalse($fake->textConfigured(), 'reset() keeps the fake unconfigured.');

        $this->expectException(ProviderException::class);
        $this->ask($fake, 'planner');
    }

    public function test_without_keys(): void
    {
        $fake = FakeProvider::withoutKeys();
        $this->assertSame([false, false], [$fake->textConfigured(), $fake->imageConfigured()]);
        $this->assertSame([true, true], [(new FakeProvider)->textConfigured(), (new FakeProvider)->imageConfigured()]);

        $fake->unconfigured(text: false);
        $this->assertSame([true, false], [$fake->textConfigured(), $fake->imageConfigured()]);
    }
}
