<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Arrange;

use NineteenNinetyFour\Ghostwriter\Core\Arrange\Arranger;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Plans;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Units;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Pattern;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Testing\LayoutLog;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Layout\Addons;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Layout 1 is the writer's draft: for every draft the addons built in the
 * golden layout fixtures (tests/Fixtures/layout), the writer's plan
 * arranges to the draft itself, and to the same entry data when it is put
 * together from its placements rather than copied.
 */
final class WriterPlanParityTest extends TestCase
{
    /**
     * @return iterable<string, array{string, array<string, mixed>, Schema, ?Pattern, array<string, mixed>}>
     */
    public static function drafts(): iterable
    {
        foreach (glob(dirname(__DIR__).'/Fixtures/layout/*/*.json') ?: [] as $path) {
            $fixture = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

            foreach ($fixture['cases'] as $i => $case) {
                if ($case['algorithm'] !== 'build') {
                    continue;
                }

                $input = $case['input'];

                yield basename(dirname($path)).'/'.basename($path, '.json').'#'.$i => [
                    basename(dirname($path)),
                    $input['draft'],
                    Schema::fromArray($fixture['schemas'][$case['schema']]),
                    $input['pattern'] === [] ? null : Pattern::fromArray($input['pattern']),
                    $input['defaults'],
                ];
            }
        }
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $defaults
     */
    #[DataProvider('drafts')]
    public function test_the_writers_plan_is_the_draft(string $addon, array $draft, Schema $schema, ?Pattern $pattern, array $defaults): void
    {
        $units = Units::fromDraft($draft, $schema);
        $plan = Plans::fromDraft($draft, $units, $schema);

        $this->assertSame($draft, (new Arranger)->arrange($plan, $units, [], $draft, $schema));

        $builder = Addons::layouts($addon)->builder();
        $built = (new Arranger(keepUntouched: false))->build($builder, $plan, $units, [], $draft, $schema, $pattern, $defaults);

        $expected = $builder->build($draft, $schema, $pattern, $defaults)->toArray();
        // A value in a page builder that isn't a block at all has no place
        // in a plan, so the note that it was left out goes with it.
        $expected['notes'] = array_values(array_filter($expected['notes'], fn (string $note) => ! str_contains($note, 'block of type "?"')));

        $this->assertSame(self::normalise($expected), self::normalise($built->toArray()), 'put together from its placements');
    }

    public function test_compare_layouts_finds_no_difference_between_the_draft_and_layout_1(): void
    {
        $dir = sys_get_temp_dir().'/gw-layouts-'.bin2hex(random_bytes(4));
        mkdir($dir);

        foreach (['draft', 'plan'] as $run) {
            foreach (self::drafts() as $name => [$addon, $draft, $schema, $pattern, $defaults]) {
                LayoutLog::start("{$dir}/{$run}.jsonl", $name);
                $builder = Addons::layouts($addon)->builder();

                if ($run === 'draft') {
                    LayoutLog::record('build', $builder->build($draft, $schema, $pattern, $defaults));
                } else {
                    $units = Units::fromDraft($draft, $schema);
                    $arranged = (new Arranger)->arrange(Plans::fromDraft($draft, $units, $schema), $units, [], $draft, $schema);
                    LayoutLog::record('build', $builder->build($arranged, $schema, $pattern, $defaults));
                }
            }
        }

        LayoutLog::stop();
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 2).'/bin/compare-layouts').' '.escapeshellarg("{$dir}/draft.jsonl").' '.escapeshellarg("{$dir}/plan.jsonl"), $output, $status);
        array_map('unlink', glob("{$dir}/*") ?: []);
        rmdir($dir);

        $this->assertSame(['The layouts are the same.'], $output);
        $this->assertSame(0, $status);
    }

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
