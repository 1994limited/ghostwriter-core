<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Ai;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Effort;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Http\RetryPolicy;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Http\Transport;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Message;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers\Anthropic;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\MockHttpClient;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\RecordingSleeper;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * The provider layer over a mocked network: what each provider is sent, and
 * how its answer is read back.
 */
abstract class ProviderTestCase extends TestCase
{
    protected const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected MockHttpClient $http;

    protected RecordingSleeper $sleeper;

    /** @var array<int, array{level: string, message: string, context: array<string, mixed>}> */
    protected array $logs = [];

    protected function setUp(): void
    {
        $this->http = new MockHttpClient;
        $this->sleeper = new RecordingSleeper;
        $this->logs = [];

        Anthropic::resetBetaGuard();
    }

    protected function tearDown(): void
    {
        Anthropic::resetBetaGuard();
    }

    protected function transport(string $provider, ?RetryPolicy $retry = null): Transport
    {
        $logs = &$this->logs;

        $logger = new class($logs) extends AbstractLogger
        {
            /** @param array<int, mixed> $logs */
            public function __construct(private array &$logs) {}

            /** @param array<string, mixed> $context */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->logs[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        // Jitter fixed at its maximum, so waits are predictable.
        return new Transport($this->http, $provider, $retry ?? new RetryPolicy(random: fn (float $max) => $max), $this->sleeper, $logger);
    }

    /**
     * @param  array<int, Image>  $images
     */
    protected function request(array $images = [], ?string $model = null, Effort|string|null $effort = null, string $agent = 'writer', ?int $maxTokens = null): TextRequest
    {
        return new TextRequest(
            agent: $agent,
            instructions: 'Be brief.',
            prompt: 'Say hello.',
            history: [new Message('user', 'Earlier.'), new Message('assistant', 'Noted.')],
            images: $images,
            maxTokens: $maxTokens,
            model: $model,
            effort: $effort,
        );
    }

    protected function png(): Image
    {
        return new Image((string) base64_decode(self::PNG), 'image/png');
    }

    /**
     * @return array<int, array{level: string, message: string, context: array<string, mixed>}>
     */
    protected function logged(string $level): array
    {
        return array_values(array_filter($this->logs, fn (array $log) => $log['level'] === $level));
    }
}
