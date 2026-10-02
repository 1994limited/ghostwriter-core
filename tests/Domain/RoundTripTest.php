<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Domain;

use Closure;
use NineteenNinetyFour\Ghostwriter\Core\Domain\DomainOptions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideState;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Images\ImageRequest;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\Analysis;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\KindSuggestions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\Idea;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\PlanState;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every stored shape recorded from the three test sites (and the synthetic
 * ones for shapes they don't hold yet) reads into core's types and comes
 * back exactly as it was, so the addons switch with no data migration.
 * tools/record-domain/record.php records them.
 */
final class RoundTripTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function fixtures(): iterable
    {
        $root = dirname(__DIR__).'/Fixtures/domain/';
        $files = glob($root.'*/*/*.json') ?: [];
        sort($files);

        foreach ($files as $file) {
            yield substr($file, strlen($root)) => [$file];
        }
    }

    #[DataProvider('fixtures')]
    public function test_a_stored_record_comes_back_exactly(string $file): void
    {
        $fixture = self::load($file);

        $this->assertSame($fixture['record'], self::write(self::read($fixture)), 'Read and written back unchanged: '.$fixture['source']);
    }

    #[DataProvider('fixtures')]
    public function test_core_writes_the_shape_itself(string $file): void
    {
        $fixture = self::load($file);
        $object = self::read($fixture);

        if (is_string($object)) {
            $this->assertSame($fixture['record']['body'], Guide::normalise($object));

            return;
        }

        if ($object instanceof Analysis) {
            $this->assertSame($fixture['record'], $object->toArray());

            return;
        }

        // What core writes from its own fields, without the record it read:
        // every key the record has (bar the host's own: Filament's workspace
        // columns, a kind row's key and timestamps), with the same values
        // (JSON columns compared decoded). An older record may lack keys
        // core writes; those must be the defaults it was read with.
        $fresh = self::normal(Closure::bind(fn () => $this->encode($this->format), $object, $object::class)(), $fixture['format']);
        $record = self::normal($fixture['record'], $fixture['format']);
        $hosts = $fixture['format'] === 'filament' && $fixture['type'] === 'kind' ? ['id' => 1, 'created_at' => 1, 'updated_at' => 1] : [];

        $this->assertSame(array_diff_key($record, $hosts), array_intersect_key($fresh, $record), 'Core writes this shape itself: '.$fixture['source']);

        foreach (array_diff_key($fresh, $record) as $key => $value) {
            $this->assertContains($value, [null, [], '', 'open', 'added'], "Only a default is added to an older record ({$key}): ".$fixture['source']);
        }
    }

    public function test_every_type_has_fixtures_from_all_three(): void
    {
        $seen = [];

        foreach (self::fixtures() as [$file]) {
            $fixture = self::load($file);
            $seen[$fixture['type']][$fixture['format']] = true;
        }

        foreach (['session', 'idea', 'plan-state', 'kind', 'kind-suggestions', 'guide-state', 'image-request'] as $type) {
            $formats = array_keys($seen[$type] ?? []);
            sort($formats);

            $this->assertSame(['craft', 'filament', 'statamic'], $formats, "{$type} fixtures for all three");
        }
    }

    public function test_a_changed_field_is_written_in_the_formats_own_way(): void
    {
        $fixture = self::load(dirname(__DIR__).'/Fixtures/domain/filament/session/01m3ycrdns656yxamjqnhy8h9e.json');
        $session = Session::fromArray($fixture['record'], Format::Filament);
        $session->usage['input'] += 5;
        $session->images['hero'] = ['status' => 'done', 'path' => 'posts/a/b.png'];

        $row = $session->toArray();

        // Laravel's JSON for the changed columns; the rest exactly as read.
        $this->assertSame('{"hero":{"status":"done","path":"posts\/a\/b.png"}}', $row['images']);
        $this->assertSame(json_decode($fixture['record']['usage'], true)['input'] + 5, json_decode($row['usage'], true)['input']);
        $this->assertSame($fixture['record']['messages'], $row['messages']);
        $this->assertSame(array_keys($fixture['record']), array_keys($row));
    }

    public function test_a_claimed_run_is_timed_in_the_record_where_there_is_room(): void
    {
        $statamic = Session::fromArray(self::load(dirname(__DIR__).'/Fixtures/domain/statamic/session/01M3WWM3KKW1FV098209K5HHYE.json')['record'], Format::Statamic);
        $statamic->claim('someone', DomainOptions::statamic());

        $this->assertArrayHasKey('started_working_at', $statamic->toArray());

        $filament = Session::fromArray(self::load(dirname(__DIR__).'/Fixtures/domain/filament/session/01m3ycrdns656yxamjqnhy8h9e.json')['record'], Format::Filament);
        $filament->claim(1, DomainOptions::filament());

        $this->assertArrayNotHasKey('started_working_at', $filament->toArray());
        $this->assertSame('working', $filament->toArray()['status']);
    }

    /**
     * @return array{source: string, format: string, type: string, handle?: string, record: array<string, mixed>}
     */
    private static function load(string $file): array
    {
        return json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array{source: string, format: string, type: string, handle?: string, record: array<string, mixed>}  $fixture
     */
    private static function read(array $fixture): object|string
    {
        $format = Format::from($fixture['format']);
        $record = $fixture['record'];

        return match ($fixture['type']) {
            'session' => Session::fromArray($record, $format),
            'idea' => Idea::fromArray($record, $format),
            'plan-state' => PlanState::fromArray($record, $format),
            'guide-state' => GuideState::fromArray($record, $format),
            'kind' => ContentType::fromArray($record, $format, $fixture['handle'] ?? null),
            'kind-suggestions' => KindSuggestions::fromArray($record, $format),
            'image-request' => ImageRequest::fromArray($record, $format),
            'analysis' => Analysis::fromArray($record),
            'guide' => (string) $record['body'],
            default => throw new \LogicException("Unknown fixture type {$fixture['type']}"),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function write(object|string $object): array
    {
        if (is_string($object)) {
            return ['body' => (new Guide(Guide::VOICE))->withBody($object)->body];
        }

        return $object->toArray();
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private static function normal(array $record, string $format): array
    {
        if ($format === 'filament') {
            foreach (['answers', 'messages', 'examples', 'images', 'usage', 'definition'] as $key) {
                if (is_string($record[$key] ?? null)) {
                    $record[$key] = json_decode($record[$key], true);
                }
            }

            // Not ours: the workspace columns, which the store's scope sets.
            unset($record['tenant_type'], $record['tenant_id']);
        }

        ksort($record);

        return $record;
    }
}
