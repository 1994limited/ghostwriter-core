<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Testing;

use AssertionError;
use Closure;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Ai\ImageProvider;
use NineteenNinetyFour\Ghostwriter\Core\Ai\ImageRequest;
use NineteenNinetyFour\Ghostwriter\Core\Ai\StopReason;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextProvider;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextResponse;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Usage;

/**
 * Stands in for every model in tests, so no call leaves the machine and no
 * key is needed. Answers are queued per agent (writer, voice-analyst and so
 * on) and handed out in order; the last one keeps being given once the
 * others are used up. Every request is kept for inspection.
 *
 *     $fake = $providers->fake();
 *     $fake->respond('writer', '<reply>Here it is.</reply><draft>...</draft>');
 *     // ...
 *     $fake->assertSent('writer', fn (TextRequest $r) => str_contains($r->prompt, 'harbour'));
 *     $fake->assertNotSent('photo-picker');
 *     $fake->prompted('writer')[0]->prompt;
 *
 * The asserts throw AssertionError, which PHPUnit reports as a failure, so
 * core needs no test framework at runtime.
 */
class FakeProvider implements ImageProvider, TextProvider
{
    /** A 1x1 PNG, the image made unless told otherwise. */
    public const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** @var array<string, array<int, string|Closure|TextResponse>> */
    private array $answers = [];

    /** @var array<int, TextRequest> */
    private array $requests = [];

    /** @var array<int, ImageRequest> Every image request, in order. */
    public array $imageRequests = [];

    private Image|Closure|null $image = null;

    /**
     * Queue answers for one agent: text, a TextResponse, or a closure given
     * the request that returns either, or throws to stand for a failed call.
     */
    public function respond(string $agent, string|Closure|TextResponse ...$answers): static
    {
        $this->answers[$agent] = [...($this->answers[$agent] ?? []), ...array_values($answers)];

        return $this;
    }

    /**
     * The agent's next call throws this.
     */
    public function failWith(string $agent, ProviderException $exception): static
    {
        return $this->respond($agent, function () use ($exception): never {
            throw $exception;
        });
    }

    /**
     * The image to make: an Image, or a closure given the request that
     * returns one or throws.
     */
    public function respondWithImage(Image|Closure $image): static
    {
        $this->image = $image;

        return $this;
    }

    /**
     * @return array<int, TextRequest> Every request one agent was sent, in order.
     */
    public function prompted(string $agent): array
    {
        return array_values(array_filter($this->requests, fn (TextRequest $request) => $request->agent === $agent));
    }

    /**
     * @return array<int, TextRequest> Every text request, in order.
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * @param  (callable(TextRequest): bool)|null  $check  Must hold for at least one request to the agent.
     *
     * @throws AssertionError
     */
    public function assertSent(string $agent, ?callable $check = null): void
    {
        $sent = $this->prompted($agent);

        if ($sent === []) {
            throw new AssertionError("Expected a request to \"{$agent}\", but none was sent.".$this->summary());
        }

        if ($check !== null && array_filter($sent, fn (TextRequest $request) => (bool) $check($request)) === []) {
            throw new AssertionError(sprintf('"%s" was sent %d request(s), but none passed the check.', $agent, count($sent)));
        }
    }

    /**
     * @param  (callable(TextRequest): bool)|null  $check  When given, only requests passing it count.
     *
     * @throws AssertionError
     */
    public function assertNotSent(string $agent, ?callable $check = null): void
    {
        $sent = $this->prompted($agent);
        $matching = $check === null ? $sent : array_filter($sent, fn (TextRequest $request) => (bool) $check($request));

        if ($matching !== []) {
            throw new AssertionError(sprintf('Expected no %srequest to "%s", but %d were sent.', $check === null ? '' : 'matching ', $agent, count($matching)));
        }
    }

    /**
     * No text or image request was made at all.
     *
     * @throws AssertionError
     */
    public function assertNothingSent(): void
    {
        if ($this->requests !== [] || $this->imageRequests !== []) {
            throw new AssertionError(sprintf('Expected no requests, but %d text and %d image request(s) were sent.', count($this->requests), count($this->imageRequests)).$this->summary());
        }
    }

    public function handle(): string
    {
        return 'fake';
    }

    public function text(TextRequest $request): TextResponse
    {
        $this->requests[] = $request;

        $queue = $this->answers[$request->agent] ?? [];

        if ($queue === []) {
            throw new ProviderException("The fake has no answer queued for \"{$request->agent}\".", 'fake');
        }

        // The last answer keeps being given once the others are used up.
        $answer = count($queue) > 1 ? array_shift($this->answers[$request->agent]) : $queue[0];
        $text = $answer instanceof Closure ? $answer($request) : $answer;

        return $text instanceof TextResponse
            ? $text
            : new TextResponse((string) $text, StopReason::End, new Usage(100, 50), 'fake', $request->model ?? 'fake');
    }

    public function image(ImageRequest $request): Image
    {
        $this->imageRequests[] = $request;

        $image = $this->image instanceof Closure ? ($this->image)($request) : $this->image;

        return $image instanceof Image ? $image : Image::fromString((string) base64_decode(self::PNG));
    }

    private function summary(): string
    {
        $agents = array_count_values(array_map(fn (TextRequest $request) => $request->agent, $this->requests));

        if ($agents === []) {
            return '';
        }

        return ' Sent: '.implode(', ', array_map(fn ($agent, $count) => "{$agent} ×{$count}", array_keys($agents), $agents)).'.';
    }
}
