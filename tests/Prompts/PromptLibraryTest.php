<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Prompts;

use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\PromptLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\Vocabulary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests: with each addon's vocabulary, every prompt that addon ships
 * must come out exactly as its own file had it before core. The files under
 * tests/Fixtures/prompts are copies of each addon's prompts as of the
 * extraction (Statamic's photo-picker was inline in its PhotoPicker agent,
 * and its photo-query is Studio::PHOTO_QUERY, first PhotoResearcher's).
 *
 * Since 0.2.0 photo-picker is core's own (it judges with or without
 * reference images, and replies with reasons or "none: ..."), so its three
 * fixtures are core's text, the same for every addon.
 */
class PromptLibraryTest extends TestCase
{
    /**
     * @return iterable<string, array{string, Vocabulary, string}>
     */
    public static function goldenPrompts(): iterable
    {
        $vocabularies = ['statamic' => Vocabulary::statamic(), 'craft' => Vocabulary::craft(), 'filament' => Vocabulary::filament()];

        foreach ($vocabularies as $addon => $vocabulary) {
            foreach (glob(dirname(__DIR__)."/Fixtures/prompts/{$addon}/*.md") ?: [] as $file) {
                $name = basename($file, '.md');

                yield "{$addon}/{$name}" => [$name, $vocabulary, (string) file_get_contents($file)];
            }
        }
    }

    #[DataProvider('goldenPrompts')]
    public function test_each_addon_gets_its_prompt_exactly(string $name, Vocabulary $vocabulary, string $expected): void
    {
        $this->assertSame(trim($expected), (new PromptLibrary($vocabulary))->get($name));
    }

    public function test_every_addon_prompt_is_covered(): void
    {
        $this->assertCount(11, glob(dirname(__DIR__).'/Fixtures/prompts/craft/*.md') ?: []);
        $this->assertCount(12, glob(dirname(__DIR__).'/Fixtures/prompts/statamic/*.md') ?: []);
        $this->assertCount(14, PromptLibrary::NAMES);
        $this->assertCount(11, glob(dirname(__DIR__).'/Fixtures/prompts/filament/*.md') ?: []);

        foreach (PromptLibrary::NAMES as $name) {
            $this->assertFileExists(PromptLibrary::path($name));
        }
    }

    public function test_the_addons_own_placeholders_are_left_alone(): void
    {
        $planner = (new PromptLibrary(Vocabulary::filament()))->get('planner');

        $this->assertStringContainsString('{{ count }}', $planner);
        $this->assertStringContainsString('{{ voice }}', $planner);
        $this->assertStringContainsString('{{ resources }}', $planner);
        $this->assertStringNotContainsString('[[', $planner);

        foreach (PromptLibrary::NAMES as $name) {
            foreach ([Vocabulary::statamic(), Vocabulary::craft(), Vocabulary::filament()] as $vocabulary) {
                $this->assertDoesNotMatchRegularExpression('/\[\[[a-z_]+\]\]/', (new PromptLibrary($vocabulary))->get($name), "{$name} has a vocabulary placeholder left in it.");
            }
        }
    }

    public function test_an_override_is_used_and_gets_the_vocabulary_too(): void
    {
        $library = new PromptLibrary(Vocabulary::craft(), fn (string $name) => $name === 'writer' ? "\n  Write for this [[place]], {{ voice }}.  \n" : null);

        $this->assertSame('Write for this website, {{ voice }}.', $library->get('writer'));
        $this->assertSame(trim((string) file_get_contents(PromptLibrary::path('image'))), $library->get('image'));
        $this->assertStringContainsString('[[group_key]]', $library->original('planner'));
    }

    public function test_an_unknown_or_unsafe_name_is_refused(): void
    {
        foreach (['nope', '../composer', 'Writer', ''] as $name) {
            try {
                (new PromptLibrary(Vocabulary::statamic()))->get($name);
                $this->fail("Expected \"{$name}\" to be refused.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('no prompt called', $exception->getMessage());
            }
        }
    }

    public function test_a_custom_vocabulary_fills_in_its_own_words(): void
    {
        $vocabulary = new Vocabulary('shop', 'shop', 'category', 'categories', 'category', 'product', 'products');
        $planner = (new PromptLibrary($vocabulary))->get('planner');

        $this->assertStringContainsString('everything its shop has in the categories you plan for', $planner);
        $this->assertStringContainsString('a question the shop\'s readers plainly have', $planner);
        $this->assertStringContainsString('  category: ...', $planner);
        $this->assertStringContainsString('{{ categories }}', $planner);
        $this->assertSame('a list of strings, exactly as given', $vocabulary->phrases['kind_ids']);
    }

    public function test_an_unknown_phrase_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Vocabulary('app', 'app', 'resource', 'resources', 'resource', 'record', 'records', phrases: ['nonsense' => 'x']);
    }
}
