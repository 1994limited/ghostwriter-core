<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Studio;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Message;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Testing\RequestLog;
use PHPUnit\Framework\TestCase;

class RequestLogTest extends TestCase
{
    private ?string $directory = null;

    protected function tearDown(): void
    {
        if ($this->directory !== null) {
            array_map('unlink', glob($this->directory.'/*') ?: []);
            rmdir($this->directory);
        }
    }

    public function test_a_request_is_recorded_with_the_values_a_provider_would_send(): void
    {
        $record = RequestLog::record(new TextRequest('photo-picker', 'Pick.', 'Which?', [new Message('user', 'Hi')], [new Image('abc', 'image/png')], timeout: 60));

        $this->assertSame([
            'agent' => 'photo-picker',
            'instructions' => 'Pick.',
            'prompt' => 'Which?',
            'history' => [['role' => 'user', 'content' => 'Hi']],
            'images' => [['mime' => 'image/png', 'bytes' => 3, 'sha1' => sha1('abc')]],
            'maxTokens' => 2000,
            'effort' => 'low',
            'model' => null,
            'timeout' => 60,
        ], $record);

        // Craft's explicit limit and Statamic's default record the same.
        $this->assertSame(RequestLog::record(new TextRequest('writer', 'i', 'p')), RequestLog::record(new TextRequest('writer', 'i', 'p', maxTokens: 16000)));
    }

    public function test_two_logs_are_compared_test_by_test(): void
    {
        $this->directory = sys_get_temp_dir().'/ghostwriter-requests-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
        $before = $this->directory.'/before.jsonl';
        $after = $this->directory.'/after.jsonl';

        RequestLog::append($before, 'WritingTest::test_a', [new TextRequest('writer', "You write.\nWarmly.", 'Go')]);
        RequestLog::append($before, 'WritingTest::test_b', [new TextRequest('planner', 'Plan.', 'Go')]);
        RequestLog::append($before, 'WritingTest::test_none', []);
        RequestLog::append($before, 'WritingTest::test_gone', [new TextRequest('planner', 'Plan.', 'Go')]);

        RequestLog::append($after, 'WritingTest::test_a', [new TextRequest('writer', "You write.\nCoolly.", 'Go', maxTokens: 8000)]);
        RequestLog::append($after, 'WritingTest::test_b', [new TextRequest('planner', 'Plan.', 'Go'), new TextRequest('planner', 'Plan.', 'Again')]);

        $this->assertSame(['WritingTest::test_a', 'WritingTest::test_b', 'WritingTest::test_gone'], array_keys(RequestLog::read($before)));

        $this->assertSame([
            'WritingTest::test_gone: only in the first log',
            'WritingTest::test_a: request 1 (writer), instructions: differs from line 2: "You write.\nWarmly." became "You write.\nCoolly."',
            'WritingTest::test_a: request 1 (writer), maxTokens: differs from line 1: "16000" became "8000"',
            'WritingTest::test_b: 1 request(s), then 2 (planner, then planner, planner)',
        ], RequestLog::compare(RequestLog::read($before), RequestLog::read($after)));

        $this->assertCount(3, RequestLog::compare(RequestLog::read($before), RequestLog::read($after), ['maxTokens']));
        $this->assertSame([], RequestLog::compare(RequestLog::read($before), RequestLog::read($before)));

        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 2).'/bin/compare-requests').' '.escapeshellarg($before).' '.escapeshellarg($before), $output, $code);
        $this->assertSame([0, 'The requests are the same.'], [$code, end($output)]);

        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 2).'/bin/compare-requests').' '.escapeshellarg($before).' '.escapeshellarg($after).' --ignore=maxTokens', $output, $code);
        $this->assertSame([1, '3 difference(s).'], [$code, end($output)]);
    }
}
