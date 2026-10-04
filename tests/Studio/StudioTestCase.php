<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Studio;

use NineteenNinetyFour\Ghostwriter\Core\Ai\StopReason;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\FakeProvider;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextResponse;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Usage;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\PromptLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\Vocabulary;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Studio\StudioOptions;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Testing\RequestLog;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

abstract class StudioTestCase extends TestCase
{
    protected FakeProvider $fake;

    /** @var array<int, array{level: string, message: string, context: array<string, mixed>}> */
    protected array $logs = [];

    protected function setUp(): void
    {
        $this->fake = new FakeProvider;
        $this->logs = [];
    }

    /**
     * With GHOSTWRITER_RECORD_REQUESTS set to a file, every request the test
     * sent is added to it (Studio\Testing\RequestLog), for bin/compare-requests.
     */
    protected function tearDown(): void
    {
        $path = getenv('GHOSTWRITER_RECORD_REQUESTS');

        if (is_string($path) && $path !== '') {
            RequestLog::append($path, static::class.'::'.$this->name(), $this->fake->requests());
        }

        parent::tearDown();
    }

    protected function studio(?Vocabulary $vocabulary = null, ?StudioOptions $options = null): Studio
    {
        return new Studio($this->fake, $this->library($vocabulary), $this->logger(), $options);
    }

    protected function library(?Vocabulary $vocabulary = null): PromptLibrary
    {
        return new PromptLibrary($vocabulary ?? Vocabulary::statamic());
    }

    protected function logger(): AbstractLogger
    {
        $logs = &$this->logs;

        return new class($logs) extends AbstractLogger
        {
            /** @param array<int, array{level: string, message: string, context: array<string, mixed>}> $logs */
            public function __construct(private array &$logs) {}

            public function log($level, $message, array $context = []): void
            {
                $this->logs[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }

    protected static function reply(string $text, int $input = 10, int $output = 20, StopReason $stop = StopReason::End): TextResponse
    {
        return new TextResponse($text, $stop, new Usage($input, $output), 'fake', 'fake-model');
    }

    protected static function cutOff(string $text, int $input = 10, int $output = 20): TextResponse
    {
        return self::reply($text, $input, $output, StopReason::MaxTokens);
    }

    /**
     * The one request sent to an agent.
     */
    protected function sent(string $agent, int $index = 0): TextRequest
    {
        $requests = $this->fake->prompted($agent);
        $this->assertArrayHasKey($index, $requests, "No request {$index} to {$agent}.");

        return $requests[$index];
    }

    /**
     * Everything logged, as one string, for checking what never appears.
     */
    protected function logged(): string
    {
        return (string) json_encode($this->logs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
