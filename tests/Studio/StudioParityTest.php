<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Studio;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\FakeProvider;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\PromptLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Testing\RequestLog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The parity fixtures: for each addon's vocabulary and options, the exact
 * requests core sends for a few representative inputs. When an addon
 * switches to core's Studio, these show what its translators must produce
 * (see docs/studio.md).
 *
 * A deliberate change to a prompt or a job rewrites them:
 *
 *     GHOSTWRITER_UPDATE_FIXTURES=1 vendor/bin/phpunit --filter StudioParityTest
 */
class StudioParityTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        foreach (array_keys(ParityCases::addons()) as $addon) {
            foreach (array_keys(ParityCases::cases($addon)) as $case) {
                yield "{$addon}/{$case}" => [$addon, $case];
            }
        }
    }

    #[DataProvider('cases')]
    public function test_core_sends_the_recorded_requests(string $addon, string $name): void
    {
        [$vocabulary, $options] = ParityCases::addons()[$addon];
        $case = ParityCases::cases($addon)[$name];
        // The addons' recorded requests are the tagged reply format: a model
        // without structured output gets exactly what they sent.
        $fake = (new FakeProvider)->withoutStructuredOutput();

        foreach ($case['replies'] as $agent => $replies) {
            $fake->respond($agent, ...$replies);
        }

        $result = ($case['run'])(new Studio($fake, new PromptLibrary($vocabulary), options: $options));

        $actual = [
            'addon' => $addon,
            'job' => $case['job'],
            'inputs' => ParityCases::export($case['inputs']),
            'replies' => $case['replies'],
            'requests' => RequestLog::records($fake->requests()),
            'result' => ParityCases::export($result),
        ];
        $path = dirname(__DIR__)."/Fixtures/studio/{$addon}/{$name}.json";
        $json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";

        if (getenv('GHOSTWRITER_UPDATE_FIXTURES')) {
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, $json);
        }

        $this->assertFileExists($path, 'Write the fixtures with GHOSTWRITER_UPDATE_FIXTURES=1.');
        $this->assertSame((string) file_get_contents($path), $json);
    }

    public function test_every_fixture_has_a_case(): void
    {
        $cases = array_keys(iterator_to_array(self::cases()));
        $files = array_map(
            fn (string $path) => basename(dirname($path)).'/'.basename($path, '.json'),
            glob(dirname(__DIR__).'/Fixtures/studio/*/*.json') ?: [],
        );

        sort($cases);
        sort($files);

        $this->assertSame($cases, $files);
    }
}
