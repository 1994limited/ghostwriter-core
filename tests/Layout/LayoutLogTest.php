<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Layout;

use NineteenNinetyFour\Ghostwriter\Core\Layout\BuiltEntry;
use NineteenNinetyFour\Ghostwriter\Core\Layout\HouseResult;
use NineteenNinetyFour\Ghostwriter\Core\Layout\KindFinder;
use NineteenNinetyFour\Ghostwriter\Core\Layout\PatternFinder;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Testing\LayoutLog;
use RuntimeException;

class LayoutLogTest extends LayoutTestCase
{
    private string $before;

    private string $after;

    protected function setUp(): void
    {
        $this->before = (string) tempnam(sys_get_temp_dir(), 'layouts');
        $this->after = (string) tempnam(sys_get_temp_dir(), 'layouts');
    }

    protected function tearDown(): void
    {
        LayoutLog::stop();
        @unlink($this->before);
        @unlink($this->after);
    }

    public function test_outputs_are_recorded_in_the_addons_shapes_and_compared(): void
    {
        $pattern = (new PatternFinder)->find(self::articles(), self::entries());

        LayoutLog::start($this->before, 'ArticlesTest::test_writing');
        LayoutLog::record('pattern', $pattern->toArray());
        LayoutLog::record('build', ['data' => ['body' => [['id' => 'a1b2c3d4', 'type' => 'text']]], 'notes' => []]);
        LayoutLog::record('linkToSelf', ['link' => 'entry::00000000-1111-2222-3333-444444444444']);

        LayoutLog::start($this->after, 'ArticlesTest::test_writing');
        LayoutLog::record('pattern', $pattern);
        LayoutLog::record('build', new BuiltEntry(['body' => [['id' => 'ffff0000', 'type' => 'text']]]));
        LayoutLog::record('linkToSelf', ['link' => 'entry::b2e72b7a-c98d-462a-9abc-e97ed6c4e856']);
        LayoutLog::stop();

        // Not recording: nothing is written.
        LayoutLog::record('kinds', (new KindFinder)->find(self::articles(), self::entries()));

        $before = LayoutLog::read($this->before);
        $this->assertSame(['pattern', 'build', 'linkToSelf'], array_column($before['ArticlesTest::test_writing'], 'algorithm'));
        $this->assertSame([], LayoutLog::compare($before, LayoutLog::read($this->after)));
    }

    public function test_differences_are_described(): void
    {
        LayoutLog::start($this->before, 'A::test_one');
        LayoutLog::record('apply', new HouseResult(['height' => 40], ['Hero']));
        LayoutLog::start($this->before, 'A::test_gone');
        LayoutLog::record('describe', 'Fields');

        LayoutLog::start($this->after, 'A::test_one');
        LayoutLog::record('apply', new HouseResult(['height' => 45], ['Hero']));
        LayoutLog::start($this->after, 'A::test_new');
        LayoutLog::record('describe', 'Fields');
        LayoutLog::record('kinds', []);

        $differences = LayoutLog::compare(LayoutLog::read($this->before), LayoutLog::read($this->after));

        $this->assertSame('A::test_gone: only in the first log', $differences[0]);
        $this->assertSame('A::test_new: only in the second log', $differences[1]);
        $this->assertStringStartsWith('A::test_one: apply 1 differs from line 3:', $differences[2]);
        $this->assertStringContainsString('became " \\"data\\": {\\n        \\"height\\": 45', $differences[2]);
        $this->assertSame([], LayoutLog::compare(LayoutLog::read($this->before), LayoutLog::read($this->before), ['apply']));
    }

    public function test_a_log_that_cannot_be_read_is_reported(): void
    {
        $this->expectException(RuntimeException::class);

        LayoutLog::read('/nonexistent/layouts.jsonl');
    }

    public function test_the_command_compares_two_logs(): void
    {
        LayoutLog::start($this->before, 'A::test_one');
        LayoutLog::record('describe', 'Fields');
        LayoutLog::start($this->after, 'A::test_one');
        LayoutLog::record('describe', 'Fields');
        LayoutLog::stop();

        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 2).'/bin/compare-layouts').' '.escapeshellarg($this->before).' '.escapeshellarg($this->after), $output, $code);

        $this->assertSame(0, $code);
        $this->assertSame(['The layouts are the same.'], $output);

        file_put_contents($this->after, str_replace('Fields', 'Other', (string) file_get_contents($this->after)));
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 2).'/bin/compare-layouts').' '.escapeshellarg($this->before).' '.escapeshellarg($this->after), $output, $code);

        $this->assertSame(1, $code);
    }
}
