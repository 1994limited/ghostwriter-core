<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Layout;

use NineteenNinetyFour\Ghostwriter\Core\Layout\FoundKind;
use NineteenNinetyFour\Ghostwriter\Core\Layout\HouseRules;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Pattern;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The safety net for the layout algorithms: what each addon's own
 * SchemaDescriber, PatternFinder, KindFinder, HouseStyle and EntryBuilder
 * gave, recorded from the addon's test suite and from its Northfold test
 * site before core took them over (tests/Fixtures/layout/<addon>/*.json),
 * and core given the same inputs in its neutral shape.
 *
 * Each case must come out the same, apart from the IDs Statamic makes at
 * random, which are compared as "<id>". Where core differs on purpose, the
 * case says so under `deliberate`, with core's output and why; see
 * docs/layout-unification.md.
 *
 * The recordings are never rewritten from core's output. How they were
 * made is in docs/layout.md, "Checking parity".
 */
class GoldenTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function cases(): iterable
    {
        foreach (glob(dirname(__DIR__).'/Fixtures/layout/*/*.json') ?: [] as $path) {
            $fixture = self::fixture($path);

            foreach ($fixture['cases'] as $i => $case) {
                yield basename(dirname($path)).'/'.basename($path, '.json').'#'.$i.' '.$case['algorithm'] => [basename(dirname($path)), $path, $i];
            }
        }
    }

    #[DataProvider('cases')]
    public function test_core_gives_what_the_addon_gave(string $addon, string $path, int $index): void
    {
        $fixture = self::fixture($path);
        $case = $fixture['cases'][$index];
        $schema = Schema::fromArray($fixture['schemas'][$case['schema']]);
        $layouts = Addons::layouts($addon);
        $input = $case['input'];

        $actual = match ($case['algorithm']) {
            'describe' => $layouts->describer()->describe($schema, $input['pattern'] === [] ? null : Pattern::fromArray($input['pattern'])),
            'pattern' => $layouts->patterns()->find($schema, self::entries($input['entries']))->toArray(),
            'kinds' => array_map(fn (FoundKind $kind) => $kind->toArray(), $layouts->kinds()->find($schema, self::entries($input['entries']))),
            'learn' => $layouts->houseStyle()->learn(self::entries($input['entries']), $schema)->toArray(),
            'apply' => (function () use ($layouts, $schema, $input) {
                $result = $layouts->houseStyle()->apply($input['data'], $schema, HouseRules::fromArray($input['style']), $input['self']['id'] ?? null, (string) ($input['self']['title'] ?? ''));

                return ['data' => $result->data, 'toFill' => $result->toFill];
            })(),
            'linkToSelf' => $layouts->houseStyle()->linkToSelf($input['data'], $schema, HouseRules::fromArray($input['style']), $input['id'], $input['title']),
            'build' => $layouts->builder()->build($input['draft'], $schema, $input['pattern'] === [] ? null : Pattern::fromArray($input['pattern']), $input['defaults'])->toArray(),
        };

        $expected = $case['expected'];

        if (isset($case['deliberate'])) {
            $this->assertNotSame(self::normalise($expected), self::normalise($case['deliberate']['expected']), 'A deliberate difference must differ from what the addon gave.');
            $expected = $case['deliberate']['expected'];
        }

        $this->assertSame(self::normalise($expected), self::normalise(json_decode((string) json_encode($actual, JSON_PRESERVE_ZERO_FRACTION), true)), "{$fixture['source']}: {$case['algorithm']}");
    }

    public function test_there_are_fixtures_for_every_addon_and_algorithm(): void
    {
        $seen = [];

        foreach (glob(dirname(__DIR__).'/Fixtures/layout/*/*.json') ?: [] as $path) {
            foreach (self::fixture($path)['cases'] as $case) {
                $seen[basename(dirname($path))][$case['algorithm']] = true;
            }
        }

        foreach (['statamic', 'craft', 'filament'] as $addon) {
            foreach (['describe', 'pattern', 'kinds', 'learn', 'apply', 'build'] as $algorithm) {
                $this->assertArrayHasKey($algorithm, $seen[$addon] ?? [], "No {$algorithm} fixtures for {$addon}.");
            }
        }

        $this->assertArrayHasKey('linkToSelf', $seen['statamic']);
    }

    /**
     * @return array{addon: string, source: string, schemas: array<string, array<int, array<string, mixed>>>, cases: array<int, array<string, mixed>>}
     */
    private static function fixture(string $path): array
    {
        static $cache = [];

        return $cache[$path] ??= json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, EntryData>
     */
    private static function entries(array $entries): array
    {
        return array_map(fn (array $entry) => EntryData::fromArray($entry), $entries);
    }

    /**
     * The IDs Statamic gives new sets and rows are random: eight hex
     * characters under an `id` key.
     */
    private static function normalise(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $key === 'id' && is_string($item) && preg_match('/^[0-9a-f]{8}$/', $item) ? '<id>' : self::normalise($item);
        }

        return $value;
    }
}
