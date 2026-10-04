<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Ai\Testing;

use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\OutputSchema;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\FakeScenario;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use PHPUnit\Framework\TestCase;

/**
 * Scripted fakes for end-to-end tests: a scenario file's replies, in order
 * per agent across requests, and only ever a file under the folder given.
 */
final class FakeScenarioTest extends TestCase
{
    private string $dir;

    /** @var array<string, int> */
    private array $counts = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/gw-fake-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/site/drafts', 0777, true);
        file_put_contents($this->dir.'/site/drafts/one.txt', '<reply>Here it is.</reply>');
        file_put_contents($this->dir.'/secret.json', '{"agents":{}}');
        file_put_contents($this->dir.'/site/write.json', (string) json_encode([
            'agents' => [
                'writer' => [['textFile' => 'drafts/one.txt'], ['text' => ['<reply>Again.</reply>', '<draft>title: x</draft>']]],
                'brief-filler' => [['schema' => true, 'merge' => ['title' => 'Winter care', 'examples' => '$all']]],
                'reviewer' => [['structured' => ['suggestions' => []]]],
                'verifier' => [['fail' => 'Overloaded.']],
            ],
        ]));
    }

    protected function tearDown(): void
    {
        foreach (['site/drafts/one.txt', 'site/write.json', 'secret.json'] as $file) {
            @unlink($this->dir.'/'.$file);
        }

        @rmdir($this->dir.'/site/drafts');
        @rmdir($this->dir.'/site');
        @rmdir($this->dir);
    }

    private function next(): \Closure
    {
        return function (string $key): int {
            $this->counts[$key] = ($this->counts[$key] ?? -1) + 1;

            return $this->counts[$key];
        };
    }

    public function test_it_reads_only_scenario_names_it_can_trust(): void
    {
        $this->assertSame(['name' => 'site/write', 'run' => 'abc'], FakeScenario::parse('site/write#abc'));
        $this->assertSame(['name' => 'site/write', 'run' => 'default'], FakeScenario::parse(' site/write '));
        $this->assertNull(FakeScenario::parse('../secret'));
        $this->assertNull(FakeScenario::parse('site/../secret'));
        $this->assertNull(FakeScenario::parse('/etc/passwd'));
        $this->assertNull(FakeScenario::parse('site/write#a b'));
        $this->assertNull(FakeScenario::parse(''));
        $this->assertNull(FakeScenario::path($this->dir, 'site/missing'));
        $this->assertSame(realpath($this->dir.'/site/write.json'), FakeScenario::path($this->dir, 'site/write#x'));
    }

    public function test_it_refuses_a_missing_scenario(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FakeScenario::load($this->dir, 'site/nothing', $this->next());
    }

    public function test_replies_come_in_order_and_the_last_repeats(): void
    {
        $next = $this->next();
        $request = new TextRequest('writer', '', 'Write it.');

        $this->assertSame('<reply>Here it is.</reply>', FakeScenario::load($this->dir, 'site/write#r1', $next)->text($request)->text);
        // A new provider, as in another request or job, carries on where the last left off.
        $this->assertSame("<reply>Again.</reply>\n<draft>title: x</draft>", FakeScenario::load($this->dir, 'site/write#r1', $next)->text($request)->text);
        $this->assertSame("<reply>Again.</reply>\n<draft>title: x</draft>", FakeScenario::load($this->dir, 'site/write#r1', $next)->text($request)->text);
        // Another run starts again.
        $this->assertSame('<reply>Here it is.</reply>', FakeScenario::load($this->dir, 'site/write#r2', $next)->text($request)->text);
    }

    public function test_a_schema_reply_is_made_up_and_merged(): void
    {
        $schema = new OutputSchema('brief', [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'answers' => ['type' => 'object', 'properties' => ['goal' => ['type' => 'string']]],
                'examples' => ['type' => 'array', 'maxItems' => 2, 'items' => ['type' => 'string', 'enum' => ['7', '8', '9']]],
            ],
        ]);

        $response = FakeScenario::load($this->dir, 'site/write', $this->next())->text(new TextRequest('brief-filler', '', '', schema: $schema));

        $this->assertSame(['title' => 'Winter care', 'answers' => ['goal' => 'text'], 'examples' => ['7', '8']], $response->structured);
    }

    public function test_structured_replies_failures_and_the_fallback(): void
    {
        $fake = FakeScenario::load($this->dir, 'site/write', $this->next());
        $schema = new OutputSchema('x', ['type' => 'object', 'properties' => ['plans' => ['type' => 'array', 'items' => ['type' => 'string']]]]);

        $this->assertSame(['suggestions' => []], $fake->text(new TextRequest('reviewer', '', '', schema: $schema))->structured);
        // An agent the file doesn't list is answered from its schema.
        $this->assertSame(['plans' => ['text']], $fake->text(new TextRequest('layout-planner', '', '', schema: $schema))->structured);

        $this->expectException(ProviderException::class);
        $fake->text(new TextRequest('verifier', '', ''));
    }
}
