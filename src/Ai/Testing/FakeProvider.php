<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Ai\Testing;

use AssertionError;
use Closure;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Ai\ImageProvider;
use NineteenNinetyFour\Ghostwriter\Core\Ai\ImageRequest;
use NineteenNinetyFour\Ghostwriter\Core\Ai\StopReason;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Structured\JsonReply;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TakesSchemas;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextProvider;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextResponse;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Usage;
use PHPUnit\Framework\Assert;

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
 *     $fake->assertImageSent(fn (ImageRequest $r) => $r->shape === Shape::Landscape);
 *     $fake->prompted('writer')[0]->prompt;
 *     $fake->reset();                       // forget answers and requests, e.g. between steps
 *
 * When PHPUnit is loaded (Pest runs on it too), the asserts go through
 * PHPUnit\Framework\Assert, so they count as assertions and a test that
 * only asserts on the fake isn't marked risky. Without PHPUnit they throw
 * AssertionError, so core needs no test framework at runtime.
 *
 * To test what happens without keys, mark the fake unconfigured: the
 * Providers registry then reports configured() false, imageHandle() and
 * image() null, keyStatus() all false, and text() throws NotConfigured.
 *
 *     $providers->fake(FakeProvider::withoutKeys());
 *     $providers->fake()->unconfigured(text: false);   // a text key, but no image key
 *
 * Structured replies: the fake stands for a model held to a request's
 * schema (TakesSchemas), so a reply to a request with one is decoded onto
 * TextResponse::$structured, as a provider would. Queue the object itself,
 * or have one made up from the schema; to stand for a model without
 * structured output (the prompt-and-parse path), turn it off.
 *
 *     $fake->respondStructured('verifier', ['verdicts' => [['notes' => '…', 'id' => 's1', 'verdict' => 'keep', 'reason' => '…']]]);
 *     $fake->respondFromSchema('verifier');   // SchemaFaker's data of the request's shape
 *     $fake->withoutStructuredOutput();
 */
class FakeProvider implements ImageProvider, TakesSchemas, TextProvider
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

    private bool $textConfigured = true;

    private bool $imageConfigured = true;

    private bool $structuredOutput = true;

    /**
     * A fake that stands for a site with no keys at all.
     */
    public static function withoutKeys(): self
    {
        return (new self)->unconfigured();
    }

    /**
     * Stand for a site without the text key, the image key, or both (the
     * default). unconfigured(false, false) gives the keys back.
     */
    public function unconfigured(bool $text = true, bool $image = true): static
    {
        $this->textConfigured = ! $text;
        $this->imageConfigured = ! $image;

        return $this;
    }

    /** Whether the registry should report a text key while this fake stands in. */
    public function textConfigured(): bool
    {
        return $this->textConfigured;
    }

    /** Whether the registry should report an image key while this fake stands in. */
    public function imageConfigured(): bool
    {
        return $this->imageConfigured;
    }

    /**
     * Forget queued answers and recorded requests: one agent's, or, with no
     * agent, everything, image requests and the image to make included.
     * Whether the fake is unconfigured is kept.
     */
    public function reset(?string $agent = null): static
    {
        if ($agent !== null) {
            unset($this->answers[$agent]);
            $this->requests = array_values(array_filter($this->requests, fn (TextRequest $request) => $request->agent !== $agent));

            return $this;
        }

        $this->answers = [];
        $this->requests = [];
        $this->imageRequests = [];
        $this->image = null;

        return $this;
    }

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
     * Queue structured replies for one agent: each object is sent back as
     * its JSON, and as TextResponse::$structured when the request had a
     * schema.
     *
     * @param  array<string, mixed>  ...$replies
     */
    public function respondStructured(string $agent, array ...$replies): static
    {
        return $this->respond($agent, ...array_map(fn (array $reply) => (string) json_encode($reply, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), array_values($replies)));
    }

    /**
     * The agent's next call is answered with data made up from its
     * request's schema (SchemaFaker); `{}` for a request without one.
     */
    public function respondFromSchema(string $agent): static
    {
        return $this->respond($agent, fn (TextRequest $request) => $request->schema !== null ? (string) json_encode(SchemaFaker::fake($request->schema)) : '{}');
    }

    /**
     * Stand for a model without structured output: schemas aren't taken,
     * and replies are text only, as the prompt-and-parse path reads them.
     */
    public function withoutStructuredOutput(bool $without = true): static
    {
        $this->structuredOutput = ! $without;

        return $this;
    }

    public function takesSchema(TextRequest $request): bool
    {
        return $this->structuredOutput && $request->schema !== null;
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
            $this->verify(false, "Expected a request to \"{$agent}\", but none was sent.".$this->summary());

            return;
        }

        $this->verify(
            $check === null || array_filter($sent, fn (TextRequest $request) => (bool) $check($request)) !== [],
            sprintf('"%s" was sent %d request(s), but none passed the check.', $agent, count($sent)),
        );
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

        $this->verify($matching === [], sprintf('Expected no %srequest to "%s", but %d were sent.', $check === null ? '' : 'matching ', $agent, count($matching)));
    }

    /**
     * @param  (callable(ImageRequest): bool)|null  $check  Must hold for at least one image request.
     *
     * @throws AssertionError
     */
    public function assertImageSent(?callable $check = null): void
    {
        if ($this->imageRequests === []) {
            $this->verify(false, 'Expected an image request, but none was sent.');

            return;
        }

        $this->verify(
            $check === null || array_filter($this->imageRequests, fn (ImageRequest $request) => (bool) $check($request)) !== [],
            sprintf('%d image request(s) were sent, but none passed the check.', count($this->imageRequests)),
        );
    }

    /**
     * No image request was made.
     *
     * @throws AssertionError
     */
    public function assertNoImageSent(): void
    {
        $this->verify($this->imageRequests === [], sprintf('Expected no image request, but %d were sent.', count($this->imageRequests)));
    }

    /**
     * No text or image request was made at all.
     *
     * @throws AssertionError
     */
    public function assertNothingSent(): void
    {
        $this->verify(
            $this->requests === [] && $this->imageRequests === [],
            sprintf('Expected no requests, but %d text and %d image request(s) were sent.', count($this->requests), count($this->imageRequests)).$this->summary(),
        );
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

        if ($text instanceof TextResponse) {
            return $text;
        }

        $structured = $this->takesSchema($request);

        return new TextResponse((string) $text, StopReason::End, new Usage(100, 50), 'fake', $request->model ?? 'fake', $structured ? JsonReply::decode((string) $text) : null, $structured ? TextResponse::JSON_SCHEMA : null);
    }

    public function image(ImageRequest $request): Image
    {
        $this->imageRequests[] = $request;

        $image = $this->image instanceof Closure ? ($this->image)($request) : $this->image;

        return $image instanceof Image ? $image : Image::fromString((string) base64_decode(self::PNG));
    }

    /**
     * Whether the asserts go through PHPUnit. A subclass may say no, to
     * throw AssertionError even with PHPUnit loaded.
     */
    protected function usesPhpUnit(): bool
    {
        return class_exists(Assert::class);
    }

    /**
     * @throws AssertionError when $passed is false and PHPUnit isn't used.
     */
    private function verify(bool $passed, string $message): void
    {
        if ($this->usesPhpUnit()) {
            Assert::assertTrue($passed, $message);

            return;
        }

        if (! $passed) {
            throw new AssertionError($message);
        }
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
