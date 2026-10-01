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
            } catch (AssertionError $error) {
                $this->assertNotSame('', $error->getMessage());
            }
        }

        try {
            $fake->assertSent('writer');
        } catch (AssertionError $error) {
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

        $this->expectException(AssertionError::class);
        $fake->assertNothingSent();
    }
}
